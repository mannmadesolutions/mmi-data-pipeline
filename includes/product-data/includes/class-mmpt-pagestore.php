<?php
/**
 * Product Data Acquisition Phase 1 (PRODUCT_CONTENT_HANDOFF.md §9): the
 * page evidence store and the per-host fetch policy.
 *
 * Every vendor page fetched is kept once per URL — status, page title and
 * the raw HTML (gzipped, in the private uploads dir) — so a page that
 * several products share (2,419 products share a page with another) is
 * fetched once, extractors can be re-run on stored HTML without refetching,
 * and a URL can be confirmed by what the page says it is.
 *
 * render() is the browser tier: the same page loaded in headless Chromium
 * with its tabs and disclosures opened (scripts/product-content/render-page.js),
 * for pages that are built by script, sit behind a bot wall, or hide their
 * edition comparisons in accordions. WP-CLI only (one Chromium is ~300 MB);
 * it waits when the server is busy.
 *
 * Host policy (owner, 2026-10-06, "take the gloves off"): robots.txt no
 * longer gates manufacturer product pages; per-host pacing stays because
 * this server's IP is allowlisted by suppliers for ordering. Per host:
 * `robots` honor|ignore (default ignore), `gap_ms` (default 4000).
 *
 * Redirects (2026-10-11): get() follows them itself, up to MAX_REDIRECTS, and
 * keeps where the page now lives (`final_url`). Old vendor addresses often take
 * 4 hops (http → https → www → the new path); following only 3 failed 48 of 52
 * pages in one night with "Too many redirects". A page that now lives at
 * another path on the same site is stored under its new address (`ok`) and the
 * old one is marked `moved`, so its product's URL can follow it (Scraper). A
 * redirect to another site is never fetched (`moved_offsite`; a lapsed domain
 * can send a vendor address to a casino), and a failure keeps its reason (`note`).
 *
 * Keep the better copy (owner 2026-11-01, VENDOR_BROWSER_EXTENSION_PLAN.md §6 and §12): a page sent from the owner's
 * browser replaces the stored copy only when it reads at least as much (reads_less(): the reader's text and pictures),
 * and a copy stored from the browser is replaced by a server read (get(), render()) only on the same terms. A copy a
 * script drew in Chrome is never swapped for a bare one by the nightly re-read.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageStore {

	const DB_OPTION   = 'mmpt_pages_db_version';
	const DB_VERSION  = '2';
	const POLICY_KEY  = 'mmpt_host_policy';
	const DEFAULT_GAP = 4000;
	const SUBDIR      = 'product-pages';
	const CSS_SUBDIR  = 'product-pages-css'; // the stored pages' own stylesheets, kept by address (styles())
	const CSS_MAX     = 8;                   // a page's own sheets kept
	const CSS_TIMEOUT = 5;                   // seconds per sheet fetched while the owner waits
	const CSS_BYTES   = 3000000;
	const MAX_REDIRECTS = 10;

	const RENDER_SCRIPT  = 'scripts/product-content/render-page.js';
	// A firewall's bot check instead of the page (waves.com's Incapsula: 212 bytes, 243 of them stored as "ok" on 2026-10-17).
	const WALL_MAX  = 20000; // bytes: a real product page is larger
	const KEEP_ON   = [ 202, 401, 403, 405, 429 ]; // a refused re-read (and any 5xx) never replaces a good stored copy
	const WALL_RE   = '/_Incapsula_Resource|Incapsula incident ID|incap_ses_|px-captcha|captcha-delivery\.com|DataDome/i';
	const WALL_NOTE = 'Bot check from the site\'s firewall instead of the page: send it from your browser with the Product Data extension';
	const READS_SLACK    = 0.98; // a new copy "reads as much" with 98% of the old one's text: pages carry dates and counters
	const RENDER_TIMEOUT = 100; // seconds; the script stops itself at 75
	const MAX_LOAD       = 2.5;  // AGENTS.md bail-out thresholds
	const MAX_RAM_PCT    = 90;


	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_pages';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(32) NOT NULL,
  url varchar(500) NOT NULL DEFAULT '',
  host varchar(190) NOT NULL DEFAULT '',
  method varchar(20) NOT NULL DEFAULT 'http',
  http_code smallint(5) unsigned NOT NULL DEFAULT 0,
  status varchar(40) NOT NULL DEFAULT '',
  title varchar(500) NOT NULL DEFAULT '',
  html_file varchar(255) NOT NULL DEFAULT '',
  content_hash char(32) NOT NULL DEFAULT '',
  fetched_at datetime DEFAULT NULL,
  final_url varchar(500) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY host (host),
  KEY status (status)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/* ── Host policy ────────────────────────────────────────────────────── */

	/** @return array{robots:string,gap_ms:int} */
	public static function policy( string $host ): array {
		$all  = (array) \MMI_Settings::get( self::POLICY_KEY, [] );
		$host = preg_replace( '/^www\./', '', strtolower( $host ) );
		$p    = (array) ( $all['hosts'][ $host ] ?? [] ) + (array) ( $all['default'] ?? [] );
		return [
			'robots' => ( $p['robots'] ?? 'ignore' ) === 'honor' ? 'honor' : 'ignore',
			'gap_ms' => max( 1000, (int) ( $p['gap_ms'] ?? self::DEFAULT_GAP ) ),
		];
	}

	/* ── Fetch through the store ─────────────────────────────────────────── */

	/**
	 * A page, from the store when fetched within $max_age_days, else from the
	 * network (throttled per host, robots per policy) and stored.
	 *
	 * @return array{status:string,code:int,html:string,title:string,from_store:bool}
	 */
	public static function get( string $url, int $max_age_days = VendorFetcher::REFETCH_DAYS ): array {
		self::maybe_install();
		global $wpdb;
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' ) {
			return [ 'status' => 'bad_url', 'code' => 0, 'html' => '', 'title' => '', 'from_store' => false ];
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE url_hash = %s', md5( $url ) ), ARRAY_A );
		if ( $row && $row['fetched_at'] && strtotime( $row['fetched_at'] . ' UTC' ) > time() - VendorFetcher::keep_days( (string) $row['status'], $max_age_days ) * DAY_IN_SECONDS
			&& in_array( $row['status'], VendorFetcher::FINAL_STATUSES, true ) ) {
			return [ 'status' => $row['status'], 'code' => (int) $row['http_code'], 'html' => self::read( (string) $row['html_file'] ), 'title' => (string) $row['title'], 'from_store' => true, 'final_url' => (string) ( $row['final_url'] ?? '' ), 'note' => (string) ( $row['note'] ?? '' ) ];
		}
		$policy = self::policy( $host );
		if ( $policy['robots'] === 'honor' && ! VendorFetcher::robots_allows( $url, $host ) ) {
			return self::save( $url, $host, 0, 'robots_disallow', '' );
		}
		$key = VendorFetcher::throttle_key( $host );
		\MMI_API_Throttler::throttle( $key, [ 'min_gap_ms' => $policy['gap_ms'] ] );
		// A good copy already stored outlives a re-read the site refuses or that fails (augment only: on 2026-10-19 a
		// re-read of one maker's site replaced three good copies with 403s). A page captured in the browser outlives any failure.
		$kept     = self::good_copy( $row );
		$captured = $kept && $row['method'] === 'capture' ? $kept : null;
		// The browser found the page gone (capture_gone()): that outlives the server being refused again.
		$gone     = $row && $row['method'] === 'capture' && in_array( $row['status'], [ 'http_404', 'http_410' ], true )
			? [ 'status' => $row['status'], 'code' => (int) $row['http_code'], 'html' => '', 'title' => '', 'from_store' => true, 'final_url' => '', 'note' => (string) $row['note'] ]
			: null;
		$cur = $url;
		for ( $hop = 0; ; $hop++ ) {
			$res = wp_safe_remote_get( // safe: a vendor address from a feed never reaches a private or local host
				$cur,
				[
					'timeout'             => VendorFetcher::TIMEOUT_S,
					'redirection'         => 0, // followed here, so each hop is checked and the last one kept
					'user-agent'          => FetchPolicy::browser_ua(),
					'limit_response_size' => VendorFetcher::MAX_BYTES,
					'headers'             => [
						'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
						'Accept-Language' => 'en-US,en;q=0.9',
					],
				]
			);
			if ( is_wp_error( $res ) ) {
				return $captured ?? $gone ?? ( $kept ? self::keep( $url, $kept, 'the re-read failed (' . self::reason( $res, $cur ) . ')' ) : self::save( $url, $host, 0, 'error', '', 'http', $cur !== $url ? $cur : '', self::reason( $res, $cur ) ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$next = $code >= 300 && $code < 400 ? self::location( $res, $cur ) : '';
			if ( $next === '' ) {
				break;
			}
			if ( ! self::same_site( $url, $next ) ) {
				return $captured ?? self::save( $url, $host, $code, 'moved_offsite', '', 'http', $next, 'Redirects to another site: ' . strtolower( (string) wp_parse_url( $next, PHP_URL_HOST ) ) );
			}
			if ( $hop >= self::MAX_REDIRECTS ) {
				return $captured ?? self::save( $url, $host, $code, 'redirect_loop', '', 'http', $next, sprintf( 'More than %d redirects', self::MAX_REDIRECTS ) );
			}
			$cur = $next;
		}
		if ( $code === 429 || $code === 503 ) {
			\MMI_API_Throttler::penalize( $key, (int) wp_remote_retrieve_header( $res, 'retry-after' ) );
		}
		if ( $code !== 200 && $captured ) {
			return $captured;
		}
		if ( $gone && ( in_array( $code, self::KEEP_ON, true ) || $code >= 500 ) ) {
			return $gone;
		}
		if ( $kept && ( in_array( $code, self::KEEP_ON, true ) || $code >= 500 ) ) {
			return self::keep( $url, $kept, sprintf( 'the re-read was refused (HTTP %d)', $code ) );
		}
		$html = $code === 200 ? (string) wp_remote_retrieve_body( $res ) : '';
		if ( $code === 200 && ! UrlFinder::same_url( $url, $cur ) ) {
			// The page lives at another address on the same site now: stored there, and the old address says where.
			self::save( $cur, strtolower( (string) wp_parse_url( $cur, PHP_URL_HOST ) ), 200, 'ok', $html );
			return self::save( $url, $host, 200, 'moved', $html, 'http', $cur, 'Now at ' . $cur );
		}
		if ( $code === 200 && self::is_wall( $html ) ) {
			return $captured ?? $gone ?? self::walled( $url, $host, $row, 'http' );
		}
		if ( $code === 200 && $captured && self::reads_less( $html, $captured['html'], $url ) ) {
			return self::keep( $url, $captured, 'the server’s copy reads less than the one from your browser' );
		}
		return self::save( $url, $host, $code, $code === 200 ? 'ok' : 'http_' . $code, $html, 'http', $cur !== $url ? $cur : '' );
	}

	/** The stored copy when it is a good page (not a bot check), in get()'s shape; null otherwise. */
	private static function good_copy( ?array $row ): ?array {
		if ( ! $row || $row['status'] !== 'ok' || (string) $row['html_file'] === '' ) {
			return null;
		}
		$html = self::read( (string) $row['html_file'] );
		if ( $html === '' || self::is_wall( $html ) ) {
			return null;
		}
		return [ 'status' => 'ok', 'code' => (int) $row['http_code'], 'html' => $html, 'title' => (string) $row['title'], 'from_store' => true, 'final_url' => (string) ( $row['final_url'] ?? '' ), 'note' => (string) ( $row['note'] ?? '' ) ];
	}

	/** Keep the good copy over a failed re-read: the row is only stamped, so it waits a normal re-read period. */
	private static function keep( string $url, array $copy, string $why ): array {
		global $wpdb;
		$note = mb_substr( 'Kept: ' . $why, 0, 255 );
		$wpdb->update( self::table(), [ 'fetched_at' => current_time( 'mysql', true ), 'note' => $note ], [ 'url_hash' => md5( $url ) ] );
		return [ 'note' => $note ] + $copy;
	}

	/**
	 * A firewall's bot check or block page instead of the page: small, and an Incapsula / DataDome / PerimeterX
	 * marker or a block page's words (VendorCatalog::BLOCK_RE: Cloudflare, Sucuri, Akamai…).
	 */
	public static function is_wall( string $html ): bool {
		return $html !== '' && strlen( $html ) <= self::WALL_MAX
			&& ( preg_match( self::WALL_RE, $html ) || preg_match( VendorCatalog::BLOCK_RE, $html ) );
	}

	/**
	 * A bot check came back: the site refuses this server, as a 202 challenge does (status http_202, so Capture
	 * offers the page to the browser). A good copy already stored stays (augment only): its row is only
	 * stamped, so it waits a normal re-read period.
	 */
	private static function walled( string $url, string $host, ?array $row, string $method ): array {
		global $wpdb;
		if ( $row && $row['status'] === 'ok' && $row['html_file'] !== '' ) {
			$html = self::read( (string) $row['html_file'] );
			if ( $html !== '' && ! self::is_wall( $html ) ) {
				$wpdb->update( self::table(), [ 'fetched_at' => current_time( 'mysql', true ), 'note' => mb_substr( 'Kept: the re-read got a bot check', 0, 255 ) ], [ 'url_hash' => md5( $url ) ] );
				return [ 'status' => 'ok', 'code' => (int) $row['http_code'], 'html' => $html, 'title' => (string) $row['title'], 'from_store' => true, 'final_url' => (string) ( $row['final_url'] ?? '' ), 'note' => 'Kept: the re-read got a bot check' ];
			}
		}
		return self::save( $url, $host, 200, 'http_202', '', $method, '', self::WALL_NOTE );
	}

	/**
	 * Stored bot checks (rows saved as "ok" before is_wall() existed): relabelled as refused (http_202, the note above)
	 * and their stub files deleted, so Capture's "pages the server can't read" lists them. $apply false only counts.
	 *
	 * @return array{checked:int,walls:int,hosts:array<string,int>}
	 */
	public static function repair_walls( bool $apply ): array {
		self::maybe_install();
		global $wpdb;
		$dir  = mmi_shared_lib_private_subdir( self::SUBDIR );
		$out  = [ 'checked' => 0, 'walls' => 0, 'hosts' => [] ];
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, url_hash, host, html_file FROM ' . self::table() . " WHERE status = 'ok' AND html_file <> '' AND id > %d ORDER BY id LIMIT 500", $last ), ARRAY_A );
			foreach ( $rows as $r ) {
				$last = (int) $r['id'];
				++$out['checked'];
				$file = (string) $r['html_file'];
				$path = $dir !== '' && strpos( $file, '/' ) === false ? $dir . $file : '';
				if ( $path === '' || ! is_readable( $path ) || filesize( $path ) > self::WALL_MAX ) {
					continue; // gz is smaller than the page: a large file is never a stub
				}
				if ( ! self::is_wall( self::read( $file ) ) ) {
					continue;
				}
				++$out['walls'];
				$out['hosts'][ $r['host'] ] = ( $out['hosts'][ $r['host'] ] ?? 0 ) + 1;
				if ( $apply ) {
					$wpdb->update( self::table(), [ 'status' => 'http_202', 'html_file' => '', 'content_hash' => '', 'note' => self::WALL_NOTE ], [ 'url_hash' => $r['url_hash'] ] );
					wp_delete_file( $path );
				}
			}
		} while ( count( $rows ) === 500 );
		if ( $apply && $out['walls'] && class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_pages.walls_relabelled', [ 'object_type' => 'page_store', 'details' => [ 'walls' => $out['walls'], 'hosts' => $out['hosts'] ] ] );
		}
		arsort( $out['hosts'] );
		return $out;
	}

	/** A stored page's row (status, title, final_url, note…), or null. */
	public static function row( string $url ): ?array {
		self::maybe_install();
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE url_hash = %s', md5( $url ) ), ARRAY_A );
		return $r ?: null;
	}

	/** Where a redirect points, as an absolute address ('' when it names none). */
	private static function location( $res, string $base ): string {
		$loc = wp_remote_retrieve_header( $res, 'location' );
		$loc = is_array( $loc ) ? (string) end( $loc ) : (string) $loc;
		return $loc === '' ? '' : (string) \WP_Http::make_absolute_url( trim( $loc ), $base );
	}

	/** Same site: the same registrable domain ("store.acoustica.com" and "www.acoustica.com" are; "producernb.com" and a casino aren't). */
	public static function same_site( string $a, string $b ): bool {
		$base = static function ( string $u ): string {
			$labels = explode( '.', preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) ) );
			$n      = count( $labels );
			$keep   = $n >= 3 && strlen( $labels[ $n - 1 ] ) === 2 && in_array( $labels[ $n - 2 ], [ 'co', 'com', 'net', 'org', 'ac', 'gov', 'edu' ], true ) ? 3 : 2; // example.co.uk
			return implode( '.', array_slice( $labels, -$keep ) );
		};
		$x = $base( $a );
		return $x !== '' && $x === $base( $b );
	}

	/** A failed fetch in words, for the Vendor pages Result column. */
	private static function reason( \WP_Error $e, string $url ): string {
		$m    = $e->get_error_message();
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( preg_match( '/valid URL/i', $m ) && $host !== '' && gethostbyname( $host ) === $host ) {
			return 'The site\'s address no longer exists (DNS)'; // wp_safe_remote_get() calls a host it cannot resolve not a valid URL
		}
		$map = [
			'/timed? ?out|Operation timed out/i'                       => sprintf( 'No answer within %d seconds', VendorFetcher::TIMEOUT_S ),
			'/resolve host|getaddrinfo|Name or service not known/i'    => 'The site\'s address no longer exists (DNS)',
			'/SSL|certificate/i'                                       => 'The site\'s security certificate is invalid',
			'/Connection refused|Failed to connect|Could not connect/i' => 'The site refused the connection',
			'/valid URL/i'                                             => 'Not a public web address',
		];
		foreach ( $map as $re => $words ) {
			if ( preg_match( $re, $m ) ) {
				return $words;
			}
		}
		return mb_substr( $m, 0, 200 );
	}

	/**
	 * A page rendered in headless Chromium, from the store when a rendered copy
	 * is younger than $max_age_days. Returns get()'s shape plus `method`;
	 * status `busy` (server over the load/RAM limits) and `render_error` are
	 * not stored, so the next run tries again.
	 *
	 * @return array{status:string,code:int,html:string,title:string,from_store:bool,method:string}
	 */
	public static function render( string $url, int $max_age_days = VendorFetcher::REFETCH_DAYS ): array {
		self::maybe_install();
		global $wpdb;
		$none = [ 'code' => 0, 'html' => '', 'title' => '', 'from_store' => false, 'method' => 'chromium' ];
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return [ 'status' => 'bad_url' ] + $none;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE url_hash = %s', md5( $url ) ), ARRAY_A );
		if ( $row && in_array( $row['method'], [ 'chromium', 'capture' ], true ) && $row['fetched_at'] && strtotime( $row['fetched_at'] . ' UTC' ) > time() - VendorFetcher::keep_days( (string) $row['status'], $max_age_days ) * DAY_IN_SECONDS ) {
			return [ 'status' => $row['status'], 'code' => (int) $row['http_code'], 'html' => self::read( (string) $row['html_file'] ), 'title' => (string) $row['title'], 'from_store' => true, 'method' => 'chromium' ];
		}
		[ $status, $html, $code ] = self::run_browser( $url, $host );
		if ( $status === 'busy' || $status === 'render_error' ) {
			return [ 'status' => $status ] + $none;
		}
		if ( self::is_browser_error( $html ) ) {
			return [ 'status' => 'render_error' ] + $none; // Chromium's own "can't be reached" page, not the vendor's
		}
		if ( $status === 'ok' && $html !== '' && self::is_wall( $html ) ) {
			return self::walled( $url, $host, $row, 'chromium' ) + [ 'method' => 'chromium' ];
		}
		if ( $status === 'ok' && $html !== '' ) {
			$captured = $row && $row['method'] === 'capture' ? self::good_copy( $row ) : null;
			if ( $captured && self::reads_less( $html, $captured['html'], $url ) ) {
				return self::keep( $url, $captured, 'the server’s browser read less than yours' ) + [ 'method' => 'chromium' ];
			}
			return self::save( $url, $host, 200, 'ok', $html, 'chromium' ) + [ 'method' => 'chromium' ];
		}
		if ( preg_match( '/^http_\d+$/', $status ) ) {
			if ( $row && $row['status'] === 'ok' && $row['html_file'] !== '' ) {
				return [ 'status' => $status, 'code' => $code ] + $none; // a refused re-read never replaces a good copy
			}
			return self::save( $url, $host, $code, $status, '', 'chromium' ) + [ 'method' => 'chromium' ];
		}
		return [ 'status' => 'render_error' ] + $none;
	}

	/**
	 * A page as the server's browser draws it, stored nowhere (VendorMaps::render_check() compares it with the stored
	 * copy before a site's pages are read in the browser). CLI only. @return array{status:string,html:string}
	 */
	public static function browse( string $url ): array {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return [ 'status' => 'bad_url', 'html' => '' ];
		}
		[ $status, $html ] = self::run_browser( $url, $host );
		if ( $status === 'ok' && ( $html === '' || self::is_browser_error( $html ) ) ) {
			$status = 'render_error';
		} elseif ( $status === 'ok' && self::is_wall( $html ) ) {
			$status = 'wall';
		}
		return [ 'status' => $status, 'html' => $status === 'ok' ? $html : '' ];
	}

	/** Headless Chromium on one page (RENDER_SCRIPT), paced per host: [ status, html, http code ]. CLI only, never over the load limits. */
	private static function run_browser( string $url, string $host ): array {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) || self::server_busy() ) {
			return [ 'busy', '', 0 ];
		}
		$node   = is_executable( '/usr/bin/node' ) ? '/usr/bin/node' : '/usr/local/bin/node';
		$home   = function_exists( 'posix_getpwuid' ) ? (string) ( posix_getpwuid( posix_geteuid() )['dir'] ?? '' ) : (string) getenv( 'HOME' );
		$chrome = glob( $home . '/.cache/ms-playwright/chromium-*/chrome-linux64/chrome' );
		$script = dirname( __DIR__, 3 ) . '/' . self::RENDER_SCRIPT;
		$tmp    = wp_tempnam( 'mmpt-render' );
		if ( ! is_executable( $node ) || ! $chrome || ! is_readable( $script ) || $tmp === '' ) {
			return [ 'render_error', '', 0 ];
		}
		\MMI_API_Throttler::throttle( VendorFetcher::throttle_key( $host ), [ 'min_gap_ms' => self::policy( $host )['gap_ms'] ] );
		$proc = proc_open(
			[ 'timeout', (string) self::RENDER_TIMEOUT, $node, $script, $url, $tmp ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes,
			null,
			[ 'NODE_PATH' => $home . '/.cache/mmi-browser-checks/node_modules', 'MMI_RENDER_CHROME' => (string) end( $chrome ), 'HOME' => $home, 'PATH' => '/usr/local/bin:/usr/bin:/bin' ]
		);
		if ( ! is_resource( $proc ) ) {
			wp_delete_file( $tmp );
			return [ 'render_error', '', 0 ];
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		proc_close( $proc );
		$lines = array_filter( explode( "\n", trim( $stdout ) ) );
		$res   = (array) json_decode( (string) end( $lines ), true );
		$html  = ( $res['status'] ?? '' ) === 'ok' && is_readable( $tmp ) ? (string) file_get_contents( $tmp ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $tmp );
		$status = (string) ( $res['status'] ?? '' );
		return [ $status !== '' ? $status : 'render_error', $html, (int) ( $res['code'] ?? 0 ) ];
	}

	/**
	 * A page sent from the owner's browser (Capture): stored as rendered HTML
	 * (method `capture`), read by get() and render() like any other copy, and
	 * kept when a later server fetch of the same URL is refused.
	 *
	 * A good copy already stored that reads more is kept (reads_less()): the answer is that copy, with `kept` and what
	 * each copy reads (`reads`, `reads_new`).
	 *
	 * @return array{status:string,code:int,html:string,title:string,from_store:bool,kept?:bool,reads?:array,reads_new?:array}
	 */
	public static function capture( string $url, string $html ): array {
		self::maybe_install();
		$kept = self::good_copy( self::row( $url ) );
		if ( $kept && self::reads_less( $html, $kept['html'], $url ) ) {
			return [ 'kept' => true, 'reads' => self::reads( $kept['html'], $url ), 'reads_new' => self::reads( $html, $url ) ] + $kept;
		}
		return self::save( $url, strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), 200, 'ok', $html, 'capture' );
	}

	/** What the reader gets from a page: its text (characters) and pictures. @return array{text:int,pictures:int} */
	public static function reads( string $html, string $url ): array {
		static $memo = [];
		$key = md5( $url . '|' . $html );
		if ( ! isset( $memo[ $key ] ) ) {
			$x            = VendorPage::extract( $html, $url );
			$memo         = array_slice( $memo, -8, null, true ); // two copies a call, a few calls a request
			$memo[ $key ] = [ 'text' => (int) $x['text_chars'], 'pictures' => count( (array) $x['images'] ) ];
		}
		return $memo[ $key ];
	}

	/**
	 * The new copy gives the reader less than the old one: less text (READS_SLACK), fewer pictures, or a Magento gallery's
	 * data lost (a browser's copy has none: Magento removes it, leaving small thumbnails where it held the full pictures).
	 */
	public static function reads_less( string $new, string $old, string $url ): bool {
		if ( str_contains( $old, VendorPage::MAGENTO_GALLERY ) && ! str_contains( $new, VendorPage::MAGENTO_GALLERY ) ) {
			return true;
		}
		$n = self::reads( $new, $url );
		$o = self::reads( $old, $url );
		return $n['text'] < $o['text'] * self::READS_SLACK || $n['pictures'] < $o['pictures'];
	}

	/**
	 * A page the owner's browser found gone (HTTP 404 or 410) on a site that refuses the server: recorded as gone
	 * (method `capture`) unless a good copy is stored, and kept when a later server read is refused, so the page
	 * stops counting as refused and its product is offered a new page (owner 2026-10-27: noiseworksaudio.com's
	 * Complete Bundle stayed "refuses the server" after every Send).
	 *
	 * @return array{status:string,code:int,html:string,title:string,from_store:bool}
	 */
	public static function capture_gone( string $url, int $code ): array {
		self::maybe_install();
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE url_hash = %s', md5( $url ) ), ARRAY_A );
		return self::good_copy( $row ) ?? self::save( $url, strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), $code, 'http_' . $code, '', 'capture', '', sprintf( 'Gone in your browser (HTTP %d)', $code ) );
	}

	/** Over the AGENTS.md load-average or RAM bail-out threshold. */
	public static function server_busy(): bool {
		$load = function_exists( 'sys_getloadavg' ) ? (array) sys_getloadavg() : [];
		if ( (float) ( $load[0] ?? 0 ) > self::MAX_LOAD ) {
			return true;
		}
		$mem = @is_readable( '/proc/meminfo' ) ? (string) @file_get_contents( '/proc/meminfo' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- open_basedir hides /proc from PHP-FPM: the load check alone then (as MMI_Resource_Guard)
		if ( preg_match( '/MemTotal:\s+(\d+)/', $mem, $t ) && preg_match( '/MemAvailable:\s+(\d+)/', $mem, $a ) && (int) $t[1] > 0 ) {
			return ( 1 - (int) $a[1] / (int) $t[1] ) * 100 > self::MAX_RAM_PCT;
		}
		return false;
	}

	/** Record a fetch (and its HTML) and return it in get()'s shape. */
	private static function save( string $url, string $host, int $code, string $status, string $html, string $method = 'http', string $final = '', string $note = '' ): array {
		global $wpdb;
		$title = $html !== '' ? self::page_title( $html ) : '';
		$file  = '';
		if ( $html !== '' ) {
			$dir = mmi_shared_lib_private_subdir( self::SUBDIR );
			if ( $dir !== '' ) {
				$file = sanitize_file_name( preg_replace( '/^www\./', '', $host ) ) . '-' . md5( $url ) . '.html.gz';
				file_put_contents( $dir . $file, gzencode( $html, 6 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$wpdb->replace(
			self::table(),
			[
				'url_hash'     => md5( $url ),
				'url'          => mb_substr( $url, 0, 500 ),
				'host'         => $host,
				'method'       => $method,
				'http_code'    => $code,
				'status'       => $status,
				'title'        => mb_substr( $title, 0, 500 ),
				'html_file'    => $file,
				'content_hash' => $html !== '' ? md5( $html ) : '',
				'fetched_at'   => current_time( 'mysql', true ),
				'final_url'    => mb_substr( $final, 0, 500 ),
				'note'         => mb_substr( $note, 0, 255 ),
			]
		);
		return [ 'status' => $status, 'code' => $code, 'html' => $html, 'title' => $title, 'from_store' => false, 'final_url' => $final, 'note' => $note ];
	}

	/** Chromium's net-error page ("This site can't be reached"), which a blocked navigation used to leave behind. */
	public static function is_browser_error( string $html ): bool {
		return $html !== '' && ( strpos( $html, 'id="main-frame-error"' ) !== false || strpos( $html, 'chrome-error://' ) !== false );
	}

	/** Stored HTML ('' when there is none). */
	public static function read( string $file ): string {
		if ( $file === '' || strpos( $file, '/' ) !== false ) {
			return '';
		}
		$path = mmi_shared_lib_private_subdir( self::SUBDIR ) . $file;
		$gz   = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $gz === false ? '' : (string) gzdecode( $gz );
	}

	/* ── A stored page's stylesheets ───────────────────────────────────── */

	/**
	 * A stored page with its own stylesheets (same site) in place of their <link>s, each kept the first time the
	 * page is shown: a site's next build renames them, and a copy without them is unstyled (2026-10-27: duy.com's
	 * Next.js chunk was gone and every "fill" picture covered the whole frame). `gone` counts the sheets the site no
	 * longer has and nobody kept: the copy is older than the site's build. A sheet that can't be had now still
	 * loads from the site.
	 *
	 * @return array{html:string,gone:int}
	 */
	public static function styles( string $url, string $html ): array {
		$dir = mmi_shared_lib_private_subdir( self::CSS_SUBDIR );
		if ( $dir === '' || $url === '' || ! preg_match_all( '#<link\b[^>]*>#i', $html, $m ) ) {
			return [ 'html' => $html, 'gone' => 0 ];
		}
		$swap = [];
		$gone = 0;
		$n    = 0;
		foreach ( array_unique( $m[0] ) as $tag ) {
			if ( ! preg_match( '#\brel\s*=\s*["\']?[^"\'>]*\bstylesheet\b#i', $tag ) || ! preg_match( '#\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $tag, $h ) ) {
				continue;
			}
			$href = (string) \WP_Http::make_absolute_url( html_entity_decode( trim( $h[1] . ( $h[2] ?? '' ) . ( $h[3] ?? '' ) ), ENT_QUOTES ), $url );
			if ( ! preg_match( '#^https?://#i', $href ) || ! self::same_site( $url, $href ) || $n++ >= self::CSS_MAX ) {
				continue; // another site's sheets (fonts, CDNs) load from there, as before
			}
			$css = self::sheet( $dir, $href );
			if ( $css === null ) {
				++$gone;
				continue;
			}
			if ( $css !== '' ) {
				$media         = preg_match( '#\bmedia\s*=\s*(["\'])(.*?)\1#i', $tag, $md ) ? ' media="' . esc_attr( $md[2] ) . '"' : '';
				$swap[ $tag ] = '<style data-mmpt-sheet="' . esc_attr( $href ) . '"' . $media . '>' . str_ireplace( '</style', '<\/style', self::css_urls( $css, $href ) ) . '</style>';
			}
		}
		return [ 'html' => $swap ? strtr( $html, $swap ) : $html, 'gone' => $gone ];
	}

	/** A stylesheet as kept, else fetched and kept; '' when it can't be had now, null when the site no longer has it. */
	// audit-ok: one-off fetch per sheet, kept on disk after; <= CSS_MAX (8) per page a person opens
	private static function sheet( string $dir, string $href ): ?string {
		$path = $dir . md5( $href ) . '.css.gz';
		if ( is_readable( $path ) ) {
			$gz = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $gz === false ? '' : (string) gzdecode( $gz );
		}
		$res = wp_safe_remote_get( // safe: an address from a stored page never reaches a private or local host
			$href,
			[
				'timeout'             => self::CSS_TIMEOUT,
				'user-agent'          => FetchPolicy::browser_ua(),
				'limit_response_size' => self::CSS_BYTES,
				'headers'             => [ 'Accept' => 'text/css,*/*;q=0.1' ],
			]
		);
		if ( is_wp_error( $res ) ) {
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
		if ( in_array( $code, [ 404, 410 ], true ) || ( $code === 200 && strpos( $type, 'html' ) !== false ) ) {
			return null; // a site that answers a missing file with its home page has no such sheet either
		}
		$css = $code === 200 ? (string) wp_remote_retrieve_body( $res ) : '';
		if ( $css === '' ) {
			return '';
		}
		file_put_contents( $path, gzencode( $css, 6 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $css;
	}

	/** A sheet's url()s and @imports resolved against the sheet's own address (inline, they would follow the page's). */
	private static function css_urls( string $css, string $href ): string {
		$abs = static fn( string $u ): string => preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $u ) ? $u : (string) \WP_Http::make_absolute_url( $u, $href );
		$css = (string) preg_replace_callback( '#url\(\s*(["\']?)([^"\')]*)\1\s*\)#i', static fn( $m ) => 'url(' . $m[1] . $abs( trim( $m[2] ) ) . $m[1] . ')', $css );
		return (string) preg_replace_callback( '#@import\s+(["\'])([^"\']+)\1#i', static fn( $m ) => '@import ' . $m[1] . $abs( $m[2] ) . $m[1], $css );
	}

	/** What the page says it is: og:title, <title> and the first <h1>, joined. */
	public static function page_title( string $html ): string {
		$parts = [];
		if ( preg_match( '/<meta\s[^>]*property\s*=\s*["\']og:title["\'][^>]*>/i', $html, $m ) && preg_match( '/content\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $m[0], $c ) ) {
			$parts[] = $c[2] !== '' ? $c[2] : ( $c[3] ?? '' );
		}
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
			$parts[] = $m[1];
		}
		if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
			$parts[] = $m[1];
		}
		$parts = array_unique( array_filter( array_map( static fn( $p ) => trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $p ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ), $parts ), 'strlen' ) );
		return implode( ' | ', $parts );
	}
}
