<?php
/**
 * A product's own name, read from its title, for matching it to a maker's page.
 *
 * Part of the maker-page block that moves to mmi-data-pipeline (Product Data
 * migration phase 5a, PRODUCT_DATA_MIGRATION_SCOPE.md §5c). It must not call
 * this site's title rules directly, so it asks filter `mmi_pipeline_product_name`
 * ( $name, $title, $brand, $fallback ); this site answers with the title
 * composer's parsed name (product-titles.php). With nothing attached, the
 * default is generic: the title without a leading brand and without bracketed
 * qualifiers ("Acme Dynamics Pro (Native, Download)" → "Dynamics Pro").
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageName {

	public static function of( string $title, string $brand, string $fallback = '' ): string {
		$name = apply_filters( 'mmi_pipeline_product_name', null, $title, $brand, $fallback );
		return is_string( $name ) ? $name : self::generic( $title, $brand, $fallback );
	}

	/**
	 * A product's core name for matching across catalogs (a distributor's listing of the same product):
	 * filter mmi_pipeline_product_core ( $core, $title, $brand ); this site answers with Bundles::core().
	 * Generic default: generic().
	 */
	public static function core( string $title, string $brand ): string {
		$core = apply_filters( 'mmi_pipeline_product_core', null, $title, $brand );
		return is_string( $core ) ? $core : self::generic( $title, $brand );
	}

	/** Plain text: tags stripped, entities decoded, white space collapsed (was Composer::clean()). */
	public static function clean( string $s ): string {
		$s = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Title minus a leading brand and bracketed qualifiers; $fallback when nothing is left. */
	public static function generic( string $title, string $brand, string $fallback = '' ): string {
		$name = trim( (string) preg_replace( '/\s*[\(\[][^\)\]]*[\)\]]\s*/u', ' ', $title ) );
		if ( $brand !== '' && stripos( $name, $brand ) === 0 ) {
			$name = ltrim( substr( $name, strlen( $brand ) ), " \t-–—:" );
		}
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		return $name !== '' ? $name : $fallback;
	}
}
