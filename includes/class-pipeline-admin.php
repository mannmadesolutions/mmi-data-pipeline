<?php
/**
 * Pipeline Admin — menu registration and asset loading
 *
 * Registers a submenu page under the MannMade top-level menu (provided by
 * mmi-hub) and enqueues all CSS/JS required by the import pipeline UI.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Admin {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 20 );
        add_action( 'add_meta_boxes',    [ __CLASS__, 'register_stock_override_metabox' ] );
        add_action( 'save_post_product', [ __CLASS__, 'save_stock_override_metabox' ], 10, 1 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_stock_override_metabox_style' ] );
        add_filter( 'mmi_primary_key_source_default', [ __CLASS__, 'known_primary_key_source_default' ], 10, 2 );
    }

    /**
     * Answers MMI_DB::get_primary_key()'s 'mmi_primary_key_source_default'
     * filter for every hard-wired supplier this plugin integrates. Reuses
     * MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS['_sku']['source'] — the
     * single existing source of truth for "which feed field holds this
     * supplier's SKU" — rather than a second, parallel map that could drift
     * out of sync with it. A supplier with no '_sku' DEFAULTS entry (a
     * genuinely custom/uploaded source) falls through to $default unchanged.
     *
     * @param  string $default     The value MMI_DB::get_primary_key() would
     *                             otherwise fall back to.
     * @param  string $supplier_id Supplier ID.
     * @return string
     */
    public static function known_primary_key_source_default( string $default, string $supplier_id ): string {
        return MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS['_sku']['source'][ $supplier_id ] ?? $default;
    }

    /**
     * The stock-override metabox renders on the core WooCommerce product edit
     * screen, not this plugin's own admin page — enqueue_assets() (and the
     * mmi-suite-common dependency chain it pulls in) never runs there, so a
     * shared utility class like .mmi-w-full would silently not apply. A
     * scoped wp_add_inline_style() on a core handle keeps this one rule in
     * real CSS without a new stylesheet file or an inline style="" attribute.
     */
    public static function enqueue_stock_override_metabox_style( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'product' ) {
            return;
        }
        wp_add_inline_style( 'common', '#mmi_stock_override{width:100%}' );
    }

    /* ── Menu ─────────────────────────────────────────────────────────────── */

    public static function register_menu(): void {
        // Submenu under the shared 'mmi-dashboard' ("MannMade") parent menu
        // (see mmi-shared/bootstrap.php).
        add_submenu_page(
            'mmi-dashboard',
            __( 'Data Pipeline', 'mmi-data-pipeline' ),
            __( 'Data Pipeline', 'mmi-data-pipeline' ),
            mmi_data_pipeline_required_capability( 'manage_options' ),
            'mmi-data-pipeline',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ── Page render ──────────────────────────────────────────────────────── */

    public static function render_page(): void {
        // The menu itself is always registered (see mmi-data-pipeline.php's
        // boot), so this can be reached with none of the feature classes
        // loaded — everything main.php's tabs reference lives behind the
        // mmi_is_licensed_or_trialing() gate further down in the main file.
        // Render the license/trial panel alone in that case rather than
        // including a page that assumes those classes exist.
        $licensed_or_trialing = function_exists( 'mmi_is_licensed_or_trialing' )
            && mmi_is_licensed_or_trialing( 'mmi-data-pipeline' );

        if ( ! $licensed_or_trialing ) {
            self::render_unlicensed_page();
            return;
        }

        self::enqueue_assets();
        include MMI_PIPELINE_PATH . 'admin/views/main.php';
    }

    /**
     * Minimal fallback shown once a trial has expired and no license has
     * been activated — deliberately touches nothing from the gated feature
     * set below, only the shared license panel.
     */
    private static function render_unlicensed_page(): void {
        echo '<div class="wrap mmi-page">';
        echo '<div class="mmi-header"><h1><span class="dashicons dashicons-database-import"></span> ' . esc_html__( 'Data Pipeline', 'mmi-data-pipeline' ) . '</h1>';
        echo '<p class="mmi-header-description">' . esc_html__( 'Unified product data workflow for WooCommerce.', 'mmi-data-pipeline' ) . '</p></div>';
        echo '<div class="wp-header-end"></div>';

        if ( class_exists( 'MMI_License_UI' ) ) {
            MMI_License_UI::render_panel( 'mmi-data-pipeline', 'Data Pipeline' );
        }

        echo '</div>';
    }

    /* ── Asset loading ────────────────────────────────────────────────────── */

    private static function enqueue_assets(): void {
        // main.php routes each top-level tab (Import/Export/Duplicates) to its
        // own full-page PHP render — only one tab's markup ever reaches the DOM
        // per request. This used to enqueue all ~15 JS files and 7 stylesheets
        // from every tab unconditionally regardless of which one was actually
        // rendered; gating each tab's assets behind the same $_GET['pipeline_tab']
        // check main.php already uses means a Duplicates view, for example, no
        // longer downloads and parses the entire Import pipeline core + its 8
        // feature modules, or the Export bundle, for markup that was never on
        // the page.
        $valid_tabs  = [ 'import', 'export', 'workbench', 'taxonomy', 'duplicates' ];
        $current_tab = isset( $_GET['pipeline_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['pipeline_tab'] ) ) : 'import';
        if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
            $current_tab = 'import';
        }
        // 'taxonomy' and 'duplicates' are legacy tab values — main.php
        // redirects each to ?pipeline_tab=import&open_{taxonomy,duplicates}=1,
        // but that redirect fires from main.php's own include, which this
        // method's caller (render_page()) runs AFTER enqueue_assets().
        // Normalize here too so each one's assets (now part of the Import
        // tab's bundle — Taxonomy Mapping folded in 2026-08-30, Duplicate
        // Products 2026-09-02) still load on the very request that's about
        // to redirect.
        if ( 'taxonomy' === $current_tab || 'duplicates' === $current_tab ) {
            $current_tab = 'import';
        }

        /* ── Shared base CSS (design tokens the other tabs' CSS builds on) ── */

        self::enqueue_style( 'mmi-pipeline-settings',
            'assets/css/import-settings.css', [ 'mmi-suite-common' ] );

        /* ── Product Workbench tab ─────────────────────────────────────── */

        if ( 'workbench' === $current_tab ) {
            self::enqueue_rule_builder();
            self::enqueue_style( 'mmi-pipeline-workbench',
                'assets/css/product-workbench.css', [ 'mmi-pipeline-settings', 'mmi-pipeline-rule-builder' ] );
            self::enqueue_script( 'mmi-pipeline-workbench',
                'assets/js/product-workbench.js', [ 'jquery', 'mmi-pipeline-rule-builder', 'mmi-escape-html', 'mmi-pagination' ] );
        }

        /* ── Import tab ────────────────────────────────────────────────── */

        if ( 'import' === $current_tab ) {
            // The media library (plupload/mediaelement/imgareaselect/media-views —
            // a substantial JS/CSS stack) is only ever opened from the Configure
            // Source modal's File Upload panel, to replace an EXISTING upload-type
            // source's file (#mmi-upload-choose-btn, import-pipeline.js) — creating
            // a brand-new upload-type source uses the wizard's own drag-drop widget
            // (a plain <input type="file">, no wp.media involved) instead. So this
            // only needs enqueuing when at least one currently configured source is
            // actually type 'upload', not unconditionally on every Import tab visit.
            $mmi_has_upload_source = false;
            foreach ( self::get_configured_suppliers() as $mmi_configured_supplier ) {
                if ( ( $mmi_configured_supplier['source_type'] ?? '' ) === 'upload' ) {
                    $mmi_has_upload_source = true;
                    break;
                }
            }
            if ( $mmi_has_upload_source ) {
                wp_enqueue_media();
            }

            self::enqueue_style( 'mmi-pipeline-preview',
                'assets/css/import-preview.css', [ 'mmi-pipeline-settings' ] );

            self::enqueue_style( 'mmi-pipeline-save-indicator',
                'assets/css/import-settings-save-indicator.css', [ 'mmi-pipeline-settings' ] );

            self::enqueue_rule_builder();

            self::enqueue_style( 'mmi-pipeline-pipeline',
                'assets/css/import-pipeline.css', [ 'mmi-pipeline-settings', 'mmi-pipeline-rule-builder' ] );

            self::enqueue_style( 'mmi-pipeline-attributes',
                'assets/css/attribute-mapping.css', [ 'mmi-pipeline-settings' ] );

            self::enqueue_script( 'mmi-pipeline-product-import',
                'assets/js/product-import.js', [ 'jquery', 'mmi-wc-filter-bar' ] );

            self::enqueue_script( 'mmi-pipeline-settings-js',
                'assets/js/import-settings.js', [ 'jquery', 'mmi-pipeline-core', 'mmi-escape-html', 'mmi-pagination', 'mmi-condition-builder' ] );

            self::enqueue_script( 'mmi-pipeline-save-indicator-js',
                'assets/js/import-settings-save-indicator.js',
                [ 'jquery', 'mmi-pipeline-settings-js' ] );

            self::enqueue_script( 'mmi-pipeline-preview-js',
                'assets/js/import-preview.js', [ 'jquery', 'mmi-modal' ] );

            self::enqueue_script( 'mmi-pipeline-pk-quality-js',
                'assets/js/pk-quality-modal.js', [ 'jquery', 'mmi-pipeline-core' ] );

            /* ── Pipeline core ──────────────────────────────────────────── */

            $core_path = MMI_PIPELINE_PATH . 'assets/js/import-pipeline.js';
            if ( file_exists( $core_path ) ) {
                wp_enqueue_script(
                    'mmi-pipeline-core',
                    MMI_PIPELINE_URL . 'assets/js/import-pipeline.js',
                    [ 'jquery', 'jquery-ui-sortable', 'mmi-modal' ],
                    filemtime( $core_path ),
                    true
                );

                // Localize before feature modules are enqueued
                wp_localize_script( 'mmi-pipeline-core', 'mmiImportSettings',
                    self::build_import_settings_data()
                );

                // Feature modules — extend window.MMIDataPipeline
                $modules = [
                    'mmi-pipeline-config'              => 'import-pipeline-config.js',
                    'mmi-pipeline-test'                => 'import-pipeline-test.js',
                    'mmi-pipeline-sources'              => 'import-pipeline-sources.js',
                    'mmi-pipeline-csv'                  => 'import-pipeline-csv.js',
                    'mmi-pipeline-ui'                    => 'import-pipeline-ui.js',
                    // Replaces the retired import-pipeline-stock-overrides.js and
                    // import-pipeline-catalog-rules.js — those two panels and this
                    // one "Update Store Catalog" button are now one Catalog
                    // Maintenance section/table; see Catalog_Phase_Runner.
                    'mmi-pipeline-catalog-maintenance' => 'import-pipeline-catalog-maintenance.js',
                    'mmi-pipeline-attributes'           => 'import-pipeline-attributes.js',
                ];
                foreach ( $modules as $handle => $file ) {
                    $deps = [ 'jquery', 'mmi-pipeline-core', 'mmi-escape-html' ];
                    if ( 'mmi-pipeline-catalog-maintenance' === $handle ) {
                        $deps[] = 'mmi-modal'; // Match Review modal, see MOD-DPL-01.
                        $deps[] = 'mmi-pipeline-rule-builder';
                    }
                    self::enqueue_script( $handle, 'assets/js/' . $file, $deps );
                }

                // Attribute & variation mapping panel data
                wp_localize_script( 'mmi-pipeline-attributes', 'mmiAttributeMapping',
                    self::build_attribute_mapping_data()
                );
            } else {
                MMI_Logger::warn( 'import-pipeline.js not found', [ 'path' => $core_path ], 'general', 'MMI_Pipeline_Admin' );
            }

            // Localize preview data (profile + enabled suppliers) after core
            wp_localize_script( 'mmi-pipeline-preview-js', 'mmiProductImportData',
                self::build_preview_data()
            );

            // Taxonomy Mapping — folded into the Import tab as a collapsible
            // section on 2026-08-30 (previously its own top-level tab, see
            // AGENTS.md's Incident History for that date). Loaded unconditionally
            // whenever the Import tab renders, same as every other section on
            // this page, since the section markup is always present (just
            // collapsed by default) rather than only reachable via its own
            // ?pipeline_tab value.
            self::enqueue_style( 'mmi-pipeline-taxonomy',
                'assets/css/taxonomy-mapping.css', [ 'mmi-pipeline-settings' ] );

            self::enqueue_script( 'mmi-pipeline-taxonomy-js',
                'assets/js/taxonomy-mapping.js', [ 'jquery', 'mmi-escape-html' ] );

            wp_localize_script( 'mmi-pipeline-taxonomy-js', 'mmiTaxMapping', [
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'mmi_pipeline_taxonomy_mapping' ),
                'aliasRules' => self::build_taxmap_alias_rules_data(),
            ] );

            // Duplicate Products — folded into the Import tab as a
            // collapsible section on 2026-09-02 (previously its own
            // top-level tab), same "loaded unconditionally, section markup
            // just starts collapsed" treatment as Taxonomy Mapping above.
            self::enqueue_style( 'mmi-pipeline-dupes',
                'assets/css/duplicate-products.css', [ 'mmi-pipeline-settings' ] );

            self::enqueue_script( 'mmi-pipeline-dupes-js',
                'assets/js/duplicate-products.js', [ 'jquery', 'mmi-escape-html' ] );

            wp_localize_script( 'mmi-pipeline-dupes-js', 'mmiDupes', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'mmi_pipeline_canonical_candidates' ),
            ] );
        }

        /* ── Export tab ───────────────────────────────────────────────────── */

        if ( 'export' === $current_tab ) {
            self::enqueue_style( 'mmi-pipeline-export',
                'assets/css/export.css', [ 'mmi-pipeline-settings' ] );

            self::enqueue_script( 'mmi-pipeline-export-settings-js',
                'assets/js/export-settings.js', [ 'jquery', 'mmi-modal' ] );

            self::enqueue_script( 'mmi-pipeline-export-preview-js',
                'assets/js/export-preview.js', [ 'jquery', 'mmi-column-customizer' ] );

            wp_localize_script( 'mmi-pipeline-export-settings-js', 'mmiExportSettings',
                self::build_export_settings_data()
            );
        }
    }

    /**
     * Build the mmiExportSettings JS object for the Export tab.
     *
     * @return array<string, mixed>
     */
    private static function build_export_settings_data(): array {
        $current_profile = isset( $_GET['profile'] ) ? sanitize_text_field( wp_unslash( $_GET['profile'] ) ) : '';

        if ( class_exists( 'MMI_DB' ) ) {
            $export_profiles = MMI_DB::get_profiles_by_direction( 'export' );
            if ( ! isset( $export_profiles[ $current_profile ] ) ) {
                $current_profile = array_key_first( $export_profiles ) ?? '';
            }
        }

        return [
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'mmi_pipeline_nonce' ),
            'currentProfile'  => $current_profile,
        ];
    }

    /**
     * Annotate saved alias rules with their current term name for initial JS render.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function build_taxmap_alias_rules_data(): array {
        $rules = MMI_DB::get_taxmap_alias_rules();
        foreach ( $rules as &$rule ) {
            $tid = (int) ( $rule['wc_term_id'] ?? 0 );
            // A deleted term: name left empty and the old id flagged, so the
            // rule panel shows the rule as broken (match_taxmap_alias_rule()
            // already skips it at import).
            $rule['missing_term_id'] = 0;
            if ( $tid > 0 ) {
                $t    = get_term( $tid, $rule['wc_taxonomy'] ?? '' );
                $live = $t && ! is_wp_error( $t );
                $rule['wc_term_name']    = $live ? $t->name : '';
                $rule['missing_term_id'] = $live ? 0 : $tid;
            } elseif ( $tid === -1 ) {
                $rule['wc_term_name'] = '__skip__';
            } else {
                $rule['wc_term_name'] = '';
            }
        }
        unset( $rule );
        return $rules;
    }

    /* ── Localization helpers ─────────────────────────────────────────────── */

    /**
     * Whether WooCommerce is active — the single check every WooCommerce-only
     * feature in this plugin gates on. Now that Import/Export support any
     * data type (Posts, taxonomies, orders, arbitrary CPTs — see
     * MMI_Data_Type_Registry), this plugin is no longer usable on a
     * WooCommerce site alone; Catalog Maintenance/Stock Overrides/Custom
     * Rules are WC-product-specific and must degrade gracefully, not fatal
     * on an undefined wc_get_product()/wc_get_products() call, on a site
     * running this plugin purely for a non-Product data type.
     *
     * @return bool
     */
    public static function is_woocommerce_active(): bool {
        return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
    }

    /**
     * Known JSON file option sets per built-in supplier_id — the single
     * canonical source for "which files can this source's field-mapping/
     * primary-key selectors offer to browse." Previously hand-maintained as
     * three independent, identically-shaped copies (this method's own former
     * inline array in get_configured_suppliers(), a second copy in
     * build_import_settings_data(), and a third in import-pipeline-sources.js's
     * _knownSupplierFiles, whose own comment already said "Mirrors the PHP
     * $known_supplier_json_files map" — an aspiration the JS copy couldn't
     * actually enforce) — confirmed live to have already silently drifted:
     * none of the three ever knew about xchange-web-assets.json once that
     * file started being written, so neither the Field Mapping panel's
     * per-supplier file dropdown nor the wizard's primary-key file selector
     * ever offered it. Consolidated here; build_import_settings_data()
     * localizes this same array to JS as mmiImportSettings.knownSupplierFiles
     * so the JS copy was deleted rather than kept in sync by hand.
     *
     * @return array<string, array<string, string>>
     */
    public static function known_supplier_json_files(): array {
        return [
            'skuport' => [
                'skuport-products.json' => 'Products',
                'skuport-promos.json'   => 'Promotions',
            ],
            'xchange' => [
                'xchange-products.json'   => 'Products',
                'xchange-promotions.json' => 'Promotions',
                'xchange-web-assets.json' => 'Web Assets (Enrichment)',
            ],
        ];
    }

    /**
     * Files that enrich an already-matched product (merged onto the item by
     * MMI_Pipeline_Field_Resolver::enrich_item_with_web_assets(), keyed by
     * whichever primary-key value the main feed already resolved) rather
     * than being an independent record source with a primary key of their
     * own. Excluded from get_configured_suppliers()'s pk_file_options so the
     * Import wizard's primary-key selector can't offer a file the resolution
     * code has no way to match a primary key against — Field Mapping's own
     * file browser (and every other file_options consumer) is unaffected,
     * since those files still hold real, mappable fields.
     */
    private const ENRICHMENT_ONLY_FILES = [
        'xchange-web-assets.json',
        // Promotions files are the same shape as web-assets — always a
        // side-lookup dictionary keyed by a primary-key value already
        // resolved from the Products feed (MMI_Pipeline_Field_Resolver::
        // load_and_index_promotions()/inject_promotion()), never iterated
        // as an independent record source.
        'xchange-promotions.json',
        'skuport-promos.json',
    ];

    /**
     * All currently-configured data sources, keyed by supplier_id — the
     * single source of truth for "what sources exist right now" wherever
     * a fresh, non-stale read is needed (as opposed to build_import_settings_data()
     * below, whose output is baked into the page's HTML once at load time via
     * mmiImportSettings.configuredSuppliers and goes stale the moment a source
     * is added or edited anywhere else without a full page reload).
     *
     * Extracted from tab-pipeline.php's own former inline copy of this exact
     * query so both the page's initial render and any later on-demand refresh
     * (see mmi_pipeline_get_wizard_sources_html in ImportSettingsController.php)
     * compute it identically — see the Wizard Source List Went Stale incident
     * in AGENTS.md for why an on-demand refresh needed to exist at all.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_configured_suppliers(): array {
        global $wpdb;
        $ds_table = $wpdb->prefix . 'mmi_data_sources';

        $configured_suppliers = [];
        $known_supplier_json_files = self::known_supplier_json_files();

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) === $ds_table ) {
            $ds_rows = $wpdb->get_results(
                "SELECT supplier_id, supplier_name, source_type, enabled, config_status, configuration
                   FROM {$ds_table} ORDER BY display_order ASC, supplier_name ASC",
                ARRAY_A
            );
            foreach ( $ds_rows as $ds_row ) {
                $sid = $ds_row['supplier_id'];
                $cfg = json_decode( $ds_row['configuration'] ?: '{}', true ) ?: [];
                // Use explicit json_files from config, known defaults, or a convention-based fallback
                if ( ! empty( $cfg['json_files'] ) && is_array( $cfg['json_files'] ) ) {
                    $file_options = $cfg['json_files'];
                } elseif ( isset( $known_supplier_json_files[ $sid ] ) ) {
                    $file_options = $known_supplier_json_files[ $sid ];
                } else {
                    $file_options = [ "{$sid}-products.json" => 'Products' ];
                }
                // Same three-tier resolution as $file_options, minus any
                // enrichment-only file — see ENRICHMENT_ONLY_FILES's own
                // docblock for why those don't belong in a primary-key
                // selector. Never empty in practice: the only supplier with
                // an enrichment-only file today (Xchange) also has 2 real
                // source files.
                $pk_file_options = array_diff_key( $file_options, array_flip( self::ENRICHMENT_ONLY_FILES ) );
                $configured_suppliers[ $sid ] = [
                    'supplier_id'   => $sid,
                    'supplier_name' => $ds_row['supplier_name'],
                    // A source is "enabled" the moment it's validated — no
                    // separate manual step (see AGENTS.md's "Enabled Toggle
                    // Eliminated" entry). The raw `enabled` column is vestigial.
                    'enabled'       => $ds_row['config_status'] === 'validated',
                    'config_status' => $ds_row['config_status'],
                    'source_type'   => $ds_row['source_type'],
                    'file_options'  => $file_options,
                    'pk_file_options' => $pk_file_options,
                    // Per-source Taxonomy Mapping toggle (2026-08-31) — unlike
                    // the removed import-wide "Enabled" toggle above, this is a
                    // narrow, well-defined gate (does this source's alias table
                    // get consulted at all), not a second invisible switch on
                    // top of validation that can silently zero out an entire
                    // import. Defaults true (existing behavior, unaffected)
                    // for any source that predates this key.
                    'taxonomy_mapping_enabled' => ! array_key_exists( 'taxonomy_mapping_enabled', $cfg ) || (bool) $cfg['taxonomy_mapping_enabled'],
                ];
            }
        }

        return $configured_suppliers;
    }

    /**
     * Build the mmiImportSettings JS object.
     *
     * @return array<string, mixed>
     */
    private static function build_import_settings_data(): array {
        global $wpdb;

        // Configured suppliers from data sources table
        $configured_suppliers = [];
        $ds_table = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) === $ds_table ) {
            $known_json = self::known_supplier_json_files();
            $rows = $wpdb->get_results(
                "SELECT supplier_id, supplier_name, source_type, enabled, config_status, configuration
                 FROM {$ds_table} ORDER BY display_order ASC, supplier_name ASC",
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                $sid  = $row['supplier_id'];
                $cfg  = json_decode( $row['configuration'] ?: '{}', true ) ?: [];
                $file_options = ! empty( $cfg['json_files'] ) && is_array( $cfg['json_files'] )
                    ? $cfg['json_files']
                    : ( $known_json[ $sid ] ?? [ "{$sid}-products.json" => 'Products' ] );
                $configured_suppliers[] = [
                    'supplier_id'   => $sid,
                    'supplier_name' => $row['supplier_name'],
                    // A source is "enabled" the moment it's validated — no
                    // separate manual step (see AGENTS.md's "Enabled Toggle
                    // Eliminated" entry). The raw `enabled` column is vestigial.
                    'enabled'       => $row['config_status'] === 'validated',
                    'config_status' => $row['config_status'],
                    'source_type'   => $row['source_type'],
                    'fileOptions'   => $file_options,
                ];
            }
        }

        // Distinct product-scoped meta keys, for the "Custom Post Meta" identifier
        // field's suggestion datalist. wp_postmeta has no full (non-prefix) index
        // on meta_key, so a bare "SELECT DISTINCT meta_key" here would be a full
        // table scan on a table that runs into the millions of rows on this site
        // (confirmed via EXPLAIN — forbidden by this project's Server Load rules).
        // Joining through wp_posts.post_type first keeps it index-driven; caching
        // behind an hour-long transient means the join itself only actually runs
        // once an hour regardless of how many times this page loads.
        $meta_keys_cache_key = 'mmi_pipeline_product_meta_keys';
        $product_meta_keys   = get_transient( $meta_keys_cache_key );
        if ( $product_meta_keys === false ) {
            $query_start = microtime( true );
            $product_meta_keys = $wpdb->get_col(
                "SELECT DISTINCT pm.meta_key
                   FROM {$wpdb->postmeta} pm
                   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                  WHERE p.post_type = 'product'
                  ORDER BY pm.meta_key
                  LIMIT 300"
            );
            set_transient( $meta_keys_cache_key, $product_meta_keys, HOUR_IN_SECONDS );
            if ( class_exists( 'MMI_Logger' ) ) {
                MMI_Logger::info(
                    'Rebuilt product meta-key suggestion cache',
                    [ 'seconds' => round( microtime( true ) - $query_start, 3 ), 'keys_found' => count( $product_meta_keys ) ],
                    'database',
                    'MMI_Pipeline_Admin'
                );
            }
        }

        // Custom product taxonomies (exclude WC built-ins)
        $built_in = [ 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' ];
        $taxes    = get_object_taxonomies( 'product', 'objects' );
        $product_taxonomies = [];
        foreach ( $taxes as $tax ) {
            if ( in_array( $tax->name, $built_in, true ) ) {
                continue;
            }
            $product_taxonomies[] = [ 'slug' => $tax->name, 'label' => $tax->label ];
        }

        // Initial profile meta for the edit-profile modal. Scoped to
        // direction='import' so an export-created profile can never become
        // "current" here via the array_key_first() fallback below.
        $profile_id = isset( $_GET['profile'] ) ? sanitize_text_field( $_GET['profile'] ) : 'default';
        if ( class_exists( 'MMI_DB' ) ) {
            $profiles = MMI_DB::get_profiles_by_direction( 'import' );
            if ( ! isset( $profiles[ $profile_id ] ) ) {
                $profile_id = array_key_first( $profiles ) ?? 'default';
            }
            $meta = $profiles[ $profile_id ] ?? [];
        } else {
            $meta = [];
        }

        return [
            'ajaxurl'             => admin_url( 'admin-ajax.php' ),
            'nonce'               => wp_create_nonce( 'mmi_pipeline_import_settings' ),
            'importNonce'         => wp_create_nonce( 'mmi_pipeline_nonce' ),
            'badgeMap'            => [
                'validated'    => [ 'class' => 'info',    'label' => 'Ready' ],
                'configured'   => [ 'class' => 'warning', 'label' => 'Needs Test' ],
                'error'        => [ 'class' => 'error',   'label' => 'Error' ],
                'unconfigured' => [ 'class' => '',        'label' => 'Needs Setup' ],
                'enabled'      => [ 'class' => 'success', 'label' => 'Active' ],
            ],
            'configuredSuppliers' => $configured_suppliers,
            // The same canonical per-supplier file-option map known_supplier_json_files()
            // provides server-side, localized so import-pipeline-sources.js's
            // client-side "add a source without reloading" row-injection path
            // reads live data instead of maintaining its own hand-copied object.
            'knownSupplierFiles'  => self::known_supplier_json_files(),
            'productTaxonomies'   => $product_taxonomies,
            'productMetaKeys'     => $product_meta_keys,
            'profileNames'        => class_exists( 'MMI_DB' ) ? array_map( fn( $p ) => $p['name'], MMI_DB::get_profiles_by_direction( 'import' ) ) : [],
            // Saved + builtin Field Mapping presets — reusable, explicitly-applied
            // starting points a user can save from one profile and apply to
            // another, rather than profiles implicitly sharing mapping state.
            // Merges user-saved presets with whatever sibling plugins ship via
            // the 'mmi_pipeline_builtin_field_mapping_presets' filter — see
            // mmi_get_all_field_mapping_presets() in ImportSettingsController.php,
            // the single read path every consumer of this data uses.
            'fieldMappingPresets' => function_exists( 'mmi_get_all_field_mapping_presets' ) ? array_map(
                static fn( $p ) => [
                    'id'        => $p['id'] ?? '',
                    'name'      => $p['name'] ?? '',
                    'data_type' => $p['data_type'] ?? 'product',
                    'sources'   => $p['sources'] ?? [],
                    'builtin'   => ! empty( $p['builtin'] ),
                ],
                mmi_get_all_field_mapping_presets()
            ) : [],
            'initialProfileMeta'  => [
                'profile_id'         => $profile_id,
                'name'               => $meta['name']               ?? $profile_id,
                'import_mode'        => $meta['import_mode']        ?? 'update-only',
                'mode_settings'      => $meta['mode_settings']      ?? [],
                'product_scope'      => $meta['product_scope']      ?? 'all_products',
                'product_identifier' => $meta['product_identifier'] ?? null,
                'sources'            => $meta['sources']            ?? [],
                // Mirrors mmi_load_import_profile's own profile_meta shape —
                // see that handler's comment (ImportSettingsController.php)
                // for why the wizard needs this (Milestone 3, Attributes
                // step skip for non-Product profiles).
                'data_type'          => $meta['data_type']           ?? 'product',
            ],
        ];
    }

    /**
     * Build the mmiProductImportData JS object.
     *
     * @return array<string, mixed>
     */
    private static function build_preview_data(): array {
        global $wpdb;

        $profile_id = isset( $_GET['profile'] ) ? sanitize_text_field( $_GET['profile'] ) : 'default';
        if ( class_exists( 'MMI_DB' ) ) {
            $profiles = MMI_DB::get_profiles_by_direction( 'import' );
            if ( ! isset( $profiles[ $profile_id ] ) ) {
                $profile_id = array_key_first( $profiles ) ?? 'default';
            }
        }

        // Prefer authoritative data sources table over settings. A source
        // counts the moment it's validated — there is no separate manual
        // "enabled" step (see AGENTS.md's "Enabled Toggle Eliminated" entry).
        $ds_table = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) === $ds_table ) {
            $enabled = $wpdb->get_col(
                "SELECT supplier_id FROM {$ds_table} WHERE config_status = 'validated' ORDER BY display_order ASC"
            ) ?: [];
        } else {
            $enabled = class_exists( 'MMI_DB' ) ? MMI_DB::get_setting( 'mmi_pipeline_enabled_suppliers', [] ) : [];
            if ( is_string( $enabled ) ) {
                $enabled = maybe_unserialize( $enabled );
            }
            if ( ! is_array( $enabled ) ) {
                $enabled = [];
            }
        }

        // Human labels for every show_ui product taxonomy, keyed by taxonomy
        // name — the Review filter bar builds its "Categories" checkboxes
        // dynamically client-side (see updateFilterBarAvailability() in
        // import-preview.js) from whichever taxonomies actually have terms
        // assigned on the products in the current preview, rather than
        // statically rendering every taxonomy the site has regardless of
        // relevance; it needs a real label to head each group with, since a
        // raw taxonomy slug like "pa_product-line" isn't presentable on its
        // own. Sent once here instead of per preview request since the set
        // of registered taxonomies doesn't change within a page load.
        $taxonomy_labels = [];
        foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
            if ( $tax->show_ui ) {
                $taxonomy_labels[ $tax->name ] = $tax->label ?: $tax->name;
            }
        }

        return [
            'ajaxurl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'mmi_product_importer_nonce' ),
            'currentProfile'   => $profile_id,
            'enabledSuppliers' => array_values( $enabled ),
            'taxonomyLabels'   => $taxonomy_labels,
        ];
    }

    /**
     * Build the mmiAttributeMapping JS object for the attribute/variation panel.
     *
     * @return array<string, mixed>
     */
    private static function build_attribute_mapping_data(): array {
        global $wpdb;

        $profile_id = isset( $_GET['profile'] ) ? sanitize_text_field( $_GET['profile'] ) : 'default';

        // Registered WooCommerce global attribute taxonomies
        $wc_attributes = [];
        if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
            foreach ( wc_get_attribute_taxonomies() as $tax ) {
                $wc_attributes[] = [
                    'slug'  => wc_attribute_taxonomy_name( $tax->attribute_name ),
                    'name'  => $tax->attribute_name,
                    'label' => $tax->attribute_label,
                ];
            }
        }

        // Configured suppliers (for "Discover from Sample Data" dropdown)
        $suppliers = [];
        $ds_table  = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) === $ds_table ) {
            $ds_rows = $wpdb->get_results(
                "SELECT supplier_id, supplier_name, configuration FROM {$ds_table} ORDER BY display_order ASC",
                ARRAY_A
            );
            foreach ( $ds_rows as $row ) {
                $sid = $row['supplier_id'];
                $cfg = json_decode( $row['configuration'] ?: '{}', true ) ?: [];
                $file_key = '';
                if ( ! empty( $cfg['json_files'] ) && is_array( $cfg['json_files'] ) ) {
                    $file_key = array_key_first( $cfg['json_files'] );
                } else {
                    $file_key = "{$sid}-products.json";
                }
                $suppliers[] = [
                    'id'      => $sid,
                    'name'    => $row['supplier_name'],
                    'fileKey' => $file_key,
                ];
            }
        }

        return [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'mmi_pipeline_import_settings' ),
            'profile'      => $profile_id,
            'wcAttributes' => $wc_attributes,
            'suppliers'    => $suppliers,
        ];
    }

    /* ── Stock Override Metabox ───────────────────────────────────────────── */

    /**
     * Register the MMI Stock Control metabox on the WooCommerce product edit screen.
     */
    public static function register_stock_override_metabox(): void {
        add_meta_box(
            'mmi_stock_override',
            'MMI Stock Control',
            [ __CLASS__, 'render_stock_override_metabox' ],
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render the metabox content.
     *
     * @param WP_Post $post
     */
    public static function render_stock_override_metabox( \WP_Post $post ): void {
        $current = get_post_meta( $post->ID, '_mmi_stock_override', true );
        wp_nonce_field( 'mmi_stock_override_save_' . $post->ID, 'mmi_stock_override_nonce' );
        ?>
        <p class="mmi-metabox-description">
            Override the stock status set by imports. Takes priority over all bulk rules.
        </p>
        <label for="mmi_stock_override" class="screen-reader-text">MMI Stock Override</label>
        <select id="mmi_stock_override"
                name="mmi_stock_override">
            <option value=""              <?php selected( $current, '' ); ?>>
                Follow import data
            </option>
            <option value="force_instock" <?php selected( $current, 'force_instock' ); ?>>
                &#x2705; Force In Stock
            </option>
            <option value="force_outofstock" <?php selected( $current, 'force_outofstock' ); ?>>
                &#x1F6AB; Force Out of Stock
            </option>
        </select>
        <?php if ( $current !== '' ) : ?>
            <p class="mmi-metabox-override-active">
                <strong>Override active</strong> — import data will not change this product's stock.
            </p>
        <?php endif; ?>
        <?php
    }

    /**
     * Persist the metabox value when a product is saved.
     *
     * @param int $post_id
     */
    public static function save_stock_override_metabox( int $post_id ): void {
        // Bail on autosave, bulk edit, or missing nonce.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['mmi_stock_override_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST['mmi_stock_override_nonce'] ), 'mmi_stock_override_save_' . $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $allowed  = [ '', 'force_instock', 'force_outofstock' ];
        $raw      = sanitize_text_field( wp_unslash( $_POST['mmi_stock_override'] ?? '' ) );
        $override = in_array( $raw, $allowed, true ) ? $raw : '';

        if ( $override === '' ) {
            delete_post_meta( $post_id, '_mmi_stock_override' );
        } else {
            update_post_meta( $post_id, '_mmi_stock_override', $override );
        }
    }

    /* ── Helpers ──────────────────────────────────────────────────────────── */

    /**
     * The suite-wide condition builder (shared library's
     * mmi-condition-builder.js, pointed at this plugin's cascade AJAX) plus
     * the action settings Custom Rules and Product Workbench share
     * (rule-builder.js/.css). Used by Catalog Maintenance, Product Workbench
     * and Field Mapping. Same nonce as the rest of the Import tab's AJAX.
     */
    private static function enqueue_rule_builder(): void {
        $nonce = wp_create_nonce( 'mmi_pipeline_import_settings' );
        wp_enqueue_script( 'mmi-condition-builder' );
        wp_localize_script( 'mmi-condition-builder', 'mmiConditionBuilder', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => $nonce,
            'actions' => [
                'fields' => 'mmi_get_custom_rule_source_fields',
                'values' => 'mmi_get_condition_meta_values',
                'terms'  => 'mmi_pipeline_get_taxonomy_terms',
            ],
        ] );
        self::enqueue_style( 'mmi-pipeline-rule-builder',
            'assets/css/rule-builder.css', [ 'mmi-pipeline-settings' ] );
        self::enqueue_script( 'mmi-pipeline-rule-builder',
            'assets/js/rule-builder.js', [ 'jquery', 'mmi-escape-html', 'mmi-condition-builder' ] );
        wp_localize_script( 'mmi-pipeline-rule-builder', 'mmiRuleBuilder', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => $nonce,
        ] );
    }

    private static function enqueue_style( string $handle, string $relative_path, array $deps ): void {
        $abs = MMI_PIPELINE_PATH . $relative_path;
        if ( ! file_exists( $abs ) ) {
            return;
        }
        wp_enqueue_style( $handle, MMI_PIPELINE_URL . $relative_path, $deps, filemtime( $abs ) );
    }

    private static function enqueue_script( string $handle, string $relative_path, array $deps ): void {
        $abs = MMI_PIPELINE_PATH . $relative_path;
        if ( ! file_exists( $abs ) ) {
            return;
        }
        wp_enqueue_script( $handle, MMI_PIPELINE_URL . $relative_path, $deps, filemtime( $abs ), true );
    }
}
