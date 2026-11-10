<?php
/**
 * Dev-only WP-CLI command `wp upshift simulate-traffic` (spec §7.10). This file is not in the release zip
 * (bin/build-zip.php), so the plugin on wordpress.org makes no HTTP requests at all (DECISIONS D64).
 *
 * @package Upshift
 */

namespace Upshift\Cli;

use Upshift\Settings;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loopback traffic for filling usage data on a local or development site.
 */
class DevCommand {

	/**
	 * Requests this site's own pages over HTTP to fill usage data (dev only, spec §7.2).
	 *
	 * ## OPTIONS
	 *
	 * [--count=<n>]
	 * : Maximum number of URLs (home + latest posts, pages, products).
	 * ---
	 * default: 50
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		// Dev tool (spec §7.10): never sends requests from a staging or production site.
		$environment = wp_get_environment_type();
		if ( ! in_array( $environment, array( 'local', 'development' ), true ) ) {
			WP_CLI::error( sprintf( 'simulate-traffic is a development tool and runs only when wp_get_environment_type() is "local" or "development" (this site: "%s"). Set WP_ENVIRONMENT_TYPE in wp-config.php or as an environment variable on a dev site.', $environment ) );
		}
		$count    = max( 1, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'count', 50 ) );
		$settings = Settings::get();
		if ( empty( $settings['usage_enabled'] ) ) {
			WP_CLI::warning( 'Usage recording is off: these requests will not be recorded.' );
		} elseif ( (float) $settings['sample_rate'] < 1.0 ) {
			WP_CLI::warning( sprintf( 'Sample rate is %s: only about that share of requests is recorded. Use 1.0 for full data.', $settings['sample_rate'] ) );
		}

		$types = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}
		$ids = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => $count,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		$urls = array( home_url( '/' ) );
		foreach ( $ids as $id ) {
			$url = get_permalink( $id );
			if ( $url ) {
				$urls[] = $url;
			}
		}
		$urls = array_slice( array_values( array_unique( $urls ) ), 0, $count );

		$ok = 0;
		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'   => 20,
					'sslverify' => false,
				)
			);
			if ( is_wp_error( $response ) ) {
				WP_CLI::log( sprintf( 'ERR %s %s', $url, $response->get_error_message() ) );
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 400 ) {
				++$ok;
			}
			WP_CLI::log( sprintf( '%d %s', $code, $url ) );
		}
		WP_CLI::success( sprintf( '%d requests sent, %d OK.', count( $urls ), $ok ) );
	}
}
