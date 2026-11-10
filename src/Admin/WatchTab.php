<?php
/**
 * The Upgrade watch tab (docs/UI-v1.md §5).
 *
 * @package Upshift
 */

namespace Upshift\Admin;

use Upshift\Settings;
use Upshift\Watch\Collector;
use Upshift\Watch\Report;
use Upshift\Watch\State;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendered from Page::render(), which checks the capability first. The handler checks it again, then its nonce.
 */
class WatchTab {

	const START = 'upshift_watch_start';

	/**
	 * Renders the tab.
	 */
	public static function render() {
		self::render_notice();
		if ( empty( Settings::get( 'upgrade_watch' ) ) ) {
			self::render_off();
			return;
		}

		$report  = Report::build();
		$waiting = Report::BEFORE === $report['phase'];
		if ( $waiting ) {
			self::render_before( $report );
		} else {
			self::render_result( $report );
		}
		self::render_notes( $report );
		echo '<p class="upshift-honest">' . esc_html( self::honest_line( $report ) ) . '</p>';
		if ( ! $waiting ) {
			self::render_switch( true );
		}
	}

	/**
	 * Notice after our own redirect.
	 */
	private static function render_notice() {
		// Read-only notice selector after our own redirect.
		$msg = isset( $_GET['upshift_msg'] ) ? sanitize_key( wp_unslash( $_GET['upshift_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'saved' === $msg ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Settings saved.', 'upshift' ) . '</p></div>';
		} elseif ( 'started' === $msg ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Upgrade guard started. Upshift compares the next 7 days with the 7 days before.', 'upshift' ) . '</p></div>';
		}
	}

	/**
	 * The switch.
	 *
	 * @param bool $on Whether the watch is on.
	 */
	private static function render_switch( $on ) {
		echo '<form method="post" class="upshift-switch" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( SettingsTab::SAVE ) . '" /><input type="hidden" name="upshift_section" value="watch" />';
		wp_nonce_field( SettingsTab::SAVE );
		echo '<p class="upshift-switch__row"><label><input type="checkbox" name="upgrade_watch" value="1"' . checked( $on, true, false ) . ' /> <strong>' . esc_html__( 'Watch for problems after a PHP upgrade', 'upshift' ) . '</strong></label> ';
		submit_button( __( 'Save', 'upshift' ), 'secondary', 'submit', false );
		echo '</p>';
		// "Turn it on now" only makes sense while it is off.
		echo '<p class="upshift-muted">' . esc_html( $on ? __( 'Tells you if any plugin starts crashing after your host changes PHP.', 'upshift' ) : __( 'Tells you if any plugin starts crashing after your host changes PHP. Turn it on now, so Upshift knows how your site behaves before the change.', 'upshift' ) ) . '</p>';
		if ( ! $on ) {
			echo '<p class="upshift-muted upshift-privacy">' . esc_html( SettingsTab::privacy_line() ) . '</p>';
		}
		echo '</form>';
	}

	/**
	 * Off: what it does and the switch.
	 */
	private static function render_off() {
		echo '<section class="upshift-card upshift-watch">';
		echo '<h2>' . esc_html__( 'Upgrade guard is off.', 'upshift' ) . '</h2>';
		self::render_switch( false );
		echo '</section>';
	}

	/**
	 * Prints a decorative dashicon (the word is always next to it).
	 *
	 * @param string $name Dashicon class.
	 */
	private static function icon( $name ) {
		echo '<span class="dashicons ' . esc_attr( $name ) . '" aria-hidden="true"></span>';
	}

	/**
	 * The "Start comparing now" button.
	 *
	 * @param bool $primary Primary style.
	 */
	private static function render_start( $primary ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::START ) . '" />';
		wp_nonce_field( self::START );
		echo '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html__( 'Start comparing now', 'upshift' ) . '</button></form>';
	}

	/**
	 * The small "Already upgraded?" button while waiting for a PHP change. Same handler as "Start comparing now".
	 */
	private static function render_compare() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::START ) . '" />';
		wp_nonce_field( self::START );
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Already upgraded? Start comparing now', 'upshift' ) . '</button></form>';
		echo '<p class="upshift-muted upshift-compare__note">' . esc_html__( 'Use this only if your host changed PHP recently.', 'upshift' ) . '</p>';
	}

	/**
	 * Before a switch: the watch is on and waiting for a PHP change.
	 *
	 * @param array $report Report::build().
	 */
	private static function render_before( array $report ) {
		$since = '' !== (string) $report['state']['since'] ? (string) $report['state']['since'] : (string) $report['today'];

		echo '<section class="upshift-hero upshift-hero--run">';
		self::icon( 'upshift-hero__icon dashicons-visibility' );
		echo '<div class="upshift-hero__body">';
		echo '<h2 class="upshift-hero__answer">' . esc_html__( 'Upgrade guard is on. Nothing more to do. When your host changes PHP, Upshift starts comparing automatically for 7 days.', 'upshift' ) . '</h2>';

		echo '<ol class="upshift-stages">';
		echo '<li class="upshift-stages__step is-current" aria-current="step"><span class="upshift-stages__marker" aria-hidden="true">1</span><span class="upshift-stages__text"><strong>' . esc_html__( 'Learning how your site normally behaves', 'upshift' ) . '</strong>';
		/* translators: %s: date, for example "9 Nov". */
		$collecting = sprintf( __( 'Collecting since %s', 'upshift' ), Report::date( $since ) );
		/* translators: %s: PHP version. */
		$runs = sprintf( __( 'Your site runs PHP %s.', 'upshift' ), $report['php'] );
		echo '<span class="upshift-stages__line">' . esc_html( $collecting . ' · ' . $runs ) . '</span></span></li>';
		echo '<li class="upshift-stages__step"><span class="upshift-stages__marker" aria-hidden="true">2</span><span class="upshift-stages__text"><strong>' . esc_html__( 'Your host changes PHP', 'upshift' ) . '</strong></span></li>';
		echo '<li class="upshift-stages__step"><span class="upshift-stages__marker" aria-hidden="true">3</span><span class="upshift-stages__text"><strong>' . esc_html__( '7-day comparison', 'upshift' ) . '</strong></span></li>';
		echo '</ol>';

		if ( $report['state']['since'] !== $report['today'] ) {
			/* translators: %s: for example "0 crashes, 3 warnings". */
			echo '<p>' . esc_html( sprintf( __( 'Last 7 days: %s.', 'upshift' ), Report::recent_words( $report['recent'] ) ) ) . '</p>';
		}
		self::render_also( $report['recent'][ Collector::DEPRECATION ] );
		self::render_switch( true );
		echo '</div><div class="upshift-hero__action upshift-compare">';
		self::render_compare();
		echo '</div></section>';
	}

	/**
	 * The muted line about old code.
	 *
	 * @param int $count Notes.
	 */
	private static function render_also( $count ) {
		if ( $count > 0 ) {
			/* translators: %s: for example "12 notes about old code that still work". */
			echo '<p class="upshift-muted">' . esc_html( sprintf( __( 'Also seen: %s.', 'upshift' ), Report::count_words( $count, Collector::DEPRECATION ) ) ) . '</p>';
		}
	}

	/**
	 * After a switch: the answer, the table, advice and the report.
	 *
	 * @param array $report Report::build().
	 */
	private static function render_result( array $report ) {
		$green = 'green' === $report['answer']['level'];

		echo '<section class="upshift-hero upshift-hero--' . esc_attr( $report['answer']['level'] ) . '">';
		self::icon( 'upshift-hero__icon ' . ( $green ? 'dashicons-yes-alt' : 'dashicons-dismiss' ) );
		echo '<div class="upshift-hero__body">';
		echo '<h2 class="upshift-hero__answer">' . esc_html( $report['answer']['sentence'] ) . '</h2>';
		self::render_path( $report );
		echo '<p class="upshift-muted">' . esc_html( Report::headline( $report ) ) . '</p>';
		self::render_also( $report['also'] );
		echo '</div>';
		if ( Report::WATCHING !== $report['phase'] ) {
			echo '<div class="upshift-hero__action">';
			self::render_start( false );
			echo '</div>';
		}
		echo '</section>';

		$rows = array_values(
			array_filter(
				$report['items'],
				function ( $item ) {
					return $item[ Collector::CRASH ] + $item[ Collector::ERROR ] + $item[ Collector::WARNING ] > 0;
				}
			)
		);

		$advice = Report::advice( $report );
		if ( '' !== $advice ) {
			echo '<div class="upshift-note' . ( $green ? '' : ' upshift-note--warn' ) . '"><p>' . esc_html( $advice ) . '</p></div>';
		}

		if ( $rows ) {
			self::render_table( $rows );
		}

		echo '<div class="upshift-actions">';
		echo '<button type="button" class="button upshift-copy" data-copy-from="upshift-watch-text">' . esc_html__( 'Copy report', 'upshift' ) . '</button>';
		echo '<span id="upshift-copy-status" class="upshift-muted" role="status"></span>';
		echo '</div>';
		echo '<textarea id="upshift-watch-text" class="upshift-offscreen" readonly tabindex="-1" aria-hidden="true">' . esc_textarea( Report::text( $report ) ) . '</textarea>';
	}

	/**
	 * The two version pills.
	 *
	 * @param array $report Report::build().
	 */
	private static function render_path( array $report ) {
		$state = $report['state'];
		$from  = Report::BACK === $report['phase'] ? $state['back']['from'] : $state['from'];
		$to    = Report::BACK === $report['phase'] ? $state['back']['to'] : $state['to'];
		if ( '' === $from ) {
			return;
		}
		echo '<p class="upshift-path">';
		/* translators: 1: PHP version before, 2: PHP version after. */
		echo '<span class="screen-reader-text">' . esc_html( sprintf( __( 'From PHP %1$s to PHP %2$s', 'upshift' ), $from, $to ) ) . '</span>';
		echo '<span class="upshift-pill" aria-hidden="true">' . esc_html( $from ) . '</span>';
		self::icon( 'dashicons-arrow-right-alt upshift-path__arrow' );
		echo '<span class="upshift-pill" aria-hidden="true">' . esc_html( $to ) . '</span></p>';
	}

	/**
	 * The per-plugin table.
	 *
	 * @param array $rows Items.
	 */
	private static function render_table( array $rows ) {
		$heads = array(
			__( 'Plugin or theme', 'upshift' ),
			__( 'New crashes', 'upshift' ),
			__( 'New errors', 'upshift' ),
			__( 'New warnings', 'upshift' ),
			__( 'Where', 'upshift' ),
			__( 'Last seen', 'upshift' ),
		);
		echo '<section class="upshift-section"><table class="upshift-problems upshift-watch-table"><thead><tr>';
		foreach ( $heads as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $item ) {
			echo '<tr>';
			echo '<th scope="row" data-label="' . esc_attr( $heads[0] ) . '">' . esc_html( $item['name'] ) . '</th>';
			echo '<td data-label="' . esc_attr( $heads[1] ) . '">' . esc_html( number_format_i18n( $item[ Collector::CRASH ] ) ) . '</td>';
			echo '<td data-label="' . esc_attr( $heads[2] ) . '">' . esc_html( number_format_i18n( $item[ Collector::ERROR ] ) ) . '</td>';
			echo '<td data-label="' . esc_attr( $heads[3] ) . '">' . esc_html( number_format_i18n( $item[ Collector::WARNING ] ) ) . '</td>';
			echo '<td data-label="' . esc_attr( $heads[4] ) . '"><span class="upshift-chips">';
			foreach ( Report::chips( $item['context'] ) as $chip ) {
				echo '<span class="upshift-chip">';
				self::icon( $chip[0] );
				echo esc_html( $chip[1] ) . '</span>';
			}
			echo '</span></td>';
			echo '<td data-label="' . esc_attr( $heads[5] ) . '">' . esc_html( Report::date( $item['day'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<details class="upshift-tech"><summary>' . esc_html__( 'Technical details', 'upshift' ) . '</summary><ul>';
		foreach ( $rows as $item ) {
			foreach ( $item['samples'] as $sample ) {
				echo '<li><strong>' . esc_html( $item['name'] ) . '</strong> <code>' . esc_html( $sample['level'] ) . '</code> ' . esc_html( $sample['sample'] ) . '</li>';
			}
		}
		echo '</ul></details></section>';
	}

	/**
	 * Notes that are true right now.
	 *
	 * @param array $report Report::build().
	 */
	private static function render_notes( array $report ) {
		$notes = array();
		if ( null !== $report['thin'] ) {
			if ( 0 === $report['thin'] ) {
				$notes[] = __( 'Upgrade guard was not on before this change, so some older problems may show as new.', 'upshift' );
			} else {
				/* translators: %s: number of days. */
				$notes[] = sprintf( _n( 'Upgrade guard was on for only %s day before this change, so some older problems may show as new.', 'Upgrade guard was on for only %s days before this change, so some older problems may show as new.', $report['thin'], 'upshift' ), number_format_i18n( $report['thin'] ) );
			}
		}
		if ( $report['paused'] ) {
			$notes[] = __( 'Upgrade guard paused itself for a few minutes after a database error. It starts again on its own.', 'upshift' );
		}
		if ( $report['capped'] ) {
			$notes[] = __( 'So many different warnings appeared today that Upshift stopped counting new ones until tomorrow. Crashes are still counted.', 'upshift' );
		}
		foreach ( $notes as $note ) {
			echo '<div class="upshift-note upshift-note--warn"><p>' . esc_html( $note ) . '</p></div>';
		}
	}

	/**
	 * The honest line at the bottom.
	 *
	 * @param array $report Report::build().
	 * @return string
	 */
	private static function honest_line( array $report ) {
		/* translators: %s: percent of page views. */
		return sprintf( __( 'Crashes are counted on every page view. Warnings are counted on about %s%% of page views, so the real numbers can be higher. Crashes in must-use plugins cannot be seen, because they load before Upshift.', 'upshift' ), number_format_i18n( $report['rate'] ) );
	}

	/**
	 * Admin-post: "Start comparing now" (capability + nonce first).
	 */
	public static function handle_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'upshift' ), 403 );
		}
		check_admin_referer( self::START );

		State::save( State::manual( State::get(), State::version(), current_time( 'Y-m-d' ) ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'         => 'watch',
					'upshift_msg' => 'started',
				),
				admin_url( 'tools.php?page=' . Page::SLUG )
			)
		);
		exit;
	}
}
