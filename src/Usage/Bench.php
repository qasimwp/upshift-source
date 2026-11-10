<?php
/**
 * Recorder benchmark (spec §7.2, M3 / DECISIONS D14). Used by `wp upshift bench-recorder`
 * and the "Run recorder benchmark" button on the Usage tab.
 *
 * Measures three parts separately, on this request's own included files:
 * (a) hot-path prep (filter + sort + hash), (b) the DB write (lock limit + hit upsert + restore),
 * (c) the new-set cost (exists check + key building + fileset insert).
 * Fake rows use the day 1970-01-01 and fake hashes, and are deleted afterwards.
 *
 * @package Upshift
 */

namespace Upshift\Usage;

use Upshift\Install;
use Upshift\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Recorder benchmark.
 */
class Bench {

	const FAKE_DAY     = '1970-01-01';
	const RESULT       = 'upshift_bench_result';
	const PREP_GATE_MS = 3.0;
	const DB_WARN_MS   = 20.0;
	const DELETE_CHUNK = 100;
	const HASH_PREFIX  = 'upshiftbench';

	/**
	 * Prep phases timed by prep_split(), in order.
	 */
	const SPLIT = array( 'setup', 'sort', 'filter', 'hash', 'context' );

	/**
	 * Stores a result as "the last benchmark run" (Usage tab, M3a information). A non-autoloaded option, not a
	 * transient: with a persistent object cache (Redis, Memcached) transients can be evicted (DECISIONS D49).
	 *
	 * @param array $result Result of run().
	 */
	public static function store( array $result ) {
		update_option( self::RESULT, $result, false );
	}

	/**
	 * The last stored result. Builds before T7.3 kept it in a transient of the same name: moved once if present.
	 *
	 * @return array|false
	 */
	public static function last() {
		$result = get_option( self::RESULT );
		if ( ! is_array( $result ) ) {
			$result = get_transient( self::RESULT );
			if ( ! is_array( $result ) ) {
				return false;
			}
			self::store( $result );
			delete_transient( self::RESULT );
		}
		return $result;
	}

	/**
	 * Runs the benchmark.
	 *
	 * @param int   $iterations Iterations.
	 * @param float $time_limit Stop after this many seconds (0 = no limit).
	 * @return array Result: iterations, requested, stopped_early, files, paths, stats, error, cleanup, time.
	 */
	public static function run( $iterations, $time_limit = 0.0 ) {
		global $wpdb;

		$iterations = max( 1, (int) $iterations );
		$files      = get_included_files();
		$run        = md5( uniqid( 'upshift-bench', true ) );
		$hit_hash   = self::fake_hash( $run . '-hit' );
		$set_hashes = array();
		$prep       = array();
		$db         = array();
		$new_set    = array();
		$split      = array_fill_keys( self::SPLIT, array() );
		$paths      = 0;
		$path_bytes = 0;
		$error      = '';
		$cleanup    = array(
			'hits' => 0,
			'sets' => 0,
		);
		$start      = microtime( true );

		$suppress = $wpdb->suppress_errors( true );
		try {
			self::delete_stale();
			for ( $i = 0; $i < $iterations; $i++ ) {
				if ( $time_limit > 0 && microtime( true ) - $start > $time_limit ) {
					break;
				}

				// Cold caches, like a real request.
				Paths::reset();
				Recorder::reset();

				$t0       = microtime( true );
				$prepared = Recorder::prepare( $files );
				$t1       = microtime( true );
				if ( null === $prepared ) {
					$error = 'No content files are loaded in this request.';
					break;
				}

				// DB write = set lock limit + upsert + restore, as in record() (DECISIONS D18).
				$limited  = Recorder::limit_lock_wait();
				$affected = Recorder::upsert_hit( $hit_hash, self::FAKE_DAY, $prepared['context'], $prepared['prep_ms'] );
				$upsert   = $wpdb->last_error;
				if ( $limited ) {
					Recorder::restore_lock_wait();
				}
				$t2 = microtime( true );
				if ( false === $affected ) {
					$error = 'Hit upsert failed: ' . $upsert;
					break;
				}

				// A fresh hash each time, so every iteration pays the full new-set path.
				$set_hash     = self::fake_hash( $run . '-set-' . $i );
				$set_hashes[] = $set_hash;
				$t3           = microtime( true );
				$stored       = Recorder::store_new_set( $set_hash, $prepared['paths'] );
				$t4           = microtime( true );
				if ( ! $stored ) {
					$error = 'Fileset insert failed: ' . $wpdb->last_error;
					break;
				}

				$paths     = count( $prepared['paths'] );
				$prep[]    = ( $t1 - $t0 ) * 1000;
				$db[]      = ( $t2 - $t1 ) * 1000;
				$new_set[] = ( $t4 - $t3 ) * 1000;

				// Prep again, phase by phase, cold caches again (not part of the gated number).
				Paths::reset();
				Recorder::reset();
				foreach ( self::prep_split( $files ) as $phase => $ms ) {
					$split[ $phase ][] = $ms;
				}
			}
			$path_bytes = strlen( implode( '', Recorder::filter( $files ) ) );
		} finally {
			$cleanup = self::cleanup( $hit_hash, $set_hashes );
			$wpdb->suppress_errors( $suppress );
		}

		return array(
			'iterations'    => count( $prep ),
			'requested'     => $iterations,
			'stopped_early' => count( $prep ) < $iterations && '' === $error,
			'files'         => count( $files ),
			'file_bytes'    => strlen( implode( '', $files ) ),
			'paths'         => $paths,
			'path_bytes'    => $path_bytes,
			'hash_algo'     => Recorder::hash_algo(),
			'stats'         => array(
				'prep'    => self::stats( $prep ),
				'db'      => self::stats( $db ),
				'new_set' => self::stats( $new_set ),
			),
			'split'         => array_map( array( __CLASS__, 'stats' ), $split ),
			'env'           => self::environment(),
			'error'         => $error,
			'cleanup'       => $cleanup,
			'time'          => time(),
		);
	}

	/**
	 * Times the hot-path prep phase by phase, like Recorder::prepare(): setup (prefix caches,
	 * incl. realpath()), sort (normalize + sort all included files), filter (binary-search
	 * blocks), hash, context (DECISIONS D46). Call with cold caches.
	 *
	 * @param string[] $files Included files.
	 * @return array phase => ms.
	 */
	public static function prep_split( array $files ) {
		$t0 = microtime( true );
		Recorder::warm();
		$t1     = microtime( true );
		$sorted = Recorder::sorted( $files );
		$t2     = microtime( true );
		$kept   = Recorder::blocks( $sorted );
		$t3     = microtime( true );
		Recorder::set_hash( $kept );
		$t4 = microtime( true );
		Recorder::context();
		$t5 = microtime( true );

		return array(
			'setup'   => ( $t1 - $t0 ) * 1000,
			'sort'    => ( $t2 - $t1 ) * 1000,
			'filter'  => ( $t3 - $t2 ) * 1000,
			'hash'    => ( $t4 - $t3 ) * 1000,
			'context' => ( $t5 - $t4 ) * 1000,
		);
	}

	/**
	 * PHP and PCRE facts that explain prep differences between hosts (T2.3): PHP version,
	 * pcre.jit, whether JIT is compiled in and actually works (a test match on a fresh pattern;
	 * a host that forbids executable memory warns "Allocation of JIT memory failed" and runs
	 * without JIT), and the filter's prefix counts.
	 *
	 * @return array
	 */
	public static function environment() {
		$support = defined( 'PCRE_JIT_SUPPORT' ) && PCRE_JIT_SUPPORT;
		$warning = '';
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- catches the JIT allocation warning of the one test match below; restored in finally.
		set_error_handler(
			function ( $errno, $errstr ) use ( &$warning ) {
				$warning = (string) $errstr;
				return true;
			}
		);
		try {
			// A unique pattern string, so PHP's compiled-pattern cache can't answer from an earlier compile.
			$matched = preg_match( '#^upshift-jit-' . md5( uniqid( '', true ) ) . '|(?:ab)+c\z#', str_repeat( 'ab', 50 ) . 'c' );
			$error   = preg_last_error();
		} finally {
			restore_error_handler();
		}
		$ini = (string) ini_get( 'pcre.jit' );

		return array(
			'php'          => PHP_VERSION,
			'pcre_version' => defined( 'PCRE_VERSION' ) ? PCRE_VERSION : '',
			'pcre_jit_ini' => $ini,
			'jit_support'  => $support,
			'jit_test'     => array(
				'matched' => 1 === $matched,
				'error'   => $error,
				'warning' => substr( $warning, 0, 200 ),
			),
			'jit_usable'   => $support && in_array( strtolower( $ini ), array( '1', 'on', 'true', 'yes' ), true ) && 1 === $matched && PREG_NO_ERROR === $error && '' === $warning,
			'filter'       => Recorder::filter_shape(),
		);
	}

	/**
	 * One-line summary of environment() (English; WP-CLI and tests).
	 *
	 * @param array $env Output of environment().
	 * @return string
	 */
	public static function describe_environment( array $env ) {
		$shape = array();
		foreach ( $env['filter'] as $name => $value ) {
			$shape[] = $name . ' ' . $value;
		}
		return sprintf(
			'PHP %s, PCRE %s, pcre.jit=%s, JIT compiled in: %s, JIT test: %s (preg_last_error %d%s) => JIT usable: %s. Filter: %s.',
			$env['php'],
			$env['pcre_version'],
			'' === $env['pcre_jit_ini'] ? '(unset)' : $env['pcre_jit_ini'],
			$env['jit_support'] ? 'yes' : 'no',
			$env['jit_test']['matched'] ? 'matched' : 'no match',
			$env['jit_test']['error'],
			'' === $env['jit_test']['warning'] ? '' : ', warning: ' . $env['jit_test']['warning'],
			$env['jit_usable'] ? 'yes' : 'no',
			implode( ', ', $shape )
		);
	}

	/**
	 * A fake set hash: HASH_PREFIX + 20 hex chars (32 total). Real hashes are md5 hex, so they
	 * never start with the prefix.
	 *
	 * @param string $seed Seed.
	 * @return string
	 */
	private static function fake_hash( $seed ) {
		return self::HASH_PREFIX . substr( md5( $seed ), 0, 32 - strlen( self::HASH_PREFIX ) );
	}

	/**
	 * Deletes fake filesets older than 1 hour, left behind when a run was killed before cleanup.
	 * Fake hit rows use the 1970 day and are removed by the retention purge.
	 *
	 * @return int Rows deleted.
	 */
	public static function delete_stale() {
		global $wpdb;

		$sets_table = Install::table( 'filesets' );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$sets_table} WHERE set_hash LIKE %s AND created < %s", $wpdb->esc_like( self::HASH_PREFIX ) . '%', wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
	}

	/**
	 * Deletes the fake rows.
	 *
	 * @param string   $hit_hash   Fake hit hash.
	 * @param string[] $set_hashes Fake set hashes.
	 * @return array array( 'hits' => n, 'sets' => n ).
	 */
	private static function cleanup( $hit_hash, array $set_hashes ) {
		global $wpdb;

		$hits_table = Install::table( 'fileset_hits' );
		$sets_table = Install::table( 'filesets' );

		$hits = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$hits_table} WHERE set_hash = %s AND day = %s", $hit_hash, self::FAKE_DAY ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.

		$sets = 0;
		foreach ( array_chunk( $set_hashes, self::DELETE_CHUNK ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name from $wpdb->prefix; $in is only %s placeholders.
			$sets += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$sets_table} WHERE set_hash IN ({$in})", $chunk ) );
		}

		return array(
			'hits' => $hits,
			'sets' => $sets,
		);
	}

	/**
	 * Median / p95 / max in ms.
	 *
	 * @param float[] $values Samples.
	 * @return array
	 */
	public static function stats( array $values ) {
		return array(
			'median' => self::percentile( $values, 0.5 ),
			'p95'    => self::percentile( $values, 0.95 ),
			'max'    => empty( $values ) ? 0.0 : max( $values ),
		);
	}

	/**
	 * Percentile (nearest rank; the median averages the two middle values).
	 *
	 * @param float[] $values Samples.
	 * @param float   $p      0..1.
	 * @return float
	 */
	private static function percentile( array $values, $p ) {
		$n = count( $values );
		if ( 0 === $n ) {
			return 0.0;
		}
		sort( $values );
		if ( 0.5 === $p && 0 === $n % 2 ) {
			return ( $values[ $n / 2 - 1 ] + $values[ $n / 2 ] ) / 2;
		}
		$index = (int) ceil( $p * $n ) - 1;
		return $values[ max( 0, min( $n - 1, $index ) ) ];
	}
}
