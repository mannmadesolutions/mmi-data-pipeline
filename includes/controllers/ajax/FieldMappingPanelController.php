<?php
/**
 * Field Mapping Panel AJAX Controller
 *
 * Renders panel-field-mapping.php's actual table content (~1MB of HTML for a
 * profile with several suppliers — 28+ fields x N suppliers, each with up to
 * 9 hidden per-transform param groups plus a full conditions builder) on
 * demand, instead of it being inlined into every admin.php?page=mmi-data-pipeline
 * page load regardless of whether the "Create/Edit Import Profile" wizard's
 * Field Mapping step is ever opened. See panel-field-mapping.php's own
 * $mmi_field_mapping_render_now docblock and import-settings.js's
 * wizardMaybeLoadFieldMappingPanel() for the two other halves of this change.
 *
 *   mmi_render_field_mapping_panel — render the field mapping table for a
 *   given profile and return it as HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_mmi_render_field_mapping_panel', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    // Reproduce the exact variable setup tab-pipeline.php performs before
    // including panel-field-mapping.php (directly, via section-profile-wizard.php)
    // so this AJAX-rendered copy is byte-for-byte what the old inline render
    // would have produced for the same profile.
    $profiles        = MMI_DB::get_profiles_by_direction( 'import' );
    $current_profile = isset( $_POST['profile'] ) ? sanitize_text_field( wp_unslash( $_POST['profile'] ) ) : 'default';

    if ( isset( $profiles[ $current_profile ] ) ) {
        $current_profile_meta     = $profiles[ $current_profile ];
        $profile_assigned_sources = $current_profile_meta['sources'] ?? [];
        $data_type                = $current_profile_meta['data_type'] ?? 'product';
    } else {
        // No saved row for this profile id — this is the Create Import
        // Profile wizard's Step 3, opened for a profile that hasn't been
        // saved yet (mmi_save_import_profile only runs when the wizard's
        // final step is submitted; see openProfileWizard()'s own comment on
        // the breadcrumb jump-navigation guard). There is nothing here to
        // silently substitute: falling back to an arbitrary EXISTING
        // profile (the previous behavior, array_key_first( $profiles )) is
        // wrong — it renders that unrelated profile's real, saved field
        // mappings and, for a non-Product data type, its Product-shaped
        // table too, regardless of what the wizard's own Data Type step has
        // selected. The client sends its live, in-progress Data Type
        // selection explicitly for exactly this case — see
        // wizardMaybeLoadFieldMappingPanel() in import-settings.js.
        $requested_data_type = isset( $_POST['data_type'] ) ? sanitize_text_field( wp_unslash( $_POST['data_type'] ) ) : 'product';
        $data_type            = array_key_exists( $requested_data_type, MMI_Data_Type_Registry::get_choices_for_ui() )
            ? $requested_data_type
            : 'product';
        $current_profile_meta = [];

        // The wizard's own Step 2 already knows which sources are checked
        // for this not-yet-saved profile — sent explicitly for the same
        // reason data_type is above (see wizardSelectedSources() in
        // import-settings.js, already used identically for
        // mmi_pipeline_validate_profile_config). Without this,
        // panel-field-mapping.php's own $mmi_restrict_to_assigned_sources
        // check treats an empty array as "no restriction," rendering a full
        // row for every configured supplier in the system, not just the
        // ones this profile will actually use.
        $requested_sources = isset( $_POST['sources'] ) ? json_decode( wp_unslash( $_POST['sources'] ), true ) : [];
        $profile_assigned_sources = is_array( $requested_sources )
            ? array_values( array_filter( array_map( 'sanitize_key', $requested_sources ) ) )
            : [];
    }

    // Non-Product data types get a separate, much simpler template — see
    // panel-field-mapping-generic.php's own docblock for why this is a
    // distinct file rather than a branch inside panel-field-mapping.php
    // (Milestone 2, DATA_PIPELINE_PHASE2_SCOPING.md).
    if ( 'product' !== $data_type ) {
        $handler = MMI_Data_Type_Registry::get( $data_type );
        if ( ! $handler ) {
            wp_send_json_error( [ 'message' => 'Unknown data type: ' . $data_type ] );
        }

        ob_start();
        include MMI_PIPELINE_PATH . 'admin/views/partials/panel-field-mapping-generic.php';
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    $configured_suppliers = MMI_Pipeline_Admin::get_configured_suppliers();
    $field_mappings       = MMI_DB::get_field_mappings( $current_profile );

    require_once MMI_PIPELINE_PATH . 'admin/views/partials/default-field-mappings.php';

    // Render the real table (not the placeholder).
    $mmi_field_mapping_render_now = true;

    ob_start();
    include MMI_PIPELINE_PATH . 'admin/views/partials/panel-field-mapping.php';
    $html = ob_get_clean();

    // The wizard always sends its ticked sources. A profile still being
    // created can already have a saved placeholder row (any autosave makes
    // one) with no sources, so fall back to what the wizard sent.
    $wizard_sources = isset( $_POST['sources'] ) ? json_decode( wp_unslash( $_POST['sources'] ), true ) : [];
    $draft_sources  = is_array( $wizard_sources ) && $wizard_sources ? $wizard_sources : (array) $profile_assigned_sources;

    wp_send_json_success( [
        'html'      => $html,
        'conflicts' => (object) MMI_Pipeline_Field_Conflicts::for_profile( $current_profile, $draft_sources ),
    ] );
} );
