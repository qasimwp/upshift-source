<?php
/**
 * Option read/write with defaults and sanitization (spec §6, §7.11).
 *
 * @package Upshift
 */

namespace Upshift;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings stored in the autoloaded option `upshift_settings`.
 */
class Settings {

	const OPTION = 'upshift_settings';

	/**
	 * Allowed "from" overrides. Empty string = use the running PHP version.
	 */
	const FROM_VERSIONS = array( '', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4' );

	/**
	 * Allowed scan targets.
	 */
	const TARGET_VERSIONS = array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' );

	const SAMPLE_MIN = 0.01;
	const SAMPLE_MAX = 1.0;

	/**
	 * Cached merged settings for this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'usage_enabled'         => false,
			'sample_rate'           => 0.25,
			'from_override'         => '',
			'default_target'        => '8.4',
			'observe_since'         => '',
			// Internal: the recorder pauses itself after a DB failure (DECISIONS D18). Not on the form.
			'recorder_paused_until' => 0,
			// Upgrade watch and its crash email: opt-in, nothing is hooked while off (DECISIONS D59).
			'upgrade_watch'         => false,
			'email_crashes'         => false,
			// Internal: Upgrade watch pauses itself after a DB failure, like the recorder. Not on the form.
			'watch_paused_until'    => 0,
			// Delete every table and option when Upshift is deleted (on by default; DECISIONS D58).
			'delete_on_uninstall'   => true,
		);
	}

	/**
	 * The stored option read directly from the database, bypassing the request's option cache,
	 * so a write based on it can't undo a change another request made meanwhile.
	 *
	 * @return array|null Stored array, or null if missing, unreadable or the query failed.
	 */
	public static function stored() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberately uncached fresh read.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION ) );
		if ( null === $raw || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		$value = maybe_unserialize( $raw );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Returns all settings, or one value when $key is given.
	 *
	 * @param string|null $key Setting name.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			self::$cache = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	/**
	 * Sanitizes a full or partial settings array into a full one.
	 *
	 * @param array $input Raw values (already unslashed).
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$out = self::defaults();

		if ( isset( $input['usage_enabled'] ) ) {
			$out['usage_enabled'] = (bool) $input['usage_enabled'];
		}

		if ( isset( $input['sample_rate'] ) && is_numeric( $input['sample_rate'] ) ) {
			$out['sample_rate'] = max( self::SAMPLE_MIN, min( self::SAMPLE_MAX, (float) $input['sample_rate'] ) );
		}

		if ( isset( $input['from_override'] ) && in_array( (string) $input['from_override'], self::FROM_VERSIONS, true ) ) {
			$out['from_override'] = (string) $input['from_override'];
		}

		if ( isset( $input['default_target'] ) && in_array( (string) $input['default_target'], self::TARGET_VERSIONS, true ) ) {
			$out['default_target'] = (string) $input['default_target'];
		}

		if ( isset( $input['observe_since'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $input['observe_since'] ) ) {
			$out['observe_since'] = (string) $input['observe_since'];
		}

		foreach ( array( 'upgrade_watch', 'email_crashes', 'delete_on_uninstall' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}

		foreach ( array( 'recorder_paused_until', 'watch_paused_until' ) as $until ) {
			if ( isset( $input[ $until ] ) ) {
				$out[ $until ] = absint( $input[ $until ] );
			}
		}

		return $out;
	}

	/**
	 * Merges $changes into the current settings, sanitizes and saves.
	 *
	 * @param array $changes Values to change.
	 * @return array The saved settings.
	 */
	public static function update( array $changes ) {
		// Merge into the stored value read fresh, so a recorder pause written meanwhile isn't lost.
		$stored  = self::stored();
		$current = null === $stored ? self::get() : array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
		$new     = self::sanitize( array_merge( $current, $changes ) );

		// Observation starts the first time usage is enabled.
		if ( $new['usage_enabled'] && '' === $new['observe_since'] ) {
			$new['observe_since'] = current_time( 'Y-m-d' );
		}

		update_option( self::OPTION, $new );
		self::$cache = $new;
		return $new;
	}

	/**
	 * Clears the per-request cache.
	 */
	public static function flush() {
		self::$cache = null;
	}
}
