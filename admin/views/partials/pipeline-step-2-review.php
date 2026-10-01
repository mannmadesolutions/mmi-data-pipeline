<?php
/**
 * Pipeline Step 2: Review & Compare
 * Preview and compare products before importing
 *
 * Variables injected by the including file (tab-pipeline.php):
 *
 * @var array  $profiles              All configured import profiles.
 * @var string $current_profile       Active profile's id/slug (e.g. 'default').
 * @var array  $current_profile_meta  Active profile's full saved meta, including 'name'.
 * @var array  $mode_info             Current mode metadata (label, icon, color) — already
 *                                    overridden by tab-pipeline.php when product_scope is 'new_only'.
 * @var string $current_import_mode   Active import mode slug (e.g. 'update-only').
 * @var string $current_product_scope Active profile's product_scope ('all_products'|'by_identifier'|'new_only').
 * @var string $current_data_type     Active profile's data_type (e.g. 'product') — used at
 *                                    line 24 but missing from this list until now.
 */

if (!defined('ABSPATH')) {
    exit;
}

// All 7 are always set by tab-pipeline.php (its only caller) before
// including this file — guarded here anyway so this file is
// self-consistent for static analysis.
$profiles              = $profiles              ?? [];
$current_profile       = $current_profile       ?? 'default';
$current_profile_meta  = $current_profile_meta  ?? [];
$mode_info              = $mode_info              ?? [ 'color' => '', 'icon' => '', 'label' => '' ];
$current_import_mode   = $current_import_mode   ?? '';
$current_product_scope = $current_product_scope ?? '';
$current_data_type     = $current_data_type     ?? 'product';
?>

<div class="mmi-pipeline-step-content">

<?php if ( $current_data_type !== 'product' ) : ?>
    <?php
    /* Non-Product data types get a plain record count instead of the rich
     * per-row diff below — see DATA_PIPELINE_PHASE2_SCOPING.md Milestone 5.
     * Deliberately a bypass, not a generalization: building a second
     * 1700-line preview system for a data type with no real daily usage yet
     * is exactly the over-engineering this project's own Business &
     * Feasibility Judgment section warns against. Revisit once a real
     * non-Product profile is in daily use and this plain count proves
     * insufficient.
     */
    $mmi_generic_preview_supplier = $current_profile_meta['sources'][0] ?? null;
    $mmi_generic_preview_count    = $mmi_generic_preview_supplier
        ? \MannMade\DataPipeline\Importers\MMI_Dynamic_Record_Importer::count_feed_items( $mmi_generic_preview_supplier )
        : 0;
    $mmi_generic_preview_handler = class_exists( 'MMI_Data_Type_Registry' ) ? MMI_Data_Type_Registry::get( $current_data_type ) : null;
    $mmi_generic_preview_label   = $mmi_generic_preview_handler ? $mmi_generic_preview_handler->get_label() : $current_data_type;
    ?>
    <div id="import-preview-main" class="mmi-preview-main-container mmi-generic-preview<?php echo empty($profiles) ? ' mmi-is-hidden' : ''; ?>">
        <div class="mmi-wizard-mode-locked-notice">
            <span class="mmi-wizard-mode-locked-icon">📋</span>
            <div>
                <strong class="mmi-wizard-mode-locked-title">
                    <?php if ( $mmi_generic_preview_supplier ) : ?>
                        <?php echo (int) $mmi_generic_preview_count; ?> record(s) found in the assigned source
                    <?php else : ?>
                        No data source assigned yet
                    <?php endif; ?>
                </strong>
                <p class="mmi-wizard-mode-locked-desc">
                    A detailed row-by-row preview isn&rsquo;t available yet for <?php echo esc_html( $mmi_generic_preview_label ); ?> —
                    Review &amp; Compare's rich diff currently only supports Product profiles. Run the import to see
                    the real results.
                </p>
            </div>
        </div>
    </div>
<?php else : ?>

    <!-- Import Preview Main Container -->
    <div id="import-preview-main" class="mmi-preview-main-container<?php echo empty($profiles) ? ' mmi-is-hidden' : ''; ?>">

        <!-- Compact Control Toolbar -->
        <div class="mmi-preview-toolbar">
            <div class="mmi-toolbar-section mmi-toolbar-toggle">
                <span class="mmi-toolbar-profile-info" id="mmi-toolbar-profile-info">
                    <span class="mmi-toolbar-label">Previewing:</span>
                    <strong id="mmi-toolbar-profile-name"><?php echo esc_html( $current_profile_meta['name'] ?? ucfirst( $current_profile ) ); ?></strong>
                </span>
                <span class="mmi-toolbar-mode-info">
                    <span class="mmi-toolbar-label">Mode:</span>
                    <span class="mmi-toolbar-mode-badge" id="mmi-toolbar-mode-badge" style="--mmi-mode-color:<?php echo esc_attr($mode_info['color']); ?>">
                        <?php echo $mode_info['icon']; ?> <strong><?php echo $mode_info['label']; ?></strong>
                    </span>
                    <span class="mmi-toolbar-mode-hint" id="mmi-toolbar-mode-hint"><?php
                        if ( $current_product_scope === 'new_only' ) {
                            echo '&mdash; new products created; existing products never modified';
                        } else {
                            echo $current_import_mode === 'create-and-update' ? '&mdash; new products will be created' : '&mdash; new products will be skipped';
                        }
                    ?></span>
                </span>
            </div>

            <?php /* Primary Keys and Field Mappings buttons removed — both panels are
                 retired; primary key config and field mapping now live in the
                 Import Profile Wizard's own steps. */ ?>
        </div>

        <!-- Advanced Filter Bar -->
        <div class="mmi-filter-bar" data-wc-filter-bar>
            <div class="mmi-filter-row">

                <!-- Status (pipeline-specific) — Create/Changed/Unchanged start
                     visible and are shown/hidden by updateFilterBarAvailability()
                     in import-preview.js once real preview data has loaded,
                     based on which of those three actually occur among the
                     loaded rows (not a static guess from the profile's stored
                     import_mode, which product_scope='new_only' can disagree
                     with — see the 2026-08-30 Incident History entry). -->
                <div class="mmi-filter-group">
                    <label class="mmi-filter-label">Status</label>
                    <div class="mmi-filter-status-buttons">
                        <button type="button" class="mmi-filter-status-btn active" data-status="all">All</button>
                        <button type="button" class="mmi-filter-status-btn" data-status="create" id="mmi-filter-status-create">Create</button>
                        <button type="button" class="mmi-filter-status-btn" data-status="update">Changed</button>
                        <button type="button" class="mmi-filter-status-btn" data-status="unchanged">Unchanged</button>
                    </div>
                </div>

                <?php /* Every filter carrying the "mmi-native-filter-group" class
                     below is a fact about an EXISTING, already-matched
                     WooCommerce product — meaningless for a 'create' row (no
                     WC product yet). It's a shared CLASS, not a wrapping
                     <div>, since these controls span three separate sibling
                     .mmi-filter-row elements (this row, the WC Price row, and
                     the Categories row further down) that can't be nested
                     inside one wrapper without breaking those rows' own
                     layout — the same reason updateWcFactFilterAvailability()
                     in import-preview.js already toggles Stock/Image/Price as
                     three independent selectors rather than one container.
                     updateNativeFilterGroupAvailability() toggles every
                     .mmi-native-filter-group element at once by this shared
                     class; a "Create Only"/new_only profile (every loaded row
                     is a create) hides all of them in favor of
                     #mmi-source-filters-group below. */ ?>

                <!-- WC Stock filter (from shared bar) -->
                <?php if ( class_exists( 'MMI_WC_Product_Filter_Handler' ) ) : ?>
                <div class="mmi-filter-group mmi-native-filter-group" data-filter-id="wc-filter-stock">
                    <label class="mmi-filter-label">Stock</label>
                    <div class="mmi-filter-pills" data-filter="wc-filter-stock" data-counts-key="stock">
                        <button type="button" class="mmi-pill" data-value="in_stock">In Stock</button>
                        <button type="button" class="mmi-pill" data-value="out_of_stock">Out of Stock</button>
                    </div>
                    <input type="hidden" id="wc-filter-stock" value="">
                </div>

                <!-- WC Image filter (from shared bar) -->
                <div class="mmi-filter-group mmi-native-filter-group" data-filter-id="wc-filter-image">
                    <label class="mmi-filter-label">Image</label>
                    <div class="mmi-filter-pills" data-filter="wc-filter-featured-image" data-counts-key="image">
                        <button type="button" class="mmi-pill" data-value="yes">Has Image</button>
                        <button type="button" class="mmi-pill" data-value="no">Missing</button>
                    </div>
                    <input type="hidden" id="wc-filter-featured-image" value="">
                </div>

                <!-- Author filter — options built dynamically by
                     updateAuthorFilterAvailability() from whichever authors
                     actually appear among the loaded rows' matched products. -->
                <div class="mmi-filter-group mmi-native-filter-group mmi-is-hidden" data-filter-id="mmi-filter-author" id="mmi-filter-author-container">
                    <label class="mmi-filter-label">Author</label>
                    <div id="mmi-filter-author-options" class="mmi-taxonomy-filters-inline"></div>
                </div>

                <!-- Coupon filter — simple yes/no, mirrors the Image pills exactly. -->
                <div class="mmi-filter-group mmi-native-filter-group" data-filter-id="mmi-filter-coupon">
                    <label class="mmi-filter-label">Coupon</label>
                    <div class="mmi-filter-pills" data-filter="mmi-filter-coupon" data-counts-key="coupon">
                        <button type="button" class="mmi-pill" data-value="yes">In Active Coupon</button>
                        <button type="button" class="mmi-pill" data-value="no">Not in a Coupon</button>
                    </div>
                    <input type="hidden" id="mmi-filter-coupon" value="">
                </div>
                <?php endif; ?>

                <?php if ( class_exists( 'MMI_WC_Product_Filter_Handler' ) ) : ?>
                <?php /* Combined search — a single input covering both capabilities that
                     used to live in two separate, confusingly-similar boxes:
                     1) the shared WC filter bar's server-side search (via the hidden
                        #wc-filter-search value, read by MMI_WcFilterBar), which matches
                        EXISTING WC products by name/SKU/ID — only affects "update" rows,
                        since "create" rows have no WC product yet to query.
                     2) a client-side match (see the 'input' handler on
                        #wc-product-search-input in import-preview.js) against the
                        already-loaded item's product_name/primary key, which covers
                        "create" rows the server-side search can't reach. */ ?>
                <div class="mmi-filter-group mmi-filter-group--search" data-filter-id="wc-search">
                    <label class="mmi-filter-label">Search</label>
                    <div class="mmi-product-search-wrap">
                        <input type="text"
                               id="wc-product-search-input"
                               class="mmi-product-search mmi-filter-input"
                               placeholder="Search by name, SKU, ID…"
                               autocomplete="off">
                    </div>
                    <input type="hidden" id="wc-filter-search" value="">
                </div>
                <?php endif; ?>

                <button type="button" class="mmi-filter-clear-btn" id="btn-clear-filters">
                    <span class="dashicons dashicons-dismiss"></span>
                    Clear
                </button>
            </div>

            <?php if ( class_exists( 'MMI_WC_Product_Filter_Handler' ) ) : ?>
            <!-- WC price (second row) -->
            <div class="mmi-filter-row mmi-pipeline-wc-search-price mmi-native-filter-group">
                <div class="mmi-filter-group" data-filter-id="wc-filter-price-range">
                    <label class="mmi-filter-label">WC Price</label>
                    <div class="mmi-price-range-inputs">
                        <span class="mmi-price-prefix">$</span>
                        <input type="number" id="wc-filter-price-min" class="mmi-price-input" placeholder="Min" min="0" step="0.01">
                        <span class="mmi-price-sep">&mdash;</span>
                        <span class="mmi-price-prefix">$</span>
                        <input type="number" id="wc-filter-price-max" class="mmi-price-input" placeholder="Max" min="0" step="0.01">
                    </div>
                </div>
            </div>

            <!-- Orders filter (status-aware) — status checkboxes built dynamically
                 by updateOrdersFilterAvailability() from whichever statuses actually
                 appear among the loaded rows' matched products' order history, using
                 wc_get_order_statuses() for labels (same helper export-scope-fields.php
                 already uses for its own Order status filter). Optional "since" date
                 narrows to products last ordered on/after that date. -->
            <div class="mmi-filter-row mmi-filter-row--orders mmi-native-filter-group mmi-is-hidden" id="mmi-filter-orders-container">
                <span class="mmi-filter-label mmi-filter-row-label" title="Filters by whether the MATCHED WooCommerce product has been ordered with this status — this profile is importing product data, not order data.">Order History</span>
                <div id="mmi-filter-orders-options" class="mmi-taxonomy-filters-inline"></div>
                <div class="mmi-filter-group mmi-filter-group--orders-date">
                    <label class="mmi-filter-label" for="mmi-filter-orders-since">Since</label>
                    <input type="date" id="mmi-filter-orders-since" class="mmi-filter-input">
                </div>
            </div>
            <?php endif; ?>

            <?php /* Taxonomy term filters (checkboxes/multi-select), one group per
                 taxonomy — used to render statically for EVERY show_ui product
                 taxonomy the whole site has registered, regardless of whether
                 any of it applied to what was actually being previewed (a
                 profile that only ever creates new products has no existing
                 product for any of these to filter, since a to-be-created row
                 has no WC terms yet — every group was dead weight). Now built
                 entirely client-side by updateFilterBarAvailability() in
                 import-preview.js, from whichever taxonomies + terms actually
                 appear in item.current_terms across the loaded preview data —
                 see that function for the full explanation. This container
                 starts empty and hidden; JS fills it in (or leaves it hidden)
                 once real preview data has loaded. */ ?>
            <div class="mmi-filter-row mmi-filter-row--categories mmi-native-filter-group mmi-is-hidden" id="taxonomy-field-filters-container">
                <span class="mmi-filter-label mmi-filter-row-label">Categories</span>
                <div id="taxonomy-field-filters" class="mmi-taxonomy-filters-inline"></div>
            </div>

            <?php /* "Create Only" source-data filters — built entirely client-side
                 by updateSourceFieldFilterAvailability() in import-preview.js from
                 each enabled supplier's calculated filterable-fields schema (see
                 generate_preview()'s 'filterable_fields' response key, backed by
                 MMI_Pipeline_Config_Validator::calculate_filterable_fields()) and
                 whatever values actually appear in item.source_filter_values across
                 the loaded rows. Shown only when the loaded preview has 'create'
                 rows — a brand-new product has no WC identity yet, so this is the
                 only real way to filter "which of these new products" using the
                 data actually driving the import. Starts empty/hidden; JS fills it
                 in (or leaves it hidden when a supplier has no calculated schema
                 yet, e.g. its source predates this feature and hasn't been
                 re-fetched/re-tested since). */ ?>
            <div class="mmi-filter-row mmi-filter-row--source-fields mmi-is-hidden" id="mmi-source-filters-group">
                <span class="mmi-filter-label mmi-filter-row-label">Source Data</span>
                <div id="mmi-source-field-filters" class="mmi-taxonomy-filters-inline"></div>
            </div>
        </div>

        <!-- Preview Stats Summary -->
        <div id="preview-stats-summary" class="mmi-preview-stats-summary">
            <!-- Summary stats will be inserted by JS -->
        </div>

        <!-- Refresh-pending notice: shown by JS while the preview debounce is active -->
        <div id="mmi-refresh-notice" class="mmi-refresh-notice mmi-refresh-notice--hidden">
            <span class="mmi-spinner-sm"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M286.7 96.1C291.7 113 282.1 130.9 265.2 135.9C185.9 159.5 128.1 233 128.1 320C128.1 426 214.1 512 320.1 512C426.1 512 512.1 426 512.1 320C512.1 233.1 454.3 159.6 375 135.9C358.1 130.9 348.4 113 353.5 96.1C358.6 79.2 376.4 69.5 393.3 74.6C498.9 106.1 576 204 576 320C576 461.4 461.4 576 320 576C178.6 576 64 461.4 64 320C64 204 141.1 106.1 246.9 74.6C263.8 69.6 281.7 79.2 286.7 96.1z"/></svg></span>
            Recalculating preview&hellip;
        </div>

        <!-- Preview Table -->
        <div id="preview-table-content" class="mmi-preview-table-content">
            <div class="mmi-preview-loading">
                <span class="mmi-spinner"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M286.7 96.1C291.7 113 282.1 130.9 265.2 135.9C185.9 159.5 128.1 233 128.1 320C128.1 426 214.1 512 320.1 512C426.1 512 512.1 426 512.1 320C512.1 233.1 454.3 159.6 375 135.9C358.1 130.9 348.4 113 353.5 96.1C358.6 79.2 376.4 69.5 393.3 74.6C498.9 106.1 576 204 576 320C576 461.4 461.4 576 320 576C178.6 576 64 461.4 64 320C64 204 141.1 106.1 246.9 74.6C263.8 69.6 281.7 79.2 286.7 96.1z"/></svg></span>
                <p>Loading preview data...</p>
            </div>
        </div>
    </div>

<?php endif; ?>

</div>
