<?php
/**
 * What state a product is in for selling: one answer, for every writer.
 *
 * Out of stock can mean two opposite things. A product a supplier dropped won't be sold again; a product held until a
 * release date is about to be. Anything deciding whether a product is worth working on asks here, so a held product isn't
 * treated as a dropped one.
 *
 * - sold: on sale.
 * - held: out of stock until a named date (Embargo).
 * - suppressed: the extra listing of a duplicate, kept out of stock while its canonical product sells.
 * - out_of_stock: anything else out of stock.
 *
 * A site can add its own reading through `mmi_pipeline_product_state`.
 *
 * @package MannMade\DataPipeline
 */

namespace MannMade\DataPipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Product_State {

	const SOLD         = 'sold';
	const HELD         = 'held';
	const SUPPRESSED   = 'suppressed';
	const OUT_OF_STOCK = 'out_of_stock';

	/** @var array<int,bool>|null */
	private static $suppressed = null;

	/** Product $id's state. */
	public static function of( int $id ): string {
		if ( metadata_exists( 'post', $id, Embargo::META_UNTIL ) ) {
			$state = self::HELD;
		} elseif ( isset( self::suppressed()[ $id ] ) ) {
			$state = self::SUPPRESSED;
		} else {
			$state = (string) get_post_meta( $id, '_stock_status', true ) === 'outofstock' ? self::OUT_OF_STOCK : self::SOLD;
		}
		return (string) apply_filters( 'mmi_pipeline_product_state', $state, $id );
	}

	/** The extra listings of duplicates, read once a request: the list takes a query across the catalog. @return array<int,bool> */
	private static function suppressed(): array {
		if ( self::$suppressed === null ) {
			self::$suppressed = class_exists( '\\MMI_Pipeline_Catalog_Updater' ) ? \MMI_Pipeline_Catalog_Updater::suppressed_duplicate_ids() : [];
		}
		return self::$suppressed;
	}

	/** Products held until a date. @return int[] */
	public static function held_ids(): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Embargo::META_UNTIL ) ) );
	}
}
