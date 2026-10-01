<?php
/**
 * Catalog Update Controller — Phased Execution
 *
 * Thin AJAX surface over MannMade\DataPipeline\Catalog\Catalog_Phase_Runner —
 * the single execution path also used by the scheduled cron/Action Scheduler
 * chain and `wp mmi catalog`. This controller no longer builds or gates the
 * phase list itself (that used to disagree with the client-built list the
 * old JS assembled from window.mmiCatalogRules, and disagreed AGAIN with
 * what the scheduled cron ran) — it only starts a run, executes one phase of
 * an already-persisted run, and finalizes it.
 *
 * Capability: manage_woocommerce, not manage_options — this endpoint's
 * effect is entirely WooCommerce catalog data (stock status, categories,
 * brands), and manage_woocommerce is the narrower of the two capabilities
 * already used across this feature's three controllers (Stock Overrides
 * used manage_woocommerce, Catalog Rules and this controller used
 * manage_options) — standardized here rather than left disagreeing.
 *
 * @package MannMade\DataPipeline
 */

use MannMade\DataPipeline\Catalog\Catalog_Run_State;
use MannMade\DataPipeline\Catalog\Catalog_Phase_Runner;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Start a new run. Returns the full, server-built phase list (gates already
 * evaluated) plus a run_id every subsequent call must present. Refuses to
 * start if a run — from the button, the scheduled cron, or WP-CLI — is
 * already in progress, since all three now share the identical lock
 * (MannMade\DataPipeline\Catalog\Catalog_Run_State::LOCK_KEY).
 */
add_action( 'wp_ajax_mmi_catalog_start_run', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
    }

    if ( ! Catalog_Phase_Runner::is_enabled() ) {
        wp_send_json_error( [ 'code' => 'disabled', 'message' => 'Catalog Maintenance is disabled — enable it via the section toggle before running.' ] );
    }

    $run_id = Catalog_Run_State::try_acquire( 'ajax' );
    if ( $run_id === null ) {
        $lock = Catalog_Run_State::get_lock_row();
        wp_send_json_error( [
            'code'       => 'locked',
            'message'    => 'A catalog update is already running.',
            'started_at' => $lock['started_at'] ?? null,
            'owner'      => $lock['owner'] ?? null,
        ] );
    }

    $phases = Catalog_Phase_Runner::build_phases();
    Catalog_Run_State::save_state( $run_id, [
        'phases'          => $phases,
        'results'         => [],
        'current_index'   => 0,
        'total_processed' => 0,
        'started_at'      => current_time( 'mysql' ),
    ] );

    mmi_data_pipeline_audit( 'catalog.run', [
        'object_type' => 'catalog_run',
        'object_id'   => $run_id,
        'outcome'     => 'success',
        'details'     => [ 'phases' => count( $phases ) ],
    ] );

    wp_send_json_success( [
        'run_id' => $run_id,
        'phases' => $phases,
        'total'  => count( $phases ),
    ] );
} );

/**
 * Execute one phase, addressed by index into the run's own persisted phase
 * list — the browser can no longer inject a phase, a supplier, or a
 * brand_oos_rules payload; every input for what a phase does was already
 * decided server-side at start time.
 */
add_action( 'wp_ajax_mmi_catalog_run_phase', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
    }

    $run_id = sanitize_text_field( $_POST['run_id'] ?? '' );
    $index  = (int) ( $_POST['index'] ?? -1 );

    if ( $run_id === '' || $index < 0 ) {
        wp_send_json_error( [ 'message' => 'run_id and index are required.' ] );
    }

    if ( ! Catalog_Run_State::reacquire( $run_id ) ) {
        wp_send_json_error( [ 'code' => 'lost_lock', 'message' => 'This run is no longer active (lock lost or expired).' ] );
    }

    $result = Catalog_Phase_Runner::run_phase( $run_id, $index );
    wp_send_json_success( $result );
} );

/**
 * Close out a run — either a normal completion after the last phase, or an
 * explicit abort (browser navigated away / a phase errored and the client
 * gave up). Always releases the lock; MUST be called exactly once per run,
 * which is why the client also fires this from a beforeunload sendBeacon.
 */
add_action( 'wp_ajax_mmi_catalog_finish_run', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
    }

    $run_id  = sanitize_text_field( $_POST['run_id'] ?? '' );
    $aborted = ! empty( $_POST['aborted'] );

    if ( $run_id === '' ) {
        wp_send_json_error( [ 'message' => 'run_id is required.' ] );
    }

    if ( $aborted ) {
        MMI_Logger::warn( "Catalog run aborted by client run_id={$run_id}", [], 'sync', 'CatalogUpdateController' );
        Catalog_Run_State::delete_state( $run_id );
        Catalog_Run_State::release( $run_id );
        mmi_data_pipeline_audit( 'catalog.abort', [
            'object_type' => 'catalog_run',
            'object_id'   => $run_id,
            'outcome'     => 'success',
        ] );
        wp_send_json_success( [ 'aborted' => true ] );
    }

    $result = Catalog_Phase_Runner::finalize( $run_id );
    Catalog_Run_State::release( $run_id );

    wp_send_json_success( $result );
} );

/**
 * Master enable/disable switch for the whole Catalog Maintenance section —
 * see Catalog_Phase_Runner::is_enabled()'s own docblock for every real entry
 * point this single setting gates. Deliberately not blocked by an in-
 * progress run: turning it off never aborts a run already dispatched, it
 * only stops a NEW one (button, cron, or CLI) from starting.
 */
add_action( 'wp_ajax_mmi_toggle_catalog_maintenance', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
    }

    $enabled = ! empty( $_POST['enabled'] );
    MMI_DB::set_setting( Catalog_Phase_Runner::ENABLED_SETTING_KEY, $enabled );

    MMI_Logger::info(
        'Catalog Maintenance master switch set to ' . ( $enabled ? 'enabled' : 'disabled' ),
        [], 'sync', 'CatalogUpdateController'
    );

    mmi_data_pipeline_audit( 'settings.update', [
        'outcome' => 'success',
        'details' => [ 'keys' => [ Catalog_Phase_Runner::ENABLED_SETTING_KEY ], 'enabled' => $enabled ],
    ] );

    wp_send_json_success( [ 'enabled' => $enabled ] );
} );
