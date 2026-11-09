<?php
/**
 * Vendor maps (owner 2026-10-09; VENDOR_MAPS_HANDOFF.md): per vendor site,
 * the product URL pattern and where its product pages keep the pictures,
 * the description and the system requirements, so every page on that site
 * is read the same way.
 *
 * Maps are drafted automatically from the site's stored pages (draft()),
 * tested on every stored page (test()), and used by the reader only once a
 * person confirms them (profile(): VendorPage::extract() tries the mapped
 * element first and keeps the generic reader as the fallback, so a confirmed
 * map never reads a page worse). Requirements may "follow" a link (a
 * separate requirements page). A confirmed map may ask for pages to be read
 * in the server's browser (render_for()).
 *
 * V2: features (a list), version, manuals (links) and videos (embeds) are
 * mapped too. Coverage counts what a confirmed map finds (extras()), and
 * Scrape fills a product's empty features, manuals and videos from it
 * (fill_product(); never over stored values or a field lock). A nightly
 * health check (health()) re-tests every confirmed field on the stored pages;
 * a field that stops matching is marked broken, the reader stops using it,
 * and the list shows the site as "Stopped matching" with the field named.
 *
 * Selectors are a CSS subset (tag, #id, .class, [attr], [attr="v"], descendant
 * and child) turned into XPath (css_to_xpath()); the screenshot script uses
 * the same CSS in Chromium. Drafting, screenshots and health checks run in one
 * detached WP-CLI runner (`wp mmpt maps run`), queued from the tab or the
 * daily health event.
 *
 * Maps and Vendor pages are one process (owner 2026-10-09): the site is the
 * parent, its products the children. A site's list numbers are kept current
 * (refresh(): what the reader finds now, from the same cached readings the
 * Vendor pages icons use, and who gets vendor pictures) after a confirm, an
 * edit, a re-read and the nightly check; `map_at` says when what the reader
 * does on a site last changed, so each product shows whether it was read with
 * the current map. Guards (owner: "If I am not using the UI correctly, then
 * that is a result of a bad UI design"): a part that finds nothing on the
 * stored pages can't be saved or confirmed, a re-read needs a confirmed map,
 * and the health check flags a confirmed part that finds nothing.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VendorMaps {

	const DB_OPTION    = 'mmpt_vendor_maps_db_version';
	const DB_VERSION   = '3'; // 3: gaps (SiteGaps: layouts, shared-page relations, the list's summary)
	const STATE        = 'mmi_admin_vendor_maps_state';
	const LOCK         = 'mmpt_maps_runner';
	const SAMPLE       = 40;   // stored pages a draft reads
	const TEST_MAX     = 80;   // stored pages a test reads (from a web request too)
	const MIN_TEXT     = 200;
	const SHOT_SCRIPT  = 'scripts/product-content/map-sample.js';
	const SHOT_TIMEOUT = 75;
	const RENDER_SAMPLE = 8;  // pages a draft reads in the browser for a site that is a JavaScript app
	const RENDER_CHECK  = 3;  // stored pages render_check() draws in the browser to compare
	const RENDER_EVERY  = 30 * DAY_IN_SECONDS; // how often refresh() checks a site again (render_check())
	const RENDER_OPTION = 'mmpt_render_checked'; // host => when render_check() last looked
	const JS_SHELL     = '/enable javascript|javascript (is )?required|requires javascript|<div id="(root|app|__next)"><\/div>/i';

	/** Fields a map can hold, in display order: label, chip, mappable now. */
	const FILL_SRC = '_mmpt_fill_src'; // the page each filled part was read from { part => url }
	const SEL_MAX = 4000; // a part's whole selector: up to LIMITS.picks (60) picked elements on a bundle's page (each one ≤300)
	const TAB_NAME_MAX = 40; // a tab's name in step:tab("…") (tab_step())
	const FIELDS = [
		'pictures'     => [ 'Pictures', 'Pic', true ],
		'description'  => [ 'Description', 'Desc', true ],
		'features'     => [ 'Features', 'Feat', true ],
		'requirements' => [ 'System requirements', 'Req', true ],
		'version'      => [ 'Version', 'Ver', true ],
		'manual'       => [ 'Manual', 'Man', true ],
		'videos'       => [ 'Videos', 'Vid', true ],
		'audio'        => [ 'Audio demos', 'Aud', true ],
		'disclaimer'   => [ 'Disclaimer', 'Note', true ],
	];
	const MODES       = [ 'pictures' => [ 'img' ], 'description' => [ 'text' ], 'features' => [ 'list' ], 'requirements' => [ 'text', 'follow', 'page', 'sub' ], 'version' => [ 'text' ], 'manual' => [ 'links', 'page', 'sub' ], 'videos' => [ 'embed', 'page', 'sub' ], 'audio' => [ 'embed', 'page', 'sub' ], 'disclaimer' => [ 'text' ] ];
	/**
	 * Parts a vendor often keeps off the product page (owner 2026-10-21): on one page for the whole site (`page`, src =
	 * its address) or on each product's own sub-page (`sub`, src = the path after the product's address, overloud.com's
	 * "/compatibility" and "/demoaudio"). sel `body` reads that page as the generic reader does.
	 */
	const ELSEWHERE   = [ 'page', 'sub' ];
	const PICKED_HEAD = '<h2>System requirements</h2>'; // a picked part read under a heading of its own
	const PART_BREAK  = '<p>Requirements:</p>';         // between kept pages' parts: what one left labelled doesn't run on into the next
	const KEPT_PAGES  = 4;  // pages a site's requirements can be kept on, each with its own pick (`src`, `sel`, then `also`)
	const SUB_RE      = '#^/(?:[A-Za-z0-9._~%-]|\{slug\})+(?:/(?:[A-Za-z0-9._~%-]|\{slug\})+){0,3}$#'; // "/compatibility", or with {slug}: "/manuals/{slug}"
	const SITEMAP_DIR = 'mmpt-site-pages'; // each site's whole sitemap list (private dir), for the sub-page finder
	const SUB_SAMPLE  = 3;    // sub-pages read at once when a part is set to them (the runner reads the rest)
	const HISTORY_OPTION = 'mmpt_map_history'; // host → the last changes to its parts, for Undo
	const HISTORY_MAX    = 30;
	const MEASURE_EVERY  = 10 * MINUTE_IN_SECONDS; // a stale draft is measured again this often while its gaps are looked at
	/**
	 * Registry concepts Scrape fills (empty fields only): from a confirmed map, else from the generic reader once
	 * `fields:vendor_page` is approved (owner 2026-10-21). The generic reader's version is used only on a page about
	 * this product alone: a shared page shows the current version, a product may be an older one.
	 */
	const FILLS       = [ 'features' => 'features', 'manual' => 'manuals', 'videos' => 'videos', 'audio' => 'audio', 'version' => 'version', 'disclaimer' => 'disclaimer' ];
	/**
	 * Parts the mapper doesn't offer (owner 2026-11-01): Features are the description's bullet lists (VendorPage::fields(),
	 * read from the map's Description), and a product's version is the version of the page it is mapped to. A map's
	 * picks for them stay stored but are not read, shown, checked or held against Confirm.
	 */
	const NOT_MAPPED  = [ 'features' => true, 'version' => true ];
	/** WooCommerce size fields the generic reader fills on physical products (Field Locks names). */
	const SIZE_FIELDS = [ 'weight' => '_weight', 'length' => '_length', 'width' => '_width', 'height' => '_height' ];
	const REQ_TEXT    = '/system\s*requirement|requirements|compatib|specification/i';
	const VERSION_RE  = '/\b(?:version|ver\.?|v)\s*:?\s*(\d{1,3}(?:\.\d{1,4}){1,3})(?![\d.]*\d)/i';
	const BARE_VERSION = '/^\D{0,12}?(\d{1,3}(?:\.\d{1,4}){1,3})(?![\d.]*\d)/';  // a short element holding just the number ("2.1.4", "v 3.0")
	const VERSION_LABEL = '/^(?:current |latest )?(?:software |plug-?in )?version\s*:?$/i';
	const MANUAL_TEXT = '/manual|user.?guide|operation|documentation|handbook|reference guide|quick.?start|getting started/i'; // not ".pdf" alone: a rig list or brochure is a PDF too
	const CHROME_SIG  = '/(^|[\s.#_-])(footer|menu|nav|navbar|breadcrumbs?|cookie|newsletter|social|site-header|megamenu)([\s._-]|$)/i'; // a draft never sits in site chrome
	const VIDEO_RE    = '~(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?(?:[^"\'\s]*&(?:amp;)?)?v=|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])|player\.vimeo\.com/video/(\d{5,})|//vimeo\.com/(\d{5,})~i';
	const VIDEO_ATTR  = '/youtube|video.?id|videoid|yt.?id|embed.?id/i';  // attributes that hold a bare YouTube id (lite-youtube, data-video-id)
	const CHROME_AXIS = 'ancestor::nav or ancestor::header or ancestor::footer or ancestor::aside or ancestor::script or ancestor::style or ancestor::noscript';
	const HEALTH_HOOK = 'mmpt_maps_health';
	const HEALTH_MIN  = 3;    // stored pages a field needs before a check can call it broken
	const HEALTH_DROP = 0.5;  // broken when it now works on under half the share of pages it did when a person saw it
	const HEALTH_WAIT = 3 * HOUR_IN_SECONDS; // the check waits this long at most for the nightly read to finish
	const REFRESH_BUDGET = 600.0; // seconds refresh() may spend reading pages a map change made stale (runner only)
	const CHROME_CLASS = '/^(js-|is-|has-|active|current|lazy|loaded|entered|lazyloaded|swiper-slide-(active|next|prev|visible)|slick-(active|current|cloned)|wp-image-|attachment-|size-|aos|animate|fade|show|hidden|visible|selected|first|last|odd|even)|\d{3,}/i';

	/** Set while measuring what the generic reader finds without any map. */
	public static bool $bypass = false;

	private static ?array $confirmed = null;

	/** Hosts whose map says to read their pages in the browser, confirmed or not (render_for()). */
	private static ?array $renders = null;

	/** Set while measuring what confirming a draft would make the reader find (measure_preview()); stands in for the confirmed maps. */
	private static ?array $preview = null;

	/**
	 * Picks not saved yet (field → part, or ['generic' => true]), laid over the map that reads a page while the editor
	 * previews a product (with_editor_map()): the product column shows what Done would give before anything is saved
	 * (owner 2026-10-31). Set by with_trial() only.
	 */
	private static array $trial = [];

	/**
	 * Set by with_editor_parts(): with_editor_map() takes the editor's copy of these parts only, and every other part as
	 * the reader has it now (confirmed or live), so a fill of one part shows and writes that part alone (PartFill).
	 */
	private static ?array $editor_only = null;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_vendor_maps';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  host varchar(190) NOT NULL,
  status varchar(12) NOT NULL DEFAULT 'draft',
  pattern varchar(255) NOT NULL DEFAULT '',
  render tinyint(1) NOT NULL DEFAULT 0,
  fields longtext,
  coverage longtext,
  stats longtext,
  shot longtext,
  reading longtext,
  gaps longtext,
  drafted_at datetime DEFAULT NULL,
  checked_at datetime DEFAULT NULL,
  confirmed_at datetime DEFAULT NULL,
  confirmed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  map_at datetime DEFAULT NULL,
  PRIMARY KEY  (host),
  KEY status (status)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	public static function register_hooks(): void {
		self::maybe_install();
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( self::HEALTH_HOOK, [ __CLASS__, 'queue_health' ] );
		ProductPreview::register_hooks(); // the editor's Product page view: a product page rendered with this map's values
		PartFill::register_hooks(); // one part written to the site's other products, and put in use (owner 2026-11-01)
	}

	public static function host( string $url_or_host ): string {
		$h = str_contains( $url_or_host, '/' ) ? (string) wp_parse_url( $url_or_host, PHP_URL_HOST ) : $url_or_host;
		return preg_replace( '/^www\./', '', strtolower( trim( $h ) ) );
	}

	/**
	 * The maker site whose map a page serves: its own host, or, on a subdomain with no map of its own, the site it belongs
	 * to (owner 2026-11-06: help.uaudio.com keeps uaudio.com's system requirements for all its products).
	 */
	public static function site_of( string $url_or_host ): string {
		$host = self::host( $url_or_host );
		if ( substr_count( $host, '.' ) < 2 || self::get( $host ) ) {
			return $host;
		}
		$parent = substr( $host, strpos( $host, '.' ) + 1 );
		return self::get( $parent ) ? $parent : $host;
	}

	/* ── Reading a map ─────────────────────────────────────────────────── */

	/** The parts the mapper offers: FIELDS without NOT_MAPPED. @return array<string,array> */
	public static function parts(): array {
		return array_diff_key( self::FIELDS, self::NOT_MAPPED );
	}

	/** @return array<string,mixed>|null the row, decoded */
	public static function get( string $host ): ?array {
		self::maybe_install();
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE host = %s', self::host( $host ) ), ARRAY_A );
		return $r ? self::decode( $r ) : null;
	}

	private static function decode( array $r ): array {
		foreach ( [ 'fields', 'coverage', 'stats', 'shot', 'reading', 'gaps' ] as $k ) {
			$r[ $k ] = (array) json_decode( (string) ( $r[ $k ] ?? '' ), true );
		}
		$r['render'] = (int) $r['render'];
		return $r;
	}

	/**
	 * The confirmed maps, host => render + the fields the reader uses (one query per request).
	 * A field the health check found broken is left out: the generic reader reads that part again.
	 * A draft's part put in use on its own (`live`, PartFill: written to the site's products) is read like a confirmed
	 * map's; the draft's other parts are not.
	 */
	private static function confirmed(): array {
		if ( self::$preview !== null ) {
			return self::$preview;
		}
		if ( self::$confirmed === null ) {
			global $wpdb;
			self::$confirmed = [];
			if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
				foreach ( $wpdb->get_results( 'SELECT host, render, status, fields FROM ' . self::table() . " WHERE status = 'confirmed' OR ( status <> 'off' AND fields LIKE '%\"live\"%' )", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
					$all    = $r['status'] === 'confirmed';
					$fields = array_filter( (array) json_decode( (string) $r['fields'], true ), static fn( $f ) => ! empty( $f['sel'] ) && empty( $f['health']['broken'] ) && ( $all || ! empty( $f['live'] ) ) );
					$fields = array_diff_key( $fields, self::NOT_MAPPED );
					self::$confirmed[ $r['host'] ] = [ 'render' => (int) $r['render'], 'fields' => $fields ];
				}
				// Confirmed page types (MapTypes): a site may have one while its own map is still a draft (its other pages: the generic reader).
				foreach ( MapTypes::confirmed_by_host() as $h => $c ) {
					self::$confirmed[ $h ] = ( self::$confirmed[ $h ] ?? [ 'render' => $c['render'], 'fields' => [] ] ) + [ 'types' => $c['types'] ];
				}
			}
		}
		return self::$confirmed;
	}

	/** Forget the confirmed maps read this request (after a change the reader must see at once). */
	public static function forget_confirmed(): void {
		self::$confirmed = null;
		MapTypes::forget();
	}

	/**
	 * The map the reader uses for one page: its site's, or that of the page type claiming it (MapTypes: a marker
	 * needs $html), or null. [ render, fields, type? ].
	 */
	/** The parts the map that reads this page has (the editor's map inside with_editor_map()). @return string[] */
	public static function mapped_parts( string $url, string $html = '' ): array {
		return array_keys( (array) ( self::map_for( $url, $html )['fields'] ?? [] ) );
	}

	private static function map_for( string $url_or_host, string $html = '' ): ?array {
		$m = self::confirmed()[ self::host( $url_or_host ) ] ?? null;
		if ( $m && ! empty( $m['types'] ) ) {
			$t = MapTypes::match( $m['types'], str_contains( $url_or_host, '/' ) ? $url_or_host : '', $html );
			$m = $t ? [ 'render' => $m['render'], 'fields' => $t['fields'], 'type' => $t['key'] ] : $m;
		}
		if ( ! self::$trial || self::$preview === null ) {
			return $m ? [ 'fields' => array_diff_key( (array) $m['fields'], self::NOT_MAPPED ) ] + $m : null; // a layout's picks too
		}
		$m = $m ?? [ 'render' => 0, 'fields' => [] ];
		foreach ( self::$trial as $k => $f ) {
			if ( ! empty( $f['generic'] ) ) {
				unset( $m['fields'][ $k ] );
			} else {
				$m['fields'][ $k ] = $f;
			}
		}
		$m['fields'] = array_diff_key( (array) $m['fields'], self::NOT_MAPPED );
		return $m;
	}

	/** The parts of the map that reads this page (a layout type's where one claims it), as the reader uses them. */
	public static function fields_for( string $url, string $html ): array {
		return (array) ( self::map_for( $url, $html )['fields'] ?? [] );
	}

	/**
	 * Picks not saved yet, as the editor sends them (JSON field → { sel, mode, src } or { generic: true }), checked
	 * the way set_field() checks a save; anything not understood is left out. @return array<string,array>
	 */
	public static function trial_parse( string $json ): array {
		$out = [];
		foreach ( (array) json_decode( $json, true ) as $k => $f ) {
			if ( ! is_string( $k ) || ! isset( self::MODES[ $k ] ) || ! is_array( $f ) ) {
				continue;
			}
			if ( ! empty( $f['generic'] ) ) {
				$out[ $k ] = [ 'generic' => true ];
				continue;
			}
			$sel  = trim( (string) preg_replace( '/\s+/', ' ', sanitize_text_field( (string) ( $f['sel'] ?? '' ) ) ) );
			$mode = in_array( (string) ( $f['mode'] ?? '' ), self::MODES[ $k ], true ) ? (string) $f['mode'] : self::MODES[ $k ][0];
			if ( $sel === '' || mb_strlen( $sel ) > self::SEL_MAX || is_wp_error( self::css_to_xpath( $sel, $k === 'pictures' ) ) ) {
				continue;
			}
			$src       = in_array( $mode, self::ELSEWHERE, true ) ? (string) ( $f['src'] ?? '' ) : '';
			$out[ $k ] = [ 'sel' => $sel, 'mode' => $mode ] + ( $src !== '' ? [ 'src' => $mode === 'page' ? esc_url_raw( $src ) : sanitize_text_field( $src ) ] : [] );
		}
		return $out;
	}

	/** Run $fn with $trial (trial_parse()) laid over the editor's map (the product preview). @return mixed what $fn returns */
	public static function with_trial( array $trial, callable $fn ) {
		$was         = self::$trial;
		self::$trial = $trial;
		try {
			return $fn();
		} finally {
			self::$trial = $was;
		}
	}

	/** A site's map as it is in the editor, standing in for the confirmed maps: its parts (or $fields) and its types. */
	private static function editor_entry( array $row, ?array $fields = null ): array {
		$own = MapTypes::usable( (array) $row['fields'] );
		return [ 'render' => (int) $row['render'], 'fields' => $fields ?? $own, 'types' => MapTypes::editor( $row['host'], $own ) ];
	}

	/**
	 * A short stamp of what a host's confirmed map makes the reader do ('' without one).
	 * Coverage keeps it with each page's reading, so confirming, editing or a broken field
	 * makes the page be read again even when the vendor's page itself didn't change.
	 */
	public static function stamp( string $url_or_host ): string {
		if ( self::$bypass ) {
			return '';
		}
		$m = self::confirmed()[ self::host( $url_or_host ) ] ?? null;
		if ( ! $m || ( ! $m['fields'] && empty( $m['types'] ) ) ) {
			return '';
		}
		$parts = [ $m['render'] ];
		$skip  = MapCheck::skip_stamp( $url_or_host ); // the site's "not product pictures" list too (only when it has one: no re-read for the rest)
		if ( $skip !== '' ) {
			$parts[] = 'skip=' . $skip;
		}
		foreach ( $m['fields'] as $f => $d ) {
			$parts[] = $f . '=' . $d['sel'] . '|' . ( $d['mode'] ?? '' ) . ( ! empty( $d['src'] ) ? '|' . $d['src'] : '' ) . ( ! empty( $d['also'] ) ? '|' . wp_json_encode( $d['also'] ) : '' );
		}
		$types = MapTypes::stamp_part( (array) ( $m['types'] ?? [] ) ); // a site's page types: any change re-reads its pages
		if ( $types !== '' ) {
			$parts[] = $types;
		}
		return substr( md5( implode( "\n", $parts ) ), 0, 8 );
	}

	/**
	 * VendorPage's profile for a page from its site's confirmed map (or its page type's, given the page's $html):
	 * XPath queries for the description and the pictures. Null without a confirmed map.
	 */
	public static function profile( string $url_or_host, string $html = '' ): ?array {
		if ( self::$bypass ) {
			return null;
		}
		$m = self::map_for( $url_or_host, $html );
		if ( ! $m ) {
			return null;
		}
		$p = [];
		foreach ( [ 'description' => 'description', 'pictures' => 'images' ] as $f => $key ) {
			$sel = (string) ( $m['fields'][ $f ]['sel'] ?? '' );
			$xp  = $sel !== '' ? self::css_to_xpath( $sel, $f === 'pictures' ) : '';
			if ( is_string( $xp ) && $xp !== '' ) {
				$p[ $key ] = [ $xp ];
			}
		}
		if ( isset( $p['images'] ) && ( $m['fields']['pictures']['source'] ?? '' ) === 'person' ) {
			$p['images_only'] = true; // a person's pick says what the pictures are; a drafted one only adds to what the reader finds
		}
		return $p ?: null;
	}

	/**
	 * Read this host's pages in the server's browser: its map says the site draws its pages with scripts, confirmed
	 * or not (owner 2026-11-01: airmusictech's draft read its Gatsby pages without their titles and main pictures).
	 */
	public static function render_for( string $url_or_host ): bool {
		$h = self::host( $url_or_host );
		if ( ! empty( self::confirmed()[ $h ]['render'] ) ) {
			return true;
		}
		if ( self::$renders === null ) {
			global $wpdb;
			self::$renders = get_option( self::DB_OPTION ) === self::DB_VERSION ? array_flip( $wpdb->get_col( 'SELECT host FROM ' . self::table() . " WHERE render = 1 AND status <> 'off'" ) ) : []; // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return isset( self::$renders[ $h ] );
	}

	/**
	 * Does the site draw what matters with scripts? Up to RENDER_CHECK stored pages read as plain HTML are drawn in the
	 * server's browser (nothing stored) and read both ways. Where most draw more (a main picture, a title, a quarter
	 * more text), the site's pages are read in the browser from now on (render = 1) and queued to be read again that way
	 * (read only: nothing is written to products). CLI: the browser runs only there.
	 * @return array{host:string, render?:bool, tried?:int, better?:int, pages?:array, queued?:int, skipped?:string}
	 */
	public static function render_check( string $host ): array {
		$row = self::get( $host );
		if ( ! $row ) {
			return [ 'host' => self::host( $host ), 'skipped' => 'no map' ];
		}
		if ( (int) $row['render'] ) {
			return [ 'host' => $row['host'], 'skipped' => 'already read in the browser' ];
		}
		$all   = self::pages( $row['host'] );
		$plain = array_values( array_filter( self::readable( $all, 0 ), static fn( $p ) => $p['method'] === 'http' ) );
		if ( ! $plain ) {
			return [ 'host' => $row['host'], 'skipped' => 'no page read as plain HTML' ];
		}
		$step   = count( $plain ) / min( count( $plain ), self::RENDER_CHECK );
		$sample = array_map( static fn( $i ) => $plain[ (int) floor( $i * $step ) ], range( 0, min( count( $plain ), self::RENDER_CHECK ) - 1 ) );
		$out    = [ 'host' => $row['host'], 'tried' => 0, 'better' => 0, 'pages' => [] ];
		foreach ( $sample as $p ) {
			$b = PageStore::browse( (string) $p['url'] );
			if ( $b['status'] !== 'ok' ) {
				$out['pages'][ $p['url'] ] = $b['status'];
				continue;
			}
			$was  = self::drawn( (string) PageStore::read( (string) $p['file'] ), (string) $p['url'] );
			$now  = self::drawn( $b['html'], (string) $p['url'] );
			// More, and nothing less: a browser copy with fewer pictures or text never counts (librewave.com 2026-11-01: Emerald
			// Flute drew a title but 2 of its 4 pictures, and switched the site).
			$less = $now['pics'] < $was['pics'] || $now['text'] < $was['text'] * 0.95;
			$more = ! $less && ( $now['pics'] >= $was['pics'] + 2 || ( $was['blank'] && $now['pics'] > $was['pics'] ) || $now['text'] >= $was['text'] * 1.25 + 200 || ( $now['h1'] && ! $was['h1'] ) );
			$out['tried']++;
			$out['better']             += $more ? 1 : 0;
			$out['pages'][ $p['url'] ] = sprintf( 'plain %d pictures (%d empty), %d characters%s; browser %d pictures, %d characters%s', $was['pics'], $was['blank'], $was['text'], $was['h1'] ? ', a title' : '', $now['pics'], $now['text'], $now['h1'] ? ', a title' : '' );
		}
		$out['render'] = $out['tried'] > 0 && $out['better'] * 2 > $out['tried'];
		if ( $out['tried'] ) {
			$seen                 = (array) get_option( self::RENDER_OPTION, [] );
			$seen[ $row['host'] ] = time();
			update_option( self::RENDER_OPTION, $seen, false );
		}
		if ( $out['render'] ) {
			self::update( $row['host'], [ 'render' => 1 ] );
			self::$renders = null;
			$ids           = array_values( array_unique( array_map( static fn( $p ) => (int) $p['id'], array_filter( $all, static fn( $p ) => $p['status'] === 'ok' && $p['method'] === 'http' ) ) ) );
			$out['queued'] = $ids ? Scraper::enqueue( $ids, 'read', true, 'nightly' ) : 0; // read again, in the browser now (Scraper::process_one())
			Scraper::kick();
			self::audit( 'vendor_maps.render', $row['host'], [ 'tried' => $out['tried'], 'better' => $out['better'], 'queued' => $out['queued'] ] );
		}
		return $out;
	}

	/**
	 * What a page holds as stored: its pictures (distinct addresses, lazy ones included, icons in SVG not), picture
	 * slots with no address yet (a script fills them: airmusictech's main picture), the description's characters
	 * without a map, and whether it has a title (h1).
	 */
	private static function drawn( string $html, string $url ): array {
		self::$bypass = true;
		try {
			$x = $html !== '' ? VendorPage::extract( $html, $url ) : [ 'text_chars' => 0 ];
		} finally {
			self::$bypass = false;
		}
		$pics  = [];
		$blank = 0;
		foreach ( preg_match_all( '~<img\b[^>]*>~i', $html, $m ) ? $m[0] : [] as $tag ) {
			$u = '';
			foreach ( [ 'data-src', 'data-lazy-src', 'data-original', 'src', 'srcset', 'data-srcset' ] as $a ) {
				if ( preg_match( '~\s' . $a . '\s*=\s*["\']\s*([^"\'\s,]+)~i', $tag, $mm ) && ! str_starts_with( $mm[1], 'data:' ) ) {
					$u = $mm[1];
					break;
				}
			}
			if ( $u === '' ) {
				++$blank;
			} elseif ( ! preg_match( '~\.svg(\?|$)~i', $u ) ) {
				$pics[ strtok( $u, '?' ) ] = true;
			}
		}
		return [ 'pics' => count( $pics ), 'blank' => $blank, 'text' => (int) $x['text_chars'], 'h1' => (bool) preg_match( '~<h1\b[^>]*>\s*(?:<[^>]+>\s*)*[^<\s]~i', $html ) ];
	}

	/**
	 * The HTML PageRequirements::extract() should read for a page: the mapped
	 * element, or the page its mapped link opens (fetched only when $fetch,
	 * i.e. during Scrape; else only if already stored). The whole page without
	 * a confirmed requirements mapping or when the mapped part isn't there.
	 */
	public static function requirements_html( string $url, string $html, bool $fetch = false ): string {
		// Content held in page data is read as the page shows it (waves' Tech Specs JSON → HTML): the line reader
		// took the raw JSON as the CPU before (2026-10-18; nothing stored had it).
		$raw  = $html;
		$html = VendorPage::script_content( $html );
		$f = self::$bypass ? null : ( self::map_for( $url, $raw )['fields']['requirements'] ?? null );
		if ( ! $f || empty( $f['sel'] ) ) {
			return $html;
		}
		$r = self::eval_requirements( $html, $url, (string) $f['sel'], (string) ( $f['mode'] ?? 'text' ), $fetch, (string) ( $f['src'] ?? '' ) );
		return $r['html'] !== '' ? $r['html'] : $html;
	}

	/**
	 * What a confirmed map's manual, video and audio parts hold on one page
	 * (only the fields it maps and that aren't broken).
	 * @return array{features?:string[], version?:string, manual?:array<int,array{label:string,url:string}>, videos?:string[]}
	 */
	public static function extras( string $url, string $html, bool $fetch = false ): array {
		$fields = self::$bypass ? [] : array_intersect_key( self::map_for( $url, $html )['fields'] ?? [], array_flip( [ 'manual', 'videos', 'audio', 'disclaimer' ] ) ); // features and version: the generic reader (NOT_MAPPED)
		if ( ! $fields || $html === '' ) {
			return [];
		}
		$out = [];
		foreach ( $fields as $f => $d ) {
			if ( ! empty( $d['sel'] ) && empty( $d['health']['broken'] ) ) {
				$out[ $f ] = self::part_values( $f, $d, $url, $html, $fetch ); // the product page, or where the map says it is kept
			}
		}
		return $out;
	}

	/**
	 * A part's values from one selector on a parsed page (read_part()): what the gaps check a selector they offer with
	 * (SiteGaps::seen()). Requirements: the platforms PageRequirements::extract() reads from what it takes (mac, windows, linux).
	 */
	public static function read_with( string $field, string $sel, \DOMDocument $doc, string $url ) {
		if ( $field === 'requirements' ) {
			$html = (string) self::eval_requirements_dom( $doc, $url, $sel, 'text', false )['html'];
			$req  = $html !== '' && class_exists( PageRequirements::class ) ? PageRequirements::extract( $html ) : null;
			return $req ? array_values( (array) $req['platforms'] ) : []; // an OS a picked part only names counts (Requirements::PICKED)
		}
		$xp = self::css_to_xpath( $sel );
		return is_wp_error( $xp ) ? [] : self::read_part( $field, new \DOMXPath( $doc ), $doc, $xp, $url );
	}

	/**
	 * One mapped part's values on a parsed page: features → list items, version → the number,
	 * manual → links, videos → watch URLs. Empty when the part isn't there.
	 * @return string[]|string|array<int,array{label:string,url:string}>
	 */
	private static function read_part( string $field, \DOMXPath $x, \DOMDocument $doc, string $xp, string $url ) {
		$nodes = @$x->query( $xp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a malformed query reads as "not found"
		$list  = [];
		foreach ( $nodes ?: [] as $i => $el ) {
			if ( $i >= 20 || ! $el instanceof \DOMElement ) {
				break;
			}
			$list[] = $el;
		}
		$clean = static fn( string $t ) => trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		switch ( $field ) {
			case 'disclaimer': // a product's note: the picked elements' words, links and emphasis included
				$top = array_filter( $list, static fn( $el ) => ! array_filter( $list, static fn( $o ) => $o !== $el && $el->getNodePath() !== $o->getNodePath() && str_starts_with( (string) $el->getNodePath(), (string) $o->getNodePath() . '/' ) ) );
				return trim( implode( ' ', array_map( static fn( $el ) => $clean( $el->textContent ), $top ) ) );
			case 'features':
				// The first matching element whose list has 3+ real items (the element itself may be the list).
				// A list that reads as system requirements is skipped: tabs often style both lists alike.
				// List items matched one by one read as their list (those of one parent together, where the first stands;
				// 2026-10-30: picking three items in the map editor promised three, and each read as an empty list of its
				// own). map-select.js featureVerdicts() mirrors this for the stored view's outlines.
				// Feature cards (2026-10-31: acondigital's "Feature Highlights" are boxes, not a list): elements holding no
				// list read one item per card ("Title: text"), cards of one parent together, or a picked box's repeated
				// children. Only where no list gives 3 items, and then the biggest group: a card pick never takes a page's
				// list away (most acondigital pages have cards above the tabs' lists). Of one parent, not of one kind: by kind,
				// overloud's content columns read install steps and a disclaimer as features (2026-10-31); text picked inside
				// cards is offered the cards instead (map editor, "Use the cards").
				$cands = [];
				$cards = [];
				$at    = [];
				$cat   = [];
				foreach ( $list as $el ) {
					if ( $el->nodeName === 'li' ) {
						$p                     = (string) $el->parentNode->getNodePath(); // object ids of DOM wrappers get reused
						$at[ $p ]              = $at[ $p ] ?? count( $cands );
						$cands[ $at[ $p ] ][] = $el;
						continue;
					}
					if ( ! in_array( $el->nodeName, [ 'ul', 'ol' ], true ) && ! $x->query( './/li', $el )->length ) {
						$kids = self::card_children( $el );
						if ( $kids ) {
							$cards[] = $kids;
							continue;
						}
						$p                     = (string) $el->parentNode->getNodePath();
						$cat[ $p ]             = $cat[ $p ] ?? count( $cards );
						$cards[ $cat[ $p ] ][] = $el;
						continue;
					}
					$cands[] = [ $el ];
				}
				$best = [];
				foreach ( $cands as $group ) {
					$items = [];
					$lis   = [];
					foreach ( $group as $el ) {
						$lis = array_merge( $lis, $el->nodeName === 'li' ? [ $el ] : iterator_to_array( in_array( $el->nodeName, [ 'ul', 'ol' ], true ) ? $x->query( './li', $el ) : $x->query( './/li', $el ) ) );
					}
					foreach ( $lis as $li ) {
						$t = $clean( $li->textContent );
						if ( mb_strlen( $t ) >= 3 && mb_strlen( $t ) <= 400 && ! VendorPage::is_chrome( $t ) ) {
							$items[ $t ] = true;
						}
					}
					if ( VendorPage::looks_like_requirements( implode( ' ', array_keys( $items ) ) ) ) {
						continue;
					}
					if ( count( $items ) > count( $best ) ) {
						$best = $items;
					}
					if ( count( $best ) >= 3 ) {
						break;
					}
				}
				if ( count( $best ) < 3 ) {
					$most = [];
					foreach ( $cards as $group ) {
						$items = [];
						foreach ( $group as $el ) {
							$t = self::card_text( $el );
							if ( mb_strlen( $t ) >= 3 && mb_strlen( $t ) <= 400 && ! VendorPage::is_chrome( $t ) ) {
								$items[ $t ] = true;
							}
						}
						if ( count( $items ) > count( $most ) && ! VendorPage::looks_like_requirements( implode( ' ', array_keys( $items ) ) ) ) {
							$most = $items;
						}
					}
					if ( count( $most ) >= 3 ) {
						$best = $most;
					}
				}
				return array_slice( array_keys( $best ), 0, 40 );
			case 'version':
				foreach ( array_slice( $list, 0, 5 ) as $el ) {
					$t = $clean( $el->textContent );
					if ( mb_strlen( $t ) <= 40 && preg_match( self::BARE_VERSION, $t, $m ) ) {
						return $m[1];
					}
					if ( preg_match( self::VERSION_RE, mb_substr( $t, 0, 600 ), $m ) ) {
						return $m[1];
					}
				}
				return '';
			case 'manual':
				$links = [];
				foreach ( $list as $el ) {
					foreach ( $el->nodeName === 'a' ? [ $el ] : $x->query( './/a[@href]', $el ) as $a ) {
						$href  = VendorPage::absolute_url( html_entity_decode( trim( (string) $a->getAttribute( 'href' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url );
						$label = $clean( $a->textContent );
						if ( ! preg_match( '#^https?://#i', $href ) || ! preg_match( self::MANUAL_TEXT, $label . ' ' . $href ) || isset( $links[ $href ] ) ) {
							continue;
						}
						$links[ $href ] = [ 'label' => mb_substr( $label !== '' ? $label : 'Manual', 0, 120 ), 'url' => $href ];
					}
				}
				return array_slice( array_values( $links ), 0, 10 );
			case 'videos':
				$ids = [];
				foreach ( $list as $el ) {
					$frag = (string) $doc->saveHTML( $el );
					if ( preg_match_all( self::VIDEO_RE, $frag, $m, PREG_SET_ORDER ) ) {
						foreach ( $m as $v ) {
							if ( ( $v[1] ?? '' ) !== '' ) {
								$ids[ 'https://www.youtube.com/watch?v=' . $v[1] ] = true;
							} else {
								$ids[ 'https://vimeo.com/' . ( ( $v[2] ?? '' ) !== '' ? $v[2] : $v[3] ) ] = true;
							}
						}
					}
					// lite-youtube / data-video-id: a bare id in an attribute named for videos.
					foreach ( $x->query( './/@*|@*', $el ) as $attr ) {
						if ( preg_match( self::VIDEO_ATTR, $attr->nodeName ) && preg_match( '/^[A-Za-z0-9_-]{11}$/', trim( $attr->nodeValue ) ) && ! preg_match( '/^[a-z]+(?:-[a-z]+)*$/', trim( $attr->nodeValue ) ) ) {
							$ids[ 'https://www.youtube.com/watch?v=' . trim( $attr->nodeValue ) ] = true;
						}
					}
				}
				return array_slice( array_keys( $ids ), 0, 20 );
			case 'audio':
				// The generic reader's audio rules (SoundCloud players and tracks, audio files) inside the picked part.
				$frag = implode( '', array_map( static fn( $el ) => (string) $doc->saveHTML( $el ), $list ) );
				$fv   = $frag !== '' ? VendorPage::fields( $frag, $url, [ 'blocks' => [] ] ) : [ 'audio' => [] ];
				return array_slice( array_map( static fn( $l ) => [ 'label' => (string) $l['label'], 'url' => (string) $l['value'] ], $fv['audio'] ), 0, 10 );
		}
		return [];
	}

	/** Elements a card's words never come from, and the ones that start a new line of them (VendorMaps::card_text()). */
	const CARD_SKIP   = [ 'script', 'style', 'noscript', 'template', 'svg', 'button', 'select', 'br' ];
	const CARD_BLOCKS = [ 'div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article', 'header', 'footer', 'aside', 'figure', 'figcaption', 'dt', 'dd', 'blockquote', 'table', 'tr', 'td', 'th' ];

	/**
	 * A box's repeated children, the cards a feature grid is made of: below any one-child wrappers, the biggest set
	 * of 3 or more children sharing tag and first class. Empty when there is none. map-select.js cardChildren() mirrors it.
	 * @return \DOMElement[]
	 */
	public static function card_children( \DOMElement $el ): array {
		$kids = [];
		for ( $depth = 0; $depth < 5; $depth++ ) {
			$kids = [];
			foreach ( $el->childNodes as $c ) {
				if ( $c instanceof \DOMElement && ! in_array( $c->nodeName, self::CARD_SKIP, true ) ) {
					$kids[] = $c;
				}
			}
			if ( count( $kids ) !== 1 ) {
				break;
			}
			$el = $kids[0];
		}
		$by = [];
		foreach ( $kids as $c ) {
			$by[ $c->nodeName . '.' . strtok( trim( $c->getAttribute( 'class' ) ) . ' ', ' ' ) ][] = $c;
		}
		$best = [];
		foreach ( $by as $group ) {
			if ( count( $group ) > count( $best ) ) {
				$best = $group;
			}
		}
		return count( $best ) >= 3 ? $best : [];
	}

	/** A card's words as one feature: its first block is the title, the rest follows a colon ("New Restoration Plugins: Updated …"). map-select.js cardText() mirrors it. */
	public static function card_text( \DOMElement $el ): string {
		$parts = [];
		$walk  = static function ( \DOMNode $n, string $at ) use ( &$walk, &$parts ) {
			foreach ( $n->childNodes as $c ) {
				if ( $c instanceof \DOMText ) {
					$parts[ $at ] = ( $parts[ $at ] ?? '' ) . $c->data;
				} elseif ( $c instanceof \DOMElement && ! in_array( $c->nodeName, self::CARD_SKIP, true ) ) {
					$walk( $c, in_array( $c->nodeName, self::CARD_BLOCKS, true ) ? $c->getNodePath() : $at );
				}
			}
		};
		$walk( $el, '' );
		$lines = array_values( array_filter( array_map( static fn( $t ) => trim( (string) preg_replace( '/\s+/u', ' ', $t ) ), $parts ), 'strlen' ) );
		if ( ! $lines ) {
			return '';
		}
		$title = array_shift( $lines );
		if ( ! $lines ) {
			return $title;
		}
		return $title . ( preg_match( '/[.:!?]$/u', $title ) ? ' ' : ': ' ) . implode( ' ', $lines );
	}

	/* ── CSS subset → XPath ────────────────────────────────────────────── */

	/**
	 * @param bool $images append //img when the selector doesn't end at an img
	 * @return string|\WP_Error
	 */
	public static function css_to_xpath( string $css, bool $images = false ) {
		$css = trim( (string) preg_replace( '/\s+/', ' ', $css ) );
		if ( $css === '' || strlen( $css ) > self::SEL_MAX ) {
			return new \WP_Error( 'mmpt_selector', 'Enter where on the page, e.g. .product-gallery img' );
		}
		// A list ("a, b") is the union of its selectors, in page order (several parts picked on the page).
		$list = preg_split( '/,(?=(?:[^"\']*["\'][^"\']*["\'])*[^"\']*$)/', $css );
		if ( count( $list ) > 1 ) {
			$xps = [];
			foreach ( array_filter( array_map( 'trim', $list ), 'strlen' ) as $one ) {
				$xp = self::css_to_xpath( $one, $images );
				if ( is_wp_error( $xp ) ) {
					return $xp;
				}
				$xps[] = $xp;
			}
			return implode( ' | ', $xps );
		}
		if ( strlen( $css ) > 300 ) {
			return new \WP_Error( 'mmpt_selector', 'Enter where on the page, e.g. .product-gallery img' );
		}
		// A tab's name may hold spaces; kept whole while the steps are split (decoded in tab_step()).
		$css   = (string) preg_replace_callback( '/:tab\("([^"]*)"\)/', static fn( $t ) => ':tab("' . rawurlencode( $t[1] ) . '")', $css );
		$parts = preg_split( '/\s*(>)\s*|\s+/', $css, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( ( $parts[0] ?? '' ) === '>' ) {
			return new \WP_Error( 'mmpt_selector', 'A selector can\'t start with ">".' );
		}
		$xp   = '';
		$axis = '//';
		$last = '';
		foreach ( $parts as $p ) {
			if ( $p === '>' ) {
				$axis = '/';
				continue;
			}
			$tab = '';
			if ( preg_match( '/^(.+):tab\("([^"]*)"\)$/', $p, $tm ) ) {
				[ $p, $tab ] = [ $tm[1], strtolower( trim( (string) preg_replace( '/\s+/', ' ', rawurldecode( $tm[2] ) ) ) ) ];
				if ( $tab === '' || strlen( $tab ) > self::TAB_NAME_MAX ) {
					return new \WP_Error( 'mmpt_selector', sprintf( 'Not understood: ":tab(\"%s\")". Name the tab in quotes, up to %d characters.', $tab, self::TAB_NAME_MAX ) );
				}
			}
			// ":not(.related)": the step without elements that carry it (the mapper leaves unpicked look-alikes out with it).
			$nots = [];
			$p    = (string) preg_replace_callback(
				'/:not\(([^()]+)\)/',
				static function ( $n ) use ( &$nots ) {
					$nots[] = trim( $n[1] );
					return '';
				},
				$p
			);
			if ( ! preg_match( '/^([a-z][a-z0-9-]*|\*)?((?:[#.][A-Za-z0-9_-]+|\[[a-z][a-z0-9_:-]*(?:[~^$*]?=(?:"[^"]*"|\'[^\']*\'|[^\]"\'\s]+))?\])*)$/i', $p, $m ) || ( $m[1] === '' && $m[2] === '' ) ) {
				return new \WP_Error( 'mmpt_selector', sprintf( 'Not understood: "%s". Use tags, #id, .class and [attribute] joined by spaces or ">"; separate several parts with commas.', $p ) );
			}
			$tag   = $m[1] !== '' ? strtolower( $m[1] ) : '*';
			$conds = self::css_conds( $m[2] );
			foreach ( $nots as $n ) {
				if ( ! preg_match( '/^(?:[#.][A-Za-z0-9_-]+|\[[a-z][a-z0-9_:-]*(?:[~^$*]?=(?:"[^"]*"|\'[^\']*\'|[^\]"\'\s]+))?\])+$/i', $n ) ) {
					return new \WP_Error( 'mmpt_selector', sprintf( 'Not understood: ":not(%s)". Inside :not() use #id, .class or [attribute].', $n ) );
				}
				$conds[] = 'not(' . implode( ' and ', self::css_conds( $n ) ) . ')';
			}
			$kind = $tag . ( $conds ? '[' . implode( ' and ', $conds ) . ']' : '' );
			$xp  .= $axis . $kind . ( $tab !== '' ? self::tab_step( $kind, $tab ) : '' );
			$axis = '//';
			$last = $tag;
		}
		if ( $images && $last !== 'img' ) {
			$xp .= '//img';
		}
		return $xp;
	}

	/** XPath conditions for a step's #id, .class and [attribute] parts. @return string[] */
	private static function css_conds( string $s ): array {
		$conds = [];
		preg_match_all( '/([#.])([A-Za-z0-9_-]+)|\[([a-z][a-z0-9_:-]*)(?:([~^$*]?)=("[^"]*"|\'[^\']*\'|[^\]"\'\s]+))?\]/i', $s, $mm, PREG_SET_ORDER );
		foreach ( $mm as $t ) {
			if ( $t[1] === '#' ) {
				$conds[] = '@id=' . self::xq( $t[2] );
			} elseif ( $t[1] === '.' ) {
				$conds[] = "contains(concat(' ',normalize-space(@class),' '),' " . $t[2] . " ')";
			} elseif ( ! isset( $t[5] ) || $t[5] === '' ) {
				$conds[] = '@' . $t[3];
			} else {
				$v       = self::xq( trim( $t[5], '"\'' ) );
				$conds[] = match ( $t[4] ) {
					'*'     => "contains(@{$t[3]},{$v})",
					'^'     => "starts-with(@{$t[3]},{$v})",
					'~'     => "contains(concat(' ',normalize-space(@{$t[3]}),' '),concat(' ',{$v},' '))",
					default => "@{$t[3]}={$v}",
				};
			}
		}
		return $conds;
	}

	/**
	 * `step:tab("name")`: only the panel of a tab row named so. A page whose tabs a script switches lists their
	 * names first (a ul/ol, one item per tab), then one panel per name, side by side (acondigital.com: Audio
	 * Examples, Features, Requirements, in a different order on each page). The panel is paired with its name
	 * by place: the nearest list before it with as many items as there are panels, and the item at the panel's
	 * place names it. A name matches when either holds the other ("system requirements" ~ "Requirements").
	 * The picker adds it when every pick sits in one such tab (map-select.js withTabs(); matchAll() reads it the same way).
	 */
	private static function tab_step( string $kind, string $tab ): string {
		$list = 'preceding-sibling::*[self::ul or self::ol][1]';
		$text = "translate(normalize-space(.),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";
		$q    = self::xq( $tab );
		$name = "[normalize-space(.)!='' and (contains({$text},{$q}) or contains({$q},{$text}))]";
		return "[{$list}/li{$name} and count({$list}/li) = count(../{$kind}) and count(preceding-sibling::{$kind}) = count({$list}/li{$name}[1]/preceding-sibling::li)]";
	}

	private static function xq( string $v ): string {
		if ( ! str_contains( $v, "'" ) ) {
			return "'{$v}'";
		}
		if ( ! str_contains( $v, '"' ) ) {
			return "\"{$v}\"";
		}
		return "concat('" . str_replace( "'", "',\"'\",'", $v ) . "')";
	}

	/* ── Evaluating a field on one page ────────────────────────────────── */

	/**
	 * @return array{found:bool, detail:string, n:int, short:string, urls?:string[]}
	 */
	public static function eval_field( string $field, string $sel, string $mode, string $html, string $url, bool $fetch = false, string $src = '' ): array {
		if ( in_array( $mode, self::ELSEWHERE, true ) ) { // kept on another page: the product page itself isn't read
			if ( $field === 'requirements' ) {
				return self::requirements_cell( self::eval_requirements( '', $url, $sel, $mode, $fetch, $src ) );
			}
			$f  = [ 'sel' => $sel, 'mode' => $mode, 'src' => $src ];
			$pg = self::part_page( $f, $url, '', $fetch );
			return self::values_cell( $field, self::part_values( $field, $f, $url, '', $fetch ) ) + [ 'link' => $pg['url'], 'stored' => $pg['stored'] ];
		}
		$doc = VendorPage::parse( $html );
		return $doc ? self::eval_dom( $field, $sel, $mode, $doc, $url, $fetch, $src ) : [ 'found' => false, 'detail' => '', 'n' => 0, 'short' => '' ];
	}

	/**
	 * One part on an already parsed page (the data preview parses each page once for all seven).
	 * n = what it reads (pictures, characters, items, links, videos; 1 for a version or a requirements
	 * link), short = the value in a few words. The DOM is only read.
	 * @return array{found:bool, detail:string, n:int, short:string, urls?:string[]}
	 */
	public static function eval_dom( string $field, string $sel, string $mode, \DOMDocument $doc, string $url, bool $fetch = false, string $src = '' ): array {
		$none = [ 'found' => false, 'detail' => '', 'n' => 0, 'short' => '' ];
		if ( $field === 'requirements' ) {
			return self::requirements_cell( self::eval_requirements_dom( $doc, $url, $sel, $mode, $fetch, $src ) );
		}
		$xp = self::css_to_xpath( $sel, $field === 'pictures' );
		if ( is_wp_error( $xp ) ) {
			return $none;
		}
		$x = new \DOMXPath( $doc );
		if ( $field === 'pictures' ) {
			$urls  = VendorPage::images_at( $doc, [ $xp ], $url );
			$label = count( $urls ) . ' picture' . ( count( $urls ) === 1 ? '' : 's' );
			return [ 'found' => (bool) $urls, 'detail' => $label, 'n' => count( $urls ), 'short' => $urls ? $label : '', 'urls' => array_slice( $urls, 0, 6 ) ];
		}
		if ( isset( self::FILLS[ $field ] ) || $field === 'version' ) {
			return self::values_cell( $field, self::read_part( $field, $x, $doc, $xp, $url ) );
		}
		$nodes = @$x->query( $xp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a malformed query reads as "not found"
		if ( ! $nodes || ! $nodes->length ) {
			return $none;
		}
		$blocks = VendorPage::elements_blocks( $nodes, $url ); // every match, in page order, as the reader reads them on this site
		$text   = trim( implode( ' ', array_map( static fn( $b ) => is_array( $b['items'] ?? null ) ? implode( ' ', $b['items'] ) : (string) ( $b['text'] ?? '' ), $blocks ) ) );
		$n      = mb_strlen( $text );
		return [ 'found' => $n >= self::MIN_TEXT, 'detail' => $n ? mb_substr( $text, 0, 140 ) . ( $n > 140 ? '…' : '' ) : '', 'n' => $n, 'short' => $n ? mb_substr( $text, 0, 80 ) . ( $n > 80 ? '…' : '' ) : '' ];
	}

	/**
	 * A product's own sub-page: its address (query and anchor dropped) + the path; or, when the path names
	 * {slug}, that path on the site with the product's own name (the last part of its address) in it
	 * ("/manuals/{slug}", "/support/{slug}/requirements").
	 */
	public static function sub_url( string $url, string $path ): string {
		$base = rtrim( (string) strtok( $url, '?#' ), '/' );
		if ( $base === '' || $path === '' ) {
			return '';
		}
		if ( strpos( $path, '{slug}' ) !== false ) {
			$slug = self::slug_of( $base );
			$p    = wp_parse_url( $base );
			return $slug !== '' && ! empty( $p['host'] ) ? ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . str_replace( '{slug}', $slug, $path ) : '';
		}
		return $base . '/' . ltrim( $path, '/' );
	}

	/** A product page's own name in its address: the last part, without .html/.php. */
	public static function slug_of( string $url ): string {
		$seg = basename( rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
		return (string) preg_replace( '/\.(?:html?|php|aspx?)$/i', '', $seg );
	}

	/**
	 * The page a part is read from: the product page, the site's one page (`page`) or the product's own sub-page
	 * (`sub`). Stored copies only, unless $fetch (Scrape: read with the host's pacing, kept 30 days, then stored).
	 * @return array{html:string,url:string,stored:bool}
	 */
	public static function part_page( array $f, string $url, string $html, bool $fetch = false ): array {
		$mode = (string) ( $f['mode'] ?? '' );
		if ( ! in_array( $mode, self::ELSEWHERE, true ) ) {
			return [ 'html' => $html, 'url' => $url, 'stored' => $html !== '' ];
		}
		$at = $mode === 'page' ? (string) ( $f['src'] ?? '' ) : self::sub_url( $url, (string) ( $f['src'] ?? '' ) );
		if ( $at === '' ) {
			return [ 'html' => '', 'url' => '', 'stored' => false ];
		}
		$page = $fetch ? PageStore::get( $at ) : self::stored( $at );
		$body = ( $page['status'] ?? 'ok' ) === 'ok' ? (string) ( $page['html'] ?? '' ) : '';
		return [ 'html' => $body, 'url' => $at, 'stored' => $body !== '' ];
	}

	/**
	 * One part's values from where its map part says (the product page, the site's page or the product's sub-page):
	 * sel `body` reads that page as the generic reader does, any other selector reads the part it names there.
	 * @return string[]|string|array<int,array{label:string,url:string}>
	 */
	private static function part_values( string $k, array $f, string $url, string $html, bool $fetch = false ) {
		$pg   = self::part_page( $f, $url, $html, $fetch );
		$none = in_array( $k, [ 'version', 'disclaimer' ], true ) ? '' : [];
		if ( $pg['html'] === '' ) {
			return $none;
		}
		if ( (string) $f['sel'] === 'body' && in_array( (string) ( $f['mode'] ?? '' ), self::ELSEWHERE, true ) ) {
			$fv    = VendorPage::fields( $pg['html'], $pg['url'], VendorPage::extract( $pg['html'], $pg['url'] ) );
			$links = static fn( array $l ) => array_map( static fn( $x ) => [ 'label' => (string) $x['label'], 'url' => (string) $x['value'] ], $l );
			return match ( $k ) {
				'features' => $fv['features'],
				'version'  => (string) $fv['version'],
				'manual'   => $links( $fv['manuals'] ),
				'audio'    => $links( $fv['audio'] ),
				'videos'   => $fv['videos'],
				default    => $none,
			};
		}
		$doc = VendorPage::parse( $pg['html'] );
		$xp  = self::css_to_xpath( (string) $f['sel'] );
		return $doc && is_string( $xp ) ? self::read_part( $k, new \DOMXPath( $doc ), $doc, $xp, $pg['url'] ) : $none;
	}

	/** A link's file name, decoded ("Soundiron - Jewel Bandolero - User Manual - v1.1.pdf"); '' for a page address. */
	public static function link_file( string $url ): string {
		$f = rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		return preg_match( '/\.(?:pdf|zip|docx?|txt|rtf|epub)$/i', $f ) ? $f : '';
	}

	/** A features / version / manual / videos / audio reading as a preview cell. */
	private static function values_cell( string $field, $v ): array {
		$n    = is_array( $v ) ? count( $v ) : ( $v !== '' ? 1 : 0 );
		$many = static fn( int $n, string $one ) => $n . ' ' . $one . ( $n === 1 ? '' : 's' );
		switch ( $field ) {
			case 'features':
				return [ 'found' => $n >= 3, 'detail' => $v ? $n . ' items: ' . mb_substr( implode( ' · ', array_slice( $v, 0, 3 ) ), 0, 140 ) : '', 'n' => $n, 'short' => $v ? $many( $n, 'list item' ) : '' ];
			case 'version':
				return [ 'found' => $v !== '', 'detail' => $v !== '' ? 'Version ' . $v : '', 'n' => $n, 'short' => $v !== '' ? 'Version ' . $v : '' ];
			case 'disclaimer':
				return [ 'found' => $v !== '', 'detail' => mb_substr( (string) $v, 0, 140 ), 'n' => mb_strlen( (string) $v ), 'short' => mb_substr( (string) $v, 0, 80 ) ];
			case 'manual':
			case 'audio':
				return [ 'found' => (bool) $v, 'detail' => implode( ' · ', array_map( static fn( $l ) => $l['label'] . ' → ' . rawurldecode( (string) preg_replace( '#^https?://(www\.)?#', '', $l['url'] ) ), array_slice( $v, 0, 2 ) ) ), 'n' => $n, 'short' => $v ? ( $field === 'audio' ? $many( $n, 'audio demo' ) : mb_substr( self::link_file( (string) ( $v[0]['url'] ?? '' ) ) ?: $v[0]['label'], 0, 80 ) . ( $n > 1 ? ' +' . ( $n - 1 ) : '' ) ) : '' ]; // a manual by its file ("… User Manual - v1.1.pdf"), not "Click here"
			default:
				return [ 'found' => (bool) $v, 'detail' => $v ? $many( $n, 'video' ) . ': ' . implode( ' ', array_slice( $v, 0, 2 ) ) : '', 'n' => $n, 'short' => $v ? $many( $n, 'video' ) : '' ];
		}
	}

	/** A requirements reading as a part's result: what was found there, in a few words, and where. */
	private static function requirements_cell( array $r ): array {
		$sum = ! empty( $r['req'] ) ? self::req_summary( $r['req'] ) : '';
		return [ 'found' => $r['found'], 'detail' => $r['detail'], 'n' => $r['n'], 'short' => $sum !== '' ? $sum : mb_substr( $r['detail'], 0, 80 ) ]
			+ ( isset( $r['link'] ) ? [ 'link' => $r['link'], 'stored' => $r['stored'] ] : [] ) + ( $sum !== '' ? [ 'summary' => $sum ] : [] );
	}

	/** @return array{found:bool, detail:string, html:string, n:int} */
	private static function eval_requirements( string $html, string $url, string $sel, string $mode, bool $fetch, string $src = '' ): array {
		if ( $mode === 'page' ) {
			return self::kept_reading( $src, $sel, $fetch );
		}
		if ( $mode === 'sub' ) {
			return self::source_reading( self::sub_url( $url, $src ), $sel, $fetch, false ); // one per product: not kept in memory
		}
		$doc = VendorPage::parse( $html );
		return $doc ? self::eval_requirements_dom( $doc, $url, $sel, $mode, $fetch, $src ) : [ 'found' => false, 'detail' => '', 'html' => '', 'n' => 0 ];
	}

	/** Where a site's requirements are kept: each page with its pick, the first being the part's own `src` and `sel`. @return array<int,array{src:string,sel:string}> */
	public static function kept_pages( string $host ): array {
		$f = (array) ( self::get( $host )['fields']['requirements'] ?? [] );
		return ( $f['mode'] ?? '' ) === 'page' ? array_merge( [ [ 'src' => (string) ( $f['src'] ?? '' ), 'sel' => (string) $f['sel'] ] ], array_values( (array) ( $f['also'] ?? [] ) ) ) : [];
	}

	/** The pick on this one of the site's kept pages. @return array{src:string,sel:string}|null */
	public static function kept_pick( string $host, string $url ): ?array {
		foreach ( self::kept_pages( $host ) as $p ) {
			if ( UrlFinder::same_url( $p['src'], $url ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Requirements kept on several pages (owner 2026-11-06: UAD keeps its plug-ins' on two), read as one. A pick being
	 * tried isn't the part's own `src`, so it is read alone. The parts go on as a picked part, read whole: the reader
	 * keeps only the best section of a page, and would lose one of them.
	 */
	private static function kept_reading( string $src, string $sel, bool $fetch ): array {
		$one   = self::source_reading( $src, $sel, $fetch );
		$site  = self::site_of( $src );
		$pages = self::kept_pages( $site );
		if ( count( $pages ) < 2 || $pages[0]['src'] !== $src || ! class_exists( PageRequirements::class ) ) {
			$part = (string) ( $one['part'] ?? '' );
			return $one['found'] || $part === '' || $site === self::host( $src ) ? $one : [ 'found' => true, 'html' => $part, 'n' => 1 ] + $one; // one page of several can hold what the others don't, with no OS named: a person picked it
		}
		$marks = [ PageRequirements::PICKED, self::PICKED_HEAD ];
		$parts = [];
		$away  = [];
		foreach ( $pages as $p ) {
			$read = $p['src'] === $src ? $one : self::source_reading( $p['src'], $p['sel'], $fetch );
			if ( (string) ( $read['part'] ?? '' ) !== '' ) {
				$parts[] = str_replace( $marks, '', (string) $read['part'] );
			} else {
				$away[] = (string) preg_replace( '#^https?://(www\.)?#', '', $p['src'] );
			}
		}
		if ( ! $parts ) {
			return $one;
		}
		$html = PageRequirements::PICKED . implode( self::PART_BREAK, $parts );
		$req  = PageRequirements::extract( $html );
		return [ 'found' => (bool) $req, 'detail' => sprintf( 'kept on %d pages', count( $pages ) ) . ( $away ? ' (nothing read from ' . implode( ', ', $away ) . ')' : '' ), 'html' => $req ? $html : '', 'n' => $req ? 1 : 0, 'link' => $src, 'stored' => $one['stored'], 'req' => $req ];
	}

	/**
	 * A part kept on one page for the whole site (mode `page`; owner 2026-10-10: Antelope keeps every
	 * plugin's system requirements on one support article): that page, or the part of it the selector
	 * takes ('body' = all of it), read once per request whatever product asks. Stored pages only, unless
	 * $fetch (Scrape: fetched with the host's pacing, then stored).
	 * @return array{found:bool, detail:string, html:string, n:int, link:string, stored:bool, req:?array}
	 */
	public static function source_reading( string $src, string $sel, bool $fetch = false, bool $keep = true ): array {
		static $memo = [];
		$key = $src . "\n" . $sel . "\n" . ( $fetch ? 1 : 0 );
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}
		if ( ! $keep ) {
			$memo = []; // a product's own sub-page is read once: only the site's one page is worth keeping
		}
		$where = (string) preg_replace( '#^https?://(www\.)?#', '', $src );
		$out   = [ 'found' => false, 'detail' => '', 'html' => '', 'n' => 0, 'link' => $src, 'stored' => false, 'req' => null ];
		if ( $src === '' ) {
			return $memo[ $key ] = $out;
		}
		$page = $fetch ? PageStore::get( $src ) : self::stored( $src );
		$body = ( $page['status'] ?? 'ok' ) === 'ok' ? (string) ( $page['html'] ?? '' ) : '';
		$out['stored'] = $body !== '';
		if ( $body === '' ) {
			$out['detail'] = 'kept on ' . $where . ' (not stored yet: read during Scrape)';
			return $memo[ $key ] = $out;
		}
		$part = $body;
		if ( $sel !== '' && $sel !== 'body' ) {
			$part = '';
			$doc  = VendorPage::parse( $body );
			$xp   = self::css_to_xpath( $sel );
			if ( $doc && is_string( $xp ) ) {
				$nodes = @( new \DOMXPath( $doc ) )->query( $xp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				foreach ( $nodes ?: [] as $el ) {
					$part .= (string) $doc->saveHTML( $el );
				}
			}
		}
		$has           = class_exists( PageRequirements::class );
		$req           = null;
		if ( $part !== '' && $has ) {
			[ $part, $req ] = $part === $body ? [ $part, PageRequirements::extract( $part ) ] : self::picked_requirements( $part );
		}
		$out['part']   = $part; // what the pick takes, even with no requirements of its own to read
		$out['found']  = $part !== '' && ( ! $has || $req );
		$out['html']   = $out['found'] ? $part : '';
		$out['req']    = $req ?: null;
		$out['n']      = $out['found'] ? 1 : 0;
		$out['detail'] = 'kept on ' . $where . ( $out['found'] ? '' : ( $part === '' ? ' (the part isn\'t on that page)' : ' (no requirements found there)' ) );
		return $memo[ $key ] = $out;
	}

	/** @return array{found:bool, detail:string, html:string, n:int} n: 1 for a link, else the characters read */
	private static function eval_requirements_dom( \DOMDocument $doc, string $url, string $sel, string $mode, bool $fetch, string $src = '' ): array {
		if ( $mode === 'page' ) {
			return self::kept_reading( $src, $sel, $fetch );
		}
		if ( $mode === 'sub' ) {
			return self::source_reading( self::sub_url( $url, $src ), $sel, $fetch, false );
		}
		$out = [ 'found' => false, 'detail' => '', 'html' => '', 'n' => 0 ];
		$xp  = self::css_to_xpath( $sel );
		if ( is_wp_error( $xp ) ) {
			return $out;
		}
		$x     = new \DOMXPath( $doc );
		$nodes = @$x->query( $xp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $nodes ) {
			return $out;
		}
		$req   = class_exists( PageRequirements::class );
		$frags = [];
		foreach ( $nodes as $el ) {
			if ( $mode === 'follow' ) {
				$a    = $el->nodeName === 'a' ? $el : ( $x->query( './/a[@href]', $el )->item( 0 ) ?: null );
				$href = $a ? trim( (string) $a->getAttribute( 'href' ) ) : '';
				if ( $href === '' || ! preg_match( self::REQ_TEXT, $el->textContent . ' ' . $href ) ) {
					continue;
				}
				$link = VendorPage::absolute_url( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url );
				if ( ! preg_match( '#^https?://#i', $link ) ) {
					continue;
				}
				$page = $fetch ? PageStore::get( $link ) : self::stored( $link );
				$body = (string) ( $page['html'] ?? '' );
				$ex   = $body !== '' && $req ? PageRequirements::extract( $body ) : null;
				$ok   = $body !== '' && ( ! $req || $ex );
				return [ 'found' => true, 'detail' => 'link → ' . preg_replace( '#^https?://(www\.)?#', '', $link ) . ( $body === '' ? ' (read during Scrape)' : ( $ok ? '' : ' (no requirements found there)' ) ), 'html' => $ok ? $body : '', 'n' => 1, 'link' => $link, 'stored' => $body !== '', 'req' => $ex ?: null ];
			}
			$frags[] = $el;
		}
		// Text mode: every matched part together (a map may name the Mac and the Windows boxes separately).
		$html  = '';
		$text  = '';
		$seen  = [];
		foreach ( $frags as $el ) {
			for ( $up = $el->parentNode; $up; $up = $up->parentNode ) {
				if ( isset( $seen[ spl_object_id( $up ) ] ) ) {
					continue 2;
				}
			}
			$seen[ spl_object_id( $el ) ] = true;
			$html .= (string) $doc->saveHTML( $el );
			$text .= ' ' . $el->textContent;
			foreach ( $el->nodeName === 'img' ? [ $el ] : $el->getElementsByTagName( 'img' ) as $img ) {
				$text .= ' ' . self::icon_text( $img->getAttribute( 'alt' ), $img->getAttribute( 'title' ) );
			}
		}
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		$ex = null;
		if ( $html !== '' && $req ) {
			[ $html, $ex ] = self::picked_requirements( $html );
		}
		if ( $html !== '' && ( ! $req || $ex ) ) {
			return [ 'found' => true, 'detail' => mb_substr( $text, 0, 140 ), 'html' => $html, 'n' => mb_strlen( $text ), 'req' => $ex ?: null ];
		}
		return [ 'n' => mb_strlen( $text ) ] + $out; // read, but no requirements in it
	}

	/**
	 * Requirements read from a part picked as the requirements: the part is the section, whatever its heading
	 * says (Acon's box is headed "Operating Systems"; the reader looks for a "System requirements" heading and
	 * found nothing in it, 2026-10-30). Without a heading of its own the part is read, and returned, under one,
	 * so requirements_html()'s callers read it the same way.
	 * @return array{0:string, 1:?array} the HTML to read, its requirements
	 */
	private static function picked_requirements( string $html ): array {
		$html = self::icons_as_text( $html );
		$ex   = PageRequirements::extract( $html );
		if ( $ex ) {
			return [ $html, $ex ];
		}
		$headed = self::PICKED_HEAD . $html;
		$ex     = PageRequirements::extract( $headed );
		if ( $ex ) {
			return [ $headed, $ex ];
		}
		// Too little for a page's section (two badges: Mac and Windows), but the person said this is the requirements.
		$picked = PageRequirements::PICKED . $html;
		$ex     = PageRequirements::extract( $picked );
		return $ex ? [ $picked, $ex ] : [ $html, null ];
	}

	/**
	 * A picked part's pictures read as the line their alt or title says (soundiron, 2026-11-02: the requirements are
	 * badges, "Mac OSX 10.9 or later" only in the picture's alt). map-select.js iconText() is the browser's side.
	 */
	private static function icons_as_text( string $html ): string {
		return (string) preg_replace_callback(
			'~<img\b[^>]*>~i',
			static function ( array $m ): string {
				$t = self::icon_text( self::attr( $m[0], 'alt' ), self::attr( $m[0], 'title' ) );
				return $t === '' ? $m[0] : '<p>' . esc_html( $t ) . '</p>';
			},
			$html
		);
	}

	/** What a picture says: its alt, else its title; '' for none, "-", or a file name. */
	public static function icon_text( string $alt, string $title ): string {
		foreach ( [ $alt, $title ] as $t ) {
			$t = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			if ( preg_match( '/\p{L}.*\p{L}/u', $t ) && ! preg_match( '/\.(?:png|jpe?g|gif|svg|webp|avif)$/i', $t ) ) {
				return mb_substr( $t, 0, 300 );
			}
		}
		return '';
	}

	private static function attr( string $tag, string $name ): string {
		return preg_match( '~\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $tag, $m ) ? (string) ( $m[1] !== '' ? $m[1] : ( $m[2] ?? '' ) ) : '';
	}

	/** A stored page's HTML without fetching. @return array{html:string} */
	private static function stored( string $url ): array {
		global $wpdb;
		$f = $wpdb->get_var( $wpdb->prepare( 'SELECT html_file FROM ' . PageStore::table() . " WHERE url_hash = %s AND status = 'ok'", md5( $url ) ) );
		return [ 'html' => $f ? PageStore::read( (string) $f ) : '' ];
	}

	/* ── The site's stored pages ───────────────────────────────────────── */

	/**
	 * The stored pages behind a host's published products with a verified URL.
	 * @return array<int,array{id:int,title:string,url:string,status:string,method:string,file:string,hash:string}>
	 */
	public static function pages( string $host, int $max = 0 ): array {
		global $wpdb;
		$h    = self::host( $host );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID id, p.post_title title, u.meta_value url, pg.status, pg.method, pg.html_file file, pg.content_hash hash
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
				 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
				 JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( u.meta_value )
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pg.host IN (%s, %s)
				 ORDER BY p.ID",
				$h,
				'www.' . $h
			),
			ARRAY_A
		);
		$out  = [];
		$seen = [];
		foreach ( $rows as $r ) {
			$r['title'] = html_entity_decode( (string) $r['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$r['id']    = (int) $r['id'];
			$out[]      = $r;
		}
		return $out;
	}

	/** One row per distinct readable page (the first product on it). */
	private static function readable( array $pages, int $max ): array {
		$seen = [];
		$out  = [];
		foreach ( $pages as $p ) {
			if ( $p['status'] !== 'ok' || isset( $seen[ $p['url'] ] ) ) {
				continue;
			}
			$seen[ $p['url'] ] = 1;
			$out[]             = $p;
		}
		if ( $max && count( $out ) > $max ) {
			// An even spread across the catalog, not the first N by ID.
			$step = count( $out ) / $max;
			$out  = array_map( static fn( $i ) => $out[ (int) floor( $i * $step ) ], range( 0, $max - 1 ) );
		}
		return $out;
	}

	/**
	 * The site's products: published, a verified URL on this host, not excluded; one row each, with its page's
	 * state (no page row = never read). The one definition the gaps, the list, MapCheck and "Fill" count by.
	 * @return array<int,array{id:int,title:string,url:string,st:string,file:string,hash:string,note:string,at:string}>
	 */
	public static function site_products( string $host ): array {
		global $wpdb;
		$h    = self::host( $host );
		$skip = array_flip( Exclusions::ids() );
		$out  = [];
		foreach ( $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID id, p.post_title title, u.meta_value url, pg.status st, pg.html_file file, pg.content_hash hash, pg.note note, pg.fetched_at at
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
				 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
				 LEFT JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( u.meta_value )
				 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( u.meta_value LIKE %s OR u.meta_value LIKE %s )
				 ORDER BY p.ID",
				'%://' . $wpdb->esc_like( $h ) . '/%',
				'%://www.' . $wpdb->esc_like( $h ) . '/%'
			),
			ARRAY_A
		) as $x ) {
			$id = (int) $x['id'];
			if ( isset( $skip[ $id ] ) || isset( $out[ $id ] ) || self::host( (string) $x['url'] ) !== $h ) {
				continue;
			}
			$out[ $id ] = [
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
		return array_values( $out );
	}

	/** Changes when any of the site's pages is read again, appears or goes: a result counted on the old set is out of date. */
	public static function pages_sig( string $host ): string {
		$parts = array_map( static fn( $p ) => $p['id'] . ':' . $p['st'] . ':' . $p['hash'], self::site_products( $host ) );
		return substr( md5( implode( ',', $parts ) ), 0, 10 );
	}

	/* ── Testing ───────────────────────────────────────────────────────── */

	/**
	 * A selector on the site's stored pages.
	 * @return array{found:int, of:int, examples:array<int,array{url:string,detail:string,urls?:string[]}>}
	 */
	public static function test( string $host, string $field, string $sel, string $mode, int $max = self::TEST_MAX, string $sample = '', string $src = '', ?array $only = null ): array { // $only: these pages only (url → any; one layout's)
		$pages   = self::pages( $host );
		$pages   = self::readable( $only === null ? $pages : array_values( array_filter( $pages, static fn( $p ) => isset( $only[ $p['url'] ] ) ) ), $max );
		$out     = [ 'found' => 0, 'of' => 0, 'examples' => [] ];
		$targets = [];
		foreach ( $pages as $p ) {
			$html = PageStore::read( (string) $p['file'] );
			if ( $html === '' ) {
				continue;
			}
			if ( $sample !== '' && (string) $p['url'] === $sample ) {
				$out['reads'] = self::reads_on( $field, $sel, $mode, $html );
			}
			$out['of']++;
			$r = self::eval_field( $field, $sel, $mode, $html, (string) $p['url'], false, $src );
			if ( ! empty( $r['link'] ) ) {
				$targets[ $r['link'] ] = ( $targets[ $r['link'] ] ?? 0 ) + 1; // one shared page for the whole site, or one per product
			}
			if ( $sample !== '' && (string) $p['url'] === $sample ) {
				$out['sample'] = array_intersect_key( $r, array_flip( [ 'found', 'detail', 'short', 'link', 'stored', 'summary' ] ) );
			}
			if ( $r['found'] ) {
				$out['found']++;
				if ( count( $out['examples'] ) < 3 ) {
					$out['examples'][] = [ 'url' => (string) $p['url'], 'detail' => $r['detail'] ] + ( isset( $r['urls'] ) ? [ 'urls' => $r['urls'] ] : [] );
				}
			}
		}
		if ( $targets ) {
			arsort( $targets );
			$out['targets'] = array_map( static fn( $u, $n ) => [ 'url' => $u, 'pages' => $n ], array_keys( array_slice( $targets, 0, 3, true ) ), array_slice( $targets, 0, 3, true ) );
			$out['target_count'] = count( $targets );
		}
		return $out;
	}

	/**
	 * Requirements found (PageRequirements::extract()) in a few words for the editor:
	 * "Mac: macOS 10.14 Mojave, 11 Big Sur, 8 GB RAM · Windows: Windows 10 or 11, 8 GB RAM · iLok".
	 */
	public static function req_summary( array $q ): string {
		$out = [];
		foreach ( [ 'mac' => 'Mac', 'windows' => 'Windows', 'linux' => 'Linux' ] as $os => $label ) {
			$o = (array) ( $q[ $os ] ?? [] );
			if ( ! $o ) {
				continue;
			}
			$bits  = array_filter( [ (string) ( $o['version'] ?? '' ), ! empty( $o['ram'] ) ? $o['ram'] . ' GB RAM' : '' ] );
			$out[] = $label . ( $bits ? ': ' . implode( ', ', $bits ) : '' );
		}
		$lic = (array) ( $q['licensing']['methods'] ?? [] );
		if ( $lic ) {
			$out[] = implode( ', ', array_map( static fn( $m ) => $m === 'ilok' ? 'iLok' : ucfirst( (string) $m ), $lic ) );
		}
		return mb_substr( implode( ' · ', $out ), 0, 200 );
	}

	/**
	 * The text a text part keeps on one page, block by block, after the reader's cleaning (shop lines,
	 * store promos and repeats dropped). The picker uses it to tell an element nobody picked that the
	 * reader would read from one it skips anyway. Null for parts that aren't read as text.
	 * @return string[]|null
	 */
	private static function reads_on( string $field, string $sel, string $mode, string $html ): ?array {
		if ( ! in_array( $field, [ 'description', 'requirements' ], true ) || ( $field === 'requirements' && in_array( $mode, [ 'follow', 'page', 'sub' ], true ) ) ) {
			return null;
		}
		$doc = VendorPage::parse( $html );
		$xp  = self::css_to_xpath( $sel );
		if ( ! $doc || is_wp_error( $xp ) ) {
			return [];
		}
		$nodes = @( new \DOMXPath( $doc ) )->query( $xp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $nodes || ! $nodes->length ) {
			return [];
		}
		if ( $field === 'requirements' ) {
			// The requirements reader takes every matched element's text, headings too: "PC Version (Windows)"
			// says whose lines follow (2026-10-30: the description reader's blocks called it skipped).
			$out = [];
			foreach ( $nodes as $el ) {
				$t = mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $el->textContent ) ), 0, 2000 );
				if ( $t !== '' ) {
					$out[] = $t;
				}
			}
			return array_slice( $out, 0, 60 );
		}
		return array_slice( array_map( static fn( $b ) => mb_substr( is_array( $b['items'] ?? null ) ? implode( ' ', $b['items'] ) : (string) ( $b['text'] ?? '' ), 0, 2000 ), VendorPage::elements_blocks( $nodes ) ), 0, 60 );
	}

	/* ── Data preview (the editor's grid) ──────────────────────────────── */

	/** Whether a page's compact reading (Coverage::read_page()) has a part, as tally() counts found or partly. */
	private static function reads( string $k, array $o ): bool {
		return match ( $k ) {
			'pictures'     => (int) ( $o['images'] ?? 0 ) >= 1,
			'description'  => (int) ( $o['description'] ?? 0 ) > 0,
			'features'     => (int) ( $o['features'] ?? 0 ) >= 3,
			'requirements' => ! empty( $o['os'] ),
			default        => ! empty( $o[ $k ] ),
		};
	}

	/* ── Drafting ──────────────────────────────────────────────────────── */

	/**
	 * Measure a site and draft its map (CLI). A confirmed map's fields, and
	 * fields a person set, are kept; coverage and stats are always refreshed.
	 */
	public static function draft( string $host ): array {
		self::maybe_install();
		global $wpdb;
		$h     = self::host( $host );
		$all   = self::pages( $h );
		if ( ! $all ) {
			return [ 'host' => $h, 'skipped' => 'no stored pages' ];
		}
		$row   = self::get( $h );
		[ $cov, $n, $shells ] = self::measure( $all );
		// A JavaScript app sends an empty page to a plain fetch: read a sample in the browser (CLI),
		// then measure again. Scrape reads the site in the browser once the map is confirmed.
		$render = (int) ( $row['render'] ?? 0 );
		if ( $n && $shells >= $n / 2 ) {
			$render = 1;
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				foreach ( self::readable( $all, self::RENDER_SAMPLE ) as $p ) {
					if ( $p['method'] === 'http' && ! PageStore::server_busy() ) {
						PageStore::render( (string) $p['url'] );
					}
				}
				$all = self::pages( $h );
				[ $cov, $n, $shells ] = self::measure( $all );
			}
		}
		$sample = array_values( array_filter( self::readable( $all, 0 ), static fn( $p ) => ! $render || $p['method'] !== 'http' ) );
		$sample = $sample ? array_slice( $sample, 0, self::SAMPLE ) : self::readable( $all, self::SAMPLE );
		$htmls  = [];
		foreach ( $sample as $p ) {
			$html = PageStore::read( (string) $p['file'] );
			if ( $html !== '' ) {
				$htmls[] = [ 'url' => (string) $p['url'], 'html' => $html ];
			}
		}
		$fields = (array) ( $row['fields'] ?? [] );
		$keep   = ( $row['status'] ?? '' ) === 'confirmed';
		foreach ( array_keys( self::MODES ) as $f ) {
			if ( $keep || ( $fields[ $f ]['source'] ?? '' ) === 'person' ) {
				continue;
			}
			$generic = $cov[ $f ][0];
			$d       = $n && $generic / $n < 0.8 ? self::draft_field( $f, $htmls ) : null;
			if ( $d ) {
				$t = self::test( $h, $f, $d['sel'], $d['mode'] );
				if ( $t['found'] > $generic ) {
					$fields[ $f ] = $d + [ 'source' => 'auto', 'found' => $t['found'], 'of' => $t['of'], 'checked_at' => current_time( 'mysql', true ) ];
					continue;
				}
			}
			unset( $fields[ $f ] ); // the generic reader does as well or better
		}
		$stats = self::stats( $h, $all ) + [ 'shells' => $shells, 'at' => current_time( 'mysql', true ) ];
		$wpdb->replace(
			self::table(),
			[
				'host'         => $h,
				'status'       => $row['status'] ?? 'draft',
				'pattern'      => ( $row && $row['pattern'] !== '' ) ? $row['pattern'] : self::pattern( array_column( $all, 'url' ) ),
				'render'       => $render,
				'fields'       => wp_json_encode( $fields ),
				'coverage'     => wp_json_encode( $cov + [ '_of' => $n ] ),
				'stats'        => wp_json_encode( $stats ),
				'shot'         => wp_json_encode( $row['shot'] ?? [] ),
				'reading'      => wp_json_encode( $row['reading'] ?? [] ), // REPLACE rewrites the whole row: keep what isn't redrafted
				'drafted_at'   => current_time( 'mysql', true ),
				'checked_at'   => current_time( 'mysql', true ),
				'confirmed_at' => $row['confirmed_at'] ?? null,
				'confirmed_by' => (int) ( $row['confirmed_by'] ?? 0 ),
				'map_at'       => $row['map_at'] ?? null,
			]
		);
		self::$confirmed = null;
		self::$renders   = null;
		if ( array_filter( $fields, static fn( $f ) => ! empty( $f['sel'] ) ) ) {
			self::refresh( $h, false ); // what the reader finds with the map now, or would once it is confirmed (stats were just counted)
		} else {
			self::update( $h, [ 'reading' => wp_json_encode( [] ) ] );
		}
		return [ 'host' => $h, 'pages' => $n, 'shells' => $shells, 'render' => $render, 'drafted' => array_keys( array_filter( $fields, static fn( $f ) => ( $f['source'] ?? '' ) === 'auto' ) ) ];
	}

	/**
	 * What the generic reader finds on a site's readable pages, per field, with no map:
	 * [ coverage field => [found, partly], pages read, pages that are an empty JavaScript shell ].
	 */
	private static function measure( array $all ): array {
		$readings = [];
		$shells   = 0;
		self::$bypass = true;
		try {
			foreach ( self::readable( $all, 0 ) as $p ) {
				$html = PageStore::read( (string) $p['file'] );
				if ( $html === '' ) {
					continue;
				}
				$o          = Coverage::read_page( (string) $p['url'], (string) $p['file'], (string) $p['title'] );
				$readings[] = $o;
				if ( empty( $o['description'] ) && empty( $o['images'] ) && preg_match( self::JS_SHELL, $html ) ) {
					$shells++;
				}
			}
		} finally {
			self::$bypass = false;
		}
		return [ self::tally( $readings ), count( $readings ), $shells ];
	}

	/**
	 * What the reader finds on a site's readable pages now, with its confirmed map in force:
	 * the cached readings Vendor pages shows (Coverage::pages() brings each up to the current
	 * map first), so the site's chips and its products' icons can't disagree.
	 * @return array{0:array,1:int} [ coverage field => [found, partly], pages read ]
	 */
	private static function measure_now( array $all ): array {
		global $wpdb;
		$pages = array_map(
			static fn( $p ) => [ 'url' => (string) $p['url'], 'status' => 'ok', 'file' => (string) $p['file'], 'hash' => (string) ( $p['hash'] ?? '' ), 'title' => (string) $p['title'] ],
			self::readable( $all, 0 )
		);
		if ( ! $pages ) {
			return [ self::tally( [] ), 0 ];
		}
		Coverage::pages( $pages, self::REFRESH_BUDGET );
		$stamp    = self::stamp( (string) $pages[0]['url'] );
		$hashes   = "'" . implode( "','", array_map( static fn( $p ) => md5( $p['url'] ), $pages ) ) . "'";
		$readings = [];
		foreach ( $wpdb->get_col( 'SELECT offers FROM ' . Coverage::table() . " WHERE url_hash IN ({$hashes})" ) as $json ) { // phpcs:ignore WordPress.DB.PreparedSQL -- md5 hex only
			$o = json_decode( (string) $json, true );
			if ( is_array( $o ) && (string) ( $o['m'] ?? '' ) === $stamp ) {
				$readings[] = $o;
			}
		}
		return [ self::tally( $readings ), count( $readings ) ];
	}

	/**
	 * What the reader would find on a site's readable pages if this (draft) map were confirmed:
	 * each page read with the map standing in for the confirmed ones, nothing stored (read_page()
	 * writes nothing). Shown before Confirm, because a part can also take something away
	 * (Antelope 2026-10-09: a description of paragraphs only hid the feature lists, 70 → 0 pages).
	 * Also what the picture runner would then do with the site's products (pictures(), read-only):
	 * a part can find a picture on every page and still change few products (Antelope: one
	 * picture per page, and the runner replaces only from 2+; 13 → 15 products).
	 * @return array{0:array,1:int,2:array}
	 */
	private static function measure_preview( array $row, array $all ): array {
		$readings = [];
		self::$preview = [ $row['host'] => self::editor_entry( $row ) ];
		try {
			foreach ( self::readable( $all, 0 ) as $p ) {
				$o = Coverage::read_page( (string) $p['url'], (string) $p['file'], (string) $p['title'] );
				if ( $o ) {
					$readings[] = $o;
				}
			}
			$pictures = self::pictures( $all );
		} finally {
			self::$preview = null;
		}
		return [ self::tally( $readings ), count( $readings ), $pictures ];
	}

	/**
	 * Run $fn with this host's map as it is in the editor (draft parts included, broken ones left out) standing
	 * in for the confirmed maps, as "If you confirm" measures it (measure_preview()). The product preview reads
	 * a product's page this way; nothing is stored.
	 * @return mixed what $fn returns
	 */
	public static function with_editor_map( string $host, callable $fn ) {
		$row           = self::get( $host );
		$was           = self::$preview;
		self::$preview = $row ? [ $row['host'] => self::editor_entry( $row, self::$editor_only === null ? null : self::in_use_with( $row, self::$editor_only ) ) ] : [];
		try {
			return $fn();
		} finally {
			self::$preview = $was;
		}
	}

	/** with_editor_map() with the editor's copy of $parts only; the site's other parts as the reader has them now. */
	public static function with_editor_parts( string $host, array $parts, callable $fn ) {
		$was               = self::$editor_only;
		self::$editor_only = $parts;
		try {
			return self::with_editor_map( $host, $fn );
		} finally {
			self::$editor_only = $was;
		}
	}

	/** The parts the reader uses on this site now (a confirmed map's, or a draft's live ones), with the editor's $parts over them. */
	private static function in_use_with( array $row, array $parts ): array {
		$own = MapTypes::usable( (array) $row['fields'] );
		$use = array_filter( $own, static fn( $f ) => $row['status'] === 'confirmed' || ! empty( $f['live'] ) );
		return array_intersect_key( $own, array_flip( $parts ) ) + $use;
	}

	/**
	 * Per field [pages where found, pages where partly] from pages' compact readings (Coverage::read_page()).
	 * @param array<int,array> $readings
	 */
	private static function tally( array $readings ): array {
		$cov = array_fill_keys( array_keys( self::FIELDS ), [ 0, 0 ] );
		foreach ( $readings as $o ) {
			$cov['pictures'][ ( $o['images'] ?? 0 ) >= 2 ? 0 : 1 ] += ( $o['images'] ?? 0 ) >= 1 ? 1 : 0;
			$cov['description'][ ( $o['description'] ?? 0 ) >= self::MIN_TEXT ? 0 : 1 ] += ( $o['description'] ?? 0 ) > 0 ? 1 : 0;
			$cov['features'][0]     += ( $o['features'] ?? 0 ) >= 3 ? 1 : 0;
			$cov['requirements'][0] += ! empty( $o['os'] ) ? 1 : 0;
			$cov['version'][0]      += ! empty( $o['version'] ) ? 1 : 0;
			$cov['manual'][0]       += ! empty( $o['manual'] ) ? 1 : 0;
			$cov['videos'][0]       += ! empty( $o['videos'] ) ? 1 : 0;
		}
		return $cov;
	}

	/**
	 * Bring a site's numbers up to what the reader does (the runner, CLI). `reading` holds, with
	 * a confirmed map, what its pages give now (measure_now()); for a map not in use with parts
	 * set, what confirming it would make the reader find (measure_preview(), `_preview`), shown
	 * before Confirm. $with_stats also recounts which products get vendor pictures. Queued after
	 * a confirm, an edit, a re-read and the nightly check.
	 */
	public static function refresh( string $host, bool $with_stats = true ): array {
		$row = self::get( $host );
		if ( ! $row ) {
			return [ 'host' => self::host( $host ), 'skipped' => 'no map' ];
		}
		$all = self::pages( $row['host'] );
		if ( ! $all ) {
			SiteGaps::read( $row['host'] ); // the gaps still say which products' pages can't be read
			return [ 'host' => $row['host'], 'skipped' => 'no stored pages' ];
		}
		$seen = (array) get_option( self::RENDER_OPTION, [] );
		if ( defined( 'WP_CLI' ) && WP_CLI && ! (int) $row['render'] && (int) ( $seen[ $row['host'] ] ?? 0 ) < time() - self::RENDER_EVERY && ! PageStore::server_busy() ) {
			self::render_check( $row['host'] ); // a site that draws its pages with scripts is read in the browser from now on
		}
		self::$confirmed = null;
		$reading         = [];
		$generic         = null;
		$at              = current_time( 'mysql', true );
		// An unconfirmed map with parts is always previewed, even with a part in use (a stamp): Confirm compares that reading,
		// and without it such a draft said "still being measured" for good (airmusictech.com, soundiron.com, 2026-11-03).
		$draft = $row['status'] !== 'confirmed' && array_filter( (array) $row['fields'], static fn( $f ) => ! empty( $f['sel'] ) );
		if ( ! $draft && self::stamp( $row['host'] ) !== '' ) {
			[ $cov, $n ] = self::measure_now( $all );
			$reading     = $cov + [ '_of' => $n, '_at' => $at ];
		} elseif ( $draft ) {
			[ $cov, $n, $pics ] = self::measure_preview( $row, $all );
			// The same pages read without the map, now: what drops() compares with. The drafting sample stood in before,
			// so a part counted on 14 pages under an older reader looked lost on 97 (arturia.com Videos 11 → 0, 2026-10-24).
			[ $base, $gn, $shells ] = self::measure( $all );
			$generic                = [ $base, $gn, $shells ];
			$reading                = $cov + [ '_of' => $n, '_at' => $at, '_preview' => 1, '_pictures' => $pics, '_base' => $base ];
		}
		// What the reader finds without a map, on every page stored now: the status ("Reads fine" / "Needs a map") and the
		// drafting compare with it. Only a draft counted it before, so a site drafted with few pages stored kept that
		// count (owner 2026-11-01: airmusictech "reads fine" from 0 of its 80 pages, counted on 2026-10-09).
		[ $gcov, $gn, $shells ] = $generic ?? self::measure( $all );
		$cols = [ 'reading' => wp_json_encode( $reading ), 'coverage' => wp_json_encode( $gcov + [ '_of' => $gn, '_at' => $at ] ) ];
		if ( $with_stats ) {
			$stats         = self::stats( $row['host'], $all ) + [ 'shells' => $shells, 'at' => $at ];
			$cols['stats'] = wp_json_encode( $stats );
		}
		self::update( $row['host'], $cols );
		$gaps = SiteGaps::read( $row['host'] ); // each product against its page, with the map as it is now (after the pictures plans above)
		return [ 'gaps' => $gaps['read'] . ' read, ' . $gaps['pending'] . ' left', 'host' => $row['host'], 'reading' => $reading ? ( isset( $reading['_preview'] ) ? 'preview' : 'now' ) : '', 'vendor' => $stats['vendor'] ?? null, 'blocked' => $stats['blocked'] ?? null ];
	}

	/** Products, pages, read, refused, rendered; products that get vendor pictures and that can't, and why. */
	private static function stats( string $h, array $all ): array {
		$urls = [];
		$s    = [ 'products' => count( $all ), 'pages' => 0, 'read' => 0, 'refused' => 0, 'rendered' => 0 ];
		foreach ( $all as $p ) {
			if ( ! isset( $urls[ $p['url'] ] ) ) {
				$urls[ $p['url'] ] = 1;
				$s['pages']++;
				if ( $p['status'] === 'ok' ) {
					$s['read']++;
					$s['rendered'] += $p['method'] !== 'http' ? 1 : 0;
				} else {
					$s['refused']++;
				}
			}
		}
		return $s + self::pictures( $all );
	}

	/**
	 * Which of a site's products the picture runner would give vendor pictures (Pictures::plan(),
	 * read-only), and why the others not: { vendor, blocked, why: reason => products }. Products
	 * the runner leaves alone on purpose (done, picked by a person, not eligible) count in neither.
	 */
	private static function pictures( array $all ): array {
		$s = [ 'vendor' => 0, 'blocked' => 0, 'why' => [], 'by' => [] ]; // by: each product's plan, for the site's gaps (SiteGaps)
		foreach ( array_unique( array_map( static fn( $p ) => (int) $p['id'], $all ) ) as $id ) {
			$plan            = Pictures::plan( $id );
			$s['by'][ $id ] = $plan['mode'] === 'vendor' ? 'vendor' : (string) $plan['reason'];
			if ( $plan['mode'] === 'vendor' ) {
				$s['vendor']++;
			} elseif ( ! in_array( $plan['reason'], [ 'not_eligible', 'already_standard', 'person_picked', 'vendor_done', 'kept_by_person' ], true ) ) {
				$s['blocked']++;
				$s['why'][ $plan['reason'] ] = ( $s['why'][ $plan['reason'] ] ?? 0 ) + 1;
			}
		}
		arsort( $s['why'] );
		return $s;
	}

	/** The most common product URL shape: numbers → {id}, last segment → {slug}. */
	public static function pattern( array $urls ): string {
		$shapes = [];
		foreach ( $urls as $u ) {
			$path  = (string) wp_parse_url( (string) $u, PHP_URL_PATH );
			$segs  = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
			$n     = count( $segs );
			$shape = array_map( static fn( $s, $i ) => ctype_digit( $s ) ? '{id}' : ( $i === $n - 1 ? '{slug}' : $s ), $segs, array_keys( $segs ) );
			$key   = '/' . implode( '/', $shape ) . ( $n && str_ends_with( $path, '/' ) ? '/' : '' );
			$shapes[ $key ] = ( $shapes[ $key ] ?? 0 ) + 1;
		}
		arsort( $shapes );
		return (string) array_key_first( $shapes );
	}

	/**
	 * Draft one field from sample pages: the element signature present on the
	 * most pages whose content differs page to page (site chrome repeats).
	 * @return array{sel:string, mode:string, where:string}|null
	 */
	/**
	 * The element that holds part $field on these pages, for a proposal (MapProposals): the drafter's finder, with a
	 * lower bar (on a third of the pages, not half: identification first, its worth is judged later) and audio players.
	 */
	public static function propose_field( string $field, array $htmls ): ?array {
		return self::draft_field( $field, $htmls, 0.3 );
	}

	private static function draft_field( string $field, array $htmls, float $share = 0.5 ): ?array {
		$by  = []; // signature => [pages => n, values => [value => n]]
		$cnt = count( $htmls );
		if ( $cnt < 2 ) {
			return null;
		}
		foreach ( $htmls as $pg ) {
			$doc = VendorPage::parse( $pg['html'] );
			if ( ! $doc ) {
				continue;
			}
			$x     = new \DOMXPath( $doc );
			$found = [];
			$worth = []; // manual: a link with words 2, to a file 1 (owner 2026-11-03: judge links by where they go)
			if ( $field === 'pictures' ) {
				foreach ( $x->query( '//img' ) as $img ) {
					$u = VendorPage::img_src( $img, $pg['url'] );
					if ( $u === '' || preg_match( VendorPage::IMAGE_SKIP, $u ) ) {
						continue;
					}
					$w = (int) $img->getAttribute( 'width' );
					$hh = (int) $img->getAttribute( 'height' );
					if ( ( $w && $w < 150 ) || ( $hh && $hh < 150 ) ) {
						continue;
					}
					$sig = self::signature( $img );
					if ( $sig !== '' ) {
						$found[ $sig ][] = strtolower( preg_replace( '/[?#].*$/', '', $u ) );
					}
				}
			} elseif ( $field === 'description' ) {
				foreach ( $x->query( '//div|//section|//article' ) as $el ) {
					$len = mb_strlen( trim( (string) preg_replace( '/\s+/', ' ', $el->textContent ) ) );
					if ( $len < self::MIN_TEXT || $len > 15000 || ! $x->query( './/p', $el )->length ) {
						continue;
					}
					// The innermost container holding the text: skip one whose child holds 80 % of it.
					$inner = false;
					foreach ( $el->childNodes as $c ) {
						if ( $c instanceof \DOMElement && mb_strlen( trim( (string) preg_replace( '/\s+/', ' ', $c->textContent ) ) ) >= 0.8 * $len ) {
							$inner = true;
							break;
						}
					}
					$sig = $inner ? '' : self::signature( $el );
					if ( $sig !== '' ) {
						$found[ $sig ][] = md5( mb_substr( $el->textContent, 0, 400 ) );
					}
				}
			} elseif ( $field === 'features' ) {
				// A list of 3+ short items outside the site's menus; a list of links is a menu.
				foreach ( $x->query( '//ul[not(' . self::CHROME_AXIS . ')]|//ol[not(' . self::CHROME_AXIS . ')]' ) as $ul ) {
					$lis   = $x->query( './li', $ul );
					$links = $x->query( './li[a and string-length(normalize-space(.)) = string-length(normalize-space(a))]', $ul )->length;
					if ( $lis->length < 3 || $links / $lis->length > 0.5 ) {
						continue;
					}
					$text = trim( (string) preg_replace( '/\s+/', ' ', $ul->textContent ) );
					$avg  = mb_strlen( $text ) / $lis->length;
					$sig  = $avg >= 8 && $avg <= 300 && ! VendorPage::is_chrome( mb_substr( $text, 0, 260 ) ) && ! VendorPage::looks_like_requirements( $text ) ? self::signature( $ul ) : '';
					if ( $sig !== '' ) {
						$found[ $sig ][] = md5( mb_substr( $text, 0, 400 ) );
					}
				}
			} elseif ( $field === 'version' ) {
				// The smallest element saying "Version 2.1.4", or the value next to a "Version" label.
				foreach ( $x->query( '//body//text()[normalize-space() and not(' . self::CHROME_AXIS . ')]' ) as $tn ) {
					$t  = trim( (string) preg_replace( '/\s+/', ' ', $tn->textContent ) );
					$el = $tn->parentNode;
					if ( ! $el instanceof \DOMElement || mb_strlen( $t ) > 160 ) {
						continue;
					}
					if ( preg_match( self::VERSION_LABEL, $t ) ) {
						$val = $el->nextSibling;
						while ( $val && ! $val instanceof \DOMElement ) {
							$val = $val->nextSibling;
						}
						if ( ! $val && $el->parentNode instanceof \DOMElement && in_array( $el->parentNode->nodeName, [ 'th', 'td', 'dt', 'span', 'strong', 'b', 'label' ], true ) ) {
							$val = $el->parentNode->nextSibling; // <th><b>Version</b></th><td>2.1</td>
							while ( $val && ! $val instanceof \DOMElement ) {
								$val = $val->nextSibling;
							}
						}
						$vt = $val ? trim( (string) preg_replace( '/\s+/', ' ', $val->textContent ) ) : '';
						if ( $val instanceof \DOMElement && mb_strlen( $vt ) <= 40 && preg_match( self::BARE_VERSION, $vt, $m ) && ( $sig = self::signature( $val ) ) !== '' ) {
							$found[ $sig ][] = $m[1];
						}
					} elseif ( preg_match( self::VERSION_RE, $t, $m ) && ( $sig = self::signature( $el ) ) !== '' ) {
						$found[ $sig ][] = $m[1];
					}
				}
			} elseif ( $field === 'manual' ) {
				foreach ( $x->query( '//a[@href and not(' . self::CHROME_AXIS . ')]' ) as $a ) {
					$label = trim( (string) preg_replace( '/\s+/', ' ', $a->textContent ) );
					$href  = (string) $a->getAttribute( 'href' );
					if ( mb_strlen( $label ) < 80 && preg_match( self::MANUAL_TEXT, $label . ' ' . $href ) && ( $sig = self::signature( $a ) ) !== '' ) {
						$found[ $sig ][] = $href;
						$worth[ $sig ]   = max( $worth[ $sig ] ?? 0, ( $label !== '' ? 2 : 0 ) + ( self::link_file( $href ) !== '' ? 1 : 0 ) );
					}
				}
			} elseif ( $field === 'videos' ) {
				// Elements carrying a YouTube or Vimeo video in an attribute (iframe src, data-src, lite-youtube videoid…).
				foreach ( $x->query( '//body//*[not(' . self::CHROME_AXIS . ')]/@*' ) as $attr ) {
					$v  = trim( (string) $attr->nodeValue );
					$id = preg_match( self::VIDEO_RE, $v, $m ) ? ( $m[1] ?? '' ) . ( $m[2] ?? '' ) . ( $m[3] ?? '' )
						: ( preg_match( self::VIDEO_ATTR, $attr->nodeName ) && preg_match( '/^[A-Za-z0-9_-]{11}$/', $v ) ? $v : '' );
					$el = $attr->parentNode;
					if ( $id !== '' && $el instanceof \DOMElement && ( $sig = self::signature( $el ) ) !== '' ) {
						$found[ $sig ][] = $id;
					}
				}
			} elseif ( $field === 'audio' ) {
				// SoundCloud players and links, audio elements and audio files (VendorPage::fields()' audio demos).
				foreach ( $x->query( '//iframe[contains(@src,"soundcloud.com") or contains(@data-src,"soundcloud.com")]|//a[contains(@href,"soundcloud.com/")]|//audio|//source[contains(@src,".mp3") or contains(@src,".m4a") or contains(@src,".ogg")]|//a[contains(@href,".mp3")]' ) as $el ) {
					$v  = (string) ( $el->getAttribute( 'src' ) ?: $el->getAttribute( 'data-src' ) ?: $el->getAttribute( 'href' ) );
					$at = $el->nodeName === 'source' && $el->parentNode instanceof \DOMElement ? $el->parentNode : $el;
					if ( $v !== '' && ! preg_match( self::CHROME_SIG, $v ) && ( $sig = self::signature( $at ) ) !== '' ) {
						$found[ $sig ][] = $v;
					}
				}
			} else { // requirements
				foreach ( $x->query( '//h2|//h3|//h4|//h5|//strong|//*[@data-mmi-tab]' ) as $hd ) {
					$t = trim( (string) ( $hd instanceof \DOMElement && $hd->hasAttribute( 'data-mmi-tab' ) ? $hd->getAttribute( 'data-mmi-tab' ) : $hd->textContent ) );
					if ( mb_strlen( $t ) < 60 && preg_match( self::REQ_TEXT, $t ) && $hd->parentNode instanceof \DOMElement ) {
						$sig = self::signature( $hd->hasAttribute( 'data-mmi-tab' ) ? $hd : $hd->parentNode );
						if ( $sig !== '' ) {
							$found[ 'text|' . $sig ][] = md5( mb_substr( $hd->parentNode->textContent, 0, 400 ) );
						}
					}
				}
				foreach ( $x->query( '//a[@href]' ) as $a ) {
					if ( preg_match( self::REQ_TEXT, $a->textContent ) && mb_strlen( trim( $a->textContent ) ) < 60 ) {
						$sig = self::signature( $a );
						if ( $sig !== '' ) {
							$found[ 'follow|' . $sig ][] = (string) $a->getAttribute( 'href' );
						}
					}
				}
			}
			foreach ( $found as $sig => $vals ) {
				if ( ( isset( self::FILLS[ $field ] ) || $field === 'version' ) && preg_match( self::CHROME_SIG, $sig ) ) {
					continue; // a footer's "Downloads & Manuals" link is the site's, not the product's
				}
				$by[ $sig ]['pages'] = ( $by[ $sig ]['pages'] ?? 0 ) + 1;
				$by[ $sig ]['worth'] = ( $by[ $sig ]['worth'] ?? 0 ) + ( $worth[ $sig ] ?? 0 );
				foreach ( array_unique( $vals ) as $v ) {
					$by[ $sig ]['values'][ $v ] = ( $by[ $sig ]['values'][ $v ] ?? 0 ) + 1;
				}
			}
		}
		$best = null;
		$top  = 0;
		foreach ( $by as $sig => $d ) {
			if ( $d['pages'] < max( 2, (int) ceil( $cnt * $share ) ) ) {
				continue;
			}
			// Content that repeats on most pages is the site's own chrome — except a requirements
			// link or a manual, which may well be one shared page (Antelope's 73 FPGA plugins share
			// one manual; footer links are kept out by CHROME_SIG), and a version, which a family
			// of products often shares (it only has to differ somewhere).
			$varies = match ( true ) {
				str_starts_with( $sig, 'follow|' ), $field === 'manual' => true,
				$field === 'version'                                    => count( $d['values'] ) >= 2,
				default                                                 => max( $d['values'] ) <= max( 1, $cnt / 3 ),
			};
			// Manuals: of links on as many pages, the one with words and to a file (soundiron's product box has an icon-only
			// link to the same PDF its Documentation section names; the person looks for the named one).
			$score = $d['pages'] * 4 + ( $field === 'manual' ? min( 3, intdiv( (int) $d['worth'], max( 1, $d['pages'] ) ) ) : 0 );
			if ( $varies && $score > $top ) {
				$best = $sig;
				$top  = $score;
			}
		}
		if ( $best === null ) {
			return null;
		}
		$mode = $field === 'requirements' ? strtok( $best, '|' ) : self::MODES[ $field ][0];
		$sel  = $field === 'requirements' ? substr( $best, strpos( $best, '|' ) + 1 ) : $best;
		$where = [
			'img'    => 'The pictures in the part of the page below',
			'text'   => [ 'description' => 'The text in the part of the page below', 'version' => 'The version number in the part of the page below' ][ $field ] ?? 'The requirements section below',
			'follow' => 'Open the requirements link below and read that page',
			'list'   => 'The list in the part of the page below',
			'links'  => 'The manual links in the part of the page below',
			'embed'  => $field === 'audio' ? 'The audio demos in the part of the page below' : 'The videos in the part of the page below',
		][ $mode ];
		return [ 'sel' => $sel, 'mode' => $mode, 'where' => $where ];
	}

	/** A short CSS path: the element and up to two ancestors with stable classes (or an id). */
	private static function signature( \DOMElement $el ): string {
		$parts = [];
		$node  = $el;
		$depth = 0;
		while ( $node instanceof \DOMElement && count( $parts ) < 3 && $depth < 8 && ! in_array( $node->nodeName, [ 'body', 'html' ], true ) ) {
			$depth++;
			$id  = (string) $node->getAttribute( 'id' );
			$cls = array_values( array_filter( preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) ), static fn( $c ) => $c !== '' && ! preg_match( self::CHROME_CLASS, $c ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $c ) ) );
			if ( $id !== '' && preg_match( '/^[A-Za-z][A-Za-z0-9_-]{1,40}$/', $id ) && ! preg_match( '/\d{3,}/', $id ) ) {
				array_unshift( $parts, $node->nodeName . '#' . $id );
				break;
			}
			if ( $cls || $node === $el ) {
				array_unshift( $parts, $node->nodeName . ( $cls ? '.' . implode( '.', array_slice( $cls, 0, 2 ) ) : '' ) );
			}
			$node = $node->parentNode;
		}
		return count( $parts ) >= 2 || ( $parts && str_contains( $parts[0], '#' ) ) || ( $parts && str_contains( $parts[0], '.' ) ) ? implode( ' ', $parts ) : '';
	}

	/* ── Changing a map (REST / CLI) ───────────────────────────────────── */

	/**
	 * Save (or with an empty selector, remove) a person's mapping of one field; tested at once.
	 * A selector that finds the part on none of the site's stored pages is refused: saved, it
	 * would replace a working draft with nothing (Antelope 2026-10-09: Pictures 73 → 0 of 73).
	 */
	public static function set_field( string $host, string $field, string $sel, string $mode, string $where = '', string $src = '', string $warn = '', string $via = '', int $layout = -1 ) { // $layout: that layout's own part (MapTypes), tested on its pages
		$row = self::get( $host );
		if ( ! $row ) {
			return new \WP_Error( 'mmpt_map', 'No map for this site yet: draft it first.' );
		}
		if ( ! isset( self::MODES[ $field ] ) ) {
			return new \WP_Error( 'mmpt_map', 'This part can\'t be mapped yet.' );
		}
		$type = null;
		$only = null;
		if ( $layout >= 0 ) {
			$type = MapTypes::for_layout( $host, $layout ); // made below, once the pick is checked: a refused save leaves no empty layout type
			if ( is_wp_error( $type ) ) {
				return $type;
			}
			$only = array_flip( MapTypes::layout_pages( $host )[ $layout ]['urls'] ?? [] );
		}
		$fields = $type ? (array) $type['fields'] : $row['fields'];
		$before = $fields[ $field ] ?? null;
		$sel    = trim( (string) preg_replace( '/\s+/', ' ', $sel ) );
		if ( $sel === '' ) {
			unset( $fields[ $field ] );
		} else {
			$mode = in_array( $mode, self::MODES[ $field ], true ) ? $mode : self::MODES[ $field ][0];
			$xp   = self::css_to_xpath( $sel, $field === 'pictures' );
			if ( is_wp_error( $xp ) ) {
				return $xp;
			}
			$src = $mode === 'page' ? self::source_url( $src ) : ( $mode === 'sub' ? self::sub_path( $host, $src ) : '' );
			if ( is_wp_error( $src ) ) {
				return $src;
			}
			$t = self::test( $host, $field, $sel, $mode, self::TEST_MAX, '', $src, $only );
			if ( $t['of'] > 0 && ! $t['found'] ) {
				return new \WP_Error(
					'mmpt_map_nothing',
					$mode === 'page'
						? sprintf( '%s: nothing found on that page (%s), so it was not saved.', self::FIELDS[ $field ][0], self::source_reading( $src, $sel )['detail'] )
						: ( $mode === 'sub' ? sprintf( '%s: nothing found on the products\' %s pages read so far, so it was not saved.', self::FIELDS[ $field ][0], $src )
						: sprintf( '%s: this finds nothing on any of the %d stored pages, so it was not saved. Pick the part again on the stored page, or keep the generic reader.', self::FIELDS[ $field ][0], $t['of'] ) )
				);
			}
			$fields[ $field ] = [ 'sel' => $sel, 'mode' => $mode, 'where' => mb_substr( $where !== '' ? $where : ( ( $fields[ $field ]['mode'] ?? '' ) === $mode ? (string) ( $fields[ $field ]['where'] ?? '' ) : '' ), 0, 200 ), 'source' => 'person', 'found' => $t['found'], 'of' => $t['of'], 'checked_at' => current_time( 'mysql', true ) ] + ( $src !== '' ? [ 'src' => $src ] : [] ) + ( $warn !== '' ? [ 'warn' => mb_substr( $warn, 0, 300 ) ] : [] ) + ( $via !== '' ? [ 'via' => $via ] : [] ) + ( ! empty( $before['live'] ) ? [ 'live' => $before['live'] ] : [] ); // a part in use stays in use with its new picks (as a confirmed map's edit does); a description of where only holds for the same way of reading; warn: what the live mapper flagged and the person saved anyway
		}
		if ( $layout >= 0 ) {
			if ( ! $type && isset( $fields[ $field ] ) ) {
				$type = MapTypes::for_layout( $host, $layout, true );
				if ( is_wp_error( $type ) ) {
					return $type;
				}
			}
			if ( $type ) {
				MapTypes::set_part( $host, (string) $type['type_key'], $field, $fields[ $field ] ?? null );
			}
			return self::get( $host );
		}
		if ( $layout < 0 && $mode === 'page' && self::host( $src ) !== $host && ( $before['mode'] ?? '' ) === 'page' ) { // another page the part is kept on
			$fields[ $field ] = self::with_kept( (array) $before, (array) $fields[ $field ] );
			if ( is_wp_error( $fields[ $field ] ) ) {
				return $fields[ $field ];
			}
		}
		if ( wp_json_encode( $before ) !== wp_json_encode( $fields[ $field ] ?? null ) ) {
			self::remember( $host, $field, $before );
		}
		return self::save_fields( $host, $row, $fields );
	}

	/** A pick on one of the part's kept pages, into $was: that page's pick is replaced, the others stay. @return array|\WP_Error */
	private static function with_kept( array $was, array $new ) {
		$also = array_values( (array) ( $was['also'] ?? [] ) );
		if ( (string) ( $was['src'] ?? '' ) === (string) $new['src'] ) {
			return $new + ( $also ? [ 'also' => $also ] : [] );
		}
		$also = array_values( array_filter( $also, static fn( $p ) => (string) $p['src'] !== (string) $new['src'] ) );
		if ( count( $also ) + 2 > self::KEPT_PAGES ) {
			return new \WP_Error( 'mmpt_map_pages', sprintf( 'Requirements can be kept on %d pages at most. Clear the pick on one of them first.', self::KEPT_PAGES ) );
		}
		$also[] = [ 'src' => (string) $new['src'], 'sel' => (string) $new['sel'] ];
		return [ 'also' => $also ] + $was;
	}

	/** This page taken out of a part kept on several, the next becoming its own: null when the part isn't, so clearing it clears the part. */
	public static function drop_kept( string $host, string $field, string $url ): ?array {
		$row   = self::get( $host );
		$pages = self::kept_pages( $host );
		if ( ! $row || $field !== 'requirements' || count( $pages ) < 2 || ! self::kept_pick( $host, $url ) ) {
			return null;
		}
		$left = array_values( array_filter( $pages, static fn( $p ) => ! UrlFinder::same_url( $p['src'], $url ) ) );
		$f    = $row['fields'][ $field ];
		self::remember( $host, $field, $f );
		$f['src'] = $left[0]['src'];
		$f['sel'] = $left[0]['sel'];
		unset( $f['also'] );
		$fields           = $row['fields'];
		$fields[ $field ] = $f + ( count( $left ) > 1 ? [ 'also' => array_slice( $left, 1 ) ] : [] );
		return self::save_fields( $host, $row, $fields );
	}

	/** Write a map's parts: the reader uses a confirmed map's edit at once; a draft's preview is measured again. */
	private static function save_fields( string $host, array $row, array $fields ): ?array {
		$live = $row['status'] === 'confirmed'; // the reader uses this map now: the edit changes what it reads
		// Not in use: the preview of what confirming would change no longer holds; it is measured again (and
		// confirming waits for it, drops()).
		self::update( $host, [ 'fields' => wp_json_encode( $fields ) ] + ( $live ? [ 'map_at' => current_time( 'mysql', true ) ] : [ 'reading' => wp_json_encode( [] ) ] ) );
		if ( self::sub_parts( $fields ) ) {
			self::queue( 'subs', $host ); // the rest of the products' sub-pages, in the background
		}
		self::queue( 'refresh', $host ); // what the reader finds with it now, or would once confirmed
		return self::get( $host );
	}

	/**
	 * Put one part of a draft in use on its own ($on), or back to being only the draft's (PartFill). A confirmed map's
	 * parts are all in use already. The reader sees it at once, and the site's pages are read again with it (stamp()).
	 */
	public static function set_live( string $host, string $field, bool $on ): ?array {
		$row = self::get( $host );
		if ( ! $row || empty( $row['fields'][ $field ]['sel'] ) || $row['status'] === 'confirmed' || ! empty( $row['fields'][ $field ]['live'] ) === $on ) {
			return $row;
		}
		$fields = $row['fields'];
		if ( $on ) {
			$fields[ $field ]['live'] = current_time( 'mysql', true );
		} else {
			unset( $fields[ $field ]['live'] );
		}
		self::update( $host, [ 'fields' => wp_json_encode( $fields ), 'map_at' => current_time( 'mysql', true ) ] );
		self::forget_confirmed();
		self::$renders = null;
		self::queue( 'refresh', $host ); // the gaps and the site's numbers as the reader now reads
		self::audit( $on ? 'vendor_maps.part_in_use' : 'vendor_maps.part_draft', $row['host'], [ 'field' => $field ] );
		return self::get( $host );
	}

	/* ── Undo ──────────────────────────────────────────────────────────── */

	/** Keep what a part was before a change (newest last, HISTORY_MAX per site). */
	private static function remember( string $host, string $field, ?array $before ): void {
		$all          = (array) get_option( self::HISTORY_OPTION, [] );
		$h            = self::host( $host );
		$list         = (array) ( $all[ $h ] ?? [] );
		$list[]       = [ 'field' => $field, 'before' => $before, 'at' => current_time( 'mysql', true ), 'by' => get_current_user_id() ];
		$all[ $h ]    = array_slice( $list, -self::HISTORY_MAX );
		update_option( self::HISTORY_OPTION, $all, false );
	}

	/* ── Sub-pages ─────────────────────────────────────────────────────── */

	/** A sub-page path typed or chosen: "/compatibility" (a leading slash added, the product's address left out). @return string|\WP_Error */
	private static function sub_path( string $host, string $src ) {
		$p = trim( (string) $src );
		if ( strpos( $p, '{slug}' ) === false && preg_match( '#^https?://#i', $p ) ) {
			$p = (string) wp_parse_url( $p, PHP_URL_PATH ); // a whole address pasted: its last part is the sub-page
			$p = '/' . basename( rtrim( $p, '/' ) );
		}
		$p = '/' . trim( $p, '/ ' );
		if ( ! preg_match( self::SUB_RE, $p ) ) {
			return new \WP_Error( 'mmpt_map_src', 'Enter the part of the address after the product\'s own, e.g. /compatibility, or a path with {slug} for the product\'s name, e.g. /manuals/{slug}' );
		}
		// Read a few now, so the part can be tested and the editor shows them; the runner reads the rest.
		$n = 0;
		foreach ( self::readable( self::pages( $host ), 0 ) as $pg ) {
			if ( $n >= self::SUB_SAMPLE ) {
				break;
			}
			$at = self::sub_url( (string) $pg['url'], $p );
			if ( $at !== '' && self::stored( $at )['html'] === '' ) {
				PageStore::get( $at );
			}
			$n++;
		}
		return $p;
	}

	/** The map's parts read from each product's sub-page: [ field => path ]. */
	private static function sub_parts( array $fields ): array {
		$out = [];
		foreach ( $fields as $k => $f ) {
			if ( ( $f['mode'] ?? '' ) === 'sub' && ! empty( $f['src'] ) ) {
				$out[ $k ] = (string) $f['src'];
			}
		}
		return $out;
	}

	/** The site's whole sitemap list as last read (the maps runner's `sitemap` task): { at, urls } or null. */
	public static function site_urls( string $host ): ?array {
		$dir  = function_exists( 'mmi_shared_lib_private_subdir' ) ? mmi_shared_lib_private_subdir( self::SITEMAP_DIR ) : '';
		$file = $dir !== '' ? $dir . sanitize_file_name( self::host( $host ) ) . '.json.gz' : '';
		if ( $file === '' || ! is_file( $file ) ) {
			return null;
		}
		$d = json_decode( (string) gzdecode( (string) file_get_contents( $file ) ), true );
		return is_array( $d ) && isset( $d['urls'] ) ? $d : null;
	}

	/**
	 * Runner: read the site's products' sub-pages for every part the map reads from them (paced per host; a
	 * copy younger than 30 days is kept). @return int pages read
	 */
	private static function read_subs( string $host ): int {
		$row = self::get( $host );
		$sub = $row ? self::sub_parts( $row['fields'] ) : [];
		if ( ! $sub ) {
			return 0;
		}
		$pages = array_values( array_filter( self::site_products( $host ), static fn( $p ) => $p['st'] === 'ok' ) );
		$n     = 0;
		$done  = 0;
		foreach ( $pages as $p ) {
			foreach ( array_unique( $sub ) as $path ) {
				while ( PageStore::server_busy() ) {
					sleep( 15 );
				}
				$at = self::sub_url( (string) $p['url'], $path );
				if ( $at !== '' && self::stored( $at )['html'] === '' ) {
					PageStore::get( $at );
					$n++;
				}
			}
			$done++;
			if ( $done % 10 === 0 ) {
				Module::set_state( self::STATE, [ 'note' => "reading {$host}'s product sub-pages: {$done} of " . count( $pages ), 'beat_at' => current_time( 'mysql', true ) ] );
			}
		}
		return $n;
	}

	/**
	 * A page a part is kept on, typed by a person: a public http(s) address (wp_http_validate_url(): no
	 * local or private hosts), stored, or fetched once now with that host's pacing and robots policy.
	 * @return string|\WP_Error
	 */
	private static function source_url( string $src ) {
		$src = esc_url_raw( trim( $src ), [ 'http', 'https' ] );
		if ( $src === '' || ! wp_http_validate_url( $src ) ) {
			return new \WP_Error( 'mmpt_map_src', 'Enter the page\'s full address, e.g. https://support.example.com/system-requirements' );
		}
		if ( self::stored( $src )['html'] === '' ) {
			$page = PageStore::get( $src );
			if ( ( $page['status'] ?? '' ) !== 'ok' || (string) ( $page['html'] ?? '' ) === '' ) {
				return new \WP_Error( 'mmpt_map_src', sprintf( 'That page could not be read (%s). If the site refuses the server, send it from your browser first (Vendor pages → Sites that block the server).', (string) ( $page['status'] ?? 'failed' ) ) );
			}
		}
		return $src;
	}

	/**
	 * Mapped parts that find nothing on the site's stored pages (the last check's numbers when
	 * there is one). A map with any can't be confirmed. @return string[] field keys
	 */
	public static function zero_fields( array $r ): array {
		$out = [];
		foreach ( array_diff_key( (array) $r['fields'], self::NOT_MAPPED ) as $k => $f ) {
			$found = (int) ( $f['health']['found'] ?? $f['found'] ?? 0 );
			$of    = (int) ( $f['health']['of'] ?? $f['of'] ?? 0 );
			if ( ! empty( $f['sel'] ) && $of > 0 && $found === 0 ) {
				$out[] = (string) $k;
			}
		}
		return $out;
	}

	/**
	 * Parts the reader would find on fewer stored pages with this map than without it (the stored preview,
	 * refresh()): [ [ field, was, now ], … ]. Null while there is no current preview to judge by. A map only
	 * adds to what the reader finds (owner 2026-10-10: "augment the existing data set in a non-destructive
	 * manner"), so a map with a drop can't be confirmed.
	 * @return array<int,array{0:string,1:int,2:int}>|null
	 */
	public static function drops( array $row ): ?array {
		$p = (array) ( $row['reading'] ?? [] );
		if ( empty( $p['_preview'] ) || empty( $p['_of'] ) ) {
			return null;
		}
		foreach ( (array) $row['fields'] as $f ) {
			if ( ! empty( $f['sel'] ) && ! empty( $f['checked_at'] ) && (string) $f['checked_at'] > (string) ( $p['_at'] ?? '' ) ) {
				return null; // measured before the last edit
			}
		}
		$pages = static fn( $x ) => is_array( $x ) ? (int) $x[0] + (int) $x[1] : 0;
		$base  = isset( $p['_base'] ) ? (array) $p['_base'] : (array) ( $row['coverage'] ?? [] ); // a preview measured before 2.9.21 has no _base: the drafting sample
		$out   = [];
		foreach ( array_keys( self::FIELDS ) as $k ) {
			$was = $pages( $base[ $k ] ?? null );
			$now = $pages( $p[ $k ] ?? null );
			if ( $now < $was ) {
				$out[] = [ $k, $was, $now ];
			}
		}
		return $out;
	}

	/**
	 * A draft whose parts changed since it was measured holds Confirm ("still being measured") until a refresh counts it
	 * again: looking at its gaps asks for that, at most once per MEASURE_EVERY, so a site can't wait on a button.
	 */
	private static function measure_if_stale( string $host ): void {
		$row = self::get( $host );
		$key = 'mmpt_measure_' . md5( self::host( $host ) );
		if ( ! $row || self::status_of( $row ) === 'confirmed' || ! array_filter( (array) $row['fields'], static fn( $f ) => ! empty( $f['sel'] ) ) || self::drops( $row ) !== null || get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, self::MEASURE_EVERY );
		self::queue( 'refresh', $host );
	}

	/** Field keys → "Pictures and Videos". */
	private static function field_names( array $keys ): string {
		$names = array_map( static fn( $k ) => self::FIELDS[ $k ][0] ?? $k, $keys );
		return count( $names ) > 1 ? implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . end( $names ) : (string) ( $names[0] ?? '' );
	}

	/**
	 * When what the reader does on each confirmed site last changed (confirm, an edit, a part
	 * the health check broke or recovered): host => UTC datetime. A product read since then was
	 * read with the current map.
	 * @return array<string,string>
	 */
	public static function map_times(): array {
		static $times = null;
		if ( $times === null ) {
			global $wpdb;
			$times = [];
			if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
				foreach ( $wpdb->get_results( 'SELECT host, COALESCE( map_at, confirmed_at ) at FROM ' . self::table() . " WHERE status = 'confirmed'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
					$times[ $r['host'] ] = (string) $r['at'];
				}
			}
		}
		return $times;
	}

	/**
	 * A site's map in a line, for Vendor pages filtered to that site (null without a map row).
	 * @return array{host:string,status:string,fields:string[],map_at:string,products:int,read_with:int}|null
	 */
	public static function summary( string $host ): ?array {
		$row = self::get( $host );
		if ( ! $row ) {
			return null;
		}
		global $wpdb;
		$at   = (string) ( $row['map_at'] ?: $row['confirmed_at'] );
		$ids  = array_values( array_unique( array_column( self::pages( $row['host'] ), 'id' ) ) );
		$with = ( $row['status'] === 'confirmed' && $at !== '' && $ids )
			? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Descriptions::table() . ' WHERE product_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') AND fetched_at >= %s', $at ) ) // phpcs:ignore WordPress.DB.PreparedSQL
			: 0;
		return [
			'host'      => $row['host'],
			'status'    => self::status_of( $row ),
			'fields'    => array_keys( array_filter( $row['fields'], static fn( $f ) => ! empty( $f['sel'] ) ) ),
			'map_at'    => $at,
			'products'  => count( $ids ),
			'read_with' => $with,
		];
	}

	public static function update( string $host, array $cols ): void {
		global $wpdb;
		$wpdb->update( self::table(), $cols, [ 'host' => self::host( $host ) ] );
		self::$confirmed = null;
		self::$renders   = null;
	}

	/* ── The runner (drafts and screenshots queued from the tab) ───────── */

	/** Queue work: [ 'draft', host|'*' ], [ 'shot', host ], [ 'health', host|'*' ] or [ 'refresh', host ]. */
	public static function queue( string $task, string $host ): bool {
		$s   = Module::state( self::STATE );
		$q   = (array) ( $s['queue'] ?? [] );
		$key = $task . ':' . ( $host === '*' ? '*' : self::host( $host ) );
		$q[ $key ] = [ $task, $host === '*' ? '*' : self::host( $host ) ];
		Module::set_state( self::STATE, [ 'queue' => $q ] );
		return self::kick();
	}

	/**
	 * The scrape runner read these sites' pages: bring their list numbers up to date
	 * (sites without a map row are skipped). One kick for all of them.
	 * @param string[] $hosts
	 */
	public static function queue_refresh( array $hosts ): void {
		$hosts = array_values( array_unique( array_filter( array_map( [ __CLASS__, 'host' ], $hosts ) ) ) );
		if ( ! $hosts || get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		$known = $wpdb->get_col( 'SELECT host FROM ' . self::table() . " WHERE host IN ('" . implode( "','", array_map( 'esc_sql', $hosts ) ) . "')" ); // phpcs:ignore WordPress.DB.PreparedSQL -- esc_sql on each host
		if ( ! $known ) {
			return;
		}
		$q = (array) ( Module::state( self::STATE )['queue'] ?? [] );
		foreach ( $known as $h ) {
			$q[ 'refresh:' . $h ] = [ 'refresh', $h ];
		}
		Module::set_state( self::STATE, [ 'queue' => $q ] );
		self::kick();
	}

	public static function runner_alive(): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', self::LOCK ) ) !== null;
	}

	public static function kick(): bool {
		if ( self::runner_alive() || ! ( Module::state( self::STATE )['queue'] ?? [] ) ) {
			return false;
		}
		return Scraper::spawn( 'mmpt maps run', 'maps-runner.log' );
	}

	/** WP-CLI: work the queue until it's empty. */
	public static function run(): array {
		global $wpdb;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK ) ) ) {
			return [ 'skipped' => 'another runner is working' ];
		}
		$out = [ 'drafted' => 0, 'shots' => 0, 'checked' => 0, 'refreshed' => 0, 'broke' => [], 'recovered' => [] ];
		Module::set_state( self::STATE, [ 'started_at' => current_time( 'mysql', true ), 'finished_at' => '', 'note' => '' ] );
		while ( $q = (array) ( Module::state( self::STATE )['queue'] ?? [] ) ) {
			$key = (string) array_key_first( $q );
			[ $task, $host ] = $q[ $key ];
			unset( $q[ $key ] );
			Module::set_state( self::STATE, [ 'queue' => $q ] );
			while ( PageStore::server_busy() ) {
				Module::set_state( self::STATE, [ 'note' => 'waiting: server busy' ] );
				sleep( 15 );
			}
			if ( $task === 'draft' ) {
				foreach ( $host === '*' ? self::hosts() : [ $host ] as $h ) {
					Module::set_state( self::STATE, [ 'note' => "drafting {$h}", 'beat_at' => current_time( 'mysql', true ) ] );
					self::draft( $h );
					$out['drafted']++;
				}
			} elseif ( $task === 'shot' ) {
				Module::set_state( self::STATE, [ 'note' => "screenshot of {$host}", 'beat_at' => current_time( 'mysql', true ) ] );
				self::shot( $host );
				$out['shots']++;
			} elseif ( $task === 'subs' ) {
				Module::set_state( self::STATE, [ 'note' => "reading {$host}'s product sub-pages", 'beat_at' => current_time( 'mysql', true ) ] );
				$out['subs'] = ( $out['subs'] ?? 0 ) + self::read_subs( $host );
				self::refresh( $host ); // what the parts read from them now
				$out['refreshed']++;
			} elseif ( $task === 'refresh' ) {
				Module::set_state( self::STATE, [ 'note' => "updating {$host}", 'beat_at' => current_time( 'mysql', true ) ] );
				self::refresh( $host );
				$out['refreshed']++;
			} elseif ( $task === 'health' ) {
				// Check the pages the nightly read just stored, not half-read ones: wait for that read (capped).
				$until = time() + self::HEALTH_WAIT;
				while ( Scraper::runner_alive() && time() < $until ) {
					Module::set_state( self::STATE, [ 'note' => 'health check waiting for the nightly read', 'beat_at' => current_time( 'mysql', true ) ] );
					sleep( 60 );
				}
				self::$confirmed = null; // maps confirmed while this runner was working count too
				foreach ( $host === '*' ? array_keys( self::confirmed() ) : [ $host ] as $h ) {
					Module::set_state( self::STATE, [ 'note' => "checking {$h}", 'beat_at' => current_time( 'mysql', true ) ] );
					$r = self::health( $h );
					$out['checked'] += isset( $r['skipped'] ) ? 0 : 1;
					if ( ! empty( $r['broke'] ) || ! empty( $r['recovered'] ) ) {
						self::refresh( $h ); // the reader reads this site differently now
					}
					foreach ( [ 'broke', 'recovered' ] as $k ) {
						foreach ( $r[ $k ] ?? [] as $f ) {
							$out[ $k ][] = "{$h}:{$f}";
						}
					}
				}
			}
		}
		Module::set_state( self::STATE, [ 'finished_at' => current_time( 'mysql', true ), 'note' => '', 'last_run' => $out ] );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		Module::info( 'Vendor maps runner finished', $out );
		if ( $out['checked'] ) {
			self::audit( 'vendor_maps.health', '*', [ 'checked' => $out['checked'], 'broke' => $out['broke'], 'recovered' => $out['recovered'] ] );
		}
		return $out;
	}

	/** Every site with a verified product URL and a stored page. @return string[] */
	public static function hosts(): array {
		global $wpdb;
		$hosts = $wpdb->get_col(
			"SELECT DISTINCT pg.host FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
			 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
			 JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( u.meta_value )
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'"
		); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
		return array_values( array_unique( array_map( [ __CLASS__, 'host' ], $hosts ) ) );
	}

	/* ── Sample screenshot ─────────────────────────────────────────────── */

	/** Screenshot one product page of the site with its mapped parts' boxes (CLI, Chromium). */
	public static function shot( string $host ): array {
		$row = self::get( $host );
		if ( ! $row || ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return [ 'status' => 'not_cli' ];
		}
		$pages = self::readable( self::pages( $host ), 0 );
		$url   = (string) ( $row['shot']['url'] ?? ( $pages[0]['url'] ?? '' ) );
		if ( $url === '' ) {
			return [ 'status' => 'no_page' ];
		}
		$policy = PageStore::policy( self::host( $host ) );
		if ( $policy['robots'] === 'honor' && ! VendorFetcher::robots_allows( $url, (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			return [ 'status' => 'robots_disallow' ];
		}
		$sels = [];
		foreach ( $row['fields'] as $f => $d ) {
			if ( ! empty( $d['sel'] ) && ( $d['mode'] ?? '' ) !== 'page' ) {
				$sels[ $f ] = [ 'sel' => $d['sel'], 'mode' => $d['mode'] ?? '' ];
			}
		}
		$dir = mmi_shared_lib_private_subdir( 'mmpt-maps' );
		if ( $dir === '' ) {
			return [ 'status' => 'no_dir' ];
		}
		$file   = $dir . sanitize_file_name( self::host( $host ) ) . '.jpg';
		$node   = is_executable( '/usr/bin/node' ) ? '/usr/bin/node' : '/usr/local/bin/node';
		$home   = (string) getenv( 'HOME' ) ?: '/home/runcloud';
		$chrome = glob( $home . '/.cache/ms-playwright/chromium-*/chrome-linux64/chrome' );
		$script = dirname( __DIR__, 3 ) . '/' . self::SHOT_SCRIPT;
		if ( ! $chrome || ! is_readable( $script ) ) {
			return [ 'status' => 'no_browser' ];
		}
		\MMI_API_Throttler::throttle( VendorFetcher::throttle_key( (string) wp_parse_url( $url, PHP_URL_HOST ) ), [ 'min_gap_ms' => $policy['gap_ms'] ] );
		$proc = proc_open(
			[ 'timeout', (string) self::SHOT_TIMEOUT, $node, $script, $url, $file, wp_json_encode( $sels ) ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes,
			null,
			[ 'NODE_PATH' => $home . '/.cache/mmi-browser-checks/node_modules', 'MMI_RENDER_CHROME' => (string) end( $chrome ), 'HOME' => $home, 'PATH' => '/usr/local/bin:/usr/bin:/bin' ]
		);
		if ( ! is_resource( $proc ) ) {
			return [ 'status' => 'start_failed' ];
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		proc_close( $proc );
		$lines = array_filter( explode( "\n", trim( $stdout ) ) );
		$res   = (array) json_decode( (string) end( $lines ), true );
		if ( ( $res['status'] ?? '' ) !== 'ok' || ! is_readable( $file ) ) {
			return [ 'status' => (string) ( $res['status'] ?? 'failed' ) ];
		}
		$title = '';
		foreach ( $pages as $p ) {
			if ( $p['url'] === $url ) {
				$title = $p['title'];
				break;
			}
		}
		self::update( $host, [ 'shot' => wp_json_encode( [ 'url' => $url, 'title' => $title, 'file' => basename( $file ), 'width' => (int) ( $res['width'] ?? 0 ), 'height' => (int) ( $res['height'] ?? 0 ), 'boxes' => (array) ( $res['boxes'] ?? [] ), 'taken_at' => current_time( 'mysql', true ) ] ) ] );
		return [ 'status' => 'ok' ];
	}

	/* ── Stored page for the tab's live view ───────────────────────────── */

	/** Shown without the vendor's JavaScript: undo what scripts would have revealed (fade-ins, preloaders). */
	const TRACKERS = '(?:facebook\\.com/tr|doubleclick\\.net|google-analytics\\.com|googletagmanager\\.com|bat\\.bing\\.com|px\\.ads\\.linkedin\\.com|analytics\\.twitter\\.com|t\\.co/i/adsct)';
	const LIVE_CSS = '[class*="animat"],[class*="aniamtion"],[class*="reveal"],[data-animation],[data-aos],.wow,.lazy,.lazyload,.lazyloaded{opacity:1!important;visibility:visible!important;transform:none!important;animation:none!important}[class*="preloader"],[id*="preloader"],[class*="page-loader"]{display:none!important}html{scroll-behavior:auto!important}.mmpt-gallery{display:flex!important;flex-wrap:wrap!important;align-items:flex-start!important;gap:8px!important;visibility:visible!important;opacity:1!important;height:auto!important;max-height:none!important;overflow:visible!important;position:static!important;padding:8px!important;margin:8px 0!important;border:2px dashed #8c8f94!important;background:#fff!important}.mmpt-gallery::before{content:"Pictures the vendor\'s script shows from the page\'s data";flex-basis:100%;font:13px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;color:#50575e}.mmpt-gallery img{display:block!important;max-height:180px!important;width:auto!important;max-width:30%!important;opacity:1!important;visibility:visible!important}.mmpt-json{display:block!important;visibility:visible!important;opacity:1!important;height:auto!important;max-height:none!important;overflow:visible!important;position:static!important;margin:8px 0!important;padding:8px 12px!important;border:2px dashed #8c8f94!important;background:#fff!important;color:#1d2327!important;text-align:left!important}.mmpt-json-part+.mmpt-json-part{margin-top:12px;padding-top:12px;border-top:1px solid #dcdcde}.mmpt-json-part::before{content:"From the page\'s data: " attr(data-mmpt-key);display:block;margin-bottom:4px;font:12px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;color:#50575e}';

	/**
	 * A stored vendor page sent to an admin's frame: no scripts (removed, and a CSP), no base or refresh, no
	 * tracking frames or pixels, links resolved against its own address; 404 when nothing is stored. Exits.
	 * Vendor maps' stored page and the URL review's stored copy (UrlReview::serve()) both send it this way.
	 */
	public static function send_stored( string $url, string $html ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( "Content-Security-Policy: script-src 'none'; object-src 'none'; form-action 'none'; frame-ancestors 'self'; upgrade-insecure-requests" ); // an http:// page's styles and pictures would be blocked as mixed content in our https admin
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $html === '' ) {
			status_header( 404 );
			echo '<!doctype html><meta charset="utf-8"><p>This page is not stored.</p>';
			exit;
		}
		$html  = VendorPage::script_content( $html ); // pictures and content a script would draw (imgix, JSON galleries, HTML in JSON), shown as the reader reads them
		$clean = preg_replace(
			[
				'#<script\b[^>]*>.*?</script\s*>#is',
				'#<base\b[^>]*>#i',
				'#<meta\b[^>]*http-equiv=["\']?refresh[^>]*>#i',
				'#<iframe\b(?![^>]*(?:youtube(?:-nocookie)?\.com|player\.vimeo\.com))[^>]*>.*?</iframe\s*>#is', // tag managers and ad frames; video embeds stay (videos are mapped)
				'#<img\b[^>]*\bsrc=["\'][^"\']*' . self::TRACKERS . '[^>]*>#i', // tracking pixels never fire from the owner's browser
			],
			'',
			$html
		);
		$html  = is_string( $clean ) ? $clean : $html; // a regex failure on a huge page still leaves the CSP in charge
		$base  = is_ssl() ? (string) preg_replace( '#^http://#i', 'https://', $url ) : $url;
		$head  = '<base href="' . esc_url( $base ) . '"><meta name="referrer" content="no-referrer"><style>' . self::LIVE_CSS . '</style>';
		$done  = preg_replace_callback( '#<head\b[^>]*>#i', static fn( $m ) => $m[0] . $head, $html, 1, $n );
		echo $n ? $done : $head . $html; // phpcs:ignore WordPress.Security.EscapeOutput -- the vendor's stored page, served script-free under a CSP to an admin
		exit;
	}

	/* ── Health check (nightly) ────────────────────────────────────────── */

	/** 10:00 site time, after the 03:30 URL pass and the nightly read it starts. */
	public static function schedule_daily(): void {
		if ( ! wp_next_scheduled( self::HEALTH_HOOK ) ) {
			$ts = strtotime( 'tomorrow 10:00', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			wp_schedule_event( $ts, 'daily', self::HEALTH_HOOK );
		}
	}

	/** @return string[] hosts with a confirmed map */
	public static function confirmed_hosts(): array {
		return array_keys( self::confirmed() );
	}

	/** The cron event only queues the check; the detached runner does the reading. */
	public static function queue_health(): void {
		if ( self::confirmed() ) {
			self::queue( 'health', '*' );
		}
	}

	/** @return string[] fields of a map the health check found broken */
	public static function broken_fields( array $r ): array {
		return array_keys( array_filter( array_diff_key( (array) $r['fields'], self::NOT_MAPPED ), static fn( $f ) => ! empty( $f['sel'] ) && ! empty( $f['health']['broken'] ) ) );
	}

	/**
	 * Re-test a confirmed map's fields on the site's stored pages. A field is broken when it
	 * now works on no page, or on under half the share of pages it worked on when a person
	 * saw it; the reader then leaves it out until a person edits it or confirms the map again.
	 * A field that works again is used again. (Before 2026-10-09 a part confirmed while it
	 * found nothing was never flagged: "under half of nothing" never happens.)
	 * @return array{host:string, broke?:string[], recovered?:string[], skipped?:string}
	 */
	public static function health( string $host ): array {
		$row = self::get( $host );
		if ( ! $row || $row['status'] !== 'confirmed' ) {
			return [ 'host' => self::host( $host ), 'skipped' => 'not confirmed' ];
		}
		$fields = $row['fields'];
		$out    = [ 'host' => $row['host'], 'broke' => [], 'recovered' => [] ];
		foreach ( array_diff_key( $fields, self::NOT_MAPPED ) as $f => $d ) {
			if ( empty( $d['sel'] ) ) {
				continue;
			}
			$t      = self::test( $row['host'], $f, (string) $d['sel'], (string) ( $d['mode'] ?? '' ), self::TEST_MAX, '', (string) ( $d['src'] ?? '' ) );
			$was    = (int) ( $d['of'] ?? 0 ) ? (int) $d['found'] / (int) $d['of'] : 0;
			$now    = $t['of'] ? $t['found'] / $t['of'] : 0;
			$broken = $t['of'] >= self::HEALTH_MIN && ( ! $t['found'] || ( $was > 0 && $now < $was * self::HEALTH_DROP ) );
			if ( $broken && empty( $d['health']['broken'] ) ) {
				$out['broke'][] = $f;
			} elseif ( ! $broken && ! empty( $d['health']['broken'] ) ) {
				$out['recovered'][] = $f;
			}
			$fields[ $f ]['health'] = [ 'found' => $t['found'], 'of' => $t['of'], 'broken' => $broken, 'at' => current_time( 'mysql', true ) ];
		}
		$changed = $out['broke'] || $out['recovered']; // a broken part leaves the reader's map, a recovered one rejoins it
		self::update( $row['host'], [ 'fields' => wp_json_encode( $fields ), 'checked_at' => current_time( 'mysql', true ) ] + ( $changed ? [ 'map_at' => current_time( 'mysql', true ) ] : [] ) );
		if ( $out['broke'] ) {
			Module::warn( 'Vendor map stopped matching', [ 'host' => $row['host'], 'fields' => $out['broke'] ] );
		}
		return $out;
	}

	/* ── Filling products (Scrape) ─────────────────────────────────────── */

	/**
	 * A product's empty features, manuals, videos, audio, version and (physical products) weight and size, from its
	 * site's confirmed map or, once `fields:vendor_page` is approved, the generic reader (Scrape).
	 * Never over a stored value (any registry source) or a field lock; a field it fills is locked against
	 * imports (owner 2026-10-16: product data comes from the maker, not a supplier feed).
	 * @return array<string,string> field → fill | kept | locked
	 */
	/**
	 * What fill_product() would do with each mapped part of one page for a product, writing nothing:
	 * [ part => [ action, value ] ], action fill (the product has none), kept (it has some) or locked
	 * (imports may not write it, and neither does a map). fill_product() writes the fills; the editor's
	 * product preview (ProductPreview) shows them, so both decide the same way.
	 * @return array<string,array{action:string,value:mixed}>
	 */
	public static function plan_fill( int $pid, string $url, string $html, bool $fetch = false ): array {
		if ( ! class_exists( '\MMI_Product_Content_Registry' ) ) {
			return [];
		}
		$vals = array_filter( self::extras( $url, $html, $fetch ) );
		if ( ! empty( Enrichment::rules()['fields:vendor_page']['approved'] ) && ! PageEvents::is_home( $url ) ) { // a home page's media are the site's, not this product's
			$vals += self::generic_fills( $pid, $url, $html ); // a mapped part wins; the generic reader gives the rest
		}
		$out = [];
		foreach ( array_intersect_key( $vals, self::FILLS ) as $f => $v ) {
			$concept = self::FILLS[ $f ];
			if ( ! $v || ( $f === 'features' && ( count( $v ) < 3 || VendorPage::is_pager( $v ) ) ) ) { // whichever reader it came from
				continue;
			}
			$action    = class_exists( 'MMI_Pipeline_Field_Locks' ) && \MMI_Pipeline_Field_Locks::is_locked( $pid, \MMI_Product_Content_Registry::canonical_key( $concept ) )
				? 'locked'
				: ( \MMI_Product_Content_Registry::count( $pid, $concept ) > 0 ? 'kept' : 'fill' );
			$out[ $f ] = [ 'action' => $action, 'value' => in_array( $f, [ 'manual', 'audio' ], true ) ? array_map( static fn( $l ) => [ 'label' => $l['label'], 'value' => $l['url'] ?? $l['value'] ?? '' ], $v ) : $v ];
		}
		if ( ! empty( $vals['size'] ) ) {
			$out['size'] = [ 'action' => self::size_action( $pid ), 'value' => $vals['size'] ];
		}
		return $out;
	}

	/**
	 * What the generic reader finds (no map) for the parts Scrape fills, plus weight and size: the values Coverage
	 * counts (VendorPage::fields()). Videos: YouTube only (the registry's video rows are YouTube embeds).
	 * @return array<string,mixed>
	 */
	private static function generic_fills( int $pid, string $url, string $html ): array {
		$x   = VendorPage::extract( $html, $url, html_entity_decode( get_the_title( $pid ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$f   = VendorPage::fields( $html, $url, $x );
		$out = [
			'features' => $f['features'],
			'manual'   => $f['manuals'],
			'videos'   => array_values( array_filter( $f['videos'], static fn( $u ) => strpos( (string) $u, 'youtube.com/' ) !== false ) ),
			'audio'    => $f['audio'],
		];
		if ( in_array( SharedPages::relation( $pid, $url )['kind'], [ 'own', 'one' ], true ) && ! in_array( 'page_version_differs', (array) $x['flags'], true ) ) {
			$out['version'] = $f['version'];
		}
		if ( $f['size']['weight'] || $f['size']['dims'] ) {
			$out['size'] = $f['size'];
		}
		return array_filter( $out );
	}

	/** fill when a physical product has no weight and no dimensions, kept when it has any, locked when imports may not write them. */
	private static function size_action( int $pid ): string {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
		if ( ! $p || $p->is_virtual() || $p->is_downloadable() ) {
			return 'kept';
		}
		if ( class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
			foreach ( self::SIZE_FIELDS as $key ) {
				if ( \MMI_Pipeline_Field_Locks::is_locked( $pid, $key ) ) {
					return 'locked';
				}
			}
		}
		return ( $p->get_weight() . $p->get_length() . $p->get_width() . $p->get_height() ) === '' ? 'fill' : 'kept';
	}

	/** The page's weight [ n, unit ] and dimensions [ l, w, h, unit ] in the store's units. */
	private static function fill_size( int $pid, array $size ): bool {
		$p = wc_get_product( $pid );
		if ( ! $p ) {
			return false;
		}
		if ( $size['weight'] ) {
			$p->set_weight( wc_format_decimal( wc_get_weight( (float) $size['weight'][0], (string) get_option( 'woocommerce_weight_unit', 'lbs' ), (string) $size['weight'][1] ), 2 ) );
		}
		if ( $size['dims'] ) {
			[ $l, $w, $h, $unit ] = $size['dims'];
			$to = (string) get_option( 'woocommerce_dimension_unit', 'in' );
			$p->set_length( wc_format_decimal( wc_get_dimension( (float) $l, $to, (string) $unit ), 2 ) );
			$p->set_width( wc_format_decimal( wc_get_dimension( (float) $w, $to, (string) $unit ), 2 ) );
			$p->set_height( wc_format_decimal( wc_get_dimension( (float) $h, $to, (string) $unit ), 2 ) );
		}
		$p->save();
		if ( class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
			foreach ( self::SIZE_FIELDS as $field => $key ) {
				if ( ( $field === 'weight' && $size['weight'] ) || ( $field !== 'weight' && $size['dims'] ) ) {
					\MMI_Pipeline_Field_Locks::lock( $pid, $key );
				}
			}
		}
		return true;
	}

	public static function fill_product( int $pid, string $url, string $html, bool $fetch = false, ?array $only = null ): array { // $only: these parts only (Scraper::$only)
		if ( ! class_exists( '\MMI_Product_Content_Registry' ) ) {
			return [];
		}
		$out = [];
		foreach ( self::plan_fill( $pid, $url, $html, $fetch ) as $f => $p ) {
			if ( $only !== null && ! in_array( $f, $only, true ) ) {
				continue;
			}
			if ( $p['action'] !== 'fill' ) {
				$out[ $f ] = $p['action'];
				continue;
			}
			if ( $f === 'size' ) {
				$out[ $f ] = self::fill_size( $pid, (array) $p['value'] ) ? 'fill' : 'kept';
				continue;
			}
			$concept   = self::FILLS[ $f ];
			$out[ $f ] = \MMI_Product_Content_Registry::store( $pid, $concept, $p['value'] ) ? 'fill' : 'kept';
			if ( $out[ $f ] === 'fill' ) { // kept after the product's vendor URL changes, so what the old page gave can be told apart
				update_post_meta( $pid, self::FILL_SRC, [ $f => $url ] + (array) get_post_meta( $pid, self::FILL_SRC, true ) );
			}
			if ( $out[ $f ] === 'fill' && class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
				\MMI_Pipeline_Field_Locks::lock( $pid, \MMI_Product_Content_Registry::canonical_key( $concept ) ); // a key imports never write (manuals) isn't lockable and is dropped
			}
		}
		// A map fill is recorded per product; generic fills run in bulk and are recorded once per pass (`wp mmpt scrape-queue`).
		if ( in_array( 'fill', $out, true ) && class_exists( 'MMI_Audit_Log' ) && isset( self::confirmed()[ self::host( $url ) ] ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'product_content.fill_from_map', [ 'object_type' => 'product', 'object_id' => (string) $pid, 'details' => [ 'host' => self::host( $url ), 'fields' => $out, 'locked' => true ] ] );
		}
		return $out;
	}

	/* ── Status and list ───────────────────────────────────────────────── */

	/** What the list shows: broken (a confirmed field stopped matching) / confirmed / off / refused / draft (a draft improves something) / needs / auto. */
	public static function status_of( array $r ): string {
		if ( $r['status'] === 'confirmed' && self::broken_fields( $r ) ) {
			return 'broken';
		}
		if ( in_array( $r['status'], [ 'confirmed', 'off' ], true ) ) {
			return $r['status'];
		}
		$s  = $r['stats'];
		$of = (int) ( $r['coverage']['_of'] ?? 0 );
		if ( ( $s['pages'] ?? 0 ) && ( $s['refused'] ?? 0 ) / $s['pages'] >= 0.5 ) {
			return 'refused';
		}
		foreach ( $r['fields'] as $f ) {
			if ( ( $f['source'] ?? '' ) !== '' && ! empty( $f['sel'] ) ) {
				return 'draft';
			}
		}
		$weak = ! $of || ( $r['coverage']['pictures'][0] ?? 0 ) / $of < 0.5 || ( $r['coverage']['description'][0] ?? 0 ) / $of < 0.5; // no page counted: not known to read fine
		return $weak ? 'needs' : 'auto';
	}

	private static function list_row( array $r ): array {
		return [
			'host'     => $r['host'],
			'status'   => self::status_of( $r ),
			'pattern'  => $r['pattern'],
			'render'   => $r['render'],
			'stats'    => array_diff_key( (array) $r['stats'], [ 'by' => 1 ] ),
			'coverage' => $r['coverage'],
			'mapped'   => array_map( static fn( $f ) => [ 'found' => (int) ( $f['found'] ?? 0 ), 'of' => (int) ( $f['of'] ?? 0 ), 'broken' => ! empty( $f['health']['broken'] ) ], array_diff_key( (array) $r['fields'], self::NOT_MAPPED ) ),
			'broken'   => self::broken_fields( $r ),
			'zero'     => self::zero_fields( $r ),
			'reading'  => array_diff_key( (array) ( $r['reading'] ?? [] ), [ '_pictures' => 1 ] ) + ( isset( $r['reading']['_pictures'] ) ? [ '_pictures' => array_diff_key( (array) $r['reading']['_pictures'], [ 'by' => 1 ] ) ] : [] ), // what the reader finds now with the confirmed map (refresh()); empty without one
			'gaps'     => $r['gaps']['summary'] ?? null, // SiteGaps::summary(): products by page state, products to gain, parts to map
			'map_at'   => (string) ( ( $r['map_at'] ?? '' ) ?: $r['confirmed_at'] ),
			'checked'  => (string) $r['checked_at'],
		];
	}

	/* ── REST (Product Titles → Vendor maps) ───────────────────────────── */

	public static function routes(): void {
		$admin = static fn() => current_user_can( 'manage_woocommerce' );
		$host  = [ 'host' => [ 'validate_callback' => static fn( $v ) => (bool) preg_match( '/^[a-z0-9.-]{3,190}$/', (string) $v ) ] ];
		register_rest_route(
			Module::NS,
			'/maps',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static function () {
					self::maybe_install();
					global $wpdb;
					$rows = array_map( [ __CLASS__, 'decode' ], $wpdb->get_results( 'SELECT * FROM ' . self::table(), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					return rest_ensure_response( [ 'rows' => array_map( [ __CLASS__, 'list_row' ], $rows ), 'fields' => self::parts(), 'modes' => self::MODES, 'fills' => array_keys( self::FILLS ), 'reasons' => Pictures::REASONS, 'runner' => self::runner_state() ] );
				},
			]
		);
		register_rest_route( Module::NS, '/maps/runner', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( self::runner_state() ) ] );
		register_rest_route(
			Module::NS,
			'/maps/draft',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$h = (string) $r->get_param( 'host' );
					$h = preg_match( '/^[a-z0-9.-]{3,190}$/', $h ) ? $h : '*';
					$started = self::queue( 'draft', $h );
					self::audit( 'vendor_maps.draft', $h, [ 'started' => $started ] );
					return rest_ensure_response( [ 'started' => $started, 'runner' => self::runner_state() ] );
				},
			]
		);
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'args'                => $host,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$row = self::get( (string) $r['host'] );
					if ( ! $row ) {
						return new \WP_REST_Response( [ 'message' => 'No map for this site yet.' ], 404 );
					}
					return rest_ensure_response( // the site drawer's header and Site menu (site-drawer.js); its gaps come from /gaps
						self::list_row( $row ) + [
							'fields'    => array_diff_key( $row['fields'], self::NOT_MAPPED ),
							'layouts'   => MapTypes::layouts_view( $row['host'] ), // each page layout and the parts it reads its own way
							'confirmed' => (string) $row['confirmed_at'],
							'drafted'   => (string) $row['drafted_at'],
						]
					);
				},
			]
		);
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/settings',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $host,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$pattern = sanitize_text_field( (string) $r->get_param( 'pattern' ) );
					if ( $pattern !== '' && ! preg_match( '#^/[A-Za-z0-9/{}._~%-]*$#', $pattern ) ) {
						return new \WP_REST_Response( [ 'message' => 'A pattern is a path like /products/{slug}/.' ], 400 );
					}
					self::update( (string) $r['host'], [ 'pattern' => $pattern, 'render' => $r->get_param( 'render' ) ? 1 : 0 ] );
					self::audit( 'vendor_maps.settings', (string) $r['host'], [ 'render' => (bool) $r->get_param( 'render' ) ] );
					return rest_ensure_response( [ 'saved' => true ] );
				},
			]
		);
		foreach ( [ 'confirm' => 'confirmed', 'off' => 'off', 'reopen' => 'draft' ] as $action => $status ) {
			register_rest_route(
				Module::NS,
				"/maps/(?P<host>[a-z0-9.-]+)/{$action}",
				[
					'methods'             => 'POST',
					'permission_callback' => $admin,
					'args'                => $host,
					'callback'            => static function ( \WP_REST_Request $r ) use ( $action, $status ) {
						$row = self::get( (string) $r['host'] );
						if ( ! $row ) {
							return new \WP_REST_Response( [ 'message' => 'No map for this site yet.' ], 404 );
						}
						if ( $status === 'confirmed' ) {
							$refused = self::confirm_guarded( $row );
							if ( $refused ) {
								return $refused;
							}
						} else {
							self::update( (string) $r['host'], [ 'status' => $status, 'map_at' => current_time( 'mysql', true ) ] );
							self::audit( 'vendor_maps.' . $action, (string) $r['host'] );
							self::queue( 'refresh', (string) $r['host'] ); // the list's numbers follow what the reader now does
						}
						return rest_ensure_response( [ 'status' => self::status_of( (array) self::get( (string) $r['host'] ) ), 'runner' => self::runner_state() ] );
					},
				]
			);
		}
		// The site's gaps (SiteGaps::matrix()): every product against its page, per kind of content, and what one Fill would do.
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/gaps',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'args'                => $host,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$m = SiteGaps::matrix( (string) $r['host'] );
					if ( ! $m ) {
						return new \WP_REST_Response( [ 'message' => 'No map for this site yet.' ], 404 );
					}
					self::measure_if_stale( (string) $r['host'] );
					$m['fill'] = array_diff_key( $m['fill'], [ 'scrape' => 1, 'pictures' => 1 ] ) + [ 'scrape' => count( $m['fill']['scrape'] ), 'pictures' => count( $m['fill']['pictures'] ) ];
					return rest_ensure_response( $m );
				},
			]
		);
		/*
		 * Fill (owner 2026-10-18: one click, "Confirm map & fill N gaps on M products"): a draft is confirmed through
		 * Confirm's own guards, then exactly the products with a gain are queued: a scrape (descriptions, requirements,
		 * features, manuals, videos; each writer fills empty or thin fields only and locks them) and the picture
		 * runner. Refused while pages are being checked, and when the numbers moved since the screen showed them.
		 */
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/fill',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $host,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$row = self::get( (string) $r['host'] );
					$m   = $row ? SiteGaps::matrix( $row['host'] ) : null;
					if ( ! $m ) {
						return new \WP_REST_Response( [ 'message' => 'No map for this site yet.' ], 404 );
					}
					$plan = $m['fill'];
					if ( $plan['why'] !== '' ) {
						return new \WP_REST_Response( [ 'message' => $plan['why'] ], 409 );
					}
					$expect = (int) $r->get_param( 'fields' );
					if ( $expect && $expect !== (int) $plan['fields'] ) {
						return new \WP_REST_Response( [ 'message' => sprintf( 'Nothing was filled: the gaps changed since the screen showed them (%d fields now, not %d). Look again, then fill.', $plan['fields'], $expect ), 'fields' => $plan['fields'] ], 409 );
					}
					// Products the person left out in the review (owner 2026-10-25): neither scraped nor given pictures now.
					$skip             = array_map( 'intval', (array) $r->get_param( 'skip' ) );
					$plan['scrape']   = array_values( array_diff( $plan['scrape'], $skip ) );
					$plan['pictures'] = array_values( array_diff( $plan['pictures'], $skip ) );
					if ( ! $plan['scrape'] && ! $plan['pictures'] ) {
						return new \WP_REST_Response( [ 'message' => 'Nothing was filled: every product was left out.' ], 409 );
					}
					$confirmed = false;
					if ( $plan['confirm'] ) {
						$refused = self::confirm_guarded( $row );
						if ( $refused ) {
							return $refused;
						}
						$confirmed = true;
					}
					$since = current_time( 'mysql', true );
					$run     = FillRun::start( $row['host'], $plan['scrape'], $plan['pictures'] ); // what each product has now, for "What this Fill wrote" and Undo
					$n       = $plan['scrape'] ? Scraper::enqueue( $plan['scrape'], 'scrape', false, 'manual' ) : 0; // the stored copy: the page the gaps were read from
					$started = $n ? Scraper::kick() : false;
					$p       = $plan['pictures'] ? PageEvents::queue_pictures( $plan['pictures'], 'manual' )['queued'] : 0; // the runner starts if any were queued
					self::audit( 'vendor_maps.fill', $row['host'], [ 'confirmed' => $confirmed, 'products' => count( array_unique( array_merge( $plan['scrape'], $plan['pictures'] ) ) ), 'fields' => $plan['per'], 'scrape' => $n, 'pictures' => $p, 'run' => $run, 'left_out' => array_values( array_intersect( $skip, array_merge( $m['fill']['scrape'], $m['fill']['pictures'] ) ) ) ] );
					return rest_ensure_response( [ 'confirmed' => $confirmed, 'scrape' => $n, 'pictures' => $p, 'started' => $started, 'since' => $since, 'per' => $plan['per'], 'products' => count( array_unique( array_merge( $plan['scrape'], $plan['pictures'] ) ) ), 'run' => $run ] );
				},
			]
		);
		// What the site's last Fills wrote, per product, and Undo (FillRun).
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/fills',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'args'                => $host,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( [ 'runs' => FillRun::runs( (string) $r['host'] ) ] ),
			]
		);
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/fills/undo',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $host + [ 'run' => [ 'required' => true, 'validate_callback' => static fn( $v ) => (bool) preg_match( '/^[0-9]{14}-[A-Za-z0-9]{4}$/', (string) $v ) ] ],
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = FillRun::undo( (string) $r['host'], (string) $r['run'], array_map( 'intval', (array) $r->get_param( 'ids' ) ) );
					return rest_ensure_response( $res + [ 'runs' => FillRun::runs( (string) $r['host'] ) ] );
				},
			]
		);
		// A product's system requirements where its page differs (a "To review" cell): use the page's, keep ours, or undo.
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/requirements',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $host + [
					'id'     => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'choice' => [ 'required' => true, 'validate_callback' => static fn( $v ) => in_array( $v, [ 'use', 'keep', 'undo' ], true ) ],
				],
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = FillRun::decide_requirements( (string) $r['host'], (int) $r['id'], (string) $r['choice'] );
					return $res['state'] === 'error' ? new \WP_REST_Response( [ 'message' => $res['note'] ], 409 ) : rest_ensure_response( $res );
				},
			]
		);
		// A gap a person closes (SiteGaps::decide_none()): its page has none of this, for every product reading that page; or undo.
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/none',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $host + [
					'id'    => [ 'required' => true, 'sanitize_callback' => 'absint' ],
					'field' => [ 'required' => true, 'validate_callback' => static fn( $v ) => isset( SiteGaps::FIELDS[ (string) $v ] ) ],
					'undo'  => [ 'required' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ],
				],
				'callback'            => static function ( \WP_REST_Request $r ) {
					$res = SiteGaps::decide_none( (string) $r['host'], (int) $r['id'], (string) $r['field'], (bool) $r['undo'] );
					return $res['state'] === 'error' ? new \WP_REST_Response( [ 'message' => $res['note'] ], 409 ) : rest_ensure_response( $res );
				},
			]
		);
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/reread',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $host,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$row = self::get( (string) $r['host'] );
					if ( ! $row || $row['status'] !== 'confirmed' ) {
						// The reader uses a confirmed map only: a re-read of a draft reads the site exactly as before.
						return new \WP_REST_Response( [ 'message' => 'Confirm the map first: a re-read uses a confirmed map only.' ], 409 );
					}
					$ids = array_values( array_unique( array_column( self::pages( (string) $r['host'] ), 'id' ) ) );
					if ( ! $ids ) {
						return new \WP_REST_Response( [ 'message' => 'No products to read.' ], 400 );
					}
					$n       = Scraper::enqueue( $ids, 'scrape', true, 'manual' ); // the picture runner waits while these are read (Activity)
					$started = Scraper::kick();
					self::audit( 'vendor_maps.reread', (string) $r['host'], [ 'count' => $n ] );
					return rest_ensure_response( [ 'queued' => $n, 'started' => $started ] );
				},
			]
		);
		// The editor's product preview: one product's fields now, what its page gives with the map as it is, and what would happen (read-only).
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/product',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'args'                => $host + [ 'id' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
				'callback'            => static function ( \WP_REST_Request $r ) {
					$try  = self::trial_parse( (string) $r->get_param( 'try' ) ); // picks not saved yet (the edit screen while picking)
					$plan = self::with_trial( $try, static fn() => ProductPreview::plan( (string) $r['host'], (int) $r['id'], true, (bool) $r->get_param( 'review' ) ) ); // review: Review pictures (Pictures::plan())
					if ( ! $plan ) {
						return new \WP_REST_Response( [ 'message' => 'That product has no verified page on this site.' ], 404 );
					}
					$writes = ProductPreview::writes( $plan );
					unset( $plan['raw'] ); // the values themselves stay on the server
					return rest_ensure_response( $plan + [ 'scraped' => ProductPreview::scraped( (string) $r['host'], (int) $r['id'] ), 'writes' => (object) $writes, 'written' => ProductPreview::written( (int) $r['id'] ) ] );
				},
			]
		);
	}

	/**
	 * Confirm a site's map as it is now (a person's sign-off; Confirm and Fill): refused (409, with why) while what
	 * confirming changes isn't measured yet, when the map would take something away, or when a part finds nothing.
	 * The last check's numbers become each part's baseline and its health mark goes. Null when confirmed.
	 */
	private static function confirm_guarded( array $row ): ?\WP_REST_Response {
		if ( $row['status'] !== 'confirmed' ) {
			$drops = self::drops( $row );
			if ( $drops === null ) {
				self::queue( 'refresh', $row['host'] );
				return new \WP_REST_Response( [ 'message' => 'Not confirmed yet: what confirming would change is still being measured. Try again in a minute.' ], 409 );
			}
			if ( $drops ) {
				return new \WP_REST_Response(
					[
						'message' => sprintf( 'Not confirmed: with this map the reader would find %s on fewer pages. A map only adds to what the reader finds; fix the part that causes it, or use the generic reader for it.', implode( ', ', array_map( static fn( $d ) => sprintf( '%s (%d → %d)', self::FIELDS[ $d[0] ][0], $d[1], $d[2] ), $drops ) ) ),
						'drops'   => $drops,
					],
					409
				);
			}
		}
		$zero = self::zero_fields( $row );
		if ( $zero ) {
			// A part that finds nothing adds nothing and hides a mistake (Antelope 2026-10-09).
			return new \WP_REST_Response( [ 'message' => sprintf( 'Not confirmed: %s %s nothing on this site\'s stored pages. Pick %s again, or switch %s to the generic reader.', self::field_names( $zero ), count( $zero ) === 1 ? 'finds' : 'find', count( $zero ) === 1 ? 'it' : 'them', count( $zero ) === 1 ? 'it' : 'them' ), 'zero' => $zero ], 409 );
		}
		$fields = array_map( static fn( $f ) => array_diff_key( (array) $f, [ 'health' => 1 ] ), $row['fields'] );
		foreach ( $row['fields'] as $k => $f ) {
			if ( isset( $f['health']['of'] ) ) {
				$fields[ $k ]['found'] = (int) $f['health']['found'];
				$fields[ $k ]['of']    = (int) $f['health']['of'];
			}
		}
		$now = current_time( 'mysql', true );
		self::update( $row['host'], [ 'status' => 'confirmed', 'map_at' => $now, 'confirmed_at' => $now, 'confirmed_by' => get_current_user_id(), 'fields' => wp_json_encode( $fields ) ] );
		self::audit( 'vendor_maps.confirm', $row['host'] );
		self::queue( 'refresh', $row['host'] ); // the list's numbers follow what the reader now does
		return null;
	}

	public static function runner_state(): array {
		$s = Module::state( self::STATE );
		return [ 'running' => self::runner_alive(), 'waiting' => count( (array) ( $s['queue'] ?? [] ) ), 'note' => (string) ( $s['note'] ?? '' ), 'finished_at' => (string) ( $s['finished_at'] ?? '' ) ];
	}

	private static function audit( string $action, string $host, array $details = [] ): void {
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', $action, [ 'object_type' => 'vendor_site', 'object_id' => $host, 'details' => $details ] );
		}
	}
}
