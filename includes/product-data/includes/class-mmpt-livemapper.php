<?php
/**
 * Live mapper (VENDOR_MAPS_HANDOFF.md V3; owner 2026-10-11): map a vendor site's parts on its live
 * page, in the owner's own browser, the way Web Scraper does. The stored-page editor shows the
 * page with scripts off, so a site that builds its pages with JavaScript (some makers) looks
 * different there from what the owner sees.
 *
 * The Product Data Chrome extension loads the mapper panel (assets/js/map-select.js, then
 * assets/js/live-mapper.js) into the page and sends its requests through ExtBridge (/ext/map), which calls hello() and
 * act() here. The old mapping bookmarklet, its mapper window on the store and the /maps/live routes it used
 * were retired 2026-11-03 (owner: the extension replaced them; remove competing features).
 *
 * Every test and save first stores the page as the browser shows it (PageStore::capture()), under
 * its own address and every product address that names the same page, so the server tests the
 * selector on what the owner sees. Saving goes through VendorMaps::set_field() and its guards (a
 * part that finds nothing on the stored pages is refused). A pick the panel thinks looks wrong
 * for its part is saved with that warning (owner 2026-10-11: allowed with a warning).
 *
 * hello( request {url} ): the site's map; act( request {url, html, field, sel, mode, save, warn, where, layout} ). layout:
 * test on that layout's pages and save the pick as the layout's own (MapTypes), the site map untouched.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LiveMapper {

	const MAX_BYTES   = 8000000;
	const READS_PAGES = 200; // of a layout's pages, enough to say how it is stored

	/** The site's map as the panel shows it (request {url}), and how many of our products use this page. */
	public static function hello( \WP_REST_Request $r ): \WP_REST_Response {
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$host = VendorMaps::site_of( $url );
		$row  = VendorMaps::get( $host );
		$ids  = array_merge( [], ...array_values( self::same_page( $url ) ) );
		return rest_ensure_response(
			[
				'host'     => $host,
				'away'     => $host !== VendorMaps::host( $url ), // a page kept for the site, on another of its hosts
				'map'      => $row ? [ 'status' => VendorMaps::status_of( $row ), 'fields' => self::fields_out( self::as_here( array_diff_key( $row['fields'], VendorMaps::NOT_MAPPED ), $host, $url ) ) ] : null,
				'parts'    => array_map( static fn( $f ) => $f[0], VendorMaps::parts() ),
				'modes'    => VendorMaps::MODES,
				'products' => count( array_unique( $ids ) ),
				'pages'    => $row ? (int) ( $row['stats']['pages'] ?? 0 ) : 0,
			]
		);
	}

	/**
	 * Store the page as the browser shows it, then test a part's selector on the
	 * site's stored pages, or save it to the map (save=1).
	 */
	public static function act( \WP_REST_Request $r ): \WP_REST_Response {
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$host  = VendorMaps::site_of( $url );
		$away  = $host !== VendorMaps::host( $url );
		$field = sanitize_key( (string) $r->get_param( 'field' ) );
		$sel   = trim( sanitize_text_field( (string) $r->get_param( 'sel' ) ) );
		$mode  = sanitize_key( (string) $r->get_param( 'mode' ) );
		if ( ! VendorMaps::get( $host ) ) {
			return new \WP_REST_Response( [ 'message' => 'This site has no vendor map: none of our products has a verified page on it.' ], 404 );
		}
		if ( ! isset( VendorMaps::MODES[ $field ] ) ) {
			return new \WP_REST_Response( [ 'message' => 'This part can\'t be mapped yet.' ], 400 );
		}
		$mode = in_array( $mode, VendorMaps::MODES[ $field ], true ) ? $mode : VendorMaps::MODES[ $field ][0];
		if ( $away && $field !== 'requirements' ) {
			return new \WP_REST_Response( [ 'message' => 'Only system requirements can be picked on this page.' ], 400 );
		}
		$mode = $away ? 'page' : $mode; // the part picked here, for every product: never the page taken whole
		if ( $sel === '' ) {
			return new \WP_REST_Response( [ 'message' => 'Pick something on the page first.' ], 400 );
		}
		$xp = VendorMaps::css_to_xpath( $sel, $field === 'pictures' );
		if ( is_wp_error( $xp ) ) {
			return new \WP_REST_Response( [ 'message' => $xp->get_error_message() ], 400 );
		}
		$stored = [ 'stored' => [], 'kept' => [] ];
		$html   = (string) $r->get_param( 'html' );
		if ( $html !== '' ) {
			$stored = self::store( $url, $html );
			if ( is_wp_error( $stored ) ) {
				return new \WP_REST_Response( [ 'message' => $stored->get_error_message() ], 400 );
			}
		}
		$src    = $mode === 'page' ? $url : ''; // "use this page for every product": the page the person is on
		$sample = self::stored_twin( $url );
		$layout = is_numeric( $r->get_param( 'layout' ) ) ? (int) $r->get_param( 'layout' ) : -1;
		if ( ! $r->get_param( 'save' ) ) {
			$only = $layout >= 0 ? array_flip( MapTypes::layout_pages( $host )[ $layout ]['urls'] ?? [] ) : null;
			$t    = VendorMaps::test( $host, $field, $sel, $mode, VendorMaps::TEST_MAX, $sample, $src, $only );
			return rest_ensure_response( $t + [ 'stored' => count( $stored['stored'] ), 'kept' => count( $stored['kept'] ), 'layout' => $layout ] );
		}
		$warn = mb_substr( sanitize_text_field( (string) $r->get_param( 'warn' ) ), 0, 300 );
		$res  = VendorMaps::set_field( $host, $field, $sel, $mode, sanitize_text_field( (string) $r->get_param( 'where' ) ), $src, $warn, 'live', $layout );
		if ( is_wp_error( $res ) ) {
			return new \WP_REST_Response( [ 'message' => $res->get_error_message() ], 400 );
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', $layout >= 0 ? 'vendor_maps.layout_field' : 'vendor_maps.live', [ 'object_type' => 'vendor_map', 'object_id' => $host, 'details' => [ 'field' => $field, 'mode' => $mode, 'warned' => $warn !== '', 'layout' => $layout, 'via' => 'live' ] ] );
		}
		Module::info( 'Vendor map part saved from the live page', [ 'host' => $host, 'field' => $field, 'warned' => $warn !== '', 'layout' => $layout ] );
		$own = null;
		if ( $layout >= 0 ) {
			foreach ( MapTypes::layouts_view( $host ) as $l ) {
				$own = $l['i'] === $layout ? ( $l['type']['own'][ $field ] ?? null ) : $own;
			}
		}
		return rest_ensure_response( [ 'field' => $layout >= 0 ? $own : ( self::as_here( $res['fields'], $host, $url )[ $field ] ?? null ), 'layout' => $layout, 'status' => VendorMaps::status_of( $res ), 'fields' => self::fields_out( self::as_here( $res['fields'], $host, $url ) ), 'stored' => count( $stored['stored'] ), 'kept' => count( $stored['kept'] ) ] );
	}

	/** A vendor page address from the panel: http(s), not this site, without its #fragment. @return string|\WP_Error */
	private static function page_url( string $url ) {
		$url  = esc_url_raw( trim( $url ), [ 'http', 'https' ] );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$own  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host === '' || VendorMaps::host( $host ) === VendorMaps::host( $own ) ) {
			return new \WP_Error( 'mmpt_live_url', 'Not a vendor page address.' );
		}
		return (string) strtok( $url, '#' );
	}

	/** Host without www, path without a trailing slash: "example.com/product" for https://www.example.com/product/ and https://example.com/product. */
	private static function key( string $url ): string {
		$p = wp_parse_url( $url );
		return VendorMaps::host( (string) ( $p['host'] ?? '' ) ) . rtrim( (string) ( $p['path'] ?? '' ), '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	/**
	 * Product vendor addresses that name this page, however they are written (www, trailing slash).
	 * @return array<string,int[]> address => product ids
	 */
	public static function same_page( string $url ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'vendor_product_url' AND meta_value LIKE %s LIMIT 5000",
				'%' . $wpdb->esc_like( VendorMaps::host( $url ) ) . '%'
			),
			ARRAY_A
		);
		$key = self::key( $url );
		$out = [];
		foreach ( $rows as $x ) {
			if ( self::key( (string) $x['meta_value'] ) === $key ) {
				$out[ (string) $x['meta_value'] ][] = (int) $x['post_id'];
			}
		}
		return $out;
	}

	/**
	 * How the pages a pick on this page reaches are stored, so the mapper judges the pick against what the reader has
	 * (owner 2026-11-04: one maker's stored pages come from a browser, so "isn't in the page as delivered" was false,
	 * and the delivered page's few classes forced selectors that took 15 other elements). By the page's layout, not this
	 * page alone: Test and Save store the browser's copy of the page they run on, so one tested page of soundiron.com says
	 * nothing of the 80 others, whose copies are as delivered.
	 * @return array{built:bool,html:string} built: most of them hold what scripts built; html: this page's stored copy, if it does
	 */
	public static function reads( string $url ): array {
		global $wpdb;
		$host = VendorMaps::host( $url );
		$own  = array_values( array_unique( array_merge( [ $url ], array_keys( self::same_page( $url ) ) ) ) );
		$lay  = MapTypes::layout_of( $host, $url );
		$urls = $lay ? ( MapTypes::layout_pages( $host )[ $lay['i'] ]['urls'] ?? [] ) : [];
		$urls = array_slice( $urls ?: $own, 0, self::READS_PAGES );
		$hash = array_map( 'md5', $urls );
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT method, COUNT(*) AS n FROM ' . PageStore::table() . " WHERE status = 'ok' AND url_hash IN (" . implode( ',', array_fill( 0, count( $hash ), '%s' ) ) . ') GROUP BY method', $hash ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$plain = 0;
		$built = 0;
		foreach ( $rows as $x ) {
			$x['method'] === 'http' ? $plain += (int) $x['n'] : $built += (int) $x['n'];
		}
		if ( $built <= $plain ) {
			return [ 'built' => false, 'html' => '' ];
		}
		foreach ( $own as $u ) {
			$row = PageStore::row( $u );
			if ( $row && $row['status'] === 'ok' && $row['method'] !== 'http' ) {
				return [ 'built' => true, 'html' => PageStore::read( (string) $row['html_file'] ) ];
			}
		}
		return [ 'built' => true, 'html' => '' ];
	}

	/** The address the page store keeps this page under for the tests' sample (a product's own address first). */
	private static function stored_twin( string $url ): string {
		$same = array_keys( self::same_page( $url ) );
		return $same ? (string) $same[0] : $url;
	}

	/**
	 * Store the page as the browser shows it, under its own address and every product address that
	 * names the same page, so the tests and the readers see what the person saw. An address whose
	 * stored copy reads more keeps it (PageStore::capture(), the keep-better-copy rule).
	 * @return array{stored:string[],kept:string[],reads?:array,reads_new?:array}|\WP_Error the addresses stored and kept
	 */
	public static function store( string $url, string $html ) {
		if ( strlen( $html ) > self::MAX_BYTES || stripos( $html, '<html' ) === false ) {
			return new \WP_Error( 'mmpt_live_html', 'The page was empty or too large to store.' );
		}
		$out = [ 'stored' => [], 'kept' => [] ];
		foreach ( array_unique( array_merge( [ $url ], array_keys( self::same_page( $url ) ) ) ) as $u ) {
			$res = PageStore::capture( $u, $html );
			if ( ! empty( $res['kept'] ) ) {
				$out['kept'][] = $u;
				$out          += [ 'reads' => $res['reads'], 'reads_new' => $res['reads_new'] ];
			} else {
				$out['stored'][] = $u;
			}
		}
		return $out;
	}

	/** On a page kept for the site, its requirements as this page holds them: this page's own pick, or none. */
	private static function as_here( array $fields, string $host, string $url ): array {
		if ( $host === VendorMaps::host( $url ) || ! isset( $fields['requirements'] ) ) {
			return $fields;
		}
		$p = VendorMaps::kept_pick( $host, $url );
		if ( ! $p ) {
			unset( $fields['requirements'] );
			return $fields;
		}
		$fields['requirements'] = $p + $fields['requirements'];
		return $fields;
	}

	/** A map's parts as the panel shows them. */
	private static function fields_out( array $fields ): array {
		$out = [];
		foreach ( $fields as $k => $f ) {
			$out[ $k ] = array_intersect_key( (array) $f, array_flip( [ 'sel', 'mode', 'found', 'of', 'source', 'warn', 'via', 'src' ] ) ) + [ 'broken' => ! empty( $f['health']['broken'] ) ];
		}
		return $out;
	}
}
