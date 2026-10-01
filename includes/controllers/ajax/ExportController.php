<?php
/**
 * Export AJAX Controller
 *
 * Direct sibling of ProductImportController.php — same JS-driven,
 * non-WP-Cron batch-polling pattern, applied to the export direction.
 *
 * @package MannMade\DataPipeline\AJAX
 */

namespace MannMade\DataPipeline\AJAX;

use MMI_DB;
use MMI_Data_Type_Registry;
use MMI_Export_File_Manager;
use MMI_Pipeline_Field_Schema_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExportController {

    public function __construct() {
        add_action( 'wp_ajax_mmi_pipeline_run_manual_export',     [ $this, 'run_manual_export' ] );
        add_action( 'wp_ajax_mmi_pipeline_process_export_batch',  [ $this, 'handle_process_export_batch' ] );
        add_action( 'wp_ajax_mmi_pipeline_export_status',         [ $this, 'get_export_status' ] );
        add_action( 'wp_ajax_mmi_pipeline_abort_export',          [ $this, 'handle_abort_export' ] );
        add_action( 'wp_ajax_mmi_pipeline_get_scope_fields_for_type', [ $this, 'get_scope_fields_for_type' ] );
        add_action( 'wp_ajax_mmi_pipeline_create_export_profile',     [ $this, 'create_export_profile' ] );
        add_action( 'wp_ajax_mmi_pipeline_update_export_profile',     [ $this, 'update_export_profile' ] );
        add_action( 'wp_ajax_mmi_pipeline_duplicate_export_profile',  [ $this, 'duplicate_export_profile' ] );
        add_action( 'wp_ajax_mmi_pipeline_delete_export_profile',     [ $this, 'delete_export_profile' ] );
        add_action( 'wp_ajax_mmi_pipeline_save_export_field_mapping', [ $this, 'save_export_field_mapping' ] );
        add_action( 'wp_ajax_mmi_pipeline_toggle_all_export_fields', [ $this, 'toggle_all_export_fields' ] );
        add_action( 'wp_ajax_mmi_pipeline_save_export_scope',         [ $this, 'save_export_scope' ] );
        add_action( 'wp_ajax_mmi_pipeline_save_export_schedule',      [ $this, 'save_export_schedule' ] );

        add_action( 'admin_post_mmi_pipeline_download_export', [ '\\MMI_Export_File_Manager', 'handle_download' ] );
    }

    /**
     * Autosave the export profile's recurrence frequency + default scheduled
     * format. Registering/clearing the actual wp_schedule_event() call is
     * handled by MMI_Pipeline_Export_Cron::init()'s self-heal check on the
     * next request (same lazy-registration pattern the import side's
     * schedule table already uses) — this handler only persists the choice.
     */
    public function save_export_schedule(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $profile_id = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';
        $frequency  = isset( $_POST['frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency'] ) ) : 'disabled';
        $format     = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'csv';

        $profiles = MMI_DB::get_profiles();
        if ( ! isset( $profiles[ $profile_id ] ) || ( $profiles[ $profile_id ]['direction'] ?? 'import' ) !== 'export' ) {
            wp_send_json_error( [ 'message' => 'Unknown export profile.' ] );
            return;
        }

        $allowed_frequencies = [ 'disabled', 'hourly', 'twicedaily', 'daily', 'weekly', 'fourhourly', 'sixhourly' ];
        if ( ! in_array( $frequency, $allowed_frequencies, true ) ) {
            $frequency = 'disabled';
        }
        $allowed_formats = [ 'csv', 'json', 'xml' ];
        if ( ! in_array( $format, $allowed_formats, true ) ) {
            $format = 'csv';
        }

        $hook = 'mmi_pipeline_export_profile_' . $profile_id;
        wp_clear_scheduled_hook( $hook );

        MMI_DB::set_setting( 'mmi_schedule_export_profile_' . $profile_id, $frequency );
        MMI_DB::set_setting( 'mmi_schedule_export_format_'  . $profile_id, $format );

        if ( $frequency !== 'disabled' ) {
            $schedules = wp_get_schedules();
            if ( isset( $schedules[ $frequency ] ) ) {
                wp_schedule_event( time(), $frequency, $hook );
            }
        }

        mmi_data_pipeline_audit( 'export_schedule.update', [
            'object_type' => 'export_profile',
            'object_id'   => $profile_id,
            'outcome'     => 'success',
            'details'     => [ 'frequency' => $frequency, 'format' => $format ],
        ] );

        wp_send_json_success();
    }

    /**
     * Persist Step 1's scope/filter values onto the profile's
     * product_identifier column (reused as the generic "scope" JSON blob for
     * every data type, not just product's by_identifier concept). Autosaved
     * on field change from export-settings.js, mirroring the field-mapping
     * autosave pattern.
     *
     * MMI_DB::upsert_profile() overwrites every column on each call (it's an
     * INSERT ... ON DUPLICATE KEY UPDATE across all columns, not a partial
     * patch) — so the existing profile row must be read first and every
     * other column re-supplied unchanged, or this save would silently wipe
     * the profile's name/data_type/direction/etc.
     */
    public function save_export_scope(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $profile_id = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';
        $profiles   = MMI_DB::get_profiles();

        if ( ! isset( $profiles[ $profile_id ] ) || ( $profiles[ $profile_id ]['direction'] ?? 'import' ) !== 'export' ) {
            wp_send_json_error( [ 'message' => 'Unknown export profile.' ] );
            return;
        }

        $raw_scope = isset( $_POST['scope'] ) ? json_decode( wp_unslash( $_POST['scope'] ), true ) : [];
        $scope     = self::sanitize_scope( is_array( $raw_scope ) ? $raw_scope : [] );

        $p = $profiles[ $profile_id ];
        MMI_DB::upsert_profile(
            $profile_id,
            $p['name'],
            $p['description']   ?? '',
            $p['import_mode']   ?? 'update-only',
            $p['mode_settings'] ?? [],
            $p['product_scope'] ?? 'all_products',
            $scope,
            $p['sources']    ?? [],
            $p['data_type']  ?? 'product',
            $p['direction']  ?? 'export'
        );

        wp_send_json_success();
    }

    /**
     * Recursively sanitize a scope filter payload. Every leaf is one of:
     * a plain scalar (sanitize_text_field), an array of such scalars (the
     * multi-select category/status/taxonomy-tree filters), or — only under
     * the 'meta_conditions' key — an array of {key, operator, value} rows
     * from the Custom Field Filters builder (export-scope-meta-conditions.php).
     * That last shape needs its own branch: the generic array-of-scalars
     * path below calls strval() on each element, which on an array element
     * emits a PHP "Array to string conversion" warning and silently
     * collapses it to the string "Array" — every condition row would be
     * lost.
     */
    private static function sanitize_scope( array $scope ): array {
        $clean = [];
        foreach ( $scope as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( $key === 'meta_conditions' && is_array( $value ) ) {
                $clean[ $key ] = self::sanitize_meta_conditions( $value );
            } elseif ( is_array( $value ) ) {
                $clean[ $key ] = array_map( 'sanitize_text_field', array_map( 'strval', $value ) );
            } elseif ( is_bool( $value ) ) {
                $clean[ $key ] = $value;
            } else {
                $clean[ $key ] = sanitize_text_field( (string) $value );
            }
        }
        return $clean;
    }

    /**
     * Sanitize the Custom Field Filters condition-row array. Rows missing a
     * key are dropped — they're not a usable filter (mirrors
     * MMI_Pipeline_Meta_Query_Builder::build()'s own skip-empty-key rule, so
     * an invalid row never round-trips back into the saved profile).
     */
    private static function sanitize_meta_conditions( array $conditions ): array {
        $clean = [];
        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) ) {
                continue;
            }
            $key = sanitize_key( (string) ( $condition['key'] ?? '' ) );
            if ( $key === '' ) {
                continue;
            }
            $clean[] = [
                'key'      => $key,
                'operator' => sanitize_text_field( (string) ( $condition['operator'] ?? 'equals' ) ),
                'value'    => sanitize_text_field( (string) ( $condition['value'] ?? '' ) ),
            ];
        }
        return $clean;
    }

    public function create_export_profile(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $data_type = isset( $_POST['data_type'] ) ? sanitize_text_field( wp_unslash( $_POST['data_type'] ) ) : 'product';

        if ( $name === '' ) {
            wp_send_json_error( [ 'message' => 'A profile name is required.' ] );
            return;
        }

        $profile_id = 'export_' . sanitize_title( $name ) . '_' . substr( md5( uniqid( '', true ) ), 0, 6 );

        MMI_DB::upsert_profile(
            $profile_id,
            $name,
            '',
            'update-only',
            [],
            'all_products',
            null,
            [],
            $data_type,
            'export'
        );

        mmi_data_pipeline_audit( 'export_profile.create', [
            'object_type' => 'export_profile',
            'object_id'   => $profile_id,
            'outcome'     => 'success',
            'details'     => [ 'data_type' => $data_type ],
        ] );

        wp_send_json_success( [ 'profile_id' => $profile_id ] );
    }

    /**
     * Save the Edit modal's Name + Data Type. If the data type changed,
     * scope (product_identifier) and field_mappings are reset — both are
     * specific to the previous data type's schema and would otherwise
     * silently carry stale/incompatible keys forward (e.g. a saved
     * "category" scope key that means nothing to an Order handler).
     */
    public function update_export_profile(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $profile_id = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';
        $profiles   = MMI_DB::get_profiles();

        if ( ! isset( $profiles[ $profile_id ] ) || ( $profiles[ $profile_id ]['direction'] ?? 'import' ) !== 'export' ) {
            wp_send_json_error( [ 'message' => 'Unknown export profile.' ] );
            return;
        }

        $name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $data_type = isset( $_POST['data_type'] ) ? sanitize_text_field( wp_unslash( $_POST['data_type'] ) ) : 'product';

        if ( $name === '' ) {
            wp_send_json_error( [ 'message' => 'A profile name is required.' ] );
            return;
        }

        $p               = $profiles[ $profile_id ];
        $data_type_changed = $data_type !== ( $p['data_type'] ?? 'product' );

        MMI_DB::upsert_profile(
            $profile_id,
            $name,
            $p['description']   ?? '',
            $p['import_mode']   ?? 'update-only',
            $p['mode_settings'] ?? [],
            $p['product_scope'] ?? 'all_products',
            $data_type_changed ? [] : ( $p['product_identifier'] ?? [] ),
            $p['sources'] ?? [],
            $data_type,
            'export'
        );

        if ( $data_type_changed ) {
            MMI_DB::delete_field_mappings( $profile_id );
        }

        mmi_data_pipeline_audit( 'export_profile.update', [
            'object_type' => 'export_profile',
            'object_id'   => $profile_id,
            'outcome'     => 'success',
            'details'     => [ 'data_type' => $data_type, 'data_type_changed' => $data_type_changed ],
        ] );

        wp_send_json_success( [ 'data_type_changed' => $data_type_changed ] );
    }

    /**
     * Clone an export profile — name, data type, scope, and field mappings
     * all carry over; the clone gets a fresh generated profile_id so it's
     * fully independent (edits to one never affect the other).
     */
    public function duplicate_export_profile(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $source_id = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';
        $profiles  = MMI_DB::get_profiles();

        if ( ! isset( $profiles[ $source_id ] ) || ( $profiles[ $source_id ]['direction'] ?? 'import' ) !== 'export' ) {
            wp_send_json_error( [ 'message' => 'Unknown export profile.' ] );
            return;
        }

        $source      = $profiles[ $source_id ];
        $new_name    = $source['name'] . ' (Copy)';
        $new_id      = 'export_' . sanitize_title( $new_name ) . '_' . substr( md5( uniqid( '', true ) ), 0, 6 );

        MMI_DB::upsert_profile(
            $new_id,
            $new_name,
            $source['description']   ?? '',
            $source['import_mode']   ?? 'update-only',
            $source['mode_settings'] ?? [],
            $source['product_scope'] ?? 'all_products',
            $source['product_identifier'] ?? [],
            $source['sources'] ?? [],
            $source['data_type'] ?? 'product',
            'export'
        );

        $field_mappings = MMI_DB::get_field_mappings( $source_id );
        if ( ! empty( $field_mappings ) ) {
            MMI_DB::set_field_mappings( $new_id, $field_mappings );
        }

        mmi_data_pipeline_audit( 'export_profile.duplicate', [
            'object_type' => 'export_profile',
            'object_id'   => $new_id,
            'outcome'     => 'success',
            'details'     => [ 'source_profile' => $source_id ],
        ] );

        wp_send_json_success( [ 'profile_id' => $new_id ] );
    }

    public function delete_export_profile(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $profile_id = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';
        $profiles   = MMI_DB::get_profiles();

        if ( ! isset( $profiles[ $profile_id ] ) || ( $profiles[ $profile_id ]['direction'] ?? 'import' ) !== 'export' ) {
            wp_send_json_error( [ 'message' => 'Unknown export profile.' ] );
            return;
        }

        MMI_DB::delete_profile( $profile_id );

        mmi_data_pipeline_audit( 'export_profile.delete', [
            'object_type' => 'export_profile',
            'object_id'   => $profile_id,
            'outcome'     => 'success',
        ] );

        wp_send_json_success();
    }

    /**
     * Save one field's enabled/output_column/transform config for an export
     * profile's field mapping (autosave, mirrors ImportSettingsController's
     * per-field autosave pattern but writing flat config, not per-supplier).
     */
    public function save_export_field_mapping(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $profile_id = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';
        $field      = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
        if ( $profile_id === '' || $field === '' ) {
            wp_send_json_error( [ 'message' => 'Missing profile or field.' ] );
            return;
        }

        $mappings = MMI_DB::get_field_mappings( $profile_id );
        if ( ! is_array( $mappings ) ) {
            $mappings = [];
        }

        $mappings[ $field ] = [
            'enabled'       => ! empty( $_POST['enabled'] ),
            'output_column' => isset( $_POST['output_column'] ) ? sanitize_text_field( wp_unslash( $_POST['output_column'] ) ) : $field,
            'transform'     => isset( $_POST['transform'] ) ? sanitize_text_field( wp_unslash( $_POST['transform'] ) ) : 'none',
        ];

        MMI_DB::set_field_mappings( $profile_id, $mappings );
        wp_send_json_success();
    }

    /**
     * Master "toggle all" for the field-mapping table header — a single
     * batched write rather than one save_export_field_mapping() AJAX call
     * per row (a real product/CPT export can have 20-30+ fields; firing
     * that many concurrent autosave requests is exactly the fan-out
     * pattern CLAUDE.md's Server Load rules prohibit on this RAM-constrained
     * server). Reads the full effective field list from the schema
     * resolver, not just MMI_DB::get_field_mappings()'s already-saved rows —
     * a field the user never touched is still "enabled" via the resolver's
     * implicit default, so turning "all" off must explicitly persist
     * enabled=false for it too, not silently skip it.
     */
    public function toggle_all_export_fields(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $profile_id = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';
        $enabled    = ! empty( $_POST['enabled'] );
        if ( $profile_id === '' ) {
            wp_send_json_error( [ 'message' => 'Missing profile.' ] );
            return;
        }

        $profiles  = MMI_DB::get_profiles();
        $data_type = $profiles[ $profile_id ]['data_type'] ?? 'product';

        $effective = MMI_Pipeline_Field_Schema_Resolver::get_effective( $profile_id, $data_type, 'export' );
        $mappings  = MMI_DB::get_field_mappings( $profile_id );
        if ( ! is_array( $mappings ) ) {
            $mappings = [];
        }

        foreach ( $effective as $field => $config ) {
            $mappings[ $field ] = [
                'enabled'       => $enabled,
                'output_column' => $mappings[ $field ]['output_column'] ?? $config['output_column'] ?? $field,
                'transform'     => $mappings[ $field ]['transform']     ?? $config['transform']     ?? 'none',
            ];
        }

        MMI_DB::set_field_mappings( $profile_id, $mappings );
        wp_send_json_success( [ 'enabled' => $enabled ] );
    }

    public function run_manual_export(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );

        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        if ( Batch_Export_State::is_locked() ) {
            wp_send_json_error( [ 'message' => 'An export is already running. Please wait for it to finish.', 'already_running' => true ] );
            return;
        }

        $profile_id = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';
        $profiles   = MMI_DB::get_profiles();

        if ( ! isset( $profiles[ $profile_id ] ) || ( $profiles[ $profile_id ]['direction'] ?? 'import' ) !== 'export' ) {
            wp_send_json_error( [ 'message' => 'Unknown export profile.' ] );
            return;
        }

        $data_type = $profiles[ $profile_id ]['data_type'] ?? 'product';
        $handler   = MMI_Data_Type_Registry::get( $data_type );
        if ( ! $handler ) {
            wp_send_json_error( [ 'message' => "No handler registered for data type '{$data_type}'." ] );
            return;
        }

        // Each data type also declares the capability its records need (e.g.
        // 'list_users' for users, 'manage_woocommerce' for orders/customers) —
        // enforced on top of the export tier so remapping that tier via the
        // capability filter can't widen access to customer data.
        if ( ! current_user_can( $handler->required_capability() ) ) {
            mmi_data_pipeline_audit( 'export.run', [
                'object_type' => 'export_profile',
                'object_id'   => $profile_id,
                'outcome'     => 'denied',
                'details'     => [ 'data_type' => $data_type ],
            ] );
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $scope   = is_array( $profiles[ $profile_id ]['product_identifier'] ?? null ) ? $profiles[ $profile_id ]['product_identifier'] : [];
        $precount = $handler->query_records( $scope, 1, 0 );
        $total    = (int) $precount['total'];

        if ( $total === 0 ) {
            wp_send_json_error( [ 'no_records' => true, 'message' => 'No records match this export profile\'s scope.' ] );
            return;
        }

        $format = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'csv';
        $run_id = MMI_Export_File_Manager::build_run_id( $profiles[ $profile_id ]['name'] ?? $profile_id );

        Batch_Export_State::acquire_lock();
        Batch_Export_State::set_progress( [
            'status'     => 'running',
            'profile'    => $profile_id,
            'data_type'  => $data_type,
            'run_id'     => $run_id,
            'format'     => $format,
            'offset'     => 0,
            'total'      => $total,
            'exported'   => 0,
            'errors'     => 0,
            'started_at' => current_time( 'mysql' ),
        ] );

        mmi_data_pipeline_audit( 'export.run', [
            'object_type' => 'export_profile',
            'object_id'   => $profile_id,
            'outcome'     => 'success',
            'details'     => [ 'data_type' => $data_type, 'format' => $format, 'run_id' => $run_id, 'total' => $total ],
        ] );

        wp_send_json_success( [ 'run_id' => $run_id, 'total' => $total, 'queued' => true ] );
    }

    public function handle_process_export_batch(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        @set_time_limit( 300 );
        ignore_user_abort( true );

        $progress = Data_Export_Worker::process_batch();
        wp_send_json_success( $progress );
    }

    public function get_export_status(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $progress = Batch_Export_State::get_progress();
        wp_send_json_success( $progress ?? [ 'status' => 'idle' ] );
    }

    public function handle_abort_export(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }
        if ( ! Batch_Export_State::is_locked() ) {
            wp_send_json_error( [ 'message' => 'No export is currently running.' ] );
            return;
        }

        $progress = Batch_Export_State::get_progress() ?? [];
        $progress['status'] = 'aborted';
        Batch_Export_State::set_progress( $progress );
        Batch_Export_State::release_lock();

        mmi_data_pipeline_audit( 'export.abort', [
            'object_type' => 'export_profile',
            'object_id'   => $progress['profile'] ?? '',
            'outcome'     => 'success',
        ] );

        wp_send_json_success( $progress );
    }

    /**
     * Return the scope/filter fields partial (HTML fragment) for a given
     * data type, loaded via AJAX when the user changes the data-type
     * dropdown on the Export tab — keeps the initial page payload small
     * instead of pre-rendering every handler's filter form.
     */
    public function get_scope_fields_for_type(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $data_type = isset( $_POST['data_type'] ) ? sanitize_text_field( wp_unslash( $_POST['data_type'] ) ) : 'product';

        ob_start();
        include MMI_PIPELINE_PATH . 'admin/views/partials/export-scope-fields.php';
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }
}

// Initialize controller
new ExportController();
