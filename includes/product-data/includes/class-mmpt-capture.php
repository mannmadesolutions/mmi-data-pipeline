<?php
/**
 * Pages sent from the owner's browser (Product Content handoff §9, decision D-A): a vendor page the server can't read
 * (Cloudflare challenge, 403) comes from the owner's own browser instead, through the Chrome extension (/ext/send,
 * ExtBridge::send(), which calls site(), list_post() and store() here). A stored copy goes in the page store like a
 * rendered page, and every reader uses it. No vendor login is stored and nothing gets around a block: the page is what
 * the owner's browser already shows. The old Send bookmark and its send window were retired 2026-11-03.
 *
 * REST (cookie + nonce, Rest::CAP): GET /capture/needed (the sites that refuse the server, most products first);
 * GET /capture/site?host= (what a site still needs from the browser, and the products to read again).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capture {

	const MAX_BYTES = 8000000;
	const MAX_SITE_PAGES = 300; // product pages one "send the site" asks the browser for
	const OWN_SITE      = 'That’s the store’s own page.';

	/** Read results that mean the site refused the server (PageReview's "refused"). */
	const READ_REFUSED = [ 'http_401', 'http_403', 'http_405', 'http_429', 'http_202', 'http_503' ];

	/** Page-store statuses that mean the server was refused or challenged ("error" is a dead or malformed address: a capture can't fix it). */
	const REFUSED = [ 'http_403', 'http_401', 'http_429', 'http_503', 'http_202' ];

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( Rest::CAP );
		register_rest_route( Rest::NS, '/capture/needed', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'needed' ] ] );
		register_rest_route( Rest::NS, '/capture/site', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'site' ] ] );
	}

	/** The site's own host, www or not: never a maker's page. */
	private static function own_host( string $url_or_host ): bool {
		$host = BrandSites::host( $url_or_host );
		return $host !== '' && $host === BrandSites::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * A page from the browser {url, html} (ExtBridge::send()): store the page and say what the readers find in it.
	 */
	public static function store( \WP_REST_Request $r ): \WP_REST_Response {
		$url  = esc_url_raw( trim( (string) $r->get_param( 'url' ) ) );
		$html = (string) $r->get_param( 'html' );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( self::own_host( $host ) ) {
			return new \WP_REST_Response( [ 'message' => self::OWN_SITE ], 400 );
		}
		if ( $host === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return new \WP_REST_Response( [ 'message' => 'Not a web page address.' ], 400 );
		}
		$gone = (int) $r->get_param( 'gone' );
		if ( in_array( $gone, [ 404, 410 ], true ) ) { // the browser found the page gone: recorded, so it stops counting as refused
			$url  = (string) strtok( $url, '#' );
			$page = PageStore::capture_gone( $url, $gone );
			$ids  = self::products_for( $url );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'vendor_page.capture_gone', [ 'object_type' => 'vendor_page', 'object_id' => mb_substr( $url, 0, 100 ), 'details' => [ 'code' => $gone, 'status' => $page['status'], 'products' => count( $ids ) ] ] );
			}
			Module::info( 'Vendor page gone in the browser', [ 'url' => $url, 'code' => $gone, 'status' => $page['status'], 'products' => count( $ids ) ] );
			return rest_ensure_response( [ 'url' => $url, 'status' => $page['status'], 'products' => count( $ids ) ] );
		}
		if ( $html === '' || strlen( $html ) > self::MAX_BYTES || stripos( $html, '<html' ) === false ) {
			return new \WP_REST_Response( [ 'message' => 'The page was empty or too large.' ], 400 );
		}
		if ( PageStore::is_wall( $html ) ) {
			return new \WP_REST_Response( [ 'message' => 'That is the site’s bot check, not its page. Finish the check in its tab, then send it again.' ], 400 );
		}
		$url  = (string) strtok( $url, '#' );
		$page = PageStore::capture( $url, $html );
		$read = VendorPage::extract( $html, $url );
		$req  = apply_filters( 'mmi_pipeline_page_requirements', null, $html ); // a store's requirements reader (this module reads none by itself)
		$ids  = self::products_for( $url );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_page.capture', [ 'object_type' => 'vendor_page', 'object_id' => mb_substr( $url, 0, 100 ), 'details' => [ 'bytes' => strlen( $html ), 'products' => count( $ids ) ] ] );
		}
		Module::info( 'Vendor page captured from the browser', [ 'url' => $url, 'bytes' => strlen( $html ), 'products' => count( $ids ) ] );
		return rest_ensure_response(
			[
				'url'          => $url,
				'title'        => $page['title'],
				'text_chars'   => (int) $read['text_chars'],
				'images'       => count( $read['images'] ),
				'requirements' => $req ? array_values( array_filter( [ ! empty( $req['mac'] ) ? 'Mac' : '', ! empty( $req['windows'] ) ? 'Windows' : '', ! empty( $req['linux'] ) ? 'Linux' : '' ] ) ) : [],
				'products'     => count( $ids ),
			]
		);
	}

	/** Products whose stored vendor URL, or open/approved candidate, is this page. @return int[] */
	public static function products_for( string $url ): array {
		global $wpdb;
		$a = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'vendor_product_url' AND meta_value = %s", $url ) );
		$b = $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM ' . UrlFinder::table() . " WHERE url = %s AND status IN ('candidate','approved')", $url ) );
		return array_values( array_unique( array_map( 'intval', array_merge( $a, $b ) ) ) );
	}

	/**
	 * GET /capture/needed: the sites that refuse the server, with how many verified pages of in-stock
	 * products each one refused, most products first: the list of sites to send from the browser.
	 */
	public static function needed(): \WP_REST_Response {
		global $wpdb;
		$in   = "'" . implode( "','", array_map( 'esc_sql', self::REFUSED ) ) . "'";
		// Joined by the page store's url_hash (indexed), not by the URL text: the text join scanned postmeta for every
		// stored page and took ~70 s (2026-10-25); same rows.
		$rows = $wpdb->get_results(
			"SELECT pg.host, COUNT(DISTINCT pg.url) pages, COUNT(DISTINCT u.post_id) products, MIN(p.post_title) example
			 FROM {$wpdb->postmeta} u
			 JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( u.meta_value ) AND pg.url = u.meta_value
			 JOIN {$wpdb->posts} p ON p.ID = u.post_id AND p.post_type = 'product' AND p.post_status = 'publish'
			 JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_stock_status' AND s.meta_value = 'instock'
			 WHERE u.meta_key = 'vendor_product_url' AND pg.status IN ({$in}) AND pg.method <> 'capture'
			 GROUP BY pg.host
			 LIMIT 200",
			ARRAY_A
		);
		$sites = [];
		foreach ( $rows as $x ) { // www.example.com and example.com are one site
			$host = BrandSites::host( (string) $x['host'] );
			$site = $sites[ $host ] ?? [ 'host' => $host, 'pages' => 0, 'products' => 0, 'example' => '' ];
			$site['pages']    += (int) $x['pages'];
			$site['products'] += (int) $x['products'];
			$site['example']   = $site['example'] ?: html_entity_decode( (string) $x['example'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$sites[ $host ]    = $site;
		}
		usort( $sites, static fn( $a, $b ) => [ $b['products'], $a['host'] ] <=> [ $a['products'], $b['host'] ] );
		return rest_ensure_response( array_values( $sites ) );
	}

	/**
	 * GET /capture/site?host=: what the browser can send for a site: whether its page list is cached
	 * (and from where), and the product pages on it whose copy the server couldn't read (no good stored
	 * copy), each with the products using it, most products first (at most MAX_SITE_PAGES).
	 */
	public static function site( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;
		$host = BrandSites::host( (string) $r->get_param( 'host' ) );
		if ( self::own_host( $host ) ) {
			return new \WP_REST_Response( [ 'message' => self::OWN_SITE ], 400 );
		}
		if ( ! BrandSites::public_host( $host ) ) {
			return new \WP_REST_Response( [ 'message' => 'Not a site address.' ], 400 );
		}
		$list = Store::state( VendorCatalog::STATE . '_hosts' )[ $host ] ?? [];
		$like = '%://' . $wpdb->esc_like( $host ) . '/%';
		$www  = '%://www.' . $wpdb->esc_like( $host ) . '/%';
		// …and the site's bare address ("https://example.com", no slash): /capture/needed counted it, Send never asked for it (2026-10-27)
		$bare = [ "http://{$host}", "https://{$host}", "http://www.{$host}", "https://www.{$host}" ];
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.meta_value url, GROUP_CONCAT( DISTINCT u.post_id ) ids
				 FROM {$wpdb->postmeta} u
				 JOIN {$wpdb->posts} p ON p.ID = u.post_id AND p.post_type = 'product' AND p.post_status = 'publish'
				 LEFT JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( u.meta_value )
				 WHERE u.meta_key = 'vendor_product_url' AND ( u.meta_value LIKE %s OR u.meta_value LIKE %s OR u.meta_value IN ( %s, %s, %s, %s ) )
				   AND ( pg.url IS NULL OR pg.status <> 'ok' )
				 GROUP BY u.meta_value
				 ORDER BY COUNT( DISTINCT u.post_id ) DESC, u.meta_value
				 LIMIT " . (int) self::MAX_SITE_PAGES,
				$like,
				$www,
				...$bare
			),
			ARRAY_A
		);
		// Products whose read still says the site refused, while a good copy of their page is stored (a maker site that refuses the server,
		// 2026-10-19: 52 products read "refused" since Oct 5 over copies stored on Oct 7): read again with the rest.
		$refused = "'" . implode( "','", array_map( 'esc_sql', self::READ_REFUSED ) ) . "'";
		$reread  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT d.product_id
				 FROM " . Descriptions::table() . " d
				 JOIN {$wpdb->postmeta} u ON u.post_id = d.product_id AND u.meta_key = 'vendor_product_url'
				 JOIN {$wpdb->posts} p ON p.ID = d.product_id AND p.post_type = 'product' AND p.post_status = 'publish'
				 JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( u.meta_value ) AND pg.status = 'ok'
				 WHERE d.fetch_status IN ({$refused}) AND ( u.meta_value LIKE %s OR u.meta_value LIKE %s OR u.meta_value IN ( %s, %s, %s, %s ) )
				 LIMIT 2000",
				$like,
				$www,
				...$bare
			)
		);
		return rest_ensure_response(
			[
				'host'    => $host,
				'reread'  => array_map( 'intval', $reread ),
				'list'    => [ 'count' => (int) ( $list['count'] ?? 0 ), 'source' => (string) ( $list['source'] ?? '' ), 'at' => (string) ( $list['at'] ?? '' ), 'blocked' => ! empty( $list['blocked'] ) ],
				'pages'   => array_map( static fn( $x ) => [ 'url' => (string) $x['url'], 'ids' => array_map( 'intval', explode( ',', (string) $x['ids'] ) ) ], $rows ),
			]
		);
	}

	/** {host, urls} (ExtBridge::send()): the site's product pages from its sitemap, read in the owner's browser. */
	public static function list_post( \WP_REST_Request $r ): \WP_REST_Response {
		$host = BrandSites::host( (string) $r->get_param( 'host' ) );
		$urls = array_slice( array_values( array_filter( array_map( 'strval', (array) $r->get_param( 'urls' ) ) ) ), 0, 20000 );
		if ( self::own_host( $host ) ) {
			return new \WP_REST_Response( [ 'message' => self::OWN_SITE ], 400 );
		}
		if ( ! BrandSites::public_host( $host ) || ! $urls ) {
			return new \WP_REST_Response( [ 'message' => 'Not a site address, or no pages sent.' ], 400 );
		}
		$urls = array_values( array_filter( $urls, static fn( $u ) => BrandSites::host( $u ) === $host ) ); // only its own pages
		$n    = VendorCatalog::store_list( $host, array_map( static fn( $u ) => esc_url_raw( $u, [ 'http', 'https' ] ), $urls ) );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_page.capture_list', [ 'object_type' => 'vendor_site', 'object_id' => $host, 'details' => [ 'sent' => count( $urls ), 'kept' => $n ] ] );
		}
		Module::info( 'Vendor page list sent from the browser', [ 'host' => $host, 'sent' => count( $urls ), 'kept' => $n ] );
		return rest_ensure_response( [ 'host' => $host, 'sent' => count( $urls ), 'kept' => $n ] );
	}

}
