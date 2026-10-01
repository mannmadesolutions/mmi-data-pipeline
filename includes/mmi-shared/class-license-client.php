<?php
/**
 * MMI License Client (bundled/vendored copy) — ADR-0008, mmi-admin/docs/decisions/.
 *
 * Identical to the original mmi-hub/includes/licensing/class-license-client.php.
 * Only ever loaded once per request, by whichever plugin's bundled copy wins
 * version negotiation in bootstrap.php — see ADR-0006. Do not hand-edit this
 * file in a single plugin; edit the canonical source (mmi-admin/lib/mmi-shared/)
 * and re-sync (sync-to-plugins.sh) to every plugin that bundles it.
 *
 * MMI License Client — Remote License Server HTTP Client
 *
 * Makes wp_remote_post() calls to the MannMade licensing server
 * (mannmade.us) to activate, validate, and deactivate license keys
 * on behalf of a customer's WordPress installation.
 *
 * Used on customer sites where MMI_License_Manager is not present.
 * Caches successful validation responses in MMI_Settings so features
 * remain available during temporary server unavailability (up to 7-day
 * grace period).
 *
 * @package MannMade\Hub\Licensing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_License_Client {

    /* ── Configuration ──────────────────────────────────────────────────── */

    /**
     * License server base URL.
     * Override at runtime with the 'mmi_license_server_url' filter.
     */
    const SERVER_URL = 'https://mannmade.us';

    /** REST namespace on the license server. */
    const REST_BASE = '/wp-json/mmi/v1';

    /** HTTP timeout for license requests (seconds). */
    const TIMEOUT = 15;

    /** Transient key prefix for consecutive remote-failure counting. */
    const FAILURE_TRANSIENT = 'mmi_lic_failures_';

    /** Maximum consecutive failures before the grace-period cache is ignored. */
    const MAX_FAILURES = 5;

    /* ── Singleton ──────────────────────────────────────────────────────── */

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /* ── Public API ─────────────────────────────────────────────────────── */

    /**
     * Activate a license key on this site.
     *
     * POSTs to /wp-json/mmi/v1/license/activate.
     * On success, persists the result to MMI_Settings and resets the
     * failure counter.
     *
     * @param  string      $license_key
     * @param  string      $plugin_slug
     * @param  string|null $domain  Defaults to get_site_url().
     * @return array{valid:bool,license_type?:string,expiry_date?:string,error?:string}
     */
    public function activate( string $license_key, string $plugin_slug, ?string $domain = null ): array {
        $domain = $domain ?: get_site_url();

        $response = $this->post( '/license/activate', [
            'license_key'    => $license_key,
            'plugin_slug'    => $plugin_slug,
            'domain'         => $domain,
            'php_version'    => PHP_VERSION,
            'wp_version'     => get_bloginfo( 'version' ),
            'plugin_version' => defined( 'MMI_HUB_VERSION' ) ? MMI_HUB_VERSION : '',
        ] );

        if ( $response['network_error'] ?? false ) {
            return [ 'valid' => false, 'error' => $response['error'], 'network_error' => true ];
        }

        if ( ! empty( $response['valid'] ) ) {
            /* Inject the key so MMI_License_API::normalise() can always build the panel. */
            $response['license_key'] = $license_key;
            $this->persist( $plugin_slug, $license_key, $response, $domain );
            $this->reset_failures( $plugin_slug );
        }

        return $response;
    }

    /**
     * Validate (check-in) a previously activated license.
     *
     * POSTs to /wp-json/mmi/v1/license/validate.
     * On network failure increments the failure counter and returns
     * the locally-cached response if still within the grace window.
     * If the server explicitly invalidates the license, clears the cache.
     *
     * @param  string      $license_key
     * @param  string      $plugin_slug
     * @param  string|null $domain
     * @return array{valid:bool,...}
     */
    public function validate( string $license_key, string $plugin_slug, ?string $domain = null ): array {
        $domain = $domain ?: get_site_url();

        $response = $this->post( '/license/validate', [
            'license_key'    => $license_key,
            'plugin_slug'    => $plugin_slug,
            'domain'         => $domain,
            'plugin_version' => defined( 'MMI_HUB_VERSION' ) ? MMI_HUB_VERSION : '',
        ] );

        if ( $response['network_error'] ?? false ) {
            $this->increment_failures( $plugin_slug );
            return $this->grace_period_fallback( $plugin_slug, $license_key, $domain, $response['error'] );
        }

        if ( ! empty( $response['valid'] ) ) {
            $response['license_key'] = $license_key;
            $this->persist( $plugin_slug, $license_key, $response, $domain );
            $this->reset_failures( $plugin_slug );
        } else {
            /* Server explicitly said invalid — purge local cache. */
            $this->clear( $plugin_slug );
        }

        return $response;
    }

    /**
     * Deactivate a license from this site.
     *
     * Always clears the local MMI_Settings cache regardless of server response
     * because the user explicitly requested removal.
     *
     * @param  string      $license_key
     * @param  string      $plugin_slug
     * @param  string|null $domain
     * @return array{valid:bool,deactivated:bool,error?:string}
     */
    public function deactivate( string $license_key, string $plugin_slug, ?string $domain = null ): array {
        $domain = $domain ?: get_site_url();

        $response = $this->post( '/license/deactivate', [
            'license_key' => $license_key,
            'plugin_slug' => $plugin_slug,
            'domain'      => $domain,
        ] );

        /* Always clear locally even if the remote call fails. */
        $this->clear( $plugin_slug );
        $this->reset_failures( $plugin_slug );

        return $response;
    }

    /**
     * Start a free trial for a plugin or bundle scope.
     *
     * POSTs to /wp-json/mmi/v1/license/trial to create the trial key on
     * the server, then immediately activates it on this site.
     *
     * @param  string      $email
     * @param  string      $scope   Plugin slug or bundle id being trialed.
     * @param  string|null $domain
     * @return array{success:bool,license_key?:string,message?:string,error?:string}
     */
    public function start_trial( string $email, string $scope = 'mmi-suite', ?string $domain = null ): array {
        $domain = $domain ?: get_site_url();

        $response = $this->post( '/license/trial', [
            'email'       => $email,
            'domain'      => $domain,
            'plugin_slug' => $scope,
        ] );

        if ( $response['network_error'] ?? false ) {
            return [ 'success' => false, 'error' => $response['error'] ];
        }

        if ( empty( $response['success'] ) ) {
            return [
                'success' => false,
                'error'   => $response['error'] ?? __( 'Trial could not be started. Please try again.', 'mmi-hub' ),
            ];
        }

        /* Activate the returned key for this domain. */
        $key    = $response['license_key'] ?? '';
        $result = $this->activate( $key, $scope, $domain );

        if ( empty( $result['valid'] ) ) {
            /* Key created but activation failed — return key for manual entry. */
            return [
                'success'     => true,
                'license_key' => $key,
                'manual'      => true,
                'message'     => sprintf(
                    /* translators: %s: license key */
                    __( 'Trial started! Your key is %s. Enter it in the activation form above.', 'mmi-hub' ),
                    $key
                ),
            ];
        }

        return array_merge( $result, [
            'success'    => true,
            'license_key'=> $key,
            'is_trial'   => true,
            'trial_days' => 14,
            'message'    => __( 'Your 14-day free trial is now active!', 'mmi-hub' ),
        ] );
    }

    /**
     * Silently start (or resume) this domain's automatic 14-day trial for a
     * plugin, with no email address and no key ever shown to the customer.
     *
     * Called by mmi_is_licensed_or_trialing() (licensing-helpers.php), never
     * by a button — trial mode is the plugin's default state, not something
     * the customer opts into. Caches the last check-in time locally
     * (MMI_Settings, 'mmi_trial_checkin_' . $plugin_slug) so this only calls
     * out to the license server about once a day per plugin, never on every
     * page load, per this project's Server Load rules — the local
     * mmi_license_{slug} cache this writes on success is what every read
     * (mmi_is_licensed(), the countdown UI) actually uses in between.
     *
     * Idempotent on the server side: MMI_License_Manager::get_or_start_trial()
     * looks up an existing trial by domain hash before creating one, so
     * calling this again (a fresh install, a cleared local cache) always
     * resumes the same clock rather than resetting it — the server, not this
     * site, is the one thing that can't be reset by deleting local state.
     *
     * @param  string $plugin_slug
     * @return array{success:bool,valid?:bool,network_error?:bool,error?:string}
     */
    public function start_automatic_trial( string $plugin_slug ): array {
        $cache_key = 'mmi_trial_checkin_' . $plugin_slug;

        if ( class_exists( 'MMI_Settings' ) ) {
            $last_checkin = (int) MMI_Settings::get( $cache_key, 0 );
            if ( $last_checkin && ( time() - $last_checkin ) < DAY_IN_SECONDS ) {
                return [ 'success' => true, 'cached' => true ];
            }
        }

        // A failed check-in backs off instead of retrying on the very next
        // request. Every MMI plugin runs this from its boot gate, so an
        // unreachable license server otherwise meant one blocking HTTP call
        // per plugin per page load.
        $backoff_key = 'mmi_trial_checkin_backoff_' . $plugin_slug;
        if ( get_transient( $backoff_key ) ) {
            return [ 'success' => false, 'network_error' => true, 'cached' => true ];
        }

        $domain = get_site_url();

        // On the license server itself, ask the engine directly. Going over
        // HTTP here meant every plugin boot sent a REST request back to this
        // same site while holding a PHP-FPM worker — with no local license
        // cached, that self-request storm exhausted the pool and took
        // mannmade.us down (2026-09-23).
        if ( class_exists( 'MMI_License_Manager' ) ) {
            $response = MMI_License_Manager::instance()->get_or_start_trial( $plugin_slug, $domain );
        } else {
            $response = $this->post( '/license/trial-check-in', [
                'plugin_slug' => $plugin_slug,
                'domain'      => $domain,
            ] );
        }

        if ( $response['network_error'] ?? false ) {
            // Leave whatever local trial state already exists untouched;
            // try again after the backoff window.
            set_transient( $backoff_key, 1, 15 * MINUTE_IN_SECONDS );
            return [ 'success' => false, 'network_error' => true, 'error' => $response['error'] ?? '' ];
        }

        if ( empty( $response['success'] ) ) {
            // Rate-limited or rejected — same backoff as a network failure.
            set_transient( $backoff_key, 1, 15 * MINUTE_IN_SECONDS );
            return [ 'success' => false, 'error' => $response['error'] ?? __( 'Could not verify trial status.', 'mmi-hub' ) ];
        }

        if ( ! empty( $response['license_key'] ) ) {
            // Persist even an expired-trial ('valid' => false) response —
            // status comes from the response itself (persist() doesn't
            // hardcode 'active'), so mmi_is_licensed() still correctly
            // reports false, while MMI_License_UI can tell "trial expired"
            // apart from "never had a trial" for its own messaging.
            $this->persist( $plugin_slug, $response['license_key'], $response, $domain );
        }

        if ( class_exists( 'MMI_Settings' ) ) {
            MMI_Settings::set( $cache_key, time() );
        }

        return $response;
    }

    /* ── MMI_Settings persistence ───────────────────────────────────────── */

    /**
     * Persist a licence response to MMI_Settings using the same key structure
     * that MMI_License_Manager::activate_license() writes, so mmi_is_licensed()
     * and the Hub panel work identically on both server and client installations.
     *
     * @param string $plugin_slug
     * @param string $license_key
     * @param array  $response     Decoded JSON body from the license server.
     * @param string $domain
     */
    public function persist( string $plugin_slug, string $license_key, array $response, string $domain ): void {
        if ( ! class_exists( 'MMI_Settings' ) ) {
            return;
        }

        // Store under the license's real scope, which the server reports as
        // 'scope' — a suite key activated from one plugin's License panel is
        // an 'mmi-suite' license, not a 'mmi-data-pipeline' one. The
        // requested slug's own entry (usually its automatic trial) is then
        // dropped so it can't keep showing a trial countdown.
        $requested_slug = $plugin_slug;
        if ( ! empty( $response['scope'] ) && is_string( $response['scope'] ) ) {
            $plugin_slug = sanitize_key( $response['scope'] );
        }

        $settings_key = 'mmi_license_' . $plugin_slug;
        $existing     = MMI_Settings::get( $settings_key ) ?: [];

        // covered_plugins lets mmi_is_licensed() resolve a bundle license for a
        // DIFFERENT plugin slug than the one this scope was activated under —
        // the server includes it in the activate/validate response whenever
        // $plugin_slug is a bundle id. A plain per-plugin license has no
        // covered_plugins in the response, so it falls back to just itself.
        MMI_Settings::set( $settings_key, array_merge( $existing, [
            'key'             => $license_key,
            'type'            => $response['license_type'] ?? ( $existing['type'] ?? '' ),
            // Every prior caller of persist() only ever passed a valid:true
            // response, so hardcoding 'active' here was harmless — until
            // start_automatic_trial() started persisting an expired-trial
            // (valid:false) check-in too, so it can no longer be hardcoded.
            // $response['status'] carries the server's real status
            // ('active'/'expired'/etc.) whenever present.
            'status'          => $response['status'] ?? 'active',
            'domain'          => $domain,
            'activated_at'    => $existing['activated_at'] ?? current_time( 'mysql' ),
            'last_validated'  => current_time( 'mysql' ),
            'features'        => $response['features'] ?? [],
            'expiry_date'     => $response['expiry_date'] ?? null,
            'is_trial'        => (bool) ( $response['is_trial'] ?? false ),
            'trial_ends_at'   => $response['trial_ends_at'] ?? null,
            'days_remaining'  => $response['days_remaining'] ?? null,
            'max_activations' => $response['max_activations'] ?? ( $existing['max_activations'] ?? 1 ),
            'covered_plugins' => $response['covered_plugins'] ?? ( $existing['covered_plugins'] ?? [ $plugin_slug ] ),
            // Server-signed copy of the fields above (ADR-0012) — what the
            // gate actually trusts. Always overwritten, never merged: an old
            // signature must not outlive the response that replaced it.
            'claim'           => $response['signed']['claim'] ?? null,
            'sig'             => $response['signed']['sig'] ?? null,
        ] ) );

        // Track every scope (plugin slug or bundle id) activated on this site
        // so mmi_is_licensed() can discover a bundle covering a different slug.
        $scopes = (array) MMI_Settings::get( 'mmi_license_scopes', [] );
        if ( ! in_array( $plugin_slug, $scopes, true ) ) {
            $scopes[] = $plugin_slug;
        }
        if ( $requested_slug !== $plugin_slug ) {
            MMI_Settings::delete( 'mmi_license_' . $requested_slug );
            $scopes = array_diff( $scopes, [ $requested_slug ] );
        }
        MMI_Settings::set( 'mmi_license_scopes', array_values( $scopes ) );

        // Local, client-side counterpart to MMI_License_Manager's server-only
        // mmi_license_activated action — fires on THIS site, where
        // MMI_Update_Client actually needs to know to drop its cached
        // catalog so a newly-licensed plugin can show "Update available"
        // immediately instead of waiting out CATALOG_TTL.
        do_action( 'mmi_license_client_activated', $plugin_slug );
    }

    /**
     * Clear the locally cached licence for a plugin.
     *
     * @param string $plugin_slug
     */
    public function clear( string $plugin_slug ): void {
        if ( ! class_exists( 'MMI_Settings' ) ) {
            return;
        }
        MMI_Settings::delete( 'mmi_license_' . $plugin_slug );
        $scopes = (array) MMI_Settings::get( 'mmi_license_scopes', [] );
        MMI_Settings::set( 'mmi_license_scopes', array_values( array_diff( $scopes, [ $plugin_slug ] ) ) );

        // See mmi_license_client_activated above.
        do_action( 'mmi_license_client_deactivated', $plugin_slug );
    }

    /* ── HTTP layer ─────────────────────────────────────────────────────── */

    /**
     * POST a JSON body to the license server and return the decoded response.
     *
     * Always returns an array. On any network-level failure sets
     * `network_error => true` so callers can distinguish server-side rejections
     * (e.g. invalid key) from connectivity problems.
     *
     * @param  string $endpoint  Path relative to REST_BASE, e.g. '/license/activate'.
     * @param  array  $body
     * @return array             Decoded JSON body; always contains 'valid' key.
     */
    private function post( string $endpoint, array $body ): array {
        $server = (string) apply_filters( 'mmi_license_server_url', self::SERVER_URL );
        $url    = rtrim( $server, '/' ) . self::REST_BASE . $endpoint;

        $http_response = wp_remote_post( $url, [
            'timeout'     => self::TIMEOUT,
            'redirection' => 2,
            'sslverify'   => true,
            'headers'     => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'X-MMI-Site'   => get_site_url(),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $http_response ) ) {
            return [
                'valid'         => false,
                'network_error' => true,
                'error'         => $http_response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $http_response );
        $raw  = wp_remote_retrieve_body( $http_response );
        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) ) {
            return [
                'valid'         => false,
                'network_error' => true,
                'error'         => sprintf( 'Invalid response from license server (HTTP %d).', $code ),
            ];
        }

        /* Surface rate-limit as a non-network error so UI can show it. */
        if ( $code === 429 ) {
            return array_merge( $data, [ 'valid' => false, 'network_error' => false ] );
        }

        return $data;
    }

    /* ── Grace period / failure tracking ───────────────────────────────── */

    /**
     * When a remote check-in fails, return the cached licence if it's still
     * within the expiry + MMI_LICENSE_GRACE_DAYS grace window.  After MAX_FAILURES consecutive
     * failures we treat the licence as unverifiable and return invalid.
     */
    private function grace_period_fallback(
        string $plugin_slug,
        string $license_key,
        string $domain,
        string $error
    ): array {
        $failures = (int) get_transient( self::FAILURE_TRANSIENT . $plugin_slug );

        if ( $failures >= self::MAX_FAILURES ) {
            return [
                'valid'         => false,
                'network_error' => true,
                'error'         => 'License server unreachable. Please check your connection and try again.',
            ];
        }

        if ( ! class_exists( 'MMI_Settings' ) ) {
            return [ 'valid' => false, 'network_error' => true, 'error' => $error ];
        }

        $cached = MMI_Settings::get( 'mmi_license_' . $plugin_slug );
        if ( empty( $cached['key'] ) ) {
            return [ 'valid' => false, 'network_error' => true, 'error' => $error ];
        }

        /* Check expiry + grace. */
        if ( ! empty( $cached['expiry_date'] ) ) {
            $grace_end = mmi_license_grace_ends_at( $cached['expiry_date'] );
            if ( time() > $grace_end ) {
                return [
                    'valid'         => false,
                    'network_error' => true,
                    'error'         => 'License has expired and server cannot be reached to renew.',
                ];
            }
        }

        /* Return a synthetic valid response from cache. */
        return [
            'valid'          => true,
            'license_key'    => $cached['key'],
            'license_type'   => $cached['type'] ?? '',
            'status'         => $cached['status'] ?? 'active',
            'expiry_date'    => $cached['expiry_date'] ?? null,
            'is_trial'       => $cached['is_trial'] ?? false,
            'trial_ends_at'  => $cached['trial_ends_at'] ?? null,
            'features'       => $cached['features'] ?? [],
            'days_remaining' => $cached['days_remaining'] ?? null,
            'from_cache'     => true,
            'network_error'  => true,
            'error'          => $error,
        ];
    }

    private function increment_failures( string $plugin_slug ): void {
        $key   = self::FAILURE_TRANSIENT . $plugin_slug;
        $count = (int) get_transient( $key );
        set_transient( $key, $count + 1, 30 * DAY_IN_SECONDS );
    }

    private function reset_failures( string $plugin_slug ): void {
        delete_transient( self::FAILURE_TRANSIENT . $plugin_slug );
    }
}
