<?php
/**
 * Generic Field Mapping AJAX Controller (Phase 2, Milestone 2)
 *
 * Save endpoint for panel-field-mapping-generic.php — the non-Product Field
 * Mapping step's simple {enabled, source, transform} shape, distinct from
 * Product's per-supplier autosave endpoints in ImportSettingsController.php.
 * See DATA_PIPELINE_PHASE2_SCOPING.md Milestone 2 for why this is a
 * separate, deliberately smaller controller rather than another branch
 * inside that file's already-large set of Product-specific handlers.
 *
 *   mmi_pipeline_save_generic_field_mapping — save one field's config for a
 *   non-Product import profile.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_mmi_pipeline_save_generic_field_mapping', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $profile_id = sanitize_text_field( wp_unslash( $_POST['profile'] ?? '' ) );
    $field      = sanitize_text_field( wp_unslash( $_POST['field'] ?? '' ) );

    if ( empty( $profile_id ) || empty( $field ) ) {
        wp_send_json_error( [ 'message' => 'Profile and field are required' ] );
    }

    $source    = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
    $transform = sanitize_text_field( wp_unslash( $_POST['transform'] ?? 'none' ) );
    $required  = ! empty( $_POST['required'] );

    // Enabled is no longer a manual toggle (2026-09-12, matching the same
    // change on the Product Field Mapping table) — a field is mapped, and
    // therefore enabled, purely by having a real source path. $required
    // covers a schema field the caller has marked always-mapped even before
    // a source is filled in (panel-field-mapping-generic.php's own
    // "(always mapped)" fields), read from a data attribute rather than
    // re-deriving the schema here, since this controller has no cheap way
    // to reconstruct the data-type handler for an arbitrary profile.
    $enabled = $required || '' !== $source;

    // update_field_mappings() (mmi-hub/includes/class-mmi-db.php) does the
    // real work: an atomic read-modify-write under a per-profile MySQL named
    // lock, the same primitive Product's own field-mapping autosave uses —
    // see this document's own Incident History ("Wizard Save & Exit Race")
    // for what happens without it. Merging onto the field's existing config
    // (rather than replacing it wholesale) preserves any sibling keys a
    // future milestone adds (transform_params, conditions) that this
    // deliberately minimal Milestone 2 UI doesn't expose yet.
    $saved = MMI_DB::update_field_mappings(
        $profile_id,
        function ( array $mappings ) use ( $field, $enabled, $source, $transform ) {
            $mappings[ $field ] = array_merge(
                $mappings[ $field ] ?? [],
                [
                    'enabled'   => $enabled,
                    'source'    => $source,
                    'transform' => $transform,
                ]
            );
            return $mappings;
        }
    );

    if ( ! $saved ) {
        wp_send_json_error( [ 'message' => 'Could not save — another save is already in progress, try again' ] );
    }

    wp_send_json_success( [ 'message' => 'Saved' ] );
} );
