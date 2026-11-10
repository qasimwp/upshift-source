<?php
/**
 * Everything the Check tab shows, in plain words (docs/UI-v1.md §1, §3; DECISIONS D58).
 *
 * @package Upshift
 */

namespace Upshift\Report;

use Upshift\Install;
use Upshift\Scan\Categories;
use Upshift\Scan\Runner;
use Upshift\Settings;
use Upshift\Usage\Recorder;
use Upshift\Usage\Rollup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Queries our own custom tables (spec §6); the object cache does not apply to them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Builds the report data for one finished scan. Never loads the engine.
 */
class Check {

	const MAX_STEPS       = 5;
	const MAX_GROUPS      = 150;
	const MAX_NOT_CHECKED = 20;
	const MAX_COPY_ITEMS  = 29; // With the header, section headings and closing lines: about 40 lines of text.
	const MAX_EXAMPLES    = 3;

	/**
	 * Words a normal screen may not show (docs/UI-v1.md §1).
	 */
	const BANNED = '/\b(Activity watch|Upgrade watch|watched|share of|M[1-5]|filesets?|buckets?|sampled|rollups?|prep|contexts?|components?|findings?|severity|advisory|newer_than_current|baseline|guarded|needs_extension|PHPCS|sniffs?|testVersion)\b/i';

	/**
	 * Builds the report.
	 *
	 * @param array $scan    Scan row.
	 * @param array $summary Status::for_scan().
	 * @return array
	 */
	public static function build( array $scan, array $summary ) {
		$meta      = Rollup::meta();
		$days      = false !== $meta ? (int) $meta['days_observed'] : 0;
		$scan_id   = (int) $scan['id'];
		$target    = (string) $scan['target_version'];
		$from      = (string) $scan['from_version'];
		$installed = self::installed();
		$contexts  = self::component_contexts();

		$items = array();
		foreach ( $summary['components'] as $id => $component ) {
			$items[ $id ] = self::new_item( (string) $id, $component, $installed );
		}

		$notes = array();
		foreach ( Join::findings( $scan_id ) as $row ) {
			if ( ! isset( $items[ $row['component'] ] ) ) {
				continue;
			}
			if ( self::is_note( $row ) ) {
				if ( $row['active'] ) {
					$notes[ self::note_key( $row ) ] = true;
				}
				continue;
			}
			self::add_problem( $items[ $row['component'] ], $row );
		}

		self::explain( $items, $days );
		foreach ( $items as $id => $item ) {
			$items[ $id ] = self::finish_item( $item, $contexts, $days, $target, $from );
		}

		$active   = array();
		$inactive = array();
		foreach ( $items as $item ) {
			if ( $item['active'] ) {
				$active[] = $item;
			} else {
				$inactive[] = $item;
			}
		}
		usort( $active, array( __CLASS__, 'compare_items' ) );
		usort( $inactive, array( __CLASS__, 'compare_items' ) );

		// Do this first: only what can stop the site. Works-fix-later items wait in their own list.
		$steps       = array();
		$later_steps = array();
		foreach ( $active as $item ) {
			if ( Status::LATER === $item['status'] ) {
				if ( count( $later_steps ) < self::MAX_STEPS ) {
					$later_steps[] = $item['advice'];
				}
			} elseif ( Status::READY !== $item['status'] && count( $steps ) < self::MAX_STEPS ) {
				$steps[] = $item['advice'];
			}
		}

		return array(
			'scan'        => $scan,
			'from'        => $from,
			'target'      => $target,
			'answer'      => Status::answer( $summary ),
			'counts'      => Status::counts( $summary ),
			'total'       => count( $items ),
			'items'       => $active,
			'inactive'    => $inactive,
			'steps'       => $steps,
			'later_steps' => $later_steps,
			'notes'       => count( $notes ),
			'not_checked' => self::not_checked( $scan_id, $items ),
			'days'        => $days,
			'watch_on'    => ! empty( Settings::get( 'usage_enabled' ) ),
			'checked'     => (float) $scan['baseline_seconds'] >= 0,
			'changed'     => Status::site_changed( $summary ),
		);
	}

	/**
	 * Installed plugins and themes: names, versions, update information from the local transients only (no remote call).
	 *
	 * @return array plugins (component id => array( name, version, file, update )), themes (same).
	 */
	private static function installed() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out     = array(
			'plugins' => array(),
			'themes'  => array(),
		);
		$updates = get_site_transient( 'update_plugins' );
		foreach ( get_plugins() as $file => $data ) {
			$dir = dirname( $file );
			$id  = 'plugin:' . ( '.' === $dir ? $file : $dir );
			// One folder can hold several plugin files: the first wins, unless a later one is active.
			if ( isset( $out['plugins'][ $id ] ) && ! is_plugin_active( $file ) ) {
				continue;
			}
			$out['plugins'][ $id ] = array(
				'name'    => isset( $data['Name'] ) && '' !== $data['Name'] ? (string) $data['Name'] : $file,
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'update'  => is_object( $updates ) && isset( $updates->response[ $file ] ),
			);
		}
		$updates = get_site_transient( 'update_themes' );
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$out['themes'][ 'theme:' . $stylesheet ] = array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'update'  => is_object( $updates ) && isset( $updates->response[ $stylesheet ] ),
			);
		}
		return $out;
	}

	/**
	 * Where each plugin or theme has been seen, for the ones with no problem rows of their own (Ready).
	 *
	 * @return array component id => context mask.
	 */
	private static function component_contexts() {
		global $wpdb;

		$table = Install::table( 'usage' );
		$out   = array();
		foreach ( (array) $wpdb->get_results( "SELECT component, BIT_OR(contexts) AS contexts FROM {$table} GROUP BY component", ARRAY_A ) as $row ) {
			$out[ $row['component'] ] = (int) $row['contexts'];
		}
		return $out;
	}

	/**
	 * Empty item.
	 *
	 * @param string $id        Component id.
	 * @param array  $component From the summary.
	 * @param array  $installed installed().
	 * @return array
	 */
	private static function new_item( $id, array $component, array $installed ) {
		$item = array(
			'id'       => $id,
			'kind'     => 0 === strpos( $id, 'theme:' ) ? 'theme' : 'plugin',
			'status'   => (string) $component['status'],
			'active'   => ! empty( $component['active'] ),
			'name'     => $id,
			'version'  => '',
			'update'   => false,
			'problems' => 0,
			'groups'   => array(),
			'more'     => 0,
			'families' => array(),
			'usage'    => array(
				'rows'     => 0,
				'contexts' => 0,
				'whole'    => false,
				'front'    => false,
			),
		);
		$list = 'theme' === $item['kind'] ? $installed['themes'] : $installed['plugins'];
		if ( isset( $list[ $id ] ) ) {
			$item = array_merge( $item, $list[ $id ] );
		} elseif ( 'mu-plugins' === $id ) {
			$item['name'] = __( 'Must-use plugins', 'upshift' );
		} elseif ( 0 === strpos( $id, 'dropin:' ) ) {
			/* translators: %s: file name, e.g. object-cache.php. */
			$item['name'] = sprintf( __( 'Special WordPress file (%s)', 'upshift' ), substr( $id, 7 ) . '.php' );
		} elseif ( false !== strpos( $id, ':' ) ) {
			$item['name'] = substr( $id, strpos( $id, ':' ) + 1 );
		}
		return $item;
	}

	/**
	 * Adds one problem row to its item. Rows of one file with the same plain text and status form one group, and the
	 * same line counts once (two parameters on one line are one place to fix, D60).
	 *
	 * @param array $item Item (by reference).
	 * @param array $row  Row from Join::findings().
	 */
	private static function add_problem( array &$item, array $row ) {
		$item['usage'] = self::merge_usage( $item['usage'], $row );
		$family        = Explain::rule_family( $row['rule'], $row['category'] );
		if ( Categories::NEEDS_EXTENSION === $row['category'] ) {
			$item['families'][ (string) $family ] = true;
		}
		$status = Status::of( $row['category'], $row['severity'] );
		$key    = $row['path_key'] . '|' . ( null === $family ? $row['rule'] : $family ) . '|' . $status;
		if ( ! isset( $item['groups'][ $key ] ) ) {
			if ( count( $item['groups'] ) >= self::MAX_GROUPS ) {
				++$item['problems'];
				++$item['more'];
				return;
			}
			$item['groups'][ $key ] = array(
				'file'     => self::file_label( $row['path_key'] ),
				'rule'     => (string) $row['rule'],
				'rules'    => array(),
				'category' => (string) $row['category'],
				'severity' => (string) $row['severity'],
				'status'   => $status,
				'lines'    => array(),
				'usage'    => array(
					'rows'     => 0,
					'contexts' => 0,
					'whole'    => false,
					'front'    => false,
				),
			);
		}
		if ( ! isset( $item['groups'][ $key ]['lines'][ (int) $row['line'] ] ) ) {
			$item['groups'][ $key ]['lines'][ (int) $row['line'] ] = true;
			++$item['problems'];
		}
		if ( ! isset( $item['groups'][ $key ]['rules'][ $row['rule'] ] ) ) {
			$item['groups'][ $key ]['rules'][ $row['rule'] ] = (int) $row['id'];
		}
		$item['groups'][ $key ]['usage'] = self::merge_usage( $item['groups'][ $key ]['usage'], $row );
	}

	/**
	 * Adds a row's activity to an aggregate.
	 *
	 * @param array $usage Aggregate.
	 * @param array $row   Row from Join::findings().
	 * @return array
	 */
	private static function merge_usage( array $usage, array $row ) {
		if ( ! empty( $row['seen'] ) ) {
			++$usage['rows'];
			$usage['contexts'] |= (int) $row['contexts'];
			$usage['whole']     = $usage['whole'] || ! empty( $row['whole_site'] );
			$usage['front']     = $usage['front'] || ( (int) $row['always_in'] & Recorder::FRONT );
		}
		return $usage;
	}

	/**
	 * The path inside the plugin or theme, e.g. "includes/class-foo.php".
	 *
	 * @param string $path_key Stored path key, e.g. "plugins:foo/includes/class-foo.php".
	 * @return string
	 */
	public static function file_label( $path_key ) {
		$colon    = strpos( (string) $path_key, ':' );
		$relative = false === $colon ? (string) $path_key : substr( (string) $path_key, $colon + 1 );
		$slash    = strpos( $relative, '/' );
		return false === $slash ? $relative : substr( $relative, $slash + 1 );
	}

	/**
	 * Fills the plain-English texts (one message query for all groups).
	 *
	 * @param array $items Items (by reference).
	 * @param int   $days  Days of activity data.
	 */
	private static function explain( array &$items, $days ) {
		$ids = array();
		foreach ( $items as $item ) {
			foreach ( $item['groups'] as $group ) {
				$ids = array_merge( $ids, array_values( $group['rules'] ) );
			}
		}
		$messages = Join::messages( $ids );

		foreach ( $items as $id => $item ) {
			foreach ( $item['groups'] as $key => $group ) {
				$tech = array();
				foreach ( $group['rules'] as $rule => $finding_id ) {
					$tech[ $rule ] = isset( $messages[ $finding_id ] ) ? (string) $messages[ $finding_id ] : '';
				}
				$where = self::where( $group['usage'], $days, $item['active'] );

				$items[ $id ]['groups'][ $key ]['text'] = Explain::of( $group['rule'], $group['category'], $group['severity'] );
				$items[ $id ]['groups'][ $key ]['tech'] = $tech;
				$items[ $id ]['groups'][ $key ]['runs'] = $where['table'];
			}
		}
	}

	/**
	 * Whether a row is a note that doesn't affect this upgrade (never counted, CSV and Developer tab only).
	 *
	 * @param array $row Row from Join::findings().
	 * @return bool
	 */
	public static function is_note( array $row ) {
		return ! empty( $row['baseline'] ) || ! empty( $row['guarded'] ) || null === Status::of( $row['category'], $row['severity'] );
	}

	/**
	 * Key that merges notes the same way as the CSV rows (D60): plugin, file, line and plain text.
	 *
	 * @param array $row Row from Join::findings().
	 * @return string
	 */
	public static function note_key( array $row ) {
		$explain = Explain::of( $row['rule'], $row['category'], $row['severity'] );
		return implode( '|', array( $row['component'], self::file_label( $row['path_key'] ), $row['line'], $explain['problem'] ) );
	}

	/**
	 * The status word of a note in the CSV.
	 *
	 * @return string
	 */
	public static function note_label() {
		return __( "Doesn't affect this upgrade", 'upshift' );
	}

	/**
	 * Why a note doesn't affect this upgrade.
	 *
	 * @param array  $row  Row from Join::findings().
	 * @param string $from From version.
	 * @return string
	 */
	public static function note_reason( array $row, $from ) {
		if ( ! empty( $row['guarded'] ) ) {
			return Explain::guarded_impact();
		}
		if ( ! empty( $row['baseline'] ) ) {
			/* translators: %s: current PHP version. */
			return sprintf( __( 'Already like this on PHP %s, so the upgrade changes nothing.', 'upshift' ), $from );
		}
		return Explain::impact( $row['category'], $row['severity'] );
	}

	/**
	 * Where a set of rows runs, in plain words with chips.
	 *
	 * @param array $usage  Aggregate from merge_usage().
	 * @param int   $days   Days of activity data.
	 * @param bool  $active Whether the plugin is active.
	 * @return array rank (lower = hotter), kind, chips, text.
	 */
	private static function where( array $usage, $days, $active ) {
		$out = array(
			'rank'  => 5,
			'kind'  => 'none',
			'chips' => array(),
			'text'  => '',
			'table' => '',
		);
		if ( ! $active ) {
			$out['rank']  = 6;
			$out['kind']  = 'inactive';
			$out['text']  = __( 'Not active', 'upshift' );
			$out['table'] = $out['text'];
			$out['chips'] = array( array( 'dashicons-hidden', __( 'not active', 'upshift' ) ) );
			return $out;
		}
		if ( $days <= 0 ) {
			return $out;
		}
		if ( $usage['rows'] <= 0 ) {
			$out['kind'] = 'never';
			/* translators: %s: number of days. */
			$out['text']  = sprintf( _n( 'Not seen running in the last %s day', 'Not seen running in the last %s days', $days, 'upshift' ), number_format_i18n( $days ) );
			$out['table'] = $out['text'];
			/* translators: %s: number of days. */
			$out['chips'] = array( array( 'dashicons-hidden', sprintf( _n( 'not seen running in the last %s day', 'not seen running in the last %s days', $days, 'upshift' ), number_format_i18n( $days ) ) ) );
			return $out;
		}

		$contexts = (int) $usage['contexts'] & ~Recorder::CLI;
		if ( 0 === $contexts ) {
			return $out;
		}
		$shop = Recorder::WC_SHOP | Recorder::WC_PRODUCT | Recorder::WC_ACCOUNT;
		if ( $contexts & ( Recorder::WC_CHECKOUT | Recorder::WC_CART ) ) {
			$out['rank'] = 0;
		} elseif ( $usage['whole'] || $usage['front'] ) {
			$out['rank'] = 1;
		} elseif ( $contexts & $shop ) {
			$out['rank'] = 2;
		} elseif ( $contexts & Recorder::FRONT ) {
			$out['rank'] = 3;
		} else {
			$out['rank'] = 4;
		}

		if ( $usage['whole'] ) {
			$out['kind']  = 'every';
			$out['text']  = __( 'Runs on every page', 'upshift' );
			$out['table'] = __( 'every page', 'upshift' );
			$out['chips'] = array( array( 'dashicons-admin-site-alt3', __( 'every page', 'upshift' ) ) );
			if ( $contexts & Recorder::WC_CHECKOUT ) {
				$out['kind'] = 'checkout';
			}
			return $out;
		}

		$labels = array();
		foreach ( self::chip_list() as $bit => $chip ) {
			if ( $contexts & $bit ) {
				$out['chips'][] = $chip;
				$labels[]       = $chip[1];
			}
		}
		$out['kind'] = $contexts & Recorder::WC_CHECKOUT ? 'checkout' : 'some';
		$out['list'] = wp_sprintf_l( '%l', $labels );
		/* translators: %s: list of places, e.g. "checkout, cart and admin". */
		$out['text']  = sprintf( __( 'Runs on: %s', 'upshift' ), $out['list'] );
		$out['table'] = $out['list'];
		return $out;
	}

	/**
	 * "Runs on" text for one row, for the CSV.
	 *
	 * @param array $row  Row from Join::findings().
	 * @param int   $days Days of activity data.
	 * @return string
	 */
	public static function row_runs( array $row, $days ) {
		$usage = self::merge_usage(
			array(
				'rows'     => 0,
				'contexts' => 0,
				'whole'    => false,
				'front'    => false,
			),
			$row
		);
		$where = self::where( $usage, $days, ! empty( $row['active'] ) );
		return $where['table'];
	}

	/**
	 * Names of the plugins and themes in a summary, for the CSV.
	 *
	 * @param array $summary Status::for_scan().
	 * @return array component id => name.
	 */
	public static function names( array $summary ) {
		$installed = self::installed();
		$names     = array();
		foreach ( $summary['components'] as $id => $component ) {
			$item         = self::new_item( (string) $id, $component, $installed );
			$names[ $id ] = $item['name'];
		}
		return $names;
	}

	/**
	 * Chips in display order (docs/UI-v1.md 8a): context bit => array( dashicon, label ).
	 *
	 * @return array
	 */
	public static function chip_list() {
		return array(
			Recorder::WC_CHECKOUT => array( 'dashicons-cart', __( 'checkout', 'upshift' ) ),
			Recorder::WC_CART     => array( 'dashicons-cart', __( 'cart', 'upshift' ) ),
			Recorder::WC_SHOP     => array( 'dashicons-store', __( 'shop', 'upshift' ) ),
			Recorder::WC_PRODUCT  => array( 'dashicons-store', __( 'product pages', 'upshift' ) ),
			Recorder::WC_ACCOUNT  => array( 'dashicons-admin-users', __( 'customer account', 'upshift' ) ),
			Recorder::FRONT       => array( 'dashicons-admin-site-alt3', __( 'site pages', 'upshift' ) ),
			Recorder::ADMIN       => array( 'dashicons-dashboard', __( 'admin', 'upshift' ) ),
			Recorder::LOGIN       => array( 'dashicons-lock', __( 'login', 'upshift' ) ),
			Recorder::CRON        => array( 'dashicons-clock', __( 'scheduled tasks', 'upshift' ) ),
			Recorder::AJAX        => array( 'dashicons-update', __( 'background requests', 'upshift' ) ),
			Recorder::REST        => array( 'dashicons-update', __( 'connected apps', 'upshift' ) ),
		);
	}

	/**
	 * Adds where it runs and what to do.
	 *
	 * @param array  $item     Item.
	 * @param array  $contexts component_contexts().
	 * @param int    $days     Days of activity data.
	 * @param string $target   Target version.
	 * @param string $from     From version.
	 * @return array
	 */
	private static function finish_item( array $item, array $contexts, $days, $target, $from ) {
		$usage = $item['usage'];
		// A Ready plugin has no problem rows to judge by, so it says where the plugin as a whole was seen.
		if ( 0 === $item['problems'] && isset( $contexts[ $item['id'] ] ) ) {
			$usage['rows']     = 1;
			$usage['contexts'] = $contexts[ $item['id'] ];
		}
		$item['where']  = self::where( $usage, $days, $item['active'] );
		$item['advice'] = self::advice( $item, $days, $target, $from );
		$item['groups'] = array_values( $item['groups'] );
		foreach ( $item['groups'] as $i => $group ) {
			$item['groups'][ $i ]['lines'] = array_keys( $group['lines'] );
		}
		usort( $item['groups'], array( __CLASS__, 'compare_groups' ) );
		$item['developer_text'] = $item['groups'] ? self::developer_text( $item, $target ) : '';
		return $item;
	}

	/**
	 * What to do about an item: one short action, one line of reason, one link or copy button.
	 *
	 * @param array  $item   Item.
	 * @param int    $days   Days of activity data.
	 * @param string $target Target version.
	 * @param string $from   From version.
	 * @return array name, status, action, reason, todo, link, link_text, copy.
	 */
	private static function advice( array $item, $days, $target, $from ) {
		$out = array(
			'name'      => $item['name'],
			'status'    => $item['status'],
			'action'    => '',
			'reason'    => '',
			'todo'      => __( 'Nothing to do.', 'upshift' ),
			'link'      => '',
			'link_text' => '',
			'copy'      => '',
		);
		if ( Status::READY === $item['status'] ) {
			return $out;
		}

		$name  = $item['name'];
		$theme = 'theme' === $item['kind'];
		if ( ! empty( $item['update'] ) ) {
			/* translators: %s: plugin or theme name. */
			$out['action']    = sprintf( __( 'Update %s.', 'upshift' ), $name );
			$out['reason']    = self::update_reason( $item['where'], $theme );
			$out['link']      = self_admin_url( 'update-core.php' );
			$out['link_text'] = __( 'Go to updates', 'upshift' );
		} elseif ( Status::HOST === $item['status'] ) {
			$out = self::host_advice( $out, $item, $days, $target, $from );
		} elseif ( Status::BREAKS === $item['status'] ) {
			/* translators: 1: plugin or theme name, 2: target PHP version. */
			$out['action'] = sprintf( __( '%1$s uses features PHP %2$s removed.', 'upshift' ), $name, $target );
			if ( 'never' === $item['where']['kind'] ) {
				$out['reason'] = $theme
					/* translators: %s: number of days. */
					? sprintf( _n( 'It hasn\'t run in %s day: switch to another theme, or contact the theme developer.', 'It hasn\'t run in %s days: switch to another theme, or contact the theme developer.', $days, 'upshift' ), number_format_i18n( $days ) )
					/* translators: %s: number of days. */
					: sprintf( _n( 'It hasn\'t run in %s day: deactivate it, or contact the plugin developer.', 'It hasn\'t run in %s days: deactivate it, or contact the plugin developer.', $days, 'upshift' ), number_format_i18n( $days ) );
			} else {
				$out['reason'] = $theme ? __( 'Contact the theme developer or look for a maintained alternative.', 'upshift' ) : __( 'Contact the plugin developer or look for a maintained alternative.', 'upshift' );
			}
			if ( 0 === strpos( $item['id'], 'plugin:' ) ) {
				$out['link']      = 'never' === $item['where']['kind'] ? self_admin_url( 'plugins.php' ) : self_admin_url( 'plugin-install.php?tab=search&type=term&s=' . rawurlencode( $name ) );
				$out['link_text'] = 'never' === $item['where']['kind'] ? __( 'Go to plugins', 'upshift' ) : __( 'Find an alternative', 'upshift' );
			} elseif ( $theme ) {
				$out['link']      = self_admin_url( 'themes.php' );
				$out['link_text'] = __( 'Go to themes', 'upshift' );
			}
		} else {
			/* translators: 1: plugin or theme name, 2: target PHP version. */
			$out['action'] = sprintf( __( '%1$s works on PHP %2$s but shows a warning.', 'upshift' ), $name, $target );
			$out['reason'] = $theme ? __( 'It will stop working in a future PHP version. Ask the theme developer for a fix when you can.', 'upshift' ) : __( 'It will stop working in a future PHP version. Ask the plugin developer for a fix when you can.', 'upshift' );
		}
		/* translators: 1: a short action, 2: the reason. Both are whole sentences. */
		$out['todo'] = sprintf( _x( '%1$s %2$s', 'action, then reason', 'upshift' ), $out['action'], $out['reason'] );
		return $out;
	}

	/**
	 * Reason for "Update X". Whole sentences per place and kind, so translators never join words.
	 *
	 * @param array $where where().
	 * @param bool  $theme Whether it is a theme.
	 * @return string
	 */
	private static function update_reason( array $where, $theme ) {
		switch ( $where['kind'] ) {
			case 'checkout':
				return $theme ? __( 'An update is available and this theme runs on your checkout.', 'upshift' ) : __( 'An update is available and this plugin runs on your checkout.', 'upshift' );
			case 'every':
				return $theme ? __( 'An update is available and this theme runs on every page.', 'upshift' ) : __( 'An update is available and this plugin runs on every page.', 'upshift' );
			case 'some':
				return $theme
					/* translators: %s: list of places, e.g. "shop and admin". */
					? sprintf( __( 'An update is available and this theme runs on: %s.', 'upshift' ), $where['list'] )
					/* translators: %s: list of places, e.g. "shop and admin". */
					: sprintf( __( 'An update is available and this plugin runs on: %s.', 'upshift' ), $where['list'] );
			default:
				return __( 'An update is available.', 'upshift' );
		}
	}

	/**
	 * Advice for a plugin that needs a feature PHP no longer includes.
	 *
	 * @param array  $out    Advice so far.
	 * @param array  $item   Item.
	 * @param int    $days   Days of activity data.
	 * @param string $target Target version.
	 * @param string $from   From version.
	 * @return array
	 */
	private static function host_advice( array $out, array $item, $days, $target, $from ) {
		$names = array();
		$map   = array(
			'ext_imap'   => 'IMAP',
			'ext_oci8'   => 'OCI8',
			'ext_pspell' => 'pspell',
			'ext_xmlrpc' => 'XML-RPC',
			'ext_other'  => __( 'a PHP add-on', 'upshift' ),
		);
		foreach ( array_keys( $item['families'] ) as $family ) {
			$names[] = isset( $map[ $family ] ) ? $map[ $family ] : $map['ext_other'];
		}
		$count = count( $names );
		$list  = wp_sprintf_l( '%l', $names );

		/* translators: 1: plugin or theme name, 2: list of PHP features, e.g. "IMAP and pspell", 3: target PHP version. */
		$out['action'] = sprintf( _n( '%1$s uses the %2$s feature, which PHP %3$s no longer includes by default.', '%1$s uses %2$s, which PHP %3$s no longer includes by default.', max( 1, $count ), 'upshift' ), $item['name'], $list, $target );
		if ( 'never' === $item['where']['kind'] ) {
			/* translators: 1: number of days, 2: list of PHP features. */
			$out['reason'] = sprintf( _n( 'It hasn\'t run in %1$s day: deactivate it, or ask your host to keep %2$s.', 'It hasn\'t run in %1$s days: deactivate it, or ask your host to keep %2$s.', $days, 'upshift' ), number_format_i18n( $days ), $list );
		} else {
			$out['reason'] = _n( 'Ask your host if they keep it.', 'Ask your host if they keep them.', max( 1, $count ), 'upshift' );
		}
		/* translators: 1: target PHP version, 2: plugin or theme name, 3: list of PHP features, 4: current PHP version. */
		$out['copy']      = sprintf( _n( 'Hello. We plan to move this site from PHP %4$s to PHP %1$s. Our %2$s uses the %3$s feature. Will it still be available after the move?', 'Hello. We plan to move this site from PHP %4$s to PHP %1$s. Our %2$s uses %3$s. Will these still be available after the move?', max( 1, $count ), 'upshift' ), $target, $item['name'], $list, $from );
		$out['link_text'] = __( 'Copy message for your host', 'upshift' );
		return $out;
	}

	/**
	 * Items: worst status first, then where it runs, then number of problems, then name.
	 *
	 * @param array $a Item.
	 * @param array $b Item.
	 * @return int
	 */
	public static function compare_items( array $a, array $b ) {
		$c = Status::rank( $a['status'] ) - Status::rank( $b['status'] );
		if ( 0 !== $c ) {
			return $c;
		}
		$c = $a['where']['rank'] - $b['where']['rank'];
		if ( 0 !== $c ) {
			return $c;
		}
		$c = $b['problems'] - $a['problems'];
		return 0 !== $c ? $c : strcasecmp( $a['name'], $b['name'] );
	}

	/**
	 * Problem groups: worst status first, then file, then first line.
	 *
	 * @param array $a Group.
	 * @param array $b Group.
	 * @return int
	 */
	public static function compare_groups( array $a, array $b ) {
		$c = Status::rank( $a['status'] ) - Status::rank( $b['status'] );
		if ( 0 !== $c ) {
			return $c;
		}
		$c = strcmp( $a['file'], $b['file'] );
		return 0 !== $c ? $c : min( $a['lines'] ) - min( $b['lines'] );
	}

	/**
	 * Code files of active plugins that were not checked, grouped by plain reason. Data files are left out on purpose.
	 *
	 * @param int   $scan_id Scan id.
	 * @param array $items   Items by component id, for the names.
	 * @return array total, reasons (list of array( text, count, files )).
	 */
	private static function not_checked( $scan_id, array $items ) {
		global $wpdb;

		$total = Runner::file_checks( (int) $scan_id );
		$total = (int) $total['code_active'];
		$out   = array(
			'total'   => $total,
			'reasons' => array(),
		);
		if ( 0 === $total ) {
			return $out;
		}
		$files = Install::table( 'scan_files' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT component, path_key, status, note FROM {$files} WHERE scan_id = %d AND component_active = 1 AND status IN ('skipped_large','unreadable','partial','engine_error') AND NOT ( status = 'skipped_large' AND note LIKE %s ) ORDER BY id LIMIT %d", (int) $scan_id, '%' . $wpdb->esc_like( 'data/classmap' ) . '%', self::MAX_NOT_CHECKED ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$text = self::not_checked_reason( $row['status'], (string) $row['note'] );
			if ( ! isset( $out['reasons'][ $text ] ) ) {
				$out['reasons'][ $text ] = array(
					'text'  => $text,
					'count' => 0,
					'files' => array(),
				);
			}
			++$out['reasons'][ $text ]['count'];
			if ( count( $out['reasons'][ $text ]['files'] ) < 3 ) {
				$name = isset( $items[ $row['component'] ] ) ? $items[ $row['component'] ]['name'] : $row['component'];
				/* translators: 1: plugin or theme name, 2: file path inside it. */
				$out['reasons'][ $text ]['files'][] = sprintf( __( '%1$s (%2$s)', 'upshift' ), $name, self::file_label( $row['path_key'] ) );
			}
		}
		$out['reasons'] = array_values( $out['reasons'] );
		return $out;
	}

	/**
	 * Plain reason and next step for a file that was not checked.
	 *
	 * @param string $status File status.
	 * @param string $note   Stored note.
	 * @return string
	 */
	private static function not_checked_reason( $status, $note ) {
		switch ( $status ) {
			case 'unreadable':
				return __( 'This file could not be read. Ask your host to check its file permissions, then check again.', 'upshift' );
			case 'partial':
				return __( 'Only part of this file could be checked. Update the plugin or theme, then check again.', 'upshift' );
			case 'engine_error':
				return __( 'The check stopped on this file. Ask your host to raise the PHP memory limit to 512M, then check again.', 'upshift' );
			default:
				return false !== strpos( $note, 'too large (' )
					? __( 'This file is too large to check. Ask the developer whether it can be split up.', 'upshift' )
					: __( 'This file is too large for this server\'s memory limit. Ask your host to raise the PHP memory limit to 512M, then check again.', 'upshift' );
		}
	}

	/**
	 * Short plain text for a developer or host, for the "Copy text" button: a header, then one line per plugin with problems.
	 *
	 * @param array $report build().
	 * @return string
	 */
	public static function text_report( array $report ) {
		$lines = array(
			/* translators: %s: site address. */
			sprintf( __( 'PHP upgrade check for %s', 'upshift' ), home_url() ),
			/* translators: 1: current PHP version, 2: target PHP version, 3: date. */
			sprintf( __( 'PHP %1$s to PHP %2$s, checked on %3$s.', 'upshift' ), $report['from'], $report['target'], mysql2date( __( 'j M Y', 'upshift' ), (string) $report['scan']['finished'] ) ),
			$report['answer']['sentence'],
			'',
		);
		$shown = 0;
		$left  = 0;
		$all   = array_merge( $report['items'], $report['inactive'] );
		foreach ( array( Status::BREAKS, Status::HOST, Status::LATER ) as $status ) {
			$section = array();
			foreach ( $all as $item ) {
				if ( $status !== $item['status'] || ! $item['groups'] ) {
					continue;
				}
				if ( $shown >= self::MAX_COPY_ITEMS ) {
					++$left;
					continue;
				}
				$section[] = self::copy_line( $item );
				++$shown;
			}
			if ( $section ) {
				if ( '' !== end( $lines ) ) {
					$lines[] = '';
				}
				$lines[] = self::copy_heading( $status );
				$lines   = array_merge( $lines, $section );
			}
		}
		if ( $left > 0 ) {
			/* translators: %s: number of plugins and themes. */
			$lines[] = sprintf( _n( '... and %s more plugin or theme in the CSV report.', '... and %s more plugins and themes in the CSV report.', $left, 'upshift' ), number_format_i18n( $left ) );
		}
		if ( 0 === $shown && 0 === $left ) {
			// Ready: no blank lines and no pointer to a CSV of problems.
			$lines   = array_slice( $lines, 0, 3 );
			$total   = (int) $report['total'];
			$lines[] = sprintf(
				/* translators: %s: number of plugins and themes. */
				_n( 'Checked %s plugin or theme. No problems found.', 'Checked %s plugins and themes. No problems found.', $total, 'upshift' ),
				number_format_i18n( $total )
			);
			return implode( "\n", $lines );
		}
		$lines[] = '';
		$lines[] = __( 'Full list with files and lines: Download report (CSV)', 'upshift' );
		return implode( "\n", $lines );
	}

	/**
	 * Section heading of the short text.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function copy_heading( $status ) {
		switch ( $status ) {
			case Status::BREAKS:
				return __( 'Will break:', 'upshift' );
			case Status::HOST:
				return __( 'Ask your host:', 'upshift' );
			default:
				return __( 'Works, fix later:', 'upshift' );
		}
	}

	/**
	 * One plugin's line in the short text: name, version, places, main problem, and "not seen running" if true.
	 *
	 * @param array $item Finished item.
	 * @return string
	 */
	private static function copy_line( array $item ) {
		$count = (int) $item['problems'];
		$line  = sprintf(
			/* translators: 1: plugin or theme name with version, 2: number of places, 3: the main problem, a whole sentence. */
			_n( '%1$s: %2$s place. %3$s', '%1$s: %2$s places. %3$s', $count, 'upshift' ),
			trim( $item['name'] . ' ' . $item['version'] ),
			number_format_i18n( $count ),
			self::main_problem( $item )
		);
		if ( 'never' === $item['where']['kind'] ) {
			$line .= ' ' . $item['where']['text'] . '.';
		}
		return $line;
	}

	/**
	 * The plain text of the problem that decides the plugin's status and occurs most often.
	 *
	 * @param array $item Finished item.
	 * @return string
	 */
	private static function main_problem( array $item ) {
		$best  = '';
		$count = 0;
		foreach ( $item['groups'] as $group ) {
			if ( $group['status'] === $item['status'] && count( $group['lines'] ) > $count ) {
				$count = count( $group['lines'] );
				$best  = $group['text']['problem'];
			}
		}
		return $best;
	}

	/**
	 * Text for one plugin's developer: problems grouped by type, each with a count and up to three file:line examples.
	 *
	 * @param array  $item   Item with explained groups and whole line lists.
	 * @param string $target Target version.
	 * @return string
	 */
	private static function developer_text( array $item, $target ) {
		$types = array();
		foreach ( $item['groups'] as $group ) {
			$problem = $group['text']['problem'];
			if ( ! isset( $types[ $problem ] ) ) {
				$types[ $problem ] = array(
					'fix'      => $group['text']['how_to_fix'],
					'count'    => 0,
					'examples' => array(),
				);
			}
			$types[ $problem ]['count'] += count( $group['lines'] );
			foreach ( array_slice( $group['lines'], 0, self::MAX_EXAMPLES - count( $types[ $problem ]['examples'] ) ) as $line ) {
				$types[ $problem ]['examples'][] = $group['file'] . ':' . $line;
			}
		}

		$count = (int) $item['problems'];
		$lines = array(
			sprintf(
				/* translators: 1: plugin or theme name with version, 2: target PHP version, 3: status word, 4: number of places. */
				_n( '%1$s. Result for PHP %2$s: %3$s, %4$s place.', '%1$s. Result for PHP %2$s: %3$s, %4$s places.', $count, 'upshift' ),
				trim( $item['name'] . ' ' . $item['version'] ),
				$target,
				Status::label( $item['status'] ),
				number_format_i18n( $count )
			),
		);
		foreach ( $types as $problem => $type ) {
			$lines[] = '';
			/* translators: 1: what the problem is, 2: how to fix it, 3: number of places. */
			$lines[] = sprintf( _n( '- %1$s %2$s (%3$s place)', '- %1$s %2$s (%3$s places)', $type['count'], 'upshift' ), $problem, $type['fix'], number_format_i18n( $type['count'] ) );
			$lines[] = '  ' . implode( ', ', $type['examples'] );
		}
		if ( $item['more'] > 0 ) {
			$lines[] = '';
			/* translators: %s: number of places. */
			$lines[] = sprintf( _n( '%s more place is in the CSV report.', '%s more places are in the CSV report.', (int) $item['more'], 'upshift' ), number_format_i18n( (int) $item['more'] ) );
		}
		return implode( "\n", $lines );
	}
}
