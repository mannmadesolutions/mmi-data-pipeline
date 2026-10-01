<?php
/**
 * MMI_API_Throttler — centralized, per-API-host rate limiter (bundled/vendored copy).
 *
 * Identical to the original mmi-hub/includes/class-api-throttler.php this was
 * vendored from. All state lives in WP transients (not a per-plugin table),
 * so this class is inherently shareable as-is once only one copy is ever
 * loaded per request — see ADR-0006. Do not hand-edit this file in a single
 * plugin; edit the canonical source and re-sync to every plugin that bundles
 * it.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_API_Throttler {

	/* ── Known API profiles ──────────────────────────────────────────────── */

	const KNOWN_APIS = array(
		'reverb'    => array(
			'min_gap_ms'     => 1500,
			'backoff_ms'     => 5000,
			'max_backoff_ms' => 60000,
			'transient_ttl'  => 300,
		),
		'xchange'   => array(
			'min_gap_ms'     => 12000,
			'backoff_ms'     => 15000,
			'max_backoff_ms' => 60000,
			'transient_ttl'  => 300,
		),
		// XChange's separate Web Asset API — different host
		// (xchangeb2b.com/XCH/...), different auth scheme, no vendor-documented
		// numeric rate limit (their own doc's only guidance: "not meant to be
		// called on every page load," i.e. cache it — see
		// mmi-admin/docs/api-reference/xchange-api.md's Web Asset API
		// section). Confirmed live, 2026-09-18: reusing the main API's 12s
		// key made a 106-vendor pull take 20+ minutes and made it fragile
		// (any single vendor's slow/failed fetch could stall the whole
		// batch); the original vendor-supplied plugin this integration was
		// ported from ran the equivalent pull far faster. Given its own
		// backoff/penalize() safety net below, this key can be pushed lower
		// than 2000ms if confirmed safe against a real, sustained pull.
		'xchange_webassets' => array(
			'min_gap_ms'     => 2000,
			'backoff_ms'     => 5000,
			'max_backoff_ms' => 60000,
			'transient_ttl'  => 300,
		),
		// The actual image/binary downloads the Web Asset API's response
		// points at are hosted on each VENDOR's own website (e.g.
		// gforcesoftware.com), never on an XChange-owned host — they have
		// nothing to do with XChange's own rate limit and shouldn't compete
		// with real XChange API calls for the same throttle slot. A light
		// courtesy gap only, mainly so a vendor with many images in a row
		// doesn't get hit as a rapid-fire burst from one client.
		'xchange_asset_binary' => array(
			'min_gap_ms'     => 300,
			'backoff_ms'     => 3000,
			'max_backoff_ms' => 30000,
			'transient_ttl'  => 120,
		),
		'skuport'   => array(
			'min_gap_ms'     => 600,
			'backoff_ms'     => 5000,
			'max_backoff_ms' => 30000,
			'transient_ttl'  => 120,
		),
		'plugivery' => array(
			'min_gap_ms'     => 600,
			'backoff_ms'     => 5000,
			'max_backoff_ms' => 30000,
			'transient_ttl'  => 120,
		),
		'gmc'       => array(
			'min_gap_ms'     => 200,
			'backoff_ms'     => 5000,
			'max_backoff_ms' => 60000,
			'transient_ttl'  => 120,
		),
		// ShipStation API v1 (mmi-po labels/rates): documented 40 req/min per key
		// (docs/api/requirements) — halved to 20/min per AGENTS.md rate-limiting rule 4.
		'shipstation' => array(
			'min_gap_ms'     => 3000,
			'backoff_ms'     => 10000,
			'max_backoff_ms' => 60000,
			'transient_ttl'  => 120,
		),
		'anthropic' => array(
			'min_gap_ms'     => 1000,
			'backoff_ms'     => 5000,
			'max_backoff_ms' => 30000,
			'transient_ttl'  => 120,
		),
	);

	/* ── Transient key helpers ───────────────────────────────────────────── */

	private static function last_call_key( string $api ): string {
		return 'mmi_throttle_last_' . $api;
	}

	private static function penalty_key( string $api ): string {
		return 'mmi_throttle_penalty_' . $api;
	}

	/* ── Public API ──────────────────────────────────────────────────────── */

	public static function throttle( string $api, array $opts = array() ): void {
		$profile  = self::resolve_profile( $api, $opts );
		$last_key = self::last_call_key( $api );
		$pen_key  = self::penalty_key( $api );

		$last_ms    = (int) get_transient( $last_key );
		$penalty_ms = (int) get_transient( $pen_key );
		$min_gap_ms = $profile['min_gap_ms'] + $penalty_ms;

		$now_ms     = self::now_ms();
		$elapsed_ms = $last_ms > 0 ? ( $now_ms - $last_ms ) : PHP_INT_MAX;

		if ( $elapsed_ms < $min_gap_ms ) {
			$wait_ms       = $min_gap_ms - $elapsed_ms;
			$jitter_ms     = (int) ( $wait_ms * 0.10 * ( mt_rand( 0, 100 ) / 100 ) );
			$total_wait_ms = $wait_ms + $jitter_ms;

			MMI_Logger::debug(
				sprintf( '[Throttler] %s — waiting %d ms (gap=%d, elapsed=%d, penalty=%d)', $api, $total_wait_ms, $min_gap_ms, $elapsed_ms, $penalty_ms ),
				array(),
				'throttler',
				'MMI_API_Throttler'
			);

			usleep( $total_wait_ms * 1000 );
		}

		set_transient( $last_key, self::now_ms(), $profile['transient_ttl'] );
	}

	public static function penalize( string $api, int $retry_after_s = 0 ): void {
		$profile = self::resolve_profile( $api, array() );
		$pen_key = self::penalty_key( $api );

		$current_penalty_ms = (int) get_transient( $pen_key );

		if ( $retry_after_s > 0 ) {
			$new_penalty_ms = (int) ( $retry_after_s * 1100 );
		} else {
			$new_penalty_ms = $current_penalty_ms > 0
				? min( $current_penalty_ms * 2, $profile['max_backoff_ms'] )
				: $profile['backoff_ms'];
		}

		$ttl = max( (int) ceil( $new_penalty_ms / 1000 ) + 60, $profile['transient_ttl'] );
		set_transient( $pen_key, $new_penalty_ms, $ttl );

		MMI_Logger::warn(
			sprintf( '[Throttler] %s — penalized %d ms (retry_after=%ds)', $api, $new_penalty_ms, $retry_after_s ),
			array(),
			'throttler',
			'MMI_API_Throttler'
		);
	}

	public static function clear_penalty( string $api ): void {
		delete_transient( self::penalty_key( $api ) );
	}

	public static function wait_ms( string $api, array $opts = array() ): int {
		$profile = self::resolve_profile( $api, $opts );

		$last_ms = (int) get_transient( self::last_call_key( $api ) );
		if ( $last_ms <= 0 ) {
			return 0;
		}

		$min_gap_ms = $profile['min_gap_ms'] + (int) get_transient( self::penalty_key( $api ) );
		$elapsed_ms = self::now_ms() - $last_ms;

		return $elapsed_ms >= $min_gap_ms ? 0 : ( $min_gap_ms - $elapsed_ms );
	}

	public static function call( string $api, callable $fn, int $max_retries = 3 ) {
		$attempts = 0;

		while ( $attempts <= $max_retries ) {
			self::throttle( $api );
			$response = $fn();

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status = wp_remote_retrieve_response_code( $response );

			if ( $status === 429 ) {
				$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				self::penalize( $api, $retry_after );
				$attempts++;

				MMI_Logger::warn(
					sprintf( '[Throttler] %s — 429 received (attempt %d/%d)', $api, $attempts, $max_retries ),
					array(),
					'throttler',
					'MMI_API_Throttler'
				);
				continue;
			}

			if ( $status >= 200 && $status < 300 ) {
				self::clear_penalty( $api );
			}

			return $response;
		}

		return new \WP_Error(
			'rate_limit_exceeded',
			sprintf( 'Rate limit exceeded for %s after %d retries.', $api, $max_retries )
		);
	}

	/* ── Private helpers ─────────────────────────────────────────────────── */

	private static function now_ms(): int {
		return (int) round( microtime( true ) * 1000 );
	}

	private static function resolve_profile( string $api, array $opts ): array {
		$defaults = self::KNOWN_APIS[ $api ] ?? array(
			'min_gap_ms'     => 1000,
			'backoff_ms'     => 5000,
			'max_backoff_ms' => 30000,
			'transient_ttl'  => 120,
		);

		return array_merge( $defaults, $opts );
	}
}
