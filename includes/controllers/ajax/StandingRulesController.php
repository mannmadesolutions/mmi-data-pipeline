<?php
/**
 * Product Workbench — standing rules and catalog upkeep endpoints
 *
 *   mmi_workbench_rules         — every standing rule, in run order, with its grade and last run
 *   mmi_workbench_rule_check    — the efficacy check for a search + change before it is saved
 *   mmi_workbench_rule_save     — save a search + change as a standing rule (new, or over rule_id)
 *   mmi_workbench_rule_enable   — switch a rule on or off
 *   mmi_workbench_rule_move     — move a rule earlier or later in the run order
 *   mmi_workbench_rule_delete   — delete a rule (its stock overrides are released)
 *   mmi_workbench_rule_hold     — run one Force In/Out of Stock rule now
 *   mmi_workbench_upkeep        — catalog upkeep: switch, schedule, steps, last run, health
 *
 * Catalog upkeep's own writes keep their existing endpoints, shared with the
 * scheduled run: mmi_toggle_catalog_maintenance, mmi_save_catalog_rules,
 * mmi_autosave_schedule (process catalog_update) and
 * mmi_catalog_start_run / run_phase / finish_run.
 *
 * A change that isn't Force In/Out of Stock is run now as an ordinary
 * Workbench job (mmi_workbench_start), so it gets a preview and Undo.
 *
 * @package MannMade\DataPipeline
 */

use MannMade\DataPipeline\Catalog\Catalog_Phase_Runner;
use MannMade\DataPipeline\Catalog\Catalog_Run_State;
use MannMade\DataPipeline\Workbench\Rule_Efficacy;
use MannMade\DataPipeline\Workbench\Standing_Rules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The rule id from the request, or a JSON error. */
function mmi_workbench_request_rule_id(): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard runs first.
    $id = Standing_Rules::sanitize_id( wp_unslash( $_POST['rule_id'] ?? '' ) );
    if ( $id === '' ) {
        wp_send_json_error( [ 'message' => 'That rule no longer exists. Reload the page.' ] );
    }
    return $id;
}

/** A rule built from the request's search + change, or a JSON error. */
function mmi_workbench_request_rule(): array {
    // phpcs:disable WordPress.Security.NonceVerification.Missing
    $id   = Standing_Rules::sanitize_id( wp_unslash( $_POST['rule_id'] ?? '' ) );
    $base = [];
    if ( $id !== '' ) {
        foreach ( Standing_Rules::all() as $saved ) {
            if ( $saved['id'] === $id ) {
                $base = [ 'id' => $id, 'enabled' => ! empty( $saved['enabled'] ) ];
            }
        }
    }
    $rule = Standing_Rules::build(
        mmi_workbench_post_array( 'filter' ),
        sanitize_key( wp_unslash( $_POST['wb_action'] ?? '' ) ),
        mmi_workbench_post_array( 'params' ),
        (string) wp_unslash( $_POST['name'] ?? '' ),
        $base
    );
    // phpcs:enable
    if ( is_wp_error( $rule ) ) {
        wp_send_json_error( [ 'message' => $rule->get_error_message() ] );
    }
    return $rule;
}

/** Catalog upkeep's settings and state, for its Workbench section. */
function mmi_workbench_upkeep_state(): array {
    $phases   = Catalog_Phase_Runner::build_phases();
    $switches = Catalog_Phase_Runner::get_rules();
    $keys     = [ 'cleanup' => 'cleanup_stock_meta', 'canonical' => 'enforce_canonical_rules', 'categories' => 'auto_assign_categories', 'brands' => 'auto_assign_brands' ];
    $about    = [
        'cleanup'        => 'Removes empty and duplicate stock-status rows that cause wrong stock readings.',
        'canonical'      => 'Keeps listings marked as duplicates (canonical = FALSE) out of stock and out of search.',
        'feed_sync'      => 'Puts products out of stock when their SKU leaves this supplier\'s feed, and back in stock when it returns.',
        'override_stock' => 'Applies the standing rules above, in their order.',
        'categories'     => 'Gives uncategorized products categories from their names and other taxonomies.',
        'brands'         => 'Gives unbranded products a brand from their SKU prefix. Only adds, never removes.',
    ];
    $kinds    = [ 'feed_sync' => 'From data sources', 'override_stock' => 'Standing rules' ];
    $steps    = [];
    foreach ( $phases as $p ) {
        $key     = $keys[ $p['phase'] ] ?? '';
        $steps[] = [
            'phase'       => $p['phase'],
            'label'       => $p['label'],
            'about'       => $about[ $p['phase'] ] ?? '',
            'kind'        => $kinds[ $p['phase'] ] ?? 'Maintenance',
            'switch'      => $key,
            'on'          => $key !== '' ? ! empty( $switches[ $key ] ) : null,
            'skipped'     => (bool) $p['skipped'],
            'skip_reason' => (string) $p['skip_reason'],
        ];
    }
    $freq = (string) MMI_DB::get_setting( 'mmi_schedule_catalog_update', 'sixhourly' );
    $next = wp_next_scheduled( 'mmi_scheduled_catalog_update' );
    $lock = Catalog_Run_State::is_locked() ? Catalog_Run_State::get_lock_row() : null;
    $last = (string) MMI_DB::get_setting( 'mmi_last_run_catalog_update', '' );
    return [
        'enabled'  => Catalog_Phase_Runner::is_enabled(),
        'schedule' => [
            'frequency' => $freq,
            'time'      => (string) MMI_DB::get_setting( 'mmi_schedule_time_catalog_update', '' ),
            'next'      => $next ? sprintf( '%s from now', human_time_diff( $next, time() ) ) : 'Not scheduled',
            'options'   => [ 'disabled' => 'Disabled', 'hourly' => 'Every hour', 'sixhourly' => 'Every 6 hours', 'twicedaily' => 'Twice daily', 'daily' => 'Daily' ],
        ],
        'steps'    => $steps,
        'last_run' => $last === '' ? null : [
            'at'        => $last,
            'ago'       => human_time_diff( strtotime( get_gmt_from_date( $last ) ), time() ) . ' ago',
            'duration'  => (string) MMI_DB::get_setting( 'mmi_catalog_update_duration', '' ),
            'processed' => (int) MMI_DB::get_setting( 'mmi_catalog_update_products_processed', 0 ),
        ],
        'health'   => Catalog_Phase_Runner::health( $phases ),
        'running'  => $lock ? [ 'owner' => (string) ( $lock['owner'] ?? '' ), 'started_at' => (string) ( $lock['started_at'] ?? '' ) ] : null,
    ];
}

add_action( 'wp_ajax_mmi_workbench_rules', function () {
    mmi_workbench_ajax_guard();
    Rule_Efficacy::refresh_later_if_stale();
    wp_send_json_success( [ 'rules' => Standing_Rules::listing() ] );
} );

add_action( 'wp_ajax_mmi_workbench_rule_check', function () {
    mmi_workbench_ajax_guard();
    $rule   = mmi_workbench_request_rule();
    $rules  = Standing_Rules::all();
    $others = array_values( array_filter( $rules, static fn( $r ) => $r['id'] !== $rule['id'] ) );
    $at     = null;
    foreach ( $rules as $i => $r ) {
        if ( $r['id'] === $rule['id'] ) {
            $at = $i;
        }
    }
    wp_send_json_success( Rule_Efficacy::predict( $rule, $others, $at ) );
} );

add_action( 'wp_ajax_mmi_workbench_rule_save', function () {
    mmi_workbench_ajax_guard();
    $is_new = Standing_Rules::sanitize_id( wp_unslash( $_POST['rule_id'] ?? '' ) ) === ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $rule   = Standing_Rules::save( mmi_workbench_request_rule() );
    $grades = Rule_Efficacy::refresh();
    mmi_data_pipeline_audit( 'catalog_rules.update', [
        'object_type' => 'standing_rule',
        'object_id'   => $rule['id'],
        'outcome'     => 'success',
        'details'     => [ 'keys' => [ Standing_Rules::SETTING ], 'new' => $is_new, 'action' => $rule['action'] ],
    ] );
    wp_send_json_success( [ 'rule' => [ 'id' => $rule['id'], 'name' => $rule['name'] ], 'efficacy' => $grades[ $rule['id'] ] ?? null ] );
} );

add_action( 'wp_ajax_mmi_workbench_rule_enable', function () {
    mmi_workbench_ajax_guard();
    $id      = mmi_workbench_request_rule_id();
    $enabled = ! empty( $_POST['enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! Standing_Rules::set_enabled( $id, $enabled ) ) {
        wp_send_json_error( [ 'message' => 'That rule no longer exists. Reload the page.' ] );
    }
    Rule_Efficacy::refresh_later_if_stale();
    mmi_data_pipeline_audit( 'catalog_rules.update', [
        'object_type' => 'standing_rule',
        'object_id'   => $id,
        'outcome'     => 'success',
        'details'     => [ 'keys' => [ Standing_Rules::SETTING ], 'enabled' => $enabled ],
    ] );
    wp_send_json_success();
} );

add_action( 'wp_ajax_mmi_workbench_rule_move', function () {
    mmi_workbench_ajax_guard();
    $id = mmi_workbench_request_rule_id();
    $by = (int) ( $_POST['by'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    if ( ! Standing_Rules::move( $id, $by ) ) {
        wp_send_json_error( [ 'message' => 'That rule can\'t move further.' ] );
    }
    Rule_Efficacy::refresh_later_if_stale();
    mmi_data_pipeline_audit( 'catalog_rules.update', [
        'object_type' => 'standing_rule',
        'object_id'   => $id,
        'outcome'     => 'success',
        'details'     => [ 'keys' => [ Standing_Rules::SETTING ], 'moved' => $by < 0 ? 'earlier' : 'later' ],
    ] );
    wp_send_json_success();
} );

add_action( 'wp_ajax_mmi_workbench_rule_delete', function () {
    mmi_workbench_ajax_guard();
    $id = mmi_workbench_request_rule_id();
    if ( ! Standing_Rules::delete( $id ) ) {
        wp_send_json_error( [ 'message' => 'That rule no longer exists. Reload the page.' ] );
    }
    mmi_data_pipeline_audit( 'catalog_rules.update', [
        'object_type' => 'standing_rule',
        'object_id'   => $id,
        'outcome'     => 'success',
        'details'     => [ 'keys' => [ Standing_Rules::SETTING ], 'deleted' => true ],
    ] );
    wp_send_json_success();
} );

add_action( 'wp_ajax_mmi_workbench_rule_hold', function () {
    mmi_workbench_ajax_guard();
    $id     = mmi_workbench_request_rule_id();
    $result = Standing_Rules::apply_one( $id );
    if ( $result === null ) {
        wp_send_json_error( [ 'message' => 'Only a Force In/Out of Stock rule runs this way.' ] );
    }
    mmi_data_pipeline_audit( 'catalog_rule.apply', [
        'object_type' => 'standing_rule',
        'object_id'   => $id,
        'outcome'     => 'success',
        'details'     => $result,
    ] );
    wp_send_json_success( $result );
} );

add_action( 'wp_ajax_mmi_workbench_upkeep', function () {
    mmi_workbench_ajax_guard();
    wp_send_json_success( mmi_workbench_upkeep_state() );
} );
