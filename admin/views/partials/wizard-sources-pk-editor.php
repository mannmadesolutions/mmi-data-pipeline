<?php
/**
 * Wizard Step 3 — Sources & Keys (Primary Key editor)
 *
 * Split off from the old wizard-sources-list.php (see
 * IMPORT_WIZARD_INLINE_SECTION_HANDOFF.md, "Step regrouping") — this half
 * is the actual per-source Primary Key editor, now its own step since
 * screen width is no longer constrained by a fixed 1500px modal box. The
 * checkbox + at-a-glance status half stays in Step 2, see
 * wizard-sources-checklist.php.
 *
 * Every row here starts "mmi-is-hidden" (matching the OLD combined row's
 * default, unchecked state) and is revealed by Step 2's own np-source-check
 * change handler (import-settings.js), matched up by data-supplier rather
 * than DOM nesting, since this row no longer shares a common ancestor with
 * its checkbox — see that handler's own comment.
 *
 * Rendered TWICE from the exact same markup: once inline at page load (via
 * section-profile-wizard.php), and once again on demand via AJAX
 * (mmi_pipeline_get_wizard_sources_html in ImportSettingsController.php) —
 * see wizard-sources-checklist.php's own docblock for why.
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
// Same grouping as wizard-sources-checklist.php, so a source appears in the
// same relative position/group in both steps.
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
            // Primary key is scoped to the SOURCE (mmi_vip_primary_key_{supplier}_{type}),
            // not the profile — every profile using this source shares whatever is
            // configured here. Raw '' default (not a '_sku' display default) is
            // deliberate: it's how we tell "never configured" apart from "explicitly
            // set to SKU matching". Pre-checking the SKU radio for this state used
            // to be a genuine bug, not just a display nuance — every radio input's
            // 'change' event only fires on a real user interaction, so a source
            // whose SKU radio was only ever pre-checked by this default, never
            // clicked, silently never autosaved anything and stayed genuinely
            // unconfigured — while visually looking identical to one the user had
            // deliberately confirmed. No radio is pre-checked now; the "Match
            // against" choice must be an explicit click, which is also what
            // triggers the autosave that actually persists it.
            $pk_wc_raw       = MMI_DB::get_primary_key($src_id, 'wc', '');
            $pk_configured   = $pk_wc_raw !== '';
            $pk_source_val   = MMI_DB::get_primary_key($src_id, 'source', 'id');
            $known_sentinels = [ '_sku' => 'sku', '__post_id' => 'post_id', '__post_title' => 'post_title' ];
            $pk_match_type   = $known_sentinels[ $pk_wc_raw ] ?? ( $pk_configured ? 'custom' : '' );
            $pk_custom_value = ( $pk_match_type === 'custom' ) ? $pk_wc_raw : '';
            // A file-choice dropdown is only meaningful when there's more than
            // one real file to pick between (API sources like Xchange/SkuPort
            // that export several named JSON files). Every other source type
            // resolves to exactly one fallback option ("{$sid}-products.json" =>
            // "Products") — showing a single-option select for those offers no
            // actual choice, so it's carried as a hidden field instead; the
            // browse-fields button and datalist still need to know which file.
            //
            // pk_file_options (not file_options) deliberately excludes
            // enrichment-only files like xchange-web-assets.json — there's no
            // primary key to match against an enrichment file, since its
            // fields are merged onto an already-matched product by SKU, not
            // looked up independently. Field Mapping's own file browser still
            // offers it via the unfiltered file_options.
            $mmi_file_opts = $src['pk_file_options'] ?? ( $src['file_options'] ?? [] );
            $mmi_has_file_choice = count($mmi_file_opts) > 1;
        ?>
        <div class="mmi-source-row mmi-is-hidden" data-supplier="<?php echo esc_attr($src_id); ?>">
            <div class="mmi-source-pk-editor-heading">
                <strong><?php echo esc_html($src['supplier_name']); ?></strong>
                <?php if (!empty($src['source_type'])) : ?>
                <span class="mmi-pgc-source-pill mmi-pgc-source-pill--spaced"><?php echo esc_html(strtoupper($src['source_type'])); ?></span>
                <?php endif; ?>
            </div>
            <div class="mmi-source-pk-editor" data-supplier="<?php echo esc_attr($src_id); ?>">
                <div class="mmi-pk-relationship-row">
                    <div class="mmi-pk-source-col field-selector-group">
                        <label class="mmi-pk-field-label">Field in the file</label>
                        <?php if ($mmi_has_file_choice) : ?>
                            <select class="pk-file-selector" data-supplier="<?php echo esc_attr($src_id); ?>">
                                <?php foreach ($mmi_file_opts as $file_val => $file_label) : ?>
                                    <option value="<?php echo esc_attr($file_val); ?>"><?php echo esc_html($file_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif (!empty($mmi_file_opts)) : ?>
                            <?php /* Only one real file for this source — nothing to
                                 choose, but the browse button/datalist below still
                                 need to know which filename to read. */ ?>
                            <input type="hidden" class="pk-file-selector" data-supplier="<?php echo esc_attr($src_id); ?>" value="<?php echo esc_attr(array_key_first($mmi_file_opts)); ?>">
                        <?php endif; ?>
                        <?php /* Options are replaced with the file's real detected
                             fields by JS (loadFieldsFromFile()) once the wizard
                             loads; this bootstrap option just keeps the saved
                             value visible/selected before that finishes. "Custom /
                             Other…" reveals the plain-text fallback for a nested
                             dot-path or a field the sample scan didn't catch. */ ?>
                        <select id="pk-field-<?php echo esc_attr($src_id); ?>"
                                class="primary-key-source-input"
                                data-supplier="<?php echo esc_attr($src_id); ?>"
                                data-field-type="source">
                            <?php if ($pk_source_val !== '') : ?>
                                <option value="<?php echo esc_attr($pk_source_val); ?>"><?php echo esc_html($pk_source_val); ?></option>
                            <?php endif; ?>
                            <option value="__custom__">✎ Custom / Other…</option>
                        </select>
                        <input type="text"
                               class="primary-key-source-custom-input mmi-is-hidden"
                               data-supplier="<?php echo esc_attr($src_id); ?>"
                               value=""
                               placeholder="e.g. attributes.mpn">
                    </div>
                    <span class="mmi-pk-relationship-arrow" aria-hidden="true">→</span>
                    <div class="mmi-pk-target-col pk-match-group">
                    <span class="mmi-pk-field-label">Match against</span>
                    <div class="mmi-pk-match-radios">
                        <input type="radio" class="primary-key-wc-select"
                               name="pk_match_<?php echo esc_attr($src_id); ?>"
                               data-supplier="<?php echo esc_attr($src_id); ?>"
                               id="pk-match-sku-<?php echo esc_attr($src_id); ?>"
                               value="_sku" <?php checked($pk_match_type, 'sku'); ?>>
                        <label for="pk-match-sku-<?php echo esc_attr($src_id); ?>" class="mmi-pk-match-label">SKU</label>

                        <input type="radio" class="primary-key-wc-select"
                               name="pk_match_<?php echo esc_attr($src_id); ?>"
                               data-supplier="<?php echo esc_attr($src_id); ?>"
                               id="pk-match-postid-<?php echo esc_attr($src_id); ?>"
                               value="__post_id" <?php checked($pk_match_type, 'post_id'); ?>>
                        <label for="pk-match-postid-<?php echo esc_attr($src_id); ?>" class="mmi-pk-match-label">Post ID</label>

                        <input type="radio" class="primary-key-wc-select"
                               name="pk_match_<?php echo esc_attr($src_id); ?>"
                               data-supplier="<?php echo esc_attr($src_id); ?>"
                               id="pk-match-posttitle-<?php echo esc_attr($src_id); ?>"
                               value="__post_title" <?php checked($pk_match_type, 'post_title'); ?>>
                        <label for="pk-match-posttitle-<?php echo esc_attr($src_id); ?>" class="mmi-pk-match-label">Post Title</label>

                        <input type="radio" class="primary-key-wc-select"
                               name="pk_match_<?php echo esc_attr($src_id); ?>"
                               data-supplier="<?php echo esc_attr($src_id); ?>"
                               id="pk-match-custom-<?php echo esc_attr($src_id); ?>"
                               value="custom" <?php checked($pk_match_type, 'custom'); ?>>
                        <label for="pk-match-custom-<?php echo esc_attr($src_id); ?>" class="mmi-pk-match-label">Custom Field</label>

                        <?php /* Options are replaced with the store's known product
                             meta keys by JS (mmiImportSettings.productMetaKeys)
                             once the wizard loads. "Custom / Other…" reveals the
                             plain-text fallback below for a meta key that doesn't
                             exist as product meta yet — importing is what will
                             create it. */ ?>
                        <select class="primary-key-wc-custom-select<?php echo $pk_match_type !== 'custom' ? ' mmi-is-hidden' : ''; ?>"
                                data-supplier="<?php echo esc_attr($src_id); ?>">
                            <?php if ($pk_custom_value !== '') : ?>
                                <option value="<?php echo esc_attr($pk_custom_value); ?>"><?php echo esc_html($pk_custom_value); ?></option>
                            <?php endif; ?>
                            <option value="__custom__">✎ Custom / Other…</option>
                        </select>
                        <input type="text"
                               class="primary-key-wc-custom-input mmi-is-hidden"
                               data-supplier="<?php echo esc_attr($src_id); ?>"
                               value="<?php echo esc_attr($pk_custom_value); ?>"
                               placeholder="_supplier_part_number">
                    </div>
                    </div>
                </div>
                <?php /* Scans the real feed for blank/duplicate values in whichever
                     field is currently selected above — see
                     MMI_Pipeline_Config_Validator::scan_primary_key_quality()
                     and assets/js/pk-quality-modal.js. Reads the live
                     .primary-key-source-input value at click time (not this
                     row's saved value) so checking a field you just picked but
                     haven't saved yet still reviews the right one. The alert
                     badge (populated once the wizard loads, see
                     initPkQualityBadges() in pk-quality-modal.js) warns of
                     outstanding issues before the button is ever clicked. */ ?>
                <button type="button"
                        class="button mmi-action-btn mmi-pk-quality-trigger mmi-pk-quality-check-btn"
                        data-supplier="<?php echo esc_attr($src_id); ?>">
                    <span class="dashicons dashicons-search"></span> Check Primary Key Data Quality
                    <span class="mmi-pk-quality-alert-badge mmi-badge error mmi-is-hidden"></span>
                </button>
                <?php include __DIR__ . '/panel-pk-quality.php'; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
