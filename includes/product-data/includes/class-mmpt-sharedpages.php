<?php
/**
 * Vendor pages that several products share (owner, 2026-10-16: "When more than 1 product is linked to the
 * same product page, we need special logic"; 2,926 products shared 848 pages on that day).
 *
 * Each product's relation to its shared page decides what it reads from it:
 *  - one      the same product, same version, sold several ways (upgrade, crossgrade, platform, term,
 *             audience): the whole page, as before.
 *  - editions editions or versions of one product (Ozone Elements/Standard/Advanced, Pro Tools Artist/Studio/
 *             Ultimate): the page's family description when the page is about its version, and its own
 *             edition's column of the vendor's comparison (Product Variants Compare / Describe::edition_html).
 *  - several  a different product on a page that lists several (a maker's packs, another's sound
 *             packs, Groove Shaper's expansions): only its own card. When the card links to the product's
 *             own page, that page is proposed as its vendor URL (reason card_link).
 *
 * Decided per product from the products sharing the page: they are grouped into families (Product Variants
 * family, Product Paths family, or the same name once offer, edition and version words are gone). The
 * family the page is about (most products, or the one the page title names) reads the page: "one" when its
 * products differ only in how they are sold, else "editions". Every other product is "several". A person can
 * set a page's kind for all its products (`wp_mmpt_shared_pages`, POST /shared-pages).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SharedPages {

	const DB_OPTION  = 'mmpt_shared_pages_db_version';
	const DB_VERSION = '1';
	const KINDS      = [ 'one', 'editions', 'several' ];
	const CARD_MIN   = 34;  // share of a name element's words that are the product's own (all of the product's words must be there)
	const CARDS_TTL  = 12 * HOUR_IN_SECONDS;
	const FAMILY_SHARE = 3;  // the largest family is the page's subject when it's at least 1 in this many of its products
	const CARD_CLIMB = 5;    // levels above a code's element to look for the block with its picture
	const CARD_MAX   = 3000; // a card's text at most: past this it has grown into a section of cards
	/** Words that say how a product is sold or supported, not what it is (removed before families compare). */
	/** What kind of product it is, words our titles add that a card often leaves out ("Bollywood Gold" for "Bollywood Gold Expansion"). */
	const KIND_WORDS = [ 'expansion', 'expansions', 'pack', 'packs', 'kit', 'kits', 'bundle', 'collection', 'library', 'sound', 'sounds', 'soundset', 'presets', 'preset', 'loops', 'samples', 'sample', 'instrument', 'instruments', 'effect', 'effects', 'plugin', 'plugins', 'virtual', 'essentials' ];

	/** Edition words beyond VendorCatalog::EDITIONS (Pro Tools Artist / Studio / Ultimate). */
	const EDITION_WORDS = [ 'studio', 'intro', 'suite' ];
	const OFFER_WORDS = [ 'upgrade', 'upgrades', 'crossgrade', 'update', 'support', 'plan', 'renewal', 'subscription', 'perpetual', 'license', 'licence', 'edu', 'education', 'educational', 'institution', 'institutional', 'student', 'teacher', 'academic', 'competitive', 'download', 'boxed', 'box', 'retail', 'nfr', 'multi', 'seat', 'seats', 'user', 'users', 'year', 'years', 'month', 'months', 'annual', 'monthly', 'mac', 'win', 'windows', 'pc', 'osx', 'bit', 'only', 'native', 'hd', 'hdx', 'aax', 'vst', 'au', 'and', 'the', 'for', 'with', 'from', 'to', 'of', 'software', 'plugin', 'plugins', 'plug', 'in', 'ins', 'version', 'edition', 'editions', 'new' ];

	/** @var array<string,array> url → its members (members()) */
	private static $members = [];

	/** @var array<string,string>|null url_hash → kind a person set */
	private static $set = null;

	/** @var array<string,int>|null url → how many published products use it, for the URLs two or more share */
	private static $shared = null;

	/** @var array<string,bool> "url|version" → whether the page is about that version (description_for()) */
	private static $about = [];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_shared_pages';
	}

	public static function maybe_install(): void {
		if ( \MMI_Settings::get( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				url_hash char(32) NOT NULL,
				url varchar(500) NOT NULL,
				kind varchar(12) NOT NULL,
				set_by bigint(20) unsigned NOT NULL DEFAULT 0,
				set_at datetime NOT NULL,
				PRIMARY KEY  (url_hash)
			) " . $wpdb->get_charset_collate() . ';'
		);
		\MMI_Settings::set( self::DB_OPTION, self::DB_VERSION );
	}

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route(
			Module::NS,
			'/shared-pages',
			[
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( Module::CAP ),
				'callback'            => [ __CLASS__, 'post' ],
			]
		);
	}

	/** POST /shared-pages {url, kind}: a person says what a shared page is ('' = decide automatically). */
	public static function post( \WP_REST_Request $r ): \WP_REST_Response {
		$url  = esc_url_raw( trim( (string) $r->get_param( 'url' ) ), [ 'http', 'https' ] );
		$kind = (string) $r->get_param( 'kind' );
		if ( $url === '' || ( $kind !== '' && ! in_array( $kind, self::KINDS, true ) ) ) {
			return new \WP_REST_Response( [ 'message' => 'Not a page address, or not a kind of page.' ], 400 );
		}
		self::set_kind( $url, $kind );
		SiteGaps::relations_changed( $url );
		$ids = array_column( self::members( $url ), 'id' );
		if ( $ids ) {
			Scraper::enqueue( $ids, 'scrape', true, 'manual' ); // what each product reads from the page changed
			Scraper::kick();
		}
		return rest_ensure_response( [ 'url' => $url, 'kind' => $kind, 'products' => count( $ids ) ] );
	}

	public static function set_kind( string $url, string $kind ): void {
		self::maybe_install();
		global $wpdb;
		if ( $kind === '' ) {
			$wpdb->delete( self::table(), [ 'url_hash' => md5( $url ) ] );
		} else {
			$wpdb->replace( self::table(), [ 'url_hash' => md5( $url ), 'url' => mb_substr( $url, 0, 500 ), 'kind' => $kind, 'set_by' => get_current_user_id(), 'set_at' => current_time( 'mysql', true ) ] );
		}
		self::$set = null;
		self::forget();
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_page.shared_kind', [ 'object_type' => 'vendor_page', 'object_id' => mb_substr( $url, 0, 100 ), 'details' => [ 'kind' => $kind ] ] );
		}
	}

	/** The kind a person set for the page ('' = none). */
	public static function kind_set( string $url ): string {
		if ( self::$set === null ) {
			self::maybe_install();
			global $wpdb;
			self::$set = [];
			foreach ( $wpdb->get_results( 'SELECT url_hash, kind FROM ' . self::table(), ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
				self::$set[ $x['url_hash'] ] = $x['kind'];
			}
		}
		return self::$set[ md5( $url ) ] ?? '';
	}

	/* ── Who shares a page, and how they relate ─────────────────────────── */

	/**
	 * The published products whose vendor URL is $url, each with its family keys.
	 * @return array<int,array{id:int,title:string,brand:string,name:string,stem:string,pv:string,pp:string,edition:string,version:string}>
	 */
	public static function members( string $url ): array {
		if ( isset( self::$members[ $url ] ) ) {
			return self::$members[ $url ];
		}
		global $wpdb;
		$rows = $url === '' ? [] : $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID id, p.post_title title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'product' AND p.post_status = 'publish'
				 WHERE m.meta_key = 'vendor_product_url' AND m.meta_value = %s LIMIT 300",
				$url
			),
			ARRAY_A
		);
		$skip = array_flip( Exclusions::ids() );
		$rows = array_values( array_filter( $rows, static fn( $x ) => ! isset( $skip[ (int) $x['id'] ] ) ) );
		if ( count( $rows ) < 2 ) {
			return self::$members[ $url ] = array_map( static fn( $x ) => [ 'id' => (int) $x['id'], 'title' => (string) $x['title'] ], $rows );
		}
		$ids = array_map( static fn( $x ) => (int) $x['id'], $rows );
		$in  = implode( ',', $ids );
		$pv  = [];
		$pp  = [];
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mmpv_members' ) ) ) {
			// audit-ok: $in is implode of (int)-cast product ids
			foreach ( $wpdb->get_results( "SELECT product_id, family_id, axes FROM {$wpdb->prefix}mmpv_members WHERE product_id IN ({$in})", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints
				$pv[ (int) $x['product_id'] ] = [ (int) $x['family_id'], (array) json_decode( (string) $x['axes'], true ) ];
			}
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mmpp_offers' ) ) ) {
			// audit-ok: $in is implode of (int)-cast product ids
			foreach ( $wpdb->get_results( "SELECT product_id, family_id, tier, version FROM {$wpdb->prefix}mmpp_offers WHERE product_id IN ({$in})", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints
				$pp[ (int) $x['product_id'] ] = $x;
			}
		}
		$out = [];
		foreach ( $rows as $x ) {
			$id    = (int) $x['id'];
			$title = html_entity_decode( (string) $x['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$terms = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
			$brand = is_array( $terms ) && $terms ? html_entity_decode( (string) $terms[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
			$name  = PageName::of( $title, $brand );
			$name  = $name !== '' ? $name : $title;
			$axes  = $pv[ $id ][1] ?? [];
			$out[] = [
				'id'      => $id,
				'title'   => $title,
				'brand'   => $brand,
				'name'    => $name,
				'stem'    => self::stem( $name, $brand ),
				'pv'      => isset( $pv[ $id ] ) ? (string) $pv[ $id ][0] : '',
				'pp'      => isset( $pp[ $id ] ) ? (string) $pp[ $id ]['family_id'] : '',
				'edition' => strtolower( (string) ( ( $axes['edition'] ?? '' ) ?: ( $pp[ $id ]['tier'] ?? '' ) ?: self::edition_in( $name ) ) ),
				'version' => (string) ( ( $axes['version'] ?? '' ) ?: ( $pp[ $id ]['version'] ?? '' ) ?: self::version_in( $name ) ),
			];
		}
		return self::$members[ $url ] = $out;
	}

	/** The vendor URLs two or more published products share, with how many (one query per request). @return array<string,int> */
	public static function shared_urls(): array {
		if ( self::$shared === null ) {
			global $wpdb;
			self::$shared = [];
			foreach ( $wpdb->get_results( "SELECT m.meta_value url, COUNT(*) n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'product' AND p.post_status = 'publish' WHERE m.meta_key = 'vendor_product_url' AND m.meta_value LIKE 'http%' GROUP BY m.meta_value HAVING COUNT(*) > 1", ARRAY_A ) as $x ) {
				self::$shared[ (string) $x['url'] ] = (int) $x['n'];
			}
		}
		return self::$shared;
	}

	/** A URL change in this request: the counts are read again. */
	public static function forget(): void {
		self::$shared  = null;
		self::$members = [];
	}

	/**
	 * A name without how it is sold, its edition or version: what the product is ("Ozone 11 Advanced Mastering
	 * Software" → "ozone mastering"). A name made only of edition words ("Studio Pro 8") keeps them: they are its name.
	 */
	public static function stem( string $name, string $brand = '' ): string {
		$w = UrlFinder::tokens( $name, $brand !== '' ? UrlFinder::tokens( $brand ) : [] );
		$w = array_filter( $w, static fn( $t ) => strlen( $t ) > 1 && ! ctype_digit( $t ) && ! preg_match( '/^v?\d+(x|\.\d+)*$/', $t ) && ! in_array( $t, self::OFFER_WORDS, true ) );
		$w = array_filter( $w, static fn( $t ) => ! in_array( $t, VendorCatalog::EDITIONS, true ) && ! in_array( $t, self::EDITION_WORDS, true ) ) ?: $w;
		// "Fairfax Vol. 2" is another product than "Fairfax Vol. 1", where "Ozone 12" is a version of Ozone.
		$nums = preg_match_all( '/\b(?:vol(?:ume)?|part|pt|no|series)\.?\s*(\d+)\b|#\s*(\d+)\b/i', $name, $m ) ? array_filter( array_merge( $m[1], $m[2] ) ) : [];
		return trim( implode( ' ', array_values( $w ) ) . ( $nums ? ' #' . implode( '#', $nums ) : '' ) );
	}

	/** "Fairfax Vol. 2" names a different product from "Fairfax Vol. 1": a volume or part number stays part of the name. */
	private static function numbered( string $name ): string {
		return (string) preg_replace( [ '/\b(vol(?:ume)?|part|pt|no|series)\.?\s*(\d+)\b/i', '/#\s*(\d+)\b/' ], [ '$1$2', 'no$1' ], $name );
	}

	private static function edition_in( string $name ): string {
		foreach ( UrlFinder::tokens( $name ) as $t ) {
			if ( in_array( $t, VendorCatalog::EDITIONS, true ) || in_array( $t, self::EDITION_WORDS, true ) ) {
				return $t;
			}
		}
		return '';
	}

	private static function version_in( string $name ): string {
		return preg_match( '/\bv?(\d+(?:\.\d+)?)(?:\.x)?\b/i', $name, $m ) ? $m[1] : '';
	}

	/**
	 * How product $id relates to its page $url: own (nobody else uses it), one, editions or several, with
	 * how many products share the page and whether a person set it.
	 * @return array{kind:string,n:int,set:bool}
	 */
	public static function relation( int $id, string $url ): array {
		if ( ! isset( self::shared_urls()[ $url ] ) ) {
			return [ 'kind' => 'own', 'n' => $url === '' ? 0 : 1, 'set' => false ]; // most pages: one query for all, none per product
		}
		$m = self::members( $url );
		$n = count( $m );
		if ( $n < 2 || ! in_array( $id, array_column( $m, 'id' ), true ) ) {
			return [ 'kind' => 'own', 'n' => $n, 'set' => false ];
		}
		$set = self::kind_set( $url );
		if ( $set !== '' ) {
			return [ 'kind' => $set, 'n' => $n, 'set' => true ];
		}
		return [ 'kind' => self::auto( $url )[ $id ] ?? 'several', 'n' => $n, 'set' => false ];
	}

	/** Every member's kind, decided from the members alone. @return array<int,string> */
	public static function auto( string $url ): array {
		$m = self::members( $url );
		if ( count( $m ) < 2 ) {
			return []; // shared by nobody once left-out products are set aside
		}
		// Families: two products are one family when Product Variants, Product Paths or their stems say so.
		$parent = [];
		$find   = static function ( $i ) use ( &$parent, &$find ) {
			return $parent[ $i ] === $i ? $i : ( $parent[ $i ] = $find( $parent[ $i ] ) );
		};
		$keys = [];
		foreach ( $m as $i => $x ) {
			$parent[ $i ] = $i;
			foreach ( [ 'pv:' . $x['pv'], 'st:' . $x['stem'] ] as $k ) { // not Product Paths' family: too broad (XLN's 18 ADpaks share one)
				if ( preg_match( '/^[a-z]+:$/', $k ) ) {
					continue;
				}
				if ( isset( $keys[ $k ] ) ) {
					$parent[ $find( $i ) ] = $find( $keys[ $k ] );
				} else {
					$keys[ $k ] = $i;
				}
			}
		}
		$fam = [];
		foreach ( $m as $i => $x ) {
			$fam[ $find( $i ) ][] = $i;
		}
		// The page is about the largest family; with no family of two or more, about the product its title names.
		uasort( $fam, static fn( $a, $b ) => count( $b ) <=> count( $a ) );
		$main = (array) reset( $fam );
		if ( count( $main ) < 2 || count( $main ) * self::FAMILY_SHARE < count( $m ) ) { // 2 of 43 aren't what a page is about, unless its title says so (moforte's Windows page)
			$title = self::page_title( $url );
			$main  = [];
			$best  = 0;
			foreach ( $m as $i => $x ) {
				$head = (string) preg_split( '/:\s|\s[-–—|]\s/u', $x['name'], 2 )[0]; // "Sonic Atlas" of "Sonic Atlas - All Access Bundle"
				$s    = $title !== '' ? self::covers( $head, $x['brand'], $title, 300 ) : 0;
				if ( $s > $best ) {
					$best = $s;
					$main = $fam[ $find( $i ) ];
				}
			}
		}
		$same = count( array_unique( array_map( static fn( $i ) => $m[ $i ]['edition'] . '|' . $m[ $i ]['version'], $main ) ) ) <= 1;
		$out  = [];
		foreach ( $m as $i => $x ) {
			// audit-ok: loop is the products sharing one page (dozens); haystack is its main family
			$out[ $x['id'] ] = in_array( $i, $main, true ) ? ( $same ? 'one' : 'editions' ) : 'several';
		}
		return $out;
	}

	private static function page_title( string $url ): string {
		global $wpdb;
		return html_entity_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM ' . PageStore::table() . ' WHERE url_hash = %s', md5( $url ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/* ── What a product reads ───────────────────────────────────────────── */

	/**
	 * The HTML product $id reads from its page: the page, or for a product listed with others only its card
	 * (null when no card on the page names it: it reads nothing rather than everyone's text and pictures).
	 */
	public static function html_for( int $id, string $url, string $html ): ?string {
		if ( $html === '' || self::relation( $id, $url )['kind'] !== 'several' ) {
			return $html;
		}
		$card = self::card( $html, $id, $url );
		return $card ? $card['html'] : null;
	}

	/**
	 * The description a product's page gives it ('' = none): the page's, its card's, or for an edition the
	 * page's family text (when the page is about its version) and its own edition's comparison.
	 */
	public static function description_for( int $id, string $url ): string {
		return ChoiceBundles::described( $id, self::page_description( $id, $url ) ); // a choice bundle: then what can be chosen
	}

	private static function page_description( int $id, string $url ): string {
		$rel = self::relation( $id, $url );
		if ( $rel['kind'] === 'own' || $rel['kind'] === 'one' ) {
			return Coverage::description_for( $url );
		}
		$html = self::stored_html( $url );
		if ( $rel['kind'] === 'several' ) {
			$card = $html !== '' ? self::card( $html, $id, $url ) : null;
			if ( ! $card ) {
				return '';
			}
			$x = VendorPage::extract( $card['html'], $url, html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( (int) $x['text_chars'] >= 80 ) {
				return VendorPage::description_html( $x );
			}
			// A card's text is often one or two lines, shorter than the page reader keeps: its own lines, as they are.
			return $card['lines'] ? '<p>' . implode( '</p><p>', array_map( 'esc_html', $card['lines'] ) ) . '</p>' : '';
		}
		// The family's text only when the page is about this product's version (read once per page and version).
		$version = '';
		foreach ( self::members( $url ) as $x ) {
			if ( $x['id'] === $id ) {
				$version = (string) ( $x['version'] ?? '' );
			}
		}
		$key = $url . '|' . $version;
		if ( ! isset( self::$about[ $key ] ) ) {
			$x                   = $html !== '' ? VendorPage::extract( $html, $url, html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : null;
			self::$about[ $key ] = $x && ! in_array( 'page_version_differs', (array) $x['flags'], true );
		}
		return ( self::$about[ $key ] ? Coverage::description_for( $url ) : '' ) . self::edition_html( $id );
	}

	/** Its own edition's column of the vendor's comparison, when Product Variants holds it ('' otherwise). */
	public static function edition_html( int $id ): string {
		return (string) apply_filters( 'mmi_pipeline_edition_html', '', $id ); // a store's product families (this site: Product Variants)
	}

	private static function stored_html( string $url ): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, html_file FROM ' . PageStore::table() . ' WHERE url_hash = %s', md5( $url ) ), ARRAY_A );
		return $row && $row['status'] === 'ok' ? PageStore::read( (string) $row['html_file'] ) : '';
	}

	/* ── Cards ──────────────────────────────────────────────────────────── */

	/**
	 * Product $id's card on a page listing several: the element that names it (a heading, a name line, a
	 * picture's alt text), grown to the largest block that holds no other card's name. With the card's own
	 * page when it links to one on the same site. null when nothing on the page names the product.
	 * @return array{html:string,name:string,link:string}|null
	 */
	public static function card( string $html, int $id, string $url ): ?array {
		$me = null;
		foreach ( self::members( $url ) as $x ) {
			if ( $x['id'] === $id ) {
				$me = $x;
			}
		}
		$title = $me['title'] ?? html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$brand = $me['brand'] ?? '';
		[ $doc, $xp ] = self::dom( $html );
		if ( ! $doc ) {
			return null;
		}
		$name = (string) ( $me['name'] ?? '' );
		$name = $name !== '' ? $name : PageName::of( $title, $brand, $title );
		$coded = self::coded( $xp, $id );
		if ( $coded ) {
			return self::card_out( $doc, $xp, $coded, $coded, self::card_name( $xp, $coded, $name ), $url, $title, $brand );
		}
		$best = null;
		foreach ( self::name_nodes( $xp ) as $n ) {
			$text = self::text_of( $n );
			$s    = self::covers( $name, $brand, $text );
			$s    = $s ? $s + ( preg_match( '/^h[1-6]$/', $n->nodeName ) ? 2 : 0 ) : 0;
			if ( $s >= self::CARD_MIN && ( ! $best || $s > $best[1] ) ) {
				$best = [ $n, $s, $text ];
			}
		}
		if ( ! $best ) {
			return null;
		}
		[ $node, , $name ] = $best;
		$el   = $node->nodeName === 'img' ? $node : $node;
		$sig  = self::signature( $el );
		$card = $el;
		for ( $p = $el->parentNode; $p && ! in_array( $p->nodeName, [ 'body', 'main', 'html' ], true ); $p = $p->parentNode ) {
			$other = false;
			foreach ( $xp->query( './/' . $el->nodeName, $p ) as $o ) {
				if ( $o !== $el && self::signature( $o ) === $sig && self::text_of( $o ) !== '' && self::text_of( $o ) !== $name ) {
					$other = true; // another card's name: the card ends below here
					break;
				}
			}
			if ( $other ) {
				break;
			}
			$card = $p;
		}
		$text = self::text_of( $card );
		if ( $card === $el || mb_strlen( $text ) < mb_strlen( $name ) + 20 && ! $xp->query( './/img', $card )->length ) {
			return null; // a name with nothing else: no card to read
		}
		return self::card_out( $doc, $xp, $card, $el, $name, $url, $title, $brand );
	}

	/** What a card gives, for card(): its HTML as a page of its own, its lines of text, its link and its id. */
	private static function card_out( \DOMDocument $doc, \DOMXPath $xp, \DOMNode $card, \DOMNode $el, string $name, string $url, string $title, string $brand ): array {
		$lines = [];
		foreach ( $xp->query( './/p|.//li|.//h5|.//h6|.//div[not(*)]', $card ) as $n ) {
			$t = self::text_of( $n );
			if ( $n === $el || $t === $name || mb_strlen( $t ) < 15 || preg_match( '/^[$€£]?\s?\d[\d.,]*\s*(usd|eur|gbp)?$/iu', $t ) || preg_match( '/^(add to cart|buy now|learn more|read more|more info|watch|listen)/i', $t ) ) {
				continue;
			}
			$lines[ mb_strtolower( $t ) ] = $t;
		}
		// The card's name as its page's name, so the reader drops the card's heading that only repeats it ("Artefacts
		// Expansion Pack (for MYTH)" opened Artefacts for MYTH's description, 2026-10-28).
		return [ 'html' => '<!DOCTYPE html><html><head><title>' . esc_html( $name ) . '</title><meta property="og:title" content="' . esc_attr( $name ) . '"></head><body><main ' . VendorPage::CARD_MARK . '>' . $doc->saveHTML( $card ) . '</main></body></html>', 'name' => $name, 'lines' => array_values( $lines ), 'link' => self::own_link( $xp, $card, $url, $title, $brand ), 'id' => $card instanceof \DOMElement ? $card->getAttribute( 'id' ) : '' ];
	}

	/**
	 * The card carrying product $id's own code (UrlFinder::code_of()): its id, or a buy button's product path
	 * (id="product-geoshred-naada-sitar", data-fsc-item-path-value="naada-reeds"), grown to the block with its picture.
	 * The fullest when the page shows a card twice (a Best Value row and the full list). null when the page doesn't carry it.
	 */
	private static function coded( \DOMXPath $xp, int $id ): ?\DOMElement {
		$code = UrlFinder::code_of( $id );
		if ( $code === '' ) {
			return null;
		}
		$best = null;
		$rank = [ -1, -1, -1 ];
		foreach ( $xp->query( sprintf( '//*[@id="product-%1$s" or @*="%1$s"]', $code ) ) as $n ) {
			$box = $n;
			for ( $up = 0; $up < self::CARD_CLIMB && ! $xp->query( './/img', $box )->length && $box->parentNode instanceof \DOMElement && ! in_array( $box->parentNode->nodeName, [ 'body', 'main', 'html' ], true ); $up++ ) {
				$box = $box->parentNode;
			}
			$text = self::text_of( $box );
			if ( ! $box instanceof \DOMElement || mb_strlen( $text ) > self::CARD_MAX ) {
				continue; // grown past a card: a whole section
			}
			$here = [ (int) $xp->query( './/p', $box )->length, mb_strlen( $text ), $box->getAttribute( 'id' ) !== '' ? 1 : 0 ];
			if ( $here > $rank ) {
				[ $best, $rank ] = [ $box, $here ];
			}
		}
		return $best;
	}

	/** Product $id's card on a page it isn't linked to, by its code alone: names are too loose to send a product to another page. @return array{name:string,id:string}|null */
	public static function coded_card( string $html, int $id, string $url ): ?array {
		[ $doc, $xp ] = self::dom( $html );
		$card = $doc ? self::coded( $xp, $id ) : null;
		return $card ? [ 'name' => self::card_name( $xp, $card, '' ), 'id' => $card->getAttribute( 'id' ) ] : null;
	}

	/** The name a coded card shows: its heading or the element its class calls a name or title, else $fallback. */
	private static function card_name( \DOMXPath $xp, \DOMNode $card, string $fallback ): string {
		foreach ( $xp->query( './/h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//*[contains(@class,"name") or contains(@class,"title")]', $card ) as $n ) {
			$t = self::text_of( $n );
			if ( $t !== '' && mb_strlen( $t ) <= 90 ) {
				return $t;
			}
		}
		return $fallback;
	}

	/** Which of $ids a page's #address names, for a panel opened on one product's card ("#product-geoshred-naada-sitar"). 0 when none. @param int[] $ids */
	public static function by_anchor( array $ids, string $anchor ): int {
		$anchor = strtolower( trim( $anchor, "# \t" ) );
		foreach ( $anchor !== '' ? $ids : [] as $id ) {
			$code = UrlFinder::code_of( (int) $id );
			if ( $code !== '' && in_array( $anchor, [ $code, 'product-' . $code ], true ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/** $url with product $id's card's id as its #address, on a page listing several: the browser lands on the card. */
	public static function anchored( int $id, string $url ): string {
		if ( self::relation( $id, $url )['kind'] !== 'several' ) {
			return $url;
		}
		$html = self::stored_html( $url );
		$card = $html !== '' ? self::card( $html, $id, $url ) : null;
		return $card && (string) $card['id'] !== '' ? $url . '#' . $card['id'] : $url;
	}

	/**
	 * The names of the cards on a page that lists several products (for the drawer's page picker: "Sonic
	 * Atlas › Bollywood Gold"), cached per page content. @return array<int,array{name:string,link:string}>
	 */
	public static function cards( string $html, string $url, string $hash = '' ): array {
		$key = 'mmpt_cards_' . md5( $url . '|' . ( $hash !== '' ? $hash : md5( $html ) ) );
		$got = get_transient( $key );
		if ( is_array( $got ) ) {
			return $got;
		}
		[ $doc, $xp ] = self::dom( $html );
		$out = [];
		if ( $doc ) {
			$groups = [];
			foreach ( self::name_nodes( $xp ) as $n ) {
				if ( $n->nodeName === 'img' ) {
					continue;
				}
				$groups[ self::signature( $n ) ][] = $n;
			}
			foreach ( $groups as $list ) {
				if ( count( $list ) < 3 ) {
					continue; // a card is one of a row of like blocks
				}
				$with  = 0;
				$names = [];
				foreach ( $list as $n ) {
					$box  = $n->parentNode && $n->parentNode->parentNode ? $n->parentNode->parentNode : $n->parentNode;
					$with += $box && $xp->query( './/img', $box )->length ? 1 : 0;
					$names[ mb_strtolower( self::text_of( $n ) ) ] = true;
				}
				if ( $with < count( $list ) / 2 || count( $names ) < 0.7 * count( $list ) ) {
					continue; // headings without pictures (a menu, a feature list), or one label repeated ("Close")
				}
				foreach ( $list as $n ) {
					$name = self::text_of( $n );
					if ( mb_strlen( $name ) >= 3 && mb_strlen( $name ) <= 70 && ! preg_match( '/^[\d,.\s%$€£]+$/u', $name ) ) {
						$out[ mb_strtolower( $name ) ] = [ 'name' => $name, 'link' => '' ];
					}
				}
			}
		}
		$out = array_slice( array_values( $out ), 0, 300 );
		set_transient( $key, $out, self::CARDS_TTL );
		return $out;
	}

	/**
	 * How tightly $text names product $name (0–100): 0 unless every word of the product's own name is in it
	 * (brand, offer and platform words aside), then the share of $text's words that are the product's. "Arachnid
	 * for BioTek" in "Arachnid Expansion Pack (for BioTek)" scores 50; in "Signal Interrupted…" 0.
	 */
	public static function covers( string $name, string $brand, string $text, int $max = 90 ): int {
		if ( $text === '' || mb_strlen( $text ) > $max ) {
			return 0;
		}
		$drop   = $brand !== '' ? UrlFinder::tokens( $brand ) : [];
		$ours   = array_values( array_unique( array_filter( UrlFinder::tokens( self::numbered( $name ), $drop ), static fn( $w ) => ! in_array( $w, self::OFFER_WORDS, true ) ) ) );
		$kinds  = array_values( array_intersect( $ours, self::KIND_WORDS ) );
		$ours   = array_values( array_diff( $ours, self::KIND_WORDS ) ) ?: $kinds; // a card may leave out what kind of product it is
		$theirs = array_values( array_unique( array_filter( UrlFinder::tokens( self::numbered( $text ), $drop ), static fn( $w ) => ! in_array( $w, [ 'and', 'the', 'for', 'with', 'of', 'to' ], true ) ) ) );
		if ( ! $ours || ! $theirs || array_diff( $ours, $theirs ) || ! array_filter( $ours, static fn( $w ) => ! ctype_digit( $w ) ) ) {
			return 0;
		}
		// Roman numbers in our name are its whole list: "Collection III & IV" isn't "Collection I, II, III & IV" (2026-11-05: the
		// shorter wrong name scored higher, and moforte's III & IV bundle read the I to IV bundle's card).
		$roman = static fn( array $w ) => array_filter( $w, static fn( $x ) => (bool) preg_match( '/^(?:i{1,3}|iv|vi{0,3}|ix)$/', (string) $x ) );
		if ( $roman( $ours ) && array_diff( $roman( $theirs ), $roman( $ours ) ) ) {
			return 0;
		}
		return (int) round( 100 * count( $ours ) / max( count( $ours ), count( $theirs ) ) );
	}

	/** Elements that can carry a product's name: short headings, name lines, linked names, pictures' alt text. */
	private static function name_nodes( \DOMXPath $xp ): array {
		$out = [];
		foreach ( $xp->query( '//h1|//h2|//h3|//h4|//h5|//h6|//p|//span|//strong|//b|//a|//figcaption|//div[not(*)]|//img[@alt]' ) as $n ) {
			$t = self::text_of( $n );
			if ( $t !== '' && mb_strlen( $t ) <= 90 ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	private static function text_of( \DOMNode $n ): string {
		$t = $n instanceof \DOMElement && $n->nodeName === 'img' ? $n->getAttribute( 'alt' ) : $n->textContent;
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** Elements in the same place of like cards: same tag and class. */
	private static function signature( \DOMNode $n ): string {
		return $n->nodeName . '.' . ( $n instanceof \DOMElement ? preg_replace( '/\s+/', ' ', trim( $n->getAttribute( 'class' ) ) ) : '' );
	}

	/** A link in the card to another page of the same site that names the product (its own page). */
	private static function own_link( \DOMXPath $xp, \DOMNode $card, string $url, string $title, string $brand ): string {
		$host = BrandSites::host( $url );
		foreach ( $xp->query( './/a[@href]', $card ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( $href === '' || $href[0] === '#' || preg_match( '#^(javascript|mailto|tel):#i', $href ) ) {
				continue;
			}
			$abs = (string) \WP_Http::make_absolute_url( $href, $url );
			if ( BrandSites::host( $abs ) !== $host || UrlFinder::same_url( $abs, $url ) || ! UrlFinder::looks_like_product_page( $abs ) ) {
				continue;
			}
			$slug = str_replace( [ '-', '_' ], ' ', basename( (string) wp_parse_url( $abs, PHP_URL_PATH ) ) );
			$s    = VendorCatalog::score( $title, $brand, $slug ) ?? VendorCatalog::score( $title, $brand, self::text_of( $a ) );
			if ( $s && $s['score'] >= 70 ) {
				return (string) strtok( $abs, '#' );
			}
		}
		return '';
	}

	/** @return array{0:?\DOMDocument,1:?\DOMXPath} the page without scripts, styles, menus, header and footer */
	private static function dom( string $html ): array {
		if ( $html === '' ) {
			return [ null, null ];
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadHTML( '<?xml encoding="utf-8"?>' . VendorPage::without_scripts( $html ), LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR ); // a script's HTML strings stay out
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return [ null, null ];
		}
		$xp = new \DOMXPath( $doc );
		foreach ( iterator_to_array( $xp->query( '//script|//style|//noscript|//template|//svg|//nav|//header|//footer|//*[@aria-hidden="true"]' ) ) as $n ) {
			if ( $n->parentNode ) {
				$n->parentNode->removeChild( $n );
			}
		}
		return [ $doc, $xp ];
	}
}
