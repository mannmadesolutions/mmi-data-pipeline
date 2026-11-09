<?php
/**
 * Products left out of this whole module: no title proposals, no Claude
 * description picks, no vendor-page fetch or URL discovery, no Phase 5
 * description/image proposals, and nothing ever written. For lines the store
 * doesn't sell yet (user request 2026-09-27: Prism Sound).
 *
 * Excluded by brand (every product carrying a product_brand term) or by
 * product ID. Catalog::eligible_ids() drops them, so the generator and the
 * enrichment pass never see them; every applier and queue query adds
 * sql_not_excluded() as a backstop, so a row that already existed (an edited
 * title, an accepted image) is never written either. Removing an exclusion
 * makes the products eligible again and recomposes them.
 *
 * ids() also holds the products the store no longer sells (NotSold, worked
 * out from the supplier feeds, 2026-10-08); manual_ids() is the Rules-tab
 * list alone, plus the store's own brand.
 *
 * The store's own brand (MannMade Solutions: its plugins, Website Care, services) is always left out (owner
 * 2026-10-27: "No product title rules should exist for MannMade Solutions brand products"): it has no maker page
 * to read and its copy is written by hand. It shows on the Rules tab as fixed, with no Remove.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Exclusions {

	const KEY = 'mmi_admin_product_titles_exclusions';

	/** @var int[]|null per-request memo of excluded product IDs */
	private static $ids = null;

	/**
	 * Brands always left out, whatever the Rules tab says (filter
	 * mmi_pipeline_fixed_excluded_brands; this site adds its own brand, see
	 * product-titles.php). Phase 5a seam: the list itself moves with Product
	 * Data, this site's brand stays here.
	 *
	 * @return int[]
	 */
	public static function fixed_brands(): array {
		$ids = apply_filters( 'mmi_pipeline_fixed_excluded_brands', [] );
		return array_values( array_filter( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) ) );
	}

	/** @return array{brands:int[],products:int[]} */
	public static function get(): array {
		$s = Module::state( self::KEY );
		return [
			'brands'   => array_values( array_filter( array_unique( array_map( 'intval', array_merge( (array) ( $s['brands'] ?? [] ), self::fixed_brands() ) ) ) ) ),
			'products' => array_values( array_unique( array_map( 'intval', (array) ( $s['products'] ?? [] ) ) ) ),
		];
	}

	/** Every product left out: the Rules-tab exclusions plus the products no longer sold. @return int[] */
	public static function ids(): array {
		if ( self::$ids === null ) {
			// Plus what the store says is left out besides (filter mmi_pipeline_left_out_ids; this site: products no longer sold, NotSold).
			$more      = apply_filters( 'mmi_pipeline_left_out_ids', [] );
			self::$ids = array_values( array_unique( array_merge( self::manual_ids(), array_map( 'intval', is_array( $more ) ? $more : [] ) ) ) );
		}
		return self::$ids;
	}

	/** Forget the per-request memo (after NotSold changes its list). */
	public static function forget(): void {
		self::$ids = null;
	}

	/** Products excluded on the Rules tab (brand members + explicit products). @return int[] */
	public static function manual_ids(): array {
		global $wpdb;
		$ex  = self::get();
		$ids = $ex['products'];
		if ( $ex['brands'] ) {
			$ids = array_merge(
				$ids,
				array_map(
					'intval',
					$wpdb->get_col(
						"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
						 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand'
						 WHERE tt.term_id IN (" . implode( ',', $ex['brands'] ) . ')'
					)
				)
			);
		}
		return array_values( array_unique( $ids ) );
	}

	/** Published products Product Data works on: every one not left out. @return int[] */
	public static function eligible_ids(): array {
		global $wpdb;
		return self::filter( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID ASC" ) );
	}

	/**
	 * Which of these products are sold as downloads (software, sample libraries): their maker
	 * pages are read for system requirements and formats. Generic answer: virtual or downloadable
	 * in WooCommerce; a store with its own rule answers filter mmi_pipeline_digital_products
	 * (this site: Catalog::contexts()'s software tree, distribution and physical markers).
	 *
	 * @param int[] $ids
	 * @return array<int,bool>
	 */
	public static function digital( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$out = apply_filters( 'mmi_pipeline_digital_products', null, $ids );
		if ( is_array( $out ) ) {
			return array_map( 'boolval', $out );
		}
		$out = [];
		foreach ( $ids as $id ) {
			$p          = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			$out[ $id ] = $p && ( $p->is_virtual() || $p->is_downloadable() );
		}
		return $out;
	}

	public static function is_excluded( int $id ): bool {
		return in_array( $id, self::ids(), true );
	}

	/** Drop excluded IDs from a list (keeps order). @return int[] */
	public static function filter( array $ids ): array {
		$ex = array_flip( self::ids() );
		return $ex ? array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => ! isset( $ex[ $id ] ) ) ) : array_map( 'intval', $ids );
	}

	/** " AND {$col} NOT IN (…)" for queue/applier queries, '' when nothing is excluded. */
	public static function sql_not_excluded( string $col = 'product_id' ): string {
		$ids = self::ids();
		return $ids ? " AND {$col} NOT IN (" . implode( ',', $ids ) . ')' : '';
	}

	/**
	 * Replace the exclusion lists, then tidy the module's own tables: open
	 * title and Phase 5 proposals for newly excluded products are removed and
	 * their description rows parked; products no longer excluded get their
	 * description rows refreshed. A recomposition is queued either way.
	 *
	 * @return array{brands:int[],products:int[],excluded:int,removed:int,restored:int}
	 */
	public static function save( array $brands, array $products ): array {
		$before = self::ids();
		$fixed  = self::fixed_brands();
		$brands = array_values( array_filter( array_unique( array_map( 'intval', $brands ) ), static fn( $t ) => $t > 0 && ! in_array( $t, $fixed, true ) && term_exists( $t, 'product_brand' ) ) ); // fixed brands are never stored
		$products = array_values( array_filter( array_unique( array_map( 'intval', $products ) ), static fn( $p ) => $p > 0 && get_post_type( $p ) === 'product' ) );
		Module::set_state( self::KEY, [ 'brands' => $brands, 'products' => $products, 'by' => get_current_user_id(), 'at' => current_time( 'mysql' ) ] );
		self::$ids = null;
		$now      = self::ids();
		$added    = array_values( array_diff( $now, $before ) );
		$restored = array_values( array_diff( $before, $now ) );

		// What the other modules do about it (park open proposals, refresh descriptions, recompose titles):
		// filter mmi_pipeline_exclusions_changed returns the rows removed. This site: PageFollowUps::park_and_refresh().
		$removed = (int) apply_filters( 'mmi_pipeline_exclusions_changed', 0, $added, $restored );
		Module::info( 'Product Titles exclusions saved', [ 'brands' => count( $brands ), 'products' => count( $products ), 'excluded' => count( $now ), 'removed' => $removed, 'restored' => count( $restored ) ] );
		return [ 'brands' => $brands, 'products' => $products, 'excluded' => count( self::manual_ids() ), 'removed' => $removed, 'restored' => count( $restored ) ];
	}

	/** For the Rules tab: names and product counts. */
	public static function summary(): array {
		global $wpdb;
		$ex     = self::get();
		$brands = [];
		foreach ( $ex['brands'] as $tid ) {
			$term = get_term( $tid, 'product_brand' );
			if ( $term && ! is_wp_error( $term ) ) {
				$brands[] = [ 'id' => $tid, 'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ), 'count' => (int) $term->count, 'fixed' => in_array( $tid, self::fixed_brands(), true ) ];
			}
		}
		$products = [];
		foreach ( $ex['products'] as $pid ) {
			$products[] = [ 'id' => $pid, 'title' => html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ), 'sku' => (string) get_post_meta( $pid, '_sku', true ) ];
		}
		$all = get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => true, 'orderby' => 'name', 'fields' => 'id=>name' ] );
		return [
			'brands'     => $brands,
			'products'   => $products,
			'excluded'   => count( self::manual_ids() ),
			'not_sold'   => apply_filters( 'mmi_pipeline_left_out_summary', [] ), // this site: NotSold::summary()
			'all_brands' => is_array( $all ) ? array_map( static fn( $id, $name ) => [ 'id' => (int) $id, 'name' => html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ], array_keys( $all ), $all ) : [],
		];
	}

	/** A product ID or SKU typed by a person → product ID (0 if none). */
	public static function resolve_product( string $input ): int {
		$input = trim( $input );
		if ( ctype_digit( $input ) && get_post_type( (int) $input ) === 'product' ) {
			return (int) $input;
		}
		return $input !== '' ? (int) wc_get_product_id_by_sku( $input ) : 0;
	}
}
