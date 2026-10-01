<?php
/**
 * Taxonomy Filter — server-side term lookup for the shared taxonomy filter
 * widget (js/shared/taxonomy-filter.js, window.mmiTaxonomyFilters). That JS
 * module has been bundled suite-wide since the mmi-hub-elimination
 * migration, but its PHP counterpart never was — the generic
 * `mmi_get_taxonomy_terms` AJAX action it posts to used to be
 * MMI_Taxonomy_Filter_Handler, registered inside mmi-hub (see
 * mmi-data-pipeline's `ImportSettingsController::get_taxonomy_terms()` own
 * docblock, which explicitly named its own endpoint differently to avoid
 * colliding with this one). Deleting mmi-hub without migrating it left the
 * action with zero listeners — confirmed live (`$wp_filter['wp_ajax_mmi_get
 * _taxonomy_terms']` empty) — silently breaking every taxonomy filter
 * dropdown built on this widget. Its only current real consumer is
 * mmi-reverb-integration's Products tab (Brand/Category/Distribution
 * filters); rebuilt here in the shared library, same shape as the sibling
 * gap already found and fixed for MMI_WC_Product_Filter_Handler, so a
 * second consumer can pick it up later without another migration.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Taxonomy_Filter_Handler' ) ) {
	class MMI_Taxonomy_Filter_Handler {

		/**
		 * Terms for each requested taxonomy, in the shape
		 * js/shared/taxonomy-filter.js's injectSection() expects:
		 * { slug => { label: string, terms: [ {slug, name}, ... ] } }.
		 * Unknown/non-existent taxonomy slugs are silently skipped — the
		 * caller declared its own filterable-taxonomy list (see
		 * $mmi_taxonomy_filter_slugs in tab-products.php), so a typo there
		 * should degrade to "one fewer filter shown," not a fatal.
		 *
		 * @param string[] $slugs Taxonomy slugs requested by the widget.
		 */
		public static function get_terms_for( array $slugs ): array {
			$result = [];

			foreach ( $slugs as $slug ) {
				$slug = sanitize_key( $slug );
				if ( '' === $slug || ! taxonomy_exists( $slug ) ) {
					continue;
				}

				$terms = get_terms( [
					'taxonomy'   => $slug,
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
				] );

				if ( is_wp_error( $terms ) ) {
					continue;
				}

				$taxonomy_obj = get_taxonomy( $slug );

				$result[ $slug ] = [
					'label' => $taxonomy_obj ? $taxonomy_obj->labels->singular_name : $slug,
					'terms' => array_map(
						static fn( $term ) => [ 'slug' => $term->slug, 'name' => $term->name ],
						$terms
					),
				];
			}

			return $result;
		}
	}
}
