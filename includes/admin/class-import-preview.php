<?php
/**
 * Import Preview Generator
 * Shows upcoming changes before running actual import
 *
 * @package MannMade\DataPipeline
 */

if (!defined('ABSPATH')) {
    exit;
}

class MMI_Import_Preview {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('wp_ajax_mmi_generate_import_preview', [$this, 'handle_generate_preview']);
        add_action('wp_ajax_mmi_import_single_item', [$this, 'handle_import_single_item']);
        add_action('wp_ajax_mmi_dismiss_sku_conflict', [$this, 'handle_dismiss_sku_conflict']);
        add_action('wp_ajax_mmi_dismiss_sku_conflicts_bulk', [$this, 'handle_dismiss_sku_conflicts_bulk']);
        add_action('wp_ajax_mmi_inspect_source_record', [$this, 'handle_inspect_source_record']);

        // Per-profile "how much pending create/update work is sitting in the
        // source feed right now" — backs the Import Profiles grid's Pending
        // column and the schedule table's "Skip if no new data" gate (see
        // MMI_Pipeline_Cron::run_scheduled_profile_import()). Computed via
        // the same generate_preview() this class already uses everywhere
        // else, just summed across a profile's sources and cached — see
        // get_profile_pending_stats() below.
        add_action('wp_ajax_mmi_pipeline_get_profile_pending_stats', [$this, 'handle_get_profile_pending_stats']);
        add_action('mmi_pipeline_as_warm_profile_pending_stats', [$this, 'warm_pending_stats_action']);

        // Opportunistic cache refresh from a live Review & Compare full scan —
        // see handle_report_live_pending_stats()'s own docblock.
        add_action('wp_ajax_mmi_pipeline_report_live_pending_stats', [$this, 'handle_report_live_pending_stats']);
    }

    /**
     * Per-profile setting name storing SKUs the user has confirmed are
     * genuine duplicates of an existing product — see dismiss_sku_conflict().
     * Dismissing means "never try to import this again", not "stop warning
     * me and create it anyway" — see get_dismissed_sku_conflicts() callers in
     * class-dynamic-product-importer.php, which skip these at real-import time too.
     */
    public static function dismissed_sku_conflicts_key($profile) {
        return 'mmi_dismissed_sku_conflicts_' . $profile;
    }

    /** SKUs dismissed (permanently excluded from import) for a given profile. */
    public static function get_dismissed_sku_conflicts($profile) {
        return (array) MMI_DB::get_setting( self::dismissed_sku_conflicts_key( $profile ), [] );
    }

    /** Persist a dismissed SKU conflict for a profile (idempotent). */
    private function dismiss_sku_conflict($profile, $sku) {
        $dismissed = self::get_dismissed_sku_conflicts($profile);
        if (!in_array($sku, $dismissed, true)) {
            $dismissed[] = $sku;
            MMI_DB::set_setting( self::dismissed_sku_conflicts_key( $profile ), $dismissed );
        }
    }

    /**
     * AJAX: skip a single SKU conflict — permanently excluded from this
     * profile's preview/import going forward (see preview_single_item()).
     */
    public function handle_dismiss_sku_conflict() {
        check_ajax_referer('mmi_product_importer_nonce', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $profile = sanitize_text_field($_POST['profile'] ?? 'default');
        $sku = sanitize_text_field($_POST['sku'] ?? '');

        if ($sku === '') {
            wp_send_json_error(['message' => 'Missing sku']);
        }

        $this->dismiss_sku_conflict($profile, $sku);

        wp_send_json_success(['message' => 'Product will no longer be imported']);
    }

    /** AJAX: permanently skip every SKU conflict in a supplied list at once. */
    public function handle_dismiss_sku_conflicts_bulk() {
        check_ajax_referer('mmi_product_importer_nonce', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $profile = sanitize_text_field($_POST['profile'] ?? 'default');
        $skus_json = stripslashes($_POST['skus'] ?? '[]');
        $skus = array_filter(array_map('sanitize_text_field', (array) json_decode($skus_json, true)));

        foreach ($skus as $sku) {
            $this->dismiss_sku_conflict($profile, $sku);
        }

        mmi_data_pipeline_audit('import.dismiss_sku_conflicts', [
            'object_type' => 'import_profile',
            'object_id'   => $profile,
            'outcome'     => 'success',
            'details'     => ['count' => count($skus)],
        ]);

        wp_send_json_success(['message' => count($skus) . ' product(s) will no longer be imported', 'count' => count($skus)]);
    }
    
    /**
     * Handle AJAX request for import preview
     */
    public function handle_generate_preview() {
        check_ajax_referer('mmi_product_importer_nonce', 'nonce');
        
        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }
        
        $supplier = sanitize_text_field($_POST['supplier'] ?? '');
        $limit = (int) ($_POST['limit'] ?? 10);
        $preview_fields_json = $_POST['preview_fields'] ?? '[]';
        $preview_fields = json_decode(stripslashes($preview_fields_json), true);
        $profile = sanitize_text_field($_POST['profile'] ?? 'default');

        // Collect WC product filter params from the shared filter bar, if available.
        $wc_filters = class_exists('MMI_WC_Product_Filter_Handler')
            ? MMI_WC_Product_Filter_Handler::sanitize($_POST)
            : [];

        // Validate profile exists
        $profiles = MMI_DB::get_profiles();
        if (!isset($profiles[$profile])) {
            $profile = 'default';
        }

        if (empty($supplier)) {
            wp_send_json_error(['message' => 'Supplier parameter required']);
        }

        try {
            $preview_data = $this->generate_preview($supplier, $limit, $preview_fields, $profile, $wc_filters);
            wp_send_json_success($preview_data);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle AJAX request to spot-check-import ONE product directly from the
     * Review & Compare table (the clickable "UPDATE (n)" status badge) —
     * lets the user verify the profile's current field mappings actually
     * produce the expected result on a real product without running/waiting
     * on a full batch import.
     */
    public function handle_import_single_item() {
        check_ajax_referer('mmi_product_importer_nonce', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $supplier    = sanitize_text_field($_POST['supplier'] ?? '');
        $primary_key = sanitize_text_field($_POST['primary_key'] ?? '');
        $profile     = sanitize_text_field($_POST['profile'] ?? 'default');

        $profiles = MMI_DB::get_profiles();
        if (!isset($profiles[$profile])) {
            $profile = 'default';
        }

        if (empty($supplier) || $primary_key === '') {
            wp_send_json_error(['message' => 'Supplier and primary key are required']);
        }

        try {
            $result = $this->import_single_item($supplier, $primary_key, $profile);
            mmi_data_pipeline_audit('import.single_item', [
                'object_type' => 'product',
                'object_id'   => is_array($result) ? (int) ($result['product_id'] ?? 0) : 0,
                'outcome'     => 'success',
                'details'     => ['supplier' => $supplier, 'primary_key' => $primary_key, 'profile' => $profile],
            ]);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Import (create-or-update) ONE product identified by its supplier +
     * primary key, using the profile's live field mappings. Reuses the exact
     * same Product_Import_Worker::import_single_product() the real batch
     * import calls, so the result matches what a full run would do to this
     * product — this is what makes it a trustworthy "spot check".
     *
     * This is a deliberate, explicit single-item action triggered by the
     * user — unlike an automatic batch run, it always allows create/update
     * regardless of the profile's product_scope ("new_only") or the global
     * duplicate_strategy ("skip") settings, since those exist to guard
     * unattended batch behavior, not a one-off manual verification click.
     */
    public function import_single_item($supplier, $primary_key, $profile = 'default') {
        if (!class_exists('MannMade\DataPipeline\AJAX\Product_Import_Worker')) {
            throw new Exception('Import worker is unavailable.');
        }

        $json_dir  = mmi_shared_lib_json_dir();
        $json_file = $this->get_json_file_for_supplier($supplier);
        $json_path = $json_dir . $json_file;

        if (!file_exists($json_path)) {
            throw new Exception("JSON file not found for {$supplier}: {$json_file}. Please fetch supplier data first.");
        }

        // Same mtime-keyed transient generate_preview() uses — this handler
        // fires once per row the admin clicks "import" on, and re-reading a
        // 13MB+ feed from disk on every click is exactly what AGENTS.md
        // forbids ("Never file_get_contents() a source-feed JSON (>1MB)
        // inside an AJAX handler"). Sharing the cache key means a click here
        // also warms/reuses the preview's copy rather than duplicating it.
        $cache_key = 'mmi_pipeline_feed_' . $supplier . '_' . filemtime( $json_path );
        $data      = get_transient( $cache_key );

        if ( false === $data ) {
            $json_content = file_get_contents($json_path);
            $data         = json_decode($json_content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in {$supplier} feed file ({$json_file}): " . json_last_error_msg());
            }

            set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
        }

        $items              = $this->extract_items($data, $supplier);
        $primary_key_source = MMI_DB::get_primary_key($supplier, 'source', 'id');
        $primary_key_wc     = MMI_DB::get_primary_key($supplier, 'wc', '_sku');

        // Find the raw source item matching the clicked row's primary key —
        // string comparison since source keys can be numeric or alphanumeric.
        $raw_item = null;
        foreach ($items as $candidate) {
            $candidate_key = MMI_Pipeline_Field_Resolver::get_nested_value($candidate, $primary_key_source);
            if ((string) $candidate_key === (string) $primary_key) {
                $raw_item = $candidate;
                break;
            }
        }

        if ($raw_item === null) {
            throw new Exception("Item with primary key \"{$primary_key}\" was not found in the {$supplier} feed. Try refreshing the preview — the feed may have changed.");
        }

        $mappings = MMI_Pipeline_Field_Mapping_Defaults::get_effective($profile);

        // Inject the matching promo record (mirrors generate_preview()) so
        // promo-sourced fields (e.g. 'promotions.street_price') resolve during mapping.
        $promo_result      = MMI_Pipeline_Field_Resolver::load_and_index_promotions($supplier, $json_dir);
        $promotions_index  = $promo_result['index'];
        $promo_namespace   = $promo_result['namespace'];
        if (!empty($promotions_index)) {
            $matched_promo = $promotions_index[(string) $primary_key] ?? null;
            if ($matched_promo !== null) {
                // Correct a self-discounting base feed before mapping — see
                // MMI_Pipeline_Field_Resolver::resolve_true_regular_price().
                $raw_item = MMI_Pipeline_Field_Resolver::correct_self_discounted_regular_price($raw_item, $matched_promo, $mappings, $supplier);
            }
            $raw_item = MMI_Pipeline_Field_Resolver::inject_promotion($raw_item, $primary_key, $promotions_index, $promo_namespace);
        }

        // Build the same optional Attributes wizard config the real batch import
        // uses, so a spot-check accurately reflects what a full run would apply.
        $attribute_manager = null;
        if (function_exists('mmi_get_attribute_config')) {
            $attribute_config = mmi_get_attribute_config($profile);
            if (
                !empty($attribute_config['enabled']) &&
                !empty($attribute_config['attributes']) &&
                class_exists('MannMade\DataPipeline\Importers\Variable_Product_Manager')
            ) {
                $attribute_manager = new \MannMade\DataPipeline\Importers\Variable_Product_Manager(
                    $attribute_config,
                    function (string $message, string $type = 'info') {
                        $level = in_array($type, ['error', 'warn', 'debug'], true) ? $type : 'info';
                        call_user_func([MMI_Logger::class, $level], $message, [], 'sync', 'MMI_Import_Preview::import_single_item');
                    }
                );
            }
        }

        $import_options = [
            'duplicate_strategy' => 'update',
            'price_update'       => true,
            'stock_update'       => true,
            'supplier'           => $supplier,
            'profile'            => $profile,
            'product_scope'      => 'all_products',
            'allow_create'       => true,
            'attribute_manager'  => $attribute_manager,
        ];

        $field_stats = [];
        $status      = \MannMade\DataPipeline\AJAX\Product_Import_Worker::import_single_product($raw_item, $mappings, $import_options, $field_stats);

        $product_id = MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key($primary_key_wc, $primary_key);

        $messages = [
            'imported'  => 'Product created successfully.',
            'updated'   => 'Product updated successfully.',
            'unchanged' => 'No changes — this product already matches the source data.',
            'skipped'   => 'Skipped — the primary key could not be resolved.',
        ];

        return [
            'status'       => $status,
            'message'      => $messages[$status] ?? 'Done.',
            'product_id'   => $product_id ?: null,
            'product_url'  => $product_id ? get_edit_post_link($product_id) : null,
            'product_name' => $product_id ? get_the_title($product_id) : null,
        ];
    }
    
    /**
     * AJAX: inspect the raw source data behind ONE preview row — the "Inspect
     * Source" magnifier next to a row's Primary Key. Lets the user see exactly
     * what the feed/upload contains for this record (complete raw item, plus
     * the original CSV row for a CSV-backed upload source, plus a human-
     * readable readout of every taxonomy field's source value and whether
     * Taxonomy Mapping currently resolves it) without leaving the page to
     * manually open the source file and search for an identifying string —
     * the standard troubleshooting process this replaces.
     */
    public function handle_inspect_source_record() {
        check_ajax_referer('mmi_product_importer_nonce', 'nonce');

        if (!mmi_data_pipeline_user_can( 'manage_options' )) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $supplier    = sanitize_text_field($_POST['supplier'] ?? '');
        $primary_key = sanitize_text_field($_POST['primary_key'] ?? '');
        $profile     = sanitize_text_field($_POST['profile'] ?? 'default');

        $profiles = MMI_DB::get_profiles();
        if (!isset($profiles[$profile])) {
            $profile = 'default';
        }

        if (empty($supplier) || $primary_key === '') {
            wp_send_json_error(['message' => 'Supplier and primary key are required']);
        }

        try {
            $result = $this->inspect_source_record($supplier, $primary_key, $profile);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Build the full source-data inspection payload for one record.
     */
    public function inspect_source_record($supplier, $primary_key, $profile = 'default') {
        $located = $this->locate_raw_item_for_inspection($supplier, $primary_key);
        $item    = $located['item'];

        $primary_key_wc = MMI_DB::get_primary_key($supplier, 'wc', '_sku');
        $product_id     = MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key($primary_key_wc, $primary_key);

        return [
            'supplier'        => $supplier,
            'primary_key'     => $primary_key,
            'product_id'      => $product_id ?: null,
            'product_url'     => $product_id ? get_edit_post_link($product_id) : null,
            'json_file'       => $located['json_file'],
            'file_modified'   => date('Y-m-d H:i:s', filemtime($located['json_path'])),
            'raw_record'      => $item,
            'taxonomy_fields' => $this->build_taxonomy_field_inspection($supplier, $item, $profile),
            'original_row'    => $this->locate_original_upload_row($supplier, $primary_key),
        ];
    }

    /**
     * Locate the raw feed record for one primary key, with the matching promo
     * record injected exactly as generate_preview()/import_single_item() do —
     * mirrors import_single_item()'s own identical lookup (see its docblock)
     * rather than sharing it directly, since that method is a live, verified
     * import-execution path and this one is a read-only inspection path; kept
     * separate so a future change to one can't silently alter the other's
     * behavior.
     */
    private function locate_raw_item_for_inspection($supplier, $primary_key) {
        $json_dir  = mmi_shared_lib_json_dir();
        $json_file = $this->get_json_file_for_supplier($supplier);
        $json_path = $json_dir . $json_file;

        if (!file_exists($json_path)) {
            throw new Exception("JSON file not found for {$supplier}: {$json_file}. Please fetch supplier data first.");
        }

        // Same mtime-keyed transient generate_preview()/import_single_item() use —
        // reuses whichever copy is already warm instead of re-reading the feed.
        $cache_key = 'mmi_pipeline_feed_' . $supplier . '_' . filemtime( $json_path );
        $data      = get_transient( $cache_key );

        if ( false === $data ) {
            $json_content = file_get_contents($json_path);
            $data         = json_decode($json_content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in {$supplier} feed file ({$json_file}): " . json_last_error_msg());
            }

            set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
        }

        $items               = $this->extract_items($data, $supplier);
        $primary_key_source  = MMI_DB::get_primary_key($supplier, 'source', 'id');

        $raw_item = null;
        foreach ($items as $candidate) {
            $candidate_key = MMI_Pipeline_Field_Resolver::get_nested_value($candidate, $primary_key_source);
            if ((string) $candidate_key === (string) $primary_key) {
                $raw_item = $candidate;
                break;
            }
        }

        if ($raw_item === null) {
            throw new Exception("Item with primary key \"{$primary_key}\" was not found in the {$supplier} feed. Try refreshing the preview — the feed may have changed.");
        }

        $promo_result     = MMI_Pipeline_Field_Resolver::load_and_index_promotions($supplier, $json_dir);
        $promotions_index = $promo_result['index'];
        $promo_namespace  = $promo_result['namespace'];
        if (!empty($promotions_index)) {
            $raw_item = MMI_Pipeline_Field_Resolver::inject_promotion($raw_item, $primary_key, $promotions_index, $promo_namespace);
        }

        return ['item' => $raw_item, 'json_file' => $json_file, 'json_path' => $json_path, 'json_dir' => $json_dir];
    }

    /**
     * Human-readable readout of every taxonomy field (product_cat, product_tag,
     * product_brand, plus any custom taxonomy with Taxonomy Mapping alias rows
     * for this supplier) for one record: which raw source field/value feeds it,
     * and whether Taxonomy Mapping currently resolves that value to a real WC
     * term — the exact question a "bad match" report like this feature exists
     * to answer, surfaced directly instead of requiring a second lookup in the
     * Taxonomy Mapping tab.
     */
    private function build_taxonomy_field_inspection($supplier, array $item, $profile): array {
        $mappings            = MMI_Pipeline_Field_Mapping_Defaults::get_effective($profile);
        $alias_taxonomy_pool = array_column(MMI_DB::get_tax_mappings($supplier, ''), 'wc_taxonomy');

        $field_names = [];
        foreach ($mappings as $field_name => $config) {
            if ((($config['type'] ?? '') === 'taxonomy') || taxonomy_exists($field_name)) {
                $field_names[$field_name] = true;
            }
        }
        foreach (array_unique($alias_taxonomy_pool) as $tax) {
            if (taxonomy_exists($tax)) {
                $field_names[$tax] = true;
            }
        }

        $result = [];
        foreach (array_keys($field_names) as $field_name) {
            $config = $mappings[$field_name] ?? [];

            $source_field = is_array($config['source'] ?? '')
                ? ($config['source'][$supplier] ?? '')
                : ($config['source'] ?? '');
            if ($source_field === 'custom') {
                $source_field = $config['source_custom'] ?? '';
            }
            if ($source_field === 'NULL' || $source_field === 'null') {
                $source_field = '';
            }

            $raw_value = '';
            if (!empty($source_field)) {
                $transform_params = is_array($config['transform_params'] ?? null) ? $config['transform_params'] : [];
                $raw_value        = MMI_Pipeline_Field_Resolver::resolve_field_value($item, $source_field, $config['transform'] ?? 'none', $transform_params);
            }

            $alias_skipped  = false;
            $alias_term_ids = MMI_Pipeline_Field_Resolver::resolve_taxonomy_via_alias_table($supplier, $item, $field_name, $profile, $alias_skipped);
            $tax_obj        = get_taxonomy($field_name);

            $result[] = [
                'taxonomy'     => $field_name,
                'label'        => $tax_obj ? $tax_obj->labels->singular_name : $field_name,
                'source_field' => $source_field ?: null,
                'raw_value'    => $this->normalize_taxonomy_display_value($raw_value),
                'mapped'       => !empty($alias_term_ids),
                'mapped_terms' => !empty($alias_term_ids) ? $this->terms_display_from_ids($alias_term_ids, $field_name) : null,
                // What an import does with it when unmapped — the field's
                // "When a value isn't in Taxonomy Mapping" setting.
                'unmapped_policy' => $alias_skipped
                    ? 'skipped'
                    : MMI_Pipeline_Field_Resolver::unmapped_term_policy($field_name, is_array($config) ? $config : []),
            ];
        }

        // Fields with a real source configured surface first — an alias-only
        // taxonomy with no Field Mapping source (e.g. product_brand on a
        // supplier that hasn't been given one) is real but secondary context.
        usort($result, fn($a, $b) => ($b['source_field'] !== null) <=> ($a['source_field'] !== null));

        return $result;
    }

    /**
     * For a CSV/TSV-backed upload data source, find and return the ONE
     * original row matching this primary key straight from the uploaded file
     * on disk — the literal "complete CSV record" a manual troubleshooting
     * session would open the file and search for. Streams the file row by
     * row rather than loading it into memory (see AGENTS.md "Server Load &
     * PHP-FPM Impact" — never load an entire large file to search it), and
     * stops at the first match.
     *
     * Returns null for any non-upload source (API suppliers have no original
     * file — the raw feed record already IS the source of truth) and for a
     * JSON/XML upload source (raw_record above already IS the complete
     * original for those formats).
     */
    private function locate_original_upload_row($supplier, $primary_key): ?array {
        if (!class_exists('MMI_Pipeline_Admin')) {
            return null;
        }

        $configured = MMI_Pipeline_Admin::get_configured_suppliers();
        if (($configured[$supplier]['source_type'] ?? '') !== 'upload') {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mmi_data_sources';
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT configuration, file_config FROM {$table} WHERE supplier_id = %s", $supplier),
            ARRAY_A
        );
        if (!$row) {
            return null;
        }

        $configuration = json_decode($row['configuration'] ?: '{}', true) ?: [];
        $file_config   = json_decode($row['file_config'] ?: '{}', true) ?: [];

        $format = sanitize_text_field($file_config['format'] ?? 'json');
        if (!in_array($format, ['csv', 'tsv'], true)) {
            return null;
        }

        $attachment_id = (int) ($configuration['upload_attachment_id'] ?? 0);
        if ($attachment_id <= 0) {
            return null;
        }
        $file_path = get_attached_file($attachment_id);
        if (empty($file_path) || !file_exists($file_path)) {
            return null;
        }

        $delimiter  = ($format === 'tsv') ? "\t" : ((($file_config['delimiter'] ?? ',')) ?: ',');
        $has_header = array_key_exists('has_header', $file_config) ? (bool) $file_config['has_header'] : true;
        $primary_key_source = MMI_DB::get_primary_key($supplier, 'source', 'id');

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return null;
        }

        $headers = $has_header ? fgetcsv($handle, 0, $delimiter) : null;
        if (is_array($headers)) {
            $headers = array_map('trim', $headers);
        }

        $matched_row = null;
        $row_number  = $has_header ? 1 : 0;
        while (($raw_row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row_number++;
            if (is_array($headers)) {
                $padded = array_pad(array_slice($raw_row, 0, count($headers)), count($headers), '');
                $assoc  = array_combine($headers, $padded);
            } else {
                $assoc = array_combine(
                    array_map(static fn($i) => "col{$i}", array_keys($raw_row)),
                    $raw_row
                );
            }

            $candidate_key = MMI_Pipeline_Field_Resolver::get_nested_value($assoc, $primary_key_source);
            if ((string) $candidate_key === (string) $primary_key) {
                $matched_row = $assoc;
                break;
            }
        }
        fclose($handle);

        if ($matched_row === null) {
            return null;
        }

        return [
            'format'     => $format,
            'delimiter'  => ($delimiter === "\t") ? 'tab' : $delimiter,
            'row_number' => $row_number,
            'fields'     => $matched_row,
        ];
    }

    /**
     * Generate import preview for a supplier
     */
    public function generate_preview($supplier, $limit = 10, $preview_fields = [], $profile = 'default', $wc_filters = []) {
        global $wpdb;
        
        // Get JSON file path
        $json_dir = mmi_shared_lib_json_dir();
        $json_file = $this->get_json_file_for_supplier($supplier);
        $json_path = $json_dir . $json_file;
        
        if (!file_exists($json_path)) {
            throw new Exception("JSON file not found for {$supplier}: {$json_file}. Please fetch supplier data first.");
        }

        // Preview reloads on every filter change and this plugin's Import Preview
        // panel fires one request per enabled supplier concurrently — the xchange
        // feed alone is 13MB+. Cache the decoded array (keyed by mtime so a fresh
        // supplier fetch invalidates it immediately) instead of re-parsing the full
        // file on every call. See AGENTS.md "Server Load & PHP-FPM Impact" — source
        // feeds over 1MB must be cached, not re-read per request.
        $cache_key = 'mmi_pipeline_feed_' . $supplier . '_' . filemtime( $json_path );
        $data      = get_transient( $cache_key );

        if ( false === $data ) {
            $json_content = file_get_contents($json_path);

            if (empty($json_content)) {
                throw new Exception("JSON file is empty for {$supplier}: {$json_file}. Please fetch supplier data again.");
            }

            $data = json_decode($json_content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $error_msg = json_last_error_msg();
                throw new Exception("Invalid JSON in {$supplier} feed file ({$json_file}): {$error_msg}. File may be corrupted - try fetching supplier data again.");
            }

            set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
        }
        
        // Get effective field mappings (defaults + saved overrides) for the specified
        // profile — must match what the importer actually uses, otherwise fields like
        // _sku/post_title/_regular_price/post_content show "—" here even though the
        // import will populate them via un-persisted defaults.
        $mappings = MMI_Pipeline_Field_Mapping_Defaults::get_effective( $profile ?? 'default' );

        // The client normally derives $preview_fields (which fields get their own
        // comparison column) from the Field Mapping table's own DOM — but that
        // table's real markup is only ever injected the first time the profile
        // wizard's Field Mapping step is actually opened this page load (see
        // panel-field-mapping.php's own $mmi_field_mapping_render_now docblock);
        // a plain Review & Compare view with the wizard never opened has nothing
        // there for the client to read, and sends an empty list. Rather than
        // leave the preview with zero dynamic columns in that (now the common)
        // case, fall back to deriving the column set directly from this
        // supplier's own effective, persisted mappings — the same "mapped
        // implies previewed" rule the Field Mapping table itself follows, just
        // computed here instead of scraped from a DOM that may not exist yet.
        // Per-supplier, mirroring preview_single_item()'s own $field_enabled
        // check a few hundred lines below exactly, since a multi-supplier
        // field can be mapped for one supplier and not another.
        if ( empty( $preview_fields ) ) {
            foreach ( $mappings as $field_name => $field_config ) {
                if ( 'post_title' === $field_name ) {
                    continue; // always the fixed "Product Name" column
                }
                $field_enabled = is_array( $field_config['enabled'] ?? null )
                    ? ! empty( $field_config['enabled'][ $supplier ] )
                    : ! empty( $field_config['enabled'] ?? null );
                if ( ! $field_enabled ) {
                    continue;
                }
                $preview_fields[] = [
                    'name'  => $field_name,
                    'label' => $field_config['label'] ?? $field_name,
                    'type'  => $field_config['type'] ?? '',
                ];
            }
        }

        // Every show_ui taxonomy registered for 'product' — Category, Tags, Brand,
        // Product Line, Product Level, any ACF-registered custom taxonomy, etc.
        // Used to attach each existing product's REAL assigned terms below so the
        // Review filter bar's taxonomy checkboxes can filter by ANY of these, not
        // just ones this profile happens to map as an import field.
        $filterable_taxonomies = [];
        foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
            if ( $tax->show_ui ) {
                $filterable_taxonomies[] = $tax->name;
            }
        }
        
        // Get primary key configuration
        $primary_key_source = MMI_DB::get_primary_key( $supplier, 'source', 'id' );
        $primary_key_wc = MMI_DB::get_primary_key( $supplier, 'wc', '_sku' );

        // SKUs the user has confirmed are genuine duplicates of an existing
        // product (see dismiss_sku_conflict()) — these are excluded from the
        // preview/import entirely below, not retried as a create.
        $dismissed_sku_conflicts = array_flip( self::get_dismissed_sku_conflicts( $profile ) );
        
        // Get import mode for the profile — this is authoritative.
        // The legacy per-profile setting is only consulted when import_mode was never stored,
        // meaning the profile pre-dates the new mode system. An explicit import_mode always wins.
        $profiles      = MMI_DB::get_profiles();
        $profile_meta  = $profiles[ $profile ?? 'default' ] ?? [];
        $import_mode   = $profile_meta['import_mode'] ?? null;

        // product_scope is a SEPARATE axis from import_mode ("which products may
        // this profile touch" vs. "what does it do to matched products") and
        // 'new_only' takes precedence over whatever import_mode says, exactly
        // matching the real importer's own precedence — see
        // Product_Import_Worker::import_single_product() ("new_only scope
        // always creates — that is its entire purpose") and
        // Dynamic_Product_Importer::update_product_from_source() ("new_only
        // scope: never modify a product that already exists in the store").
        // Preview must mirror this precedence or its stats/table lie about
        // what a real run will actually do — see the 2026-08-30 Incident
        // History entry for the live case that surfaced this gap: a profile
        // with product_scope='new_only' but import_mode='create-and-update'
        // showed "Will Update: 4,115" here while the real import correctly
        // skips every one of those 4,115 existing products.
        $product_scope = $profile_meta['product_scope'] ?? 'all_products';

        if ( $product_scope === 'new_only' ) {
            $allow_create_products = true;
        } elseif ( MMI_Pipeline_Field_Mapping_Defaults::is_create_capable_mode( $import_mode ) ) {
            $allow_create_products = true;
        } elseif ( $import_mode === 'update-only' || $import_mode === 'availability-sync' ) {
            // Explicit mode — never overridden by legacy settings.
            $allow_create_products = false;
        } else {
            // No import_mode stored (legacy/unset profile) — fall back to old flag.
            $allow_create_option_key = $profile === 'default'
                ? 'mmi_pipeline_import_allow_create_products'
                : 'mmi_pipeline_import_allow_create_products_' . $profile;
            $allow_create_products = (bool) MMI_DB::get_setting( $allow_create_option_key, false );
            // Derive a consistent import_mode label for the response.
            $import_mode = $allow_create_products ? 'create-and-update' : 'update-only';
        }
        
        // Process items
        // Re-indexed so the chunked cache-priming below can rely on sequential
        // integer keys (it pairs $item_index against array_slice()'s positional
        // offset); a feed whose records came back string- or sparsely-keyed
        // would otherwise prime the wrong slice.
        $items = array_values($this->extract_items($data, $supplier));

        // Resolve WC product filter bar — build an allowed-IDs set so we can skip
        // matched products that don't satisfy the admin's stock/image/price/search criteria.
        $wc_allowed_ids = null;
        if ( ! empty( $wc_filters ) && class_exists( 'MMI_WC_Product_Filter_Handler' ) ) {
            $has_active = array_filter( $wc_filters, fn( $v ) => $v !== null && $v !== '' );
            if ( ! empty( $has_active ) ) {
                $sql     = MMI_WC_Product_Filter_Handler::count_query( $wc_filters );
                $ids_sql = str_replace( 'SELECT COUNT(DISTINCT p.ID)', 'SELECT DISTINCT p.ID', $sql );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wc_allowed_ids = array_flip( array_map( 'intval', $wpdb->get_col( $ids_sql ) ) );
            }
        }

        // Load and index promotion data so promo-sourced fields resolve when mapped.
        // Returns ['index' => [...SKU => record], 'namespace' => string|null].
        // namespace = the JSON container key (e.g. 'promotions' for xchange), which
        // mirrors the dot-notation prefix used in source field paths (promotions.street_price).
        // null means the file is a bare array and fields should be merged flat (skuport).
        $promo_result      = MMI_Pipeline_Field_Resolver::load_and_index_promotions( $supplier, $json_dir );
        $promotions_index  = $promo_result['index'];
        $promo_namespace   = $promo_result['namespace'];

        /* ── Batch pre-resolve + cache prime ───────────────────────────────
         * preview_single_item() used to do four DB round trips per row
         * (primary-key lookup, get_post(), a raw postmeta SELECT, and
         * wp_get_object_terms()). Across a full feed scan that was ~4.4
         * queries × thousands of rows — measured at ~55,000 queries and ~46s
         * of worker time for one preview load across three sources.
         *
         * Resolving every primary key up front (chunked WHERE IN) and priming
         * WP's post/meta/term caches for the matched IDs collapses all of that
         * into a fixed handful of queries; the per-row calls below then hit
         * the primed caches instead of MySQL. See AGENTS.md "Never re-query
         * the database inside a loop (the N+1 pattern)". */
        $feed_keys = [];
        foreach ( $items as $item ) {
            $key = MMI_Pipeline_Field_Resolver::get_nested_value( $item, $primary_key_source );
            if ( $key !== null && $key !== '' ) {
                $feed_keys[] = (string) $key;
            }
        }
        $primary_key_id_map = MMI_Pipeline_Field_Resolver::find_product_ids_by_primary_keys(
            $primary_key_wc,
            $feed_keys
        );

        // Values matching MORE than one live WC product — see this method's
        // own docblock. Flagged per-row below (`pk_collision`) so Review &
        // Compare can warn on rows the pipeline can only ever partially see,
        // instead of silently resolving to whichever product $primary_key_id_map
        // happened to collapse onto.
        $primary_key_collisions = MMI_Pipeline_Field_Resolver::find_primary_key_collisions(
            $primary_key_wc,
            $feed_keys
        );

        // Supplier-wide and constant for the whole scan — read once here rather
        // than once per row inside preview_single_item().
        $alias_taxonomy_pool = array_column( MMI_DB::get_tax_mappings( $supplier, '' ), 'wc_taxonomy' );

        /* ── Batch pre-compute Author / Orders / Coupon facts ──────────────
         * One query each (never per-row — see AGENTS.md's N+1 rule), keyed
         * by matched WC product ID, feeding the Review filter bar's new
         * WP-native filter groups. Only ever populated for 'update' rows (a
         * 'create' row has no product_id yet to look any of this up
         * against) — same convention $current_terms above already uses. */
        $matched_product_ids = array_values( array_unique( array_filter( array_map( 'intval', $primary_key_id_map ) ) ) );

        $author_map         = [];
        $order_map          = [];
        $coupon_product_ids = [];

        if ( ! empty( $matched_product_ids ) ) {
            $ids_placeholder = implode( ',', array_fill( 0, count( $matched_product_ids ), '%d' ) );

            $author_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_author FROM {$wpdb->posts} WHERE ID IN ({$ids_placeholder})",
                    ...$matched_product_ids
                )
            );
            $distinct_author_ids = array_values( array_unique( array_map( static fn( $r ) => (int) $r->post_author, $author_rows ) ) );
            $author_names = [];
            if ( ! empty( $distinct_author_ids ) ) {
                foreach ( get_users( [ 'include' => $distinct_author_ids, 'fields' => [ 'ID', 'display_name' ] ] ) as $user ) {
                    $author_names[ (int) $user->ID ] = $user->display_name;
                }
            }
            foreach ( $author_rows as $row ) {
                $aid = (int) $row->post_author;
                $author_map[ (int) $row->ID ] = [ 'id' => $aid, 'name' => $author_names[ $aid ] ?? '' ];
            }

            // Order status/date — HPOS-only. This store runs HPOS (see
            // MMI_Order_Data_Type's own docblock, mmi-data-pipeline's
            // existing HPOS-aware order handler) so there is no live case
            // needing a legacy postmeta fallback; adding one speculatively
            // would be exactly the unstated-requirement abstraction AGENTS.md
            // warns against. If HPOS is ever disabled, this map is simply
            // empty and the Orders filter group has nothing to show.
            if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
                $order_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT lookup.product_id, o.status, lookup.date_created
                         FROM {$wpdb->prefix}wc_order_product_lookup lookup
                         INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = lookup.order_id
                         WHERE lookup.product_id IN ({$ids_placeholder})
                           AND o.type = 'shop_order'",
                        ...$matched_product_ids
                    )
                );
                foreach ( $order_rows as $row ) {
                    $pid = (int) $row->product_id;
                    if ( ! isset( $order_map[ $pid ] ) ) {
                        $order_map[ $pid ] = [ 'statuses' => [], 'last_order_date' => null ];
                    }
                    $order_map[ $pid ]['statuses'][ $row->status ] = true;
                    if ( $order_map[ $pid ]['last_order_date'] === null || $row->date_created > $order_map[ $pid ]['last_order_date'] ) {
                        $order_map[ $pid ]['last_order_date'] = $row->date_created;
                    }
                }
                foreach ( $order_map as $pid => $data ) {
                    $order_map[ $pid ]['statuses'] = array_keys( $data['statuses'] );
                }
            }

            // Coupons — total published-coupon count is small and bounded
            // (not per-product), so one full read is cheap regardless of
            // catalog size, same reasoning as the alias-taxonomy pool above.
            $coupon_meta = $wpdb->get_col(
                "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type = 'shop_coupon' AND p.post_status = 'publish' AND pm.meta_key = '_product_ids'"
            );
            foreach ( $coupon_meta as $csv ) {
                foreach ( array_filter( array_map( 'trim', explode( ',', (string) $csv ) ) ) as $pid_str ) {
                    $coupon_product_ids[ (int) $pid_str ] = true;
                }
            }
        }

        /* ── "Create Only" source-data filter schema ───────────────────────
         * Calculated once per source at upload/connect time (see
         * MMI_Pipeline_Config_Validator::calculate_filterable_fields()) and
         * read here, not recomputed per preview load. Attached to EVERY row
         * below (create and update alike, unlike author/orders/coupon)
         * since the raw feed value exists regardless of WC match status —
         * this is what lets a Create & Update profile filter its update
         * rows by source data too. */
        $filterable_fields_schema = class_exists( 'MMI_Pipeline_Config_Validator' )
            ? MMI_Pipeline_Config_Validator::get_filterable_fields( $supplier, $json_dir )
            : [ 'fields' => [] ];
        $filterable_field_paths = array_keys( $filterable_fields_schema['fields'] ?? [] );

        // Any custom taxonomy resolved purely through Taxonomy Mapping's alias
        // table that ALSO has no real Field Mapping row (i.e. not product_cat/
        // product_tag/product_brand, which all have a real "Show in Preview"
        // checkbox — see $mappings) has no way for $preview_fields (built from
        // those checkboxes) to ever include it. The change-detection loop below
        // already assigns fields like that unconditionally whenever alias rows
        // exist for this supplier (mirrors
        // Product_Import_Worker::get_active_alias_taxonomies()); auto-adding the
        // same set as preview columns here keeps the visible table consistent
        // with what the import will actually do, rather than silently computing
        // a change nobody can see. A taxonomy that DOES have a real Field
        // Mapping row is left entirely to its own checkbox/'enabled' state —
        // auto-adding it here too would silently override a profile that
        // deliberately left it unchecked or disabled.
        $requested_field_names = array_column( $preview_fields, 'name' );
        foreach ( array_unique( $alias_taxonomy_pool ) as $alias_taxonomy ) {
            if ( ! taxonomy_exists( $alias_taxonomy )
                || isset( $mappings[ $alias_taxonomy ] )
                || in_array( $alias_taxonomy, $requested_field_names, true )
            ) {
                continue;
            }
            $tax_obj           = get_taxonomy( $alias_taxonomy );
            $preview_fields[]  = [
                'name'  => $alias_taxonomy,
                'label' => $tax_obj ? $tax_obj->labels->singular_name : $alias_taxonomy,
                'type'  => 'taxonomy',
            ];
        }

        $preview_items = [];
        $count = 0;
        $skipped_new_products = 0;
        $skipped_existing = 0;
        $skipped_conflicts = 0;
        $limit_reached = false;

        /* Priming is done per chunk and evicted immediately after that chunk is
         * processed, rather than priming every matched product up front. Priming
         * the whole feed at once cut queries but held every post object + all its
         * meta in memory for the entire request — measured at 610MB peak for a
         * 6,482-row feed, which would fatal against this site's 512M
         * WP_MEMORY_LIMIT in a real web request. Chunking bounds peak memory to
         * one chunk's worth while keeping the batched-query win. */
        $prime_chunk_size = 250;
        $primed_ids       = [];

        foreach ($items as $item_index => $item) {
            if ($count >= $limit) {
                $limit_reached = true;
                break;
            }

            // At each chunk boundary: release the previous chunk's cached posts,
            // then prime the next chunk's in one batch.
            if ( $item_index % $prime_chunk_size === 0 ) {
                foreach ( $primed_ids as $primed_id ) {
                    wp_cache_delete( $primed_id, 'posts' );
                    wp_cache_delete( $primed_id, 'post_meta' );
                }
                $primed_ids = [];

                $slice = array_slice( $items, $item_index, $prime_chunk_size );
                foreach ( $slice as $slice_item ) {
                    $slice_key = MMI_Pipeline_Field_Resolver::get_nested_value( $slice_item, $primary_key_source );
                    if ( $slice_key === null || $slice_key === '' ) {
                        continue;
                    }
                    $slice_id = $primary_key_id_map[ (string) $slice_key ] ?? 0;
                    if ( $slice_id ) {
                        $primed_ids[] = $slice_id;
                    }
                }
                if ( ! empty( $primed_ids ) ) {
                    $primed_ids = array_values( array_unique( $primed_ids ) );
                    _prime_post_caches( $primed_ids, ! empty( $filterable_taxonomies ), true );
                }
            }

            // Inject the matching promo record into the raw item before field-mapping
            // so configured source paths resolve naturally:
            //   - xchange: inject as $item['promotions'] = promo_record
            //     (source paths like 'promotions.street_price' resolve via dot-notation)
            //   - skuport: flat-merge promo_record into $item
            //     (source paths like 'promoPrice' resolve at top level)
            if ( ! empty( $promotions_index ) ) {
                $item_key      = MMI_Pipeline_Field_Resolver::get_nested_value( $item, $primary_key_source );
                $matched_promo = $promotions_index[ (string) $item_key ] ?? null;
                if ( $matched_promo !== null ) {
                    // Correct a self-discounting base feed before mapping — see
                    // MMI_Pipeline_Field_Resolver::resolve_true_regular_price().
                    $item = MMI_Pipeline_Field_Resolver::correct_self_discounted_regular_price( $item, $matched_promo, $mappings, $supplier );
                }
                $item = MMI_Pipeline_Field_Resolver::inject_promotion( $item, $item_key, $promotions_index, $promo_namespace );
            }
            
            $preview_item = $this->preview_single_item($item, $supplier, $mappings, $primary_key_source, $primary_key_wc, $preview_fields, $filterable_taxonomies, $dismissed_sku_conflicts, $primary_key_id_map, $alias_taxonomy_pool, $profile, $author_map, $order_map, $coupon_product_ids, $filterable_field_paths, $primary_key_collisions);
            
            if ($preview_item) {
                // Dismissed conflicts are confirmed duplicates of an existing product —
                // permanently excluded from import, never shown as a create attempt.
                if ( ! empty( $preview_item['sku_conflict']['dismissed'] ) ) {
                    $skipped_conflicts++;
                    continue;
                }

                // Apply WC product filter bar: if the matched WC product doesn't satisfy the
                // stock/image/price/search criteria, hide it from the preview.
                // Supplier-side "create" rows have no WC product yet, so they are excluded
                // from filtering (we can't evaluate WC criteria for non-existent products).
                if (
                    $wc_allowed_ids !== null &&
                    $preview_item['action'] === 'update' &&
                    ! isset( $wc_allowed_ids[ (int) $preview_item['product_id'] ] )
                ) {
                    continue;
                }

                // Skip new products if creation is disabled
                if (!$allow_create_products && $preview_item['action'] === 'create') {
                    $skipped_new_products++;
                    continue;
                }

                // new_only scope: never modify a product that already exists —
                // matches the real importer's identical, unconditional guard
                // (checked before create-only below since scope, not mode, is
                // the one that's supposed to win — see the precedence note above).
                if ( $product_scope === 'new_only' && $preview_item['action'] === 'update' ) {
                    $skipped_existing++;
                    continue;
                }

                // create-only mode: skip existing products (don't show them as updates)
                if ( $import_mode === 'create-only' && $preview_item['action'] === 'update' ) {
                    $skipped_existing++;
                    continue;
                }
                
                $preview_items[] = $preview_item;
                $count++;
            }
        }
        
        // Calculate statistics
        // Note: For update-only and availability-sync modes, don't count/show creates at all
        // — unless product_scope is 'new_only', which always creates regardless of
        // import_mode (same precedence as the allow_create_products derivation above).
        if ( $product_scope === 'new_only' || $import_mode === 'create-and-update' || $import_mode === 'create-only' ) {
            // Exclude SKU-conflict rows — they will fail at save, not create.
            $will_create = array_filter($preview_items, fn($i) => $i['action'] === 'create' && empty($i['sku_conflict']));
        } else {
            $will_create = [];
        }
        // For create-only mode, existing products were skipped so will_update is always empty
        $will_update = array_filter($preview_items, fn($i) => $i['action'] === 'update' && $i['change_count'] > 0);
        $unchanged = array_filter($preview_items, fn($i) => $i['action'] === 'update' && $i['change_count'] === 0);
        
        $total_items_count = count( $items );

        return [
            'supplier' => $supplier,
            'total_in_feed' => $total_items_count,
            'preview_count' => count($preview_items),
            'fully_sampled' => ! $limit_reached,
            'items' => $preview_items,
            'stats' => [
                'will_create' => count($will_create),
                'will_update' => count($will_update),
                'unchanged' => count($unchanged),
                'total_changes' => array_sum(array_column($preview_items, 'change_count')),
                'skipped_new_products' => $skipped_new_products,
                // Create rows whose mapped SKU already belongs to another store
                // product — these WILL fail at import with "Invalid or duplicated SKU".
                'sku_conflicts' => count(array_filter($preview_items, fn($i) => ! empty($i['sku_conflict']))),
                // Confirmed-duplicate conflicts the user dismissed — excluded above,
                // never attempted again by preview or the real import.
                'skipped_conflicts' => $skipped_conflicts,
                // Rows whose primary key matches more than one live WC product —
                // see preview_single_item()'s $pk_collision computation. Real data
                // defects (Manage Duplicates > Confirmed Collisions), not the
                // cross-vendor Candidate case.
                'pk_collisions' => count(array_filter($preview_items, fn($i) => ! empty($i['pk_collision']))),
            ],
            'mappings_configured' => !empty($mappings),
            'primary_key' => [
                'source' => $primary_key_source,
                'wc_field' => $primary_key_wc
            ],
            'preview_fields' => $preview_fields,
            'import_mode' => $import_mode,
            'json_file' => $json_file,
            'file_size' => filesize($json_path),
            'file_modified' => date('Y-m-d H:i:s', filemtime($json_path)),
            'allow_create_products' => $allow_create_products,
            // Review filter bar's "Create Only" source-data filter group —
            // see MMI_Pipeline_Config_Validator::calculate_filterable_fields().
            'filterable_fields' => $filterable_fields_schema['fields'] ?? []
        ];
    }
    
    /**
     * Preview a single item
     */
    private function preview_single_item($item, $supplier, $mappings, $primary_key_source, $primary_key_wc, $preview_fields = [], $filterable_taxonomies = [], $dismissed_sku_conflicts = [], $primary_key_id_map = null, $alias_taxonomy_pool = null, $profile = 'default', $author_map = null, $order_map = null, $coupon_product_ids = null, $filterable_field_paths = null, $primary_key_collisions = null) {
        // Get primary key value
        $primary_value = MMI_Pipeline_Field_Resolver::get_nested_value($item, $primary_key_source);

        if (empty($primary_value)) {
            return null;
        }

        // Check if product exists — use a lightweight approach that avoids
        // instantiating WC_Product objects, which loads attribute/term data
        // into the object cache and causes memory exhaustion with large catalogs.
        $product_id    = null;
        $action        = 'create';
        $existing_post = null;
        $existing_meta = [];

        // generate_preview() (the only caller today) batch-resolves the whole
        // feed up front and passes the map in. The single-value fallback keeps
        // this method correct on its own for any future caller that hasn't
        // pre-resolved — it is not currently exercised.
        if ( is_array( $primary_key_id_map ) ) {
            $found_id = $primary_key_id_map[ (string) $primary_value ] ?? 0;
        } else {
            $found_id = MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key( $primary_key_wc, $primary_value );
        }

        if ( $found_id ) {
            $post = get_post( (int) $found_id );
            if ( $post && $post->post_status !== 'trash' ) {
                $product_id    = (int) $found_id;
                $action        = 'update';
                $existing_post = $post;
                $existing_meta = $this->get_product_meta_fast( $product_id );
            }
        }

        // Fields this product has locked against imports — the importer skips
        // them, so the preview must not count them as pending changes (the
        // same "preview promises what the import won't do" trap product_scope
        // once fell into). get_product_meta_fast() above already warmed the
        // meta cache this reads.
        $locked_fields = $product_id ? MMI_Pipeline_Field_Locks::get( $product_id ) : [];

        // Real assigned term slugs, keyed by taxonomy, for the Review filter bar's
        // taxonomy checkboxes — covers EVERY show_ui product taxonomy (Brand,
        // Product Line, Product Level, custom ACF ones, etc.), not just ones this
        // profile happens to map as an import field. Only existing products have
        // this — a to-be-created row has no WC product yet to query terms from,
        // so the Review filter bar leaves 'create' rows unaffected by taxonomy
        // filters (mirrors the WC search box's existing create-row exemption).
        $current_terms = [];
        if ( $product_id && ! empty( $filterable_taxonomies ) ) {
            // get_the_terms() reads through the object term cache that
            // generate_preview() primes per chunk; wp_get_object_terms() (used
            // here previously) always goes straight to the database, so it
            // stayed a per-row query no matter what was primed — the single
            // largest remaining N+1 in this loop.
            foreach ( $filterable_taxonomies as $taxonomy ) {
                $terms = get_the_terms( $product_id, $taxonomy );
                if ( is_wp_error( $terms ) || empty( $terms ) ) {
                    continue;
                }
                foreach ( $terms as $term ) {
                    // {slug, name} rather than a bare slug — the Review filter
                    // bar builds its taxonomy checkboxes dynamically from
                    // whatever terms actually show up here (see
                    // updateFilterBarAvailability() in import-preview.js), and
                    // needs a real display name to label each option with,
                    // not just the slug it filters on.
                    $current_terms[ $term->taxonomy ][] = [
                        'slug' => $term->slug,
                        'name' => $term->name,
                    ];
                }
            }
        }

        // Author/Orders/Coupon facts about the EXISTING matched product —
        // batch-computed once in generate_preview(), see the maps built
        // there. Empty/absent for 'create' rows, same convention as
        // $current_terms above.
        $author_id   = null;
        $author_name = '';
        if ( $product_id && is_array( $author_map ) && isset( $author_map[ $product_id ] ) ) {
            $author_id   = $author_map[ $product_id ]['id'];
            $author_name = $author_map[ $product_id ]['name'];
        }

        $order_statuses  = [];
        $last_order_date = null;
        if ( $product_id && is_array( $order_map ) && isset( $order_map[ $product_id ] ) ) {
            $order_statuses   = $order_map[ $product_id ]['statuses'];
            $last_order_date  = $order_map[ $product_id ]['last_order_date'];
        }

        $in_active_coupon = (bool) ( $product_id && is_array( $coupon_product_ids ) && isset( $coupon_product_ids[ $product_id ] ) );

        // Raw source-feed values for whichever fields the Review filter
        // bar's "Create Only" source-data group offers — populated for
        // EVERY row (create and update alike), since $item is the raw feed
        // record regardless of WC match status. See generate_preview()'s
        // $filterable_field_paths (from
        // MMI_Pipeline_Config_Validator::get_filterable_fields()).
        $source_filter_values = [];
        if ( is_array( $filterable_field_paths ) ) {
            foreach ( $filterable_field_paths as $path ) {
                $value = MMI_Pipeline_Field_Resolver::get_nested_value( $item, $path );
                if ( $value !== null && $value !== '' && ! is_array( $value ) ) {
                    $source_filter_values[ $path ] = (string) $value;
                }
            }
        }

        // Build mapped fields
        $mapped_data = [];
        $changes = [];
        
        // Build preview field data for each requested field
        $preview_field_data = [];
        foreach ($preview_fields as $field_info) {
            $field_name = $field_info['name'];
            $field_config = $mappings[$field_name] ?? null;

            if (!$field_config) {
                // A custom taxonomy with saved Taxonomy Mapping alias rows but
                // no Field Mapping entry of its own (product_brand has a real
                // one now — see MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS —
                // so this only fires for some other, not-yet-managed taxonomy).
                // Fall through with an empty config so the taxonomy-alias
                // resolution below still runs instead of dropping the column;
                // every lookup on $field_config past this point already
                // tolerates a missing key via ?? .
                if (taxonomy_exists($field_name)) {
                    $field_config = [];
                } else {
                    continue;
                }
            }

            // taxonomy_exists() is checked in addition to the saved 'type' since
            // some taxonomy fields (e.g. product_brand) were saved with a
            // stale/generic 'string' type.
            $is_taxonomy_field = ( ( $field_config['type'] ?? '' ) === 'taxonomy' ) || taxonomy_exists( $field_name );

            // Get source value
            $source_field = is_array($field_config['source'] ?? '') 
                ? ($field_config['source'][$supplier] ?? '') 
                : ($field_config['source'] ?? '');
            
            if ($source_field === 'custom') {
                $source_field = $field_config['source_custom'] ?? '';
            }
            
            // Treat the literal string "NULL" / "null" (stored when the UI saves
            // an unconfigured select) the same as an empty source field.
            if ($source_field === 'NULL' || $source_field === 'null') {
                $source_field = '';
            }

            // Resolve Taxonomy Mapping tab aliases up front for taxonomy fields —
            // these can supply a value even when Field Mapping has no source
            // configured at all (e.g. product_brand), so they must be checked
            // BEFORE the empty-source skip below, otherwise alias-only fields
            // would never appear in the preview at all.
            $preview_alias_skipped = false;
            $alias_term_ids = $is_taxonomy_field
                ? MMI_Pipeline_Field_Resolver::resolve_taxonomy_via_alias_table( $supplier, $item, $field_name, $profile, $preview_alias_skipped )
                : [];
            
            $preview_constant_value = MMI_Pipeline_Field_Mapping_Defaults::resolve_constant($field_config, $supplier);

            // Skip this preview column entirely if no source is configured, no
            // constant value is set, and no Taxonomy Mapping alias resolved a
            // value — avoids phantom SOURCE: null display.
            if (empty($source_field) && $preview_constant_value === null && empty($alias_term_ids)) {
                continue;
            }

            // Get source value from feed
            if ($preview_constant_value !== null) {
                $source_value = $preview_constant_value;
            } elseif (!empty($source_field)) {
                $field_transform_params = is_array( $field_config['transform_params'] ?? null ) ? $field_config['transform_params'] : [];
                $source_value = MMI_Pipeline_Field_Resolver::resolve_field_value( $item, $source_field, $field_config['transform'] ?? 'none', $field_transform_params );

                // Apply default if empty
                if (empty($source_value) && !empty($field_config['default_value'])) {
                    $source_value = $field_config['default_value'];
                }
            } else {
                // No Field Mapping source configured at all — value will come
                // entirely from the Taxonomy Mapping alias resolved above.
                $source_value = '';
            }

            // Get current value from existing product
            $current_value = null;
            if ($existing_post) {
                $current_value = $this->get_product_field_value_raw($existing_post, $existing_meta, $field_name);
            }

            // Taxonomy fields (product_cat/product_tag/product_brand) resolve from
            // the feed as an array (of names, or of {name,...} objects) — flatten
            // to the same comma-separated string form get_product_field_value_raw()
            // now returns for the CURRENT side, so they're actually comparable and
            // don't render as raw JSON/objects in the UI.
            if ( $is_taxonomy_field ) {
                // Taxonomy Mapping tab aliases (coded source values, e.g. Xchange's
                // master_category+sub_category) take priority over the raw
                // field-mapping passthrough whenever the admin has built alias
                // rows for this supplier+taxonomy — otherwise the preview shows
                // "empty" for fields whose real data lives in a different raw
                // field than the one configured in Field Mapping.
                $source_value = ! empty( $alias_term_ids )
                    ? $this->terms_display_from_ids( $alias_term_ids, $field_name )
                    : $this->normalize_taxonomy_display_value( $source_value );
            }

            // A value Taxonomy Mapping does not resolve, on a field set to
            // leave terms as they are (the default for categories and brands):
            // the import writes nothing, so show the current value plus an
            // "Unmapped" note instead of a pending change to the raw value.
            $tax_unmapped = null;
            if ( $is_taxonomy_field && empty( $alias_term_ids ) && $preview_constant_value === null
                && $source_value !== '' && $source_value !== null ) {
                $preview_taxonomy = ( strpos( $field_name, 'tax:' ) === 0 ) ? substr( $field_name, 4 ) : $field_name;
                if ( $preview_alias_skipped
                    || MMI_Pipeline_Field_Resolver::unmapped_term_policy( $preview_taxonomy, $field_config ) === MMI_Pipeline_Field_Resolver::UNMAPPED_SKIP ) {
                    $tax_unmapped = (string) $source_value;
                    $source_value = $current_value ?? '';
                }
            }

            // Whether the REAL import would actually write this field for this
            // supplier — mirrors class-product-import-worker.php's own
            // per-supplier gate exactly, including its default: an absent
            // 'enabled' key means disabled/skip there (empty() on an unset
            // key is true), not enabled — so this must NOT default to true
            // via '??' the way an "assume on unless told otherwise" read
            // would. "Show in Preview" and "Enabled" are independent toggles
            // (see panel-field-mapping.php): a field can be checked to
            // display here for context while being fully disabled for
            // import. Without this, the comparison below has no way to tell
            // the difference and always narrates as if the import will act
            // on it — see mmi-field-comparison's own JS rendering in
            // import-preview.js, which reads this key to switch to a
            // non-actionable "preview only" cell instead.
            $field_enabled = is_array( $field_config['enabled'] ?? null )
                ? ! empty( $field_config['enabled'][ $supplier ] )
                : ! empty( $field_config['enabled'] ?? null );

            $field_locked = MMI_Pipeline_Field_Locks::in_list( (string) $field_name, $locked_fields );

            $preview_field_data[$field_name] = [
                'current' => $current_value,
                'source'  => $source_value,
                'changed' => ! MMI_Pipeline_Field_Resolver::values_are_equal( $current_value, $source_value, $field_config['type'] ?? '' ),
                // A locked field renders as non-actionable, exactly like a
                // disabled one — import-preview.js already handles that state.
                'enabled' => $field_enabled && ! $field_locked,
                'locked'  => $field_locked,
                'unmapped' => $tax_unmapped,
            ];
        }
        
        foreach ($mappings as $wc_field => $config) {
            // Skip if not enabled for this supplier
            if (is_array($config['enabled'] ?? false)) {
                if (empty($config['enabled'][$supplier])) {
                    continue;
                }
            } elseif (empty($config['enabled'])) {
                continue;
            }
            
            // Get source value
            $source_field = is_array($config['source'] ?? '') 
                ? ($config['source'][$supplier] ?? '') 
                : ($config['source'] ?? '');
            
            if ($source_field === 'custom') {
                $source_field = $config['source_custom'] ?? '';
            }
            
            // Treat literal "NULL" / "null" as unconfigured — prevents phantom
            // change detection that would show every product as needing an update.
            if ($source_field === 'NULL' || $source_field === 'null') {
                $source_field = '';
            }
            
            // Use constant value if configured
            $preview_constant_value = MMI_Pipeline_Field_Mapping_Defaults::resolve_constant($config, $supplier);
            if ($preview_constant_value !== null) {
                $new_value = $preview_constant_value;
            } elseif (empty($source_field)) {
                // No source configured for this supplier — skip to avoid writing null.
                continue;
            } else {
                $transform_params = is_array( $config['transform_params'] ?? null ) ? $config['transform_params'] : [];
                $new_value = MMI_Pipeline_Field_Resolver::resolve_field_value( $item, $source_field, $config['transform'] ?? 'none', $transform_params );

                // Apply default if empty
                if (empty($new_value) && !empty($config['default_value'])) {
                    $new_value = $config['default_value'];
                }
            }

            // See matching comment above — flatten taxonomy arrays to a comparable
            // display string so change-detection (and the will-update/unchanged
            // stats it drives) isn't skewed by comparing an array to a string.
            // Taxonomy Mapping alias rows take priority over the raw passthrough,
            // same as the preview-column loop above.
            if ( ( $config['type'] ?? '' ) === 'taxonomy' || taxonomy_exists( $wc_field ) ) {
                $alias_skipped  = false;
                $alias_term_ids = MMI_Pipeline_Field_Resolver::resolve_taxonomy_via_alias_table( $supplier, $item, $wc_field, $profile, $alias_skipped );
                $real_taxonomy  = ( strpos( $wc_field, 'tax:' ) === 0 ) ? substr( $wc_field, 4 ) : $wc_field;
                if ( empty( $alias_term_ids ) && ! empty( $new_value )
                    && MMI_Pipeline_Field_Mapping_Defaults::resolve_constant( $config, $supplier ) === null
                    && ( $alias_skipped || MMI_Pipeline_Field_Resolver::unmapped_term_policy( $real_taxonomy, $config ) === MMI_Pipeline_Field_Resolver::UNMAPPED_SKIP ) ) {
                    // Unmapped and left as is by the import: not a change.
                    continue;
                }
                $new_value      = ! empty( $alias_term_ids )
                    ? $this->terms_display_from_ids( $alias_term_ids, $wc_field )
                    : $this->normalize_taxonomy_display_value( $new_value );
            }

            // Mirrors MMI_Dynamic_Product_Importer::map_product_data()'s own
            // resolver handling exactly — without it, a field like __mmi_cog
            // (meta_key_resolver: mmi_get_cog_meta_key) compares against the
            // literal, never-written '__mmi_cog' postmeta forever, permanently
            // flagging every product as "will update" for a write the real
            // importer already correctly redirects to the real 'cog' key.
            $resolved_field = $wc_field;
            if ( ! empty( $config['meta_key_resolver'] ) && is_callable( $config['meta_key_resolver'] ) ) {
                $resolved_field = call_user_func( $config['meta_key_resolver'] );
            }
            $mapped_data[$resolved_field] = $new_value;

            // WooCommerce silently discards a sale price that isn't below the
            // regular price — set_sale_price() + save() leaves _sale_price
            // empty (verified against WC directly). Reporting it as a pending
            // change promised something the import could never deliver, so the
            // row stayed "changed" no matter how many times it was imported.
            // The regular price in effect is whichever this run will apply,
            // falling back to the product's current one.
            if ( $wc_field === '_sale_price' && is_numeric( $new_value ) ) {
                $effective_regular = $mapped_data['_regular_price']
                    ?? ( $existing_meta['_regular_price'] ?? null );
                if ( is_numeric( $effective_regular ) && (float) $new_value >= (float) $effective_regular ) {
                    continue;
                }
            }

            // Compare with existing product (a locked field is never written, so never a change)
            if ( $existing_post && ! MMI_Pipeline_Field_Locks::in_list( (string) $wc_field, $locked_fields ) ) {
                $old_value = $this->get_product_field_value_raw($existing_post, $existing_meta, $resolved_field);

                // Field type drives datetime normalisation — without it a
                // sale-dated product never stops showing as "changed". Note
                // this loop's per-field config is $config; $field_config
                // belongs to the separate preview-columns loop above.
                if ( ! MMI_Pipeline_Field_Resolver::values_are_equal( $old_value, $new_value, $config['type'] ?? '' ) ) {
                    $changes[] = [
                        'field' => $resolved_field,
                        'old'   => $old_value,
                        'new'   => $new_value,
                    ];
                }
            }
        }

        // Taxonomy Mapping alias rows can supply a value for taxonomies that
        // have no Field Mapping entry at all (or one that's disabled/unenabled
        // for this supplier) — e.g. product_brand. Mirrors
        // Product_Import_Worker::apply_non_native_taxonomy_aliases() so the
        // preview accurately reflects what the actual import will write,
        // regardless of whether the field happens to be wired up in Field
        // Mapping. product_cat/product_tag are excluded here since they're
        // always real Field Mapping entries handled by the loop above.
        // The mapping table itself is identical for every row, so the caller
        // reads it once and passes the taxonomy column in; only the
        // $mapped_data-dependent filter below is genuinely per-row. Read
        // inline previously, this was one full SELECT against
        // wp_mmi_vip_tax_mappings per item — 6,489 queries on a 6,482-row
        // feed, the single biggest remaining N+1 in the preview.
        $mapping_taxonomies = is_array( $alias_taxonomy_pool )
            ? $alias_taxonomy_pool
            : array_column( MMI_DB::get_tax_mappings( $supplier, '' ), 'wc_taxonomy' );

        $alias_taxonomies = array_unique( array_filter(
            $mapping_taxonomies,
            static function ( $tax ) use ( $mapped_data ) {
                return taxonomy_exists( $tax )
                    && ! in_array( $tax, [ 'product_cat', 'product_tag' ], true )
                    && ! array_key_exists( $tax, $mapped_data );
            }
        ) );

        foreach ( $alias_taxonomies as $taxonomy ) {
            $alias_term_ids = MMI_Pipeline_Field_Resolver::resolve_taxonomy_via_alias_table( $supplier, $item, $taxonomy, $profile );
            if ( empty( $alias_term_ids ) ) {
                continue;
            }

            $new_value               = $this->terms_display_from_ids( $alias_term_ids, $taxonomy );
            $mapped_data[$taxonomy]   = $new_value;

            if ( $existing_post && ! MMI_Pipeline_Field_Locks::in_list( $taxonomy, $locked_fields ) ) {
                $old_value = $this->get_product_field_value_raw($existing_post, $existing_meta, $taxonomy);

                if ( ! MMI_Pipeline_Field_Resolver::values_are_equal( $old_value, $new_value ) ) {
                    $changes[] = [
                        'field' => $taxonomy,
                        'old'   => $old_value,
                        'new'   => $new_value,
                    ];
                }
            }
        }
        
        // Determine if product needs update
        $needs_update = $action === 'create' || count($changes) > 0;

        // SKU-collision pre-flight for "create" rows: the supplier primary-key
        // lookup above can't see a product that exists under a DIFFERENT key
        // (e.g. created manually or imported from another supplier). If the
        // mapped _sku already belongs to a store product, WC_Product::save()
        // will throw "Invalid or duplicated SKU" at import time — surface the
        // conflict here so it's visible before the run instead of failing it.
        // wc_get_product_id_by_sku() reads the indexed wc_product_meta_lookup
        // table, so this adds one cheap keyed query per create row only.
        $sku_conflict = null;
        if ( $action === 'create' && ! empty( $mapped_data['_sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $sku_value   = (string) $mapped_data['_sku'];
            $conflict_id = (int) wc_get_product_id_by_sku( $sku_value );
            if ( $conflict_id ) {
                $conflict_post = get_post( $conflict_id );
                $sku_conflict  = [
                    'product_id'   => $conflict_id,
                    'product_name' => $conflict_post ? $conflict_post->post_title : '',
                    'product_url'  => get_edit_post_link( $conflict_id ),
                    'sku'          => $sku_value,
                    // User has confirmed this is a genuine duplicate — the
                    // caller (generate_preview) excludes dismissed items from
                    // the preview/import entirely rather than retrying them.
                    'dismissed'    => isset( $dismissed_sku_conflicts[ $sku_value ] ),
                ];
            }
        }

        // Primary-key collision: this raw value matches MORE than one live WC
        // product — a genuine data-integrity defect (two posts sharing one
        // supplier tracking key), not the cross-vendor "same item, different
        // feed" case Duplicate Products' candidate scanner handles. See
        // MMI_Pipeline_Field_Resolver::find_primary_key_collisions(). Computed
        // regardless of $action/$product_id — the collision is a fact about
        // the value itself, independent of which product it happened to
        // resolve to above.
        $pk_collision = null;
        if ( is_array( $primary_key_collisions ) && isset( $primary_key_collisions[ (string) $primary_value ] ) ) {
            $colliding_ids  = $primary_key_collisions[ (string) $primary_value ];
            $colliding_info = [];
            foreach ( $colliding_ids as $colliding_id ) {
                $colliding_post   = get_post( $colliding_id );
                $colliding_info[] = [
                    'product_id'   => $colliding_id,
                    'product_name' => $colliding_post ? $colliding_post->post_title : '',
                    'product_url'  => get_edit_post_link( $colliding_id ),
                ];
            }
            $pk_collision = [
                'value'    => (string) $primary_value,
                'products' => $colliding_info,
            ];
        }

        return [
            'primary_key'    => $primary_value,
            'action'         => $action,
            'needs_update'   => $needs_update,
            'product_id'     => $product_id,
            'pk_collision'   => $pk_collision,
            // $mapped_data is keyed by WC field name (post_title, _sku, ...) — 'name'
            // is not a valid key here and was always falling through to 'New Product'
            // for every create row, regardless of the mapped title.
            'product_name'   => $existing_post ? $existing_post->post_title : ( $mapped_data['post_title'] ?? 'New Product' ),
            'product_url'    => $product_id ? get_edit_post_link( $product_id ) : null,
            'mapped_data'    => $mapped_data,
            'changes'        => $changes,
            'change_count'   => count($changes),
            'locked_fields'  => $locked_fields,
            'status'         => $sku_conflict ? 'sku_conflict' : ( $needs_update ? ($action === 'create' ? 'will_create' : 'will_update') : 'unchanged' ),
            'sku_conflict'   => $sku_conflict,
            'preview_fields' => $preview_field_data,
            // Real WP terms ({slug, name}[]) assigned to the EXISTING product,
            // keyed by taxonomy \u2014 see the current_terms computation above.
            // Empty for 'create' rows (no WC product yet).
            'current_terms'  => $current_terms,
            // Native WP-app filter facts \u2014 see the batch maps built in
            // generate_preview(). Empty/null for 'create' rows.
            'author_id'         => $author_id,
            'author_name'       => $author_name,
            'order_statuses'    => $order_statuses,
            'last_order_date'   => $last_order_date,
            'in_active_coupon'  => $in_active_coupon,
            // Raw data-source field values, for the "Create Only" source-data
            // filter group \u2014 populated for every row, see the computation above.
            'source_filter_values' => $source_filter_values,
        ];
    }
    
    /**
     * Load the promotions file for a supplier and return an array indexed by
     * the promo's own identifier key (sku / id / product_id) so items can be
     * merged in O(1) per product during preview generation.
     *
     * Returns [] when no promo file exists or cannot be parsed.
     *
     * Promotions loading/indexing now lives in
     * MMI_Pipeline_Field_Resolver::load_and_index_promotions() — single
     * canonical implementation shared with the importer and ProductImportController.
     */

    /**
     * Fetch product postmeta in one lightweight SQL query.
     * Skips large/serialised keys (_product_attributes, ratings) that are
     * not needed for field comparison and bloat the object cache.
     */
    private function get_product_meta_fast( int $product_id ): array {
        // get_post_meta() with no key reads through WP's meta cache, which
        // generate_preview() primes for the whole matched set in one batch —
        // so this costs zero queries there. The raw $wpdb SELECT this replaced
        // bypassed that cache entirely, forcing one query per row (measured at
        // 6,482 queries for a 6,482-row feed) and defeating the persistent
        // object cache on repeat loads too.
        $raw = get_post_meta( $product_id );

        if ( ! is_array( $raw ) ) {
            return [];
        }

        // Excluded keys are large/derived values the preview never displays;
        // skipping them keeps the returned array (and the JSON response built
        // from it) from carrying serialized attribute blobs per row.
        static $excluded = [
            '_product_attributes'  => true,
            '_wc_average_rating'   => true,
            '_wc_review_count'     => true,
            '_wc_rating_count'     => true,
        ];

        $meta = [];
        foreach ( $raw as $key => $values ) {
            if ( isset( $excluded[ $key ] ) ) {
                continue;
            }
            // First occurrence wins (postmeta may have dup keys for multi-value
            // fields) — matches the previous query's row ordering semantics.
            if ( is_array( $values ) && array_key_exists( 0, $values ) ) {
                $meta[ $key ] = $values[0];
            }
        }

        return $meta;
    }
    
    /**
     * Get a product field value from a lightweight WP_Post + raw meta array,
     * avoiding the memory exhaustion that instantiating a WC_Product per row
     * caused when processing hundreds of products. (The WC-object-based
     * get_product_field_value() this superseded has since been deleted.)
     *
     * Semantic equality now lives in MMI_Pipeline_Field_Resolver::values_are_equal()
     * — single canonical implementation shared with the importer and ProductImportController.
     */
    private function get_product_field_value_raw( \WP_Post $post, array $meta, string $field ): ?string {
        switch ( $field ) {
            case 'post_title':
            case 'name':
                return $post->post_title;
            case 'post_content':
            case 'description':
                return $post->post_content;
            case 'post_excerpt':
            case 'short_description':
                return $post->post_excerpt;
            case 'post_status':
                return $post->post_status;
            case 'product_cat':
            case 'product_cat_ids':
                // Categories are a taxonomy, not postmeta — the default case below
                // was falling through to a postmeta lookup for a 'product_cat' key
                // that this plugin never writes, which could pick up unrelated
                // stale/serialized data from another plugin's postmeta row of the
                // same name instead of the product's actual assigned categories.
                return $this->get_product_terms_display( $post->ID, 'product_cat' );
            case 'product_tag':
            case 'product_tag_ids':
                return $this->get_product_terms_display( $post->ID, 'product_tag' );
            default:
                // Any other registered taxonomy (product_brand, or any custom
                // one reachable through the Taxonomy Mapping alias table) reads
                // its assigned terms. Only product_cat/product_tag were special-
                // cased above, so everything else fell through to the postmeta
                // lookup below — which has no row for a taxonomy and returned
                // null, leaving the row flagged "changed" forever even straight
                // after a successful import that assigned the term correctly.
                if ( taxonomy_exists( $field ) ) {
                    return $this->get_product_terms_display( $post->ID, $field );
                }

                // All standard WooCommerce fields (_regular_price, _sku, _stock_status, etc.)
                // are stored in postmeta with the same key name.
                return isset( $meta[ $field ] ) ? (string) $meta[ $field ] : null;
        }
    }

    /**
     * Comma-separated term names currently assigned to a product for a given
     * taxonomy — used as the preview's CURRENT value for product_cat/product_tag.
     */
    private function get_product_terms_display( int $product_id, string $taxonomy ): ?string {
        $terms = get_the_terms( $product_id, $taxonomy );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return null;
        }
        return implode( ', ', wp_list_pluck( $terms, 'name' ) );
    }

    /**
     * Flatten a taxonomy field's raw resolved source value (array of name
     * strings, or array of {name,...}-shaped rows, per supplier feed format)
     * into the same comma-separated display string used for the CURRENT side —
     * so SOURCE/CURRENT are actually comparable and the UI never renders a raw
     * array/object (JSON dump or empty-object glyph) for these fields.
     *
     * @param mixed $value
     * @return mixed  String when normalized; original value unchanged if it
     *                wasn't an array (e.g. already a delimited string).
     */
    private function normalize_taxonomy_display_value( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        $parts = array_map( function ( $entry ) {
            if ( is_array( $entry ) ) {
                $entry = $entry['name'] ?? $entry['title'] ?? $entry['label'] ?? ( is_scalar( reset( $entry ) ) ? reset( $entry ) : '' );
            } elseif ( is_object( $entry ) ) {
                $entry = $entry->name ?? $entry->title ?? $entry->label ?? '';
            }
            return is_scalar( $entry ) ? trim( (string) $entry ) : '';
        }, $value );

        return implode( ', ', array_filter( $parts, static fn( $p ) => $p !== '' ) );
    }

    /**
     * Comma-separated term names for a list of already-resolved term IDs —
     * used when the Taxonomy Mapping alias table resolved the value, so the
     * preview shows the real assigned term name(s) rather than the raw code.
     */
    private function terms_display_from_ids( array $term_ids, string $taxonomy ): string {
        $names = array_map( static function ( $term_id ) use ( $taxonomy ) {
            $term = get_term( $term_id, $taxonomy );
            return ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
        }, $term_ids );
        return implode( ', ', array_filter( $names ) );
    }
    
    /**
     * Dot-notation/bracket path walking and transform application now live in
     * MMI_Pipeline_Field_Resolver::get_nested_value() / ::apply_transform() —
     * single canonical implementation shared with the importer and ProductImportController.
     */

    /**
     * Extract items from JSON data based on supplier format
     */
    private function extract_items($data, $supplier) {
        // Different suppliers have different JSON structures
        switch ($supplier) {
            case 'xchange':
                return $data['products'] ?? $data ?? [];
            case 'skuport':
                return $data['products'] ?? $data ?? [];
            case 'plugivery':
                return $data['products'] ?? $data ?? [];
            default:
                return $data;
        }
    }
    
    /**
     * Get JSON filename for supplier
     */
    private function get_json_file_for_supplier($supplier) {
        $files = [
            'xchange'  => 'xchange-products.json',
            'skuport'  => 'skuport-products.json',
            'plugivery' => 'plugivery-products.json',
        ];

        // Dynamic data sources (url, upload, dropbox, gdrive) store their
        // fetched data as {supplier_id}-products.json via Data_Source_Manager.
        return $files[$supplier] ?? ( sanitize_file_name( $supplier ) . '-products.json' );
    }

    /* ── Per-Profile Pending Stats ────────────────────────────────────────
     * "How much create/update work is the current source data waiting on
     * for this profile" — a cheap-to-read summary of the same computation
     * generate_preview() already does for Review & Compare, cached so the
     * Import Profiles grid can show it without recomputing on every page
     * load, and read (never computed synchronously) by the scheduled
     * dispatcher's "skip if no new data" gate — see
     * MMI_Pipeline_Cron::run_scheduled_profile_import().
     */

    /** How long a cached pending-stats snapshot is considered fresh. */
    const PENDING_STATS_FRESH_SECONDS = 20 * MINUTE_IN_SECONDS;

    /** Concurrency-guard lock TTL — long enough to cover a real compute, short enough that a fataled worker doesn't wedge this profile forever. */
    const PENDING_STATS_LOCK_TTL_SECONDS = 300;

    public static function pending_stats_setting_key( string $profile_id ): string {
        return 'mmi_pipeline_profile_pending_stats_' . $profile_id;
    }

    /**
     * Which stat actually matters for this profile's own mode/scope —
     * mirrors generate_preview()'s own create/update precedence exactly
     * (product_scope='new_only' or import_mode='create-only' → only
     * creates are real; 'update-only'/'availability-sync' → only updates
     * are real; anything else can do both) so the grid column and the
     * skip-gate never disagree with what a real run would actually do.
     *
     * @param array<string, mixed> $profile_data One row from MMI_DB::get_profiles().
     * @return string 'create'|'update'|'both'
     */
    public static function relevant_pending_type_for( array $profile_data ): string {
        $import_mode   = $profile_data['import_mode']   ?? 'update-only';
        $product_scope = $profile_data['product_scope'] ?? 'all_products';

        if ( $product_scope === 'new_only' || $import_mode === 'create-only' ) {
            return 'create';
        }
        if ( $import_mode === 'update-only' || $import_mode === 'availability-sync' ) {
            return 'update';
        }
        return 'both';
    }

    public static function get_relevant_pending_type( string $profile_id ): string {
        $profile_data = MMI_DB::get_profiles()[ $profile_id ] ?? [];
        return self::relevant_pending_type_for( $profile_data );
    }

    /**
     * Does a pending-stats snapshot show anything this profile would
     * actually act on? Used by the schedule dispatcher's skip gate — NOT
     * by the grid, which shows the raw counts regardless.
     *
     * @param string               $profile_id
     * @param array<string, mixed> $pending
     */
    public static function is_pending_actionable( string $profile_id, array $pending ): bool {
        $type   = self::get_relevant_pending_type( $profile_id );
        $create = (int) ( $pending['will_create'] ?? 0 );
        $update = (int) ( $pending['will_update'] ?? 0 );

        if ( $type === 'create' ) {
            return $create > 0;
        }
        if ( $type === 'update' ) {
            return $update > 0;
        }
        return ( $create + $update ) > 0;
    }

    /**
     * Read-only — never computes. Used anywhere a synchronous, bounded-time
     * caller (a page render, the <1s cron dispatcher) needs "what do we
     * last know" without risking a multi-second generate_preview() scan.
     * Returns null only when nothing has ever been computed for this
     * profile yet.
     *
     * @return array{will_create:int,will_update:int,total_in_feed:int,computed_at:string,stale:bool,age_seconds:int}|null
     */
    public static function get_cached_profile_pending_stats( string $profile_id ): ?array {
        $cached = MMI_DB::get_setting( self::pending_stats_setting_key( $profile_id ), null );
        if ( ! is_array( $cached ) || empty( $cached['computed_at'] ) ) {
            return null;
        }
        $age = time() - strtotime( $cached['computed_at'] );
        return array_merge( $cached, [
            'stale'       => $age >= self::PENDING_STATS_FRESH_SECONDS,
            'age_seconds' => $age,
        ] );
    }

    /**
     * Read the cached snapshot if it's fresh; otherwise compute (and cache)
     * a fresh one now. This is the one method allowed to actually run
     * generate_preview() — called from the AJAX handler (a deliberate,
     * user-triggered or page-load-deferred request, not the cron
     * dispatcher's own <1s budget) and from the Action Scheduler warm job.
     *
     * @return array{will_create:int,will_update:int,total_in_feed:int,computed_at:string,sources:array<int,string>,errors:array<int,string>,stale:bool}
     */
    public function get_profile_pending_stats( string $profile_id, bool $force_refresh = false ): array {
        $key    = self::pending_stats_setting_key( $profile_id );
        $cached = MMI_DB::get_setting( $key, null );

        $is_fresh = is_array( $cached ) && ! empty( $cached['computed_at'] )
            && ( time() - strtotime( $cached['computed_at'] ) ) < self::PENDING_STATS_FRESH_SECONDS;

        if ( is_array( $cached ) && ! $force_refresh && $is_fresh ) {
            return array_merge( $cached, [ 'stale' => false ] );
        }

        // Prevent two concurrent computations for the same profile (a
        // manual "Check" click racing the background warm job, or a
        // page load kicking off checks for several profiles at once).
        $lock_key = 'mmi_pipeline_pending_stats_lock_' . $profile_id;
        if ( MMI_DB::get_job_state( $lock_key ) ) {
            return is_array( $cached )
                ? array_merge( $cached, [ 'stale' => true, 'computing' => true ] )
                : [ 'will_create' => 0, 'will_update' => 0, 'total_in_feed' => 0, 'computed_at' => null, 'stale' => true, 'computing' => true ];
        }

        MMI_DB::set_job_state( $lock_key, 1, self::PENDING_STATS_LOCK_TTL_SECONDS );

        try {
            $result = $this->compute_profile_pending_stats( $profile_id );
            MMI_DB::set_setting( $key, $result );
            MMI_DB::delete_job_state( $lock_key );
            return array_merge( $result, [ 'stale' => false ] );
        } catch ( \Throwable $e ) {
            MMI_DB::delete_job_state( $lock_key );
            MMI_Logger::error(
                "Pending stats computation failed [{$profile_id}]: " . $e->getMessage(),
                [], 'sync', 'MMI_Import_Preview'
            );
            return is_array( $cached )
                ? array_merge( $cached, [ 'stale' => true, 'error' => $e->getMessage() ] )
                : [ 'will_create' => 0, 'will_update' => 0, 'total_in_feed' => 0, 'computed_at' => null, 'stale' => true, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Which of this profile's assigned sources are currently enabled/validated
     * data sources — same "which sources does this profile actually use"
     * derivation as MMI_Pipeline_Cron::run_scheduled_profile_import(), kept
     * in sync with that one deliberately rather than shared, since the cron
     * path also carries state-record/abandoned-run concerns this read-only
     * stats class has no use for. Shared here, though, between this class's
     * own two callers (compute_profile_pending_stats() and
     * handle_report_live_pending_stats()) so "which sources are correct for
     * this profile" is computed in exactly one place, not two copies that
     * can quietly drift from each other.
     *
     * @return string[] Supplier ids — order not guaranteed; callers comparing
     *                   this against another list should sort both first.
     */
    private static function enabled_sources_for_profile( array $profiles, string $profile_id ): array {
        global $wpdb;
        $ds_table          = $wpdb->prefix . 'mmi_data_sources';
        $enabled_suppliers = ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) === $ds_table )
            ? $wpdb->get_col( "SELECT supplier_id FROM {$ds_table} WHERE config_status = 'validated' ORDER BY display_order ASC" )
            : array_values( (array) MMI_DB::get_setting( 'mmi_pipeline_enabled_suppliers', [] ) );
        $profile_sources = $profiles[ $profile_id ]['sources'] ?? [];
        if ( ! empty( $profile_sources ) ) {
            $enabled_suppliers = array_values( array_intersect( $enabled_suppliers, $profile_sources ) );
        }
        return $enabled_suppliers;
    }

    /**
     * The actual work — one generate_preview() call per source this
     * profile is assigned to (excludes creates/updates the same way a real
     * run would, since generate_preview() already applies product_scope/
     * import_mode precedence), summed. Never called directly outside
     * get_profile_pending_stats()'s lock.
     */
    private function compute_profile_pending_stats( string $profile_id ): array {
        $profiles = MMI_DB::get_profiles();
        if ( ! isset( $profiles[ $profile_id ] ) ) {
            throw new Exception( "Unknown profile: {$profile_id}" );
        }

        $enabled_suppliers = self::enabled_sources_for_profile( $profiles, $profile_id );

        $will_create   = 0;
        $will_update   = 0;
        $total_in_feed = 0;
        $errors        = [];

        foreach ( $enabled_suppliers as $supplier ) {
            try {
                $preview        = $this->generate_preview( $supplier, 999999, [], $profile_id, [] );
                $will_create   += (int) ( $preview['stats']['will_create'] ?? 0 );
                $will_update   += (int) ( $preview['stats']['will_update'] ?? 0 );
                $total_in_feed += (int) ( $preview['total_in_feed'] ?? 0 );
            } catch ( \Throwable $e ) {
                // A missing/unfetched feed file for one source shouldn't
                // block the whole profile's stats — logged and surfaced in
                // the response, not fatal.
                $errors[] = "{$supplier}: " . $e->getMessage();
            }
        }

        return [
            'will_create'   => $will_create,
            'will_update'   => $will_update,
            'total_in_feed' => $total_in_feed,
            'computed_at'   => current_time( 'mysql' ),
            'sources'       => $enabled_suppliers,
            'errors'        => $errors,
        ];
    }

    /**
     * Queue a background (Action Scheduler) recompute for one profile if
     * one isn't already pending. Fire-and-forget — callers never wait on
     * this. Used both when the schedule dispatcher decides to skip a run
     * (so the NEXT check has fresh evidence) and when it decides to run
     * normally because the cache was missing/stale.
     */
    public static function queue_pending_stats_refresh( string $profile_id ): void {
        if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
            return;
        }
        $hook  = 'mmi_pipeline_as_warm_profile_pending_stats';
        $group = 'mmi-pipeline-pending-stats';
        if ( as_next_scheduled_action( $hook, [ $profile_id ], $group ) ) {
            return;
        }
        as_schedule_single_action( time(), $hook, [ $profile_id ], $group, false, MMI_PIPELINE_AS_PRIORITY_BATCH );
    }

    /** Action Scheduler callback for queue_pending_stats_refresh(). */
    public function warm_pending_stats_action( string $profile_id ): void {
        $this->get_profile_pending_stats( $profile_id, true );
    }

    /**
     * AJAX: backs the Import Profiles grid's Pending column — a manual
     * "Check"/refresh click, or the page-load-time background sweep that
     * fills in any profile without a fresh cached snapshot yet (see
     * initProfilePendingStats() in import-pipeline.js).
     */
    public function handle_get_profile_pending_stats() {
        check_ajax_referer( 'mmi_product_importer_nonce', 'nonce' );

        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
        }

        $profile_id     = sanitize_text_field( $_POST['profile_id'] ?? '' );
        $force_refresh  = ! empty( $_POST['force_refresh'] );

        // Scoped to import-direction profiles specifically — this is the
        // Import Profiles grid's own column, and generate_preview() (what
        // this ultimately calls) assumes an import profile's shape
        // (import_mode/product_scope), not an export profile's.
        $profiles = MMI_DB::get_profiles_by_direction( 'import' );
        if ( empty( $profile_id ) || ! isset( $profiles[ $profile_id ] ) ) {
            wp_send_json_error( [ 'message' => 'Unknown profile' ] );
        }

        $stats = $this->get_profile_pending_stats( $profile_id, $force_refresh );

        wp_send_json_success( array_merge( $stats, [
            'profile_id'     => $profile_id,
            'relevant_type'  => self::relevant_pending_type_for( $profiles[ $profile_id ] ),
        ] ) );
    }

    /**
     * AJAX: opportunistic cache refresh, called by import-preview.js's
     * renderSummary() once a full (isExact — every item in the feed
     * actually scanned, no sampling) Review & Compare load finishes for a
     * profile. The exact numbers were already computed server-side, per
     * supplier, by the same generate_preview() calls
     * compute_profile_pending_stats() itself would have made — this just
     * relays their sum back into the same cache, so the Import Profiles
     * grid's "Pending" column doesn't keep showing a stale number just
     * because nobody happened to click "Refresh now" after visiting Review
     * & Compare for this profile.
     *
     * Never trusts the reported numbers blindly: only writes the cache when
     * the reported source list is EXACTLY the profile's real enabled
     * sources (see enabled_sources_for_profile()) — a partial scan (one
     * supplier failed to load, or a stale tab holding an outdated source
     * list) can never silently overwrite a good cached count with an
     * incomplete one.
     */
    public function handle_report_live_pending_stats() {
        check_ajax_referer( 'mmi_product_importer_nonce', 'nonce' );

        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
        }

        $profile_id = sanitize_text_field( $_POST['profile_id'] ?? '' );
        $profiles   = MMI_DB::get_profiles();
        if ( empty( $profile_id ) || ! isset( $profiles[ $profile_id ] ) ) {
            wp_send_json_error( [ 'message' => 'Unknown profile' ] );
        }

        $reported_sources = json_decode( stripslashes( (string) ( $_POST['sources'] ?? '[]' ) ), true );
        if ( ! is_array( $reported_sources ) ) {
            wp_send_json_error( [ 'message' => 'Invalid sources payload' ] );
        }
        $reported_sources = array_values( array_unique( array_map( 'sanitize_text_field', $reported_sources ) ) );
        sort( $reported_sources );

        $enabled_suppliers = self::enabled_sources_for_profile( $profiles, $profile_id );
        sort( $enabled_suppliers );

        if ( $reported_sources !== $enabled_suppliers ) {
            wp_send_json_error( [ 'message' => "Reported sources do not match this profile's enabled sources — cache not updated" ] );
        }

        $result = [
            'will_create'   => max( 0, (int) ( $_POST['will_create'] ?? 0 ) ),
            'will_update'   => max( 0, (int) ( $_POST['will_update'] ?? 0 ) ),
            'total_in_feed' => max( 0, (int) ( $_POST['total_in_feed'] ?? 0 ) ),
            'computed_at'   => current_time( 'mysql' ),
            'sources'       => $enabled_suppliers,
            'errors'        => [],
        ];
        MMI_DB::set_setting( self::pending_stats_setting_key( $profile_id ), $result );

        wp_send_json_success( array_merge( $result, [ 'stale' => false ] ) );
    }
}

// Initialize
MMI_Import_Preview::instance();
