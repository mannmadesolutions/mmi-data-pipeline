<?php
/**
 * Choice bundles (owner 2026-11-07, on UAD Custom 2 Premium Bundle, #226119): a product the buyer completes at the
 * maker by choosing N items from a list. On this store it is one simple product, one code; on uaudio.com the buyer
 * then picks 2 of 150+ plug-ins. Its maker page is a picker: a grid of other products' cards. So:
 *  - its data is the offer: the page's intro, its own picture, its requirements and what can be chosen. Never any
 *    one choice's data;
 *  - the grid's pictures are other products'. The picker labels them "Other product" and leaves them out of Pick all
 *    (VendorPage::other_product(), pictures.js otherProduct()); the server's readers do the same;
 *  - its contents aren't fixed: Bundles kind E, so no members are proposed (Bundles::compute()) and nothing is
 *    inherited from member products (BundleInherit reads confirmed members only, and a choice bundle has none);
 *  - what can be chosen is read from the page as drawn in a person's browser, because the maker's scripts draw the
 *    grid and the server's copy lacks it. The extension's "Read the choices on this page" sends the page (the Store
 *    this page request, ExtBridge::snapshot()), page by page of the maker's list, add only. The list becomes the
 *    description's "Choose any N of these" section (described()), whose names DescLinks links to our own products.
 *
 * A title naming Custom / Select / Pick / Choose N proposes it. A person says yes or no in the extension's panel,
 * or sets the kind in the Bundles view; a re-run never changes a person's kind.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ChoiceBundles {

	const KIND = 'E';
	/** { n, items: [ {name, url, img} ], pages: { address: cards read }, at } */
	const META = '_mmpt_choices';
	/** "Custom 2", "Select 10", "Pick 4", "Choose 3": the buyer's number of choices. */
	const TITLE_RE = '/\b(?:custom|select|pick|choose|choice of)\s+(\d{1,2})\b/i';
	/** An offer for a product ("Upgrade from any 2 Rob Papen products") is no choice bundle. */
	const OFFER_RE = '/\b(?:upgrades?|crossgrades?|cross-grade|trade[- ]?up|renewal|update)\b|\bfrom any\b/i';
	/** "Pick Your 2 Plug-Ins", "Choose any 3": the number on the page, when the title has none. */
	const PAGE_N_RE = '/\b(?:pick|choose|select)\s+(?:your|any)\s+(\d{1,2})\b/i';
	/** A grid of choices has at least this many like cards. */
	const MIN_CARDS = 4;
	const MAX_ITEMS = 400;
	/** Levels above a card's name link to look for the card's picture. */
	const CLIMB = 6;
	/** Blocks of other products that aren't the choices: related, upsell, recently viewed (menus, headers and footers go by their tags). */
	const ASIDE_RE = '/(?:^|[\s_-])(?:related|upsell|cross-?sell|recommend\w*|also-?like|you-?may|recently-?viewed|mega-?menu|menu)(?:[\s_-]|$)/i';
	/** Query words that turn the maker's list to another of its pages. */
	const PAGED_RE = '/(?:^|&)[^=&]*page[^=&]*=(\d{1,3})(?:&|$)/i';

	/** @var array<int,?array> bundle row per product, this request */
	private static $rows = [];

	/* ── What it is ─────────────────────────────────────────────────────── */

	/** The number of choices a title states (0 = none, or an offer). */
	public static function title_n( string $title ): int {
		$title = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return ! preg_match( self::OFFER_RE, $title ) && preg_match( self::TITLE_RE, $title, $m ) && (int) $m[1] >= 1 ? (int) $m[1] : 0;
	}

	/** How many the buyer chooses: the title's number, else the number the maker's page states. 0 = not known. */
	public static function n( int $id ): int {
		return self::title_n( (string) get_the_title( $id ) ) ?: (int) ( self::kept( $id )['n'] ?? 0 );
	}

	/**
	 * yes: a person said it is one; proposed: its title (or the automatic kind) says so and nobody has decided;
	 * no: a person said it isn't; '': nothing suggests it.
	 */
	public static function state( int $id ): string {
		$row = self::row( $id );
		if ( $row && $row['kind_by'] === 'owner' ) {
			return $row['kind'] === self::KIND ? 'yes' : ( self::title_n( (string) get_the_title( $id ) ) ? 'no' : '' );
		}
		return ( $row && $row['kind'] === self::KIND ) || self::title_n( (string) get_the_title( $id ) ) ? 'proposed' : '';
	}

	public static function is( int $id ): bool {
		return self::state( $id ) === 'yes';
	}

	/** The product's Bundles row (kind, kind_by), or null. */
	private static function row( int $id ): ?array {
		if ( ! array_key_exists( $id, self::$rows ) ) {
			\MMI_Pipeline_Bundles::install();
			global $wpdb;
			self::$rows[ $id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT kind, kind_by FROM ' . \MMI_Pipeline_Bundles::table() . ' WHERE bundle_id = %d', $id ), ARRAY_A ) ?: null;
		}
		return self::$rows[ $id ];
	}

	/**
	 * A person's answer: yes makes it kind E (kept by every re-run) and drops the member proposals a choice bundle
	 * can't have; no hands the kind back to the automatic reading, which then leaves the title's number aside.
	 */
	public static function set( int $id, bool $yes ): void {
		\MMI_Pipeline_Bundles::install();
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . \MMI_Pipeline_Bundles::table() . " (bundle_id, kind, kind_by, computed_at) VALUES (%d, %s, 'owner', %s)
				 ON DUPLICATE KEY UPDATE kind = VALUES(kind), kind_by = 'owner'", // phpcs:ignore WordPress.DB.PreparedSQL
				$id,
				$yes ? self::KIND : '',
				current_time( 'mysql', true )
			)
		);
		if ( $yes ) {
			self::drop_proposals( $id );
		}
		unset( self::$rows[ $id ] );
		self::touched( $id );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'bundles.choice_' . ( $yes ? 'yes' : 'no' ), [ 'object_type' => 'product', 'object_id' => (string) $id ] );
		}
	}

	/** Proposed members leave a choice bundle; a person's own decisions on members stay. */
	public static function drop_proposals( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . \MMI_Pipeline_Bundles::members_table() . " WHERE bundle_id = %d AND status = 'proposed' AND by_owner = 0", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* ── What can be chosen ─────────────────────────────────────────────── */

	/** @return array{n?:int,items?:array,pages?:array,at?:string} */
	public static function kept( int $id ): array {
		$v = get_post_meta( $id, self::META, true );
		return is_array( $v ) ? $v : [];
	}

	/** What can be chosen, by name. @return array<int,array{name:string,url:string,img:string}> */
	public static function items( int $id ): array {
		$items = array_values( (array) ( self::kept( $id )['items'] ?? [] ) );
		usort( $items, static fn( $a, $b ) => strnatcasecmp( (string) $a['name'], (string) $b['name'] ) );
		return $items;
	}

	/**
	 * The cards of a page that lists what can be chosen: the largest group of like name links (same tag and class,
	 * under the same kind of parent) that lead to other product pages of the same site, each with a picture
	 * nearby. Menus, headers, footers and side blocks are left out first. Each: its name, its page, its picture.
	 * @return array<int,array{name:string,url:string,img:string}>
	 */
	public static function cards( string $html, string $url ): array {
		if ( $html === '' ) {
			return [];
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadHTML( '<?xml encoding="utf-8"?>' . VendorPage::without_scripts( $html ), LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR ); // the whole page: the grid sits outside what the description reader keeps
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return [];
		}
		$xp = new \DOMXPath( $doc );
		foreach ( iterator_to_array( $xp->query( '//script|//style|//noscript|//template|//nav|//header|//footer|//aside' ) ) as $n ) {
			$n->parentNode && $n->parentNode->removeChild( $n );
		}
		$here   = rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$groups = [];
		foreach ( $xp->query( '//a[@href]' ) as $a ) { // name links; a picture's link, whose words are its badges ("Native"), is a group of its own
			if ( ! $a instanceof \DOMElement ) {
				continue;
			}
			$name = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $a->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( mb_strlen( $name ) < 3 || mb_strlen( $name ) > 90 || $href === '' || $href[0] === '#' || preg_match( '#^(?:javascript|mailto|tel):#i', $href ) ) {
				continue;
			}
			$abs  = (string) strtok( (string) strtok( VendorPage::absolute_url( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url ), '#' ), '?' );
			$path = rtrim( (string) wp_parse_url( $abs, PHP_URL_PATH ), '/' );
			if ( ! PageStore::same_site( $abs, $url ) || $path === '' || $path === $here || ! UrlFinder::looks_like_product_page( $abs ) || preg_match( self::ASIDE_RE, self::classes_up( $a, 12 ) ) ) {
				continue;
			}
			$parent = $a->parentNode instanceof \DOMElement ? $a->parentNode : null;
			$sig    = self::signature( $a ) . '<' . ( $parent ? self::signature( $parent ) : '' );
			$groups[ $sig ][ mb_strtolower( $name ) ] = [ 'a' => $a, 'name' => $name, 'url' => $abs ];
		}
		$best = [];
		foreach ( $groups as $list ) {
			if ( count( $list ) < self::MIN_CARDS || count( $list ) <= count( $best ) ) {
				continue;
			}
			$out = [];
			foreach ( $list as $k => $c ) {
				$img = self::picture_near( $c['a'], $url );
				if ( $img !== '' ) {
					$out[ $k ] = [ 'name' => $c['name'], 'url' => $c['url'], 'img' => $img ];
				}
			}
			if ( count( $out ) >= max( self::MIN_CARDS, (int) ceil( count( $list ) / 2 ) ) ) { // cards have pictures; a list of text links isn't a grid of choices
				$best = $out;
			}
		}
		if ( count( $best ) < self::MIN_CARDS ) {
			$best = self::named_cards( $xp, $url ); // cards with no link of their own (ujam.com's "Add to Bundle" tiles)
		}
		return array_slice( array_values( $best ), 0, self::MAX_ITEMS );
	}

	/**
	 * Cards that name a choice without linking it: the largest group of like name elements (a class saying title or
	 * name, no elements inside) each with a picture nearby. A heading over the group is the family the names belong
	 * to: ujam.com lists "BERSERK" under "Beatmaker", which is "Beatmaker BERSERK". Each card's own product link, if any.
	 * @return array<string,array{name:string,url:string,img:string}>
	 */
	private static function named_cards( \DOMXPath $xp, string $url ): array {
		$groups = [];
		foreach ( $xp->query( '//*[not(*)][contains(@class,"title") or contains(@class,"name") or contains(@class,"Title") or contains(@class,"Name")]' ) as $n ) {
			$name = $n instanceof \DOMElement ? trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $n->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : '';
			if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 60 || preg_match( self::ASIDE_RE, self::classes_up( $n, 12 ) ) ) {
				continue;
			}
			$parent = $n->parentNode instanceof \DOMElement ? $n->parentNode : null;
			$groups[ self::signature( $n ) . '<' . ( $parent ? self::signature( $parent ) : '' ) ][] = [ $n, $name ];
		}
		$best = [];
		foreach ( $groups as $list ) {
			if ( count( $list ) < self::MIN_CARDS || count( $list ) <= count( $best ) ) {
				continue;
			}
			$out = [];
			foreach ( $list as [ $n, $name ] ) {
				$img = self::picture_near( $n, $url );
				if ( $img === '' ) {
					continue;
				}
				$family = self::family_of( $n, $name );
				$full   = $family !== '' && stripos( $name, $family ) === false ? $family . ' ' . $name : $name;
				$out[ mb_strtolower( $full ) ] ??= [ 'name' => $full, 'url' => self::own_link( $n, $url ), 'img' => $img ];
			}
			if ( count( $out ) >= max( self::MIN_CARDS, (int) ceil( count( $list ) / 2 ) ) ) {
				$best = $out;
			}
		}
		return $best;
	}

	/** The heading of the group a card sits in: a heading that is a direct child of one of the card's nearer boxes ('' = none). */
	private static function family_of( \DOMElement $n, string $name ): string {
		$box = $n->parentNode;
		for ( $i = 0; $i < self::CLIMB + 4 && $box instanceof \DOMElement && ! in_array( $box->nodeName, [ 'body', 'main', 'html' ], true ); $i++, $box = $box->parentNode ) {
			foreach ( $box->childNodes as $c ) {
				if ( $c instanceof \DOMElement && in_array( $c->nodeName, [ 'h2', 'h3', 'h4', 'h5' ], true ) ) {
					$t = trim( (string) preg_replace( '/\s+/u', ' ', $c->textContent ) );
					return $t !== '' && $t !== $name && mb_strlen( $t ) <= 40 ? $t : '';
				}
			}
		}
		return '';
	}

	/** A card's link to another product page of the same site ('' = none). */
	private static function own_link( \DOMElement $n, string $url ): string {
		$box = $n;
		for ( $i = 0; $i < self::CLIMB && $box instanceof \DOMElement; $i++, $box = $box->parentNode ) {
			if ( $box->getElementsByTagName( 'img' )->length ) {
				foreach ( $box->getElementsByTagName( 'a' ) as $a ) {
					$href = trim( (string) $a->getAttribute( 'href' ) );
					if ( $href === '' || $href[0] === '#' || preg_match( '#^(?:javascript|mailto|tel):#i', $href ) ) {
						continue;
					}
					$abs = (string) strtok( (string) strtok( VendorPage::absolute_url( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url ), '#' ), '?' );
					if ( PageStore::same_site( $abs, $url ) && rtrim( (string) wp_parse_url( $abs, PHP_URL_PATH ), '/' ) !== rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) && UrlFinder::looks_like_product_page( $abs ) ) {
						return $abs;
					}
				}
				return '';
			}
		}
		return '';
	}

	/** The nearest card picture above a name link: the first <img> of the smallest box around it that has one. */
	private static function picture_near( \DOMElement $a, string $url ): string {
		$box = $a;
		for ( $i = 0; $i < self::CLIMB && $box instanceof \DOMElement; $i++, $box = $box->parentNode ) {
			$imgs = $box->getElementsByTagName( 'img' );
			if ( $imgs->length ) {
				$img = $imgs->item( 0 );
				$src = '';
				foreach ( [ 'src', 'data-src', 'data-lazy-src' ] as $attr ) {
					$v = trim( (string) $img->getAttribute( $attr ) );
					if ( $v !== '' && ! str_starts_with( $v, 'data:' ) ) {
						$src = $v;
						break;
					}
				}
				return $src !== '' ? VendorPage::absolute_url( html_entity_decode( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url ) : '';
			}
		}
		return '';
	}

	private static function signature( \DOMElement $n ): string {
		return $n->nodeName . '.' . preg_replace( '/\s+/', ' ', trim( $n->getAttribute( 'class' ) ) );
	}

	/** The classes and ids of an element and a few of its parents, for the menu and related-block test. */
	private static function classes_up( \DOMNode $n, int $levels ): string {
		$out = '';
		for ( $i = 0; $i < $levels && $n instanceof \DOMElement; $i++, $n = $n->parentNode ) {
			$out .= ' ' . $n->getAttribute( 'class' ) . ' ' . $n->getAttribute( 'id' );
		}
		return $out;
	}

	/**
	 * The maker's other pages of the list, as its page links give them ("?…[page]=2"), in order. Each is the product's
	 * page with only the list's page number changed.
	 * @return string[]
	 */
	public static function pages_in( string $html, string $url ): array {
		$base = (string) strtok( $url, '?' );
		$out  = [];
		if ( ! preg_match_all( '/href\s*=\s*(["\'])([^"\']+)\1/i', $html, $m ) ) {
			return [];
		}
		foreach ( $m[2] as $href ) {
			$abs = VendorPage::absolute_url( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url );
			$q   = (string) wp_parse_url( $abs, PHP_URL_QUERY );
			if ( (string) strtok( $abs, '?' ) === $base && $q !== '' && preg_match( self::PAGED_RE, urldecode( $q ), $p ) ) {
				$out[ (int) $p[1] ] = (string) strtok( $abs, '#' );
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/** The page number a list address shows (1 for the product's page itself). */
	public static function page_no( string $url ): int {
		$q = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return $q !== '' && preg_match( self::PAGED_RE, urldecode( $q ), $p ) ? max( 1, (int) $p[1] ) : 1;
	}

	/** The address with no query: the product's own page. */
	public static function base_of( string $url ): string {
		return (string) strtok( (string) strtok( $url, '#' ), '?' );
	}

	/**
	 * The choice bundle whose maker page this is, or one of its list's other pages: its product, or 0. $prefer when it
	 * is one of them (the panel's product).
	 */
	public static function product_for( string $url, int $prefer = 0 ): int {
		$base = self::base_of( $url );
		$ids  = array_values( array_unique( array_merge( [], ...array_values( LiveMapper::same_page( $base ) ) ) ) );
		$ids  = array_values( array_filter( $ids, static fn( $id ) => self::state( (int) $id ) !== '' ) );
		return in_array( $prefer, $ids, true ) ? $prefer : (int) ( $ids[0] ?? 0 );
	}

	/**
	 * Read the cards of the page as drawn into the product's list, add only. Nothing is taken off: a person starts the
	 * list again (clear()) when the maker has taken choices away.
	 * @return array{read:int,added:int,total:int,pages:string[],page:int}
	 */
	public static function read( int $id, string $url, string $html ): array {
		$cards = self::cards( $html, $url );
		$kept  = self::kept( $id );
		$items = [];
		foreach ( (array) ( $kept['items'] ?? [] ) as $it ) {
			$items[ self::key( (array) $it ) ] = $it;
		}
		$before = count( $items );
		foreach ( $cards as $c ) {
			$items[ self::key( $c ) ] ??= $c;
		}
		$pages = self::pages_in( $html, $url );
		$read  = (array) ( $kept['pages'] ?? [] );
		if ( $cards ) {
			$read[ self::page_no( $url ) ] = count( $cards );
		}
		$n = (int) ( $kept['n'] ?? 0 );
		if ( ! $n && preg_match( self::PAGE_N_RE, wp_strip_all_tags( VendorPage::without_scripts( $html ) ), $m ) ) {
			$n = (int) $m[1];
		}
		$out = [ 'n' => $n, 'items' => array_slice( array_values( $items ), 0, self::MAX_ITEMS ), 'pages' => $read, 'more' => array_values( array_unique( array_merge( (array) ( $kept['more'] ?? [] ), $pages ) ) ), 'at' => current_time( 'mysql', true ) ];
		if ( $cards ) {
			update_post_meta( $id, self::META, $out );
			self::touched( $id );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'bundles.choices_read', [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'page' => self::page_no( $url ), 'cards' => count( $cards ), 'added' => count( $items ) - $before ] ] );
			}
		}
		return [ 'read' => count( $cards ), 'added' => count( $items ) - $before, 'total' => count( $out['items'] ), 'pages' => $out['more'], 'page' => self::page_no( $url ) ];
	}

	/** The list starts again from the next page read. */
	public static function clear( int $id ): void {
		delete_post_meta( $id, self::META );
		self::touched( $id );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'bundles.choices_cleared', [ 'object_type' => 'product', 'object_id' => (string) $id ] );
		}
	}

	/** The next list page not read yet, other than page $here (the one shown), or ''. */
	public static function next_page( int $id, int $here = 0 ): string {
		$kept = self::kept( $id );
		foreach ( (array) ( $kept['more'] ?? [] ) as $u ) {
			$no = self::page_no( (string) $u );
			if ( $no !== $here && ! isset( $kept['pages'][ $no ] ) ) {
				return (string) $u;
			}
		}
		return '';
	}

	private static function key( array $c ): string {
		$path = rtrim( strtolower( (string) wp_parse_url( (string) ( $c['url'] ?? '' ), PHP_URL_PATH ) ), '/' );
		return $path !== '' ? $path : mb_strtolower( (string) ( $c['name'] ?? '' ) );
	}

	/** What reads this product's description and links works it out again. */
	private static function touched( int $id ): void {
		delete_post_meta( $id, DescLinks::META );
	}

	/* ── Pictures ───────────────────────────────────────────────────────── */

	/** A picture's key for matching a card's picture to a reader's or the picker's: its file name. */
	public static function pic_key( string $u ): string {
		return strtolower( basename( (string) wp_parse_url( $u, PHP_URL_PATH ) ) );
	}

	/** The pictures of the choices' cards: those in the list, and those on $html's grid. @return array<string,bool> */
	public static function card_keys( int $id, string $url, string $html = '' ): array {
		$keys = [];
		foreach ( array_merge( self::items( $id ), $html !== '' ? self::cards( $html, $url ) : [] ) as $c ) {
			if ( (string) ( $c['img'] ?? '' ) !== '' ) {
				$keys[ self::pic_key( (string) $c['img'] ) ] = true;
			}
		}
		return $keys;
	}

	/**
	 * A choice bundle's page pictures without its choices' cards (other products: Capitol Studios, Beatmaker BERSERK).
	 * Pictures::vendor_pictures(). Unchanged for anything else. @param array<int,array{url:string}> $imgs
	 */
	public static function without_cards( int $id, string $url, string $html, array $imgs ): array {
		if ( ! $imgs || ! self::is( $id ) ) {
			return $imgs;
		}
		$keys = self::card_keys( $id, $url, $html );
		return $keys ? array_values( array_filter( $imgs, static fn( $i ) => ! isset( $keys[ self::pic_key( (string) ( $i['url'] ?? '' ) ) ] ) ) ) : $imgs;
	}

	/* ── In the description ─────────────────────────────────────────────── */

	/**
	 * The description a choice bundle's page gives it: the page's own text, then what can be chosen as a list under its
	 * heading. Plain names; DescLinks links the ones we sell when the page is shown. Unchanged for anything else.
	 */
	public static function described( int $id, string $desc ): string {
		if ( ! self::is( $id ) ) {
			return $desc;
		}
		$items = self::items( $id );
		if ( ! $items ) {
			return $desc;
		}
		$n    = self::n( $id );
		$head = $n ? sprintf( 'Choose any %d of these', $n ) : 'Choose from these';
		$li   = implode( '', array_map( static fn( $it ) => '<li>' . esc_html( (string) $it['name'] ) . '</li>', $items ) );
		return $desc . '<h3>' . esc_html( $head ) . '</h3><ul>' . $li . '</ul>';
	}

	/* ── In the extension's panel ───────────────────────────────────────── */

	/**
	 * The panel's section for a product that is, or may be, a choice bundle (null for anything else). $url is the page
	 * shown; $paged: one of the list's other pages, not the product's own page.
	 */
	public static function section( int $id, string $url ): ?array {
		$state = self::state( $id );
		if ( $state === '' || $state === 'no' ) {
			return null;
		}
		$n = self::n( $id );
		if ( $state === 'proposed' ) {
			return [
				'title' => 'Choice bundle?',
				'text'  => ( $n ? sprintf( 'Its title says the buyer chooses %d.', $n ) : 'It may be a product whose buyer chooses what it holds.' ) . ' As a choice bundle, its description lists what can be chosen, linked to our own products, no bundle members are proposed, and nothing is taken from member products. The grid’s pictures are other products: they stay out of Pick all.',
				'rows'  => [],
				'more'  => [
					[ 'id' => 'choice_yes', 'label' => 'It’s a choice bundle', 'help' => 'The buyer chooses what it holds at the maker.', 'kind' => 'sitepart', 'op' => 'choice_yes', 'primary' => true ],
					[ 'id' => 'choice_no', 'label' => 'Not a choice bundle', 'help' => 'It holds the same products for every buyer.', 'kind' => 'sitepart', 'op' => 'choice_no' ],
				],
			];
		}
		$kept  = self::kept( $id );
		$total = count( (array) ( $kept['items'] ?? [] ) );
		$read  = (array) ( $kept['pages'] ?? [] );
		$here  = self::page_no( $url );
		$next  = self::next_page( $id, $here );
		$pages = count( (array) ( $kept['more'] ?? [] ) );
		$value = $total ? sprintf( '%d read', $total ) . ( $pages ? sprintf( ', from %d of %d list pages', count( $read ), max( $pages, count( $read ) ) ) : '' ) : 'None read yet';
		$rows  = [
			[ 'label' => 'Buyer chooses', 'value' => $n ? (string) $n : 'Not stated', 'state' => '' ],
			[ 'label' => 'Choices', 'value' => $value, 'state' => $total && self::next_page( $id ) === '' ? 'ok' : '' ],
		];
		$more = [
			[ 'id' => 'choice_read', 'label' => isset( $read[ $here ] ) ? 'Read the choices on this page again' : 'Read the choices on this page', 'help' => 'Adds the cards shown on this page to the list. Show all of the page’s cards first.', 'kind' => 'snapshot', 'primary' => ! isset( $read[ $here ] ) ],
		];
		if ( $next !== '' ) {
			$more[] = [ 'id' => 'choice_next', 'label' => sprintf( 'Open list page %d →', self::page_no( $next ) ), 'help' => 'The maker’s next page of choices. Read it there.', 'kind' => 'go', 'url' => $next, 'primary' => isset( $read[ $here ] ) ];
		}
		if ( $here !== 1 ) {
			$more[] = [ 'id' => 'choice_back', 'label' => '← Back to the product’s page', 'help' => 'Where its other parts are read.', 'kind' => 'go', 'url' => self::base_of( $url ) ];
		}
		if ( $total ) {
			$more[] = [ 'id' => 'choice_clear', 'label' => 'Start the list again', 'help' => 'Empties the list, for when the maker has taken choices away. The next page read starts it.', 'kind' => 'sitepart', 'op' => 'choice_clear' ];
		}
		$more[] = [ 'id' => 'choice_no', 'label' => 'Not a choice bundle', 'help' => 'It holds the same products for every buyer.', 'kind' => 'sitepart', 'op' => 'choice_no' ];
		$names  = array_map( static fn( $it ) => (string) $it['name'], array_slice( self::items( $id ), 0, 6 ) );
		return [
			'title' => 'Choice bundle',
			'rows'  => $rows,
			'text'  => $total
				? 'Its description gets “' . ( $n ? sprintf( 'Choose any %d of these', $n ) : 'Choose from these' ) . '” with this list: ' . implode( ', ', $names ) . ( $total > count( $names ) ? sprintf( ' and %d more', $total - count( $names ) ) : '' ) . '. The ones we sell link to their pages here.'
				: 'Read the choices on this page to list them in its description.',
			'more'  => $more,
		];
	}
}
