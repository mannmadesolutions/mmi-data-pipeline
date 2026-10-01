<?php
/**
 * User Data Type Handler (Phase 4)
 *
 * Generic WordPress users (any/all roles) — distinct from
 * MMI_Customer_Data_Type, which is dedicated to WooCommerce customers with
 * billing/shipping/lifetime-spend fields that would be noise on a plain
 * author/editor export. This handler exposes only core WP_User fields plus
 * roles; use the 'role' scope filter to narrow to a specific role if needed
 * (e.g. exporting just Editors), but it is not restricted to 'customer' by
 * default the way MMI_Customer_Data_Type is.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_User_Data_Type implements MMI_Data_Type_Handler {

    public function get_type_key(): string {
        return 'user';
    }

    public function get_label(): string {
        return __( 'WordPress Users (all roles)', 'mmi-data-pipeline' );
    }

    public function get_field_schema(): array {
        return [
            'ID'              => [ 'label' => 'User ID',        'type' => 'integer', 'group' => 'core', 'required' => true ],
            'user_login'      => [ 'label' => 'Username',       'type' => 'string',  'group' => 'core',  'required' => true ],
            'user_email'      => [ 'label' => 'Email',          'type' => 'string',  'group' => 'core',  'required' => true ],
            'display_name'    => [ 'label' => 'Display Name',   'type' => 'string',  'group' => 'core',  'required' => false ],
            'first_name'      => [ 'label' => 'First Name',     'type' => 'string',  'group' => 'core',  'required' => false ],
            'last_name'       => [ 'label' => 'Last Name',      'type' => 'string',  'group' => 'core',  'required' => false ],
            'user_registered' => [ 'label' => 'Registered Date', 'type' => 'datetime', 'group' => 'core', 'required' => false ],
            'roles'           => [ 'label' => 'Roles',          'type' => 'string',  'group' => 'core',  'required' => false ],
        ];
    }

    public function get_id_field(): string {
        return 'ID';
    }

    public function get_edit_url( $id ): string {
        return admin_url( 'user-edit.php?user_id=' . absint( $id ) );
    }

    /**
     * @param array $scope Supports: role (single role slug or empty for any), search.
     */
    public function query_records( array $scope, int $limit, int $offset ): array {
        $args = [
            'number'  => $limit,
            'offset'  => $offset,
            'fields'  => 'all',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ];
        if ( ! empty( $scope['role'] ) ) {
            $args['role'] = $scope['role'];
        }
        if ( ! empty( $scope['search'] ) ) {
            $args['search']         = '*' . $scope['search'] . '*';
            $args['search_columns'] = [ 'user_email', 'user_login', 'display_name' ];
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

        return [
            'ID'              => $record->ID,
            'user_login'      => $record->user_login,
            'user_email'      => $record->user_email,
            'display_name'    => $record->display_name,
            'first_name'      => $record->first_name,
            'last_name'       => $record->last_name,
            'user_registered' => $record->user_registered,
            'roles'           => implode( ', ', $record->roles ),
        ];
    }

    public function upsert_record( array $data, ?int $existing_id ): array {
        if ( $existing_id ) {
            $user_id = wp_update_user( array_filter( [
                'ID'           => $existing_id,
                'user_email'   => $data['user_email']   ?? null,
                'display_name' => $data['display_name'] ?? null,
                'first_name'   => $data['first_name']   ?? null,
                'last_name'    => $data['last_name']    ?? null,
            ] ) );
        } else {
            if ( empty( $data['user_email'] ) ) {
                return [ 'success' => false, 'id' => 0, 'message' => 'user_email is required to create a user', 'action' => 'error' ];
            }
            $login = $data['user_login'] ?? $data['user_email'];
            $user_id = wp_insert_user( [
                'user_login'   => $login,
                'user_email'   => $data['user_email'],
                'user_pass'    => wp_generate_password( 20 ),
                'display_name' => $data['display_name'] ?? $login,
                'first_name'   => $data['first_name']   ?? '',
                'last_name'    => $data['last_name']    ?? '',
            ] );
        }

        if ( is_wp_error( $user_id ) ) {
            return [ 'success' => false, 'id' => 0, 'message' => $user_id->get_error_message(), 'action' => 'error' ];
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
        return 'list_users';
    }
}
