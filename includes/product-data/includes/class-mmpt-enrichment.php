<?php
/**
 * Product Data Quality Phase 5 — fill thin descriptions and missing
 * featured images from data the store already holds. Proposals first, the
 * same way titles work: nothing reaches a product until a person accepts a
 * row (or approves its source) and switches applying on.
 *
 * Content: products whose post_content is under THIN_CHARS of text get the
 * vendor's own prose, verbatim — headings, paragraphs and lists as the
 * vendor structured them — never generated text (§6d: "use a PHP template,
 * not AI"). Sources, most trusted first: XChange web-assets
 * long_description (vendor-supplied), then the vendor page paragraphs
 * VendorFetcher stored. Not description_scrub: it is AI-rewritten copy. Features, requirements and videos are NOT copied in:
 * the Single Product template already renders them from the registry.
 *
 * Images: products with no featured image get the product's own pictures
 * from its verified vendor page first (VendorPage reader over the stored
 * page: JSON-LD, Open Graph, gallery; owner 2026-10-05 "the vendor's product
 * page is the preferred source", 2026-10-07 "make sure you're grabbing
 * product images"), else the first real image from web-assets images[]
 * (logos and blanks skipped). Small pictures are kept and marked low-res
 * (owner: never discard for size). Sideloaded through MMI_Media_Helper
 * (dedup by source URL), with the product title as alt.
 *
 * Plugivery (products keyed `_mmi_supplier_sku_plugivery`, 2026-09-29) is a
 * third, distributor-supplied source: act=get `desc` and typed `images[]`
 * from mmi-data-pipeline's plugivery-product-details.json. Used only when
 * web-assets and the vendor page have nothing. Its images are downloaded and
 * rehosted like any other — Plugivery forbids hotlinking (account revocation).
 *
 * Picker (owner 2026-10-08): an image row keeps every candidate picture
 * (MAX_CANDIDATES) and the person's pick in `chosen` — featured first, then
 * the gallery. Products that already have pictures get a vendor-page row
 * too (propose_for_imaged(), CLI): `current_value` holds their pictures as
 * {thumb, gallery}, they can be kept in the pick as "att:ID", and applying
 * replaces the set exactly (revert puts the old set back). Without a pick, an
 * imageless product gets the first 1 + MAX_GALLERY pictures, as before.
 * Every downloaded picture becomes the standard 800×800 JPEG on white under
 * ~100 KB (ImageConvert); AVIF is decoded by Chromium, so a row with AVIF
 * waits for the next CLI run when applied from a web request.
 *
 * Apply: re-checks the product is still thin/imageless, keeps the raw
 * original for revert(), locks post_content (Field Locks) so an import can't
 * undo it. Accepting a row starts the writer at once, like titles (owner
 * 2026-10-04: no daily caps); batches of BATCH, GAP_S apart.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Enrichment {

	const DB_VERSION   = '2'; // 2: `chosen` (image picker, 2026-10-08)
	const DB_OPTION    = 'mmpt_enrich_db_version';
	const THIN_CHARS   = PageEvents::THIN_CHARS;

	/** refresh_for_review() is running: the refreshes it proposes wait for a person (next() skips them). */
	private static bool $hold = false;
	const KINDS        = [ 'content', 'image' ];
	const SOURCES      = [
		'content:web_assets'  => 'Description from XChange web-assets (vendor-supplied)',
		'content:vendor_page' => 'Description from the vendor page fetched now',
		'image:vendor_page'   => "Pictures for products with none, from the product's own vendor page (the picture runner writes them)",
		'image:web_assets'          => 'Featured image from XChange web-assets',
		'content:plugivery'   => 'Description from the Plugivery catalog (distributor-supplied)',
		'content:maker_replaces' => "The vendor page's description replaces one that isn't the maker's, on Scrape (the old text is kept; Revert puts it back)",
		'fields:vendor_page'  => 'Empty features, videos, manuals, audio demos, version, weight and size from the vendor page, without a confirmed vendor map (empty fields only)',
		'image:plugivery'     => 'Featured image + gallery from the Plugivery catalog (downloaded, never hotlinked)',
	];

	/** Picture sources retired by the owner's D2 (2026-10-08): no new rows; their written rows can still be undone. */
	const RETIRED = [ 'image:web_assets', 'image:plugivery' ];

	/** Plugivery image types, best storefront image first. */
	const PLUGIVERY_IMAGE_ORDER = [ 'box' => 0, 'gui' => 1, 'home2' => 2, 'home1' => 3, 'misc' => 4 ];
	const RULES_KEY    = 'mmi_product_enrichment_rules';
	const APPLY_KEY    = 'mmi_product_enrichment_apply';
	const STATE        = 'mmi_admin_product_enrichment_state';
	const HOOK         = 'mmpt_enrich_apply_batch';
	const DAILY_HOOK   = 'mmpt_daily_enrich';
	const BATCH        = 20;
	const GAP_S        = 120;
	const BATCH_S      = 25; // stop starting rows after this: the cron runner kills a request at ~45 s, and a killed batch never queued the next one (2026-10-08: 13 descriptions, then nothing)
	const MAX_GALLERY  = 4;
	const MAX_CANDIDATES = 12; // pictures offered to the picker

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_enrichment';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  product_id bigint(20) unsigned NOT NULL,
  kind varchar(10) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'proposed',
  source varchar(40) NOT NULL DEFAULT '',
  source_url varchar(500) NOT NULL DEFAULT '',
  current_value longtext,
  proposed_value longtext,
  original_value longtext,
  detail longtext,
  chosen longtext,
  composed_at datetime DEFAULT NULL,
  reviewed_by bigint(20) unsigned DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  applied_at datetime DEFAULT NULL,
  PRIMARY KEY  (product_id, kind),
  KEY status_kind (status, kind),
  KEY source (source)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	public static function register_hooks(): void {
		add_action( self::HOOK, [ __CLASS__, 'run_batch' ] );
		add_action( self::DAILY_HOOK, [ __CLASS__, 'daily' ] );
		add_filter( 'mmi_seo_product_data_coverage', [ __CLASS__, 'seo_coverage' ] );
		add_filter( 'mmi_seo_product_data_ready_ids', [ __CLASS__, 'seo_ready_ids' ] );
	}

	/**
	 * MMI SEO's question "how many products have product data good enough to write SEO from?" (its Meta
	 * health accounting of AI sources): published products whose description is the maker's, written by this
	 * module from the maker's page or the family copy, and still in use (applied, not reverted).
	 *
	 * @param array|null $answer Another plugin's answer, kept if given.
	 * @return array{with:int, what:string}|null
	 */
	public static function seo_coverage( $answer ) {
		if ( is_array( $answer ) ) {
			return $answer;
		}
		$ids = self::seo_ready_ids( null );
		return $ids === null ? $answer : [ 'with' => count( $ids ), 'what' => __( "a description from the maker's page", 'mmi-data-pipeline' ) ];
	}

	/**
	 * The same products by ID, for MMI SEO to write AI drafts only for products with their maker's description
	 * (`mmi_seo_product_data_ready_ids`). One query, kept for the request.
	 *
	 * @param int[]|null $answer Another plugin's answer, kept if given.
	 * @return int[]|null
	 */
	public static function seo_ready_ids( $answer ) {
		static $memo = null;
		if ( is_array( $answer ) ) {
			return $answer;
		}
		if ( $memo !== null ) {
			return $memo;
		}
		global $wpdb;
		$t = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			return $answer;
		}
		$memo = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT e.product_id FROM {$t} e JOIN {$wpdb->posts} p ON p.ID = e.product_id AND p.post_type = 'product' AND p.post_status = 'publish' WHERE e.kind = 'content' AND e.status = 'applied' AND e.source IN ('vendor_page','family_copy')" ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name only
		return $memo;
	}

	public static function schedule_daily(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			// 05:10 site time — after descriptions (04:40) and titles (04:50).
			$ts = strtotime( 'tomorrow 05:10', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			wp_schedule_event( $ts, 'daily', self::DAILY_HOOK );
		}
	}

	public static function daily(): void {
		self::generate();
		self::dispatch();
		// First pictures for products with none, while the owner approves vendor-page pictures and writing is on.
		if ( ! empty( self::rules()['image:vendor_page']['approved'] ) && ! empty( self::settings()['enabled'] ) && Pictures::queue_first() ) {
			Pictures::kick( true );
		}
	}

	/* ── Settings ───────────────────────────────────────────────────────── */

	public static function rules(): array {
		$saved = \MMI_Settings::get( self::RULES_KEY, [] );
		$out   = [];
		foreach ( self::SOURCES as $id => $label ) {
			$retired    = in_array( $id, self::RETIRED, true );
			$out[ $id ] = [ 'label' => $label, 'approved' => ! $retired && ! empty( $saved[ $id ]['approved'] ), 'at' => (string) ( $saved[ $id ]['at'] ?? '' ), 'retired' => $retired ];
		}
		return $out;
	}

	public static function set_rule( string $id, bool $approved ): void {
		if ( ! isset( self::SOURCES[ $id ] ) || ( $approved && in_array( $id, self::RETIRED, true ) ) ) {
			return;
		}
		$saved        = (array) \MMI_Settings::get( self::RULES_KEY, [] );
		$saved[ $id ] = [ 'approved' => $approved, 'by' => get_current_user_id(), 'at' => current_time( 'mysql' ) ];
		\MMI_Settings::set( self::RULES_KEY, $saved );
	}

	public static function settings(): array {
		$s = (array) \MMI_Settings::get( self::APPLY_KEY, [] );
		return [ 'enabled' => ! empty( $s['enabled'] ) ];
	}

	public static function save_settings( array $in ): array {
		$s = array_merge( self::settings(), array_intersect_key( $in, self::settings() ) );
		\MMI_Settings::set( self::APPLY_KEY, $s );
		return self::settings();
	}

	/* ── Generation ─────────────────────────────────────────────────────── */

	/**
	 * Propose content and images for every eligible product that needs them.
	 * Open rows are refreshed; accepted/rejected/applied/reverted rows keep
	 * their decision unless the proposal itself changed.
	 */
	/**
	 * Descriptions this module wrote, nobody edited since, and the reader now reads differently: proposed again, held
	 * for a person in One page's Proposals view (owner 2026-10-26: "refresh … in batches for you to review"), never
	 * written by the writer on its own. Accepting one writes it with the original kept for Undo.
	 */
	public static function refresh_for_review( array $ids ): array {
		self::$hold = true;
		try {
			return self::generate( $ids );
		} finally {
			self::$hold = false;
		}
	}

	public static function generate( array $only = [] ): array {
		global $wpdb;
		self::maybe_install();
		$t     = self::table();
		$run   = current_time( 'mysql' );
		// $only: just these products (a scrape on the Vendor pages tab), leaving every other row alone.
		$ids   = $only ? Exclusions::filter( array_values( array_filter( array_map( 'intval', $only ), static fn( $i ) => get_post_status( $i ) === 'publish' ) ) ) : Exclusions::eligible_ids();
		$stats = [ 'content' => 0, 'image' => 0, 'thin_without_source' => 0, 'imageless_without_source' => 0 ];
		$wa    = self::web_assets_media();
		$pv    = self::plugivery_media();
		$maker = $only && self::rules()['content:maker_replaces']['approved']; // owner 2026-10-21: the maker's copy replaces what isn't the maker's (Scrape only)
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in    = implode( ',', $chunk );
			// audit-ok: $in is a chunk of ids intval'd by Exclusions::filter()/eligible_ids()
			$posts = $wpdb->get_results( "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE ID IN ({$in})", ARRAY_A );
			$meta  = [];
			// audit-ok: $in is a chunk of ids intval'd by Exclusions::filter()/eligible_ids()
			foreach ( $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_sku','_thumbnail_id','_mmi_supplier_sku_plugivery','vendor_product_url','vendor_product_url_verified')", ARRAY_A ) as $m ) {
				$meta[ (int) $m['post_id'] ][ $m['meta_key'] ] = $m['meta_value'];
			}
			$descs    = Descriptions::get_many( $chunk );
			$existing = [];
			// audit-ok: $in is a chunk of ids intval'd by Exclusions::filter()/eligible_ids()
			foreach ( $wpdb->get_results( "SELECT product_id, kind, status, source, proposed_value, original_value, detail FROM {$t} WHERE product_id IN ({$in})", ARRAY_A ) as $r ) {
				$existing[ (int) $r['product_id'] ][ $r['kind'] ] = $r;
			}
			foreach ( $posts as $p ) {
				$id  = (int) $p['ID'];
				$sku  = (string) ( $meta[ $id ]['_sku'] ?? '' );
				$pvid = trim( (string) ( $meta[ $id ]['_mmi_supplier_sku_plugivery'] ?? '' ) );
				// A scrape ($only) may refresh a description this module wrote, while nobody has edited it since.
				$was  = $existing[ $id ]['content'] ?? null;
				// Family copy (Product Variants' edited maker text per edition) is never refreshed from the raw page.
				// The distributor's text is kept too: the maker's page is read for what the feed lacks (owner 2026-10-29).
				$ours = $only && $was && $was['status'] === 'applied' && ! in_array( $was['source'], [ 'family_copy', 'plugivery', 'web_assets' ], true ) && trim( (string) $p['post_content'] ) === trim( (string) $was['proposed_value'] );
				$thin = mb_strlen( Descriptions::plain( $p['post_content'] ) ) < self::THIN_CHARS;
				// A full description that isn't the maker's: replaced when the owner approved it. A row this module
				// applied that someone edited since, or one a person reverted, stays as it is.
				$replace = $maker && ! $ours && ! $thin && ! ( $was && ( in_array( $was['status'], [ 'applied', 'reverted' ], true ) || strpos( (string) $was['detail'], 'refresh' ) !== false ) );
				if ( $ours || $thin || $replace ) {
					$shared = SharedPages::description_for( $id, (string) ( $descs[ $id ]['vendor_url'] ?? '' ) ); // its share of a page others use too
					// Feed first (owner 2026-10-29: "start the product data journey there and utilize the scraping platform
					// when it is needed only after"): a thin description takes the distributor's text before the maker's page.
					$c = $thin && ! $ours
						? ( self::compose_feed( $pvid, $wa[ $sku ]['blocks'] ?? [], $pv, $id ) ?? self::compose_content( [], $descs[ $id ] ?? null, $shared ) )
						: ( self::compose_content( $wa[ $sku ]['blocks'] ?? [], $descs[ $id ] ?? null, $shared )
							?? self::compose_plugivery( $pv[ $pvid !== '' ? $pvid : Pictures::plugivery_match( $id ) ]['blocks'] ?? [] ) );
					if ( $c && $replace && ( $c['source'] !== 'vendor_page' || trim( $c['proposed_value'] ) === trim( (string) $p['post_content'] ) ) ) {
						$c = null; // only the maker's own words replace a full description, and only when they differ
					}
					if ( $c && $c['source'] === 'vendor_page' && self::is_home( (string) ( $c['source_url'] ?? '' ) ) ) {
						$c = null; // a site's home page isn't this product's page: its copy is the site's (bitwig.com, 2026-10-21)
					}
					if ( $c && $ours ) {
						// A held refresh is the maker's page read again, never another source standing in for it.
						if ( ( ! self::$hold || $c['source'] === 'vendor_page' ) && trim( $c['proposed_value'] ) !== trim( (string) $was['proposed_value'] ) ) {
							self::refresh_written( $id, $c, $was, (string) $p['post_content'] );
							$stats['content']++;
						}
					} elseif ( $c ) {
						self::put( $id, 'content', $c + [ 'current_value' => $p['post_content'], 'replace' => $replace ], $existing[ $id ]['content'] ?? null );
						$stats['content']++;
					} elseif ( $thin ) {
						$stats['thin_without_source']++;
					}
				}
				// Pictures: the picture runner (Pictures) writes every product's pictures since 2026-10-08,
				// first pictures included (queue_first() in daily()); no image rows are proposed here.
			}
		}
		// Undecided rows this run didn't re-propose (fixed at the source, product drafted, source dropped) go.
		// Accepted rows stay: a person decided them, and apply_one() marks them stale if the product no
		// longer needs them.
		// Rows for products that already have pictures belong to propose_for_imaged(), not this run.
		$scope            = $only ? ' AND product_id IN (' . ( implode( ',', array_map( 'intval', $only ) ) ?: '0' ) . ')' : '';
		$stats['removed'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE status IN ('proposed','stale','error') AND composed_at < %s AND NOT ( kind = 'image' AND current_value <> '' ) AND LOCATE( 'refresh', COALESCE( detail, '' ) ) = 0{$scope}", $run ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- a refresh of our own write waits for a person, not the next run
		if ( $only ) {
			return $stats;
		}
		Module::set_state( self::STATE, [ 'generated_at' => current_time( 'mysql' ), 'generate' => $stats ] );
		Module::info( 'Enrichment proposals generated', $stats );
		return $stats;
	}

	/**
	 * The product's own pictures from its stored vendor page (never fetched here:
	 * the nightly vendor fetch stores pages). A page about another version is
	 * skipped. @return array<int,array{url:string,width:int,height:int,low_res?:bool}>
	 */
	public static function vendor_page_images( string $url, string $title, int $id = 0 ): array {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return [];
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, html_file FROM ' . PageStore::table() . ' WHERE url_hash = %s', md5( $url ) ), ARRAY_A );
		if ( ! $row || $row['status'] !== 'ok' ) {
			return [];
		}
		$html = PageStore::read( (string) $row['html_file'] );
		$html = $id ? ProductPage::own_html( $id, $url, $html ) : $html; // a page listing several products: its own card only
		if ( $html === null || $html === '' ) {
			return [];
		}
		$page = VendorPage::extract( $html, $url, html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( in_array( 'page_version_differs', $page['flags'], true ) ) {
			return [];
		}
		$out = [];
		foreach ( $page['images'] as $i ) {
			if ( ! empty( $i['url'] ) && preg_match( '#^https?://#i', $i['url'] ) ) {
				$out[] = [ 'url' => self::largest_variant( $i['url'], $html ) ] + $i;
			}
		}
		return $out;
	}

	/**
	 * A shop's own resized copy → its full-size picture, as the Chrome extension's picker and mapper do (map-select.js
	 * fullSize(), the same rules): Shopify "x.jpg?width=917" / "x_400x400.jpg" / "x_small_cropped.jpg" → "x.jpg"; WordPress "x-300x300.jpg" →
	 * "x.jpg"; and (owner 2026-11-02: "most sites have an easy to guess/determine image URL structure") imgix's w/h/fit
	 * parameters, Cloudinary's transformation segment, Wix's /v1/fill/…, Squarespace's ?format=, BigCommerce's
	 * /stencil/<size>/ → original, Magento's /cache/<hash>/. Those six, and Shopify's named sizes, are conventions, not promises: their full-size
	 * address is used once it answered with a picture (exists(), kept FULL_DAYS); otherwise the address as found.
	 */
	public static function full_size( string $url, bool $check = true ): string {
		[ $out, $sure ] = self::full_size_of( $url );
		if ( $out === $url || $sure || ! $check ) {
			return $out;
		}
		return self::exists( $out ) ? $out : $url;
	}

	const FULL_DAYS     = 30;
	const SHOPIFY_NAMED = 'pico|icon|thumb|small|compact|medium|large|grande|master|original';

	/** @return array{0:string,1:bool} the full-size address, and whether its rule always holds (Shopify, WordPress) */
	private static function full_size_of( string $url ): array {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) || empty( $p['path'] ) ) {
			return [ $url, true ];
		}
		$host = strtolower( (string) $p['host'] );
		$path = (string) $p['path'];
		parse_str( (string) ( $p['query'] ?? '' ), $q );
		$sure = false;
		if ( preg_match( '/(^|\.)shopify\.com$/', $host ) || strpos( $path, '/cdn/shop/' ) !== false ) {
			$q    = array_diff_key( $q, [ 'width' => 1, 'height' => 1, 'crop' => 1 ] );
			$path = (string) preg_replace( '/_(?:\d+x\d*|\d*x\d+)(?:_crop_\w+)?(?=(?:@\dx)?\.\w+$)/', '', $path, -1, $sized );
			$path = (string) preg_replace( '/_(?:' . self::SHOPIFY_NAMED . ')(?:_cropped)?(?=(?:@\dx)?\.\w+$)/', '', $path, -1, $named ); // Shopify's older named sizes ("_small_cropped": soundiron's thumbnails)
			$sure = ! $named; // "drum_large.jpg" may be the file's own name: checked
		} elseif ( strpos( $path, '/wp-content/uploads/' ) !== false ) {
			$path = (string) preg_replace( '/-\d{2,4}x\d{2,4}(?=\.(?:jpe?g|png|webp|gif|avif)$)/i', '', $path );
			$sure = true;
		} elseif ( preg_match( '/\.imgix\.net$/', $host ) ) {
			$q = array_diff_key( $q, array_flip( [ 'w', 'h', 'fit', 'crop', 'dpr', 'q', 'max-w', 'max-h' ] ) );
		} elseif ( preg_match( '/(^|\.)cloudinary\.com$/', $host ) ) {
			$path = (string) preg_replace( '#/upload/(?:[a-z]{1,2}_[^/]+/)+(?=v\d+/)#', '/upload/', $path );
		} elseif ( $host === 'static.wixstatic.com' ) {
			$path = (string) preg_replace( '#^(/media/[^/]+)/v1/.*$#', '$1', $path );
		} elseif ( preg_match( '/squarespace-cdn\.com$/', $host ) ) {
			unset( $q['format'] );
		} elseif ( strpos( $path, '/images/stencil/' ) !== false ) {
			$path = (string) preg_replace( '#/images/stencil/[^/]+/#', '/images/stencil/original/', $path );
		} elseif ( strpos( $path, '/media/catalog/product/cache/' ) !== false ) {
			$path = (string) preg_replace( '#/cache/(?:[^/]+/)*?[0-9a-f]{32}/#', '/', $path );
		} else {
			return [ $url, true ];
		}
		$qs = http_build_query( $q );
		return [ ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path . ( $qs !== '' ? '?' . $qs : '' ), $sure ];
	}

	/** The address answers with a picture (HEAD, the host's pacing), remembered FULL_DAYS. */
	private static function exists( string $url ): bool {
		$key = 'mmpt_full_' . md5( $url );
		$got = get_transient( $key );
		if ( $got !== false ) {
			return $got === 'y';
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		\MMI_API_Throttler::throttle( VendorFetcher::throttle_key( $host ), [ 'min_gap_ms' => PageStore::policy( $host )['gap_ms'] ] );
		$res = wp_safe_remote_head( $url, [ 'timeout' => 10, 'redirection' => 3 ] );
		$ok  = ! is_wp_error( $res ) && (int) wp_remote_retrieve_response_code( $res ) === 200 && str_starts_with( strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) ), 'image/' );
		set_transient( $key, $ok ? 'y' : 'n', ( $ok ? self::FULL_DAYS : 1 ) * DAY_IN_SECONDS );
		return $ok;
	}

	/**
	 * The same picture at the largest size the page links: "img_140x140/00016762.jpg"
	 * → "img_500x500/00016762.jpg" (a distributor's CDN). Only a WxH token in the path
	 * differs; anything else is another picture.
	 */
	public static function largest_variant( string $url, string $html ): string {
		$url = self::full_size( $url );
		if ( ! preg_match( '/\d{2,4}x\d{2,4}/', $url ) ) {
			return $url;
		}
		$parts = preg_split( '/\d{2,4}x\d{2,4}/', $url );
		$rx    = '#' . implode( '(\d{2,4})x(\d{2,4})', array_map( static fn( $p ) => preg_quote( preg_replace( '#^https?://#i', '', $p ), '#' ), $parts ) ) . '#i';
		$best  = $url;
		$area  = 0;
		if ( preg_match( '/(\d{2,4})x(\d{2,4})/', $url, $m ) ) {
			$area = (int) $m[1] * (int) $m[2];
		}
		if ( count( $parts ) === 2 && preg_match_all( $rx, $html, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $v ) {
				if ( (int) $v[1] * (int) $v[2] > $area ) {
					$area = (int) $v[1] * (int) $v[2];
					$best = ( stripos( $url, 'http://' ) === 0 ? 'http://' : 'https://' ) . $v[0];
				}
			}
		}
		return $best;
	}

	/**
	 * A better description for one this module wrote (and nobody edited since): back to `proposed`,
	 * remembering the text from before the first write so revert still restores the original.
	 */
	private static function refresh_written( int $id, array $c, array $was, string $written ): void {
		global $wpdb;
		$prev = (array) json_decode( (string) $was['detail'], true );
		$wpdb->update(
			self::table(),
			[
				'status'         => 'proposed',
				'source'         => $c['source'],
				'source_url'     => mb_substr( (string) ( $c['source_url'] ?? '' ), 0, 500 ),
				'proposed_value' => $c['proposed_value'],
				'current_value'  => $written,
				'detail'         => wp_json_encode( [ 'refresh' => 1, 'original' => (string) $was['original_value'], 'locked_by_us' => (int) ( $prev['locked_by_us'] ?? 0 ) ] + ( self::$hold ? [ 'hold' => 1 ] : [] ) ),
				'composed_at'    => current_time( 'mysql' ),
				'applied_at'     => null,
				'reviewed_by'    => null,
				'reviewed_at'    => null,
			],
			[ 'product_id' => $id, 'kind' => 'content' ]
		);
	}

	private static function put( int $id, string $kind, array $f, ?array $row ): void {
		global $wpdb;
		$fields = [
			'source'         => $f['source'],
			'source_url'     => mb_substr( (string) ( $f['source_url'] ?? '' ), 0, 500 ),
			'proposed_value' => $f['proposed_value'],
			'current_value'  => $f['current_value'],
			'composed_at'    => current_time( 'mysql' ),
		];
		if ( ! empty( $f['low_res'] ) ) {
			$fields['detail'] = wp_json_encode( [ 'low_res' => $f['low_res'] ] ); // shown to the reviewer; never a reason to drop a picture
		} elseif ( ! empty( $f['replace'] ) ) {
			$fields['detail'] = wp_json_encode( [ 'replace' => 1 ] ); // a full description the maker's copy replaces
		}
		if ( ! $row ) {
			$wpdb->insert( self::table(), [ 'product_id' => $id, 'kind' => $kind, 'status' => 'proposed' ] + $fields );
			return;
		}
		if ( in_array( $row['status'], [ 'applied', 'reverted' ], true ) ) {
			return;
		}
		if ( $row['proposed_value'] !== $fields['proposed_value'] ) {
			$fields['status'] = 'proposed'; // a decision was about different text
			$fields['chosen'] = '';         // and a pick about different pictures
		}
		$wpdb->update( self::table(), $fields, [ 'product_id' => $id, 'kind' => $kind ] );
	}

	/**
	 * Vendor prose, verbatim, as HTML. Returns null when no source has
	 * THIN_CHARS of real text.
	 *
	 * @param array<int,array{type:string,text:string}> $wa_blocks
	 * @return array{source:string,source_url:string,proposed_value:string}|null
	 */
	public static function compose_content( array $wa_blocks, ?array $desc_row, string $page_desc = '' ): ?array {
		// A maker URL that is a login, basket or registration page has no description to give (SKU 1143-1600's description
		// was ikmultimedia.com/registration/'s form text, 2026-10-27): its parts are skipped; the supplier's copy may still fill.
		if ( UrlFinder::is_not_product_page( (string) ( $desc_row['vendor_url'] ?? '' ) ) ) {
			$page_desc = '';
			$desc_row  = null;
		}
		// The vendor's own page first, read with the structured reader (headings, paragraphs, lists;
		// chrome, downloads and requirements out — Coverage::description_for()). Owner 2026-10-05: the
		// vendor product page is the preferred source; 2026-10-08: the paragraph reader below kept 472 of
		// the Undertone MPEQ-1 page's ~1,900 characters and missed its system requirements.
		if ( $page_desc !== '' && mb_strlen( Descriptions::plain( $page_desc ) ) >= self::THIN_CHARS ) {
			return [ 'source' => 'vendor_page', 'source_url' => (string) ( $desc_row['vendor_url'] ?? '' ), 'proposed_value' => $page_desc ];
		}
		if ( mb_strlen( implode( ' ', array_column( $wa_blocks, 'text' ) ) ) >= self::THIN_CHARS ) {
			return [ 'source' => 'web_assets', 'source_url' => '', 'proposed_value' => self::render_blocks( $wa_blocks ) ];
		}
		if ( $desc_row && ( $desc_row['fetch_status'] ?? '' ) === 'ok' ) {
			// Only real paragraphs from the vendor page: not its navigation ("HOME | INSTRUMENT | …").
			// Body copy only — the meta/summary part is SEO text ("Detailed information and
			// applications for…"). Rows fetched before the split (no BODY_MARK) aren't used here.
			$copy = (string) $desc_row['vendor_copy'];
			$body = strpos( $copy, VendorFetcher::BODY_MARK ) !== false ? explode( VendorFetcher::BODY_MARK, $copy, 2 )[1] : '';
			// Shop chrome goes too: cookie banners, cart and price lines, newsletter boxes, menus
			// (2026-10-08: 300 of 1,201 auto-written descriptions carried some).
			$paras = array_values(
				array_filter(
					explode( ' ¶ ', $body ),
					static fn( $b ) => mb_strlen( $b ) >= 80 && substr_count( $b, '|' ) < 3 && substr_count( $b, '•' ) < 5 && ! VendorPage::is_chrome( $b )
				)
			);
			$blocks = array_map( static fn( $t ) => [ 'type' => 'paragraph', 'text' => $t ], array_slice( $paras, 0, 5 ) );
			if ( mb_strlen( implode( ' ', array_column( $blocks, 'text' ) ) ) >= self::THIN_CHARS ) {
				return [ 'source' => 'vendor_page', 'source_url' => (string) $desc_row['vendor_url'], 'proposed_value' => self::render_blocks( $blocks ) ];
			}
		}
		return null;
	}

	/**
	 * Plugivery `desc`, verbatim: plain paragraphs with only <b>/<a> markup.
	 *
	 * @return array{source:string,source_url:string,proposed_value:string}|null
	 */
	public static function compose_plugivery( array $blocks ): ?array {
		if ( mb_strlen( implode( ' ', array_column( $blocks, 'text' ) ) ) < self::THIN_CHARS ) {
			return null;
		}
		return [ 'source' => 'plugivery', 'source_url' => '', 'proposed_value' => self::render_blocks( $blocks ) ];
	}

	/**
	 * The distributor's description, the product's own listing first: its Plugivery listing, its XChange
	 * web assets, then the same maker's Plugivery product of the same name. Read like a maker's page, so
	 * the maker's first-person lines and shop chrome stay out (VendorPage::clean_feed()).
	 *
	 * @param array<string,array> $pv plugivery_media()
	 * @return array{source:string,source_url:string,proposed_value:string}|null
	 */
	public static function compose_feed( string $pvid, array $wa_blocks, array $pv, int $id ): ?array {
		if ( $pvid !== '' && ( $c = self::compose_plugivery( VendorPage::clean_feed( $pv[ $pvid ]['blocks'] ?? [] ) ) ) ) {
			return $c;
		}
		$wa_blocks = VendorPage::clean_feed( $wa_blocks );
		if ( mb_strlen( implode( ' ', array_column( $wa_blocks, 'text' ) ) ) >= self::THIN_CHARS ) {
			return [ 'source' => 'web_assets', 'source_url' => '', 'proposed_value' => self::render_blocks( $wa_blocks ) ];
		}
		$match = $pvid === '' ? Pictures::plugivery_match( $id ) : '';
		return $match !== '' ? self::compose_plugivery( VendorPage::clean_feed( $pv[ $match ]['blocks'] ?? [] ) ) : null;
	}

	/**
	 * Plugivery id → description blocks + image list (box first, then GUI
	 * shots, then the rest; the single `img` when images[] is empty).
	 * The ~2.7 MB detail file → slim transient.
	 */
	public static function plugivery_media(): array {
		$key    = 'mmpt_plugivery_media_index';
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$file = mmi_shared_lib_json_dir() . 'plugivery-product-details.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$idx  = [];
		foreach ( (array) ( $data['products'] ?? [] ) as $id => $r ) {
			if ( ! is_array( $r ) || ! empty( $r['detail_missing'] ) ) {
				continue;
			}
			$text   = trim( html_entity_decode( wp_strip_all_tags( str_replace( [ '<br>', '<br/>', '<br />' ], "\n", (string) ( $r['desc'] ?? '' ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$images = array_values( array_filter( (array) ( $r['images'] ?? [] ), static fn( $i ) => is_array( $i ) && ! empty( $i['url'] ) ) );
			usort( $images, static fn( $a, $b ) => ( self::PLUGIVERY_IMAGE_ORDER[ $a['type'] ?? '' ] ?? 9 ) <=> ( self::PLUGIVERY_IMAGE_ORDER[ $b['type'] ?? '' ] ?? 9 ) );
			$urls = array_values( array_unique( array_column( $images, 'url' ) ) );
			if ( ! $urls && ! empty( $r['img'] ) ) {
				$urls = [ (string) $r['img'] ];
			}
			$idx[ (string) $id ] = [
				'blocks' => $text !== '' ? [ [ 'type' => 'paragraph', 'text' => $text ] ] : [],
				'images' => $urls,
			];
		}
		unset( $data );
		set_transient( $key, $idx, HOUR_IN_SECONDS );
		return $idx;
	}

	/** heading → <h3>, paragraph → <p> (blank lines split), list → <ul>. Text is escaped. */
	private static function render_blocks( array $blocks ): string {
		$html = [];
		foreach ( $blocks as $b ) {
			if ( $b['type'] === 'heading' ) {
				$html[] = '<h3>' . esc_html( $b['text'] ) . '</h3>';
			} elseif ( $b['type'] === 'list' ) {
				$items  = array_filter( array_map( 'trim', explode( ', ', $b['text'] ) ), 'strlen' );
				$html[] = '<ul>' . implode( '', array_map( static fn( $i ) => '<li>' . esc_html( $i ) . '</li>', $items ) ) . '</ul>';
			} else {
				foreach ( preg_split( '/\n\s*\n/', $b['text'] ) as $para ) {
					$para = trim( preg_replace( '/\s+/', ' ', $para ) );
					if ( $para !== '' ) {
						$html[] = '<p>' . esc_html( $para ) . '</p>';
					}
				}
			}
		}
		return implode( "\n", $html );
	}

	/**
	 * sku → cleaned web-assets image list (blanks, logos and duplicates out).
	 * The 16 MB file → slim transient.
	 */
	public static function web_assets_media(): array {
		$key    = 'mmpt_web_assets_media_index';
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$dir  = mmi_shared_lib_json_dir();
		$data = is_readable( $dir . 'xchange-web-assets.json' ) ? json_decode( (string) file_get_contents( $dir . 'xchange-web-assets.json' ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$idx  = [];
		foreach ( (array) ( $data['web_assets'] ?? [] ) as $r ) {
			if ( empty( $r['sku'] ) ) {
				continue;
			}
			$imgs = $r['images'] ?? [];
			if ( is_string( $imgs ) ) {
				preg_match_all( '#https?://[^\'",\s\]]+#', $imgs, $m ); // older rows store a stringified list
				$imgs = $m[0];
			}
			$clean = [];
			foreach ( (array) $imgs as $u ) {
				$u = trim( (string) $u );
				if ( $u !== '' && preg_match( '#^https?://#', $u ) && ! preg_match( '/logo|icon|badge|favicon/i', $u ) && preg_match( '/\.(jpe?g|png|webp)(\?|$)/i', $u ) ) {
					$clean[ $u ] = true;
				}
			}
			$idx[ (string) $r['sku'] ] = [
				'blocks' => Descriptions::web_assets_blocks( (string) ( $r['long_description'] ?? '' ) ),
				'images' => array_keys( $clean ),
			];
		}
		unset( $data );
		set_transient( $key, $idx, HOUR_IN_SECONDS );
		return $idx;
	}

	/* ── Apply / revert ─────────────────────────────────────────────────── */

	/**
	 * Queue a batch. From inside a running batch ($chain) only a pending one
	 * counts: as_has_scheduled_action() also counts the batch that is running,
	 * so a batch could never queue the next and writing stopped after one.
	 */
	public static function dispatch( int $delay = 0, bool $chain = false ): bool {
		if ( ! self::settings()['enabled'] || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$queued = $chain
			? (bool) as_get_scheduled_actions( [ 'hook' => self::HOOK, 'group' => Module::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ], 'ids' )
			: as_has_scheduled_action( self::HOOK, [], Module::GROUP );
		if ( $queued ) {
			return false;
		}
		as_schedule_single_action( time() + $delay, self::HOOK, [], Module::GROUP );
		return true;
	}

	/** Rows the applier writes: accepted ones, plus proposals whose source is approved. */
	/** @var int Unix time a running batch must stop importing pictures by (0 = no limit: CLI, Apply now). */
	private static $deadline = 0;

	public static function next( string $kind, int $limit, int $only = 0 ): array {
		global $wpdb;
		$approved = array_keys( array_filter( self::rules(), static fn( $r ) => $r['approved'] ) );
		$sources  = array_map( static fn( $k ) => substr( $k, strlen( $kind ) + 1 ), array_filter( $approved, static fn( $k ) => strpos( $k, $kind . ':' ) === 0 ) );
		$auto     = $sources ? $wpdb->prepare( " OR (status = 'proposed' AND source IN (" . implode( ',', array_fill( 0, count( $sources ), '%s' ) ) . '))', ...$sources ) : '';
		// A product that has pictures changes only to a set a person picked; AVIF rows wait for a CLI run.
		$skip = " AND NOT ( kind = 'image' AND current_value <> '' AND COALESCE( chosen, '' ) = '' )" . ( ImageConvert::browser_available() ? '' : " AND LOCATE( 'avif_wait', COALESCE( detail, '' ) ) = 0" );
		$skip .= " AND NOT ( status = 'proposed' AND LOCATE( '\"hold\":1', COALESCE( detail, '' ) ) > 0 )"; // refresh_for_review(): a person decides
		$skip .= $only ? ' AND product_id = ' . (int) $only : '';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM ' . self::table() . " WHERE kind = %s AND (status = 'accepted'{$auto}){$skip}" . Exclusions::sql_not_excluded() . " ORDER BY FIELD(status,'accepted','proposed'), product_id LIMIT %d", $kind, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function run_batch(): array {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return [ 'skipped' => 'disabled' ];
		}
		$state = Module::state( self::STATE );
		$today = current_time( 'Y-m-d' );
		$done  = ( $state['day'] ?? '' ) === $today ? (array) ( $state['today'] ?? [] ) : [];
		$out   = [];
		$stop           = time() + self::BATCH_S;
		$timed          = false;
		self::$deadline = $stop + 10; // a picture row stops between images, so one slow row can't outlive the ~45 s cron limit (2026-10-08 06:44: a 5-picture row was killed and the chain died)
		foreach ( self::KINDS as $kind ) {
			foreach ( self::next( $kind, self::BATCH ) as $id ) {
				if ( time() >= $stop ) {
					$timed = true;
					break 2;
				}
				$r                     = self::apply_one( $id, $kind );
				$out[ $r['result'] ]   = ( $out[ $r['result'] ] ?? 0 ) + 1;
				if ( $r['result'] === 'later' ) {
					$timed = true;
					break 2;
				}
				if ( $r['result'] === 'applied' ) {
					$done[ $kind ] = (int) ( $done[ $kind ] ?? 0 ) + 1;
				}
			}
		}
		self::$deadline = 0;
		Module::set_state( self::STATE, [ 'day' => $today, 'today' => $done, 'last_batch' => $out, 'last_batch_at' => current_time( 'mysql' ) ] );
		if ( ( ! empty( $out['applied'] ) || $timed ) && ( self::next( 'content', 1 ) || self::next( 'image', 1 ) ) ) {
			self::dispatch( $timed ? 15 : self::GAP_S, true ); // stopped for time: carry on shortly
		}
		return $out;
	}

	/**
	 * Write this product's rows the writer would write anyway — accepted, or
	 * from a source the owner approved — now (Scrape on the Vendor pages tab:
	 * page → proposals → product in one step). Rows that need a person stay.
	 *
	 * @return array<string,string> kind → result
	 */
	public static function apply_ready( int $id, ?array $kinds = null ): array { // $kinds: content and/or image (all when null)
		$out = [];
		if ( ! self::settings()['enabled'] ) {
			return $out;
		}
		foreach ( $kinds === null ? self::KINDS : array_intersect( self::KINDS, $kinds ) as $kind ) {
			if ( self::next( $kind, 1, $id ) ) {
				$out[ $kind ] = self::apply_one( $id, $kind )['result'];
			}
		}
		return $out;
	}

	/** Restart the writer when rows are waiting and no batch is queued or running (a killed batch queues nothing). */
	public static function watchdog(): bool {
		if ( ! self::settings()['enabled'] || ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( self::HOOK, [], Module::GROUP ) ) {
			return false;
		}
		return ( self::next( 'content', 1 ) || self::next( 'image', 1 ) ) && self::dispatch();
	}

	/** @return array{result:string,message:string} */
	public static function apply_one( int $id, string $kind ): array {
		global $wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE product_id = %d AND kind = %s', $id, $kind ), ARRAY_A );
		$post = get_post( $id );
		if ( ! $row || ! $post || $post->post_status !== 'publish' || ! in_array( $row['status'], [ 'proposed', 'accepted' ], true ) || Exclusions::is_excluded( $id ) ) {
			return [ 'result' => 'error', 'message' => 'Not applicable.' ];
		}
		$mark = static fn( array $f ) => $wpdb->update( self::table(), $f, [ 'product_id' => $id, 'kind' => $kind ] );

		if ( $kind === 'content' ) {
			$det     = (array) json_decode( (string) $row['detail'], true );
			$refresh = ! empty( $det['refresh'] ) && trim( (string) $post->post_content ) === trim( (string) $row['current_value'] ); // still exactly our earlier write
			$replace = ! empty( $det['replace'] ) && self::rules()['content:maker_replaces']['approved'] && trim( (string) $post->post_content ) === trim( (string) $row['current_value'] ); // the text it was proposed over, unchanged since
			if ( ! $refresh && ! $replace && mb_strlen( Descriptions::plain( $post->post_content ) ) >= self::THIN_CHARS ) {
				$mark( [ 'status' => 'stale' ] );
				return [ 'result' => 'stale', 'message' => 'The description is no longer thin.' ];
			}
			// An approved fill: written as before (never stopped at a lock), then locked against imports.
			$res = Write::field( $id, 'post_content', (string) $row['proposed_value'], 'product-descriptions', 'force', [ 'lock' => true ] );
			if ( $res['result'] === 'error' ) {
				$mark( [ 'status' => 'error', 'detail' => (string) ( $res['message'] ?? '' ) ] );
				return [ 'result' => 'error', 'message' => (string) ( $res['message'] ?? '' ) ];
			}
			if ( ! empty( $res['lock_added'] ) ) {
				$locked = 1;
			}
			$mark( [ 'status' => 'applied', 'original_value' => $refresh ? (string) ( $det['original'] ?? '' ) : $post->post_content, 'applied_at' => current_time( 'mysql' ), 'detail' => wp_json_encode( [ 'locked_by_us' => $refresh ? (int) ( $det['locked_by_us'] ?? 0 ) : ( $locked ?? 0 ) ] + ( $replace ? [ 'replaced' => 1 ] : [] ) ) ] );
			Module::info( $replace ? "Description replaced with the maker's copy" : 'Description filled', [ 'product_id' => $id, 'source' => $row['source'] ] );
			return [ 'result' => 'applied', 'message' => '' ];
		}

		$replace = (string) $row['current_value'] !== ''; // the product had pictures when this was proposed
		if ( ! $replace && (int) get_post_thumbnail_id( $id ) ) {
			$mark( [ 'status' => 'stale' ] );
			return [ 'result' => 'stale', 'message' => 'The product has an image now.' ];
		}
		$chosen = array_values( array_filter( (array) json_decode( (string) ( $row['chosen'] ?? '' ), true ), 'is_string' ) );
		if ( ! $chosen ) {
			if ( $replace ) {
				return [ 'result' => 'error', 'message' => 'Pick the pictures for this product first.' ];
			}
			$chosen = array_slice( (array) json_decode( (string) $row['proposed_value'], true ), 0, 1 + self::MAX_GALLERY );
		}
		$detail = (array) json_decode( (string) $row['detail'], true );
		$wait   = static function () use ( $mark, $detail ): array {
			$mark( [ 'detail' => wp_json_encode( [ 'avif_wait' => 1 ] + $detail ) ] );
			return [ 'result' => 'waiting', 'message' => 'AVIF pictures are converted by the next background run (within 30 minutes); the row is applied then.' ];
		};
		foreach ( $chosen as $tok ) {
			if ( strpos( $tok, 'att:' ) !== 0 && ImageConvert::url_needs_browser( $tok ) && ! ImageConvert::browser_available() && ! \MMI_Media_Helper::find_by_source_url( $tok ) ) {
				return $wait();
			}
		}
		$ids = [];
		foreach ( $chosen as $tok ) {
			if ( strpos( $tok, 'att:' ) === 0 ) { // one of the product's own pictures, kept by the person
				$att = (int) substr( $tok, 4 );
				if ( $att && get_post_type( $att ) === 'attachment' ) {
					$ids[] = $att;
				}
				continue;
			}
			$att = \MMI_Media_Helper::find_by_source_url( $tok );
			if ( ! $att ) {
				// Plugivery's image CDN gets its own courtesy gap (not an API call; no quota).
				if ( $row['source'] === 'plugivery' ) {
					\MMI_API_Throttler::throttle( 'plugivery_asset_binary', [ 'min_gap_ms' => 300 ] );
				} elseif ( $row['source'] === 'vendor_page' ) {
					$host = strtolower( (string) wp_parse_url( $tok, PHP_URL_HOST ) );
					\MMI_API_Throttler::throttle( VendorFetcher::throttle_key( $host ), [ 'min_gap_ms' => PageStore::policy( $host )['gap_ms'] ] );
				} else {
					\MMI_API_Throttler::throttle( 'xchange_asset_binary' );
				}
				if ( self::$deadline && time() >= self::$deadline ) {
					return [ 'result' => 'later', 'message' => 'Out of time in this batch; the next one carries on (pictures already imported are reused).' ];
				}
				$att = ImageConvert::import( $tok, $id ); // the standard 800×800 JPEG
				if ( $att === 'wait' ) {
					return $wait(); // pictures already imported are found by source URL next time
				}
				if ( is_wp_error( $att ) ) {
					Module::warn( 'Product image not imported', [ 'product_id' => $id, 'url' => $tok, 'error' => $att->get_error_message() ] );
					continue; // a dead or unreadable picture — the next one may still work
				}
				update_post_meta( (int) $att, '_wp_attachment_image_alt', PageName::clean( $post->post_title ) );
			}
			if ( $att ) {
				$ids[] = (int) $att;
			}
		}
		if ( ! $ids ) {
			$mark( [ 'status' => 'error', 'detail' => 'no image could be downloaded' ] );
			return [ 'result' => 'error', 'message' => 'No image could be downloaded.' ];
		}
		$before = [ 'thumb' => (int) get_post_thumbnail_id( $id ), 'gallery' => (string) get_post_meta( $id, '_product_image_gallery', true ) ];
		Write::field( $id, '_thumbnail_id', $ids[0], 'product-first-pictures', 'force' );
		$gallery = '';
		if ( $replace ) {
			// The picked set is the product's set now; revert() puts $before back.
			$gallery = implode( ',', array_slice( $ids, 1 ) );
			Write::field( $id, '_product_image_gallery', $gallery === '' ? null : $gallery, 'product-first-pictures', 'force' );
		} elseif ( count( $ids ) > 1 && ! get_post_meta( $id, '_product_image_gallery', true ) ) {
			$gallery = implode( ',', array_slice( $ids, 1 ) );
			Write::field( $id, '_product_image_gallery', $gallery, 'product-first-pictures', 'force' );
		}
		wc_delete_product_transients( $id );
		// mmi-seo's picture text (alt, title, caption, description); pictures downloaded now may be overwritten.
		do_action( 'mmi_seo_refresh_product_images', $id, array_values( array_diff( $ids, array_map( static fn( $t ) => (int) substr( $t, 4 ), array_filter( $chosen, static fn( $t ) => strpos( $t, 'att:' ) === 0 ) ) ) ) );
		$mark( [ 'status' => 'applied', 'original_value' => $replace ? wp_json_encode( $before ) : '', 'applied_at' => current_time( 'mysql' ), 'detail' => wp_json_encode( [ 'attachments' => $ids, 'gallery_set' => $gallery ] ) ] );
		Module::info( $replace ? 'Product images replaced' : 'Featured image added', [ 'product_id' => $id, 'attachments' => $ids ] );
		return [ 'result' => 'applied', 'message' => '' ];
	}

	/**
	 * A person's pick for an image row: featured first, then the gallery. Tokens
	 * are the row's candidate URLs and, for a product that has pictures, its
	 * own pictures as "att:ID". An empty pick clears it.
	 *
	 * @return array{chosen:string[]}|\WP_Error
	 */
	public static function choose( int $id, array $tokens ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE product_id = %d AND kind = 'image'", $id ), ARRAY_A );
		if ( ! $row || ! in_array( $row['status'], [ 'proposed', 'accepted', 'stale', 'error' ], true ) ) {
			return new \WP_Error( 'mmpt_bad', 'This image row can no longer be changed.', [ 'status' => 400 ] );
		}
		$allowed = array_merge( array_values( array_filter( (array) json_decode( (string) $row['proposed_value'], true ), 'is_string' ) ), self::current_tokens( (string) $row['current_value'] ) );
		$pick    = array_values( array_unique( array_filter( array_map( 'strval', $tokens ), static fn( $t ) => in_array( $t, $allowed, true ) ) ) );
		$wpdb->update( self::table(), [ 'chosen' => $pick ? wp_json_encode( $pick ) : '', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time( 'mysql' ) ], [ 'product_id' => $id, 'kind' => 'image' ] );
		return [ 'chosen' => $pick ];
	}

	/** A product's own pictures recorded on its row ({thumb, gallery}) as picker tokens, featured first. @return string[] */
	public static function current_tokens( string $current_value ): array {
		$c = json_decode( $current_value, true );
		if ( ! is_array( $c ) ) {
			return [];
		}
		$ids = array_merge( [ (int) ( $c['thumb'] ?? 0 ) ], array_map( 'intval', (array) ( $c['gallery'] ?? [] ) ) );
		return array_values( array_unique( array_map( static fn( $a ) => 'att:' . $a, array_filter( $ids ) ) ) );
	}

	/**
	 * Vendor-page picture rows for products that already have pictures (owner
	 * 2026-10-08: the picker covers every vendor URL), from stored pages only.
	 * New products first, then rows whose page was fetched again since. Pictures
	 * already in the media library from that page are left out; a product with
	 * nothing new gets no row. CLI (nightly in `find_urls`, `wp mmpt image-candidates`).
	 */
	public static function propose_for_imaged( int $max = 400, array $only = [] ): array {
		global $wpdb;
		self::maybe_install();
		$t    = self::table();
		$pg   = PageStore::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID id, p.post_title title, u.meta_value url, th.meta_value thumb, g.meta_value gallery, e.status, e.proposed_value
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} th ON th.post_id = p.ID AND th.meta_key = '_thumbnail_id' AND th.meta_value > 0
				 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url' AND LEFT( u.meta_value, 4 ) = 'http'
				 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
				 JOIN {$pg} pg ON pg.url_hash = MD5( u.meta_value ) AND pg.status = 'ok'
				 LEFT JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = '_product_image_gallery'
				 JOIN " . Pictures::table() . " l ON l.product_id = p.ID AND l.status = 'applied' AND l.mode = 'in_place' AND l.reason IN ('page_one_picture','page_share_only','page_low_res')
				 LEFT JOIN {$t} e ON e.product_id = p.ID AND e.kind = 'image'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
				   AND ( e.product_id IS NULL OR ( e.status = 'proposed' AND e.current_value <> '' AND e.composed_at < pg.fetched_at ) )" . Exclusions::sql_not_excluded( 'p.ID' ) . ( $only ? ' AND p.ID IN (' . implode( ',', array_map( 'intval', $only ) ) . ')' : '' ) . '
				 ORDER BY e.product_id IS NOT NULL, p.ID DESC
				 LIMIT %d',
				$max
			),
			ARRAY_A
		);
		$out = [ 'checked' => count( $rows ), 'proposed' => 0, 'nothing_new' => 0 ];
		foreach ( $rows as $r ) {
			$vp  = self::vendor_page_images( (string) $r['url'], (string) $r['title'], (int) $r['id'] );
			$new = array_values( array_filter( $vp, static fn( $i ) => ! \MMI_Media_Helper::find_by_source_url( $i['url'] ) ) );
			if ( ! $new ) {
				$out['nothing_new']++;
				continue;
			}
			$new = array_slice( $new, 0, self::MAX_CANDIDATES );
			self::put(
				(int) $r['id'],
				'image',
				[
					'source'         => 'vendor_page',
					'source_url'     => (string) $r['url'],
					'proposed_value' => wp_json_encode( array_column( $new, 'url' ) ),
					'current_value'  => wp_json_encode( [ 'thumb' => (int) $r['thumb'], 'gallery' => array_values( array_filter( array_map( 'intval', explode( ',', (string) $r['gallery'] ) ) ) ) ] ),
					'low_res'        => array_values( array_column( array_filter( $new, static fn( $i ) => ! empty( $i['low_res'] ) ), 'url' ) ),
				],
				$r['status'] ? [ 'status' => $r['status'], 'proposed_value' => $r['proposed_value'] ] : null
			);
			$out['proposed']++;
		}
		Module::info( 'Picture rows for products with pictures', $out );
		return $out;
	}

	/** Apply rows that waited for AVIF conversion (CLI; every 30 minutes via `wp mmpt pick`). */
	public static function apply_waiting( int $max = 20 ): array {
		if ( ! ImageConvert::browser_available() ) {
			return [ 'skipped' => 'not CLI or server busy' ];
		}
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM ' . self::table() . " WHERE kind = 'image' AND status IN ('accepted','proposed') AND LOCATE( 'avif_wait', COALESCE( detail, '' ) ) > 0 LIMIT %d", $max ) ) );
		$out = [ 'waiting' => count( $ids ) ];
		foreach ( $ids as $id ) {
			$r                    = self::apply_one( $id, 'image' );
			$out[ $r['result'] ] = ( $out[ $r['result'] ] ?? 0 ) + 1;
			if ( PageStore::server_busy() ) {
				break;
			}
		}
		return $out;
	}

	/** A site's home page or bare language root ("/", "/en/"): never one product's page. */
	public static function is_home( string $url ): bool {
		return PageEvents::is_home( $url );
	}

	/** Put a filled description back exactly; remove images this module attached. */
	public static function revert( int $id, string $kind ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE product_id = %d AND kind = %s', $id, $kind ), ARRAY_A );
		if ( ! $row || $row['status'] !== 'applied' ) {
			return [ 'result' => 'error', 'message' => 'Only an applied row can be reverted.' ];
		}
		$detail = (array) json_decode( (string) $row['detail'], true );
		if ( $kind === 'content' ) {
			Write::field( $id, 'post_content', (string) $row['original_value'], 'product-descriptions', 'force' ); // our own write, put back
			if ( ! empty( $detail['locked_by_us'] ) && class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
				\MMI_Pipeline_Field_Locks::unlock( $id, 'post_content' );
			}
		} elseif ( array_key_exists( 'thumb', (array) json_decode( (string) $row['original_value'], true ) ) ) {
			// A picked set replaced the product's pictures: put the old set back exactly.
			$orig = (array) json_decode( (string) $row['original_value'], true );
			Write::field( $id, '_thumbnail_id', (int) $orig['thumb'], 'product-first-pictures', 'force' );
			Write::field( $id, '_product_image_gallery', (string) ( $orig['gallery'] ?? '' ) === '' ? null : (string) $orig['gallery'], 'product-first-pictures', 'force' );
			wc_delete_product_transients( $id );
		} else {
			$atts = array_map( 'intval', (array) ( $detail['attachments'] ?? [] ) );
			if ( in_array( (int) get_post_thumbnail_id( $id ), $atts, true ) ) {
				Write::field( $id, '_thumbnail_id', 0, 'product-first-pictures', 'force' );
			}
			// Only a gallery this module set, and only while it is still exactly ours: a gallery
			// that was there before, or that someone changed since, stays. Rows applied before
			// `gallery_set` was recorded: remove it only if every image in it is one we attached.
			$now     = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $id, '_product_image_gallery', true ) ) ) );
			$ours    = array_key_exists( 'gallery_set', $detail )
				? array_filter( array_map( 'intval', explode( ',', (string) $detail['gallery_set'] ) ) )
				: ( $now && ! array_diff( $now, $atts ) ? $now : [] );
			if ( $ours && array_values( $now ) === array_values( $ours ) ) {
				Write::field( $id, '_product_image_gallery', null, 'product-first-pictures', 'force' );
			}
			wc_delete_product_transients( $id );
		}
		$wpdb->update( self::table(), [ 'status' => 'reverted', 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time( 'mysql' ) ], [ 'product_id' => $id, 'kind' => $kind ] );
		return [ 'result' => 'reverted', 'message' => '' ];
	}

	/* ── Review ─────────────────────────────────────────────────────────── */

	public static function query( array $f ): array {
		global $wpdb;
		$t     = self::table();
		$where = [ $wpdb->prepare( 'kind = %s', in_array( $f['kind'] ?? '', self::KINDS, true ) ? $f['kind'] : 'content' ) ];
		if ( ! empty( $f['product'] ) ) {
			$where[] = $wpdb->prepare( 'product_id = %d', (int) $f['product'] ); // a Vendor pages row's "Review"
		}
		if ( isset( $f['ids'] ) && is_array( $f['ids'] ) ) { // One page's scope (OnePage::scope_of())
			$where[] = $f['ids'] ? 'product_id IN (' . implode( ',', array_map( 'intval', $f['ids'] ) ) . ')' : '0=1';
		}
		if ( ! empty( $f['status'] ) && $f['status'] !== 'all' ) {
			$where[] = $wpdb->prepare( 'status = %s', $f['status'] );
		}
		if ( ! empty( $f['source'] ) ) {
			$where[] = $wpdb->prepare( 'source = %s', $f['source'] );
		}
		if ( ( $f['kind'] ?? '' ) === 'image' && in_array( $f['has_images'] ?? '', [ 'yes', 'no' ], true ) ) {
			$where[] = $f['has_images'] === 'yes' ? "current_value <> ''" : "COALESCE( current_value, '' ) = ''";
		}
		$w     = implode( ' AND ', $where );
		$per   = max( 25, min( 200, (int) ( $f['per_page'] ?? 0 ) ?: 25 ) );
		$page  = max( 1, (int) ( $f['page'] ?? 1 ) );
		// audit-ok: $w joins prepare() outputs, an intval id list and fixed clauses
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$w}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT e.*, p.post_title FROM {$t} e JOIN {$wpdb->posts} p ON p.ID = e.product_id WHERE {$w} ORDER BY e.product_id LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return [ 'rows' => $rows, 'total' => $total, 'pages' => (int) max( 1, ceil( $total / $per ) ) ];
	}

	public static function summary(): array {
		global $wpdb;
		$counts = [];
		foreach ( $wpdb->get_results( 'SELECT kind, source, status, COUNT(*) n FROM ' . self::table() . ' GROUP BY kind, source, status', ARRAY_A ) as $r ) {
			$counts[ $r['kind'] . ':' . $r['source'] ][ $r['status'] ] = (int) $r['n'];
		}
		return [ 'counts' => $counts, 'rules' => self::rules(), 'settings' => self::settings(), 'state' => Module::state( self::STATE ), 'today' => current_time( 'Y-m-d' ) ];
	}
}
