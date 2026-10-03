<?php
/**
 * Product Workbench AJAX endpoints
 *
 *   mmi_workbench_search  — one page of matching products (+ total, category counts)
 *   mmi_workbench_preview — how many of the selection an action would change, with samples
 *   mmi_workbench_start   — create an apply job for the selection
 *   mmi_workbench_run     — process the job's next batch (client loops until done)
 *   mmi_workbench_stop    — stop a running job where it is
 *   mmi_workbench_undo    — undo the job's next batch (client loops until undone)
 *   mmi_workbench_jobs    — Recent Changes list
 *   mmi_workbench_set_terms — set one product's categories or brand from the results table
 *   mmi_workbench_save_search   — save the conditions as a named search (condition library "wb:")
 *   mmi_workbench_delete_search — delete one (from the library's Delete)
 *
 * All share the Import tab's nonce and manage_woocommerce capability.
 *
 * @package MannMade\DataPipeline
 */

use MannMade\DataPipeline\Workbench\Product_Workbench;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Nonce + capability + WooCommerce gate shared by every Workbench endpoint. */
function mmi_workbench_ajax_guard(): void {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
    }
    if ( ! MMI_Pipeline_Admin::is_woocommerce_active() ) {
        wp_send_json_error( [ 'message' => 'WooCommerce is required for Product Workbench.' ] );
    }
}

/** $_POST[$key] as an unslashed array. */
function mmi_workbench_post_array( string $key ): array {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard runs first; each value is sanitized downstream.
    return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : [];
}

/** Action + params from the request, validated, or a JSON error. */
function mmi_workbench_request_action(): array {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $action = sanitize_key( wp_unslash( $_POST['wb_action'] ?? '' ) );
    $valid  = Product_Workbench::sanitize_action( $action, mmi_workbench_post_array( 'params' ) );
    if ( is_wp_error( $valid ) ) {
        wp_send_json_error( [ 'message' => $valid->get_error_message() ] );
    }
    return $valid;
}

/** Selected product IDs from the request, or a JSON error. */
function mmi_workbench_request_ids(): array {
    $ids = Product_Workbench::resolve_selection( mmi_workbench_post_array( 'selection' ) );
    if ( is_wp_error( $ids ) ) {
        wp_send_json_error( [ 'message' => $ids->get_error_message() ] );
    }
    return $ids;
}

add_action( 'wp_ajax_mmi_workbench_search', function () {
    mmi_workbench_ajax_guard();
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    $result = Product_Workbench::search(
        Product_Workbench::sanitize_filter( mmi_workbench_post_array( 'filter' ) ),
        absint( $_POST['page'] ?? 1 ),
        absint( $_POST['per_page'] ?? 50 ),
        sanitize_key( wp_unslash( $_POST['sort'] ?? 'title' ) ),
        sanitize_key( wp_unslash( $_POST['dir'] ?? 'asc' ) ),
        ! empty( $_POST['fresh'] )
    );
    // phpcs:enable
    wp_send_json_success( $result );
} );

add_action( 'wp_ajax_mmi_workbench_preview', function () {
    mmi_workbench_ajax_guard();
    $valid = mmi_workbench_request_action();
    wp_send_json_success( Product_Workbench::preview( mmi_workbench_request_ids(), $valid['action'], $valid['params'] ) );
} );

add_action( 'wp_ajax_mmi_workbench_start', function () {
    mmi_workbench_ajax_guard();
    $valid = mmi_workbench_request_action();
    $ids   = mmi_workbench_request_ids();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $label = sanitize_text_field( wp_unslash( $_POST['filter_label'] ?? '' ) );
    wp_send_json_success( [ 'job' => Product_Workbench::start_job( $ids, $valid['action'], $valid['params'], $label ) ] );
} );

add_action( 'wp_ajax_mmi_workbench_run', function () {
    mmi_workbench_ajax_guard();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $result = Product_Workbench::run_batch( sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ), ! empty( $_POST['resume'] ) );
    isset( $result['error'] ) ? wp_send_json_error( [ 'message' => $result['error'] ] ) : wp_send_json_success( $result );
} );

add_action( 'wp_ajax_mmi_workbench_stop', function () {
    mmi_workbench_ajax_guard();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $job = Product_Workbench::stop_job( sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ) );
    if ( $job ) {
        mmi_data_pipeline_audit( 'workbench.stop', [
            'object_type' => 'workbench_job',
            'object_id'   => $job['id'],
            'outcome'     => 'success',
        ] );
    }
    $job ? wp_send_json_success( [ 'job' => $job ] ) : wp_send_json_error( [ 'message' => 'Unknown job.' ] );
} );

add_action( 'wp_ajax_mmi_workbench_undo', function () {
    mmi_workbench_ajax_guard();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $result = Product_Workbench::undo_batch( sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ) );
    isset( $result['error'] ) ? wp_send_json_error( [ 'message' => $result['error'] ] ) : wp_send_json_success( $result );
} );

add_action( 'wp_ajax_mmi_workbench_jobs', function () {
    mmi_workbench_ajax_guard();
    wp_send_json_success( [ 'jobs' => Product_Workbench::job_list() ] );
} );

add_action( 'wp_ajax_mmi_workbench_save_search', function () {
    mmi_workbench_ajax_guard();
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    $label = (string) wp_unslash( $_POST['label'] ?? '' );
    $logic = sanitize_key( wp_unslash( $_POST['match_logic'] ?? 'all' ) );
    // phpcs:enable
    $id = Product_Workbench::save_search( $label, $logic, mmi_workbench_post_array( 'conditions' ) );
    if ( is_wp_error( $id ) ) {
        wp_send_json_error( [ 'message' => $id->get_error_message() ] );
    }
    mmi_data_pipeline_audit( 'workbench.search.save', [
        'object_type' => 'workbench_search',
        'object_id'   => $id,
        'outcome'     => 'success',
    ] );
    wp_send_json_success( [ 'id' => $id ] );
} );

add_action( 'wp_ajax_' . Product_Workbench::SAVED_SEARCH_DELETE, function () {
    mmi_workbench_ajax_guard();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
    if ( ! Product_Workbench::delete_search( $id ) ) {
        wp_send_json_error( [ 'message' => 'That saved search no longer exists.' ] );
    }
    mmi_data_pipeline_audit( 'workbench.search.delete', [
        'object_type' => 'workbench_search',
        'object_id'   => $id,
        'outcome'     => 'success',
    ] );
    wp_send_json_success();
} );

add_action( 'wp_ajax_mmi_workbench_set_terms', function () {
    mmi_workbench_ajax_guard();
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    $row = Product_Workbench::set_product_terms(
        absint( $_POST['product_id'] ?? 0 ),
        sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) ),
        array_map( 'absint', (array) ( $_POST['term_ids'] ?? [] ) )
    );
    // phpcs:enable
    is_wp_error( $row ) ? wp_send_json_error( [ 'message' => $row->get_error_message() ] ) : wp_send_json_success( [ 'row' => $row ] );
} );
