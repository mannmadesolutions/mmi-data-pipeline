<?php
/**
 * Taxonomy Tree Renderer
 *
 * Renders a hierarchical taxonomy as nested `<ul>` checkbox lists (parent
 * category with indented children), the same shape as a scope multi-select
 * field (`.mmi-scope-field` / `data-scope-key` / `data-scope-multi="1"`) so
 * it drops into `collectScopeValues()` in export-settings.js without any
 * server-side consumer changes — `MMI_Product_Data_Type::query_records()`
 * already accepts `scope['category']` as an array of slugs regardless of
 * whether it came from a flat `<select multiple>` or this tree.
 *
 * Generalizes the working hierarchy-checkbox pattern already proven in
 * `mmi-reverb-integration` (`display_term_hierarchy()` in
 * tab-bulk-updates.php) — same recursive parent/child algorithm — but is a
 * fresh implementation scoped to this plugin rather than a shared include,
 * so the two plugins' proven features stay independent and this plugin
 * carries no new cross-plugin dependency.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Taxonomy_Tree_Renderer {

    /**
     * Above this term count, render_all_for_post_type() falls back to a
     * plain comma-separated slug input instead of a full checkbox tree.
     * Verified against this install's real product taxonomies: most are
     * well under this (brand=245, pa_version=272 both render fine as trees),
     * but two ("level"=1711 terms, "line"=817 terms — evidently a
     * third-party plugin's taxonomies, not ones this plugin controls) would
     * each dump well over a thousand checkboxes into the page on every Step
     * 1 load if not capped, regardless of whether that <details> is ever
     * opened. A typed-slug fallback avoids that without needing a lazy-load
     * AJAX round trip, which would risk silently dropping an unloaded
     * taxonomy's previously saved selection on the next unrelated autosave
     * (collectScopeValues() only sees fields actually present in the DOM).
     */
    private const TREE_TERM_LIMIT = 150;

    /**
     * @param string   $taxonomy       Taxonomy slug (e.g. 'product_cat').
     * @param string   $scope_key      Value for the container's data-scope-key.
     * @param string[] $selected_slugs Term slugs that should render checked.
     */
    public static function render( string $taxonomy, string $scope_key, array $selected_slugs ): void {
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            echo '<p class="description">No terms found for this taxonomy.</p>';
            return;
        }

        echo '<div class="mmi-taxonomy-tree mmi-scope-field" data-scope-key="' . esc_attr( $scope_key ) . '" data-scope-multi="1" data-scope-tree="1">';
        self::render_level( $terms, 0, $selected_slugs );
        echo '</div>';
    }

    /**
     * Renders one collapsible `.mmi-modal-field` per `show_ui` taxonomy
     * registered to $post_type (product's Brand/Tags/attribute taxonomies,
     * a CPT's custom taxonomies, etc.) — "all available taxonomies should be
     * available for filtering," not just the one or two the scope form
     * happened to hardcode. Each gets its own scope key ('tax_{taxonomy}')
     * consumed generically by MMI_Product_Data_Type / MMI_WP_Post_Type_Data_Type's
     * query_records(). Wrapped in native <details> (zero extra JS) so N
     * taxonomies don't turn Step 1 into a wall of checkboxes — only the
     * first one renders expanded.
     *
     * @param string   $post_type   Post type to enumerate taxonomies for.
     * @param string[] $exclude     Taxonomy slugs already given a dedicated
     *                              field elsewhere (e.g. 'product_cat',
     *                              rendered via the legacy 'category' scope
     *                              key for backward compatibility).
     * @param array    $scope_saved The full saved scope array (for prefilling).
     */
    public static function render_all_for_post_type( string $post_type, array $exclude, array $scope_saved ): void {
        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        $first      = true;

        foreach ( $taxonomies as $tax ) {
            if ( ! $tax->show_ui || in_array( $tax->name, $exclude, true ) ) {
                continue;
            }

            $scope_key = 'tax_' . $tax->name;
            $selected  = $scope_saved[ $scope_key ] ?? [];
            if ( ! is_array( $selected ) ) {
                $selected = [ $selected ];
            }
            $label      = $tax->label ?: $tax->name;
            $term_count = wp_count_terms( [ 'taxonomy' => $tax->name, 'hide_empty' => false ] );
            $term_count = is_wp_error( $term_count ) ? 0 : (int) $term_count;

            echo '<div class="mmi-modal-field">';
            echo '<details' . ( $first ? ' open' : '' ) . ' class="mmi-taxonomy-tree-details">';
            echo '<summary><strong>' . esc_html( $label ) . '</strong> <span class="description">(optional' . ( $term_count > 0 ? ', ' . $term_count . ' terms' : '' ) . ')</span></summary>';
            self::render_tree_or_fallback( $tax->name, $scope_key, $selected, $label );
            echo '</details>';
            echo '</div>';

            $first = false;
        }
    }

    /**
     * Renders the checkbox tree for one taxonomy, or — above
     * TREE_TERM_LIMIT — the comma-separated-slug text fallback, exactly the
     * per-taxonomy decision render_all_for_post_type() already made inline.
     * Factored out so a single-taxonomy caller (the "export this taxonomy's
     * own terms" scope field, which isn't iterating "every taxonomy for a
     * post type") gets identical large-taxonomy handling without
     * duplicating the threshold check.
     *
     * @param string   $taxonomy  Taxonomy slug.
     * @param string   $scope_key Scope key for the field.
     * @param string[] $selected  Term slugs that should render checked/prefilled.
     * @param string   $label     Human label, used only in hint text.
     */
    public static function render_tree_or_fallback( string $taxonomy, string $scope_key, array $selected, string $label ): void {
        $term_count = wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
        $term_count = is_wp_error( $term_count ) ? 0 : (int) $term_count;

        if ( $term_count > self::TREE_TERM_LIMIT ) {
            echo '<input type="text" class="widefat mmi-scope-field" data-scope-key="' . esc_attr( $scope_key ) . '" placeholder="Comma-separated term slugs…" value="' . esc_attr( implode( ', ', $selected ) ) . '">';
            echo '<span class="description mmi-scope-multi-hint">' . esc_html( $term_count ) . ' terms — too many to list as checkboxes. Enter one or more slugs, separated by commas. Leave blank to include all.</span>';
            return;
        }

        echo '<div class="mmi-taxonomy-tree-toolbar">';
        echo '<button type="button" class="button-link mmi-taxonomy-tree-select-all">Select All</button>';
        echo '<span class="mmi-taxonomy-tree-toolbar-sep" aria-hidden="true">·</span>';
        echo '<button type="button" class="button-link mmi-taxonomy-tree-clear-all">Clear All</button>';
        echo '</div>';
        self::render( $taxonomy, $scope_key, $selected );
        echo '<span class="description mmi-scope-multi-hint">Leave nothing checked to include all ' . esc_html( strtolower( $label ) ) . '.</span>';
    }

    /**
     * Normalizes a 'tax_{taxonomy}' scope value into a term-slug array,
     * regardless of whether it came from the checkbox tree (already an
     * array) or the large-taxonomy comma-separated-slug text fallback
     * (a single string) — see render_all_for_post_type()'s TREE_TERM_LIMIT
     * branch. Shared by every data type handler's query_records() so the
     * two input shapes don't need separate handling at each call site.
     *
     * @param array|string $value
     * @return string[]
     */
    public static function normalize_scope_terms( $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }
        return array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
    }

    /**
     * @param \WP_Term[] $terms          Full flat term list (queried once by render()).
     * @param int        $parent         Parent term_id to render children of.
     * @param string[]   $selected_slugs Term slugs that should render checked.
     */
    private static function render_level( array $terms, int $parent, array $selected_slugs ): void {
        $children = array_filter( $terms, static fn( $t ) => (int) $t->parent === $parent );
        if ( empty( $children ) ) {
            return;
        }

        echo '<ul>';
        foreach ( $children as $term ) {
            $has_children = (bool) array_filter( $terms, static fn( $t ) => (int) $t->parent === (int) $term->term_id );
            $checked      = in_array( $term->slug, $selected_slugs, true );

            echo '<li>';
            echo '<label class="' . ( $has_children ? 'mmi-taxonomy-tree-parent' : '' ) . '">';
            echo '<input type="checkbox" class="mmi-taxonomy-tree-checkbox" value="' . esc_attr( $term->slug ) . '" data-term-id="' . esc_attr( $term->term_id ) . '"' . ( $checked ? ' checked' : '' ) . '>';
            echo ' ' . esc_html( $term->name );
            if ( $term->count > 0 ) {
                echo ' <span class="mmi-taxonomy-tree-count">(' . (int) $term->count . ')</span>';
            }
            echo '</label>';
            self::render_level( $terms, (int) $term->term_id, $selected_slugs );
            echo '</li>';
        }
        echo '</ul>';
    }
}
