<?php
/**
 * Path normalization, path keys and component detection (spec §7.1).
 *
 * The single source of truth for file identity: the usage recorder and the
 * scanner must produce the same key for the same file.
 *
 * @package Upshift
 */

namespace Upshift;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File identity helpers.
 */
class Paths {

	/**
	 * Bases, longest first. Each: array( 'name', 'dir', 'dir_cmp', 'real', 'real_cmp' ).
	 * `dir`/`real` keep case (display), `*_cmp` are the comparison forms; all end with '/'.
	 *
	 * @var array|null
	 */
	private static $bases = null;

	/**
	 * Cached own component id.
	 *
	 * @var string|null
	 */
	private static $own = null;

	/**
	 * Cached drop-in file names.
	 *
	 * @var array|null
	 */
	private static $dropins = null;

	/**
	 * Normalized comparison form: forward slashes, lowercased on Windows.
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public static function normalize( $abs ) {
		$path = wp_normalize_path( (string) $abs );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
		}
		return $path;
	}

	/**
	 * Path key, e.g. `plugins:woocommerce/woocommerce.php`, or null outside all bases.
	 * On Windows the key is lowercased (it is the identity used for hashing).
	 *
	 * @param string $abs Absolute path.
	 * @return string|null
	 */
	public static function key( $abs ) {
		$match = self::match( $abs );
		return null === $match ? null : $match[0] . ':' . $match[2];
	}

	/**
	 * Same as key() but with the original case kept. For display only.
	 *
	 * @param string $abs Absolute path.
	 * @return string|null
	 */
	public static function display_key( $abs ) {
		$match = self::match( $abs );
		return null === $match ? null : $match[0] . ':' . $match[1];
	}

	/**
	 * Hash of a path key.
	 *
	 * @param string $key Path key.
	 * @return string
	 */
	public static function hash( $key ) {
		return md5( $key );
	}

	/**
	 * Component id for a path key.
	 *
	 * @param string $key Path key.
	 * @return string
	 */
	public static function component( $key ) {
		$pos = strpos( (string) $key, ':' );
		if ( false === $pos ) {
			return 'other:content';
		}
		$base     = substr( $key, 0, $pos );
		$relative = substr( $key, $pos + 1 );
		$slash    = strpos( $relative, '/' );
		$first    = false === $slash ? $relative : substr( $relative, 0, $slash );

		switch ( $base ) {
			case 'plugins':
				return 'plugin:' . $first;
			case 'mu-plugins':
				return 'mu-plugins';
			case 'themes':
				return false === $slash ? 'other:content' : 'theme:' . $first;
			case 'content':
				if ( false === $slash && '.php' === substr( $relative, -4 ) && array_key_exists( $relative, self::dropins() ) ) {
					return 'dropin:' . substr( $relative, 0, -4 );
				}
				return 'other:content';
		}
		return 'other:content';
	}

	/**
	 * Whether a path key belongs to this plugin (excluded from usage and scans).
	 *
	 * @param string|null $key Path key.
	 * @return bool
	 */
	public static function is_own( $key ) {
		if ( null === $key ) {
			return false;
		}
		if ( null === self::$own ) {
			$own_key   = self::key( UPSHIFT_FILE );
			self::$own = null === $own_key ? '' : self::component( $own_key );
		}
		return '' !== self::$own && self::component( $key ) === self::$own;
	}

	/**
	 * Comparison-form prefixes of all bases and their real paths, each ending with '/'.
	 * Used by the usage recorder's hot-path filter (DECISIONS D13, D19).
	 *
	 * @return array prefix => base name (mu-plugins, plugins, themes, content).
	 */
	public static function base_prefixes() {
		$out = array();
		foreach ( self::bases() as $base ) {
			foreach ( array( 'dir_cmp', 'real_cmp' ) as $field ) {
				if ( '' !== $base[ $field ] && ! isset( $out[ $base[ $field ] ] ) ) {
					$out[ $base[ $field ] ] = $base['name'];
				}
			}
		}
		return $out;
	}

	/**
	 * Absolute path for a path key: the first base with that name whose dir + relative path is a file.
	 * Keys are lowercased on Windows, which its case-insensitive file system accepts.
	 *
	 * @param string $key Path key.
	 * @return string|null Absolute path (forward slashes), or null when no such file exists.
	 */
	public static function resolve( $key ) {
		$pos = strpos( (string) $key, ':' );
		if ( false === $pos ) {
			return null;
		}
		$name     = substr( $key, 0, $pos );
		$relative = substr( $key, $pos + 1 );
		if ( '' === $relative || false !== strpos( '/' . $relative . '/', '/../' ) ) {
			return null;
		}
		foreach ( self::bases() as $base ) {
			if ( $base['name'] === $name && is_file( $base['dir'] . $relative ) ) {
				return $base['dir'] . $relative;
			}
		}
		return null;
	}

	/**
	 * Clears cached bases (for tests).
	 */
	public static function reset() {
		self::$bases   = null;
		self::$own     = null;
		self::$dropins = null;
	}

	/**
	 * Finds the base for a file.
	 *
	 * @param string $abs Absolute path.
	 * @return array|null array( base name, relative with case, relative comparison form ).
	 */
	private static function match( $abs ) {
		$display = wp_normalize_path( (string) $abs );
		$found   = self::match_normalized( $display );
		if ( null !== $found ) {
			return $found;
		}

		// Symlinks: compare the file's real path too.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- realpath() warns under open_basedir when a symlink resolves outside the allowed paths; a warning isn't a Throwable, and false is handled.
		$real = @realpath( (string) $abs );
		if ( false !== $real ) {
			$real = wp_normalize_path( $real );
			if ( $real !== $display ) {
				return self::match_normalized( $real );
			}
		}
		return null;
	}

	/**
	 * Matches an already normalized (case kept) path against base dirs and their real paths.
	 *
	 * @param string $display Normalized path, case kept.
	 * @return array|null
	 */
	private static function match_normalized( $display ) {
		$cmp = '\\' === DIRECTORY_SEPARATOR ? strtolower( $display ) : $display;

		foreach ( array( 'dir', 'real' ) as $field ) {
			foreach ( self::bases() as $base ) {
				$prefix = $base[ $field . '_cmp' ];
				if ( '' === $prefix ) {
					continue;
				}
				$len = strlen( $prefix );
				if ( strlen( $cmp ) > $len && 0 === strncmp( $cmp, $prefix, $len ) ) {
					return array( $base['name'], substr( $display, $len ), substr( $cmp, $len ) );
				}
			}
		}
		return null;
	}

	/**
	 * Builds and caches the bases, longest first.
	 *
	 * @return array
	 */
	private static function bases() {
		if ( null !== self::$bases ) {
			return self::$bases;
		}

		$dirs = array(
			array( 'mu-plugins', WPMU_PLUGIN_DIR ),
			array( 'plugins', WP_PLUGIN_DIR ),
		);
		foreach ( self::theme_dirs() as $theme_dir ) {
			$dirs[] = array( 'themes', $theme_dir );
		}
		$dirs[] = array( 'content', WP_CONTENT_DIR );

		$bases = array();
		foreach ( $dirs as $pair ) {
			$dir = trailingslashit( wp_normalize_path( $pair[1] ) );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- realpath() warns under open_basedir when a symlinked base resolves outside the allowed paths; false is handled.
			$real    = @realpath( $pair[1] );
			$real    = false === $real ? '' : trailingslashit( wp_normalize_path( $real ) );
			$real    = $real === $dir ? '' : $real;
			$bases[] = array(
				'name'     => $pair[0],
				'dir'      => $dir,
				'dir_cmp'  => self::lower( $dir ),
				'real'     => $real,
				'real_cmp' => self::lower( $real ),
			);
		}

		usort(
			$bases,
			function ( $a, $b ) {
				return strlen( $b['dir_cmp'] ) - strlen( $a['dir_cmp'] );
			}
		);

		self::$bases = $bases;
		return $bases;
	}

	/**
	 * Absolute theme root directories, resolved like get_theme_root().
	 *
	 * @return string[]
	 */
	private static function theme_dirs() {
		global $wp_theme_directories;

		$roots = function_exists( 'get_theme_roots' ) ? get_theme_roots() : '/themes';
		$roots = array_unique( array_values( (array) $roots ) );
		$out   = array();
		foreach ( $roots as $root ) {
			if ( in_array( $root, (array) $wp_theme_directories, true ) ) {
				$out[] = $root;
			} else {
				$out[] = WP_CONTENT_DIR . $root;
			}
		}
		if ( empty( $out ) ) {
			$out[] = WP_CONTENT_DIR . '/themes';
		}
		return array_unique( $out );
	}

	/**
	 * Known drop-in file names (keys of _get_dropins()).
	 *
	 * @return array
	 */
	private static function dropins() {
		if ( null === self::$dropins ) {
			if ( ! function_exists( '_get_dropins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			self::$dropins = _get_dropins();
		}
		return self::$dropins;
	}

	/**
	 * Lowercases on Windows only.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function lower( $path ) {
		return '\\' === DIRECTORY_SEPARATOR ? strtolower( $path ) : $path;
	}
}
