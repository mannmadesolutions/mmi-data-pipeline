<?php

use MannMade\Integrations\HTTP\HTTPClient;
use MannMade\Integrations\Helpers\MMI_Updater_Trait;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class MMI_Pipeline_Xchange_Vendors
{
    use MMI_Updater_Trait;

    /** @var HTTPClient */
    protected $http;

    /** @var array */
    protected $creds;

    public function __construct()
    {
        $this->http = new HTTPClient();
        $this->creds = $this->load_xchange_credentials();
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
            $etok = $this->fetch_timed_token();

            // Fetch and save vendors
            $vendors = $this->fetch_vendors($etok);
            $this->save_json($vendors, 'XchangeVendors.json');

            echo "SUCCESS: Xchange vendors updated\n";

        } catch (Exception $e) {
            $this->log('[XchangeVendors] ' . $e->getMessage());
            echo "ERROR: {$e->getMessage()}\n";
        }
    }

    /**
     * Timed tokens are deprecated per Xchange's own API docs — see
     * mmi-hub/docs/XCHANGE_INTEGRATION_AUDIT.md §3. token_key is used
     * directly as the Basic-Auth password, no HTTP round-trip. This class
     * was missed in §3's original fix pass (only MMI_Xchange_API_Client and
     * MMI_Pipeline_Xchange_Updater's twin method were caught); fixed here
     * while reconciling credential shape for §10 — the old code also made
     * this call via bare wp_remote_get(), bypassing MMI_API_Throttler
     * entirely, an API Rate Limiting Golden Rule violation on top of being
     * an unnecessary request.
     *
     * @return string
     */
    protected function fetch_timed_token(): string
    {
        return $this->creds['token_key'];
    }

    /**
     * GET the vendor list via HTTPClient
     *
     * @param string $etok
     * @throws Exception
     * @return array
     */
    protected function fetch_vendors(string $etok): array
    {
        $url = $this->creds['api_url'] . '/vendors';

        // HTTPClient will throw on non-2xx or JSON parse errors
        return $this->http->getJson($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$this->creds['api_key']}:{$etok}"),
            ],
            'timeout' => 60,
        ]);
    }
}

// If you want a WP‐CLI command too:
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    \WP_CLI::add_command('mmi fetch-xchange-vendors', function () {
        (new MMI_Pipeline_Xchange_Vendors())->run();
    });
}
