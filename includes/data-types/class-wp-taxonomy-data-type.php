<?php
/**
 * Generic WP Taxonomy Data Type Handler (Phase 3)
 *
 * One class, parameterized by $taxonomy, covers every registered taxonomy
 * in the install — including product_cat/product_tag (WooCommerce's own
 * taxonomies) and any custom taxonomy — the "collapse N types into 1
 * parameterized class" half of the handler-count minimization goal, same
 * pattern as MMI_WP_Post_Type_Data_Type.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_WP_Taxonomy_Data_Type implements MMI_Data_Type_Handler {

    private string $taxonomy;
    private const META_SAMPLE_SIZE = 10;

    public function __construct( string $taxonomy ) {
        $this->taxonomy = $taxonomy;
    }

    public function get_type_key(): string {
        return 'taxonomy:' . $this->taxonomy;
    }

    public function get_label(): string {
        $obj = get_taxonomy( $this->taxonomy );
        $label = $obj ? $obj->labels->name : $this->taxonomy;
        return sprintf( '%s (Taxonomy)', $label );
    }

    public function get_field_schema(): array {
        $schema = [
            'term_id'     => [ 'label' => 'Term ID',     'type' => 'integer', 'group' => 'core', 'required' => true ],
            'name'        => [ 'label' => 'Name',        'type' => 'string',  'group' => 'core',  'required' => true ],
            'slug'        => [ 'label' => 'Slug',        'type' => 'string',  'group' => 'core',  'required' => false ],
            'description' => [ 'label' => 'Description', 'type' => 'longtext', 'group' => 'core', 'required' => false ],
            'parent'      => [ 'label' => 'Parent Term ID', 'type' => 'integer', 'group' => 'core', 'required' => false ],
            'count'       => [ 'label' => 'Post Count',  'type' => 'integer',  'group' => 'core',  'required' => false ],
        ];

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
        return 'term_id';
    }

    public function get_edit_url( $id ): string {
        return add_query_arg(
            [ 'taxonomy' => $this->taxonomy, 'tag_ID' => absint( $id ) ],
            admin_url( 'term.php' )
        );
    }

    /**
     * @return string[]
     */
    private function discover_meta_keys(): array {
        $sample = get_terms( [
            'taxonomy'   => $this->taxonomy,
            'number'     => self::META_SAMPLE_SIZE,
            'hide_empty' => false,
            'fields'     => 'ids',
        ] );
        if ( is_wp_error( $sample ) ) {
            return [];
        }

        $keys = [];
        foreach ( $sample as $term_id ) {
            $meta = get_term_meta( $term_id );
            foreach ( $meta as $key => $values ) {
                if ( isset( $keys[ $key ] ) ) {
                    continue;
                }
                $value = $values[0] ?? '';
                if ( is_serialized( $value ) || is_array( maybe_unserialize( $value ) ) ) {
                    continue;
                }
                $keys[ $key ] = true;
            }
        }

        return array_keys( $keys );
    }

    /**
     * @param array $scope Supports: search, hide_empty (bool), terms (string[] of
     *                     slugs — from the hierarchical checkbox tree on
     *                     hierarchical taxonomies, or its large-taxonomy
     *                     comma-separated-slug fallback; see
     *                     MMI_Taxonomy_Tree_Renderer::render_tree_or_fallback()).
     */
    public function query_records( array $scope, int $limit, int $offset ): array {
        $args = [
            'taxonomy'   => $this->taxonomy,
            'number'     => $limit,
            'offset'     => $offset,
            'hide_empty' => ! empty( $scope['hide_empty'] ),
            'orderby'    => 'id',
            'order'      => 'ASC',
        ];
        if ( ! empty( $scope['search'] ) ) {
            $args['search'] = $scope['search'];
        }
        $selected_terms = MMI_Taxonomy_Tree_Renderer::normalize_scope_terms( $scope['terms'] ?? [] );
        if ( ! empty( $selected_terms ) ) {
            $args['slug'] = $selected_terms;
        }

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            $terms = [];
        }

        $count_args = $args;
        unset( $count_args['number'], $count_args['offset'] );
        $count_terms = get_terms( $count_args );
        $total = is_wp_error( $count_terms ) ? 0 : count( $count_terms );

        return [ 'records' => $terms, 'total' => $total ];
    }

    /**
     * @param \WP_Term $record
     */
    public function resolve_record( $record ): array {
        if ( ! $record instanceof \WP_Term ) {
            return [];
        }

        $flat = [
            'term_id'     => $record->term_id,
            'name'        => $record->name,
            'slug'        => $record->slug,
            'description' => $record->description,
            'parent'      => $record->parent,
            'count'       => $record->count,
        ];

        foreach ( $this->discover_meta_keys() as $meta_key ) {
            $flat[ 'meta:' . $meta_key ] = get_term_meta( $record->term_id, $meta_key, true );
        }

        return $flat;
    }

    public function upsert_record( array $data, ?int $existing_id ): array {
        $args = [];
        if ( array_key_exists( 'description', $data ) ) {
            $args['description'] = $data['description'];
        }
        if ( array_key_exists( 'slug', $data ) ) {
            $args['slug'] = $data['slug'];
        }
        if ( array_key_exists( 'parent', $data ) ) {
            $args['parent'] = (int) $data['parent'];
        }

        $name = $data['name'] ?? '';

        if ( $existing_id ) {
            $result = wp_update_term( $existing_id, $this->taxonomy, array_merge( $args, $name !== '' ? [ 'name' => $name ] : [] ) );
        } else {
            if ( $name === '' ) {
                return [ 'success' => false, 'id' => 0, 'message' => 'name is required to create a term', 'action' => 'error' ];
            }
            $result = wp_insert_term( $name, $this->taxonomy, $args );
        }

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'id' => 0, 'message' => $result->get_error_message(), 'action' => 'error' ];
        }

        $term_id = (int) $result['term_id'];

        foreach ( $data as $field => $value ) {
            if ( strpos( $field, 'meta:' ) === 0 ) {
                update_term_meta( $term_id, substr( $field, 5 ), $value );
            }
        }

        return [
            'success' => true,
            'id'      => $term_id,
            'message' => $existing_id ? 'updated' : 'created',
            'action'  => $existing_id ? 'update' : 'create',
        ];
    }

    public function find_existing_id( string $primary_key_field, $primary_value ): ?int {
        if ( $primary_value === null || $primary_value === '' ) {
            return null;
        }
        if ( $primary_key_field === 'term_id' ) {
            $term = get_term( (int) $primary_value, $this->taxonomy );
            return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : null;
        }
        $term = get_term_by( $primary_key_field === 'slug' ? 'slug' : 'name', (string) $primary_value, $this->taxonomy );
        return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : null;
    }

    public function required_capability(): string {
        $obj = get_taxonomy( $this->taxonomy );
        return $obj && $obj->cap->manage_terms ? $obj->cap->manage_terms : 'manage_categories';
    }
}
