<?php
/**
 * Extension and engine availability checks (spec §4, §7.4).
 *
 * @package Upshift
 */

namespace Upshift\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether scanning can run on this site. The usage map works either way.
 */
class Requirements {

	/**
	 * PHP extensions PHPCS 4 requires (its composer.json).
	 */
	const EXTENSIONS = array( 'tokenizer', 'simplexml', 'xmlwriter', 'libxml' );

	/**
	 * Reasons scanning is unavailable. Empty array = OK.
	 *
	 * @return string[] Translated, unescaped messages.
	 */
	public static function problems() {
		$problems = array();

		$missing = array();
		foreach ( self::EXTENSIONS as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing[] = $extension;
			}
		}
		if ( $missing ) {
			$problems[] = sprintf(
				/* translators: %s: comma-separated list of PHP extension names. */
				__( 'Scanning needs these PHP extensions, which are missing: %s. Ask your host to enable them.', 'upshift' ),
				implode( ', ', $missing )
			);
		}

		if ( ! is_file( self::vendor_dir() . '/autoload.php' ) || ! is_file( self::vendor_dir() . '/squizlabs/php_codesniffer/autoload.php' ) ) {
			$problems[] = __( 'The scan engine (vendor-prefixed folder) is missing. Reinstall the plugin from the release zip.', 'upshift' );
		}

		return $problems;
	}

	/**
	 * Whether all requirements are met.
	 *
	 * @return bool
	 */
	public static function ok() {
		return array() === self::problems();
	}

	/**
	 * Absolute path of the scoped engine folder.
	 *
	 * @return string
	 */
	public static function vendor_dir() {
		return UPSHIFT_DIR . '/vendor-prefixed';
	}
}
