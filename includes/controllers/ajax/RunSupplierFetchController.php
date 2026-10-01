<?php

namespace MannMade\DataPipeline\Controllers\AJAX;

if (!defined('ABSPATH')) {
    exit;
}

use MMI_DB;
use MMI_Logger;
use MMI_Pipeline_Supplier_Fetch_Runner;

/**
 * Run Supplier Fetch AJAX Controller
 * Handles manual supplier data fetching from the UI
 */

add_action('wp_ajax_mmi_run_supplier_fetch', function () {
    try {
        check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        // Get options
        $options = isset($_POST['options']) ? $_POST['options'] : [];
        $plugiveryFull = !empty($options['plugivery_full']);
        $selected_suppliers = isset($_POST['suppliers']) && is_array($_POST['suppliers'])
            ? array_map('sanitize_text_field', $_POST['suppliers'])
            : [];

        mmi_data_pipeline_audit('source.fetch', [
            'object_type' => 'data_source',
            'outcome'     => 'success',
            'details'     => ['trigger' => 'manual', 'suppliers' => $selected_suppliers ?: ['all'], 'plugivery_full' => $plugiveryFull],
        ]);

        // Update tracking
        MMI_DB::set_setting( 'mmi_supplier_fetch_last_run', current_time( 'mysql' ) );
        MMI_DB::increment_setting( 'mmi_supplier_fetch_runs' );

        // Legacy suppliers that have dedicated updater classes
        $legacy_suppliers = ['xchange', 'skuport', 'plugivery'];
        $results = [];
        $output = '';

        // Split selected suppliers into legacy and dynamic
        $legacy_selected = [];
        $dynamic_selected = [];

        if (empty($selected_suppliers)) {
            // No specific selection = run all via legacy runner (backward compatible)
            $legacy_selected = $legacy_suppliers;
        } else {
            foreach ($selected_suppliers as $sid) {
                if (in_array($sid, $legacy_suppliers)) {
                    $legacy_selected[] = $sid;
                } else {
                    $dynamic_selected[] = $sid;
                }
            }
        }

        // Run legacy fetch for built-in suppliers
        if (!empty($legacy_selected)) {
            if (class_exists(MMI_Pipeline_Supplier_Fetch_Runner::class)) {
                $runner = new MMI_Pipeline_Supplier_Fetch_Runner();
                ob_start();
                $runner->runAll($plugiveryFull);
                $output .= ob_get_clean();
                $results['legacy'] = 'completed';

                // Update fetch metadata for legacy suppliers in wp_mmi_data_sources
                global $wpdb;
                $ds_table = $wpdb->prefix . 'mmi_data_sources';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$ds_table}'") === $ds_table) {
                    foreach ($legacy_selected as $sid) {
                        $json_dir = mmi_shared_lib_json_dir();
                        $json_file = $json_dir . $sid . '-products.json';
                        if ($sid === 'xchange') {
                            $json_file = $json_dir . 'xchange-products.json';
                        }

                        $fetch_count = 0;
                        if (file_exists($json_file)) {
                            $data = json_decode(file_get_contents($json_file), true);
                            if (is_array($data)) {
                                // Top-level array — every element is a product
                                if (isset($data[0])) {
                                    $fetch_count = count($data);
                                // Wrapper object — use known product-array sub-keys
                                } elseif (isset($data['products']) && is_array($data['products'])) {
                                    $fetch_count = count($data['products']);
                                } elseif (isset($data['data']) && is_array($data['data'])) {
                                    $fetch_count = count($data['data']);
                                } elseif (isset($data['items']) && is_array($data['items'])) {
                                    $fetch_count = count($data['items']);
                                } else {
                                    // Flat SKU-keyed dict — exclude known metadata keys
                                    $meta_keys = ['wa_count','debug','timezone','server_zone','zone_offset','api_time','status','metadata','meta'];
                                    $fetch_count = count(array_diff_key($data, array_flip($meta_keys)));
                                }
                            }
                        }

                        $wpdb->update($ds_table, [
                            'last_fetch_at'     => current_time('mysql'),
                            'last_fetch_status'  => 'success',
                            'last_fetch_count'   => $fetch_count,
                            'updated_at'         => current_time('mysql'),
                        ], ['supplier_id' => $sid]);

                        // NOTE: fetch_count and consecutive_failures columns do not exist in this schema.
                        // last_fetch_at / last_fetch_count are sufficient for the status card.
                    }
                }
            } else {
                $output .= "WARNING: SupplierFetchRunner not available for legacy suppliers.\n";
                $results['legacy'] = 'unavailable';
            }
        }

        // Run dynamic fetch for non-legacy suppliers. Upload sources need no
        // network fetch at all — the file is already on disk via its WP
        // attachment — so they don't wait on Data_Source_Manager (see
        // MMI_Pipeline_Upload_Source_Fetcher's docblock for why that class
        // can't be relied on for the other dynamic source types below).
        if (!empty($dynamic_selected)) {
            $dsm_available = class_exists('MannMade\\Integrations\\Acquisition\\Data_Source_Manager');
            $upload_available = class_exists('MMI_Pipeline_Upload_Source_Fetcher');

            foreach ($dynamic_selected as $supplier_id) {
                $is_upload = $upload_available && \MMI_Pipeline_Upload_Source_Fetcher::is_upload_source($supplier_id);

                if (!$is_upload && !$dsm_available) {
                    $output .= "WARNING: No fetch mechanism available for '{$supplier_id}'.\n";
                    continue;
                }

                try {
                    if ($is_upload) {
                        $data = \MMI_Pipeline_Upload_Source_Fetcher::fetch($supplier_id);
                    } else {
                        $data = \MannMade\Integrations\Acquisition\Data_Source_Manager::instance()->fetch_from_supplier($supplier_id);
                    }
                    $count = is_array($data) ? count($data) : 0;
                    $output .= "SUCCESS: {$supplier_id} - {$count} items fetched\n";
                    $results[$supplier_id] = 'success';

                    // Update fetch metadata
                    global $wpdb;
                    $ds_table = $wpdb->prefix . 'mmi_data_sources';
                    $wpdb->update($ds_table, [
                        'last_fetch_at'    => current_time('mysql'),
                        'last_fetch_status' => 'success',
                        'last_fetch_count'  => $count,
                        'updated_at'        => current_time('mysql'),
                    ], ['supplier_id' => $supplier_id]);

                    // NOTE: fetch_count and consecutive_failures columns do not exist in this schema.
                } catch (\Throwable $e) {
                    $output .= "ERROR: {$supplier_id} - {$e->getMessage()}\n";
                    $results[$supplier_id] = 'error';

                    global $wpdb;
                    $ds_table = $wpdb->prefix . 'mmi_data_sources';
                    $wpdb->update($ds_table, [
                        'last_fetch_at'    => current_time('mysql'),
                        'last_fetch_status' => 'failed',
                        'updated_at'        => current_time('mysql'),
                    ], ['supplier_id' => $supplier_id]);

                    // NOTE: consecutive_failures column does not exist in this schema.
                }
            }
        }

        wp_send_json_success([
            'message' => 'Supplier fetch completed',
            'result' => 'Completed',
            'output' => $output,
            'results' => $results,
        ]);
    } catch (\Exception $e) {
        MMI_Logger::error( '[MMI Pipeline] Supplier Fetch Error: ' . $e->getMessage(), [], 'integrations', 'MMI_Pipeline_Run_Supplier_Fetch' );
        MMI_DB::increment_setting( 'mmi_supplier_fetch_errors' );
        wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
    } catch (\Throwable $e) {
        MMI_Logger::error( '[MMI Pipeline] Supplier Fetch Fatal Error: ' . $e->getMessage(), [], 'integrations', 'MMI_Pipeline_Run_Supplier_Fetch' );
        MMI_DB::increment_setting( 'mmi_supplier_fetch_errors' );
        wp_send_json_error(['message' => 'Fatal error: ' . $e->getMessage()]);
    }
});

add_action('wp_ajax_mmi_pipeline_fetch_status', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can( 'manage_options' )) {
        wp_send_json_error(['message' => 'Unauthorized']);
        return;
    }
    
    $running = MMI_DB::get_job_state( 'mmi_supplier_fetch_running' );
    $status = MMI_DB::get_job_state( 'mmi_supplier_fetch_status' );
    
    wp_send_json_success([
        'running' => (bool) $running,
        'status' => $status ?: 'idle',
        'last_run' => MMI_DB::get_setting( 'mmi_supplier_fetch_last_run' ),
        'runs' => MMI_DB::get_setting( 'mmi_supplier_fetch_runs', 0 ),
        'errors' => MMI_DB::get_setting( 'mmi_supplier_fetch_errors', 0 ),
    ]);
});

add_action('wp_ajax_mmi_pipeline_fetch_cancel', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');
    
    if (!mmi_data_pipeline_user_can( 'manage_options' )) {
        wp_send_json_error(['message' => 'Unauthorized']);
        return;
    }
    
    // Clear transients
    MMI_DB::delete_job_state( 'mmi_supplier_fetch_running' );
    MMI_DB::delete_job_state( 'mmi_supplier_fetch_status' );
    
    wp_send_json_success([
        'message' => 'Supplier fetch cancelled',
    ]);
});

add_action('wp_ajax_mmi_pipeline_fetch_log', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can( 'manage_options' )) {
        wp_send_json_error(['message' => 'Unauthorized']);
        return;
    }

    $log_file = MMI_PIPELINE_PATH . 'storage/logs/supplier-fetch.log';
    
    if (!file_exists($log_file)) {
        wp_send_json_success(['log' => 'No log file yet...']);
        return;
    }

    $log_content = file_get_contents($log_file);
    
    // Get last 100 lines
    $lines = explode("\n", $log_content);
    $lines = array_slice($lines, -100);
    $log_content = implode("\n", $lines);

    wp_send_json_success(['log' => $log_content]);
});

