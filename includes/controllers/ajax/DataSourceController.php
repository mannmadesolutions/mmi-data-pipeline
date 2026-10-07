<?php
/**
 * Data Source AJAX Controller
 * Comprehensive management of data sources: CRUD, testing, validation, health monitoring
 *
 * @package MannMade\DataPipeline\Controllers\AJAX
 * @since 1.6.0
 */

namespace MannMade\DataPipeline\Controllers\AJAX;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Recalculates a supplier's Review & Compare "Create Only" source-data filter
 * schema (see \MMI_Pipeline_Config_Validator::calculate_filterable_fields())
 * right after mmi_test_data_source() marks it validated. Best-effort — a
 * source that's only just been connectivity-tested may not have a real
 * {supplier}-products.json feed cached yet (that's built separately, by
 * MMI_Pipeline_Upload_Source_Fetcher::fetch() for uploads, or a scheduled
 * updater for API sources); calculate_filterable_fields() already no-ops
 * gracefully when the feed file doesn't exist yet, and
 * get_filterable_fields()'s lazy fallback picks it up the first time the
 * Review & Compare filter bar actually asks for it.
 */
function mmi_pipeline_trigger_filterable_fields_calc(string $supplier_id): void {
    if (!class_exists('MMI_Pipeline_Config_Validator')) {
        return;
    }
    $json_dir = mmi_shared_lib_json_dir();
    try {
        \MMI_Pipeline_Config_Validator::calculate_filterable_fields($supplier_id, $json_dir);
    } catch (\Throwable $e) {
        if (class_exists('MMI_Logger')) {
            \MMI_Logger::warn(
                'Failed to calculate filterable-fields schema after source validation: ' . $e->getMessage(),
                ['supplier' => $supplier_id],
                'validation',
                'DataSourceController'
            );
        }
    }
}

// ─── File Analysis ──────────────────────────────────────────────────────────

/**
 * Upload a file, save it to the WP media library, and analyze its contents.
 * Returns format, row count estimate, and column names to allow immediate
 * feedback in the "Add New Data Source" modal before the source is created.
 */
add_action('wp_ajax_mmi_analyze_uploaded_file', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
        wp_send_json_error(['message' => 'No file received']);
    }

    $file_info = $_FILES['file'];
    $ext       = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
    $allowed   = ['csv', 'tsv', 'json', 'xml', 'txt', 'xls', 'xlsx'];

    if (!in_array($ext, $allowed, true)) {
        wp_send_json_error(['message' => "Unsupported file type (.{$ext}). Accepted: " . implode(', ', $allowed)]);
    }

    // Load WP upload helpers
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // The attachment ID is the handle the source config and fetcher use; the
    // file itself is moved into private storage right after upload (see
    // MMI_Pipeline_Private_Uploads). The random suffix covers the moment
    // before that move. The original name still feeds the suggested
    // supplier name below.
    $_FILES['file']['name'] = sanitize_file_name(pathinfo($file_info['name'], PATHINFO_FILENAME))
        . '-' . strtolower(wp_generate_password(12, false, false)) . '.' . $ext;

    $attachment_id = media_handle_upload('file', 0);

    if (is_wp_error($attachment_id)) {
        wp_send_json_error(['message' => 'Upload failed: ' . $attachment_id->get_error_message()]);
    }

    // Supplier feeds (dealer costs, contacts) must never be downloadable by URL.
    $private = MMI_Pipeline_Private_Uploads::privatize((int) $attachment_id);
    if (is_wp_error($private)) {
        wp_delete_attachment((int) $attachment_id, true);
        wp_send_json_error(['message' => 'Upload failed: could not store the file privately (' . $private->get_error_message() . ').']);
    }

    mmi_data_pipeline_audit('file.upload', [
        'object_type' => 'attachment',
        'object_id'   => (int) $attachment_id,
        'outcome'     => 'success',
        'details'     => ['extension' => $ext, 'bytes' => (int) ($file_info['size'] ?? 0)],
    ]);

    $file_path = get_attached_file($attachment_id);
    $filename  = basename($file_path);

    // ── Detect format ────────────────────────────────────────────
    $format = $ext;
    if ($ext === 'txt') {
        $peek    = file_get_contents($file_path, false, null, 0, 2048) ?: '';
        $decoded = json_decode($peek, true);
        $format  = ($decoded !== null || json_last_error() === JSON_ERROR_NONE) ? 'json' : 'csv';
    }
    if ($ext === 'xls' || $ext === 'xlsx') {
        $format = 'excel';
    }

    // ── Analyze row count + columns + a small data preview ────────
    // sample_rows lets the "Add Data Source" modal show the user actual
    // parsed values (not just column names) before they commit to creating
    // the source, so they can confirm they uploaded the right file.
    $row_count    = null;
    $columns      = [];
    $sample_rows  = [];
    $sample_limit = 5;

    switch ($format) {
        case 'json':
            $content = file_get_contents($file_path);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $first = isset($data[0]) ? $data[0] : null;
                    if ($first !== null) {
                        $row_count = count($data);
                        if (is_array($first)) {
                            $columns = array_keys($first);
                        }
                        foreach (array_slice($data, 0, $sample_limit) as $row) {
                            $sample_rows[] = is_array($row) ? array_slice($row, 0, 10, true) : ['value' => $row];
                        }
                    } else {
                        // Single-object JSON
                        $row_count     = 1;
                        $columns       = array_keys($data);
                        $sample_rows[] = array_slice($data, 0, 10, true);
                    }
                }
            }
            break;

        case 'csv':
        case 'tsv':
            $delimiter = ($format === 'tsv') ? "\t" : ',';
            $handle    = fopen($file_path, 'r');
            if ($handle) {
                $headers = fgetcsv($handle, 0, $delimiter);
                if (is_array($headers)) {
                    $trimmed_headers = array_map('trim', $headers);
                    $columns         = array_filter($trimmed_headers);
                    $row_count       = 0;
                    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                        if ($row_count < $sample_limit) {
                            $padded = array_pad(array_slice($row, 0, count($trimmed_headers)), count($trimmed_headers), '');
                            $sample_rows[] = array_slice(array_combine($trimmed_headers, $padded), 0, 10, true);
                        }
                        $row_count++;
                    }
                }
                fclose($handle);
            }
            break;

        case 'xml':
            libxml_use_internal_errors(true);
            $xml = simplexml_load_file($file_path, 'SimpleXMLElement', LIBXML_NONET);
            if ($xml !== false) {
                $children  = $xml->children();
                $row_count = count($children);
                $first_el  = $children[0] ?? null;
                if ($first_el) {
                    foreach ($first_el->children() as $key => $val) {
                        $columns[] = $key;
                    }
                }
                $i = 0;
                foreach ($children as $child) {
                    if ($i >= $sample_limit) {
                        break;
                    }
                    $row = [];
                    foreach ($child->children() as $key => $val) {
                        if (count($row) >= 10) {
                            break;
                        }
                        $row[$key] = (string) $val;
                    }
                    $sample_rows[] = $row;
                    $i++;
                }
            }
            break;

        default:
            // Excel / unknown — cannot analyze without a library
            break;
    }

    // ── Suggested supplier name from filename ────────────────────
    $name_base      = pathinfo($file_info['name'], PATHINFO_FILENAME);
    $suggested_name = ucwords(str_replace(['-', '_', '.'], ' ', $name_base));

    wp_send_json_success([
        'attachment_id'  => $attachment_id,
        'filename'       => $filename,
        'format'         => $format,
        'row_count'      => $row_count,
        'columns'        => array_values(array_slice($columns, 0, 10)),
        'sample_rows'    => $sample_rows,
        'suggested_name' => $suggested_name,
    ]);
});

// ─── CRUD Operations ────────────────────────────────────────────────────────

/**
 * Get all data sources
 */
add_action('wp_ajax_mmi_get_data_sources', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        wp_send_json_error(['message' => 'Data sources table not found. Please deactivate and reactivate the MMI Hub plugin.']);
    }

    $sources = $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY display_order ASC, supplier_name ASC",
        ARRAY_A
    );

    // Decode JSON columns
    foreach ($sources as &$source) {
        $source['configuration'] = json_decode($source['configuration'] ?: '{}', true);
        $source['auth_config'] = json_decode($source['auth_config'] ?: '{}', true);
        $source['file_config'] = json_decode($source['file_config'] ?: '{}', true);
        // Strip sensitive credential values from auth_config before sending to frontend
        if (isset($source['auth_config']['credential_keys'])) {
            $source['auth_config']['has_credentials'] = true;
        }
    }

    // Load endpoints for each source
    $endpoints_table = $wpdb->prefix . 'mmi_data_source_endpoints';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$endpoints_table}'") === $endpoints_table) {
        foreach ($sources as &$source) {
            $source['endpoints'] = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$endpoints_table} WHERE source_id = %d ORDER BY is_primary DESC, id ASC",
                $source['id']
            ), ARRAY_A);
        }
    }

    wp_send_json_success($sources);
});

/**
 * Get single data source configuration
 */
add_action('wp_ajax_mmi_get_data_source', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $supplier_id = sanitize_text_field($_POST['supplier_id'] ?? '');
    if (empty($supplier_id)) {
        wp_send_json_error(['message' => 'Supplier ID required']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';
    $endpoints_table = $wpdb->prefix . 'mmi_data_source_endpoints';

    $source = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE supplier_id = %s",
        $supplier_id
    ), ARRAY_A);

    if (!$source) {
        wp_send_json_error(['message' => 'Data source not found']);
    }

    $source['configuration'] = json_decode($source['configuration'] ?: '{}', true);
    $source['auth_config']   = json_decode($source['auth_config'] ?: '{}', true);
    $source['file_config']   = json_decode($source['file_config'] ?: '{}', true);
    // Hoist notes + documentation_url to root level so JS reads s.notes / s.documentation_url
    $source['documentation_url'] = $source['configuration']['documentation_url'] ?? '';
    $source['notes']             = $source['configuration']['notes'] ?? '';

    // For Dropbox: expose a boolean flag so the JS masked-token display knows
    // a token exists in the vault without sending the actual value.
    if ($source['source_type'] === 'dropbox') {
        $cred_field = $source['supplier_id'] . '-dropbox_access_token';
        $mmi_table  = class_exists( 'MMI_Settings' ) ? \MMI_Settings::ensure_table() : $wpdb->prefix . 'mmi';
        $source['configuration']['dropbox_has_token'] = (
            $wpdb->get_var("SHOW TABLES LIKE '{$mmi_table}'") === $mmi_table &&
            (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$mmi_table} WHERE tab_name = 'Credentials & API Keys' AND field_name = %s",
                $cred_field
            ))
        );
    }

    // Migrate legacy api_url from timed_token auth_config → base_url in configuration.
    // Old records stored the API base URL as auth_config.api_url; new records store it in
    // configuration.base_url. Promote the value so the Connection tab shows it correctly.
    if (empty($source['configuration']['base_url']) && !empty($source['auth_config']['api_url'])) {
        $source['configuration']['base_url'] = $source['auth_config']['api_url'];
    }

    // Preconfigured templates resolve base_url/token_url from the credential vault
    // (the same source XchangeUpdater reads). Rows created before the vault keys were
    // populated have empty stored values, leaving Token Endpoint URL blank so the admin
    // can't Test Connection. Re-resolve from the vault at load so it self-heals.
    // Fall back to supplier_id when preconfigured_template wasn't recorded (rows
    // created before this field existed) — for every built-in supplier the
    // template array key equals the supplier_id, so this still resolves
    // correctly; for unknown/custom suppliers the lookup below just no-ops.
    $template_name = $source['configuration']['preconfigured_template'] ?: $source['supplier_id'];
    if ($template_name && function_exists('mmi_ds_get_preconfigured_templates')) {
        $template = mmi_ds_get_preconfigured_templates()[$template_name] ?? null;
        if ($template) {
            $mmi_table = class_exists( 'MMI_Settings' ) ? \MMI_Settings::ensure_table() : $wpdb->prefix . 'mmi';
            $vault_tab = 'Credentials & API Keys';
            $resolve_vault = function (string $key) use ($wpdb, $mmi_table, $vault_tab) {
                if (empty($key) || $wpdb->get_var("SHOW TABLES LIKE '{$mmi_table}'") !== $mmi_table) {
                    return '';
                }
                return (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT field_value FROM {$mmi_table} WHERE tab_name = %s AND field_name = %s",
                    $vault_tab,
                    $key
                ));
            };
            if (empty($source['auth_config']['token_url']) && !empty($template['token_url_vault_key'])) {
                $resolved = $resolve_vault($template['token_url_vault_key']);
                if ($resolved !== '') {
                    $source['auth_config']['token_url'] = $resolved;
                }
            }
            if (empty($source['configuration']['base_url']) && !empty($template['base_url_vault_key'])) {
                $resolved = $resolve_vault($template['base_url_vault_key']);
                if ($resolved !== '') {
                    $source['configuration']['base_url'] = $resolved;
                }
            }
        }
    }

    // Credential values for the config form. Secret (password-type) fields are
    // sent as a bullet mask only — never the stored secret. The JS treats a
    // bullet run as "value lives in the vault" and sends nothing back for it,
    // and the save/test handlers skip bullet runs, so the stored value is kept.
    $source['auth_config']['resolved_values'] = mmi_ds_resolve_credentials_masked($source['auth_config']);

    // Xchange credentials are read-path-managed by mmi-xchange-integration when that
    // plugin is installed and licensed (see mmi-hub/docs/XCHANGE_INTEGRATION_AUDIT.md
    // §10 Phase 3) — the fields here still render the vault values but no longer
    // control what the pipeline actually fetches with, so mark them read-only.
    $source['managed_by_xchange_plugin'] = (
        $supplier_id === 'xchange'
        && class_exists('MMI_Xchange_API')
        && \MMI_Xchange_API::is_active()
    );

    // Load endpoints
    if ($wpdb->get_var("SHOW TABLES LIKE '{$endpoints_table}'") === $endpoints_table) {
        $source['endpoints'] = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$endpoints_table} WHERE source_id = %d ORDER BY is_primary DESC, id ASC",
            $source['id']
        ), ARRAY_A);

        foreach ($source['endpoints'] as &$ep) {
            $ep['request_headers'] = mmi_ds_mask_headers(json_decode($ep['request_headers'] ?: '{}', true));
        }
        unset($ep);
    }

    // Header values that are credentials never reach the browser either.
    if (!empty($source['configuration']['custom_headers'])) {
        $source['configuration']['custom_headers'] = mmi_ds_mask_headers($source['configuration']['custom_headers']);
    }
    if (!empty($source['auth_config']['custom_headers'])) {
        $source['auth_config']['custom_headers'] = mmi_ds_mask_headers($source['auth_config']['custom_headers']);
    }

    wp_send_json_success($source);
});

/**
 * Add a new data source
 */
add_action('wp_ajax_mmi_add_data_source', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $supplier_id   = sanitize_key($_POST['supplier_id'] ?? '');
    $supplier_name = sanitize_text_field($_POST['supplier_name'] ?? '');
    $source_type   = sanitize_text_field($_POST['source_type'] ?? 'api');
    $template      = sanitize_key($_POST['template'] ?? '');

    // ── Preconfigured API template: override supplier details and pre-fill config ──
    $templates           = mmi_ds_get_preconfigured_templates();
    $template_insert     = null; // will hold the template definition if one is selected
    $template_endpoints  = [];

    if (!empty($template) && isset($templates[$template])) {
        $tpl           = $templates[$template];
        $supplier_id   = $tpl['supplier_id'];
        $supplier_name = $tpl['supplier_name'];
        $source_type   = $tpl['source_type'];
        $template_insert = $tpl;
    }

    if (empty($supplier_id) || empty($supplier_name)) {
        wp_send_json_error(['message' => 'Supplier ID and name are required']);
    }

    if (!preg_match('/^[a-z0-9_-]+$/', $supplier_id)) {
        wp_send_json_error(['message' => 'Supplier ID must be lowercase letters, numbers, hyphens, or underscores']);
    }

    global $wpdb;
    $table          = $wpdb->prefix . 'mmi_data_sources';
    $endpoints_table = $wpdb->prefix . 'mmi_data_source_endpoints';

    // Ensure table exists before attempting insert
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        wp_send_json_error(['message' => 'Data sources table not found. Please deactivate and reactivate the MMI VIP plugin.']);
    }

    // Check for duplicate
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE supplier_id = %s",
        $supplier_id
    ));

    if ($exists) {
        $label = $template_insert ? "A \"{$supplier_name}\" data source" : 'A data source with this ID';
        wp_send_json_error(['message' => "{$label} already exists. Only one instance of each preconfigured integration is allowed."]);
    }

    // Get next display order
    $max_order = (int) $wpdb->get_var("SELECT MAX(display_order) FROM {$table}");
    $now = current_time('mysql');

    // Default config status — may be overridden below for wizard-populated non-API sources
    $wizard_config_status = 'unconfigured';

    // ── Build initial configuration ──────────────────────────────────────────
    if ($template_insert !== null) {
        // Resolve any base URL and token URL already stored in the credential vault
        $resolved_base_url  = '';
        $resolved_token_url = '';
        $mmi_table = class_exists( 'MMI_Settings' ) ? \MMI_Settings::ensure_table() : $wpdb->prefix . 'mmi';
        $vault_tab = 'Credentials & API Keys';

        if (!empty($template_insert['base_url_vault_key']) && $wpdb->get_var("SHOW TABLES LIKE '{$mmi_table}'") === $mmi_table) {
            $resolved_base_url = $wpdb->get_var($wpdb->prepare(
                "SELECT field_value FROM {$mmi_table} WHERE tab_name = %s AND field_name = %s",
                $vault_tab,
                $template_insert['base_url_vault_key']
            )) ?: '';
        }
        if (!empty($template_insert['token_url_vault_key']) && $wpdb->get_var("SHOW TABLES LIKE '{$mmi_table}'") === $mmi_table) {
            $resolved_token_url = $wpdb->get_var($wpdb->prepare(
                "SELECT field_value FROM {$mmi_table} WHERE tab_name = %s AND field_name = %s",
                $vault_tab,
                $template_insert['token_url_vault_key']
            )) ?: '';
        }

        $initial_config = [
            'base_url'             => $resolved_base_url,
            'documentation_url'    => $template_insert['documentation_url'] ?? '',
            'notes'                => $template_insert['notes'] ?? '',
            'timeout'              => 60,
            'retries'              => 3,
            'cache_duration'       => 0,
            'detect_changes'       => true,
            'preconfigured_template' => $template,  // lock config modal to credentials-only editing
        ];

        $initial_auth = [
            'type'            => $template_insert['auth_type'],
            'credential_keys' => $template_insert['credential_keys'],
            'credential_tab'  => $vault_tab,
        ];
        // Timed-token: store token URL in auth config
        if (!empty($resolved_token_url)) {
            $initial_auth['token_url'] = $resolved_token_url;
        }
        // Extra request headers (e.g. SkuPort Accept header)
        if (!empty($template_insert['extra_headers'])) {
            $initial_auth['custom_headers'] = $template_insert['extra_headers'];
        }

        $initial_file = [
            'format'         => 'json',
            'delimiter'      => ',',
            'has_header'     => true,
            'encoding'       => 'UTF-8',
            'data_root_path' => $template_insert['data_root_path'] ?? '',
        ];

        $template_endpoints = $template_insert['endpoints'] ?? [];
        $initial_base_url   = $resolved_base_url;

    } else {
        // ── Blank (custom) data source ────────────────────────────────────
        // For non-API sources the user may specify an expected file format
        $file_format_param = sanitize_text_field($_POST['file_format'] ?? 'json');
        $allowed_formats   = ['json', 'csv', 'tsv', 'xml', 'txt', 'excel', 'numbers'];
        $initial_file_fmt  = in_array($file_format_param, $allowed_formats, true) ? $file_format_param : 'json';

        // ── Collect type-specific fields passed from the creation wizard ──
        $base_url_init      = '';
        $att_id_init        = 0;
        $dropbox_path_init  = '';
        $gdrive_id_init     = '';

        if ($source_type === 'url') {
            $base_url_init = esc_url_raw(sanitize_text_field($_POST['base_url'] ?? ''));
        } elseif ($source_type === 'upload') {
            $att_id_init = max(0, (int) ($_POST['upload_attachment_id'] ?? 0));
        } elseif ($source_type === 'dropbox') {
            $dropbox_path_init = sanitize_text_field($_POST['dropbox_file_path'] ?? '');
            $dropbox_token     = sanitize_text_field($_POST['dropbox_access_token'] ?? '');
            if (!empty($dropbox_token)) {
                mmi_ds_save_credential($supplier_id . '-dropbox_access_token', $dropbox_token);
            }
        } elseif ($source_type === 'gdrive') {
            $gdrive_id_init = sanitize_text_field($_POST['gdrive_file_id'] ?? '');
        }

        // Determine initial config_status: 'configured' if minimum required fields present
        $wizard_config_status = 'unconfigured';
        if ($source_type === 'url'     && !empty($base_url_init))    { $wizard_config_status = 'configured'; }
        if ($source_type === 'upload'  && $att_id_init > 0)          { $wizard_config_status = 'configured'; }
        if ($source_type === 'dropbox' && !empty($dropbox_path_init) && !empty($dropbox_token)) { $wizard_config_status = 'configured'; }
        if ($source_type === 'gdrive'  && !empty($gdrive_id_init))   { $wizard_config_status = 'configured'; }

        $initial_config = [
            'base_url'             => $base_url_init,
            'timeout'              => 60,
            'retries'              => 3,
            'cache_duration'       => 0,
            'detect_changes'       => true,
            'upload_attachment_id' => $att_id_init,
            'dropbox_file_path'    => $dropbox_path_init,
            'gdrive_file_id'       => $gdrive_id_init,
        ];
        $initial_auth = [
            'type'           => 'none',
            'credential_keys'=> [],
            'credential_tab' => 'Credentials & API Keys',
        ];
        $initial_file = [
            'format'        => $initial_file_fmt,
            'delimiter'     => ',',
            'has_header'    => true,
            'encoding'      => 'UTF-8',
            'data_root_path'=> '',
        ];
        $initial_base_url = $base_url_init;
    }

    $result = $wpdb->insert($table, [
        'supplier_id'   => $supplier_id,
        'supplier_name' => $supplier_name,
        'source_type'   => $source_type,
        'configuration' => wp_json_encode($initial_config),
        'auth_config'   => wp_json_encode($initial_auth),
        'file_config'   => wp_json_encode($initial_file),
        'enabled'       => 0,
        'config_status' => $wizard_config_status ?? 'unconfigured',
        'display_order' => $max_order + 1,
        'created_at'    => $now,
        'updated_at'    => $now,
    ]);

    if ($result === false) {
        wp_send_json_error(['message' => 'Failed to create data source']);
    }

    $new_source_id = $wpdb->insert_id;

    // ── Insert preconfigured endpoints ────────────────────────────────────
    if (!empty($template_endpoints) && $wpdb->get_var("SHOW TABLES LIKE '{$endpoints_table}'") === $endpoints_table) {
        foreach ($template_endpoints as $ep) {
            $ep_url = !empty($initial_base_url)
                ? rtrim($initial_base_url, '/') . $ep['path']
                : $ep['path'];

            if (!empty($ep['params'])) {
                $ep_url .= '?' . $ep['params'];
            }

            $wpdb->insert($endpoints_table, [
                'source_id'       => $new_source_id,
                'endpoint_name'   => $ep['name'],
                'endpoint_url'    => $ep_url,
                'http_method'     => 'GET',
                'response_format' => $ep['format'] ?? 'json',
                'data_root_path'  => $ep['data_root_path'] ?? '',
                'is_primary'      => !empty($ep['is_primary']) ? 1 : 0,
                'enabled'         => 1,
                'created_at'      => $now,
            ]);
        }
    }

    if ($template_insert !== null) {
        mmi_ds_complete_template_setup($supplier_id);
    }

    $source = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE supplier_id = %s",
        $supplier_id
    ), ARRAY_A);

    $source['configuration'] = json_decode($source['configuration'] ?: '{}', true);
    $source['auth_config']   = json_decode($source['auth_config'] ?: '{}', true);
    $source['file_config']   = json_decode($source['file_config'] ?: '{}', true);

    // Load created endpoints
    $source['endpoints'] = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '{$endpoints_table}'") === $endpoints_table) {
        $source['endpoints'] = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$endpoints_table} WHERE source_id = %d ORDER BY is_primary DESC, id ASC",
            $new_source_id
        ), ARRAY_A);
    }

    mmi_data_pipeline_audit('source.create', [
        'object_type' => 'data_source',
        'object_id'   => $supplier_id,
        'outcome'     => 'success',
        'details'     => ['source_type' => $source_type, 'template' => $template ?: ''],
    ]);

    wp_send_json_success([
        'message'  => 'Data source created',
        'source'   => $source,
        'template' => $template ?: null,
    ]);
});

/**
 * Save data source configuration (full config: connection + auth + parsing + advanced)
 */
add_action('wp_ajax_mmi_save_data_source', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $supplier_id = sanitize_text_field($_POST['supplier_id'] ?? '');
    $config_json = wp_unslash($_POST['config'] ?? '{}');
    $config = json_decode($config_json, true);

    if (empty($supplier_id) || !is_array($config)) {
        wp_send_json_error(['message' => 'Supplier ID and configuration required']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';
    $endpoints_table = $wpdb->prefix . 'mmi_data_source_endpoints';

    $source = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE supplier_id = %s",
        $supplier_id
    ), ARRAY_A);

    if (!$source) {
        wp_send_json_error(['message' => 'Data source not found']);
    }

    // Build configuration JSON
    $connection = $config['connection'] ?? [];

    // Existing configuration is decoded further down ($existing_auth) but not
    // otherwise re-read here — preconfigured_template (set only at creation,
    // see mmi_add_data_source) must be explicitly carried forward or this
    // wholesale rebuild below silently drops it on every save, which would
    // un-lock a preconfigured Xchange/SkuPort integration's Connection tab
    // the first time its credentials are edited.
    $existing_configuration = json_decode($source['configuration'] ?: '{}', true) ?: [];

    $configuration = [
        'base_url'             => esc_url_raw($connection['base_url'] ?? ''),
        'documentation_url'    => esc_url_raw($connection['documentation_url'] ?? ''),
        'notes'                => sanitize_textarea_field($connection['notes'] ?? ''),
        'timeout'              => max(5, min(300, (int) ($config['advanced']['timeout'] ?? 60))),
        'retries'              => max(0, min(10, (int) ($config['advanced']['retries'] ?? 3))),
        'cache_duration'       => max(0, (int) ($config['advanced']['cache_duration'] ?? 0)),
        'detect_changes'       => !empty($config['advanced']['detect_changes']),
        'custom_headers'       => mmi_ds_sanitize_headers(mmi_ds_unmask_headers($config['advanced']['custom_headers'] ?? [], $existing_configuration['custom_headers'] ?? [])),
        // Non-HTTP source fields
        'upload_attachment_id' => max(0, (int) ($connection['upload_attachment_id'] ?? 0)),
        'dropbox_file_path'    => sanitize_text_field($connection['dropbox_file_path'] ?? ''),
        'gdrive_file_id'       => sanitize_text_field($connection['gdrive_file_id'] ?? ''),
        'preconfigured_template' => sanitize_key($existing_configuration['preconfigured_template'] ?? ''),
    ];

    // Keys this modal does not edit but other screens own — rebuilt from
    // scratch above, they were silently dropped on every save until
    // 2026-10-07: the Data Sources table's per-source Taxonomy Mapping
    // toggle (mmi_toggle_source_taxonomy_mapping) came back on after any
    // Configure save, and a custom json_files list vanished.
    foreach (['taxonomy_mapping_enabled', 'json_files'] as $carry) {
        if (array_key_exists($carry, $existing_configuration)) {
            $configuration[$carry] = $existing_configuration[$carry];
        }
    }

    // Declared taxonomy fields (Taxonomies tab). Sent as a list, possibly
    // empty — an empty list is a real answer ("this source has no brand or
    // category field"), so it is stored as [] rather than dropped, which
    // would let the template fallback in
    // MMI_Pipeline_Admin::resolve_source_taxonomy_fields() re-add the
    // template's fields. Absent from the payload (older JS, other callers):
    // keep whatever is stored.
    if (array_key_exists('taxonomy_fields', $config)) {
        $configuration['taxonomy_fields'] = \MMI_Pipeline_Field_Mapping_Defaults::normalize_taxonomy_fields($config['taxonomy_fields']);
    } elseif (array_key_exists('taxonomy_fields', $existing_configuration)) {
        $configuration['taxonomy_fields'] = $existing_configuration['taxonomy_fields'];
    }

    // Build auth_config JSON
    $auth = $config['auth'] ?? [];
    $auth_type = sanitize_text_field($auth['type'] ?? 'none');
    $auth_config = [
        'type' => $auth_type,
        'credential_tab' => 'Credentials & API Keys',
    ];

    // Store credential keys and save actual values to wp_mmi if provided
    $credential_values = $auth['credentials'] ?? [];
    $credential_keys = [];

    // Non-credential auth config fields: these go directly into auth_config, not wp_mmi.
    // They don't need credential_keys entries because they aren't secrets.
    $non_credential_params = [];
    switch ($auth_type) {
        case 'timed_token':
            $non_credential_params = ['token_url'];
            break;
        case 'oauth2_client_credentials':
            $non_credential_params = ['token_url', 'scope'];
            break;
        case 'oauth2_auth_code':
            $non_credential_params = ['token_url', 'scope', 'auth_url', 'redirect_uri'];
            break;
        case 'api_key_header':
            $non_credential_params = ['header_name'];
            break;
        case 'api_key_query':
            $non_credential_params = ['param_name'];
            break;
        case 'hmac':
            $non_credential_params = ['algorithm', 'header_name'];
            break;
    }
    foreach ($non_credential_params as $nc) {
        unset($credential_values[$nc]);
    }

    // Read existing credential_keys BEFORE the save loop so we can reuse the same
    // vault field names. Previously the save loop always generated a new field name
    // (e.g. 'xchange-api_key') that differed from the registered reference
    // (e.g. 'xchange-api-key'), creating phantom vault rows the load path never found.
    $existing_auth      = json_decode($source['auth_config'] ?: '{}', true);
    $existing_cred_keys = $existing_auth['credential_keys'] ?? [];

    $changed_credential_keys = [];
    if (!empty($credential_values) && is_array($credential_values)) {
        foreach ($credential_values as $param => $value) {
            $param = sanitize_key($param);
            // Reuse the registered vault field name so saves always write to the same
            // row the load path reads from (preserves legacy hyphen-format names too).
            $field_name = $existing_cred_keys[$param] ?? ($supplier_id . '-' . $param);
            $credential_keys[$param] = $field_name;

            // Save to wp_mmi if a real value was submitted — skip any run of bullet
            // characters, which are masking sentinels (not real credential values).
            if (!empty($value) && !preg_match('/^[\x{2022}]+$/u', $value)) {
                mmi_ds_save_credential($field_name, sanitize_text_field($value));
                $changed_credential_keys[] = $param;
            }
        }
    }

    // Ensure ALL credential fields for this auth type have a credential_keys entry
    // even when the user left a field blank, so the reference is preserved for loads.
    $field_defs = mmi_ds_get_auth_type_fields($auth_type);

    foreach ($field_defs as $field_def) {
        $param = $field_def['key'];
        // Skip non-credential params and non-scalar types
        if (in_array($param, $non_credential_params, true)) {
            continue;
        }
        if (in_array($field_def['type'], ['key_value_pairs'], true)) {
            continue;
        }
        // Register the key reference if not already set by this save
        if (!isset($credential_keys[$param])) {
            $credential_keys[$param] = $existing_cred_keys[$param] ?? ($supplier_id . '-' . $param);
        }
    }

    $auth_config['credential_keys'] = $credential_keys;

    if (!empty($auth['custom_headers'])) {
        $auth_config['custom_headers'] = mmi_ds_sanitize_headers(mmi_ds_unmask_headers($auth['custom_headers'], $existing_auth['custom_headers'] ?? []));
    }

    // Required URL fields (token_url/auth_url/redirect_uri) must never be blanked
    // by an incidental save — e.g. the config-modal load path rendering one empty
    // due to a self-heal miss (see mmi-hub/docs/XCHANGE_INTEGRATION_AUDIT.md §7).
    // Only overwrite when a real, non-empty value was actually submitted; otherwise
    // keep whatever was already stored. `scope` is genuinely optional so it's exempt.
    $keep_or_set_url = function (string $key) use ($auth, $existing_auth): string {
        $submitted = esc_url_raw($auth[$key] ?? '');
        return $submitted !== '' ? $submitted : (string) ($existing_auth[$key] ?? '');
    };

    // OAuth2-specific fields
    if (in_array($auth_type, ['oauth2_client_credentials', 'oauth2_auth_code'])) {
        $auth_config['token_url'] = $keep_or_set_url('token_url');
        $auth_config['scope'] = sanitize_text_field($auth['scope'] ?? '');
        if ($auth_type === 'oauth2_auth_code') {
            $auth_config['auth_url'] = $keep_or_set_url('auth_url');
            $auth_config['redirect_uri'] = $keep_or_set_url('redirect_uri');
        }
    }

    // Timed token specific
    if ($auth_type === 'timed_token') {
        $auth_config['token_url'] = $keep_or_set_url('token_url');
    }

    // API key specific
    if ($auth_type === 'api_key_header') {
        $auth_config['header_name'] = sanitize_text_field($auth['header_name'] ?? 'X-API-Key');
    } elseif ($auth_type === 'api_key_query') {
        $auth_config['param_name'] = sanitize_text_field($auth['param_name'] ?? 'api_key');
    }

    // A template's fixed auth params are not shown in the modal, so nothing
    // posted can be trusted for them; the template decides.
    $save_template = mmi_ds_template_for_source(['supplier_id' => $supplier_id, 'configuration' => $configuration]);
    foreach ((array) ($save_template['auth_params'] ?? []) as $fixed_key => $fixed_value) {
        $auth_config[$fixed_key] = $fixed_value;
    }

    // HMAC specific
    if ($auth_type === 'hmac') {
        $auth_config['algorithm'] = sanitize_text_field($auth['algorithm'] ?? 'sha256');
        $auth_config['header_name'] = sanitize_text_field($auth['header_name'] ?? 'X-Signature');
    }

    // Build file_config JSON
    $parsing = $config['parsing'] ?? [];
    $file_config = [
        'format' => sanitize_text_field($parsing['format'] ?? 'json'),
        'delimiter' => sanitize_text_field($parsing['delimiter'] ?? ','),
        'enclosure' => sanitize_text_field($parsing['enclosure'] ?? '"'),
        'has_header' => !empty($parsing['has_header']),
        'encoding' => sanitize_text_field($parsing['encoding'] ?? 'UTF-8'),
        'data_root_path' => sanitize_text_field($parsing['data_root_path'] ?? ''),
        'sheet_name' => sanitize_text_field($parsing['sheet_name'] ?? ''),
        'skip_rows' => max(0, (int) ($parsing['skip_rows'] ?? 0)),
    ];

    // Update source type from connection settings
    $source_type = sanitize_text_field($connection['source_type'] ?? $source['source_type']);

    // For file upload: derive the filename from the attachment record.
    if ($source_type === 'upload' && $configuration['upload_attachment_id'] > 0) {
        $att = get_post($configuration['upload_attachment_id']);
        if ($att && $att->post_type === 'attachment') {
            $configuration['upload_filename'] = basename(get_attached_file($configuration['upload_attachment_id'])) ?: $att->post_title;
        } else {
            $configuration['upload_attachment_id'] = 0; // invalid attachment
            $configuration['upload_filename'] = '';
        }
    } else {
        $configuration['upload_filename'] = '';
    }

    // Save Dropbox access token to credential vault if a real (non-masked) value was submitted.
    if ($source_type === 'dropbox') {
        $db_token = $connection['dropbox_access_token'] ?? '';
        if (!empty($db_token) && !preg_match('/^[\x{2022}]+$/u', $db_token)) {
            mmi_ds_save_credential($supplier_id . '-dropbox_access_token', sanitize_text_field($db_token));
            $changed_credential_keys[] = 'dropbox_access_token';
        }
    }

    // Determine config_status based on source type.
    // Never automatically elevate to 'validated' — that requires a successful Test Connection.
    // Never downgrade from 'validated' back to 'configured' if the user re-saves without re-testing.
    $existing_status = $source['config_status'] ?? 'unconfigured';

    if ($source_type === 'upload') {
        // Upload validates immediately when a valid attachment is present.
        $config_status = ($configuration['upload_attachment_id'] > 0 && !empty($configuration['upload_filename']))
            ? 'validated'
            : 'unconfigured';
    } elseif ($source_type === 'dropbox') {
        // Dropbox needs a file path and a token in the vault.
        $has_db_path  = !empty($configuration['dropbox_file_path']);
        $mmi_table    = class_exists( 'MMI_Settings' ) ? \MMI_Settings::ensure_table() : $wpdb->prefix . 'mmi';
        $has_db_token = (
            $wpdb->get_var("SHOW TABLES LIKE '{$mmi_table}'") === $mmi_table &&
            (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$mmi_table} WHERE tab_name = 'Credentials & API Keys' AND field_name = %s",
                $supplier_id . '-dropbox_access_token'
            ))
        );
        if (! ($has_db_path && $has_db_token)) {
            $config_status = 'unconfigured';
        } elseif ($existing_status === 'validated') {
            $config_status = 'validated';
        } else {
            $config_status = 'configured';
        }
    } elseif ($source_type === 'gdrive') {
        // Google Drive needs a file ID.
        if (empty($configuration['gdrive_file_id'])) {
            $config_status = 'unconfigured';
        } elseif ($existing_status === 'validated') {
            $config_status = 'validated';
        } else {
            $config_status = 'configured';
        }
    } else {
        // HTTP source types (api, url): require a base URL.
        $has_url = !empty($configuration['base_url']);
        if (!$has_url) {
            $config_status = 'unconfigured';
        } elseif ($existing_status === 'validated') {
            $config_status = 'validated';
        } else {
            $config_status = 'configured';
        }
    }

    // Update the main record
    $update_result = $wpdb->update(
        $table,
        [
            'supplier_name' => sanitize_text_field($connection['supplier_name'] ?? $source['supplier_name']),
            'source_type'   => $source_type,
            'configuration' => wp_json_encode($configuration),
            'auth_config'   => wp_json_encode($auth_config),
            'file_config'   => wp_json_encode($file_config),
            'config_status' => $config_status,
            'updated_at'    => current_time('mysql'),
        ],
        ['supplier_id' => $supplier_id],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        ['%s']
    );

    if ($update_result === false) {
        wp_send_json_error(['message' => 'Database error: ' . $wpdb->last_error]);
    }

    // Save endpoints
    $endpoints = $config['endpoints'] ?? [];
    if (!empty($endpoints) && is_array($endpoints)) {
        // Stored headers by endpoint URL, so a masked value can be restored
        // after the delete-and-reinsert below.
        $stored_ep_headers = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT endpoint_url, request_headers FROM {$endpoints_table} WHERE source_id = %d",
            $source['id']
        ), ARRAY_A) as $row) {
            $stored_ep_headers[$row['endpoint_url']] = json_decode($row['request_headers'] ?: '{}', true) ?: [];
        }

        // Clear existing endpoints
        $wpdb->delete($endpoints_table, ['source_id' => $source['id']]);

        $now = current_time('mysql');
        foreach ($endpoints as $ep) {
            if (empty($ep['endpoint_url'])) continue;

            $wpdb->insert($endpoints_table, [
                'source_id' => $source['id'],
                'endpoint_name' => sanitize_text_field($ep['endpoint_name'] ?? 'Default'),
                'endpoint_url' => esc_url_raw($ep['endpoint_url']),
                'http_method' => sanitize_text_field($ep['http_method'] ?? 'GET'),
                'request_headers' => !empty($ep['request_headers']) ? wp_json_encode(mmi_ds_sanitize_headers(mmi_ds_unmask_headers($ep['request_headers'], $stored_ep_headers[esc_url_raw($ep['endpoint_url'])] ?? []))) : null,
                'response_format' => sanitize_text_field($ep['response_format'] ?? 'json'),
                'data_root_path' => sanitize_text_field($ep['data_root_path'] ?? ''),
                'is_primary' => !empty($ep['is_primary']) ? 1 : 0,
                'enabled' => 1,
                'created_at' => $now,
            ]);
        }
    }

    // Reload the saved source
    $saved = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE supplier_id = %s",
        $supplier_id
    ), ARRAY_A);
    $saved['configuration']    = json_decode($saved['configuration'] ?: '{}', true);
    $saved['auth_config']      = json_decode($saved['auth_config'] ?: '{}', true);
    $saved['file_config']      = json_decode($saved['file_config'] ?: '{}', true);
    // Hoist these into root so JS can read s.documentation_url / s.notes
    $saved['documentation_url'] = $saved['configuration']['documentation_url'] ?? '';
    $saved['notes']             = $saved['configuration']['notes'] ?? '';

    mmi_data_pipeline_audit('source.update', [
        'object_type' => 'data_source',
        'object_id'   => $supplier_id,
        'outcome'     => 'success',
        'details'     => ['source_type' => $source_type, 'auth_type' => $auth_type, 'config_status' => $config_status],
    ]);
    if (!empty($changed_credential_keys)) {
        // Key names only — never the values.
        mmi_data_pipeline_audit('credentials.update', [
            'object_type' => 'data_source',
            'object_id'   => $supplier_id,
            'outcome'     => 'success',
            'details'     => ['keys' => array_values(array_unique($changed_credential_keys))],
        ]);
    }

    wp_send_json_success([
        'message'       => 'Configuration saved',
        'source'        => $saved,
        'config_status' => $config_status,
    ]);
});

/**
 * Delete a data source
 */
add_action('wp_ajax_mmi_delete_data_source', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $supplier_id = sanitize_text_field($_POST['supplier_id'] ?? '');
    if (empty($supplier_id)) {
        wp_send_json_error(['message' => 'Supplier ID required']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';
    $endpoints_table = $wpdb->prefix . 'mmi_data_source_endpoints';

    $source = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE supplier_id = %s",
        $supplier_id
    ), ARRAY_A);

    if (!$source) {
        wp_send_json_error(['message' => 'Data source not found']);
    }

    // Delete endpoints
    $wpdb->delete($endpoints_table, ['source_id' => $source['id']]);

    // Delete source
    $wpdb->delete($table, ['supplier_id' => $supplier_id]);

    // Sync enabled suppliers option
    mmi_ds_sync_enabled_suppliers();

    mmi_data_pipeline_audit('source.delete', [
        'object_type' => 'data_source',
        'object_id'   => $supplier_id,
        'outcome'     => 'success',
        'details'     => ['source_type' => $source['source_type'] ?? ''],
    ]);

    wp_send_json_success(['message' => 'Data source deleted']);
});

// ─── Enable/Disable ─────────────────────────────────────────────────────────
//
// The manual enable/disable toggle was removed (see AGENTS.md's "Enabled
// Toggle Eliminated" entry) — a source is treated as enabled the moment it's
// validated (config_status === 'validated'), with no separate manual step.
// There was no real scenario for wanting a validated, working source
// excluded from imports short of removing it outright, and the toggle's
// existence let a validated-but-still-disabled source (assignable to a
// profile with zero warning anywhere) silently import nothing. The
// `wp_ajax_mmi_toggle_supplier_enabled` handler that used to live here is
// gone; nothing calls it anymore.

// ─── Per-Source Taxonomy Mapping Toggle ────────────────────────────────────
//
// A narrower, well-defined toggle than the one eliminated above — this
// doesn't control whether a source imports at all, only whether its brand/
// category values are resolved through Taxonomy Mapping's alias table (see
// MMI_Pipeline_Admin::get_configured_suppliers()'s 'taxonomy_mapping_enabled'
// key and its consumers: MMI_Pipeline_Field_Mapping_Defaults::
// get_taxonomy_source_fields(), MMI_Pipeline_Field_Resolver::
// resolve_taxonomy_via_alias_table(), Taxonomy_Mapping_Handler::
// apply_taxonomy_mappings()). Disabling it doesn't stop the source from
// importing — it just means that source's taxonomy fields resolve however
// they would with zero alias rows configured (literal-value auto-create on
// the manual path, left untouched on the scheduled path), which is a real,
// legitimate choice for a source whose raw values are already clean.
add_action('wp_ajax_mmi_toggle_source_taxonomy_mapping', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $supplier_id = sanitize_text_field($_POST['supplier_id'] ?? '');
    $enabled     = !empty($_POST['enabled']);

    if (empty($supplier_id)) {
        wp_send_json_error(['message' => 'supplier_id required']);
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'mmi_data_sources';
    $source = $wpdb->get_row($wpdb->prepare("SELECT configuration FROM {$table} WHERE supplier_id = %s", $supplier_id), ARRAY_A);

    if (!$source) {
        wp_send_json_error(['message' => 'Unknown data source']);
    }

    $cfg = json_decode($source['configuration'] ?: '{}', true) ?: [];
    $cfg['taxonomy_mapping_enabled'] = $enabled;

    $ok = $wpdb->update(
        $table,
        ['configuration' => wp_json_encode($cfg), 'updated_at' => current_time('mysql')],
        ['supplier_id' => $supplier_id]
    );

    if ($ok === false) {
        wp_send_json_error(['message' => 'Failed to save']);
    }

    wp_send_json_success(['supplier_id' => $supplier_id, 'enabled' => $enabled]);
});

// ─── Testing & Validation ───────────────────────────────────────────────────

/**
 * Test data source connection (real HTTP request)
 */
add_action('wp_ajax_mmi_test_data_source', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $supplier_id = sanitize_text_field($_POST['supplier_id'] ?? '');
    $config_json = wp_unslash($_POST['config'] ?? '');

    if (empty($supplier_id)) {
        wp_send_json_error(['message' => 'Supplier ID required']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';
    $endpoints_table = $wpdb->prefix . 'mmi_data_source_endpoints';

    $source = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE supplier_id = %s",
        $supplier_id
    ), ARRAY_A);

    // Source may not be saved yet — allowed if a config payload was posted
    if (!$source && empty($config_json)) {
        wp_send_json_error(['message' => 'Data source not found. Save the configuration first, or provide connection details before testing.']);
    }

    // Use provided config or fall back to saved config
    if (!empty($config_json)) {
        $config = json_decode($config_json, true);
    } else {
        $config = [
            'connection' => json_decode(($source['configuration'] ?? '{}') ?: '{}', true),
            'auth'       => json_decode(($source['auth_config']   ?? '{}') ?: '{}', true),
            'parsing'    => json_decode(($source['file_config']   ?? '{}') ?: '{}', true),
        ];
    }

    $source_type = sanitize_text_field(
        $config['connection']['source_type']
        ?? $source['source_type']
        ?? 'api'
    );

    // ── Plugivery: test via the updater's free act=info call ──────────
    // The generic API test below would spend a real read (300/day cap,
    // revocation risk) outside the updater's quota ledger. act=info is
    // uncounted but still enforces the IP allowlist and token.
    if ($supplier_id === 'plugivery' && class_exists('MMI_Pipeline_Plugivery_Updater')) {
        try {
            $check = (new \MMI_Pipeline_Plugivery_Updater())->check_connection();
        } catch (\Throwable $e) {
            $check = ['ok' => false, 'message' => $e->getMessage()];
        }
        if (!$check['ok']) {
            wp_send_json_error(['message' => esc_html($check['message'])]);
        }
        if ($source) {
            $wpdb->update(
                $table,
                ['config_status' => 'validated', 'last_validated_at' => current_time('mysql'), 'updated_at' => current_time('mysql')],
                ['supplier_id' => $supplier_id],
                ['%s', '%s', '%s'],
                ['%s']
            );
            mmi_ds_sync_enabled_suppliers();
        }
        wp_send_json_success([
            'message'          => esc_html($check['message']),
            'response_time_ms' => 0,
            'product_count'    => 0,
            'sample_data'      => [],
        ]);
    }

    // ── Upload: verify attachment exists on disk ──────────────────────
    if ($source_type === 'upload') {
        $saved_config = json_decode(($source['configuration'] ?? '{}') ?: '{}', true);
        // Prefer the posted (current form) value; fall back to the saved DB value
        $att_id = (int) (
            $config['connection']['upload_attachment_id']
            ?? $saved_config['upload_attachment_id']
            ?? 0
        );
        if ($att_id <= 0) {
            wp_send_json_error(['message' => 'No file selected. Choose a file in the Connection tab first.']);
        }
        $att = get_post($att_id);
        if (!$att || $att->post_type !== 'attachment') {
            wp_send_json_error(['message' => "Attachment ID {$att_id} not found in the media library. Please re-select the file."]);
        }
        $file_path = get_attached_file($att_id);
        if (empty($file_path) || !file_exists($file_path)) {
            wp_send_json_error(['message' => 'The selected file is no longer accessible on disk. Please re-upload it.']);
        }
        $file_size = size_format((int) filesize($file_path));
        $filename  = esc_html(basename($file_path));
        $wpdb->update(
            $table,
            ['config_status' => 'validated', 'last_validated_at' => current_time('mysql'), 'updated_at' => current_time('mysql')],
            ['supplier_id' => $supplier_id],
            ['%s', '%s', '%s'],
            ['%s']
        );
        // A no-op today if the product JSON cache hasn't been built yet (Verify
        // here only confirms the attachment is still on disk — the actual parse
        // happens later, via mmi_pipeline_ensure_source_cache) — the lazy
        // fingerprint check in get_filterable_fields() picks it up once that
        // cache exists. Called here anyway so every source type's Verify click
        // goes through the identical trigger, per this project's Elegance rule
        // against two divergent code paths deciding the same thing.
        mmi_pipeline_trigger_filterable_fields_calc($supplier_id);
        wp_send_json_success([
            'message'          => "File verified: <strong>{$filename}</strong> ({$file_size}). Data source is ready to import.",
            'response_time_ms' => 0,
            'product_count'    => 0,
            'sample_data'      => [],
        ]);
    }

    // ── Dropbox: test file metadata via Dropbox API ───────────────────
    if ($source_type === 'dropbox') {
        $saved_config = json_decode(($source['configuration'] ?? '{}') ?: '{}', true);
        // Prefer the posted (current form) value; fall back to saved DB value
        $db_file_path = $config['connection']['dropbox_file_path']
            ?? $saved_config['dropbox_file_path']
            ?? '';

        // Resolve access token from vault
        $mmi_table  = class_exists( 'MMI_Settings' ) ? \MMI_Settings::ensure_table() : $wpdb->prefix . 'mmi';
        $db_token   = '';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$mmi_table}'") === $mmi_table) {
            $db_token = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT field_value FROM {$mmi_table} WHERE tab_name = 'Credentials & API Keys' AND field_name = %s",
                $supplier_id . '-dropbox_access_token'
            ));
        }

        if (empty($db_file_path)) {
            wp_send_json_error(['message' => 'Dropbox file path is not configured.']);
        }
        if (empty($db_token)) {
            wp_send_json_error(['message' => 'Dropbox access token not found in credential vault. Re-enter the token and save first.']);
        }

        $t_start = microtime(true);
        $meta_response = wp_remote_post('https://api.dropboxapi.com/2/files/get_metadata', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $db_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode(['path' => $db_file_path]),
        ]);
        $elapsed_ms = (int) ((microtime(true) - $t_start) * 1000);

        if (is_wp_error($meta_response)) {
            wp_send_json_error(['message' => 'Dropbox API request failed: ' . esc_html($meta_response->get_error_message())]);
        }

        $http_code = wp_remote_retrieve_response_code($meta_response);
        $body      = wp_remote_retrieve_body($meta_response);
        $resp_data = json_decode($body, true);

        if ($http_code === 401) {
            wp_send_json_error(['message' => 'Dropbox: Invalid or expired access token. Re-enter the token in the Connection tab.']);
        }
        if ($http_code === 409) {
            $err_tag = $resp_data['error']['.tag'] ?? '';
            if ($err_tag === 'not_found' || str_contains((string) $body, 'not_found')) {
                wp_send_json_error(['message' => "Dropbox: File not found at path '{$db_file_path}'. Check the path."]);
            }
            wp_send_json_error(['message' => "Dropbox error: " . esc_html(wp_trim_words((string) ($resp_data['error_summary'] ?? $body), 20))]);
        }
        if ($http_code !== 200) {
            $err_msg = $resp_data['error_summary'] ?? $body;
            wp_send_json_error(['message' => "Dropbox API error ({$http_code}): " . esc_html(wp_trim_words((string) $err_msg, 20))]);
        }

        $file_name = esc_html($resp_data['name'] ?? basename($db_file_path));
        $file_size = isset($resp_data['size']) ? size_format((int) $resp_data['size']) : 'unknown size';

        $wpdb->update(
            $table,
            ['config_status' => 'validated', 'last_validated_at' => current_time('mysql'), 'updated_at' => current_time('mysql')],
            ['supplier_id' => $supplier_id],
            ['%s', '%s', '%s'],
            ['%s']
        );
        mmi_pipeline_trigger_filterable_fields_calc($supplier_id);
        wp_send_json_success([
            'message'          => "Dropbox connected. File <strong>{$file_name}</strong> ({$file_size}) is accessible.",
            'response_time_ms' => $elapsed_ms,
            'product_count'    => 0,
            'sample_data'      => [],
        ]);
    }

    // ── Google Drive: verify public share link is accessible ──────────
    if ($source_type === 'gdrive') {
        $saved_config = json_decode(($source['configuration'] ?? '{}') ?: '{}', true);
        // Prefer the posted (current form) value; fall back to saved DB value
        $gd_file_id = $config['connection']['gdrive_file_id']
            ?? $saved_config['gdrive_file_id']
            ?? '';

        if (empty($gd_file_id)) {
            wp_send_json_error(['message' => 'Google Drive File ID is not configured.']);
        }

        $download_url = 'https://drive.google.com/uc?export=download&id=' . rawurlencode($gd_file_id);

        $t_start = microtime(true);
        $head_response = wp_remote_head($download_url, [
            'timeout'     => 15,
            'redirection' => 5,
            'headers'     => ['Accept' => '*/*'],
        ]);
        $elapsed_ms = (int) ((microtime(true) - $t_start) * 1000);

        if (is_wp_error($head_response)) {
            wp_send_json_error(['message' => 'Google Drive request failed: ' . esc_html($head_response->get_error_message())]);
        }

        $http_code    = wp_remote_retrieve_response_code($head_response);
        $content_type = wp_remote_retrieve_header($head_response, 'content-type');

        if ($http_code === 404) {
            wp_send_json_error(['message' => 'Google Drive: File not found. Check the File ID.']);
        }
        if ($http_code === 403 || $http_code === 401) {
            wp_send_json_error(['message' => 'Google Drive: Access denied. Make sure the file is shared as "Anyone with the link can view".']);
        }
        if ($http_code !== 200) {
            wp_send_json_error(['message' => "Google Drive returned HTTP {$http_code}. Ensure the file exists and is publicly shared."]);
        }

        // HTML response means large-file confirmation page — importer handles it
        $is_html    = str_contains(strtolower((string) $content_type), 'text/html');
        $extra_note = $is_html ? ' Note: Google Drive may show a confirmation page for large files (>25&nbsp;MB) — the importer handles this automatically.' : '';

        $wpdb->update(
            $table,
            ['config_status' => 'validated', 'last_validated_at' => current_time('mysql'), 'updated_at' => current_time('mysql')],
            ['supplier_id' => $supplier_id],
            ['%s', '%s', '%s'],
            ['%s']
        );
        mmi_pipeline_trigger_filterable_fields_calc($supplier_id);
        wp_send_json_success([
            'message'          => "Google Drive file is accessible.{$extra_note}",
            'response_time_ms' => $elapsed_ms,
            'product_count'    => 0,
            'sample_data'      => [],
        ]);
    }

    // Find the test URL
    $test_url    = '';
    $http_method = 'GET'; // default; overridden by endpoint config below

    // Check endpoints table first (only if we have a saved source)
    if ($source && $wpdb->get_var("SHOW TABLES LIKE '{$endpoints_table}'") === $endpoints_table) {
        $primary_endpoint = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$endpoints_table} WHERE source_id = %d AND is_primary = 1 LIMIT 1",
            $source['id']
        ), ARRAY_A);

        if (!$primary_endpoint) {
            $primary_endpoint = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$endpoints_table} WHERE source_id = %d AND enabled = 1 ORDER BY id ASC LIMIT 1",
                $source['id']
            ), ARRAY_A);
        }

        if ($primary_endpoint) {
            $test_url    = $primary_endpoint['endpoint_url'];
            $http_method = strtoupper($primary_endpoint['http_method'] ?? 'GET');
        }
    }

    // Fall back to endpoints from config
    if (empty($test_url) && !empty($config['endpoints'])) {
        foreach ($config['endpoints'] as $ep) {
            if (!empty($ep['is_primary']) && !empty($ep['endpoint_url'])) {
                $test_url    = $ep['endpoint_url'];
                $http_method = strtoupper($ep['http_method'] ?? 'GET');
                break;
            }
        }
        if (empty($test_url) && !empty($config['endpoints'][0]['endpoint_url'])) {
            $test_url    = $config['endpoints'][0]['endpoint_url'];
            $http_method = strtoupper($config['endpoints'][0]['http_method'] ?? 'GET');
        }
    }

    // Fall back to base_url
    if (empty($test_url)) {
        $conn = $config['connection'] ?? [];
        $test_url = $conn['base_url'] ?? '';
    }

    if (empty($test_url)) {
        wp_send_json_error(['message' => 'No endpoint URL configured. Please add at least one endpoint.']);
    }

    // Build request headers from auth config.
    // Start from the saved DB auth_config (has credential_keys for wp_mmi lookups),
    // then overlay the current form values so an unsaved change is still used during the test.
    $saved_auth_config = json_decode(($source['auth_config'] ?? '{}') ?: '{}', true);
    $form_auth = $config['auth'] ?? [];
    $auth_config = $saved_auth_config;
    $auth_config['type'] = sanitize_text_field($form_auth['type'] ?? $saved_auth_config['type'] ?? 'none');
    foreach (['token_url', 'api_url', 'scope', 'auth_url', 'redirect_uri', 'header_name', 'param_name', 'algorithm'] as $nc_key) {
        if (!empty($form_auth[$nc_key] ?? '')) {
            $nc_val = $form_auth[$nc_key];
            if (in_array($nc_key, ['token_url', 'api_url', 'auth_url', 'redirect_uri'])) {
                $auth_config[$nc_key] = esc_url_raw($nc_val);
            } else {
                $auth_config[$nc_key] = sanitize_text_field($nc_val);
            }
        }
    }

    // Overlay any real credential values the user typed in the form but hasn't saved yet.
    // This lets "Test Connection" verify unsaved credentials without forcing a save first.
    // Any bullet-run (••••••••) is a masking sentinel, never a real value — skip those.
    $test_cred_overrides = [];
    $form_creds_raw = $form_auth['credentials'] ?? [];
    if (!empty($form_creds_raw) && is_array($form_creds_raw)) {
        foreach ($form_creds_raw as $ckey => $cval) {
            if (is_string($cval) && $cval !== '' && !preg_match('/^\x{2022}+$/u', $cval)) {
                $test_cred_overrides[sanitize_key($ckey)] = sanitize_text_field($cval);
            }
        }
    }

    // ── Pre-flight: timed_token requires a successful token exchange before the main request.
    // Validate the token endpoint now and return a precise error message so the user
    // knows the issue is with their Token Key, not the API endpoint URL.
    if ($auth_config['type'] === 'timed_token') {
        $pf_creds     = array_merge(mmi_ds_resolve_credentials($auth_config), $test_cred_overrides);
        $pf_token_url = $auth_config['token_url'] ?? '';
        $pf_token_key = $pf_creds['token_key'] ?? '';
        $pf_api_key   = $pf_creds['api_key'] ?? '';

        if (empty($pf_token_url)) {
            wp_send_json_error([
                'message'          => 'Token Endpoint URL is missing. Enter it in the Authentication tab and save before testing.',
                'status_code'      => 0,
                'auth_stage'       => 'preflight',
                'response_time_ms' => 0,
            ]);
        }

        if (empty($pf_token_key) || empty($pf_api_key)) {
            wp_send_json_error([
                'message'          => 'Credentials not found in vault. Enter your Token Key and API Key in the Authentication tab, then save the configuration before testing.',
                'status_code'      => 0,
                'auth_stage'       => 'preflight',
                'response_time_ms' => 0,
            ]);
        }

        $pf_resp = wp_safe_remote_get(
            $pf_token_url . '?tkey=' . rawurlencode($pf_token_key),
            ['timeout' => 15, 'sslverify' => true]
        );

        if (is_wp_error($pf_resp)) {
            wp_send_json_error([
                'message'          => 'Token endpoint unreachable: ' . $pf_resp->get_error_message(),
                'status_code'      => 0,
                'auth_stage'       => 'preflight',
                'response_time_ms' => 0,
            ]);
        }

        $pf_sc = (int) wp_remote_retrieve_response_code($pf_resp);
        if ($pf_sc < 200 || $pf_sc >= 300) {
            wp_send_json_error([
                'message'          => "Token endpoint returned HTTP {$pf_sc} — your Token Key is likely incorrect or expired. Verify it in the Authentication tab.",
                'status_code'      => $pf_sc,
                'auth_stage'       => 'preflight',
                'response_time_ms' => 0,
            ]);
        }

        if (empty(trim(wp_remote_retrieve_body($pf_resp)))) {
            wp_send_json_error([
                'message'          => 'Token endpoint returned an empty response — cannot build the timed-token Authorization header.',
                'status_code'      => $pf_sc,
                'auth_stage'       => 'preflight',
                'response_time_ms' => 0,
            ]);
        }
    }

    // ── Guard: for timed_token, if the test URL is just the base API URL with no
    // resource path, the API will almost certainly return 403 even with valid auth.
    // Detect this early and return a targeted error pointing to the Endpoints tab.
    if ( $auth_config['type'] === 'timed_token' ) {
        $base_api_url = rtrim( $auth_config['api_url'] ?? '', '/' );
        if ( $base_api_url && rtrim( $test_url, '/' ) === $base_api_url ) {
            wp_send_json_error([
                'message'     => 'The endpoint URL is set to the base API URL with no resource path. '
                               . 'Please open the Endpoints tab and update the URL to include a specific '
                               . 'resource path (e.g. ' . $base_api_url . '/products).',
                'status_code' => 0,
                'auth_stage'  => 'endpoint_config',
            ]);
        }
    }

    $headers = mmi_ds_build_auth_headers($supplier_id, $auth_config, $test_cred_overrides);

    // Add custom headers
    $saved_configuration = json_decode(($source['configuration'] ?? '{}') ?: '{}', true) ?: [];
    $connection_config   = $config['connection'] ?? $saved_configuration;
    if (!empty($connection_config['custom_headers'])) {
        $connection_config['custom_headers'] = mmi_ds_unmask_headers($connection_config['custom_headers'], $saved_configuration['custom_headers'] ?? []);
        foreach ($connection_config['custom_headers'] as $key => $val) {
            if (!empty($key) && !empty($val)) {
                $headers[$key] = $val;
            }
        }
    }

    // Make the request — honour the endpoint's configured HTTP method
    $timeout = (int) ($connection_config['timeout'] ?? 30);
    $request_args = [
        'timeout'  => min($timeout, 30), // Cap at 30s for test
        'headers'  => $headers,
        'sslverify' => true,
    ];

    // Mirror the domain-specific header injection that HTTPClient::getJson applies.
    // xchangemarketb2b.com sits behind a WAF that rejects the default WordPress
    // user-agent with 403 before it ever evaluates the Authorization header.
    if ( strpos( $test_url, 'xchangemarketb2b.com' ) !== false ) {
        $request_args['headers']['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36';
        $request_args['headers']['Referer']    = 'https://xchangemarketb2b.com/';
    }

    $start_time = microtime(true);

    // Retry up to 2 times on 5xx — some APIs (e.g. Xchange) intermittently blip.
    // On each retry the timed-token auth header is rebuilt with a fresh token so
    // an expired token is never the cause of a retry failure.
    $max_attempts = 3;
    $response = null;
    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        // On retries, rebuild auth headers so a fresh timed token is used.
        if ($attempt > 1) {
            sleep(1);
            $request_args['headers'] = array_merge(
                mmi_ds_build_auth_headers($supplier_id, $auth_config, $test_cred_overrides),
                array_diff_key($request_args['headers'], array_flip(['Authorization']))
            );
            // Re-apply WAF-bypass headers stripped by the merge above
            if ( strpos( $test_url, 'xchangemarketb2b.com' ) !== false ) {
                $request_args['headers']['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36';
                $request_args['headers']['Referer']    = 'https://xchangemarketb2b.com/';
            }
        }
        if ($http_method === 'POST') {
            $response = wp_safe_remote_post($test_url, $request_args);
        } else {
            $response = wp_safe_remote_get($test_url, $request_args);
        }
        if (is_wp_error($response)) break;
        $sc = (int) wp_remote_retrieve_response_code($response);
        if ($sc < 500 || $sc >= 600) break; // Only retry on 5xx
    }

    $response_time_ms = (int) ((microtime(true) - $start_time) * 1000);

    if (is_wp_error($response)) {
        // Only persist status when the row exists in the DB
        if ($source) {
            $wpdb->update($table, [
                'config_status'      => 'error',
                'last_validated_at'  => current_time('mysql'),
                'updated_at'         => current_time('mysql'),
            ], ['supplier_id' => $supplier_id]);
        }

        wp_send_json_error([
            'message'          => 'Connection failed: ' . $response->get_error_message(),
            'response_time_ms' => $response_time_ms,
        ]);
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code < 200 || $status_code >= 400) {
        if ($source) {
            $wpdb->update($table, [
                'config_status'      => 'error',
                'last_validated_at'  => current_time('mysql'),
                'updated_at'         => current_time('mysql'),
            ], ['supplier_id' => $supplier_id]);
        }

        wp_send_json_error([
            'message'          => "HTTP error {$status_code}: " . wp_remote_retrieve_response_message($response),
            'response_time_ms' => $response_time_ms,
            'status_code'      => $status_code,
        ]);
    }

    $body = wp_remote_retrieve_body($response);
    $content_type = wp_remote_retrieve_header($response, 'content-type');
    $parsing_config = $config['parsing'] ?? json_decode(($source['file_config'] ?? '{}') ?: '{}', true);
    $format = $parsing_config['format'] ?? 'json';

    // Parse response
    $product_count = 0;
    $sample_data = [];

    if ($format === 'json' || strpos($content_type, 'json') !== false) {
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error([
                'message' => 'Invalid JSON response: ' . json_last_error_msg(),
                'response_time_ms' => $response_time_ms,
            ]);
        }

        // Apply data root path
        $root_path = $parsing_config['data_root_path'] ?? '';
        if (!empty($root_path)) {
            $parts = explode('.', $root_path);
            foreach ($parts as $part) {
                if (is_array($data) && isset($data[$part])) {
                    $data = $data[$part];
                }
            }
        }

        if (is_array($data)) {
            // Check if indexed array (list of items) or associative (single item/wrapper)
            if (isset($data[0]) || empty($data)) {
                $product_count = count($data);
                $sample_data = array_slice($data, 0, 3);
            } else {
                // Could be a single product or a wrapper
                $product_count = 1;
                $sample_data = [$data];
            }
        }
    } elseif ($format === 'csv' || $format === 'tsv') {
        $delimiter = $format === 'tsv' ? "\t" : ($parsing_config['delimiter'] ?? ',');
        $lines = explode("\n", trim($body));
        $product_count = max(0, count($lines) - ($parsing_config['has_header'] ?? true ? 1 : 0));

        // Parse sample
        if (!empty($lines)) {
            $header = str_getcsv($lines[0], $delimiter);
            $start = ($parsing_config['has_header'] ?? true) ? 1 : 0;
            for ($i = $start; $i < min($start + 3, count($lines)); $i++) {
                if (!empty(trim($lines[$i]))) {
                    $row = str_getcsv($lines[$i], $delimiter);
                    $sample_row = [];
                    foreach ($header as $idx => $col) {
                        $sample_row[$col] = $row[$idx] ?? '';
                    }
                    $sample_data[] = $sample_row;
                }
            }
        }
    } elseif ($format === 'xml') {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            wp_send_json_error([
                'message' => 'Invalid XML response',
                'response_time_ms' => $response_time_ms,
            ]);
        }

        $json = json_decode(json_encode($xml), true);
        $root_path = $parsing_config['data_root_path'] ?? '';
        if (!empty($root_path)) {
            $parts = explode('.', $root_path);
            foreach ($parts as $part) {
                if (is_array($json) && isset($json[$part])) {
                    $json = $json[$part];
                }
            }
        }

        $product_count = is_array($json) ? count($json) : 0;
        $sample_data = is_array($json) ? array_slice($json, 0, 3) : [];
    } elseif ($format === 'excel' || $format === 'numbers') {
        // Binary spreadsheet format — cannot be counted without PhpSpreadsheet.
        // Verify the HTTP layer succeeded (which we already confirmed above) and
        // report connectivity success without attempting to parse the binary body.
        $product_count = 0;
        $sample_data = [];
        $spreadsheet_note = ($format === 'numbers')
            ? 'Apple Numbers file detected. The connection is live. Item count is available after the first import run.'
            : 'Excel file detected. The connection is live. Item count is available after the first import run. Ensure the PhpSpreadsheet library is installed for parsing.';

        // Every other success branch in this handler persists config_status
        // here (see the general HTTP-format branch below) — this one never
        // did, so a spreadsheet source's successful test reported "validated"
        // in the JSON response but the database row was silently left behind.
        if ($source) {
            $wpdb->update($table, [
                'config_status'     => 'validated',
                'last_validated_at' => current_time('mysql'),
                'updated_at'        => current_time('mysql'),
            ], ['supplier_id' => $supplier_id]);
            mmi_pipeline_trigger_filterable_fields_calc($supplier_id);
        }

        wp_send_json_success([
            'message'          => $spreadsheet_note,
            'product_count'    => 0,
            'response_time_ms' => $response_time_ms,
            'status_code'      => $status_code,
            'sample_data'      => [],
            'config_status'    => 'validated',
            'detected_fields'  => [],
            'format_note'      => $spreadsheet_note,
        ]);
        return;
    } elseif ($format === 'txt') {
        // Plain text: count non-empty lines as a rough indicator
        $lines = array_filter(explode("\n", trim($body)), function($l) { return trim($l) !== ''; });
        $product_count = count($lines);
        $sample_data = array_map('trim', array_slice(array_values($lines), 0, 3));
    } else {
        $lines = explode("\n", trim($body));
        $product_count = count($lines);
    }

    // Update status to validated (only when the row exists in the DB)
    if ($source) {
        $wpdb->update($table, [
            'config_status'     => 'validated',
            'last_validated_at' => current_time('mysql'),
            'updated_at'        => current_time('mysql'),
        ], ['supplier_id' => $supplier_id]);
        mmi_pipeline_trigger_filterable_fields_calc($supplier_id);
    }

    // Log the test to fetch log (only if source is saved in DB)
    $fetch_log_table = $wpdb->prefix . 'mmi_data_source_fetch_log';
    if ($source && $wpdb->get_var("SHOW TABLES LIKE '{$fetch_log_table}'") === $fetch_log_table) {
        $wpdb->insert($fetch_log_table, [
            'source_id' => $source['id'],
            'fetch_status' => 'test_success',
            'product_count' => $product_count,
            'response_time_ms' => $response_time_ms,
            'fetched_at' => current_time('mysql'),
        ]);
    }

    wp_send_json_success([
        'message' => 'Connection successful',
        'product_count' => $product_count,
        'response_time_ms' => $response_time_ms,
        'status_code' => $status_code,
        'sample_data' => $sample_data,
        'config_status' => 'validated',
        'detected_fields' => !empty($sample_data[0]) ? array_keys($sample_data[0]) : [],
    ]);
});

/**
 * Get auth field definitions for dynamic form rendering
 */
add_action('wp_ajax_mmi_get_auth_fields', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Unauthorized']);
        return;
    }

    $auth_type = sanitize_text_field($_POST['auth_type'] ?? 'none');

    $fields = mmi_ds_get_auth_type_fields($auth_type);

    wp_send_json_success([
        'auth_type' => $auth_type,
        'fields' => $fields,
        'html' => mmi_ds_render_auth_fields_html($auth_type, $fields),
    ]);
});

/**
 * Reorder suppliers (drag & drop)
 */
add_action('wp_ajax_mmi_reorder_suppliers', function () {
    check_ajax_referer('mmi_pipeline_import_settings', 'nonce');

    if (!mmi_data_pipeline_user_can()) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $order = $_POST['order'] ?? [];
    if (!is_array($order)) {
        wp_send_json_error(['message' => 'Invalid order data']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';

    foreach ($order as $index => $supplier_id) {
        $wpdb->update(
            $table,
            ['display_order' => (int) $index],
            ['supplier_id' => sanitize_text_field($supplier_id)]
        );
    }

    wp_send_json_success(['message' => 'Order saved']);
});

// ─── Helper Functions ───────────────────────────────────────────────────────

/**
 * Sync enabled suppliers from wp_mmi_data_sources to mmi_pipeline_enabled_suppliers option
 */
function mmi_ds_sync_enabled_suppliers() {
    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        return;
    }

    // A source counts as "enabled" the moment it's validated — no separate
    // manual step (see AGENTS.md's "Enabled Toggle Eliminated" entry). Kept
    // only as a legacy mirror for the rare fresh-install-before-migration
    // fallback in class-pipeline-admin.php; every real gating decision now
    // queries wp_mmi_data_sources.config_status directly.
    $enabled = $wpdb->get_col(
        "SELECT supplier_id FROM {$table} WHERE config_status = 'validated' ORDER BY display_order ASC"
    );

    \MMI_DB::set_setting( 'mmi_pipeline_enabled_suppliers', $enabled ?: [] );
}

/**
 * Build auth headers for a given supplier and auth config
 */
function mmi_ds_build_auth_headers(string $supplier_id, array $auth_config, array $override_creds = []): array {
    $type = $auth_config['type'] ?? 'none';
    $headers = [];

    // Resolve credentials from wp_mmi, then apply any caller-supplied overrides.
    // Overrides are used when the test handler has unsaved form credentials to try.
    $creds = array_merge(mmi_ds_resolve_credentials($auth_config), $override_creds);

    switch ($type) {
        case 'basic':
            $username = $creds['username'] ?? '';
            $password = $creds['password'] ?? '';
            if ($username && $password) {
                $headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$password}");
            }
            break;

        case 'bearer':
            $token = $creds['token'] ?? $creds['api_token'] ?? '';
            if ($token) {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
            break;

        case 'timed_token':
            // Fetch timed token first, then use Basic Auth.
            // The token endpoint MUST return HTTP 2xx; error response bodies are never
            // used as a token, which would produce a corrupted Authorization header.
            $token_url = $auth_config['token_url'] ?? ($creds['token_url'] ?? '');
            $token_key = $creds['token_key'] ?? '';
            $api_key = $creds['api_key'] ?? '';

            if ($token_url && $token_key) {
                $url = $token_url . '?tkey=' . rawurlencode($token_key);
                $resp = wp_safe_remote_get($url, ['timeout' => 15, 'sslverify' => true]);

                if (!is_wp_error($resp)) {
                    $token_sc = (int) wp_remote_retrieve_response_code($resp);
                    if ($token_sc >= 200 && $token_sc < 300) {
                        $timed_token = trim(wp_remote_retrieve_body($resp));
                        if ($timed_token && $api_key) {
                            $headers['Authorization'] = 'Basic ' . base64_encode("{$api_key}:{$timed_token}");
                        }
                    }
                    // Non-2xx: no Authorization header is set; the subsequent API call
                    // will also fail, making the problem visible to the caller.
                }
            }
            break;

        case 'api_key_header':
            $header_name = $auth_config['header_name'] ?? 'X-API-Key';
            $api_key = $creds['api_key'] ?? '';
            if ($api_key) {
                $headers[$header_name] = $api_key;
            }
            break;

        case 'oauth2_client_credentials':
            $token_url = $auth_config['token_url'] ?? '';
            $client_id = $creds['client_id'] ?? '';
            $client_secret = $creds['client_secret'] ?? '';

            if ($token_url && $client_id && $client_secret) {
                $token_resp = wp_safe_remote_post($token_url, [
                    'timeout' => 15,
                    'body' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $client_id,
                        'client_secret' => $client_secret,
                        'scope' => $auth_config['scope'] ?? '',
                    ],
                ]);

                if (!is_wp_error($token_resp)) {
                    $token_data = json_decode(wp_remote_retrieve_body($token_resp), true);
                    if (!empty($token_data['access_token'])) {
                        $headers['Authorization'] = 'Bearer ' . $token_data['access_token'];
                    }
                }
            }
            break;

        case 'custom_headers':
            $custom = $auth_config['custom_headers'] ?? [];
            foreach ($custom as $key => $val) {
                if (!empty($key) && !empty($val)) {
                    $headers[$key] = $val;
                }
            }
            break;

        case 'none':
        default:
            break;
    }

    // Add any auth-level custom headers
    if (!empty($auth_config['custom_headers'])) {
        foreach ($auth_config['custom_headers'] as $key => $val) {
            if (!empty($key) && !empty($val) && !isset($headers[$key])) {
                $headers[$key] = $val;
            }
        }
    }

    return $headers;
}

/**
 * Resolve credential values from wp_mmi table using auth_config references
 */
function mmi_ds_resolve_credentials(array $auth_config): array {
    $credential_keys = $auth_config['credential_keys'] ?? [];
    $tab = $auth_config['credential_tab'] ?? 'Credentials & API Keys';

    if (empty($credential_keys)) {
        return [];
    }

    global $wpdb;
    $mmi_table = class_exists( 'MMI_Settings' ) ? \MMI_Settings::ensure_table() : $wpdb->prefix . 'mmi';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$mmi_table}'") !== $mmi_table) {
        return [];
    }

    $resolved = [];
    foreach ($credential_keys as $param => $field_name) {
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT field_value FROM {$mmi_table} WHERE tab_name = %s AND field_name = %s",
            $tab,
            $field_name
        ));
        $resolved[$param] = $value ?: '';
    }

    return $resolved;
}

/**
 * Resolve credentials but mask the values for frontend display
 */
function mmi_ds_resolve_credentials_masked(array $auth_config): array {
    $resolved = mmi_ds_resolve_credentials($auth_config);
    $masked = [];

    // Get field type definitions to determine which fields are secrets vs plain config
    $auth_type = $auth_config['type'] ?? 'none';
    $field_defs = mmi_ds_get_auth_type_fields($auth_type);
    $field_type_map = [];
    foreach ($field_defs as $f) {
        $field_type_map[$f['key']] = $f['type'];
    }

    foreach ($resolved as $key => $value) {
        if (empty($value)) {
            $masked[$key] = '';
        } elseif (($field_type_map[$key] ?? 'password') === 'password') {
            // Unknown keys are treated as secrets (fail closed).
            // Fixed-length mask: a proportional one would reveal the secret's length.
            $masked[$key] = str_repeat('•', 12);
        } else {
            // Non-secret fields (URLs, header names, etc.): show the real value
            $masked[$key] = $value;
        }
    }

    return $masked;
}

/**
 * Save a credential to the wp_mmi table
 */
function mmi_ds_save_credential(string $field_name, string $value): bool {
    global $wpdb;

    $mmi_table = class_exists( 'MMI_Settings' ) ? \MMI_Settings::ensure_table() : $wpdb->prefix . 'mmi';
    $tab = 'Credentials & API Keys';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$mmi_table}'") !== $mmi_table) {
        return false;
    }

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$mmi_table} WHERE tab_name = %s AND field_name = %s",
        $tab,
        $field_name
    ));

    if ($exists) {
        return $wpdb->update(
            $mmi_table,
            [
                'field_value' => $value,
                'updated_at' => current_time('mysql'),
            ],
            [
                'tab_name' => $tab,
                'field_name' => $field_name,
            ]
        ) !== false;
    } else {
        return $wpdb->insert($mmi_table, [
            'tab_name' => $tab,
            'field_name' => $field_name,
            'field_value' => $value,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]) !== false;
    }
}

/**
 * Sanitize a headers array
 */
/**
 * Header names whose values are credentials (Authorization, X-Api-Key,
 * Cookie, X-Auth-Token …) — masked before a config reaches the browser.
 */
function mmi_ds_is_sensitive_header(string $name): bool {
    return (bool) preg_match('/auth|token|key|secret|pass|cookie|signature|session|credential|bearer/i', $name);
}

/** Replace sensitive header values with a fixed bullet mask. */
function mmi_ds_mask_headers($headers): array {
    $out = [];
    foreach ((array) $headers as $name => $value) {
        $out[$name] = ($value !== '' && $value !== null && mmi_ds_is_sensitive_header((string) $name))
            ? str_repeat('•', 12)
            : $value;
    }
    return $out;
}

/**
 * A submitted bullet mask means "unchanged": put the stored value back. A
 * mask with no stored value behind it is dropped, never sent as a header.
 */
function mmi_ds_unmask_headers($submitted, $stored): array {
    $stored = (array) $stored;
    $out    = [];
    foreach ((array) $submitted as $name => $value) {
        if (is_string($value) && preg_match('/^\x{2022}+$/u', $value)) {
            if (isset($stored[$name]) && $stored[$name] !== '') {
                $out[$name] = $stored[$name];
            }
            continue;
        }
        $out[$name] = $value;
    }
    return $out;
}

function mmi_ds_sanitize_headers($headers): array {
    if (!is_array($headers)) {
        return [];
    }

    $clean = [];
    foreach ($headers as $key => $value) {
        $key = sanitize_text_field($key);
        $value = sanitize_text_field($value);
        if (!empty($key)) {
            $clean[$key] = $value;
        }
    }

    return $clean;
}

/**
 * Get field definitions for a given auth type
 */
function mmi_ds_get_auth_type_fields(string $type): array {
    $types = [
        'none' => [],
        'basic' => [
            ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true],
        ],
        'bearer' => [
            ['key' => 'token', 'label' => 'Bearer Token', 'type' => 'password', 'required' => true],
        ],
        'timed_token' => [
            ['key' => 'token_url', 'label' => 'Token Endpoint URL', 'type' => 'url', 'required' => true, 'description' => 'URL to fetch the timed token from'],
            ['key' => 'token_key', 'label' => 'Token Key', 'type' => 'password', 'required' => true, 'description' => 'Key used to request the timed token'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'description' => 'API key used with the timed token for Basic Auth'],
        ],
        'api_key_header' => [
            ['key' => 'header_name', 'label' => 'Header Name', 'type' => 'text', 'required' => true, 'placeholder' => 'X-API-Key'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
        ],
        'api_key_query' => [
            ['key' => 'param_name', 'label' => 'Query Parameter Name', 'type' => 'text', 'required' => true, 'placeholder' => 'api_key'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
        ],
        'oauth2_client_credentials' => [
            ['key' => 'token_url', 'label' => 'Token Endpoint URL', 'type' => 'url', 'required' => true],
            ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
            ['key' => 'scope', 'label' => 'Scope (optional)', 'type' => 'text', 'required' => false, 'placeholder' => 'read products'],
        ],
        'oauth2_auth_code' => [
            ['key' => 'auth_url', 'label' => 'Authorization URL', 'type' => 'url', 'required' => true],
            ['key' => 'token_url', 'label' => 'Token Endpoint URL', 'type' => 'url', 'required' => true],
            ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
            ['key' => 'redirect_uri', 'label' => 'Redirect URI', 'type' => 'url', 'required' => true],
            ['key' => 'scope', 'label' => 'Scope (optional)', 'type' => 'text', 'required' => false],
        ],
        'hmac' => [
            ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password', 'required' => true],
            ['key' => 'algorithm', 'label' => 'Algorithm', 'type' => 'select', 'required' => true, 'options' => ['sha256', 'sha512', 'sha1', 'md5']],
            ['key' => 'header_name', 'label' => 'Signature Header', 'type' => 'text', 'required' => true, 'placeholder' => 'X-Signature'],
        ],
        'custom_headers' => [
            ['key' => 'custom_headers', 'label' => 'Custom Headers', 'type' => 'key_value_pairs', 'required' => true, 'description' => 'Add custom HTTP headers as key-value pairs'],
        ],
    ];

    return $types[$type] ?? [];
}

/**
 * Render auth fields as HTML for dynamic form
 */
function mmi_ds_render_auth_fields_html(string $auth_type, array $fields): string {
    if (empty($fields)) {
        if ($auth_type === 'none') {
            return '<p class="mmi-auth-info"><span class="dashicons dashicons-info"></span> No authentication required for this data source.</p>';
        }
        return '';
    }

    $html = '';

    foreach ($fields as $field) {
        $key = esc_attr($field['key']);
        $label = esc_html($field['label']);
        $type = $field['type'];
        $required = !empty($field['required']) ? ' required' : '';
        $placeholder = esc_attr($field['placeholder'] ?? '');
        $description = $field['description'] ?? '';

        if ($type === 'key_value_pairs') {
            $html .= '<div class="mmi-config-field mmi-auth-field">';
            $html .= '<label>' . $label . '</label>';
            if ($description) {
                $html .= '<small class="mmi-field-description">' . esc_html($description) . '</small>';
            }
            $html .= '<div id="auth-custom-headers-list" class="mmi-kv-pairs">';
            $html .= '<div class="mmi-kv-row"><input type="text" class="mmi-kv-key" placeholder="Header Name"><input type="text" class="mmi-kv-value" placeholder="Header Value"><button type="button" class="button mmi-kv-remove">&times;</button></div>';
            $html .= '</div>';
            $html .= '<button type="button" class="button mmi-add-kv-row mmi-action-btn" data-target="auth-custom-headers-list"><span class="dashicons dashicons-plus"></span> Add Header</button>';
            $html .= '</div>';
        } elseif ($type === 'select') {
            $html .= '<div class="mmi-config-field mmi-auth-field">';
            $html .= '<label for="auth-' . $key . '">' . $label . '</label>';
            $html .= '<select id="auth-' . $key . '" class="mmi-auth-input" data-key="' . $key . '"' . $required . '>';
            foreach ($field['options'] as $opt) {
                $html .= '<option value="' . esc_attr($opt) . '">' . esc_html($opt) . '</option>';
            }
            $html .= '</select>';
            if ($description) {
                $html .= '<small class="mmi-field-description">' . esc_html($description) . '</small>';
            }
            $html .= '</div>';
        } else {
            // Always render as type="text" — using type="password" triggers browser
            // "Save Password" dialogs since the browser treats any password input near
            // text inputs as a login form. Visual masking is handled by the JS sentinel
            // value (••••••••) and the data-orig-type attribute.
            $input_type = ($type === 'url') ? 'url' : 'text';
            $orig_type_attr = ($type === 'password') ? ' data-orig-type="password"' : '';
            $html .= '<div class="mmi-config-field mmi-auth-field">';
            $html .= '<label for="auth-' . $key . '">' . $label . '</label>';
            $html .= '<input type="' . $input_type . '" id="auth-' . $key . '" class="mmi-auth-input" data-key="' . $key . '" placeholder="' . $placeholder . '" autocomplete="off"' . $required . $orig_type_attr . '>';
            if ($description) {
                $html .= '<small class="mmi-field-description">' . esc_html($description) . '</small>';
            }
            $html .= '</div>';
        }
    }

    return $html;
}

/**
 * Return definitions for preconfigured (first-party) API integrations.
 *
 * Each template pre-populates the data source authentication config and
 * default endpoints so the user only needs to verify credentials before
 * enabling the source.  The `credential_keys` map points to the same vault
 * field names used by the legacy CLI updaters (XchangeUpdater / SkuPortUpdater)
 * so existing credentials are immediately picked up without re-entry.
 *
 * @return array<string, array>
 */
/*
 * Template keys beyond connection/auth (2026-10-06):
 *   auth_params            — auth settings the integration fixes (not credentials);
 *                            enforced on setup and save, hidden in the Configure modal.
 *   distribution_term_slug — Distribution term for the source's products when a
 *                            profile does not map Distribution itself.
 *   taxonomy_fields        — the feed fields that hold brand/category, offered in
 *                            Taxonomy Mapping before any profile maps them.
 * mmi_ds_complete_template_setup() applies all of them, so a source row created
 * any way (Add Data Source, a script, an older version) ends up fully set up.
 */
function mmi_ds_get_preconfigured_templates(): array {
    return [

        // ── Xchange ────────────────────────────────────────────────
        'xchange' => [
            'supplier_id'         => 'xchange',
            'supplier_name'       => 'Xchange',
            'source_type'         => 'api',
            'auth_type'           => 'timed_token',
            'documentation_url'   => '',
            'notes'               => 'Xchange B2B API. Fetches products and promotions via a timed-token authentication scheme. Token and API keys are stored in the Credentials & API Keys vault.',
            // Vault field names used by the legacy XchangeUpdater CLI class
            'base_url_vault_key'  => 'xchange-api-url',   // stored in vault as full base URL
            'token_url_vault_key' => 'xchange-token-url', // stored in vault; goes into auth_config.token_url
            // Credential key references: param name → vault field name
            'credential_keys'     => [
                'token_key' => 'xchange-token-key',
                'api_key'   => 'xchange-api-key',
            ],
            // Default endpoints (paths relative to base_url)
            'endpoints' => [
                [
                    'name'           => 'Products',
                    'path'           => '/products/',
                    'params'         => 'include_long_desc=yes&include_webassets=yes',
                    'format'         => 'json',
                    'data_root_path' => 'products',
                    'is_primary'     => true,
                ],
                [
                    'name'           => 'Promotions',
                    'path'           => '/promotions/',
                    'params'         => 'include_long_desc=yes&include_webassets=yes',
                    'format'         => 'json',
                    'data_root_path' => 'promotions',
                    'is_primary'     => false,
                ],
            ],
            'data_root_path' => 'products',
            'distribution_term_slug' => 'x',
            'taxonomy_fields' => [
                [ 'source_field' => 'brand',                         'wc_taxonomy' => 'product_brand' ],
                [ 'source_field' => 'master_category+sub_category', 'wc_taxonomy' => 'product_cat' ],
            ],
        ],

        // ── SkuPort ──────────────────────────────────────────────────────
        'skuport' => [
            'supplier_id'         => 'skuport',
            'supplier_name'       => 'SkuPort',
            'source_type'         => 'api',
            'auth_type'           => 'basic',
            'documentation_url'   => '',
            'notes'               => 'SkuPort inventory API. Authenticates with HTTP Basic Auth. Credentials are stored in the Credentials & API Keys vault.',
            'base_url_vault_key'  => 'skuport-api-url',
            'token_url_vault_key' => '',  // no timed token — basic auth only
            'credential_keys'     => [
                'username' => 'skuport-remote-username',
                'password' => 'skuport-remote-password',
            ],
            // SkuPort requires a specific Accept header on every request
            'extra_headers' => [
                'Accept' => 'application/skuport-v2+json',
            ],
            'endpoints' => [
                [
                    'name'           => 'Products',
                    'path'           => '/products',
                    'params'         => '',
                    'format'         => 'json',
                    'data_root_path' => '',
                    'is_primary'     => true,
                ],
                [
                    'name'           => 'Promos',
                    'path'           => '/promos',
                    'params'         => '',
                    'format'         => 'json',
                    'data_root_path' => '',
                    'is_primary'     => false,
                ],
            ],
            'data_root_path' => '',
            'distribution_term_slug' => 's',
            'taxonomy_fields' => [
                [ 'source_field' => 'developer', 'wc_taxonomy' => 'product_brand' ],
            ],
        ],

        // ── Plugivery ────────────────────────────────────────────────────
        // Fetched by MMI_Pipeline_Plugivery_Updater (quota-ledgered, 300
        // reads/day) — never the generic endpoint fetcher, so no endpoints
        // here; Test uses the updater's free act=info check instead.
        'plugivery' => [
            'supplier_id'         => 'plugivery',
            'supplier_name'       => 'Plugivery',
            'source_type'         => 'api',
            'auth_type'           => 'api_key_query',
            'documentation_url'   => 'https://www.plugivery.com/store/help/dev/',
            'notes'               => 'Plugivery Catalog API v1.31. Token (query parameter) + IP allowlist; 300 reads/day, 4,000/month. Credentials are stored in the Credentials & API Keys vault.',
            'base_url_vault_key'  => 'plugivery-api-url',
            'token_url_vault_key' => '',
            'credential_keys'     => [
                'api_key' => 'plugivery-api-token',
            ],
            // The token goes in ?token= (MMI_Pipeline_Plugivery_Updater::request()).
            // Fixed by the integration, so the Configure modal does not ask for it.
            'auth_params'    => [
                'param_name' => 'token',
            ],
            'endpoints'      => [],
            'data_root_path' => '',
            'distribution_term_slug' => 'p',
            'taxonomy_fields' => [
                [ 'source_field' => 'brand_name', 'wc_taxonomy' => 'product_brand' ],
                [ 'source_field' => 'cat_name',   'wc_taxonomy' => 'product_cat' ],
            ],
        ],

    ];
}

/**
 * The template a data source row was created from, or null for a custom
 * source. Rows created before preconfigured_template was recorded match by
 * supplier_id, the same fallback the Configure modal uses.
 *
 * @param array $source Row from wp_mmi_data_sources (configuration raw or decoded).
 */
function mmi_ds_template_for_source(array $source): ?array {
    $configuration = is_array($source['configuration'] ?? null)
        ? $source['configuration']
        : (json_decode((string) ($source['configuration'] ?? ''), true) ?: []);
    $name = (string) ($configuration['preconfigured_template'] ?? '') ?: (string) ($source['supplier_id'] ?? '');
    return mmi_ds_get_preconfigured_templates()[$name] ?? null;
}

/**
 * Bring a template source's row up to its template: everything Add Data
 * Source sets, plus the template's fixed auth params and Distribution term.
 * Fills what is missing and never overwrites a value the admin set, except
 * auth_params, which the integration fixes.
 *
 * Idempotent. Called after Add Data Source creates a template source, and by
 * MMI_Pipeline_Migration for rows that already exist (Plugivery was inserted
 * by a script on 2026-09-25 and never got these).
 *
 * @return string[] What was filled in (empty when nothing changed).
 */
function mmi_ds_complete_template_setup(string $supplier_id): array {
    global $wpdb;
    $table = $wpdb->prefix . 'mmi_data_sources';

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE supplier_id = %s", $supplier_id), ARRAY_A);
    $tpl = $row ? mmi_ds_template_for_source($row) : null;
    if (!$tpl) {
        return [];
    }

    $configuration = json_decode((string) ($row['configuration'] ?? ''), true) ?: [];
    $auth          = json_decode((string) ($row['auth_config'] ?? ''), true) ?: [];
    $filled        = [];
    $update        = [];

    if (empty($configuration['preconfigured_template'])) {
        $configuration['preconfigured_template'] = $tpl['supplier_id'];
        $update['configuration'] = wp_json_encode($configuration);
        $filled[] = 'preconfigured_template';
    }

    // Declared taxonomy fields (2026-10-07, TEMPLATE_SETUP_VERSION 2): the
    // template's brand/category fields become the row's own declaration,
    // which the admin can then edit in the Configure modal. Only when the
    // key has never been saved — an emptied list is a deliberate answer.
    if (!array_key_exists('taxonomy_fields', $configuration) && !empty($tpl['taxonomy_fields'])) {
        $configuration['taxonomy_fields'] = \MMI_Pipeline_Field_Mapping_Defaults::normalize_taxonomy_fields((array) $tpl['taxonomy_fields']);
        $update['configuration'] = wp_json_encode($configuration);
        $filled[] = 'taxonomy fields';
    }

    $auth_before = $auth;
    if (empty($auth['type']) || $auth['type'] === 'none') {
        $auth['type'] = $tpl['auth_type'];
        $filled[] = 'auth type';
    }
    foreach ((array) ($tpl['credential_keys'] ?? []) as $param => $vault_key) {
        if (empty($auth['credential_keys'][$param])) {
            $auth['credential_keys'][$param] = $vault_key;
            $filled[] = "credential key {$param}";
        }
    }
    if (empty($auth['credential_tab'])) {
        $auth['credential_tab'] = 'Credentials & API Keys';
    }
    foreach ((array) ($tpl['auth_params'] ?? []) as $param => $value) {
        if (($auth[$param] ?? null) !== $value) {
            $auth[$param] = $value;
            $filled[] = "auth {$param}";
        }
    }
    if ($auth !== $auth_before) {
        $update['auth_config'] = wp_json_encode($auth);
    }

    if (empty($row['distribution_term_slug']) && !empty($tpl['distribution_term_slug'])) {
        $update['distribution_term_slug'] = $tpl['distribution_term_slug'];
        $filled[] = 'distribution term ' . $tpl['distribution_term_slug'];
    }

    if ($update) {
        $update['updated_at'] = current_time('mysql');
        $wpdb->update($table, $update, ['supplier_id' => $supplier_id]);
        \MMI_Logger::info(
            "Data source {$supplier_id}: completed template setup (" . implode(', ', $filled) . ')',
            [],
            'general',
            'MMI_Data_Source_Setup'
        );
    }
    return $filled;
}

/**
 * Template list for the admin JS and the Add Data Source picker — the one
 * list both read, instead of each keeping its own copy (Plugivery was missing
 * from both copies, so it could never be added from the UI).
 *
 * @return array<string, array{label:string, supplierName:string, fixedAuthKeys:string[]}>
 */
function mmi_ds_preconfigured_templates_for_js(): array {
    $out = [];
    foreach (mmi_ds_get_preconfigured_templates() as $id => $tpl) {
        $out[$id] = [
            'label'          => $tpl['supplier_name'],
            'supplierName'   => $tpl['supplier_name'],
            'fixedAuthKeys'  => array_keys((array) ($tpl['auth_params'] ?? [])),
            'taxonomyFields' => array_values((array) ($tpl['taxonomy_fields'] ?? [])),
        ];
    }
    return $out;
}

