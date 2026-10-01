<?php
/**
 * Export Preview Table Container
 *
 * Lives in Step 1's right-hand column (see tab-export.php's
 * .mmi-scope-layout) next to the filter fields it previews, so a filter
 * change is immediately visible beside the control that caused it. Reads
 * directly from the DB via the selected data type's handler (no diffing,
 * nothing is written yet). Table body is rendered client-side by
 * export-preview.js from the mmi_generate_export_preview AJAX response.
 *
 * Loads once automatically on page ready (a single request — within
 * CLAUDE.md's "max 2 simultaneous requests on page/panel open" budget, not
 * a violation of it) so opening or creating a profile shows real data
 * immediately, no click required. After that, a
 * `mmi:pipeline-export-preview-stale` event — fired by export-settings.js
 * on a scope-filter/Data Type change, or by export-preview.js itself after
 * a Columns-popover field-mapping save — triggers a debounced refresh. The
 * manual button stays for a forced refresh.
 *
 * Only the record's identifier column (`id_field` in the AJAX response) is
 * shown by default — every other column is opt-in via the "Columns" button,
 * same interaction pattern as mmi-reverb-integration's
 * #mmi-column-customize-btn on tab=products (the mmi-column-customize and
 * mmi-fcp classes now live in mmi-suite-common.css since both plugins use
 * them). Because the export preview's column set is dynamic per data type
 * (unlike reverb's fixed product columns), the checkbox list itself is built
 * by export-preview.js once the first batch's columns are known, not
 * hardcoded here. That popover is also where a field's actual export
 * inclusion, output-column name, and value transform are edited — what used
 * to be a separate "Step 2: Field Mapping" table (see export-preview.js's
 * top-of-file docblock for why that table was folded in here instead).
 *
 * There is no pagination — the table infinite-scrolls, fetching the next
 * "Rows per page" batch as the user nears the bottom of the scroll
 * container, until every matching record has been loaded.
 *
 * The "no data yet" / "no records match" state is a plain <p> sibling of
 * the <table>, not a <tr><td> inside it — #mmi-export-preview-table uses
 * table-layout:fixed for the drag-to-resize columns (export.css), which
 * renders a single placeholder cell in an otherwise header-less table
 * strangely (a collapsed/misaligned box). export-preview.js toggles which
 * of the two is visible instead.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="mmi-export-preview-wrap" id="mmi-export-preview-wrap">
    <div class="mmi-preview-toolbar">
        <button type="button" class="button mmi-action-btn" id="mmi-load-export-preview">
            <span class="dashicons dashicons-visibility"></span> Load Preview
        </button>
        <label class="mmi-preview-page-size-label">
            Rows per page
            <select id="mmi-export-preview-page-size">
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="200" selected>200</option>
                <option value="500">500</option>
            </select>
        </label>
        <div class="mmi-column-customize-wrap" id="mmi-export-preview-column-customize-wrap">
            <button type="button" id="mmi-export-preview-column-customize-btn" class="button mmi-column-customize-btn" title="Choose, reorder, and rename the fields this profile exports">
                <span class="dashicons dashicons-list-view"></span> Columns
            </button>
            <div class="mmi-column-customize-panel" id="mmi-export-preview-column-customize-panel" hidden>
                <div class="mmi-fcp-header">
                    <span class="mmi-fcp-title">Export Columns</span>
                    <button type="button" id="mmi-export-preview-ccp-close" class="mmi-fcp-close" title="Close">×</button>
                </div>
                <div class="mmi-fcp-body" id="mmi-export-preview-ccp-body"></div>
                <div class="mmi-fcp-footer">
                    <button type="button" id="mmi-export-preview-ccp-select-all" class="button button-small">Select All</button>
                    <button type="button" id="mmi-export-preview-ccp-clear-all" class="button button-small">Clear All</button>
                </div>
            </div>
        </div>
        <span class="mmi-preview-summary" id="mmi-export-preview-summary"></span>
        <span class="mmi-preview-stale-note mmi-hidden" id="mmi-export-preview-stale-note">
            <span class="mmi-loading"></span> Refreshing…
        </span>
    </div>
    <p class="mmi-empty-state" id="mmi-export-preview-empty">
        <span class="mmi-loading"></span> Loading preview…
    </p>
    <div class="mmi-preview-table-scroll mmi-hidden" id="mmi-export-preview-table-scroll">
        <table class="mmi-uniform-table" id="mmi-export-preview-table">
            <thead><tr id="mmi-export-preview-head"></tr></thead>
            <tbody id="mmi-export-preview-body"></tbody>
        </table>
        <p class="mmi-preview-scroll-sentinel mmi-hidden" id="mmi-export-preview-scroll-sentinel">
            <span class="mmi-loading"></span> Loading more…
        </p>
    </div>
</div>
