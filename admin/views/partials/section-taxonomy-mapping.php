<?php
/**
 * Taxonomy Mapping Section (Import tab)
 *
 * Renders the brand and category mapping UI. Included from
 * pipeline-step-1-acquisition.php (the Supplier Data Sources section),
 * toggled open by the "Taxonomy Mapping" button in that section's
 * .mmi-process-actions toolbar. This has moved several times: a standalone
 * top-level tab (?pipeline_tab=taxonomy) until 2026-08-30, folded into the
 * Import tab as its own top-level section, then merged into Review & Compare
 * later the same day/into 2026-08-31, then moved here — its final, correct
 * home — once the user pointed out that Taxonomy Mapping's own data is keyed
 * by *data source* (supplier/source_field/wc_taxonomy — see
 * MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_source_fields()), the
 * same ownership the Supplier Data Sources table itself represents, not by
 * import profile the way Review & Compare's content is. See the 2026-08-30
 * and 2026-08-31 Incident History entries for the full reasoning at each
 * step.
 *
 * The underlying DATA is still fundamentally a *shared* concern — Global
 * mappings + explicit, opt-in per-value variations, never silently owned by
 * whichever profile happens to be active (see the 2026-08-30 redesign entry
 * for why an ambient profile-owns-everything model was deliberately rejected
 * and is a hard line, not a preference). This section reads the page's
 * shared #mmi-import-profile "active profile" state — a page-global hidden
 * select, not owned by whichever section happens to sit near it — purely as
 * a read-only convenience: which profile a new variation defaults to
 * (taxonomy-mapping.js's deepLinkProfile()) and the profile-scoped summary
 * readout below. Neither of those is a write path — creating/editing a
 * mapping is always the same explicit, named action regardless of where
 * this section is rendered.
 *
 * Communicates with TaxonomyMappingController.php via AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Discover all registered product taxonomies for the taxonomy selector ── */
$raw_product_taxonomies = get_object_taxonomies( 'product', 'objects' );
$product_taxonomies     = [];
foreach ( $raw_product_taxonomies as $tax ) {
    $product_taxonomies[] = [
        'slug'  => $tax->name,
        'label' => $tax->label ?: $tax->name,
    ];
}
usort( $product_taxonomies, fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );

/* ── Import Profiles, for the alias-row Profile scope selector ────────────
 * Only import-direction profiles — export profiles never resolve aliases.
 * Deep-linked via ?pipeline_tab=import&open_taxonomy=1&profile={id} from a
 * specific profile's Field Mapping "Alias mapping" link (see
 * panel-field-mapping.php) so that click lands with this section already
 * expanded and scrolled into view, scoped to the profile the admin came
 * from — see taxonomy-mapping.js's maybeOpenFromUrl().
 */
$mmi_taxmap_import_profiles = MMI_DB::get_profiles_by_direction( 'import' );
$mmi_taxmap_deep_link_profile = isset( $_GET['profile'] ) ? sanitize_text_field( $_GET['profile'] ) : '';
if ( ! isset( $mmi_taxmap_import_profiles[ $mmi_taxmap_deep_link_profile ] ) ) {
    $mmi_taxmap_deep_link_profile = '';
}

/* ── Configured suppliers, for the Advanced panel's supplier dropdown ─────
 * Every currently-configured data source (API-based or uploaded/custom),
 * not a hardcoded xchange/skuport/plugivery list — see
 * MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_source_fields()'s
 * docblock for why this class of hardcoded list was replaced suite-wide.
 */
$mmi_taxmap_configured_suppliers = class_exists( 'MMI_Pipeline_Admin' )
    ? MMI_Pipeline_Admin::get_configured_suppliers()
    : [];

/* ── Preset views ───────────────────────────────────────────────────────── *
 * Derived from every currently-configured (supplier, source_field, wc_taxonomy)
 * triple — MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_source_fields() —
 * instead of a hardcoded xchange/skuport/plugivery list, so a pill for a
 * newly-configured/uploaded source (or a newly-mapped taxonomy on an existing
 * one) appears here automatically once its own Field Mapping entry has a real
 * source, with no template change needed. See that method's own docblock and
 * the 2026-08-30 taxonomy/data-source integration work in AGENTS.md.
 */
$mmi_taxmap_taxonomy_icons = [
    'product_brand' => 'dashicons-tag',
    'product_cat'   => 'dashicons-category',
    'product_tag'   => 'dashicons-tag',
];
$preset_views = [];
// get_taxonomy_mapping_sources() also lists a template source's brand/category
// fields before any profile imports them, so aliases can be set up first.
foreach ( MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_mapping_sources() as $mmi_tv_row ) {
    $preset_views[] = [
        'key'          => $mmi_tv_row['supplier'] . '-' . $mmi_tv_row['wc_taxonomy'],
        'label'        => $mmi_tv_row['label'],
        'supplier'     => $mmi_tv_row['supplier'],
        'source_field' => $mmi_tv_row['source_field'],
        'wc_taxonomy'  => $mmi_tv_row['wc_taxonomy'],
        'icon'         => $mmi_taxmap_taxonomy_icons[ $mmi_tv_row['wc_taxonomy'] ] ?? 'dashicons-networking',
        'suggested'    => ! empty( $mmi_tv_row['suggested'] ),
    ];
}
?>

<div class="mmi-taxmap-wrap" id="mmi-taxmap-app">

    <?php /* Page description — no h2/title here; the wrapping collapsible
         section's own h3 header (tab-pipeline.php) provides that now. */ ?>
    <div class="mmi-taxmap-page-header">
        <p class="mmi-taxmap-applies-note">
            <span class="dashicons dashicons-yes-alt"></span>
            Saving a mapping here applies it right away to existing products with that source
            value (unless the field is locked on the product), and every import applies it to
            new and changed products. Alias Rules apply at import; use Apply All to Existing
            Products to push them onto products already in the store.
        </p>
    </div>

    <!-- Toolbar: filter pills + primary actions -->
    <div class="mmi-toolbar mmi-taxmap-toolbar">

        <!-- Source filter pills -->
        <div class="mmi-taxmap-filter-pills" id="mmi-taxmap-filter-pills">
            <button class="mmi-taxmap-pill is-active" data-filter-supplier="" data-filter-field="">
                All Sources
            </button>
            <?php foreach ( $preset_views as $preset ) : ?>
                <button
                    class="mmi-taxmap-pill"
                    data-filter-supplier="<?php echo esc_attr( $preset['supplier'] ); ?>"
                    data-filter-field="<?php echo esc_attr( $preset['source_field'] ); ?>"
                    <?php if ( $preset['suggested'] ) : ?>title="No import profile maps this field yet. Mappings saved here are used once a profile maps it in Field Mapping."<?php endif; ?>
                >
                    <span class="dashicons <?php echo esc_attr( $preset['icon'] ); ?>"></span>
                    <?php echo esc_html( $preset['label'] ); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Primary action buttons -->
        <div class="mmi-taxmap-primary-actions">
            <button class="button button-primary mmi-taxmap-apply-btn mmi-action-btn" id="mmi-taxmap-apply-btn"
                    title="Apply all saved mappings to existing WooCommerce products">
                <span class="dashicons dashicons-database-import"></span>
                Apply All to Existing Products
            </button>
            <button class="button mmi-taxmap-refresh-btn mmi-action-btn" id="mmi-taxmap-refresh-btn"
                    title="Reload source data from supplier JSON files">
                <span class="dashicons dashicons-update"></span>
                Reload
            </button>
        </div>
    </div>

    <?php /* Every mapping saved via the main term-search input below is Global — it
         applies to every Import Profile. There is no ambient "editing scope" for
         this page: a profile-specific override is always an explicit, per-value
         action taken from that value's own row ("+ Add profile variation"), never
         a page-wide mode you could leave switched on by accident — see the
         2026-08-30 Incident History entry ("Taxonomy Mapping Table Hardcoded
         profile_id === ''...") for the data-loss-looking report an ambient scope
         selector caused before this redesign.

         Hidden here only as a template: every import profile's <option> list,
         cloned by JS into each row's variation-profile picker and the Copy
         action's two selectors below, so there's one place that list is rendered
         from. data-deep-link carries ?profile={id} (set by a specific profile's
         Field Mapping "Alias mapping" link — see panel-field-mapping.php) as the
         default pre-selected profile for a row's "+ Add variation" picker, purely
         a convenience default — it never scopes the page itself. */ ?>
    <select id="mmi-taxmap-profile-options-template" class="mmi-hidden" data-deep-link="<?php echo esc_attr( $mmi_taxmap_deep_link_profile ); ?>">
        <?php foreach ( $mmi_taxmap_import_profiles as $mmi_tp_id => $mmi_tp_info ) : ?>
            <option value="<?php echo esc_attr( $mmi_tp_id ); ?>"><?php echo esc_html( $mmi_tp_info['name'] ?? $mmi_tp_id ); ?></option>
        <?php endforeach; ?>
    </select>

    <!-- Custom / advanced mapping panel (collapsed by default) -->
    <div class="mmi-taxmap-advanced-toggle">
        <button type="button" class="mmi-taxmap-advanced-toggle-btn" id="mmi-taxmap-custom-btn">
            <span class="dashicons dashicons-admin-settings"></span>
            Advanced: Custom Source Mapping
        </button>
        <button type="button" class="mmi-taxmap-advanced-toggle-btn" id="mmi-taxmap-rules-btn">
            <span class="dashicons dashicons-randomize"></span>
            Alias Rules
            <span class="mmi-taxmap-rules-badge mmi-hidden" id="mmi-taxmap-rules-badge">0</span>
        </button>
        <button type="button" class="mmi-taxmap-advanced-toggle-btn" id="mmi-taxmap-variations-btn">
            <span class="dashicons dashicons-networking"></span>
            Manage Variations
            <span class="mmi-taxmap-rules-badge mmi-hidden" id="mmi-taxmap-variations-badge">0</span>
        </button>
    </div>

    <?php /* Manage Variations panel (collapsed by default) — every saved
         profile-specific override across the whole dataset, flat, so bulk
         review/cleanup doesn't require expanding each row's own variation
         panel one at a time. See the 2026-08-30 "explicit per-value
         variations" redesign entry in AGENTS.md for why per-row is still the
         right place to ADD one, and why this panel exists alongside it for
         reviewing/removing many at once. */ ?>
    <div class="mmi-taxmap-config-panel mmi-hidden" id="mmi-taxmap-variations-panel">
        <p class="description mmi-taxmap-rules-desc">
            Every profile-specific override currently saved, across every source value. Adding a new
            one still happens from that value's own row ("+ Add profile variation") — this panel is
            for reviewing and removing what's already there, individually or in bulk.
        </p>
        <div class="mmi-taxmap-variations-toolbar">
            <label for="mmi-taxmap-variations-profile-filter">
                Show variations for:
            </label>
            <select id="mmi-taxmap-variations-profile-filter">
                <option value="">All Profiles</option>
            </select>
            <button type="button" class="button mmi-action-btn mmi-action-btn--danger" id="mmi-taxmap-variations-wipe-btn" disabled>
                <span class="dashicons dashicons-trash"></span>
                Delete All for This Profile
            </button>
            <span class="mmi-taxmap-variations-spacer"></span>
            <button type="button" class="button mmi-action-btn mmi-action-btn--danger mmi-hidden" id="mmi-taxmap-variations-delete-selected-btn">
                <span class="dashicons dashicons-no-alt"></span>
                Delete Selected (<span id="mmi-taxmap-variations-selected-count">0</span>)
            </button>
            <button type="button" class="button mmi-action-btn" id="mmi-taxmap-variations-refresh-btn">
                <span class="dashicons dashicons-update"></span>
                Refresh
            </button>
        </div>
        <table class="mmi-taxmap-rules-table mmi-taxmap-variations-table" id="mmi-taxmap-variations-table">
            <thead>
                <tr>
                    <th class="col-select"><input type="checkbox" id="mmi-taxmap-variations-select-all"></th>
                    <th class="col-value">Source Value</th>
                    <th class="col-supplier">Source</th>
                    <th class="col-taxonomy">Taxonomy</th>
                    <th class="col-profile">Profile</th>
                    <th class="col-term">→ Term</th>
                    <th class="col-actions"></th>
                </tr>
            </thead>
            <tbody id="mmi-taxmap-variations-tbody">
                <!-- Rows injected by JS -->
            </tbody>
        </table>
        <p class="description mmi-taxmap-variations-empty mmi-hidden" id="mmi-taxmap-variations-empty">
            No profile-specific variations saved yet.
        </p>
    </div>

    <!-- Alias rules panel (collapsed by default) -->
    <div class="mmi-taxmap-config-panel mmi-hidden" id="mmi-taxmap-rules-panel">
        <p class="description mmi-taxmap-rules-desc">
            Resolution order: <strong>1.</strong> exact mapping in the table below
            &nbsp;→&nbsp; <strong>2.</strong> alias rules here, top to bottom, first match wins
            &nbsp;→&nbsp; <strong>3.</strong> unmapped.
            One rule (e.g. <em>contains "roland"</em>) catches every supplier spelling instead of
            mapping each one by hand.
        </p>
        <table class="mmi-taxmap-rules-table" id="mmi-taxmap-rules-table">
            <thead>
                <tr>
                    <th class="col-order"></th>
                    <th class="col-label">Label</th>
                    <th class="col-supplier">Supplier</th>
                    <th class="col-field">Source Field</th>
                    <th class="col-operator">Operator</th>
                    <th class="col-value">Value</th>
                    <th class="col-taxonomy">Taxonomy</th>
                    <th class="col-term">→ Term</th>
                    <th class="col-matches">Matches</th>
                    <th class="col-actions"></th>
                </tr>
            </thead>
            <tbody id="mmi-taxmap-rules-tbody">
                <!-- Rows injected by JS -->
            </tbody>
        </table>
        <div class="mmi-taxmap-rules-actions">
            <button type="button" class="button mmi-action-btn" id="mmi-taxmap-rules-add-btn">
                <span class="dashicons dashicons-plus-alt2"></span> Add Rule
            </button>
            <button type="button" class="button button-primary mmi-action-btn" id="mmi-taxmap-rules-save-btn">
                <span class="dashicons dashicons-saved"></span> Save Rules
            </button>
        </div>
    </div>

    <div class="mmi-taxmap-config-panel mmi-hidden" id="mmi-taxmap-config-panel">
        <div class="mmi-taxmap-config-row">

            <div class="mmi-taxmap-config-field">
                <label for="mmi-taxmap-supplier">Supplier</label>
                <select id="mmi-taxmap-supplier">
                    <?php foreach ( $mmi_taxmap_configured_suppliers as $mmi_tcs_id => $mmi_tcs_info ) : ?>
                        <option value="<?php echo esc_attr( $mmi_tcs_id ); ?>">
                            <?php echo esc_html( $mmi_tcs_info['supplier_name'] ?? $mmi_tcs_id ); ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="">— Global (all suppliers) —</option>
                </select>
            </div>

            <div class="mmi-taxmap-config-field">
                <label for="mmi-taxmap-source-field">Source Field</label>
                <input type="text" id="mmi-taxmap-source-field"
                       placeholder="e.g. brand"
                       value="brand" />
                <p class="description">Use <code>field1+field2</code> for compound fields.</p>
            </div>

            <div class="mmi-taxmap-config-field">
                <label for="mmi-taxmap-wc-taxonomy">WooCommerce Taxonomy</label>
                <select id="mmi-taxmap-wc-taxonomy">
                    <?php foreach ( $product_taxonomies as $tax ) : ?>
                        <option value="<?php echo esc_attr( $tax['slug'] ); ?>">
                            <?php echo esc_html( $tax['label'] . ' (' . $tax['slug'] . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mmi-taxmap-config-actions">
                <span class="mmi-taxmap-config-actions-spacer" aria-hidden="true">&nbsp;</span>
                <button class="button button-primary mmi-action-btn" id="mmi-taxmap-scan-btn">
                    <span class="dashicons dashicons-search"></span>
                    Add to Table
                </button>
            </div>
        </div>
        <p class="description">
            Scans an additional source field and merges results into the table above.
        </p>
    </div>

    <!-- Progress bar for batch operations -->
    <div class="mmi-taxmap-progress mmi-hidden" id="mmi-taxmap-progress">
        <div class="mmi-taxmap-progress-bar">
            <div class="mmi-taxmap-progress-fill" id="mmi-taxmap-progress-fill"></div>
        </div>
        <div class="mmi-taxmap-progress-text" id="mmi-taxmap-progress-text">Working…</div>
    </div>

    <!-- Notice area -->
    <div id="mmi-taxmap-notice" class="mmi-taxmap-notice mmi-hidden"></div>

    <?php /* Stats bar (shows once data is loaded) — the three counters ARE the status filter,
         no separate radio group duplicating what they already show. */ ?>
    <div class="mmi-taxmap-stats mmi-hidden" id="mmi-taxmap-stats">
        <button type="button" class="mmi-taxmap-stat is-active" data-status-filter="all">
            Total source values: <strong id="mmi-taxmap-total">0</strong>
        </button>
        <button type="button" class="mmi-taxmap-stat mmi-taxmap-stat-mapped" data-status-filter="mapped">
            Mapped: <strong id="mmi-taxmap-mapped-count">0</strong>
        </button>
        <button type="button" class="mmi-taxmap-stat mmi-taxmap-stat-unmapped" data-status-filter="unmapped">
            Unmapped: <strong id="mmi-taxmap-unmapped-count">0</strong>
        </button>
        <?php /* Deleted-term check: only shown (by taxonomy-mapping.js) when a mapping points at a term that no longer exists. */ ?>
        <button type="button" class="mmi-taxmap-stat mmi-taxmap-stat-missing mmi-hidden" id="mmi-taxmap-stat-missing" data-status-filter="term_missing">
            <span class="dashicons dashicons-warning"></span> Deleted terms: <strong id="mmi-taxmap-missing-count">0</strong>
        </button>

        <!-- Search within loaded values -->
        <span class="mmi-taxmap-search">
            <input type="search" id="mmi-taxmap-search-input" placeholder="Filter values…" />
        </span>
    </div>

    <!-- Mapping table -->
    <div id="mmi-taxmap-table-wrap">
        <table class="mmi-taxmap-table mmi-hidden" id="mmi-taxmap-table">
            <thead>
                <tr>
                    <th class="col-source sortable" data-col="source_value" data-resize-col="source">
                        Source Value <span class="sort-icon"></span>
                        <span class="mmi-taxmap-th-resize" data-resize-col="source"></span>
                    </th>
                    <th class="col-source-file sortable" data-col="source_file" data-resize-col="source-file">
                        Source <span class="sort-icon"></span>
                        <span class="mmi-taxmap-th-resize" data-resize-col="source-file"></span>
                    </th>
                    <th class="col-count sortable" data-col="count" data-resize-col="count">
                        Products <span class="sort-icon"></span>
                        <span class="mmi-taxmap-th-resize" data-resize-col="count"></span>
                    </th>
                    <th class="col-samples" data-resize-col="samples">
                        Sample Products
                        <span class="mmi-taxmap-th-resize" data-resize-col="samples"></span>
                    </th>
                    <th class="col-arrow"></th>
                    <th class="col-target" data-resize-col="target">
                        WooCommerce Term
                        <span class="mmi-taxmap-th-resize" data-resize-col="target"></span>
                    </th>
                    <th class="col-status sortable" data-col="status" data-resize-col="status">
                        Status <span class="sort-icon"></span>
                        <span class="mmi-taxmap-th-resize" data-resize-col="status"></span>
                    </th>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody id="mmi-taxmap-tbody">
                <!-- Rows injected by JS -->
            </tbody>
        </table>

        <div class="mmi-taxmap-empty mmi-hidden" id="mmi-taxmap-empty">
            <span class="dashicons dashicons-info"></span>
            No source values found. Check that supplier JSON files are present, then click
            <strong>Reload</strong>.
        </div>

        <div class="mmi-taxmap-loading mmi-hidden" id="mmi-taxmap-loading">
            <span class="spinner is-active"></span>
            Scanning source data…
        </div>
    </div>

</div><!-- .mmi-taxmap-wrap -->

<!-- Term autocomplete dropdown (shared, repositioned by JS) -->
<div class="mmi-taxmap-autocomplete mmi-hidden" id="mmi-taxmap-autocomplete">
    <div class="mmi-taxmap-ac-header mmi-hidden" id="mmi-taxmap-ac-header"></div>
    <ul class="mmi-taxmap-autocomplete-list" id="mmi-taxmap-autocomplete-list"></ul>
    <div class="mmi-taxmap-autocomplete-create mmi-hidden" id="mmi-taxmap-autocomplete-create">
        <button class="mmi-taxmap-create-term-btn">
            <span class="dashicons dashicons-plus-alt2"></span>
            Create term: "<span class="create-term-name"></span>"
        </button>
    </div>
</div>
