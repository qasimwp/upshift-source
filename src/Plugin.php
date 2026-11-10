<?php
/**
 * Wires hooks and decides what loads per request (spec §5.3).
 *
 * @package Upshift
 */

namespace Upshift;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap.
 */
class Plugin {

	/**
	 * Registers hooks. Loads nothing beyond this class and Settings on normal requests.
	 */
	public static function boot() {
		register_activation_hook( UPSHIFT_FILE, array( __NAMESPACE__ . '\\Install', 'activate' ) );
		register_deactivation_hook( UPSHIFT_FILE, array( __NAMESPACE__ . '\\Install', 'deactivate' ) );

		// Literal hook name (= Install::CRON_HOOK) so Install isn't loaded on every request (§5.3).
		add_action( 'upshift_daily', array( __NAMESPACE__ . '\\Usage\\Rollup', 'daily' ) );
		// Single event: one rollup step, re-scheduled until the job is done (= Rollup::STEP_HOOK, DECISIONS D48).
		add_action( 'upshift_rollup_step', array( __NAMESPACE__ . '\\Usage\\Rollup', 'cron_step' ) );
		// Hourly, and again while a backlog remains: new file lists become dictionary ids (DECISIONS D55).
		add_action( 'upshift_compact', array( __NAMESPACE__ . '\\Usage\\Rollup', 'compact' ) );
		add_action( 'upshift_compact_step', array( __NAMESPACE__ . '\\Usage\\Rollup', 'compact' ) );
		// Nightly, in the site's quiet hour: the size cap and table rebuild (DECISIONS D63).
		add_action( 'upshift_storage', array( __NAMESPACE__ . '\\Usage\\Storage', 'nightly' ) );
		// Single event per scan id: one batch of a running scan when no page is driving it (= Runner::STEP_HOOK, DECISIONS D57).
		add_action( 'upshift_scan_step', array( __NAMESPACE__ . '\\Scan\\Runner', 'cron_step' ) );

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
			add_action( 'upgrader_process_complete', array( __NAMESPACE__ . '\\Install', 'ensure_schedules' ), 10, 0 );
			add_action( 'admin_menu', array( __NAMESPACE__ . '\\Admin\\Page', 'register' ) );
			// The Developer tab's handlers exist only when UPSHIFT_DEV is defined (T12.3, DECISIONS D62).
			if ( Admin\Page::is_dev() ) {
				add_action( 'admin_post_upshift_save_usage', array( __NAMESPACE__ . '\\Admin\\Page', 'handle_save_usage' ) );
				add_action( 'admin_post_upshift_reset_usage', array( __NAMESPACE__ . '\\Admin\\Page', 'handle_reset_usage' ) );
				add_action( 'admin_post_upshift_bench', array( __NAMESPACE__ . '\\Admin\\Page', 'handle_bench' ) );
				add_action( 'admin_post_upshift_export_csv', array( __NAMESPACE__ . '\\Admin\\Report', 'handle_export' ) );
			}
			add_action( 'admin_post_upshift_download_report', array( __NAMESPACE__ . '\\Admin\\CheckTab', 'handle_export' ) );
			add_action( 'admin_post_upshift_activity_on', array( __NAMESPACE__ . '\\Admin\\CheckTab', 'handle_activity_on' ) );
			add_action( 'admin_post_upshift_save_settings', array( __NAMESPACE__ . '\\Admin\\SettingsTab', 'handle_save' ) );
			add_action( 'admin_post_upshift_delete_data', array( __NAMESPACE__ . '\\Admin\\SettingsTab', 'handle_delete' ) );
			// The Plugins screen badge and the Site Health test (they read the small cached summary only).
			add_filter( 'plugin_action_links_' . plugin_basename( UPSHIFT_FILE ), array( __NAMESPACE__ . '\\Admin\\Integrations', 'action_links' ) );
			add_filter( 'plugin_row_meta', array( __NAMESPACE__ . '\\Admin\\Integrations', 'row_meta' ), 10, 2 );
			add_filter( 'site_status_tests', array( __NAMESPACE__ . '\\Admin\\Integrations', 'site_health_tests' ) );
			// One notice after activation (the flag is autoloaded, so this costs no query).
			if ( get_option( 'upshift_show_notice' ) ) {
				add_action( 'admin_notices', array( __NAMESPACE__ . '\\Admin\\Integrations', 'activation_notice' ) );
			}
			add_action( 'admin_enqueue_scripts', array( __NAMESPACE__ . '\\Admin\\Page', 'enqueue' ) );
			foreach ( array( 'start', 'batch', 'cancel', 'status' ) as $action ) {
				add_action( 'wp_ajax_upshift_scan_' . $action, array( __NAMESPACE__ . '\\Admin\\Ajax', $action ) );
			}
			add_action( 'wp_ajax_upshift_rollup_step', array( __NAMESPACE__ . '\\Admin\\Ajax', 'rollup_step' ) );
			add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// WP-CLI never fires admin_init, so a plugin updated by file replacement upgrades here too.
			add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
			\WP_CLI::add_command( 'upshift', __NAMESPACE__ . '\\Cli\\Command' );
			// Dev-only loopback command; its file is not in the release zip (DECISIONS D64).
			if ( is_file( UPSHIFT_DIR . '/src/Cli/DevCommand.php' ) ) {
				\WP_CLI::add_command( 'upshift simulate-traffic', __NAMESPACE__ . '\\Cli\\DevCommand' );
			}
		}

		self::maybe_watch();
		self::maybe_sample();
	}

	/**
	 * Upgrade watch wiring (DECISIONS D59). Off = no hook, no handler and no Watch class on any normal request; only a cron
	 * request with leftover data gets the callback that keeps the 30-day retention running.
	 */
	private static function maybe_watch() {
		$settings = Settings::get();
		$cron     = wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );

		if ( empty( $settings['upgrade_watch'] ) || ( defined( 'UPSHIFT_DISABLE_WATCH' ) && UPSHIFT_DISABLE_WATCH ) ) {
			if ( $cron && false !== get_option( 'upshift_watch_state', false ) ) {
				add_action( 'upshift_daily', array( __NAMESPACE__ . '\\Watch\\Collector', 'purge' ) );
			}
			return;
		}

		if ( $cron ) {
			add_action( 'upshift_daily', array( __NAMESPACE__ . '\\Watch\\Collector', 'purge' ) );
			add_action( 'upshift_daily', array( __NAMESPACE__ . '\\Watch\\State', 'move_first' ) );
		}
		if ( is_admin() ) {
			// WordPress sorts active_plugins every time a plugin is activated.
			add_action( 'activated_plugin', array( __NAMESPACE__ . '\\Watch\\State', 'move_first' ), 10, 0 );
			add_action( 'admin_post_upshift_watch_start', array( __NAMESPACE__ . '\\Admin\\WatchTab', 'handle_start' ) );
		}

		if ( 'cli' === PHP_SAPI // WP-CLI, and a system cron whose PHP can differ from the web's.
			|| ( defined( 'UPSHIFT_SCANNING' ) && UPSHIFT_SCANNING )
			|| (int) $settings['watch_paused_until'] > time()
			|| UPSHIFT_DB_VERSION !== get_option( 'upshift_db_version' )
			|| wp_installing()
			|| self::is_scan_ajax()
		) {
			return;
		}

		Watch\Collector::start( $settings );
	}

	/**
	 * Sampling decision (spec §7.2). Not sampled = one random_int() call and nothing else.
	 */
	private static function maybe_sample() {
		$settings = Settings::get();
		// Files updated but tables not yet: ask cron for the upgrade (never in this request), so recording comes back without an admin visit.
		if ( UPSHIFT_DB_VERSION !== get_option( 'upshift_db_version' ) && ! wp_installing() && ! wp_next_scheduled( 'upshift_compact_step' ) ) {
			wp_schedule_single_event( time(), 'upshift_compact_step' );
		}
		if ( empty( $settings['usage_enabled'] )
			|| ( defined( 'UPSHIFT_DISABLE_USAGE' ) && UPSHIFT_DISABLE_USAGE )
			|| ( defined( 'UPSHIFT_SCANNING' ) && UPSHIFT_SCANNING )
			|| (int) $settings['recorder_paused_until'] > time()
			// Files updated but tables not yet (upgrade runs on the next admin_init): the upsert would fail.
			|| UPSHIFT_DB_VERSION !== get_option( 'upshift_db_version' )
			|| wp_installing()
			|| self::is_scan_ajax()
			|| self::is_own_cli()
		) {
			return;
		}

		$rate = (float) $settings['sample_rate'];
		if ( $rate < 1.0 ) {
			// random_int(): not affected by another plugin's mt_srand( <fixed> ) and leaves the mt_rand() state
			// alone (DECISIONS D51). No randomness source = not sampled.
			try {
				if ( random_int( 0, PHP_INT_MAX ) / PHP_INT_MAX >= $rate ) {
					return;
				}
			} catch ( \Throwable $e ) {
				return;
			}
		}

		add_action( 'shutdown', array( new Usage\Recorder(), 'record' ), PHP_INT_MAX );
	}

	/**
	 * Our notice argument ("saved", "reset", "bench") is removed from the address bar after the redirect, so a reload or
	 * a bookmark doesn't show the notice again. The forms post to admin-post.php, so a reload never re-saves.
	 *
	 * @param string[] $args Removable query args.
	 * @return string[]
	 */
	public static function removable_query_args( $args ) {
		$args[] = 'upshift_msg';
		return $args;
	}

	/**
	 * Whether this is one of our own scan or rollup AJAX requests.
	 *
	 * @return bool
	 */
	private static function is_scan_ajax() {
		if ( ! wp_doing_ajax() || ! isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
			return false;
		}
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 0 === strpos( $action, 'upshift_scan_' ) || 0 === strpos( $action, 'upshift_rollup_' );
	}

	/**
	 * Whether this is a `wp upshift ...` command.
	 *
	 * @return bool
	 */
	private static function is_own_cli() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return false;
		}
		$args = array();
		if ( class_exists( '\WP_CLI' ) && method_exists( '\WP_CLI', 'get_runner' ) ) {
			// Runner::__get() exposes the parsed positional arguments.
			$args = (array) \WP_CLI::get_runner()->arguments;
		}
		if ( empty( $args ) && isset( $GLOBALS['argv'] ) ) {
			return in_array( 'upshift', (array) $GLOBALS['argv'], true );
		}
		return isset( $args[0] ) && 'upshift' === $args[0];
	}

	/**
	 * Runs the DB upgrade only when the stored version differs (keeps Install unloaded otherwise). Never in a
	 * visitor's admin-ajax.php request (front-end AJAX also fires admin_init; DECISIONS D51).
	 */
	public static function maybe_upgrade() {
		if ( wp_doing_ajax() && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( UPSHIFT_DB_VERSION !== get_option( 'upshift_db_version' ) ) {
			Install::maybe_upgrade();
		}
		// Literal hook names, as in boot(): events lost in any way come back on the next admin page (DECISIONS D63).
		if ( ! wp_next_scheduled( 'upshift_compact' ) || ! wp_next_scheduled( 'upshift_daily' ) || ! wp_next_scheduled( 'upshift_storage' ) ) {
			Install::ensure_schedules();
		}
	}
}
