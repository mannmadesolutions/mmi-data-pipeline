<?php
/**
 * Links in a bundle's description, and no others (owner 2026-11-04: "when product descriptions of bundles/collections/etc
 * contain hyperlinks/hrefs, replace them with host URL links. Never embed non host URL links.").
 *
 * A bundle's maker page links the products it holds. The reader takes words, not links, so the description stays plain,
 * and the links are drawn when the product page is shown (PRODUCT_DATA_PROGRAM.md §8.3): each link on the maker's page
 * that leads to a product we sell becomes a link to that product here, as many times as the maker linked it. A link to
 * nothing we sell stays plain words. Nothing is written to post_content, so feeds and Reverb keep the plain text, the
 * links follow URL changes, and a product going off sale loses its link at once.
 *
 * Off-site links can't be stored in a product at all: guard() unwraps them on every save, whoever writes, and render()
 * unwraps any that got in past it. The words stay.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DescLinks {

	const META  = '_mmpt_desc_links'; // what map() found { sig, links[{text,id,n}], none[] }
	const MARK  = 'mmpt-link';        // a link drawn here: drawn once, whichever way the content arrives
	const KINDS = [ 'A', 'C', 'D', 'E' ];  // bundles, collections, packs of packs, choice bundles (Bundles): products that list others
	/** Anchors outside the page's content: its menus, footers, side blocks and other products' cards. */
	const OUTSIDE = '/(?:^|[-_\s])(?:nav|navigation|menu|header|footer|breadcrumbs?|sidebar|related|upsell|crosssell|minicart|block-search)(?:$|[-_\s])/i';
	/** Offers aren't the product a maker's link means, unless its words say so. */
	const OFFER = '/\b(?:upgrades?|crossgrades?|renewals?|subscriptions?|bundles?|collections?|education|edu|student|nfr)\b/i';
	/** Words that ask rather than name. */
	const CTA = [ 'learn', 'more', 'explore', 'discover', 'read', 'click', 'here', 'details', 'view', 'see', 'buy', 'shop', 'now', 'get', 'info', 'the', 'and', 'for', 'with' ];
	const TEXT_MIN = 3;
	const TEXT_MAX = 80;

	/** @var array<string,array> a site's products, for this request */
	private static $sites = [];

	public static function register_hooks(): void {
		add_filter( 'post_content', [ __CLASS__, 'render_field' ], 20, 2 ); // get_post_field() for display: Bricks' Product content element, which never runs the_content
		add_filter( 'the_content', [ __CLASS__, 'render' ], 19 ); // WooCommerce's description tab; before "What's included" (BundleInherit, 20)
		add_filter( 'wp_insert_post_data', [ __CLASS__, 'guard' ], 10, 1 );
	}

	/** The product lists other products: a bundle, collection or pack of packs. */
	public static function lists( int $id ): bool {
		global $wpdb;
		return in_array( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT kind FROM ' . \MMI_Pipeline_Bundles::table() . ' WHERE bundle_id = %d', $id ) ), self::KINDS, true );
	}

	/**
	 * The product's links, kept per stored page and description. { links: [ { text, id, n } ], none: string[] }
	 * @return array{links:array,none:array}
	 */
	public static function map( int $id ): array {
		$url  = self::page_of( $id );
		$page = $url !== '' ? PageStore::row( $url ) : null;
		$sig  = md5( implode( '|', [ 3, $url, $page['html_file'] ?? '', $page['fetched_at'] ?? '', (string) get_post_field( 'post_modified_gmt', $id ), (string) ( ChoiceBundles::kept( $id )['at'] ?? '' ) ] ) );
		$kept = get_post_meta( $id, self::META, true );
		if ( is_array( $kept ) && ( $kept['sig'] ?? '' ) === $sig ) {
			return $kept;
		}
		$out = [ 'sig' => $sig ] + self::read( $id, $url, $page );
		update_post_meta( $id, self::META, $out );
		return $out;
	}

	/** The product's verified vendor page, or ''. */
	private static function page_of( int $id ): string {
		$ok = in_array( strtoupper( (string) get_post_meta( $id, 'vendor_product_url_verified', true ) ), [ 'TRUE', '1' ], true );
		return $ok ? (string) get_post_meta( $id, 'vendor_product_url', true ) : '';
	}

	/**
	 * What the stored page links, among the words of the description. A choice bundle's choices count as the maker's
	 * links too: its page draws them with scripts, so the stored copy has none (ChoiceBundles::read()).
	 */
	private static function read( int $id, string $url, ?array $page ): array {
		$none = [ 'links' => [], 'none' => [] ];
		$text = Descriptions::plain( (string) ( get_post( $id )->post_content ?? '' ) );
		if ( $text === '' ) {
			return $none;
		}
		$seen = [];
		foreach ( ChoiceBundles::is( $id ) ? ChoiceBundles::items( $id ) : [] as $it ) {
			$words = trim( (string) $it['name'] );
			if ( mb_strlen( $words ) >= self::TEXT_MIN && mb_strlen( $words ) <= self::TEXT_MAX && self::has_words( $text, $words ) ) {
				$seen[ $words ] = [ 'n' => preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $words, '/' ) . '(?![\p{L}\p{N}])/u', $text ), 'href' => (string) $it['url'] ]; // its line in the list, and any mention before it
			}
		}
		$doc = $page && $page['status'] === 'ok' && $page['html_file'] !== '' ? VendorPage::parse( PageStore::read( (string) $page['html_file'] ) ) : null;
		foreach ( $doc ? $doc->getElementsByTagName( 'a' ) : [] as $a ) {
			$words = trim( (string) preg_replace( '/\s+/u', ' ', $a->textContent ) );
			$href  = trim( (string) $a->getAttribute( 'href' ) );
			$len   = mb_strlen( $words );
			if ( $href === '' || $href[0] === '#' || $len < self::TEXT_MIN || $len > self::TEXT_MAX || self::outside( $a ) || ! self::has_words( $text, $words ) ) {
				continue;
			}
			$seen[ $words ]['n']    = ( $seen[ $words ]['n'] ?? 0 ) + 1;
			$seen[ $words ]['href'] = $seen[ $words ]['href'] ?? VendorPage::absolute_url( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url );
		}
		$out = $none;
		foreach ( $seen as $words => $s ) {
			$to = self::resolve( (string) $words, (string) $s['href'], $id );
			if ( $to ) {
				$out['links'][] = [ 'text' => (string) $words, 'id' => $to, 'n' => (int) $s['n'] ];
			} else {
				$out['none'][] = (string) $words;
			}
		}
		return $out;
	}

	private static function outside( \DOMElement $a ): bool {
		for ( $n = $a->parentNode; $n instanceof \DOMElement; $n = $n->parentNode ) {
			if ( in_array( strtolower( $n->tagName ), [ 'nav', 'header', 'footer', 'aside' ], true ) || preg_match( self::OUTSIDE, $n->getAttribute( 'class' ) . ' ' . $n->getAttribute( 'id' ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function has_words( string $text, string $words ): bool {
		return (bool) preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $words, '/' ) . '(?![\p{L}\p{N}])/u', $text );
	}

	/** The product a maker's link means: the one whose verified page it is, else the one of this brand with that name. 0: none, or no telling which. */
	private static function resolve( string $words, string $href, int $self ): int {
		$host = VendorMaps::host( $href );
		$key  = static fn( string $u ) => preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) ) . rtrim( strtolower( (string) wp_parse_url( $u, PHP_URL_PATH ) ), '/' );
		$on   = [];
		self::$sites[ $host ] ??= $host !== '' ? VendorMaps::site_products( $host ) : [];
		foreach ( self::$sites[ $host ] as $p ) {
			if ( $key( (string) $p['url'] ) === $key( $href ) ) {
				$on[ (int) $p['id'] ] = html_entity_decode( (string) $p['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return self::best( $on ?: self::named( $words, $self ), $words, $self );
	}

	/** Products of $self's brand whose title holds these words. @return array<int,string> id → title */
	private static function named( string $words, int $self ): array {
		global $wpdb;
		$brands = wp_get_post_terms( $self, 'product_brand', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $brands ) || ! $brands ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE %s LIMIT 60", '%' . $wpdb->esc_like( $words ) . '%' ), ARRAY_A );
		$out  = [];
		foreach ( $rows as $r ) {
			$terms = wp_get_post_terms( (int) $r['ID'], 'product_brand', [ 'fields' => 'ids' ] );
			if ( ! is_wp_error( $terms ) && array_intersect( $terms, $brands ) && self::has_words( (string) $r['post_title'], $words ) ) {
				$out[ (int) $r['ID'] ] = html_entity_decode( (string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $out;
	}

	/** Of the candidates, what is on sale and not an offer; the shortest title is the plain product. A tie is no answer. */
	private static function best( array $titles, string $words, int $self ): int {
		unset( $titles[ $self ] );
		$sold = array_filter( $titles, static fn( $t, $id ) => self::names( $words, $t ) && get_post_status( $id ) === 'publish' && ProductPreview::skip( (int) $id ) === '' && ( preg_match( self::OFFER, $words ) || ! preg_match( self::OFFER, $t ) ), ARRAY_FILTER_USE_BOTH );
		if ( ! $sold ) {
			return 0;
		}
		asort( $sold );
		uasort( $sold, static fn( $a, $b ) => mb_strlen( $a ) <=> mb_strlen( $b ) );
		$ids = array_keys( $sold );
		return count( $ids ) > 1 && mb_strlen( $sold[ $ids[0] ] ) === mb_strlen( $sold[ $ids[1] ] ) ? 0 : (int) $ids[0];
	}

	/** A link's words name the product: not a call to action that leads to it ("explore", "Learn More"). */
	private static function names( string $words, string $title ): bool {
		$bare = static fn( string $s ) => (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( $s ) );
		if ( str_contains( $bare( $title ), $bare( $words ) ) ) {
			return true;
		}
		$hits = array_filter( (array) preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $words ) ), static fn( $w ) => mb_strlen( (string) $w ) >= 3 && ! in_array( $w, self::CTA, true ) && str_contains( $bare( $title ), (string) $w ) );
		return (bool) $hits;
	}

	/** The post_content filter: a field read for display, on that product's own page. */
	public static function render_field( $value, $post_id = 0 ) {
		return is_string( $value ) && (int) $post_id === (int) get_queried_object_id() ? self::render( $value ) : $value;
	}

	/** the_content on a product's own page: off-site links unwrapped, a bundle's links drawn. */
	public static function render( string $content ): string {
		if ( is_admin() || ! is_singular( 'product' ) ) {
			return $content;
		}
		$id = (int) get_queried_object_id(); // Bricks draws the content outside the loop: the queried product is the test
		if ( ! $id || str_contains( $content, self::MARK ) || ( in_the_loop() && (int) get_the_ID() !== $id ) ) {
			return $content;
		}
		$content = self::unwrap( $content );
		return self::lists( $id ) ? self::linked( $content, self::map( $id )['links'] ) : $content;
	}

	/** Each link's words become a link to its product, as often as the maker linked them, outside any link there already. */
	public static function linked( string $html, array $links ): string {
		$live = [];
		foreach ( $links as $l ) {
			if ( get_post_status( (int) $l['id'] ) === 'publish' && ProductPreview::skip( (int) $l['id'] ) === '' ) {
				$live[] = $l + [ 'href' => (string) get_permalink( (int) $l['id'] ) ];
			}
		}
		if ( ! $live ) {
			return $html;
		}
		usort( $live, static fn( $a, $b ) => mb_strlen( $b['text'] ) <=> mb_strlen( $a['text'] ) ); // "GeoShred Studio" before "GeoShred"
		$left = array_column( $live, 'n', 'text' );
		$in_a = 0;
		$out  = '';
		foreach ( (array) preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE ) as $part ) {
			if ( $part !== '' && $part[0] === '<' ) {
				$in_a += preg_match( '/^<a[\s>]/i', $part ) ? 1 : ( preg_match( '#^</a>#i', $part ) ? -1 : 0 );
				$out  .= $part;
				continue;
			}
			if ( $in_a === 0 ) {
				foreach ( $live as $l ) {
					$part = (string) preg_replace_callback(
						'/(?<![\p{L}\p{N}>"])' . preg_quote( esc_html( $l['text'] ), '/' ) . '(?![\p{L}\p{N}<"])/u',
						static function ( $m ) use ( &$left, $l ) {
							if ( $left[ $l['text'] ] < 1 ) {
								return $m[0];
							}
							--$left[ $l['text'] ];
							return '<a class="' . self::MARK . '" href="' . esc_url( $l['href'] ) . '">' . $m[0] . '</a>';
						},
						$part
					);
				}
			}
			$out .= $part;
		}
		return $out;
	}

	/** Links to any other site lose their <a>; their words stay. Links here, on the page and to mail or a phone stay. */
	public static function unwrap( string $html ): string {
		if ( stripos( $html, '<a' ) === false ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#<a\b([^>]*)>(.*?)</a>#is',
			static function ( $m ) {
				$href = preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $m[1], $h ) ? trim( html_entity_decode( $h[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
				return self::own( $href ) ? $m[0] : $m[2];
			},
			$html
		);
	}

	/** An address on this site, or no site at all. */
	private static function own( string $href ): bool {
		if ( $href === '' || preg_match( '/^(?:#|mailto:|tel:)/i', $href ) ) {
			return true;
		}
		$host = (string) wp_parse_url( str_starts_with( $href, '//' ) ? 'https:' . $href : $href, PHP_URL_HOST );
		$bare = static fn( string $h ) => preg_replace( '/^www\./', '', strtolower( $h ) );
		return $host === '' || $bare( $host ) === $bare( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/** wp_insert_post_data: whoever saves a product, no off-site link is stored in it. */
	public static function guard( array $data ): array {
		if ( ( $data['post_type'] ?? '' ) !== 'product' ) {
			return $data;
		}
		foreach ( [ 'post_content', 'post_excerpt' ] as $k ) {
			if ( stripos( (string) ( $data[ $k ] ?? '' ), '<a' ) !== false ) {
				$data[ $k ] = wp_slash( self::unwrap( wp_unslash( (string) $data[ $k ] ) ) );
			}
		}
		return $data;
	}
}
