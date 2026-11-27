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
			return rest_ensure_response( [ 'user' => wp_get_current_user()->display_name, 'site' => home_url(), 'api' => self::API, 'parts' => self::parts_for_user() ] );
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
					'rest'     => esc_url_raw( rest_url( $p['ns'] . '/ext/' ) ),
					'protocol' => $p['protocol'],
					'sites'    => $p['sites'],
					'locked'   => $why,
				];
			}
			return $out;
		}
	}

endif;
