<?php
/**
 * Custom Rule Actions
 *
 * The action registry and executor for the Catalog Maintenance "Custom Rule"
 * builder (panel-catalog-maintenance.php) — every action a rule's condition
 * cascade can trigger once matched, beyond the two original stock actions
 * (force_instock/force_outofstock, still owned by Stock_Override_Resolver's
 * tiered real-time resolution). Everything here is bulk-apply only: it runs
 * from Stock_Override_Resolver::apply_to_catalog()/apply_catalog_page()'s
 * second, additive per-product pass (see that class), never from the
 * real-time import path — these are post-import catalog-maintenance actions,
 * not import-time stock resolution.
 *
 * ACTION_GROUPS is the single source of truth for the action <select>'s
 * <optgroup>s (panel-catalog-maintenance.php) and for validating/sanitizing
 * a saved rule's action + action_params (StockOverrideController.php).
 *
 * @package MannMade\DataPipeline
 */

namespace MannMade\DataPipeline;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Custom_Rule_Actions {

    /**
     * group label => [ action key => [ 'label' => ..., 'needs_params' => bool, 'params' => 'taxonomy_term'|'price'|'date_range' ] ]
     *
     * The two 'Stock' entries are listed here too (so they render in the same
     * <select>), but are never dispatched by self::apply() — they're handled
     * exclusively by Stock_Override_Resolver's existing tiered resolve()/
     * apply_catalog_page() stock-resolution pass. See is_stock_action().
     */
    const ACTION_GROUPS = [
        'Stock' => [
            'force_outofstock' => [ 'label' => 'Force Out of Stock' ],
            'force_instock'    => [ 'label' => 'Force In Stock' ],
        ],
        'Stock Management' => [
            'set_stock_status_backorder' => [ 'label' => 'Set On Backorder' ],
            'set_backorders_yes'         => [ 'label' => 'Allow Backorders' ],
            'set_backorders_notify'      => [ 'label' => 'Allow Backorders (Notify Customer)' ],
            'set_backorders_no'          => [ 'label' => "Don't Allow Backorders" ],
            'enable_manage_stock'        => [ 'label' => 'Enable Managed Stock' ],
            'disable_manage_stock'       => [ 'label' => 'Disable Managed Stock' ],
        ],
        'Post Status' => [
            'set_status_publish' => [ 'label' => 'Set to Published' ],
            'set_status_draft'   => [ 'label' => 'Set to Draft' ],
            'set_status_pending' => [ 'label' => 'Set to Pending Review' ],
        ],
        'Taxonomy' => [
            'set_taxonomy_term' => [ 'label' => 'Set Taxonomy Term', 'needs_params' => true, 'params' => 'taxonomy_term' ],
        ],
        'Pricing' => [
            'set_regular_price' => [ 'label' => 'Set Regular Price', 'needs_params' => true, 'params' => 'price' ],
            'set_sale_price'    => [ 'label' => 'Set Sale Price', 'needs_params' => true, 'params' => 'price' ],
            'clear_sale_price'  => [ 'label' => 'Clear Sale Price' ],
            'set_sale_dates'    => [ 'label' => 'Set Sale Dates', 'needs_params' => true, 'params' => 'date_range' ],
        ],
        'Meta' => [
            // Writes a matched product's own raw supplier-feed value for
            // action_params.source_field into action_params.meta_key —
            // independent of whether that field is part of the profile's
            // normal Field Mapping/import config. Needs the per-product raw
            // feed record, so apply() takes an optional $raw_item param this
            // one action actually uses (every other action ignores it).
            'copy_source_field_to_meta' => [ 'label' => 'Copy Source Field to Meta', 'needs_params' => true, 'params' => 'copy_field_meta' ],
        ],
    ];

    /**
     * WooCommerce's own structural/CRUD-owned postmeta keys. The
     * "Copy Source Field to Meta" action must never target one of these —
     * update_post_meta() is a raw write that bypasses WC's own validation
     * (e.g. SKU uniqueness), lookup-table sync (price/stock filtering), and
     * cache invalidation entirely. Targeting _sku specifically would be a
     * direct route to the same duplicate-SKU/primary-key-collision problem
     * already documented elsewhere in this project's Incident History.
     */
    const RESERVED_META_KEYS = [
        '_sku', '_regular_price', '_sale_price', '_price',
        '_stock', '_stock_status', '_manage_stock', '_backorders',
        '_sold_individually', '_virtual', '_downloadable',
        '_weight', '_length', '_width', '_height',
        '_tax_status', '_tax_class', '_thumbnail_id',
        '_product_attributes', '_product_image_gallery',
        '_sale_price_dates_from', '_sale_price_dates_to',
        '_wc_average_rating', '_wc_rating_count', '_wc_review_count',
        '_product_version', '_downloadable_files', '_download_limit', '_download_expiry',
    ];

    /* ── Registry lookups ────────────────────────────────────────────────── */

    public static function is_valid( string $action ): bool {
        foreach ( self::ACTION_GROUPS as $actions ) {
            if ( array_key_exists( $action, $actions ) ) {
                return true;
            }
        }
        return false;
    }

    public static function is_stock_action( string $action ): bool {
        return in_array( $action, Stock_Override_Resolver::VALID_OVERRIDES, true );
    }

    public static function label_for( string $action ): string {
        foreach ( self::ACTION_GROUPS as $actions ) {
            if ( isset( $actions[ $action ] ) ) {
                return $actions[ $action ]['label'];
            }
        }
        return $action;
    }

    public static function needs_params( string $action ): bool {
        foreach ( self::ACTION_GROUPS as $actions ) {
            if ( isset( $actions[ $action ] ) ) {
                return ! empty( $actions[ $action ]['needs_params'] );
            }
        }
        return false;
    }

    public static function param_type( string $action ): string {
        foreach ( self::ACTION_GROUPS as $actions ) {
            if ( isset( $actions[ $action ] ) ) {
                return $actions[ $action ]['params'] ?? '';
            }
        }
        return '';
    }

    /* ── Sanitization (used by the AJAX save/apply handlers) ──────────────── */

    /**
     * @param  string $action
     * @param  array  $raw     Raw, unsanitized action_params from $_POST.
     * @return array           Sanitized params, or [] if the action needs none
     *                         or the supplied params don't pass validation.
     */
    public static function sanitize_params( string $action, array $raw ): array {
        switch ( self::param_type( $action ) ) {
            case 'taxonomy_term':
                $taxonomy = sanitize_key( $raw['taxonomy'] ?? '' );
                $term_id  = absint( $raw['term_id'] ?? 0 );
                $mode     = in_array( $raw['mode'] ?? '', [ 'replace', 'add', 'remove' ], true ) ? $raw['mode'] : 'replace';
                if ( $taxonomy === '' || $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
                    return [];
                }
                return [ 'taxonomy' => $taxonomy, 'term_id' => $term_id, 'mode' => $mode ];

            case 'price':
                $value = isset( $raw['value'] ) ? wc_format_decimal( sanitize_text_field( (string) $raw['value'] ) ) : '';
                return ( $value !== '' && is_numeric( $value ) ) ? [ 'value' => $value ] : [];

            case 'date_range':
                return [
                    'date_from' => self::sanitize_date( (string) ( $raw['date_from'] ?? '' ) ),
                    'date_to'   => self::sanitize_date( (string) ( $raw['date_to'] ?? '' ) ),
                ];

            case 'copy_field_meta':
                $source_field = sanitize_text_field( (string) ( $raw['source_field'] ?? '' ) );
                $meta_key     = sanitize_key( (string) ( $raw['meta_key'] ?? '' ) );
                if ( $source_field === '' || $meta_key === '' || in_array( $meta_key, self::RESERVED_META_KEYS, true ) ) {
                    return [];
                }
                return [ 'source_field' => $source_field, 'meta_key' => $meta_key ];

            default:
                return [];
        }
    }

    private static function sanitize_date( string $value ): string {
        $value = sanitize_text_field( $value );
        if ( $value === '' ) {
            return '';
        }
        $d = \DateTime::createFromFormat( 'Y-m-d', $value );
        return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';
    }

    /* ── Execution ───────────────────────────────────────────────────────── */

    /**
     * Apply a non-stock custom-rule action to one product.
     *
     * Never called for a stock action (force_instock/force_outofstock) —
     * those run exclusively through Stock_Override_Resolver's existing
     * tiered resolve()/force_stock_status() path. See is_stock_action().
     *
     * @param  int    $product_id
     * @param  string $action
     * @param  array  $params      Already-sanitized action_params.
     * @param  array  $raw_item    The matched product's raw supplier feed
     *                             record, when available (only
     *                             copy_source_field_to_meta uses this;
     *                             every other action ignores it).
     * @return bool                True when the product was actually changed.
     */
    public static function apply( int $product_id, string $action, array $params, array $raw_item = [] ): bool {
        switch ( $action ) {

            case 'set_stock_status_backorder':
                $product = wc_get_product( $product_id );
                if ( ! $product || $product->get_stock_status() === 'onbackorder' ) {
                    return false;
                }
                Stock_Override_Resolver::force_stock_status( $product_id, 'onbackorder' );
                return true;

            case 'set_backorders_yes':
            case 'set_backorders_notify':
            case 'set_backorders_no':
                $target_map = [
                    'set_backorders_yes'    => 'yes',
                    'set_backorders_notify' => 'notify',
                    'set_backorders_no'     => 'no',
                ];
                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    return false;
                }
                $target = $target_map[ $action ];
                if ( $product->get_backorders( 'edit' ) === $target ) {
                    return false;
                }
                $product->set_backorders( $target );
                $product->save();
                return true;

            case 'enable_manage_stock':
            case 'disable_manage_stock':
                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    return false;
                }
                $target = ( $action === 'enable_manage_stock' );
                if ( (bool) $product->get_manage_stock( 'edit' ) === $target ) {
                    return false;
                }
                $product->set_manage_stock( $target );
                $product->save();
                return true;

            case 'set_status_publish':
            case 'set_status_draft':
            case 'set_status_pending':
                $status_map = [
                    'set_status_publish' => 'publish',
                    'set_status_draft'   => 'draft',
                    'set_status_pending' => 'pending',
                ];
                $target = $status_map[ $action ];
                $post   = get_post( $product_id );
                if ( ! $post || $post->post_status === $target ) {
                    return false;
                }
                wp_update_post( [ 'ID' => $product_id, 'post_status' => $target ] );
                return true;

            case 'set_taxonomy_term':
                return self::apply_taxonomy_term( $product_id, $params );

            case 'set_regular_price':
            case 'set_sale_price':
                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    return false;
                }
                $value = isset( $params['value'] ) ? (string) $params['value'] : '';
                if ( $action === 'set_regular_price' ) {
                    if ( (string) $product->get_regular_price( 'edit' ) === $value ) {
                        return false;
                    }
                    $product->set_regular_price( $value );
                } else {
                    if ( (string) $product->get_sale_price( 'edit' ) === $value ) {
                        return false;
                    }
                    $product->set_sale_price( $value );
                }
                $product->save();
                return true;

            case 'clear_sale_price':
                $product = wc_get_product( $product_id );
                if ( ! $product || (string) $product->get_sale_price( 'edit' ) === '' ) {
                    return false;
                }
                $product->set_sale_price( '' );
                $product->save();
                return true;

            case 'set_sale_dates':
                return self::apply_sale_dates( $product_id, $params );

            case 'copy_source_field_to_meta':
                return self::apply_copy_source_field_to_meta( $product_id, $params, $raw_item );

            default:
                return false;
        }
    }

    private static function apply_taxonomy_term( int $product_id, array $params ): bool {
        $taxonomy = $params['taxonomy'] ?? '';
        $term_id  = (int) ( $params['term_id'] ?? 0 );
        $mode     = $params['mode'] ?? 'replace';

        if ( $taxonomy === '' || $term_id <= 0 || ! taxonomy_exists( $taxonomy ) ) {
            return false;
        }

        $current = wp_get_object_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] );
        if ( is_wp_error( $current ) ) {
            $current = [];
        }
        $current = array_map( 'intval', $current );

        if ( $mode === 'remove' ) {
            if ( ! in_array( $term_id, $current, true ) ) {
                return false;
            }
            wp_remove_object_terms( $product_id, $term_id, $taxonomy );
            return true;
        }

        if ( $mode === 'add' ) {
            if ( in_array( $term_id, $current, true ) ) {
                return false;
            }
            wp_set_object_terms( $product_id, array_merge( $current, [ $term_id ] ), $taxonomy, true );
            return true;
        }

        // replace
        if ( count( $current ) === 1 && $current[0] === $term_id ) {
            return false;
        }
        wp_set_object_terms( $product_id, [ $term_id ], $taxonomy, false );
        return true;
    }

    /**
     * Write a matched product's own raw feed value for $params['source_field']
     * into the postmeta key $params['meta_key'] — literally, whatever the
     * supplier's feed currently says for that field on this product, not an
     * admin-typed constant. Works regardless of whether $params['source_field']
     * is part of the profile's normal Field Mapping config; $raw_item is
     * already scoped to the matched product's own feed record by the caller
     * (apply_to_catalog()/apply_catalog_page(), the same per-product raw item
     * condition-matching already uses).
     */
    private static function apply_copy_source_field_to_meta( int $product_id, array $params, array $raw_item ): bool {
        $source_field = $params['source_field'] ?? '';
        $meta_key     = $params['meta_key']     ?? '';
        if ( $source_field === '' || $meta_key === '' ) {
            return false;
        }

        // Belt-and-suspenders for a rule saved before RESERVED_META_KEYS
        // existed, or before the source-field picker excluded array-shaped
        // feed fields — sanitize_params() already blocks both for any rule
        // saved from now on, but a rule saved earlier is still applied via
        // its stored action_params, not re-sanitized on every run.
        if ( in_array( $meta_key, self::RESERVED_META_KEYS, true ) ) {
            return false;
        }
        if ( isset( $raw_item[ $source_field ] ) && is_array( $raw_item[ $source_field ] ) ) {
            // Nested/array-shaped feed value — this flat action has no
            // dot/bracket path support. Casting it to string would silently
            // write the literal string "Array" via PHP's (string) cast.
            return false;
        }

        $new_value = isset( $raw_item[ $source_field ] ) ? (string) $raw_item[ $source_field ] : '';
        $current   = (string) get_post_meta( $product_id, $meta_key, true );
        if ( $current === $new_value ) {
            return false;
        }

        update_post_meta( $product_id, $meta_key, $new_value );
        return true;
    }

    private static function apply_sale_dates( int $product_id, array $params ): bool {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $from = $params['date_from'] ?? '';
        $to   = $params['date_to']   ?? '';

        $cur_from = $product->get_date_on_sale_from( 'edit' );
        $cur_to   = $product->get_date_on_sale_to( 'edit' );
        $cur_from_str = $cur_from ? $cur_from->date( 'Y-m-d' ) : '';
        $cur_to_str   = $cur_to   ? $cur_to->date( 'Y-m-d' )   : '';

        $changed = false;
        if ( $from !== $cur_from_str ) {
            $product->set_date_on_sale_from( $from !== '' ? $from : null );
            $changed = true;
        }
        if ( $to !== $cur_to_str ) {
            $product->set_date_on_sale_to( $to !== '' ? $to : null );
            $changed = true;
        }
        if ( $changed ) {
            $product->save();
        }
        return $changed;
    }
}
