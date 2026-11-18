<?php
/**
 * What state a product is in for selling.
 *
 * Out of stock can mean two opposite things. A product a supplier dropped won't be sold again; a product held until a
 * release date (Embargo) is about to be. Anything that needs the held products asks here, so a held product isn't
 * treated as a dropped one.
 *
 * @package MannMade\DataPipeline
 */

namespace MannMade\DataPipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Product_State {

	/** Products held until a date. @return int[] */
	public static function held_ids(): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Embargo::META_UNTIL ) ) );
	}
}
