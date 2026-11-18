<?php
/**
 * Custom Rule AJAX Controller
 *
 * Field and value lookups for the bulk Custom Rules used by
 * Stock_Override_Resolver / Custom_Rule_Actions during imports and catalog
 * maintenance. Originally "Stock Override" rules (two actions only); the
 * action set has since grown well past stock — see Custom_Rule_Actions —
 * so this file's own name stays (chasing down its loader for a pure rename
 * isn't worth the risk) but every action name/label here now says
 * "custom rule" rather than "override".
 *
 * @package MannMade\DataPipeline\Controllers\Ajax
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Get source field names for a supplier ──────────────────────────────── */

add_action( 'wp_ajax_mmi_get_custom_rule_source_fields', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    // Every Custom Rule action operates on WooCommerce products — see this
    // file's own docblock. Gate here rather than assume the UI's disabled
    // button state was actually honored (stale page, direct AJAX call).
    if ( ! MMI_Pipeline_Admin::is_woocommerce_active() ) {
        wp_send_json_error( [ 'message' => 'WooCommerce is required for Catalog Maintenance / Custom Rules.' ] );
    }

    $supplier = sanitize_text_field( $_POST['supplier'] ?? 'all' );

    // Source = "WordPress / WooCommerce" — the app-data counterpart to a
    // supplier source. "Field" here means "which taxonomy," not a feed
    // field name, so each option carries a real slug (value) and a human
    // label — every other branch below returns plain field-name strings,
    // where value and label are the same thing. Also reused, unchanged, as
    // the taxonomy list for a "Set Taxonomy Term" action's taxonomy picker.
    if ( $supplier === \MannMade\DataPipeline\Stock_Override_Resolver::TAXONOMY_SOURCE ) {
        $taxonomies = get_object_taxonomies( 'product', 'objects' );
        $fields     = [];
        foreach ( $taxonomies as $taxonomy ) {
            // show_ui, not public — excludes WC's internal bookkeeping
            // taxonomies (product_type, product_visibility,
            // product_shipping_class) while keeping every real,
            // admin-manageable one, including non-public custom taxonomies
            // like 'distribution' that still have their own admin screen.
            if ( ! $taxonomy->show_ui ) {
                continue;
            }
            $fields[] = [ 'value' => $taxonomy->name, 'label' => $taxonomy->label ];
        }
        usort( $fields, static fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );
        wp_send_json_success( [ 'fields' => $fields ] );
    }

    // Post Field / Meta condition source — a WordPress post column
    // (POST_FIELD_MAP) or any postmeta key. Discovered keys come from
    // MMI_Meta_Key_Discovery::discover_for_post_type('product') (already
    // used by the Export tab's own meta-key suggestions), sampling only 10
    // posts — merged with a short list of common WC meta keys so a key like
    // _sku or _regular_price is always offered even when it wasn't among
    // the sample.
    if ( $supplier === \MannMade\DataPipeline\Stock_Override_Resolver::POSTMETA_SOURCE ) {
        $fields = [
            [ 'value' => '__post_title',   'label' => 'Post: Title' ],
            [ 'value' => '__post_content', 'label' => 'Post: Content' ],
            [ 'value' => '__post_excerpt', 'label' => 'Post: Excerpt' ],
            [ 'value' => '__post_name',    'label' => 'Post: Slug' ],
            [ 'value' => '__post_status',  'label' => 'Post: Status' ],
        ];

        // Data-health keys too — images, identifiers, shipping sizes, SEO
        // and the product content registry's keys — which the 10-product
        // sample often misses (and never offers _thumbnail_id).
        $common_meta = [
            '_sku', '_regular_price', '_sale_price', '_price', '_stock_status', '_manage_stock', '_backorders', '_mmi_stock_override',
            '_thumbnail_id', '_product_image_gallery', '_global_unique_id', 'mpn', '_virtual', '_downloadable',
            '_weight', '_length', '_width', '_height', '_supplier_name', '_seopress_titles_desc',
        ];
        if ( class_exists( 'MMI_Product_Content_Registry' ) ) {
            foreach ( MMI_Product_Content_Registry::storable_concepts() as $concept ) {
                $common_meta[] = MMI_Product_Content_Registry::canonical_key( $concept );
            }
        }
        $discovered  = class_exists( 'MMI_Meta_Key_Discovery' ) ? MMI_Meta_Key_Discovery::discover_for_post_type( 'product' ) : [];
        $meta_keys   = array_filter( array_unique( array_merge( $common_meta, $discovered ) ) );
        sort( $meta_keys );

        foreach ( $meta_keys as $meta_key ) {
            $fields[] = [ 'value' => $meta_key, 'label' => 'Meta: ' . $meta_key ];
        }

        wp_send_json_success( [ 'fields' => $fields ] );
    }

    // Cache per supplier — this only needs to change when a supplier's feed
    // structure changes, which is rare. Without this, opening the panel
    // with several saved condition rows fired one uncached multi-MB JSON
    // read per row (the same concurrent-AJAX-on-open pattern documented as the
    // May 5 outage cause). The client also memoizes per supplier for the
    // duration of one panel-open; this transient covers repeat opens/reloads.
    $cache_key = 'mmi_stock_override_fields_' . $supplier;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        wp_send_json_success( [ 'fields' => $cached ] );
    }

    $json_dir = mmi_shared_lib_json_dir();

    // Generic across every configured supplier, not just the three that
    // happened to exist when this endpoint was first written — a rule
    // scoped to e.g. Mogami must browse Mogami's own field names/values,
    // not silently fall back to Xchange+SkuPort+Plugivery combined.
    $file_map = [];
    if ( class_exists( 'MMI_Pipeline_Admin' ) ) {
        foreach ( array_keys( MMI_Pipeline_Admin::get_configured_suppliers() ) as $mmi_cr_sid ) {
            $file_map[ $mmi_cr_sid ] = $mmi_cr_sid . '-products.json';
        }
    }
    if ( empty( $file_map ) ) {
        $file_map = [
            'xchange'   => 'xchange-products.json',
            'skuport'   => 'skuport-products.json',
            'plugivery' => 'plugivery-products.json',
        ];
    }

    $files = ( $supplier !== 'all' && isset( $file_map[ $supplier ] ) )
        ? [ $file_map[ $supplier ] ]
        : array_values( $file_map );

    // Tracked separately, not just array_keys() — a key seen holding an
    // array value (e.g. Xchange's "categories"/"top_features") is excluded
    // below. This picker's consumers (a rule condition's raw string compare,
    // and copy_source_field_to_meta's flat postmeta write) have no
    // dot/bracket path support; offering an array-shaped field here would
    // let either silently coerce it to the literal string "Array".
    $scalar_fields = [];
    $array_fields  = [];

    foreach ( $files as $filename ) {
        $json_file = $json_dir . $filename;
        if ( ! file_exists( $json_file ) ) {
            continue;
        }

        $data = json_decode( file_get_contents( $json_file ), true );  // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            continue;
        }

        $items = $data['products'] ?? $data['items'] ?? ( is_array( $data ) ? $data : [] );
        foreach ( array_slice( $items, 0, 20 ) as $item ) {
            foreach ( (array) $item as $key => $val ) {
                if ( is_array( $val ) ) {
                    $array_fields[ $key ] = true;
                } else {
                    $scalar_fields[ $key ] = true;
                }
            }
        }
    }

    $fields = array_keys( array_diff_key( $scalar_fields, $array_fields ) );
    sort( $fields );

    set_transient( $cache_key, $fields, 5 * MINUTE_IN_SECONDS );

    wp_send_json_success( [ 'fields' => $fields ] );
} );

/* ── Terms for the "Set Taxonomy Term" action's term picker ───────────────
 * Served by ImportSettingsController.php's mmi_pipeline_get_taxonomy_terms
 * (it loads first, so it's the handler that runs). A second copy registered
 * here never ran, and the one that did run returned no term id — every term
 * option was value="undefined", so a Set Taxonomy Term rule could never be
 * saved. That handler now returns `id` too; this copy was removed. */


/* ── Get real distinct values for a Post Field/Meta condition's checklist ──
 * The 'in_list'/'not_in_list' operators show a multi-select checklist
 * instead of a free-text Value input when the field's real values are
 * enumerable. Taxonomy conditions reuse mmi_pipeline_get_taxonomy_terms
 * above unchanged (a term's own 'name' is what eval_operator() compares
 * against, so that endpoint's existing {id, name} response already has
 * everything this needs); this endpoint is the wp_postmeta counterpart. */

add_action( 'wp_ajax_mmi_get_condition_meta_values', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    if ( ! MMI_Pipeline_Admin::is_woocommerce_active() ) {
        wp_send_json_error( [ 'message' => 'WooCommerce is required for Catalog Maintenance / Custom Rules.' ] );
    }

    $field = sanitize_key( $_POST['field'] ?? '' );
    if ( $field === '' ) {
        wp_send_json_error( [ 'message' => 'No field specified.' ] );
    }

    // Post: Status is the only POST_FIELD_MAP column with a genuinely
    // bounded value set (WordPress's own registered post statuses) — the
    // other four (title, content, excerpt, slug) are free text with no
    // meaningful enumeration, so the client falls back to the plain text
    // input for those.
    if ( $field === '__post_status' ) {
        wp_send_json_success( [ 'values' => array_keys( get_post_statuses() ), 'truncated' => false ] );
    }

    if ( in_array( $field, [ '__post_title', '__post_content', '__post_excerpt', '__post_name' ], true ) ) {
        wp_send_json_success( [ 'values' => [], 'unsupported' => true ] );
    }

    // A literal postmeta key — bounded DISTINCT scan on the indexed
    // meta_key column, capped so an unbounded free-text field (price, SKU,
    // ...) falls back to the plain text input instead of dumping hundreds
    // of one-off values into a checklist.
    global $wpdb;
    $cap = 50;

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $values = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT pm.meta_value
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status = 'publish' AND pm.meta_value != ''
         ORDER BY pm.meta_value ASC
         LIMIT %d",
        $field,
        $cap + 1
    ) );
    // phpcs:enable

    $truncated = count( $values ) > $cap;
    if ( $truncated ) {
        $values = array_slice( $values, 0, $cap );
    }

    wp_send_json_success( [ 'values' => $values, 'truncated' => $truncated ] );
} );
