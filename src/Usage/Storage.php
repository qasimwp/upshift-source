<?php
/**
 * Upshift's database size: the number shown in Settings, the 50 MB cap and the table rebuild (DECISIONS D63).
 *
 * Runs from WP-Cron and admin pages only, never on a visitor request.
 *
 * @package Upshift
 */

namespace Upshift\Usage;

use Upshift\Install;
use Upshift\Scan\Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

/**
 * Size, cap and rebuild.
 */
class Storage {

	const MB     = 1048576;
	const CAP    = 52428800; // 50 MB: the most Upshift may use, including a rollup's staging rows.
	const TARGET = 42991616; // 41 MB when idle: a rollup's staging rows (about 9 MB on the largest site seen) still fit under the cap.

	const HOOK             = 'upshift_storage';
	const OPTION           = 'upshift_storage_state';
	const QUIET_HOUR       = 3;
	const REBUILD_MIN_FREE = 10485760;
	const REBUILD_DAILY    = 72000; // 20 h.
	const REBUILD_OVER_CAP = 21600; // 6 h.
	const BACK_OFF         = 604800; // 7 days after a failed or refused rebuild.
	const BUDGET           = 20.0;
	const NIGHTLY_BUDGET   = 150.0;
	const DELETE_CHUNK     = 5000;
	const ORPHAN_PAGE      = 1000;
	const ROW_OVERHEAD     = 200; // Bytes of a filesets row besides its list (columns, primary key, set_hash index), measured on dev.

	/**
	 * Fresh size of Upshift's own tables and options, and nothing else: the exact tables of Install::tables() and the
	 * options named upshift_*. Data + index, without free space (DECISIONS D63).
	 *
	 * @return array|null total (bytes), options (bytes), tables (name => data, index, free, rows, engine); null when
	 *                    information_schema can't be read.
	 */
	public static function size() {
		global $wpdb;

		$names = array();
		foreach ( Install::tables() as $name ) {
			$names[ Install::table( $name ) ] = $name;
		}
		$restore = self::fresh_stats();
		$in      = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is only %s placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT table_name AS t, data_length AS d, index_length AS i, data_free AS f, table_rows AS r, engine AS e FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ({$in})", array_keys( $names ) ), ARRAY_A );
		if ( null !== $restore ) {
			$wpdb->query( $wpdb->prepare( 'SET SESSION information_schema_stats_expiry = %d', $restore ) );
		}
		if ( empty( $rows ) ) {
			return null;
		}

		$tables = array();
		$total  = 0;
		foreach ( $rows as $row ) {
			$row   = array_change_key_case( $row, CASE_LOWER );
			$short = isset( $names[ $row['t'] ] ) ? $names[ $row['t'] ] : $row['t'];

			$tables[ $short ] = array(
				'data'   => (int) $row['d'],
				'index'  => (int) $row['i'],
				'free'   => (int) $row['f'],
				'rows'   => (int) $row['r'],
				'engine' => (string) $row['e'],
			);
			$total           += (int) $row['d'] + (int) $row['i'];
		}
		$like    = $wpdb->esc_like( 'upshift_' ) . '%';
		$options = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(LENGTH(option_name) + LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		return array(
			'total'   => $total + $options,
			'options' => $options,
			'tables'  => $tables,
		);
	}

	/**
	 * MySQL 8 serves information_schema sizes from a cache up to 24 h old: turn that off for this connection.
	 * MariaDB has no such setting and is not asked.
	 *
	 * @return int|null Value to restore, or null when nothing was changed.
	 */
	private static function fresh_stats() {
		global $wpdb;

		$version = (string) $wpdb->get_var( 'SELECT VERSION()' );
		if ( false !== stripos( $version, 'mariadb' ) || version_compare( $version, '8.0.0', '<' ) ) {
			return null;
		}
		$old = $wpdb->get_var( 'SELECT @@SESSION.information_schema_stats_expiry' );
		if ( null === $old ) {
			return null;
		}
		$wpdb->query( 'SET SESSION information_schema_stats_expiry = 0' );
		return (int) $old;
	}

	/**
	 * Cron: the nightly run, with a rebuild when worthwhile.
	 */
	public static function nightly() {
		self::run( true );
	}

	/**
	 * Cron: the hourly check, which acts only above the cap.
	 */
	public static function hourly() {
		self::run( false );
	}

	/**
	 * Never throws: a failure is stored in the recorder flags.
	 *
	 * @param bool $nightly Whether this is the nightly run.
	 */
	private static function run( $nightly ) {
		if ( UPSHIFT_DB_VERSION !== get_option( 'upshift_db_version' ) ) {
			return;
		}
		$deleted = Rollup::guarded(
			function () use ( $nightly ) {
				return self::enforce( $nightly );
			}
		);
		if ( true === $deleted ) {
			// So "not seen running in the last N days" shows the days really kept.
			Rollup::daily();
		}
	}

	/**
	 * Brings the size under the target. Empty space only: a rebuild. Live data over the target: a day still waiting for its
	 * fold is left to the fold (unless above the cap); otherwise the oldest days go, then one rebuild, and only then the
	 * check cache. The latest check results are never deleted.
	 *
	 * @param bool $nightly Whether this is the nightly run. Otherwise it acts only above the cap.
	 * @param int  $now     Current time (tests).
	 * @return bool Whether days were deleted.
	 */
	public static function enforce( $nightly, $now = 0 ) {
		$now  = $now > 0 ? $now : time();
		$size = self::size();
		if ( null === $size || ( ! $nightly && $size['total'] <= self::CAP ) ) {
			return false;
		}
		$status = Rollup::status();
		if ( null !== Runner::latest( 'running' ) || $status['progress']['running'] ) {
			return false;
		}
		$deleted = Rollup::with_lock(
			function () use ( $nightly, $now, $size ) {
				return self::work( $nightly, $now, $size );
			}
		);
		return true === $deleted;
	}

	/**
	 * The work of enforce(), under the rollup lock.
	 *
	 * @param bool  $nightly Nightly run.
	 * @param int   $now     Current time.
	 * @param array $size    Output of size().
	 * @return bool Whether days were deleted.
	 */
	private static function work( $nightly, $now, array $size ) {
		$deadline = microtime( true ) + self::budget( $nightly );
		$state    = self::state();

		// An earlier delete that ran out of time: finish it first, or its leftovers would count as live data and cost more days.
		if ( ! empty( $state['pending_cutoff'] ) || ! empty( $state['orphans'] ) ) {
			if ( ! empty( $state['pending_cutoff'] ) && ! self::delete_hits_before( $state['pending_cutoff'], $deadline ) ) {
				self::save( $state );
				return false;
			}
			unset( $state['pending_cutoff'] );
			$state['orphans'] = ! self::purge_orphans( $state, $deadline );
			self::save( $state );
			self::rollup_soon();
			if ( $state['orphans'] ) {
				return false;
			}
			$size = self::size();
		}

		$over   = $size['total'] > self::CAP;
		$excess = self::live( $size ) - self::TARGET;
		if ( $excess > 0 && ! $over && Rollup::fold_pending() ) {
			// A finished day not folded yet: the daily rollup folds it to a few KB, so no day is deleted for it.
			$excess = 0;
		}
		if ( $excess <= 0 ) {
			// Only empty space to win back.
			if ( self::may_rebuild( $state, $now, $over ) ) {
				self::rebuild_one( $size, $state, $now );
			}
			self::save( $state );
			return false;
		}

		// Live data over the target: delete first, then rebuild once (a rebuild before the delete would copy rows about to go).
		$deleted = self::delete_days( $excess, $size, $deadline, $state );
		if ( $deleted > 0 ) {
			$state['last_cap_days'] = $deleted;
			$state['last_cap_at']   = $now;
			if ( ! empty( $state['pending_cutoff'] ) ) {
				self::save( $state );
				return true;
			}
			$state['orphans'] = ! self::purge_orphans( $state, $deadline );
			if ( ! $state['orphans'] && self::may_rebuild( $state, $now, true ) ) {
				// Both tables the delete emptied: their pages only come back with a rebuild.
				foreach ( array( 'filesets', 'fileset_hits' ) as $name ) {
					if ( microtime( true ) < $deadline && ! self::rebuild_one( self::size(), $state, $now, $name ) ) {
						break;
					}
				}
			}
			self::analyze();
			self::save( $state );
			self::rollup_soon();
			return true;
		}

		// No day left to delete: the check cache can be rebuilt by the next check.
		if ( self::live( $size ) > self::TARGET ) {
			self::clear_cache();
		}
		self::save( $state );
		return false;
	}

	/**
	 * Saved state.
	 *
	 * @return array
	 */
	private static function state() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Saves the state (autoload off).
	 *
	 * @param array $state State.
	 */
	private static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Size that counts for the cap: what is stored, without the empty space a rebuild would remove from filesets.
	 *
	 * @param array $size Output of size().
	 * @return int Bytes.
	 */
	public static function live( array $size ) {
		global $wpdb;

		if ( ! isset( $size['tables']['filesets'] ) ) {
			return (int) $size['total'];
		}
		$table  = Install::table( 'filesets' );
		$row    = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(files)), 0) AS b FROM {$table}", ARRAY_A );
		$sets   = $size['tables']['filesets'];
		$actual = $sets['data'] + $sets['index'];
		$live   = (int) $row['b'] + (int) $row['n'] * self::ROW_OVERHEAD;
		return (int) $size['total'] - $actual + min( $actual, $live );
	}

	/**
	 * Whether a rebuild may start now. Failed or refused ones wait 7 days; there is never a retry loop.
	 *
	 * @param array $state State.
	 * @param int   $now   Current time.
	 * @param bool  $over  Whether the size is above the cap.
	 * @return bool
	 */
	private static function may_rebuild( array $state, $now, $over ) {
		if ( ! empty( $state['blocked_until'] ) && (int) $state['blocked_until'] > $now ) {
			return false;
		}
		$last = isset( $state['last_rebuild'] ) ? (int) $state['last_rebuild'] : 0;
		return $now - $last >= ( $over ? self::REBUILD_OVER_CAP : self::REBUILD_DAILY );
	}

	/**
	 * Rebuilds the table that gains most, if any. The attempt is stamped first, so a request killed by the host doesn't retry.
	 *
	 * @param array|null  $size  Output of size().
	 * @param array       $state State (changed).
	 * @param int         $now   Current time.
	 * @param string|null $name  Table to rebuild (InnoDB only), or null for the one that gains most.
	 * @return bool Whether a rebuild ran and succeeded.
	 */
	private static function rebuild_one( $size, array &$state, $now, $name = null ) {
		global $wpdb;

		if ( null === $size ) {
			return false;
		}
		if ( null === $name ) {
			$name = self::table_to_rebuild( $size );
		} elseif ( ! isset( $size['tables'][ $name ] ) || 'innodb' !== strtolower( $size['tables'][ $name ]['engine'] ) ) {
			$name = null;
		}
		if ( null === $name ) {
			return false;
		}
		$state['last_rebuild'] = $now;
		unset( $state['blocked_until'] );
		self::save( $state );

		$table = Install::table( $name );
		$rows  = $wpdb->get_results( "OPTIMIZE TABLE `{$table}`", ARRAY_A );
		$error = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : '';
		foreach ( (array) $rows as $row ) {
			$row = array_change_key_case( $row, CASE_LOWER );
			if ( isset( $row['msg_type'] ) && 'error' === strtolower( (string) $row['msg_type'] ) ) {
				$error = (string) $row['msg_text'];
			}
		}
		if ( '' !== $error || null === $rows ) {
			$state['blocked_until'] = $now + self::BACK_OFF;
			self::save( $state );
			Recorder::set_error( 'storage: rebuild of ' . $name . ' failed: ' . ( '' !== $error ? $error : 'no result' ) );
			return false;
		}
		return true;
	}

	/**
	 * The table a rebuild would help most: InnoDB, with much free space, or filesets holding far less than it occupies.
	 *
	 * @param array $size Output of size().
	 * @return string|null Short table name.
	 */
	private static function table_to_rebuild( array $size ) {
		global $wpdb;

		$best = null;
		$gain = 0;
		foreach ( $size['tables'] as $name => $info ) {
			if ( 'innodb' !== strtolower( $info['engine'] ) ) {
				continue; // MyISAM would lock the table for the whole rebuild.
			}
			$value = $info['free'];
			if ( 'filesets' === $name ) {
				$table = Install::table( 'filesets' );
				$row   = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(files)), 0) AS b FROM {$table}", ARRAY_A );
				$value = max( $value, $info['data'] + $info['index'] - (int) $row['b'] - (int) $row['n'] * self::ROW_OVERHEAD );
			}
			if ( $value >= self::REBUILD_MIN_FREE && $value > $gain ) {
				$best = $name;
				$gain = $value;
			}
		}
		return $best;
	}

	/**
	 * Deletes the oldest days until the estimate fits: folded days (one small row group each) and days still in the hit
	 * rows, oldest first, whichever kind. The newest day always stays.
	 *
	 * @param int   $excess   Bytes over the target.
	 * @param array $size     Output of size().
	 * @param float $deadline microtime() to stop at.
	 * @param array $state    State (changed: pending_cutoff when the hit rows could not all be deleted in time).
	 * @return int Days deleted.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function delete_days( $excess, array $size, $deadline, array &$state ) {
		global $wpdb;

		$hits_table = Install::table( 'fileset_hits' );
		$days_table = Install::table( 'usage_days' );
		$raw        = (array) $wpdb->get_col( "SELECT DISTINCT day FROM {$hits_table} ORDER BY day" );
		$folded     = array();
		foreach ( (array) $wpdb->get_results( "SELECT day, SUM(LENGTH(data)) AS b FROM {$days_table} GROUP BY day", ARRAY_A ) as $row ) {
			$folded[ $row['day'] ] = (int) $row['b'] + 100;
		}
		$all = array_unique( array_merge( $raw, array_keys( $folded ) ) );
		sort( $all );
		if ( count( $all ) < 2 ) {
			return 0;
		}

		// A day in the hit rows costs its share of the hit rows and of the stored sets.
		$hits    = isset( $size['tables']['fileset_hits'] ) ? $size['tables']['fileset_hits']['data'] + $size['tables']['fileset_hits']['index'] : 0;
		$sets    = self::live( $size ) - ( (int) $size['total'] - $size['tables']['filesets']['data'] - $size['tables']['filesets']['index'] );
		$per_raw = empty( $raw ) ? 0 : ( $hits + $sets ) / count( $raw );

		// Every day but the newest together can't cover the excess (folded days are a few KB each): deleting would only
		// lose history.
		$most = $per_raw * count( array_diff( $raw, array( end( $all ) ) ) );
		foreach ( $folded as $day => $bytes ) {
			$most += end( $all ) === $day ? 0 : $bytes;
		}
		if ( $most < $excess ) {
			return 0;
		}

		$gone       = array();
		$raw_cutoff = '';
		$freed      = 0;
		foreach ( array_slice( $all, 0, count( $all ) - 1 ) as $day ) {
			if ( $freed >= $excess ) {
				break;
			}
			$gone[] = $day;
			if ( isset( $folded[ $day ] ) ) {
				$freed += $folded[ $day ];
			}
			if ( in_array( $day, $raw, true ) ) {
				$freed     += $per_raw;
				$raw_cutoff = $day;
			}
		}
		$folded_gone = array_values( array_intersect( $gone, array_keys( $folded ) ) );
		if ( ! empty( $folded_gone ) ) {
			$in = implode( ', ', array_fill( 0, count( $folded_gone ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is only %s placeholders.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$days_table} WHERE day IN ({$in})", $folded_gone ) );
		}
		if ( '' !== $raw_cutoff ) {
			// Delete hit rows up to and including the last day chosen.
			$cutoff = wp_date( 'Y-m-d', strtotime( $raw_cutoff . ' 12:00:00 UTC' ) + DAY_IN_SECONDS );
			if ( ! self::delete_hits_before( $cutoff, $deadline ) ) {
				$state['pending_cutoff'] = $cutoff;
			}
		}
		return count( $gone );
	}

	/**
	 * Deletes hit rows before a day in big chunks.
	 *
	 * @param string $cutoff   Y-m-d (exclusive).
	 * @param float  $deadline microtime() to stop at.
	 * @return bool Whether all were deleted.
	 * @throws \RuntimeException On a DB error.
	 */
	private static function delete_hits_before( $cutoff, $deadline ) {
		global $wpdb;

		$table = Install::table( 'fileset_hits' );
		do {
			$done = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s LIMIT %d", $cutoff, self::DELETE_CHUNK ) );
			if ( false === $done ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- stored in the recorder flags, escaped when displayed.
				throw new \RuntimeException( 'storage: ' . $wpdb->last_error );
			}
			if ( self::DELETE_CHUNK !== (int) $done ) {
				return true;
			}
		} while ( microtime( true ) < $deadline );
		return false;
	}

	/**
	 * Seconds a run may use. The nightly run raises the time limit where the host allows it, so the cap is reached in
	 * one run; the hourly check stays short.
	 *
	 * @param bool $nightly Nightly run.
	 * @return float
	 */
	private static function budget( $nightly ) {
		if ( ! $nightly ) {
			return self::BUDGET;
		}
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( $limit, (int) self::NIGHTLY_BUDGET + 60 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disallowed by the host; then the limit stays.
			$limit = (int) ini_get( 'max_execution_time' );
		}
		return $limit > 0 ? min( self::NIGHTLY_BUDGET, $limit * 0.6 ) : self::NIGHTLY_BUDGET;
	}

	/**
	 * Deletes the sets no hit row uses any more, continuing where the last run stopped.
	 *
	 * @param array $state    State (keeps the cursor).
	 * @param float $deadline microtime() to stop at.
	 * @return bool Whether it finished.
	 */
	private static function purge_orphans( array &$state, $deadline ) {
		$job = array( 'orphan_id' => isset( $state['orphan_id'] ) ? (int) $state['orphan_id'] : 0 );
		$end = Rollup::purge_orphans( $job, $deadline, self::ORPHAN_PAGE );

		$state['orphan_id'] = $end ? 0 : (int) $job['orphan_id'];
		return $end;
	}

	/**
	 * After a delete: a rollup step that starts at once, so "not seen in N days" counts only the days kept.
	 */
	private static function rollup_soon() {
		if ( ! wp_next_scheduled( Install::STEP_HOOK, array( true ) ) ) {
			wp_schedule_single_event( time(), Install::STEP_HOOK, array( true ) );
		}
	}

	/**
	 * Refreshes the sizes MariaDB and MySQL report after a delete.
	 */
	private static function analyze() {
		global $wpdb;
		foreach ( array( 'filesets', 'fileset_hits' ) as $name ) {
			$table = Install::table( $name );
			$wpdb->query( "ANALYZE TABLE `{$table}`" );
		}
	}

	/**
	 * Empties the check cache. The next check is slower once.
	 */
	private static function clear_cache() {
		global $wpdb;
		$table = Install::table( 'file_cache' );
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}

	/**
	 * Timestamp of the next quiet hour in the site's time zone.
	 *
	 * @param int $now Current time.
	 * @return int
	 */
	public static function next_quiet_time( $now ) {
		$at = new \DateTimeImmutable( '@' . $now );
		$at = $at->setTimezone( wp_timezone() )->setTime( self::QUIET_HOUR, 0 );
		return $at->getTimestamp() > $now ? $at->getTimestamp() : $at->modify( '+1 day' )->getTimestamp();
	}
}
