<?php
/**
 * URL decisions for the Vendor pages tab, the Brands & sites drawer and the WooCommerce
 * product screens (the "Vendor URLs" tab they came from was folded into Vendor pages on
 * 2026-10-08): the URL candidates UrlFinder held for a person — XChange URLs that disagree with a
 * working stored URL, couldn't be confirmed or sit on a reseller's site,
 * Plugivery manufacturer links next to an unverified URL, and sitemap
 * matches under the auto-use score.
 *
 * Each row carries the product's content coverage (Coverage: images,
 * description, features, requirements, version, manual, videos, audio) and
 * what the proposed page could fill; the Content column sorts by gaps.
 *
 * Approving or setting a URL adopts it (the product's `vendor_product_url` + verified)
 * and queues a scrape of that page at once (scrape_now). Rejecting keeps the stored URL
 * and stops the candidate coming back. Rows show what the page calls itself
 * (from the page store) so most decisions need no click-through; "Check
 * page" fetches it when the store has nothing yet and only reports. One request per action.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UrlQueue {

	const PER_PAGE = 50;

	/**
	 * A bulk approve may replace a verified page when the evidence says it is the wrong page and the proposal the right
	 * one: the proposal rates at least `proposal`, the verified page under `current` (UrlReview: owner 2026-10-24, two
	 * NUGEN products whose verified page was the maker's product list stayed on it through three bulk approvals).
	 */
	const REPLACE = [ 'proposal' => 85, 'current' => 55 ];

	/** Why a candidate is in the queue, in plain words (reason → label). */
	const REASONS = [
		'xchange_feed_differs'      => 'XChange names a different page than the working one we use',
		'xchange_feed_unverified'   => 'XChange URL; the URL we have was never verified',
		'xchange_feed_unconfirmed'  => "XChange URL; the page title doesn't clearly name the product",
		'xchange_feed_reseller'     => "XChange URL on a reseller's site",
		'plugivery_link_unverified' => "Plugivery's manufacturer link; the URL we have was never verified",
		'plugivery_link_doubtful'   => "Plugivery's link looks like a store, file or listing page",
		'sitemap_slug'              => "Found by name in the vendor's sitemap (weak match)",
		'vendor_catalog'            => "Named in the vendor's own product list (score = how closely its name matches ours)",
		'xchange_feed'              => 'XChange URL naming the product (adopted automatically)',
		'xchange_feed_page'         => 'XChange URL confirmed by the page title (adopted automatically)',
		'xchange_feed_sitemap'      => 'XChange and the sitemap agree (adopted automatically)',
		'plugivery_link'            => "Plugivery's manufacturer link (adopted automatically)",
		'manual'                    => 'Decided by a person',
		'auto_same_page'            => 'Closed automatically: the same page as our stored URL (canonical link, redirect or the same address)',
		'auto_dead'                 => 'Closed automatically: the proposed page is gone (404/410)',
		'auto_listing'              => 'Closed automatically: a listing or non-product page',
		'auto_stored_names'         => "Closed automatically: our page names the product, the proposed one doesn't",
		'auto_keep_ours'            => 'Closed automatically: our verified URL is better (the proposed page is shared, a parent, a listing or malformed)',
		'auto_in_use'               => "Closed automatically: the proposed URL already is the product's URL",
		'auto_not_product'          => 'Closed automatically: the proposed address goes to a basket, checkout, account, login or error page',
		'moved'                     => 'The old address now redirects here (followed automatically when the page names the product or rates 75+)',
		'moved_offsite'             => 'The old address redirects to another site: check it is the vendor before approving',
		'card_link'                 => 'Its card on a page listing several products links to this page',
		'sku_slug'                  => "The page's address is the product's own part number, in the maker's sitemap",
		'recheck'                   => 'Our verified page lists several products and no card names this one; this page names it',
		'recheck_card'              => 'Our verified page lists several products and its card there has little or no description; this page is its own',
	];

	/** Sortable columns → SQL (whitelist). */
	const ORDER = [
		'id'       => 'c.product_id',
		'product'  => 'p.post_title',
		'current'  => 'u.meta_value',
		'proposed' => 'c.url',
		'page'     => 'pg.title',
		'coverage' => '', // sorted in PHP by Coverage::scores()
		'reason'   => 'c.reason',
		'status'   => 'c.status',
		'score'    => 'c.score',
	];

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( Module::CAP );
		register_rest_route( Module::NS, '/urls', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'list' ] ] );
		register_rest_route( Module::NS, '/urls/decide', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'decide' ] ] );
		register_rest_route( Module::NS, '/urls/set-many', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'set_many' ] ] );
		register_rest_route( Module::NS, '/urls/none-many', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'none_many' ] ] );
		register_rest_route( Module::NS, '/urls/look-many', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'look_many' ] ] );
		register_rest_route(
			Module::NS,
			'/urls/(?P<id>\d+)/check',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
				'callback'            => [ __CLASS__, 'check' ],
			]
		);
		foreach ( [ 'set', 'clear', 'none', 'look' ] as $action ) {
			register_rest_route(
				Module::NS,
				'/urls/(?P<id>\d+)/' . $action,
				[
					'methods'             => 'POST',
					'permission_callback' => $admin,
					'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
					'callback'            => [ __CLASS__, $action . '_url' ],
				]
			);
		}
	}

	/** What the stored ("Current") URL is: verified, there but never verified, or none. */
	const VERIFIED = [
		'yes'  => "LEFT( u.meta_value, 4 ) = 'http' AND UPPER( v.meta_value ) IN ('TRUE','1')",
		'no'   => "LEFT( u.meta_value, 4 ) = 'http' AND UPPER( COALESCE( v.meta_value, '' ) ) NOT IN ('TRUE','1')",
		'none' => "COALESCE( LEFT( u.meta_value, 4 ), '' ) <> 'http'",
	];

	/** GET /urls?status=open|approved|rejected|all&reason=&host=&min_score=&verified=yes|no|none&search=&orderby=&order=&page=&per_page= */
	public static function list( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;
		UrlFinder::maybe_install();
		PageStore::maybe_install();
		$c        = UrlFinder::table();
		$pg       = PageStore::table();
		$status   = (string) $r->get_param( 'status' ) ?: 'open';
		$reason   = sanitize_key( (string) $r->get_param( 'reason' ) );
		$search   = trim( sanitize_text_field( (string) $r->get_param( 'search' ) ) );
		$host     = preg_replace( '/^www\./', '', strtolower( sanitize_text_field( (string) $r->get_param( 'host' ) ) ) ); // one vendor with or without "www." 
		$min      = max( 0, min( 100, (int) $r->get_param( 'min_score' ) ) );
		$verified = self::VERIFIED[ (string) $r->get_param( 'verified' ) ] ?? '';
		$per_page = min( 200, max( 10, (int) ( $r->get_param( 'per_page' ) ?: self::PER_PAGE ) ) );
		$page     = max( 1, (int) $r->get_param( 'page' ) );
		$by_cov   = (string) $r->get_param( 'orderby' ) === 'coverage';
		$orderby  = ( self::ORDER[ (string) $r->get_param( 'orderby' ) ] ?? '' ) ?: 'c.product_id';
		$order    = strtolower( (string) $r->get_param( 'order' ) ) === 'asc' ? 'ASC' : 'DESC';

		// Excluded and no-longer-sold products (Exclusions, NotSold) aren't worth a vendor page;
		// their rows stay in the table and come back if the product is sold again.
		$ex    = Exclusions::sql_not_excluded( 'c.product_id' );
		$where = [ "p.post_status = 'publish'" . $ex ];
		$args  = [];
		$map   = [ 'open' => 'candidate', 'approved' => 'approved', 'rejected' => 'rejected' ];
		if ( isset( $map[ $status ] ) ) {
			$where[] = 'c.status = %s';
			$args[]  = $map[ $status ];
		}
		if ( $reason !== '' ) {
			$where[] = 'c.reason = %s';
			$args[]  = $reason;
		}
		if ( $host !== '' ) {
			$where[] = "TRIM( LEADING 'www.' FROM c.host ) = %s";
			$args[]  = $host;
		}
		if ( $min > 0 ) {
			$where[] = 'c.score >= %d';
			$args[]  = $min;
		}
		if ( $verified !== '' ) {
			$where[] = $verified;
		}
		if ( $search !== '' ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '( p.post_title LIKE %s OR c.url LIKE %s OR u.meta_value LIKE %s OR c.product_id = %d )';
			array_push( $args, $like, $like, $like, (int) $search );
		}
		$from = "FROM {$c} c
			JOIN {$wpdb->posts} p ON p.ID = c.product_id
			LEFT JOIN {$wpdb->postmeta} u ON u.post_id = c.product_id AND u.meta_key = 'vendor_product_url'
			LEFT JOIN {$wpdb->postmeta} v ON v.post_id = c.product_id AND v.meta_key = 'vendor_product_url_verified'
			LEFT JOIN {$pg} pg ON pg.url_hash = MD5( c.url )
			WHERE " . implode( ' AND ', $where );
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) {$from}" ) );
		if ( $by_cov ) {
			// Most gaps first (desc): every matching product is scored (cached 15 min), then one page is read.
			$all    = array_map( 'intval', $args ? $wpdb->get_col( $wpdb->prepare( "SELECT c.product_id {$from}", $args ) ) : $wpdb->get_col( "SELECT c.product_id {$from}" ) );
			$scores = Coverage::scores( $all );
			usort( $all, static fn( $a, $b ) => ( $order === 'ASC' ? $scores[ $a ] <=> $scores[ $b ] : $scores[ $b ] <=> $scores[ $a ] ) ?: $b <=> $a );
			$ids  = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
			$rows = [];
			if ( $ids ) {
				$got = [];
				foreach ( $wpdb->get_results( 'SELECT ' . self::COLUMNS . ' ' . self::from_ids( $ids ), ARRAY_A ) as $x ) {
					$got[ (int) $x['product_id'] ] = $x;
				}
				foreach ( $ids as $id ) {
					if ( isset( $got[ $id ] ) ) {
						$rows[] = $got[ $id ];
					}
				}
			}
		} else {
			$sql  = 'SELECT ' . self::COLUMNS . " {$from} ORDER BY {$orderby} {$order}, c.product_id DESC LIMIT %d OFFSET %d";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, [ $per_page, ( $page - 1 ) * $per_page ] ) ), ARRAY_A );
		}
		// A product ID typed in the search box that has no row in the queue: shown on its own, so any
		// product's vendor URL can be set or cleared here (owner 2026-10-07: "manage the URLs if they are wrong").
		if ( ! $rows && ctype_digit( $search ) && get_post_type( (int) $search ) === 'product' && ! Exclusions::is_excluded( (int) $search ) ) {
			$rows  = [ self::product_row( (int) $search ) ];
			$total = 1;
		}

		$counts = [];
		foreach ( $wpdb->get_results( "SELECT c.reason, COUNT(*) n FROM {$c} c JOIN {$wpdb->posts} p ON p.ID = c.product_id AND p.post_status = 'publish' WHERE c.status = 'candidate'{$ex} GROUP BY c.reason", ARRAY_A ) as $x ) {
			$counts[ $x['reason'] ] = (int) $x['n'];
		}
		// Vendors with open rows, A–Z by the name the filter shows (no "www."; owner 2026-10-08), each with its count.
		$hosts = $wpdb->get_results( "SELECT TRIM( LEADING 'www.' FROM c.host ) host, COUNT(*) n FROM {$c} c JOIN {$wpdb->posts} p ON p.ID = c.product_id AND p.post_status = 'publish' WHERE c.status = 'candidate' AND c.host <> ''{$ex} GROUP BY 1 ORDER BY 1 LIMIT 300", ARRAY_A );
		$reasons = [];
		foreach ( self::REASONS as $key => $label ) {
			$reasons[] = [ 'key' => $key, 'label' => $label, 'open' => $counts[ $key ] ?? 0 ];
		}
		return rest_ensure_response(
			[
				'rows'    => self::out( $rows ),
				'total'   => $total,
				'pages'   => max( 1, (int) ceil( $total / $per_page ) ),
				'open'    => array_sum( $counts ),
				'reasons' => $reasons,
				'hosts'   => array_map( static fn( $x ) => [ 'host' => (string) $x['host'], 'open' => (int) $x['n'] ], $hosts ),
			]
		);
	}

	/** The columns every row needs (list, decide and the single-row routes share them). */
	const COLUMNS = 'c.product_id, c.url, c.host, c.score, c.status, c.reason, c.note, c.vendor_name, c.image, c.found_at, p.post_title,
			u.meta_value stored_url, v.meta_value verified, pg.title page_title, pg.status page_status, pg.fetched_at page_fetched,
			pg.html_file page_file, pg.content_hash page_hash';

	/** FROM … WHERE for these products' queue rows (the same joins as the list). */
	private static function from_ids( array $ids ): string {
		global $wpdb;
		return 'FROM ' . UrlFinder::table() . " c
			 JOIN {$wpdb->posts} p ON p.ID = c.product_id
			 LEFT JOIN {$wpdb->postmeta} u ON u.post_id = c.product_id AND u.meta_key = 'vendor_product_url'
			 LEFT JOIN {$wpdb->postmeta} v ON v.post_id = c.product_id AND v.meta_key = 'vendor_product_url_verified'
			 LEFT JOIN " . PageStore::table() . ' pg ON pg.url_hash = MD5( c.url )
			 WHERE c.product_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
	}

	/** Raw rows → the table's row shape, each with its content coverage. */
	private static function out( array $rows ): array {
		$cov = Coverage::for_rows( $rows );
		return array_map( static fn( $r ) => self::row( $r ) + [ 'coverage' => $cov[ (int) $r['product_id'] ] ?? null ], array_values( $rows ) );
	}

	/** Rows for these products, as the table shows them (a product with no queue row gets its own). @return array<int,array> */
	public static function rows_for( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$raw = [];
		foreach ( $wpdb->get_results( 'SELECT ' . self::COLUMNS . ' ' . self::from_ids( $ids ), ARRAY_A ) as $r ) {
			$raw[ (int) $r['product_id'] ] = $r;
		}
		foreach ( $ids as $id ) {
			if ( ! isset( $raw[ $id ] ) && get_post_type( $id ) === 'product' ) {
				$raw[ $id ] = self::product_row( $id );
			}
		}
		return self::out( $raw );
	}

	/** A product with no queue row, in the row shape (status `none`). */
	private static function product_row( int $id ): array {
		return [
			'product_id' => $id, 'url' => '', 'host' => '', 'score' => 0, 'status' => 'none', 'reason' => '', 'note' => '', 'vendor_name' => '', 'image' => '', 'found_at' => '',
			'post_title' => (string) get_post_field( 'post_title', $id ), 'stored_url' => (string) get_post_meta( $id, 'vendor_product_url', true ),
			'verified' => (string) get_post_meta( $id, 'vendor_product_url_verified', true ), 'page_title' => '', 'page_status' => '', 'page_fetched' => '', 'page_file' => '', 'page_hash' => '',
		];
	}

	/**
	 * POST /urls/{id}/set {url}: a person's own URL for the product, adopted at once
	 * (approved, reason `manual`). The page is read and scraped at once (scrape_now).
	 */
	public static function set_url( \WP_REST_Request $r ): \WP_REST_Response {
		$id  = (int) $r['id'];
		$err = self::set_one( $id, (string) $r->get_param( 'url' ) );
		if ( $err !== '' ) {
			return new \WP_REST_Response( [ 'message' => $err ], get_post_type( $id ) === 'product' ? 400 : 404 );
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.set', [ 'object_type' => 'product', 'object_id' => $id, 'details' => [ 'host' => strtolower( (string) wp_parse_url( (string) get_post_meta( $id, 'vendor_product_url', true ), PHP_URL_HOST ) ) ] ] );
		}
		return rest_ensure_response( [ 'rows' => self::rows_for( [ $id ] ), 'queue' => self::scrape_now( [ $id ] ) ] );
	}

	/**
	 * POST /urls/set-many {items: [{id, url}]}: a person's URLs for several products at once (the Brands &
	 * sites drawer's "Fill URLs from the site"), each set as /urls/{id}/set does, one scrape run for all
	 * and one audit event. Up to 200. A person ticked every row; a replaced URL is named in its note.
	 */
	public static function set_many( \WP_REST_Request $r ): \WP_REST_Response {
		$done   = [];
		$errors = [];
		foreach ( array_slice( (array) $r->get_param( 'items' ), 0, 200 ) as $it ) {
			$id  = (int) ( $it['id'] ?? 0 );
			$err = self::set_one( $id, (string) ( $it['url'] ?? '' ) );
			if ( $err === '' ) {
				$done[] = $id;
			} else {
				$errors[ $id ] = $err;
			}
		}
		if ( ! $done && ! $errors ) {
			return new \WP_REST_Response( [ 'message' => 'Nothing to do.' ], 400 );
		}
		if ( $done && class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.set', [ 'object_type' => 'product', 'details' => [ 'count' => count( $done ), 'ids' => $done ] ] );
		}
		return rest_ensure_response( [ 'done' => $done, 'errors' => (object) $errors, 'queue' => $done ? self::scrape_now( $done ) : null ] );
	}

	/** Make $url the product's vendor URL, decided by a person (also the Chrome extension's Link this page). '' when done, else why not. */
	public static function set_one( int $id, string $url ): string {
		global $wpdb;
		$url  = esc_url_raw( trim( $url ), [ 'http', 'https' ] );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( get_post_type( $id ) !== 'product' ) {
			return 'Not a product.';
		}
		if ( $url === '' || $host === '' || strpos( $host, '.' ) === false ) {
			return 'That is not a web address (it must start with https://).';
		}
		if ( UrlFinder::not_product_why( $url ) === 'account' ) {
			return 'That is a login, basket, account or registration page, not a product’s page.'; // ikmultimedia.com/registration/ (2026-10-27)
		}
		$old = (string) get_post_meta( $id, 'vendor_product_url', true );
		$wpdb->replace(
			UrlFinder::table(),
			[ 'product_id' => $id, 'url' => mb_substr( $url, 0, 500 ), 'host' => $host, 'score' => 100, 'status' => 'approved', 'reason' => 'manual', 'note' => mb_substr( 'set by a person' . ( $old !== '' && ! UrlFinder::same_url( $old, $url ) ? '; was ' . $old : '' ), 0, 255 ), 'vendor_name' => '', 'image' => '', 'found_at' => current_time( 'mysql' ) ]
		);
		UrlFinder::adopt( $id, $url, true );
		return '';
	}

	/**
	 * POST /urls/{id}/none: the product has no vendor page (a bundle sold only here, a discontinued
	 * product). Its vendor URL goes; a proposal waiting is rejected; what the last read got (the
	 * result, its errors and the copy it took) is cleared, as are description and picture proposals
	 * made from that page and a read waiting in the queue. Nothing automatic proposes, adopts or reads
	 * a URL for it until a person sets or approves one (UrlFinder::NO_PAGE). The old URLs stay in the
	 * audit log and as the rejected proposal.
	 */
	public static function none_url( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		if ( get_post_type( $id ) !== 'product' ) {
			return new \WP_REST_Response( [ 'message' => 'Not a product.' ], 404 );
		}
		self::mark_none( $id );
		return rest_ensure_response( [ 'rows' => self::rows_for( [ $id ] ) ] );
	}

	/**
	 * Mark a product as having no vendor page (none_url() says what goes). $audit false: the caller records
	 * one event for a batch (BrandSites::bundles_none()). @return array{url:string,read:string,proposals_dropped:int}
	 */
	public static function mark_none( int $id, bool $audit = true ): array {
		global $wpdb;
		$old  = (string) get_post_meta( $id, 'vendor_product_url', true );
		$read = (string) ( Descriptions::get_many( [ $id ] )[ $id ]['vendor_url'] ?? '' );
		Write::field( $id, 'vendor_product_url', null, 'product-vendor-links', 'force' );
		Write::field( $id, 'vendor_product_url_verified', null, 'product-vendor-links', 'force' );
		$wpdb->update( UrlFinder::table(), [ 'status' => 'rejected', 'note' => 'the product has no vendor page (a person)' ], [ 'product_id' => $id ] );
		Descriptions::upsert( $id, [ 'vendor_url' => '', 'vendor_copy' => '', 'fetch_status' => UrlFinder::NO_PAGE, 'fetched_at' => null ] );
		UrlFinder::no_page_ids( true );
		$dropped = PageEvents::page_cleared( $id ); // proposals made from the page it no longer has
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Scraper::table() . ' WHERE product_id = %d AND done_at IS NULL', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$done = [ 'url' => $old, 'read' => $read, 'proposals_dropped' => $dropped ];
		if ( $audit && class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.none', [ 'object_type' => 'product', 'object_id' => $id, 'details' => $done ] );
		}
		return $done;
	}

	/**
	 * POST /urls/none-many {ids}: "the maker doesn't publish it" for several products at once (the Brands &
	 * sites drawer's selection), as none_url() does for one. A product whose verified page is read fine on a
	 * site that isn't saved as a bad one is kept: that page is taken away one row at a time (owner 2026-10-08,
	 * bulk actions never destroy verified data). One audit event lists every product with what it had.
	 */
	public static function none_many( \WP_REST_Request $r ): \WP_REST_Response {
		$ids  = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ) ) ) ), 0, 200 );
		$desc = Descriptions::get_many( $ids );
		$done = [];
		$kept = [];
		foreach ( $ids as $id ) {
			if ( get_post_type( $id ) !== 'product' || UrlFinder::is_no_page( $id ) ) {
				continue;
			}
			$url = (string) get_post_meta( $id, 'vendor_product_url', true );
			$v   = (string) get_post_meta( $id, 'vendor_product_url_verified', true );
			$res = PageReview::result_of( (string) ( $desc[ $id ]['fetch_status'] ?? '' ), (string) ( $desc[ $id ]['vendor_url'] ?? '' ), $url, $v );
			if ( str_starts_with( $url, 'http' ) && UrlFinder::is_verified( $v ) && in_array( $res, [ 'read', 'manual', 'no_copy' ], true ) && ! BrandSites::on_bad_site( $id, $url ) ) {
				$kept[] = $id;
				continue;
			}
			$done[ $id ] = self::mark_none( $id, false );
		}
		if ( ! $done && ! $kept ) {
			return new \WP_REST_Response( [ 'message' => 'Nothing to do.' ], 400 );
		}
		if ( $done && class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.none', [ 'object_type' => 'product', 'details' => [ 'count' => count( $done ), 'products' => $done, 'kept' => $kept ] ] ); // every id with its URL, so it can be undone
		}
		return rest_ensure_response( [ 'done' => array_keys( $done ), 'kept' => $kept ] );
	}

	/** POST /urls/look-many {ids}: look_url() for several products at once; products not marked are left alone. */
	public static function look_many( \WP_REST_Request $r ): \WP_REST_Response {
		$ids  = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ) ) ) ), 0, 200 );
		$done = array_values( array_filter( $ids, [ __CLASS__, 'look_one' ] ) );
		if ( ! $done ) {
			return new \WP_REST_Response( [ 'message' => 'None of these products is marked as having no vendor page.' ], 400 );
		}
		UrlFinder::no_page_ids( true );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.look', [ 'object_type' => 'product', 'details' => [ 'count' => count( $done ), 'ids' => $done ] ] );
		}
		return rest_ensure_response( [ 'done' => $done ] );
	}

	/** POST /urls/{id}/look: undo "no vendor page"; the finder may propose pages again. */
	public static function look_url( \WP_REST_Request $r ): \WP_REST_Response {
		$id = (int) $r['id'];
		if ( ! self::look_one( $id ) ) {
			return new \WP_REST_Response( [ 'message' => 'This product isn’t marked as having no vendor page.' ], 400 );
		}
		UrlFinder::no_page_ids( true );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.look', [ 'object_type' => 'product', 'object_id' => $id ] );
		}
		return rest_ensure_response( [ 'rows' => self::rows_for( [ $id ] ) ] );
	}

	/** Undo "no vendor page" for one product (false when it isn't marked); the caller refreshes the no-page list. */
	private static function look_one( int $id ): bool {
		global $wpdb;
		if ( ! UrlFinder::is_no_page( $id ) ) {
			return false;
		}
		Descriptions::upsert( $id, [ 'fetch_status' => '' ] );
		$wpdb->delete( UrlFinder::table(), [ 'product_id' => $id, 'status' => 'rejected' ] ); // the finder only looks where no decision stands
		return true;
	}

	/**
	 * POST /urls/{id}/clear: a wrong vendor URL leaves the product (unverified, so the
	 * finder looks again); the same URL is kept as rejected so it isn't proposed again.
	 */
	public static function clear_url( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;
		$id  = (int) $r['id'];
		$old = (string) get_post_meta( $id, 'vendor_product_url', true );
		if ( get_post_type( $id ) !== 'product' ) {
			return new \WP_REST_Response( [ 'message' => 'Not a product.' ], 404 );
		}
		if ( $old === '' ) {
			return new \WP_REST_Response( [ 'message' => 'This product has no vendor URL.' ], 400 );
		}
		Write::field( $id, 'vendor_product_url', null, 'product-vendor-links', 'force' );
		Write::field( $id, 'vendor_product_url_verified', null, 'product-vendor-links', 'force' );
		$wpdb->replace(
			UrlFinder::table(),
			[ 'product_id' => $id, 'url' => mb_substr( $old, 0, 500 ), 'host' => strtolower( (string) wp_parse_url( $old, PHP_URL_HOST ) ), 'score' => 0, 'status' => 'rejected', 'reason' => 'manual', 'note' => 'cleared by a person: wrong page', 'vendor_name' => '', 'image' => '', 'found_at' => current_time( 'mysql' ) ]
		);
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.clear', [ 'object_type' => 'product', 'object_id' => $id, 'details' => [ 'host' => strtolower( (string) wp_parse_url( $old, PHP_URL_HOST ) ) ] ] );
		}
		return rest_ensure_response( [ 'rows' => self::rows_for( [ $id ] ) ] );
	}

	private static function row( array $r ): array {
		$id = (int) $r['product_id'];
		return [
			'id'           => $id,
			'title'        => html_entity_decode( (string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'view_url'     => get_permalink( $id ),
			'edit_url'     => get_edit_post_link( $id, 'raw' ),
			'current'      => (string) $r['stored_url'],
			'verified'     => UrlFinder::is_verified( $r['verified'] ),
			'proposed'     => (string) $r['url'],
			'host'         => (string) $r['host'],
			'page_title'   => (string) $r['page_title'],
			'page_status'  => (string) $r['page_status'],
			'reason'       => (string) $r['reason'],
			'reason_label' => self::REASONS[ $r['reason'] ] ?? (string) $r['reason'],
			'score'        => (int) $r['score'],
			'note'         => (string) $r['note'],
			'vendor_name'  => (string) $r['vendor_name'],
			'image'        => (string) $r['image'],
			'status'       => (string) $r['status'],
		];
	}

	/**
	 * POST /urls/decide {action: approve|reject|reset, ids: []}
	 * approve → approved + adopted as the product's vendor URL; reject → rejected
	 * (the stored URL stays); reset → back in the queue (an adopted URL stays).
	 */
	public static function decide( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;
		$action = (string) $r->get_param( 'action' );
		$ids    = array_values( array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ) ) );
		$to     = [ 'approve' => 'approved', 'reject' => 'rejected', 'reset' => 'candidate' ][ $action ] ?? '';
		if ( $to === '' || ! $ids ) {
			return new \WP_REST_Response( [ 'message' => 'Nothing to do.' ], 400 );
		}
		$t       = UrlFinder::table();
		$done    = 0;
		$skipped = [];
		$adopted = [];
		$offsite  = []; // skipped because the page moved to a site nobody has confirmed: host → count
		$replaced = []; // product id → the verified URL an approval replaced, so a bulk decision can be undone
		foreach ( array_slice( $ids, 0, 500 ) as $id ) {
			$url = (string) $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$t} WHERE product_id = %d", $id ) );
			if ( $url === '' ) {
				continue;
			}
			// Bulk approve never replaces a verified URL with a different page; that is decided one row at a time
			// (owner 2026-10-08: 1,000 bulk approvals replaced 69 working URLs with XChange family/listing pages).
			// Except when the verified URL itself now redirects to exactly this page: it no longer shows its own
			// page, so nothing is lost — on its own site, or on one a person confirmed as the brand's. And
			// except a move off a site the brand saved as a reseller, a store or not its own, onto a site a
			// person confirmed as the brand's own (owner 2026-10-14, a brand's pages on a distributor's site).
			$stored = (string) get_post_meta( $id, 'vendor_product_url', true );
			if ( $to === 'approved' && str_starts_with( $stored, 'http' ) && UrlFinder::is_verified( get_post_meta( $id, 'vendor_product_url_verified', true ) ) && ! UrlFinder::same_url( $stored, $url ) ) {
				$why = count( $ids ) > 1 ? self::replace_refused( $id, $stored, $url ) : '';
				if ( $why !== '' ) {
					$skipped[] = $id;
					if ( $why === 'moved_offsite' ) {
						$h             = BrandSites::host( $url );
						$offsite[ $h ] = ( $offsite[ $h ] ?? 0 ) + 1;
					}
					continue;
				}
				$replaced[ $id ] = $stored;
			}
			$wpdb->update( $t, [ 'status' => $to ], [ 'product_id' => $id ] );
			if ( $to === 'approved' ) {
				UrlFinder::adopt( $id, $url, true );
				$adopted[] = $id;
			}
			$done++;
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.' . $action, [ 'object_type' => 'product', 'details' => [ 'count' => $done, 'skipped' => count( $skipped ), 'ids' => array_values( array_diff( array_slice( $ids, 0, 500 ), $skipped ) ), 'replaced' => $replaced ] ] ); // every id, so a bulk decision can be undone
		}
		return rest_ensure_response( [ 'done' => $done, 'skipped' => $skipped, 'offsite' => (object) $offsite, 'status' => $to, 'rows' => self::rows_for( array_slice( $ids, 0, 500 ) ), 'queue' => $adopted ? self::scrape_now( $adopted ) : null ] );
	}

	/**
	 * Why a bulk approve may not replace the product's verified URL $stored with $url ('' = it may):
	 * 'moved_offsite' when the stored page moved to a site nobody confirmed, 'verified' otherwise. It may
	 * when the stored page now redirects to exactly $url (on its own site, or on one a person confirmed as
	 * the brand's), or when it moves off a site the brand saved as a reseller, a store or not its own onto
	 * a site a person confirmed as the brand's own, or when the ratings say the verified page is wrong and the proposal
	 * right (REPLACE; never onto another site nobody confirmed), or when the proposal is a page of its own for a product
	 * that only has a listing's thin card (own_over_card()).
	 */
	public static function replace_refused( int $id, string $stored, string $url ): string {
		$moved = self::moved_to( $stored, $url );
		if ( $moved === 'moved' || ( $moved === 'moved_offsite' && BrandSites::confirmed_for( $id, $url ) ) ) {
			return '';
		}
		if ( BrandSites::on_bad_site( $id, $stored ) && BrandSites::confirmed_for( $id, $url ) ) {
			return '';
		}
		if ( $moved !== 'moved_offsite' && ( PageStore::same_site( $stored, $url ) || BrandSites::confirmed_for( $id, $url ) ) && ( self::outrated( $id ) || self::own_over_card( $id, $stored, $url ) ) ) {
			return '';
		}
		return $moved === 'moved_offsite' ? 'moved_offsite' : 'verified';
	}

	/**
	 * A page of its own, rated REPLACE['proposal'] or more, for a product that reads only a card with a line or two on a page
	 * listing several. Nothing is lost, so a verified listing doesn't hold it back. The rule against bulk replacing was written
	 * for the opposite move, onto a listing (2026-10-08). Owner 2026-11-05: 21 moforte.com products selected, 0 used, since a
	 * card naming a product rates 70. A card with a real description stays protected.
	 */
	private static function own_over_card( int $id, string $stored, string $url ): bool {
		if ( SharedPages::relation( $id, $stored )['kind'] !== 'several' || isset( SharedPages::shared_urls()[ $url ] ) ) {
			return false;
		}
		$proposal = UrlReview::rates( [ $id ], 5.0 )['n'][ $id ] ?? null;
		return $proposal !== null && $proposal >= self::REPLACE['proposal'] && mb_strlen( wp_strip_all_tags( SharedPages::description_for( $id, $stored ) ) ) < ProductPage::THIN_TEXT;
	}

	/** The proposal rates REPLACE['proposal'] or more and the product's verified page under REPLACE['current']. */
	private static function outrated( int $id ): bool {
		$proposal = UrlReview::rates( [ $id ], 5.0 )['n'][ $id ] ?? null; // a waiting proposal's rating (stored, else worked out)
		$current  = UrlReview::current( [ $id ] )[ $id ] ?? null;
		return $proposal !== null && $proposal >= self::REPLACE['proposal']
			&& $current && $current['confidence'] && $current['confidence']['n'] < self::REPLACE['current'];
	}

	/** Does the stored URL now redirect to exactly $url? Its read's status ('moved' or 'moved_offsite'), else ''. */
	private static function moved_to( string $stored, string $url ): string {
		$page = PageStore::row( $stored );
		return $page && in_array( (string) $page['status'], [ 'moved', 'moved_offsite' ], true ) && UrlFinder::same_url( (string) $page['final_url'], $url ) ? (string) $page['status'] : '';
	}

	/**
	 * Approve these products' proposed pages: each becomes the product's vendor URL and is scraped now,
	 * as Approve does (BrandSites::move()). No guard: the caller is a person's decision. @return int[] done
	 */
	public static function approve( array $ids ): array {
		global $wpdb;
		$t    = UrlFinder::table();
		$done = [];
		foreach ( array_values( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$url = (string) $wpdb->get_var( $wpdb->prepare( "SELECT url FROM {$t} WHERE product_id = %d AND status = 'candidate'", $id ) );
			if ( $url !== '' ) {
				$wpdb->update( $t, [ 'status' => 'approved' ], [ 'product_id' => $id ] );
				UrlFinder::adopt( $id, $url, true );
				$done[] = $id;
			}
		}
		if ( $done ) {
			self::scrape_now( $done );
		}
		return $done;
	}

	/**
	 * A URL a person just made the product's page is read and scraped at once (owner 2026-10-11:
	 * "setting a URL doesn't trigger anything"): its description and picture proposals follow, and
	 * the sources approved under Sources and writing write as they always do. The queue's status.
	 */
	private static function scrape_now( array $ids ): array {
		Scraper::enqueue( $ids, 'scrape', true, 'manual' ); // the picture runner waits while these are read (Activity)
		Scraper::kick();
		return Scraper::status();
	}

	/** POST /urls/{id}/check — fetch the proposed page (page store, throttled) and return what it calls itself. */
	public static function check( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;
		$id  = (int) $r['id'];
		$url = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM ' . UrlFinder::table() . ' WHERE product_id = %d', $id ) );
		if ( $url === '' ) {
			return new \WP_REST_Response( [ 'message' => 'No URL for this product.' ], 404 );
		}
		$page  = PageStore::get( $url );
		$title = html_entity_decode( (string) get_post_field( 'post_title', $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$brand = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
		return rest_ensure_response(
			[
				'page_status'  => $page['status'],
				'page_title'   => $page['title'],
				'names_product' => $page['status'] === 'ok' && UrlFinder::page_names_product( $page['title'], $title, is_array( $brand ) ? (string) ( $brand[0] ?? '' ) : '' ),
				'row'           => self::rows_for( [ $id ] )[0] ?? null, // redrawn with what the page can fill
			]
		);
	}
}
