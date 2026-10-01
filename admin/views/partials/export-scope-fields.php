<?php
/**
 * Export Scope Fields Partial
 *
 * Per-data-type scope/filter form. Rendered both on initial page load (Step 1
 * of the Export tab) and as an AJAX-fetched fragment when the user changes
 * the data-type dropdown (ExportController::get_scope_fields_for_type()) —
 * keeps the page from pre-rendering every handler's filter form up front.
 *
 * Available in this scope when included directly: $current_data_type,
 * $current_profile_meta. Available when included from the AJAX handler:
 * $data_type only.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_profile_meta = $current_profile_meta ?? [];
$scope_data_type = $data_type ?? ( $current_data_type ?? 'product' );
$scope_saved     = is_array( $current_profile_meta['product_identifier'] ?? null ) ? $current_profile_meta['product_identifier'] : [];
?>

<div class="mmi-export-scope-inner" data-data-type="<?php echo esc_attr( $scope_data_type ); ?>">
    <div class="mmi-scope-section-title">
        <span class="dashicons dashicons-filter"></span>
        Filters
        <span class="description">(optional — narrows what gets exported)</span>
    </div>
<?php if ( $scope_data_type === 'product' ) : ?>
    <div class="mmi-modal-field">
        <label><strong>Category</strong> <span class="description">(optional)</span></label>
        <div class="mmi-taxonomy-tree-toolbar">
            <button type="button" class="button-link mmi-taxonomy-tree-select-all">Select All</button>
            <span class="mmi-taxonomy-tree-toolbar-sep" aria-hidden="true">·</span>
            <button type="button" class="button-link mmi-taxonomy-tree-clear-all">Clear All</button>
        </div>
        <?php
        $selected_cats = $scope_saved['category'] ?? [];
        if ( ! is_array( $selected_cats ) ) {
            $selected_cats = [ $selected_cats ];
        }
        MMI_Taxonomy_Tree_Renderer::render( 'product_cat', 'category', $selected_cats );
        ?>
        <span class="description mmi-scope-multi-hint">Click a checkbox to toggle it on or off. Checking a parent category automatically includes all of its subcategories. Leave nothing checked to export all categories.</span>
    </div>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-stock-status"><strong>Stock Status</strong> <span class="description">(optional)</span></label>
        <select id="mmi-export-scope-stock-status" class="widefat mmi-scope-field" data-scope-key="stock_status">
            <option value="">Any</option>
            <option value="instock" <?php selected( $scope_saved['stock_status'] ?? '', 'instock' ); ?>>In Stock</option>
            <option value="outofstock" <?php selected( $scope_saved['stock_status'] ?? '', 'outofstock' ); ?>>Out of Stock</option>
        </select>
    </div>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-search"><strong>Search</strong> <span class="description">(optional)</span></label>
        <input type="text" id="mmi-export-scope-search" class="widefat mmi-scope-field" data-scope-key="search" placeholder="Product name or SKU…" value="<?php echo esc_attr( $scope_saved['search'] ?? '' ); ?>">
    </div>
    <?php
    // Every other taxonomy registered to 'product' (Brand, Tags, attribute
    // taxonomies like pa_color, etc.) — product_cat is excluded since it
    // already has its own always-expanded field above.
    MMI_Taxonomy_Tree_Renderer::render_all_for_post_type( 'product', [ 'product_cat' ], $scope_saved );
    $meta_key_options = MMI_Meta_Key_Discovery::discover_for_post_type( 'product' );
    include __DIR__ . '/export-scope-meta-conditions.php';
    ?>
<?php elseif ( $scope_data_type === 'order' ) : ?>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-status"><strong>Order Status</strong> <span class="description">(optional)</span></label>
        <select id="mmi-export-scope-status" class="widefat mmi-scope-field" data-scope-key="status" data-scope-multi="1" multiple size="7">
            <?php
            $order_statuses  = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
            $selected_status = $scope_saved['status'] ?? [];
            if ( ! is_array( $selected_status ) ) {
                $selected_status = [ $selected_status ];
            }
            foreach ( $order_statuses as $slug => $label ) :
                $slug_bare = str_replace( 'wc-', '', $slug );
            ?>
                <option value="<?php echo esc_attr( $slug_bare ); ?>" <?php selected( in_array( $slug_bare, $selected_status, true ) ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span class="description mmi-scope-multi-hint">Hold Ctrl (Windows) or ⌘ (Mac) to select multiple. Leave nothing selected to export orders in any status.</span>
    </div>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-date-after"><strong>Date Range</strong> <span class="description">(optional)</span></label>
        <input type="date" id="mmi-export-scope-date-after" class="mmi-scope-field" data-scope-key="date_after" value="<?php echo esc_attr( $scope_saved['date_after'] ?? '' ); ?>"> to
        <input type="date" id="mmi-export-scope-date-before" class="mmi-scope-field" data-scope-key="date_before" value="<?php echo esc_attr( $scope_saved['date_before'] ?? '' ); ?>">
    </div>
<?php elseif ( $scope_data_type === 'customer' ) : ?>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-cust-search"><strong>Search</strong> <span class="description">(optional)</span></label>
        <input type="text" id="mmi-export-scope-cust-search" class="widefat mmi-scope-field" data-scope-key="search" placeholder="Email or name…" value="<?php echo esc_attr( $scope_saved['search'] ?? '' ); ?>">
    </div>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-reg-after"><strong>Registered Date Range</strong> <span class="description">(optional)</span></label>
        <input type="date" id="mmi-export-scope-reg-after" class="mmi-scope-field" data-scope-key="registered_after" value="<?php echo esc_attr( $scope_saved['registered_after'] ?? '' ); ?>"> to
        <input type="date" id="mmi-export-scope-reg-before" class="mmi-scope-field" data-scope-key="registered_before" value="<?php echo esc_attr( $scope_saved['registered_before'] ?? '' ); ?>">
    </div>
    <p class="description">Covers registered customer accounts only — guest checkout orders are not included yet.</p>
<?php elseif ( $scope_data_type === 'coupon' ) : ?>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-coupon-status"><strong>Status</strong> <span class="description">(optional)</span></label>
        <select id="mmi-export-scope-coupon-status" class="widefat mmi-scope-field" data-scope-key="status">
            <option value="">Any</option>
            <option value="publish" <?php selected( $scope_saved['status'] ?? '', 'publish' ); ?>>Active</option>
            <option value="draft" <?php selected( $scope_saved['status'] ?? '', 'draft' ); ?>>Draft</option>
        </select>
    </div>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-coupon-search"><strong>Search</strong> <span class="description">(optional)</span></label>
        <input type="text" id="mmi-export-scope-coupon-search" class="widefat mmi-scope-field" data-scope-key="search" placeholder="Coupon code…" value="<?php echo esc_attr( $scope_saved['search'] ?? '' ); ?>">
    </div>
<?php elseif ( $scope_data_type === 'comment' ) : ?>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-comment-status"><strong>Status</strong> <span class="description">(optional)</span></label>
        <select id="mmi-export-scope-comment-status" class="widefat mmi-scope-field" data-scope-key="status">
            <option value="approve" <?php selected( $scope_saved['status'] ?? 'approve', 'approve' ); ?>>Approved</option>
            <option value="hold" <?php selected( $scope_saved['status'] ?? '', 'hold' ); ?>>Pending</option>
            <option value="spam" <?php selected( $scope_saved['status'] ?? '', 'spam' ); ?>>Spam</option>
            <option value="any" <?php selected( $scope_saved['status'] ?? '', 'any' ); ?>>Any</option>
        </select>
    </div>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-comment-post-id"><strong>Post ID</strong> <span class="description">(optional)</span></label>
        <input type="number" id="mmi-export-scope-comment-post-id" class="widefat mmi-scope-field" data-scope-key="post_id" value="<?php echo esc_attr( $scope_saved['post_id'] ?? '' ); ?>">
    </div>
<?php elseif ( $scope_data_type === 'user' ) : ?>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-user-role"><strong>Role</strong> <span class="description">(optional)</span></label>
        <select id="mmi-export-scope-user-role" class="widefat mmi-scope-field" data-scope-key="role">
            <option value="">Any role</option>
            <?php foreach ( wp_roles()->get_names() as $role_slug => $role_label ) : ?>
                <option value="<?php echo esc_attr( $role_slug ); ?>" <?php selected( $scope_saved['role'] ?? '', $role_slug ); ?>>
                    <?php echo esc_html( $role_label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-user-search"><strong>Search</strong> <span class="description">(optional)</span></label>
        <input type="text" id="mmi-export-scope-user-search" class="widefat mmi-scope-field" data-scope-key="search" placeholder="Email, username, or name…" value="<?php echo esc_attr( $scope_saved['search'] ?? '' ); ?>">
    </div>
<?php elseif ( $scope_data_type === 'post' || $scope_data_type === 'page' || strpos( $scope_data_type, 'cpt:' ) === 0 ) : ?>
    <?php
    $cpt_post_type = strpos( $scope_data_type, 'cpt:' ) === 0 ? substr( $scope_data_type, 4 ) : $scope_data_type;
    $cpt_statuses  = get_post_statuses();
    ?>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-post-status"><strong>Status</strong> <span class="description">(optional)</span></label>
        <select id="mmi-export-scope-post-status" class="widefat mmi-scope-field" data-scope-key="status">
            <?php foreach ( $cpt_statuses as $status_slug => $status_label ) : ?>
                <option value="<?php echo esc_attr( $status_slug ); ?>" <?php selected( $scope_saved['status'] ?? 'publish', $status_slug ); ?>>
                    <?php echo esc_html( $status_label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-post-search"><strong>Search</strong> <span class="description">(optional)</span></label>
        <input type="text" id="mmi-export-scope-post-search" class="widefat mmi-scope-field" data-scope-key="search" placeholder="Title or content…" value="<?php echo esc_attr( $scope_saved['search'] ?? '' ); ?>">
    </div>
    <?php
    // Every registered taxonomy for this post type gets its own filter —
    // replaces the old single dropdown+term-text-input combo, which could
    // only filter by one taxonomy at a time and offered no hierarchy.
    MMI_Taxonomy_Tree_Renderer::render_all_for_post_type( $cpt_post_type, [], $scope_saved );
    $meta_key_options = MMI_Meta_Key_Discovery::discover_for_post_type( $cpt_post_type );
    include __DIR__ . '/export-scope-meta-conditions.php';
    ?>
<?php elseif ( strpos( $scope_data_type, 'taxonomy:' ) === 0 ) :
    $tax_slug       = substr( $scope_data_type, strlen( 'taxonomy:' ) );
    $tax_obj        = get_taxonomy( $tax_slug );
    $tax_label      = $tax_obj ? ( $tax_obj->label ?: $tax_slug ) : $tax_slug;
    $selected_terms = MMI_Taxonomy_Tree_Renderer::normalize_scope_terms( $scope_saved['terms'] ?? [] );
    ?>
    <?php if ( $tax_obj && $tax_obj->hierarchical ) : ?>
    <div class="mmi-modal-field">
        <label><strong>Terms</strong> <span class="description">(optional)</span></label>
        <?php
        // render_tree_or_fallback(), not a direct render() call — some
        // registered taxonomies on this install run into the thousands of
        // terms (e.g. a third-party plugin's "Level"/"Line" taxonomies),
        // and this data type covers every public taxonomy, not just the
        // ~70-term product_cat shown in the Product export's Category
        // field above. See TREE_TERM_LIMIT's docblock for why the checkbox
        // tree isn't safe to render unconditionally at that size.
        MMI_Taxonomy_Tree_Renderer::render_tree_or_fallback( $tax_slug, 'terms', $selected_terms, $tax_label );
        ?>
    </div>
    <?php endif; ?>
    <div class="mmi-modal-field">
        <label for="mmi-export-scope-tax-search"><strong>Search</strong> <span class="description">(optional)</span></label>
        <input type="text" id="mmi-export-scope-tax-search" class="widefat mmi-scope-field" data-scope-key="search" placeholder="Term name…" value="<?php echo esc_attr( $scope_saved['search'] ?? '' ); ?>">
    </div>
    <div class="mmi-modal-field">
        <label>
            <input type="checkbox" id="mmi-export-scope-tax-hide-empty" class="mmi-scope-field" data-scope-key="hide_empty" data-scope-checkbox="1" <?php checked( ! empty( $scope_saved['hide_empty'] ) ); ?>>
            Only include terms with at least one post
        </label>
    </div>
<?php else : ?>
    <p class="description">No scope filters are available yet for this data type.</p>
<?php endif; ?>
</div>
