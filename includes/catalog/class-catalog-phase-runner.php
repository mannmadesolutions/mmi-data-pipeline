<?php
/**
 * Catalog Phase Runner
 *
 * The single owner of the catalog-update phase list and its execution —
 * shared by the AJAX "Update Store Catalog" button, the WP-Cron/Action-
 * Scheduler dispatch chain, and `wp mmi catalog`. Before this class existed,
 * the button and the schedule ran materially different code: the button ran
 * a JS-built phase list gated on toggles, while the cron ran the monolithic
 * (and now-retired) MMI_Pipeline_Catalog_Updater::run(), which honored only
 * the brand-rules toggle and never re-applied stock overrides at all. See
 * this project's AGENTS.md changelog / plan doc for the full incident list
 * this consolidation fixes.
 *
 * Phase order is deliberate, not incidental:
 *   cleanup -> canonical -> feed_sync(xN) -> override_stock -> categories -> brands
 * feed_sync's own back-in-stock SQL only ever excludes products carrying a
 * PER-PRODUCT `_mmi_stock_override = force_outofstock` meta key — it has no
 * idea that a bulk stock-override rule put a product out of stock, so
 * anything writing `outofstock` in bulk BEFORE feed_sync gets silently
 * undone by feed_sync's "restore to instock if the SKU is still in the
 * feed" pass. override_stock must therefore run AFTER every feed_sync
 * phase, not before.
 *
 * "Brand out-of-stock" rules were retired as a separate phase/row type
 * 2026-09-02 — the same result (mark every product carrying a given
 * product_brand term out of stock) is now a single override_stock condition
 * with source='wp_taxonomy', field='product_brand'. See
 * Stock_Override_Resolver::get_product_ids_by_taxonomy_condition(). There
 * were zero saved brand_oos_rules on this install at the time of removal —
 * confirmed live before deleting, not assumed — so no data migration was
 * needed.
 *
 * @package MannMade\DataPipeline\Catalog
 */

namespace MannMade\DataPipeline\Catalog;

use MMI_DB;
use MMI_Logger;
use MMI_Pipeline_Admin;
use MMI_Pipeline_Catalog_Updater;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Catalog_Phase_Runner {

    /** Setting key for the section-wide master enable/disable switch. */
    const ENABLED_SETTING_KEY = 'mmi_catalog_maintenance_enabled';

    /**
     * Whole-section kill switch, on by default. Checked at every real entry
     * point into a run — the AJAX "Update Store Catalog" button
     * (CatalogUpdateController.php's mmi_catalog_start_run), the scheduled
     * cron dispatcher (MMI_Pipeline_Cron::run_catalog_update()), and
     * `wp mmi catalog` (run_all(), below) — rather than duplicating the same
     * MMI_DB::get_setting() call independently at each call site, so a
     * future entry point can't silently forget to check it (see AGENTS.md
     * Rule 11's "one lock, every call site" reasoning, applied here to an
     * enable gate instead of a concurrency lock). Deliberately does NOT gate
     * build_phases() itself — that's still called to render the read-only
     * phase table even while disabled, so the section's UI can show what
     * WOULD run rather than going blank.
     *
     * Also unconditionally false when WooCommerce isn't active — every
     * phase this class runs (cleanup, canonical, feed_sync, override_stock,
     * categories, brands) is WooCommerce-product-specific and would fatal
     * on an undefined wc_get_product()/wc_get_products() call otherwise.
     * Folding this into the one existing gate (rather than adding a second,
     * separate check at each call site) means the AJAX button, the cron
     * dispatcher, and the UI's disabled state all stay correct together.
     */
    public static function is_enabled(): bool {
        return MMI_Pipeline_Admin::is_woocommerce_active()
            && (bool) MMI_DB::get_setting( self::ENABLED_SETTING_KEY, true );
    }

    /**
     * Distribution/feed map for the feed_sync phase. This is a fixed,
     * small set of legacy stock-reconciliation feeds — NOT the same concept
     * as wp_mmi_data_sources (the Import Profile data-source list), which
     * has no "distribution term" concept and includes upload sources (e.g.
     * one-off category/brand corrections) that this stock diff has never
     * applied to. Confirmed live: wp_mmi_data_sources today has no row for
     * either supplier below — these are pure distribution-term + feed-file
     * pairs from this class alone, independent of what's configured on the
     * Import tab.
     *
     * 'plugivery' and 'prism-sound' entries were removed 2026-09-02 —
     * confirmed dead on this install: prism-sound had no distribution term,
     * no data source, and no feed file ever; plugivery had a distribution
     * term ('p') but its expected filename never matched what
     * MMI_Pipeline_Plugivery_Updater actually writes
     * (plugivery-master-products-items.json), and neither file exists in
     * the shared JSON feed directory (mmi_shared_lib_json_dir()) regardless.
     * If Plugivery stock reconciliation is wanted again, re-add it here
     * pointed at the real output filename.
     */
    const FEEDS = [
        'xchange' => [ 'label' => 'Xchange', 'term_slug' => 'x', 'file' => 'xchange-products.json' ],
        'skuport' => [ 'label' => 'SkuPort', 'term_slug' => 's', 'file' => 'skuport-products.json' ],
    ];

    /**
     * The one copy of catalog-rules defaults. Previously duplicated,
     * independently, in tab-pipeline.php's rule-count computation,
     * CatalogUpdateController.php's $defaults, and panel-catalog-rules.php —
     * the first two disagreed by one key (apply_stock_overrides), which is
     * also now removed entirely: override_stock always runs when stock
     * override rules exist, it is no longer a separate opt-in toggle.
     */
    public static function get_rules(): array {
        $saved = MMI_DB::get_setting( 'mmi_catalog_rules', [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        $defaults = [
            'cleanup_stock_meta'      => true,
            'enforce_canonical_rules' => true,
            'auto_assign_categories'  => true,
            'auto_assign_brands'      => false,
        ];
        // Drop legacy keys from before their mechanisms were removed —
        // nothing reads either anymore. 'brand_oos_rules' (retired
        // 2026-09-02) is now expressed as an override_stock condition with
        // source='wp_taxonomy' instead of its own separate rule type.
        unset( $saved['apply_stock_overrides'], $saved['brand_oos_rules'] );
        return array_merge( $defaults, $saved );
    }

    /**
     * Build the full phase list for a run, server-side, with every gate
     * already evaluated. Disabled/inapplicable phases are returned WITH
     * skipped=true rather than omitted, so the UI can show "skipped —
     * disabled" instead of a phase silently vanishing, and so the client
     * never has to (and can no longer) evaluate a gate itself.
     *
     * @return list<array{phase:string,label:string,args:array,skipped:bool,skip_reason:string}>
     */
    public static function build_phases(): array {
        $rules  = self::get_rules();
        $phases = [];

        $phases[] = [
            'phase'       => 'cleanup',
            'label'       => 'Clean up duplicate stock metadata',
            'args'        => [],
            'skipped'     => ! $rules['cleanup_stock_meta'],
            'skip_reason' => $rules['cleanup_stock_meta'] ? '' : 'Disabled in Catalog Rules',
        ];

        $phases[] = [
            'phase'       => 'canonical',
            'label'       => 'Enforce canonical product rules',
            'args'        => [],
            'skipped'     => ! $rules['enforce_canonical_rules'],
            'skip_reason' => $rules['enforce_canonical_rules'] ? '' : 'Disabled in Catalog Rules',
        ];

        foreach ( self::FEEDS as $supplier_id => $feed ) {
            $skip_reason = self::feed_skip_reason( $feed );
            $phases[] = [
                'phase'       => 'feed_sync',
                'label'       => 'Feed sync: ' . $feed['label'],
                'args'        => [ 'supplier' => $supplier_id ],
                'skipped'     => $skip_reason !== '',
                'skip_reason' => $skip_reason,
            ];
        }

        $override_rules = MMI_DB::get_setting( 'mmi_stock_override_rules', [] );
        $has_overrides  = is_array( $override_rules ) && ! empty( $override_rules );
        $phases[] = [
            'phase'       => 'override_stock',
            'label'       => 'Re-apply bulk custom rules',
            'args'        => [],
            'skipped'     => ! $has_overrides,
            'skip_reason' => $has_overrides ? '' : 'No custom rules configured',
        ];

        $phases[] = [
            'phase'       => 'categories',
            'label'       => 'Auto-assign categories to uncategorized products',
            'args'        => [],
            'skipped'     => ! $rules['auto_assign_categories'],
            'skip_reason' => $rules['auto_assign_categories'] ? '' : 'Disabled in Catalog Rules',
        ];

        $phases[] = [
            'phase'       => 'brands',
            'label'       => 'Auto-assign brands from SKU prefix',
            'args'        => [],
            'skipped'     => ! $rules['auto_assign_brands'],
            'skip_reason' => $rules['auto_assign_brands'] ? '' : 'Disabled in Catalog Rules',
        ];

        return $phases;
    }

    /**
     * Empty string when the feed is usable; a human reason otherwise.
     * This is the fix for the confirmed-live bug where a missing/empty feed
     * file (plugivery_products_items.json has never existed) was silently
     * treated as "every product in that distribution is gone," marking all
     * 890 real Plugivery products out of stock on every run.
     */
    private static function feed_skip_reason( array $feed ): string {
        global $wpdb;

        $term_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT t.term_id FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'distribution' AND t.slug = %s",
            $feed['term_slug']
        ) );
        if ( ! $term_id ) {
            return 'No distribution term configured';
        }

        $path = mmi_shared_lib_json_dir() . $feed['file'];
        if ( ! is_readable( $path ) || filesize( $path ) === 0 ) {
            return 'Feed file missing or empty';
        }

        $raw   = json_decode( (string) file_get_contents( $path ), true );
        $items = is_array( $raw ) && isset( $raw['products'] ) && is_array( $raw['products'] )
            ? $raw['products']
            : ( is_array( $raw ) ? $raw : [] );
        if ( empty( $items ) ) {
            return 'Feed file has zero products';
        }

        return '';
    }

    /**
     * Number of catalog-update rules currently "active" — the single count
     * used both server-side (section-bar summary) and by the JS badge
     * refresh, replacing two independently-maintained formulas that used to
     * disagree by one (tab-pipeline.php's server render omitted
     * apply_stock_overrides while the JS badge counted it) — moot now since
     * that toggle was removed, but kept as one shared function so a future
     * rule addition can't reintroduce the same drift.
     */
    public static function get_active_rule_count(): int {
        $rules = self::get_rules();
        $count = (int) $rules['cleanup_stock_meta']
               + (int) $rules['enforce_canonical_rules']
               + (int) $rules['auto_assign_categories']
               + (int) $rules['auto_assign_brands'];

        $override_rules = MMI_DB::get_setting( 'mmi_stock_override_rules', [] );
        $count += count( is_array( $override_rules ) ? $override_rules : [] );

        return $count;
    }

    /**
     * Run one phase by index against the run's persisted phase list. Used by
     * both the AJAX mmi_catalog_run_phase handler (one call per HTTP
     * request) and the Action-Scheduler cron chain (one call per queued
     * action) — this is what makes the two paths literally the same code.
     *
     * @return array{phase:string,label:string,skipped:bool,message:string,items_processed:int,duration_ms:int,error:?string}
     */
    public static function run_phase( string $run_id, int $index ): array {
        $state = Catalog_Run_State::get_state( $run_id );
        if ( ! $state || ! isset( $state['phases'][ $index ] ) ) {
            return [
                'phase' => '', 'label' => '', 'skipped' => false,
                'message' => 'Unknown run or phase index.', 'items_processed' => 0,
                'duration_ms' => 0, 'error' => 'invalid_state',
            ];
        }

        $phase_def = $state['phases'][ $index ];

        // Stock snapshot before anything runs, so finalize() can tell Reverb about the products
        // whose stock status really changed. The phases write stock with raw SQL (no WC hooks),
        // and a rule-forced product flips in→out every run as feed_sync restores it and
        // override_stock re-forces it — neither write alone says whether anything changed.
        if ( 0 === $index && ! isset( $state['oos_before'] ) ) {
            $state['oos_before'] = self::out_of_stock_ids();
            Catalog_Run_State::save_state( $run_id, $state );
        }

        if ( $phase_def['skipped'] ) {
            $result = [
                'phase' => $phase_def['phase'], 'label' => $phase_def['label'], 'skipped' => true,
                'message' => $phase_def['skip_reason'], 'items_processed' => 0,
                'duration_ms' => 0, 'error' => null,
            ];
            self::record_result( $run_id, $index, $result );
            return $result;
        }

        $start   = microtime( true );
        $updater = self::get_updater( $run_id );

        try {
            [ $items_processed, $message ] = self::dispatch( $updater, $phase_def );
            $error = null;
        } catch ( \Throwable $e ) {
            $items_processed = 0;
            $message         = 'Phase failed: ' . $e->getMessage();
            $error           = $e->getMessage();
            MMI_Logger::error( "Catalog phase '{$phase_def['phase']}' failed: " . $e->getMessage(), [], 'sync', 'Catalog_Phase_Runner' );
        }

        $result = [
            'phase'           => $phase_def['phase'],
            'label'           => $phase_def['label'],
            'skipped'         => false,
            'message'         => $message,
            'items_processed' => $items_processed,
            'duration_ms'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'error'           => $error,
        ];

        self::record_result( $run_id, $index, $result );
        return $result;
    }

    /** @return array{0:int,1:string} [items_processed, message] */
    private static function dispatch( MMI_Pipeline_Catalog_Updater $updater, array $phase_def ): array {
        switch ( $phase_def['phase'] ) {
            case 'cleanup':
                $result    = $updater->cleanup_duplicate_stock_meta_public();
                $processed = $result['removed'] ?? 0;
                return [ $processed, $processed > 0 ? "{$processed} duplicate meta rows removed" : 'No duplicate meta rows found' ];

            case 'canonical':
                $result  = $updater->enforce_canonical_stock_rules_public();
                $changed = (int) ( $result['changed'] ?? 0 );
                $message = "{$changed} of {$result['targets']} non-canonical product(s) corrected";
                if ( ! empty( $result['deferred'] ) ) {
                    $message .= "; {$result['deferred']} left for the next run (time budget)";
                }
                return [ $changed, $message ];

            case 'feed_sync':
                $result    = $updater->sync_supplier_feed_public( $phase_def['args']['supplier'] ?? '' );
                $gone      = $result['gone'] ?? 0;
                $back      = $result['back'] ?? 0;
                return [ $gone + $back, "{$gone} marked out of stock, {$back} marked in stock" ];

            case 'override_stock':
                if ( ! class_exists( 'MannMade\\DataPipeline\\Stock_Override_Resolver' ) ) {
                    throw new \RuntimeException( 'Stock_Override_Resolver class not available.' );
                }
                $override_rules = MMI_DB::get_setting( 'mmi_stock_override_rules', [] );
                $result         = \MannMade\DataPipeline\Stock_Override_Resolver::apply_to_catalog( (array) $override_rules );
                $processed      = $result['changed'] ?? 0;
                return [ $processed, "{$processed} product(s) stock status updated via override rules" ];

            case 'categories':
                $result    = $updater->assign_categories_comprehensive( 'uncategorized', true, 100 );
                if ( ! empty( $result['skipped'] ) ) {
                    return [ 0, 'Skipped: the category scanner isn’t installed on this site' ];
                }
                $processed = $result['assigned'] ?? 0;
                return [ $processed, "{$processed} product(s) assigned to categories" ];

            case 'brands':
                $result    = $updater->assign_brands_from_sku_prefix( 'all', true, 200 );
                if ( ! empty( $result['skipped'] ) ) {
                    return [ 0, 'Skipped: the brand scanner isn’t installed on this site' ];
                }
                $processed = $result['assigned'] ?? 0;
                return [ $processed, "{$processed} product(s) assigned a brand" ];

            default:
                throw new \RuntimeException( "Unknown phase: {$phase_def['phase']}" );
        }
    }

    /**
     * One MMI_Pipeline_Catalog_Updater instance per run, memoized statically
     * so run_phase()'s per-request calls (AJAX: one per HTTP request; AS
     * chain: one per queued action) still share get_associated_sku_skiplist()'s
     * memoization within a single PHP process — and so run_all() (one
     * process, all phases) only builds the updater once.
     */
    private static array $updater_cache = [];

    private static function get_updater( string $run_id ): MMI_Pipeline_Catalog_Updater {
        if ( ! isset( self::$updater_cache[ $run_id ] ) ) {
            self::$updater_cache[ $run_id ] = new MMI_Pipeline_Catalog_Updater();
        }
        return self::$updater_cache[ $run_id ];
    }

    private static function record_result( string $run_id, int $index, array $result ): void {
        $state = Catalog_Run_State::get_state( $run_id );
        if ( ! $state ) {
            return;
        }
        $state['results'][ $index ] = $result;
        $state['current_index']     = $index + 1;
        if ( ! $result['skipped'] ) {
            $state['total_processed'] += $result['items_processed'];
        }
        Catalog_Run_State::save_state( $run_id, $state );
    }

    /**
     * Whole sequence, one PHP process. WP-CLI only — AJAX and cron both run
     * one phase per request/action via run_phase(), since a full sequence
     * can exceed both a web request's practical limit and wp-cron.php's 45s
     * execution ceiling (see AGENTS.md Operational Continuity Rule 12).
     */
    public static function run_all( string $owner = 'cli' ): array {
        if ( ! self::is_enabled() ) {
            throw new \RuntimeException( 'Catalog Maintenance is disabled (see the section toggle on the Import tab).' );
        }

        $run_id = Catalog_Run_State::try_acquire( $owner );
        if ( $run_id === null ) {
            $lock = Catalog_Run_State::get_lock_row();
            throw new \RuntimeException( 'A catalog update is already running (started ' . ( $lock['started_at'] ?? 'unknown' ) . ' by ' . ( $lock['owner'] ?? 'unknown' ) . ').' );
        }

        $phases = self::build_phases();
        Catalog_Run_State::save_state( $run_id, [
            'phases'          => $phases,
            'results'         => [],
            'current_index'   => 0,
            'total_processed' => 0,
            'started_at'      => current_time( 'mysql' ),
        ] );

        try {
            foreach ( $phases as $index => $phase_def ) {
                self::run_phase( $run_id, $index );
                Catalog_Run_State::reacquire( $run_id ); // heartbeat between phases
            }
            return self::finalize( $run_id );
        } finally {
            Catalog_Run_State::release( $run_id );
            unset( self::$updater_cache[ $run_id ] );
        }
    }

    /**
     * Lifecycle close-out — deliberately NOT one of the phases in
     * build_phases()'s returned list, so a client can't skip straight to it
     * and leak the lock without every real phase having run.
     *
     * @return array{total_processed:int,duration_ms:int,phases:array}
     */
    /**
     * Is Catalog Maintenance actually keeping up? One owner for the badge,
     * the section-header alert and the notice (panel-catalog-maintenance.php).
     * Two cheap queries; phase labels come from the caller's already-built
     * phase list (build_phases() reads the feed files, too heavy to repeat).
     *
     * A run's phases execute as a chain of Action Scheduler actions, so a
     * phase that dies on a PHP fatal/timeout never reaches finalize() and
     * never records anything itself — the failed AS action is the evidence
     * (this is how every scheduled run since at least 2026-09-06 failed
     * silently at "Feed sync: Xchange" while the badge said "3 weeks ago").
     *
     * @param array $phases build_phases() output, for labels.
     * @return array{level:string,text:string,detail:string}
     *         level: ok | disabled | warning | error
     */
    public static function health( array $phases = [] ): array {
        global $wpdb;
        $freq      = (string) MMI_DB::get_setting( 'mmi_schedule_catalog_update', 'sixhourly' );
        $last      = (string) MMI_DB::get_setting( 'mmi_last_run_catalog_update', '' );
        $last_ts   = $last !== '' ? strtotime( get_gmt_from_date( $last ) ) : 0;
        $interval  = (int) ( wp_get_schedules()[ $freq ]['interval'] ?? 6 * HOUR_IN_SECONDS );
        $labels    = array_column( $phases, 'label' );

        $failed = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) n, MAX(action_id) last_id FROM {$wpdb->prefix}actionscheduler_actions
             WHERE hook = %s AND status = 'failed' AND last_attempt_gmt > %s",
            'mmi_pipeline_catalog_phase',
            gmdate( 'Y-m-d H:i:s', max( $last_ts, time() - 2 * DAY_IN_SECONDS ) )
        ), ARRAY_A );
        if ( ! empty( $failed['n'] ) ) {
            $args  = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT args FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id = %d", (int) $failed['last_id'] ) ), true );
            $label = $labels[ (int) ( $args[1] ?? -1 ) ] ?? 'a phase';
            $why   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT message FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id = %d ORDER BY log_id DESC LIMIT 1", (int) $failed['last_id'] ) );
            return [
                'level'  => 'error',
                'text'   => 'Failing',
                'detail' => sprintf( 'Scheduled runs are stopping at "%s" (%d failed since the last complete run) — the operations after it are not running. Last error: %s', $label, (int) $failed['n'], mb_substr( $why, 0, 200 ) ),
            ];
        }
        if ( 'disabled' === $freq ) {
            return [ 'level' => 'disabled', 'text' => 'Schedule off', 'detail' => 'The Catalog Maintenance schedule is disabled (Schedules panel). It only runs when "Update Store Catalog" is clicked.' ];
        }
        if ( $last_ts === 0 || time() - $last_ts > 2 * $interval + HOUR_IN_SECONDS ) {
            return [
                'level'  => 'error',
                'text'   => 'Overdue',
                'detail' => $last_ts ? sprintf( 'No complete run for %s — the schedule expects one every %s.', human_time_diff( $last_ts ), human_time_diff( 0, $interval ) ) : 'Catalog Maintenance has never completed a run.',
            ];
        }
        $errored = array_filter( (array) MMI_DB::get_setting( 'mmi_catalog_last_run_errors', [] ) );
        if ( $errored ) {
            return [ 'level' => 'warning', 'text' => 'Finished with errors', 'detail' => 'The last run finished, but these operations reported an error: ' . implode( ', ', $errored ) . '. See the sync log.' ];
        }
        return [ 'level' => 'ok', 'text' => '', 'detail' => '' ];
    }

    /**
     * Bring WooCommerce's derived stock data in line with each product's own
     * `_stock_status`. The phases write stock with raw SQL (fast, no WC hooks),
     * which never touched `wc_product_meta_lookup.stock_status` or the
     * `product_visibility` "outofstock" term — and with "Hide out of stock
     * items" on, that term is what hides a product. Found 2026-09-28: 1,615
     * out-of-stock products still showing, 143 in-stock products hidden,
     * 138 lookup rows saying in stock.
     *
     * Bulk SQL for only the mismatched rows, then one term recount and a
     * cache clean; safe inside a 60 s Action Scheduler action.
     *
     * @return array{term_added:int,term_removed:int,lookup_fixed:int}
     */
    public static function reconcile_stock_visibility(): array {
        global $wpdb;
        $out = [ 'term_added' => 0, 'term_removed' => 0, 'lookup_fixed' => 0 ];
        $tt  = (int) $wpdb->get_var(
            "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'product_visibility' AND t.slug = 'outofstock'"
        );
        $has_term = "SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = {$tt}";
        $touched  = [];
        if ( $tt ) {
            $add = array_map( 'intval', $wpdb->get_col(
                "SELECT p.ID FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock_status' AND st.meta_value = 'outofstock'
                 WHERE p.post_type = 'product' AND p.ID NOT IN ({$has_term})"
            ) );
            $remove = array_map( 'intval', $wpdb->get_col(
                "SELECT p.ID FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock_status' AND st.meta_value IN ('instock','onbackorder')
                 WHERE p.post_type = 'product' AND p.ID IN ({$has_term})"
            ) );
            foreach ( array_chunk( $add, 500 ) as $chunk ) {
                $values = implode( ',', array_map( static fn( $id ) => "({$id},{$tt},0)", $chunk ) );
                $out['term_added'] += (int) $wpdb->query( "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) VALUES {$values}" );
            }
            foreach ( array_chunk( $remove, 500 ) as $chunk ) {
                $out['term_removed'] += (int) $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = {$tt} AND object_id IN (" . implode( ',', $chunk ) . ')' );
            }
            $touched = array_merge( $add, $remove );
            if ( $touched ) {
                wp_update_term_count_now( [ $tt ], 'product_visibility' );
            }
        }
        $lookup_ids = array_map( 'intval', $wpdb->get_col(
            "SELECT l.product_id FROM {$wpdb->prefix}wc_product_meta_lookup l
             JOIN {$wpdb->postmeta} st ON st.post_id = l.product_id AND st.meta_key = '_stock_status'
             WHERE l.stock_status <> st.meta_value"
        ) );
        if ( $lookup_ids ) {
            $out['lookup_fixed'] = (int) $wpdb->query(
                "UPDATE {$wpdb->prefix}wc_product_meta_lookup l
                 JOIN {$wpdb->postmeta} st ON st.post_id = l.product_id AND st.meta_key = '_stock_status'
                 SET l.stock_status = st.meta_value
                 WHERE l.stock_status <> st.meta_value"
            );
        }
        $touched = array_values( array_unique( array_merge( $touched, $lookup_ids ) ) );
        if ( $touched ) {
            clean_object_term_cache( $touched, 'product' );
            foreach ( $touched as $id ) {
                wc_delete_product_transients( $id );
            }
        }
        return $out;
    }

    /** @return int[] published products currently out of stock (one indexed query). */
    private static function out_of_stock_ids(): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' AND p.post_status = 'publish'
             WHERE pm.meta_key = '_stock_status' AND pm.meta_value = 'outofstock'"
        ) );
    }

    public static function finalize( string $run_id ): array {
        $state = Catalog_Run_State::get_state( $run_id );
        if ( ! $state ) {
            return [ 'total_processed' => 0, 'duration_ms' => 0, 'phases' => [] ];
        }

        $duration_ms = 0;
        if ( ! empty( $state['started_at'] ) ) {
            $duration_ms = (int) round( ( time() - strtotime( $state['started_at'] ) ) * 1000 );
        }

        $visibility = self::reconcile_stock_visibility();
        MMI_Logger::info( 'Catalog update stock visibility reconciled: ' . wp_json_encode( $visibility ), [], 'sync', 'Catalog_Phase_Runner' );

        if ( isset( $state['oos_before'] ) && function_exists( 'mmi_reverb_queue_resync' ) ) {
            $before  = array_flip( array_map( 'intval', (array) $state['oos_before'] ) );
            $after   = array_flip( self::out_of_stock_ids() );
            $changed = array_keys( array_diff_key( $before, $after ) + array_diff_key( $after, $before ) );
            if ( $changed ) {
                mmi_reverb_queue_resync( $changed );
            }
            MMI_Logger::info( 'Catalog update stock changes this run: ' . count( $changed ) . ' product(s) queued for Reverb resync', [], 'sync', 'Catalog_Phase_Runner' );
        }

        // Phases that caught an exception still let the run finish; keep their names for the health badge.
        $errored = [];
        foreach ( (array) ( $state['results'] ?? [] ) as $r ) {
            if ( ! empty( $r['error'] ) ) {
                $errored[] = (string) ( $r['label'] ?? '' );
            }
        }
        MMI_DB::set_setting( 'mmi_catalog_last_run_errors', $errored );

        MMI_DB::set_setting( 'mmi_last_run_catalog_update', current_time( 'mysql' ) );
        MMI_DB::set_setting( 'mmi_catalog_update_duration', round( $duration_ms / 1000, 2 ) . 's' );
        MMI_DB::set_setting( 'mmi_catalog_update_products_processed', $state['total_processed'] ?? 0 );

        MMI_Logger::info(
            "Catalog update complete run_id={$run_id} processed=" . ( $state['total_processed'] ?? 0 ) . " duration={$duration_ms}ms",
            [], 'sync', 'Catalog_Phase_Runner'
        );

        $out = [
            'total_processed' => $state['total_processed'] ?? 0,
            'duration_ms'     => $duration_ms,
            'phases'          => $state['results'] ?? [],
        ];

        Catalog_Run_State::delete_state( $run_id );
        unset( self::$updater_cache[ $run_id ] );

        return $out;
    }
}
