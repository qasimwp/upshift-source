<?php
/**
 * Upgrade watch state: the small autoloaded option, the PHP version change and the load order (DECISIONS D59).
 *
 * @package Upshift
 */

namespace Upshift\Watch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and saves the state.
 */
class State {

	const OPTION      = 'upshift_watch_state';
	const WATCH_DAYS  = 7;
	const CONFIRM_SEC = 300; // A new version counts only when seen again this long after the first time.

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'php'           => '',    // The PHP version the site runs (major.minor).
			'since'         => '',    // Day the switch was last turned on.
			'from'          => '',    // Watch: version before the change ('' = started by hand).
			'to'            => '',    // Watch: version being watched.
			'start'         => '',    // Watch: first day ('' = no watch).
			'end'           => '',    // Watch: last day.
			'source'        => '',    // Watch: 'switch' or 'manual'.
			'back'          => array(), // After a switch back: from, to, start, day.
			'capped_day'    => '',    // Day the row cap was reached.
			'pending'       => '',    // New version seen but not confirmed yet.
			'pending_at'    => 0,     // When it was first seen.
			'pending_block' => 0,     // No new pending before this time.
		);
	}

	/**
	 * The state, with every key present.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), array_intersect_key( is_array( $stored ) ? $stored : array(), self::defaults() ) );
	}

	/**
	 * Saves the state (autoloaded: the request path reads it without a query).
	 *
	 * @param array $state State.
	 */
	public static function save( array $state ) {
		update_option( self::OPTION, $state, true );
	}

	/**
	 * The running PHP version, major.minor.
	 *
	 * @return string
	 */
	public static function version() {
		return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	}

	/**
	 * Called once per request at the end: writes only when something changed.
	 */
	public static function on_request() {
		$state = self::observe( self::get(), self::version(), time() );
		if ( null !== $state ) {
			self::save( $state );
		}
	}

	/**
	 * Applies what this request saw. A new version must be seen twice, at least 5 minutes apart, so one odd request (a
	 * different pool, a stray worker) can't start a watch. The watch still starts on the day it was first seen.
	 *
	 * @param array  $state State.
	 * @param string $php   Running version.
	 * @param int    $now   Unix time.
	 * @return array|null The new state, or null when nothing changed.
	 */
	public static function observe( array $state, $php, $now ) {
		if ( '' === $state['php'] ) {
			$state['php'] = $php;
			return $state;
		}

		if ( $php === $state['php'] ) {
			if ( '' === $state['pending'] ) {
				return null;
			}
			// The old version came back: the new one was an odd request. The block keeps a mixed pool from writing every time.
			$state['pending']       = '';
			$state['pending_at']    = 0;
			$state['pending_block'] = $now + self::CONFIRM_SEC;
			return $state;
		}

		if ( $php !== $state['pending'] ) {
			if ( $now < (int) $state['pending_block'] ) {
				return null;
			}
			$state['pending']    = $php;
			$state['pending_at'] = $now;
			return $state;
		}

		if ( $now - (int) $state['pending_at'] < self::CONFIRM_SEC ) {
			return null;
		}
		return self::confirmed( $state, $php );
	}

	/**
	 * A confirmed version change: a new watch, or the end of the current one on a switch back.
	 *
	 * @param array  $state State.
	 * @param string $php   New version.
	 * @return array
	 */
	private static function confirmed( array $state, $php ) {
		$old = $state['php'];
		$day = wp_date( 'Y-m-d', (int) $state['pending_at'] );

		if ( '' !== $state['from'] && $php === $state['from'] && self::is_active( $state, $day ) ) {
			// Comparing with the days before would use the broken version as the normal one, so the watch just ends.
			$state['back'] = array(
				'from'  => $old,
				'to'    => $php,
				'start' => $state['start'],
				'day'   => $day,
			);
			foreach ( array( 'from', 'to', 'start', 'end', 'source' ) as $key ) {
				$state[ $key ] = '';
			}
		} else {
			$state         = self::begin( $state, $old, $php, $day, 'switch' );
			$state['back'] = array();
		}

		$state['php']           = $php;
		$state['pending']       = '';
		$state['pending_at']    = 0;
		$state['pending_block'] = 0;
		return $state;
	}

	/**
	 * Fills in a 7-day watch.
	 *
	 * @param array  $state  State.
	 * @param string $from   Version before ('' = none).
	 * @param string $to     Version watched.
	 * @param string $day    First day.
	 * @param string $source 'switch' or 'manual'.
	 * @return array
	 */
	private static function begin( array $state, $from, $to, $day, $source ) {
		$state['from']   = $from;
		$state['to']     = $to;
		$state['start']  = $day;
		$state['end']    = self::add_days( $day, self::WATCH_DAYS - 1 );
		$state['source'] = $source;
		return $state;
	}

	/**
	 * "Start comparing now".
	 *
	 * @param array  $state State.
	 * @param string $php   Running version.
	 * @param string $today Y-m-d.
	 * @return array
	 */
	public static function manual( array $state, $php, $today ) {
		$state         = self::begin( $state, '', $php, $today, 'manual' );
		$state['php']  = $php;
		$state['back'] = array();
		return $state;
	}

	/**
	 * The switch was turned on: the version is taken as it is now, so a change made while it was off isn't reported late.
	 * A watch of another version doesn't apply any more.
	 *
	 * @param array  $state State.
	 * @param string $php   Running version.
	 * @param string $today Y-m-d.
	 * @return array
	 */
	public static function enabled( array $state, $php, $today ) {
		if ( '' !== $state['to'] && $php !== $state['to'] ) {
			foreach ( array( 'from', 'to', 'start', 'end', 'source' ) as $key ) {
				$state[ $key ] = '';
			}
		}
		$state['php']        = $php;
		$state['since']      = $today;
		$state['pending']    = '';
		$state['pending_at'] = 0;
		return $state;
	}

	/**
	 * Whether a watch is running.
	 *
	 * @param array  $state State.
	 * @param string $today Y-m-d.
	 * @return bool
	 */
	public static function is_active( array $state, $today ) {
		return '' !== $state['start'] && $today >= $state['start'] && $today <= $state['end'];
	}

	/**
	 * A date plus some days.
	 *
	 * @param string $day  Y-m-d.
	 * @param int    $days Days (may be negative).
	 * @return string
	 */
	public static function add_days( $day, $days ) {
		return gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + (int) $days * DAY_IN_SECONDS );
	}

	/**
	 * Upshift's entry in active_plugins.
	 *
	 * @return string
	 */
	private static function own_plugin() {
		return plugin_basename( UPSHIFT_FILE );
	}

	/**
	 * Moves Upshift to the front of active_plugins, so it can see a crash in a plugin that loads later. The other plugins
	 * keep their order. Not run on every page, so a plugin that also forces itself first can't cause a write on each request.
	 */
	public static function move_first() {
		$active = (array) get_option( 'active_plugins', array() );
		$own    = array_search( self::own_plugin(), $active, true );
		if ( false === $own || 0 === $own ) {
			return;
		}
		unset( $active[ $own ] );
		array_unshift( $active, self::own_plugin() );
		update_option( 'active_plugins', array_values( $active ) );
	}

	/**
	 * Puts Upshift back at its alphabetical place. The other plugins keep their order (they may have a custom one).
	 */
	public static function move_back() {
		$active = (array) get_option( 'active_plugins', array() );
		$own    = array_search( self::own_plugin(), $active, true );
		if ( false === $own ) {
			return;
		}
		unset( $active[ $own ] );
		$active = array_values( $active );
		$place  = count( $active );
		foreach ( $active as $i => $plugin ) {
			if ( strcmp( (string) $plugin, self::own_plugin() ) > 0 ) {
				$place = $i;
				break;
			}
		}
		array_splice( $active, $place, 0, array( self::own_plugin() ) );
		update_option( 'active_plugins', $active );
	}
}
