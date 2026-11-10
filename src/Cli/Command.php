<?php
/**
 * WP-CLI commands (spec §7.10). Registered only when WP_CLI is defined.
 *
 * @package Upshift
 */

namespace Upshift\Cli;

use Upshift\Engine\Engine;
use Upshift\Install;
use Upshift\Report\Join;
use Upshift\Scan\Categories;
use Upshift\Scan\Runner;
use Upshift\Settings;
use Upshift\Usage\Bench;
use Upshift\Usage\Recorder;
use Upshift\Usage\Rollup;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * PHP compatibility scanning and usage map.
 */
class Command {

	/**
	 * Prints per-file usage.
	 *
	 * ## OPTIONS
	 *
	 * [--rollup]
	 * : Rebuild the usage table from the recorded filesets first.
	 *
	 * [--component=<id>]
	 * : Only this component, e.g. plugin:woocommerce or theme:twentytwentyfive.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function usage( $args, $assoc_args ) {
		global $wpdb;

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'rollup', false ) ) {
			$meta = self::rollup( 'table' === $format );
			if ( false === $meta ) {
				WP_CLI::warning( 'Another process kept the rollup lock; showing the current table.' );
			} elseif ( 'table' === $format ) {
				WP_CLI::log( sprintf( 'Rollup: %d sampled requests, %d filesets, %d files.', $meta['sampled_requests'], $meta['filesets'], $meta['files'] ) );
			}
		}

		$table     = Install::table( 'usage' );
		$component = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'component', '' );
		if ( '' !== $component ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT path_key, component, hits_total, contexts, max_share, first_seen, last_seen FROM {$table} WHERE component = %s ORDER BY hits_total DESC, path_key", $component ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		} else {
			$rows = $wpdb->get_results( "SELECT path_key, component, hits_total, contexts, max_share, first_seen, last_seen FROM {$table} ORDER BY hits_total DESC, path_key", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'path_key'   => $row['path_key'],
				'component'  => $row['component'],
				'hits'       => (int) $row['hits_total'],
				'contexts'   => Recorder::label( (int) $row['contexts'] ),
				'max_share'  => round( (float) $row['max_share'], 3 ),
				'first_seen' => $row['first_seen'],
				'last_seen'  => $row['last_seen'],
			);
		}
		if ( empty( $items ) && 'table' === $format ) {
			WP_CLI::log( 'No usage data. Enable usage recording, wait for requests, then run with --rollup.' );
			return;
		}
		WP_CLI\Utils\format_items( $format, $items, array( 'path_key', 'component', 'hits', 'contexts', 'max_share', 'first_seen', 'last_seen' ) );
	}

	/**
	 * Runs the rollup to the end in time-budgeted steps (DECISIONS D48): continues a running job or starts one.
	 *
	 * @param bool $verbose Print one line per step.
	 * @return array|false Meta, or false if another process kept the lock.
	 */
	private static function rollup( $verbose ) {
		return Rollup::run_all(
			function ( $step ) use ( $verbose ) {
				if ( ! $verbose ) {
					return;
				}
				if ( $step['busy'] ) {
					WP_CLI::log( 'Rollup step: another step holds the lock; waiting…' );
					return;
				}
				$work = array();
				foreach ( $step['work'] as $phase => $seconds ) {
					$work[] = sprintf( '%s %.2f s', $phase, $seconds );
				}
				WP_CLI::log( sprintf( 'Rollup step: %s; now %s, sets %d/%d, files %d/%d; %.2f s, peak %.1f MB (+%.1f MB).', implode( ', ', $work ), $step['running'] ? $step['phase'] : 'done', $step['sets_done'], $step['sets_total'], $step['files_done'], $step['files_total'], $step['seconds'], $step['mem_peak'] / 1048576, ( $step['mem_peak'] - $step['mem_start'] ) / 1048576 ) );
			}
		);
	}

	/**
	 * Deletes all usage data (filesets, hit counters, usage table).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @subcommand usage-reset
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function usage_reset( $args, $assoc_args ) {
		WP_CLI::confirm( 'Delete all recorded usage data?', $assoc_args );
		Rollup::reset();
		WP_CLI::success( 'Usage data deleted.' );
	}

	/**
	 * Benchmarks the recorder, spec §7.2 / M3 (DECISIONS D14).
	 *
	 * Reports median / p95 / max separately for (a) hot-path prep (filter + sort + hash),
	 * (b) the DB write (lock limit + hit upsert + restore, 3 queries) and (c) the new-set cost (exists check + key building +
	 * fileset insert). Uses this process's included files, cold path caches each iteration,
	 * fake hashes and the fake day 1970-01-01; fake rows are deleted afterwards.
	 * On XAMPP these numbers are reported only; the M3b gate is the live-site button.
	 *
	 * ## OPTIONS
	 *
	 * [--iterations=<n>]
	 * : Number of iterations.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--[no-]jit]
	 * : --no-jit turns PCRE JIT off for this run (ini pcre.jit=0), to reproduce hosts without JIT.
	 *
	 * @subcommand bench-recorder
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function bench_recorder( $args, $assoc_args ) {
		// Before any recorder pattern is compiled in this process (PHP caches compiled patterns).
		if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'jit', true ) ) {
			ini_set( 'pcre.jit', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- dev benchmark only, this WP-CLI process.
		}
		$result = Bench::run( (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'iterations', 500 ) );
		// Same store as the Usage tab button: "last benchmark run" for the Usage tab and M3a information.
		Bench::store( $result );
		if ( '' !== $result['error'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::log( sprintf( 'Iterations: %d. Included files: %d (%d path bytes). Content files per set: %d (%d path bytes). Set hash: %s.', $result['iterations'], $result['files'], $result['file_bytes'], $result['paths'], $result['path_bytes'], $result['hash_algo'] ) );
		WP_CLI::log( 'Environment: ' . Bench::describe_environment( $result['env'] ) );
		$labels = array(
			'prep'    => '(a) hot-path prep',
			'db'      => '(b) DB write (3 queries)',
			'new_set' => '(c) new-set cost',
		);
		$rows   = array();
		foreach ( $labels as $name => $label ) {
			$stats  = $result['stats'][ $name ];
			$rows[] = array(
				'metric'    => $label,
				'median_ms' => sprintf( '%.3f', $stats['median'] ),
				'p95_ms'    => sprintf( '%.3f', $stats['p95'] ),
				'max_ms'    => sprintf( '%.3f', $stats['max'] ),
			);
		}
		foreach ( $result['split'] as $phase => $stats ) {
			$rows[] = array(
				'metric'    => '    prep: ' . $phase,
				'median_ms' => sprintf( '%.3f', $stats['median'] ),
				'p95_ms'    => sprintf( '%.3f', $stats['p95'] ),
				'max_ms'    => sprintf( '%.3f', $stats['max'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'median_ms', 'p95_ms', 'max_ms' ) );
		WP_CLI::log( 'The "prep:" rows are a separate phase-by-phase run of the same prep (not gated).' );
		WP_CLI::log( sprintf( 'Cleanup: %d fake hit row(s), %d fake fileset row(s) deleted.', $result['cleanup']['hits'], $result['cleanup']['sets'] ) );

		$prep = $result['stats']['prep']['median'];
		$db   = $result['stats']['db']['median'];
		WP_CLI::log( sprintf( 'Prep median %.3f ms (%s %.1f ms; gated on live sites only, D14).', $prep, $prep <= Bench::PREP_GATE_MS ? '<=' : '>', Bench::PREP_GATE_MS ) );
		if ( $db > Bench::DB_WARN_MS ) {
			WP_CLI::log( sprintf( 'DB write median %.3f ms > %.0f ms: on a live site, lower the sample rate.', $db, Bench::DB_WARN_MS ) );
		}
	}

	/**
	 * Runs a full scan in this process (no time budget; the memory check stays on) and prints per-component results.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<version>]
	 * : PHP version the site runs now. Default: the "from" override setting, else the running PHP.
	 *
	 * [--target=<version>]
	 * : PHP version to move to. Default: the default target setting.
	 *
	 * [--component=<id>]
	 * : Only this component, e.g. plugin:woocommerce, mu-plugins or dropin:object-cache.
	 *
	 * [--active-only]
	 * : Leave out inactive plugins (the Scan tab's default). Without it, WP-CLI scans every plugin.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function scan( $args, $assoc_args ) {
		$from      = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'from', Engine::default_from() );
		$target    = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'target', Settings::get( 'default_target' ) );
		$component = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'component', '' );
		$inactive  = ! WP_CLI\Utils\get_flag_value( $assoc_args, 'active-only', false );
		if ( '8.5' === $target ) {
			WP_CLI::warning( 'PHP 8.5 rules may be incomplete.' );
		}

		$started = microtime( true );
		$scan_id = Runner::start( $from, $target, $component, $inactive );
		if ( is_wp_error( $scan_id ) ) {
			$message = $scan_id->get_error_message();
			if ( 'running' === $scan_id->get_error_code() ) {
				$message .= ' (Tools → Upshift → Scan has Resume and Cancel.)';
			}
			WP_CLI::error( $message );
		}
		$scan = Runner::get( $scan_id );
		WP_CLI::log( sprintf( 'Scan #%d, range %s-%s: %d files (%.1f s for the inventory). memory_limit %s.', $scan_id, $from, $target, $scan['files_total'], microtime( true ) - $started, ini_get( 'memory_limit' ) ) );

		// Per component: seconds and peak memory (per-file peaks, PHP 8.2+; the process peak so far below).
		$timing  = array();
		$pending = max( 1, (int) $scan['files_total'] - (int) $scan['files_done'] );
		$bar     = WP_CLI\Utils\make_progress_bar( 'Scanning', $pending );
		$result  = Runner::batch(
			$scan_id,
			array(
				'time_budget' => 0,
				'retry_fresh' => false,
				'on_file'     => function ( $row, $status, $seconds, $peak ) use ( &$timing, $bar ) {
					$id = $row['component'];
					if ( ! isset( $timing[ $id ] ) ) {
						$timing[ $id ] = array(
							'seconds' => 0.0,
							'peak'    => 0,
						);
					}
					$timing[ $id ]['seconds'] += $seconds;
					$timing[ $id ]['peak']     = max( $timing[ $id ]['peak'], $peak );
					$bar->tick();
				},
			)
		);
		$bar->finish();
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$summary = Runner::summary( $scan_id );
		$rows    = array();
		foreach ( $summary['components'] as $id => $c ) {
			$status = $c['status'];
			$row    = array(
				'component'     => $id,
				'active'        => $c['active'] ? 'yes' : 'no',
				'files'         => $c['files'],
				'scanned'       => isset( $status['done'] ) ? $status['done'] : 0,
				'skipped'       => ( isset( $status['skipped_large'] ) ? $status['skipped_large'] : 0 ) + ( isset( $status['unreadable'] ) ? $status['unreadable'] : 0 ),
				'engine_errors' => ( isset( $status['engine_error'] ) ? $status['engine_error'] : 0 ) + ( isset( $status['partial'] ) ? $status['partial'] : 0 ),
			);
			foreach ( $c['category'] as $category => $n ) {
				$row[ $category ] = $n;
			}
			$row['seconds'] = isset( $timing[ $id ] ) ? sprintf( '%.2f', $timing[ $id ]['seconds'] ) : '0.00';
			$row['peak_mb'] = isset( $timing[ $id ] ) ? sprintf( '%.1f', $timing[ $id ]['peak'] / 1048576 ) : '-';
			$rows[]         = $row;
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}

		$scan  = $summary['scan'];
		$stats = $summary['stats'];
		$mb    = function ( $bytes ) {
			return sprintf( '%.1f MB', $bytes / 1048576 );
		};
		$tiers = array_fill_keys( Categories::counted(), 0 );
		foreach ( $summary['components'] as $c ) {
			foreach ( $tiers as $category => $n ) {
				$tiers[ $category ] += $c['active'] ? $c['category'][ $category ] : 0;
			}
		}
		WP_CLI::log( Join::headline( $tiers, $scan['from_version'], $scan['target_version'], $summary['checked'], $summary['file_checks']['code_active'] ) . ' (active components)' );
		if ( ! $summary['inactive_scanned'] ) {
			WP_CLI::log( Join::inactive_note() );
		}
		WP_CLI::log( sprintf( 'Problems new for this upgrade (removed, needs extension, deprecated, changed): %d errors, %d warnings. By category: %s (newer_than_current, advisory and ignored are information, not counted). Already true on PHP %s: %s. Guarded (not counted): %d.', $summary['by_severity']['error'], $summary['by_severity']['warning'], http_build_query( $summary['by_category'], '', ', ' ), $scan['from_version'], $summary['checked'] ? $summary['current'] : 'not checked', $summary['guarded'] ) );
		WP_CLI::log( sprintf( 'Duration %.1f s (engine %.1f s, new-risk re-scan %.1f s extra), %d batch(es). Status: %s.', microtime( true ) - $started, $scan['engine_seconds'], max( 0, (float) $scan['baseline_seconds'] ), $scan['batches'], $scan['status'] ) );
		if ( ! empty( $scan['files_reused'] ) ) {
			WP_CLI::log( sprintf( 'Reused %d unchanged file(s) from earlier scans (not checked again).', $scan['files_reused'] ) );
		}
		if ( null !== $stats ) {
			WP_CLI::log( sprintf( 'Memory: %s before the engine loaded, %s after engine init (before the first file), peak %s (%.1f%% of memory_limit %s).', $mb( $stats['mem_base'] ), $mb( $stats['mem_engine'] ), $mb( $scan['peak_memory'] ), 100 * $scan['peak_memory'] / $summary['memory_limit'], $mb( $summary['memory_limit'] ) ) );
			if ( ! empty( $stats['biggest'] ) ) {
				WP_CLI::log( sprintf( 'Biggest file: %s (%.1f KB): %.2f s, peak %s.', $stats['biggest']['path_key'], $stats['biggest']['bytes'] / 1024, $stats['biggest']['seconds'], $mb( $stats['biggest']['peak'] ) ) );
			}
		}
		foreach ( $summary['skipped_files'] as $skipped ) {
			WP_CLI::warning( sprintf( '%s: %s: %s', 'partial' === $skipped['status'] ? 'Not fully checked' : 'Not scanned', $skipped['path_key'], $skipped['note'] ) );
		}
		foreach ( $summary['engine_errors'] as $error ) {
			WP_CLI::warning( sprintf( 'Engine error: %s: %s', $error['path_key'], $error['note'] ) );
		}
		if ( 'lock' === $result['stopped'] ) {
			WP_CLI::error( sprintf( 'Scan #%d: another request took over the scan lock (e.g. Resume in Tools → Upshift → Scan), so this process stopped; the scan continues there.', $scan_id ) );
		}
		if ( 'done' !== $scan['status'] ) {
			WP_CLI::error( sprintf( 'Scan #%d ended with status %s (%s). %s', $scan_id, $scan['status'], $result['stopped'], (string) $scan['error'] ) );
		}
		WP_CLI::success( sprintf( 'Scan #%d done.', $scan_id ) );
	}

	/**
	 * Prints the spike metrics (spec §2, §7.8): M1 / M3a / M4, counted findings by bucket, top components, usage, scan.
	 *
	 * Counted findings = active components, categories removed / deprecated / changed. newer_than_current, advisory and
	 * inactive components are shown separately and never counted. Runs the usage rollup first if it is older than 1 hour.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - markdown
	 * ---
	 *
	 * @subcommand spike-report
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function spike_report( $args, $assoc_args ) {
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( Rollup::overdue() ) {
			self::rollup( false );
		}
		$m = Join::metrics();

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		if ( 'markdown' === $format ) {
			WP_CLI::line( Join::markdown( $m ) );
			return;
		}

		$gates = array();
		foreach ( array(
			'M1'  => 'm1',
			'M3a' => 'm3a',
			'M4'  => 'm4',
		) as $name => $key ) {
			$gates[] = array(
				'metric' => $name,
				'result' => strtoupper( $m[ $key ]['result'] ),
				'detail' => $m[ $key ]['note'],
			);
		}
		if ( isset( $m['headline'] ) ) {
			WP_CLI::log( $m['headline'] );
		}
		WP_CLI\Utils\format_items( 'table', $gates, array( 'metric', 'result', 'detail' ) );
		WP_CLI::log( 'M3a information (not gated): ' . $m['m3a_info'] );

		if ( null !== $m['findings'] ) {
			$f    = $m['findings'];
			$rows = array();
			foreach ( $f['by_bucket'] as $bucket => $n ) {
				$rows[] = array(
					'bucket'   => $bucket,
					'findings' => $n,
					'share'    => $f['counted'] > 0 ? sprintf( '%.1f%%', 100 * $n / $f['counted'] ) : '-',
				);
			}
			WP_CLI::log( sprintf( 'Counted findings (new for this upgrade): %d (will break %d, needs a PHP extension %d, deprecation notices %d, behaviour changes %d). Not counted: %d guarded, already true on the from version %s, %d newer_than_current, %d advisory, %d ignored (information), %s in inactive components.', $f['counted'], $f['by_category']['removed'], $f['by_category']['needs_extension'], $f['by_category']['deprecated'], $f['by_category']['changed'], $f['guarded'], $f['checked'] ? (string) $f['current'] : 'not checked (old scan)', $f['newer_than_current'], $f['advisory'], $f['ignored'], $m['scan']['inactive_scanned'] ? (string) $f['inactive'] : 'not scanned' ) );
			WP_CLI::log( 'Counted findings by bucket (always = whole site):' );
			WP_CLI\Utils\format_items( 'table', $rows, array( 'bucket', 'findings', 'share' ) );
			if ( $f['components'] ) {
				WP_CLI::log( 'Top components (always = whole site):' );
				$items = array();
				foreach ( $f['components'] as $id => $c ) {
					$items[] = array_merge( array( 'component' => $id ), $c );
				}
				WP_CLI\Utils\format_items( 'table', $items, array( 'component', 'counted', 'always', 'sometimes', 'never' ) );
			}
		}

		$u      = $m['usage'];
		$by_ctx = array();
		foreach ( $u['requests_by_context'] as $label => $count ) {
			$by_ctx[] = $label . ' ' . $count;
		}
		WP_CLI::log( sprintf( 'Usage: %d days (%s to %s), %d sampled requests (%s), %d filesets.', $u['days_observed'], $u['first_day'], $u['last_day'], $u['sampled_requests'], implode( ', ', $by_ctx ), $u['filesets'] ) );
		WP_CLI::log( sprintf( 'Recorder prep: avg %.3f ms, max %.3f ms, %.2f%% of requests >= 3 ms.', $u['avg_prep_ms'], $u['max_prep_ms'], 100 * $u['slow_share'] ) );
		WP_CLI::log( sprintf( 'Recorder prep as %% of request time: median %s, p95 %s (%d requests with a request time, avg request %.1f ms).', $u['rel_median'], $u['rel_p95'], $u['req_hits'], $u['avg_req_ms'] ) );
		if ( null !== $m['scan'] ) {
			$s = $m['scan'];
			WP_CLI::log( sprintf( 'Scan #%d %s (%s): %d files, %d skipped, %d engine errors, %d s (engine %.1f s, new-risk re-scan %s), %d batches, peak %.1f MB of %.1f MB (%s).', $s['id'], $s['range'], $s['finished'], $s['files'], $s['skipped'], $s['engine_errors'], $s['seconds'], $s['engine_seconds'], $s['checked'] ? sprintf( '%.1f s', $s['baseline_seconds'] ) : 'not run', $s['batches'], $s['peak_memory'] / 1048576, $s['memory_limit'] / 1048576, $s['memory_limit_recorded'] ? $s['sapi'] : 'current limit, not recorded at scan time' ) );
		}
	}

	/**
	 * Scans one file with the engine and prints the findings. Doesn't touch the database.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : File to scan: absolute, or relative to the current directory or the WordPress root.
	 *
	 * [--from=<version>]
	 * : PHP version the site runs now. Default: the "from" override setting, else the running PHP.
	 *
	 * [--target=<version>]
	 * : PHP version to move to. Default: the default target setting.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @subcommand scan-file
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function scan_file( $args, $assoc_args ) {
		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$from   = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'from', Engine::default_from() );
		$target = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'target', Settings::get( 'default_target' ) );

		$error = Engine::range_error( $from, $target );
		if ( '' !== $error ) {
			WP_CLI::error( $error );
		}
		if ( '8.5' === $target ) {
			WP_CLI::warning( 'PHP 8.5 rules may be incomplete.' );
		}

		$path       = '';
		$candidates = path_is_absolute( $args[0] ) ? array( $args[0] ) : array( getcwd() . '/' . $args[0], ABSPATH . $args[0] );
		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				$path = (string) realpath( $candidate );
				break;
			}
		}
		if ( '' === $path ) {
			WP_CLI::error( sprintf( 'File not found: %s', $args[0] ) );
		}

		try {
			$started = microtime( true );
			$engine  = Engine::for_range( $from, $target );
			$booted  = microtime( true );
			$result  = $engine->scan_file( $path );
			$done    = microtime( true );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( Engine::is_error( $result ) ) {
			WP_CLI::error( sprintf( 'Engine error in %s: %s', $path, $result['engine_error'] ) );
		}
		foreach ( $result as $i => $finding ) {
			$result[ $i ]['category'] = Categories::of( $finding['rule'] );
		}

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result ) );
			return;
		}

		if ( $result ) {
			WP_CLI\Utils\format_items( 'table', $result, array( 'line', 'severity', 'category', 'rule', 'message' ) );
		}
		$counts = array_count_values( wp_list_pluck( $result, 'severity' ) );
		WP_CLI::log(
			sprintf(
				'%s, range %s: %d errors, %d warnings. Engine init %.2f s, scan %.2f s, peak memory %.1f MB.',
				$path,
				$engine->range(),
				isset( $counts['error'] ) ? $counts['error'] : 0,
				isset( $counts['warning'] ) ? $counts['warning'] : 0,
				$booted - $started,
				$done - $booted,
				memory_get_peak_usage( true ) / 1048576
			)
		);
	}

	/**
	 * Runs the engine fixtures (spec §9.2) against tests/fixtures/expected.json.
	 *
	 * Cases run in file order in this one process. The ranges switch (7.4-8.4, 8.3-8.4, 7.4-8.4), which
	 * also tests the engine's static-state reset (DECISIONS D22). Exit code 1 when a required case fails;
	 * a non-required case that fails is listed as KNOWN-GAP.
	 */
	public function selftest() {
		$dir  = UPSHIFT_DIR . '/tests/fixtures';
		$json = is_readable( $dir . '/expected.json' ) ? file_get_contents( $dir . '/expected.json' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		$spec = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $spec ) || empty( $spec['cases'] ) || ! is_array( $spec['cases'] ) ) {
			WP_CLI::error( sprintf( 'Missing or invalid %s/expected.json (the fixtures are not in the release zip).', $dir ) );
		}

		$rows    = array();
		$failed  = 0;
		$gaps    = 0;
		$covered = array();
		$started = microtime( true );
		foreach ( $spec['cases'] as $test ) {
			$fixture  = (string) $test['fixture'];
			$required = ! empty( $test['required'] );

			$covered[ $fixture ]           = true;
			list( $ok, $expected, $found ) = self::check_case( $dir . '/' . $fixture, $test );
			if ( $ok ) {
				$status = 'PASS';
			} elseif ( $required ) {
				$status = 'FAIL';
				++$failed;
			} else {
				$status = 'KNOWN-GAP';
				++$gaps;
			}
			$rows[] = array(
				'fixture'  => $fixture,
				'range'    => (string) $test['range'],
				'required' => $required ? 'yes' : 'no',
				'expected' => $expected,
				'found'    => $found,
				'result'   => $status,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'fixture', 'range', 'required', 'expected', 'found', 'result' ) );

		foreach ( (array) glob( $dir . '/*.php' ) as $file ) {
			if ( ! isset( $covered[ basename( $file ) ] ) ) {
				WP_CLI::warning( sprintf( 'Fixture without a case in expected.json: %s', basename( $file ) ) );
			}
		}
		WP_CLI::log( sprintf( '%d cases in %.2f s, peak memory %.1f MB.', count( $rows ), microtime( true ) - $started, memory_get_peak_usage( true ) / 1048576 ) );
		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( '%d required case(s) failed.', $failed ) );
		}
		WP_CLI::success( sprintf( 'All required cases passed (%d known gap(s)).', $gaps ) );
	}

	/**
	 * Scans one fixture and compares the result with its case from expected.json.
	 *
	 * @param string $path Fixture file.
	 * @param array  $test Case from expected.json: range, expect, rules (rule_contains, min, max, category, counted),
	 *                     max_total.
	 * @return array array( bool $ok, string $expected, string $found ).
	 */
	private static function check_case( $path, array $test ) {
		$expect    = isset( $test['expect'] ) ? (string) $test['expect'] : 'findings';
		$rules     = array();
		$wanted    = array();
		$need_base = false;
		foreach ( isset( $test['rules'] ) ? (array) $test['rules'] : array() as $rule ) {
			$rule      = array(
				'contains' => (string) $rule['rule_contains'],
				'min'      => isset( $rule['min'] ) ? (int) $rule['min'] : ( isset( $rule['max'] ) ? 0 : 1 ),
				'max'      => isset( $rule['max'] ) ? (int) $rule['max'] : null,
				// Optional: every finding matching the rule must have this category (DECISIONS D29).
				'category' => isset( $rule['category'] ) ? (string) $rule['category'] : '',
				// Optional: every matching finding must (true) or must not (false) count as a new risk, after the
				// re-scan with testVersion = the range's "from" version (DECISIONS D52).
				'counted'  => isset( $rule['counted'] ) ? (bool) $rule['counted'] : null,
				// Optional: every matching finding must (true) or must not (false) be marked guarded (DECISIONS D56).
				'guarded'  => isset( $rule['guarded'] ) ? (bool) $rule['guarded'] : null,
			);
			$rules[]   = $rule;
			$need_base = $need_base || null !== $rule['counted'];
			// Last two segments (sniff + error code) keep the table readable; the match uses the full substring.
			$label    = implode( '.', array_slice( explode( '.', $rule['contains'] ), -2 ) );
			$wanted[] = ( null === $rule['max'] ? "{$label} >= {$rule['min']}" : ( $rule['min'] > 0 ? "{$label} {$rule['min']}-{$rule['max']}" : "{$label} <= {$rule['max']}" ) )
				. ( '' !== $rule['category'] ? " [{$rule['category']}]" : '' )
				. ( null !== $rule['counted'] ? ( $rule['counted'] ? ' [counted]' : ' [not counted]' ) : '' )
				. ( null !== $rule['guarded'] ? ( $rule['guarded'] ? ' [guarded]' : ' [not guarded]' ) : '' );
		}
		if ( isset( $test['max_total'] ) ) {
			$wanted[] = 'total <= ' . (int) $test['max_total'];
		}
		if ( 'no_crash' === $expect ) {
			$wanted[] = 'no crash';
		} elseif ( 'error_or_findings' === $expect ) {
			$wanted[] = 'engine error or >= 1 finding';
		}
		$expected = implode( '; ', $wanted );

		if ( ! is_file( $path ) ) {
			return array( false, $expected, 'fixture file missing' );
		}
		$range = explode( '-', (string) $test['range'], 2 );
		try {
			$result = Engine::for_range( $range[0], isset( $range[1] ) ? $range[1] : '' )->scan_file( $path );
		} catch ( \Throwable $e ) {
			return array( false, $expected, 'exception: ' . $e->getMessage() );
		}
		if ( Engine::is_error( $result ) ) {
			return array( in_array( $expect, array( 'no_crash', 'error_or_findings' ), true ), $expected, 'engine error: ' . $result['engine_error'] );
		}
		if ( $need_base ) {
			// The same new-risk check as Runner::scan_one(): re-scan with testVersion = from alone.
			try {
				$base = Engine::baseline( $range[0] )->scan_file( $path );
			} catch ( \Throwable $e ) {
				return array( false, $expected, 'baseline exception: ' . $e->getMessage() );
			}
			if ( Engine::is_error( $base ) ) {
				return array( false, $expected, 'baseline engine error: ' . $base['engine_error'] );
			}
			$result = Runner::mark_baseline( $result, $base );
		}

		$ok     = true;
		$counts = array();
		foreach ( $rules as $rule ) {
			$count = 0;
			$wrong = array();
			foreach ( $result as $finding ) {
				if ( false !== strpos( $finding['rule'], $rule['contains'] ) ) {
					++$count;
					$category = Categories::of( $finding['rule'] );
					if ( '' !== $rule['category'] && $category !== $rule['category'] ) {
						$wrong[ $category ] = true;
					}
					if ( null !== $rule['counted'] ) {
						$counted = Join::is_counted(
							array(
								'active'   => true,
								'category' => $category,
								'baseline' => ! empty( $finding['baseline'] ),
								'guarded'  => ! empty( $finding['guarded'] ),
							)
						);
						if ( $counted !== $rule['counted'] ) {
							$wrong[ $counted ? 'counted' : 'not counted' ] = true;
						}
					}
					if ( null !== $rule['guarded'] && ! empty( $finding['guarded'] ) !== $rule['guarded'] ) {
						$wrong[ ! empty( $finding['guarded'] ) ? 'guarded' : 'not guarded' ] = true;
					}
				}
			}
			$tag      = '' !== $rule['category'] ? $rule['category'] : '';
			$tag     .= null !== $rule['counted'] ? ( '' !== $tag ? ', ' : '' ) . ( $rule['counted'] ? 'counted' : 'not counted' ) : '';
			$tag     .= null !== $rule['guarded'] ? ( '' !== $tag ? ', ' : '' ) . ( $rule['guarded'] ? 'guarded' : 'not guarded' ) : '';
			$counts[] = $count . ( '' !== $tag && $count > 0 ? ( $wrong ? ' [wrong: ' . implode( ',', array_keys( $wrong ) ) . ']' : " [{$tag}]" ) : '' );
			if ( $count < $rule['min'] || ( null !== $rule['max'] && $count > $rule['max'] ) || $wrong ) {
				$ok = false;
			}
		}
		$total = count( $result );
		if ( isset( $test['max_total'] ) && $total > (int) $test['max_total'] ) {
			$ok = false;
		}
		if ( 'error_or_findings' === $expect && 0 === $total ) {
			$ok = false;
		}
		$found = $counts ? implode( '; ', $counts ) . '; ' : '';
		return array( $ok, $expected, $found . 'total ' . $total );
	}
}
