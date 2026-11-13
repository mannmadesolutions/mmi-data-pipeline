<?php
/**
 * Finds vendor product pages for products that have none (or a dead one) by
 * matching the product name against the vendor's own sitemap — Product Data
 * Quality Phase 6 / SkuPort handoff E0 (the user's idea, probed 2026-09-24).
 *
 * Vendor sites only (user decision 2026-09-24): the host is the one the
 * brand's own verified `vendor_product_url`s already point at — never a
 * search engine, never a reseller. Only sitemaps are read here; pages are
 * fetched later by VendorFetcher, through the same per-host throttle and
 * robots.txt check.
 *
 * Candidates live in their own table and never touch the product. A match
 * scoring AUTO_SCORE+ is used by VendorFetcher straight away (the copy it
 * yields is still only a proposal, reviewed like every other); lower matches
 * wait for a person (`wp mmpt urls`).
 *
 * Runs from WP-CLI only (`wp mmpt find_urls`, nightly system cron), never in
 * a web request: a large sitemap at one request per 4 s per host outlasts an
 * Action Scheduler tick.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UrlFinder {

	const NO_PAGE = 'no_page'; // the read row's fetch_status for "this product has no vendor page" (a person's decision)

	const DB_OPTION     = 'mmpt_url_db_version';
	const DB_VERSION    = '2'; // 2: vendor_name + image (VendorCatalog, 2026-10-07)
	const STATE         = 'mmi_admin_product_titles_urlfinder_state';
	const AUTO_SCORE    = 80;
	const CODE_SCORE    = 95; // the page's address is the product's part number (code_of()): stronger than any match of words
	const MIN_SCORE     = 50;
	const MAX_SITEMAPS  = 40;
	const MAX_URLS      = 40000;
	const MAX_BYTES     = 12000000;
	const CACHE_S       = 7 * DAY_IN_SECONDS;

	/** Hosts that sell many brands — never treated as a brand's own site. */
	const RESELLER_HOSTS = [ 'www.ilio.com', 'ilio.com', 'pluginfox.com', 'www.pluginfox.com', 'www.pluginboutique.com', 'www.kvraudio.com', 'www.bestservice.com', 'www.sweetwater.com' ];

	/**
	 * Vendor sites named by the user for brands with no verified vendor URL to
	 * learn the host from (lowercase brand name → host, optionally with the
	 * path the shop lives under). 2026-09-27: MoForte, Dear Reality (being
	 * absorbed into Sennheiser) and XILS-lab (a WordPress shop under /store).
	 */
	/** Brand sites a store names by hand (lowercase brand → host[/path]): filter mmi_pipeline_brand_hosts (this site: SitePolicy). */
	public static function named_hosts(): array {
		static $h = null;
		return $h ??= array_change_key_case( (array) apply_filters( 'mmi_pipeline_brand_hosts', [] ), CASE_LOWER );
	}

	/** Words that describe the offer or the listing, not the product (dropped before matching). */
	const NOISE = [ 'download', 'dld', 'esd', 'upgrade', 'upgrades', 'upg', 'crossgrade', 'xgrade', 'update', 'from', 'to', 'subscription', 'sub', 'renewal', 'renew', 'edu', 'educational', 'education', 'academic', 'student', 'teacher', 'institution', 'license', 'licence', 'perpetual', 'perp', 'year', 'years', 'yr', 'month', 'monthly', 'annual', 'the', 'a', 'an', 'and', 'of', 'for', 'by', 'with', 'in', 'on', 'new', 'full', 'version', 'boxed', 'serial', 'code', 'plugin', 'plugins', 'plug', 'software', 'vst', 'au', 'aax', 'mac', 'win', 'windows', 'pc', 'virtual', 'instrument', 'instruments', 'audio', 'sound', 'sounds', 'collection', 'effect', 'effects', 'multi' ];

	/** URL segments that are never a product page. */
	const NOT_PRODUCT = '#/(blog|news|press|support|kb|faq|forum|forums|community|tag|tags|category|categories|author|manual|manuals|docs|documentation|help|learn|tutorials?|artists?|jobs|careers|legal|privacy|terms|cart|checkout|account|login|search|events?|videos?)(/|$)#i';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_url_candidates';
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
  url varchar(500) NOT NULL DEFAULT '',
  host varchar(190) NOT NULL DEFAULT '',
  score tinyint(3) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'candidate',
  reason varchar(40) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  vendor_name varchar(255) NOT NULL DEFAULT '',
  image varchar(500) NOT NULL DEFAULT '',
  found_at datetime DEFAULT NULL,
  PRIMARY KEY  (product_id),
  KEY status (status),
  KEY score (score)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * The URL VendorFetcher should use for a product with no usable stored URL:
	 * an approved candidate. '' when there is none. (Until 2026-10-11 an open
	 * candidate at AUTO_SCORE+ was read as the product's page too, before the
	 * stored URL: a sitemap match for Drumagog 6 Pro, the library's "sounds"
	 * page, was read and its copy kept as if it were the product's. 118 open
	 * candidates qualified. A page waiting for a decision is read for the
	 * decision only, never as the product's page.)
	 */
	public static function usable_url( int $product_id ): string {
		global $wpdb;
		$t = self::table();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$t} WHERE product_id = %d AND status = 'approved'", $product_id ) );
	}

	/** The product's URL row: url, status, reason, score, note, vendor_name (null when it has none). */
	public static function row( int $product_id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT url, status, reason, score, note, vendor_name FROM ' . self::table() . ' WHERE product_id = %d', $product_id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Is the product's decided (approved) page one a better page may be proposed over? Only when the
	 * product's page sits on a site its brand saved as a reseller, a store or not its own (owner
	 * 2026-10-14: a brand's approved pages on a distributor's site were never looked past). The current
	 * URL stays until a person approves another.
	 */
	public static function reopened( int $id, array $row ): bool {
		return ( $row['status'] ?? '' ) === 'approved' && BrandSites::on_bad_site( $id, (string) get_post_meta( $id, 'vendor_product_url', true ) );
	}

	/** Record a URL for the product (the Vendor pages decision row): a proposal, or one adopted by a rule. */
	public static function offer( int $id, string $url, int $score, string $status, string $reason, string $note ): void {
		self::store( $id, $url, strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), $score, $status, $reason, $note );
	}

	/**
	 * Products needing a URL: no stored vendor URL, an unverified one, one whose last fetch was a
	 * 404/410, or one on a site its brand saved as a reseller, a store or not its own (even an approved
	 * one: reopened()) — grouped by brand. @return array<string,array{host:string,ids:int[]}>
	 */
	public static function gaps( array $only_brands = [] ): array {
		global $wpdb;
		$d    = Descriptions::table();
		$c    = self::table();
		$rows = $wpdb->get_results(
			"SELECT p.ID id, t.name brand, t.term_id, u.meta_value url, v.meta_value verified, d.fetch_status fs, COALESCE( c.status, '' ) cs
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand'
			 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 JOIN {$d} d ON d.product_id = p.ID
			 LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
			 LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified'
			 LEFT JOIN {$c} c ON c.product_id = p.ID
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND ( c.product_id IS NULL OR c.status IN ( 'candidate', 'approved' ) )",
			ARRAY_A
		);
		$out      = [];
		$excluded = array_flip( Exclusions::ids() );
		$bad      = BrandSites::bad_hosts();
		$gone     = BrandSites::gone_ids(); // brands with no maker site: nothing to find
		foreach ( $rows as $r ) {
			if ( isset( $excluded[ (int) $r['id'] ] ) || isset( $gone[ (int) $r['term_id'] ] ) ) {
				continue;
			}
			// audit-ok: $only_brands is the caller's brand filter (one brand, or CLI-named brands)
			if ( $only_brands && ! in_array( strtolower( $r['brand'] ), $only_brands, true ) ) {
				continue;
			}
			// A gap: no URL, an unverified one, a dead one, or a page on a site the brand marked as a reseller,
			// a store or not its own (Brands & sites: a better page is proposed; the current one stays).
			if ( (string) $r['fs'] === self::NO_PAGE ) {
				continue; // a person said it has none
			}
			$url  = (string) $r['url'];
			$away = in_array( BrandSites::host( $url ), $bad[ (int) $r['term_id'] ] ?? [], true );
			$gap  = $r['cs'] === 'approved' ? $away // a decided page is looked past only when it sits on such a site
				: ! str_starts_with( $url, 'http' ) || ! self::is_verified( $r['verified'] ) || in_array( (string) $r['fs'], [ 'http_404', 'http_410' ], true ) || $away;
			if ( ! $gap ) {
				continue;
			}
			$out[ $r['brand'] ]['term_id'] = (int) $r['term_id'];
			$out[ $r['brand'] ]['ids'][]   = (int) $r['id'];
		}
		$hosts = self::brand_hosts( array_column( $out, 'term_id' ) );
		foreach ( $out as $brand => &$g ) {
			$g['hosts'] = self::search_sites( (int) $g['term_id'], (string) $brand, $hosts[ $g['term_id'] ] ?? '' );
			$g['host']  = $g['hosts'][0] ?? '';
		}
		return $out;
	}

	/**
	 * Where the finder looks for a brand's pages, in order (owner 2026-10-11: the Brand website first):
	 * a site named for the brand, its Brand website, its official then partner sites (Brands & sites),
	 * then the site most of its verified pages are on. Sites the brand marked as a reseller, a store or
	 * not its own are left out. @return string[] hosts (a named site may carry a path)
	 */
	public static function search_sites( int $term_id, string $brand, string $common = '' ): array {
		$roles = BrandSites::roles()[ $term_id ] ?? [];
		$list  = [ self::named_hosts()[ strtolower( html_entity_decode( $brand, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ] ?? '', self::brand_website_host( $term_id ) ];
		foreach ( [ 'official', 'partner' ] as $want ) {
			foreach ( $roles as $h => $role ) {
				if ( $role === $want ) {
					$list[] = $h;
				}
			}
		}
		$list[] = $common;
		$out    = [];
		$seen   = [];
		foreach ( $list as $h ) {
			$k = BrandSites::host( (string) $h );
			if ( $k === '' || isset( $seen[ $k ] ) || in_array( $roles[ $k ] ?? '', BrandSites::BAD, true ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = (string) $h;
		}
		return $out;
	}

	/**
	 * Each brand's first search site (search_sites()) and where it comes from, for the brand screens (Screens).
	 * @param array<int,string> $brands term id → name
	 * @return array<int,array{host:string,from:string}> from: named | website | role | pages | ''
	 */
	public static function brand_sites( array $brands ): array {
		$common = self::brand_hosts( array_keys( $brands ) );
		$out    = [];
		foreach ( $brands as $id => $name ) {
			$sites = self::search_sites( (int) $id, (string) $name, $common[ $id ] ?? '' );
			$first = $sites[0] ?? '';
			$k     = BrandSites::host( $first );
			$from  = match ( true ) {
				$first === '' => '',
				isset( self::named_hosts()[ strtolower( html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ] ) => 'named',
				$k === BrandSites::host( self::brand_website_host( (int) $id ) ) => 'website',
				isset( ( BrandSites::roles()[ (int) $id ] ?? [] )[ $k ] ) => 'role',
				default => 'pages',
			};
			$out[ $id ] = [ 'host' => $first, 'from' => $from ];
		}
		return $out;
	}

	/** The host of the brand's own website field (shared MMI_Brand_Website), '' when unset or a reseller. */
	private static function brand_website_host( int $term_id ): string {
		if ( ! class_exists( 'MMI_Brand_Website' ) ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( \MMI_Brand_Website::get( $term_id ), PHP_URL_HOST ) );
		return in_array( $host, self::RESELLER_HOSTS, true ) ? '' : $host;
	}

	/** Each brand's own site: the most common host among its verified vendor URLs, resellers excluded. */
	private static function brand_hosts( array $term_ids ): array {
		global $wpdb;
		if ( ! $term_ids ) {
			return [];
		}
		$in   = implode( ',', array_map( 'intval', $term_ids ) );
		$rows = $wpdb->get_results(
			"SELECT tt.term_id, u.meta_value url FROM {$wpdb->postmeta} u
			 JOIN {$wpdb->postmeta} v ON v.post_id = u.post_id AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
			 JOIN {$wpdb->term_relationships} tr ON tr.object_id = u.post_id
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' AND tt.term_id IN ({$in})
			 WHERE u.meta_key = 'vendor_product_url' AND u.meta_value LIKE 'http%'",
			ARRAY_A
		);
		$count = [];
		foreach ( $rows as $r ) {
			$host = strtolower( (string) wp_parse_url( $r['url'], PHP_URL_HOST ) );
			if ( $host !== '' && ! in_array( $host, self::RESELLER_HOSTS, true ) ) {
				$count[ (int) $r['term_id'] ][ $host ] = ( $count[ (int) $r['term_id'] ][ $host ] ?? 0 ) + 1;
			}
		}
		$out = [];
		foreach ( $count as $tid => $hosts ) {
			arsort( $hosts );
			$out[ $tid ] = (string) array_key_first( $hosts );
		}
		return $out;
	}

	/**
	 * Match every gap product of every brand (or $brands) against its vendor's
	 * sitemap and store the best candidate. CLI only.
	 */
	public static function run( array $brands = [] ): array {
		if ( ! ( ( defined( 'WP_CLI' ) && WP_CLI ) || Background::$stepping ) ) {
			return [ 'error' => 'URL discovery runs from WP-CLI only (wp mmpt find_urls).' ];
		}
		self::maybe_install();
		$totals = [ 'brands' => 0, 'no_host' => 0, 'no_sitemap' => 0, 'products' => 0, 'auto' => 0, 'review' => 0, 'no_match' => 0 ];
		foreach ( self::gaps( array_map( 'strtolower', $brands ) ) as $brand => $g ) {
			$totals['brands']++;
			if ( $g['host'] === '' ) {
				$totals['no_host']++;
				continue;
			}
			$load = function_exists( 'sys_getloadavg' ) ? (float) ( sys_getloadavg()[0] ?? 0 ) : 0.0;
			while ( $load > VendorFetcher::LOAD_LIMIT ) {
				sleep( 20 );
				$load = (float) ( sys_getloadavg()[0] ?? 0 );
			}
			// Each of the brand's sites in turn, for the products still without a match: a maker with a sitemap of its own may
			// keep its product pages on another site (moforte.com lists none; ilio.com, also its official site, has one for each).
			$titles = Descriptions::inputs( $g['ids'] );
			$left   = $g['ids'];
			$found  = false;
			$totals['products'] += count( $left );
			foreach ( $g['hosts'] ?? [ $g['host'] ] as $site ) {
				if ( ! $left ) {
					break;
				}
				[ $host, $base ] = array_pad( explode( '/', $site, 2 ), 2, '' );
				$urls            = self::sitemap_urls( $host, $base !== '' ? '/' . trim( $base, '/' ) : '' );
				if ( ! $urls ) {
					continue;
				}
				$found = true;
				$index = self::index_urls( $urls );
				$slugs = self::slugs( array_keys( $index ) );
				foreach ( $left as $k => $id ) {
					$code = self::code_of( (int) $id );
					$own  = self::code_url( $code, $slugs );
					$m    = $own !== '' ? [ 'url' => $own, 'score' => self::CODE_SCORE ] : self::best_match( (string) ( $titles[ $id ]['title'] ?? get_the_title( $id ) ), $brand, $index );
					if ( ! $m || $m['score'] < self::MIN_SCORE ) {
						continue;
					}
					unset( $left[ $k ] );
					// A stronger kind of proposal (a feed's page, a redirect, the vendor's own list) is never
					// replaced by a sitemap guess; only weak ones are (2026-10-11).
					$old = self::row( $id );
					if ( $old && $old['status'] === 'candidate' && ! in_array( $old['reason'], VendorCatalog::WEAK, true ) ) {
						$totals['kept'] = ( $totals['kept'] ?? 0 ) + 1;
						continue;
					}
					if ( $old && $old['status'] === 'candidate' && $old['reason'] === 'vendor_catalog' && (int) $old['score'] >= $m['score'] ) {
						$totals['kept'] = ( $totals['kept'] ?? 0 ) + 1;
						continue;
					}
					if ( $m['score'] === self::CODE_SCORE ) {
						self::store( (int) $id, $m['url'], $host, $m['score'], 'candidate', 'sku_slug', 'Its address is its part number: ' . $code );
					} else {
						self::store( (int) $id, $m['url'], $host, $m['score'] );
					}
					$totals[ $m['score'] >= self::AUTO_SCORE ? 'auto' : 'review' ]++;
				}
			}
			if ( ! $found ) {
				$totals['no_sitemap']++;
				$totals['products'] -= count( $g['ids'] );
				Module::info( 'URL finder: no sitemap', [ 'brand' => $brand, 'host' => $g['host'] ] );
				continue;
			}
			$totals['no_match'] += count( $left );
		}
		Module::set_state( self::STATE, [ 'last_run' => current_time( 'mysql' ), 'totals' => $totals ] );
		Module::info( 'URL finder run', $totals );
		return $totals;
	}

	/**
	 * Products a person said have no vendor page (Vendor pages → ✎ → No vendor page; owner 2026-10-12,
	 * a bundle: "There is no vendor URL for this product"). Kept as the read row's
	 * fetch_status, so the list needs no extra join. Nothing automatic proposes, adopts or reads a URL
	 * for them; a person setting or approving a URL lifts it. @return array<int,bool>
	 */
	public static function no_page_ids( bool $fresh = false ): array {
		static $memo = null;
		if ( $memo === null || $fresh ) {
			global $wpdb;
			$memo = array_fill_keys( array_map( 'intval', $wpdb->get_col( 'SELECT product_id FROM ' . Descriptions::table() . " WHERE fetch_status = '" . self::NO_PAGE . "'" ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $memo;
	}

	public static function is_no_page( int $id ): bool {
		return isset( self::no_page_ids()[ $id ] );
	}

	private static function store( int $id, string $url, string $host, int $score, string $status = 'candidate', string $reason = 'sitemap_slug', string $note = '' ): bool {
		$url = self::clean_url( $url ); // every proposal is stored without tracking codes or shop actions
		if ( $status === 'candidate' && self::same_url( $url, (string) get_post_meta( $id, 'vendor_product_url', true ) ) ) {
			return false; // already the product's URL: nothing to decide
		}
		if ( BrandSites::blocked( $id, $url ) ) {
			return false; // the brand marked this site "not this brand" (Brands & sites)
		}
		if ( self::is_no_page( $id ) ) {
			return false; // a person said this product has no vendor page
		}
		global $wpdb;
		$wpdb->replace(
			self::table(),
			[ 'product_id' => $id, 'url' => mb_substr( $url, 0, 500 ), 'host' => $host, 'score' => $score, 'status' => $status, 'reason' => $reason, 'note' => mb_substr( $note, 0, 255 ), 'found_at' => current_time( 'mysql' ) ],
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
		return true;
	}

	/**
	 * Make an approved URL the product's vendor URL: the meta VendorFetcher
	 * and mmi-google-services read, which nothing wrote before 2026-10-06.
	 * Plain strings, so update_post_meta()'s unslash can't damage them.
	 */
	public static function adopt( int $id, string $url, bool $by_person = false ): bool {
		if ( self::is_no_page( $id ) ) {
			if ( ! $by_person ) {
				return false; // a person said this product has no vendor page; only a person changes that
			}
			Descriptions::upsert( $id, [ 'fetch_status' => '' ] );
			self::no_page_ids( true );
		}
		Write::field( $id, 'vendor_product_url', esc_url_raw( self::clean_url( $url ) ), 'product-vendor-links', 'force' );
		Write::field( $id, 'vendor_product_url_verified', 'TRUE', 'product-vendor-links', 'force' );
		return true;
	}

	/**
	 * A `vendor_product_url_verified` value that means verified. adopt() writes TRUE, but the JetEngine field on the
	 * product screen writes true/false and older imports wrote 1 (2026-10-16: 30 `true` and 5 `1` were never read,
	 * the scraper wanted TRUE exactly). In SQL: UPPER( v.meta_value ) IN ('TRUE','1').
	 */
	public static function is_verified( $value ): bool {
		return in_array( strtoupper( trim( (string) $value ) ), [ 'TRUE', '1' ], true );
	}

	/** Same page apart from scheme, "www.", host case and a trailing slash. */
	/**
	 * Query parameters that never change which page an address is: tracking codes and shop actions (owner
	 * 2026-10-22: a listing card's link `…/rhodes-v8-plugin/?dm_i=…&add-to-cart=19395` was proposed for the product
	 * whose URL is `…/rhodes-v8-plugin/`, and redirected to the basket).
	 */
	const JUNK_PARAMS = '/^(?:utm_[a-z_]+|dm_i|fbclid|gclid|gbraid|wbraid|msclkid|mc_cid|mc_eid|_ga|_gl|srsltid|ref|add-to-cart|add_to_cart|quantity)$/i';

	/** A shop's basket, checkout, account, login or error page: never a product's page. */
	const NOT_PRODUCT_PAGE = '~/(?:cart|basket|checkout|bag|log-?in|sign-?in|auth|account|my-account|regist(?:er|ration)|error|404|(?:page-)?not-found)(?:[/?.#]|$)~i'; // registration: ikmultimedia.com/registration/ (2026-10-27)

	/** The address without tracking codes, shop actions (JUNK_PARAMS) or a #fragment; the rest of its query kept as written. */
	public static function clean_url( string $url ): string {
		$url = (string) strtok( trim( $url ), '#' );
		$q   = strpos( $url, '?' );
		if ( $q === false ) {
			return $url;
		}
		$keep = array_filter( explode( '&', substr( $url, $q + 1 ) ), static fn( $pair ) => $pair !== '' && ! preg_match( self::JUNK_PARAMS, rawurldecode( (string) strtok( $pair, '=' ) ) ) );
		return substr( $url, 0, $q ) . ( $keep ? '?' . implode( '&', $keep ) : '' );
	}

	/** A page about the product rather than its page: support, documentation, news, a blog post, its requirements. */
	const INFO_PAGE = '~/(?:blog|news|press|support|kb|faq|forums?|community|manuals?|docs|documentation|help|tutorials?|system-requirements|tech-specs)(?:[/?.#]|$)~i';

	/**
	 * Why a product's maker URL plainly isn't its page (owner 2026-10-27: SKU 1143-1600's description was read from
	 * ikmultimedia.com/registration/; "include an alert/check for this in the Product Data process"). One page's
	 * Product pages lists these as waiting ("Not a product page"); a person may keep an info or home page as the
	 * product's page (PAGE_OK_META), never an account page.
	 */
	const NOT_PRODUCT_WHY = [
		'account' => 'A login, basket, account or registration page: never a product’s page.',
		'info'    => 'A support, documentation, news or blog page, not the product’s own page.',
		'home'    => 'The maker’s home page: no product data is read from it.',
	];
	const PAGE_OK_META = '_mmpt_page_ok'; // the maker URL a person kept as the product's page anyway

	/** The NOT_PRODUCT_WHY key for this maker URL, or '' when it may be the product's page. */
	public static function not_product_why( string $url ): string {
		if ( self::is_not_product_page( $url ) ) {
			return 'account';
		}
		if ( preg_match( self::INFO_PAGE, (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
			return 'info';
		}
		return PageEvents::is_home( $url ) ? 'home' : '';
	}

	/** Is this address a basket, checkout, account, login or error page (NOT_PRODUCT_PAGE)? */
	public static function is_not_product_page( string $url ): bool {
		return (bool) preg_match( self::NOT_PRODUCT_PAGE, (string) wp_parse_url( $url, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $url, PHP_URL_QUERY ) );
	}

	public static function same_url( string $a, string $b ): bool {
		$norm = static function ( string $u ): string {
			$p = wp_parse_url( self::clean_url( $u ) ); // tracking codes and shop actions don't make another page
			return preg_replace( '/^www\./', '', strtolower( (string) ( $p['host'] ?? '' ) ) ) . rtrim( (string) ( $p['path'] ?? '' ), '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		};
		return $norm( $a ) === $norm( $b );
	}

	/* ── Distributor feeds ──────────────────────────────────────────────── */

	/** A URL the distributor names for the exact SKU outranks any slug match. */
	const FEED_SCORE = 100;
	/** A feed URL given for this many products or more is a list or a dealer page unless its address names the product. */
	const SHARED_FEED_MIN = 5;
	/** A feed URL that needs a person: it disagrees with a working verified URL, or sits on a reseller's site. */
	const REVIEW_SCORE = 60;
	/** Hosts a feed's URL field sometimes holds that are never a vendor product page. */
	const NOT_VENDOR_HOSTS = [ 'google.com', 'www.google.com', 'google.ca', 'www.google.ca', 'youtube.com', 'www.youtube.com', 'youtu.be', 'facebook.com', 'www.facebook.com', 'drive.google.com' ];

	/**
	 * Vendor URLs the distributor gives for the exact SKU: XChange's `info`
	 * field (4,953 of 5,010 feed rows on 2026-10-06), never read before.
	 *
	 * - No URL (or a dead one) and the URL's slug names the product → approved
	 *   and adopted at once.
	 * - Everything else waits for a person (`wp mmpt urls`), the stored URL in
	 *   the note: it disagrees with a stored URL, sits on a reseller's site, or
	 *   its slug doesn't name the product. The first run (2026-10-06) adopted
	 *   on "no working URL" alone; XChange often gives a listing page
	 *   ("waves.com/plugins") where we held the product's own page, so that
	 *   run was rolled back and the slug test added.
	 * - A row a person (or an earlier pass) approved or rejected is kept.
	 *
	 * CLI only: the feed is ~13 MB.
	 */
	public static function from_feeds( bool $dry = false ): array {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return [ 'error' => 'Feed URL import runs from WP-CLI only (wp mmpt find_urls).' ];
		}
		global $wpdb;
		self::maybe_install();
		$feed   = self::xchange_info_urls();
		$totals = [ 'feed_urls' => count( $feed ), 'products' => 0, 'adopted' => 0, 'review' => 0, 'agrees' => 0, 'kept_decision' => 0, 'kept_sitemap' => 0, 'shared_unnamed' => 0, 'dry_run' => $dry ];
		// a maker's dealer page was the feed's URL for 42 SKUs and was approved for 22 (2026-10-26).
		$shared = array_count_values( array_map( [ self::class, 'feed_key' ], $feed ) );
		if ( ! $feed ) {
			return $totals;
		}
		$c        = self::table();
		$d        = Descriptions::table();
		$rows     = $wpdb->get_results(
			"SELECT p.ID id, p.post_title title, s.meta_value sku, u.meta_value url, v.meta_value verified, d.fetch_status, c.status cand_status, c.url cand_url, c.score cand_score,
			   ( SELECT MIN( t.name ) FROM {$wpdb->term_relationships} tr
			     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand'
			     JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			     WHERE tr.object_id = p.ID ) brand
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_sku'
			 LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
			 LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified'
			 LEFT JOIN {$d} d ON d.product_id = p.ID
			 LEFT JOIN {$c} c ON c.product_id = p.ID
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'",
			ARRAY_A
		);
		$excluded = array_flip( Exclusions::ids() );
		foreach ( $rows as $r ) {
			$id  = (int) $r['id'];
			$url = $feed[ (string) $r['sku'] ] ?? '';
			if ( $url === '' || isset( $excluded[ $id ] ) ) {
				continue;
			}
			$totals['products']++;
			$stored  = (string) $r['url'];
			$working = self::is_verified( $r['verified'] ) && str_starts_with( $stored, 'http' ) && ! in_array( (string) $r['fetch_status'], [ 'http_404', 'http_410' ], true );
			if ( $working && self::same_url( $stored, $url ) ) {
				$totals['agrees']++;
				continue;
			}
			if ( $shared[ self::feed_key( $url ) ] >= self::SHARED_FEED_MIN && ! self::slug_in_title( $url, (string) $r['title'], (string) $r['brand'] ) ) {
				$totals['shared_unnamed']++; // a maker's /plugins or /activation page: not this product's page, so not even a proposal
				continue;
			}
			if ( in_array( (string) $r['cand_status'], [ 'approved', 'rejected' ], true ) ) {
				$totals['kept_decision']++;
				continue;
			}
			$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$empty  = ! str_starts_with( $stored, 'http' ) || in_array( (string) $r['fetch_status'], [ 'http_404', 'http_410' ], true );
			$usable = $r['cand_status'] === 'candidate' && (int) $r['cand_score'] >= self::AUTO_SCORE;
			$agrees = $usable && self::same_url( (string) $r['cand_url'], $url );
			if ( $usable && ! $agrees ) {
				$totals['kept_sitemap']++; // a sitemap match already in use is never displaced by a feed URL waiting for review
				continue;
			}
			$why = ( $agrees && ! $working ) ? '' : match ( true ) {
				$working                                       => 'xchange_feed_differs',
				! $empty                                       => 'xchange_feed_unverified',
				in_array( $host, self::RESELLER_HOSTS, true )  => 'xchange_feed_reseller',
				! self::slug_in_title( $url, (string) $r['title'], (string) $r['brand'] ) => 'xchange_feed_unconfirmed',
				default                                        => '',
			};
			if ( $why !== '' ) {
				$dry || self::store( $id, $url, $host, self::REVIEW_SCORE, 'candidate', $why, $stored !== '' ? 'stored: ' . $stored : '' );
				$totals['review']++;
				$totals[ $why ] = ( $totals[ $why ] ?? 0 ) + 1;
				continue;
			}
			if ( ! $dry ) {
				if ( self::store( $id, $url, $host, self::FEED_SCORE, 'approved', $agrees ? 'xchange_feed_sitemap' : 'xchange_feed', $stored !== '' ? 'replaced: ' . $stored : '' ) ) {
					self::adopt( $id, $url );
				}
			}
			$totals['adopted']++;
		}
		$dry || Module::info( 'URL finder: feed URLs', $totals );
		return $totals;
	}

	/** A last path segment holding one of these names a listing ("/uad-plugins.html", "/sound-packs"), not a product. */
	const LISTING_WORDS = '/(^|[-_])(plugins|products|instruments|effects|bundles|collections|libraries|packs|sounds|expansions|accessories|marketing)($|[-_])/i';

	/** Trailing path segments that are a tab of a product page, not its name ("/30-8676-hz/overview/"). */
	const PAGE_TABS = [ 'overview', 'features', 'specs', 'specifications', 'details', 'index', 'info', 'buy', 'description' ];

	/**
	 * Every word of the URL's last named path segment (brand and noise words
	 * aside) is in the product title: "/plugins/doubler" for "Waves Doubler",
	 * "/pro-tools" for every Pro Tools edition. A listing page ("/plugins",
	 * "/products/sound-packs", "/marketing") names no product and fails.
	 */
	public static function slug_in_title( string $url, string $title, string $brand ): bool {
		$path = preg_replace( '/\.(html?|php|aspx?)$/i', '', (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$segs = array_values( array_filter( explode( '/', $path ), static fn( $x ) => $x !== '' && ! preg_match( '/^[a-z]{2}([_-][a-z]{2})?$/i', $x ) ) );
		while ( $segs && in_array( strtolower( end( $segs ) ), self::PAGE_TABS, true ) ) {
			array_pop( $segs );
		}
		if ( ! $segs || preg_match( self::LISTING_WORDS, (string) end( $segs ) ) ) {
			return false;
		}
		$slug = self::tokens( (string) end( $segs ), array_merge( self::tokens( $brand ), [ 'products', 'product', 'shop', 'store', 'p', 'item' ] ) );
		if ( ! $slug || ! array_filter( $slug, static fn( $w ) => ! ctype_digit( $w ) ) ) {
			return false;
		}
		$words = array_flip( self::tokens( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) );
		foreach ( $slug as $w ) {
			if ( ! isset( $words[ $w ] ) ) {
				return false;
			}
		}
		return true;
	}

	/* ── Plugivery product pages: the manufacturer's own link ─────────── */

	/**
	 * Plugivery's product page for each SKU carries a "Manufacturer's Product
	 * Page" button (80s Spaces → nomadfactory.com/products/p3054-80s-Spaces/).
	 * For products keyed to Plugivery (`_mmi_supplier_sku_plugivery`) with no
	 * working vendor URL, the page is fetched through PageStore (kept for the
	 * requirements/licensing extractors) and the link is:
	 * - adopted when the product has no URL or a dead one;
	 * - a candidate for review when an unverified URL is stored;
	 * - ignored when it's a home page, a Plugivery page, or a usable sitemap
	 *   match already exists that it disagrees with.
	 * CLI only; at most $max pages a run.
	 *
	 * Since 2026-10-28 (owner: the distributors' data is the fallback) the link mmi-distributors already read
	 * (MMI_Plugivery_Pages) is used without fetching the page again, and with no cap, and products not keyed
	 * to Plugivery take the link of the same maker's Plugivery product of the same name (Pictures::plugivery_match(),
	 * reason `plugivery_link_by_name`).
	 */
	public static function from_plugivery_pages( int $max = 300, bool $dry = false ): array {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return [ 'error' => 'Runs from WP-CLI only.' ];
		}
		global $wpdb;
		self::maybe_install();
		$file   = mmi_shared_lib_json_dir() . 'plugivery-product-details.json';
		$detail = is_readable( $file ) ? (array) ( json_decode( (string) file_get_contents( $file ), true )['products'] ?? [] ) : []; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$c      = self::table();
		$d      = Descriptions::table();
		$rows   = $wpdb->get_results(
			"SELECT p.ID id, k.meta_value pv, u.meta_value url, v.meta_value verified, d.fetch_status, c.status cand_status, c.url cand_url, c.score cand_score
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = '_mmi_supplier_sku_plugivery' AND k.meta_value <> ''
			 LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
			 LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified'
			 LEFT JOIN {$d} d ON d.product_id = p.ID
			 LEFT JOIN {$c} c ON c.product_id = p.ID
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'",
			ARRAY_A
		);
		$read     = class_exists( '\MMI_Plugivery_Pages' );
		$totals   = [ 'keyed' => count( array_filter( $rows, static fn( $r ) => (string) $r['pv'] !== '' ) ), 'by_name' => 0, 'stored_links' => 0, 'pages' => 0, 'adopted' => 0, 'review' => 0, 'no_link' => 0, 'skipped_has_url' => 0, 'kept_decision' => 0, 'excluded' => 0, 'dry_run' => $dry ];
		$excluded = array_flip( Exclusions::ids() );
		foreach ( $rows as $r ) {
			if ( isset( $excluded[ (int) $r['id'] ] ) ) {
				$totals['excluded']++;
				continue;
			}
			$stored  = (string) $r['url'];
			$dead    = in_array( (string) $r['fetch_status'], [ 'http_404', 'http_410' ], true );
			$working = self::is_verified( $r['verified'] ) && str_starts_with( $stored, 'http' ) && ! $dead;
			$usable  = $r['cand_status'] === 'approved' || ( $r['cand_status'] === 'candidate' && (int) $r['cand_score'] >= self::AUTO_SCORE );
			if ( $working || $usable ) {
				$totals['skipped_has_url']++;
				continue;
			}
			if ( $r['cand_status'] === 'rejected' ) {
				$totals['kept_decision']++;
				continue;
			}
			$pv      = (string) $r['pv'];
			$by_name = false;
			if ( $pv === '' ) {
				$pv = $read ? Pictures::plugivery_match( (int) $r['id'] ) : '';
				if ( $pv === '' ) {
					continue;
				}
				$by_name = true;
				$totals['by_name']++;
			}
			$pv_page = $read ? \MMI_Plugivery_Pages::get( $pv ) : null;
			if ( $pv_page && isset( $pv_page['page_date'] ) ) {
				// Already read by mmi-distributors: no second fetch, no cap.
				$totals['stored_links']++;
				$link = self::clean_feed_url( (string) ( $pv_page['maker_url'] ?? '' ) );
			} else {
				$page_url = (string) ( $detail[ $pv ]['url'] ?? '' );
				if ( $page_url === '' || $totals['pages'] >= $max ) {
					continue;
				}
				$totals['pages']++;
				$page = PageStore::get( preg_replace( '#^http://#i', 'https://', $page_url ) );
				$link = $page['status'] === 'ok' && preg_match( '/<a\s[^>]*href="([^"]+)"[^>]*>(?:(?!<\/a>).){0,300}?Manufacturer(?:&#0?39;|\x27|’|&rsquo;)s Product Page/is', $page['html'], $m ) ? self::clean_feed_url( html_entity_decode( $m[1] ) ) : '';
			}
			$host = strtolower( (string) wp_parse_url( $link, PHP_URL_HOST ) );
			if ( $link === '' || strpos( $host, 'plugivery' ) !== false ) {
				$totals['no_link']++;
				continue;
			}
			$id    = (int) $r['id'];
			$empty = ! str_starts_with( $stored, 'http' ) || $dead;
			if ( ! self::looks_like_product_page( $link ) ) {
				$dry || self::store( $id, $link, $host, self::REVIEW_SCORE, 'candidate', $by_name ? 'plugivery_link_by_name' : 'plugivery_link_doubtful', $stored !== '' ? 'stored: ' . $stored : '' );
				$totals['review']++;
				continue;
			}
			if ( ! $empty ) {
				$dry || self::store( $id, $link, $host, self::REVIEW_SCORE, 'candidate', 'plugivery_link_unverified', 'stored: ' . $stored );
				$totals['review']++;
				continue;
			}
			if ( ! $dry ) {
				if ( self::store( $id, $link, $host, self::FEED_SCORE, 'approved', $by_name ? 'plugivery_link_by_name' : 'plugivery_link', $stored !== '' ? 'replaced dead: ' . $stored : '' ) ) {
					self::adopt( $id, $link );
				}
			}
			$totals['adopted']++;
		}
		$dry || Module::info( 'URL finder: Plugivery manufacturer links', $totals );
		return $totals;
	}

	/** Hosts that are checkouts, promos or file servers, never a product page. */
	const NOT_PRODUCT_HOSTS = '/(fastspring|paddle|gumroad|checkout|^cdn\.|\.cdn\.|dropbox|drive\.google)/i';

	/**
	 * A link a distributor offers as "the manufacturer's page" that is a store
	 * promo (airmusictech.onfastspring.com/BlackFriday), a file on a CDN, or a
	 * listing ("/mpc-expansion-packs/") is not one: those wait for a person.
	 */
	public static function looks_like_product_page( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$segs = array_values( array_filter( explode( '/', preg_replace( '/\.(html?|php|aspx?)$/i', '', $path ) ), 'strlen' ) );
		if ( $host === '' || preg_match( self::NOT_PRODUCT_HOSTS, $host ) || ! $segs ) {
			return false;
		}
		if ( preg_match( '/\.(pdf|zip|dmg|exe|pkg|jpe?g|png)$/i', $path ) ) {
			return false;
		}
		return ! preg_match( self::LISTING_WORDS, (string) end( $segs ) );
	}

	/* ── Confirm held feed URLs by the page itself ─────────────────────── */

	/**
	 * Feed URLs held for review because nothing confirmed them (no URL before,
	 * an unverified one, a slug that doesn't name the product, a reseller host)
	 * are fetched through PageStore. A page whose own title (og:title, <title>,
	 * <h1>) holds every word of the product's name is the product's page:
	 * approved and adopted. A 404/410 is rejected. Disagreements with a
	 * working stored URL (`xchange_feed_differs`) are left alone: the stored
	 * URL works. CLI only; at most $max pages a run.
	 *
	 * @return array<string,int|bool>
	 */
	public static function confirm_held( int $max = 300, bool $dry = false ): array {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return [ 'error' => 'URL confirmation runs from WP-CLI only.' ];
		}
		global $wpdb;
		$t      = self::table();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.product_id, c.url, c.reason, c.note, p.post_title,
				   ( SELECT MIN( tm.name ) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id WHERE tr.object_id = c.product_id ) brand
				 FROM {$t} c JOIN {$wpdb->posts} p ON p.ID = c.product_id AND p.post_status = 'publish'
				 WHERE c.status = 'candidate' AND c.reason IN ('xchange_feed_unverified','xchange_feed_unconfirmed','xchange_feed_reseller')" . Exclusions::sql_not_excluded( 'c.product_id' ) . "
				 ORDER BY c.found_at LIMIT %d",
				$max
			),
			ARRAY_A
		);
		$totals = [ 'checked' => 0, 'confirmed' => 0, 'dead' => 0, 'no_match' => 0, 'not_fetched' => 0, 'dry_run' => $dry ];
		foreach ( $rows as $r ) {
			$load = function_exists( 'sys_getloadavg' ) ? (float) ( sys_getloadavg()[0] ?? 0 ) : 0.0;
			if ( $load > VendorFetcher::LOAD_LIMIT ) {
				sleep( 20 );
			}
			$totals['checked']++;
			$page = PageStore::get( (string) $r['url'] );
			$id   = (int) $r['product_id'];
			if ( in_array( $page['status'], [ 'http_404', 'http_410' ], true ) ) {
				$totals['dead']++;
				$dry || $wpdb->update( $t, [ 'status' => 'rejected', 'note' => mb_substr( 'feed URL dead (' . $page['status'] . ') ' . $r['note'], 0, 255 ) ], [ 'product_id' => $id ] );
				continue;
			}
			if ( $page['status'] !== 'ok' ) {
				$totals['not_fetched']++;
				continue;
			}
			if ( ! self::page_names_product( $page['title'], (string) $r['post_title'], (string) $r['brand'] ) ) {
				$totals['no_match']++;
				continue;
			}
			$totals['confirmed']++;
			if ( ! $dry ) {
				$wpdb->update( $t, [ 'status' => 'approved', 'score' => self::FEED_SCORE, 'reason' => 'xchange_feed_page', 'note' => mb_substr( 'page title: ' . $page['title'], 0, 255 ) ], [ 'product_id' => $id ] );
				self::adopt( $id, (string) $r['url'] );
			}
		}
		$dry || Module::info( 'URL finder: held feed URLs checked by page title', $totals );
		return $totals;
	}

	/**
	 * Every word of the product's name (brand, offer words and the bracketed
	 * qualifiers aside) appears in what the page calls itself.
	 */
	public static function page_names_product( string $page_title, string $product_title, string $brand ): bool {
		$name = preg_replace( '/\([^)]*\)/', ' ', html_entity_decode( $product_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$need = self::tokens( (string) $name, self::tokens( $brand ) );
		if ( ! $need || ! array_filter( $need, static fn( $w ) => ! ctype_digit( $w ) ) ) {
			return false;
		}
		$have = array_flip( self::tokens( $page_title ) );
		foreach ( $need as $w ) {
			if ( ! isset( $have[ $w ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** XChange SKU → vendor product URL from the feed's `info` field. @return array<string,string> */
	/** A feed URL as one page whatever its scheme, "www.", case or trailing slash ("/marketing", "/MARKETING/"). */
	private static function feed_key( string $url ): string {
		return strtolower( rtrim( (string) preg_replace( '#^https?://(www\.)?#i', '', $url ), '/' ) );
	}

	private static function xchange_info_urls(): array {
		$file = mmi_shared_lib_json_dir() . 'xchange-products.json';
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$out  = [];
		foreach ( (array) ( $data['products'] ?? [] ) as $r ) {
			$sku = trim( (string) ( $r['sku'] ?? '' ) );
			$url = self::clean_feed_url( (string) ( $r['info'] ?? '' ) );
			if ( $sku !== '' && $url !== '' ) {
				$out[ $sku ] = $url;
			}
		}
		return $out;
	}

	/** '' for a home page (names the vendor, not the product), a PDF, a non-product section or a non-vendor host. */
	private static function clean_feed_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$p    = wp_parse_url( $url );
		$host = strtolower( (string) ( $p['host'] ?? '' ) );
		$path = trim( (string) ( $p['path'] ?? '' ), '/' );
		if ( $host === '' || strpos( $host, '.' ) === false || in_array( $host, self::NOT_VENDOR_HOSTS, true ) ) {
			return '';
		}
		if ( $path === '' && empty( $p['query'] ) && empty( $p['fragment'] ) ) {
			return '';
		}
		if ( preg_match( '/\.pdf$/i', $path ) || preg_match( self::NOT_PRODUCT, '/' . $path ) ) {
			return '';
		}
		return esc_url_raw( $url );
	}

	/* ── Sitemaps ───────────────────────────────────────────────────────── */

	/**
	 * Every page URL in a host's sitemap(s), cached a week. With no sitemap at
	 * all, the links on the site's own start page ($base) stand in for one.
	 * @return string[]
	 */
	public static function sitemap_urls( string $host, string $base = '' ): array {
		$key    = 'mmpt_sitemap_' . md5( $host . $base );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$roots = [];
		$body  = self::get( 'https://' . $host . '/robots.txt', $host );
		if ( $body !== null && preg_match_all( '/^\s*sitemap:\s*(\S+)/im', $body, $m ) ) {
			foreach ( $m[1] as $loc ) {
				$roots[] = strpos( $loc, '/' ) === 0 ? 'https://' . $host . $loc : $loc; // "Sitemap: /sitemap-index.xml"
			}
		}
		// The usual locations back up robots.txt; each is only fetched while nothing has been found.
		$fallbacks = [];
		foreach ( array_unique( [ $base, '' ] ) as $prefix ) {
			foreach ( [ '/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml' ] as $file ) {
				$fallbacks[] = 'https://' . $host . $prefix . $file;
			}
		}
		$roots     = array_values( array_unique( array_merge( $roots, $fallbacks ) ) );
		$queue     = $roots;
		$pages = [];
		$seen  = 0;
		while ( $queue && $seen < self::MAX_SITEMAPS && count( $pages ) < self::MAX_URLS ) {
			$url = array_shift( $queue );
			// audit-ok: haystack is the <= 6 fallback sitemap URLs
			if ( $pages && in_array( $url, $fallbacks, true ) ) {
				continue;
			}
			$seen++;
			$xml = self::get( $url, $host );
			if ( $xml === null || ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $xml, $m ) ) {
				continue;
			}
			$is_index = stripos( $xml, '<sitemapindex' ) !== false;
			foreach ( $m[1] as $loc ) {
				$loc = html_entity_decode( trim( $loc ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
				if ( $is_index ) {
					$queue[] = $loc;
				} elseif ( self::same_site( $loc, $host ) ) {
					$pages[ $loc ] = true;
				}
			}
			// Child sitemaps that look like products first, blog/news/tag ones last.
			usort( $queue, static fn( $a, $b ) => self::sitemap_rank( $a ) <=> self::sitemap_rank( $b ) );
		}
		if ( ! $pages ) {
			// No sitemap: the vendor's own start page lists its products (XILS-lab's /store/).
			$html = self::get( 'https://' . $host . $base . '/', $host );
			if ( $html !== null && preg_match_all( '#href\s*=\s*["\']([^"\'\#?]+)#i', $html, $m ) ) {
				foreach ( $m[1] as $href ) {
					$href = html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
					$href = strpos( $href, '/' ) === 0 && strpos( $href, '//' ) !== 0 ? 'https://' . $host . $href : $href;
					if ( self::same_site( $href, $host ) && ! preg_match( '#/(wp-json|feed|wp-content|wp-includes)(/|$)#', $href ) ) {
						$pages[ $href ] = true;
					}
				}
			}
		}
		$pages = array_slice( array_keys( $pages ), 0, self::MAX_URLS );
		set_transient( $key, $pages, $pages ? self::CACHE_S : DAY_IN_SECONDS );
		return $pages;
	}

	/** Same site: the host itself or a subdomain of its domain (cms.sennheiser.com for www.sennheiser.com). */
	private static function same_site( string $url, string $host ): bool {
		$h    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$root = preg_replace( '/^www\./', '', $host );
		return $h !== '' && ( $h === $host || $h === $root || str_ends_with( $h, '.' . $root ) );
	}

	private static function sitemap_rank( string $url ): int {
		// Multi-locale indexes (Sennheiser has dozens): English/US first, other locales last.
		if ( preg_match( '/[-_\/]en[-_]us[-_\/.]/i', $url ) ) {
			return -1;
		}
		if ( preg_match( '/[-_\/][a-z]{2}[-_][a-z]{2}[-_\/.]/i', $url ) ) {
			return 3;
		}
		if ( preg_match( '/product|plugin|software|instrument|shop|store|item/i', $url ) ) {
			return 0;
		}
		if ( preg_match( '/post|blog|news|tag|author|categor|video|event|press|attachment|image/i', $url ) ) {
			return 2;
		}
		return 1;
	}

	/** One throttled, robots-checked GET on the vendor host. null on any failure. */
	private static function get( string $url, string $host ): ?string {
		if ( ! self::same_site( $url, $host ) ) {
			return null; // Vendor site only, even when a sitemap points elsewhere.
		}
		if ( ! str_ends_with( (string) wp_parse_url( $url, PHP_URL_PATH ), 'robots.txt' ) && ! VendorFetcher::robots_allows( $url, $host ) ) {
			return null;
		}
		$key = VendorFetcher::throttle_key( $host );
		for ( $attempt = 1; ; $attempt++ ) {
			\MMI_API_Throttler::throttle( $key, [ 'min_gap_ms' => VendorFetcher::HOST_GAP_MS ] );
			$res = wp_safe_remote_get( $url, [ 'timeout' => 30, 'redirection' => 3, 'user-agent' => FetchPolicy::bot_ua(), 'limit_response_size' => self::MAX_BYTES ] );
			if ( is_wp_error( $res ) ) {
				return null;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code !== 429 && $code !== 503 ) {
				break;
			}
			$wait = max( 20, min( 120, (int) wp_remote_retrieve_header( $res, 'retry-after' ) ) );
			\MMI_API_Throttler::penalize( $key, $wait );
			if ( $attempt >= 2 ) {
				return null;
			}
			sleep( $wait ); // CLI only: one patient retry beats losing a whole vendor for a week.
		}
		if ( $code !== 200 ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( strncmp( $body, "\x1f\x8b", 2 ) === 0 ) {
			$body = (string) @gzdecode( $body );
		}
		return $body;
	}

	/* ── Matching ───────────────────────────────────────────────────────── */

	/** @return array<string,string[]> url => slug tokens, product-looking pages only. */
	/**
	 * The maker's own code for a product, from its SKU: a distributor puts the maker's letters first and may add a platform
	 * last. MF-GEOSHRED-NAADA-SITAR is geoshred-naada-sitar on moforte.com, MF-NAADA-REEDS-WN is naada-reeds. Two words at
	 * least, since one ("AA-30H") could be anything. Names can't tell "Collection III & IV" from "Collection I, II, III & IV"
	 * or "GeoViolin" from "geoshred-violin"; a code can. '' when the SKU carries none.
	 */
	public static function code_of( int $id ): string {
		$parts = explode( '-', strtolower( trim( (string) get_post_meta( $id, '_sku', true ) ) ) );
		if ( count( $parts ) < 3 || ! preg_match( '/^[a-z]{2,4}$/', $parts[0] ) ) {
			return '';
		}
		array_shift( $parts );
		if ( in_array( end( $parts ), [ 'wn', 'win', 'mac', 'pc', 'osx' ], true ) ) {
			array_pop( $parts );
		}
		$code = implode( '-', $parts );
		return count( $parts ) >= 2 && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)+$/', $code ) ? $code : '';
	}

	/** The address that is code $code, alone or with a platform after it ("naada-all-instruments-windows"), from slugs(). '' when none is. @param array<string,string> $slugs */
	public static function code_url( string $code, array $slugs ): string {
		foreach ( $code !== '' ? [ '', '-windows', '-win', '-mac', '-macos' ] : [] as $tail ) {
			if ( isset( $slugs[ $code . $tail ] ) ) {
				return $slugs[ $code . $tail ];
			}
		}
		return '';
	}

	/** A site's addresses by their last part, lowercase, for finding a code. @param string[] $urls @return array<string,string> */
	public static function slugs( array $urls ): array {
		$out = [];
		foreach ( $urls as $u ) {
			$slug = strtolower( basename( (string) preg_replace( '/\.(?:html?|php|aspx?)$/i', '', rtrim( (string) wp_parse_url( (string) $u, PHP_URL_PATH ), '/' ) ) ) );
			if ( $slug !== '' && ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = (string) $u;
			}
		}
		return $out;
	}

	public static function index_urls( array $urls ): array {
		$out = [];
		foreach ( $urls as $u ) {
			$path = (string) wp_parse_url( $u, PHP_URL_PATH );
			if ( $path === '' || $path === '/' || preg_match( self::NOT_PRODUCT, $path ) ) {
				continue;
			}
			// Every path segment counts: vendors nest products ("/beatmaker/eden/"). Locale segments don't.
			$segs = array_filter( explode( '/', preg_replace( '/\.(html?|php|aspx?)$/i', '', $path ) ), static fn( $x ) => $x !== '' && ! preg_match( '/^[a-z]{2}([_-][a-z]{2})?$/i', $x ) );
			$toks = self::tokens( implode( ' ', $segs ) );
			if ( $toks ) {
				$out[ $u ] = $toks;
			}
		}
		return $out;
	}

	/** @return string[] distinct matching tokens: lowercase words and numbers, noise dropped. */
	public static function tokens( string $s, array $drop = [] ): array {
		$s    = strtolower( remove_accents( $s ) );
		$s    = preg_replace( '/([a-z])(\d)|(\d)([a-z])/', '$1$3 $2$4', $s ); // "rx11" → "rx 11"
		$toks = preg_split( '/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY );
		$noise = array_flip( array_merge( self::NOISE, $drop ) );
		return array_values( array_unique( array_filter( $toks, static fn( $t ) => ! isset( $noise[ $t ] ) ) ) );
	}

	/**
	 * Best sitemap URL for a product title. Score = token overlap (Dice), with
	 * every slug word required to appear in the title — a slug naming a
	 * different product ("/chromaphone-3" for an Exodus pack) can't win on a
	 * shared word. @return array{url:string,score:int}|null
	 */
	public static function best_match( string $title, string $brand, array $index ): ?array {
		$title  = preg_replace( '/\([^)]*\)/', ' ', html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) );
		// An add-on's title names its host ("GeoOboe for GeoShred Studio"); matching on the host's
		// words sends every add-on to the host's page. Only the add-on's own name counts.
		// A platform named there stays allowed in the address ("Naada Reeds for Windows" is /naada-reeds-windows).
		$host_w = preg_match( '/\s(?:for|für)\s(.*)$/iu', (string) $title, $tail ) ? array_values( array_intersect( self::tokens( $tail[1] ), [ 'windows', 'win', 'mac', 'macos' ] ) ) : [];
		$title  = preg_replace( '/\s(for|für)\s.*$/iu', '', $title );
		$brandt = self::tokens( $brand );
		$t      = self::tokens( $title, $brandt );
		if ( ! $t || ! array_filter( $t, static fn( $w ) => ! ctype_digit( $w ) ) ) {
			return null;
		}
		$tset = array_flip( $t );
		$best = null;
		foreach ( $index as $url => $u ) {
			$u = array_values( array_diff( $u, $brandt, $host_w, [ 'en', 'us', 'uk', 'de', 'fr', 'products', 'product', 'shop', 'store', 'p', 'item' ] ) );
			if ( ! $u ) {
				continue;
			}
			$common = 0;
			foreach ( $u as $w ) {
				if ( isset( $tset[ $w ] ) ) {
					$common++;
				}
			}
			if ( $common < count( $u ) || ! array_filter( $u, static fn( $w ) => ! ctype_digit( $w ) ) ) {
				continue; // every slug word must be in the title, and at least one must be a real word
			}
			$score = (int) round( 200 * $common / ( count( $u ) + count( $t ) ) );
			if ( ! $best || $score > $best['score'] || ( $score === $best['score'] && strlen( $url ) < strlen( $best['url'] ) ) ) {
				$best = [ 'url' => $url, 'score' => $score ];
			}
		}
		return $best;
	}
}
