<?php
/**
 * Pipeline Migration
 *
 * One-time migration that renames all mmi_vip_* identifiers left over from the
 * plugin's origin inside mmi-vip to the canonical mmi_pipeline_* namespace.
 *
 * Covers:
 *   1. wp_options keys — renamed in-place so existing settings are preserved.
 *   2. WP cron hooks  — old events are cancelled and rescheduled under the new
 *      hook name at the same time, so no scheduled run is lost.
 *
 * The migration runs at most once: after it completes successfully it sets the
 * `mmi_pipeline_migration_v1_done` option to prevent re-running on every load.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Migration {

    const FLAG = 'mmi_pipeline_migration_v1_done';

    /**
     * v2: adds wp_mmi_data_sources.distribution_term_slug, replacing the
     * hardcoded $distribution_map in ProductImportController::assign_distribution_term().
     */
    const FLAG_V2_DISTRIBUTION_TERM = 'mmi_pipeline_migration_v2_distribution_term_done';

    /**
     * v3: adds wp_mmi_vip_tax_mappings.profile_id so an alias row can
     * optionally be scoped to one Import Profile instead of always being
     * global — see MMI_DB::resolve_tax_mapping()'s profile-aware tiering.
     */
    const FLAG_V3_TAX_MAPPINGS_PROFILE_ID = 'mmi_pipeline_migration_v3_tax_mappings_profile_id_done';

    /**
     * Template setup level applied to existing template data sources. Bump
     * TEMPLATE_SETUP_VERSION when mmi_ds_get_preconfigured_templates() gains
     * something existing rows need, and the next admin load applies it.
     */
    const FLAG_TEMPLATE_SETUP    = 'mmi_pipeline_template_setup_version';
    const TEMPLATE_SETUP_VERSION = 1;

    /**
     * Hook into plugins_loaded (priority 20, after MMI_DB is available).
     */
    public static function init(): void {
        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_run' ], 20 );
        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_run_v2_distribution_term' ], 20 );
        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_run_v3_tax_mappings_profile_id' ], 20 );
        // admin_init, not plugins_loaded: DataSourceController.php (which
        // defines mmi_ds_complete_template_setup()) loads on plugins_loaded 20.
        add_action( 'admin_init', [ __CLASS__, 'maybe_complete_template_sources' ] );
    }

    /**
     * Run mmi_ds_complete_template_setup() over every existing data source
     * created from a template. A source added any way other than Add Data
     * Source (Plugivery was inserted by a script) skipped that setup, and the
     * v2 Distribution backfill above only ever ran once, before it existed.
     */
    public static function maybe_complete_template_sources(): void {
        if ( (int) MMI_Settings::get( self::FLAG_TEMPLATE_SETUP, 0 ) >= self::TEMPLATE_SETUP_VERSION ) {
            return;
        }
        // DataSourceController.php is namespaced; its functions are not global.
        if ( ! function_exists( '\\MannMade\\DataPipeline\\Controllers\\AJAX\\mmi_ds_complete_template_setup' ) ) {
            return; // Controllers not loaded on this request — try again later.
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return;
        }

        foreach ( (array) $wpdb->get_col( "SELECT supplier_id FROM {$table}" ) as $supplier_id ) {
            \MannMade\DataPipeline\Controllers\AJAX\mmi_ds_complete_template_setup( (string) $supplier_id );
        }
        MMI_Settings::set( self::FLAG_TEMPLATE_SETUP, self::TEMPLATE_SETUP_VERSION );
    }

    /**
     * Add profile_id to wp_mmi_vip_tax_mappings and widen its unique key to
     * include it, so a profile-specific alias row can coexist with the
     * existing global row for the same (supplier, field, value, taxonomy)
     * tuple instead of colliding on insert. The column defaults to '' (the
     * same "global" sentinel already used by this table's supplier_id
     * column), so every existing row becomes a global-fallback row with zero
     * backfill needed — see MMI_DB::resolve_tax_mapping()'s tiered lookup.
     */
    public static function maybe_run_v3_tax_mappings_profile_id(): void {
        if ( MMI_Settings::get( self::FLAG_V3_TAX_MAPPINGS_PROFILE_ID ) ) {
            return;
        }

        if ( ! class_exists( 'MMI_DB' ) ) {
            return; // mmi-hub not loaded yet — try again on a later load.
        }

        global $wpdb;
        $table = MMI_DB::tax_mappings_table();

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return; // Table not installed yet — try again on a later load.
        }

        $column_exists = $wpdb->get_var(
            "SHOW COLUMNS FROM {$table} LIKE 'profile_id'"
        );
        if ( ! $column_exists ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN profile_id varchar(100) NOT NULL DEFAULT '' AFTER supplier_id" );
        }

        // Widen the unique key so a profile-scoped row can coexist with the
        // global row for the same value — dbDelta() never alters an existing
        // index on upgrade, so this has to be done explicitly, and only once
        // (re-adding an index that already has profile_id is a no-op check,
        // not a re-run risk, but skip it cleanly either way).
        $existing_indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'src_map'" );
        $index_has_profile = false;
        foreach ( (array) $existing_indexes as $idx ) {
            if ( isset( $idx->Column_name ) && $idx->Column_name === 'profile_id' ) {
                $index_has_profile = true;
                break;
            }
        }
        if ( ! empty( $existing_indexes ) && ! $index_has_profile ) {
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX src_map" );
            $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY src_map (supplier_id, profile_id, source_field, source_value(255), wc_taxonomy)" );
        }

        MMI_Settings::set( self::FLAG_V3_TAX_MAPPINGS_PROFILE_ID, time() );
        MMI_Logger::info( 'Added wp_mmi_vip_tax_mappings.profile_id and widened src_map unique key.', [], 'general', 'MMI_Pipeline_Migration' );
    }

    /**
     * Add distribution_term_slug to wp_mmi_data_sources and backfill the
     * suppliers previously hardcoded in assign_distribution_term().
     */
    public static function maybe_run_v2_distribution_term(): void {
        if ( get_option( self::FLAG_V2_DISTRIBUTION_TERM ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'mmi_data_sources';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return; // Table not installed yet — try again on a later load.
        }

        $column_exists = $wpdb->get_var(
            "SHOW COLUMNS FROM {$table} LIKE 'distribution_term_slug'"
        );
        if ( ! $column_exists ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN distribution_term_slug varchar(20) DEFAULT NULL AFTER supplier_name" );
        }

        // Backfill the suppliers previously hardcoded in assign_distribution_term().
        $legacy_map = [ 'xchange' => 'x', 'skuport' => 's', 'plugivery' => 'p' ];
        foreach ( $legacy_map as $supplier_id => $slug ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET distribution_term_slug = %s
                     WHERE supplier_id = %s AND ( distribution_term_slug IS NULL OR distribution_term_slug = '' )",
                    $slug,
                    $supplier_id
                )
            );
        }

        update_option( self::FLAG_V2_DISTRIBUTION_TERM, time(), false );
        MMI_Logger::info( 'Added wp_mmi_data_sources.distribution_term_slug and backfilled legacy suppliers.', [], 'general', 'MMI_Pipeline_Migration' );
    }

    /**
     * Run the migration if it hasn't already completed.
     */
    public static function maybe_run(): void {
        if ( get_option( self::FLAG ) ) {
            return;
        }
        self::migrate_options();
        self::migrate_cron_hooks();
        update_option( self::FLAG, time(), false );
        MMI_Logger::info( 'mmi_vip → mmi_pipeline migration completed.', [], 'general', 'MMI_Pipeline_Migration' );
    }

    // ── Option key migration ────────────────────────────────────────────────

    /**
     * Map of old option key → new option key.
     * Only simple scalar / array options stored directly in wp_options.
     * Field-mapping and profile data are handled by MMI_DB's own table layer
     * and do not need to be moved here.
     */
    private static function option_map(): array {
        return [
            // Import rule options
            'mmi_vip_import_duplicate_strategy'   => 'mmi_pipeline_import_duplicate_strategy',
            'mmi_vip_import_price_update'          => 'mmi_pipeline_import_price_update',
            'mmi_vip_import_stock_update'          => 'mmi_pipeline_import_stock_update',
            'mmi_vip_import_image_sync'            => 'mmi_pipeline_import_image_sync',
            'mmi_vip_import_category_mapping'      => 'mmi_pipeline_import_category_mapping',
            'mmi_vip_import_email_notifications'   => 'mmi_pipeline_import_email_notifications',
            'mmi_vip_import_allow_create_products' => 'mmi_pipeline_import_allow_create_products',

            // Scheduling options
            'mmi_vip_import_schedule_enabled'      => 'mmi_pipeline_import_schedule_enabled',
            'mmi_vip_import_schedule_frequency'    => 'mmi_pipeline_import_schedule_frequency',

            // Supplier list
            'mmi_vip_enabled_suppliers'            => 'mmi_pipeline_enabled_suppliers',
        ];
    }

    private static function migrate_options(): void {
        foreach ( self::option_map() as $old => $new ) {
            // Only migrate if the old key exists AND the new key doesn't yet.
            $old_value = get_option( $old, '__NOT_SET__' );
            if ( $old_value === '__NOT_SET__' ) {
                continue;
            }
            if ( get_option( $new, '__NOT_SET__' ) === '__NOT_SET__' ) {
                add_option( $new, $old_value );
            }
            delete_option( $old );
        }

        // Per-profile allow_create_products options (dynamic key count).
        // Scan all options with the old prefix and copy them.
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'mmi_vip_import_allow_create_products_%'"
        );
        foreach ( (array) $rows as $row ) {
            $suffix  = substr( $row->option_name, strlen( 'mmi_vip_import_allow_create_products_' ) );
            $new_key = 'mmi_pipeline_import_allow_create_products_' . $suffix;
            if ( get_option( $new_key, '__NOT_SET__' ) === '__NOT_SET__' ) {
                add_option( $new_key, $row->option_value );
            }
            delete_option( $row->option_name );
        }
    }

    // ── Cron hook migration ─────────────────────────────────────────────────

    /**
     * Map of old cron hook → new cron hook.
     */
    private static function cron_map(): array {
        return [
            'mmi_vip_import_cron'   => 'mmi_pipeline_import_cron',
            'mmi_vip_update_catalog' => 'mmi_pipeline_update_catalog',
            'mmi_vip_import_batch'  => 'mmi_pipeline_import_batch',
        ];
    }

    private static function migrate_cron_hooks(): void {
        $crons = _get_cron_array();
        if ( ! is_array( $crons ) ) {
            return;
        }

        // Rename fixed hooks.
        foreach ( self::cron_map() as $old_hook => $new_hook ) {
            self::reschedule_hook( $crons, $old_hook, $new_hook );
        }

        // Rename per-profile hooks: mmi_vip_profile_* → mmi_pipeline_profile_*
        foreach ( $crons as $timestamp => $hooks ) {
            foreach ( array_keys( (array) $hooks ) as $hook ) {
                if ( strpos( $hook, 'mmi_vip_profile_' ) === 0 ) {
                    $new_hook = 'mmi_pipeline_profile_' . substr( $hook, strlen( 'mmi_vip_profile_' ) );
                    self::reschedule_hook( $crons, $hook, $new_hook );
                }
            }
        }
    }

    /**
     * Cancel a single scheduled cron event and re-register it under a new hook name,
     * preserving the original next-run timestamp and recurrence.
     */
    private static function reschedule_hook( array $crons, string $old_hook, string $new_hook ): void {
        foreach ( $crons as $timestamp => $hooks ) {
            if ( ! isset( $hooks[ $old_hook ] ) ) {
                continue;
            }
            foreach ( $hooks[ $old_hook ] as $callback_key => $event ) {
                $schedule = $event['schedule'] ?? false;
                $args     = $event['args']     ?? [];

                // Cancel old event.
                wp_unschedule_event( $timestamp, $old_hook, $args );

                // Only re-schedule if the timestamp is in the future.
                if ( $timestamp > time() ) {
                    if ( $schedule ) {
                        wp_schedule_event( $timestamp, $schedule, $new_hook, $args );
                    } else {
                        wp_schedule_single_event( $timestamp, $new_hook, $args );
                    }
                }
            }
        }
    }
}
