<?php
/**
 * MMI_Supplier_Rule_Pack
 *
 * The mmi-data-health rules every software-distributor integration shares
 * (XChange, SkuPort, Plugivery), each scored only on that supplier's own
 * products:
 *
 *  - still_sold (required): a product carrying the supplier's SKU that the
 *    supplier's last feed no longer lists can't be fulfilled. Membership
 *    comes from mmi-data-pipeline's feed catalog index (one compact file per
 *    supplier, never the raw multi-MB feed), so a background scan batch
 *    never parses a feed.
 *  - supplier_sku_present (recommended): a product the store records as
 *    coming from this supplier (`_supplier_name`) but with no supplier SKU
 *    can't be matched to the feed or ordered.
 *
 * A supplier plugin's pack extends this and adds its own rules through
 * extra_rules().
 *
 * mmi-data-health's interface exists only while that plugin is active, and
 * PHP resolves `implements` at declaration, so without it this file
 * declares nothing. Supplier packs are only loaded from inside the
 * mmi_data_health_rule_packs filter, where the interface always exists.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Supplier_Rule_Pack', false ) && interface_exists( 'MMI_Data_Health_Rule_Pack' ) ) {

	abstract class MMI_Supplier_Rule_Pack implements MMI_Data_Health_Rule_Pack {

		/** Supplier ID: the feed catalog's and `_supplier_name`'s ('skuport'). */
		abstract protected function supplier_id(): string;

		/** Display name ('SkuPort'). */
		abstract protected function supplier_label(): string;

		/** Postmeta keys holding this supplier's SKU, primary first. */
		protected function sku_meta_keys(): array {
			return array( '_mmi_supplier_sku_' . $this->supplier_id() );
		}

		/** Rules only this supplier has. */
		protected function extra_rules(): array {
			return array();
		}

		public function get_id(): string {
			return 'mmi-' . $this->supplier_id() . '-integration';
		}

		public function get_label(): string {
			/* translators: 1: supplier name, 2: plugin slug */
			return sprintf( __( '%1$s Fulfillment (%2$s)', 'mmi-shared' ), $this->supplier_label(), $this->get_id() );
		}

		public function get_rules(): array {
			return array_merge( array( $this->still_sold_rule(), $this->supplier_sku_rule() ), $this->extra_rules() );
		}

		/** The product's supplier SKU from the first meta key that has one, or ''. */
		protected function sku( int $post_id ): string {
			foreach ( $this->sku_meta_keys() as $key ) {
				$value = trim( (string) get_post_meta( $post_id, $key, true ) );
				if ( $value !== '' ) {
					return $value;
				}
			}
			return '';
		}

		protected function is_supplier_product( int $post_id ): bool {
			return get_post_type( $post_id ) === 'product' && $this->sku( $post_id ) !== '';
		}

		/**
		 * Whether the supplier's last feed lists $sku, or null when there's
		 * no feed catalog to ask (mmi-data-pipeline inactive, or never
		 * fetched): the rule is then not applicable rather than failing.
		 */
		protected function in_feed( string $sku ): ?bool {
			$catalog = class_exists( 'MMI_Pipeline_Feed_Catalog' ) ? MMI_Pipeline_Feed_Catalog::get( $this->supplier_id() ) : null;
			return ( $catalog && method_exists( $catalog, 'has_sku' ) ) ? $catalog->has_sku( $sku ) : null;
		}

		protected function still_sold_rule(): MMI_Data_Health_Rule {
			$label = $this->supplier_label();
			return new MMI_Data_Health_Rule( array(
				'id'         => $this->get_id() . '.still_sold',
				/* translators: %s: supplier name */
				'label'      => sprintf( __( 'Still sold by %s', 'mmi-shared' ), $label ),
				'severity'   => 'required',
				'weight'     => 10,
				'post_types' => array( 'product' ),
				'applicable' => fn( int $post_id ): bool => $this->is_supplier_product( $post_id ) && $this->in_feed( $this->sku( $post_id ) ) !== null,
				'check'      => function ( int $post_id ) use ( $label ): array {
					if ( $this->in_feed( $this->sku( $post_id ) ) ) {
						return array( 'pass' => true );
					}
					$sellable = get_post_status( $post_id ) === 'publish' && get_post_meta( $post_id, '_stock_status', true ) === 'instock';
					return array(
						'pass'    => false,
						'message' => $sellable
							/* translators: %s: supplier name */
							? sprintf( __( '%s no longer lists this product, but it is published and in stock, so it cannot be fulfilled', 'mmi-shared' ), $label )
							/* translators: %s: supplier name */
							: sprintf( __( '%s no longer lists this product', 'mmi-shared' ), $label ),
					);
				},
			) );
		}

		protected function supplier_sku_rule(): MMI_Data_Health_Rule {
			$label = $this->supplier_label();
			return new MMI_Data_Health_Rule( array(
				'id'         => $this->get_id() . '.supplier_sku_present',
				/* translators: %s: supplier name */
				'label'      => sprintf( __( '%s supplier SKU', 'mmi-shared' ), $label ),
				'severity'   => 'recommended',
				'weight'     => 5,
				'post_types' => array( 'product' ),
				'applicable' => fn( int $post_id ): bool => get_post_type( $post_id ) === 'product'
					&& strcasecmp( trim( (string) get_post_meta( $post_id, '_supplier_name', true ) ), $this->supplier_id() ) === 0,
				'check'      => fn( int $post_id ): array => $this->sku( $post_id ) !== ''
					? array( 'pass' => true )
					/* translators: %1$s: supplier name */
					: array( 'pass' => false, 'message' => sprintf( __( 'Supplier is %1$s, but no %1$s SKU is on file', 'mmi-shared' ), $label ) ),
			) );
		}
	}
}
