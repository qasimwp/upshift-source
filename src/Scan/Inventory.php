<?php
/**
 * Components and their file lists (spec §7.5).
 *
 * @package Upshift
 */

namespace Upshift\Scan;

use Upshift\Install;
use Upshift\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Plugins, active theme + parent, mu-plugins and drop-ins.
 */
class Inventory {

	const MAX_BYTES      = 1572864; // 1.5 MB.
	const DATA_MIN_BYTES = 102400; // Data/classmap files from 100 KB on are skipped (DECISIONS D40).
	const INSERT_CHUNK   = 200;
	const RUNNING_DAYS   = 7; // A component the usage map saw this recently counts as active (DECISIONS D55).
	const EXTENSIONS     = array( 'php', 'inc' );
	const SKIP_DIRS      = array( 'node_modules', '.git', '.svn' );

	/**
	 * Test folders of bundled libraries (PHPCS test fixtures etc.), skipped only inside a /vendor/ path (DECISIONS D54).
	 */
	const VENDOR_TEST_DIRS = array( 'tests', 'Tests', 'test' );

	/**
	 * Identifiers a pure data file may contain (lowercase), besides $variables and string contents.
	 */
	const DATA_WORDS = array( 'php', 'return', 'array', 'true', 'false', 'null', 'dirname', '__dir__', '__file__', 'defined', 'abspath', 'exit', 'die' );

	/**
	 * Components to scan, our own plugin excluded.
	 *
	 * @param string $only Limit to this component id ('' = all).
	 * @return array List of array( 'id', 'label', 'active', 'root', 'type' ).
	 */
	public static function components( $only = '' ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out = array();

		foreach ( get_plugins() as $file => $data ) {
			$dir   = dirname( $file );
			$root  = WP_PLUGIN_DIR . '/' . ( '.' === $dir ? $file : $dir );
			$out[] = array(
				'root'   => $root,
				'label'  => isset( $data['Name'] ) && '' !== $data['Name'] ? $data['Name'] : $file,
				'active' => is_plugin_active( $file ) || is_plugin_active_for_network( $file ),
				'type'   => 'plugin',
			);
		}

		$stylesheet = get_stylesheet_directory();
		$template   = get_template_directory();
		$out[]      = array(
			'root'   => $stylesheet,
			'label'  => wp_get_theme()->get( 'Name' ),
			'active' => true,
			'type'   => 'theme',
		);
		if ( wp_normalize_path( $template ) !== wp_normalize_path( $stylesheet ) ) {
			$out[] = array(
				'root'   => $template,
				'label'  => wp_get_theme( get_template() )->get( 'Name' ),
				'active' => true,
				'type'   => 'theme',
			);
		}

		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			$out[] = array(
				'root'   => WPMU_PLUGIN_DIR,
				'label'  => 'mu-plugins',
				'active' => true,
				'type'   => 'mu-plugins',
			);
		}

		foreach ( array_keys( _get_dropins() ) as $dropin ) {
			if ( is_file( WP_CONTENT_DIR . '/' . $dropin ) ) {
				$out[] = array(
					'root'   => WP_CONTENT_DIR . '/' . $dropin,
					'label'  => $dropin,
					'active' => true,
					'type'   => 'dropin',
				);
			}
		}

		$components = array();
		foreach ( $out as $component ) {
			// A folder's id comes from a (hypothetical) file inside it: `themes:{dir}` alone maps to other:content,
			// and the mu-plugins dir has no relative part at all.
			$key = Paths::key( is_dir( $component['root'] ) ? $component['root'] . '/x.php' : $component['root'] );
			if ( null === $key || Paths::is_own( $key ) ) {
				continue;
			}
			$component['id']     = Paths::component( $key );
			$component['active'] = (bool) $component['active'];
			// One folder can hold several plugin files: it is active when any of them is (DECISIONS D55).
			if ( isset( $components[ $component['id'] ] ) ) {
				$component['active'] = $component['active'] || $components[ $component['id'] ]['active'];
			}
			$components[ $component['id'] ] = $component;
		}

		foreach ( self::seen_in_usage() as $id => $last_seen ) {
			$running = strtotime( $last_seen ) >= strtotime( current_time( 'Y-m-d' ) ) - self::RUNNING_DAYS * DAY_IN_SECONDS;
			if ( isset( $components[ $id ] ) ) {
				$components[ $id ]['active'] = $components[ $id ]['active'] || $running;
				$components[ $id ]['seen']   = true;
				continue;
			}
			$root = self::component_root( $id );
			if ( null !== $root ) {
				$components[ $id ] = array(
					'id'     => $id,
					'root'   => $root,
					'label'  => substr( $id, strpos( $id, ':' ) + 1 ),
					'active' => $running,
					'type'   => 0 === strpos( $id, 'theme:' ) ? 'theme' : 'plugin',
					'seen'   => true,
				);
			}
		}

		if ( '' !== $only ) {
			$components = array_intersect_key( $components, array( $only => true ) );
		}
		return array_values( $components );
	}

	/**
	 * Plugins and themes the usage map has seen loading, with the day they were last seen. WordPress may not list them
	 * as active: Wordfence's firewall loads through auto_prepend_file even when the plugin is deactivated (DECISIONS D55).
	 *
	 * @return array Component id => Y-m-d.
	 */
	private static function seen_in_usage() {
		global $wpdb;

		$table = Install::table( 'usage' );
		$seen  = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		foreach ( (array) $wpdb->get_results( "SELECT component, MAX(last_seen) AS last_seen FROM {$table} WHERE component LIKE 'plugin:%' OR component LIKE 'theme:%' GROUP BY component", ARRAY_A ) as $row ) {
			$seen[ $row['component'] ] = $row['last_seen'];
		}
		return $seen;
	}

	/**
	 * Folder or single file of a component the usage map has seen.
	 *
	 * @param string $id Component id: plugin:{dir}, plugin:{file}.php or theme:{dir}.
	 * @return string|null Absolute path, or null when it is no longer on disk.
	 */
	private static function component_root( $id ) {
		list( $type, $name ) = explode( ':', $id, 2 );
		if ( 'theme' === $type ) {
			$root = wp_get_theme( $name )->exists() ? wp_get_theme( $name )->get_stylesheet_directory() : '';
		} else {
			$root = WP_PLUGIN_DIR . '/' . $name;
		}
		return '' !== $root && file_exists( $root ) ? $root : null;
	}

	/**
	 * Absolute file paths (forward slashes) of a component: .php/.inc only, skipped dirs left out.
	 *
	 * @param array $component Component from components().
	 * @return string[]
	 */
	public static function files( array $component ) {
		$root = wp_normalize_path( $component['root'] );
		if ( is_file( $root ) ) {
			return self::wanted( $root ) ? array( $root ) : array();
		}
		if ( ! is_dir( $root ) ) {
			return array();
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warning; false is handled.
		$real_root = @realpath( $root );
		$real_root = false === $real_root ? $root : wp_normalize_path( $real_root );

		$directory = new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS );
		$filter    = new \RecursiveCallbackFilterIterator(
			$directory,
			function ( $item ) use ( $real_root, $root ) {
				if ( ! $item->isDir() ) {
					return true;
				}
				if ( in_array( $item->getFilename(), self::SKIP_DIRS, true ) ) {
					return false;
				}
				// Only the part below the component root, so a site installed under a "vendor" folder isn't affected.
				if ( in_array( $item->getFilename(), self::VENDOR_TEST_DIRS, true ) && false !== strpos( substr( wp_normalize_path( $item->getPathname() ), strlen( $root ) ), '/vendor/' ) ) {
					return false;
				}
				if ( $item->isLink() ) {
					// Don't follow a directory symlink that leaves the component's base.
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warning; false is handled.
					$real = @realpath( $item->getPathname() );
					return false !== $real && 0 === strpos( wp_normalize_path( $real ) . '/', trailingslashit( $real_root ) );
				}
				return true;
			}
		);

		$files = array();
		foreach ( new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::LEAVES_ONLY ) as $item ) {
			$path = wp_normalize_path( $item->getPathname() );
			if ( $item->isFile() && self::wanted( $path ) ) {
				$files[] = $path;
			}
		}
		sort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * Inserts the scan_files rows for a scan.
	 *
	 * @param int    $scan_id  Scan id.
	 * @param string $only     Limit to this component id ('' = all).
	 * @param bool   $inactive Also list inactive plugins (DECISIONS D54).
	 * @return array Per component id: array( 'label' => display name, 'files' => n, 'pending' => n, 'skipped_large' => n, 'unreadable' => n ).
	 */
	public static function populate( $scan_id, $only = '', $inactive = true ) {
		$counts = array();
		$rows   = array();
		foreach ( self::components( $only ) as $component ) {
			// Anything the usage map has seen is always scanned (DECISIONS D55).
			if ( ! $inactive && ! $component['active'] && empty( $component['seen'] ) ) {
				continue;
			}
			$id            = $component['id'];
			$counts[ $id ] = array(
				'label'         => (string) $component['label'],
				'files'         => 0,
				'pending'       => 0,
				'skipped_large' => 0,
				'unreadable'    => 0,
			);
			foreach ( self::files( $component ) as $path ) {
				$key = Paths::key( $path );
				if ( null === $key ) {
					continue;
				}
				$note = null;
				if ( ! is_readable( $path ) ) {
					$status = 'unreadable';
					$note   = __( 'File not readable.', 'upshift' );
				} else {
					$note   = self::skip_note( $path, (int) filesize( $path ) );
					$status = null === $note ? 'pending' : 'skipped_large';
				}
				++$counts[ $id ]['files'];
				++$counts[ $id ][ $status ];
				$rows[] = array( (int) $scan_id, Paths::hash( $key ), $key, $id, $component['active'] ? 1 : 0, $status, $note );
				if ( count( $rows ) >= self::INSERT_CHUNK ) {
					self::insert( $rows );
					$rows = array();
				}
			}
		}
		if ( $rows ) {
			self::insert( $rows );
		}
		return $counts;
	}

	/**
	 * Why a file is not scanned at all: over the size limit, or a large pure data/classmap file (DECISIONS D40).
	 *
	 * @param string $path  Absolute path.
	 * @param int    $bytes File size.
	 * @return string|null Note starting with "skipped: too large", or null to scan the file.
	 */
	public static function skip_note( $path, $bytes ) {
		$kb = number_format_i18n( $bytes / 1024, 1 );
		if ( $bytes > self::MAX_BYTES ) {
			/* translators: 1: file size in KB, 2: limit in KB. */
			return sprintf( __( 'skipped: too large (%1$s KB, limit %2$s KB)', 'upshift' ), $kb, number_format_i18n( self::MAX_BYTES / 1024 ) );
		}
		if ( $bytes >= self::DATA_MIN_BYTES && self::is_data_file( $path ) ) {
			/* translators: %s: file size in KB. */
			return sprintf( __( 'skipped: too large (data/classmap file, %s KB)', 'upshift' ), $kb );
		}
		return null;
	}

	/**
	 * Whether a file is generated data with nothing a PHP version can break: a Composer autoload map
	 * or classmap by name, or code that is only arrays of literals (`return array( 'a' => 'b' … );`).
	 * Anything else (functions, classes, calls, heredocs, "…$var…" strings) is not data.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_data_file( $path ) {
		$path = wp_normalize_path( $path );
		$name = strtolower( basename( $path ) );
		if ( false !== strpos( $name, 'classmap' )
			|| ( false !== strpos( strtolower( $path ), '/vendor/composer/' ) && ( 0 === strpos( $name, 'autoload_' ) || 'installed.php' === $name ) )
		) {
			return true;
		}

		$code = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, read as text only.
		if ( false === $code ) {
			return false;
		}
		// Drop single-quoted strings, double-quoted strings without "$", and comments (unrolled loops, no backtracking).
		$rest = preg_replace( '~\'[^\'\\\\]*+(?:\\\\.[^\'\\\\]*+)*+\'|"[^"\\\\$]*+(?:\\\\.[^"\\\\$]*+)*+"|/\*.*?\*/|(?://|#)[^\n]*+~s', ' ', $code );
		if ( null === $rest ) {
			return false;
		}
		$rest = preg_replace( '/\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/', ' ', $rest );
		// Literal arrays need no braces, backticks or heredocs ($s{0} and `cmd` are version-sensitive code).
		if ( null === $rest || strpbrk( $rest, '"{}`' ) || false !== strpos( $rest, '<<<' ) || ! preg_match_all( '/[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/', $rest, $m ) ) {
			return false;
		}
		return ! array_diff( array_unique( array_map( 'strtolower', $m[0] ) ), self::DATA_WORDS );
	}

	/**
	 * Whether a file has a scanned extension.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function wanted( $path ) {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
	}

	/**
	 * One prepared multi-row INSERT into scan_files.
	 *
	 * @param array $rows Rows of array( scan_id, path_hash, path_key, component, component_active, status, note|null ).
	 * @throws \RuntimeException When the insert fails.
	 */
	private static function insert( array $rows ) {
		global $wpdb;

		$table  = Install::table( 'scan_files' );
		$values = array();
		$args   = array();
		foreach ( $rows as $row ) {
			$note     = array_pop( $row );
			$values[] = null === $note ? '(%d, %s, %s, %s, %d, %s, NULL)' : '(%d, %s, %s, %s, %d, %s, %s)';
			$args     = array_merge( $args, null === $note ? $row : array_merge( $row, array( $note ) ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from $wpdb->prefix; placeholders built above.
		$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (scan_id, path_hash, path_key, component, component_active, status, note) VALUES " . implode( ', ', $values ), $args ) );
		if ( false === $ok ) {
			throw new \RuntimeException( 'scan_files insert failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
		}
	}
}
