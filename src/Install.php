<?php
/**
 * Tables, DB version, cron scheduling, uninstall (spec §6, §7.11).
 *
 * The uninstall() method must not depend on UPSHIFT_* constants or other classes: uninstall.php
 * loads this file directly while the plugin is inactive.
 *
 * @package Upshift
 */

namespace Upshift;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Install / upgrade / uninstall.
 */
class Install {

	const CRON_HOOK         = 'upshift_daily';
	const OPTION_DB_VERSION = 'upshift_db_version';
	const OPTION_SETTINGS   = 'upshift_settings';
	const OPTION_FLAGS      = 'upshift_recorder_flags';
	const STEP_HOOK         = 'upshift_rollup_step';
	const COMPACT_HOOK      = 'upshift_compact';
	const COMPACT_STEP_HOOK = 'upshift_compact_step';
	const STORAGE_HOOK      = 'upshift_storage';
	const SCAN_STEP_HOOK    = 'upshift_scan_step';
	const OPTION_NOTICE     = 'upshift_show_notice';

	/**
	 * Short table names (without `{prefix}upshift_`).
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array( 'filesets', 'fileset_hits', 'usage', 'scans', 'scan_files', 'findings', 'usage_next', 'rollup_state', 'files', 'file_cache', 'errors', 'usage_days' );
	}

	/**
	 * Full table name.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'upshift_' . $name;
	}

	/**
	 * Activation: tables, default settings, daily cron. Usage is never enabled here.
	 */
	public static function activate() {
		self::maybe_upgrade();

		if ( false === get_option( self::OPTION_SETTINGS ) ) {
			add_option( self::OPTION_SETTINGS, Settings::defaults(), '', 'yes' );
		}

		self::ensure_schedules();
		// One notice on the next admin page, pointing to Tools > PHP Upgrade (autoloaded, so checking it costs no query).
		update_option( self::OPTION_NOTICE, '1', 'yes' );
	}

	/**
	 * Schedules every recurring event that is missing (DECISIONS D55, D63). Also called after updates and from admin pages,
	 * because an update by file replacement doesn't run the activation hook.
	 */
	public static function ensure_schedules() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::COMPACT_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::COMPACT_HOOK );
		}
		if ( ! wp_next_scheduled( Usage\Storage::HOOK ) ) {
			wp_schedule_event( Usage\Storage::next_quiet_time( time() ), 'daily', Usage\Storage::HOOK );
		}
	}

	/**
	 * Deactivation: unschedule cron, keep data. A network deactivation does this on every site (DECISIONS D64).
	 *
	 * @param bool $network_wide True when deactivated for the whole network.
	 */
	public static function deactivate( $network_wide = false ) {
		self::each_site( array( __CLASS__, 'deactivate_site' ), is_multisite() && $network_wide );
	}

	/**
	 * Runs a callback on the current site, or on every site of the network.
	 *
	 * @param callable $callback  Callback without arguments.
	 * @param bool     $all_sites Every site of the network (multisite only).
	 */
	private static function each_site( $callback, $all_sites ) {
		if ( ! $all_sites ) {
			call_user_func( $callback );
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			call_user_func( $callback );
			restore_current_blog();
		}
	}

	/**
	 * Unschedules this site's events.
	 */
	public static function deactivate_site() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::STEP_HOOK );
		wp_clear_scheduled_hook( self::COMPACT_HOOK );
		wp_clear_scheduled_hook( self::COMPACT_STEP_HOOK );
		wp_clear_scheduled_hook( self::STORAGE_HOOK );
		// Every scan id (WP 4.9+): a scan left running resumes from the Scan tab.
		wp_unschedule_hook( self::SCAN_STEP_HOOK );
	}

	/**
	 * Creates/updates tables with dbDelta and stores the DB version, under a short lock so two requests can't run
	 * it at once (DECISIONS D51).
	 *
	 * @return bool Whether the DB version is current afterwards.
	 */
	public static function maybe_upgrade() {
		global $wpdb;

		// Option row "{expires}", SQL only: INSERT IGNORE is atomic; an expired lock is deleted by compare-and-swap.
		$lock = 'upshift_upgrade_lock';
		$sql  = "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')";
		if ( 1 !== $wpdb->query( $wpdb->prepare( $sql, $lock, (string) ( time() + 120 ) ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed query string.
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock row.
			if ( ! is_string( $current ) || (int) $current > time()
				|| 1 !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, $current ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock row.
				|| 1 !== $wpdb->query( $wpdb->prepare( $sql, $lock, (string) ( time() + 120 ) ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed query string.
			) {
				return false;
			}
		}
		try {
			// Another request may have finished the upgrade while we waited for the lock: read the version with SQL.
			$version = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_DB_VERSION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the option cache.
			if ( UPSHIFT_DB_VERSION === $version ) {
				wp_cache_delete( 'alloptions', 'options' );
				return true;
			}
			return self::upgrade();
		} finally {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock row.
		}
	}

	/**
	 * Body of maybe_upgrade(): dbDelta, then the version is stored only when every table exists.
	 *
	 * @return bool
	 */
	private static function upgrade() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'upshift_';

		$sql = array();

		$sql[] = "CREATE TABLE {$p}filesets (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  set_hash char(32) NOT NULL,
  file_count int(10) unsigned NOT NULL DEFAULT 0,
  files longblob NOT NULL,
  is_compressed tinyint(1) NOT NULL DEFAULT 0,
  format tinyint(1) NOT NULL DEFAULT 0,
  created datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY set_hash (set_hash),
  KEY format (format)
) {$collate};";

		// Every file the usage map has seen; filesets refer to these ids (DECISIONS D55).
		$sql[] = "CREATE TABLE {$p}files (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  path_hash char(32) NOT NULL,
  path_key varchar(1024) NOT NULL,
  component varchar(191) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash)
) {$collate};";

		$sql[] = "CREATE TABLE {$p}fileset_hits (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  set_hash char(32) NOT NULL,
  day date NOT NULL,
  context int(10) unsigned NOT NULL DEFAULT 0,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  prep_ms_total double NOT NULL DEFAULT 0,
  prep_ms_max double NOT NULL DEFAULT 0,
  slow_hits bigint(20) unsigned NOT NULL DEFAULT 0,
  req_hits bigint(20) unsigned NOT NULL DEFAULT 0,
  req_ms_total double NOT NULL DEFAULT 0,
  ovh_0 bigint(20) unsigned NOT NULL DEFAULT 0,
  ovh_1 bigint(20) unsigned NOT NULL DEFAULT 0,
  ovh_2 bigint(20) unsigned NOT NULL DEFAULT 0,
  ovh_3 bigint(20) unsigned NOT NULL DEFAULT 0,
  ovh_4 bigint(20) unsigned NOT NULL DEFAULT 0,
  ovh_5 bigint(20) unsigned NOT NULL DEFAULT 0,
  ovh_6 bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY set_day_ctx (set_hash,day,context),
  KEY day (day)
) {$collate};";

		$sql[] = "CREATE TABLE {$p}usage (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path_hash char(32) NOT NULL,
  path_key varchar(1024) NOT NULL,
  component varchar(191) NOT NULL,
  contexts int(10) unsigned NOT NULL DEFAULT 0,
  hits_total bigint(20) unsigned NOT NULL DEFAULT 0,
  hits_by_context longtext NOT NULL,
  max_share double NOT NULL DEFAULT 0,
  first_seen date NOT NULL,
  last_seen date NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY component (component)
) {$collate};";

		$sql[] = "CREATE TABLE {$p}scans (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL,
  from_version varchar(10) NOT NULL,
  target_version varchar(10) NOT NULL,
  files_total int(10) unsigned NOT NULL DEFAULT 0,
  files_done int(10) unsigned NOT NULL DEFAULT 0,
  batches int(10) unsigned NOT NULL DEFAULT 0,
  peak_memory bigint(20) unsigned NOT NULL DEFAULT 0,
  engine_seconds double NOT NULL DEFAULT 0,
  baseline_seconds double NOT NULL DEFAULT -1,
  files_reused int(10) unsigned NOT NULL DEFAULT 0,
  memory_limit bigint(20) unsigned NOT NULL DEFAULT 0,
  memory_ini varchar(20) NOT NULL DEFAULT '',
  sapi varchar(30) NOT NULL DEFAULT '',
  started datetime NOT NULL,
  finished datetime NULL,
  error text NULL,
  PRIMARY KEY  (id)
) {$collate};";

		$sql[] = "CREATE TABLE {$p}scan_files (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scan_id bigint(20) unsigned NOT NULL,
  path_hash char(32) NOT NULL,
  path_key varchar(1024) NOT NULL,
  component varchar(191) NOT NULL,
  component_active tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL,
  note text NULL,
  PRIMARY KEY  (id),
  KEY scan_status (scan_id,status)
) {$collate};";

		$sql[] = "CREATE TABLE {$p}findings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scan_id bigint(20) unsigned NOT NULL,
  path_hash char(32) NOT NULL,
  path_key varchar(1024) NOT NULL,
  component varchar(191) NOT NULL,
  line int(10) unsigned NOT NULL DEFAULT 0,
  severity varchar(10) NOT NULL,
  category varchar(30) NOT NULL,
  rule varchar(255) NOT NULL,
  message text NOT NULL,
  baseline tinyint(1) NOT NULL DEFAULT 0,
  guarded tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY scan_path (scan_id,path_hash),
  KEY scan_component (scan_id,component)
) {$collate};";

		// Results of files scanned before, for unchanged files in later scans (DECISIONS D57). Binary hashes; findings is NULL
		// for a file without any.
		$sql[] = "CREATE TABLE {$p}file_cache (
  path_hash binary(16) NOT NULL,
  range_key varchar(12) NOT NULL,
  content_hash binary(16) NOT NULL,
  rules binary(16) NOT NULL,
  size int(10) unsigned NOT NULL,
  mtime int(10) unsigned NOT NULL,
  last_used int(10) unsigned NOT NULL,
  findings blob NULL,
  PRIMARY KEY  (path_hash,range_key)
) {$collate};";

		// Upgrade watch (DECISIONS D59): one row per problem, day and PHP version. message_hash covers the component and level.
		$sql[] = "CREATE TABLE {$p}errors (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  php_version varchar(7) NOT NULL,
  component varchar(191) NOT NULL,
  level varchar(11) NOT NULL,
  message_hash char(32) NOT NULL,
  hits bigint(20) unsigned NOT NULL DEFAULT 0,
  context int(10) unsigned NOT NULL DEFAULT 0,
  sample varchar(300) NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY day_php_hash (day,php_version,message_hash)
) {$collate};";

		// One finished day of usage per row group (DECISIONS D63): per-file counters, so the day's sets can go.
		$sql[] = "CREATE TABLE {$p}usage_days (
  day date NOT NULL,
  seq int(10) unsigned NOT NULL DEFAULT 0,
  data longblob NOT NULL,
  PRIMARY KEY  (day,seq)
) {$collate};";

		// Rollup staging (DECISIONS D48): new usage rows, tagged with the rollup job id, before the swap.
		$sql[] = "CREATE TABLE {$p}usage_next (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job char(32) NOT NULL,
  path_hash char(32) NOT NULL,
  path_key varchar(1024) NOT NULL,
  component varchar(191) NOT NULL,
  contexts int(10) unsigned NOT NULL DEFAULT 0,
  hits_total bigint(20) unsigned NOT NULL DEFAULT 0,
  hits_by_context longtext NOT NULL,
  max_share double NOT NULL DEFAULT 0,
  first_seen date NOT NULL,
  last_seen date NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY job_path (job,path_hash)
) {$collate};";

		// Rollup per-file totals between steps (JSON, gzcompressed, in chunks), keyed by a token per save.
		$sql[] = "CREATE TABLE {$p}rollup_state (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token char(32) NOT NULL,
  seq int(10) unsigned NOT NULL DEFAULT 0,
  data longblob NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_seq (token,seq)
) {$collate};";

		dbDelta( $sql );

		// A CREATE that failed (privileges, disk) must not mark the upgrade done; the next admin request retries.
		foreach ( self::tables() as $name ) {
			$table = self::table( $name );
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
				Usage\Recorder::set_error( 'install: table ' . $table . ' is missing after the upgrade; it is retried on the next admin page' );
				return false;
			}
		}
		// Columns added in DB v4 (DECISIONS D52): an ALTER that failed must not mark the upgrade done either.
		$columns = array(
			'scans'    => array( 'baseline_seconds', 'files_reused', 'memory_limit', 'memory_ini', 'sapi' ),
			'findings' => array( 'baseline', 'guarded' ),
			'filesets' => array( 'format' ),
		);
		foreach ( $columns as $name => $list ) {
			$table = self::table( $name );
			foreach ( $list as $column ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check, table name from $wpdb->prefix.
				if ( null === $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) ) ) {
					Usage\Recorder::set_error( 'install: column ' . $table . '.' . $column . ' is missing after the upgrade; it is retried on the next admin page' );
					return false;
				}
			}
		}

		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) < 5 ) {
			self::prepare_v5();
		}
		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) < 8 ) {
			self::prepare_v8();
		}
		self::ensure_schedules();
		if ( self::recategorize() > 0 ) {
			Report\Status::refresh_after_remap();
		}

		update_option( self::OPTION_DB_VERSION, UPSHIFT_DB_VERSION, 'yes' );
		return true;
	}

	/**
	 * Upgrade to DB v5 (DECISIONS D55): a rollup job in flight has saved totals in the old shape, so it starts over; the
	 * fileset cap no longer exists. Stored filesets stay and are converted by the rollup.
	 */
	private static function prepare_v5() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- job row is SQL only.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'upshift_rollup_job' ) );
		foreach ( array( 'usage_next', 'rollup_state' ) as $name ) {
			$table = self::table( $name );
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from $wpdb->prefix.
		}
		$flags = get_option( self::OPTION_FLAGS, array() );
		if ( is_array( $flags ) && isset( $flags['fileset_cap_reached'] ) ) {
			unset( $flags['fileset_cap_reached'] );
			update_option( self::OPTION_FLAGS, $flags, false );
		}
		delete_transient( 'upshift_fileset_count' );
	}

	/**
	 * Upgrade to DB v8 (DECISIONS D57): a scan in flight was started by older code, so it ends here with a one-line
	 * reason, shown on the Scan tab and by an open page at its next batch. The previous finished scan and its findings
	 * stay. The scan lock is left to expire, so a batch still running on the old code isn't disturbed.
	 */
	private static function prepare_v8() {
		global $wpdb;

		$table = self::table( 'scans' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'failed', finished = %s, error = %s WHERE status IN ('queued','running')", current_time( 'mysql' ), __( 'Upshift was updated while this scan was running. Please start a new scan.', 'upshift' ) ) );
	}

	/**
	 * Re-maps stored findings to the current category rules (Scan\Categories), one UPDATE per changed rule
	 * code, so an upgrade that changes the mapping (DECISIONS D37) also fixes the stored scan.
	 *
	 * @return int Rule codes changed.
	 */
	public static function recategorize() {
		global $wpdb;

		$table   = self::table( 'findings' );
		$changed = 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name from $wpdb->prefix.
		foreach ( (array) $wpdb->get_results( "SELECT DISTINCT rule, category FROM {$table}", ARRAY_A ) as $row ) {
			$category = Scan\Categories::of( $row['rule'] );
			if ( $category !== $row['category'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own table.
				$wpdb->update( $table, array( 'category' => $category ), array( 'rule' => $row['rule'] ), array( '%s' ), array( '%s' ) );
				++$changed;
			}
		}
		return $changed;
	}

	/**
	 * Uninstall: on multisite every site of the network has its own tables and options (DECISIONS D64).
	 */
	public static function uninstall() {
		self::each_site( array( __CLASS__, 'uninstall_site' ), is_multisite() );
	}

	/**
	 * Removes this site's tables, options, transients and cron events.
	 */
	public static function uninstall_site() {
		global $wpdb;

		delete_option( self::OPTION_NOTICE );
		// "Delete all data when Upshift is uninstalled" is on unless the owner turned it off. The scheduled events always go.
		$settings = get_option( self::OPTION_SETTINGS );
		if ( is_array( $settings ) && isset( $settings['delete_on_uninstall'] ) && ! $settings['delete_on_uninstall'] ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_clear_scheduled_hook( self::STEP_HOOK );
			wp_clear_scheduled_hook( self::COMPACT_HOOK );
			wp_clear_scheduled_hook( self::COMPACT_STEP_HOOK );
			wp_clear_scheduled_hook( self::STORAGE_HOOK );
			wp_unschedule_hook( self::SCAN_STEP_HOOK );
			return;
		}

		foreach ( self::tables() as $name ) {
			$table = self::table( $name );
			// Table name is built from $wpdb->prefix and a fixed list; identifiers can't be prepared on WP 5.8.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		delete_option( self::OPTION_SETTINGS );
		delete_option( self::OPTION_DB_VERSION );
		delete_option( self::OPTION_FLAGS );
		delete_option( 'upshift_scan_stats' );
		delete_option( 'upshift_scan_lock' );
		delete_option( 'upshift_bench_result' );
		delete_option( 'upshift_rollup_meta' );
		delete_option( 'upshift_rollup_job' );
		delete_option( 'upshift_rollup_lock' );
		delete_option( 'upshift_rollup_stopped' );
		delete_option( 'upshift_upgrade_lock' );
		delete_option( 'upshift_scan_page_until' );
		delete_option( 'upshift_check_summary' );
		delete_option( 'upshift_watch_state' );
		delete_option( 'upshift_watch_mail_day' );
		delete_option( 'upshift_compact_last' );
		delete_option( 'upshift_storage_state' );

		// delete_transient() also reaches a persistent object cache, where transients never touch
		// wp_options. Literal names: this method can't load other classes. The LIKE below is a sweep.
		foreach ( array( 'upshift_rollup_meta', 'upshift_rollup_lock', 'upshift_fileset_count', 'upshift_bench_result', 'upshift_scan_lock' ) as $transient ) {
			delete_transient( $transient );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk delete of our own transients.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_upshift_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_upshift_' ) . '%'
			)
		);
		wp_cache_delete( 'alloptions', 'options' );

		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::STEP_HOOK );
		wp_clear_scheduled_hook( self::COMPACT_HOOK );
		wp_clear_scheduled_hook( self::COMPACT_STEP_HOOK );
		wp_clear_scheduled_hook( self::STORAGE_HOOK );
		wp_unschedule_hook( self::SCAN_STEP_HOOK );
	}
}
