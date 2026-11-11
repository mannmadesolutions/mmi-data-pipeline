<?php
/**
 * The MMI Product Data Chrome extension's side on the server (docs/VENDOR_BROWSER_EXTENSION_PLAN.md; owner
 * 2026-11-01: "Use Chrome for everything", mapping starts with a product, the live browser plan shelved).
 *
 * The extension is a dumb agent in the owner's Chrome: on a maker's page it draws a panel this class describes
 * (panel()), and sends the page as the browser shows it to be stored (snapshot()). Everything that decides
 * anything stays here: storing (PageStore), reading, Save's guards, writing products.
 *
 * Product first (phases 2–3): the panel shows one product's before/after on this page (ProductPreview::plan(), the
 * map editor's own preview), with Write to this product (ProductPreview::write_start(), Undo), picking a part on the
 * page (the live mapper, assets/js/live-mapper.js, bundled into the download with map-select.js and talking to
 * LiveMapper through /ext/map), and pictures and videos picked on the page (Pictures::pick(): the runner's normal
 * merge, add-only). Every Test, Save and Write sends the page as drawn, stored by the keep-better-copy rule.
 *
 * Moving through a site (protocol 3): a page picture can be vetoed for the product from its thumbnail
 * (PictureChoices), the page can be linked to a product of the site's brands (UrlQueue::set_one(), a verified
 * page replaced only on a second click, Undo), Next product with work on this site opens the next product's page
 * in the same tab (SiteGaps::matrix()), and Send this site sends a site that refuses the server from this tab
 * (Capture's calls through /ext/send).
 *
 * Auth: a per-browser token from pairing (POST /ext/pair, cookie + nonce on the Extension page). Only a hash is kept
 * (user meta META). The extension sends it in the X-MMI-Ext-Token header, and it signs in only on the token routes
 * (TOKEN_ROUTES); everywhere else it is ignored. The extension sends no cookies (fetch credentials: 'omit'), so the
 * login cookie and its nonce check never meet the token.
 *
 * REST (Rest::NS):
 *   POST /ext/pair {label}          cookie: a new token for this browser (shown once)
 *   GET  /ext/tokens                cookie: this person's connected browsers
 *   POST /ext/revoke {id}           cookie: disconnect one
 *   POST /ext/hello                 token:  who is connected, the protocol
 *   POST /ext/page {url}            token:  the panel for a maker's page
 * The token routes are POST only: nginx and Cloudflare never cache a POST, and these requests carry no login cookie
 * for their caches' logged-in bypass to see.
 *   POST /ext/snapshot {url, html, dry}  token: store the page as drawn (dry: check only)
 *   POST /ext/map {url, op, …}            token: the live mapper's hello / test / save (LiveMapper); op skip {lines}: lines
 *        the site's description reader leaves out (MapCheck::set_text_skips())
 *   POST /ext/write {url, product, expect, html, dry}  token: store the page, then Write to this product
 *   POST /ext/undo {url, product, what}   token: undo the last write, picture run or videos added
 *   POST /ext/pictures {url, product, pictures, videos, html, dry}  token: picked pictures and videos to the product
 *        op check {url, product, pictures}: which of these pictures the product has already (the merge's own rules)
 *   POST /ext/veto {url, product, picture}  token: a page picture never goes on this product (PictureChoices)
 *   POST /ext/products {url, q}            token: products of the site's brands, to link this page to
 *   POST /ext/link {url, product, confirm, html, dry}  token: this page becomes the product's vendor URL
 *   POST /ext/next {url, product}          token: the site's next product with work on its page
 *   POST /ext/held {url, product}          token: the product in hand as this store shows it (reads only)
 *   POST /ext/scrape {url, product, dry}   token: from our product page, its linked page read again; answers with what it gives
 *   POST /ext/send {url, op, …}            token: Send this site (op site / list / page / queue: Capture's routes)
 *   POST /ext/layout {url, op, part, …}    token: the page's layout's own part (plan §13 S2b): op plan (one slice of
 *        PartFill::layout_plan(), kept), write (to the layout's products the plan writes to, Undo), undo, confirm
 * dry=1 (the browser check's copy of the extension) on snapshot, write, pictures, veto, link, layout's write and send's
 * list / page / queue runs every check and changes nothing.
 *
 * A layout's picks (protocol 5, plan §13 S2b): on a page of a layout with 2+ pages the panel shows that layout's own
 * picks, how many of its pages each reads, and on request what writing it does to the layout's products, with Write,
 * Undo and Confirm. The product's heading carries Preview on the store (ProductPreview::signed_url(): the product
 * page with what this page gives it) and Open on the store.
 *
 * Protocol 6 (owner 2026-11-02, plan §13 F3): each before/after row opens picking for its part (ROW_PICK): the live
 * mapper on that part, or the picker for Pictures, Videos and Audio demos, in place of the two Pick buttons. Audio
 * demos (SoundCloud tracks and sets, named by SoundCloud's oEmbed) are a row, and the picker adds them add-only, Undo.
 * Headers: X-MMI-Ext-Protocol (PROTOCOL), X-MMI-Ext-Build (build(): the download it came from; an older one is told
 * to update), X-MMI-Ext-Version (the extension's version, kept with its key: a newer one disconnects this person's
 * browsers on older versions, retire_older()).
 * Download: admin-post.php?action=mmpt_ext_download (nonce): the extension folder as a zip.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExtBridge {

	const SLUG         = 'mmi-product-extension'; // mmi- prefix → the shared library adds body.mmi-page and the suite styling
	const PROTOCOL     = 10;                      // the panel description and routes the extension speaks (EXT_PROTOCOL in sw.js)
	const MIN_PROTOCOL = 1;                       // the oldest extension still answered
	const META         = '_mmpt_ext_tokens';      // [ id => { hash, label, created, used } ]
	const HEADER       = 'X-MMI-Ext-Token';
	const MAX_TOKENS   = 5;                       // per person; the oldest goes first
	const IDLE_DAYS    = 90;                      // a token unused this long stops working
	const TOKEN_ROUTES = [ 'hello', 'page', 'snapshot', 'map', 'write', 'undo', 'pictures', 'veto', 'products', 'link', 'next', 'send', 'layout', 'site', 'example', 'disclaimer', 'mapping', 'sitepart', 'held', 'scrape' ];
	const MAX_PRODUCTS = 20;                      // product rows in one panel
	const HELD_WORDS   = 40;                      // words of the product's text on the Finding the page card
	const SAME_TEXT    = 120;                     // opening characters of a description that must be on a page to count as its text
	const BUNDLED      = [ 'map-select.js', 'live-mapper.js' ]; // assets/js files the download carries
	const JOB_SHOWN    = 900;                     // seconds after a write that our product page still opens on it, with its answer
	const POLL_MS      = 4000;                    // the panel asks again this often while a write or picture run is under way
	const MAX_VIDEOS   = 20;
	const VIDEOS_META  = '_mmpt_ext_videos';      // videos the extension added to a product last { added[], at }, for Undo
	const AUDIO_META   = '_mmpt_ext_audio';       // audio demos the extension added to a product last { added[] keys, at }, for Undo
	const MAX_AUDIO    = 20;
	/** Protocol 6: a before/after row opens picking for its part (owner 2026-11-02: the rows, not buttons). Features: the generic reader only (VendorMaps::NOT_MAPPED). */
	const ROW_PICK     = [ 'Description' => [ 'map', 'description' ], 'Pictures' => [ 'pictures', 'pictures' ], 'System requirements' => [ 'map', 'requirements' ], 'Manuals' => [ 'map', 'manual' ], 'Videos' => [ 'pictures', 'videos' ], 'Audio demos' => [ 'pictures', 'audio' ], 'Disclaimer' => [ 'map', 'disclaimer' ] ];
	/** Protocol 7: each row is a part with its criteria (the pick that reads it), committed to this product or the layout's products. */
	const ROW_PART     = [ 'Description' => 'description', 'Pictures' => 'pictures', 'System requirements' => 'requirements', 'Features' => 'features', 'Manuals' => 'manual', 'Videos' => 'videos', 'Audio demos' => 'audio', 'Disclaimer' => 'disclaimer' ];
	const DISCLAIMER_META = '_mmpt_ext_disclaimer'; // the disclaimer before a person typed one here { was, now, at }, for Undo
	const DISCLAIMER_MAX  = 1000;
	const PAGE_OK      = '_mmpt_page_ok';        // the address a person said is the product's own page (it leaves the check until the address changes)
	/** UrlReview's rating as a person reads it. It is evidence, not a verdict: right pages with short titles rate low too. */
	const MATCH_FINE   = 70;                      // from here it looks right
	const MATCH_DOUBT  = 40;                      // under this it is doubtful
	const MATCH_LIST   = 60;                      // under this it is on the site's check
	const MATCH_ROWS   = 200;
	const MATCH_WAIT_S = 12;                      // ratings worked out in one look; the rest are queued
	const LIKELY_MAX   = 3;                       // pages suggested for a product
	/** Words an address carries that name nothing. */
	const SLUG_STOP    = [ 'for', 'the', 'and', 'by', 'of', 'with', 'in', 'to', 'a', 'an', 'html', 'php', 'en', 'us', 'product', 'products' ];
	const VETOES_META  = '_mmpt_ext_vetoes';      // pictures vetoed from the panel { rules[], at }, for Undo
	const LINK_META    = '_mmpt_ext_link';        // the page this panel linked the product to { url, was, was_verified, at }, for Undo
	const UNDO_DAYS    = 1;                       // a link's Undo is offered this long
	const SEARCH_MAX   = 12;
	const CHECK_MAX    = 60;                      // page pictures one picker check compares with the product's
	const SITE_CACHE_S = 600;                     // the site section, per site and map stamp (plan §13 S1)
	const SITE_POLL_MS = 8000;                    // the panel asks again this often while the site's map is started or its layouts grouped
	const LAYOUT_S     = HOUR_IN_SECONDS;         // a layout part's plan (what writing it does), per pick and stored pages
	const REVIEW_MAX   = 300;                     // products a review names per outcome
	const EXAMPLE_CHARS = 6000;                   // text an example shows per side
	const EXAMPLE_PICS  = 40;                     // pictures an example shows per side
	/**
	 * PartFill's decision for a product, as a person reads it (owner 2026-11-04: the report's language was "ambiguous at
	 * best"): [ tone, name, what it means, what to do ]. change: a commit writes it. look: it won't reach the product, and
	 * a person may want to know why. same: nothing needed. %s: the part, in lower case.
	 */
	const OUTCOMES = [
		'fill'          => [ 'change', 'Filled in', 'They have no %s now, so they get what their page gives.' ],
		'refresh'       => [ 'change', 'Rewritten', 'Their %s came from this site before. The new reading replaces it.' ],
		'maker'         => [ 'change', 'Replaced by the maker’s words', 'Their %s is other text. The maker’s own text replaces it.' ],
		'merge'         => [ 'change', 'Added to', 'They keep what they have, and get what they don’t have yet from their page.' ],
		'replace'       => [ 'change', 'Replaced', 'What their page gives replaces their %s.' ],
		'review'        => [ 'change', 'Sent to Review', 'Nothing changes on the product yet: a proposal waits in the Product Workbench’s proposals.' ],
		'not_read'      => [ 'look', 'Pick finds nothing on their pages', 'They keep what they have.', 'Open one of these pages, click the part in the panel and add to the pick.' ],
		'taken_once'    => [ 'look', 'Pictures taken from their page once already', 'A page’s pictures are taken only once, so these get nothing new from this pick.', 'To add more to one product, click Pictures on its page, then pick them for that product only.' ],
		'other_version' => [ 'look', 'Their page is about another version', 'They keep what they have.', 'If the page is the wrong one, link the right page to the product.' ],
		'in_place'      => [ 'look', 'Their page’s pictures can’t be used', 'Too few or too small, so they keep what they have.' ],
		'too_little'    => [ 'look', 'Too little text on their pages', 'The pick reads too little to use, so they keep what they have.', 'Check that the pick is on the text itself.' ],
		'no_page'       => [ 'look', 'No stored copy of their page', 'The store has nothing to read for them.', 'Open the page and use Store this page.' ],
		'no_card'       => [ 'look', 'Their page doesn’t name them', 'The page lists several products and none of its cards names this one.', 'Link a page of its own to the product, if it has one.' ],
		'same'          => [ 'same', 'Already the same', 'Their page gives exactly what they have.' ],
		'general_same'  => [ 'same', 'Already right without the pick', 'The pick doesn’t reach their pages, but the general reader finds nothing different from what they have. Nothing to do.' ],
		'kept'          => [ 'same', 'Keep their own', 'Product Data fills gaps and refreshes its own copy. It doesn’t replace what these have.' ],
		'person_picked' => [ 'same', 'A person chose their pictures', 'Those choices stay.' ],
		'locked'        => [ 'same', 'Locked', 'A person locked this field, so it is never written.' ],
		'skip'          => [ 'same', 'Left alone', 'Not published, excluded, or a person wrote their copy.' ],
		'none'          => [ 'same', 'Nothing to do', 'Their page gives nothing to write.' ],
	];
	/** A part's status on the product, in words (owner 2026-11-04: the coloured dots said neither what they meant nor what to do): tone, word, what it means and what to do. */
	const STATUS = [
		'add'     => [ 'kind' => 'change', 'word' => 'Will change', 'help' => 'Ticked, so a commit writes it. Untick it to leave it out.' ],
		'ok'      => [ 'kind' => 'ok', 'word' => 'No change needed', 'help' => 'The product has it and this page gives nothing new. Nothing to do.' ],
		'missing' => [ 'kind' => 'missing', 'word' => 'Missing', 'help' => 'The product has none, and nothing is read from this page. If it’s on the page, click the part and pick it.' ],
		'warn'    => [ 'kind' => 'review', 'word' => 'Goes to Review', 'help' => 'A commit sends a proposal to the Product Workbench’s proposals. The product doesn’t change until it’s approved.' ],
		'old'     => [ 'kind' => 'review', 'word' => 'From another page', 'help' => 'It was read from a page that is no longer this product’s. Check it, and correct it on the product’s edit screen if it’s wrong.' ],
		'auto'    => [ 'kind' => 'ok', 'word' => 'Automatic', 'help' => 'Done when the product page is shown. Nothing to commit.' ],
		'doubt'   => [ 'kind' => 'review', 'word' => 'Page in doubt', 'help' => 'Settle the page first: it may not be this product’s.' ],
		'away'    => [ 'kind' => 'review', 'word' => 'Kept on another page', 'help' => 'The page it’s on has to be stored, by you, with the part that applies picked. The server can’t read it, or hasn’t yet.' ],
		'copied'  => [ 'kind' => 'review', 'word' => 'Text from this page', 'help' => 'The product’s description is on this page. If this page is another product’s, that text is wrong too: use the right page, then commit Description from there to replace it.' ],
	];
	/** Gap cells (SiteGaps) that mean work on the product's page: a write fills it (A), the page shows it and nothing reads it (S), only a mapped part writes it (P). */
	const WORK_CELLS   = [ 'A' => 'to write', 'S' => 'on the page, nothing reads it', 'P' => 'on the page, needs a part' ];
	const WORK_FIELDS  = [ 'images', 'description', 'features', 'os', 'manual', 'videos' ];
	const THUMBS       = 6;
	const SKIPS        = [
		'not_published' => 'It isn’t published, so Product Data leaves it alone.',
		'excluded'      => 'It is excluded in Rules, or not sold, so Product Data leaves it alone.',
		'manual'        => 'A person wrote its copy, so its page is never read over it.',
		'no_page'       => 'It is marked as having no vendor page.',
	];
	/** Pages that may hold the owner's own account details on a maker's site: never stored. */
	const PRIVATE_PATH = '#/(my-?account|account|login|log-in|signin|sign-in|register|cart|basket|checkout|wishlist|orders?)(/|$|\?)#i';
	/** Page-store statuses that mean the site refused or challenged the server (Capture::REFUSED's read side). */
	const REFUSED      = [ 'http_401', 'http_403', 'http_405', 'http_429', 'http_202', 'http_503' ];

	/** @var string|null 'ok' when this request signed in with a token, 'bad' when it sent one that failed */
	private static $auth = null;
	/** @var bool the token is being checked: WordPress asks who the user is again from inside (never re-entered) */
	private static $checking = false;
	/** @var array<string,string[]> a site's page list, for this request */
	private static $pages = [];
	/** @var array|null browsers retire_older() disconnected this request, written to the audit log once the user is known */
	private static $retired = null;

	public static function register_hooks(): void {
		add_filter( 'determine_current_user', [ __CLASS__, 'user_from_token' ], 20 );
		add_filter( 'rest_authentication_errors', [ __CLASS__, 'auth_errors' ], 90 );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'admin_post_mmpt_ext_download', [ __CLASS__, 'download' ] );
		if ( is_admin() ) {
			add_action( 'admin_menu', [ __CLASS__, 'menu' ], 32 );
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		}
	}

	/* ── Auth ───────────────────────────────────────────────────────────── */

	/** The token's user, on the token routes only. Decided from the address, so it holds however early WordPress asks. */
	public static function user_from_token( $user ) {
		$token = isset( $_SERVER['HTTP_X_MMI_EXT_TOKEN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MMI_EXT_TOKEN'] ) ) : '';
		if ( $token === '' || self::$checking || ! self::token_route() ) {
			return $user;
		}
		self::$checking = true;
		try {
			$uid = self::check( $token );
		} finally {
			self::$checking = false;
		}
		self::$auth = $uid ? 'ok' : 'bad';
		return $uid ?: $user;
	}

	/** A refused token is an error, not a quiet sign-out; a good one needs no cookie nonce. */
	public static function auth_errors( $result ) {
		if ( self::$retired && class_exists( 'MMI_Audit_Log' ) ) { // the user is known now: the audit log asks who it is
			\MMI_Audit_Log::record( 'mmi-admin', 'extension.retire_older', [ 'object_type' => 'extension', 'object_id' => (string) self::$retired['keep'], 'details' => self::$retired ] );
			self::$retired = null;
		}
		if ( ! empty( $result ) || self::$auth === null ) {
			return $result;
		}
		if ( self::$auth === 'bad' ) {
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'extension.token_refused', [ 'object_type' => 'extension', 'object_id' => 'token' ] );
			}
			return new \WP_Error( 'mmpt_ext_token', 'This browser is no longer connected. Connect it again on the Chrome extension page.', [ 'status' => 401 ] );
		}
		return true;
	}

	/** This request is one of TOKEN_ROUTES (pretty or ?rest_route= form). */
	private static function token_route(): bool {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against a pattern
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : (string) wp_parse_url( $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only routing
		return (bool) preg_match( '#/' . preg_quote( Rest::NS, '#' ) . '/ext/(' . implode( '|', self::TOKEN_ROUTES ) . ')/?$#', $route );
	}

	/** The token's user id when it is current and the user may still use Product Data; 0 otherwise. Marks it used. */
	private static function check( string $token ): int {
		if ( ! preg_match( '/^mmx1\.(\d+)\.([a-f0-9]{8})\.([a-f0-9]{64})$/', $token, $m ) ) {
			return 0;
		}
		$uid  = (int) $m[1];
		$rows = self::tokens_of( $uid );
		$row  = $rows[ $m[2] ] ?? null;
		if ( ! $row || ! hash_equals( (string) $row['hash'], self::hash( $m[3] ) ) || ! user_can( $uid, Rest::CAP ) ) {
			return 0;
		}
		if ( strtotime( (string) ( $row['used'] ?: $row['created'] ) . ' UTC' ) < time() - self::IDLE_DAYS * DAY_IN_SECONDS ) {
			return 0;
		}
		$ver   = isset( $_SERVER['HTTP_X_MMI_EXT_VERSION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MMI_EXT_VERSION'] ) ) : '';
		$ver   = preg_match( '/^\d{1,4}(\.\d{1,4}){1,3}$/', $ver ) ? $ver : '';
		$was   = (string) ( $row['version'] ?? '' );
		$newer = $ver !== '' && ( $was === '' || version_compare( $ver, $was, '>' ) );
		$stamp = (string) $row['used'] === '' || strtotime( (string) $row['used'] . ' UTC' ) < time() - HOUR_IN_SECONDS; // last used, to the hour: one meta write an hour at most
		if ( $stamp || $newer ) {
			$rows[ $m[2] ]['used'] = $stamp ? gmdate( 'Y-m-d H:i:s' ) : (string) $row['used'];
			if ( $newer ) {
				$rows[ $m[2] ]['version'] = $ver;
				$rows                     = self::retire_older( $uid, $rows, $m[2], $ver );
			}
			update_user_meta( $uid, self::META, $rows );
		}
		return $uid;
	}

	/**
	 * A browser now runs extension $ver (connected, or updated in place): this person's other connections that run an
	 * older version, or never said which (a key the extension never took), are disconnected (owner 2026-11-02: "When the
	 * user installs a newer version of the extension, automatically disconnect all previously connected instances").
	 */
	private static function retire_older( int $uid, array $rows, string $keep, string $ver ): array {
		$gone = [];
		foreach ( $rows as $id => $t ) {
			$v = (string) ( $t['version'] ?? '' );
			if ( (string) $id !== $keep && ( $v === '' || version_compare( $v, $ver, '<' ) ) ) {
				unset( $rows[ $id ] );
				$gone[] = [ 'id' => (string) $id, 'version' => $v ];
			}
		}
		if ( $gone ) {
			self::$retired = [ 'keep' => $keep, 'user' => $uid, 'version' => $ver, 'disconnected' => $gone ]; // recorded by auth_errors(): here, inside the sign-in, asking who the user is would start the sign-in again
		}
		return $rows;
	}

	private static function hash( string $secret ): string {
		return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
	}

	/** @return array<string,array{hash:string,label:string,created:string,used:string}> */
	private static function tokens_of( int $uid ): array {
		$rows = get_user_meta( $uid, self::META, true );
		return is_array( $rows ) ? $rows : [];
	}

	/* ── Routes ─────────────────────────────────────────────────────────── */

	public static function routes(): void {
		$admin = static fn() => current_user_can( Rest::CAP );
		$token = static fn() => self::$auth === 'ok' && current_user_can( Rest::CAP );
		register_rest_route( Rest::NS, '/ext/pair', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'pair' ] ] );
		register_rest_route( Rest::NS, '/ext/tokens', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'tokens' ] ] );
		register_rest_route( Rest::NS, '/ext/revoke', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'revoke' ] ] );
		register_rest_route( Rest::NS, '/ext/hello', [ 'methods' => 'POST', 'permission_callback' => $token, 'callback' => [ __CLASS__, 'hello' ] ] );
		register_rest_route( Rest::NS, '/ext/page', [ 'methods' => 'POST', 'permission_callback' => $token, 'callback' => [ __CLASS__, 'page' ] ] );
		register_rest_route( Rest::NS, '/ext/snapshot', [ 'methods' => 'POST', 'permission_callback' => $token, 'callback' => [ __CLASS__, 'snapshot' ] ] );
		register_rest_route( Rest::NS, '/ext/map', [ 'methods' => 'POST', 'permission_callback' => $token, 'callback' => [ __CLASS__, 'map' ] ] );
		register_rest_route( Rest::NS, '/ext/write', [ 'methods' => 'POST', 'permission_callback' => $token, 'callback' => [ __CLASS__, 'write' ] ] );
		register_rest_route( Rest::NS, '/ext/undo', [ 'methods' => 'POST', 'permission_callback' => $token, 'callback' => [ __CLASS__, 'undo' ] ] );
		register_rest_route( Rest::NS, '/ext/pictures', [ 'methods' => 'POST', 'permission_callback' => $token, 'callback' => [ __CLASS__, 'pictures' ] ] );
		foreach ( [ 'veto', 'products', 'link', 'next', 'send', 'layout', 'site', 'example', 'disclaimer', 'mapping', 'sitepart', 'held', 'scrape' ] as $route ) {
			register_rest_route( Rest::NS, '/ext/' . $route, [ 'methods' => 'POST', 'permission_callback' => $token, 'callback' => [ __CLASS__, $route ] ] );
		}
	}

	/** POST /ext/pair {label}: a new token for the browser asking. The plain token is in this answer only. */
	public static function pair( \WP_REST_Request $r ): \WP_REST_Response {
		$uid    = get_current_user_id();
		$rows   = self::tokens_of( $uid );
		$id     = bin2hex( random_bytes( 4 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		$label  = mb_substr( sanitize_text_field( (string) $r->get_param( 'label' ) ), 0, 80 ) ?: 'Chrome';
		uasort( $rows, static fn( $a, $b ) => strcmp( (string) $a['created'], (string) $b['created'] ) );
		while ( count( $rows ) >= self::MAX_TOKENS ) {
			unset( $rows[ array_key_first( $rows ) ] ); // not array_shift(): it renumbers an all-digit id ("19347385" → 0), and that key stops working
		}
		$rows[ $id ] = [ 'hash' => self::hash( $secret ), 'label' => $label, 'created' => gmdate( 'Y-m-d H:i:s' ), 'used' => '' ];
		update_user_meta( $uid, self::META, $rows );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'extension.pair', [ 'object_type' => 'extension', 'object_id' => $id, 'details' => [ 'label' => $label ] ] );
		}
		Module::info( 'Chrome extension connected', [ 'id' => $id, 'label' => $label ] );
		return rest_ensure_response( [ 'token' => "mmx1.{$uid}.{$id}.{$secret}", 'id' => $id, 'protocol' => self::PROTOCOL, 'tokens' => self::tokens_out( $rows ) ] );
	}

	/** GET /ext/tokens: this person's connected browsers. */
	public static function tokens(): \WP_REST_Response {
		return rest_ensure_response( [ 'tokens' => self::tokens_out( self::tokens_of( get_current_user_id() ) ) ] );
	}

	/** POST /ext/revoke {id}: disconnect one browser. */
	public static function revoke( \WP_REST_Request $r ): \WP_REST_Response {
		$uid  = get_current_user_id();
		$id   = sanitize_key( (string) $r->get_param( 'id' ) );
		$rows = self::tokens_of( $uid );
		if ( ! isset( $rows[ $id ] ) ) {
			return new \WP_REST_Response( [ 'message' => 'That browser isn’t connected.', 'tokens' => self::tokens_out( $rows ) ], 404 );
		}
		unset( $rows[ $id ] );
		update_user_meta( $uid, self::META, $rows );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'extension.revoke', [ 'object_type' => 'extension', 'object_id' => $id ] );
		}
		return rest_ensure_response( [ 'tokens' => self::tokens_out( $rows ) ] );
	}

	/** Connected browsers as the Extension page lists them: no hashes. */
	private static function tokens_out( array $rows ): array {
		$out = [];
		foreach ( $rows as $id => $t ) {
			$out[] = [ 'id' => (string) $id, 'label' => (string) $t['label'], 'created' => (string) $t['created'], 'used' => (string) $t['used'], 'version' => (string) ( $t['version'] ?? '' ) ];
		}
		usort( $out, static fn( $a, $b ) => strcmp( $b['created'], $a['created'] ) );
		return $out;
	}

	/** POST /ext/hello: who this browser is connected as. */
	public static function hello( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$u = wp_get_current_user();
		return rest_ensure_response( [ 'user' => $u->display_name, 'site' => home_url(), 'protocol' => self::PROTOCOL, 'page' => admin_url( 'admin.php?page=' . self::SLUG ) ] );
	}

	/** An extension older than MIN_PROTOCOL is told to update instead of being half answered. */
	private static function too_old( \WP_REST_Request $r ): ?\WP_REST_Response {
		$p = (int) $r->get_header( 'x_mmi_ext_protocol' );
		return $p >= self::MIN_PROTOCOL ? null : new \WP_REST_Response( [ 'message' => self::update_help( 'This copy of the extension is too old.' ), 'update' => true ], 426 );
	}

	/* ── The panel ──────────────────────────────────────────────────────── */

	/** POST /ext/page {url, product}: the panel for this page, about one of its products when there is one. */
	public static function page( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		return rest_ensure_response( self::panel( (string) $r->get_param( 'url' ), (int) $r->get_param( 'product' ), self::client( $r ) + [ 'site' => (bool) $r->get_param( 'site' ), 'look' => (bool) $r->get_param( 'look' ) ] ) );
	}

	/** The extension asking: its protocol and the download it came from. @return array{protocol:int,build:string} */
	private static function client( \WP_REST_Request $r ): array {
		return [ 'protocol' => (int) $r->get_header( 'x_mmi_ext_protocol' ), 'build' => sanitize_key( (string) $r->get_header( 'x_mmi_ext_build' ) ) ];
	}

	/**
	 * The panel as data. Protocol 1: { title, subtitle, notices[{kind,text}], sections[{title, rows[{label, value,
	 * state, href}], text}], actions[{id, label, kind, primary, href}] }. Protocol 2 adds: a section's own `actions`
	 * and `busy` line, a row's `action` (clicking it) and `thumbs` (picture addresses), and `poll` (ask again in ms).
	 * The extension draws text as text and opens links only to this site. Action kinds: snapshot (store the page as
	 * drawn), open (href in a new tab), reload, and in protocol 2 focus {product}, write {product, expect}, undo
	 * {product, what}, map (the picker), pictures {product} (pick pictures and videos). Protocol 9 (owner 2026-11-04: the
	 * commit bar, the section's buttons and the footer "have competing actions"): one footer. A section's `commit` draws
	 * there and opens the review; `more` and the panel's `actions` that aren't primary go in its More list, each with a
	 * `help` line; `undo` [{text, action}] lists what can be put back; a row's `status` {kind, word, help} says its state
	 * in words, and `key` explains them. Protocol 10 (owner 2026-11-04: on one of our product pages, link and map in reverse):
	 * store_panel(), with a row's `brand`, `site` and a match's `store`, `empty`, `marked`, and the `hand` action.
	 * $product: the product to show (one that uses this page); 0 = the only one, when there is only one.
	 */
	public static function panel( string $raw, int $product = 0, array $client = [ 'protocol' => self::PROTOCOL, 'build' => '' ] ): array {
		$own = (int) $client['protocol'] >= 10 ? self::store_product( $raw ) : 0;
		if ( $own ) {
			return self::store_panel( $own, $raw, $client );
		}
		$url = self::page_url( $raw );
		if ( is_wp_error( $url ) ) {
			return [ 'title' => self::store_name(), 'subtitle' => '', 'notices' => [ [ 'kind' => 'error', 'text' => $url->get_error_message() ] ], 'sections' => [], 'actions' => [] ];
		}
		$host   = VendorMaps::host( $url );
		$same   = LiveMapper::same_page( $url );
		$ids    = array_values( array_unique( array_merge( [], ...array_values( $same ) ) ) );
		$propos = array_values( array_diff( self::proposed_for( $url ), $ids ) );
		$brands = self::brands_for( $host );
		$map    = VendorMaps::get( $host );
		$twin   = $same ? (string) array_key_first( $same ) : $url;
		$stored = PageStore::row( $twin ) ?? PageStore::row( $url );
		$known  = $ids || $propos || $brands || $map;
		$v2     = (int) $client['protocol'] >= 2;
		$v3     = (int) $client['protocol'] >= 3;
		$v4     = (int) $client['protocol'] >= 4;
		$focus  = in_array( $product, $ids, true ) ? $product : ( count( $ids ) === 1 ? (int) $ids[0] : SharedPages::by_anchor( $ids, (string) wp_parse_url( $raw, PHP_URL_FRAGMENT ) ) );
		if ( ! $ids && $v2 && ChoiceBundles::base_of( $url ) !== $url ) { // another page of a choice bundle's list of choices
			$listed = self::choice_list_panel( $url, $host, $product );
			if ( $listed ) {
				return $listed;
			}
		}

		$notices = [];
		$actions = [];
		self::say_stale( $client, $notices, $actions );
		$maker = ! $known ? self::requirements_for( $url ) : '';
		if ( $maker !== '' ) {
			$notices[] = [ 'kind' => 'info', 'text' => 'This page keeps ' . $maker . '’s system requirements, and may list many products. Pick the part that applies to ours: only that part is read, for all of them.' ];
		} elseif ( ! $known ) {
			$notices[] = [ 'kind' => 'info', 'text' => 'No product uses this page, and this site isn’t one of a brand’s maker sites. Add the site in its brand’s row in the Product Workbench’s Brands & sites to work on it here.' ];
		} elseif ( ! $ids && ! $propos ) {
			$notices[] = [ 'kind' => 'info', 'text' => 'No product uses this page as its vendor URL yet.' ];
		}
		if ( $stored && in_array( (string) $stored['status'], self::REFUSED, true ) ) {
			$notices[] = [ 'kind' => 'warn', 'text' => 'This site refuses the server, so the readers have nothing from this page. Store this page gives them the page as you see it.' ];
		}

		$sections = [];
		$poll     = 0;
		if ( $v2 && $focus ) {
			$sections[] = self::product_section( $host, $url, $focus, $poll, (int) $client['protocol'] >= 6, (int) $client['protocol'] >= 7 );
			$choice     = ChoiceBundles::section( $focus, $url ); // the buyer chooses what it holds: its list of choices
			if ( $choice ) {
				$sections[] = $choice;
			}
		}
		$layout = (int) $client['protocol'] >= 5 && $map && ! ( $focus && (int) $client['protocol'] >= 7 ) ? self::layout_section( $host, $url ) : null; // protocol 7: the product's commit bar commits to the layout
		if ( $layout ) {
			$sections[] = $layout;
		}
		$site = $v4 && ( $brands || $map ) && ( ! $focus || ! empty( $client['site'] ) ); // a home, listing or unused page, or asked for
		$here = $map ? MapTypes::layout_of( $host, $url ) : null; // owner 2026-11-03: say which layout the page is part of
		$page = [
			'title' => 'This page',
			'rows'  => array_values(
				array_filter(
					[
						$v4 && ( $brands || $map ) ? self::layout_here_row( $here, (bool) $map, (bool) ( $ids || $propos ) ) : null,
						[ 'label' => 'Stored copy', 'value' => self::stored_text( $stored ), 'state' => self::stored_state( $stored ) ],
						$brands ? [ 'label' => 'Maker site of', 'value' => implode( ', ', $brands ), 'state' => '' ] : null,
						$site ? null : [ 'label' => 'Site map', 'value' => self::map_text( $map ), 'state' => $map && VendorMaps::status_of( $map ) === 'confirmed' ? 'ok' : '' ], // the site section says it, with Start
					]
				)
			),
			'text'  => '',
		];
		$sections[] = $page; // above the site, so its layout is in sight
		if ( $site ) {
			$whole = self::mark_layout( self::site_section( $host, $poll, (int) $client['protocol'] >= 8 ), $here );
			if ( $focus ) { // the product's own Next is in the panel's More
				$whole['actions'] = array_values( array_filter( $whole['actions'], static fn( $a ) => $a['id'] !== 'next' ) );
			}
			$sections[] = $whole;
		}

		$others = array_values( array_filter( array_merge( $ids, $propos ), static fn( $id ) => (int) $id !== $focus ) );
		if ( $others ) {
			$rows = [];
			foreach ( array_slice( $others, 0, self::MAX_PRODUCTS ) as $id ) {
				$rows[] = self::product_row( (int) $id, in_array( $id, $ids, true ), $v2 );
			}
			$more       = count( $others ) - count( $rows );
			$sections[] = [ 'title' => $focus ? 'Other products linked to this address' : 'Products linked to this address', 'rows' => $rows, 'text' => trim( ( $v2 && ! $focus && count( $ids ) > 1 ? 'Choose a product to see what this page gives it. ' : '' ) . ( $more > 0 ? sprintf( 'And %d more.', $more ) : '' ) ) ];
		}

		if ( $known ) {
			$fresh     = $stored && $stored['status'] === 'ok';
			$actions[] = [ 'id' => 'store', 'label' => 'Store this page', 'help' => 'Keeps a copy of the page as you see it now, for the store to read.', 'kind' => 'snapshot', 'primary' => ! $focus && ( ! $fresh || (string) ( $stored['method'] ?? '' ) !== 'capture' ) ];
		}
		$list = Store::state( VendorCatalog::STATE . '_hosts' )[ $host ] ?? [];
		if ( $v3 && ( ( $stored && in_array( (string) $stored['status'], self::REFUSED, true ) ) || ! empty( $list['blocked'] ) || ( $map && VendorMaps::status_of( $map ) === 'refused' ) ) ) { // a refusing site, from any of its pages
			$actions[] = [ 'id' => 'send', 'label' => 'Send this site', 'kind' => 'send', 'primary' => true ];
		}
		if ( $v2 && $map && ! $focus ) {
			$actions[] = [ 'id' => 'map', 'label' => 'Pick a part on this page', 'help' => 'Choose what a part reads on this site’s pages.', 'kind' => 'map' ];
		}
		if ( $v3 && $brands ) {
			$actions[] = [ 'id' => 'link', 'label' => 'Link this page to a product…', 'help' => 'Makes this page the vendor page of a product you search for.', 'kind' => 'search', 'placeholder' => 'Product name, SKU or #ID' ];
		}
		if ( $v3 && ! $v4 && $map && ! $focus ) {
			$actions[] = [ 'id' => 'next', 'label' => 'Next product with work on this site →', 'kind' => 'next', 'product' => 0 ];
		}
		if ( (int) $client['protocol'] >= 9 && ( $brands || $map ) ) {
			$actions[] = [ 'id' => 'links', 'label' => 'Check this site’s product links', 'help' => 'Lists the products whose vendor page may be the wrong one, worst first, to confirm or correct.', 'kind' => 'links' ];
		}
		if ( $v4 && ! $site && ( $brands || $map ) ) {
			$actions[] = [ 'id' => 'thissite', 'label' => 'Show the site overview', 'help' => 'Lists the site’s pages, its layouts and the work left, here in the panel.', 'kind' => 'site' ];
		}
		if ( $brands || $map ) {
			$actions[] = [ 'id' => 'site', 'label' => 'Show this site in the Workbench', 'help' => 'Opens the Product Workbench’s Site maps on this site, in a new tab.', 'kind' => 'open', 'href' => Module::workbench_url( [ 'site' => $host ] ) ];
		}
		if ( $maker !== '' ) {
			$picked = (bool) VendorMaps::kept_pick( $maker, $url );
			$others = array_map( static fn( $p ) => self::short_url( $p['src'] ), array_filter( VendorMaps::kept_pages( $maker ), static fn( $p ) => ! UrlFinder::same_url( $p['src'], $url ) ) );
			$rows   = [ [ 'label' => 'Read for its products', 'value' => $picked ? 'Your pick on this page' : 'Nothing yet: no part of this page is picked', 'state' => '' ] ];
			if ( $others ) {
				$rows[] = [ 'label' => 'Read with it', 'value' => implode( ', ', $others ), 'state' => '' ];
			}
			$sections[] = [ 'title' => 'System requirements for ' . $maker, 'text' => '', 'rows' => $rows ];
			$actions[]  = [ 'id' => 'map', 'label' => $picked ? 'Change the part' : ( $others ? 'Add the part from this page' : 'Pick the part that applies' ), 'help' => 'Opens the mapper on this page. The page is stored with your pick.', 'kind' => 'map', 'part' => 'requirements', 'primary' => true ];
		}
		$out = [ 'title' => self::store_name() . ' · ' . $host, 'href' => Module::workbench_url( [ 'site' => $host ] ), 'subtitle' => self::short_url( $url ), 'notices' => $notices, 'sections' => $sections, 'actions' => $actions, 'product' => $focus ];
		return $poll ? $out + [ 'poll' => $poll ] : $out;
	}

	/** The panel on another page of a choice bundle's list ("…?…[page]=2"): its list section only. null when no choice bundle lists here. */
	private static function choice_list_panel( string $url, string $host, int $product ): ?array {
		$id  = ChoiceBundles::product_for( $url, $product );
		$sec = $id ? ChoiceBundles::section( $id, $url ) : null;
		if ( ! $sec ) {
			return null;
		}
		$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return [
			'title'    => self::store_name() . ' · ' . $host,
			'href'     => Module::workbench_url( [ 'site' => $host ] ),
			'subtitle' => self::short_url( $url ),
			'notices'  => [ [ 'kind' => 'info', 'text' => sprintf( 'Page %d of the choices for %s. Its other parts are read on the product’s own page.', ChoiceBundles::page_no( $url ), $title ) ] ],
			'sections' => [ $sec ],
			'actions'  => [],
			'product'  => $id,
		];
	}

	/** What reading a page's choices did, in words. */
	private static function choice_said( array $c ): string {
		if ( ! $c['read'] ) {
			return 'No cards of choices were found on this page as shown. Show the list (its grid of cards), then read it again.';
		}
		$next = '';
		foreach ( (array) $c['pages'] as $u ) {
			if ( ChoiceBundles::page_no( (string) $u ) > (int) $c['page'] ) {
				$next = (string) $u;
				break;
			}
		}
		return sprintf( 'Read %d choices on this page, %d of them new: %d in the list.', $c['read'], $c['added'], $c['total'] ) . ( $next !== '' ? sprintf( ' The maker lists more: open list page %d and read it there.', ChoiceBundles::page_no( $next ) ) : '' );
	}

	/** The maker site this page keeps system requirements for ('' when it doesn't): on another of its hosts, named as requirements. */
	private static function requirements_for( string $url ): string {
		$site = VendorMaps::site_of( $url );
		return $site !== VendorMaps::host( $url ) && preg_match( VendorMaps::REQ_TEXT, $url ) ? $site : '';
	}

	/** The extension asking is older than the one on offer: the panel says so, with the way to the new one. */
	private static function say_stale( array $client, array &$notices, array &$actions ): void {
		if ( (int) $client['protocol'] < 2 || $client['build'] !== self::build() ) {
			$notices[] = [ 'kind' => 'warn', 'text' => self::update_help( 'A newer version of the extension is out.' ) ];
			$actions[] = [ 'id' => 'update', 'label' => 'Get the new version', 'kind' => 'open', 'primary' => true, 'href' => admin_url( 'admin.php?page=' . self::SLUG ) ];
		}
	}

	/* ── One of our own product pages (protocol 10) ─────────────────────── */

	/** The product a page of this store shows, or 0. */
	private static function store_product( string $raw ): int {
		$url = esc_url_raw( trim( $raw ), [ 'http', 'https' ] );
		if ( $url === '' || BrandSites::host( $url ) !== BrandSites::host( home_url() ) ) {
			return 0;
		}
		$id = url_to_postid( (string) strtok( $url, '#' ) );
		return $id && get_post_type( $id ) === 'product' ? $id : 0;
	}

	/** The maker sites of these brands, host → where to start. A brand's site is never this store. @param \WP_Term[] $terms @return array<string,string> */
	private static function brand_sites( array $terms ): array {
		$roles = BrandSites::roles();
		$out   = [];
		foreach ( $terms as $t ) {
			$mine = $roles[ $t->term_id ] ?? [];
			$main = BrandSites::website_host( (int) $t->term_id );
			if ( $main !== '' && ! in_array( $mine[ $main ] ?? '', BrandSites::BAD, true ) ) {
				$out[ $main ] = (string) \MMI_Brand_Website::get( (int) $t->term_id );
			}
			foreach ( array_keys( array_intersect( $mine, [ 'official', 'partner' ] ) ) as $host ) {
				$out[ $host ] ??= 'https://' . $host . '/';
			}
		}
		unset( $out[ BrandSites::host( home_url() ) ] );
		return $out;
	}

	/**
	 * The panel on one of our own product pages: the same work as on a maker's page, from the other end (owner 2026-11-04).
	 * It starts from what the product lacks, in order: its brand, the brand's maker site, then its page there. Nothing but
	 * the choices made here is written, and the product's parts are still read and committed from its page on the maker's site.
	 */
	private static function store_panel( int $id, string $raw, array $client ): array {
		$title   = html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$terms   = wp_get_post_terms( $id, 'product_brand' );
		$terms   = is_array( $terms ) ? $terms : [];
		$names   = array_map( static fn( $t ) => html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $terms );
		$sites   = self::brand_sites( $terms );
		$page    = (string) get_post_meta( $id, 'vendor_product_url', true );
		$marked  = $page === '' && UrlFinder::is_no_page( $id );
		$left    = in_array( $id, Exclusions::ids(), true );
		$client['look'] = ! empty( $client['look'] ) || ( $page !== '' && self::job_here( $id, $page ) );
		$missing = static fn( string $help ) => [ 'kind' => 'missing', 'word' => 'Missing', 'help' => $help ];
		$notices = [];
		$actions = [];
		self::say_stale( $client, $notices, $actions );

		$rows = [
			[ 'label' => 'Brand', 'value' => $names ? implode( ', ', $names ) : 'None set', 'state' => '', 'status' => $names ? null : $missing( 'The brand says which maker’s site to look on.' ), 'brand' => [ 'product' => $id, 'open' => ! $names ] ],
		];
		if ( $terms ) {
			$term   = (int) $terms[0]->term_id;
			$gone   = ! $sites && BrandSites::is_gone( $term );
			$on     = $page !== '' ? BrandSites::host( $page ) : '';
			$away   = $on !== '' && ! isset( $sites[ $on ] ); // its page is on a site the brand doesn't list, so a page there would be refused
			$said   = $away ? ( BrandSites::roles()[ $term ][ $on ] ?? '' ) : '';
			$field  = [ 'product' => $id, 'term' => $term, 'name' => $names[0], 'gone' => $gone ];
			$rows[] = [
				'label'  => 'Maker site',
				'value'  => $sites ? implode( ', ', array_keys( $sites ) ) : ( $gone ? 'This brand has no maker site' : 'None yet' ),
				'state'  => '',
				'status' => $away
					? [ 'kind' => 'review', 'word' => 'Worth checking', 'help' => 'Its page is on ' . $on . ', which ' . ( $said !== '' ? 'is saved as not ' . $names[0] . '’s own site.' : 'isn’t listed as ' . $names[0] . '’s site.' ) ]
					: ( $sites || $gone ? null : $missing( 'Without it there is nowhere to look for this product’s page.' ) ),
			] + ( $away && $said === '' ? [ 'site' => $field + [ 'add' => $on ] ] : ( $sites || $away ? [] : [ 'site' => $field ] ) );
		}
		if ( $sites || $page !== '' ) {
			$rows[] = self::store_page_row( $id, $title, $page, $marked, $sites );
		}
		if ( $page !== '' ) {
			$host   = VendorMaps::host( $page );
			$rows[] = [ 'label' => 'Stored copy', 'value' => self::stored_text( PageStore::row( $page ) ), 'state' => self::stored_state( PageStore::row( $page ) ) ];
			$rows[] = [ 'label' => 'Site map', 'value' => self::map_text( VendorMaps::get( $host ) ), 'state' => '' ];
		}

		$first = (string) array_key_first( $sites );
		if ( $left ) {
			$notices[] = [ 'kind' => 'warn', 'text' => self::SKIPS['excluded'] ];
		} elseif ( ! $terms ) {
			$notices[] = [ 'kind' => 'info', 'text' => 'Next: set its brand, so the panel knows which maker’s site to look on.' ];
		} elseif ( ! $sites && $page === '' ) {
			$notices[] = [ 'kind' => 'info', 'text' => $gone ? 'Its brand is set aside as having no maker site, so there is no page to link. If that’s wrong, add the site.' : 'Next: add ' . $names[0] . '’s site, or say it has none.' ];
		} elseif ( $page === '' && ! $marked ) {
			$notices[] = [ 'kind' => 'info', 'text' => 'Next: find this product’s page on ' . $first . '. A Link this URL button follows you there.' ];
		} elseif ( $page !== '' && self::open_proposal( $id, $page ) !== '' ) {
			$notices[] = [ 'kind' => 'info', 'text' => 'Next: decide the page proposed for it, under Vendor page. Then open its page to read or pick its parts.' ];
		} elseif ( $page !== '' && empty( $client['look'] ) ) {
			$notices[] = [ 'kind' => 'info', 'text' => 'Scrape its page to see what it would bring here, before anything is written. To pick its parts, open its page: the panel there shows this product.' ];
		}

		$hand = static fn( string $op, string $to ) => [ 'kind' => 'hand', 'op' => $op, 'url' => $to, 'product' => $id, 'title' => $title ];
		if ( $page !== '' ) {
			$linked = in_array( strtoupper( (string) get_post_meta( $id, 'vendor_product_url_verified', true ) ), [ 'TRUE', '1' ], true );
			$lead   = self::open_proposal( $id, $page ) === '';
			if ( $linked && empty( $client['look'] ) ) {
				$actions[] = [ 'id' => 'look', 'label' => 'Scrape its page', 'help' => 'Shows what its page gives this product. Nothing is written until you commit it.', 'kind' => 'look', 'primary' => $lead ];
			}
			$actions[] = [ 'id' => 'visit', 'label' => 'Open its page ↗', 'primary' => $lead && ! $linked ] + $hand( 'visit', SharedPages::anchored( $id, $page ) );
		}
		$look = $page !== '' && ! empty( $client['look'] ) && ! empty( $linked ) ? self::store_look( $id, $page, $notices, $actions ) : null;
		foreach ( $sites as $host => $start ) {
			$actions[] = [ 'id' => 'seek', 'label' => ( $page !== '' ? 'Find its right page on ' : 'Find its page on ' ) . $host . ' ↗', 'help' => 'Opens the site with this product in hand. A Link this URL button follows you there.', 'primary' => $page === '' && ! $marked && $host === $first ] + $hand( 'start', $start );
		}
		$actions[] = [ 'id' => 'data', 'label' => 'Show it in the Workbench', 'help' => 'Opens the Product Workbench on this product, in a new tab.', 'kind' => 'open', 'href' => Module::workbench_url( [ 'q' => (string) $id ] ) ];

		$wb  = Module::workbench_url( [ 'q' => (string) $id ] );
		$out = [ 'title' => self::store_name(), 'href' => $wb, 'subtitle' => self::short_url( (string) strtok( $raw, '#' ) ), 'notices' => $notices, 'sections' => [ [ 'title' => $title . ' · #' . $id, 'href' => $wb, 'rows' => $rows, 'text' => '' ] ], 'actions' => $actions, 'product' => $id ];
		if ( $look ) { // committed through the vendor page's address (`base`), never with this page's HTML
			$out['sections'][] = $look['section'];
			$out['base']       = $page;
			$out              += $look['poll'] ? [ 'poll' => $look['poll'] ] : [];
		}
		return $out;
	}

	/**
	 * A write or a read of this product under way or just over: the panel opens on it, so a job and its answer survive
	 * reloading the page (owner 2026-11-06). The server's record, not the page's memory.
	 */
	private static function job_here( int $id, string $page ): bool {
		$host = VendorMaps::host( $page );
		$read = ProductPreview::scraped( $host, $id );
		$w    = ProductPreview::written( $id );
		return ( $read['state'] ?? '' ) === 'running' || ( $w && ( $w['state'] === 'running' || strtotime( (string) ( $w['at'] ?? '' ) . ' UTC' ) > time() - self::JOB_SHOWN ) );
	}

	/**
	 * What its linked page gives the product, on our own product page (owner 2026-11-06: to scrape from here, with a summary
	 * first): the maker's page's own rows, from the stored copy, for this one product. Reading the page again is a choice:
	 * a read can only be worse than a copy from the person's browser. Picking parts stays on the maker's page.
	 * @return array{section:array,poll:int}
	 */
	private static function store_look( int $id, string $page, array &$notices, array &$actions ): array {
		$host = VendorMaps::host( $page );
		$poll = 0;
		$sec  = self::product_section( $host, $page, $id, $poll, false, true );
		if ( ( $sec['rows'][0]['label'] ?? '' ) === 'Is this its page?' ) {
			array_shift( $sec['rows'] ); // the Vendor page row above says it
		}
		foreach ( $sec['rows'] as &$row ) {
			unset( $row['action'], $row['hint'], $row['sel'], $row['find'], $row['items'] ); // these work on the maker's page
			if ( isset( $row['status']['help'] ) ) {
				$row['status']['help'] = str_replace( 'If it’s on the page, click the part and pick it.', 'If it’s on the page, open its page to pick it.', (string) $row['status']['help'] );
			}
		}
		unset( $row );
		$sec['title']   = 'What its page gives';
		$sec['actions'] = [];
		$sec['more']    = [];
		unset( $sec['href'] );
		$sec['links'] = array_values( array_filter( (array) ( $sec['links'] ?? [] ), static fn( $l ) => str_starts_with( (string) ( $l['label'] ?? '' ), 'Preview' ) ) );
		if ( isset( $sec['commit'] ) ) {
			$sec['commit']['layout'] = null;
			$sec['commit']['site']   = null;
		}
		$read = ProductPreview::scraped( $host, $id );
		if ( ( $read['state'] ?? '' ) === 'running' ) {
			$notices[] = [ 'kind' => 'info', 'text' => 'Reading its page again. This list follows when it’s done.' ];
			$poll      = $poll ?: self::POLL_MS;
		}
		$notices[] = [ 'kind' => 'info', 'text' => 'This is what its page gives this product, from the stored copy (' . self::stored_text( PageStore::row( $page ) ) . '). Nothing is written until you tick parts and commit them.' ];
		$actions[] = [ 'id' => 'scrape', 'label' => 'Read its page again', 'help' => 'Fetches it now, if the site lets the server in, and updates this list.', 'kind' => 'scrape', 'product' => $id ];
		return [ 'section' => $sec, 'poll' => $poll ];
	}

	/** POST /ext/scrape {url, product, dry}: from our product page, its linked page read again (ProductPreview::scrape()); answers with what it gives. */
	public static function scrape( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$raw  = (string) $r->get_param( 'url' );
		$id   = absint( $r->get_param( 'product' ) );
		$page = (string) get_post_meta( $id, 'vendor_product_url', true );
		if ( ! $id || self::store_product( $raw ) !== $id || $page === '' ) {
			return new \WP_REST_Response( [ 'message' => 'This product has no linked page to read.' ], 400 );
		}
		$panel = static fn() => self::store_panel( $id, $raw, self::client( $r ) + [ 'look' => true ] );
		if ( $r->get_param( 'dry' ) ) {
			return rest_ensure_response( [ 'dry' => true, 'message' => 'Checked, nothing read: ' . self::short_url( $page ) . ' would be read again.', 'panel' => $panel() ] );
		}
		$res = ProductPreview::scrape( VendorMaps::host( $page ), $id );
		$say = match ( true ) {
			! $res                             => 'That page isn’t this product’s verified page.',
			$res['state'] === 'running'        => '',
			$res['state'] === 'error'          => (string) $res['note'],
			( $res['status'] ?? '' ) !== 'ok'  => 'The site didn’t give the server the page (' . (string) ( $res['status'] ?? '' ) . '). The stored copy stays: to refresh it, open the page and use Store this page.',
			default                            => ! empty( $res['changed'] ) ? 'Read again: the page has changed.' : 'Read again: no change.',
		};
		return rest_ensure_response( [ 'message' => $say, 'panel' => $panel() ] );
	}

	/** The product's page, from its own page here: what it is and what can be done about it, or what it could be. */
	private static function store_page_row( int $id, string $title, string $page, bool $marked, array $sites ): array {
		$m    = $page !== '' ? self::match_of( $id ) : null;
		$open = $m && ! $m['ok'] ? self::open_proposal( $id, $page ) : '';
		$like = ! $m || $m['kind'] !== 'ok' || $open !== '' ? array_values( array_column( array_merge( [], ...array_map( static fn( $h ) => self::likely_pages( $id, (string) $h ), array_keys( $sites ) ) ), null, 'url' ) ) : []; // a proposal leads each site's list: once
		return [
			'label'  => 'Vendor page',
			'value'  => $page !== '' ? '“' . self::page_title( $page ) . '” · ' . self::short_url( $page ) : ( $marked ? 'The maker doesn’t publish one' : 'None yet' ),
			'state'  => '',
			'status' => $open !== '' ? self::proposed_status( $open ) : ( $m ? [ 'kind' => $m['kind'], 'word' => $m['word'], 'help' => $m['help'] ] : ( $marked ? null : [ 'kind' => 'missing', 'word' => 'Missing', 'help' => 'Nothing can be read for this product until it has a page.' ] ) ),
			'match'  => [ 'product' => $id, 'title' => $title, 'ok' => $m ? $m['ok'] : false, 'likely' => array_slice( $like, 0, self::LIKELY_MAX ), 'store' => true, 'empty' => ! $m, 'marked' => $marked ],
		];
	}

	/** A brand search, or a choice made on a product's page here (mapping()): the brand, or its maker site. */
	private static function store_brand( \WP_REST_Request $r, string $op, int $id, callable $out ): \WP_REST_Response {
		$term = absint( $r->get_param( 'term' ) );
		$dry  = (bool) $r->get_param( 'dry' );
		if ( $op === 'brands' ) {
			$q     = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
			$found = mb_strlen( $q ) < 2 ? [] : get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false, 'name__like' => $q, 'number' => self::SEARCH_MAX, 'orderby' => 'name' ] );
			return rest_ensure_response( [ 'rows' => array_map( static fn( $t ) => [ 'term' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'site' => BrandSites::website_host( (int) $t->term_id ) ], is_array( $found ) ? $found : [] ) ] );
		}
		$name = html_entity_decode( (string) get_term_field( 'name', $term, 'product_brand' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( ! $term || ! term_exists( $term, 'product_brand' ) ) {
			return new \WP_REST_Response( [ 'message' => 'That brand no longer exists.' ], 404 );
		}
		if ( $op === 'brand' ) {
			if ( $dry ) {
				return $out( 'Checked, nothing changed: its brand would be ' . $name . '.', [ 'dry' => true ] );
			}
			$set = new \WP_REST_Request( 'POST', '/' . Rest::NS . '/brands' );
			$set->set_param( 'id', $id );
			$set->set_param( 'ids', [ $term ] );
			$res = Rest::set_brands( $set ); // locked against imports, its title recomposed: the Review table's change
			if ( is_wp_error( $res ) ) {
				return new \WP_REST_Response( [ 'message' => $res->get_error_message() ], 400 );
			}
			return $out( 'Its brand is ' . $name . ' now.' );
		}
		if ( ! in_array( $term, array_map( 'intval', (array) wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'ids' ] ) ), true ) ) {
			return new \WP_REST_Response( [ 'message' => 'That isn’t this product’s brand.' ], 400 );
		}
		$save = new \WP_REST_Request( 'POST', '/' . Rest::NS . '/brands/sites' );
		$save->set_param( 'id', $term );
		if ( $op === 'nosite' ) {
			if ( $dry ) {
				return $out( 'Checked, nothing changed: ' . $name . ' would be set aside as having no maker site.', [ 'dry' => true ] );
			}
			$save->set_param( 'gone', true );
			$res = BrandSites::post( $save );
			return $res->get_status() < 300 ? $out( $name . ' is set aside as having no maker site.' ) : $res;
		}
		$site = trim( (string) $r->get_param( 'target' ) );
		$site = preg_match( '#^https?://#i', $site ) ? $site : 'https://' . $site; // "babyaudio.com" is fine
		$host = BrandSites::host( $site );
		if ( ! BrandSites::public_host( $host ) || $host === BrandSites::host( home_url() ) ) {
			return new \WP_REST_Response( [ 'message' => 'That is not a maker’s site: type it like https://babyaudio.com.' ], 400 );
		}
		if ( $dry ) {
			return $out( 'Checked, nothing changed: ' . $host . ' would be ' . $name . '’s site.', [ 'dry' => true ] );
		}
		if ( self::brand_sites( [ get_term( $term, 'product_brand' ) ] ) ) { // a further site: the Brand website stays
			$save->set_param( 'roles', [ $host => 'official' ] );
		} else {
			$save->set_param( 'website', $site );
		}
		$res = BrandSites::post( $save );
		return $res->get_status() < 300 ? $out( $host . ' is ' . $name . '’s site now.' ) : $res;
	}

	/* ── One product on this page (protocol 2) ──────────────────────────── */

	/**
	 * Product $id's before/after on this page: each part as the product has it and what this page gives it, worded
	 * from ProductPreview::plan() (the map editor's preview, so the panel never disagrees with a write), and what can
	 * be done here. $poll is set when a write or the picture runner is under way.
	 */
	private static function product_section( string $host, string $url, int $id, int &$poll, bool $row_pick = false, bool $commit = false ): array {
		$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$sec   = [ 'title' => $title . ' · #' . $id, 'href' => Module::workbench_url( [ 'q' => (string) $id ] ), 'rows' => [], 'text' => '', 'actions' => [], 'links' => [ [ 'label' => 'Open on ' . self::store_host(), 'href' => (string) get_permalink( $id ) ] ] ];
		$plan  = ProductPreview::plan( $host, $id, true );
		if ( $plan ) { // the product page with what this page gives it, as Write would leave it (owner 2026-11-02)
			array_unshift( $sec['links'], [ 'label' => 'Preview on ' . self::store_host(), 'href' => ProductPreview::signed_url( $host, $id ) ] );
		}
		if ( ! $plan ) {
			$sec['text'] = 'This page isn’t its verified vendor URL yet, so nothing is read from it for this product. Decide its page in Product Data first.';
			return $sec;
		}
		$f      = $plan['fields'];
		$writes = ProductPreview::writes( $plan );
		$status = (string) ( $plan['page']['status'] ?? '' );
		$notes  = [];
		if ( $status !== 'ok' ) {
			$notes[] = $status === 'no_card'
				? ( PageStore::row( (string) $plan['url'] ) && VendorPage::is_listing_page( PageStore::read( (string) PageStore::row( (string) $plan['url'] )['html_file'] ) ) ? 'This is a category page, not this product’s own page, so nothing is read from it. Link the product’s own page.' : 'This page lists several products and none of its cards names this one.' )
				: 'The store has no readable copy of this page yet: Store this page first.';
		}
		if ( $plan['skip'] !== '' ) {
			$notes[] = self::SKIPS[ $plan['skip'] ] ?? 'Product Data leaves this product alone.';
		}
		$sec['rows'] = array_values(
			array_filter(
				[
					self::row_card( $id, $url ),
					self::row_description( (array) $f['description'], $writes ),
					self::row_pictures( (array) $f['pictures'], $writes ),
					self::row_requirements( (array) $f['requirements'], $writes ),
					self::row_items( 'Features', (array) $f['features'], $writes['features'] ?? '' ),
					self::row_items( 'Manuals', (array) $f['manual'], $writes['manual'] ?? '' ),
					self::row_items( 'Videos', (array) $f['videos'], $writes['videos'] ?? '' ),
					isset( $f['audio'] ) ? self::row_items( 'Audio demos', (array) $f['audio'], $writes['audio'] ?? '' ) : null,
					isset( $f['disclaimer'] ) ? self::row_disclaimer( (array) $f['disclaimer'], $writes['disclaimer'] ?? '' ) : null,
					self::row_links( $id ),
				]
			)
		);
		$mapped = (bool) VendorMaps::get( $host );
		if ( $row_pick && $plan['skip'] === '' ) {
			foreach ( $sec['rows'] as &$row ) {
				[ $kind, $part ] = self::ROW_PICK[ $row['label'] ] ?? [ '', '' ];
				if ( $kind === 'pictures' || ( $kind === 'map' && $mapped ) ) {
					$row['action'] = [ 'kind' => $kind, 'part' => $part, 'product' => $id, 'title' => $title ];
					$row['hint']   = $kind === 'map' ? 'Pick it on this page' : ( $part === 'pictures' ? 'Pick pictures on this page' : 'Pick them on this page' );
				}
			}
			unset( $row );
		}
		$lay = null;
		$lt  = null;
		if ( $commit ) {
			[ $lay, $lt ] = self::commit_parts( $sec, $host, $url, $id, $title, $writes, $plan['skip'] === '' && $mapped, $plan );
		}

		$written = ProductPreview::written( $id );
		$running = ( $written['state'] ?? '' ) === 'running';
		$pics    = self::picture_state( $id );
		if ( $running ) {
			$sec['busy'] = 'Writing to this product…';
			$poll        = self::POLL_MS;
		} elseif ( $pics['busy'] ) {
			$sec['busy'] = $pics['text'];
			$poll        = self::POLL_MS;
		}
		if ( ! $running && ( $written['state'] ?? '' ) === 'error' && ( $written['host'] ?? '' ) === $host ) {
			$notes[] = (string) ( $written['note'] ?? 'The last write didn’t finish.' );
		}
		if ( ! $pics['busy'] && $pics['text'] !== '' ) {
			$notes[] = $pics['text'];
		}
		$vetoes = get_post_meta( $id, self::VETOES_META, true );
		$vetoed = is_array( $vetoes ) ? count( (array) ( $vetoes['rules'] ?? [] ) ) : 0;
		$link   = self::link_record( $id );
		$canwrite = $writes && $plan['skip'] === '' && ! $running && $status === 'ok';
		if ( $commit && $plan['skip'] === '' && $status === 'ok' ) {
			$notes[] = 'Click a part to choose what it reads. Tick the parts to commit, then review them below: nothing is written until you commit in the review.';
		} elseif ( $canwrite ) {
			$will    = array_map( static fn( $p, $how ) => ucfirst( $p ) . ( $how === 'review' ? ' (a proposal for Review)' : '' ), array_keys( $writes ), $writes );
			$notes[] = 'Write to this product stores this page as you see it, then writes: ' . implode( ', ', $will ) . '. Undo puts back what it changed.';
		} elseif ( ! $running && $status === 'ok' && $plan['skip'] === '' ) {
			$notes[] = 'Nothing to write: the product already has what this page gives. Pick a part on the page for anything it misses.';
		}
		$sec['text'] = implode( ' ', $notes );

		if ( $canwrite && ! $commit ) {
			$sec['actions'][] = [ 'id' => 'write', 'label' => 'Write to this product', 'kind' => 'write', 'product' => $id, 'expect' => array_keys( $writes ), 'primary' => true ];
		}
		foreach ( $sec['rows'] as &$row ) {
			if ( $row['label'] === 'Disclaimer' && $plan['skip'] === '' ) {
				$row['edit'] = [ 'product' => $id, 'value' => (string) ( ( (array) ( $f['disclaimer']['current']['items'] ?? [] ) )[0] ?? '' ) ];
			}
		}
		unset( $row );
		$sec['undo'] = [];
		$sec['more'] = [];
		$undo        = static function ( string $text, array $action ) use ( &$sec, $id ): void {
			$sec['undo'][] = [ 'text' => $text, 'action' => $action + [ 'kind' => 'undo', 'product' => $id, 'label' => 'Undo' ] ];
		};
		if ( $lt ) {
			$base = [ 'kind' => 'layout', 'layout' => $lay['i'] ];
			$last = get_transient( self::layout_run_key( $host, (string) $lt['type_key'] ) );
			if ( is_array( $last ) ) {
				$sec['undo'][] = self::layout_undo( $last, $base );
			}
			if ( $lt['status'] !== 'confirmed' ) {
				$sec['more'][] = self::layout_confirm( $base, self::letter( $lay['i'] ) );
			}
		}
		$whole = get_transient( self::layout_run_key( $host, 'site' ) );
		if ( is_array( $whole ) ) {
			$line          = self::layout_undo( $whole, [ 'kind' => 'sitepart' ] );
			$line['text']  = str_replace( 'to the layout', 'to the site', $line['text'] );
			$sec['undo'][] = $line;
		}
		if ( ! $running && ( $written['state'] ?? '' ) === 'done' && ( $written['host'] ?? '' ) === $host && ! empty( $written['wrote'] ) ) {
			$undo( 'Last write to this product: ' . implode( ', ', (array) $written['wrote'] ) . '.', [ 'id' => 'undo', 'what' => 'write' ] );
		}
		if ( $pics['undo'] ) {
			$undo( 'The pictures you picked were added to this product.', [ 'id' => 'undo-pictures', 'what' => 'pictures' ] );
		}
		if ( $vetoed ) {
			$undo( $vetoed === 1 ? 'You vetoed 1 picture, so it stays off this product.' : sprintf( 'You vetoed %d pictures, so they stay off this product.', $vetoed ), [ 'id' => 'undo-vetoes', 'what' => 'vetoes', 'label' => $vetoed === 1 ? 'Allow it again' : 'Allow them again' ] );
		}
		if ( $link ) {
			$undo( 'You linked this page to the product' . ( $link['was'] !== '' ? ' in place of ' . self::short_url( $link['was'] ) : '' ) . '.', [ 'id' => 'undo-link', 'what' => 'link' ] );
		}
		$vids = get_post_meta( $id, self::VIDEOS_META, true );
		if ( is_array( $vids ) && ! empty( $vids['added'] ) ) {
			$undo( sprintf( 'You added %d video(s) to this product.', count( (array) $vids['added'] ) ), [ 'id' => 'undo-videos', 'what' => 'videos' ] );
		}
		$note = get_post_meta( $id, self::DISCLAIMER_META, true );
		if ( is_array( $note ) ) {
			$undo( 'You typed this product’s disclaimer.', [ 'id' => 'undo-disclaimer', 'what' => 'disclaimer' ] );
		}
		$aud = get_post_meta( $id, self::AUDIO_META, true );
		if ( is_array( $aud ) && ! empty( $aud['added'] ) ) {
			$undo( sprintf( 'You added %d audio demo(s) to this product.', count( (array) $aud['added'] ) ), [ 'id' => 'undo-audio', 'what' => 'audio' ] );
		}
		if ( ! $row_pick && $plan['skip'] === '' ) {
			$sec['actions'][] = [ 'id' => 'pictures', 'label' => 'Pick pictures and videos', 'kind' => 'pictures', 'product' => $id, 'title' => $title ];
		}
		if ( ! $row_pick && $mapped ) {
			$sec['actions'][] = [ 'id' => 'map', 'label' => 'Pick a part on this page', 'kind' => 'map', 'product' => $id ];
		}
		if ( $mapped ) {
			$sec['more'][] = [ 'id' => 'next', 'label' => 'Next product with work on this site →', 'help' => 'Opens the next product’s page here, ready to work on.', 'kind' => 'next', 'product' => $id ];
		}
		$from  = (array) get_post_meta( $id, VendorMaps::FILL_SRC, true );
		$check = self::row_match( $id, $url );
		$doubt = $check['status']['kind'] === 'missing' || self::open_proposal( $id, $url ) !== ''; // Doubtful, or another page proposed: "No change needed" means nothing then (owner 2026-11-06). Not Worth checking: most of those are right, versions sharing a page
		foreach ( $sec['rows'] as &$row ) {
			$part = (string) ( self::ROW_PART[ $row['label'] ] ?? '' );
			$was  = (string) ( $from[ $part === 'manual' ? 'manual' : $part ] ?? '' );
			if ( $was !== '' && $row['state'] !== 'add' && self::short_url( $was ) !== self::short_url( $url ) ) {
				$row['state']  = 'old';
				$row['value'] .= ' · read from ' . self::short_url( $was );
			}
			$row['status'] = self::STATUS[ $row['state'] ] ?? null;
			if ( $doubt && $row['state'] === 'ok' ) {
				$row['status'] = self::STATUS[ $row['label'] === 'Description' && self::text_on_page( $id, $url ) ? 'copied' : 'doubt' ];
			}
		}
		unset( $row );
		$sec['key'] = array_values( self::STATUS );
		array_unshift( $sec['rows'], $check );
		return $sec;
	}

	/**
	 * Protocol 7 (owner 2026-11-03: "the user picks criteria for each product meta, then commits either to that product
	 * or to all products linked to any vendor URL in that layout"): each part's row says its criteria (the pick that
	 * reads it: the layout's own, the site's, or the general reader), opens the mapper to pick them, and carries a tick
	 * (on where this product gets a change); the section's commit says what can be committed where. A part commits to
	 * the layout's products only with a pick of the layout's own (PartFill writes the layout's own parts).
	 * @return array{0:?array,1:?array} the page's layout and its type
	 */
	private static function commit_parts( array &$sec, string $host, string $url, int $id, string $title, array $writes, bool $pickable, array $plan ): array {
		$lay  = MapTypes::layout_of( $host, $url );
		$lay  = $lay && $lay['pages'] >= 2 ? $lay : null;
		$t    = $lay ? MapTypes::for_layout( $host, $lay['i'] ) : null;
		$t    = $t && ! is_wp_error( $t ) ? $t : null;
		$site = VendorMaps::get( $host );
		$name = $lay ? 'Layout ' . self::letter( $lay['i'] ) : '';
		$prop = MapProposals::for_page( $host, $lay ? (int) $lay['i'] : null );
		$page = PageStore::row( (string) $plan['url'] );
		$html = $page && $page['status'] === 'ok' && $page['html_file'] !== '' ? PageStore::read( (string) $page['html_file'] ) : '';
		$own  = $html !== '' ? ProductPage::own_html( $id, (string) $plan['url'], $html ) : null;
		$read = Descriptions::plain( (string) ( $plan['raw']['description'] ?? '' ) );
		$parts = [];
		foreach ( $sec['rows'] as &$row ) {
			$k = self::ROW_PART[ $row['label'] ] ?? '';
			if ( $k === '' ) {
				continue;
			}
			$c      = self::criteria( $k, $t, $site, $name );
			$missed = $own !== null && ! empty( $c['sel'] ) && empty( VendorMaps::eval_field( $k, (string) $c['sel'], (string) ( $c['f']['mode'] ?? '' ), $own, (string) $plan['url'], false, (string) ( $c['f']['src'] ?? '' ) )['found'] );
			if ( $missed ) { // "found on 58 of 68" says nothing of this page, and it may be one of the other 10
				$c['text'] .= '. It finds nothing on this page, so the general reader reads it here';
				$c['sel']   = '';
			}
			$c               = self::proposed( $c, $prop[ $k ] ?? null, $name );
			$row['criteria'] = $c['text'];
			$row['sel']      = (string) ( $c['sel'] ?? '' ); // the panel outlines what it reads on the page while the row is hovered
			if ( $k === 'description' && $row['sel'] === '' && $read !== '' ) {
				$row['find'] = [ mb_substr( $read, 0, 60 ), mb_substr( $read, -60 ) ]; // no selector to follow: the text's two ends find what was read
			}
			if ( $k === 'requirements' && $row['state'] === 'missing' && ( $c['f']['mode'] ?? '' ) === 'follow' && $own !== null ) { // it says where they are, and that page can't be read yet
				$went = VendorMaps::eval_field( $k, (string) $c['sel'], 'follow', $own, (string) $plan['url'], false );
				if ( ! empty( $went['link'] ) && empty( $went['stored'] ) ) {
					$row['state'] = 'away';
					$row['value'] = 'None · kept on ' . VendorMaps::host( (string) $went['link'] ) . ', not stored yet';
					$row['away']  = [ 'label' => 'Open it to pick the part ↗', 'url' => (string) $went['link'] ];
				}
			}
			if ( $k === 'features' ) { // no pick of their own: each item is outlined, as they can come from more than one list
				$row['items'] = array_values( (array) ( ( $plan['fields']['features']['proposed']['items'] ?? [] ) ?: ( $plan['fields']['features']['current']['items'] ?? [] ) ) );
			}
			$row['commit']   = [ 'key' => $k, 'checked' => isset( $writes[ $k ] ) ];
			$parts[ $k ]     = [ 'label' => $row['label'], 'layout' => $lay && $c['source'] === 'layout' && isset( PartFill::PLAN_FIELD[ $k ] ), 'site' => ! empty( $site['fields'][ $k ]['sel'] ) && isset( PartFill::PLAN_FIELD[ $k ] ) ];
			if ( $pickable ) {
				unset( $row['action'], $row['hint'] ); // on a site with no map a row keeps the picker: there are no criteria to choose
			}
			if ( $pickable && $k !== 'features' ) { // features have no pick of their own (VendorMaps::NOT_MAPPED)
				$row['action'] = [ 'kind' => 'map', 'part' => $k, 'product' => $id, 'title' => $title ];
				$row['hint']   = ! empty( $c['proposal'] ) ? 'Inspect the proposal' : ( $c['source'] === 'generic' ? 'Pick criteria' : 'Change criteria' );
			}
		}
		unset( $row );
		$sec['commit'] = [
			'product' => $id,
			'writes'  => array_keys( $writes ),
			'parts'   => $parts,
			'layout'  => $lay ? [ 'i' => $lay['i'], 'name' => $name, 'products' => $t ? count( PartFill::layout_products( $host, $t ) ) : self::layout_count( $host, $lay['i'] ) ] : null,
			'site'    => $site ? [ 'products' => count( VendorMaps::site_products( $host ) ) ] : null,
		];
		return [ $lay, $t ];
	}

	/** A part's criteria in words, and where they come from: layout (its own pick), site (the site map's pick) or generic. */
	private static function criteria( string $k, ?array $t, ?array $site, string $name ): array {
		$f = $t ? (array) ( $t['fields'][ $k ] ?? [] ) : [];
		if ( ! empty( $f['sel'] ) ) {
			$live = $t['status'] === 'confirmed' || ! empty( $f['live'] );
			return [ 'source' => 'layout', 'sel' => (string) $f['sel'], 'f' => $f, 'text' => sprintf( 'Criteria: %s’s pick, found on %d of %d stored pages%s', $name, (int) ( $f['found'] ?? 0 ), (int) ( $f['of'] ?? 0 ), $live ? '' : ' (in use once committed to the layout)' ) ];
		}
		$f = $site ? (array) ( $site['fields'][ $k ] ?? [] ) : [];
		if ( ! empty( $f['sel'] ) && empty( $f['health']['broken'] ) ) {
			return [ 'source' => 'site', 'sel' => (string) $f['sel'], 'f' => $f, 'text' => sprintf( 'Criteria: the site’s pick, found on %d of %d stored pages%s', (int) ( $f['found'] ?? 0 ), (int) ( $f['of'] ?? 0 ), $name !== '' ? sprintf( '. Pick it for %s to commit it to the layout', $name ) : '' ) ];
		}
		return [ 'source' => 'generic', 'text' => $k === 'features' ? 'Criteria: the lists on this page' : 'Criteria: none picked; the general reader' ];
	}

	/** A part's criteria with Claude's proposal waiting for inspection (MapProposals). */
	private static function proposed( array $c, ?array $p, string $name ): array {
		if ( ! $p || $p['status'] !== 'proposed' || $c['source'] === 'layout' ) {
			return $c;
		}
		$c['text']    .= sprintf( '. Proposed by Claude for %s: found on %d of %d stored pages. Click to inspect it.', $p['layout'] < 0 ? 'the whole site' : $name, $p['found'], $p['of'] );
		$c['proposal'] = true;
		return $c;
	}

	/** The site's products whose verified page is one of layout $i's pages (before the layout has a type of its own). */
	private static function layout_count( string $host, int $i ): int {
		$key  = static fn( string $u ) => preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) ) . rtrim( strtolower( (string) wp_parse_url( $u, PHP_URL_PATH ) ), '/' );
		$urls = array_flip( array_map( $key, (array) ( MapTypes::layout_pages( $host )[ $i ]['urls'] ?? [] ) ) );
		return count( array_filter( VendorMaps::site_products( $host ), static fn( $p ) => isset( $urls[ $key( (string) $p['url'] ) ] ) ) );
	}

	/** The product's description is word for word on this stored page: how a wrong page's text shows on the product. */
	private static function text_on_page( int $id, string $url ): bool {
		$pg = PageStore::row( $url );
		if ( ! $pg || $pg['status'] !== 'ok' ) {
			return false;
		}
		$words = static fn( string $html ) => trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		$head  = mb_substr( $words( (string) get_post_field( 'post_content', $id ) ), 0, self::SAME_TEXT );
		return mb_strlen( $head ) === self::SAME_TEXT && mb_stripos( $words( PageStore::read( (string) $pg['html_file'] ) ), $head ) !== false;
	}

	/** A row's state: add (a write fills or changes it), ok (it has it), missing (it lacks it and this page gives none), warn. */
	private static function row_state( bool $has, string $write ): string {
		if ( $write === 'write' ) {
			return 'add';
		}
		if ( $write === 'review' ) {
			return 'warn';
		}
		return $has ? 'ok' : 'missing';
	}

	private static function row_description( array $f, array $writes ): array {
		$now  = (int) ( $f['current']['chars'] ?? 0 );
		$page = (int) ( $f['proposed']['chars'] ?? 0 );
		$has  = $now >= Enrichment::THIN_CHARS;
		$nowt = $now ? number_format( $now ) . ' characters' : 'None';
		$v    = match ( (string) ( $f['action'] ?? '' ) ) {
			'fill'       => $nowt . ' → ' . number_format( $page ) . ' from this page',
			'refresh'    => $nowt . ' (our earlier write) → ' . number_format( $page ) . ' from this page',
			'same'       => $nowt . ' · the same as this page gives',
			'maker'      => $nowt . ' → the maker’s ' . number_format( $page ) . ' from this page',
			'proposal'   => $nowt . ' → a ' . number_format( $page ) . '-character proposal from this page waits in Review',
			'too_little' => $nowt . ( $page ? ' · this page gives only ' . number_format( $page ) : ' · this page gives none' ),
			'no_page'    => $nowt,
			default      => $nowt . ( $page ? ' · kept (this page gives ' . number_format( $page ) . ')' : '' ),
		};
		return [ 'label' => 'Description', 'value' => $v, 'state' => self::row_state( $has, $writes['description'] ?? '' ) ];
	}

	private static function row_pictures( array $f, array $writes ): array {
		$now   = count( (array) ( $f['current']['images'] ?? [] ) );
		$merge = (array) ( $f['merge'] ?? [] );
		$found = (int) ( $f['proposed']['found'] ?? 0 );
		if ( $merge ) {
			$v = $now . ' now · ' . ( (string) ( $merge['summary'] ?? '' ) !== '' ? (string) $merge['summary'] : 'nothing new on this page' );
		} elseif ( ( $f['action'] ?? '' ) === 'no_page' ) {
			$v = $now . ' now';
		} else {
			$v = $now . ' now · ' . ( $found ? $found . ' on this page, not used: ' . ( Pictures::REASONS[ (string) ( $f['why'] ?? '' ) ] ?? 'kept as they are' ) : 'no pictures read on this page' );
		}
		$new   = array_values( array_filter( (array) ( $merge['final'] ?? [] ), static fn( $p ) => ( $p['kind'] ?? '' ) === 'new' && empty( $p['att'] ) ) );
		$thumbs = array_slice( array_map( static fn( $p ) => (string) $p['url'], $new ), 0, self::THUMBS );
		$row    = [ 'label' => 'Pictures', 'value' => $v . ( $now ? '' : '. Pick them on the page.' ), 'state' => self::row_state( $now > 0, $writes['pictures'] ?? '' ) ];
		return $thumbs ? $row + [ 'thumbs' => $thumbs, 'thumb_action' => [ 'kind' => 'veto', 'label' => 'Don’t add this picture to the product' ] ] : $row;
	}

	private static function row_requirements( array $f, array $writes ): array {
		$names = [ 'mac' => 'Mac', 'windows' => 'Windows', 'linux' => 'Linux' ];
		$now   = array_values( array_intersect_key( $names, (array) ( $f['current'] ?? [] ) ) );
		$page  = array_values( array_intersect_key( $names, array_diff_key( (array) ( $f['proposed'] ?? [] ), [ 'platforms' => 1 ] ) ) );
		$plan  = $f['action'] ?? 'none';
		$v     = ( $now ? implode( ', ', $now ) : 'None' );
		if ( is_array( $plan ) ) {
			$say = [ 'fill' => 'added', 'same' => 'the same', 'locked' => 'locked', 'replace' => 'newer, for Review', 'review' => 'differs, for Review' ];
			$per = [];
			foreach ( $names as $os => $name ) {
				if ( isset( $plan[ $os ] ) && is_string( $plan[ $os ] ) ) {
					$per[] = $name . ' ' . ( $say[ $plan[ $os ] ] ?? $plan[ $os ] );
				}
			}
			$v .= ' · this page: ' . ( $per ? implode( ', ', $per ) : implode( ', ', $page ) );
		} elseif ( $plan === 'no_page' ) {
			$v .= '';
		} else {
			$v .= ' · this page names none';
		}
		return [ 'label' => 'System requirements', 'value' => $v, 'state' => self::row_state( (bool) $now, $writes['requirements'] ?? '' ) ];
	}

	private static function row_items( string $label, array $f, string $write ): array {
		$now  = count( (array) ( $f['current']['items'] ?? [] ) );
		$page = count( (array) ( $f['proposed']['items'] ?? [] ) );
		$nowt = $now ? $now . ' now' : 'None';
		if ( $now && $label === 'Features' ) { // a count alone hid a pager stored as features (2026-11-04)
			$nowt .= ': ' . implode( ', ', array_map( static fn( $i ) => mb_strimwidth( (string) $i, 0, 44, '…' ), array_slice( (array) $f['current']['items'], 0, 3 ) ) ) . ( $now > 3 ? ', …' : '' );
		}
		$v    = match ( (string) ( $f['action'] ?? '' ) ) {
			'fill'        => $nowt . ' → ' . $page . ' from this page',
			'no_list'     => $nowt . ( $page ? ' · ' . $page . ' in this page’s description lists' : ' · this page’s description has no feature list' ),
			'not_mapped'  => $nowt . ( $now ? '' : ' · not mapped on this site: pick it on the page' ),
			'mapped_none' => $nowt . ' · the site map’s part finds none on this page',
			'no_page'     => $nowt,
			default       => $nowt . ( $page ? ' · kept (this page gives ' . $page . ')' : '' ),
		};
		return [ 'label' => $label, 'value' => $v, 'state' => self::row_state( $now > 0, $write ) ];
	}

	/** A product's note, shown as "Note:" on its page. Having none is normal, so it is never Missing. */
	private static function row_disclaimer( array $f, string $write ): array {
		$now  = (string) ( ( (array) ( $f['current']['items'] ?? [] ) )[0] ?? '' );
		$page = (string) ( ( (array) ( $f['proposed']['items'] ?? [] ) )[0] ?? '' );
		$v    = $now !== '' ? '“' . $now . '”' : 'None';
		if ( $write === 'write' ) {
			$v .= ' → “' . $page . '” from this page';
		} elseif ( $page !== '' && $page !== $now ) {
			$v .= ' · kept (this page says “' . $page . '”)';
		} elseif ( $now === '' ) {
			$v .= ' · nothing picked on this page';
		}
		return [ 'label' => 'Disclaimer', 'value' => $v, 'state' => $write === 'write' ? 'add' : 'ok' ];
	}

	/**
	 * How well a product's vendor page matches it (owner 2026-11-04: "The user needs to be able to check if a host URL
	 * product is mapped incorrectly to a vendor URL"): UrlReview's rating in words, or a person's word that it is right.
	 * @return array{n:?int,kind:string,word:string,help:string,ok:bool}
	 */
	private static function match_of( int $id, ?int $n = null ): array {
		$url = (string) get_post_meta( $id, 'vendor_product_url', true );
		if ( $url !== '' && (string) get_post_meta( $id, self::PAGE_OK, true ) === $url ) {
			return [ 'n' => null, 'kind' => 'ok', 'word' => 'Confirmed', 'help' => 'You said this is its page.', 'ok' => true ];
		}
		$e   = UrlReview::current( [ $id ], true )[ $id ] ?? null;
		$n ??= $e && $e['confidence'] ? (int) $e['confidence']['n'] : null;
		$why = implode( ' ', array_map( static fn( $x ) => preg_replace( '/^Its /', 'The page’s ', $x[1] ) . '.', array_filter( (array) ( $e['notes'] ?? [] ), static fn( $x ) => in_array( $x[0], [ 'caution', 'bad' ], true ) ) ) );
		if ( $n === null ) {
			return [ 'n' => null, 'kind' => '', 'word' => 'Not checked yet', 'help' => 'Its page hasn’t been compared with it yet.', 'ok' => false ];
		}
		return $n >= self::MATCH_FINE
			? [ 'n' => $n, 'kind' => 'ok', 'word' => 'Looks right', 'help' => 'The page’s title and address name this product.', 'ok' => false ]
			: [ 'n' => $n, 'kind' => $n < self::MATCH_DOUBT ? 'missing' : 'review', 'word' => $n < self::MATCH_DOUBT ? 'Doubtful' : 'Worth checking', 'help' => $why !== '' ? $why : 'The page doesn’t clearly name this product.', 'ok' => false ];
	}

	/** The page waiting for a decision as product $id's own, on any of its brand's sites, other than $url. '' when none is. */
	private static function open_proposal( int $id, string $url ): string {
		$row = UrlFinder::row( $id );
		return $row && $row['status'] === 'candidate' && str_starts_with( (string) $row['url'], 'http' ) && ! UrlFinder::same_url( (string) $row['url'], $url ) ? (string) $row['url'] : '';
	}

	/** The row's label when a page is proposed. */
	private static function proposed_status( string $open ): array {
		return [ 'kind' => 'review', 'word' => 'Other page proposed', 'help' => self::short_url( $open ) . ' is proposed as its page: use it below, or say this one’s right.' ];
	}

	/** On a page listing several products, which card is this one's: hovering the row outlines the card and brings it into view. */
	private static function row_card( int $id, string $url ): ?array {
		if ( SharedPages::relation( $id, $url )['kind'] !== 'several' ) {
			return null;
		}
		$pg   = PageStore::row( $url );
		$html = $pg && $pg['status'] === 'ok' ? PageStore::read( (string) $pg['html_file'] ) : '';
		$card = $html !== '' ? SharedPages::card( $html, $id, $url ) : null;
		if ( ! $card ) {
			return null;
		}
		$row = [ 'label' => 'Its card on this page', 'value' => (string) $card['name'], 'state' => 'ok' ];
		return (string) $card['id'] !== '' ? $row + [ 'sel' => '[id="' . $card['id'] . '"]' ] : $row;
	}

	/** The product's page, against it: first among its rows, with what can be done about it. */
	private static function row_match( int $id, string $url ): array {
		$m    = self::match_of( $id );
		$open = $m['ok'] ? '' : self::open_proposal( $id, $url );
		return [
			'label'  => 'Is this its page?',
			'value'  => '“' . self::page_title( $url ) . '”',
			'state'  => 'ok',
			'status' => $open !== '' ? self::proposed_status( $open ) : [ 'kind' => $m['kind'], 'word' => $m['word'], 'help' => $m['help'] ],
			'match'  => [ 'product' => $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'ok' => $m['ok'], 'likely' => $m['kind'] === 'ok' && $open === '' ? [] : self::likely_pages( $id, VendorMaps::host( $url ) ) ],
		];
	}

	/**
	 * Pages of the site's list that may be product $id's own, by what their addresses say: "caffeine-sound-pack-for-lounge-
	 * lizard-ep4", "bt-modern-wave". Its name's words must be in the address and the address's words in its title: one
	 * shared word ("cubase", "overloud-thu") is no match. UrlFinder's matcher reads the names a shop's list gives; a
	 * sitemap gives none. @return array<int,array{url:string,short:string}>
	 */
	private static function likely_pages( int $id, string $host ): array {
		global $wpdb;
		self::$pages[ $host ] ??= array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT url FROM ' . VendorCatalog::table() . ' WHERE host = %s', $host ) ) );
		$bare  = static fn( string $s ) => (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( $s ) );
		$words = static fn( string $s ) => array_values( array_filter( (array) preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $s ) ), static fn( $w ) => mb_strlen( (string) $w ) >= 2 && ! in_array( $w, self::SLUG_STOP, true ) ) );
		$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$brand = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
		$name  = (string) preg_replace( '/^' . preg_quote( html_entity_decode( (string) ( is_array( $brand ) ? ( $brand[0] ?? '' ) : '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), '/' ) . '\s*/iu', '', $title );
		$head  = $words( (string) ( preg_split( '/\s[–—-]\s|\(|:/u', $name )[0] ?? '' ) );
		$all   = $bare( $title );
		$now   = (string) get_post_meta( $id, 'vendor_product_url', true );
		$hits = [];
		$row  = UrlFinder::row( $id );
		$lead = $row && $row['status'] === 'candidate' && str_starts_with( (string) $row['url'], 'http' ) && ! UrlFinder::same_url( (string) $row['url'], $now ) ? [ [ 'url' => (string) $row['url'], 'short' => self::short_url( (string) $row['url'] ), 'proposed' => true ] ] : [];
		foreach ( self::$pages[ $host ] as $u ) {
			$seg  = basename( rtrim( (string) wp_parse_url( $u, PHP_URL_PATH ), '/' ) );
			$slug = $bare( $seg );
			$own  = $words( (string) preg_replace( '/\.(?:html?|php)$/i', '', $seg ) );
			$miss = count( array_filter( $head, static fn( $w ) => ! str_contains( $slug, $w ) ) );
			$lost = count( array_filter( $own, static fn( $w ) => ! str_contains( $all, $w ) ) );
			if ( $own && ! UrlFinder::same_url( $u, $now ) && $miss <= ( count( $head ) >= 3 ? 1 : 0 ) && $lost <= ( count( $own ) >= 5 ? 1 : 0 ) ) {
				$hits[ $u ] = count( $own ) - $lost - $miss;
			}
		}
		arsort( $hits );
		$more = array_filter( array_keys( $hits ), static fn( $u ) => ! $lead || ! UrlFinder::same_url( (string) $u, (string) $lead[0]['url'] ) );
		return array_slice( array_merge( $lead, array_map( static fn( $u ) => [ 'url' => (string) $u, 'short' => self::short_url( (string) $u ) ], array_values( $more ) ) ), 0, self::LIKELY_MAX );
	}

	private static function page_title( string $url ): string {
		$pg = PageStore::row( $url );
		$t  = $pg ? trim( html_entity_decode( (string) $pg['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
		return $t !== '' ? $t : self::short_url( $url );
	}

	/** A bundle's links, which the product page draws by itself (DescLinks). Null for any other product. */
	private static function row_links( int $id ): ?array {
		if ( ! DescLinks::lists( $id ) ) {
			return null;
		}
		$m    = DescLinks::map( $id );
		$to   = array_values( array_unique( array_column( $m['links'], 'text' ) ) );
		$v    = $to ? 'Linked to their pages on the store: ' . implode( ', ', $to ) . '.' : 'None of this page’s links lead to a product we sell.';
		$left = array_slice( $m['none'], 0, 6 );
		$none = $left ? ' No product here for: ' . implode( ', ', $left ) . ( count( $m['none'] ) > 6 ? sprintf( ' and %d more', count( $m['none'] ) - 6 ) : '' ) . ', so those stay plain words.' : '';
		return [ 'label' => 'Links in the description', 'value' => $v . $none, 'state' => 'auto' ];
	}

	/**
	 * The pictures a person picked here: waiting for the runner (busy, its place), or what the runner did with them.
	 * @return array{busy:bool,text:string,undo:bool}
	 */
	private static function picture_state( int $id ): array {
		$meta = get_post_meta( $id, Pictures::PICKED_META, true );
		if ( ! is_array( $meta ) || empty( $meta['at'] ) ) {
			return [ 'busy' => false, 'text' => '', 'undo' => false ];
		}
		$q    = Pictures::queue_status( [ $id ] );
		$item = ( (array) $q['items'] )[ $id ] ?? null;
		$n    = count( (array) $meta['urls'] );
		if ( Pictures::picked( $id ) ) {
			$where = $item && $item['ahead'] ? sprintf( ' (%d ahead of it)', (int) $item['ahead'] ) : '';
			$when  = $item && $item['eta'] ? sprintf( ', in about %d min', max( 1, (int) round( $item['eta'] / 60 ) ) ) : '';
			$state = in_array( $q['runner']['mode'], [ 'paused', 'limit', 'stopped' ], true ) ? ' The picture runner is ' . $q['runner']['mode'] . ( $q['runner']['note'] !== '' ? ': ' . $q['runner']['note'] : '' ) . '.' : '';
			return [ 'busy' => true, 'text' => sprintf( 'The %d picture(s) you picked wait for the picture runner%s%s.', $n, $where, $when ) . $state, 'undo' => false ];
		}
		if ( ! $item || strtotime( (string) $meta['at'] . ' UTC' ) < time() - DAY_IN_SECONDS ) {
			return [ 'busy' => false, 'text' => '', 'undo' => false ];
		}
		$said = match ( $item['status'] ) {
			'applied' => 'The picture runner added the pictures you picked that the product didn’t have.',
			'skipped' => 'The picture runner added none of the pictures you picked: ' . ( $item['reason'] === 'nothing_to_add' ? 'the product has them all already.' : ( Pictures::REASONS[ $item['reason'] ] ?? $item['reason'] ) . '.' ),
			'error'   => 'The picture runner couldn’t download the pictures you picked.',
			default   => '',
		};
		return [ 'busy' => false, 'text' => $said, 'undo' => $item['status'] === 'applied' ];
	}

	/* ── The site (protocol 4: Site mode, plan §13 S1) ──────────────────── */

	/**
	 * The maker's site as a whole: its page list split into our products' pages, pages proposed for them, the maker's
	 * products we don't sell, and our products whose page isn't in the list; its site map (where it keeps each part),
	 * with Start when it has none (protocol 8); its layouts (SiteGaps groups our products' stored pages by structure),
	 * how many, each with how its pages read and a page of it to open; and the work its products' pages hold (the gaps
	 * matrix). While the map is drafted or the pages grouped, a busy line and $poll; pages without a current reading
	 * are queued for grouping here, as the Product Workbench does. Cached per site for SITE_CACHE_S under the map's
	 * stamp and the list's date once nothing is under way: the matrix alone takes seconds on a large site.
	 */
	private static function site_section( string $host, int &$poll = 0, bool $start = false ): array {
		$list = Store::state( VendorCatalog::STATE . '_hosts' )[ $host ] ?? null;
		global $wpdb;
		$map  = VendorMaps::get( $host );
		$kept = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) n, MAX(fetched_at) at FROM ' . PageStore::table() . ' WHERE host IN (%s, %s)', $host, 'www.' . $host ), ARRAY_A ); // the host index: a page stored or linked here (Link stores it) shows at once, so a new store's first page offers Start
		$key  = 'mmpt_ext_site2_' . md5( implode( '|', [ $host, SiteGaps::stamp( $host ), $list['at'] ?? '', (int) $start, $map['drafted_at'] ?? 'none', $map['gaps']['at'] ?? '', $kept['n'] ?? 0, $kept['at'] ?? '' ] ) ); // a new map or a new grouping shows at once
		$busy = self::site_busy( $host );
		$sec  = $busy === '' ? get_transient( $key ) : false;
		if ( is_array( $sec ) ) {
			return $sec;
		}
		$m      = $map ? SiteGaps::matrix( $host, false ) : null;
		$stored = count( array_filter( VendorMaps::pages( $host ), static fn( $p ) => ( $p['status'] ?? '' ) === 'ok' ) );
		if ( $m && $m['pending'] && $busy === '' ) {
			VendorMaps::queue( 'refresh', $host ); // pages read since the last grouping, or never grouped
			$busy = self::site_busy( $host );
		}
		$sec = [
			'title'   => 'Site overview · ' . $host,
			'href'    => Module::workbench_url( [ 'site' => $host ] ),
			'rows'    => array_merge( self::site_pages( $host, $list ), [ self::site_map_row( $map, $stored, $start ) ], self::site_layouts( $host, $m, $map !== null ) ),
			'text'    => self::site_work( $host ),
			'actions' => [],
		];
		if ( ! $map && $start && $stored ) {
			$sec['actions'][] = [ 'id' => 'startmap', 'label' => 'Start this site’s map', 'kind' => 'startmap', 'op' => 'start', 'primary' => true ];
		}
		if ( $map ) {
			$sec['actions'][] = [ 'id' => 'next', 'label' => 'Next product with work on this site →', 'kind' => 'next', 'product' => 0, 'primary' => true ];
		}
		if ( ! $list || ! (int) ( $list['count'] ?? 0 ) || ! empty( $list['blocked'] ) ) { // a list read here would replace a good one with names and pictures by a bare one
			$sec['actions'][] = [ 'id' => 'list', 'label' => 'Read its page list in this browser', 'kind' => 'readlist' ];
		}
		if ( $busy !== '' ) {
			$sec['busy'] = $m && $m['pending'] && ! str_starts_with( $busy, 'Starting' ) ? sprintf( '%s %d of our products’ pages still to read.', $busy, (int) $m['pending'] ) : $busy;
			$poll        = $poll ? min( $poll, self::SITE_POLL_MS ) : self::SITE_POLL_MS;
			return $sec; // not kept: the next look shows the progress
		}
		$grouped = $m && $m['layouts'];
		set_transient( $key, $sec, $grouped ? self::SITE_CACHE_S : MINUTE_IN_SECONDS );
		return $sec;
	}

	/**
	 * Which of the site's layouts this page is part of (MapTypes::layout_of(): its grouped stored page). $here: that
	 * layout or null; $mapped: the site has a map; $ours: a product uses or is proposed this page.
	 */
	private static function layout_here_row( ?array $here, bool $mapped, bool $ours ): array {
		if ( $here && $here['pages'] >= 2 ) {
			return [ 'label' => 'Layout', 'value' => sprintf( 'Layout %s: this page is one of its %d pages', self::letter( (int) $here['i'] ), (int) $here['pages'] ), 'state' => 'add' ];
		}
		if ( $here ) {
			return [ 'label' => 'Layout', 'value' => 'Its own: built like no other of our products’ pages on this site (one of the One-page layouts). Picks saved for the site reach it.', 'state' => '' ];
		}
		if ( ! $mapped ) {
			return [ 'label' => 'Layout', 'value' => 'Not known yet: the site’s pages are grouped into layouts once its map is started.', 'state' => '' ];
		}
		return $ours
			? [ 'label' => 'Layout', 'value' => 'Not grouped yet: its layout shows once the site’s pages are grouped again.', 'state' => '' ]
			: [ 'label' => 'Layout', 'value' => 'None: no product of ours uses this page, so it isn’t in the site’s layouts.', 'state' => '' ];
	}

	/** The site section's layout rows with this page's marked (the section is kept per site, so marked afterwards). */
	private static function mark_layout( array $sec, ?array $here ): array {
		if ( ! $here ) {
			return $sec;
		}
		$want = $here['pages'] >= 2 ? 'Layout ' . self::letter( (int) $here['i'] ) . ' · ' : 'One-page layouts';
		foreach ( $sec['rows'] as $n => $r ) {
			if ( str_starts_with( (string) $r['label'], $want ) ) {
				$sec['rows'][ $n ]['label'] .= $here['pages'] >= 2 ? ' ← this page' : ' ← this page is one';
				$sec['rows'][ $n ]['state'] = 'add';
				break;
			}
		}
		return $sec;
	}

	/** What the map runner is doing for this site, in words ('' when nothing). */
	private static function site_busy( string $host ): string {
		$s     = Store::state( VendorMaps::STATE );
		$queue = (array) ( $s['queue'] ?? [] );
		$note  = (string) ( $s['note'] ?? '' );
		$now   = VendorMaps::runner_alive() ? $note : '';
		$wait  = static function () use ( $now ) {
			if ( $now === 'waiting: server busy' || PageStore::server_busy() ) { // the runner reads no page while the load is high
				return ' The server is busy (load above 2.5): its pages are read when the load drops.';
			}
			return $now !== '' ? ' Waiting for the map runner (' . $now . ').' : ' Waiting for the map runner to start.';
		};
		if ( $now === 'drafting ' . $host ) {
			return 'Starting this site’s map: reading our products’ stored pages for where it keeps each part. Its layouts follow.';
		}
		if ( isset( $queue[ 'draft:' . $host ] ) || isset( $queue['draft:*'] ) ) {
			return 'Starting this site’s map.' . $wait();
		}
		if ( $now === 'updating ' . $host ) {
			return 'Grouping our products’ stored pages into layouts.';
		}
		if ( isset( $queue[ 'refresh:' . $host ] ) ) {
			return 'Grouping our products’ stored pages into layouts.' . $wait();
		}
		return '';
	}

	/** The site's map: where it keeps each part. With none, what Start does (protocol 8) or why it can't yet. */
	private static function site_map_row( ?array $map, int $stored, bool $start ): array {
		if ( $map ) {
			return [ 'label' => 'Site map', 'value' => self::map_text( $map ), 'state' => VendorMaps::status_of( $map ) === 'confirmed' ? 'ok' : '' ];
		}
		if ( ! $stored ) {
			return [ 'label' => 'Site map', 'value' => 'None yet, and none of our products’ pages on it is stored yet to start one from. Product Data reads them once their pages are found (Find pages); Store this page stores this one.', 'state' => 'missing' ];
		}
		return [ 'label' => 'Site map', 'value' => sprintf( 'None yet. %s it reads our products’ %d stored pages for where the site keeps pictures, description, requirements and manuals, and groups the pages into layouts.', $start ? 'Start it below:' : 'Starting it (Product Data, Site maps):', $stored ), 'state' => 'missing' ];
	}

	/** A page address as the site's lists compare it: no scheme, no www, no trailing slash. */
	private static function page_key( string $url ): string {
		$p = wp_parse_url( UrlFinder::clean_url( $url ) );
		return preg_replace( '/^www\./', '', strtolower( (string) ( $p['host'] ?? '' ) ) ) . rtrim( strtolower( (string) ( $p['path'] ?? '' ) ), '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	/** The site's page list against our products. @return array<int,array> panel rows */
	private static function site_pages( string $host, ?array $list ): array {
		global $wpdb;
		$listed = array_flip( array_map( [ __CLASS__, 'page_key' ], (array) $wpdb->get_col( $wpdb->prepare( 'SELECT url FROM ' . VendorCatalog::table() . ' WHERE host = %s LIMIT %d', $host, VendorCatalog::MAX_ITEMS ) ) ) );
		$ours   = [];
		foreach ( VendorMaps::site_products( $host ) as $p ) {
			$ours[ self::page_key( (string) $p['url'] ) ][] = (int) $p['id'];
		}
		$proposed = array_flip( array_map( [ __CLASS__, 'page_key' ], (array) $wpdb->get_col( $wpdb->prepare( 'SELECT url FROM ' . UrlFinder::table() . " WHERE host IN (%s, %s) AND status = 'candidate' LIMIT 5000", $host, 'www.' . $host ) ) ) );
		$matched  = count( array_intersect_key( $listed, $ours ) );
		$waiting  = count( array_diff_key( array_intersect_key( $listed, $proposed ), $ours ) );
		$others   = count( $listed ) - $matched - $waiting;
		$missing  = array_sum( array_map( 'count', array_diff_key( $ours, $listed ) ) );
		$sources  = [ 'shopify' => 'the shop’s own product list (Shopify)', 'woo' => 'the shop’s own product list (WooCommerce)', 'wp' => 'the site’s own page list (WordPress)', 'sitemap' => 'its sitemap', 'browser' => 'its sitemap, read in your browser' ];
		if ( ! $listed ) {
			$why = $list && ! empty( $list['blocked'] ) ? 'the site refused the server when it read the list' : 'its sitemap and shop lists gave none';
			return [ [ 'label' => 'Page list', 'value' => 'None yet: ' . $why . '. Read its page list in this browser below, or pick the product links on a category page (coming: plan §13 S4).', 'state' => 'missing' ] ];
		}
		return array_values(
			array_filter(
				[
					[ 'label' => 'Page list', 'value' => sprintf( '%s pages, from %s, read %s', number_format( count( $listed ) ), $sources[ (string) ( $list['source'] ?? '' ) ] ?? 'its sitemap', $list ? mysql2date( 'M j, Y', (string) $list['at'] ) : '?' ), 'state' => 'ok' ],
					[ 'label' => 'Our products’ pages', 'value' => sprintf( '%s of them are pages of our products', number_format( $matched ) ), 'state' => $matched ? 'ok' : '' ],
					$waiting ? [ 'label' => 'Proposed for our products', 'value' => sprintf( '%s wait as proposals in Product pages', number_format( $waiting ) ), 'state' => 'warn', 'href' => Module::workbench_url( [ 'site' => $host, 'sec' => 'pages' ] ) ] : null,
					$others > 0 ? [ 'label' => 'The maker’s products we don’t sell', 'value' => sprintf( '%s pages, listed only', number_format( $others ) ), 'state' => '' ] : null,
					$missing ? [ 'label' => 'Our products not in the list', 'value' => sprintf( '%s products’ pages aren’t in it: they may have moved or be unlisted', number_format( $missing ) ), 'state' => 'warn' ] : null,
				]
			)
		);
	}

	/**
	 * The site's layouts (SiteGaps groups our products' stored pages by structure): how many, then each, largest
	 * first. $m: SiteGaps::matrix(), null without a site map. @return array<int,array> panel rows
	 */
	private static function site_layouts( string $host, ?array $m, bool $mapped ): array {
		if ( ! $m || ! $m['layouts'] ) {
			return [ [ 'label' => 'Layouts', 'value' => $mapped ? 'Not grouped yet: they show here once our products’ stored pages on it are grouped.' : 'Not grouped yet: starting the site map groups our products’ stored pages into layouts.', 'state' => '' ] ];
		}
		$types = [];
		foreach ( MapTypes::layouts_view( $host ) as $l ) {
			$types[ (int) $l['i'] ] = $l['type'];
		}
		$rows = [];
		$ones = 0;
		$read = [ 'images' => 'pictures', 'description' => 'description', 'os' => 'requirements', 'manual' => 'manuals', 'videos' => 'videos' ];
		foreach ( $m['layouts'] as $l ) {
			if ( (int) $l['pages'] < 2 ) {
				++$ones;
				continue;
			}
			$parts = [];
			foreach ( $read as $f => $name ) {
				$p        = array_map( 'intval', (array) ( $l['per'][ $f ] ?? [ 0, 0, 0 ] ) );
				$parts[] = $name . ' ' . ( $p[0] + $p[1] ) . '/' . array_sum( $p );
			}
			$t      = $types[ (int) $l['i'] ] ?? null;
			$own    = $t ? ' · its own picks (' . ( ( $t['status'] ?? '' ) === 'confirmed' ? 'confirmed' : 'draft' ) . ')' : '';
			$sample = (string) ( $l['samples'][0] ?? '' );
			$row    = [ 'label' => sprintf( 'Layout %s · %d pages', self::letter( (int) $l['i'] ), (int) $l['pages'] ), 'value' => 'Reads ' . implode( ', ', $parts ) . $own . ( $sample !== '' ? ' · click to open a page of it' : '' ), 'state' => $t ? 'add' : '' ];
			$rows[] = $sample !== '' ? $row + [ 'action' => [ 'kind' => 'go', 'url' => $sample ] ] : $row;
		}
		if ( $ones ) {
			$rows[] = [ 'label' => 'One-page layouts', 'value' => sprintf( '%d pages each look like no other page of the site', $ones ), 'state' => '' ];
		}
		$pages = array_sum( array_map( static fn( $l ) => (int) $l['pages'], $m['layouts'] ) );
		$many  = count( $m['layouts'] ) - $ones;
		$head  = [
			'label' => 'Layouts',
			'value' => ( $many
				? sprintf( '%d %s of our products’ %d grouped pages%s. Click a layout to open one of its pages and map it.', $many, $many === 1 ? 'layout' : 'layouts', $pages, $ones ? sprintf( ', and %d pages built like no other', $ones ) : '' )
				: sprintf( 'No two of our products’ %d grouped pages are built alike%s: map each page itself.', $pages, (int) $m['products'] > $pages * 2 ? sprintf( ' (our %d products share them)', (int) $m['products'] ) : '' ) ),
			'state' => 'ok',
		];
		return array_merge( [ $head ], $rows );
	}

	/* ── The page's layout (protocol 5, plan §13 S2b) ─────────────────── */

	/**
	 * The layout this page is built like (2+ pages): each of its own picks, how many of its pages it reads, whether the
	 * reader uses it there, a Review of what committing it does to the layout's products (the panel's review sheet), Undo
	 * of the last layout commit, and Confirm. Null on a page of no layout, or of a one-page one.
	 */
	private static function layout_section( string $host, string $url ): ?array {
		$lay = MapTypes::layout_of( $host, $url );
		if ( ! $lay || $lay['pages'] < 2 ) {
			return null;
		}
		$name = 'Layout ' . self::letter( $lay['i'] );
		$sec  = [ 'title' => sprintf( '%s · %d pages built like this one', $name, $lay['pages'] ), 'href' => Module::workbench_url( [ 'site' => $host ] ), 'rows' => [], 'text' => '', 'actions' => [] ];
		$t    = MapTypes::for_layout( $host, $lay['i'] );
		$own  = $t && ! is_wp_error( $t ) ? array_filter( (array) $t['fields'], static fn( $f, $k ) => ! empty( $f['sel'] ) && isset( PartFill::PLAN_FIELD[ $k ] ), ARRAY_FILTER_USE_BOTH ) : [];
		if ( ! $own ) {
			$sec['text'] = sprintf( 'It has no picks of its own. Pick a part on this page and save it for %s: it reads all its pages, and this section then says what it writes to their products.', $name );
			return $sec;
		}
		$n     = count( PartFill::layout_products( $host, $t ) );
		$base  = [ 'kind' => 'layout', 'layout' => $lay['i'] ];
		$notes = [];
		foreach ( $own as $k => $f ) {
			$label = VendorMaps::FIELDS[ $k ][0] ?? $k;
			$live  = $t['status'] === 'confirmed' || ! empty( $f['live'] );
			$value = sprintf( 'This layout’s pick reads it on %d of its %d stored pages. ', (int) ( $f['found'] ?? 0 ), (int) ( $f['of'] ?? 0 ) ) . ( $live ? 'In use on its pages.' : 'Not in use yet: its products have what the site’s map reads.' );
			$plan  = get_transient( self::layout_key( $host, $t, (string) $k ) );
			$known = is_array( $plan ) && $plan['next'] === null;
			$sec['rows'][]    = [ 'label' => $label, 'value' => $value . ( $known ? ' ' . self::layout_summary( $plan['rows'] ) : '' ) ];
			$sec['actions'][] = $base + [ 'id' => 'layout-plan-' . $k, 'op' => 'plan', 'part' => $k, 'part_label' => $label, 'name' => $name, 'products' => $n, 'label' => sprintf( 'Review %s on its %d products', strtolower( $label ), $n ) ];
		}
		$last = get_transient( self::layout_run_key( $host, (string) $t['type_key'] ) );
		$sec['undo'] = is_array( $last ) ? [ self::layout_undo( $last, $base ) ] : [];
		if ( $t['status'] !== 'confirmed' ) {
			$sec['actions'][] = self::layout_confirm( $base, self::letter( $lay['i'] ) );
			$notes[]          = 'A commit puts a pick in use on this layout’s pages only, and only adds: a pick that reads less than the site’s map is refused.';
		}
		$sec['text'] = implode( ' ', $notes );
		return $sec;
	}

	/** Where a layout part's plan is kept: per pick and the site's stored pages. */
	private static function layout_key( string $host, array $t, string $part ): string {
		$f = (array) ( $t['fields'][ $part ] ?? [] );
		return 'mmpt_ext_lp2_' . md5( implode( '|', [ VendorMaps::host( $host ), $t['type_key'], $part, $f['sel'] ?? '', $f['mode'] ?? '', $f['src'] ?? '', VendorMaps::pages_sig( $host ) ] ) );
	}

	/** The runs of the last layout commit (one part, or one batch of parts; the old one-run record too). */
	private static function layout_runs( array $last ): array {
		return isset( $last['runs'] ) ? (array) $last['runs'] : [ [ 'run' => $last['run'] ?? '', 'part' => $last['part'] ?? '', 'products' => $last['products'] ?? 0 ] ];
	}

	/** Undo for the last layout commit, as an undo line. */
	private static function layout_undo( array $last, array $base ): array {
		$runs  = self::layout_runs( $last );
		$parts = implode( ', ', array_map( static fn( $x ) => strtolower( VendorMaps::FIELDS[ $x['part'] ][0] ?? $x['part'] ), $runs ) );
		return [ 'text' => sprintf( 'Last commit to the layout: %s, %d products.', $parts, max( array_map( static fn( $x ) => (int) $x['products'], $runs ) ) ), 'action' => $base + [ 'id' => 'layout-undo', 'op' => 'undo', 'label' => 'Undo' ] ];
	}

	/** Confirm for a layout, as a More action. */
	private static function layout_confirm( array $base, string $letter ): array {
		return $base + [ 'id' => 'layout-confirm', 'op' => 'confirm', 'label' => sprintf( 'Use Layout %s’s picks from now on', $letter ), 'help' => 'Reads of its pages then use all its picks. It writes nothing to products by itself.' ];
	}

	/** Where the last layout write is kept, for its Undo. */
	private static function layout_run_key( string $host, string $type ): string {
		return 'mmpt_ext_lr_' . md5( VendorMaps::host( $host ) . '|' . $type );
	}

	/** An outcome's [ tone, name, meaning, what to do ]; a decision with no words of its own changes nothing. */
	private static function outcome( string $action ): array {
		return self::OUTCOMES[ $action ] ?? [ 'same', 'Stay as they are', 'Nothing here changes them.' ];
	}

	private static function is_write( string $action ): bool {
		return self::outcome( $action )[0] === 'change';
	}

	/** A plan's decisions counted in words: "49 will change (49 rewritten). 12 won't, worth a look (10 pick finds nothing …). 10 stay as they are." */
	private static function layout_summary( array $rows ): string {
		$by = [ 'change' => [], 'look' => [], 'same' => [] ];
		foreach ( array_count_values( array_map( static fn( $r ) => (string) $r['action'], $rows ) ) as $action => $n ) {
			$o                   = self::outcome( (string) $action );
			$by[ $o[0] ][ $o[1] ] = ( $by[ $o[0] ][ $o[1] ] ?? 0 ) + $n;
		}
		$list = static fn( array $names ) => implode( ', ', array_map( static fn( $name, $n ) => $n . ' ' . lcfirst( $name ), array_keys( $names ), $names ) );
		$out  = $by['change'] ? sprintf( '%d will change (%s).', array_sum( $by['change'] ), $list( $by['change'] ) ) : 'None will change.';
		$out .= $by['look'] ? sprintf( ' %d won’t, worth a look (%s).', array_sum( $by['look'] ), $list( $by['look'] ) ) : '';
		return $out . ( $by['same'] ? sprintf( ' %d stay as they are.', array_sum( $by['same'] ) ) : '' );
	}

	/**
	 * A finished plan as the review shows it: the part's products by outcome, what would change first, then what is worth
	 * a look. `ids`: the products a commit writes to; `live`: the pick is in use already (a commit otherwise also puts it in
	 * use on the layout's pages). @return array{part:string,label:string,total:int,at:int,live:bool,ids:int[],groups:array}
	 */
	private static function review_of( string $part, array $rows, int $at, bool $live ): array {
		$label  = VendorMaps::FIELDS[ $part ][0] ?? $part;
		$groups = [];
		foreach ( $rows as $row ) {
			$action = (string) $row['action'];
			$o      = self::outcome( $action );
			$groups[ $action ] ??= [ 'key' => $action, 'tone' => $o[0], 'title' => $o[1], 'text' => sprintf( $o[2], strtolower( $label ) ), 'fix' => (string) ( $o[3] ?? '' ), 'n' => 0, 'products' => [] ];
			++$groups[ $action ]['n'];
			if ( count( $groups[ $action ]['products'] ) < self::REVIEW_MAX ) {
				$groups[ $action ]['products'][] = [ 'id' => (int) $row['id'], 'title' => html_entity_decode( (string) $row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'url' => (string) $row['url'] ];
			}
		}
		$order = [ 'change' => 0, 'look' => 1, 'same' => 2 ];
		uasort( $groups, static fn( $a, $b ) => [ $order[ $a['tone'] ], $b['n'] ] <=> [ $order[ $b['tone'] ], $a['n'] ] );
		return [ 'part' => $part, 'label' => $label, 'total' => count( $rows ), 'at' => $at, 'live' => $live, 'ids' => array_values( array_map( static fn( $r ) => (int) $r['id'], array_filter( $rows, static fn( $r ) => self::is_write( (string) $r['action'] ) ) ) ), 'groups' => array_values( $groups ) ];
	}

	/** Layout 0 → A, as the gaps name them. */
	private static function letter( int $i ): string {
		return $i < 26 ? chr( 65 + $i ) : (string) ( $i + 1 );
	}

	/** The work the site's product pages hold, in words (the gaps matrix's cells, WORK_CELLS). */
	private static function site_work( string $host ): string {
		$m = VendorMaps::get( $host ) ? SiteGaps::matrix( $host, false ) : null;
		if ( ! $m ) {
			return '';
		}
		$n = [];
		foreach ( $m['rows'] as $row ) {
			foreach ( self::WORK_FIELDS as $f ) {
				$c = (string) ( $row['cells'][ $f ][0] ?? '' );
				if ( isset( self::WORK_CELLS[ $c ] ) ) {
					$n[ SiteGaps::LABELS[ $f ] . ' ' . self::WORK_CELLS[ $c ] ] = ( $n[ SiteGaps::LABELS[ $f ] . ' ' . self::WORK_CELLS[ $c ] ] ?? 0 ) + 1;
				}
			}
		}
		if ( ! $n ) {
			return sprintf( 'Its %d products’ pages hold no work right now.', (int) $m['products'] );
		}
		arsort( $n );
		return sprintf( 'Work on its %d products’ pages: ', (int) $m['products'] ) . implode( '; ', array_map( static fn( $what, $k ) => $what . ' on ' . $k, array_keys( $n ), $n ) ) . '.';
	}

	/**
	 * One product as a panel row: its title, brand and whether this page is its verified page or a proposal. Protocol 2:
	 * a verified one shows its before/after when clicked (focus).
	 */
	private static function product_row( int $id, bool $verified, bool $v2 = false ): array {
		$brands = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
		$brand  = is_array( $brands ) && $brands ? (string) $brands[0] : '';
		$match  = $verified ? self::match_of( $id ) : null;
		$row    = [
			'label' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'value' => trim( ( $brand !== '' ? $brand . ' · ' : '' ) . '#' . $id . ' · ' . ( $verified ? 'this is its vendor URL' : 'proposed as its page' ) ),
			'state' => $verified ? 'ok' : 'warn',
			'status' => $match ? [ 'kind' => $match['kind'], 'word' => $match['word'], 'help' => $match['help'] ] : null,
			'href'  => Module::workbench_url( [ 'q' => (string) $id ] ),
		];
		if ( ! $verified && $v2 ) { // proposed for this page: decided here
			$row['proposal'] = [ 'product' => $id, 'title' => $row['label'] ];
		}
		return $v2 && $verified ? [ 'action' => [ 'kind' => 'focus', 'product' => $id ] ] + $row : $row;
	}

	/** Products with an open or approved proposal of exactly this page. @return int[] */
	private static function proposed_for( string $url ): array {
		global $wpdb;
		$alt = str_ends_with( $url, '/' ) ? rtrim( $url, '/' ) : $url . '/';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT product_id FROM ' . UrlFinder::table() . " WHERE url IN (%s, %s) AND status IN ('candidate','approved') LIMIT 100", $url, $alt ) ) );
	}

	/** Brands whose maker sites include this host: its Brand website, or a saved site role other than "not this brand". @return string[] */
	private static function brands_for( string $host ): array {
		$roles = BrandSites::roles();
		$names = [];
		foreach ( get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false ] ) as $t ) { // term meta primed in the same query
			$role = $roles[ $t->term_id ][ $host ] ?? '';
			if ( ( $role !== '' && $role !== 'notbrand' ) || ( $role === '' && BrandSites::website_host( (int) $t->term_id ) === $host ) ) {
				$names[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		return $names;
	}

	private static function stored_text( ?array $row ): string {
		if ( ! $row ) {
			return 'None yet';
		}
		$when = mysql2date( 'M j, Y', (string) $row['fetched_at'] );
		if ( $row['status'] !== 'ok' ) {
			return in_array( (string) $row['status'], self::REFUSED, true ) ? 'The site refused the server (' . $when . ')' : 'Not readable (' . $row['status'] . ', ' . $when . ')';
		}
		$how = [ 'capture' => 'from your browser', 'chromium' => 'drawn by the server’s browser', 'http' => 'read by the server' ][ (string) ( $row['method'] ?? '' ) ] ?? 'stored';
		return ucfirst( $how ) . ', ' . $when;
	}

	private static function stored_state( ?array $row ): string {
		return ! $row ? 'missing' : ( $row['status'] === 'ok' ? 'ok' : 'warn' );
	}

	private static function map_text( ?array $map ): string {
		if ( ! $map ) {
			return 'None yet';
		}
		$parts = [];
		foreach ( array_diff_key( (array) $map['fields'], VendorMaps::NOT_MAPPED ) as $k => $f ) {
			if ( ! empty( $f['sel'] ) ) {
				$parts[] = ucfirst( str_replace( '_', ' ', (string) $k ) );
			}
		}
		$status = [ 'confirmed' => 'Confirmed', 'draft' => 'Draft', 'broken' => 'Confirmed, a part broken', 'refused' => 'Draft (site refuses the server)', 'off' => 'Off', 'auto' => 'Reads fine without a map', 'needs' => 'Needs a map' ][ VendorMaps::status_of( $map ) ] ?? ucfirst( VendorMaps::status_of( $map ) );
		return $status . ( $parts ? ' · mapped: ' . implode( ', ', $parts ) : ( VendorMaps::status_of( $map ) === 'auto' ? '' : ' · nothing mapped' ) );
	}

	private static function short_url( string $url ): string {
		$p = wp_parse_url( $url );
		return preg_replace( '/^www\./', '', (string) ( $p['host'] ?? '' ) ) . ( $p['path'] ?? '' );
	}

	/* ── Storing a page ─────────────────────────────────────────────────── */

	/**
	 * POST /ext/snapshot {url, html, dry, product}: store the page as the browser drew it, under its own address and
	 * every product address naming it (LiveMapper::store(), the keep-better-copy rule). dry=1 runs every check and
	 * stores nothing.
	 */
	public static function snapshot( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$html = (string) $r->get_param( 'html' );
		$bad  = self::bad_html( $html );
		if ( $bad !== '' ) {
			return new \WP_REST_Response( [ 'message' => $bad ], 400 );
		}
		$reads = PageStore::reads( $html, $url );
		$out   = [ 'url' => $url, 'bytes' => strlen( $html ), 'text_chars' => $reads['text'], 'images' => $reads['pictures'] ];
		if ( $r->get_param( 'dry' ) ) {
			$choice = ChoiceBundles::product_for( $url, (int) $r->get_param( 'product' ) );
			$said   = $choice && ChoiceBundles::is( $choice ) ? [ 'message' => sprintf( 'Checked, nothing saved: %d choices on this page.', count( ChoiceBundles::cards( $html, $url ) ) ) ] : [];
			return rest_ensure_response( $out + [ 'dry' => true, 'would_store' => count( array_unique( array_merge( [ $url ], array_keys( LiveMapper::same_page( $url ) ) ) ) ) ] + $said );
		}
		// A choice bundle's page, or another page of its list: the cards shown are read into its list of choices.
		$choice = ChoiceBundles::product_for( $url, (int) $r->get_param( 'product' ) );
		$said   = '';
		if ( $choice && ChoiceBundles::is( $choice ) ) {
			$said = self::choice_said( ChoiceBundles::read( $choice, $url, $html ) );
			if ( ChoiceBundles::base_of( $url ) !== $url ) { // a list page isn't a page of its own to store
				return rest_ensure_response( $out + [ 'message' => $said, 'panel' => self::panel( $url, $choice, self::client( $r ) ) ] );
			}
		}
		$st = self::store( $url, $html );
		if ( is_wp_error( $st ) ) {
			return new \WP_REST_Response( [ 'message' => $st->get_error_message() ], 400 );
		}
		return rest_ensure_response( $out + [ 'stored' => count( $st['stored'] ), 'kept' => count( $st['kept'] ), 'kept_reads' => $st['reads'] ?? null, 'panel' => self::panel( $url, (int) $r->get_param( 'product' ), self::client( $r ) ) ] + ( $said !== '' ? [ 'message' => $said ] : [] ) );
	}

	/** Why this HTML isn't a page to store ('' when it is). */
	private static function bad_html( string $html ): string {
		if ( $html === '' || strlen( $html ) > LiveMapper::MAX_BYTES || stripos( $html, '<html' ) === false ) {
			return 'The page was empty or too large to store.';
		}
		return PageStore::is_wall( $html ) ? 'That is the site’s bot check, not its page. Nothing was stored.' : '';
	}

	/** Store the page as drawn (LiveMapper::store()), with its audit line. @return array|\WP_Error */
	private static function store( string $url, string $html ) {
		$st = LiveMapper::store( $url, $html );
		if ( is_wp_error( $st ) ) {
			return $st;
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_page.ext_snapshot', [ 'object_type' => 'vendor_page', 'object_id' => mb_substr( $url, 0, 100 ), 'details' => [ 'bytes' => strlen( $html ), 'addresses' => count( $st['stored'] ), 'kept' => count( $st['kept'] ) ] ] );
		}
		Module::info( 'Vendor page stored from the Chrome extension', [ 'url' => $url, 'bytes' => strlen( $html ), 'addresses' => count( $st['stored'] ), 'kept' => count( $st['kept'] ) ] );
		return $st;
	}

	/* ── Working on a product (protocol 2) ──────────────────────────────── */

	/**
	 * POST /ext/map {url, op, field, sel, mode, html, warn, where, layout}: the live mapper on this page. op hello: the
	 * site's map (LiveMapper::hello()) and the layout this page is (MapTypes::layout_of(), from its stored copy or the
	 * page as drawn: plan §13 S2) with that layout's own picks; test / save: the page as drawn is stored, then the pick
	 * is tested or saved with Save's guards (LiveMapper::act()), for the whole site or, with layout, as that layout's own.
	 */
	public static function map( \WP_REST_Request $r ) {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$op = sanitize_key( (string) $r->get_param( 'op' ) );
		if ( $op === 'hello' ) {
			return self::map_hello( $url, (string) $r->get_param( 'html' ) );
		}
		if ( $op === 'effect' ) { // what the pick being made would do to the products it reaches, before anything is saved
			return self::map_effect( $r, $url );
		}
		if ( $op === 'reads' ) { // how the pages this pick reaches are stored: what the mapper judges a pick against
			return rest_ensure_response( LiveMapper::reads( $url ) );
		}
		if ( $op === 'proposal' ) { // a person's decision on Claude's proposal, made inspecting it on the page (MapProposals)
			$p    = MapProposals::get( absint( $r->get_param( 'proposal' ) ) );
			$host = VendorMaps::host( $url );
			if ( ! $p || $p['host'] !== $host ) {
				return new \WP_REST_Response( [ 'message' => 'That proposal isn’t this site’s (or it was made again): close the mapper and open it again.' ], 404 );
			}
			$approve = sanitize_key( (string) $r->get_param( 'decision' ) ) === 'approve';
			if ( $r->get_param( 'dry' ) ) {
				return rest_ensure_response( [ 'dry' => true, 'message' => sprintf( 'Checked, nothing changed: %s would be %s.', VendorMaps::FIELDS[ $p['field'] ][0] ?? $p['field'], $approve ? 'approved as ' . ( $p['layout'] < 0 ? 'the site’s' : 'Layout ' . self::letter( $p['layout'] ) . '’s' ) . ' criteria' : 'rejected' ), 'proposal' => MapProposals::out( $p ) ] );
			}
			$res = MapProposals::decide( $p['id'], $approve, get_current_user_id() );
			if ( is_wp_error( $res ) ) {
				return new \WP_REST_Response( [ 'message' => $res->get_error_message() ], 409 );
			}
			$hello = self::map_hello( $url, '' );
			return rest_ensure_response( ( $hello->get_status() === 200 ? (array) $hello->get_data() : [] ) + [ 'proposal' => MapProposals::out( $res ) ] );
		}
		if ( $op === 'undo' ) { // the site's pick for a part put back as it was before its last change (§6 item 7b)
			$field = sanitize_key( (string) $r->get_param( 'field' ) );
			if ( ! isset( VendorMaps::FIELDS[ $field ] ) ) {
				return new \WP_REST_Response( [ 'message' => 'Unknown part.' ], 400 );
			}
			$res = VendorMaps::undo_part( VendorMaps::site_of( $url ), $field );
			if ( is_wp_error( $res ) ) {
				return new \WP_REST_Response( [ 'message' => $res->get_error_message() ], 409 );
			}
			$hello = self::map_hello( $url, '' );
			return rest_ensure_response( ( $hello->get_status() === 200 ? (array) $hello->get_data() : [] ) + [ 'undone' => $field ] );
		}
		if ( $op === 'clear' ) { // a saved pick taken away, for the site or the layout the mapper is on
			return self::map_clear( $r, $url );
		}
		if ( $op === 'skip' ) { // the mapper's "Leave these lines out on this site": the description reader's list of lines to leave out
			$lines = array_slice( array_values( array_filter( array_map( static fn( $t ) => trim( sanitize_text_field( (string) $t ) ), (array) $r->get_param( 'lines' ) ) ) ), 0, 20 );
			if ( ! $lines ) {
				return new \WP_REST_Response( [ 'message' => 'No lines to leave out.' ], 400 );
			}
			$host = VendorMaps::host( $url );
			$all  = MapCheck::set_text_skips( $host, $lines, [] );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'vendor_maps.text_skip', [ 'object_type' => 'vendor_site', 'object_id' => $host, 'details' => [ 'added' => count( $lines ), 'via' => 'extension' ] ] );
			}
			return rest_ensure_response( [ 'added' => count( $lines ), 'lines' => count( $all ) ] );
		}
		if ( $op === 'check' ) { // the pick under Test, checked across the site's stored pages (MapCheck; §6 item 7a). Read-only.
			$part = sanitize_key( (string) $r->get_param( 'field' ) );
			$sel  = trim( sanitize_text_field( (string) $r->get_param( 'sel' ) ) );
			if ( $sel === '' || ! isset( VendorMaps::MODES[ $part ] ) ) {
				return rest_ensure_response( [ 'lines' => [] ] );
			}
			if ( PageStore::server_busy() ) {
				return rest_ensure_response( [ 'busy' => true, 'lines' => [] ] );
			}
			$modes = VendorMaps::MODES[ $part ];
			$mode  = in_array( sanitize_key( (string) $r->get_param( 'mode' ) ), $modes, true ) ? sanitize_key( (string) $r->get_param( 'mode' ) ) : $modes[0];
			$res   = MapCheck::run( VendorMaps::site_of( $url ), $part, [ 'sel' => $sel, 'mode' => $mode ] );
			return rest_ensure_response( is_wp_error( $res ) ? [ 'lines' => [], 'message' => $res->get_error_message() ] : MapCheck::summary( $res ) );
		}
		if ( ! in_array( $op, [ 'test', 'save' ], true ) ) {
			return new \WP_REST_Response( [ 'message' => 'Unknown mapper request.' ], 400 );
		}
		$html = (string) $r->get_param( 'html' );
		if ( $html !== '' && PageStore::is_wall( $html ) ) {
			return new \WP_REST_Response( [ 'message' => 'That is the site’s bot check, not its page. Nothing was stored or saved.' ], 400 );
		}
		$q = new \WP_REST_Request( 'POST', '/' . Rest::NS . '/maps/live' );
		foreach ( [ 'field', 'sel', 'mode', 'html', 'warn', 'where' ] as $k ) {
			$q->set_param( $k, (string) $r->get_param( $k ) );
		}
		$q->set_param( 'url', $url );
		$q->set_param( 'save', $op === 'save' ? 1 : 0 );
		if ( is_numeric( $r->get_param( 'layout' ) ) && (int) $r->get_param( 'layout' ) >= 0 ) {
			$q->set_param( 'layout', (int) $r->get_param( 'layout' ) );
		}
		return LiveMapper::act( $q );
	}

	/** The mapper's hello: the site's map, this page's layout (its own picks) and Claude's proposals for its parts. */
	/**
	 * What a pick would do to its products, in slices the mapper asks for one after another: the panel's commit report, at
	 * the moment of picking. Read-only. A finished answer is kept per pick and stored pages, so a pick looked at again is
	 * answered at once; on a busy server it says so and stops.
	 */
	private static function map_effect( \WP_REST_Request $r, string $url ): \WP_REST_Response {
		$part = sanitize_key( (string) $r->get_param( 'field' ) );
		$sel  = trim( sanitize_text_field( (string) $r->get_param( 'sel' ) ) );
		if ( ! isset( PartFill::PLAN_FIELD[ $part ] ) || $sel === '' || is_wp_error( VendorMaps::css_to_xpath( $sel, $part === 'pictures' ) ) ) {
			return rest_ensure_response( [ 'message' => '' ] ); // a part with no plan (Audio demos), or a selector being typed
		}
		$host  = VendorMaps::site_of( $url );
		$modes = VendorMaps::MODES[ $part ];
		$mode  = in_array( sanitize_key( (string) $r->get_param( 'mode' ) ), $modes, true ) ? sanitize_key( (string) $r->get_param( 'mode' ) ) : $modes[0];
		$src   = $mode === 'page' ? $url : '';
		$i     = is_numeric( $r->get_param( 'layout' ) ) ? (int) $r->get_param( 'layout' ) : null;
		$key   = 'mmpt_ext_fx2_' . md5( implode( '|', [ $host, $i ?? 'site', $part, $sel, $mode, $src, VendorMaps::pages_sig( $host ) ] ) );
		$kept  = get_transient( $key );
		$kept  = is_array( $kept ) ? $kept : null;
		$say   = static function ( array $k ) use ( $i ): array {
			$who = $i === null ? sprintf( 'the site’s %d products', $k['total'] ) : sprintf( 'Layout %s’s %d products', self::letter( $i ), $k['total'] );
			return [ 'next' => null, 'done' => $k['total'], 'total' => $k['total'], 'message' => sprintf( 'If committed to %s: %s', $who, self::layout_summary( $k['rows'] ) ) ];
		};
		if ( $kept && $kept['next'] === null ) {
			return rest_ensure_response( $say( $kept ) );
		}
		if ( PageStore::server_busy() ) {
			return rest_ensure_response( [ 'busy' => true, 'message' => '' ] );
		}
		$offset = $kept ? (int) $kept['next'] : 0;
		$res    = PartFill::trial_plan( $host, $i, $part, [ 'sel' => $sel, 'mode' => $mode, 'src' => $src, 'source' => 'person' ], $offset, $kept ? (array) $kept['ids'] : null );
		if ( is_wp_error( $res ) ) {
			return rest_ensure_response( [ 'message' => '' ] );
		}
		$rows = array_merge( $kept ? (array) $kept['rows'] : [], array_map( static fn( $x ) => [ 'action' => (string) $x['action'] ], $res['rows'] ) );
		$now  = [ 'rows' => $rows, 'next' => $res['next'], 'total' => $res['total'], 'ids' => $res['ids'] ];
		set_transient( $key, $now, self::LAYOUT_S );
		return rest_ensure_response( $res['next'] === null ? $say( $now ) : [ 'next' => $res['next'], 'done' => count( $rows ), 'total' => $res['total'] ] );
	}

	/** A part's saved pick cleared: the layout's own (its pages go back to the site's pick) or the site's. */
	private static function map_clear( \WP_REST_Request $r, string $url ): \WP_REST_Response {
		$host  = VendorMaps::site_of( $url );
		$field = sanitize_key( (string) $r->get_param( 'field' ) );
		$i     = is_numeric( $r->get_param( 'layout' ) ) ? (int) $r->get_param( 'layout' ) : null;
		$name  = VendorMaps::FIELDS[ $field ][0] ?? $field;
		if ( ! isset( VendorMaps::FIELDS[ $field ] ) ) {
			return new \WP_REST_Response( [ 'message' => 'Unknown part.' ], 400 );
		}
		if ( $r->get_param( 'dry' ) ) {
			return rest_ensure_response( [ 'dry' => true, 'message' => sprintf( 'Checked, nothing changed: %s’s pick would be cleared.', $name ) ] );
		}
		if ( $i !== null ) {
			$t = MapTypes::for_layout( $host, $i );
			if ( ! $t || is_wp_error( $t ) || empty( $t['fields'][ $field ] ) ) {
				return new \WP_REST_Response( [ 'message' => 'This layout has no pick of its own for that part.' ], 409 );
			}
			MapTypes::set_part( $host, (string) $t['type_key'], $field, null );
		} else {
			$res = VendorMaps::drop_kept( $host, $field, $url ) ?? VendorMaps::set_field( $host, $field, '', '' );
			if ( is_wp_error( $res ) ) {
				return new \WP_REST_Response( [ 'message' => $res->get_error_message() ], 400 );
			}
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_maps.field_clear', [ 'object_type' => 'vendor_site', 'object_id' => $host, 'details' => [ 'field' => $field, 'layout' => $i, 'via' => 'extension' ] ] );
		}
		$hello = self::map_hello( $url, '' );
		return rest_ensure_response( ( $hello->get_status() === 200 ? (array) $hello->get_data() : [] ) + [ 'cleared' => $field ] );
	}

	private static function map_hello( string $url, string $html ): \WP_REST_Response {
		$q = new \WP_REST_Request( 'GET', '/' . Rest::NS . '/maps/live' );
		$q->set_param( 'url', $url );
		$res = LiveMapper::hello( $q );
		if ( $res->get_status() !== 200 ) {
			return $res;
		}
		if ( ! empty( $res->get_data()['away'] ) ) {
			return rest_ensure_response( $res->get_data() + [ 'layout' => null, 'proposals' => (object) [] ] ); // not one of the site's product pages
		}
		$host = VendorMaps::host( $url );
		$lay  = MapTypes::layout_of( $host, $url, $html !== '' && ! PageStore::is_wall( $html ) ? $html : '' );
		if ( $lay ) {
			foreach ( MapTypes::layouts_view( $host ) as $l ) {
				if ( $l['i'] === $lay['i'] ) {
					$lay += [ 'own' => (object) ( $l['type']['own'] ?? [] ), 'status' => (string) ( $l['type']['status'] ?? '' ) ];
				}
			}
			$lay['letter'] = self::letter( $lay['i'] );
		}
		$props = array_map( [ MapProposals::class, 'out' ], MapProposals::for_page( $host, $lay ? (int) $lay['i'] : null ) );
		return rest_ensure_response( $res->get_data() + [ 'layout' => $lay, 'proposals' => (object) $props, 'undo' => (object) VendorMaps::undoable( $host ) ] );
	}

	/**
	 * POST /ext/write {url, product, expect, html}: Write to this product. The page as drawn is stored first (it is the
	 * product's page as it is today: ProductPreview::browser_copy()), then the map editor's writer runs for this one
	 * product (ProductPreview::write_start(), refused when what it would write isn't what the panel showed: expect).
	 */
	public static function write( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$ctx = self::product_page( $r );
		if ( is_wp_error( $ctx ) ) {
			return new \WP_REST_Response( [ 'message' => $ctx->get_error_message() ], 400 );
		}
		[ $url, $id, $host ] = $ctx;
		$html = (string) $r->get_param( 'html' );
		if ( $r->get_param( 'dry' ) ) {
			return self::write_dry( $r, $url, $id, $host, $html );
		}
		if ( $html !== '' ) {
			$bad = self::bad_html( $html );
			$st  = $bad === '' ? self::store( $url, $html ) : new \WP_Error( 'mmpt_ext_html', $bad );
			if ( is_wp_error( $st ) ) {
				return new \WP_REST_Response( [ 'message' => $st->get_error_message() ], 400 );
			}
		}
		$copy = ProductPreview::browser_copy( $host, $id );
		if ( ! $copy || $copy['state'] !== 'done' ) {
			return new \WP_REST_Response( [ 'message' => (string) ( $copy['note'] ?? 'This page isn’t the product’s verified vendor URL.' ) ], 400 );
		}
		$res = ProductPreview::write_start( $host, $id, array_map( 'sanitize_key', (array) $r->get_param( 'expect' ) ), self::only( $r ) );
		if ( $res['state'] === 'error' ) {
			return new \WP_REST_Response( [ 'message' => (string) $res['note'], 'panel' => self::panel( $url, $id, self::client( $r ) ) ], (int) ( $res['code'] ?? 400 ) );
		}
		Module::info( 'Product write started from the Chrome extension', [ 'product_id' => $id, 'host' => $host ] );
		return rest_ensure_response( [ 'state' => $res['state'], 'panel' => self::panel( $url, $id, self::client( $r ) ) ] );
	}

	/** The parts a person ticked to commit (protocol 7); empty: every part. @return string[] */
	private static function only( \WP_REST_Request $r ): array {
		return array_values( array_intersect( array_map( 'sanitize_key', (array) $r->get_param( 'only' ) ), self::ROW_PART ) );
	}

	/** Write to this product, checked only: what would be written, and every refusal a write would give. */
	private static function write_dry( \WP_REST_Request $r, string $url, int $id, string $host, string $html ): \WP_REST_Response {
		$bad = $html !== '' ? self::bad_html( $html ) : '';
		if ( $bad !== '' ) {
			return new \WP_REST_Response( [ 'message' => $bad ], 400 );
		}
		$plan   = ProductPreview::plan( $host, $id, true );
		$parts  = $plan ? array_keys( ProductPreview::writes( $plan ) ) : [];
		$only   = self::only( $r );
		if ( $only ) {
			$parts = array_values( array_intersect( $parts, $only ) );
		}
		sort( $parts );
		$expect = array_map( 'sanitize_key', (array) $r->get_param( 'expect' ) );
		sort( $expect );
		$why = ! $plan ? 'This page isn’t the product’s verified vendor URL.' : ( $plan['skip'] !== '' ? ( self::SKIPS[ $plan['skip'] ] ?? 'Product Data leaves this product alone.' ) : ( ! $parts ? 'Nothing to write.' : ( $parts !== $expect ? 'What would be written changed since the panel showed it.' : '' ) ) );
		if ( $why !== '' ) {
			return new \WP_REST_Response( [ 'message' => $why ], 409 );
		}
		return rest_ensure_response( [ 'dry' => true, 'message' => 'Checked, nothing written: it would store this page and write ' . implode( ', ', $parts ) . '.', 'would_write' => $parts, 'panel' => self::panel( $url, $id, self::client( $r ) ) ] );
	}

	/**
	 * POST /ext/undo {url, product, what}: put back the last write (ProductPreview::undo_write()), the picture run that
	 * added the pictures picked here (Pictures::revert()), or the videos added here (only those still there).
	 */
	public static function undo( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$ctx = self::product_page( $r );
		if ( is_wp_error( $ctx ) ) {
			return new \WP_REST_Response( [ 'message' => $ctx->get_error_message() ], 400 );
		}
		[ $url, $id ] = $ctx;
		$what = sanitize_key( (string) $r->get_param( 'what' ) );
		if ( $what === 'write' ) {
			$res  = ProductPreview::undo_write( $id );
			$ok   = $res['state'] !== 'error';
			$said = $ok ? 'Put back: ' . ( $res['undone'] ? implode( ', ', $res['undone'] ) : 'nothing' ) . ( $res['kept'] ? '. Kept, because they were changed since: ' . implode( ', ', $res['kept'] ) : '' ) . '.' : (string) $res['note'];
		} elseif ( $what === 'pictures' ) {
			$res  = Pictures::revert( $id );
			$ok   = $res['result'] !== 'error';
			$said = $ok ? ( $res['result'] === 'queued' ? 'The picture runner puts the pictures back next.' : 'The pictures are back as they were.' ) : (string) ( $res['message'] ?? 'The pictures couldn’t be put back.' );
			if ( $ok ) {
				delete_post_meta( $id, Pictures::PICKED_META );
			}
		} elseif ( $what === 'videos' ) {
			$n    = self::undo_videos( $id );
			$ok   = true;
			$said = sprintf( '%d video(s) taken off.', $n );
		} elseif ( $what === 'audio' ) {
			$n    = self::undo_audio( $id );
			$ok   = true;
			$said = sprintf( '%d audio demo(s) taken off.', $n );
		} elseif ( $what === 'disclaimer' ) {
			$rec  = get_post_meta( $id, self::DISCLAIMER_META, true );
			$same = is_array( $rec ) && (string) \MMI_Product_Content_Registry::canonical_value( $id, 'disclaimer' ) === (string) $rec['now'];
			if ( $same ) {
				\MMI_Product_Content_Registry::store( $id, 'disclaimer', (string) $rec['was'], 'product-extension' );
			}
			delete_post_meta( $id, self::DISCLAIMER_META );
			$ok   = $same;
			$said = $same ? 'The disclaimer is back as it was.' : 'The disclaimer changed since, so it was kept.';
		} elseif ( $what === 'vetoes' ) {
			$rec = get_post_meta( $id, self::VETOES_META, true );
			$n   = 0;
			foreach ( (array) ( is_array( $rec ) ? ( $rec['rules'] ?? [] ) : [] ) as $rule ) {
				$n += PictureChoices::remove( (int) $rule ) ? 1 : 0;
			}
			delete_post_meta( $id, self::VETOES_META );
			$ok   = true;
			$said = sprintf( '%d picture(s) can be added again.', $n );
		} elseif ( $what === 'link' ) {
			$ok   = self::undo_link( $id );
			$said = $ok ? 'The product has its earlier page again.' : 'There is no link to undo, or the product’s page changed since.';
		} else {
			return new \WP_REST_Response( [ 'message' => 'Nothing to undo.' ], 400 );
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_maps.ext_undo', [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'what' => $what, 'ok' => $ok ] ] );
		}
		return new \WP_REST_Response( [ 'message' => $said, 'panel' => self::panel( $url, $id, self::client( $r ) ) ], $ok ? 200 : 400 );
	}

	/**
	 * POST /ext/pictures {url, product, pictures[], videos[], audio[], html}: pictures, videos and audio demos a person
	 * picked on the product's page go straight to the product (owner 2026-11-01). Pictures: Pictures::pick(), the
	 * runner's normal merge (added, never replacing, no duplicates, vetoes apply). Videos: YouTube videos the product
	 * doesn't have are added. Audio: SoundCloud tracks and sets it doesn't have (add_audio()).
	 */
	public static function pictures( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$ctx = self::product_page( $r );
		if ( is_wp_error( $ctx ) ) {
			return new \WP_REST_Response( [ 'message' => $ctx->get_error_message() ], 400 );
		}
		[ $url, $id ] = $ctx;
		$host = VendorMaps::host( $url );
		if ( (string) $r->get_param( 'op' ) === 'check' ) {
			return rest_ensure_response( self::pictures_check( $id, $host, (array) $r->get_param( 'pictures' ) ) + [ 'layout' => self::picture_layout( $host, $url ) ] );
		}
		if ( ProductPreview::skip( $id ) !== '' ) {
			return new \WP_REST_Response( [ 'message' => self::SKIPS[ ProductPreview::skip( $id ) ] ?? 'Product Data leaves this product alone.' ], 409 );
		}
		$pics = array_values( array_filter( array_map( 'strval', (array) $r->get_param( 'pictures' ) ), static fn( $u ) => preg_match( '#^https?://#i', $u ) && ! self::own_address( $u ) ) );
		$vids = (array) $r->get_param( 'videos' );
		$auds = array_values( array_filter( array_map( [ SoundCloud::class, 'url' ], array_map( 'strval', (array) $r->get_param( 'audio' ) ) ) ) );
		if ( ! $pics && ! $vids && ! $auds ) {
			return new \WP_REST_Response( [ 'message' => 'Pick at least one picture, video or audio demo first.' ], 400 );
		}
		$html = (string) $r->get_param( 'html' );
		if ( $r->get_param( 'dry' ) ) {
			$have = class_exists( '\MMI_Product_Content_Registry' ) ? array_map( [ '\MMI_Product_Content_Registry', 'youtube_id' ], (array) \MMI_Product_Content_Registry::canonical_value( $id, 'videos' ) ) : [];
			$new  = array_diff( array_filter( array_map( static fn( $u ) => class_exists( '\MMI_Product_Content_Registry' ) ? \MMI_Product_Content_Registry::youtube_id( (string) $u ) : '', $vids ) ), $have );
			$aud = count( self::new_audio( $id, $auds ) );
			return rest_ensure_response( [ 'dry' => true, 'message' => sprintf( 'Checked, nothing sent: %d picture(s) would go to the picture runner, %d video(s) and %d audio demo(s) would be added.', count( $pics ), count( array_unique( $new ) ), $aud ), 'panel' => self::panel( $url, $id, self::client( $r ) ) ] );
		}
		if ( $html !== '' && self::bad_html( $html ) === '' ) {
			self::store( $url, $html ); // the page as drawn, for the readers (the keep-better-copy rule decides)
		}
		$said = [];
		if ( $pics ) {
			$res    = Pictures::pick( $id, $pics, $url );
			$said[] = sprintf( '%d picture(s) sent to the picture runner: it adds the ones the product doesn’t have.', count( $res['urls'] ) );
		}
		if ( $vids ) {
			$added  = self::add_videos( $id, $vids );
			$said[] = $added ? sprintf( '%d video(s) added.', $added ) : 'The product has those videos already.';
		}
		if ( $auds ) {
			$added  = self::add_audio( $id, $auds );
			$said[] = $added ? sprintf( '%d audio demo(s) added.', $added ) : 'The product has those audio demos already.';
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_pictures.ext_pick', [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'host' => $host, 'pictures' => count( $pics ), 'videos' => count( $vids ), 'audio' => count( $auds ) ] ] );
		}
		Module::info( 'Pictures picked in the Chrome extension', [ 'product_id' => $id, 'host' => $host, 'pictures' => count( $pics ), 'videos' => count( $vids ), 'audio' => count( $auds ) ] );
		return rest_ensure_response( [ 'message' => implode( ' ', $said ), 'panel' => self::panel( $url, $id, self::client( $r ) ) ] );
	}

	/* ── Moving through a site (protocol 3) ─────────────────────────────── */

	/**
	 * POST /ext/veto {url, product, picture}: a picture this page offers never goes on the product (a product veto,
	 * PictureChoices; the merge, the preview and the runner all leave it out). Kept for Undo.
	 */
	public static function veto( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$ctx = self::product_page( $r );
		if ( is_wp_error( $ctx ) ) {
			return new \WP_REST_Response( [ 'message' => $ctx->get_error_message() ], 400 );
		}
		[ $url, $id, $host ] = $ctx;
		$pic = esc_url_raw( trim( (string) $r->get_param( 'picture' ) ), [ 'http', 'https' ] );
		if ( $pic === '' ) {
			return new \WP_REST_Response( [ 'message' => 'No picture named.' ], 400 );
		}
		if ( $r->get_param( 'dry' ) ) {
			return rest_ensure_response( [ 'dry' => true, 'message' => 'Checked, nothing changed: that picture would stay off this product.' ] );
		}
		$res = PictureChoices::add( 'veto', $id, $host, 0, $pic );
		if ( is_wp_error( $res ) ) {
			return new \WP_REST_Response( [ 'message' => $res->get_error_message() ], 400 );
		}
		$rec            = get_post_meta( $id, self::VETOES_META, true );
		$rec            = is_array( $rec ) ? $rec : [ 'rules' => [] ];
		$rec['rules'][] = (int) $res['id'];
		$rec['at']      = current_time( 'mysql', true );
		update_post_meta( $id, self::VETOES_META, $rec );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_pictures.ext_veto', [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'host' => $host, 'rule' => (int) $res['id'] ] ] );
		}
		return rest_ensure_response( [ 'message' => 'That picture won’t be added to this product.', 'panel' => self::panel( $url, $id, self::client( $r ) ) ] );
	}

	/** POST /ext/products {url, q}: products of the brands this site is a maker site of, to link this page to. */
	public static function products( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$terms = self::brand_terms( VendorMaps::host( $url ) );
		if ( ! $terms ) {
			return new \WP_REST_Response( [ 'message' => 'This site isn’t a maker site of any brand. Add it in the brand’s row in the Product Workbench’s Brands & sites first.' ], 400 );
		}
		$q = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		if ( mb_strlen( $q ) < 2 ) {
			return rest_ensure_response( [ 'rows' => [] ] );
		}
		global $wpdb;
		$num  = (int) ltrim( $q, '#' );
		$like = '%' . $wpdb->esc_like( $q ) . '%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand'
				 LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
				 WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')
				   AND tt.term_id IN (" . implode( ',', array_map( 'intval', $terms ) ) . ')
				   AND ( p.ID = %d OR p.post_title LIKE %s OR sku.meta_value = %s )
				 ORDER BY p.ID = %d DESC, p.post_status = \'publish\' DESC, p.post_title
				 LIMIT %d',
				$num,
				$like,
				$q,
				$num,
				self::SEARCH_MAX
			)
		);
		$here = array_merge( [], ...array_values( LiveMapper::same_page( $url ) ) );
		$rows = [];
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$cur  = (string) get_post_meta( $id, 'vendor_product_url', true );
			$ok   = UrlFinder::is_verified( get_post_meta( $id, 'vendor_product_url_verified', true ) );
			$same = in_array( $id, $here, true );
			$row  = [
				'label' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'value' => '#' . $id . ( get_post_status( $id ) !== 'publish' ? ' · ' . get_post_status( $id ) : '' ) . ' · ' . ( $same ? 'already uses this page' : ( $cur !== '' ? ( $ok ? 'its page: ' : 'unverified page: ' ) . self::short_url( $cur ) : 'no vendor URL' ) ),
				'state' => $same ? 'ok' : ( $cur !== '' && $ok ? 'warn' : 'missing' ),
			];
			$rows[] = $same ? $row : $row + [ 'action' => [ 'kind' => 'link', 'product' => $id ] ];
		}
		return rest_ensure_response( [ 'rows' => $rows ] );
	}

	/**
	 * POST /ext/link {url, product, confirm, html, dry}: this page becomes the product's vendor URL, decided by a
	 * person (UrlQueue::set_one(), the Product pages Save). A verified page that isn't this one is replaced only with
	 * confirm (the panel's second click). The page as drawn is stored for it and the product is read from it.
	 */
	public static function link( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$id    = (int) $r->get_param( 'product' );
		$terms = self::brand_terms( VendorMaps::host( $url ) );
		$mine  = $terms && get_post_type( $id ) === 'product' && array_intersect( $terms, array_map( 'intval', (array) wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'ids' ] ) ) );
		if ( ! $mine ) {
			return new \WP_REST_Response( [ 'message' => 'That product isn’t one of this site’s brands.' ], 400 );
		}
		$was = (string) get_post_meta( $id, 'vendor_product_url', true );
		$ver = (string) get_post_meta( $id, 'vendor_product_url_verified', true );
		if ( $was !== '' && UrlFinder::same_url( $was, $url ) && UrlFinder::is_verified( $ver ) ) {
			return new \WP_REST_Response( [ 'message' => 'This page is already its verified vendor URL.', 'panel' => self::panel( $url, $id, self::client( $r ) ) ], 200 );
		}
		if ( $was !== '' && UrlFinder::is_verified( $ver ) && ! $r->get_param( 'confirm' ) ) {
			return new \WP_REST_Response( [ 'confirm' => sprintf( '%s already has a verified page: %s. Replace it with this page?', html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), self::short_url( $was ) ), 'label' => 'Replace its page with this one' ], 409 );
		}
		$html = (string) $r->get_param( 'html' );
		$bad  = $html !== '' ? self::bad_html( $html ) : '';
		if ( $bad !== '' ) {
			return new \WP_REST_Response( [ 'message' => $bad ], 400 );
		}
		if ( $r->get_param( 'dry' ) ) {
			return rest_ensure_response( [ 'dry' => true, 'message' => 'Checked, nothing changed: this page would become the vendor URL of #' . $id . ( $was !== '' ? ' in place of ' . self::short_url( $was ) : '' ) . '.' ] );
		}
		$err = UrlQueue::set_one( $id, $url );
		if ( $err !== '' ) {
			return new \WP_REST_Response( [ 'message' => $err ], 400 );
		}
		update_post_meta( $id, self::LINK_META, [ 'url' => (string) get_post_meta( $id, 'vendor_product_url', true ), 'was' => $was, 'was_verified' => $ver, 'at' => current_time( 'mysql', true ) ] );
		if ( $html !== '' ) {
			self::store( $url, $html ); // under its own address and the product's, now that it names this page
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) { // nothing is written to the product here: its rows say what the page gives, and the review commits it
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.set', [ 'object_type' => 'product', 'object_id' => $id, 'details' => [ 'host' => VendorMaps::host( $url ), 'was' => $was, 'via' => 'extension' ] ] );
		}
		return rest_ensure_response( [ 'message' => 'Linked: this page is the product’s vendor URL now. Nothing is written to it until you commit.', 'panel' => self::panel( $url, $id, self::client( $r ) ) ] );
	}

	/**
	 * POST /ext/site {url, op: start, product, dry} (protocol 8): start the site's map from our products' stored pages
	 * (VendorMaps::draft() in the map runner, as Product Data's Site maps does), which groups the pages into layouts.
	 * A site with a map has its pages grouped again. Answers with the panel, its site section showing the progress.
	 */
	public static function site( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$host = VendorMaps::host( $url );
		$map  = VendorMaps::get( $host );
		if ( ! $map && ! self::brands_for( $host ) ) {
			return new \WP_REST_Response( [ 'message' => 'This site isn’t one of a brand’s maker sites. Add it in its brand’s row in the Product Workbench’s Brands & sites first.' ], 404 );
		}
		if ( sanitize_key( (string) $r->get_param( 'op' ) ) !== 'start' ) {
			return new \WP_REST_Response( [ 'message' => 'Unknown site action.' ], 400 );
		}
		$panel = static fn() => self::panel( $url, (int) $r->get_param( 'product' ), self::client( $r ) + [ 'site' => true ] );
		if ( ! $map && ! array_filter( VendorMaps::pages( $host ), static fn( $p ) => ( $p['status'] ?? '' ) === 'ok' ) ) {
			return new \WP_REST_Response( [ 'message' => 'None of our products’ pages on this site is stored yet, so there is nothing to start its map from.', 'panel' => $panel() ], 409 );
		}
		if ( ! empty( $r->get_param( 'dry' ) ) ) { // the browser check: nothing queued
			return rest_ensure_response( [ 'message' => $map ? 'Checked: its pages would be grouped again.' : 'Checked: its map would be started.', 'dry' => true, 'panel' => $panel() ] );
		}
		if ( ! $map ) {
			VendorMaps::queue( 'draft', $host );
		}
		VendorMaps::queue( 'refresh', $host ); // after the draft: a draft that maps nothing leaves the pages ungrouped
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', $map ? 'extension.site_regroup' : 'extension.site_map_start', [ 'object_type' => 'vendor_site', 'object_id' => $host ] );
		}
		return rest_ensure_response( [ 'message' => $map ? 'Grouping its pages again. The layouts show here when it’s done.' : 'Starting this site’s map. Its layouts show here when it’s done, usually within a few minutes.', 'panel' => $panel() ] );
	}

	/**
	 * POST /ext/next {url, product}: the site's next product, after this one, whose page has work in it (WORK_CELLS:
	 * something to write, or something the page shows that nothing reads). Its page opens in the same tab.
	 */
	public static function next( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$host = VendorMaps::host( $url );
		$m    = SiteGaps::matrix( $host, false );
		if ( ! $m ) {
			return new \WP_REST_Response( [ 'message' => 'This site has no products with a verified page on it yet.' ], 404 );
		}
		$after = (int) $r->get_param( 'product' ) ?: max( [ 0, ...array_merge( [], ...array_values( LiveMapper::same_page( $url ) ) ) ] );
		$work  = [];
		foreach ( $m['rows'] as $row ) {
			if ( UrlFinder::same_url( (string) $row['url'], $url ) || ( $row['status'] ?? '' ) !== 'ok' ) {
				continue;
			}
			$what = [];
			foreach ( self::WORK_FIELDS as $f ) {
				$c = (string) ( $row['cells'][ $f ][0] ?? '' );
				if ( isset( self::WORK_CELLS[ $c ] ) ) {
					$what[] = SiteGaps::LABELS[ $f ] . ' ' . self::WORK_CELLS[ $c ];
				}
			}
			if ( $what ) {
				$work[ (int) $row['id'] ] = [ 'url' => (string) $row['url'], 'product' => (int) $row['id'], 'title' => html_entity_decode( (string) $row['title'], ENT_QUOTES, 'UTF-8' ), 'what' => $what ];
			}
		}
		if ( ! $work ) {
			return new \WP_REST_Response( [ 'message' => 'No other product on this site has work on its page.' ], 404 );
		}
		ksort( $work );
		$later = array_filter( $work, static fn( $k ) => $k > $after, ARRAY_FILTER_USE_KEY );
		$pick  = $later ? reset( $later ) : reset( $work ); // past the last one: from the start
		return rest_ensure_response( $pick + [ 'left' => count( $work ), 'message' => sprintf( 'Next: %s (%s). %d product(s) on this site have work on their page.', $pick['title'], implode( ', ', $pick['what'] ), count( $work ) ) ] );
	}

	/**
	 * POST /ext/send {url, op, …}: Send this site from this tab (send.js), on
	 * Capture's routes. op site: what the site still needs from the browser (Capture::site()); list {urls}: its
	 * sitemap's pages; page {page, html | gone}: one page as the browser fetched it (Capture::store()); queue {ids,
	 * mode}: its products read again (scrape) or by the server (read). Only this tab's own site.
	 */
	public static function send( \WP_REST_Request $r ) {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$host = BrandSites::host( $url );
		$op   = sanitize_key( (string) $r->get_param( 'op' ) );
		if ( $r->get_param( 'dry' ) && $op !== 'site' ) {
			$html = (string) $r->get_param( 'html' );
			$page = esc_url_raw( (string) $r->get_param( 'page' ), [ 'http', 'https' ] );
			if ( $op === 'page' && ( BrandSites::host( $page ) !== $host || ( ! (int) $r->get_param( 'gone' ) && self::bad_html( $html ) !== '' ) ) ) {
				return new \WP_REST_Response( [ 'message' => BrandSites::host( $page ) !== $host ? 'Only this site’s own pages are sent.' : self::bad_html( $html ) ], 400 );
			}
			return rest_ensure_response( [ 'dry' => true, 'op' => $op, 'kept' => count( (array) $r->get_param( 'urls' ) ), 'n' => count( (array) $r->get_param( 'ids' ) ) ] );
		}
		if ( $op === 'site' ) {
			$q = new \WP_REST_Request( 'GET', '/' . Rest::NS . '/capture/site' );
			$q->set_param( 'host', $host );
			return Capture::site( $q );
		}
		if ( $op === 'list' ) {
			$q = new \WP_REST_Request( 'POST', '/' . Rest::NS . '/capture/list' );
			$q->set_param( 'host', $host );
			$q->set_param( 'urls', (array) $r->get_param( 'urls' ) );
			return Capture::list_post( $q );
		}
		if ( $op === 'page' ) {
			$page = esc_url_raw( (string) $r->get_param( 'page' ), [ 'http', 'https' ] );
			if ( BrandSites::host( $page ) !== $host ) {
				return new \WP_REST_Response( [ 'message' => 'Only this site’s own pages are sent.' ], 400 );
			}
			$q = new \WP_REST_Request( 'POST', '/' . Rest::NS . '/capture' );
			$q->set_param( 'url', $page );
			$q->set_param( 'html', (string) $r->get_param( 'html' ) );
			$q->set_param( 'gone', (int) $r->get_param( 'gone' ) );
			return Capture::store( $q );
		}
		if ( $op === 'queue' ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ), static fn( $id ) => BrandSites::host( (string) get_post_meta( $id, 'vendor_product_url', true ) ) === $host ) );
			if ( ! $ids ) {
				return new \WP_REST_Response( [ 'message' => 'Nothing to queue.' ], 400 );
			}
			$q = new \WP_REST_Request( 'POST', '/' . Rest::NS . '/pages/queue' );
			$q->set_param( 'ids', $ids );
			$q->set_param( 'mode', (string) $r->get_param( 'mode' ) === 'read' ? 'read' : 'scrape' );
			return PageReview::queue( $q );
		}
		return new \WP_REST_Response( [ 'message' => 'Unknown send request.' ], 400 );
	}

	/** Put back the product's page from before the panel linked it (link_record()), and read it again. */
	private static function undo_link( int $id ): bool {
		global $wpdb;
		$rec = self::link_record( $id );
		if ( ! $rec ) {
			return false;
		}
		if ( $rec['was'] === '' ) {
			Write::field( $id, 'vendor_product_url', null, 'product-vendor-links', 'force' );
			Write::field( $id, 'vendor_product_url_verified', null, 'product-vendor-links', 'force' );
		} else {
			Write::field( $id, 'vendor_product_url', (string) $rec['was'], 'product-vendor-links', 'force' );
			Write::field( $id, 'vendor_product_url_verified', (string) $rec['was_verified'] === '' ? null : (string) $rec['was_verified'], 'product-vendor-links', 'force' );
			Scraper::enqueue( [ $id ], 'scrape', true, 'manual' );
			Scraper::kick();
		}
		$wpdb->update( UrlFinder::table(), [ 'status' => 'rejected', 'note' => 'linked from the Chrome extension, then undone' ], [ 'product_id' => $id, 'url' => mb_substr( (string) $rec['url'], 0, 500 ) ] );
		delete_post_meta( $id, self::LINK_META );
		return true;
	}

	/** Brand term ids whose maker sites include this host (brands_for()'s rule). @return int[] */
	private static function brand_terms( string $host ): array {
		$roles = BrandSites::roles();
		$ids   = [];
		foreach ( get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false ] ) as $t ) {
			$role = $roles[ $t->term_id ][ $host ] ?? '';
			if ( ( $role !== '' && $role !== 'notbrand' ) || ( $role === '' && BrandSites::website_host( (int) $t->term_id ) === $host ) ) {
				$ids[] = (int) $t->term_id;
			}
		}
		return $ids;
	}

	/** The panel's last link of this product, while its Undo is offered and the product still has that page. @return array{url:string,was:string,was_verified:string,at:string}|null */
	private static function link_record( int $id ): ?array {
		$rec = get_post_meta( $id, self::LINK_META, true );
		if ( ! is_array( $rec ) || empty( $rec['at'] ) || strtotime( (string) $rec['at'] . ' UTC' ) < time() - self::UNDO_DAYS * DAY_IN_SECONDS ) {
			return null;
		}
		return (string) get_post_meta( $id, 'vendor_product_url', true ) === (string) $rec['url'] ? $rec : null;
	}

	/**
	 * Which of these page pictures product $id has already, by PictureMatch::merge()'s own rules (fingerprints, the
	 * person's vetoes; never Claude): have, maybe (a doubtful match), new, sharper (a better copy of one it has), vetoed.
	 * Reads only; fingerprints are downloaded together once and cached. @return array{kinds:object,counts:array}
	 */
	private static function pictures_check( int $id, string $host, array $urls ): array {
		$urls  = array_slice( array_values( array_unique( array_filter( array_map( 'strval', $urls ), static fn( $u ) => preg_match( '#^https?://#i', $u ) && ! self::own_address( $u ) ) ) ), 0, self::CHECK_MAX );
		$kinds = [];
		if ( $urls ) {
			$m = PictureMatch::merge( Pictures::current( $id ), $urls, false, $id, $host );
			foreach ( (array) $m['page'] as $p ) {
				$kinds[ (string) $p['url'] ] = ! empty( $p['doubt'] ) ? 'maybe' : (string) $p['kind'];
			}
			// A choice bundle's cards are other products, link or no link (ujam.com's tiles have none): labelled so, and left out of Pick all.
			if ( in_array( ChoiceBundles::state( $id ), [ 'yes', 'proposed' ], true ) ) {
				$page  = (string) get_post_meta( $id, 'vendor_product_url', true );
				$row   = $page !== '' ? PageStore::row( $page ) : null;
				$cards = ChoiceBundles::card_keys( $id, $page, $row && $row['status'] === 'ok' ? PageStore::read( (string) $row['html_file'] ) : '' );
				foreach ( $urls as $u ) {
					if ( isset( $cards[ ChoiceBundles::pic_key( $u ) ] ) && ( $kinds[ $u ] ?? 'new' ) !== 'have' ) {
						$kinds[ $u ] = 'other';
					}
				}
			}
		}
		return [ 'kinds' => (object) $kinds, 'counts' => [ 'now' => count( Pictures::current( $id ) ) ] ];
	}

	/** The picker's view of the page's layout (2+ pages): to save a gallery as the layout's Pictures part. */
	private static function picture_layout( string $host, string $url ): ?array {
		$lay = MapTypes::layout_of( $host, $url );
		if ( ! $lay || $lay['pages'] < 2 ) {
			return null;
		}
		$t = MapTypes::for_layout( $host, $lay['i'] );
		$f = $t && ! is_wp_error( $t ) ? (array) ( $t['fields']['pictures'] ?? [] ) : [];
		return [ 'i' => $lay['i'], 'letter' => self::letter( $lay['i'] ), 'pages' => $lay['pages'], 'sel' => (string) ( $f['sel'] ?? '' ) ];
	}

	/**
	 * The request's page and product: the product must use this page as its verified vendor URL.
	 * @return array{0:string,1:int,2:string}|\WP_Error [ url, product id, host ]
	 */
	private static function product_page( \WP_REST_Request $r ) {
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$id  = (int) $r->get_param( 'product' );
		$ids = array_merge( [], ...array_values( LiveMapper::same_page( $url ) ) );
		if ( ! $id || ! in_array( $id, $ids, true ) || ! in_array( strtoupper( (string) get_post_meta( $id, 'vendor_product_url_verified', true ) ), [ 'TRUE', '1' ], true ) ) {
			return new \WP_Error( 'mmpt_ext_product', 'That product doesn’t use this page as its verified vendor URL.' );
		}
		return [ $url, $id, VendorMaps::host( $url ) ];
	}

	/**
	 * POST /ext/layout {url, op, part, offset, product, dry}: the page's layout's own part $part. op plan: one slice of
	 * PartFill::layout_plan() from offset (0 starts again), kept under layout_key(); `next` until done, then the panel.
	 * op write: to the products the finished plan writes to (PartFill::layout_write(): the part in use on the layout's
	 * pages, the products read again, a Fill with Undo). op undo: that write's Undo (FillRun). op confirm: the layout
	 * confirmed (MapTypes::set_status(), its guard).
	 */
	public static function layout( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$host = VendorMaps::host( $url );
		$lay  = MapTypes::layout_of( $host, $url );
		$t    = $lay ? MapTypes::for_layout( $host, $lay['i'] ) : null;
		if ( ! $lay || ! $t || is_wp_error( $t ) ) {
			return new \WP_REST_Response( [ 'message' => is_wp_error( $t ) ? $t->get_error_message() : 'This page’s layout has no picks of its own.' ], 409 );
		}
		$name  = 'Layout ' . self::letter( $lay['i'] );
		$op    = sanitize_key( (string) $r->get_param( 'op' ) );
		$part  = sanitize_key( (string) $r->get_param( 'part' ) );
		$label = strtolower( VendorMaps::FIELDS[ $part ][0] ?? $part );
		$panel = static fn() => self::panel( $url, (int) $r->get_param( 'product' ), self::client( $r ) );
		$fail  = static fn( \WP_Error $e ) => new \WP_REST_Response( [ 'message' => $e->get_error_message(), 'panel' => $panel() ], (int) ( $e->get_error_data()['status'] ?? 409 ) );
		if ( $op === 'plan' ) {
			$offset = absint( $r->get_param( 'offset' ) );
			$key    = self::layout_key( $host, $t, $part );
			$kept   = get_transient( $key );
			$kept   = is_array( $kept ) ? $kept : null;
			$live   = $t['status'] === 'confirmed' || ! empty( $t['fields'][ $part ]['live'] );
			$finish = static function ( array $rows, int $total, int $at ) use ( $label, $name, $part, $panel, $r, $live ): \WP_REST_Response {
				$out = [ 'next' => null, 'done' => count( $rows ), 'total' => $total, 'writes' => count( array_filter( $rows, static fn( $x ) => self::is_write( (string) $x['action'] ) ) ), 'review' => self::review_of( $part, $rows, $at, $live ), 'message' => sprintf( '%s on %s’s %d products: %s', ucfirst( $label ), $name, $total, self::layout_summary( $rows ) ) ];
				return rest_ensure_response( self::client( $r )['protocol'] >= 9 ? $out : $out + [ 'panel' => $panel() ] );
			};
			if ( ! $offset && ! $r->get_param( 'fresh' ) && $kept && $kept['next'] === null ) { // looked at already: a cold look takes minutes
				return $finish( (array) $kept['rows'], (int) $kept['total'], (int) ( $kept['at'] ?? 0 ) );
			}
			$kept = $offset ? $kept : null;
			if ( $offset && ! $kept ) {
				return new \WP_REST_Response( [ 'message' => 'The pick or the stored pages changed while it looked: look again.', 'panel' => $panel() ], 409 );
			}
			$res = PartFill::layout_plan( $host, $lay['i'], $part, $offset, $kept ? (array) $kept['ids'] : null );
			if ( is_wp_error( $res ) ) {
				return $fail( $res );
			}
			$rows = array_merge( $kept ? (array) $kept['rows'] : [], array_map( static fn( $x ) => [ 'id' => (int) $x['id'], 'title' => (string) $x['title'], 'url' => (string) $x['url'], 'action' => (string) $x['action'] ], $res['rows'] ) );
			set_transient( $key, [ 'rows' => $rows, 'next' => $res['next'], 'total' => $res['total'], 'ids' => $res['ids'], 'at' => time() ], self::LAYOUT_S );
			return $res['next'] === null ? $finish( $rows, (int) $res['total'], time() ) : rest_ensure_response( [ 'next' => $res['next'], 'done' => count( $rows ), 'total' => $res['total'] ] );
		}
		if ( $op === 'write' ) {
			$plan = get_transient( self::layout_key( $host, $t, $part ) );
			if ( ! is_array( $plan ) || $plan['next'] !== null ) {
				return new \WP_REST_Response( [ 'message' => 'Look at what it writes first: the pick or the stored pages changed since.', 'panel' => $panel() ], 409 );
			}
			$ids = array_column( array_filter( $plan['rows'], static fn( $x ) => self::is_write( (string) $x['action'] ) ), 'id' );
			$res = PartFill::layout_write( $host, $lay['i'], $part, $ids, (bool) $r->get_param( 'dry' ) );
			if ( is_wp_error( $res ) ) {
				return $fail( $res );
			}
			if ( ! empty( $res['dry'] ) ) {
				return rest_ensure_response( [ 'dry' => true, 'message' => sprintf( 'Checked, nothing written: %s would go to %d products%s.', $label, $res['products'], $res['live'] ? ', put in use on ' . $name . '’s pages' : '' ), 'panel' => $panel() ] );
			}
			// One commit of several parts is one batch: its Undo puts back every part's write.
			$rk    = self::layout_run_key( $host, (string) $t['type_key'] );
			$batch = substr( sanitize_key( (string) $r->get_param( 'batch' ) ), 0, 24 );
			$was   = get_transient( $rk );
			$runs  = is_array( $was ) && $batch !== '' && ( $was['batch'] ?? '' ) === $batch ? (array) $was['runs'] : [];
			$runs[] = [ 'run' => $res['run'], 'part' => $part, 'products' => $res['products'] ];
			set_transient( $rk, [ 'batch' => $batch, 'runs' => $runs ], DAY_IN_SECONDS );
			delete_transient( self::layout_key( $host, $t, $part ) ); // what it writes now is what it wrote
			$how = $part === 'pictures' ? 'the picture runner adds their pictures' : 'they are read again with it';
			return rest_ensure_response( [ 'message' => sprintf( 'Writing %s to %d of %s’s products: %s%s. Undo puts back what it changed.', $label, $res['products'], $name, $how, $res['live'] ? ', and the pick is now in use on its pages' : '' ), 'panel' => $panel() ] );
		}
		if ( $op === 'undo' ) {
			$key  = self::layout_run_key( $host, (string) $t['type_key'] );
			$last = get_transient( $key );
			if ( ! is_array( $last ) ) {
				return new \WP_REST_Response( [ 'message' => 'There is no layout write to undo.', 'panel' => $panel() ], 409 );
			}
			$undone = 0;
			$kept   = 0;
			foreach ( self::layout_runs( $last ) as $run ) {
				$u       = FillRun::undo( $host, (string) $run['run'] );
				$undone += (int) $u['undone'];
				$kept   += (int) $u['kept'];
			}
			delete_transient( $key );
			return rest_ensure_response( [ 'message' => sprintf( 'Put back %d write(s).', $undone ) . ( $kept ? sprintf( ' %d kept what changed since the write.', $kept ) : '' ) . ' The picks stay in use on the layout’s pages.', 'panel' => $panel() ] );
		}
		if ( $op === 'confirm' ) {
			$res = MapTypes::set_status( $host, (string) $t['type_key'], true );
			if ( is_wp_error( $res ) ) {
				return $fail( $res );
			}
			return rest_ensure_response( [ 'message' => sprintf( '%s is confirmed: the reader reads its pages with all its picks.', $name ), 'panel' => $panel() ] );
		}
		return new \WP_REST_Response( [ 'message' => 'Unknown layout request.' ], 400 );
	}

	/**
	 * POST /ext/sitepart {url, op, part, offset, fresh, batch, dry}: a part of the site's map committed to all the site's
	 * products, for sites whose pages don't group into layouts (moforte.com: 2 pages). As /ext/layout: plan in slices (kept
	 * per pick and stored pages), write to the products the plan writes to (PartFill::write(): one batch, one Undo), undo.
	 */
	/** A person's answer on a choice bundle (ChoiceBundles): it is one, it isn't, or its list starts again. */
	private static function choice_op( \WP_REST_Request $r, string $url, string $op ): \WP_REST_Response {
		$id = ChoiceBundles::product_for( $url, (int) $r->get_param( 'product' ) );
		if ( ! $id ) {
			return new \WP_REST_Response( [ 'message' => 'No product that may be a choice bundle uses this page.' ], 404 );
		}
		if ( $r->get_param( 'dry' ) ) { // the browser check's copy: says what it would do
			$would = [ 'choice_clear' => 'empty its list of choices', 'choice_yes' => 'mark it as a choice bundle', 'choice_no' => 'mark it as not a choice bundle' ][ $op ];
			return rest_ensure_response( [ 'dry' => true, 'message' => 'Checked, nothing saved: this would ' . $would . '.', 'panel' => self::panel( $url, $id, self::client( $r ) ) ] );
		}
		if ( $op === 'choice_clear' ) {
			ChoiceBundles::clear( $id );
			$said = 'The list of choices is empty. Read the choices on each list page to fill it again.';
		} else {
			ChoiceBundles::set( $id, $op === 'choice_yes' );
			$said = $op === 'choice_yes' ? 'Marked as a choice bundle: no members are proposed for it. Read the choices on this page next.' : 'Marked as not a choice bundle.';
		}
		return rest_ensure_response( [ 'message' => $said, 'panel' => self::panel( $url, $id, self::client( $r ) ) ] );
	}

	public static function sitepart( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$host = VendorMaps::host( $url );
		$map  = VendorMaps::get( $host );
		$op   = sanitize_key( (string) $r->get_param( 'op' ) );
		$part = sanitize_key( (string) $r->get_param( 'part' ) );
		$fail = static fn( string $why, int $code = 409 ) => new \WP_REST_Response( [ 'message' => $why ], $code );
		if ( in_array( $op, [ 'choice_yes', 'choice_no', 'choice_clear' ], true ) ) {
			return self::choice_op( $r, $url, $op );
		}
		if ( ! $map ) {
			return $fail( 'This site has no map yet.', 404 );
		}
		$rk = self::layout_run_key( $host, 'site' );
		if ( $op === 'undo' ) {
			$last = get_transient( $rk );
			if ( ! is_array( $last ) ) {
				return $fail( 'There is no site commit to undo.' );
			}
			$undone = 0;
			$kept   = 0;
			foreach ( self::layout_runs( $last ) as $run ) {
				$u       = FillRun::undo( $host, (string) $run['run'] );
				$undone += (int) $u['undone'];
				$kept   += (int) $u['kept'];
			}
			delete_transient( $rk );
			return rest_ensure_response( [ 'message' => sprintf( 'Put back %d write(s).', $undone ) . ( $kept ? sprintf( ' %d kept what changed since the write.', $kept ) : '' ), 'panel' => self::panel( $url, (int) $r->get_param( 'product' ), self::client( $r ) ) ] );
		}
		$f     = (array) ( $map['fields'][ $part ] ?? [] );
		$label = strtolower( VendorMaps::FIELDS[ $part ][0] ?? $part );
		$key   = 'mmpt_ext_sp_' . md5( implode( '|', [ $host, $part, $f['sel'] ?? '', $f['mode'] ?? '', $f['src'] ?? '', VendorMaps::pages_sig( $host ) ] ) );
		if ( $op === 'plan' ) {
			$offset = absint( $r->get_param( 'offset' ) );
			$kept   = get_transient( $key );
			$kept   = is_array( $kept ) ? $kept : null;
			$live   = $map['status'] === 'confirmed' || ! empty( $f['live'] );
			$finish = static fn( array $rows, int $total, int $at ) => rest_ensure_response( [ 'next' => null, 'done' => count( $rows ), 'total' => $total, 'review' => self::review_of( $part, $rows, $at, $live ) ] );
			if ( ! $offset && ! $r->get_param( 'fresh' ) && $kept && $kept['next'] === null ) {
				return $finish( (array) $kept['rows'], (int) $kept['total'], (int) ( $kept['at'] ?? 0 ) );
			}
			$kept = $offset ? $kept : null;
			if ( $offset && ! $kept ) {
				return $fail( 'The pick or the stored pages changed while it looked: look again.' );
			}
			$res = PartFill::plan( $host, $part, $offset );
			if ( is_wp_error( $res ) ) {
				return $fail( $res->get_error_message() );
			}
			$rows = array_merge( $kept ? (array) $kept['rows'] : [], array_map( static fn( $x ) => [ 'id' => (int) $x['id'], 'title' => (string) $x['title'], 'url' => (string) $x['url'], 'action' => (string) $x['action'] ], $res['rows'] ) );
			set_transient( $key, [ 'rows' => $rows, 'next' => $res['next'], 'total' => $res['total'], 'at' => time() ], self::LAYOUT_S );
			return $res['next'] === null ? $finish( $rows, (int) $res['total'], time() ) : rest_ensure_response( [ 'next' => $res['next'], 'done' => count( $rows ), 'total' => $res['total'] ] );
		}
		if ( $op === 'write' ) {
			$plan = get_transient( $key );
			if ( ! is_array( $plan ) || $plan['next'] !== null ) {
				return $fail( 'Look at what it writes first: the pick or the stored pages changed since.' );
			}
			$ids = array_column( array_filter( $plan['rows'], static fn( $x ) => self::is_write( (string) $x['action'] ) ), 'id' );
			if ( $r->get_param( 'dry' ) ) {
				return rest_ensure_response( [ 'dry' => true, 'message' => sprintf( 'Checked, nothing written: %s would go to %d products of the site.', $label, count( $ids ) ) ] );
			}
			$res = PartFill::write( $host, $part, array_map( 'intval', $ids ) );
			if ( is_wp_error( $res ) ) {
				return $fail( $res->get_error_message() );
			}
			$batch = substr( sanitize_key( (string) $r->get_param( 'batch' ) ), 0, 24 );
			$was   = get_transient( $rk );
			$runs  = is_array( $was ) && $batch !== '' && ( $was['batch'] ?? '' ) === $batch ? (array) $was['runs'] : [];
			$runs[] = [ 'run' => $res['run'], 'part' => $part, 'products' => $res['products'] ];
			set_transient( $rk, [ 'batch' => $batch, 'runs' => $runs ], DAY_IN_SECONDS );
			delete_transient( $key );
			return rest_ensure_response( [ 'message' => sprintf( 'Writing %s to %d of the site’s products. Undo puts back what it changed.', $label, $res['products'] ), 'panel' => self::panel( $url, (int) $r->get_param( 'product' ), self::client( $r ) ) ] );
		}
		return $fail( 'Unknown request.', 400 );
	}

	/**
	 * POST /ext/mapping {url, op, product, was, target, html, panel, dry}: whether products are linked to the right vendor
	 * page. set: `target` becomes its page, from a likely page, a pasted address or the page in hand (`html`); nothing is
	 * written to the product (owner 2026-11-04: a commit goes through the review). undo: puts the last set back. list: the
	 * site's products under MATCH_LIST, worst first, with what the rating saw (the rest are queued for rating). right: a
	 * person says it is its page, and it leaves the list until the address changes. unsure: takes that back. none: "the
	 * maker doesn't publish it" (UrlQueue::mark_none(); owner 2026-10-31: not a cleared URL). restore: puts the address
	 * `was` back.
	 */
	public static function mapping( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$own = self::store_product( (string) $r->get_param( 'url' ) );
		$url = $own ? (string) $r->get_param( 'url' ) : self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$op = sanitize_key( (string) $r->get_param( 'op' ) );
		if ( $own && ( $own !== absint( $r->get_param( 'product' ) ) || $op === 'list' ) ) { // this page's product is the only one here
			return new \WP_REST_Response( [ 'message' => 'This page is about another product.' ], 400 );
		}
		if ( $op === 'list' ) {
			return rest_ensure_response( self::mapping_list( VendorMaps::host( $url ) ) );
		}
		$id   = absint( $r->get_param( 'product' ) );
		$addr = (string) get_post_meta( $id, 'vendor_product_url', true );
		$out  = static fn( string $said, array $more = [] ) => rest_ensure_response( [ 'message' => $said ] + $more + ( $r->get_param( 'panel' ) ? [ 'panel' => self::panel( $url, $id, self::client( $r ) ) ] : [] ) );
		if ( get_post_type( $id ) !== 'product' ) {
			return new \WP_REST_Response( [ 'message' => 'That isn’t a product.' ], 404 );
		}
		if ( $own && in_array( $op, [ 'brands', 'brand', 'site', 'nosite' ], true ) ) {
			return self::store_brand( $r, $op, $id, $out );
		}
		if ( $op === 'set' ) {
			return self::mapping_set( $r, $id, $addr, $out );
		}
		if ( $op === 'accept' ) { // the finder's proposal: a set like any other, with the same checks
			$row = UrlFinder::row( $id );
			if ( ! $row || $row['status'] !== 'candidate' ) {
				return new \WP_REST_Response( [ 'message' => 'That proposal has been decided already.' ], 409 );
			}
			$r->set_param( 'target', (string) $row['url'] );
			return self::mapping_set( $r, $id, $addr, $out );
		}
		if ( in_array( $op, [ 'reject', 'reset' ], true ) ) {
			if ( $r->get_param( 'dry' ) ) {
				return $out( 'Checked, nothing changed: ' . $op . '.', [ 'dry' => true ] );
			}
			global $wpdb;
			$wpdb->update( UrlFinder::table(), [ 'status' => $op === 'reject' ? 'rejected' : 'candidate' ], [ 'product_id' => $id ] );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.' . $op, [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'via' => 'extension' ] ] );
			}
			return $out( $op === 'reject' ? 'Proposal rejected.' : 'The proposal is back.' );
		}
		if ( $op === 'undo' ) {
			if ( $r->get_param( 'dry' ) ) {
				return $out( 'Checked, nothing changed: undo.', [ 'dry' => true ] );
			}
			return self::undo_link( $id ) ? $out( 'Its earlier page is back.' ) : new \WP_REST_Response( [ 'message' => 'There is no link to undo, or the product’s page changed since.' ], 409 );
		}
		if ( $r->get_param( 'dry' ) && in_array( $op, [ 'right', 'unsure', 'none', 'restore' ], true ) ) {
			return $out( 'Checked, nothing changed: ' . $op . '.', [ 'dry' => true, 'was' => $addr ] );
		}
		if ( $op === 'right' ) {
			update_post_meta( $id, self::PAGE_OK, $addr );
			$said = 'Marked as its page. It leaves the check until its address changes.';
		} elseif ( $op === 'unsure' ) {
			delete_post_meta( $id, self::PAGE_OK );
			$said = 'It is back on the check.';
		} elseif ( $op === 'none' ) {
			$done = UrlQueue::mark_none( $id );
			delete_post_meta( $id, self::PAGE_OK );
			return $out( 'Its vendor URL is removed: the maker doesn’t publish this product. What it already has stays.', [ 'was' => (string) $done['url'] ] );
		} elseif ( $op === 'restore' ) {
			$why = UrlQueue::set_one( $id, esc_url_raw( (string) $r->get_param( 'was' ) ) );
			if ( $why !== '' ) {
				return new \WP_REST_Response( [ 'message' => $why ], 409 );
			}
			$said = 'Its vendor URL is back.';
		} else {
			return new \WP_REST_Response( [ 'message' => 'Unknown request.' ], 400 );
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.ext_check', [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'op' => $op ] ] );
		}
		return $out( $said );
	}

	/**
	 * Make `target` product $id's page, checked first: a site of its brand, a real page, not a shop's category. Stored from
	 * the browser when it came with `html`, else read now. The product itself isn't touched: its rows then say what the
	 * page gives, and the review commits it.
	 */
	private static function mapping_set( \WP_REST_Request $r, int $id, string $was, callable $out ): \WP_REST_Response {
		$target = UrlFinder::clean_url( esc_url_raw( trim( (string) $r->get_param( 'target' ) ), [ 'http', 'https' ] ) );
		$refuse = static fn( string $why ) => new \WP_REST_Response( [ 'message' => $why ], 400 );
		if ( $target === '' ) {
			return $refuse( 'That is not a web address (it must start with https://).' );
		}
		$terms = self::brand_terms( VendorMaps::host( $target ) );
		if ( ! $terms || ! array_intersect( $terms, array_map( 'intval', (array) wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'ids' ] ) ) ) ) {
			return $refuse( 'That address isn’t on a maker site of this product’s brand. Add the site in the brand’s row in the Product Workbench’s Brands & sites first.' );
		}
		if ( UrlFinder::same_url( $was, $target ) ) {
			return $refuse( 'That is already its page.' );
		}
		$html = (string) $r->get_param( 'html' );
		if ( $html !== '' ) {
			$bad = self::bad_html( $html );
			if ( $bad !== '' ) {
				return $refuse( $bad );
			}
			$page = [ 'status' => 'ok', 'html' => $html ];
		} else {
			$page = PageStore::get( $target );
		}
		if ( in_array( (string) $page['status'], [ 'http_404', 'http_410' ], true ) ) {
			return $refuse( 'That page doesn’t exist any more.' );
		}
		if ( (string) $page['status'] === 'ok' && VendorPage::is_listing_page( (string) $page['html'] ) ) {
			return $refuse( 'That is a category page, not a product’s own page. Open the product’s own page.' );
		}
		$short  = self::short_url( $target );
		$next   = static fn( string $page ) => [ 'product' => $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'url' => SharedPages::anchored( $id, $page ), 'here' => UrlFinder::same_url( $page, (string) $r->get_param( 'url' ) ) ];
		if ( $r->get_param( 'dry' ) ) {
			return $out( 'Checked, nothing changed: ' . $short . ' would become its page.', [ 'dry' => true, 'linked' => $next( $target ) ] );
		}
		$ver = (string) get_post_meta( $id, 'vendor_product_url_verified', true );
		$why = UrlQueue::set_one( $id, $target );
		if ( $why !== '' ) {
			return $refuse( $why );
		}
		$now = (string) get_post_meta( $id, 'vendor_product_url', true );
		update_post_meta( $id, self::LINK_META, [ 'url' => $now, 'was' => $was, 'was_verified' => $ver, 'at' => current_time( 'mysql', true ) ] );
		update_post_meta( $id, self::PAGE_OK, $now ); // a person chose it
		if ( $html !== '' ) {
			self::store( $target, $html );
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_url.set', [ 'object_type' => 'product', 'object_id' => $id, 'details' => [ 'host' => VendorMaps::host( $target ), 'was' => $was, 'via' => 'extension check' ] ] );
		}
		$said = (string) $page['status'] === 'ok' ? '' : ' It couldn’t be read from here: open it and use Store this page.';
		$here = $next( $now );
		return $out( 'Linked to ' . $short . '. Nothing is written to the product until you commit it ' . ( $here['here'] ? 'here' : 'from that page' ) . '.' . $said, [ 'short' => $short, 'linked' => $here ] );
	}

	/** The finder's proposals waiting on this site, best first, each beside what the product has now. */
	private static function mapping_proposals( string $host ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT f.product_id id, f.url FROM ' . UrlFinder::table() . " f JOIN {$wpdb->posts} p ON p.ID = f.product_id AND p.post_status = 'publish' WHERE f.status = 'candidate' AND f.host IN (%s, %s) LIMIT %d", $host, 'www.' . $host, self::MATCH_ROWS ), ARRAY_A );
		$ids  = array_map( 'intval', array_column( $rows, 'id' ) );
		$skip = array_flip( Exclusions::ids() );
		$n    = UrlReview::rates( $ids, self::MATCH_WAIT_S )['n'];
		$out  = [];
		foreach ( $rows as $x ) {
			$id = (int) $x['id'];
			if ( isset( $skip[ $id ] ) ) {
				continue;
			}
			$now   = (string) get_post_meta( $id, 'vendor_product_url', true );
			$rate  = $n[ $id ] ?? null;
			$out[] = [ 'id' => $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'url' => (string) $x['url'], 'short' => self::short_url( (string) $x['url'] ), 'page' => self::page_title( (string) $x['url'] ), 'now' => $now !== '' ? self::short_url( $now ) : '', 'n' => $rate, 'word' => $rate === null ? 'Not checked yet' : ( $rate >= self::MATCH_FINE ? 'Looks right' : ( $rate < self::MATCH_DOUBT ? 'Doubtful' : 'Worth checking' ) ), 'kind' => $rate === null ? '' : ( $rate >= self::MATCH_FINE ? 'ok' : ( $rate < self::MATCH_DOUBT ? 'missing' : 'review' ) ) ];
		}
		usort( $out, static fn( $a, $b ) => (int) $b['n'] <=> (int) $a['n'] );
		return $out;
	}

	/** The site's products whose page may be the wrong one, worst first. */
	private static function mapping_list( string $host ): array {
		$all   = VendorMaps::site_products( $host );
		$rated = UrlReview::rates( array_map( 'intval', array_column( $all, 'id' ) ), self::MATCH_WAIT_S );
		if ( $rated['unrated'] ) {
			UrlReview::rate_later( $rated['unrated'] );
		}
		$rows = [];
		$ok   = 0;
		foreach ( $all as $p ) {
			$id = (int) $p['id'];
			if ( (string) get_post_meta( $id, self::PAGE_OK, true ) === (string) $p['url'] ) {
				++$ok;
			} elseif ( isset( $rated['n'][ $id ] ) && $rated['n'][ $id ] < self::MATCH_LIST ) {
				$rows[] = [ $rated['n'][ $id ], $p ];
			}
		}
		usort( $rows, static fn( $a, $b ) => $a[0] <=> $b[0] );
		$out = [];
		foreach ( array_slice( $rows, 0, self::MATCH_ROWS ) as [ $n, $p ] ) {
			$id    = (int) $p['id'];
			$out[] = [ 'id' => $id, 'title' => html_entity_decode( (string) $p['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'url' => (string) $p['url'], 'page' => self::page_title( (string) $p['url'] ), 'view' => (string) get_permalink( $id ), 'likely' => self::likely_pages( $id, $host ) ] + self::match_of( $id, $n );
		}
		return [ 'host' => $host, 'rows' => $out, 'proposals' => self::mapping_proposals( $host ), 'total' => count( $all ), 'listed' => count( $rows ), 'confirmed' => $ok, 'waiting' => count( $rated['unrated'] ) ];
	}

	/**
	 * POST /ext/held {url, product}: the product in hand as this store shows it, for the card on the panel's Finding the
	 * page bar (owner 2026-11-06: a final confirmation before Link this URL). A card, not the page: the store can't be
	 * framed on a maker's site (X-Frame-Options). Reads only.
	 */
	public static function held( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$id = absint( $r->get_param( 'product' ) );
		$p  = get_post_type( $id ) === 'product' ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			return new \WP_REST_Response( [ 'message' => 'That isn’t a product.' ], 404 );
		}
		$page  = (string) get_post_meta( $id, 'vendor_product_url', true );
		$words = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $p->get_short_description() ?: $p->get_description() ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$price = (string) $p->get_price();
		return rest_ensure_response(
			[
				'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'image'  => (string) wp_get_attachment_image_url( $p->get_image_id(), 'medium' ),
				'facts'  => implode( ' · ', array_filter( [ (string) $p->get_sku(), $price !== '' ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $price ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '' ] ) ),
				'text'   => wp_trim_words( $words, self::HELD_WORDS, '…' ),
				'linked' => $page === '' ? 'No maker page linked yet.' : ( UrlFinder::same_url( $page, $url ) ? 'Already linked to this page.' : 'Linked now: ' . self::short_url( $page ) ),
				'view'   => (string) get_permalink( $id ),
			]
		);
	}

	/**
	 * POST /ext/disclaimer {url, product, text, dry}: a person types the product's note (owner 2026-11-04: "needs to be able
	 * to set the product Disclaimer"), where the page has none to pick or the product already has another. Locked against
	 * imports, as a fill is; Undo puts back what was there. Empty text clears it.
	 */
	public static function disclaimer( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$id = absint( $r->get_param( 'product' ) );
		if ( get_post_type( $id ) !== 'product' || ! class_exists( '\MMI_Product_Content_Registry' ) ) {
			return new \WP_REST_Response( [ 'message' => 'That isn’t a product.' ], 404 );
		}
		$text  = mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', sanitize_textarea_field( (string) $r->get_param( 'text' ) ) ) ), 0, self::DISCLAIMER_MAX );
		$was   = (string) \MMI_Product_Content_Registry::canonical_value( $id, 'disclaimer' );
		$panel = static fn() => self::panel( $url, $id, self::client( $r ) );
		if ( $text === $was ) {
			return rest_ensure_response( [ 'message' => 'The disclaimer already says that.', 'panel' => $panel() ] );
		}
		if ( $r->get_param( 'dry' ) ) {
			return rest_ensure_response( [ 'dry' => true, 'message' => 'Checked, nothing written: the disclaimer would be set.', 'panel' => $panel() ] );
		}
		\MMI_Product_Content_Registry::store( $id, 'disclaimer', $text, 'product-extension' );
		if ( class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
			\MMI_Pipeline_Field_Locks::lock( $id, \MMI_Product_Content_Registry::canonical_key( 'disclaimer' ) );
		}
		$kept = get_post_meta( $id, self::DISCLAIMER_META, true );
		update_post_meta( $id, self::DISCLAIMER_META, [ 'was' => is_array( $kept ) ? (string) $kept['was'] : $was, 'now' => $text, 'at' => time() ] );
		wc_delete_product_transients( $id );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'vendor_maps.ext_disclaimer', [ 'object_type' => 'product', 'object_id' => (string) $id, 'details' => [ 'chars' => mb_strlen( $text ) ] ] );
		}
		return rest_ensure_response( [ 'message' => $text === '' ? 'The disclaimer is cleared.' : 'The disclaimer is set.', 'panel' => $panel() ] );
	}

	/**
	 * POST /ext/example {url, product, parts[], scope, stays}: one product's parts as they are and as a commit would leave
	 * them, for the review (owner 2026-11-04: "allow the user to quickly, easily preview an example"). Read-only. scope
	 * layout: read with the layout's own picks, as PartFill's plan reads them; otherwise as the panel's rows do. stays: the
	 * plan's outcome for it writes nothing, so it stays as it is: a plan can leave out a product (its pick reads nothing
	 * there) that a general read of its page would write.
	 */
	public static function example( \WP_REST_Request $r ): \WP_REST_Response {
		$old = self::too_old( $r );
		if ( $old ) {
			return $old;
		}
		$url = self::page_url( (string) $r->get_param( 'url' ) );
		if ( is_wp_error( $url ) ) {
			return new \WP_REST_Response( [ 'message' => $url->get_error_message() ], 400 );
		}
		$host  = VendorMaps::host( $url );
		$id    = absint( $r->get_param( 'product' ) );
		$names = array_flip( self::ROW_PART );
		$parts = array_values( array_intersect( array_map( 'sanitize_key', (array) $r->get_param( 'parts' ) ), array_keys( $names ) ) );
		$plan  = $r->get_param( 'scope' ) !== 'product'
			? VendorMaps::with_editor_parts( $host, $parts, static fn() => ProductPreview::plan( $host, $id, true ) )
			: ProductPreview::plan( $host, $id, true );
		if ( ! $plan || ! $parts ) {
			return new \WP_REST_Response( [ 'message' => 'This product has no verified page on this site, so there is nothing to show.' ], 404 );
		}
		$writes = $r->get_param( 'stays' ) ? [] : ProductPreview::writes( $plan );
		return rest_ensure_response(
			[
				'product' => [ 'id' => $id, 'title' => (string) $plan['title'], 'page' => (string) $plan['url'], 'view' => (string) $plan['view'], 'page_title' => self::page_title( (string) $plan['url'] ), 'match' => self::match_of( $id ) ],
				'parts'   => array_map(
					static fn( $k ) => self::example_part( $plan, $k, $names[ $k ], (string) ( $writes[ $k ] ?? '' ) ) + ( isset( $writes[ $k ] ) ? [ 'preview' => ProductPreview::signed_url( $host, $id, isset( ProductPreview::ON_PAGE[ $k ] ) ? $k : '' ) ] : [] ), // a preview shows what a read gives: only for what a commit writes
					$parts
				),
			]
		);
	}

	/** One part of an example: its now and after. $will: write, review or '' (a commit leaves it as it is). */
	private static function example_part( array $plan, string $k, string $label, string $will ): array {
		$f    = (array) ( $plan['fields'][ $k ] ?? [] );
		$base = [ 'key' => $k, 'label' => $label, 'will' => $will ];
		if ( $k === 'description' ) {
			$now   = Descriptions::plain( (string) ( $f['current']['html'] ?? '' ) );
			$after = $will !== '' ? Descriptions::plain( (string) ( $plan['raw']['description'] ?? '' ) ) : $now;
			return $base + [ 'kind' => 'text', 'now' => self::marked( $now, $after ), 'after' => self::marked( $after, $now ), 'now_chars' => mb_strlen( $now ), 'after_chars' => mb_strlen( $after ) ];
		}
		if ( $k === 'pictures' ) {
			$gone  = [ 'vetoed' => 'Comes off', 'duplicate' => 'Duplicate, removed' ];
			$fresh = [ 'new' => 'New', 'sharper' => 'Sharper copy' ];
			$now   = array_map( static fn( $p ) => [ 'url' => (string) $p['url'], 'tag' => $will !== '' ? ( $gone[ $p['kind'] ?? '' ] ?? '' ) : '' ], (array) ( $f['current']['images'] ?? [] ) );
			$after = $will !== '' && ! empty( $f['merge']['final'] ) ? array_map( static fn( $p ) => [ 'url' => (string) $p['url'], 'tag' => $fresh[ $p['kind'] ] ?? '' ], (array) $f['merge']['final'] ) : $now;
			return $base + [ 'kind' => 'pictures', 'now' => array_slice( $now, 0, self::EXAMPLE_PICS ), 'after' => array_slice( $after, 0, self::EXAMPLE_PICS ), 'now_n' => count( $now ), 'after_n' => count( $after ) ];
		}
		if ( $k === 'requirements' ) {
			$names = [ 'mac' => 'Mac', 'windows' => 'Windows', 'linux' => 'Linux' ];
			$now   = [];
			$after = [];
			foreach ( $names as $os => $name ) {
				$has = self::flat( $f['current'][ $os ] ?? [] );
				$new = self::flat( $f['proposed'][ $os ] ?? [] );
				$now[]   = $has !== '' ? $name . ': ' . $has : '';
				$after[] = ( ( (array) ( $f['action'] ?? [] ) )[ $os ] ?? '' ) === 'fill' && $will !== '' ? $name . ': ' . $new : end( $now );
			}
			return $base + [ 'kind' => 'lines', 'now' => array_values( array_filter( $now ) ), 'after' => array_values( array_filter( $after ) ) ];
		}
		$now = array_map( [ __CLASS__, 'flat' ], (array) ( $f['current']['items'] ?? [] ) );
		return $base + [ 'kind' => 'lines', 'now' => $now, 'after' => $will !== '' ? array_map( [ __CLASS__, 'flat' ], (array) ( $f['proposed']['items'] ?? [] ) ) : $now ];
	}

	/** Text as sentences, each saying whether $other lacks it: [ [ sentence, changed ], … ]. */
	private static function marked( string $text, string $other ): array {
		$split = static fn( string $s ) => array_values( array_filter( (array) preg_split( '/(?<=[.!?])\s+/u', mb_substr( $s, 0, self::EXAMPLE_CHARS ) ), 'strlen' ) );
		$there = array_flip( $split( $other ) );
		return array_map( static fn( $t ) => [ $t, ! isset( $there[ $t ] ) ], $split( $text ) );
	}

	/** A stored value of any depth as one line of text. */
	private static function flat( $v ): string {
		$v   = (array) $v;
		$out = [];
		array_walk_recursive(
			$v,
			static function ( $x ) use ( &$out ) {
				if ( is_scalar( $x ) && trim( (string) $x ) !== '' ) {
					$out[] = trim( (string) $x );
				}
			}
		);
		return implode( ' · ', $out );
	}

	/** An address on this site. */
	private static function own_address( string $url ): bool {
		return BrandSites::host( $url ) === BrandSites::host( home_url() );
	}

	/** Add YouTube videos the product doesn't have (add-only), kept for Undo. @return int videos added */
	private static function add_videos( int $id, array $urls ): int {
		if ( ! class_exists( '\MMI_Product_Content_Registry' ) ) {
			return 0;
		}
		$have = (array) \MMI_Product_Content_Registry::canonical_value( $id, 'videos' );
		$ids  = array_filter( array_map( [ '\MMI_Product_Content_Registry', 'youtube_id' ], $have ) );
		$new  = [];
		foreach ( array_slice( $urls, 0, self::MAX_VIDEOS ) as $u ) {
			$vid = \MMI_Product_Content_Registry::youtube_id( (string) $u );
			if ( $vid !== '' && ! in_array( $vid, $ids, true ) ) {
				$ids[] = $vid;
				$new[] = 'https://www.youtube.com/watch?v=' . $vid;
			}
		}
		if ( ! $new ) {
			return 0;
		}
		\MMI_Product_Content_Registry::store( $id, 'videos', array_merge( $have, $new ), 'product-extension' );
		update_post_meta( $id, self::VIDEOS_META, [ 'added' => $new, 'at' => current_time( 'mysql', true ) ] );
		wc_delete_product_transients( $id );
		return count( $new );
	}

	/**
	 * The SoundCloud tracks and sets of $urls the product doesn't have, one per item (SoundCloud::key(): a page address
	 * and a player's count once), named by SoundCloud's oEmbed. @return array<string,array{label:string,value:string}> key → item
	 */
	private static function new_audio( int $id, array $urls ): array {
		if ( ! class_exists( '\MMI_Product_Content_Registry' ) ) {
			return [];
		}
		$have = [];
		foreach ( (array) \MMI_Product_Content_Registry::canonical_value( $id, 'audio' ) as $a ) {
			$k = SoundCloud::key( (string) ( is_array( $a ) ? ( $a['value'] ?? '' ) : $a ) );
			$have[ $k !== '' ? $k : md5( wp_json_encode( $a ) ) ] = true;
		}
		$new = [];
		foreach ( array_slice( $urls, 0, self::MAX_AUDIO ) as $u ) {
			$k = SoundCloud::key( (string) $u );
			if ( $k === '' || isset( $have[ $k ] ) || isset( $new[ $k ] ) ) {
				continue;
			}
			$o         = SoundCloud::oembed( (string) $u );
			$new[ $k ] = [ 'label' => $o && $o['title'] !== '' ? $o['title'] : 'SoundCloud', 'value' => SoundCloud::url( (string) $u ) ];
		}
		return $new;
	}

	/** Add the SoundCloud tracks and sets the product doesn't have (add-only; AUDIO_META for Undo). @return int added */
	private static function add_audio( int $id, array $urls ): int {
		$new = self::new_audio( $id, $urls );
		if ( ! $new ) {
			return 0;
		}
		\MMI_Product_Content_Registry::store( $id, 'audio', array_merge( (array) \MMI_Product_Content_Registry::canonical_value( $id, 'audio' ), array_values( $new ) ), 'product-extension' );
		update_post_meta( $id, self::AUDIO_META, [ 'added' => array_keys( $new ), 'at' => current_time( 'mysql', true ) ] );
		wc_delete_product_transients( $id );
		return count( $new );
	}

	/** Take off the audio demos the extension added last, those still there. @return int taken off */
	private static function undo_audio( int $id ): int {
		$rec = get_post_meta( $id, self::AUDIO_META, true );
		if ( ! is_array( $rec ) || empty( $rec['added'] ) || ! class_exists( '\MMI_Product_Content_Registry' ) ) {
			return 0;
		}
		$drop = array_flip( (array) $rec['added'] );
		$have = (array) \MMI_Product_Content_Registry::canonical_value( $id, 'audio' );
		$keep = array_values( array_filter( $have, static fn( $a ) => ! isset( $drop[ SoundCloud::key( (string) ( is_array( $a ) ? ( $a['value'] ?? '' ) : $a ) ) ] ) ) );
		\MMI_Product_Content_Registry::store( $id, 'audio', $keep, 'product-extension' );
		delete_post_meta( $id, self::AUDIO_META );
		wc_delete_product_transients( $id );
		return count( $have ) - count( $keep );
	}

	/** Take off the videos the extension added last, those still there. @return int taken off */
	private static function undo_videos( int $id ): int {
		$rec = get_post_meta( $id, self::VIDEOS_META, true );
		if ( ! is_array( $rec ) || empty( $rec['added'] ) || ! class_exists( '\MMI_Product_Content_Registry' ) ) {
			return 0;
		}
		$drop = array_map( [ '\MMI_Product_Content_Registry', 'youtube_id' ], (array) $rec['added'] );
		$have = (array) \MMI_Product_Content_Registry::canonical_value( $id, 'videos' );
		$keep = array_values( array_filter( $have, static fn( $u ) => ! in_array( \MMI_Product_Content_Registry::youtube_id( (string) $u ), $drop, true ) ) );
		\MMI_Product_Content_Registry::store( $id, 'videos', $keep, 'product-extension' );
		delete_post_meta( $id, self::VIDEOS_META );
		wc_delete_product_transients( $id );
		return count( $have ) - count( $keep );
	}

	/** A maker's page address: http(s), not this site, not an account/cart area, without its #fragment. @return string|\WP_Error */
	private static function page_url( string $raw ) {
		$url  = esc_url_raw( trim( $raw ), [ 'http', 'https' ] );
		$host = BrandSites::host( $url );
		if ( $host === '' ) {
			return new \WP_Error( 'mmpt_ext_url', 'Not a web page address.' );
		}
		if ( $host === BrandSites::host( home_url() ) ) {
			return new \WP_Error( 'mmpt_ext_own', 'This is the store’s own site. Open a product’s page here, or a maker’s page, to use the panel.' );
		}
		if ( preg_match( self::PRIVATE_PATH, (string) wp_parse_url( $url, PHP_URL_PATH ) . '/' ) ) {
			return new \WP_Error( 'mmpt_ext_private', 'This looks like an account, cart or sign-in page. The extension never stores those, so your own details stay off the store.' );
		}
		return (string) strtok( $url, '#' );
	}

	/** The store's name, as the panel's headings show it (its site title, else its host). */
	private static function store_name(): string {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		return $name !== '' ? $name : self::store_host();
	}

	/** The store's host, for "Open on …" and "Preview on …". */
	private static function store_host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * What to do about an old extension. A store copy updates through the Chrome Web Store by itself; a copy loaded by
	 * hand is updated from this site's Chrome extension page. A store's own plugin can add its way (filter
	 * mmi_pipeline_extension_update_help, e.g. an updater that keeps unpacked copies current).
	 */
	private static function update_help( string $lead ): string {
		$text = $lead . ' A copy from the Chrome Web Store updates by itself. A copy loaded by hand: on the Chrome extension page, download the new version, unzip it (its folder carries the version number), remove the old card in chrome://extensions, Load unpacked the new folder and connect it again.';
		return (string) apply_filters( 'mmi_pipeline_extension_update_help', $text, $lead );
	}

	/* ── The Chrome extension page ──────────────────────────────────────── */

	/** The page: no menu entry, linked from the Product Workbench. */
	public static function menu(): void {
		add_submenu_page( '', __( 'Chrome extension', 'mmi-data-pipeline' ), '', Rest::CAP, self::SLUG, [ __CLASS__, 'render' ] );
	}

	/** The module's own files (assets/). */
	private static function asset_ver( string $rel ): string {
		$f = dirname( __DIR__ ) . '/assets/' . $rel;
		return file_exists( $f ) ? (string) filemtime( $f ) : ( defined( 'MMI_PIPELINE_VERSION' ) ? MMI_PIPELINE_VERSION : '1' );
	}

	public static function assets( $hook ): void {
		if ( ! is_string( $hook ) || strpos( $hook, self::SLUG ) === false ) {
			return;
		}
		wp_enqueue_style( 'mmpt-extension', PageEvents::asset_url( 'css/extension.css' ), wp_style_is( 'mmi-suite-common', 'registered' ) ? [ 'mmi-suite-common' ] : [], self::asset_ver( 'css/extension.css' ) );
		wp_enqueue_script( 'mmpt-ext-connect', PageEvents::asset_url( 'js/ext-connect.js' ), [], self::asset_ver( 'js/ext-connect.js' ), true );
		wp_localize_script(
			'mmpt-ext-connect',
			'mmptExtConnect',
			[
				'rest'     => esc_url_raw( rest_url( Rest::NS . '/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'protocol' => self::PROTOCOL,
				'version'  => self::version(),
			]
		);
		do_action( 'mmi_pipeline_extension_page_assets' ); // a store's own parts of this page (e.g. an updater for unpacked copies)
	}

	/** The extension's Chrome Web Store listing, once it has one (filter mmi_pipeline_extension_store_url). */
	public static function store_url(): string {
		return esc_url_raw( (string) apply_filters( 'mmi_pipeline_extension_store_url', '' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Rest::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-data-pipeline' ) );
		}
		$zip   = wp_nonce_url( admin_url( 'admin-post.php?action=mmpt_ext_download' ), 'mmpt_ext_download' );
		$store = self::store_url();
		?>
		<div class="wrap mmi-page mmpt-ext-page">
			<div class="mmi-header">
				<h1><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e( 'Chrome extension', 'mmi-data-pipeline' ); ?></h1>
				<p class="mmi-header-description"><?php esc_html_e( 'MMI Product Data works on a maker’s own page in Chrome: it shows what the page gives each product that uses it, writes that to the product (with Undo in the Product Workbench’s Recent changes), and lets you pick a part or the product’s pictures and videos right on the page. It also links a page to a product, moves to the site’s next product with work on its page, and sends a page that refuses the server from your tab. It talks only to this site.', 'mmi-data-pipeline' ); ?></p>
			</div>
			<div class="wp-header-end"></div>

			<div class="mmi-process-section">
				<div class="mmi-section-header">
					<div>
						<h3 class="mmi-process-section-header"><span class="dashicons dashicons-download"></span> <?php esc_html_e( '1 · Install it in Chrome', 'mmi-data-pipeline' ); ?></h3>
						<p class="mmi-process-section-description"><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Version %s. It works in Chrome, Edge and Brave.', 'mmi-data-pipeline' ), self::version() ) ); ?></p>
					</div>
					<?php if ( $store !== '' ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $store ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> <?php esc_html_e( 'Get it from the Chrome Web Store', 'mmi-data-pipeline' ); ?></a>
					<?php endif; ?>
					<?php do_action( 'mmi_pipeline_extension_install_buttons' ); ?>
					<a class="button<?php echo $store === '' ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $zip ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download the extension', 'mmi-data-pipeline' ); ?></a>
				</div>
				<div class="mmi-section-content">
					<?php do_action( 'mmi_pipeline_extension_install' ); ?>
					<details class="mmpt-ext-manual"<?php echo $store === '' ? ' open' : ''; ?>>
						<summary><?php esc_html_e( 'Loading the download by hand', 'mmi-data-pipeline' ); ?></summary>
						<ol class="mmpt-ext-steps">
							<li><?php esc_html_e( 'Unzip the download. Keep the folder somewhere it can stay (Documents, for example).', 'mmi-data-pipeline' ); ?></li>
							<li><?php esc_html_e( 'In Chrome, open chrome://extensions and switch on Developer mode (top right).', 'mmi-data-pipeline' ); ?></li>
							<li><?php esc_html_e( 'Press Load unpacked and choose the folder. Pin MMI Product Data to the toolbar (the puzzle-piece menu).', 'mmi-data-pipeline' ); ?></li>
							<li><?php esc_html_e( 'Reload this page, then connect it below.', 'mmi-data-pipeline' ); ?></li>
						</ol>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: the unzipped folder's name */ __( 'To update: download again and unzip it: the folder is named with its version (%s), so it never becomes “… 2”. In chrome://extensions remove the old MMI Product Data card, press Load unpacked and choose the new folder, then connect it again below (one click; the old version’s connection closes by itself). The old folder can then be deleted.', 'mmi-data-pipeline' ), 'mmi-product-data-extension-' . self::version() ) ); ?></p>
					</details>
				</div>
			</div>

			<?php do_action( 'mmi_pipeline_extension_sections' ); ?>

			<div class="mmi-process-section">
				<div class="mmi-section-header">
					<div>
						<h3 class="mmi-process-section-header"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( '2 · Connect this browser', 'mmi-data-pipeline' ); ?></h3>
						<p class="mmi-process-section-description"><?php esc_html_e( 'Connecting gives the extension a key that works only for the extension’s routes on this site, under your login. Nothing is copied by hand.', 'mmi-data-pipeline' ); ?></p>
					</div>
					<button type="button" class="button button-primary" id="mmpt-ext-connect" disabled><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Connect this browser', 'mmi-data-pipeline' ); ?></button>
				</div>
				<div class="mmi-section-content">
					<div id="mmpt-ext-status" class="notice notice-info inline" role="status"><p><?php esc_html_e( 'Looking for the extension in this browser…', 'mmi-data-pipeline' ); ?></p></div>
				</div>
			</div>

			<div class="mmi-process-section">
				<div class="mmi-section-header">
					<div>
						<h3 class="mmi-process-section-header"><span class="dashicons dashicons-laptop"></span> <?php esc_html_e( 'Connected browsers', 'mmi-data-pipeline' ); ?></h3>
						<p class="mmi-process-section-description"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'A browser not used for %d days is disconnected on its own, and connecting or updating to a newer version of the extension disconnects the browsers on older versions.', 'mmi-data-pipeline' ), self::IDLE_DAYS ) ); ?></p>
					</div>
				</div>
				<div class="mmi-section-content">
					<div class="mmi-table-scroll-wrapper">
						<table class="widefat striped mmpt-ext-tokens">
							<thead><tr><th><?php esc_html_e( 'Browser', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'Connected', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'Last used', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'Version', 'mmi-data-pipeline' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mmi-data-pipeline' ); ?></span></th></tr></thead>
							<tbody id="mmpt-ext-tokens"><tr><td colspan="5"><span class="spinner is-active"></span> <?php esc_html_e( 'Loading…', 'mmi-data-pipeline' ); ?></td></tr></tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ── The download ───────────────────────────────────────────────────── */

	/**
	 * Where the extension's files live: this plugin's browser-extension/ folder (filter
	 * mmi_pipeline_extension_source_dir, for a store that builds its own copy).
	 */
	private static function source_dir(): string {
		return trailingslashit( (string) apply_filters( 'mmi_pipeline_extension_source_dir', dirname( __DIR__, 3 ) . '/browser-extension/' ) );
	}

	/**
	 * The files a download carries: the extension folder, and the live mapper's scripts from assets/js (BUNDLED, one
	 * source). @return array<string,string> path in the zip => file
	 */
	private static function files(): array {
		$dir = self::source_dir();
		$out = [];
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$rel = ltrim( substr( (string) $file->getPathname(), strlen( $dir ) ), '/' );
			if ( $file->isFile() && ! preg_match( '#(^|/)(\.|README|tests?/)#', $rel ) ) {
				$out[ $rel ] = (string) $file->getPathname();
			}
		}
		foreach ( self::BUNDLED as $f ) {
			$out[ $f ] = dirname( __DIR__ ) . '/assets/js/' . $f;
		}
		ksort( $out );
		return $out;
	}

	/** This server's build of the extension: changes with any file a download carries (build.json in the zip). */
	public static function build(): string {
		static $build = null;
		if ( $build === null ) {
			$build = substr( md5( (string) wp_json_encode( array_map( 'md5_file', self::files() ) ) ), 0, 12 );
		}
		return $build;
	}

	/** The extension's version, from its manifest. */
	public static function version(): string {
		$m = json_decode( (string) @file_get_contents( self::source_dir() . 'manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- a local file
		return is_array( $m ) ? (string) ( $m['version'] ?? '?' ) : '?';
	}

	/**
	 * The extension folder as a zip in a temporary file (the caller deletes it): the download, and the Mac updater's
	 * package (a store's own updater, if it has one). Its files sit under one folder named with the version, so unzipping never makes "… 2"
	 * (owner 2026-11-02); build.json says which build it is.
	 *
	 * The source names no store (migration phase 10b). A copy for this store ($this_store, the download and the Mac
	 * updater's package) is made this store's here: its manifest gets this site's host permission and the bridge on its
	 * admin pages, and build.json names the store (store_info()), so it connects here without asking for an address. Without
	 * $this_store it is the copy for the Chrome Web Store (10e), which asks which store when it is connected.
	 *
	 * @return string|\WP_Error the file
	 */
	public static function zip_file( bool $this_store = true ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'mmpt_ext_zip', __( 'This server can’t make zip files (PHP zip extension missing).', 'mmi-data-pipeline' ) );
		}
		$tmp = wp_tempnam( 'mmi-product-data-extension' );
		$zip = new \ZipArchive();
		if ( $zip->open( $tmp, \ZipArchive::OVERWRITE ) !== true ) {
			return new \WP_Error( 'mmpt_ext_zip', __( 'The zip file couldn’t be made.', 'mmi-data-pipeline' ) );
		}
		$root = 'mmi-product-data-extension-' . sanitize_file_name( self::version() ) . '/';
		$store = $this_store ? self::store_info() : null;
		foreach ( self::files() as $rel => $path ) {
			if ( $store && $rel === 'manifest.json' ) {
				$zip->addFromString( $root . $rel, self::store_manifest( (string) file_get_contents( $path ), $store ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file
				continue;
			}
			$zip->addFile( $path, $root . $rel );
		}
		$zip->addFromString( $root . 'build.json', (string) wp_json_encode( array_filter( [ 'build' => self::build(), 'version' => self::version(), 'store' => $store ] ) ) );
		$zip->close();
		return $tmp;
	}

	/**
	 * The Chrome Web Store's package (migration phase 10e, `wp mmpt extension package`): the copy that names no store,
	 * its files at the zip's root (the store wants manifest.json there), without the alarms permission that only a
	 * folder copy's self-update uses (the store updates its own copies).
	 * @return string|\WP_Error the file
	 */
	public static function listing_zip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'mmpt_ext_zip', __( 'This server can’t make zip files (PHP zip extension missing).', 'mmi-data-pipeline' ) );
		}
		$tmp = wp_tempnam( 'mmi-product-data-listing' );
		$zip = new \ZipArchive();
		if ( $zip->open( $tmp, \ZipArchive::OVERWRITE ) !== true ) {
			return new \WP_Error( 'mmpt_ext_zip', __( 'The zip file couldn’t be made.', 'mmi-data-pipeline' ) );
		}
		foreach ( self::files() as $rel => $path ) {
			if ( $rel === 'manifest.json' ) {
				$m                = (array) json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file
				$m['permissions'] = array_values( array_diff( (array) ( $m['permissions'] ?? [] ), [ 'alarms' ] ) );
				$zip->addFromString( $rel, (string) wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				continue;
			}
			$zip->addFile( $path, $rel );
		}
		$zip->addFromString( 'build.json', (string) wp_json_encode( [ 'build' => self::build(), 'version' => self::version() ] ) );
		$zip->close();
		return $tmp;
	}

	/**
	 * This store as the extension keeps it (sw.js storeOf()): its origin, where WordPress is, its routes and its Chrome
	 * extension page. @return array{origin:string,base:string,rest:string,connect:string}
	 */
	public static function store_info(): array {
		$admin = untrailingslashit( admin_url() );
		$p     = wp_parse_url( $admin );
		$port  = isset( $p['port'] ) ? ':' . $p['port'] : '';
		return [
			'origin'  => ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . $port,
			'base'    => (string) preg_replace( '#/wp-admin$#', '', $admin ),
			'rest'    => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'connect' => admin_url( 'admin.php?page=' . self::SLUG ),
		];
	}

	/** The source's manifest made this store's: host permission for its site, the bridge on its admin pages. */
	private static function store_manifest( string $json, array $store ): string {
		$m = json_decode( $json, true );
		if ( ! is_array( $m ) ) {
			return $json;
		}
		$m['host_permissions'] = [ $store['origin'] . '/*' ];
		$m['content_scripts']  = [
			[
				'matches' => [ $store['origin'] . (string) wp_parse_url( admin_url(), PHP_URL_PATH ) . '*' ],
				'js'      => [ 'bridge.js' ],
				'run_at'  => 'document_idle',
			],
		];
		return (string) wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** admin-post.php?action=mmpt_ext_download: the extension folder as a zip, built from the files on disk. */
	public static function download(): void {
		if ( ! current_user_can( Rest::CAP ) || ! check_admin_referer( 'mmpt_ext_download' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-data-pipeline' ) );
		}
		$tmp = self::zip_file();
		if ( is_wp_error( $tmp ) ) {
			wp_die( esc_html( $tmp->get_error_message() ) );
		}
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'extension.download', [ 'object_type' => 'extension', 'object_id' => self::version(), 'details' => [ 'build' => self::build() ] ] );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="mmi-product-data-extension-' . sanitize_file_name( self::version() ) . '.zip"' );
		header( 'Content-Length: ' . (string) filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $tmp );
		exit;
	}
}
