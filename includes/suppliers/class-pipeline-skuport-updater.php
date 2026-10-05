<?php

use MannMade\Integrations\HTTP\HTTPClient;
use MannMade\Integrations\Helpers\MMI_Updater_Trait;

if (!defined('ABSPATH')) {
    exit;
}

class MMI_Pipeline_SkuPort_Updater {
    use MMI_Updater_Trait;

    /** @var HTTPClient */
    protected $http;

    /** @var array */
    protected $creds;

    public function __construct()
    {
        $this->http = new HTTPClient();
        $this->bootstrap_wp();
        $this->creds = $this->load_skuport_credentials();
    }

    /**
     * Load SkuPort credentials from wp_mmi
     *
     * @throws \Exception
     * @return array
     */
    protected function load_skuport_credentials(): array
    {
        // mmi-skuport-integration owns SkuPort credentials when active (same
        // facade shape as MMI_Xchange_API::get_credentials()); the vault read
        // below is the standalone fallback when it isn't installed.
        if (class_exists('MMI_SkuPort_API')) {
            $creds = MMI_SkuPort_API::get_credentials();
            if ($creds !== null) {
                return [
                    'username' => $creds['username'],
                    'password' => $creds['password'],
                    'api_url'  => rtrim($creds['api_url'], '/'),
                ];
            }
        }

        $map = $this->load_credentials('Credentials & API Keys', [
            'skuport-remote-username',
            'skuport-remote-password',
            'skuport-api-url'
        ]);

        return [
            'username' => $map['skuport-remote-username'],
            'password' => $map['skuport-remote-password'],
            'api_url' => rtrim($map['skuport-api-url'], '/'),
        ];
    }

    public function run(): void {
        try {
            $jsonDir = $this->init_json_dir();

            $endpoints = [
                'promos'   => $this->creds['api_url'] . '/promos',
                'products' => $this->creds['api_url'] . '/products',
            ];

            foreach ($endpoints as $type => $url) {
                // HTTPClient only throttles XChange URLs itself.
                if (class_exists('MMI_API_Throttler')) {
                    \MMI_API_Throttler::throttle('skuport');
                }
                $data = $this->http->getJson($url, [
                    // Well under the 45 s cron runner even if SkuPort hangs.
                    'timeout' => 20,
                    'headers' => [
                        'Accept'        => 'application/skuport-v2+json',
                        'Authorization' => 'Basic ' . base64_encode("{$this->creds['username']}:{$this->creds['password']}"),
                    ],
                ]);

                $this->save_json($data, "skuport-{$type}.json", $jsonDir);
            }

            echo "SUCCESS: SkuPort data updated\n";

        } catch (\Exception $e) {
            $this->log('[SkuPortUpdater] ' . $e->getMessage());
            throw $e;
        }
    }
}

// Register WP-CLI command
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    \WP_CLI::add_command('mmi fetch-skuport', function () {
        try {
            \WP_CLI::log('Starting SkuPort data fetch...');
            $updater = new MMI_Pipeline_SkuPort_Updater();
            $updater->run();
            \WP_CLI::success('SkuPort data fetch completed');
        } catch (\Exception $e) {
            \WP_CLI::error('SkuPort fetch failed: ' . $e->getMessage());
        }
    });
}
