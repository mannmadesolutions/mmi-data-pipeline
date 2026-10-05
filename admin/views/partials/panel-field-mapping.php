<?php
/**
 * Field Mapping table content — reused inline as the unified Import Profile
 * wizard's Field Mapping step (see section-profile-wizard.php). Previously wrapped
 * in its own slide-out .mmi-config-panel; that chrome was retired along with
 * the standalone panel this session, but the table/row markup and every JS
 * handler that drives it (all delegated on $(document), never scoped to the
 * old panel container) are unchanged.
 */

if (!defined('ABSPATH')) {
    exit;
}

// $configured_suppliers is built by tab-import-pipeline.php before this partial is included.
// Fall back to an empty array so the panel renders gracefully if not provided.
if (!isset($configured_suppliers)) {
    $configured_suppliers = [];
}
// $default_mappings is set by default-field-mappings.php, required before this partial.
if (!isset($default_mappings)) {
    $default_mappings = [];
}
// $profile_assigned_sources is set by tab-pipeline.php. Empty means "all
// enabled sources" (Step 3's own default) — nothing gets hidden in that case.
if (!isset($profile_assigned_sources) || !is_array($profile_assigned_sources)) {
    $profile_assigned_sources = [];
}
$mmi_restrict_to_assigned_sources = !empty($profile_assigned_sources);

// This panel's main table (buttons + ~28 fields x 2 suppliers, each with up to
// 9 hidden per-transform param groups + a full conditions builder) was
// previously always rendered inline by section-profile-wizard.php, even though
// it lives inside a wizard modal that starts closed — on the real profile
// this was built against, that's over 1MB of HTML shipped on every single
// admin.php?page=mmi-data-pipeline page load regardless of whether the
// wizard is ever opened. $mmi_field_mapping_render_now lets a caller opt
// into the real render (section-profile-wizard.php's own include leaves this
// unset, so it renders only a lightweight placeholder + the always-present
// Field Browser Modal below); FieldMappingPanelController.php's AJAX
// handler sets it true and captures this file's output to serve the real
// table lazily, the first time the wizard's Field Mapping step is opened
// (see import-settings.js's wizardMaybeLoadFieldMappingPanel()). Several of
// this table's own controls (#add-custom-field, #reset-default-mappings)
// bind their click/change handlers directly rather than delegated on
// $(document) — those were converted to delegated bindings in the same
// change that added this flag, specifically so they still work once this
// content arrives after script-load time instead of being present in the
// initial page HTML.
$mmi_field_mapping_render_now = !empty($mmi_field_mapping_render_now);

/**
 * Inline "N alias values mapped" readout for one taxonomy, per configured
 * supplier — originally built for product_brand only (which has no 'source'
 * field of its own, so this WAS its whole cell), now generalized to any
 * taxonomy-type field so product_cat/product_tag (which gained a real Step 1
 * DEFAULTS source the same day) show the same live visibility into what
 * Taxonomy Mapping's alias table already has for them, alongside their own
 * normal source-field UI rather than replacing it. See the 2026-08-30
 * taxonomy/data-source integration Step 4 entry in AGENTS.md.
 *
 * @param string $wc_taxonomy         Real taxonomy slug (not 'tax:'-prefixed).
 * @param string $current_profile_val '' when not in a profile-scoped context (the wizard).
 * @param bool   $always_show_suppliers Show every configured supplier even
 *               with zero mapped values (labeled "not configured yet") —
 *               used only for the Taxonomy-Mapping-only fields
 *               ($mmi_taxonomy_mapping_only_fields below: product_brand,
 *               product_cat), which have no editable source-field row of
 *               their own for this to supplement; every other taxonomy
 *               field renders nothing for a supplier with nothing mapped,
 *               since its normal source-field row is already the primary
 *               "is this configured" signal.
 */
$mmi_render_taxonomy_alias_status = static function (string $wc_taxonomy, string $current_profile_val, bool $always_show_suppliers = false) use ($configured_suppliers, $profile_assigned_sources, $mmi_restrict_to_assigned_sources): void {
    $scope_suppliers = $mmi_restrict_to_assigned_sources
        ? array_intersect_key($configured_suppliers, array_flip($profile_assigned_sources))
        : $configured_suppliers;

    if (empty($scope_suppliers)) {
        return;
    }

    // get_tax_mappings('', ...) has no supplier_id filter at all (returns
    // EVERY supplier's rows, not just true-global ones) — read once, keep
    // only rows explicitly stored with supplier_id === '' (the real
    // "any supplier" rows resolve_tax_mapping() falls back to), reuse
    // across the loop below instead of re-querying per supplier.
    $global_rows = array_filter(
        MMI_DB::get_tax_mappings('', $wc_taxonomy),
        static fn($row) => ($row['supplier_id'] ?? '') === ''
    );

    $this_profile = $current_profile_val !== '' ? $current_profile_val : 'default';
    ?>
    <span class="mmi-brand-status-summary mmi-taxonomy-alias-status">
        <?php foreach ($scope_suppliers as $sid => $sinfo):
            $rows = array_filter(
                array_merge(MMI_DB::get_tax_mappings($sid, $wc_taxonomy), $global_rows),
                static fn($row) => in_array(($row['profile_id'] ?? ''), ['', $this_profile], true)
            );
            $source_fields = array_unique(array_filter(array_column($rows, 'source_field')));
            $count         = count($rows);
            if ($count === 0 && !$always_show_suppliers) {
                continue; // Nothing mapped for this supplier yet — no row worth showing.
            }
        ?>
            <br class="mmi-inline-break">
            <span class="mmi-brand-status-row">
                <strong class="mmi-brand-status-supplier"><?php echo esc_html(strtoupper($sinfo['supplier_name'] ?? $sid)); ?>:</strong>
                <?php if ($count > 0): ?>
                    source field<?php echo count($source_fields) === 1 ? '' : 's'; ?>
                    "<?php echo esc_html(implode('", "', $source_fields)); ?>",
                    <?php echo (int) $count; ?> alias value<?php echo $count === 1 ? '' : 's'; ?> mapped
                <?php else: ?>
                    <span class="mmi-brand-status-unconfigured">not configured yet</span>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
    </span>
    <?php
};

// Taxonomy fields that resolve entirely through Taxonomy Mapping's alias
// table (MMI_DB::resolve_tax_mapping()) rather than a per-profile editable
// source-field picker — the raw feed field feeding the alias lookup is a
// fixed per-supplier value (see MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS),
// not something a profile customizes. product_brand always worked this way;
// product_cat joined it 2026-09-15 (no profile in production had ever
// customized its source away from the default 'master_category+sub_category'
// anyway). product_tag is deliberately NOT included — it has no real source
// data in any feed today (see its own DEFAULTS comment), so there is nothing
// yet to route through Taxonomy Mapping.
$mmi_taxonomy_mapping_only_fields = ['product_brand', 'product_cat'];
?>

<?php /* Neither branch below carries the id that used to live here — it now
     lives on the stable wrapper div in section-profile-wizard.php, which is never
     replaced by either the page-load render or the AJAX swap that fills this
     in later (see that file's own comment). Adding it back to either branch
     here would just reintroduce two competing copies of the same id. */ ?>
<?php if (!$mmi_field_mapping_render_now): ?>
<div class="mmi-panel-content mmi-field-mapping-placeholder">
    <div class="mmi-preview-loading">
        <span class="mmi-spinner"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M286.7 96.1C291.7 113 282.1 130.9 265.2 135.9C185.9 159.5 128.1 233 128.1 320C128.1 426 214.1 512 320.1 512C426.1 512 512.1 426 512.1 320C512.1 233.1 454.3 159.6 375 135.9C358.1 130.9 348.4 113 353.5 96.1C358.6 79.2 376.4 69.5 393.3 74.6C498.9 106.1 576 204 576 320C576 461.4 461.4 576 320 576C178.6 576 64 461.4 64 320C64 204 141.1 106.1 246.9 74.6C263.8 69.6 281.7 79.2 286.7 96.1z"/></svg></span>
        Loading field mappings&hellip;
    </div>
</div>
<?php else: ?>
<div class="mmi-panel-content">
        <p class="mmi-section-description">Map supplier JSON fields to WooCommerce product fields. Use dot notation for nested fields (e.g., <code class="mmi-inline-code">product.price</code>). <strong class="mmi-autosave-notice">All changes are automatically saved.</strong></p>

        <?php
        // Suppliers with more than one real file to choose from (currently
        // Xchange and SkuPort — product/promotions/web-assets, etc.). Computed
        // once here and reused by both this toolbar's profile-wide picker and
        // each field-group-header row's own per-section picker below, rather
        // than each recomputing the same filter over $configured_suppliers.
        $mmi_multi_file_suppliers = array_filter(
            $configured_suppliers,
            static fn($sinfo) => count($sinfo['file_options'] ?? []) > 1
        );
        ?>

        <div class="mmi-action-buttons">
            <button type="button" class="mmi-btn-secondary" id="add-custom-field">
                ➕ Add Custom Field
            </button>
            <button type="button" class="mmi-btn-secondary" id="reset-default-mappings">
                🔄 Reset to Defaults
            </button>
            <button type="button" class="mmi-btn-secondary" id="auto-map-source-fields"
                    title="Scan each source's real data and automatically fill in any blank Source Field boxes below — matches by exact field name first, then common naming patterns. Never overwrites a value you've already set.">
                🪄 Auto-Map Source Fields
            </button>
            <span class="mmi-field-preset-divider" aria-hidden="true"></span>
            <select id="field-mapping-preset-select" class="mmi-field-preset-select">
                <option value="">— Apply a saved preset —</option>
                <!-- options populated by JS from mmiImportSettings.fieldMappingPresets -->
            </select>
            <button type="button" class="mmi-btn-secondary" id="apply-field-mapping-preset" disabled>
                Apply
            </button>
            <button type="button" class="mmi-btn-secondary mmi-btn-danger" id="delete-field-mapping-preset" disabled title="Delete selected preset">
                🗑️
            </button>
            <button type="button" class="mmi-btn-secondary" id="save-field-mapping-preset">
                💾 Save as Preset&hellip;
            </button>
            <?php if (!empty($mmi_multi_file_suppliers)): ?>
            <span class="mmi-field-preset-divider" aria-hidden="true"></span>
            <div class="mmi-default-file-toolbar"
                 title="Sets every field's file selector across this WHOLE profile to the chosen file — a convenience default only. It does not change which file the import actually reads; it only changes which file's fields get suggested when mapping a Source box.">
                <span class="mmi-default-file-toolbar-label">Default file:</span>
                <?php foreach ($mmi_multi_file_suppliers as $mmi_dfs_sid => $mmi_dfs_sinfo):
                    $mmi_dfs_not_assigned = $mmi_restrict_to_assigned_sources && !in_array($mmi_dfs_sid, $profile_assigned_sources, true);
                ?>
                    <select class="profile-file-default<?php echo $mmi_dfs_not_assigned ? ' mmi-supplier-not-in-profile' : ''; ?>" data-supplier="<?php echo esc_attr($mmi_dfs_sid); ?>">
                        <option value="">— <?php echo esc_html(strtoupper($mmi_dfs_sinfo['supplier_name'])); ?> —</option>
                        <?php foreach ($mmi_dfs_sinfo['file_options'] as $mmi_dfs_file_val => $mmi_dfs_file_label): ?>
                            <option value="<?php echo esc_attr($mmi_dfs_file_val); ?>"><?php echo esc_html($mmi_dfs_file_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <p class="description mmi-field-preset-hint">
            Presets are a snapshot of this profile's current mappings, saved under a name you choose
            and reusable on any other profile — applying one never changes another profile's mappings
            until you explicitly apply it there too.
        </p>

        <?php
        // Single source of truth for "is this field enabled," now that
        // enabling is no longer a manual toggle (2026-09-12): a field/
        // supplier is enabled exactly when it has a real mapping — see
        // ImportSettingsController.php's mmi_autosave_field_property
        // handler, which auto-derives and persists this same value on every
        // save. Computed here, before <thead>, so the table-wide summary
        // below can use it — the tbody loop further down reuses this exact
        // same closure and the $mmi_group_mapped_counts/$mmi_total_mapped/
        // $mmi_total_fields totals computed alongside it, rather than a
        // second, later copy of the same check.
        $mmi_is_field_enabled = static function ( $m ) use ( $mmi_restrict_to_assigned_sources, $profile_assigned_sources ) {
            if ( ! isset( $m['enabled'] ) ) {
                return false;
            }
            if ( is_bool( $m['enabled'] ) ) {
                return $m['enabled'];
            }
            if ( is_array( $m['enabled'] ) ) {
                // A supplier this profile hasn't assigned is hidden from every
                // per-supplier control on this table (see mmi-supplier-not-in-profile
                // in import-settings.css) — but its 'enabled'/'source' can still be
                // genuinely non-blank, e.g. a Taxonomy-Mapping-only field's fixed
                // DEFAULTS source (panel-field-mapping.php's
                // $mmi_taxonomy_mapping_only_fields), which falls back to a real
                // value for every supplier DEFAULTS knows about regardless of
                // whether this profile currently assigns that supplier. Counting
                // that phantom, invisible entry made a field with every VISIBLE
                // toggle off still show as mapped and count toward the group
                // chip — found live 2026-09-15 on product_brand while editing a
                // profile whose Data Sources step had SKUPORT unchecked.
                $relevant = $mmi_restrict_to_assigned_sources
                    ? array_intersect_key( $m['enabled'], array_flip( $profile_assigned_sources ) )
                    : $m['enabled'];
                return ! empty( array_filter( $relevant ) );
            }
            return ! empty( $m['enabled'] );
        };

        $mmi_group_mapped_counts = [];
        $mmi_total_mapped        = 0;
        $mmi_total_fields        = 0;
        foreach ( $default_mappings as $mmi_dm_fn => $mmi_dm_m ) {
            $mmi_dm_g = $mmi_dm_m['group'] ?? 'meta';
            if ( ! isset( $mmi_group_mapped_counts[ $mmi_dm_g ] ) ) {
                $mmi_group_mapped_counts[ $mmi_dm_g ] = [ 'mapped' => 0, 'total' => 0 ];
            }
            $mmi_group_mapped_counts[ $mmi_dm_g ]['total']++;
            $mmi_total_fields++;
            if ( $mmi_is_field_enabled( $mmi_dm_m ) ) {
                $mmi_group_mapped_counts[ $mmi_dm_g ]['mapped']++;
                $mmi_total_mapped++;
            }
        }
        ?>
        <div class="mmi-fm-table-wrap">
        <table class="mmi-field-mapping-table">
            <thead class="mmi-table-head">
                <?php /* Single header row — the Enabled/Show in Preview columns and
                     their master toggles were removed at the user's direction: a
                     field is enabled purely by having a real mapping, see
                     $mmi_is_field_enabled above, so there's no longer a bulk on/off
                     control to anchor a second header row on. Both columns (and
                     every placeholder cell that used to keep colspans lined up
                     against them) are removed outright rather than kept as hidden,
                     zero-width cells — see the matching removal in the group-header
                     row and each data row below; only 4 real columns remain now,
                     and every colspan in this file was renumbered to match. */ ?>
                <tr class="mmi-fm-head-labels">
                    <?php /* No persistent label here — each section's own field-group-header
                         row (e.g. "📦 Core Product Fields") already repeats directly above
                         this column's cells at every group boundary and now serves as this
                         column's only label; keeping a second, permanently-fixed "WooCommerce
                         Field" caption here duplicated that repeating header for no benefit. */ ?>
                    <th class="col-woo-field">
                        <?php /* Table-wide "N mapped / M skipped" summary — the
                             complete-picture replacement for the removed master
                             toggles. $mmi_total_mapped/$mmi_total_fields are computed
                             once, above, from the same $mmi_is_field_enabled check
                             every row and group chip already use. */ ?>
                        <span class="mmi-fm-total-summary">
                            <span class="mmi-fm-total-mapped"><?php echo (int) $mmi_total_mapped; ?> mapped</span>
                            <span class="mmi-fm-total-skipped"><?php echo (int) ( $mmi_total_fields - $mmi_total_mapped ); ?> skipped</span>
                        </span>
                    </th>
                    <th class="col-source-file">
                        Source File<span class="mmi-per-supplier-label"> (Per Supplier)</span>
                    </th>
                    <th class="col-source-field">
                        Source Field<span class="mmi-per-supplier-label"> (Per Supplier)</span>
                        <?php if (empty($configured_suppliers)): ?>
                            <br class="mmi-inline-break"><span class="mmi-no-sources-notice">No data sources configured yet</span>
                        <?php endif; ?>
                    </th>
                    <th class="col-transform">Transform</th>
                </tr>
            </thead>
            <tbody class="mmi-table-body">
                <?php
                $current_group = '';
                $group_labels = [
                    'core' => '📦 Core Product Fields',
                    'pricing' => '💰 Pricing',
                    'product_type' => '🎯 Product Type (Virtual/Downloadable)',
                    'inventory' => '📊 Inventory & Availability',
                    'shipping' => '📮 Shipping (⚠️ N/A for Virtual Products)',
                    'media' => '🖼️ Media/Images',
                    'taxonomy' => '🏷️ Taxonomies',
                    'meta' => '🔧 Custom Meta'
                ];

                // The wizard's Field Mapping step shows these by default (same
                // set the retired Quick Import wizard curated) with everything
                // else behind a "Show Advanced Fields" toggle — see the
                // .mmi-field-advanced CSS rule and its toggle in the wizard step.
                // A group whose fields are ALL advanced gets the same class on
                // its own header row, so an empty-looking header never shows
                // with the curated view.
                $curated_fields = ['post_title', '_sku', '_regular_price', '_price', 'product_cat', '_stock', 'post_content'];
                $group_has_curated_field = [];
                foreach ($default_mappings as $fn => $m) {
                    if (in_array($fn, $curated_fields, true)) {
                        $group_has_curated_field[$m['group']] = true;
                    }
                }

                // $mmi_is_field_enabled and the mapped-count totals are computed
                // once, above (before <thead>), so the table-wide summary and
                // every group's "N of M mapped" chip below all agree with each
                // other and with each row's own mapped/not-mapped state.

                // Bucket fields by group before rendering. The defaults array is
                // authored in a hand-maintained order where a group's fields
                // aren't necessarily contiguous (__mmi_cog is group 'pricing'
                // but declared after the taxonomy fields), and the header below
                // is emitted on every group *change* — so a non-contiguous group
                // rendered a second, duplicate header for itself. Bucketing also
                // means an advanced-only field always lands under its real
                // heading rather than wherever it happened to be declared.
                // $group_labels order drives section order; any unrecognised
                // group still renders, appended after the known ones.
                $grouped_fields = [];
                foreach ( array_keys( $group_labels ) as $known_group ) {
                    $grouped_fields[ $known_group ] = [];
                }
                foreach ($default_mappings as $fn => $m) {
                    $g = $m['group'] ?? 'meta';
                    if (!isset($grouped_fields[$g])) {
                        $grouped_fields[$g] = [];
                        $group_labels[$g]   = $group_labels[$g] ?? ucfirst(str_replace('_', ' ', $g));
                    }
                    $grouped_fields[$g][$fn] = $m;
                }
                $ordered_mappings = [];
                foreach ($grouped_fields as $g => $fields) {
                    foreach ($fields as $fn => $m) {
                        $ordered_mappings[$fn] = $m;
                    }
                }

                foreach ($ordered_mappings as $field_name => $mapping):
                    if ($current_group !== $mapping['group']) {
                        $current_group = $mapping['group'];
                        $group_advanced_class = empty($group_has_curated_field[$current_group]) ? ' mmi-field-advanced' : '';

                        // Consolidated group-header bar, matching the "Option B — Refined
                        // Data Grid" mockup: a single icon+label cell on the left and a
                        // single ratio-chip cell on the right, with nothing in between.
                        // This row has exactly one <th>, colspan="4" — one per real column
                        // (col-woo-field, col-source-file, col-source-field, col-transform)
                        // — since the Enabled/Show in Preview columns and their master
                        // toggles were removed at the user's direction (a field is enabled
                        // purely by having a real mapping, see $mmi_is_field_enabled
                        // above), and no longer have placeholder cells to fill here either.
                        $mmi_gc = $mmi_group_mapped_counts[$current_group] ?? ['mapped' => 0, 'total' => 0];
                        $mmi_gc_class = 'is-empty';
                        $mmi_gc_variant = '';
                        if ($mmi_gc['total'] > 0 && $mmi_gc['mapped'] === $mmi_gc['total']) {
                            $mmi_gc_class = 'is-full';
                            $mmi_gc_variant = 'success';
                        } elseif ($mmi_gc['mapped'] > 0) {
                            $mmi_gc_class = 'is-partial';
                            $mmi_gc_variant = 'warning';
                        }
                        // mmi-badge = shared pill shape/base gray (mmi-suite-common.css);
                        // is-full/is-partial already used its success/warning tokens directly.
                        $mmi_group_chip = '<span class="mmi-group-mapped-chip mmi-badge ' . esc_attr($mmi_gc_variant) . ' ' . esc_attr($mmi_gc_class) . '">'
                            . (int) $mmi_gc['mapped'] . ' of ' . (int) $mmi_gc['total'] . ' mapped</span>';

                        // One single colspan cell — label+icon on the left, chip on the
                        // right, laid out with justify-content:space-between (see
                        // import-settings.css) — rather than two separately-positioned
                        // cells, so the chip always anchors to the row's right edge
                        // instead of tracking wherever a second cell's own colspan
                        // happened to start.
                        echo '<tr class="field-group-header' . $group_advanced_class . '" data-group="' . esc_attr($current_group) . '">'
                            . '<th colspan="4" class="mmi-group-header-label">'
                            . '<div class="mmi-group-header-label-inner">'
                            . '<span class="mmi-group-header-label-text">' . $group_labels[$current_group] . '</span>'
                            . $mmi_group_chip
                            . '</div>'
                            . '</th>'
                            . '</tr>';
                    }
                    $row_advanced_class = in_array($field_name, $curated_fields, true) ? '' : ' mmi-field-advanced';
                ?>
                    <tr data-field="<?php echo esc_attr($field_name); ?>" data-field-type="<?php echo esc_attr($mapping['type'] ?? ''); ?>" class="field-mapping-row<?php echo $row_advanced_class; ?>">
                        <?php
                        // Calculate enabled state via the same $mmi_is_field_enabled
                        // closure the group chips/table summary above already use
                        // (previously a second, independent copy of this logic that
                        // had drifted to not share its assigned-sources filtering —
                        // see that closure's own comment for the bug this caused).
                        // An absent 'enabled' key defaults to DISABLED, matching
                        // class-product-import-worker.php's own gate exactly (empty()
                        // on an unset 'enabled' key there is true → the real importer
                        // skips the field). A field is enabled purely by having a
                        // real mapping (see ImportSettingsController.php's
                        // mmi_autosave_field_property handler, which auto-derives and
                        // persists this same value on every save) — there is no
                        // manual toggle to set it.
                        $is_enabled = $mmi_is_field_enabled( $mapping );
                        ?>
                        <td class="col-woo-field">
                            <strong class="mmi-woo-field-name"><?php echo esc_html($mapping['label']); ?></strong>
                            <?php if (!empty($mapping['required'])): ?>
                                <span class="field-required">*</span>
                            <?php endif; ?>
                            <span class="mmi-badge"><?php echo esc_html($mapping['type']); ?></span>
                            <?php /* A field is enabled purely by having a real mapping — so
                                 instead of a switch to flip, an unmapped field just says
                                 so; mapping a source below is what turns this off. */ ?>
                            <?php if ( ! $is_enabled ): ?>
                                <span class="mmi-field-not-mapped-pill mmi-badge" title="No source or constant value is configured for any supplier — this field is skipped on import.">Not mapped</span>
                            <?php endif; ?>
                            <?php if ($mapping['type'] === 'taxonomy'): ?>
                                <?php /* Points to the Taxonomy Mapping section, now embedded in the
                                     Supplier Data Sources toolbar on this SAME page (see
                                     section-taxonomy-mapping.php) rather than a separate tab/page the
                                     way it was when this link was first written — a same-tab click
                                     is now a full reload of the page this panel is already sitting
                                     on (or, worse, of the page underneath this panel's own wizard
                                     modal, silently discarding any in-progress unsaved wizard steps
                                     with no warning). target="_blank" (2026-08-31) keeps this a
                                     genuinely useful reference/edit link without that risk — see the
                                     matching fix on the product_brand-specific link below and
                                     AGENTS.md's changelog for the same date. ?open_taxonomy=1 expands
                                     and scrolls to that section (see taxonomy-mapping.js's
                                     maybeOpenFromUrl()); ?profile= pre-selects this profile in
                                     the section's own "+ Add variation" pickers, not a page-wide
                                     scope — Taxonomy Mapping has no such thing (see AGENTS.md's
                                     2026-08-30 Incident History for why). */ ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=mmi-data-pipeline&pipeline_tab=import&open_taxonomy=1' . (isset($current_profile) ? '&profile=' . urlencode($current_profile) : ''))); ?>"
                                   class="mmi-source-help-link mmi-taxonomy-mapping-link"
                                   target="_blank" rel="noopener noreferrer"
                                   title="Map this taxonomy's raw supplier values to specific WooCommerce terms — including alias rules for spelling variants — in Taxonomy Mapping. Opens in a new tab.">
                                    <span class="dashicons dashicons-admin-links"></span> Alias mapping
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($mapping['note'])): ?>
                                <?php /* Note text moved to a tooltip (was its own stacked line) so the
                                     row stays one line tall for the common case. */ ?>
                                <span class="dashicons dashicons-warning field-note-icon" title="<?php echo esc_attr($mapping['note']); ?>"></span>
                            <?php endif; ?>
                            <?php if (!empty($mapping['meta_key_resolver']) && function_exists($mapping['meta_key_resolver'])): ?>
                                <?php /* Destination meta key is configurable, not fixed to $field_name —
                                     edit it directly here instead of a separate global setting. */ ?>
                                <label class="mmi-pipeline-field-meta-key-label">
                                    Meta key:
                                    <input type="text"
                                           id="mmi-cog-meta-key-input"
                                           class="mmi-cog-meta-key-input mmi-pipeline-field-meta-key-input mmi-meta-key-input"
                                           value="<?php echo esc_attr( call_user_func( $mapping['meta_key_resolver'] ) ); ?>"
                                           placeholder="cog"
                                           autocomplete="off"
                                           title="Click to browse known meta keys (WooCommerce, JetEngine, MannMade), or type any custom key">
                                    <span class="mmi-loading mmi-cog-meta-key-loading mmi-is-hidden" aria-hidden="true"></span>
                                    <span class="mmi-cog-meta-key-save-status"></span>
                                </label>
                            <?php else: ?>
                                <code class="mmi-pipeline-field-code"><?php echo esc_html($field_name); ?></code>
                            <?php endif; ?>
                            <?php if (!empty($mapping['default'])): ?>
                                <span class="field-default" title="Default value applied when this field isn't mapped">Default: <?php echo esc_html($mapping['default'] === true ? 'true' : $mapping['default']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="col-source-file">
                        <?php
                        // Parallel to the per-supplier loop in col-source-field below (same
                        // $configured_suppliers iteration) — extracted into its own column
                        // and its own pass rather than one shared loop emitting into two open
                        // <td>s at once, since each <td> needs to be a complete, independently
                        // valid cell. $configured_suppliers is a small, already-in-memory array
                        // (not a query), so iterating it twice per field costs nothing real.
                        // Renders nothing for a Taxonomy-Mapping-only field (no editable
                        // source/file concept at all — see $mmi_taxonomy_mapping_only_fields
                        // above) or for a legacy single-source/custom field (no per-supplier
                        // file to pick).
                        if (!in_array($field_name, $mmi_taxonomy_mapping_only_fields, true) && is_array($mapping['source'] ?? null) && !empty($configured_suppliers)):
                        ?>
                            <div class="supplier-files mmi-label-grid" data-field="<?php echo esc_attr($field_name); ?>">
                                <?php foreach ($configured_suppliers as $sid => $sinfo):
                                    $mmi_not_assigned = $mmi_restrict_to_assigned_sources && !in_array($sid, $profile_assigned_sources, true);
                                    $mmi_fm_file_opts      = $sinfo['file_options'] ?? [];
                                    $mmi_fm_has_file_choice = count($mmi_fm_file_opts) > 1;
                                    $mmi_fm_selected_file   = $mapping['file'][$sid] ?? '';
                                    if ($mmi_fm_selected_file !== '' && !array_key_exists($mmi_fm_selected_file, $mmi_fm_file_opts)) {
                                        // Stale/foreign selection — see the identical guard in the
                                        // col-source-field loop below for why this can happen.
                                        $mmi_fm_selected_file = '';
                                    }
                                ?>
                                    <div class="supplier-file-row mmi-label-grid-row<?php echo !$sinfo['enabled'] ? ' supplier-source-inactive' : ''; ?><?php echo $mmi_not_assigned ? ' mmi-supplier-not-in-profile' : ''; ?>" data-supplier="<?php echo esc_attr($sid); ?>">
                                        <span class="supplier-label mmi-file-supplier-label"><?php echo esc_html(strtoupper($sinfo['supplier_name'])); ?>:</span>
                                        <div class="mmi-label-grid-value">
                                        <?php if ($mmi_fm_has_file_choice): ?>
                                            <?php /* Browsing aid only — does NOT route the import to a
                                                 specific file. Identical semantics/tooltip to before this
                                                 column split; see the matching comment on col-source-field's
                                                 own loop below and initFieldFileSelectors() in
                                                 import-settings.js. */ ?>
                                            <select class="field-file-selector"
                                                    name="field_mappings[<?php echo esc_attr($field_name); ?>][file][<?php echo esc_attr($sid); ?>]"
                                                    data-supplier="<?php echo esc_attr($sid); ?>"
                                                    data-field="<?php echo esc_attr($field_name); ?>"
                                                    title="Which file's field names to suggest when browsing the Source box — the import itself always reads this supplier's main feed either way.">
                                                <?php $mmi_fm_selected_file = $mmi_fm_selected_file !== '' ? $mmi_fm_selected_file : array_key_first($mmi_fm_file_opts); ?>
                                                <?php foreach ($mmi_fm_file_opts as $file_val => $file_label): ?>
                                                    <option value="<?php echo esc_attr($file_val); ?>" <?php selected($mmi_fm_selected_file, $file_val); ?>>
                                                        <?php echo esc_html($file_label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif (!empty($mmi_fm_file_opts)):
                                            $mmi_fm_only_file = $mmi_fm_selected_file !== '' ? $mmi_fm_selected_file : array_key_first($mmi_fm_file_opts);
                                        ?>
                                            <input type="hidden"
                                                   class="field-file-selector"
                                                   name="field_mappings[<?php echo esc_attr($field_name); ?>][file][<?php echo esc_attr($sid); ?>]"
                                                   data-supplier="<?php echo esc_attr($sid); ?>"
                                                   data-field="<?php echo esc_attr($field_name); ?>"
                                                   value="<?php echo esc_attr($mmi_fm_only_file); ?>">
                                            <span class="mmi-file-single-notice"><?php echo esc_html($mmi_fm_file_opts[$mmi_fm_only_file] ?? $mmi_fm_only_file); ?></span>
                                        <?php else: ?>
                                            <span class="mmi-file-single-notice">—</span>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        </td>
                        <td class="col-source-field">
                        <?php
                        // Real taxonomy slug behind this field, hoisted here (once per
                        // field, not per supplier row, and before the fixed-source-vs-
                        // editable-source branch below since the hierarchical parent/child
                        // settings further down apply to either one) so every consumer
                        // shares one derivation instead of independent copies. Dynamic
                        // per-taxonomy fields are named 'tax:{slug}' (see
                        // MMI_Taxonomy_Field_Helper); built-ins use the taxonomy slug
                        // directly as the field key.
                        $mmi_tax_slug     = ($mapping['type'] === 'taxonomy') ? ((strpos($field_name, 'tax:') === 0) ? substr($field_name, 4) : $field_name) : '';
                        $mmi_taxonomy_obj = $mmi_tax_slug !== '' && taxonomy_exists($mmi_tax_slug) ? get_taxonomy($mmi_tax_slug) : null;
                        ?>
                        <?php if (in_array($field_name, $mmi_taxonomy_mapping_only_fields, true)): ?>
                            <?php /* Both product_brand and product_cat DO have a real per-supplier
                                 'source' in DEFAULTS (product_cat since 2026-08-30, see AGENTS.md's
                                 "Product Categories Were Silently Broken" entry) — but for these two
                                 fields it's a FIXED value the plugin already knows (which raw feed
                                 field feeds Taxonomy Mapping's alias-table lookup — MMI_DB::
                                 resolve_tax_mapping()), not something a profile picks, so this is a
                                 read-only summary plus an enable/disable toggle rather than the
                                 normal .mmi-source-field-row editable controls below. No profile has
                                 ever customized either field's source away from that fixed default
                                 (confirmed live, 2026-09-15), so nothing real is exposed by not
                                 offering a picker.
                                 Checking a supplier's toggle below writes that fixed value into
                                 'source' for it; unchecking blanks it — the exact same
                                 "enabled purely by having a real mapping" model every other field
                                 uses (mmi_pipeline_derive_field_enabled_state() in
                                 ImportSettingsController.php), just with a fixed value instead of a
                                 picker, since there's nothing else here to pick. This is also what
                                 makes Taxonomy_Mapping_Handler::apply_taxonomy_mappings() actually
                                 turn on/off for this supplier — it gates purely on 'source' being
                                 non-empty, so the toggle is a real functional switch, not a cosmetic
                                 one, and a brand-new profile (blank_mappings() blanks 'source' the
                                 same as every other field) starts with both fields genuinely off. */ ?>
                            <p class="mmi-no-sources-inline">
                                <span class="dashicons dashicons-info"></span>
                                Supplier values for <?php echo esc_html(strtolower($mapping['label'])); ?> —
                                including matching spelling variants (e.g. "fender inc" to "Fender") —
                                are resolved directly by
                                <?php /* target="_blank" (2026-08-31) — this now points at a section
                                     living on this SAME page (see the matching comment on the
                                     general "Alias mapping" link above), so a same-tab click here
                                     was a plain reload with no real destination change, and inside
                                     this panel's own wizard modal, one that silently discarded
                                     whatever wizard progress hadn't been saved yet. */ ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=mmi-data-pipeline&pipeline_tab=import&open_taxonomy=1' . (isset($current_profile) ? '&profile=' . urlencode($current_profile) : ''))); ?>" class="mmi-brand-taxonomy-link" target="_blank" rel="noopener noreferrer">Taxonomy Mapping</a>
                                (opens in a new tab), not a source field here.
                                <?php $mmi_render_taxonomy_alias_status($field_name, isset($current_profile) ? (string) $current_profile : '', true); ?>
                            </p>
                            <?php
                            $mmi_fixed_sources = MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS[$field_name]['source'] ?? [];
                            $mmi_tm_suppliers_with_source = array_filter(
                                $configured_suppliers,
                                static fn($sid) => trim((string) ($mmi_fixed_sources[$sid] ?? '')) !== '',
                                ARRAY_FILTER_USE_KEY
                            );
                            ?>
                            <?php if (!empty($mmi_tm_suppliers_with_source)): ?>
                                <div class="mmi-taxonomy-enable-toggles">
                                    <?php foreach ($mmi_tm_suppliers_with_source as $sid => $sinfo):
                                        $mmi_tm_checked     = trim((string) ($mapping['source'][$sid] ?? '')) !== '';
                                        // Same mmi-supplier-not-in-profile treatment as every other
                                        // per-supplier row on this table (import-settings.css) —
                                        // hidden outright for a supplier this profile hasn't
                                        // assigned, not just visually deprioritized.
                                        $mmi_tm_not_assigned = $mmi_restrict_to_assigned_sources && !in_array($sid, $profile_assigned_sources, true);
                                    ?>
                                        <label class="mmi-taxonomy-enable-toggle<?php echo $mmi_tm_not_assigned ? ' mmi-supplier-not-in-profile' : ''; ?>" title="Turn on Taxonomy Mapping resolution for <?php echo esc_attr(strtoupper($sinfo['supplier_name'])); ?>, using its '<?php echo esc_attr($mmi_fixed_sources[$sid]); ?>' field">
                                            <input type="checkbox"
                                                   class="field-taxonomy-mapping-toggle"
                                                   data-field="<?php echo esc_attr($field_name); ?>"
                                                   data-supplier="<?php echo esc_attr($sid); ?>"
                                                   data-source="<?php echo esc_attr($mmi_fixed_sources[$sid]); ?>"
                                                   <?php checked($mmi_tm_checked); ?>>
                                            Enable for <?php echo esc_html(strtoupper($sinfo['supplier_name'])); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                        <?php // $mmi_tax_slug/$mmi_taxonomy_obj already hoisted above. ?>
                        <?php if ($mmi_tax_slug !== ''): ?>
                            <?php /* Same alias-mapping-status readout product_brand has always had
                                 (see $mmi_render_taxonomy_alias_status above), now shown for any
                                 taxonomy field with a real source, alongside its normal
                                 source-field row rather than in place of it — unlike brand, these
                                 fields DO have a literal field to map here, so this is
                                 supplementary visibility into Taxonomy Mapping's alias table, not
                                 a replacement for the row above. Renders nothing when no alias
                                 rows exist yet for this taxonomy — no "not configured yet" noise
                                 on a field most profiles will never need an alias for. */ ?>
                            <?php $mmi_render_taxonomy_alias_status($mmi_tax_slug, isset($current_profile) ? (string) $current_profile : ''); ?>
                        <?php endif; ?>
                        <?php
                        // Renders the type-appropriate constant-value control for one
                        // supplier row, swapped in place of that row's source-path input
                        // (same slot, same width) rather than injected as a new flex
                        // sibling — the latter was what forced .mmi-source-field-row to
                        // wrap and jump in height on toggle (see import-settings.css
                        // history). $current_field_name is passed explicitly rather than
                        // captured via `use()` so the closure stays correct across every
                        // iteration of the field loop it's (re)defined in.
                        // $disabled hardens this control so it can only receive input while
                        // its paired 'Const' toggle is actually on — the 'hidden' class alone
                        // (display:none) already blocks interactive access, but a disabled
                        // attribute additionally strips it from focus/tab order and guarantees
                        // no stale value from a prior toggle-on state is ever mistaken for a
                        // live one by anything that later inspects this input's state.
                        // $taxonomy_slug is '' for every non-taxonomy field type.
                        $render_constant_input = static function (string $name, string $type, $value, string $current_field_name, bool $disabled = false, string $taxonomy_slug = ''): void {
                            if ($type === 'taxonomy'): ?>
                                <?php /* Locked to this taxonomy's real terms only — no
                                     free-text entry. Starts with just the saved
                                     value (if any) as a placeholder option; real
                                     terms are loaded on demand by
                                     loadConstantTaxonomyTerms() in import-settings.js
                                     once this row's Const toggle is first checked,
                                     which reconciles/replaces this seed option
                                     against the real list. */ ?>
                                <select name="<?php echo esc_attr($name); ?>"
                                        class="constant-value-select mmi-constant-taxonomy-select"
                                        data-taxonomy="<?php echo esc_attr($taxonomy_slug); ?>"
                                        <?php disabled($disabled); ?>>
                                    <option value="">-- Select a term --</option>
                                    <?php if ($value !== ''): ?>
                                        <option value="<?php echo esc_attr($value); ?>" selected><?php echo esc_html($value); ?></option>
                                    <?php endif; ?>
                                </select>
                            <?php elseif ($type === 'boolean'): ?>
                                <select name="<?php echo esc_attr($name); ?>" class="constant-value-select" <?php disabled($disabled); ?>>
                                    <option value="">-- Select Value --</option>
                                    <option value="1" <?php selected($value, '1'); ?>>Yes / True</option>
                                    <option value="0" <?php selected($value, '0'); ?>>No / False</option>
                                </select>
                            <?php elseif ($current_field_name === '_stock_status'): ?>
                                <select name="<?php echo esc_attr($name); ?>" class="constant-value-select" <?php disabled($disabled); ?>>
                                    <option value="">-- Select Availability --</option>
                                    <option value="instock" <?php selected($value, 'instock'); ?>>✅ In Stock</option>
                                    <option value="outofstock" <?php selected($value, 'outofstock'); ?>>❌ Out of Stock</option>
                                    <option value="onbackorder" <?php selected($value, 'onbackorder'); ?>>⏳ On Backorder</option>
                                </select>
                            <?php elseif ($type === 'integer'): ?>
                                <input type="number"
                                       name="<?php echo esc_attr($name); ?>"
                                       value="<?php echo esc_attr($value); ?>"
                                       class="constant-value-input-text"
                                       placeholder="Number"
                                       <?php disabled($disabled); ?>>
                            <?php elseif ($type === 'decimal'): ?>
                                <input type="number"
                                       step="0.01"
                                       name="<?php echo esc_attr($name); ?>"
                                       value="<?php echo esc_attr($value); ?>"
                                       class="constant-value-input-text"
                                       placeholder="Decimal"
                                       <?php disabled($disabled); ?>>
                            <?php else: ?>
                                <input type="text"
                                       name="<?php echo esc_attr($name); ?>"
                                       value="<?php echo esc_attr($value); ?>"
                                       class="constant-value-input-text"
                                       placeholder="Constant value"
                                       <?php disabled($disabled); ?>>
                            <?php endif;
                        };
                        ?>
                        <div class="mmi-source-field-row">
                            <?php
                            // Checking is_array() FIRST (not !empty()) matters: a freshly-added
                            // custom field gets 'source' => [] from merge()'s custom-field-append
                            // fallback (class-pipeline-field-mapping-defaults.php) — a real,
                            // multi-supplier-shaped array that simply has nothing saved in it
                            // yet. !empty([]) is false, so checking that first sent every such
                            // field straight to the "no source concept" custom-path fallback
                            // below (meant only for Taxonomy-Mapping-only fields, which never
                            // even reach this branch — see $mmi_taxonomy_mapping_only_fields above) —
                            // rendering a single free-text input instead of the real per-supplier
                            // dropdown UI every other field with configured sources gets, and
                            // silently misfiling it as if it had no source concept at all.
                            ?>
                            <?php if (is_array($mapping['source'] ?? null)): ?>
                                    <?php /* Multi-supplier field sources (dynamic from wp_mmi_data_sources).
                                         Constant values are configured per supplier row below (swapped
                                         in place of that row's source-path input) rather than as a
                                         single field-wide override — a saved scalar use_constant_value
                                         (pre-dating this) still applies to every row identically, see
                                         MMI_Pipeline_Field_Mapping_Defaults::resolve_constant(). */ ?>
                                    <div class="supplier-sources mmi-label-grid" data-field="<?php echo esc_attr($field_name); ?>">
                                        <?php if (empty($configured_suppliers)): ?>
                                            <p class="mmi-no-sources-inline">
                                                No data sources configured.
                                                <?php /* target="_blank" (2026-08-31) — same fix as the two
                                                     Taxonomy Mapping links above: this panel is reached from
                                                     the Data Pipeline page itself (directly, or via this
                                                     panel's own wizard modal sitting on top of it), so a
                                                     same-tab click here is effectively a reload of the exact
                                                     page underneath, discarding any unsaved wizard progress
                                                     for no real navigational benefit. */ ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=mmi-data-pipeline')); ?>" class="mmi-configure-sources-link" target="_blank" rel="noopener noreferrer">Configure sources</a> in the Data Pipeline (opens in a new tab).
                                            </p>
                                        <?php elseif (count($configured_suppliers) > 1): ?>
                                            <button type="button" class="mmi-copy-constant-to-all" data-field="<?php echo esc_attr($field_name); ?>" title="Copy the first source's Constant checkbox and value to every other source for this field">⧉</button>
                                        <?php endif; ?>
                                        <?php if (!empty($configured_suppliers)): ?>
                                            <?php foreach ($configured_suppliers as $sid => $sinfo):
                                                $mmi_not_assigned = $mmi_restrict_to_assigned_sources && !in_array($sid, $profile_assigned_sources, true);
                                                $mmi_use_const_raw = $mapping['use_constant_value'] ?? false;
                                                $mmi_sup_use_const = is_array($mmi_use_const_raw) ? !empty($mmi_use_const_raw[$sid]) : !empty($mmi_use_const_raw);
                                                $mmi_const_val_raw = $mapping['constant_value'] ?? '';
                                                $mmi_sup_const_val = is_array($mmi_const_val_raw) ? ($mmi_const_val_raw[$sid] ?? '') : $mmi_const_val_raw;
                                                $mmi_image_array_raw = $mapping['image_array_mode'] ?? false;
                                                $mmi_sup_image_array = is_array($mmi_image_array_raw) ? !empty($mmi_image_array_raw[$sid]) : !empty($mmi_image_array_raw);
                                            ?>
                                                <div class="supplier-source-row mmi-label-grid-row<?php echo !$sinfo['enabled'] ? ' supplier-source-inactive' : ''; ?><?php echo $mmi_not_assigned ? ' mmi-supplier-not-in-profile' : ''; ?>" data-supplier="<?php echo esc_attr($sid); ?>">
                                                    <?php
                                                    // Per-supplier enable checkbox removed (2026-09-12) — a
                                                    // supplier's contribution to this field is now enabled
                                                    // purely by having a real source or constant configured
                                                    // for it, mirroring the row-level $is_enabled logic above.
                                                    // The old $sup_enabled ('enabled'[$sid], defaulting true
                                                    // for a never-saved supplier) is gone along with it; this
                                                    // dot is a status readout only, computed from the same
                                                    // 'source'/'use_constant_value' this row already reads.
                                                    $mmi_sup_source_val  = is_array( $mapping['source'] ?? null ) ? trim( (string) ( $mapping['source'][ $sid ] ?? '' ) ) : '';
                                                    $mmi_sup_has_mapping = ( '' !== $mmi_sup_source_val ) || $mmi_sup_use_const;
                                                    ?>
                                                    <div class="supplier-enable-wrapper">
                                                        <span class="mmi-supplier-mapped-dot<?php echo $mmi_sup_has_mapping ? ' is-mapped' : ' is-unmapped'; ?>" title="<?php echo $mmi_sup_has_mapping ? 'Mapped' : 'Not mapped'; ?>"></span>
                                                        <span class="supplier-label">
                                                            <?php echo esc_html(strtoupper($sinfo['supplier_name'])); ?>:
                                                            <?php if (!$sinfo['enabled']): ?>
                                                                <span class="supplier-status-dot supplier-status-<?php echo esc_attr($sinfo['config_status']); ?>"
                                                                      title="Source is <?php echo esc_attr($sinfo['config_status']); ?> — not currently active">●</span>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                    <div class="field-selector-group">
                                                        <?php
                                                        // The per-supplier file-selector (which file's field names to
                                                        // suggest) now renders in its own column — col-source-file,
                                                        // above — rather than stacked here above the source-value
                                                        // select. This cell now holds only the value itself (or its
                                                        // Const-swapped constant input) plus the Const toggle below.
                                                        $mmi_current_source = $mapping['source'][$sid] ?? '';
                                                        ?>
                                                        <div class="mmi-source-value-swap">
                                                            <?php /* Real <select> of this supplier's actual feed fields
                                                                 ONLY (populated by populateSourceFieldSelect() in
                                                                 import-settings.js once loadFieldsFromFile()
                                                                 resolves) — a value can only come from picking a real
                                                                 option, never free-typed, so a typo can never become
                                                                 the saved mapping. Deliberately no "Custom / Other…"
                                                                 escape hatch here (unlike the Primary Key source
                                                                 field, .primary-key-source-input above, which does
                                                                 have one) — a mapped-but-not-in-the-current-feed value
                                                                 shows here as unselected rather than as a pickable
                                                                 option, and is still caught by
                                                                 MMI_Pipeline_Config_Validator's pre-flight check. */ ?>
                                                            <select class="field-source-<?php echo esc_attr($sid); ?> mmi-source-path-input<?php echo $mmi_sup_use_const ? ' hidden' : ''; ?>"
                                                                    data-field="<?php echo esc_attr($field_name); ?>"
                                                                    data-supplier="<?php echo esc_attr($sid); ?>"
                                                                    <?php disabled($mmi_sup_use_const); ?>>
                                                                <?php if ($mmi_current_source !== ''): ?>
                                                                    <option value="<?php echo esc_attr($mmi_current_source); ?>"><?php echo esc_html($mmi_current_source); ?></option>
                                                                <?php else: ?>
                                                                    <option value="">— Select a field —</option>
                                                                <?php endif; ?>
                                                            </select>
                                                            <div class="mmi-source-constant-input<?php echo $mmi_sup_use_const ? '' : ' hidden'; ?>">
                                                                <?php $render_constant_input(
                                                                    "field_mappings[{$field_name}][constant_value][{$sid}]",
                                                                    $mapping['type'],
                                                                    $mmi_sup_const_val,
                                                                    $field_name,
                                                                    !$mmi_sup_use_const,
                                                                    $mmi_tax_slug
                                                                ); ?>
                                                            </div>
                                                        </div>
                                                        <label class="mmi-per-source-constant-toggle" title="Use a fixed value for <?php echo esc_attr(strtoupper($sinfo['supplier_name'])); ?> instead of mapping a source field">
                                                            <input type="checkbox"
                                                                   name="field_mappings[<?php echo esc_attr($field_name); ?>][use_constant_value][<?php echo esc_attr($sid); ?>]"
                                                                   value="1"
                                                                   class="use-constant-checkbox"
                                                                   data-field="<?php echo esc_attr($field_name); ?>"
                                                                   data-supplier="<?php echo esc_attr($sid); ?>"
                                                                   <?php checked($mmi_sup_use_const); ?>>
                                                            Const
                                                        </label>
                                                        <?php if ($field_name === '_product_image_url'): ?>
                                                            <?php /* Some feeds (e.g. Xchange's Web Asset API) bundle every
                                                                 image for a product into ONE JSON array with no separate
                                                                 main-image field at all — this tells the importer to split
                                                                 that array itself: first item becomes the featured image,
                                                                 the rest become the gallery. No _product_gallery_urls
                                                                 mapping is needed alongside this. See
                                                                 MMI_Pipeline_Field_Resolver::assemble_product_images(). */ ?>
                                                            <label class="mmi-per-source-constant-toggle" title="This source's field above is a single JSON array holding every image for the product — use its first item as the featured image and the rest as the gallery, instead of mapping a separate gallery field">
                                                                <input type="checkbox"
                                                                       name="field_mappings[<?php echo esc_attr($field_name); ?>][image_array_mode][<?php echo esc_attr($sid); ?>]"
                                                                       value="1"
                                                                       class="image-array-mode-checkbox"
                                                                       data-field="<?php echo esc_attr($field_name); ?>"
                                                                       data-supplier="<?php echo esc_attr($sid); ?>"
                                                                       <?php checked($mmi_sup_image_array); ?>>
                                                                Array holds all images (first = featured, rest = gallery)
                                                            </label>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif (!empty($mapping['source'])): ?>
                                    <?php /* Legacy single source (backward compatibility) — a saved
                                         scalar string from before per-supplier sources existed. */ ?>
                                    <input type="text"
                                           name="field_mappings[<?php echo esc_attr($field_name); ?>][source]"
                                           value="<?php echo esc_attr($mapping['source']); ?>"
                                           class="field-source"
                                           placeholder="Enter field path">
                                <?php else: ?>
                                    <?php /* No 'source' concept at all (e.g. a Taxonomy-Mapping-only
                                         field — though those never reach this branch, see
                                         $mmi_taxonomy_mapping_only_fields above). */ ?>
                                    <input type="text"
                                           name="field_mappings[<?php echo esc_attr($field_name); ?>][source_custom]"
                                           value="<?php echo esc_attr($mapping['source_custom'] ?? ''); ?>"
                                           class="field-source"
                                           placeholder="Enter custom path">
                                <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php
                        // Hierarchical (parent/child) term paths — only offered for
                        // taxonomy-typed fields whose underlying WC taxonomy actually
                        // supports a hierarchy (product_cat/brand/custom hierarchical
                        // taxonomies; product_tag and attribute taxonomies don't, so
                        // they never see this block). Outside the fixed-source-vs-
                        // editable-source branch above (moved here 2026-09-15) since a
                        // Taxonomy-Mapping-only field like product_cat still needs this
                        // control — its saved tax_hierarchical/leaf-only settings are
                        // read at import time by MMI_Pipeline_Field_Resolver::
                        // init_hierarchy_settings() regardless of which UI configures
                        // the field's source. $mmi_tax_slug/$mmi_taxonomy_obj were
                        // already hoisted above, before either branch.
                        if ($mapping['type'] === 'taxonomy'):
                            if ($mmi_taxonomy_obj && $mmi_taxonomy_obj->hierarchical):
                        ?>
                            <div class="mmi-tax-hierarchy-settings">
                                <label class="mmi-checkbox-label" title="Split this field's value into a parent/child term chain instead of one flat term (e.g. &quot;Sports > Golf > Clubs&quot; matches/creates Sports, then its child Golf, then Golf's child Clubs).">
                                    <input type="hidden" name="field_mappings[<?php echo esc_attr($field_name); ?>][tax_hierarchical]" value="0" class="mmi-hidden-toggle-fallback">
                                    <input type="checkbox"
                                           name="field_mappings[<?php echo esc_attr($field_name); ?>][tax_hierarchical]"
                                           value="1"
                                           class="mmi-tax-hierarchical-toggle"
                                           data-field="<?php echo esc_attr($field_name); ?>"
                                           <?php checked(!empty($mapping['tax_hierarchical'])); ?>>
                                    Hierarchical terms (Parent &gt; Child)
                                </label>
                                <div class="mmi-tax-hierarchy-options<?php echo empty($mapping['tax_hierarchical']) ? ' hidden' : ''; ?>">
                                    <label class="mmi-field-label">Separator
                                        <input type="text"
                                               class="mmi-tax-hierarchy-delim"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               name="field_mappings[<?php echo esc_attr($field_name); ?>][tax_hierarchical_delim]"
                                               value="<?php echo esc_attr(!empty($mapping['tax_hierarchical_delim']) ? $mapping['tax_hierarchical_delim'] : '>'); ?>">
                                    </label>
                                    <label class="mmi-checkbox-label" title="Off (default): every level in the chain is matched/created AND assigned to the product. On: only the deepest (most specific) term is assigned — its ancestors are still matched/created so the term tree exists, just not directly assigned.">
                                        <input type="hidden" name="field_mappings[<?php echo esc_attr($field_name); ?>][tax_hierarchical_leaf_only]" value="0" class="mmi-hidden-toggle-fallback">
                                        <input type="checkbox"
                                               class="mmi-tax-hierarchy-leaf-only"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               name="field_mappings[<?php echo esc_attr($field_name); ?>][tax_hierarchical_leaf_only]"
                                               value="1"
                                               <?php checked(!empty($mapping['tax_hierarchical_leaf_only'])); ?>>
                                        Only assign the leaf term
                                    </label>
                                </div>
                            </div>
                        <?php
                            endif;
                        endif;
                        // What an import does with a value that has no Taxonomy
                        // Mapping row or alias rule. Defaults (no saved setting):
                        // leave as is for categories/brands, create for every
                        // other taxonomy — MMI_Pipeline_Field_Resolver::
                        // unmapped_term_policy().
                        if ($mapping['type'] === 'taxonomy' && $mmi_tax_slug !== ''):
                            $mmi_tax_unmapped = MMI_Pipeline_Field_Resolver::unmapped_term_policy($mmi_tax_slug, $mapping);
                        ?>
                            <div class="mmi-tax-hierarchy-settings mmi-tax-unmapped-setting">
                                <label class="mmi-field-label" title="A value with no row in the Taxonomy Mapping tab and no alias rule. Unmapped values are listed under Unmapped in the Taxonomy Mapping tab; mapping one there updates the products that have it.">
                                    When a value isn't in Taxonomy Mapping
                                    <select class="mmi-tax-unmapped-policy"
                                            data-field="<?php echo esc_attr($field_name); ?>"
                                            name="field_mappings[<?php echo esc_attr($field_name); ?>][tax_unmapped]">
                                        <option value="skip" <?php selected($mmi_tax_unmapped, 'skip'); ?>>Leave the product's terms as they are</option>
                                        <option value="match" <?php selected($mmi_tax_unmapped, 'match'); ?>>Use an existing term with the same name</option>
                                        <option value="create" <?php selected($mmi_tax_unmapped, 'create'); ?>>Create a new term</option>
                                    </select>
                                </label>
                            </div>
                        <?php endif; ?>
                        </td>
                        <?php if (in_array($field_name, $mmi_taxonomy_mapping_only_fields, true)): ?>
                        <td class="col-transform">
                            <span class="mmi-no-sources-notice" title="Not applicable — values resolved via Taxonomy Mapping never run through Field Mapping's transform pipeline">N/A</span>
                        </td>
                        <?php else: ?>
                        <?php
                        $cur_t = $mapping['transform'] ?? 'none';
                        $tp    = is_array( $mapping['transform_params'] ?? null ) ? $mapping['transform_params'] : [];
                        // Closure returns '' when transform matches (visible) else hide class.
                        $vis = static function ( string $t ) use ( $cur_t ): string {
                            return $cur_t === $t ? '' : ' mmi-is-hidden';
                        };
                        ?>
                        <td class="col-transform">
                            <select name="field_mappings[<?php echo esc_attr($field_name); ?>][transform]"
                                    class="field-transform"
                                    data-field="<?php echo esc_attr($field_name); ?>">
                                <optgroup label="— Text —">
                                    <option value="none" <?php selected($cur_t, 'none'); ?>>None</option>
                                    <option value="uppercase" <?php selected($cur_t, 'uppercase'); ?>>UPPERCASE</option>
                                    <option value="lowercase" <?php selected($cur_t, 'lowercase'); ?>>lowercase</option>
                                    <option value="title_case" <?php selected($cur_t, 'title_case'); ?>>Title Case</option>
                                    <option value="ucwords" <?php selected($cur_t, 'ucwords'); ?>>Ucwords</option>
                                    <option value="trim" <?php selected($cur_t, 'trim'); ?>>Trim Whitespace</option>
                                    <option value="strip_html" <?php selected($cur_t, 'strip_html'); ?>>Strip HTML</option>
                                    <option value="html_decode" <?php selected($cur_t, 'html_decode'); ?>>HTML Decode</option>
                                    <option value="sanitize_url" <?php selected($cur_t, 'sanitize_url'); ?>>Sanitize URL</option>
                                    <option value="str_replace" <?php selected($cur_t, 'str_replace'); ?>>String Replace ✎</option>
                                    <option value="regex_replace" <?php selected($cur_t, 'regex_replace'); ?>>Regex Replace ✎</option>
                                    <option value="prefix" <?php selected($cur_t, 'prefix'); ?>>Add Prefix ✎</option>
                                    <option value="suffix" <?php selected($cur_t, 'suffix'); ?>>Add Suffix ✎</option>
                                    <option value="template" <?php selected($cur_t, 'template'); ?>>Template (multi-field) ✎</option>
                                </optgroup>
                                <optgroup label="— Numeric —">
                                    <option value="to_integer" <?php selected($cur_t, 'to_integer'); ?>>To Integer</option>
                                    <option value="to_decimal" <?php selected($cur_t, 'to_decimal'); ?>>To Decimal</option>
                                    <option value="boolean" <?php selected($cur_t, 'boolean'); ?>>To Boolean</option>
                                    <option value="price_multiply" <?php selected($cur_t, 'price_multiply'); ?>>× Multiply Price ✎</option>
                                    <option value="price_add" <?php selected($cur_t, 'price_add'); ?>>+ Add to Price ✎</option>
                                    <option value="price_subtract" <?php selected($cur_t, 'price_subtract'); ?>>− Subtract from Price ✎</option>
                                </optgroup>
                                <optgroup label="— Date —">
                                    <option value="to_datetime" <?php selected($cur_t, 'to_datetime'); ?>>To DateTime</option>
                                    <option value="date_format" <?php selected($cur_t, 'date_format'); ?>>Format Date ✎</option>
                                </optgroup>
                                <optgroup label="— Other —">
                                    <option value="map_stock_status" <?php selected($cur_t, 'map_stock_status'); ?>>Map Stock Status</option>
                                </optgroup>
                            </select>

                            <!-- Transform parameter inputs (shown per-transform by JS) -->
                            <div class="mmi-transform-params" data-field="<?php echo esc_attr($field_name); ?>">
                                <div class="mmi-tp-group<?php echo $vis('str_replace'); ?>" data-for-transform="str_replace">
                                    <label class="mmi-tp-label">Find
                                        <input type="text" class="mmi-tp-input" data-param="find"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['find'] ?? ''); ?>"
                                               placeholder="text to find">
                                    </label>
                                    <label class="mmi-tp-label">Replace
                                        <input type="text" class="mmi-tp-input" data-param="replace"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['replace'] ?? ''); ?>"
                                               placeholder="replacement text">
                                    </label>
                                </div>
                                <div class="mmi-tp-group<?php echo $vis('regex_replace'); ?>" data-for-transform="regex_replace">
                                    <label class="mmi-tp-label">Pattern
                                        <input type="text" class="mmi-tp-input" data-param="pattern"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['pattern'] ?? ''); ?>"
                                               placeholder="/pattern/flags">
                                    </label>
                                    <label class="mmi-tp-label">Replacement
                                        <input type="text" class="mmi-tp-input" data-param="replacement"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['replacement'] ?? ''); ?>"
                                               placeholder="replacement (use $1 for groups)">
                                    </label>
                                </div>
                                <div class="mmi-tp-group<?php echo $vis('prefix'); ?>" data-for-transform="prefix">
                                    <label class="mmi-tp-label">Prefix text
                                        <input type="text" class="mmi-tp-input" data-param="text"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['text'] ?? ''); ?>"
                                               placeholder="text to prepend">
                                    </label>
                                </div>
                                <div class="mmi-tp-group<?php echo $vis('suffix'); ?>" data-for-transform="suffix">
                                    <label class="mmi-tp-label">Suffix text
                                        <input type="text" class="mmi-tp-input" data-param="text"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['text'] ?? ''); ?>"
                                               placeholder="text to append">
                                    </label>
                                </div>
                                <div class="mmi-tp-group<?php echo $vis('template'); ?>" data-for-transform="template">
                                    <label class="mmi-tp-label">Template
                                        <input type="text" class="mmi-tp-input mmi-tp-wide" data-param="template"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['template'] ?? ''); ?>"
                                               placeholder="{field.path} — extra text — {other.field}">
                                    </label>
                                    <span class="mmi-tp-hint">Use <code class="mmi-inline-code">{dot.path}</code> tokens referencing source JSON fields.</span>
                                </div>
                                <div class="mmi-tp-group<?php echo $vis('price_multiply'); ?>" data-for-transform="price_multiply">
                                    <label class="mmi-tp-label">Multiplier
                                        <input type="number" step="0.0001" class="mmi-tp-input mmi-tp-narrow" data-param="multiplier"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['multiplier'] ?? ''); ?>"
                                               placeholder="e.g. 1.4">
                                    </label>
                                </div>
                                <div class="mmi-tp-group<?php echo $vis('price_add'); ?>" data-for-transform="price_add">
                                    <label class="mmi-tp-label">Amount
                                        <input type="number" step="0.01" class="mmi-tp-input mmi-tp-narrow" data-param="amount"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['amount'] ?? ''); ?>"
                                               placeholder="e.g. 5.00">
                                    </label>
                                </div>
                                <div class="mmi-tp-group<?php echo $vis('price_subtract'); ?>" data-for-transform="price_subtract">
                                    <label class="mmi-tp-label">Amount
                                        <input type="number" step="0.01" class="mmi-tp-input mmi-tp-narrow" data-param="amount"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['amount'] ?? ''); ?>"
                                               placeholder="e.g. 5.00">
                                    </label>
                                </div>
                                <div class="mmi-tp-group<?php echo $vis('date_format'); ?>" data-for-transform="date_format">
                                    <label class="mmi-tp-label">Format
                                        <input type="text" class="mmi-tp-input" data-param="format"
                                               data-field="<?php echo esc_attr($field_name); ?>"
                                               value="<?php echo esc_attr($tp['format'] ?? ''); ?>"
                                               placeholder="Y-m-d  /  d/m/Y  /  U">
                                    </label>
                                </div>
                            </div><!-- /.mmi-transform-params -->

                            <?php
                            // Per-record condition builder — the suite-wide
                            // MMI_Condition_Builder (same as Catalog Maintenance), evaluated
                            // at import time by Condition_Evaluator via
                            // Stock_Override_Resolver: this field's own mapped value
                            // ("This field's value"), any feed field of the record, or the
                            // existing product's WP/WC data. When conditions fail and no fallback is enabled, the
                            // field is skipped entirely for that record (existing value on
                            // the product, if any, is left untouched) — the backend save
                            // path (ImportSettingsController.php's mmi_autosave_field_property,
                            // property 'conditions'/'condition_fallback_enabled'/
                            // 'condition_fallback_value') and the runtime evaluation both
                            // already existed; this UI was the only missing piece.
                            //
                            // Only the toggle button lives in this narrow Transform cell —
                            // the actual builder renders as its own full-width table row
                            // right after this field's row (below), not nested inside this
                            // <td>: table-layout:fixed (see AGENTS.md's Data Tables rule)
                            // holds every column to a fixed width regardless of content, so
                            // a multi-row condition builder crammed in here just overflowed
                            // the cell and visually overlapped the rows below it.
                            $fm_shown        = \MannMade\DataPipeline\Rule_Builder_View::field_conditions_for_builder( (array) $mapping );
                            $fm_conditions   = $fm_shown['conditions'];
                            $fm_fallback_on  = ! empty( $mapping['condition_fallback_enabled'] );
                            $fm_fallback_val = (string) ( $mapping['condition_fallback_value'] ?? '' );
                            ?>
                            <button type="button" class="mmi-fm-conditions-toggle<?php echo empty( $fm_conditions ) ? '' : ' has-conditions'; ?>" data-field="<?php echo esc_attr( $field_name ); ?>">
                                Conditions<?php echo empty( $fm_conditions ) ? '' : ' (' . count( $fm_conditions ) . ')'; ?>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php if ( ! in_array( $field_name, $mmi_taxonomy_mapping_only_fields, true ) ) : ?>
                    <tr class="mmi-fm-conditions-row<?php echo $row_advanced_class; ?><?php echo empty( $fm_conditions ) ? ' mmi-is-hidden' : ''; ?>" data-field="<?php echo esc_attr( $field_name ); ?>">
                        <td colspan="4" class="mmi-fm-conditions-cell">
                            <div class="mmi-fm-conditions" data-field="<?php echo esc_attr( $field_name ); ?>">
                                <?php
                                MMI_Condition_Builder::render( [
                                    'sources'     => \MannMade\DataPipeline\Rule_Builder_View::source_groups( \MannMade\DataPipeline\Rule_Builder_View::site_sources(), true ),
                                    'conditions'  => $fm_conditions,
                                    'match_logic' => $fm_shown['match_logic'],
                                    'label'       => 'Only apply this field when',
                                    'empty_hint'  => 'No conditions — this field is applied to every record. A record that fails the conditions keeps its existing value (or the fallback below, if enabled).',
                                    'context'     => 'fm:' . ( $current_profile ?? '' ) . ':' . $field_name,
                                    'attrs'       => [ 'data-field' => $field_name ],
                                ] );
                                ?>

                                <label class="mmi-checkbox-label mmi-cond-fallback-toggle">
                                    <input type="hidden" name="field_mappings[<?php echo esc_attr( $field_name ); ?>][condition_fallback_enabled]" value="0" class="mmi-hidden-toggle-fallback">
                                    <input type="checkbox"
                                           class="mmi-cond-fallback-enabled"
                                           data-field="<?php echo esc_attr( $field_name ); ?>"
                                           name="field_mappings[<?php echo esc_attr( $field_name ); ?>][condition_fallback_enabled]"
                                           value="1"
                                           <?php checked( $fm_fallback_on ); ?>>
                                    Use a fallback value when conditions fail (instead of leaving the field unchanged)
                                </label>
                                <input type="text" class="mmi-tp-input mmi-cond-fallback-value<?php echo $fm_fallback_on ? '' : ' mmi-is-hidden'; ?>"
                                       data-field="<?php echo esc_attr( $field_name ); ?>"
                                       name="field_mappings[<?php echo esc_attr( $field_name ); ?>][condition_fallback_value]"
                                       value="<?php echo esc_attr( $fm_fallback_val ); ?>"
                                       placeholder="fallback value">
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /.mmi-fm-table-wrap -->
    </div>
<?php endif; // $mmi_field_mapping_render_now ?>
