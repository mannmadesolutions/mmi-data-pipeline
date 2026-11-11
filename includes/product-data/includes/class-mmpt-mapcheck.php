<?php
/**
 * A map part checked across its whole site (owner 2026-10-11: the picks made with "Pick … on the
 * page" should be evaluated "downstream to the rest of the products which use the HTML template for
 * gaps/outliers"). Run after a part is saved, and on demand, over every stored page of the site
 * (an even sample of MAX_PAGES when there are more), it reports:
 *
 * - the site: its products with a page on it, how many of those pages are stored (a map can only be
 *   checked and read on stored pages) and the products whose pages aren't, to read them first;
 * - each pick (the selector's comma-separated parts): pages it finds something on and what;
 * - gaps: pages where the part finds nothing;
 * - pictures: images repeated on many pages (site decoration: badges, install screenshots), images
 *   named after another of the site's products, collection pages shared by several products (each
 *   would get every picture), pictures under the 800 px standard, products whose own pictures are
 *   larger than any the page offers, and products with none (they get first pictures automatically
 *   once the map is confirmed);
 * - text: pages far shorter or longer than the rest, and the same text on several pages.
 *
 * Images marked "not product pictures" (skip(), per site, by file name stem so a re-upload such as
 * Install1_0_0_3.jpg is caught too) are left out wherever the site's pages are read: the reader
 * (VendorPage::extract()), the map's tests and previews (VendorMaps::eval_dom()) and this check.
 *
 * Lines marked "not product copy" (text_skips(), per site, owner 2026-10-14: "identify text which can
 * be ignored") work the same way for text: a marked line, a line that starts with a long marked line,
 * and everything under a marked heading are left out of descriptions read on that site. text_scan()
 * suggests them: lines on many of the site's pages are the vendor's template, not this product.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MapCheck {

	const DB_OPTION   = 'mmpt_map_checks_db_version';
	const DB_VERSION  = '1';
	const SKIP_OPTION = 'mmpt_map_skip'; // host → stems that aren't product pictures
	const TEXT_OPTION = 'mmpt_map_text_skip'; // host → lines that aren't product copy (text_key() form)
	const TEXT_SCAN   = 'mmpt_map_text_scan_'; // transient prefix: the last suggestion scan per site
	const TEXT_PREFIX_MIN = 20;  // a marked line this long also marks lines that start with it
	const TEXT_MAX    = 300;     // characters kept of a marked line
	const TEXT_PAGES  = 80;      // stored pages a scan reads
	const TEXT_BUDGET = 20.0;    // seconds a scan may take
	/** Headings every product page has: repeated, but marking one would leave out real copy, so never suggested. */
	const TEXT_GENERIC = '/^(key |main |more |other |product |technical |tech |new )?(features?|specifications?|specs|overview|description|details|requirements|system requirements|compatibility|what\'?s new|highlights|included|what\'?s included|in the box|formats?|presets|sounds|audio demos?|demos?|faq|reviews?|videos?)$/i';
	const MAX_PAGES   = 150;
	const BUDGET_S    = 25.0;
	const STANDARD    = 800;  // the product picture standard (ImageConvert)
	const LIST_MAX    = 40;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_map_checks';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				host varchar(190) NOT NULL,
				field varchar(20) NOT NULL,
				checked_at datetime NOT NULL,
				result longtext NOT NULL,
				PRIMARY KEY  (host, field)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	/* ── Skipped images ────────────────────────────────────────────────── */

	/** An image URL's file name stem: lowercase, no extension, no re-upload suffix (_0, _0_0_3) or size (-300x200). */
	public static function stem( string $url ): string {
		$f = strtolower( urldecode( (string) basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$f = preg_replace( '/\.[a-z0-9]{2,5}$/', '', $f );
		$f = preg_replace( '/-\d{2,4}x\d{2,4}$/', '', $f );
		return trim( (string) preg_replace( '/(_\d{1,3})+$/', '', $f ) );
	}

	/** @return string[] the site's stems that aren't product pictures */
	public static function skips( string $host ): array {
		$all = (array) get_option( self::SKIP_OPTION, [] );
		return array_values( (array) ( $all[ VendorMaps::host( $host ) ] ?? [] ) );
	}

	/** Is this picture on this site marked "not a product picture"? */
	public static function skipped( string $page_url, string $image_url ): bool {
		static $memo = [];
		$h = VendorMaps::host( $page_url );
		if ( ! isset( $memo[ $h ] ) ) {
			$memo[ $h ] = array_flip( self::skips( $h ) );
		}
		return $memo[ $h ] && isset( $memo[ $h ][ self::stem( $image_url ) ] );
	}

	/** A stamp of the site's skip list (VendorMaps::stamp(): pages are read again when it changes). */
	public static function skip_stamp( string $host ): string {
		$s = self::skips( $host );
		sort( $s );
		return $s ? substr( md5( implode( "\n", $s ) ), 0, 6 ) : '';
	}

	/* ── Skipped text ──────────────────────────────────────────────────── */

	/** A line as compared: lower case, plain quotes and dashes, single spaces, no trailing dots, arrows or colon. */
	public static function text_key( string $t ): string {
		$t = mb_strtolower( html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$t = str_replace( [ '’', '‘', '“', '”', '–', '—', "\u{00a0}" ], [ "'", "'", '"', '"', '-', '-', ' ' ], $t );
		$t = trim( (string) preg_replace( '/\s+/u', ' ', $t ) );
		return trim( (string) preg_replace( '/[\s.…:»›→>!]+$/u', '', $t ) );
	}

	/** @return string[] the site's lines that aren't product copy */
	public static function text_skips( string $host ): array {
		$all = (array) get_option( self::TEXT_OPTION, [] );
		return array_values( (array) ( $all[ VendorMaps::host( $host ) ] ?? [] ) );
	}

	/** The site's marked lines as a lookup (line => true), for VendorPage. @return array<string,bool> */
	public static function text_skip_set( string $url_or_host ): array {
		$h = VendorMaps::host( $url_or_host );
		if ( $h === '' ) {
			return [];
		}
		if ( ! isset( self::$text_memo[ $h ] ) ) {
			self::$text_memo[ $h ] = array_fill_keys( self::text_skips( $h ), true );
		}
		return self::$text_memo[ $h ];
	}

	/** @var array<string,array<string,bool>> */
	private static array $text_memo = [];

	/** A stamp of the site's marked lines ('' for none): Coverage reads a site's pages again when it changes. */
	public static function text_stamp( string $url_or_host ): string {
		$s = self::text_skips( $url_or_host );
		sort( $s );
		return $s ? substr( md5( implode( "\n", $s ) ), 0, 6 ) : '';
	}

	/** @return string[] the new list */
	public static function set_text_skips( string $host, array $add, array $remove ): array {
		$h   = VendorMaps::host( $host );
		$all = (array) get_option( self::TEXT_OPTION, [] );
		$now = array_flip( (array) ( $all[ $h ] ?? [] ) );
		foreach ( $add as $t ) {
			$k = self::text_key( mb_substr( sanitize_text_field( (string) $t ), 0, self::TEXT_MAX ) );
			if ( mb_strlen( $k ) >= 3 ) {
				$now[ $k ] = 1;
			}
		}
		foreach ( $remove as $t ) {
			unset( $now[ self::text_key( (string) $t ) ] );
		}
		$all[ $h ] = array_keys( $now );
		if ( ! $all[ $h ] ) {
			unset( $all[ $h ] );
		}
		update_option( self::TEXT_OPTION, $all, false );
		unset( self::$text_memo[ $h ] );
		return array_keys( $now );
	}

	/**
	 * Suggestions for the site's list: the lines the description reader keeps that recur on many of the
	 * site's stored pages (the vendor's template: install steps, store promos, "Learn more"), and what the
	 * reader already leaves out by itself, with how many pages each is on. Reads an even sample of
	 * TEXT_PAGES stored pages within TEXT_BUDGET; kept until the list or the pages change.
	 *
	 * @return array{host:string,checked:int,pages:int,partial:bool,repeated:array,auto:array,at:string}
	 */
	public static function text_scan( string $host, bool $fresh = false ): array {
		$h   = VendorMaps::host( $host );
		$key = self::TEXT_SCAN . md5( $h );
		$was = get_transient( $key );
		if ( ! $fresh && is_array( $was ) && ( $was['stamp'] ?? '' ) === self::text_stamp( $h ) ) {
			return $was;
		}
		global $wpdb;
		$files = [];
		foreach ( self::linked( $h ) as $id => $st ) {
			if ( $st === 'ok' ) {
				$files[ $id ] = true;
			}
		}
		$rows = [];
		if ( $files ) {
			$in   = implode( ',', array_map( 'intval', array_keys( $files ) ) );
			$rows = $wpdb->get_results( "SELECT DISTINCT u.meta_value url, pg.html_file file FROM {$wpdb->postmeta} u JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( u.meta_value ) AND pg.status = 'ok' WHERE u.meta_key = 'vendor_product_url' AND u.post_id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- ids are ints
		}
		$total = count( $rows );
		if ( $total > self::TEXT_PAGES ) {
			$step = $total / self::TEXT_PAGES;
			$rows = array_map( static fn( $i ) => $rows[ (int) floor( $i * $step ) ], range( 0, self::TEXT_PAGES - 1 ) );
		}
		$start   = microtime( true );
		$kept    = []; // text_key → [text, pages, heading]
		$auto    = [];
		$checked = 0;
		foreach ( $rows as $r ) {
			if ( microtime( true ) - $start > self::TEXT_BUDGET ) {
				break;
			}
			$html = PageStore::read( (string) $r['file'] );
			if ( $html === '' ) {
				continue;
			}
			$x = VendorPage::extract( $html, (string) $r['url'] );
			$checked++;
			$on = [];
			foreach ( (array) $x['blocks'] as $b ) {
				if ( $b['type'] === 'list' ) {
					continue; // a list item shared by a product line (a microphone in a gear list) is copy, and too small to suggest
				}
				foreach ( [ (string) $b['text'] ] as $t ) {
					$k = self::text_key( (string) $t );
					if ( $k === '' || mb_strlen( $k ) > self::TEXT_MAX || isset( $on[ $k ] ) || preg_match( self::TEXT_GENERIC, $k ) ) {
						continue;
					}
					$on[ $k ]   = true;
					$kept[ $k ] = [ 'text' => $kept[ $k ]['text'] ?? mb_substr( (string) $t, 0, self::TEXT_MAX ), 'pages' => ( $kept[ $k ]['pages'] ?? 0 ) + 1, 'heading' => $b['type'] === 'heading' ];
				}
			}
			foreach ( array_unique( array_map( [ __CLASS__, 'text_key' ], (array) $x['removed'] ) ) as $k ) {
				if ( $k !== '' ) {
					$auto[ $k ] = [ 'text' => $auto[ $k ]['text'] ?? $k, 'pages' => ( $auto[ $k ]['pages'] ?? 0 ) + 1 ];
				}
			}
		}
		$min      = max( 3, (int) ceil( 0.25 * $checked ) );
		$repeated = array_values( array_filter( $kept, static fn( $k ) => $k['pages'] >= $min ) );
		usort( $repeated, static fn( $a, $b ) => $b['pages'] <=> $a['pages'] );
		$auto = array_values( array_filter( $auto, static fn( $a ) => $a['pages'] >= 2 ) );
		usort( $auto, static fn( $a, $b ) => $b['pages'] <=> $a['pages'] );
		$out = [
			'host'     => $h,
			'checked'  => $checked,
			'pages'    => $total,
			'partial'  => $checked < count( $rows ),
			'min'      => $min,
			'repeated' => array_slice( $repeated, 0, self::LIST_MAX ),
			'auto'     => array_slice( $auto, 0, self::LIST_MAX ),
			'at'       => current_time( 'mysql', true ),
			'stamp'    => self::text_stamp( $h ),
		];
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	/* ── The check ─────────────────────────────────────────────────────── */

	/** Split a selector list on its top-level commas. @return string[] */
	private static function picks( string $sel ): array {
		$out   = [];
		$depth = 0;
		$cur   = '';
		$quote = '';
		foreach ( preg_split( '//u', $sel, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( $quote !== '' ) {
				$quote = $ch === $quote ? '' : $quote;
			} elseif ( $ch === '"' || $ch === "'" ) {
				$quote = $ch;
			} elseif ( $ch === '(' || $ch === '[' ) {
				$depth++;
			} elseif ( $ch === ')' || $ch === ']' ) {
				$depth--;
			} elseif ( $ch === ',' && $depth === 0 ) {
				$out[] = trim( $cur );
				$cur   = '';
				continue;
			}
			$cur .= $ch;
		}
		$out[] = trim( $cur );
		return array_values( array_filter( $out, 'strlen' ) );
	}

	/** A product page's slug as letters and digits ("rig-libraries" → riglibraries). */
	private static function slug_key( string $url ): string {
		$p = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) basename( $p ) ) );
	}

	/**
	 * Check a part of a site's map across its stored pages, store and return the result.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function run( string $host, string $field, array $trial = [] ) {
		self::maybe_install();
		$row = VendorMaps::get( $host );
		if ( ! $row ) {
			return new \WP_Error( 'mmpt_check', 'No map for this site yet.' );
		}
		// $trial: a pick not saved yet (the extension mapper's Test, §6 item 7a): checked the same way, never stored.
		$f    = isset( $trial['sel'] ) ? [ 'sel' => (string) $trial['sel'], 'mode' => (string) ( $trial['mode'] ?? '' ) ] : (array) ( $row['fields'][ $field ] ?? [] );
		$sel  = (string) ( $f['sel'] ?? '' );
		$mode = (string) ( $f['mode'] ?? '' );
		if ( $sel === '' ) {
			return new \WP_Error( 'mmpt_check', 'This part has no picks to check: the generic reader reads it.' );
		}
		$start = microtime( true );
		$h     = $row['host'];

		// The site: every product with a page on it, stored or not (the one definition the gaps count by too).
		global $wpdb;
		$linked = VendorMaps::site_products( $h );
		if ( isset( VendorMaps::RELATIONS[ $field ] ) ) {
			$linked = VendorMaps::bundle_pages( $linked ); // only a bundle's page has a contents list
		}
		$by_url   = [];
		$unstored = []; // never read: "Read their pages" reads them
		$failed   = []; // read, but the site moved, refused or lost the page: a URL decision, not another read
		$slugs    = [];
		foreach ( $linked as $x ) {
			$slugs[ self::slug_key( (string) $x['url'] ) ] = (string) $x['url'];
			if ( $x['st'] !== 'ok' || (string) $x['file'] === '' ) {
				if ( (string) $x['st'] === '' ) {
					$unstored[] = (int) $x['id'];
				} else {
					$failed[] = [ 'id' => (int) $x['id'], 'title' => html_entity_decode( (string) $x['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'url' => (string) $x['url'], 'status' => (string) $x['st'], 'note' => (string) $x['note'] ];
				}
				continue;
			}
			$by_url[ (string) $x['url'] ]['file']   = (string) $x['file'];
			$by_url[ (string) $x['url'] ]['ids'][]  = (int) $x['id'];
			$by_url[ (string) $x['url'] ]['titles'][] = html_entity_decode( (string) $x['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$urls  = array_keys( $by_url );
		$total = count( $urls );
		if ( $total > self::MAX_PAGES ) { // an even spread across the catalog
			$step = $total / self::MAX_PAGES;
			$urls = array_map( static fn( $i ) => $urls[ (int) floor( $i * $step ) ], range( 0, self::MAX_PAGES - 1 ) );
		}

		$picks   = self::picks( $sel );
		$xps     = [];
		foreach ( $picks as $p ) {
			$xp    = VendorMaps::css_to_xpath( $p, $field === 'pictures' );
			$xps[] = is_string( $xp ) ? $xp : '';
		}
		$skips   = array_flip( self::skips( $h ) );
		$per     = [];  // page url → reading
		$pick_at = array_fill( 0, count( $picks ), [ 'pages' => 0, 'items' => 0, 'sample' => [] ] );
		$stems   = [];  // pictures: stem → [pages, picks, url, width]
		$texts   = [];  // text: first 160 chars → pages
		$checked = 0;
		foreach ( $urls as $url ) {
			if ( microtime( true ) - $start > self::BUDGET_S ) {
				break;
			}
			$html = PageStore::read( $by_url[ $url ]['file'] );
			$doc  = $html !== '' ? VendorPage::parse( $html ) : null;
			if ( ! $doc ) {
				continue;
			}
			$checked++;
			if ( $field === 'pictures' ) {
				$imgs = [];
				foreach ( $xps as $i => $xp ) {
					if ( $xp === '' ) {
						continue;
					}
					$got = 0;
					foreach ( VendorPage::xpath_images( $doc, [ $xp ] ) as $img ) {
						$u = VendorPage::absolute_url( html_entity_decode( trim( $img['url'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $url );
						if ( $u === '' || ! preg_match( '#^https?://#i', $u ) || preg_match( VendorPage::IMAGE_SKIP, $u ) ) {
							continue;
						}
						$st = self::stem( $u );
						if ( isset( $skips[ $st ] ) ) {
							continue;
						}
						$key = strtolower( preg_replace( [ '/[?#].*$/', '#^https?:#i' ], '', $u ) );
						if ( isset( $imgs[ $key ] ) ) {
							continue;
						}
						$imgs[ $key ] = [ 'url' => $u, 'stem' => $st, 'width' => (int) $img['width'], 'pick' => $i ];
						$got++;
						$stems[ $st ]['pages'][ $url ] = 1;
						$stems[ $st ]['picks'][ $i ]   = 1;
						$stems[ $st ]['url']           = $stems[ $st ]['url'] ?? $u;
						$stems[ $st ]['width']         = max( (int) ( $stems[ $st ]['width'] ?? 0 ), (int) $img['width'] );
						if ( count( $pick_at[ $i ]['sample'] ) < 10 && ! in_array( $st, $pick_at[ $i ]['sample'], true ) ) {
							$pick_at[ $i ]['sample'][] = $st;
						}
					}
					if ( $got ) {
						$pick_at[ $i ]['pages']++;
						$pick_at[ $i ]['items'] += $got;
					}
				}
				$per[ $url ] = [ 'n' => count( $imgs ), 'imgs' => array_values( $imgs ) ];
			} else {
				$all = 0;
				foreach ( $xps as $i => $xp ) {
					if ( $xp === '' ) {
						continue;
					}
					$r = VendorMaps::eval_dom( $field, $picks[ $i ], $mode, $doc, $url );
					if ( $r['found'] ) {
						$pick_at[ $i ]['pages']++;
						$pick_at[ $i ]['items'] += (int) $r['n'];
						if ( count( $pick_at[ $i ]['sample'] ) < 3 && $r['short'] !== '' ) {
							$pick_at[ $i ]['sample'][] = $r['short'];
						}
					}
				}
				$r           = VendorMaps::eval_dom( $field, $sel, $mode, $doc, $url );
				$per[ $url ] = [ 'n' => (int) $r['n'], 'found' => (bool) $r['found'], 'short' => (string) $r['short'] ];
				$key         = mb_substr( preg_replace( '/\s+/', ' ', (string) $r['detail'] ), 0, 160 );
				if ( $r['found'] && $key !== '' ) {
					$texts[ $key ][ $url ] = 1;
				}
				$all = $r['n'];
			}
		}

		$page = static fn( string $url ) => [ 'url' => $url, 'ids' => $by_url[ $url ]['ids'] ?? [], 'title' => $by_url[ $url ]['titles'][0] ?? '' ];
		$out  = [
			'host'       => $h,
			'field'      => $field,
			'sel'        => $sel,
			'skip_stamp' => self::skip_stamp( $h ),
			'pages_sig'  => VendorMaps::pages_sig( $h ), // a page read again or relabelled (bot checks, 2026-10-18) makes this check out of date
			'checked_at' => current_time( 'mysql', true ),
			'site'       => [ 'products' => count( $linked ), 'pages' => $total, 'checked' => $checked, 'unstored' => count( $unstored ), 'unstored_ids' => array_slice( $unstored, 0, 2000 ), 'failed' => array_slice( $failed, 0, self::LIST_MAX ), 'failed_n' => count( $failed ), 'partial' => $checked < count( $urls ) ],
			'picks'      => array_map( static fn( $p, $a ) => [ 'sel' => $p ] + $a, $picks, $pick_at ),
			'gaps'       => [],
			'issues'     => [],
		];
		$ns     = array_column( $per, 'n' );
		sort( $ns );
		$median = $ns ? $ns[ (int) floor( count( $ns ) / 2 ) ] : 0;
		$out['median'] = $median;
		foreach ( $per as $url => $p ) {
			if ( ! $p['n'] || ( $field !== 'pictures' && ! $p['found'] ) ) {
				$out['gaps'][] = $page( $url );
			}
		}

		if ( $field === 'pictures' ) {
			$pages_n = max( 1, $checked );
			// Site decoration: the same picture on many pages.
			$deco = [];
			foreach ( $stems as $st => $s ) {
				$n = count( $s['pages'] );
				if ( $n >= 3 && $n >= 0.2 * $pages_n ) {
					$deco[] = [ 'stem' => $st, 'url' => $s['url'], 'pages' => $n, 'picks' => array_keys( $s['picks'] ), 'width' => $s['width'] ];
				}
			}
			usort( $deco, static fn( $a, $b ) => $b['pages'] <=> $a['pages'] );
			$out['issues']['repeated'] = $deco;
			// Pictures named after another of the site's products (a cross-promotion, not this product).
			$other = [];
			foreach ( $per as $url => $p ) {
				$own = self::slug_key( $url );
				foreach ( $p['imgs'] as $img ) {
					$k = preg_replace( '/[^a-z0-9]/', '', $img['stem'] );
					foreach ( $slugs as $slug => $surl ) {
						if ( strlen( $slug ) >= 6 && $slug !== $own && str_contains( $k, $slug ) && ! str_contains( $own, $slug ) && ! str_contains( $slug, $own ) ) {
							$other[] = $page( $url ) + [ 'image' => $img['url'], 'stem' => $img['stem'], 'names' => $surl ];
							break;
						}
					}
				}
			}
			$out['issues']['other_product'] = array_slice( $other, 0, self::LIST_MAX );
			// Collection pages: several products share the page, so each would get every picture.
			$shared = [];
			foreach ( $per as $url => $p ) {
				if ( count( array_unique( $by_url[ $url ]['ids'] ?? [] ) ) >= 2 && $p['n'] >= 3 ) {
					$shared[] = $page( $url ) + [ 'n' => $p['n'], 'titles' => array_slice( array_unique( $by_url[ $url ]['titles'] ), 0, 6 ) ];
				}
			}
			$out['issues']['shared'] = array_slice( $shared, 0, self::LIST_MAX );
			// Many more pictures than the site's usual page.
			$many = [];
			foreach ( $per as $url => $p ) {
				if ( $p['n'] >= max( 8, 2 * $median ) ) {
					$many[] = $page( $url ) + [ 'n' => $p['n'] ];
				}
			}
			$out['issues']['many'] = array_slice( $many, 0, self::LIST_MAX );
			// Under the standard, and against the product's own pictures.
			$small    = [];
			$bigger   = [];
			$first    = [];
			$unknown  = 0;
			foreach ( $per as $url => $p ) {
				$widths = array_filter( array_column( $p['imgs'], 'width' ) );
				$best   = $widths ? max( $widths ) : 0;
				if ( ! $widths && $p['imgs'] ) {
					$unknown++;
				}
				if ( $best && $best < self::STANDARD ) {
					$small[] = $page( $url ) + [ 'width' => $best ];
				}
				foreach ( array_unique( $by_url[ $url ]['ids'] ?? [] ) as $id ) {
					$thumb = (int) get_post_thumbnail_id( $id );
					if ( ! $thumb ) {
						if ( $p['n'] ) {
							$first[] = [ 'id' => $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'url' => $url, 'n' => $p['n'], 'stems' => array_slice( array_column( $p['imgs'], 'stem' ), 0, 6 ) ];
						}
						continue;
					}
					$meta = wp_get_attachment_metadata( $thumb );
					$mine = (int) ( $meta['width'] ?? 0 );
					if ( $best && $mine && $mine > $best ) {
						$bigger[] = [ 'id' => $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'url' => $url, 'own' => $mine, 'page' => $best ];
					}
				}
			}
			$out['issues']['small']        = array_slice( $small, 0, self::LIST_MAX );
			$out['issues']['small_n']      = count( $small );
			$out['issues']['size_unknown'] = $unknown;
			$out['issues']['bigger']       = array_slice( $bigger, 0, self::LIST_MAX );
			$out['issues']['bigger_n']     = count( $bigger );
			$out['issues']['first']        = array_slice( $first, 0, self::LIST_MAX );
			$out['issues']['first_n']      = count( $first );
		} else {
			// Text far shorter or longer than the site's usual page, and the same text on several pages.
			$odd = [];
			foreach ( $per as $url => $p ) {
				if ( $p['found'] && $median && ( $p['n'] < $median / 3 || $p['n'] > $median * 3 ) ) {
					$odd[] = $page( $url ) + [ 'n' => $p['n'], 'short' => $p['short'] ];
				}
			}
			$out['issues']['length'] = array_slice( $odd, 0, self::LIST_MAX );
			$same = [];
			foreach ( $texts as $t => $pages ) {
				if ( count( $pages ) >= 3 ) {
					$same[] = [ 'text' => $t, 'pages' => count( $pages ), 'examples' => array_slice( array_map( $page, array_keys( $pages ) ), 0, 3 ) ];
				}
			}
			$out['issues']['same_text'] = $same;
		}
		$out['gaps_n'] = count( $out['gaps'] );
		$out['gaps']   = array_slice( $out['gaps'], 0, self::LIST_MAX );
		$out['ms']     = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( $trial ) {
			$out['trial'] = true;
			return $out;
		}
		$wpdb->replace( self::table(), [ 'host' => $h, 'field' => $field, 'checked_at' => $out['checked_at'], 'result' => wp_json_encode( $out ) ] );
		return $out;
	}

	/**
	 * A check's findings as short lines for the extension mapper (under its Test): what each finding means, how many
	 * pages, and up to three example pages. Nothing found: one line saying so.
	 *
	 * @return array{lines: array<int,array{key:string,text:string,n:int,type:string,examples:array<int,array{url:string,title:string}>}>, checked:int, pages:int, products:int}
	 */
	public static function summary( array $c ): array {
		$ex    = static fn( array $list ): array => array_values( array_map( static fn( $x ) => [ 'url' => (string) ( $x['url'] ?? '' ), 'title' => (string) ( $x['title'] ?? '' ) ], array_slice( array_filter( $list, static fn( $x ) => is_array( $x ) && ! empty( $x['url'] ) ), 0, 3 ) ) );
		$is    = (array) ( $c['issues'] ?? [] );
		$lines = [];
		$add   = static function ( string $key, int $n, string $text, array $examples = [], string $type = 'warning' ) use ( &$lines ): void {
			if ( $n > 0 ) {
				$lines[] = [ 'key' => $key, 'text' => $text, 'n' => $n, 'type' => $type, 'examples' => $examples ];
			}
		};
		$site    = (array) ( $c['site'] ?? [] );
		$checked = (int) ( $site['checked'] ?? 0 );
		$gaps    = (int) ( $c['gaps_n'] ?? 0 );
		$add( 'gaps', $gaps, sprintf( 'Finds nothing on %d of the %d stored pages checked.', $gaps, $checked ), $ex( (array) ( $c['gaps'] ?? [] ) ) );
		$rep = (array) ( $is['repeated'] ?? [] );
		$add( 'repeated', count( $rep ), sprintf( '%d picture(s) are on many pages (site decoration, not this product): mark them "not a product picture".', count( $rep ) ), array_values( array_map( static fn( $x ) => [ 'url' => (string) $x['url'], 'title' => sprintf( 'on %d pages', (int) $x['pages'] ) ], array_slice( $rep, 0, 3 ) ) ) );
		$add( 'other_product', count( (array) ( $is['other_product'] ?? [] ) ), sprintf( '%d page(s) have a picture named after another of the site’s products.', count( (array) ( $is['other_product'] ?? [] ) ) ), $ex( (array) ( $is['other_product'] ?? [] ) ) );
		$add( 'shared', count( (array) ( $is['shared'] ?? [] ) ), sprintf( '%d collection page(s): several products share the page, so each would get every picture.', count( (array) ( $is['shared'] ?? [] ) ) ), $ex( (array) ( $is['shared'] ?? [] ) ) );
		$add( 'many', count( (array) ( $is['many'] ?? [] ) ), sprintf( '%d page(s) give far more pictures than the site’s usual page.', count( (array) ( $is['many'] ?? [] ) ) ), $ex( (array) ( $is['many'] ?? [] ) ) );
		$add( 'small', (int) ( $is['small_n'] ?? 0 ), sprintf( '%d page(s) have no picture as large as the %d px standard.', (int) ( $is['small_n'] ?? 0 ), self::STANDARD ), $ex( (array) ( $is['small'] ?? [] ) ), 'info' );
		$add( 'length', count( (array) ( $is['length'] ?? [] ) ), sprintf( '%d page(s) read far shorter or longer than the rest (the site’s usual is %d).', count( (array) ( $is['length'] ?? [] ) ), (int) ( $c['median'] ?? 0 ) ), $ex( (array) ( $is['length'] ?? [] ) ) );
		$same = (array) ( $is['same_text'] ?? [] );
		$add( 'same_text', count( $same ), sprintf( '%d line(s) are the same on 3 or more pages (the site’s template): leave them out on this site.', count( $same ) ), array_values( array_map( static fn( $x ) => [ 'url' => (string) ( $x['examples'][0]['url'] ?? '' ), 'title' => mb_substr( (string) $x['text'], 0, 80 ) ], array_slice( $same, 0, 3 ) ) ) );
		if ( ! $lines ) {
			$lines[] = [ 'key' => 'clean', 'text' => sprintf( 'Across the site: nothing odd on the %d stored pages checked.', $checked ), 'n' => 0, 'type' => 'success', 'examples' => [] ];
		}
		return [ 'lines' => $lines, 'checked' => $checked, 'pages' => (int) ( $site['pages'] ?? 0 ), 'products' => (int) ( $site['products'] ?? 0 ) ];
	}

	/** The stored check, or null when there is none or the part's picks or the skip list changed since. */
	public static function stored( string $host, string $field ): ?array {
		self::maybe_install();
		global $wpdb;
		$h   = VendorMaps::host( $host );
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT result FROM ' . self::table() . ' WHERE host = %s AND field = %s', $h, $field ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$r   = $raw ? json_decode( (string) $raw, true ) : null;
		$row = VendorMaps::get( $h );
		if ( ! is_array( $r ) || ! $row || (string) ( $row['fields'][ $field ]['sel'] ?? '' ) !== (string) ( $r['sel'] ?? '' ) || self::skip_stamp( $h ) !== (string) ( $r['skip_stamp'] ?? '' ) || VendorMaps::pages_sig( $h ) !== (string) ( $r['pages_sig'] ?? '' ) ) {
			return null;
		}
		return $r;
	}

	/** The site's products (VendorMaps::site_products()): id → page status. @return array<int,string> */
	private static function linked( string $host ): array {
		return array_column( VendorMaps::site_products( $host ), 'st', 'id' );
	}

	/**
	 * How the reading of a site's pages is going ("Read their pages"): its products in the scrape queue
	 * since $since (UTC; default: since the oldest one still waiting), how many are waiting, being read
	 * and done, how the done ones went, and how many of the site's pages are stored now.
	 * @return array<string,mixed>
	 */
	public static function reading( string $host, string $since = '' ): array {
		global $wpdb;
		$linked = self::linked( $host );
		$out    = [ 'products' => count( $linked ), 'stored' => count( array_filter( $linked, static fn( $s ) => $s === 'ok' ) ), 'waiting' => 0, 'working' => 0, 'done' => 0, 'read' => 0, 'failed' => 0, 'since' => '', 'running' => Scraper::runner_alive(), 'busy' => PageStore::server_busy() ];
		if ( ! $linked ) {
			return $out;
		}
		Scraper::maybe_install();
		$t  = Scraper::table();
		$in = implode( ',', array_keys( $linked ) );
		if ( ! preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $since ) ) {
			$since = (string) $wpdb->get_var( "SELECT MIN( queued_at ) FROM {$t} WHERE done_at IS NULL AND product_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( $since === '' ) {
			return $out; // nothing waiting and no batch to follow
		}
		$c = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM( done_at IS NULL AND started_at IS NULL ) waiting, SUM( done_at IS NULL AND started_at IS NOT NULL ) working,
				        SUM( done_at >= %s ) done, SUM( done_at >= %s AND result IN ('ok','no_copy','manual') ) read_ok
				 FROM {$t} WHERE product_id IN ({$in}) AND queued_at >= %s - INTERVAL 1 MINUTE", // phpcs:ignore WordPress.DB.PreparedSQL
				$since,
				$since,
				$since
			),
			ARRAY_A
		);
		$out['since']   = $since;
		$out['waiting'] = (int) ( $c['waiting'] ?? 0 );
		$out['working'] = (int) ( $c['working'] ?? 0 );
		$out['done']    = (int) ( $c['done'] ?? 0 );
		$out['read']    = (int) ( $c['read_ok'] ?? 0 );
		$out['failed']  = $out['done'] - $out['read'];
		$out['why']     = [];
		if ( $out['failed'] ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT result, COUNT(*) n FROM {$t} WHERE product_id IN ({$in}) AND queued_at >= %s - INTERVAL 1 MINUTE AND done_at >= %s AND result NOT IN ('ok','no_copy','manual') GROUP BY result", $since, $since ), ARRAY_A ) as $w ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$out['why'][ (string) $w['result'] ] = (int) $w['n'];
			}
		}
		return $out;
	}

	/* ── REST ──────────────────────────────────────────────────────────── */

	public static function routes(): void {
		$admin = static fn() => current_user_can( Module::CAP );
		$host  = [ 'host' => [ 'validate_callback' => static fn( $v ) => (bool) preg_match( '/^[a-z0-9.-]+$/', (string) $v ) ] ];
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/reading',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'args'                => $host,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( self::reading( (string) $r['host'], sanitize_text_field( (string) $r->get_param( 'since' ) ) ) ),
			]
		);
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/text',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => $admin,
					'args'                => $host,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$h   = (string) $r['host'];
						$was = get_transient( self::TEXT_SCAN . md5( VendorMaps::host( $h ) ) );
						return rest_ensure_response( [ 'skips' => self::text_skips( $h ), 'scan' => is_array( $was ) ? $was : null ] );
					},
				],
				[
					'methods'             => 'POST', // read the site's stored pages for suggestions
					'permission_callback' => $admin,
					'args'                => $host,
					'callback'            => static function ( \WP_REST_Request $r ) {
						if ( PageStore::server_busy() ) {
							return new \WP_REST_Response( [ 'message' => 'The server is busy right now; try again in a minute.' ], 503 );
						}
						$h = (string) $r['host'];
						return rest_ensure_response( [ 'skips' => self::text_skips( $h ), 'scan' => self::text_scan( $h, true ) ] );
					},
				],
			]
		);
		register_rest_route(
			Module::NS,
			'/maps/(?P<host>[a-z0-9.-]+)/text-skip',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $host,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$h      = (string) $r['host'];
					$add    = array_slice( array_map( 'strval', (array) $r->get_param( 'add' ) ), 0, 100 );
					$remove = array_slice( array_map( 'strval', (array) $r->get_param( 'remove' ) ), 0, 100 );
					$list   = self::set_text_skips( $h, $add, $remove );
					if ( class_exists( 'MMI_Audit_Log' ) ) {
						\MMI_Audit_Log::record( 'mmi-admin', 'vendor_maps.text_skip', [ 'object_type' => 'site', 'details' => [ 'host' => VendorMaps::host( $h ), 'add' => count( $add ), 'remove' => count( $remove ), 'total' => count( $list ) ] ] );
					}
					// The suggestion scan stays as it was, less what is now on the list (a fresh scan is the person's click).
					$scan = get_transient( self::TEXT_SCAN . md5( VendorMaps::host( $h ) ) );
					if ( is_array( $scan ) ) {
						$on               = array_fill_keys( $list, true );
						$scan['repeated'] = array_values( array_filter( (array) $scan['repeated'], static fn( $x ) => ! isset( $on[ self::text_key( (string) $x['text'] ) ] ) ) );
						$scan['stamp']    = self::text_stamp( $h );
						set_transient( self::TEXT_SCAN . md5( VendorMaps::host( $h ) ), $scan, DAY_IN_SECONDS );
					}
					return rest_ensure_response( [ 'skips' => $list, 'scan' => is_array( $scan ) ? $scan : null ] );
				},
			]
		);
	}
}
