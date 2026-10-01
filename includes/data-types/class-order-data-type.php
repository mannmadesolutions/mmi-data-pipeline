<?php
/**
 * Order Data Type Handler (Phase 2)
 *
 * HPOS-aware (this store runs HPOS) — reads/writes exclusively through
 * wc_get_orders()/WC_Order getters/setters, never raw postmeta or a direct
 * WP_Query on 'shop_order', so it works whether the store has HPOS enabled
 * or is still on the legacy posts table.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Order_Data_Type implements MMI_Data_Type_Handler {

    public function get_type_key(): string {
        return 'order';
    }

    public function get_label(): string {
        return __( 'WooCommerce Orders', 'mmi-data-pipeline' );
    }

    public function get_field_schema(): array {
        return [
            'ID'                    => [ 'label' => 'Order ID',        'type' => 'integer', 'group' => 'core',     'required' => true ],
            'order_number'          => [ 'label' => 'Order Number',    'type' => 'string',  'group' => 'core',     'required' => true ],
            'status'                => [ 'label' => 'Status',          'type' => 'string',  'group' => 'core',     'required' => false ],
            'date_created'          => [ 'label' => 'Date Created',    'type' => 'datetime', 'group' => 'core',    'required' => false ],
            'date_modified'         => [ 'label' => 'Date Modified',   'type' => 'datetime', 'group' => 'core',    'required' => false ],
            'currency'              => [ 'label' => 'Currency',        'type' => 'string',  'group' => 'totals',   'required' => false ],
            'total'                 => [ 'label' => 'Order Total',     'type' => 'decimal',  'group' => 'totals',  'required' => false ],
            'subtotal'              => [ 'label' => 'Subtotal',        'type' => 'decimal',  'group' => 'totals',  'required' => false ],
            'total_tax'             => [ 'label' => 'Tax Total',       'type' => 'decimal',  'group' => 'totals',  'required' => false ],
            'shipping_total'        => [ 'label' => 'Shipping Total',  'type' => 'decimal',  'group' => 'totals',  'required' => false ],
            'discount_total'        => [ 'label' => 'Discount Total',  'type' => 'decimal',  'group' => 'totals',  'required' => false ],
            'payment_method'        => [ 'label' => 'Payment Method (key)',   'type' => 'string', 'group' => 'payment', 'required' => false ],
            'payment_method_title'  => [ 'label' => 'Payment Method',  'type' => 'string',  'group' => 'payment',  'required' => false ],
            'customer_id'           => [ 'label' => 'Customer ID',     'type' => 'integer',  'group' => 'customer', 'required' => false ],
            'billing_first_name'    => [ 'label' => 'Billing First Name', 'type' => 'string', 'group' => 'billing', 'required' => false ],
            'billing_last_name'     => [ 'label' => 'Billing Last Name',  'type' => 'string', 'group' => 'billing', 'required' => false ],
            'billing_email'         => [ 'label' => 'Billing Email',   'type' => 'string',  'group' => 'billing',  'required' => false ],
            'billing_phone'         => [ 'label' => 'Billing Phone',   'type' => 'string',  'group' => 'billing',  'required' => false ],
            'billing_address_1'     => [ 'label' => 'Billing Address 1', 'type' => 'string', 'group' => 'billing', 'required' => false ],
            'billing_city'          => [ 'label' => 'Billing City',    'type' => 'string',  'group' => 'billing',  'required' => false ],
            'billing_state'         => [ 'label' => 'Billing State',   'type' => 'string',  'group' => 'billing',  'required' => false ],
            'billing_postcode'      => [ 'label' => 'Billing Postcode','type' => 'string',  'group' => 'billing',  'required' => false ],
            'billing_country'       => [ 'label' => 'Billing Country', 'type' => 'string',  'group' => 'billing',  'required' => false ],
            'shipping_first_name'   => [ 'label' => 'Shipping First Name', 'type' => 'string', 'group' => 'shipping', 'required' => false ],
            'shipping_last_name'    => [ 'label' => 'Shipping Last Name',  'type' => 'string', 'group' => 'shipping', 'required' => false ],
            'shipping_address_1'    => [ 'label' => 'Shipping Address 1', 'type' => 'string', 'group' => 'shipping', 'required' => false ],
            'shipping_city'         => [ 'label' => 'Shipping City',   'type' => 'string',  'group' => 'shipping',  'required' => false ],
            'shipping_state'        => [ 'label' => 'Shipping State',  'type' => 'string',  'group' => 'shipping',  'required' => false ],
            'shipping_postcode'     => [ 'label' => 'Shipping Postcode', 'type' => 'string', 'group' => 'shipping', 'required' => false ],
            'shipping_country'      => [ 'label' => 'Shipping Country','type' => 'string',  'group' => 'shipping',  'required' => false ],
            'item_count'            => [ 'label' => 'Item Count',      'type' => 'integer',  'group' => 'items',    'required' => false ],
            'item_summary'          => [ 'label' => 'Items (name x qty)', 'type' => 'string', 'group' => 'items',   'required' => false ],
            'customer_note'         => [ 'label' => 'Customer Note',   'type' => 'longtext', 'group' => 'core',     'required' => false ],
        ];
    }

    public function get_id_field(): string {
        return 'ID';
    }

    public function get_edit_url( $id ): string {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $id ) );
        }
        return admin_url( 'post.php?post=' . absint( $id ) . '&action=edit' );
    }

    /**
     * @param array $scope Supports: status (single or array of WC order status slugs,
     *                      without the 'wc-' prefix), date_after / date_before (Y-m-d).
     */
    public function query_records( array $scope, int $limit, int $offset ): array {
        $args = [
            'type'    => 'shop_order',
            'limit'   => $limit,
            'offset'  => $offset,
            'return'  => 'objects',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ];

        if ( ! empty( $scope['status'] ) ) {
            $args['status'] = $scope['status'];
        }
        if ( ! empty( $scope['date_after'] ) || ! empty( $scope['date_before'] ) ) {
            $after  = ! empty( $scope['date_after'] ) ? $scope['date_after'] : '';
            $before = ! empty( $scope['date_before'] ) ? $scope['date_before'] : '';
            $args['date_created'] = $after . '...' . $before;
        }

        $orders = wc_get_orders( $args );

        $count_args = $args;
        unset( $count_args['limit'], $count_args['offset'], $count_args['return'] );
        $count_args['limit']  = -1;
        $count_args['return'] = 'ids';
        $total = count( wc_get_orders( $count_args ) );

        return [ 'records' => $orders, 'total' => $total ];
    }

    /**
     * @param \WC_Order $record
     */
    public function resolve_record( $record ): array {
        if ( ! $record instanceof \WC_Order ) {
            return [];
        }

        $items = [];
        foreach ( $record->get_items() as $item ) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
        }

        $date_created  = $record->get_date_created();
        $date_modified = $record->get_date_modified();

        return [
            'ID'                   => $record->get_id(),
            'order_number'         => $record->get_order_number(),
            'status'                => $record->get_status(),
            'date_created'          => $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : '',
            'date_modified'         => $date_modified ? $date_modified->date( 'Y-m-d H:i:s' ) : '',
            'currency'              => $record->get_currency(),
            'total'                 => $record->get_total(),
            'subtotal'              => $record->get_subtotal(),
            'total_tax'             => $record->get_total_tax(),
            'shipping_total'        => $record->get_shipping_total(),
            'discount_total'        => $record->get_discount_total(),
            'payment_method'        => $record->get_payment_method(),
            'payment_method_title'  => $record->get_payment_method_title(),
            'customer_id'           => $record->get_customer_id(),
            'billing_first_name'    => $record->get_billing_first_name(),
            'billing_last_name'     => $record->get_billing_last_name(),
            'billing_email'         => $record->get_billing_email(),
            'billing_phone'         => $record->get_billing_phone(),
            'billing_address_1'     => $record->get_billing_address_1(),
            'billing_city'          => $record->get_billing_city(),
            'billing_state'         => $record->get_billing_state(),
            'billing_postcode'      => $record->get_billing_postcode(),
            'billing_country'       => $record->get_billing_country(),
            'shipping_first_name'   => $record->get_shipping_first_name(),
            'shipping_last_name'    => $record->get_shipping_last_name(),
            'shipping_address_1'    => $record->get_shipping_address_1(),
            'shipping_city'         => $record->get_shipping_city(),
            'shipping_state'        => $record->get_shipping_state(),
            'shipping_postcode'     => $record->get_shipping_postcode(),
            'shipping_country'      => $record->get_shipping_country(),
            'item_count'            => count( $items ),
            'item_summary'          => implode( '; ', $items ),
            'customer_note'         => $record->get_customer_note(),
        ];
    }

    /**
     * Creates/updates via WC_Order setters exclusively (never raw postmeta),
     * so HPOS's own cache/data-store invalidation stays consistent.
     */
    public function upsert_record( array $data, ?int $existing_id ): array {
        $order = $existing_id ? wc_get_order( $existing_id ) : new \WC_Order();
        if ( ! $order ) {
            return [ 'success' => false, 'id' => 0, 'message' => 'Order not found', 'action' => 'error' ];
        }

        $setter_map = [
            'status'               => 'set_status',
            'currency'             => 'set_currency',
            'payment_method'       => 'set_payment_method',
            'payment_method_title' => 'set_payment_method_title',
            'billing_first_name'   => 'set_billing_first_name',
            'billing_last_name'    => 'set_billing_last_name',
            'billing_email'        => 'set_billing_email',
            'billing_phone'        => 'set_billing_phone',
            'billing_address_1'    => 'set_billing_address_1',
            'billing_city'         => 'set_billing_city',
            'billing_state'        => 'set_billing_state',
            'billing_postcode'     => 'set_billing_postcode',
            'billing_country'      => 'set_billing_country',
            'shipping_first_name'  => 'set_shipping_first_name',
            'shipping_last_name'   => 'set_shipping_last_name',
            'shipping_address_1'   => 'set_shipping_address_1',
            'shipping_city'        => 'set_shipping_city',
            'shipping_state'       => 'set_shipping_state',
            'shipping_postcode'    => 'set_shipping_postcode',
            'shipping_country'     => 'set_shipping_country',
            'customer_note'        => 'set_customer_note',
        ];

        foreach ( $setter_map as $field => $setter ) {
            if ( array_key_exists( $field, $data ) && method_exists( $order, $setter ) ) {
                $order->{$setter}( $data[ $field ] );
            }
        }
        if ( ! empty( $data['customer_id'] ) ) {
            $order->set_customer_id( (int) $data['customer_id'] );
        }

        $order_id = $order->save();

        return [
            'success' => (bool) $order_id,
            'id'      => (int) $order_id,
            'message' => $existing_id ? 'updated' : 'created',
            'action'  => $existing_id ? 'update' : 'create',
        ];
    }

    public function find_existing_id( string $primary_key_field, $primary_value ): ?int {
        if ( $primary_value === null || $primary_value === '' ) {
            return null;
        }
        if ( $primary_key_field === 'ID' || $primary_key_field === 'order_number' ) {
            $order = wc_get_order( (int) $primary_value );
            return $order ? $order->get_id() : null;
        }
        return null;
    }

    public function required_capability(): string {
        return 'manage_woocommerce';
    }
}
