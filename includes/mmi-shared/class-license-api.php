<?php
/**
 * MMI License API (bundled/vendored copy) — ADR-0008, mmi-admin/docs/decisions/.
 *
 * Identical to the original mmi-hub/includes/licensing/class-license-api.php.
 * Only ever loaded once per request, by whichever plugin's bundled copy wins
 * version negotiation in bootstrap.php — see ADR-0006. Do not hand-edit this
 * file in a single plugin; edit the canonical source (mmi-admin/lib/mmi-shared/)
 * and re-sync (sync-to-plugins.sh) to every plugin that bundles it.
 *
 * MMI License API — Unified license operation router
 *
 * Provides a single static interface for all license operations throughout
 * the MMI plugin suite.  Routes transparently to the correct backend:
 *
 *   • License server (mannmade.us, where MMI_License_Manager is installed):
 *     — Calls MMI_License_Manager directly — no HTTP overhead.
 *
 *   • Customer site (any other WordPress install):
 *     — Calls MMI_License_Client which POSTs to mannmade.us via wp_remote_post().
 *
 * The normalise() helper bridges the two response shapes so every caller
 * (class-license-hub.php, cron, etc.) always gets a consistent array with
 * a `license` stdClass object and a `days_remaining` integer.
 *
 * Usage anywhere in the plugin suite:
 *   $result = MMI_License_API::activate( $key, 'mmi-suite' );
 *   $result = MMI_License_API::validate( $key, 'mmi-suite' );
 *   MMI_License_API::deactivate( $key, 'mmi-suite' );
 *   $result = MMI_License_API::start_trial( $email );
 *
 * @package MannMade\Hub\Licensing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_License_API {

    /* ── Backend detection ──────────────────────────────────────────────── */

    /**
     * Returns true when running on the license server itself
     * (MMI_License_Manager is installed).
     */
    public static function is_server(): bool {
        return class_exists( 'MMI_License_Manager' );
    }

    /* ── License operations ─────────────────────────────────────────────── */

    /**
     * Activate a license key for the current site.
     *
     * @param  string      $key
     * @param  string      $slug    Plugin slug, e.g. 'mmi-suite'.
     * @param  string|null $domain  Defaults to get_site_url().
     * @return array{valid:bool,license?:object,days_remaining?:int,error?:string}
     */
    public static function activate( string $key, string $slug, ?string $domain = null ): array {
        $domain = $domain ?: get_site_url();

        if ( self::is_server() ) {
            return MMI_License_Manager::instance()->activate_license( $key, $slug, $domain );
        }

        return MMI_License_Client::instance()->activate( $key, $slug, $domain );
    }

    /**
     * Validate (check-in) an existing license.
     *
     * On a customer site this updates the last-validated timestamp on the
     * server; the local cache is refreshed on success.
     *
     * @param  string      $key
     * @param  string      $slug
     * @param  string|null $domain
     * @return array{valid:bool,license?:object,days_remaining?:int,error?:string}
     */
    public static function validate( string $key, string $slug, ?string $domain = null ): array {
        $domain = $domain ?: get_site_url();

        if ( self::is_server() ) {
            return MMI_License_Manager::instance()->validate_license( $key, $slug, $domain );
        }

        return MMI_License_Client::instance()->validate( $key, $slug, $domain );
    }

    /**
     * Deactivate a license for the current domain.
     *
     * Always clears the local MMI_Settings cache.
     *
     * @param  string      $key
     * @param  string      $slug
     * @param  string|null $domain
     * @return array{valid:bool,deactivated:bool,error?:string}
     */
    public static function deactivate( string $key, string $slug, ?string $domain = null ): array {
        $domain = $domain ?: get_site_url();

        if ( self::is_server() ) {
            MMI_License_Manager::instance()->deactivate_license( $key, $slug, $domain );
            return [ 'valid' => true, 'deactivated' => true ];
        }

        return MMI_License_Client::instance()->deactivate( $key, $slug, $domain );
    }

    /**
     * Start a 14-day free trial for a plugin or bundle scope.
     *
     * On the license server: creates and activates the trial license directly
     * through MMI_License_Manager::create_trial_license().
     * On a customer site: POSTs to mannmade.us/wp-json/mmi/v1/license/trial
     * then activates the returned key.
     *
     * @param  string      $email
     * @param  string      $scope   Plugin slug or bundle id being trialed. Defaults
     *                              to 'mmi-suite' for backward compatibility with
     *                              existing callers that don't yet pass one.
     * @param  string|null $domain
     * @return array{success:bool,license_key?:string,message?:string,license?:object,error?:string}
     */
    public static function start_trial( string $email, string $scope = 'mmi-suite', ?string $domain = null ): array {
        $domain = $domain ?: get_site_url();

        if ( self::is_server() ) {
            return self::start_trial_local( $email, $scope, $domain );
        }

        return MMI_License_Client::instance()->start_trial( $email, $scope, $domain );
    }

    /* ── Local trial creation (license server only) ─────────────────────── */

    private static function start_trial_local( string $email, string $scope, string $domain ): array {
        if ( ! class_exists( 'MMI_License_Manager' ) ) {
            return [ 'success' => false, 'error' => 'License Manager unavailable.' ];
        }

        $manager = MMI_License_Manager::instance();
        $created = $manager->create_trial_license( $scope, $email, $domain );

        if ( ! $created['success'] ) {
            return $created;
        }

        $license_key = $created['license_key'];
        $result      = $manager->activate_license( $license_key, $scope, $domain );

        return [
            'success'        => (bool) ( $result['valid'] ?? false ),
            'license_key'    => $license_key,
            'license_type'   => 'trial',
            'is_trial'       => true,
            'trial_days'     => 14,
            'expiry_date'    => $result['license']->expiry_date  ?? null,
            'trial_ends_at'  => $result['license']->trial_ends_at ?? null,
            'error'          => $result['error'] ?? null,
            'license'        => $result['license'] ?? null,
            'days_remaining' => $result['days_remaining'] ?? 14,
        ];
    }

    /* ── Response normalisation ─────────────────────────────────────────── */

    /**
     * Normalise an activate/validate response so callers like
     * MMI_License_Hub::build_minimal_status_html() can always be called
     * without knowing whether the response came from the local manager or
     * the remote client.
     *
     * MMI_License_Manager returns a `license` DB row object plus `days_remaining`.
     * MMI_License_Client returns flat scalar keys (`license_type`, `expiry_date`, …).
     *
     * After this call the array is guaranteed to have:
     *   - `license`       stdClass  with all fields the caller needs
     *   - `days_remaining` int|null
     *
     * @param  array $response
     * @return array
     */
    public static function normalise( array $response ): array {
        if ( ! isset( $response['license'] ) ) {
            $lic                      = new stdClass();
            $lic->license_key         = $response['license_key'] ?? '';
            $lic->license_type        = $response['license_type'] ?? '';
            $lic->status              = $response['status'] ?? 'active';
            $lic->expiry_date         = $response['expiry_date'] ?? null;
            $lic->is_trial            = ! empty( $response['is_trial'] );
            $lic->trial_ends_at       = $response['trial_ends_at'] ?? null;
            $lic->current_activations = (int) ( $response['current_activations'] ?? 1 );
            $lic->max_activations     = (int) ( $response['max_activations'] ?? 1 );
            $lic->features            = $response['features'] ?? [];
            $response['license']      = $lic;
        }

        if ( ! isset( $response['days_remaining'] ) ) {
            $expiry = $response['license']->expiry_date ?? null;
            $response['days_remaining'] = $expiry
                ? max( 0, (int) ceil( ( strtotime( $expiry ) - time() ) / DAY_IN_SECONDS ) )
                : null;
        }

        return $response;
    }
}
