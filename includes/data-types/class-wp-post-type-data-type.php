<?php
/**
 * Generic WP Post Type Data Type Handler (Phase 3)
 *
 * One class, parameterized by $post_type, covers Post, Page, and every
 * public Custom Post Type in the install (excluding product/shop_coupon/
 * shop_order/product_variation, which have dedicated handlers with richer
 * domain logic — see MMI_Data_Type_Registry::boot()). This is the "collapse
 * N types into 1 parameterized class" half of the handler-count minimization
 * goal from the architecture plan.
 *
 * Field schema is built dynamically per instance: core post fields common to
 * every post type, plus that post type's registered taxonomies, plus any
 * scalar (non-array/non-serialized) meta keys discovered by sampling a
 * handful of existing posts of this type. The meta-key discovery is
 * sample-based — a meta key that exists on zero of the sampled posts (e.g.
 * a rarely-used custom field) won't appear in the schema. This mirrors the
 * existing technique in class-pipeline-admin.php's build_import_settings_data(),
 * which already discovers custom product taxonomies the same way at render time.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_WP_Post_Type_Data_Type implements MMI_Data_Type_Handler {

    private string $post_type;

    public function __construct( string $post_type ) {
        $this->post_type = $post_type;
    }

    public function get_type_key(): string {
        if ( $this->post_type === 'post' || $this->post_type === 'page' ) {
            return $this->post_type;
        }
        return 'cpt:' . $this->post_type;
    }

    public function get_label(): string {
        $obj = get_post_type_object( $this->post_type );
        $label = $obj ? $obj->labels->name : $this->post_type;
        return $this->post_type === 'post' || $this->post_type === 'page'
            ? $label
            : sprintf( '%s (Custom Post Type)', $label );
    }

    public function get_field_schema(): array {
        $schema = [
            'ID'           => [ 'label' => 'ID',          'type' => 'integer', 'group' => 'core', 'required' => true ],
            'post_title'   => [ 'label' => 'Title',        'type' => 'string',  'group' => 'core',  'required' => true ],
            'post_content' => [ 'label' => 'Content',      'type' => 'longtext', 'group' => 'core', 'required' => false ],
            'post_excerpt' => [ 'label' => 'Excerpt',      'type' => 'string',  'group' => 'core',  'required' => false ],
            'post_status'  => [ 'label' => 'Status',       'type' => 'string',  'group' => 'core',  'required' => false ],
            'post_date'    => [ 'label' => 'Date',         'type' => 'datetime', 'group' => 'core', 'required' => false ],
            'post_name'    => [ 'label' => 'Slug',         'type' => 'string',  'group' => 'core',  'required' => false ],
            'post_author'  => [ 'label' => 'Author (user login)', 'type' => 'string', 'group' => 'core', 'required' => false ],
        ];

        $schema += MMI_Taxonomy_Field_Helper::schema_fields( $this->post_type );

        foreach ( $this->discover_meta_keys() as $meta_key ) {
            $schema[ 'meta:' . $meta_key ] = [
                'label'    => $meta_key,
                'type'     => 'string',
                'group'    => 'meta',
                'required' => false,
            ];
        }

        return $schema;
    }

    public function get_id_field(): string {
        return 'ID';
    }

    public function get_edit_url( $id ): string {
        return admin_url( 'post.php?post=' . absint( $id ) . '&action=edit' );
    }

    /**
     * Sample-based discovery of scalar custom-field meta keys used by this
     * post type — delegates to the shared helper so the Export Step 1
     * "Custom Field Filters" datalist (export-scope-fields.php) uses the
     * exact same discovery logic as the field schema built here.
     *
     * @return string[]
     */
    private function discover_meta_keys(): array {
        return MMI_Meta_Key_Discovery::discover_for_post_type( $this->post_type );
    }

    /**
     * @param array $scope Supports: status, search, one tax_query clause per
     *                      'tax_{taxonomy}' key (array of term slugs — see
     *                      export-scope-fields.php's per-taxonomy tree), and
     *                      'meta_conditions' (array of key/operator/value
     *                      rows, built into a meta_query via
     *                      MMI_Pipeline_Meta_Query_Builder). The legacy single
     *                      'taxonomy' + 'term' pair from before every
     *                      taxonomy got its own filter is still honored for
     *                      profiles saved before this scope UI existed.
     */
    public function query_records( array $scope, int $limit, int $offset ): array {
        $args = [
            'post_type'      => $this->post_type,
            'post_status'    => ! empty( $scope['status'] ) ? $scope['status'] : 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ];
        if ( ! empty( $scope['search'] ) ) {
            $args['s'] = $scope['search'];
        }

        $tax_query = [];
        if ( ! empty( $scope['taxonomy'] ) && ! empty( $scope['term'] ) ) {
            $tax_query[] = [
                'taxonomy' => $scope['taxonomy'],
                'field'    => is_numeric( $scope['term'] ) ? 'term_id' : 'slug',
                'terms'    => $scope['term'],
            ];
        }
        foreach ( $scope as $key => $value ) {
            if ( strpos( $key, 'tax_' ) === 0 && ! empty( $value ) ) {
                $tax_query[] = [
                    'taxonomy' => substr( $key, 4 ),
                    'field'    => 'slug',
                    'terms'    => MMI_Taxonomy_Tree_Renderer::normalize_scope_terms( $value ),
                ];
            }
        }
        if ( ! empty( $tax_query ) ) {
            if ( count( $tax_query ) > 1 ) {
                $tax_query['relation'] = 'AND';
            }
            $args['tax_query'] = $tax_query;
        }

        if ( ! empty( $scope['meta_conditions'] ) && is_array( $scope['meta_conditions'] ) ) {
            $meta_query = MMI_Pipeline_Meta_Query_Builder::build( $scope['meta_conditions'] );
            if ( ! empty( $meta_query ) ) {
                $args['meta_query'] = $meta_query;
            }
        }

        $query = new \WP_Query( $args );

        $count_args = $args;
        unset( $count_args['posts_per_page'], $count_args['offset'] );
        $count_args['posts_per_page'] = -1;
        $count_args['fields']         = 'ids';
        $count_query = new \WP_Query( $count_args );

        return [ 'records' => $query->posts, 'total' => count( $count_query->posts ) ];
    }

    /**
     * @param \WP_Post $record
     */
    public function resolve_record( $record ): array {
        if ( ! $record instanceof \WP_Post ) {
            return [];
        }

        $author = get_userdata( (int) $record->post_author );

        $flat = [
            'ID'           => $record->ID,
            'post_title'   => $record->post_title,
            'post_content' => $record->post_content,
            'post_excerpt' => $record->post_excerpt,
            'post_status'  => $record->post_status,
            'post_date'    => $record->post_date,
            'post_name'    => $record->post_name,
            'post_author'  => $author ? $author->user_login : '',
        ];

        $flat += MMI_Taxonomy_Field_Helper::resolve_fields( $record->ID, $this->post_type );

        foreach ( $this->discover_meta_keys() as $meta_key ) {
            $flat[ 'meta:' . $meta_key ] = get_post_meta( $record->ID, $meta_key, true );
        }

        return $flat;
    }

    public function upsert_record( array $data, ?int $existing_id ): array {
        $post_args = [ 'post_type' => $this->post_type ];

        $core_map = [
            'post_title'   => 'post_title',
            'post_content' => 'post_content',
            'post_excerpt' => 'post_excerpt',
            'post_status'  => 'post_status',
            'post_name'    => 'post_name',
            'post_date'    => 'post_date',
        ];
        foreach ( $core_map as $field => $wp_key ) {
            if ( array_key_exists( $field, $data ) ) {
                $post_args[ $wp_key ] = $data[ $field ];
            }
        }
        if ( ! isset( $post_args['post_status'] ) ) {
            $post_args['post_status'] = 'publish';
        }

        if ( $existing_id ) {
            $post_args['ID'] = $existing_id;
            $post_id = wp_update_post( $post_args, true );
        } else {
            $post_id = wp_insert_post( $post_args, true );
        }

        if ( is_wp_error( $post_id ) ) {
            return [ 'success' => false, 'id' => 0, 'message' => $post_id->get_error_message(), 'action' => 'error' ];
        }

        // If a row carries both 'tax:{taxonomy}' (names) and its
        // 'tax:{taxonomy}:id' companion (see MMI_Taxonomy_Field_Helper — this
        // plugin's own exports now produce both), the unambiguous ID column
        // wins for that taxonomy and the name column is skipped, rather than
        // resolving the same taxonomy twice from two possibly-inconsistent
        // values (e.g. a term renamed since the export was taken).
        $tax_id_taxonomies = [];
        foreach ( $data as $field => $value ) {
            if ( preg_match( '/^tax:(.+):id$/', $field, $m ) ) {
                $tax_id_taxonomies[] = $m[1];
            }
        }

        foreach ( $data as $field => $value ) {
            if ( preg_match( '/^tax:(.+):id$/', $field, $m ) ) {
                $term_ids = MMI_Pipeline_Field_Resolver::resolve_term_ids_smart( $m[1], $value );
                wp_set_object_terms( $post_id, $term_ids, $m[1] );
            } elseif ( strpos( $field, 'tax:' ) === 0 ) {
                $taxonomy = substr( $field, 4 );
                if ( in_array( $taxonomy, $tax_id_taxonomies, true ) ) {
                    continue;
                }
                // Auto-detects ID vs. slug vs. name per value — see
                // MMI_Pipeline_Field_Resolver::resolve_term_ids_smart().
                // Previously this passed the raw comma-split value straight
                // to wp_set_object_terms(), which treats every non-numeric
                // entry as a name (creating a term on any miss) and every
                // numeric entry as an ID without validating it exists.
                $term_ids = MMI_Pipeline_Field_Resolver::resolve_term_ids_smart( $taxonomy, $value );
                wp_set_object_terms( $post_id, $term_ids, $taxonomy );
            } elseif ( strpos( $field, 'meta:' ) === 0 ) {
                update_post_meta( $post_id, substr( $field, 5 ), $value );
            }
        }

        return [
            'success' => true,
            'id'      => (int) $post_id,
            'message' => $existing_id ? 'updated' : 'created',
            'action'  => $existing_id ? 'update' : 'create',
        ];
    }

    public function find_existing_id( string $primary_key_field, $primary_value ): ?int {
        if ( $primary_value === null || $primary_value === '' ) {
            return null;
        }
        if ( $primary_key_field === 'ID' ) {
            $post = get_post( (int) $primary_value );
            return ( $post && $post->post_type === $this->post_type ) ? (int) $post->ID : null;
        }
        if ( $primary_key_field === 'post_name' ) {
            $posts = get_posts( [
                'post_type'      => $this->post_type,
                'name'           => (string) $primary_value,
                'posts_per_page' => 1,
                'post_status'    => 'any',
                'fields'         => 'ids',
            ] );
            return ! empty( $posts ) ? (int) $posts[0] : null;
        }
        return null;
    }

    public function required_capability(): string {
        $obj = get_post_type_object( $this->post_type );
        return $obj && $obj->cap->edit_posts ? $obj->cap->edit_posts : 'edit_posts';
    }
}
