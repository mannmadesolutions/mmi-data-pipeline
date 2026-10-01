<?php
/**
 * Edit Export Profile Modal
 *
 * Same single-panel edit-modal chrome as the Import side's
 * modal-edit-profile.php (mmi-modal-box / mmi-ep-section / mmi-ep-section-title
 * classes, all already defined in import-settings.css) — but with far fewer
 * sections, since an export profile's scope/field-mapping configuration
 * lives in the Export tab's own Step 1/2, not in this modal (the Import
 * side's edit modal embeds scope/identifier/mode because THAT profile has
 * no equivalent "Step 1" to configure it separately). Name + Data Type are
 * the only properties an export profile carries independent of the tab's
 * step sections.
 *
 * All form IDs are prefixed "eep-" (edit-export-profile) so they never
 * collide with the Import side's "ep-" prefixed edit modal, which can be
 * open in the same DOM (both tabs' partials render into the same page).
 *
 * Opened by #edit-export-profile-btn; submitted via
 * mmi_pipeline_update_export_profile AJAX.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$_mmi_eep_data_type_choices = class_exists( 'MMI_Data_Type_Registry' ) ? MMI_Data_Type_Registry::get_choices_for_ui() : [];
?>

<div class="mmi-modal-backdrop" id="edit-export-profile-modal" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="eep-modal-title">
    <div class="mmi-modal-box mmi-edit-profile-modal">

        <!-- ── Header ───────────────────────────────────────────────── -->
        <div class="mmi-modal-header">
            <div class="mmi-modal-header-top">
                <h3 id="eep-modal-title">⚙️ Edit Export Profile</h3>
                <button type="button" class="mmi-modal-close" id="eep-modal-close" data-close aria-label="Close">&times;</button>
            </div>
            <p class="description mmi-pipeline-mt-xs">
                Changes are saved immediately when you click <strong>Save Changes</strong>.
            </p>
        </div>

        <!-- ── Body ─────────────────────────────────────────────────── -->
        <div class="mmi-modal-body mmi-ep-body">

            <!-- ── 1. Profile Name ──────────────────────────────────── -->
            <div class="mmi-ep-section">
                <h4 class="mmi-ep-section-title">Profile Name</h4>
                <div class="mmi-modal-field">
                    <input type="text" id="eep-profile-name" class="widefat"
                           placeholder="e.g. Full Catalog Export, Category Price List…" autocomplete="off">
                </div>
            </div>

            <!-- ── 2. Data Type ──────────────────────────────────────── -->
            <div class="mmi-ep-section">
                <h4 class="mmi-ep-section-title">Data Type</h4>
                <p class="description mmi-pipeline-mb-12">
                    Changing this resets Step 1 scope and Step 2 field mapping for this profile — they're
                    specific to the previously selected data type.
                </p>
                <select id="eep-data-type" class="widefat">
                    <?php foreach ( $_mmi_eep_data_type_choices as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div><!-- /.mmi-ep-body -->

        <!-- ── Footer ───────────────────────────────────────────────── -->
        <div class="mmi-modal-footer">
            <button type="button" class="mmi-btn-profile" id="eep-modal-cancel" data-close>Cancel</button>
            <button type="button" class="button button-primary mmi-action-btn" id="eep-modal-save">💾 Save Changes</button>
        </div>

    </div><!-- /.mmi-modal-box -->
</div><!-- /#edit-export-profile-modal -->
