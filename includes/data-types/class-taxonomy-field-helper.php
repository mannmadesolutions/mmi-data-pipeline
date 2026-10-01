<?php
/**
 * Taxonomy Field Helper
 *
 * Shared by every WP_Post-backed data type handler (product, order, coupon,
 * generic post type) that needs to expose "every taxonomy registered for
 * this post type" as its own `tax:{slug}` export/preview column — not just
 * a hardcoded shortlist. Originally only MMI_WP_Post_Type_Data_Type did
 * this (inline); product/order/coupon are pulled onto the same behavior
 * here rather than duplicating the loop in each handler.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Taxonomy_Field_Helper {

    /**
     * Field schema entries for every taxonomy registered on $post_type.
     *
     * @param string   $post_type
     * @param string[] $exclude   Taxonomy names already covered by the
     *                            handler's own hardcoded fields (e.g.
     *                            product_cat/product_tag) — skipped here
     *                            to avoid a duplicate/conflicting field key.
     * @return array<string, array{label:string,type:string,group:string,required:bool}>
     */
    public static function schema_fields( string $post_type, array $exclude = [] ): array {
        $fields = [];
        foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
            if ( in_array( $tax->name, $exclude, true ) ) {
                continue;
            }
            $label = $tax->label ?: $tax->name;
            $fields[ 'tax:' . $tax->name ] = [
                'label'    => $label,
                'type'     => 'taxonomy',
                'group'    => 'taxonomy',
                'required' => false,
            ];
            // Term-ID companion column, alongside the names column above —
            // re-importing a "Product Categories" export and reassigning
            // terms from it needs a value re-import can match unambiguously
            // (a name can collide/get renamed; the ID can't) — see
            // MMI_Pipeline_Field_Resolver::resolve_term_ids_smart(), which
            // this feeds on the way back in.
            $fields[ 'tax:' . $tax->name . ':id' ] = [
                'label'    => $label . ' (ID)',
                'type'     => 'taxonomy_ids',
                'group'    => 'taxonomy',
                'required' => false,
            ];
        }
        return $fields;
    }

    /**
     * Flattened `tax:{slug} => "Term A, Term B"` (+ `tax:{slug}:id => "12, 34"`)
     * values for one post, covering every taxonomy registered on $post_type.
     *
     * @param int      $post_id
     * @param string   $post_type
     * @param string[] $exclude   Same list passed to schema_fields() so the
     *                            two stay in sync.
     * @return array<string, string>
     */
    public static function resolve_fields( int $post_id, string $post_type, array $exclude = [] ): array {
        $flat = [];
        foreach ( get_object_taxonomies( $post_type ) as $tax ) {
            if ( in_array( $tax, $exclude, true ) ) {
                continue;
            }
            $terms    = wp_get_post_terms( $post_id, $tax, [ 'fields' => 'names' ] );
            $term_ids = wp_get_post_terms( $post_id, $tax, [ 'fields' => 'ids' ] );
            $flat[ 'tax:' . $tax ]          = is_wp_error( $terms ) ? '' : implode( ', ', $terms );
            $flat[ 'tax:' . $tax . ':id' ]  = is_wp_error( $term_ids ) ? '' : implode( ', ', $term_ids );
        }
        return $flat;
    }
}
