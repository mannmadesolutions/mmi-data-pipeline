<?php
/**
 * Needs a decision: every product something waits on, across the Workbench's queues, in one list (Product
 * Workbench → Needs a decision), and everything the product drawer shows about one product.
 *
 *   GET /waiting?kind=&q=&page=&per_page=&orderby=&order=   a page of products with what waits on each, counts per kind
 *   GET /products/{id}/workbench                          one product for the drawer: header, issues, title, terms
 *
 * What waits on a product (its keys):
 *   title          an open title proposal (proposed, stale, error)
 *   description    a description proposal (Enrichment, content)
 *   pictures       a picture proposal (Enrichment, image)
 *   page           its maker page waits (Maker pages' four waiting groups; WorkbenchCounts keeps their ids)
 *   no_brand, no_category, no_sku   a gap the title rules need, read live (title rows only, as Title proposals)
 *   flag:{name}    a flag on a written title the store says still holds (filter mmi_pipeline_waiting_flags):
 *                  stored flags on written titles aren't recomputed, so only a live check may list them
 *   picture_error  the picture runner failed on it in its last run
 *
 * Products Product Data leaves alone (Exclusions, the not-sold rule) are never listed. Order: errors first (a
 * failed picture download, no brand), then the most things waiting, then the newest product.
 *
 * (`/decide` is the title queue's POST route, so the list has its own path.)
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waiting {

	const PER_PAGE = 25;
	/** The list's chips → the keys each shows ('' = every product listed). */
	const KINDS = [
		''               => [],
		'titles'         => [ 'title' ],
		'descriptions'   => [ 'description' ],
		'pictures'       => [ 'pictures' ],
		'pages'          => [ 'page' ],
		'data'           => [ 'no_brand', 'no_category', 'no_sku', 'flag:' ],
		'picture_errors' => [ 'picture_error' ],
	];
	/** Keys that put a product at the top. */
	const ERRORS = [ 'picture_error', 'no_brand' ];
	/** How each key reads as a badge: label, tone. */
	const LABELS = [
		'title'         => [ 'Title', 'success' ],
		'description'   => [ 'Description', 'info' ],
		'pictures'      => [ 'Pictures', 'info' ],
		'page'          => [ 'Maker page', 'info' ],
		'no_brand'      => [ 'No brand', 'error' ],
		'no_category'   => [ 'No category', 'error' ],
		'no_sku'        => [ 'No SKU', 'warning' ],
		'picture_error' => [ 'Pictures failed', 'error' ],
	];
	const TOTAL_CACHE = 'mmpt_wb_waiting_total';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( Rest::CAP );
		register_rest_route( Rest::NS, '/waiting', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'list' ] ] );
		register_rest_route(
			Rest::NS,
			'/products/(?P<id>\d+)/workbench',
			[ 'methods' => 'GET', 'permission_callback' => $admin, 'args' => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ], 'callback' => [ __CLASS__, 'product' ] ]
		);
	}

	/* ── What waits on what ───────────────────────────────────────────────── */

	/**
	 * Product id → its waiting keys, for every product (null) or the given ones.
	 *
	 * @param int[]|null $only
	 * @return array<int,string[]>
	 */
	public static function map( ?array $only = null ): array {
		global $wpdb;
		$m    = [];
		$add  = static function ( $id, string $key ) use ( &$m ) {
			$m[ (int) $id ][ $key ] = true;
		};
		$only = $only === null ? null : array_values( array_filter( array_map( 'intval', $only ) ) );
		if ( $only === [] ) {
			return [];
		}
		$in = static fn( string $col ) => $only === null ? '' : " AND {$col} IN (" . implode( ',', $only ) . ')';
		$t  = Store::table();

		foreach ( $wpdb->get_col( "SELECT product_id FROM {$t} WHERE status IN ('proposed','stale','error')" . $in( 'product_id' ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL -- table, fixed values and ints
			$add( $id, 'title' );
		}
		// Gaps the title rules need, on products they compose (Title proposals' own scope); one grouped join each.
		$def = (int) get_option( 'default_product_cat', 0 );
		foreach ( $wpdb->get_results(
			"SELECT s.product_id id, SUM(tt.taxonomy = 'product_brand') hb, SUM(tt.taxonomy = 'product_cat' AND tt.term_id <> {$def}) hc
			 FROM {$t} s LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = s.product_id
			 LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ('product_brand','product_cat')
			 WHERE 1=1" . $in( 's.product_id' ) . '
			 GROUP BY s.product_id HAVING NOT hb OR NOT hc OR hb IS NULL OR hc IS NULL',
			ARRAY_A
		) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! (int) $r['hb'] ) {
				$add( $r['id'], 'no_brand' );
			}
			if ( ! (int) $r['hc'] ) {
				$add( $r['id'], 'no_category' );
			}
		}
		// WooCommerce's lookup table holds the SKU indexed (postmeta has no key+value index: 0.7 s against 30 ms).
		foreach ( $wpdb->get_col( "SELECT s.product_id FROM {$t} s LEFT JOIN {$wpdb->wc_product_meta_lookup} l ON l.product_id = s.product_id WHERE ( l.product_id IS NULL OR l.sku IS NULL OR l.sku = '' )" . $in( 's.product_id' ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$add( $id, 'no_sku' );
		}
		foreach ( (array) apply_filters( 'mmi_pipeline_waiting_flags', [], $only ) as $id => $flags ) {
			foreach ( (array) $flags as $flag => $detail ) {
				$add( $id, 'flag:' . $flag );
			}
		}
		foreach ( $wpdb->get_results( 'SELECT product_id, kind FROM ' . Enrichment::table() . " WHERE status = 'proposed'" . $in( 'product_id' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$add( $r['product_id'], $r['kind'] === 'image' ? 'pictures' : 'description' );
		}
		$pages = WorkbenchCounts::page_ids();
		foreach ( $only === null ? $pages : array_intersect( $pages, $only ) as $id ) {
			$add( $id, 'page' );
		}
		Pictures::maybe_install();
		$since = (string) ( Module::state( Pictures::STATE )['started_at'] ?? '' );
		if ( $since !== '' ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM ' . Pictures::table() . " WHERE status = 'error' AND run_at >= %s", $since ) . $in( 'product_id' ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$add( $id, 'picture_error' );
			}
		}
		foreach ( Exclusions::ids() as $id ) {
			unset( $m[ $id ] );
		}
		$out = array_map( static fn( $keys ) => array_keys( $keys ), $m );
		if ( $only === null ) {
			set_transient( self::TOTAL_CACHE, count( $out ), 5 * MINUTE_IN_SECONDS );
		}
		return $out;
	}

	/** The menu's badge: the last full count (≤ 5 minutes old), worked out now when there is none. */
	public static function total(): int {
		$n = get_transient( self::TOTAL_CACHE );
		return $n === false ? count( self::map() ) : (int) $n;
	}

	/** Does a product's key list match a chip? */
	private static function matches( array $keys, string $kind ): bool {
		$want = self::KINDS[ $kind ] ?? [];
		if ( ! $want ) {
			return true;
		}
		foreach ( $keys as $k ) {
			foreach ( $want as $w ) {
				if ( $k === $w || ( str_ends_with( $w, ':' ) && str_starts_with( $k, $w ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ── GET /waiting ─────────────────────────────────────────────────────── */

	public static function list( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;
		$kind  = array_key_exists( (string) $r->get_param( 'kind' ), self::KINDS ) ? (string) $r->get_param( 'kind' ) : '';
		$q     = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		$page  = max( 1, absint( $r->get_param( 'page' ) ) );
		$per   = max( 10, min( 100, absint( $r->get_param( 'per_page' ) ) ?: self::PER_PAGE ) );
		$order = strtolower( (string) $r->get_param( 'order' ) ) === 'asc' ? 'asc' : 'desc';
		$by    = in_array( (string) $r->get_param( 'orderby' ), [ 'id', 'title', 'brand', 'waiting' ], true ) ? (string) $r->get_param( 'orderby' ) : '';

		$map = self::map();
		if ( $q !== '' && $map ) { // title, SKU or ID, among the products listed
			$ids  = implode( ',', array_keys( $map ) );
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$hit  = array_flip( array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->wc_product_meta_lookup} l ON l.product_id = p.ID
				 WHERE p.ID IN ({$ids}) AND ( p.ID = %d OR p.post_title LIKE %s OR l.sku LIKE %s )", // phpcs:ignore WordPress.DB.PreparedSQL -- ints
				ctype_digit( $q ) ? (int) $q : 0,
				$like,
				$like
			) ) ) );
			$map = array_intersect_key( $map, $hit );
		}
		$counts = array_fill_keys( array_keys( self::KINDS ), 0 );
		foreach ( $map as $keys ) {
			foreach ( array_keys( self::KINDS ) as $k ) {
				if ( self::matches( $keys, $k ) ) {
					$counts[ $k ]++;
				}
			}
		}
		$counts['all'] = $counts[''];
		unset( $counts[''] );
		$map = array_filter( $map, static fn( $keys ) => self::matches( $keys, $kind ) );

		// Sort keys for every listed product, then one page of rows.
		$ids   = array_keys( $map );
		$dates = $titles = $brands = [];
		if ( $ids ) {
			$in = implode( ',', $ids );
			foreach ( $wpdb->get_results( "SELECT ID, post_date, post_title FROM {$wpdb->posts} WHERE ID IN ({$in})", ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$dates[ (int) $p['ID'] ]  = $p['post_date'];
				$titles[ (int) $p['ID'] ] = $p['post_title'];
			}
			if ( $by === 'brand' ) {
				foreach ( $wpdb->get_results( "SELECT tr.object_id id, MIN(t.name) name FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN ({$in}) GROUP BY tr.object_id", ARRAY_A ) as $b ) { // phpcs:ignore WordPress.DB.PreparedSQL
					$brands[ (int) $b['id'] ] = $b['name'];
				}
			}
		}
		$err = static fn( int $id ) => (int) (bool) array_intersect( $map[ $id ], self::ERRORS );
		usort(
			$ids,
			static function ( $a, $b ) use ( $by, $order, $map, $dates, $titles, $brands, $err ) {
				$dir = $order === 'asc' ? 1 : -1;
				switch ( $by ) {
					case 'id':
						return ( $a <=> $b ) * $dir;
					case 'title':
						return strcasecmp( $titles[ $a ] ?? '', $titles[ $b ] ?? '' ) * $dir ?: $a <=> $b;
					case 'brand': // no brand last either way
						return ( ( $brands[ $a ] ?? '' ) === '' ) <=> ( ( $brands[ $b ] ?? '' ) === '' ) ?: strcasecmp( $brands[ $a ] ?? '', $brands[ $b ] ?? '' ) * $dir ?: $a <=> $b;
					case 'waiting':
						return ( count( $map[ $a ] ) <=> count( $map[ $b ] ) ) * $dir ?: $b <=> $a;
				}
				return $err( $b ) <=> $err( $a ) ?: count( $map[ $b ] ) <=> count( $map[ $a ] ) ?: strcmp( $dates[ $b ] ?? '', $dates[ $a ] ?? '' ) ?: $b <=> $a;
			}
		);
		$total = count( $ids );
		$slice = array_slice( $ids, ( $page - 1 ) * $per, $per );
		return rest_ensure_response(
			[
				'rows'   => self::rows( $slice, $map ),
				'total'  => $total,
				'pages'  => max( 1, (int) ceil( $total / $per ) ),
				'page'   => $page,
				'counts' => $counts,
			]
		);
	}

	/** Rows for the table: product, terms, SKU, and what waits as badges. */
	private static function rows( array $ids, array $map ): array {
		if ( ! $ids ) {
			return [];
		}
		_prime_post_caches( $ids, true, false );
		$terms = Store::row_terms( $ids );
		$out   = [];
		foreach ( $ids as $id ) {
			$out[] = [
				'id'       => $id,
				'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'status'   => (string) get_post_status( $id ),
				'sku'      => (string) get_post_meta( $id, '_sku', true ),
				'cats'     => $terms['cats'][ $id ] ?? [],
				'brands'   => $terms['brands'][ $id ] ?? [],
				'waiting'  => self::badges( $id, $map[ $id ] ),
			];
		}
		return $out;
	}

	/** A product's waiting keys as badges, errors first. @return array<int,array{key:string,label:string,tone:string}> */
	public static function badges( int $id, array $keys ): array {
		$out = [];
		foreach ( $keys as $k ) {
			if ( str_starts_with( $k, 'flag:' ) ) {
				$i     = Issues::describe_flag( substr( $k, 5 ), '', $id );
				$out[] = [ 'key' => $k, 'label' => (string) $i['label'], 'tone' => (string) ( $i['tone'] ?? 'warning' ) ];
			} elseif ( isset( self::LABELS[ $k ] ) ) {
				$out[] = [ 'key' => $k, 'label' => self::LABELS[ $k ][0], 'tone' => self::LABELS[ $k ][1] ];
			}
		}
		$rank = [ 'error' => 0, 'warning' => 1, 'success' => 2, 'info' => 3 ];
		usort( $out, static fn( $a, $b ) => ( $rank[ $a['tone'] ] ?? 4 ) <=> ( $rank[ $b['tone'] ] ?? 4 ) );
		return $out;
	}

	/* ── GET /products/{id}/workbench ─────────────────────────────────────── */

	/** Everything the drawer shows about one product, through the shapers the sections use. */
	public static function product( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( get_post_type( $id ) !== 'product' ) {
			return new \WP_Error( 'mmpt_not_found', __( 'There is no such product.', 'mmi-data-pipeline' ), [ 'status' => 404 ] );
		}
		$keys    = self::map( [ $id ] )[ $id ] ?? [];
		$missing = Store::missing_for( [ $id ] );
		$row     = Store::get( $id );
		$terms   = Store::row_terms( [ $id ] );
		$title   = $row ? Rest::shape( $row, Store::stock_statuses( [ $id ] ), $terms, $missing ) : null;
		$open    = $title && in_array( $title['status'], [ 'proposed', 'stale', 'error' ], true );
		// An open proposal's issues are its own (its flags were composed now); a written title's stored flags may be
		// out of date, so only live gaps and the flags the store confirms (mmi_pipeline_waiting_flags) are shown.
		$issues = $open ? $title['issues'] : Issues::for_missing( $missing[ $id ] ?? [] );
		if ( ! $open ) {
			foreach ( (array) ( apply_filters( 'mmi_pipeline_waiting_flags', [], [ $id ] )[ $id ] ?? [] ) as $flag => $detail ) {
				$issues[] = Issues::describe_flag( (string) $flag, (string) $detail, $id ) + [ 'key' => (string) $flag ];
			}
		}
		$locked = static fn( string $tax ) => class_exists( '\MMI_Pipeline_Field_Locks' ) && \MMI_Pipeline_Field_Locks::is_locked( $id, $tax );
		$stock  = (string) ( Store::stock_statuses( [ $id ] )[ $id ] ?? '' );
		return rest_ensure_response(
			[
				'id'       => $id,
				'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'status'   => (string) get_post_status( $id ),
				'stock'    => $stock,
				'sku'      => (string) get_post_meta( $id, '_sku', true ),
				'edit_url' => get_edit_post_link( $id, 'raw' ),
				'view_url' => get_permalink( $id ),
				'waiting'  => self::badges( $id, $keys ),
				'issues'   => array_values( array_map( [ Issues::class, 'normalize' ], $issues ) ),
				'proposal' => $title,
				'cats'     => $terms['cats'][ $id ] ?? [],
				'brands'   => $terms['brands'][ $id ] ?? [],
				'locks'    => [ 'cats' => $locked( 'product_cat' ), 'brands' => $locked( 'product_brand' ) ],
				'feed'     => [ 'brands' => Issues::feed_values( $id, 'product_brand' ), 'cats' => Issues::feed_values( $id, 'product_cat' ) ],
			]
		);
	}
}
