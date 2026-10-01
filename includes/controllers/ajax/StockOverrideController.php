<?php
/**
 * Custom Rule AJAX Controller
 *
 * Handles saving and retrieving the bulk Custom Rules used by
 * Stock_Override_Resolver / Custom_Rule_Actions during imports and catalog
 * maintenance. Originally "Stock Override" rules (two actions only); the
 * action set has since grown well past stock — see Custom_Rule_Actions —
 * so this file's own name stays (chasing down its loader for a pure rename
 * isn't worth the risk) but every action name/label here now says
 * "custom rule" rather than "override".
 *
 * Actions:
 *   wp_ajax_mmi_save_custom_rules     — persist rule array
 *   wp_ajax_mmi_get_custom_rules      — return current rules as JSON
 *
 * @package MannMade\DataPipeline\Controllers\Ajax
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Allowed values ─────────────────────────────────────────────────────── */

const MMI_CUSTOM_RULE_VALID_SUPPLIERS   = [];  // empty = accept any sanitised string
const MMI_CUSTOM_RULE_VALID_MATCH_LOGIC = [ 'all', 'any' ];
// Operators are validated by Stock_Override_Resolver::sanitize_condition().

/* ── Save ───────────────────────────────────────────────────────────────── */

add_action( 'wp_ajax_mmi_save_custom_rules', function () {
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

    if ( ! class_exists( 'MannMade\\DataPipeline\\Custom_Rule_Actions' ) ) {
        wp_send_json_error( [ 'message' => 'Custom_Rule_Actions class not found.' ] );
    }

    $raw_rules = isset( $_POST['rules'] ) ? array_values( (array) $_POST['rules'] ) : [];
    $sanitised = [];

    // A rule that fails validation refuses the whole save, naming the rule.
    // It used to be dropped silently while the response still said "saved",
    // so a new rule looked saved and was gone on the next page load.
    $reject = static function ( int $index, string $name, string $reason ) {
        $label = $name !== '' ? $name : 'Custom Rule';
        wp_send_json_error( [
            'message'    => sprintf( '"%s": %s Nothing was saved.', $label, $reason ),
            'rule_index' => $index,
        ] );
    };

    foreach ( $raw_rules as $index => $rule ) {
        if ( ! is_array( $rule ) ) {
            continue;
        }

        $supplier    = sanitize_text_field( $rule['supplier']    ?? '' );
        $match_logic = sanitize_text_field( $rule['match_logic'] ?? 'all' );
        $action      = sanitize_text_field( $rule['action']      ?? '' );
        $name        = mb_substr( sanitize_text_field( $rule['name'] ?? '' ), 0, 80 );
        // Absent key (older client, or a rule saved before this toggle
        // existed) defaults to enabled — only an explicit '0' turns it off.
        $enabled     = ( $rule['enabled'] ?? '1' ) !== '0';

        // Require a supplier identifier, valid match_logic, and a real action.
        if ( empty( $supplier ) ) {
            $reject( $index, $name, 'pick a supplier scope.' );
        }
        if ( ! in_array( $match_logic, MMI_CUSTOM_RULE_VALID_MATCH_LOGIC, true ) ) {
            $reject( $index, $name, 'pick All conditions or Any condition.' );
        }
        if ( ! \MannMade\DataPipeline\Custom_Rule_Actions::is_valid( $action ) ) {
            $reject( $index, $name, 'pick what the rule should do.' );
        }

        // Sanitize conditions array.
        $raw_conditions = isset( $rule['conditions'] ) ? (array) $rule['conditions'] : [];
        $conditions     = [];
        foreach ( array_values( $raw_conditions ) as $cond_index => $cond ) {
            if ( ! is_array( $cond ) ) {
                continue;
            }
            // A dropped condition can leave the rule with none, and a rule
            // with no conditions matches every product.
            $clean = \MannMade\DataPipeline\Stock_Override_Resolver::sanitize_condition( $cond );
            if ( $clean === null ) {
                $reject( $index, $name, sprintf( 'condition %d needs a field and a valid operator.', $cond_index + 1 ) );
            }
            $conditions[] = $clean;
        }

        $raw_params = isset( $rule['action_params'] ) ? (array) $rule['action_params'] : [];
        $params     = \MannMade\DataPipeline\Custom_Rule_Actions::needs_params( $action )
            ? \MannMade\DataPipeline\Custom_Rule_Actions::sanitize_params( $action, $raw_params )
            : [];

        // An action that needs params but didn't get valid ones is discarded
        // outright rather than saved half-configured (e.g. "Set Taxonomy
        // Term" with no term picked would silently no-op forever).
        if ( \MannMade\DataPipeline\Custom_Rule_Actions::needs_params( $action ) && empty( $params ) ) {
            $reject( $index, $name, sprintf(
                '"%s" needs its settings filled in (in the rule\'s expanded panel).',
                \MannMade\DataPipeline\Custom_Rule_Actions::label_for( $action )
            ) );
        }

        $sanitised[] = [
            'supplier'      => $supplier,
            'match_logic'   => $match_logic,
            'conditions'    => $conditions,
            'action'        => $action,
            'action_params' => $params,
            'name'          => $name,
            'enabled'       => $enabled,
        ];
    }

    MMI_DB::set_setting( 'mmi_stock_override_rules', $sanitised );

    mmi_data_pipeline_audit( 'catalog_rules.update', [
        'object_type' => 'custom_rules',
        'outcome'     => 'success',
        'details'     => [ 'keys' => [ 'mmi_stock_override_rules' ], 'rule_count' => count( $sanitised ) ],
    ] );

    wp_send_json_success( [
        'message' => 'Custom rules saved',
        'count'   => count( $sanitised ),
        'rules'   => $sanitised,
    ] );
} );

/* ── Get ────────────────────────────────────────────────────────────────── */

add_action( 'wp_ajax_mmi_get_custom_rules', function () {
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

    $rules = MMI_DB::get_setting( 'mmi_stock_override_rules', [] );
    if ( ! is_array( $rules ) ) {
        $rules = [];
    }

    // Normalise any old-format rules (condition/flag/flag_value, or the old
    // 'override' field name) to the current shape before returning to the client.
    if ( class_exists( 'MannMade\\DataPipeline\\Stock_Override_Resolver' ) ) {
        $rules = \MannMade\DataPipeline\Stock_Override_Resolver::normalize_rules( $rules );
    }

    wp_send_json_success( [ 'rules' => $rules ] );
} );

/* ── Preview rule match count — live badge while editing ──────────────────── */

add_action( 'wp_ajax_mmi_preview_custom_rule_match_count', function () {
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

    $supplier    = sanitize_text_field( $_POST['supplier']    ?? 'all' );
    $match_logic = sanitize_text_field( $_POST['match_logic'] ?? 'all' );
    if ( ! in_array( $match_logic, MMI_CUSTOM_RULE_VALID_MATCH_LOGIC, true ) ) {
        $match_logic = 'all';
    }

    $raw_conditions = isset( $_POST['conditions'] ) ? (array) $_POST['conditions'] : [];
    $conditions     = [];
    foreach ( $raw_conditions as $cond ) {
        // Same sanitizer as the save handler, so a preview/apply sees the
        // exact condition that would be saved (including Match case).
        $c = \MannMade\DataPipeline\Stock_Override_Resolver::sanitize_condition( $cond );
        if ( $c === null ) {
            continue;
        }
        $conditions[] = $c;
    }

    // action is irrelevant for counting; use a dummy valid value.
    $rule = [
        'supplier'    => $supplier,
        'match_logic' => $match_logic,
        'conditions'  => $conditions,
        'action'      => 'force_outofstock',
    ];

    if ( ! class_exists( 'MannMade\\DataPipeline\\Stock_Override_Resolver' ) ) {
        wp_send_json_error( [ 'message' => 'Stock_Override_Resolver class not found.' ] );
    }

    try {
        $count = \MannMade\DataPipeline\Stock_Override_Resolver::count_matched_products_for_rule( $rule );
        wp_send_json_success( [ 'count' => $count ] );
    } catch ( \Throwable $e ) {
        wp_send_json_error( [ 'message' => $e->getMessage() ] );
    }
} );

/* ── Per-condition match breakdown — debugging why a rule matches N products ─ */

add_action( 'wp_ajax_mmi_get_custom_rule_condition_breakdown', function () {
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

    if ( ! class_exists( 'MannMade\\DataPipeline\\Stock_Override_Resolver' ) ) {
        wp_send_json_error( [ 'message' => 'Stock_Override_Resolver class not found.' ] );
    }

    $supplier    = sanitize_text_field( $_POST['supplier']    ?? 'all' );
    $match_logic = sanitize_text_field( $_POST['match_logic'] ?? 'all' );
    if ( ! in_array( $match_logic, MMI_CUSTOM_RULE_VALID_MATCH_LOGIC, true ) ) {
        $match_logic = 'all';
    }

    $raw_conditions = isset( $_POST['conditions'] ) ? (array) $_POST['conditions'] : [];
    $conditions     = [];
    foreach ( $raw_conditions as $cond ) {
        // Same sanitizer as the save handler, so a preview/apply sees the
        // exact condition that would be saved (including Match case).
        $c = \MannMade\DataPipeline\Stock_Override_Resolver::sanitize_condition( $cond );
        if ( $c === null ) {
            continue;
        }
        $conditions[] = $c;
    }

    if ( empty( $conditions ) ) {
        wp_send_json_error( [ 'message' => 'No conditions to break down.' ] );
    }

    $rule = [
        'supplier'    => $supplier,
        'match_logic' => $match_logic,
        'conditions'  => $conditions,
        'action'      => 'force_outofstock', // irrelevant for matching; a valid placeholder
    ];

    try {
        $breakdown = \MannMade\DataPipeline\Stock_Override_Resolver::get_condition_breakdown( $rule );
        wp_send_json_success( $breakdown );
    } catch ( \Throwable $e ) {
        wp_send_json_error( [ 'message' => $e->getMessage() ] );
    }
} );

/* ── Apply single rule now — per-card Apply Now button ────────────────────── */

add_action( 'wp_ajax_mmi_apply_single_custom_rule_now', function () {
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

    if ( ! class_exists( 'MannMade\\DataPipeline\\Stock_Override_Resolver' )
        || ! class_exists( 'MannMade\\DataPipeline\\Custom_Rule_Actions' ) ) {
        wp_send_json_error( [ 'message' => 'Required classes not found.' ] );
    }

    $page     = max( 1, (int) ( $_POST['page']     ?? 1 ) );
    $per_page = min( 500, max( 10, (int) ( $_POST['per_page'] ?? 200 ) ) );
    $token    = sanitize_key( $_POST['token'] ?? '' );

    // Sanitize rule from POST on every page (rule data is re-sent each chunk).
    $supplier    = sanitize_text_field( $_POST['supplier']    ?? '' );
    $match_logic = sanitize_text_field( $_POST['match_logic'] ?? 'all' );
    // Read from 'rule_action', not 'action' — the latter is WordPress's own
    // AJAX dispatch key ($_POST['action'] === 'mmi_apply_single_custom_rule_now'
    // here), and this endpoint's own rule-action field would otherwise collide
    // with it under the exact same POST key.
    $action      = sanitize_text_field( $_POST['rule_action'] ?? '' );

    if ( empty( $supplier ) || ! \MannMade\DataPipeline\Custom_Rule_Actions::is_valid( $action ) ) {
        wp_send_json_error( [ 'message' => 'Invalid rule parameters.' ] );
    }
    if ( ! in_array( $match_logic, MMI_CUSTOM_RULE_VALID_MATCH_LOGIC, true ) ) {
        $match_logic = 'all';
    }

    $raw_conditions = isset( $_POST['conditions'] ) ? (array) $_POST['conditions'] : [];
    $conditions     = [];
    foreach ( $raw_conditions as $cond ) {
        // Same sanitizer as the save handler, so a preview/apply sees the
        // exact condition that would be saved (including Match case).
        $c = \MannMade\DataPipeline\Stock_Override_Resolver::sanitize_condition( $cond );
        if ( $c === null ) {
            continue;
        }
        $conditions[] = $c;
    }

    $raw_params = isset( $_POST['action_params'] ) ? (array) $_POST['action_params'] : [];
    $params     = \MannMade\DataPipeline\Custom_Rule_Actions::needs_params( $action )
        ? \MannMade\DataPipeline\Custom_Rule_Actions::sanitize_params( $action, $raw_params )
        : [];

    if ( \MannMade\DataPipeline\Custom_Rule_Actions::needs_params( $action ) && empty( $params ) ) {
        wp_send_json_error( [ 'message' => 'This action requires additional parameters — please fill them in and save first.' ] );
    }

    $rule = [
        'supplier'      => $supplier,
        'match_logic'   => $match_logic,
        'conditions'    => $conditions,
        'action'        => $action,
        'action_params' => $params,
    ];

    $transient_key = '';
    $product_ids   = [];
    $new_token     = '';
    $total_count   = 0;

    if ( $page === 1 ) {
        // Compute matched product IDs and store in transient for subsequent pages.
        try {
            $product_ids = \MannMade\DataPipeline\Stock_Override_Resolver::get_matched_product_ids_for_rule( $rule );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'Failed to compute matched products: ' . $e->getMessage() ] );
        }

        $new_token     = substr( uniqid( 'mmis', false ), 0, 16 );
        $transient_key = 'mmi_rule_apply_ids_' . $new_token;
        set_transient( $transient_key, $product_ids, 5 * MINUTE_IN_SECONDS );
        $total_count = count( $product_ids );

        MMI_Logger::info(
            'Custom rule single-rule Apply Now initiated',
            [ 'supplier' => $supplier, 'action' => $action, 'matched' => $total_count, 'user_id' => get_current_user_id() ],
            'sync',
            'StockOverrideController'
        );

        mmi_data_pipeline_audit( 'catalog_rule.apply', [
            'object_type' => 'custom_rule',
            'outcome'     => 'success',
            'details'     => [ 'supplier' => $supplier, 'action' => $action, 'matched' => $total_count ],
        ] );
    } else {
        if ( empty( $token ) ) {
            wp_send_json_error( [ 'message' => 'Token required for pages > 1.' ] );
        }
        $transient_key = 'mmi_rule_apply_ids_' . $token;
        $cached        = get_transient( $transient_key );
        if ( $cached === false ) {
            wp_send_json_error( [ 'message' => 'Session expired. Please restart.' ] );
        }
        $product_ids = (array) $cached;
        $total_count = count( $product_ids );
    }

    if ( empty( $product_ids ) ) {
        wp_send_json_success( [
            'processed_this_page' => 0,
            'changed_this_page'   => 0,
            'total_products'      => 0,
            'has_more'            => false,
            'token'               => $new_token ?: $token,
        ] );
    }

    try {
        $result = \MannMade\DataPipeline\Stock_Override_Resolver::apply_catalog_page(
            [ $rule ], $page, $per_page, $product_ids
        );

        if ( ! empty( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => 'PHP error: ' . $result['error'] ] );
        }

        if ( ! $result['has_more'] ) {
            MMI_Logger::info(
                "Custom rule single-rule Apply Now complete: {$result['changed_this_page']} changed, {$result['processed_this_page']} processed (final page)",
                [ 'changed' => $result['changed_this_page'], 'processed' => $result['processed_this_page'], 'supplier' => $supplier, 'action' => $action ],
                'sync',
                'StockOverrideController'
            );
        }

        wp_send_json_success( [
            'processed_this_page' => $result['processed_this_page'],
            'changed_this_page'   => $result['changed_this_page'],
            'total_products'      => $total_count,
            'has_more'            => $result['has_more'],
            'page'                => $page,
            'token'               => $new_token ?: $token,
        ] );
    } catch ( \Throwable $e ) {
        wp_send_json_error( [ 'message' => 'PHP exception: ' . $e->getMessage() ] );
    }
} );

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

        $common_meta = [ '_sku', '_regular_price', '_sale_price', '_stock_status', '_manage_stock', '_backorders', '_mmi_stock_override' ];
        $discovered  = class_exists( 'MMI_Meta_Key_Discovery' ) ? MMI_Meta_Key_Discovery::discover_for_post_type( 'product' ) : [];
        $meta_keys   = array_unique( array_merge( $common_meta, $discovered ) );
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

/* ── Get matched products for a rule — product review modal ─────────────── */

add_action( 'wp_ajax_mmi_get_matched_products_for_custom_rule', function () {
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

    if ( ! class_exists( 'MannMade\\DataPipeline\\Stock_Override_Resolver' )
        || ! class_exists( 'MannMade\\DataPipeline\\Custom_Rule_Actions' ) ) {
        wp_send_json_error( [ 'message' => 'Required classes not found.' ] );
    }

    $supplier    = sanitize_text_field( $_POST['supplier']    ?? 'all' );
    $match_logic = sanitize_text_field( $_POST['match_logic'] ?? 'all' );
    // 'rule_action', not 'action' — see the identical note in the Apply Now
    // handler above; $_POST['action'] here is WordPress's own AJAX dispatch key.
    $action      = sanitize_text_field( $_POST['rule_action'] ?? 'force_outofstock' );
    $page        = max( 1, (int) ( $_POST['page'] ?? 1 ) );
    $per_page    = 20;
    $token       = sanitize_key( $_POST['token'] ?? '' );

    if ( ! in_array( $match_logic, MMI_CUSTOM_RULE_VALID_MATCH_LOGIC, true ) ) {
        $match_logic = 'all';
    }
    if ( ! \MannMade\DataPipeline\Custom_Rule_Actions::is_valid( $action ) ) {
        $action = 'force_outofstock';
    }
    $is_stock_action = \MannMade\DataPipeline\Custom_Rule_Actions::is_stock_action( $action );

    $raw_conditions = isset( $_POST['conditions'] ) ? (array) $_POST['conditions'] : [];
    $conditions     = [];
    foreach ( $raw_conditions as $cond ) {
        // Same sanitizer as the save handler, so a preview/apply sees the
        // exact condition that would be saved (including Match case).
        $c = \MannMade\DataPipeline\Stock_Override_Resolver::sanitize_condition( $cond );
        if ( $c === null ) {
            continue;
        }
        $conditions[] = $c;
    }

    $rule = [
        'supplier'    => $supplier,
        'match_logic' => $match_logic,
        'conditions'  => $conditions,
        'action'      => $action,
    ];

    $product_ids = [];
    $new_token   = '';

    if ( $page === 1 ) {
        // Compute matched IDs and cache in a transient for subsequent pages.
        try {
            $product_ids = \MannMade\DataPipeline\Stock_Override_Resolver::get_matched_product_ids_for_rule( $rule );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
        $new_token = substr( uniqid( 'mmim', false ), 0, 16 );
        set_transient( 'mmi_match_preview_ids_' . $new_token, $product_ids, 5 * MINUTE_IN_SECONDS );
    } else {
        if ( empty( $token ) ) {
            wp_send_json_error( [ 'message' => 'Token required for pages > 1.' ] );
        }
        $cached = get_transient( 'mmi_match_preview_ids_' . $token );
        if ( $cached === false ) {
            wp_send_json_error( [ 'message' => 'Preview session expired — please reopen.' ] );
        }
        $product_ids = (array) $cached;
    }

    $total         = count( $product_ids );
    $offset        = ( $page - 1 ) * $per_page;
    $chunk         = array_slice( $product_ids, $offset, $per_page );
    $target_status = ( $action === 'force_instock' ) ? 'instock' : 'outofstock';
    $action_label  = \MannMade\DataPipeline\Custom_Rule_Actions::label_for( $action );

    $products_out = [];
    foreach ( $chunk as $pid ) {
        $product = wc_get_product( (int) $pid );
        if ( ! $product ) {
            continue;
        }
        $current = $product->get_stock_status();
        $row     = [
            'id'       => (int) $pid,
            'title'    => $product->get_name(),
            'sku'      => $product->get_sku(),
            'stock_status' => $current,
            'edit_url' => (string) get_edit_post_link( (int) $pid, 'raw' ),
        ];
        if ( $is_stock_action ) {
            $row['already_correct'] = ( $current === $target_status );
        }
        $products_out[] = $row;
    }

    wp_send_json_success( [
        'products'        => $products_out,
        'total'           => $total,
        'page'            => $page,
        'has_more'        => ( $offset + $per_page ) < $total,
        'action'          => $action,
        'action_label'    => $action_label,
        'is_stock_action' => $is_stock_action,
        'target_status'   => $is_stock_action ? $target_status : null,
        'token'           => $new_token ?: $token,
    ] );
} );

/* ── Get unique values for a field in a supplier's source data ───────────── */

add_action( 'wp_ajax_mmi_get_override_field_values', function () {
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
    $field    = sanitize_key( $_POST['field'] ?? '' );

    if ( empty( $field ) ) {
        wp_send_json_error( [ 'message' => 'Field is required' ] );
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

    $values = [];

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
        foreach ( $items as $item ) {
            $v = (string) ( $item[ $field ] ?? '' );
            if ( $v !== '' ) {
                $values[ $v ] = true;
            }
        }
    }

    $unique = array_keys( $values );
    usort( $unique, 'strnatcasecmp' );

    wp_send_json_success( [ 'values' => $unique ] );
} );

/* ── Compliance report — how many products already match each rule ───────── */

add_action( 'wp_ajax_mmi_get_custom_rule_compliance', function () {
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

    $rules = MMI_DB::get_setting( 'mmi_stock_override_rules', [] );
    if ( ! is_array( $rules ) ) {
        $rules = [];
    }

    if ( ! class_exists( 'MannMade\\DataPipeline\\Stock_Override_Resolver' ) ) {
        wp_send_json_error( [ 'message' => 'Stock_Override_Resolver class not found.' ] );
    }

    try {
        $report = \MannMade\DataPipeline\Stock_Override_Resolver::get_compliance_report( $rules );
        wp_send_json_success( [ 'compliance' => $report ] );
    } catch ( \Throwable $e ) {
        wp_send_json_error( [ 'message' => 'PHP exception: ' . $e->getMessage() ] );
    }
} );
