<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MMI_Pipeline_Supplier_Fetch_Runner
 *
 * Runs all supplier updaters in sequence. When invoked directly via CLI you
 * can pass --plugivery-full to force Plugivery products full dataset
 * (since=all) instead of the normal incremental daily fetch.
 *
 * Example:
 *   php class-pipeline-supplier-fetch-runner.php --plugivery-full
 */
class MMI_Pipeline_Supplier_Fetch_Runner {

    /** Supplier IDs this runner has a dedicated updater for. */
    public const SUPPORTED_SUPPLIERS = ['xchange', 'skuport', 'plugivery'];

    /**
     * Updater classes for one supplier, in run order.
     *
     * Single owner of the supplier -> updater mapping: runAll() and runOne()
     * both build their work list from here, so a caller that wants one supplier
     * can never drift from what the scheduled full run would have done.
     *
     * @return array<int, class-string|callable> Empty when the supplier is unknown or gated off.
     */
    private function updatersFor(string $supplierId, bool $plugiveryFull): array {
        switch ($supplierId) {
            case 'xchange':
                // Previously gated behind a 'vip' feature tier — removed along
                // with the whole tier/feature-license system in favor of a
                // plain max_activations model. Any valid mmi-data-pipeline
                // license now includes this supplier integration.
                return [MMI_Pipeline_Xchange_Vendors::class, MMI_Pipeline_Xchange_Updater::class];

            case 'skuport':
                return [MMI_Pipeline_SkuPort_Updater::class];

            case 'plugivery':
                return [function() use ($plugiveryFull) { (new MMI_Pipeline_Plugivery_Updater($plugiveryFull))->run(); }];
        }

        return [];
    }

    /**
     * Run a single supplier's updaters.
     *
     * Exists so an on-demand "fetch this one source now" action doesn't have to
     * re-fetch every other supplier as a side effect. Deliberately ignores the
     * `mmi_vip_enabled_suppliers` list that runAll() honours — that list governs
     * what the *schedule* pulls automatically; an explicit per-source request is
     * the user overriding that, not violating it.
     *
     * @param string $supplierId    One of SUPPORTED_SUPPLIERS.
     * @param bool   $plugiveryFull Forces full Plugivery product fetch.
     * @throws \RuntimeException When the supplier is unsupported/gated, or its updater fails.
     */
    public function runOne(string $supplierId, bool $plugiveryFull = false): void {
        $updaters = $this->updatersFor($supplierId, $plugiveryFull);

        if (empty($updaters)) {
            throw new \RuntimeException(
                in_array($supplierId, self::SUPPORTED_SUPPLIERS, true)
                    ? "Supplier '{$supplierId}' is not available on this site's licence tier."
                    : "No dedicated updater exists for supplier '{$supplierId}'."
            );
        }

        $this->runUpdaters($updaters);
    }

    /**
     * Run all supplier updaters.
     * @param bool $plugiveryFull If true, forces full Plugivery product fetch.
     */
    public function runAll(bool $plugiveryFull = false): void {
        // Get enabled suppliers from WordPress options
        $enabled_suppliers = MMI_Settings::get('mmi_vip_enabled_suppliers', ['skuport', 'xchange', 'plugivery']);

        // Build updater list based on enabled suppliers
        $updaters = [];
        foreach (self::SUPPORTED_SUPPLIERS as $supplierId) {
            if (in_array($supplierId, $enabled_suppliers)) {
                $updaters = array_merge($updaters, $this->updatersFor($supplierId, $plugiveryFull));
            }
        }

        if (empty($updaters)) {
            echo "[" . date('Y-m-d H:i:s') . "] No suppliers enabled. Skipping fetch.\n";
            return;
        }

        $this->runUpdaters($updaters);
    }

    /**
     * Execute a prepared updater list, isolating per-updater failures.
     *
     * @param array<int, class-string|callable> $updaters
     * @throws \RuntimeException Aggregated message when one or more updaters failed.
     */
    private function runUpdaters(array $updaters): void {
        $errors = [];
        // NOTE: Inter-API gaps (e.g. 12s between XChange calls) are now enforced
        // automatically by MMI_API_Throttler inside HTTPClient::getJson(). No
        // manual sleep() needed here. Add new APIs to MMI_API_Throttler::KNOWN_APIS.
        foreach ($updaters as $class) {
            $now = date('Y-m-d H:i:s');
            if (is_string($class)) {
                echo "[$now] Running {$class}...\n";
            } else {
                echo "[$now] Running MMI_Pipeline_Plugivery_Updater...\n";
            }
            try {
                if (is_string($class)) {
                    (new $class())->run();
                    $completedName = $class;
                } else {
                    $class();
                    $completedName = MMI_Pipeline_Plugivery_Updater::class;
                }
                $now = date('Y-m-d H:i:s');
                echo "[$now] {$completedName} completed.\n";
            } catch (\Throwable $e) {
                $now = date('Y-m-d H:i:s');
                $name = is_string($class) ? $class : MMI_Pipeline_Plugivery_Updater::class;
                echo "[$now] Error in {$name}: {$e->getMessage()}\n";
                if (class_exists('MMI_Logger')) {
                    MMI_Logger::info( sprintf(
                        '[SupplierFetchRunner] Error in %s: %s (File: %s, Line: %d)',
                        $name,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ), [], 'integrations', 'MMI_Pipeline_Supplier_Fetch_Runner' );
                }
                $errors[] = "[{$name}] " . $e->getMessage();
                // Don't let one supplier failure stop the others
                continue;
            }
        }
        $now = date('Y-m-d H:i:s');
        echo "[$now] Supplier fetch completed.\n";
        if ( ! empty( $errors ) ) {
            throw new \RuntimeException( implode( ' | ', $errors ) );
        }
    }
}

if (PHP_SAPI === 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    // Bootstrap WordPress environment for CLI. wp-load.php already fires
    // 'plugins_loaded' during its own bootstrap, which is what requires this
    // very file (see mmi-data-pipeline.php) — no second require needed.
    define('WP_USE_THEMES', false);
    require dirname(__DIR__, 5) . '/wp-load.php';

    // Simple arg parse for --plugivery-full
    global $argv;
    $plugiveryFull = in_array('--plugivery-full', $argv ?? [], true);

    (new MMI_Pipeline_Supplier_Fetch_Runner())->runAll($plugiveryFull);
    exit;
}
