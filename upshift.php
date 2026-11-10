<?php
/**
 * Plugin Name:       Upshift - PHP Upgrade Check & Guard
 * Description:       Checks your plugins and themes for code that breaks on a newer PHP version. Optional: see which of that code really runs on your site, and get warned when a plugin starts failing after a PHP change.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Muhammad Qasim
 * Author URI:        https://profiles.wordpress.org/qasimwp/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       upshift
 *
 * @package Upshift
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPSHIFT_VERSION', '1.0.0' );
define( 'UPSHIFT_DB_VERSION', '11' );
define( 'UPSHIFT_FILE', __FILE__ );
define( 'UPSHIFT_DIR', __DIR__ );

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'Upshift\\';
		if ( 0 !== strpos( $class_name, $prefix ) || 0 === strpos( $class_name, 'Upshift\\Vendor\\' ) ) {
			return;
		}
		$file = UPSHIFT_DIR . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

\Upshift\Plugin::boot();
