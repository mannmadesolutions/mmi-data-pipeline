<?php
/**
 * Page types within a site's vendor map (VENDOR_MAPS_PAGE_TYPES_DESIGN.md; owner's answers 2026-10-25).
 *
 * A site whose product pages come in several kinds (uaudio.com: 9 page designs) can't be read by one set of
 * parts. A type is a named part of the site's map:
 * - a rule saying which pages it covers: a marker (an element only that kind of page has, like webscraper.io's
 *   parent selector), an address pattern, a layout (`sig`: the structure of one of the site's page layouts,
 *   SiteGaps::cluster(); a page whose main content shares SiteGaps::LAYOUT_SIM of it), or several (owner Q1: either);
 * - only the parts it reads differently (owner Q4): every other part comes from the site's map;
 * - its own status: draft, confirmed or off (owner Q5: one type at a time).
 * A page belongs to the first type, by position, whose rule fits; pages no type claims read with the site's map
 * (owner Q2), which stays as it is: a site with no types reads exactly as before.
 *
 * What the reader uses for a confirmed type: its own parts, and for the rest the site map's parts while the site
 * map is confirmed (an edit there reaches the type at once, as it does the site's other pages); while the site map
 * is a draft, the inherited parts as they were when the type was confirmed (`inherited`), so a draft never reaches
 * the reader through a type. The editor (VendorMaps::with_editor_map()) reads every type that isn't off, with the
 * site map's parts as they are in the editor.
 *
 * Layouts (owner 2026-10-31: "map those different page renders generically, with an override where the generic reader
 * gets it wrong"): the Chrome extension offers each of a site's layouts; a part changed on one (a pick, or "the generic reader
 * here") makes that layout a type (for_layout()), whose rule is the layout's structure. Its draft is read by the
 * editor and the product preview at once and by the reader once confirmed (confirm()). A type left with no part
 * of its own is removed: its pages read with the site's map again.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MapTypes {

	const DB_OPTION  = 'mmpt_map_types_db_version';
	const DB_VERSION = '2'; // 2: sig (a layout's structure)
	/** A proposed type needs at least this many of the site's stored pages (owner Q3, 2026-10-25); a layout a person chooses may be any size. */
	const MIN_PAGES = 5;
	const KEY_MAX   = 40;
	/** Pages whose type is remembered per request (a page is read by several parts in a row). */
	const MEMO_MAX = 64;

	/** Confirmed types by host, read once per request. */
	private static ?array $confirmed = null;
	private static array $memo       = [];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_vendor_map_types';
	}

	public static function installed(): bool {
		return get_option( self::DB_OPTION ) === self::DB_VERSION;
	}

	public static function maybe_install(): void {
		if ( self::installed() ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			host varchar(190) NOT NULL,
			type_key varchar(40) NOT NULL,
			name varchar(120) NOT NULL DEFAULT '',
			marker varchar(500) NOT NULL DEFAULT '',
			pattern varchar(255) NOT NULL DEFAULT '',
			sig longtext NULL,
			fields longtext NULL,
			inherited longtext NULL,
			status varchar(12) NOT NULL DEFAULT 'draft',
			reading longtext NULL,
			position int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			map_at datetime NULL,
			confirmed_at datetime NULL,
			confirmed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY host_key (host, type_key),
			KEY status (status)
			) " . $wpdb->get_charset_collate() . ';'
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) === self::table() ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/** Forget what this request read (after a change the reader must see at once). */
	public static function forget(): void {
		self::$confirmed = null;
		self::$memo      = [];
	}

	private static function decode( array $r ): array {
		foreach ( [ 'fields', 'inherited', 'reading', 'sig' ] as $k ) {
			$r[ $k ] = (array) json_decode( (string) ( $r[ $k ] ?? '' ), true );
		}
		$r['position'] = (int) $r['position'];
		return $r;
	}

	/** A site's types in order (off ones too). @return array<int,array> */
	public static function all( string $host ): array {
		if ( ! self::installed() ) {
			return [];
		}
		global $wpdb;
		return array_map( [ __CLASS__, 'decode' ], $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE host = %s ORDER BY position, id', VendorMaps::host( $host ) ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function get( string $host, string $key ): ?array {
		if ( ! self::installed() ) {
			return null;
		}
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE host = %s AND type_key = %s', VendorMaps::host( $host ), $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $r ? self::decode( $r ) : null;
	}

	/* ── What a type reads ─────────────────────────────────────────────── */

	/** The parts the reader may use: a selector, and not found broken by the health check. */
	public static function usable( array $fields ): array {
		return array_filter( $fields, static fn( $f ) => is_array( $f ) && ! empty( $f['sel'] ) && empty( $f['health']['broken'] ) );
	}

	/**
	 * A type's parts: the ones it inherits, with its own on top. Its own part marked `generic` reads that part
	 * with the generic reader on its pages (the site map's part is not used there).
	 */
	public static function effective( array $inherit, array $own ): array {
		$out = $inherit;
		foreach ( $own as $k => $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			if ( ! empty( $f['generic'] ) ) {
				unset( $out[ $k ] );
			} else {
				$out[ $k ] = $f;
			}
		}
		return self::usable( $out );
	}

	/** One type as the reader sees it. */
	private static function entry( array $t, array $inherit ): array {
		$sig = array_values( array_map( 'strval', (array) ( $t['sig'] ?? [] ) ) );
		return [ 'key' => (string) $t['type_key'], 'name' => (string) $t['name'], 'marker' => (string) $t['marker'], 'pattern' => (string) $t['pattern'], 'sig' => $sig, 'sigk' => $sig ? md5( implode( ',', $sig ) ) : '', 'fields' => self::effective( $inherit, (array) $t['fields'] ) ];
	}

	/**
	 * Confirmed types by host, for VendorMaps::confirmed(): host → [ render, default (the site map is confirmed),
	 * types ]. Inherited parts: the site map's while it is confirmed; the snapshot taken at the type's confirm otherwise.
	 * A draft type's own part put in use on its own (`live`: written to its layout's products, set_live()) is read like a
	 * confirmed type's, over the parts the site's map has in use; the draft's other parts are not.
	 */
	public static function confirmed_by_host(): array {
		if ( self::$confirmed !== null ) {
			return self::$confirmed;
		}
		self::$confirmed = [];
		if ( ! self::installed() ) {
			return self::$confirmed;
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT t.*, m.status map_status, m.render map_render, m.fields map_fields FROM ' . self::table() . ' t JOIN ' . VendorMaps::table() . " m ON m.host = t.host WHERE t.status = 'confirmed' OR ( t.status <> 'off' AND t.fields LIKE '%\"live\"%' ) ORDER BY t.host, t.position, t.id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $r ) {
			$t       = self::decode( $r );
			$site_ok = $r['map_status'] === 'confirmed';
			$site    = (array) json_decode( (string) $r['map_fields'], true );
			if ( $t['status'] !== 'confirmed' ) {
				$t['fields'] = array_filter( (array) $t['fields'], static fn( $f ) => ! empty( $f['live'] ) );
				$inherit     = self::usable( $site_ok ? $site : array_filter( $site, static fn( $f ) => ! empty( $f['live'] ) ) );
			} else {
				$inherit = $site_ok ? self::usable( $site ) : self::usable( $t['inherited'] );
			}
			$h       = (string) $r['host'];
			self::$confirmed[ $h ]            = self::$confirmed[ $h ] ?? [ 'render' => (int) $r['map_render'], 'default' => $site_ok, 'types' => [] ];
			self::$confirmed[ $h ]['types'][] = self::entry( $t, $inherit );
		}
		return self::$confirmed;
	}

	/** Every type of a site that isn't off, on the site map's parts as they are in the editor ($site_fields, usable). */
	public static function editor( string $host, array $site_fields ): array {
		$out = [];
		foreach ( self::all( $host ) as $t ) {
			if ( $t['status'] !== 'off' ) {
				$out[] = self::entry( $t, $site_fields );
			}
		}
		return $out;
	}

	/* ── Which type a page is ──────────────────────────────────────────── */

	/** The first type whose rule fits the page, or null (the site map reads it). A marker needs the page's HTML. */
	public static function match( array $types, string $url, string $html ): ?array {
		if ( ! $types ) {
			return null;
		}
		$k = md5( $url . "\0" . md5( $html ) . "\0" . wp_json_encode( array_map( static fn( $t ) => [ $t['key'], $t['marker'], $t['pattern'], $t['sigk'] ?? '' ], $types ) ) );
		if ( array_key_exists( $k, self::$memo ) ) {
			return self::$memo[ $k ] === null ? null : ( $types[ self::$memo[ $k ] ] ?? null );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$doc  = null;
		$skel = null;
		$hit  = null;
		foreach ( $types as $i => $t ) {
			$sig = (array) ( $t['sig'] ?? [] );
			if ( $t['marker'] === '' && $t['pattern'] === '' && ! $sig ) {
				continue; // no rule yet: claims nothing
			}
			if ( $t['pattern'] !== '' && ! self::path_fits( $t['pattern'], $path ) ) {
				continue;
			}
			if ( $t['marker'] !== '' ) {
				if ( $html === '' ) {
					continue; // can't tell without the page
				}
				$doc = $doc ?? VendorPage::parse( $html );
				if ( ! $doc || ! self::marker_found( $t['marker'], $doc ) ) {
					continue;
				}
			}
			if ( $sig ) {
				if ( $html === '' ) {
					continue;
				}
				$doc  = $doc ?? VendorPage::parse( $html );
				$skel = $skel ?? ( $doc ? SiteGaps::skeleton( $doc ) : [] );
				if ( ! self::alike( $skel, $sig ) ) {
					continue;
				}
			}
			$hit = $i;
			break;
		}
		if ( count( self::$memo ) >= self::MEMO_MAX ) {
			self::$memo = [];
		}
		self::$memo[ $k ] = $hit;
		return $hit === null ? null : $types[ $hit ];
	}

	/** Whether two page structures (SiteGaps::skeleton()) are one layout: they share LAYOUT_SIM of their tokens, as cluster() groups them. */
	public static function alike( array $a, array $b ): bool {
		$a     = array_flip( $a );
		$b     = array_flip( $b );
		$union = count( $a + $b );
		return $union > 0 && count( array_intersect_key( $a, $b ) ) / $union >= SiteGaps::LAYOUT_SIM;
	}

	/** Whether the page has the marker element (a CSS selector, as a part's pick is written). */
	public static function marker_found( string $marker, \DOMDocument $doc ): bool {
		$xp = VendorMaps::css_to_xpath( $marker );
		if ( ! is_string( $xp ) || $xp === '' ) {
			return false;
		}
		$n = @( new \DOMXPath( $doc ) )->query( $xp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a malformed query reads as "not found"
		return $n && $n->length > 0;
	}

	/**
	 * An address pattern against a page's path: {slug} or * is one path segment, {id} a number, ** anything;
	 * a trailing slash is optional. "/products/{slug}" fits /products/ua-610/ but not /products/ua-610/specs.
	 */
	public static function path_fits( string $pattern, string $path ): bool {
		$re = preg_quote( rtrim( $pattern, '/' ), '#' );
		$re = strtr( $re, [ '\*\*' => '.*', '\{id\}' => '[0-9]+', '\{slug\}' => '[^/]+', '\*' => '[^/]*' ] );
		return (bool) preg_match( '#^' . $re . '/?$#i', $path === '' ? '/' : $path );
	}

	/** What a site's types make the reader do, for VendorMaps::stamp() ('' with none): any change re-reads its pages. */
	public static function stamp_part( array $types ): string {
		if ( ! $types ) {
			return '';
		}
		$parts = [];
		foreach ( $types as $t ) {
			$f = array_diff_key( (array) $t['fields'], VendorMaps::RELATIONS ); // a relation part changes no page's reading
			ksort( $f );
			$parts[] = $t['key'] . '|' . $t['marker'] . '|' . $t['pattern'] . '|' . ( $t['sigk'] ?? '' ) . '|' . implode( ';', array_map( static fn( $k, $d ) => $k . '=' . $d['sel'] . '|' . ( $d['mode'] ?? '' ) . '|' . ( $d['src'] ?? '' ), array_keys( $f ), $f ) );
		}
		return 'types=' . md5( implode( "\n", $parts ) );
	}

	/* ── Layouts (the Chrome extension) ────────────────────────────────────────────── */

	/** The site's layouts as SiteGaps last grouped them: per index, its pages' addresses and its first page's structure (what cluster() compared against). */
	public static function layout_pages( string $host ): array {
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT layout, url, sig FROM ' . SiteGaps::table() . ' WHERE host = %s AND product_id = 0 AND layout >= 0 ORDER BY layout, url', VendorMaps::host( $host ) ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$i         = (int) $r['layout'];
			$out[ $i ] = $out[ $i ] ?? [ 'urls' => [], 'sig' => array_values( (array) json_decode( (string) $r['sig'], true ) ) ];
			$out[ $i ]['urls'][] = (string) $r['url'];
		}
		return $out;
	}

	/**
	 * The layout a page belongs to: its stored copy's group (SiteGaps, `how` stored), else the layout whose first page's
	 * structure the page's HTML shares (alike(), `how` drawn: the Chrome extension sends the page as drawn), else null.
	 * @return array{i:int,pages:int,how:string}|null
	 */
	public static function layout_of( string $host, string $url, string $html = '' ): ?array {
		global $wpdb;
		$host = VendorMaps::host( $host );
		$all  = self::layout_pages( $host );
		if ( ! $all ) {
			return null;
		}
		$p    = wp_parse_url( $url );
		$path = rtrim( (string) ( $p['path'] ?? '' ), '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		$ways = [];
		foreach ( [ 'https://', 'http://' ] as $scheme ) {
			foreach ( [ $host, 'www.' . $host ] as $h ) {
				$ways[] = $scheme . $h . $path;
				$ways[] = $scheme . $h . $path . '/';
			}
		}
		$i = $wpdb->get_var( $wpdb->prepare( 'SELECT layout FROM ' . SiteGaps::table() . ' WHERE host = %s AND product_id = 0 AND layout >= 0 AND url IN (' . implode( ',', array_fill( 0, count( $ways ), '%s' ) ) . ') LIMIT 1', $host, ...$ways ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $i !== null && isset( $all[ (int) $i ] ) ) {
			return [ 'i' => (int) $i, 'pages' => count( $all[ (int) $i ]['urls'] ), 'how' => 'stored' ];
		}
		$doc = $html !== '' ? VendorPage::parse( $html ) : null;
		if ( ! $doc ) {
			return null;
		}
		$sig = SiteGaps::skeleton( $doc );
		foreach ( $all as $k => $l ) {
			if ( $l['sig'] && $sig && self::alike( $l['sig'], $sig ) ) {
				return [ 'i' => (int) $k, 'pages' => count( $l['urls'] ), 'how' => 'drawn' ];
			}
		}
		return null;
	}

	/** The type whose layout rule claims a layout (by its first page's structure), or null. */
	private static function claiming( array $types, array $sig ): ?array {
		foreach ( $types as $t ) {
			if ( $t['status'] !== 'off' && $t['sig'] && $sig && self::alike( (array) $t['sig'], $sig ) ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * The type of layout $i: the one claiming it, or (with $create) a new draft whose rule is the layout's structure.
	 * @return array|null|\WP_Error
	 */
	public static function for_layout( string $host, int $i, bool $create = false ) {
		$l = self::layout_pages( $host )[ $i ] ?? null;
		if ( ! $l || ! $l['sig'] ) {
			return new \WP_Error( 'mmpt_layout', 'That layout is no longer measured: the site’s pages were grouped again. Choose the layout again.' );
		}
		$t = self::claiming( self::all( $host ), $l['sig'] );
		if ( $t || ! $create ) {
			return $t;
		}
		self::maybe_install();
		global $wpdb;
		$host = VendorMaps::host( $host );
		$key  = 'layout-' . substr( md5( implode( ',', $l['sig'] ) ), 0, 8 );
		$path = (string) wp_parse_url( $l['urls'][0], PHP_URL_PATH );
		$wpdb->insert(
			self::table(),
			[
				'host'       => $host,
				'type_key'   => $key,
				'name'       => mb_substr( sprintf( 'Pages like %s', $path !== '' ? $path : '/' ), 0, 120 ),
				'sig'        => wp_json_encode( $l['sig'] ),
				'fields'     => wp_json_encode( new \stdClass() ),
				'status'     => 'draft',
				'position'   => count( self::all( $host ) ),
				'created_at' => current_time( 'mysql', true ),
			]
		);
		self::forget();
		return self::get( $host, $key );
	}

	/**
	 * One part of a type: its own pick (sel, mode…), ['generic' => true] (the generic reader on its pages, not the
	 * site map's part) or null (as the site's other pages). A type with no part left is removed.
	 */
	public static function set_part( string $host, string $key, string $field, ?array $part ): void {
		$t = self::get( $host, $key );
		if ( ! $t ) {
			return;
		}
		$fields = (array) $t['fields'];
		if ( $part === null ) {
			unset( $fields[ $field ] );
		} else {
			$fields[ $field ] = $part;
		}
		global $wpdb;
		$relation = isset( VendorMaps::RELATIONS[ $field ] ); // names other products: no page is read differently
		if ( ! $fields ) {
			$wpdb->delete( self::table(), [ 'id' => (int) $t['id'] ] );
		} else {
			$wpdb->update( self::table(), [ 'fields' => wp_json_encode( $fields ) ] + ( $relation ? [] : [ 'map_at' => current_time( 'mysql', true ) ] ), [ 'id' => (int) $t['id'] ] );
		}
		if ( $relation ) {
			self::forget();
			VendorMaps::relation_changed( $host );
			return;
		}
		self::changed( $host );
	}

	/**
	 * Why type $t's own pick of $k may not be used ('' = it may): it reads the part on fewer of its pages than the
	 * site's map does now (maps only add, program doc §3.3).
	 */
	public static function drop( string $host, array $t, string $k ): string {
		$f    = (array) ( $t['fields'][ $k ] ?? [] );
		$site = (array) ( VendorMaps::get( $host )['fields'][ $k ] ?? [] );
		$urls = array_flip( self::urls_of( $host, $t ) );
		if ( empty( $f['sel'] ) || empty( $site['sel'] ) || ! $urls ) {
			return '';
		}
		$own = VendorMaps::test( $host, $k, (string) $f['sel'], (string) ( $f['mode'] ?? '' ), 0, '', (string) ( $f['src'] ?? '' ), $urls );
		$was = VendorMaps::test( $host, $k, (string) $site['sel'], (string) ( $site['mode'] ?? '' ), 0, '', (string) ( $site['src'] ?? '' ), $urls );
		return $own['found'] < $was['found'] ? sprintf( '%s: this layout’s pick reads it on %d of its %d pages, and the site’s map reads it on %d now. Pick it again, or set it back to the site’s map.', VendorMaps::FIELDS[ $k ][0] ?? $k, $own['found'], $own['of'], $was['found'] ) : '';
	}

	/** Put type $key's own part $k in use on its layout's pages without confirming the type (or stop: on = false). */
	public static function set_live( string $host, string $key, string $k, bool $on ): void {
		$t = self::get( $host, $key );
		if ( ! $t || empty( $t['fields'][ $k ]['sel'] ) || $t['status'] === 'confirmed' || ! empty( $t['fields'][ $k ]['live'] ) === $on ) {
			return;
		}
		$fields = (array) $t['fields'];
		if ( $on ) {
			$fields[ $k ]['live'] = current_time( 'mysql', true );
		} else {
			unset( $fields[ $k ]['live'] );
		}
		global $wpdb;
		$wpdb->update( self::table(), [ 'fields' => wp_json_encode( $fields ) ], [ 'id' => (int) $t['id'] ] );
		self::changed( $host );
	}

	/**
	 * Confirm a type (the reader reads its pages with it) or put it back to a draft. Refused while one of its own picks
	 * reads its part on fewer of its pages than the site's map does now (drop()).
	 * @return true|\WP_Error
	 */
	public static function set_status( string $host, string $key, bool $confirm ) {
		$t = self::get( $host, $key );
		if ( ! $t ) {
			return new \WP_Error( 'mmpt_layout', 'This layout has no parts of its own.' );
		}
		$row = VendorMaps::get( $host );
		if ( $confirm ) {
			foreach ( array_keys( (array) $t['fields'] ) as $k ) {
				$why = self::drop( $host, $t, (string) $k );
				if ( $why !== '' ) {
					return new \WP_Error( 'mmpt_layout_drop', $why . ' It wasn’t confirmed.' );
				}
			}
		}
		global $wpdb;
		$wpdb->update(
			self::table(),
			$confirm
				? [ 'status' => 'confirmed', 'confirmed_at' => current_time( 'mysql', true ), 'confirmed_by' => get_current_user_id(), 'inherited' => wp_json_encode( self::usable( (array) ( $row['fields'] ?? [] ) ) ) ]
				: [ 'status' => 'draft' ],
			[ 'id' => (int) $t['id'] ]
		);
		self::changed( $host );
		return true;
	}

	/** The stored pages a type's layout rule claims now (its layout's pages). @return string[] */
	public static function urls_of( string $host, array $t ): array {
		foreach ( self::layout_pages( $host ) as $l ) {
			if ( $t['sig'] && self::alike( (array) $t['sig'], $l['sig'] ) ) {
				return $l['urls'];
			}
		}
		return [];
	}

	/** After a change: the reader, the editor and the site's readings (their stamp includes the types) see it. */
	private static function changed( string $host ): void {
		self::forget();
		VendorMaps::forget_confirmed();
		VendorMaps::queue( 'refresh', VendorMaps::host( $host ) );
	}

	/**
	 * The layouts as the site's map holds them: per layout index, its pages and the type claiming it (status, own parts: `pick`
	 * with its selector or `generic`). @return array<int,array>
	 */
	public static function layouts_view( string $host ): array {
		$types = self::installed() ? self::all( $host ) : [];
		$out   = [];
		foreach ( self::layout_pages( $host ) as $i => $l ) {
			$t     = self::claiming( $types, $l['sig'] );
			$out[] = [
				'i'     => $i,
				'pages' => count( $l['urls'] ),
				'urls'  => array_slice( $l['urls'], 0, 3 ),
				'type'  => $t ? [
					'key'          => (string) $t['type_key'],
					'name'         => (string) $t['name'],
					'status'       => (string) $t['status'],
					'confirmed_at' => (string) $t['confirmed_at'],
					'own'          => array_map( static fn( $f ) => ! empty( $f['generic'] ) ? [ 'how' => 'generic' ] : [ 'how' => 'pick', 'sel' => (string) ( $f['sel'] ?? '' ), 'mode' => (string) ( $f['mode'] ?? '' ), 'found' => (int) ( $f['found'] ?? 0 ), 'of' => (int) ( $f['of'] ?? 0 ), 'live' => ! empty( $f['live'] ) ], (array) $t['fields'] ),
				] : null,
			];
		}
		return $out;
	}
}
