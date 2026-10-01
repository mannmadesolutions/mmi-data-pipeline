<?php
/**
 * MMI_Product — product ORM (bundled/vendored copy).
 *
 * Vendored from mmi-hub/includes/models/class-product-model.php, with one
 * addition: maybe_create_table(), called once per request the first time any
 * MMI_Product method touches the table. mmi-hub never actually creates
 * wp_mmi_products via its own installer's activation hook either — it only
 * ever existed on mannmade.us because a legacy migration script
 * (migrate-credentials-to-mmi-table.php) happened to run once, long ago, and
 * the table has persisted since. A genuinely standalone site (mmi-hub never
 * installed) gets no such migration, so without this the table simply never
 * exists — not a fatal (MMI_Model's queries degrade to null/empty/false
 * against a missing table, same as MMI_Settings), but a real silent
 * persistence gap: product sync state quietly never saves. See ADR-0006.
 * Do not hand-edit this file in a single plugin; edit the canonical source
 * and re-sync to every plugin that bundles it.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_Product extends MMI_Model {

	protected static $table_name = 'mmi_products';

	/** Ensures wp_mmi_products exists — idempotent, safe to call every request. */
	private static $table_ensured = false;

	/**
	 * Create wp_mmi_products if it doesn't exist yet. Schema copied verbatim
	 * from mmi-hub/includes/class-installer.php's create_tables() so a table
	 * created this way is byte-for-byte identical to one mmi-hub would have
	 * created — dbDelta() is idempotent, so this is safe to call even when
	 * mmi-hub (or another plugin's bundled copy) already created the table.
	 */
	public static function maybe_create_table(): void {
		if ( self::$table_ensured ) {
			return;
		}
		self::$table_ensured = true;

		global $wpdb;
		$table = static::get_table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id bigint(20) UNSIGNED NOT NULL,

            sku varchar(100) DEFAULT NULL,
            brand varchar(255) DEFAULT NULL,
            model varchar(255) DEFAULT NULL,
            product_condition varchar(50) DEFAULT NULL,

            reverb_listing_id varchar(100) DEFAULT NULL,
            reverb_state varchar(50) DEFAULT NULL,
            reverb_price decimal(10,2) DEFAULT NULL,
            reverb_last_sync datetime DEFAULT NULL,

            gmc_offer_id varchar(100) DEFAULT NULL,
            gmc_status varchar(50) DEFAULT NULL,
            gmc_last_sync datetime DEFAULT NULL,

            meta_title varchar(255) DEFAULT NULL,
            meta_description text DEFAULT NULL,
            ai_generated tinyint(1) DEFAULT 0,

            cost_price decimal(10,2) DEFAULT NULL,
            margin_percent decimal(5,2) DEFAULT NULL,
            min_stock_level int(11) DEFAULT 0,

            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,

            PRIMARY KEY (id),
            UNIQUE KEY post_id (post_id),
            KEY sku (sku),
            KEY brand (brand),
            KEY product_condition (product_condition),
            KEY reverb_listing_id (reverb_listing_id),
            KEY gmc_offer_id (gmc_offer_id),
            KEY reverb_last_sync (reverb_last_sync),
            KEY gmc_last_sync (gmc_last_sync)
        ) {$charset_collate};";

		dbDelta( $sql );

		if ( class_exists( 'MMI_Logger' ) ) {
			MMI_Logger::info( 'wp_mmi_products created (self-healing, no mmi-hub required)', array(), 'database', 'MMI_Product' );
		}
	}

	protected static function get_table_name() {
		self::maybe_create_table();
		return parent::get_table_name();
	}

	/**
	 * @return static|null
	 */
	public static function find_by_post_id( $post_id ) {
		return static::find_one_by( array( 'post_id' => $post_id ) );
	}

	/**
	 * @return static|null
	 */
	public static function find_by_sku( $sku ) {
		return static::find_one_by( array( 'sku' => $sku ) );
	}

	/**
	 * @return static|null
	 */
	public static function find_by_reverb_id( $reverb_id ) {
		return static::find_one_by( array( 'reverb_listing_id' => $reverb_id ) );
	}

	/**
	 * @return static|null
	 */
	public static function find_by_gmc_id( $gmc_id ) {
		return static::find_one_by( array( 'gmc_offer_id' => $gmc_id ) );
	}

	public function get_wc_product() {
		if ( ! $this->post_id ) {
			return null;
		}

		return wc_get_product( $this->post_id );
	}

	public function needs_reverb_sync() {
		if ( ! $this->reverb_last_sync ) {
			return true;
		}

		$last_sync  = strtotime( $this->reverb_last_sync );
		$updated_at = strtotime( $this->updated_at );

		return $updated_at > $last_sync;
	}

	public function needs_gmc_sync() {
		if ( ! $this->gmc_last_sync ) {
			return true;
		}

		$last_sync  = strtotime( $this->gmc_last_sync );
		$updated_at = strtotime( $this->updated_at );

		return $updated_at > $last_sync;
	}

	public static function needs_sync( $sync_type = 'reverb', $limit = 50 ) {
		global $wpdb;
		$table = static::get_table_name();

		$column = ( $sync_type === 'reverb' ) ? 'reverb_last_sync' : 'gmc_last_sync';

		$sql = "SELECT * FROM {$table}
                WHERE {$column} IS NULL
                   OR updated_at > {$column}
                ORDER BY updated_at DESC
                LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );

		return array_map(
			function ( $row ) {
				return new static( $row );
			},
			$rows
		);
	}

	public static function sync_from_wc_product( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return false;
		}

		$mmi_product = static::find_by_post_id( $product_id );

		if ( ! $mmi_product ) {
			$mmi_product = new static( array( 'post_id' => $product_id ) );
		}

		$mmi_product->sku = $product->get_sku();

		$mmi_product->brand              = get_post_meta( $product_id, '_brand', true );
		$mmi_product->model              = get_post_meta( $product_id, '_model', true );
		$mmi_product->product_condition  = get_post_meta( $product_id, '_condition', true );
		$mmi_product->reverb_listing_id  = get_post_meta( $product_id, '_reverb_listing_id', true );
		$mmi_product->reverb_state       = get_post_meta( $product_id, '_reverb_state', true );
		$mmi_product->gmc_offer_id       = get_post_meta( $product_id, '_gmc_offer_id', true );
		$mmi_product->gmc_status         = get_post_meta( $product_id, '_gmc_status', true );

		return $mmi_product->save();
	}
}
