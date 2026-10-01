<?php
/**
 * Variable Product Manager
 *
 * Attribute-taxonomy mechanics shared by simple and variable products:
 * ensuring a WC global attribute taxonomy is registered, and applying
 * informational attributes to a simple product. Extracted from
 * MMI_Dynamic_Product_Importer as part of the god-class decomposition.
 *
 * NOTE: run_variable_flat()/run_variable_nested()/process_variable_group()/
 * upsert_variation() deliberately stayed on the importer rather than moving
 * here. They reach into nearly every piece of the importer's state ($stats,
 * promotions, identifier handling, taxonomy mapping, primary keys, the stock
 * override resolver) — extracting them would mean threading 7-8 callbacks
 * through this class's constructor, which is indirection, not decoupling.
 * This class holds only the two methods that are genuinely self-contained.
 *
 * @package MannMade\DataPipeline\Importers
 */

namespace MannMade\DataPipeline\Importers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Variable_Product_Manager {

    /** @var array */
    protected $attribute_config;

    /** @var callable */
    protected $logger;

    /**
     * @param array    $attribute_config  From MMI_Dynamic_Product_Importer::load_configuration().
     * @param callable $logger            function(string $message, string $type = 'info'): void
     */
    public function __construct( array $attribute_config, callable $logger ) {
        $this->attribute_config = $attribute_config;
        $this->logger           = $logger;
    }

    private function log( string $message, string $type = 'info' ): void {
        ( $this->logger )( $message, $type );
    }

    /**
     * Apply informational product attributes (Simple products).
     *
     * Reads source values from the raw supplier item, ensures WC global attribute
     * taxonomies and terms exist, then sets them on the product object.
     *
     * @param \WC_Product $product
     * @param array       $item    Raw source item.
     * @return bool True if any attributes were set.
     */
    public function apply_product_attributes( \WC_Product $product, array $item ): bool {
        $attr_defs = $this->attribute_config['attributes'] ?? [];
        if ( empty( $attr_defs ) ) {
            return false;
        }

        $existing  = $product->get_attributes();
        $new_attrs = [];
        $changed   = false;

        foreach ( $attr_defs as $def ) {
            $slug         = $def['wc_slug']      ?? '';
            $source_field = $def['source_field'] ?? '';
            $for_var      = ! empty( $def['for_variations'] );
            $visible      = isset( $def['visible'] ) ? (bool) $def['visible'] : true;

            if ( empty( $slug ) || empty( $source_field ) ) {
                continue;
            }

            $raw = \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $source_field );
            if ( $raw === null || $raw === '' ) {
                continue;
            }

            // Support comma-separated multi-values
            $values = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
            if ( empty( $values ) ) {
                continue;
            }

            // Ensure the global attribute taxonomy is registered in WC
            $this->ensure_wc_global_attribute( $slug, $def['label'] ?? ucwords( str_replace( '_', ' ', ltrim( $slug, 'pa_' ) ) ) );

            // Auto-detects ID vs. slug vs. name per value — see
            // MMI_Pipeline_Field_Resolver::resolve_term_ids_smart(). Previously
            // this only ever matched by exact name, creating a new term on
            // any miss (including a value that was actually meant as an ID
            // or slug from a re-imported export).
            $term_ids = \MMI_Pipeline_Field_Resolver::resolve_term_ids_smart( $slug, $values );
            if ( empty( $term_ids ) ) {
                continue;
            }

            $attr = new \WC_Product_Attribute();
            $attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
            $attr->set_name( $slug );
            $attr->set_options( $term_ids );
            $attr->set_position( count( $new_attrs ) );
            $attr->set_visible( $visible );
            $attr->set_variation( $for_var );

            $new_attrs[ $slug ] = $attr;
            $changed = true;
        }

        if ( $changed ) {
            $product->set_attributes( array_merge( $existing, $new_attrs ) );
        }

        return $changed;
    }

    /**
     * Ensure a WooCommerce global attribute taxonomy is registered.
     *
     * If it doesn't exist in the `woocommerce_attribute_taxonomies` table it is
     * created now; if the taxonomy is not yet registered in the current request
     * it is registered inline.
     *
     * @param string $slug  Full slug including `pa_` prefix.
     * @param string $label Human-readable label.
     */
    public function ensure_wc_global_attribute( string $slug, string $label ): void {
        if ( taxonomy_exists( $slug ) ) {
            return; // already registered in this request
        }

        $name = preg_replace( '/^pa_/', '', $slug );

        if ( function_exists( 'wc_attribute_taxonomy_id_by_name' )
             && wc_attribute_taxonomy_id_by_name( $name ) ) {
            // Exists in DB but not yet registered — register it now.
            register_taxonomy( $slug, [ 'product' ], [
                'label'        => $label,
                'hierarchical' => false,
                'rewrite'      => [ 'slug' => $slug ],
                'public'       => true,
            ] );
            return;
        }

        // Create and register
        if ( function_exists( 'wc_create_attribute' ) ) {
            $result = wc_create_attribute( [
                'name'         => $label,
                'slug'         => $name,
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ] );
            if ( ! is_wp_error( $result ) ) {
                $this->log( "Created WC attribute taxonomy: {$slug} ({$label})" );
            }
        }

        if ( ! taxonomy_exists( $slug ) ) {
            register_taxonomy( $slug, [ 'product' ], [
                'label'        => $label,
                'hierarchical' => false,
                'rewrite'      => [ 'slug' => $slug ],
                'public'       => true,
            ] );
        }
    }
}
