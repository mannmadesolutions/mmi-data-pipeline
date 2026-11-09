<?php
/**
 * Product Content — deciding a proposed vendor page from what the server already has (owner 2026-10-22: "it's
 * difficult and time consuming to visually inspect and then possibly click 'mmpt-url', wait for the webpage to load,
 * then make a decision"; 328 of 384 open proposals already had their page stored).
 *
 * evidence(): for products whose proposed page waits for a decision (UrlFinder rows `candidate`), our product beside
 * the proposed page as stored: what it calls itself, its first pictures and the opening of its text, notes saying why
 * the match holds or doesn't (our old address redirects here, the same site, its title names it or the words it
 * lacks, its address names it, the maker's own name for it, not read yet, gone, another site, a login page), how the
 * product's current page stands, and the action that fits (use it, use the new address, read it first, no vendor
 * page). The page part is kept per stored copy (a transient keyed by its content hash); one request reads at most
 * BUDGET seconds of pages and returns the rest `pending`, which url-review.js asks for again.
 *
 * Vendor pages' review list and focus review, and the proposals in the Brands & sites drawer, show it
 * (url-review.js). Every decision goes through UrlQueue's routes, as before.
 *
 * serve(): admin-ajax `mmpt_url_page`: the stored copy of a product's proposed (or current) page, sent as Vendor maps
 * sends a stored page (VendorMaps::send_stored(): no scripts, a CSP), with its own stylesheets as kept
 * (PageStore::styles()). Only the product's own proposal or URL can be shown, and only its stylesheets are fetched;
 * a copy whose stylesheets the site no longer has is read again once (the site was rebuilt since).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UrlReview {

	const VERSION = 2;                    // the kept page part's shape: a new one reads every stored copy again (2: card names)
	const KEEP    = 14 * DAY_IN_SECONDS;  // a stored copy's page part, kept (a new copy has a new hash)
	const BUDGET  = 4.0;                  // seconds of page reading per request; the rest come back `pending`
	const EXCERPT = 280;                  // characters of the page's text shown
	const PICS    = 3;                    // pictures shown per page
	const MAX_IDS = 100;                  // products per request
	const LOGIN   = '~/(?:log-?in|sign-?in|auth|account|my-account|register)(?:[/?.#]|$)~i';
	const FETCH   = 120;                  // proposed pages never read, fetched per nightly run (throttled per host)
	const STREAMED = 50;                  // React streamed parts put in place per stored copy shown (streamed())
	const NEARLY  = 0.34; // a title missing at most this share of the name's words still names it (a descriptor like "Compressor")
	const CARD    = 50;   // SharedPages::covers() of a card's name for it to name the product (as Find pages' card matches)
	const SURE    = 85;   // a rating this or more needs no person: an info or home address isn't flagged (OnePage::pages())
	const FOLLOW  = 75;   // a same-site redirect whose page rates this or more is followed without a click (Scraper::follow_move(); owner 2026-10-28: every bad redirect then open rated 65 or less)
	// A proposal's confidence: the start plus its notes' points, at most max (a person's choice is the only certainty).
	// moved: our address redirecting there, in full only when the page or its address names the product (a redirect to
	// a support or family page says little); lacks: a title missing at most half the name's words, else lacks_most.
	// approved: a product's current page that was approved as its page (by a person or a rule with its own evidence).
	// home: a site's home page (a dead product's address often redirects there). generic_brands: a word in this many
	// brands' product names is a descriptor ("echo", "synthesizer"). v: the rules' version (stored ratings follow it).
	const CONF    = [ 'start' => 50, 'max' => 95, 'moved' => 35, 'moved_unnamed' => 10, 'lacks' => -15, 'lacks_most' => -25, 'approved' => 25, 'home' => -40, 'generic_brands' => 5, 'v' => 2 ];

	const RATES_TABLE = 'mmpt_url_conf';   // each product's stored rating (rates())
	const RATES_DB    = 'mmpt_url_conf_db'; // its schema version
	const RATE_HOOK   = 'mmpt_url_rate';    // the background job rating what a request had no time for
	const RATE_JOB_S  = 20.0;               // seconds of rating per background run

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'wp_ajax_mmpt_url_page', [ __CLASS__, 'serve' ] );
		add_action( self::RATE_HOOK, [ __CLASS__, 'rate_job' ] );
	}

	public static function routes(): void {
		register_rest_route(
			Module::NS,
			'/urls/preview',
			[
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( Module::CAP ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					$urls = array_slice( array_values( array_unique( array_filter( array_map( 'strval', (array) $r->get_param( 'urls' ) ), static fn( $u ) => (bool) wp_http_validate_url( $u ) ) ) ), 0, self::MAX_IDS );
					$ids  = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ) ) ) ), 0, self::MAX_IDS );
					$read = ! PageStore::server_busy();
					return rest_ensure_response( [ 'rows' => (object) self::preview( $urls, $read ), 'rates' => (object) self::current( $ids, $read ) ] );
				},
			]
		);
		register_rest_route(
			Module::NS,
			'/urls/evidence',
			[
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( Module::CAP ),
				'callback'            => static function ( \WP_REST_Request $r ) {
					if ( PageStore::server_busy() ) {
						return new \WP_REST_Response( [ 'message' => 'The server is busy right now. Try again in a minute.' ], 503 );
					}
					$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ) ) ) ), 0, self::MAX_IDS );
					return rest_ensure_response( [ 'rows' => (object) self::evidence( $ids ) ] );
				},
			]
		);
	}

	/**
	 * Each product's proposal as evidence (null for a product with none waiting). @return array<int,?array>
	 */
	public static function evidence( array $ids ): array {
		$start = microtime( true );
		$out   = [];
		foreach ( $ids as $id ) {
			$row        = UrlFinder::row( $id );
			$out[ $id ] = $row && $row['status'] === 'candidate' && get_post_type( $id ) === 'product'
				? self::one( $id, $row, microtime( true ) - $start < self::BUDGET )
				: null;
		}
		return $out;
	}

	/**
	 * What each address shows, from its stored copy (nothing is fetched): its status, what it calls itself, its first
	 * picture, when it was stored and where it now lives (owner 2026-10-23: a row's address and its "Now at" should be
	 * easy to tell apart, with previews of their pages). `pending`: this request had no time, or the server no room, to
	 * read it; product-row.js asks again.
	 * @return array<string,array{status:string,title:string,image:string,stored_at:string,final:string,pending:bool}>
	 */
	public static function preview( array $urls, bool $read = true ): array {
		$start = microtime( true );
		$out   = [];
		foreach ( $urls as $url ) {
			$pg          = PageStore::row( $url );
			$p           = self::page( $url, $pg, '', $read && microtime( true ) - $start < self::BUDGET );
			$out[ $url ] = [
				'status'    => $p['status'],
				'title'     => $p['title'],
				'image'     => (string) ( $p['images'][0] ?? '' ),
				'stored_at' => $p['stored_at'],
				'final'     => $pg ? (string) $pg['final_url'] : '',
				'pending'   => ! empty( $p['pending'] ),
			];
		}
		return $out;
	}

	/**
	 * How sure each product's current page is its page (owner 2026-10-23: a rating on every Product pages row, not only
	 * on proposals): the same notes and points as a proposal's, about the page it has now. Its address moving away or
	 * being gone counts against it; "same site" and "our address redirects here" don't apply. Null for a product with no
	 * address; `pending` when this request had no time, or the server no room, to read its page.
	 * @return array<int,?array{url:string,notes:array,confidence:?array,pending:bool}>
	 */
	public static function current( array $ids, bool $read = true ): array {
		$start = microtime( true );
		$out   = [];
		foreach ( $ids as $id ) {
			$cur = (string) get_post_meta( $id, 'vendor_product_url', true );
			if ( ! str_starts_with( $cur, 'http' ) || get_post_type( $id ) !== 'product' ) {
				$out[ $id ] = null;
				continue;
			}
			$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$brand = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
			$brand = is_array( $brand ) && $brand ? html_entity_decode( (string) $brand[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
			$pg    = PageStore::row( $cur );
			$page  = self::page( $cur, $pg, $title, $read && microtime( true ) - $start < self::BUDGET );
			if ( $page['pending'] ) {
				$out[ $id ] = [ 'url' => $cur, 'notes' => [], 'confidence' => null, 'pending' => true ];
				continue;
			}
			$fr    = UrlFinder::row( $id ); // how the address was found, when it is the one the finder holds
			$row   = $fr && UrlFinder::same_url( (string) $fr['url'], $cur ) ? $fr : [ 'reason' => '', 'vendor_name' => '' ];
			$lands = $pg && in_array( (string) $pg['status'], [ 'moved', 'moved_offsite' ], true ) ? (string) $pg['final_url'] : '';
			$login = (bool) preg_match( self::LOGIN, (string) wp_parse_url( $cur, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $cur, PHP_URL_QUERY ) );
			$ours  = [ 'chars' => mb_strlen( Descriptions::plain( (string) get_post_field( 'post_content', $id ) ) ) ];
			$notes = self::notes( $id, $row, $title, $brand, $cur, $page, $ours, false, false, $login, false, $lands );
			if ( $fr && ( $fr['status'] ?? '' ) === 'approved' && UrlFinder::same_url( (string) $fr['url'], $cur ) ) {
				array_unshift( $notes, [ 'good', 'Approved as its page', self::CONF['approved'] ] );
			}
			$out[ $id ] = [ 'url' => $cur, 'notes' => $notes, 'confidence' => self::confidence( $notes ), 'pending' => false ];
		}
		return $out;
	}

	/* ── Stored ratings (the confidence filter, owner 2026-10-23) ─────────── */

	public static function rates_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::RATES_TABLE;
	}

	private static function rates_install(): void {
		if ( get_option( self::RATES_DB ) === '1' ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::rates_table() . " (
  product_id bigint(20) unsigned NOT NULL,
  n tinyint(3) unsigned NOT NULL DEFAULT 0,
  kind varchar(10) NOT NULL DEFAULT '',
  sig char(32) NOT NULL DEFAULT '',
  rated_at datetime DEFAULT NULL,
  PRIMARY KEY  (product_id)
) {$wpdb->get_charset_collate()};" );
		update_option( self::RATES_DB, '1', false );
	}

	/**
	 * Each product's rating as a row of Product pages shows it: its proposal's when one waits (evidence()), else its
	 * current page's (current()); none without either. Stored with a signature of everything it reads (the addresses,
	 * their stored copies, the finder's row, the title, the rules' version), so a stored one is used only while all of
	 * that is unchanged. Ratings missing or out of date are worked out within $budget seconds (none while the server is
	 * busy); the rest come back in `unrated` for rate_later().
	 * @return array{n:array<int,int>,unrated:int[]}
	 */
	public static function rates( array $ids, float $budget ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = [ 'n' => [], 'unrated' => [] ];
		if ( ! $ids ) {
			return $out;
		}
		self::rates_install();
		$start = microtime( true );
		$sig   = self::signatures( $ids );
		$kept  = [];
		foreach ( array_chunk( array_keys( $sig ), 1000 ) as $chunk ) {
			foreach ( $wpdb->get_results( 'SELECT product_id, n, sig FROM ' . self::rates_table() . ' WHERE product_id IN (' . implode( ',', $chunk ) . ')', ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
				$kept[ (int) $x['product_id'] ] = $x;
			}
		}
		$room = ! PageStore::server_busy();
		foreach ( $sig as $id => [ $kind, $key ] ) {
			if ( isset( $kept[ $id ] ) && $kept[ $id ]['sig'] === $key ) {
				$out['n'][ $id ] = (int) $kept[ $id ]['n'];
				continue;
			}
			if ( ! $room || microtime( true ) - $start > $budget ) {
				$out['unrated'][] = $id;
				continue;
			}
			$n = null;
			if ( $kind === 'proposal' ) {
				$row = UrlFinder::row( $id );
				$e   = $row ? self::one( $id, $row, true ) : null;
				$n   = $e && $e['confidence'] ? (int) $e['confidence']['n'] : null;
			} else {
				$c = self::current( [ $id ], true )[ $id ] ?? null;
				$n = $c && $c['confidence'] ? (int) $c['confidence']['n'] : null;
			}
			if ( $n === null ) {
				$out['unrated'][] = $id;
				continue;
			}
			$wpdb->replace( self::rates_table(), [ 'product_id' => $id, 'n' => $n, 'kind' => $kind, 'sig' => $key, 'rated_at' => current_time( 'mysql', true ) ] );
			$out['n'][ $id ] = $n;
		}
		return $out;
	}

	/** What each product's rating reads, as [ kind, md5 ] (kind proposal | page); products with neither are left out. */
	private static function signatures( array $ids ): array {
		global $wpdb;
		$in    = implode( ',', $ids );
		$urls  = [];
		$title = [];
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'vendor_product_url' AND post_id IN ({$in})", ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
			$urls[ (int) $m['post_id'] ] = (string) $m['meta_value'];
		}
		foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$in})", ARRAY_A ) as $p ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
			$title[ (int) $p['ID'] ] = (string) $p['post_title'];
		}
		$found = [];
		foreach ( $wpdb->get_results( 'SELECT product_id, url, status, reason, vendor_name FROM ' . UrlFinder::table() . " WHERE product_id IN ({$in})", ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
			$found[ (int) $f['product_id'] ] = $f;
		}
		$hashes = [];
		foreach ( $ids as $id ) {
			foreach ( [ $urls[ $id ] ?? '', $found[ $id ]['url'] ?? '' ] as $u ) {
				if ( str_starts_with( $u, 'http' ) ) {
					$hashes[ md5( $u ) ] = true;
				}
			}
		}
		$pages = [];
		foreach ( array_chunk( array_keys( $hashes ), 1000 ) as $chunk ) {
			foreach ( $wpdb->get_results( 'SELECT url_hash, status, content_hash, final_url FROM ' . PageStore::table() . " WHERE url_hash IN ('" . implode( "','", $chunk ) . "')", ARRAY_A ) as $pg ) { // phpcs:ignore WordPress.DB.PreparedSQL -- md5 hex only
				$pages[ $pg['url_hash'] ] = $pg['status'] . '|' . $pg['content_hash'] . '|' . $pg['final_url'];
			}
		}
		$out = [];
		foreach ( $ids as $id ) {
			$cur  = (string) ( $urls[ $id ] ?? '' );
			$f    = $found[ $id ] ?? null;
			$kind = $f && $f['status'] === 'candidate' && str_starts_with( (string) $f['url'], 'http' ) ? 'proposal' : ( str_starts_with( $cur, 'http' ) ? 'page' : '' );
			if ( $kind === '' ) {
				continue;
			}
			$out[ $id ] = [ $kind, md5( implode( "\n", [ self::CONF['v'], self::VERSION, $kind, $cur, $pages[ md5( $cur ) ] ?? '', $f ? implode( '|', $f ) : '', $f ? ( $pages[ md5( (string) $f['url'] ) ] ?? '' ) : '', $title[ $id ] ?? '' ] ) ) ];
		}
		return $out;
	}

	/** The ratings a request had no time for, worked out in the background (one job at a time, its own budget). */
	public static function rate_later( array $ids ): void {
		$ids = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, 2000 );
		if ( $ids && ! get_transient( self::RATE_HOOK ) ) { // wp_next_scheduled() only finds a job by its exact arguments
			set_transient( self::RATE_HOOK, 1, 10 * MINUTE_IN_SECONDS );
			wp_schedule_single_event( time() + 15, self::RATE_HOOK, [ $ids ] );
		}
	}

	public static function rate_job( $ids ): void {
		$r = self::rates( (array) $ids, self::RATE_JOB_S );
		if ( $r['unrated'] ) {
			set_transient( self::RATE_HOOK, 1, 10 * MINUTE_IN_SECONDS );
			wp_schedule_single_event( time() + ( PageStore::server_busy() ? 300 : 60 ), self::RATE_HOOK, [ $r['unrated'] ] );
		} else {
			delete_transient( self::RATE_HOOK );
		}
	}

	/**
	 * Nightly: first fetch the proposed pages nobody has read yet (owner 2026-10-22: "Read it now" was the click every
	 * such row needed before it could be decided), then keep the page part of every open proposal whose stored copy
	 * isn't kept yet. @return array{fetched:int,read:int,left:int}
	 */
	public static function warm( int $max = 500, int $fetch = self::FETCH ): array {
		global $wpdb;
		$fetched = 0;
		$unread  = $fetch > 0 ? $wpdb->get_col(
			$wpdb->prepare(
				'SELECT c.url FROM ' . UrlFinder::table() . " c JOIN {$wpdb->posts} p ON p.ID = c.product_id AND p.post_status = 'publish'
				 LEFT JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( c.url )
				 WHERE c.status = 'candidate' AND c.url LIKE 'http%' AND pg.url_hash IS NULL" . Exclusions::sql_not_excluded( 'c.product_id' ) . ' LIMIT %d',
				$fetch
			)
		) : [];
		foreach ( array_unique( $unread ) as $url ) {
			if ( PageStore::server_busy() ) {
				break;
			}
			PageStore::get( (string) $url );
			$fetched++;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.product_id, c.url FROM ' . UrlFinder::table() . " c JOIN {$wpdb->posts} p ON p.ID = c.product_id AND p.post_status = 'publish'
				 JOIN " . PageStore::table() . " pg ON pg.url_hash = MD5( c.url ) AND pg.status = 'ok' AND pg.html_file <> ''
				 WHERE c.status = 'candidate'" . Exclusions::sql_not_excluded( 'c.product_id' ) . ' LIMIT %d',
				$max
			),
			ARRAY_A
		);
		$read = 0;
		foreach ( $rows as $r ) {
			if ( PageStore::server_busy() ) {
				break;
			}
			$pg = PageStore::row( (string) $r['url'] );
			if ( $pg && get_transient( self::key( (string) $r['url'], (string) $pg['content_hash'] ) ) === false ) {
				self::page( (string) $r['url'], $pg, html_entity_decode( get_the_title( (int) $r['product_id'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
				$read++;
			}
		}
		return [ 'fetched' => $fetched, 'read' => $read, 'left' => max( 0, count( $rows ) - $read ) ];
	}

	/* ── One product ────────────────────────────────────────────────────── */

	private static function one( int $id, array $row, bool $read ): array {
		$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$brand = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
		$brand = is_array( $brand ) && $brand ? html_entity_decode( (string) $brand[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		$cur   = (string) get_post_meta( $id, 'vendor_product_url', true );
		$cur   = str_starts_with( $cur, 'http' ) ? $cur : '';
		$url   = (string) $row['url'];
		$pg    = PageStore::row( $url );
		$page  = self::page( $url, $pg, $title, $read );
		$thumb = (int) get_post_thumbnail_id( $id );
		$ours  = [
			'image'    => $thumb ? (string) wp_get_attachment_image_url( $thumb, 'medium' ) : '',
			'pictures' => count( PageEvents::pictures( $id ) ),
			'chars'    => mb_strlen( Descriptions::plain( (string) get_post_field( 'post_content', $id ) ) ),
		];
		$cpg    = $cur !== '' ? PageStore::row( $cur ) : null;
		$moved  = $cpg && in_array( (string) $cpg['status'], [ 'moved', 'moved_offsite' ], true ) && UrlFinder::same_url( (string) $cpg['final_url'], $url );
		$login  = (bool) preg_match( self::LOGIN, (string) wp_parse_url( $url, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $url, PHP_URL_QUERY ) );
		$away   = $row['reason'] === 'moved_offsite' || ( $cur !== '' && ! PageStore::same_site( $cur, $url ) );
		$lands  = $pg && in_array( (string) $pg['status'], [ 'moved', 'moved_offsite' ], true ) ? (string) $pg['final_url'] : ''; // where the proposed address itself redirects
		$junk   = $lands !== '' && UrlFinder::is_not_product_page( $lands );
		$p      = wp_parse_url( $url );
		$host   = strtolower( (string) ( $p['host'] ?? '' ) );
		$notes  = self::notes( $id, $row, $title, $brand, $url, $page, $ours, $moved, $away, $login, $cur !== '', $lands );
		return [
			'id'           => $id,
			'title'        => $title,
			'brand'        => $brand,
			'sku'          => (string) get_post_meta( $id, '_sku', true ),
			'edit_url'     => (string) get_edit_post_link( $id, 'raw' ),
			'ours'         => $ours,
			'proposed'     => [ 'url' => $url, 'host' => $host, 'path' => substr( $url, (int) strpos( $url, $host ) + strlen( $host ) ) ] + array_diff_key( $page, [ 'cards' => 1 ] ),
			'reason'       => (string) $row['reason'],
			'reason_label' => UrlQueue::REASONS[ $row['reason'] ] ?? (string) $row['reason'],
			'score'        => (int) $row['score'],
			'notes'        => $notes,
			'confidence'   => $page['pending'] ? null : self::confidence( $notes ),
			'now'          => self::now( $id, $cur, $cpg, $moved, $away ),
			'primary'      => $login ? 'none' : ( $junk ? 'reject' : ( $page['status'] === 'not_read' && ! $away ? 'read' : ( $moved ? 'use_new' : 'use' ) ) ),
		];
	}

	/**
	 * The proposed page as stored: status, what it calls itself, when it was stored, and (kept per stored copy) its
	 * first pictures, the opening of its text and its length. `pending` when this request had no time left to read it.
	 */
	private static function page( string $url, ?array $pg, string $title, bool $read ): array {
		$base = [ 'status' => $pg ? (string) $pg['status'] : 'not_read', 'title' => $pg ? self::tidy( (string) $pg['title'] ) : '', 'stored_at' => $pg ? (string) $pg['fetched_at'] : '', 'images' => [], 'text' => '', 'chars' => 0, 'pending' => false ];
		if ( ! $pg || $pg['status'] !== 'ok' || (string) $pg['html_file'] === '' ) {
			return $base;
		}
		$key  = self::key( $url, (string) $pg['content_hash'] );
		$kept = get_transient( $key );
		if ( is_array( $kept ) ) {
			return $kept + $base;
		}
		if ( ! $read ) {
			return [ 'pending' => true ] + $base;
		}
		$html = PageStore::read( (string) $pg['html_file'] );
		if ( $html === '' ) {
			return $base;
		}
		$x     = VendorPage::extract( $html, $url, $title );
		$imgs  = (array) $x['images'];
		usort( $imgs, static fn( $a, $b ) => (int) ! empty( $a['low_res'] ) <=> (int) ! empty( $b['low_res'] ) ); // sharp ones first
		$plain = trim( (string) preg_replace( '/\s+/u', ' ', Descriptions::plain( VendorPage::description_html( $x ) ) ) );
		$text  = mb_strlen( $plain ) > self::EXCERPT ? rtrim( (string) preg_replace( '/\s+\S*$/u', '', mb_substr( $plain, 0, self::EXCERPT ) ), ' ,.;:' ) . '…' : $plain;
		$part  = [
			'images' => array_slice( array_values( array_unique( array_filter( array_map( static fn( $i ) => (string) $i['url'], $imgs ) ) ) ), 0, self::PICS ),
			'text'   => $text,
			'chars'  => mb_strlen( $plain ),
			'cards'  => array_column( SharedPages::cards( $html, $url, (string) $pg['content_hash'] ), 'name' ), // a page listing several: its cards' names
		];
		set_transient( $key, $part, self::KEEP );
		return $part + $base;
	}

	/**
	 * How sure the proposal is, 0-95, like a title proposal's confidence (owner 2026-10-23): the start plus each note's
	 * points, so it only says what the notes say. The finder's own score is left out: each finder scores its own way
	 * (every "moved" proposal is 70). Null while the page part is pending: its title notes aren't known yet.
	 * @return array{n:int,why:string[]}
	 */
	public static function confidence( array $notes ): array {
		$n   = self::CONF['start'];
		$why = [];
		foreach ( $notes as $note ) {
			$pts = (int) ( $note[2] ?? 0 );
			if ( $pts !== 0 ) {
				$n    += $pts;
				$why[] = sprintf( '%+d %s', $pts, $note[1] );
			}
		}
		return [ 'n' => max( 0, min( self::CONF['max'], $n ) ), 'why' => $why ];
	}

	/** A site's home page: the bare address, or a page that calls itself "Home | …". */
	private static function is_home( string $url, string $title ): bool {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		return $path === '' || in_array( strtolower( $path ), [ 'index.html', 'index.php', 'home', 'en', 'en-us' ], true ) || (bool) preg_match( '/^\s*home\s*[|\-–—:]/iu', $title );
	}

	/**
	 * Words many brands' product names use (CONF generic_brands), so a page title lacking only those still names the
	 * product: one query over the catalog's titles, kept a day. @return string[]
	 */
	private static function generic_words(): array {
		$kept = get_transient( 'mmpt_ur_generic_words' );
		if ( is_array( $kept ) ) {
			return $kept;
		}
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT p.post_title t, tr.term_taxonomy_id b FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' WHERE p.post_type = 'product' AND p.post_status = 'publish'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
		$by   = [];
		foreach ( $rows as $r ) {
			foreach ( UrlFinder::tokens( (string) preg_replace( '/\([^)]*\)/', ' ', html_entity_decode( (string) $r['t'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) as $t ) {
				$by[ $t ][ $r['b'] ] = true;
			}
		}
		$words = array_keys( array_filter( $by, static fn( $b ) => count( $b ) >= self::CONF['generic_brands'] ) );
		set_transient( 'mmpt_ur_generic_words', $words, DAY_IN_SECONDS );
		return $words;
	}

	private static function key( string $url, string $hash ): string {
		return 'mmpt_ur_' . md5( $url . '|' . $hash . '|' . self::VERSION );
	}

	/**
	 * Why the match holds or doesn't: [ tone, words, points ], tone good | caution | bad | info. Every note is a fact
	 * about the two pages; none decides. The points are what the note does to the proposal's confidence (confidence()).
	 */
	private static function notes( int $id, array $row, string $title, string $brand, string $url, array $page, array $ours, bool $moved, bool $away, bool $login, bool $has_url, string $lands = '' ): array {
		$n     = [];
		$named = false; // the page, its address or the maker's list names the product
		if ( $moved ) {
			$n[] = [ 'good', 'Our address now redirects here', self::CONF['moved'] ];
		}
		if ( $has_url ) {
			$terms = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'ids' ] );
			$site  = BrandSites::roles()[ (int) ( is_array( $terms ) ? ( $terms[0] ?? 0 ) : 0 ) ][ BrandSites::host( $url ) ] ?? '';
			// A move to another site is a doubt unless that site is the brand's own (moforte.com's products have their pages on ilio.com, also its official site).
			$n[] = ! $away ? [ 'good', 'Same site as our page', 5 ] : ( in_array( $site, [ 'official', 'partner' ], true ) ? [ 'info', 'On another of the brand’s sites' ] : [ 'bad', 'On another site than our page', -20 ] );
		}
		if ( $login ) {
			$n[] = [ 'bad', 'A login page, not a product', -60 ];
		}
		$status = $page['status'];
		if ( $status === 'not_read' ) {
			$n[] = [ 'caution', 'Not read yet', -10 ];
		} elseif ( in_array( $status, [ 'http_404', 'http_410' ], true ) ) {
			$n[] = [ 'bad', 'The page is gone', -60 ];
		} elseif ( $lands !== '' ) {
			$to  = (string) wp_parse_url( $lands, PHP_URL_HOST ) . (string) wp_parse_url( $lands, PHP_URL_PATH );
			$n[] = UrlFinder::is_not_product_page( $lands )
				? [ 'bad', sprintf( 'It redirects to a basket, login or error page (%s)', $to ), -50 ]
				: [ 'caution', sprintf( 'It redirects to %s', $to ), -15 ];
		} elseif ( $status !== 'ok' ) {
			$n[] = [ 'caution', sprintf( 'Couldn’t be read (%s)', str_replace( 'http_', 'HTTP ', $status ) ), -15 ];
		} else {
			if ( self::is_home( $url, (string) $page['title'] ) ) {
				$n[] = [ 'bad', 'The site’s home page, not a product page', self::CONF['home'] ];
			}
			$name = (string) preg_replace( '/\([^)]*\)/', ' ', $title );
			$name = (string) preg_replace( '/\s(for|für)\s.*$/iu', '', $name ); // an add-on's host isn't its name ("GeoOboe for GeoShred Studio"), as in UrlFinder::best_match()
			$need = UrlFinder::tokens( $name, UrlFinder::tokens( $brand ) );
			$lack = array_values( array_diff( $need, UrlFinder::tokens( $page['title'] ) ) );
			$said = implode( ' ', array_slice( $lack, 0, 4 ) );
			$share = $need ? count( $lack ) / count( $need ) : 0;
			// What it lacks is only the name's trailing words, each a word many brands' products use, no number among
			// them, at most half the name: a descriptor ("Stardust 201 Tape Echo" on a page titled "Stardust 201"; owner
			// 2026-10-23: that right page rated 50). A missing model word ("AmpliTube Slash", "SynthMaster One"), version
			// ("v 6") or longer edition ("Platinum Plus Vota") still counts against it.
			$generic  = self::generic_words();
			$trailing = $lack && $share <= 0.5 && array_slice( $need, -count( $lack ) ) === $lack
				&& ! preg_grep( '/\d|^(one|two|three|four|five|six|seven|eight|nine|ten)$/', $lack )
				&& ! array_diff( $lack, $generic );
			if ( ! $lack || UrlFinder::page_names_product( $page['title'], $title, $brand ) ) {
				$n[]   = [ 'good', 'Its title names it', 25 ];
				$named = true;
			} elseif ( $share <= self::NEARLY || $trailing ) {
				$n[]   = [ 'info', sprintf( 'Its title names it, without “%s”', $said ), 15 ];
				$named = true;
			} elseif ( ( $card = self::card_naming( (array) ( $page['cards'] ?? [] ), $title, $brand ) ) !== '' ) {
				// A page listing several, one card each (owner 2026-10-28: one maker's synth page holds its expansion packs,
				// each described in a pop-up): the product reads its card there (SharedPages).
				$n[]   = [ 'good', sprintf( 'A card on it names it: “%s”', $card ), 20 ];
				$named = true;
			} else {
				$n[] = [ 'caution', sprintf( 'Its title lacks “%s”', $said ), $share > 0.5 ? self::CONF['lacks_most'] : self::CONF['lacks'] ];
			}
			if ( ! $page['pending'] && ! $page['images'] ) {
				$n[] = [ 'caution', 'No pictures on it', -10 ];
			}
			if ( $page['chars'] >= Coverage::MIN_PAGE_DESCRIPTION && $page['chars'] > $ours['chars'] ) {
				$n[] = [ 'info', sprintf( 'Its text %s characters; ours %s', number_format( $page['chars'] ), number_format( $ours['chars'] ) ) ];
			}
		}
		$code = UrlFinder::code_of( $id );
		if ( UrlFinder::code_url( $code, UrlFinder::slugs( [ $url ] ) ) !== '' ) {
			$n[]   = [ 'good', 'Its address is its part number', 20 ];
			$named = true;
		} elseif ( UrlFinder::slug_in_title( $url, $title, $brand ) ) {
			$n[]   = [ 'good', 'Its address names it', 10 ];
			$named = true;
		}
		$reason = (string) $row['reason'];
		$vendor = (string) ( $row['vendor_name'] ?? '' );
		if ( $reason === 'vendor_catalog' && $vendor !== '' ) {
			$n[] = [ 'good', sprintf( 'The maker’s list calls it %s', $vendor ), 15 ];
		} elseif ( $reason === 'card_link' ) {
			$n[] = [ 'good', 'A listing page’s card for it links here', 15 ];
		} elseif ( str_starts_with( $reason, 'xchange_feed' ) ) {
			$n[] = [ 'info', 'XChange names this page', 5 ];
		} elseif ( str_starts_with( $reason, 'plugivery_link' ) ) {
			$n[] = [ 'info', 'Plugivery links here', 5 ];
		}
		if ( $moved && ! $named && ! in_array( $reason, [ 'vendor_catalog', 'card_link' ], true ) ) {
			$n[0][2] = self::CONF['moved_unnamed'];
		}
		return $n;
	}

	/** The name of the card on a page that names the product best ('' when none does). */
	private static function card_naming( array $cards, string $title, string $brand ): string {
		$name = PageName::of( $title, $brand );
		$name = $name !== '' ? $name : $title;
		$best = '';
		$top  = self::CARD - 1;
		foreach ( $cards as $c ) {
			$s = SharedPages::covers( $name, $brand, (string) $c );
			if ( $s > $top ) {
				[ $best, $top ] = [ (string) $c, $s ];
			}
		}
		return $best;
	}

	/** How the product's current page stands: [ text, tone plain | caution | bad ]. */
	private static function now( int $id, string $cur, ?array $cpg, bool $moved, bool $away ): array {
		if ( $cur === '' ) {
			return [ 'text' => 'No vendor URL yet', 'tone' => 'plain' ];
		}
		$short = (string) preg_replace( '#^https?://(www\.)?#i', '', $cur );
		$never = UrlFinder::is_verified( get_post_meta( $id, 'vendor_product_url_verified', true ) ) ? '' : ' (never verified)';
		if ( $moved ) {
			return $away
				? [ 'text' => sprintf( '%s, which now redirects to another site (%s)', $short, (string) wp_parse_url( (string) $cpg['final_url'], PHP_URL_HOST ) ), 'tone' => 'bad' ]
				: [ 'text' => $short . ', which now redirects here' . $never, 'tone' => 'plain' ];
		}
		$s = $cpg ? (string) $cpg['status'] : 'not_read';
		if ( $s === 'ok' ) {
			$html = SharedPages::relation( $id, $cur )['kind'] === 'several' ? PageStore::read( (string) $cpg['html_file'] ) : '';
			if ( $html !== '' && ProductPage::own_html( $id, $cur, $html ) === null ) {
				return [ 'text' => sprintf( '%s (%s) lists several products; none is this one', $short, self::tidy( (string) $cpg['title'] ) ), 'tone' => 'bad' ];
			}
			return [ 'text' => $short . ( (string) $cpg['title'] !== '' ? ' (' . self::tidy( (string) $cpg['title'] ) . ')' : '' ) . $never, 'tone' => 'plain' ];
		}
		if ( $s === 'moved_offsite' ) {
			return [ 'text' => sprintf( '%s redirects to %s', $short, (string) wp_parse_url( (string) $cpg['final_url'], PHP_URL_HOST ) ), 'tone' => 'bad' ];
		}
		if ( in_array( $s, [ 'http_404', 'http_410' ], true ) ) {
			return [ 'text' => $short . ' is gone', 'tone' => 'bad' ];
		}
		return [ 'text' => $short . ( $s === 'not_read' ? ', not read yet' : sprintf( ', couldn’t be read (%s)', str_replace( 'http_', 'HTTP ', $s ) ) ) . $never, 'tone' => 'caution' ];
	}

	/** A page title with its repeated parts once ("X | X | Maker" → "X | Maker"). */
	private static function tidy( string $title ): string {
		$parts = array_values( array_filter( array_map( 'trim', explode( '|', html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ), 'strlen' ) );
		$seen  = [];
		foreach ( $parts as $p ) {
			$seen[ mb_strtolower( $p ) ] ??= $p;
		}
		return implode( ' | ', $seen );
	}

	/* ── The stored copy ────────────────────────────────────────────────── */

	/** admin-ajax `mmpt_url_page`: ?id=&which=proposed|current — that page's stored copy, script-free (see the class doc). */
	public static function serve(): void {
		if ( ! current_user_can( Module::CAP ) || ! check_ajax_referer( 'wp_rest', '_wpnonce', false ) ) {
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::denied( 'mmi-admin', 'vendor_url.page' );
			}
			status_header( 403 );
			exit;
		}
		$id    = absint( $_GET['id'] ?? 0 );
		$which = sanitize_key( wp_unslash( (string) ( $_GET['which'] ?? '' ) ) );
		$url   = $which === 'current' ? (string) get_post_meta( $id, 'vendor_product_url', true ) : (string) ( UrlFinder::row( $id )['url'] ?? '' );
		$pg    = $url !== '' ? PageStore::row( $url ) : null;
		$html  = $pg && $pg['status'] === 'ok' && (string) $pg['html_file'] !== '' ? PageStore::read( (string) $pg['html_file'] ) : '';
		$st    = PageStore::styles( $url, $html );
		if ( $st['gone'] && $pg['method'] === 'http' ) { // the site was rebuilt since this copy: read again, once (a capture or a render stays)
			$new = PageStore::get( $url, 0 );
			if ( $new['status'] === 'ok' && $new['html'] !== '' && empty( $new['from_store'] ) ) {
				$st = PageStore::styles( $url, $new['html'] );
			}
		}
		VendorMaps::send_stored( $url, self::streamed( $st['html'] ) );
	}

	/**
	 * React's streamed parts in place (Next.js): the server sends a part's fallback where it goes (duy.com: a grey
	 * pulsing box for the product picture) and the part itself at the end, in <div hidden id="S:n">, for a script
	 * ($RC) to swap in. With scripts off the fallback stayed. Each part now takes its fallback's place, as $RC does.
	 * Shown here only: the readers and Vendor maps read the page as stored.
	 */
	private static function streamed( string $html ): string {
		if ( strpos( $html, '<template id="B:' ) === false || ! preg_match_all( '#<div hidden(?:="")? id="S:(\d+)">#', $html, $m ) ) {
			return $html;
		}
		foreach ( array_slice( $m[1], 0, self::STREAMED ) as $i => $n ) {
			$start = strpos( $html, $m[0][ $i ] );
			$from  = $start + strlen( $m[0][ $i ] );
			$pos   = $from;
			$depth = 1;
			while ( $depth && preg_match( '#<(/?)div\b[^>]*>#i', $html, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
				$depth += $t[1][0] === '' ? 1 : -1;
				$pos    = $t[0][1] + strlen( $t[0][0] );
			}
			if ( $depth ) {
				continue; // unbalanced: left as the site sent it
			}
			$part = substr( $html, $from, $t[0][1] - $from );
			$html = substr_replace( $html, '', $start, $pos - $start );
			$mark = '<!--$?--><template id="B:' . $n . '"></template>';
			$at   = strpos( $html, $mark );
			$end  = $at === false ? false : strpos( $html, '<!--/$-->', $at );
			if ( $end === false || strpos( substr( $html, $at + strlen( $mark ), $end - $at - strlen( $mark ) ), '<!--$' ) !== false ) {
				$html = substr_replace( $html, $m[0][ $i ] . $part . '</div>', $start, 0 ); // no place, or one holding another part: left as sent
				continue;
			}
			$html = substr_replace( $html, '<!--$-->' . $part, $at, $end - $at );
		}
		return $html;
	}
}
