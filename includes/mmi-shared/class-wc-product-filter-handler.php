<?php
/**
 * WC Product Filter Bar — sanitize + SQL support for the shared search/stock/image/price
 * filter bar (js/shared/wc-product-filter-bar.js, MMI_WcFilterBar). That JS module has
 * been bundled suite-wide since the mmi-hub-elimination migration, but its PHP
 * counterpart (this class, plus the rendering partial and the pill-count AJAX handler)
 * was never migrated out of mmi-hub — every call site across the suite degraded to
 * `class_exists('MMI_HUB')` (permanently false) or `class_exists('MMI_WC_Product_Filter_Handler')`
 * (previously undefined), so the filters silently never applied anywhere. Rebuilt here,
 * in the shared library, once a second real consumer (mmi-data-pipeline's Review &
 * Compare panel, alongside mmi-google-services' Schema Health tab) confirmed this is
 * genuinely cross-plugin rather than a single plugin's concern — see AGENTS.md's CSS
 * Ownership rule.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_WC_Product_Filter_Handler' ) ) {
	class MMI_WC_Product_Filter_Handler {

		/**
		 * Sanitize the raw filter params posted by MMI_WcFilterBar.getParams().
		 *
		 * @param  array $raw  Usually $_POST.
		 * @return array{s: string, filter_stock: string, filter_featured_image: string, filter_price_min: string, filter_price_max: string}
		 */
		public static function sanitize( array $raw ): array {
			$stock = isset( $raw['filter_stock'] ) ? sanitize_key( wp_unslash( $raw['filter_stock'] ) ) : '';
			if ( ! in_array( $stock, [ 'in_stock', 'out_of_stock' ], true ) ) {
				$stock = '';
			}

			$image = isset( $raw['filter_featured_image'] ) ? sanitize_key( wp_unslash( $raw['filter_featured_image'] ) ) : '';
			if ( ! in_array( $image, [ 'yes', 'no' ], true ) ) {
				$image = '';
			}

			return [
				's'                     => isset( $raw['s'] ) ? sanitize_text_field( wp_unslash( $raw['s'] ) ) : '',
				'filter_stock'          => $stock,
				'filter_featured_image' => $image,
				'filter_price_min'      => isset( $raw['filter_price_min'] ) && is_numeric( $raw['filter_price_min'] ) ? (string) floatval( $raw['filter_price_min'] ) : '',
				'filter_price_max'      => isset( $raw['filter_price_max'] ) && is_numeric( $raw['filter_price_max'] ) ? (string) floatval( $raw['filter_price_max'] ) : '',
			];
		}

		/**
		 * Build a `SELECT COUNT(DISTINCT p.ID) ...` query matching the given filters.
		 * Callers needing the matching IDs instead swap the SELECT clause themselves
		 * (see MMI_Product_Health::get_report() / class-import-preview.php).
		 */
		public static function count_query( array $filters ): string {
			global $wpdb;

			$joins = [];
			$where = [ "p.post_type = 'product'", "p.post_status = 'publish'" ];

			if ( ! empty( $filters['s'] ) ) {
				$like    = '%' . $wpdb->esc_like( $filters['s'] ) . '%';
				$joins[] = "LEFT JOIN {$wpdb->postmeta} pm_sku ON pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku'";
				$where[] = $wpdb->prepare( '(p.post_title LIKE %s OR pm_sku.meta_value LIKE %s)', $like, $like );
			}

			if ( '' !== $filters['filter_stock'] ) {
				$joins[] = "LEFT JOIN {$wpdb->postmeta} pm_stock ON pm_stock.post_id = p.ID AND pm_stock.meta_key = '_stock_status'";
				$where[] = $wpdb->prepare( 'pm_stock.meta_value = %s', $filters['filter_stock'] );
			}

			if ( '' !== $filters['filter_featured_image'] ) {
				$joins[] = "LEFT JOIN {$wpdb->postmeta} pm_thumb ON pm_thumb.post_id = p.ID AND pm_thumb.meta_key = '_thumbnail_id'";
				$where[] = 'yes' === $filters['filter_featured_image']
					? "pm_thumb.meta_value IS NOT NULL AND pm_thumb.meta_value != ''"
					: "(pm_thumb.meta_value IS NULL OR pm_thumb.meta_value = '')";
			}

			if ( '' !== $filters['filter_price_min'] || '' !== $filters['filter_price_max'] ) {
				$joins[] = "LEFT JOIN {$wpdb->postmeta} pm_price ON pm_price.post_id = p.ID AND pm_price.meta_key = '_price'";
				if ( '' !== $filters['filter_price_min'] ) {
					$where[] = $wpdb->prepare( 'CAST(pm_price.meta_value AS DECIMAL(10,2)) >= %f', (float) $filters['filter_price_min'] );
				}
				if ( '' !== $filters['filter_price_max'] ) {
					$where[] = $wpdb->prepare( 'CAST(pm_price.meta_value AS DECIMAL(10,2)) <= %f', (float) $filters['filter_price_max'] );
				}
			}

			$joins_sql = implode( ' ', array_unique( $joins ) );
			$where_sql = implode( ' AND ', $where );

			return "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$joins_sql} WHERE {$where_sql}";
		}

		/**
		 * Sitewide counts for the filter bar's pill badges: { stock: {in_stock, out_of_stock}, image: {yes, no} }.
		 * Cached briefly — this scans the whole product catalog and the bar can appear on every page load,
		 * across every plugin that uses it.
		 */
		public static function global_counts(): array {
			$cached = get_transient( 'mmi_wc_filter_bar_counts' );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			global $wpdb;

			$counts = [
				'stock' => [
					'in_stock'     => (int) $wpdb->get_var( self::count_query( self::sanitize( [ 'filter_stock' => 'in_stock' ] ) ) ),
					'out_of_stock' => (int) $wpdb->get_var( self::count_query( self::sanitize( [ 'filter_stock' => 'out_of_stock' ] ) ) ),
				],
				'image' => [
					'yes' => (int) $wpdb->get_var( self::count_query( self::sanitize( [ 'filter_featured_image' => 'yes' ] ) ) ),
					'no'  => (int) $wpdb->get_var( self::count_query( self::sanitize( [ 'filter_featured_image' => 'no' ] ) ) ),
				],
			];

			set_transient( 'mmi_wc_filter_bar_counts', $counts, 5 * MINUTE_IN_SECONDS );

			return $counts;
		}

		/**
		 * Render the filter bar markup expected by MMI_WcFilterBar (js/shared/wc-product-filter-bar.js).
		 * Optional — a consumer with its own bespoke layout (e.g. mmi-data-pipeline's Review &
		 * Compare panel, whose filter groups interleave with non-WC filters) can hand-roll the
		 * same markup instead and just use sanitize()/count_query()/global_counts() above.
		 *
		 * @param array $config {
		 *     @type string[] $filters   Which controls to show: 'search', 'stock', 'image', 'price'.
		 *     @type string   $css_class Extra class appended to the outer wrapper.
		 * }
		 */
		public static function render( array $config = [] ): void {
			$filters   = $config['filters'] ?? [ 'search', 'stock', 'image', 'price' ];
			$css_class = $config['css_class'] ?? '';
			?>
			<div class="mmi-wc-filter-bar <?php echo esc_attr( $css_class ); ?>" data-wc-filter-bar>
				<?php if ( in_array( 'search', $filters, true ) ) : ?>
					<div class="mmi-wc-filter-group mmi-wc-filter-search">
						<input type="search" id="wc-product-search-input" class="mmi-wc-filter-input" placeholder="<?php esc_attr_e( 'Search products…', 'mmi-shared' ); ?>" />
						<input type="hidden" id="wc-filter-search" value="" />
					</div>
				<?php endif; ?>

				<?php if ( in_array( 'stock', $filters, true ) ) : ?>
					<div class="mmi-filter-pills" data-filter="wc-filter-stock" data-counts-key="stock">
						<button type="button" class="mmi-pill" data-value="in_stock"><span class="mmi-pill-label"><?php esc_html_e( 'In Stock', 'mmi-shared' ); ?></span></button>
						<button type="button" class="mmi-pill" data-value="out_of_stock"><span class="mmi-pill-label"><?php esc_html_e( 'Out of Stock', 'mmi-shared' ); ?></span></button>
					</div>
					<input type="hidden" id="wc-filter-stock" value="" />
				<?php endif; ?>

				<?php if ( in_array( 'image', $filters, true ) ) : ?>
					<div class="mmi-filter-pills" data-filter="wc-filter-featured-image" data-counts-key="image">
						<button type="button" class="mmi-pill" data-value="yes"><span class="mmi-pill-label"><?php esc_html_e( 'Has Image', 'mmi-shared' ); ?></span></button>
						<button type="button" class="mmi-pill" data-value="no"><span class="mmi-pill-label"><?php esc_html_e( 'No Image', 'mmi-shared' ); ?></span></button>
					</div>
					<input type="hidden" id="wc-filter-featured-image" value="" />
				<?php endif; ?>

				<?php if ( in_array( 'price', $filters, true ) ) : ?>
					<div class="mmi-wc-filter-group mmi-wc-filter-price">
						<input type="number" id="wc-filter-price-min" class="mmi-wc-filter-input mmi-wc-filter-price-input" placeholder="<?php esc_attr_e( 'Min $', 'mmi-shared' ); ?>" min="0" step="0.01" />
						<span class="mmi-wc-filter-price-sep">–</span>
						<input type="number" id="wc-filter-price-max" class="mmi-wc-filter-input mmi-wc-filter-price-input" placeholder="<?php esc_attr_e( 'Max $', 'mmi-shared' ); ?>" min="0" step="0.01" />
					</div>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
