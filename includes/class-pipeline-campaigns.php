<?php
/**
 * Campaigns: every supplier promotion window becomes a dated campaign on the
 * store, so the storefront, the product feed and the mailing can name it.
 *
 * - A campaign is a term in the `mmi_campaign` taxonomy (public, URL base
 *   `/deals/`), so Bricks query loops, term archives and `{post_terms_mmi_campaign}`
 *   work without plugin templates. Term meta: start, end (Y-m-d), supplier,
 *   code (the supplier's promotion code or id), assets_url (the vendor's
 *   dealer asset folder), reverb_sale_id, hero_image_id.
 * - Membership comes from the distributor feeds through the registered feed
 *   catalogs (`MMI_Pipeline_Feed_Catalog::rows('promotions')`: XChange,
 *   SkuPort, Plugivery), the same rows the Distributors › Promotions tab
 *   shows. A product belongs to a campaign while its promotion is running or
 *   upcoming and the product is published; when the promotion ends the
 *   product leaves the campaign. The term stays as history.
 * - Campaign name: the supplier's promotion name (XChange "Black Friday / EOY
 *   Sale 2026"). Suppliers that name nothing (Plugivery, SkuPort) get
 *   "{Supplier} promotions ending {Month YYYY}" from the promotion's end month.
 *   A name the owner edits on the term is kept; only the slug links the feed
 *   row to the term (`_mmi_campaign_key` term meta).
 * - Sync: hourly (Action Scheduler), after each supplier fetch, and
 *   `wp mmi campaigns sync|list [--dry-run]`.
 * - The owner can also make a campaign by hand (Products › Campaigns) and
 *   assign products in the product editor; hand-made terms (no `_mmi_campaign_key`)
 *   are never touched by the sync.
 *
 * @package MannMade\DataPipeline
 */

namespace MannMade\DataPipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Campaigns {

	const TAXONOMY  = 'mmi_campaign';
	const REWRITE   = 'deals';
	const HOOK_SYNC = 'mmi_pipeline_campaigns_sync';
	const GROUP     = 'mmi-pipeline-campaigns';

	const META_KEY      = '_mmi_campaign_key';  // feed-derived identity: supplier|promotion name (or supplier|YYYY-MM)
	const META_START    = 'start';
	const META_END      = 'end';
	const META_SUPPLIER = 'supplier';
	const META_CODE     = 'code';
	const META_ASSETS   = 'assets_url';
	const META_REVERB   = 'reverb_sale_id';
	const META_HERO     = 'hero_image_id';
	const META_SYNCED   = '_mmi_campaign_synced_at';

	// Customer-facing labels, recomputed on every sync (Bricks reads them as {term_meta:…}).
	const META_SUPPLIER_NAME = 'supplier_name';     // the distributor's own promotion name, kept for staff
	const META_STATE         = 'state';             // active | upcoming | ended
	const META_MAX_DISCOUNT  = 'max_discount';      // whole percent, from our own prices
	const META_DISCOUNT_LBL  = 'discount_label';    // "Up to 65% off"
	const META_SUMMARY       = 'summary';           // "43 EastWest titles"
	const META_DATES_LBL     = 'dates_label';       // "Ends November 2" / "Starts November 4"
	const META_BRANDS        = 'brands';            // JSON, by product count
	const META_HERO_URL      = 'hero_image_url';
	const META_AUTO_NAME     = '_mmi_campaign_auto_name';  // last name the sync wrote; a different name is the owner's
	const META_AUTO_DESC     = '_mmi_campaign_auto_desc';
	const META_HERO_AUTO     = '_mmi_campaign_hero_auto';  // '1' while the hero is the sync's choice

	/** Product meta mirror so a query by campaign end date needs no term join. */
	const PRODUCT_META_END = '_mmi_campaign_end';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_taxonomy' ], 5 );
		add_action( 'init', [ __CLASS__, 'schedule' ], 20 );
		add_action( self::HOOK_SYNC, [ __CLASS__, 'sync' ] );
		// XChange pushes promotion_started / promotion_ending_soon (mmi-distributors 2.7.0): re-sync within the minute.
		add_action( 'mmi_xchange_webhook_event', [ __CLASS__, 'on_supplier_event' ], 10, 2 );
		// A supplier fetch finished (feed catalog index rebuilt): the promotions may have changed.
		add_action( 'mmi_pipeline_feed_catalog_fetched', [ __CLASS__, 'queue_sync' ] );
		add_action( self::TAXONOMY . '_add_form_fields', [ __CLASS__, 'add_form_fields' ] );
		add_action( self::TAXONOMY . '_edit_form_fields', [ __CLASS__, 'edit_form_fields' ] );
		add_action( 'created_' . self::TAXONOMY, [ __CLASS__, 'save_term_fields' ] );
		add_action( 'edited_' . self::TAXONOMY, [ __CLASS__, 'save_term_fields' ] );
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', [ __CLASS__, 'columns' ] );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', [ __CLASS__, 'column' ], 10, 3 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mmi campaigns', [ __CLASS__, 'cli' ] );
		}
	}

	/* ── Taxonomy ──────────────────────────────────────────────────────── */

	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			[ 'product' ],
			[
				'labels'            => [
					'name'          => __( 'Campaigns', 'mmi-data-pipeline' ),
					'singular_name' => __( 'Campaign', 'mmi-data-pipeline' ),
					'search_items'  => __( 'Search campaigns', 'mmi-data-pipeline' ),
					'all_items'     => __( 'All campaigns', 'mmi-data-pipeline' ),
					'edit_item'     => __( 'Edit campaign', 'mmi-data-pipeline' ),
					'add_new_item'  => __( 'Add campaign', 'mmi-data-pipeline' ),
					'menu_name'     => __( 'Campaigns', 'mmi-data-pipeline' ),
				],
				'description'       => __( 'Promotion windows: supplier sales and the store\'s own deals, each with start and end dates.', 'mmi-data-pipeline' ),
				'public'            => true,
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_nav_menus' => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'show_tagcloud'     => false,
				'query_var'         => true,
				'rewrite'           => [ 'slug' => self::REWRITE, 'with_front' => false ],
				'capabilities'      => [
					'manage_terms' => 'manage_woocommerce',
					'edit_terms'   => 'manage_woocommerce',
					'delete_terms' => 'manage_woocommerce',
					'assign_terms' => 'edit_products',
				],
			]
		);
		foreach ( [ self::META_START, self::META_END, self::META_SUPPLIER, self::META_CODE, self::META_ASSETS, self::META_REVERB ] as $key ) {
			register_term_meta( self::TAXONOMY, $key, [ 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field' ] );
		}
		register_term_meta( self::TAXONOMY, self::META_HERO, [ 'type' => 'integer', 'single' => true, 'show_in_rest' => true ] );
	}

	public static function on_supplier_event( string $event, array $data = [] ): void {
		if ( in_array( $event, [ 'promotion_started', 'promotion_ending_soon', 'promotion_updates' ], true ) ) {
			self::queue_sync();
		}
	}

	/** One sync soon, never two queued at once. */
	public static function queue_sync(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_SYNC, [ 'event' ], self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + 60, self::HOOK_SYNC, [ 'event' ], self::GROUP );
	}

	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK_SYNC, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + 600, HOUR_IN_SECONDS, self::HOOK_SYNC, [], self::GROUP );
		}
	}

	/* ── What the feeds say ───────────────────────────────────────────── */

	/**
	 * Running and upcoming promotions grouped into campaigns.
	 *
	 * @return array<string, array{key:string,name:string,supplier:string,code:string,start:string,end:string,products:int[]}>
	 */
	public static function from_feeds(): array {
		if ( ! class_exists( 'MMI_Pipeline_Feed_Catalog' ) ) {
			return [];
		}
		$groups = [];
		foreach ( self::suppliers() as $supplier ) {
			$catalog = \MMI_Pipeline_Feed_Catalog::get( $supplier );
			if ( ! $catalog ) {
				continue;
			}
			try {
				$rows = $catalog->rows( 'promotions' );
			} catch ( \Throwable $e ) {
				if ( class_exists( 'MMI_Logger' ) ) {
					\MMI_Logger::warning( 'Campaigns: could not read promotions', [ 'supplier' => $supplier, 'error' => $e->getMessage() ] );
				}
				continue;
			}
			foreach ( $rows as $row ) {
				$promo = $row['promo'] ?? null;
				$post  = (int) ( $row['site_post'] ?? 0 );
				if ( ! $promo || ! $post || ( $row['site_status'] ?? '' ) !== 'publish' ) {
					continue;
				}
				if ( ! in_array( $promo['state'] ?? '', [ 'active', 'upcoming' ], true ) ) {
					continue;
				}
				$name = trim( (string) ( $promo['name'] ?? '' ) );
				$start = (string) ( $promo['start'] ?? '' );
				$end   = (string) ( $promo['end'] ?? '' );
				if ( $name === '' ) {
					// Nameless promotions (Plugivery, SkuPort) group by the maker and the month
					// they end in: a shopper sees "Overloud Sale", never the distributor's name.
					$month = $end !== '' ? $end : ( $start !== '' ? $start : current_time( 'Y-m-d' ) );
					$brand = self::brand_of( $post );
					$name  = trim( ( $brand['name'] !== '' ? $brand['name'] : self::supplier_label( $supplier ) ) . ' ' . __( 'Sale', 'mmi-data-pipeline' ) );
					$key   = $supplier . '|brand:' . ( $brand['slug'] !== '' ? $brand['slug'] : 'none' ) . '|' . substr( $month, 0, 7 );
				} else {
					$key = $supplier . '|' . mb_strtolower( $name );
				}
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = [
						'key'      => $key,
						'name'     => $name,
						'supplier' => $supplier,
						'code'     => (string) ( $promo['code'] ?? '' ),
						'start'    => $start,
						'end'      => $end,
						'products' => [],
					];
				}
				$g = &$groups[ $key ];
				if ( $start !== '' && ( $g['start'] === '' || $start < $g['start'] ) ) {
					$g['start'] = $start;
				}
				if ( $end !== '' && ( $g['end'] === '' || $end > $g['end'] ) ) {
					$g['end'] = $end;
				}
				if ( $g['code'] === '' && ! empty( $promo['code'] ) ) {
					$g['code'] = (string) $promo['code'];
				}
				$g['products'][ $post ] = $post;
				unset( $g );
			}
		}
		foreach ( $groups as &$g ) {
			$g['products'] = array_values( $g['products'] );
		}
		unset( $g );
		return $groups;
	}

	/** Feed catalog ids to read, in the order the Distributors page lists them. */
	public static function suppliers(): array {
		return (array) apply_filters( 'mmi_pipeline_campaign_suppliers', [ 'xchange', 'skuport', 'plugivery' ] );
	}

	public static function supplier_label( string $supplier ): string {
		$labels = [ 'xchange' => 'XChange', 'skuport' => 'SkuPort', 'plugivery' => 'Plugivery' ];
		return $labels[ $supplier ] ?? ucfirst( $supplier );
	}

	/* ── Sync ─────────────────────────────────────────────────────────── */

	/**
	 * Make the taxonomy match the feeds.
	 *
	 * @return array{campaigns:int,created:int,attached:int,detached:int,dry:bool}
	 */
	public static function sync( $dry = false ): array {
		$dry = $dry === true;
		$stats  = [ 'campaigns' => 0, 'created' => 0, 'attached' => 0, 'detached' => 0, 'dry' => $dry ];
		$groups = self::from_feeds();
		$seen   = [];
		foreach ( $groups as $g ) {
			$stats['campaigns']++;
			$term_id = self::term_for_key( $g['key'] );
			if ( ! $term_id ) {
				if ( $dry ) {
					$stats['created']++;
					$stats['attached'] += count( $g['products'] );
					continue;
				}
				$term_id = self::create_term( $g );
				if ( ! $term_id ) {
					continue;
				}
				$stats['created']++;
			}
			$seen[ $term_id ] = true;
			if ( ! $dry ) {
				self::update_term_dates( $term_id, $g );
			}
			$current = self::members( $term_id );
			$wanted  = array_map( 'intval', $g['products'] );
			$add     = array_diff( $wanted, $current );
			$remove  = array_diff( $current, $wanted );
			$stats['attached'] += count( $add );
			$stats['detached'] += count( $remove );
			if ( $dry ) {
				continue;
			}
			foreach ( $add as $pid ) {
				wp_set_object_terms( $pid, [ $term_id ], self::TAXONOMY, true );
				update_post_meta( $pid, self::PRODUCT_META_END, $g['end'] );
			}
			foreach ( $remove as $pid ) {
				wp_remove_object_terms( $pid, [ $term_id ], self::TAXONOMY );
				delete_post_meta( $pid, self::PRODUCT_META_END );
			}
			update_term_meta( $term_id, self::META_SYNCED, time() );
			if ( empty( get_term_meta( $term_id, self::META_SUPPLIER_NAME, true ) ) ) {
				update_term_meta( $term_id, self::META_SUPPLIER_NAME, $g['name'] );
			}
			self::refresh_display( $term_id );
		}

		// Feed-derived campaigns whose promotions all ended: empty them.
		foreach ( self::feed_terms() as $term_id ) {
			if ( isset( $seen[ $term_id ] ) ) {
				continue;
			}
			$current = self::members( $term_id );
			$stats['detached'] += count( $current );
			if ( $dry ) {
				continue;
			}
			foreach ( $current as $pid ) {
				wp_remove_object_terms( $pid, [ $term_id ], self::TAXONOMY );
				delete_post_meta( $pid, self::PRODUCT_META_END );
			}
			update_term_meta( $term_id, self::META_SYNCED, time() );
			self::maybe_delete_orphan( $term_id );
		}
		// Hand-made campaigns get the same labels (their names stay the owner's).
		if ( ! $dry ) {
			$hand = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids', 'meta_query' => [ [ 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			foreach ( is_array( $hand ) ? $hand : [] as $term_id ) {
				self::refresh_display( (int) $term_id );
			}
		}
		if ( ! $dry && class_exists( 'MMI_Logger' ) ) {
			\MMI_Logger::info( 'Campaigns synced', $stats );
		}
		do_action( 'mmi_pipeline_campaigns_synced', $stats, $dry );
		return $stats;
	}

	private static function term_for_key( string $key ): int {
		$terms = get_terms( [
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'ids',
			'meta_key'   => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		] );
		return is_array( $terms ) && $terms ? (int) $terms[0] : 0;
	}

	/** Every term the sync owns. */
	private static function feed_terms(): array {
		$terms = get_terms( [
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => false,
			'fields'     => 'ids',
			'meta_query' => [ [ 'key' => self::META_KEY, 'compare' => 'EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		] );
		return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
	}

	private static function create_term( array $g ): int {
		$slug   = sanitize_title( $g['name'] );
		$exists = term_exists( $slug, self::TAXONOMY );
		if ( $exists ) {
			// A hand-made term with the same slug: keep it the owner's; give the feed term a suffix.
			$slug .= '-' . $g['supplier'];
		}
		$result = wp_insert_term( $g['name'], self::TAXONOMY, [
			'slug'        => $slug,
			'description' => '',
		] );
		if ( is_wp_error( $result ) ) {
			if ( class_exists( 'MMI_Logger' ) ) {
				\MMI_Logger::warning( 'Campaigns: could not create term', [ 'name' => $g['name'], 'error' => $result->get_error_message() ] );
			}
			return 0;
		}
		$term_id = (int) $result['term_id'];
		update_term_meta( $term_id, self::META_KEY, $g['key'] );
		update_term_meta( $term_id, self::META_SUPPLIER_NAME, $g['name'] );
		update_term_meta( $term_id, self::META_SUPPLIER, $g['supplier'] );
		update_term_meta( $term_id, self::META_CODE, $g['code'] );
		self::update_term_dates( $term_id, $g );
		return $term_id;
	}

	private static function update_term_dates( int $term_id, array $g ): void {
		if ( $g['start'] !== '' ) {
			update_term_meta( $term_id, self::META_START, $g['start'] );
		}
		if ( $g['end'] !== '' ) {
			update_term_meta( $term_id, self::META_END, $g['end'] );
		}
		if ( $g['code'] !== '' && get_term_meta( $term_id, self::META_CODE, true ) === '' ) {
			update_term_meta( $term_id, self::META_CODE, $g['code'] );
		}
	}

	/* ── Customer-facing labels ────────────────────────────────────────── */

	/** @return array{name:string,slug:string,id:int} the product's first brand */
	private static function brand_of( int $post_id ): array {
		static $cache = [];
		if ( isset( $cache[ $post_id ] ) ) {
			return $cache[ $post_id ];
		}
		$terms = taxonomy_exists( 'product_brand' ) ? wp_get_post_terms( $post_id, 'product_brand' ) : [];
		$t     = is_array( $terms ) && $terms ? $terms[0] : null;
		return $cache[ $post_id ] = [ 'name' => $t ? html_entity_decode( $t->name, ENT_QUOTES ) : '', 'slug' => $t ? $t->slug : '', 'id' => $t ? (int) $t->term_id : 0 ];
	}

	/**
	 * A shopper-facing campaign name from the distributor's promotion name:
	 * the maker and the event ("XLN Audio Black Friday Sale"), with dealer
	 * words (US Cost, PP, XCHANGE), date ranges, prices and the vendor's own
	 * percentage claims taken out. Our discount label states the percentage.
	 *
	 * @param string   $raw    the supplier's promotion name
	 * @param string[] $brands member brand names, most products first
	 */
	public static function display_name( string $raw, array $brands ): string {
		$months = 'jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?';
		$day    = '\\d{1,2}(?:st|nd|rd|th)?';
		$s      = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s      = str_replace( [ '‘', '’', '“', '”', '"' ], '', $s );
		$s      = str_replace( [ '—', '–' ], '-', $s );
		$s      = preg_replace( '/Black\\s*Friday\\s*[-\\/&]\\s*Cyber\\s*Monday/i', 'Black Friday & Cyber Monday', $s );
		$s      = preg_replace( [ '/\\bBlackFriday\\b/i', '/\\bCyberMonday\\b/i', '/\\bGreenMonday\\b/i' ], [ 'Black Friday', 'Cyber Monday', 'Green Monday' ], $s );
		$s      = preg_replace( '/\\s*\\/\\s*/', ' / ', $s );
		$s      = preg_replace( '/\\bpromotions?\\s+ending\\b.*$/i', '', $s );
		// Pipe-separated parts: drop the ones that are only dates or percentages.
		$parts = array_map( 'trim', explode( '|', $s ) );
		$parts = array_filter( $parts, static function ( $part ) use ( $months, $day ) {
			$bare = trim( preg_replace( "/\\b(?:{$months})\\.?\\s*{$day}\\b|[-\\s,.]+|\\b\\d{1,3}\\s*%(?:\\s*off)?/i", ' ', $part ) );
			return $bare !== '' && ! preg_match( '/^(?:to|\\d+)?$/i', $bare );
		} );
		$s = implode( ' ', $parts );
		$cut = [
			"/\\b(?:{$months})\\.?\\s*{$day}(?:\\s*(?:-|to)\\s*(?:(?:{$months})\\.?\\s*)?{$day})?\\b/i", // "Nov 3 - 17", "Nov 4th"
			"/\\b\\d{1,2}-(?:{$months})\\b(?:\\s*(?:to|-)\\s*\\d{1,2}-(?:{$months})\\b)?/i",               // "20-Oct to 02-Nov"
			"/\\b(?:{$months})\\b\\.?(?=\\s*(?:20\\d\\d|$))/i",                                          // "OCT 2026"
			'/\\b20\\d\\d\\b/',                                                  // years
			'/\\bup\\s+to\\s+\\d{1,3}\\s*%\\s*(?:off)?/i',                            // "up to 81% OFF"
			'/\\b\\d{1,3}\\s*%\\s*(?:off)?/i',                                         // "30%"
			'/(?:US)?\\$\\s*\\d+(?:\\.\\d+)?/i',                                       // "$339", "US$35"
			'/\\bwith\\s+(?:a\\s+)?free\\b.*$/i',                                       // vendor mechanics we can't promise
			'/\\b(?:US\\s*Cost|All\\s+XCHANGE|XCHANGE|X-?Change|SkuPort|Plugivery|Dealers?|Resellers?|PP)\\b/i',
			'/\\bthe\\s+(?=sale\\b)/i',
		];
		$s = preg_replace( $cut, ' ', $s );
		$s = preg_replace( '/\\bPromo(?:tion)?s?\\b/i', 'Sale', $s );
		$s = preg_replace( '/[\\s:,;\\/\\-]*\\(\\s*\\)/', ' ', $s );
		$s = trim( preg_replace( '/\\s+/', ' ', $s ), " -:|,;./!" );
		$s = str_replace( '!', '', $s );
		$s = preg_replace( '/\\s+[-:]\\s*$|^\\s*[-:]\\s+/', '', $s );
		// Headline case: shouting names come down, lowercase words go up.
		$letters = preg_replace( '/[^A-Za-z]/', '', $s );
		if ( $letters !== '' && strlen( preg_replace( '/[^A-Z]/', '', $letters ) ) / strlen( $letters ) > 0.6 ) {
			$s = ucwords( strtolower( $s ) );
		}
		// Shouted sale words in an otherwise normal name ("SINGLE PLUGINS", "FLASH SALE").
		$s = preg_replace_callback( '/\\b(SINGLE|PLUGINS?|BUNDLES?|SALES?|FLASH|HOLIDAY|ANNUAL|SUB|SPECIAL|LAUNCH|INTRO|RELEASE|EDITION|COLLECTION|BLACK|FRIDAY|CYBER|MONDAY|GREEN|HALLOWEEN|SUMMER|WINTER|SPRING|EARLY|SEASON|SOUND)\\b/', static fn( $m ) => ucfirst( strtolower( $m[1] ) ), $s );
		$small = [ 'a', 'an', 'and', 'at', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'with' ];
		$words = explode( ' ', $s );
		foreach ( $words as $i => $w ) {
			if ( $w !== '' && $w === strtolower( $w ) && ( $i === 0 || ! in_array( $w, $small, true ) ) ) {
				$words[ $i ] = ucfirst( $w );
			}
		}
		$s = implode( ' ', $words );
		foreach ( [ 'FX', 'EQ', 'UA', 'XLN', 'DJ', 'VST', 'MIDI', 'FL', 'AI' ] as $acronym ) {
			$s = preg_replace( '/\\b' . $acronym . '\\b/i', $acronym, $s );
		}
		$s = preg_replace_callback( '/\\b(Ii|Iii|Iv|Vi|Vii|Viii|Ix|Xi|Xii)\\b/', static fn( $m ) => strtoupper( $m[1] ), $s );
		// The maker's own spelling, and the maker first when the name doesn't say whose sale it is.
		$named = false;
		foreach ( $brands as $b ) {
			if ( $b === '' ) {
				continue;
			}
			if ( stripos( $s, $b ) !== false ) {
				$s     = preg_replace( '/' . preg_quote( $b, '/' ) . '/i', $b, $s );
				$named = true;
				continue;
			}
			$tokens   = preg_split( '/\\s+/', $b );
			$initials = count( $tokens ) > 1 ? implode( '', array_map( static fn( $t ) => mb_substr( $t, 0, 1 ), $tokens ) ) : '';
			if ( $initials !== '' && preg_match( '/\\b' . preg_quote( $initials, '/' ) . '\\b/i', $s ) ) {
				$named = true; // "UA" for Universal Audio: the maker's own shorthand stays
			} elseif ( count( $tokens ) > 1 && mb_strlen( $tokens[0] ) >= 3 && preg_match( '/\\b' . preg_quote( $tokens[0], '/' ) . '\\b/i', $s ) ) {
				$s     = preg_replace( '/\\b' . preg_quote( $tokens[0], '/' ) . '\\b/i', $b, $s, 1 ); // "MAAT" → "MAAT Digital"
				$named = true;
			}
		}
		$generic = preg_match( '/^(?:sale|sales|special|deals?|offer|launch sale)?$/i', $s );
		if ( $generic ) {
			$s = trim( ( $brands[0] ?? '' ) . ' ' . ( $s !== '' ? $s : __( 'Sale', 'mmi-data-pipeline' ) ) );
		} elseif ( ! $named && count( $brands ) === 1 && $brands[0] !== '' ) {
			$s = $brands[0] . ' ' . $s;
		} elseif ( ! $named && count( $brands ) === 2 ) {
			$s = $brands[0] . ' & ' . $brands[1] . ' ' . $s;
		}
		if ( ! preg_match( '/\\b(?:sales?|special|deals?|event|offer|release|launch)\\b/i', $s ) ) {
			$s .= ' ' . __( 'Sale', 'mmi-data-pipeline' );
		}
		return trim( preg_replace( '/\\s+/', ' ', $s ) );
	}

	/**
	 * Recompute a campaign's shopper-facing labels from its members and dates.
	 * Feed campaigns also get their name, slug and description, unless the owner
	 * changed them (then the owner's text stays).
	 */
	public static function refresh_display( int $term_id ): void {
		global $wpdb;
		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		$ids = self::members( $term_id );
		$live = [];
		if ( $ids ) {
			$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from ints
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, rp.meta_value AS regular, pr.meta_value AS price, sp.meta_value AS sale, th.meta_value AS thumb
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} rp ON rp.post_id = p.ID AND rp.meta_key = '_regular_price'
				 LEFT JOIN {$wpdb->postmeta} pr ON pr.post_id = p.ID AND pr.meta_key = '_price'
				 LEFT JOIN {$wpdb->postmeta} sp ON sp.post_id = p.ID AND sp.meta_key = '_sale_price'
				 LEFT JOIN {$wpdb->postmeta} th ON th.post_id = p.ID AND th.meta_key = '_thumbnail_id'
				 WHERE p.post_status = 'publish' AND p.ID IN ({$ph})",
				...$ids
			), ARRAY_A );
			$live = (array) $rows;
		}
		$max        = 0;
		$discounted = 0;
		$brands     = [];
		$thumb  = 0;
		$best   = -1;
		foreach ( $live as $r ) {
			$regular = (float) $r['regular'];
			$now     = (float) $r['price'];
			$sale    = (float) $r['sale'];
			$offer   = ( $sale > 0 && $sale < $regular ) ? $sale : $now;
			$pct     = ( $regular > 0 && $offer > 0 && $offer < $regular ) ? (int) floor( ( 1 - $offer / $regular ) * 100 ) : 0;
			$max     = max( $max, $pct );
			if ( $pct >= 5 ) {
				$discounted++;
			}
			$b       = self::brand_of( (int) $r['ID'] );
			if ( $b['name'] !== '' ) {
				$brands[ $b['name'] ] = ( $brands[ $b['name'] ] ?? 0 ) + 1;
			}
			if ( (int) $r['thumb'] > 0 && $pct > $best ) {
				$best  = $pct;
				$thumb = (int) $r['thumb'];
			}
		}
		arsort( $brands );
		$names = array_keys( $brands );
		// A campaign counts the products it actually discounts (a supplier promotion our
		// price doesn't follow yet is no deal to a shopper).
		$count = $discounted > 0 ? $discounted : count( $live );

		$start = (string) get_term_meta( $term_id, self::META_START, true );
		$end   = (string) get_term_meta( $term_id, self::META_END, true );
		$today = current_time( 'Y-m-d' );
		$state = ( $end !== '' && $end < $today ) ? 'ended' : ( ( $start !== '' && $start > $today ) ? 'upcoming' : 'active' );
		$fmt   = static function ( string $ymd ) use ( $today ): string {
			$ts = strtotime( $ymd );
			return $ts ? date_i18n( substr( $ymd, 0, 4 ) === substr( $today, 0, 4 ) ? 'F j' : 'F j, Y', $ts ) : '';
		};
		if ( $state === 'upcoming' ) {
			$dates = sprintf( /* translators: %s: date */ __( 'Starts %s', 'mmi-data-pipeline' ), $fmt( $start ) );
		} elseif ( $state === 'ended' ) {
			$dates = sprintf( /* translators: %s: date */ __( 'Ended %s', 'mmi-data-pipeline' ), $fmt( $end ) );
		} else {
			$dates = $end !== '' ? sprintf( /* translators: %s: date */ __( 'Ends %s', 'mmi-data-pipeline' ), $fmt( $end ) ) : __( 'On now', 'mmi-data-pipeline' );
		}
		if ( count( $names ) === 1 ) {
			$summary = sprintf( _n( '%1$d %2$s title', '%1$d %2$s titles', $count, 'mmi-data-pipeline' ), $count, $names[0] );
		} elseif ( count( $names ) === 2 ) {
			$summary = sprintf( _n( '%1$d title from %2$s and %3$s', '%1$d titles from %2$s and %3$s', $count, 'mmi-data-pipeline' ), $count, $names[0], $names[1] );
		} elseif ( $names ) {
			$summary = sprintf( __( '%1$d titles from %2$s, %3$s and %4$d more makers', 'mmi-data-pipeline' ), $count, $names[0], $names[1], count( $names ) - 2 );
		} else {
			$summary = sprintf( _n( '%d title', '%d titles', $count, 'mmi-data-pipeline' ), $count );
		}
		$discount = $max >= 5 ? sprintf( /* translators: %d: percent */ __( 'Up to %d%% off', 'mmi-data-pipeline' ), $max ) : __( 'Special pricing', 'mmi-data-pipeline' );

		update_term_meta( $term_id, self::META_STATE, $state );
		update_term_meta( $term_id, self::META_MAX_DISCOUNT, $max );
		update_term_meta( $term_id, self::META_DISCOUNT_LBL, $discount );
		update_term_meta( $term_id, self::META_SUMMARY, $summary );
		update_term_meta( $term_id, self::META_DATES_LBL, $dates );
		update_term_meta( $term_id, self::META_BRANDS, wp_json_encode( $names ) );

		// Hero: the main maker's logo, else the best-discounted product's picture.
		$hero_set = (int) get_term_meta( $term_id, self::META_HERO, true );
		if ( ! $hero_set || get_term_meta( $term_id, self::META_HERO_AUTO, true ) === '1' ) {
			$hero = 0;
			if ( $names ) {
				$bt = get_term_by( 'name', $names[0], 'product_brand' );
				$hero = $bt ? (int) get_term_meta( $bt->term_id, 'thumbnail_id', true ) : 0;
			}
			if ( ! $hero ) {
				$hero = $thumb;
			}
			if ( $hero ) {
				update_term_meta( $term_id, self::META_HERO, $hero );
				update_term_meta( $term_id, self::META_HERO_AUTO, '1' );
				$hero_set = $hero;
			}
		}
		$url = $hero_set ? (string) wp_get_attachment_image_url( $hero_set, 'medium_large' ) : '';
		update_term_meta( $term_id, self::META_HERO_URL, $url );

		// Name, slug and description: feed campaigns only, and only while they are still the sync's own.
		if ( get_term_meta( $term_id, self::META_KEY, true ) === '' ) {
			return;
		}
		$supplier_name = (string) get_term_meta( $term_id, self::META_SUPPLIER_NAME, true );
		$auto_name     = (string) get_term_meta( $term_id, self::META_AUTO_NAME, true );
		$current       = html_entity_decode( $term->name, ENT_QUOTES );
		$args          = [];
		if ( $supplier_name !== '' && ( $auto_name === '' ? $current === $supplier_name : $current === $auto_name ) ) {
			$name = self::display_name( $supplier_name, $names );
			if ( $name !== '' && $name !== $current ) {
				$args['name'] = $name;
				// The slug follows while it is still the one made from an earlier automatic name.
				if ( $term->slug === sanitize_title( $current ) || $term->slug === sanitize_title( $current ) . '-' . get_term_meta( $term_id, self::META_SUPPLIER, true ) ) {
					$slug = sanitize_title( $name );
					$args['slug'] = term_exists( $slug, self::TAXONOMY ) ? $slug . '-' . $term_id : $slug;
				}
			}
			update_term_meta( $term_id, self::META_AUTO_NAME, $name );
		}
		$auto_desc = (string) get_term_meta( $term_id, self::META_AUTO_DESC, true );
		$desc      = (string) $term->description;
		if ( $desc === '' || $desc === $auto_desc || preg_match( '/^(?:XChange|SkuPort|Plugivery) promotion,/', $desc ) ) {
			$new_desc = $max >= 5
				? sprintf( /* translators: 1: percent, 2: "43 EastWest titles", 3: "Ends November 2" */ __( 'Save up to %1$d%% on %2$s. %3$s.', 'mmi-data-pipeline' ), $max, $summary, $dates )
				: sprintf( /* translators: 1: "43 EastWest titles", 2: "Ends November 2" */ __( 'Special pricing on %1$s. %2$s.', 'mmi-data-pipeline' ), $summary, $dates );
			if ( $new_desc !== $desc ) {
				$args['description'] = $new_desc;
			}
			update_term_meta( $term_id, self::META_AUTO_DESC, $new_desc );
		}
		if ( $args ) {
			$r = wp_update_term( $term_id, self::TAXONOMY, $args );
			if ( is_wp_error( $r ) && isset( $args['slug'] ) ) {
				unset( $args['slug'] );
				wp_update_term( $term_id, self::TAXONOMY, $args );
			}
		}
	}

	/**
	 * Remove a feed campaign that holds nothing and never will: the brand-less
	 * monthly groups the first build made (2026-10-29), and campaigns over a
	 * month past their end. A campaign that ever fed a Reverb sale is kept.
	 */
	private static function maybe_delete_orphan( int $term_id ): void {
		if ( self::members( $term_id ) || get_term_meta( $term_id, 'reverb_sale_id', true ) !== '' || get_term_meta( $term_id, '_reverb_sale_members', true ) ) {
			return;
		}
		$key = (string) get_term_meta( $term_id, self::META_KEY, true );
		$end = (string) get_term_meta( $term_id, self::META_END, true );
		$stale_month_group = (bool) preg_match( '/^(?:skuport|plugivery)\\|\\d{4}-\\d{2}$/', $key );
		$long_over         = $end !== '' && $end < gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		if ( $stale_month_group || $long_over ) {
			wp_delete_term( $term_id, self::TAXONOMY );
		}
	}

	/** Product ids in a campaign (any status). */
	public static function members( int $term_id ): array {
		$ids = get_objects_in_term( $term_id, self::TAXONOMY );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/* ── Reading campaigns (for the storefront, the feed, the mailing) ─── */

	/**
	 * @return array<int, array{id:int,name:string,slug:string,url:string,start:string,end:string,supplier:string,code:string,state:string,count:int}>
	 */
	public static function all( bool $running_only = false ): array {
		$terms = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
		if ( ! is_array( $terms ) ) {
			return [];
		}
		$out = [];
		foreach ( $terms as $t ) {
			$c = self::describe( $t );
			if ( $running_only && $c['state'] !== 'active' ) {
				continue;
			}
			$out[] = $c;
		}
		usort( $out, static fn( $a, $b ) => [ $a['state'] !== 'active', $a['end'] ] <=> [ $b['state'] !== 'active', $b['end'] ] );
		return $out;
	}

	public static function describe( \WP_Term $t ): array {
		$start = (string) get_term_meta( $t->term_id, self::META_START, true );
		$end   = (string) get_term_meta( $t->term_id, self::META_END, true );
		$today = current_time( 'Y-m-d' );
		if ( $end !== '' && $end < $today ) {
			$state = 'ended';
		} elseif ( $start !== '' && $start > $today ) {
			$state = 'upcoming';
		} else {
			$state = 'active';
		}
		$link = get_term_link( $t );
		return [
			'id'       => (int) $t->term_id,
			'name'     => $t->name,
			'slug'     => $t->slug,
			'url'      => is_wp_error( $link ) ? '' : $link,
			'start'    => $start,
			'end'      => $end,
			'supplier' => (string) get_term_meta( $t->term_id, self::META_SUPPLIER, true ),
			'code'     => (string) get_term_meta( $t->term_id, self::META_CODE, true ),
			'state'    => $state,
			'count'    => (int) $t->count,
			'feed'     => get_term_meta( $t->term_id, self::META_KEY, true ) !== '',
		];
	}

	/** The campaign a product is in, if any (first term). */
	public static function for_product( int $product_id ): ?array {
		$terms = get_the_terms( $product_id, self::TAXONOMY );
		if ( ! is_array( $terms ) || ! $terms ) {
			return null;
		}
		return self::describe( $terms[0] );
	}

	/* ── Term screen fields ────────────────────────────────────────────── */

	private static function fields(): array {
		return [
			self::META_START    => [ __( 'Starts', 'mmi-data-pipeline' ), 'date', __( 'First day of the promotion (store time).', 'mmi-data-pipeline' ) ],
			self::META_END      => [ __( 'Ends', 'mmi-data-pipeline' ), 'date', __( 'Last day of the promotion. Products leave a feed campaign when their promotion ends.', 'mmi-data-pipeline' ) ],
			self::META_SUPPLIER => [ __( 'Supplier', 'mmi-data-pipeline' ), 'text', __( 'xchange, skuport, plugivery, or blank for the store\'s own campaign.', 'mmi-data-pipeline' ) ],
			self::META_CODE     => [ __( 'Promotion code or id', 'mmi-data-pipeline' ), 'text', __( 'The supplier\'s promotion code (XChange "Promo ID").', 'mmi-data-pipeline' ) ],
			self::META_ASSETS   => [ __( 'Dealer assets', 'mmi-data-pipeline' ), 'url', __( 'Link to the vendor\'s banner and copy folder for this promotion.', 'mmi-data-pipeline' ) ],
			self::META_REVERB   => [ __( 'Reverb sale id', 'mmi-data-pipeline' ), 'text', __( 'The Reverb sales event this campaign\'s listings join (mmi-reverb-integration reads it).', 'mmi-data-pipeline' ) ],
			self::META_HERO     => [ __( 'Hero image id', 'mmi-data-pipeline' ), 'number', __( 'Media library id of the banner used on the campaign page and in the campaign email.', 'mmi-data-pipeline' ) ],
		];
	}

	public static function add_form_fields(): void {
		wp_nonce_field( 'mmi_campaign_fields', 'mmi_campaign_nonce' );
		foreach ( self::fields() as $key => [ $label, $type, $help ] ) {
			echo '<div class="form-field"><label for="mmi-campaign-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
			echo '<input type="' . esc_attr( $type ) . '" name="mmi_campaign[' . esc_attr( $key ) . ']" id="mmi-campaign-' . esc_attr( $key ) . '" value="">';
			echo '<p class="description">' . esc_html( $help ) . '</p></div>';
		}
	}

	public static function edit_form_fields( \WP_Term $term ): void {
		wp_nonce_field( 'mmi_campaign_fields', 'mmi_campaign_nonce' );
		$key = get_term_meta( $term->term_id, self::META_KEY, true );
		if ( $key !== '' ) {
			echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Source', 'mmi-data-pipeline' ) . '</th><td>';
			echo '<p class="description">' . esc_html( sprintf( /* translators: %s: supplier promotion key */ __( 'Built from the supplier feed (%s). Dates and products follow the feed; the name, assets and Reverb sale are yours to edit.', 'mmi-data-pipeline' ), $key ) ) . '</p></td></tr>';
		}
		foreach ( self::fields() as $meta => [ $label, $type, $help ] ) {
			$value = get_term_meta( $term->term_id, $meta, true );
			echo '<tr class="form-field"><th scope="row"><label for="mmi-campaign-' . esc_attr( $meta ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="' . esc_attr( $type ) . '" name="mmi_campaign[' . esc_attr( $meta ) . ']" id="mmi-campaign-' . esc_attr( $meta ) . '" value="' . esc_attr( (string) $value ) . '">';
			echo '<p class="description">' . esc_html( $help ) . '</p></td></tr>';
		}
	}

	public static function save_term_fields( int $term_id ): void {
		if ( ! isset( $_POST['mmi_campaign_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mmi_campaign_nonce'] ) ), 'mmi_campaign_fields' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$in = isset( $_POST['mmi_campaign'] ) && is_array( $_POST['mmi_campaign'] ) ? wp_unslash( $_POST['mmi_campaign'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitized below
		foreach ( self::fields() as $meta => [ , $type ] ) {
			if ( ! array_key_exists( $meta, $in ) ) {
				continue;
			}
			$raw = (string) $in[ $meta ];
			if ( $type === 'date' ) {
				$value = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
			} elseif ( $type === 'url' ) {
				$value = esc_url_raw( $raw );
			} elseif ( $type === 'number' ) {
				$value = absint( $raw );
			} else {
				$value = sanitize_text_field( $raw );
			}
			if ( $value === '' || $value === 0 ) {
				delete_term_meta( $term_id, $meta );
			} else {
				update_term_meta( $term_id, $meta, $value );
			}
		}
	}

	public static function columns( array $columns ): array {
		$out = [];
		foreach ( $columns as $k => $v ) {
			$out[ $k ] = $v;
			if ( $k === 'name' ) {
				$out['mmi_dates']    = __( 'Dates', 'mmi-data-pipeline' );
				$out['mmi_supplier'] = __( 'Supplier', 'mmi-data-pipeline' );
			}
		}
		return $out;
	}

	public static function column( string $content, string $column, int $term_id ): string {
		if ( $column === 'mmi_dates' ) {
			$start = (string) get_term_meta( $term_id, self::META_START, true );
			$end   = (string) get_term_meta( $term_id, self::META_END, true );
			$t     = get_term( $term_id, self::TAXONOMY );
			$state = $t instanceof \WP_Term ? self::describe( $t )['state'] : '';
			return esc_html( trim( ( $start ?: '?' ) . ' → ' . ( $end ?: '?' ) ) ) . ( $state ? ' <span class="description">(' . esc_html( $state ) . ')</span>' : '' );
		}
		if ( $column === 'mmi_supplier' ) {
			$supplier = (string) get_term_meta( $term_id, self::META_SUPPLIER, true );
			return esc_html( $supplier !== '' ? self::supplier_label( $supplier ) : __( 'Store', 'mmi-data-pipeline' ) );
		}
		return $content;
	}

	/* ── WP-CLI ───────────────────────────────────────────────────────── */

	/**
	 * wp mmi campaigns sync [--dry-run]   make the Campaigns taxonomy match the supplier feeds
	 * wp mmi campaigns list               every campaign with dates, state and product count
	 * wp mmi campaigns preview            what the feeds would make, without touching terms
	 */
	public static function cli( $args, $assoc ): void {
		$sub = (string) ( $args[0] ?? 'list' );
		if ( $sub === 'sync' ) {
			$stats = self::sync( ! empty( $assoc['dry-run'] ) );
			\WP_CLI::log( sprintf( '%s: %d campaigns, %d created, %d products attached, %d detached.', $stats['dry'] ? 'Dry run' : 'Synced', $stats['campaigns'], $stats['created'], $stats['attached'], $stats['detached'] ) );
			return;
		}
		if ( $sub === 'preview' ) {
			foreach ( self::from_feeds() as $g ) {
				\WP_CLI::log( sprintf( '%-10s %-60s %s → %s  %d products  code %s', $g['supplier'], mb_substr( $g['name'], 0, 60 ), $g['start'] ?: '?', $g['end'] ?: '?', count( $g['products'] ), $g['code'] ?: '-' ) );
			}
			return;
		}
		foreach ( self::all() as $c ) {
			\WP_CLI::log( sprintf( '%-8s %-10s %-60s %s → %s  %d products  /%s/%s/%s', $c['state'], $c['supplier'] ?: 'store', mb_substr( $c['name'], 0, 60 ), $c['start'] ?: '?', $c['end'] ?: '?', $c['count'], self::REWRITE, $c['slug'], $c['feed'] ? '' : ' (hand-made)' ) );
		}
	}
}
