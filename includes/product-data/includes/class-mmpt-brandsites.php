<?php
/**
 * Brands and their sites (owner 2026-10-11: "the admin should be able to edit the brand URL at any
 * point in this process on any page and see the repercussions … and see the URL complexity picture at
 * a glance"). A brand's products have pages on several sites, and one site can serve several brands,
 * so a site has a role per brand:
 *
 *   official  the maker's own site (several allowed: babyaudio.com and babyaud.io)
 *   partner   a distributor or sister company the owner accepts (a sister company's site for one of its brands)
 *   reseller  a shop selling many brands (bestservice.com); its pages stay, flagged
 *   store     a checkout or file host (FastSpring, a CDN): never a product page
 *   notbrand  not this brand's at all: never proposed for it again
 *
 * No row = not decided yet (the list suggests a role from the domain; a person confirms it). The
 * Brand website field (shared MMI_Brand_Website) stays where it is and counts as official.
 *
 * What roles change: the finder looks on the Brand website first, then official and partner sites,
 * then the site most pages are on (UrlFinder::search_sites()); products whose page is on a reseller,
 * store or not-this-brand site are searched for again and a better page is proposed for a person to
 * decide (UrlFinder::gaps()); a not-this-brand site is never proposed (UrlFinder::store(),
 * VendorCatalog::record()). Nothing is removed: current URLs stay until a person approves another.
 *
 * A brand with no maker site at all (the maker is gone, or never had a website; owner 2026-10-17, Event)
 * is set aside: is_gone(). Its products count as not published by the maker, the finder skips them, and
 * the brand leaves the tiles and the "Needs a person" chips. Titles and the rest of Product Content still
 * work them (that is Exclusions, a different decision). Adding a maker site lifts it.
 *
 * Shown on Product Content → Brands & sites, and in a drawer every screen opens (brand-sites.js).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BrandSites {

	/** Term meta: the brand's last "Find product pages" run (was self::FIND_META). */
	const FIND_META    = '_mmpt_find_pages';
	/** A run not finished after this long is "stopped". */
	const FIND_STALE_S = 1800;


	const DB_OPTION  = 'mmpt_brand_sites_db_version';
	const DB_VERSION = '1';
	const CACHE      = 'mmpt_brand_landscape_v9'; // v2: rows carry failed_open and moves; v3: sites carry `better`; v4: sites carry `why`; v5: rows carry `gone`; v6: rows carry `bundles`, `bundles_none`; v7: sites carry `bundles`; v8: sites carry `open`; v9: rows carry `logo`
	const CACHE_S    = 300;
	const DIRTY      = 'mmpt_brand_landscape_dirty'; // products and brands changed since the cache was saved
	const DIRTY_MAX  = 400;
	const PER_PAGE   = 50; // drawer product lists
	const READ_STEP_S = 15.0;
	const CARD_PAGES = 25; // stored pages of a site looked through for products' cards (drawer's page list) // a store's list is read in steps this long, each a request well inside nginx's 60 s
	const ROLES      = [ 'official', 'partner', 'reseller', 'store', 'notbrand' ];
	const BAD        = [ 'reseller', 'store', 'notbrand' ]; // pages there are searched for again
	const LINKS      = 'mmpt_brand_site_links'; // Brand website host → the other sites its home page links to
	const LINKS_DAYS = 7;
	const GONE       = '_mmpt_no_maker_site'; // term meta: { at, by } when a person said the brand has no maker site

	/** Shops that sell many brands, for the suggestion only (UrlFinder::RESELLER_HOSTS and more). */
	const RESELLERS = [ 'bestservice.com', 'bestservice.de', 'ilio.com', 'pluginfox.com', 'pluginboutique.com', 'kvraudio.com', 'sweetwater.com', 'thempcstore.com', 'plugivery.com', 'audiodeluxe.com', 'vstbuzz.com', 'adsrsounds.com', 'jrrshop.com', 'audioplugin.deals' ];

	/** @var array<int,array<string,string>>|null term id → host → role */
	private static $roles = null;

	/** @var array<string,array>|null Brand website host → its links as last read (links()) */
	private static $links = null;

	/** @var array{p:int[],t:int[]}|null changes noted in this request (dirty()) */
	private static $dirty = null;

	/** @var array<int,bool>|null brands set aside as having no maker site (gone_ids()) */
	private static $gone = null;

	/** @var array<int,bool>|null products that are bundles or collections (Bundles), id → true */
	private static $bundles = null;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_brand_sites';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				term_id bigint(20) unsigned NOT NULL,
				host varchar(190) NOT NULL,
				role varchar(20) NOT NULL,
				set_by bigint(20) unsigned NOT NULL DEFAULT 0,
				set_at datetime NOT NULL,
				PRIMARY KEY  (term_id, host),
				KEY host (host)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		// A product's vendor URL changed (set, approved, cleared, adopted by the finder): the picture is stale.
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'url_changed' ], 10, 3 );
		}
		// A brand renamed, added or deleted, or a product moved between brands: those rows are stale.
		foreach ( [ 'created_product_brand', 'edited_product_brand', 'delete_product_brand' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'term_changed' ] );
		}
		add_action( 'set_object_terms', [ __CLASS__, 'brands_set' ], 10, 6 );
	}

	/** A brand's name or slug changed, or the brand was added or deleted. */
	public static function term_changed( $term_id ): void {
		self::dirty( [], [ (int) $term_id ] );
	}

	/** A product's brands were set: the brands it left and the brands it joined are rebuilt. */
	public static function brands_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( $taxonomy !== 'product_brand' || array_map( 'intval', (array) $tt_ids ) == array_map( 'intval', (array) $old_tt_ids ) ) {
			return;
		}
		$old = $old_tt_ids ? get_terms( [ 'taxonomy' => 'product_brand', 'term_taxonomy_id' => array_map( 'intval', $old_tt_ids ), 'hide_empty' => false, 'fields' => 'ids' ] ) : [];
		self::dirty( [ (int) $object_id ], is_array( $old ) ? array_map( 'intval', $old ) : [] );
	}

	/** A vendor URL or its verified flag changed: its brands are rebuilt on the next landscape read. */
	public static function url_changed( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, [ 'vendor_product_url', 'vendor_product_url_verified' ], true ) ) {
			self::dirty( [ (int) $object_id ], [] );
		}
	}

	/** A URL or host → its host, lowercase, without "www." or a path. */
	public static function host( string $url_or_host ): string {
		$s = strtolower( trim( $url_or_host ) );
		$h = str_contains( $s, '://' ) ? (string) wp_parse_url( $s, PHP_URL_HOST ) : explode( '/', $s )[0];
		return preg_replace( '/^www\./', '', $h );
	}

	/** example.co.uk → example.co.uk, shop.example.com → example.com */
	private static function domain( string $host ): string {
		$p = explode( '.', $host );
		$n = count( $p );
		return $n >= 3 && in_array( $p[ $n - 2 ], [ 'co', 'com', 'org', 'net' ], true ) && strlen( $p[ $n - 1 ] ) === 2 ? implode( '.', array_slice( $p, -3 ) ) : implode( '.', array_slice( $p, -2 ) );
	}

	/** Saved roles, term id → host → role. @return array<int,array<string,string>> */
	public static function roles(): array {
		if ( self::$roles === null ) {
			self::maybe_install();
			global $wpdb;
			self::$roles = [];
			foreach ( $wpdb->get_results( 'SELECT term_id, host, role FROM ' . self::table(), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				self::$roles[ (int) $r['term_id'] ][ $r['host'] ] = $r['role'];
			}
		}
		return self::$roles;
	}

	/** Brands a person set aside as having no maker site, term id → true. One query per request. @return array<int,bool> */
	public static function gone_ids(): array {
		if ( self::$gone === null ) {
			global $wpdb;
			self::$gone = array_fill_keys( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s", self::GONE ) ) ), true );
		}
		return self::$gone;
	}

	public static function is_gone( int $term_id ): bool {
		return isset( self::gone_ids()[ $term_id ] );
	}

	/** Products that are bundles or collections (the Bundles tab's list), id → true. @return array<int,bool> */
	private static function bundle_ids(): array {
		if ( self::$bundles === null ) {
			global $wpdb;
			\MMI_Pipeline_Bundles::install();
			self::$bundles = array_fill_keys( array_map( 'intval', $wpdb->get_col( 'SELECT bundle_id FROM ' . \MMI_Pipeline_Bundles::table() ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return self::$bundles;
	}

	/** Does the brand have a maker site: a Brand website, or a site saved as official or partner? */
	private static function has_maker_site( int $term_id ): bool {
		return self::website_host( $term_id ) !== '' || (bool) array_intersect( self::roles()[ $term_id ] ?? [], [ 'official', 'partner' ] );
	}

	/** The brand's Brand website, as a host ('' when unset). */
	public static function website_host( int $term_id ): string {
		return class_exists( 'MMI_Brand_Website' ) ? self::host( \MMI_Brand_Website::get( $term_id ) ) : '';
	}

	/** A guess at a site's role for a brand, from the domain, for a person to confirm. */
	/**
	 * Has a person said this site is one of the product's brand's own (saved official or partner, or its
	 * Brand website)? Bulk approve takes a page that moved there only then (UrlQueue::decide()).
	 */
	public static function confirmed_for( int $product_id, string $host ): bool {
		$host  = self::host( $host );
		$terms = wp_get_post_terms( $product_id, 'product_brand', [ 'fields' => 'ids' ] );
		foreach ( is_array( $terms ) ? $terms : [] as $t ) {
			if ( self::own_host( (int) $t, self::website_host( (int) $t ), $host ) ) {
				return true;
			}
		}
		return false;
	}

	public static function suggest( string $brand, string $website, string $host ): string {
		return self::guess( $brand, $website, $host )[0];
	}

	/**
	 * The suggested role and why, for the drawer to say ("akaipro.com's home page links to it"):
	 * website, domain (same site as the Brand website), linked (the Brand website's home page links to it,
	 * read_links()), name (its name is the brand's), reseller (a shop selling many brands), store (a file
	 * or checkout host), or '' when nothing says.
	 * @return array{0:string,1:string}
	 */
	private static function guess( string $brand, string $website, string $host ): array {
		if ( $website !== '' && $host === $website ) {
			return [ 'official', 'website' ];
		}
		if ( preg_match( '/(^|\.)(cdn|files|downloads?)\.|fastspring|gumroad/', $host ) ) {
			return [ 'store', 'store' ];
		}
		if ( $website !== '' && self::domain( $host ) === self::domain( $website ) ) {
			return [ 'official', 'domain' ];
		}
		if ( $website !== '' && in_array( self::domain( $host ), self::links( $website ), true ) ) {
			return [ 'official', 'linked' ]; // the maker sends its own visitors there (AKAI → thempcstore.com, 2026-10-15)
		}
		$key = static fn( string $s ) => preg_replace( '/[^a-z0-9]/', '', strtolower( $s ) );
		$hk  = $key( explode( '.', self::domain( $host ) )[0] );
		$bk  = $key( html_entity_decode( $brand, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( $hk === $bk || ( strlen( $bk ) >= 5 && str_starts_with( $hk, substr( $bk, 0, 5 ) ) ) || ( strlen( $hk ) >= 5 && str_starts_with( $bk, substr( $hk, 0, 5 ) ) ) ) {
			return [ 'official', 'name' ];
		}
		return in_array( self::domain( $host ), self::RESELLERS, true ) ? [ 'reseller', 'reseller' ] : [ 'unknown', '' ];
	}

	/** The sites (registrable domains) the Brand website's home page links to, as last read (read_links()). @return string[] */
	private static function links( string $website ): array {
		self::$links = self::$links ?? Module::state( self::LINKS );
		return (array) ( self::$links[ $website ]['hosts'] ?? [] );
	}

	/**
	 * Read the Brand website's home page once a week for the other sites it links to: a maker's own store on
	 * another domain is linked from its home page (akaipro.com → thempcstore.com). One GET, on opening the
	 * drawer, never during a landscape build. A failed read is kept too, so it isn't retried on every open.
	 */
	private static function read_links( string $website ): bool {
		$seen = Module::state( self::LINKS )[ $website ] ?? null;
		if ( $website === '' || ( $seen && strtotime( (string) $seen['at'] ) > time() - self::LINKS_DAYS * DAY_IN_SECONDS ) ) {
			return false;
		}
		$html  = VendorCatalog::text( 'https://' . $website . '/', $website, 10 );
		$hosts = [];
		if ( $html !== null && preg_match_all( '#href=["\']https?://([a-z0-9.-]+)#i', $html, $m ) ) {
			foreach ( $m[1] as $h ) {
				$d = self::domain( self::host( $h ) );
				if ( $d !== '' && $d !== self::domain( $website ) ) {
					$hosts[ $d ] = true;
				}
			}
		}
		self::$links = Module::set_state( self::LINKS, [ $website => [ 'at' => current_time( 'mysql' ), 'hosts' => array_slice( array_keys( $hosts ), 0, 200 ), 'ok' => $html !== null ] ] );
		return true;
	}

	/** Does any of the product's brands say this site is not theirs? (Then it is never proposed for it.) */
	public static function blocked( int $product_id, string $url ): bool {
		$host  = self::host( $url );
		$roles = self::roles();
		if ( $host === '' || ! $roles ) {
			return false;
		}
		foreach ( (array) wp_get_post_terms( $product_id, 'product_brand', [ 'fields' => 'ids' ] ) as $t ) {
			if ( ( $roles[ (int) $t ][ $host ] ?? '' ) === 'notbrand' ) {
				return true;
			}
		}
		return false;
	}

	/** Is the URL on a site one of the product's brands saved as a reseller, a store or not its own? */
	public static function on_bad_site( int $product_id, string $url ): bool {
		$host  = self::host( $url );
		$roles = self::roles();
		if ( $host === '' || ! $roles ) {
			return false;
		}
		$terms = wp_get_post_terms( $product_id, 'product_brand', [ 'fields' => 'ids' ] );
		foreach ( is_array( $terms ) ? $terms : [] as $t ) {
			if ( in_array( $roles[ (int) $t ][ $host ] ?? '', self::BAD, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Has a person said $host is the brand's own (saved official or partner, or its Brand website)? */
	private static function own_host( int $term_id, string $website, string $host ): bool {
		return $host !== '' && ( $host === $website || in_array( self::roles()[ $term_id ][ $host ] ?? '', [ 'official', 'partner' ], true ) );
	}

	/** term id → hosts with a reseller / store / not-this-brand role. @return array<int,string[]> */
	public static function bad_hosts(): array {
		$out = [];
		foreach ( self::roles() as $t => $hosts ) {
			foreach ( $hosts as $h => $role ) {
				if ( in_array( $role, self::BAD, true ) ) {
					$out[ $t ][] = $h;
				}
			}
		}
		return $out;
	}

	/**
	 * Every brand: its products, decisions waiting, products without a URL, and each site its products'
	 * pages are on with counts, what reading got, the site's vendor map, the role (saved or suggested)
	 * and how many brands share the site. Cached 5 minutes.
	 *
	 * Building every brand takes ~1.5 s, so a change patches the cache instead of dropping it: a vendor
	 * URL change marks its product's brands (url_changed()), a save marks its brand, and the next read
	 * rebuilds just those brands (refresh()). How many brands share a site comes from the cached
	 * site → brands index at read time, so patching one brand keeps every other brand's count right.
	 * Patching re-saves the cache but keeps its build time, so a full rebuild still happens every
	 * 5 minutes and catches any change no hook reported.
	 * @return array{brands:array<int,array>,shared:array<string,int[]>}
	 */
	public static function landscape( bool $fresh = false ): array {
		$c = $fresh ? null : self::cached();
		if ( $c === null ) {
			$c = self::build( null ) + [ 'at' => time() ];
			self::$dirty = [ 'p' => [], 't' => [] ];
			delete_transient( self::DIRTY );
			set_transient( self::CACHE, $c, self::CACHE_S );
		} else {
			$c = self::apply_dirty( $c );
		}
		return self::publish( $c );
	}

	/** One brand's landscape row, rebuilt now (its products' reads may have changed since it was cached). */
	public static function brand_row( int $term_id ): array {
		$c = self::cached();
		if ( $c === null ) {
			$out = self::landscape( true );
		} else {
			$out = self::publish( self::patch( self::apply_dirty( $c ), [ $term_id ] ) );
		}
		foreach ( $out['brands'] as $b ) {
			if ( $b['id'] === $term_id ) {
				return $b;
			}
		}
		return [];
	}

	/**
	 * One product's place in its brand's picture: no page (a person said so), no URL, or a site with what
	 * reading it got. `open`: a proposed URL waits for a decision (counted whatever else is true).
	 * @return array{kind:string,open:bool,host?:string,state?:string,unverified?:bool}
	 */
	private static function bucket( array $x, array $flags ): array {
		$open = (string) $x['cs'] === 'candidate';
		if ( (string) $x['fs'] === UrlFinder::NO_PAGE ) {
			return [ 'kind' => 'no_page', 'open' => $open ];
		}
		$url = (string) $x['url'];
		if ( ! str_starts_with( $url, 'http' ) ) {
			return [ 'kind' => 'no_url', 'open' => $open ];
		}
		$v   = $flags[ (int) $x['id'] ] ?? '';
		$res = PageReview::result_of( (string) $x['fs'], (string) $x['read_url'], $url, $v );
		return [
			'kind'       => 'site',
			'open'       => $open,
			'host'       => self::host( $url ),
			'state'      => in_array( $res, [ 'read', 'manual', 'no_copy' ], true ) ? 'read' : ( in_array( $res, [ 'not_read', 'changed' ], true ) ? 'unread' : 'failed' ),
			'unverified' => ! in_array( $v, [ 'TRUE', '1' ], true ),
			// Its page now redirects to another site and that address is proposed: the site it moved to.
			'to'         => $open && (string) $x['fs'] === 'moved_offsite' && (string) $x['cr'] === 'moved_offsite' ? self::host( (string) $x['ch'] ) : '',
		];
	}

	/**
	 * Is a product (its bucket()) in a drawer list? site:<host>, failed, nourl, nopage, open, all, and the
	 * three the drawer works from (owner 2026-10-15, product data only from the maker's own pages): work
	 * (no page, a broken one, a page on a site that isn't the maker's, or a proposed page waiting for a decision:
	 * owner 2026-10-22, the drawer said "Need work 0" while Vendor pages had ujam proposals), has (a page on the maker's site)
	 * and nopub (the maker doesn't publish it). $off: the brand's sites that aren't the maker's.
	 */
	private static function in_group( array $k, string $group, array $off = [] ): bool {
		if ( str_starts_with( $group, 'site:' ) ) {
			return $k['kind'] === 'site' && $k['host'] === substr( $group, 5 );
		}
		$away = $k['kind'] === 'site' && isset( $off[ $k['host'] ] );
		switch ( $group ) {
			case 'work':
				return $k['kind'] === 'no_url' || $away || ( $k['kind'] === 'site' && $k['state'] === 'failed' ) || ( $k['open'] && $k['kind'] !== 'no_page' );
			case 'has':
				return $k['kind'] === 'site' && ! $away && $k['state'] !== 'failed';
			case 'nopub':
				return $k['kind'] === 'no_page';
			case 'bundles':
				return ! empty( $k['bundle'] );
			case 'bundles_none': // a bundle with no maker page: none, or one on a site that isn't the maker's (bundles_none())
				return ! empty( $k['bundle'] ) && ( $k['kind'] === 'no_url' || $away );
			case 'failed':
				return $k['kind'] === 'site' && $k['state'] === 'failed';
			case 'nourl':
				return $k['kind'] === 'no_url';
			case 'nopage':
				return $k['kind'] === 'no_page';
			case 'open':
				return $k['open'];
			default:
				return $group === 'all';
		}
	}

	/** Every list a product is in (a row leaves a list once it isn't). @return string[] */
	private static function groups_of( array $k, array $off = [] ): array {
		return array_values( array_filter( [ 'all', $k['kind'] === 'site' ? 'site:' . $k['host'] : '', 'failed', 'nourl', 'nopage', 'open', 'work', 'has', 'nopub', 'bundles' ], static fn( $g ) => $g !== '' && self::in_group( $k, $g, $off ) ) );
	}

	/** The brand's sites that aren't the maker's (saved or suggested reseller, store or not-this-brand), host → true. */
	private static function off_hosts( array $b ): array {
		$off = [];
		foreach ( $b['sites'] ?? [] as $s ) {
			if ( in_array( $s['role'], self::BAD, true ) ) {
				$off[ $s['host'] ] = true;
			}
		}
		return $off;
	}

	/** These brands' products (null: every brand's), each with its bucket() as `k`. Left-out products are skipped. */
	private static function scan( ?array $terms ): array {
		global $wpdb;
		$in   = $terms === null ? '' : ' AND tt.term_id IN (' . implode( ',', array_map( 'intval', $terms ?: [ 0 ] ) ) . ')';
		$rows = $wpdb->get_results(
			"SELECT tt.term_id t, p.ID id, p.post_title title, u.meta_value url, d.fetch_status fs, d.vendor_url read_url, COALESCE( c.status, '' ) cs, COALESCE( c.host, '' ) ch, COALESCE( c.reason, '' ) cr
			 FROM {$wpdb->term_taxonomy} tt
			 JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
			 LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
			 LEFT JOIN " . Descriptions::table() . ' d ON d.product_id = p.ID
			 LEFT JOIN ' . UrlFinder::table() . " c ON c.product_id = p.ID
			 WHERE tt.taxonomy = 'product_brand'{$in}", // phpcs:ignore WordPress.DB.PreparedSQL -- ids are ints
			ARRAY_A
		);
		$flags = [];
		$ids   = array_map( static fn( $x ) => (int) $x['id'], $rows );
		if ( $terms === null || $ids ) {
			$only = $terms === null ? '' : ' AND post_id IN (' . implode( ',', $ids ) . ')';
			foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'vendor_product_url_verified'{$only}", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ids are ints
				$flags[ (int) $x['post_id'] ] = strtoupper( (string) $x['meta_value'] );
			}
		}
		$skip = array_flip( Exclusions::ids() );
		$gone    = self::gone_ids();
		$bundles = self::bundle_ids();
		$out     = [];
		foreach ( $rows as $x ) {
			if ( ! isset( $skip[ (int) $x['id'] ] ) ) {
				// No maker site at all: there is no maker page to have, so none is looked for. Its URL stays.
				$x['k'] = isset( $gone[ (int) $x['t'] ] ) ? [ 'kind' => 'no_page', 'open' => (string) $x['cs'] === 'candidate', 'gone' => true ] : self::bucket( $x, $flags );
				if ( isset( $bundles[ (int) $x['id'] ] ) ) {
					$x['k']['bundle'] = true;
				}
				$out[] = $x;
			}
		}
		return $out;
	}

	/** Every site with a vendor map → its status. @return array<string,string> */
	private static function maps(): array {
		static $maps = null;
		if ( $maps === null ) {
			global $wpdb;
			$maps = [];
			VendorMaps::maybe_install();
			foreach ( $wpdb->get_col( 'SELECT host FROM ' . VendorMaps::table() ) as $h ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$m = VendorMaps::get( (string) $h );
				if ( $m ) {
					$maps[ (string) $h ] = VendorMaps::status_of( $m );
				}
			}
		}
		return $maps;
	}

	/**
	 * These brands' rows (null: every brand), and which brands have pages on each site.
	 * @return array{brands:array<int,array>,hosts:array<string,array<int,bool>>}
	 */
	/**
	 * A brand logo's small size, from the attachment's stored sizes. Not wp_get_attachment_image_url(): its filters
	 * include a Code Snippets snippet ("Woo Product Fallback Image") that runs a term-meta query for every
	 * attachment, one per brand here.
	 */
	private static function logo( int $att ): string {
		$meta = $att ? wp_get_attachment_metadata( $att ) : false;
		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return $att ? (string) ( wp_get_attachment_url( $att ) ?: '' ) : ''; // no stored sizes (a few SVGs): the file itself
		}
		$file = (string) ( $meta['sizes']['thumbnail']['file'] ?? '' );
		$dir  = dirname( (string) $meta['file'] );
		return trailingslashit( wp_upload_dir( null, false )['baseurl'] ) . ( $file !== '' ? ( $dir !== '.' ? $dir . '/' : '' ) . $file : (string) $meta['file'] );
	}

	private static function build( ?array $terms ): array {
		$args = [ 'taxonomy' => 'product_brand', 'hide_empty' => false ];
		if ( $terms !== null ) {
			$args['include'] = $terms ?: [ 0 ];
		}
		$list   = get_terms( $args );
		$brands = [];
		// The brands' logos (WooCommerce's brand thumbnail), their attachments read in one query, not one per brand.
		_prime_post_caches( array_values( array_filter( array_map( static fn( $t ) => (int) get_term_meta( (int) $t->term_id, 'thumbnail_id', true ), is_array( $list ) ? $list : [] ) ) ), false, true );
		foreach ( is_array( $list ) ? $list : [] as $t ) {
			$id            = (int) $t->term_id;
			$brands[ $id ] = [ 'id' => $id, 'name' => html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'website' => self::website_host( $id ), 'url' => class_exists( 'MMI_Brand_Website' ) ? \MMI_Brand_Website::get( $id ) : '', 'logo' => self::logo( (int) get_term_meta( $id, 'thumbnail_id', true ) ), 'products' => 0, 'open' => 0, 'failed_open' => 0, 'no_url' => 0, 'no_page' => 0, 'moves' => [], 'sites' => [], 'gone' => self::is_gone( $id ), 'bundles' => 0, 'bundles_none' => 0, 'bundle_hosts' => [] ];
		}
		$hosts = [];
		$empty = [ 'n' => 0, 'read' => 0, 'unread' => 0, 'failed' => 0, 'unverified' => 0, 'better' => 0, 'bundles' => 0, 'open' => 0 ];
		foreach ( self::scan( $terms ) as $x ) {
			$t = (int) $x['t'];
			if ( ! isset( $brands[ $t ] ) ) {
				continue;
			}
			$k = $x['k'];
			$b = &$brands[ $t ];
			$b['products']++;
			if ( ! empty( $k['bundle'] ) ) {
				$b['bundles']++;
				if ( $k['kind'] === 'no_url' ) {
					$b['bundles_none']++;
				} elseif ( $k['kind'] === 'site' ) {
					$b['bundle_hosts'][ $k['host'] ] = ( $b['bundle_hosts'][ $k['host'] ] ?? 0 ) + 1; // on a site that isn't the maker's: counted once roles are known
				}
			}
			if ( $k['open'] ) {
				$b['open']++;
			}
			if ( $k['kind'] === 'no_page' ) {
				$b['no_page']++; // a person said it has none
			} elseif ( $k['kind'] === 'no_url' ) {
				$b['no_url']++;
			} else {
				$h = $k['host'];
				$s = $b['sites'][ $h ] ?? [ 'host' => $h ] + $empty;
				$s['n']++;
				$s[ $k['state'] ]++;
				if ( $k['state'] === 'failed' && $k['open'] ) {
					$b['failed_open']++; // can't be read, but a fix waits under "to decide"
				} elseif ( $k['open'] ) {
					$s['open']++; // its page reads, and a proposed page waits for a decision: drawer's Need work
				}
				if ( $k['to'] !== '' ) {
					$b['moves'][ $h . ' ' . $k['to'] ] = ( $b['moves'][ $h . ' ' . $k['to'] ] ?? 0 ) + 1;
				}
				if ( $k['unverified'] ) {
					$s['unverified']++;
				}
				if ( ! empty( $k['bundle'] ) ) {
					$s['bundles']++; // its Delete can mark these as having no maker page (remove())
				}
				if ( $k['open'] && self::own_host( $t, $b['website'], self::host( (string) $x['ch'] ) ) ) {
					$s['better']++; // a page on the brand's own site waits for a decision (to_own())
				}
				$b['sites'][ $h ]  = $s;
				$hosts[ $h ][ $t ] = true;
			}
			unset( $b );
		}
		$roles = self::roles();
		$maps  = self::maps();
		foreach ( $brands as $id => $b ) {
			// The Brand website and saved sites are listed even with no pages on them yet.
			foreach ( array_merge( $b['website'] !== '' ? [ $b['website'] ] : [], array_keys( $roles[ $id ] ?? [] ) ) as $h ) {
				$b['sites'][ $h ] = $b['sites'][ $h ] ?? [ 'host' => $h ] + $empty;
			}
			foreach ( $b['sites'] as $h => $s ) {
				$saved                     = $roles[ $id ][ $h ] ?? '';
				[ $role, $why ]            = self::guess( $b['name'], $b['website'], $h );
				$b['sites'][ $h ]['role']  = $saved !== '' ? $saved : $role;
				$b['sites'][ $h ]['why']   = $saved !== '' ? '' : $why;
				$b['sites'][ $h ]['saved'] = $saved !== '';
				$b['sites'][ $h ]['map']   = $maps[ $h ] ?? '';
			}
			// A site whose pages moved to another site, two or more of them: one decision moves them all (move()).
			$moves = [];
			foreach ( $b['moves'] as $pair => $n ) {
				[ $from, $to ] = explode( ' ', $pair );
				if ( $n > 1 ) {
					$moves[] = [ 'from' => $from, 'to' => $to, 'n' => $n, 'role' => $roles[ $id ][ $to ] ?? '' ];
				}
			}
			$b['moves'] = $moves;
			foreach ( $b['bundle_hosts'] as $h => $n ) {
				if ( in_array( $b['sites'][ $h ]['role'] ?? '', self::BAD, true ) ) {
					$b['bundles_none'] += $n;
				}
			}
			unset( $b['bundle_hosts'] );
			uasort( $b['sites'], static fn( $x, $y ) => $y['n'] <=> $x['n'] );
			$b['sites']    = array_values( $b['sites'] );
			$brands[ $id ] = $b;
		}
		return [ 'brands' => $brands, 'hosts' => $hosts ];
	}

	/** The cached landscape, or null when there is none or it was built more than CACHE_S ago. */
	private static function cached(): ?array {
		$c = get_transient( self::CACHE );
		return is_array( $c ) && isset( $c['hosts'] ) && ( $c['at'] ?? 0 ) > time() - self::CACHE_S ? $c : null;
	}

	/** Rebuild these brands inside the cached landscape $c (and save it). */
	private static function patch( array $c, array $terms ): array {
		$terms = array_values( array_unique( array_filter( array_map( 'intval', $terms ) ) ) );
		if ( ! $terms ) {
			return $c;
		}
		$new = self::build( $terms );
		foreach ( $c['hosts'] as $h => $ts ) {
			foreach ( $terms as $t ) {
				unset( $ts[ $t ] );
			}
			if ( $ts ) {
				$c['hosts'][ $h ] = $ts;
			} else {
				unset( $c['hosts'][ $h ] );
			}
		}
		foreach ( $new['hosts'] as $h => $ts ) {
			$c['hosts'][ $h ] = ( $c['hosts'][ $h ] ?? [] ) + $ts;
		}
		foreach ( $terms as $t ) {
			if ( isset( $new['brands'][ $t ] ) ) {
				$c['brands'][ $t ] = $new['brands'][ $t ];
			} else {
				unset( $c['brands'][ $t ] ); // the brand was deleted
			}
		}
		set_transient( self::CACHE, $c, max( 1, (int) ( $c['at'] ?? 0 ) + self::CACHE_S - time() ) );
		return $c;
	}

	/** Patch the brands of what changed since the cache was saved. */
	private static function apply_dirty( array $c ): array {
		self::flush_dirty();
		$d = get_transient( self::DIRTY );
		if ( ! is_array( $d ) || ( empty( $d['p'] ) && empty( $d['t'] ) ) ) {
			return $c;
		}
		delete_transient( self::DIRTY );
		$terms = array_map( 'intval', $d['t'] ?? [] );
		if ( ! empty( $d['p'] ) ) {
			$got   = wp_get_object_terms( array_map( 'intval', $d['p'] ), 'product_brand', [ 'fields' => 'ids' ] );
			$terms = array_merge( $terms, is_array( $got ) ? array_map( 'intval', $got ) : [] );
		}
		return self::patch( $c, $terms );
	}

	/** The cache → what callers get: brands as a list, each site with how many brands share it. */
	private static function publish( array $c ): array {
		$brands = [];
		foreach ( $c['brands'] as $b ) {
			foreach ( $b['sites'] as $i => $s ) {
				$b['sites'][ $i ]['shared'] = count( $c['hosts'][ $s['host'] ] ?? [] );
			}
			$brands[] = $b;
		}
		$shared = [];
		foreach ( $c['hosts'] as $h => $ts ) {
			if ( count( $ts ) > 1 ) {
				$shared[ $h ] = array_keys( $ts );
			}
		}
		return [ 'brands' => $brands, 'shared' => $shared ];
	}

	/** Note what changed (saved once, at the end of the request: an import changes many URLs). */
	private static function dirty( array $products, array $terms ): void {
		if ( self::$dirty === null ) {
			self::$dirty = [ 'p' => [], 't' => [] ];
			add_action( 'shutdown', [ __CLASS__, 'flush_dirty' ] );
		}
		self::$dirty['p'] = array_merge( self::$dirty['p'], $products );
		self::$dirty['t'] = array_merge( self::$dirty['t'], $terms );
	}

	/** Save the changes noted in this request for the next landscape read. */
	public static function flush_dirty(): void {
		if ( empty( self::$dirty['p'] ) && empty( self::$dirty['t'] ) ) {
			return;
		}
		$d = get_transient( self::DIRTY );
		$d = is_array( $d ) ? $d : [ 'p' => [], 't' => [] ];
		$d = [ 'p' => array_values( array_unique( array_merge( $d['p'], self::$dirty['p'] ) ) ), 't' => array_values( array_unique( array_merge( $d['t'], self::$dirty['t'] ) ) ) ];
		self::$dirty = [ 'p' => [], 't' => [] ];
		if ( count( $d['p'] ) > self::DIRTY_MAX ) {
			delete_transient( self::CACHE ); // a big batch: rebuilding everything is cheaper than patching
			delete_transient( self::DIRTY );
			return;
		}
		set_transient( self::DIRTY, $d, self::CACHE_S );
	}

	public static function forget(): void {
		delete_transient( self::CACHE );
		delete_transient( self::DIRTY );
		self::$dirty = [ 'p' => [], 't' => [] ];
		self::$roles   = null;
		self::$gone    = null;
		self::$bundles = null;
	}

	/**
	 * Save a brand's Brand website and site roles ('unknown' clears a role). The Brand website's site
	 * becomes official unless it has a role already. $gone sets the brand aside as having no maker site
	 * (true) or brings it back (false); a maker site saved here brings it back too. @return array the brand's landscape row
	 */
	public static function save( int $term_id, ?string $website, array $roles, ?bool $gone = null ): array {
		self::maybe_install();
		global $wpdb;
		$before = self::roles()[ $term_id ] ?? [];
		if ( $website !== null && class_exists( 'MMI_Brand_Website' ) ) {
			$url = esc_url_raw( trim( $website ) );
			if ( $url === '' ) {
				\MMI_Brand_Website::clear( $term_id ); // and the older keys a site lists, or it comes back
			} else {
				\MMI_Brand_Website::set( $term_id, $url ); // shared field: cleaned the same way, change announced
				$h = self::host( $url );
				if ( $h !== '' && ! isset( $roles[ $h ] ) && ! isset( $before[ $h ] ) ) {
					$roles[ $h ] = 'official';
				}
			}
		}
		foreach ( $roles as $host => $role ) {
			$host = self::host( (string) $host );
			if ( $host === '' ) {
				continue;
			}
			if ( in_array( $role, self::ROLES, true ) ) {
				$wpdb->replace( self::table(), [ 'term_id' => $term_id, 'host' => mb_substr( $host, 0, 190 ), 'role' => $role, 'set_by' => get_current_user_id(), 'set_at' => current_time( 'mysql', true ) ] );
			} else {
				$wpdb->delete( self::table(), [ 'term_id' => $term_id, 'host' => $host ] );
			}
		}
		self::$roles = null;
		$was_gone    = self::is_gone( $term_id );
		if ( $gone === true && ! self::has_maker_site( $term_id ) ) {
			update_term_meta( $term_id, self::GONE, [ 'at' => current_time( 'mysql', true ), 'by' => get_current_user_id() ] );
		} elseif ( $gone === false || self::has_maker_site( $term_id ) ) {
			delete_term_meta( $term_id, self::GONE );
		}
		self::$gone = null; // brand_row() below rebuilds the brand, its products' lists with it
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'brand_sites.save', [ 'object_type' => 'term', 'object_id' => $term_id, 'details' => [ 'website' => $website, 'roles' => $roles, 'before' => $before, 'gone' => self::is_gone( $term_id ), 'gone_before' => $was_gone ] ] );
		}
		return self::brand_row( $term_id ); // roles and the website only change this brand's row
	}

	/* ── REST ──────────────────────────────────────────────────────────── */

	public static function routes(): void {
		$admin = static fn() => current_user_can( Module::CAP );
		register_rest_route( Module::NS, '/brand-sites', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( self::landscape( (bool) $r->get_param( 'fresh' ) ) + [ 'find' => (object) self::finds() ] ) ] );
		register_rest_route(
			Module::NS,
			'/brand-sites/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => $admin,
					'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
					'callback'            => [ __CLASS__, 'one' ],
				],
				[
					'methods'             => 'POST',
					'permission_callback' => $admin,
					'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
					'callback'            => [ __CLASS__, 'post' ],
				],
			]
		);
		register_rest_route(
			Module::NS,
			'/brand-sites/(?P<id>\d+)/to-own',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
				'callback'            => [ __CLASS__, 'to_own' ],
			]
		);
		register_rest_route(
			Module::NS,
			'/brand-sites/(?P<id>\d+)/products',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'args'                => [
					'id'    => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ],
					'group' => [ 'sanitize_callback' => static fn( $v ) => substr( preg_replace( '/[^a-z0-9.:-]/', '', strtolower( (string) $v ) ), 0, 200 ) ],
					'page'  => [ 'sanitize_callback' => 'absint' ],
				],
				'callback'            => [ __CLASS__, 'products' ],
			]
		);
		register_rest_route(
			Module::NS,
			'/brand-sites/(?P<id>\d+)/bundles-none',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
				'callback'            => [ __CLASS__, 'bundles_none' ],
			]
		);
		register_rest_route(
			Module::NS,
			'/brand-sites/(?P<id>\d+)/move',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
				'callback'            => [ __CLASS__, 'move' ],
			]
		);
		register_rest_route(
			Module::NS,
			'/brand-sites/(?P<id>\d+)/pages',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
				'callback'            => [ __CLASS__, 'pages' ],
			]
		);
	}

	/** Brands whose "Find product pages" search is running or ran: term id → state (self::find_state()). */
	private static function finds(): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s", self::FIND_META ) ) as $t ) {
			$out[ (int) $t ] = self::find_state( (int) $t );
		}
		return $out;
	}

	/**
	 * GET /brand-sites/{id}/products?group=&page= — the products behind one of the drawer's counts, as
	 * Vendor pages rows (PageReview), each with `bs` (its place in the picture, bucket()) and `groups`
	 * (every list it is in). group: site:<host>, failed, nourl, nopage, open or all. With ids[] instead,
	 * just those products, whatever list they are in now (a row redrawn after its action). Each answer
	 * carries the brand's row rebuilt now, so the drawer's counts and chips follow every action.
	 */
	public static function products( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! term_exists( $id, 'product_brand' ) ) {
			return new \WP_REST_Response( [ 'message' => 'No such brand.' ], 404 );
		}
		$group  = (string) ( $r->get_param( 'group' ) ?: 'all' );
		$want   = array_values( array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ) ) );
		$brand  = self::brand_row( $id );
		$off    = self::off_hosts( $brand );
		$k      = [];
		$titles = [];
		foreach ( self::scan( [ $id ] ) as $x ) {
			$k[ (int) $x['id'] ]      = $x['k'];
			$titles[ (int) $x['id'] ] = html_entity_decode( (string) $x['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$ids = $want ? array_values( array_intersect( $want, array_keys( $k ) ) ) : array_keys( array_filter( $k, static fn( $b ) => self::in_group( $b, $group, $off ) ) );
		usort( $ids, static fn( $a, $b ) => strnatcasecmp( $titles[ $a ], $titles[ $b ] ) ?: $a <=> $b );
		$total = count( $ids );
		$page  = max( 1, (int) $r->get_param( 'page' ) );
		$rows  = PageReview::rows_for( $want ? $ids : array_slice( $ids, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) );
		$members = self::bundle_members( array_values( array_filter( array_map( 'intval', array_column( $rows, 'id' ) ), static fn( $p ) => ! empty( $k[ $p ]['bundle'] ) ) ) );
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['bs']     = $k[ $row['id'] ];
			$rows[ $i ]['groups'] = self::groups_of( $k[ $row['id'] ], $off );
			$rows[ $i ]['shared'] = SharedPages::relation( (int) $row['id'], (string) ( $row['current'] ?? '' ) ); // a page others use too: what this one reads
			$rows[ $i ]['bundle'] = $members[ (int) $row['id'] ] ?? null; // a bundle: its members by status
		}
		return rest_ensure_response( [ 'group' => $group, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE, 'brand' => $brand ] );
	}

	/**
	 * GET /brand-sites/{id}/pages?host=&ids[]=&list=0&fresh=1 — a URL picker for the drawer (owner 2026-10-13: "their
	 * base URLs should populate … then the only question is the remaining slug"). For one of the brand's own
	 * sites: the product pages it publishes (its store's product list, VendorCatalog::catalog(), cached 7
	 * days; else its sitemap, only when the finder has already read it), the address its product pages
	 * share (`base`), and for each product asked about the page whose name names it best. Slugs carry the
	 * vendor's taglines ("bull-sub-machine-leslie-brathwaites-secret-weapon"), so pages are picked from the
	 * site's own list, never built from our titles. Nothing is saved here.
	 *
	 * A store's list not read yet is read in steps of READ_STEP_S (a large store takes minutes and timed
	 * out as one request, 2026-10-15): until it is all in, the answer is { pending, reading } and the
	 * drawer asks again, showing how far it got.
	 */
	public static function pages( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! term_exists( $id, 'product_brand' ) ) {
			return new \WP_REST_Response( [ 'message' => 'No such brand.' ], 404 );
		}
		$b     = self::brand_row( $id );
		$hosts = self::own_hosts( $b );
		$host  = self::host( (string) $r->get_param( 'host' ) );
		if ( $host !== '' && ! self::public_host( $host ) ) {
			return new \WP_REST_Response( [ 'message' => sprintf( '“%s” isn’t a site address: type it like example.com.', $host ) ], 400 );
		}
		$host  = $host !== '' ? $host : ( $hosts[0] ?? '' );
		if ( $host !== '' && ! in_array( $host, $hosts, true ) ) {
			$hosts[] = $host;
		}
		if ( $host !== '' && $r->get_param( 'fresh' ) ) {
			VendorCatalog::forget( $host ); // "Reload the site's list": read its store again
		}
		[ $items, $source ] = $host !== '' ? self::site_pages( $host ) : [ [], 'none' ];
		if ( $items === null ) {
			return rest_ensure_response( [ 'pending' => true, 'hosts' => $hosts, 'host' => $host, 'reading' => VendorCatalog::progress( $host ) ] );
		}
		$matches = [];
		$mine    = [];
		foreach ( self::scan( [ $id ] ) as $x ) {
			$mine[ (int) $x['id'] ] = html_entity_decode( (string) $x['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		foreach ( array_slice( array_map( 'intval', (array) $r->get_param( 'ids' ) ), 0, 200 ) as $pid ) {
			if ( isset( $mine[ $pid ] ) ) {
				$matches[ $pid ] = $items ? self::match( $mine[ $pid ], $b['name'] ?? '', $items ) : null;
			}
		}
		// Products a page lists among others (Sonic Atlas's packs): no page of their own, a card on one of the site's.
		$unsure = array_filter( $matches, static fn( $m ) => ! $m || $m['score'] < 70 );
		$cards  = [];
		if ( $unsure && $host !== '' ) {
			foreach ( self::card_matches( $host, $items, array_intersect_key( $mine, $unsure ), (string) ( $b['name'] ?? '' ) ) as $pid => $hit ) {
				$matches[ $pid ]           = $hit['match'];
				$cards[ $hit['item']['name'] ] = $hit['item'];
			}
		}
		return rest_ensure_response(
			[
				'hosts'   => $hosts,
				'host'    => $host,
				'source'  => $source, // shopify | woo | wp | sitemap | browser | none
				'blocked' => $host !== '' && VendorCatalog::blocked( $host ), // its firewall refuses this server: send its pages from the browser
				'base'    => $host !== '' ? self::base_of( $host, $items, $id ) : '',
				'items'   => (string) $r->get_param( 'list' ) === '0' ? null : array_map( static fn( $i ) => [ 'url' => $i['url'], 'name' => $i['name'] ], $items ),
				'matches' => (object) $matches,
				'cards'   => array_values( $cards ), // "Page › Card" entries for the picker, sent even when the list isn't
			]
		);
	}

	/**
	 * A host the server may read for a person: a public name (letters, digits, dots, hyphens, a real top-level
	 * domain), never an IP address, localhost or a port. The fetch itself also refuses private addresses
	 * (wp_safe_remote_get()).
	 */
	public static function public_host( string $host ): bool {
		return strlen( $host ) <= 253 && (bool) preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host ) && ! filter_var( $host, FILTER_VALIDATE_IP ) && ! preg_match( '/(^|\.)(localhost|local|internal|lan|home\.arpa)$/', $host );
	}

	/**
	 * Cards that name these products on the site's stored pages that list several (its list's pages and the
	 * pages its products already use, at most CARD_PAGES): page → card → product, each as a match pointing at
	 * the page ("Sonic Atlas › Bollywood Gold"). The product then reads its card (SharedPages).
	 * @param array<int,string> $titles product id → title @return array<int,array{match:array,item:array}>
	 */
	private static function card_matches( string $host, array $items, array $titles, string $brand ): array {
		global $wpdb;
		$bare = (string) preg_replace( '/^www\./', '', $host );
		// Every page our products use there, not only pages two of them share (owner 2026-10-28: one maker's synth page,
		// used by MYTH alone, holds its expansion packs' cards; a site with no product list had nothing else to look at).
		$used = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'product' AND p.post_status = 'publish' WHERE m.meta_key = 'vendor_product_url' AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s ) LIMIT 3000", '%//' . $wpdb->esc_like( $bare ) . '/%', '%//www.' . $wpdb->esc_like( $bare ) . '/%' ) );
		$urls = array_slice( array_values( array_unique( array_merge( array_column( $items, 'url' ), array_keys( array_filter( SharedPages::shared_urls(), static fn( $n, $u ) => self::host( $u ) === $host, ARRAY_FILTER_USE_BOTH ) ), $used ) ) ), 0, 3000 );
		if ( ! $urls ) {
			return [];
		}
		$hashes = implode( ',', array_map( static fn( $u ) => "'" . md5( $u ) . "'", $urls ) );
		$pages  = $wpdb->get_results( 'SELECT url, title, html_file, content_hash FROM ' . PageStore::table() . " WHERE url_hash IN ({$hashes}) AND status = 'ok' ORDER BY fetched_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- md5 hex
		// Pages whose address carries a word of these products' names first (a pack "for Myth" is listed on /products/myth).
		$words = [];
		foreach ( $titles as $title ) {
			$words += array_flip( UrlFinder::tokens( PageName::of( $title, $brand, $title ), UrlFinder::tokens( $brand ) ) );
		}
		$rank = static fn( $pg ) => count( array_intersect_key( array_flip( UrlFinder::tokens( (string) wp_parse_url( (string) $pg['url'], PHP_URL_PATH ) ) ), $words ) );
		$at   = array_flip( array_keys( $pages ) );
		uksort( $pages, static fn( $a, $b ) => $rank( $pages[ $b ] ) <=> $rank( $pages[ $a ] ) ?: $at[ $a ] <=> $at[ $b ] );
		$pages = array_slice( $pages, 0, self::CARD_PAGES );
		$out    = [];
		foreach ( $pages as $pg ) {
			$cards = SharedPages::cards( PageStore::read( (string) $pg['html_file'] ), (string) $pg['url'], (string) $pg['content_hash'] );
			if ( ! $cards ) {
				continue;
			}
			$page = trim( (string) preg_split( '/\s[-–—|]\s/u', html_entity_decode( (string) $pg['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 2 )[0] );
			foreach ( $titles as $pid => $title ) {
				$name = PageName::of( $title, $brand, $title );
				foreach ( $cards as $c ) {
					$s = SharedPages::covers( $name, $brand, $c['name'] );
					if ( $s >= 50 && ( ! isset( $out[ $pid ] ) || $s > $out[ $pid ]['s'] ) ) {
						$label       = ( $page !== '' ? $page : (string) $pg['url'] ) . ' › ' . $c['name'];
						$out[ $pid ] = [ 's' => $s, 'match' => [ 'url' => (string) $pg['url'], 'name' => $label, 'score' => 75, 'why' => 'its card on ' . ( $page !== '' ? $page : 'a page listing several' ) ], 'item' => [ 'url' => (string) $pg['url'], 'name' => $label ] ];
					}
				}
			}
		}
		return array_map( static fn( $x ) => [ 'match' => $x['match'], 'item' => $x['item'] ], $out );
	}

	/** The brand's own sites (official or partner, saved or suggested, most products first), then its Brand website. @return string[] */
	private static function own_hosts( array $b ): array {
		$own = array_filter( $b['sites'] ?? [], static fn( $s ) => in_array( $s['role'], [ 'official', 'partner' ], true ) );
		usort( $own, static fn( $x, $y ) => $y['n'] <=> $x['n'] );
		return array_values( array_unique( array_filter( array_merge( array_column( $own, 'host' ), [ (string) ( $b['website'] ?? '' ) ] ) ) ) );
	}

	/**
	 * A site's product pages: its store's product list or its WordPress page list, else the sitemap the
	 * finder already read (never fetched here: a sitemap walk can take minutes). A site's own list beats
	 * its sitemap: a maker's 2020 sitemap still lists pages that are gone (2026-10-14).
	 * @return array{0:array<int,array{url:string,name:string}>|null,1:string} items null: the store's list is still being read
	 */
	private static function site_pages( string $host ): array {
		$items = VendorCatalog::catalog( $host, self::READ_STEP_S ); // cached 7 days; a first look reads in steps
		if ( $items === null ) {
			return [ null, 'reading' ];
		}
		if ( $items ) {
			return [ array_slice( $items, 0, 3000 ), (string) ( $items[0]['source'] ?? 'catalog' ) ];
		}
		$urls = get_transient( 'mmpt_sitemap_' . md5( $host ) );
		if ( ! is_array( $urls ) || ! $urls ) {
			return [ [], 'none' ];
		}
		$out = [];
		foreach ( $urls as $u ) {
			if ( UrlFinder::looks_like_product_page( (string) $u ) ) {
				$slug  = basename( (string) wp_parse_url( (string) $u, PHP_URL_PATH ) );
				$out[] = [ 'url' => (string) $u, 'name' => ucwords( trim( preg_replace( '/[-_]+/', ' ', preg_replace( '/\.(html?|php|aspx?)$/i', '', rawurldecode( $slug ) ) ) ) ) ];
			}
		}
		return [ array_slice( $out, 0, 3000 ), $out ? 'sitemap' : 'none' ];
	}

	/** The address the site's product pages share ("https://safaripedals.com/products/"): from its list, else the brand's URLs there. */
	private static function base_of( string $host, array $items, int $term_id ): string {
		$urls = array_column( $items, 'url' );
		if ( ! $urls ) {
			foreach ( self::scan( [ $term_id ] ) as $x ) {
				if ( self::host( (string) $x['url'] ) === $host ) {
					$urls[] = (string) $x['url'];
				}
			}
		}
		$dirs = [];
		foreach ( $urls as $u ) {
			$p = (string) wp_parse_url( $u, PHP_URL_PATH );
			$d = rtrim( substr( $p, 0, (int) strrpos( rtrim( $p, '/' ), '/' ) + 1 ), '/' ) . '/';
			$dirs[ $d ] = ( $dirs[ $d ] ?? 0 ) + 1;
		}
		arsort( $dirs );
		$scheme = $urls ? ( (string) wp_parse_url( $urls[0], PHP_URL_SCHEME ) ?: 'https' ) : 'https';
		$h      = $urls ? (string) wp_parse_url( $urls[0], PHP_URL_HOST ) : $host;
		return $scheme . '://' . $h . ( $dirs ? (string) array_key_first( $dirs ) : '/' );
	}

	/**
	 * The page whose name names the product best. Vendor names often add a tagline after " - " ("Bull Sub
	 * Machine - Leslie Brathwaite's secret weapon"): the name before it is tried too. @return array|null
	 */
	private static function match( string $title, string $brand, array $items ): ?array {
		$heads = array_map( static fn( $i ) => [ 'name' => trim( (string) preg_split( '/\s[-–—|]\s/u', (string) $i['name'], 2 )[0] ), 'full' => $i['name'], 'url' => $i['url'] ], $items );
		$a     = VendorCatalog::best( $title, $brand, $items );
		$h     = VendorCatalog::best( $title, $brand, $heads );
		$m     = $h && ( ! $a || $h['score'] > $a['score'] ) ? $h : $a;
		if ( ! $m ) {
			return null;
		}
		return [ 'url' => $m['url'], 'name' => $m['full'] ?? $m['name'], 'score' => (int) $m['score'], 'why' => (string) $m['why'] ];
	}

	/** POST /brand-sites/{id} {website?, roles: {host: role}, find?: bool} */
	/**
	 * POST /brand-sites/{id}/move {from, to} — one of the brand's sites moved to another (owner 2026-10-13:
	 * safaripedals.com → safariaudio.com, fixable only one row at a time). Every product whose page on `from`
	 * now redirects to `to`, with that address proposed, takes it: approved and scraped, as Approve does.
	 * `to` is saved as an official site (unless it has a role), and becomes the Brand website when that was
	 * `from`. One person's decision about one site, so the bulk approve guard doesn't apply.
	 */
	public static function move( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! term_exists( $id, 'product_brand' ) ) {
			return new \WP_REST_Response( [ 'message' => 'No such brand.' ], 404 );
		}
		$from = self::host( (string) $r->get_param( 'from' ) );
		$to   = self::host( (string) $r->get_param( 'to' ) );
		if ( $from === '' || $to === '' || $from === $to ) {
			return new \WP_REST_Response( [ 'message' => 'Name the site the pages moved from and the site they moved to.' ], 400 );
		}
		$saved = self::roles()[ $id ][ $to ] ?? '';
		if ( $saved !== '' && ! in_array( $saved, [ 'official', 'partner' ], true ) ) {
			return new \WP_REST_Response( [ 'message' => sprintf( '%s is saved as a %s site for this brand. Change its role first.', $to, $saved ) ], 409 );
		}
		$ids = [];
		foreach ( self::scan( [ $id ] ) as $x ) {
			if ( $x['k']['kind'] === 'site' && $x['k']['host'] === $from && $x['k']['to'] === $to ) {
				$ids[] = (int) $x['id'];
			}
		}
		if ( ! $ids ) {
			return new \WP_REST_Response( [ 'message' => sprintf( 'No product page waits to move from %s to %s.', $from, $to ) ], 404 );
		}
		self::save( $id, self::website_host( $id ) === $from ? 'https://' . $to . '/' : null, $saved === '' ? [ $to => 'official' ] : [] );
		$done = UrlQueue::approve( $ids );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'brand_sites.move', [ 'object_type' => 'term', 'object_id' => $id, 'details' => [ 'from' => $from, 'to' => $to, 'ids' => $done ] ] ); // every id, so the move can be undone
		}
		return rest_ensure_response( [ 'done' => count( $done ), 'brand' => self::brand_row( $id ) ] );
	}

	/** GET /brand-sites/{id} — the brand's row now and its "Find product pages" state (the drawer watches a search). */
	public static function one( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! term_exists( $id, 'product_brand' ) ) {
			return new \WP_REST_Response( [ 'message' => 'No such brand.' ], 404 );
		}
		self::read_links( self::website_host( $id ) ); // brand_row() rebuilds the brand, so new suggestions show at once
		return rest_ensure_response( [ 'brand' => self::brand_row( $id ), 'find' => self::find_state( $id ) ] );
	}

	/**
	 * POST /brand-sites/{id}/to-own {from} — products whose page is on a reseller (or a store, or a site
	 * that isn't the brand's) take the page proposed for them on the brand's own site (owner 2026-10-14:
	 * a brand had 54 pages on a distributor's site while it has its own site). Every product
	 * on `from` whose proposed page is on a site a person confirmed as the brand's (saved official or
	 * partner, or the Brand website) takes it: approved and scraped, as Approve does. `from` keeps its
	 * suggested role, saved. Products with no such proposal stay as they are. One person's decision about
	 * one site, as move() is; every replaced URL is in the audit log.
	 */
	public static function to_own( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! term_exists( $id, 'product_brand' ) ) {
			return new \WP_REST_Response( [ 'message' => 'No such brand.' ], 404 );
		}
		$from = self::host( (string) $r->get_param( 'from' ) );
		$b    = self::brand_row( $id );
		$site = current( array_filter( $b['sites'] ?? [], static fn( $s ) => $s['host'] === $from ) );
		if ( ! $site || ! in_array( $site['role'], self::BAD, true ) ) {
			return new \WP_REST_Response( [ 'message' => sprintf( 'Pages are moved off a reseller, a store or a site that isn’t the brand’s; %s isn’t one for this brand.', $from ) ], 409 );
		}
		$prior = [];
		foreach ( self::scan( [ $id ] ) as $x ) {
			if ( $x['k']['kind'] === 'site' && $x['k']['host'] === $from && $x['k']['open'] && self::own_host( $id, (string) $b['website'], self::host( (string) $x['ch'] ) ) ) {
				$prior[ (int) $x['id'] ] = (string) $x['url'];
			}
		}
		if ( ! $prior ) {
			return new \WP_REST_Response( [ 'message' => sprintf( 'No product on %s has a page proposed on the brand’s own site yet.', $from ) ], 404 );
		}
		if ( ! $site['saved'] ) {
			self::save( $id, null, [ $from => $site['role'] ] );
		}
		$done = UrlQueue::approve( array_keys( $prior ) );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'brand_sites.to_own', [ 'object_type' => 'term', 'object_id' => $id, 'details' => [ 'from' => $from, 'ids' => $done, 'replaced' => array_intersect_key( $prior, array_flip( $done ) ) ] ] ); // every id and its old URL, so it can be undone
		}
		return rest_ensure_response( [ 'done' => count( $done ), 'brand' => self::brand_row( $id ) ] );
	}

	/** Bundle id → its members by status, for the drawer's bundle rows. @return array<int,array{confirmed:int,proposed:int}> */
	private static function bundle_members( array $ids ): array {
		if ( ! $ids ) {
			return [];
		}
		global $wpdb;
		$out = array_fill_keys( $ids, [ 'confirmed' => 0, 'proposed' => 0 ] );
		foreach ( $wpdb->get_results( 'SELECT bundle_id, status, COUNT(*) n FROM ' . \MMI_Pipeline_Bundles::members_table() . ' WHERE bundle_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ") AND status IN ('confirmed','proposed') GROUP BY bundle_id, status", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ids are ints
			$out[ (int) $x['bundle_id'] ][ $x['status'] ] = (int) $x['n'];
		}
		return $out;
	}

	/**
	 * POST /brand-sites/{id}/bundles-none — every bundle of the brand with no maker page (none, or one on a site
	 * that isn't the maker's) is marked as having no vendor page (owner 2026-10-17: a bundle sold here takes its
	 * content from its products, Bundles → From its products). A bundle on a maker's page, even a broken one, is
	 * left alone. One audit event lists every product and the URL it had, so it can be undone.
	 */
	public static function bundles_none( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! term_exists( $id, 'product_brand' ) ) {
			return new \WP_REST_Response( [ 'message' => 'No such brand.' ], 404 );
		}
		$off = self::off_hosts( self::brand_row( $id ) );
		$ids = [];
		foreach ( self::scan( [ $id ] ) as $x ) {
			if ( self::in_group( $x['k'], 'bundles_none', $off ) ) {
				$ids[ (int) $x['id'] ] = true;
			}
		}
		$had = [];
		foreach ( array_keys( $ids ) as $pid ) {
			$had[ $pid ] = UrlQueue::mark_none( $pid, false )['url'];
		}
		if ( $had && class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.none_bundles', [ 'object_type' => 'term', 'object_id' => $id, 'details' => [ 'products' => $had ] ] ); // each product and the URL it had
		}
		return rest_ensure_response( [ 'done' => count( $had ), 'brand' => self::brand_row( $id ) ] );
	}

	public static function post( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! term_exists( $id, 'product_brand' ) ) {
			return new \WP_REST_Response( [ 'message' => 'No such brand.' ], 404 );
		}
		$website = $r->has_param( 'website' ) ? trim( (string) $r->get_param( 'website' ) ) : null;
		if ( $website !== null && $website !== '' ) {
			$website = preg_match( '#^https?://#i', $website ) ? $website : 'https://' . $website; // "babyaudio.com" is fine
			$host    = (string) wp_parse_url( esc_url_raw( $website, [ 'http', 'https' ] ), PHP_URL_HOST );
			if ( preg_match( '/\s/', $website ) || ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host ) ) {
				return new \WP_REST_Response( [ 'message' => 'That is not a web address: type the site, such as https://babyaudio.com.' ], 400 );
			}
		}
		$remove = self::host( (string) $r->get_param( 'remove' ) );
		if ( $remove !== '' ) {
			return self::remove( $id, $remove, rest_sanitize_boolean( $r->get_param( 'bundles_none' ) ) );
		}
		$roles = array_map( 'strval', (array) $r->get_param( 'roles' ) );
		foreach ( array_keys( $roles ) as $h ) { // a person can add any site: only a public site name is kept
			if ( ! self::public_host( self::host( (string) $h ) ) ) {
				return new \WP_REST_Response( [ 'message' => sprintf( '“%s” isn’t a site address: type it like example.com.', $h ) ], 400 );
			}
		}
		$gone = $r->has_param( 'gone' ) ? rest_sanitize_boolean( $r->get_param( 'gone' ) ) : null;
		if ( $gone === true && ( $website || array_intersect( $roles, [ 'official', 'partner' ] ) || self::has_maker_site( $id ) ) ) {
			return new \WP_REST_Response( [ 'message' => 'This brand has a maker site: switch its maker sites off first.' ], 409 );
		}
		$brand = $website !== null || $roles || $gone !== null ? self::save( $id, $website, $roles, $gone ) : self::brand_row( $id ); // "Find them" alone saves nothing
		$find  = null;
		if ( $r->get_param( 'find' ) ) {
			$req = new \WP_REST_Request( 'POST', '/' . Module::NS . '/screens/brands/' . $id . '/find' );
			$req->set_url_params( [ 'id' => $id ] );
			$res  = self::brand_find( $req );
			$find = $res->get_status() < 300 ? ( $res->get_data()['find'] ?? null ) : [ 'status' => 'error', 'message' => $res->get_data()['message'] ?? '' ];
		}
		return rest_ensure_response( [ 'brand' => $brand, 'find' => $find ?? self::find_state( $id ) ] );
	}

	/**
	 * Take a site off a brand's list: its saved role goes, and the Brand website if it was that. Refused while
	 * products link there (owner, 2026-10-16: give them other pages first; no URLs are cleared in bulk), since
	 * the list is drawn from the products' URLs and the row would only come back.
	 *
	 * $bundles_none, for a site that isn't the maker's (owner 2026-10-17): the bundles linking there are marked
	 * as having no maker page first (a bundle sold here takes its content from its products), as bundles_none()
	 * does for the whole brand, in one audit event. The site goes once nothing else links there; otherwise the
	 * answer says how many products still do (`left`).
	 */
	private static function remove( int $id, string $host, bool $bundles_none = false ): \WP_REST_Response {
		$site = null;
		foreach ( self::brand_row( $id )['sites'] ?? [] as $s ) {
			if ( $s['host'] === $host ) {
				$site = $s;
			}
		}
		if ( ! $site ) {
			return new \WP_REST_Response( [ 'message' => sprintf( '%s isn’t on this brand’s list.', $host ) ], 404 );
		}
		$marked = 0;
		if ( $bundles_none && (int) $site['n'] > 0 ) {
			if ( ! isset( self::off_hosts( self::brand_row( $id ) )[ $host ] ) ) {
				return new \WP_REST_Response( [ 'message' => sprintf( '%s is the maker’s site: its pages are kept. Switch it off first if it isn’t.', $host ) ], 409 );
			}
			$had = [];
			foreach ( self::scan( [ $id ] ) as $x ) {
				if ( ! empty( $x['k']['bundle'] ) && self::in_group( $x['k'], 'site:' . $host ) ) {
					$had[ (int) $x['id'] ] = UrlQueue::mark_none( (int) $x['id'], false )['url'];
				}
			}
			if ( $had && class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.none_bundles', [ 'object_type' => 'term', 'object_id' => $id, 'details' => [ 'host' => $host, 'products' => $had ] ] ); // each product and the URL it had
			}
			$marked = count( $had );
			foreach ( self::brand_row( $id )['sites'] ?? [] as $s ) {
				if ( $s['host'] === $host ) {
					$site = $s;
				}
			}
			if ( (int) $site['n'] > 0 ) {
				return rest_ensure_response( [ 'brand' => self::brand_row( $id ), 'find' => self::find_state( $id ), 'marked' => $marked, 'left' => (int) $site['n'] ] );
			}
		}
		if ( (int) $site['n'] > 0 ) {
			return new \WP_REST_Response( [ 'message' => sprintf( '%1$d products link to %2$s: give them other pages first.', (int) $site['n'], $host ), 'n' => (int) $site['n'] ], 409 );
		}
		$brand = self::save( $id, self::website_host( $id ) === $host ? '' : null, [ $host => '' ] );
		return rest_ensure_response( [ 'brand' => $brand, 'find' => self::find_state( $id ), 'marked' => $marked, 'left' => 0 ] );
	}

	/**
	 * `wp mmpt runner brand-pages --term=<id>` (or a Background step): the brand's catalog (Shopify / WooCommerce) or
	 * sitemap, matched to its products with no verified vendor URL (VendorCatalog, then UrlFinder), and what the
	 * proposed pages can fill worked out (Coverage). Moved from the site plugin's screens (migration phase 10d).
	 * @return array<string,mixed>
	 */
	public static function find_pages( int $term_id ): array {
		if ( ! ( ( defined( 'WP_CLI' ) && WP_CLI ) || Background::$stepping ) ) {
			return [ 'error' => 'Runs from WP-CLI or a background step only.' ];
		}
		$term = get_term( $term_id, 'product_brand' );
		if ( ! $term instanceof \WP_Term ) {
			return [ 'error' => 'No such brand.' ];
		}
		global $wpdb;
		$m     = get_term_meta( $term_id, self::FIND_META, true );
		$m     = is_array( $m ) ? $m : [];
		$since = current_time( 'mysql' ); // UrlFinder's found_at is site time
		update_term_meta( $term_id, self::FIND_META, [ 'started_at' => gmdate( 'Y-m-d H:i:s' ) ] + $m );
		$finder  = UrlFinder::run( [ $term->name ] );                                               // sitemap guesses first,
		$catalog = VendorCatalog::run( [ $term->name ], 60, false, Background::$stepping ? 60 : 600 ); // then the vendor's own list, which replaces weak ones
		Coverage::precompute( 300 );
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . UrlFinder::table() . " c JOIN {$wpdb->term_relationships} tr ON tr.object_id = c.product_id JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' AND tt.term_id = %d WHERE c.status = 'candidate' AND c.found_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$term_id,
				$since
			)
		);
		$out = [
			'looked'     => max( (int) ( $catalog['products'] ?? 0 ), (int) ( $finder['products'] ?? 0 ) ),
			'found'      => $found,
			'no_sitemap' => (int) ( $catalog['no_sitemap'] ?? 0 ) + (int) ( $finder['no_sitemap'] ?? 0 ) > 0,
			'no_gaps'    => ! ( $catalog['brands'] ?? 0 ) && ! ( $finder['brands'] ?? 0 ),
		];
		$m = get_term_meta( $term_id, self::FIND_META, true );
		update_term_meta( $term_id, self::FIND_META, $out + [ 'finished_at' => gmdate( 'Y-m-d H:i:s' ) ] + ( is_array( $m ) ? $m : [] ) );
		Module::info( 'Brand product pages looked for', [ 'brand' => $term_id ] + $out );
		return $out;
	}

	/**
	 * POST /screens/brands/{id}/find — look for the brand's product pages on its own site, in the
	 * background (`wp mmpt runner brand-pages`). Matches become proposed URLs waiting for a person.
	 */
	public static function brand_find( \WP_REST_Request $r ): \WP_REST_Response {
		$id   = (int) $r['id'];
		$term = get_term( $id, 'product_brand' );
		if ( ! $term instanceof \WP_Term ) {
			return new \WP_REST_Response( [ 'message' => 'No such brand.' ], 404 );
		}
		$state = self::find_state( $id );
		if ( $state['status'] === 'running' ) {
			return new \WP_REST_Response( [ 'message' => 'Already looking for this brand’s pages.', 'find' => $state ], 409 );
		}
		$site = UrlFinder::brand_sites( [ $id => $term->name ] )[ $id ]['host'] ?? '';
		if ( $site === '' ) {
			return new \WP_REST_Response( [ 'message' => 'No site is known for this brand: fill in Brand website above, click Update, then try again.' ], 400 );
		}
		update_term_meta( $id, self::FIND_META, [ 'started_at' => gmdate( 'Y-m-d H:i:s' ), 'site' => $site, 'by' => get_current_user_id() ] );
		if ( ! Scraper::spawn( 'mmpt runner brand-pages --term=' . $id, 'brand-pages.log' ) ) {
			delete_term_meta( $id, self::FIND_META );
			return new \WP_REST_Response( [ 'message' => 'The server could not start the search.' ], 500 );
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.find_brand', [ 'object_type' => 'term', 'object_id' => $id, 'details' => [ 'site' => $site ] ] );
		}
		return rest_ensure_response( [ 'find' => self::find_state( $id ) ] );
	}

	/**
	 * The brand's last "Find product pages" run: never | running | stopped (not finished in time) | done.
	 * @return array<string,mixed>
	 */
	public static function find_state( int $term_id ): array {
		$m       = get_term_meta( $term_id, self::FIND_META, true );
		$m       = is_array( $m ) ? $m : [];
		$started = (string) ( $m['started_at'] ?? '' );
		if ( $started === '' ) {
			return [ 'status' => 'never' ];
		}
		if ( (string) ( $m['finished_at'] ?? '' ) >= $started ) {
			return [ 'status' => 'done' ] + $m;
		}
		$age = time() - (int) strtotime( $started . ' UTC' );
		return [ 'status' => $age > self::FIND_STALE_S ? 'stopped' : 'running' ] + $m;
	}
}
