<?php
/**
 * Plugin Name: MMI Data Pipeline
 * Plugin URI:  https://mannmade.us
 * Description: Import and export any WordPress or WooCommerce data — products,
 *              orders, customers, coupons, users, comments, posts, pages,
 *              custom post types and taxonomies — with guided field mapping,
 *              taxonomy mapping, supplier data sources, scheduling and run
 *              reports. CSV, JSON and XML.
 * Version:     2.56.2
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author:      MannMade Solutions
 * Author URI:  https://mannmade.us
 * Requires PHP: 7.4
 * Text Domain: mmi-data-pipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Constants ────────────────────────────────────────────────────────────── */

define( 'MMI_PIPELINE_VERSION', '2.56.2' );
define( 'MMI_PIPELINE_PATH',    plugin_dir_path( __FILE__ ) );
define( 'MMI_PIPELINE_URL',     plugin_dir_url( __FILE__ ) );
define( 'MMI_PIPELINE_SLUG',    'mmi-data-pipeline' );

// ── MMI Shared Library (ADR-0006/ADR-0007, mmi-admin/docs/decisions/) ────────
// Registers this plugin's bundled copy of MMI_Settings/MMI_Logger/
// MMI_API_Throttler/MMI_Resource_Guard/MMI_Model/MMI_Product/MMI_Media_Helper/
// MMI_DB/HTTPClient/MMI_Updater_Trait as a version-negotiation candidate. This
// plugin has no hard MMI plugin dependency (WooCommerce is a soft, per-feature
// dependency already — see MMI_Pipeline_Admin::is_woocommerce_active()) — these
// classes resolve from this bundled copy whenever mmi-hub isn't present, or
// from mmi-hub's own copy (which still wins whenever mmi-hub IS present — see
// ADR-0006). Must load before anything below could reference any of those
// class names.
require_once MMI_PIPELINE_PATH . 'includes/mmi-shared/bootstrap.php';

// Action Scheduler priority (lower number = claimed first — see
// ActionScheduler_DBStore::claim_actions()'s "ORDER BY priority ASC, ...").
// Profile import/export batches are user-triggered and covered by this
// plugin's own stale-run alerting (see MMI_Pipeline_Cron's abandoned-run
// detector), so they deliberately run ABOVE Action Scheduler's default
// priority (10) — they shouldn't have to wait behind another plugin's
// routine background work (e.g. mmi-reverb-integration's
// MMI_REVERB_AS_PRIORITY_BACKGROUND-tier hooks) in the single-concurrency
// queue.
define( 'MMI_PIPELINE_AS_PRIORITY_BATCH', 5 );

/* ── Access control + audit helpers ───────────────────────────────────────── */

/**
 * The capability a Data Pipeline admin action requires.
 *
 * Every admin page, AJAX handler and download in this plugin routes its
 * capability check through here. $default_cap is the capability that handler
 * has always required ('manage_woocommerce' for import/catalog tooling,
 * 'manage_options' for exports, logs and other site-level actions), so the
 * unfiltered result is unchanged. Sites that want least-privilege roles can
 * remap either tier with the 'mmi_data_pipeline_required_capability' filter,
 * which receives the handler's default capability.
 *
 * @param string $default_cap Capability the calling handler requires by default.
 * @return string
 */
function mmi_data_pipeline_required_capability( string $default_cap = 'manage_woocommerce' ): string {
    $cap = apply_filters( 'mmi_data_pipeline_required_capability', $default_cap );
    return ( is_string( $cap ) && $cap !== '' ) ? $cap : $default_cap;
}

/**
 * Whether the current user may perform a Data Pipeline admin action.
 *
 * A refusal during an AJAX/admin-post request is written to the audit log
 * (once per request) as 'access.denied'.
 *
 * @param string $default_cap See mmi_data_pipeline_required_capability().
 * @return bool
 */
function mmi_data_pipeline_user_can( string $default_cap = 'manage_woocommerce' ): bool {
    $cap     = mmi_data_pipeline_required_capability( $default_cap );
    $allowed = current_user_can( $cap );

    static $denial_logged = false;
    if ( ! $allowed && ! $denial_logged && ( wp_doing_ajax() || 'admin-post.php' === ( $GLOBALS['pagenow'] ?? '' ) ) ) {
        $denial_logged = true;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- logging the requested action name only.
        $request_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        mmi_data_pipeline_audit( 'access.denied', [
            'outcome' => 'denied',
            'details' => [ 'request_action' => $request_action, 'capability' => $cap ],
        ] );
    }

    return $allowed;
}

/**
 * Write one entry to the shared append-only audit log, when present.
 *
 * Never pass secret values in $args['details'] — setting/credential changes
 * record key names only.
 *
 * @param string $action Dot-separated action name, e.g. 'export.download'.
 * @param array  $args   object_type, object_id, outcome, details.
 */
function mmi_data_pipeline_audit( string $action, array $args = [] ): void {
    if ( class_exists( 'MMI_Audit_Log' ) ) {
        MMI_Audit_Log::record( 'mmi-data-pipeline', $action, $args );
    }
}

/* ── Boot ─────────────────────────────────────────────────────────────────── */
// No hard MMI plugin dependency guard needed here — see the shared-library
// comment above. WooCommerce is checked per-feature at its own call sites
// (MMI_Pipeline_Admin::is_woocommerce_active()), not at boot time.

// Menu registration runs unconditionally, licensed or not — a customer with
// an expired trial and no active license must still be able to find this
// plugin's own settings page to see why nothing works and enter a real key.
// Everything ELSE below (the actual import/export machinery) stays behind
// the license/trial gate; MMI_Pipeline_Admin::render_page() itself checks
// mmi_is_licensed_or_trialing() before including a page that assumes those
// classes are loaded.
add_action( 'plugins_loaded', function () {
    require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-admin.php';
    MMI_Pipeline_Admin::init();
} );

// Private storage for uploaded supplier feeds. Outside the license gate: a
// lapsed license must never re-expose a dealer price list or the private
// folder's name.
add_action( 'plugins_loaded', function () {
    require_once MMI_PIPELINE_PATH . 'includes/suppliers/class-pipeline-private-uploads.php';
    MMI_Pipeline_Private_Uploads::register_hooks();
} );

// Product Content Registry + its Bricks integration are read-only display of
// the store's own product data (specs, requirements, videos). Deliberately
// outside the license gate below: a lapsed license must never blank the
// specs on a live storefront's product pages.
add_action( 'plugins_loaded', function () {
    require_once MMI_PIPELINE_PATH . 'includes/content/class-product-content-registry.php';
    require_once MMI_PIPELINE_PATH . 'includes/content/class-product-content-bricks.php';
    MMI_Product_Content_Bricks::init();
    // The Product Specs metabox is the only editor for the registry's
    // canonical keys, so it lives outside the license gate with them.
    require_once MMI_PIPELINE_PATH . 'includes/content/class-product-content-editor.php';
    MMI_Product_Content_Editor::init();

    // The Product Quality rule pack for mmi-data-health moved to mmi-admin
    // (includes/catalog-rules/) on 2026-09-26: it encodes mannmade.us's own
    // quality bar, which customers don't get (ADR-0012 part 2).
} );

add_action( 'plugins_loaded', function () {
    // License-or-trial guard. mmi_is_licensed_or_trialing() (shared library)
    // silently starts/resumes this domain's 14-day trial the first time this
    // returns false for a never-before-seen domain — see licensing-helpers.php.
    // A site past its trial with no purchased key correctly returns false
    // here forever after, same as the old bare mmi_is_licensed() gate did
    // for "never licensed at all."
    if ( function_exists( 'mmi_is_licensed_or_trialing' ) && ! mmi_is_licensed_or_trialing( 'mmi-data-pipeline' ) ) {
        return;
    }

    // Self-contained helpers (no external plugin dependencies)
    require_once MMI_PIPELINE_PATH . 'includes/helpers/class-pipeline-rate-limiter.php';

    // Canonical field-value resolver (path walking, transforms, promo indexing,
    // change comparison) — single source of truth shared by the importer,
    // the preview generator, and the field-mapping test endpoints.
    require_once MMI_PIPELINE_PATH . 'includes/helpers/class-pipeline-field-resolver.php';

    // Hierarchical taxonomy checkbox-tree renderer (Export Step 1 category
    // filter and any future taxonomy-scoped field).
    require_once MMI_PIPELINE_PATH . 'includes/helpers/class-taxonomy-tree-renderer.php';

    // Meta-key discovery + meta_query builder — shared by the postmeta-backed
    // data type handlers and the Export Step 1 "Custom Field Filters" UI.
    require_once MMI_PIPELINE_PATH . 'includes/helpers/class-meta-key-discovery.php';
    require_once MMI_PIPELINE_PATH . 'includes/helpers/class-meta-query-builder.php';

    // Data source acquisition for url/dropbox/gdrive types —
    // \MannMade\Integrations\Acquisition\Data_Source_Manager, called by its
    // fully-qualified name from RunSupplierFetchController.php,
    // SourceFetchController.php, ImportSettingsController.php, and
    // class-pipeline-cron.php. Originally lived only in mmi-hub; that copy
    // was never vendored anywhere before mmi-hub's 2026-09-17 deletion, so
    // every one of those call sites silently no-op'd via class_exists() and
    // no url/dropbox/gdrive source was ever actually fetchable. Rebuilt here
    // 2026-09-18 (inferred from those call sites' usage — no original source
    // survived) rather than left missing.
    require_once MMI_PIPELINE_PATH . 'includes/acquisition/class-data-source-manager.php';

    // Legacy per-supplier fetch runner (Xchange/XchangeVendors/SkuPort/Plugivery)
    // — consolidated here from mmi-hub and mmi-vip's separate duplicate copies.
    // Companion classes stay together; only HTTPClient/MMI_Updater_Trait remain
    // in mmi-hub (shared with its other updaters).
    require_once MMI_PIPELINE_PATH . 'includes/suppliers/class-pipeline-xchange-updater.php';
    require_once MMI_PIPELINE_PATH . 'includes/suppliers/class-pipeline-xchange-vendors.php';
    require_once MMI_PIPELINE_PATH . 'includes/suppliers/class-pipeline-skuport-updater.php';
    require_once MMI_PIPELINE_PATH . 'includes/suppliers/class-pipeline-plugivery-updater.php';
    MMI_Pipeline_Plugivery_Updater::register_hooks();
    require_once MMI_PIPELINE_PATH . 'includes/suppliers/class-pipeline-supplier-fetch-runner.php';
    require_once MMI_PIPELINE_PATH . 'includes/suppliers/class-pipeline-upload-source-fetcher.php';

    // Catalog updater (stock diff/reconcile against supplier feeds, canonical/
    // associated-product stock rules, category/brand auto-assignment) —
    // consolidated here from mmi-hub; previously invoked from mmi-vip and
    // mmi-hub as well, each with its own duplicate trigger/registration.
    require_once MMI_PIPELINE_PATH . 'includes/catalog/class-pipeline-catalog-updater.php';

    // Stock override resolver (needed by the importer and the admin metabox)
    require_once MMI_PIPELINE_PATH . 'includes/class-stock-override-resolver.php';

    // Custom Rule action registry/executor — every non-stock action a
    // Catalog Maintenance custom rule can apply (taxonomy, post status,
    // pricing, etc). Depends on Stock_Override_Resolver above (stock-action
    // detection + the shared force_stock_status() writer).
    require_once MMI_PIPELINE_PATH . 'includes/class-custom-rule-actions.php';

    // Condition cascade + action picker markup, shared by Catalog
    // Maintenance's Custom Rules and Product Workbench.
    require_once MMI_PIPELINE_PATH . 'includes/rule-builder/class-rule-builder-view.php';

    // Product Workbench — search the catalog with the Custom Rule condition
    // engine and apply any Custom Rule action to the results, with undo.
    require_once MMI_PIPELINE_PATH . 'includes/workbench/class-workbench-change-log.php';
    require_once MMI_PIPELINE_PATH . 'includes/workbench/class-product-workbench.php';
    require_once MMI_PIPELINE_PATH . 'includes/workbench/class-health-checks.php';

    // Catalog run state + phase runner — the single execution path shared by
    // the "Update Store Catalog" button, the scheduled cron/Action Scheduler
    // chain, and `wp mmi catalog`. Must load after the catalog updater and
    // the stock override resolver above, both of which it calls into.
    require_once MMI_PIPELINE_PATH . 'includes/catalog/class-catalog-run-state.php';
    require_once MMI_PIPELINE_PATH . 'includes/catalog/class-catalog-phase-runner.php';

    // Field mapping defaults — single source of truth for the Field Mapping UI,
    // the importer, and the pre-flight validator (must load before both).
    // Sibling plugins (e.g. mmi-xchange-integration) read this class directly
    // when contributing their own ready-made presets via the
    // 'mmi_pipeline_builtin_field_mapping_presets' filter — see
    // mmi_get_all_field_mapping_presets() in ImportSettingsController.php.
    require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-field-mapping-defaults.php';

    // Per-product Field Locks — fields an import must never overwrite on an
    // existing product. Needs DEFAULTS (above) for its lockable-field list;
    // read by the worker, the dynamic importer and Import Preview below.
    require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-field-locks.php';
    MMI_Pipeline_Field_Locks::init();

    // Two automated writers on one field (profile vs profile, profile vs
    // Catalog Maintenance) — reported where the conflict is created.
    require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-field-conflicts.php';

    // Pre-flight profile config validator (used before manual/scheduled imports)
    require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-config-validator.php';

    // Importer collaborators (must load before the importer that instantiates them)
    require_once MMI_PIPELINE_PATH . 'includes/importers/class-condition-evaluator.php';
    require_once MMI_PIPELINE_PATH . 'includes/importers/class-product-crud-manager.php';
    require_once MMI_PIPELINE_PATH . 'includes/importers/class-taxonomy-mapping-handler.php';
    require_once MMI_PIPELINE_PATH . 'includes/importers/class-variable-product-manager.php';

    // Self-contained product importer
    require_once MMI_PIPELINE_PATH . 'includes/importers/class-dynamic-product-importer.php';

    // Data type handler abstraction (export + future generalized import).
    // Registered on 'init'@30 (after third-party CPTs/taxonomies), not here —
    // see MMI_Data_Type_Registry::boot(). Classes just need to be loaded.
    require_once MMI_PIPELINE_PATH . 'includes/data-types/interface-data-type-handler.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-data-type-registry.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-taxonomy-field-helper.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-product-data-type.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-order-data-type.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-customer-data-type.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-coupon-data-type.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-comment-data-type.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-user-data-type.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-wp-post-type-data-type.php';
    require_once MMI_PIPELINE_PATH . 'includes/data-types/class-wp-taxonomy-data-type.php';
    add_action( 'init', [ 'MMI_Data_Type_Registry', 'boot' ], 30 );

    // Field schema resolver — generalizes field-mapping defaults beyond product/import.
    require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-field-schema-resolver.php';

    // Generic record importer (Phase 2 foundation) — non-product import
    // profiles only; the product import path above is untouched.
    require_once MMI_PIPELINE_PATH . 'includes/importers/class-dynamic-record-importer.php';

    // Export engine (writers -> exporter -> file manager)
    require_once MMI_PIPELINE_PATH . 'includes/exporters/interface-export-writer.php';
    require_once MMI_PIPELINE_PATH . 'includes/exporters/writers/class-csv-export-writer.php';
    require_once MMI_PIPELINE_PATH . 'includes/exporters/writers/class-json-export-writer.php';
    require_once MMI_PIPELINE_PATH . 'includes/exporters/writers/class-xml-export-writer.php';
    require_once MMI_PIPELINE_PATH . 'includes/exporters/class-export-file-manager.php';
    require_once MMI_PIPELINE_PATH . 'includes/exporters/class-dynamic-data-exporter.php';
    MMI_Export_File_Manager::init();

    // One-time migration: runs DB migration if needed (mmi_pipeline_* namespace)
    require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-migration.php';
    MMI_Pipeline_Migration::init();

    // Core admin UI (class-pipeline-admin.php is already required and
    // initialized unconditionally above, outside this gate).

    // Cron: scheduled supplier fetch + catalog import (with professional emails).
    // Email HTML/CSS comes from MMI_Email_Templates, which now ships bundled
    // in the shared library (ADR-0006, added 2026-09-18 after being found
    // missing post mmi-hub-elimination — see changelog 1.187.0). The render
    // functions that call it still guard with class_exists() as defensive
    // dead code, matching how other shared-library classes are guarded
    // suite-wide even though they're always present.
    require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-cron.php';
    MMI_Pipeline_Cron::init();

    // Supplier feed catalogs: the base each supplier plugin's Catalog tab
    // extends (they register on init when this class exists).
    require_once MMI_PIPELINE_PATH . 'includes/catalog-browser/class-pipeline-feed-catalog.php';

    // Export scheduling/recurrence — structural sibling of the above, export direction.
    require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-export-cron.php';
    MMI_Pipeline_Export_Cron::init();

    // Admin helper classes (UI renderers, import preview, export preview)
    require_once MMI_PIPELINE_PATH . 'includes/admin/class-import-preview.php';
    require_once MMI_PIPELINE_PATH . 'includes/admin/class-export-preview.php';

    // ProductImportController collaborators (must load before the controllers below)
    require_once MMI_PIPELINE_PATH . 'includes/controllers/ajax/class-batch-import-state.php';
    require_once MMI_PIPELINE_PATH . 'includes/controllers/ajax/class-product-import-worker.php';

    // Export batching collaborators (must load before ExportController below)
    require_once MMI_PIPELINE_PATH . 'includes/controllers/ajax/class-batch-export-state.php';
    require_once MMI_PIPELINE_PATH . 'includes/controllers/ajax/class-data-export-worker.php';

    // AJAX controllers (self-contained)
    $pipeline_controllers = [
        'ImportSettingsController.php',
        'DataSourceController.php',
        'TaxonomyMappingController.php',
        'ProductImportController.php',
        'RunSupplierFetchController.php',
        'SourceFetchController.php',
        'CsvMappingController.php',
        'ProcessLogController.php',
        'StockOverrideController.php',
        'CatalogRulesController.php',
        'CatalogUpdateController.php',
        'AttributeMappingController.php',
        'FieldMappingPanelController.php',
        'GenericFieldMappingController.php',
        'CanonicalCandidatesController.php',
        'ExportController.php',
        'QuickImportController.php',
        'WorkbenchController.php',
        'RunInsightsController.php',
    ];
    foreach ( $pipeline_controllers as $ctrl ) {
        require_once MMI_PIPELINE_PATH . 'includes/controllers/ajax/' . $ctrl;
    }

    // Boot process-log controller (explicit init needed; import controllers
    // self-register via their own constructor/add_action calls)
    MMI_Process_Log_Controller::init();
}, 20 );

