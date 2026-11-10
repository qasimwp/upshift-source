<?php
/**
 * Plugin status, the site answer and the cached summary (docs/UI-v1.md §1, §3; DECISIONS D58).
 *
 * @package Upshift
 */

namespace Upshift\Report;

use Upshift\Install;
use Upshift\Scan\Categories;
use Upshift\Scan\Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * One source of truth for "how does this plugin do", shared by the Check tab, the Plugins screen and Site Health.
 * Usage data is never an input here, so it can't change a status. Never loads the engine.
 */
class Status {

	const OPTION = 'upshift_check_summary';

	const BREAKS = 'break';
	const HOST   = 'host';
	const LATER  = 'later';
	const READY  = 'ready';

	/**
	 * Statuses, worst first.
	 */
	const ORDER = array( self::BREAKS, self::HOST, self::LATER, self::READY );

	/**
	 * Status of one counted problem (DECISIONS D58: a changed warning only behaves differently, so it is not "will break").
	 *
	 * @param string $category Category.
	 * @param string $severity error or warning.
	 * @return string|null Status, null when the category is not an upgrade problem.
	 */
	public static function of( $category, $severity ) {
		switch ( $category ) {
			case Categories::REMOVED:
				return self::BREAKS;
			case Categories::CHANGED:
				return 'error' === $severity ? self::BREAKS : self::LATER;
			case Categories::NEEDS_EXTENSION:
				return self::HOST;
			case Categories::DEPRECATED:
				return self::LATER;
			default:
				return null;
		}
	}

	/**
	 * Position in ORDER (0 = worst).
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function rank( $status ) {
		$rank = array_search( $status, self::ORDER, true );
		return false === $rank ? count( self::ORDER ) : (int) $rank;
	}

	/**
	 * The word shown for a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function label( $status ) {
		switch ( $status ) {
			case self::BREAKS:
				return __( 'Will break', 'upshift' );
			case self::HOST:
				return __( 'Ask your host', 'upshift' );
			case self::LATER:
				return __( 'Works, fix later', 'upshift' );
			default:
				return __( 'Ready', 'upshift' );
		}
	}

	/**
	 * Dashicon of a status (the word is always shown next to it).
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function icon( $status ) {
		switch ( $status ) {
			case self::BREAKS:
				return 'dashicons-dismiss';
			case self::HOST:
				return 'dashicons-format-chat';
			case self::LATER:
				return 'dashicons-info-outline';
			default:
				return 'dashicons-yes-alt';
		}
	}

	/**
	 * The cached summary, or null when there is none.
	 *
	 * @return array|null
	 */
	public static function summary() {
		$summary = get_option( self::OPTION );
		return is_array( $summary ) && isset( $summary['scan_id'], $summary['components'] ) ? $summary : null;
	}

	/**
	 * The summary for a finished scan. The Check tab calls this: if the stored one belongs to another scan (or to none,
	 * as right after an update from an older build), it is rebuilt here.
	 *
	 * @param array $scan Scan row.
	 * @return array|null
	 */
	public static function for_scan( array $scan ) {
		$summary = self::summary();
		if ( null !== $summary && (int) $summary['scan_id'] === (int) $scan['id'] ) {
			return $summary;
		}
		return self::refresh( (int) $scan['id'] );
	}

	/**
	 * Writes the summary of a finished scan. Runs once per check, so the Plugins screen and Site Health need no query
	 * on the findings table.
	 *
	 * @param int  $scan_id       Scan id.
	 * @param bool $keep_snapshot Keep the stored site snapshot (a category re-map says nothing about the site changing).
	 * @return array|null The summary, or null for an unknown scan.
	 */
	public static function refresh( $scan_id, $keep_snapshot = false ) {
		global $wpdb;

		$scan = Runner::get( (int) $scan_id );
		if ( null === $scan ) {
			return null;
		}
		$files    = Install::table( 'scan_files' );
		$findings = Install::table( 'findings' );

		$components = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT component, MAX(component_active) AS active FROM {$files} WHERE scan_id = %d GROUP BY component", (int) $scan_id ), ARRAY_A ) as $row ) {
			$components[ $row['component'] ] = array(
				'status' => self::READY,
				'active' => (bool) $row['active'],
			);
		}
		// baseline and guarded rows are not upgrade problems (DECISIONS D52, D56).
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT component, category, severity FROM {$findings} WHERE scan_id = %d AND baseline = 0 AND guarded = 0 GROUP BY component, category, severity", (int) $scan_id ), ARRAY_A ) as $row ) {
			$status = self::of( $row['category'], $row['severity'] );
			if ( null !== $status && isset( $components[ $row['component'] ] ) && self::rank( $status ) < self::rank( $components[ $row['component'] ]['status'] ) ) {
				$components[ $row['component'] ]['status'] = $status;
			}
		}

		$old     = self::summary();
		$summary = array(
			'scan_id'    => (int) $scan['id'],
			'from'       => (string) $scan['from_version'],
			'target'     => (string) $scan['target_version'],
			'finished'   => (string) $scan['finished'],
			'components' => $components,
			'snapshot'   => $keep_snapshot && null !== $old && isset( $old['snapshot'] ) ? $old['snapshot'] : self::snapshot(),
		);
		update_option( self::OPTION, $summary, false );
		return $summary;
	}

	/**
	 * Refreshes the summary after stored categories were re-mapped by a DB upgrade.
	 */
	public static function refresh_after_remap() {
		$scan = Runner::latest( 'done' );
		if ( null !== $scan ) {
			self::refresh( (int) $scan['id'], true );
		}
	}

	/**
	 * What is installed and active right now, Upshift itself excluded.
	 *
	 * @return array plugins (id => version), themes (stylesheet => version), active (sorted list).
	 */
	public static function snapshot() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$own     = plugin_basename( UPSHIFT_FILE );
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( $own !== $file ) {
				$plugins[ $file ] = isset( $data['Version'] ) ? (string) $data['Version'] : '';
			}
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$plugins[ 'mu:' . $file ] = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}
		foreach ( array_keys( get_dropins() ) as $file ) {
			$plugins[ 'dropin:' . $file ] = '';
		}
		ksort( $plugins );

		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$themes[ $stylesheet ] = (string) $theme->get( 'Version' );
		}
		ksort( $themes );

		$active = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		$active = array_values( array_unique( array_diff( $active, array( $own ) ) ) );
		sort( $active );
		$active[] = 'theme:' . get_stylesheet();
		$active[] = 'theme:' . get_template();

		return array(
			'plugins' => $plugins,
			'themes'  => $themes,
			'active'  => array_values( array_unique( $active ) ),
		);
	}

	/**
	 * Whether plugins or themes were updated, activated, deactivated, added or removed since the summary was written.
	 *
	 * @param array $summary Summary.
	 * @return bool
	 */
	public static function site_changed( array $summary ) {
		return isset( $summary['snapshot'] ) && self::snapshot() !== $summary['snapshot'];
	}

	/**
	 * Counts of active plugins and themes per status; plugins and themes separately for the answer sentence.
	 *
	 * @param array $summary Summary.
	 * @return array status => array( 'all', 'plugin', 'theme' ).
	 */
	public static function counts( array $summary ) {
		$counts = array_fill_keys(
			self::ORDER,
			array(
				'all'    => 0,
				'plugin' => 0,
				'theme'  => 0,
			)
		);
		foreach ( $summary['components'] as $id => $component ) {
			if ( empty( $component['active'] ) || ! isset( $counts[ $component['status'] ] ) ) {
				continue;
			}
			$kind = 0 === strpos( (string) $id, 'theme:' ) ? 'theme' : 'plugin';
			++$counts[ $component['status'] ]['all'];
			++$counts[ $component['status'] ][ $kind ];
		}
		return $counts;
	}

	/**
	 * The one answer for the site, by the worst status among active plugins and themes (DECISIONS D58).
	 *
	 * @param array $summary Summary.
	 * @return array level (green, yellow, orange, red), sentence, status (the worst one).
	 */
	public static function answer( array $summary ) {
		$counts = self::counts( $summary );
		$target = (string) $summary['target'];

		if ( $counts[ self::BREAKS ]['all'] > 0 ) {
			return array(
				'level'    => 'red',
				'status'   => self::BREAKS,
				'sentence' => self::red_sentence( $counts[ self::BREAKS ], $target ),
			);
		}
		if ( $counts[ self::HOST ]['all'] > 0 ) {
			return array(
				'level'    => 'orange',
				'status'   => self::HOST,
				'sentence' => __( 'Ask your host one question before you upgrade.', 'upshift' ),
			);
		}
		if ( $counts[ self::LATER ]['all'] > 0 ) {
			return array(
				'level'    => 'yellow',
				'status'   => self::LATER,
				'sentence' => self::yellow_sentence( $counts[ self::LATER ], $target ),
			);
		}
		return array(
			'level'    => 'green',
			'status'   => self::READY,
			/* translators: %s: target PHP version. */
			'sentence' => sprintf( __( 'Your site looks ready for PHP %s.', 'upshift' ), $target ),
		);
	}

	/**
	 * Red answer. Whole sentences per kind, so translators never join words.
	 *
	 * @param array  $count  all, plugin, theme.
	 * @param string $target Target version.
	 * @return string
	 */
	private static function red_sentence( array $count, $target ) {
		$n      = (int) $count['all'];
		$number = number_format_i18n( $n );
		if ( 0 === (int) $count['theme'] ) {
			/* translators: 1: number of plugins, 2: target PHP version. */
			return sprintf( _n( '%1$s plugin will likely break on PHP %2$s. Fix it before you upgrade.', '%1$s plugins will likely break on PHP %2$s. Fix them before you upgrade.', $n, 'upshift' ), $number, $target );
		}
		if ( 0 === (int) $count['plugin'] ) {
			/* translators: 1: number of themes, 2: target PHP version. */
			return sprintf( _n( '%1$s theme will likely break on PHP %2$s. Fix it before you upgrade.', '%1$s themes will likely break on PHP %2$s. Fix them before you upgrade.', $n, 'upshift' ), $number, $target );
		}
		/* translators: 1: number of plugins and themes together, 2: target PHP version. */
		return sprintf( _n( '%1$s plugin or theme will likely break on PHP %2$s. Fix it before you upgrade.', '%1$s plugins and themes will likely break on PHP %2$s. Fix them before you upgrade.', $n, 'upshift' ), $number, $target );
	}

	/**
	 * Yellow answer. Whole sentences per kind, so translators never join words.
	 *
	 * @param array  $count  all, plugin, theme.
	 * @param string $target Target version.
	 * @return string
	 */
	private static function yellow_sentence( array $count, $target ) {
		$n      = (int) $count['all'];
		$number = number_format_i18n( $n );
		if ( 0 === (int) $count['theme'] ) {
			/* translators: 1: target PHP version, 2: number of plugins. */
			return sprintf( _n( 'Your site should work on PHP %1$s. %2$s plugin shows warnings you can fix later.', 'Your site should work on PHP %1$s. %2$s plugins show warnings you can fix later.', $n, 'upshift' ), $target, $number );
		}
		if ( 0 === (int) $count['plugin'] ) {
			/* translators: 1: target PHP version, 2: number of themes. */
			return sprintf( _n( 'Your site should work on PHP %1$s. %2$s theme shows warnings you can fix later.', 'Your site should work on PHP %1$s. %2$s themes show warnings you can fix later.', $n, 'upshift' ), $target, $number );
		}
		/* translators: 1: target PHP version, 2: number of plugins and themes together. */
		return sprintf( _n( 'Your site should work on PHP %1$s. %2$s plugin or theme shows warnings you can fix later.', 'Your site should work on PHP %1$s. %2$s plugins and themes show warnings you can fix later.', $n, 'upshift' ), $target, $number );
	}
}
