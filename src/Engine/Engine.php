<?php
/**
 * In-process PHPCS wrapper (spec §7.4). Loads vendor-prefixed/ only when an engine is created.
 *
 * Class and method names are taken from the scoped PHPCS 4.0.4 source in vendor-prefixed/.
 *
 * @package Upshift
 */

namespace Upshift\Engine;

use Upshift\Settings;
use Upshift\Vendor\PHP_CodeSniffer\Config;
use Upshift\Vendor\PHP_CodeSniffer\Files\LocalFile;
use Upshift\Vendor\PHP_CodeSniffer\Runner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One configured PHPCS runner per "from-target" range.
 */
class Engine {

	/**
	 * Engines created in this process, keyed by "from-target".
	 *
	 * @var Engine[]
	 */
	private static $instances = array();

	/**
	 * Range PHPCS and PHPCompatibility currently hold in their statics ('' = none yet).
	 *
	 * @var string
	 */
	private static $active_range = '';

	/**
	 * Whether guard detection runs. Only the dev timing script turns it off (tests/guards-corpus.php).
	 *
	 * @var bool
	 */
	public static $guards_enabled = true;

	/**
	 * Version range for testVersion, e.g. "7.4-8.4".
	 *
	 * @var string
	 */
	private $range;

	/**
	 * Initialized PHPCS runner (config + ruleset).
	 *
	 * @var Runner
	 */
	private $runner;

	/**
	 * Whether scanning can run (extensions + vendor-prefixed present).
	 *
	 * @return bool
	 */
	public static function is_available() {
		return Requirements::ok();
	}

	/**
	 * The default "from" version: the override setting, else the running PHP version.
	 *
	 * @return string
	 */
	public static function default_from() {
		$override = (string) Settings::get( 'from_override' );
		return '' !== $override ? $override : PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	}

	/**
	 * Validates a range against the allow-lists (spec §7.4, version range rule).
	 *
	 * @param string $from   From version.
	 * @param string $target Target version.
	 * @return string Translated error message, or '' when the range is valid.
	 */
	public static function range_error( $from, $target ) {
		$running = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		if ( '' === $from || ( ! in_array( $from, Settings::FROM_VERSIONS, true ) && $running !== $from ) ) {
			/* translators: %s: PHP version string as given. */
			return sprintf( __( 'Unsupported "from" version: %s.', 'upshift' ), $from );
		}
		if ( ! in_array( $target, Settings::TARGET_VERSIONS, true ) ) {
			return sprintf(
				/* translators: 1: PHP version string as given, 2: comma-separated list of allowed versions. */
				__( 'Unsupported target version: %1$s. Allowed: %2$s.', 'upshift' ),
				$target,
				implode( ', ', Settings::TARGET_VERSIONS )
			);
		}
		if ( version_compare( $from, $target, '>=' ) ) {
			return sprintf(
				/* translators: 1: target PHP version, 2: from PHP version. */
				__( 'The target version (%1$s) must be newer than the from version (%2$s). Nothing to check.', 'upshift' ),
				$target,
				$from
			);
		}
		return '';
	}

	/**
	 * Returns the engine for a range, creating it once per process.
	 *
	 * @param string $from   From version, e.g. "7.4".
	 * @param string $target Target version, e.g. "8.4".
	 * @return Engine
	 * @throws \InvalidArgumentException When the range is invalid.
	 * @throws \RuntimeException When the engine is unavailable or PHPCS fails to initialize.
	 */
	public static function for_range( $from, $target ) {
		$from   = (string) $from;
		$target = (string) $target;
		$error  = self::range_error( $from, $target );
		// Exception messages below are escaped where they are displayed.
		if ( '' !== $error ) {
			throw new \InvalidArgumentException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return self::instance( $from . '-' . $target );
	}

	/**
	 * Returns the engine for the "from" version alone (testVersion = from), for the new-risk check (DECISIONS D52):
	 * a finding this engine also reports is already true on the PHP version the site runs now.
	 *
	 * @param string $from From version, e.g. "8.2".
	 * @return Engine
	 * @throws \InvalidArgumentException When the version is not allowed.
	 * @throws \RuntimeException When the engine is unavailable or PHPCS fails to initialize.
	 */
	public static function baseline( $from ) {
		$from = (string) $from;
		if ( '' === $from || ( ! in_array( $from, Settings::FROM_VERSIONS, true ) && PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== $from ) ) {
			/* translators: %s: PHP version string as given. */
			throw new \InvalidArgumentException( sprintf( __( 'Unsupported "from" version: %s.', 'upshift' ), $from ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
		}
		return self::instance( $from );
	}

	/**
	 * Creates the engine for a testVersion value once per process.
	 *
	 * @param string $key testVersion value: "7.4-8.4", or one version.
	 * @return Engine
	 * @throws \RuntimeException When the engine is unavailable or PHPCS fails to initialize.
	 */
	private static function instance( $key ) {
		if ( ! self::is_available() ) {
			throw new \RuntimeException( implode( ' ', Requirements::problems() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( ! isset( self::$instances[ $key ] ) ) {
			self::load();
			$engine = new self( $key );
			try {
				$engine->boot();
			} catch ( \Throwable $e ) {
				throw new \RuntimeException( 'PHPCS init failed: ' . trim( $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			self::$instances[ $key ] = $engine;
		}
		return self::$instances[ $key ];
	}

	/**
	 * Constructor.
	 *
	 * @param string $range testVersion range.
	 */
	private function __construct( $range ) {
		$this->range = $range;
	}

	/**
	 * Scans one file. Never includes or runs it; PHPCS reads it as text.
	 *
	 * @param string $abs_path Absolute file path.
	 * @return array List of array( 'line' => int, 'column' => int, 'severity' => 'error'|'warning', 'rule' => string,
	 *               'message' => string, 'guarded' => 0|1 (from-target scans only, DECISIONS D56) ), sorted by line. On an engine failure: array( 'engine_error' => string ) instead (see is_error()).
	 */
	public function scan_file( $abs_path ) {
		$file = null;
		// Like PHPCS's Runner::handleErrors(): a PHP warning inside a sniff aborts the file. Deprecations
		// raised by the engine's own code on newer PHP are ignored (DECISIONS D22).
		set_error_handler( array( __CLASS__, 'handle_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- restored in finally.
		try {
			if ( self::$active_range !== $this->range ) {
				$this->activate();
			}
			$file = new LocalFile( (string) $abs_path, $this->runner->ruleset, $this->runner->config );
			$file->process();
			$findings = array_merge(
				self::flatten( $file->getErrors(), 'error' ),
				self::flatten( $file->getWarnings(), 'warning' )
			);
			usort(
				$findings,
				function ( $a, $b ) {
					return $a['line'] - $b['line'];
				}
			);
			return $this->mark_guarded( $findings, $file );
		} catch ( \Throwable $e ) {
			return array( 'engine_error' => trim( $e->getMessage() ) );
		} finally {
			restore_error_handler();
			if ( null !== $file ) {
				$file->cleanUp();
			}
			unset( $file );
			self::clear_caches();
		}
	}

	/**
	 * Sets 'guarded' on the findings of a from-target scan (DECISIONS D56). A failure leaves them counted: it must
	 * neither hide a problem nor turn the file into an engine error.
	 *
	 * @param array     $findings Findings.
	 * @param LocalFile $file     The processed file.
	 * @return array
	 */
	private function mark_guarded( array $findings, $file ) {
		$dash = strpos( $this->range, '-' );
		if ( false === $dash || ! self::$guards_enabled ) {
			return $findings;
		}
		try {
			return Guards::mark( $findings, $file->getTokens(), substr( $this->range, $dash + 1 ) );
		} catch ( \Throwable $e ) {
			return $findings;
		}
	}

	/**
	 * Error handler during scan_file().
	 *
	 * @param int    $code    Error level.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 * @return bool
	 * @throws \RuntimeException For every non-muted, non-deprecation error.
	 */
	public static function handle_error( $code, $message, $file, $line ) {
		if ( 0 === ( error_reporting() & $code ) || E_DEPRECATED === $code || E_USER_DEPRECATED === $code ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- reads the level only.
			return true;
		}
		throw new \RuntimeException( "{$message} in {$file} on line {$line}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as engine_error, escaped when displayed.
	}

	/**
	 * Makes this engine's range the one PHPCS and PHPCompatibility use (DECISIONS D22, D52).
	 *
	 * Both keep the range in process-wide statics: PHPCS in its static config data (shared by all
	 * Config objects) and PHPCompatibility in ScannedCode::$testVersion / $testVersions (read once per run).
	 * testVersion is set with setConfigData() instead of --runtime-set (a runtime-set key can't be set again),
	 * so a switch is one call, not a new runner.
	 */
	private function activate() {
		if ( Config::getConfigData( 'testVersion' ) !== $this->range ) {
			$this->runner->config->setConfigData( 'testVersion', $this->range, true );
		}
		$class = 'Upshift\\Vendor\\PHPCompatibility\\Helpers\\ScannedCode';
		if ( class_exists( $class, false ) ) {
			foreach ( array( 'testVersion', 'testVersions' ) as $name ) {
				$property = new \ReflectionProperty( $class, $name );
				if ( PHP_VERSION_ID < 80100 ) {
					$property->setAccessible( true );
				}
				$property->setValue( null, null );
			}
		}
		self::$active_range = $this->range;
	}

	/**
	 * Whether a scan_file() result is an engine failure.
	 *
	 * @param array $result scan_file() result.
	 * @return bool
	 */
	public static function is_error( array $result ) {
		return isset( $result['engine_error'] );
	}

	/**
	 * The "from-target" range this engine checks.
	 *
	 * @return string
	 */
	public function range() {
		return $this->range;
	}

	/**
	 * Loads the scoped engine (spec §7.4 rule 1).
	 */
	private static function load() {
		if ( ! defined( 'PHP_CODESNIFFER_CBF' ) ) {
			define( 'PHP_CODESNIFFER_CBF', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- PHPCS's own constant.
		}
		if ( ! defined( 'PHP_CODESNIFFER_VERBOSITY' ) ) {
			define( 'PHP_CODESNIFFER_VERBOSITY', 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- PHPCS's own constant.
		}
		$vendor = Requirements::vendor_dir();
		require_once $vendor . '/autoload.php';
		require_once $vendor . '/squizlabs/php_codesniffer/autoload.php';
		// Defines PHPCS's global T_* token constants.
		class_exists( 'Upshift\\Vendor\\PHP_CodeSniffer\\Util\\Tokens' );
	}

	/**
	 * Builds the config from an explicit args array and initializes the runner (spec §7.4 rules 2–4).
	 */
	private function boot() {
		// testVersion is set after init(), not with --runtime-set, so activate() can switch it (DECISIONS D52).
		$args = array(
			'--standard=PHPCompatibilityWP',
			'--extensions=php,inc',
			'--ignore-annotations',
			'-q',
			'--no-cache',
		);
		// A non-empty array, so Config never reads $_SERVER['argv'].
		$config = new Config( $args );

		$vendor = Requirements::vendor_dir();
		$paths  = array(
			$vendor . '/phpcompatibility/php-compatibility',
			$vendor . '/phpcompatibility/phpcompatibility-paragonie',
			$vendor . '/phpcompatibility/phpcompatibility-wp',
			$vendor . '/phpcsstandards/phpcsutils',
		);
		// Third argument true = temporary, in memory only; CodeSniffer.conf is never written.
		$config->setConfigData( 'installed_paths', implode( ',', $paths ), true );

		$runner         = new Runner();
		$runner->config = $config;
		$runner->init();
		// After init(): the PHPCompatibilityWP ruleset sets its own testVersion ("7.2-") while it loads.
		$config->setConfigData( 'testVersion', $this->range, true );
		$this->runner = $runner;
		// Booting changed the shared statics: the next scan_file() of any engine must activate its range.
		self::$active_range = '';
	}

	/**
	 * Flattens PHPCS's line → column → messages arrays.
	 *
	 * @param array  $messages getErrors() or getWarnings() result.
	 * @param string $severity 'error' or 'warning'.
	 * @return array
	 */
	private static function flatten( $messages, $severity ) {
		$out = array();
		foreach ( (array) $messages as $line => $columns ) {
			foreach ( (array) $columns as $column => $list ) {
				foreach ( (array) $list as $message ) {
					$out[] = array(
						'line'     => (int) $line,
						'column'   => (int) $column,
						'severity' => $severity,
						'rule'     => (string) $message['source'],
						'message'  => (string) $message['message'],
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Clears PHPCSUtils' static caches after each file (spec §7.4 rule 5).
	 */
	private static function clear_caches() {
		foreach ( array( 'Upshift\\Vendor\\PHPCSUtils\\Internal\\Cache', 'Upshift\\Vendor\\PHPCSUtils\\Internal\\NoFileCache' ) as $class ) {
			if ( class_exists( $class, false ) ) {
				$class::clear();
			}
		}
	}
}
