<?php
/**
 * MMI_Product_Identifiers — the one place every MMI plugin reads a product's
 * GTIN and MPN (feeds, schema, data health, purchase orders, Product Data).
 *
 * GTIN: WooCommerce's native field first (`_global_unique_id`, WC 9.2+ —
 * what WooCommerce's own UI and Google's WC integration write), then the keys
 * other plugins use. A value only counts if it's 8/12/13/14 digits with a valid
 * GS1 check digit — Google rejects anything else, and an invented or mistyped
 * GTIN is worse than none.
 *
 * MPN: the manufacturer's part number. A store SKU is not an MPN, so it's
 * never used as one.
 *
 * Seams (neutral names, ADR-0012 addendum):
 *  - 'mmi_gtin_meta_keys'  meta keys read for a GTIN, in order. A plugin that
 *    stores one under its own key adds it (mmi-reverb-integration: reverb_upc).
 *  - 'mmi_mpn_meta_keys'   the same for an MPN.
 *
 * Lifted from mmi-google-services' MMI_GS_Product_Identifiers (shared library 1.48.0).
 * Do not hand-edit this file in a single plugin; edit the canonical source in
 * mmi-admin/lib/mmi-shared/ and run lib/sync-to-plugins.sh.
 *
 * @package MMI_Shared
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Product_Identifiers' ) ) :

	final class MMI_Product_Identifiers {

		const GTIN_META_KEYS = array( '_global_unique_id', '_wc_gtin', 'sp_wc_barcode_field', '_gtin', 'gtin', '_wc_gpf_gtin', '_ean', 'ean', '_upc', 'upc' );
		const MPN_META_KEYS  = array( 'mpn', '_mpn', '_wc_gpf_mpn' );

		/** @return string[] meta keys read for a GTIN, in order. */
		public static function gtin_keys(): array {
			return self::keys( 'mmi_gtin_meta_keys', self::GTIN_META_KEYS );
		}

		/** @return string[] meta keys read for an MPN, in order. */
		public static function mpn_keys(): array {
			return self::keys( 'mmi_mpn_meta_keys', self::MPN_META_KEYS );
		}

		/** @param WC_Product|int $product */
		public static function gtin( $product ): string {
			$id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
			if ( $product instanceof WC_Product && method_exists( $product, 'get_global_unique_id' ) ) {
				$native = self::valid_gtin( (string) $product->get_global_unique_id() );
				if ( $native !== '' ) {
					return $native;
				}
			}
			foreach ( self::gtin_keys() as $key ) {
				$gtin = self::valid_gtin( (string) get_post_meta( $id, $key, true ) );
				if ( $gtin !== '' ) {
					return $gtin;
				}
			}
			return '';
		}

		/** @param WC_Product|int $product */
		public static function mpn( $product ): string {
			$id = $product instanceof WC_Product ? $product->get_id() : (int) $product;
			foreach ( self::mpn_keys() as $key ) {
				$mpn = trim( (string) get_post_meta( $id, $key, true ) );
				if ( strlen( $mpn ) >= 3 && ! preg_match( '/^0+$/', $mpn ) ) {
					return mb_substr( $mpn, 0, 70 );
				}
			}
			return '';
		}

		/** The digits if $raw is a GTIN-8/12/13/14 with a correct check digit, else ''. */
		public static function valid_gtin( string $raw ): string {
			$digits = (string) preg_replace( '/[\s-]/', '', trim( $raw ) );
			if ( ! preg_match( '/^(\d{8}|\d{12,14})$/', $digits ) || preg_match( '/^0+$/', $digits ) ) {
				return '';
			}
			$sum  = 0;
			$body = substr( $digits, 0, -1 );
			$len  = strlen( $body );
			for ( $i = 0; $i < $len; $i++ ) {
				// GS1: weight 3 on the digit nearest the check digit, alternating 3/1 leftwards.
				$sum += (int) $body[ $len - 1 - $i ] * ( $i % 2 === 0 ? 3 : 1 );
			}
			return ( ( 10 - $sum % 10 ) % 10 ) === (int) substr( $digits, -1 ) ? $digits : '';
		}

		private static function keys( string $filter, array $default ): array {
			$keys = apply_filters( $filter, $default );
			return array_values( array_unique( array_filter( array_map( 'strval', is_array( $keys ) ? $keys : $default ), static fn( $k ) => $k !== '' ) ) );
		}
	}

endif;
