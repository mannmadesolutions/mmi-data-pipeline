<?php
/**
 * Export Scope: Custom Field Filters (post meta conditions)
 *
 * Included by export-scope-fields.php for every postmeta-backed data type
 * (product, post/page/CPT). Renders a repeatable key/operator/value
 * condition-row builder — the row-add/remove pattern already established in
 * this plugin for catalog rules (panel-stock-overrides.php's
 * .mmi-condition-row), but a fresh, contained implementation here rather
 * than reusing that file's JS, which is bound to a different feature.
 *
 * Expects in scope: $scope_data_type, $scope_saved, $meta_key_options
 * (string[] from MMI_Meta_Key_Discovery — used only as <datalist> suggestions,
 * not a hard whitelist; any key can still be typed).
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// All three are always set by export-scope-fields.php before including this
// partial (its only caller) — guarded here anyway so this file is
// self-consistent for static analysis and safe if ever included elsewhere
// without them.
$meta_key_options  = $meta_key_options ?? [];
$scope_saved       = $scope_saved ?? [];
$scope_data_type   = $scope_data_type ?? '';
$saved_conditions  = is_array( $scope_saved['meta_conditions'] ?? null ) ? $scope_saved['meta_conditions'] : [];
$datalist_id       = 'mmi-meta-key-options-' . sanitize_html_class( $scope_data_type );

$condition_operators = [
    'equals'       => 'Equals',
    'not_equals'   => 'Not Equals',
    'contains'     => 'Contains',
    'not_contains' => "Doesn't Contain",
    'greater_than' => 'Greater Than',
    'less_than'    => 'Less Than',
    'exists'       => 'Exists',
    'not_exists'   => "Doesn't Exist",
];
?>
<div class="mmi-modal-field">
    <label><strong>Custom Field Filters</strong> <span class="description">(optional)</span></label>

    <datalist id="<?php echo esc_attr( $datalist_id ); ?>">
        <?php foreach ( $meta_key_options as $meta_key ) : ?>
            <option value="<?php echo esc_attr( $meta_key ); ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <div class="mmi-meta-conditions mmi-scope-field" data-scope-key="meta_conditions" data-scope-meta-conditions="1">
        <?php
        $rows = ! empty( $saved_conditions ) ? $saved_conditions : [ [ 'key' => '', 'operator' => 'equals', 'value' => '' ] ];
        foreach ( $rows as $row ) :
        ?>
        <div class="mmi-meta-condition-row">
            <input type="text" class="mmi-meta-cond-key" list="<?php echo esc_attr( $datalist_id ); ?>" placeholder="Meta key…" value="<?php echo esc_attr( $row['key'] ?? '' ); ?>">
            <select class="mmi-meta-cond-operator">
                <?php foreach ( $condition_operators as $op_key => $op_label ) : ?>
                    <option value="<?php echo esc_attr( $op_key ); ?>" <?php selected( $row['operator'] ?? 'equals', $op_key ); ?>><?php echo esc_html( $op_label ); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" class="mmi-meta-cond-value" placeholder="Value…" value="<?php echo esc_attr( $row['value'] ?? '' ); ?>">
            <button type="button" class="button-link mmi-meta-cond-remove" aria-label="Remove this filter">&times;</button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="button mmi-action-btn mmi-meta-cond-add">
        <span class="dashicons dashicons-plus-alt2"></span> Add Filter
    </button>
    <span class="description">Filter by any custom field — start typing a key for suggestions, or enter your own. "Value" is ignored for Exists/Doesn't Exist.</span>
</div>
