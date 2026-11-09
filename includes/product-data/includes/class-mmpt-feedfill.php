<?php
/**
 * Feed first (owner 2026-10-29): "Product data sources contain the assets we're looking for most of the time.
 * We should start the product data journey there and utilize the scraping platform when it is needed only after."
 *
 * A product's distributor record (Plugivery's catalog detail, XChange's web assets and feed) fills what the
 * product lacks: features, videos, system requirements and plugin formats, platforms and licensing, here;
 * a thin description through Enrichment (compose_feed() first); first pictures through the picture runner
 * (Pictures::feed_pictures() first). Only empty fields and products with no picture: nothing is replaced, and
 * field locks are kept. Each filled field is recorded in `_mmi_feed_filled` with its source.
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

	/** Plugivery's formats → the registry's plugin keys. */
	const PLUGIVERY_FORMATS = [ 'au' => 'au', 'vst' => 'vst-2', 'vst3' => 'vst-3', 'aax' => 'aax', 'aaxd' => 'aax', 'rtas' => 'rtas', 'application' => 'standalone' ];
	/** Plugivery's platforms → the registry's. */
	const PLUGIVERY_PLATFORMS = [ 'mac' => 'mac', 'pc' => 'windows', 'linux' => 'linux' ];

	public static function register_hooks(): void {
		add_action( 'woocommerce_new_product', [ __CLASS__, 'noted' ], 20 );
		add_action( self::HOOK, [ __CLASS__, 'run_queue' ] );
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
		foreach ( self::fields( $rec ) as $concept => [ $source, $value ] ) {
			$key = \MMI_Product_Content_Registry::canonical_key( $concept );
			if ( \MMI_Product_Content_Registry::count( $id, $concept ) > 0 || ( class_exists( '\MMI_Pipeline_Field_Locks' ) && \MMI_Pipeline_Field_Locks::is_locked( $id, $key ) ) ) {
				continue;
			}
			if ( $dry || \MMI_Product_Content_Registry::store( $id, $concept, $value, 'product-feed-fill' ) ) {
				$out[ $concept ] = $source;
			}
		}
		if ( ! $dry ) {
			$platforms = self::platforms( $rec );
			$locked    = class_exists( '\MMI_Pipeline_Field_Locks' ) && \MMI_Pipeline_Field_Locks::is_locked( $id, \MMI_Product_Content_Registry::canonical_key( 'platforms' ) );
			if ( $platforms && ! $locked && \MMI_Product_Content_Registry::merge_platforms( $id, $platforms[1], 'product-feed-fill' ) ) {
				$out['platforms'] = $platforms[0];
			}
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

	/** @return array<string,array{0:string,1:mixed}> registry concept => [ source, value ] */
	private static function fields( array $rec ): array {
		$out = [];
		$pv  = $rec['plugivery'];
		$wa  = $rec['web_assets'];

		if ( $wa && array_filter( (array) ( $wa['features'] ?? [] ) ) ) {
			$items = array_column( VendorPage::clean_feed( array_map( static fn( $f ) => [ 'type' => 'paragraph', 'text' => trim( (string) $f ) ], array_filter( (array) $wa['features'], 'is_string' ) ) ), 'text' );
			if ( $items ) {
				$out['features'] = [ 'web_assets', $items ];
			}
		}

		$videos = [];
		$vsrc   = '';
		if ( $wa && ! empty( $wa['videos'] ) ) {
			$videos = array_merge( [ (string) ( $wa['videos']['main'] ?? '' ) ], (array) ( $wa['videos']['extras'] ?? [] ) );
			$vsrc   = 'web_assets';
		}
		if ( ! array_filter( $videos ) && ! empty( $rec['xchange']['youtube'] ) ) {
			$videos = [ (string) $rec['xchange']['youtube'] ];
			$vsrc   = 'xchange';
		}
		if ( ! array_filter( $videos ) && $pv && ! empty( $pv['videos'] ) ) {
			$videos = array_column( (array) $pv['videos'], 'url' );
			$vsrc   = 'plugivery';
		}
		$videos = array_values( array_unique( array_filter( array_map( 'strval', $videos ), static fn( $u ) => preg_match( '#^https?://#i', $u ) ) ) );
		if ( $videos ) {
			$out['videos'] = [ $vsrc, $videos ];
		}

		// System requirements per OS: XChange's web assets give the text; Plugivery's compatibility list the plugin formats.
		$formats = [];
		foreach ( (array) ( $pv['compats'] ?? [] ) as $c ) {
			$os  = self::PLUGIVERY_PLATFORMS[ strtolower( (string) ( $c['platform'] ?? '' ) ) ] ?? '';
			$fmt = self::PLUGIVERY_FORMATS[ strtolower( (string) ( $c['format'] ?? '' ) ) ] ?? '';
			if ( $os !== '' && $fmt !== '' ) {
				$formats[ $os ][ $fmt ] = true;
			}
		}
		foreach ( [ 'mac' => 'requirements_mac', 'windows' => 'requirements_windows', 'linux' => 'requirements_linux' ] as $os => $concept ) {
			$req = (array) ( $wa['requirements'][ $os ] ?? [] );
			$req = array_filter( array_intersect_key( $req, array_flip( array_merge( \MMI_Product_Content_Registry::REQUIREMENT_TEXT_FIELDS, [ 'ram', 'disk' ] ) ) ), static fn( $v ) => is_scalar( $v ) && trim( (string) $v ) !== '' && (string) $v !== '0' );
			if ( $req ) {
				$out[ $concept ] = [ 'web_assets', $req + ( isset( $formats[ $os ] ) ? [ 'plugins' => array_keys( $formats[ $os ] ) ] : [] ) ];
			} elseif ( isset( $formats[ $os ] ) ) {
				$out[ $concept ] = [ 'plugivery', [ 'plugins' => array_keys( $formats[ $os ] ) ] ];
			}
		}

		if ( $wa && ! empty( $wa['licensing']['methods'] ) ) {
			$out['licensing'] = [ 'web_assets', (array) $wa['licensing'] ];
		}
		return $out;
	}

	/** [ source, platforms ] the feed lists, or null. */
	private static function platforms( array $rec ): ?array {
		$from_pv = [];
		foreach ( (array) ( $rec['plugivery']['compats'] ?? [] ) as $c ) {
			$os = self::PLUGIVERY_PLATFORMS[ strtolower( (string) ( $c['platform'] ?? '' ) ) ] ?? '';
			if ( $os !== '' ) {
				$from_pv[ $os ] = true;
			}
		}
		if ( $from_pv ) {
			return [ 'plugivery', array_keys( $from_pv ) ];
		}
		$from_wa = array_map( 'strval', (array) ( $rec['web_assets']['platforms'] ?? [] ) );
		return $from_wa ? [ 'web_assets', $from_wa ] : null;
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
