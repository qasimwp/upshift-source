<?php
/**
 * Guard detection: marks findings in code that cannot run on the target PHP, or only runs when the feature exists
 * (DECISIONS D56). Reads the PHPCS token stream of the run that made the findings; scanned code is never executed.
 *
 * Conservative: whatever it cannot read for certain stays counted.
 *
 * @package Upshift
 */

namespace Upshift\Engine;

use Upshift\Scan\Categories;
use Upshift\Vendor\PHP_CodeSniffer\Util\Tokens;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds guarded regions of one file and sets 'guarded' on the findings inside them.
 */
class Guards {

	/**
	 * Rule families that run only when the line runs (PHPCompatibility.*). Everything else is left out on purpose:
	 * syntax, declarations and names take effect when the file is compiled, even in a branch that never runs.
	 */
	const RUNTIME_RULES = array(
		'PHPCompatibility.FunctionUse.',
		'PHPCompatibility.ParameterValues.',
		'PHPCompatibility.Constants.RemovedConstants.',
		'PHPCompatibility.Classes.RemovedClasses.',
		'PHPCompatibility.Extensions.',
		'PHPCompatibility.IniDirectives.',
	);

	/**
	 * Sniff => kind of symbol it flags, for "only runs when the feature exists" guards.
	 */
	const SYMBOL_SNIFFS = array(
		'RemovedFunctions'  => 'function',
		'RemovedExtensions' => 'function',
		'RemovedClasses'    => 'class',
		'RemovedConstants'  => 'constant',
	);

	/**
	 * Check function => kind of symbol it proves.
	 */
	const CHECKS = array(
		'function_exists'  => 'function',
		'is_callable'      => 'function',
		'class_exists'     => 'class',
		'defined'          => 'constant',
		'extension_loaded' => 'extension',
	);

	/**
	 * Extension => prefix of its symbols. Other extensions are not read as guards.
	 */
	const EXTENSIONS = array(
		'imap'   => 'imap_',
		'oci8'   => 'oci',
		'pspell' => 'pspell_',
		'xmlrpc' => 'xmlrpc_',
		'mysql'  => 'mysql_',
		'mcrypt' => 'mcrypt_',
	);

	/**
	 * Tokens of the file.
	 *
	 * @var array
	 */
	private $tokens;

	/**
	 * Lowest and highest version string of the target, e.g. array( '8.4.0', '8.4.99' ).
	 *
	 * @var string[]
	 */
	private $versions;

	/**
	 * Lowest and highest PHP_VERSION_ID of the target.
	 *
	 * @var int[]
	 */
	private $ids;

	/**
	 * Major version of the target.
	 *
	 * @var int
	 */
	private $major;

	/**
	 * Sets 'guarded' (1 or 0) on each finding.
	 *
	 * @param array  $findings Findings with line, column and rule.
	 * @param array  $tokens   PHPCS tokens of the file.
	 * @param string $target   Target version, e.g. "8.4".
	 * @return array
	 */
	public static function mark( array $findings, array $tokens, $target ) {
		foreach ( $findings as $i => $finding ) {
			$findings[ $i ]['guarded'] = 0;
		}
		$parts = explode( '.', (string) $target );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! ctype_digit( $parts[1] ) ) {
			return $findings;
		}
		$wanted = array();
		foreach ( $findings as $finding ) {
			if ( in_array( Categories::of( $finding['rule'] ), Categories::counted(), true ) && 0 === strpos( $finding['rule'], 'PHPCompatibility.' ) ) {
				$wanted[ $finding['line'] . ':' . $finding['column'] ] = null;
			}
		}
		if ( ! $wanted ) {
			return $findings;
		}

		$self           = new self();
		$self->tokens   = $tokens;
		$self->versions = array( $target . '.0', $target . '.99' );
		$self->major    = (int) $parts[0];
		$base           = 10000 * (int) $parts[0] + 100 * (int) $parts[1];
		$self->ids      = array( $base, $base + 99 );

		$regions = $self->regions();
		if ( ! $regions ) {
			return $findings;
		}
		foreach ( $tokens as $ptr => $token ) {
			$key = $token['line'] . ':' . $token['column'];
			if ( array_key_exists( $key, $wanted ) && null === $wanted[ $key ] ) {
				$wanted[ $key ] = $ptr;
			}
		}
		foreach ( $findings as $i => $finding ) {
			$key = $finding['line'] . ':' . $finding['column'];
			if ( isset( $wanted[ $key ] ) && $self->is_guarded( $finding, $wanted[ $key ], $regions ) ) {
				$findings[ $i ]['guarded'] = 1;
			}
		}
		return $findings;
	}

	/**
	 * Whether a finding lies in a region that guards it.
	 *
	 * @param array $finding Finding.
	 * @param int   $ptr     Token the finding is on.
	 * @param array $regions Regions.
	 * @return bool
	 */
	private function is_guarded( array $finding, $ptr, array $regions ) {
		$rule     = $finding['rule'];
		$category = Categories::of( $rule );
		if ( ! in_array( $category, Categories::counted(), true ) ) {
			return false;
		}
		$runtime = false;
		foreach ( self::RUNTIME_RULES as $prefix ) {
			$runtime = $runtime || 0 === strpos( $rule, $prefix );
		}
		foreach ( $regions as $region ) {
			if ( $ptr <= $region['start'] || $ptr >= $region['end'] ) {
				continue;
			}
			if ( $region['dead'] && $runtime ) {
				return true;
			}
			// A symbol check says nothing about a deprecated symbol: it still exists on the target, so the branch runs.
			if ( in_array( $category, array( Categories::REMOVED, Categories::NEEDS_EXTENSION ), true ) && $this->symbol_matches( $rule, $ptr, $region['facts'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the flagged token is a symbol that the region's checks prove to exist.
	 *
	 * @param string $rule  Rule code.
	 * @param int    $ptr   Token the finding is on.
	 * @param array  $facts List of array( kind, name ).
	 * @return bool
	 */
	private function symbol_matches( $rule, $ptr, array $facts ) {
		$parts = explode( '.', $rule );
		$sniff = count( $parts ) >= 2 ? $parts[ count( $parts ) - 2 ] : '';
		if ( T_NEW === $this->tokens[ $ptr ]['code'] ) {
			// RemovedClasses reports "new X" on the keyword.
			$ptr = $this->next_code( $ptr + 1 );
		}
		if ( ! isset( self::SYMBOL_SNIFFS[ $sniff ] ) || null === $ptr || ! $this->is_name( $ptr ) ) {
			return false;
		}
		$kind  = self::SYMBOL_SNIFFS[ $sniff ];
		$name  = ltrim( $this->tokens[ $ptr ]['content'], '\\' );
		$lower = strtolower( $name );
		foreach ( $facts as $fact ) {
			if ( 'extension' === $fact[0] ) {
				if ( 'class' !== $kind && 0 === strpos( $lower, self::EXTENSIONS[ $fact[1] ] ) ) {
					return true;
				}
			} elseif ( $fact[0] === $kind && ( 'constant' === $kind ? $fact[1] === $name : strtolower( $fact[1] ) === $lower ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a token is a plain or fully qualified name.
	 *
	 * @param int $ptr Token.
	 * @return bool
	 */
	private function is_name( $ptr ) {
		return in_array( $this->tokens[ $ptr ]['code'], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true );
	}

	/**
	 * All guarded regions of the file.
	 *
	 * @return array List of array( start, end, dead, facts ); start and end are exclusive token positions.
	 */
	private function regions() {
		$regions = array();
		foreach ( $this->tokens as $ptr => $token ) {
			if ( T_IF === $token['code'] && isset( $token['parenthesis_opener'], $token['parenthesis_closer'] ) ) {
				$regions = array_merge( $regions, $this->chain_regions( $ptr ) );
			}
		}
		return $regions;
	}

	/**
	 * Regions of one if / elseif / else chain, plus the early-return region of a standalone if.
	 *
	 * @param int $if T_IF token.
	 * @return array
	 */
	private function chain_regions( $if ) {
		$regions     = array();
		$prior_dead  = false;
		$prior_facts = array();
		$current     = $if;
		$first_cond  = null;
		while ( true ) {
			$token = $this->tokens[ $current ];
			$cond  = isset( $token['parenthesis_opener'], $token['parenthesis_closer'] ) && T_ELSE !== $token['code']
				? $this->condition( $token['parenthesis_opener'], $token['parenthesis_closer'] )
				: array(
					'value'    => null,
					'if_true'  => array(),
					'if_false' => array(),
				);
			if ( null === $first_cond ) {
				$first_cond = $cond;
			}
			$body = $this->body( $current );
			if ( null === $body ) {
				break;
			}
			$dead  = $prior_dead || false === $cond['value'];
			$facts = array_merge( $prior_facts, $cond['if_true'] );
			if ( $dead || $facts ) {
				$regions[] = array(
					'start' => $body['start'],
					'end'   => $body['end'],
					'dead'  => $dead,
					'facts' => $facts,
				);
			}
			$prior_dead  = $prior_dead || true === $cond['value'];
			$prior_facts = array_merge( $prior_facts, $cond['if_false'] );

			$next = $this->next_code( $body['end'] + 1 );
			if ( $current === $if && ( null === $next || ! in_array( $this->tokens[ $next ]['code'], array( T_ELSE, T_ELSEIF ), true ) ) ) {
				$early = $this->early_return_region( $if, $body, $first_cond );
				if ( null !== $early ) {
					$regions[] = $early;
				}
				break;
			}
			// An "else if" has no scope of its own here; alternative syntax and brace-less bodies end the chain.
			if ( ! $body['braces'] || null === $next || ! in_array( $this->tokens[ $next ]['code'], array( T_ELSE, T_ELSEIF ), true ) || ! isset( $this->tokens[ $next ]['scope_opener'] ) ) {
				break;
			}
			$current = $next;
		}
		return $regions;
	}

	/**
	 * Region after a standalone if whose body always leaves the function (the if sits directly in a function body).
	 *
	 * @param int   $if   T_IF token.
	 * @param array $body Body from body().
	 * @param array $cond Condition from condition().
	 * @return array|null
	 */
	private function early_return_region( $if, array $body, array $cond ) {
		if ( true !== $cond['value'] && ! $cond['if_false'] ) {
			return null;
		}
		$conditions = isset( $this->tokens[ $if ]['conditions'] ) ? $this->tokens[ $if ]['conditions'] : array();
		if ( ! $conditions ) {
			return null;
		}
		$owner = (int) array_keys( $conditions )[ count( $conditions ) - 1 ];
		if ( ! in_array( $this->tokens[ $owner ]['code'], array( T_FUNCTION, T_CLOSURE ), true ) || ! isset( $this->tokens[ $owner ]['scope_closer'] ) || ! $this->always_leaves( $body ) ) {
			return null;
		}
		return array(
			'start' => $body['end'],
			'end'   => $this->tokens[ $owner ]['scope_closer'],
			'dead'  => true === $cond['value'],
			'facts' => $cond['if_false'],
		);
	}

	/**
	 * Whether the last statement of a body is return, exit, die or throw at the top level of that body.
	 *
	 * @param array $body Body from body().
	 * @return bool
	 */
	private function always_leaves( array $body ) {
		if ( ! $body['braces'] ) {
			// One statement (or alternative syntax, where a first statement that leaves is enough).
			$first = $this->next_code( $body['start'] + 1 );
			return null !== $first && $first < $body['end'] && in_array( $this->tokens[ $first ]['code'], array( T_RETURN, T_EXIT, T_THROW ), true );
		}
		$last  = null;
		$start = null;
		for ( $i = $body['start'] + 1; $i < $body['end']; $i++ ) {
			$token = $this->tokens[ $i ];
			if ( isset( Tokens::EMPTY_TOKENS[ $token['code'] ] ) ) {
				continue;
			}
			if ( null === $start ) {
				$start = $i;
			}
			if ( isset( $token['scope_opener'], $token['scope_closer'] ) && $token['scope_opener'] > $i && T_CLOSURE !== $token['code'] && T_FN !== $token['code'] ) {
				// A block as the last statement doesn't prove anything.
				$i     = $token['scope_closer'];
				$start = null;
				$last  = null;
				continue;
			}
			if ( isset( $token['parenthesis_closer'] ) && T_OPEN_PARENTHESIS === $token['code'] ) {
				$i = $token['parenthesis_closer'];
				continue;
			}
			if ( isset( $token['bracket_closer'] ) && $token['bracket_closer'] > $i ) {
				$i = $token['bracket_closer'];
				continue;
			}
			if ( isset( $token['scope_closer'] ) && $token['scope_closer'] > $i ) {
				$i = $token['scope_closer'];
				continue;
			}
			if ( T_SEMICOLON === $token['code'] ) {
				$last  = $start;
				$start = null;
			}
		}
		return null === $start && null !== $last && in_array( $this->tokens[ $last ]['code'], array( T_RETURN, T_EXIT, T_THROW ), true );
	}

	/**
	 * Body of an if, elseif or else.
	 *
	 * @param int $ptr Control token.
	 * @return array|null array( start, end, braces ); start and end are exclusive. For a brace-less body end is the
	 *                    semicolon. Null when it can't be read.
	 */
	private function body( $ptr ) {
		$token = $this->tokens[ $ptr ];
		if ( isset( $token['scope_opener'], $token['scope_closer'] ) ) {
			return array(
				'start'  => $token['scope_opener'],
				'end'    => $token['scope_closer'],
				'braces' => T_OPEN_CURLY_BRACKET === $this->tokens[ $token['scope_opener'] ]['code'],
			);
		}
		if ( ! isset( $token['parenthesis_closer'] ) ) {
			return null;
		}
		$count = count( $this->tokens );
		for ( $i = $token['parenthesis_closer'] + 1; $i < $count; $i++ ) {
			$current = $this->tokens[ $i ];
			if ( T_SEMICOLON === $current['code'] ) {
				return array(
					'start'  => $token['parenthesis_closer'],
					'end'    => $i,
					'braces' => false,
				);
			}
			if ( T_OPEN_PARENTHESIS === $current['code'] && isset( $current['parenthesis_closer'] ) ) {
				$i = $current['parenthesis_closer'];
				continue;
			}
			if ( isset( $current['bracket_closer'] ) && $current['bracket_closer'] > $i ) {
				$i = $current['bracket_closer'];
				continue;
			}
			if ( isset( $current['scope_opener'] ) || in_array( $current['code'], array( T_OPEN_CURLY_BRACKET, T_CLOSE_CURLY_BRACKET, T_CLOSE_TAG, T_COLON ), true ) ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Next token that isn't whitespace or a comment.
	 *
	 * @param int $from First token to look at.
	 * @return int|null
	 */
	private function next_code( $from ) {
		$count = count( $this->tokens );
		for ( $i = $from; $i < $count; $i++ ) {
			if ( ! isset( Tokens::EMPTY_TOKENS[ $this->tokens[ $i ]['code'] ] ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Reads a condition.
	 *
	 * @param int $open  Opening parenthesis.
	 * @param int $close Closing parenthesis.
	 * @return array value (true/false on the target, null = unknown), if_true and if_false (facts proven in each case).
	 */
	private function condition( $open, $close ) {
		$unknown = array(
			'value'    => null,
			'if_true'  => array(),
			'if_false' => array(),
		);
		$parts   = $this->split( $open + 1, $close - 1 );
		if ( null === $parts ) {
			return $unknown;
		}
		$atoms = array();
		foreach ( $parts['ranges'] as $range ) {
			$atoms[] = $this->atom( $range[0], $range[1] );
		}

		$true  = array();
		$false = array();
		$any_t = false;
		$any_f = false;
		$all_t = true;
		$all_f = true;
		foreach ( $atoms as $atom ) {
			$value = null === $atom || ! array_key_exists( 'value', $atom ) ? null : $atom['value'];
			$any_t = $any_t || true === $value;
			$any_f = $any_f || false === $value;
			$all_t = $all_t && true === $value;
			$all_f = $all_f && false === $value;
			if ( null !== $atom && isset( $atom['fact'] ) ) {
				if ( $atom['negated'] ) {
					$false[] = $atom['fact'];
				} else {
					$true[] = $atom['fact'];
				}
			}
		}
		if ( 'or' === $parts['op'] ) {
			return array(
				'value'    => $any_t ? true : ( $all_f ? false : null ),
				'if_true'  => array(),
				'if_false' => $false,
			);
		}
		if ( 'and' === $parts['op'] ) {
			return array(
				'value'    => $any_f ? false : ( $all_t ? true : null ),
				'if_true'  => $true,
				'if_false' => array(),
			);
		}
		return array(
			'value'    => $all_t ? true : ( $all_f ? false : null ),
			'if_true'  => $true,
			'if_false' => $false,
		);
	}

	/**
	 * Splits a condition on top-level && or ||.
	 *
	 * @param int $start First token.
	 * @param int $end   Last token.
	 * @return array|null op (single, and, or), ranges. Null for a mix or anything that can't be read for certain.
	 */
	private function split( $start, $end ) {
		list( $start, $end ) = $this->unwrap( $start, $end );
		$op                  = 'single';
		$ranges              = array();
		$from                = $start;
		for ( $i = $start; $i <= $end; $i++ ) {
			$token = $this->tokens[ $i ];
			if ( T_OPEN_PARENTHESIS === $token['code'] && isset( $token['parenthesis_closer'] ) ) {
				$i = $token['parenthesis_closer'];
				continue;
			}
			if ( isset( $token['bracket_closer'] ) && $token['bracket_closer'] > $i ) {
				$i = $token['bracket_closer'];
				continue;
			}
			if ( in_array( $token['code'], array( T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR, T_INLINE_THEN, T_INLINE_ELSE, T_COALESCE ), true ) || isset( Tokens::ASSIGNMENT_TOKENS[ $token['code'] ] ) ) {
				return null;
			}
			if ( T_BOOLEAN_AND === $token['code'] || T_BOOLEAN_OR === $token['code'] ) {
				$this_op = T_BOOLEAN_AND === $token['code'] ? 'and' : 'or';
				if ( 'single' !== $op && $op !== $this_op ) {
					return null;
				}
				$op       = $this_op;
				$ranges[] = array( $from, $i - 1 );
				$from     = $i + 1;
			}
		}
		$ranges[] = array( $from, $end );
		return array(
			'op'     => $op,
			'ranges' => $ranges,
		);
	}

	/**
	 * Drops whitespace, comments and parentheses around a whole range.
	 *
	 * @param int $start First token.
	 * @param int $end   Last token.
	 * @return int[]
	 */
	private function unwrap( $start, $end ) {
		while ( $start < $end ) {
			if ( isset( Tokens::EMPTY_TOKENS[ $this->tokens[ $start ]['code'] ] ) ) {
				++$start;
			} elseif ( isset( Tokens::EMPTY_TOKENS[ $this->tokens[ $end ]['code'] ] ) ) {
				--$end;
			} elseif ( T_OPEN_PARENTHESIS === $this->tokens[ $start ]['code'] && isset( $this->tokens[ $start ]['parenthesis_closer'] ) && $this->tokens[ $start ]['parenthesis_closer'] === $end && ! isset( $this->tokens[ $start ]['parenthesis_owner'] ) ) {
				++$start;
				--$end;
			} else {
				break;
			}
		}
		return array( $start, $end );
	}

	/**
	 * Reads one part of a condition.
	 *
	 * @param int $start First token.
	 * @param int $end   Last token.
	 * @return array|null array( fact, negated ) for a symbol check, array( value ) for a version check, null otherwise.
	 */
	private function atom( $start, $end ) {
		list( $start, $end ) = $this->unwrap( $start, $end );
		$negated             = false;
		while ( $start < $end && T_BOOLEAN_NOT === $this->tokens[ $start ]['code'] ) {
			$negated             = ! $negated;
			list( $start, $end ) = $this->unwrap( $start + 1, $end );
		}
		$code = array();
		for ( $i = $start; $i <= $end; $i++ ) {
			if ( ! isset( Tokens::EMPTY_TOKENS[ $this->tokens[ $i ]['code'] ] ) ) {
				$code[] = $i;
			}
		}
		if ( ! $code ) {
			return null;
		}
		$fact = $this->check_fact( $code );
		if ( null !== $fact ) {
			return array(
				'fact'    => $fact,
				'negated' => $negated,
			);
		}
		$value = $this->version_value( $code );
		if ( null === $value ) {
			return null;
		}
		return array( 'value' => $negated ? ! $value : $value );
	}

	/**
	 * Name of a plain or \qualified global name token, lowercase; '' for anything else.
	 *
	 * @param int $ptr Token.
	 * @return string
	 */
	private function name_of( $ptr ) {
		return $this->is_name( $ptr ) ? strtolower( ltrim( $this->tokens[ $ptr ]['content'], '\\' ) ) : '';
	}

	/**
	 * Reads function_exists( 'x' ) and the like.
	 *
	 * @param int[] $code Tokens of the part.
	 * @return array|null array( kind, name ).
	 */
	private function check_fact( array $code ) {
		$name = $this->name_of( $code[0] );
		if ( ! isset( self::CHECKS[ $name ] ) || count( $code ) < 4 || T_OPEN_PARENTHESIS !== $this->tokens[ $code[1] ]['code'] ) {
			return null;
		}
		$closer = isset( $this->tokens[ $code[1] ]['parenthesis_closer'] ) ? $this->tokens[ $code[1] ]['parenthesis_closer'] : -1;
		if ( end( $code ) !== $closer || T_CONSTANT_ENCAPSED_STRING !== $this->tokens[ $code[2] ]['code'] ) {
			return null;
		}
		// Only class_exists( 'x', false ) may have more after the string.
		if ( T_CLOSE_PARENTHESIS !== $this->tokens[ $code[3] ]['code'] && ! ( 'class_exists' === $name && T_COMMA === $this->tokens[ $code[3] ]['code'] ) ) {
			return null;
		}
		$symbol = ltrim( $this->literal( $code[2] ), '\\' );
		$kind   = self::CHECKS[ $name ];
		if ( '' === $symbol ) {
			return null;
		}
		if ( 'extension' === $kind ) {
			$symbol = strtolower( $symbol );
			if ( ! isset( self::EXTENSIONS[ $symbol ] ) ) {
				return null;
			}
		}
		return array( $kind, $symbol );
	}

	/**
	 * Text of a string literal token.
	 *
	 * @param int $ptr Token.
	 * @return string
	 */
	private function literal( $ptr ) {
		return substr( $this->tokens[ $ptr ]['content'], 1, -1 );
	}

	/**
	 * Evaluates a PHP version check for the target.
	 *
	 * @param int[] $code Tokens of the part.
	 * @return bool|null Null when it isn't a version check or its result depends on the patch version.
	 */
	private function version_value( array $code ) {
		if ( 'version_compare' === $this->name_of( $code[0] ) ) {
			return $this->version_compare_value( $code );
		}
		if ( 3 !== count( $code ) ) {
			return null;
		}
		$left  = $this->name_of( $code[0] );
		$right = $this->name_of( $code[2] );
		$op    = $this->tokens[ $code[1] ]['content'];
		$names = array( 'php_version_id', 'php_major_version' );
		if ( T_LNUMBER === $this->tokens[ $code[2] ]['code'] && in_array( $left, $names, true ) ) {
			list( $low, $high ) = $this->range_of( $left );
			$number             = $this->number( $code[2] );
			return null === $number ? null : $this->same( self::compare_ints( $low, $op, $number ), self::compare_ints( $high, $op, $number ) );
		}
		if ( T_LNUMBER === $this->tokens[ $code[0] ]['code'] && in_array( $right, $names, true ) ) {
			list( $low, $high ) = $this->range_of( $right );
			$number             = $this->number( $code[0] );
			return null === $number ? null : $this->same( self::compare_ints( $number, $op, $low ), self::compare_ints( $number, $op, $high ) );
		}
		return null;
	}

	/**
	 * Lowest and highest value of a version constant on the target.
	 *
	 * @param string $constant Lowercase constant name.
	 * @return int[]
	 */
	private function range_of( $constant ) {
		return 'php_version_id' === $constant ? $this->ids : array( $this->major, $this->major );
	}

	/**
	 * Value of a decimal integer token.
	 *
	 * @param int $ptr Token.
	 * @return int|null
	 */
	private function number( $ptr ) {
		$content = $this->tokens[ $ptr ]['content'];
		return ctype_digit( $content ) && ( '0' === $content || '0' !== $content[0] ) ? (int) $content : null;
	}

	/**
	 * The common result of the lowest and highest target version.
	 *
	 * @param bool|null $low  Result for the lowest.
	 * @param bool|null $high Result for the highest.
	 * @return bool|null
	 */
	private function same( $low, $high ) {
		return null !== $low && $low === $high ? $low : null;
	}

	/**
	 * Compares two integers with a comparison operator from the source.
	 *
	 * @param int    $a  Left.
	 * @param string $op Operator.
	 * @param int    $b  Right.
	 * @return bool|null Null for an operator that isn't a comparison.
	 */
	private static function compare_ints( $a, $op, $b ) {
		switch ( $op ) {
			case '<':
				return $a < $b;
			case '<=':
				return $a <= $b;
			case '>':
				return $a > $b;
			case '>=':
				return $a >= $b;
			case '==':
			case '===':
				return $a === $b;
			case '!=':
			case '!==':
			case '<>':
				return $a !== $b;
			default:
				return null;
		}
	}

	/**
	 * Evaluates version_compare( PHP_VERSION, 'x', 'op' ) and version_compare( PHP_VERSION, 'x' ) op int.
	 *
	 * @param int[] $code Tokens of the part.
	 * @return bool|null
	 */
	private function version_compare_value( array $code ) {
		if ( ! isset( $code[1] ) || T_OPEN_PARENTHESIS !== $this->tokens[ $code[1] ]['code'] || ! isset( $this->tokens[ $code[1] ]['parenthesis_closer'] ) ) {
			return null;
		}
		$closer = $this->tokens[ $code[1] ]['parenthesis_closer'];
		$pos    = array_search( $closer, $code, true );
		if ( false === $pos ) {
			return null;
		}
		$args = array( array() );
		foreach ( array_slice( $code, 2, $pos - 2 ) as $ptr ) {
			if ( T_COMMA === $this->tokens[ $ptr ]['code'] ) {
				$args[] = array();
			} else {
				$args[ count( $args ) - 1 ][] = $ptr;
			}
		}
		if ( count( $args ) < 2 || count( $args ) > 3 ) {
			return null;
		}
		$a_php = $this->is_php_version( $args[0] );
		$b_php = $this->is_php_version( $args[1] );
		if ( $a_php === $b_php || 1 !== count( $args[ $a_php ? 1 : 0 ] ) || T_CONSTANT_ENCAPSED_STRING !== $this->tokens[ $args[ $a_php ? 1 : 0 ][0] ]['code'] ) {
			return null;
		}
		$literal = $this->literal( $args[ $a_php ? 1 : 0 ][0] );
		if ( ! preg_match( '/^\d+(\.\d+){0,2}$/', $literal ) ) {
			return null;
		}
		$rest = array_slice( $code, $pos + 1 );

		$results = array();
		foreach ( $this->versions as $version ) {
			$first  = $a_php ? $version : $literal;
			$second = $a_php ? $literal : $version;
			if ( 3 === count( $args ) ) {
				if ( $rest || 1 !== count( $args[2] ) || T_CONSTANT_ENCAPSED_STRING !== $this->tokens[ $args[2][0] ]['code'] ) {
					return null;
				}
				$op = $this->literal( $args[2][0] );
				if ( ! in_array( $op, array( '<', 'lt', '<=', 'le', '>', 'gt', '>=', 'ge', '==', 'eq', '!=', '<>', 'ne' ), true ) ) {
					return null;
				}
				$results[] = version_compare( $first, $second, $op );
			} else {
				if ( 2 !== count( $rest ) ) {
					return null;
				}
				$number = $this->signed_rest( $rest );
				if ( null === $number ) {
					return null;
				}
				$results[] = self::compare_ints( version_compare( $first, $second ), $this->tokens[ $rest[0] ]['content'], $number );
			}
		}
		return $this->same( $results[0], $results[1] );
	}

	/**
	 * The integer after the comparison operator in "version_compare( a, b ) op int".
	 *
	 * @param int[] $rest Tokens after the call.
	 * @return int|null
	 */
	private function signed_rest( array $rest ) {
		return T_LNUMBER === $this->tokens[ $rest[1] ]['code'] ? $this->number( $rest[1] ) : null;
	}

	/**
	 * Whether an argument is PHP_VERSION or phpversion().
	 *
	 * @param int[] $arg Tokens of the argument.
	 * @return bool
	 */
	private function is_php_version( array $arg ) {
		if ( 1 === count( $arg ) ) {
			return 'php_version' === $this->name_of( $arg[0] );
		}
		return 3 === count( $arg ) && 'phpversion' === $this->name_of( $arg[0] ) && T_OPEN_PARENTHESIS === $this->tokens[ $arg[1] ]['code'] && T_CLOSE_PARENTHESIS === $this->tokens[ $arg[2] ]['code'];
	}
}
