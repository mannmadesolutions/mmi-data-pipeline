<?php
/**
 * Catalog Maintenance Section
 *
 * Merges what used to be three separate UIs — the Stock Overrides panel, the
 * Catalog Rules panel, and the "Update Store Catalog" button — into one
 * section, styled like Import Profiles: a .mmi-profile-section-bar with the
 * run action, and a .mmi-uniform-table listing every operation the catalog
 * update performs, in the order it actually runs them.
 *
 * All execution goes through MannMade\DataPipeline\Catalog\Catalog_Phase_Runner
 * — the same class the scheduled cron and `wp mmi catalog` use — so this row
 * order IS the real execution order, not a separate description of it that
 * could drift out of sync.
 *
 * Expects from the parent scope (tab-pipeline.php): $configured_suppliers,
 * $mmi_catalog_rules, $mmi_catalog_rule_count, $mmi_stock_override_rules,
 * $mmi_catalog_last_run, $mmi_catalog_last_dur, $mmi_catalog_last_count,
 * $mmi_catalog_is_locked, $mmi_catalog_maintenance_enabled.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// All 9 are always set by tab-pipeline.php (lines 122-258) before it
// includes this partial (its only caller) — guarded here anyway so this
// file is self-consistent for static analysis and safe if ever included
// elsewhere without them.
$configured_suppliers            = $configured_suppliers            ?? [];
$mmi_catalog_rules               = $mmi_catalog_rules               ?? [];
$mmi_catalog_rule_count          = $mmi_catalog_rule_count          ?? 0;
$mmi_stock_override_rules        = $mmi_stock_override_rules        ?? [];
$mmi_catalog_last_run            = $mmi_catalog_last_run            ?? '';
$mmi_catalog_last_dur            = $mmi_catalog_last_dur            ?? '';
$mmi_catalog_last_count          = $mmi_catalog_last_count          ?? 0;
$mmi_catalog_is_locked           = $mmi_catalog_is_locked           ?? false;
$mmi_catalog_maintenance_enabled = $mmi_catalog_maintenance_enabled ?? false;

$mmi_cog_phases = \MannMade\DataPipeline\Catalog\Catalog_Phase_Runner::build_phases();

// Is the schedule actually keeping up? Drives the header alert, the last-run badge color and the notice.
$mmi_cog_health = \MannMade\DataPipeline\Catalog\Catalog_Phase_Runner::health( $mmi_cog_phases );
$mmi_cog_health_colors = [ 'ok' => '69, 133, 44', 'disabled' => '100, 100, 100', 'warning' => '184, 114, 10', 'error' => '214, 54, 56' ];
$mmi_cog_badge_color   = $mmi_cog_health_colors[ $mmi_cog_health['level'] ] ?? $mmi_cog_health_colors['ok'];

// Conditions are the suite-wide condition builder (MMI_Condition_Builder,
// shared library); action pickers are shared with Product Workbench
// (Rule_Builder_View + assets/js/rule-builder.js). Match logic stays in the
// rule's header row (Scope cell), so the builder renders without its own.
$mmi_cog_override_sources      = \MannMade\DataPipeline\Rule_Builder_View::sources( $configured_suppliers );
$mmi_cog_render_action_options = [ \MannMade\DataPipeline\Rule_Builder_View::class, 'action_options' ];
$mmi_cog_render_action_params  = [ \MannMade\DataPipeline\Rule_Builder_View::class, 'action_params' ];
$mmi_cog_source_groups         = \MannMade\DataPipeline\Rule_Builder_View::source_groups( $mmi_cog_override_sources );
$mmi_cog_render_conditions     = static function ( array $conditions, string $context ) use ( $mmi_cog_source_groups ): void {
    MMI_Condition_Builder::render( [
        'sources'      => $mmi_cog_source_groups,
        'conditions'   => $conditions,
        'context'      => $context,
        'empty_hint'   => 'No conditions yet — this rule applies to every product from the selected supplier.',
        'actions_html' => '<button type="button" class="mmi-cb-btn-secondary mmi-show-breakdown-btn" title="'
            . esc_attr( 'Preview which real products match each condition on its own, before they\'re combined with the All/Any logic above — useful for checking one condition at a time.' )
            . '">Show Per-Condition Matches</button>',
    ] );
};

$mmi_cog_last_run_label = 'Never run';
if ( $mmi_catalog_last_run ) {
    $mmi_cog_last_run_label = human_time_diff( strtotime( $mmi_catalog_last_run ), current_time( 'timestamp' ) ) . ' ago';
    if ( $mmi_catalog_last_count ) {
        // "Operations," not "products" — $mmi_catalog_last_count is
        // Catalog_Phase_Runner::finalize()'s total_processed, a SUM of every
        // non-skipped phase's own items_processed count (cleanup rows +
        // canonical-rule products + feed_sync in/out-of-stock flips + custom
        // rule changes + category/brand assignments). A single product
        // touched by more than one phase in the same run is counted once per
        // phase, so this number is not, and was never meant to be, a count
        // of distinct products — it can and routinely does exceed the
        // catalog's real product count.
        $mmi_cog_last_run_label .= ' · ' . number_format( (int) $mmi_catalog_last_count ) . ' operation' . ( (int) $mmi_catalog_last_count === 1 ? '' : 's' );
    }
    if ( $mmi_catalog_last_dur ) {
        $mmi_cog_last_run_label .= ' · ' . $mmi_catalog_last_dur;
    }
}

// Every phase this section runs (cleanup, canonical, feed_sync,
// override_stock, categories, brands) and the Custom Rule builder are
// WooCommerce-product-specific — this whole section is inert, not just
// "disabled," on a site with no WooCommerce (e.g. this plugin used for a
// Posts/taxonomy/order-only Import profile — see MMI_Data_Type_Registry).
// Checked separately from $mmi_catalog_maintenance_enabled (the user-facing
// on/off setting) so the notice/toggle below can tell the two apart rather
// than showing a "turn it back on" message that wouldn't actually do
// anything while WooCommerce is inactive.
$mmi_wc_active = MMI_Pipeline_Admin::is_woocommerce_active();

$mmi_cog_run_disabled_reason = '';
if ( ! $mmi_wc_active ) {
    $mmi_cog_run_disabled_reason = 'Catalog Maintenance requires WooCommerce, which is not currently active on this site';
} elseif ( ! $mmi_catalog_maintenance_enabled ) {
    $mmi_cog_run_disabled_reason = 'Catalog Maintenance is disabled — turn it back on above to run';
} elseif ( $mmi_catalog_is_locked ) {
    $mmi_cog_run_disabled_reason = 'A catalog update is already running';
}
?>

<?php
/*
 * Starts collapsed: a browser performance recording of the Import tab found
 * this section firing several concurrent AJAX calls (mmi_get_custom_rule_
 * source_fields, mmi_preview_custom_rule_match_count — one round trip per
 * condition/rule) on every single page load regardless of whether it was
 * ever opened, contributing to a documented multi-AJAX-fan-out slow-load
 * (see AGENTS.md's Server Load & PHP-FPM Impact section). Matches the same
 * collapsed-by-default + load-on-expand treatment already used for
 * Duplicate Products and Import History on this same tab — see
 * import-pipeline-catalog-maintenance.js's window.MMICatalogMaintenance.
 */
?>
<div class="mmi-profile-section mmi-process-section mmi-collapsible-section collapsed" id="mmi-catalog-maintenance-section" data-section="catalog-maintenance">

    <div class="mmi-section-header mmi-process-section-header-row mmi-section-clickable">
        <div>
            <h3 class="mmi-process-section-header">
                <span class="dashicons dashicons-admin-tools"></span>
                Catalog Maintenance
                <span class="mmi-collapse-toggle">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </span>
                <?php if ( in_array( $mmi_cog_health['level'], [ 'warning', 'error' ], true ) ) : ?>
                    <span class="mmi-badge <?php echo esc_attr( $mmi_cog_health['level'] ); ?> mmi-cog-health-alert" title="<?php echo esc_attr( $mmi_cog_health['detail'] ); ?>"><?php echo esc_html( $mmi_cog_health['text'] ); ?></span>
                <?php endif; ?>
            </h3>
            <p class="mmi-process-section-description">Bulk stock, category, and custom rules applied to the whole catalog — runs in the order shown below, manually or on its schedule (Schedules panel above)</p>
        </div>
        <div class="mmi-section-header-actions" onclick="event.stopPropagation()">
            <label class="mmi-toggle-switch mmi-section-master-toggle" title="<?php echo $mmi_wc_active ? 'Turn off to disable Catalog Maintenance entirely — no scheduled runs, and &quot;Update Store Catalog&quot; is disabled until this is turned back on.' : 'Catalog Maintenance requires WooCommerce, which is not currently active on this site.'; ?>">
                <input type="checkbox" id="mmi-catalog-maintenance-master-toggle" <?php checked( $mmi_catalog_maintenance_enabled ); ?> <?php disabled( ! $mmi_wc_active ); ?>>
                <span class="mmi-toggle-slider"></span>
            </label>
            <span class="mmi-section-master-toggle-label"><?php echo $mmi_wc_active ? 'Enabled' : 'Requires WooCommerce'; ?></span>
        </div>
    </div>

    <div class="mmi-section-content">

        <div class="mmi-section-disabled-notice<?php echo ( $mmi_wc_active && $mmi_catalog_maintenance_enabled ) ? ' mmi-hidden' : ''; ?>" id="mmi-cog-disabled-notice">
            <span class="dashicons dashicons-warning"></span>
            <?php if ( ! $mmi_wc_active ) : ?>
                Catalog Maintenance requires WooCommerce. This section is inactive — every operation below (stock, category, brand, and custom rules) applies to WooCommerce products, none of which exist without it.
            <?php else : ?>
                Catalog Maintenance is disabled. Scheduled runs are paused and "Update Store Catalog" won't run until this is turned back on above.
            <?php endif; ?>
        </div>

        <?php if ( 'ok' !== $mmi_cog_health['level'] && $mmi_catalog_maintenance_enabled && $mmi_wc_active ) : ?>
        <div class="mmi-section-disabled-notice mmi-cog-health-notice mmi-cog-health-notice--<?php echo esc_attr( $mmi_cog_health['level'] ); ?>" id="mmi-cog-health-notice">
            <span class="dashicons dashicons-warning"></span>
            <?php echo esc_html( $mmi_cog_health['detail'] ); ?>
        </div>
        <?php endif; ?>

        <div class="mmi-profile-section-bar">
            <div class="mmi-psb-identity">
                <span class="mmi-psb-name">Catalog Maintenance</span>
                <span class="mmi-pgc-mode-badge mmi-psb-mode-badge" id="mmi-cog-last-run-badge" style="--badge-raw-color:<?php echo esc_attr( $mmi_cog_badge_color ); ?>" title="<?php echo esc_attr( $mmi_cog_health['detail'] ); ?>">
                    <?php echo esc_html( $mmi_cog_last_run_label . ( $mmi_cog_health['text'] !== '' ? ' · ' . $mmi_cog_health['text'] : '' ) ); ?>
                </span>
            </div>
            <div class="mmi-psb-actions">
                <button type="button" class="mmi-btn-profile mmi-btn-profile--primary" id="run-catalog-update" <?php echo $mmi_cog_run_disabled_reason ? 'disabled title="' . esc_attr( $mmi_cog_run_disabled_reason ) . '"' : ''; ?>>
                    <span class="dashicons dashicons-admin-tools"></span> Update Store Catalog
                    <span class="mmi-catalog-run-indicator<?php echo $mmi_catalog_rule_count > 0 ? '' : ' mmi-hidden'; ?>" id="mmi-catalog-run-indicator"><?php echo esc_html( $mmi_catalog_rule_count ); ?> rule<?php echo $mmi_catalog_rule_count === 1 ? '' : 's'; ?></span>
                </button>
                <span class="mmi-psb-divider" aria-hidden="true"></span>
                <button type="button" class="mmi-btn-profile" id="mmi-cog-add-custom-rule" <?php echo $mmi_wc_active ? '' : 'disabled title="Requires WooCommerce"'; ?>>
                    <span class="dashicons dashicons-plus-alt2"></span> Custom Rule
                </button>
                <button type="button" class="mmi-btn-profile mmi-btn-profile--danger" id="mmi-cog-delete-rule" disabled title="Select a custom rule row to delete">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
        </div><!-- /.mmi-profile-section-bar -->

        <table class="mmi-catalog-ops-grid mmi-uniform-table mmi-uniform-table--hoverable">
            <thead>
                <tr>
                    <th class="mmi-cog-col-name">Operation</th>
                    <th class="mmi-cog-col-type mmi-cog-col-type--sortable" id="mmi-cog-type-sort" role="button" tabindex="0" title="Group rows by Type (click again to restore run order)">
                        Type <span class="dashicons dashicons-sort mmi-cog-sort-icon"></span>
                    </th>
                    <th class="mmi-cog-col-scope">Scope</th>
                    <th class="mmi-cog-col-status">Status</th>
                </tr>
            </thead>
            <tbody id="mmi-catalog-ops-tbody">

            <?php foreach ( $mmi_cog_phases as $mmi_cog_phase ) :
                if ( $mmi_cog_phase['phase'] === 'feed_sync' ) :
                    $mmi_cog_supplier = $mmi_cog_phase['args']['supplier'] ?? '';
                    ?>
                    <tr class="mmi-cog-row mmi-cog-row--fixed" data-phase="feed_sync" data-supplier="<?php echo esc_attr( $mmi_cog_supplier ); ?>">
                        <td class="mmi-cog-col-name">
                            <span class="mmi-pgc-name"><?php echo esc_html( $mmi_cog_phase['label'] ); ?></span>
                            <span class="mmi-catalog-toggle-desc">Marks products out of stock when their SKU drops out of this supplier's feed, and restores them when it reappears.</span>
                        </td>
                        <td class="mmi-cog-col-type"><span class="mmi-pgc-mode-badge" style="--badge-raw-color:10, 92, 138">Feed Sync</span></td>
                        <td class="mmi-cog-col-scope">
                            <span class="mmi-pgc-source-pill"><?php echo esc_html( ucfirst( $mmi_cog_supplier ) ); ?></span>
                        </td>
                        <td class="mmi-cog-col-status">
                            <?php if ( $mmi_cog_phase['skipped'] ) : ?>
                                <span class="mmi-cog-skip-reason" title="<?php echo esc_attr( $mmi_cog_phase['skip_reason'] ); ?>">Skipped — <?php echo esc_html( $mmi_cog_phase['skip_reason'] ); ?></span>
                            <?php else : ?>
                                <span class="mmi-cog-active-indicator">Active</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                elseif ( in_array( $mmi_cog_phase['phase'], [ 'cleanup', 'canonical', 'categories', 'brands' ], true ) ) :
                    $mmi_cog_rule_key_map = [
                        'cleanup'    => 'cleanup_stock_meta',
                        'canonical'  => 'enforce_canonical_rules',
                        'categories' => 'auto_assign_categories',
                        'brands'     => 'auto_assign_brands',
                    ];
                    $mmi_cog_rule_key = $mmi_cog_rule_key_map[ $mmi_cog_phase['phase'] ];
                    $mmi_cog_descriptions = [
                        'cleanup'    => 'Removes NULL and duplicate _stock_status / _stock DB rows that cause incorrect stock readings.',
                        'canonical'  => 'Marks products with canonical = FALSE as out of stock and ensures canonical groups are consistent.',
                        'categories' => 'Scans uncategorized products and assigns WooCommerce categories based on product names and taxonomies.',
                        'brands'     => 'Matches unbranded products to brand taxonomy terms using SKU prefix patterns. Only assigns — never removes existing brands.',
                    ];
                    ?>
                    <tr class="mmi-cog-row mmi-cog-row--fixed" data-phase="<?php echo esc_attr( $mmi_cog_phase['phase'] ); ?>">
                        <td class="mmi-cog-col-name">
                            <span class="mmi-pgc-name"><?php echo esc_html( $mmi_cog_phase['label'] ); ?></span>
                            <span class="mmi-catalog-toggle-desc"><?php echo esc_html( $mmi_cog_descriptions[ $mmi_cog_phase['phase'] ] ); ?></span>
                        </td>
                        <td class="mmi-cog-col-type"><span class="mmi-pgc-mode-badge" style="--badge-raw-color:100, 100, 100">Maintenance</span></td>
                        <td class="mmi-cog-col-scope"><span class="mmi-pgc-no-sources">All products</span></td>
                        <td class="mmi-cog-col-status">
                            <div class="mmi-cog-status-toggle">
                                <label class="mmi-toggle-switch" title="Enable or disable this operation">
                                    <input type="checkbox" class="mmi-catalog-toggle" data-rule="<?php echo esc_attr( $mmi_cog_rule_key ); ?>" <?php checked( $mmi_catalog_rules[ $mmi_cog_rule_key ] ); ?>>
                                    <span class="mmi-toggle-slider"></span>
                                </label>
                                <span class="mmi-cog-toggle-status-label">Enabled</span>
                            </div>
                        </td>
                    </tr>
                    <?php
                endif;
            endforeach; ?>

            <?php
            foreach ( $mmi_stock_override_rules as $mmi_cog_oi => $mmi_cog_orule ) :
                $mmi_cog_orule_norm = \MannMade\DataPipeline\Stock_Override_Resolver::normalize_rule( (array) $mmi_cog_orule );
                $mmi_cog_osupplier  = esc_attr( $mmi_cog_orule_norm['supplier']     ?? 'all' );
                $mmi_cog_omatch     = esc_attr( $mmi_cog_orule_norm['match_logic'] ?? 'all' );
                $mmi_cog_oaction    = esc_attr( $mmi_cog_orule_norm['action']      ?? '' );
                $mmi_cog_oparams    = is_array( $mmi_cog_orule_norm['action_params'] ?? null ) ? $mmi_cog_orule_norm['action_params'] : [];
                $mmi_cog_oconds     = is_array( $mmi_cog_orule_norm['conditions'] ?? null ) ? $mmi_cog_orule_norm['conditions'] : [];
                $mmi_cog_oname      = esc_attr( $mmi_cog_orule_norm['name'] ?? '' );
                $mmi_cog_oenabled   = ! isset( $mmi_cog_orule_norm['enabled'] ) || (bool) $mmi_cog_orule_norm['enabled'];
            ?>
                <tr class="mmi-cog-row mmi-cog-row--custom-rule mmi-uniform-row--selectable<?php echo $mmi_cog_oenabled ? '' : ' mmi-cog-rule-disabled'; ?>" data-phase="override_stock" data-index="<?php echo (int) $mmi_cog_oi; ?>" role="button" tabindex="0" aria-pressed="false">
                    <td class="mmi-cog-col-name">
                        <input type="text" class="mmi-cog-rule-name-input" value="<?php echo $mmi_cog_oname; ?>" placeholder="Custom Rule" maxlength="80" aria-label="Rule name">
                        <button type="button" class="mmi-cog-expand-toggle" title="Edit conditions">
                            <span class="dashicons dashicons-arrow-down-alt2"></span> <span class="mmi-cog-cond-count"><?php echo count( $mmi_cog_oconds ); ?> condition<?php echo count( $mmi_cog_oconds ) === 1 ? '' : 's'; ?></span>
                        </button>
                    </td>
                    <td class="mmi-cog-col-type">
                        <select class="mmi-custom-rule-action">
                            <?php $mmi_cog_render_action_options( $mmi_cog_oaction ); ?>
                        </select>
                        <div class="mmi-crb-section-head mmi-hidden">
                            <span class="mmi-crb-step">1</span> What happens
                            <span class="dashicons dashicons-editor-help mmi-info-icon" tabindex="0"
                                  title="Configured by the action chosen above (e.g. Set Taxonomy Term, Set Sale Price). Some actions, like Force Out of Stock, need no extra settings here."></span>
                        </div>
                    </td>
                    <td class="mmi-cog-col-scope">
                        <div class="mmi-cog-scope-inner">
                            <select class="mmi-custom-rule-supplier">
                                <option value="all" <?php selected( $mmi_cog_osupplier, 'all' ); ?>>All Suppliers</option>
                                <?php foreach ( $configured_suppliers as $mmi_cog_sid => $mmi_cog_sinfo ) : ?>
                                    <option value="<?php echo esc_attr( $mmi_cog_sid ); ?>" <?php selected( $mmi_cog_osupplier, $mmi_cog_sid ); ?>>
                                        <?php echo esc_html( $mmi_cog_sinfo['supplier_name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="native" <?php selected( $mmi_cog_osupplier, 'native' ); ?>>Live App Data (No Supplier)</option>
                            </select>
                            <select class="mmi-custom-rule-match-logic">
                                <option value="all" <?php selected( $mmi_cog_omatch, 'all' ); ?>>All conditions</option>
                                <option value="any" <?php selected( $mmi_cog_omatch, 'any' ); ?>>Any condition</option>
                            </select>
                        </div>
                        <div class="mmi-crb-section-head mmi-hidden">
                            <span class="mmi-crb-step">2</span> Which products
                            <span class="dashicons dashicons-editor-help mmi-info-icon" tabindex="0"
                                  title="A product must satisfy the match logic selected above (All conditions / Any condition) to be affected. &quot;Source Data&quot; checks the raw value from the supplier's feed, before import; &quot;WP/WC Data&quot; checks the value already live on the WordPress product (its taxonomy terms, post fields, or meta)."></span>
                        </div>
                    </td>
                    <td class="mmi-cog-col-status">
                        <div class="mmi-cog-status-toggle">
                            <label class="mmi-toggle-switch" title="Enable or disable this rule">
                                <input type="checkbox" class="mmi-cog-rule-enabled-toggle" <?php checked( $mmi_cog_oenabled ); ?>>
                                <span class="mmi-toggle-slider"></span>
                            </label>
                            <span class="mmi-cog-toggle-status-label">Enabled</span>
                        </div>
                        <span class="mmi-rule-compliance"><span class="mmi-cpl-loading">&#8230;</span></span>
                    </td>
                </tr>
                <tr class="mmi-cog-detail-row mmi-hidden">
                    <td colspan="4">
                        <div class="mmi-rule-apply-status"></div>
                        <div class="mmi-rule-conditions-body">
                            <div class="mmi-crb-section mmi-crb-section--params">
                                <div class="mmi-custom-rule-params">
                                    <?php $mmi_cog_render_action_params( $mmi_cog_oparams ); ?>
                                </div>
                            </div>
                            <div class="mmi-crb-section mmi-crb-section--conditions">
                                <?php $mmi_cog_render_conditions( $mmi_cog_oconds, 'cm:' . (int) $mmi_cog_oi ); ?>
                                <div class="mmi-cond-breakdown mmi-hidden"></div>
                            </div>
                        </div><!-- /.mmi-rule-conditions-body -->
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ( empty( $mmi_stock_override_rules ) && empty( array_filter( $mmi_cog_phases, static fn( $p ) => ! $p['skipped'] ) ) ) : ?>
                <tr>
                    <td class="mmi-pgc-empty-state" colspan="4">
                        <p>No catalog operations configured.</p>
                    </td>
                </tr>
            <?php endif; ?>

            </tbody>
        </table><!-- /.mmi-catalog-ops-grid -->

        <div id="mmi-catalog-progress-panel" class="mmi-catalog-progress-panel--collapsed">
            <div class="mmi-catalog-progress-header">
                <span class="mmi-loading mmi-catalog-progress-spinner"></span>
                <span id="mmi-catalog-progress-title">Running Catalog Update…</span>
            </div>
            <div id="mmi-catalog-progress-phases" class="mmi-catalog-progress-phases"></div>
        </div><!-- /#mmi-catalog-progress-panel -->

        <div class="mmi-panel-footer-actions">
            <button type="button" class="button button-primary mmi-action-btn mmi-is-loading-capable" id="mmi-save-catalog-rules">
                <span class="dashicons dashicons-yes"></span> Save Rules
            </button>
            <button type="button" class="button button-secondary mmi-action-btn mmi-is-loading-capable" id="mmi-apply-overrides-now" disabled
                    title="Select a custom rule row, then apply it to all catalog products now. Rules with multiple conditions require an import run for full compliance.">
                <span class="dashicons dashicons-update"></span> Apply Selected Rule Now
            </button>
            <span class="mmi-catalog-rules-save-status" id="mmi-catalog-rules-save-status"></span>
        </div>

    </div><!-- /.mmi-section-content -->
</div><!-- /#mmi-catalog-maintenance-section -->

<!-- ── Match Review Modal (custom rule "Apply Now" results) ─────────── -->
<div id="mmi-match-review-modal" class="mmi-modal-backdrop" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-match-modal-title">
    <div class="mmi-match-modal-inner">
        <div class="mmi-match-modal-header">
            <h3 class="mmi-match-modal-title" id="mmi-match-modal-title">Matched Products</h3>
            <button type="button" class="mmi-match-modal-close" data-close aria-label="Close">&times;</button>
        </div>
        <div class="mmi-match-modal-body" id="mmi-match-modal-body">
            <div class="mmi-match-modal-loading">Loading&hellip;</div>
        </div>
        <div class="mmi-match-modal-footer">
            <span id="mmi-match-modal-info"></span>
            <div class="mmi-modal-pager">
                <button type="button" class="button" id="mmi-match-modal-prev" disabled>&#8592; Prev</button>
                <span id="mmi-match-modal-page-label"></span>
                <button type="button" class="button" id="mmi-match-modal-next" disabled>Next &#8594;</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Row templates (cloned by JS on "Custom Rule" / "Add Condition") ── -->
<template id="mmi-cog-custom-rule-row-template">
    <tr class="mmi-cog-row mmi-cog-row--custom-rule mmi-uniform-row--selectable" data-phase="override_stock" role="button" tabindex="0" aria-pressed="false">
        <td class="mmi-cog-col-name">
            <input type="text" class="mmi-cog-rule-name-input" value="" placeholder="Custom Rule" maxlength="80" aria-label="Rule name">
            <button type="button" class="mmi-cog-expand-toggle" title="Edit conditions">
                <span class="dashicons dashicons-arrow-down-alt2"></span> <span class="mmi-cog-cond-count">0 conditions</span>
            </button>
        </td>
        <td class="mmi-cog-col-type">
            <select class="mmi-custom-rule-action">
                <?php $mmi_cog_render_action_options(); ?>
            </select>
            <div class="mmi-crb-section-head">
                <span class="mmi-crb-step">1</span> What happens
                <span class="dashicons dashicons-editor-help mmi-info-icon" tabindex="0"
                      title="Configured by the action chosen above (e.g. Set Taxonomy Term, Set Sale Price). Some actions, like Force Out of Stock, need no extra settings here."></span>
            </div>
        </td>
        <td class="mmi-cog-col-scope">
            <div class="mmi-cog-scope-inner">
                <select class="mmi-custom-rule-supplier">
                    <option value="all">All Suppliers</option>
                    <?php foreach ( $configured_suppliers as $mmi_cog_sid => $mmi_cog_sinfo ) : ?>
                        <option value="<?php echo esc_attr( $mmi_cog_sid ); ?>"><?php echo esc_html( $mmi_cog_sinfo['supplier_name'] ); ?></option>
                    <?php endforeach; ?>
                    <option value="native">Live App Data (No Supplier)</option>
                </select>
                <select class="mmi-custom-rule-match-logic">
                    <option value="all">All conditions</option>
                    <option value="any">Any condition</option>
                </select>
            </div>
            <div class="mmi-crb-section-head">
                <span class="mmi-crb-step">2</span> Which products
                <span class="dashicons dashicons-editor-help mmi-info-icon" tabindex="0"
                      title="A product must satisfy the match logic selected above (All conditions / Any condition) to be affected. &quot;Source Data&quot; checks the raw value from the supplier's feed, before import; &quot;WP/WC Data&quot; checks the value already live on the WordPress product (its taxonomy terms, post fields, or meta)."></span>
            </div>
        </td>
        <td class="mmi-cog-col-status">
            <div class="mmi-cog-status-toggle">
                <label class="mmi-toggle-switch" title="Enable or disable this rule">
                    <input type="checkbox" class="mmi-cog-rule-enabled-toggle" checked>
                    <span class="mmi-toggle-slider"></span>
                </label>
                <span class="mmi-cog-toggle-status-label">Enabled</span>
            </div>
            <span class="mmi-rule-compliance"></span>
        </td>
    </tr>
    <tr class="mmi-cog-detail-row">
        <td colspan="4">
            <div class="mmi-rule-apply-status"></div>
            <div class="mmi-rule-conditions-body">
                <div class="mmi-crb-section mmi-crb-section--params">
                    <div class="mmi-custom-rule-params">
                        <?php $mmi_cog_render_action_params(); ?>
                    </div>
                </div>
                <div class="mmi-crb-section mmi-crb-section--conditions">
                    <?php $mmi_cog_render_conditions( [], '' ); ?>
                    <div class="mmi-cond-breakdown mmi-hidden"></div>
                </div>
            </div>
        </td>
    </tr>
</template>

