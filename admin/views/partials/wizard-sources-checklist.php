<?php
/**
 * Wizard Step 2 — Data Sources Checklist
 *
 * Split off from the old wizard-sources-list.php (see
 * IMPORT_WIZARD_INLINE_SECTION_HANDOFF.md, "Step regrouping") — this half
 * is JUST the checkbox + name + at-a-glance Primary Key status per source;
 * the actual Primary Key editor controls moved to their own Step 3, see
 * wizard-sources-pk-editor.php. Both halves are rendered from the SAME
 * $configured_suppliers loop so a source's row always exists in both
 * places, matched up by data-supplier — the Step 3 editor row starts
 * hidden and is revealed by the Step 2 checkbox's own change handler (see
 * import-settings.js), not by DOM nesting, since the two rows no longer
 * share a common ancestor the way the combined row used to.
 *
 * Rendered TWICE from the exact same markup: once inline at page load (via
 * section-profile-wizard.php), and once again on demand via AJAX
 * (mmi_pipeline_get_wizard_sources_html in ImportSettingsController.php)
 * whenever the wizard opens — the page's initial render goes stale the
 * moment a source is added anywhere else (the separate Data Sources tab,
 * another browser tab, etc.) without a full reload of THIS page. See the
 * "Wizard Source List Went Stale" incident in AGENTS.md.
 *
 * Expects $configured_suppliers to already be set (associative, keyed by
 * supplier_id — see MMI_Pipeline_Admin::get_configured_suppliers()).
 *
 * @package MannMade\DataPipeline
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($configured_suppliers) || !is_array($configured_suppliers)) {
    $configured_suppliers = [];
}
?>
<?php
// Group by source type — matches the "Add New Data Source" modal's own
// tile order, and wizard-sources-pk-editor.php's identical grouping, so all
// three surfaces read as one consistent system. 'other' is a safety net for
// any legacy/unrecognized source_type value, never a real label choice.
$mmi_type_labels = [
    'upload'  => 'Upload File',
    'url'     => 'Direct URL',
    'dropbox' => 'Dropbox',
    'gdrive'  => 'Google Drive',
    'api'     => 'API',
    'other'   => 'Other',
];
$mmi_sources_by_type = [];
foreach ($configured_suppliers as $src_id => $src) {
    $type = $src['source_type'] ?? '';
    if (!isset($mmi_type_labels[$type])) {
        $type = 'other';
    }
    $mmi_sources_by_type[$type][$src_id] = $src;
}
?>
<?php if (empty($configured_suppliers)) : ?>
    <p class="description">No data sources configured yet.</p>
<?php else : ?>
    <?php foreach ($mmi_type_labels as $mmi_type_key => $mmi_type_label) :
        if (empty($mmi_sources_by_type[$mmi_type_key])) {
            continue;
        }
    ?>
    <div class="mmi-source-type-group">
        <div class="mmi-source-type-group-label"><?php echo esc_html($mmi_type_label); ?></div>
        <?php foreach ($mmi_sources_by_type[$mmi_type_key] as $src_id => $src) :
            // Same "never pre-check the match-type radio" reasoning as the
            // editor half — see wizard-sources-pk-editor.php's own comment.
            // Only $pk_configured/$pk_display are actually needed here, for
            // the at-a-glance status line.
            $pk_wc_raw       = MMI_DB::get_primary_key($src_id, 'wc', '');
            $pk_configured   = $pk_wc_raw !== '';
            $known_sentinels = [ '_sku' => 'sku', '__post_id' => 'post_id', '__post_title' => 'post_title' ];
            $pk_match_type   = $known_sentinels[ $pk_wc_raw ] ?? ( $pk_configured ? 'custom' : '' );
            $pk_display_labels = [ 'sku' => 'SKU', 'post_id' => 'Post ID', 'post_title' => 'Post Title' ];
            $pk_display      = $pk_display_labels[ $pk_match_type ] ?? $pk_wc_raw;
        ?>
        <div class="mmi-source-row" data-supplier="<?php echo esc_attr($src_id); ?>">
            <label class="mmi-ep-source-checkbox">
                <input type="checkbox"
                       class="np-source-check"
                       value="<?php echo esc_attr($src_id); ?>">
                <span class="mmi-ep-source-name"><?php echo esc_html($src['supplier_name']); ?></span>
                <?php if (!empty($src['source_type'])) : ?>
                <span class="mmi-pgc-source-pill mmi-pgc-source-pill--spaced"><?php echo esc_html(strtoupper($src['source_type'])); ?></span>
                <?php endif; ?>
            </label>
            <div class="mmi-source-pk-status" data-supplier="<?php echo esc_attr($src_id); ?>">
                <?php if ($pk_configured) : ?>
                    <span class="mmi-source-pk-ok"><span class="dashicons dashicons-yes-alt"></span> Primary Key: <?php echo esc_html($pk_display); ?></span>
                <?php else : ?>
                    <span class="mmi-source-pk-missing">Primary Key: not set</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
