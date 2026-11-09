<?php
/**
 * One map part written to the rest of its site's products (owner 2026-11-01: "I just mapped a product description for
 * AIR Music Tech Soft Clipper, but I was only able to fill just that product's description. The scraper logic should
 * provide a summary and allow for filling the rest of the brand's products which match the layout/structure selected
 * by the user").
 *
 * plan(): each of the site's products, in slices: whether the part's picks read its stored page (the layout they were
 * made on), and if they do, what writing the part would do, decided by the editor's own preview with the editor's copy
 * of that part only (ProductPreview::plan() under VendorMaps::with_editor_parts()): fills it (none now), refreshes
 * Product Data's earlier text, the maker's words replace text that isn't theirs, for review, same as now, or kept (why).
 *
 * write(): the part is put in use on the site (VendorMaps::set_live(): the reader uses it from now on, as it would a
 * confirmed map's, so the next read doesn't put the generic reader's text back; the draft's other parts stay drafts).
 * Then the chosen products are read again with it (a Scrape; Pictures: the picture runner), each recorded by FillRun
 * with Undo, as a Fill is. Refused, as Confirm is for this part, when it finds nothing or would take something away.
 *
 * The Chrome extension commits a part to a product or a layout (layout_plan(), layout_write()). Writing a part to the
 * whole site has no screen since the drawer's Map mode went (2026-11-03): plan() and write() stay for the extension's
 * "to the whole site" commit (docs/SITE_MAPS_EXTENSION_GAPS.md).
 *
 * REST (Module::CAP): GET /maps/{host}/part?part=&offset= · POST /maps/{host}/part {part, ids}
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PartFill {

	const SLICE    = 20;   // products one plan request looks at
	const BUDGET_S = 20.0; // seconds one plan request may spend: the client asks again from where it stopped
	/** Why a product keeps its own pictures (Pictures::plan()) → the plan's action. Others: the page's are too few or too small. */
	const KEPT_WHY = [ 'vendor_done' => 'taken_once', 'person_picked' => 'person_picked', 'page_other_version' => 'other_version' ];
	/** A map part → its field in the editor's preview (ProductPreview::plan()). */
	const PLAN_FIELD = [ 'description' => 'description', 'pictures' => 'pictures', 'requirements' => 'requirements', 'manual' => 'manual', 'videos' => 'videos', 'disclaimer' => 'disclaimer' ];

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( Module::CAP );
		$args  = [ 'host' => [ 'sanitize_callback' => 'sanitize_text_field' ] ];
		$route = '/maps/(?P<host>[a-z0-9.-]+)/part';
		register_rest_route( Module::NS, $route, [ 'methods' => 'GET', 'permission_callback' => $admin, 'args' => $args, 'callback' => static fn( \WP_REST_Request $r ) => self::answer( self::plan( (string) $r['host'], sanitize_key( (string) $r->get_param( 'part' ) ), absint( $r->get_param( 'offset' ) ) ) ) ] );
		register_rest_route( Module::NS, $route, [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $args, 'callback' => static fn( \WP_REST_Request $r ) => self::answer( self::write( (string) $r['host'], sanitize_key( (string) $r->get_param( 'part' ) ), array_map( 'intval', (array) $r->get_param( 'ids' ) ) ) ) ] );
	}

	private static function answer( $out ): \WP_REST_Response {
		return is_wp_error( $out ) ? new \WP_REST_Response( [ 'message' => $out->get_error_message() ], (int) ( $out->get_error_data()['status'] ?? 409 ) ) : rest_ensure_response( $out );
	}

	/** The site's map and its part, or why not. @return array{0:array,1:array}|\WP_Error */
	private static function part_of( string $host, string $part ) {
		$row = VendorMaps::get( $host );
		if ( ! $row ) {
			return new \WP_Error( 'mmpt_part', 'No map for this site yet.', [ 'status' => 404 ] );
		}
		if ( ! isset( self::PLAN_FIELD[ $part ] ) ) {
			return new \WP_Error( 'mmpt_part', 'That part can’t be written to the site’s products.', [ 'status' => 400 ] );
		}
		$f = (array) ( $row['fields'][ $part ] ?? [] );
		if ( empty( $f['sel'] ) ) {
			return new \WP_Error( 'mmpt_part', sprintf( '%s isn’t mapped: pick it on a page first.', VendorMaps::FIELDS[ $part ][0] ?? $part ) );
		}
		return [ $row, $f ];
	}

	/**
	 * What writing $part would do for the site's products, from $offset: rows (one per product looked at), and `next`
	 * (the offset to ask from, or null at the end).
	 * Row: id, title, url, edit, reads (the part's picks find something on its page), action (fill, refresh, maker,
	 * review, merge, replace: a write; same, kept, locked, too_little, none: nothing; not_read: the picks read nothing
	 * there and the general reader would change the product; general_same: they read nothing, and it wouldn't; no_page, no_card; skip: the writers leave the product alone, `why`), now/page (characters, or items).
	 * @return array|\WP_Error
	 */
	public static function plan( string $host, string $part, int $offset = 0 ) {
		$got = self::part_of( $host, $part );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		[ $row, $f ] = $got;
		$all   = VendorMaps::site_products( $row['host'] );
		$slice = array_slice( $all, $offset, self::SLICE );
		$start = microtime( true );
		$rows  = [];
		foreach ( $slice as $p ) {
			$rows[] = self::row( $row['host'], $part, $f, $p );
			if ( microtime( true ) - $start > self::BUDGET_S ) {
				break;
			}
		}
		$next = $offset + count( $rows );
		return [
			'host'  => $row['host'],
			'part'  => $part,
			'total' => count( $all ),
			'rows'  => $rows,
			'next'  => $next < count( $all ) ? $next : null,
			'live'  => $row['status'] === 'confirmed' || ! empty( $f['live'] ),
			'found' => (int) ( $f['found'] ?? 0 ),
			'of'    => (int) ( $f['of'] ?? 0 ),
		];
	}

	/** One product's row (plan()). */
	private static function row( string $host, string $part, array $f, array $p ): array {
		$id  = (int) $p['id'];
		$out = [ 'id' => $id, 'title' => (string) $p['title'], 'url' => (string) $p['url'], 'edit' => (string) get_edit_post_link( $id, 'raw' ), 'reads' => false, 'action' => '' ];
		if ( $p['st'] !== 'ok' || $p['file'] === '' ) {
			return [ 'action' => 'no_page', 'why' => $p['st'] !== '' ? $p['st'] : 'not_read' ] + $out;
		}
		$html = PageStore::read( (string) $p['file'] );
		$own  = $html !== '' ? ProductPage::own_html( $id, (string) $p['url'], $html ) : null; // a page listing several products: its own card
		if ( $own === null ) {
			return [ 'action' => $html === '' ? 'no_page' : 'no_card' ] + $out;
		}
		$read         = VendorMaps::eval_field( $part, (string) $f['sel'], (string) ( $f['mode'] ?? '' ), $own, (string) $p['url'], false, (string) ( $f['src'] ?? '' ) );
		$out['reads'] = ! empty( $read['found'] );
		$plan = VendorMaps::with_editor_parts( $host, [ $part ], static fn() => ProductPreview::plan( $host, $id, true ) );
		if ( ! $out['reads'] ) { // the general reader still reads the page: a miss matters only if it would give the product something else
			return [ 'action' => $plan && ! isset( ProductPreview::writes( $plan )[ $part ] ) ? 'general_same' : 'not_read' ] + $out;
		}
		if ( ! $plan ) {
			return [ 'action' => 'no_page' ] + $out;
		}
		if ( ( $plan['skip'] ?? '' ) !== '' ) {
			return [ 'action' => 'skip', 'why' => (string) $plan['skip'] ] + $out;
		}
		$fld    = (array) ( $plan['fields'][ self::PLAN_FIELD[ $part ] ] ?? [] );
		$action = $fld['action'] ?? '';
		$write  = ProductPreview::writes( $plan )[ $part ] ?? '';
		if ( is_array( $action ) ) { // requirements: one decision per OS
			$action = $write === 'write' ? 'fill' : ( in_array( 'replace', $action, true ) ? 'review' : 'kept' );
		}
		$action = (string) $action;
		if ( $part === 'description' ) {
			$now  = Descriptions::plain( (string) ( get_post( $id )->post_content ?? '' ) );
			$page = Descriptions::plain( (string) ( $plan['raw']['description'] ?? '' ) );
			if ( in_array( $action, [ 'refresh', 'maker' ], true ) && $now === $page ) {
				$action = 'same'; // the writer leaves the same words alone
			}
			$out['now']  = mb_strlen( $now );
			$out['page'] = mb_strlen( $page );
		} elseif ( $write === 'review' || $action === 'proposal' ) {
			$action = 'review';
		}
		if ( $part === 'pictures' && $action === 'in_place' ) { // the product keeps its own: say why, not only that
			$why    = (string) VendorMaps::with_editor_parts( $host, [ $part ], static fn() => Pictures::plan( $id )['reason'] );
			$action = self::KEPT_WHY[ $why ] ?? $action;
		}
		return [ 'action' => $action !== '' ? $action : 'none' ] + $out;
	}

	/**
	 * Put $part in use on the site and read the chosen products again with it ($ids: products plan() said it writes to).
	 * @return array|\WP_Error { since, run, scrape, pictures, products, live }
	 */
	public static function write( string $host, string $part, array $ids ) {
		$got = self::part_of( $host, $part );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		[ $row, $f ] = $got;
		if ( empty( Enrichment::settings()['enabled'] ) ) {
			return new \WP_Error( 'mmpt_part', 'Writing from vendor pages is switched off (Product Data → Applying).' );
		}
		$refused = self::refused( $row, $part );
		if ( $refused !== '' ) {
			return new \WP_Error( 'mmpt_part', $refused );
		}
		$ids = array_values( array_intersect( array_unique( array_filter( $ids ) ), array_column( VendorMaps::site_products( $row['host'] ), 'id' ) ) );
		if ( ! $ids ) {
			return new \WP_Error( 'mmpt_part', 'Nothing was written: none of those products is on this site.' );
		}
		$live = $row['status'] !== 'confirmed';
		if ( $live ) {
			VendorMaps::set_live( $row['host'], $part, true ); // before the reads: they read with it
		}
		return self::run( $row['host'], $part, $ids, [ 'put_in_use' => $live ] ) + [ 'live' => $live ];
	}

	/** Read $ids again with $part as the reader now has it, each recorded by FillRun (Undo). @return array{since,run,scrape,pictures,products} */
	private static function run( string $host, string $part, array $ids, array $audit ): array {
		$pics  = $part === 'pictures';
		$since = current_time( 'mysql', true );
		$run   = FillRun::start( $host, $pics ? [] : $ids, $pics ? $ids : [] ); // what each product has now, for "What the last fill wrote" and Undo
		$n     = 0;
		$p     = 0;
		if ( $pics ) {
			$p = Pictures::enqueue( $ids, 'manual' );
			if ( $p ) {
				Pictures::kick();
			}
		} else {
			$n = Scraper::enqueue( $ids, 'scrape', false, 'manual' ); // the stored copy, read with the part now in use
			if ( $n ) {
				Scraper::kick();
			}
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_maps.part_fill', [ 'object_type' => 'vendor_site', 'object_id' => $host, 'details' => [ 'field' => $part, 'products' => count( $ids ), 'run' => $run ] + $audit ] );
		}
		return [ 'since' => $since, 'run' => $run, 'scrape' => $n, 'pictures' => $p, 'products' => count( $ids ) ];
	}

	/* ── One layout's products (the Chrome extension, plan §13 S2b) ──────── */

	/** Layout $i's map row, type and own part $part, or why not. @return array{0:array,1:array,2:array}|\WP_Error */
	private static function layout_part( string $host, int $i, string $part ) {
		$row = VendorMaps::get( $host );
		if ( ! $row ) {
			return new \WP_Error( 'mmpt_part', 'No map for this site yet.', [ 'status' => 404 ] );
		}
		if ( ! isset( self::PLAN_FIELD[ $part ] ) ) {
			return new \WP_Error( 'mmpt_part', 'That part can’t be written to the layout’s products.', [ 'status' => 400 ] );
		}
		$t = MapTypes::for_layout( $row['host'], $i );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$f = $t ? (array) ( $t['fields'][ $part ] ?? [] ) : [];
		if ( empty( $f['sel'] ) ) {
			return new \WP_Error( 'mmpt_part', sprintf( '%s has no pick of this layout’s own: pick it on one of its pages and save it for the layout.', VendorMaps::FIELDS[ $part ][0] ?? $part ) );
		}
		return [ $row, $t, $f ];
	}

	/** The site's products whose verified page is one of type $t's layout's pages. */
	public static function layout_products( string $host, array $t ): array {
		return self::products_on( $host, MapTypes::urls_of( $host, $t ) );
	}

	/** The site's products whose verified page is one of $pages. */
	private static function products_on( string $host, array $pages ): array {
		$key  = static fn( string $u ) => preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) ) . rtrim( strtolower( (string) wp_parse_url( $u, PHP_URL_PATH ) ), '/' );
		$urls = array_flip( array_map( $key, $pages ) );
		return array_values( array_filter( VendorMaps::site_products( $host ), static fn( $p ) => isset( $urls[ $key( (string) $p['url'] ) ] ) ) );
	}

	/**
	 * What a pick not saved yet would do to the products of layout $i, or of the whole site ($i null): plan()'s answer for
	 * each, with $f laid over the map (owner 2026-11-04: "calculate these exposures at the time of selection"). Nothing is
	 * stored or written. $ids: the products the first slice named. @return array|\WP_Error { total, ids, rows, next }
	 */
	public static function trial_plan( string $host, ?int $i, string $part, array $f, int $offset = 0, ?array $ids = null ) {
		$row = VendorMaps::get( $host );
		if ( ! $row || ! isset( self::PLAN_FIELD[ $part ] ) ) {
			return new \WP_Error( 'mmpt_part', 'That part can’t be looked at here.', [ 'status' => 400 ] );
		}
		if ( $ids !== null ) {
			$want = array_flip( array_map( 'intval', $ids ) );
			$all  = array_values( array_filter( VendorMaps::site_products( $row['host'] ), static fn( $p ) => isset( $want[ (int) $p['id'] ] ) ) );
		} else {
			$all = $i === null ? VendorMaps::site_products( $row['host'] ) : self::products_on( $row['host'], MapTypes::layout_pages( $row['host'] )[ $i ]['urls'] ?? [] );
		}
		$start = microtime( true );
		$rows  = [];
		foreach ( array_slice( $all, $offset, self::SLICE ) as $p ) {
			$rows[] = VendorMaps::with_trial( [ $part => $f ], static fn() => self::row( $row['host'], $part, $f, $p ) );
			if ( microtime( true ) - $start > self::BUDGET_S ) {
				break;
			}
		}
		$next = $offset + count( $rows );
		return [ 'total' => count( $all ), 'ids' => array_column( $all, 'id' ), 'rows' => $rows, 'next' => $next < count( $all ) ? $next : null ];
	}

	/**
	 * plan() for layout $i's own part, over that layout's products only. $ids: the products the first slice named
	 * (`ids`), so later slices go on over the same list while the site's pages are grouped again after a save.
	 * @return array|\WP_Error
	 */
	public static function layout_plan( string $host, int $i, string $part, int $offset = 0, ?array $ids = null ) {
		$got = self::layout_part( $host, $i, $part );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		[ $row, $t, $f ] = $got;
		if ( $ids === null ) {
			$all = self::layout_products( $row['host'], $t );
		} else {
			$want = array_flip( array_map( 'intval', $ids ) );
			$all  = array_values( array_filter( VendorMaps::site_products( $row['host'] ), static fn( $p ) => isset( $want[ (int) $p['id'] ] ) ) );
		}
		$start = microtime( true );
		$rows  = [];
		foreach ( array_slice( $all, $offset, self::SLICE ) as $p ) {
			$rows[] = self::row( $row['host'], $part, $f, $p ); // the editor's map: the layout's own pick reads its pages
			if ( microtime( true ) - $start > self::BUDGET_S ) {
				break;
			}
		}
		$next = $offset + count( $rows );
		return [
			'host'  => $row['host'],
			'part'  => $part,
			'type'  => (string) $t['type_key'],
			'total' => count( $all ),
			'ids'   => array_column( $all, 'id' ),
			'rows'  => $rows,
			'next'  => $next < count( $all ) ? $next : null,
			'live'  => $t['status'] === 'confirmed' || ! empty( $f['live'] ),
		];
	}

	/**
	 * write() for layout $i's own part: put in use on that layout's pages only (MapTypes::set_live(); a confirmed layout
	 * has it already), then $ids (of the layout's products) read again with it. $dry: what it would do, nothing changed.
	 * @return array|\WP_Error { since, run, scrape, pictures, products, live }
	 */
	public static function layout_write( string $host, int $i, string $part, array $ids, bool $dry = false ) {
		$got = self::layout_part( $host, $i, $part );
		if ( is_wp_error( $got ) ) {
			return $got;
		}
		[ $row, $t, $f ] = $got;
		if ( empty( Enrichment::settings()['enabled'] ) ) {
			return new \WP_Error( 'mmpt_part', 'Writing from vendor pages is switched off (Product Data → Applying).' );
		}
		if ( (int) ( $f['of'] ?? 0 ) > 0 && ! (int) ( $f['found'] ?? 0 ) ) {
			return new \WP_Error( 'mmpt_part', sprintf( '%s finds nothing on this layout’s stored pages: pick it again first.', VendorMaps::FIELDS[ $part ][0] ?? $part ) );
		}
		$why = MapTypes::drop( $row['host'], $t, $part );
		if ( $why !== '' ) {
			return new \WP_Error( 'mmpt_part', 'Nothing was written. ' . $why );
		}
		$ids = array_values( array_intersect( array_unique( array_filter( array_map( 'intval', $ids ) ) ), array_column( self::layout_products( $row['host'], $t ), 'id' ) ) );
		if ( ! $ids ) {
			return new \WP_Error( 'mmpt_part', 'Nothing was written: none of those products’ pages is of this layout.' );
		}
		$live = $t['status'] !== 'confirmed' && empty( $f['live'] );
		if ( $dry ) {
			return [ 'dry' => true, 'products' => count( $ids ), 'live' => $live ];
		}
		if ( $live ) {
			MapTypes::set_live( $row['host'], (string) $t['type_key'], $part, true ); // before the reads: they read with it
			VendorMaps::forget_confirmed();
		}
		return self::run( $row['host'], $part, $ids, [ 'layout' => (string) $t['type_key'], 'put_in_use' => $live ] ) + [ 'live' => $live ];
	}

	/** Confirm's guards for one part ('' = none): it finds something, it was measured since its last change, it takes nothing away. */
	private static function refused( array $row, string $part ): string {
		$name = VendorMaps::FIELDS[ $part ][0] ?? $part;
		if ( in_array( $part, VendorMaps::zero_fields( $row ), true ) ) {
			return sprintf( '%s finds nothing on the stored pages: pick it again first.', $name );
		}
		if ( $row['status'] === 'confirmed' || ! empty( $row['fields'][ $part ]['live'] ) ) {
			return ''; // already what the reader uses
		}
		$drops = VendorMaps::drops( $row );
		if ( $drops === null ) {
			return 'What the map changes is still being measured since its last edit: try again in a minute.';
		}
		foreach ( $drops as [ $k, $was, $now ] ) {
			if ( $k === $part ) {
				return sprintf( 'Nothing was written: with this part the reader finds %s on %d pages, not %d. A part may only add.', $name, $now, $was );
			}
		}
		return '';
	}

}
