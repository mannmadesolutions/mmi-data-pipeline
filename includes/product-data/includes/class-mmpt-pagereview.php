<?php
/**
 * Vendor pages (Product Content → "Vendor pages" tab): one row per product
 * with a vendor URL or a proposed one. Owner 2026-10-08: "the Vendor URLs and
 * Vendor Pages tabs are the same thing, really" — so this is both:
 *
 * - the URL decision: the proposed URL a source holds for a person (UrlFinder
 *   candidates, reason, score), approved / rejected / edited here through
 *   UrlQueue's routes. "Needs a URL decision" is the old Vendor URLs queue;
 * - the page: what reading it got (Descriptions.fetch_status), when, how much
 *   copy was kept, the content icons (Coverage: colour = what the product has,
 *   dot = what the page offers — the proposed page while a decision waits, else
 *   the vendor page), the product's description and picture proposals, and
 *   Read / Scrape — at once for one row, or through the scrape queue (Scraper)
 *   for selected rows or every row the filter shows.
 *
 * Products left out of the module (Exclusions: Rules tab and no longer sold)
 * aren't listed.
 *
 * The product list, the product screen and the brand screens show the same rows and
 * actions (Screens, 2026-10-11); a brand screen links here filtered to its brand.
 *
 * Vendor maps are the site-level half of the same process (2026-10-09): each
 * row says whether its page was read with its site's confirmed map, a list
 * filtered to one site carries that site's map in a line, and the map's
 * detail view lists the site's products with these same rows (rows_for()).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageReview {

	const PER_PAGE = 50;

	/** Result group → [label, badge]. The group comes from fetch_status (result_sql()). */
	const RESULTS = [
		'read'     => [ 'Read', 'success' ],
		'changed'  => [ 'URL changed, not read yet', 'info' ],
		'not_read' => [ 'Not read yet', 'info' ],
		'no_copy'  => [ 'Read, no usable copy', 'warning' ],
		'refused'  => [ 'Refused by the site', 'error' ],
		'robots'   => [ 'Blocked by robots.txt', 'error' ],
		'gone'     => [ 'Page gone', 'error' ],
		'error'    => [ 'Could not load', 'error' ],
		'moved'    => [ 'Moved: decide on its new address', 'warning' ],
		'offsite'  => [ 'Redirects to another site', 'error' ],
		'loop'     => [ 'Redirect loop', 'error' ],
		'manual'   => [ 'Copy entered by a person', 'success' ],
		'no_page'  => [ 'No vendor page', '' ],
	];

	/**
	 * Result filter only, not a group of its own: read (ok / no copy) but no stored copy of the page, because it
	 * was read before the page store kept copies (2026-10-06 and earlier). Maps, coverage and scraping can't see
	 * these; the nightly read stores VendorFetcher::NEVER_STORED_PER_NIGHT of them, Read stores the ones ticked.
	 */
	const NOT_STORED = [ 'not_stored', 'Read, page not stored' ];

	/** URL decision filter → candidate status. */
	const DECISIONS = [ 'open' => 'candidate', 'approved' => 'approved', 'rejected' => 'rejected' ];

	/** Sortable columns → SQL (whitelist). `coverage` is sorted in PHP (Coverage::scores()). */
	const ORDER = [
		'id'        => 'p.ID',
		'product'   => 'p.post_title',
		'url'       => 'u.meta_value',
		'proposed'  => 'c.score',
		'reason'    => 'c.reason', // the review list groups by why a page was proposed
		'result'    => 'result',
		'read_at'   => 'd.fetched_at',
		'proposals' => 'proposals',
		'coverage'  => '',
	];

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( Module::CAP );
		register_rest_route( Module::NS, '/pages', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'list' ] ] );
		register_rest_route( Module::NS, '/pages/queue', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( Scraper::status() ) ] );
		register_rest_route( Module::NS, '/pages/queue', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'queue' ] ] );
		register_rest_route(
			Module::NS,
			'/pages/queue/stop',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static fn() => rest_ensure_response( [ 'removed' => Scraper::clear_pending(), 'status' => Scraper::status() ] ),
			]
		);
		register_rest_route(
			Module::NS,
			'/pages/(?P<id>\d+)/read',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ],
				'callback'            => [ __CLASS__, 'read' ],
			]
		);
	}

	/* ── SQL pieces ─────────────────────────────────────────────────────── */

	/** The URL reads use: what was read last, else the product's stored vendor URL. */
	private static function url_sql(): string {
		return "COALESCE( NULLIF( d.vendor_url, '' ), u.meta_value )";
	}

	/**
	 * The page the content dots describe: the proposed one while a decision waits, else the product's
	 * vendor URL as it is now (not the last page read: after a URL change that is the old page).
	 */
	private static function offer_url_sql(): string {
		return "CASE WHEN c.status = 'candidate' AND c.url <> '' THEN c.url WHEN LEFT( u.meta_value, 4 ) = 'http' THEN u.meta_value ELSE " . self::url_sql() . ' END';
	}

	/** fetch_status → result group (see RESULTS). result_of() is the same in PHP: keep the two in step. */
	private static function result_sql(): string {
		return "CASE
			WHEN d.fetch_status = 'no_page' THEN 'no_page'
			WHEN d.fetch_status = 'manual' THEN 'manual'
			WHEN COALESCE( d.fetch_status, '' ) = '' THEN 'not_read'
			WHEN COALESCE( d.vendor_url, '' ) <> '' AND LEFT( u.meta_value, 4 ) = 'http' AND UPPER( v.meta_value ) IN ('TRUE','1') AND d.vendor_url <> u.meta_value THEN 'changed'
			WHEN d.fetch_status = 'ok' THEN 'read'
			WHEN d.fetch_status = 'no_copy' THEN 'no_copy'
			WHEN d.fetch_status = 'robots_disallow' THEN 'robots'
			WHEN d.fetch_status IN ('http_404','http_410') THEN 'gone'
			WHEN d.fetch_status IN ('http_401','http_403','http_405','http_429','http_202','http_503') THEN 'refused'
			WHEN d.fetch_status = 'moved' THEN 'moved'
			WHEN d.fetch_status = 'moved_offsite' THEN 'offsite'
			WHEN d.fetch_status = 'redirect_loop' THEN 'loop'
			ELSE 'error' END";
	}

	/** NOT_STORED as SQL on from(): the page it read has no row in the page store. */
	private static function not_stored_sql(): string {
		return "d.fetch_status IN ('ok','no_copy') AND NOT EXISTS ( SELECT 1 FROM " . PageStore::table() . ' nsp WHERE nsp.url_hash = MD5( d.vendor_url ) )';
	}

	/** The vendor site of the row (the proposed URL's while a decision waits), without "www.". */
	private static function host_sql(): string {
		return "TRIM( LEADING 'www.' FROM SUBSTRING_INDEX( SUBSTRING_INDEX( SUBSTRING_INDEX( " . self::offer_url_sql() . ", '://', -1 ), '/', 1 ), '?', 1 ) )";
	}

	/**
	 * FROM … WHERE shared by the list, the counts and "all shown". $pages adds the page-store join
	 * the row columns need; counting, filtering and sorting don't (it tripled their time).
	 */
	private static function from( bool $pages = false, bool $any = false, string $join = '' ): string {
		global $wpdb;
		$d  = Descriptions::table();
		$c  = UrlFinder::table();
		$pg = PageStore::table();
		return "FROM {$wpdb->posts} p{$join}
			LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
			LEFT JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified'
			LEFT JOIN {$d} d ON d.product_id = p.ID
			LEFT JOIN {$c} c ON c.product_id = p.ID"
			. ( $pages ? " LEFT JOIN {$pg} pg ON pg.url_hash = MD5( " . self::offer_url_sql() . ' )' : '' ) . "
			WHERE p.post_type = 'product' AND p.post_status = 'publish'"
			. ( $any ? '' : " AND ( ( LEFT( u.meta_value, 4 ) = 'http' AND UPPER( v.meta_value ) IN ('TRUE','1') ) OR COALESCE( d.vendor_url, '' ) <> '' OR d.fetch_status IN ('manual','no_page') OR c.status = 'candidate' )" )
			. Exclusions::sql_not_excluded( 'p.ID' );
	}

	/** Columns every row needs. */
	private static function columns(): string {
		return 'p.ID id, p.post_title title, ' . self::url_sql() . ' url, u.meta_value stored_url, v.meta_value verified, ( ' . self::result_sql() . " ) result,
			d.fetch_status, d.fetched_at, CHAR_LENGTH( COALESCE( d.vendor_copy, '' ) ) copy_chars,
			c.url cand_url, c.status cand_status, c.reason, c.score, c.note, c.vendor_name,
			" . self::offer_url_sql() . ' offer_url, pg.status page_status, pg.html_file page_file, pg.content_hash page_hash, pg.title page_title, pg.method page_method';
	}

	/**
	 * Filters as SQL appended to from().
	 * @return array{0:string,1:array}
	 */
	private static function filters( \WP_REST_Request $r ): array {
		global $wpdb;
		$where    = '';
		$args     = [];
		$decision = sanitize_key( (string) $r->get_param( 'decision' ) );
		if ( isset( self::DECISIONS[ $decision ] ) ) {
			$where .= ' AND c.status = %s';
			$args[] = self::DECISIONS[ $decision ];
		}
		$result = sanitize_key( (string) $r->get_param( 'result' ) );
		if ( isset( self::RESULTS[ $result ] ) ) {
			$where .= ' AND ( ' . self::result_sql() . ' ) = %s';
			$args[] = $result;
		} elseif ( $result === self::NOT_STORED[0] ) {
			$where .= ' AND ' . self::not_stored_sql();
		}
		$reason = sanitize_key( (string) $r->get_param( 'reason' ) );
		if ( $reason !== '' ) {
			$where .= ' AND c.reason = %s';
			$args[] = $reason;
		}
		$min = max( 0, min( 100, (int) $r->get_param( 'min_score' ) ) );
		if ( $min > 0 ) {
			$where .= ' AND c.score >= %d';
			$args[] = $min;
		}
		$verified = UrlQueue::VERIFIED[ (string) $r->get_param( 'verified' ) ] ?? '';
		if ( $verified !== '' ) {
			$where .= " AND {$verified}";
		}
		$host = preg_replace( '/^www\./', '', strtolower( sanitize_text_field( (string) $r->get_param( 'host' ) ) ) );
		if ( $host !== '' ) {
			$where .= ' AND ' . self::host_sql() . ' = %s';
			$args[] = $host;
		}
		$search = trim( sanitize_text_field( (string) $r->get_param( 'search' ) ) );
		if ( $search !== '' ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			if ( ctype_digit( $search ) ) {
				$where .= ' AND p.ID = %d'; // an ID finds that product
				$args[] = (int) $search;
			} else {
				// Matching IDs first, four quick lookups: one OR across the joined tables took ~3 s.
				$found = array_merge(
					$wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE %s", $like ) ),
					$wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'vendor_product_url' AND meta_value LIKE %s", $like ) ),
					$wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM ' . Descriptions::table() . ' WHERE vendor_url LIKE %s', $like ) ),
					$wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM ' . UrlFinder::table() . ' WHERE url LIKE %s', $like ) )
				);
				$found  = array_unique( array_map( 'intval', $found ) );
				$where .= $found ? ' AND p.ID IN (' . implode( ',', $found ) . ')' : ' AND 0 = 1';
			}
		}
		// What the picture runner did (Pictures ledger), and open pictures picks.
		$pics = [
			'vendor'   => "IN ( SELECT product_id FROM %L WHERE status = 'applied' AND mode = 'vendor' )",
			'in_place' => "IN ( SELECT product_id FROM %L WHERE status = 'applied' AND mode = 'in_place' )",
			'waiting'  => "IN ( SELECT product_id FROM %L WHERE status IN ('queued','working','revert') )",
			'skipped'  => "IN ( SELECT product_id FROM %L WHERE status IN ('skipped','error','reverted') )",
			'none'     => 'NOT IN ( SELECT product_id FROM %L )',
			'pick'     => 'IN ( SELECT product_id FROM %E WHERE kind = \'image\' AND status IN (\'proposed\',\'accepted\') )',
		];
		$pf = sanitize_key( (string) $r->get_param( 'pictures' ) );
		if ( isset( $pics[ $pf ] ) ) {
			$where .= ' AND p.ID ' . strtr( $pics[ $pf ], [ '%L' => Pictures::table(), '%E' => Enrichment::table() ] );
		}
		$df = sanitize_key( (string) $r->get_param( 'description' ) );
		if ( in_array( $df, [ 'proposed', 'applied' ], true ) ) {
			$where .= ' AND p.ID IN ( SELECT product_id FROM ' . Enrichment::table() . " WHERE kind = 'content' AND status " . ( $df === 'proposed' ? "IN ('proposed','accepted')" : "= 'applied'" ) . ' )';
		}
		// One brand's products (the Brand - site filter, and the brand screens' links: Screens), or products with no brand.
		$brand  = (string) $r->get_param( 'brand' );
		$branded = "SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand'";
		if ( $brand === 'none' ) {
			$where .= " AND p.ID NOT IN ( {$branded} )";
		} elseif ( (int) $brand > 0 ) {
			$where .= " AND p.ID IN ( {$branded} WHERE tt.term_id = %d )";
			$args[] = (int) $brand;
		}
		$ids = array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ) );
		if ( $ids ) {
			$where .= ' AND p.ID IN (' . implode( ',', $ids ) . ')';
		}
		return [ $where, $args ];
	}

	/* ── List ───────────────────────────────────────────────────────────── */

	/**
	 * GET /pages?decision=open|approved|rejected&result=&reason=&min_score=&verified=yes|no|none&host=&brand=<term id>|none&search=&ids[]=
	 *   &orderby=&order=&page=&per_page=&facets=1 (filter counts: first load only, they cost ~1 s)
	 */
	public static function list( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;
		Coverage::maybe_install();
		PageStore::maybe_install();
		UrlFinder::maybe_install();
		$per_page          = min( 200, max( 10, (int) ( $r->get_param( 'per_page' ) ?: self::PER_PAGE ) ) );
		$page              = max( 1, (int) $r->get_param( 'page' ) );
		$key               = (string) $r->get_param( 'orderby' );
		$orderby           = array_key_exists( $key, self::ORDER ) ? $key : 'id';
		$order             = strtolower( (string) $r->get_param( 'order' ) ) === 'asc' ? 'ASC' : 'DESC';
		$from              = self::from();
		[ $where, $args ]  = self::filters( $r );
		$prep              = static fn( string $sql ) => $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL
		// audit-ok: $from is self::from(); $where is filters(): %-placeholders run through prepare
		$total             = (int) $wpdb->get_var( $prep( "SELECT COUNT(*) {$from}{$where}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		if ( $orderby === 'coverage' ) {
			// Most missing first: every matching product scored (cached 15 min), then one page read.
			// audit-ok: $from is self::from(); $where is filters(): %-placeholders run through prepare
			$all    = array_map( 'intval', $wpdb->get_col( $prep( "SELECT p.ID {$from}{$where}" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			$scores = Coverage::scores( $all );
			usort( $all, static fn( $a, $b ) => ( $order === 'ASC' ? $scores[ $a ] <=> $scores[ $b ] : $scores[ $b ] <=> $scores[ $a ] ) ?: $b <=> $a );
			$ids  = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
			$rows = self::rows_by_ids( $ids );
		} else {
			$sort = $orderby === 'proposals'
				? '( SELECT GROUP_CONCAT( CONCAT( e.kind, ":", e.status ) ORDER BY e.kind SEPARATOR " " ) FROM ' . Enrichment::table() . ' e WHERE e.product_id = p.ID )'
				: self::ORDER[ $orderby ];
			$ids  = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT p.ID, ( ' . self::result_sql() . " ) result {$from}{$where} ORDER BY {$sort} {$order}, p.ID DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
					array_merge( $args, [ $per_page, ( $page - 1 ) * $per_page ] )
				)
			);
			$rows = self::rows_by_ids( $ids );
		}

		// A product ID typed in the search box finds that product even with no URL at all, so any
		// product's vendor URL can be set here (owner 2026-10-07: "manage the URLs if they are wrong").
		$search = trim( (string) $r->get_param( 'search' ) );
		if ( ! $rows && ctype_digit( $search ) && get_post_type( (int) $search ) === 'product' ) {
			$rows  = self::rows_by_ids( [ (int) $search ], true );
			$total = count( $rows );
		}
		$out = [
			'rows'  => self::out( $rows ),
			'total' => $total,
			'pages' => max( 1, (int) ceil( $total / $per_page ) ),
			'queue' => Scraper::status(),
			// audit-ok: $from is self::from(): table names, fixed SQL and Exclusions' int id list
			'open'  => (int) $wpdb->get_var( "SELECT COUNT(*) {$from} AND c.status = 'candidate'" ), // phpcs:ignore WordPress.DB.PreparedSQL -- the tab label's count
		];
		if ( $r->get_param( 'facets' ) ) {
			$out += self::facets( $from );
		}
		// Filtered to one vendor site: that site's map in a line (Vendor maps → its detail).
		$host = (string) $r->get_param( 'host' );
		if ( $host !== '' ) {
			$out['site'] = VendorMaps::summary( $host ) ?? [ 'host' => VendorMaps::host( $host ), 'status' => 'none' ];
		}
		return rest_ensure_response( $out );
	}

	/** Counts for the filters, over the whole list. */
	private static function facets( string $from ): array {
		global $wpdb;
		$res = self::result_sql();
		$n   = [];
		// audit-ok: $res is the fixed result_sql() CASE; $from is self::from() (tables, ints)
		foreach ( $wpdb->get_results( "SELECT ( {$res} ) k, COUNT(*) n {$from} GROUP BY k", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$n[ $x['k'] ] = (int) $x['n'];
		}
		$results = [];
		foreach ( self::RESULTS as $key => [ $label ] ) {
			$results[] = [ 'key' => $key, 'label' => $label, 'n' => $n[ $key ] ?? 0 ];
		}
		// audit-ok: $from is self::from() and not_stored_sql() is fixed SQL with table names
		$results[] = [ 'key' => self::NOT_STORED[0], 'label' => self::NOT_STORED[1], 'n' => (int) $wpdb->get_var( "SELECT COUNT(*) {$from} AND " . self::not_stored_sql() ) ]; // phpcs:ignore WordPress.DB.PreparedSQL
		$dec = [];
		// audit-ok: $from is self::from(): table names, fixed SQL and Exclusions' int id list
		foreach ( $wpdb->get_results( "SELECT c.status k, COUNT(*) n {$from} AND c.status IS NOT NULL GROUP BY k", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$dec[ $x['k'] ] = (int) $x['n'];
		}
		$reasons = [];
		$open    = [];
		// audit-ok: $from is self::from(): table names, fixed SQL and Exclusions' int id list
		foreach ( $wpdb->get_results( "SELECT c.reason k, COUNT(*) n {$from} AND c.status = 'candidate' GROUP BY k", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$open[ $x['k'] ] = (int) $x['n'];
		}
		foreach ( UrlQueue::REASONS as $key => $label ) {
			$reasons[] = [ 'key' => $key, 'label' => $label, 'n' => $open[ $key ] ?? 0 ];
		}
		return [
			'all'       => array_sum( $n ),
			'results'   => $results,
			'decisions' => array_map( static fn( $k ) => [ 'key' => $k, 'n' => $dec[ self::DECISIONS[ $k ] ] ?? 0 ], array_keys( self::DECISIONS ) ),
			'reasons'   => $reasons,
			'sites'     => self::site_facets(),
		];
	}

	/**
	 * The Brand - site filter (owner 2026-10-11: the brand first, its web address after): one option per
	 * brand and site its products' pages are on, sorted by brand, with "all sites" first for a brand on
	 * several. Brands and sites don't pair one to one: resellers carry many brands (bestservice.com 8),
	 * and 49 brands have pages on more than one site. Products with no brand are listed as brand `none`.
	 * @return array<int,array{brand:string,name:string,host:string,n:int}> host '' = all the brand's sites
	 */
	private static function site_facets(): array {
		global $wpdb;
		// One row per product, brands looked up apart: a brand join in from() made the first load 5 s.
		// `site`, not `host`: the URL table has a `host` column and GROUP BY took it over the alias.
		$sites = [];
		foreach ( $wpdb->get_results( 'SELECT p.ID id, ' . self::host_sql() . ' site ' . self::from(), ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
			if ( (string) $x['site'] !== '' ) {
				$sites[ (int) $x['id'] ] = (string) $x['site'];
			}
		}
		$brands = [];
		$names  = [];
		foreach ( $wpdb->get_results( "SELECT tr.object_id id, t.term_id, t.name FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id", ARRAY_A ) as $x ) {
			if ( isset( $sites[ (int) $x['id'] ] ) ) {
				$brands[ (int) $x['id'] ][]   = (string) $x['term_id'];
				$names[ (string) $x['term_id'] ] = html_entity_decode( (string) $x['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		$rows = [];
		foreach ( $sites as $id => $site ) {
			foreach ( $brands[ $id ] ?? [ 'none' ] as $b ) {
				$rows[] = [ 'b' => $b, 'name' => $names[ $b ] ?? '', 'host' => $site ];
			}
		}
		$by = [];
		foreach ( $rows as $x ) {
			$by[ $x['b'] ]['name']                 = $x['name'];
			$by[ $x['b'] ]['hosts'][ $x['host'] ] = ( $by[ $x['b'] ]['hosts'][ $x['host'] ] ?? 0 ) + 1;
		}
		uasort( $by, static fn( $a, $b ) => ( $a['name'] === '' ) <=> ( $b['name'] === '' ) ?: strnatcasecmp( $a['name'], $b['name'] ) ); // no brand last
		$out = [];
		foreach ( $by as $id => $b ) {
			$id = (string) $id; // numeric keys come back as ints
			arsort( $b['hosts'] );
			if ( count( $b['hosts'] ) > 1 && $id !== 'none' ) {
				$out[] = [ 'brand' => (string) $id, 'name' => $b['name'], 'host' => '', 'n' => array_sum( $b['hosts'] ) ];
			}
			foreach ( $b['hosts'] as $host => $n ) {
				$out[] = [ 'brand' => (string) $id, 'name' => $b['name'], 'host' => (string) $host, 'n' => $n ];
			}
		}
		return $out;
	}

	/** Raw rows for these products, in this order. */
	private static function rows_by_ids( array $ids, bool $any = false ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$got = [];
		foreach ( $wpdb->get_results( 'SELECT ' . self::columns() . ' ' . self::from( true, $any ) . ' AND p.ID IN (' . implode( ',', $ids ) . ')', ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$got[ (int) $x['id'] ] = $x;
		}
		return array_values( array_filter( array_map( static fn( $id ) => $got[ $id ] ?? null, $ids ) ) );
	}

	/** Above this many products, one query over every product beats chunks of an IN list (measured: ~0.4 s against ~6 s for the catalog). */
	const IN_MAX = 1500;

	/**
	 * The products among $ids that pass this tab's filters in $r (result, reason, verified, pictures, description…),
	 * with or without a URL: One page's section 2 narrows its own lists with them (ONE_PAGE_COMMIT_HANDOFF.md §4.3).
	 * @return int[] in $ids' order
	 */
	public static function matching( array $ids, \WP_REST_Request $r ): array {
		global $wpdb;
		[ $where, $args ] = self::filters( $r );
		$keep             = [];
		foreach ( count( $ids ) > self::IN_MAX ? [ null ] : array_chunk( array_map( 'intval', $ids ), 1000 ) as $chunk ) {
			$sql = 'SELECT p.ID ' . self::from( false, true ) . $where . ( $chunk ? ' AND p.ID IN (' . implode( ',', $chunk ) . ')' : '' );
			// audit-ok: already batched: at most 2 chunked IN queries (IN_MAX 1500, chunks of 1000)
			foreach ( $wpdb->get_col( $args ? $wpdb->prepare( $sql, $args ) : $sql ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers and prepared filters only
				$keep[ (int) $id ] = true;
			}
		}
		return array_values( array_filter( $ids, static fn( $id ) => isset( $keep[ (int) $id ] ) ) );
	}

	/** How many of these products are in each read result (One page's result filter), keyed as RESULTS. @return array<string,int> */
	public static function result_counts( array $ids ): array {
		global $wpdb;
		$want = array_flip( array_map( 'intval', $ids ) );
		$n    = [];
		foreach ( count( $ids ) > self::IN_MAX ? [ null ] : array_chunk( array_keys( $want ), 1000 ) as $chunk ) {
			// audit-ok: already batched: at most 2 chunked IN queries (IN_MAX 1500, chunks of 1000)
			foreach ( $wpdb->get_results( 'SELECT p.ID, ( ' . self::result_sql() . ' ) ' . self::from( false, true ) . ( $chunk ? ' AND p.ID IN (' . implode( ',', $chunk ) . ')' : '' ), ARRAY_N ) as [ $id, $k ] ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
				if ( isset( $want[ (int) $id ] ) ) {
					$n[ $k ] = ( $n[ $k ] ?? 0 ) + 1;
				}
			}
		}
		return $n;
	}

	/** Table rows for these products (row redraws after an action). @return array<int,array> */
	public static function rows_for( array $ids ): array {
		return self::out( self::rows_by_ids( $ids, true ) );
	}

	/**
	 * Per brand, for the brand screens (Screens): its products, how many have a verified vendor page, what
	 * reading those got, URL decisions waiting, products with nothing, its sites and what waits in the
	 * scrape queue. Products left out of the module aren't counted. @return array<int,array<string,mixed>> by term id
	 */
	public static function brand_stats( array $term_ids ): array {
		global $wpdb;
		$term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
		if ( ! $term_ids ) {
			return [];
		}
		$in = implode( ',', $term_ids );
		// One row per product; the verified flag, exclusions and the result are worked out here: joined as
		// SQL (a second postmeta join, a long NOT IN) they made this 1.5–2.9 s for 100–150 brands.
		$rows = $wpdb->get_results(
			"SELECT btt.term_id t, p.ID id, u.meta_value url, d.fetch_status fs, d.vendor_url read_url, COALESCE( c.status, '' ) cs
			 FROM {$wpdb->term_taxonomy} btt
			 JOIN {$wpdb->term_relationships} btr ON btr.term_taxonomy_id = btt.term_taxonomy_id
			 JOIN {$wpdb->posts} p ON p.ID = btr.object_id
			 LEFT JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
			 LEFT JOIN " . Descriptions::table() . ' d ON d.product_id = p.ID
			 LEFT JOIN ' . UrlFinder::table() . " c ON c.product_id = p.ID
			 WHERE btt.taxonomy = 'product_brand' AND btt.term_id IN ({$in}) AND p.post_type = 'product' AND p.post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$flags = [];
		if ( $rows ) {
			$pids = implode( ',', array_unique( array_map( static fn( $x ) => (int) $x['id'], $rows ) ) );
			// audit-ok: $pids is implode of (int)-cast product ids
			foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'vendor_product_url_verified' AND post_id IN ({$pids})", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$flags[ (int) $x['post_id'] ] = strtoupper( (string) $x['meta_value'] ); // SQL compares it case-blind ('true' is stored too)
			}
		}
		$skip = array_flip( Exclusions::ids() );
		$out  = array_fill_keys( $term_ids, [ 'products' => 0, 'with_url' => 0, 'read' => 0, 'not_read' => 0, 'no_copy' => 0, 'moved' => 0, 'failed' => 0, 'open' => 0, 'no_url' => 0, 'no_page' => 0, 'queued' => 0, 'hosts' => [] ] );
		$keys = [ 'read' => 'read', 'not_read' => 'not_read', 'changed' => 'not_read', 'no_copy' => 'no_copy', 'moved' => 'moved', 'manual' => '' ];
		foreach ( $rows as $x ) {
			$id = (int) $x['id'];
			if ( isset( $skip[ $id ] ) ) {
				continue;
			}
			$t    = (int) $x['t'];
			$url  = (string) $x['url'];
			$flag = $flags[ $id ] ?? '';
			$res  = self::result_of( (string) $x['fs'], (string) $x['read_url'], $url, $flag );
			$has  = str_starts_with( $url, 'http' ) && UrlFinder::is_verified( $flag );
			$out[ $t ]['products']++;
			if ( $x['cs'] === 'candidate' ) {
				$out[ $t ]['open']++;
			}
			if ( $res === 'manual' ) {
				$out[ $t ]['read']++; // copy a person entered counts as read
			}
			if ( $res === UrlFinder::NO_PAGE ) {
				$out[ $t ]['no_page']++; // a person said it has none: not a missing URL
				continue;
			}
			if ( ! $has ) {
				if ( $x['cs'] !== 'candidate' && $res !== 'manual' ) {
					$out[ $t ]['no_url']++;
				}
				continue;
			}
			$out[ $t ]['with_url']++;
			$key = $keys[ $res ] ?? 'failed';
			if ( $key !== '' ) {
				$out[ $t ][ $key ]++;
			}
			$site                         = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
			$out[ $t ]['hosts'][ $site ] = ( $out[ $t ]['hosts'][ $site ] ?? 0 ) + 1;
		}
		foreach ( $out as $t => $s ) {
			arsort( $s['hosts'] );
			$top                = array_slice( $s['hosts'], 0, 3, true );
			$out[ $t ]['hosts'] = array_map( static fn( $h, $n ) => [ 'host' => (string) $h, 'n' => $n ], array_keys( $top ), array_values( $top ) );
		}
		Scraper::maybe_install();
		foreach ( $wpdb->get_results( 'SELECT btt.term_id t, COUNT(*) n FROM ' . Scraper::table() . " q JOIN {$wpdb->term_relationships} btr ON btr.object_id = q.product_id JOIN {$wpdb->term_taxonomy} btt ON btt.term_taxonomy_id = btr.term_taxonomy_id AND btt.taxonomy = 'product_brand' AND btt.term_id IN ({$in}) WHERE q.done_at IS NULL GROUP BY t", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ (int) $x['t'] ]['queued'] = (int) $x['n'];
		}
		return $out;
	}

	/** result_sql() for one product, in PHP: keep the two in step. */
	public static function result_of( string $fs, string $read_url, string $url, string $verified ): string {
		if ( $fs === 'manual' || $fs === UrlFinder::NO_PAGE ) {
			return $fs;
		}
		if ( $fs === '' ) {
			return 'not_read';
		}
		if ( $read_url !== '' && str_starts_with( $url, 'http' ) && UrlFinder::is_verified( $verified ) && $read_url !== $url ) {
			return 'changed';
		}
		$map = [ 'ok' => 'read', 'no_copy' => 'no_copy', 'robots_disallow' => 'robots', 'http_404' => 'gone', 'http_410' => 'gone', 'moved' => 'moved', 'moved_offsite' => 'offsite', 'redirect_loop' => 'loop' ];
		if ( isset( $map[ $fs ] ) ) {
			return $map[ $fs ];
		}
		return in_array( $fs, [ 'http_401', 'http_403', 'http_405', 'http_429', 'http_202', 'http_503' ], true ) ? 'refused' : 'error';
	}

	/**
	 * Of these products, the ones a scrape reads: a verified vendor URL, or a URL a person approved
	 * (Scraper::process_one()). @return int[]
	 */
	public static function readable( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$in = implode( ',', $ids );
		return array_map(
			'intval',
			$wpdb->get_col(
				"SELECT u.post_id FROM {$wpdb->postmeta} u JOIN {$wpdb->postmeta} v ON v.post_id = u.post_id AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
				 WHERE u.meta_key = 'vendor_product_url' AND LEFT( u.meta_value, 4 ) = 'http' AND u.post_id IN ({$in})
				 UNION SELECT product_id FROM " . UrlFinder::table() . " WHERE status = 'approved' AND product_id IN ({$in})" // phpcs:ignore WordPress.DB.PreparedSQL
			)
		);
	}

	/** One brand's published products the module reads. @return int[] */
	public static function brand_products( int $term_id ): array {
		global $wpdb;
		$join = " JOIN {$wpdb->term_relationships} btr ON btr.object_id = p.ID JOIN {$wpdb->term_taxonomy} btt ON btt.term_taxonomy_id = btr.term_taxonomy_id AND btt.taxonomy = 'product_brand' AND btt.term_id = " . (int) $term_id;
		return array_map( 'intval', $wpdb->get_col( 'SELECT p.ID ' . self::from( false, true, $join ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Raw rows → the table's shape: URL decision, read result, content icons, proposals, queue state. */
	private static function out( array $rows ): array {
		global $wpdb;
		if ( ! $rows ) {
			return [];
		}
		$cov = Coverage::for_rows(
			array_map(
				static fn( $r ) => [ 'product_id' => (int) $r['id'], 'url' => (string) $r['offer_url'], 'page_status' => (string) $r['page_status'], 'page_file' => (string) $r['page_file'], 'page_hash' => (string) $r['page_hash'], 'post_title' => (string) $r['title'] ],
				$rows
			)
		);
		$ids   = implode( ',', array_map( static fn( $r ) => (int) $r['id'], $rows ) );
		$props = [];
		foreach ( $wpdb->get_results( 'SELECT product_id, kind, status, source, current_value FROM ' . Enrichment::table() . " WHERE product_id IN ({$ids})", ARRAY_A ) as $e ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$props[ (int) $e['product_id'] ][ $e['kind'] ] = [ 'status' => $e['status'], 'source' => $e['source'], 'has_images' => $e['kind'] === 'image' && (string) $e['current_value'] !== '' ];
		}
		$queued = Scraper::states( array_map( static fn( $r ) => (int) $r['id'], $rows ) );
		$pics   = [];
		foreach ( $wpdb->get_results( 'SELECT product_id, status, mode, reason, pics_before, pics_after, bytes_before, bytes_after, run_at FROM ' . Pictures::table() . " WHERE product_id IN ({$ids})", ARRAY_A ) as $l ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$pics[ (int) $l['product_id'] ] = [
				'status'       => $l['status'],
				'mode'         => $l['mode'],
				'reason'       => Pictures::REASONS[ $l['reason'] ] ?? $l['reason'],
				'pics'         => (int) $l['pics_before'] . ' → ' . (int) $l['pics_after'],
				'bytes_before' => (int) $l['bytes_before'],
				'bytes_after'  => (int) $l['bytes_after'],
				'at'           => (string) $l['run_at'],
			];
		}
		$maps = VendorMaps::map_times(); // confirmed sites → when what the reader does there last changed
		$brand = []; // each product's brand, for its brand chip (Brands & sites drawer)
		foreach ( $wpdb->get_results( "SELECT tr.object_id id, t.term_id, t.name FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN ({$ids})", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$brand[ (int) $x['id'] ] = $brand[ (int) $x['id'] ] ?? [ 'id' => (int) $x['term_id'], 'name' => html_entity_decode( (string) $x['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ];
		}
		// Why the last read failed, and where a moved page lives now (the page store row of the URL read).
		$why    = [];
		$hashes = array_values( array_unique( array_map( static fn( $r ) => md5( (string) $r['url'] ), $rows ) ) );
		foreach ( $wpdb->get_results( 'SELECT url_hash, note, final_url FROM ' . PageStore::table() . " WHERE url_hash IN ('" . implode( "','", array_map( 'esc_sql', $hashes ) ) . "')", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL -- md5 hashes
			$why[ $x['url_hash'] ] = $x;
		}
		$bundles = [];
		foreach ( $wpdb->get_results( 'SELECT b.bundle_id id, SUM( m.status = \'confirmed\' ) confirmed, SUM( m.status = \'proposed\' ) proposed FROM ' . \MMI_Pipeline_Bundles::table() . ' b LEFT JOIN ' . \MMI_Pipeline_Bundles::members_table() . " m ON m.bundle_id = b.bundle_id WHERE b.bundle_id IN ({$ids}) GROUP BY b.bundle_id", ARRAY_A ) as $x ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$bundles[ (int) $x['id'] ] = [ 'confirmed' => (int) $x['confirmed'], 'proposed' => (int) $x['proposed'] ];
		}
		// Each product's picture and SKU for the row's head (product-row.js): its meta and its pictures' meta read once for the list.
		$pids = array_map( static fn( $r ) => (int) $r['id'], $rows );
		update_meta_cache( 'post', $pids );
		$thumbs = array_filter( array_map( static fn( $p ) => (int) get_post_thumbnail_id( $p ), array_combine( $pids, $pids ) ) );
		if ( $thumbs ) {
			update_meta_cache( 'post', array_values( $thumbs ) );
		}
		$out = [];
		foreach ( $rows as $r ) {
			$id    = (int) $r['id'];
			$none  = $r['result'] === UrlFinder::NO_PAGE; // a person said it has none: its old proposal and read are history, not errors
			$at    = $maps[ VendorMaps::host( (string) $r['url'] ) ] ?? '';
			$out[] = [
				'id'           => $id,
				'title'        => html_entity_decode( (string) $r['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'edit_url'     => get_edit_post_link( $id, 'raw' ),
				'view_url'     => get_permalink( $id ),
				'image'        => isset( $thumbs[ $id ] ) ? (string) wp_get_attachment_image_url( $thumbs[ $id ], 'thumbnail' ) : '',
				'sku'          => (string) get_post_meta( $id, '_sku', true ),
				'brand'        => $brand[ $id ] ?? null,
				'url'          => (string) $r['url'],
				'current'      => str_starts_with( (string) $r['stored_url'], 'http' ) ? (string) $r['stored_url'] : '',
				'verified'     => UrlFinder::is_verified( $r['verified'] ),
				'proposed'     => $none ? '' : (string) $r['cand_url'],
				'status'       => $none ? 'none' : (string) ( $r['cand_status'] ?: 'none' ), // URL decision: candidate | approved | rejected | none
				'reason'       => (string) $r['reason'],
				'reason_label' => UrlQueue::REASONS[ $r['reason'] ] ?? (string) $r['reason'],
				'score'        => (int) $r['score'],
				'note'         => (string) $r['note'],
				'vendor_name'  => (string) $r['vendor_name'],
				'page_title'   => (string) $r['page_title'],
				'page_status'  => (string) $r['page_status'],
				'page_method'  => (string) $r['page_method'],
				'offer_url'    => (string) $r['offer_url'],
				'result'       => (string) $r['result'],
				'result_label' => self::RESULTS[ $r['result'] ][0] ?? (string) $r['result'],
				'result_badge' => self::RESULTS[ $r['result'] ][1] ?? '',
				'fetch_status' => (string) $r['fetch_status'],
				'read_at'      => (string) $r['fetched_at'],
				'read_note'    => $none ? '' : (string) ( $why[ md5( (string) $r['url'] ) ]['note'] ?? '' ),
				'read_final'   => $none ? '' : (string) ( $why[ md5( (string) $r['url'] ) ]['final_url'] ?? '' ),
				'bundle'       => $bundles[ $id ] ?? null, // a bundle: its content can come from its products (Bundles tab)
				'coverage'     => $cov[ $id ] ?? null,
				'proposals'    => $props[ $id ] ?? [],
				'pictures'     => $pics[ $id ] ?? null,
				'queued'       => $queued[ $id ] ?? '',
				// The site has a confirmed vendor map: was this page last read with it ('current') or before it ('before')?
				'map'          => $at === '' || (string) $r['fetched_at'] === '' || (string) $r['url'] === '' ? '' : ( (string) $r['fetched_at'] >= $at ? 'current' : 'before' ),
			];
		}
		return $out;
	}

	/* ── Actions ────────────────────────────────────────────────────────── */

	/**
	 * POST /pages/{id}/read {mode: read|scrape} — read (and for scrape, propose and write
	 * approved rows for) one product now, fetched afresh. Returns the redrawn row.
	 */
	public static function read( \WP_REST_Request $r ): \WP_REST_Response {
		$id   = (int) $r['id'];
		$mode = (string) $r->get_param( 'mode' ) === 'scrape' ? 'scrape' : 'read';
		if ( get_post_type( $id ) !== 'product' || Exclusions::is_excluded( $id ) ) {
			return new \WP_REST_Response( [ 'message' => 'Not a product this module reads.' ], 404 );
		}
		Activity::mark_vendor_pages(); // the picture runner waits while a person works here
		$status = Scraper::process_one( $id, $mode, true ); // fetched afresh; robots.txt and per-host pacing as always
		if ( $status === 'manual' ) {
			return new \WP_REST_Response( [ 'message' => 'This product has copy a person entered; it is never read over.' ], 409 );
		}
		if ( $status === 'no_url' ) {
			return new \WP_REST_Response( [ 'message' => 'This product has no verified vendor URL to read. Approve or edit one first.' ], 400 );
		}
		Module::info( $mode === 'scrape' ? 'Vendor page scraped by hand' : 'Vendor page read by hand', [ 'product_id' => $id, 'status' => $status ] );
		return rest_ensure_response( [ 'status' => $status, 'row' => self::rows_for( [ $id ] )[0] ?? null ] );
	}

	/**
	 * POST /pages/queue {mode: read|scrape, ids: [] | all: 1 + the list's filters}
	 * Queue the selected products — or every product the filter shows — and
	 * start the runner. Pages are fetched afresh (a person asked for them now).
	 */
	public static function queue( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;
		$mode = in_array( (string) $r->get_param( 'mode' ), [ 'scrape', 'pictures' ], true ) ? (string) $r->get_param( 'mode' ) : 'read';
		if ( $r->get_param( 'all' ) ) {
			$r->set_param( 'ids', [] );
			[ $where, $args ] = self::filters( $r );
			$sql              = 'SELECT p.ID ' . self::from() . $where;
			$ids              = array_map( 'intval', $args ? $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$ids = array_slice( array_map( 'intval', (array) $r->get_param( 'ids' ) ), 0, 5000 );
		}
		if ( ! $ids ) {
			return new \WP_REST_Response( [ 'message' => 'Nothing to queue.' ], 400 );
		}
		if ( $mode === 'pictures' ) {
			$q       = PageEvents::queue_pictures( $ids, 'manual', false ); // and start the runner, as before
			$n       = $q['queued'];
			$started = $q['started'];
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'product_images.queue', [ 'object_type' => 'product', 'details' => [ 'count' => $n, 'all' => (bool) $r->get_param( 'all' ), 'ids' => array_slice( $ids, 0, 500 ) ] ] );
			}
			return rest_ensure_response( [ 'queued' => $n, 'started' => $started, 'pictures' => Pictures::summary() ] );
		}
		$n       = Scraper::enqueue( $ids, $mode, true, 'manual' ); // the picture runner waits while these are read (Activity)
		$started = Scraper::kick();
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_pages.queue', [ 'object_type' => 'product', 'details' => [ 'mode' => $mode, 'count' => $n, 'all' => (bool) $r->get_param( 'all' ), 'ids' => array_slice( $ids, 0, 500 ) ] ] );
		}
		return rest_ensure_response( [ 'queued' => $n, 'started' => $started, 'status' => Scraper::status(), 'states' => Scraper::states( array_slice( $ids, 0, 500 ) ) ] );
	}
}
