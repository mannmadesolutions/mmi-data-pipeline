<?php
/**
 * Duplicate Products Section
 *
 * Surfaces products from supplier feeds that appear to be the same physical
 * item listed more than once (e.g. via different vendors/distributors at
 * different dealer costs) and lets the user link them via the existing
 * `canonical` / `associated_product_ids` postmeta that CatalogUpdater already
 * consumes. Communicates with CanonicalCandidatesController.php via AJAX.
 *
 * As of the changelog.md 1.72.0 fold-in, the "Candidates"
 * detection mode's table is a UNIFIED queue merging two independent sources
 * (see duplicate-products.js's candidateGroups/existingGroups/allGroups):
 * freshly-detected feed-match groups (source: 'candidate', from
 * mmi_scan_duplicate_candidates) and the real, pre-existing canonical/
 * associated_product_ids links already in the database (source: 'legacy',
 * from mmi_scan_existing_canonical_groups) — a "Source" column distinguishes
 * the two, and a legacy row's Manage panel supports editing an existing
 * group's membership (remove a member, reassign canonical, unlink entirely),
 * not just approving a brand-new one.
 *
 * Moved here from its own top-level tab (tab-duplicate-products.php, now
 * retired — see main.php's backward-compat redirect for ?pipeline_tab=duplicates)
 * 2026-09-02, onto the Import tab as a collapsible section — content and IDs
 * unchanged, only the page-level <h2> header replaced by this section's own
 * <h3>, matching the same move already made for Taxonomy Mapping. Starts
 * collapsed: this is a feed-scanning scan, not a cheap render, so it
 * shouldn't run on every Import tab load — see duplicate-products.js's
 * bindSectionToggle()/ensureLoaded() for the load-on-expand wiring.
 *
 * Expects from the parent scope (tab-pipeline.php): $mmi_duplicate_products_enabled.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Always set by tab-pipeline.php (its only caller) before including this
// file — guarded here anyway so this file is self-consistent for static
// analysis.
$mmi_duplicate_products_enabled = $mmi_duplicate_products_enabled ?? false;
?>

<div class="mmi-process-section mmi-collapsible-section collapsed" id="mmi-duplicate-products-section" data-section="duplicates">

    <div class="mmi-section-header mmi-process-section-header-row mmi-section-clickable">
        <div>
            <h3 class="mmi-process-section-header">
                <span class="dashicons dashicons-randomize"></span>
                Duplicates
                <span class="mmi-collapse-toggle">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </span>
            </h3>
            <p class="mmi-process-section-description" id="mmi-dupes-description-candidates">The same physical product is sometimes listed more than once — either freshly detected by matching brand + product name across live supplier feeds, or already linked in the database from an earlier session. Both appear together below (see the Source column) as one queue: pick which listing should be canonical (customer-facing) for a new group, or manage membership (remove a member, reassign canonical, unlink) for one that's already linked.</p>
            <p class="mmi-process-section-description mmi-hidden" id="mmi-dupes-description-collisions">Two separate WooCommerce products sometimes end up carrying the exact same supplier tracking key — a data defect, not a legitimate multi-vendor listing. Only one of the two is ever reachable by any future import run; the other silently stops receiving price/stock updates forever. Review each pair below before deciding which one to keep.</p>
        </div>
        <div class="mmi-section-header-actions" onclick="event.stopPropagation()">
            <label class="mmi-toggle-switch mmi-section-master-toggle" title="Turn off to disable Duplicate Products scanning entirely — the feed scan is disabled until this is turned back on.">
                <input type="checkbox" id="mmi-dupes-master-toggle" <?php checked( $mmi_duplicate_products_enabled ); ?>>
                <span class="mmi-toggle-slider"></span>
            </label>
            <span class="mmi-section-master-toggle-label">Enabled</span>
        </div>
    </div>

    <div class="mmi-section-content">

        <div class="mmi-section-disabled-notice<?php echo $mmi_duplicate_products_enabled ? ' mmi-hidden' : ''; ?>" id="mmi-dupes-disabled-notice">
            <span class="dashicons dashicons-warning"></span>
            Duplicate Products scanning is disabled. Turn it back on above to scan supplier feeds for duplicate candidates.
        </div>

        <div class="mmi-dupes-wrap<?php echo $mmi_duplicate_products_enabled ? '' : ' mmi-hidden'; ?>" id="mmi-dupes-app">

            <!-- Toolbar -->
            <div class="mmi-toolbar">
                <label class="mmi-dupes-detection-label" for="mmi-dupes-detection-mode">Detection:</label>
                <select id="mmi-dupes-detection-mode" class="mmi-dupes-detection-select">
                    <option value="candidates">Candidates (same item, different vendor)</option>
                    <option value="collisions">Confirmed Collisions (same tracking key)</option>
                </select>
                <button class="button mmi-dupes-refresh-btn mmi-action-btn" id="mmi-dupes-refresh-btn"
                        title="Re-scan supplier feeds for duplicate candidates">
                    <span class="dashicons dashicons-update"></span>
                    Reload
                </button>

                <!-- Stats bar (status filter) — right-justified next to the Reload button -->
                <div class="mmi-dupes-stats mmi-hidden" id="mmi-dupes-stats">
                    <button type="button" class="mmi-dupes-stat is-active" data-status-filter="all">
                        Total groups: <strong id="mmi-dupes-total">0</strong>
                    </button>
                    <button type="button" class="mmi-dupes-stat mmi-dupes-stat-new" data-status-filter="new">
                        Needs review: <strong id="mmi-dupes-new-count">0</strong>
                    </button>
                    <button type="button" class="mmi-dupes-stat mmi-dupes-stat-linked" data-status-filter="linked">
                        Already linked: <strong id="mmi-dupes-linked-count">0</strong>
                    </button>
                </div>
            </div>

            <div class="mmi-filter-row mmi-filter-row--categories mmi-hidden" id="mmi-dupes-brand-filter-row">
                <span class="mmi-filter-label mmi-filter-row-label">Brand</span>
                <div id="mmi-dupes-brand-filters" class="mmi-taxonomy-filters-inline"></div>
            </div>

            <!-- Notice area -->
            <div id="mmi-dupes-notice" class="mmi-dupes-notice mmi-hidden"></div>

            <!-- Candidates table -->
            <div id="mmi-dupes-table-wrap">
                <table class="mmi-dupes-table mmi-hidden" id="mmi-dupes-table">
                    <thead>
                        <tr>
                            <th class="col-arrow"></th>
                            <th class="col-brand sortable" data-col="brand" data-resize-col="brand">
                                Brand <span class="sort-icon"></span>
                                <span class="mmi-dupes-th-resize" data-resize-col="brand"></span>
                            </th>
                            <th class="col-name sortable sort-active" data-col="name" data-resize-col="name">
                                Product <span class="sort-icon"> ↑</span>
                                <span class="mmi-dupes-th-resize" data-resize-col="name"></span>
                            </th>
                            <th class="col-members sortable" data-col="members" data-resize-col="members">
                                Members <span class="sort-icon"></span>
                                <span class="mmi-dupes-th-resize" data-resize-col="members"></span>
                            </th>
                            <th class="col-spread sortable" data-col="price_spread" data-resize-col="spread">
                                COG SPREAD <span class="sort-icon"></span>
                                <span class="mmi-dupes-th-resize" data-resize-col="spread"></span>
                            </th>
                            <th class="col-source sortable" data-col="source" data-resize-col="source" title="Legacy link = a real, pre-existing canonical/associated_product_ids link found in the database. Fresh match = detected just now by matching brand + product name across live supplier feeds.">
                                Source <span class="sort-icon"></span>
                                <span class="mmi-dupes-th-resize" data-resize-col="source"></span>
                            </th>
                            <th class="col-status sortable" data-col="status" data-resize-col="status">
                                Status <span class="sort-icon"></span>
                                <span class="mmi-dupes-th-resize" data-resize-col="status"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="mmi-dupes-tbody">
                        <!-- Rows injected by JS -->
                    </tbody>
                </table>

                <div class="mmi-dupes-empty mmi-hidden" id="mmi-dupes-empty">
                    <span class="dashicons dashicons-yes-alt"></span>
                    No duplicate groups found — neither fresh feed matches nor existing database links.
                </div>

                <div class="mmi-dupes-loading mmi-hidden" id="mmi-dupes-loading">
                    <span class="spinner is-active"></span>
                    Scanning supplier feeds and existing links for duplicates…
                </div>

                <!-- Confirmed Collisions — a different detection mode, see
                     panel-catalog-maintenance's own mmi-dupes-description-collisions
                     text above. Deliberately a separate, simpler table rather than
                     reusing #mmi-dupes-table's candidate-specific columns/sort/
                     resize machinery — a real collision group has no brand/price-
                     spread concept, and this table is expected to stay small
                     (well under the ~15-row threshold that would otherwise call
                     for sort/resize per AGENTS.md's Data Tables section). -->
                <table class="mmi-dupes-collision-table mmi-hidden" id="mmi-dupes-collision-table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Tracking Key</th>
                            <th>Products</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="mmi-dupes-collision-tbody">
                        <!-- Rows injected by JS -->
                    </tbody>
                </table>

                <div class="mmi-dupes-empty mmi-hidden" id="mmi-dupes-collision-empty">
                    <span class="dashicons dashicons-yes-alt"></span>
                    No confirmed key collisions found across the connected supplier feeds.
                </div>
            </div>

        </div><!-- .mmi-dupes-wrap -->

    </div><!-- /.mmi-section-content -->
</div><!-- /#mmi-duplicate-products-section -->
