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
		 * { slug => { label: string, terms: [ {slug, name, depth}, ... ] } }.
		 * A hierarchical taxonomy's terms come as a tree, each parent followed
		 * by its children (alphabetical among siblings); depth is 0 for a
		 * top-level term. Unknown/non-existent taxonomy slugs are silently
		 * skipped — the caller declared its own filterable-taxonomy list, so a
		 * typo there should degrade to "one fewer filter shown," not a fatal.
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

				$result[ $slug ] = [
					'label' => self::label( $slug ),
					'terms' => self::as_tree( $terms, is_taxonomy_hierarchical( $slug ) ),
				];
			}

			return $result;
		}

		/**
		 * The taxonomy's singular name. A taxonomy named like a core one
		 * ("Category", "Tag") is prefixed with its post type ("Product
		 * Category"), since WordPress posts have a Category too.
		 */
		private static function label( string $taxonomy ): string {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax ) {
				return $taxonomy;
			}
			$label = $tax->labels->singular_name;
			if ( ! in_array( $taxonomy, [ 'category', 'post_tag' ], true ) ) {
				$core = array_filter( array_map(
					static fn( $core_slug ) => get_taxonomy( $core_slug ) ? get_taxonomy( $core_slug )->labels->singular_name : '',
					[ 'category', 'post_tag' ]
				) );
				$type = get_post_type_object( $tax->object_type[0] ?? '' );
				if ( $type && in_array( $label, $core, true ) ) {
					$label = $type->labels->singular_name . ' ' . $label;
				}
			}
			return $label;
		}

		/**
		 * Name-sorted terms → [ {slug, name, depth} ] with every parent
		 * followed by its children. Names are decoded (WordPress stores
		 * "&" as "&amp;"); the browser escapes them on output.
		 *
		 * @param WP_Term[] $terms Sorted by name.
		 */
		private static function as_tree( array $terms, bool $hierarchical ): array {
			$ids      = array_flip( array_map( static fn( $t ) => (int) $t->term_id, $terms ) );
			$children = [];
			foreach ( $terms as $term ) {
				// A term whose parent is missing is shown at the top level, never dropped.
				$parent = $hierarchical && isset( $ids[ (int) $term->parent ] ) ? (int) $term->parent : 0;
				$children[ $parent ][] = $term;
			}

			$out   = [];
			$stack = [ [ 0, 0 ] ];
			$queue = [ 0 => array_reverse( $children[0] ?? [] ) ];
			while ( $stack ) {
				[ $parent, $depth ] = end( $stack );
				$term = array_pop( $queue[ $parent ] );
				if ( null === $term ) {
					array_pop( $stack );
					continue;
				}
				$out[] = [
					'slug'  => $term->slug,
					'name'  => wp_specialchars_decode( $term->name, ENT_QUOTES ),
					'depth' => $depth,
				];
				$id = (int) $term->term_id;
				if ( ! empty( $children[ $id ] ) ) {
					$queue[ $id ] = array_reverse( $children[ $id ] );
					$stack[]      = [ $id, $depth + 1 ];
				}
			}
			return $out;
		}
	}
}
