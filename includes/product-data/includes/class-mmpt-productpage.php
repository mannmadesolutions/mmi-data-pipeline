<?php
/**
 * Product Content — a product's vendor page, decided in one place (owner 2026-10-22: Vendor pages counted 3,131
 * characters and a picture for #17178 from a page whose cards are other products, while Vendor maps rightly showed
 * it nothing; "eliminate this from happening").
 *
 * own_html() is the one answer to "what on this page is this product's": the whole page, or on a page several
 * products share only the card naming it (SharedPages), null when no card names it. Every reader of a product's
 * page goes through it: the scrape, the gaps, the editor's preview, the picture runner, the description
 * proposals and the Vendor pages Content column (Coverage::for_rows()). A new reader must too; reading the
 * stored page whole for a product is how two screens came to disagree.
 *
 * recheck() looks again at products whose verified URL came before these rules (nothing re-checked URLs set
 * before 2026-10-06): a page several products share with no card naming the product is the product's page in
 * name only. Where the vendor's sitemap has an address that names the product, it is proposed in the URL queue
 * (reason `recheck`) for a person to approve; with no sitemap address, one guessed from the shared page's own
 * (sibling()). Its brand's other sites count too, and an address that is the product's own part number counts most
 * (moforte.com has no product pages; ilio.com, also its official site, has one for each). A card with only a line or two
 * gets the same look: the product is listed there, but a page of its own gives it a description. Failing all that,
 * a stored page of the site with its card (the store page, for a product linked to the Windows page). Nothing here
 * changes a product's URL.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProductPage {

	const NO_CARD    = 'Its listing page has no card naming it'; // SiteGaps and Coverage word it the same
	const THIN_CARD  = 'Its card on the listing page has little or no description';
	const THIN_TEXT  = 400; // a card with less than this is a listing, not a description: a page of its own is worth proposing
	const CODE_SCORE = 90; // a brand site's address that is the product's part number
	const CARD_SCORE = 70; // a stored page of the site that carries the product's card
	const MIN_SCORE  = 60; // a sitemap address proposed by recheck(): how closely it must match the name (UrlFinder::best_match())
	const WEAK_SCORE = 50; // its score when its page isn't stored yet, so its title can't confirm it
	const GUESS_SCORE = 70; // an address guessed from the shared page's whose page's title names the product (sibling())
	const GUESS_MAX   = 40; // guessed addresses read from the network per run

	/** @var int guessed addresses read from the network in this request */
	private static $guessed = 0;

	/**
	 * What on the stored page is product $id's: the page, its card on a page several share, or null (no card names it). A
	 * shop's category page as a whole is no product's: its cards are read, its intro, pager and other products' pictures
	 * aren't (2026-11-04: ilio.com's GeoSWAM category gave #185988 its pager as features).
	 */
	public static function own_html( int $id, string $url, string $html ): ?string {
		$own = SharedPages::html_for( $id, $url, $html );
		return $own === $html && VendorPage::is_listing_page( $html ) ? null : $own;
	}

	/**
	 * Products whose verified page gives them nothing because no card names them, and the proposals made for them.
	 * $host: one site ('' = all). @return array{checked:int,no_card:int,proposed:int,kept:int,dry_run:bool,examples:array}
	 */
	public static function recheck( string $host = '', bool $dry = false ): array {
		$h    = $host !== '' ? VendorMaps::host( $host ) : '';
		$skip = array_flip( Exclusions::ids() );
		$out  = [ 'checked' => 0, 'no_card' => 0, 'thin' => 0, 'proposed' => 0, 'kept' => 0, 'dry_run' => $dry, 'examples' => [] ];
		foreach ( array_keys( SharedPages::shared_urls() ) as $url ) {
			if ( $h !== '' && VendorMaps::host( $url ) !== $h ) {
				continue;
			}
			$pg = PageStore::row( $url );
			if ( ! $pg || $pg['status'] !== 'ok' || (string) $pg['html_file'] === '' ) {
				continue;
			}
			$html = '';
			foreach ( SharedPages::members( $url ) as $m ) {
				$id = (int) $m['id'];
				if ( isset( $skip[ $id ] ) || SharedPages::relation( $id, $url )['kind'] !== 'several' ) {
					continue;
				}
				$out['checked']++;
				$html = $html !== '' ? $html : PageStore::read( (string) $pg['html_file'] );
				$has = self::own_html( $id, $url, $html ) !== null;
				if ( $has && mb_strlen( wp_strip_all_tags( SharedPages::description_for( $id, $url ) ) ) >= self::THIN_TEXT ) {
					continue;
				}
				$out[ $has ? 'thin' : 'no_card' ]++;
				$p = self::proposal( $id, $url, $dry, $has );
				if ( ! $p ) {
					continue;
				}
				if ( ! self::may_offer( $id, $p['url'] ) ) {
					$out['kept']++;
					continue;
				}
				$out['proposed']++;
				if ( count( $out['examples'] ) < 10 ) {
					$out['examples'][] = $id . ' → ' . $p['url'] . ' (' . $p['score'] . ')';
				}
				if ( ! $dry ) {
					UrlFinder::offer( $id, $p['url'], $p['score'], 'candidate', $has ? 'recheck_card' : 'recheck', mb_substr( sprintf( '%s (%s). %s', $has ? self::THIN_CARD : self::NO_CARD, $url, $p['how'] ), 0, 255 ) );
				}
			}
		}
		if ( ! $dry && class_exists( 'MMI_Audit_Log' ) && $out['proposed'] ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.recheck', [ 'object_type' => 'vendor_site', 'object_id' => $h !== '' ? $h : '*', 'details' => array_diff_key( $out, [ 'examples' => 1 ] ) ] );
		}
		$dry || Module::info( 'Vendor pages re-checked: pages that list several products and no card naming the product', array_diff_key( $out, [ 'examples' => 1 ] ) );
		return $out;
	}

	/**
	 * An address on the vendor's site that names the product (site_index()), other than $url. Refused when its
	 * stored page doesn't name the product; weaker when its page isn't stored. Without one, an address guessed from
	 * $url's (sibling()). @return array{url:string,score:int,how:string}|null
	 */
	private static function proposal( int $id, string $url, bool $dry = false, bool $has_card = false ): ?array {
		$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$brand = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
		$brand = is_array( $brand ) ? (string) ( $brand[0] ?? '' ) : '';
		$code  = UrlFinder::code_of( $id );
		$first = VendorMaps::host( $url );
		$hosts = array_values( array_unique( array_merge( [ $first ], self::brand_hosts( $id, $brand ) ) ) );
		$weak  = null;
		foreach ( $hosts as $host ) {
			$index = array_filter( self::site_index( $host, ! $dry ), static fn( $u ) => ! UrlFinder::same_url( $u, $url ), ARRAY_FILTER_USE_KEY );
			$slugs = $code !== '' ? UrlFinder::slugs( array_keys( $index ) ) : [];
			$own = UrlFinder::code_url( $code, $slugs );
			if ( $own !== '' ) {
				return [ 'url' => $own, 'score' => self::CODE_SCORE, 'how' => 'Its address is its part number: ' . $code ];
			}
			$best = $index ? UrlFinder::best_match( $title, $brand, $index ) : null;
			if ( ! $best || $best['score'] < self::MIN_SCORE ) {
				continue;
			}
			$pg = PageStore::row( $best['url'] );
			if ( ! $pg || $pg['status'] !== 'ok' ) {
				$weak ??= [ 'url' => $best['url'], 'score' => min( (int) $best['score'], self::WEAK_SCORE ), 'how' => 'Its address in the vendor’s sitemap names it (page not read yet)' ];
			} elseif ( UrlFinder::page_names_product( (string) $pg['title'], $title, $brand ) ) {
				return [ 'url' => $best['url'], 'score' => (int) $best['score'], 'how' => 'This page’s title names it: ' . $pg['title'] ];
			}
		}
		if ( $weak ) {
			return $weak;
		}
		if ( $has_card ) {
			return null; // listed here already: only a page of its own would be better
		}
		return self::with_card( $id, $first, $url ) ?? self::sibling( $url, $title, $brand, $dry );
	}

	/** The brand's sites a product's page may be on (UrlFinder::search_sites()). @return string[] */
	private static function brand_hosts( int $id, string $brand ): array {
		$terms = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'ids' ] );
		$term  = is_array( $terms ) ? (int) ( $terms[0] ?? 0 ) : 0;
		return $term ? array_map( static fn( $h ) => VendorMaps::host( 'https://' . strtok( (string) $h, '/' ) . '/' ), UrlFinder::search_sites( $term, $brand ) ) : [];
	}

	/** Another stored page of the site, used by products, with this product's card by its code. @return array{url:string,score:int,how:string}|null */
	private static function with_card( int $id, string $host, string $url ): ?array {
		if ( UrlFinder::code_of( $id ) === '' ) {
			return null;
		}
		foreach ( array_keys( SharedPages::shared_urls() ) as $page ) {
			if ( VendorMaps::host( $page ) !== $host || UrlFinder::same_url( $page, $url ) ) {
				continue;
			}
			$pg   = PageStore::row( $page );
			$html = $pg && $pg['status'] === 'ok' ? PageStore::read( (string) $pg['html_file'] ) : '';
			$card = $html !== '' ? SharedPages::coded_card( $html, $id, $page ) : null;
			if ( $card ) {
				return [ 'url' => $page, 'score' => self::CARD_SCORE, 'how' => 'This page has its card: ' . $card['name'] ];
			}
		}
		return null;
	}

	/**
	 * Its own address guessed from the shared page's: the same folder, its name as the last part
	 * ("waves.com/bundles/diamond" → "/bundles/transform"; owner 2026-10-30: waves.com has no stored sitemap, so
	 * Waves Transform's own page was never proposed). Only when that page answers and its title names the product.
	 * A dry run reads only pages already stored. @return array{url:string,score:int,how:string}|null
	 */
	private static function sibling( string $url, string $title, string $brand, bool $dry ): ?array {
		$parts = wp_parse_url( $url );
		$path  = (string) ( $parts['path'] ?? '' );
		$dir   = (string) preg_replace( '#[^/]+/?$#', '', $path );
		if ( empty( $parts['host'] ) || $dir === '' || $dir === '/' ) {
			return null; // a page at the site's top level: its neighbours are the site's own pages, not products
		}
		$name  = PageName::of( $title, $brand );
		$words = UrlFinder::tokens( $name !== '' ? $name : $title, $brand !== '' ? UrlFinder::tokens( $brand ) : [] );
		$slugs = array_unique( array_filter( [ implode( '-', $words ), implode( '-', array_diff( $words, SharedPages::KIND_WORDS, SharedPages::OFFER_WORDS ) ) ] ) );
		$base  = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . $dir;
		$tail  = str_ends_with( $path, '/' ) ? '/' : '';
		foreach ( $slugs as $slug ) {
			$guess = $base . $slug . $tail;
			if ( UrlFinder::same_url( $guess, $url ) ) {
				continue;
			}
			$pg = PageStore::row( $guess );
			if ( ! $pg && ! $dry && self::$guessed < self::GUESS_MAX ) {
				self::$guessed++;
				$pg = PageStore::get( $guess );
			}
			if ( ! $pg ) {
				continue;
			}
			$at = (string) $pg['status'] === 'moved' && (string) ( $pg['final_url'] ?? '' ) !== '' ? (string) $pg['final_url'] : $guess;
			if ( ! in_array( (string) $pg['status'], [ 'ok', 'moved' ], true ) || UrlFinder::same_url( $at, $url ) ) {
				continue; // not there, or it leads back to the shared page
			}
			if ( UrlFinder::page_names_product( (string) $pg['title'], $title, $brand ) ) {
				return [ 'url' => $at, 'score' => self::GUESS_SCORE, 'how' => 'Its address follows the shared page’s and its title names it: ' . $pg['title'] ];
			}
		}
		return null;
	}

	/**
	 * A site's sitemap addresses indexed for UrlFinder::best_match(), once per request (some list tens of thousands):
	 * the list read for the site's map, else the copy UrlFinder keeps. Nothing is fetched here.
	 */
	private static function site_index( string $host, bool $read = false ): array {
		static $memo = [];
		if ( ! isset( $memo[ $host ] ) ) {
			$list = VendorMaps::site_urls( $host );
			$kept = get_transient( 'mmpt_sitemap_' . md5( $host ) );
			$urls = $list ? (array) $list['urls'] : ( is_array( $kept ) ? $kept : [] );
			if ( ! $urls && $read && defined( 'WP_CLI' ) && WP_CLI ) {
				$urls = UrlFinder::sitemap_urls( $host ); // the sitemap walk can take minutes, so never from a screen; a dry run reads only what's kept
			}
			$memo[ $host ] = UrlFinder::index_urls( array_values( array_filter( array_map( static fn( $u ) => is_array( $u ) ? (string) ( $u['url'] ?? '' ) : (string) $u, $urls ) ) ) );
		}
		return $memo[ $host ];
	}

	/** Never over a person's decision about that page, or an open proposal (UrlFinder's rule for card links). */
	private static function may_offer( int $id, string $url ): bool {
		$row = UrlFinder::row( $id );
		return ! $row || ( $row['status'] !== 'candidate' && ! UrlFinder::same_url( (string) $row['url'], $url ) );
	}
}
