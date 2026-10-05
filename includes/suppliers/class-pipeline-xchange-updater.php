<?php

use MannMade\Integrations\HTTP\HTTPClient;
use MannMade\Integrations\Helpers\MMI_Updater_Trait;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class MMI_Pipeline_Xchange_Updater
{
    use MMI_Updater_Trait;

    /** @var HTTPClient */
    protected $http;

    /** @var array */
    protected $creds;

    /** @var string */
    protected $json_dir;

    public function __construct()
    {
        $this->http = new HTTPClient();
        $this->creds = $this->load_xchange_credentials();
        // Suite-wide JSON feed directory — see mmi_shared_lib_json_dir()
        // for why every reader/writer of these files must resolve the same
        // path (they previously didn't; see changelog).
        $this->json_dir = mmi_shared_lib_json_dir();
    }

    /**
     * Load Xchange credentials, preferring mmi-xchange-integration's facade
     * when that plugin is installed and licensed (see
     * mmi-hub/docs/XCHANGE_INTEGRATION_AUDIT.md §10) so a key rotated
     * through its settings page is reflected here immediately. Falls back
     * to the legacy wp_mmi vault, unchanged, for standalone pipeline
     * installs that don't have mmi-xchange-integration.
     *
     * @throws Exception
     * @return array
     */
    protected function load_xchange_credentials(): array
    {
        if (class_exists('MMI_Xchange_API') && MMI_Xchange_API::is_active()) {
            $creds = MMI_Xchange_API::get_credentials();
            if ($creds !== null) {
                return [
                    'token_key' => $creds['token_key'],
                    'api_key' => $creds['api_key'],
                    'api_url' => rtrim($creds['api_url'], '/'),
                ];
            }
        }

        $map = $this->load_credentials('Credentials & API Keys', [
            'xchange-token-key',
            'xchange-api-key',
            'xchange-token-url',
            'xchange-api-url'
        ]);

        return [
            'token_key' => $map['xchange-token-key'],
            'api_key' => $map['xchange-api-key'],
            'token_url' => rtrim($map['xchange-token-url'], '/'),
            'api_url' => rtrim($map['xchange-api-url'], '/'),
        ];
    }

    /**
     * Entry point
     */
    public function run(): void
    {
        try {
            // Check if products file exists and is recent (< 10 minutes old)
            $products_file = $this->json_dir . '/xchange-products.json';
            if (file_exists($products_file)) {
                $file_age = time() - filemtime($products_file);
                if ($file_age < 600) { // 10 minutes
                    $this->log("Products file is only {$file_age} seconds old - skipping to avoid rate limit");
                    return;
                }
            }

            // Fetch and save products with retry logic
            $products = $this->fetch_with_retry(function() {
                $tok = $this->fetch_timed_token();
                return $this->fetch_products($tok);
            }, 'products');
            $this->save_json($products, 'xchange-products.json');

            // MMI_API_Throttler enforces the 12-second gap automatically before
            // the next XChange HTTP call inside HTTPClient::getJson(). No bare
            // sleep() needed here — the throttler handles it.
            $this->log('Throttler will enforce 12s gap before fetching promotions...');

            // Fetch and save promotions with retry logic
            $promotions = $this->fetch_with_retry(function() {
                $tok = $this->fetch_timed_token();
                return $this->fetch_promotions($tok);
            }, 'promotions');
            $this->save_json($promotions, 'xchange-promotions.json');
            echo "SUCCESS: Xchange data updated\n";

        } catch (Exception $e) {
            $this->log('[XchangeUpdater] ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * One feed file per call, for the stepped fetch
     * (MMI_Pipeline_Cron::run_source_fetch_step()): each file gets its own
     * short Action Scheduler action, so no single run outlives the 45 s cron
     * runner the way products + promotions together did. Same 10-minute
     * freshness skip as run(), per file.
     *
     * @param string $part 'products' or 'promotions'.
     * @return bool True when the file was rewritten.
     * @throws Exception
     */
    public function run_part(string $part): bool
    {
        $files = ['products' => 'xchange-products.json', 'promotions' => 'xchange-promotions.json'];
        if (!isset($files[$part])) {
            throw new InvalidArgumentException("Unknown XChange feed part '{$part}'.");
        }

        $file = $this->json_dir . '/' . $files[$part];
        if (file_exists($file) && time() - filemtime($file) < 600) {
            $this->log("{$files[$part]} is under 10 minutes old - skipping to avoid rate limit");
            return false;
        }

        $data = $this->fetch_with_retry(function () use ($part) {
            $tok = $this->fetch_timed_token();
            return $part === 'products' ? $this->fetch_products($tok) : $this->fetch_promotions($tok);
        }, $part);
        $this->save_json($data, $files[$part]);
        return true;
    }

    /**
     * Fetch with retry logic for rate-limited API calls
     *
     * @param callable $fetchFunction Function to call that returns data
     * @param string $dataType Type of data being fetched (for logging)
     * @param int $maxRetries Maximum number of retry attempts
     * @param int $retryDelay Seconds to wait between retries
     * @throws Exception
     * @return array
     */
    protected function fetch_with_retry(callable $fetchFunction, string $dataType, int $maxRetries = 3, int $retryDelay = 15): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                return $fetchFunction();
            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;

                // Check if it's a rate limit error
                if (strpos($e->getMessage(), 'too frequent') !== false && $attempt < $maxRetries) {
                    $this->log("Rate limit hit for {$dataType} (attempt {$attempt}/{$maxRetries}). Throttler will delay next call...");
                    // Penalize immediately so the throttler waits extra on the next call
                    if ( class_exists( 'MMI_API_Throttler' ) ) {
                        \MMI_API_Throttler::penalize( 'xchange' );
                    } else {
                        sleep($retryDelay); // fallback if throttler not loaded
                    }
                    continue;
                }

                // If not a rate limit error or out of retries, throw
                throw $e;
            }
        }

        throw $lastException;
    }

    /**
     * Timed tokens are deprecated per Xchange's own API docs (Reseller API
     * 2.2r6, confirmed live 2026-08-14 — see mmi-hub/docs/XCHANGE_INTEGRATION_AUDIT.md
     * §3): the token-fetch HTTP call is no longer required, and empirical
     * testing against /version/ and /vendors/ confirmed the second half of
     * the Basic-Auth header isn't validated at all anymore — only the API
     * key is checked. No HTTP round-trip anymore; token_key is used
     * directly. Halves real request volume against Xchange's per-call-type
     * rate limit (every operation previously cost 2 requests instead of 1).
     *
     * @return string
     */
    protected function fetch_timed_token(): string
    {
        return $this->creds['token_key'];
    }

    /**
     * GET the full product list via HTTPClient
     *
     * @param string $etok
     * @throws Exception
     * @return array
     */
    protected function fetch_products(string $etok): array
    {
        $headers = [
            'Authorization' => 'Basic ' . base64_encode("{$this->creds['api_key']}:{$etok}"),
        ];

        // Try with extended params first; fall back to plain URL if server returns HTTP 500.
        $paramSets = [
            ['include_long_desc' => 'yes', 'include_webassets' => 'yes'],
            [], // fallback: no extra params
        ];

        $lastException = null;
        foreach ($paramSets as $params) {
            $url = $this->creds['api_url'] . '/products' . ($params ? '?' . http_build_query($params) : '');
            try {
                $data = $this->http->getJson($url, ['headers' => $headers]);

                if (isset($data['success']) && $data['success'] === false) {
                    throw new Exception("Xchange API error for products: " . ($data['error'] ?? 'Unknown API error'));
                }
                if (isset($data['error']) && !empty($data['error'])) {
                    throw new Exception("Xchange API error for products: {$data['error']}");
                }

                return $data;
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'status 500') === false) {
                    throw $e; // non-500 errors: re-throw immediately
                }
                $lastException = $e;
                $this->log('[XchangeUpdater] Products endpoint returned 500 with extended params; retrying without them.');
            }
        }
        throw $lastException;
    }

    /**
     * GET the promotions list via HTTPClient
     *
     * future=yes pulls in upcoming (not-yet-started) promotions as well as
     * current ones — Xchange's default is current-only. Safe to include:
     * MMI_Pipeline_Catalog_Updater already gates promo pricing application
     * on `$now >= $start && $now <= $end` when reading xchange-promotions.json,
     * so a future promo just sits inert in the cached file until its own
     * start_date arrives — this only adds lead time for merchandising
     * "coming soon" pricing, it can't cause an early discount.
     *
     * @param string $etok
     * @throws Exception
     * @return array
     */
    protected function fetch_promotions(string $etok): array
    {
        $query = http_build_query([
            'include_long_desc' => 'yes',
            'include_webassets' => 'yes',
            'future' => 'yes',
        ]);

        $url = $this->creds['api_url'] . '/promotions?' . $query;

        // HTTPClient will throw on non-2xx or JSON parse errors
        $data = $this->http->getJson($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$this->creds['api_key']}:{$etok}"),
            ],
        ]);

        // Check for API-level errors (success: false)
        if (isset($data['success']) && $data['success'] === false) {
            $errorMsg = $data['error'] ?? 'Unknown API error';
            throw new Exception("Xchange API error for promotions: {$errorMsg}");
        }

        if (isset($data['error']) && !empty($data['error'])) {
            throw new Exception("Xchange API error for promotions: {$data['error']}");
        }

        return $data;
    }
}

// If you want a WP‐CLI command too:
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    \WP_CLI::add_command('mmi fetch-xchange', function () {
        (new MMI_Pipeline_Xchange_Updater())->run();
    });
}
