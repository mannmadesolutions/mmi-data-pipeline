<?php
/**
 * MMI License Recheck — the two ways a site re-checks its licenses sooner
 * than the periodic pull (mmi_license_revalidate_all() on a weekly cron):
 *
 *  1. Push: the license server POSTs a signed ping to
 *     /?rest_route=/mmi/v1/license/recheck when staff change a license this
 *     site holds (MMI_License_Push in mmi-admin). The ping carries no license
 *     state — this site just re-runs its normal validate() against the
 *     server, so a forged ping can at worst cause one extra check-in. The
 *     HMAC (keyed by the license key both sides already hold) and nonce keep
 *     strangers from using this endpoint to generate traffic.
 *
 *  2. Expiry: a single cron event at the soonest of each cached license's
 *     expiry_date, trial_ends_at and (an hour before) grace end, so a
 *     renewal — or a lapse — takes effect on time.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_License_Recheck {

	const ROUTE_NAMESPACE = 'mmi/v1';
	const ROUTE           = '/license/recheck';
	const EXPIRY_HOOK     = 'mmi_shared_license_revalidate_expiry';

	/** A ping older/newer than this is rejected (covers modest clock skew). */
	const MAX_SKEW = 5 * MINUTE_IN_SECONDS;

	/** At most one pushed re-check per this window; extra pings are acknowledged and dropped. */
	const COALESCE_SECONDS = MINUTE_IN_SECONDS;

	/* ── 1. Push receiver ──────────────────────────────────────────────── */

	public static function register_route(): void {
		register_rest_route( self::ROUTE_NAMESPACE, self::ROUTE, [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_ping' ],
			// No WordPress user is involved: the caller is the license server,
			// authenticated by an HMAC keyed with a license key this site holds
			// (verify_signature()), a +/- MAX_SKEW timestamp window, and a
			// single-use nonce (handle_ping()).
			'permission_callback' => [ __CLASS__, 'verify_signature' ],
		] );
	}

	/**
	 * permission_callback: true only for a well-formed, in-window ping whose
	 * HMAC matches a cached license key. A WP_Error (not false) so the
	 * rejection stays HTTP 403 — false would become 401 for a logged-out
	 * caller, which the server's sender treats as retryable.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_signature( WP_REST_Request $request ) {
		$kid   = (string) $request->get_param( 'kid' );
		$ts    = (int) $request->get_param( 'ts' );
		$nonce = (string) $request->get_param( 'nonce' );
		$sig   = (string) $request->get_param( 'sig' );

		if ( ! preg_match( '/^[a-f0-9]{16}$/', $kid ) || ! preg_match( '/^[A-Za-z0-9]{8,64}$/', $nonce )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $sig ) || abs( time() - $ts ) > self::MAX_SKEW ) {
			return self::forbidden();
		}

		$key = self::cached_key_for( $kid );
		if ( $key === '' || ! hash_equals( hash_hmac( 'sha256', "mmi-license-recheck|{$kid}|{$ts}|{$nonce}", $key ), $sig ) ) {
			return self::forbidden();
		}

		return true;
	}

	public static function handle_ping( WP_REST_Request $request ): WP_REST_Response {
		// Signature, key id and timestamp window already enforced by
		// verify_signature() (permission_callback); re-checked here so the
		// handler stays safe if ever called directly.
		if ( true !== self::verify_signature( $request ) ) {
			return self::reject();
		}
		$nonce = (string) $request->get_param( 'nonce' );

		$nonce_key = 'mmi_lic_push_nonce_' . md5( $nonce );
		if ( get_transient( $nonce_key ) ) {
			return self::reject();
		}
		set_transient( $nonce_key, 1, 2 * self::MAX_SKEW );

		if ( ! get_transient( 'mmi_lic_push_recheck_pending' ) ) {
			set_transient( 'mmi_lic_push_recheck_pending', 1, self::COALESCE_SECONDS );
			add_action( 'shutdown', [ __CLASS__, 'run_after_response' ] );
		}

		// Accepted pings only: rejected ones are unauthenticated traffic and
		// would let anyone grow the audit table.
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			MMI_Audit_Log::record( 'mmi-shared', 'license.push_recheck', array(
				'object_type' => 'license',
				'object_id'   => (string) $request->get_param( 'kid' ),
				'outcome'     => 'success',
			) );
		}

		return new WP_REST_Response( [ 'accepted' => true ], 202 );
	}

	/**
	 * Re-check once the 202 has gone back, so the server's sender never waits
	 * on this site's own round-trip to it. Without PHP-FPM's
	 * fastcgi_finish_request() the check is queued to WP-Cron instead.
	 */
	public static function run_after_response(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			mmi_license_revalidate_all();
			return;
		}
		if ( ! wp_next_scheduled( 'mmi_shared_license_revalidate_now' ) ) {
			wp_schedule_single_event( time(), 'mmi_shared_license_revalidate_now' );
		}
	}

	/** Cached license key whose key id matches, or ''. */
	private static function cached_key_for( string $kid ): string {
		foreach ( (array) MMI_Settings::get( 'mmi_license_scopes', [] ) as $scope ) {
			$data = MMI_Settings::get( 'mmi_license_' . $scope );
			$key  = is_array( $data ) ? (string) ( $data['key'] ?? '' ) : '';
			if ( $key !== '' && hash_equals( substr( hash( 'sha256', $key ), 0, 16 ), $kid ) ) {
				return $key;
			}
		}
		return '';
	}

	private static function forbidden(): WP_Error {
		return new WP_Error( 'mmi_license_recheck_forbidden', 'Forbidden.', [ 'status' => 403 ] );
	}

	private static function reject(): WP_REST_Response {
		return new WP_REST_Response( [ 'accepted' => false ], 403 );
	}

	/* ── 2. Check at expiry ────────────────────────────────────────────── */

	/**
	 * Keep exactly one EXPIRY_HOOK event at the soonest upcoming moment that
	 * matters across cached licenses (none if nothing expires): a minute
	 * after expiry_date / trial_ends_at, and an hour before grace ends so a
	 * renewal whose push never arrived still lands before the local gate
	 * locks. Cheap: one cron-array read when nothing changed.
	 */
	public static function schedule_expiry_check(): void {
		$soonest = 0;
		foreach ( (array) MMI_Settings::get( 'mmi_license_scopes', [] ) as $scope ) {
			$data = MMI_Settings::get( 'mmi_license_' . $scope );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$moments = [];
			foreach ( [ 'expiry_date', 'trial_ends_at' ] as $field ) {
				if ( ! empty( $data[ $field ] ) ) {
					$moments[] = (int) strtotime( (string) $data[ $field ] ) + MINUTE_IN_SECONDS;
				}
			}
			$grace_end = mmi_license_grace_ends_at( $data['expiry_date'] ?? null );
			if ( $grace_end !== null ) {
				$moments[] = $grace_end - HOUR_IN_SECONDS;
			}
			foreach ( $moments as $at ) {
				if ( $at > time() && ( $soonest === 0 || $at < $soonest ) ) {
					$soonest = $at;
				}
			}
		}

		$target = $soonest;
		if ( (int) wp_next_scheduled( self::EXPIRY_HOOK ) === $target ) {
			return;
		}
		wp_clear_scheduled_hook( self::EXPIRY_HOOK );
		if ( $target ) {
			wp_schedule_single_event( $target, self::EXPIRY_HOOK );
		}
	}
}
