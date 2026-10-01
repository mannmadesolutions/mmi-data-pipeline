<?php
/**
 * Coupon Data Type Handler (Phase 3)
 *
 * Dedicated handler, not a generic-post-type reuse — even though
 * `shop_coupon` is technically a CPT. Essentially everything that makes a
 * coupon useful is meta (discount_type, amount, usage limits, product/
 * category restrictions as serialized ID arrays), and WooCommerce provides
 * `WC_Coupon` getters/setters that handle that serialization and cache
 * invalidation correctly — using raw `update_post_meta()` for array-valued
 * fields risks a coupon that looks saved but silently misbehaves at
 * checkout. Same reasoning as why Product isn't generic-post-type-based.
 *
 * Note: WooCommerce core has no `wc_get_coupons()` helper (unlike
 * `wc_get_orders()`/`wc_get_products()`) — confirmed via direct check on
 * this install. Coupons are queried the same way any CPT is (`get_posts()`),
 * then wrapped in `new WC_Coupon($id)` for all field access/mutation.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Coupon_Data_Type implements MMI_Data_Type_Handler {

    public function get_type_key(): string {
        return 'coupon';
    }

    public function get_label(): string {
        return __( 'WooCommerce Coupons', 'mmi-data-pipeline' );
    }

    public function get_field_schema(): array {
        return [
            'ID'                     => [ 'label' => 'Coupon ID',          'type' => 'integer', 'group' => 'core',   'required' => true ],
            'code'                   => [ 'label' => 'Coupon Code',        'type' => 'string',  'group' => 'core',    'required' => true ],
            'description'            => [ 'label' => 'Description',        'type' => 'longtext', 'group' => 'core',   'required' => false ],
            'discount_type'          => [ 'label' => 'Discount Type',       'type' => 'string',  'group' => 'discount', 'required' => false ],
            'amount'                 => [ 'label' => 'Amount',              'type' => 'decimal',  'group' => 'discount', 'required' => false ],
            'free_shipping'          => [ 'label' => 'Free Shipping',       'type' => 'boolean',  'group' => 'discount', 'required' => false ],
            'date_expires'           => [ 'label' => 'Expiry Date',         'type' => 'datetime', 'group' => 'restrictions', 'required' => false ],
            'minimum_amount'         => [ 'label' => 'Minimum Spend',       'type' => 'decimal',  'group' => 'restrictions', 'required' => false ],
            'maximum_amount'         => [ 'label' => 'Maximum Spend',       'type' => 'decimal',  'group' => 'restrictions', 'required' => false ],
            'individual_use'         => [ 'label' => 'Individual Use Only', 'type' => 'boolean',  'group' => 'restrictions', 'required' => false ],
            'exclude_sale_items'     => [ 'label' => 'Exclude Sale Items',  'type' => 'boolean',  'group' => 'restrictions', 'required' => false ],
            'usage_limit'            => [ 'label' => 'Usage Limit',        'type' => 'integer',  'group' => 'usage',   'required' => false ],
            'usage_limit_per_user'   => [ 'label' => 'Usage Limit Per User', 'type' => 'integer', 'group' => 'usage',  'required' => false ],
            'usage_count'            => [ 'label' => 'Times Used',          'type' => 'integer',  'group' => 'usage',   'required' => false ],
            'product_ids'            => [ 'label' => 'Product IDs',         'type' => 'array',   'group' => 'products', 'required' => false ],
            'excluded_product_ids'   => [ 'label' => 'Excluded Product IDs','type' => 'array',   'group' => 'products', 'required' => false ],
            'product_categories'     => [ 'label' => 'Product Categories',  'type' => 'array',   'group' => 'products', 'required' => false ],
            'excluded_product_categories' => [ 'label' => 'Excluded Categories', 'type' => 'array', 'group' => 'products', 'required' => false ],
            'email_restrictions'     => [ 'label' => 'Allowed Emails',      'type' => 'array',   'group' => 'restrictions', 'required' => false ],
        ] + MMI_Taxonomy_Field_Helper::schema_fields( 'shop_coupon' );
    }

    public function get_id_field(): string {
        return 'ID';
    }

    public function get_edit_url( $id ): string {
        return admin_url( 'post.php?post=' . absint( $id ) . '&action=edit' );
    }

    /**
     * @param array $scope Supports: status ('publish'|'draft'), search (coupon code).
     */
    public function query_records( array $scope, int $limit, int $offset ): array {
        $args = [
            'post_type'      => 'shop_coupon',
            'post_status'    => ! empty( $scope['status'] ) ? $scope['status'] : 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        if ( ! empty( $scope['search'] ) ) {
            $args['s'] = $scope['search'];
        }

        $posts   = get_posts( $args );
        $coupons = array_map( static fn( $p ) => new \WC_Coupon( $p->ID ), $posts );

        $count_args = $args;
        unset( $count_args['posts_per_page'], $count_args['offset'] );
        $count_args['posts_per_page'] = -1;
        $count_args['fields']         = 'ids';
        $total = count( get_posts( $count_args ) );

        return [ 'records' => $coupons, 'total' => $total ];
    }

    /**
     * @param \WC_Coupon $record
     */
    public function resolve_record( $record ): array {
        if ( ! $record instanceof \WC_Coupon ) {
            return [];
        }

        $expires = $record->get_date_expires();

        return [
            'ID'                          => $record->get_id(),
            'code'                        => $record->get_code(),
            'description'                 => $record->get_description(),
            'discount_type'               => $record->get_discount_type(),
            'amount'                      => $record->get_amount(),
            'free_shipping'               => $record->get_free_shipping() ? 1 : 0,
            'date_expires'                => $expires ? $expires->date( 'Y-m-d' ) : '',
            'minimum_amount'              => $record->get_minimum_amount(),
            'maximum_amount'              => $record->get_maximum_amount(),
            'individual_use'              => $record->get_individual_use() ? 1 : 0,
            'exclude_sale_items'          => $record->get_exclude_sale_items() ? 1 : 0,
            'usage_limit'                 => $record->get_usage_limit(),
            'usage_limit_per_user'        => $record->get_usage_limit_per_user(),
            'usage_count'                 => $record->get_usage_count(),
            'product_ids'                 => implode( ',', $record->get_product_ids() ),
            'excluded_product_ids'        => implode( ',', $record->get_excluded_product_ids() ),
            'product_categories'          => implode( ',', $record->get_product_categories() ),
            'excluded_product_categories' => implode( ',', $record->get_excluded_product_categories() ),
            'email_restrictions'          => implode( ',', $record->get_email_restrictions() ),
        ] + MMI_Taxonomy_Field_Helper::resolve_fields( $record->get_id(), 'shop_coupon' );
    }

    /**
     * Writes exclusively via WC_Coupon setters (never raw update_post_meta())
     * so WooCommerce's own array-serialization and cache invalidation stay
     * correct for product_ids/excluded_product_ids/product_categories/etc.
     */
    public function upsert_record( array $data, ?int $existing_id ): array {
        $coupon = $existing_id ? new \WC_Coupon( $existing_id ) : new \WC_Coupon();

        if ( ! $existing_id && ! empty( $data['code'] ) ) {
            $coupon->set_code( $data['code'] );
        }

        $scalar_setter_map = [
            'description'          => 'set_description',
            'discount_type'        => 'set_discount_type',
            'amount'                => 'set_amount',
            'date_expires'          => 'set_date_expires',
            'minimum_amount'        => 'set_minimum_amount',
            'maximum_amount'        => 'set_maximum_amount',
            'usage_limit'           => 'set_usage_limit',
            'usage_limit_per_user'  => 'set_usage_limit_per_user',
        ];
        foreach ( $scalar_setter_map as $field => $setter ) {
            if ( array_key_exists( $field, $data ) && $data[ $field ] !== '' ) {
                $coupon->{$setter}( $data[ $field ] );
            }
        }

        $bool_setter_map = [
            'free_shipping'      => 'set_free_shipping',
            'individual_use'     => 'set_individual_use',
            'exclude_sale_items' => 'set_exclude_sale_items',
        ];
        foreach ( $bool_setter_map as $field => $setter ) {
            if ( array_key_exists( $field, $data ) ) {
                $coupon->{$setter}( filter_var( $data[ $field ], FILTER_VALIDATE_BOOLEAN ) );
            }
        }

        $array_setter_map = [
            'product_ids'                 => 'set_product_ids',
            'excluded_product_ids'        => 'set_excluded_product_ids',
            'product_categories'          => 'set_product_categories',
            'excluded_product_categories' => 'set_excluded_product_categories',
            'email_restrictions'          => 'set_email_restrictions',
        ];
        foreach ( $array_setter_map as $field => $setter ) {
            if ( array_key_exists( $field, $data ) && $data[ $field ] !== '' ) {
                $ids = is_array( $data[ $field ] ) ? $data[ $field ] : array_filter( array_map( 'trim', explode( ',', (string) $data[ $field ] ) ) );
                $coupon->{$setter}( array_map( 'strval', $ids ) === $ids ? array_filter( $ids ) : $ids );
            }
        }

        $coupon_id = $coupon->save();

        return [
            'success' => (bool) $coupon_id,
            'id'      => (int) $coupon_id,
            'message' => $existing_id ? 'updated' : 'created',
            'action'  => $existing_id ? 'update' : 'create',
        ];
    }

    public function find_existing_id( string $primary_key_field, $primary_value ): ?int {
        if ( $primary_value === null || $primary_value === '' ) {
            return null;
        }
        if ( $primary_key_field === 'code' ) {
            $id = wc_get_coupon_id_by_code( (string) $primary_value );
            return $id ? (int) $id : null;
        }
        if ( $primary_key_field === 'ID' ) {
            $post = get_post( (int) $primary_value );
            return ( $post && $post->post_type === 'shop_coupon' ) ? (int) $post->ID : null;
        }
        return null;
    }

    public function required_capability(): string {
        return 'manage_woocommerce';
    }
}
