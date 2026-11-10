<?php
/**
 * Product Content — the Vendor maps editor's product preview (owner 2026-10-18).
 *
 * For one product of a site: each field as the product has it now, what its stored vendor page gives
 * with the map as it is in the editor (draft parts stand in for the confirmed map, as "If you confirm"
 * measures it: VendorMaps::with_editor_map()), and what a Scrape or the picture runner would then do.
 * Every decision is the writer's own, so the preview never disagrees with a write:
 *
 * - description  Enrichment: a description under THIN_CHARS gets a proposal from the page (written at once
 *                when the "content:vendor_page" rule is approved); one this module wrote is refreshed;
 *                a longer one is kept.
 * - pictures     Pictures::plan() and PictureMatch::merge(): first pictures, the page's pictures merged into the
 *                product's (Kept / Sharper copy / New / Duplicate, picture by picture), own ones re-saved. The
 *                preview never asks Claude: a doubtful page picture it hasn't answered is not added (2026-10-21).
 * - requirements PageRequirements::plan(): fill | same | locked | replace (clearly outdated) | review, per platform.
 * - features                  VendorMaps::plan_fill(): the description's bullet lists (3+ points), only when empty.
 * - manuals, videos           VendorMaps::plan_fill(): only a mapped part fills them, and only when empty.
 * - version      no product field yet (PRODUCT_DATA_PROGRAM.md §11): shown, never written.
 *
 * Reads the stored page only and writes nothing. Data only: its callers word it (the extension panel, the Fill review). Scrape now
 * (scrape()) reads the product's page afresh into the page store first; the product is still not written.
 *
 * The product page view (phase 3): the product's real page (Bricks single product template) rendered for an
 * administrator with what this map would give it, nothing saved. The URL carries the product's own permalink,
 * the site and a nonce (preview_url()); the render recomputes the plan itself (no stored state) and swaps the
 * values in for that request only: the description (post_content / the_content), pictures the media library
 * doesn't have yet (attachment IDs from FAKE_BASE, image_downsize answering with the vendor's URL), and the
 * registry's requirements, features, manuals and videos (get_post_metadata). Proposals show as if approved;
 * kept, locked and review values stay as they are. Never cached, never indexed, no admin bar.
 *
 * @package MMI_Admin
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProductPreview {

	const MAX_ITEMS  = 30; // features, manuals, videos listed per side
	const QUERY      = 'mmpt_preview'; // the nonce
	const QUERY_HOST = 'mmpt_host';
	const QUERY_PART = 'mmpt_part'; // one part only: its values swapped in and outlined (the parts' Preview buttons)
	const QUERY_TRY  = 'mmpt_try';  // picks not saved yet (VendorMaps::trial_parse()), base64url JSON: the page as Save would leave it
	const SIGNED_S   = 6 * HOUR_IN_SECONDS; // a signed link (signed_url()) works this long, for its person only
	/**
	 * Where each part shows on the product page: the template's elements by name, or by the registry concept their
	 * query loop reads ("MMI Spec: …"). Manuals, audio, version and size aren't on the page.
	 */
	const ON_PAGE = [
		'description'  => [ 'label' => 'Description', 'names' => [ 'product-content', 'post-content' ], 'concepts' => [] ],
		'pictures'     => [ 'label' => 'Pictures', 'names' => [ 'product-gallery' ], 'concepts' => [] ],
		'requirements' => [ 'label' => 'System requirements', 'names' => [], 'concepts' => [ 'requirements_mac', 'requirements_windows', 'requirements_linux' ] ],
		'features'     => [ 'label' => 'Features', 'names' => [], 'concepts' => [ 'features', 'key_features' ] ],
		'videos'       => [ 'label' => 'Videos', 'names' => [], 'concepts' => [ 'videos', 'video_primary' ] ],
		'disclaimer'   => [ 'label' => 'Disclaimer', 'names' => [], 'concepts' => [ 'disclaimer' ] ],
	];
	const PARTS = [ 'description', 'pictures', 'requirements', 'features', 'manual', 'videos', 'audio', 'version', 'disclaimer' ];
	const FAKE_BASE  = 2000000000; // attachment IDs for pictures not in the media library yet (no real ID gets near)
	const FAKE_SIZE  = 800;        // their width and height: the store's standard picture
	const SHOWN      = [ 'fill', 'replace', 'refresh', 'maker', 'proposal', 'merge' ]; // actions the product page view applies
	const SCRAPE_WAIT = 600; // seconds a Scrape now in the server's browser, or a write, may take before it counts as failed
	const WRITE_WAIT  = 300; // seconds a write may take before it counts as not finished: the writer can die before it can say so
	const WRITE_META  = '_mmpt_map_write'; // the product's last write from the editor (write()): what changed, for undo_write()

	public static function register_hooks(): void {
		add_action( 'wp', [ __CLASS__, 'maybe_preview' ], 1 );
	}

	/** The product page with what this map would give it (the editor's Product page view). */
	public static function preview_url( string $host, int $id, string $part = '', array $try = [] ): string {
		$args = [ self::QUERY => wp_create_nonce( 'mmpt_preview_' . $id ), self::QUERY_HOST => VendorMaps::host( $host ) ];
		if ( in_array( $part, self::PARTS, true ) ) {
			$args[ self::QUERY_PART ] = $part;
		}
		if ( $try ) {
			$args[ self::QUERY_TRY ] = rtrim( strtr( base64_encode( (string) wp_json_encode( $try ) ), '+/', '-_' ), '=' );
		}
		return add_query_arg( $args, get_permalink( $id ) );
	}

	/**
	 * preview_url() for a request that has no login cookie (the Chrome extension's token): the nonce can't be made there,
	 * so the link carries "s.<expires>.<user>.<HMAC of product, host, part, try, expires, user>", checked by maybe_preview()
	 * for the same signed-in person only.
	 */
	public static function signed_url( string $host, int $id, string $part = '', array $try = [] ): string {
		$url = remove_query_arg( self::QUERY, self::preview_url( $host, $id, $part, $try ) );
		$q   = (array) wp_parse_args( (string) wp_parse_url( $url, PHP_URL_QUERY ) );
		$exp = time() + self::SIGNED_S;
		$uid = get_current_user_id();
		return add_query_arg( self::QUERY, 's.' . $exp . '.' . $uid . '.' . self::signature( $id, $q, $exp, $uid ), $url );
	}

	/** The HMAC a signed link carries. @param array $q its query: host, part, try */
	private static function signature( int $id, array $q, int $exp, int $uid ): string {
		$parts = [ $id, (string) ( $q[ self::QUERY_HOST ] ?? '' ), (string) ( $q[ self::QUERY_PART ] ?? '' ), (string) ( $q[ self::QUERY_TRY ] ?? '' ), $exp, $uid ];
		return hash_hmac( 'sha256', implode( '|', $parts ), wp_salt( 'auth' ) );
	}

	/** A preview link's key is good for product $id: this module's nonce, or a signed link (signed_url()) of this person, not expired. */
	private static function key_ok( string $key, int $id ): bool {
		if ( ! preg_match( '/^s\.(\d+)\.(\d+)\.([a-f0-9]{64})$/', $key, $m ) ) {
			return (bool) wp_verify_nonce( $key, 'mmpt_preview_' . $id );
		}
		$q = array_map( static fn( $v ) => sanitize_text_field( wp_unslash( (string) $v ) ), array_intersect_key( $_GET, array_flip( [ self::QUERY_HOST, self::QUERY_PART, self::QUERY_TRY ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- this is the check
		return (int) $m[1] >= time() && (int) $m[2] === get_current_user_id() && hash_equals( self::signature( $id, $q, (int) $m[1], (int) $m[2] ), $m[3] );
	}

	/**
	 * The preview of product $id on $host's map, or null when the product has no verified page on that site.
	 * @return array<string,mixed>|null
	 */
	public static function plan( string $host, int $id, bool $raw = false, bool $review = false ): ?array { // $review: pictures as a person's review reads them (Pictures::plan())
		$host = VendorMaps::host( $host );
		$url  = self::page_url( $host, $id );
		if ( $url === '' ) {
			return null;
		}
		$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$post  = get_post( $id );
		$base  = [
			'id'    => $id,
			'title' => $title,
			'url'   => $url,
			'edit'  => get_edit_post_link( $id, 'raw' ),
			'view'  => get_permalink( $id ),
			'auto'  => ! empty( Enrichment::rules()['content:vendor_page']['approved'] ),
			'skip'  => self::skip( $id ), // why the writers leave it alone ('' = they don't)
			'offer' => self::open_offer( $id, $url ), // another page proposed for it in the URL queue, or null
		];
		$row = PageStore::row( $url );
		if ( ! $row || $row['status'] !== 'ok' || $row['html_file'] === '' ) {
			return $base + [ 'page' => [ 'status' => $row ? (string) $row['status'] : 'not_read', 'note' => $row ? (string) $row['note'] : '' ], 'fields' => self::current_only( $id, $post ) ];
		}
		$html = PageStore::read( (string) $row['html_file'] );
		$rel  = SharedPages::relation( $id, $url );
		$own  = ProductPage::own_html( $id, $url, $html ); // a page listing several products: its own card only
		if ( $own === null ) {
			return $base + [ 'page' => [ 'status' => 'no_card', 'note' => '', 'kind' => $rel['kind'] ], 'fields' => self::current_only( $id, $post ) ];
		}
		$read = VendorMaps::with_editor_map(
			$host,
			static function () use ( $id, $url, $own, $title, $rel, $review ) {
				$x   = VendorPage::extract( $own, $url, $title );
				$req = class_exists( PageRequirements::class ) ? PageRequirements::extract( VendorMaps::requirements_html( $url, $own ) ) : null;
				return [
					'x'        => $x,
					'desc'     => in_array( $rel['kind'], [ 'own', 'one' ], true ) ? ChoiceBundles::described( $id, VendorPage::description_html( $x ) ) : SharedPages::description_for( $id, $url ), // an edition: its family's text and its own column; a choice bundle: then what can be chosen
					'fields'   => VendorPage::fields( $own, $url, $x ),
					'mapped'   => VendorMaps::extras( $url, $own ),
					'fills'    => VendorMaps::plan_fill( $id, $url, $own ),
					'parts'    => VendorMaps::mapped_parts( $url, $own ),
					'req'      => $req,
					'req_plan' => $req ? PageRequirements::plan( $id, $req ) : [],
					'pictures' => Pictures::plan( $id, $review ),
				];
			}
		);
		$merge = $read['pictures']['mode'] === 'vendor' ? PictureMatch::merge( (array) $read['pictures']['current'], (array) $read['pictures']['urls'], false, $id, $host ) : null; // a person's choices apply (PictureChoices)
		$out   = $base + [
			'page'   => [ 'status' => 'ok', 'note' => '', 'kind' => $rel['kind'], 'flags' => array_values( (array) $read['x']['flags'] ) ],
			'fields' => [
				'title'        => [ 'current' => [ 'text' => $title ] ],
				'description'  => self::description( $id, $post, (string) $read['desc'] ),
				'pictures'     => self::pictures( $id, $read['pictures'], ChoiceBundles::without_cards( $id, $url, $own, (array) $read['x']['images'] ), $merge ), // a choice bundle's grid is other products
				'requirements' => self::requirements( $id, $read['req'], $read['req_plan'] ),
				'features'     => self::filled( $id, 'features', 'features', $read['fills'], (array) $read['fields']['features'], in_array( 'features', $read['parts'], true ) ),
				'manual'       => self::filled( $id, 'manual', 'manuals', $read['fills'], (array) $read['fields']['manuals'], in_array( 'manual', $read['parts'], true ) ),
				'videos'       => self::filled( $id, 'videos', 'videos', $read['fills'], (array) $read['fields']['videos'], in_array( 'videos', $read['parts'], true ) ),
				'audio'        => self::filled( $id, 'audio', 'audio', $read['fills'], (array) ( $read['fields']['audio'] ?? [] ), in_array( 'audio', $read['parts'], true ) ),
				'disclaimer'   => self::filled( $id, 'disclaimer', 'disclaimer', $read['fills'], [], in_array( 'disclaimer', $read['parts'], true ) ),
				'version'      => [ 'proposed' => [ 'text' => (string) ( $read['fields']['version'] !== '' ? $read['fields']['version'] : ( $read['mapped']['version'] ?? '' ) ) ], 'action' => 'no_field' ],
			],
		];
		if ( $raw ) { // the values as the writers would store them (the product page view), never sent to the editor
			$out['raw'] = [ 'fills' => $read['fills'], 'req' => $read['req'], 'req_plan' => $read['req_plan'], 'description' => (string) $read['desc'], 'pictures' => $merge ? array_map( static fn( $f ) => isset( $f['att'] ) ? (string) wp_get_attachment_image_url( (int) $f['att'], 'full' ) : (string) $f['url'], $merge['final'] ) : [] ];
		}
		return $out;
	}

	/**
	 * Why Scraper::process_one() leaves product $id alone, or '': not_published, excluded (the Rules tab, or not sold),
	 * manual (a person entered its copy; its page is never read over it) or no_page (marked as having none).
	 */
	public static function skip( int $id ): string {
		if ( get_post_status( $id ) !== 'publish' ) {
			return 'not_published';
		}
		if ( Exclusions::is_excluded( $id ) ) {
			return 'excluded';
		}
		$fs = (string) ( Descriptions::get_many( [ $id ] )[ $id ]['fetch_status'] ?? '' );
		return $fs === 'manual' ? 'manual' : ( $fs === UrlFinder::NO_PAGE ? 'no_page' : '' );
	}

	/** A page proposed for product $id in the URL queue other than its own (ProductPage::recheck(), card links…), or null. */
	private static function open_offer( int $id, string $url ): ?array {
		$row = UrlFinder::row( $id );
		if ( ! $row || $row['status'] !== 'candidate' || UrlFinder::same_url( (string) $row['url'], $url ) ) {
			return null;
		}
		return [ 'url' => (string) $row['url'], 'reason' => (string) $row['reason'], 'label' => UrlQueue::REASONS[ $row['reason'] ] ?? '', 'score' => (int) $row['score'] ];
	}

	/** The product's verified page on $host, or '' when it has none there. */
	private static function page_url( string $host, int $id ): string {
		$ok  = in_array( strtoupper( (string) get_post_meta( $id, 'vendor_product_url_verified', true ) ), [ 'TRUE', '1' ], true ); // stored as TRUE, true and 1
		$url = $ok ? (string) get_post_meta( $id, 'vendor_product_url', true ) : '';
		return get_post_type( $id ) === 'product' && preg_match( '#^https?://#i', $url ) && VendorMaps::host( $url ) === VendorMaps::host( $host ) ? $url : '';
	}

	/* ── Scrape now (owner 2026-10-22: test the process with a preview before committing) ── */

	/**
	 * The product's page read from the vendor's site now, and the pages its map's parts are kept on read as a Scrape
	 * reads them (stored copies under 30 days kept), with the map as it is in the editor. Only the page store changes
	 * (a refused or failed re-read keeps the good copy, PageStore::get()); plan() then shows the fresh copy, and
	 * nothing is written to the product: Confirm map & fill does that. A site read in the server's browser is read by
	 * WP-CLI (PageStore::render() refuses a web request): started here, the editor waits for it (state `running`).
	 * $browser reads this one page in the server's browser whatever the site's setting (owner 2026-10-24: a page whose
	 * content its scripts show, BOOM's demo list, stays hidden in a plain copy).
	 * @return array{state:string,at?:string,status?:string,changed?:?bool,was_at?:string,note?:string}|null null when the product has no verified page on this site
	 */
	public static function scrape( string $host, int $id, bool $browser = false ): ?array {
		$url = self::page_url( $host, $id );
		if ( $url === '' ) {
			return null;
		}
		$key    = self::scraped_key( $host, $id );
		$render = $browser || (bool) VendorMaps::with_editor_map( $host, static fn() => VendorMaps::render_for( $url ) );
		$cli    = defined( 'WP_CLI' ) && WP_CLI;
		if ( $render && ! $cli ) {
			$state = [ 'state' => 'running', 'at' => current_time( 'mysql', true ) ];
			set_transient( $key, $state, DAY_IN_SECONDS );
			if ( ! Scraper::spawn( 'mmpt maps scrape-now ' . VendorMaps::host( $host ) . ' ' . $id . ( $browser ? ' --browser' : '' ), 'maps-runner.log' ) ) {
				delete_transient( $key );
				return [ 'state' => 'error', 'note' => 'The server’s browser could not be started.' ];
			}
			return $state;
		}
		while ( $render && PageStore::server_busy() ) {
			sleep( 15 ); // WP-CLI only: the browser waits for the server, as the runners do
		}
		$was     = PageStore::row( $url );
		$before  = $was && $was['status'] === 'ok' && (string) $was['html_file'] !== '' ? self::text_sig( PageStore::read( (string) $was['html_file'] ) ) : '';
		$res     = $render ? PageStore::render( $url, 0 ) : PageStore::get( $url, 0 );
		$after   = self::read_around( $host, $id, $url );
		$changed = $before !== '' && $after !== '' ? $before !== $after : null; // its words, not its HTML: pages carry a new token on every read
		if ( $changed ) {
			VendorMaps::queue( 'refresh', $host ); // the gaps and the site's numbers follow the new copy
		}
		$out = [
			'state'   => 'done',
			'at'      => current_time( 'mysql', true ),
			'status'  => (string) $res['status'],
			'changed' => $changed,
			'was_at'  => $was ? (string) $was['fetched_at'] : '',
			'note'    => (string) ( $res['note'] ?? '' ),
		];
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * The product's page as the owner's browser just stored it (the Chrome extension, ExtBridge): counted as a Scrape
	 * now, so Write to this product follows from the page as it is today. The pages its map's parts are kept on are
	 * read as a Scrape reads them. @return array|null scrape()'s answer; null when the product has no verified page here
	 */
	public static function browser_copy( string $host, int $id ): ?array {
		$url = self::page_url( $host, $id );
		if ( $url === '' ) {
			return null;
		}
		$row = PageStore::row( $url );
		if ( ! $row || $row['status'] !== 'ok' || (string) $row['html_file'] === '' ) {
			return [ 'state' => 'error', 'note' => 'The page isn’t stored yet.' ];
		}
		self::read_around( $host, $id, $url );
		$out = [ 'state' => 'done', 'at' => current_time( 'mysql', true ), 'status' => 'ok', 'changed' => null, 'was_at' => (string) $row['fetched_at'], 'note' => 'Stored from your browser' ];
		set_transient( self::scraped_key( $host, $id ), $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * After the product's page is stored: the pages its map's parts are kept on read again (a requirements link's page,
	 * sub-pages, the site's one page). @return string the stored page's word signature ('' when none is stored)
	 */
	private static function read_around( string $host, int $id, string $url ): string {
		$now = PageStore::row( $url );
		if ( ! $now || $now['status'] !== 'ok' || (string) $now['html_file'] === '' ) {
			return '';
		}
		$html = PageStore::read( (string) $now['html_file'] );
		$own  = ProductPage::own_html( $id, $url, $html );
		if ( $own !== null ) {
			VendorMaps::with_editor_map(
				$host,
				static function () use ( $url, $own ) {
					VendorMaps::requirements_html( $url, $own, true ); // the page a requirements link opens
					VendorMaps::extras( $url, $own, true );            // sub-pages and the site's one page
				}
			);
		}
		return self::text_sig( $html );
	}

	/** The last Scrape now of product $id on $host (scrape()'s answer), or null. */
	public static function scraped( string $host, int $id ): ?array {
		$s = get_transient( self::scraped_key( $host, $id ) );
		if ( is_array( $s ) && $s['state'] === 'running' && strtotime( $s['at'] . ' UTC' ) < time() - self::SCRAPE_WAIT ) {
			return [ 'state' => 'error', 'note' => 'The server’s browser did not finish reading it. Try again.' ];
		}
		return is_array( $s ) ? $s : null;
	}

	/** A page's visible words, as one signature. */
	private static function text_sig( string $html ): string {
		return $html === '' ? '' : md5( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $html ) ) ) );
	}

	private static function scraped_key( string $host, int $id ): string {
		return 'mmpt_scraped_' . md5( VendorMaps::host( $host ) . '|' . $id );
	}

	/* ── Write to this product (owner 2026-10-22: commit one product before filling them all) ── */

	/**
	 * What a write would change, from a plan made with $raw: part => write | review (a description proposal waits in
	 * Review). The writers' own decisions: empty fields filled, a description this module wrote refreshed, the page's
	 * pictures merged in. Requirements a page has newer than the product's are review only, as in a Scrape.
	 * @return array<string,string>
	 */
	public static function writes( array $plan ): array {
		if ( ( $plan['skip'] ?? '' ) !== '' ) {
			return [];
		}
		$f   = $plan['fields'] ?? [];
		$raw = $plan['raw'] ?? [];
		$w   = [];
		$d   = (string) ( $f['description']['action'] ?? '' );
		if ( in_array( $d, [ 'fill', 'refresh', 'maker', 'proposal' ], true ) ) {
			$w['description'] = $d === 'proposal' ? 'review' : 'write';
		}
		if ( in_array( $f['pictures']['action'] ?? '', [ 'fill', 'merge', 'replace' ], true ) ) {
			$w['pictures'] = 'write';
		}
		if ( in_array( 'fill', (array) ( $raw['req_plan'] ?? [] ), true ) ) {
			$w['requirements'] = 'write';
		}
		foreach ( (array) ( $raw['fills'] ?? [] ) as $part => $p ) {
			if ( ( $p['action'] ?? '' ) === 'fill' ) {
				$w[ $part ] = 'write';
			}
		}
		ksort( $w );
		return $w;
	}

	/**
	 * Write product $id from its page as the map in the editor reads it (the draft stands in, as in the preview): the
	 * Fill's own writers for this one product (Pictures::process(), Scraper::process_one()). WP-CLI only (pictures are
	 * converted and the site may be read in the server's browser); write_start() starts it. What changed is kept on
	 * the product (WRITE_META) for undo_write().
	 * @return array<string,mixed> the record
	 */
	public static function write( string $host, int $id, array $only = [] ): array { // $only: the parts a person ticked (all when empty)
		global $wpdb;
		$plan = self::plan( $host, $id, true );
		if ( ! $plan ) {
			return self::write_record( $id, [ 'state' => 'error', 'note' => 'That product has no verified page on this site.' ] );
		}
		$writes = $only ? array_intersect_key( self::writes( $plan ), array_flip( $only ) ) : self::writes( $plan );
		$before = self::snapshot( $id );
		$note   = '';
		Activity::mark_vendor_pages();
		Scraper::$only = $only ?: null;
		try {
			VendorMaps::with_editor_map(
				$host,
				static function () use ( $id, $writes, &$note, $wpdb ) {
				if ( isset( $writes['pictures'] ) ) {
					// Pictures first: a product given pictures here isn't queued for first pictures by the scrape below.
					Pictures::enqueue( [ $id ], 'manual', true );
					if ( $wpdb->update( Pictures::table(), [ 'status' => 'working' ], [ 'product_id' => $id, 'status' => 'queued' ] ) ) { // claimed as the runner claims a row
						Pictures::process( $id );
					} else {
						$note = 'The picture runner was already working on its pictures, without this map’s draft parts.';
					}
				}
				Scraper::process_one( $id, 'scrape', false ); // the page as just scraped (stored), not read again
				}
			);
		} finally {
			Scraper::$only = null;
		}
		$c   = self::changes( $before, self::snapshot( $id ) );
		self::log_changes( $id, $c, 'product-extension' );
		$rec = self::write_record( $id, [
			'state'   => 'done',
			'host'    => VendorMaps::host( $host ),
			'planned' => $writes,
			'only'    => $only,
			'note'    => $note,
		] + $c );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_maps.write_one', [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'host' => VendorMaps::host( $host ), 'wrote' => $c['wrote'], 'planned' => array_keys( $writes ) ] ] );
		}
		return $rec;
	}

	/**
	 * What the writers changed between two snapshot()s of a product: the parts written, each meta key's old and new
	 * value, the locks added, the Review rows applied (content, image) and whether the picture runner applied.
	 * @return array{wrote:string[],meta:array,locks:string[],enrich:string[],pictures:bool}
	 */
	public static function changes( array $before, array $after ): array {
		$keys = self::write_keys();
		$meta = [];
		foreach ( $after['meta'] as $k => $v ) {
			if ( $v !== ( $before['meta'][ $k ] ?? null ) ) {
				$meta[ $k ] = [ 'was' => $before['meta'][ $k ] ?? null, 'now' => $v ];
			}
		}
		$enrich = array_keys( array_filter( $after['enrich'], static fn( $s, $kind ) => strpos( $s, 'applied|' ) === 0 && $s !== ( $before['enrich'][ $kind ] ?? '' ), ARRAY_FILTER_USE_BOTH ) );
		$pics   = strpos( $after['pictures'], 'applied|' ) === 0 && $after['pictures'] !== $before['pictures'];
		return [
			'wrote'    => array_values( array_unique( array_merge(
				array_map( static fn( $k ) => $keys[ $k ] ?? $k, array_keys( $meta ) ),
				in_array( 'content', $enrich, true ) ? [ 'description' ] : [],
				$pics || in_array( 'image', $enrich, true ) ? [ 'pictures' ] : []
			) ) ),
			'meta'     => $meta,
			'locks'    => array_values( array_diff( $after['locks'], $before['locks'] ) ),
			'enrich'   => $enrich,
			'pictures' => $pics,
		];
	}

	/**
	 * From the editor: start write() in WP-CLI, refused unless the product was scraped now and what would be written
	 * is still what the screen showed ($expect, its parts). @return array{state:string,note?:string,code?:int}
	 */
	public static function write_start( string $host, int $id, array $expect, array $only = [] ): array {
		$plan = self::plan( $host, $id, true );
		if ( ! $plan ) {
			return [ 'state' => 'error', 'code' => 404, 'note' => 'That product has no verified page on this site.' ];
		}
		if ( $plan['skip'] !== '' ) {
			return [ 'state' => 'error', 'code' => 409, 'note' => 'Product Data leaves this product alone (' . $plan['skip'] . '), so nothing is written to it.' ];
		}
		if ( ( self::scraped( $host, $id )['state'] ?? '' ) !== 'done' ) {
			return [ 'state' => 'error', 'code' => 409, 'note' => 'Scrape it now first, so it is written from its page as it is today.' ];
		}
		$parts = array_keys( self::writes( $plan ) );
		if ( $only ) {
			$parts = array_values( array_intersect( $parts, $only ) );
		}
		sort( $parts );
		sort( $expect );
		if ( ! $parts || $parts !== $expect ) {
			return [ 'state' => 'error', 'code' => 409, 'note' => $parts ? 'What would be written changed since the screen showed it. Look again, then write.' : 'Nothing to write: the product already has what its page gives.' ];
		}
		if ( ( self::written( $id )['state'] ?? '' ) === 'running' ) {
			return [ 'state' => 'error', 'code' => 409, 'note' => 'It is being written already.' ];
		}
		$rec = self::write_record( $id, [ 'state' => 'running', 'host' => VendorMaps::host( $host ) ] );
		if ( ! Scraper::spawn( 'mmpt maps write-one ' . VendorMaps::host( $host ) . ' ' . $id . ( $only ? ' --only=' . implode( ',', array_map( 'sanitize_key', $only ) ) : '' ) . ' --user=' . get_current_user_id(), 'maps-runner.log' ) ) {
			return self::write_record( $id, [ 'state' => 'error', 'note' => 'The writer could not be started.' ] );
		}
		return $rec;
	}

	/**
	 * Put back what write() changed, part by part, and only what is still as it left it (anything edited since is
	 * kept and named). Description and pictures go back through their own revert.
	 * @return array<string,mixed> the record
	 */
	public static function undo_write( int $id ): array {
		$w = get_post_meta( $id, self::WRITE_META, true );
		if ( ! is_array( $w ) || ( $w['state'] ?? '' ) !== 'done' ) {
			return [ 'state' => 'error', 'note' => 'There is no write to undo.' ];
		}
		$u   = self::undo_changes( $id, $w, 'product-extension' );
		$rec = self::write_record( $id, [ 'state' => 'undone' ] + $u + array_intersect_key( $w, array_flip( [ 'host', 'wrote', 'at' ] ) ), false );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_maps.write_one_undo', [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'undone' => $rec['undone'], 'kept' => $rec['kept'] ] ] );
		}
		return $rec;
	}

	/**
	 * Put back what changes() recorded, part by part, and only what is still as it was left (anything edited since
	 * is kept and named). Description and pictures go back through their own revert.
	 * $source: the writer that logged them (log_changes()), so Recent changes loses them again.
	 * @return array{undone:string[],kept:string[],queued:bool}
	 */
	public static function undo_changes( int $id, array $w, string $source = '' ): array {
		$keys   = self::write_keys();
		$undone = [];
		$kept   = [];
		foreach ( (array) $w['meta'] as $k => $c ) {
			$cur = metadata_exists( 'post', $id, $k ) ? (string) get_post_meta( $id, $k, true ) : null;
			if ( $cur !== $c['now'] ) {
				$kept[] = $keys[ $k ] ?? $k;
				continue;
			}
			if ( $c['was'] === null ) {
				delete_post_meta( $id, $k );
			} else {
				update_post_meta( $id, $k, wp_slash( (string) $c['was'] ) );
			}
			if ( $source !== '' ) {
				Write::logged( $id, (string) $k, $c['now'], $source );
			}
			$undone[] = $keys[ $k ] ?? $k;
		}
		if ( $w['locks'] && class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
			\MMI_Pipeline_Field_Locks::set( $id, array_diff( \MMI_Pipeline_Field_Locks::get( $id ), (array) $w['locks'] ) );
		}
		foreach ( (array) $w['enrich'] as $kind ) {
			$part = $kind === 'content' ? 'description' : 'pictures';
			if ( $kind === 'content' && ! self::content_still_ours( $id ) ) {
				$kept[] = $part;
				continue;
			}
			if ( Enrichment::revert( $id, (string) $kind )['result'] === 'error' ) {
				$kept[] = $part;
			} else {
				$undone[] = $part;
			}
		}
		$queued = false;
		if ( ! empty( $w['pictures'] ) ) {
			$r      = Pictures::revert( $id );
			$queued = $r['result'] === 'queued';
			if ( $r['result'] === 'error' ) {
				$kept[] = 'pictures';
			} else {
				$undone[] = 'pictures';
			}
		}
		if ( class_exists( '\MMI_Product_Content_Registry' ) ) {
			\MMI_Product_Content_Registry::forget( $id );
		}
		wc_delete_product_transients( $id );
		return [ 'undone' => array_values( array_unique( $undone ) ), 'kept' => array_values( array_unique( array_diff( $kept, $undone ) ) ), 'queued' => $queued ];
	}

	/**
	 * Show the meta changes() found in the Product Workbench's Recent changes as $source's, with Undo there. They are
	 * stored through the content registry, around the write gateway; the description and pictures log through their
	 * own writers already.
	 */
	public static function log_changes( int $id, array $c, string $source ): void {
		$locks = (array) ( $c['locks'] ?? [] );
		foreach ( (array) ( $c['meta'] ?? [] ) as $k => $m ) {
			Write::logged( $id, (string) $k, isset( $m['was'] ) ? (string) $m['was'] : null, $source, in_array( $k, $locks, true ) ? [ (string) $k ] : [] );
		}
	}

	/** The last write to product $id (for the editor: what was written, not the values), or null. */
	public static function written( int $id ): ?array {
		$w = get_post_meta( $id, self::WRITE_META, true );
		if ( ! is_array( $w ) ) {
			return null;
		}
		if ( $w['state'] === 'running' && strtotime( $w['at'] . ' UTC' ) < time() - self::WRITE_WAIT ) {
			$w = [ 'state' => 'error', 'note' => 'The writer did not finish. Look at the product before trying again.' ] + $w;
		}
		return array_intersect_key( $w, array_flip( [ 'state', 'at', 'host', 'wrote', 'note', 'undone', 'kept', 'queued', 'undone_at' ] ) );
	}

	/** Keep a write's record on the product ($stamp: `at` is now; else `undone_at`). */
	private static function write_record( int $id, array $rec, bool $stamp = true ): array {
		$rec[ $stamp ? 'at' : 'undone_at' ] = current_time( 'mysql', true );
		update_post_meta( $id, self::WRITE_META, $rec );
		return $rec;
	}

	/** The meta keys the map's writers fill, key => part: features, manuals, videos, audio, version, requirements, size. */
	private static function write_keys(): array {
		$keys = array_fill_keys( array_values( VendorMaps::SIZE_FIELDS ), 'size' );
		if ( class_exists( '\MMI_Product_Content_Registry' ) ) {
			$concepts = array_flip( VendorMaps::FILLS ) + [ 'requirements_mac' => 'requirements', 'requirements_windows' => 'requirements', 'requirements_linux' => 'requirements', 'platforms' => 'requirements' ];
			foreach ( $concepts as $concept => $part ) {
				$k = \MMI_Product_Content_Registry::canonical_key( (string) $concept );
				if ( $k !== '' ) {
					$keys[ $k ] = $part;
				}
			}
		}
		return $keys;
	}

	/** What the writers can change on a product: those keys' raw values (platforms among them), its locks, its Review and picture rows. */
	public static function snapshot( int $id ): array {
		global $wpdb;
		$meta = [];
		foreach ( array_keys( self::write_keys() ) as $k ) {
			$meta[ $k ] = metadata_exists( 'post', $id, $k ) ? (string) get_post_meta( $id, $k, true ) : null;
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT kind, CONCAT(status, '|', IFNULL(applied_at, '')) s FROM " . Enrichment::table() . ' WHERE product_id = %d', $id ), ARRAY_A );
		$pic   = $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(status, '|', IFNULL(run_at, '')) FROM " . Pictures::table() . ' WHERE product_id = %d', $id ) );
		return [
			'meta'     => $meta,
			'locks'    => class_exists( 'MMI_Pipeline_Field_Locks' ) ? \MMI_Pipeline_Field_Locks::get( $id ) : [],
			'enrich'   => array_column( $rows, 's', 'kind' ),
			'pictures' => (string) $pic,
		];
	}

	/** The product's description is still exactly what this module wrote (an edit since is never undone). */
	private static function content_still_ours( int $id ): bool {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT proposed_value FROM ' . Enrichment::table() . " WHERE product_id = %d AND kind = 'content' AND status = 'applied'", $id ) );
		return $v !== null && trim( (string) get_post_field( 'post_content', $id ) ) === trim( (string) $v );
	}

	/* ── The product page view (phase 3) ────────────────────────────────── */

	/**
	 * What the product page view swaps in: [ description => html|null, pictures => url[], meta => key => json ].
	 * Only values a write would make (fills, replacements, refreshes) and proposals (shown as if approved).
	 */
	public static function overrides( array $plan, string $only = '', array $tried = [] ): array { // $tried: parts being picked (VendorMaps::trial_parse()), shown with what the picks read even where a write would keep the product's own
		$f    = $plan['fields'] ?? [];
		$raw  = $plan['raw'] ?? [];
		$o    = [ 'description' => null, 'pictures' => [], 'meta' => [], 'shown' => [] ];
		$want = static fn( string $part ) => $only === '' || $only === $part;
		if ( $want( 'description' ) && ( in_array( $f['description']['action'] ?? '', self::SHOWN, true ) || isset( $tried['description'] ) ) && ( $raw['description'] ?? '' ) !== '' ) {
			$o['description'] = wp_kses_post( $raw['description'] );
			$o['shown'][]     = 'description';
		}
		if ( $want( 'pictures' ) && ( in_array( $f['pictures']['action'] ?? '', self::SHOWN, true ) || isset( $tried['pictures'] ) ) && ! empty( $raw['pictures'] ) ) {
			$o['pictures'] = array_values( (array) $raw['pictures'] );
			$o['shown'][]  = 'pictures';
		}
		if ( class_exists( '\MMI_Product_Content_Registry' ) ) {
			$id  = (int) ( $plan['id'] ?? 0 );
			$add = ( $raw['req_plan']['platforms'] ?? '' ) === 'fill' ? (array) ( $raw['req']['platforms'] ?? [] ) : [];
			foreach ( [ 'mac' => 'requirements_mac', 'windows' => 'requirements_windows', 'linux' => 'requirements_linux' ] as $os => $concept ) {
				if ( $want( 'requirements' ) && ( in_array( $raw['req_plan'][ $os ] ?? '', [ 'fill', 'replace' ], true ) || isset( $tried['requirements'] ) ) && ! empty( $raw['req'][ $os ] ) ) {
					$o['meta'][ \MMI_Product_Content_Registry::canonical_key( $concept ) ] = wp_json_encode( $raw['req'][ $os ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					$o['shown'][] = 'requirements';
					$add[]        = $os; // requirements list their OS among the platforms
				}
			}
			// The OS icons as the write would leave them: the stored platforms plus the ones it adds.
			$now  = (array) \MMI_Product_Content_Registry::canonical_value( $id, 'platforms' );
			$plat = array_values( array_intersect( array_keys( \MMI_Product_Content_Registry::PLATFORM_LABELS ), array_merge( $now, array_map( 'strtolower', $add ) ) ) );
			$lock = class_exists( '\MMI_Pipeline_Field_Locks' ) && \MMI_Pipeline_Field_Locks::is_locked( $id, \MMI_Product_Content_Registry::canonical_key( 'platforms' ) );
			if ( $want( 'requirements' ) && $id > 0 && ! $lock && array_diff( $plat, $now ) ) {
				$o['meta'][ \MMI_Product_Content_Registry::canonical_key( 'platforms' ) ] = wp_json_encode( $plat );
				$o['shown'][] = 'requirements';
			}
			foreach ( VendorMaps::FILLS as $part => $concept ) {
				if ( $want( $part ) && ( ( $raw['fills'][ $part ]['action'] ?? '' ) === 'fill' || ( isset( $tried[ $part ] ) && ! empty( $raw['fills'][ $part ]['value'] ) ) ) ) {
					$v = $raw['fills'][ $part ]['value'];
					$o['meta'][ \MMI_Product_Content_Registry::canonical_key( $concept ) ] = is_string( $v ) ? $v : wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // a text concept is stored as text
					$o['shown'][] = $part;
				}
			}
		}
		$o['shown'] = array_values( array_unique( $o['shown'] ) );
		return $o;
	}

	/** A product page asked for with this module's nonce or signed link, by someone who may: rendered with the map's values (see the class doc). */
	public static function maybe_preview(): void {
		if ( empty( $_GET[ self::QUERY ] ) || is_admin() || ! is_singular( 'product' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the nonce is checked below
			return;
		}
		$id    = (int) get_queried_object_id();
		$nonce = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY ] ) );
		$host  = sanitize_text_field( wp_unslash( (string) ( $_GET[ self::QUERY_HOST ] ?? '' ) ) );
		if ( ! current_user_can( Module::CAP ) || ! self::key_ok( $nonce, $id ) ) {
			return; // anyone else gets the product page as it is
		}
		$part = sanitize_key( wp_unslash( (string) ( $_GET[ self::QUERY_PART ] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked above
		$part = in_array( $part, self::PARTS, true ) ? $part : '';
		$try  = VendorMaps::trial_parse( (string) base64_decode( strtr( sanitize_text_field( wp_unslash( (string) ( $_GET[ self::QUERY_TRY ] ?? '' ) ) ), '-_', '+/' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.PHP.DiscouragedPHPFunctions -- checked above; base64url of the editor's own JSON
		$plan = VendorMaps::with_trial( $try, static fn() => self::plan( $host, $id, true ) );
		if ( ! $plan ) {
			return;
		}
		self::apply( $id, self::overrides( $plan, $part, $try ), $plan );
		if ( $part !== '' ) {
			self::mark( $part );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, private', true );
		header( 'CDN-Cache-Control: no-store', true );
		header( 'Cloudflare-CDN-Cache-Control: no-store', true );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		add_filter( 'show_admin_bar', '__return_false', 99 );
	}

	/**
	 * One part's preview: the template's elements that show it wrapped in a marker (display: contents, so the layout
	 * is untouched), which preview.js outlines and scrolls to; the label says when the page doesn't show it.
	 */
	private static function mark( string $part ): void {
		$on    = self::ON_PAGE[ $part ] ?? [ 'label' => ucfirst( $part ), 'names' => [], 'concepts' => [] ];
		$types = array_map( static fn( $c ) => 'mmi_spec_' . $c, $on['concepts'] );
		add_filter(
			'bricks/frontend/render_element',
			static function ( $html, $el ) use ( $on, $types, $part ) {
				$name = (string) ( $el->name ?? '' );
				$type = (string) ( $el->settings['query']['objectType'] ?? '' );
				if ( in_array( $name, $on['names'], true ) || ( $type !== '' && in_array( $type, $types, true ) ) ) {
					return '<div class="mmpt-pv-part" data-mmpt-part="' . esc_attr( $part ) . '">' . $html . '</div>';
				}
				return $html;
			},
			99,
			2
		);
		wp_enqueue_script( 'mmpt-preview', PageEvents::asset_url( 'js/preview.js' ), [], PageEvents::asset_ver( 'js/preview.js' ), true );
		wp_localize_script(
			'mmpt-preview',
			'mmptPreview',
			[
				'part'    => $part,
				'label'   => $on['label'],
				'missing' => isset( self::ON_PAGE[ $part ] )
					? sprintf( /* translators: %s: part */ __( '%s: not shown on this product’s page', 'mmi-data-pipeline' ), $on['label'] )
					: sprintf( /* translators: %s: part */ __( '%s: the product page doesn’t show this part', 'mmi-data-pipeline' ), $on['label'] ),
			]
		);
	}

	/** The request's swaps (see overrides()), for product $id only. */
	private static function apply( int $id, array $o, array $plan ): void {
		$post = get_post( $id );
		if ( $o['description'] !== null && $post ) {
			$orig = trim( (string) $post->post_content );
			$new  = $o['description'];
			add_filter( 'post_content', static fn( $v, $pid = 0 ) => (int) $pid === $id ? $new : $v, 99, 2 ); // get_post_field() for display (Bricks' Product content)
			add_filter( 'the_content', static fn( $c ) => (int) get_the_ID() === $id && trim( (string) $c ) === $orig ? $new : $c, 1 ); // WooCommerce's description tab
		}
		if ( $o['pictures'] ) {
			$fake = [];
			foreach ( $o['pictures'] as $i => $url ) {
				$fake[ self::FAKE_BASE + $i ] = $url;
			}
			$ids   = array_keys( $fake );
			$first = (int) array_shift( $ids );
			$mine  = static fn( $product ) => is_object( $product ) && method_exists( $product, 'get_id' ) && (int) $product->get_id() === $id;
			add_filter( 'woocommerce_product_get_image_id', static fn( $v, $product = null ) => $mine( $product ) ? $first : $v, 99, 2 );
			add_filter( 'woocommerce_product_get_gallery_image_ids', static fn( $v, $product = null ) => $mine( $product ) ? $ids : $v, 99, 2 );
			add_filter( 'post_thumbnail_id', static fn( $v, $p = null ) => ( $p instanceof \WP_Post ? $p->ID : (int) $p ) === $id ? $first : $v, 99, 2 );
			add_filter( 'image_downsize', static fn( $out, $att = 0 ) => isset( $fake[ (int) $att ] ) ? [ $fake[ (int) $att ], self::FAKE_SIZE, self::FAKE_SIZE, false ] : $out, 1, 2 );
			add_filter( 'wp_get_attachment_image_srcset', static fn( $v, $size = null, $src = null, $meta = null, $att = 0 ) => isset( $fake[ (int) $att ] ) ? false : $v, 99, 5 );
			add_filter( 'wp_get_attachment_image_attributes', static function ( $attr, $att = null ) use ( $fake ) {
				if ( is_object( $att ) && isset( $fake[ (int) $att->ID ] ) || is_numeric( $att ) && isset( $fake[ (int) $att ] ) ) {
					$attr['referrerpolicy'] = 'no-referrer';
				}
				return $attr;
			}, 99, 2 );
		}
		if ( $o['meta'] ) {
			$meta = $o['meta'];
			add_filter( 'get_post_metadata', static fn( $v, $oid, $key, $single ) => (int) $oid === $id && isset( $meta[ $key ] ) ? ( $single ? [ $meta[ $key ] ] : [ $meta[ $key ] ] ) : $v, 99, 4 );
			if ( class_exists( '\MMI_Product_Content_Registry' ) ) {
				\MMI_Product_Content_Registry::forget( $id );
			}
		}
		wp_enqueue_style( 'mmpt-preview', PageEvents::asset_url( 'css/preview.css' ), [], PageEvents::asset_ver( 'css/preview.css' ) );
		$label = sprintf( /* translators: %s: vendor site */ __( 'Preview with the %s map: nothing is saved', 'mmi-data-pipeline' ), $plan['page']['status'] === 'ok' ? VendorMaps::host( (string) $plan['url'] ) : '' );
		add_action( 'wp_body_open', static function () use ( $label ) {
			echo '<div class="mmpt-preview-label" role="status">' . esc_html( $label ) . '</div>';
		} );
	}

	/** The product's fields as they are, when its page can't be read. */
	private static function current_only( int $id, ?\WP_Post $post ): array {
		return [
			'title'        => [ 'current' => [ 'text' => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ] ],
			'description'  => [ 'current' => self::text_now( $post ), 'action' => 'no_page' ],
			'pictures'     => [ 'current' => [ 'images' => self::images_now( $id ) ], 'action' => 'no_page' ],
			'requirements' => [ 'current' => self::requirements_now( $id ), 'action' => 'no_page' ],
			'features'     => [ 'current' => [ 'items' => self::items_now( $id, 'features' ) ], 'action' => 'no_page' ],
			'manual'       => [ 'current' => [ 'items' => self::items_now( $id, 'manuals' ) ], 'action' => 'no_page' ],
			'videos'       => [ 'current' => [ 'items' => self::items_now( $id, 'videos' ) ], 'action' => 'no_page' ],
			'audio'        => [ 'current' => [ 'items' => self::items_now( $id, 'audio' ) ], 'action' => 'no_page' ],
			'version'      => [ 'action' => 'no_field' ],
		];
	}

	/* ── Each field ─────────────────────────────────────────────────────── */

	/**
	 * Enrichment's rule on a Scrape (generate()): thin → a proposal from the page; this module's own write → refreshed;
	 * a full description that isn't the maker's → the maker's words replace it, once `content:maker_replaces` is approved
	 * (not one this module applied, a person reverted, or a refresh waiting); else kept.
	 */
	private static function description( int $id, ?\WP_Post $post, string $page_html ): array {
		global $wpdb;
		$now      = self::text_now( $post );
		$proposed = [ 'html' => wp_kses_post( $page_html ), 'chars' => mb_strlen( Descriptions::plain( $page_html ) ) ];
		$was      = $wpdb->get_row( $wpdb->prepare( 'SELECT status, proposed_value, detail FROM ' . Enrichment::table() . " WHERE product_id = %d AND kind = 'content'", $id ), ARRAY_A );
		$ours     = $was && $was['status'] === 'applied' && $post && trim( (string) $post->post_content ) === trim( (string) $was['proposed_value'] );
		$decided  = $was && ( in_array( $was['status'], [ 'applied', 'reverted' ], true ) || strpos( (string) $was['detail'], 'refresh' ) !== false );
		if ( ! $ours && $now['chars'] >= Enrichment::THIN_CHARS ) {
			$maker  = ! empty( Enrichment::rules()['content:maker_replaces']['approved'] ) && ! $decided && $proposed['chars'] >= Enrichment::THIN_CHARS;
			$action = $maker && Descriptions::plain( $page_html ) !== Descriptions::plain( (string) ( $post ? $post->post_content : '' ) ) ? 'maker' : 'kept';
		} elseif ( $proposed['chars'] < Enrichment::THIN_CHARS ) {
			$action = 'too_little'; // Enrichment then tries XChange web assets and Plugivery
		} elseif ( $ours ) {
			$action = Descriptions::plain( $page_html ) === Descriptions::plain( (string) $post->post_content ) ? 'same' : 'refresh'; // the same words: nothing to rewrite
		} else {
			$action = ! empty( Enrichment::rules()['content:vendor_page']['approved'] ) ? 'fill' : 'proposal';
		}
		return [ 'current' => $now, 'proposed' => $proposed, 'action' => $action ];
	}

	/**
	 * Pictures::plan(): vendor → first pictures (none now) or the page's pictures merged in (PictureMatch::merge(),
	 * each picture's decision: current[].kind, merge.final, merge.page); in_place → own pictures re-saved; skip → kept.
	 */
	private static function pictures( int $id, array $plan, array $page_images, ?array $merge ): array {
		$now  = self::images_now( $id );
		$used = $plan['mode'] === 'vendor';
		$urls = $used ? (array) $plan['urls'] : array_map( static fn( $i ) => (string) $i['url'], $page_images );
		$out  = [
			'current'  => [ 'images' => $now ],
			'proposed' => [ 'images' => array_values( array_unique( array_filter( $urls ) ) ), 'found' => count( $page_images ), 'used' => $used ],
			'action'   => $used ? ( $now ? 'replace' : 'fill' ) : ( $plan['mode'] === 'in_place' ? 'in_place' : 'kept' ),
			'why'      => (string) $plan['reason'], // Pictures::REASONS: page_one_picture, page_low_res, person_picked, vendor_done…
		];
		if ( $merge ) {
			$cur = array_column( $merge['current'], null, 'att' );
			// key: the picture's fingerprint (the same picture on other products); choice: the person's choice that decided it
			$out['current']['images'] = array_map( static fn( $i ) => $i + [ 'kind' => (string) ( $cur[ $i['id'] ]['kind'] ?? '' ), 'key' => (string) ( $cur[ $i['id'] ]['key'] ?? '' ), 'choice' => (int) ( $cur[ $i['id'] ]['choice'] ?? 0 ) ], $now );
			$out['merge']             = [
				'final'    => array_map( static fn( $f ) => [ 'url' => isset( $f['att'] ) ? (string) wp_get_attachment_image_url( (int) $f['att'], 'medium' ) : (string) $f['url'], 'src' => (string) ( $f['url'] ?? '' ), 'kind' => $f['kind'], 'att' => (int) ( $f['att'] ?? 0 ), 'of' => (int) ( $f['of'] ?? 0 ), 'key' => (string) ( $f['key'] ?? '' ), 'choice' => (int) ( $f['choice'] ?? 0 ) ], $merge['final'] ), // att / of: which current picture it is, for the review's Now / After columns; src: a page picture's address
				'page'     => array_map( static fn( $p ) => [ 'url' => $p['url'], 'kind' => $p['kind'], 'key' => (string) ( $p['key'] ?? '' ), 'choice' => (int) ( $p['choice'] ?? 0 ), 'doubt' => ! empty( $p['doubt'] ) ], $merge['page'] ),
				'featured' => (int) $merge['featured'],
				'choices'  => (object) array_column( array_map( static fn( $r ) => [ $r['id'], $r['scope'] ], PictureChoices::for_product( $id ) ), 1, 0 ), // choice id → product | site, for Undo's words
				'counts'  => $merge['counts'],
				'doubt'   => $merge['doubt'],
				'summary' => PictureMatch::summary( $merge ),
			];
			$out['action'] = ! $now ? 'fill' : ( $merge['changed'] ? 'merge' : 'kept' );
			$out['why']    = $now && ! $merge['changed'] ? 'nothing_to_add' : '';
		}
		return $out;
	}

	/** PageRequirements::plan() per platform; the page's values as PageRequirements::extract() reads them. */
	private static function requirements( int $id, ?array $req, array $plan ): array {
		$proposed = [];
		foreach ( [ 'mac', 'windows', 'linux' ] as $os ) {
			if ( ! empty( $req[ $os ] ) ) {
				$proposed[ $os ] = (array) $req[ $os ];
			}
		}
		return [
			'current'  => self::requirements_now( $id ),
			'proposed' => $proposed + [ 'platforms' => array_values( (array) ( $req['platforms'] ?? [] ) ) ],
			'action'   => $plan ? $plan : 'none', // per platform; 'none' when the page names no requirements
		];
	}

	/**
	 * Features, manuals, videos: VendorMaps::plan_fill() for a mapped part; otherwise what the generic reader sees (nothing
	 * writes it). $mapped: the map has the part, but it reads nothing on this page (`mapped_none`, not "map this part").
	 */
	private static function filled( int $id, string $part, string $concept, array $fills, array $generic, bool $mapped = false ): array {
		$now = self::items_now( $id, $concept );
		if ( isset( $fills[ $part ] ) ) {
			$v = array_map( static fn( $i ) => is_array( $i ) ? (string) ( $i['label'] ?? '' ) . ( isset( $i['value'] ) ? ' ' . $i['value'] : '' ) : (string) $i, (array) $fills[ $part ]['value'] );
			return [ 'current' => [ 'items' => $now ], 'proposed' => [ 'items' => array_slice( array_map( 'trim', $v ), 0, self::MAX_ITEMS ) ], 'action' => $fills[ $part ]['action'] ];
		}
		$g = array_map( static fn( $i ) => is_array( $i ) ? trim( (string) ( $i['label'] ?? '' ) . ' ' . (string) ( $i['url'] ?? $i['value'] ?? '' ) ) : (string) $i, $generic );
		return [ 'current' => [ 'items' => $now ], 'proposed' => [ 'items' => array_slice( array_values( array_filter( $g ) ), 0, self::MAX_ITEMS ) ], 'action' => $part === 'features' ? 'no_list' : ( $mapped ? 'mapped_none' : 'not_mapped' ) ]; // features: the description's lists (VendorMaps::NOT_MAPPED)
	}

	/* ── The product now ────────────────────────────────────────────────── */

	private static function text_now( ?\WP_Post $post ): array {
		$html = $post ? (string) $post->post_content : '';
		return [ 'html' => wp_kses_post( $html ), 'chars' => mb_strlen( Descriptions::plain( $html ) ) ];
	}

	/** @return array<int,array{id:int,url:string}> featured first */
	private static function images_now( int $id ): array {
		return array_map( static fn( $a ) => [ 'id' => $a, 'url' => (string) wp_get_attachment_image_url( $a, 'medium' ) ], Pictures::current( $id ) );
	}

	private static function requirements_now( int $id ): array {
		$out = [];
		if ( class_exists( '\MMI_Product_Content_Registry' ) ) {
			foreach ( [ 'mac' => 'requirements_mac', 'windows' => 'requirements_windows', 'linux' => 'requirements_linux' ] as $os => $concept ) {
				$v = \MMI_Product_Content_Registry::canonical_value( $id, $concept );
				if ( $v ) {
					$out[ $os ] = (array) $v;
				}
			}
		}
		return $out;
	}

	/** @return string[] */
	private static function items_now( int $id, string $concept ): array {
		if ( ! class_exists( '\MMI_Product_Content_Registry' ) ) {
			return [];
		}
		$items = array_map( static fn( $i ) => trim( (string) ( $i['label'] ?? '' ) . ' ' . (string) ( $i['text'] ?? $i['value'] ?? '' ) ), \MMI_Product_Content_Registry::get_items( $id, $concept ) );
		return array_slice( array_values( array_filter( $items ) ), 0, self::MAX_ITEMS );
	}
}
