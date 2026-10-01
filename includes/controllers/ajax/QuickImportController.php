<?php
/**
 * Quick Import AJAX Controller
 *
 * Backs the "Quick Import" wizard (upload → curated field mapping → primary
 * key → name/mode → save/run), a streamlined alternative entry point to the
 * existing "Add Data Source" + "Create Import Profile" flow. That existing
 * flow stays untouched for recurring API-integration use — this controller
 * only collapses "create a Data Source row from an already-uploaded file,
 * then fetch it" into one request, and batches the wizard's curated
 * field-mapping + primary-key writes into one request, so the wizard's UX
 * goal ("upload → see it working") doesn't need N sequential AJAX round
 * trips to plumbing the user never asked to see.
 *
 * The resulting Data Source row and Import Profile are ordinary, persistent,
 * fully-functional objects afterward — editable via the existing Edit
 * Profile modal and Data Sources tab exactly like ones created the long way.
 * Nothing here is deleted or hidden after the wizard closes.
 *
 * @package MannMade\DataPipeline\Controllers\AJAX
 */

namespace MannMade\DataPipeline\Controllers\AJAX;

use MMI_DB;
use MMI_Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create (or reuse) a Data Source row for an already-uploaded file, then
 * fetch/parse it into the JSON cache the importer and field-browse UI both
 * depend on — all in one request.
 *
 * Deliberately NOT built on top of wp_ajax_mmi_add_data_source: that handler
 * always inserts config_status='unconfigured', pending a separate "Test
 * Connection" step to reach 'validated' (the one state that makes a source
 * eligible for imports — see AGENTS.md's "Enabled Toggle Eliminated" entry).
 * Special-casing that shared endpoint for this wizard would risk the existing
 * 5-tile Add Source modal's own flow, so this is a dedicated endpoint instead
 * — inserting config_status='validated' directly is safe here because the
 * file has already been synchronously parsed by mmi_analyze_uploaded_file in
 * the step before this one, which is strictly more verification than a Test
 * Connection would add.
 */
add_action('wp_ajax_mmi_pipeline_quick_create_source', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $attachment_id = absint($_POST['attachment_id'] ?? 0);
    $format        = sanitize_text_field($_POST['format'] ?? 'json');
    $supplier_name = sanitize_text_field($_POST['supplier_name'] ?? '');

    if ($attachment_id <= 0) {
        wp_send_json_error(['message' => 'Missing uploaded file.']);
        return;
    }
    if ($supplier_name === '') {
        wp_send_json_error(['message' => 'A source name is required.']);
        return;
    }
    $allowed_formats = ['json', 'csv', 'tsv', 'xml'];
    if (!in_array($format, $allowed_formats, true)) {
        wp_send_json_error(['message' => "Unsupported format '{$format}' for Quick Import."]);
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        wp_send_json_error(['message' => 'Data sources table not found. Please deactivate and reactivate the MMI Hub plugin.']);
        return;
    }

    // Slugify the name into a supplier_id, de-duping on collision — same
    // uniqueness convention as wp_ajax_mmi_add_data_source.
    $base_id     = sanitize_key(preg_replace('/[^a-z0-9]+/i', '-', strtolower($supplier_name)));
    $base_id     = trim($base_id, '-') ?: 'quick-import';
    $supplier_id = $base_id;
    $suffix      = 2;
    while ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = %s", $supplier_id))) {
        $supplier_id = $base_id . '-' . $suffix;
        $suffix++;
    }

    $max_order = (int) $wpdb->get_var("SELECT MAX(display_order) FROM {$table}");
    $now       = current_time('mysql');

    $configuration = [
        'upload_attachment_id' => $attachment_id,
        'timeout'              => 60,
        'retries'              => 3,
        'cache_duration'       => 0,
        'detect_changes'       => true,
    ];
    $file_config = [
        'format'         => $format,
        'delimiter'      => ',',
        'has_header'     => true,
        'encoding'       => 'UTF-8',
        'data_root_path' => '',
    ];
    $auth_config = [
        'type'            => 'none',
        'credential_keys' => [],
        'credential_tab'  => 'Credentials & API Keys',
    ];

    $inserted = $wpdb->insert($table, [
        'supplier_id'       => $supplier_id,
        'supplier_name'     => $supplier_name,
        'source_type'       => 'upload',
        'configuration'     => wp_json_encode($configuration),
        'auth_config'       => wp_json_encode($auth_config),
        'file_config'       => wp_json_encode($file_config),
        // Set directly (see docblock) — this is a deliberate, narrow bypass
        // of the normal Test-Connection-first enable gate, not a bug.
        'enabled'           => 1,
        'config_status'     => 'validated',
        'last_validated_at' => $now,
        'display_order'     => $max_order + 1,
        'created_at'        => $now,
        'updated_at'        => $now,
    ]);

    if ($inserted === false) {
        wp_send_json_error(['message' => 'Failed to create the source record.']);
        return;
    }

    mmi_data_pipeline_audit('source.create', [
        'object_type' => 'data_source',
        'object_id'   => $supplier_id,
        'outcome'     => 'success',
        'details'     => ['source_type' => 'upload', 'attachment_id' => $attachment_id, 'trigger' => 'quick_import'],
    ]);

    // This source was just created as source_type => 'upload' above, so it
    // needs no network fetch — the file is already on disk via its WP
    // attachment — and doesn't wait on Data_Source_Manager (see
    // MMI_Pipeline_Upload_Source_Fetcher's docblock for why that class can't
    // be relied on here).
    if (!class_exists('MMI_Pipeline_Upload_Source_Fetcher')) {
        wp_send_json_error(['message' => 'Data source manager unavailable.', 'supplier_id' => $supplier_id]);
        return;
    }

    try {
        $data = \MMI_Pipeline_Upload_Source_Fetcher::fetch($supplier_id);
        $count = is_array($data) ? count($data) : 0;

        $wpdb->update($table, [
            'last_fetch_at'     => current_time('mysql'),
            'last_fetch_status' => 'success',
            'last_fetch_count'  => $count,
            'updated_at'        => current_time('mysql'),
        ], ['supplier_id' => $supplier_id]);

        MMI_Logger::info(
            "Quick Import: created source '{$supplier_id}' from upload, {$count} record(s) fetched",
            ['supplier_id' => $supplier_id, 'count' => $count],
            'general',
            'QuickImportController'
        );

        wp_send_json_success([
            'supplier_id'     => $supplier_id,
            'supplier_name'   => $supplier_name,
            'row_count'       => $count,
            'sample_products' => array_slice($data, 0, 3),
        ]);
    } catch (\Throwable $e) {
        // The row already exists (0 products is harmless — enabled=1 with no
        // cache file simply contributes nothing to any import) so the wizard
        // can offer "retry fetch" without forcing the user to re-upload.
        $wpdb->update($table, [
            'last_fetch_at'     => current_time('mysql'),
            'last_fetch_status' => 'error',
            'updated_at'        => current_time('mysql'),
        ], ['supplier_id' => $supplier_id]);

        MMI_Logger::error(
            "Quick Import: fetch failed for source '{$supplier_id}': " . $e->getMessage(),
            ['supplier_id' => $supplier_id],
            'general',
            'QuickImportController'
        );

        wp_send_json_error([
            'supplier_id' => $supplier_id,
            'message'     => 'Could not read the uploaded file: ' . $e->getMessage(),
        ]);
    }
});

/**
 * Batch-save the wizard's curated field mappings + primary key for one
 * profile/supplier in a single request, instead of the ~10 sequential
 * autosave calls the full Field Mapping / Primary Key panels would need for
 * the same set of fields (one get_field_mappings()+set_field_mappings()
 * round trip per field otherwise).
 */
add_action('wp_ajax_mmi_pipeline_quick_save_mapping', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
        return;
    }

    $profile_id  = sanitize_text_field($_POST['profile_id'] ?? '');
    $supplier_id = sanitize_text_field($_POST['supplier_id'] ?? '');

    if ($profile_id === '' || $supplier_id === '') {
        wp_send_json_error(['message' => 'Missing profile or source.']);
        return;
    }

    $profiles = MMI_DB::get_profiles();
    if (!isset($profiles[$profile_id])) {
        wp_send_json_error(['message' => 'Unknown profile.']);
        return;
    }

    $raw_mappings = isset($_POST['mappings']) ? json_decode(stripslashes($_POST['mappings']), true) : null;
    if (!is_array($raw_mappings)) {
        wp_send_json_error(['message' => 'Missing field mappings.']);
        return;
    }

    $mappings = MMI_DB::get_field_mappings($profile_id);
    if (!is_array($mappings)) {
        $mappings = [];
    }

    foreach ($raw_mappings as $row) {
        if (!is_array($row) || empty($row['field_name'])) {
            continue;
        }
        $field_name = sanitize_key($row['field_name']);

        if (!isset($mappings[$field_name]) || !is_array($mappings[$field_name])) {
            $mappings[$field_name] = [];
        }

        // Constant-value fields (e.g. _manage_stock=yes whenever _stock is
        // mapped) apply regardless of supplier/enabled — same mechanism the
        // full Field Mapping panel uses via mmi_autosave_field_property.
        if (!empty($row['use_constant_value'])) {
            $mappings[$field_name]['use_constant_value'] = true;
            $mappings[$field_name]['constant_value']     = sanitize_text_field($row['constant_value'] ?? '');
            continue;
        }

        $source  = sanitize_text_field($row['source_path'] ?? '');
        $enabled = !empty($row['enabled']);

        if (!isset($mappings[$field_name]['source']) || !is_array($mappings[$field_name]['source'])) {
            $mappings[$field_name]['source'] = [];
        }
        if (!isset($mappings[$field_name]['enabled']) || !is_array($mappings[$field_name]['enabled'])) {
            $mappings[$field_name]['enabled'] = [];
        }
        $mappings[$field_name]['source'][$supplier_id]  = $source;
        $mappings[$field_name]['enabled'][$supplier_id] = $enabled;
    }

    MMI_DB::set_field_mappings($profile_id, $mappings);

    // Primary key: source field + WC match target (SKU / Post ID / Post
    // Title / custom meta key) — same sentinel convention as the Import
    // Profile wizard's Step 2 (Source) primary-key editor (section-profile-wizard.php).
    $pk_source      = sanitize_text_field($_POST['primary_key_source'] ?? 'id');
    $pk_match_type  = sanitize_text_field($_POST['primary_key_wc_match_type'] ?? 'sku');
    $pk_custom_meta = sanitize_key($_POST['primary_key_wc_custom'] ?? '');

    $wc_sentinels = [
        'sku'        => '_sku',
        'post_id'    => '__post_id',
        'post_title' => '__post_title',
    ];
    $pk_wc_value = $wc_sentinels[$pk_match_type] ?? ($pk_custom_meta !== '' ? $pk_custom_meta : '_sku');

    MMI_DB::set_primary_key($supplier_id, 'source', $pk_source ?: 'id');
    MMI_DB::set_primary_key($supplier_id, 'wc', $pk_wc_value);

    wp_send_json_success([
        'profile_id'  => $profile_id,
        'supplier_id' => $supplier_id,
    ]);
});
