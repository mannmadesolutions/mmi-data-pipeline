<?php
/**
 * A person's say over product pictures (owner 2026-10-30): "some images may pass the code/logic test, but upon human
 * inspection, they fail … veto images individually and/or in bulk (identifiable from product to product) as well as
 * designate the featured image."
 *
 * Two kinds of choice, each for one product or for every product of a maker's site:
 *
 *   veto     — the picture never goes on the product: a page picture isn't added, a picture the product has is
 *              taken off when its pictures are next written (released to quarantine like any replaced picture, so
 *              Put back returns it).
 *   featured — the picture leads the product's set (its featured image) whenever the product has it.
 *
 * A choice names its picture by attachment, by address and by fingerprint (PictureMatch: Image Trim's hash of the
 * picture inside its plain borders), so the same picture is recognised on every product, whether it is one shared
 * attachment, a separate copy, or a page's picture at another size. PictureMatch::merge() applies the choices, so the
 * review, the editor's preview and the picture runner all decide the same way; the runner writes them.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PictureChoices {

	const DB_OPTION = 'mmpt_picture_choices_db_version';
	const DB_VERSION = '1';
	const KINDS     = [ 'veto', 'featured' ];
	const BUDGET_S  = 20;   // seconds one request of a site's pictures may spend; the screen asks again for the rest
	const MAX_IDS   = 600;

	/** @var array<string,array> rules per "product|host", for one request */
	private static array $cache = [];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_picture_choices';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(10) NOT NULL DEFAULT 'veto',
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  host varchar(190) NOT NULL DEFAULT '',
  att bigint(20) unsigned NOT NULL DEFAULT 0,
  url varchar(500) NOT NULL DEFAULT '',
  fkey char(32) NOT NULL DEFAULT '',
  fp text,
  by_user bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY product (product_id, kind),
  KEY host (host, kind)
) " . $wpdb->get_charset_collate() . ';' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	public static function register_hooks(): void {
		self::maybe_install();
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	/** The maker's site a product's pictures come from (its vendor page's host), or ''. */
	public static function host_of( int $id ): string {
		$url = (string) get_post_meta( $id, 'vendor_product_url', true );
		return preg_match( '#^https?://#i', $url ) ? VendorMaps::host( $url ) : '';
	}

	/** The choices that reach product $id: its own, then its site's; newest first within each. @return array[] */
	public static function for_product( int $id, string $host = '' ): array {
		$host = $host !== '' ? VendorMaps::host( $host ) : self::host_of( $id );
		$ck   = $id . '|' . $host;
		if ( ! isset( self::$cache[ $ck ] ) ) {
			self::maybe_install();
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE product_id = %d OR ( product_id = 0 AND host = %s AND host <> '' ) ORDER BY product_id = 0, id DESC", $id, $host ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name only
			self::$cache[ $ck ] = array_map( [ __CLASS__, 'decode' ], (array) $rows );
		}
		return self::$cache[ $ck ];
	}

	private static function decode( array $r ): array {
		$fp = json_decode( (string) $r['fp'], true );
		return [
			'id'      => (int) $r['id'],
			'kind'    => (string) $r['kind'],
			'scope'   => (int) $r['product_id'] ? 'product' : 'site',
			'product' => (int) $r['product_id'],
			'host'    => (string) $r['host'],
			'att'     => (int) $r['att'],
			'url'     => (string) $r['url'],
			'fkey'    => (string) $r['fkey'],
			'fp'      => is_array( $fp ) && isset( $fp['phash'] ) ? $fp : null,
		];
	}

	/**
	 * The first choice of $kind that names this picture, or null. A picture is named by its attachment, its address,
	 * or a fingerprint PictureMatch calls the same picture.
	 *
	 * @param array $pic [ att?:int, url?:string, fp?:array|null ]
	 */
	public static function match( array $rules, array $pic, string $kind ): ?array {
		foreach ( $rules as $r ) {
			if ( $r['kind'] !== $kind ) {
				continue;
			}
			if ( ( $r['att'] && (int) ( $pic['att'] ?? 0 ) === $r['att'] ) || ( $r['url'] !== '' && (string) ( $pic['url'] ?? '' ) === $r['url'] ) ) {
				return $r;
			}
			$fp = $pic['fp'] ?? null;
			if ( $r['fp'] && $fp && ( $r['fkey'] === ( $fp['key'] ?? '' ) || PictureMatch::relation( $r['fp'], $fp )[0] === 'same' ) ) {
				return $r;
			}
		}
		return null;
	}

	/** A picture as the screens send it: its attachment or its address, with its fingerprint. */
	private static function picture( int $att, string $url ): ?array {
		if ( $att > 0 && get_post_type( $att ) === 'attachment' ) {
			return [ 'att' => $att, 'url' => '', 'fp' => PictureMatch::of_attachment( $att ) ];
		}
		if ( $url !== '' && wp_http_validate_url( $url ) ) {
			return [ 'att' => 0, 'url' => esc_url_raw( $url ), 'fp' => PictureMatch::of_url( $url ) ];
		}
		return null;
	}

	/**
	 * Record a choice. A product's own choice of the same kind for the same picture is replaced, and a product has one
	 * featured picture: its earlier featured choice goes.
	 *
	 * @return array{id:int,kind:string,scope:string}|\WP_Error
	 */
	public static function add( string $kind, int $product, string $host, int $att, string $url ) {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error( 'mmpt_choice_kind', 'Unknown choice.' );
		}
		$host = VendorMaps::host( $host );
		if ( ! $product && $host === '' ) {
			return new \WP_Error( 'mmpt_choice_scope', 'A choice for every product needs the site.' );
		}
		if ( $product && get_post_type( $product ) !== 'product' ) {
			return new \WP_Error( 'mmpt_choice_product', 'Not a product.' );
		}
		$pic = self::picture( $att, $url );
		if ( ! $pic ) {
			return new \WP_Error( 'mmpt_choice_picture', 'That picture can’t be read.' );
		}
		self::maybe_install();
		global $wpdb;
		$t   = self::table();
		$old = array_filter(
			array_map( [ __CLASS__, 'decode' ], (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE product_id = %d AND host = %s AND kind = %s", $product, $host, $kind ), ARRAY_A ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			static fn( $r ) => ( $kind === 'featured' && $product ) || self::match( [ $r ], $pic, $kind )
		);
		foreach ( $old as $r ) {
			$wpdb->delete( $t, [ 'id' => $r['id'] ] );
		}
		if ( $kind === 'veto' ) { // a vetoed picture isn't featured here any more
			self::drop_matching( 'featured', $product, $host, $pic );
		} else {
			self::drop_matching( 'veto', $product, $host, $pic );
		}
		$fp = $pic['fp'];
		$wpdb->insert(
			$t,
			[
				'kind'       => $kind,
				'product_id' => $product,
				'host'       => $host,
				'att'        => $pic['att'],
				'url'        => mb_substr( $pic['url'], 0, 500 ),
				'fkey'       => $fp ? (string) $fp['key'] : '',
				'fp'         => $fp ? wp_json_encode( array_intersect_key( $fp, array_flip( [ 'phash', 'thumb', 'content_w', 'content_h', 'key' ] ) ) ) : null,
				'by_user'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			]
		);
		self::$cache = [];
		return [ 'id' => (int) $wpdb->insert_id, 'kind' => $kind, 'scope' => $product ? 'product' : 'site', 'fingerprint' => (bool) $fp ];
	}

	/** Remove choices of $kind at this scope that name $pic. */
	private static function drop_matching( string $kind, int $product, string $host, array $pic ): void {
		global $wpdb;
		$t = self::table();
		foreach ( array_map( [ __CLASS__, 'decode' ], (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE product_id = %d AND host = %s AND kind = %s", $product, $host, $kind ), ARRAY_A ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( self::match( [ $r ], $pic, $kind ) ) {
				$wpdb->delete( $t, [ 'id' => $r['id'] ] );
			}
		}
	}

	/** Undo a choice. @return array|null the choice removed */
	public static function remove( int $rule ): ?array {
		self::maybe_install();
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $rule ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return null;
		}
		$wpdb->delete( self::table(), [ 'id' => $rule ] );
		self::$cache = [];
		return self::decode( $row );
	}

	/**
	 * Every distinct picture across these products of one site, as their merges have them (PictureMatch::merge(),
	 * choices applied), grouped by fingerprint: which products have it and what happens to it on each. Read in
	 * BUDGET_S slices: `next` is the first product not read yet, or null.
	 *
	 * @param int[] $ids
	 * @return array{groups:array,products:array,next:?int,of:int}
	 */
	public static function across( string $host, array $ids, int $from = 0, bool $review = false ): array { // $review: every product's pictures, as a person's review reads them
		$host  = VendorMaps::host( $host );
		$ids   = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, self::MAX_IDS );
		$start = microtime( true );
		$pics  = [];
		$per   = [];
		$next  = null;
		for ( $k = max( 0, $from ), $n = count( $ids ); $k < $n; $k++ ) {
			if ( $k > $from && microtime( true ) - $start > self::BUDGET_S ) {
				$next = $k;
				break;
			}
			$id   = $ids[ $k ];
			$plan = VendorMaps::with_editor_map( $host, static fn() => Pictures::plan( $id, $review ) ); // as the review's preview reads it
			if ( $plan['mode'] !== 'vendor' ) {
				$per[ $id ] = [];
				continue;
			}
			$m          = PictureMatch::merge( $plan['current'], $plan['urls'], false, $id, $host );
			$per[ $id ] = [];
			foreach ( array_merge( $m['current'], $m['page'] ) as $p ) {
				if ( empty( $p['key'] ) ) {
					continue; // no fingerprint: it can't be recognised elsewhere
				}
				$per[ $id ][] = [ 'key' => $p['key'], 'kind' => $p['kind'] ];
				$pics[ $p['key'] ] ??= [ 'fp' => $m['fps'][ $p['key'] ], 'url' => isset( $p['att'] ) ? (string) wp_get_attachment_image_url( (int) $p['att'], 'thumbnail' ) : (string) $p['url'], 'att' => (int) ( $p['att'] ?? 0 ), 'src' => (string) ( $p['url'] ?? '' ) ];
			}
		}
		// One group per picture: fingerprints PictureMatch calls the same share a group (first seen leads).
		$keys = array_keys( $pics );
		$lead = array_combine( $keys, $keys );
		foreach ( $keys as $i => $a ) {
			if ( $lead[ $a ] !== $a ) {
				continue;
			}
			foreach ( array_slice( $keys, $i + 1 ) as $b ) {
				if ( $lead[ $b ] === $b && PictureMatch::relation( $pics[ $a ]['fp'], $pics[ $b ]['fp'] )[0] === 'same' ) {
					$lead[ $b ] = $a;
				}
			}
		}
		$groups = [];
		foreach ( $per as $id => $list ) {
			foreach ( $list as $p ) {
				$g = $lead[ $p['key'] ];
				$groups[ $g ] ??= [ 'key' => $g, 'keys' => [], 'thumb' => $pics[ $g ]['url'], 'att' => $pics[ $g ]['att'], 'url' => $pics[ $g ]['src'], 'products' => [], 'kinds' => [] ];
				$groups[ $g ]['keys'][ $p['key'] ] = true;
				$groups[ $g ]['products'][ $id ]   = true;
				$groups[ $g ]['kinds'][ $p['kind'] ] = ( $groups[ $g ]['kinds'][ $p['kind'] ] ?? 0 ) + 1;
			}
		}
		$site = array_values( array_filter( self::for_product( 0, $host ), static fn( $r ) => $r['scope'] === 'site' ) );
		$out  = [];
		foreach ( $groups as $g ) {
			$fp    = $pics[ $g['key'] ]['fp'];
			$veto  = self::match( $site, [ 'att' => $g['att'], 'url' => $g['url'], 'fp' => $fp ], 'veto' );
			$feat  = self::match( $site, [ 'att' => $g['att'], 'url' => $g['url'], 'fp' => $fp ], 'featured' );
			$out[] = [
				'key'      => $g['key'],
				'keys'     => array_keys( $g['keys'] ),
				'thumb'    => $g['thumb'],
				'att'      => $g['att'],
				'url'      => $g['url'],
				'products' => array_keys( $g['products'] ),
				'kinds'    => $g['kinds'],
				'veto'     => $veto ? $veto['id'] : 0,
				'featured' => $feat ? $feat['id'] : 0,
			];
		}
		usort( $out, static fn( $a, $b ) => count( $b['products'] ) <=> count( $a['products'] ) );
		return [ 'groups' => $out, 'products' => array_map( static fn( $l ) => array_values( array_unique( array_column( $l, 'key' ) ) ), $per ), 'next' => $next, 'of' => count( $ids ) ];
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( Module::CAP );
		// Record a choice: { kind: veto|featured, scope: product|site, product, host, att | url }
		register_rest_route(
			Module::NS,
			'/pictures/choices',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$product = absint( $r->get_param( 'product' ) );
					$site    = $r->get_param( 'scope' ) === 'site';
					$host    = (string) $r->get_param( 'host' );
					$host    = $host !== '' ? $host : self::host_of( $product );
					$res     = self::add( sanitize_key( (string) $r->get_param( 'kind' ) ), $site ? 0 : $product, $host, absint( $r->get_param( 'att' ) ), (string) $r->get_param( 'url' ) );
					if ( is_wp_error( $res ) ) {
						return new \WP_REST_Response( [ 'message' => $res->get_error_message() ], 400 );
					}
					self::audit( 'product_pictures.choice', $site ? $host : (string) $product, $res + [ 'att' => absint( $r->get_param( 'att' ) ), 'url' => (string) $r->get_param( 'url' ) ] );
					return rest_ensure_response( $res );
				},
			]
		);
		register_rest_route(
			Module::NS,
			'/pictures/choices/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$gone = self::remove( (int) $r['id'] );
					if ( ! $gone ) {
						return new \WP_REST_Response( [ 'message' => 'That choice is already gone.' ], 404 );
					}
					self::audit( 'product_pictures.choice_undone', $gone['product'] ? (string) $gone['product'] : $gone['host'], array_diff_key( $gone, [ 'fp' => 1 ] ) );
					return rest_ensure_response( [ 'removed' => $gone['id'], 'kind' => $gone['kind'], 'scope' => $gone['scope'] ] );
				},
			]
		);
		// A site's pictures across these products, grouped by fingerprint (read in slices: ask again from `next`).
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/pictures',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$ids = array_map( 'absint', explode( ',', (string) $r->get_param( 'ids' ) ) );
					return rest_ensure_response( self::across( (string) $r['host'], $ids, absint( $r->get_param( 'from' ) ), (bool) $r->get_param( 'review' ) ) );
				},
			]
		);
	}

	private static function audit( string $action, string $object, array $details ): void {
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', $action, [ 'object_type' => 'product_pictures', 'object_id' => $object, 'details' => $details ] );
		}
	}
}
