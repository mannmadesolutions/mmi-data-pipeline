<?php
/**
 * Attribute & Variation Mapping AJAX Controller
 *
 * Handles all AJAX actions for the attribute/variation configuration panel:
 *   mmi_get_wc_attributes        — list registered WooCommerce global attributes
 *   mmi_autosave_attribute_config — persist the full attribute config for a profile
 *   mmi_discover_attributes       — scan a sample supplier JSON file and suggest attributes
 *   mmi_create_wc_attribute       — create a new global WC attribute taxonomy on demand
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Get registered WooCommerce attributes ────────────────────────────────────
add_action( 'wp_ajax_mmi_get_wc_attributes', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $attributes = [];

    if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
        foreach ( wc_get_attribute_taxonomies() as $tax ) {
            $attributes[] = [
                'slug'      => wc_attribute_taxonomy_name( $tax->attribute_name ),
                'name'      => $tax->attribute_name,  // without pa_ prefix
                'label'     => $tax->attribute_label,
                'type'      => $tax->attribute_type,
                'public'    => (bool) $tax->attribute_public,
            ];
        }
    }

    wp_send_json_success( [ 'attributes' => $attributes ] );
} );

// ── Autosave entire attribute config for a profile ───────────────────────────
add_action( 'wp_ajax_mmi_autosave_attribute_config', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $profile  = sanitize_text_field( $_POST['profile'] ?? 'default' );
    $raw_json = wp_unslash( $_POST['config'] ?? '' );
    $config   = json_decode( $raw_json, true );

    if ( ! is_array( $config ) ) {
        wp_send_json_error( [ 'message' => 'Invalid config JSON' ] );
    }

    // Sanitize and validate
    $clean = mmi_sanitize_attribute_config( $config );
    $key   = mmi_attribute_config_key( $profile );

    MMI_DB::set_setting( $key, wp_json_encode( $clean ) );

    wp_send_json_success( [
        'message' => 'Attribute config saved',
        'profile' => $profile,
    ] );
} );

// ── Discover attribute candidates from a supplier sample file ────────────────
add_action( 'wp_ajax_mmi_discover_attributes', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $supplier_id = sanitize_text_field( $_POST['supplier_id'] ?? '' );
    $file_key    = sanitize_text_field( $_POST['file_key']    ?? '' );

    if ( empty( $supplier_id ) ) {
        wp_send_json_error( [ 'message' => 'supplier_id required' ] );
    }

    // Locate the JSON file — use same resolution as the importer's get_json_file_path()
    $json_dir  = mmi_shared_lib_json_dir();
    $json_path = rtrim( $json_dir, '/' ) . '/' . ( $file_key ?: "{$supplier_id}-products.json" );

    if ( ! file_exists( $json_path ) ) {
        // Try common fallback names
        $fallbacks = [
            "{$supplier_id}-products.json",
            "{$supplier_id}-products-flat.json",
            "{$supplier_id}.json",
        ];
        $json_path = null;
        foreach ( $fallbacks as $fb ) {
            $try = rtrim( $json_dir, '/' ) . '/' . $fb;
            if ( file_exists( $try ) ) {
                $json_path = $try;
                break;
            }
        }
    }

    if ( ! $json_path ) {
        wp_send_json_error( [ 'message' => 'No data file found for this source. Run a Data Fetch first.' ] );
    }

    // Read a small chunk of the file (first 64 KB is enough to find keys)
    $handle  = fopen( $json_path, 'r' );
    $content = fread( $handle, 65536 );
    fclose( $handle );

    // Complete the JSON at the next '}' boundary to make it valid for decode
    $data = json_decode( $content, true );
    if ( $data === null ) {
        // Incomplete chunk — try reading the whole file (small files)
        $data = json_decode( file_get_contents( $json_path ), true );
    }
    if ( ! is_array( $data ) ) {
        wp_send_json_error( [ 'message' => 'Could not parse the data file as JSON.' ] );
    }

    // Extract a representative item
    $items = [];
    if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
        $items = $data['products'];
    } elseif ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
        $items = $data['items'];
    } elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
        $items = $data['data'];
    } elseif ( is_array( $data ) && isset( $data[0] ) ) {
        $items = $data;
    }

    if ( empty( $items ) ) {
        wp_send_json_error( [ 'message' => 'No product items found in the file.' ] );
    }

    // Flatten the first few items and collect unique scalar-value fields
    $sample_items = array_slice( $items, 0, min( 50, count( $items ) ) );
    $field_values = [];  // path => [values…]

    // Skip these fields that are clearly not attributes
    $skip_patterns = [
        '/^(id|sku|price|map|msrp|sale_price|cost|upc|ean|gtin|asin)$/i',
        '/^(description|name|title|image|gallery|url|link|permalink)$/i',
        '/^(stock|inventory|quantity|available|shipping|weight|width|height|length)$/i',
        '/^(updated_at|created_at|date|timestamp|status|enabled|active|featured)$/i',
        '/\.(id|sku|price|url|image|description|name)$/i',
    ];

    foreach ( $sample_items as $item ) {
        $flat = mmi_flatten_item( $item );
        foreach ( $flat as $path => $value ) {
            if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
                continue;
            }
            if ( is_numeric( $value ) ) {
                continue; // purely numeric values are not attributes
            }

            $skip = false;
            foreach ( $skip_patterns as $pattern ) {
                if ( preg_match( $pattern, $path ) ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) {
                continue;
            }

            $str = (string) $value;
            if ( strlen( $str ) > 100 || strlen( $str ) < 1 ) {
                continue; // too long = probably a description; too short = empty
            }

            $field_values[ $path ][] = $str;
        }
    }

    // Score fields by cardinality: good attributes have 2-50 unique values across samples
    $candidates = [];
    foreach ( $field_values as $path => $values ) {
        $unique = array_unique( $values );
        $count  = count( $unique );
        if ( $count < 2 || $count > 60 ) {
            continue;
        }
        // A low-cardinality field with repeated text values is likely an attribute
        $sample_vals = array_slice( $unique, 0, 5 );

        // Guess a human label from the last path segment
        $last_seg = basename( str_replace( '.', '/', $path ) );
        $label    = ucwords( str_replace( [ '_', '-' ], ' ', $last_seg ) );

        // Match against existing WC attributes
        $wc_slug = '';
        $wc_label = '';
        if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
            foreach ( wc_get_attribute_taxonomies() as $tax ) {
                if ( strcasecmp( $tax->attribute_name, $last_seg ) === 0
                    || strcasecmp( $tax->attribute_label, $label ) === 0 ) {
                    $wc_slug  = wc_attribute_taxonomy_name( $tax->attribute_name );
                    $wc_label = $tax->attribute_label;
                    break;
                }
            }
        }

        $candidates[] = [
            'path'        => $path,
            'label'       => $label,
            'wc_slug'     => $wc_slug,
            'wc_label'    => $wc_label ?: $label,
            'unique_count'=> $count,
            'sample_vals' => $sample_vals,
        ];
    }

    // Sort by uniqueness score: prefer fields that look most attribute-like
    usort( $candidates, function ( $a, $b ) {
        // Prefer 2-20 unique values
        $score_a = abs( $a['unique_count'] - 10 );
        $score_b = abs( $b['unique_count'] - 10 );
        return $score_a - $score_b;
    } );

    wp_send_json_success( [
        'candidates'  => array_slice( $candidates, 0, 15 ),
        'total_items' => count( $items ),
        'file'        => basename( $json_path ),
    ] );
} );

// ── Create a new global WooCommerce attribute ─────────────────────────────────
add_action( 'wp_ajax_mmi_create_wc_attribute', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $label = sanitize_text_field( $_POST['label'] ?? '' );
    $slug  = sanitize_title( $_POST['slug']  ?? $label );

    if ( empty( $label ) ) {
        wp_send_json_error( [ 'message' => 'Attribute label is required' ] );
    }

    // Remove pa_ prefix if accidentally included — wc_create_attribute uses the raw name
    $slug = preg_replace( '/^pa_/', '', $slug );

    if ( ! function_exists( 'wc_create_attribute' ) ) {
        wp_send_json_error( [ 'message' => 'WooCommerce is required' ] );
    }

    // Check if it already exists
    $existing_id = wc_attribute_taxonomy_id_by_name( $slug );
    if ( $existing_id ) {
        $full_slug = wc_attribute_taxonomy_name( $slug );
        wp_send_json_success( [
            'message'    => 'Attribute already exists',
            'slug'       => $full_slug,
            'label'      => $label,
            'created'    => false,
        ] );
        return;
    }

    $result = wc_create_attribute( [
        'name'         => $label,
        'slug'         => $slug,
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ] );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => $result->get_error_message() ] );
    }

    // Register the taxonomy immediately in this request so it's usable
    $full_slug = wc_attribute_taxonomy_name( $slug );
    if ( ! taxonomy_exists( $full_slug ) ) {
        register_taxonomy( $full_slug, [ 'product' ], [ 'label' => $label ] );
    }

    wp_send_json_success( [
        'message' => "Created attribute: {$label}",
        'slug'    => $full_slug,
        'label'   => $label,
        'created' => true,
    ] );
} );

// ── Get current attribute config for a profile ───────────────────────────────
add_action( 'wp_ajax_mmi_get_attribute_config', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $profile = sanitize_text_field( $_POST['profile'] ?? 'default' );
    $config  = mmi_get_attribute_config( $profile );

    wp_send_json_success( [ 'config' => $config ] );
} );

// ── Helper functions ─────────────────────────────────────────────────────────

/**
 * Get the settings key for a profile's attribute config.
 */
function mmi_attribute_config_key( string $profile ): string {
    return $profile === 'default'
        ? 'mmi_pipeline_attribute_config'
        : "mmi_pipeline_attribute_config_{$profile}";
}

/**
 * Load and decode a profile's attribute config from DB.
 *
 * @return array<string, mixed>
 */
function mmi_get_attribute_config( string $profile ): array {
    $raw     = MMI_DB::get_setting( mmi_attribute_config_key( $profile ), '' );
    $decoded = json_decode( is_string( $raw ) ? $raw : '', true );
    return is_array( $decoded ) ? $decoded : [];
}

/**
 * Sanitize an attribute config array from untrusted input.
 *
 * @param  array $config Raw decoded JSON.
 * @return array         Cleaned config.
 */
function mmi_sanitize_attribute_config( array $config ): array {
    $clean = [
        'enabled'              => ! empty( $config['enabled'] ),
        'product_type'         => in_array( $config['product_type'] ?? '', [ 'simple', 'variable' ], true )
                                    ? $config['product_type'] : 'simple',
        'variation_mode'       => in_array( $config['variation_mode'] ?? '', [ 'flat', 'nested' ], true )
                                    ? $config['variation_mode'] : 'flat',
        'parent_group_field'   => sanitize_text_field( $config['parent_group_field']   ?? '' ),
        'variants_path'        => sanitize_text_field( $config['variants_path']        ?? 'variants' ),
        'variation_sku_field'  => sanitize_text_field( $config['variation_sku_field']  ?? '' ),
        'variation_price_key'  => sanitize_text_field( $config['variation_price_key']  ?? '' ),
        'variation_stock_key'  => sanitize_text_field( $config['variation_stock_key']  ?? '' ),
        'attributes'           => [],
    ];

    $allowed_slug_pattern = '/^(pa_[a-z0-9_-]+|[a-z0-9_-]+)$/';

    foreach ( (array) ( $config['attributes'] ?? [] ) as $attr ) {
        if ( ! is_array( $attr ) ) {
            continue;
        }

        $slug = sanitize_text_field( $attr['wc_slug'] ?? '' );
        // Auto-prefix with pa_ if user provided a bare slug without it
        if ( ! empty( $slug ) && ! str_starts_with( $slug, 'pa_' ) ) {
            $slug = 'pa_' . sanitize_title( $slug );
        }
        if ( ! preg_match( $allowed_slug_pattern, $slug ) ) {
            continue; // skip malformed slugs
        }

        $clean_attr = [
            'id'              => sanitize_key( $attr['id']    ?? uniqid( 'attr_', false ) ),
            'label'           => sanitize_text_field( $attr['label']        ?? '' ),
            'wc_slug'         => $slug,
            'is_global'       => ! empty( $attr['is_global'] ),
            'for_variations'  => ! empty( $attr['for_variations'] ),
            'visible'         => isset( $attr['visible'] ) ? (bool) $attr['visible'] : true,
            'filterable'      => isset( $attr['filterable'] ) ? (bool) $attr['filterable'] : false,
            'source_field'    => sanitize_text_field( $attr['source_field'] ?? '' ),
            'supplier_overrides' => [],
        ];

        // Per-supplier source field overrides
        foreach ( (array) ( $attr['supplier_overrides'] ?? [] ) as $sid => $sf ) {
            $clean_attr['supplier_overrides'][ sanitize_key( $sid ) ] = sanitize_text_field( (string) $sf );
        }

        $clean['attributes'][] = $clean_attr;
    }

    return $clean;
}

/**
 * Recursively flatten a nested array into dot-notation paths.
 * Stops after depth 3 and skips array values.
 *
 * @param  array  $item   Source item.
 * @param  string $prefix Current prefix.
 * @param  int    $depth  Current depth.
 * @return array<string, mixed>
 */
function mmi_flatten_item( array $item, string $prefix = '', int $depth = 0 ): array {
    if ( $depth > 3 ) {
        return [];
    }
    $result = [];
    foreach ( $item as $key => $value ) {
        $path = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;
        if ( is_array( $value ) ) {
            $nested = mmi_flatten_item( $value, $path, $depth + 1 );
            $result = array_merge( $result, $nested );
        } else {
            $result[ $path ] = $value;
        }
    }
    return $result;
}
