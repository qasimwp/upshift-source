<?php
/**
 * Upgrade watch: the crash email (DECISIONS D59). Sent at the end of the crashing request, after its row is written:
 * WP-Cron may never run on a site that crashes on every request. WordPress's recovery-mode email works the same way.
 *
 * @package Upshift
 */

namespace Upshift\Watch;

use Upshift\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom table and one claim row in wp_options; the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Sends at most one email a day.
 */
class Notify {

	const OPTION = 'upshift_watch_mail_day';

	/**
	 * Sends the email if this is a new crash during a watch and none was sent today.
	 *
	 * @param array $row     Row from Collector::row().
	 * @param int   $context Page type bits.
	 */
	public static function on_crash( array $row, $context ) {
		if ( empty( Settings::get( 'email_crashes' ) ) ) {
			return;
		}
		$state = State::get();
		$today = current_time( 'Y-m-d' );
		if ( ! State::is_active( $state, $today ) || ! Report::is_new( $state, $row['hash'], $today ) || ! self::claim( $today ) ) {
			return;
		}

		// A crash while plugins load comes before WordPress allows translations, and the first __() would add a notice to the
		// log. Only our own text runs inside this window.
		$early = ! did_action( 'after_setup_theme' );
		if ( $early ) {
			add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		}
		$mail = self::message( $row, $context, self::count( $row['hash'], $state['start'] ) );
		if ( $early ) {
			remove_filter( 'doing_it_wrong_trigger_error', '__return_false' );
		}

		if ( ! function_exists( 'wp_mail' ) ) {
			// A crash while plugins load happens before WordPress loads this file.
			require_once ABSPATH . WPINC . '/pluggable.php';
		}
		wp_mail( (string) get_option( 'admin_email' ), $mail['subject'], $mail['body'], array( 'Content-Type: text/plain; charset=UTF-8' ) );
	}

	/**
	 * Claims today's email with one atomic write, before sending, so two crashing requests can't both send.
	 *
	 * @param string $today Y-m-d.
	 * @return bool Whether this request may send.
	 */
	private static function claim( $today ) {
		global $wpdb;

		// Rows affected: 1 inserted, 2 changed, 0 already today.
		$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", self::OPTION, $today ) );
		return false !== $result && (int) $wpdb->rows_affected > 0;
	}

	/**
	 * How often this crash happened since the watch started.
	 *
	 * @param string $hash  Message hash.
	 * @param string $start First day of the watch.
	 * @return int
	 */
	private static function count( $hash, $start ) {
		global $wpdb;

		$table = $wpdb->prefix . Collector::TABLE;
		return max( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(hits) FROM {$table} WHERE message_hash = %s AND day >= %s", $hash, $start ) ) );
	}

	/**
	 * The email: only the plugin name, the count, the page type and a link to the tab.
	 *
	 * @param array $row     Row.
	 * @param int   $context Page type bits.
	 * @param int   $count   Crashes.
	 * @return array array( 'subject', 'body' ).
	 */
	public static function message( array $row, $context, $count ) {
		$where = Report::where( $context );
		$lines = array(
			/* translators: 1: plugin name, 2: number of crashes. */
			sprintf( _n( '%1$s has crashed %2$s time since your PHP version changed.', '%1$s has crashed %2$s times since your PHP version changed.', $count, 'upshift' ), self::name( $row['component'] ), number_format_i18n( $count ) ),
		);
		if ( '' !== $where ) {
			/* translators: %s: page type, e.g. checkout. */
			$lines[] = sprintf( __( 'Where: %s.', 'upshift' ), $where );
		}
		$lines[] = '';
		$lines[] = __( 'See what happened and what to do:', 'upshift' );
		$lines[] = admin_url( 'tools.php?page=upshift&tab=watch' );

		return array(
			'subject' => __( 'A plugin started crashing after a PHP change', 'upshift' ),
			'body'    => implode( "\n", $lines ),
		);
	}

	/**
	 * A readable name, reading only the one plugin folder or theme concerned.
	 *
	 * @param string $component Component id.
	 * @return string
	 */
	private static function name( $component ) {
		$parts = explode( ':', (string) $component, 2 );
		$slug  = isset( $parts[1] ) ? $parts[1] : (string) $component;
		if ( 'plugin' === $parts[0] ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins( '/' . $slug ) as $data ) {
				if ( ! empty( $data['Name'] ) ) {
					return (string) $data['Name'];
				}
			}
			return $slug;
		}
		if ( 'theme' === $parts[0] ) {
			$name = wp_get_theme( $slug )->get( 'Name' );
			return is_string( $name ) && '' !== $name ? $name : $slug;
		}
		if ( 'mu-plugins' === $component ) {
			return __( 'Must-use plugins', 'upshift' );
		}
		return Collector::CORE === $component ? __( 'WordPress or your server', 'upshift' ) : $slug;
	}
}
