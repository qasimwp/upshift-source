<?php
/**
 * Builds vendor-prefixed/ (the scoped PHPCS engine). Dev script, not shipped. Usage: php bin/build-engine.php
 *
 * Steps (spec §7.3):
 * 1. composer install --no-dev --prefer-dist --working-dir=engine
 * 2. PHP-Scoper (tools/php-scoper.phar, scoper.inc.php): engine/ → build/engine-scoped/, prefix Upshift\Vendor
 * 3. composer dump-autoload --classmap-authoritative --no-dev --working-dir=build/engine-scoped
 * 4. Copy build/engine-scoped/vendor → build/vendor-prefixed.tmp/
 * 5. Strip unused PHPCS standards, tests, docs, bin, *.md (except licenses), phpunit*, .github, *.dist, CodeSniffer.conf*;
 *    check that no exec-family call / backtick / eval() is left and that every patch landed
 * 6. Write the BUILD-OK marker, swap the temp folder in as vendor-prefixed/, print size and package versions
 * vendor-prefixed/ is replaced only when every step passed (DECISIONS D47); a failed build leaves it as it was.
 *
 * @package Upshift
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions -- CLI dev script, no WordPress loaded.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/engine-inputs.php';

if ( PHP_VERSION_ID < 80200 ) {
	fwrite( STDERR, 'PHP-Scoper needs PHP 8.2+, this is ' . PHP_VERSION . ".\n" );
	exit( 1 );
}

$upshift_root   = dirname( __DIR__ );
$upshift_scoper = $upshift_root . '/tools/php-scoper.phar';
$upshift_scoped = $upshift_root . '/build/engine-scoped';
$upshift_final  = $upshift_root . '/vendor-prefixed';
$upshift_target = $upshift_root . '/build/vendor-prefixed.tmp';
$upshift_old    = $upshift_root . '/build/vendor-prefixed.old';

if ( ! is_file( $upshift_scoper ) ) {
	fwrite( STDERR, "Missing tools/php-scoper.phar.\n" );
	exit( 1 );
}

/**
 * Runs a shell command and stops the build when it fails.
 *
 * @param string $label   Step label.
 * @param string $command Command line.
 */
function upshift_build_run( $label, $command ) {
	echo "\n== {$label}\n> {$command}\n";
	passthru( $command, $code );
	if ( 0 !== $code ) {
		fwrite( STDERR, "Step failed (exit {$code}): {$label}\n" );
		exit( 1 );
	}
}

/**
 * Stops the build.
 *
 * @param string $message Reason.
 */
function upshift_build_fail( $message ) {
	fwrite( STDERR, $message . "\nBuild FAILED; vendor-prefixed/ was not changed.\n" );
	exit( 1 );
}

/**
 * Deletes a file or a directory tree; a failed delete (e.g. a file locked on Windows) stops the build.
 *
 * @param string $path Path.
 */
function upshift_build_delete( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		if ( ! unlink( $path ) ) {
			upshift_build_fail( "Cannot delete {$path}" );
		}
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$ok = $item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		if ( ! $ok ) {
			upshift_build_fail( 'Cannot delete ' . $item->getPathname() );
		}
	}
	if ( ! rmdir( $path ) ) {
		upshift_build_fail( "Cannot delete {$path}" );
	}
}

/**
 * Copies a directory tree; any failed mkdir/copy stops the build.
 *
 * @param string $from Source directory.
 * @param string $to   Destination directory (created).
 */
function upshift_build_copy( $from, $to ) {
	if ( ! is_dir( $to ) && ! mkdir( $to, 0755, true ) ) {
		upshift_build_fail( "Cannot create {$to}" );
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $items as $item ) {
		$dest = $to . '/' . str_replace( '\\', '/', substr( $item->getPathname(), strlen( $from ) + 1 ) );
		if ( $item->isDir() ) {
			if ( ! is_dir( $dest ) && ! mkdir( $dest, 0755, true ) ) {
				upshift_build_fail( "Cannot create {$dest}" );
			}
		} elseif ( ! copy( $item->getPathname(), $dest ) ) {
			upshift_build_fail( "Cannot copy {$dest}" );
		}
	}
}

/**
 * Total size of a directory tree in bytes, and its file count.
 *
 * @param string $path Directory.
 * @return array{0:int,1:int}
 */
function upshift_build_size( $path ) {
	$bytes = 0;
	$files = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $item ) {
		if ( $item->isFile() ) {
			$bytes += $item->getSize();
			++$files;
		}
	}
	return array( $bytes, $files );
}

$upshift_composer = 'composer';
$upshift_php      = escapeshellarg( PHP_BINARY );

// 1. Install the engine packages (versions pinned in engine/composer.json, DECISIONS D3).
upshift_build_run( '1/6 composer install (engine)', $upshift_composer . ' install --no-dev --prefer-dist --no-interaction --working-dir=' . escapeshellarg( $upshift_root . '/engine' ) );

// 2. Scope.
upshift_build_delete( $upshift_scoped );
upshift_build_run(
	'2/6 PHP-Scoper',
	$upshift_php . ' ' . escapeshellarg( $upshift_scoper ) . ' add-prefix --no-interaction --force'
	. ' --config=' . escapeshellarg( $upshift_root . '/scoper.inc.php' )
	. ' --output-dir=' . escapeshellarg( $upshift_scoped )
);

// 3. Regenerate the Composer autoloader for the scoped class names.
upshift_build_run( '3/6 composer dump-autoload (scoped)', $upshift_composer . ' dump-autoload --classmap-authoritative --no-dev --no-interaction --working-dir=' . escapeshellarg( $upshift_scoped ) );

// 4. Copy into a temp folder; vendor-prefixed/ is only replaced after every check passed (step 6).
echo "\n== 4/6 copy build/engine-scoped/vendor -> build/vendor-prefixed.tmp/\n";
upshift_build_delete( $upshift_target );
upshift_build_copy( $upshift_scoped . '/vendor', $upshift_target );

// 5. Strip.
echo "\n== 5/6 strip\n";
$upshift_standards_dir = $upshift_target . '/squizlabs/php_codesniffer/src/Standards';
$upshift_referenced    = array();
foreach ( array( 'phpcompatibility', 'phpcsstandards' ) as $upshift_vendor ) {
	$upshift_rulesets = new RegexIterator(
		new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $upshift_target . '/' . $upshift_vendor, FilesystemIterator::SKIP_DOTS ) ),
		'#[\\\\/]ruleset\.xml$#'
	);
	foreach ( $upshift_rulesets as $upshift_ruleset ) {
		preg_match_all( '#<rule\s+ref="([A-Za-z0-9_]+)#', (string) file_get_contents( $upshift_ruleset->getPathname() ), $upshift_m );
		foreach ( $upshift_m[1] as $upshift_ref ) {
			$upshift_referenced[ $upshift_ref ] = true;
		}
	}
}
echo 'Standards referenced by the PHPCompatibility* / PHPCSUtils rulesets: ' . implode( ', ', array_keys( $upshift_referenced ) ) . "\n";
$upshift_kept = array();
foreach ( (array) glob( $upshift_standards_dir . '/*', GLOB_ONLYDIR ) as $upshift_standard ) {
	$upshift_name = basename( $upshift_standard );
	if ( isset( $upshift_referenced[ $upshift_name ] ) ) {
		$upshift_kept[] = $upshift_name;
		continue;
	}
	upshift_build_delete( $upshift_standard );
}
echo 'PHPCS standards kept: ' . ( $upshift_kept ? implode( ', ', $upshift_kept ) : '(none)' ) . "\n";

$upshift_strip_dirs = array( 'tests', 'Tests', 'docs', 'bin', '.github' );
$upshift_removed    = 0;
$upshift_items      = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $upshift_target, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);
$upshift_to_delete  = array();
foreach ( $upshift_items as $upshift_item ) {
	$upshift_base = $upshift_item->getFilename();
	if ( preg_match( '/^(LICEN[SC]E|COPYING)/i', $upshift_base ) ) {
		continue;
	}
	if ( $upshift_item->isDir() ) {
		if ( in_array( $upshift_base, $upshift_strip_dirs, true ) ) {
			$upshift_to_delete[] = $upshift_item->getPathname();
		}
		continue;
	}
	if ( preg_match( '/(\.md$|^phpunit|\.dist$|^CodeSniffer\.conf)/i', $upshift_base ) ) {
		$upshift_to_delete[] = $upshift_item->getPathname();
	}
}
foreach ( $upshift_to_delete as $upshift_path ) {
	if ( file_exists( $upshift_path ) ) {
		upshift_build_delete( $upshift_path );
		++$upshift_removed;
	}
}
echo "Removed {$upshift_removed} files/folders.\n";

// PHPCS files we never load that run shell commands (DECISIONS D28): VCS blame and notify reports, the
// diff report, the Git filters. The remaining calls in Config, Common and Fixer are patched in scoper.inc.php.
$upshift_unused = array(
	'src/Reports/Gitblame.php',
	'src/Reports/Hgblame.php',
	'src/Reports/Svnblame.php',
	'src/Reports/VersionControl.php',
	'src/Reports/Notifysend.php',
	'src/Reports/Diff.php',
	'src/Filters/GitModified.php',
	'src/Filters/GitStaged.php',
);
foreach ( $upshift_unused as $upshift_path ) {
	$upshift_path = $upshift_target . '/squizlabs/php_codesniffer/' . $upshift_path;
	if ( ! is_file( $upshift_path ) ) {
		upshift_build_fail( "Expected PHPCS file to strip is missing (PHPCS update?): {$upshift_path}" );
	}
	upshift_build_delete( $upshift_path );
}
echo 'Removed ' . count( $upshift_unused ) . " unused PHPCS files with shell calls.\n";

// Parts the plugin never loads, because it uses PHPCS as a library (no CLI, reports, doc generator, cache file or
// Composer metadata at run time) (DECISIONS D64). Proved by tests/engine-strip-check.php: the same findings before and
// after on a wide set of plugins. Each path must exist, so an upstream move fails the build instead of shipping it.
$upshift_strip_trees = array(
	'phpcompatibility/php-compatibility/PHPCompatibility/Docs', // XML docs for `phpcs --generator`.
	'squizlabs/php_codesniffer/src/Generators',                 // The doc generator.
	'squizlabs/php_codesniffer/src/Reports',                    // CLI reports; the engine reads errors from the File object.
	'phpcsstandards/phpcsutils/PHPCSUtils/TestUtils',           // PHPUnit helpers.
);
$upshift_strip_files = array(
	'squizlabs/php_codesniffer/requirements.php', // CLI requirement check.
	'squizlabs/php_codesniffer/phpcs.xsd',        // Ruleset schema for editors.
	'composer/installed.json',                    // Composer metadata (InstalledVersions uses installed.php).
	'squizlabs/php_codesniffer/composer.json',
	'phpcompatibility/php-compatibility/composer.json',
	'phpcompatibility/phpcompatibility-wp/composer.json',
	'phpcompatibility/phpcompatibility-paragonie/composer.json',
	'phpcsstandards/phpcsutils/composer.json',
);
foreach ( array_merge( $upshift_strip_trees, $upshift_strip_files ) as $upshift_path ) {
	if ( ! file_exists( $upshift_target . '/' . $upshift_path ) ) {
		upshift_build_fail( "Expected engine path to strip is missing (package update?): {$upshift_path}" );
	}
	upshift_build_delete( $upshift_target . '/' . $upshift_path );
}
echo 'Removed ' . count( $upshift_strip_trees ) . ' unused folders and ' . count( $upshift_strip_files ) . " unused files (docs, generators, reports, test utils, Composer metadata).\n";

// No exec-family call, backtick operator or eval() may remain (CLAUDE.md, D7, D28, D43). Token-based, so rule
// messages that only mention a function name (PHPCompatibility sniffs) don't count.
$upshift_banned = array( 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec' );
$upshift_found  = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $upshift_target, FilesystemIterator::SKIP_DOTS ) ) as $upshift_item ) {
	if ( ! preg_match( '/\.(php|inc)$/', $upshift_item->getFilename() ) ) {
		continue;
	}
	$upshift_tokens = array_values(
		array_filter(
			token_get_all( (string) file_get_contents( $upshift_item->getPathname() ) ),
			function ( $token ) {
				return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
	foreach ( $upshift_tokens as $upshift_i => $upshift_token ) {
		if ( '`' === $upshift_token ) {
			$upshift_found[] = $upshift_item->getPathname() . ': backtick operator';
			continue;
		}
		// eval() of scanned code (DECISIONS D43).
		if ( is_array( $upshift_token ) && T_EVAL === $upshift_token[0] ) {
			$upshift_found[] = $upshift_item->getPathname() . ':' . $upshift_token[2] . ': eval()';
			continue;
		}
		if ( ! is_array( $upshift_token ) || ! in_array( $upshift_token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) { // phpcs:ignore PHPCompatibility.Constants.NewConstants -- dev script, exits below PHP 8.2.
			continue;
		}
		$upshift_next = isset( $upshift_tokens[ $upshift_i + 1 ] ) ? $upshift_tokens[ $upshift_i + 1 ] : null;
		$upshift_prev = $upshift_i > 0 ? $upshift_tokens[ $upshift_i - 1 ] : null;
		if ( '(' !== $upshift_next || ! in_array( strtolower( ltrim( $upshift_token[1], '\\' ) ), $upshift_banned, true ) ) {
			continue;
		}
		if ( is_array( $upshift_prev ) && in_array( $upshift_prev[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) { // phpcs:ignore PHPCompatibility.Constants.NewConstants -- dev script, exits below PHP 8.2.
			continue;
		}
		$upshift_found[] = $upshift_item->getPathname() . ':' . $upshift_token[2] . ': ' . $upshift_token[1] . '()';
	}
}
if ( $upshift_found ) {
	upshift_build_fail( "exec-family or eval() calls left in the engine:\n  " . implode( "\n  ", $upshift_found ) );
}
echo "exec-family / eval check: 0 calls (exec, shell_exec, system, passthru, proc_open, popen, pcntl_exec, backticks, eval).\n";

// Every scoper.inc.php patch must have landed. A patcher only runs when PHP-Scoper processes a file matching its
// path pattern, so a moved or renamed upstream file would skip its patch silently (review finding #10).
$upshift_markers = array(
	'squizlabs/php_codesniffer/src/Util/Common.php' => 1,
	'squizlabs/php_codesniffer/src/Config.php'      => 2,
	'squizlabs/php_codesniffer/src/Fixer.php'       => 1,
	'phpcompatibility/php-compatibility/PHPCompatibility/Sniffs/ControlStructures/DiscouragedSwitchContinueSniff.php' => 1,
	'phpcsstandards/phpcsutils/PHPCSUtils/AbstractSniffs/AbstractArrayDeclarationSniff.php' => 1,
);
$upshift_total   = 0;
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $upshift_target, FilesystemIterator::SKIP_DOTS ) ) as $upshift_item ) {
	if ( $upshift_item->isFile() ) {
		$upshift_total += substr_count( (string) file_get_contents( $upshift_item->getPathname() ), 'Upshift (DECISIONS' );
	}
}
if ( array_sum( $upshift_markers ) !== $upshift_total ) {
	upshift_build_fail( 'Patch markers: expected ' . array_sum( $upshift_markers ) . " \"Upshift (DECISIONS\" markers in the engine, found {$upshift_total}." );
}
foreach ( $upshift_markers as $upshift_file => $upshift_expected ) {
	$upshift_count = substr_count( (string) @file_get_contents( $upshift_target . '/' . $upshift_file ), 'Upshift (DECISIONS' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file counts 0.
	if ( $upshift_expected !== $upshift_count ) {
		upshift_build_fail( "Patch markers: expected {$upshift_expected} in {$upshift_file}, found {$upshift_count}." );
	}
}
// Patches without a marker comment: Fixer's proc_open line (D28) and PHPCS's autoloader (D21).
$upshift_fixer    = (string) file_get_contents( $upshift_target . '/squizlabs/php_codesniffer/src/Fixer.php' );
$upshift_autoload = (string) file_get_contents( $upshift_target . '/squizlabs/php_codesniffer/autoload.php' );
$upshift_own      = 'Upshift\\\\Vendor\\\\PHP_CodeSniffer\\\\';
$upshift_needles  = array(
	array( $upshift_fixer, '$process = \\false;', 'Fixer.php' ),
	array( $upshift_autoload, 'substr($className, 0, 31) === \'' . $upshift_own . '\'', 'autoload.php' ),
	array( $upshift_autoload, 'substr($className, 0, 37) === \'' . $upshift_own . 'Tests\\\\\'', 'autoload.php' ),
	array( $upshift_autoload, '$className = strpos($className, \'Upshift\\\\Vendor\\\\\') === 0 ? substr($className, 15) : $className;', 'autoload.php' ),
);
foreach ( $upshift_needles as $upshift_needle ) {
	if ( 1 !== substr_count( $upshift_needle[0], $upshift_needle[1] ) ) {
		upshift_build_fail( "Patch check: expected exactly one \"{$upshift_needle[1]}\" in {$upshift_needle[2]}." );
	}
}
echo 'Patch check: ' . $upshift_total . " markers in the 5 patched files, Fixer proc_open line and 3 PHPCS autoloader patches present.\n";

// 6. Marker, swap, report.
echo "\n== 6/6 swap + result\n";
// Open directory iterators keep a handle on the temp folder, and Windows refuses to rename it ("Access is denied").
unset( $upshift_items, $upshift_rulesets, $upshift_item );
$upshift_count = upshift_engine_file_count( $upshift_target );
if ( false === file_put_contents( $upshift_target . '/' . UPSHIFT_BUILD_MARKER, 'inputs ' . upshift_engine_inputs_hash( $upshift_root ) . "\nfiles " . $upshift_count . "\nbuilt " . gmdate( 'c' ) . "\n" ) ) {
	upshift_build_fail( 'Cannot write the ' . UPSHIFT_BUILD_MARKER . ' marker.' );
}
upshift_build_delete( $upshift_old );
if ( is_dir( $upshift_final ) && ! rename( $upshift_final, $upshift_old ) ) {
	upshift_build_fail( 'Cannot move the old vendor-prefixed/ aside (a file in it may be open).' );
}
if ( ! rename( $upshift_target, $upshift_final ) ) {
	if ( is_dir( $upshift_old ) ) {
		rename( $upshift_old, $upshift_final );
	}
	upshift_build_fail( 'Cannot move build/vendor-prefixed.tmp/ to vendor-prefixed/.' );
}
$upshift_target = $upshift_final;
echo 'vendor-prefixed/ replaced; marker ' . UPSHIFT_BUILD_MARKER . " written ({$upshift_count} files). The previous engine stays in build/vendor-prefixed.old/ until the next build.\n";
list( $upshift_bytes, $upshift_files ) = upshift_build_size( $upshift_target );
printf( "vendor-prefixed/: %d files, %.2f MB (%s bytes)%s\n", $upshift_files, $upshift_bytes / 1048576, number_format( $upshift_bytes ), $upshift_bytes < 6 * 1048576 ? '' : '  OVER the 6 MB target' );

// installed.json is stripped from the engine (step 5); the scoped build still has it.
$upshift_installed = json_decode( (string) file_get_contents( $upshift_scoped . '/vendor/composer/installed.json' ), true );
$upshift_packages  = isset( $upshift_installed['packages'] ) ? $upshift_installed['packages'] : (array) $upshift_installed;
foreach ( $upshift_packages as $upshift_package ) {
	printf( "  %-46s %s%s\n", $upshift_package['name'], $upshift_package['version'], is_dir( $upshift_target . '/' . $upshift_package['name'] ) ? '' : '  (build-time only, not shipped)' );
}
