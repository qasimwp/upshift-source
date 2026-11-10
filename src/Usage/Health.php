<?php
/**
 * Plain-words state of the recording: dropped page views and an hourly job that stopped (DECISIONS D63).
 *
 * @package Upshift
 */

namespace Upshift\Usage;

use Upshift\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read by the Settings and Check tabs, Site Health and the Developer tab. Never loaded on a visitor request.
 */
class Health {

	const HEARTBEAT    = 'upshift_compact_last';
	const OVERDUE      = DAY_IN_SECONDS;
	const DROP_WINDOW  = 7 * DAY_IN_SECONDS;
	const UNMAPPED_MIN = 0.01;

	/**
	 * Dropped page views by reason, from the recorder flags.
	 *
	 * @return array reason => count.
	 */
	public static function dropped() {
		$flags = Recorder::flags();
		return isset( $flags['dropped'] ) && is_array( $flags['dropped'] ) ? array_map( 'intval', $flags['dropped'] ) : array();
	}

	/**
	 * Whether the hourly job has been silent for more than a day. A site without a heartbeat yet starts the clock here,
	 * so a site whose cron never runs is still reported a day later.
	 *
	 * @param int|null $now Current time (tests).
	 * @return bool
	 */
	public static function job_overdue( $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$last = get_option( self::HEARTBEAT, false );
		if ( false === $last ) {
			update_option( self::HEARTBEAT, $now, false );
			return false;
		}
		return $now - (int) $last > self::OVERDUE;
	}

	/**
	 * What the owner should know, one sentence each. Empty when all is well or Smart results is off.
	 *
	 * @param int|null $now Current time (tests).
	 * @return string[]
	 */
	public static function messages( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$out = array();
		if ( empty( Settings::get( 'usage_enabled' ) ) ) {
			return $out;
		}
		if ( self::job_overdue( $now ) ) {
			$out[] = __( "Upshift's hourly job has not run for more than a day, so Smart results is not being updated. WordPress scheduled tasks (WP-Cron) may be off on this site. Your host can check this.", 'upshift' );
		}

		$flags   = Recorder::flags();
		$dropped = array_sum( self::dropped() );
		if ( $dropped > 0 && ! empty( $flags['dropped_at'] ) && $now - (int) $flags['dropped_at'] <= self::DROP_WINDOW ) {
			$out[] = sprintf(
				/* translators: %s: number of page views. */
				_n( 'Upshift could not save %s page view because of a database problem. Recording continues by itself.', 'Upshift could not save %s page views because of a database problem. Recording continues by itself.', $dropped, 'upshift' ),
				number_format_i18n( $dropped )
			);
		}

		$meta = Rollup::meta();
		if ( false !== $meta && ! empty( $meta['sampled_requests'] ) && (int) $meta['unmapped_hits'] / (int) $meta['sampled_requests'] >= self::UNMAPPED_MIN ) {
			$out[] = sprintf(
				/* translators: 1: page views without a file list, 2: all page views. */
				__( '%1$s of %2$s page views have no list of files, so Smart results is incomplete for them. They are removed after 30 days.', 'upshift' ),
				number_format_i18n( (int) $meta['unmapped_hits'] ),
				number_format_i18n( (int) $meta['sampled_requests'] )
			);
		}
		return $out;
	}
}
