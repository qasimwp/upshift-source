<?php
/**
 * Builds dist/upshift.zip for live sites (dev script, not shipped). Usage: php bin/build-zip.php [--no-engine]
 *
 * Includes (spec §8 T7): vendor-prefixed, standards, src, assets, languages, upshift.php,
 * uninstall.php, readme.txt, licenses. Optional folders that don't exist yet are skipped.
 * Everything else (tests, docs, engine, bin, build, tools, dist, dotfiles, composer.*,
 * scoper.inc.php, CLAUDE.md) is left out because it is not on the include list.
 * Refuses without a complete engine build (vendor-prefixed/BUILD-OK matching the current build inputs and file
 * count), unless --no-engine is passed, which builds a zip without the engine (DECISIONS D47).
 *
 * @package Upshift
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions -- CLI dev script, no WordPress loaded.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/engine-inputs.php';

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The zip extension is required.\n" );
	exit( 1 );
}

$upshift_root      = dirname( __DIR__ );
$upshift_no_engine = in_array( '--no-engine', array_slice( (array) $argv, 1 ), true );
$upshift_dirs      = array( 'src', 'assets', 'languages', 'standards' );
$upshift_files     = array_merge(
	array( 'upshift.php', 'uninstall.php', 'readme.txt' ),
	array_map( 'basename', array_merge( (array) glob( $upshift_root . '/LICENSE*' ), (array) glob( $upshift_root . '/COPYING*' ) ) )
);

// The engine must be a complete, checked build of the current inputs (bin/build-engine.php writes the marker last).
$upshift_engine = $upshift_root . '/vendor-prefixed';
if ( $upshift_no_engine ) {
	echo "--no-engine: building a zip without vendor-prefixed/ (scans will report the engine as missing).\n";
} else {
	$upshift_marker = $upshift_engine . '/' . UPSHIFT_BUILD_MARKER;
	if ( ! is_dir( $upshift_engine ) ) {
		fwrite( STDERR, "Refusing to build: vendor-prefixed/ is missing. Run php bin/build-engine.php (or pass --no-engine).\n" );
		exit( 1 );
	}
	if ( ! is_file( $upshift_marker ) ) {
		fwrite( STDERR, 'Refusing to build: vendor-prefixed/' . UPSHIFT_BUILD_MARKER . " is missing, so the engine build did not finish its checks. Run php bin/build-engine.php.\n" );
		exit( 1 );
	}
	$upshift_lines = array();
	foreach ( (array) file( $upshift_marker, FILE_IGNORE_NEW_LINES ) as $upshift_line ) {
		$upshift_parts = explode( ' ', (string) $upshift_line, 2 );
		if ( 2 === count( $upshift_parts ) ) {
			$upshift_lines[ $upshift_parts[0] ] = $upshift_parts[1];
		}
	}
	$upshift_inputs = upshift_engine_inputs_hash( $upshift_root );
	if ( ! isset( $upshift_lines['inputs'] ) || $upshift_lines['inputs'] !== $upshift_inputs ) {
		fwrite( STDERR, "Refusing to build: vendor-prefixed/ was built from other inputs (engine/composer.*, scoper.inc.php or bin/build-engine.php changed since). Run php bin/build-engine.php.\n" );
		exit( 1 );
	}
	$upshift_count = upshift_engine_file_count( $upshift_engine );
	if ( ! isset( $upshift_lines['files'] ) || (int) $upshift_lines['files'] !== $upshift_count ) {
		fwrite( STDERR, 'Refusing to build: vendor-prefixed/ has ' . $upshift_count . ' files, the build wrote ' . ( isset( $upshift_lines['files'] ) ? (int) $upshift_lines['files'] : 0 ) . ". Run php bin/build-engine.php.\n" );
		exit( 1 );
	}
	$upshift_dirs[] = 'vendor-prefixed';
	echo 'Engine: ' . UPSHIFT_BUILD_MARKER . ' OK (' . $upshift_count . ' files, built ' . ( isset( $upshift_lines['built'] ) ? $upshift_lines['built'] : '?' ) . ").\n";
}

$upshift_dist = $upshift_root . '/dist';
if ( ! is_dir( $upshift_dist ) && ! mkdir( $upshift_dist, 0755, true ) ) {
	fwrite( STDERR, "Cannot create dist/.\n" );
	exit( 1 );
}
$upshift_zip_path = $upshift_dist . '/upshift.zip';
if ( file_exists( $upshift_zip_path ) && ! unlink( $upshift_zip_path ) ) {
	fwrite( STDERR, "Cannot delete the old {$upshift_zip_path}.\n" );
	exit( 1 );
}

$upshift_zip = new ZipArchive();
if ( true !== $upshift_zip->open( $upshift_zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot open {$upshift_zip_path}.\n" );
	exit( 1 );
}

$upshift_count   = 0;
$upshift_skipped = array();
// Developer-only code (DECISIONS D64): the Developer tab's screens (without them Page::is_dev() is false even with
// UPSHIFT_DEV) and the loopback `simulate-traffic` command, the plugin's only HTTP request.
$upshift_dev_only = array( 'src/Admin/Report.php', 'src/Cli/DevCommand.php' );
foreach ( $upshift_dev_only as $upshift_file ) {
	if ( ! is_file( $upshift_root . '/' . $upshift_file ) ) {
		fwrite( STDERR, "Refusing to build: the dev-only file {$upshift_file} to leave out is missing (renamed?).\n" );
		exit( 1 );
	}
}

foreach ( $upshift_files as $upshift_file ) {
	if ( ! is_file( $upshift_root . '/' . $upshift_file ) ) {
		$upshift_skipped[] = $upshift_file;
		continue;
	}
	$upshift_zip->addFile( $upshift_root . '/' . $upshift_file, 'upshift/' . $upshift_file );
	++$upshift_count;
}

foreach ( $upshift_dirs as $upshift_dir ) {
	$upshift_base = $upshift_root . '/' . $upshift_dir;
	if ( ! is_dir( $upshift_base ) ) {
		$upshift_skipped[] = $upshift_dir . '/';
		continue;
	}
	$upshift_iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $upshift_base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $upshift_iterator as $upshift_item ) {
		if ( ! $upshift_item->isFile() ) {
			continue;
		}
		$upshift_relative = $upshift_dir . '/' . str_replace( '\\', '/', substr( $upshift_item->getPathname(), strlen( $upshift_base ) + 1 ) );
		// Skip dotfiles and anything inside dot-directories.
		if ( preg_match( '#(^|/)\.#', $upshift_relative ) || in_array( $upshift_relative, $upshift_dev_only, true ) ) {
			continue;
		}
		$upshift_zip->addFile( $upshift_item->getPathname(), 'upshift/' . $upshift_relative );
		++$upshift_count;
	}
}

// Build id for the admin page footer (DECISIONS D32): git short hash, "-dirty" with uncommitted changes.
// Written into the zip only, never into the repo. Dev script, so exec is allowed here.
$upshift_build = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $upshift_root ) . ' rev-parse --short HEAD' ) );
if ( ! preg_match( '/^[0-9a-f]{7,12}$/', $upshift_build ) ) {
	fwrite( STDERR, "Cannot read the git hash for build.txt.\n" );
	exit( 1 );
}
if ( '' !== trim( (string) shell_exec( 'git -C ' . escapeshellarg( $upshift_root ) . ' status --porcelain' ) ) ) {
	$upshift_build .= '-dirty';
}
$upshift_zip->addFromString( 'upshift/build.txt', $upshift_build . "\n" );
++$upshift_count;

if ( ! $upshift_zip->close() ) {
	fwrite( STDERR, "Failed to write {$upshift_zip_path}.\n" );
	exit( 1 );
}

echo 'Built ' . $upshift_zip_path . ': ' . $upshift_count . ' files, ' . number_format( filesize( $upshift_zip_path ) / 1024, 1 ) . ' KB, build ' . $upshift_build . "\n";
if ( $upshift_skipped ) {
	echo 'Not present (skipped): ' . implode( ', ', $upshift_skipped ) . "\n";
}
