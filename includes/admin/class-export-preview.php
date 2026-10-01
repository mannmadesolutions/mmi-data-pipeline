<?php
/**
 * Export Preview Generator
 *
 * Mirrors MMI_Import_Preview's role but reads live records directly from the
 * DB via a MMI_Data_Type_Handler (no diffing — nothing is being written yet,
 * every row is simply "will be exported"). Reuses
 * MMI_Pipeline_Field_Resolver::apply_transform() so preview and the actual
 * exporter apply transforms identically by construction.
 *
 * Supports offset-based batch fetching so the preview table (export-preview.js)
 * can infinite-scroll through the full result set rather than being capped at
 * a single fixed-size sample — each request is still bounded per-call
 * (MAX_BATCH_LIMIT) to stay inside CLAUDE.md's DB query cost rules.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Export_Preview {

    // Per-request cap, independent of how many batches a client has scrolled
    // through cumulatively — keeps any single AJAX call's DB query bounded.
    private const MAX_BATCH_LIMIT = 500;

    private static ?self $instance = null;

    public static function instance(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_mmi_generate_export_preview', [ $this, 'handle_generate_preview' ] );
    }

    public function handle_generate_preview(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );

        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
            return;
        }

        $profile_id = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : '';
        // Per-request size is capped at MAX_BATCH_LIMIT; the client fetches
        // further batches at increasing $offset to page through the full
        // result set (infinite scroll), not just a fixed first sample.
        $limit  = min( self::MAX_BATCH_LIMIT, max( 1, (int) ( $_POST['limit'] ?? 200 ) ) );
        $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

        $profiles = MMI_DB::get_profiles();
        if ( ! isset( $profiles[ $profile_id ] ) ) {
            wp_send_json_error( [ 'message' => 'Unknown profile.' ] );
            return;
        }

        $data_type = $profiles[ $profile_id ]['data_type'] ?? 'product';

        // The Export tab's Data Type <select> lets a user preview a different
        // data type "without switching your saved profile" (see
        // tab-export.php) — but until now nothing here ever consulted it, so
        // the preview always silently rendered the profile's own real,
        // saved $data_type regardless of what was selected. Honor an
        // explicit override, validated against the registry so a tampered/
        // stale value can't request a nonexistent handler.
        $preview_data_type_raw = isset( $_POST['preview_data_type'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_data_type'] ) ) : '';
        $is_type_override      = $preview_data_type_raw !== ''
            && $preview_data_type_raw !== $data_type
            && array_key_exists( $preview_data_type_raw, MMI_Data_Type_Registry::get_choices_for_ui() );

        if ( $is_type_override ) {
            $data_type = $preview_data_type_raw;
        }

        $handler = MMI_Data_Type_Registry::get( $data_type );
        if ( ! $handler ) {
            wp_send_json_error( [ 'message' => "No handler registered for data type '{$data_type}'." ] );
            return;
        }

        if ( $is_type_override ) {
            // The saved profile's scope (product_identifier) and field
            // mappings are shaped for its OWN data type's filters/fields —
            // reusing them against an unrelated handler wouldn't just be
            // wrong, it would silently mix a different type's saved field
            // keys into this one's schema (MMI_Pipeline_Field_Schema_Resolver
            // ::merge() appends any saved key not in the schema as a
            // "fully-custom" field). An overridden preview is unfiltered and
            // uses the handler's own raw schema defaults only — no saved
            // profile-specific field mapping to (mis)apply.
            $scope                    = [];
            $field_mappings_profile_id = '';
        } else {
            $scope                    = is_array( $profiles[ $profile_id ]['product_identifier'] ?? null ) ? $profiles[ $profile_id ]['product_identifier'] : [];
            $field_mappings_profile_id = $profile_id;
        }

        try {
            $preview = $this->generate_preview( $handler, $field_mappings_profile_id, $data_type, $scope, $limit, $offset );
            wp_send_json_success( $preview );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    public function generate_preview( MMI_Data_Type_Handler $handler, string $profile_id, string $data_type, array $scope, int $limit = 200, int $offset = 0 ): array {
        $field_mappings = MMI_Pipeline_Field_Schema_Resolver::get_effective( $profile_id, $data_type, 'export' );
        $columns        = array_keys( array_filter( $field_mappings, fn( $c ) => ! empty( $c['enabled'] ) ) );
        $id_field       = $handler->get_id_field();

        // Every schema field (enabled or not), for the preview's "Columns"
        // popover — which now doubles as the field-mapping editor formerly
        // known as Step 2 (see export-preview.js). $columns above only
        // covers currently-enabled fields, since that's all this request
        // needs to fetch/transform real row values for; a disabled field's
        // row data isn't fetched until the user re-enables it and the
        // preview refetches.
        $field_config = [];
        foreach ( $field_mappings as $field => $config ) {
            $field_config[ $field ] = [
                'label'         => $config['label'] ?? $field,
                'group'         => $config['group'] ?? 'meta',
                'enabled'       => ! empty( $config['enabled'] ),
                'output_column' => $config['output_column'] ?? $field,
                'transform'     => $config['transform'] ?? 'none',
            ];
        }

        $result  = $handler->query_records( $scope, $limit, $offset );
        $records = $result['records'];
        $total   = $result['total'];

        $rows = [];
        foreach ( $records as $record ) {
            $flat = $handler->resolve_record( $record );
            $row  = [];
            foreach ( $columns as $field ) {
                $config    = $field_mappings[ $field ] ?? [];
                $transform = $config['transform'] ?? 'none';
                $params    = is_array( $config['transform_params'] ?? null ) ? $config['transform_params'] : [];
                $value     = $flat[ $field ] ?? '';
                $row[ $field ] = MMI_Pipeline_Field_Resolver::apply_transform( $value, $transform, $params, $flat );
            }
            // The ID column is always shown in the preview regardless of
            // whether it's marked "enabled" for export (see export-preview.js
            // — it's structural, not one of the toggleable $columns), so its
            // raw value and edit link must always be present even when
            // $columns doesn't include it.
            $id_value = $flat[ $id_field ] ?? '';
            if ( ! array_key_exists( $id_field, $row ) ) {
                $row[ $id_field ] = $id_value;
            }
            $row['__mmi_edit_url'] = $id_value !== '' ? $handler->get_edit_url( $id_value ) : '';
            $rows[] = $row;
        }

        return [
            'data_type'     => $data_type,
            'id_field'      => $id_field,
            'columns'       => $columns,
            'field_config'  => $field_config,
            'items'         => $rows,
            'offset'        => $offset,
            'total_records' => $total,
            'preview_count' => count( $rows ),
            'fully_sampled' => ( $offset + count( $rows ) ) >= $total,
        ];
    }
}

MMI_Export_Preview::instance();
