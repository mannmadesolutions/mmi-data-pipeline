<?php
/**
 * Proposed maps (owner 2026-11-03: "the intent is to identify rendered DOM elements (however many exist per
 * brand/vendor) for product asset scraping on a macro level, catalog-wide … mapping those elements and their content
 * (images, links, text, etc) to the host's product meta. Deciding its quality/validity is phase 2 … shouldn't prevent
 * identification … you could do most of that mapping work yourself and provide a human with your work to approve").
 *
 * For every maker site and every part, propose() finds the page element that holds it on the site's stored pages
 * (VendorMaps::propose_field(): the drafter's element finder, which here always proposes, even where the general
 * reader does as well), first for the whole site, then for each layout the site's element misses on (its own pages).
 * Each proposal keeps what it reads on three sample products. A reviewer (Claude, `wp mmpt maps proposal`) may change
 * one and leave a note. Nothing a proposal says reaches the reader or a product until a person approves it: approve()
 * saves it as the site's or the layout's part through VendorMaps::set_field() (its guards and tests), and products are
 * written from it only by a commit (the Chrome extension) or Confirm.
 *
 * A person inspects them on the maker's page in the extension's mapper (each part's proposal outlined on the live
 * page, with Approve and Reject) or on the Product Data page (Site maps).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MapProposals {

	const DB_OPTION  = 'mmpt_map_proposals_db';
	const DB_VERSION = '1';
	const FIELDS     = [ 'pictures', 'description', 'requirements', 'features', 'manual', 'videos', 'audio', 'version' ];
	const SITE       = -1;  // a proposal for the whole site; else a layout index (MapTypes)
	const SAMPLE     = 12;  // stored pages an element is looked for on (the site's, or one layout's)
	const LAYOUT_MIN = 3;   // pages a layout needs for a proposal of its own
	const LAYOUTS    = 12;  // layouts looked at per site (the largest)
	const MISS       = 0.6; // a layout gets its own proposal when the site's element reads fewer of its pages than this
	const SAMPLES    = 3;   // products whose reading a proposal keeps (those it reads something on first)
	const LOOK       = 10;  // pages looked at for them
	const STATUSES   = [ 'proposed', 'approved', 'rejected' ];

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	/** The Product Data page's list (Site maps) and its decisions. Not /maps/…: /maps/{host} would take "proposals" as a host. */
	public static function routes(): void {
		$admin = static fn() => current_user_can( Module::CAP );
		register_rest_route(
			Module::NS,
			'/map-proposals',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$host = (string) $r->get_param( 'host' );
					$rows = self::rows( preg_match( '/^[a-z0-9.-]{3,190}$/', $host ) ? $host : '' );
					return rest_ensure_response( [ 'rows' => array_map( static fn( $p ) => self::out( $p ) + [ 'host' => $p['host'] ], $rows ) ] );
				},
			]
		);
		register_rest_route(
			Module::NS,
			'/map-proposals/(?P<id>\d+)',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = self::decide( (int) $r['id'], sanitize_key( (string) $r->get_param( 'decision' ) ) === 'approve', get_current_user_id() );
					return is_wp_error( $res ) ? new \WP_REST_Response( [ 'message' => $res->get_error_message() ], 409 ) : rest_ensure_response( [ 'row' => self::out( $res ) + [ 'host' => $res['host'] ] ] );
				},
			]
		);
		register_rest_route(
			Module::NS,
			'/map-proposals/site',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$host = VendorMaps::host( (string) $r->get_param( 'host' ) );
					$done = 0;
					$fail = [];
					foreach ( self::rows( $host, 'proposed' ) as $p ) {
						$res = self::decide( $p['id'], true, get_current_user_id() );
						if ( is_wp_error( $res ) ) {
							$fail[] = ( VendorMaps::FIELDS[ $p['field'] ][0] ?? $p['field'] ) . ': ' . $res->get_error_message();
						} else {
							++$done;
						}
					}
					return rest_ensure_response( [ 'approved' => $done, 'failed' => $fail, 'rows' => array_map( static fn( $p ) => self::out( $p ) + [ 'host' => $p['host'] ], self::rows( $host ) ) ] );
				},
			]
		);
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_map_proposals';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			host varchar(190) NOT NULL,
			layout int(11) NOT NULL DEFAULT -1,
			field varchar(32) NOT NULL,
			sel text NOT NULL,
			mode varchar(16) NOT NULL DEFAULT '',
			where_text varchar(200) NOT NULL DEFAULT '',
			found int(11) NOT NULL DEFAULT 0,
			of int(11) NOT NULL DEFAULT 0,
			samples longtext NULL,
			note text NULL,
			checked varchar(16) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'proposed',
			decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			decided_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY host_layout_field (host, layout, field),
			KEY status (status)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/* ── Proposing ──────────────────────────────────────────────────────── */

	/**
	 * Propose every part's element for site $host: for the whole site, and for each layout its element misses on.
	 * A proposal already decided is left as it is (approved stays approved; one rejected comes back only with another
	 * element). Parts a person mapped are left out. @return array{host:string,proposed:int,layouts:int,skipped?:string}
	 */
	public static function propose( string $host ): array {
		self::maybe_install();
		$h   = VendorMaps::host( $host );
		$row = VendorMaps::get( $h );
		if ( ! $row ) {
			return [ 'host' => $h, 'proposed' => 0, 'layouts' => 0, 'skipped' => 'no site map' ];
		}
		$pages = self::readable( VendorMaps::pages( $h ) );
		if ( count( $pages ) < 2 ) {
			return [ 'host' => $h, 'proposed' => 0, 'layouts' => 0, 'skipped' => 'fewer than 2 stored pages' ];
		}
		$layouts = array_filter( MapTypes::layout_pages( $h ), static fn( $l ) => count( $l['urls'] ) >= self::LAYOUT_MIN );
		uasort( $layouts, static fn( $a, $b ) => count( $b['urls'] ) <=> count( $a['urls'] ) );
		$layouts = array_slice( $layouts, 0, self::LAYOUTS, true );
		$site    = self::htmls( self::spread( $pages, self::SAMPLE ) );
		$n       = 0;
		foreach ( self::FIELDS as $f ) {
			if ( ( $row['fields'][ $f ]['source'] ?? '' ) === 'person' && ! empty( $row['fields'][ $f ]['sel'] ) ) {
				continue; // a person mapped it
			}
			$cand = VendorMaps::propose_field( $f, $site );
			$on   = null;
			if ( $cand ) {
				$t = VendorMaps::test( $h, $f, $cand['sel'], $cand['mode'] );
				$n += self::keep( $h, self::SITE, $f, $cand, $t, self::spread( $pages, self::LOOK ) );
			}
			foreach ( $layouts as $i => $l ) {
				$own = MapTypes::for_layout( $h, (int) $i );
				if ( $own && ! is_wp_error( $own ) && ! empty( $own['fields'][ $f ]['sel'] ) ) {
					continue; // the layout has a pick of its own
				}
				$only = array_fill_keys( $l['urls'], 1 );
				$on   = $cand ? VendorMaps::test( $h, $f, $cand['sel'], $cand['mode'], VendorMaps::TEST_MAX, '', '', $only ) : null;
				if ( $on && $on['of'] && $on['found'] / $on['of'] >= self::MISS ) {
					self::drop( $h, (int) $i, $f ); // the site's element reads it: no layout proposal
					continue;
				}
				$mine = array_values( array_filter( $pages, static fn( $p ) => isset( $only[ $p['url'] ] ) ) );
				$lc   = VendorMaps::propose_field( $f, self::htmls( self::spread( $mine, self::SAMPLE ) ) );
				if ( ! $lc || ( $cand && $lc['sel'] === $cand['sel'] ) ) {
					continue;
				}
				$lt = VendorMaps::test( $h, $f, $lc['sel'], $lc['mode'], VendorMaps::TEST_MAX, '', '', $only );
				if ( $lt['found'] > (int) ( $on['found'] ?? 0 ) ) {
					$n += self::keep( $h, (int) $i, $f, $lc, $lt, self::spread( $mine, self::LOOK ) );
				}
			}
		}
		return [ 'host' => $h, 'proposed' => $n, 'layouts' => count( $layouts ) ];
	}

	/** Stored pages read fine, one per address. */
	private static function readable( array $pages ): array {
		$seen = [];
		return array_values( array_filter( $pages, static function ( $p ) use ( &$seen ) {
			if ( $p['status'] !== 'ok' || (string) $p['file'] === '' || isset( $seen[ $p['url'] ] ) ) {
				return false;
			}
			$seen[ $p['url'] ] = 1;
			return true;
		} ) );
	}

	/** Up to $max pages spread evenly over $pages. */
	private static function spread( array $pages, int $max ): array {
		if ( count( $pages ) <= $max ) {
			return $pages;
		}
		$step = count( $pages ) / $max;
		return array_map( static fn( $i ) => $pages[ (int) floor( $i * $step ) ], range( 0, $max - 1 ) );
	}

	/** @return array<int,array{url:string,html:string}> */
	private static function htmls( array $pages ): array {
		$out = [];
		foreach ( $pages as $p ) {
			$html = PageStore::read( (string) $p['file'] );
			if ( $html !== '' ) {
				$out[] = [ 'url' => (string) $p['url'], 'html' => VendorPage::script_content( $html ) ];
			}
		}
		return $out;
	}

	/** Store one proposal with what it reads on a few products; 1 when it is new or changed. */
	private static function keep( string $h, int $layout, string $f, array $cand, array $t, array $samples ): int {
		global $wpdb;
		$was = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE host = %s AND layout = %d AND field = %s', $h, $layout, $f ), ARRAY_A );
		if ( $was && ( $was['status'] === 'approved' || ( $was['status'] === 'rejected' && $was['sel'] === $cand['sel'] ) ) ) {
			return 0; // decided: kept as the person left it
		}
		if ( $was && $was['status'] === 'proposed' && (string) $was['checked'] !== '' && $was['sel'] !== $cand['sel'] ) {
			return 0; // reviewed (a changed element, a note): a later run never puts the finder's first guess back
		}
		$same = $was && $was['sel'] === $cand['sel'] && $was['mode'] === $cand['mode'];
		$data = [
			'host'       => $h,
			'layout'     => $layout,
			'field'      => $f,
			'sel'        => $cand['sel'],
			'mode'       => $cand['mode'],
			'where_text' => mb_substr( (string) ( $cand['where'] ?? '' ), 0, 200 ),
			'found'      => (int) $t['found'],
			'of'         => (int) $t['of'],
			'samples'    => wp_json_encode( self::readings( $f, $cand, $samples ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'note'       => $same ? (string) $was['note'] : '',
			'checked'    => $same ? (string) $was['checked'] : '',
			'status'     => 'proposed',
			'created_at' => current_time( 'mysql', true ),
		];
		if ( $was ) {
			$wpdb->update( self::table(), $data, [ 'id' => (int) $was['id'] ] );
		} else {
			$wpdb->insert( self::table(), $data );
		}
		return $same ? 0 : 1;
	}

	/** A layout proposal the site's element made unneeded goes (unless decided). */
	private static function drop( string $h, int $layout, string $f ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE host = %s AND layout = %d AND field = %s AND status = 'proposed' AND checked = ''", $h, $layout, $f ) ); // a reviewed one stays
	}

	/** What an element reads on a few products' pages, those it reads something on first: { id, title, url, n, short, detail, urls }. */
	private static function readings( string $f, array $cand, array $pages ): array {
		$out = [];
		$nil = [];
		foreach ( $pages as $p ) {
			$html = PageStore::read( (string) $p['file'] );
			if ( $html === '' ) {
				continue;
			}
			$r     = VendorMaps::eval_field( $f, $cand['sel'], $cand['mode'], $html, (string) $p['url'] );
			$x = [
				'id'     => (int) $p['id'],
				'title'  => (string) $p['title'],
				'url'    => (string) $p['url'],
				'n'      => (int) ( $r['n'] ?? 0 ),
				'short'  => mb_substr( (string) ( $r['short'] ?? '' ), 0, 120 ),
				'detail' => mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $r['detail'] ?? '' ) ) ) ), 0, 300 ),
				'urls'   => array_slice( array_values( (array) ( $r['urls'] ?? [] ) ), 0, 6 ),
			];
			if ( $x['n'] > 0 ) {
				$out[] = $x;
			} else {
				$nil[] = $x;
			}
			if ( count( $out ) >= self::SAMPLES ) {
				break;
			}
		}
		return array_slice( array_merge( $out, $nil ), 0, self::SAMPLES );
	}

	/* ── Reading, reviewing, deciding ───────────────────────────────────── */

	/** @return array<int,array> a site's proposals (all layouts), or every site's when $host is '' */
	public static function rows( string $host = '', string $status = '' ): array {
		self::maybe_install();
		global $wpdb;
		$where = [ '1=1' ];
		$args  = [];
		if ( $host !== '' ) {
			$where[] = 'host = %s';
			$args[]  = VendorMaps::host( $host );
		}
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		$sql  = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY host, layout, FIELD(field, ' . implode( ',', array_map( static fn( $f ) => "'" . $f . "'", self::FIELDS ) ) . ')';
		return array_map( [ __CLASS__, 'rows_one' ], $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Parts waiting for a person, every site (Needs a decision's Map parts pill). */
	public static function waiting(): int {
		self::maybe_install();
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'proposed'" ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name, fixed value
	}

	/** The proposals a page's mapper inspects: per part, its layout's proposal, else the site's. */
	public static function for_page( string $host, ?int $layout ): array {
		$out = [];
		foreach ( self::rows( $host ) as $r ) {
			if ( $r['layout'] === self::SITE && ! isset( $out[ $r['field'] ] ) ) {
				$out[ $r['field'] ] = $r;
			} elseif ( $layout !== null && $r['layout'] === $layout ) {
				$out[ $r['field'] ] = $r; // a layout's own proposal is closer to this page
			}
		}
		return $out;
	}

	public static function get( int $id ): ?array {
		self::maybe_install();
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ? self::rows_one( $r ) : null;
	}

	private static function rows_one( array $r ): array {
		$r['id']      = (int) $r['id'];
		$r['layout']  = (int) $r['layout'];
		$r['found']   = (int) $r['found'];
		$r['of']      = (int) $r['of'];
		$r['samples'] = (array) json_decode( (string) $r['samples'], true );
		return $r;
	}

	/**
	 * The reviewer's change (Claude): a better element ($sel/$mode, tested again with its readings) and a note.
	 * @return array|\WP_Error the proposal
	 */
	public static function review( int $id, string $note, string $sel = '', string $mode = '' ) {
		global $wpdb;
		$p = self::get( $id );
		if ( ! $p ) {
			return new \WP_Error( 'mmpt_proposal', 'No such proposal.' );
		}
		$set = [ 'note' => mb_substr( $note, 0, 600 ), 'checked' => 'claude' ];
		if ( $sel !== '' ) {
			$mode = in_array( $mode, VendorMaps::MODES[ $p['field'] ] ?? [], true ) ? $mode : $p['mode'];
			$only = $p['layout'] === self::SITE ? null : array_fill_keys( (array) ( MapTypes::layout_pages( $p['host'] )[ $p['layout'] ]['urls'] ?? [] ), 1 );
			$t    = VendorMaps::test( $p['host'], $p['field'], $sel, $mode, VendorMaps::TEST_MAX, '', '', $only );
			$pg   = self::readable( VendorMaps::pages( $p['host'] ) );
			if ( $only !== null ) {
				$pg = array_values( array_filter( $pg, static fn( $x ) => isset( $only[ $x['url'] ] ) ) );
			}
			$set += [ 'sel' => $sel, 'mode' => $mode, 'found' => (int) $t['found'], 'of' => (int) $t['of'], 'samples' => wp_json_encode( self::readings( $p['field'], [ 'sel' => $sel, 'mode' => $mode ], self::spread( $pg, self::LOOK ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'status' => 'proposed' ];
		}
		$wpdb->update( self::table(), $set, [ 'id' => $id ] );
		return self::get( $id );
	}

	/**
	 * The reviewer's own proposal for a part the finder missed (or got wrong at the layout's level): tested on the
	 * site's or the layout's pages, with its readings and a note. @return array|\WP_Error the proposal
	 */
	public static function add( string $host, int $layout, string $field, string $sel, string $mode, string $note ) {
		self::maybe_install();
		$h = VendorMaps::host( $host );
		if ( ! in_array( $field, self::FIELDS, true ) ) {
			return new \WP_Error( 'mmpt_proposal', 'Unknown part.' );
		}
		$mode = in_array( $mode, VendorMaps::MODES[ $field ], true ) ? $mode : VendorMaps::MODES[ $field ][0];
		$only = $layout === self::SITE ? null : array_fill_keys( (array) ( MapTypes::layout_pages( $h )[ $layout ]['urls'] ?? [] ), 1 );
		$t    = VendorMaps::test( $h, $field, $sel, $mode, VendorMaps::TEST_MAX, '', '', $only );
		$pg   = self::readable( VendorMaps::pages( $h ) );
		if ( $only !== null ) {
			$pg = array_values( array_filter( $pg, static fn( $x ) => isset( $only[ $x['url'] ] ) ) );
		}
		self::keep( $h, $layout, $field, [ 'sel' => $sel, 'mode' => $mode, 'where' => 'Proposed by Claude on review' ], $t, self::spread( $pg, self::LOOK ) );
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE host = %s AND layout = %d AND field = %s', $h, $layout, $field ) );
		return $id ? self::review( $id, $note ) : new \WP_Error( 'mmpt_proposal', 'Not kept (that part was decided already).' );
	}

	/**
	 * A person's decision. Approve saves the element as the site's part (or the layout's own) through
	 * VendorMaps::set_field(): tested on the stored pages, refused when it finds nothing. @return array|\WP_Error
	 */
	public static function decide( int $id, bool $approve, int $user ) {
		global $wpdb;
		$p = self::get( $id );
		if ( ! $p ) {
			return new \WP_Error( 'mmpt_proposal', 'That proposal is gone (the site’s proposals were made again).' );
		}
		if ( $approve ) {
			$res = VendorMaps::set_field( $p['host'], $p['field'], $p['sel'], $p['mode'], (string) $p['where_text'], '', '', 'proposal', $p['layout'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$wpdb->update( self::table(), [ 'status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user, 'decided_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', $approve ? 'vendor_maps.proposal_approve' : 'vendor_maps.proposal_reject', [ 'object_type' => 'vendor_map', 'object_id' => $p['host'], 'details' => [ 'field' => $p['field'], 'layout' => $p['layout'], 'proposal' => $id ] ] );
		}
		return self::get( $id );
	}

	/** A proposal as the extension's mapper and the Product Data page show it. */
	public static function out( array $p ): array {
		return [
			'id'      => $p['id'],
			'field'   => (string) $p['field'],
			'layout'  => $p['layout'],
			'scope'   => $p['layout'] === self::SITE ? 'site' : 'layout',
			'sel'     => (string) $p['sel'],
			'mode'    => (string) $p['mode'],
			'found'   => $p['found'],
			'of'      => $p['of'],
			'note'    => (string) $p['note'],
			'checked' => (string) $p['checked'],
			'status'  => (string) $p['status'],
			'samples' => $p['samples'],
		];
	}
}
