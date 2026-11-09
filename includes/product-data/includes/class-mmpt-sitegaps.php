<?php
/**
 * A vendor site's gaps (owner 2026-10-18: compare each product's data with its maker's page, see what's
 * covered and what isn't, and fill the gaps in one click): for every product of the site and every kind of
 * content, what the product has now and what filling would do with its page, decided by the writers' own
 * rules, so the screen can't promise a write that doesn't happen.
 *
 * Two halves:
 * - read() (the maps runner, inside VendorMaps::refresh()): each readable page of the site read once with the
 *   map as it is in the editor, stored per page in wp_mmpt_site_pages with the stamp it was read under (the
 *   map's parts, the site's skip lists, the reader's version) and the page's content hash. A page listing
 *   several products is read per product, from its own card (SharedPages). The pages are then grouped into
 *   layouts by the structure of their main content.
 * - matrix() (a web request): the site's products (VendorMaps::site_products()) joined with those readings
 *   and each product's current state (Coverage::products()). A reading made under another stamp, or of an
 *   older copy of the page, is never shown: its cells say "being checked" and the runner is asked to read it.
 *
 * Cell codes: H has it · K has some (only empty fields are filled) · A filling writes it · Q pictures a fill queued (the
 * picture runner writes them; not offered again) · P the page has it
 * but only a mapped part writes it · S the page shows it (feature cards, a video) but nothing reads it: seen(), with
 * a selector the part's reader reads it from · R a proposal for review · G gap left (the page doesn't have it; its
 * text says exactly what's there) · N the page has it, nothing stores it yet · L locked · C being checked · X page
 * blocked · U not read · B page broken · - doesn't apply, or a person said the page has none (decide_none()).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SiteGaps {

	const DB_OPTION  = 'mmpt_site_pages_db_version';
	const DB_VERSION = '1';
	const VERSION    = 3;    // the reading's shape: a change re-reads every site (2: seen(), and the Features reader's cards. 3: Features and Version have no map part, VendorMaps::NOT_MAPPED)
	const BUDGET_S   = 600.0; // seconds one read() may spend (the runner)
	const LAYOUT_SIM = 0.8;  // pages whose main content shares this much structure are one layout
	const SKEL_DEPTH = 5;
	const SKEL_MAX   = 400;  // structure tokens kept per page
	const SAMPLES    = 3;    // sample pages per layout
	const MIN_DESCRIPTION = 300; // page copy that counts as a description (Coverage::MIN_PAGE_DESCRIPTION)

	/** Coverage attribute → the map part that reads it ('' = none: features and version, VendorMaps::NOT_MAPPED). Order = column order. */
	const FIELDS = [ 'images' => 'pictures', 'description' => 'description', 'features' => '', 'os' => 'requirements', 'version' => '', 'manual' => 'manual', 'videos' => 'videos', 'audio' => '', 'size' => '' ];
	/** Each field as a sentence names it (decide_none()). */
	const LABELS = [ 'images' => 'pictures', 'description' => 'description', 'features' => 'features', 'os' => 'system requirements', 'version' => 'version', 'manual' => 'manual', 'videos' => 'videos', 'audio' => 'audio demos', 'size' => 'weight and size' ];
	/** Parts only a mapped part writes (VendorMaps::fill_product()), with their registry concepts. */
	const MAPPED_ONLY = [ 'manual' => 'manuals', 'videos' => 'videos' ];
	/** Page statuses: the site refuses this server (send the page from a browser) / the page is gone or moved (a URL decision). Anything else not ok is read again. */
	const BLOCKED = [ 'http_202', 'http_401', 'http_403', 'http_429', 'robots_disallow' ];
	const BROKEN  = [ 'http_404', 'http_410', 'moved', 'moved_offsite', 'redirect_loop' ];
	/** Class tokens that don't describe structure: generated names, states, numbers. */
	const NOISE_CLASS = '/(^css-|^_|[0-9a-f]{5,}|\d{2,}|^jsx-|^sc-|^is-|^has-|^js-|^active|^show|^open|^hidden|^visible|^lazy|^loaded|^aos)/i';
	const SKEL_SKIP   = [ 'script', 'style', 'noscript', 'svg', 'br', 'img', 'a', 'span', 'i', 'b', 'em', 'strong', 'p', 'li', 'option', 'source', 'picture', 'path', 'button', 'input', 'label', 'iframe' ];

	/** Cells with nothing written and nothing to write: a gap (G) or something nothing reads (S). */
	const EMPTY = [ 'G', 'S' ];
	/** Post meta: the fields a person said the product's page has none of: field → [ url, at, by ] (decide_none()). */
	const NONE_META = '_mmpt_page_none';
	/** What a block of features is called in a class, an id or a heading (seen()). */
	const FEATURE_CLASS = '/^(?:[a-z0-9]+[-_])?(?:key[-_]?)?(?:features?|highlights?)(?:[-_][a-z0-9]+)*$/i';
	const FEATURE_HEAD  = '/\bfeatures\b|\bhighlights\b/i'; // plural: "Full-feature trial" isn't a block of features
	/** Where a page keeps videos the Videos part reads, tried in order (seen()). */
	const VIDEO_SELECTORS = [ 'iframe[src*="youtube"]', 'iframe[data-src*="youtube"]', 'iframe[src*="vimeo"]', 'iframe[data-src*="vimeo"]', '[data-video-id]', '[data-youtube-id]', '[data-yt-id]', 'a[href*="youtube.com/watch"]', 'a[href*="youtu.be/"]', 'a[href*="vimeo.com/"]' ];
	/** A link to a product's requirements kept on another page (seen(): the gap's text names it). */
	const REQ_LINK = '/system\s*requirements|tech(?:nical)?\s*spec/i';
	/** A gap's words when nothing on the page is like it (cell()). */
	const NOTHING = [ 'features' => 'No feature list, feature cards or features heading found on the page', 'manual' => 'No manual link found on the page', 'videos' => 'No video found on the page', 'os' => 'No Mac or Windows requirements found on the page', 'version' => 'No version number found on the page', 'audio' => 'No audio demo found on the page', 'size' => 'No weight or size found on the page' ];

	/** Products whose page requirements a person decided on (FillRun::kept_requirements()), for cell(). */
	private static $req_decided = [];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_site_pages';
	}

	/** Product $id's stored reading on its site (its own card on a shared page first), or null. */
	public static function reading_for( string $host, int $id ): ?array {
		global $wpdb;
		$url = (string) ( array_column( VendorMaps::site_products( $host ), 'url', 'id' )[ $id ] ?? '' );
		if ( $url === '' ) {
			return null;
		}
		$r = $wpdb->get_var( $wpdb->prepare( 'SELECT reading FROM ' . self::table() . ' WHERE page_key IN (%s, %s) ORDER BY product_id DESC LIMIT 1', self::key( $url, $id ), self::key( $url ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $r ? (array) json_decode( (string) $r, true ) : null;
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  page_key char(32) NOT NULL,
  host varchar(190) NOT NULL,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  url varchar(500) NOT NULL DEFAULT '',
  content_hash char(32) NOT NULL DEFAULT '',
  stamp varchar(16) NOT NULL DEFAULT '',
  layout smallint(6) NOT NULL DEFAULT -1,
  sig longtext,
  reading longtext,
  read_at datetime DEFAULT NULL,
  PRIMARY KEY  (page_key),
  KEY host (host)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/** A page read whole, or one product's card on a page that lists several. */
	private static function key( string $url, int $card_of = 0 ): string {
		return md5( $card_of ? $url . '#' . $card_of : $url );
	}

	/** What a reading depends on besides the page: the map as it is in the editor, the site's skip lists, the readers' versions. */
	public static function stamp( string $host ): string {
		$host = VendorMaps::host( $host );
		$map  = VendorMaps::with_editor_map( $host, static fn() => VendorMaps::stamp( $host ) );
		return substr( md5( implode( '|', [ $map, MapCheck::text_stamp( $host ), MapCheck::skip_stamp( $host ), Coverage::OFFERS_VERSION, self::VERSION ] ) ), 0, 12 );
	}

	/** Where a product's page stands: read / blocked / broken / unread. */
	public static function page_state( array $p ): string {
		$st = (string) $p['st'];
		if ( $st === 'ok' && (string) $p['file'] !== '' ) {
			return 'read';
		}
		if ( in_array( $st, self::BLOCKED, true ) ) {
			return 'blocked';
		}
		return in_array( $st, self::BROKEN, true ) ? 'broken' : 'unread';
	}

	/* ── Reading (the runner) ──────────────────────────────────────────── */

	/**
	 * Read every page of the site that has no current reading, then group the pages into layouts and keep the
	 * site's summary for the list. Pages read before the budget runs out stay; the next refresh reads the rest.
	 * @return array{host:string,read:int,pending:int,layouts:int}
	 */
	public static function read( string $host, float $budget = self::BUDGET_S ): array {
		self::maybe_install();
		global $wpdb;
		$host     = VendorMaps::host( $host );
		$start    = microtime( true );
		$stamp    = self::stamp( $host );
		$products = VendorMaps::site_products( $host );
		$rel      = self::relations( $products );
		$units    = self::units( $products, $rel );
		$have     = [];
		if ( $units ) {
			$in = "'" . implode( "','", array_keys( $units ) ) . "'";
			foreach ( $wpdb->get_results( 'SELECT page_key, content_hash, stamp FROM ' . self::table() . " WHERE page_key IN ({$in})", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- md5 hex only
				$have[ $r['page_key'] ] = $r['stamp'] . '|' . $r['content_hash'];
			}
		}
		$read    = 0;
		$pending = 0;
		foreach ( $units as $key => $u ) {
			if ( ( $have[ $key ] ?? '' ) === $stamp . '|' . $u['hash'] ) {
				continue;
			}
			if ( microtime( true ) - $start > $budget || PageStore::server_busy() ) {
				$pending++;
				continue;
			}
			$reading = self::read_unit( $host, $u );
			$wpdb->replace(
				self::table(),
				[
					'page_key'     => $key,
					'host'         => $host,
					'product_id'   => $u['card_of'],
					'url'          => mb_substr( $u['url'], 0, 500 ),
					'content_hash' => $u['hash'],
					'stamp'        => $stamp,
					'layout'       => -1,
					'sig'          => wp_json_encode( $reading['sig'] ),
					'reading'      => wp_json_encode( array_diff_key( $reading, [ 'sig' => 1 ] ) ),
					'read_at'      => current_time( 'mysql', true ),
				]
			);
			$read++;
		}
		// Pages the site's products no longer use.
		$keys = $units ? "'" . implode( "','", array_keys( $units ) ) . "'" : "''";
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE host = %s AND page_key NOT IN ({$keys})", $host ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- md5 hex only
		$layouts = self::cluster( $host );
		$summary = $pending ? null : self::summary( self::matrix( $host, false ) );
		$gaps    = [ 'stamp' => $stamp, 'at' => current_time( 'mysql', true ), 'layouts' => $layouts, 'rel' => $rel ] + ( $summary ? [ 'summary' => $summary ] : [ 'summary' => ( VendorMaps::get( $host )['gaps']['summary'] ?? null ) ] );
		VendorMaps::update( $host, [ 'gaps' => wp_json_encode( $gaps ) ] );
		Module::info( 'Vendor site gaps read', [ 'host' => $host, 'read' => $read, 'pending' => $pending, 'layouts' => count( $layouts ), 'ms' => (int) round( ( microtime( true ) - $start ) * 1000 ) ] );
		return [ 'host' => $host, 'read' => $read, 'pending' => $pending, 'layouts' => count( $layouts ) ];
	}

	/**
	 * A person said what a shared page is (SharedPages::post()): its products' relations change at once, so the
	 * gaps stop showing them as "not the product's own" before the next refresh, and the readings now needed are queued.
	 */
	public static function relations_changed( string $url ): void {
		$host = VendorMaps::host( $url );
		$row  = $host !== '' ? VendorMaps::get( $host ) : null;
		if ( ! $row || empty( $row['gaps'] ) ) {
			return;
		}
		$gaps = (array) $row['gaps'];
		$rel  = (array) ( $gaps['rel'] ?? [] );
		foreach ( SharedPages::members( $url ) as $m ) {
			$kind = (string) SharedPages::relation( (int) $m['id'], $url )['kind'];
			if ( $kind === 'own' ) {
				unset( $rel[ (int) $m['id'] ] );
			} else {
				$rel[ (int) $m['id'] ] = $kind;
			}
		}
		$gaps['rel'] = $rel;
		VendorMaps::update( $host, [ 'gaps' => wp_json_encode( $gaps ) ] );
		VendorMaps::queue( 'refresh', $host );
	}

	/** How each product on a page several products share is read: id → one / editions / several (SharedPages; only shared pages are asked). */
	private static function relations( array $products ): array {
		$by = [];
		foreach ( $products as $p ) {
			$by[ $p['url'] ][] = (int) $p['id'];
		}
		$out = [];
		foreach ( $products as $p ) {
			if ( count( $by[ $p['url'] ] ) > 1 && self::page_state( $p ) === 'read' ) {
				$kind = (string) SharedPages::relation( (int) $p['id'], (string) $p['url'] )['kind'];
				if ( $kind !== 'own' ) {
					$out[ (int) $p['id'] ] = $kind;
				}
			}
		}
		return $out;
	}

	/** The readings a site needs: one per readable page, plus one per product read from its own card. @return array<string,array> key → unit */
	private static function units( array $products, array $rel ): array {
		$out = [];
		foreach ( $products as $p ) {
			if ( self::page_state( $p ) !== 'read' ) {
				continue;
			}
			$url  = (string) $p['url'];
			$page = self::key( $url );
			if ( ! isset( $out[ $page ] ) ) {
				$out[ $page ] = [ 'url' => $url, 'file' => (string) $p['file'], 'hash' => (string) $p['hash'], 'title' => (string) $p['title'], 'card_of' => 0 ];
			}
			if ( ( $rel[ (int) $p['id'] ] ?? '' ) === 'several' ) {
				$out[ self::key( $url, (int) $p['id'] ) ] = [ 'url' => $url, 'file' => (string) $p['file'], 'hash' => (string) $p['hash'], 'title' => (string) $p['title'], 'card_of' => (int) $p['id'] ];
			}
		}
		return $out;
	}

	/**
	 * One page (or card) read with the editor's map: what the generic reader finds, what the reader finds with
	 * the map, the mapped parts' values a fill would write (counted), the requirements a fill would plan with,
	 * which picks match, and the page's structure.
	 */
	private static function read_unit( string $host, array $u ): array {
		$html = PageStore::read( $u['file'] );
		if ( $u['card_of'] ) {
			$card = $html === '' ? null : ProductPage::own_html( $u['card_of'], $u['url'], $html );
			if ( $card === null ) {
				return [ 'no_card' => 1, 'sig' => [] ];
			}
			$html = $card;
		}
		if ( $html === '' ) {
			return [ 'empty' => 1, 'sig' => [] ];
		}
		VendorMaps::$bypass = true;
		try {
			$gen = Coverage::read_html( $u['url'], $html, $u['title'] );
		} finally {
			VendorMaps::$bypass = false;
		}
		return VendorMaps::with_editor_map(
			$host,
			static function () use ( $u, $html, $gen ) {
				$fields = VendorMaps::fields_for( $u['url'], $html ); // the site's parts, or those of the layout type that reads this page (MapTypes)
				$with   = Coverage::read_html( $u['url'], $html, $u['title'] );
				$extras = VendorMaps::extras( $u['url'], $html );
				$req    = class_exists( PageRequirements::class ) ? PageRequirements::extract( VendorMaps::requirements_html( $u['url'], $html ) ) : null;
				$doc    = VendorPage::parse( $html );
				$picks  = [];
				foreach ( $fields as $k => $f ) {
					if ( empty( $f['sel'] ) || ! empty( $f['health']['broken'] ) || in_array( (string) ( $f['mode'] ?? '' ), VendorMaps::ELSEWHERE, true ) || ! $doc ) { // a part read from another page has no pick on this one
						continue;
					}
					foreach ( array_values( array_filter( array_map( 'trim', explode( ',', (string) $f['sel'] ) ) ) ) as $i => $sel ) {
						$picks[ $k ][ $i ] = (bool) VendorMaps::eval_dom( $k, $sel, (string) ( $f['mode'] ?? '' ), $doc, $u['url'] )['found'];
					}
				}
				return [
					'gen'    => array_diff_key( Coverage::decode( (string) wp_json_encode( $gen ) ), [ '_flags' => 1 ] ),
					'with'   => array_diff_key( Coverage::decode( (string) wp_json_encode( $with ) ), [ '_flags' => 1 ] ),
					'ex'     => array_map( static fn( $v ) => is_array( $v ) ? count( $v ) : ( (string) $v !== '' ? 1 : 0 ), array_intersect_key( $extras, self::MAPPED_ONLY ) ),
					'req'    => $req ?: null,
					'picks'  => $picks,
					'seen'   => $doc ? self::seen( $doc, $u['url'] ) : [],
					'shape'  => (string) preg_replace( '~/[^/]+/?$~', '/*', (string) wp_parse_url( $u['url'], PHP_URL_PATH ) ) ?: '/',
					'sig'    => $u['card_of'] || ! $doc ? [] : self::skeleton( $doc ),
				];
			}
		);
	}

	/**
	 * What a page shows for a part that nothing may read (owner 2026-10-31: a gap whose page has six feature cards
	 * said "Not on the page"): feature lists or cards in a block named for features, and videos, each with a selector
	 * the part's own reader reads them from (VendorMaps::read_with()), so the cell can say what is there and the extension
	 * can offer that selector; a features heading with nothing readable under it; a link to requirements kept elsewhere.
	 * @return array<string,array> field → { n, kind (cards | items | videos), under (a heading), sel } | { head } | { link, href }
	 */
	private static function seen( \DOMDocument $doc, string $url ): array {
		$x    = new \DOMXPath( $doc );
		$skip = 'ancestor-or-self::nav or ancestor-or-self::header or ancestor-or-self::footer';
		$out  = [];
		$sels = [];
		$inline = 'self::span or self::a or self::strong or self::em or self::b or self::i or self::img or self::button or self::label or self::svg';
		foreach ( $x->query( "//*[(@class or @id) and not({$inline}) and not({$skip})]" ) as $el ) {
			$id = (string) $el->getAttribute( 'id' );
			if ( $id !== '' && preg_match( self::FEATURE_CLASS, $id ) && preg_match( '/^[A-Za-z][A-Za-z0-9_-]{1,40}$/', $id ) ) {
				$sels[ '#' . $id ] = true;
			}
			foreach ( preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ) ) as $t ) {
				if ( $t !== '' && preg_match( self::FEATURE_CLASS, $t ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $t ) ) {
					$sels[ $el->nodeName . '.' . $t ] = true;
				}
			}
			if ( count( $sels ) >= 12 ) {
				break;
			}
		}
		$best = null;
		foreach ( array_keys( $sels ) as $sel ) {
			$items = (array) VendorMaps::read_with( 'features', $sel, $doc, $url );
			if ( count( $items ) >= 3 && ( ! $best || count( $items ) > $best['n'] ) ) {
				$best = [ 'n' => count( $items ), 'sel' => $sel, 'first' => (string) $items[0] ];
			}
		}
		if ( $best ) {
			[ $kind, $under ] = self::seen_where( $x, $best['sel'], $best['first'] );
			$out['features']  = [ 'n' => $best['n'], 'kind' => $kind, 'under' => $under, 'sel' => $best['sel'] ];
		} else {
			foreach ( $x->query( "//*[self::h1 or self::h2 or self::h3 or self::h4][not({$skip})]" ) as $hd ) {
				$t = trim( (string) preg_replace( '/\s+/u', ' ', $hd->textContent ) );
				if ( $t !== '' && mb_strlen( $t ) <= 80 && str_word_count( $t ) <= 8 && preg_match( self::FEATURE_HEAD, $t ) ) {
					$out['features'] = [ 'head' => $t ];
					break;
				}
			}
		}
		foreach ( self::VIDEO_SELECTORS as $sel ) {
			$v = (array) VendorMaps::read_with( 'videos', $sel, $doc, $url );
			if ( $v ) {
				$out['videos'] = [ 'n' => count( $v ), 'kind' => 'videos', 'under' => '', 'sel' => $sel ];
				break;
			}
		}
		// Requirements in lists the requirements reader doesn't take (acondigital's tabs): a selector a Requirements part reads them with
		foreach ( $x->query( "(//ul | //ol)[not({$skip})]" ) as $i => $list ) {
			if ( $i >= 40 ) {
				break;
			}
			if ( ! VendorPage::looks_like_requirements( (string) $list->textContent ) ) {
				continue;
			}
			$sel = self::list_selector( $list );
			$got = $sel !== '' ? (array) VendorMaps::read_with( 'requirements', $sel, $doc, $url ) : [];
			if ( $got ) {
				$h         = $x->query( 'preceding::*[self::h1 or self::h2 or self::h3 or self::h4][1]', $list )->item( 0 );
				$out['os'] = [ 'n' => count( $got ), 'kind' => 'requirements', 'platforms' => $got, 'under' => $h ? mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $h->textContent ) ), 0, 80 ) : '', 'sel' => $sel ];
				break;
			}
		}
		foreach ( empty( $out['os'] ) ? $x->query( "//a[@href][not({$skip})]" ) : [] as $a ) {
			$t    = trim( (string) preg_replace( '/\s+/u', ' ', $a->textContent ) );
			$href = VendorPage::absolute_url( html_entity_decode( trim( (string) $a->getAttribute( 'href' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url );
			if ( $t !== '' && mb_strlen( $t ) <= 60 && preg_match( self::REQ_LINK, $t ) && preg_match( '#^https?://#i', $href ) && strtok( $href, '#' ) !== strtok( $url, '#' ) ) {
				$out['os'] = [ 'link' => $t, 'href' => (string) wp_parse_url( $href, PHP_URL_PATH ) ];
				break;
			}
		}
		return $out;
	}

	/** A selector for a list: its own first class, or the classes of its two nearest classed ancestors above its tag ("div.tab div.rich-text ul"). '' when nothing names it. */
	private static function list_selector( \DOMElement $list ): string {
		$first = static function ( \DOMElement $el ): string {
			foreach ( preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ) ) as $t ) {
				if ( $t !== '' && ! preg_match( self::NOISE_CLASS, $t ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $t ) ) {
					return $t;
				}
			}
			return '';
		};
		$own = $first( $list );
		if ( $own !== '' ) {
			return $list->nodeName . '.' . $own;
		}
		$up = [];
		for ( $n = $list->parentNode, $depth = 0; $n instanceof \DOMElement && $depth < 6 && count( $up ) < 2 && ! in_array( $n->nodeName, [ 'body', 'html' ], true ); $n = $n->parentNode, $depth++ ) {
			$c = $first( $n );
			if ( $c !== '' ) {
				array_unshift( $up, $n->nodeName . '.' . $c );
			}
		}
		return $up ? implode( ' ', $up ) . ' ' . $list->nodeName : '';
	}

	/** Where the first item seen() read sits: in a card or a list, under the nearest heading before it. @return array{0:string,1:string} */
	private static function seen_where( \DOMXPath $x, string $sel, string $first ): array {
		$xp = VendorMaps::css_to_xpath( $sel );
		if ( is_wp_error( $xp ) ) {
			return [ 'items', '' ];
		}
		$clean = static fn( string $t ) => trim( (string) preg_replace( '/\s+/u', ' ', $t ) );
		$at    = null;
		$kind  = 'items';
		foreach ( $x->query( $xp ) as $i => $el ) {
			if ( $i >= 20 || ! $el instanceof \DOMElement ) {
				break;
			}
			foreach ( $el->nodeName === 'li' ? [ $el ] : $x->query( './/li', $el ) as $li ) {
				if ( $clean( $li->textContent ) === $first ) {
					$at = $li;
					break 2;
				}
			}
			foreach ( array_merge( [ $el ], VendorMaps::card_children( $el ) ) as $c ) {
				if ( VendorMaps::card_text( $c ) === $first ) {
					$at   = $c;
					$kind = 'cards';
					break 2;
				}
			}
		}
		$h = $at ? $x->query( 'preceding::*[self::h1 or self::h2 or self::h3 or self::h4][1]', $at )->item( 0 ) : null;
		return [ $kind, $h ? mb_substr( $clean( $h->textContent ), 0, 80 ) : '' ];
	}

	/** The structure of a page's main content: tag and first stable class of each container, down to SKEL_DEPTH, hashed (a layout type's rule compares pages by it, MapTypes). @return string[] */
	public static function skeleton( \DOMDocument $doc ): array {
		$x    = new \DOMXPath( $doc );
		$root = $x->query( '//main | //*[@role="main"] | //*[@id="content" or @id="main" or @id="main-content"]' )->item( 0 ) ?: $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $root ) {
			return [];
		}
		$set  = [];
		$walk = static function ( \DOMNode $n, int $depth, string $path ) use ( &$walk, &$set ) {
			if ( $depth > self::SKEL_DEPTH || count( $set ) >= self::SKEL_MAX ) {
				return;
			}
			foreach ( $n->childNodes as $c ) {
				if ( ! $c instanceof \DOMElement || in_array( $c->tagName, self::SKEL_SKIP, true ) ) {
					continue;
				}
				$cls = '';
				foreach ( preg_split( '/\s+/', trim( (string) $c->getAttribute( 'class' ) ) ) as $t ) {
					if ( $t !== '' && ! preg_match( self::NOISE_CLASS, $t ) ) {
						$cls = $t;
						break;
					}
				}
				$tok                          = $path . '>' . $c->tagName . ( $cls !== '' ? '.' . $cls : '' );
				$set[ hash( 'crc32b', $tok ) ] = true;
				$walk( $c, $depth + 1, $tok );
			}
		};
		$walk( $root, 1, '' );
		return array_keys( $set );
	}

	/**
	 * Group the site's pages into layouts (structure alike by LAYOUT_SIM or more, against each group's first page),
	 * largest first, and store each page's layout. @return array<int,array{pages:int,shapes:array<string,int>}>
	 */
	private static function cluster( string $host ): array {
		global $wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT page_key, url, sig, reading FROM ' . self::table() . ' WHERE host = %s AND product_id = 0 ORDER BY url', $host ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$groups = [];
		foreach ( $rows as $r ) {
			$set = array_flip( (array) json_decode( (string) $r['sig'], true ) );
			$hit = null;
			foreach ( $groups as $i => $g ) {
				$union = count( $set + $g['set'] );
				if ( $union && count( array_intersect_key( $set, $g['set'] ) ) / $union >= self::LAYOUT_SIM ) {
					$hit = $i;
					break;
				}
			}
			$shape = (string) ( json_decode( (string) $r['reading'], true )['shape'] ?? '' );
			if ( $hit === null ) {
				$groups[] = [ 'set' => $set, 'keys' => [ $r['page_key'] ], 'urls' => [ $r['url'] ], 'shapes' => [ $shape => 1 ] ];
			} else {
				$groups[ $hit ]['keys'][]            = $r['page_key'];
				$groups[ $hit ]['urls'][]            = $r['url'];
				$groups[ $hit ]['shapes'][ $shape ] = ( $groups[ $hit ]['shapes'][ $shape ] ?? 0 ) + 1;
			}
		}
		usort( $groups, static fn( $a, $b ) => count( $b['keys'] ) <=> count( $a['keys'] ) );
		$out = [];
		foreach ( $groups as $i => $g ) {
			$in = "'" . implode( "','", $g['keys'] ) . "'";
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET layout = %d WHERE page_key IN ({$in})", $i ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- md5 hex only
			$cards = "'" . implode( "','", array_map( 'esc_sql', $g['urls'] ) ) . "'";
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET layout = %d WHERE host = %s AND product_id > 0 AND url IN ({$cards})", $i, $host ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- esc_sql on each URL
			arsort( $g['shapes'] );
			$out[] = [ 'pages' => count( $g['keys'] ), 'shapes' => $g['shapes'] ];
		}
		return $out;
	}

	/* ── The matrix (a web request) ────────────────────────────────────── */

	/**
	 * Every product of the site with a cell per kind of content, the column totals, the layouts with what each
	 * part finds on them, and whether filling may run now. $queue: ask the runner to read pages without a current
	 * reading (a request does; the runner's own summary doesn't).
	 * @return array<string,mixed>|null null without a map row
	 */
	public static function matrix( string $host, bool $queue = true ): ?array {
		self::maybe_install();
		$host = VendorMaps::host( $host );
		$row  = VendorMaps::get( $host );
		if ( ! $row ) {
			return null;
		}
		$start = microtime( true );
		$s     = self::site_rows( $host, $row, VendorMaps::site_products( $host ) );
		$rows  = $s['rows'];
		$pend  = $s['pending'];
		if ( $pend && $queue ) {
			VendorMaps::queue( 'refresh', $host ); // read now; the screen asks again while pages are being checked
		}
		$totals = array_fill_keys( array_keys( self::FIELDS ), [] );
		foreach ( $rows as $r ) {
			foreach ( $r['cells'] as $f => $c ) {
				$totals[ $f ][ $c[0] ] = ( $totals[ $f ][ $c[0] ] ?? 0 ) + 1;
			}
		}
		$out = [
			'host'     => $host,
			'status'   => VendorMaps::status_of( $row ),
			'products' => count( $rows ),
			'no_card'  => count( array_filter( array_column( $rows, 'no_card' ) ) ),
			'ready'    => $s['ready'],
			'pending'  => $pend,
			'rows'     => $rows,
			'totals'   => $totals,
			'layouts'  => self::layouts( $row, $s['units'], $s['stamp'] ),
			'parts'    => array_map( static fn( $f ) => [ 'sel' => (string) ( $f['sel'] ?? '' ), 'mode' => (string) ( $f['mode'] ?? '' ), 'broken' => ! empty( $f['health']['broken'] ) ], array_filter( (array) $row['fields'], static fn( $f ) => ! empty( $f['sel'] ) ) ),
			'writes'   => $s['writes'],
			'at'       => current_time( 'mysql', true ),
			'ms'       => 0,
		];
		$out['fill'] = self::fill_plan( $out, $row );
		$out['ms']   = (int) round( ( microtime( true ) - $start ) * 1000 );
		return $out;
	}

	/**
	 * Cells for any products, whatever site they're on (One page): each product on a site with a vendor map gets
	 * that site's row, by the same rules as matrix(); a product on no mapped site is left out.
	 * @param int[] $ids
	 * @return array<int,array> product id → matrix() row plus `host` and `map` (the map's status)
	 */
	public static function cells_for( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		self::maybe_install();
		// The products as VendorMaps::site_products() lists them (a verified page, published, not excluded), found by
		// ID: one query, not one scan of every vendor URL per site.
		$skip = array_flip( Exclusions::ids() );
		$by   = [];
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			foreach ( $wpdb->get_results(
				"SELECT p.ID id, p.post_title title, u.meta_value url, pg.status st, pg.html_file file, pg.content_hash hash, pg.note note, pg.fetched_at at
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
				 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
				 LEFT JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( u.meta_value )
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.ID IN (" . implode( ',', $chunk ) . ')
				 ORDER BY p.ID',
				ARRAY_A
			) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
				$id   = (int) $x['id'];
				$host = VendorMaps::host( (string) $x['url'] );
				if ( $host === '' || isset( $skip[ $id ] ) || isset( $by[ $host ][ $id ] ) ) {
					continue;
				}
				$by[ $host ][ $id ] = [
					'id'    => $id,
					'title' => html_entity_decode( (string) $x['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'url'   => (string) $x['url'],
					'st'    => (string) $x['st'],
					'file'  => (string) $x['file'],
					'hash'  => (string) $x['hash'],
					'note'  => (string) $x['note'],
					'at'    => (string) $x['at'],
				];
			}
		}
		$out = [];
		foreach ( $by as $host => $products ) {
			$row = VendorMaps::get( $host );
			if ( ! $row ) {
				continue;
			}
			$products = array_values( $products );
			$status   = VendorMaps::status_of( $row );
			foreach ( self::site_rows( $host, $row, $products, true )['rows'] as $r ) {
				$out[ (int) $r['id'] ] = $r + [ 'host' => $host, 'map' => $status ];
			}
		}
		return $out;
	}

	/**
	 * Rows for these products of one site (matrix(): all of them), with the readings, page states and whether
	 * writing is on that the cells were decided with. $some: only these products' readings are fetched (a few
	 * products of a large site), not the whole site's.
	 * @return array{rows:array,ready:array,pending:int,units:array,stamp:string,writes:array}
	 */
	private static function site_rows( string $host, array $row, array $products, bool $some = false ): array {
		global $wpdb;
		$stamp  = self::stamp( $host );
		$gaps   = (array) ( $row['gaps'] ?? [] );
		$rel    = array_map( 'strval', (array) ( $gaps['rel'] ?? [] ) );
		$units  = [];
		$where  = $wpdb->prepare( 'host = %s', $host );
		if ( $some ) {
			$keys = [];
			foreach ( $products as $p ) {
				$keys[] = self::key( (string) $p['url'] );
				$keys[] = self::key( (string) $p['url'], (int) $p['id'] );
			}
			$where .= " AND page_key IN ('" . implode( "','", $keys ) . "')"; // md5 hex only
		}
		foreach ( $wpdb->get_results( 'SELECT page_key, product_id, url, content_hash, stamp, layout, reading FROM ' . self::table() . ' WHERE ' . $where, ARRAY_A ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$units[ $u['page_key'] ] = [ 'card' => (int) $u['product_id'], 'url' => $u['url'], 'hash' => $u['content_hash'], 'stamp' => $u['stamp'], 'layout' => (int) $u['layout'], 'r' => (array) json_decode( (string) $u['reading'], true ) ];
		}
		$ids    = array_map( static fn( $p ) => (int) $p['id'], $products );
		$states = Coverage::products( $ids );
		self::$req_decided = FillRun::kept_requirements( $ids );
		$none_of           = self::none_decided( $ids );
		$pics   = self::picture_plans( $row );
		$queued = self::pictures_queued( $ids );
		$write  = ! empty( Enrichment::settings()['enabled'] );
		$rules  = Enrichment::rules();
		$desc   = $write && ! empty( $rules['content:vendor_page']['approved'] );
		$ready  = [ 'read' => 0, 'blocked' => 0, 'unread' => 0, 'broken' => 0 ];
		$rows   = [];
		$pend   = 0;
		foreach ( $products as $p ) {
			$id    = (int) $p['id'];
			$where = self::page_state( $p );
			$ready[ $where ]++;
			$card  = ( $rel[ $id ] ?? '' ) === 'several';
			$unit  = $where === 'read' ? ( $units[ self::key( (string) $p['url'], $card ? $id : 0 ) ] ?? null ) : null;
			$fresh = $unit && $unit['stamp'] === $stamp && $unit['hash'] === (string) $p['hash'];
			if ( $where === 'read' && ! $fresh ) {
				$pend++;
			}
			$st    = (array) ( $states[ $id ] ?? [] );
			$cells = [];
			foreach ( array_keys( self::FIELDS ) as $f ) {
				$s = (array) ( $st[ $f ] ?? [ 'na', '' ] );
				if ( ( $s[0] ?? 'na' ) === 'na' ) {
					$cells[ $f ] = [ '-', '', (string) ( $s[1] ?? '' ), '' ];
				} elseif ( $where !== 'read' ) {
					$cells[ $f ] = [ [ 'blocked' => 'X', 'broken' => 'B', 'unread' => 'U' ][ $where ], '', (string) $s[1], '' ];
				} elseif ( ! $fresh ) {
					$cells[ $f ] = [ 'C', '', (string) $s[1], '' ];
				} else {
					$cells[ $f ] = self::cell( $f, $id, $s, $unit['r'], $pics[ $id ] ?? '', $desc );
				}
			}
			// Pictures a fill (or Product pages) queued: the picture runner writes them, so they aren't offered again (owner 2026-10-25)
			if ( isset( $queued[ $id ], $cells['images'] ) && $cells['images'][0] === 'A' ) {
				$cells['images'][0] = 'Q';
				$cells['images'][3] = 'Queued: the picture runner writes them (up to 300 products a day)';
			}
			// A person said its page has none of it (decide_none()): no longer a gap, while the page is the one they decided on
			$none = [];
			foreach ( (array) ( $none_of[ $id ] ?? [] ) as $nf => $d ) {
				if ( isset( $cells[ $nf ] ) && in_array( $cells[ $nf ][0], self::EMPTY, true ) && (string) ( $d['url'] ?? '' ) === (string) $p['url'] ) {
					$cells[ $nf ] = [ '-', '', $cells[ $nf ][2], 'Its page has none: a person decided its maker doesn’t publish it' ];
					$none[]       = $nf;
				}
			}
			$seen = [];
			foreach ( $cells as $sf => $c ) {
				if ( $c[0] === 'S' ) {
					$seen[ $sf ] = (string) ( $unit['r']['seen'][ $sf ]['sel'] ?? '' ); // the selector its part reads it from (seen())
				}
			}
			$rows[] = [
				'id'     => $id,
				'title'  => (string) $p['title'],
				'url'    => (string) $p['url'],
				'edit'   => (string) get_edit_post_link( $id, 'raw' ),
				'page'   => $where,
				'status' => (string) $p['st'],
				'note'   => (string) $p['note'],
				'shared' => (string) ( $rel[ $id ] ?? '' ),
				'no_card' => $unit && ! empty( $unit['r']['no_card'] ), // its page is shared and no card names it (ProductPage)
				'layout' => $unit ? (int) $unit['layout'] : -1,
				'req'    => (string) ( self::$req_decided[ $id ]['choice'] ?? '' ), // a person's decision on its page's requirements (FillRun)
				'none'   => $none,
				'seen'   => (object) $seen,
				'cells'  => $cells,
			];
		}
		return [
			'rows'    => $rows,
			'ready'   => $ready,
			'pending' => $pend,
			'units'   => $units,
			'stamp'   => $stamp,
			'writes'  => [ 'enabled' => $write, 'descriptions' => $desc, 'pictures' => $write && ! empty( $rules['image:vendor_page']['approved'] ) ],
		];
	}

	/** The fields a person said these products' pages have none of: id → field → [ url, at, by ]. @param int[] $ids */
	private static function none_decided( array $ids ): array {
		global $wpdb;
		$out = [];
		foreach ( array_chunk( array_values( array_filter( array_map( 'intval', $ids ) ) ), 1000 ) as $chunk ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (" . implode( ',', $chunk ) . ')', self::NONE_META ), ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
				$v = maybe_unserialize( $m['meta_value'] );
				if ( is_array( $v ) ) {
					$out[ (int) $m['post_id'] ] = $v;
				}
			}
		}
		return $out;
	}

	/**
	 * A person closes a gap (owner 2026-10-31: a ✗ said there was a gap and nothing said how to clear it): the page
	 * has none of field $f, so its maker doesn't publish it. Said of the page, so it holds for every product of the
	 * site reading that page whose cell is still a gap (G) or unread (S); a product reading its own card on a shared
	 * page decides alone. The cell shows "doesn't apply" while the product's vendor URL is that page. $undo takes it
	 * back for the same products.
	 * @return array{state:string,note:string,ids?:int[]}
	 */
	public static function decide_none( string $host, int $id, string $f, bool $undo = false ): array {
		$host = VendorMaps::host( $host );
		$row  = VendorMaps::get( $host );
		if ( ! $row || ! isset( self::FIELDS[ $f ] ) ) {
			return [ 'state' => 'error', 'note' => 'This site has no map.' ];
		}
		$products = VendorMaps::site_products( $host );
		$mine     = null;
		foreach ( $products as $p ) {
			if ( (int) $p['id'] === $id ) {
				$mine = $p;
				break;
			}
		}
		if ( ! $mine ) {
			return [ 'state' => 'error', 'note' => 'This product has no verified page on this site.' ];
		}
		$url  = (string) $mine['url'];
		$rel  = array_map( 'strval', (array) ( ( (array) ( $row['gaps'] ?? [] ) )['rel'] ?? [] ) );
		$card = static fn( $p ) => ( $rel[ (int) $p['id'] ] ?? '' ) === 'several';
		$same = $card( $mine ) ? [ $mine ] : array_values( array_filter( $products, static fn( $p ) => (string) $p['url'] === $url && ! $card( $p ) ) );
		$ids  = [];
		foreach ( self::site_rows( $host, $row, $same, true )['rows'] as $r ) {
			if ( $undo ? in_array( $f, $r['none'], true ) : in_array( $r['cells'][ $f ][0], self::EMPTY, true ) ) {
				$ids[] = (int) $r['id'];
			}
		}
		if ( ! $ids ) {
			return [ 'state' => 'error', 'note' => $undo ? 'There is no decision to undo.' : 'Its page isn’t a gap for this any more: nothing to decide.' ];
		}
		foreach ( $ids as $pid ) {
			$all = get_post_meta( $pid, self::NONE_META, true );
			$all = is_array( $all ) ? $all : [];
			if ( $undo ) {
				unset( $all[ $f ] );
			} else {
				$all[ $f ] = [ 'url' => $url, 'at' => current_time( 'mysql', true ), 'by' => get_current_user_id() ];
			}
			if ( $all ) {
				update_post_meta( $pid, self::NONE_META, $all );
			} else {
				delete_post_meta( $pid, self::NONE_META );
			}
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', $undo ? 'vendor_maps.page_none_undo' : 'vendor_maps.page_none', [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'host' => $host, 'field' => $f, 'url' => $url, 'products' => $ids ] ] );
		}
		$n = count( $ids );
		return [
			'state' => $undo ? 'undone' : 'decided',
			'ids'   => $ids,
			'note'  => $undo ? sprintf( 'Taken back: the %s cell is a gap again on %d product%s.', self::LABELS[ $f ], $n, $n === 1 ? '' : 's' ) : sprintf( 'Saved: no %s on this page, for the %d product%s reading it.', self::LABELS[ $f ], $n, $n === 1 ? '' : 's' ),
		];
	}

	/** The products among these waiting in the picture runner's queue (Pictures ledger: queued or working). @return array<int,bool> */
	private static function pictures_queued( array $ids ): array {
		global $wpdb;
		$out = [];
		foreach ( array_chunk( array_map( 'intval', $ids ), 1000 ) as $chunk ) {
			foreach ( $wpdb->get_col( 'SELECT product_id FROM ' . Pictures::table() . " WHERE status IN ('queued','working') AND product_id IN (" . implode( ',', $chunk ) . ')' ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
				$out[ (int) $id ] = true;
			}
		}
		return $out;
	}

	/** Per product what the picture runner would do with the map as it is in the editor (VendorMaps' refresh keeps it): id → 'vendor' or the reason it wouldn't. */
	private static function picture_plans( array $row ): array {
		$pre = (array) ( $row['reading'] ?? [] );
		$by  = ! empty( $pre['_preview'] ) && isset( $pre['_pictures']['by'] ) ? $pre['_pictures']['by'] : ( $row['stats']['by'] ?? [] );
		return array_map( 'strval', (array) $by );
	}

	/**
	 * One cell: [ code, source (g generic reader / m the map), the product now, the page ] (+ 1 when the map
	 * would take away what the generic reader finds). The writers' rules: Enrichment for descriptions (thin only,
	 * when vendor descriptions are approved), the picture runner's own plan, PageRequirements::plan() (fills empty
	 * ones, anything else is for review), VendorMaps::plan_fill() for manuals and videos (a mapped part,
	 * empty fields only), the generic reader for features (the description's lists, empty fields only). Version,
	 * audio, weight and size have no writer yet.
	 */
	private static function cell( string $f, int $id, array $s, array $r, string $pic, bool $desc ): array {
		[ $state, $now ] = [ (string) $s[0], (string) ( $s[1] ?? '' ) ];
		if ( ! empty( $r['no_card'] ) ) {
			return [ 'G', '', $now, 'Its listing page has no card naming it' ];
		}
		$with   = (array) ( $r['with'][ $f ] ?? [ false, '' ] );
		$gen    = (array) ( $r['gen'][ $f ] ?? [ false, '' ] );
		$page   = (string) ( $with[1] ?? '' );
		$src    = ! empty( $gen[0] ) ? 'g' : ( ! empty( $with[0] ) ? 'm' : '' );
		$lost   = ! empty( $gen[0] ) && empty( $with[0] ) ? [ 1 ] : [];
		$cell   = static fn( string $code, string $from = '', string $why = '' ) => array_merge( [ $code, $from, $now, $why !== '' ? $why : $page ], $lost );
		switch ( $f ) {
			case 'images':
				if ( $pic === 'vendor' ) {
					return $cell( 'A', $src );
				}
				return $state === 'ok' ? $cell( 'H' ) : $cell( 'G', '', Pictures::REASONS[ $pic ] ?? ( $page !== '' ? $page : 'No pictures found' ) );
			case 'description':
				if ( $state === 'ok' ) {
					return $cell( 'H' );
				}
				if ( empty( $with[0] ) ) {
					return $cell( 'G', '', $page !== '' ? $page . ' (too little to use)' : 'No description found' );
				}
				return $desc ? $cell( 'A', $src ) : $cell( 'R', $src, $page . ' (vendor descriptions are proposals: writing them is switched off)' );
			case 'features':
				// The description's lists of 3+ points (VendorMaps::NOT_MAPPED): the generic reader writes them into empty fields.
				if ( ! empty( $with[0] ) ) {
					if ( class_exists( 'MMI_Pipeline_Field_Locks' ) && class_exists( 'MMI_Product_Content_Registry' ) && \MMI_Pipeline_Field_Locks::is_locked( $id, \MMI_Product_Content_Registry::canonical_key( 'features' ) ) ) {
						return $cell( 'L' );
					}
					if ( $state === 'ok' || $state === 'warn' ) {
						return $cell( $state === 'ok' ? 'H' : 'K' );
					}
					return $cell( 'A', 'g' );
				}
				if ( $state === 'ok' ) {
					return $cell( 'H' );
				}
				return $state === 'warn' ? $cell( 'K' ) : self::gap( $f, $r, 0, $cell );
			case 'manual':
			case 'videos':
				$n = (int) ( $r['ex'][ $f ] ?? 0 );
				if ( $n > 0 ) {
					$concept = self::MAPPED_ONLY[ $f ];
					if ( class_exists( 'MMI_Pipeline_Field_Locks' ) && class_exists( 'MMI_Product_Content_Registry' ) && \MMI_Pipeline_Field_Locks::is_locked( $id, \MMI_Product_Content_Registry::canonical_key( $concept ) ) ) {
						return $cell( 'L' );
					}
					if ( class_exists( 'MMI_Product_Content_Registry' ) && \MMI_Product_Content_Registry::count( $id, $concept ) > 0 ) {
						return $cell( $state === 'ok' ? 'H' : 'K' );
					}
					return $cell( 'A', 'm' );
				}
				if ( $state === 'ok' ) {
					return $cell( 'H' );
				}
				return ! empty( $with[0] ) ? $cell( 'P', 'g' ) : self::gap( $f, $r, $n, $cell );
			case 'os':
				$plan = ! empty( $r['req'] ) && class_exists( PageRequirements::class ) ? PageRequirements::plan( $id, (array) $r['req'] ) : [];
				$acts = array_values( array_intersect_key( $plan, [ 'mac' => 1, 'windows' => 1, 'linux' => 1 ] ) );
				if ( in_array( 'fill', $acts, true ) ) {
					return $cell( 'A', $src ?: 'g' );
				}
				if ( in_array( 'replace', $acts, true ) || ( $state !== 'ok' && in_array( 'review', $acts, true ) ) ) {
					$d = self::$req_decided[ $id ] ?? null;
					if ( $d && $d['choice'] === 'keep' && $d['sig'] === FillRun::req_sig( (array) $r['req'] ) ) {
						return $cell( 'H', '', 'Kept ours over the page’s (a person decided)' );
					}
					return $cell( 'R', $src ?: 'g' ); // the product's are clearly outdated (PageRequirements::outdated()), or missing a platform the page names
				}
				if ( in_array( 'locked', $acts, true ) ) {
					return $cell( 'L' );
				}
				if ( $state === 'ok' ) {
					return $cell( 'H' );
				}
				if ( $state === 'warn' ) {
					return $cell( 'K' );
				}
				return $page !== '' && empty( $r['seen']['os']['sel'] ) ? $cell( 'G', '', $page . ' (no Mac or Windows requirements read from it)' ) : self::gap( $f, $r, 0, $cell );
			default: // version, audio, size
				if ( $state === 'ok' ) {
					return $cell( 'H' );
				}
				return ! empty( $with[0] ) ? $cell( 'N', $src ) : self::gap( $f, $r, 0, $cell );
		}
	}

	/**
	 * A gap as exactly as the reading can say it (owner 2026-10-31): what the page shows that nothing reads (S, with
	 * seen()'s selector on the row), what the map's part reads too little of, a heading with nothing readable under
	 * it, requirements linked from the page, or nothing like it on the page. $n: what the map's part reads here.
	 */
	private static function gap( string $f, array $r, int $n, callable $cell ): array {
		$seen = (array) ( $r['seen'][ $f ] ?? [] );
		if ( $f === 'features' && ( $seen['kind'] ?? '' ) === 'cards' ) { // Key Features are bullet points (owner 2026-11-01): cards aren't picked for them
			return $cell( 'G', '', sprintf( 'The page has %d feature cards%s, not a bullet list; Features come from the description’s lists', (int) $seen['n'], ( $seen['under'] ?? '' ) !== '' ? ' under “' . $seen['under'] . '”' : '' ) );
		}
		if ( ! empty( $seen['sel'] ) ) {
			$n    = (int) $seen['n'];
			$what = [
				'cards'        => sprintf( 'On the page as %d cards', $n ),
				'items'        => sprintf( 'On the page as a list of %d', $n ),
				'videos'       => sprintf( '%d video%s on the page', $n, $n === 1 ? '' : 's' ),
				'requirements' => implode( ' and ', array_map( static fn( $p ) => [ 'mac' => 'Mac', 'windows' => 'Windows', 'linux' => 'Linux' ][ $p ] ?? $p, (array) ( $seen['platforms'] ?? [] ) ) ) . ' requirements are on the page in lists',
			][ $seen['kind'] ] ?? sprintf( '%d on the page', $n );
			return $cell( 'S', '', $what . ( $seen['under'] !== '' ? ' under “' . $seen['under'] . '”' : '' ) . '; nothing reads ' . ( $n === 1 ? 'it' : 'them' ) );
		}
		if ( ! empty( $seen['head'] ) ) {
			return $cell( 'G', '', sprintf( 'A “%s” heading is on the page, but no list or cards under it', $seen['head'] ) );
		}
		if ( ! empty( $seen['link'] ) ) {
			return $cell( 'G', '', sprintf( 'Not on the page; its “%s” link goes to %s, which Requirements can read (Kept on another page)', $seen['link'], $seen['href'] ) );
		}
		return $cell( 'G', '', self::NOTHING[ $f ] ?? 'Not on the page' );
	}

	/** Per layout: its pages and products, address shapes, sample pages, and per field where the map / the generic reader / nothing finds it; per part how far each pick reaches. */
	private static function layouts( array $row, array $units, string $stamp ): array {
		$meta = (array) ( $row['gaps']['layouts'] ?? [] );
		$out  = [];
		foreach ( $units as $u ) {
			if ( $u['card'] || $u['stamp'] !== $stamp || $u['layout'] < 0 || ! empty( $u['r']['empty'] ) ) { // pages only: a product's card is part of its page
				continue;
			}
			$i = $u['layout'];
			if ( ! isset( $out[ $i ] ) ) {
				$out[ $i ] = [ 'i' => $i, 'pages' => 0, 'shapes' => (array) ( $meta[ $i ]['shapes'] ?? [] ), 'samples' => [], 'per' => array_fill_keys( array_keys( self::FIELDS ), [ 0, 0, 0 ] ), 'picks' => [] ];
			}
			$out[ $i ]['pages']++;
			if ( count( $out[ $i ]['samples'] ) < self::SAMPLES ) {
				$out[ $i ]['samples'][] = $u['url'];
			}
			foreach ( self::FIELDS as $f => $part ) {
				$picked = $part !== '' && in_array( true, (array) ( $u['r']['picks'][ $part ] ?? [] ), true );
				$out[ $i ]['per'][ $f ][ $picked ? 0 : ( ! empty( $u['r']['gen'][ $f ][0] ) ? 1 : 2 ) ]++;
			}
			foreach ( (array) ( $u['r']['picks'] ?? [] ) as $part => $hits ) {
				foreach ( (array) $hits as $n => $hit ) {
					$out[ $i ]['picks'][ $part ][ $n ] = ( $out[ $i ]['picks'][ $part ][ $n ] ?? 0 ) + ( $hit ? 1 : 0 );
				}
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * What one click would do now: the fields it writes (products per field), the products queued for a scrape
	 * and for the picture runner, and why it may not run (said on the button). The same guards as Confirm.
	 */
	private static function fill_plan( array $m, array $row ): array {
		$scrape = [];
		$pics   = [];
		$per    = [];
		$wait   = 0; // products whose pictures wait for the picture runner (cell Q): not offered again
		foreach ( $m['rows'] as $r ) {
			foreach ( $r['cells'] as $f => $c ) {
				if ( $c[0] === 'Q' ) {
					$wait++;
				}
				if ( $c[0] !== 'A' ) {
					continue;
				}
				$per[ $f ] = ( $per[ $f ] ?? 0 ) + 1;
				if ( $f === 'images' ) {
					$pics[ $r['id'] ] = true;
				} else {
					$scrape[ $r['id'] ] = true;
				}
			}
		}
		$products = count( $scrape + $pics );
		$parts    = array_filter( (array) $row['fields'], static fn( $f ) => ! empty( $f['sel'] ) );
		$confirm  = $parts && ! in_array( $row['status'], [ 'confirmed', 'off' ], true );
		$why      = '';
		if ( $row['status'] === 'off' ) {
			$why = 'The map is switched off: reopen it first.';
		} elseif ( $m['pending'] ) {
			$why = sprintf( '%d page%s being checked with the map as it is now.', $m['pending'], $m['pending'] === 1 ? ' is' : 's are' );
		} elseif ( ! $products ) {
			$why = $m['ready']['blocked'] > $m['ready']['read'] ? 'The readable pages add nothing: send the blocked ones from your browser first.' : ( $wait ? sprintf( 'Nothing more to fill: %d product%s’ pictures wait for the picture runner.', $wait, $wait === 1 ? '' : 's' ) : 'Nothing to fill: every gain the pages offer is written.' );
		} elseif ( ! $m['writes']['enabled'] ) {
			$why = 'Writing from vendor pages is switched off (Product Data → Applying).';
		} elseif ( $confirm ) {
			$why = self::confirm_block( $row );
		}
		return [ 'confirm' => $confirm, 'products' => $products, 'fields' => array_sum( $per ), 'per' => $per, 'scrape' => array_keys( $scrape ), 'pictures' => array_keys( $pics ), 'waiting' => $wait, 'why' => $why ];
	}

	/** Why this draft can't be confirmed now ('' = it can): the Confirm route's own guards. */
	public static function confirm_block( array $row ): string {
		$zero = VendorMaps::zero_fields( $row );
		if ( $zero ) {
			return sprintf( '%s finds nothing on the stored pages: pick it again or clear it on the maker’s site, with the extension.', implode( ' and ', array_map( static fn( $k ) => VendorMaps::FIELDS[ $k ][0] ?? $k, $zero ) ) );
		}
		$drops = VendorMaps::drops( $row );
		if ( $drops === null ) {
			return 'What confirming changes is still being measured: look again in a minute.';
		}
		return $drops ? 'Confirming would lose what the reader finds now: ' . implode( ', ', array_map( static fn( $d ) => sprintf( '%s %d → %d pages', VendorMaps::FIELDS[ $d[0] ][0] ?? $d[0], $d[1], $d[2] ), $drops ) ) . '. A mapped part reads less than the generic reader there: pick it again or clear it on the maker’s site, with the extension.' : '';
	}

	/** The list's line for a site: products by page state, products and fields filling would add, proposals, parts that would add more if mapped, layouts. */
	public static function summary( ?array $m ): ?array {
		if ( ! $m ) {
			return null;
		}
		$pick = [];
		$prop = 0;
		foreach ( $m['totals'] as $f => $t ) {
			$n = (int) ( $t['P'] ?? 0 ) + (int) ( $t['S'] ?? 0 ); // cells a mapped part would write: the generic reader finds it, or seen() does
			if ( $n ) {
				$pick[ $f ] = $n;
			}
			$prop += (int) ( $t['R'] ?? 0 );
		}
		arsort( $pick );
		return [
			'products' => $m['products'],
			'ready'    => $m['ready'],
			'gain'     => $m['fill']['products'],
			'fields'   => $m['fill']['fields'],
			'proposals' => $prop,
			'pick'     => $pick,
			'layouts'  => count( $m['layouts'] ),
			'no_card'  => (int) ( $m['no_card'] ?? 0 ),
			'pages'    => array_sum( array_column( $m['layouts'], 'pages' ) ),
			'pending'  => $m['pending'],
			'at'       => $m['at'],
		];
	}
}
