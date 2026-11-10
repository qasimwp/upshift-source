<?php
/**
 * The Settings tab (docs/UI-v1.md §6).
 *
 * @package Upshift
 */

namespace Upshift\Admin;

use Upshift\Install;
use Upshift\Report\Status;
use Upshift\Scan\Runner;
use Upshift\Settings;
use Upshift\Usage\Rollup;
use Upshift\Usage\Storage;
use Upshift\Watch\State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Rendered from Page::render(), which checks the capability first. Every handler checks it again, then its nonce.
 */
class SettingsTab {

	const SAVE   = 'upshift_save_settings';
	const DELETE = 'upshift_delete_data';

	/**
	 * Notice after our own redirect.
	 */
	private static function render_notice() {
		// Read-only notice selector after our own redirect.
		$msg = isset( $_GET['upshift_msg'] ) ? sanitize_key( wp_unslash( $_GET['upshift_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'saved' === $msg ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'upshift' ) . '</p></div>';
		} elseif ( 'deleted' === $msg ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Smart results data, Upgrade guard data and your last check were deleted.', 'upshift' ) . '</p></div>';
		} elseif ( 'busy' === $msg ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A check is running. Stop it first, then delete the data.', 'upshift' ) . '</p></div>';
		}
	}

	/**
	 * One checkbox setting: the switch with its title, a sentence, and an optional note.
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked Whether it is on.
	 * @param string $title   Title.
	 * @param string $text    One sentence.
	 */
	private static function render_toggle( $name, $checked, $title, $text ) {
		echo '<div class="upshift-setting"><p class="upshift-setting__title"><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . ' /> <strong>' . esc_html( $title ) . '</strong></label></p>';
		echo '<p class="upshift-muted">' . esc_html( $text ) . '</p>';
		echo '</div>';
	}

	/**
	 * Whether the site has a shop, so a text may name checkout and shop.
	 *
	 * @return bool
	 */
	public static function has_shop() {
		return class_exists( 'WooCommerce', false );
	}

	/**
	 * The one muted line about privacy, under the two features and in the Upgrade guard box.
	 *
	 * @return string
	 */
	public static function privacy_line() {
		return __( "Upshift looks at your plugins' code, not at your visitors or customers. Nothing leaves your site except the crash email, if you turn it on.", 'upshift' );
	}

	/**
	 * The page-view choices: percent => label.
	 *
	 * @return array
	 */
	public static function share_options() {
		return array(
			100 => __( 'Every page view (small sites)', 'upshift' ),
			50  => __( 'Half', 'upshift' ),
			25  => __( '1 in 4 (busy sites)', 'upshift' ),
			10  => __( '1 in 10 (very busy sites)', 'upshift' ),
		);
	}

	/**
	 * The choice closest to a saved rate. A tie goes to the lighter choice (0.75 gives Half).
	 *
	 * @param float $rate Saved sample rate, 0.01 to 1.0.
	 * @return int Percent: 100, 50, 25 or 10.
	 */
	public static function nearest_share( $rate ) {
		$percent = 100 * (float) $rate;
		$best    = 25;
		$gap     = null;
		// Lightest first, so a tie keeps the lighter choice.
		foreach ( array( 10, 25, 50, 100 ) as $option ) {
			$distance = abs( $option - $percent );
			if ( null === $gap || $distance < $gap ) {
				$gap  = $distance;
				$best = $option;
			}
		}
		return $best;
	}

	/**
	 * Settings tab.
	 */
	public static function render() {
		$settings = Settings::get();
		self::render_notice();
		Integrations::render_recording_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE ) . '" /><input type="hidden" name="upshift_section" value="settings" />';
		wp_nonce_field( self::SAVE );
		echo '<div class="upshift-card upshift-settings">';
		self::render_toggle(
			'usage_enabled',
			! empty( $settings['usage_enabled'] ),
			__( 'Smart results (recommended)', 'upshift' ),
			self::has_shop()
				? __( 'Learns which plugins your site really uses (checkout, shop and admin), so your check shows which problems matter. Stays in your database and uses very few resources.', 'upshift' )
				: __( 'Learns which plugins your site really uses (your pages and admin), so your check shows which problems matter. Stays in your database and uses very few resources.', 'upshift' )
		);
		self::render_toggle(
			'upgrade_watch',
			! empty( $settings['upgrade_watch'] ),
			__( 'Upgrade guard (recommended)', 'upshift' ),
			__( 'Tells you if any plugin starts crashing after your host changes PHP. Turn it on now, so Upshift knows how your site behaves before the change.', 'upshift' )
		);
		echo '<p class="upshift-muted upshift-privacy">' . esc_html( self::privacy_line() ) . '</p>';
		self::render_toggle(
			'email_crashes',
			! empty( $settings['email_crashes'] ),
			__( 'Email me about new crashes', 'upshift' ),
			__( "Sends at most one email a day to your site's admin address if a plugin starts crashing after your host changes PHP. Needs Upgrade guard.", 'upshift' )
		);
		self::render_toggle(
			'delete_on_uninstall',
			! empty( $settings['delete_on_uninstall'] ),
			__( 'Delete all data when Upshift is uninstalled', 'upshift' ),
			__( 'Removes your check results, Smart results and Upgrade guard data. Turn this off to keep them.', 'upshift' )
		);

		echo '<details class="upshift-advanced"><summary>' . esc_html__( 'Advanced', 'upshift' ) . '</summary>';
		$chosen = self::nearest_share( (float) $settings['sample_rate'] );
		echo '<div class="upshift-setting"><p class="upshift-setting__title"><label for="upshift-share"><strong>' . esc_html__( 'How many page views to look at', 'upshift' ) . '</strong></label></p>';
		echo '<p><select id="upshift-share" name="share">';
		foreach ( self::share_options() as $percent => $label ) {
			echo '<option value="' . esc_attr( (string) $percent ) . '"' . selected( $chosen, $percent, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="upshift-muted">' . esc_html__( 'Looking at fewer page views keeps Upshift light on busy sites. Nothing leaves your site.', 'upshift' ) . '</p></div>';

		echo '<div class="upshift-setting"><p class="upshift-setting__title"><strong>' . esc_html__( 'Delete collected data', 'upshift' ) . '</strong></p>';
		echo '<p class="upshift-muted">' . esc_html( self::size_sentence() ) . '</p>';
		echo '<p class="upshift-muted">' . esc_html__( 'Upshift keeps its collected data under 50 MB. Your latest check results stay until you check again.', 'upshift' ) . '</p>';
		echo '<p><button type="button" class="button" id="upshift-delete-data" form="upshift-delete-form" data-confirm="' . esc_attr__( 'This deletes Smart results data, Upgrade guard data and your last check. Your settings stay.', 'upshift' ) . '">' . esc_html__( 'Delete collected data', 'upshift' ) . '</button></p></div>';
		echo '</details>';
		echo '</div>';
		submit_button( __( 'Save settings', 'upshift' ) );
		echo '</form>';

		// Outside the settings form (forms cannot be nested); the button above reaches it with its form attribute.
		echo '<form method="post" id="upshift-delete-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::DELETE ) . '" />';
		wp_nonce_field( self::DELETE, '_wpnonce_delete' );
		echo '</form>';
	}

	/**
	 * "Upshift uses N MB of your database", with "about" when it is an estimate.
	 *
	 * @return string
	 */
	private static function size_sentence() {
		global $wpdb;

		// Only Upshift's own tables and options, data + index; free space is left out (DECISIONS D63).
		$size = Storage::size();
		if ( null !== $size ) {
			/* translators: %s: size in MB. */
			$text = sprintf( __( 'Upshift uses %s MB of your database.', 'upshift' ), number_format_i18n( max( 0.1, $size['total'] / Storage::MB ), 1 ) );
			if ( $size['total'] > 20 * Storage::MB ) {
				$text .= ' ' . __( 'This is normal for a large site. Upshift removes collected data older than 30 days automatically.', 'upshift' );
			}
			return $text;
		}
		// information_schema is not readable here: about 150 bytes a row.
		$rows = 0;
		foreach ( Install::tables() as $name ) {
			$table = Install::table( $name );
			$rows += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		/* translators: %s: size in MB. */
		return sprintf( __( 'Upshift uses about %s MB of your database.', 'upshift' ), number_format_i18n( max( 0.1, $rows * 150 / Storage::MB ), 1 ) );
	}

	/**
	 * Redirects back to a tab with a notice, and exits.
	 *
	 * @param string $tab Tab.
	 * @param string $msg Notice key.
	 */
	private static function redirect( $tab, $msg ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'         => $tab,
					'upshift_msg' => $msg,
				),
				admin_url( 'tools.php?page=' . Page::SLUG )
			)
		);
		exit;
	}

	/**
	 * Admin-post: saves the settings (capability + nonce first).
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'upshift' ), 403 );
		}
		check_admin_referer( self::SAVE );

		$section = isset( $_POST['upshift_section'] ) ? sanitize_key( wp_unslash( $_POST['upshift_section'] ) ) : 'settings';
		$changes = array( 'upgrade_watch' => ! empty( $_POST['upgrade_watch'] ) );
		$was_on  = ! empty( Settings::get( 'upgrade_watch' ) );
		if ( $changes['upgrade_watch'] ) {
			// Turning it on (or saving with it on) ends a pause.
			$changes['watch_paused_until'] = 0;
		}
		if ( 'settings' === $section ) {
			$changes['usage_enabled']       = ! empty( $_POST['usage_enabled'] );
			$changes['email_crashes']       = ! empty( $_POST['email_crashes'] );
			$changes['delete_on_uninstall'] = ! empty( $_POST['delete_on_uninstall'] );
			if ( $changes['usage_enabled'] ) {
				// Turning it on (or saving with it on) ends a recorder pause.
				$changes['recorder_paused_until'] = 0;
			}
			$share = isset( $_POST['share'] ) ? sanitize_text_field( wp_unslash( $_POST['share'] ) ) : '';
			if ( is_numeric( $share ) ) {
				$changes['sample_rate'] = max( 1.0, min( 100.0, (float) $share ) ) / 100;
			}
		}
		Settings::update( $changes );

		if ( $changes['upgrade_watch'] ) {
			if ( ! $was_on ) {
				State::save( State::enabled( State::get(), State::version(), current_time( 'Y-m-d' ) ) );
			}
			State::move_first();
		} elseif ( $was_on ) {
			State::move_back();
		}

		self::redirect( 'watch' === $section ? 'watch' : 'settings', 'saved' );
	}

	/**
	 * Admin-post: deletes Smart results data, check results and the file cache. Settings stay (capability + nonce first).
	 */
	public static function handle_delete() {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'upshift' ), 403 );
		}
		check_admin_referer( self::DELETE, '_wpnonce_delete' );

		if ( null !== Runner::latest( 'running' ) ) {
			self::redirect( 'settings', 'busy' );
		}
		Rollup::reset();
		foreach ( array( 'scans', 'scan_files', 'findings', 'file_cache', 'errors' ) as $name ) {
			$table = Install::table( $name );
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name from $wpdb->prefix.
		}
		delete_option( Status::OPTION );
		delete_option( Runner::STATS );
		delete_option( State::OPTION );
		if ( ! empty( Settings::get( 'upgrade_watch' ) ) ) {
			State::save( State::enabled( State::get(), State::version(), current_time( 'Y-m-d' ) ) );
		}

		self::redirect( 'settings', 'deleted' );
	}
}
