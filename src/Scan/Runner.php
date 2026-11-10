<?php
/**
 * Scan lifecycle: start, resumable batches with time and memory budgets, lock, cancel (spec §7.6).
 *
 * @package Upshift
 */

namespace Upshift\Scan;

use Upshift\Engine\Engine;
use Upshift\Engine\Requirements;
use Upshift\Install;
use Upshift\Paths;
use Upshift\Report\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Scan runner. Every query uses table names from $wpdb->prefix only.
 */
class Runner {

	const LOCK           = 'upshift_scan_lock';
	const LOCK_TTL       = 120;
	const STATS          = 'upshift_scan_stats';
	const CHUNK          = 25;
	const FINDINGS_CHUNK = 100;
	const MAX_SECONDS    = 15;
	const MEMORY_SHARE   = 0.75;
	const NOTE_MAX       = 1000;

	/**
	 * Note of a file that was claimed in a block when its request died (DECISIONS D57). It is scanned again on its own.
	 */
	const SUSPECT = 'suspect';

	/**
	 * Cached results not used for this many days are deleted when a scan finishes (DECISIONS D57).
	 */
	const CACHE_DAYS = 30;

	/**
	 * Background scanning (DECISIONS D57): a single WP-Cron event per scan id runs one batch and schedules the next. While
	 * a page drives the scan (its last batch request is younger than the grace, longer in gentle mode where the page waits
	 * minutes between batches) the event only reschedules itself.
	 */
	const STEP_HOOK       = 'upshift_scan_step';
	const PAGE_AT         = 'upshift_scan_page_until';
	const PAGE_GRACE      = 130;
	const PAGE_GRACE_SLOW = 400;
	const STEP_DELAY      = 60;

	/**
	 * Rules version for the result cache: md5 of the engine build and the code that shapes a result. '' = not computed.
	 *
	 * @var string|null
	 */
	private static $cache_rules = null;

	/**
	 * Seconds after which results saved in a transaction are committed, however few files there are.
	 */
	const COMMIT_SECONDS = 3;

	/**
	 * Seconds between lock refreshes during a batch (well inside LOCK_TTL).
	 */
	const LOCK_REFRESH = 30;

	/**
	 * Estimated engine memory of a file: a fixed part plus a cost per token, counted without tokenizing (DECISIONS D57).
	 * Measured on 425 real files (engine peak per file): 733-919 bytes per token for files of 100 KB and up. 6 MB covers
	 * the fixed part and the few-token outliers; 880 is the measured upper envelope plus 10 %. The old flat 320 bytes per
	 * file byte (D40) over-estimated 2-4 times for string-heavy files such as generated containers and icon lists.
	 */
	const EST_BASE      = 6291456;
	const EST_PER_TOKEN = 880;

	/**
	 * Per file byte, when the tokens can't be counted (the regex failed): the old flat rule, rounded up.
	 */
	const EST_PER_BYTE = 350;

	/**
	 * A file that doesn't fit the memory of a fresh request is tried in this many further fresh requests before it is
	 * skipped (DECISIONS D57).
	 */
	const MEMORY_RETRIES = 2;

	/**
	 * Counts tokens like PHP does: a string, a name or variable, a number, a run of white space, a comment, or one other
	 * character each. preg_match_all() without a result array allocates nothing.
	 */
	const TOKEN_RX = '~\'(?:[^\'\\\\]++|\\\\.)*+\'|"(?:[^"\\\\]++|\\\\.)*+"|[A-Za-z_\x80-\xff$][A-Za-z0-9_\x80-\xff]*+|[0-9]++|\s++|//[^\n]*+|#[^\n]*+|/\*.*?\*/|[^\sA-Za-z0-9_$\'"]~s';

	/**
	 * After a file that grew the real memory by more than this, the memory the engine freed is handed back (bytes).
	 */
	const GC_GROWTH = 8388608;

	/**
	 * A file whose estimate doesn't fit under this share of memory_limit even in a fresh request is skipped.
	 */
	const HARD_SHARE = 0.9;

	/**
	 * Room for one large file in the "memory needed" message (bytes; DECISIONS D44).
	 */
	const FILE_ROOM = 33554432;

	/**
	 * Creates a scan, runs the inventory and marks it running.
	 *
	 * @param string $from     From version.
	 * @param string $target   Target version.
	 * @param string $only     Limit to this component id ('' = all).
	 * @param bool   $inactive Also scan inactive plugins (the Scan tab's default is no; WP-CLI's is yes; DECISIONS D54).
	 * @return int|\WP_Error Scan id. Error codes: range, engine, running (data: scan_id), busy, component, inventory.
	 */
	public static function start( $from, $target, $only = '', $inactive = true ) {
		$error = Engine::range_error( (string) $from, (string) $target );
		if ( '' !== $error ) {
			return new \WP_Error( 'range', $error );
		}
		if ( ! Engine::is_available() ) {
			return new \WP_Error( 'engine', implode( ' ', \Upshift\Engine\Requirements::problems() ) );
		}
		// The inventory runs under the scan lock, so a 'queued' scan found while we hold it was left by a request
		// that died during its inventory (DECISIONS D47).
		$token = self::lock( 0 );
		if ( false === $token ) {
			return new \WP_Error( 'busy', __( 'Another scan request is still running.', 'upshift' ) );
		}
		try {
			return self::start_locked( (string) $from, (string) $target, (string) $only, $token, (bool) $inactive );
		} finally {
			self::unlock( $token );
		}
	}

	/**
	 * Body of start(), run while holding the scan lock.
	 *
	 * @param string $from     From version.
	 * @param string $target   Target version.
	 * @param string $only     Component id or ''.
	 * @param string $token    Lock token.
	 * @param bool   $inactive Also scan inactive plugins.
	 * @return int|\WP_Error
	 */
	private static function start_locked( $from, $target, $only, $token, $inactive ) {
		global $wpdb;

		$table = Install::table( 'scans' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', finished = %s, error = %s WHERE status = 'queued'",
				current_time( 'mysql' ),
				sprintf(
					/* translators: %s: WP-CLI command. */
					__( 'The file inventory did not finish (the request hit a time or memory limit). Start the scan again; if this keeps happening, run it with WP-CLI: %s', 'upshift' ),
					'wp upshift scan'
				)
			)
		);
		// Any running scan blocks a new one, lock or not: it can be resumed or cancelled (DECISIONS D30).
		$running = self::latest( 'running' );
		if ( null !== $running ) {
			return new \WP_Error(
				'running',
				/* translators: %d: scan id. */
				sprintf( __( 'A scan is already running (#%d). Resume or cancel it.', 'upshift' ), (int) $running['id'] ),
				array( 'scan_id' => (int) $running['id'] )
			);
		}

		$wpdb->insert(
			$table,
			array(
				'status'           => 'queued',
				'from_version'     => (string) $from,
				'target_version'   => (string) $target,
				// 0, not the column default -1: this scan runs the new-risk check (DECISIONS D52).
				'baseline_seconds' => 0,
				'started'          => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%f', '%s' )
		);
		$scan_id = (int) $wpdb->insert_id;
		if ( $scan_id <= 0 ) {
			return new \WP_Error( 'inventory', 'scans insert failed: ' . $wpdb->last_error );
		}

		try {
			$counts = Inventory::populate( $scan_id, (string) $only, $inactive );
		} catch ( \Throwable $e ) {
			self::fail( $scan_id, $e->getMessage() );
			return new \WP_Error( 'inventory', $e->getMessage() );
		}
		if ( '' !== (string) $only && empty( $counts ) ) {
			/* translators: %s: component id. */
			$message = sprintf( __( 'Component not found: %s', 'upshift' ), $only );
			self::fail( $scan_id, $message );
			return new \WP_Error( 'component', $message );
		}

		$total = 0;
		foreach ( $counts as $count ) {
			$total += $count['files'];
		}
		// Only while still ours and still queued: an inventory slower than the lock TTL may have been marked failed.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', files_total = %d, files_done = %d WHERE id = %d AND status = 'queued'", $total, self::done_count( $scan_id ), $scan_id ) );
		if ( 1 !== $updated || ! self::refresh( $token ) ) {
			self::fail( $scan_id, __( 'The file inventory took longer than the scan lock allows.', 'upshift' ) );
			return new \WP_Error( 'inventory', __( 'The file inventory took longer than the scan lock allows.', 'upshift' ) );
		}
		// Display names of the components, for the progress shown while the scan runs (DECISIONS D57).
		$labels = array();
		foreach ( $counts as $component_id => $count ) {
			$labels[ $component_id ] = $count['label'];
		}
		update_option(
			self::STATS,
			array(
				'scan_id'    => $scan_id,
				'mem_base'   => 0,
				'mem_engine' => 0,
				'mem_peak'   => 0,
				'biggest'    => null,
				'labels'     => $labels,
			),
			false
		);
		// If the admin leaves this page, WP-Cron carries on (DECISIONS D57).
		self::ensure_step( $scan_id, self::STEP_DELAY );
		return $scan_id;
	}

	/**
	 * Called by the page's batch request: stamps the time until which the page is considered to be driving the scan.
	 *
	 * @param int  $scan_id Scan id.
	 * @param bool $slow    Gentle mode: the page waits minutes between batches, so the grace is longer.
	 */
	public static function page_batch( $scan_id, $slow = false ) {
		update_option( self::PAGE_AT, array( (int) $scan_id, time() + ( $slow ? self::PAGE_GRACE_SLOW : self::PAGE_GRACE ) ), false );
	}

	/**
	 * Makes sure a background step is scheduled for a running scan.
	 *
	 * @param int $scan_id Scan id.
	 * @param int $delay   Seconds from now.
	 */
	public static function ensure_step( $scan_id, $delay = 0 ) {
		$args = array( (int) $scan_id );
		if ( ! wp_next_scheduled( self::STEP_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::STEP_HOOK, $args );
		}
	}

	/**
	 * Removes a scan's background step (the scan is done, failed or cancelled).
	 *
	 * @param int $scan_id Scan id.
	 */
	private static function clear_step( $scan_id ) {
		wp_clear_scheduled_hook( self::STEP_HOOK, array( (int) $scan_id ) );
	}

	/**
	 * WP-Cron event: one batch of a running scan when no page is driving it, then the next event while it runs. Uses the
	 * same lock and batch() as the page (batch() raises the memory limit itself). Ends when the scan is no longer running.
	 *
	 * @param int $scan_id Scan id.
	 */
	public static function cron_step( $scan_id = 0 ) {
		$scan_id = (int) $scan_id;
		// Files updated but tables not yet (the upgrade runs on the next admin request): do nothing, like the rollup.
		if ( UPSHIFT_DB_VERSION !== get_option( 'upshift_db_version' ) ) {
			return;
		}
		$scan = $scan_id > 0 ? self::get( $scan_id ) : null;
		if ( null === $scan || 'running' !== $scan['status'] ) {
			return;
		}
		$page = get_option( self::PAGE_AT );
		if ( is_array( $page ) && isset( $page[0], $page[1] ) && (int) $page[0] === $scan_id && (int) $page[1] > time() ) {
			self::ensure_step( $scan_id, self::STEP_DELAY );
			return;
		}
		$result = self::batch( $scan_id );
		if ( is_wp_error( $result ) ) {
			// Another batch holds the lock (or the scan row is gone): look again later.
			self::ensure_step( $scan_id, self::STEP_DELAY );
			return;
		}
		if ( 'running' === $result['status'] ) {
			self::ensure_step( $scan_id, 0 );
		}
	}

	/**
	 * Scans pending files until the time or memory budget is used or nothing is left.
	 *
	 * @param int   $scan_id Scan id.
	 * @param array $opts    'time_budget' => seconds (null = min( 15 s, 50% of max_execution_time ), 0 = none),
	 *                       'on_file' => callable( array $row, string $status, float $seconds, int $peak_bytes, array $by_category ),
	 *                       'retry_fresh' => bool (default true): stop the batch before a file whose memory estimate
	 *                       doesn't fit, so a fresh request retries it; false (one-process WP-CLI scan) = skip it instead.
	 * @return array|\WP_Error progress() array plus 'stopped' (time | memory | done | cancelled | lock = the lock was
	 *                        lost to another request | ''), or an error (not_found, busy = another batch holds the lock).
	 */
	public static function batch( $scan_id, array $opts = array() ) {
		global $wpdb;

		$opts    = array_merge(
			array(
				'time_budget' => null,
				'on_file'     => null,
				'retry_fresh' => true,
			),
			$opts
		);
		$scan_id = (int) $scan_id;
		$scan    = self::get( $scan_id );
		if ( null === $scan ) {
			return new \WP_Error( 'not_found', __( 'Scan not found.', 'upshift' ) );
		}
		if ( 'running' !== $scan['status'] ) {
			return self::progress( $scan, '' );
		}
		$token = self::lock( $scan_id );
		if ( false === $token ) {
			return new \WP_Error( 'busy', __( 'Another batch of this scan is still running.', 'upshift' ) );
		}

		if ( ! defined( 'UPSHIFT_SCANNING' ) ) {
			define( 'UPSHIFT_SCANNING', true );
		}
		wp_raise_memory_limit( 'admin' );

		$started = microtime( true );
		// Seconds since the request began: WordPress bootstrap plus the lock (web requests only; DECISIONS D57).
		$boot      = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && 'cli' !== PHP_SAPI ? max( 0.0, $started - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) : 0.0;
		$tx        = array(
			'open' => false,
			'at'   => 0.0,
			'secs' => 0.0,
		);
		$db_secs   = 0.0;
		$budget    = null === $opts['time_budget'] ? self::time_budget() : (float) $opts['time_budget'];
		$mem_cap   = (int) ( self::MEMORY_SHARE * self::memory_limit() );
		$hard_cap  = (int) ( self::HARD_SHARE * self::memory_limit() );
		$stats     = self::stats( $scan_id );
		$base      = memory_get_usage( true );
		$peak      = memory_get_peak_usage( true );
		$seconds   = 0.0;
		$base_secs = 0.0;
		$processed = 0;
		$stopped   = '';
		$failed    = '';
		// Scans started before DB v4 (baseline_seconds -1) don't get the new-risk check halfway through.
		$check = (float) $scan['baseline_seconds'] >= 0 ? (string) $scan['from_version'] : '';
		// Result cache (DECISIONS D57): only with the new-risk check on (its flags are part of a result), an engine build
		// marker and zlib. 'rows' holds the cached rows of the chunk being scanned.
		$cache_ctx = null;
		$reused    = 0;
		$touch     = array();
		if ( '' !== $check && function_exists( 'gzuncompress' ) && '' !== self::cache_rules() ) {
			$cache_ctx = array(
				'range' => $scan['from_version'] . '-' . $scan['target_version'],
				'rules' => self::cache_rules(),
				'rows'  => array(),
			);
		}

		// The limit this batch ran under (after the raise), for M1 in Report\Join.
		$stats['memory_limit'] = max( isset( $stats['memory_limit'] ) ? (int) $stats['memory_limit'] : 0, self::memory_limit() );
		$stats['memory_ini']   = (string) ini_get( 'memory_limit' );
		$stats['sapi']         = PHP_SAPI;

		try {
			$refreshed = time();
			self::reap( $scan_id );

			$t      = microtime( true );
			$engine = Engine::for_range( $scan['from_version'], $scan['target_version'] );
			$ready  = memory_get_usage( true );

			$seconds += microtime( true ) - $t;
			$init     = microtime( true ) - $started;
			if ( 0 === (int) $stats['mem_base'] ) {
				// First batch of the scan: memory before the engine loads, and after its init (ruleset built).
				$stats['mem_base']   = $base;
				$stats['mem_engine'] = $ready;
			}
			$files = Install::table( 'scan_files' );
			$scans = Install::table( 'scans' );

			while ( '' === $stopped ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, path_hash, path_key, component, component_active, note FROM {$files} WHERE scan_id = %d AND status = 'pending' ORDER BY id LIMIT %d", $scan_id, self::CHUNK ), ARRAY_A );
				if ( empty( $rows ) ) {
					// A Cancel during the last chunk leaves no pending rows: not done (DECISIONS D51).
					$stopped = 'running' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$scans} WHERE id = %d", $scan_id ) ) ? 'done' : 'cancelled';
					break;
				}
				if ( 'running' !== $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$scans} WHERE id = %d", $scan_id ) ) ) {
					$stopped = 'cancelled';
					break;
				}

				// One claim for the whole chunk, committed before any file is scanned, so a request that dies leaves the
				// chunk marked and never silently pending (DECISIONS D57, amends D47). Results are saved in a transaction.
				self::tx_commit( $tx );
				$t        = microtime( true );
				$claimed  = self::claim_block( $rows, $token );
				$db_secs += microtime( true ) - $t;
				$todo     = $claimed;
				$retry_id = 0;
				$retry_n  = 0;
				if ( null !== $cache_ctx ) {
					$t                 = microtime( true );
					$cache_ctx['rows'] = self::cache_fetch( $rows, $cache_ctx );
					$db_secs          += microtime( true ) - $t;
				}

				foreach ( $rows as $row ) {
					$suspect = self::SUSPECT === (string) $row['note'];
					if ( ! $suspect && ! isset( $claimed[ (int) $row['id'] ] ) ) {
						continue;
					}
					// At least one file per batch, so a batch always makes progress.
					if ( $processed > 0 && $budget > 0 && microtime( true ) - $started >= $budget ) {
						$stopped = 'time';
						break;
					}
					$in_use = memory_get_usage( true );
					if ( $in_use >= $mem_cap && $in_use > $ready + self::GC_GROWTH ) {
						// Chunks the engine freed for earlier files can still be held: hand them back before judging.
						$in_use = self::free_memory();
					}
					if ( $in_use >= $mem_cap ) {
						$stopped = 'memory';
						break;
					}
					// Memory headroom for this file (DECISIONS D36, D40): a fresh request starts lower, so retry
					// there; skip only what doesn't fit even there (first file of a batch) or in a one-process scan.
					$abs = Paths::resolve( $row['path_key'] );
					// An unchanged file with a cached result needs no engine, so no memory estimate (DECISIONS D57).
					$row['cached'] = null !== $cache_ctx ? self::cache_lookup( $row, $abs, $cache_ctx ) : null;
					$est           = null === $row['cached'] && null !== $abs && is_file( $abs ) ? self::estimate_memory( $abs ) : 0;
					$need          = $in_use + $est;
					if ( $need > $mem_cap && $in_use > $ready + self::GC_GROWTH ) {
						$in_use = self::free_memory();
						$need   = $in_use + $est;
					}
					if ( $processed > 0 && $opts['retry_fresh'] && $need > $mem_cap ) {
						$stopped = 'memory';
						break;
					}
					$skip = null;
					if ( $need > $hard_cap && ( 0 === $processed || ! $opts['retry_fresh'] ) ) {
						// What a request starts with differs by tens of MB from one request to the next (the web server
						// worker it lands on, measured 54-106 MB on one site), so a file that doesn't fit now may fit in
						// the next request: try up to MEMORY_RETRIES more fresh requests before skipping (DECISIONS D57).
						$tries = preg_match( '/^memory-retry:(\d+)$/', (string) $row['note'], $retry_match ) ? (int) $retry_match[1] : 0;
						if ( $opts['retry_fresh'] && ! $suspect && $tries < self::MEMORY_RETRIES ) {
							$retry_id = (int) $row['id'];
							$retry_n  = $tries + 1;
							$stopped  = 'retry';
							break;
						}
						$skip = sprintf(
							/* translators: 1: memory the file needs (MB), 2: memory already in use (MB), 3: memory_limit (MB), 4: memory_limit that would be enough (MB), 5: wp-config.php line, 6: WP-CLI command. */
							__( 'skipped: not enough memory to check this file. It needs about %1$s MB and %2$s MB of the %3$s MB PHP memory limit is already in use. Raise the limit to about %4$s MB (ask your host, or add %5$s to wp-config.php), or run the scan with WP-CLI: %6$s', 'upshift' ),
							number_format_i18n( $est / 1048576 ),
							number_format_i18n( $in_use / 1048576 ),
							number_format_i18n( self::memory_limit() / 1048576 ),
							number_format_i18n( ceil( $need / self::HARD_SHARE / 16777216 ) * 16 ),
							"define( 'WP_MAX_MEMORY_LIMIT', '" . ( ceil( $need / self::HARD_SHARE / 16777216 ) * 16 ) . "M' );",
							'wp upshift scan'
						);
						if ( $tries > 0 ) {
							/* translators: %d: number of separate requests the file was tried in. */
							$skip .= ' ' . sprintf( __( '(It did not fit in any of %d separate requests.)', 'upshift' ), $tries + 1 );
						}
					}
					if ( time() - $refreshed >= self::LOCK_REFRESH ) {
						// Not inside the transaction: it would hold the lock row until the commit.
						self::tx_commit( $tx );
						if ( ! self::refresh( $token ) ) {
							$stopped = 'lock';
							break;
						}
						$refreshed = time();
					}
					$claim_note = $token;
					if ( $suspect ) {
						// A file from a block whose request died: scanned alone, claimed with '!'. If it kills this
						// request too, the next batch marks it engine_error instead of retrying it forever (D47).
						self::tx_commit( $tx );
						$claim_note = $token . '!';
						$t          = microtime( true );
						$mine       = self::claim( (int) $row['id'], $claim_note );
						$db_secs   += microtime( true ) - $t;
						if ( ! $mine ) {
							continue;
						}
					} else {
						unset( $todo[ (int) $row['id'] ] );
						self::tx_begin( $tx );
					}
					$peak   = max( $peak, memory_get_peak_usage( true ) );
					$t_file = microtime( true );
					$file   = self::scan_one( $engine, $row, $scan_id, $abs, $skip, $claim_note, $check, $cache_ctx );
					// Everything but the engine's own time is database work.
					$db_secs += max( 0.0, microtime( true ) - $t_file - $file['seconds'] - $file['baseline_seconds'] );
					if ( memory_get_usage( true ) > $in_use + self::GC_GROWTH ) {
						// A big file: the engine freed its memory, but PHP keeps the chunks until asked (DECISIONS D57).
						self::free_memory();
					}
					if ( 'lost' === $file['status'] ) {
						// The claim was taken over (our lock had expired): nothing was stored for this file.
						$stopped = 'lock';
						break;
					}
					if ( $tx['open'] && microtime( true ) - $tx['at'] >= self::COMMIT_SECONDS ) {
						self::tx_commit( $tx );
					}
					if ( ! empty( $file['reused'] ) ) {
						++$reused;
						$touch[] = $row['path_hash'];
					}
					$seconds   += $file['seconds'];
					$base_secs += $file['baseline_seconds'];
					$peak       = max( $peak, $file['peak'] );
					++$processed;
					if ( $file['bytes'] > 0 && ( null === $stats['biggest'] || $file['bytes'] > $stats['biggest']['bytes'] ) ) {
						$stats['biggest'] = array(
							'path_key' => $row['path_key'],
							'bytes'    => $file['bytes'],
							'seconds'  => $file['seconds'],
							'peak'     => $file['peak'],
						);
					}
					if ( is_callable( $opts['on_file'] ) ) {
						call_user_func( $opts['on_file'], $row, $file['status'], $file['seconds'], $file['peak'], $file['by_category'] );
					}
				}
				if ( $touch ) {
					self::cache_touch( $touch, $cache_ctx );
					$touch = array();
				}
				// Claimed but not reached (budget, memory, lock): back to pending for the next batch.
				if ( $todo ) {
					self::tx_commit( $tx );
					self::release( array_keys( $todo ), $token );
				}
				if ( 'retry' === $stopped && $retry_id > 0 ) {
					// The file that didn't fit keeps count of its tries, in its note, for the next request.
					$wpdb->query( $wpdb->prepare( "UPDATE {$files} SET note = %s WHERE id = %d AND status = 'pending'", 'memory-retry:' . $retry_n, $retry_id ) );
				}
			}
			if ( 'memory' === $stopped && 0 === $processed ) {
				// Needed: what is in use plus room for a large file (~30 MB measured, DECISIONS D44) within the
				// 75 % batch budget, rounded up to 16 MB.
				$in_use = memory_get_usage( true );
				$needed = (int) ( ceil( ( $in_use + self::FILE_ROOM ) / self::MEMORY_SHARE / 16777216 ) * 16777216 );
				$failed = sprintf(
					/* translators: 1: memory in use (MB), 2: memory_limit needed (MB), 3: current memory_limit (MB), 4: wp-config.php line, 5: WP-CLI command. */
					__( 'Not enough memory to scan: %1$s MB is already in use before the first file, and a scan needs a memory_limit of about %2$s MB (this request has %3$s MB). To fix it, add %4$s to wp-config.php, ask your host for a higher PHP memory_limit, or run the scan with WP-CLI: %5$s', 'upshift' ),
					number_format_i18n( $in_use / 1048576, 1 ),
					number_format_i18n( $needed / 1048576 ),
					number_format_i18n( self::memory_limit() / 1048576 ),
					"define( 'WP_MAX_MEMORY_LIMIT', '256M' );",
					'wp upshift scan'
				);
			}
		} catch ( \Throwable $e ) {
			$failed = $e->getMessage();
		}
		// Saved results are committed before anything counts them or the lock is released.
		self::tx_commit( $tx );

		$db_secs += $tx['secs'];
		if ( ! isset( $stats['timing'] ) || ! is_array( $stats['timing'] ) ) {
			$stats['timing'] = array(
				'requests' => 0,
				'boot'     => 0.0,
				'init'     => 0.0,
				'db'       => 0.0,
			);
		}
		// For reading live sites later (wp option get upshift_scan_stats): where batch time went (DECISIONS D57).
		++$stats['timing']['requests'];
		$stats['timing']['boot'] += $boot;
		$stats['timing']['init'] += isset( $init ) ? $init : 0.0;
		$stats['timing']['db']   += $db_secs;

		$peak              = max( $peak, memory_get_peak_usage( true ) );
		$stats['mem_peak'] = max( (int) $stats['mem_peak'], $peak );
		update_option( self::STATS, $stats, false );

		// The limit is also stored on the scan row, so a later scan's stats can't take it away from M1 (DECISIONS D52).
		$scans = Install::table( 'scans' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$scans} SET files_done = %d, batches = batches + 1, peak_memory = GREATEST(peak_memory, %d), engine_seconds = engine_seconds + %f, baseline_seconds = IF(baseline_seconds < 0, baseline_seconds, baseline_seconds + %f), files_reused = files_reused + %d, memory_limit = GREATEST(memory_limit, %d), memory_ini = %s, sapi = %s WHERE id = %d",
				self::done_count( $scan_id ),
				$peak,
				$seconds,
				$base_secs,
				$reused,
				(int) $stats['memory_limit'],
				substr( (string) $stats['memory_ini'], 0, 20 ),
				substr( (string) $stats['sapi'], 0, 30 ),
				$scan_id
			)
		);
		if ( '' !== $failed ) {
			self::fail( $scan_id, $failed );
		} elseif ( 'done' === $stopped && ! self::finish( $scan_id ) ) {
			$stopped = 'cancelled';
		}
		self::unlock( $token );

		return self::progress( self::get( $scan_id ), $stopped );
	}

	/**
	 * Cancels a queued or running scan.
	 *
	 * @param int $scan_id Scan id.
	 * @return bool Whether a scan was cancelled.
	 */
	public static function cancel( $scan_id ) {
		global $wpdb;
		$scans   = Install::table( 'scans' );
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$scans} SET status = 'cancelled', finished = %s WHERE id = %d AND status IN ('queued','running')", current_time( 'mysql' ), (int) $scan_id ) );
		self::clear_step( $scan_id );
		return $changed > 0;
	}

	/**
	 * One scan row.
	 *
	 * @param int $scan_id Scan id.
	 * @return array|null
	 */
	public static function get( $scan_id ) {
		global $wpdb;
		$scans = Install::table( 'scans' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$scans} WHERE id = %d", (int) $scan_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Latest scan, optionally with a given status.
	 *
	 * @param string $status Status ('' = any).
	 * @return array|null
	 */
	public static function latest( $status = '' ) {
		global $wpdb;
		$table = Install::table( 'scans' );
		if ( '' === $status ) {
			$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );
		} else {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT 1", $status ), ARRAY_A );
		}
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Progress of a scan for AJAX / CLI.
	 *
	 * @param array|null $scan    Scan row.
	 * @param string     $stopped Why the batch stopped.
	 * @return array
	 */
	public static function progress( $scan, $stopped = '' ) {
		$scan = (array) $scan;
		return array(
			'scan_id'     => isset( $scan['id'] ) ? (int) $scan['id'] : 0,
			'status'      => isset( $scan['status'] ) ? $scan['status'] : '',
			'files_done'  => isset( $scan['files_done'] ) ? (int) $scan['files_done'] : 0,
			'files_total' => isset( $scan['files_total'] ) ? (int) $scan['files_total'] : 0,
			'batches'     => isset( $scan['batches'] ) ? (int) $scan['batches'] : 0,
			'peak_memory' => isset( $scan['peak_memory'] ) ? (int) $scan['peak_memory'] : 0,
			'stopped'     => (string) $stopped,
			'error'       => isset( $scan['error'] ) ? (string) $scan['error'] : '',
		);
	}

	/**
	 * Progress plus what the progress display needs (DECISIONS D57), for the AJAX answers: how many components are
	 * done, the one being worked on with its file counts, files reused from the cache, and whether the scan is being
	 * carried on in the background. One GROUP BY over the scan's files; the CLI uses progress() alone.
	 *
	 * @param array $progress From progress() or batch().
	 * @return array $progress plus components_done, components_total, current_component (id), current_component_label,
	 *               component_files_done, component_files_total, files_reused, background.
	 */
	public static function progress_detail( array $progress ) {
		global $wpdb;

		$scan_id = isset( $progress['scan_id'] ) ? (int) $progress['scan_id'] : 0;
		$detail  = array(
			'components_done'         => 0,
			'components_total'        => 0,
			'current_component'       => '',
			'current_component_label' => '',
			'component_files_done'    => 0,
			'component_files_total'   => 0,
			'files_reused'            => 0,
			'background'              => false,
		);
		$scan    = $scan_id > 0 ? self::get( $scan_id ) : null;
		if ( null === $scan ) {
			return array_merge( $progress, $detail );
		}
		$files = Install::table( 'scan_files' );
		// Components in inventory order: the files of a scan are scanned in that order, one component after the other.
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT component, COUNT(*) AS total, SUM(status NOT IN ('pending','scanning')) AS done FROM {$files} WHERE scan_id = %d GROUP BY component ORDER BY MIN(id)", $scan_id ), ARRAY_A );
		$current = null;
		foreach ( (array) $rows as $row ) {
			if ( (int) $row['done'] >= (int) $row['total'] ) {
				++$detail['components_done'];
			} elseif ( null === $current ) {
				$current = $row;
			}
		}
		$detail['components_total'] = count( (array) $rows );
		if ( null !== $current ) {
			$stats  = get_option( self::STATS );
			$labels = is_array( $stats ) && isset( $stats['scan_id'], $stats['labels'] ) && (int) $stats['scan_id'] === $scan_id && is_array( $stats['labels'] ) ? $stats['labels'] : array();
			$id     = (string) $current['component'];

			$detail['current_component']       = $id;
			$detail['current_component_label'] = isset( $labels[ $id ] ) && '' !== $labels[ $id ] ? (string) $labels[ $id ] : ( false !== strpos( $id, ':' ) ? substr( $id, strpos( $id, ':' ) + 1 ) : $id );
			$detail['component_files_done']    = (int) $current['done'];
			$detail['component_files_total']   = (int) $current['total'];
		}
		$detail['files_reused'] = isset( $scan['files_reused'] ) ? (int) $scan['files_reused'] : 0;
		if ( 'running' === $scan['status'] ) {
			// Background = a step is scheduled and no page is driving the scan right now.
			$page                 = get_option( self::PAGE_AT );
			$page_active          = is_array( $page ) && isset( $page[0], $page[1] ) && (int) $page[0] === $scan_id && (int) $page[1] > time();
			$detail['background'] = ! $page_active && false !== wp_next_scheduled( self::STEP_HOOK, array( $scan_id ) );
		}
		return array_merge( $progress, $detail );
	}

	/**
	 * Summary data for the Scan tab and the CLI.
	 *
	 * @param int $scan_id Scan id.
	 * @return array|null
	 */
	public static function summary( $scan_id ) {
		global $wpdb;

		$scan = self::get( $scan_id );
		if ( null === $scan ) {
			return null;
		}
		$files    = Install::table( 'scan_files' );
		$findings = Install::table( 'findings' );

		$components = array();
		$statuses   = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT component, component_active, status, COUNT(*) AS n FROM {$files} WHERE scan_id = %d GROUP BY component, component_active, status", $scan['id'] ), ARRAY_A ) as $row ) {
			$id = $row['component'];
			if ( ! isset( $components[ $id ] ) ) {
				$components[ $id ] = array(
					'active'   => (bool) $row['component_active'],
					'files'    => 0,
					'status'   => array(),
					'category' => array_fill_keys( Categories::all(), 0 ),
				);
			}
			$components[ $id ]['files']                   += (int) $row['n'];
			$components[ $id ]['status'][ $row['status'] ] = (int) $row['n'];
			$statuses[ $row['status'] ]                    = ( isset( $statuses[ $row['status'] ] ) ? $statuses[ $row['status'] ] : 0 ) + (int) $row['n'];
		}

		// by_category, component counts and the problem totals leave out findings already true on the "from"
		// version (baseline = 1, DECISIONS D52); those are in 'current'. Problem totals by severity count only
		// removed/deprecated/changed (spec §2).
		$by_category = array_fill_keys( Categories::all(), 0 );
		$by_severity = array(
			'error'   => 0,
			'warning' => 0,
		);
		// The same totals for active components only, shown beside them (DECISIONS D54).
		$by_category_active = $by_category;
		$by_severity_active = $by_severity;
		$current            = 0;
		$guarded            = 0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT component, category, severity, baseline, guarded, COUNT(*) AS n FROM {$findings} WHERE scan_id = %d GROUP BY component, category, severity, baseline, guarded", $scan['id'] ), ARRAY_A ) as $row ) {
			$n = (int) $row['n'];
			// Code that can't run on the target (DECISIONS D56): counted once, here, even if also true on "from".
			if ( '1' === (string) $row['guarded'] ) {
				$guarded += $n;
				continue;
			}
			if ( '1' === (string) $row['baseline'] ) {
				$current += $n;
				if ( isset( $components[ $row['component'] ] ) ) {
					$components[ $row['component'] ]['current'] = ( isset( $components[ $row['component'] ]['current'] ) ? $components[ $row['component'] ]['current'] : 0 ) + $n;
				}
				continue;
			}
			if ( isset( $components[ $row['component'] ]['category'][ $row['category'] ] ) ) {
				$components[ $row['component'] ]['category'][ $row['category'] ] += $n;
			}
			$active = ! empty( $components[ $row['component'] ]['active'] );
			if ( isset( $by_category[ $row['category'] ] ) ) {
				$by_category[ $row['category'] ]        += $n;
				$by_category_active[ $row['category'] ] += $active ? $n : 0;
			}
			if ( isset( $by_severity[ $row['severity'] ] ) && in_array( $row['category'], Categories::counted(), true ) ) {
				$by_severity[ $row['severity'] ]        += $n;
				$by_severity_active[ $row['severity'] ] += $active ? $n : 0;
			}
		}
		ksort( $components );

		$engine_errors = $wpdb->get_results( $wpdb->prepare( "SELECT path_key, note FROM {$files} WHERE scan_id = %d AND status = 'engine_error' ORDER BY id LIMIT 50", $scan['id'] ), ARRAY_A );
		// Files not scanned (size, data/classmap, memory, unreadable) or not fully checked (partial, D52): always
		// listed, never hidden (DECISIONS D40).
		$skipped_files = $wpdb->get_results( $wpdb->prepare( "SELECT path_key, component, status, note FROM {$files} WHERE scan_id = %d AND status IN ('skipped_large','unreadable','partial') ORDER BY id LIMIT 200", $scan['id'] ), ARRAY_A );

		$stats = get_option( self::STATS );
		return array(
			'scan'               => $scan,
			'components'         => $components,
			'statuses'           => $statuses,
			'by_category'        => $by_category,
			'by_severity'        => $by_severity,
			'by_category_active' => $by_category_active,
			'by_severity_active' => $by_severity_active,
			'inactive_scanned'   => self::inactive_scanned( (int) $scan['id'] ),
			'file_checks'        => self::file_checks( (int) $scan['id'] ),
			'current'            => $current,
			'guarded'            => $guarded,
			'checked'            => (float) $scan['baseline_seconds'] >= 0,
			'engine_errors'      => (array) $engine_errors,
			'skipped_files'      => (array) $skipped_files,
			'stats'              => is_array( $stats ) && isset( $stats['scan_id'] ) && (int) $stats['scan_id'] === (int) $scan['id'] ? $stats : null,
			'memory_limit'       => self::memory_limit(),
		);
	}

	/**
	 * Whether a scan has any file of an inactive component (a scan_files row with component_active = 0). None means
	 * inactive plugins were not scanned (the Scan tab default), or the site had none (DECISIONS D54).
	 *
	 * @param int $scan_id Scan id.
	 * @return bool
	 */
	public static function inactive_scanned( $scan_id ) {
		global $wpdb;
		$files = Install::table( 'scan_files' );
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$files} WHERE scan_id = %d AND component_active = 0 LIMIT 1", (int) $scan_id ) );
	}

	/**
	 * Files of a scan whose code was not checked, split into harmless data files and code files (DECISIONS D54).
	 * Data = skipped as a data/classmap file (D40). Code = every other skipped_large file (too large, or too large for
	 * the memory limit), unreadable, engine_error and partial (not fully checked).
	 *
	 * @param int $scan_id Scan id.
	 * @return array data, code, code_active (int).
	 */
	public static function file_checks( $scan_id ) {
		global $wpdb;
		$files = Install::table( 'scan_files' );
		$out   = array(
			'data'        => 0,
			'code'        => 0,
			'code_active' => 0,
		);
		// The note of a data file is "skipped: too large (data/classmap file, … KB)" (Inventory::skip_note()).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT component_active, ( status = 'skipped_large' AND note LIKE %s ) AS is_data, COUNT(*) AS n FROM {$files} WHERE scan_id = %d AND status IN ('skipped_large','unreadable','partial','engine_error') GROUP BY component_active, is_data", '%' . $wpdb->esc_like( 'data/classmap' ) . '%', (int) $scan_id ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$n = (int) $row['n'];
			if ( '1' === (string) $row['is_data'] ) {
				$out['data'] += $n;
				continue;
			}
			$out['code']        += $n;
			$out['code_active'] += '1' === (string) $row['component_active'] ? $n : 0;
		}
		return $out;
	}

	/**
	 * Files of a scan that were not scanned or not fully checked (skipped_large, unreadable, partial).
	 *
	 * @param int $scan_id Scan id.
	 * @return int
	 */
	public static function not_scanned( $scan_id ) {
		global $wpdb;
		$files = Install::table( 'scan_files' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE scan_id = %d AND status IN ('skipped_large','unreadable','partial')", (int) $scan_id ) );
	}

	/**
	 * Effective memory_limit in bytes (-1 or unparsable = 1 GB, spec §7.6).
	 *
	 * @return int
	 */
	public static function memory_limit() {
		$bytes = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $bytes > 0 ? $bytes : 1073741824;
	}

	/**
	 * Time budget per batch: min( 15 s, 50% of max_execution_time if > 0 ).
	 *
	 * @return float
	 */
	public static function time_budget() {
		$max = (int) ini_get( 'max_execution_time' );
		return $max > 0 ? min( (float) self::MAX_SECONDS, 0.5 * $max ) : (float) self::MAX_SECONDS;
	}

	/**
	 * Scans one scan_files row and stores its findings and status.
	 *
	 * @param Engine      $engine  Engine.
	 * @param array       $row     scan_files row.
	 * @param int         $scan_id Scan id.
	 * @param string|null $abs     Absolute path (Paths::resolve()), null when not found.
	 * @param string|null $skip    Note when the file is skipped for memory instead of scanned.
	 * @param string      $token   Lock token the row was claimed with.
	 * @param string      $from      From version for the new-risk check ('' = no check, scan from before DB v4).
	 * @param array|null  $cache_ctx Result cache context (range, rules) or null when caching is off (DECISIONS D57).
	 * @return array status (lost = the claim was taken over, nothing stored), seconds, baseline_seconds, peak, bytes,
	 *               by_category, reused (only when the cached result was used).
	 * @throws \RuntimeException When a findings insert fails.
	 */
	private static function scan_one( Engine $engine, array $row, $scan_id, $abs, $skip, $token, $from, $cache_ctx = null ) {
		global $wpdb;

		$out  = array(
			'status'           => 'done',
			'seconds'          => 0.0,
			'baseline_seconds' => 0.0,
			'peak'             => 0,
			'bytes'            => 0,
			'by_category'      => array(),
		);
		$note = null;
		if ( null === $abs || ! is_readable( $abs ) ) {
			$out['status'] = 'unreadable';
			$note          = __( 'File not found or not readable.', 'upshift' );
		} elseif ( isset( $row['cached'] ) && is_array( $row['cached'] ) ) {
			// Unchanged since a scan with the same range and rules: cache_lookup() checked size, mtime and content hash.
			$out['bytes']       = (int) filesize( $abs );
			$out['reused']      = true;
			$out['by_category'] = self::insert_findings( $scan_id, $row, $row['cached'] );
			$out['peak']        = memory_get_peak_usage( true );
		} elseif ( null !== $skip ) {
			$out['status'] = 'skipped_large';
			$note          = $skip;
		} else {
			$out['bytes'] = (int) filesize( $abs );
			// Per-file peak memory needs a peak reset (PHP 8.2+); older PHP reports the process peak so far.
			if ( function_exists( 'memory_reset_peak_usage' ) ) {
				memory_reset_peak_usage(); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.memory_reset_peak_usageFound -- guarded by function_exists().
			}
			// Read before the engine runs: a file changed while it is scanned must not match its cached result later.
			$signature      = null !== $cache_ctx ? self::cache_signature( $abs ) : null;
			$t              = microtime( true );
			$result         = $engine->scan_file( $abs );
			$out['seconds'] = microtime( true ) - $t;
			if ( Engine::is_error( $result ) ) {
				$out['status'] = 'engine_error';
				$note          = substr( (string) $result['engine_error'], 0, self::NOTE_MAX );
			} else {
				// PHPCS aborted part of the file: keep what it found, mark the file like an engine error (D52).
				list( $result, $aborted ) = self::split_internal( $result );
				if ( '' !== $aborted ) {
					$out['status'] = 'partial';
					$note          = substr( 'file not fully checked: ' . $aborted, 0, self::NOTE_MAX );
				}
				if ( '' !== $from && self::has_counted( $result ) ) {
					// New-risk check (DECISIONS D52): the same file with testVersion = from alone. A finding reported
					// there too is already true on the PHP the site runs now.
					$t    = microtime( true );
					$base = Engine::baseline( $from )->scan_file( $abs );

					$out['baseline_seconds'] = microtime( true ) - $t;
					if ( Engine::is_error( $base ) ) {
						// Unchecked findings stay counted: a failed check must not hide a problem.
						$note = substr( ( null !== $note ? $note . '; ' : '' ) . 'baseline check failed: ' . $base['engine_error'], 0, self::NOTE_MAX );
						$base = array();
					}
					$result = self::mark_baseline( $result, $base );
				}
				$out['by_category'] = self::insert_findings( $scan_id, $row, $result );
				if ( null !== $signature && 'done' === $out['status'] && null === $note ) {
					self::cache_store( $row, $cache_ctx, $signature, $result );
				}
			}
			$out['peak'] = memory_get_peak_usage( true );
		}
		// Only while the claim is still ours; otherwise drop what this file inserted (reap() marked it already).
		$files = Install::table( 'scan_files' );
		$saved = $wpdb->query( $wpdb->prepare( "UPDATE {$files} SET status = %s, note = %s WHERE id = %d AND status = 'scanning' AND note = %s", $out['status'], (string) $note, (int) $row['id'], $token ) );
		if ( 1 !== $saved ) {
			$findings = Install::table( 'findings' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$findings} WHERE scan_id = %d AND path_hash = %s", (int) $scan_id, $row['path_hash'] ) );
			$out['status'] = 'lost';
		}
		return $out;
	}

	/**
	 * Memory the engine needs for a file (DECISIONS D57): EST_BASE plus EST_PER_TOKEN for each token, counted from the
	 * text without building a token list. If the count fails (regex limits), the flat per-byte rule is used.
	 *
	 * @param string $abs Absolute path.
	 * @return int Bytes.
	 */
	public static function estimate_memory( $abs ) {
		$size = is_readable( $abs ) ? filesize( $abs ) : false;
		if ( false === $size ) {
			return 0;
		}
		$code   = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, read as text only.
		$tokens = false === $code ? false : preg_match_all( self::TOKEN_RX, $code );
		unset( $code );
		if ( false === $tokens ) {
			return (int) $size * self::EST_PER_BYTE;
		}
		return self::EST_BASE + $tokens * self::EST_PER_TOKEN;
	}

	/**
	 * Hands freed memory back to the system: PHP keeps the chunks of a big file, and memory_get_usage( true ) counts them.
	 * The engine itself keeps nothing between files (0.4 MB after 14 files of 150-625 KB, DECISIONS D57).
	 *
	 * @return int Real memory in use afterwards (bytes).
	 */
	private static function free_memory() {
		gc_collect_cycles();
		gc_mem_caches();
		return memory_get_usage( true );
	}

	/**
	 * Version of the rules a cached result was made with: the engine build (BUILD-OK 'inputs' line) and the code that
	 * shapes a result (this class, Engine, Guards, Categories). Any change to them makes every cached result a miss.
	 *
	 * @return string 32 hex characters, or '' when the engine build is unknown (no caching).
	 */
	public static function cache_rules() {
		if ( null !== self::$cache_rules ) {
			return self::$cache_rules;
		}
		self::$cache_rules = '';
		$marker            = Requirements::vendor_dir() . '/BUILD-OK';
		$text              = is_readable( $marker ) ? file_get_contents( $marker ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own local file.
		if ( ! is_string( $text ) || ! preg_match( '/^inputs ([0-9a-f]{32})\s*$/m', $text, $match ) ) {
			return self::$cache_rules;
		}
		$parts = array( $match[1] );
		foreach ( array( __FILE__, UPSHIFT_DIR . '/src/Engine/Engine.php', UPSHIFT_DIR . '/src/Engine/Guards.php', UPSHIFT_DIR . '/src/Scan/Categories.php' ) as $file ) {
			$hash = is_readable( $file ) ? md5_file( $file ) : false;
			if ( false === $hash ) {
				return self::$cache_rules;
			}
			$parts[] = $hash;
		}
		self::$cache_rules = md5( implode( '|', $parts ) );
		return self::$cache_rules;
	}

	/**
	 * Size, modification time and content hash of a file, or null when any can't be read.
	 *
	 * @param string $abs Absolute path.
	 * @return array|null size, mtime, hash (lowercase hex).
	 */
	private static function cache_signature( $abs ) {
		$size  = filesize( $abs );
		$mtime = filemtime( $abs );
		$hash  = md5_file( $abs );
		if ( false === $size || false === $mtime || false === $hash || $mtime < 0 ) {
			return null;
		}
		return array(
			'size'  => (int) $size,
			'mtime' => (int) $mtime,
			'hash'  => $hash,
		);
	}

	/**
	 * Cached results of a chunk's files for this range and rules.
	 *
	 * @param array $rows scan_files rows (path_hash).
	 * @param array $ctx  Cache context (range, rules).
	 * @return array Lowercase path hash => row (size, mtime, c = content hash, findings).
	 */
	private static function cache_fetch( array $rows, array $ctx ) {
		global $wpdb;
		$hashes = array();
		foreach ( $rows as $row ) {
			if ( preg_match( '/^[0-9a-f]{32}$/', (string) $row['path_hash'] ) ) {
				$hashes[] = $row['path_hash'];
			}
		}
		if ( ! $hashes ) {
			return array();
		}
		$cache        = Install::table( 'file_cache' );
		$placeholders = implode( ', ', array_fill( 0, count( $hashes ), 'UNHEX(%s)' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(HEX(path_hash)) AS h, size, mtime, LOWER(HEX(content_hash)) AS c, findings FROM {$cache} WHERE range_key = %s AND rules = UNHEX(%s) AND path_hash IN ({$placeholders})", array_merge( array( $ctx['range'], $ctx['rules'] ), $hashes ) ), ARRAY_A );
		$out   = array();
		foreach ( (array) $found as $found_row ) {
			$out[ $found_row['h'] ] = $found_row;
		}
		return $out;
	}

	/**
	 * The cached findings of a file if it is unchanged: same size, modification time and content hash.
	 *
	 * @param array       $row Chunk row (path_hash).
	 * @param string|null $abs Absolute path.
	 * @param array       $ctx Cache context with the fetched rows.
	 * @return array|null Findings (possibly empty), or null for a miss.
	 */
	private static function cache_lookup( array $row, $abs, array $ctx ) {
		$key = strtolower( (string) $row['path_hash'] );
		if ( null === $abs || ! isset( $ctx['rows'][ $key ] ) || ! is_readable( $abs ) ) {
			return null;
		}
		$cached = $ctx['rows'][ $key ];
		$size   = (int) filesize( $abs );
		$mtime  = (int) filemtime( $abs );
		if ( (int) $cached['size'] !== $size || (int) $cached['mtime'] !== $mtime ) {
			return null;
		}
		$hash = md5_file( $abs );
		if ( false === $hash || $hash !== $cached['c'] ) {
			return null;
		}
		return self::cache_decode( $cached['findings'] );
	}

	/**
	 * Findings from their cached form. Anything unexpected is a miss.
	 *
	 * @param string|null $blob gzcompressed JSON, or null for a file without findings.
	 * @return array|null
	 */
	private static function cache_decode( $blob ) {
		if ( null === $blob ) {
			return array();
		}
		$json = @gzuncompress( (string) $blob ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt data is a miss.
		$list = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $list ) ) {
			return null;
		}
		$out = array();
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || 6 !== count( $item ) || ! is_int( $item[0] ) || ! is_string( $item[2] ) || ! is_string( $item[3] ) ) {
				return null;
			}
			$out[] = array(
				'line'     => $item[0],
				'column'   => 0,
				'severity' => 'e' === $item[1] ? 'error' : 'warning',
				'rule'     => $item[2],
				'message'  => $item[3],
				'baseline' => $item[4] ? 1 : 0,
				'guarded'  => $item[5] ? 1 : 0,
			);
		}
		return $out;
	}

	/**
	 * Stores the result of a file that was checked completely (no engine error, nothing partial, no failed baseline
	 * check). A file without findings is stored with findings NULL. Runs inside the file's transaction.
	 *
	 * @param array $row       Chunk row (path_hash).
	 * @param array $ctx       Cache context (range, rules).
	 * @param array $signature From cache_signature(), read before the scan.
	 * @param array $findings  The findings as saved (with baseline and guarded flags).
	 */
	private static function cache_store( array $row, array $ctx, array $signature, array $findings ) {
		global $wpdb;
		if ( ! preg_match( '/^[0-9a-f]{32}$/', (string) $row['path_hash'] ) ) {
			return;
		}
		$cache = Install::table( 'file_cache' );
		$args  = array( $row['path_hash'], $ctx['range'], $signature['hash'], $ctx['rules'], $signature['size'], $signature['mtime'], time() );
		if ( ! $findings ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name from $wpdb->prefix; arguments built above.
			$wpdb->query( $wpdb->prepare( "REPLACE INTO {$cache} (path_hash, range_key, content_hash, rules, size, mtime, last_used, findings) VALUES (UNHEX(%s), %s, UNHEX(%s), UNHEX(%s), %d, %d, %d, NULL)", $args ) );
			return;
		}
		$list = array();
		foreach ( $findings as $finding ) {
			$list[] = array( (int) $finding['line'], 'error' === $finding['severity'] ? 'e' : 'w', (string) $finding['rule'], (string) $finding['message'], empty( $finding['baseline'] ) ? 0 : 1, empty( $finding['guarded'] ) ? 0 : 1 );
		}
		$json = json_encode( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- invalid UTF-8 must fail (no caching), not be repaired.
		$blob = false === $json ? false : gzcompress( $json, 6 );
		if ( false === $blob ) {
			return;
		}
		$args[] = bin2hex( $blob );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name from $wpdb->prefix; arguments built above.
		$wpdb->query( $wpdb->prepare( "REPLACE INTO {$cache} (path_hash, range_key, content_hash, rules, size, mtime, last_used, findings) VALUES (UNHEX(%s), %s, UNHEX(%s), UNHEX(%s), %d, %d, %d, UNHEX(%s))", $args ) );
	}

	/**
	 * Marks cached results as used today (only when the last mark is a day old, to avoid a write per file).
	 *
	 * @param string[] $hashes Path hashes of the reused files.
	 * @param array    $ctx    Cache context (range).
	 */
	private static function cache_touch( array $hashes, array $ctx ) {
		global $wpdb;
		$valid = array();
		foreach ( $hashes as $hash ) {
			if ( preg_match( '/^[0-9a-f]{32}$/', (string) $hash ) ) {
				$valid[] = $hash;
			}
		}
		if ( ! $valid ) {
			return;
		}
		$cache        = Install::table( 'file_cache' );
		$placeholders = implode( ', ', array_fill( 0, count( $valid ), 'UNHEX(%s)' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$cache} SET last_used = %d WHERE range_key = %s AND last_used < %d AND path_hash IN ({$placeholders})", array_merge( array( time(), $ctx['range'], time() - DAY_IN_SECONDS ), $valid ) ) );
	}

	/**
	 * Takes PHPCS's Internal.Exception / Internal.Tokenizer.Exception (Categories::is_aborted(); "An error occurred during processing; checking has been aborted…") out of the
	 * findings. It is not a PHP version issue but means the file was not fully checked, so the runner stores it as
	 * the file status 'partial' with the message, listed with the skipped files, never as a finding (DECISIONS D52).
	 *
	 * @param array $findings Engine findings.
	 * @return array array( findings without those codes, its message(s) joined, or '' when there was none ).
	 */
	public static function split_internal( array $findings ) {
		$kept     = array();
		$messages = array();
		foreach ( $findings as $finding ) {
			if ( Categories::is_aborted( $finding['rule'] ) ) {
				$messages[] = trim( (string) $finding['message'] );
			} else {
				$kept[] = $finding;
			}
		}
		return array( $kept, implode( ' | ', $messages ) );
	}

	/**
	 * Whether engine findings include one of a counted category (only those need the new-risk check).
	 *
	 * @param array $findings Engine findings.
	 * @return bool
	 */
	private static function has_counted( array $findings ) {
		foreach ( $findings as $finding ) {
			if ( empty( $finding['guarded'] ) && in_array( Categories::of( $finding['rule'] ), Categories::counted(), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sets 'baseline' (1 or 0) on each finding: 1 when the scan with testVersion = from alone reported the same rule
	 * code on the same line (DECISIONS D52). Counted as a multiset, so two equal findings need two matches. A code
	 * that changes with the range (e.g. eachDeprecated for 7.4 alone vs eachDeprecatedRemoved for 7.4-8.4) doesn't
	 * match: the removal is new for this upgrade.
	 *
	 * @param array $findings Findings of the from-target scan.
	 * @param array $baseline Findings of the from-only scan.
	 * @return array $findings with 'baseline' set.
	 */
	public static function mark_baseline( array $findings, array $baseline ) {
		$seen = array();
		foreach ( $baseline as $finding ) {
			$key          = (int) $finding['line'] . '|' . $finding['rule'];
			$seen[ $key ] = ( isset( $seen[ $key ] ) ? $seen[ $key ] : 0 ) + 1;
		}
		foreach ( $findings as $i => $finding ) {
			$key = (int) $finding['line'] . '|' . $finding['rule'];

			$findings[ $i ]['baseline'] = 0;
			if ( ! empty( $seen[ $key ] ) ) {
				--$seen[ $key ];
				$findings[ $i ]['baseline'] = 1;
			}
		}
		return $findings;
	}

	/**
	 * Claims a pending row for this lock holder (status 'scanning', note = token).
	 *
	 * @param int    $id    scan_files row id.
	 * @param string $token Lock token.
	 * @return bool False when the row is no longer pending.
	 */
	private static function claim( $id, $token ) {
		global $wpdb;
		$files = Install::table( 'scan_files' );
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$files} SET status = 'scanning', note = %s WHERE id = %d AND status = 'pending'", $token, (int) $id ) );
	}

	/**
	 * Deals with rows left 'scanning' and drops their partial findings. Called while holding the lock, so those rows
	 * belong to a request that died or lost the lock (DECISIONS D47, D57). A row claimed alone (note ends in '!') is the
	 * file that was being scanned: engine_error. A row from a block claim may only have been waiting, or its result was
	 * rolled back with the request: back to pending as a 'suspect', scanned on its own next time. Row by row, with the
	 * row's note: a slow request that stores its result first keeps it, and findings are deleted only for rows changed
	 * here (D51).
	 *
	 * @param int $scan_id Scan id.
	 */
	private static function reap( $scan_id ) {
		global $wpdb;
		$files    = Install::table( 'scan_files' );
		$findings = Install::table( 'findings' );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT id, path_hash, note FROM {$files} WHERE scan_id = %d AND status = 'scanning'", (int) $scan_id ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			if ( '!' === substr( (string) $row['note'], -1 ) ) {
				$marked = $wpdb->query( $wpdb->prepare( "UPDATE {$files} SET status = 'engine_error', note = %s WHERE id = %d AND status = 'scanning' AND note = %s", __( 'request died while scanning (memory/time limit)', 'upshift' ), (int) $row['id'], (string) $row['note'] ) );
			} else {
				$marked = $wpdb->query( $wpdb->prepare( "UPDATE {$files} SET status = 'pending', note = %s WHERE id = %d AND status = 'scanning' AND note = %s", self::SUSPECT, (int) $row['id'], (string) $row['note'] ) );
			}
			if ( 1 === $marked ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$findings} WHERE scan_id = %d AND path_hash = %s", (int) $scan_id, $row['path_hash'] ) );
			}
		}
	}

	/**
	 * Claims the not-suspect rows of a chunk with one UPDATE (status 'scanning', note = token). Run outside a
	 * transaction, so the claim is committed before any file is scanned.
	 *
	 * @param array  $rows  scan_files rows (id, note).
	 * @param string $token Lock token.
	 * @return array Claimed row ids as keys (id => true), in chunk order.
	 */
	private static function claim_block( array $rows, $token ) {
		global $wpdb;
		$ids = array();
		foreach ( $rows as $row ) {
			if ( self::SUSPECT !== (string) $row['note'] ) {
				$ids[] = (int) $row['id'];
			}
		}
		if ( ! $ids ) {
			return array();
		}
		$files        = Install::table( 'scan_files' );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$files} SET status = 'scanning', note = %s WHERE status = 'pending' AND id IN ({$placeholders})", array_merge( array( $token ), $ids ) ) );
		if ( count( $ids ) !== $changed ) {
			// Some row was no longer pending: keep only the ones that carry our token.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$files} WHERE status = 'scanning' AND note = %s AND id IN ({$placeholders})", array_merge( array( $token ), $ids ) ) ) );
		}
		return array_fill_keys( $ids, true );
	}

	/**
	 * Puts claimed rows that were not scanned back to pending.
	 *
	 * @param int[]  $ids   scan_files row ids.
	 * @param string $token Lock token they were claimed with.
	 */
	private static function release( array $ids, $token ) {
		global $wpdb;
		$files        = Install::table( 'scan_files' );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$files} SET status = 'pending', note = NULL WHERE status = 'scanning' AND note = %s AND id IN ({$placeholders})", array_merge( array( $token ), $ids ) ) );
	}

	/**
	 * Starts the transaction that holds the saved results of the files being scanned (a no-op on MyISAM tables).
	 *
	 * @param array $tx State: open, at, secs.
	 */
	private static function tx_begin( array &$tx ) {
		global $wpdb;
		if ( $tx['open'] ) {
			return;
		}
		$t = microtime( true );
		$wpdb->query( 'START TRANSACTION' );
		$tx['open']  = true;
		$tx['at']    = microtime( true );
		$tx['secs'] += $tx['at'] - $t;
	}

	/**
	 * Commits the open transaction, if any.
	 *
	 * @param array $tx State: open, at, secs.
	 */
	private static function tx_commit( array &$tx ) {
		global $wpdb;
		if ( ! $tx['open'] ) {
			return;
		}
		$t = microtime( true );
		$wpdb->query( 'COMMIT' );
		$tx['open']  = false;
		$tx['secs'] += microtime( true ) - $t;
	}

	/**
	 * Inserts a file's findings in prepared multi-row chunks.
	 *
	 * @param int   $scan_id  Scan id.
	 * @param array $row      scan_files row.
	 * @param array $findings Engine findings.
	 * @return array Count per category.
	 * @throws \RuntimeException When an insert fails.
	 */
	private static function insert_findings( $scan_id, array $row, array $findings ) {
		global $wpdb;

		$table  = Install::table( 'findings' );
		$counts = array();
		foreach ( array_chunk( $findings, self::FINDINGS_CHUNK ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $finding ) {
				// baseline only matters for counted categories; the information categories keep their own groups.
				$category            = Categories::of( $finding['rule'] );
				$counts[ $category ] = ( isset( $counts[ $category ] ) ? $counts[ $category ] : 0 ) + 1;
				$baseline            = ! empty( $finding['baseline'] ) && in_array( $category, Categories::counted(), true ) ? 1 : 0;
				$guarded             = ! empty( $finding['guarded'] ) && in_array( $category, Categories::counted(), true ) ? 1 : 0;
				$values[]            = '(%d, %s, %s, %s, %d, %s, %s, %s, %s, %d, %d)';
				array_push( $args, (int) $scan_id, $row['path_hash'], $row['path_key'], $row['component'], (int) $finding['line'], $finding['severity'], $category, substr( $finding['rule'], 0, 255 ), $finding['message'], $baseline, $guarded );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
			$ok = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (scan_id, path_hash, path_key, component, line, severity, category, rule, message, baseline, guarded) VALUES " . implode( ', ', $values ), $args ) );
			if ( false === $ok ) {
				throw new \RuntimeException( 'findings insert failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
			}
		}
		return $counts;
	}

	/**
	 * Files of a scan that are no longer pending.
	 *
	 * @param int $scan_id Scan id.
	 * @return int
	 */
	private static function done_count( $scan_id ) {
		global $wpdb;
		$files = Install::table( 'scan_files' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$files} WHERE scan_id = %d AND status NOT IN ('pending','scanning')", (int) $scan_id ) );
	}

	/**
	 * Marks a scan done and drops the findings and file rows of older scans (scan rows stay as history). Only when
	 * the scan was still running: a cancelled scan must not delete the previous done scan's findings (DECISIONS D51).
	 *
	 * @param int $scan_id Scan id.
	 * @return bool Whether the scan was marked done.
	 */
	private static function finish( $scan_id ) {
		global $wpdb;
		$scans    = Install::table( 'scans' );
		$findings = Install::table( 'findings' );
		$files    = Install::table( 'scan_files' );
		$changed  = $wpdb->query( $wpdb->prepare( "UPDATE {$scans} SET status = 'done', finished = %s WHERE id = %d AND status = 'running'", current_time( 'mysql' ), $scan_id ) );
		if ( 1 !== $changed ) {
			return false;
		}
		self::clear_step( $scan_id );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$findings} WHERE scan_id < %d", $scan_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$files} WHERE scan_id < %d", $scan_id ) );
		// Cached results unused for 30 days (a file that no longer exists is never marked used again), a limited delete.
		$cache = Install::table( 'file_cache' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$cache} WHERE last_used < %d LIMIT 1000", time() - self::CACHE_DAYS * DAY_IN_SECONDS ) );
		try {
			Status::refresh( $scan_id ); // The small summary the Plugins screen and Site Health read (DECISIONS D58).
		} catch ( \Throwable $e ) {
			// Never let this stop a finished scan: the Check tab rebuilds a missing summary.
			unset( $e );
		}
		return true;
	}

	/**
	 * Marks a scan failed.
	 *
	 * @param int    $scan_id Scan id.
	 * @param string $message Error.
	 */
	private static function fail( $scan_id, $message ) {
		global $wpdb;
		self::clear_step( $scan_id );
		$wpdb->update(
			Install::table( 'scans' ),
			array(
				'status'   => 'failed',
				'finished' => current_time( 'mysql' ),
				'error'    => substr( (string) $message, 0, self::NOTE_MAX ),
			),
			array( 'id' => (int) $scan_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Memory stats of a scan (DECISIONS D31), fresh for a new scan id.
	 *
	 * @param int $scan_id Scan id.
	 * @return array
	 */
	private static function stats( $scan_id ) {
		$stats = get_option( self::STATS );
		if ( ! is_array( $stats ) || ! isset( $stats['scan_id'] ) || (int) $stats['scan_id'] !== $scan_id ) {
			$stats = array(
				'scan_id'    => $scan_id,
				'mem_base'   => 0,
				'mem_engine' => 0,
				'mem_peak'   => 0,
				'biggest'    => null,
			);
		}
		return $stats;
	}

	/**
	 * Takes the scan lock: option row `upshift_scan_lock` = "{scan id}:{token}|{expires}", read and written with SQL
	 * only (never through the option cache). INSERT IGNORE on the unique option_name is atomic; add_option() is not
	 * (it sends ON DUPLICATE KEY UPDATE). An expired lock is taken over by compare-and-swap (DECISIONS D47).
	 *
	 * @param int $scan_id Scan id (0 = start()).
	 * @return string|false Token, or false when another request holds it.
	 */
	private static function lock( $scan_id ) {
		global $wpdb;
		$token = (int) $scan_id . ':' . wp_generate_password( 12, false );
		$value = $token . '|' . ( time() + self::LOCK_TTL );
		if ( 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $value ) ) ) {
			return $token;
		}
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		if ( ! is_string( $current ) ) {
			return false;
		}
		$bar = strrpos( $current, '|' );
		if ( false !== $bar && (int) substr( $current, $bar + 1 ) > time() ) {
			return false;
		}
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK, $current ) );
		return 1 === $taken ? $token : false;
	}

	/**
	 * Extends the lock if it is still ours.
	 *
	 * @param string $token Token from lock().
	 * @return bool
	 */
	private static function refresh( $token ) {
		global $wpdb;
		$mine = $wpdb->esc_like( $token . '|' ) . '%';
		if ( 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", $token . '|' . ( time() + self::LOCK_TTL ), self::LOCK, $mine ) ) ) {
			return true;
		}
		// 0 rows also when the value didn't change (same second).
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		return is_string( $current ) && 0 === strpos( $current, $token . '|' );
	}

	/**
	 * Releases the lock if it is still ours.
	 *
	 * @param string $token Token from lock().
	 */
	private static function unlock( $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK, $wpdb->esc_like( $token . '|' ) . '%' ) );
	}
}
