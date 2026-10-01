<?php
/**
 * Product Import AJAX Controller
 * 
 * Handles AJAX requests for product import functionality
 * 
 * @package MannMade\DataPipeline\AJAX
 */

namespace MannMade\DataPipeline\AJAX;

use MMI_DB;
use MMI_Logger;
use MMI_Pipeline_Cron;
use MMI_Pipeline_Rate_Limiter;
use MMI_Pipeline_Field_Resolver;
use MannMade\DataPipeline\Importers\Variable_Product_Manager;
use MannMade\DataPipeline\Importers\MMI_Dynamic_Record_Importer;

if (!defined('ABSPATH')) {
    exit;
}

class ProductImportController {
    
    /**
     * Constructor - Register AJAX hooks
     */
    public function __construct() {
        // Legacy handlers (keep for backwards compatibility)
        add_action('wp_ajax_mmi_run_product_import', [$this, 'handle_product_import']);
        add_action('wp_ajax_mmi_save_import_schedule', [$this, 'handle_save_schedule']);
        add_action('wp_ajax_mmi_save_fetch_schedule', [$this, 'handle_save_fetch_schedule']);
        add_action('wp_ajax_mmi_get_supplier_stats', [$this, 'handle_get_supplier_stats']);
        add_action('wp_ajax_mmi_run_process', [$this, 'handle_run_process']);

        // Import settings handlers
        add_action('wp_ajax_mmi_pipeline_test_all_mappings',     [$this, 'test_all_mappings']);
        add_action('wp_ajax_mmi_pipeline_run_manual_import',     [$this, 'run_manual_import']);
        add_action('wp_ajax_mmi_pipeline_import_status',         [$this, 'get_import_status']);
        add_action('wp_ajax_mmi_pipeline_get_import_history',    [$this, 'get_import_history']);
        add_action('wp_ajax_mmi_pipeline_validate_profile_config', [$this, 'validate_profile_config']);

        // Background import cron hook
        add_action('mmi_pipeline_import_batch', [$this, 'process_import_batch_cron']);

        // JS-driven batch processing — does not rely on WP-Cron
        add_action('wp_ajax_mmi_pipeline_process_import_batch', [$this, 'handle_process_import_batch']);
        add_action('wp_ajax_mmi_pipeline_abort_import',         [$this, 'handle_abort_import']);

        // Row-expand spot-check on the "Field changes" results table.
        add_action('wp_ajax_mmi_pipeline_get_field_change_products', [$this, 'handle_get_field_change_products']);
    }

    /**
     * AJAX endpoint — one page of the products affected by one field's change
     * during one completed import run, for the "Field changes" table's
     * row-expand spot-check UI (see import-settings.js's renderFieldChangeRow()).
     */
    public function handle_get_field_change_products(): void {
        check_ajax_referer('mmi_pipeline_nonce', 'nonce');
        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $run_id   = isset($_POST['run_id'])   ? (int) $_POST['run_id']   : 0;
        $supplier = isset($_POST['supplier']) ? sanitize_text_field(wp_unslash($_POST['supplier'])) : '';
        $field    = isset($_POST['field'])    ? sanitize_text_field(wp_unslash($_POST['field']))    : '';
        $page     = isset($_POST['page'])     ? max(1, (int) $_POST['page']) : 1;

        if (!$run_id || $supplier === '' || $field === '') {
            wp_send_json_error(['message' => 'Missing run_id, supplier, or field.']);
            return;
        }

        $per_page = 25;
        $result   = MMI_DB::get_field_change_items($run_id, $supplier, $field, $page, $per_page);

        $items = array_map(static function ($row) {
            $product_id = (int) $row['product_id'];
            return [
                'product_id' => $product_id,
                'sku'        => $row['sku'],
                'title'      => $row['product_title'],
                'old_value'  => $row['old_value'],
                'new_value'  => $row['new_value'],
                'edit_url'   => $product_id ? get_edit_post_link($product_id, '') : '',
            ];
        }, $result['items']);

        wp_send_json_success([
            'items'      => $items,
            'total'      => $result['total'],
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages' => $per_page > 0 ? (int) ceil($result['total'] / $per_page) : 1,
        ]);
    }
    
    /**
     * Handle product import AJAX request - Uses Dynamic Product Importer
     */
    public function handle_product_import() {
        // Rate limiting
        if (!MMI_Pipeline_Rate_Limiter::check('mmi_run_product_import')) {
            MMI_Pipeline_Rate_Limiter::send_rate_limit_error('mmi_run_product_import');
            return;
        }
        
        // Set reasonable execution time limit (10 minutes max)
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');
        
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mmi_pipeline_nonce')) {
                wp_send_json_error(['message' => 'Security check failed']);
                return;
            }
            
            // Check permissions
            if (!mmi_data_pipeline_user_can( 'manage_options' )) {
                wp_send_json_error(['message' => 'Unauthorized']);
                return;
            }
            
            // Get parameters
            $supplier = isset($_POST['supplier']) ? sanitize_text_field($_POST['supplier']) : 'all';
            $profile = isset($_POST['profile']) ? sanitize_text_field($_POST['profile']) : 'default';
            
            // Determine suppliers to import
            if ($supplier === 'all') {
                $suppliers = $this->get_enabled_suppliers();
            } else {
                $suppliers = [$supplier];
            }
            
            $results = [];
            $total_stats = [
                'created' => 0,
                'updated' => 0,
                'errors' => 0,
                'pricing_updated' => 0,
                'stock_updated' => 0,
                'promo_applied' => 0
            ];
            
            // Run import for each supplier
            foreach ($suppliers as $supplier_name) {
                try {
                    $importer = new \MannMade\DataPipeline\Importers\MMI_Dynamic_Product_Importer( $supplier_name, $profile );
                    $result = $importer->run();
                    
                    $results[$supplier_name] = $result;
                    
                    // Aggregate stats
                    foreach ($total_stats as $key => $value) {
                        $total_stats[$key] += $result['stats'][$key] ?? 0;
                    }
                    
                } catch (\Exception $e) {
                    $results[$supplier_name] = [
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                    $total_stats['errors']++;
                }
            }
            
            // Log the import
            $this->log_import($results);

            mmi_data_pipeline_audit('import.run', [
                'object_type' => 'import_profile',
                'object_id'   => $profile,
                'outcome'     => 'success',
                'details'     => ['trigger' => 'legacy_manual', 'suppliers' => array_values((array) $suppliers), 'stats' => $total_stats],
            ]);
            
            wp_send_json_success([
                'message' => $this->format_import_message($total_stats),
                'results' => $results,
                'stats' => $total_stats,
                'log' => $this->format_import_log($results)
            ]);
            
        } catch (\Exception $e) {
            MMI_Logger::error( 'Product Import Error: ' . $e->getMessage(), [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Format import success message
     */
    private function format_import_message($stats) {
        $parts = [];
        if ($stats['created'] > 0) $parts[] = "{$stats['created']} created";
        if ($stats['updated'] > 0) $parts[] = "{$stats['updated']} updated";
        if ($stats['promo_applied'] > 0) $parts[] = "{$stats['promo_applied']} promos";
        if ($stats['errors'] > 0) $parts[] = "{$stats['errors']} errors";
        
        return "Import completed: " . (empty($parts) ? 'no changes' : implode(', ', $parts));
    }
    
    /**
     * Handle save import schedule
     */
    public function handle_save_schedule() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mmi_pipeline_nonce')) {
                wp_send_json_error(['message' => 'Security check failed']);
                return;
            }
            
            // Check permissions
            if (!mmi_data_pipeline_user_can( 'manage_options' )) {
                wp_send_json_error(['message' => 'Unauthorized']);
                return;
            }
            
            // Get settings
            $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
            $frequency = sanitize_text_field($_POST['frequency'] ?? 'twicedaily');
            $create_new = isset($_POST['create_new']) && $_POST['create_new'] === 'true';
            $update_existing = isset($_POST['update_existing']) && $_POST['update_existing'] === 'true';
            
            // Validate frequency
            $valid_frequencies = ['hourly', 'twicedaily', 'daily', 'weekly'];
            if (!in_array($frequency, $valid_frequencies)) {
                $frequency = 'twicedaily';
            }
            
            // Save settings
            MMI_DB::set_setting( 'mmi_scheduled_imports_enabled', $enabled );
            MMI_DB::set_setting( 'mmi_import_schedule_frequency', $frequency );
            MMI_DB::set_setting( 'mmi_import_schedule_create', $create_new );
            MMI_DB::set_setting( 'mmi_import_schedule_update', $update_existing );

            mmi_data_pipeline_audit('schedule.update', [
                'outcome' => 'success',
                'details' => ['keys' => ['mmi_scheduled_imports_enabled', 'mmi_import_schedule_frequency', 'mmi_import_schedule_create', 'mmi_import_schedule_update'], 'enabled' => (bool) $enabled, 'frequency' => $frequency],
            ]);
            
            // Clear any existing scheduled event first
            $cron_hook = 'mmi_scheduled_product_import';
            wp_clear_scheduled_hook($cron_hook);
            
            // Schedule cron if enabled
            if ($enabled) {
                wp_schedule_event(time(), $frequency, $cron_hook);
            }
            
            wp_send_json_success([
                'message' => 'Settings saved successfully',
                'enabled' => $enabled,
                'frequency' => $frequency
            ]);
            
        } catch (\Exception $e) {
            MMI_Logger::error( 'Save Import Schedule Error: ' . $e->getMessage(), [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Handle save fetch schedule
     */
    public function handle_save_fetch_schedule() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mmi_pipeline_nonce')) {
                wp_send_json_error(['message' => 'Security check failed']);
                return;
            }
            
            // Check permissions
            if (!mmi_data_pipeline_user_can( 'manage_options' )) {
                wp_send_json_error(['message' => 'Unauthorized']);
                return;
            }
            
            // Get settings
            $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
            $frequency = sanitize_text_field($_POST['frequency'] ?? 'daily');
            $plugivery_full_auto = isset($_POST['plugivery_full_auto']) && $_POST['plugivery_full_auto'] === 'true';
            
            // Validate frequency
            $valid_frequencies = ['hourly', 'twicedaily', 'daily', 'weekly'];
            if (!in_array($frequency, $valid_frequencies)) {
                $frequency = 'daily';
            }
            
            // Save settings
            MMI_DB::set_setting( 'mmi_scheduled_fetch_enabled', $enabled );
            MMI_DB::set_setting( 'mmi_fetch_schedule_frequency', $frequency );
            MMI_DB::set_setting( 'mmi_fetch_plugivery_full_auto', $plugivery_full_auto );

            mmi_data_pipeline_audit('schedule.update', [
                'outcome' => 'success',
                'details' => ['keys' => ['mmi_scheduled_fetch_enabled', 'mmi_fetch_schedule_frequency', 'mmi_fetch_plugivery_full_auto'], 'enabled' => (bool) $enabled, 'frequency' => $frequency],
            ]);
            
            // Clear any existing scheduled event first
            $cron_hook = 'mmi_scheduled_supplier_fetch';
            wp_clear_scheduled_hook($cron_hook);
            
            // Schedule cron if enabled
            if ($enabled) {
                wp_schedule_event(time(), $frequency, $cron_hook);
            }
            
            wp_send_json_success([
                'message' => 'Settings saved successfully',
                'enabled' => $enabled,
                'frequency' => $frequency
            ]);
            
        } catch (\Exception $e) {
            MMI_Logger::error( 'Save Fetch Schedule Error: ' . $e->getMessage(), [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }

    /**
     * Manually run a legacy supplier's fetch — the "Fetch Selected" button's
     * path for xchange/skuport (the two suppliers with dedicated CLI updater
     * classes; every other source type routes through the separate
     * mmi_run_supplier_fetch action instead). Runs
     * MMI_Pipeline_Supplier_Fetch_Runner::runOne() in-process — the same call
     * the scheduled-cron fetch path already uses
     * (MMI_Pipeline_Cron::run_scheduled_source_fetch()) — rather than the
     * retired MMI_Product_Importer_UI's exec()-based shell-out (hardcoded
     * `sudo -u <server-user>` prefix, three guessed `wp` binary paths).
     *
     * Nonce: mmi_product_importer_nonce (matches mmiProductImportData.nonce
     * in product-import.js's runProcessesSequentially()).
     */
    public function handle_run_process() {
        check_ajax_referer('mmi_product_importer_nonce', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'mmi-data-pipeline')]);
            return;
        }

        $process      = sanitize_text_field($_POST['process'] ?? '');
        $supplier_map = [
            'xchange_fetch' => 'xchange',
            'skuport_fetch' => 'skuport',
        ];

        if (!isset($supplier_map[$process])) {
            wp_send_json_error(['message' => __('Invalid or unsupported process', 'mmi-data-pipeline')]);
            return;
        }

        if (!class_exists('MMI_Pipeline_Supplier_Fetch_Runner')) {
            wp_send_json_error(['message' => __('Supplier fetch runner not available', 'mmi-data-pipeline')]);
            return;
        }

        $supplier_id = $supplier_map[$process];

        mmi_data_pipeline_audit('source.fetch', [
            'object_type' => 'data_source',
            'object_id'   => $supplier_id,
            'outcome'     => 'success',
            'details'     => ['trigger' => 'manual', 'process' => $process],
        ]);

        try {
            (new \MMI_Pipeline_Supplier_Fetch_Runner())->runOne($supplier_id);
        } catch (\Throwable $e) {
            MMI_Logger::error("Manual fetch failed for \"{$supplier_id}\": " . $e->getMessage(), [], 'sync', 'ProductImportController');
            wp_send_json_error(['message' => $e->getMessage()]);
            return;
        }

        MMI_DB::set_setting('mmi_last_run_' . $process, current_time('mysql'));

        // Count products from the just-refreshed JSON file and update
        // wp_mmi_data_sources — matches the shape refreshSupplierStats()'s
        // response.data.fetch_count expects.
        $fetch_count = 0;
        $json_dir    = mmi_shared_lib_json_dir();
        $json_file   = $json_dir . $supplier_id . '-products.json';
        if (file_exists($json_file)) {
            $data = json_decode(file_get_contents($json_file), true);
            if (is_array($data)) {
                if (isset($data[0])) {
                    $fetch_count = count($data);
                } elseif (isset($data['products']) && is_array($data['products'])) {
                    $fetch_count = count($data['products']);
                } elseif (isset($data['data']) && is_array($data['data'])) {
                    $fetch_count = count($data['data']);
                } elseif (isset($data['items']) && is_array($data['items'])) {
                    $fetch_count = count($data['items']);
                } else {
                    $non_product_keys = ['wa_count', 'debug', 'timezone', 'server_zone', 'zone_offset', 'api_time', 'status', 'metadata', 'meta'];
                    $fetch_count = count(array_diff_key($data, array_flip($non_product_keys)));
                }
            }
        }

        if ($fetch_count > 0) {
            global $wpdb;
            $ds_table = $wpdb->prefix . 'mmi_data_sources';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ds_table)) === $ds_table) {
                $wpdb->update(
                    $ds_table,
                    [
                        'last_fetch_at'     => current_time('mysql'),
                        'last_fetch_status' => 'success',
                        'last_fetch_count'  => $fetch_count,
                        'updated_at'        => current_time('mysql'),
                    ],
                    ['supplier_id' => $supplier_id]
                );
            }
        }

        // Schedule Catalog Update 5 minutes after a successful manual fetch —
        // matches the scheduled-cron path's post-fetch trigger. Avoid
        // duplicating if one's already queued.
        if (!wp_next_scheduled('mmi_scheduled_catalog_update')) {
            wp_schedule_single_event(time() + 300, 'mmi_scheduled_catalog_update');
        }

        wp_send_json_success([
            'message'     => sprintf(__('%s completed successfully', 'mmi-data-pipeline'), $process),
            'fetch_count' => $fetch_count > 0 ? $fetch_count : null,
        ]);
    }

    /**
     * Log import to file
     */
    private function log_import($result) {
        MMI_Logger::info( 'Import completed', [
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'errors'  => $result['errors'] ?? 0,
        ], 'sync', 'MMI_Pipeline_Product_Import_Controller' );
    }
    
    /**
     * Format import result for display
     */
    private function format_import_log($result) {
        $log = "Import Results:\n";
        $log .= "===============\n";
        $log .= "Products Created: " . ($result['created'] ?? 0) . "\n";
        $log .= "Products Updated: " . ($result['updated'] ?? 0) . "\n";
        $log .= "Products Skipped: " . ($result['skipped'] ?? 0) . "\n";
        $log .= "Errors: " . ($result['errors'] ?? 0) . "\n";
        
        if (!empty($result['messages'])) {
            $log .= "\nMessages:\n";
            foreach ($result['messages'] as $message) {
                $log .= "- " . $message . "\n";
            }
        }
        
        return $log;
    }
    
    /**
     * Get per-supplier last-fetch stats for the Data Sources table's
     * "Last Fetch" column (product-import.js's refreshSupplierStats()).
     *
     * Nonce: mmi_product_importer_nonce (matches mmiProductImportData.nonce
     * in JS — this was previously mismatched against a dead sibling
     * registration checking mmi_pipeline_nonce that never actually ran,
     * since MMI_Product_Importer_UI's own mmi_product_importer_nonce-checked
     * handler for this action registered first and always won).
     *
     * Response shape (last_run/duration/file_exists/file_size/fetch_count)
     * ported from the retired MMI_Product_Importer_UI, generalized: any
     * supplier row (not just the two legacy CLI-updater suppliers) gets its
     * last_fetch_at/last_fetch_count from wp_mmi_data_sources.
     */
    public function handle_get_supplier_stats() {
        check_ajax_referer('mmi_product_importer_nonce', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $supplier = sanitize_text_field($_POST['supplier'] ?? '');

        if (empty($supplier)) {
            wp_send_json_error(['message' => 'Supplier parameter required']);
            return;
        }

        // Legacy per-process config — only populated for suppliers with a
        // dedicated CLI updater class (xchange/skuport).
        $processes     = class_exists('MMI_Pipeline_Cron') ? MMI_Pipeline_Cron::get_supplier_configs() : [];
        $fetch_key     = $supplier . '_fetch';
        $fetch_process = $processes[$fetch_key] ?? null;

        // wp_mmi_data_sources covers both legacy and dynamic (url/upload/
        // dropbox/gdrive) sources — the fallback source of truth whenever
        // $fetch_process is unavailable.
        $fetch_count_db = null;
        $last_fetch_at  = null;
        global $wpdb;
        $ds_table = $wpdb->prefix . 'mmi_data_sources';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $ds_table)) === $ds_table) {
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT last_fetch_count, last_fetch_at FROM `{$ds_table}` WHERE supplier_id = %s", $supplier),
                ARRAY_A
            );
            if ($row) {
                if (isset($row['last_fetch_count']) && $row['last_fetch_count'] > 0) {
                    $fetch_count_db = (int) $row['last_fetch_count'];
                }
                $last_fetch_at = $row['last_fetch_at'] ?? null;
            }
        }

        if (!$fetch_process && $last_fetch_at === null && $fetch_count_db === null) {
            wp_send_json_error(['message' => 'Invalid supplier']);
            return;
        }

        $last_run = $fetch_process
            ? MMI_Pipeline_Cron::format_last_run(MMI_DB::get_setting('mmi_last_run_' . $fetch_key, 'Never'))
            : MMI_Pipeline_Cron::format_last_run($last_fetch_at ?: 'Never');

        wp_send_json_success([
            'data_age'    => $fetch_process['status']['data_age'] ?? 'Unknown',
            'last_run'    => $last_run,
            'duration'    => $fetch_process['duration'] ?? '—',
            'file_exists' => $fetch_process['status']['exists'] ?? ($fetch_count_db !== null),
            'file_size'   => $fetch_process['status']['size'] ?? 0,
            'fetch_count' => $fetch_count_db,
        ]);
    }
    
    /**
     * Test all enabled field mappings
     */
    public function test_all_mappings() {
        check_ajax_referer('mmi_pipeline_nonce', 'nonce');
        
        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        // Get field mappings
        $field_mappings = MMI_DB::get_field_mappings( 'default' );
        
        // Log for debugging
        MMI_Logger::info( 'MMI Test All Mappings - Total fields: ' . count($field_mappings), [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
        
        // Get sample data
        $sample_data = $this->get_sample_product_data();
        
        if (!$sample_data) {
            wp_send_json_error(['message' => 'No sample product data available. Please ensure supplier JSON files are in place.']);
        }
        
        $results = [];
        
        foreach ($field_mappings as $field_name => $mapping) {
            // Check if field is enabled (handle both boolean and array formats)
            // Default to true if not set (same as UI default behavior)
            $is_enabled = true;
            if (isset($mapping['enabled'])) {
                if (is_bool($mapping['enabled'])) {
                    $is_enabled = $mapping['enabled'];
                } elseif (is_array($mapping['enabled'])) {
                    // Multi-supplier field - enabled if ANY supplier is enabled
                    $is_enabled = !empty(array_filter($mapping['enabled']));
                } else {
                    $is_enabled = !empty($mapping['enabled']);
                }
            }
            
            if (!$is_enabled) {
                continue;
            }
            
            // Handle multi-supplier source paths (array) vs single source (string)
            if (isset($mapping['source']) && is_array($mapping['source'])) {
                // Multi-supplier field - test each supplier's source
                $supplier_results = [];
                foreach ($mapping['source'] as $supplier => $source_path) {
                    if (empty($source_path)) {
                        continue;
                    }
                    
                    $source_value = MMI_Pipeline_Field_Resolver::get_nested_value($sample_data, $source_path);
                    $transformed_value = MMI_Pipeline_Field_Resolver::apply_transform($source_value, $mapping['transform'] ?? 'none');

                    $supplier_results[$supplier] = [
                        'source_path' => $source_path,
                        'raw_value' => $source_value,
                        'transformed' => $transformed_value
                    ];
                }
                
                if (!empty($supplier_results)) {
                    $results[$field_name] = [
                        'type' => 'multi-supplier',
                        'suppliers' => $supplier_results
                    ];
                }
            } elseif (isset($mapping['source']) && !empty($mapping['source'])) {
                // Single source field
                $source_value = MMI_Pipeline_Field_Resolver::get_nested_value($sample_data, $mapping['source']);
                $transformed_value = MMI_Pipeline_Field_Resolver::apply_transform($source_value, $mapping['transform'] ?? 'none');
                
                $results[$field_name] = [
                    'type' => 'single-source',
                    'source' => $mapping['source'],
                    'raw_value' => $source_value,
                    'transformed' => $transformed_value
                ];
            }
        }
        
        MMI_Logger::info( 'MMI Test All Mappings - Results count: ' . count($results), [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
        
        wp_send_json_success([
            'sample_product' => [
                'title' => $sample_data['title'] ?? 'N/A',
                'sku' => $sample_data['sku'] ?? 'N/A'
            ],
            'results' => $results
        ]);
    }
    
    /**
     * Lock/progress-state constants and field-stats merging now live in
     * Batch_Import_State. Single-product import logic (create/update dispatch,
     * field mapping, dirty-checking) now lives in Product_Import_Worker.
     */

    /**
     * Start a background product import.
     *
     * Acquires a processing lock, writes an in-progress history row, stores
     * initial progress in job_state, schedules a WP-Cron single event, and
     * fires a non-blocking loopback to wp-cron.php to trigger it immediately.
     *
     * Returns {run_id, total, queued: true} — the caller should then poll
     * mmi_pipeline_import_status for live progress updates.
     */
    public function run_manual_import() {
        check_ajax_referer('mmi_pipeline_nonce', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        if (MMI_DB::get_job_state(Batch_Import_State::LOCK_KEY)) {
            wp_send_json_error(['message' => 'An import is already running. Please wait for it to finish.', 'already_running' => true]);
            return;
        }

        $profile        = isset($_POST['profile']) ? sanitize_text_field($_POST['profile']) : 'default';
        $start_datetime = current_time('mysql');

        // Pre-flight: refuse to start if there is nothing to import.
        // This prevents false "Success" history rows when JSON files are missing or
        // the enabled-suppliers list is empty.
        $total = $this->count_all_products($profile);
        if ( $total === 0 ) {
            wp_send_json_error([
                'no_products' => true,
                'message'     => 'No products were found to import. Please run a Data Fetch first and verify your data sources are enabled.',
            ]);
            return;
        }

        // Acquire lock
        MMI_DB::set_job_state(Batch_Import_State::LOCK_KEY, 1, Batch_Import_State::LOCK_TTL);

        // Create in-progress history row so a crash is never silent
        $run_id = (int) $this->add_import_history_entry([
            'profile_id' => $profile,
            'started_at' => $start_datetime,
            'status'     => 'In Progress',
            'notes'      => wp_json_encode(['in_progress' => true]),
        ]);

        // Bound the field-change-item detail table's growth — cheap (indexed on
        // created_at), done once per run start rather than on a cron, since this
        // is the one place a new run's own detail rows are about to be written.
        MMI_DB::prune_old_field_change_items();

        // Persist initial progress so the polling endpoint has something to return
        MMI_DB::set_job_state(Batch_Import_State::PROGRESS_KEY, [
            'status'         => 'running',
            'profile'        => $profile,
            'run_id'         => $run_id,
            'start_datetime' => $start_datetime,
            'offset'         => 0,
            'total'          => $total,
            'imported'       => 0,
            'updated'        => 0,
            'skipped'        => 0,
            'failed'         => 0,
            'failures'       => [],
            'field_stats'    => [],
            'field_change_counts' => [],
        ], Batch_Import_State::LOCK_TTL);

        mmi_data_pipeline_audit('import.run', [
            'object_type' => 'import_profile',
            'object_id'   => $profile,
            'outcome'     => 'success',
            'details'     => ['trigger' => 'manual', 'run_id' => $run_id, 'total' => $total],
        ]);

        // Return immediately — the JS caller drives batching via mmi_pipeline_process_import_batch.
        // No WP-Cron is required; this works even when DISABLE_WP_CRON is true.
        wp_send_json_success([
            'run_id' => $run_id,
            'total'  => $total,
            'queued' => true,
        ]);
    }

    /**
     * Return the current background import progress for the JS polling loop.
     */
    public function get_import_status() {
        check_ajax_referer('mmi_pipeline_nonce', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $progress = MMI_DB::get_job_state(Batch_Import_State::PROGRESS_KEY);
        if (!$progress) {
            wp_send_json_success(['status' => 'idle']);
            return;
        }

        // Omit the large accumulated field_stats blob — the JS doesn't need it
        unset($progress['field_stats']);
        wp_send_json_success($progress);
    }

    /**
     * AJAX endpoint — pre-flight validation of a profile's configuration.
     *
     * Surfaces issues (missing field-mapping sources, disabled constants,
     * missing primary keys, missing/stale source feeds) that would otherwise
     * fail silently during import and produce empty/broken products. Called
     * before a manual run starts, before a schedule is enabled, and by the
     * profile creation wizard's Field Mapping step (which may be validating a
     * profile that hasn't been saved yet — see the optional $overrides below).
     */
    public function validate_profile_config(): void {
        check_ajax_referer('mmi_pipeline_nonce', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        $profile = isset($_POST['profile']) ? sanitize_text_field($_POST['profile']) : 'default';

        // Only meaningful for a profile that has no saved row yet (the wizard
        // mid-creation) — MMI_Pipeline_Config_Validator::validate() ignores
        // these entirely once a real saved profile is found under $profile.
        $overrides = [];
        if (isset($_POST['sources'])) {
            $sources = json_decode(wp_unslash($_POST['sources']), true);
            if (is_array($sources)) {
                $overrides['sources'] = array_map('sanitize_text_field', $sources);
            }
        }
        if (isset($_POST['import_mode'])) {
            $overrides['import_mode'] = sanitize_text_field($_POST['import_mode']);
        }

        $issues  = \MMI_Pipeline_Config_Validator::validate($profile, $overrides);

        $critical = array_values(array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'critical'));
        $warning  = array_values(array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'warning'));

        wp_send_json_success([
            'profile'  => $profile,
            'issues'   => $issues,
            'critical' => $critical,
            'warning'  => $warning,
            'has_issues' => !empty($issues),
        ]);
    }

    /**
     * WP-Cron callback — process one time-bounded batch of the background import.
     *
     * Reads state from job_state, processes up to IMPORT_TIME_BUDGET seconds of
     * products, merges field-change stats, then either reschedules itself (if
     * more products remain) or finalises the import run.
     */
    public function process_import_batch_cron(): void {
        $progress = MMI_DB::get_job_state(Batch_Import_State::PROGRESS_KEY);

        if (!$progress || $progress['status'] !== 'running') {
            if ($progress && $progress['status'] !== 'running') {
                MMI_Logger::info( sprintf(
                    'MMI VIP Import: process_import_batch_cron() early return — status="%s" (not running). Context: %s',
                    $progress['status'] ?? 'null',
                    defined('DOING_AJAX') && DOING_AJAX ? 'ajax' : 'cli/cron'
                ), [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
            }
            return;
        }

        $profile         = $progress['profile']        ?? 'default';

        // Everything below this point (field mapping, promo injection,
        // primary-key batch resolution, Product_Import_Worker::import_single_product())
        // is entirely WooCommerce-product-shaped and has no generic
        // equivalent — a non-Product profile is delegated to
        // MMI_Dynamic_Record_Importer instead, in its own separate method,
        // rather than threading a data_type branch through this ~250-line
        // Product-specific block. See DATA_PIPELINE_PHASE2_SCOPING.md
        // Milestone 4 — this mirrors the same reasoning Milestone 2 already
        // used for panel-field-mapping.php: a parallel path carries zero
        // regression risk to the real, financially-consequential Product
        // import path, where branching this method internally would not.
        $profile_meta_for_type = MMI_DB::get_profiles()[ $profile ] ?? [];
        $data_type              = $profile_meta_for_type['data_type'] ?? 'product';
        if ( 'product' !== $data_type ) {
            $this->process_generic_import_batch( $progress, $profile, $profile_meta_for_type, $data_type );
            return;
        }

        $offset          = (int) ($progress['offset']  ?? 0);
        $run_id          = (int) ($progress['run_id']  ?? 0);
        $start_datetime  = $progress['start_datetime'] ?? current_time('mysql');
        $acc_imported    = (int) ($progress['imported']    ?? 0);
        $acc_updated     = (int) ($progress['updated']     ?? 0);
        $acc_skipped     = (int) ($progress['skipped']     ?? 0);
        $acc_failed      = (int) ($progress['failed']      ?? 0);
        $acc_failures    = is_array($progress['failures'] ?? null) ? $progress['failures'] : [];
        $acc_field_stats = $progress['field_stats']         ?? [];
        // Running per-(supplier,field) count of detail rows already written to
        // MMI_DB::record_field_change_item() this run — carried across batches
        // the same way $acc_field_stats is, so flush_change_details()'s
        // MAX_FIELD_CHANGE_ITEMS cap holds for the whole run, not just one batch,
        // without a COUNT query per product (see that method's own docblock).
        $acc_field_change_counts = is_array($progress['field_change_counts'] ?? null) ? $progress['field_change_counts'] : [];

        try {
            // Effective mappings = saved overrides merged onto field-mapping defaults —
            // matches what the Field Mapping UI displays. Using raw
            // MMI_DB::get_field_mappings() here previously caused fields like
            // post_title/_sku/_regular_price (which rely on un-persisted defaults)
            // to be silently skipped, producing empty placeholder products.
            $field_mappings     = \MMI_Pipeline_Field_Mapping_Defaults::get_effective($profile);

            // Informational product attributes (Attributes wizard step) — this is the
            // "Path A" fix: previously only the legacy MMI_Dynamic_Product_Importer
            // (wired to the old mmi_run_product_import handler and the WP-Cron
            // scheduled path) read attribute config at all, so a profile's Attributes
            // configuration silently did nothing when "Run Import Now" (this path)
            // was clicked. Built once per batch, reused across every item in the
            // loop below — not re-instantiated per product.
            $attribute_config   = function_exists( 'mmi_get_attribute_config' )
                ? mmi_get_attribute_config( $profile )
                : [];
            $attribute_manager  = null;
            if ( ! empty( $attribute_config['enabled'] ) && ! empty( $attribute_config['attributes'] ) ) {
                $attribute_manager = new Variable_Product_Manager(
                    $attribute_config,
                    function ( string $message, string $type = 'info' ) {
                        $level = in_array( $type, [ 'error', 'warn', 'debug' ], true ) ? $type : 'info';
                        call_user_func( [ MMI_Logger::class, $level ], $message, [], 'sync', 'MMI_Pipeline_Product_Import_Controller' );
                    }
                );
            }
            // Configured attribute taxonomy slugs — used by Product_Import_Worker's
            // has_product_changes() to detect products imported before Attributes
            // was configured/enabled, so they get backfilled instead of staying
            // stuck as "unchanged" indefinitely.
            $attribute_config_slugs = $attribute_manager
                ? array_values( array_filter( array_column( $attribute_config['attributes'] ?? [], 'wc_slug' ) ) )
                : [];

            $enabled_suppliers  = $this->get_enabled_suppliers();
            $duplicate_strategy = MMI_DB::get_setting('mmi_pipeline_import_duplicate_strategy', 'update');
            $price_update       = MMI_DB::get_setting('mmi_pipeline_import_price_update', true);
            $stock_update       = MMI_DB::get_setting('mmi_pipeline_import_stock_update', true);

            // Derive allow_create and product_scope from the profile's stored import_mode.
            // This bridges the wizard-driven profile metadata with the per-product importer.
            $profile_registry    = MMI_DB::get_profiles();
            $profile_meta        = $profile_registry[$profile] ?? [];
            $import_mode_str     = $profile_meta['import_mode']   ?? null;
            $product_scope       = $profile_meta['product_scope'] ?? 'all_products';

            // Scope to the profile's own assigned sources when it has any — this
            // array was previously display-only, so every profile silently pulled
            // from every currently-enabled supplier regardless of what was actually
            // assigned to it. Profiles with an empty sources array (all pre-existing
            // profiles as of this change) fall through to the prior all-enabled
            // behavior unchanged.
            $profile_sources = $profile_meta['sources'] ?? [];
            if ( ! empty( $profile_sources ) ) {
                $enabled_suppliers = array_values( array_intersect( $enabled_suppliers, $profile_sources ) );
            }
            // create-only: allow creation, force 'skip' for existing products so wc_get_product()
            // is never called for them — calling it on thousands of existing products exhausts the
            // 256MB PHP memory limit (confirmed OOM crash, fatal-errors log 2026-06-09).
            // create-and-update / update-only: use the global duplicate_strategy setting.
            // null: legacy fallback (import_single_product reads the per-setting key).
            if ( $import_mode_str === 'create-only' ) {
                $allow_create_opt   = true;
                $duplicate_strategy = 'skip';
            } elseif ( $import_mode_str !== null ) {
                $allow_create_opt   = ( $import_mode_str === 'create-and-update' );
            } else {
                $allow_create_opt   = null;
            }

            $json_path = mmi_shared_lib_json_dir();

            /* ── Load promotion indices (keyed by supplier) ─────────── */
            // Mirrors what class-import-preview.php does: inject the matched
            // promo record into each product's raw data before field-mapping, so
            // source paths like 'promotions.street_price' resolve naturally.
            // Canonical loader/indexer: MMI_Pipeline_Field_Resolver::load_and_index_promotions().
            $promos_by_supplier = []; // [ supplier => ['index'=>[sku=>record], 'namespace'=>string|null] ]
            foreach ($enabled_suppliers as $supplier) {
                $promos_by_supplier[$supplier] = MMI_Pipeline_Field_Resolver::load_and_index_promotions($supplier, $json_path);
            }

            /* ── Build flat product list ────────────────────────────── */
            $all_products          = [];
            $pk_source_by_supplier = [];
            $pk_wc_by_supplier     = [];
            $pk_values_by_supplier = []; // supplier => [feed primary-key value, ...], for the batch resolve below
            foreach ($enabled_suppliers as $supplier) {
                $filename = $supplier === 'xchange' ? 'xchange-products.json' : "{$supplier}-products.json";
                $file     = $json_path . $filename;
                if (!file_exists($file)) {
                    continue;
                }
                $decoded  = json_decode(file_get_contents($file), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $products = $decoded['products'] ?? $decoded;
                if (!is_array($products)) {
                    continue;
                }
                // Determine primary key field for this supplier (used for promo lookup
                // and for identifying products in failure details)
                $primary_key_source = MMI_DB::get_primary_key($supplier, 'source', 'id');
                $pk_source_by_supplier[$supplier] = $primary_key_source;
                $pk_wc_by_supplier[$supplier]     = MMI_DB::get_primary_key($supplier, 'wc', '_sku');
                $promo_index     = $promos_by_supplier[$supplier]['index']     ?? [];
                $promo_namespace = $promos_by_supplier[$supplier]['namespace'] ?? null;
                foreach ($products as $product_data) {
                    // Always resolved (not just when a promo index exists) — this is
                    // also the batch-lookup key below, so every item needs it regardless.
                    $item_key = MMI_Pipeline_Field_Resolver::get_nested_value($product_data, $primary_key_source);
                    // Inject matching promo record so source fields like
                    // 'promotions.street_price' / 'promoPrice' resolve during mapping.
                    if (!empty($promo_index)) {
                        $matched_promo = $promo_index[(string) $item_key] ?? null;
                        if ($matched_promo !== null) {
                            // Correct a self-discounting base feed (e.g. SkuPort's
                            // Products feed reports its regular-price field as the
                            // CURRENTLY ACTIVE, already-discounted price while a
                            // promo runs) before mapping, so _regular_price doesn't
                            // resolve to the discounted value. See
                            // MMI_Pipeline_Field_Resolver::resolve_true_regular_price().
                            $product_data = MMI_Pipeline_Field_Resolver::correct_self_discounted_regular_price($product_data, $matched_promo, $field_mappings, $supplier);
                        }
                        $product_data = MMI_Pipeline_Field_Resolver::inject_promotion($product_data, $item_key, $promo_index, $promo_namespace);
                    }
                    $pk_value = ($item_key !== null && $item_key !== '') ? (string) $item_key : '';
                    if ($pk_value !== '') {
                        $pk_values_by_supplier[$supplier][] = $pk_value;
                    }
                    $all_products[] = ['supplier' => $supplier, 'data' => $product_data, 'pk_value' => $pk_value];
                }
            }

            // Batch-resolve every item's existing product ID up front, one chunked
            // WHERE-IN query per supplier, instead of the one-query-per-product
            // find_product_id_by_primary_key() call the loop below used to make
            // directly (Product_Import_Worker::import_single_product() still falls
            // back to that singular call for any caller that doesn't pass a
            // resolved_product_id — e.g. Import Preview's single-item spot-check).
            // For a 5,000+ item feed that was 5,000+ individual, largely-unindexed
            // postmeta lookups per run — see AGENTS.md's "Never re-query the
            // database inside a loop" rule and find_product_ids_by_primary_keys()'s
            // own docblock, which fixed the identical N+1 shape for Import Preview's
            // full-feed scan; this batch importer never received the same fix.
            $resolved_ids_by_supplier = [];
            foreach ($pk_values_by_supplier as $supplier => $values) {
                $resolved_ids_by_supplier[$supplier] = MMI_Pipeline_Field_Resolver::find_product_ids_by_primary_keys(
                    $pk_wc_by_supplier[$supplier] ?? '_sku',
                    $values
                );
            }

            $total        = count($all_products);
            $stats        = ['imported' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
            $acc_unchanged = (int) ($progress['unchanged'] ?? 0);
            $field_stats  = [];
            $budget_start = microtime(true);
            $processed    = 0;

            /* ── Time-bounded processing loop ───────────────────────── */
            for ($i = $offset; $i < $total; $i++) {
                $item = $all_products[$i];

                $import_options = [
                    'duplicate_strategy' => $duplicate_strategy,
                    'price_update'       => $price_update,
                    'stock_update'       => $stock_update,
                    'supplier'           => $item['supplier'],
                    'profile'            => $profile,
                    'product_scope'      => $product_scope,
                    // null means: fall back to legacy allow_create setting inside import_single_product
                    'allow_create'       => $allow_create_opt,
                    'attribute_manager'      => $attribute_manager,
                    'attribute_config_slugs' => $attribute_config_slugs,
                    // Pre-resolved above in one batched query per supplier — see the
                    // comment at $resolved_ids_by_supplier's own assignment. 0 is a
                    // real, correct "no existing product" answer here (distinct from
                    // the key being absent entirely, which is what tells
                    // import_single_product() no batch resolution was done at all).
                    'resolved_product_id'   => $resolved_ids_by_supplier[$item['supplier']][$item['pk_value']] ?? 0,
                    'run_id'                 => $run_id,
                ];

                try {
                    $result = Product_Import_Worker::import_single_product($item['data'], $field_mappings, $import_options, $field_stats, $acc_field_change_counts);
                    $stats[$result]++;
                } catch (\Throwable $e) {
                    $stats['failed']++;

                    // Identify the product so the completion summary can say WHICH
                    // product failed and WHY — a bare "Failed: N" count is unactionable.
                    $pk_source  = $pk_source_by_supplier[$item['supplier']] ?? 'id';
                    $item_key   = MMI_Pipeline_Field_Resolver::get_nested_value($item['data'], $pk_source);
                    $item_title = $item['data']['name'] ?? $item['data']['title'] ?? $item['data']['product_name'] ?? '';

                    if (count($acc_failures) < Batch_Import_State::MAX_FAILURE_DETAILS) {
                        $acc_failures[] = [
                            'supplier' => $item['supplier'],
                            'key'      => $item_key !== null && $item_key !== '' ? (string) $item_key : '(unknown)',
                            'title'    => is_string($item_title) ? mb_substr($item_title, 0, 120) : '',
                            'reason'   => mb_substr($e->getMessage(), 0, 300),
                        ];
                    }

                    MMI_Logger::error( sprintf(
                        'MMI Pipeline Product Import Error [supplier=%s, key=%s]: %s in %s:%d',
                        $item['supplier'],
                        $item_key !== null && $item_key !== '' ? $item_key : '(unknown)',
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ), [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
                }

                $processed++;

                if ($processed % 10 === 0 && (microtime(true) - $budget_start) >= Batch_Import_State::TIME_BUDGET) {
                    break;
                }
            }

            $next_offset        = $offset + $processed;
            $has_more           = $next_offset < $total;
            $total_imported     = $acc_imported  + $stats['imported'];
            $total_updated      = $acc_updated   + $stats['updated'];
            $total_unchanged    = $acc_unchanged + $stats['unchanged'];
            $total_skipped      = $acc_skipped   + $stats['skipped'];
            $total_failed       = $acc_failed    + $stats['failed'];
            $merged_field_stats = Batch_Import_State::merge_field_stats($acc_field_stats, $field_stats);

            // Re-read the current status before writing back: an abort or another worker
            // may have changed it while this batch was running (especially since we set
            // ignore_user_abort(true) to keep processing after browser disconnects).
            $current_state = MMI_DB::get_job_state(Batch_Import_State::PROGRESS_KEY);
            if (!$current_state || $current_state['status'] !== 'running') {
                // Import was aborted or otherwise invalidated while we processed — bail.
                return;
            }

            if ($has_more) {
                /* ── More products remain — persist progress; JS will call next batch ─ */
                MMI_DB::set_job_state(Batch_Import_State::PROGRESS_KEY, [
                    'status'         => 'running',
                    'profile'        => $profile,
                    'run_id'         => $run_id,
                    'start_datetime' => $start_datetime,
                    'offset'         => $next_offset,
                    'total'          => $total,
                    'imported'       => $total_imported,
                    'updated'        => $total_updated,
                    'unchanged'      => $total_unchanged,
                    'skipped'        => $total_skipped,
                    'failed'         => $total_failed,
                    'failures'       => $acc_failures,
                    'field_stats'    => $merged_field_stats,
                    'field_change_counts' => $acc_field_change_counts,
                ], Batch_Import_State::LOCK_TTL);
            } else {
                /* ── Final batch — write history, mark complete, release lock ─ */
                $duration = max(1, strtotime(current_time('mysql')) - strtotime($start_datetime));

                MMI_DB::set_setting('mmi_product_import_last_run', current_time('mysql'));
                MMI_DB::increment_setting('mmi_product_import_runs');

                if ($run_id) {
                    MMI_DB::update_import_history($run_id, [
                        'completed_at' => current_time('mysql'),
                        'imported'     => $total_imported,
                        'updated'      => $total_updated,
                        'skipped'      => $total_skipped + $total_unchanged,
                        'errors'       => $total_failed,
                        'status'       => $total_failed > 0 ? 'Partial Success' : 'Success',
                        'notes'        => wp_json_encode(array_filter([
                            'duration_seconds' => $duration,
                            'unchanged'        => $total_unchanged,
                            'failures'         => $acc_failures,
                        ])),
                    ]);

                    if (!empty($merged_field_stats)) {
                        MMI_DB::save_import_field_stats($run_id, $merged_field_stats);
                    }
                }

                // Build a compact field-change summary for the import completion notice.
                // Stored under 'top_changes' (not 'field_stats') so the unset() in
                // handle_process_import_batch() — which strips the large intermediate blob — does not remove it.
                //
                // Grouped and capped PER SUPPLIER, then round-robin-merged, rather than
                // one global top-10 sorted purely by raw 'changed' count. A single global
                // ranking silently starves any supplier whose batch is smaller than
                // another's — every one of its fields sits below the larger supplier's
                // fields in raw count, so a 2-supplier run can fill all 10 slots with one
                // supplier's fields and never show the other supplier did anything at all,
                // even though the completion notice's own "Created: N" total already
                // includes both. Round-robin guarantees every supplier with real changes
                // gets fair, proportional representation instead of being crowded out.
                $top_changes_by_supplier = [];
                foreach ($merged_field_stats as $fld_supplier => $fld_fields) {
                    foreach ($fld_fields as $fld_name => $fld_counts) {
                        $fld_changed = (int) ($fld_counts['changed']   ?? 0);
                        $fld_total   = $fld_changed + (int) ($fld_counts['unchanged'] ?? 0);
                        if ($fld_changed > 0) {
                            $top_changes_by_supplier[$fld_supplier][] = [
                                'field'    => $fld_name,
                                'supplier' => $fld_supplier,
                                'changed'  => $fld_changed,
                                'total'    => $fld_total,
                            ];
                        }
                    }
                }
                foreach ($top_changes_by_supplier as $fld_supplier => $rows) {
                    usort($rows, fn($a, $b) => $b['changed'] <=> $a['changed']);
                    $top_changes_by_supplier[$fld_supplier] = $rows;
                }
                $top_changes    = [];
                $supplier_queue = array_keys($top_changes_by_supplier);
                while (count($top_changes) < 10 && !empty($supplier_queue)) {
                    $added_this_round = false;
                    foreach ($supplier_queue as $idx => $fld_supplier) {
                        if (empty($top_changes_by_supplier[$fld_supplier])) {
                            unset($supplier_queue[$idx]);
                            continue;
                        }
                        $top_changes[]     = array_shift($top_changes_by_supplier[$fld_supplier]);
                        $added_this_round  = true;
                        if (count($top_changes) >= 10) {
                            break;
                        }
                    }
                    if (!$added_this_round) {
                        break; // every supplier's queue is empty
                    }
                }

                // Keep result available for 1 hour so the JS can read it after the last batch
                MMI_DB::set_job_state(Batch_Import_State::PROGRESS_KEY, [
                    'status'      => 'complete',
                    'run_id'      => $run_id,
                    'imported'    => $total_imported,
                    'updated'     => $total_updated,
                    'unchanged'   => $total_unchanged,
                    'skipped'     => $total_skipped,
                    'failed'      => $total_failed,
                    'failures'    => $acc_failures,
                    'top_changes' => $top_changes,
                ], 3600);

                MMI_DB::delete_job_state(Batch_Import_State::LOCK_KEY);

                // Send email notification if enabled (HTML email via pipeline template)
                MMI_Pipeline_Cron::send_import_notification(
                    [],
                    [
                        'imported' => $total_imported,
                        'updated'  => $total_updated,
                        'skipped'  => $total_skipped,
                        'failed'   => $total_failed,
                    ],
                    (float) $duration,
                    current_time( 'mysql' )
                );
            }

        } catch (\Throwable $e) {
            MMI_Logger::error( 'MMI Pipeline Import Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
            // Release lock and mark failed so the UI doesn't hang indefinitely
            MMI_DB::set_job_state(Batch_Import_State::PROGRESS_KEY, [
                'status'  => 'failed',
                'run_id'  => $run_id,
                'message' => $e->getMessage(),
            ], 3600);
            MMI_DB::delete_job_state(Batch_Import_State::LOCK_KEY);
            // Update the history row so it never stays stuck as "In Progress"
            if ($run_id) {
                MMI_DB::update_import_history($run_id, [
                    'completed_at' => current_time('mysql'),
                    'status'       => 'Failed',
                    'notes'        => wp_json_encode(['error' => $e->getMessage()]),
                ]);
            }
        }
    }

    /**
     * Manual "Run Import Now" batch, non-Product data types (Phase 2,
     * Milestone 4). Delegates entirely to MMI_Dynamic_Record_Importer —
     * see process_import_batch_cron()'s own comment for why this is a
     * separate method rather than a branch woven through that one.
     *
     * A non-Product profile is expected to have exactly one assigned source
     * (MMI_Dynamic_Record_Importer's constructor takes a single $supplier,
     * not Product's many-suppliers-into-one-profile model — see that
     * class's own docblock). Reuses MMI_Pipeline_Cron's batch-sizing
     * constants rather than inventing a second set of the same numbers for
     * what is conceptually the same "one batch of a profile import" concern.
     *
     * @param array  $progress     Current job-state progress record.
     * @param string $profile      Profile ID.
     * @param array  $profile_meta The profile's saved row (for 'sources').
     * @param string $data_type    e.g. 'taxonomy:product_brand'.
     */
    private function process_generic_import_batch( array $progress, string $profile, array $profile_meta, string $data_type ): void {
        $offset         = (int) ( $progress['offset']  ?? 0 );
        $run_id         = (int) ( $progress['run_id']  ?? 0 );
        $start_datetime = $progress['start_datetime'] ?? current_time( 'mysql' );
        $acc_imported   = (int) ( $progress['imported']  ?? 0 );
        $acc_updated    = (int) ( $progress['updated']   ?? 0 );
        $acc_unchanged  = (int) ( $progress['unchanged'] ?? 0 );
        $acc_skipped    = (int) ( $progress['skipped']   ?? 0 );
        $acc_failed     = (int) ( $progress['failed']    ?? 0 );

        $supplier = $profile_meta['sources'][0] ?? null;

        if ( empty( $supplier ) ) {
            MMI_Logger::error( "Generic import batch [{$profile}] — profile has no assigned data source", [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
            MMI_DB::set_job_state( Batch_Import_State::PROGRESS_KEY, [
                'status'  => 'failed',
                'run_id'  => $run_id,
                'message' => 'This profile has no assigned data source.',
            ], 3600 );
            Batch_Import_State::release_lock();
            if ( $run_id ) {
                MMI_DB::update_import_history( $run_id, [
                    'completed_at' => current_time( 'mysql' ),
                    'status'       => 'Failed',
                    'notes'        => wp_json_encode( [ 'error' => 'No assigned data source' ] ),
                ] );
            }
            return;
        }

        try {
            $importer = new MMI_Dynamic_Record_Importer( $supplier, $profile, $data_type );
            $result   = $importer->run(
                $offset,
                \MMI_Pipeline_Cron::PROFILE_BATCH_ITEM_LIMIT,
                \MMI_Pipeline_Cron::PROFILE_BATCH_TIME_BUDGET,
                \MMI_Pipeline_Cron::PROFILE_BATCH_MEMORY_BUDGET
            );
            $stats = $result['stats'] ?? [];

            // MMI_Dynamic_Record_Importer's stat names (created/errors) differ
            // from Batch_Import_State's progress shape (imported/failed) —
            // the same naming Product's own path already uses; mapped here
            // rather than renaming one side to match the other.
            $total_imported  = $acc_imported  + (int) ( $stats['created']   ?? 0 );
            $total_updated   = $acc_updated   + (int) ( $stats['updated']   ?? 0 );
            $total_unchanged = $acc_unchanged + (int) ( $stats['unchanged'] ?? 0 );
            $total_skipped   = $acc_skipped   + (int) ( $stats['skipped']   ?? 0 );
            $total_failed    = $acc_failed    + (int) ( $stats['errors']    ?? 0 );
            $next_offset     = (int) ( $result['next_offset'] ?? $offset );
            $has_more        = (bool) ( $result['has_more'] ?? false );

            // Re-read current status before writing back — an abort may have
            // changed it while this batch ran, same guard the Product path uses.
            $current_state = MMI_DB::get_job_state( Batch_Import_State::PROGRESS_KEY );
            if ( ! $current_state || $current_state['status'] !== 'running' ) {
                return;
            }

            if ( $has_more ) {
                MMI_DB::set_job_state( Batch_Import_State::PROGRESS_KEY, [
                    'status'         => 'running',
                    'profile'        => $profile,
                    'run_id'         => $run_id,
                    'start_datetime' => $start_datetime,
                    'offset'         => $next_offset,
                    'total'          => (int) ( $result['total_in_feed'] ?? ( $progress['total'] ?? 0 ) ),
                    'imported'       => $total_imported,
                    'updated'        => $total_updated,
                    'unchanged'      => $total_unchanged,
                    'skipped'        => $total_skipped,
                    'failed'         => $total_failed,
                    'failures'       => [],
                    'field_stats'    => [],
                    'field_change_counts' => [],
                ], Batch_Import_State::LOCK_TTL );
                return;
            }

            // Final batch — write history, mark complete, release lock.
            $duration = max( 1, strtotime( current_time( 'mysql' ) ) - strtotime( $start_datetime ) );

            if ( $run_id ) {
                MMI_DB::update_import_history( $run_id, [
                    'completed_at' => current_time( 'mysql' ),
                    'imported'     => $total_imported,
                    'updated'      => $total_updated,
                    'skipped'      => $total_skipped + $total_unchanged,
                    'errors'       => $total_failed,
                    'status'       => $total_failed > 0 ? 'Partial Success' : 'Success',
                    'notes'        => wp_json_encode( array_filter( [
                        'duration_seconds' => $duration,
                        'unchanged'        => $total_unchanged,
                        'data_type'        => $data_type,
                    ] ) ),
                ] );
            }

            MMI_DB::set_job_state( Batch_Import_State::PROGRESS_KEY, [
                'status'    => 'complete',
                'run_id'    => $run_id,
                'imported'  => $total_imported,
                'updated'   => $total_updated,
                'unchanged' => $total_unchanged,
                'skipped'   => $total_skipped,
                'failed'    => $total_failed,
                'failures'  => [],
                'top_changes' => [],
            ], 3600 );

            Batch_Import_State::release_lock();

            MMI_Pipeline_Cron::send_import_notification(
                [],
                [
                    'imported' => $total_imported,
                    'updated'  => $total_updated,
                    'skipped'  => $total_skipped,
                    'failed'   => $total_failed,
                ],
                (float) $duration,
                current_time( 'mysql' )
            );
        } catch ( \Throwable $e ) {
            MMI_Logger::error( 'MMI Pipeline Generic Import Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), [], 'general', 'MMI_Pipeline_Product_Import_Controller' );
            MMI_DB::set_job_state( Batch_Import_State::PROGRESS_KEY, [
                'status'  => 'failed',
                'run_id'  => $run_id,
                'message' => $e->getMessage(),
            ], 3600 );
            Batch_Import_State::release_lock();
            if ( $run_id ) {
                MMI_DB::update_import_history( $run_id, [
                    'completed_at' => current_time( 'mysql' ),
                    'status'       => 'Failed',
                    'notes'        => wp_json_encode( [ 'error' => $e->getMessage() ] ),
                ] );
            }
        }
    }

    /**
     * Return the list of validated supplier IDs.
     *
     * A source counts here the moment it's validated — there is no separate
     * manual "enabled" step (see AGENTS.md's "Enabled Toggle Eliminated"
     * entry; the `enabled` column is no longer read for this decision
     * anywhere in the plugin). Reads directly from wp_mmi_data_sources (the
     * authoritative source for the UI) and falls back to the legacy
     * mmi_pipeline_enabled_suppliers option when the table doesn't exist
     * (e.g. fresh install before migration).
     */
    private function get_enabled_suppliers(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
            $ids = $wpdb->get_col( "SELECT supplier_id FROM {$table} WHERE config_status = 'validated' ORDER BY display_order ASC" );
            if ( is_array( $ids ) && count( $ids ) > 0 ) {
                return $ids;
            }
        }
        // Fallback: legacy option (kept in sync by mmi_ds_sync_enabled_suppliers()).
        return MMI_DB::get_setting( 'mmi_pipeline_enabled_suppliers', [] );
    }

    /**
     * Count total products across all enabled suppliers without building the full list.
     *
     * @param string|null $profile_id When given and the profile has a non-empty
     *                    'sources' array, the enabled-suppliers list is scoped to
     *                    just those — otherwise (legacy profiles with no sources
     *                    assigned) every enabled supplier is counted, unchanged
     *                    from prior behavior. Without this, a profile whose
     *                    pre-flight check runs here would report totals belonging
     *                    to unrelated suppliers whenever more than one is enabled.
     */
    private function count_all_products( ?string $profile_id = null ): int {
        $json_path        = mmi_shared_lib_json_dir();
        $enabled_suppliers = $this->get_enabled_suppliers();
        if ( $profile_id !== null ) {
            $profile_sources = MMI_DB::get_profiles()[ $profile_id ]['sources'] ?? [];
            if ( ! empty( $profile_sources ) ) {
                $enabled_suppliers = array_values( array_intersect( $enabled_suppliers, $profile_sources ) );
            }
        }
        $total            = 0;

        foreach ($enabled_suppliers as $supplier) {
            $filename = $supplier === 'xchange' ? 'xchange-products.json' : "{$supplier}-products.json";
            $file     = $json_path . $filename;
            if (!file_exists($file)) {
                continue;
            }
            $decoded  = json_decode(file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }
            $products = $decoded['products'] ?? $decoded;
            if (is_array($products)) {
                $total += count($products);
            }
        }

        return $total;
    }

    /**
     * AJAX endpoint — process one time-bounded import batch and return updated progress.
     * JS calls this repeatedly to drive the import loop without relying on WP-Cron.
     */
    public function handle_process_import_batch(): void {
        check_ajax_referer('mmi_pipeline_nonce', 'nonce');
        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }

        // Ensure the batch can never be killed by PHP's max_execution_time in a web context.
        // Each batch is bounded by IMPORT_TIME_BUDGET (8 s), so this will complete quickly.
        @set_time_limit(300);

        // Keep processing even if the browser refreshes or closes mid-batch.
        // Without this, nginx/PHP-FPM will abort the script when the HTTP connection
        // is reset, leaving progress at the last DB-persisted offset (often 0).
        ignore_user_abort(true);

        $this->process_import_batch_cron();
        $progress = MMI_DB::get_job_state(Batch_Import_State::PROGRESS_KEY);
        if (!$progress) {
            wp_send_json_success(['status' => 'idle']);
            return;
        }
        unset($progress['field_stats']);
        wp_send_json_success($progress);
    }

    /**
     * AJAX endpoint — abort a running import.
     * Sets state to 'aborted', releases the lock, and writes a history entry.
     */
    public function handle_abort_import(): void {
        check_ajax_referer('mmi_pipeline_nonce', 'nonce');
        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
            return;
        }
        if (!MMI_DB::get_job_state(Batch_Import_State::LOCK_KEY)) {
            wp_send_json_error(['message' => 'No import is currently running.']);
            return;
        }
        $progress       = MMI_DB::get_job_state(Batch_Import_State::PROGRESS_KEY);
        $run_id         = (int) ($progress['run_id']   ?? 0);
        $total_imported = (int) ($progress['imported'] ?? 0);
        $total_updated  = (int) ($progress['updated']  ?? 0);
        $total_skipped  = (int) ($progress['skipped']  ?? 0);
        $total_unchanged = (int) ($progress['unchanged'] ?? 0);
        $total_failed   = (int) ($progress['failed']   ?? 0);
        MMI_DB::set_job_state(Batch_Import_State::PROGRESS_KEY, [
            'status'    => 'aborted',
            'run_id'    => $run_id,
            'imported'  => $total_imported,
            'updated'   => $total_updated,
            'unchanged' => $total_unchanged,
            'skipped'   => $total_skipped,
            'failed'    => $total_failed,
        ], 3600);
        MMI_DB::delete_job_state(Batch_Import_State::LOCK_KEY);
        if ($run_id) {
            MMI_DB::update_import_history($run_id, [
                'completed_at' => current_time('mysql'),
                'status'       => 'Aborted',
                'imported'     => $total_imported,
                'updated'      => $total_updated,
                'skipped'      => $total_skipped + $total_unchanged,
                'errors'       => $total_failed,
                'notes'        => wp_json_encode(['aborted' => true]),
            ]);
        }
        mmi_data_pipeline_audit('import.abort', [
            'object_type' => 'import_run',
            'object_id'   => $run_id,
            'outcome'     => 'success',
        ]);
        wp_send_json_success(['status' => 'aborted']);
    }

    /**
     * Fire a non-blocking HTTP POST to wp-cron.php to trigger due cron events.
     * NOTE: This will have no effect when DISABLE_WP_CRON is true (the default on
     * this server). Import batching is now JS-driven via handle_process_import_batch.
     */
    private function spawn_import_cron(): void {
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return; // wp-cron.php immediately dies when this constant is true
        }
        $cron_url = add_query_arg('doing_wp_cron', '1', site_url('wp-cron.php'));
        wp_remote_post($cron_url, [
            'timeout'    => 0.01,
            'blocking'   => false,
            'sslverify'  => apply_filters('https_local_ssl_verify', false),
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ]);
    }

    /**
     * Get import history
     */
    public function get_import_history() {
        check_ajax_referer('mmi_pipeline_nonce', 'nonce');
        
        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $history = MMI_DB::get_import_history( 50 );
        wp_send_json_success(['history' => $history]);
    }

    /**
     * Single-product import logic (create/update dispatch, field mapping,
     * dirty-checking, distribution taxonomy assignment) now lives in
     * Product_Import_Worker.
     */

    
    /**
     * Field path extraction and transform application now live in
     * MMI_Pipeline_Field_Resolver::get_nested_value() / ::apply_transform() —
     * single canonical implementation shared with the importer and the
     * preview generator.
     */

    /**
     * Get sample product data from any available supplier
     */
    private function get_sample_product_data() {
        $json_path = mmi_shared_lib_json_dir();
        
        $suppliers = [
            'skuport-products.json',
            'xchange-products.json',
            'plugivery-products.json'
        ];
        
        foreach ($suppliers as $filename) {
            $file = $json_path . $filename;
            
            if (file_exists($file)) {
                $data = json_decode(file_get_contents($file), true);
                
                if (is_array($data) && !empty($data)) {
                    // Xchange format: { products: [...], api_time: ..., debug: ... }
                    if (isset($data['products']) && is_array($data['products']) && !empty($data['products'])) {
                        return $data['products'][0];
                    }
                    // Skuport/Plugivery format: [...] (array at root)
                    return $data[0];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Add entry to import history
     */
    private function add_import_history_entry($entry): int|false {
        return MMI_DB::append_import_history( $entry );
    }
}

// Initialize controller
new ProductImportController();
