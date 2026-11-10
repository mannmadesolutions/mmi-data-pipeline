<?php
/**
 * Vendor catalogs: a vendor's own list of its products, matched to ours, so a
 * person approves "our product ↔ the vendor's name for it" instead of judging
 * URLs one by one (owner, 2026-10-07).
 *
 * Where a vendor's list comes from, best first:
 *  1. Shopify stores publish their catalog at /products.json (name, handle, images).
 *  2. WooCommerce stores publish theirs through the Store API (/wp-json/wc/store/v1/products).
 *  3. Other WordPress sites list their pages through the REST API (/wp-json/wp/v2/pages, and a
 *     downloads or products type when the site has one). A product missing from that list is
 *     still looked for in the sitemap (4).
 *  4. Everyone else: the sitemap's best slug matches for each product, fetched
 *     through PageStore, and the page's own title and og:image read.
 *
 * Matching is strict about what tells editions apart: every word of the
 * vendor's name must be in our product's name, version numbers must agree, and
 * edition words (Standard, Advanced, Pro, MAX, LE…) must be the same on both
 * sides. A vendor page naming no edition is accepted as the family page, with
 * a lower score. Matches are written to the Vendor URLs queue (reason
 * `vendor_catalog`) with the vendor's name and main image; nothing is adopted
 * without a person. Offers (upgrades, crossgrades, Student/Teacher) match the
 * product page they belong to, since qualifiers are left out of the name.
 *
 * WP-CLI only (`wp mmpt vendor-catalog`, and nightly inside `find_urls`).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VendorCatalog {

	const DB_OPTION  = 'mmpt_vcat_db_version';
	const DB_VERSION = '1';
	const STATE      = 'mmi_admin_product_titles_vendor_catalog_state';
	const CACHE_DAYS = 7;
	const MAX_PAGES  = 20;   // catalog API pages per vendor (Shopify 250, Woo 100 each)
	const MIN_SCORE  = 60;   // below this a match isn't written
	const PAGE_PICKS = 2;    // sitemap candidates fetched per product
	const LIST_VERSION = 2;  // 2: WordPress page lists are read too (2026-10-14)
	const SITEMAPS     = 40;   // sitemap files read per site, index files included
	const MAX_ITEMS    = 3000; // pages kept from one site's sitemap
	/** A firewall's "you are blocked" page (Cloudflare, Sucuri, Akamai…): the site refuses this server, not the page. */
	const BLOCK_RE     = '/been blocked|Attention Required|Just a moment|cf-chl|Access denied|Sucuri WebSite Firewall/i';

	/** @var array<string,bool> hosts whose firewall refused this server during this request */
	private static $blocked_now = [];
	const STAGE        = '~'; // host prefix of a list being read in steps, until it replaces the cached one
	const STEP_TIMEOUT_S = 20; // per request in a stepped read: a step ends well inside nginx's 60 s
	const READ_STALE_S   = 3600; // a stepped read nobody carried on for this long starts over

	/** WordPress post types that can hold a site's products (WooCommerce's are read through its Store API). */
	const WP_TYPES = [ 'page', 'download', 'product' ];

	/** A WordPress site's own pages that are never a product page (UrlFinder::NOT_PRODUCT has the rest). */
	const WP_NOT_PRODUCT = '#/(my-account|contact(-us)?|about(-us)?|faqs|licen[cs](e|es|ing)|shop|store|home|cart-\d+|checkout-\d+|customer|register|forgot-password|reset-password|privacy(-policy)?|terms(-and-conditions|-conditions|-of-use|-of-service|-of-sale)?|(cancellations?-)?refunds?(-policy)?|shipping(-policy)?|cookies?(-policy)?)(/|\?|$)#i'; // whole policy names only: "terms-of-endearment" is a product

	/**
	 * A site's content sections, never product pages, as a path's first segment (owner 2026-10-19: one maker's
	 * sitemap of 2,094 "product pages" was 1,450 articles: /resource-center/, /customer-stories/, /press-room/,
	 * /courses/…). Only the first segment: "fx-collection/resources" stays (arturia.com's real pages).
	 */
	const CONTENT_SECTIONS = '#^/(resource-?centers?|resources|customer-stories|case-stud(y|ies)|success-stories|stories|press-room|press-releases|newsroom|courses?|training|certifications?|learning|webinars?|podcasts?|technical-support|white-?papers?|ebooks?|insights|articles?)(/|$)#i';

	/** Words that tell editions apart: both names must carry the same ones. */
	const EDITIONS = [ 'elements', 'standard', 'advanced', 'pro', 'lite', 'le', 'se', 'max', 'deluxe', 'essentials', 'essential', 'complete', 'ultimate', 'artist', 'producer', 'core', 'plus', 'mini', 'express', 'intro', 'first', 'expanded', 'ce', 'xl', 'native', 'hd', 'hdx', 'basic', 'premium', 'professional', 'starter' ];

	/** Candidate reasons a vendor-catalog match may replace (none of them confirms the page). */
	const WEAK = [ 'sitemap_slug', 'xchange_feed_unverified', 'xchange_feed_unconfirmed', 'xchange_feed_reseller', 'plugivery_link_unverified', 'plugivery_link_doubtful', 'vendor_catalog' ];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_vendor_catalog';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  host varchar(190) NOT NULL DEFAULT '',
  url_hash char(32) NOT NULL,
  url varchar(500) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  image varchar(500) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT '',
  fetched_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY host (host)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/* ── Run ────────────────────────────────────────────────────────────── */

	/**
	 * Match every product that still needs a vendor page (UrlFinder::gaps())
	 * against its vendor's catalog. CLI only.
	 *
	 * @param string[] $brands    Only these brands (lowercase).
	 * @param int      $max_pages Sitemap pages fetched this run (catalog APIs don't count).
	 * @return array<string,mixed>
	 */
	public static function run( array $brands = [], int $max_pages = 300, bool $dry = false, int $seconds = 1800 ): array {
		if ( ! ( ( defined( 'WP_CLI' ) && WP_CLI ) || Background::$stepping ) ) {
			return [ 'error' => 'Vendor catalogs are read from WP-CLI only.' ];
		}
		self::maybe_install();
		UrlFinder::maybe_install();
		$totals  = [ 'brands' => 0, 'no_host' => 0, 'no_sitemap' => 0, 'products' => 0, 'matched' => 0, 'written' => 0, 'corroborated' => 0, 'kept_existing' => 0, 'no_match' => 0, 'pages_fetched' => 0, 'stopped_early' => false, 'by_source' => [], 'dry_run' => $dry ];
		$start   = time();
		$pending = []; // vendors without a catalog API, worked after the API vendors, largest first
		// Each vendor is matched and written before the next, so a stopped run keeps what it did.
		foreach ( UrlFinder::gaps( array_map( 'strtolower', $brands ) ) as $brand => $g ) {
			$totals['brands']++;
			if ( $g['host'] === '' ) {
				$totals['no_host']++;
				continue;
			}
			[ $host ] = explode( '/', $g['host'], 2 );
			$items    = self::catalog( $host );
			if ( ! $items || ( $items[0]['source'] ?? '' ) === 'sitemap' ) {
				$pending[ $brand ] = $g; // a sitemap's names are only slugs: its pages are fetched and checked below
				continue;
			}
			$found = [];
			$miss  = []; // not in a WordPress page list: the sitemap may still have them
			foreach ( self::titles( $g['ids'] ) as $id => $title ) {
				$m = self::best( $title, $brand, $items );
				if ( ( ! $m || $m['score'] < self::MIN_SCORE ) && ( $items[0]['source'] ?? '' ) === 'wp' ) {
					$miss[] = $id;
					continue;
				}
				$totals['products']++;
				$found[ $id ] = [ $host, $m, $title, $brand ];
			}
			self::record_all( $found, $dry, $totals );
			if ( $miss ) {
				$pending[ $brand ] = [ 'ids' => $miss ] + $g;
			}
		}
		uasort( $pending, static fn( $a, $b ) => count( $b['ids'] ) <=> count( $a['ids'] ) );
		foreach ( $pending as $brand => $g ) {
			if ( $totals['pages_fetched'] >= $max_pages || time() - $start > $seconds ) {
				$totals['stopped_early'] = true;
				break;
			}
			[ $host, $base ] = array_pad( explode( '/', $g['host'], 2 ), 2, '' );
			$index = self::prefer_english( UrlFinder::index_urls( UrlFinder::sitemap_urls( $host, $base !== '' ? '/' . trim( $base, '/' ) : '' ) ) );
			if ( ! $index ) {
				$totals['no_sitemap']++;
				continue;
			}
			$found = [];
			foreach ( self::titles( $g['ids'] ) as $id => $title ) {
				if ( $totals['pages_fetched'] >= $max_pages || time() - $start > $seconds ) {
					$totals['stopped_early'] = true;
					break;
				}
				if ( PageStore::server_busy() ) {
					sleep( 20 );
				}
				$totals['products']++;
				$found[ $id ] = [ $host, self::from_pages( $title, $brand, $index, $totals ), $title, $brand ];
			}
			self::record_all( $found, $dry, $totals );
		}
		if ( ! $dry ) {
			Module::set_state( self::STATE, [ 'last_run' => current_time( 'mysql' ), 'totals' => $totals ] );
			Module::info( 'Vendor catalog run', $totals );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.catalog', [ 'object_type' => 'product', 'details' => array_diff_key( $totals, [ 'rows' => 1 ] ) ] );
			}
		}
		return $totals;
	}

	/**
	 * A page listed both in English (or with no language prefix) and in another
	 * language ("/ja/shop/vocalign-pro" beside "/shop/vocalign-pro") keeps only
	 * the English one. A page that exists only in another language stays.
	 *
	 * @param array<string,string[]> $index url => tokens
	 * @return array<string,string[]>
	 */
	public static function prefer_english( array $index ): array {
		$lang = '#^/([a-z]{2})(?:[-_][a-z]{2})?(/.*)$#i';
		$have = [];
		foreach ( array_keys( $index ) as $u ) {
			$path = (string) wp_parse_url( $u, PHP_URL_PATH );
			if ( ! preg_match( $lang, $path, $m ) || strtolower( $m[1] ) === 'en' ) {
				$have[ rtrim( preg_match( $lang, $path, $e ) ? $e[2] : $path, '/' ) ] = true;
			}
		}
		foreach ( array_keys( $index ) as $u ) {
			$path = (string) wp_parse_url( $u, PHP_URL_PATH );
			if ( preg_match( $lang, $path, $m ) && strtolower( $m[1] ) !== 'en' && isset( $have[ rtrim( $m[2], '/' ) ] ) ) {
				unset( $index[ $u ] );
			}
		}
		return $index;
	}

	/** One vendor's matches, shared pages capped, written. @param array<int,array> $found */
	private static function record_all( array $found, bool $dry, array &$totals ): void {
		foreach ( self::shared_pages( $found ) as $id => $f ) {
			self::record( $id, $f[0], $f[1], $dry, $totals );
		}
	}

	/** A vendor URL claimed by this many products with different names of their own is a family or listing page. */
	const SHARED_NAMES = 4;

	/**
	 * Matches whose vendor page is shared by several different products ("Naada
	 * Plugins for Windows" for 44 Naada instruments) are capped at MIN_SCORE and
	 * say so: the page covers a line, not this product. Offers of one product
	 * (its upgrade, crossgrade and Student/Teacher SKUs) share a page legitimately:
	 * their names differ only in our descriptor words, which the vendor name also lacks,
	 * so only names that keep a word of their own the others lack count as different.
	 *
	 * @param array<int,array> $found id => [host, match|null, title, brand]
	 * @return array<int,array>
	 */
	private static function shared_pages( array $found ): array {
		$by = [];
		foreach ( $found as $id => $f ) {
			if ( $f[1] ) {
				$rest = array_diff( self::our_tokens( $f[2], $f[3] ), UrlFinder::tokens( (string) $f[1]['name'] ) );
				$by[ strtolower( rtrim( $f[1]['url'], '/' ) ) ][ $id ] = $rest;
			}
		}
		foreach ( $by as $members ) {
			if ( count( $members ) < self::SHARED_NAMES ) {
				continue;
			}
			// Words that appear in at least half the names are descriptors ("processor"), not names.
			$freq = array_count_values( array_merge( ...array_values( array_map( 'array_values', $members ) ) ) );
			$own  = [];
			foreach ( $members as $id => $rest ) {
				$key = implode( ' ', array_filter( $rest, static fn( $w ) => $freq[ $w ] < count( $members ) / 2 ) );
				if ( $key !== '' ) {
					$own[ $key ] = true;
				}
			}
			if ( count( $own ) < self::SHARED_NAMES ) {
				continue;
			}
			foreach ( array_keys( $members ) as $id ) {
				$found[ $id ][1]['score'] = min( (int) $found[ $id ][1]['score'], self::MIN_SCORE );
				$found[ $id ][1]['why']  .= '; shared by ' . count( $members ) . ' different products (a family or listing page)';
			}
		}
		return $found;
	}

	/** Product titles, decoded. @return array<int,string> */
	private static function titles( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT ID, post_title FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', $ids ) . ')', ARRAY_A ) as $r ) {
			$out[ (int) $r['ID'] ] = html_entity_decode( (string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $out;
	}

	/** @param array{url:string,name:string,image:string,source:string,score:int,why:string}|null $m */
	private static function record( int $id, string $host, ?array $m, bool $dry, array &$totals ): void {
		if ( $dry ) {
			$totals['rows'][] = [ 'id' => $id, 'title' => get_the_title( $id ), 'match' => $m ];
		}
		if ( ! $m || $m['score'] < self::MIN_SCORE ) {
			$totals['no_match']++;
			if ( ! $dry ) {
				global $wpdb; // an earlier run's match this run no longer makes leaves the queue
				$wpdb->delete( UrlFinder::table(), [ 'product_id' => $id, 'status' => 'candidate', 'reason' => 'vendor_catalog' ] );
			}
			return;
		}
		if ( BrandSites::blocked( $id, (string) $m['url'] ) ) {
			$totals['blocked'] = ( $totals['blocked'] ?? 0 ) + 1; // the brand marked this site "not this brand"
			return;
		}
		$totals['matched']++;
		$totals['by_source'][ $m['source'] ] = ( $totals['by_source'][ $m['source'] ] ?? 0 ) + 1;
		global $wpdb;
		$t   = UrlFinder::table();
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT url, status, reason, score FROM {$t} WHERE product_id = %d", $id ), ARRAY_A );
		if ( $old && ( $old['status'] !== 'candidate' || ! in_array( $old['reason'], self::WEAK, true ) ) && ! UrlFinder::reopened( $id, $old ) ) {
			$totals['kept_existing']++; // decided by a person, or a stronger kind of candidate
			return;
		}
		$same = $old && UrlFinder::same_url( (string) $old['url'], $m['url'] );
		if ( $same ) {
			$totals['corroborated']++;
		}
		$note = mb_substr( 'vendor calls it: ' . $m['name'] . ' (' . $m['source'] . '; ' . $m['why'] . ( $same && $old['reason'] !== 'vendor_catalog' ? '; also proposed as ' . $old['reason'] : '' ) . ')', 0, 255 );
		$totals['written']++;
		if ( $dry ) {
			return;
		}
		$wpdb->replace(
			$t,
			[
				'product_id'  => $id,
				'url'         => mb_substr( $m['url'], 0, 500 ),
				'host'        => $host,
				'score'       => min( 99, $m['score'] + ( $same ? 5 : 0 ) ),
				'status'      => 'candidate',
				'reason'      => 'vendor_catalog',
				'note'        => $note,
				'vendor_name' => mb_substr( $m['name'], 0, 255 ),
				'image'       => mb_substr( $m['image'], 0, 500 ),
				'found_at'    => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/* ── Catalog APIs ───────────────────────────────────────────────────── */

	/**
	 * The vendor's product list from its store API (or its WordPress page list), cached CACHE_DAYS in
	 * the catalog table. [] when the host has none of them.
	 *
	 * With a $budget (seconds) the list is read in steps that each end inside it: a large store at a
	 * 4 s gap takes minutes (moogaudio.com, 5,000 products, answered the drawer with a 504 on
	 * 2026-10-15). When the time is up, where the read got to is kept (progress()) and null comes back;
	 * the next call carries on from there. Without one (WP-CLI) the whole list is read now.
	 *
	 * @return array<int,array{url:string,name:string,image:string,source:string}>|null null: still reading
	 */
	public static function catalog( string $host, float $budget = 0.0 ): ?array {
		global $wpdb;
		$state = Module::state( self::STATE . '_hosts' );
		$seen  = $state[ $host ] ?? null;
		// Only the drawer (a stepped read) falls back to the sitemap, so a site the nightly run found
		// without a list is read again, sitemap included, the first time a person asks for it.
		$sm = $budget > 0;
		// A host found to have no list before WordPress page lists were read is asked again once.
		if ( $seen && strtotime( (string) $seen['at'] ) > time() - self::CACHE_DAYS * DAY_IN_SECONDS && ( $seen['count'] || (int) ( $seen['v'] ?? 1 ) >= self::LIST_VERSION ) && ( $seen['count'] || ! $sm || ! empty( $seen['sm'] ) ) ) {
			if ( self::progress( $host ) ) {
				self::drop_read( $host ); // a read left behind by a list someone else finished
			}
			return $seen['count'] ? self::listed( $host ) : [];
		}
		$lock = 'mmpt_vcat_read_' . md5( $host );
		if ( $budget > 0 ) {
			if ( get_transient( $lock ) ) {
				return null; // another step is reading this site now
			}
			set_transient( $lock, 1, MINUTE_IN_SECONDS );
		}
		$cur = self::progress( $host );
		if ( ! $cur || strtotime( (string) $cur['at'] ) < time() - self::READ_STALE_S ) {
			$wpdb->delete( self::table(), [ 'host' => self::STAGE . $host ] );
			$cur = [ 'at' => current_time( 'mysql' ), 'source' => 'shopify', 'page' => 1, 'n' => 0, 'sm' => $sm ];
		}
		$until   = $budget > 0 ? microtime( true ) + $budget : PHP_FLOAT_MAX;
		$timeout = $budget > 0 ? self::STEP_TIMEOUT_S : 30;
		while ( $cur['source'] !== 'done' ) {
			if ( microtime( true ) >= $until ) {
				self::save_read( $host, $cur );
				delete_transient( $lock );
				return null;
			}
			self::step( $host, $cur, $timeout );
			if ( ! empty( self::$blocked_now[ $host ] ) ) {
				$cur['blocked'] = 1;
			}
		}
		$list = (string) $cur['list'];
		if ( ! $cur['n'] && ! empty( $cur['blocked'] ) && ! empty( $seen['count'] ) ) {
			// The site's firewall refused the re-read: the list sent from the owner's browser stays (Capture::site()).
			$wpdb->delete( self::table(), [ 'host' => self::STAGE . $host ] );
			Module::set_state( self::STATE . '_hosts', [ $host => [ 'at' => current_time( 'mysql' ), 'blocked' => true ] + $seen ] );
			Module::set_state( self::STATE . '_reading', [ $host => null ] );
			delete_transient( $lock );
			return self::listed( $host );
		}
		$wpdb->delete( self::table(), [ 'host' => $host ] );
		// The read list replaces the old one in one statement; a row a unique key refuses is dropped.
		$wpdb->query( $wpdb->prepare( 'UPDATE IGNORE ' . self::table() . ' SET host = %s, url_hash = MD5(url) WHERE host = %s', $host, self::STAGE . $host ) );
		$wpdb->delete( self::table(), [ 'host' => self::STAGE . $host ] );
		Module::set_state( self::STATE . '_hosts', [ $host => [ 'at' => current_time( 'mysql' ), 'count' => (int) $cur['n'], 'source' => $list, 'v' => self::LIST_VERSION, 'sm' => ! empty( $cur['sm'] ), 'blocked' => ! $cur['n'] && ! empty( $cur['blocked'] ) ] ] );
		Module::set_state( self::STATE . '_reading', [ $host => null ] );
		delete_transient( $lock );
		return $cur['n'] ? self::listed( $host ) : [];
	}

	/**
	 * How far a stepped read of the site's list has got, or null when none is under way.
	 * @return array{at:string,source:string,page:int,n:int}|null
	 */
	public static function progress( string $host ): ?array {
		$r = Module::state( self::STATE . '_reading' )[ $host ] ?? null;
		return is_array( $r ) ? $r : null;
	}

	/** Did the site's firewall refuse this server the last time its list was read? */
	public static function blocked( string $host ): bool {
		return ! empty( ( Module::state( self::STATE . '_hosts' )[ $host ] ?? [] )['blocked'] );
	}

	/**
	 * A site's product pages sent from the owner's browser (its sitemap, read there because the site's
	 * firewall refuses this server; Capture::list_post()). They replace the cached list, filtered and named
	 * as a sitemap read here would be. @param string[] $urls @return int pages kept
	 */
	public static function store_list( string $host, array $urls ): int {
		global $wpdb;
		self::maybe_install();
		$rows = [];
		foreach ( $urls as $u ) {
			$item = self::item_from_url( (string) $u, $host );
			if ( $item ) {
				$rows[ $item['url'] ] = $item;
			}
			if ( count( $rows ) >= self::MAX_ITEMS ) {
				break;
			}
		}
		if ( ! $rows ) {
			return 0;
		}
		$wpdb->delete( self::table(), [ 'host' => $host ] );
		foreach ( $rows as $i ) {
			$wpdb->replace( self::table(), [ 'host' => $host, 'url_hash' => md5( $i['url'] ), 'url' => mb_substr( $i['url'], 0, 500 ), 'name' => mb_substr( $i['name'], 0, 255 ), 'image' => '', 'source' => 'browser', 'fetched_at' => current_time( 'mysql' ) ] );
		}
		$seen = Module::state( self::STATE . '_hosts' )[ $host ] ?? [];
		Module::set_state( self::STATE . '_hosts', [ $host => [ 'at' => current_time( 'mysql' ), 'count' => count( $rows ), 'source' => 'browser', 'v' => self::LIST_VERSION, 'sm' => true, 'blocked' => ! empty( $seen['blocked'] ) ] ] );
		self::drop_read( $host );
		return count( $rows );
	}

	/** A path that is never a product page: help, news, policies, a WordPress site's own pages, a content section. */
	private static function not_product_path( string $path ): bool {
		return (bool) ( preg_match( UrlFinder::NOT_PRODUCT, $path ) || preg_match( self::WP_NOT_PRODUCT, $path ) || preg_match( self::CONTENT_SECTIONS, $path ) );
	}

	/**
	 * Drop stored list rows in a content section (lists kept before CONTENT_SECTIONS), each site's count
	 * following. $apply false only counts. @return array<string,array{0:int,1:int}> host → [ dropped, left ]
	 */
	public static function drop_content_sections( bool $apply ): array {
		global $wpdb;
		self::maybe_install();
		$rows = $wpdb->get_results( 'SELECT host, url_hash, url FROM ' . self::table() . " WHERE host NOT LIKE '" . esc_sql( $wpdb->esc_like( self::STAGE ) ) . "%'", ARRAY_A );
		$out  = [];
		$gone = [];
		foreach ( $rows as $x ) {
			$host = (string) $x['host'];
			$out[ $host ] = $out[ $host ] ?? [ 0, 0 ];
			if ( preg_match( self::CONTENT_SECTIONS, '/' . ltrim( (string) wp_parse_url( (string) $x['url'], PHP_URL_PATH ), '/' ) ) ) {
				++$out[ $host ][0];
				$gone[ $host ][] = (string) $x['url_hash'];
			} else {
				++$out[ $host ][1];
			}
		}
		$out = array_filter( $out, static fn( $c ) => $c[0] > 0 );
		if ( $apply && $gone ) {
			$state = Module::state( self::STATE . '_hosts' );
			foreach ( $gone as $host => $hashes ) {
				foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
					$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE host = %s AND url_hash IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', $host, ...$chunk ) );
				}
				if ( isset( $state[ $host ] ) ) {
					Module::set_state( self::STATE . '_hosts', [ $host => [ 'count' => $out[ $host ][1] ] + $state[ $host ] ] );
				}
			}
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'vendor_catalog.content_sections_dropped', [ 'object_type' => 'vendor_catalog', 'object_id' => 'all', 'details' => array_map( static fn( $c ) => $c[0], $out ) ] );
			}
		}
		return $out;
	}

	/** A sitemap address → a product page of the site, named from its slug; null when it isn't one. */
	private static function item_from_url( string $u, string $host ): ?array {
		$path = '/' . ltrim( (string) wp_parse_url( $u, PHP_URL_PATH ), '/' );
		if ( ! preg_match( '#^https?://#i', $u ) || BrandSites::host( $u ) !== BrandSites::host( $host ) || ! UrlFinder::looks_like_product_page( $u ) || self::not_product_path( $path ) ) {
			return null;
		}
		$slug = preg_replace( '/\.(html?|php|aspx?)$/i', '', rawurldecode( basename( rtrim( $path, '/' ) ) ) );
		$name = ucwords( trim( preg_replace( '/[-_]+/', ' ', $slug ) ) );
		return $name === '' ? null : [ 'url' => $u, 'name' => $name ];
	}

	/** Read the site's list again from the start: "Reload the site's list". */
	public static function forget( string $host ): void {
		Module::set_state( self::STATE . '_hosts', [ $host => null ] );
		self::drop_read( $host );
	}

	/** The cached list, in the order it was read. */
	private static function listed( string $host ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT url, name, image, source FROM ' . self::table() . ' WHERE host = %s ORDER BY id', $host ), ARRAY_A );
	}

	/**
	 * Keep where a stepped read got to, unless the list was finished meanwhile (the nightly run reads it
	 * too): then this read is dropped, its rows with it.
	 */
	private static function save_read( string $host, array $cur ): void {
		$done = Module::state( self::STATE . '_hosts' )[ $host ] ?? null;
		if ( $done && strtotime( (string) $done['at'] ) >= strtotime( (string) $cur['at'] ) ) {
			self::drop_read( $host );
			return;
		}
		Module::set_state( self::STATE . '_reading', [ $host => $cur ] );
	}

	private static function drop_read( string $host ): void {
		global $wpdb;
		Module::set_state( self::STATE . '_reading', [ $host => null ] );
		$wpdb->delete( self::table(), [ 'host' => self::STAGE . $host ] );
	}

	/**
	 * One request of the read: the next page of the list being tried, kept under the staging host. A
	 * store's list is tried first (Shopify, then WooCommerce), then the WordPress page list, then the
	 * sitemap (owner 2026-10-15: thempcstore.com, AKAI's own store, publishes nothing else); the first
	 * kind that lists anything is the site's list.
	 */
	private static function step( string $host, array &$cur, int $timeout ): void {
		global $wpdb;
		if ( $cur['source'] === 'sitemap' ) {
			self::sitemap_step( $host, $cur, $timeout );
			return;
		}
		if ( $cur['source'] === 'wp' && ! isset( $cur['bases'] ) ) {
			$types        = self::json( 'https://' . $host . '/wp-json/wp/v2/types', $host, $timeout );
			$cur['bases'] = [];
			if ( is_array( $types ) && isset( $types['page'] ) ) { // else not WordPress, or its REST API is closed
				foreach ( self::WP_TYPES as $type ) {
					$base = (string) ( $types[ $type ]['rest_base'] ?? '' );
					if ( preg_match( '/^[a-z0-9_-]+$/i', $base ) ) {
						$cur['bases'][] = $base;
					}
				}
			}
			$cur['type'] = 0;
			if ( ! $cur['bases'] ) {
				self::next_source( $cur );
			}
			return;
		}
		[ $items, $more ] = self::page_of( $host, $cur, $timeout );
		foreach ( (array) $items as $i ) {
			$wpdb->replace( self::table(), [ 'host' => self::STAGE . $host, 'url_hash' => md5( self::STAGE . $i['url'] ), 'url' => mb_substr( $i['url'], 0, 500 ), 'name' => mb_substr( $i['name'], 0, 255 ), 'image' => mb_substr( $i['image'], 0, 500 ), 'source' => $cur['source'], 'fetched_at' => current_time( 'mysql' ) ] );
		}
		$cur['n'] += count( (array) $items );
		if ( $items !== null && $more && $cur['page'] < self::MAX_PAGES ) {
			$cur['page']++;
			return;
		}
		if ( $cur['source'] === 'wp' && ++$cur['type'] < count( $cur['bases'] ) ) {
			$cur['page'] = 1; // the next post type
			return;
		}
		self::next_source( $cur );
	}

	/** Past the kind of list being read: done when it listed anything, else the next kind. */
	private static function next_source( array &$cur ): void {
		$next = [ 'shopify' => 'woo', 'woo' => 'wp', 'wp' => empty( $cur['sm'] ) ? 'done' : 'sitemap', 'sitemap' => 'done' ][ $cur['source'] ];
		if ( $cur['n'] > 0 || $next === 'done' ) {
			$cur['list']   = $cur['n'] > 0 ? $cur['source'] : 'none';
			$cur['source'] = 'done';
			return;
		}
		$cur['source'] = $next;
		$cur['page']   = 1;
	}

	/**
	 * One page of the list being read: [items, more pages?]. Items null: this kind of list doesn't
	 * answer (not that kind of site, past its last page, or it stopped answering).
	 * @return array{0:array<int,array{url:string,name:string,image:string}>|null,1:bool}
	 */
	private static function page_of( string $host, array $cur, int $timeout ): array {
		$page = (int) $cur['page'];
		$out  = [];
		if ( $cur['source'] === 'shopify' ) {
			$data = self::json( 'https://' . $host . '/products.json?limit=250&page=' . $page, $host, $timeout );
			$list = is_array( $data['products'] ?? null ) ? $data['products'] : null;
			if ( $list === null ) {
				return [ null, false ];
			}
			foreach ( $list as $p ) {
				if ( empty( $p['handle'] ) || empty( $p['title'] ) ) {
					continue;
				}
				$out[] = [
					'url'   => 'https://' . $host . '/products/' . rawurlencode( (string) $p['handle'] ),
					'name'  => html_entity_decode( (string) $p['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'image' => (string) ( $p['images'][0]['src'] ?? '' ),
				];
			}
			return [ $out, count( $list ) >= 250 ];
		}
		if ( $cur['source'] === 'woo' ) {
			$list = self::json( 'https://' . $host . '/wp-json/wc/store/v1/products?per_page=100&page=' . $page, $host, $timeout );
			if ( ! is_array( $list ) || ( $list && ! isset( $list[0]['permalink'] ) ) ) {
				return [ null, false ];
			}
			foreach ( $list as $p ) {
				if ( empty( $p['permalink'] ) || empty( $p['name'] ) ) {
					continue;
				}
				$out[] = [
					'url'   => (string) $p['permalink'],
					'name'  => html_entity_decode( wp_strip_all_tags( (string) $p['name'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'image' => (string) ( $p['images'][0]['src'] ?? '' ),
				];
			}
			return [ $out, count( $list ) >= 100 ];
		}
		// A WordPress site's pages, and its downloads or products when its REST API lists them (owner
		// 2026-10-14: a maker's sitemap is an 18-page file from 2020; its page list holds all
		// 71 of its products). The site's own furniture (cart, account, contact, licence…) is left out.
		$list = self::json( 'https://' . $host . '/wp-json/wp/v2/' . $cur['bases'][ $cur['type'] ] . '?per_page=100&_fields=link,title&page=' . $page, $host, $timeout );
		if ( ! is_array( $list ) || ( $list && ! isset( $list[0]['link'] ) ) ) {
			return [ null, false ]; // past the last page (WordPress answers 400), or not a list of posts
		}
		foreach ( $list as $p ) {
			$url  = (string) ( $p['link'] ?? '' );
			$name = trim( html_entity_decode( wp_strip_all_tags( (string) ( $p['title']['rendered'] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$path = '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
			if ( $name === '' || BrandSites::host( $url ) !== BrandSites::host( $host ) || ! UrlFinder::looks_like_product_page( $url ) || self::not_product_path( $path ) ) {
				continue;
			}
			$out[] = [ 'url' => $url, 'name' => $name, 'image' => '' ];
		}
		return [ $out, count( $list ) >= 100 ];
	}

	/** One throttled JSON GET on the vendor's host; null on anything but a 200 with JSON. */
	private static function json( string $url, string $host, int $timeout = 30 ) {
		$body = self::text( $url, $host, $timeout, 'application/json' );
		$data = $body === null ? null : json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * One throttled GET on the vendor's host: the body of a 200, else null. Never a private or local address
	 * (wp_safe_remote_get()): a person can name the site the drawer reads.
	 */
	public static function text( string $url, string $host, int $timeout = 30, string $accept = '*/*' ): ?string {
		$key = VendorFetcher::throttle_key( $host );
		\MMI_API_Throttler::throttle( $key, [ 'min_gap_ms' => PageStore::policy( $host )['gap_ms'] ] );
		$res = wp_safe_remote_get( $url, [ 'timeout' => $timeout, 'redirection' => 3, 'user-agent' => FetchPolicy::browser_ua(), 'headers' => [ 'Accept' => $accept ], 'limit_response_size' => 20000000 ] );
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code === 429 || $code === 503 ) {
			\MMI_API_Throttler::penalize( $key, (int) wp_remote_retrieve_header( $res, 'retry-after' ) );
		}
		if ( in_array( $code, [ 403, 429, 503 ], true ) && preg_match( self::BLOCK_RE, substr( (string) wp_remote_retrieve_body( $res ), 0, 20000 ) ) ) {
			self::$blocked_now[ $host ] = true; // the site's firewall, not a missing page: the drawer says so
		}
		return $code === 200 ? (string) wp_remote_retrieve_body( $res ) : null;
	}

	/**
	 * One file of the site's sitemap. The first step queues where sitemaps live (robots.txt's Sitemap lines,
	 * else the usual names); each later step reads one file: an index adds its files to the queue, a list
	 * adds its product pages, named from their address (a sitemap carries no titles). Pages on other sites,
	 * listings and the site's own furniture are left out, as for a WordPress page list.
	 */
	private static function sitemap_step( string $host, array &$cur, int $timeout ): void {
		global $wpdb;
		if ( ! isset( $cur['maps'] ) ) {
			$cur['maps'] = [];
			$robots      = self::text( 'https://' . $host . '/robots.txt', $host, $timeout, 'text/plain' );
			if ( $robots !== null && preg_match_all( '/^\s*sitemap:\s*(\S+)/mi', $robots, $m ) ) {
				foreach ( $m[1] as $u ) {
					if ( BrandSites::host( $u ) === BrandSites::host( $host ) ) {
						$cur['maps'][] = $u;
					}
				}
			}
			if ( ! $cur['maps'] ) {
				$cur['maps'] = [ 'https://' . $host . '/sitemap_index.xml', 'https://' . $host . '/sitemap-index.xml', 'https://' . $host . '/sitemap.xml' ];
				$cur['guess'] = 1; // the usual names: the first that answers is the sitemap, the rest are skipped
			}
			$cur['mi'] = 0;
			return;
		}
		$url = (string) ( $cur['maps'][ $cur['mi'] ] ?? '' );
		$cur['mi']++;
		$xml = $url === '' ? null : self::text( $url, $host, $timeout, 'application/xml' );
		if ( $xml !== null && preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $xml, $m ) ) {
			if ( ! empty( $cur['guess'] ) ) {
				$cur['maps']  = array_slice( $cur['maps'], 0, $cur['mi'] ); // found it: the other usual names aren't read
				$cur['guess'] = 0;
			}
			$locs = array_map( static fn( $u ) => html_entity_decode( $u, ENT_QUOTES | ENT_XML1, 'UTF-8' ), $m[1] );
			if ( stripos( $xml, '<sitemapindex' ) !== false ) {
				foreach ( $locs as $u ) {
					if ( count( $cur['maps'] ) < self::SITEMAPS && BrandSites::host( $u ) === BrandSites::host( $host ) && ! in_array( $u, $cur['maps'], true ) ) {
						$cur['maps'][] = $u;
					}
				}
			} else {
				foreach ( $locs as $u ) {
					$item = $cur['n'] < self::MAX_ITEMS ? self::item_from_url( $u, $host ) : null;
					if ( ! $item ) {
						continue;
					}
					$wpdb->replace( self::table(), [ 'host' => self::STAGE . $host, 'url_hash' => md5( self::STAGE . $u ), 'url' => mb_substr( $u, 0, 500 ), 'name' => mb_substr( $item['name'], 0, 255 ), 'image' => '', 'source' => 'sitemap', 'fetched_at' => current_time( 'mysql' ) ] );
					$cur['n']++;
				}
			}
		}
		if ( $cur['mi'] >= count( $cur['maps'] ) || $cur['mi'] >= self::SITEMAPS || $cur['n'] >= self::MAX_ITEMS ) {
			self::next_source( $cur );
		}
	}

	/**
	 * Every page the site's sitemap lists, on this site (WP-CLI: the maps runner). Found the way the drawer's
	 * "Read its pages" finds them (robots.txt's Sitemap lines, else the usual names; index files followed, at most
	 * SITEMAPS files), but nothing is left out: a product's manual or system-requirements page is what is wanted
	 * here (owner 2026-10-21). Paced per host. @return string[]
	 */
	public static function all_urls( string $host, int $max = 20000 ): array {
		$maps   = [];
		$robots = self::text( 'https://' . $host . '/robots.txt', $host, 30, 'text/plain' );
		if ( $robots !== null && preg_match_all( '/^\s*sitemap:\s*(\S+)/mi', $robots, $m ) ) {
			foreach ( $m[1] as $u ) {
				if ( BrandSites::host( $u ) === BrandSites::host( $host ) ) {
					$maps[] = $u;
				}
			}
		}
		$guess = ! $maps;
		if ( $guess ) {
			$maps = [ 'https://' . $host . '/sitemap_index.xml', 'https://' . $host . '/sitemap-index.xml', 'https://' . $host . '/sitemap.xml' ];
		}
		$urls = [];
		for ( $i = 0; $i < count( $maps ) && $i < self::SITEMAPS && count( $urls ) < $max; $i++ ) {
			$xml = self::text( $maps[ $i ], $host, 30, 'application/xml' );
			if ( $xml === null || ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $xml, $m ) ) {
				continue;
			}
			if ( $guess ) {
				$maps  = array_slice( $maps, 0, $i + 1 ); // found it: the other usual names aren't read
				$guess = false;
			}
			$locs = array_map( static fn( $u ) => html_entity_decode( $u, ENT_QUOTES | ENT_XML1, 'UTF-8' ), $m[1] );
			$sub  = stripos( $xml, '<sitemapindex' ) !== false;
			foreach ( $locs as $u ) {
				if ( BrandSites::host( $u ) !== BrandSites::host( $host ) ) {
					continue;
				}
				if ( $sub ) {
					if ( count( $maps ) < self::SITEMAPS && ! in_array( $u, $maps, true ) ) {
						$maps[] = $u;
					}
				} elseif ( count( $urls ) < $max ) {
					$urls[ $u ] = true;
				}
			}
		}
		return array_keys( $urls );
	}

	/* ── Sitemap vendors ────────────────────────────────────────────────── */

	/**
	 * The best of a product's top sitemap slug matches, judged by the page's own
	 * title. @param array<string,string[]> $index
	 */
	private static function from_pages( string $title, string $brand, array $index, array &$totals ): ?array {
		$picks = [];
		$left  = $index;
		for ( $i = 0; $i < self::PAGE_PICKS; $i++ ) {
			$m = UrlFinder::best_match( $title, $brand, $left );
			if ( ! $m || $m['score'] < UrlFinder::MIN_SCORE ) {
				break;
			}
			$picks[] = $m['url'];
			unset( $left[ $m['url'] ] );
		}
		$best = null;
		foreach ( $picks as $url ) {
			$page = PageStore::get( $url );
			if ( ! $page['from_store'] ) {
				$totals['pages_fetched']++;
			}
			if ( $page['status'] !== 'ok' ) {
				continue;
			}
			$name = self::page_name( $page['title'], $brand );
			$m    = self::score( $title, $brand, $name );
			if ( ! $m && UrlFinder::page_names_product( $page['title'], $title, $brand ) && self::editions_agree( $title, $page['title'], $brand ) ) {
				$m = [ 'score' => 70, 'why' => 'the page title names the product' ];
			}
			if ( $m && ( ! $best || $m['score'] > $best['score'] ) ) {
				$best = [ 'url' => $url, 'name' => $name !== '' ? $name : $page['title'], 'image' => self::og_image( $page['html'], $url ), 'source' => 'page' ] + $m;
			}
		}
		return $best;
	}

	/** The product part of a page title: "Product 12 Standard | Maker" or "RePitch Elements: The Essential…" → the first segment naming more than the brand. */
	public static function page_name( string $title, string $brand ): string {
		$parts = array_values( array_filter( array_map( 'trim', preg_split( '/\s+[|–—•:]\s+|\s+-\s+|(?<=\S):\s+/u', html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ), 'strlen' ) );
		$bt    = UrlFinder::tokens( $brand );
		foreach ( $parts as $p ) {
			if ( array_diff( UrlFinder::tokens( $p ), $bt ) ) {
				return $p;
			}
		}
		return '';
	}

	/** The page's og:image (absolute), '' when it has none. */
	public static function og_image( string $html, string $base ): string {
		if ( ! preg_match( '/<meta\b[^>]*\bproperty\s*=\s*["\']og:image(?::url)?["\'][^>]*>/i', $html, $m ) || ! preg_match( '/\bcontent\s*=\s*["\']([^"\']+)["\']/i', $m[0], $c ) ) {
			return '';
		}
		$src = trim( html_entity_decode( $c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( strpos( $src, '//' ) === 0 ) {
			return 'https:' . $src;
		}
		if ( strpos( $src, '/' ) === 0 ) {
			$p = wp_parse_url( $base );
			return ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . $src;
		}
		return preg_match( '#^https?://#i', $src ) ? $src : '';
	}

	/* ── Matching ───────────────────────────────────────────────────────── */

	/**
	 * The vendor item that names our product best.
	 *
	 * @param array<int,array{url:string,name:string,image:string,source:string}> $items
	 * @return array{url:string,name:string,image:string,source:string,score:int,why:string}|null
	 */
	public static function best( string $title, string $brand, array $items ): ?array {
		$best = null;
		$tie  = false;
		foreach ( $items as $i ) {
			$m = self::score( $title, $brand, (string) $i['name'] );
			if ( ! $m ) {
				continue;
			}
			$m['words'] = count( UrlFinder::tokens( (string) $i['name'] ) ); // the more specific vendor name wins a tie ("Trash Lite" over "Trash")
			if ( ! $best || $m['score'] > $best['score'] || ( $m['score'] === $best['score'] && $m['words'] > $best['words'] ) ) {
				$best = $i + $m;
				$tie  = false;
			} elseif ( $m['score'] === $best['score'] && $m['words'] === $best['words'] && ! UrlFinder::same_url( $i['url'], $best['url'] ) ) {
				$tie = true;
			}
		}
		if ( $best && $tie ) {
			$best['score'] -= 15; // two vendor products read the same: a person decides
			$best['why']   .= '; another vendor product scores the same';
		}
		return $best;
	}

	/** Our name's matching words: qualifiers, "[Box]", an add-on's host and the brand left out. @return string[] */
	public static function our_tokens( string $title, string $brand ): array {
		$t = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = preg_replace( [ '/\([^)]*\)/', '/\[[^\]]*\]/', '/\s(for|für)\s.*$/iu' ], ' ', $t );
		return UrlFinder::tokens( (string) $t, UrlFinder::tokens( $brand ) );
	}

	/**
	 * Does the vendor's name name our product? Every vendor word must be in our
	 * name, numbers must agree, edition words must be the same (a vendor name
	 * with none is the family page). @return array{score:int,why:string}|null
	 */
	public static function score( string $title, string $brand, string $vendor ): ?array {
		$ours   = self::our_tokens( $title, $brand );
		$theirs = UrlFinder::tokens( $vendor, UrlFinder::tokens( $brand ) );
		if ( ! $ours || ! $theirs || ! array_filter( $theirs, static fn( $w ) => ! ctype_digit( $w ) ) ) {
			return null;
		}
		if ( array_diff( $theirs, $ours ) ) {
			return null; // the vendor's name says something ours doesn't
		}
		// Our name proper is what comes before a ":" or " - " descriptor ("Launch Bundle: Grand Piano Suite,
		// Plucks…" is the Launch Bundle). A vendor name found only in our descriptor names something else.
		// Unless the part before it is a series ("Atelier Series: Daydream" is Daydream), or the vendor's
		// name is all of ours ("Midnight: Tenor Sax" both sides; a brand, 2026-10-14).
		$head      = self::our_tokens( preg_match( '/^[^:]*\bseries:\s+(.+)$/iu', $title, $s ) ? $s[1] : (string) preg_split( '/:\s|\s[-–—]\s/u', $title, 2 )[0], $brand );
		$in_desc   = $head && $head !== $ours && array_diff( $theirs, $head ) && array_diff( $ours, $theirs );
		$ours      = $head && ! $in_desc ? $head : $ours;
		$ed_ours   = array_values( array_intersect( $ours, self::EDITIONS ) );
		$ed_theirs = array_values( array_intersect( $theirs, self::EDITIONS ) );
		$why       = [];
		// Every vendor word is in our name; our extra words are mostly our descriptor ("Audio Processor").
		$score     = 100 - min( 30, 5 * ( count( $ours ) - count( $theirs ) ) );
		if ( $ed_theirs && array_diff( $ed_ours, $ed_theirs ) ) {
			return null; // "RX 12 Advanced" is not the "RX 12 Standard" page
		}
		if ( $ed_ours && ! $ed_theirs ) {
			$score -= 20; // the family page, or another product ("UAD Select 3 Bundle" for "Select 3 Plus")
			$why[]  = 'names no edition (' . implode( ', ', $ed_ours ) . ')';
		}
		$num_ours   = array_filter( $ours, 'ctype_digit' );
		$num_theirs = array_filter( $theirs, 'ctype_digit' );
		if ( $num_ours && ! $num_theirs ) {
			$score -= 10;
			$why[]  = 'names no version';
		}
		if ( $in_desc ) {
			$score = min( $score, 65 );
			$why[] = 'only our description names it';
		}
		if ( $score >= 100 ) {
			$why[] = 'same name';
		}
		return [ 'score' => max( 0, min( 100, $score ) ), 'why' => $why ? implode( ', ', $why ) : 'name match' ];
	}

	/** No edition word on one side that the other lacks (page titles carry extra words, so only editions count). */
	private static function editions_agree( string $title, string $page_title, string $brand ): bool {
		$a = array_intersect( self::our_tokens( $title, $brand ), self::EDITIONS );
		$b = array_intersect( UrlFinder::tokens( $page_title ), self::EDITIONS );
		return ! array_diff( $a, $b ) && ! array_diff( $b, $a );
	}
}
