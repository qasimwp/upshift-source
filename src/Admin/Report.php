<?php
/**
 * Findings × Usage tab, Spike metrics tab and the CSV export (spec §7.9).
 *
 * @package Upshift
 */

namespace Upshift\Admin;

use Upshift\Report\Explain;
use Upshift\Report\Join;
use Upshift\Scan\Categories;
use Upshift\Scan\Runner;
use Upshift\Usage\Rollup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report tabs. Rendered from Page::render(), which checks the capability first.
 */
class Report {

	const PER_PAGE = 50;
	const EXPORT   = 'upshift_export_csv';

	/**
	 * Findings × Usage: filters, priority-sorted table (50 per page), CSV export.
	 */
	public static function render_findings() {
		$scan = Runner::latest( 'done' );
		if ( null === $scan ) {
			echo '<p>' . esc_html__( 'No completed scan yet. Run one from the Scan tab.', 'upshift' ) . '</p>';
			return;
		}
		$meta = Page::render_rollup_status();
		$days = false !== $meta ? (int) $meta['days_observed'] : 0;

		// Read-only view filters, validated against allow-lists; no state change, so no nonce.
		$filters = Join::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is allow-listed in Join::filters().
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$all  = Join::findings( (int) $scan['id'] );
		$rows = Join::apply( $all, $filters );

		$counted    = 0;
		$tiers      = array_fill_keys( Join::counted_categories(), 0 );
		$current    = 0;
		$guarded    = 0;
		$info       = array_fill_keys( array( Categories::NEWER_THAN_CURRENT, Categories::ADVISORY, Categories::IGNORED ), 0 );
		$inactive   = 0;
		$components = array();
		foreach ( $all as $row ) {
			$components[ $row['component'] ] = true;
			if ( ! $row['active'] ) {
				++$inactive;
			} elseif ( Join::is_counted( $row ) ) {
				++$counted;
				++$tiers[ $row['category'] ];
			} elseif ( ! empty( $row['guarded'] ) ) {
				++$guarded;
			} elseif ( ! empty( $row['baseline'] ) ) {
				++$current;
			} elseif ( isset( $info[ $row['category'] ] ) ) {
				++$info[ $row['category'] ];
			}
		}
		ksort( $components );
		$checked          = (float) $scan['baseline_seconds'] >= 0;
		$file_checks      = Runner::file_checks( (int) $scan['id'] );
		$inactive_scanned = Runner::inactive_scanned( (int) $scan['id'] );

		echo '<p><strong>' . esc_html( Join::headline( $tiers, $scan['from_version'], $scan['target_version'], $checked, $file_checks['code_active'] ) ) . '</strong></p>';
		if ( ! $inactive_scanned ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( Join::inactive_note() ) . '</p></div>';
		}
		/* translators: 1: scan id, 2: range, 3: finished date. */
		echo '<p>' . esc_html( sprintf( __( 'Scan #%1$d (%2$s), finished %3$s. Sorted by priority (severity × category × usage).', 'upshift' ), $scan['id'], $scan['from_version'] . '-' . $scan['target_version'], $scan['finished'] ) ) . '</p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: counted findings, 2: from PHP version, 3: findings already true on it, 4: newer_than_current findings, 5: advisory findings, 6: ignored findings, 7: findings in inactive components, 8: guarded findings. */
				__( 'Counted problems (active components; removed, needs extension, deprecated, changed; new for this upgrade): %1$s. Not counted: %8$s guarded (fallback code that cannot run on the target PHP, or only runs when the feature exists), %3$s already true on PHP %2$s (information: reported for PHP %2$s alone too, so the code is already guarded, unused or noticed today), %4$s newer_than_current (information: the code uses features newer than the "from" version, usually guarded or polyfilled), %5$s advisory (information: naming advice, heuristics and ini-dependent notes that break nothing by themselves), %6$s ignored (not a PHP version issue, e.g. mixed line endings) and %7$s in inactive components.', 'upshift' ),
				number_format_i18n( $counted ),
				$scan['from_version'],
				$checked ? number_format_i18n( $current ) : __( '(not checked in this scan)', 'upshift' ),
				number_format_i18n( $info[ Categories::NEWER_THAN_CURRENT ] ),
				number_format_i18n( $info[ Categories::ADVISORY ] ),
				number_format_i18n( $info[ Categories::IGNORED ] ),
				$inactive_scanned ? number_format_i18n( $inactive ) : __( '(not scanned)', 'upshift' ),
				number_format_i18n( $guarded )
			)
		) . '</p>';
		echo '<p class="description">' . esc_html__( '"Not seen" means not seen during the observation period, not "never runs": a yearly cron job or a rarely used screen may not appear.', 'upshift' ) . '</p>';
		if ( $file_checks['code_active'] > 0 ) {
			/* translators: %s: number of files. */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( _n( '%s active code file was not checked (too large, unreadable, engine error, or PHPCS stopped inside it), so problems in it can be missing here. The Scan tab lists it.', '%s active code files were not checked (too large, unreadable, engine error, or PHPCS stopped inside them), so problems in them can be missing here. The Scan tab lists them.', $file_checks['code_active'], 'upshift' ), number_format_i18n( $file_checks['code_active'] ) ) ) . '</p></div>';
		}
		if ( $file_checks['data'] > 0 ) {
			/* translators: %s: number of files. */
			echo '<p class="description">' . esc_html( sprintf( _n( '%s data file was skipped (harmless: only arrays or a classmap, nothing a PHP version can break).', '%s data files were skipped (harmless: only arrays or classmaps, nothing a PHP version can break).', $file_checks['data'], 'upshift' ), number_format_i18n( $file_checks['data'] ) ) ) . '</p>';
		}

		self::render_filters( $filters, array_keys( $components ), $scan['from_version'], $scan['target_version'] );

		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$slice = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$msgs  = Join::messages( wp_list_pluck( $slice, 'id' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:1em 0">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT ) . '" />';
		foreach ( $filters as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}
		wp_nonce_field( self::EXPORT );
		/* translators: %s: number of findings. */
		submit_button( sprintf( __( 'Export CSV (%s findings, current filters)', 'upshift' ), number_format_i18n( $total ) ), 'secondary', 'submit', false );
		echo '</form>';

		if ( 0 === $total ) {
			echo '<p>' . esc_html__( 'No findings match these filters.', 'upshift' ) . '</p>';
			return;
		}

		$pagination = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			)
		);
		/* translators: 1: first row number, 2: last row number, 3: total rows. */
		$range = sprintf( __( '%1$s–%2$s of %3$s', 'upshift' ), number_format_i18n( ( $paged - 1 ) * self::PER_PAGE + 1 ), number_format_i18n( ( $paged - 1 ) * self::PER_PAGE + count( $slice ) ), number_format_i18n( $total ) );
		echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . esc_html( $range ) . '</span> ' . wp_kses_post( (string) $pagination ) . '</div></div>';

		$heads = array(
			__( 'Priority', 'upshift' ),
			__( 'Component', 'upshift' ),
			__( 'File:line', 'upshift' ),
			__( 'Category', 'upshift' ),
			__( 'Severity', 'upshift' ),
			__( 'Rule', 'upshift' ),
			__( 'Message', 'upshift' ),
			__( 'Usage', 'upshift' ),
		);
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $heads as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $slice as $row ) {
			$label = Join::usage_label( $row, $days );
			echo '<tr>';
			echo '<td>' . esc_html( number_format_i18n( $row['priority'], 2 ) ) . '</td>';
			echo '<td>' . esc_html( $row['component'] ) . '</td>';
			echo '<td><code>' . esc_html( $row['path_key'] . ':' . $row['line'] ) . '</code></td>';
			$tier = Join::row_tier( $row, $scan['target_version'] );
			echo '<td>' . esc_html( $row['category'] ) . ( '' !== $tier ? '<br><small>' . esc_html( $tier ) . '</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( $row['severity'] ) . '</td>';
			echo '<td><code style="font-size:11px">' . esc_html( $row['rule'] ) . '</code></td>';
			echo '<td>' . esc_html( isset( $msgs[ $row['id'] ] ) ? $msgs[ $row['id'] ] : '' ) . '</td>';
			echo '<td><strong>' . esc_html( $row['bucket'] ) . '</strong>' . ( '' !== $label ? '<br>' . esc_html( $label ) : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) $pagination ) . '</div></div>';
	}

	/**
	 * Filter form (GET).
	 *
	 * @param array    $filters    Current filters.
	 * @param string[] $components Component ids in the scan.
	 * @param string   $from       From version.
	 * @param string   $target     Target version.
	 */
	private static function render_filters( array $filters, array $components, $from, $target ) {
		$selects = array(
			'component' => array( '' => __( 'All components', 'upshift' ) ) + array_combine( $components, $components ),
			'bucket'    => array( '' => __( 'All buckets', 'upshift' ) ) + array_combine( Join::buckets(), Join::buckets() ),
			'category'  => array(
				Join::COUNTED_FILTER           => __( 'Counted: new risks (removed, needs extension, deprecated, changed)', 'upshift' ),
				Categories::REMOVED            => Categories::REMOVED . ': ' . Join::tier_label( Categories::REMOVED, $target ),
				Categories::NEEDS_EXTENSION    => Categories::NEEDS_EXTENSION . ': ' . Join::tier_label( Categories::NEEDS_EXTENSION, $target ),
				Categories::DEPRECATED         => Categories::DEPRECATED . ': ' . Join::tier_label( Categories::DEPRECATED, $target ),
				Categories::CHANGED            => Categories::CHANGED . ': ' . Join::tier_label( Categories::CHANGED, $target ),
				/* translators: %s: from PHP version. */
				Join::CURRENT_FILTER           => sprintf( __( 'Already true on PHP %s (information)', 'upshift' ), $from ),
				Join::GUARDED_FILTER           => __( 'Guarded: only runs where it is safe (information)', 'upshift' ),
				Categories::NEWER_THAN_CURRENT => __( 'newer_than_current (information)', 'upshift' ),
				Categories::ADVISORY           => __( 'advisory (information)', 'upshift' ),
				Categories::IGNORED            => __( 'ignored: not a PHP version issue', 'upshift' ),
				'all'                          => __( 'All categories', 'upshift' ),
			),
			'severity'  => array(
				''        => __( 'All severities', 'upshift' ),
				'error'   => 'error',
				'warning' => 'warning',
			),
		);

		echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Page::SLUG ) . '" /><input type="hidden" name="tab" value="developer" /><input type="hidden" name="view" value="findings" />';
		foreach ( $selects as $name => $options ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			foreach ( $options as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters[ $name ], (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> ';
		}
		submit_button( __( 'Filter', 'upshift' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Admin-post: CSV of the findings matching the posted filters (capability + nonce first).
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'upshift' ), 403 );
		}
		check_admin_referer( self::EXPORT );

		$scan = Runner::latest( 'done' );
		if ( null === $scan ) {
			wp_die( esc_html__( 'No completed scan yet.', 'upshift' ) );
		}
		$filters = Join::filters( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is allow-listed in Join::filters().
		$rows    = Join::apply( Join::findings( (int) $scan['id'] ), $filters );
		$meta    = Rollup::meta();
		$days    = false !== $meta ? (int) $meta['days_observed'] : 0;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="upshift-findings-scan-' . (int) $scan['id'] . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming the download.
		// The escape argument is passed so PHP 8.4 doesn't deprecate the default; '' (RFC 4180) needs PHP 7.4.
		$escape = version_compare( PHP_VERSION, '7.4', '>=' ) ? '' : '\\';
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so Excel reads the encoding.
		fputcsv( $out, array( 'priority', 'component', 'file', 'line', 'category', 'tier', 'severity', 'rule', 'message', 'bucket', 'usage', 'problem', 'how_to_fix', 'impact' ), ',', '"', $escape );
		foreach ( array_chunk( $rows, Join::MESSAGE_CHUNK ) as $chunk ) {
			$msgs = Join::messages( wp_list_pluck( $chunk, 'id' ) );
			foreach ( $chunk as $row ) {
				$message = isset( $msgs[ $row['id'] ] ) ? $msgs[ $row['id'] ] : '';
				$explain = Explain::of( $row['rule'], $row['category'], $row['severity'] );
				$cells   = array(
					$row['priority'],
					$row['component'],
					$row['path_key'],
					$row['line'],
					$row['category'],
					Join::row_tier( $row, $scan['target_version'] ),
					$row['severity'],
					$row['rule'],
					$message,
					$row['bucket'],
					Join::usage_label( $row, $days ),
					$explain['problem'],
					$explain['how_to_fix'],
					! empty( $row['guarded'] ) ? Explain::guarded_impact() : $explain['impact'],
				);
				fputcsv( $out, array_map( array( Join::class, 'csv_cell' ), $cells ), ',', '"', $escape );
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Spike metrics: M1 / M3a / M4 with ✅/❌, the tables, and a "Copy as Markdown" textarea.
	 */
	public static function render_metrics() {
		Page::render_rollup_status();
		$m = Join::metrics();

		if ( isset( $m['headline'] ) ) {
			echo '<p><strong>' . esc_html( $m['headline'] ) . '</strong></p>';
		}
		if ( null !== $m['scan'] && ! $m['scan']['inactive_scanned'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( Join::inactive_note() ) . '</p></div>';
		}
		echo '<table class="widefat striped" style="max-width:60em"><thead><tr><th>' . esc_html__( 'Metric', 'upshift' ) . '</th><th>' . esc_html__( 'Result', 'upshift' ) . '</th><th>' . esc_html__( 'Detail', 'upshift' ) . '</th></tr></thead><tbody>';
		$gates = array(
			'm1'  => __( 'M1 Engine works on this host', 'upshift' ),
			'm3a' => __( 'M3a Recorder overhead (prep median ≤ 2%, p95 ≤ 5% of request time)', 'upshift' ),
			'm4'  => __( 'M4 Signal: always = whole site (≥ 30% of counted findings not always loaded)', 'upshift' ),
		);
		foreach ( $gates as $key => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td><strong>' . esc_html( Join::result_label( $m[ $key ]['result'] ) ) . '</strong></td><td>' . esc_html( $m[ $key ]['note'] ) . '</td></tr>';
			if ( 'm3a' === $key ) {
				echo '<tr><td style="padding-left:2em">' . esc_html__( 'M3a information (not gated)', 'upshift' ) . '</td><td></td><td>' . esc_html( $m['m3a_info'] ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'M2 (wp upshift selftest), M3b (the Usage tab benchmark button) and M5 (your judgement of the top 15 findings) are not computed here.', 'upshift' ) . '</p>';

		if ( null !== $m['findings'] ) {
			$f = $m['findings'];
			echo '<h2>' . esc_html__( 'Counted findings by bucket (always = whole site)', 'upshift' ) . '</h2>';
			$rows = array();
			foreach ( $f['by_bucket'] as $bucket => $n ) {
				$rows[] = array( $bucket, number_format_i18n( $n ) . ( $f['counted'] > 0 ? ' (' . number_format_i18n( 100 * $n / $f['counted'], 1 ) . '%)' : '' ) );
			}
			$rows[] = array( __( 'Counted total', 'upshift' ), number_format_i18n( $f['counted'] ) );
			/* translators: 1: will break, 2: needs a PHP extension, 3: deprecation notices, 4: behaviour changes. */
			$rows[] = array( __( 'Counted by tier', 'upshift' ), sprintf( __( 'will break %1$s, needs a PHP extension %2$s, deprecation notices %3$s, behaviour changes %4$s', 'upshift' ), number_format_i18n( $f['by_category'][ Categories::REMOVED ] ), number_format_i18n( $f['by_category'][ Categories::NEEDS_EXTENSION ] ), number_format_i18n( $f['by_category'][ Categories::DEPRECATED ] ), number_format_i18n( $f['by_category'][ Categories::CHANGED ] ) ) );
			$rows[] = array( __( 'Not counted: already true on the from version (information)', 'upshift' ), $f['checked'] ? number_format_i18n( $f['current'] ) : __( 'not checked (scan from before the new-risk check; run a new scan)', 'upshift' ) );
			$rows[] = array( __( 'Not counted: newer_than_current (information)', 'upshift' ), number_format_i18n( $f['newer_than_current'] ) );
			$rows[] = array( __( 'Not counted: advisory (information)', 'upshift' ), number_format_i18n( $f['advisory'] ) );
			$rows[] = array( __( 'Not counted: ignored (not a PHP version issue)', 'upshift' ), number_format_i18n( $f['ignored'] ) );
			$rows[] = array( __( 'Not counted: inactive components', 'upshift' ), null !== $m['scan'] && ! $m['scan']['inactive_scanned'] ? __( 'not scanned', 'upshift' ) : number_format_i18n( $f['inactive'] ) );
			self::key_value_table( $rows );

			if ( $f['components'] ) {
				echo '<h2>' . esc_html__( 'Top components by counted findings (always = whole site)', 'upshift' ) . '</h2>';
				echo '<table class="widefat striped" style="max-width:60em"><thead><tr>';
				foreach ( array( __( 'Component', 'upshift' ), __( 'Counted', 'upshift' ), Join::ALWAYS, Join::SOMETIMES, Join::NEVER ) as $head ) {
					echo '<th>' . esc_html( $head ) . '</th>';
				}
				echo '</tr></thead><tbody>';
				foreach ( $f['components'] as $id => $c ) {
					echo '<tr><td>' . esc_html( $id ) . '</td>';
					foreach ( array( 'counted', Join::ALWAYS, Join::SOMETIMES, Join::NEVER ) as $key ) {
						echo '<td>' . esc_html( number_format_i18n( $c[ $key ] ) ) . '</td>';
					}
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
		}

		$u = $m['usage'];
		echo '<h2>' . esc_html__( 'Usage and overhead', 'upshift' ) . '</h2>';
		$by_ctx = array();
		foreach ( $u['requests_by_context'] as $label => $count ) {
			$by_ctx[] = $label . ': ' . number_format_i18n( $count );
		}
		self::key_value_table(
			array(
				/* translators: 1: days, 2: first day, 3: last day. */
				array( __( 'Days observed', 'upshift' ), sprintf( __( '%1$s (%2$s to %3$s)', 'upshift' ), number_format_i18n( $u['days_observed'] ), $u['first_day'], $u['last_day'] ) ),
				array( __( 'Sampled requests', 'upshift' ), number_format_i18n( $u['sampled_requests'] ) . ( $by_ctx ? ' (' . implode( '; ', $by_ctx ) . ')' : '' ) ),
				array( __( 'Filesets', 'upshift' ), number_format_i18n( $u['filesets'] ) ),
				/* translators: 1: average ms, 2: max ms, 3: percent. */
				array( __( 'Recorder prep', 'upshift' ), sprintf( __( 'avg %1$s ms, max %2$s ms, %3$s%% of requests ≥ 3 ms', 'upshift' ), number_format_i18n( $u['avg_prep_ms'], 3 ), number_format_i18n( $u['max_prep_ms'], 3 ), number_format_i18n( 100 * $u['slow_share'], 2 ) ) ),
				/* translators: 1: median range, 2: p95 range, 3: requests, 4: average request ms. */
				array( __( 'Recorder prep as % of request time', 'upshift' ), sprintf( __( 'median %1$s, p95 %2$s (%3$s requests with a request time, avg request %4$s ms)', 'upshift' ), $u['rel_median'], $u['rel_p95'], number_format_i18n( $u['req_hits'] ), number_format_i18n( $u['avg_req_ms'], 1 ) ) ),
			)
		);

		if ( null !== $m['scan'] ) {
			$s = $m['scan'];
			echo '<h2>' . esc_html__( 'Scan', 'upshift' ) . '</h2>';
			self::key_value_table(
				array(
					array( __( 'Scan', 'upshift' ), sprintf( '#%d, %s, %s', $s['id'], $s['range'], $s['finished'] ) ),
					/* translators: 1: files, 2: skipped, 3: engine errors. */
					array( __( 'Files', 'upshift' ), sprintf( __( '%1$s (skipped %2$s, engine errors %3$s)', 'upshift' ), number_format_i18n( $s['files'] ), number_format_i18n( $s['skipped'] ), number_format_i18n( $s['engine_errors'] ) ) ),
					/* translators: 1: seconds, 2: engine seconds, 3: batches. */
					array( __( 'Duration', 'upshift' ), sprintf( __( '%1$s s (engine %2$s s), %3$s batches', 'upshift' ), number_format_i18n( $s['seconds'] ), number_format_i18n( $s['engine_seconds'], 1 ), number_format_i18n( $s['batches'] ) ) ),
					/* translators: %s: seconds. */
					array( __( 'New-risk check', 'upshift' ), $s['checked'] ? sprintf( __( '%s s extra for re-scanning files with findings on the "from" version alone', 'upshift' ), number_format_i18n( $s['baseline_seconds'], 1 ) ) : __( 'not run (scan from before the new-risk check)', 'upshift' ) ),
					/* translators: 1: peak MB, 2: limit MB, 3: SAPI or note. */
					array( __( 'Peak memory', 'upshift' ), sprintf( __( '%1$s MB of %2$s MB %3$s', 'upshift' ), number_format_i18n( $s['peak_memory'] / 1048576, 1 ), number_format_i18n( $s['memory_limit'] / 1048576, 1 ), $s['memory_limit_recorded'] ? '(' . $s['sapi'] . ')' : __( '(current limit; not recorded at scan time)', 'upshift' ) ) ),
				)
			);
		}

		echo '<h2>' . esc_html__( 'Copy as Markdown', 'upshift' ) . '</h2>';
		echo '<p>' . esc_html__( 'For docs/SPIKE-RESULT.md.', 'upshift' ) . '</p>';
		echo '<textarea id="upshift-markdown" readonly rows="22" class="large-text code" onfocus="this.select()">' . esc_textarea( Join::markdown( $m ) ) . '</textarea>';
		echo '<p><button type="button" class="button" id="upshift-copy-markdown">' . esc_html__( 'Copy', 'upshift' ) . '</button> <span id="upshift-copy-status"></span></p>';
	}

	/**
	 * Two-column table.
	 *
	 * @param array $rows List of array( label, value ).
	 */
	private static function key_value_table( array $rows ) {
		echo '<table class="widefat striped" style="max-width:60em"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row" style="width:22em">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
