<?php
/**
 * Customer Data Type Handler (Phase 2)
 *
 * Dedicated handler, not a filtered reuse of a generic User handler — see
 * the plan (spicy-sparking-llama.md section "Customer handler") for why:
 * WC customers need lifetime-spend/order-count aggregate fields a generic
 * user export shouldn't carry, and billing/shipping is WC-specific user
 * meta, not a core user field.
 *
 * Scope note (Phase 2): this handler covers REGISTERED customers only
 * (wp_users rows with the 'customer' role). Guest checkout customers (no
 * user account — identified only via order billing meta / the
 * wc_customer_lookup table) are NOT covered yet; exporting them needs a
 * separate query path against that lookup table, deferred to keep this
 * phase's scope bounded. Flag this to the user if a "customer export" is
 * missing guest orders.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Customer_Data_Type implements MMI_Data_Type_Handler {

    public function get_type_key(): string {
        return 'customer';
    }

    public function get_label(): string {
        return __( 'WooCommerce Customers (registered)', 'mmi-data-pipeline' );
    }

    public function get_field_schema(): array {
        return [
            'ID'                  => [ 'label' => 'Customer ID',    'type' => 'integer', 'group' => 'core',     'required' => true ],
            'user_email'          => [ 'label' => 'Email',          'type' => 'string',  'group' => 'core',      'required' => true ],
            'user_login'          => [ 'label' => 'Username',       'type' => 'string',  'group' => 'core',      'required' => false ],
            'display_name'        => [ 'label' => 'Display Name',   'type' => 'string',  'group' => 'core',      'required' => false ],
            'user_registered'     => [ 'label' => 'Registered Date', 'type' => 'datetime', 'group' => 'core',    'required' => false ],
            'order_count'         => [ 'label' => 'Order Count',    'type' => 'integer',  'group' => 'stats',    'required' => false ],
            'total_spent'         => [ 'label' => 'Lifetime Spend', 'type' => 'decimal',  'group' => 'stats',    'required' => false ],
            'billing_first_name'  => [ 'label' => 'Billing First Name', 'type' => 'string', 'group' => 'billing', 'required' => false ],
            'billing_last_name'   => [ 'label' => 'Billing Last Name',  'type' => 'string', 'group' => 'billing', 'required' => false ],
            'billing_phone'       => [ 'label' => 'Billing Phone',  'type' => 'string',  'group' => 'billing',   'required' => false ],
            'billing_address_1'   => [ 'label' => 'Billing Address 1', 'type' => 'string', 'group' => 'billing', 'required' => false ],
            'billing_city'        => [ 'label' => 'Billing City',   'type' => 'string',  'group' => 'billing',   'required' => false ],
            'billing_state'       => [ 'label' => 'Billing State',  'type' => 'string',  'group' => 'billing',   'required' => false ],
            'billing_postcode'    => [ 'label' => 'Billing Postcode', 'type' => 'string', 'group' => 'billing',  'required' => false ],
            'billing_country'     => [ 'label' => 'Billing Country','type' => 'string',  'group' => 'billing',   'required' => false ],
            'shipping_first_name' => [ 'label' => 'Shipping First Name', 'type' => 'string', 'group' => 'shipping', 'required' => false ],
            'shipping_last_name'  => [ 'label' => 'Shipping Last Name',  'type' => 'string', 'group' => 'shipping', 'required' => false ],
            'shipping_address_1'  => [ 'label' => 'Shipping Address 1', 'type' => 'string', 'group' => 'shipping', 'required' => false ],
            'shipping_city'       => [ 'label' => 'Shipping City',  'type' => 'string',  'group' => 'shipping',   'required' => false ],
            'shipping_state'      => [ 'label' => 'Shipping State', 'type' => 'string',  'group' => 'shipping',   'required' => false ],
            'shipping_postcode'   => [ 'label' => 'Shipping Postcode', 'type' => 'string', 'group' => 'shipping', 'required' => false ],
            'shipping_country'    => [ 'label' => 'Shipping Country', 'type' => 'string', 'group' => 'shipping',  'required' => false ],
        ];
    }

    public function get_id_field(): string {
        return 'ID';
    }

    public function get_edit_url( $id ): string {
        return admin_url( 'user-edit.php?user_id=' . absint( $id ) );
    }

    /**
     * @param array $scope Supports: registered_after / registered_before (Y-m-d),
     *                      search (email/name).
     */
    public function query_records( array $scope, int $limit, int $offset ): array {
        $args = [
            'role'   => 'customer',
            'number' => $limit,
            'offset' => $offset,
            'fields' => 'all',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ];

        if ( ! empty( $scope['search'] ) ) {
            $args['search'] = '*' . $scope['search'] . '*';
            $args['search_columns'] = [ 'user_email', 'user_login', 'display_name' ];
        }
        if ( ! empty( $scope['registered_after'] ) || ! empty( $scope['registered_before'] ) ) {
            $args['date_query'] = [ [
                'after'  => ! empty( $scope['registered_after'] ) ? $scope['registered_after'] : null,
                'before' => ! empty( $scope['registered_before'] ) ? $scope['registered_before'] : null,
            ] ];
        }

        $query = new \WP_User_Query( $args );
        $users = $query->get_results();

        $count_args = $args;
        unset( $count_args['number'], $count_args['offset'], $count_args['fields'] );
        $count_args['count_total'] = true;
        $count_args['fields']      = 'ID';
        $count_query = new \WP_User_Query( $count_args );
        $total = (int) $count_query->get_total();

        return [ 'records' => $users, 'total' => $total ];
    }

    /**
     * @param \WP_User $record
     */
    public function resolve_record( $record ): array {
        if ( ! $record instanceof \WP_User ) {
            return [];
        }

        $id = $record->ID;
        $meta_fields = [
            'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_address_1',
            'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
            'shipping_first_name', 'shipping_last_name', 'shipping_address_1',
            'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
        ];
        $meta = [];
        foreach ( $meta_fields as $key ) {
            $meta[ $key ] = get_user_meta( $id, $key, true );
        }

        return array_merge( [
            'ID'              => $id,
            'user_email'      => $record->user_email,
            'user_login'      => $record->user_login,
            'display_name'    => $record->display_name,
            'user_registered' => $record->user_registered,
            'order_count'     => function_exists( 'wc_get_customer_order_count' ) ? wc_get_customer_order_count( $id ) : 0,
            'total_spent'     => function_exists( 'wc_get_customer_total_spent' ) ? wc_get_customer_total_spent( $id ) : 0,
        ], $meta );
    }

    /**
     * Writes core user fields via wp_insert_user()/wp_update_user() and
     * billing/shipping via update_user_meta() — the same storage WooCommerce
     * itself uses for customer address data (plain user meta, not a rich
     * domain-object contract like Product/Coupon/Order need).
     */
    public function upsert_record( array $data, ?int $existing_id ): array {
        $meta_fields = [
            'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_address_1',
            'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
            'shipping_first_name', 'shipping_last_name', 'shipping_address_1',
            'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country',
        ];

        if ( $existing_id ) {
            $user_id = wp_update_user( [
                'ID'           => $existing_id,
                'user_email'   => $data['user_email']   ?? null,
                'display_name' => $data['display_name'] ?? null,
            ] );
        } else {
            if ( empty( $data['user_email'] ) ) {
                return [ 'success' => false, 'id' => 0, 'message' => 'user_email is required to create a customer', 'action' => 'error' ];
            }
            $login = $data['user_login'] ?? $data['user_email'];
            $user_id = wp_insert_user( [
                'user_login'   => $login,
                'user_email'   => $data['user_email'],
                'user_pass'    => wp_generate_password( 20 ),
                'display_name' => $data['display_name'] ?? $login,
                'role'         => 'customer',
            ] );
        }

        if ( is_wp_error( $user_id ) ) {
            return [ 'success' => false, 'id' => 0, 'message' => $user_id->get_error_message(), 'action' => 'error' ];
        }

        foreach ( $meta_fields as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                update_user_meta( $user_id, $field, $data[ $field ] );
            }
        }

        return [
            'success' => true,
            'id'      => (int) $user_id,
            'message' => $existing_id ? 'updated' : 'created',
            'action'  => $existing_id ? 'update' : 'create',
        ];
    }

    public function find_existing_id( string $primary_key_field, $primary_value ): ?int {
        if ( $primary_value === null || $primary_value === '' ) {
            return null;
        }
        if ( $primary_key_field === 'user_email' ) {
            $user = get_user_by( 'email', (string) $primary_value );
            return $user ? (int) $user->ID : null;
        }
        if ( $primary_key_field === 'user_login' ) {
            $user = get_user_by( 'login', (string) $primary_value );
            return $user ? (int) $user->ID : null;
        }
        if ( $primary_key_field === 'ID' ) {
            $user = get_user_by( 'id', (int) $primary_value );
            return $user ? (int) $user->ID : null;
        }
        return null;
    }

    public function required_capability(): string {
        return 'manage_woocommerce';
    }
}
