<?php
/**
 * Scan AJAX endpoints (spec §7.6): start, batch, cancel, status.
 *
 * @package Upshift
 */

namespace Upshift\Admin;

use Upshift\Scan\Runner;
use Upshift\Settings;
use Upshift\Usage\Recorder;
use Upshift\Usage\Rollup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every handler checks the capability and its nonce (`upshift_scan`, or `upshift_rollup` for the rollup step) before
 * any work.
 */
class Ajax {

	const NONCE        = 'upshift_scan';
	const ROLLUP_NONCE = 'upshift_rollup';

	/**
	 * Smallest and largest time budget a batch request may ask for (seconds; DECISIONS D54).
	 */
	const BUDGET_MIN = 5;
	const BUDGET_MAX = 15;

	/**
	 * Starts a scan. POST: from, target, inactive (1 = also scan inactive plugins; default no, DECISIONS D54).
	 *
	 * Every scan answer (start, batch, cancel, status) carries Runner::progress() plus Runner::progress_detail(): components
	 * done and total, the current component with its file counts, files reused and whether the scan runs in the
	 * background (DECISIONS D57).
	 */
	public static function start() {
		self::guard();

		$from     = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$target   = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$inactive = isset( $_POST['inactive'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['inactive'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().

		// Runner::start() validates the range against the allow-lists.
		$scan_id = Runner::start( $from, $target, '', $inactive );
		if ( is_wp_error( $scan_id ) ) {
			$data = (array) $scan_id->get_error_data();
			wp_send_json_error(
				array(
					'code'    => $scan_id->get_error_code(),
					'message' => $scan_id->get_error_message(),
					'scan_id' => isset( $data['scan_id'] ) ? (int) $data['scan_id'] : 0,
				)
			);
		}

		// Remember the target as the form default. "From" is not saved: the form defaults to the running PHP (T6).
		Settings::update( array( 'default_target' => $target ) );

		wp_send_json_success( Runner::progress_detail( Runner::progress( Runner::get( $scan_id ) ) ) );
	}

	/**
	 * Runs one batch. POST: scan_id, budget (optional seconds; admin.js sends 8 after a resource error, DECISIONS D54), slow (1 in gentle mode, D57).
	 */
	public static function batch() {
		self::guard();

		$budget  = self::budget( isset( $_POST['budget'] ) ? sanitize_text_field( wp_unslash( $_POST['budget'] ) ) : null ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$slow    = isset( $_POST['slow'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['slow'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$scan_id = self::scan_id();
		// This page is driving the scan: the background step (WP-Cron) leaves it alone for a while (DECISIONS D57).
		Runner::page_batch( $scan_id, $slow );
		$result = Runner::batch( $scan_id, null === $budget ? array() : array( 'time_budget' => $budget ) );
		if ( ! is_wp_error( $result ) && 'running' === $result['status'] ) {
			Runner::ensure_step( $scan_id, Runner::STEP_DELAY );
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
			);
		}
		wp_send_json_success( Runner::progress_detail( $result ) );
	}

	/**
	 * Cancels a scan. POST: scan_id.
	 */
	public static function cancel() {
		self::guard();

		$scan_id = self::scan_id();
		Runner::cancel( $scan_id );
		wp_send_json_success( Runner::progress_detail( Runner::progress( Runner::get( $scan_id ) ) ) );
	}

	/**
	 * Status of a scan. POST: scan_id (0 = latest).
	 */
	public static function status() {
		self::guard();

		$scan_id = self::scan_id();
		$scan    = $scan_id > 0 ? Runner::get( $scan_id ) : Runner::latest();
		if ( null === $scan ) {
			wp_send_json_error(
				array(
					'code'    => 'not_found',
					'message' => __( 'Scan not found.', 'upshift' ),
				)
			);
		}
		wp_send_json_success( Runner::progress_detail( Runner::progress( $scan ) ) );
	}

	/**
	 * "Update usage data now": one time-budgeted rollup step (DECISIONS D48). POST: start (1 on the first call of
	 * the loop: starts a rollup if none is running). The JS calls it again while `running` is true.
	 */
	public static function rollup_step() {
		self::guard( self::ROLLUP_NONCE );

		$start  = ! empty( $_POST['start'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$result = Rollup::guarded(
			function () use ( $start ) {
				return Rollup::step( $start );
			}
		);
		if ( false === $result ) {
			$flags = Recorder::flags();
			wp_send_json_error(
				array(
					'code'    => 'rollup_error',
					'message' => isset( $flags['last_error'] ) ? (string) $flags['last_error'] : __( 'Unknown error.', 'upshift' ),
				)
			);
		}
		$meta                = Rollup::meta();
		$result['meta_time'] = false !== $meta ? (int) $meta['time'] : 0;
		wp_send_json_success( $result );
	}

	/**
	 * Capability + nonce check; ends the request on failure.
	 *
	 * @param string $nonce Nonce action.
	 */
	private static function guard( $nonce = self::NONCE ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __( 'You do not have permission to do this.', 'upshift' ),
				),
				403
			);
		}
		check_ajax_referer( $nonce, 'nonce' );
	}

	/**
	 * Time budget asked for by a batch request: clamped to 5–15 s and never above Runner::time_budget().
	 *
	 * @param string|null $raw Posted value (null = not sent).
	 * @return float|null Seconds, or null (not sent or not a number: the runner's own budget).
	 */
	public static function budget( $raw ) {
		if ( null === $raw || ! is_numeric( $raw ) ) {
			return null;
		}
		$budget = max( (float) self::BUDGET_MIN, min( (float) self::BUDGET_MAX, (float) $raw ) );
		return min( $budget, Runner::time_budget() );
	}

	/**
	 * Scan id from the request.
	 *
	 * @return int
	 */
	private static function scan_id() {
		return isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
	}
}
