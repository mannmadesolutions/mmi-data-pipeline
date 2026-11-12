<?php
/**
 * Feed first (owner 2026-10-29): "Product data sources contain the assets we're looking for most of the time.
 * We should start the product data journey there and utilize the scraping platform when it is needed only after."
 *
 * A product's distributor record (Plugivery's catalog detail, XChange's web assets) gives a thin description
 * through Enrichment (compose_feed() first) and first pictures through the picture runner
 * (Pictures::feed_pictures() first). Each is recorded in `_mmi_feed_filled` with its source.
 *
 * Features, videos, system requirements and plugin formats, platforms and licensing are no longer filled
 * here (owner 2026-11-09, Product Data migration phase 3b): mmi-data-pipeline's Web Assets import profile
 * fills them, XChange then Plugivery, "Only fill when empty", with features cleaned the same way
 * (VendorPage::clean_import_value()). Drafts included.
 *
 * New products are filled within minutes of the import that made them (woocommerce_new_product → one WP-Cron
 * event → run_queue(); the 30-minute `wp mmpt heartbeat` catches any it missed). `wp mmpt feed_fill` covers
 * the whole catalog.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

defined( 'ABSPATH' ) || exit;

final class FeedFill {

	const HOOK    = 'mmpt_feed_fill';
	const CHECKED = '_mmi_feed_checked'; // when this product was last filled from its feed
	const FILLED  = '_mmi_feed_filled';  // { field: source } for what the feed filled
	const NEW_DAYS = 7;                   // run_queue() looks at products made this recently
	const BATCH    = 100;


	public static function register_hooks(): void {
		add_action( 'woocommerce_new_product', [ __CLASS__, 'noted' ], 20 );
		add_action( 'set_object_terms', [ __CLASS__, 'brand_arrived' ], 20, 6 );
		add_action( self::HOOK, [ __CLASS__, 'run_queue' ] );
	}

	/**
	 * A new product got its first brand after it was filled (a supplier value mapped later, a brand
	 * created in the Workbench): fill it again, since its description and pictures were looked up
	 * without one (2026-11-12). fill() only fills gaps, so a second pass adds and never replaces.
	 */
	public static function brand_arrived( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		$id = (int) $object_id;
		if ( $taxonomy !== 'product_brand' || $old_tt_ids || ! $tt_ids || get_post_type( $id ) !== 'product' || ! metadata_exists( 'post', $id, self::CHECKED ) ) {
			return;
		}
		if ( strtotime( (string) get_post_field( 'post_date_gmt', $id ) . ' UTC' ) < time() - self::NEW_DAYS * DAY_IN_SECONDS ) {
			return; // run_queue() only looks at new products; an old one is the catalog-wide `wp mmpt feed_fill`'s
		}
		delete_post_meta( $id, self::CHECKED );
		self::noted();
	}

	/** An import (or a person) made a product: fill it shortly, once the import has set its links and terms. */
	public static function noted(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/** Products made in the last NEW_DAYS days that were never filled. @return array{filled:int,checked:int} */
	public static function run_queue(): array {
		global $wpdb;
		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s
					 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_date_gmt > UTC_TIMESTAMP() - INTERVAL %d DAY AND c.post_id IS NULL
					 ORDER BY p.ID LIMIT %d",
					self::CHECKED,
					self::NEW_DAYS,
					self::BATCH
				)
			)
		);
		$ids    = array_values( array_unique( array_merge( $ids, self::held_unfilled() ) ) );
		$filled = 0;
		foreach ( $ids as $id ) {
			$filled += self::fill( $id ) ? 1 : 0;
		}
		if ( $ids ) {
			Pictures::kick( true );
		}
		return [ 'filled' => $filled, 'checked' => count( $ids ) ];
	}

	/** Products held until a release date and never filled, however long ago they were made: a launch can be weeks away. @return int[] */
	private static function held_unfilled(): array {
		if ( ! class_exists( '\\MannMade\\DataPipeline\\Product_State' ) ) {
			return [];
		}
		return array_values( array_filter( \MannMade\DataPipeline\Product_State::held_ids(), static fn( $id ) => get_post_status( $id ) === 'publish' && ! metadata_exists( 'post', $id, self::CHECKED ) ) );
	}

	/**
	 * Fill one product from its distributors' data.
	 *
	 * @return array<string,string> field => source, for what was (or, dry, would be) filled
	 */
	public static function fill( int $id, bool $dry = false ): array {
		if ( get_post_type( $id ) !== 'product' || get_post_status( $id ) !== 'publish' || Exclusions::is_excluded( $id ) ) {
			return [];
		}
		$rec = self::record( $id );
		$out = [];
		if ( ! $dry ) {
			\MMI_Product_Content_Registry::sync_platforms( $id );
		}

		// A thin description: Enrichment proposes the feed's text first and writes it (content:plugivery / content:web_assets approved).
		$thin = mb_strlen( Descriptions::plain( (string) get_post_field( 'post_content', $id ) ) ) < Enrichment::THIN_CHARS;
		if ( $thin && $rec['desc'] ) {
			if ( ! $dry ) {
				Enrichment::generate( [ $id ] );
				if ( ( Enrichment::apply_ready( $id )['content'] ?? '' ) === 'applied' ) {
					$out['description'] = $rec['desc'];
				}
			} else {
				$out['description'] = $rec['desc'];
			}
		}

		// No picture: the picture runner gives it the feed's pictures first (Pictures::plan()).
		if ( ! Pictures::current( $id ) && Pictures::feed_pictures( $id ) ) {
			$out['pictures'] = 'queued';
			if ( ! $dry ) {
				Pictures::enqueue( [ $id ], 'first' );
			}
		}

		if ( ! $dry ) {
			if ( $out ) {
				$was = json_decode( (string) get_post_meta( $id, self::FILLED, true ), true );
				update_post_meta( $id, self::FILLED, wp_slash( wp_json_encode( array_merge( is_array( $was ) ? $was : [], $out ) ) ) );
			}
			update_post_meta( $id, self::CHECKED, current_time( 'mysql', true ) );
		}
		return $out;
	}

	/**
	 * The product's distributor records: its Plugivery listing (its own, else the same maker's product of the
	 * same name), its XChange web assets and feed row; and which one has a description long enough to use.
	 *
	 * @return array{plugivery:?array,web_assets:?array,xchange:?array,desc:string}
	 */
	public static function record( int $id ): array {
		$pvid    = Pictures::plugivery_match( $id );
		$details = $pvid !== '' && class_exists( '\MMI_Plugivery_Catalog' ) ? \MMI_Plugivery_Catalog::details() : [];
		$x       = trim( (string) get_post_meta( $id, '_mmi_supplier_sku_xchange', true ) );
		$wa      = $x !== '' ? self::web_assets()[ $x ] ?? null : null;
		$rec     = [
			'plugivery'  => $details[ $pvid ] ?? null,
			'web_assets' => $wa,
			'xchange'    => $x !== '' ? self::xchange_row( $x ) : null,
			'desc'       => '',
		];
		if ( $rec['plugivery'] && mb_strlen( trim( wp_strip_all_tags( (string) ( $rec['plugivery']['desc'] ?? '' ) ) ) ) >= Enrichment::THIN_CHARS ) {
			$rec['desc'] = 'plugivery';
		} elseif ( $wa && (int) ( $wa['desc_len'] ?? 0 ) >= Enrichment::THIN_CHARS ) {
			$rec['desc'] = 'web_assets';
		}
		return $rec;
	}

	/**
	 * XChange web assets by SKU: the fields filled here (Enrichment::web_assets_media() keeps the text and
	 * pictures). The 15 MB file → a slim index, an hour in a transient.
	 */
	private static function web_assets(): array {
		static $idx = null;
		if ( $idx === null ) {
			$idx = get_transient( 'mmpt_feed_fill_wa' );
		}
		if ( ! is_array( $idx ) ) {
			$idx  = [];
			$file = mmi_shared_lib_json_dir() . 'xchange-web-assets.json';
			$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
			foreach ( (array) ( $data['web_assets'] ?? [] ) as $r ) {
				if ( ! empty( $r['sku'] ) ) {
					$idx[ (string) $r['sku'] ] = array_intersect_key( $r, array_flip( [ 'features', 'videos', 'requirements', 'licensing', 'platforms' ] ) )
						+ [ 'desc_len' => mb_strlen( trim( wp_strip_all_tags( (string) ( $r['long_description'] ?? '' ) ) ) ) ];
				}
			}
			unset( $data );
			set_transient( 'mmpt_feed_fill_wa', $idx, HOUR_IN_SECONDS );
		}
		return $idx;
	}

	/** One XChange feed row's fields used here (its YouTube link). The 13 MB feed → an hour in a transient. */
	private static function xchange_row( string $sku ): ?array {
		static $rows = null;
		if ( $rows === null ) {
			$rows = get_transient( 'mmpt_feed_fill_xc' );
		}
		if ( ! is_array( $rows ) ) {
			$rows = [];
			$file = mmi_shared_lib_json_dir() . 'xchange-products.json';
			foreach ( (array) ( is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : [] ) as $r ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( is_array( $r ) && ! empty( $r['sku'] ) && ! empty( $r['youtube'] ) ) {
					$rows[ (string) $r['sku'] ] = [ 'youtube' => (string) $r['youtube'] ];
				}
			}
			set_transient( 'mmpt_feed_fill_xc', $rows, HOUR_IN_SECONDS );
		}
		return $rows[ $sku ] ?? null;
	}
}
