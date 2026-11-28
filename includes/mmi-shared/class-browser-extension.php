<?php
/**
 * MMI_Browser_Extension — the MannMade Chrome extension's shell on the server, shared by every MMI plugin
 * (mmi-admin/docs/BROWSER_EXTENSION_PLATFORM_SCOPE.md, owner 2026-11-27: one extension, and each person sees only the
 * parts of the plugins installed on their store).
 *
 * The extension is a shell plus parts. A plugin that brings a part registers it here (register_part()) with the
 * routes it answers under its own REST namespace ({ns}/ext/{route}) and the capability it needs. This class holds what
 * no part owns:
 *  - connecting: a per-browser key (POST pair, cookie + nonce), only its hash kept (user meta META);
 *  - the sign-in: the key in the X-MMI-Ext-Token header signs a person in on a registered part's routes and the
 *    shell's own hello, nowhere else, and only while that person has the part's capability;
 *  - hello: who is connected, and the parts this store has for them. A part whose plugin isn't installed isn't
 *    registered, so it isn't listed and its routes don't exist here: the store, not the extension, keeps it out.
 *    A registered part that says it is locked (its license) is listed as locked.
 *
 * The routes (NS) are POST, except the cookie-only list of connected browsers: nginx and Cloudflare never cache a
 * POST, and the extension's calls carry no login cookie for their logged-in bypass to see.
 *   POST pair {label}  cookie: a key for this browser (shown once)
 *   GET  tokens        cookie: this person's connected browsers
 *   POST revoke {id}   cookie: disconnect one
 *   POST hello         key:    who is connected, the parts
 *
 * Keys made before the shell (Product Data's `_mmpt_ext_tokens`, same `mmx1.` form) are moved here on first use, so
 * no browser connects again.
 *
 * The extension itself (E1c): the shell's files are this library's browser-extension/ folder, and each part brings its
 * own folder (register_part() 'files'), carried under parts/{id}/ in the download. A part's sw-part.js is loaded by the
 * shell's service worker (parts.js, written here), and its bridge-part.js goes on the store's admin pages with the
 * shell's bridge.js. A store's own download carries the parts registered on that store; the Chrome Web Store's package
 * is built where every part is installed. The Chrome extension page (SLUG) is the shell's: install, connect, connected
 * browsers, then each part's sections (action mmi_browser_extension_sections).
 *
 * Every plugin that brings a part calls init() (idempotent). Never ask for the current user, or call anything that
 * does (MMI_Audit_Log among them), inside user_from_token(): the sign-in would start again (2026-11-02, out of memory).
 *
 * Do not hand-edit this file in a single plugin; edit the canonical source in mmi-admin/lib/mmi-shared/ and run
 * lib/sync-to-plugins.sh.
 *
 * @package MMI_Shared
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Browser_Extension' ) ) :

	final class MMI_Browser_Extension {

		const NS         = 'mmi-ext/v1';
		/** What the shell offers parts; a part needing more stays off under an older shell. */
		const API        = 1;
		const META       = '_mmi_ext_tokens';       // [ id => { hash, label, created, used, version } ]
		const OLD_META   = '_mmpt_ext_tokens';      // Product Data's keys before the shell, moved on first use
		const MAX_TOKENS = 5;                       // per person; the oldest goes first
		const IDLE_DAYS  = 90;                      // a key unused this long stops working
		const TOKEN_RE   = '/^mmx1\.(\d+)\.([a-f0-9]{8})\.([a-f0-9]{64})$/';
		const SLUG       = 'mmi-chrome-extension';  // the Chrome extension page
		const OLD_SLUG   = 'mmi-product-extension'; // Product Data's page before the shell: redirects to SLUG
		/** The download's folder name, before its version (the Mac updater finds manifest.json wherever it lands; the checks unzip by this). */
		const ZIP_PREFIX = 'mannmade-extension-';

		private static bool $initialized = false;

		/** @var array<string,array{id:string,name:string,ns:string,routes:string[],cap:string,sites:string[],protocol:int,api:int,locked:?callable}> */
		private static array $parts = [];

		/** null: no key on this request; 'ok' or 'bad' once checked. */
		private static ?string $auth = null;

		private static bool $checking = false;

		/** Browsers disconnected by a newer version during the sign-in, recorded once the user is known. */
		private static ?array $retired = null;

		public static function init(): void {
			if ( self::$initialized ) {
				return;
			}
			self::$initialized = true;
			add_filter( 'determine_current_user', [ __CLASS__, 'user_from_token' ], 20 );
			add_filter( 'rest_authentication_errors', [ __CLASS__, 'auth_errors' ], 90 );
			add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
			add_action( 'admin_post_mmi_ext_download', [ __CLASS__, 'download' ] );
			if ( is_admin() ) {
				add_action( 'admin_menu', [ __CLASS__, 'menu' ], 32 );
				add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
			}
		}

		/**
		 * A plugin's part of the extension.
		 *
		 * @param array $part {
		 *   id:       short key ('product-data', 'xchange')
		 *   name:     what a person calls it
		 *   ns:       the plugin's REST namespace; the part's routes are {ns}/ext/{route}
		 *   routes:   route names the key signs in on
		 *   cap:      the capability a person needs for it
		 *   sites:    hosts the part works on ('*': any page no other part claims)
		 *   protocol: the part's own protocol, told to the extension
		 *   api:      the shell API it needs (default 1)
		 *   locked:   optional callable → '' when usable, else why not (shown to the person)
		 *   files:    optional callable( string $root ) → [ path in the part => file ]: the part's browser files, from the
		 *             live plugin, or from $root, another copy of the plugin (a pushed commit, for the Web Store package)
		 *   legacy:   true for the one part whose routes copies of the extension from before the shell call at the store's
		 *             address (Product Data): the connect page still hands that address over for them
		 * }
		 */
		public static function register_part( array $part ): void {
			$id = sanitize_key( (string) ( $part['id'] ?? '' ) );
			$ns = trim( (string) ( $part['ns'] ?? '' ), '/' );
			if ( $id === '' || $ns === '' ) {
				return;
			}
			self::$parts[ $id ] = [
				'id'       => $id,
				'name'     => (string) ( $part['name'] ?? $id ),
				'ns'       => $ns,
				'routes'   => array_values( array_filter( array_map( 'sanitize_key', (array) ( $part['routes'] ?? [] ) ) ) ),
				'cap'      => (string) ( $part['cap'] ?? 'manage_options' ),
				'sites'    => array_values( array_filter( array_map( 'strval', (array) ( $part['sites'] ?? [] ) ) ) ),
				'protocol' => (int) ( $part['protocol'] ?? 1 ),
				'api'      => (int) ( $part['api'] ?? 1 ),
				'locked'   => isset( $part['locked'] ) && is_callable( $part['locked'] ) ? $part['locked'] : null,
				'files'    => isset( $part['files'] ) && is_callable( $part['files'] ) ? $part['files'] : null,
				'legacy'   => ! empty( $part['legacy'] ),
			];
		}

		/** @return array<string,array> the registered parts this shell can serve */
		public static function parts(): array {
			return array_filter( self::$parts, static fn( $p ) => $p['api'] <= self::API );
		}

		/** For a part's permission_callback: the key signed this person in, and they may use $cap. */
		public static function signed_in( string $cap ): bool {
			return self::$auth === 'ok' && current_user_can( $cap );
		}

		/** For a cookie route: this person may use at least one part. */
		public static function may_connect(): bool {
			foreach ( self::parts() as $p ) {
				if ( current_user_can( $p['cap'] ) ) {
					return true;
				}
			}
			return false;
		}

		/* ── Sign-in ────────────────────────────────────────────────────── */

		/** The key's user, on a part's routes and hello only. Decided from the address, so it holds however early WordPress asks. */
		public static function user_from_token( $user ) {
			$token = isset( $_SERVER['HTTP_X_MMI_EXT_TOKEN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MMI_EXT_TOKEN'] ) ) : '';
			if ( $token === '' || self::$checking ) {
				return $user;
			}
			$cap = self::route_cap();
			if ( $cap === null ) {
				return $user;
			}
			self::$checking = true;
			try {
				$uid = self::check( $token, $cap );
			} finally {
				self::$checking = false;
			}
			self::$auth = $uid ? 'ok' : 'bad';
			return $uid ?: $user;
		}

		/** A refused key is an error, not a quiet sign-out; a good one needs no cookie nonce. */
		public static function auth_errors( $result ) {
			if ( self::$retired && class_exists( 'MMI_Audit_Log' ) ) { // the user is known now: the audit log asks who it is
				MMI_Audit_Log::record( 'mmi-shared', 'extension.retire_older', [ 'object_type' => 'extension', 'object_id' => (string) self::$retired['keep'], 'details' => self::$retired ] );
				self::$retired = null;
			}
			if ( ! empty( $result ) || self::$auth === null ) {
				return $result;
			}
			if ( self::$auth === 'bad' ) {
				if ( class_exists( 'MMI_Audit_Log' ) ) {
					MMI_Audit_Log::record( 'mmi-shared', 'extension.token_refused', [ 'object_type' => 'extension', 'object_id' => 'token' ] );
				}
				return new WP_Error( 'mmi_ext_token', 'This browser is no longer connected. Connect it again on the Chrome extension page.', [ 'status' => 401 ] );
			}
			return true;
		}

		/**
		 * The capability this request's route needs when it is one the key signs in on (pretty or ?rest_route= form),
		 * else null. hello needs any part's capability: the empty string, checked in check().
		 */
		private static function route_cap(): ?string {
			$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against patterns
			$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : (string) wp_parse_url( $uri, PHP_URL_PATH ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only routing
			if ( preg_match( '#/' . preg_quote( self::NS, '#' ) . '/hello/?$#', $route ) ) {
				return '';
			}
			foreach ( self::parts() as $p ) {
				if ( $p['routes'] && preg_match( '#/' . preg_quote( $p['ns'], '#' ) . '/ext/(' . implode( '|', $p['routes'] ) . ')/?$#', $route ) ) {
					return $p['cap'];
				}
			}
			return null;
		}

		/** The key's user id when it is current and the user has $cap (any part's when ''); 0 otherwise. Marks it used. */
		private static function check( string $token, string $cap ): int {
			if ( ! preg_match( self::TOKEN_RE, $token, $m ) ) {
				return 0;
			}
			$uid  = (int) $m[1];
			$rows = self::tokens_of( $uid );
			$row  = $rows[ $m[2] ] ?? null;
			if ( ! $row || ! hash_equals( (string) $row['hash'], self::hash( $m[3] ) ) || ! self::user_may( $uid, $cap ) ) {
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

		/** user_can() for $cap, or for any part's capability when $cap is ''. Never asks for the current user. */
		private static function user_may( int $uid, string $cap ): bool {
			if ( $cap !== '' ) {
				return user_can( $uid, $cap );
			}
			foreach ( self::parts() as $p ) {
				if ( user_can( $uid, $p['cap'] ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * A browser now runs extension $ver (connected, or updated in place): this person's other connections on an
		 * older version, or one never said (a key the extension never took), are disconnected (owner 2026-11-02).
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
				self::$retired = [ 'keep' => $keep, 'user' => $uid, 'version' => $ver, 'disconnected' => $gone ]; // recorded by auth_errors()
			}
			return $rows;
		}

		private static function hash( string $secret ): string {
			return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
		}

		/** A person's keys. Keys from before the shell move here the first time they are asked for. @return array<string,array> */
		private static function tokens_of( int $uid ): array {
			if ( ! metadata_exists( 'user', $uid, self::META ) ) {
				$old = get_user_meta( $uid, self::OLD_META, true );
				if ( is_array( $old ) && $old ) {
					update_user_meta( $uid, self::META, $old );
					delete_user_meta( $uid, self::OLD_META );
					return $old;
				}
				return [];
			}
			$rows = get_user_meta( $uid, self::META, true );
			return is_array( $rows ) ? $rows : [];
		}

		/* ── Routes ─────────────────────────────────────────────────────── */

		public static function routes(): void {
			$cookie = [ __CLASS__, 'may_connect' ];
			register_rest_route( self::NS, '/pair', [ 'methods' => 'POST', 'permission_callback' => $cookie, 'callback' => [ __CLASS__, 'pair' ] ] );
			register_rest_route( self::NS, '/tokens', [ 'methods' => 'GET', 'permission_callback' => $cookie, 'callback' => [ __CLASS__, 'tokens' ] ] );
			register_rest_route( self::NS, '/revoke', [ 'methods' => 'POST', 'permission_callback' => $cookie, 'callback' => [ __CLASS__, 'revoke' ] ] );
			register_rest_route(
				self::NS,
				'/hello',
				[
					'methods'             => 'POST',
					'permission_callback' => static fn() => self::$auth === 'ok',
					'callback'            => [ __CLASS__, 'hello' ],
				]
			);
		}

		/** POST pair {label}: a new key for the browser asking. The plain key is in this answer only. */
		public static function pair( WP_REST_Request $r ): WP_REST_Response {
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
				MMI_Audit_Log::record( 'mmi-shared', 'extension.pair', [ 'object_type' => 'extension', 'object_id' => $id, 'details' => [ 'label' => $label ] ] );
			}
			if ( class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::info( 'Chrome extension connected', [ 'id' => $id, 'label' => $label ], 'security', 'MMI_Browser_Extension' );
			}
			return rest_ensure_response( [ 'token' => "mmx1.{$uid}.{$id}.{$secret}", 'id' => $id, 'api' => self::API, 'tokens' => self::tokens_out( $rows ) ] );
		}

		/** GET tokens: this person's connected browsers. */
		public static function tokens(): WP_REST_Response {
			return rest_ensure_response( [ 'tokens' => self::tokens_out( self::tokens_of( get_current_user_id() ) ) ] );
		}

		/** POST revoke {id}: disconnect one browser. */
		public static function revoke( WP_REST_Request $r ): WP_REST_Response {
			$uid  = get_current_user_id();
			$id   = sanitize_key( (string) $r->get_param( 'id' ) );
			$rows = self::tokens_of( $uid );
			if ( ! isset( $rows[ $id ] ) ) {
				return new WP_REST_Response( [ 'message' => 'That browser isn’t connected.', 'tokens' => self::tokens_out( $rows ) ], 404 );
			}
			unset( $rows[ $id ] );
			update_user_meta( $uid, self::META, $rows );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				MMI_Audit_Log::record( 'mmi-shared', 'extension.revoke', [ 'object_type' => 'extension', 'object_id' => $id ] );
			}
			return rest_ensure_response( [ 'tokens' => self::tokens_out( $rows ) ] );
		}

		/** Connected browsers as the Chrome extension page lists them: no hashes. */
		private static function tokens_out( array $rows ): array {
			$out = [];
			foreach ( $rows as $id => $t ) {
				$out[] = [ 'id' => (string) $id, 'label' => (string) $t['label'], 'created' => (string) $t['created'], 'used' => (string) $t['used'], 'version' => (string) ( $t['version'] ?? '' ) ];
			}
			usort( $out, static fn( $a, $b ) => strcmp( $b['created'], $a['created'] ) );
			return $out;
		}

		/** POST hello: who this browser is connected as, and the parts this store has for them. */
		public static function hello(): WP_REST_Response {
			return rest_ensure_response( [ 'user' => wp_get_current_user()->display_name, 'site' => home_url(), 'api' => self::API, 'page' => self::page_url(), 'parts' => self::parts_for_user() ] );
		}

		/** The parts the current person may use, each with its routes' base and whether it is locked. @return array<int,array> */
		public static function parts_for_user(): array {
			$out = [];
			foreach ( self::parts() as $p ) {
				if ( ! current_user_can( $p['cap'] ) ) {
					continue;
				}
				$why   = $p['locked'] ? (string) call_user_func( $p['locked'] ) : '';
				$out[] = [
					'id'       => $p['id'],
					'name'     => $p['name'],
					'rest'     => esc_url_raw( rest_url( $p['ns'] . '/' ) ), // the part's routes are ext/{route} under it
					'protocol' => $p['protocol'],
					'sites'    => $p['sites'],
					'locked'   => $why,
				];
			}
			return $out;
		}

		/* ── The Chrome extension page ──────────────────────────────────── */

		public static function page_url(): string {
			return admin_url( 'admin.php?page=' . self::SLUG );
		}

		/** The capability the page asks for: the first part's this person has ('' when none). */
		private static function page_cap(): string {
			foreach ( self::parts() as $p ) {
				if ( current_user_can( $p['cap'] ) ) {
					return $p['cap'];
				}
			}
			return '';
		}

		/** The page: no menu entry, linked from each part's own screens. Product Data's old address redirects here. */
		public static function menu(): void {
			$cap = self::page_cap();
			if ( $cap === '' ) {
				return;
			}
			add_submenu_page( '', __( 'Chrome extension', 'mmi-shared' ), '', $cap, self::SLUG, [ __CLASS__, 'render' ] );
			if ( ! empty( $GLOBALS['_registered_pages'][ get_plugin_page_hookname( self::OLD_SLUG, '' ) ] ) ) {
				return; // a plugin still draws its own page there (Product Data before the shell)
			}
			$old = add_submenu_page( '', __( 'Chrome extension', 'mmi-shared' ), '', $cap, self::OLD_SLUG, '__return_null' );
			if ( $old ) {
				add_action(
					'load-' . $old,
					static function () {
						wp_safe_redirect( self::page_url() );
						exit;
					}
				);
			}
		}

		public static function assets( $hook ): void {
			if ( ! is_string( $hook ) || strpos( $hook, self::SLUG ) === false || ! function_exists( 'mmi_shared_assets_winner' ) ) {
				return;
			}
			$w = mmi_shared_assets_winner();
			wp_enqueue_style( 'mmi-ext-page', $w['url'] . 'css/shared/browser-extension-page.css', wp_style_is( 'mmi-suite-common', 'registered' ) ? [ 'mmi-suite-common' ] : [], $w['version'] );
			wp_enqueue_script( 'mmi-ext-page', $w['url'] . 'js/shared/browser-extension-page.js', [], $w['version'], true );
			wp_localize_script(
				'mmi-ext-page',
				'mmiExtConnect',
				[
					'rest'    => esc_url_raw( rest_url( self::NS . '/' ) ),
					'legacy'  => self::legacy_rest(),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'version' => self::version(),
					'name'    => self::name(),
				]
			);
			do_action( 'mmi_browser_extension_page_assets' ); // a part's or a store's own parts of this page
		}

		/**
		 * Where copies of the extension from before the shell send every call: the legacy part's routes (Product Data's
		 * mmpt/v1/), '' when no part is one. The connect page hands it over as `rest`, the shell's own as `shell`.
		 */
		private static function legacy_rest(): string {
			foreach ( self::parts() as $p ) {
				if ( $p['legacy'] ) {
					return esc_url_raw( rest_url( $p['ns'] . '/' ) );
				}
			}
			return '';
		}

		/** The extension's Chrome Web Store listing, once it has one (filter mmi_browser_extension_store_url). */
		public static function store_url(): string {
			return esc_url_raw( (string) apply_filters( 'mmi_browser_extension_store_url', '' ) );
		}

		/**
		 * What to do about an old extension. A store copy updates through the Chrome Web Store by itself; a copy loaded by
		 * hand is updated from the Chrome extension page. A store's own plugin can add its way (filter
		 * mmi_browser_extension_update_help, e.g. an updater that keeps unpacked copies current).
		 */
		public static function update_help( string $lead ): string {
			$text = $lead . ' A copy from the Chrome Web Store updates by itself. A copy loaded by hand: on the Chrome extension page, download the new version, unzip it (its folder carries the version number), remove the old card in chrome://extensions, Load unpacked the new folder and connect it again.';
			return (string) apply_filters( 'mmi_browser_extension_update_help', $text, $lead );
		}

		public static function render(): void {
			if ( self::page_cap() === '' ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'mmi-shared' ) );
			}
			$zip   = wp_nonce_url( admin_url( 'admin-post.php?action=mmi_ext_download' ), 'mmi_ext_download' );
			$store = self::store_url();
			$name  = self::name();
			$parts = array_map( static fn( $p ) => $p['name'], self::parts_for_user() );
			?>
			<div class="wrap mmi-page mmi-ext-page">
				<div class="mmi-header">
					<h1><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e( 'Chrome extension', 'mmi-shared' ); ?></h1>
					<p class="mmi-header-description"><?php echo esc_html( sprintf( /* translators: 1: the extension's name, 2: the parts this store has, comma-separated */ __( '%1$s brings this store’s tools to the web pages you work on in Chrome. On this store it works with: %2$s. It talks only to this site.', 'mmi-shared' ), $name, implode( ', ', $parts ) ) ); ?></p>
				</div>
				<div class="wp-header-end"></div>

				<div class="mmi-process-section">
					<div class="mmi-section-header">
						<div>
							<h3 class="mmi-process-section-header"><span class="dashicons dashicons-download"></span> <?php esc_html_e( '1 · Install it in Chrome', 'mmi-shared' ); ?></h3>
							<p class="mmi-process-section-description"><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Version %s. It works in Chrome, Edge and Brave.', 'mmi-shared' ), self::version() ) ); ?></p>
						</div>
						<?php if ( $store !== '' ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $store ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-external"></span> <?php esc_html_e( 'Get it from the Chrome Web Store', 'mmi-shared' ); ?></a>
						<?php endif; ?>
						<?php do_action( 'mmi_browser_extension_install_buttons' ); ?>
						<a class="button<?php echo $store === '' ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $zip ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download the extension', 'mmi-shared' ); ?></a>
					</div>
					<div class="mmi-section-content">
						<?php do_action( 'mmi_browser_extension_install' ); ?>
						<details class="mmi-ext-manual"<?php echo $store === '' ? ' open' : ''; ?>>
							<summary><?php esc_html_e( 'Loading the download by hand', 'mmi-shared' ); ?></summary>
							<ol class="mmi-ext-steps">
								<li><?php esc_html_e( 'Unzip the download. Keep the folder somewhere it can stay (Documents, for example).', 'mmi-shared' ); ?></li>
								<li><?php esc_html_e( 'In Chrome, open chrome://extensions and switch on Developer mode (top right).', 'mmi-shared' ); ?></li>
								<li><?php echo esc_html( sprintf( /* translators: %s: the extension's name */ __( 'Press Load unpacked and choose the folder. Pin %s to the toolbar (the puzzle-piece menu).', 'mmi-shared' ), $name ) ); ?></li>
								<li><?php esc_html_e( 'Reload this page, then connect it below.', 'mmi-shared' ); ?></li>
							</ol>
							<p class="description"><?php echo esc_html( sprintf( /* translators: 1: the unzipped folder's name, 2: the extension's name */ __( 'To update: download again and unzip it: the folder is named with its version (%1$s), so it never becomes “… 2”. In chrome://extensions remove the old %2$s card, press Load unpacked and choose the new folder, then connect it again below (one click; the old version’s connection closes by itself). The old folder can then be deleted.', 'mmi-shared' ), self::ZIP_PREFIX . self::version(), $name ) ); ?></p>
						</details>
					</div>
				</div>

				<?php do_action( 'mmi_browser_extension_after_install' ); ?>

				<div class="mmi-process-section">
					<div class="mmi-section-header">
						<div>
							<h3 class="mmi-process-section-header"><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( '2 · Connect this browser', 'mmi-shared' ); ?></h3>
							<p class="mmi-process-section-description"><?php esc_html_e( 'Connecting gives the extension a key that works only for the extension’s routes on this site, under your login. Nothing is copied by hand.', 'mmi-shared' ); ?></p>
						</div>
						<button type="button" class="button button-primary" id="mmi-ext-connect" disabled><span class="dashicons dashicons-admin-links"></span> <?php esc_html_e( 'Connect this browser', 'mmi-shared' ); ?></button>
					</div>
					<div class="mmi-section-content">
						<div id="mmi-ext-status" class="notice notice-info inline" role="status"><p><?php esc_html_e( 'Looking for the extension in this browser…', 'mmi-shared' ); ?></p></div>
					</div>
				</div>

				<div class="mmi-process-section">
					<div class="mmi-section-header">
						<div>
							<h3 class="mmi-process-section-header"><span class="dashicons dashicons-laptop"></span> <?php esc_html_e( 'Connected browsers', 'mmi-shared' ); ?></h3>
							<p class="mmi-process-section-description"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'A browser not used for %d days is disconnected on its own, and connecting or updating to a newer version of the extension disconnects the browsers on older versions.', 'mmi-shared' ), self::IDLE_DAYS ) ); ?></p>
						</div>
					</div>
					<div class="mmi-section-content">
						<div class="mmi-table-scroll-wrapper">
							<table class="widefat striped mmi-ext-tokens">
								<thead><tr><th><?php esc_html_e( 'Browser', 'mmi-shared' ); ?></th><th><?php esc_html_e( 'Connected', 'mmi-shared' ); ?></th><th><?php esc_html_e( 'Last used', 'mmi-shared' ); ?></th><th><?php esc_html_e( 'Version', 'mmi-shared' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mmi-shared' ); ?></span></th></tr></thead>
								<tbody id="mmi-ext-tokens"><tr><td colspan="5"><span class="spinner is-active"></span> <?php esc_html_e( 'Loading…', 'mmi-shared' ); ?></td></tr></tbody>
							</table>
						</div>
					</div>
				</div>

				<?php do_action( 'mmi_browser_extension_sections' ); ?>
			</div>
			<?php
		}

		/* ── The download ───────────────────────────────────────────────── */

		/** The shell's own files: this library's browser-extension/ folder. */
		private static function shell_dir(): string {
			return __DIR__ . '/browser-extension/';
		}

		/** A folder's files: relative path → file, without dot files, READMEs and tests. @return array<string,string> */
		public static function folder_files( string $dir ): array {
			$dir = trailingslashit( $dir );
			$out = [];
			if ( ! is_dir( $dir ) ) {
				return $out;
			}
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				$rel = ltrim( substr( (string) $file->getPathname(), strlen( $dir ) ), '/' );
				if ( $file->isFile() && ! preg_match( '#(^|/)(\.|README|tests?/)#', $rel ) ) {
					$out[ $rel ] = (string) $file->getPathname();
				}
			}
			return $out;
		}

		/**
		 * Every file the extension is made of: the shell's at the root, each registered part's under parts/{id}/.
		 * $roots: [ 'shell' => folder, part id => plugin copy ] to build from other copies (a pushed commit).
		 * @return array<string,string> path in the zip => file
		 */
		public static function files( array $roots = [] ): array {
			$out = self::folder_files( (string) ( $roots['shell'] ?? self::shell_dir() ) );
			foreach ( self::parts() as $id => $p ) {
				if ( ! $p['files'] ) {
					continue;
				}
				foreach ( (array) call_user_func( $p['files'], (string) ( $roots[ $id ] ?? '' ) ) as $rel => $file ) {
					$out[ 'parts/' . $id . '/' . ltrim( (string) $rel, '/' ) ] = (string) $file;
				}
			}
			ksort( $out );
			return $out;
		}

		/** The scripts that go on the store's admin pages: the shell's bridge, then each part's. @return string[] */
		private static function bridge_files( array $files ): array {
			return array_merge( [ 'bridge.js' ], array_values( array_filter( array_keys( $files ), static fn( $f ) => (bool) preg_match( '#^parts/[a-z0-9-]+/bridge-part\.js$#', $f ) ) ) );
		}

		/** parts.js: the parts this copy carries, loaded by the shell's service worker. */
		private static function parts_js( array $files ): string {
			$parts = [];
			$load  = [];
			foreach ( array_keys( self::parts() ) as $id ) {
				$dir = 'parts/' . $id . '/';
				if ( ! isset( $files[ $dir . 'sw-part.js' ] ) ) {
					continue;
				}
				$parts[] = [ 'id' => $id, 'dir' => $dir ];
				$load[]  = $dir . 'sw-part.js';
			}
			return "/* Written by MMI_Browser_Extension::parts_js(): the parts this copy carries. */\n'use strict';\nself.MMI_EXT_PARTS = " . wp_json_encode( $parts, JSON_UNESCAPED_SLASHES ) . ";\nself.MMI_EXT_BRIDGE = " . wp_json_encode( self::bridge_files( $files ), JSON_UNESCAPED_SLASHES ) . ";\n" . ( $load ? 'importScripts( ' . implode( ', ', array_map( static fn( $f ) => wp_json_encode( $f, JSON_UNESCAPED_SLASHES ), $load ) ) . " );\n" : '' );
		}

		/** A build's id: changes with any file the extension carries (build.json in the zip). */
		private static function build_of( array $files ): string {
			return substr( md5( (string) wp_json_encode( array_map( 'md5_file', $files ) ) . self::parts_js( $files ) ), 0, 12 );
		}

		/** This server's build of the extension. */
		public static function build(): string {
			static $build = null;
			if ( $build === null ) {
				$build = self::build_of( self::files() );
			}
			return $build;
		}

		/** The shell's manifest. @return array */
		private static function manifest( string $dir = '' ): array {
			$m = json_decode( (string) @file_get_contents( ( $dir !== '' ? trailingslashit( $dir ) : self::shell_dir() ) . 'manifest.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- a local file
			return is_array( $m ) ? $m : [];
		}

		/** The extension's version, from its manifest. */
		public static function version(): string {
			return (string) ( self::manifest()['version'] ?? '?' );
		}

		/** The extension's name, from its manifest. */
		public static function name(): string {
			return (string) ( self::manifest()['name'] ?? 'MannMade' );
		}

		/**
		 * The extension as a zip in a temporary file (the caller deletes it): the download, and the Mac updater's package.
		 * Its files sit under one folder named with the version, so unzipping never makes "… 2" (owner 2026-11-02).
		 *
		 * A copy for this store ($this_store) is made this store's here: its manifest gets this site's host permission and
		 * the bridge on its admin pages, and build.json names the store (store_info()), so it connects here without asking.
		 * Without $this_store it names no store, as the Chrome Web Store's copy, and asks which store when connected.
		 *
		 * @return string|WP_Error the file
		 */
		public static function zip_file( bool $this_store = true ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				return new WP_Error( 'mmi_ext_zip', __( 'This server can’t make zip files (PHP zip extension missing).', 'mmi-shared' ) );
			}
			$tmp = wp_tempnam( 'mmi-extension' );
			$zip = new ZipArchive();
			if ( $zip->open( $tmp, ZipArchive::OVERWRITE ) !== true ) {
				return new WP_Error( 'mmi_ext_zip', __( 'The zip file couldn’t be made.', 'mmi-shared' ) );
			}
			$root  = self::ZIP_PREFIX . sanitize_file_name( self::version() ) . '/';
			$store = $this_store ? self::store_info() : null;
			$files = self::files();
			foreach ( $files as $rel => $path ) {
				if ( $store && $rel === 'manifest.json' ) {
					$zip->addFromString( $root . $rel, self::store_manifest( (string) file_get_contents( $path ), $store, self::bridge_files( $files ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file
					continue;
				}
				$zip->addFile( $path, $root . $rel );
			}
			$zip->addFromString( $root . 'parts.js', self::parts_js( $files ) );
			$zip->addFromString( $root . 'build.json', (string) wp_json_encode( array_filter( [ 'build' => self::build(), 'version' => self::version(), 'store' => $store ] ) ) );
			$zip->close();
			return $tmp;
		}

		/**
		 * The Chrome Web Store's package: the copy that names no store, its files at the zip's root (the store wants
		 * manifest.json there), without the alarms permission only a folder copy's self-update uses. $roots: build it from
		 * other copies (files()).
		 *
		 * @return string|WP_Error the file
		 */
		public static function listing_zip( array $roots = [] ) {
			$files = self::files( $roots );
			if ( ! isset( $files['manifest.json'] ) || ! is_file( $files['manifest.json'] ) ) {
				return new WP_Error( 'mmi_ext_zip', __( 'That copy has no browser-extension/manifest.json.', 'mmi-shared' ) );
			}
			if ( ! class_exists( 'ZipArchive' ) ) {
				return new WP_Error( 'mmi_ext_zip', __( 'This server can’t make zip files (PHP zip extension missing).', 'mmi-shared' ) );
			}
			$tmp = wp_tempnam( 'mmi-extension-listing' );
			$zip = new ZipArchive();
			if ( $zip->open( $tmp, ZipArchive::OVERWRITE ) !== true ) {
				return new WP_Error( 'mmi_ext_zip', __( 'The zip file couldn’t be made.', 'mmi-shared' ) );
			}
			$version = '?';
			foreach ( $files as $rel => $path ) {
				if ( $rel === 'manifest.json' ) {
					$m                = (array) json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file
					$version          = (string) ( $m['version'] ?? '?' );
					$m['permissions'] = array_values( array_diff( (array) ( $m['permissions'] ?? [] ), [ 'alarms' ] ) );
					$zip->addFromString( $rel, (string) wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
					continue;
				}
				$zip->addFile( $path, $rel );
			}
			$zip->addFromString( 'parts.js', self::parts_js( $files ) );
			$zip->addFromString( 'build.json', (string) wp_json_encode( [ 'build' => self::build_of( $files ), 'version' => $version ] ) );
			$zip->close();
			return $tmp;
		}

		/**
		 * This store as the extension keeps it (sw.js storeOf()): its origin, where WordPress is, the routes copies from
		 * before the shell call (rest), the shell's own (shell), and its Chrome extension page.
		 * @return array{origin:string,base:string,rest:string,shell:string,connect:string}
		 */
		public static function store_info(): array {
			$admin = untrailingslashit( admin_url() );
			$p     = wp_parse_url( $admin );
			$port  = isset( $p['port'] ) ? ':' . $p['port'] : '';
			$shell = esc_url_raw( rest_url( self::NS . '/' ) );
			return [
				'origin'  => ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . $port,
				'base'    => (string) preg_replace( '#/wp-admin$#', '', $admin ),
				'rest'    => self::legacy_rest() ?: $shell,
				'shell'   => $shell,
				'connect' => self::page_url(),
			];
		}

		/** The shell's manifest made this store's: host permission for its site, the bridges on its admin pages. */
		private static function store_manifest( string $json, array $store, array $bridge ): string {
			$m = json_decode( $json, true );
			if ( ! is_array( $m ) ) {
				return $json;
			}
			$m['host_permissions'] = [ $store['origin'] . '/*' ];
			$m['content_scripts']  = [
				[
					'matches' => [ $store['origin'] . (string) wp_parse_url( admin_url(), PHP_URL_PATH ) . '*' ],
					'js'      => $bridge,
					'run_at'  => 'document_idle',
				],
			];
			return (string) wp_json_encode( $m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		/** admin-post.php?action=mmi_ext_download: the extension as a zip, built from the files on disk. */
		public static function download(): void {
			if ( self::page_cap() === '' || ! check_admin_referer( 'mmi_ext_download' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'mmi-shared' ) );
			}
			$tmp = self::zip_file();
			if ( is_wp_error( $tmp ) ) {
				wp_die( esc_html( $tmp->get_error_message() ) );
			}
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				MMI_Audit_Log::record( 'mmi-shared', 'extension.download', [ 'object_type' => 'extension', 'object_id' => self::version(), 'details' => [ 'build' => self::build() ] ] );
			}
			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . self::ZIP_PREFIX . sanitize_file_name( self::version() ) . '.zip"' );
			header( 'Content-Length: ' . (string) filesize( $tmp ) );
			readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $tmp );
			exit;
		}
	}

endif;
