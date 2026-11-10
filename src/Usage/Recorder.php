<?php
/**
 * Usage recorder: one fileset hit per sampled request (spec §7.2).
 *
 * Runs on live sites at `shutdown`. It must never break a request: everything is inside
 * try/catch, DB errors are suppressed, lock waits are limited to 1 s, and failures only
 * set `last_error` and pause recording for 10 minutes.
 * Loaded only on sampled requests (spec §5.3), so it uses $wpdb->prefix directly
 * instead of loading Install.
 *
 * @package Upshift
 */

namespace Upshift\Usage;

use Upshift\Paths;
use Upshift\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Records which content files a request loaded, as a hashed set.
 */
class Recorder {

	const FLAGS   = 'upshift_recorder_flags';
	const SLOW_MS = 3.0;

	// upshift_filesets.format (DECISIONS D55): how the `files` column stores the file list.
	const FORMAT_KEYS  = 0; // Path keys, one per line (rows from before DB v5).
	const FORMAT_PATHS = 1; // Raw paths as the recorder saw them, until Rollup converts them.
	const FORMAT_IDS   = 2; // Packed runs of dictionary ids (upshift_files).

	// Upper edges (%) of the prep-time / request-time histogram buckets ovh_0..ovh_5; ovh_6 = 10 % and over (D38).
	const OVERHEAD_EDGES = array( 0.5, 1, 2, 3, 5, 10 );
	const PRIMARY_MASK   = 127;

	const SLOW_RECORD_MS = 500.0;
	const PAUSE_SECONDS  = 600;

	const FRONT       = 1;
	const ADMIN       = 2;
	const AJAX        = 4;
	const REST        = 8;
	const CRON        = 16;
	const CLI         = 32;
	const LOGIN       = 64;
	const WC_CHECKOUT = 128;
	const WC_CART     = 256;
	const WC_PRODUCT  = 512;
	const WC_SHOP     = 1024;
	const WC_ACCOUNT  = 2048;

	/**
	 * Content base prefixes (comparison form, nested ones removed) => length.
	 *
	 * @var array|null
	 */
	private static $content_prefixes = null;

	/**
	 * This plugin's directory prefixes (comparison form, as given and real path) => length.
	 *
	 * @var array|null
	 */
	private static $own_prefixes = null;

	/**
	 * Tests/bench only: force the md5 branch of set_hash() on PHP 8.1+ (see test_force_md5()).
	 *
	 * @var bool
	 */
	private static $force_md5 = false;

	/**
	 * Shutdown callback. Never throws, never outputs.
	 */
	public function record() {
		global $wpdb;

		$start = microtime( true );
		if ( ( defined( 'UPSHIFT_SCANNING' ) && UPSHIFT_SCANNING ) || ! ( $wpdb instanceof \wpdb ) ) {
			return;
		}
		// After a fatal error (shutdown still runs): don't record the crashed request or risk a second fatal.
		$last = error_get_last();
		if ( null !== $last && 0 !== ( $last['type'] & ( E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR ) ) ) {
			return;
		}

		// The request's own duration up to this shutdown callback, before any recorder work (DECISIONS D38).
		$req_ms   = self::request_ms( $start );
		$suppress = $wpdb->suppress_errors( true );
		$limited  = false;
		try {
			$prep = self::prepare();
			if ( null !== $prep ) {
				$limited = self::limit_lock_wait();
				if ( ! self::write( $prep['hash'], current_time( 'Y-m-d' ), $prep['context'], $prep['prep_ms'], $prep['paths'], $req_ms ) ) {
					self::pause();
				}
			}
		} catch ( \Throwable $e ) {
			self::set_error( get_class( $e ) . ': ' . $e->getMessage() );
			self::note_drop( 'error' );
			self::pause();
		} finally {
			// Measured before the restore, so a rare slow-flag write still runs under the 1 s lock limit.
			self::note_duration( ( microtime( true ) - $start ) * 1000 );
			if ( $limited ) {
				self::restore_lock_wait();
			}
			$wpdb->suppress_errors( $suppress );
		}
	}

	/**
	 * Request duration in ms from REQUEST_TIME_FLOAT to $now, or null when unknown or not positive.
	 *
	 * @param float $now microtime( true ).
	 * @return float|null
	 */
	public static function request_ms( $now ) {
		if ( empty( $_SERVER['REQUEST_TIME_FLOAT'] ) || ! is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- numeric server timestamp, cast below.
			return null;
		}
		$ms = ( $now - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000;
		return $ms > 0 ? $ms : null;
	}

	/**
	 * Histogram bucket (0–6) of prep time as a percentage of request time; upper edges in OVERHEAD_EDGES.
	 *
	 * @param float $prep_ms Prep time.
	 * @param float $req_ms  Request time (> 0).
	 * @return int
	 */
	public static function overhead_bucket( $prep_ms, $req_ms ) {
		$pct = 100 * $prep_ms / $req_ms;
		foreach ( self::OVERHEAD_EDGES as $i => $edge ) {
			if ( $pct < $edge ) {
				return $i;
			}
		}
		return count( self::OVERHEAD_EDGES );
	}

	/**
	 * Limits row-lock waits for this connection to 1 s (DECISIONS D18). The old value is kept
	 * in the session variable @upshift_lwt. Callers suppress DB errors.
	 *
	 * @return bool Whether the limit was set (only then call restore_lock_wait()).
	 */
	public static function limit_lock_wait() {
		global $wpdb;
		try {
			return false !== $wpdb->query( 'SET @upshift_lwt = @@SESSION.innodb_lock_wait_timeout, SESSION innodb_lock_wait_timeout = 1' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Restores the lock wait timeout saved by limit_lock_wait().
	 */
	public static function restore_lock_wait() {
		global $wpdb;
		try {
			$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = @upshift_lwt' );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Records the whole record() duration when it is over 500 ms and above the stored maximum.
	 * Writes only when the value rises, so the write stays rare.
	 *
	 * @param float $ms Duration in ms.
	 */
	private static function note_duration( $ms ) {
		if ( $ms <= self::SLOW_RECORD_MS ) {
			return;
		}
		$flags = self::flags();
		if ( isset( $flags['slow_record_ms_max'] ) && (float) $flags['slow_record_ms_max'] >= $ms ) {
			return;
		}
		$flags['slow_record_ms_max'] = round( $ms, 1 );
		$flags['slow_record_at']     = current_time( 'mysql' );
		self::save_flags( $flags );
	}

	/**
	 * Pauses recording for 10 minutes after a failure (DECISIONS D18). Stored in the autoloaded
	 * settings, which the sampling decision already reads, so the check costs no query.
	 * Reads the option fresh and changes only this key; no sanitize, no other side effects.
	 */
	private static function pause() {
		try {
			$settings = Settings::stored();
			if ( null === $settings ) {
				return;
			}
			$settings['recorder_paused_until'] = time() + self::PAUSE_SECONDS;
			update_option( Settings::OPTION, $settings );
			Settings::flush();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Hot-path CPU work (§7.2): filter to content files, sort, hash, context bitmask.
	 * No Paths::key() here; keys are built only for new sets (DECISIONS D13).
	 *
	 * @param string[]|null $files Included files; null = get_included_files().
	 * @return array|null array( 'paths', 'hash', 'context', 'prep_ms' ), or null when no content files.
	 */
	public static function prepare( $files = null ) {
		$t0 = microtime( true );

		if ( null === $files ) {
			$files = get_included_files();
		}
		$paths = self::filter( $files );
		if ( empty( $paths ) ) {
			return null;
		}
		$hash    = self::set_hash( $paths );
		$context = self::context();

		return array(
			'paths'   => $paths,
			'hash'    => $hash,
			'context' => $context,
			'prep_ms' => ( microtime( true ) - $t0 ) * 1000,
		);
	}

	/**
	 * Sorted, unique raw paths of content files (comparison form: forward slashes,
	 * lowercased on Windows), without this plugin's files. Kept: anything under plugins,
	 * mu-plugins or a theme root, and top-level *.php files directly in wp-content (drop-ins).
	 * Other wp-content files (uploads/, cache/, compiled templates) are dropped so generated
	 * files can't create a new set per request (DECISIONS D19). Core files, PHP includes and
	 * phar:// paths never start with a content base, so they drop out too.
	 *
	 * @param string[] $files Absolute paths.
	 * @return string[]
	 */
	public static function filter( array $files ) {
		return self::blocks( self::sorted( $files ) );
	}

	/**
	 * The sort part of filter(): every included file in comparison form, sorted byte-wise.
	 * In the sorted list all paths under one prefix form one contiguous block (DECISIONS D46).
	 *
	 * @param string[] $files Absolute paths.
	 * @return string[]
	 */
	public static function sorted( array $files ) {
		$files = str_replace( '\\', '/', $files );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$files = array_map( 'strtolower', $files );
		}
		sort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * The filter part of filter(): the kept paths of a sorted() list, sorted and unique.
	 * Each prefix's block is found by binary search and taken with array_slice(), so the work
	 * doesn't grow with the file count and needs no PCRE (no JIT dependence, DECISIONS D46).
	 * wp-content blocks are walked one top-level entry at a time: a file is kept if it ends in
	 * .php, a subdirectory is skipped with one more binary search.
	 *
	 * @param string[] $sorted Output of sorted().
	 * @return string[]
	 */
	public static function blocks( array $sorted ) {
		$prefixes = self::content_prefixes();
		$count    = count( $sorted );
		$keep     = array();
		foreach ( $prefixes['dirs'] as $prefix => $len ) {
			$keep[] = self::block( $sorted, $prefix, $len, 0, $count );
		}
		foreach ( $prefixes['content'] as $prefix => $len ) {
			list( $i, $end ) = self::block( $sorted, $prefix, $len, 0, $count );
			while ( $i < $end ) {
				$slash = strpos( $sorted[ $i ], '/', $len );
				if ( false === $slash ) {
					if ( '.php' === substr( $sorted[ $i ], -4 ) ) {
						$keep[] = array( $i, $i + 1 );
					}
					++$i;
				} else {
					$i = self::bound( $sorted, substr( $sorted[ $i ], 0, $slash + 1 ), $slash + 1, $i, $end, true );
				}
			}
		}
		$drop = array();
		foreach ( self::own_prefixes() as $prefix => $len ) {
			$drop[] = self::block( $sorted, $prefix, $len, 0, $count );
		}

		$slices = array();
		foreach ( self::subtract( self::merge( $keep ), self::merge( $drop ) ) as $range ) {
			$slices[] = array_slice( $sorted, $range[0], $range[1] - $range[0] );
		}
		if ( empty( $slices ) ) {
			return array();
		}
		// Paths contain '/', so they stay string keys; array_keys( array_flip() ) = array_unique(),
		// and duplicates are adjacent, so the result stays sorted.
		return array_keys( array_flip( array_merge( ...$slices ) ) );
	}

	/**
	 * Index range [start, end) of the paths starting with $prefix in $sorted[ $lo .. $hi ).
	 *
	 * @param string[] $sorted Sorted paths.
	 * @param string   $prefix Prefix.
	 * @param int      $len    Prefix length.
	 * @param int      $lo     First index.
	 * @param int      $hi     End index.
	 * @return int[] array( start, end ).
	 */
	private static function block( array $sorted, $prefix, $len, $lo, $hi ) {
		$start = self::bound( $sorted, $prefix, $len, $lo, $hi, false );
		return array( $start, self::bound( $sorted, $prefix, $len, $start, $hi, true ) );
	}

	/**
	 * Binary search: the first index in [ $lo, $hi ) whose first $len bytes compare >= $prefix
	 * (or > $prefix when $after), i.e. the start (or end) of the prefix's block.
	 *
	 * @param string[] $sorted Sorted paths.
	 * @param string   $prefix Prefix.
	 * @param int      $len    Prefix length.
	 * @param int      $lo     First index.
	 * @param int      $hi     End index.
	 * @param bool     $after  Find the end of the block instead of its start.
	 * @return int
	 */
	private static function bound( array $sorted, $prefix, $len, $lo, $hi, $after ) {
		while ( $lo < $hi ) {
			$mid = ( $lo + $hi ) >> 1;
			$cmp = strncmp( $sorted[ $mid ], $prefix, $len );
			if ( $cmp < 0 || ( $after && 0 === $cmp ) ) {
				$lo = $mid + 1;
			} else {
				$hi = $mid;
			}
		}
		return $lo;
	}

	/**
	 * Index ranges sorted by start, empty ones dropped, overlapping/adjacent ones joined.
	 *
	 * @param array $ranges array( start, end ) pairs.
	 * @return array
	 */
	private static function merge( array $ranges ) {
		usort(
			$ranges,
			function ( $a, $b ) {
				return $a[0] - $b[0];
			}
		);
		$out  = array();
		$last = -1;
		foreach ( $ranges as $range ) {
			if ( $range[0] >= $range[1] ) {
				continue;
			}
			if ( $last >= 0 && $range[0] <= $out[ $last ][1] ) {
				$out[ $last ][1] = max( $out[ $last ][1], $range[1] );
			} else {
				$out[] = $range;
				++$last;
			}
		}
		return $out;
	}

	/**
	 * Merged ranges $keep minus merged ranges $drop.
	 *
	 * @param array $keep Output of merge().
	 * @param array $drop Output of merge().
	 * @return array
	 */
	private static function subtract( array $keep, array $drop ) {
		$out = array();
		foreach ( $keep as $range ) {
			list( $start, $end ) = $range;
			foreach ( $drop as $cut ) {
				if ( $cut[1] <= $start || $cut[0] >= $end ) {
					continue;
				}
				if ( $cut[0] > $start ) {
					$out[] = array( $start, $cut[0] );
				}
				$start = max( $start, $cut[1] );
			}
			if ( $start < $end ) {
				$out[] = array( $start, $end );
			}
		}
		return $out;
	}

	/**
	 * Set hash of filter() output: xxh128 on PHP 8.1+, md5 below (both 32 hex chars, DECISIONS D24).
	 *
	 * @param string[] $paths Output of filter().
	 * @return string
	 */
	public static function set_hash( array $paths ) {
		$list = implode( "\n", $paths );
		if ( 'xxh128' === self::hash_algo() ) {
			return hash( 'xxh128', $list ); // phpcs:ignore PHPCompatibility.ParameterValues.NewHashAlgorithms.xxh128Found -- only used on PHP 8.1+, see hash_algo().
		}
		return md5( $list );
	}

	/**
	 * Set hash algorithm in use.
	 *
	 * @return string 'xxh128' or 'md5'.
	 */
	public static function hash_algo() {
		return PHP_VERSION_ID >= 80100 && ! self::$force_md5 ? 'xxh128' : 'md5';
	}

	/**
	 * Tests/bench only (tests/recorder-check.php, tests/bench-prep.php): force the md5 branch
	 * of set_hash(). Never called by plugin code.
	 *
	 * @param bool $on Force md5.
	 */
	public static function test_force_md5( $on ) {
		self::$force_md5 = (bool) $on;
	}

	/**
	 * Sorted, unique path keys for filtered paths (new sets only, not the hot path).
	 *
	 * @param string[] $paths Output of filter().
	 * @return string[]
	 */
	public static function keys_for( array $paths ) {
		$keys = array();
		foreach ( $paths as $path ) {
			$key = Paths::key( $path );
			if ( null !== $key ) {
				$keys[ $key ] = true;
			}
		}
		$keys = array_keys( $keys );
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * Context bitmask: one primary bit (cli > cron > rest > ajax > login > admin > front),
	 * plus WooCommerce bits on front-end requests.
	 *
	 * @return int
	 */
	public static function context() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return self::CLI;
		}
		if ( wp_doing_cron() ) {
			return self::CRON;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::REST;
		}
		if ( wp_doing_ajax() ) {
			return self::AJAX;
		}
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return self::LOGIN;
		}
		if ( is_admin() ) {
			return self::ADMIN;
		}

		$context = self::FRONT;
		if ( ! did_action( 'wp' ) ) {
			return $context;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$context |= self::WC_CHECKOUT;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			$context |= self::WC_CART;
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			$context |= self::WC_PRODUCT;
		}
		if ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
			$context |= self::WC_SHOP;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$context |= self::WC_ACCOUNT;
		}
		return $context;
	}

	/**
	 * DB part: one upsert; only when the hit row is new, the new-set path (§7.2 steps 5–6).
	 * Callers suppress DB errors. On failure sets last_error and returns false.
	 *
	 * @param string   $hash    Set hash.
	 * @param string   $day     Y-m-d.
	 * @param int      $context Context bitmask.
	 * @param float    $prep_ms Prep time in ms.
	 * @param string[] $paths   Output of filter().
	 * @param float    $req_ms  Request duration in ms (null = unknown).
	 * @return bool
	 */
	public static function write( $hash, $day, $context, $prep_ms, array $paths, $req_ms = null ) {
		$affected = self::upsert_hit( $hash, $day, $context, $prep_ms, $req_ms );
		if ( false === $affected ) {
			self::note_drop( 'hit' );
			return false;
		}
		// 1 = inserted (new set/day/context), 2 = updated an existing row.
		if ( 1 !== $affected ) {
			return true;
		}
		if ( self::store_new_set( $hash, $paths ) ) {
			return true;
		}
		// Without this, later requests only update the hit row and the file list stays missing all day (DECISIONS D63).
		self::remove_hit( $hash, $day, $context );
		self::note_drop( 'set' );
		return false;
	}

	/**
	 * Removes the hit row of a set that could not be stored, so the next request with this set is "new" again. Only a row
	 * still at 1 hit: a row another request has counted since keeps its count. Failure path only.
	 *
	 * @param string $hash    Set hash.
	 * @param string $day     Y-m-d.
	 * @param int    $context Context bitmask.
	 */
	private static function remove_hit( $hash, $day, $context ) {
		global $wpdb;

		$table = $wpdb->prefix . 'upshift_fileset_hits';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE set_hash = %s AND day = %s AND context = %d AND hits = 1", $hash, $day, $context ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
	}

	/**
	 * Counts one dropped page view by reason in the recorder flags (failure paths only, DECISIONS D63).
	 * Reasons: hit (the counter could not be written), set (the file list could not be stored), error (an exception).
	 *
	 * @param string $reason Reason.
	 */
	private static function note_drop( $reason ) {
		$flags = self::flags();
		if ( ! isset( $flags['dropped'] ) || ! is_array( $flags['dropped'] ) ) {
			$flags['dropped'] = array();
		}
		$flags['dropped'][ $reason ] = ( isset( $flags['dropped'][ $reason ] ) ? (int) $flags['dropped'][ $reason ] : 0 ) + 1;
		$flags['dropped_at']         = time();
		self::save_flags( $flags );
	}

	/**
	 * The hot-path query: one upsert into fileset_hits.
	 *
	 * @param string $hash    Set hash.
	 * @param string $day     Y-m-d.
	 * @param int    $context Context bitmask.
	 * @param float  $prep_ms Prep time in ms.
	 * @param float  $req_ms  Request duration in ms (null = unknown: no request time, no histogram count).
	 * @return int|false Rows affected (1 = new row, 2 = updated), false on error.
	 */
	public static function upsert_hit( $hash, $day, $context, $prep_ms, $req_ms = null ) {
		global $wpdb;

		// One histogram bucket gets 1 when the request time is known (DECISIONS D38).
		$ovh = array_fill( 0, count( self::OVERHEAD_EDGES ) + 1, 0 );
		if ( null !== $req_ms && $req_ms > 0 ) {
			$ovh[ self::overhead_bucket( $prep_ms, $req_ms ) ] = 1;
		} else {
			$req_ms = null;
		}

		$table  = $wpdb->prefix . 'upshift_fileset_hits';
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; one query by design (spec §7.2).
				"INSERT INTO {$table} (set_hash, day, context, hits, prep_ms_total, prep_ms_max, slow_hits, req_hits, req_ms_total, ovh_0, ovh_1, ovh_2, ovh_3, ovh_4, ovh_5, ovh_6)
				VALUES (%s, %s, %d, 1, %f, %f, %d, %d, %f, %d, %d, %d, %d, %d, %d, %d)
				ON DUPLICATE KEY UPDATE hits = hits + 1,
					prep_ms_total = prep_ms_total + VALUES(prep_ms_total),
					prep_ms_max = GREATEST(prep_ms_max, VALUES(prep_ms_max)),
					slow_hits = slow_hits + VALUES(slow_hits),
					req_hits = req_hits + VALUES(req_hits),
					req_ms_total = req_ms_total + VALUES(req_ms_total),
					ovh_0 = ovh_0 + VALUES(ovh_0), ovh_1 = ovh_1 + VALUES(ovh_1), ovh_2 = ovh_2 + VALUES(ovh_2),
					ovh_3 = ovh_3 + VALUES(ovh_3), ovh_4 = ovh_4 + VALUES(ovh_4), ovh_5 = ovh_5 + VALUES(ovh_5),
					ovh_6 = ovh_6 + VALUES(ovh_6)",
				$hash,
				$day,
				$context,
				$prep_ms,
				$prep_ms,
				$prep_ms >= self::SLOW_MS ? 1 : 0,
				null === $req_ms ? 0 : 1,
				null === $req_ms ? 0.0 : $req_ms,
				$ovh[0],
				$ovh[1],
				$ovh[2],
				$ovh[3],
				$ovh[4],
				$ovh[5],
				$ovh[6]
			)
		);
		if ( false === $result ) {
			self::set_error( 'fileset_hits: ' . self::db_error() );
			return false;
		}
		return (int) $wpdb->rows_affected;
	}

	/**
	 * New-set path: if the set isn't stored yet, store its raw paths once (INSERT IGNORE). No cap and no time budget, so
	 * only a failing database can lose a set; Rollup turns the paths into dictionary ids later (DECISIONS D55).
	 *
	 * @param string   $hash  Set hash.
	 * @param string[] $paths Output of filter().
	 * @return bool
	 */
	public static function store_new_set( $hash, array $paths ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'upshift_filesets';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE set_hash = %s LIMIT 1", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		if ( '' !== (string) $wpdb->last_error ) {
			self::set_error( 'filesets: ' . self::db_error() );
			return false;
		}
		if ( null !== $exists ) {
			return true;
		}

		$list       = implode( "\n", $paths );
		$compressed = 0;
		if ( function_exists( 'gzcompress' ) ) {
			// Level 1: this runs in a request, and the row only lives until the next conversion.
			$packed = gzcompress( $list, 1 );
			if ( false !== $packed ) {
				$list       = $packed;
				$compressed = 1;
			}
		}

		// Hex keeps the query ASCII so wpdb's invalid-text check can't reject binary data (DECISIONS D15).
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (set_hash, file_count, files, is_compressed, format, created) VALUES (%s, %d, UNHEX(%s), %d, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$hash,
				count( $paths ),
				bin2hex( $list ),
				$compressed,
				self::FORMAT_PATHS,
				current_time( 'mysql' )
			)
		);
		if ( false === $result ) {
			self::set_error( 'filesets: ' . self::db_error() );
			return false;
		}
		return true;
	}

	/**
	 * Last DB error text.
	 *
	 * @return string
	 */
	private static function db_error() {
		global $wpdb;
		return '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : 'unknown database error';
	}

	/**
	 * Stores last_error (max 500 chars). Writes only when the message changes.
	 *
	 * @param string $message Error text.
	 */
	public static function set_error( $message ) {
		$message = substr( (string) $message, 0, 500 );
		$flags   = self::flags();
		if ( isset( $flags['last_error'] ) && $flags['last_error'] === $message ) {
			return;
		}
		$flags['last_error']    = $message;
		$flags['last_error_at'] = current_time( 'mysql' );
		self::save_flags( $flags );
	}

	/**
	 * Current recorder flags.
	 *
	 * @return array
	 */
	public static function flags() {
		try {
			$flags = get_option( self::FLAGS, array() );
		} catch ( \Throwable $e ) {
			$flags = array();
		}
		return is_array( $flags ) ? $flags : array();
	}

	/**
	 * Saves flags (autoload off). Never throws.
	 *
	 * @param array $flags Flags.
	 */
	private static function save_flags( array $flags ) {
		try {
			update_option( self::FLAGS, $flags, false );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Base prefixes from Paths (bases + their real paths), computed once per request.
	 * 'dirs' = plugins, mu-plugins and theme roots (every file kept); a dir prefix nested in a
	 * shorter dir prefix is dropped. 'content' = wp-content (top-level *.php only).
	 *
	 * @return array array( 'dirs' => prefix => length, 'content' => prefix => length ).
	 */
	private static function content_prefixes() {
		if ( null === self::$content_prefixes ) {
			$dirs    = array();
			$content = array();
			foreach ( Paths::base_prefixes() as $prefix => $name ) {
				if ( 'content' === $name ) {
					$content[ $prefix ] = strlen( $prefix );
				} else {
					$dirs[] = $prefix;
				}
			}
			usort(
				$dirs,
				function ( $a, $b ) {
					return strlen( $a ) - strlen( $b );
				}
			);
			$out = array();
			foreach ( $dirs as $prefix ) {
				foreach ( $out as $shorter => $len ) {
					if ( 0 === strncmp( $prefix, $shorter, $len ) ) {
						continue 2;
					}
				}
				$out[ $prefix ] = strlen( $prefix );
			}
			self::$content_prefixes = array(
				'dirs'    => $out,
				'content' => $content,
			);
		}
		return self::$content_prefixes;
	}

	/**
	 * This plugin's directory, from UPSHIFT_DIR and its real path (a renamed folder still works).
	 *
	 * @return array prefix => length.
	 */
	private static function own_prefixes() {
		if ( null === self::$own_prefixes ) {
			$out = array();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- realpath() warns under open_basedir when a symlinked plugin dir resolves outside the allowed paths; false is handled.
			$dirs = array( UPSHIFT_DIR, @realpath( UPSHIFT_DIR ) );
			foreach ( $dirs as $dir ) {
				if ( false !== $dir && '' !== $dir ) {
					$prefix         = Paths::normalize( trailingslashit( $dir ) );
					$out[ $prefix ] = strlen( $prefix );
				}
			}
			self::$own_prefixes = $out;
		}
		return self::$own_prefixes;
	}

	/**
	 * Filter shape for the benchmark's environment line (T2.3): prefix counts.
	 *
	 * @return array name => number.
	 */
	public static function filter_shape() {
		$prefixes = self::content_prefixes();
		return array(
			'dir prefixes'        => count( $prefixes['dirs'] ),
			'wp-content prefixes' => count( $prefixes['content'] ),
			'own prefixes'        => count( self::own_prefixes() ),
		);
	}

	/**
	 * Builds the per-request prefix caches (incl. realpath()). Bench only: it times this as the
	 * "setup" phase. On a real request blocks() builds the same caches lazily inside prepare().
	 */
	public static function warm() {
		self::content_prefixes();
		self::own_prefixes();
	}

	/**
	 * Sets the prefix caches directly (synthetic benchmark only, tests/bench-prep.php).
	 *
	 * @param array $dirs    Dir prefix => length (plugins, mu-plugins, theme roots).
	 * @param array $content wp-content prefix => length.
	 * @param array $own     Own plugin prefix => length.
	 */
	public static function prime( array $dirs, array $content, array $own ) {
		self::$content_prefixes = array(
			'dirs'    => $dirs,
			'content' => $content,
		);
		self::$own_prefixes     = $own;
	}

	/**
	 * Clears per-request caches (bench uses this to measure a cold request).
	 */
	public static function reset() {
		self::$content_prefixes = null;
		self::$own_prefixes     = null;
	}

	/**
	 * Context bit labels.
	 *
	 * @return array bit => label.
	 */
	public static function labels() {
		return array(
			self::FRONT       => __( 'front end', 'upshift' ),
			self::ADMIN       => __( 'admin', 'upshift' ),
			self::AJAX        => __( 'ajax', 'upshift' ),
			self::REST        => __( 'rest', 'upshift' ),
			self::CRON        => __( 'cron', 'upshift' ),
			self::CLI         => __( 'cli', 'upshift' ),
			self::LOGIN       => __( 'login', 'upshift' ),
			self::WC_CHECKOUT => __( 'checkout', 'upshift' ),
			self::WC_CART     => __( 'cart', 'upshift' ),
			self::WC_PRODUCT  => __( 'product', 'upshift' ),
			self::WC_SHOP     => __( 'shop', 'upshift' ),
			self::WC_ACCOUNT  => __( 'account', 'upshift' ),
		);
	}

	/**
	 * Human list of the bits in a mask, e.g. "front end, checkout".
	 *
	 * @param int $mask Bitmask.
	 * @return string
	 */
	public static function label( $mask ) {
		$out = array();
		foreach ( self::labels() as $bit => $label ) {
			if ( (int) $mask & $bit ) {
				$out[] = $label;
			}
		}
		return implode( ', ', $out );
	}
}
