<?php
/**
 * The Check tab (docs/UI-v1.md §3, 8a) and its report download.
 *
 * @package Upshift
 */

namespace Upshift\Admin;

use Upshift\Engine\Requirements;
use Upshift\Report\Check;
use Upshift\Report\Explain;
use Upshift\Report\Join;
use Upshift\Report\Status;
use Upshift\Scan\Runner;
use Upshift\Settings;
use Upshift\Usage\Rollup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendered from Page::render(), which checks the capability first.
 */
class CheckTab {

	const EXPORT      = 'upshift_download_report';
	const ACTIVITY_ON = 'upshift_activity_on';

	/**
	 * Prints a decorative dashicon (the word is always next to it).
	 *
	 * @param string $name Dashicon class, e.g. dashicons-cart.
	 */
	private static function icon( $name ) {
		echo '<span class="dashicons ' . esc_attr( $name ) . '" aria-hidden="true"></span>';
	}

	/**
	 * Renders the tab.
	 */
	public static function render() {
		$problems = Requirements::problems();
		if ( $problems ) {
			echo '<div class="notice notice-error inline">';
			foreach ( $problems as $problem ) {
				echo '<p>' . esc_html( $problem ) . '</p>';
			}
			echo '</div>';
			return;
		}

		// Read-only notice selector after our own redirect.
		$msg = isset( $_GET['upshift_msg'] ) ? sanitize_key( wp_unslash( $_GET['upshift_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'activity_on' === $msg ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Smart results is on.', 'upshift' ) . '</p></div>';
		}

		Integrations::render_recording_notice();

		$running = Runner::latest( 'running' );
		$done    = Runner::latest( 'done' );
		$latest  = Runner::latest();
		$summary = null !== $done ? Status::for_scan( $done ) : null;

		if ( null !== $latest && 'failed' === $latest['status'] ) {
			self::render_failed( $latest );
		}

		self::render_strip( null !== $running, null !== $summary );
		self::render_progress( $running );

		echo '<div id="upshift-results"' . ( null !== $running ? ' hidden' : '' ) . '>';
		if ( null !== $done && null !== $summary ) {
			self::render_report( Check::build( $done, $summary ) );
		} else {
			self::render_empty();
		}
		echo '</div>';
	}

	/**
	 * The last check failed: plain words first, the raw error behind a toggle.
	 *
	 * @param array $scan Scan row.
	 */
	private static function render_failed( array $scan ) {
		echo '<div class="upshift-note upshift-note--warn">';
		echo '<p>' . esc_html__( 'The last check did not finish. Please check again.', 'upshift' ) . '</p>';
		if ( '' !== (string) $scan['error'] ) {
			echo '<details class="upshift-tech"><summary>' . esc_html__( 'Technical details', 'upshift' ) . '</summary><p>' . esc_html( (string) $scan['error'] ) . '</p></details>';
		}
		echo '</div>';
	}

	/**
	 * PHP versions that can be checked against: newer than the version the site runs.
	 *
	 * @param string $from Version the site runs.
	 * @return array version => whether it can be chosen.
	 */
	private static function targets( $from ) {
		$out = array();
		foreach ( Settings::TARGET_VERSIONS as $version ) {
			$out[ $version ] = version_compare( $version, $from, '>' );
		}
		return $out;
	}

	/**
	 * The version the form starts on: the saved one if it can be chosen, else the first newer one.
	 *
	 * @param array $targets targets().
	 * @return string '' when there is no newer version.
	 */
	private static function default_target( array $targets ) {
		$saved = (string) Settings::get( 'default_target' );
		if ( ! empty( $targets[ $saved ] ) ) {
			return $saved;
		}
		foreach ( $targets as $version => $allowed ) {
			if ( $allowed ) {
				return (string) $version;
			}
		}
		return '';
	}

	/**
	 * The version the site runs (same rule as Engine::default_from(), without loading the engine on this page).
	 *
	 * @return string
	 */
	private static function from_version() {
		$override = (string) Settings::get( 'from_override' );
		return '' !== $override ? $override : PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	}

	/**
	 * The slim strip above the answer: site PHP, target, scope. Hidden while a check runs.
	 *
	 * @param bool $running    Whether a check is running.
	 * @param bool $has_result Whether there is a result (the button then lives in the answer card).
	 */
	private static function render_strip( $running, $has_result ) {
		$from    = self::from_version();
		$targets = self::targets( $from );
		$target  = self::default_target( $targets );

		echo '<form id="upshift-strip" class="upshift-strip' . ( $has_result ? ' upshift-strip--slim' : '' ) . '" onsubmit="return false;"' . ( $running ? ' hidden' : '' ) . '>';
		echo '<input type="hidden" id="upshift-from" value="' . esc_attr( $from ) . '" />';
		/* translators: %s: PHP version. */
		echo '<p class="upshift-strip__php">' . esc_html( sprintf( __( 'Your site runs PHP %s.', 'upshift' ), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ) ) . '</p>';

		if ( '' === $target ) {
			echo '<p class="upshift-muted">' . esc_html__( 'There is no newer PHP version to check against.', 'upshift' ) . '</p></form>';
			return;
		}

		echo '<p class="upshift-strip__target"><label for="upshift-target">' . esc_html__( 'Check against:', 'upshift' ) . '</label> ';
		echo '<select id="upshift-target">';
		foreach ( $targets as $version => $allowed ) {
			/* translators: %s: PHP version. */
			echo '<option value="' . esc_attr( $version ) . '"' . selected( $target, (string) $version, false ) . disabled( ! $allowed, true, false ) . '>' . esc_html( sprintf( __( 'PHP %s', 'upshift' ), $version ) ) . '</option>';
		}
		echo '</select></p>';

		echo '<fieldset class="upshift-strip__scope"><legend class="screen-reader-text">' . esc_html__( 'What to check', 'upshift' ) . '</legend>';
		echo '<label><input type="radio" name="upshift_scope" value="active" checked /> ' . esc_html__( 'Active plugins and theme (recommended)', 'upshift' ) . '</label> ';
		echo '<label><input type="radio" name="upshift_scope" value="all" id="upshift-scan-inactive" /> ' . esc_html__( 'Everything installed', 'upshift' ) . '</label>';
		echo '</fieldset>';
		echo '<p class="upshift-muted upshift-strip__note" id="upshift-target-note"' . ( '8.5' === $target ? '' : ' style="display:none"' ) . '>' . esc_html__( 'PHP 8.5 rules may be incomplete.', 'upshift' ) . '</p>';
		echo '</form>';
	}

	/**
	 * The start button, used once per page: in the answer card ("Check again") or the empty state ("Check my site").
	 *
	 * @param string $label Button text.
	 */
	private static function render_start_button( $label ) {
		$from    = self::from_version();
		$enabled = '' !== self::default_target( self::targets( $from ) );
		echo '<button type="button" class="button button-primary button-hero" id="upshift-scan-start"' . disabled( ! $enabled, true, false ) . ' data-upshift-no-config="' . esc_attr( Page::no_config_message() ) . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * The running line, also used by admin.js (same strings).
	 *
	 * @param array $progress Runner::progress_detail().
	 * @return string
	 */
	public static function progress_line( array $progress ) {
		if ( (int) $progress['components_total'] <= 0 ) {
			return __( 'Getting ready…', 'upshift' );
		}
		$total = (int) $progress['components_total'];
		$files = (int) $progress['component_files_total'];
		/* translators: 1: plugins and themes checked so far, 2: how many there are in all. */
		$checked = sprintf( _n( '%1$s of %2$s plugin checked.', '%1$s of %2$s plugins checked.', $total, 'upshift' ), number_format_i18n( (int) $progress['components_done'] ), number_format_i18n( $total ) );
		if ( '' === (string) $progress['current_component_label'] || $files <= 0 ) {
			return $checked;
		}
		/* translators: 1: sentence "12 of 50 plugins checked.", 2: plugin or theme name, 3: files checked so far, 4: files in all. */
		return sprintf( _n( '%1$s Now checking %2$s (%3$s of %4$s file).', '%1$s Now checking %2$s (%3$s of %4$s files).', $files, 'upshift' ), $checked, (string) $progress['current_component_label'], number_format_i18n( (int) $progress['component_files_done'] ), number_format_i18n( $files ) );
	}

	/**
	 * The line under the bar: whether the check goes on without this page.
	 *
	 * @return string
	 */
	private static function background_note() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return __( 'Keep this page open until the check finishes.', 'upshift' );
		}
		return __( 'You can leave this page. The check keeps running in the background and the result will be here when you come back.', 'upshift' );
	}

	/**
	 * Progress view, a full-width card like the answer card. Always printed, so admin.js can show it when a check starts.
	 *
	 * @param array|null $running Running scan row.
	 */
	private static function render_progress( $running ) {
		$progress   = null !== $running ? Runner::progress_detail( Runner::progress( $running ) ) : null;
		$reused     = null !== $progress ? (int) $progress['files_reused'] : 0;
		$total      = null !== $progress ? (int) $progress['files_total'] : 0;
		$done       = null !== $progress ? (int) $progress['files_done'] : 0;
		$percent    = $total > 0 ? (int) floor( 100 * $done / $total ) : 0;
		$background = null !== $progress && ! empty( $progress['background'] );
		$from       = null !== $running ? (string) $running['from_version'] : self::from_version();
		$target     = null !== $running ? (string) $running['target_version'] : self::default_target( self::targets( $from ) );
		$back       = admin_url( 'tools.php?page=' . Page::SLUG );

		echo '<section id="upshift-scan-progress" class="upshift-hero upshift-hero--run upshift-progress"' . ( null === $running ? ' hidden' : '' ) . '>';
		echo '<span class="upshift-hero__icon dashicons dashicons-update" aria-hidden="true"></span>';
		echo '<div class="upshift-hero__body">';
		echo '<h2 class="upshift-hero__answer">' . esc_html__( 'Checking your site', 'upshift' ) . '</h2>';
		echo '<p class="upshift-path"><span class="upshift-pill" id="upshift-run-from">' . esc_html( $from ) . '</span>';
		self::icon( 'dashicons-arrow-right-alt upshift-path__arrow' );
		echo '<span class="upshift-pill" id="upshift-run-target">' . esc_html( $target ) . '</span></p>';
		echo '<div class="upshift-bar-row"><progress class="upshift-bar" value="' . esc_attr( (string) $done ) . '" max="' . esc_attr( (string) max( 1, $total ) ) . '">' . esc_html__( 'Progress of the check', 'upshift' ) . '</progress>';
		/* translators: %s: percent done. */
		echo '<span class="upshift-percent">' . esc_html( sprintf( __( '%s%%', 'upshift' ), number_format_i18n( $percent ) ) ) . '</span></div>';
		echo '<p class="upshift-scan-message" role="status">' . esc_html( null !== $progress ? self::progress_line( $progress ) : '' ) . '</p>';
		/* translators: %s: number of files. */
		echo '<p class="upshift-muted upshift-reused"' . ( $reused > 0 ? '' : ' hidden' ) . '>' . esc_html( $reused > 0 ? sprintf( _n( 'Reused %s unchanged file.', 'Reused %s unchanged files.', $reused, 'upshift' ), number_format_i18n( $reused ) ) : '' ) . '</p>';
		echo '<p class="upshift-muted">' . esc_html( self::background_note() ) . '</p>';
		echo '<p class="upshift-muted" id="upshift-speedup-note"' . ( $background ? '' : ' hidden' ) . '>' . esc_html__( 'Keep this page open to finish faster.', 'upshift' ) . '</p>';
		echo '</div><div class="upshift-hero__action upshift-controls" id="upshift-scan-controls" data-running="' . esc_attr( null !== $running ? (string) (int) $running['id'] : '0' ) . '">';
		echo '<button type="button" class="button" id="upshift-scan-cancel"' . ( null === $running ? ' style="display:none"' : '' ) . '>' . esc_html__( 'Stop', 'upshift' ) . '</button>';
		echo '<button type="button" class="button" id="upshift-scan-resume" data-speed="' . esc_attr__( 'Speed up', 'upshift' ) . '" data-continue="' . esc_attr__( 'Continue', 'upshift' ) . '"' . ( null === $running ? ' style="display:none"' : '' ) . '>' . esc_html( $background ? __( 'Speed up', 'upshift' ) : __( 'Continue', 'upshift' ) ) . '</button>';
		echo '<a id="upshift-back" href="' . esc_url( $back ) . '" hidden>' . esc_html__( 'Back to the results', 'upshift' ) . '</a>';
		echo '</div></section>';
	}

	/**
	 * Empty state: no finished check yet.
	 */
	private static function render_empty() {
		echo '<section class="upshift-card upshift-empty">';
		echo '<div class="upshift-empty__art" aria-hidden="true"><span class="dashicons dashicons-admin-plugins"></span><span class="dashicons dashicons-yes-alt"></span></div>';
		echo '<p class="upshift-empty__text">' . esc_html__( 'Upshift reads your plugins and themes and tells you what a newer PHP version would break.', 'upshift' ) . '</p>';
		self::render_start_button( __( 'Check my site', 'upshift' ) );
		echo '</section>';

		if ( empty( Settings::get( 'usage_enabled' ) ) ) {
			echo '<section class="upshift-card upshift-offer">';
			echo '<p>' . esc_html(
				SettingsTab::has_shop()
					? __( 'Turn on Smart results to see which plugins run on your checkout, shop and admin. It stores only file names and counts on your own site, and nothing is sent anywhere.', 'upshift' )
					: __( 'Turn on Smart results to see which plugins run on your pages and admin. It stores only file names and counts on your own site, and nothing is sent anywhere.', 'upshift' )
			) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTIVITY_ON ) . '" />';
			wp_nonce_field( self::ACTIVITY_ON );
			echo '<button type="submit" class="button">' . esc_html__( 'Turn on', 'upshift' ) . '</button></form></section>';
		}
	}

	/**
	 * The finished report.
	 *
	 * @param array $report Check::build().
	 */
	private static function render_report( array $report ) {
		self::render_hero( $report );
		self::render_notes( $report );
		self::render_counts( $report );
		self::render_steps( $report );
		self::render_list( $report );
		self::render_other_notes( $report );
		self::render_buttons( $report );
		echo '<p class="upshift-honest">' . esc_html__( "This check reads the code; it doesn't run it. 'Not seen running' doesn't mean 'never runs'. Test on a staging copy before you upgrade a live shop.", 'upshift' ) . '</p>';
	}

	/**
	 * Hero answer card.
	 *
	 * @param array $report Check::build().
	 */
	private static function render_hero( array $report ) {
		$answer = $report['answer'];
		$total  = (int) $report['total'];
		$date   = mysql2date( __( 'j M Y', 'upshift' ), (string) $report['scan']['finished'] );

		echo '<section class="upshift-hero upshift-hero--' . esc_attr( $answer['level'] ) . '">';
		echo '<span class="upshift-hero__icon dashicons ' . esc_attr( Status::icon( $answer['status'] ) ) . '" aria-hidden="true"></span>';
		echo '<div class="upshift-hero__body">';
		echo '<h2 class="upshift-hero__answer">' . esc_html( $answer['sentence'] ) . '</h2>';
		echo '<p class="upshift-path">';
		/* translators: 1: current PHP version, 2: target PHP version. */
		echo '<span class="screen-reader-text">' . esc_html( sprintf( __( 'From PHP %1$s to PHP %2$s', 'upshift' ), $report['from'], $report['target'] ) ) . '</span>';
		echo '<span class="upshift-pill" aria-hidden="true">' . esc_html( $report['from'] ) . '</span>';
		self::icon( 'dashicons-arrow-right-alt upshift-path__arrow' );
		echo '<span class="upshift-pill" aria-hidden="true">' . esc_html( $report['target'] ) . '</span></p>';
		echo '<p class="upshift-muted">';
		/* translators: 1: number of plugins and themes, 2: date. */
		echo esc_html( sprintf( _n( 'Checked %1$s plugin or theme on %2$s.', 'Checked %1$s plugins and themes on %2$s.', $total, 'upshift' ), number_format_i18n( $total ), $date ) );
		if ( $report['watch_on'] ) {
			$days = (int) $report['days'];
			/* translators: %s: number of days. */
			echo ' ' . esc_html( sprintf( _n( 'Smart results: %s day.', 'Smart results: %s days.', $days, 'upshift' ), number_format_i18n( $days ) ) );
		}
		echo '</p>';
		if ( $report['changed'] ) {
			echo '<p class="upshift-hero__changed">';
			self::icon( 'dashicons-update' );
			echo ' ' . esc_html__( 'Your site changed since the last check. Check again for an up-to-date answer.', 'upshift' ) . '</p>';
		}
		echo '</div><div class="upshift-hero__action">';
		self::render_start_button( __( 'Check again', 'upshift' ) );
		echo '</div></section>';
	}

	/**
	 * Files not checked and old-result notes, right under the answer.
	 *
	 * @param array $report Check::build().
	 */
	private static function render_notes( array $report ) {
		if ( ! $report['checked'] ) {
			echo '<div class="upshift-note upshift-note--warn"><p>' . esc_html__( 'This result comes from an older version of the check, so it may list problems your site already handles today. Check again for an exact answer.', 'upshift' ) . '</p></div>';
		}
		$not_checked = $report['not_checked'];
		if ( $not_checked['total'] <= 0 ) {
			return;
		}
		echo '<div class="upshift-note upshift-note--warn">';
		/* translators: %s: number of files. */
		echo '<p><strong>' . esc_html( sprintf( _n( '%s code file could not be fully checked, so a problem in it could be missing here.', '%s code files could not be fully checked, so problems in them could be missing here.', (int) $not_checked['total'], 'upshift' ), number_format_i18n( (int) $not_checked['total'] ) ) ) . '</strong></p>';
		echo '<ul>';
		foreach ( $not_checked['reasons'] as $reason ) {
			echo '<li>' . esc_html( $reason['text'] ) . ' <span class="upshift-muted">' . esc_html( implode( ', ', $reason['files'] ) ) . '</span></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * The four count cards. Each one filters the list.
	 *
	 * @param array $report Check::build().
	 */
	private static function render_counts( array $report ) {
		echo '<div class="upshift-cards" role="group" aria-label="' . esc_attr__( 'Show only plugins with this result', 'upshift' ) . '">';
		foreach ( Status::ORDER as $status ) {
			$n = (int) $report['counts'][ $status ]['all'];
			echo '<button type="button" class="upshift-count upshift-count--' . esc_attr( $status ) . '" data-filter="' . esc_attr( $status ) . '" aria-pressed="false"' . disabled( 0 === $n, true, false ) . '>';
			echo '<span class="upshift-count__top"><span class="upshift-count__number">' . esc_html( number_format_i18n( $n ) ) . '</span>';
			self::icon( Status::icon( $status ) );
			echo '</span><span class="upshift-count__label">' . esc_html( Status::label( $status ) ) . '</span>';
			if ( $n > 0 ) {
				echo '<span class="upshift-count__show" data-show="' . esc_attr__( 'Show these', 'upshift' ) . '" data-showall="' . esc_attr__( 'Show all plugins', 'upshift' ) . '">' . esc_html__( 'Show these', 'upshift' ) . '</span>';
			}
			echo '</button>';
		}
		echo '</div>';
	}

	/**
	 * "Do this first" (Will break and Ask your host) and "When you have time" (Works, fix later), at most five numbered steps each.
	 *
	 * @param array $report Check::build().
	 */
	private static function render_steps( array $report ) {
		self::render_step_list( __( 'Do this first', 'upshift' ), $report['steps'] );
		self::render_step_list( __( 'When you have time', 'upshift' ), $report['later_steps'] );
	}

	/**
	 * One numbered list of steps. Prints nothing without steps.
	 *
	 * @param string $title Heading.
	 * @param array  $steps Advice rows.
	 */
	private static function render_step_list( $title, array $steps ) {
		if ( ! $steps ) {
			return;
		}
		echo '<section class="upshift-section"><h2>' . esc_html( $title ) . '</h2><ol class="upshift-steps">';
		foreach ( $steps as $step ) {
			echo '<li class="upshift-step"><div class="upshift-step__text"><strong>' . esc_html( $step['action'] ) . '</strong><span>' . esc_html( $step['reason'] ) . '</span></div>';
			if ( '' !== $step['copy'] ) {
				echo '<button type="button" class="button upshift-copy" data-copy="' . esc_attr( $step['copy'] ) . '">' . esc_html( $step['link_text'] ) . '</button>';
			} elseif ( '' !== $step['link'] ) {
				echo '<a class="button" href="' . esc_url( $step['link'] ) . '">' . esc_html( $step['link_text'] ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol></section>';
	}

	/**
	 * The plugin list. Ready plugins are one compact line each; next to problems they sit in a collapsed group.
	 * Below it, the plugins that are off when "Everything installed" was checked.
	 *
	 * @param array $report Check::build().
	 */
	private static function render_list( array $report ) {
		$problems = array();
		$ready    = array();
		foreach ( $report['items'] as $item ) {
			if ( Status::READY === $item['status'] ) {
				$ready[] = $item;
			} else {
				$problems[] = $item;
			}
		}

		echo '<section class="upshift-section"><h2>' . esc_html__( 'Your plugins and themes', 'upshift' ) . '</h2>';
		echo '<p class="upshift-filter"><span id="upshift-filter-status" class="upshift-muted" role="status"></span> <button type="button" class="button-link" id="upshift-show-all" hidden>' . esc_html__( 'Show all plugins', 'upshift' ) . '</button></p>';
		echo '<div class="upshift-list" id="upshift-active-list">';
		foreach ( $problems as $item ) {
			self::render_item( $item );
		}
		if ( $ready && $problems ) {
			$n = count( $ready );
			/* translators: %s: number of plugins and themes. */
			echo '<details class="upshift-ready-group" id="upshift-ready-group"><summary>' . esc_html( sprintf( _n( '%s plugin or theme is ready (show)', '%s plugins and themes are ready (show)', $n, 'upshift' ), number_format_i18n( $n ) ) ) . '</summary><div class="upshift-list">';
			foreach ( $ready as $item ) {
				self::render_item( $item );
			}
			echo '</div></details>';
		} else {
			foreach ( $ready as $item ) {
				self::render_item( $item );
			}
		}
		echo '</div></section>';

		if ( $report['inactive'] ) {
			echo '<section class="upshift-section"><h2>' . esc_html__( 'Not active', 'upshift' ) . '</h2>';
			echo '<p class="upshift-muted">' . esc_html__( 'These are turned off, so they cannot affect your site right now. The result applies if you turn one on.', 'upshift' ) . '</p><div class="upshift-list">';
			foreach ( $report['inactive'] as $item ) {
				self::render_item( $item );
			}
			echo '</div></section>';
		}
	}

	/**
	 * One row of the list.
	 *
	 * @param array $item Item.
	 */
	private static function render_item( array $item ) {
		$has_body = ! empty( $item['groups'] );
		$tag      = $has_body ? 'details' : 'div';
		$inner    = $has_body ? 'summary' : 'div';
		$compact  = ! $has_body && Status::READY === $item['status'];

		echo '<' . tag_escape( $tag ) . ' class="upshift-item' . ( $compact ? ' upshift-item--compact' : '' ) . '" data-status="' . esc_attr( $item['status'] ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag_escape().
		echo '<' . tag_escape( $inner ) . ' class="upshift-item__row">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag_escape().
		echo '<span class="upshift-badge upshift-badge--' . esc_attr( $item['status'] ) . '">';
		self::icon( Status::icon( $item['status'] ) );
		echo esc_html( Status::label( $item['status'] ) ) . '</span>';

		echo '<span class="upshift-item__main"><span class="upshift-item__name">' . esc_html( $item['name'] ) . '</span>';
		if ( '' !== $item['version'] ) {
			echo ' <span class="upshift-muted">' . esc_html( $item['version'] ) . '</span>';
		}
		self::render_chips( $item['where'] );
		echo '</span>';

		if ( ! $compact ) {
			echo '<span class="upshift-item__todo">' . esc_html( $item['advice']['todo'] ) . '</span>';
		}
		if ( $has_body ) {
			echo '<span class="dashicons dashicons-arrow-down-alt2 upshift-item__chevron" aria-hidden="true"></span>';
		}
		echo '</' . tag_escape( $inner ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag_escape().

		if ( $has_body ) {
			self::render_body( $item );
		}
		echo '</' . tag_escape( $tag ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag_escape().
	}

	/**
	 * "Runs on" chips.
	 *
	 * @param array $where Check where().
	 */
	private static function render_chips( array $where ) {
		if ( ! $where['chips'] ) {
			return;
		}
		echo '<span class="upshift-chips">';
		if ( in_array( $where['kind'], array( 'some', 'every', 'checkout' ), true ) ) {
			echo '<span class="upshift-chips__label">' . esc_html__( 'Runs on:', 'upshift' ) . '</span>';
		}
		foreach ( $where['chips'] as $chip ) {
			echo '<span class="upshift-chip">';
			self::icon( $chip[0] );
			echo esc_html( $chip[1] ) . '</span>';
		}
		echo '</span>';
	}

	/**
	 * Expanded area: problems per file, then Technical details.
	 *
	 * @param array $item Item.
	 */
	private static function render_body( array $item ) {
		$file = null;
		echo '<div class="upshift-item__body">';
		echo '<p class="upshift-item__copy"><button type="button" class="button upshift-copy" data-copy="' . esc_attr( $item['developer_text'] ) . '">' . esc_html__( 'Copy for this developer', 'upshift' ) . '</button></p>';
		echo '<table class="upshift-problems"><thead><tr>';
		foreach ( array( __( 'File and line', 'upshift' ), __( 'What it is', 'upshift' ), __( 'How to fix', 'upshift' ), __( 'What it means for your site', 'upshift' ), __( 'Runs on', 'upshift' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $item['groups'] as $group ) {
			if ( $file !== $group['file'] ) {
				$file = $group['file'];
				echo '<tr class="upshift-problems__file"><th colspan="5" scope="colgroup"><code>' . esc_html( $file ) . '</code></th></tr>';
			}
			echo '<tr>';
			echo '<td data-label="' . esc_attr__( 'File and line', 'upshift' ) . '">' . esc_html( self::lines_label( $group['lines'] ) ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'What it is', 'upshift' ) . '">' . esc_html( $group['text']['problem'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'How to fix', 'upshift' ) . '">' . esc_html( $group['text']['how_to_fix'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'What it means for your site', 'upshift' ) . '">' . esc_html( $group['text']['impact'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Runs on', 'upshift' ) . '">' . esc_html( $group['runs'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( $item['more'] > 0 ) {
			/* translators: %s: number of places. */
			echo '<p class="upshift-muted">' . esc_html( sprintf( _n( '%s more place is in the CSV report.', '%s more places are in the CSV report.', (int) $item['more'], 'upshift' ), number_format_i18n( (int) $item['more'] ) ) ) . '</p>';
		}

		echo '<details class="upshift-tech"><summary>' . esc_html__( 'Technical details', 'upshift' ) . '</summary><ul>';
		foreach ( $item['groups'] as $group ) {
			foreach ( $group['tech'] as $rule => $message ) {
				echo '<li><code>' . esc_html( $rule ) . '</code> ' . esc_html( $message ) . '</li>';
			}
		}
		echo '</ul></details></div>';
	}

	/**
	 * "Line 23", or "12 places in this file (lines 23, 40, 88, 90, 101)".
	 *
	 * @param int[] $lines Line numbers.
	 * @return string
	 */
	private static function lines_label( array $lines ) {
		$count = count( $lines );
		if ( 1 === $count ) {
			/* translators: %s: line number. */
			return sprintf( __( 'Line %s', 'upshift' ), number_format_i18n( $lines[0] ) );
		}
		sort( $lines );
		/* translators: 1: number of places, 2: list of line numbers (at most five). */
		return sprintf( _n( '%1$s place in this file (lines %2$s)', '%1$s places in this file (lines %2$s)', $count, 'upshift' ), number_format_i18n( $count ), implode( ', ', array_slice( $lines, 0, 5 ) ) );
	}

	/**
	 * One muted line about the notes that are only in the CSV.
	 *
	 * @param array $report Check::build().
	 */
	private static function render_other_notes( array $report ) {
		$count = (int) $report['notes'];
		if ( 0 === $count ) {
			return;
		}
		/* translators: %s: number of notes. */
		echo '<p class="upshift-muted upshift-other-notes">' . esc_html( sprintf( _n( "%s other note doesn't affect this upgrade. It's in the CSV report.", "%s other notes don't affect this upgrade. They're in the CSV report.", $count, 'upshift' ), number_format_i18n( $count ) ) ) . '</p>';
	}

	/**
	 * Download, copy and print.
	 *
	 * @param array $report Check::build().
	 */
	private static function render_buttons( array $report ) {
		echo '<div class="upshift-actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT ) . '" />';
		wp_nonce_field( self::EXPORT );
		echo '<button type="submit" class="button">' . esc_html__( 'Download report (CSV)', 'upshift' ) . '</button></form>';
		echo '<button type="button" class="button upshift-copy" data-copy-from="upshift-report-text">' . esc_html__( 'Copy text for your developer or host', 'upshift' ) . '</button>';
		echo '<button type="button" class="button" id="upshift-print">' . esc_html__( 'Print report', 'upshift' ) . '</button>';
		echo '<span id="upshift-copy-status" class="upshift-muted" role="status"></span>';
		echo '</div>';
		echo '<textarea id="upshift-report-text" class="upshift-offscreen" readonly tabindex="-1" aria-hidden="true">' . esc_textarea( Check::text_report( $report ) ) . '</textarea>';
	}

	/**
	 * Admin-post: turns Smart results on from the empty state.
	 */
	public static function handle_activity_on() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'upshift' ), 403 );
		}
		check_admin_referer( self::ACTIVITY_ON );

		Settings::update(
			array(
				'usage_enabled'         => true,
				'recorder_paused_until' => 0,
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'         => 'check',
					'upshift_msg' => 'activity_on',
				),
				admin_url( 'tools.php?page=' . Page::SLUG )
			)
		);
		exit;
	}

	/**
	 * Admin-post: the report as CSV with plain headers (capability + nonce first).
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'upshift' ), 403 );
		}
		check_admin_referer( self::EXPORT );

		$scan = Runner::latest( 'done' );
		if ( null === $scan ) {
			wp_die( esc_html__( 'There is no finished check yet.', 'upshift' ) );
		}
		$rows = self::csv_rows( $scan );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="php-upgrade-report.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming the download.
		// The escape argument is passed so PHP 8.4 doesn't deprecate the default; '' (RFC 4180) needs PHP 7.4.
		$escape = version_compare( PHP_VERSION, '7.4', '>=' ) ? '' : '\\';
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so Excel reads the encoding.
		fputcsv( $out, self::csv_headers(), ',', '"', $escape );
		foreach ( $rows as $cells ) {
			fputcsv( $out, array_map( array( Join::class, 'csv_cell' ), $cells ), ',', '"', $escape );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Rows of the report CSV, in the order of csv_headers().
	 *
	 * @param array $scan Finished scan row.
	 * @return array[]
	 */
	public static function csv_rows( array $scan ) {
		$meta  = Rollup::meta();
		$days  = false !== $meta ? (int) $meta['days_observed'] : 0;
		$names = Check::names( Status::for_scan( $scan ) );
		$rows  = array();
		$notes = array();
		foreach ( Join::findings( (int) $scan['id'] ) as $row ) {
			$explain = Explain::of( $row['rule'], $row['category'], $row['severity'] );
			if ( Check::is_note( $row ) ) {
				// Notes of active plugins, the same ones the page counts.
				if ( ! $row['active'] || ! isset( $names[ $row['component'] ] ) ) {
					continue;
				}
				$key = Check::note_key( $row );
				if ( isset( $notes[ $key ] ) ) {
					++$notes[ $key ][4];
					continue;
				}
				$notes[ $key ] = array(
					$names[ $row['component'] ],
					Check::note_label(),
					Check::file_label( $row['path_key'] ),
					$row['line'],
					1,
					$explain['problem'],
					$explain['how_to_fix'],
					Check::note_reason( $row, (string) $scan['from_version'] ),
					Check::row_runs( $row, $days ),
				);
				continue;
			}
			$status = Status::label( (string) Status::of( $row['category'], $row['severity'] ) );
			$file   = Check::file_label( $row['path_key'] );
			$key    = implode( '|', array( $row['component'], $file, $row['line'], $status, $explain['problem'] ) );
			if ( isset( $rows[ $key ] ) ) {
				++$rows[ $key ][4];
				continue;
			}
			$rows[ $key ] = array(
				isset( $names[ $row['component'] ] ) ? $names[ $row['component'] ] : $row['component'],
				$status,
				$file,
				$row['line'],
				1,
				$explain['problem'],
				$explain['how_to_fix'],
				$explain['impact'],
				Check::row_runs( $row, $days ),
			);
		}
		return array_merge( array_values( $rows ), array_values( $notes ) );
	}

	/**
	 * Column headers of the report CSV.
	 *
	 * @return string[]
	 */
	public static function csv_headers() {
		return array( 'plugin_or_theme', 'status', 'file', 'line', 'count', 'problem', 'how_to_fix', 'impact', 'runs_on' );
	}
}
