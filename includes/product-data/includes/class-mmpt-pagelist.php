<?php
/**
 * Product pages: the products whose maker page needs a person's decision, a page at a time (Product Workbench →
 * Maker pages, and the old Product Data page's section 2). Moved out of the Product Data screen (OnePage) in phase 8
 * of the Product Data migration, so the list keeps working when that screen is gone; OnePage's routes call it.
 *
 * Groups: open (a proposed page waits), broken (its page is gone, moved or refused), none (no page and nothing
 * proposed), wrong (its address plainly isn't a product page: a login, home or support page), approved and rejected
 * (decisions taken), every (every product in scope). With no group, the four waiting groups together.
 *
 *   GET  /page-list?brand=&site=&q=&group=&conf=&result=&reason=&verified=&page=&per_page=   rows, counts, filters
 *   GET  /page-list?…&ids[]=                                                               those products only
 *   GET  /page-list?…&rows=0                                                               the counts only
 *   GET  /page-list?…&all=1                                                                every matching ID (≤ 5,000)
 *   POST /page-list/{id}/keep   a "not a product page" address is the product's page after all: kept
 *
 * Every other change goes through the routes the old screens used (UrlQueue's /urls/*, PageReview's /pages/queue).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageList {

	const PER_PAGE    = 50;
	const GROUPS      = [ 'open', 'broken', 'none', 'wrong', 'approved', 'rejected', 'every' ];
	/** The confidence filter: the rating badge's bands, and the rows not rated yet (UrlReview::rates()). */
	const CONF_BANDS  = [ 'high', 'mid', 'low', 'bad', 'unrated' ];
	const RATE_BUDGET = 2.5; // seconds a request spends rating; the rest is rated in the background

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( Module::CAP );
		register_rest_route( Module::NS, '/page-list', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'pages' ] ] );
		register_rest_route(
			Module::NS,
			'/page-list/(?P<id>\d+)/keep',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
				'callback'            => [ __CLASS__, 'keep' ],
			]
		);
	}

	/**
	 * POST /page-list/{id}/keep: a maker URL listed as not a product page (UrlFinder::not_product_why()) is the
	 * product's page after all (a one-product maker's home page): kept, so it stops waiting. A login, basket, account
	 * or registration page can't be kept. Setting another URL brings the check back.
	 */
	public static function keep( \WP_REST_Request $r ): \WP_REST_Response {
		$id  = (int) $r['id'];
		$url = (string) get_post_meta( $id, 'vendor_product_url', true );
		if ( get_post_type( $id ) !== 'product' || $url === '' ) {
			return new \WP_REST_Response( [ 'message' => 'This product has no maker page to keep.' ], 400 );
		}
		if ( UrlFinder::not_product_why( $url ) === 'account' ) {
			return new \WP_REST_Response( [ 'message' => 'A login, basket, account or registration page is never a product’s page.' ], 400 );
		}
		update_post_meta( $id, UrlFinder::PAGE_OK_META, $url );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.keep', [ 'object_type' => 'product', 'object_id' => $id, 'details' => [ 'host' => strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), 'why' => UrlFinder::not_product_why( $url ) ] ] );
		}
		return rest_ensure_response( [ 'kept' => $url ] );
	}

	/** GET /page-list (see the class doc). */
	public static function pages( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;
		$brand = absint( $r->get_param( 'brand' ) );
		$site  = VendorMaps::host( sanitize_text_field( (string) $r->get_param( 'site' ) ) );
		$group = in_array( (string) $r->get_param( 'group' ), self::GROUPS, true ) ? (string) $r->get_param( 'group' ) : '';
		$page  = max( 1, absint( $r->get_param( 'page' ) ) );
		$per   = max( 25, min( 200, absint( $r->get_param( 'per_page' ) ) ?: self::PER_PAGE ) );
		$only  = array_values( array_filter( array_map( 'absint', (array) $r->get_param( 'ids' ) ) ) );
		$q     = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		$ids   = self::scope( $brand, $site, $q );
		$open  = $ids ? self::col( 'SELECT product_id, 1 FROM ' . UrlFinder::table() . " WHERE status = 'candidate' AND product_id IN (%s)", $ids ) : [];
		$urls  = self::meta( $ids, 'vendor_product_url' );
		// A maker URL that plainly isn't the product's page (a login, support or home page), unless a person kept it
		// (owner 2026-10-27, after a registration form was read as a product page): waiting, as "Not a product page".
		$kept  = self::meta( $ids, UrlFinder::PAGE_OK_META );
		$wrong = [];
		foreach ( $urls as $id => $u ) {
			$w = str_starts_with( $u, 'http' ) && ! isset( $open[ $id ] ) && ( $kept[ $id ] ?? '' ) !== $u ? UrlFinder::not_product_why( $u ) : '';
			if ( $w !== '' ) {
				$wrong[ $id ] = $w;
			}
		}
		// An info or home address whose page rates UrlReview::SURE or more is the product's page by its own evidence
		// (owner 2026-10-27: a maker keeps a product's page on its blog, approved and rated 90, and it still waited).
		// A page not rated yet stays listed; a login, basket or account page always does.
		$rated = array_keys( array_filter( $wrong, static fn( $w ) => $w !== 'account' ) );
		if ( $rated ) {
			$n = UrlReview::rates( $rated, 1.0 )['n'];
			foreach ( $rated as $id ) {
				if ( ( $n[ $id ] ?? 0 ) >= UrlReview::SURE ) {
					unset( $wrong[ $id ] );
				}
			}
		}
		$hash = [];
		foreach ( $urls as $id => $u ) {
			if ( str_starts_with( $u, 'http' ) && ! isset( $open[ $id ] ) && ! isset( $wrong[ $id ] ) ) {
				$hash[ md5( $u ) ][] = $id;
			}
		}
		$broken = [];
		foreach ( array_chunk( array_keys( $hash ), 1000 ) as $chunk ) {
			foreach ( $wpdb->get_results( 'SELECT url_hash FROM ' . PageStore::table() . " WHERE status IN ('" . implode( "','", SiteGaps::BROKEN ) . "') AND url_hash IN ('" . implode( "','", $chunk ) . "')", ARRAY_A ) as $pg ) { // phpcs:ignore WordPress.DB.PreparedSQL -- constants and md5 hex only
				foreach ( $hash[ $pg['url_hash'] ] as $id ) {
					$broken[ $id ] = true;
				}
			}
		}
		$no_page = UrlFinder::no_page_ids();
		$none    = [];
		foreach ( $ids as $id ) {
			if ( ! str_starts_with( (string) ( $urls[ $id ] ?? '' ), 'http' ) && ! isset( $open[ $id ] ) && ! isset( $no_page[ $id ] ) ) {
				$none[ $id ] = true;
			}
		}
		$decided = $ids ? self::col( 'SELECT product_id, status FROM ' . UrlFinder::table() . " WHERE status IN ('approved','rejected') AND product_id IN (%s)", $ids ) : [];
		$sets    = [
			'open'     => array_keys( $open ),
			'broken'   => array_keys( $broken ),
			'none'     => array_keys( $none ),
			'wrong'    => array_keys( $wrong ),
			'approved' => array_keys( array_filter( $decided, static fn( $s ) => $s === 'approved' ) ),
			'rejected' => array_keys( array_filter( $decided, static fn( $s ) => $s === 'rejected' ) ),
			'every'    => $ids,
		];
		$waiting = array_merge( $sets['open'], $sets['broken'], $sets['none'], $sets['wrong'] );
		if ( $group === '' && ! $waiting && $q !== '' && $ids ) {
			$group = 'every'; // a search that finds products none of which waits: the products themselves, to set their URL
		}
		$list    = $group ? $sets[ $group ] : $waiting;
		$why     = in_array( $group, [ 'approved', 'rejected', 'every' ], true ) ? array_fill_keys( $sets[ $group ], $group ) : array_fill_keys( $sets['open'], 'open' ) + array_fill_keys( $sets['broken'], 'broken' ) + array_fill_keys( $sets['none'], 'none' ) + array_fill_keys( $sets['wrong'], 'wrong' );
		$counts  = array_map( 'count', $sets );
		if ( ! $brand && $site === '' && $q === '' ) { // every product: the side menu's Maker pages badge (WorkbenchCounts)
			WorkbenchCounts::store( $counts['open'] + $counts['broken'] + $counts['none'] + $counts['wrong'] );
		}
		$results = PageReview::result_counts( $list ); // the result filter's counts, over the group shown
		$reasons = [];
		foreach ( $sets['open'] ? array_count_values( self::col( 'SELECT product_id, reason FROM ' . UrlFinder::table() . " WHERE status = 'candidate' AND product_id IN (%s)", $sets['open'] ) ) : [] as $k => $n ) {
			$reasons[] = [ 'key' => (string) $k, 'label' => UrlQueue::REASONS[ $k ] ?? (string) $k, 'n' => $n ];
		}
		usort( $reasons, static fn( $a, $b ) => $b['n'] <=> $a['n'] );
		if ( array_filter( [ $r->get_param( 'result' ), $r->get_param( 'reason' ), $r->get_param( 'verified' ), $r->get_param( 'pictures' ), $r->get_param( 'description' ) ] ) ) {
			$list = PageReview::matching( $list, $r );
			$why  = array_intersect_key( $why, array_flip( $list ) ); // after an action a row stays only while it still passes them
		}
		$total = count( $list );
		$conf  = in_array( (string) $r->get_param( 'conf' ), self::CONF_BANDS, true ) ? (string) $r->get_param( 'conf' ) : '';
		// Rated: the waiting groups (proposals and pages to decide); the history and every product aren't
		$rated = in_array( $group, [ 'approved', 'rejected', 'every' ], true ) ? [ 'n' => [], 'unrated' => [] ] : UrlReview::rates( $list, self::RATE_BUDGET );
		$waits = array_flip( $rated['unrated'] );
		$band  = static fn( int $n ): string => $n >= 85 ? 'high' : ( $n >= 70 ? 'mid' : ( $n >= 55 ? 'low' : 'bad' ) );
		$cc    = array_fill_keys( self::CONF_BANDS, 0 );
		foreach ( $list as $id ) {
			if ( isset( $rated['n'][ $id ] ) ) {
				$cc[ $band( $rated['n'][ $id ] ) ]++;
			} elseif ( isset( $waits[ $id ] ) ) {
				$cc['unrated']++;
			}
		}
		if ( $rated['unrated'] ) {
			UrlReview::rate_later( $rated['unrated'] );
		}
		if ( $conf !== '' ) {
			$list  = array_values( array_filter( $list, static fn( $id ) => $conf === 'unrated' ? isset( $waits[ $id ] ) : ( isset( $rated['n'][ $id ] ) && $band( $rated['n'][ $id ] ) === $conf ) ) );
			$total = count( $list );
		}
		if ( $r->get_param( 'all' ) ) { // a bulk action on every row matching
			return rest_ensure_response( [ 'ids' => array_slice( array_map( 'intval', $list ), 0, 5000 ), 'total' => $total ] );
		}
		$slice = $only ? array_values( array_filter( $only, static fn( $id ) => isset( $why[ $id ] ) ) ) : array_slice( $list, ( $page - 1 ) * $per, $per );
		$rows  = $slice && (string) $r->get_param( 'rows' ) !== '0' ? PageReview::rows_for( $slice ) : [];
		foreach ( $rows as $i => $x ) {
			$rows[ $i ]['op_group']      = $why[ (int) $x['id'] ] ?? '';
			$rows[ $i ]['op_conf']       = $rated['n'][ (int) $x['id'] ] ?? null;
			$rows[ $i ]['op_wrong']      = $wrong[ (int) $x['id'] ] ?? '';
			$rows[ $i ]['op_wrong_note'] = isset( $wrong[ (int) $x['id'] ] ) ? UrlFinder::NOT_PRODUCT_WHY[ $wrong[ (int) $x['id'] ] ] : '';
		}
		return rest_ensure_response(
			[
				'rows'        => $rows,
				'total'       => $total,
				'page'        => $page,
				'pages'       => max( 1, (int) ceil( $total / $per ) ),
				'counts'      => $counts,
				'conf_counts' => $cc,
				'group'       => $group,
				'conf'        => $conf,
				'reasons'     => $reasons,
				'results'     => array_map( static fn( $k ) => [ 'key' => $k, 'label' => PageReview::RESULTS[ $k ][0], 'n' => $results[ $k ] ?? 0 ], array_keys( PageReview::RESULTS ) ),
			]
		);
	}

	/* ── Scope ─────────────────────────────────────────────────────────── */

	/**
	 * A request's scope (brand, site, search) for another route's list: null when it names none (every product),
	 * else the IDs (possibly none).
	 * @return int[]|null
	 */
	public static function scope_of( \WP_REST_Request $r ): ?array {
		$brand = absint( $r->get_param( 'brand' ) );
		$site  = VendorMaps::host( sanitize_text_field( (string) $r->get_param( 'site' ) ) );
		$q     = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		return $brand || $site !== '' || $q !== '' ? self::scope( $brand, $site, $q ) : null;
	}

	/**
	 * The products in scope: of these WooCommerce statuses (published unless a caller asks for others), not
	 * excluded, of the brand (0 = every brand), with their page on the site ('' = any), matching the search (an ID,
	 * a SKU, or words of the title).
	 * @param string[] $statuses
	 * @return int[]
	 */
	public static function scope( int $brand, string $site, string $q, array $statuses = [ 'publish' ] ): array {
		global $wpdb;
		$join  = '';
		$where = "p.post_type = 'product' AND p.post_status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')";
		$args  = [];
		if ( $brand ) {
			$join  .= " JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' AND tt.term_id = %d";
			$args[] = $brand;
		}
		if ( $site !== '' ) {
			$join  .= " JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url' AND ( u.meta_value LIKE %s OR u.meta_value LIKE %s )";
			$args[] = '%://' . $wpdb->esc_like( $site ) . '/%';
			$args[] = '%://www.' . $wpdb->esc_like( $site ) . '/%';
		}
		if ( $q !== '' ) {
			if ( ctype_digit( $q ) ) {
				$where .= " AND ( p.ID = %d OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = '_sku' AND s.meta_value = %s ) )";
				$args[] = (int) $q;
				$args[] = $q;
			} else {
				$where .= " AND ( p.post_title LIKE %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = '_sku' AND s.meta_value = %s ) )";
				$args[] = '%' . $wpdb->esc_like( $q ) . '%';
				$args[] = $q;
			}
		}
		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p{$join} WHERE {$where} ORDER BY p.ID";
		$ids = array_map( 'intval', $wpdb->get_col( $args ? $wpdb->prepare( $sql, $args ) : $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $site !== '' ) {
			// LIKE matches a sub-domain's address too; keep the products whose page is on exactly this site.
			$urls = self::meta( $ids, 'vendor_product_url' );
			$ids  = array_values( array_filter( $ids, static fn( $id ) => VendorMaps::host( (string) ( $urls[ $id ] ?? '' ) ) === $site ) );
		}
		$skip = array_flip( Exclusions::ids() );
		return array_values( array_filter( $ids, static fn( $id ) => ! isset( $skip[ $id ] ) ) );
	}

	/** One meta value per product. @return array<int,string> */
	public static function meta( array $ids, string $key ): array {
		global $wpdb;
		$out = [];
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')', $key ), ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
				$out[ (int) $m['post_id'] ] = (string) $m['meta_value'];
			}
		}
		return $out;
	}

	/** Product IDs from one column of a module table, limited to these products. @return array<int,string> id → value */
	public static function col( string $sql, array $ids ): array {
		global $wpdb;
		$out = [];
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			foreach ( $wpdb->get_results( sprintf( $sql, implode( ',', array_map( 'intval', $chunk ) ) ), ARRAY_N ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
				$out[ (int) $r[0] ] = (string) ( $r[1] ?? '' );
			}
		}
		return $out;
	}
}
