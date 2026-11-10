<?php
/**
 * Upgrade watch: what the tab shows, compared with the days before (DECISIONS D59). Reads only.
 *
 * @package Upshift
 */

namespace Upshift\Watch;

use Upshift\Report\Check;
use Upshift\Settings;
use Upshift\Usage\Recorder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom table (spec §6); the object cache does not apply to it.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Builds the report for the Upgrade watch tab.
 */
class Report {

	const BEFORE_DAYS = 7;
	const MAX_SAMPLES = 5;

	const BEFORE   = 'before';
	const WATCHING = 'watching';
	const FINISHED = 'finished';
	const BACK     = 'back';

	/**
	 * Builds the report.
	 *
	 * @return array
	 */
	public static function build() {
		$state  = State::get();
		$today  = current_time( 'Y-m-d' );
		$phase  = self::phase( $state, $today );
		$report = array(
			'state'  => $state,
			'today'  => $today,
			'phase'  => $phase,
			'php'    => State::version(),
			'paused' => (int) Settings::get( 'watch_paused_until' ) > time(),
			'capped' => $state['capped_day'] === $today,
			'rate'   => self::rate_percent(),
			'recent' => self::recent( $today ),
			'items'  => array(),
			'also'   => 0,
			'day'    => 0,
			'thin'   => null,
			'answer' => null,
		);
		if ( self::BEFORE === $phase ) {
			return $report;
		}

		$ranges          = self::ranges( $state, $phase, $today );
		$report['items'] = self::items( $ranges );
		foreach ( $report['items'] as $item ) {
			$report['also'] += $item['deprecation'];
		}
		$report['day']    = min( State::WATCH_DAYS, self::days_between( $state['start'], $today ) + 1 );
		$report['thin']   = self::thin( $state );
		$report['answer'] = self::answer( $report['items'], $phase, $state );
		return $report;
	}

	/**
	 * Which screen applies.
	 *
	 * @param array  $state State.
	 * @param string $today Y-m-d.
	 * @return string
	 */
	private static function phase( array $state, $today ) {
		if ( '' !== $state['start'] ) {
			return $today <= $state['end'] ? self::WATCHING : self::FINISHED;
		}
		return empty( $state['back'] ) ? self::BEFORE : self::BACK;
	}

	/**
	 * The share of page views watched, in percent (the same rule as Collector::watched()).
	 *
	 * @return int
	 */
	private static function rate_percent() {
		$rate = ! empty( Settings::get( 'usage_enabled' ) ) ? (float) Settings::get( 'sample_rate' ) : Collector::DEFAULT_RATE;
		return (int) round( 100 * $rate );
	}

	/**
	 * Whole days from one date to another.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int
	 */
	private static function days_between( $from, $to ) {
		return (int) floor( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
	}

	/**
	 * Last 7 days, whatever the PHP version: count per level.
	 *
	 * @param string $today Y-m-d.
	 * @return array level => count.
	 */
	private static function recent( $today ) {
		global $wpdb;

		$out = array(
			Collector::CRASH       => 0,
			Collector::ERROR       => 0,
			Collector::WARNING     => 0,
			Collector::DEPRECATION => 0,
		);

		$table = $wpdb->prefix . Collector::TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT level, SUM(hits) AS hits FROM {$table} WHERE day >= %s GROUP BY level", State::add_days( $today, 1 - self::BEFORE_DAYS ) ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$out[ $row['level'] ] = (int) $row['hits'];
		}
		return $out;
	}

	/**
	 * The two windows to compare.
	 *
	 * @param array  $state State.
	 * @param string $phase Phase.
	 * @param string $today Y-m-d.
	 * @return array array( 'after' => php, first, last; 'before' => php ('' = any), first, last ).
	 */
	private static function ranges( array $state, $phase, $today ) {
		if ( self::BACK === $phase ) {
			$back = $state['back'];
			return array(
				'after'  => array( $back['from'], $back['start'], $back['day'] ),
				'before' => array( $back['to'], State::add_days( $back['start'], -self::BEFORE_DAYS ), $back['start'] ),
			);
		}
		$after = array( $state['to'], $state['start'], min( $state['end'], $today ) );
		if ( 'manual' === $state['source'] ) {
			return array(
				'after'  => $after,
				'before' => array( '', State::add_days( $state['start'], -self::BEFORE_DAYS ), State::add_days( $state['start'], -1 ) ),
			);
		}
		return array(
			'after'  => $after,
			'before' => array( $state['from'], State::add_days( $state['start'], -self::BEFORE_DAYS ), $state['start'] ),
		);
	}

	/**
	 * Whether a problem was absent from the before window of the running watch.
	 *
	 * @param array  $state State.
	 * @param string $hash  Message hash.
	 * @param string $today Y-m-d.
	 * @return bool
	 */
	public static function is_new( array $state, $hash, $today ) {
		global $wpdb;

		$table = $wpdb->prefix . Collector::TABLE;

		list( $php, $first, $last ) = self::ranges( $state, self::WATCHING, $today )['before'];
		if ( '' === $php ) {
			$seen = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE message_hash = %s AND day BETWEEN %s AND %s", $hash, $first, $last ) );
		} else {
			$seen = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE message_hash = %s AND php_version = %s AND day BETWEEN %s AND %s", $hash, $php, $first, $last ) );
		}
		return 0 === (int) $seen;
	}

	/**
	 * Problems of the after window that weren't seen in the before window, per plugin or theme, worst first.
	 *
	 * @param array $ranges ranges().
	 * @return array
	 */
	private static function items( array $ranges ) {
		global $wpdb;

		$table = $wpdb->prefix . Collector::TABLE;

		list( $before_php, $before_first, $before_last ) = $ranges['before'];
		if ( '' === $before_php ) {
			$seen = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT message_hash FROM {$table} WHERE day BETWEEN %s AND %s", $before_first, $before_last ) );
		} else {
			$seen = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT message_hash FROM {$table} WHERE php_version = %s AND day BETWEEN %s AND %s", $before_php, $before_first, $before_last ) );
		}
		$seen = array_flip( (array) $seen );

		list( $after_php, $after_first, $after_last ) = $ranges['after'];

		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT component, level, message_hash, SUM(hits) AS hits, BIT_OR(context) AS context, MAX(day) AS day, MAX(sample) AS sample FROM {$table} WHERE php_version = %s AND day BETWEEN %s AND %s GROUP BY component, level, message_hash", $after_php, $after_first, $after_last ), ARRAY_A );
		$items = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $seen[ $row['message_hash'] ] ) ) {
				continue;
			}
			$id = (string) $row['component'];
			if ( ! isset( $items[ $id ] ) ) {
				$items[ $id ] = array(
					'id'                   => $id,
					'name'                 => $id,
					Collector::CRASH       => 0,
					Collector::ERROR       => 0,
					Collector::WARNING     => 0,
					Collector::DEPRECATION => 0,
					'context'              => 0,
					'day'                  => '',
					'samples'              => array(),
				);
			}
			$items[ $id ][ $row['level'] ] += (int) $row['hits'];
			$items[ $id ]['context']       |= (int) $row['context'];
			$items[ $id ]['day']            = max( $items[ $id ]['day'], (string) $row['day'] );
			$items[ $id ]['samples'][]      = array(
				'level'  => (string) $row['level'],
				'hits'   => (int) $row['hits'],
				'sample' => (string) $row['sample'],
			);
		}

		// Deprecations alone don't get a row: they are only counted in the "Also seen" line.
		$names = self::names( array_keys( $items ) );
		foreach ( $items as $id => $item ) {
			$items[ $id ]['name'] = $names[ $id ];
			usort(
				$items[ $id ]['samples'],
				function ( $a, $b ) {
					$by_level = self::rank( $b['level'] ) - self::rank( $a['level'] );
					return 0 !== $by_level ? $by_level : $b['hits'] - $a['hits'];
				}
			);
			$items[ $id ]['samples'] = array_slice( $items[ $id ]['samples'], 0, self::MAX_SAMPLES );
		}
		uasort(
			$items,
			function ( $a, $b ) {
				return array( $b[ Collector::CRASH ], $b[ Collector::ERROR ], $b[ Collector::WARNING ], $b[ Collector::DEPRECATION ] ) <=> array( $a[ Collector::CRASH ], $a[ Collector::ERROR ], $a[ Collector::WARNING ], $a[ Collector::DEPRECATION ] );
			}
		);
		return array_values( $items );
	}

	/**
	 * Sort weight of a level.
	 *
	 * @param string $level Level.
	 * @return int
	 */
	private static function rank( $level ) {
		$ranks = array(
			Collector::CRASH       => 3,
			Collector::ERROR       => 2,
			Collector::WARNING     => 1,
			Collector::DEPRECATION => 0,
		);
		return isset( $ranks[ $level ] ) ? $ranks[ $level ] : 0;
	}

	/**
	 * Display names, from the same list as the Check tab.
	 *
	 * @param string[] $ids Component ids.
	 * @return array id => name.
	 */
	private static function names( array $ids ) {
		$components = array();
		foreach ( $ids as $id ) {
			$components[ $id ] = array(
				'status' => '',
				'active' => true,
			);
		}
		$names = Check::names( array( 'components' => $components ) );
		if ( isset( $names[ Collector::CORE ] ) ) {
			$names[ Collector::CORE ] = __( 'WordPress or your server', 'upshift' );
		}
		return $names;
	}

	/**
	 * Days the switch was on before the change, when that is less than the 7 days compared.
	 *
	 * @param array $state State.
	 * @return int|null
	 */
	private static function thin( array $state ) {
		$start = '' !== $state['start'] ? $state['start'] : ( isset( $state['back']['start'] ) ? $state['back']['start'] : '' );
		if ( '' === $start || '' === $state['since'] ) {
			return null;
		}
		$days = self::days_between( $state['since'], $start );
		return $days < self::BEFORE_DAYS ? max( 0, $days ) : null;
	}

	/**
	 * The page types in a bit mask, most specific first, as chips: "site pages" is dropped when a shop page is named.
	 *
	 * @param int $mask Context bits.
	 * @return array array( array( dashicon, label ), ... ).
	 */
	public static function chips( $mask ) {
		$shop  = Recorder::WC_CHECKOUT | Recorder::WC_CART | Recorder::WC_PRODUCT | Recorder::WC_SHOP | Recorder::WC_ACCOUNT;
		$chips = array();
		foreach ( Check::chip_list() as $bit => $chip ) {
			if ( ( (int) $mask & $bit ) && ! ( Recorder::FRONT === $bit && ( (int) $mask & $shop ) ) ) {
				$chips[] = $chip;
			}
		}
		return $chips;
	}

	/**
	 * The chips as words.
	 *
	 * @param int $mask Context bits.
	 * @return string
	 */
	public static function where( $mask ) {
		return implode( ', ', array_column( self::chips( $mask ), 1 ) );
	}

	/**
	 * The coloured answer. Red = a new crash or a new error; new warnings alone stay green and say so (DECISIONS D59).
	 *
	 * @param array  $items items().
	 * @param string $phase Phase.
	 * @param array  $state State.
	 * @return array array( 'level', 'sentence' ).
	 */
	private static function answer( array $items, $phase, array $state ) {
		$since = self::since_words( $phase, $state );
		foreach ( array( Collector::CRASH, Collector::ERROR ) as $kind ) {
			$bad = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $kind ) {
						return $item[ $kind ] > 0;
					}
				)
			);
			if ( ! $bad ) {
				continue;
			}
			$count = array_sum( array_column( $bad, $kind ) );
			$mask  = 0;
			foreach ( $bad as $item ) {
				$mask |= $item['context'];
			}
			$where = self::where( $mask );
			$place = '' !== $where ? ' (' . $where . ')' : '';
			if ( 1 === count( $bad ) ) {
				$name = $bad[0]['name'];
				if ( Collector::CRASH === $kind ) {
					/* translators: 1: plugin name, 2: number of crashes, 3: "since the upgrade", 4: where, e.g. " (checkout)". */
					$text = _n( '%1$s caused %2$s crash %3$s%4$s.', '%1$s caused %2$s crashes %3$s%4$s.', $count, 'upshift' );
				} else {
					/* translators: 1: plugin name, 2: number of errors, 3: "since the upgrade", 4: where, e.g. " (checkout)". */
					$text = _n( '%1$s caused %2$s error %3$s%4$s.', '%1$s caused %2$s errors %3$s%4$s.', $count, 'upshift' );
				}
				$sentence = sprintf( $text, $name, number_format_i18n( $count ), $since, $place );
			} else {
				if ( Collector::CRASH === $kind ) {
					/* translators: 1: number of plugins, 2: number of crashes, 3: "since the upgrade", 4: where, e.g. " (checkout)". */
					$text = _n( '%1$s plugins caused %2$s crash %3$s%4$s.', '%1$s plugins caused %2$s crashes %3$s%4$s.', $count, 'upshift' );
				} else {
					/* translators: 1: number of plugins, 2: number of errors, 3: "since the upgrade", 4: where, e.g. " (checkout)". */
					$text = _n( '%1$s plugins caused %2$s error %3$s%4$s.', '%1$s plugins caused %2$s errors %3$s%4$s.', $count, 'upshift' );
				}
				$sentence = sprintf( $text, number_format_i18n( count( $bad ) ), number_format_i18n( $count ), $since, $place );
			}
			return array(
				'level'    => 'red',
				'sentence' => $sentence,
			);
		}

		$warned = count(
			array_filter(
				$items,
				function ( $item ) {
					return $item[ Collector::WARNING ] > 0;
				}
			)
		);
		if ( $warned > 0 ) {
			/* translators: 1: "since the upgrade", 2: number of plugins. */
			$sentence = sprintf( _n( 'No new crashes or errors %1$s. %2$s plugin shows new warnings.', 'No new crashes or errors %1$s. %2$s plugins show new warnings.', $warned, 'upshift' ), $since, number_format_i18n( $warned ) );
		} else {
			/* translators: %s: "since the upgrade". */
			$sentence = sprintf( __( 'No new problems %s.', 'upshift' ), $since );
		}
		return array(
			'level'    => 'green',
			'sentence' => $sentence,
		);
	}

	/**
	 * "since the upgrade", or its equivalent for a watch started by hand or a version left again.
	 *
	 * @param string $phase Phase.
	 * @param array  $state State.
	 * @return string
	 */
	private static function since_words( $phase, array $state ) {
		if ( self::BACK === $phase ) {
			/* translators: %s: PHP version. */
			return sprintf( __( 'while on PHP %s', 'upshift' ), $state['back']['from'] );
		}
		return 'manual' === $state['source'] ? __( 'since you started comparing', 'upshift' ) : __( 'since the upgrade', 'upshift' );
	}

	/**
	 * A date in the short form used on the tab.
	 *
	 * @param string $day Y-m-d.
	 * @return string
	 */
	public static function date( $day ) {
		return (string) mysql2date( __( 'j M', 'upshift' ), $day . ' 12:00:00' );
	}

	/**
	 * The line under the answer: what happened and which day of the watch it is.
	 *
	 * @param array $report build().
	 * @return string
	 */
	public static function headline( array $report ) {
		$state = $report['state'];
		if ( self::BACK === $report['phase'] ) {
			/* translators: 1: PHP version, 2: date. */
			return sprintf( __( 'You moved back to PHP %1$s on %2$s.', 'upshift' ), $state['back']['to'], self::date( $state['back']['day'] ) );
		}
		if ( 'manual' === $state['source'] ) {
			/* translators: %s: date. */
			$first = sprintf( __( 'You started comparing on %s.', 'upshift' ), self::date( $state['start'] ) );
		} else {
			/* translators: 1: old PHP version, 2: new PHP version, 3: date. */
			$first = sprintf( __( 'You moved from PHP %1$s to PHP %2$s on %3$s.', 'upshift' ), $state['from'], $state['to'], self::date( $state['start'] ) );
		}
		if ( self::FINISHED === $report['phase'] ) {
			/* translators: %s: date. */
			return $first . ' ' . sprintf( __( 'The 7-day comparison ended on %s.', 'upshift' ), self::date( $state['end'] ) );
		}
		/* translators: 1: day number, 2: number of days. */
		return $first . ' ' . sprintf( __( 'Day %1$s of %2$s.', 'upshift' ), number_format_i18n( (int) $report['day'] ), number_format_i18n( State::WATCH_DAYS ) );
	}

	/**
	 * A count with its word, e.g. "2 crashes".
	 *
	 * @param int    $count Count.
	 * @param string $level Level.
	 * @return string
	 */
	public static function count_words( $count, $level ) {
		$n = number_format_i18n( $count );
		switch ( $level ) {
			case Collector::CRASH:
				/* translators: %s: number of crashes. */
				return sprintf( _n( '%s crash', '%s crashes', $count, 'upshift' ), $n );
			case Collector::ERROR:
				/* translators: %s: number of errors. */
				return sprintf( _n( '%s error', '%s errors', $count, 'upshift' ), $n );
			case Collector::WARNING:
				/* translators: %s: number of warnings. */
				return sprintf( _n( '%s warning', '%s warnings', $count, 'upshift' ), $n );
		}
		/* translators: %s: number of notes. */
		return sprintf( _n( '%s note about old code that still works', '%s notes about old code that still work', $count, 'upshift' ), $n );
	}

	/**
	 * Last 7 days as "0 crashes, 3 warnings". Errors appear only when there are some.
	 *
	 * @param array $recent recent().
	 * @return string
	 */
	public static function recent_words( array $recent ) {
		$parts = array( self::count_words( $recent[ Collector::CRASH ], Collector::CRASH ) );
		if ( $recent[ Collector::ERROR ] > 0 ) {
			$parts[] = self::count_words( $recent[ Collector::ERROR ], Collector::ERROR );
		}
		$parts[] = self::count_words( $recent[ Collector::WARNING ], Collector::WARNING );
		return implode( ', ', $parts );
	}

	/**
	 * The plain advice under the answer.
	 *
	 * @param array $report build().
	 * @return string '' when there is nothing to do.
	 */
	public static function advice( array $report ) {
		$has = function ( $level ) use ( $report ) {
			return array_sum( array_column( $report['items'], $level ) ) > 0;
		};
		if ( $has( Collector::CRASH ) ) {
			if ( self::BACK !== $report['phase'] && 'switch' === $report['state']['source'] ) {
				/* translators: %s: PHP version. */
				return sprintf( __( 'Ask your host to switch back to PHP %s and contact the plugin developer.', 'upshift' ), $report['state']['from'] );
			}
			return __( 'Contact the plugin developer and send them this report.', 'upshift' );
		}
		if ( $has( Collector::ERROR ) ) {
			return __( 'Contact the plugin developer and send them this report.', 'upshift' );
		}
		if ( $has( Collector::WARNING ) ) {
			return __( 'Nothing to do right away. Send this report to the plugin developer when you have time.', 'upshift' );
		}
		return '';
	}

	/**
	 * Plain text for "Copy report".
	 *
	 * @param array $report build().
	 * @return string
	 */
	public static function text( array $report ) {
		$lines = array( __( 'Upgrade guard report', 'upshift' ), self::headline( $report ), $report['answer']['sentence'], '' );
		foreach ( $report['items'] as $item ) {
			$lines[] = sprintf(
				/* translators: 1: plugin name, 2: number of crashes in words, 3: number of errors in words, 4: number of warnings in words, 5: page types, 6: date. */
				__( '%1$s: new: %2$s, %3$s, %4$s. Where: %5$s. Last seen: %6$s.', 'upshift' ),
				$item['name'],
				self::count_words( $item[ Collector::CRASH ], Collector::CRASH ),
				self::count_words( $item[ Collector::ERROR ], Collector::ERROR ),
				self::count_words( $item[ Collector::WARNING ], Collector::WARNING ),
				self::where( $item['context'] ),
				self::date( $item['day'] )
			);
			foreach ( $item['samples'] as $sample ) {
				$lines[] = '  ' . $sample['sample'];
			}
		}
		return implode( "\n", $lines );
	}
}
