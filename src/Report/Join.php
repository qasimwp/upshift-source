<?php
/**
 * Findings × usage: buckets, priority, filters, spike metrics (spec §7.8, §2).
 *
 * @package Upshift
 */

namespace Upshift\Report;

use Upshift\Install;
use Upshift\Scan\Categories;
use Upshift\Scan\Runner;
use Upshift\Usage\Bench;
use Upshift\Usage\Recorder;
use Upshift\Usage\Rollup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Joins the latest done scan's findings with the usage table. Never loads the engine.
 */
class Join {

	const ALWAYS     = 'always';
	const SOMETIMES  = 'sometimes';
	const NEVER      = 'never';
	const INACTIVE   = 'inactive';
	const ALWAYS_MIN = 0.95;

	// Pass rules (spec §2).
	const M1_MEMORY_SHARE = 0.75;
	const M4_SHARE        = 0.30;
	const MIN_COUNTED     = 20;
	const MIN_SAMPLED     = 500;
	const MIN_DAYS        = 5;

	// M3a limits: recorder prep as % of request time (spec §2, DECISIONS D38).
	const REL_MEDIAN_MAX = 2;
	const REL_P95_MAX    = 5;

	const PASS         = 'pass';
	const FAIL         = 'fail';
	const INCONCLUSIVE = 'inconclusive';
	const NOT_MEASURED = 'n/a';

	// Category filter values: "removed + deprecated + changed", new for this upgrade (the counted findings), and the
	// findings of those categories that are already true on the "from" version (DECISIONS D52).
	const COUNTED_FILTER = 'counted';
	const CURRENT_FILTER = 'current';
	// Findings in code that can't run on the target, or only runs when the feature exists (DECISIONS D56).
	const GUARDED_FILTER = 'guarded';
	const MESSAGE_CHUNK  = 500;

	/**
	 * Buckets in display order.
	 *
	 * @return string[]
	 */
	public static function buckets() {
		return array( self::ALWAYS, self::SOMETIMES, self::NEVER, self::INACTIVE );
	}

	/**
	 * Categories that count as upgrade problems. newer_than_current, advisory and ignored are information only (spec §2).
	 *
	 * @return string[]
	 */
	public static function counted_categories() {
		return Categories::counted();
	}

	/**
	 * Whether a finding row counts toward metrics and problem totals: active component, counted category, and new for
	 * this upgrade (not also reported for the "from" version alone, DECISIONS D52).
	 *
	 * @param array $row Row from findings() (active, category, baseline).
	 * @return bool
	 */
	public static function is_counted( array $row ) {
		return $row['active'] && empty( $row['baseline'] ) && empty( $row['guarded'] ) && in_array( $row['category'], self::counted_categories(), true );
	}

	/**
	 * Label for the tier column of the Findings table and the CSV.
	 *
	 * @param array  $row    Row from findings().
	 * @param string $target Target version.
	 * @return string
	 */
	public static function row_tier( array $row, $target ) {
		if ( ! empty( $row['guarded'] ) ) {
			return __( 'guarded: only runs where it is safe', 'upshift' );
		}
		return ! empty( $row['baseline'] ) ? __( 'already true on the from version', 'upshift' ) : self::tier_label( $row['category'], $target );
	}

	/**
	 * Severity tier of a counted category (spec §7.8): removed = will break on the target, needs_extension = the host
	 * must install a PHP extension (D55), deprecated = deprecation notice, changed = behaviour change.
	 *
	 * @param string $category Category.
	 * @param string $target   Target version.
	 * @return string Label, '' for categories that aren't counted.
	 */
	public static function tier_label( $category, $target ) {
		switch ( $category ) {
			case Categories::REMOVED:
				/* translators: %s: target PHP version. */
				return sprintf( __( 'will break on PHP %s', 'upshift' ), $target );
			case Categories::NEEDS_EXTENSION:
				return __( 'needs a PHP extension', 'upshift' );
			case Categories::DEPRECATED:
				return __( 'deprecation notice', 'upshift' );
			case Categories::CHANGED:
				return __( 'behaviour change', 'upshift' );
			default:
				return '';
		}
	}

	/**
	 * The summary line every report leads with: "Will break on PHP 8.4: N", said plainly when N is 0 (spec §7.8).
	 *
	 * @param int[]  $tiers   Counted findings per category (removed, needs_extension, deprecated, changed).
	 * @param string $from    From version.
	 * @param string $target  Target version.
	 * @param bool   $checked   Whether the scan ran the new-risk check (DECISIONS D52).
	 * @param int    $unchecked Active code files that were not checked (Runner::file_checks(); DECISIONS D54).
	 * @return string
	 */
	public static function headline( array $tiers, $from, $target, $checked, $unchecked = 0 ) {
		$tiers = array_merge( array_fill_keys( self::counted_categories(), 0 ), $tiers );
		if ( 0 === (int) $tiers[ Categories::REMOVED ] ) {
			/* translators: 1: target PHP version, 2: from PHP version. */
			$line = sprintf( __( 'Will break on PHP %1$s: 0. No code was found that works on PHP %2$s today and stops working on %1$s.', 'upshift' ), $target, $from );
		} else {
			/* translators: 1: target PHP version, 2: number of findings, 3: from PHP version. */
			$line = sprintf( __( 'Will break on PHP %1$s: %2$s (code that works on PHP %3$s today and is removed by %1$s).', 'upshift' ), $target, number_format_i18n( $tiers[ Categories::REMOVED ] ), $from );
		}
		if ( $unchecked > 0 ) {
			/* translators: %s: number of files. */
			$line .= ' ' . sprintf( _n( 'This result excludes %s active code file that was not checked (listed on the Scan tab).', 'This result excludes %s active code files that were not checked (listed on the Scan tab).', $unchecked, 'upshift' ), number_format_i18n( $unchecked ) );
		}
		/* translators: 1: findings that need a PHP extension, 2: deprecation notices, 3: behaviour changes. */
		$line .= ' ' . sprintf( __( 'Needs a PHP extension: %1$s. Deprecation notices: %2$s. Behaviour changes: %3$s.', 'upshift' ), number_format_i18n( $tiers[ Categories::NEEDS_EXTENSION ] ), number_format_i18n( $tiers[ Categories::DEPRECATED ] ), number_format_i18n( $tiers[ Categories::CHANGED ] ) );
		if ( ! $checked ) {
			/* translators: %s: from PHP version. */
			$line .= ' ' . sprintf( __( 'This scan was made before the new-risk check: these totals still include problems that are already true on PHP %s. Run a new scan for the exact numbers.', 'upshift' ), $from );
		}
		return $line;
	}

	/**
	 * All findings of a scan joined with usage, without messages, sorted by priority (desc).
	 *
	 * @param int $scan_id Scan id.
	 * @return array List of rows: id, component, path_key, line, severity, category, rule, baseline, active, contexts,
	 *               always_in, whole_site, bucket, priority.
	 */
	public static function findings( $scan_id ) {
		global $wpdb;

		// Request totals of the swapped rollup, for the per-context shares (DECISIONS D53).
		$meta     = Rollup::meta();
		$requests = false !== $meta && isset( $meta['requests'] ) ? (array) $meta['requests'] : array();

		$findings = Install::table( 'findings' );
		$usage    = Install::table( 'usage' );
		$files    = Install::table( 'scan_files' );

		$active = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT component, MAX(component_active) AS active FROM {$files} WHERE scan_id = %d GROUP BY component", (int) $scan_id ), ARRAY_A ) as $row ) {
			$active[ $row['component'] ] = (bool) $row['active'];
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT f.id, f.component, f.path_key, f.line, f.severity, f.category, f.rule, f.baseline, f.guarded, u.contexts, u.hits_by_context, u.id AS usage_id FROM {$findings} f LEFT JOIN {$usage} u ON u.path_hash = f.path_hash WHERE f.scan_id = %d", (int) $scan_id ), ARRAY_A );
		foreach ( $rows as $i => $row ) {
			$row['id']        = (int) $row['id'];
			$row['line']      = (int) $row['line'];
			$row['baseline']  = (int) $row['baseline'];
			$row['guarded']   = (int) $row['guarded'];
			$row['active']    = ! empty( $active[ $row['component'] ] );
			$row['seen']      = null !== $row['usage_id'];
			$row['contexts']  = (int) $row['contexts'];
			$always           = self::always_in( $row['hits_by_context'], $requests );
			$row['always_in'] = $always['mask'];
			// Whole site: at least 95% in every primary context with enough requests (WP-CLI aside), not just in one.
			$row['whole_site'] = $always['counted'] > 0 && ( $always['mask'] & $always['counted'] ) === $always['counted'];
			unset( $row['usage_id'], $row['hits_by_context'] );
			$row['bucket']   = self::bucket( $row );
			$row['priority'] = self::priority( $row );
			$rows[ $i ]      = $row;
		}
		usort( $rows, array( __CLASS__, 'compare' ) );
		return $rows;
	}

	/**
	 * Primary contexts with at least Rollup::MIN_REQUESTS sampled requests, and the ones among them in which a file
	 * loaded on at least 95% of the requests. Read time only, from the stored `hits_by_context` and the meta request
	 * totals (DECISIONS D53). WP-CLI is never in `counted` (it isn't site
	 * traffic), at any request count, but can be in `mask` for the label.
	 *
	 * @param string|null $hits_by_context JSON from upshift_usage (bit => hits).
	 * @param array       $requests        Meta 'requests' (primary bit => sampled requests).
	 * @return array mask (always-loaded contexts), counted (contexts the whole-site check needs).
	 */
	public static function always_in( $hits_by_context, array $requests ) {
		$by  = is_string( $hits_by_context ) ? json_decode( $hits_by_context, true ) : null;
		$out = array(
			'mask'    => 0,
			'counted' => 0,
		);
		foreach ( $requests as $bit => $total ) {
			$bit   = (int) $bit;
			$total = (int) $total;
			if ( $total < Rollup::MIN_REQUESTS ) {
				continue;
			}
			if ( Recorder::CLI !== $bit ) {
				$out['counted'] |= $bit;
			}
			if ( is_array( $by ) && isset( $by[ $bit ] ) && $by[ $bit ] / $total >= self::ALWAYS_MIN ) {
				$out['mask'] |= $bit;
			}
		}
		return $out;
	}

	/**
	 * Bucket of a finding (spec §7.8). `always` = loaded on at least 95% of the requests in every primary context
	 * with 30+ requests except WP-CLI (the whole site; DECISIONS D53, D55).
	 *
	 * @param array $row Needs active, seen, whole_site.
	 * @return string
	 */
	public static function bucket( array $row ) {
		if ( ! $row['active'] ) {
			return self::INACTIVE;
		}
		if ( ! $row['seen'] ) {
			return self::NEVER;
		}
		return ! empty( $row['whole_site'] ) ? self::ALWAYS : self::SOMETIMES;
	}

	/**
	 * Priority = severity_w × category_w × usage_w (spec §7.8). A finding already true on the "from" version gets
	 * category_w 0.2, like the other information findings (DECISIONS D52).
	 *
	 * A file loaded on every front-end request keeps the `always` weight even when it isn't loaded on the whole site;
	 * one always loaded only in admin, cron, REST, AJAX or login gets the `sometimes` weight (DECISIONS D53).
	 *
	 * @param array $row Needs severity, category, baseline, bucket, contexts, always_in.
	 * @return float
	 */
	public static function priority( array $row ) {
		$severity = 'error' === $row['severity'] ? 3.0 : 1.0;
		$weights  = array(
			Categories::REMOVED            => 3.0,
			Categories::CHANGED            => 2.0,
			Categories::NEEDS_EXTENSION    => 1.0,
			Categories::DEPRECATED         => 1.0,
			Categories::NEWER_THAN_CURRENT => 0.2,
			Categories::ADVISORY           => 0.2,
			Categories::IGNORED            => 0.2,
		);
		$category = isset( $weights[ $row['category'] ] ) ? $weights[ $row['category'] ] : 1.0;
		if ( ! empty( $row['baseline'] ) || ! empty( $row['guarded'] ) ) {
			$category = 0.2;
		}

		if ( self::INACTIVE === $row['bucket'] ) {
			$usage = 0.1;
		} elseif ( self::NEVER === $row['bucket'] ) {
			$usage = 0.4;
		} elseif ( $row['contexts'] & ( Recorder::WC_CHECKOUT | Recorder::WC_CART ) ) {
			$usage = 4.0;
		} elseif ( self::ALWAYS === $row['bucket'] || ( isset( $row['always_in'] ) && ( $row['always_in'] & Recorder::FRONT ) ) ) {
			$usage = 3.0;
		} elseif ( $row['contexts'] & Recorder::FRONT ) {
			$usage = 2.5;
		} else {
			$usage = 2.0;
		}
		return round( $severity * $category * $usage, 2 );
	}

	/**
	 * Sort: priority desc, then component, file, line.
	 *
	 * @param array $a Row.
	 * @param array $b Row.
	 * @return int
	 */
	public static function compare( array $a, array $b ) {
		if ( $a['priority'] !== $b['priority'] ) {
			return $a['priority'] < $b['priority'] ? 1 : -1;
		}
		$c = strcmp( $a['component'], $b['component'] );
		if ( 0 !== $c ) {
			return $c;
		}
		$c = strcmp( $a['path_key'], $b['path_key'] );
		return 0 !== $c ? $c : $a['line'] - $b['line'];
	}

	/**
	 * Human usage label: contexts for seen files, "not seen in N days of observation" otherwise. Never "safe" (spec §2).
	 * A seen file loaded on every request says "whole site" or which contexts, e.g. "every admin request" (DECISIONS D53).
	 *
	 * @param array $row  Row from findings().
	 * @param int   $days Days observed.
	 * @return string
	 */
	public static function usage_label( array $row, $days ) {
		if ( self::INACTIVE === $row['bucket'] ) {
			return __( 'component inactive', 'upshift' );
		}
		if ( self::NEVER === $row['bucket'] ) {
			if ( $days <= 0 ) {
				return __( 'no usage data yet', 'upshift' );
			}
			/* translators: %d: number of days. */
			return sprintf( _n( 'not seen during %d day of observation', 'not seen during %d days of observation', $days, 'upshift' ), $days );
		}
		$label = Recorder::label( $row['contexts'] );
		if ( ! empty( $row['whole_site'] ) ) {
			/* translators: %s: contexts the file was seen in. */
			return sprintf( __( 'every request on the whole site · seen in: %s', 'upshift' ), $label );
		}
		if ( ! empty( $row['always_in'] ) ) {
			/* translators: 1: contexts with every request, e.g. "admin", 2: contexts the file was seen in. */
			return sprintf( __( 'every %1$s request · seen in: %2$s', 'upshift' ), Recorder::label( $row['always_in'] ), $label );
		}
		return $label;
	}

	/**
	 * Sanitized filters from a request array (allow-lists, spec §11).
	 *
	 * @param array $input Raw input (already unslashed).
	 * @return array component, bucket, category, severity.
	 */
	public static function filters( array $input ) {
		$input = array_filter( $input, 'is_scalar' );
		$out   = array(
			'component' => '',
			'bucket'    => '',
			'category'  => self::COUNTED_FILTER,
			'severity'  => '',
		);
		if ( isset( $input['component'] ) && preg_match( '/^[a-z0-9:._@\/-]{1,191}$/i', (string) $input['component'] ) ) {
			$out['component'] = (string) $input['component'];
		}
		if ( isset( $input['bucket'] ) && in_array( (string) $input['bucket'], self::buckets(), true ) ) {
			$out['bucket'] = (string) $input['bucket'];
		}
		if ( isset( $input['category'] ) && in_array( (string) $input['category'], array_merge( array( self::COUNTED_FILTER, self::CURRENT_FILTER, self::GUARDED_FILTER, 'all' ), Categories::all() ), true ) ) {
			$out['category'] = (string) $input['category'];
		}
		if ( isset( $input['severity'] ) && in_array( (string) $input['severity'], array( 'error', 'warning' ), true ) ) {
			$out['severity'] = (string) $input['severity'];
		}
		return $out;
	}

	/**
	 * Applies filters to rows from findings(), keeping the order. "counted" and a single category show only findings
	 * that are new for the upgrade; "current" shows the ones already true on the "from" version; "all" shows every row.
	 *
	 * @param array $rows    Rows.
	 * @param array $filters From filters().
	 * @return array
	 */
	public static function apply( array $rows, array $filters ) {
		$counted = self::counted_categories();
		$special = array( self::COUNTED_FILTER, self::CURRENT_FILTER, self::GUARDED_FILTER, 'all' );
		$out     = array();
		foreach ( $rows as $row ) {
			$guarded = ! empty( $row['guarded'] );
			if ( ( '' !== $filters['component'] && $row['component'] !== $filters['component'] )
				|| ( '' !== $filters['bucket'] && $row['bucket'] !== $filters['bucket'] )
				|| ( '' !== $filters['severity'] && $row['severity'] !== $filters['severity'] )
				|| ( self::COUNTED_FILTER === $filters['category'] && ( ! in_array( $row['category'], $counted, true ) || ! empty( $row['baseline'] ) || $guarded ) )
				|| ( self::CURRENT_FILTER === $filters['category'] && ( empty( $row['baseline'] ) || $guarded ) )
				|| ( self::GUARDED_FILTER === $filters['category'] && ! $guarded )
				|| ( ! in_array( $filters['category'], $special, true ) && ( $row['category'] !== $filters['category'] || ! empty( $row['baseline'] ) || $guarded ) )
			) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Messages for finding ids.
	 *
	 * @param int[] $ids Finding ids.
	 * @return array id => message.
	 */
	public static function messages( array $ids ) {
		global $wpdb;

		$out   = array();
		$table = Install::table( 'findings' );
		foreach ( array_chunk( array_map( 'intval', $ids ), self::MESSAGE_CHUNK ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is only %d placeholders.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, message FROM {$table} WHERE id IN ({$in})", $chunk ), ARRAY_A ) as $row ) {
				$out[ (int) $row['id'] ] = $row['message'];
			}
		}
		return $out;
	}

	/**
	 * CSV/formula injection guard: a cell starting with = + - @ tab or CR gets a leading apostrophe.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Spike metrics (spec §7.8, §2): counted findings, buckets, components, usage, overhead, scan stats, M1 / M3a / M4.
	 *
	 * @return array
	 */
	public static function metrics() {
		// Reads the last swapped rollup only; it never rolls up (DECISIONS D48).
		$meta          = Rollup::meta();
		$scan          = Runner::latest( 'done' );
		$m             = array(
			'generated' => current_time( 'mysql' ),
			'site'      => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'php'       => PHP_VERSION,
			'scan'      => null,
			'findings'  => null,
			'usage'     => self::usage_metrics( $meta ),
			'm1'        => array(
				'result' => self::NOT_MEASURED,
				'note'   => __( 'No completed scan.', 'upshift' ),
			),
			'm3a'       => null,
			'm4'        => array(
				'result' => self::NOT_MEASURED,
				'note'   => __( 'No completed scan.', 'upshift' ),
			),
		);
		$m['m3a']      = self::m3a( $m['usage'] );
		$m['m3a_info'] = self::m3a_info( $m['usage'] );

		if ( null === $scan ) {
			return $m;
		}
		$m['scan']                = self::scan_metrics( $scan );
		$m['m1']                  = self::m1( $m['scan'] );
		$m['findings']            = self::finding_metrics( self::findings( (int) $scan['id'] ) );
		$m['findings']['checked'] = $m['scan']['checked'];
		$m['headline']            = self::headline( $m['findings']['by_category'], $scan['from_version'], $scan['target_version'], $m['scan']['checked'], $m['scan']['code_unchecked_active'] );
		$m['m4']                  = self::m4( $m['findings'], $m['usage'] );
		return $m;
	}

	/**
	 * Usage part of the metrics.
	 *
	 * @param array|false $meta Rollup meta.
	 * @return array
	 */
	private static function usage_metrics( $meta ) {
		$out = array(
			'rollup'              => false !== $meta,
			'days_observed'       => 0,
			'first_day'           => '',
			'last_day'            => '',
			'sampled_requests'    => 0,
			'requests_by_context' => array(),
			'filesets'            => 0,
			'avg_prep_ms'         => 0.0,
			'max_prep_ms'         => 0.0,
			'slow_hits'           => 0,
			'slow_share'          => 0.0,
		);
		if ( false === $meta ) {
			return array_merge( $out, self::relative_overhead( array() ) );
		}
		foreach ( $meta['requests'] as $bit => $count ) {
			$label = Recorder::label( $bit );
			$name  = '' !== $label ? $label : (string) $bit;

			$out['requests_by_context'][ $name ] = (int) $count;
		}
		$overhead                = $meta['overhead'];
		$out['days_observed']    = (int) $meta['days_observed'];
		$out['first_day']        = (string) $meta['first_day'];
		$out['last_day']         = (string) $meta['last_day'];
		$out['sampled_requests'] = (int) $meta['sampled_requests'];
		$out['filesets']         = (int) $meta['filesets'];
		$out['max_prep_ms']      = (float) $overhead['prep_ms_max'];
		$out['slow_hits']        = (int) $overhead['slow_hits'];
		if ( $overhead['hits'] > 0 ) {
			$out['avg_prep_ms'] = $overhead['prep_ms_total'] / $overhead['hits'];
			$out['slow_share']  = $overhead['slow_hits'] / $overhead['hits'];
		}
		return array_merge( $out, self::relative_overhead( $overhead ) );
	}

	/**
	 * Recorder prep as a share of request time (DECISIONS D38), from the rollup histogram. Median and p95
	 * are histogram buckets (nearest rank), shown as a range such as "1–2%".
	 *
	 * @param array $overhead Rollup meta 'overhead'.
	 * @return array req_hits, avg_req_ms, rel_median, rel_p95 (labels), rel_median_bucket, rel_p95_bucket (-1 = none).
	 */
	public static function relative_overhead( array $overhead ) {
		$hist = isset( $overhead['ovh_hist'] ) ? array_map( 'intval', (array) $overhead['ovh_hist'] ) : array();
		$hits = isset( $overhead['req_hits'] ) ? (int) $overhead['req_hits'] : 0;
		$med  = self::hist_rank( $hist, 0.5 );
		$p95  = self::hist_rank( $hist, 0.95 );
		return array(
			'req_hits'          => $hits,
			'avg_req_ms'        => $hits > 0 ? (float) $overhead['req_ms_total'] / $hits : 0.0,
			'rel_median_bucket' => $med,
			'rel_p95_bucket'    => $p95,
			'rel_median'        => self::bucket_label( $med ),
			'rel_p95'           => self::bucket_label( $p95 ),
		);
	}

	/**
	 * Bucket index holding the nearest-rank quantile $q, or -1 for an empty histogram.
	 *
	 * @param int[] $hist Counts per bucket.
	 * @param float $q    Quantile (0–1).
	 * @return int
	 */
	private static function hist_rank( array $hist, $q ) {
		$total = array_sum( $hist );
		if ( $total <= 0 ) {
			return -1;
		}
		$rank = max( 1, (int) ceil( $q * $total ) );
		$seen = 0;
		foreach ( $hist as $i => $count ) {
			$seen += $count;
			if ( $seen >= $rank ) {
				return (int) $i;
			}
		}
		return count( $hist ) - 1;
	}

	/**
	 * Label of an overhead bucket: "0–0.5%", "1–2%", "≥ 10%"; "-" for none.
	 *
	 * @param int $bucket Bucket index.
	 * @return string
	 */
	private static function bucket_label( $bucket ) {
		$edges = Recorder::OVERHEAD_EDGES;
		if ( $bucket < 0 ) {
			return '-';
		}
		if ( $bucket >= count( $edges ) ) {
			return '≥ ' . $edges[ count( $edges ) - 1 ] . '%';
		}
		return ( 0 === $bucket ? '0' : $edges[ $bucket - 1 ] ) . '–' . $edges[ $bucket ] . '%';
	}

	/**
	 * M3a (spec §2, DECISIONS D38): median recorder prep ≤ 2% and p95 ≤ 5% of request time on live sites.
	 * With bucket edges at 2 and 5 the check is exact: the median bucket must end at ≤ 2%.
	 *
	 * @param array $usage usage_metrics().
	 * @return array result, note.
	 */
	private static function m3a( array $usage ) {
		if ( $usage['req_hits'] <= 0 ) {
			return array(
				'result' => self::NOT_MEASURED,
				'note'   => __( 'No sampled requests with a request time yet (recorded from DB version 2 on).', 'upshift' ),
			);
		}
		$edges  = Recorder::OVERHEAD_EDGES;
		$med_ok = $usage['rel_median_bucket'] < count( $edges ) && $edges[ $usage['rel_median_bucket'] ] <= self::REL_MEDIAN_MAX;
		$p95_ok = $usage['rel_p95_bucket'] < count( $edges ) && $edges[ $usage['rel_p95_bucket'] ] <= self::REL_P95_MAX;
		return array(
			'result' => $med_ok && $p95_ok ? self::PASS : self::FAIL,
			'note'   => sprintf(
				/* translators: 1: median range, 2: p95 range, 3: requests, 4: average request ms, 5: median limit, 6: p95 limit. */
				__( 'Prep / request time: median %1$s, p95 %2$s over %3$s requests (avg request %4$s ms). Limit: median ≤ %5$s%%, p95 ≤ %6$s%%.', 'upshift' ),
				$usage['rel_median'],
				$usage['rel_p95'],
				number_format_i18n( $usage['req_hits'] ),
				number_format_i18n( $usage['avg_req_ms'], 1 ),
				number_format_i18n( self::REL_MEDIAN_MAX ),
				number_format_i18n( self::REL_P95_MAX )
			),
		);
	}

	/**
	 * Information next to M3a (not gated): absolute recorder cost per sampled request = prep median + DB-write
	 * median from the last benchmark run (Usage tab button or bench-recorder), and the old 3 ms share.
	 *
	 * @param array $usage usage_metrics().
	 * @return string
	 */
	private static function m3a_info( array $usage ) {
		$parts = array();
		$bench = Bench::last();
		if ( is_array( $bench ) && isset( $bench['stats']['prep']['median'], $bench['stats']['db']['median'] ) && $bench['iterations'] > 0 ) {
			$prep    = (float) $bench['stats']['prep']['median'];
			$db      = (float) $bench['stats']['db']['median'];
			$parts[] = sprintf(
				/* translators: 1: total ms, 2: prep ms, 3: DB write ms, 4: human time difference. */
				__( 'Absolute cost per sampled request ≈ %1$s ms (prep median %2$s + DB-write median %3$s, benchmark %4$s ago).', 'upshift' ),
				number_format_i18n( $prep + $db, 2 ),
				number_format_i18n( $prep, 2 ),
				number_format_i18n( $db, 2 ),
				human_time_diff( (int) $bench['time'] )
			);
		} else {
			$parts[] = __( 'Absolute cost: no benchmark run yet (Usage tab → Run recorder benchmark).', 'upshift' );
		}
		if ( $usage['sampled_requests'] > 0 ) {
			/* translators: 1: percent, 2: number of sampled requests. */
			$parts[] = sprintf( __( '%1$s%% of %2$s sampled requests had prep ≥ 3 ms (old absolute rule, information).', 'upshift' ), number_format_i18n( 100 * $usage['slow_share'], 1 ), number_format_i18n( $usage['sampled_requests'] ) );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Scan part of the metrics.
	 *
	 * @param array $scan Scan row.
	 * @return array
	 */
	private static function scan_metrics( array $scan ) {
		$summary = Runner::summary( (int) $scan['id'] );
		$stats   = $summary['stats'];
		$status  = $summary['statuses'];

		// memory_limit as recorded during the scan's batches (after wp_raise_memory_limit): the scan row (DB v4), else
		// the latest-scan stats option when it still belongs to this scan (older scans), else the current limit.
		if ( ! empty( $scan['memory_limit'] ) ) {
			$recorded = array(
				'memory_limit' => (int) $scan['memory_limit'],
				'memory_ini'   => (string) $scan['memory_ini'],
				'sapi'         => (string) $scan['sapi'],
			);
		} elseif ( null !== $stats && ! empty( $stats['memory_limit'] ) ) {
			$recorded = array(
				'memory_limit' => (int) $stats['memory_limit'],
				'memory_ini'   => isset( $stats['memory_ini'] ) ? (string) $stats['memory_ini'] : '',
				'sapi'         => isset( $stats['sapi'] ) ? (string) $stats['sapi'] : '',
			);
		} else {
			$recorded = null;
		}
		$limit    = null !== $recorded ? $recorded['memory_limit'] : $summary['memory_limit'];
		$finished = '' !== (string) $scan['finished'] ? strtotime( $scan['finished'] ) : 0;

		return array(
			'id'                    => (int) $scan['id'],
			'range'                 => $scan['from_version'] . '-' . $scan['target_version'],
			'finished'              => (string) $scan['finished'],
			'files'                 => (int) $scan['files_total'],
			'scanned'               => isset( $status['done'] ) ? (int) $status['done'] : 0,
			'skipped'               => ( isset( $status['skipped_large'] ) ? (int) $status['skipped_large'] : 0 ) + ( isset( $status['unreadable'] ) ? (int) $status['unreadable'] : 0 ),
			// Not fully checked (Internal.Exception, DECISIONS D52) counts like an engine error.
			'engine_errors'         => ( isset( $status['engine_error'] ) ? (int) $status['engine_error'] : 0 ) + ( isset( $status['partial'] ) ? (int) $status['partial'] : 0 ),
			// Harmless data files vs code files not checked, and whether inactive plugins were scanned (DECISIONS D54).
			'data_skipped'          => $summary['file_checks']['data'],
			'code_unchecked'        => $summary['file_checks']['code'],
			'code_unchecked_active' => $summary['file_checks']['code_active'],
			'inactive_scanned'      => $summary['inactive_scanned'],
			'seconds'               => $finished > 0 ? $finished - strtotime( $scan['started'] ) : 0,
			'engine_seconds'        => (float) $scan['engine_seconds'],
			// New-risk check (DECISIONS D52): -1 = scan from before DB v4, not checked.
			'checked'               => (float) $scan['baseline_seconds'] >= 0,
			'baseline_seconds'      => max( 0.0, (float) $scan['baseline_seconds'] ),
			'batches'               => (int) $scan['batches'],
			'peak_memory'           => (int) $scan['peak_memory'],
			'memory_limit'          => $limit,
			'memory_limit_recorded' => null !== $recorded,
			'sapi'                  => null !== $recorded ? $recorded['sapi'] : '',
			'unlimited'             => null !== $recorded && '-1' === trim( $recorded['memory_ini'] ),
		);
	}

	/**
	 * Findings part of the metrics. Findings already true on the "from" version (current), newer_than_current,
	 * advisory, ignored and inactive components are counted separately, never in "counted". by_category holds the
	 * counted findings per tier category (removed = will break, needs_extension, deprecated, changed).
	 *
	 * @param array $rows Rows from findings().
	 * @return array
	 */
	private static function finding_metrics( array $rows ) {
		$out = array(
			'total'              => count( $rows ),
			'counted'            => 0,
			'by_bucket'          => array(
				self::ALWAYS    => 0,
				self::SOMETIMES => 0,
				self::NEVER     => 0,
			),
			'by_category'        => array_fill_keys( self::counted_categories(), 0 ),
			'current'            => 0,
			'guarded'            => 0,
			'newer_than_current' => 0,
			'advisory'           => 0,
			'ignored'            => 0,
			'inactive'           => 0,
			'components'         => array(),
		);
		foreach ( $rows as $row ) {
			if ( ! $row['active'] ) {
				++$out['inactive'];
				continue;
			}
			if ( ! self::is_counted( $row ) ) {
				// Active, not counted: guarded, already true on the from version, or an information category.
				if ( ! empty( $row['guarded'] ) ) {
					++$out['guarded'];
				} elseif ( ! empty( $row['baseline'] ) ) {
					++$out['current'];
				} elseif ( isset( $out[ $row['category'] ] ) ) {
					++$out[ $row['category'] ];
				}
				continue;
			}
			++$out['counted'];
			++$out['by_bucket'][ $row['bucket'] ];
			++$out['by_category'][ $row['category'] ];
			$id = $row['component'];
			if ( ! isset( $out['components'][ $id ] ) ) {
				$out['components'][ $id ] = array(
					'counted'       => 0,
					self::ALWAYS    => 0,
					self::SOMETIMES => 0,
					self::NEVER     => 0,
				);
			}
			++$out['components'][ $id ]['counted'];
			++$out['components'][ $id ][ $row['bucket'] ];
		}
		uasort(
			$out['components'],
			function ( $a, $b ) {
				return $b['counted'] - $a['counted'];
			}
		);
		$out['components'] = array_slice( $out['components'], 0, 10, true );
		$out['not_always'] = $out['by_bucket'][ self::SOMETIMES ] + $out['by_bucket'][ self::NEVER ];
		$out['share']      = $out['counted'] > 0 ? $out['not_always'] / $out['counted'] : 0.0;
		return $out;
	}

	/**
	 * M1: the scan finished (no fatal, no timeout) with peak memory under 75% of memory_limit.
	 *
	 * Uses the limit recorded at scan time (after wp_raise_memory_limit). Not measured when it wasn't recorded, or
	 * for a WP-CLI scan with memory_limit -1 (WP-CLI's own setting, not the host's). A web scan under -1 (the host
	 * allows unlimited memory) is measured against the limit the scan budgeted with (1 GB, spec §7.6; DECISIONS D52).
	 *
	 * @param array $scan scan_metrics().
	 * @return array result, note.
	 */
	private static function m1( array $scan ) {
		$share = $scan['memory_limit'] > 0 ? $scan['peak_memory'] / $scan['memory_limit'] : 1.0;
		/* translators: 1: scan id, 2: peak memory MB, 3: percent of memory_limit, 4: memory_limit MB. */
		$note = sprintf( __( 'Scan #%1$d finished; peak memory %2$s MB, %3$s%% of memory_limit %4$s MB.', 'upshift' ), $scan['id'], number_format_i18n( $scan['peak_memory'] / 1048576, 1 ), number_format_i18n( 100 * $share, 1 ), number_format_i18n( $scan['memory_limit'] / 1048576 ) );
		if ( ! $scan['memory_limit_recorded'] || ( $scan['unlimited'] && 'cli' === $scan['sapi'] ) ) {
			return array(
				'result' => self::NOT_MEASURED,
				'note'   => sprintf(
					/* translators: 1: scan id, 2: peak memory MB. */
					__( 'Scan #%1$d finished; peak memory %2$s MB, but its memory_limit was not recorded (scan from before T6, or a later scan replaced the record) or it ran in WP-CLI with memory_limit -1. Run a scan from the Scan tab to measure M1 with the host limits.', 'upshift' ),
					$scan['id'],
					number_format_i18n( $scan['peak_memory'] / 1048576, 1 )
				),
			);
		}
		if ( $scan['unlimited'] ) {
			$note .= ' ' . __( 'memory_limit was -1 (unlimited) during the scan; the scan budgeted with this limit.', 'upshift' );
		}
		return array(
			'result' => $share < self::M1_MEMORY_SHARE ? self::PASS : self::FAIL,
			'note'   => $note,
		);
	}


	/**
	 * M4: share of counted findings in files that are not always loaded ≥ 30%, with ≥ 20 counted findings,
	 * ≥ 500 sampled requests and ≥ 5 days observed (else inconclusive). A scan from before the new-risk check also
	 * counts findings already true on the "from" version, so it is inconclusive until rescanned (DECISIONS D52).
	 *
	 * @param array $findings finding_metrics().
	 * @param array $usage    usage_metrics().
	 * @return array result, note.
	 */
	private static function m4( array $findings, array $usage ) {
		$share      = $findings['share'];
		$not_always = $findings['not_always'];
		/* translators: 1: percent, 2: findings not always loaded, 3: counted findings. */
		$note    = sprintf( __( '%1$s%% not always loaded (%2$s of %3$s counted findings).', 'upshift' ), number_format_i18n( 100 * $share, 1 ), number_format_i18n( $not_always ), number_format_i18n( $findings['counted'] ) );
		$reasons = array();
		if ( empty( $findings['checked'] ) ) {
			$reasons[] = __( 'the scan was made before the new-risk check, so a new scan is needed', 'upshift' );
		}
		if ( $findings['counted'] < self::MIN_COUNTED ) {
			/* translators: %d: minimum. */
			$reasons[] = sprintf( __( 'under %d counted findings', 'upshift' ), self::MIN_COUNTED );
		}
		if ( $usage['sampled_requests'] < self::MIN_SAMPLED ) {
			/* translators: %d: minimum. */
			$reasons[] = sprintf( __( 'under %d sampled requests', 'upshift' ), self::MIN_SAMPLED );
		}
		if ( $usage['days_observed'] < self::MIN_DAYS ) {
			/* translators: %d: minimum. */
			$reasons[] = sprintf( __( 'under %d days observed', 'upshift' ), self::MIN_DAYS );
		}
		if ( $reasons ) {
			return array(
				'result' => self::INCONCLUSIVE,
				'note'   => $note . ' ' . implode( '; ', $reasons ) . '.',
			);
		}
		return array(
			'result' => $share >= self::M4_SHARE ? self::PASS : self::FAIL,
			'note'   => $note,
		);
	}

	/**
	 * Shown when a scan has no file of an inactive component (Runner::inactive_scanned(); DECISIONS D54).
	 *
	 * @return string
	 */
	public static function inactive_note() {
		return __( 'Inactive plugins were not scanned (the Scan tab scans active plugins unless "Also scan inactive plugins" is ticked), or the site has none. This report says nothing about problems in inactive plugins.', 'upshift' );
	}

	/**
	 * Result label with an icon.
	 *
	 * @param string $result pass | fail | inconclusive | n/a.
	 * @return string
	 */
	public static function result_label( $result ) {
		switch ( $result ) {
			case self::PASS:
				return '✅ ' . __( 'PASS', 'upshift' );
			case self::FAIL:
				return '❌ ' . __( 'FAIL', 'upshift' );
			case self::INCONCLUSIVE:
				return '⚠️ ' . __( 'INCONCLUSIVE', 'upshift' );
			default:
				return '— ' . __( 'not measured', 'upshift' );
		}
	}

	/**
	 * Metrics as Markdown for docs/SPIKE-RESULT.md (spec Appendix A).
	 *
	 * @param array $m metrics().
	 * @return string
	 */
	public static function markdown( array $m ) {
		$u   = $m['usage'];
		$pct = function ( $part, $whole ) {
			return $whole > 0 ? number_format( 100 * $part / $whole, 1 ) . '%' : '-';
		};

		$lines   = array();
		$lines[] = sprintf( '## Upshift spike metrics: %s (%s)', $m['site'], $m['generated'] );
		$lines[] = '';
		if ( isset( $m['headline'] ) ) {
			$lines[] = '**' . $m['headline'] . '**';
			$lines[] = '';
		}
		if ( null !== $m['scan'] && ! $m['scan']['inactive_scanned'] ) {
			$lines[] = self::inactive_note();
			$lines[] = '';
		}
		$lines[] = '| Metric | Result | Detail |';
		$lines[] = '|---|---|---|';
		$lines[] = sprintf( '| M1 Engine works on this host | %s | %s |', self::result_label( $m['m1']['result'] ), $m['m1']['note'] );
		$lines[] = sprintf( '| M3a Recorder overhead (prep median ≤ 2%%, p95 ≤ 5%% of request time) | %s | %s |', self::result_label( $m['m3a']['result'] ), $m['m3a']['note'] );
		$lines[] = sprintf( '| M3a information (not gated) | | %s |', $m['m3a_info'] );
		$lines[] = sprintf( '| M4 Signal: always = whole site (≥ 30%% not always loaded) | %s | %s |', self::result_label( $m['m4']['result'] ), $m['m4']['note'] );
		$lines[] = '';
		$lines[] = 'M2 (selftest), M3b (Usage tab benchmark button) and M5 (your judgement) are not computed here.';
		$lines[] = '';
		$lines[] = '| Row (Appendix A) | Value |';
		$lines[] = '|---|---|';
		$lines[] = sprintf( '| Host / PHP / memory_limit / max_execution_time | %s / %s / %s / %s |', $m['site'], $m['php'], (string) ini_get( 'memory_limit' ), (string) ini_get( 'max_execution_time' ) );
		$lines[] = sprintf( '| Days observed / sampled requests | %d (%s to %s) / %d |', $u['days_observed'], $u['first_day'], $u['last_day'], $u['sampled_requests'] );
		if ( null !== $m['findings'] ) {
			$f       = $m['findings'];
			$b       = $f['by_bucket'];
			$lines[] = sprintf( '| Counted findings (active, new for this upgrade) | %d (will break %d, needs a PHP extension %d, deprecation notices %d, behaviour changes %d) |', $f['counted'], $f['by_category']['removed'], $f['by_category']['needs_extension'], $f['by_category']['deprecated'], $f['by_category']['changed'] );
			$lines[] = sprintf( '| %% always / sometimes / never (always = whole site) | %s / %s / %s |', $pct( $b['always'], $f['counted'] ), $pct( $b['sometimes'], $f['counted'] ), $pct( $b['never'], $f['counted'] ) );
			$lines[] = sprintf( '| Not counted: guarded / already true on the from version / newer_than_current / advisory / ignored (information) / inactive components | %d / %s / %d / %d / %d / %s |', $f['guarded'], $f['checked'] ? (string) $f['current'] : 'not checked (old scan)', $f['newer_than_current'], $f['advisory'], $f['ignored'], null !== $m['scan'] && ! $m['scan']['inactive_scanned'] ? 'not scanned' : (string) $f['inactive'] );
		}
		if ( null !== $m['scan'] ) {
			$s       = $m['scan'];
			$lines[] = sprintf( '| M1 scan (duration, peak memory) | #%d %s, %d files (%d skipped, %d engine errors), %d s in %d batches, peak %.1f MB of %.1f MB (%s)%s |', $s['id'], $s['range'], $s['files'], $s['skipped'], $s['engine_errors'], $s['seconds'], $s['batches'], $s['peak_memory'] / 1048576, $s['memory_limit'] / 1048576, $pct( $s['peak_memory'], $s['memory_limit'] ), '' !== $s['sapi'] ? ', ' . $s['sapi'] : '' );
			$lines[] = sprintf( '| Code files not checked: active / all; data files skipped (harmless) | %d / %d; %d |', $s['code_unchecked_active'], $s['code_unchecked'], $s['data_skipped'] );
			$lines[] = sprintf( '| Inactive plugins | %s |', $s['inactive_scanned'] ? 'scanned (not counted)' : 'not scanned' );
			$lines[] = sprintf( '| New-risk check (engine s / extra s for the from-only re-scan) | %s |', $s['checked'] ? sprintf( '%.1f / %.1f', $s['engine_seconds'], $s['baseline_seconds'] ) : 'not run (scan from before T7.5)' );
		}
		$lines[] = sprintf( '| M3a slow share / avg prep ms / max prep ms | %s / %.3f / %.3f |', $pct( $u['slow_hits'], $u['sampled_requests'] ), $u['avg_prep_ms'], $u['max_prep_ms'] );
		$lines[] = sprintf( '| Prep as %% of request time: median / p95 (requests, avg request ms) | %s / %s (%d, %.1f) |', $u['rel_median'], $u['rel_p95'], $u['req_hits'], $u['avg_req_ms'] );
		$lines[] = sprintf( '| Filesets | %d |', $u['filesets'] );
		$by_ctx  = array();
		foreach ( $u['requests_by_context'] as $label => $count ) {
			$by_ctx[] = $label . ' ' . $count;
		}
		$lines[] = sprintf( '| Sampled requests by context | %s |', $by_ctx ? implode( ', ', $by_ctx ) : '-' );

		if ( null !== $m['findings'] && $m['findings']['components'] ) {
			$lines[] = '';
			$lines[] = 'Top components (always = whole site):';
			$lines[] = '';
			$lines[] = '| Component | Counted | Always | Sometimes | Never |';
			$lines[] = '|---|---|---|---|---|';
			foreach ( $m['findings']['components'] as $id => $c ) {
				$lines[] = sprintf( '| %s | %d | %d | %d | %d |', $id, $c['counted'], $c['always'], $c['sometimes'], $c['never'] );
			}
		}
		return implode( "\n", $lines ) . "\n";
	}
}
