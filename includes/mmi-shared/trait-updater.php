<?php
/**
 * MMI_Updater_Trait (bundled/vendored copy) — ADR-0007, mmi-admin/docs/decisions/.
 *
 * Identical to the original mmi-hub/includes/helpers-legacy/MMI_Updater_Trait.php,
 * except load_credentials() below now resolves the wp_mmi table name via
 * MMI_Settings::ensure_table() instead of a raw `$wpdb->prefix . 'mmi'` —
 * guarantees the table exists (self-healing) and follows AGENTS.md's Settings
 * & Data Storage rule rather than bypassing it. Only ever loaded once per
 * request, by whichever plugin's bundled copy wins version negotiation in
 * bootstrap.php — see ADR-0006. Do not hand-edit this file in a single
 * plugin; edit the canonical source (mmi-admin/lib/mmi-shared/) and re-sync
 * (sync-to-plugins.sh) to every plugin that bundles it.
 *
 * Not the WordPress plugin self-updater — despite the name, "updater" here
 * means classes that update *product/pricing data* from supplier feeds
 * (CatalogUpdater, PricingPromotionsUpdater, etc. in mmi-data-pipeline).
 * For the mechanism that updates the plugin's own code, see
 * MMI_Plugin_Update_Client (class-plugin-update-client.php, same directory).
 */
namespace MannMade\Integrations\Helpers;

if ( ! \defined( 'ABSPATH' ) && ! \defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
    exit;
}

/**
 * Trait MMI_Updater_Trait
 *
 * Provides shared methods for updater classes.
 */

trait MMI_Updater_Trait
{
    /**
     * The HTTP client fetch_json() calls into. Not initialized here — the
     * consuming class must assign it (e.g. `$this->http = new HTTPClient();`
     * in its constructor) before calling fetch_json(). Declared here (rather
     * than left as an undeclared dynamic property, as it was before) so
     * static analysis can see the contract and so PHP 8.2+ doesn't flag
     * every consumer with a deprecated-dynamic-property notice.
     *
     * @var \MannMade\Integrations\HTTP\HTTPClient|null
     */
    protected $http = null;

    /**
     * Load and decode JSON file into array.
     *
     * @param string $path
     * @return array
     */
    protected function loadJson(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Retrieve product IDs by distribution taxonomy and meta key.
     *
     * @param string $dist Distribution term slug.
     * @param string $metaKey Meta key to match.
     * @return int[]
     */
    protected function getProductsByDistribution(string $dist, string $metaKey): array
    {
        $this->log("getProductsByDistribution('{$dist}', {$metaKey}) fetching all pages");

        // Callers need the complete matching ID set (not just a page of it),
        // but a single posts_per_page=-1 query is an unbounded read against
        // the products table with no LIMIT — fine at today's catalog size,
        // not something to leave uncapped per AGENTS.md's Scalability rule.
        // Paginate internally in fixed-size batches instead, so the result
        // is identical but no single query can grow without bound.
        $batch_size = 500;
        $page       = 1;
        $all_ids    = [];

        do {
            $args = [
                'post_type'      => 'product',
                'tax_query'      => [
                    [
                        'taxonomy' => 'distribution',
                        'field'    => 'slug',
                        'terms'    => $dist,
                    ],
                ],
                'meta_query'     => [
                    [
                        'key'     => $metaKey,
                        'compare' => 'EXISTS',
                    ],
                ],
                'fields'         => 'ids',
                'posts_per_page' => $batch_size,
                'paged'          => $page,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ];

            $batch = get_posts($args);
            if (!is_array($batch)) {
                break;
            }

            $all_ids = array_merge($all_ids, $batch);
            $page++;
        } while (count($batch) === $batch_size);

        return $all_ids;
    }

    /**
     * Check if an abort has been requested for the updater.
     *
     * @return bool
     */
    protected function isAborted(): bool
    {
        if (get_transient('mannmade_update_abort')) {
            delete_transient('mannmade_update_abort');
            return true;
        }
        return false;
    }

    /**
     * Log a message using the plugin's logging system.
     *
     * @param string $message
     * @return void
     */
    protected function log(string $message): void
    {
        $class = get_class($this);

        // A consuming class can declare `const LOG_CATEGORY = '...';` to
        // pick its own category explicitly. Falls back to matching against
        // a fixed list of historical class-name fragments for any consumer
        // that doesn't — brittle (a differently-named future updater class
        // silently lands in the generic 'pipeline' bucket with no warning),
        // kept only for backward compatibility with existing consumers.
        if (defined("{$class}::LOG_CATEGORY")) {
            \MMI_Logger::info( $message, [], constant("{$class}::LOG_CATEGORY"), $class );
            return;
        }

        $catalog_class_fragments = ['CatalogUpdater', 'PrismSoundUpdater', 'PricingPromotionsUpdater'];
        foreach ($catalog_class_fragments as $fragment) {
            if (strpos($class, $fragment) !== false) {
                \MMI_Logger::info( $message, [], 'catalog-update', $class );
                return;
            }
        }

        \MMI_Logger::info( $message, [], 'pipeline', $class );
    }

    /**
     * Load credentials from wp_mmi table.
     *
     * @param string $tabName The tab name to load credentials for (e.g., 'Xchange', 'Plugivery')
     * @param array $requiredKeys Required credential keys to validate
     * @return array Associative array of credentials
     * @throws \Exception If credentials are missing or incomplete
     */
    protected function load_credentials(string $tabName, array $requiredKeys = []): array
    {
        global $wpdb;
        $table = class_exists( 'MMI_Settings' ) ? \MMI_Settings::ensure_table() : $wpdb->prefix . 'mmi';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT field_name, field_value 
             FROM {$table} 
             WHERE tab_name = %s",
            $tabName
        ));

        if (empty($rows)) {
            throw new \Exception("No {$tabName} credentials found in {$table}");
        }

        $map = array_column($rows, 'field_value', 'field_name');
        if (class_exists('MMI_Credentials')) {
            foreach ($map as $name => $value) {
                $map[$name] = \MMI_Credentials::reveal_setting((string) $name, $value);
            }
        }
        
        // Validate required keys if provided. array_key_exists()+strict
        // empty-string check, not empty() — empty() can't tell "key never
        // saved" apart from "saved as a legitimate falsy-looking value" (a
        // credential that happens to be the literal string "0"), per
        // AGENTS.md's Merge & Defaults Functions rule.
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $map) || $map[$key] === '' || $map[$key] === null) {
                throw new \Exception("Missing {$tabName} credential: {$key}");
            }
        }

        return $map;
    }

    /**
     * Initialize JSON directory for storing feed data.
     *
     * @return string The JSON directory path
     */
    protected function init_json_dir(): string
    {
        // constant() rather than a bare MMI_JSON_PATH reference: no plugin in
        // this suite actually defines this constant any more (it was an
        // mmi-hub-era override, swept for dead references during the
        // elimination migration), so a bare reference is unresolvable to
        // static analysis and flags as an undefined constant. defined()
        // still guards it correctly at runtime either way — if some plugin
        // ever defines it again, this picks it up exactly as before.
        //
        // Fallback uses wp_upload_dir() rather than a hardcoded
        // WP_CONTENT_DIR . '/uploads/...' guess — matches
        // MMI_Package_Publisher::get_packages_dir()'s existing pattern and
        // correctly resolves on multisite or any site with a customized
        // uploads path (a bare WP_CONTENT_DIR guess would silently miss both).
        //
        // Since 2026-09-26 this defers to mmi_shared_lib_json_dir(), the private
        // (not URL-reachable) feed directory — ADR-0012. Its own fallback kept
        // for a bundle whose bootstrap predates that function.
        $json_dir = function_exists('mmi_shared_lib_json_dir')
            ? mmi_shared_lib_json_dir()
            : ( defined('MMI_JSON_PATH')
                ? constant('MMI_JSON_PATH')
                : trailingslashit( wp_upload_dir()['basedir'] ) . 'mmi-json/' );

        if (!is_dir($json_dir)) {
            wp_mkdir_p($json_dir);
        }

        return $json_dir;
    }

    /**
     * Bootstrap WordPress environment if not already loaded.
     * Useful for CLI scripts that may run outside of WordPress context.
     *
     * @return void
     */
    protected function bootstrap_wp(): void
    {
        if (!function_exists('get_option')) {
            $wp_load = dirname(__FILE__, 6) . '/wp-load.php';
            if (file_exists($wp_load)) {
                require_once $wp_load;
            } else {
                throw new \Exception('Cannot locate wp-load.php to bootstrap WordPress');
            }
        }
    }

    /**
     * Fetch JSON from URL with retry logic and error handling.
     *
     * @param string $url The URL to fetch from
     * @param int $retries Number of retry attempts (default: 3)
     * @param int $sleepMs Minimum gap enforced between retries, in ms (default: 400)
     * @param string $apiKey Throttler key for the retry gap — defaults to a
     *                       per-class key derived from get_class($this) when
     *                       omitted; pass the real shared key (e.g. 'xchange')
     *                       to coordinate pacing with the rest of that API's
     *                       traffic instead of pacing this call in isolation.
     * @return array|null Decoded JSON data or null on failure
     */
    protected function fetch_json(string $url, int $retries = 3, int $sleepMs = 400, string $apiKey = ''): ?array
    {
        if (!isset($this->http)) {
            throw new \Exception('HTTPClient not initialized. Ensure $this->http is set in your class.');
        }

        if ($apiKey === '') {
            $class  = get_class($this);
            $apiKey = 'updater_' . strtolower(substr($class, strrpos($class, '\\') !== false ? strrpos($class, '\\') + 1 : 0));
        }

        $attempt = 0;
        $lastError = null;

        while ($attempt < $retries) {
            $attempt++;

            // Route the between-attempt gap through the shared throttler
            // (which applies its own jittered wait internally, see
            // MMI_API_Throttler::throttle()) rather than a bare usleep()
            // here — AGENTS.md's API Rate Limiting rule forbids hand-rolled
            // sleep-based pacing outside that one sanctioned mechanism. No
            // wait happens on the first attempt (nothing recorded yet for
            // this key), matching the original "only sleep between retries"
            // behavior.
            if (class_exists('\\MMI_API_Throttler')) {
                \MMI_API_Throttler::throttle($apiKey, ['min_gap_ms' => $sleepMs]);
            }

            try {
                $data = $this->http->getJson($url);

                if (is_array($data)) {
                    return $data;
                }

                $lastError = "Invalid JSON response (attempt {$attempt}/{$retries})";
                $this->log($lastError);

            } catch (\Exception $e) {
                $lastError = "HTTP error on attempt {$attempt}/{$retries}: " . $e->getMessage();
                $this->log($lastError);
            }
        }

        $this->log("Failed to fetch JSON from {$url} after {$retries} attempts. Last error: {$lastError}");
        return null;
    }

    /**
     * Save JSON data to file.
     *
     * @param array $data Data to encode and save
     * @param string $filename Target filename
     * @param string|null $dir Directory path (uses init_json_dir if null)
     * @return bool Success status
     */
    protected function save_json(array $data, string $filename, ?string $dir = null): bool
    {
        $dir = $dir ?? $this->init_json_dir();
        $path = rtrim($dir, '/') . '/' . $filename;
        $tmp_path = $path . '.' . uniqid('', true) . '.tmp';

        // Keep the last good copy. The feed on disk is the customer's own
        // data (ADR-0012): if credentials are revoked or a supplier answers
        // with an empty list instead of an HTTP error, that must not wipe
        // out a feed that had records. The rejected payload is left beside
        // it as .rejected.json for diagnosis.
        // Promotions are exempt: an empty promo list is normal and must win.
        if ( stripos( $filename, 'promo' ) === false
            && self::count_feed_records( $data ) === 0 && file_exists( $path ) && filesize( $path ) > 0 ) {
            $existing = json_decode( (string) file_get_contents( $path ), true );
            if ( is_array( $existing ) && self::count_feed_records( $existing ) > 0 ) {
                @file_put_contents( $path . '.rejected.json', json_encode( $data ) );
                $this->log( "Kept existing {$filename}: new payload had no records" );
                return false;
            }
        }

        // Stamp a human-readable generation time so it's obvious at a glance
        // whether a cache file is fresh or stale, without putting the
        // timestamp in the filename. Only safe for object-shaped payloads
        // ({"products": [...], ...} — Xchange's own raw API responses are
        // already this shape). A plain top-level JSON array (SkuPort's own
        // raw responses — confirmed live: skuport-products.json/
        // skuport-promos.json are bare `[...]` lists) would silently become
        // an object the moment a string key is added, and anything that
        // iterates the decoded result expecting only product rows would
        // suddenly also see this one — skipped entirely for that shape
        // rather than restructuring how those files are consumed everywhere.
        if ( $data !== array_values( $data ) ) {
            $data['generated_at'] = current_time( 'mysql' );
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if ($encoded === false) {
            $this->log("Failed to encode JSON for {$filename}");
            return false;
        }
        
        // Write to a temp path, then rename() into place — rename() on the
        // same filesystem is atomic, so any concurrent loadJson($path) call
        // either sees the complete old file or the complete new one, never a
        // truncated/partial write mid-flight. A plain file_put_contents()
        // straight to $path (the previous behavior here) had no such
        // guarantee — see AGENTS.md's "temp path + rename(), not fopen()
        // truncating the real path up front" rule; MMI_Package_Publisher's
        // ZIP generation already follows this same pattern.
        $result = file_put_contents($tmp_path, $encoded, LOCK_EX);

        if ($result === false) {
            $this->log("Failed to write JSON to {$tmp_path}");
            @unlink($tmp_path);
            return false;
        }

        // One previous version stays beside the live file (.prev.json).
        if ( file_exists( $path ) ) {
            @copy( $path, $path . '.prev.json' );
        }

        if (!rename($tmp_path, $path)) {
            $this->log("Failed to move JSON into place at {$path}");
            @unlink($tmp_path);
            return false;
        }

        $this->log("Saved JSON to {$path} (" . number_format($result) . " bytes)");
        return true;
    }

    /**
     * Rows in a feed payload: a bare list, or the first list-valued key of
     * an object ({"products": [...]}). Non-list objects count as one record.
     */
    private static function count_feed_records( array $data ): int
    {
        if ( $data === array_values( $data ) ) {
            return count( $data );
        }
        foreach ( $data as $key => $value ) {
            if ( $key !== 'generated_at' && is_array( $value ) && $value === array_values( $value ) ) {
                return count( $value );
            }
        }
        return 1;
    }
}