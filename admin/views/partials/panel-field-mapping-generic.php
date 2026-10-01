<?php
/**
 * Generic Field Mapping Panel — non-Product data types (Phase 2, Milestone 2)
 *
 * Rendered instead of panel-field-mapping.php (the Product-specific,
 * per-supplier multi-column table) whenever a profile's data_type isn't
 * 'product'. Deliberately a SEPARATE, much simpler template rather than a
 * branch inside that file — see DATA_PIPELINE_PHASE2_SCOPING.md Milestone 2
 * for why: panel-field-mapping.php's per-supplier 'source' sub-arrays,
 * autosave races (see this plugin's own Incident History — five prior
 * recurrences of "wizard changes aren't saving" trace directly to that
 * file), and 9 hidden per-transform param groups are all real complexity
 * this data shape doesn't have and doesn't need. Every non-product data
 * type's field mapping is the simple shape MMI_Dynamic_Record_Importer's
 * own docblock already documents: one field -> one source path, no
 * per-supplier branching (a profile pulls from a single assigned source at
 * a time for these types, unlike Product's many-suppliers-into-one-field
 * model).
 *
 * Expects in scope (set by FieldMappingPanelController.php before including
 * this file): $current_profile (string), $data_type (string), $handler
 * (MMI_Data_Type_Handler).
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// $current_profile/$data_type are always set by FieldMappingPanelController.php
// (its only caller) before including this file — guarded here anyway so
// this file is self-consistent for static analysis, defaulting to the
// same values that controller itself falls back to.
$current_profile = $current_profile ?? 'default';
$data_type        = $data_type        ?? 'product';

// $handler has no safe fake default to fall back to (it's an interface,
// not a concrete class) — a missing/invalid instance here means something
// upstream is genuinely broken, so this renders an honest "unavailable"
// state and stops, rather than fabricating a handler or fataling on the
// very next line's method call.
if ( ! isset( $handler ) || ! $handler instanceof MMI_Data_Type_Handler ) {
    echo '<p class="description">Field mapping is unavailable for this data type.</p>';
    return;
}

$mmi_generic_fm_schema    = $handler->get_field_schema();
$mmi_generic_fm_effective = MMI_Pipeline_Field_Schema_Resolver::get_effective( $current_profile, $data_type, 'import' );
$mmi_generic_fm_id_field  = $handler->get_id_field();

/* The record's own identity field is excluded from this editable list, same
 * treatment as the Export side's Columns popover (export-preview.js) gives
 * its id_field — matching an incoming row to an existing record is the
 * Primary Key mechanism's job (Step 1's per-source "Match against" config,
 * same as Product), not a per-field mapping concern. */
unset( $mmi_generic_fm_schema[ $mmi_generic_fm_id_field ] );

$mmi_generic_fm_transforms = [
    'none'        => 'None',
    'uppercase'   => 'Uppercase',
    'lowercase'   => 'Lowercase',
    'trim'        => 'Trim',
    'date_format' => 'Date Format (Y-m-d)',
];
?>
<div class="mmi-generic-field-mapping" id="mmi-generic-field-mapping" data-profile="<?php echo esc_attr( $current_profile ); ?>" data-data-type="<?php echo esc_attr( $data_type ); ?>">
    <p class="description mmi-pipeline-mb-14">
        Map each field to a source path in your feed (dot notation for nested values, e.g.
        <code class="mmi-inline-code">webassets.name</code>). Only <?php echo esc_html( $handler->get_label() ); ?> fields
        are shown — this step doesn't yet support the same per-transform options, per-supplier
        overrides, or conditions Product's Field Mapping step has.
    </p>
    <?php
    // Table-wide "N mapped / M skipped" summary — see panel-field-mapping.php's
    // identical treatment (2026-09-12) for why: the Enabled column below is
    // gone, so this is the complete-picture replacement for it.
    $mmi_generic_fm_total  = count( $mmi_generic_fm_schema );
    $mmi_generic_fm_mapped = 0;
    foreach ( $mmi_generic_fm_schema as $mmi_gfk => $mmi_gfd ) {
        $mmi_gfe = $mmi_generic_fm_effective[ $mmi_gfk ] ?? $mmi_gfd;
        if ( ! empty( $mmi_gfd['required'] ) || trim( (string) ( $mmi_gfe['source'] ?? '' ) ) !== '' ) {
            $mmi_generic_fm_mapped++;
        }
    }
    ?>
    <p class="mmi-fm-total-summary mmi-pipeline-mb-14">
        <span class="mmi-fm-total-mapped"><?php echo (int) $mmi_generic_fm_mapped; ?> mapped</span>
        <span class="mmi-fm-total-skipped"><?php echo (int) ( $mmi_generic_fm_total - $mmi_generic_fm_mapped ); ?> skipped</span>
    </p>
    <div class="mmi-fm-table-wrap">
        <table class="mmi-uniform-table" id="mmi-generic-fm-table">
            <thead>
                <tr>
                    <th>Field</th>
                    <th>Source Path</th>
                    <th>Transform</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $mmi_generic_fm_schema as $mmi_field_key => $mmi_field_defaults ) :
                    $mmi_field_effective = $mmi_generic_fm_effective[ $mmi_field_key ] ?? $mmi_field_defaults;
                    $mmi_field_required  = ! empty( $mmi_field_defaults['required'] );
                    $mmi_field_source    = $mmi_field_effective['source'] ?? '';
                    $mmi_field_transform = $mmi_field_effective['transform'] ?? 'none';
                    // Enabled is no longer a manual toggle (2026-09-12) — a
                    // field is mapped, and therefore enabled, purely by having
                    // a real source path, same rule as the Product Field
                    // Mapping table. GenericFieldMappingController.php's save
                    // handler derives and persists the same value server-side.
                    $mmi_field_mapped    = $mmi_field_required || trim( (string) $mmi_field_source ) !== '';
                    ?>
                    <tr class="mmi-uniform-row generic-fm-row" data-field="<?php echo esc_attr( $mmi_field_key ); ?>" data-required="<?php echo $mmi_field_required ? '1' : '0'; ?>">
                        <td>
                            <strong><?php echo esc_html( $mmi_field_defaults['label'] ?? $mmi_field_key ); ?></strong>
                            <?php if ( $mmi_field_required ) : ?>
                                <span class="description">(always mapped)</span>
                            <?php elseif ( ! $mmi_field_mapped ) : ?>
                                <span class="mmi-field-not-mapped-pill mmi-badge" title="No source path configured — this field is skipped on import.">Not mapped</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="text" class="widefat generic-fm-source"
                                   value="<?php echo esc_attr( $mmi_field_source ); ?>"
                                   placeholder="e.g. <?php echo esc_attr( $mmi_field_key ); ?>" autocomplete="off">
                        </td>
                        <td>
                            <select class="generic-fm-transform">
                                <?php foreach ( $mmi_generic_fm_transforms as $mmi_t_key => $mmi_t_label ) : ?>
                                    <option value="<?php echo esc_attr( $mmi_t_key ); ?>" <?php selected( $mmi_field_transform, $mmi_t_key ); ?>><?php echo esc_html( $mmi_t_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="mmi-generic-fm-status description" id="mmi-generic-fm-status" aria-live="polite"></p>
</div>
