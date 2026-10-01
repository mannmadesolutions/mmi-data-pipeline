<?php
/**
 * Catalog Rules Controller
 *
 * AJAX handlers for saving and retrieving the mmi_catalog_rules configuration
 * — the toggle-only settings for the Catalog Updater's Maintenance phases.
 * Brand-level out-of-stock rules (formerly this controller's own
 * brand_oos_rules concept) were retired 2026-09-02 in favor of an
 * override_stock condition with source='wp_taxonomy' — see
 * StockOverrideController.php and Stock_Override_Resolver::TAXONOMY_SOURCE.
 *
 * @package MannMade\DataPipeline
 */

use MannMade\DataPipeline\Catalog\Catalog_Phase_Runner;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Save catalog rules ───────────────────────────────────────────────────── */

add_action( 'wp_ajax_mmi_save_catalog_rules', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
    }

    // Sanitise toggles
    $raw_toggles = $_POST['toggles'] ?? [];
    if ( ! is_array( $raw_toggles ) ) {
        $raw_toggles = [];
    }

    // 'apply_stock_overrides' was removed as a toggle — the override_stock
    // phase now always runs whenever mmi_stock_override_rules has any rules,
    // the same way the other bulk stock-writing phases (feed_sync) always
    // run. It used to be possible to leave this toggle off and have
    // feed_sync silently undo bulk overrides on every catalog update — see
    // Catalog_Phase_Runner's phase-order docblock.
    $toggles = [
        'cleanup_stock_meta'      => ! empty( $raw_toggles['cleanup_stock_meta'] ),
        'enforce_canonical_rules' => ! empty( $raw_toggles['enforce_canonical_rules'] ),
        'auto_assign_categories'  => ! empty( $raw_toggles['auto_assign_categories'] ),
        'auto_assign_brands'      => ! empty( $raw_toggles['auto_assign_brands'] ),
    ];

    $catalog_rules = $toggles;

    MMI_DB::set_setting( 'mmi_catalog_rules', $catalog_rules );

    mmi_data_pipeline_audit( 'settings.update', [
        'outcome' => 'success',
        'details' => [ 'keys' => [ 'mmi_catalog_rules' ], 'rules' => $catalog_rules ],
    ] );

    wp_send_json_success( [
        'message' => 'Catalog rules saved.',
        'rules'   => $catalog_rules,
        // Single shared formula (Catalog_Phase_Runner::get_active_rule_count())
        // — previously this endpoint and tab-pipeline.php's server render used
        // two independently-maintained counts that disagreed by one key.
        'count'   => Catalog_Phase_Runner::get_active_rule_count(),
    ] );
} );

/* ── Get catalog rules ────────────────────────────────────────────────────── */

add_action( 'wp_ajax_mmi_get_catalog_rules', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
    }

    $rules = Catalog_Phase_Runner::get_rules();

    wp_send_json_success( [ 'rules' => $rules ] );
} );

// The dedicated "get brand slugs for the brand-rule dropdown" endpoint
// (mmi_get_brand_slugs) was retired 2026-09-02 along with the separate
// Brand Rule row type it only ever fed — see StockOverrideController.php's
// wp_ajax_mmi_get_custom_rule_source_fields, which now returns every real
// taxonomy (including product_brand) for the generalized Custom Rule
// condition's Source='WordPress/WooCommerce' cascade.
