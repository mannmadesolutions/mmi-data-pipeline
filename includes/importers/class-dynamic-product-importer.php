<?php
/**
 * Dynamic Product Importer
 * 
 * Configuration-driven importer that adapts to ANY supplier based on field mappings.
 * No hardcoded field mappings - everything comes from mmi_pipeline_field_mappings option.
 * Supports promotional pricing, multiple suppliers, and easy onboarding.
 *
 * @package MannMade\DataPipeline\Importers
 */

namespace MannMade\DataPipeline\Importers;

if (!defined('ABSPATH')) {
    exit;
}

class MMI_Dynamic_Product_Importer {
    
    /**
     * Supplier name (xchange, skuport, plugivery, etc.)
     */
    protected $supplier_name = '';
    
    /**
     * Import profile (default, pricing, full, etc.)
     */
    protected $profile = 'default';
    
    /**
     * JSON file path
     */
    protected $json_file = '';
    
    /**
     * Field mappings from database
     */
    protected $field_mappings = [];

    /**
     * Existing product the record being mapped will update (0 when it will
     * be created) — read by field conditions on WP/WC sources.
     */
    protected $mapping_product_id = 0;
    
    /**
     * Primary key configuration
     */
    protected $primary_key_source = '';
    protected $primary_key_wc = '_sku';

    /**
     * Promotions for the current run, indexed by SKU/ID for O(1) lookup.
     * Populated once in run() via MMI_Pipeline_Field_Resolver::load_and_index_promotions().
     */
    protected $promotions_index = [];

    /**
     * Xchange's supplemental "web assets" feed (richer images + long-form
     * description), indexed by SKU. Populated once in run() via
     * MMI_Pipeline_Field_Resolver::load_web_assets_index() — empty for every
     * supplier other than 'xchange', and empty for xchange too unless the site
     * has mmi-data-pipeline VIP tier access and the writer
     * (MMI_Xchange_Vendors::cron_write_web_assets_json()) has run at least once.
     */
    protected $web_assets_index = [];

    /**
     * Batch progress from the most recent run() call — set when $limit/$offset
     * are used (see run()'s docblock). Unbounded calls (the default) always
     * leave has_more = false / next_offset = total_in_feed.
     */
    protected $total_in_feed = null;
    protected $next_offset   = null;
    protected $has_more      = false;

    /**
     * value => product ID map for the CURRENT batch's own primary-key values,
     * pre-resolved in one chunked WHERE-IN query by run()'s standard (non-variable)
     * per-item loop — see the comment at that call site and
     * MMI_Pipeline_Field_Resolver::find_product_ids_by_primary_keys()'s own
     * docblock, which fixed the identical N+1 shape for Import Preview's
     * full-feed scan and (later) ProductImportController's manual "Run Import
     * Now" path; this scheduled-import class had never received the same fix.
     * Null (the default) means "not batch-resolved for this call" —
     * find_product_by_primary_key() falls back to its original one-query-per-item
     * lookup, which is what still happens for the variable-product paths
     * (run_variable_flat()/run_variable_nested()) below, unchanged.
     */
    protected $resolved_product_ids = null;

    /**
     * Import statistics
     */
    protected $stats = [
        'processed' => 0,
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'skipped' => 0,
        'errors' => 0,
        'pricing_updated' => 0,
        'stock_updated' => 0,
        'promo_applied' => 0,
    ];

    /**
     * Per-item failure detail — mirrors what ProductImportController's manual
     * batch loop already captures ($acc_failures), so scheduled/cron imports
     * can report WHICH item failed and WHY instead of only a bare error count.
     * Capped so a pathological run (thousands of failures) can't bloat the
     * state record threaded back through run_profile_import_batch().
     */
    const MAX_FAILURE_DETAILS = 25;
    protected $failures = [];

    /**
     * Log messages
     */
    protected $log_entries = [];
    
    /**
     * Throttle manager instance
     */
    protected $throttle = null;
    
    /**
     * Import rules
     */
    protected $import_rules = [];

    /**
     * Product scope: 'all_products', 'by_identifier', or 'new_only'
     */
    protected $product_scope = 'all_products';

    /**
     * Identifier config (decoded from profile's product_identifier JSON).
     * Shape: [ 'storage_type' => ..., 'storage_config' => [...], 'auto_apply_to_new' => bool ]
     */
    protected $product_identifier = null;

    /**
     * Stock override resolver — applies per-product and bulk overrides before
     * source stock data reaches the WooCommerce product object.
     */
    protected $stock_override_resolver = null;

    /**
     * Attribute & variation mapping config loaded from the Attributes panel.
     *
     * @var array<string, mixed>
     */
    protected $attribute_config = [];

    /**
     * Collaborators instantiated once in load_configuration(). See the god-class
     * decomposition note above process_item() for what moved where.
     */
    protected $product_crud = null;
    protected $taxonomy_handler = null;
    protected $variable_product_manager = null;

    /**
     * Constructor
     *
     * @param string $supplier Supplier name (xchange, skuport, plugivery, etc.)
     * @param string $profile Import profile (default, pricing, full, etc.)
     * @param bool   $enable_throttle Whether to register with WP_Throttle_Manager
     *               and pause for safe server load. Must be false for WP-Cron-invoked
     *               runs — wait_for_safe_load() can block for up to 900s, far beyond
     *               the ~50s wall-clock budget WP-Cron gets per cycle, which guarantees
     *               the cron process is killed mid-import every time. Only the manual
     *               VIP dashboard import (which has no such time limit) should throttle.
     */
    public function __construct($supplier, $profile = 'default', $enable_throttle = true) {
        $this->supplier_name = $supplier;
        $this->profile = $profile;

        // Load configuration from database
        $this->load_configuration();

        // Set JSON file path
        $this->json_file = $this->get_json_file_path();

        // Initialize throttle manager if available
        if ($enable_throttle && class_exists('WP_Throttle_Manager')) {
            $this->throttle = \WP_Throttle_Manager::instance();
        }
    }
    
    /**
     * Load configuration from database
     */
    protected function load_configuration() {
        // Effective mappings = saved overrides merged onto field-mapping defaults —
        // matches the Field Mapping UI and the Import Preview (see ProductImportController.php).
        // The raw MMI_DB::get_field_mappings() call only returns explicitly-saved rows; a
        // profile that has never had its mappings edited (e.g. "pricing") gets back an empty
        // array, so price/stock fields are silently omitted and every product is reported
        // "unchanged" even when the source feed has actually moved.
        $this->field_mappings = \MMI_Pipeline_Field_Mapping_Defaults::get_effective( $this->profile );
        
        // Load primary key configuration for this supplier
        $this->primary_key_source = \MMI_DB::get_primary_key( $this->supplier_name, 'source', 'id' );
        $this->primary_key_wc = \MMI_DB::get_primary_key( $this->supplier_name, 'wc', '_sku' );
        
        // Load import rules - derive allow_create from the profile's import_mode.
        // Legacy per-profile option is honoured as a fallback if mode is unset.
        $allow_create = false;
        $profiles     = \MMI_DB::get_profiles();
        $profile_meta = ( is_array( $profiles ) ? ( $profiles[ $this->profile ] ?? [] ) : [] );
        $import_mode  = $profile_meta['import_mode'] ?? null;

        if ( \MMI_Pipeline_Field_Mapping_Defaults::is_create_capable_mode( $import_mode ) ) {
            $allow_create = true;
        } elseif ( $import_mode === 'availability-sync' || $import_mode === 'update-only' ) {
            $allow_create = false;
        } else {
            // Legacy fallback.
            $allow_create_option_key = $this->profile === 'default'
                ? 'mmi_pipeline_import_allow_create_products'
                : 'mmi_pipeline_import_allow_create_products_' . $this->profile;
            $allow_create = (bool) \MMI_DB::get_setting( $allow_create_option_key, true );
        }

        $this->import_rules = [
            'duplicate_strategy' => \MMI_DB::get_setting( 'mmi_pipeline_import_duplicate_strategy', 'update' ),
            'price_update'       => \MMI_DB::get_setting( 'mmi_pipeline_import_price_update', true ),
            'stock_update'       => \MMI_DB::get_setting( 'mmi_pipeline_import_stock_update', true ),
            'image_sync'         => \MMI_DB::get_setting( 'mmi_pipeline_import_image_sync', false ),
            'category_mapping'   => \MMI_DB::get_setting( 'mmi_pipeline_import_category_mapping', true ),
            'allow_create_products' => $allow_create,
            'import_mode'           => $import_mode ?? 'update-only',
        ];

        // Load product scope and identifier from profile metadata.
        $this->product_scope      = $profile_meta['product_scope'] ?? 'all_products';
        $this->product_identifier = $profile_meta['product_identifier'] ?? null;

        // Load stock override rules and instantiate resolver.
        $override_rules = \MMI_DB::get_setting( 'mmi_stock_override_rules', [] );
        if ( ! is_array( $override_rules ) ) {
            $override_rules = [];
        }
        $this->stock_override_resolver = new \MannMade\DataPipeline\Stock_Override_Resolver( $override_rules );

        // Load attribute & variation config (from Attributes panel)
        if ( function_exists( 'mmi_get_attribute_config' ) ) {
            $this->attribute_config = mmi_get_attribute_config( $this->profile );
        }

        // Instantiate collaborators now that all the config they need is loaded.
        $logger = function ( string $message, string $type = 'info' ) {
            $this->log( $message, $type );
        };
        $this->product_crud             = new Product_CRUD_Manager( $this->import_rules, $logger );
        $this->taxonomy_handler         = new Taxonomy_Mapping_Handler( $this->field_mappings, $this->supplier_name, $this->product_scope, $this->product_identifier, $this->profile );
        $this->variable_product_manager = new Variable_Product_Manager( $this->attribute_config, $logger );

        $this->log("Configuration loaded: " . count($this->field_mappings) . " field mappings for profile '{$this->profile}'");
        if ( $this->product_scope === 'by_identifier' && ! empty( $this->product_identifier ) ) {
            $type = $this->product_identifier['storage_type'] ?? '?';
            $this->log( "Product scope: by_identifier (storage_type: {$type})" );
        }
    }
    
    /**
     * Get JSON file path for current supplier
     */
    protected function get_json_file_path() {
        $json_dir = mmi_shared_lib_json_dir();
        
        // Support multiple files per supplier (products + promotions)
        $file_map = [
            'xchange' => 'xchange-products.json',
            'skuport' => 'skuport-products.json',
            'plugivery' => 'plugivery-products.json',
        ];
        
        return $json_dir . ($file_map[$this->supplier_name] ?? $this->supplier_name . '-products.json');
    }
    
    /**
     * Promotions loading/indexing now lives in
     * MMI_Pipeline_Field_Resolver::load_and_index_promotions(), called once
     * from run(). get_promotions_file_path()/extract_promotions() removed —
     * no longer called.
     */

    /**
     * Run the import.
     *
     * @param int        $offset               Index to start processing from (for batched/resumable
     *                                          runs — see MMI_Pipeline_Cron::run_profile_import_batch()).
     * @param int|null   $limit                Max items to process this call. Null = unbounded
     *                                          (default; processes the full catalog in one call,
     *                                          unchanged behavior for all pre-existing callers).
     * @param float|null $time_budget_seconds  Wall-clock cap for this call. If set, processing stops
     *                                          as soon as elapsed time exceeds this, even if $limit
     *                                          items haven't all been reached. Null = no cap.
     * @param int|null   $memory_budget_bytes  Memory cap for this call (checked via memory_get_usage(true)
     *                                          every 25 items). If set, processing stops as soon as usage
     *                                          crosses this, regardless of $limit/$time_budget_seconds.
     *                                          Null = no cap. See class-pipeline-cron.php's
     *                                          run_profile_import_batch() for why this exists.
     */
    public function run( int $offset = 0, ?int $limit = null, ?float $time_budget_seconds = null, ?int $memory_budget_bytes = null ) {
        $this->log("Starting {$this->supplier_name} import (profile: {$this->profile})...");

        // Register with throttle manager
        if ($this->throttle) {
            $process_id = "product_import_{$this->supplier_name}";
            $this->throttle->register_process($process_id, [
                'name' => ucfirst($this->supplier_name) . ' Product Import',
                'type' => 'php',
                'max_load' => 2.5,
                'max_cpu' => 80,
                'max_memory' => 85,
                'poll_interval' => 3,
                'timeout' => 900
            ]);
            $this->throttle->start_process($process_id, ['source' => 'vip_dashboard']);
            $this->log("Throttle manager registered for {$process_id}");
        }

        // Check if products file exists
        if (!file_exists($this->json_file)) {
            $this->log("ERROR: JSON file not found: {$this->json_file}", 'error');
            if ($this->throttle) {
                $this->throttle->complete_process("product_import_{$this->supplier_name}", ['status' => 'error']);
            }
            $this->total_in_feed = 0;
            $this->next_offset   = $offset;
            $this->has_more      = false;
            return $this->get_results();
        }
        
        // Load and index promotions data if available — O(1) lookup per item via
        // $this->promotions_index instead of the old O(n) linear scan in find_promotion().
        // Canonical loader: MMI_Pipeline_Field_Resolver::load_and_index_promotions()
        // (same logic used by the preview generator and ProductImportController).
        $json_dir = mmi_shared_lib_json_dir();
        $promo_result = \MMI_Pipeline_Field_Resolver::load_and_index_promotions($this->supplier_name, $json_dir);
        $this->promotions_index = $promo_result['index'];
        $promotions = $this->promotions_index; // retained for variable-product call signatures below
        if (!empty($this->promotions_index)) {
            $this->log("Loaded " . count($this->promotions_index) . " promotions for {$this->supplier_name}");
        }

        // Xchange web-assets enrichment. Previously also gated behind a 'vip'
        // feature tier — removed along with the tier/feature-license system;
        // available to any valid mmi-data-pipeline license. Loader itself
        // already no-ops for non-xchange suppliers / missing file.
        if ( $this->supplier_name === 'xchange' ) {
            $this->web_assets_index = \MMI_Pipeline_Field_Resolver::load_web_assets_index( $this->supplier_name, $json_dir );
            if ( ! empty( $this->web_assets_index ) ) {
                $this->log( "Loaded " . count( $this->web_assets_index ) . " web-asset records for {$this->supplier_name}" );
            }
        }
        
        // Read products JSON file
        $json_content = file_get_contents($this->json_file);
        $data = json_decode($json_content, true);
        unset($json_content); // Free the raw string — the decoded array is what we need

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("ERROR: Invalid JSON in file: " . json_last_error_msg(), 'error');
            $this->total_in_feed = 0;
            $this->next_offset   = $offset;
            $this->has_more      = false;
            return $this->get_results();
        }

        // Process items
        $items = $this->extract_items($data);
        unset($data); // Free the outer wrapper; $items holds the product list
        $total = count($items);
        $this->total_in_feed = $total;

        $this->log("Found {$total} items to process" . ( $limit !== null ? " (batch offset={$offset}, limit={$limit})" : '' ));

        // Variable product import — dispatch to the appropriate runner before
        // the standard per-item loop. Batching ($offset/$limit/$time_budget_seconds)
        // is not supported here — variable profiles always run unbounded.
        if ( ! empty( $this->attribute_config['enabled'] )
             && ( $this->attribute_config['product_type'] ?? 'simple' ) === 'variable' ) {
            $this->next_offset = $total;
            $this->has_more    = false;
            $mode = $this->attribute_config['variation_mode'] ?? 'flat';
            if ( $mode === 'nested' ) {
                return $this->run_variable_nested( $items, $promotions );
            }
            return $this->run_variable_flat( $items, $promotions );
        }

        // preserve_keys=true so $index below remains the item's true position in
        // the full feed — keeps throttle/GC modulo checks and progress logging
        // ("current/total") meaningful across batches, not reset to 0 each call.
        $batch_items = ( $limit !== null ) ? array_slice( $items, $offset, $limit, true ) : $items;

        // Pre-resolve every item's existing-product ID for THIS batch in one
        // chunked WHERE-IN query, instead of process_item() -> find_product_by_primary_key()
        // making one unbatched postmeta query per item below. Scoped to $batch_items
        // (not the full $items feed) since that's already exactly the slice this
        // call will actually process — no wasted resolution work on items a later
        // batch call will handle. See $resolved_product_ids's own docblock.
        $batch_pk_values = [];
        foreach ( $batch_items as $item ) {
            $v = \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $this->primary_key_source );
            if ( $v !== null && $v !== '' ) {
                $batch_pk_values[] = (string) $v;
            }
        }
        $this->resolved_product_ids = \MMI_Pipeline_Field_Resolver::find_product_ids_by_primary_keys(
            $this->primary_key_wc,
            $batch_pk_values
        );

        $batch_start_time = microtime( true );
        $items_consumed   = 0;

        foreach ($batch_items as $index => $item) {
            $this->stats['processed']++;
            $items_consumed++;

            // Throttle check every 10 items
            if ($this->throttle && ($index % 10 === 0)) {
                $process_id = "product_import_{$this->supplier_name}";
                if (!$this->throttle->can_proceed($process_id)) {
                    $this->log("Throttling: waiting for safe server load...");
                    $this->throttle->wait_for_safe_load($process_id);
                }
            }

            // Force PHP's cyclic garbage collector every 500 items to reclaim memory
            // from WC/WP product objects that accumulate during long imports.
            if ( $index > 0 && $index % 500 === 0 ) {
                gc_collect_cycles();
            }

            try {
                $this->process_item($item, $promotions, $index + 1, $total);
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $this->log("ERROR processing item {$index}: " . $e->getMessage(), 'error');

                if ( count( $this->failures ) < self::MAX_FAILURE_DETAILS ) {
                    $item_key = \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $this->primary_key_source );
                    $this->failures[] = [
                        'supplier' => $this->supplier_name,
                        'key'      => ( $item_key !== null && $item_key !== '' ) ? (string) $item_key : '(unknown)',
                        'reason'   => mb_substr( $e->getMessage(), 0, 300 ),
                    ];
                }
            }

            // Time-budget check — stop this call early regardless of $limit so a
            // single batch can never run longer than the caller's wall-clock cap
            // (e.g. the cron runner's hard per-cycle timeout).
            if ( $time_budget_seconds !== null && ( microtime( true ) - $batch_start_time ) >= $time_budget_seconds ) {
                $this->log( "Time budget ({$time_budget_seconds}s) reached after {$items_consumed} item(s) this batch — deferring remainder." );
                break;
            }

            // Memory-budget check — stop this call before PHP's memory_limit kills the
            // process outright. A time budget alone doesn't help when a large supplier's
            // catalog accumulates memory (WC object cache, term/meta caches) faster than
            // the clock runs out — this is what silently OOM'd the xchange batch (4,820
            // items) every hour for 3+ days while skuport (636 items) always completed.
            // Checked every 25 items, not every item, to keep the overhead negligible.
            if ( $memory_budget_bytes !== null && $items_consumed % 25 === 0 && memory_get_usage( true ) >= $memory_budget_bytes ) {
                $this->log( "Memory budget ({$memory_budget_bytes} bytes) reached after {$items_consumed} item(s) this batch — deferring remainder." );
                break;
            }
        }

        if ( $limit === null ) {
            $this->next_offset = $total;
            $this->has_more    = false;
        } else {
            $this->next_offset = $offset + $items_consumed;
            $this->has_more    = $this->next_offset < $total;
        }

        // Mark process as complete
        if ($this->throttle) {
            $this->throttle->complete_process("product_import_{$this->supplier_name}", $this->stats);
        }

        $this->log("Import complete: {$this->stats['created']} created, {$this->stats['updated']} updated, {$this->stats['unchanged']} unchanged, {$this->stats['skipped']} skipped, {$this->stats['errors']} errors, {$this->stats['promo_applied']} promos applied");

        return $this->get_results();
    }
    
    /**
     * Extract items from JSON data based on supplier format
     */
    protected function extract_items($data) {
        // Check for common wrapper keys
        if (isset($data['products']) && is_array($data['products'])) {
            return $data['products'];
        }
        if (isset($data['items']) && is_array($data['items'])) {
            return $data['items'];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        
        // Assume data is array of items
        return is_array($data) ? $data : [];
    }
    
    /**
     * Process single item
     */
    protected function process_item($item, $promotions, $current, $total) {
        // Get primary key value to identify the product
        $primary_value = \MMI_Pipeline_Field_Resolver::get_nested_value($item, $this->primary_key_source);
        
        if (empty($primary_value)) {
            $this->log("Skipping item without primary key ({$this->primary_key_source})", 'warning');
            $this->stats['skipped']++;
            return;
        }

        // Check if product exists by primary key — moved up front, before any
        // field-mapping/promo/stock-override/hash work, so a scope/mode that's
        // guaranteed to skip an existing product (new_only, by_identifier,
        // create-only, duplicate_strategy=skip) does so before doing ANY of
        // that wasted per-item work. This matters most in exactly the steady
        // state a 'New Products' (new_only) profile settles into once its
        // scheduled run has caught up: most feed items already exist, so most
        // calls into this function used to do a full field-mapping pass
        // (including any per-item taxonomy/alias lookups) and a stock-override
        // resolution just to immediately discard the result. The lookup itself
        // is already O(1) against this batch's pre-resolved map — see
        // $resolved_product_ids and find_product_by_primary_key().
        $product_id = $this->find_product_by_primary_key($primary_value);

        if ( $product_id ) {
            // new_only scope: never modify a product that already exists in the
            // store — this scope's entire purpose is to only touch new products.
            // Matches class-product-import-worker.php's identical guard for the
            // manual/AJAX import path.
            if ( $this->product_scope === 'new_only' ) {
                $this->log( "Skipping existing product {$primary_value}: new_only scope never updates existing products ({$current}/{$total})", 'info' );
                $this->stats['skipped']++;
                return;
            }

            if ( $this->product_scope === 'by_identifier' && ! $this->taxonomy_handler->product_matches_identifier( $product_id ) ) {
                $this->log( "Skipping product {$primary_value}: not in scope for this profile (identifier filter)", 'info' );
                $this->stats['skipped']++;
                return;
            }

            // create-only mode: existing products are never touched.
            if ( ( $this->import_rules['import_mode'] ?? '' ) === 'create-only' ) {
                $this->log( "Skipping existing product in create-only mode: {$primary_value} ({$current}/{$total})", 'info' );
                $this->stats['skipped']++;
                return;
            }

            if ($this->import_rules['duplicate_strategy'] === 'skip') {
                $this->log("Skipping existing product: {$primary_value} ({$current}/{$total})", 'info');
                $this->stats['skipped']++;
                return;
            }
        }

        // Backfill image_url/gallery_urls/descript from Xchange's richer web-assets
        // feed when present — no-op when web_assets_index is empty (see run()).
        if ( ! empty( $this->web_assets_index ) ) {
            $item = \MMI_Pipeline_Field_Resolver::enrich_item_with_web_assets( $item, $primary_value, $this->web_assets_index );
        }

        // Map data using field mappings
        $this->mapping_product_id = (int) $product_id;
        $product_data = $this->map_product_data($item);
        $this->mapping_product_id = 0;

        if (!$product_data) {
            $this->stats['skipped']++;
            return;
        }

        // Check for promotional pricing
        $promo_data = $this->find_promotion($primary_value, $promotions);
        if ($promo_data) {
            $product_data = $this->apply_promotion($product_data, $promo_data);
        }

        // Apply stock override (per-product meta or bulk rule) before CRUD.
        // Runs after promo so promotional pricing is preserved regardless.
        // Works for both new ($product_id = 0) and existing products.
        $override_stock_status = null; // captured for post-save force-write (see below)
        if ( $this->stock_override_resolver !== null ) {
            $src_status      = $product_data['stock_status'] ?? ( $product_data['_stock_status'] ?? null );
            $stock_is_mapped = ( $src_status !== null );

            // When the stock field is not mapped for this supplier, override rules must
            // still be able to fire. Use 'instock' as the base so the resolver can
            // evaluate per-product and bulk rules; if nothing matches, $resolved equals
            // the effective base and we skip the write below (no-op for unmapped stock).
            $effective_src = $src_status ?? 'instock';

            $resolved = $this->stock_override_resolver->resolve(
                (int) ( $product_id ?? 0 ),
                $effective_src,
                $item,
                $this->supplier_name
            );
            if ( $resolved !== $effective_src ) {
                $this->log(
                    "Stock override applied: source={$effective_src} → {$resolved}"
                    . " (SKU: {$primary_value})",
                    'info'
                );
                \MMI_Logger::info(
                    "Stock override applied: source={$effective_src} → {$resolved} (SKU: {$primary_value})",
                    [ 'product_id' => $product_id ?? 0, 'sku' => $primary_value, 'source' => $effective_src, 'resolved' => $resolved ],
                    'sync',
                    'MMI_Dynamic_Product_Importer'
                );
            }

            // Write into product_data and schedule force-write when:
            //   a) The stock field IS mapped (normal flow — always persist the result), OR
            //   b) An override rule actually changed the status (unmapped-stock override).
            // When stock is not mapped AND no rule fired, leave the product stock untouched.
            if ( $stock_is_mapped || $resolved !== $effective_src ) {
                $product_data['stock_status']  = $resolved;
                $product_data['_stock_status'] = $resolved;

                // When forced out of stock, zero the quantity to keep WC state consistent.
                // CRITICAL: Must set even if not mapped, else WC recalculates stock_status during save()
                if ( $resolved === 'outofstock' ) {
                    $product_data['stock_quantity'] = 0;
                    $product_data['_stock']         = 0;
                }

                // Always capture so we can force-write after all WC save() calls below.
                $override_stock_status = $resolved;
            }
        }
        
        // Selective-hashing gate (pattern borrowed from WP All Import's
        // wp_pmxi_hash table): hash the fully-resolved mapped data — after field
        // mapping, promo pricing, and stock-override resolution — and compare to
        // the hash stored from the last run. If unchanged, skip BEFORE touching
        // WooCommerce at all. This is deliberately not a per-field "skip setter if
        // unchanged" approach: WC_Product's own dirty-tracking is unreliable for
        // this (e.g. set_date_on_sale_from() always reports a change in
        // get_changes() even when given the exact same timestamp already stored),
        // which is why every prior run was re-saving every single product.
        //
        // The hash is NOT computed from $product_data alone. A hash of the source
        // data only answers "has the feed changed since we last looked" — it has
        // no idea whether the value this importer last wrote is still what's
        // actually stored. Any other writer of a mapped field (a manual admin
        // edit, a different plugin's sync, a bulk tool) that touches the product
        // after this importer's last successful save silently and permanently
        // desyncs it: the feed hasn't moved, so the old hash keeps matching
        // forever, and this importer never notices its own last write was
        // overwritten by something else. Confirmed live: SKU 1035-2238's stored
        // hash exactly matched a source-only hash of its correct, current $549
        // price, yet the product's actual _regular_price had been silently reset
        // to a stale $55 by a process outside this importer — the gate had no way
        // to detect the drift because it never looked at the destination.
        //
        // Folding a cheap raw-postmeta snapshot of the mapped meta-backed fields'
        // CURRENT stored values into the hash closes this: if nothing else has
        // touched those fields since our last confirmed write, the snapshot is
        // identical to what we stored it as, and the fast skip still applies
        // (get_post_meta() reads from WP's already-warm per-post meta cache — this
        // does not reintroduce the wc_get_product() cost the gate exists to
        // avoid). If any of them drifted, the snapshot differs, the hash misses,
        // and this item falls through to the normal update_product() path, which
        // re-derives the truth from a real WC_Product object and corrects it.
        $hash_key       = '_mmi_src_hash_' . $this->profile;
        $confirm_fields = $this->confirmation_meta_keys( $product_data );
        $source_hash    = md5( wp_json_encode( $product_data ) );

        // Same field-type map the real update path below uses for its own
        // values_are_equal() calls — computed once here and reused there so
        // the hash gate's "is this actually still correct" check and the
        // real writer's comparison can never disagree about what a field's
        // type-aware equality means.
        $field_types = array_map( static fn( $config ) => $config['type'] ?? '', $this->field_mappings );

        if ( $product_id ) {
            $pre_snapshot = $this->read_confirmation_snapshot( (int) $product_id, $confirm_fields );
            $new_hash     = md5( $source_hash . '|' . wp_json_encode( $pre_snapshot ) );

            // A stable hash only proves "neither side has moved since the last
            // time we looked" — it says nothing about whether source and
            // destination actually agree right now. A field that starts out
            // mismatched (e.g. newly enabled in this profile's mapping while
            // the destination was already stale) satisfies hash-stability from
            // the very first observation onward, permanently hiding a real,
            // ongoing difference that no run ever corrects — confirmed live:
            // SKU 1009-1005's mapped _stock (100) and stored _stock (9999)
            // never matched, yet 7 scheduled runs in a row reported 0 updates
            // because the hash of "both sides, whatever they are" had already
            // gone stable. Requiring genuine per-field equality — the same
            // values_are_equal() comparison Import Preview already uses —
            // means the gate can only ever skip a product that is actually
            // correct, never one that merely stopped changing while still
            // wrong.
            $destination_confirmed = true;
            foreach ( $confirm_fields as $meta_key ) {
                $mapped_value = $product_data[ $meta_key ] ?? null;
                $stored_value = $pre_snapshot[ $meta_key ] ?? null;
                $field_type   = $field_types[ $meta_key ] ?? '';
                if ( ! \MMI_Pipeline_Field_Resolver::values_are_equal( $mapped_value, $stored_value, $field_type ) ) {
                    $destination_confirmed = false;
                    break;
                }
            }

            if ( $destination_confirmed && get_post_meta( $product_id, $hash_key, true ) === $new_hash ) {
                // Match update_product()'s own convention: a no-op result increments
                // only 'unchanged', not 'skipped' — the caller sums both into one
                // "skipped" total, so incrementing both here would double-count.
                $this->stats['unchanged'] = ( $this->stats['unchanged'] ?? 0 ) + 1;
                $this->log( "Unchanged (source + destination hash match): {$primary_value} ({$current}/{$total})", 'info' );
                return;
            }
        }

        if ($product_id) {
            // Every scope/mode combination that would skip an existing product
            // (new_only, by_identifier, create-only, duplicate_strategy=skip) was
            // already checked and returned on above, before the hash gate — see
            // that block's own comment. Reaching here means this existing
            // product is genuinely eligible to be updated.

            // Pass field types through so boolean/datetime fields compare
            // correctly instead of by raw string identity — see
            // MMI_Pipeline_Field_Resolver::values_are_equal()'s docs (a
            // profile mapping _virtual's constant '1' against WooCommerce's
            // stored 'yes' otherwise never matches, so every such product
            // gets needlessly re-saved on every scheduled run).
            $update_result = $this->product_crud->update_product($product_id, $this->resolve_crud_taxonomy_terms($item, $product_data), $field_types);
            $this->stats['stock_updated'] += $update_result['stock_updated_count'];
            if ($update_result['status'] !== 'unchanged') {
                $this->stats['updated']++;
                if ( $this->import_rules['price_update'] && ( isset( $product_data['regular_price'] ) || isset( $product_data['_regular_price'] ) ) ) {
                    $this->stats['pricing_updated']++;
                }
                $this->log("Updated product: {$primary_value} ({$current}/{$total})");
            } else {
                $this->log("Unchanged product: {$primary_value} ({$current}/{$total})", 'info');
            }
        } else {
            // New product — use allow_create from import_rules (set by load_configuration)
            if ( ! $this->import_rules['allow_create_products'] ) {
                $this->log("Skipping new product (creation disabled): {$primary_value} ({$current}/{$total})", 'info');
                $this->stats['skipped']++;
                return;
            }

            // SKU conflict the user has confirmed (via the Review screen's "Skip"
            // action) is a genuine duplicate of an existing product — never retry
            // creating it. Re-checks wc_get_product_id_by_sku() live rather than
            // trusting the dismissal blindly, so a since-deleted/renamed conflicting
            // product doesn't permanently block this SKU forever.
            $conflict_sku = (string) ( $product_data['_sku'] ?? '' );
            if ( $conflict_sku !== '' && function_exists( 'wc_get_product_id_by_sku' ) ) {
                $dismissed_conflicts = \MMI_Import_Preview::get_dismissed_sku_conflicts( $this->profile );
                if ( in_array( $conflict_sku, $dismissed_conflicts, true ) && wc_get_product_id_by_sku( $conflict_sku ) ) {
                    $this->log("Skipping new product: SKU '{$conflict_sku}' conflict confirmed as duplicate, dismissed from import ({$current}/{$total})", 'info');
                    $this->stats['skipped']++;
                    return;
                }
            }

            // A new product needs a name — this only applies to creation. Profiles that
            // don't map post_title/name (e.g. a price-only profile) can still update
            // existing products via the branch above; they just can't create new ones.
            if ( empty( $product_data['post_title'] ) && empty( $product_data['name'] ) ) {
                $this->log("Skipping new product without name/title: {$primary_value} ({$current}/{$total})", 'warning');
                $this->stats['skipped']++;
                return;
            }

            // Create new product
            $create_result = $this->product_crud->create_product($this->resolve_crud_taxonomy_terms($item, $product_data));
            $product_id    = $create_result['product_id'];
            $this->stats['stock_updated'] += $create_result['stock_updated_count'];
            if ($product_id) {
                // Tag the new product with this supplier's primary-key value —
                // Product_CRUD_Manager::create_product() has no awareness of
                // this concept at all, and this simple-product branch was the
                // ONLY create path in this class that never called it (the
                // variable-product/group path already does, right after ITS
                // own $product->save() — see apply_primary_key_to_product()'s
                // one other call site). Without it, find_product_ids_by_primary_keys()
                // can never match this exact product on any FUTURE run — every
                // later run (scheduled or manual) sees "no existing product for
                // this key" and re-attempts a create, which WooCommerce then
                // correctly rejects as "Invalid or duplicated SKU" once the SKU
                // already exists. Confirmed live: 12 real products created by a
                // scheduled run of the "New Products" profile were missing this
                // exact meta, and a subsequent manual "Run Import Now" for the
                // same profile failed all 12 with that exact error.
                $this->apply_primary_key_to_product( (int) $product_id, (string) $primary_value );

                // Auto-apply identifier tag to newly created product
                $this->taxonomy_handler->apply_identifier_to_product( $product_id );
                $this->stats['created']++;
                if ( $this->import_rules['price_update'] && ( isset( $product_data['regular_price'] ) || isset( $product_data['_regular_price'] ) ) ) {
                    $this->stats['pricing_updated']++;
                }
                $this->log("Created product: {$primary_value} ({$current}/{$total})");
            }
        }

        // Apply taxonomy mappings (brand → product_brand, etc.) and store raw
        // source values as _mmi_src_{field} meta for later batch-apply.
        if ( ! empty( $product_id ) ) {
            $this->taxonomy_handler->apply_taxonomy_mappings( (int) $product_id, $product_data );

            // Record this run's source+destination hash so the next run can skip
            // this product outright if neither has moved (see hash gate above).
            // Deliberately re-read the confirmation snapshot NOW, after whatever
            // save just happened above, rather than reusing $pre_snapshot — the
            // stored hash must reflect what's actually persisted, not what this
            // run merely intended to write. If a save silently failed to persist
            // a field for any reason, this re-read reflects that failure too,
            // so the next run's comparison correctly sees a mismatch and retries
            // instead of trusting an assumption that turned out to be false.
            $post_snapshot = $this->read_confirmation_snapshot( (int) $product_id, $confirm_fields );
            $confirmed_hash = md5( $source_hash . '|' . wp_json_encode( $post_snapshot ) );
            update_post_meta( (int) $product_id, $hash_key, $confirmed_hash );
        }

        // Apply product attributes configured in the Attributes panel.
        // Runs for simple products only; variable products are handled by run_variable_flat/nested.
        if ( ! empty( $this->attribute_config['enabled'] )
             && ( $this->attribute_config['product_type'] ?? 'simple' ) === 'simple'
             && ! empty( $product_id ) ) {
            $product = wc_get_product( (int) $product_id );
            if ( $product ) {
                $changed = $this->variable_product_manager->apply_product_attributes( $product, $item );
                if ( $changed ) {
                    $product->save();
                }
            }
        }

        // Force the resolved stock status directly to postmeta after all WC save() calls.
        // WC's validate_props() recalculates _stock_status from stock_quantity for managed-
        // stock products, overwriting any override set via set_stock_status(). Writing
        // directly bypasses that recalculation and matches the Apply Now behaviour.
        if ( $override_stock_status !== null && ! empty( $product_id ) ) {
            \MannMade\DataPipeline\Stock_Override_Resolver::force_stock_status(
                (int) $product_id,
                $override_stock_status
            );
        }
    }
    
    /**
     * Map product data using field mappings from database
     */
    /**
     * The mapped data Product_CRUD_Manager writes, with product_cat and
     * product_tag already resolved to term IDs: Taxonomy Mapping first, then
     * the field's "When a value isn't in Taxonomy Mapping" setting
     * (MMI_Pipeline_Field_Resolver::resolve_unmapped_term_ids()). A value
     * that resolves to nothing is dropped, so an existing product keeps its
     * terms and a new one gets WooCommerce's default category.
     *
     * Before 2.49.0 the CRUD manager turned the raw value into terms itself
     * (map_categories(), creating any it could not find) and
     * apply_taxonomy_mappings() replaced them with the mapped term after
     * save, leaving an empty "Software / 3D Audio"-style category behind for
     * every value.
     *
     * Returns a copy: $product_data itself still carries the raw value, which
     * the hash gate and apply_taxonomy_mappings() (stores it as _mmi_src_*,
     * so a later mapping save can reach the product) both need.
     */
    protected function resolve_crud_taxonomy_terms( array $item, array $product_data ): array {
        foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
            if ( ! array_key_exists( $taxonomy, $product_data ) ) {
                continue;
            }
            $mapping = is_array( $this->field_mappings[ $taxonomy ] ?? null ) ? $this->field_mappings[ $taxonomy ] : [];
            $skipped = false;
            $term_ids = \MMI_Pipeline_Field_Resolver::resolve_taxonomy_via_alias_table( $this->supplier_name, $item, $taxonomy, $this->profile, $skipped );
            if ( empty( $term_ids ) ) {
                $is_constant = \MMI_Pipeline_Field_Mapping_Defaults::resolve_constant( $mapping, $this->supplier_name ) !== null;
                $term_ids    = \MMI_Pipeline_Field_Resolver::resolve_unmapped_term_ids( $taxonomy, $mapping, $product_data[ $taxonomy ], $is_constant, $skipped );
            }
            if ( empty( $term_ids ) ) {
                unset( $product_data[ $taxonomy ] );
                continue;
            }
            $product_data[ $taxonomy ] = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
        }
        return $product_data;
    }

    protected function map_product_data($item) {
        $product_data = [];
        
        foreach ($this->field_mappings as $wc_field => $config) {
            // Skip if not enabled for this supplier
            if (is_array($config['enabled'] ?? false)) {
                if (empty($config['enabled'][$this->supplier_name])) {
                    continue;
                }
            } elseif (empty($config['enabled'])) {
                continue;
            }
            
            // Get source field for this supplier
            $source_field = '';
            if (is_array($config['source'] ?? '')) {
                $source_field = $config['source'][$this->supplier_name] ?? '';
            } else {
                $source_field = $config['source'] ?? '';
            }
            
            // Handle custom source field
            if ($source_field === 'custom' && !empty($config['source_custom'])) {
                $source_field = $config['source_custom'];
            }
            
            // Treat the literal string "NULL" / "null" as unconfigured — this can
            // be stored when the UI saves an unset source select without a value.
            if ($source_field === 'NULL' || $source_field === 'null') {
                $source_field = '';
            }
            
            // Get value
            $value = null;
            $dynamic_constant_value = \MMI_Pipeline_Field_Mapping_Defaults::resolve_constant($config, $this->supplier_name);
            if ($dynamic_constant_value !== null) {
                // Use constant value
                $value = $dynamic_constant_value;
            } else {
                // Extract from source data
                $value = \MMI_Pipeline_Field_Resolver::get_nested_value($item, $source_field);

                // Apply transform (with optional params)
                $transform_params = is_array( $config['transform_params'] ?? null )
                    ? $config['transform_params']
                    : [];
                $value = \MMI_Pipeline_Field_Resolver::apply_transform(
                    $value,
                    $config['transform'] ?? 'none',
                    $transform_params,
                    $item
                );

                // Evaluate conditions / fallback
                $conditions = is_array( $config['conditions'] ?? null ) ? $config['conditions'] : [];
                if ( ! empty( $conditions ) ) {
                    $use_fallback  = ! empty( $config['condition_fallback_enabled'] );
                    $fallback_val  = $config['condition_fallback_value'] ?? '';
                    $cond_result   = Condition_Evaluator::evaluate_conditions(
                        $value, $conditions, (string) $fallback_val, $use_fallback,
                        (array) $item, (int) $this->mapping_product_id, (string) ( $config['condition_match_logic'] ?? 'all' )
                    );
                    if ( ! $cond_result['pass'] && ! $use_fallback ) {
                        // Conditions failed and no fallback — skip this field entirely.
                        continue;
                    }
                    $value = $cond_result['value'];
                }

                // Apply default if empty
                if (empty($value) && isset($config['default_value'])) {
                    $value = $config['default_value'];
                }
            }
            
            // Store mapped value — resolver overrides the mapping key with a runtime meta key
            $resolved_field = $wc_field;
            if ( ! empty( $config['meta_key_resolver'] ) && is_callable( $config['meta_key_resolver'] ) ) {
                $resolved_field = call_user_func( $config['meta_key_resolver'] );
            }
            $product_data[$resolved_field] = $value;
        }

        // Media/Images: _product_image_url/_product_gallery_urls have no WC
        // CRUD prop of their own (see Product_CRUD_Manager::
        // apply_fields_to_product()'s NOOP_FIELDS) — the only thing that
        // actually sets the featured image + gallery is Product_CRUD_Manager
        // ::set_product_images(), which reads a single combined 'images'
        // list, not these two field-mapping keys directly. Assemble it here,
        // once, from whichever of the two this profile has mapped —
        // supports both a feed with separate main/gallery fields and one
        // (like Xchange's Web Asset API) that bundles every image into a
        // single JSON array via the per-supplier 'image_array_mode' toggle.
        if ( array_key_exists( '_product_image_url', $product_data ) || array_key_exists( '_product_gallery_urls', $product_data ) ) {
            $image_config = $this->field_mappings['_product_image_url'] ?? [];
            $array_mode_config = $image_config['image_array_mode'] ?? false;
            $image_array_mode = is_array( $array_mode_config )
                ? ! empty( $array_mode_config[ $this->supplier_name ] )
                : (bool) $array_mode_config;

            $images = \MMI_Pipeline_Field_Resolver::assemble_product_images(
                $product_data['_product_image_url'] ?? null,
                $product_data['_product_gallery_urls'] ?? null,
                $image_array_mode
            );

            unset( $product_data['_product_image_url'], $product_data['_product_gallery_urls'] );
            if ( ! empty( $images ) ) {
                $product_data['images'] = $images;
            }
        }

        // NOTE: a missing post_title/name is NOT treated as invalid here. Profiles that
        // only map a subset of fields (e.g. "pricing", which intentionally leaves
        // post_title unmapped) must still be able to update existing products by their
        // mapped fields alone. The name requirement only matters when actually creating
        // a brand-new product — see the allow_create_products branch in process_item(),
        // which is the right place to enforce it.

        return $product_data;
    }

    /**
     * Meta-backed field aliases that don't share their own name with the real
     * postmeta key WooCommerce stores them under — everything else is either
     * already the literal meta key (any '_'-prefixed key in $product_data) or
     * isn't cheaply confirmable via a raw postmeta read at all (post_title,
     * description, categories/tags — array- or column-backed, not a single
     * meta value) and is deliberately left out of the confirmation snapshot.
     */
    private const CONFIRMATION_META_ALIASES = [
        'regular_price'  => '_regular_price',
        'sale_price'     => '_sale_price',
        'price'          => '_price',
        'stock_quantity' => '_stock',
        'stock_status'   => '_stock_status',
        'sku'            => '_sku',
    ];

    /**
     * Resolve which real postmeta keys are worth confirming for one item's
     * mapped $product_data — i.e. every field this run is about to write that
     * has a single, cheaply-readable postmeta key backing it.
     *
     * @param array $product_data
     * @return string[] Real postmeta keys, deduplicated.
     */
    private function confirmation_meta_keys( array $product_data ): array {
        $keys = [];
        foreach ( array_keys( $product_data ) as $field ) {
            if ( ! is_string( $field ) || $field === '' ) {
                continue;
            }
            $meta_key = self::CONFIRMATION_META_ALIASES[ $field ] ?? ( $field[0] === '_' ? $field : null );
            if ( $meta_key !== null ) {
                $keys[ $meta_key ] = true;
            }
        }
        return array_keys( $keys );
    }

    /**
     * Cheap raw-postmeta snapshot of a product's CURRENT stored values for the
     * given meta keys — deliberately get_post_meta() reads, not a wc_get_product()
     * hydration, so this stays cheap enough to run on every item the hash gate
     * considers (see process_item()'s hash-gate comment for why this exists:
     * a source-only hash can't detect another process silently overwriting a
     * field this importer already correctly set).
     *
     * @param int      $product_id
     * @param string[] $meta_keys
     * @return array<string, mixed>
     */
    private function read_confirmation_snapshot( int $product_id, array $meta_keys ): array {
        $snapshot = [];
        foreach ( $meta_keys as $meta_key ) {
            $snapshot[ $meta_key ] = get_post_meta( $product_id, $meta_key, true );
        }
        return $snapshot;
    }

    /**
     * Find promotion for a product — O(1) lookup against the index built once
     * in run() via MMI_Pipeline_Field_Resolver::load_and_index_promotions().
     * The $promotions param is accepted for call-site compatibility with the
     * variable-product runners but is no longer consulted directly.
     */
    protected function find_promotion($primary_value, $promotions) {
        if ($primary_value === null || $primary_value === '') {
            return null;
        }
        return $this->promotions_index[(string) $primary_value] ?? null;
    }
    
    /**
     * Resolve the key to read off a matched promo record for a given product_data
     * field, using that field's own configured mapping source for this supplier.
     *
     * The field mapping source is written for the "promotions.xxx" nesting that
     * would exist if the promo were embedded in the main item (it isn't — promos
     * live in a separate file matched by SKU), so a leading "promotions." prefix
     * is stripped to get the real key on the promo record itself. Falls back to
     * null when the field has no mapping (caller decides the legacy fallback).
     */
    protected function get_promo_source_key($wc_field) {
        $config = $this->field_mappings[$wc_field]['source'] ?? null;
        $key     = is_array($config) ? ($config[$this->supplier_name] ?? null) : $config;
        return $key ? preg_replace('/^promotions\./', '', $key) : null;
    }

    /**
     * Apply promotional pricing to product data
     */
    protected function apply_promotion($product_data, $promo_data) {
        // Use the same source key already configured for _sale_price (e.g. xchange's
        // "street_price" vs skuport's "promoPrice" — these are NOT interchangeable:
        // xchange's "promo_price" is the dealer's net/wholesale cost, not a customer
        // sale price). Only fall back to the old hardcoded guesses if this profile
        // has no _sale_price mapping configured at all.
        $price_key   = $this->get_promo_source_key('_sale_price');
        $promo_price = $price_key !== null && isset( $promo_data[ $price_key ] )
            ? $promo_data[ $price_key ]
            : ( $promo_data['price'] ?? $promo_data['sale_price'] ?? $promo_data['promo_price'] ?? null );

        if ($promo_price !== null) {
            $regular_price = $product_data['_regular_price'] ?? $product_data['regular_price'] ?? 0;

            // Correct a self-discounting base feed (e.g. SkuPort's Products
            // feed reports "map" as the CURRENTLY ACTIVE, already-discounted
            // price while a promo runs — confirmed live 124/124 active promos)
            // before comparing against $promo_price, or "promo < regular" can
            // never be true and the promotion silently never applies. See
            // MMI_Pipeline_Field_Resolver::resolve_true_regular_price()'s docs.
            $true_regular = \MMI_Pipeline_Field_Resolver::resolve_true_regular_price( $regular_price, $promo_data );
            if ( $true_regular !== null ) {
                $regular_price = $true_regular;
                $product_data['_regular_price'] = $true_regular;
                $product_data['regular_price']  = $true_regular;
            }

            // Only apply if promo price is lower than regular
            if (floatval($promo_price) < floatval($regular_price) && floatval($promo_price) > 0) {
                $product_data['_sale_price'] = floatval($promo_price);

                $from_key = $this->get_promo_source_key('_sale_price_dates_from');
                $to_key   = $this->get_promo_source_key('_sale_price_dates_to');
                $start    = ( $from_key !== null ? ( $promo_data[ $from_key ] ?? null ) : null ) ?? $promo_data['start_date'] ?? null;
                $end      = ( $to_key   !== null ? ( $promo_data[ $to_key ]   ?? null ) : null ) ?? $promo_data['end_date']   ?? null;

                // Set sale date range if provided
                if (!empty($start)) {
                    $from_ts = strtotime($start);
                    if ( $from_ts !== false ) {
                        $product_data['_sale_price_dates_from'] = $from_ts;
                    }
                }
                if (!empty($end)) {
                    $to_ts = strtotime($end);
                    if ( $to_ts !== false ) {
                        $product_data['_sale_price_dates_to'] = $to_ts;
                    }
                }
                
                $this->stats['promo_applied']++;
                $this->log("Applied promotion: Regular {$regular_price} → Sale {$promo_price}", 'info');
            }
        }
        
        return $product_data;
    }
    
    /**
     * Find product by primary key. Uses the current batch's pre-resolved map
     * ($resolved_product_ids, populated once per run() call for the standard
     * per-item loop — see that call site) when available; falls back to the
     * original one-query-per-item lookup otherwise (the variable-product
     * paths below never populate the map, so they're unaffected).
     */
    protected function find_product_by_primary_key($value) {
        if ($this->resolved_product_ids !== null) {
            $key = ($value !== null && $value !== '') ? (string) $value : '';
            return (int) ($this->resolved_product_ids[$key] ?? 0);
        }
        return \MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key($this->primary_key_wc, $value);
    }
    
    /**
     * Identifier/scope helpers, taxonomy mapping, product CRUD (create/update/
     * apply_fields_to_product), category/image handling, condition evaluation,
     * and attribute-taxonomy mechanics now live in dedicated collaborators
     * (instantiated in load_configuration()):
     *   - Taxonomy_Mapping_Handler  ($this->taxonomy_handler)
     *   - Product_CRUD_Manager      ($this->product_crud)
     *   - Condition_Evaluator       (static, no instance)
     *   - Variable_Product_Manager  ($this->variable_product_manager)
     * run_variable_flat()/run_variable_nested()/process_variable_group()/
     * upsert_variation() stayed here — they reach into nearly every piece of
     * this class's state and extracting them would mean threading several
     * callbacks through a constructor rather than real decoupling.
     */


    /**
     * Variable product runner — Flat mode.
     *
     * Groups all items by the configured `parent_group_field` value, then
     * processes each group as one WC_Product_Variable with child variations.
     *
     * @param array $items      All extracted items from the JSON file.
     * @param array $promotions Loaded promotions array.
     * @return array            Import results.
     */
    protected function run_variable_flat( array $items, array $promotions ): array {
        $group_field = $this->attribute_config['parent_group_field'] ?? '';

        if ( empty( $group_field ) ) {
            $this->log( 'Variable flat mode: parent_group_field not configured. Falling back to simple import.', 'warning' );
            foreach ( $items as $index => $item ) {
                $this->stats['processed']++;
                try {
                    $this->process_item( $item, $promotions, $index + 1, count( $items ) );
                } catch ( \Exception $e ) {
                    $this->stats['errors']++;
                    $this->log( 'ERROR: ' . $e->getMessage(), 'error' );
                }
            }
            return $this->get_results();
        }

        // Group items by parent key value
        $groups = [];
        foreach ( $items as $item ) {
            $key = (string) \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $group_field );
            if ( $key === '' ) {
                continue;
            }
            $groups[ $key ][] = $item;
        }

        $total = count( $groups );
        $this->log( "Variable flat mode: {$total} product groups (grouped by '{$group_field}')" );

        $index = 0;
        foreach ( $groups as $parent_key => $group_items ) {
            $this->stats['processed']++;
            try {
                $this->process_variable_group( $parent_key, $group_items, $promotions, ++$index, $total );
            } catch ( \Exception $e ) {
                $this->stats['errors']++;
                $this->log( "ERROR processing group '{$parent_key}': " . $e->getMessage(), 'error' );
            }
        }

        return $this->get_results();
    }

    /**
     * Variable product runner — Nested mode.
     *
     * Each item is a parent product that contains a nested array of variation
     * objects at the path configured in `variants_path`.
     *
     * @param array $items      All extracted items.
     * @param array $promotions Loaded promotions.
     * @return array
     */
    protected function run_variable_nested( array $items, array $promotions ): array {
        $variants_path = $this->attribute_config['variants_path'] ?? 'variants';
        $total         = count( $items );
        $this->log( "Variable nested mode: processing {$total} parent items (variants at '{$variants_path}')" );

        foreach ( $items as $index => $item ) {
            $this->stats['processed']++;
            try {
                $variants = \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $variants_path );
                if ( ! is_array( $variants ) ) {
                    // No nested variants — treat as simple product
                    $this->process_item( $item, $promotions, $index + 1, $total );
                    continue;
                }
                $primary_value = (string) \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $this->primary_key_source );
                $this->process_variable_group( $primary_value, $variants, $promotions, $index + 1, $total, $item );
            } catch ( \Exception $e ) {
                $this->stats['errors']++;
                $this->log( 'ERROR: ' . $e->getMessage(), 'error' );
            }
        }

        return $this->get_results();
    }

    /**
     * Process one group of variation items into a WooCommerce variable product.
     *
     * @param string     $parent_key   The value that identifies this product group.
     * @param array      $group_items  Variation items (each becomes one WC_Product_Variation).
     * @param array      $promotions   Promotions array.
     * @param int        $current      1-based index for log messages.
     * @param int        $total        Total group count.
     * @param array|null $parent_item  Explicit parent item (nested mode). If null,
     *                                 the first group item is used for parent fields.
     */
    protected function process_variable_group(
        string $parent_key,
        array  $group_items,
        array  $promotions,
        int    $current,
        int    $total,
        ?array $parent_item = null
    ): void {
        $base_item   = $parent_item ?? $group_items[0];
        $parent_data = $this->map_product_data( $base_item );

        if ( ! $parent_data ) {
            $this->stats['skipped']++;
            return;
        }

        // Find existing variable product
        $product_id = $this->find_product_by_primary_key( $parent_key );

        if ( $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $this->stats['skipped']++;
                return;
            }
            // If currently a simple product, don't convert automatically
            if ( $product->get_type() !== 'variable' ) {
                $this->log( "Skipping group '{$parent_key}': product exists as '{$product->get_type()}' (not variable)", 'warning' );
                $this->stats['skipped']++;
                return;
            }
        } else {
            if ( ! $this->import_rules['allow_create_products'] ) {
                $this->log( "Skipping new variable product (creation disabled): {$parent_key} ({$current}/{$total})", 'info' );
                $this->stats['skipped']++;
                return;
            }
            $product = new \WC_Product_Variable();
        }

        // Apply standard fields to the parent
        $this->product_crud->apply_fields_to_product( $product, $this->resolve_crud_taxonomy_terms( $base_item, $parent_data ) );

        // Collect all unique variation attribute values across the group
        $var_attr_defs = array_filter(
            $this->attribute_config['attributes'] ?? [],
            fn( $d ) => ! empty( $d['for_variations'] ) && ! empty( $d['wc_slug'] ) && ! empty( $d['source_field'] )
        );

        $collected_values = []; // slug => [value, ...]
        foreach ( $var_attr_defs as $def ) {
            $slug   = $def['wc_slug'];
            $sfld   = $def['source_field'];
            $values = [];
            foreach ( $group_items as $vi ) {
                $v = (string) \MMI_Pipeline_Field_Resolver::get_nested_value( $vi, $sfld );
                if ( $v !== '' ) {
                    $values[] = $v;
                }
            }
            $collected_values[ $slug ] = array_values( array_unique( $values ) );
        }

        // Ensure attribute taxonomies exist and set on parent
        $parent_attrs = $product->get_attributes();
        foreach ( $var_attr_defs as $def ) {
            $slug   = $def['wc_slug'];
            $label  = $def['label'] ?? ucwords( str_replace( '_', ' ', ltrim( $slug, 'pa_' ) ) );
            $this->variable_product_manager->ensure_wc_global_attribute( $slug, $label );

            // Auto-detects ID vs. slug vs. name per value — see
            // MMI_Pipeline_Field_Resolver::resolve_term_ids_smart(). Previously
            // this only ever matched by exact name, creating a new term on
            // any miss (including a value that was actually meant as an ID
            // or slug from a re-imported export).
            $term_ids = \MMI_Pipeline_Field_Resolver::resolve_term_ids_smart( $slug, $collected_values[ $slug ] );

            $attr = new \WC_Product_Attribute();
            $attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
            $attr->set_name( $slug );
            $attr->set_options( $term_ids );
            $attr->set_position( count( $parent_attrs ) );
            $attr->set_visible( isset( $def['visible'] ) ? (bool) $def['visible'] : true );
            $attr->set_variation( true );
            $parent_attrs[ $slug ] = $attr;
        }
        $product->set_attributes( $parent_attrs );
        $is_new_product = ! $product->get_id();
        $product_id = $product->save();
        update_post_meta( (int) $product_id, '_mmi_pipeline_updated_at', current_time( 'mysql' ) );

        // Store primary key on new product
        $this->apply_primary_key_to_product( (int) $product_id, $parent_key );
        $this->taxonomy_handler->apply_identifier_to_product( (int) $product_id );
        $this->taxonomy_handler->apply_taxonomy_mappings( (int) $product_id, $parent_data );

        // Create/update individual variations
        $this->log( "Processing " . count( $group_items ) . " variations for group '{$parent_key}' ({$current}/{$total})" );
        foreach ( $group_items as $vi ) {
            $this->upsert_variation( (int) $product_id, $vi, $var_attr_defs, $promotions );
        }

        // Sync variation prices to parent
        \WC_Product_Variable::sync( $product_id );

        $this->stats[ $is_new_product ? 'created' : 'updated' ]++;
        $this->log( "Variable product '{$parent_key}' saved (ID: {$product_id}) ({$current}/{$total})" );
    }

    /**
     * Create or update one WC_Product_Variation for a single variation item.
     *
     * @param int   $parent_id    Parent variable product ID.
     * @param array $item         Raw variation item.
     * @param array $attr_defs    Variation attribute definitions from attribute_config.
     * @param array $promotions   Loaded promotions.
     */
    protected function upsert_variation( int $parent_id, array $item, array $attr_defs, array $promotions ): void {
        // Build attribute combination for this variation: [slug => term_slug]
        $attr_combo = [];
        foreach ( $attr_defs as $def ) {
            $slug  = $def['wc_slug'];
            $sfld  = $def['source_field'];
            $val   = (string) \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $sfld );
            if ( $val !== '' ) {
                // Resolve to term slug — auto-detects ID vs. slug vs. name
                // (see MMI_Pipeline_Field_Resolver::resolve_term_ids_smart()).
                // create_missing=false: this is matching against an existing
                // variation combination, not assigning new terms — the
                // parent-attribute step above already created any term this
                // value could legitimately resolve to.
                $resolved_ids = \MMI_Pipeline_Field_Resolver::resolve_term_ids_smart( $slug, $val, false );
                $term         = $resolved_ids ? get_term( $resolved_ids[0], $slug ) : null;
                $attr_combo[ $slug ] = ( $term && ! is_wp_error( $term ) ) ? $term->slug : sanitize_title( $val );
            }
        }

        // Try to find an existing variation with this attribute combination
        $variation_id = 0;
        $var_sku_field  = $this->attribute_config['variation_sku_field']  ?? '';
        $var_price_key  = $this->attribute_config['variation_price_key']  ?? '';
        $var_stock_key  = $this->attribute_config['variation_stock_key']  ?? '';

        $var_sku = $var_sku_field ? (string) \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $var_sku_field ) : '';

        if ( $var_sku ) {
            $existing_id = wc_get_product_id_by_sku( $var_sku );
            if ( $existing_id ) {
                $candidate = wc_get_product( $existing_id );
                if ( $candidate && $candidate->get_parent_id() === $parent_id ) {
                    $variation_id = $existing_id;
                }
            }
        }

        if ( $variation_id ) {
            $variation = wc_get_product( $variation_id );
        } else {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id( $parent_id );
        }

        $variation->set_attributes( $attr_combo );

        // SKU
        if ( $var_sku ) {
            $variation->set_sku( $var_sku );
        }

        // Price — use variation-specific field if configured, else mapped parent data
        if ( $var_price_key ) {
            $price = \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $var_price_key );
        } else {
            $mapped = $this->map_product_data( $item );
            $price  = $mapped ? ( $mapped['_regular_price'] ?? ( $mapped['regular_price'] ?? null ) ) : null;
        }
        if ( $price !== null && $price !== '' ) {
            $variation->set_regular_price( (string) $price );
        }

        // Stock status
        $stock_status = null;
        if ( $var_stock_key ) {
            $stock_status = (string) \MMI_Pipeline_Field_Resolver::get_nested_value( $item, $var_stock_key );
        } else {
            $mapped_for_stock = $this->map_product_data( $item );
            $stock_status = $mapped_for_stock ? ( $mapped_for_stock['_stock_status'] ?? ( $mapped_for_stock['stock_status'] ?? null ) ) : null;
        }
        if ( $stock_status !== null && $stock_status !== '' ) {
            // Apply stock override rules to variations too
            if ( $this->stock_override_resolver !== null ) {
                $resolved_status = $this->stock_override_resolver->resolve(
                    (int) $variation->get_id(),
                    $stock_status,
                    $item,
                    $this->supplier_name
                );
                $stock_status = $resolved_status;
                // When forced out of stock, zero the quantity to keep WC state consistent
                if ( $resolved_status === 'outofstock' ) {
                    $variation->set_stock_quantity( 0 );
                }
            }
            $variation->set_stock_status( $stock_status );
        }

        // Promotions
        if ( $var_sku ) {
            $promo = $this->find_promotion( $var_sku, $promotions );
            if ( $promo && $price !== null ) {
                $promo_price = $promo['sale_price'] ?? $promo['price'] ?? $promo['promo_price'] ?? null;
                if ( $promo_price !== null ) {
                    $variation->set_sale_price( (string) $promo_price );
                }
            }
        }

        $variation->save();
    }

    /**
     * Store the primary key on a product (meta or taxonomy, depending on the
     * configured WC primary key target).
     *
     * This is a no-op when the product already has the right primary key stored;
     * it is safe to call on both new and existing products.
     *
     * @param int    $product_id
     * @param string $primary_value
     */
    protected function apply_primary_key_to_product( int $product_id, string $primary_value ): void {
        if ( ! $product_id || $primary_value === '' ) {
            return;
        }
        $wc_key = $this->primary_key_wc;
        if ( str_starts_with( $wc_key, '_' ) ) {
            update_post_meta( $product_id, $wc_key, $primary_value );
        } else {
            // Non-underscore key — store as SKU since taxonomy assignment
            // requires a term to exist; use _sku as the universal fallback.
            $existing = get_post_meta( $product_id, $wc_key, true );
            if ( empty( $existing ) ) {
                update_post_meta( $product_id, $wc_key, $primary_value );
            }
        }
    }

    /**
     * Log message
     */
    protected function log($message, $type = 'info') {
        $this->log_entries[] = [
            'type' => $type,
            'message' => $message,
            'time' => current_time('mysql')
        ];
    }
    
    /**
     * Get results
     */
    public function get_results() {
        return [
            'success' => true,
            'supplier' => $this->supplier_name,
            'profile' => $this->profile,
            'stats' => $this->stats,
            'failures' => $this->failures,
            'log' => $this->log_entries,
            'message' => "Import completed: {$this->stats['created']} created, {$this->stats['updated']} updated, {$this->stats['unchanged']} unchanged",
            'total_in_feed' => $this->total_in_feed,
            'next_offset'   => $this->next_offset,
            'has_more'      => $this->has_more,
        ];
    }
}
