<?php
/**
 * The Plugins screen badge, the Site Health test and the one notice after activation (docs/UI-v1.md §4, §8a).
 * All three read the small cached summary (Report\Status) and never query the findings table.
 *
 * @package Upshift
 */

namespace Upshift\Admin;

use Upshift\Report\Status;
use Upshift\Usage\Health;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks registered in Plugin::boot() with literal class names, so this class loads only when one of them fires.
 */
class Integrations {

	/**
	 * Colour of a status for the badge. The word is always shown, so colour is never the only signal.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function colour( $status ) {
		switch ( $status ) {
			case Status::BREAKS:
				return '#c62828';
			case Status::HOST:
				return '#7a4300';
			case Status::LATER:
				return '#614200';
			default:
				return '#005c12';
		}
	}

	/**
	 * Text of a badge, e.g. "PHP 8.4: Ready".
	 *
	 * @param string $status Status.
	 * @param string $target Target PHP version.
	 * @return string
	 */
	public static function badge_text( $status, $target ) {
		/* translators: 1: target PHP version, 2: result, e.g. "Ready" or "Will break". */
		return sprintf( __( 'PHP %1$s: %2$s', 'upshift' ), $target, Status::label( $status ) );
	}

	/**
	 * Badge for one plugin row, linked to the Check tab.
	 *
	 * @param string $status Status.
	 * @param string $target Target PHP version.
	 * @return string Escaped HTML.
	 */
	public static function badge_html( $status, $target ) {
		return sprintf(
			'<a href="%1$s" style="color:%2$s;font-weight:600;white-space:nowrap"><span class="dashicons %3$s" aria-hidden="true" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span> %4$s</a>',
			esc_url( admin_url( 'tools.php?page=' . Page::SLUG ) ),
			esc_attr( self::colour( $status ) ),
			esc_attr( Status::icon( $status ) ),
			esc_html( self::badge_text( $status, $target ) )
		);
	}

	/**
	 * Filter plugin_action_links_{basename}: "Settings" and "Check my site" before the stock links.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}
		$base = admin_url( 'tools.php' );
		$mine = array(
			'upshift_settings' => '<a href="' . esc_url(
				add_query_arg(
					array(
						'page' => Page::SLUG,
						'tab'  => 'settings',
					),
					$base
				)
			) . '">' . esc_html__( 'Settings', 'upshift' ) . '</a>',
			'upshift_check'    => '<a href="' . esc_url( add_query_arg( 'page', Page::SLUG, $base ) ) . '">' . esc_html__( 'Check my site', 'upshift' ) . '</a>',
		);
		return array_merge( $mine, (array) $links );
	}

	/**
	 * Filter plugin_row_meta: adds the badge after a check exists.
	 *
	 * @param array  $meta Row meta links.
	 * @param string $file Plugin file, e.g. "foo/foo.php".
	 * @return array
	 */
	public static function row_meta( $meta, $file ) {
		$summary = Status::summary();
		if ( null === $summary ) {
			return $meta;
		}
		$dir = dirname( (string) $file );
		$id  = 'plugin:' . ( '.' === $dir ? $file : $dir );
		if ( isset( $summary['components'][ $id ]['status'] ) ) {
			$meta[] = self::badge_html( (string) $summary['components'][ $id ]['status'], (string) $summary['target'] );
		}
		return $meta;
	}

	/**
	 * Filter site_status_tests: one direct test once a check exists.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function site_health_tests( $tests ) {
		if ( null !== Status::summary() ) {
			$tests['direct']['upshift_php'] = array(
				'label' => __( 'Your plugins and PHP version', 'upshift' ),
				'test'  => array( __CLASS__, 'site_health_result' ),
			);
		}
		$tests['direct']['upshift_recording'] = array(
			'label' => __( 'Upshift Smart results', 'upshift' ),
			'test'  => array( __CLASS__, 'site_health_recording' ),
		);
		return $tests;
	}

	/**
	 * Recording problems as one notice, on the Settings and Check tabs (DECISIONS D63). Prints nothing when all is well.
	 */
	public static function render_recording_notice() {
		$messages = Health::messages();
		if ( empty( $messages ) ) {
			return;
		}
		echo '<div class="notice notice-warning inline">';
		foreach ( $messages as $message ) {
			echo '<p>' . esc_html( $message ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The Site Health result for recording problems (DECISIONS D63).
	 *
	 * @return array
	 */
	public static function site_health_recording() {
		$messages = Health::messages();
		$ok       = empty( $messages );
		$text     = '';
		foreach ( $ok ? array( __( 'Upshift is recording normally, or Smart results is off.', 'upshift' ) ) : $messages as $message ) {
			$text .= '<p>' . esc_html( $message ) . '</p>';
		}
		return array(
			'label'       => $ok ? __( 'Upshift Smart results is working', 'upshift' ) : __( 'Upshift Smart results needs a look', 'upshift' ),
			'status'      => $ok ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'PHP upgrade', 'upshift' ),
				'color' => $ok ? 'green' : 'orange',
			),
			'description' => $text,
			'actions'     => '',
			'test'        => 'upshift_recording',
		);
	}

	/**
	 * The Site Health result: the same answer sentence as the Check tab.
	 *
	 * @return array
	 */
	public static function site_health_result() {
		$summary = Status::summary();
		$answer  = null !== $summary ? Status::answer( $summary ) : array(
			'level'    => 'green',
			'sentence' => '',
		);
		$target  = null !== $summary ? (string) $summary['target'] : '';
		$map     = array(
			'green'  => array( 'good', 'green' ),
			'yellow' => array( 'recommended', 'orange' ),
			'orange' => array( 'recommended', 'orange' ),
			'red'    => array( 'critical', 'red' ),
		);
		$status  = isset( $map[ $answer['level'] ] ) ? $map[ $answer['level'] ] : $map['green'];

		return array(
			/* translators: %s: target PHP version. */
			'label'       => sprintf( __( 'Your plugins and PHP %s', 'upshift' ), $target ),
			'status'      => $status[0],
			'badge'       => array(
				'label' => __( 'PHP upgrade', 'upshift' ),
				'color' => $status[1],
			),
			'description' => '<p>' . esc_html( $answer['sentence'] ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . Page::SLUG ) ) . '">' . esc_html__( 'See the details', 'upshift' ) . '</a></p>',
			'test'        => 'upshift_php',
		);
	}

	/**
	 * The one notice after activation. It is shown once: the flag goes as soon as an admin page prints it.
	 */
	public static function activation_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( 'upshift_show_notice' );
		// Read-only check of the current page; no state change, so no nonce.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Page::SLUG === $page ) {
			return;
		}
		$link = '<a href="' . esc_url( admin_url( 'tools.php?page=' . Page::SLUG ) ) . '">' . esc_html__( 'Tools → PHP Upgrade', 'upshift' ) . '</a>';
		/* translators: %s: link to the Tools → PHP Upgrade page. */
		echo '<div class="notice notice-info is-dismissible"><p>' . wp_kses_post( sprintf( esc_html__( 'Upshift is ready. Open %s to check your site for PHP upgrade problems.', 'upshift' ), $link ) ) . '</p></div>';
	}
}
