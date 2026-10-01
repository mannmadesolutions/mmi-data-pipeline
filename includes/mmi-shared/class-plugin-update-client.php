<?php
/**
 * MMI Shared Library — Plugin Update Client
 *
 * Not to be confused with MMI_Updater_Trait (trait-updater.php, same
 * directory) — that trait is a helper for *data-feed* updater classes
 * (CatalogUpdater, PricingPromotionsUpdater, etc. in mmi-data-pipeline)
 * pulling supplier catalog/pricing data from Xchange/SkuPort/Plugivery. It
 * has nothing to do with updating plugin code. This class is the one that
 * actually self-updates the mmi-* plugin — named "Plugin Update Client"
 * specifically to not collide with "Updater Trait" in the same folder.
 *
 * Client-side half of the self-hosted plugin updater. The server half —
 * MMI_Extensions_REST_Controller in mmi-admin, GET mmi-hub/v1/extensions
 * and mmi-hub/v1/extensions/{slug}/download — is internal-only, mannmade.us
 * tooling; this class is what customer sites actually run, bundled into
 * every mmi-* plugin the same way MMI_Settings/MMI_Logger are. It hooks
 * WordPress's native plugin-update machinery so an mmi-* plugin behaves
 * exactly like any wordpress.org-hosted or EDD/WooCommerce-licensed
 * commercial plugin: "Update available" on the Plugins list, a real "View
 * version X.X details" popup, and one-click update — no manual zip
 * download/upload ever required.
 *
 * A plugin only shows as updatable when this site holds an active license
 * covering it (direct or via a bundle) for its own domain. A lapsed or
 * revoked license simply stops the update from being offered — same
 * enforcement shape as feature access, not a separate mechanism.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Plugin_Update_Client {

    const CATALOG_URL           = 'https://mannmade.us/wp-json/mmi-hub/v1/extensions';
    const CATALOG_TRANSIENT     = 'mmi_update_client_catalog';
    const CATALOG_STALE_SETTING = 'mmi_update_client_catalog_stale';
    const CATALOG_TTL           = 6 * HOUR_IN_SECONDS;

    /* ── WordPress update-check hook ─────────────────────────────────────── */

    /**
     * Filters the `update_plugins` site transient WordPress recomputes on
     * its own schedule (twice daily by default) — this is what actually
     * drives the Plugins list's "Update available" row and the "N updates
     * available" admin-bar/menu badge.
     *
     * @param  object $transient
     * @return object
     */
    public static function check_for_updates( $transient ) {
        if ( empty( $transient ) || ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }

        $catalog = self::get_catalog();
        if ( empty( $catalog ) ) {
            return $transient;
        }

        foreach ( self::installed_mmi_plugins() as $plugin_file => $data ) {
            $slug = dirname( $plugin_file );

            if ( empty( $catalog[ $slug ]['version'] ) || empty( $catalog[ $slug ]['download_url'] ) ) {
                continue;
            }
            if ( ! version_compare( $catalog[ $slug ]['version'], $data['Version'], '>' ) ) {
                continue;
            }

            $package_url = self::authorize_download_url( $slug, $catalog[ $slug ]['download_url'] );
            if ( ! $package_url ) {
                // No active license covering this plugin — don't advertise an
                // update this site isn't actually entitled to install.
                continue;
            }

            $transient->response[ $plugin_file ] = (object) [
                'id'           => 'mannmade.us/' . $slug,
                'slug'         => $slug,
                'plugin'       => $plugin_file,
                'new_version'  => $catalog[ $slug ]['version'],
                'url'          => $catalog[ $slug ]['url'] ?? '',
                'package'      => $package_url,
                'tested'       => $catalog[ $slug ]['tested'] ?? '',
                'requires'     => $catalog[ $slug ]['requires'] ?? '',
                'requires_php' => $catalog[ $slug ]['requires_php'] ?? '',
                'icons'        => [],
                'banners'      => [],
            ];
        }

        return $transient;
    }

    /* ── "View version details" popup ────────────────────────────────────── */

    /**
     * Filters `plugins_api` so the Plugins list's "View details" link (and
     * the update notice's version-details popup) render real data instead
     * of WordPress.org's 404 for a plugin it's never heard of.
     *
     * @param  false|object|array $result
     * @param  string             $action
     * @param  object             $args
     * @return false|object
     */
    public static function plugin_info( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
            return $result;
        }

        $catalog = self::get_catalog();
        $entry   = $catalog[ $args->slug ] ?? null;
        if ( ! $entry ) {
            return $result;
        }

        return (object) [
            'name'          => $entry['name'] ?? $args->slug,
            'slug'          => $args->slug,
            'version'       => $entry['version'] ?? '',
            'author'        => '<a href="https://mannmade.us">MannMade Industries</a>',
            'homepage'      => $entry['url'] ?? '',
            'requires'      => $entry['requires'] ?? '',
            'requires_php'  => $entry['requires_php'] ?? '',
            'sections'      => [
                'description' => $entry['description'] ?? '',
            ],
            'download_link' => self::authorize_download_url( $args->slug, $entry['download_url'] ?? '' ) ?: '',
        ];
    }

    /* ── Cache lifecycle ──────────────────────────────────────────────────── */

    /**
     * Force a fresh catalog fetch on the next check — after this site's own
     * update just ran, or after a license is activated/deactivated (a
     * newly-licensed plugin should be able to show "Update available"
     * immediately, not up to CATALOG_TTL later).
     */
    public static function purge_cache(): void {
        delete_transient( self::CATALOG_TRANSIENT );
    }

    /**
     * upgrader_process_complete callback — same purge, WP's own hook signature.
     */
    public static function purge_cache_after_update( $upgrader, $hook_extra ): void {
        self::purge_cache();
    }

    /* ── Catalog fetch ────────────────────────────────────────────────────── */

    /**
     * Fetch the public catalog, transient-cached for CATALOG_TTL. On a
     * network failure, serves the last successfully-fetched catalog (via
     * MMI_Settings, never wp_options — see AGENTS.md's Settings & Data
     * Storage rule) rather than going dark, same grace-period shape as
     * MMI_License_Client::grace_period_fallback().
     *
     * @return array<string, array>
     */
    private static function get_catalog(): array {
        $cached = get_transient( self::CATALOG_TRANSIENT );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_get( self::CATALOG_URL, [
            'timeout'    => 10,
            'sslverify'  => true,
            'user-agent' => 'MMI-Update-Client/1.0; ' . get_site_url(),
        ] );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            $stale = class_exists( 'MMI_Settings' ) ? MMI_Settings::get( self::CATALOG_STALE_SETTING ) : null;
            return is_array( $stale ) ? $stale : [];
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return [];
        }

        set_transient( self::CATALOG_TRANSIENT, $data, self::CATALOG_TTL );
        if ( class_exists( 'MMI_Settings' ) ) {
            MMI_Settings::set( self::CATALOG_STALE_SETTING, $data );
        }

        return $data;
    }

    /* ── License → download URL ──────────────────────────────────────────── */

    /**
     * Append this site's domain to a bare catalog download_url, or return
     * null if no active license covers $slug — the caller must treat null as
     * "don't offer this update/download", not "offer it without credentials."
     *
     * The license key is deliberately NOT in the URL: package URLs are stored
     * in the update_plugins transient, printed by the upgrader and written to
     * web-server access logs. add_license_header() sends it as a header on
     * the download request itself.
     */
    private static function authorize_download_url( string $slug, string $bare_url ): ?string {
        if ( empty( $bare_url ) || ! class_exists( 'MMI_Settings' ) || ! self::is_trusted_package_url( $bare_url ) ) {
            return null;
        }
        $license = self::find_license_for_slug( $slug );
        if ( ! $license || empty( $license['key'] ) ) {
            return null;
        }

        // Domain must match the string MMI_License_Client::activate() sent
        // at activation time (get_site_url(), not home_url()) — the license
        // table's active_domains membership check is a strict string
        // compare, not a normalized one.
        return add_query_arg( [
            'domain' => get_site_url(),
        ], $bare_url );
    }

    /**
     * http_request_args filter: attach the license key as X-MMI-License-Key
     * to a package download from the catalog host — and only there. Redirects
     * are turned off for that request so the header can never be forwarded
     * to another host.
     */
    public static function add_license_header( $args, $url ) {
        if ( ! is_string( $url ) || ! self::is_trusted_package_url( $url ) ) {
            return $args;
        }
        $parts = wp_parse_url( $url );
        $route = (string) ( $parts['path'] ?? '' );
        if ( ! empty( $parts['query'] ) ) {
            parse_str( $parts['query'], $query );
            $route .= ' ' . (string) ( $query['rest_route'] ?? '' ); // plain-permalink form
        }
        if ( ! preg_match( '#/extensions/([a-z0-9\-]+)/download(?:\s|$)#', $route, $m ) ) {
            return $args;
        }
        $license = self::find_license_for_slug( $m[1] );
        if ( ! $license || empty( $license['key'] ) ) {
            return $args;
        }
        $args['headers']                      = (array) ( $args['headers'] ?? [] );
        $args['headers']['X-MMI-License-Key'] = $license['key'];
        $args['redirection']                  = 0;
        return $args;
    }

    /**
     * The license key is only ever appended to an https URL on the catalog's
     * own host — never to whatever host a catalog entry happens to name.
     */
    private static function is_trusted_package_url( string $url ): bool {
        $strip   = static fn( $h ) => preg_replace( '/^www\./', '', strtolower( (string) $h ) );
        $catalog = wp_parse_url( self::CATALOG_URL );
        $package = wp_parse_url( $url );
        return is_array( $package )
            && 'https' === strtolower( (string) ( $package['scheme'] ?? '' ) )
            && empty( $package['user'] )
            && '' !== $strip( $package['host'] ?? '' )
            && $strip( $package['host'] ?? '' ) === $strip( $catalog['host'] ?? '' );
    }

    /**
     * Resolve a locally-cached license covering $slug — a direct per-plugin
     * activation first, then any other activated scope (a bundle) whose
     * covered_plugins list includes $slug or '*'. Mirrors
     * MMI_License_Manager::has_valid_license()'s own resolution order,
     * client-side, from data MMI_License_Client::persist() already cached
     * at activation — no extra network round-trip needed to discover this.
     *
     * @return array{key:string,covered_plugins?:string[]}|null
     */
    private static function find_license_for_slug( string $slug ): ?array {
        $direct = MMI_Settings::get( 'mmi_license_' . $slug );
        if ( ! empty( $direct['key'] ) ) {
            return $direct;
        }

        foreach ( (array) MMI_Settings::get( 'mmi_license_scopes', [] ) as $scope ) {
            if ( $scope === $slug ) {
                continue;
            }
            $data = MMI_Settings::get( 'mmi_license_' . $scope );
            if ( empty( $data['key'] ) ) {
                continue;
            }
            $covered = (array) ( $data['covered_plugins'] ?? [] );
            if ( in_array( '*', $covered, true ) || in_array( $slug, $covered, true ) ) {
                return $data;
            }
        }

        return null;
    }

    /* ── Installed mmi-* plugin discovery ────────────────────────────────── */

    /**
     * @return array<string, array> Keyed by plugin file (e.g.
     *                               'mmi-data-pipeline/mmi-data-pipeline.php').
     */
    private static function installed_mmi_plugins(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $mmi = [];
        foreach ( get_plugins() as $file => $data ) {
            // mmi-admin is staff-only internal tooling, never in the public
            // catalog (see MMI_Extension_Status::INTERNAL_SLUGS) — excluding
            // it here too just avoids a wasted catalog lookup per request.
            if ( strpos( $file, 'mmi-' ) === 0 && strpos( $file, 'mmi-admin/' ) !== 0 ) {
                $mmi[ $file ] = $data;
            }
        }
        return $mmi;
    }
}
