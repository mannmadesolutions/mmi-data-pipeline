<?php
/**
 * Taxonomy Mapping Handler
 *
 * Post-save taxonomy term assignment (brand/category mapping tables) and
 * profile identifier scope filtering/auto-tagging. Extracted from
 * MMI_Dynamic_Product_Importer as part of the god-class decomposition.
 *
 * @package MannMade\DataPipeline\Importers
 */

namespace MannMade\DataPipeline\Importers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Taxonomy_Mapping_Handler {

    /** @var array */
    protected $field_mappings;

    /** @var string */
    protected $supplier_name;

    /** @var string  'all_products' or 'by_identifier' */
    protected $product_scope;

    /** @var array|null */
    protected $product_identifier;

    /** @var string Import Profile ID — see resolve_tax_mapping()'s profile-aware tiering. */
    protected $profile_id;

    /**
     * Per-source Taxonomy Mapping toggle (2026-08-31) — resolved once here
     * (one instance per supplier per import run) rather than re-checked per
     * product in apply_taxonomy_mappings(), which runs once per product in
     * this supplier's batch. See MMI_Pipeline_Admin::get_configured_suppliers()'s
     * 'taxonomy_mapping_enabled' key.
     *
     * @var bool
     */
    protected $taxonomy_mapping_enabled;

    public function __construct( array $field_mappings, string $supplier_name, string $product_scope, ?array $product_identifier, string $profile_id = '' ) {
        $this->field_mappings      = $field_mappings;
        $this->supplier_name       = $supplier_name;
        $this->product_scope       = $product_scope;
        $this->product_identifier  = $product_identifier;
        $this->profile_id          = $profile_id;

        $configured = class_exists( '\\MMI_Pipeline_Admin' ) ? \MMI_Pipeline_Admin::get_configured_suppliers() : [];
        $this->taxonomy_mapping_enabled = ! isset( $configured[ $supplier_name ] ) || ( $configured[ $supplier_name ]['taxonomy_mapping_enabled'] ?? true );
    }

    /**
     * Check whether a product belongs to this profile's identifier scope.
     *
     * Returns true when:
     * - product_scope is 'all_products' (no filtering), or
     * - identifier config is missing / incomplete, or
     * - the product genuinely matches the taxonomy term / post_meta condition.
     *
     * @param int $product_id WooCommerce product post ID.
     * @return bool
     */
    public function product_matches_identifier( $product_id ) {
        if ( $this->product_scope !== 'by_identifier' || empty( $this->product_identifier ) ) {
            return true;
        }

        $type   = $this->product_identifier['storage_type'] ?? '';
        $config = $this->product_identifier['storage_config'] ?? [];

        if ( $type === 'taxonomy' ) {
            $slug        = $config['taxonomy_slug'] ?? '';
            $term_lookup = $config['term_slug_or_id'] ?? '';
            if ( ! $slug || ! $term_lookup ) {
                return true; // incomplete config — don't block
            }

            // term_slug_or_id is comma-separated when the admin UI's multi-select term
            // picker is used (see import-settings.js: "store as comma-separated for
            // back-compat") — a product matches if it has ANY of the listed terms.
            $lookups = array_filter( array_map( 'trim', explode( ',', (string) $term_lookup ) ) );
            if ( empty( $lookups ) ) {
                return true;
            }

            foreach ( $lookups as $lookup ) {
                $term = is_numeric( $lookup )
                    ? get_term( (int) $lookup, $slug )
                    : get_term_by( 'slug', $lookup, $slug );
                if ( $term && ! is_wp_error( $term ) && has_term( $term->term_id, $slug, $product_id ) ) {
                    return true;
                }
            }

            return false;
        }

        if ( $type === 'post_meta' ) {
            $key = $config['meta_key'] ?? '';
            $val = $config['meta_value'] ?? '';
            if ( ! $key ) {
                return true; // incomplete config — don't block
            }
            return (string) get_post_meta( $product_id, $key, true ) === (string) $val;
        }

        return true; // unknown type — don't block
    }

    /**
     * Apply this profile's identifier (taxonomy term or post_meta) to a
     * newly created product, when auto_apply_to_new is enabled.
     *
     * @param int $product_id WooCommerce product post ID.
     */
    public function apply_identifier_to_product( $product_id ) {
        if ( $this->product_scope !== 'by_identifier' || empty( $this->product_identifier ) ) {
            return;
        }
        if ( empty( $this->product_identifier['auto_apply_to_new'] ) ) {
            return;
        }

        $type   = $this->product_identifier['storage_type'] ?? '';
        $config = $this->product_identifier['storage_config'] ?? [];

        if ( $type === 'taxonomy' ) {
            $slug = $config['taxonomy_slug'] ?? '';
            $term = $config['term_slug_or_id'] ?? '';
            if ( $slug && $term ) {
                wp_set_object_terms( $product_id, $term, $slug, false );
            }
        } elseif ( $type === 'post_meta' ) {
            $key = $config['meta_key'] ?? '';
            $val = $config['meta_value'] ?? '';
            if ( $key ) {
                update_post_meta( $product_id, $key, $val );
            }
        }
    }

    /**
     * Store raw source values as postmeta and apply any saved taxonomy
     * mappings (brand → product_brand, etc.) to the given product.
     *
     * Called after create_product() / update_product() for every processed item.
     *
     * @param int   $product_id   WC product post ID.
     * @param array $product_data Mapped product data (keyed by WC field name).
     */
    public function apply_taxonomy_mappings( int $product_id, array $product_data ): void {
        if ( ! $this->taxonomy_mapping_enabled ) {
            return;
        }

        $locked = \MMI_Pipeline_Field_Locks::get( $product_id );

        foreach ( $this->field_mappings as $wc_field => $config ) {
            // Only process registered taxonomies (product_brand, product_cat, etc.)
            if ( ! taxonomy_exists( $wc_field ) ) {
                continue;
            }
            if ( \MMI_Pipeline_Field_Locks::in_list( (string) $wc_field, $locked ) ) {
                continue;
            }

            // Determine the source field name for this supplier
            $source_field = '';
            if ( is_array( $config['source'] ?? '' ) ) {
                $source_field = $config['source'][ $this->supplier_name ] ?? '';
            } else {
                $source_field = $config['source'] ?? '';
            }
            if ( empty( $source_field ) || $source_field === 'NULL' || $source_field === 'null' ) {
                continue;
            }

            $mapped_value = $product_data[ $wc_field ] ?? '';

            // This single-value resolver (one source value → one term) can't handle a
            // source field that maps to an array (e.g. a feed's multi-category list).
            // Casting an array to string previously produced the literal text "Array",
            // which got written into postmeta and fed into resolve_tax_mapping() as
            // garbage — skip cleanly instead until multi-value taxonomy mapping exists.
            if ( is_array( $mapped_value ) ) {
                continue;
            }

            $raw_value = (string) $mapped_value;
            if ( $raw_value === '' ) {
                continue;
            }

            // Store raw source value so the batch-apply tool can find this product later.
            // Meta key must match TaxonomyMappingController.php's mmi_apply_taxonomy_mappings
            // handler, which normalizes a compound '+' field the same way before its own
            // lookup — a literal '+' here would never match that sanitized key.
            update_post_meta( $product_id, '_mmi_src_' . str_replace( '+', '_', $source_field ), $raw_value );

            // Resolve taxonomy mapping (this profile+supplier, then supplier
            // global, then profile-any-supplier, then fully global — see
            // MMI_DB::resolve_tax_mapping()'s tiering)
            $term_id = \MMI_DB::resolve_tax_mapping(
                $this->supplier_name,
                $source_field,
                $raw_value,
                $wc_field,
                $this->profile_id
            );

            if ( $term_id && $term_id > 0 ) {
                wp_set_object_terms( $product_id, [ $term_id ], $wc_field );
            }
        }
    }
}
