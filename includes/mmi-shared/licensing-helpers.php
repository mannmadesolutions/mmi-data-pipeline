<?php
/**
 * MMI License Helpers — Global Functions (bundled/vendored copy) — ADR-0008,
 * mmi-admin/docs/decisions/.
 *
 * Identical logic to the original mmi-hub/includes/licensing/class-license-helpers.php.
 * Unlike the classes in this directory, these are plain global functions —
 * PHP has no autoloading mechanism for functions, so this file is `require_once`'d
 * EAGERLY from bootstrap.php (every function wrapped in `function_exists()`, the
 * same guard pattern bootstrap.php's own registry functions already use) rather
 * than lazily resolved through the version-negotiated class-map registry.
 * Consequence: whichever plugin's bootstrap.php happens to `require_once` this
 * file FIRST in a given request "wins" — there is no per-function version
 * negotiation the way there is for MMI_Settings/MMI_DB/etc. This is an accepted
 * limitation (ADR-0008's Consequences), not an oversight: these functions are
 * thin, stable wrappers around MMI_Settings::get() (which IS version-negotiated
 * correctly), so a version-skew mismatch here has much lower blast radius than
 * the same problem would for a real stateful class.
 *
 * Do not hand-edit this file in a single plugin; edit the canonical source
 * (mmi-admin/lib/mmi-shared/) and re-sync (sync-to-plugins.sh) to every plugin
 * that bundles it.
 *
 * Globally-callable helper functions so individual plugins can check their
 * license status without directly coupling to MMI_License_Manager internals.
 *
 * There is no feature-tier system anymore — a license is either valid for a
 * given plugin or it isn't. Every license (single-plugin or bundle) carries
 * only a max_activations count; resolution here is purely "does this site
 * hold a currently-active, non-expired license (direct or via a bundle) that
 * covers this plugin slug."
 *
 * A bundle's coverage is cached client-side under 'mmi_license_' . $scope's
 * 'covered_plugins' key (populated by MMI_License_Client::persist() /
 * MMI_License_Manager::activate_license() at activation time), and every
 * scope this site has activated is tracked in 'mmi_license_scopes' so a
 * bundle covering a DIFFERENT plugin slug than the one it was activated
 * under can still be discovered.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

/**
 * Days a paid license keeps working after its expiry_date, so a customer
 * has time to renew before anything stops. Shared by the license server
 * (validate_license(), MMI_License_Expiry_Handler) and every customer site's
 * local gate, so both sides always agree. Trials get no grace.
 */
if ( ! defined( 'MMI_LICENSE_GRACE_DAYS' ) ) {
	define( 'MMI_LICENSE_GRACE_DAYS', 14 );
}

/**
 * Until when a cached license row without a signature still counts (ADR-0012).
 * Signing shipped 2026-09-26. The only installs that existed then
 * (mannmade.us, studiomann.com) were re-signed and verified the same day, so
 * the transition was closed immediately: unsigned rows no longer count. Kept
 * as a constant for a future key or claim-format change that needs one.
 */
if ( ! defined( 'MMI_LICENSE_UNSIGNED_UNTIL' ) ) {
	define( 'MMI_LICENSE_UNSIGNED_UNTIL', '2026-09-26 00:00:00' );
}

if ( ! function_exists( 'mmi_license_grace_ends_at' ) ) {
	/**
	 * Unix time a license with this expiry_date stops working, or null for
	 * a license that never expires.
	 */
	function mmi_license_grace_ends_at( ?string $expiry_date ): ?int {
		if ( empty( $expiry_date ) ) {
			return null;
		}
		return (int) strtotime( $expiry_date ) + MMI_LICENSE_GRACE_DAYS * DAY_IN_SECONDS;
	}
}

if ( ! function_exists( 'mmi_license_normalize_host' ) ) {
	/**
	 * "https://www.Site.com/path" → "site.com". The license server and every
	 * site compare hosts in this form, so a claim signed for one spelling of
	 * a domain verifies on the same site however its URL is written.
	 */
	function mmi_license_normalize_host( string $domain ): string {
		$domain = trim( $domain );
		if ( $domain === '' ) {
			return '';
		}
		$with_scheme = preg_match( '#^[a-z][a-z0-9+.-]*://#i', $domain ) ? $domain : 'https://' . $domain;
		$host        = strtolower( (string) ( parse_url( $with_scheme, PHP_URL_HOST ) ?: $domain ) );
		return preg_replace( '/^www\./', '', $host );
	}
}

if ( ! function_exists( 'mmi_license_public_keys' ) ) {
	/**
	 * License-signing public keys, kid => base64 Ed25519 public key
	 * (ADR-0012). The private half lives only in mannmade.us's wp-config
	 * (MMI_LICENSE_SIGNING_KEY). To rotate: ship the new key here alongside
	 * the old one, switch the server, then drop the old key a release later.
	 *
	 * @return array<string,string>
	 */
	function mmi_license_public_keys(): array {
		return array(
			'9bcbdaa4' => 'LKEvQIJhVaW9ljc8XAcuFFo/mt7Yzwqqq/P6jriegvc=',
		);
	}
}

if ( ! function_exists( 'mmi_license_verified_claim' ) ) {
	/**
	 * The signed claim inside a cached license row, if its signature is
	 * valid and it belongs to this row (same key, same scope) and this site
	 * (same host). Null otherwise — including for an unsigned row.
	 *
	 * @param  array  $row    Cached `mmi_license_{scope}` row.
	 * @param  string $scope
	 * @return array|null
	 */
	function mmi_license_verified_claim( array $row, string $scope ): ?array {
		if ( empty( $row['claim'] ) || empty( $row['sig'] ) || ! is_string( $row['claim'] ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return null;
		}
		$claim = json_decode( $row['claim'], true );
		if ( ! is_array( $claim ) ) {
			return null;
		}
		$keys = mmi_license_public_keys();
		$pk   = isset( $claim['kid'] ) ? base64_decode( (string) ( $keys[ $claim['kid'] ] ?? '' ), true ) : false;
		$sig  = base64_decode( (string) $row['sig'], true );
		if ( ! $pk || ! $sig || strlen( $pk ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
			return null;
		}
		try {
			if ( ! sodium_crypto_sign_verify_detached( $sig, $row['claim'], $pk ) ) {
				return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ( $claim['key'] ?? '' ) !== ( $row['key'] ?? null )
			|| ( $claim['scope'] ?? '' ) !== $scope
			|| ( $claim['host'] ?? '' ) !== mmi_license_normalize_host( get_site_url() ) ) {
			return null;
		}
		return $claim;
	}
}

if ( ! function_exists( 'mmi_license_trusted_row' ) ) {
	/**
	 * The cached license row for $scope, as far as it can be trusted — the
	 * one source every gate decision below reads from (ADR-0012).
	 *
	 * Signed and valid: the row, with every field the gate relies on taken
	 * from the signed claim, so editing the plain fields changes nothing.
	 *
	 * Unsigned: rows cached before signing shipped. Accepted until
	 * MMI_LICENSE_UNSIGNED_UNTIL so no site loses its plugins on update, and
	 * each sighting queues a background re-check that replaces it with a
	 * signed row. After that date, rejected.
	 *
	 * Signed but invalid (tampered, other domain, unknown key): rejected,
	 * plus the same background re-check (a changed domain resolves itself).
	 *
	 * @param  string $scope
	 * @return array|null
	 */
	function mmi_license_trusted_row( string $scope ): ?array {
		if ( ! class_exists( 'MMI_Settings' ) ) {
			return null;
		}
		$row = MMI_Settings::get( 'mmi_license_' . $scope );
		if ( ! is_array( $row ) || empty( $row['key'] ) ) {
			return null;
		}

		$claim = mmi_license_verified_claim( $row, $scope );
		if ( $claim !== null ) {
			return array_merge( $row, array(
				'status'          => $claim['status'] ?? '',
				'expiry_date'     => $claim['expiry_date'] ?? null,
				'is_trial'        => ! empty( $claim['is_trial'] ),
				'trial_ends_at'   => $claim['trial_ends_at'] ?? null,
				'covered_plugins' => (array) ( $claim['covered'] ?? array( $scope ) ),
			) );
		}

		mmi_license_queue_recheck();

		if ( empty( $row['claim'] ) && time() < strtotime( MMI_LICENSE_UNSIGNED_UNTIL ) ) {
			return $row;
		}
		return null;
	}
}

if ( ! function_exists( 'mmi_license_queue_recheck' ) ) {
	/**
	 * Queue one background re-validation — at most once an hour per site,
	 * never an inline HTTP call from a gate check (see the 2026-09-23
	 * self-request outage). The hourly cap matters: a row that can never
	 * verify would otherwise re-queue on every request, on every site.
	 */
	function mmi_license_queue_recheck(): void {
		static $queued = false;
		if ( $queued || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		$queued = true;
		if ( get_transient( 'mmi_license_recheck_queued' ) ) {
			return;
		}
		set_transient( 'mmi_license_recheck_queued', 1, HOUR_IN_SECONDS );
		if ( ! wp_next_scheduled( 'mmi_shared_license_revalidate_now' ) ) {
			wp_schedule_single_event( time() + 30, 'mmi_shared_license_revalidate_now' );
		}
	}
}

if ( ! function_exists( 'mmi_license_scope_is_active' ) ) {
	/**
	 * Is the locally cached license for $scope currently valid (active status,
	 * not past its expiry + MMI_LICENSE_GRACE_DAYS grace, trial not expired)?
	 *
	 * @param  string $scope  Plugin slug or bundle id.
	 * @return bool
	 */
	function mmi_license_scope_is_active( string $scope ): bool {
		$data = mmi_license_trusted_row( $scope );
		if ( empty( $data['key'] ) || empty( $data['status'] ) || $data['status'] !== 'active' ) {
			return false;
		}

		// Grace period: stay valid past expiry so features don't hard-cut.
		$grace_end = mmi_license_grace_ends_at( $data['expiry_date'] ?? null );
		if ( $grace_end !== null && time() > $grace_end ) {
			return false;
		}

		if ( ! empty( $data['trial_ends_at'] ) && time() > strtotime( $data['trial_ends_at'] ) ) {
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'mmi_is_licensed' ) ) {
	/**
	 * Returns true if the current site has an active license (direct, or via a
	 * bundle covering it) for the given plugin.
	 *
	 * @param  string $plugin_slug  Plugin slug.
	 * @return bool
	 */
	function mmi_is_licensed( string $plugin_slug ): bool {
		if ( ! class_exists( 'MMI_Settings' ) ) {
			return false;
		}

		// 1. A license activated directly under this plugin's own slug.
		if ( mmi_license_scope_is_active( $plugin_slug ) ) {
			return true;
		}

		// 2. Any other activated scope on this site (a bundle) that covers it.
		$scopes = (array) MMI_Settings::get( 'mmi_license_scopes', [] );
		foreach ( $scopes as $scope ) {
			if ( $scope === $plugin_slug || ! mmi_license_scope_is_active( $scope ) ) {
				continue;
			}
			$data    = mmi_license_trusted_row( $scope );
			$covered = $data['covered_plugins'] ?? [ $scope ];
			if ( in_array( '*', $covered, true ) || in_array( $plugin_slug, $covered, true ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'mmi_is_trial_active' ) ) {
	/**
	 * Returns true if the currently-active license for $scope is a trial.
	 *
	 * @param  string $scope  Plugin slug or bundle id. Defaults to 'mmi-suite'
	 *                         for backward compatibility with existing callers.
	 * @return bool
	 */
	function mmi_is_trial_active( string $scope = 'mmi-suite' ): bool {
		if ( ! class_exists( 'MMI_Settings' ) ) {
			return false;
		}
		$data = mmi_license_trusted_row( $scope );
		if ( empty( $data['is_trial'] ) ) {
			return false;
		}
		if ( ! empty( $data['trial_ends_at'] ) && time() > strtotime( $data['trial_ends_at'] ) ) {
			return false;
		}
		return (bool) $data['is_trial'];
	}
}

if ( ! function_exists( 'mmi_is_licensed_or_trialing' ) ) {
	/**
	 * The gate check every plugin should use instead of bare mmi_is_licensed().
	 *
	 * Adds automatic trial mode on top of the existing license check: if the
	 * site has no active license for $plugin_slug, this makes (at most once
	 * per day — see MMI_License_Client::start_automatic_trial()'s own local
	 * cache) a silent check-in call to the license server to start or resume
	 * this domain's 14-day trial for that plugin, no email or button click
	 * required. The server is the single source of truth for whether a given
	 * domain has already used its trial (keyed by domain hash), so
	 * reinstalling the plugin never grants a second trial — see
	 * MMI_License_Manager::get_or_start_trial() on the server side.
	 *
	 * A successful check-in persists exactly the same 'mmi_license_' . $slug
	 * shape a real activation would (MMI_License_Client::persist()), so every
	 * other existing helper here (mmi_is_licensed(), mmi_is_trial_active(),
	 * mmi_trial_days_remaining()) keeps working completely unchanged.
	 *
	 * @param  string $plugin_slug
	 * @return bool
	 */
	function mmi_is_licensed_or_trialing( string $plugin_slug ): bool {
		if ( mmi_is_licensed( $plugin_slug ) ) {
			return true;
		}

		if ( class_exists( 'MMI_License_Client' ) ) {
			MMI_License_Client::instance()->start_automatic_trial( $plugin_slug );
		}

		// Re-check: a successful check-in above just persisted a fresh
		// 'mmi_license_' . $plugin_slug entry that this now picks up.
		return mmi_is_licensed( $plugin_slug );
	}
}

if ( ! function_exists( 'mmi_trial_days_remaining' ) ) {
	/**
	 * Returns the number of days remaining in $scope's trial, or null if no
	 * trial is active for it.
	 *
	 * @param  string $scope  Plugin slug or bundle id. Defaults to 'mmi-suite'.
	 * @return int|null
	 */
	function mmi_trial_days_remaining( string $scope = 'mmi-suite' ): ?int {
		if ( ! mmi_is_trial_active( $scope ) ) {
			return null;
		}
		$data = MMI_Settings::get( 'mmi_license_' . $scope );
		if ( empty( $data['trial_ends_at'] ) ) {
			return null;
		}
		$remaining = (int) ceil( ( strtotime( $data['trial_ends_at'] ) - time() ) / DAY_IN_SECONDS );
		return max( 0, $remaining );
	}
}

if ( ! function_exists( 'mmi_license_covering_scope' ) ) {
	/**
	 * Which cached, currently-active license scope covers $plugin_slug —
	 * its own slug, or a bundle id like 'mmi-suite' — or null if none.
	 * Paid licenses win over trials; $paid_only skips trials entirely.
	 *
	 * @param  string $plugin_slug
	 * @param  bool   $paid_only
	 * @return string|null
	 */
	function mmi_license_covering_scope( string $plugin_slug, bool $paid_only = false ): ?string {
		if ( ! class_exists( 'MMI_Settings' ) ) {
			return null;
		}
		$scopes      = array_unique( array_merge( [ $plugin_slug ], (array) MMI_Settings::get( 'mmi_license_scopes', [] ) ) );
		$trial_scope = null;
		foreach ( $scopes as $scope ) {
			if ( ! mmi_license_scope_is_active( $scope ) ) {
				continue;
			}
			$data    = mmi_license_trusted_row( $scope );
			$covered = $data['covered_plugins'] ?? [ $scope ];
			if ( $scope !== $plugin_slug && ! in_array( '*', $covered, true ) && ! in_array( $plugin_slug, $covered, true ) ) {
				continue;
			}
			if ( empty( $data['is_trial'] ) ) {
				return $scope;
			}
			$trial_scope = $trial_scope ?? $scope;
		}
		return $paid_only ? null : $trial_scope;
	}
}

if ( ! function_exists( 'mmi_has_paid_license' ) ) {
	/**
	 * Does an active, NON-trial license (direct or via a bundle) cover
	 * $plugin_slug? A plugin's own automatic trial entry can outlive the
	 * moment a real key is activated under a different scope (a suite key
	 * activated from another plugin's panel) — trial UI must key off this,
	 * not off mmi_is_trial_active() alone, or it keeps showing a countdown
	 * on a fully licensed site.
	 *
	 * @param  string $plugin_slug
	 * @return bool
	 */
	function mmi_has_paid_license( string $plugin_slug ): bool {
		return null !== mmi_license_covering_scope( $plugin_slug, true );
	}
}

if ( ! function_exists( 'mmi_license_revalidate_all' ) ) {
	/**
	 * Re-check every license this site has cached against the license
	 * server's wp_mmi_licenses table — the source of truth — so a key that
	 * was deleted, suspended, revoked, or expired there stops unlocking
	 * plugins here. Covers every cached scope (per-plugin keys and trials,
	 * not just 'mmi-suite', which is all the old daily check looked at).
	 *
	 * On the license server itself this is a local DB read
	 * (MMI_License_Manager::sync_local_license_cache()) — no HTTP, no
	 * activation-log noise, no domain/capacity re-check. On a customer site
	 * it's MMI_License_Client::validate(), which re-persists a still-valid
	 * key and clears one the server explicitly rejects; a network failure
	 * leaves the cache alone (the existing grace-period rules apply).
	 *
	 * @return array<string,string>  scope => 'valid'|'invalid'|'removed'|'network_error'|'<status>'
	 */
	function mmi_license_revalidate_all(): array {
		if ( ! class_exists( 'MMI_Settings' ) ) {
			return [];
		}

		$results = [];
		$scopes  = (array) MMI_Settings::get( 'mmi_license_scopes', [] );

		foreach ( $scopes as $scope ) {
			$data = MMI_Settings::get( 'mmi_license_' . $scope );
			$key  = is_array( $data ) ? (string) ( $data['key'] ?? '' ) : '';

			if ( $key === '' ) {
				// A scope with no cached entry behind it — drop the dangling pointer.
				MMI_Settings::set( 'mmi_license_scopes', array_values( array_diff( (array) MMI_Settings::get( 'mmi_license_scopes', [] ), [ $scope ] ) ) );
				$results[ $scope ] = 'removed';
				continue;
			}

			if ( class_exists( 'MMI_License_Manager' ) ) {
				MMI_License_Manager::instance()->sync_local_license_cache( $key );
				$after             = MMI_Settings::get( 'mmi_license_' . $scope );
				$results[ $scope ] = is_array( $after ) ? (string) ( $after['status'] ?? 'valid' ) : 'removed';
				continue;
			}

			if ( ! class_exists( 'MMI_License_Client' ) ) {
				continue;
			}

			$response = MMI_License_Client::instance()->validate( $key, $scope );
			if ( ! empty( $response['network_error'] ) ) {
				$results[ $scope ] = 'network_error';
			} else {
				$results[ $scope ] = empty( $response['valid'] ) ? 'invalid' : 'valid';
			}
		}

		do_action( 'mmi_license_revalidated', $results );

		return $results;
	}
}

if ( ! function_exists( 'mmi_license_maybe_revalidate' ) ) {
	/**
	 * Throttled (once an hour per site) re-check for screens that show
	 * license state — the License panel and header badge — so a staff
	 * change on the license server shows up within the hour instead of
	 * waiting for the daily cron. On the license server the check is a
	 * cheap local DB read and runs inline; on a customer site the HTTP
	 * round-trip is handed to a one-off background cron event rather than
	 * blocking the page render.
	 */
	function mmi_license_maybe_revalidate(): void {
		$throttle_key = 'mmi_license_revalidated_recently';
		if ( get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, HOUR_IN_SECONDS );

		if ( class_exists( 'MMI_License_Manager' ) ) {
			mmi_license_revalidate_all();
			return;
		}

		if ( ! wp_next_scheduled( 'mmi_shared_license_revalidate_now' ) ) {
			wp_schedule_single_event( time(), 'mmi_shared_license_revalidate_now' );
		}
	}
}
