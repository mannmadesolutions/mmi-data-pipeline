<?php
/**
 * Attribute & Variation Mapping — step content, reused inline as the unified
 * Import Profile wizard's Attributes step (see section-profile-wizard.php).
 * Previously wrapped in its own slide-out .mmi-config-panel; that chrome was
 * retired along with the standalone panel this session. Initial values come
 * from $current_profile at PHP render time (matching the page's active
 * profile); import-pipeline-attributes.js's loadConfigIntoUI() repopulates
 * these fields via AJAX when the wizard is edited for a different profile
 * without a page reload.
 *
 * Variables available from the including view (all set by tab-pipeline.php
 * before section-profile-wizard.php includes this file):
 *  - $current_profile (string)
 *  - $configured_suppliers (array)
 *  - $profile_assigned_sources (array)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Guarded here too (not just at the including view) so this file is
// self-consistent for static analysis and safe if ever included elsewhere
// without them.
if ( ! isset( $profile_assigned_sources ) || ! is_array( $profile_assigned_sources ) ) {
    $profile_assigned_sources = [];
}
$current_profile      = $current_profile ?? 'default';
$configured_suppliers = $configured_suppliers ?? [];

// Load the saved config for this profile
$attr_config    = function_exists( 'mmi_get_attribute_config' )
    ? mmi_get_attribute_config( $current_profile )
    : [];

$attr_enabled   = ! empty( $attr_config['enabled'] );
$product_type   = $attr_config['product_type']       ?? 'simple';
$variation_mode = $attr_config['variation_mode']      ?? 'flat';
$parent_field   = $attr_config['parent_group_field']  ?? '';
$variants_path  = $attr_config['variants_path']       ?? 'variants';
$var_sku_field  = $attr_config['variation_sku_field'] ?? '';
$var_price_key  = $attr_config['variation_price_key'] ?? '';
$var_stock_key  = $attr_config['variation_stock_key'] ?? '';
$attr_defs      = is_array( $attr_config['attributes'] ?? null ) ? $attr_config['attributes'] : [];

// Fetch registered WC global attributes
$wc_attributes = [];
if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
    foreach ( wc_get_attribute_taxonomies() as $tax ) {
        $wc_attributes[] = [
            'slug'  => wc_attribute_taxonomy_name( $tax->attribute_name ),
            'name'  => $tax->attribute_name,
            'label' => $tax->attribute_label,
        ];
    }
}

// Helper: render the WC attribute selector for one attribute row
function mmi_render_wc_attr_select( string $row_id, string $current_slug, array $wc_attributes ): void {
    $is_new = $current_slug === '__new__';
    ?>
    <div class="mmi-wc-attr-wrap">
        <select class="mmi-wc-attr-select" data-row="<?php echo esc_attr( $row_id ); ?>">
            <option value="">— Select WooCommerce attribute —</option>
            <?php foreach ( $wc_attributes as $wa ): ?>
                <option value="<?php echo esc_attr( $wa['slug'] ); ?>"
                    <?php selected( $current_slug, $wa['slug'] ); ?>>
                    <?php echo esc_html( $wa['label'] ); ?> (<?php echo esc_html( $wa['slug'] ); ?>)
                </option>
            <?php endforeach; ?>
            <option value="__new__" <?php selected( $is_new ); ?>>✚ Create new attribute…</option>
        </select>
        <?php if ( ! empty( $current_slug ) && $current_slug !== '__new__' ): ?>
            <span class="mmi-wc-attr-status registered">✓ Registered in WooCommerce</span>
        <?php elseif ( $is_new ): ?>
            <span class="mmi-wc-attr-status will-create">⚡ New attribute will be created on first import</span>
        <?php else: ?>
            <span class="mmi-wc-attr-status"></span>
        <?php endif; ?>
        <div class="mmi-new-attr-input-wrap<?php echo $is_new ? '' : ' mmi-is-hidden'; ?>" data-row="<?php echo esc_attr( $row_id ); ?>">
            <input type="text"
                   class="mmi-new-attr-name"
                   data-row="<?php echo esc_attr( $row_id ); ?>"
                   placeholder="Label, e.g. Color  →  will create pa_color">
            <button type="button"
                    class="button mmi-create-attr-btn"
                    data-row="<?php echo esc_attr( $row_id ); ?>">
                Create now in WooCommerce
            </button>
        </div>
    </div>
    <?php
}
?>

<div class="mmi-panel-content">

        <p class="mmi-section-description">
            Map supplier data fields to WooCommerce product attributes and define how variations
            are structured. <strong class="mmi-autosave-notice">All changes are automatically saved.</strong>
        </p>

        <!-- ── Enable toggle ─────────────────────────────────────────────── -->
        <div class="mmi-attr-enable-row">
            <label class="mmi-toggle-switch">
                <input type="checkbox"
                       id="mmi-attr-enabled"
                       class="mmi-attr-master-enable"
                       value="1"
                       <?php checked( $attr_enabled ); ?>>
                <span class="mmi-toggle-slider"></span>
            </label>
            <label for="mmi-attr-enabled" class="mmi-field-label">
                Enable Attribute &amp; Variation Import for this profile
            </label>
            <span id="mmi-attr-save-indicator" class="mmi-attr-save-indicator" data-state="saved"></span>
        </div>

        <div id="mmi-attr-config-body" class="mmi-attr-config-body<?php echo $attr_enabled ? '' : ' mmi-attr-disabled-overlay'; ?>">

        <?php /* ══════════════════════════════════════════════════════════════
             SECTION 1 — Product Type
        ═══════════════════════════════════════════════════════════════════ */ ?>
        <div class="mmi-attr-section" id="mmi-attr-sec-1">
            <div class="mmi-attr-section-head">
                <span class="mmi-attr-section-num <?php echo $product_type ? 'done' : ''; ?>">1</span>
                <strong class="mmi-attr-section-title">Choose Product Type</strong>
                <span class="mmi-attr-section-status" id="mmi-attr-sec1-status">
                    <?php echo $product_type === 'variable' ? '🔀 Variable Product' : '📦 Simple Product'; ?>
                </span>
            </div>
            <div class="mmi-attr-section-body">
                <div class="mmi-product-type-cards">

                    <label class="mmi-pt-card <?php echo $product_type === 'simple' ? 'selected' : ''; ?>">
                        <input type="radio" id="pt-simple" name="mmi_product_type" value="simple"
                               class="mmi-attr-product-type"
                               tabindex="-1"
                               <?php checked( $product_type, 'simple' ); ?>>
                        <span class="mmi-pt-card-icon">📦</span>
                        <span class="mmi-pt-card-title">Simple Products</span>
                        <span class="mmi-pt-card-desc">
                            Each row in your supplier feed creates one product.
                            Attributes (Color, Material, etc.) are shown on the product page
                            for information but do not create separate purchasable variations.
                        </span>
                        <span class="mmi-pt-card-example">
                            Example: Software license with "Platform: Windows / macOS" shown as specs
                        </span>
                    </label>

                    <label class="mmi-pt-card <?php echo $product_type === 'variable' ? 'selected' : ''; ?>">
                        <input type="radio" id="pt-variable" name="mmi_product_type" value="variable"
                               class="mmi-attr-product-type"
                               tabindex="-1"
                               <?php checked( $product_type, 'variable' ); ?>>
                        <span class="mmi-pt-card-icon">🔀</span>
                        <span class="mmi-pt-card-title">Variable Products</span>
                        <span class="mmi-pt-card-desc">
                            One parent product contains multiple purchasable variations.
                            Customers choose attributes (Color, Size) before adding to cart.
                            Each combination has its own SKU, price, and stock level.
                        </span>
                        <span class="mmi-pt-card-example">
                            Example: T-shirt in Red/S, Red/M, Blue/S, Blue/M — all under one listing
                        </span>
                    </label>

                </div><!-- /.mmi-product-type-cards -->
            </div>
        </div>

        <?php /* ══════════════════════════════════════════════════════════════
             SECTION 2 — Variation Structure (Variable only)
        ═══════════════════════════════════════════════════════════════════ */ ?>
        <div class="mmi-attr-section <?php echo $product_type !== 'variable' ? 'mmi-is-hidden' : ''; ?>"
             id="mmi-attr-sec-2">
            <div class="mmi-attr-section-head">
                <span class="mmi-attr-section-num <?php echo $variation_mode ? 'done' : ''; ?>">2</span>
                <strong class="mmi-attr-section-title">How Are Variations Structured in Your Feed?</strong>
                <span class="mmi-attr-section-status" id="mmi-attr-sec2-status">
                    <?php echo $variation_mode === 'nested' ? '🗂 Nested variants array' : '📄 Flat list'; ?>
                </span>
            </div>
            <div class="mmi-attr-section-body">

                <div class="mmi-attr-info-box">
                    <strong class="mmi-info-box-lead">Why does this matter?</strong>
                    Supplier feeds can represent variations in two ways. You need to tell the importer
                    which structure yours uses so it can group rows into the correct parent products.
                </div>

                <div class="mmi-variation-mode-cards">

                    <label class="mmi-vm-card <?php echo $variation_mode === 'flat' ? 'selected' : ''; ?>">
                        <input type="radio" id="vm-flat" name="mmi_variation_mode" value="flat"
                               class="mmi-attr-variation-mode"
                               tabindex="-1"
                               <?php checked( $variation_mode, 'flat' ); ?>>
                        <div class="mmi-vm-card-title">📄 Flat list — one row per variation</div>
                        <div class="mmi-vm-card-desc">
                            Each row in the feed <strong class="mmi-inline-strong">is one variation</strong>.
                            Rows that share the same value in a "parent group" field
                            (e.g., <code class="mmi-inline-code">parent_sku</code> or <code class="mmi-inline-code">product_id</code>)
                            are grouped together into one variable product.
                        </div>
                    </label>

                    <label class="mmi-vm-card <?php echo $variation_mode === 'nested' ? 'selected' : ''; ?>">
                        <input type="radio" id="vm-nested" name="mmi_variation_mode" value="nested"
                               class="mmi-attr-variation-mode"
                               tabindex="-1"
                               <?php checked( $variation_mode, 'nested' ); ?>>
                        <div class="mmi-vm-card-title">🗂 Nested — product contains a variants array</div>
                        <div class="mmi-vm-card-desc">
                            Each row is the <strong class="mmi-inline-strong">parent product</strong> and contains
                            a nested array (e.g., <code class="mmi-inline-code">"variants": [...]</code>) holding
                            the individual variation objects.
                        </div>
                    </label>

                </div><!-- /.mmi-variation-mode-cards -->

                <!-- Flat mode: parent group field -->
                <div id="mmi-flat-group-settings" class="mmi-flat-group-settings<?php echo $variation_mode === 'nested' ? ' mmi-is-hidden' : ''; ?>">
                    <div class="mmi-attr-field-row">
                        <label for="mmi-parent-group-field" class="mmi-field-label">Parent Group Field</label>
                        <div class="mmi-attr-field-wrap">
                            <input type="text"
                                   id="mmi-parent-group-field"
                                   class="mmi-attr-config-input"
                                   data-key="parent_group_field"
                                   value="<?php echo esc_attr( $parent_field ); ?>"
                                   placeholder="e.g.  parent_sku  or  product.id">
                            <span class="mmi-attr-field-hint">
                                The JSON field whose value groups rows into the same parent product.
                                Rows sharing the same value here become variations of one WooCommerce product.
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Nested mode: variants array path -->
                <div id="mmi-nested-group-settings" class="mmi-nested-group-settings<?php echo $variation_mode !== 'nested' ? ' mmi-is-hidden' : ''; ?>">
                    <div class="mmi-attr-field-row">
                        <label for="mmi-variants-path" class="mmi-field-label">Variants Array Path</label>
                        <div class="mmi-attr-field-wrap">
                            <input type="text"
                                   id="mmi-variants-path"
                                   class="mmi-attr-config-input"
                                   data-key="variants_path"
                                   value="<?php echo esc_attr( $variants_path ); ?>"
                                   placeholder="e.g.  variants  or  product.variants">
                            <span class="mmi-attr-field-hint">
                                Dot-notation path to the array containing the variation objects
                                within each product row.
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php /* ══════════════════════════════════════════════════════════════
             SECTION 3 — Attribute Definitions
        ═══════════════════════════════════════════════════════════════════ */ ?>
        <div class="mmi-attr-section" id="mmi-attr-sec-3">
            <div class="mmi-attr-section-head">
                <span class="mmi-attr-section-num <?php echo ! empty( $attr_defs ) ? 'done' : ''; ?>">
                    <?php echo $product_type === 'variable' ? '3' : '2'; ?>
                </span>
                <strong class="mmi-attr-section-title">Define Attributes</strong>
                <span class="mmi-attr-section-status">
                    <span id="mmi-attr-count" class="mmi-attr-count-value"><?php echo count( $attr_defs ); ?></span> defined
                </span>
            </div>
            <div class="mmi-attr-section-body">

                <div class="mmi-attr-info-box">
                    <strong class="mmi-info-box-lead">What is a WooCommerce Attribute?</strong>
                    Attributes describe product characteristics (Color, Size, Material, Platform).
                    In WooCommerce, <em class="mmi-inline-em">global attributes</em> are registered store-wide
                    (used in layered navigation filters) while <em class="mmi-inline-em">local attributes</em> only
                    exist on that product. This panel creates global attributes only.
                    Select an existing attribute from the dropdown or create a new one — the importer
                    will handle term creation automatically.
                </div>

                <?php if ( empty( $wc_attributes ) ): ?>
                    <div class="mmi-attr-info-box info-warning">
                        <strong class="mmi-info-box-lead">⚠️ No global attributes registered yet</strong>
                        Your WooCommerce store has no global product attributes configured.
                        Use the "Create new attribute…" option in the table below to define them,
                        or go to <strong class="mmi-inline-strong">WooCommerce → Attributes</strong> to set them up first.
                    </div>
                <?php endif; ?>

                <!-- Attribute definitions table -->
                <div class="mmi-attr-table-wrap">
                    <table class="mmi-attr-defs-table">
                        <thead class="mmi-table-head">
                            <tr class="mmi-table-header-row">
                                <th class="col-label">
                                    Attribute Label
                                    <span class="mmi-tp-hint mmi-font-normal">Your display name</span>
                                </th>
                                <th class="col-wc-attr">
                                    WooCommerce Attribute
                                    <span class="mmi-tp-hint mmi-font-normal">Registered taxonomy slug</span>
                                </th>
                                <th class="col-source">
                                    Source JSON Field
                                    <span class="mmi-tp-hint mmi-font-normal">Dot-notation path</span>
                                </th>
                                <th class="col-options">Options</th>
                                <th class="col-delete"></th>
                            </tr>
                        </thead>
                        <tbody id="mmi-attr-defs-tbody" class="mmi-attr-defs-tbody">
                            <?php if ( empty( $attr_defs ) ): ?>
                                <tr id="mmi-attr-empty-row" class="mmi-attr-empty-row">
                                    <td colspan="5" class="mmi-attr-empty">
                                        No attributes defined yet. Click <strong class="mmi-inline-strong">+ Add Attribute</strong> below
                                        or use <strong class="mmi-inline-strong">🔍 Discover from Sample Data</strong> to auto-detect.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ( $attr_defs as $attr ): ?>
                                    <?php
                                    $row_id = esc_attr( $attr['id'] ?? uniqid( 'attr_', false ) );
                                    ?>
                                    <tr data-attr-id="<?php echo $row_id; ?>" class="mmi-attr-def-row">
                                        <td class="col-label">
                                            <input type="text"
                                                   class="mmi-attr-label-input"
                                                   data-row="<?php echo $row_id; ?>"
                                                   value="<?php echo esc_attr( $attr['label'] ?? '' ); ?>"
                                                   placeholder="e.g. Color">
                                        </td>
                                        <td class="col-wc-attr">
                                            <?php mmi_render_wc_attr_select( $row_id, $attr['wc_slug'] ?? '', $wc_attributes ); ?>
                                        </td>
                                        <td class="col-source">
                                            <div class="mmi-source-field-wrap">
                                                <input type="text"
                                                       class="mmi-attr-source-input"
                                                       data-row="<?php echo $row_id; ?>"
                                                       value="<?php echo esc_attr( $attr['source_field'] ?? '' ); ?>"
                                                       placeholder="e.g. color"
                                                       list="attr-fields-<?php echo $row_id; ?>">
                                                <datalist id="attr-fields-<?php echo $row_id; ?>" class="mmi-attr-source-datalist"></datalist>
                                            </div>
                                        </td>
                                        <td class="col-options">
                                            <div class="mmi-attr-toggles">
                                                <label class="mmi-attr-toggle-item <?php echo $product_type !== 'variable' ? 'mmi-is-hidden' : ''; ?>"
                                                       title="Use this attribute to define purchasable variations (not just for display)">
                                                    <label class="mmi-toggle-switch">
                                                        <input type="checkbox"
                                                               class="mmi-attr-for-var"
                                                               data-row="<?php echo $row_id; ?>"
                                                               value="1"
                                                               <?php checked( ! empty( $attr['for_variations'] ) ); ?>>
                                                        <span class="mmi-toggle-slider"></span>
                                                    </label>
                                                    For variations
                                                </label>
                                                <label class="mmi-attr-toggle-item"
                                                       title="Show attribute on the product page">
                                                    <label class="mmi-toggle-switch">
                                                        <input type="checkbox"
                                                               class="mmi-attr-visible"
                                                               data-row="<?php echo $row_id; ?>"
                                                               value="1"
                                                               <?php checked( isset( $attr['visible'] ) ? $attr['visible'] : true ); ?>>
                                                        <span class="mmi-toggle-slider"></span>
                                                    </label>
                                                    Visible
                                                </label>
                                                <label class="mmi-attr-toggle-item"
                                                       title="Show in WooCommerce layered navigation filters">
                                                    <label class="mmi-toggle-switch">
                                                        <input type="checkbox"
                                                               class="mmi-attr-filterable"
                                                               data-row="<?php echo $row_id; ?>"
                                                               value="1"
                                                               <?php checked( ! empty( $attr['filterable'] ) ); ?>>
                                                        <span class="mmi-toggle-slider"></span>
                                                    </label>
                                                    Filterable
                                                </label>
                                            </div>
                                        </td>
                                        <td class="col-delete">
                                            <button type="button"
                                                    class="button button-link-delete mmi-attr-delete-row"
                                                    data-row="<?php echo $row_id; ?>"
                                                    title="Remove this attribute">
                                                🗑️
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div><!-- /.mmi-attr-table-wrap -->

                <!-- Actions -->
                <div class="mmi-attr-actions">
                    <button type="button" class="button mmi-btn-primary" id="mmi-attr-add-row">
                        ➕ Add Attribute
                    </button>

                    <?php
                    // Only offer sample data from source(s) this profile is actually
                    // scoped to (Step 3) — same restriction as the Field Mapping step's
                    // supplier columns. Empty $profile_assigned_sources means "all
                    // enabled sources", so nothing is filtered out in that case.
                    $mmi_attr_restrict = ! empty( $profile_assigned_sources );
                    $mmi_attr_discover_suppliers = $mmi_attr_restrict
                        ? array_intersect_key( $configured_suppliers, array_flip( $profile_assigned_sources ) )
                        : $configured_suppliers;
                    ?>
                    <?php if ( ! empty( $mmi_attr_discover_suppliers ) ): ?>
                        <select id="mmi-attr-discover-supplier" class="mmi-attr-discover-select">
                            <?php foreach ( $mmi_attr_discover_suppliers as $sid => $sinfo ): ?>
                                <option value="<?php echo esc_attr( $sid ); ?>"
                                        data-file="<?php echo esc_attr( array_key_first( $sinfo['file_options'] ?? [] ) ?? '' ); ?>">
                                    <?php echo esc_html( $sinfo['supplier_name'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button" id="mmi-attr-discover-btn">
                            🔍 Discover from Sample Data
                        </button>
                    <?php endif; ?>
                </div><!-- /.mmi-attr-actions -->

                <!-- Discover results (injected by JS) -->
                <div id="mmi-attr-discover-result" class="mmi-attr-discover-result mmi-is-hidden"></div>

            </div>
        </div>

        <?php /* ══════════════════════════════════════════════════════════════
             SECTION 4 — Variation-specific Fields (Variable only)
        ═══════════════════════════════════════════════════════════════════ */ ?>
        <div class="mmi-attr-section <?php echo $product_type !== 'variable' ? 'mmi-is-hidden' : ''; ?>"
             id="mmi-attr-sec-4">
            <div class="mmi-attr-section-head">
                <span class="mmi-attr-section-num done">4</span>
                <strong class="mmi-attr-section-title">Variation-specific Field Mapping</strong>
                <span class="mmi-attr-section-status">Price &amp; stock per variation</span>
            </div>
            <div class="mmi-attr-section-body">

                <div class="mmi-attr-info-box">
                    <strong class="mmi-info-box-lead">How does this differ from the main Field Mapping panel?</strong>
                    The main Field Mapping panel sets <em class="mmi-inline-em">parent product</em> fields (name, description, brand).
                    Here you specify which source fields carry the <strong class="mmi-inline-strong">per-variation</strong>
                    SKU, price, and stock — because each variation can have different values.
                    Leave blank to inherit from the parent field mapping.
                </div>

                <div class="mmi-variation-field-grid">

                    <div class="mmi-vf-item">
                        <label for="mmi-var-sku-field" class="mmi-field-label">Variation SKU source field</label>
                        <input type="text"
                               id="mmi-var-sku-field"
                               class="mmi-attr-config-input"
                               data-key="variation_sku_field"
                               value="<?php echo esc_attr( $var_sku_field ); ?>"
                               placeholder="e.g.  sku  or  variant.sku">
                        <span class="mmi-vf-hint">JSON path to the SKU for this specific variation.</span>
                    </div>

                    <div class="mmi-vf-item">
                        <label for="mmi-var-price-key" class="mmi-field-label">Variation price field key</label>
                        <input type="text"
                               id="mmi-var-price-key"
                               class="mmi-attr-config-input"
                               data-key="variation_price_key"
                               value="<?php echo esc_attr( $var_price_key ); ?>"
                               placeholder="e.g.  _regular_price  or  price">
                        <span class="mmi-vf-hint">
                            Either the source JSON path (if variations have a dedicated price field)
                            or leave blank to use the mapped <code class="mmi-inline-code">_regular_price</code> field from
                            the main Field Mapping panel.
                        </span>
                    </div>

                    <div class="mmi-vf-item">
                        <label for="mmi-var-stock-key" class="mmi-field-label">Variation stock field key</label>
                        <input type="text"
                               id="mmi-var-stock-key"
                               class="mmi-attr-config-input"
                               data-key="variation_stock_key"
                               value="<?php echo esc_attr( $var_stock_key ); ?>"
                               placeholder="e.g.  _stock_status  or  available">
                        <span class="mmi-vf-hint">
                            Source JSON path for variation stock status.
                            Leave blank to share the parent product's mapped stock field.
                        </span>
                    </div>

                </div><!-- /.mmi-variation-field-grid -->

            </div>
        </div>

        <?php /* ══════════════════════════════════════════════════════════════
             SECTION 5 — Live Configuration Preview
        ═══════════════════════════════════════════════════════════════════ */ ?>
        <div class="mmi-attr-section" id="mmi-attr-sec-preview">
            <div class="mmi-attr-section-head" id="mmi-attr-preview-toggle">
                <span class="mmi-attr-section-num">✓</span>
                <strong class="mmi-attr-section-title">Configuration Summary</strong>
                <span class="mmi-attr-section-status">
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </span>
            </div>
            <div class="mmi-attr-section-body" id="mmi-attr-preview-body">
                <div class="mmi-attr-preview-wrap" id="mmi-attr-preview-content">
                    <!-- Rendered by JS -->
                    <p class="mmi-attr-empty">Add attributes above to see a summary here.</p>
                </div>
            </div>
        </div>

        </div><!-- /#mmi-attr-config-body -->

    </div><!-- /.mmi-panel-content -->
