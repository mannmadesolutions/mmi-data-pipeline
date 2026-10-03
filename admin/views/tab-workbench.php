<?php
/**
 * Product Workbench tab
 *
 * Find products with the same condition builder Custom Rules use (plus a
 * quick title/SKU search and any post status), review how they're
 * categorized, then apply one change to all or some of them — with a
 * preview first and Undo after. Behavior: assets/js/product-workbench.js;
 * server side: MannMade\DataPipeline\Workbench\Product_Workbench.
 *
 * @package MannMade\DataPipeline
 */

use MannMade\DataPipeline\Rule_Builder_View;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$mmi_wb_sources   = Rule_Builder_View::sources( MMI_Pipeline_Admin::get_configured_suppliers() );
$mmi_wb_suppliers = MMI_Pipeline_Admin::get_configured_suppliers();
$mmi_wb_statuses  = [
    'publish' => 'Published',
    'draft'   => 'Draft',
    'pending' => 'Pending',
    'private' => 'Private',
];
$mmi_wb_stock = [
    'instock'     => 'In stock',
    'outofstock'  => 'Out of stock',
    'onbackorder' => 'Backorder',
];
$mmi_wb_checks = \MannMade\DataPipeline\Workbench\Health_Checks::all();

/**
 * Prints a shared .mmi-multiselect checkbox dropdown (behavior in
 * product-workbench.js). $before is already-escaped markup shown above the
 * options (e.g. the Health mode picker).
 *
 * @param string               $id      Wrapper id.
 * @param string               $label   Filter name, also the trigger text when nothing is ticked.
 * @param string               $class   Checkbox class the JS collects.
 * @param array<string,string> $options value => label.
 * @param string[]             $checked Values ticked on load.
 * @param string               $before  Markup above the options.
 */
$mmi_wb_multiselect = static function ( string $id, string $label, string $class, array $options, array $checked = [], string $before = '' ): void {
    ?>
    <div class="mmi-multiselect mmi-wb-filter" id="<?php echo esc_attr( $id ); ?>" data-label="<?php echo esc_attr( $label ); ?>">
        <button type="button" class="mmi-ms-trigger" aria-expanded="false" aria-haspopup="true">
            <span class="mmi-ms-label"><?php echo esc_html( $label ); ?></span>
            <span class="mmi-ms-badge" hidden></span>
            <span class="mmi-ms-caret" aria-hidden="true">▼</span>
        </button>
        <div class="mmi-ms-menu" hidden>
            <?php echo $before; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup built by the caller. ?>
            <?php foreach ( $options as $value => $text ) : ?>
                <label class="mmi-ms-option mmi-ms-option--check"><input type="checkbox" class="<?php echo esc_attr( $class ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $checked, true ) ); ?>> <span class="mmi-ms-opt-label"><?php echo esc_html( $text ); ?></span></label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
};

/**
 * Prints a section's header band.
 *
 * @param string $icon    Dashicon suffix.
 * @param string $title   Section title.
 * @param string $desc    One-line description.
 * @param string $actions Already-escaped button markup for the header's right side.
 */
$mmi_wb_section_head = static function ( string $icon, string $title, string $desc, string $actions = '' ): void {
    ?>
    <div class="mmi-section-header">
        <div>
            <h3 class="mmi-process-section-header"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span> <?php echo esc_html( $title ); ?></h3>
            <p class="mmi-process-section-description"><?php echo esc_html( $desc ); ?></p>
        </div>
        <?php if ( $actions !== '' ) : ?>
            <div class="mmi-wb-section-actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup built below. ?></div>
        <?php endif; ?>
    </div>
    <?php
};
?>
<div class="mmi-workbench" id="mmi-workbench">

    <?php if ( ! MMI_Pipeline_Admin::is_woocommerce_active() ) : ?>
        <div class="notice notice-warning inline"><p>Product Workbench works on WooCommerce products — activate WooCommerce to use it.</p></div>
    <?php else : ?>

    <!-- ── 1. Find products ─────────────────────────────────────────── -->
    <div class="mmi-process-section" id="mmi-wb-find">
        <?php
        $mmi_wb_section_head(
            'search',
            'Find products',
            'Search titles and SKUs, then narrow with the same conditions Custom Rules use — supplier feed fields, categories and other taxonomies, post fields and meta',
            '<button type="button" class="button" id="mmi-wb-reset"><span class="dashicons dashicons-dismiss"></span> Clear</button>'
            . '<button type="button" class="button button-primary" id="mmi-wb-search"><span class="dashicons dashicons-search"></span> Search</button>'
        );
        ?>
        <div class="mmi-section-content">
            <div class="mmi-toolbar mmi-wb-quick">
                <input type="search" id="mmi-wb-q" class="mmi-wb-q" placeholder="Search titles and SKUs… (e.g. bundle)" aria-label="Search products">
                <label class="mmi-cond-case" title="Match case — off: &quot;bundle&quot; also finds &quot;Bundle&quot; and &quot;BUNDLE&quot;">
                    <input type="checkbox" id="mmi-wb-q-case">Aa
                </label>
                <select id="mmi-wb-q-in" aria-label="Search in">
                    <option value="title_sku">in title or SKU</option>
                    <option value="title">in title only</option>
                    <option value="sku">in SKU only</option>
                </select>
                <span class="mmi-wb-divider" aria-hidden="true"></span>
                <select id="mmi-wb-supplier" aria-label="Supplier scope" title="Only products tracked under this supplier">
                    <option value="all">All suppliers</option>
                    <?php foreach ( $mmi_wb_suppliers as $mmi_wb_sid => $mmi_wb_sinfo ) : ?>
                        <option value="<?php echo esc_attr( $mmi_wb_sid ); ?>"><?php echo esc_html( $mmi_wb_sinfo['supplier_name'] ); ?></option>
                    <?php endforeach; ?>
                    <option value="native">No supplier (created here)</option>
                </select>
            </div>

            <div class="mmi-toolbar mmi-wb-filters" role="group" aria-label="Filters">
                <span class="mmi-wb-filters-label">Filter</span>
                <?php
                $mmi_wb_multiselect( 'mmi-wb-status-ms', 'Status', 'mmi-wb-status', $mmi_wb_statuses, [ 'publish', 'draft' ] );
                $mmi_wb_multiselect( 'mmi-wb-stock-ms', 'Stock', 'mmi-wb-stock', $mmi_wb_stock );
                $mmi_wb_multiselect(
                    'mmi-wb-health-ms',
                    'Health',
                    'mmi-wb-health',
                    array_map( static fn( $c ) => $c['label'], $mmi_wb_checks ),
                    [],
                    '<label class="mmi-wb-health-mode">Show products that '
                    . '<select id="mmi-wb-health-mode" aria-label="Health filter mode">'
                    . '<option value="any">fail any ticked check</option>'
                    . '<option value="all">fail every ticked check</option>'
                    . '<option value="none">pass every ticked check</option>'
                    . '</select></label>'
                );
                ?>
                <button type="button" class="button-link mmi-wb-filters-clear" id="mmi-wb-filters-clear">Clear filters</button>
            </div>

            <?php
            // The suite-wide condition builder (same as Catalog Maintenance
            // and Field Mapping), with its own match logic here.
            MMI_Condition_Builder::render( [
                'sources'     => Rule_Builder_View::source_groups( $mmi_wb_sources ),
                'match_logic' => 'all',
                'label'       => 'Conditions',
                'empty_hint'  => 'No conditions — the search uses only the fields above.',
                'context'     => 'workbench',
                'class'       => 'mmi-wb-conditions',
                'actions_html' => '<button type="button" class="mmi-cb-btn-secondary" id="mmi-wb-save-search" title="Keep these conditions as a named search, loadable from Load saved conditions here and in Catalog Maintenance"><span class="dashicons dashicons-saved"></span> Save search</button>'
                    . '<span class="mmi-wb-save-search-form mmi-hidden" id="mmi-wb-save-search-form">'
                    . '<input type="text" id="mmi-wb-save-search-name" placeholder="Name this search…" aria-label="Search name" maxlength="80">'
                    . '<button type="button" class="button button-small" id="mmi-wb-save-search-confirm">Save</button>'
                    . '<button type="button" class="button-link" id="mmi-wb-save-search-cancel">Cancel</button>'
                    . '</span>',
                'ids'         => [ 'root' => 'mmi-wb-cb', 'list' => 'mmi-wb-conditions', 'add' => 'mmi-wb-add-condition', 'match_logic' => 'mmi-wb-match-logic' ],
            ] );
            ?>
            <p class="mmi-wb-stale mmi-hidden" id="mmi-wb-stale"><span class="dashicons dashicons-info-outline"></span> The search has changed — click Search to update the results.</p>
        </div>
    </div>

    <!-- ── 2. Results ───────────────────────────────────────────────── -->
    <div class="mmi-process-section" id="mmi-wb-results-section">
        <?php $mmi_wb_section_head( 'list-view', 'Results', 'Tick the products to change, or select every match. Click a category below to narrow to it' ); ?>
        <div class="mmi-section-content">
            <div class="mmi-wb-empty" id="mmi-wb-empty">Run a search to see products here.</div>
            <div class="mmi-wb-results mmi-hidden" id="mmi-wb-results">
                <div class="mmi-wb-facets" id="mmi-wb-facets" aria-label="Categories in these results"></div>
                <div class="mmi-toolbar mmi-wb-selection">
                    <span class="mmi-wb-count" id="mmi-wb-count"></span>
                    <span class="mmi-wb-selected" id="mmi-wb-selected"></span>
                    <button type="button" class="button-link" id="mmi-wb-select-all-matching"></button>
                    <button type="button" class="button-link" id="mmi-wb-clear-selection">Clear selection</button>
                    <label class="mmi-pagination-size-label mmi-wb-per-page">Per page:
                        <select class="mmi-pagination-size" id="mmi-wb-per-page">
                            <?php foreach ( \MannMade\DataPipeline\Workbench\Product_Workbench::PER_PAGE_OPTIONS as $mmi_wb_size ) : ?>
                                <option value="<?php echo (int) $mmi_wb_size; ?>" <?php selected( 50, $mmi_wb_size ); ?>><?php echo (int) $mmi_wb_size; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="mmi-table-scroll-wrapper mmi-wb-table-wrap">
                    <table class="wp-list-table widefat striped mmi-data-table mmi-wb-table" id="mmi-wb-table">
                        <thead>
                            <tr>
                                <td class="check-column"><input type="checkbox" id="mmi-wb-select-page" aria-label="Select this page"></td>
                                <th data-col="id" data-resize-col="id" class="mmi-wb-col-id mmi-wb-sortable">ID <span class="sort-icon"></span></th>
                                <th class="mmi-wb-col-thumb" data-resize-col="thumb"><span class="screen-reader-text">Image</span></th>
                                <th data-col="title" data-resize-col="title" class="mmi-wb-col-title mmi-wb-sortable">Product <span class="sort-icon"></span></th>
                                <th data-col="sku" data-resize-col="sku" class="mmi-wb-col-sku mmi-wb-sortable">SKU <span class="sort-icon"></span></th>
                                <th data-col="categories" data-resize-col="categories" class="mmi-wb-col-cats mmi-wb-sortable">Categories <span class="sort-icon"></span></th>
                                <th data-col="brand" data-resize-col="brand" class="mmi-wb-col-brand mmi-wb-sortable">Brand <span class="sort-icon"></span></th>
                                <th data-col="status" data-resize-col="status" class="mmi-wb-col-status mmi-wb-sortable">Status <span class="sort-icon"></span></th>
                                <th data-col="stock" data-resize-col="stock" class="mmi-wb-col-stock mmi-wb-sortable">Stock <span class="sort-icon"></span></th>
                                <th data-col="supplier" data-resize-col="supplier" class="mmi-wb-col-supplier mmi-wb-sortable">Supplier <span class="sort-icon"></span></th>
                                <th data-col="health" data-resize-col="health" class="mmi-wb-col-health mmi-wb-sortable" title="Data-health checks this product fails. Sorting puts the most problems first (descending) or last">Health <span class="sort-icon"></span></th>
                            </tr>
                        </thead>
                        <tbody id="mmi-wb-rows"></tbody>
                    </table>
                </div>
                <div id="mmi-wb-pager"></div>
            </div>
        </div>
    </div>

    <!-- ── 3. Change selected products ──────────────────────────────── -->
    <div class="mmi-process-section" id="mmi-wb-change">
        <?php
        $mmi_wb_section_head(
            'edit',
            'Change selected products',
            'Any Custom Rule action, once. Replace swaps every existing term in that taxonomy for the one chosen — nothing of the old value is kept on the product',
            '<button type="button" class="button" id="mmi-wb-preview" disabled><span class="dashicons dashicons-visibility"></span> Preview</button>'
            . '<button type="button" class="button button-primary" id="mmi-wb-apply" disabled><span class="dashicons dashicons-yes"></span> Apply</button>'
        );
        ?>
        <div class="mmi-section-content">
            <div class="mmi-wb-action" id="mmi-wb-action-scope">
                <select class="mmi-custom-rule-action" id="mmi-wb-action" aria-label="What to change">
                    <?php Rule_Builder_View::action_options( 'set_taxonomy_term', true ); ?>
                </select>
                <div class="mmi-custom-rule-params">
                    <?php Rule_Builder_View::action_params( [ 'taxonomy' => 'product_cat', 'mode' => 'replace' ] ); ?>
                </div>
            </div>
            <p class="mmi-wb-target" id="mmi-wb-target">Nothing selected yet.</p>
            <div class="mmi-wb-progress mmi-hidden" id="mmi-wb-progress">
                <div class="mmi-progress-track"><div class="mmi-progress-fill" id="mmi-wb-progress-fill"></div></div>
                <span class="mmi-wb-progress-text" id="mmi-wb-progress-text"></span>
                <button type="button" class="button-link mmi-wb-stop" id="mmi-wb-stop">Stop</button>
            </div>
            <div class="mmi-wb-message" id="mmi-wb-message" role="status"></div>
            <div class="mmi-wb-preview mmi-hidden" id="mmi-wb-preview-out"></div>
        </div>
    </div>

    <!-- ── 4. Recent changes ────────────────────────────────────────── -->
    <div class="mmi-process-section" id="mmi-wb-history">
        <?php $mmi_wb_section_head( 'backup', 'Recent changes', 'Every Apply is recorded product by product. Undo restores each product\'s previous value — unless it has been edited again since, which is left alone' ); ?>
        <div class="mmi-section-content">
            <div class="mmi-table-scroll-wrapper">
                <table class="wp-list-table widefat striped mmi-wb-jobs-table" id="mmi-wb-jobs">
                    <thead>
                        <tr>
                            <th data-col="created_at" class="mmi-wb-job-sortable is-active is-desc" aria-sort="descending">When <span class="sort-icon"></span></th>
                            <th data-col="summary" class="mmi-wb-job-sortable">Change <span class="sort-icon"></span></th>
                            <th data-col="filter_label" class="mmi-wb-job-sortable">Products searched for <span class="sort-icon"></span></th>
                            <th data-col="changed" class="mmi-wb-job-sortable">Changed <span class="sort-icon"></span></th>
                            <th data-col="status" class="mmi-wb-job-sortable">Status <span class="sort-icon"></span></th>
                            <th><span class="screen-reader-text">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody id="mmi-wb-jobs-rows">
                        <tr><td colspan="6" class="mmi-wb-empty-cell">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
