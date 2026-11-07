<?php
/**
 * Duplicate listings point search engines at their main listing.
 *
 * A product listed twice (one listing per distributor) keeps one listing on
 * sale (canonical = TRUE, with associated_product_ids naming the others) and
 * takes the rest off sale. The others' pages still exist, with the same name
 * and text, so to a search engine they are copies competing with the listing
 * that sells. This answers mmi-seo's `mmi_seo_canonical` filter: a linked
 * listing's canonical link is its main listing, so Google credits that one
 * and stops weighing the copies. Pages stay up; nothing is redirected.
 *
 * Only groups linked in the Duplicates queue are known here. The map is
 * built once and kept until a group changes (mmi_duplicate_groups_changed).
 *
 * @package MMI_Data_Pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMI_Pipeline_SEO_Canonical {

	const CACHE = 'mmi_pipeline_duplicate_canonical_map';
	const TTL   = 12 * HOUR_IN_SECONDS;

	public static function register_hooks(): void {
		add_filter( 'mmi_seo_canonical', array( __CLASS__, 'canonical' ), 10, 2 );
		add_action( 'mmi_duplicate_groups_changed', array( __CLASS__, 'forget' ) );
	}

	/**
	 * @param string   $url  A canonical another filter gave, or ''.
	 * @param \WP_Post $post The page being shown.
	 */
	public static function canonical( $url, $post ): string {
		if ( (string) $url !== '' || ! $post instanceof \WP_Post || $post->post_type !== 'product' ) {
			return (string) $url;
		}
		$main = self::map()[ (int) $post->ID ] ?? 0;
		if ( ! $main || get_post_status( $main ) !== 'publish' || get_post_type( $main ) !== 'product' ) {
			return '';
		}
		return (string) get_permalink( $main );
	}

	/** listing ID => its main listing's ID, for every group linked in the Duplicates queue. */
	public static function map(): array {
		static $memo = null;
		if ( $memo !== null ) {
			return $memo;
		}
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $memo = $cached;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status = 'publish'
			 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = pm.post_id AND c.meta_key = 'canonical'
			 WHERE pm.meta_key = 'associated_product_ids' AND pm.meta_value <> '' AND pm.meta_value <> 'a:0:{}'
			   AND ( c.meta_value IS NULL OR c.meta_value NOT IN ( 'FALSE', 'false', '0' ) )"
		);
		$memo = array();
		foreach ( (array) $rows as $row ) {
			$main = (int) $row->post_id;
			foreach ( self::ids( $row->meta_value ) as $id ) {
				if ( $id > 0 && $id !== $main ) {
					$memo[ $id ] = $main;
				}
			}
		}
		set_transient( self::CACHE, $memo, self::TTL );
		return $memo;
	}

	/**
	 * The listing IDs in an associated_product_ids value: a comma list ("18729,18829") or, from JetEngine's
	 * days, a serialized repeater ({"item-0":{"associated_product_id":"4062"}}). As the Duplicates queue reads it.
	 *
	 * @return int[]
	 */
	private static function ids( $raw ): array {
		$value = maybe_unserialize( $raw );
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				$out[] = is_array( $item ) ? (int) ( $item['associated_product_id'] ?? $item['product_id'] ?? $item['ID'] ?? $item['id'] ?? 0 ) : (int) $item;
			}
			return array_filter( $out );
		}
		return array_filter( array_map( 'intval', (array) preg_split( '/[,|\s]+/', trim( (string) $value ) ) ) );
	}

	public static function forget(): void {
		delete_transient( self::CACHE );
	}
}
