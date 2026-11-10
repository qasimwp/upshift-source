<?php
/**
 * The Help tab. The only place with a review request or a link to the author's other plugin.
 *
 * @package Upshift
 */

namespace Upshift\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendered from Page::render(), which checks the capability first. No external requests: links only.
 */
class HelpTab {

	/**
	 * A link that opens in a new tab.
	 *
	 * @param string $url  Address.
	 * @param string $text Link text.
	 */
	private static function link( $url, $text ) {
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Renders the tab: help on the left, the author on the right.
	 */
	public static function render() {
		echo '<div class="upshift-help-grid">';
		self::render_help();
		self::render_author();
		echo '</div>';
	}

	/**
	 * Left card: what Upshift does, quick start, questions, links.
	 */
	private static function render_help() {
		echo '<div class="upshift-card upshift-help">';

		echo '<h2>' . esc_html__( 'What Upshift does', 'upshift' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upshift reads the code of your plugins and themes and tells you what would break on a newer PHP version. It can also learn which plugins your site really uses, and tell you if a plugin starts crashing after your host changes PHP.', 'upshift' ) . '</p>';

		echo '<h2>' . esc_html__( 'Quick start', 'upshift' ) . '</h2>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'Click Check.', 'upshift' ) . '</li>';
		echo '<li>' . esc_html__( 'Fix "Do this first".', 'upshift' ) . '</li>';
		echo '<li>' . esc_html__( 'Turn on Upgrade guard before your host changes PHP.', 'upshift' ) . '</li>';
		echo '</ol>';

		echo '<h2>' . esc_html__( 'Questions', 'upshift' ) . '</h2>';
		echo '<h3>' . esc_html__( 'Does Upshift send my data anywhere?', 'upshift' ) . '</h3>';
		echo '<p>' . esc_html__( "No. Everything stays in your database. The only exception is the crash email, if you turn it on: it goes to your site's admin address.", 'upshift' ) . '</p>';
		echo '<h3>' . esc_html__( 'Will it slow my site?', 'upshift' ) . '</h3>';
		echo '<p>' . esc_html__( 'Upshift is built to stay light. By default it looks at only some page views and stores very little.', 'upshift' ) . '</p>';
		echo '<h3>' . esc_html__( "What does 'not seen running' mean?", 'upshift' ) . '</h3>';
		echo '<p>' . esc_html__( "Upshift didn't see that code run on the page views it looked at, but it may still run at other times.", 'upshift' ) . '</p>';

		echo '<p class="upshift-help__links">';
		self::link( Links::SUPPORT, __( 'Get help', 'upshift' ) );
		echo ' &nbsp;·&nbsp; ';
		self::link( Links::REVIEWS, __( 'Leave a review', 'upshift' ) );
		echo '</p>';

		echo '</div>';
	}

	/**
	 * Right card: the author. No image from outside: the circle is drawn with CSS.
	 */
	private static function render_author() {
		echo '<aside class="upshift-card upshift-author" aria-label="' . esc_attr__( 'About the author', 'upshift' ) . '">';
		echo '<div class="upshift-author__head">';
		echo '<span class="upshift-author__avatar" aria-hidden="true">' . esc_html_x( 'MQ', 'initials of the author', 'upshift' ) . '</span>';
		echo '<div><p class="upshift-author__name">' . esc_html__( 'Muhammad Qasim', 'upshift' ) . '</p>';
		echo '<p class="upshift-muted upshift-author__role">' . esc_html__( 'WordPress developer', 'upshift' ) . '</p></div>';
		echo '</div>';
		echo '<p>' . esc_html__( "I've been building WordPress plugins, themes and WooCommerce integrations for over 10 years. I made Upshift because a PHP upgrade shouldn't be a guessing game for site owners.", 'upshift' ) . '</p>';
		echo '<p class="upshift-author__also">' . esc_html__( 'Also by Muhammad:', 'upshift' ) . ' ';
		self::link( Links::CHECKOUT_LAB, __( 'Checkout Test Lab for WooCommerce', 'upshift' ) );
		echo ' ' . esc_html__( '- place safe test orders and see why a shipping or payment method is missing.', 'upshift' ) . '</p>';
		/* translators: %s: plugin version. */
		echo '<p class="upshift-muted upshift-author__version">' . esc_html( sprintf( __( 'Upshift version %s', 'upshift' ), UPSHIFT_VERSION ) ) . '</p>';
		echo '</aside>';
	}
}
