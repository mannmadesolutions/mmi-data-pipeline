<?php
/**
 * Pipeline Cron — Scheduled Supplier Fetch & Catalog Import
 *
 * Owns all cron hook bindings, scheduled-run logic, and notification emails
 * for the Import Pipeline. Self-contained cron handlers for mmi-data-pipeline.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Cron {

    /* ── Per-profile batch sizing ─────────────────────────────────────────── */

    /**
     * Max items per batch. Belt-and-suspenders alongside PROFILE_BATCH_MEMORY_BUDGET —
     * caps the worst case even if a particular catalog's per-item memory cost is
     * unusually low (so the memory check wouldn't trip until far more items had
     * already accumulated other state).
     */
    const PROFILE_BATCH_ITEM_LIMIT = 300;

    /** Wall-clock cap per batch (seconds) — see run_profile_import_batch(). */
    const PROFILE_BATCH_TIME_BUDGET = 25.0;

    /**
     * Memory cap per batch, in bytes (200 MB). The cron runner's PHP process is
     * capped at 256 MB (scripts/wp-cron.php sets memory_limit to 256M); this
     * leaves ~56 MB of headroom for the rest of the request after a batch stops
     * itself. This is the actual fix for the bug where the xchange supplier
     * (4,820 products) fataled with "Allowed memory size of 268435456 bytes
     * exhausted" every single hour for 3+ days while skuport (636 products,
     * never hit this ceiling) always completed — a time budget alone doesn't
     * help when memory grows faster than the clock runs out.
     */
    const PROFILE_BATCH_MEMORY_BUDGET = 200 * 1024 * 1024;

    /**
     * TTL for the per-profile import lock (mmi_profile_import_lock_{profile_id}).
     *
     * This lock is renewed on EVERY batch tick in run_profile_import_batch(), not
     * just once at dispatch — so this only needs to bridge the gap between two
     * consecutive ~25s batches (Action Scheduler re-queue latency), not survive the
     * whole multi-minute-to-hour run. It was previously a single 3300s (55 min)
     * TTL set once at dispatch and never renewed, sized to "hope the whole job
     * finishes before the next hourly cron tick." Observed real runs range from
     * under a minute to 59m49s — close enough to that 55-minute ceiling that a
     * legitimately slow (not stuck) run had its lock expire mid-flight, letting
     * the next hourly tick dispatch a SECOND concurrent batch chain for the same
     * profile. Both chains then raced on the same state record: whichever
     * finished first called finish_profile_import() and deleted it, so the
     * other chain's next batch hit "missing state record, aborting batch" and
     * the final counts were corrupted by the interleaved read-modify-write on
     * the shared state. See run_scheduled_profile_import()'s abandoned-run
     * check (90 min) for the actual "this run is dead" detection — that is
     * intentionally much larger than this TTL.
     */
    const PROFILE_BATCH_LOCK_TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * Site-wide past-due Action Scheduler pending-action count above which a
     * profile-import failure alert should name the backlog as the likely root
     * cause. See get_action_scheduler_backlog()'s docblock — chosen well above
     * normal churn (a healthy site briefly has a handful of past-due actions
     * between runner ticks) so this only fires for a genuine pile-up.
     */
    const AS_BACKLOG_ALERT_THRESHOLD = 500;

    /* ── Boot ─────────────────────────────────────────────────────────────── */

    /**
     * Sources whose fetch runs as a chain of short Action Scheduler steps,
     * one feed request per step. The scheduled run used to do all of them in
     * one WP-Cron callback, and the cron runner (scripts/wp-cron.php under
     * `timeout 45`) killed it partway through whenever vendors + products +
     * promotions with their 12 s XChange gaps passed 45 s — 2026-10-05 20:35
     * saved products, died in the wait before promotions, and never recorded
     * the run.
     */
    const STEPPED_SOURCES = [
        'xchange' => [ 'vendors', 'products', 'promotions' ],
    ];
    const STEP_HOOK  = 'mmi_pipeline_source_fetch_step';
    const STEP_GROUP = 'mmi-pipeline-source-fetch';
    /** Seconds between steps — XChange allows one request per 12 s. */
    const STEP_GAP = 13;

    public static function init(): void {
        add_action( self::STEP_HOOK, [ __CLASS__, 'run_source_fetch_step' ], 10, 3 );

        // Per-source fetch handlers — register a handler for every schedulable
        // source's OWN cron hook (mirrors the per-profile loop below) so each
        // source can run on its own independent cadence instead of one shared
        // mmi_scheduled_supplier_fetch tick for everything at once.
        foreach ( self::schedulable_source_ids() as $sid ) {
            add_action( 'mmi_pipeline_source_fetch_' . $sid, static function () use ( $sid ) {
                self::run_scheduled_source_fetch( $sid );
            } );
        }
        // Retired unified hook — clear so a stale scheduled event (from
        // before this per-source migration) doesn't keep firing with no
        // handler bound to it.
        wp_clear_scheduled_hook( 'mmi_scheduled_supplier_fetch' );

        // mmi_scheduled_product_import / mmi_pipeline_import_cron used to run
        // run_scheduled_catalog_import() — a pre-Import-Profiles legacy path that
        // bypassed the profile system entirely (ignored import_mode/create-only
        // gating, used raw 'default' field mappings instead of get_effective(),
        // never applied taxonomy mappings, and had no batching/memory budget).
        // Its set_product_field() fallback also wrote 'post_title'/'post_content'
        // as literal postmeta keys instead of calling set_name()/set_description()
        // (no explicit setter-name override for those two fields), so every
        // product it created got WooCommerce's default post_title of "Product"
        // with the real title/description sitting inert in postmeta. Confirmed
        // responsible for 463 malformed "Product"-titled products created over
        // 2026-06-22 through 2026-08-03 via this still-active 12-hour cron.
        // Deliberately NOT re-bound below — creation/update is now handled
        // exclusively by the profile-based system (New Products / Pricing
        // profiles), which gates on import_mode, uses get_effective() field
        // mappings, applies taxonomy mappings, and batches via Action Scheduler.
        wp_clear_scheduled_hook( 'mmi_scheduled_product_import' );
        wp_clear_scheduled_hook( 'mmi_pipeline_import_cron' );

        // Daily digest email (fires once per day at ~7am site time)
        add_action( 'mmi_daily_email_digest', [ __CLASS__, 'run_daily_digest' ] );
        self::maybe_schedule_daily_digest();

        // Per-profile import handlers — register a handler for every profile's cron hook
        // so that mmi_pipeline_profile_{id} events actually execute their import instead
        // of firing with no handler and silently no-oping.
        $profiles = MMI_DB::get_profiles();
        foreach ( array_keys( (array) $profiles ) as $profile_id ) {
            add_action( 'mmi_pipeline_profile_' . $profile_id, static function () use ( $profile_id ) {
                self::run_scheduled_profile_import( $profile_id );
            } );

            // Clear the legacy mmi_vip_profile_{id} hook that was previously scheduled
            // by an older version of the pipeline. It has no handler and fires as a
            // silent no-op every hour, wasting a cron slot.
            wp_clear_scheduled_hook( 'mmi_vip_profile_' . $profile_id );
        }

        // Per-profile import batches — see run_profile_import_batch(). Action Scheduler
        // (not WP-Cron) drives these so a slow batch can't be killed by the wp-cron.php
        // runner's hard per-cycle timeout; the dispatcher (run_scheduled_profile_import())
        // only ever queues these, it never does the import work itself.
        add_action( 'mmi_pipeline_profile_import_batch', [ __CLASS__, 'run_profile_import_batch' ], 10, 3 );

        // Custom schedule intervals
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedules' ] );

        // Register this plugin's email samples with MMI VIP's Email Customization
        // tab preview picker — see register_email_samples()'s docblock.
        add_filter( 'mmi_email_customizer_samples', [ __CLASS__, 'register_email_samples' ] );

        // Clear the orphaned mmi_pipeline_update_catalog event that was independently scheduled
        // by the old UI but had no registered handler — silently no-oped every hour.
        // Catalog Update is now driven exclusively by the post-fetch trigger below.
        wp_clear_scheduled_hook( 'mmi_pipeline_update_catalog' );

        // Catalog Updater — fires opportunistically 5 minutes after a successful
        // supplier fetch (see run_scheduled_source_fetch()'s post-fetch trigger)
        // and on a routine recurring schedule (registered below), same handler
        // either way. Previously lived on the retired MMI_Product_Importer_UI
        // legacy class; consolidated here since this class already owns both the
        // post-fetch scheduling side of this exact hook and all other cron
        // registrations for this plugin.
        add_action( 'mmi_scheduled_catalog_update', [ __CLASS__, 'run_catalog_update' ] );
        // Frequency is set in the Schedules panel (process 'catalog_update', default every
        // 6 hours; see mmi_pipeline_apply_schedule()). "Disabled" means no recurring run.
        $catalog_freq = (string) MMI_DB::get_setting( 'mmi_schedule_catalog_update', 'sixhourly' );
        if ( 'disabled' !== $catalog_freq && ! wp_get_schedule( 'mmi_scheduled_catalog_update' ) ) {
            wp_schedule_event( self::compute_schedule_anchor( 'catalog_update', $catalog_freq ), $catalog_freq, 'mmi_scheduled_catalog_update' );
        }

        // Per-phase Action Scheduler chain for the catalog update dispatcher
        // above — see run_catalog_phase()'s docblock.
        add_action( 'mmi_pipeline_catalog_phase', [ __CLASS__, 'run_catalog_phase' ], 10, 2 );
    }

    /**
     * Schedule the daily digest at 7 am site time if not already queued.
     * Called on every init() so that a cleared event is re-created automatically.
     */
    public static function maybe_schedule_daily_digest(): void {
        if ( wp_next_scheduled( 'mmi_daily_email_digest' ) ) {
            return;
        }
        try {
            $tz     = wp_timezone();
            $now    = new \DateTime( 'now', $tz );
            $target = new \DateTime( 'today 07:00:00', $tz );
            if ( $now >= $target ) {
                $target->modify( '+1 day' );
            }
            wp_schedule_event( $target->getTimestamp(), 'daily', 'mmi_daily_email_digest' );
        } catch ( \Exception $e ) {
            // Scheduling is non-critical; log and continue.
            MMI_Logger::warn( 'Could not schedule daily digest: ' . $e->getMessage(), [], 'sync', 'MMI_Pipeline_Cron' );
        }
    }

    /* ── Custom schedules ─────────────────────────────────────────────────── */

    public static function add_cron_schedules( array $schedules ): array {
        $schedules['fourhourly'] = $schedules['fourhourly'] ?? [
            'interval' => 4 * HOUR_IN_SECONDS,
            'display'  => 'Every 4 Hours',
        ];
        $schedules['sixhourly'] = $schedules['sixhourly'] ?? [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'Every 6 Hours',
        ];
        return $schedules;
    }

    /**
     * Soonest upcoming run across every schedule the pipeline owns — Data
     * Fetch, every Import profile, and every Export profile. Each of those
     * lives in its own tab-scoped Schedules panel (see tab-pipeline.php /
     * tab-export.php), so a user landing on Taxonomy Mapping or Duplicate
     * Products has no way to see this. This is the single source the page
     * header (main.php) reads to surface a "Next scheduled run" link that's
     * visible from every sub-tab, pointing at whichever tab actually owns it.
     *
     * @return array{label:string, process:string, tab:string}|null Null when nothing is scheduled.
     */
    public static function get_next_scheduled_summary(): ?array {
        $candidates = [];

        foreach ( self::get_schedulable_sources() as $src ) {
            if ( $src['frequency'] === 'disabled' || ! $src['next'] ) {
                continue;
            }
            $candidates[] = [ 'next' => $src['next'], 'process' => 'Data Fetch: ' . $src['name'], 'tab' => 'import' ];
        }

        foreach ( MMI_DB::get_profiles_by_direction( 'import' ) as $profile_id => $profile_data ) {
            if ( MMI_DB::get_setting( 'mmi_schedule_profile_' . $profile_id, 'disabled' ) === 'disabled' ) {
                continue;
            }
            $next = wp_next_scheduled( 'mmi_pipeline_profile_' . $profile_id );
            if ( $next ) {
                $candidates[] = [ 'next' => $next, 'process' => $profile_data['name'] ?? $profile_id, 'tab' => 'import' ];
            }
        }

        foreach ( MMI_DB::get_profiles_by_direction( 'export' ) as $profile_id => $profile_data ) {
            if ( MMI_DB::get_setting( 'mmi_schedule_export_profile_' . $profile_id, 'disabled' ) === 'disabled' ) {
                continue;
            }
            $next = wp_next_scheduled( 'mmi_pipeline_export_profile_' . $profile_id );
            if ( $next ) {
                $candidates[] = [ 'next' => $next, 'process' => $profile_data['name'] ?? $profile_id, 'tab' => 'export' ];
            }
        }

        if ( empty( $candidates ) ) {
            return null;
        }

        usort( $candidates, static fn( $a, $b ) => $a['next'] <=> $b['next'] );
        $soonest = $candidates[0];

        return [
            'label'   => human_time_diff( $soonest['next'], current_time( 'timestamp' ) ) . ' from now',
            'process' => $soonest['process'],
            'tab'     => $soonest['tab'],
        ];
    }

    /**
     * Site-wide Action Scheduler backlog — pending actions past their scheduled
     * time, regardless of which plugin queued them.
     *
     * Every scheduled profile import's own batch actions (mmi_pipeline_profile_
     * import_batch) run through the SAME shared Action Scheduler queue as every
     * other plugin's AS actions. When that queue is badly backlogged, a fresh
     * batch action can sit behind thousands of older, unrelated ones and never
     * get a turn — which looks identical, from this plugin's side, to "the
     * import is broken" (a stale profile, an abandoned run). Confirmed live on
     * this site 2026-08-08: `mmi_pipeline_profile_import_batch` had 72 past-due
     * actions of its own, queued behind ~8,700 total past-due actions site-wide,
     * ~6,700 of them a single mmi-reverb-integration hook
     * (mmi_reverb_as_import_order_batch) — see AGENTS.md's "Reverb Sync Backlog"
     * incident for the same failure family. Called by the alert paths in
     * check_stale_profile_imports() and run_scheduled_profile_import()'s
     * abandoned-run detector so the resulting email names the actual likely
     * cause instead of generic "a process was killed" boilerplate that sends
     * someone looking for a bug in the wrong plugin.
     *
     * @return array{total:int, top_hook:string, top_hook_count:int}|null Null when the backlog isn't large enough to be a likely root cause.
     */
    public static function get_action_scheduler_backlog(): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'actionscheduler_actions';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return null;
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND scheduled_date_gmt < UTC_TIMESTAMP()"
        );

        if ( $total < self::AS_BACKLOG_ALERT_THRESHOLD ) {
            return null;
        }

        $top = $wpdb->get_row(
            "SELECT hook, COUNT(*) as cnt FROM {$table} WHERE status = 'pending' AND scheduled_date_gmt < UTC_TIMESTAMP() GROUP BY hook ORDER BY cnt DESC LIMIT 1",
            ARRAY_A
        );

        return [
            'total'          => $total,
            'top_hook'       => $top['hook'] ?? 'unknown',
            'top_hook_count' => (int) ( $top['cnt'] ?? 0 ),
        ];
    }

    /**
     * Human-readable sentence naming the Action Scheduler backlog as a likely
     * root cause — appended to an alert's error message, and mirrored into the
     * MMI_Logger context so the correlation is visible in sync.log too, not
     * only in the email (a log-only reader shouldn't have to open their inbox
     * to learn that a "broken" profile is actually queue starvation).
     */
    private static function describe_as_backlog( array $backlog ): string {
        return sprintf(
            ' LIKELY ROOT CAUSE: Action Scheduler has %s pending action(s) overdue site-wide (mostly "%s", %s of them) — this profile\'s own batch actions are almost certainly queued behind that backlog rather than failing on their own. Check Tools > Scheduled Actions before assuming this profile itself is broken.',
            number_format( $backlog['total'] ),
            $backlog['top_hook'],
            number_format( $backlog['top_hook_count'] )
        );
    }

    /* ── Scheduled Source Fetch (per-source, independent schedules) ───────── */

    /**
     * Every wp_mmi_data_sources supplier_id eligible for its own fetch
     * schedule — validated, non-upload rows, plus any legacy supplier (see
     * MMI_Pipeline_Supplier_Fetch_Runner::SUPPORTED_SUPPLIERS) enabled via the older
     * mmi_vip_enabled_suppliers setting but never migrated into this table.
     * Upload sources are excluded: they're static files with no live
     * endpoint to refresh on a schedule.
     *
     * Cheap, no self-heal/writes — used by init() to register a cron handler
     * for every eligible hook on every request. See get_schedulable_sources()
     * for the richer, display-ready version (name, frequency, next-run).
     *
     * @return string[]
     */
    private static function schedulable_source_ids(): array {
        global $wpdb;
        $ds_table = $wpdb->prefix . 'mmi_data_sources';

        $ids = [];
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) === $ds_table ) {
            $ids = $wpdb->get_col( "SELECT supplier_id FROM {$ds_table} WHERE config_status = 'validated' AND source_type != 'upload'" );
        }

        $legacy_class = 'MMI_Pipeline_Supplier_Fetch_Runner';
        if ( class_exists( $legacy_class ) ) {
            $legacy_enabled = (array) MMI_Settings::get( 'mmi_vip_enabled_suppliers', [] );
            foreach ( $legacy_class::SUPPORTED_SUPPLIERS as $sid ) {
                if ( ! in_array( $sid, $ids, true ) && in_array( $sid, $legacy_enabled, true ) ) {
                    $ids[] = $sid;
                }
            }
        }

        return $ids;
    }

    /**
     * Next UTC timestamp a process's schedule should anchor to, honoring an
     * admin-chosen site-local time-of-day (mmi_schedule_time_{process}, "HH:MM"
     * 24h — set from the Schedules panel's time picker) when one exists, so a
     * "Twice Daily" schedule fires at (say) 6:00 AM and 6:00 PM site time
     * instead of drifting from whatever moment the dropdown was last saved —
     * and so different jobs can be deliberately staggered to spread out
     * server load instead of all landing on the same moment.
     *
     * Falls back to right now when no time has been chosen, which is exactly
     * this schedule's behavior from before time-of-day selection existed
     * (the same time()-anchored wp_schedule_event() every schedule already
     * used) — picking a time is opt-in, not a forced migration.
     *
     * Public and self-contained (no dependency on ImportSettingsController.php,
     * loaded later in this plugin's own bootstrap — see apply_source_schedule()'s
     * docblock for why that ordering matters) so it's safely callable from
     * mmi_pipeline_apply_schedule() (AJAX-time, always fully bootstrapped) and
     * from tab-pipeline.php's profile self-heal loop (view-render time), not
     * just from this class's own self-heal below.
     *
     * A saved time-of-day only makes sense as an anchor for a once-or-twice-a-day
     * cadence — applying it to a sub-daily frequency (currently just 'hourly')
     * would anchor the very first run up to 24h out (e.g. a 6 PM anchor picked
     * for an old "Twice Daily" schedule, still on file when the user switches
     * to "Every Hour"), which looks indistinguishable from the Next Run column
     * not having recalculated at all. So when $frequency's own registered
     * interval is under a day, the time-of-day is ignored and this always
     * anchors to right now instead — see the Schedules Panel Next Run incident
     * in incident-history.md for the report this fixed.
     *
     * @param string $process   e.g. 'source_fetch_xchange', 'profile_pricing'.
     * @param string $frequency Optional wp_get_schedules() key (e.g. 'hourly',
     *                          'daily') for the schedule being anchored. Omit
     *                          only when the caller doesn't have it handy —
     *                          every call site above does.
     */
    public static function compute_schedule_anchor( string $process, string $frequency = '' ): int {
        $time_of_day = MMI_DB::get_setting( 'mmi_schedule_time_' . $process, '' );
        $has_time    = is_string( $time_of_day ) && preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time_of_day, $time_parts );

        if ( '' !== $frequency ) {
            $schedules = wp_get_schedules();
            $interval  = $schedules[ $frequency ]['interval'] ?? null;

            // Exactly 'hourly' (not any other sub-daily interval — see below)
            // gets its own anchor rule: only the MINUTE component of the saved
            // time-of-day is meaningful here (which hour doesn't matter, the
            // job repeats every hour), so this anchors to the next occurrence
            // of that minute — never more than 60 minutes out. Deliberately
            // does NOT fall into the generic full-HH:MM anchor path below,
            // which is what the pre-existing "ignore time-of-day for any
            // sub-daily frequency" rule (next branch) was written to avoid:
            // a stale HH:MM saved while this process was still a once/twice-
            // daily schedule could otherwise anchor the very first hourly run
            // up to 24h out. Capping the lookahead at one hour by construction
            // makes that failure mode impossible regardless of what HH was
            // last saved, so reusing the same mmi_schedule_time_{process}
            // setting here (rather than a second, separate minute-only
            // setting) is safe.
            if ( $interval === HOUR_IN_SECONDS ) {
                if ( ! $has_time ) {
                    return time();
                }
                try {
                    $tz     = wp_timezone();
                    $now    = new \DateTime( 'now', $tz );
                    $target = clone $now;
                    $target->setTime( (int) $now->format( 'H' ), (int) $time_parts[2], 0 );
                    if ( $target <= $now ) {
                        $target->modify( '+1 hour' );
                    }
                    return $target->getTimestamp();
                } catch ( \Exception $e ) {
                    return time();
                }
            }

            // Any other sub-daily interval (currently none of the frequencies
            // offered for source/profile schedules, but kept as-is for
            // anything registered via add_cron_schedules()): time-of-day
            // stays ignored, unchanged from the original behavior this
            // method has always had here.
            if ( is_int( $interval ) && $interval < DAY_IN_SECONDS ) {
                return time();
            }
        }

        if ( ! $has_time ) {
            return time();
        }

        try {
            $tz     = wp_timezone();
            $now    = new \DateTime( 'now', $tz );
            $target = new \DateTime( 'today ' . $time_of_day . ':00', $tz );
            if ( $now >= $target ) {
                $target->modify( '+1 day' );
            }
            return $target->getTimestamp();
        } catch ( \Exception $e ) {
            return time();
        }
    }

    /**
     * Self-heals one process's WP-Cron registration against its saved
     * frequency: (re)registers it if missing entirely, or if it's already
     * scheduled to fire further out than its own frequency's interval
     * allows. That second case is what actually catches a stale anchor —
     * e.g. an 'hourly' schedule that was originally registered while
     * compute_schedule_anchor() still anchored every frequency to a saved
     * time-of-day (fixed 2026-09-18, see incident-history.md), leaving a
     * real WP-Cron event on the books whose `next_run` sits many hours past
     * where an hourly job should ever be. The 2026-09-18 fix to
     * compute_schedule_anchor() only corrected the calculation for schedules
     * created or edited *after* that fix shipped — it could not retroactively
     * fix an already-scheduled event, since nothing re-reads or rewrites a
     * live wp_cron entry just because the code that would have anchored it
     * differently changed. This is that missing piece: called from the same
     * render-time self-heal spots that already handled "event missing
     * entirely" (get_schedulable_sources() below and tab-pipeline.php's
     * per-profile loop), so a stale existing anchor gets corrected the next
     * time the Schedules panel renders, with no manual re-save required.
     *
     * A no-op when the schedule is already correctly registered — every
     * healthy schedule's next run is by definition no more than one interval
     * away from now, so the staleness check never fires a false positive.
     *
     * @param string $process 'source_fetch_{id}' or 'profile_{id}'.
     * @param string $freq    Saved frequency; callers must not pass 'disabled'.
     * @param string $hook    The cron hook name, e.g. 'mmi_pipeline_' . $process.
     * @return int|null Next scheduled timestamp after healing, or null if
     *                  nothing is scheduled and $freq isn't a real WP schedule.
     */
    public static function self_heal_schedule( string $process, string $freq, string $hook ): ?int {
        $schedules = wp_get_schedules();
        if ( ! isset( $schedules[ $freq ] ) ) {
            return wp_next_scheduled( $hook ) ?: null;
        }

        $next  = wp_next_scheduled( $hook );
        $stale = $next && ( $next - time() ) > $schedules[ $freq ]['interval'];

        if ( ! $next || $stale ) {
            wp_clear_scheduled_hook( $hook );
            wp_schedule_event( self::compute_schedule_anchor( $process, $freq ), $freq, $hook );
            $next = wp_next_scheduled( $hook );
        }

        return $next ?: null;
    }

    /**
     * Every schedulable source (see schedulable_source_ids()) with its own
     * fetch frequency and next-run time — the single source of truth for the
     * Schedules panel rows (tab-pipeline.php), the topbar "next fetch" badge,
     * and get_next_scheduled_summary(). Self-heals a frequency that's saved
     * but whose WP-Cron event went missing, same as the per-profile rows in
     * tab-pipeline.php already do.
     *
     * @return array<int, array{supplier_id:string, name:string, source_type:string, frequency:string, next:int|null}>
     */
    public static function get_schedulable_sources(): array {
        global $wpdb;
        $ds_table = $wpdb->prefix . 'mmi_data_sources';

        $rows = [];
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) === $ds_table ) {
            $rows = $wpdb->get_results(
                "SELECT supplier_id, supplier_name, source_type FROM {$ds_table} WHERE config_status = 'validated' AND source_type != 'upload' ORDER BY display_order ASC, supplier_name ASC",
                ARRAY_A
            );
        }

        $known_ids    = array_column( $rows, 'supplier_id' );
        $legacy_class = 'MMI_Pipeline_Supplier_Fetch_Runner';
        if ( class_exists( $legacy_class ) ) {
            $legacy_enabled = (array) MMI_Settings::get( 'mmi_vip_enabled_suppliers', [] );
            foreach ( $legacy_class::SUPPORTED_SUPPLIERS as $sid ) {
                if ( in_array( $sid, $known_ids, true ) || ! in_array( $sid, $legacy_enabled, true ) ) {
                    continue;
                }
                // No wp_mmi_data_sources row to read a display name/type from.
                $rows[] = [ 'supplier_id' => $sid, 'supplier_name' => ucfirst( $sid ), 'source_type' => 'api' ];
            }
        }

        $out = [];
        foreach ( $rows as $row ) {
            $sid  = $row['supplier_id'];
            $freq = MMI_DB::get_setting( 'mmi_schedule_source_fetch_' . $sid, 'disabled' );
            $hook = 'mmi_pipeline_source_fetch_' . $sid;
            $next = ( 'disabled' !== $freq )
                ? self::self_heal_schedule( 'source_fetch_' . $sid, $freq, $hook )
                : wp_next_scheduled( $hook );

            $out[] = [
                'supplier_id' => $sid,
                'name'        => $row['supplier_name'] ?? $sid,
                'source_type' => $row['source_type'] ?? 'api',
                'frequency'   => $freq,
                'next'        => $next ?: null,
                'time'        => MMI_DB::get_setting( 'mmi_schedule_time_source_fetch_' . $sid, '' ),
            ];
        }

        return $out;
    }

    /**
     * A frequency+time-of-day pair's position in a comparable, circular
     * "anchor space" — used only by find_stagger_conflict() below to detect
     * two sources scheduled within 5 minutes of each other. Mirrors
     * compute_schedule_anchor()'s own rules for which part of a saved
     * HH:MM is actually meaningful for a given frequency, so the two never
     * disagree about what "the same time" means:
     *  - 'hourly': only the minute matters (space = 'hour', 0-59).
     *  - a daily-or-slower frequency: the full time-of-day matters
     *    (space = 'day', 0-1439 minutes since midnight).
     *  - anything else sub-daily (e.g. 'twicedaily'): compute_schedule_anchor()
     *    ignores the saved time entirely and always anchors to "now", so
     *    there is no stable value to compare — returns null.
     *
     * @return array{space:string, minutes:int}|null
     */
    private static function effective_anchor_minutes( string $frequency, string $time_of_day ): ?array {
        if ( ! is_string( $time_of_day ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time_of_day, $m ) ) {
            return null;
        }
        $schedules = wp_get_schedules();
        $interval  = $schedules[ $frequency ]['interval'] ?? null;

        if ( $interval === HOUR_IN_SECONDS ) {
            return [ 'space' => 'hour', 'minutes' => (int) $m[2] ];
        }
        if ( is_int( $interval ) && $interval < DAY_IN_SECONDS ) {
            return null;
        }
        return [ 'space' => 'day', 'minutes' => ( (int) $m[1] ) * 60 + (int) $m[2] ];
    }

    /** Shortest distance between two points on a circle of $modulus minutes. */
    private static function circular_distance( int $a, int $b, int $modulus ): int {
        $diff = abs( $a - $b ) % $modulus;
        return min( $diff, $modulus - $diff );
    }

    /**
     * Blocks two "API Data Sources" fetch schedules from landing within 5
     * minutes of each other — both hitting their endpoints/writing their
     * feed files back-to-back is exactly the kind of concurrent-load spike
     * AGENTS.md's Server Load rules exist to prevent, and it's easy to do
     * by accident since each source's schedule is independent (see
     * get_schedulable_sources()'s docblock). Only ever called for a
     * 'source_fetch_{id}' process — see the mmi_autosave_schedule handler
     * in ImportSettingsController.php, the only call site.
     *
     * @param string $process     'source_fetch_{supplier_id}' — the schedule about to be saved.
     * @param string $frequency   Its proposed frequency.
     * @param string $time_of_day Its proposed "HH:MM" time-of-day (or minute-select value).
     * @return array{supplier_id:string, name:string}|null Null when no conflict.
     */
    public static function find_stagger_conflict( string $process, string $frequency, string $time_of_day ): ?array {
        if ( strpos( $process, 'source_fetch_' ) !== 0 || 'disabled' === $frequency ) {
            return null;
        }
        $self_id     = substr( $process, strlen( 'source_fetch_' ) );
        $self_anchor = self::effective_anchor_minutes( $frequency, $time_of_day );
        if ( null === $self_anchor ) {
            return null; // this frequency has no stable anchor to compare against
        }

        foreach ( self::get_schedulable_sources() as $src ) {
            if ( $src['supplier_id'] === $self_id || $src['frequency'] !== $frequency ) {
                continue;
            }
            $other_anchor = self::effective_anchor_minutes( $src['frequency'], $src['time'] );
            if ( null === $other_anchor ) {
                continue;
            }
            $modulus  = 'hour' === $self_anchor['space'] ? 60 : 1440;
            $distance = self::circular_distance( $self_anchor['minutes'], $other_anchor['minutes'], $modulus );
            if ( $distance < 5 ) {
                return [ 'supplier_id' => $src['supplier_id'], 'name' => $src['name'] ];
            }
        }

        return null;
    }

    /* ── Fetch → Import Linking ───────────────────────────────────────────
     * A profile can be explicitly linked to one or more of its own
     * API-driven data sources when the two share the same schedule
     * frequency, so the profile runs immediately after any linked source's
     * next successful fetch instead of drifting on its own independent
     * WP-Cron clock (an OR trigger, not an AND — see run_scheduled_source_
     * fetch()'s docblock on the linked-profile dispatch for why waiting on
     * every linked source to fetch before running was rejected). Never
     * created automatically — get_link_rows() only ever surfaces a
     * suggestion in the Schedules panel; linking itself is always an
     * explicit user click through link_profile_to_source().
     *
     * Storage: mmi_schedule_profile_link_{profile_id} holds an ARRAY of
     * linked supplier_ids (via MMI_DB::set_setting()'s automatic
     * maybe_serialize(), not JSON) — a profile can link to more than one of
     * its own sources at once, one row per pairing in the Schedules panel.
     * A site upgraded from the single-link version (mmi-data-pipeline
     * 2.28.0-2.28.2) may still have the OLD scalar-string shape on disk for
     * a profile that was linked before this change shipped;
     * get_profile_links() below transparently reads that as a one-element
     * array — no migration script, it's rewritten in array form the next
     * time anything links/unlinks for that profile, same "self-heal on
     * next read/write" precedent this class already uses elsewhere.
     */

    /** Schedulable sources whose source_type is 'api' — the only kind eligible to be linked. */
    public static function get_api_schedulable_sources(): array {
        return array_values( array_filter(
            self::get_schedulable_sources(),
            static function ( $src ) {
                return ( $src['source_type'] ?? '' ) === 'api';
            }
        ) );
    }

    /**
     * Every supplier_id one profile is currently linked to (see this
     * section's own docblock for the storage shape and legacy-scalar
     * compat). Self-validates and self-repairs on every read: any entry
     * whose source no longer exists, is no longer an API source, or whose
     * frequency has since drifted from the profile's own (either side
     * edited independently after linking) is dropped — a stale entry left
     * in place would count toward "this profile is linked" (clearing its
     * independent WP-Cron event — see link_profile_to_source()) while
     * never actually triggering anything, leaving it permanently dormant
     * for that one dead entry. The filtered set is persisted back
     * (or the setting deleted entirely if nothing survives) only when it
     * actually differs from what was stored, so a normal read that changes
     * nothing doesn't churn a write on every page load.
     *
     * By construction, every supplier_id this returns has BOTH a valid API
     * source AND a currently-matching frequency — callers building a
     * per-row `linked` flag from this (see get_link_rows()) can rely on
     * "linked implies matches" without re-checking it themselves.
     *
     * @return string[]
     */
    public static function get_profile_links( string $profile_id ): array {
        $raw = MMI_DB::get_setting( 'mmi_schedule_profile_link_' . $profile_id, [] );
        if ( is_string( $raw ) ) {
            // Legacy pre-2.28.3 shape: a single supplier_id string.
            $raw = ( '' === $raw ) ? [] : [ $raw ];
        }
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        $profile_freq  = MMI_DB::get_setting( 'mmi_schedule_profile_' . $profile_id, 'disabled' );
        $sources_by_id = array_column( self::get_api_schedulable_sources(), null, 'supplier_id' );

        $valid = [];
        foreach ( array_unique( $raw ) as $supplier_id ) {
            $source = $sources_by_id[ $supplier_id ] ?? null;
            if ( $source && 'disabled' !== $profile_freq && $source['frequency'] === $profile_freq ) {
                $valid[] = $supplier_id;
            }
        }

        if ( $valid !== array_values( $raw ) ) {
            if ( empty( $valid ) ) {
                MMI_DB::delete_setting( 'mmi_schedule_profile_link_' . $profile_id );
            } else {
                MMI_DB::set_setting( 'mmi_schedule_profile_link_' . $profile_id, $valid );
            }
        }

        return $valid;
    }

    /**
     * Every (profile, source) pairing the Schedules panel should render a
     * row for — including pairs that are ALREADY linked, not just ones
     * that could be. One row per (profile, source): the profile lists that
     * supplier among its own configured sources, and the supplier is an
     * API-type schedulable source with a real (non-disabled) fetch
     * schedule of its own. Each row carries both `linked` (is this exact
     * pair currently linked — via get_profile_links(), never a raw setting
     * read, so the "linked implies matches" invariant documented on that
     * method actually holds here) and `matches` (would linking it work
     * right now, whether or not it already is).
     *
     * `matches` eligibility is deliberately broader than "ready to link
     * right now" — a profile whose own frequency doesn't currently match a
     * given source's (or is 'disabled' entirely) still gets a row, with
     * `matches: false`, rather than being omitted. Omitting it would make
     * the feature effectively invisible for any profile whose frequency
     * happens not to already agree with a source it reads from — e.g. a
     * profile created before this feature existed, at 'twicedaily', reading
     * from an 'hourly' source — even though linking it is one frequency
     * change away. tab-pipeline.php renders every row either way, disabling
     * the toggle itself (native `disabled` attribute, not just hidden) only
     * when neither `linked` nor `matches` is true. link_profile_to_source()
     * re-validates the match itself regardless, so a non-matching row can
     * never actually be linked even if a disabled toggle were somehow
     * bypassed client-side.
     *
     * @return array<int, array{profile_id:string, profile_name:string, profile_frequency:string, supplier_id:string, supplier_name:string, frequency:string, matches:bool, linked:bool}>
     */
    public static function get_link_rows(): array {
        $api_sources = array_filter(
            self::get_api_schedulable_sources(),
            static function ( $src ) {
                // A source with no active fetch schedule of its own never
                // fetches — nothing would ever actually trigger the link.
                return 'disabled' !== $src['frequency'];
            }
        );
        if ( empty( $api_sources ) ) {
            return [];
        }
        $sources_by_id = array_column( array_values( $api_sources ), null, 'supplier_id' );

        $rows = [];
        foreach ( MMI_DB::get_profiles_by_direction( 'import' ) as $profile_id => $profile ) {
            $profile_freq   = MMI_DB::get_setting( 'mmi_schedule_profile_' . $profile_id, 'disabled' );
            $linked_sources = self::get_profile_links( $profile_id );
            foreach ( (array) ( $profile['sources'] ?? [] ) as $supplier_id ) {
                $source = $sources_by_id[ $supplier_id ] ?? null;
                if ( ! $source ) {
                    continue;
                }
                $rows[] = [
                    'profile_id'        => $profile_id,
                    'profile_name'      => $profile['name'] ?? $profile_id,
                    'profile_frequency' => $profile_freq,
                    'supplier_id'       => $supplier_id,
                    'supplier_name'     => $source['name'],
                    'frequency'         => $source['frequency'],
                    'matches'           => ( 'disabled' !== $profile_freq && $source['frequency'] === $profile_freq ),
                    'linked'            => in_array( $supplier_id, $linked_sources, true ),
                ];
            }
        }

        return $rows;
    }

    /**
     * Links a profile to one more of its own API sources (adds to the
     * linked set — does not replace it, so a profile with multiple
     * matching sources can link to all of them). Re-validates the pairing
     * server-side (never trusts the AJAX caller's word that the
     * frequencies actually match) and clears the profile's independent
     * WP-Cron event unconditionally (idempotent/harmless if already
     * cleared by an earlier link), since the post-fetch trigger in
     * run_scheduled_source_fetch() becomes its trigger from here on.
     *
     * @return true|string True on success, or a human-readable error message.
     */
    public static function link_profile_to_source( string $profile_id, string $supplier_id ) {
        $profiles = MMI_DB::get_profiles_by_direction( 'import' );
        if ( ! isset( $profiles[ $profile_id ] ) ) {
            return 'Unknown profile.';
        }
        if ( ! in_array( $supplier_id, (array) ( $profiles[ $profile_id ]['sources'] ?? [] ), true ) ) {
            return 'That source is not configured on this profile.';
        }

        $source = null;
        foreach ( self::get_api_schedulable_sources() as $src ) {
            if ( $src['supplier_id'] === $supplier_id ) {
                $source = $src;
                break;
            }
        }
        if ( ! $source ) {
            return 'That source is not an API-driven, schedulable data source.';
        }

        $profile_freq = MMI_DB::get_setting( 'mmi_schedule_profile_' . $profile_id, 'disabled' );
        if ( 'disabled' === $profile_freq || $source['frequency'] !== $profile_freq ) {
            return "This profile's frequency no longer matches that source's fetch frequency.";
        }

        $links = self::get_profile_links( $profile_id );
        if ( ! in_array( $supplier_id, $links, true ) ) {
            $links[] = $supplier_id;
            MMI_DB::set_setting( 'mmi_schedule_profile_link_' . $profile_id, $links );
        }
        wp_clear_scheduled_hook( 'mmi_pipeline_profile_' . $profile_id );

        return true;
    }

    /**
     * Unlinks one source from a profile (removes it from the linked set —
     * any other linked source for the same profile is untouched). Once the
     * set is empty, immediately self-heals the profile's independent
     * WP-Cron event (same call — self_heal_schedule() — tab-pipeline.php's
     * own per-profile render loop already makes) rather than leaving that
     * to the next page render. Doing it right here is what lets the
     * Schedules panel's toggle (mmi_pipeline_toggle_profile_link in
     * ImportSettingsController.php) report a real, accurate Next Run over
     * AJAX without the caller needing a page reload to see a correct value.
     */
    public static function unlink_source_from_profile( string $profile_id, string $supplier_id ): void {
        $links = array_values( array_diff( self::get_profile_links( $profile_id ), [ $supplier_id ] ) );

        if ( empty( $links ) ) {
            MMI_DB::delete_setting( 'mmi_schedule_profile_link_' . $profile_id );
            $freq = MMI_DB::get_setting( 'mmi_schedule_profile_' . $profile_id, 'disabled' );
            if ( 'disabled' !== $freq ) {
                self::self_heal_schedule( 'profile_' . $profile_id, $freq, 'mmi_pipeline_profile_' . $profile_id );
            }
            return;
        }

        MMI_DB::set_setting( 'mmi_schedule_profile_link_' . $profile_id, $links );
    }

    /**
     * Human-readable Next Run label for one import profile — accounts for
     * every Fetch → Import link (see get_profile_links()) same as
     * tab-pipeline.php's Schedules panel does inline. When linked to more
     * than one source, reports the SOONEST of their next-fetch times —
     * that's genuinely when the profile will next run, since any one of
     * them triggers it (OR semantics, see this section's own docblock).
     * Extracted here (rather than left duplicated in the view) so
     * mmi_pipeline_toggle_profile_link's AJAX response can report an
     * accurate label right after a link/unlink without the caller needing
     * a page reload — the same live-update requirement
     * unlink_source_from_profile()'s docblock above describes.
     */
    public static function get_profile_next_run_label( string $profile_id ): string {
        $freq = MMI_DB::get_setting( 'mmi_schedule_profile_' . $profile_id, 'disabled' );
        if ( 'disabled' === $freq ) {
            return '—';
        }

        $links = self::get_profile_links( $profile_id );
        if ( ! empty( $links ) ) {
            $sources_by_id = array_column( self::get_api_schedulable_sources(), null, 'supplier_id' );
            $soonest       = null;
            foreach ( $links as $supplier_id ) {
                $src = $sources_by_id[ $supplier_id ] ?? null;
                if ( $src && $src['next'] && ( null === $soonest || $src['next'] < $soonest['next'] ) ) {
                    $soonest = $src;
                }
            }
            return $soonest
                ? 'After next ' . $soonest['name'] . ' fetch (' . human_time_diff( $soonest['next'], current_time( 'timestamp' ) ) . ')'
                : 'Linked — awaiting next fetch';
        }

        $next = self::self_heal_schedule( 'profile_' . $profile_id, $freq, 'mmi_pipeline_profile_' . $profile_id );
        return $next ? human_time_diff( $next, current_time( 'timestamp' ) ) . ' from now' : '—';
    }

    /**
     * Names of a profile's OWN configured sources that are NOT part of its
     * current link set — API or not, matching frequency or not, including
     * source types (upload, url, dropbox, gdrive) that never get their own
     * row in the Schedules panel at all. Only meaningful once a profile has
     * at least one link: an unlinked profile runs entirely on its own
     * independent clock and this warning wouldn't mean anything for it.
     *
     * Surfaces the risk a partial link creates — e.g. linking only to
     * Xchange when a profile also reads SkuPort means the profile can run
     * right after Xchange's fetch using SkuPort data that's still up to a
     * full SkuPort cycle old — so the Schedules panel can say so rather
     * than silently implying full freshness. Also appends a short nudge
     * when 2+ sources are linked and this profile hasn't opted into "Skip
     * if no new data": with more than one linked source each independently
     * triggering a run (OR semantics), more than one real import pass per
     * cycle is expected, and that toggle is what makes the extra pass
     * cheap rather than wasted work — see run_scheduled_profile_import()'s
     * skip gate, off by default.
     *
     * @return string Empty string when there's nothing to warn about.
     */
    public static function get_profile_staleness_warning( string $profile_id ): string {
        $links = self::get_profile_links( $profile_id );
        if ( empty( $links ) ) {
            return '';
        }

        $profiles = MMI_DB::get_profiles_by_direction( 'import' );
        $sources  = (array) ( $profiles[ $profile_id ]['sources'] ?? [] );
        $unlinked = array_values( array_diff( $sources, $links ) );

        $message = '';
        if ( ! empty( $unlinked ) ) {
            $configured = class_exists( 'MMI_Pipeline_Admin' ) ? MMI_Pipeline_Admin::get_configured_suppliers() : [];
            $names      = array_map(
                static function ( $sid ) use ( $configured ) {
                    return $configured[ $sid ]['supplier_name'] ?? $sid;
                },
                $unlinked
            );
            $message = 'This import also uses ' . implode( ', ', $names )
                . ' — its schedule isn\'t linked, so this import may run before that data refreshes.';
        }

        if ( count( $links ) > 1 && ! (bool) MMI_DB::get_setting( 'mmi_schedule_skip_if_no_data_' . $profile_id, false ) ) {
            $nudge   = 'Multiple linked sources can each trigger a run — consider enabling "Skip if no new data" above to keep an extra pass cheap.';
            $message = $message ? ( $message . ' ' . $nudge ) : $nudge;
        }

        return $message;
    }

    /**
     * Every profile_id currently linked to one supplier — used by
     * run_scheduled_source_fetch()'s post-fetch trigger to find which
     * profile(s), if any, should run right after this source's fetch just
     * succeeded. A profile linked to more than one source appears here
     * once per linked source it matches — an OR trigger, deliberately not
     * gated on every linked source having fetched: an AND-gate would need
     * new per-source last-fetch-success tracking, adds a failure mode
     * where one stalled source permanently blocks the profile, and would
     * reintroduce a version of the exact lag this multi-link feature
     * exists to fix (a profile not reacting to one of its own sources).
     * Bounded by the (small, admin-configured) profile count, not by
     * catalog size.
     *
     * @return string[]
     */
    private static function get_profiles_linked_to_source( string $supplier_id ): array {
        $linked = [];
        foreach ( MMI_DB::get_profiles_by_direction( 'import' ) as $profile_id => $profile ) {
            if ( in_array( $supplier_id, self::get_profile_links( $profile_id ), true ) ) {
                $linked[] = $profile_id;
            }
        }
        return $linked;
    }

    /**
     * Apply a frequency to one source's own fetch schedule — writes the
     * setting, clears any existing event, and reschedules unless disabled.
     *
     * Deliberately self-contained rather than calling
     * ImportSettingsController.php's mmi_pipeline_apply_schedule(): this
     * needs to run from init(), during THIS plugin's own bootstrap
     * (mmi-data-pipeline.php requires class-pipeline-cron.php and calls
     * init() before it requires ImportSettingsController.php later in the
     * same sequence), so that function isn't defined yet when this could run.
     * The live Schedules-panel dropdown still saves through the real
     * mmi_pipeline_apply_schedule() via the mmi_autosave_schedule AJAX
     * action — full page bootstrap has completed by the time that fires, so
     * no ordering issue there.
     */
    private static function apply_source_schedule( string $supplier_id, string $frequency ): void {
        $hook = 'mmi_pipeline_source_fetch_' . $supplier_id;
        MMI_DB::set_setting( 'mmi_schedule_source_fetch_' . $supplier_id, $frequency );
        wp_clear_scheduled_hook( $hook );
        if ( $frequency !== 'disabled' ) {
            $wp_schedules = wp_get_schedules();
            if ( isset( $wp_schedules[ $frequency ] ) ) {
                wp_schedule_event( time(), $frequency, $hook );
            }
        }
    }

    /**
     * One-time migration: seed each schedulable source's own fetch frequency
     * from the retired unified "Data Fetch" setting (mmi_schedule_supplier_fetch)
     * so upgrading from the single shared schedule doesn't silently turn off
     * automatic fetching for every source that used to run under it.
     * Idempotent — a source that already has its own frequency setting
     * (written by an earlier run of this migration, or set by hand via the
     * Schedules panel) is left untouched. Self-gates on a transient so the
     * source-table query runs at most once per hour regardless of how often
     * this is called; safe to call unconditionally from the view.
     */
    public static function migrate_sources_to_own_schedules(): void {
        if ( get_transient( 'mmi_sources_schedule_migration_done' ) ) {
            return;
        }
        set_transient( 'mmi_sources_schedule_migration_done', 1, HOUR_IN_SECONDS );

        global $wpdb;
        $ds_table = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) !== $ds_table ) {
            return;
        }

        $legacy_freq = MMI_DB::get_setting( 'mmi_schedule_supplier_fetch', 'disabled' );

        $known_ids = $wpdb->get_col( "SELECT supplier_id FROM {$ds_table} WHERE config_status = 'validated' AND source_type != 'upload'" );
        foreach ( $known_ids as $sid ) {
            if ( MMI_DB::get_setting( 'mmi_schedule_source_fetch_' . $sid, null ) !== null ) {
                continue; // already migrated or explicitly configured
            }
            self::apply_source_schedule( $sid, $legacy_freq );
        }

        // Legacy suppliers (e.g. Plugivery) enabled via the older per-tier
        // setting but never migrated into wp_mmi_data_sources — same seeding,
        // so they keep running at whatever cadence they already had instead
        // of going silent the moment this migration ships.
        $legacy_class = 'MMI_Pipeline_Supplier_Fetch_Runner';
        if ( class_exists( $legacy_class ) ) {
            $legacy_enabled = (array) MMI_Settings::get( 'mmi_vip_enabled_suppliers', [] );
            foreach ( $legacy_class::SUPPORTED_SUPPLIERS as $sid ) {
                if ( in_array( $sid, $known_ids, true ) || ! in_array( $sid, $legacy_enabled, true ) ) {
                    continue;
                }
                if ( MMI_DB::get_setting( 'mmi_schedule_source_fetch_' . $sid, null ) !== null ) {
                    continue;
                }
                self::apply_source_schedule( $sid, $legacy_freq );
            }
        }
    }

    /**
     * Fetch exactly one data source — the cron callback bound to that
     * source's own mmi_pipeline_source_fetch_{supplier_id} hook (registered
     * in init(), one per schedulable source, mirroring the per-profile
     * pattern above).
     *
     * Replaces the old run_scheduled_supplier_fetch(), which fired one
     * unified mmi_scheduled_supplier_fetch tick for every source at once via
     * MMI_Pipeline_Supplier_Fetch_Runner::runAll() — that runner only ever knew about the 3
     * hardcoded legacy suppliers (xchange/skuport/plugivery) via the separate
     * mmi_vip_enabled_suppliers setting, so every dynamically-added source
     * (Upload/URL/Dropbox/Google Drive) was silently never fetched by the
     * schedule at all, and no source could run on its own cadence — only the
     * one shared frequency. Legacy suppliers still go through
     * MMI_Pipeline_Supplier_Fetch_Runner::runOne() (unchanged updater logic); everything
     * else goes through Data_Source_Manager::fetch_from_supplier(), the same
     * method the manual "Fetch Selected"/"Fetch Now" actions already use.
     *
     * Locked per source, not globally — independent schedules must be able
     * to run concurrently; this only guards a source against overlapping
     * with its OWN previous run.
     */
    public static function run_scheduled_source_fetch( string $supplier_id ): void {
        MMI_Logger::debug( "Scheduled fetch starting for source \"{$supplier_id}\"", [], 'sync', 'MMI_Pipeline_Cron' );

        $lock_key = self::fetch_lock_key( $supplier_id );
        if ( self::is_fetch_running( $supplier_id ) ) {
            MMI_Logger::info( "Scheduled fetch skipped for \"{$supplier_id}\" — already running", [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }
        // Duplicate-trigger guard. This site has more than one cron runner
        // (scripts/wp-cron.php plus the RunCloud panel's
        // `wp cron event run --due-now`, which ignores the doing_cron lock),
        // so the same due event can fire twice. A second run that starts
        // just after the first released $lock_key re-reads the feed file the
        // first run wrote seconds earlier, and the stale check below then
        // reported "no new data". That was every "Completed with Warnings"
        // Data Fetch in the 2026-10-05 digest (8 false xchange warnings).
        $done_key = 'mmi_source_fetch_done_' . $supplier_id;
        $done_at  = MMI_DB::get_job_state( $done_key );
        if ( $done_at ) {
            MMI_Logger::info( "Scheduled fetch skipped for \"{$supplier_id}\" — a fetch already completed at {$done_at} (duplicate cron trigger)", [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }

        if ( self::is_stepped_source( $supplier_id ) ) {
            self::start_stepped_fetch( $supplier_id, 'cron' );
            return;
        }

        MMI_DB::set_job_state( $lock_key, current_time( 'mysql' ), 1800 );

        try {
            $start_time     = microtime( true );
            $plugivery_full = MMI_DB::get_setting( 'mmi_fetch_plugivery_full_auto', false );

            $legacy_class  = 'MMI_Pipeline_Supplier_Fetch_Runner';
            $upload_class  = 'MMI_Pipeline_Upload_Source_Fetcher';
            $dsm_class     = 'MannMade\\Integrations\\Acquisition\\Data_Source_Manager';
            $is_legacy     = class_exists( $legacy_class ) && in_array( $supplier_id, $legacy_class::SUPPORTED_SUPPLIERS, true );
            $is_upload     = ! $is_legacy && class_exists( $upload_class ) && $upload_class::is_upload_source( $supplier_id );

            if ( $is_legacy ) {
                ( new $legacy_class() )->runOne( $supplier_id, $plugivery_full );
            } elseif ( $is_upload ) {
                // Upload sources need no network fetch — the file is already
                // on disk via its WP attachment — so they don't wait on
                // Data_Source_Manager (see that class's docblock for why).
                $upload_class::fetch( $supplier_id );
            } elseif ( class_exists( $dsm_class ) ) {
                $dsm_class::instance()->fetch_from_supplier( $supplier_id );
            } else {
                throw new \RuntimeException( 'No fetch mechanism available for this source (SupplierFetchRunner/Data_Source_Manager not loaded).' );
            }

            self::complete_source_fetch( $supplier_id, $start_time, 'cron' );
        } catch ( \Throwable $e ) {
            self::fail_source_fetch( $supplier_id, $e );
        } finally {
            MMI_DB::delete_job_state( $lock_key );
        }
    }

    /* ── Fetch run bookkeeping (shared by the inline and stepped paths) ───── */

    public static function fetch_lock_key( string $supplier_id ): string {
        return 'mmi_source_fetch_lock_' . $supplier_id;
    }

    private static function fetch_run_key( string $supplier_id ): string {
        return 'mmi_source_fetch_run_' . $supplier_id;
    }

    public static function is_stepped_source( string $supplier_id ): bool {
        return isset( self::STEPPED_SOURCES[ $supplier_id ] ) && function_exists( 'as_schedule_single_action' );
    }

    /**
     * One lock for every way a source gets fetched: the schedule, the stepped
     * chain, and Data Pipeline's "Fetch now" (whose own transient used to be
     * a separate lock, so a manual fetch could overlap a scheduled one).
     */
    public static function is_fetch_running( string $supplier_id ): bool {
        return (bool) MMI_DB::get_job_state( self::fetch_lock_key( $supplier_id ) )
            || (bool) get_transient( 'mmi_pipeline_fetch_now_' . $supplier_id );
    }

    /**
     * The stepped run in progress, if any:
     * { run_id, trigger, started, started_at, steps, step_index }.
     */
    public static function fetch_run_state( string $supplier_id ): ?array {
        $run = MMI_DB::get_job_state( self::fetch_run_key( $supplier_id ) );
        return is_array( $run ) ? $run : null;
    }

    /**
     * Starts a stepped fetch: takes the lock and queues the first step. Returns
     * in milliseconds; the requests happen in the steps.
     *
     * @param string $trigger 'cron' (full post-fetch follow-up) or 'manual'
     *                        (feed files only).
     * @return array{started:bool, reason?:string, run?:array}
     */
    public static function start_stepped_fetch( string $supplier_id, string $trigger ): array {
        if ( ! self::is_stepped_source( $supplier_id ) ) {
            return [ 'started' => false, 'reason' => 'unsupported' ];
        }
        if ( self::is_fetch_running( $supplier_id ) ) {
            return [ 'started' => false, 'reason' => 'running', 'run' => self::fetch_run_state( $supplier_id ) ];
        }

        $steps = self::STEPPED_SOURCES[ $supplier_id ];
        $run   = [
            'run_id'     => wp_generate_password( 12, false ),
            'trigger'    => $trigger === 'manual' ? 'manual' : 'cron',
            'started'    => microtime( true ),
            'started_at' => current_time( 'mysql' ),
            'steps'      => $steps,
            'step_index' => 0,
        ];
        // TTL covers the whole chain with margin; a killed step can't hold
        // the lock past it.
        MMI_DB::set_job_state( self::fetch_lock_key( $supplier_id ), $run['started_at'], 15 * MINUTE_IN_SECONDS );
        MMI_DB::set_job_state( self::fetch_run_key( $supplier_id ), $run, 15 * MINUTE_IN_SECONDS );
        as_schedule_single_action( time(), self::STEP_HOOK, [ $supplier_id, 0, $run['run_id'] ], self::STEP_GROUP );

        MMI_Logger::info( "Stepped fetch started for \"{$supplier_id}\" ({$run['trigger']}): " . implode( ' → ', $steps ), [], 'sync', 'MMI_Pipeline_Cron' );
        return [ 'started' => true, 'run' => $run ];
    }

    /**
     * Every manual "Fetch now" (Data Pipeline's source table and each
     * supplier's Catalog tab): the stepped chain for stepped sources, else one
     * Action Scheduler fetch under the same transient the schedule checks
     * (is_fetch_running()). Feed files only, no imports.
     *
     * @return array{started:bool, reason?:string}
     */
    public static function queue_manual_fetch( string $supplier_id ): array {
        if ( self::is_stepped_source( $supplier_id ) ) {
            return self::start_stepped_fetch( $supplier_id, 'manual' );
        }
        if ( self::is_fetch_running( $supplier_id ) ) {
            return [ 'started' => false, 'reason' => 'running' ];
        }
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            return [ 'started' => false, 'reason' => 'unavailable' ];
        }
        // Worker + lock TTL: SourceFetchController.php (FETCH_NOW_HOOK).
        set_transient( 'mmi_pipeline_fetch_now_' . $supplier_id, current_time( 'mysql' ), 300 );
        as_schedule_single_action( time(), 'mmi_pipeline_fetch_source_now', [ $supplier_id ], self::STEP_GROUP );
        return [ 'started' => true ];
    }

    /**
     * Action Scheduler callback (STEP_HOOK): one request, then queue the next
     * step STEP_GAP seconds out, or finish the run.
     */
    public static function run_source_fetch_step( $supplier_id, $index, $run_id ): void {
        $supplier_id = (string) $supplier_id;
        $index       = (int) $index;
        $run         = self::fetch_run_state( $supplier_id );
        if ( ! $run || ( $run['run_id'] ?? '' ) !== (string) $run_id ) {
            MMI_Logger::info( "Stepped fetch step {$index} for \"{$supplier_id}\" dropped — its run is no longer current", [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }
        $steps = $run['steps'];
        $step  = $steps[ $index ] ?? null;

        try {
            if ( $step === null ) {
                throw new \RuntimeException( "Unknown fetch step {$index}." );
            }
            // The updaters echo progress for their CLI origins.
            ob_start();
            try {
                self::run_fetch_part( $supplier_id, $step );
            } finally {
                ob_end_clean();
            }

            if ( isset( $steps[ $index + 1 ] ) ) {
                $run['step_index'] = $index + 1;
                MMI_DB::set_job_state( self::fetch_run_key( $supplier_id ), $run, 15 * MINUTE_IN_SECONDS );
                as_schedule_single_action( time() + self::STEP_GAP, self::STEP_HOOK, [ $supplier_id, $index + 1, $run['run_id'] ], self::STEP_GROUP );
                return;
            }

            self::complete_source_fetch( $supplier_id, (float) $run['started'], $run['trigger'] );
        } catch ( \Throwable $e ) {
            self::fail_source_fetch( $supplier_id, $e );
        }

        MMI_DB::delete_job_state( self::fetch_run_key( $supplier_id ) );
        MMI_DB::delete_job_state( self::fetch_lock_key( $supplier_id ) );
    }

    private static function run_fetch_part( string $supplier_id, string $step ): void {
        if ( $supplier_id === 'xchange' ) {
            if ( $step === 'vendors' ) {
                ( new MMI_Pipeline_Xchange_Vendors() )->run();
                return;
            }
            ( new MMI_Pipeline_Xchange_Updater() )->run_part( $step );
            return;
        }
        throw new \RuntimeException( "No stepped fetch for \"{$supplier_id}\"." );
    }

    /**
     * Everything after a successful fetch: the source's row, activity log,
     * and — for the schedule only — the duplicate-run window, digest entry,
     * catalog update and linked imports.
     */
    private static function complete_source_fetch( string $supplier_id, float $start_time, string $trigger ): void {
        $error_key = 'mmi_pipeline_process_error_source_fetch_' . $supplier_id;
        $duration  = round( microtime( true ) - $start_time, 2 );
        $timestamp = current_time( 'mysql' );

        // Refresh just THIS source's row from its live JSON file — see
        // refresh_all_source_counts()'s $only_supplier_id doc for why an
        // unscoped call here would misjudge other, independently-
        // scheduled sources as newly stale.
        $source_result = self::refresh_all_source_counts( $start_time, $supplier_id );
        $result        = $source_result[0] ?? null;
        $is_stale      = $result && ( $result['status'] ?? '' ) === 'stale';
        $count         = $result['count'] ?? 0;

        $summary = sprintf(
            'Data fetch for "%s" completed in %s — %s products',
            $supplier_id,
            self::format_duration( $duration ),
            number_format( $count )
        );
        if ( $is_stale ) {
            $summary .= ' — WARNING: no new data (updater may be failing or skipped)';
        }

        MMI_DB::add_activity( $is_stale ? 'fetch_warning' : 'fetch', $summary, [
            'supplier_id'      => $supplier_id,
            'duration_seconds' => $duration,
            'result'           => $result,
            'plugivery_full'   => (bool) MMI_DB::get_setting( 'mmi_fetch_plugivery_full_auto', false ),
            'trigger'          => $trigger,
        ] );

        // A manual "Fetch now" only refreshes the feed files: it doesn't
        // claim the hour's duplicate-run window or start imports.
        $is_cron = $trigger === 'cron';
        if ( $is_cron ) {
            MMI_DB::set_job_state( 'mmi_source_fetch_done_' . $supplier_id, $timestamp, self::duplicate_fetch_window( $supplier_id ) );
        }
        if ( $is_stale ) {
            self::record_fetch_warning( $supplier_id, self::FETCH_WARNING_NO_NEW_DATA );
        }

        if ( $is_stale ) {
            MMI_Logger::error(
                "Scheduled fetch for \"{$supplier_id}\" produced NO NEW DATA — its updater is failing or being skipped; the feed file on disk was not rewritten.",
                [ 'result' => $result ], 'sync', 'MMI_Pipeline_Cron'
            );
        } else {
            MMI_Logger::info( $summary, [ 'result' => $result ], 'sync', 'MMI_Pipeline_Cron' );
        }

        if ( $is_cron ) {
            self::queue_success_notification( [
                'label'     => 'Data Fetch: ' . $supplier_id,
                'status'    => $is_stale ? 'partial' : 'success',
                'warning'   => $is_stale ? self::FETCH_WARNING_NO_NEW_DATA : '',
                'queued_at' => $timestamp,
                'duration'  => self::format_duration( $duration ),
                'summary'   => [
                    [ 'label' => 'Source',   'value' => $supplier_id ],
                    [ 'label' => 'Products', 'value' => number_format( $count ) ],
                    [ 'label' => 'Duration', 'value' => self::format_duration( $duration ) ],
                ],
            ] );
        }

        // Clear any previously stored fetch-error state — this run succeeded.
        MMI_DB::delete_setting( $error_key );
        self::resolve_upstream_outage( $supplier_id );

        // Schedule Catalog Update to run 5 minutes after ANY source's
        // fetch completes. Avoids scheduling a duplicate if another
        // source's fetch already queued one.
        if ( $is_cron && 'disabled' !== MMI_DB::get_setting( 'mmi_schedule_catalog_update', 'sixhourly' ) && ! wp_next_scheduled( 'mmi_scheduled_catalog_update' ) ) {
            wp_schedule_single_event( time() + 300, 'mmi_scheduled_catalog_update' );
            MMI_Logger::info( 'Catalog update scheduled in 5 min after cron fetch', [], 'sync', 'MMI_Pipeline_Cron' );
        }

        // Fetch → Import Linking (see the "Fetch → Import Linking" section
        // above): a profile explicitly linked to THIS source dispatches
        // its own scheduled import right now rather than waiting for its
        // independent clock — which no longer exists for a linked
        // profile, since linking clears it (link_profile_to_source()).
        // Skipped when this fetch was stale ($is_stale) — no point
        // re-running an import against data that didn't actually change.
        // run_scheduled_profile_import() is the exact same dispatcher the
        // profile's own WP-Cron hook would have called; it owns its own
        // lock/skip-gate/Action-Scheduler-batch handling, so calling it
        // here needs no extra safety logic of its own.
        if ( $is_cron && ! $is_stale ) {
            foreach ( self::get_profiles_linked_to_source( $supplier_id ) as $linked_profile_id ) {
                MMI_Logger::info(
                    "Fetch–import link triggered: \"{$linked_profile_id}\" after \"{$supplier_id}\" fetch",
                    [], 'sync', 'MMI_Pipeline_Cron'
                );
                self::run_scheduled_profile_import( $linked_profile_id );
            }
        }
    }

    private static function fail_source_fetch( string $supplier_id, \Throwable $e ): void {
        $error_key = 'mmi_pipeline_process_error_source_fetch_' . $supplier_id;
        MMI_Logger::error( "Scheduled fetch error for \"{$supplier_id}\": " . $e->getMessage(), [], 'sync', 'MMI_Pipeline_Cron' );
        MMI_DB::add_activity( 'fetch_error', "Data fetch for \"{$supplier_id}\" failed: " . $e->getMessage() );
        MMI_DB::set_setting( $error_key, [
            'message' => $e->getMessage(),
            'time'    => current_time( 'mysql' ),
            'process' => 'Data Fetch: ' . $supplier_id,
        ] );
        if ( self::is_upstream_unavailable_error( $e->getMessage() ) ) {
            // The supplier's own API is down (HALT mode, connection reset,
            // 5xx). Nothing on this site can fix that, and the previous feed
            // file stays in use, so this goes through the grace window
            // rather than an immediate "action required" email.
            self::record_upstream_outage( $supplier_id, $e->getMessage() );
        } else {
            MMI_DB::delete_setting( self::upstream_outage_key( $supplier_id ) );
            self::send_failure_alert( 'Data Fetch: ' . $supplier_id, $e->getMessage(), [
                [ 'label' => 'Source',    'value' => $supplier_id ],
                [ 'label' => 'Last Feed', 'value' => self::describe_source_feed_age( $supplier_id ) ],
            ] );
        }
    }

    /* ── Catalog Updater ──────────────────────────────────────────────────── */

    /**
     * Dispatcher for the scheduled catalog update. Bound to
     * `mmi_scheduled_catalog_update` in init() — fires 5 minutes after any
     * supplier fetch completes (see the post-fetch trigger in
     * run_scheduled_source_fetch() above) and on a routine 6-hourly schedule.
     *
     * This used to run the monolithic MMI_Pipeline_Catalog_Updater::run() —
     * a single long-running call honoring only the brand-rules toggle and
     * never re-applying stock overrides — synchronously inside wp-cron.php's
     * request, which per this project's Operational Continuity Rule 12 has a
     * 45s soft timeout + 3s SIGKILL grace. A full catalog run cannot finish
     * in 45s, meaning this was very likely being SIGKILLed mid-run, and
     * SIGKILL gives PHP no chance to run a shutdown-function lock release.
     *
     * Now a true Rule 3 dispatcher: acquire the SAME lock the AJAX button
     * path and `wp mmi catalog` use (MannMade\DataPipeline\Catalog\
     * Catalog_Run_State — one lock, every call site, per Rule 11), persist
     * the server-built phase list, and queue the first phase as an Action
     * Scheduler action. Completes in well under a second; the actual work
     * happens one phase per AS action via run_catalog_phase() below — the
     * identical run_phase() call the AJAX handler makes, so the button and
     * the schedule are now, literally, the same code.
     */
    public static function run_catalog_update(): void {
        if ( ! \MannMade\DataPipeline\Catalog\Catalog_Phase_Runner::is_enabled() ) {
            MMI_Logger::info( 'Catalog update dispatch skipped — Catalog Maintenance is disabled.', [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }

        $run_id = \MannMade\DataPipeline\Catalog\Catalog_Run_State::try_acquire( 'cron' );
        if ( $run_id === null ) {
            // Expected, not an error — the button or a prior scheduled run
            // is already in progress and holds the shared lock.
            MMI_Logger::info( 'Catalog update dispatch skipped — a run is already in progress.', [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }

        $phases = \MannMade\DataPipeline\Catalog\Catalog_Phase_Runner::build_phases();
        \MannMade\DataPipeline\Catalog\Catalog_Run_State::save_state( $run_id, [
            'phases'          => $phases,
            'results'         => [],
            'current_index'   => 0,
            'total_processed' => 0,
            'started_at'      => current_time( 'mysql' ),
        ] );

        as_schedule_single_action( time(), 'mmi_pipeline_catalog_phase', [ $run_id, 0 ], 'mmi-pipeline-catalog', false, MMI_PIPELINE_AS_PRIORITY_BATCH );

        MMI_Logger::info( "Catalog update dispatched run_id={$run_id} — " . count( $phases ) . ' phase(s) queued', [], 'sync', 'MMI_Pipeline_Cron' );
    }

    /**
     * Action Scheduler handler for one catalog-update phase. Self-reschedules
     * for the next phase, or finalizes + releases the lock on the last one —
     * the same self-rescheduling chain pattern already used for profile
     * imports at run_profile_import_batch() in this same class.
     */
    public static function run_catalog_phase( string $run_id, int $index ): void {
        if ( ! \MannMade\DataPipeline\Catalog\Catalog_Run_State::reacquire( $run_id ) ) {
            MMI_Logger::warn( "Catalog phase chain abandoned — lock lost run_id={$run_id} index={$index}", [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }

        $state = \MannMade\DataPipeline\Catalog\Catalog_Run_State::get_state( $run_id );
        if ( ! $state || ! isset( $state['phases'][ $index ] ) ) {
            MMI_Logger::error( "Catalog phase chain: missing state or invalid index run_id={$run_id} index={$index}", [], 'sync', 'MMI_Pipeline_Cron' );
            \MannMade\DataPipeline\Catalog\Catalog_Run_State::release( $run_id );
            return;
        }

        $total_phases = count( $state['phases'] );
        \MannMade\DataPipeline\Catalog\Catalog_Phase_Runner::run_phase( $run_id, $index );

        $next_index = $index + 1;
        if ( $next_index < $total_phases ) {
            as_schedule_single_action( time(), 'mmi_pipeline_catalog_phase', [ $run_id, $next_index ], 'mmi-pipeline-catalog', false, MMI_PIPELINE_AS_PRIORITY_BATCH );
            return;
        }

        // Last phase — finalize and release.
        $result  = \MannMade\DataPipeline\Catalog\Catalog_Phase_Runner::finalize( $run_id );
        \MannMade\DataPipeline\Catalog\Catalog_Run_State::release( $run_id );

        $summary = "Completed in " . self::format_duration( $result['duration_ms'] / 1000 ) . " - {$result['total_processed']} products processed";
        MMI_DB::add_activity( 'catalog', $summary, [ 'duration_seconds' => $result['duration_ms'] / 1000 ] );
        MMI_Logger::info( "Catalog update complete run_id={$run_id} {$summary}", [], 'sync', 'MMI_Pipeline_Cron' );
    }

    /* ── Legacy Supplier Status (display-only, for the Acquisition step) ───── */

    /**
     * Per-process status cards for the Import tab's Data Acquisition step —
     * fetch/import "processes" for the two suppliers with dedicated CLI
     * updater classes (Xchange, SkuPort), plus the Catalog Updater itself.
     * Read-only display data; ported from the retired MMI_Product_Importer_UI.
     */
    public static function get_supplier_configs(): array {
        return [
            'xchange_fetch' => [
                'name'        => 'Xchange Data Fetch',
                'type'        => 'fetch',
                'description' => 'Fetch products and promotions from Xchange API',
                'command'     => 'mmi fetch-xchange',
                'schedule'    => MMI_DB::get_setting( 'mmi_schedule_xchange_fetch', 'disabled' ),
                'last_run'    => MMI_DB::get_setting( 'mmi_last_run_xchange_fetch', 'Never' ),
                'duration'    => '~7 sec',
                'json_files'  => [ 'xchange-products.json', 'xchange-promotions.json' ],
                'status'      => array_merge(
                    self::check_json_file_status( 'xchange-products.json' ),
                    [
                        'last_run' => self::format_last_run( MMI_DB::get_setting( 'mmi_last_run_xchange_fetch', 'Never' ) ),
                        'duration' => '~7 sec',
                    ]
                ),
            ],
            'skuport_fetch' => [
                'name'        => 'SkuPort Data Fetch',
                'type'        => 'fetch',
                'description' => 'Fetch products and promos from SkuPort API',
                'command'     => 'mmi fetch-skuport',
                'schedule'    => MMI_DB::get_setting( 'mmi_schedule_skuport_fetch', 'disabled' ),
                'last_run'    => MMI_DB::get_setting( 'mmi_last_run_skuport_fetch', 'Never' ),
                'duration'    => '~5 sec',
                'json_files'  => [ 'skuport-products.json', 'skuport-promos.json' ],
                'status'      => array_merge(
                    self::check_json_file_status( 'skuport-products.json' ),
                    [
                        'last_run' => self::format_last_run( MMI_DB::get_setting( 'mmi_last_run_skuport_fetch', 'Never' ) ),
                        'duration' => '~5 sec',
                    ]
                ),
            ],
            'xchange_import' => [
                'name'        => 'Xchange Product Import',
                'type'        => 'import',
                'description' => 'Import Xchange products into WooCommerce',
                'command'     => 'mmi import xchange',
                'schedule'    => MMI_DB::get_setting( 'mmi_schedule_xchange_import', 'disabled' ),
                'last_run'    => self::format_last_run( MMI_DB::get_setting( 'mmi_last_run_xchange_import', 'Never' ) ),
                'duration'    => '~5-10 min',
                'depends_on'  => 'xchange_fetch',
                'status'      => array_merge( self::check_json_file_status( 'xchange-products.json' ), [ 'count' => 0 ] ),
            ],
            'skuport_import' => [
                'name'        => 'SkuPort Product Import',
                'type'        => 'import',
                'description' => 'Import SkuPort products into WooCommerce',
                'command'     => 'mmi import skuport',
                'schedule'    => MMI_DB::get_setting( 'mmi_schedule_skuport_import', 'disabled' ),
                'last_run'    => self::format_last_run( MMI_DB::get_setting( 'mmi_last_run_skuport_import', 'Never' ) ),
                'duration'    => '~3-5 min',
                'depends_on'  => 'skuport_fetch',
                'status'      => array_merge( self::check_json_file_status( 'skuport_products.json' ), [ 'count' => 0 ] ),
            ],
            'catalog_update' => [
                'name'        => 'Catalog Updater',
                'type'        => 'maintenance',
                'description' => 'Update prices, stock, categories and metadata - Runs automatically after fetch',
                'command'     => 'mmi catalog',
                'schedule'    => 'auto',
                'last_run'    => MMI_DB::get_setting( 'mmi_last_run_catalog_update', 'Never' ),
                'duration'    => '~10-15 min',
                'depends_on'  => null,
                'status'      => [ 'exists' => true, 'size' => 0 ],
            ],
        ];
    }

    /**
     * Human-readable "N ago" label for a MySQL datetime string, or 'Never'.
     */
    public static function format_last_run( $timestamp ): string {
        if ( $timestamp === 'Never' || empty( $timestamp ) ) {
            return 'Never';
        }

        $time = strtotime( $timestamp );
        if ( ! $time ) {
            return 'Never';
        }

        $diff = time() - $time;

        if ( $diff < 60 ) {
            return 'Just now';
        } elseif ( $diff < 3600 ) {
            return round( $diff / 60 ) . ' min ago';
        } elseif ( $diff < 86400 ) {
            return round( $diff / 3600 ) . ' hrs ago';
        }
        return round( $diff / 86400 ) . ' days ago';
    }

    /**
     * Existence/age/size status of one of the legacy suppliers' cached JSON
     * feed files, for the Acquisition step's status cards.
     */
    private static function check_json_file_status( string $filename ): array {
        $json_dir = mmi_shared_lib_json_dir();
        $filepath = $json_dir . $filename;

        if ( ! file_exists( $filepath ) ) {
            return [
                'exists'    => false,
                'size'      => 0,
                'modified'  => null,
                'age_hours' => null,
                'data_age'  => 'Unknown',
            ];
        }

        $modified  = filemtime( $filepath );
        $age_hours = ( time() - $modified ) / 3600;

        if ( $age_hours < 1 ) {
            $data_age = round( $age_hours * 60 ) . ' min ago';
        } elseif ( $age_hours < 24 ) {
            $data_age = round( $age_hours, 1 ) . ' hrs ago';
        } else {
            $data_age = round( $age_hours / 24, 1 ) . ' days ago';
        }

        return [
            'exists'    => true,
            'size'      => filesize( $filepath ),
            'modified'  => $modified,
            'age_hours' => round( $age_hours, 1 ),
            'data_age'  => $data_age,
        ];
    }

    /* ── Scheduled Per-Profile Import ────────────────────────────────────── */

    /**
     * Dispatch a scheduled import for a specific profile (e.g. "Pricing").
     *
     * Triggered by the `mmi_pipeline_profile_{profile_id}` WP-Cron hook registered
     * in init(). This is a thin dispatcher only — it never imports anything itself.
     * It used to run every enabled supplier's ENTIRE catalog synchronously in one
     * call, which worked only when the source feed hadn't changed (the importer's
     * hash-gate short-circuits almost instantly). The moment real price/stock
     * movement required actual WooCommerce product saves, a run could take minutes
     * — but the server's wp-cron.php runner is hard-killed by the system crontab
     * after 55 seconds (`timeout -k 5 55 ... wp-cron.php`), and the import_history
     * row was only written at the very end. A killed run left zero trace — no
     * success, no failure — which is exactly why the Pricing profile's last-success
     * timestamp stopped advancing for 53+ hours despite the cron firing every hour.
     *
     * Fix: this dispatcher only queues one Action Scheduler action per enabled
     * supplier (mmi_pipeline_profile_import_batch) and returns immediately. Each
     * batch action processes a time-boxed slice of that supplier's catalog
     * (see run_profile_import_batch()) and re-queues itself if more remains, so no
     * single PHP process is ever asked to do more than ~25s of work — comfortably
     * inside the 55s hard limit regardless of how slow the overall import is.
     *
     * @param string $profile_id  The profile slug (e.g. 'pricing').
     */
    /**
     * Resolves a profile_id (a permanent slug, generated once at creation and
     * never updated when a profile is renamed — see ImportSettingsController's
     * mmi_save_import_profile/_rename_import_profile) to its CURRENT saved
     * display name, for anything shown to a human (activity log headlines,
     * failure alerts, the daily digest email). Falls back to ucfirst($profile_id)
     * only when the profile no longer exists (e.g. deleted mid-run), so a label
     * is still produced rather than emitting an empty string.
     *
     * @param string $profile_id
     * @return string
     */
    private static function get_profile_label( string $profile_id ): string {
        $name = MMI_DB::get_profiles()[ $profile_id ]['name'] ?? '';
        return $name !== '' ? $name : ucfirst( $profile_id );
    }

    public static function run_scheduled_profile_import( string $profile_id ): void {
        MMI_Logger::debug( "Scheduled profile import dispatch starting [{$profile_id}]", [], 'sync', 'MMI_Pipeline_Cron' );

        $lock_key = 'mmi_profile_import_lock_' . $profile_id;
        if ( MMI_DB::get_job_state( $lock_key ) ) {
            MMI_Logger::info( "Scheduled profile import skipped [{$profile_id}] — another run is in progress", [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }

        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            MMI_Logger::error( "Scheduled profile import [{$profile_id}] cannot start — Action Scheduler is not available", [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }

        // Scope to this profile's own configured sources, same as the manual
        // import path (ProductImportController::count_all_products() /
        // handle_process_import_batch()) — otherwise every scheduled profile
        // dispatches a batch job per globally validated supplier regardless of
        // which sources the profile actually lists, and the resulting
        // import_history/activity row reports suppliers that profile never touched.
        //
        // Queries wp_mmi_data_sources directly (config_status = 'validated'),
        // same as ProductImportController::get_enabled_suppliers() and
        // MMI_Pipeline_Config_Validator::get_enabled_suppliers() — previously
        // this read the mmi_pipeline_enabled_suppliers option instead, which
        // depended on the now-removed manual "enable" toggle keeping it in
        // sync (see AGENTS.md's "Enabled Toggle Eliminated" entry).
        global $wpdb;
        $ds_table          = $wpdb->prefix . 'mmi_data_sources';
        $enabled_suppliers = ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) === $ds_table )
            ? $wpdb->get_col( "SELECT supplier_id FROM {$ds_table} WHERE config_status = 'validated' ORDER BY display_order ASC" )
            : array_values( (array) MMI_DB::get_setting( 'mmi_pipeline_enabled_suppliers', [] ) );
        $profile_sources   = MMI_DB::get_profiles()[ $profile_id ]['sources'] ?? [];
        if ( ! empty( $profile_sources ) ) {
            $enabled_suppliers = array_values( array_intersect( $enabled_suppliers, $profile_sources ) );
        }
        if ( empty( $enabled_suppliers ) ) {
            MMI_Logger::info( "Scheduled profile import [{$profile_id}] skipped — no validated suppliers", [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }

        // Optional per-profile gate (the schedule table's "Skip if no new
        // data" toggle): if the last known pending-data check found nothing
        // this profile would actually create/update, skip queuing any batch
        // work at all — see MMI_Import_Preview::get_relevant_pending_type()/
        // is_pending_actionable(), the exact same computation the Import
        // Profiles grid's Pending column shows, so a skip is never a
        // mystery to whoever turned the toggle on.
        //
        // Deliberately never computes stats synchronously here — that would
        // spend this dispatcher's own <1s budget (Rule 3) on a run that's
        // supposed to be nearly instant. A missing/stale cache always falls
        // through to a normal run below; either way, a background refresh
        // is queued so the NEXT tick has fresh evidence to decide with —
        // this is what keeps the cache warm without a separate blanket
        // cron, at the cost of the (much cheaper than a real import) preview
        // scan running on every tick regardless of whether it ends up
        // skipping.
        if ( class_exists( 'MMI_Import_Preview' ) && (bool) MMI_DB::get_setting( 'mmi_schedule_skip_if_no_data_' . $profile_id, false ) ) {
            $pending = MMI_Import_Preview::get_cached_profile_pending_stats( $profile_id );

            if ( $pending !== null && empty( $pending['stale'] )
                && ! MMI_Import_Preview::is_pending_actionable( $profile_id, $pending ) ) {
                MMI_Logger::info(
                    "Scheduled profile import skipped [{$profile_id}] — no new/changed data pending (last checked {$pending['computed_at']})",
                    [ 'pending' => $pending ],
                    'sync',
                    'MMI_Pipeline_Cron'
                );
                MMI_DB::append_import_history( [
                    'supplier_id'  => implode( ',', $enabled_suppliers ),
                    'profile_id'   => $profile_id,
                    'started_at'   => current_time( 'mysql' ),
                    'completed_at' => current_time( 'mysql' ),
                    'imported'     => 0,
                    'updated'      => 0,
                    'skipped'      => (int) ( $pending['total_in_feed'] ?? 0 ),
                    'errors'       => 0,
                    'status'       => 'Skipped',
                    'notes'        => wp_json_encode( [
                        'reason'  => 'no_new_data',
                        'pending' => $pending,
                    ] ),
                ] );
                MMI_Import_Preview::queue_pending_stats_refresh( $profile_id );
                return;
            }

            // Cache missing, stale, or shows real pending work — proceed
            // with a normal run below, and (re)warm the cache in the
            // background regardless of which of those three it was.
            MMI_Import_Preview::queue_pending_stats_refresh( $profile_id );
        }

        $state_key = 'mmi_pipeline_profile_batch_state_' . $profile_id;

        // Initial lock — just needs to survive until the first batch action fires
        // and renews it (see run_profile_import_batch()). If Action Scheduler never
        // picks up that first action at all, this TTL is what eventually releases
        // the lock; the 90-minute abandoned-run check below is the real "this run
        // is dead" detector.
        MMI_DB::set_job_state( $lock_key, current_time( 'mysql' ), self::PROFILE_BATCH_LOCK_TTL );

        // If a previous dispatch's state record is still sitting here unfinished
        // (finish_profile_import() never ran to clear it — e.g. every batch attempt
        // fataled, as happened with the xchange OOM bug this guards against), don't
        // silently overwrite it and retry forever with zero visible trace. Write a
        // "Failed" history row for the abandoned attempt first, so a human looking at
        // Import History sees a red Failed row instead of an all-green list that's
        // quietly gone stale — this is the actual gap behind "the email says failed
        // but the dashboard only shows Success": the dashboard never had anything to
        // show because finish_profile_import() never got called even once.
        $previous_state = MMI_DB::get_setting( $state_key, null );
        if ( is_array( $previous_state ) && ! empty( $previous_state['started_at'] ) ) {
            $age_seconds = time() - strtotime( $previous_state['started_at'] );
            if ( $age_seconds > 90 * MINUTE_IN_SECONDS ) {
                // A batch action that's still sitting past-due in a backlogged Action
                // Scheduler queue looks identical from here to one whose worker actually
                // crashed — don't assert "fataled" as the cause when the more likely
                // explanation (queue starvation) is directly checkable.
                $as_backlog = self::get_action_scheduler_backlog();
                $last_error = $as_backlog !== null
                    ? 'A previous batch run never reached completion.' . self::describe_as_backlog( $as_backlog )
                    : 'A previous batch run never reached completion — most likely a worker process fataled (e.g. memory exhaustion) without updating the state record.';

                MMI_Logger::error(
                    "Scheduled profile import [{$profile_id}] — previous dispatch from {$previous_state['started_at']} never completed (no finish_profile_import() in " . round( $age_seconds / 60 ) . ' min); recording as Failed and starting fresh.',
                    array_filter( [ 'previous_state' => $previous_state, 'as_backlog' => $as_backlog ] ),
                    'sync',
                    'MMI_Pipeline_Cron'
                );
                $abandoned_history_id = MMI_DB::append_import_history( [
                    'supplier_id'  => implode( ',', array_keys( $previous_state['per_supplier'] ?? [] ) ),
                    'profile_id'   => $profile_id,
                    'started_at'   => $previous_state['started_at'],
                    'completed_at' => current_time( 'mysql' ),
                    'imported'     => 0,
                    'updated'      => 0,
                    'skipped'      => 0,
                    'errors'       => 1,
                    'status'       => 'Failed',
                    'notes'        => wp_json_encode( [
                        'abandoned'     => true,
                        'last_error'    => $last_error,
                        'partial_state' => $previous_state['per_supplier'] ?? [],
                    ] ),
                ] );
                $abandoned_message = 'Previous run abandoned — ' . $last_error;
                $abandoned_context = [
                    [ 'label' => 'Profile',     'value' => $profile_id ],
                    [ 'label' => 'Stuck since', 'value' => $previous_state['started_at'] ],
                ];
                if ( $as_backlog !== null ) {
                    $abandoned_context[] = [ 'label' => 'AS Backlog (site-wide)', 'value' => number_format( $as_backlog['total'] ) . ' overdue' ];
                    $abandoned_context[] = [ 'label' => 'Likely Cause',           'value' => $as_backlog['top_hook'] ];
                }
                self::send_failure_alert( sprintf( 'Import Profile: %s', self::get_profile_label( $profile_id ) ), $abandoned_message, $abandoned_context );
                // Also surface this as a persistent on-page notice — send_failure_alert()
                // only emails; without this, main.php's notice banner (which reads this
                // exact key) never shows anything for an abandoned run.
                MMI_DB::set_setting( 'mmi_pipeline_process_error_profile_' . $profile_id, [
                    'message'    => $abandoned_message,
                    'time'       => current_time( 'mysql' ),
                    'process'    => sprintf( 'Import Profile: %s', self::get_profile_label( $profile_id ) ),
                    'history_id' => (int) $abandoned_history_id,
                ] );

                // The dead run's batch actions may still be sitting in the Action
                // Scheduler queue (e.g. paused mid-retry, or simply never picked up).
                // Cancel them before queuing the fresh run's actions below — otherwise
                // a zombie batch can fire later, read the FRESH state record (since
                // it shares the same $state_key), and either corrupt it via a racing
                // read-modify-write or hit "missing state record" after the fresh run
                // already finished and deleted it. Filtered to this profile's own
                // pending actions only — 'mmi-pipeline-profile-import' is a shared
                // group across every profile, not just this one.
                self::cancel_pending_batch_actions( $profile_id );
            }
        }

        try {
            $per_supplier_state = [];
            foreach ( $enabled_suppliers as $supplier ) {
                $per_supplier_state[ $supplier ] = [
                    'offset'   => 0,
                    'done'     => false,
                    'imported' => 0,
                    'updated'  => 0,
                    'skipped'  => 0,
                    'failed'   => 0,
                ];
            }

            MMI_DB::set_setting( $state_key, [
                'started_at'   => current_time( 'mysql' ),
                'per_supplier' => $per_supplier_state,
            ] );

            foreach ( $enabled_suppliers as $supplier ) {
                as_schedule_single_action( time(), 'mmi_pipeline_profile_import_batch', [ $profile_id, $supplier, 0 ], 'mmi-pipeline-profile-import', false, MMI_PIPELINE_AS_PRIORITY_BATCH );
            }

            MMI_Logger::info( "Scheduled profile import dispatched [{$profile_id}] — " . count( $enabled_suppliers ) . ' supplier batch(es) queued', [], 'sync', 'MMI_Pipeline_Cron' );
        } catch ( \Throwable $e ) {
            MMI_Logger::error( "Scheduled profile import dispatch failed [{$profile_id}]: " . $e->getMessage(), [], 'sync', 'MMI_Pipeline_Cron' );
            MMI_DB::delete_setting( $state_key );
            MMI_DB::delete_job_state( $lock_key );
            self::send_failure_alert( sprintf( 'Import Profile: %s', self::get_profile_label( $profile_id ) ), $e->getMessage(), [
                [ 'label' => 'Profile', 'value' => $profile_id ],
                [ 'label' => 'Stage',   'value' => 'Dispatch' ],
            ] );
        }
    }

    /**
     * Cancel any still-pending mmi_pipeline_profile_import_batch actions for one
     * profile before starting a fresh dispatch over an abandoned run.
     *
     * as_unschedule_all_actions() can't be used here — it matches on hook+args+group,
     * and these actions are scheduled with a per-batch $offset baked into the args,
     * which we don't know in advance. 'mmi-pipeline-profile-import' is also a single
     * group shared by every profile, so an unfiltered group-wide cancel would kill
     * other profiles' legitimately in-flight batches too. Query every pending action
     * in the group instead and cancel only the ones whose first arg matches this
     * profile_id — mirrors the established pattern in
     * mmi-reverb-integration.php's full-store-sync re-dispatch guard.
     *
     * @param string $profile_id
     */
    private static function cancel_pending_batch_actions( string $profile_id ): void {
        if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
            return;
        }

        $pending = as_get_scheduled_actions( [
            'hook'     => 'mmi_pipeline_profile_import_batch',
            'group'    => 'mmi-pipeline-profile-import',
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 500,
        ] );

        if ( empty( $pending ) ) {
            return;
        }

        $store     = \ActionScheduler::store();
        $cancelled = 0;
        foreach ( $pending as $action_id => $action ) {
            $args = $action->get_args();
            if ( ( $args[0] ?? null ) === $profile_id ) {
                $store->cancel_action( (int) $action_id );
                $cancelled++;
            }
        }

        if ( $cancelled > 0 ) {
            MMI_Logger::info(
                "Cancelled {$cancelled} stale batch action(s) for abandoned profile import [{$profile_id}]",
                [],
                'sync',
                'MMI_Pipeline_Cron'
            );
        }
    }

    /**
     * Action Scheduler callback — processes one time-bounded batch of one
     * supplier's catalog for a scheduled profile import. Re-queues itself with
     * the next offset if more remains; if this was the last supplier to finish,
     * triggers finish_profile_import() to write the history row and notify.
     *
     * @param string $profile_id
     * @param string $supplier
     * @param int    $offset
     */
    public static function run_profile_import_batch( string $profile_id, string $supplier, int $offset ): void {
        $lock_key  = 'mmi_profile_import_lock_' . $profile_id;
        $state_key = 'mmi_pipeline_profile_batch_state_' . $profile_id;
        $state     = MMI_DB::get_setting( $state_key, null );

        if ( ! is_array( $state ) || empty( $state['per_supplier'][ $supplier ] ) ) {
            MMI_Logger::error( "Profile import batch [{$profile_id}][{$supplier}] — missing state record, aborting batch", [], 'sync', 'MMI_Pipeline_Cron' );
            return;
        }

        // Renew the lock on every batch tick — see PROFILE_BATCH_LOCK_TTL's docblock.
        // As long as batches keep firing, the lock never goes stale and the next
        // hourly dispatch correctly sees "another run is in progress" and skips,
        // instead of racing a second batch chain against this one's state record.
        MMI_DB::set_job_state( $lock_key, current_time( 'mysql' ), self::PROFILE_BATCH_LOCK_TTL );

        try {
            // Non-Product profiles are delegated to MMI_Dynamic_Record_Importer,
            // the generic sibling importer — see that class's own docblock and
            // DATA_PIPELINE_PHASE2_SCOPING.md Milestone 4. Unlike the manual
            // "Run Import Now" path (ProductImportController::process_import_batch_cron()),
            // this dispatcher already delegates cleanly to one importer class
            // per call, so the branch is a straight substitution rather than
            // needing a separate parallel method.
            $profile_data_type = MMI_DB::get_profiles()[ $profile_id ]['data_type'] ?? 'product';

            if ( 'product' !== $profile_data_type ) {
                $importer = new \MannMade\DataPipeline\Importers\MMI_Dynamic_Record_Importer( $supplier, $profile_id, $profile_data_type );
                $result   = $importer->run(
                    $offset,
                    self::PROFILE_BATCH_ITEM_LIMIT,
                    self::PROFILE_BATCH_TIME_BUDGET,
                    self::PROFILE_BATCH_MEMORY_BUDGET
                );
                // MMI_Dynamic_Record_Importer's stat names (created/errors) differ
                // from Product's (imported/failed) — mapped the same way
                // ProductImportController::process_generic_import_batch() does.
                $stats = [
                    'created'   => $result['stats']['created']   ?? 0,
                    'updated'   => $result['stats']['updated']   ?? 0,
                    'unchanged' => $result['stats']['unchanged'] ?? 0,
                    'skipped'   => $result['stats']['skipped']   ?? 0,
                    'errors'    => $result['stats']['errors']    ?? 0,
                ];
            } else {
                // enable_throttle=false — this batch already runs in an isolated Action
                // Scheduler action with its own time budget; the throttle's
                // wait_for_safe_load() can block up to 900s, which would defeat the
                // whole point of batching (see run_scheduled_profile_import()'s docblock).
                $importer = new \MannMade\DataPipeline\Importers\MMI_Dynamic_Product_Importer( $supplier, $profile_id, false );
                $result   = $importer->run(
                    $offset,
                    self::PROFILE_BATCH_ITEM_LIMIT,
                    self::PROFILE_BATCH_TIME_BUDGET,
                    self::PROFILE_BATCH_MEMORY_BUDGET
                );
                $stats = $result['stats'] ?? [];
            }

            $has_more    = (bool) ( $result['has_more'] ?? false );
            $next_offset = (int) ( $result['next_offset'] ?? $offset );

            $state['per_supplier'][ $supplier ]['offset']    = $next_offset;
            $state['per_supplier'][ $supplier ]['imported'] += (int) ( $stats['created']  ?? 0 );
            $state['per_supplier'][ $supplier ]['updated']  += (int) ( $stats['updated']  ?? 0 );
            $state['per_supplier'][ $supplier ]['skipped']  += (int) ( $stats['skipped']  ?? 0 ) + (int) ( $stats['unchanged'] ?? 0 );
            $state['per_supplier'][ $supplier ]['failed']   += (int) ( $stats['errors']   ?? 0 );
            $state['per_supplier'][ $supplier ]['done']      = ! $has_more;

            // Carry a bounded sample of WHICH items failed and WHY through to
            // finish_profile_import() — without this, a failed run only ever
            // reports a bare count ("Errors: 1"), same gap the manual import
            // path already closed via ProductImportController's $acc_failures.
            // MMI_Dynamic_Record_Importer's get_results() carries no 'failures'
            // key at all, so this is always a no-op for a non-Product profile —
            // consistent with process_generic_import_batch()'s own [] for the
            // same field, not a gap introduced here.
            if ( ! empty( $result['failures'] ) && is_array( $result['failures'] ) ) {
                $existing = $state['per_supplier'][ $supplier ]['failures'] ?? [];
                $state['per_supplier'][ $supplier ]['failures'] = array_slice(
                    array_merge( $existing, $result['failures'] ),
                    0,
                    \MannMade\DataPipeline\Importers\MMI_Dynamic_Product_Importer::MAX_FAILURE_DETAILS
                );
            }

            MMI_DB::set_setting( $state_key, $state );

            if ( $has_more ) {
                as_schedule_single_action( time(), 'mmi_pipeline_profile_import_batch', [ $profile_id, $supplier, $next_offset ], 'mmi-pipeline-profile-import', false, MMI_PIPELINE_AS_PRIORITY_BATCH );
                return;
            }
        } catch ( \Throwable $e ) {
            $state['per_supplier'][ $supplier ]['done']   = true; // stop retrying this supplier this run
            $state['per_supplier'][ $supplier ]['failed'] = (int) $state['per_supplier'][ $supplier ]['failed'] + 1;
            $existing = $state['per_supplier'][ $supplier ]['failures'] ?? [];
            $existing[] = [ 'supplier' => $supplier, 'key' => '(entire batch)', 'reason' => mb_substr( $e->getMessage(), 0, 300 ) ];
            $state['per_supplier'][ $supplier ]['failures'] = $existing;
            MMI_DB::set_setting( $state_key, $state );
            MMI_Logger::error( "Profile import batch error [{$profile_id}][{$supplier}]: " . $e->getMessage(), [], 'sync', 'MMI_Pipeline_Cron' );
        }

        // Only the supplier that finishes LAST reaches here and triggers finalization.
        $all_done = true;
        foreach ( $state['per_supplier'] as $s_state ) {
            if ( empty( $s_state['done'] ) ) {
                $all_done = false;
                break;
            }
        }
        if ( ! $all_done ) {
            return;
        }

        try {
            self::finish_profile_import( $profile_id, $state, $lock_key, $state_key );
        } catch ( \Throwable $e ) {
            MMI_Logger::error( "Profile import finalize error [{$profile_id}]: " . $e->getMessage(), [], 'sync', 'MMI_Pipeline_Cron' );
            MMI_DB::delete_setting( $state_key );
            MMI_DB::delete_job_state( $lock_key );
            self::send_failure_alert( sprintf( 'Import Profile: %s', self::get_profile_label( $profile_id ) ), $e->getMessage(), [
                [ 'label' => 'Profile', 'value' => $profile_id ],
                [ 'label' => 'Stage',   'value' => 'Finalize' ],
            ] );
        }
    }

    /**
     * Finalize a profile import once every enabled supplier's batches are done:
     * write the import_history row, log the activity, queue the success
     * notification, and release the lock/state record. Mirrors exactly what the
     * old synchronous run_scheduled_profile_import() used to do inline at the end
     * of its single call — same fields/shape — so check_stale_profile_imports()
     * and the daily digest email keep working unchanged.
     *
     * @param string $profile_id
     * @param array  $state      The per-profile batch state record.
     * @param string $lock_key
     * @param string $state_key
     */
    private static function finish_profile_import( string $profile_id, array $state, string $lock_key, string $state_key ): void {
        $totals        = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0 ];
        $per_supplier  = [];
        $all_failures  = [];
        foreach ( $state['per_supplier'] as $supplier => $s ) {
            $per_supplier[ $supplier ] = [
                'imported' => $s['imported'],
                'updated'  => $s['updated'],
                'skipped'  => $s['skipped'],
                'failed'   => $s['failed'],
            ];
            foreach ( $totals as $k => $v ) {
                $totals[ $k ] += $s[ $k ];
            }
            if ( ! empty( $s['failures'] ) && is_array( $s['failures'] ) ) {
                $all_failures = array_merge( $all_failures, $s['failures'] );
            }
        }
        $all_failures = array_slice( $all_failures, 0, \MannMade\DataPipeline\Importers\MMI_Dynamic_Product_Importer::MAX_FAILURE_DETAILS );

        // A short, human-readable example of WHAT went wrong — "Errors: 3" alone
        // isn't actionable. Shown in the activity log message and (below) in the
        // on-page error notice; the full list still lives in notes/failures for
        // anyone who needs every occurrence.
        $error_detail = '';
        if ( $totals['failed'] > 0 && ! empty( $all_failures ) ) {
            $first        = $all_failures[0];
            $error_detail = sprintf( '%s (%s): %s', $first['key'] ?? '?', $first['supplier'] ?? $profile_id, $first['reason'] ?? 'unknown error' );
            if ( $totals['failed'] > 1 ) {
                $error_detail .= sprintf( ' (+%d more)', $totals['failed'] - 1 );
            }
        }

        $started_at = $state['started_at'] ?? current_time( 'mysql' );
        $timestamp  = current_time( 'mysql' );
        $duration   = max( 0, strtotime( $timestamp ) - strtotime( $started_at ) );

        $history_id = MMI_DB::append_import_history( [
            'supplier_id'  => implode( ',', array_keys( $per_supplier ) ),
            'profile_id'   => $profile_id,
            'started_at'   => $started_at,
            'completed_at' => $timestamp,
            'imported'     => $totals['imported'],
            'updated'      => $totals['updated'],
            'skipped'      => $totals['skipped'],
            'errors'       => $totals['failed'],
            'status'       => $totals['failed'] > 0 ? 'Partial' : 'Success',
            'notes'        => wp_json_encode( [
                'duration_seconds' => $duration,
                'per_supplier'     => $per_supplier,
                'failures'         => $all_failures,
            ] ),
        ] );

        MMI_DB::add_activity( 'import', sprintf(
            'Profile "%s" import completed in %s — %d imported, %d updated, %d skipped, %d failed%s',
            $profile_id,
            self::format_duration( $duration ),
            $totals['imported'],
            $totals['updated'],
            $totals['skipped'],
            $totals['failed'],
            $error_detail !== '' ? " — {$error_detail}" : ''
        ), [
            'profile_id'       => $profile_id,
            'duration_seconds' => $duration,
            'per_supplier'     => $per_supplier,
            'totals'           => $totals,
            'failures'         => $all_failures,
        ] );

        // Failures here are real (per-item exceptions from a run that otherwise
        // completed) — log level must reflect that or they're invisible to
        // anything watching sync.log at error level, same as an outright crash.
        $log_level = $totals['failed'] > 0 ? 'error' : 'info';
        MMI_Logger::{$log_level}(
            sprintf( 'Scheduled profile import [%s] completed in %s — imported:%d updated:%d skipped:%d failed:%d%s',
                $profile_id, self::format_duration( $duration ),
                $totals['imported'], $totals['updated'], $totals['skipped'], $totals['failed'],
                $error_detail !== '' ? " — {$error_detail}" : ''
            ),
            [ 'per_supplier' => $per_supplier, 'failures' => $all_failures ],
            'sync',
            'MMI_Pipeline_Cron'
        );

        // Queue this profile import for the daily digest email.
        // Count total products available in source feeds to include as a fetch stat.
        $feed_total = 0;
        foreach ( array_keys( $per_supplier ) as $sid ) {
            $fname = ( $sid === 'xchange' ) ? 'xchange-products.json' : "{$sid}-products.json";
            $feed_total += self::count_json_products( $fname );
        }

        self::queue_success_notification( [
            'label'     => sprintf( 'Import Profile: %s', self::get_profile_label( $profile_id ) ),
            'status'    => $totals['failed'] > 0 ? 'partial' : 'success',
            'warning'   => $totals['failed'] > 0 ? 'Some items failed' : '',
            'queued_at' => $timestamp,
            'duration'  => self::format_duration( $duration ),
            'summary'   => [
                [ 'label' => 'Feed Products', 'value' => $feed_total > 0 ? number_format( $feed_total ) : '—' ],
                [ 'label' => 'Updated',       'value' => number_format( $totals['updated'] ) ],
                [ 'label' => 'Skipped',       'value' => number_format( $totals['skipped'] ) ],
                [ 'label' => 'Failed',        'value' => number_format( $totals['failed'] ), 'highlight' => $totals['failed'] > 0 ],
                [ 'label' => 'Duration',      'value' => self::format_duration( $duration ) ],
            ],
        ] );

        // Surface the failure as a persistent on-page notice (main.php reads this
        // key) — this setting was previously only ever deleted here, never set,
        // so a partial-failure run never actually produced a visible notice
        // anywhere on the Data Pipeline page, only a same-day digest email queue
        // entry that could be hours away.
        if ( $totals['failed'] > 0 ) {
            // history_id + totals let the notice say what actually happened
            // (most records imported, a few skipped) and open this run's
            // Run Insights, instead of reading as if the whole import failed.
            MMI_DB::set_setting( 'mmi_pipeline_process_error_profile_' . $profile_id, [
                'message'    => $error_detail !== '' ? $error_detail : "{$totals['failed']} item(s) failed to import",
                'time'       => $timestamp,
                'process'    => sprintf( 'Import Profile: %s', self::get_profile_label( $profile_id ) ),
                'history_id' => (int) $history_id,
                'partial'    => true,
                'totals'     => $totals,
            ] );
        } else {
            MMI_DB::delete_setting( 'mmi_pipeline_process_error_profile_' . $profile_id );
        }
        MMI_DB::delete_setting( $state_key );
        MMI_DB::delete_job_state( $lock_key );
    }


    /* ── Email Notifications ──────────────────────────────────────────────── */

    /**
     * Format a duration in seconds as a human-readable string.
     *
     * Examples: 0.4 → "< 1s", 4.5 → "4.5s", 93 → "1 min 33s", 3720 → "1 hr 2 min".
     *
     * @param  float  $seconds Duration in seconds.
     * @return string
     */
    public static function format_duration( float $seconds ): string {
        if ( $seconds < 1 ) {
            return '< 1s';
        }
        if ( $seconds < 60 ) {
            return round( $seconds, $seconds < 10 ? 1 : 0 ) . 's';
        }
        $mins     = (int) floor( $seconds / 60 );
        $rem_secs = (int) round( $seconds - $mins * 60 );
        if ( $mins < 60 ) {
            return $rem_secs > 0 ? "{$mins} min {$rem_secs}s" : "{$mins} min";
        }
        $hrs     = (int) floor( $mins / 60 );
        $rem_min = $mins % 60;
        return $rem_min > 0 ? "{$hrs} hr {$rem_min} min" : "{$hrs} hr";
    }

    /**
     * Send an import-complete notification for the daily digest.
     * Called from AJAX import controllers after a full run finishes.
     *
     * @param array  $context  Reserved for future context rows (currently unused).
     * @param array  $stats    Associative array: imported, updated, skipped, failed.
     * @param float  $duration Duration in seconds.
     * @param string $timestamp MySQL datetime string for when the run completed.
     */
    public static function send_import_notification( array $context, array $stats, float $duration, string $timestamp ): void {
        $imported = (int) ( $stats['imported'] ?? 0 );
        $updated  = (int) ( $stats['updated']  ?? 0 );
        $skipped  = (int) ( $stats['skipped']  ?? 0 );
        $failed   = (int) ( $stats['failed']   ?? 0 );

        self::queue_success_notification( [
            'label'     => 'Catalog Import (Manual)',
            'status'    => $failed > 0 ? 'partial' : 'success',
            'warning'   => $failed > 0 ? 'Some items failed' : '',
            'queued_at' => $timestamp,
            'duration'  => self::format_duration( $duration ),
            'summary'   => [
                [ 'label' => 'Imported', 'value' => number_format( $imported ) ],
                [ 'label' => 'Updated',  'value' => number_format( $updated ) ],
                [ 'label' => 'Skipped',  'value' => number_format( $skipped ) ],
                [ 'label' => 'Failed',   'value' => number_format( $failed ), 'highlight' => $failed > 0 ],
                [ 'label' => 'Duration', 'value' => self::format_duration( $duration ) ],
            ],
        ] );
    }

    /**
     * Queue a successful process record for the morning daily digest.
     *
     * @param array $entry {
     *   label:    string   — human-readable process name
     *   status:   string   — 'success' | 'partial'
     *   warning:  string   — (optional) why a 'partial' run was partial; the
     *                        digest lists it so "Completed with Warnings"
     *                        always says what the warnings were
     *   queued_at: string  — MySQL datetime
     *   duration: string   — formatted duration
     *   summary:  array    — [{label, value, highlight?}]
     * }
     */
    private static function queue_success_notification( array $entry ): void {
        if ( ! MMI_DB::get_setting( 'mmi_pipeline_import_email_notifications', false ) ) {
            return;
        }
        $queue = MMI_DB::get_setting( 'mmi_email_digest_queue', [] );
        if ( ! is_array( $queue ) ) {
            $queue = [];
        }
        $queue[] = $entry;
        MMI_DB::set_setting( 'mmi_email_digest_queue', $queue );
    }

    /**
     * Register this plugin's two email types with MMI VIP's Email Customization
     * tab so staff can preview/test them with in-progress branding edits — see
     * MMI_Email_Customizer::get_registered_samples() in mmi-vip. Sample data is
     * illustrative (not a live import's real numbers), same as it ever was when
     * this lived hardcoded inside mmi-vip itself.
     */
    public static function register_email_samples( array $samples ): array {
        // MMI_Email_Templates now ships bundled in the shared library (ADR-0006,
        // added as its 15th class 2026-09-18 after being found missing post
        // mmi-hub-elimination) — this guard is kept as defensive dead code,
        // matching how other shared-library classes (e.g. MMI_Settings) are
        // still guarded suite-wide even though they're always present too.
        if ( ! class_exists( 'MMI_Email_Templates' ) ) {
            return $samples;
        }

        $samples[] = [
            'id'     => 'pipeline_failure_alert',
            'group'  => 'Data Pipeline',
            'label'  => 'Failure Alert',
            'render' => function () {
                $site_name = get_bloginfo( 'name' );
                return MMI_Email_Templates::render_alert( [
                    'site_name'     => $site_name,
                    'headline'      => 'Import Profile: Pricing (Stale) Failed',
                    'status_color'  => MMI_Email_Templates::token( 'danger' ),
                    'status_label'  => 'Failed — Immediate Action Required',
                    'completed_at'  => date_i18n( 'F j, Y \a\t g:i a' ),
                    'duration'      => '—',
                    'summary_stats' => [
                        [ 'label' => 'Process',  'value' => 'Import Profile: Pricing (Stale)' ],
                        [ 'label' => 'Profile',  'value' => 'pricing' ],
                        [ 'label' => 'Failed',   'value' => '1', 'highlight' => true ],
                    ],
                    'dashboard_url' => admin_url(),
                    'cta_label'     => 'View Data Pipeline Dashboard &rarr;',
                    'footer_text'   => "This notification was sent by the MMI Data Pipeline plugin on {$site_name}. Manage notification settings from the Data Pipeline dashboard.",
                ] );
            },
        ];

        $samples[] = [
            'id'     => 'pipeline_daily_digest',
            'group'  => 'Data Pipeline',
            'label'  => 'Daily Digest',
            'render' => function () {
                $hero = MMI_Email_Templates::render_hero_cell( '7,645', 'Products Monitored' )
                    . MMI_Email_Templates::render_hero_cell( '3', 'Processes Run' )
                    . MMI_Email_Templates::render_hero_cell( '12', 'Product Changes', MMI_Email_Templates::token( 'primary' ) )
                    . MMI_Email_Templates::render_hero_cell( '0', 'Failures', MMI_Email_Templates::token( 'neutral' ), false );
                $cells = MMI_Email_Templates::render_card_stat_cell( '3', 'Updated', MMI_Email_Templates::token( 'text_dark' ) )
                    . MMI_Email_Templates::render_card_stat_cell( '5,460', 'Skipped', MMI_Email_Templates::token( 'text_dark' ) )
                    . MMI_Email_Templates::render_card_stat_cell( '0', 'Failed', MMI_Email_Templates::token( 'text_dark' ), false );
                $card = MMI_Email_Templates::render_process_card(
                    MMI_Email_Templates::token( 'primary' ), 'Import Profile: Pricing', 'Completed Successfully',
                    'Completed at 7:00 am', '', $cells
                );
                $site_name = get_bloginfo( 'name' );
                return MMI_Email_Templates::render_daily_digest( [
                    'site_name'     => $site_name,
                    'today'         => date_i18n( 'F j, Y' ),
                    'hero_cells'    => $hero,
                    'process_cards' => $card,
                    'dashboard_url' => admin_url(),
                    // Same cta_label/footer_text the real send passes, so the
                    // preview shows the footer this email actually carries —
                    // and correctly shows a site-level footer override winning
                    // over it once one is set.
                    'cta_label'     => 'View Data Pipeline Dashboard &rarr;',
                    'footer_text'   => "This summary is sent once daily at 7 am by the MMI Data Pipeline on <strong>{$site_name}</strong>. Repeated runs of the same profile are grouped. Failures are reported immediately in separate alert emails. Manage settings from the Data Pipeline dashboard.",
                ] );
            },
        ];

        return $samples;
    }

    /**
     * Send an immediate failure alert email.
     * Called from the catch blocks of each scheduled process.
     *
     * @param string $process_name  Human-readable name, e.g. "Supplier Fetch".
     * @param string $error_message The caught exception message.
     * @param array  $context       Extra [{label, value}] rows for the summary box.
     * @param array  $options       Optional overrides: subject, headline,
     *                              status_label, status_color (token name),
     *                              detail_label. Defaults are the failure alert.
     */
    public static function send_failure_alert( string $process_name, string $error_message, array $context = [], array $options = [] ): void {
        if ( ! MMI_DB::get_setting( 'mmi_pipeline_import_email_notifications', false ) ) {
            return;
        }

        $admin_email   = get_option( 'admin_email' );
        $site_name     = get_bloginfo( 'name' );
        $dashboard_url = admin_url( 'admin.php?page=mmi-data-pipeline' );

        // The process name is already the headline, and the time is already
        // on the "Completed:" line — the stat cells are only for extra context.
        $summary_stats = $context;

        $subject = $options['subject'] ?? sprintf( '[%s] ALERT: %s failed — action required', $site_name, $process_name );

        // MMI_Email_Templates ships bundled in the shared library (see bootstrap.php) —
        // this guard is kept as defensive dead code (same precedent as MMI_Settings
        // guards elsewhere), falling back to a plain-text alert in the
        // unreachable-in-practice case it's somehow absent.
        if ( ! class_exists( 'MMI_Email_Templates' ) ) {
            $lines = array_map(
                static function ( $row ) {
                    return "{$row['label']}: {$row['value']}";
                },
                $summary_stats
            );
            $plain_body = sprintf(
                "%s failed on %s.\n\nError: %s\n\n%s\n\nDashboard: %s",
                $process_name,
                $site_name,
                $error_message,
                implode( "\n", $lines ),
                $dashboard_url
            );
            wp_mail( $admin_email, $subject, $plain_body );
            return;
        }

        $status_color = MMI_Email_Templates::token( $options['status_color'] ?? 'danger' );
        $detail_label = esc_html( $options['detail_label'] ?? 'Error Detail' );
        $body = MMI_Email_Templates::render_alert( [
            'site_name'     => $site_name,
            'headline'      => $options['headline'] ?? $process_name . ' Failed',
            'status_color'  => $status_color,
            'status_label'  => $options['status_label'] ?? 'Failed — Immediate Action Required',
            'completed_at'  => wp_date( 'F j, Y \a\t g:i a' ),
            // No duration: a failure alert has no meaningful one, and an empty
            // value makes render_alert() drop the field instead of printing "—".
            'duration'      => '',
            'summary_stats' => $summary_stats,
            'detail_table_header' => "<tr><th style=\"padding:8px 12px;text-align:left;background:rgba(220,38,38,0.08);font-size:12px;text-transform:uppercase;color:{$status_color};\">{$detail_label}</th></tr>",
            'detail_table_rows'   => '<tr><td class="mmi-t-detail-text" style="padding:12px;font-family:monospace;font-size:13px;color:#334155;word-break:break-word;">' . esc_html( $error_message ) . '</td></tr>',
            'dashboard_url' => $dashboard_url,
            'cta_label'     => 'View Data Pipeline Dashboard &rarr;',
            'footer_text'   => "This notification was sent by the MMI Data Pipeline plugin on {$site_name}. Manage notification settings from the Data Pipeline dashboard.",
        ] );

        wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    /* ── Upstream Outage Grace Window ─────────────────────────────────────── */

    /**
     * Hours a source's upstream API may be continuously unavailable before
     * one "unavailable" email goes out. Filterable per source.
     */
    const UPSTREAM_OUTAGE_GRACE_HOURS = 3;

    private static function upstream_outage_key( string $supplier_id ): string {
        return 'mmi_pipeline_upstream_outage_' . $supplier_id;
    }

    /**
     * Whether a fetch exception means the supplier's own API is unavailable
     * (not a problem on this site). Xchange answers "These API's are
     * currently in a HALT mode." during its maintenance windows, then resets
     * connections outright; other suppliers surface as cURL network errors
     * or 5xx statuses via MMI_HTTP_Client. Auth errors, 4xx, bad JSON, and
     * our own code errors are deliberately NOT matched — those still alert
     * immediately.
     */
    public static function is_upstream_unavailable_error( string $message ): bool {
        $patterns = [
            '/\bHALT mode\b/i',
            '/\bmaintenance\b/i',
            '/cURL error (6|7|28|35|52|56):/i',
            '/Connection (reset|refused|timed out)/i',
            '/Operation timed out/i',
            '/Unexpected status 5\d\d\b/',
            '/\bstatus 5\d\d\b/',
        ];
        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $message ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record one failed fetch against an ongoing upstream outage, and send the
     * single "unavailable" alert once the outage outlasts the grace window.
     * The per-run failures still land in the activity log and the dashboard
     * error banner; only the email is held back.
     */
    private static function record_upstream_outage( string $supplier_id, string $message ): void {
        $key    = self::upstream_outage_key( $supplier_id );
        $outage = MMI_DB::get_setting( $key, null );
        if ( ! is_array( $outage ) || empty( $outage['since'] ) ) {
            $outage = [ 'since' => time(), 'failures' => 0, 'alerted' => false ];
        }
        $outage['failures']   = (int) $outage['failures'] + 1;
        $outage['last_error'] = $message;

        $grace_hours = (float) apply_filters( 'mmi_pipeline_upstream_outage_grace_hours', self::UPSTREAM_OUTAGE_GRACE_HOURS, $supplier_id );
        $elapsed     = time() - (int) $outage['since'];

        MMI_Logger::warn(
            "Upstream for \"{$supplier_id}\" unavailable (failure #{$outage['failures']}, " . self::format_duration( (float) $elapsed ) . ' so far) — using the previous feed file.',
            [ 'error' => $message ], 'sync', 'MMI_Pipeline_Cron'
        );

        if ( empty( $outage['alerted'] ) && $elapsed >= $grace_hours * HOUR_IN_SECONDS ) {
            $since = wp_date( 'F j, Y \a\t g:i a', (int) $outage['since'] );
            self::send_failure_alert(
                'Data Fetch: ' . $supplier_id,
                $message,
                [
                    [ 'label' => 'Unavailable Since', 'value' => $since ],
                    [ 'label' => 'Failed Fetches',    'value' => number_format( $outage['failures'] ) ],
                    [ 'label' => 'Feed In Use',       'value' => self::describe_source_feed_age( $supplier_id ) ],
                ],
                [
                    'subject'      => sprintf( '[%s] %s API unavailable since %s', get_bloginfo( 'name' ), $supplier_id, $since ),
                    'headline'     => sprintf( '%s API Unavailable', $supplier_id ),
                    'status_label' => 'Supplier outage — imports are using the last good feed',
                    'status_color' => 'warning',
                    'detail_label' => 'Last Response From Supplier',
                ]
            );
            $outage['alerted'] = true;
        }

        MMI_DB::set_setting( $key, $outage );
    }

    /**
     * Close an upstream outage after a successful fetch. Sends a recovery
     * email only if the "unavailable" alert actually went out — a blip that
     * resolved inside the grace window stays silent end to end.
     */
    private static function resolve_upstream_outage( string $supplier_id ): void {
        $key    = self::upstream_outage_key( $supplier_id );
        $outage = MMI_DB::get_setting( $key, null );
        if ( ! is_array( $outage ) ) {
            return;
        }
        MMI_DB::delete_setting( $key );

        $lasted = self::format_duration( (float) ( time() - (int) ( $outage['since'] ?? time() ) ) );
        MMI_Logger::info( "Upstream for \"{$supplier_id}\" recovered after {$lasted} ({$outage['failures']} failed fetches).", [], 'sync', 'MMI_Pipeline_Cron' );

        if ( empty( $outage['alerted'] ) ) {
            return;
        }
        self::send_failure_alert(
            'Data Fetch: ' . $supplier_id,
            sprintf( 'Fetching succeeded again after %s of upstream unavailability. No action needed.', $lasted ),
            [
                [ 'label' => 'Outage Lasted',  'value' => $lasted ],
                [ 'label' => 'Failed Fetches', 'value' => number_format( (int) $outage['failures'] ) ],
            ],
            [
                'subject'      => sprintf( '[%s] RESOLVED: %s API available again', get_bloginfo( 'name' ), $supplier_id ),
                'headline'     => sprintf( '%s API Recovered', $supplier_id ),
                'status_label' => 'Resolved',
                'status_color' => 'primary',
                'detail_label' => 'Detail',
            ]
        );
    }

    /**
     * "3 hours ago (Sep 27, 10:35 pm)" for a source's products feed file,
     * using the same filename convention as refresh_all_source_counts().
     */
    private static function describe_source_feed_age( string $supplier_id ): string {
        $filename = ( $supplier_id === 'xchange' ) ? 'xchange-products.json' : "{$supplier_id}-products.json";
        $mtime    = @filemtime( mmi_shared_lib_json_dir() . $filename );
        if ( ! $mtime ) {
            return 'none on disk';
        }
        return sprintf( '%s ago (%s)', human_time_diff( $mtime ), wp_date( 'M j, g:i a', $mtime ) );
    }

    /**
     * Detect scheduled per-profile imports that have stopped running.
     *
     * Some failure modes (e.g. a WP-Cron process killed by the system `timeout`
     * wrapper while blocked inside a throttle wait) never throw a catchable PHP
     * exception, so they're invisible to send_failure_alert(). This is a backstop:
     * compare each profile's actual cron recurrence against its last completed
     * history row, and alert when a run is overdue by more than 1.5x its interval.
     * Runs once daily alongside the digest — coarse, but enough to catch a process
     * that has gone silent for hours/days, which is the failure mode that matters.
     */
    public static function check_stale_profile_imports(): void {
        $profiles = MMI_DB::get_profiles();

        // Computed once per run, not per-profile — it's a site-wide snapshot,
        // not something that varies by which profile happens to be stale.
        $as_backlog = self::get_action_scheduler_backlog();

        foreach ( array_keys( (array) $profiles ) as $profile_id ) {
            $staleness = self::get_profile_staleness( $profile_id );
            if ( $staleness === null || ! $staleness['is_stale'] ) {
                continue;
            }

            // Avoid re-sending every day once flagged — only alert again if the
            // staleness has grown by another full interval since the last alert.
            $alert_key  = 'mmi_pipeline_stale_alert_' . $profile_id;
            $last_alert = MMI_DB::get_setting( $alert_key, 0 );
            if ( $last_alert && ( time() - (int) $last_alert ) < $staleness['interval'] ) {
                continue;
            }

            $log_context = [ 'profile_id' => $profile_id, 'last_success' => $staleness['last_success'] ];
            if ( $as_backlog !== null ) {
                $log_context['as_backlog'] = $as_backlog;
            }
            MMI_Logger::warn(
                sprintf( 'Profile "%s" import is stale — last success %s ago, expected every %s%s', $profile_id, self::format_duration( (float) $staleness['age_seconds'] ), self::format_duration( (float) $staleness['interval'] ), $as_backlog !== null ? sprintf( ' — Action Scheduler backlog: %s overdue (top: %s)', number_format( $as_backlog['total'] ), $as_backlog['top_hook'] ) : '' ),
                $log_context,
                'sync',
                'MMI_Pipeline_Cron'
            );

            $message = sprintf(
                'No successful run recorded in %s. Expected to run every %s. The scheduled cron event is still firing, but the import itself is not completing — check for a process being killed mid-run (e.g. by a server time limit) or a fatal error not caught by the normal error handler.',
                self::format_duration( (float) $staleness['age_seconds'] ),
                self::format_duration( (float) $staleness['interval'] )
            );
            $context = [
                [ 'label' => 'Profile',        'value' => $profile_id ],
                [ 'label' => 'Last Success',   'value' => $staleness['last_success'] ?: 'never' ],
                [ 'label' => 'Expected Every',  'value' => self::format_duration( (float) $staleness['interval'] ) ],
            ];
            if ( $as_backlog !== null ) {
                $message   .= self::describe_as_backlog( $as_backlog );
                $context[]  = [ 'label' => 'AS Backlog (site-wide)', 'value' => number_format( $as_backlog['total'] ) . ' overdue' ];
                $context[]  = [ 'label' => 'Likely Cause',           'value' => $as_backlog['top_hook'] ];
            }

            self::send_failure_alert( sprintf( 'Import Profile: %s (Stale)', self::get_profile_label( $profile_id ) ), $message, $context );

            MMI_DB::set_setting( $alert_key, time() );
        }
    }

    /**
     * Compute whether a profile's scheduled import is currently stale, for use by
     * both check_stale_profile_imports() (email alert) and the dashboard's Import
     * History panel (visible badge) — single source of truth so the two can't
     * silently disagree the way the email and an all-green history table did
     * before this existed.
     *
     * @param string $profile_id
     * @return array{is_stale:bool, age_seconds:int|null, interval:int, last_success:string|null}|null
     *         Null when the profile has no active schedule (nothing to monitor).
     *         age_seconds is null when there has never been a single successful run.
     */
    public static function get_profile_staleness( string $profile_id ): ?array {
        $hook  = 'mmi_pipeline_profile_' . $profile_id;
        $event = wp_get_scheduled_event( $hook );

        if ( ! $event || empty( $event->schedule ) ) {
            return null;
        }

        $schedules = wp_get_schedules();
        $interval  = $schedules[ $event->schedule ]['interval'] ?? null;
        if ( ! $interval ) {
            return null;
        }

        global $wpdb;
        $table = MMI_DB::import_history_table();
        // status = 'Success' only — see the comment at the call site in
        // check_stale_profile_imports() for why a recent 'Failed' row must not
        // count as "ran recently enough."
        $last_success = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT completed_at FROM {$table} WHERE profile_id = %s AND status = 'Success' ORDER BY completed_at DESC LIMIT 1",
                $profile_id
            )
        );

        // completed_at is stored via current_time('mysql') — site LOCAL time, not
        // UTC — interpret it in the site's configured timezone before diffing
        // against time()'s UTC epoch.
        $age = null;
        if ( $last_success ) {
            $last_dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $last_success, wp_timezone() );
            $age     = $last_dt ? ( time() - $last_dt->getTimestamp() ) : null;
        }

        $threshold = $interval * 1.5;

        return [
            'is_stale'     => ( $age === null || $age > $threshold ),
            'age_seconds'  => $age,
            'interval'     => $interval,
            'last_success' => $last_success,
        ];
    }

    /**
     * Heartbeat staleness check for listeners on a shared cron hook that can
     * produce zero log output on failure — no success line, no error line,
     * total silence. A hook can carry more than one listener (e.g.
     * mmi_pipeline_source_fetch_xchange fires both this plugin's own
     * product-feed fetch AND MMI_Xchange_Vendors::cron_write_web_assets_json())
     * and a sibling listener's clean log entry makes the digest report the
     * whole hook as healthy while one specific listener on it is dead. This
     * closes that gap without needing to know *why* a listener went quiet —
     * see DIGEST_SILENT_FAILURE_HANDOFF.md for the incident this follows up on.
     *
     * Registry, not a bespoke check per hook: any plugin adds itself via the
     * `mmi_pipeline_listener_heartbeats` filter, each entry shaped:
     *   key                        string   Suffix for the MMI_Settings heartbeat key.
     *   label                      string   Display name for the digest card.
     *   hook                       string   (optional) The shared cron hook this listener
     *                                       rides on — its real configured WP-Cron interval
     *                                       is looked up dynamically, the same way
     *                                       get_profile_staleness() above does for scheduled
     *                                       import profiles, so a site-specific schedule change
     *                                       doesn't need a matching code change here.
     *   fallback_interval_seconds  int      Used only when `hook` isn't currently scheduled
     *                                       (feature just enabled, hook not found, etc.).
     *   is_enabled                 callable (optional) Return false to skip a listener whose
     *                                       owning feature is toggled off on this site — an
     *                                       intentionally-idle listener is not a stale one.
     * The listener itself is responsible for calling
     * MMI_Settings::set( "mmi_listener_last_success_{key}", time() ) next to its
     * own existing success log line — see MMI_Xchange_Vendors::cron_write_web_assets_json().
     *
     * @return array<int, array{label:string, last_success:int, age_seconds:int|null, interval:int}>
     */
    private static function get_stale_listeners(): array {
        $registry = apply_filters( 'mmi_pipeline_listener_heartbeats', [] );
        if ( ! is_array( $registry ) || empty( $registry ) ) {
            return [];
        }

        $wp_schedules = null;
        $stale        = [];

        foreach ( $registry as $entry ) {
            $key = (string) ( $entry['key'] ?? '' );
            if ( $key === '' ) {
                continue;
            }

            if ( isset( $entry['is_enabled'] ) && is_callable( $entry['is_enabled'] ) && ! call_user_func( $entry['is_enabled'] ) ) {
                continue;
            }

            $interval = null;
            if ( ! empty( $entry['hook'] ) ) {
                $event = wp_get_scheduled_event( (string) $entry['hook'] );
                if ( $event && ! empty( $event->schedule ) ) {
                    $wp_schedules = $wp_schedules ?? wp_get_schedules();
                    $interval     = $wp_schedules[ $event->schedule ]['interval'] ?? null;
                }
            }
            if ( ! $interval ) {
                $interval = (int) ( $entry['fallback_interval_seconds'] ?? 0 );
            }
            if ( $interval <= 0 ) {
                continue;
            }

            $last_success = (int) MMI_Settings::get( "mmi_listener_last_success_{$key}", 0 );
            $age          = $last_success > 0 ? ( time() - $last_success ) : null;
            $threshold    = $interval * 1.5;

            if ( $age === null || $age > $threshold ) {
                $stale[] = [
                    'label'        => (string) ( $entry['label'] ?? $key ),
                    'last_success' => $last_success,
                    'age_seconds'  => $age,
                    'interval'     => $interval,
                ];
            }
        }

        return $stale;
    }

    /**
     * Send one combined summary email covering all processes that succeeded
     * since the last digest. Fired by the mmi_daily_email_digest cron at 7 am.
     *
     * Multiple runs of the same process (e.g. hourly Pricing imports) are collapsed
     * into a single grouped card showing aggregated stats and a run-count + time range.
     */
    public static function run_daily_digest(): void {
        self::check_stale_profile_imports();

        if ( ! MMI_DB::get_setting( 'mmi_pipeline_import_email_notifications', false ) ) {
            MMI_DB::delete_setting( 'mmi_email_digest_queue' );
            return;
        }

        $queue = MMI_DB::get_setting( 'mmi_email_digest_queue', [] );
        if ( ! is_array( $queue ) ) {
            $queue = [];
        }

        // A registered listener on a shared cron hook (see get_stale_listeners())
        // can go completely silent — no success line, no error line — while a
        // sibling listener on the same hook keeps logging cleanly. That sibling's
        // queue entries keep $queue non-empty and its group's status 'success',
        // so this check must run independently of whether $queue has anything in
        // it, or the silent listener never gets a line anywhere. See
        // DIGEST_SILENT_FAILURE_HANDOFF.md.
        $stale_listeners = self::get_stale_listeners();

        if ( empty( $queue ) && empty( $stale_listeners ) ) {
            MMI_DB::delete_setting( 'mmi_email_digest_queue' );
            return;
        }

        // Clear immediately to prevent re-send on concurrent runs.
        MMI_DB::delete_setting( 'mmi_email_digest_queue' );

        $admin_email   = get_option( 'admin_email' );
        $site_name     = get_bloginfo( 'name' );
        $dashboard_url = admin_url( 'admin.php?page=mmi-data-pipeline' );
        $today         = wp_date( 'F j, Y' );

        // ── Step 1: Group queue entries by label ────────────────────────────
        // Stats labelled "Imported", "Updated", "Failed" are additive across runs.
        // All other stats (Skipped, Total Products, Sources, Duration, etc.) show
        // the last observed value — they represent catalog snapshots, not changes.
        $summable_labels = [ 'Imported', 'Updated', 'Failed' ];
        $groups          = [];

        foreach ( $queue as $entry ) {
            $lbl = $entry['label'] ?? 'Process';

            if ( ! isset( $groups[ $lbl ] ) ) {
                $groups[ $lbl ] = [
                    'label'    => $lbl,
                    'status'   => 'success',
                    'runs'     => 0,
                    'first_ts' => null,
                    'last_ts'  => null,
                    'stats'    => [],   // stat_label => [ sum, last_value, summable, highlight ]
                    'warnings' => [],   // reason => [ count, last_ts ]
                ];
            }

            $g = &$groups[ $lbl ];
            $g['runs']++;

            $ts = ! empty( $entry['queued_at'] ) ? strtotime( $entry['queued_at'] ) : null;

            if ( ( $entry['status'] ?? 'success' ) === 'partial' ) {
                $g['status'] = 'partial';
                // Entries queued before 'warning' existed have no reason.
                $reason = (string) ( $entry['warning'] ?? '' ) ?: 'Warning';
                $w      = $g['warnings'][ $reason ] ?? [ 'count' => 0, 'last_ts' => null ];
                $w['count']++;
                if ( $ts && ( $w['last_ts'] === null || $ts > $w['last_ts'] ) ) {
                    $w['last_ts'] = $ts;
                }
                $g['warnings'][ $reason ] = $w;
            }
            if ( $ts ) {
                if ( $g['first_ts'] === null || $ts < $g['first_ts'] ) {
                    $g['first_ts'] = $ts;
                }
                if ( $g['last_ts'] === null || $ts > $g['last_ts'] ) {
                    $g['last_ts'] = $ts;
                }
            }

            foreach ( $entry['summary'] ?? [] as $stat ) {
                $sl       = $stat['label'] ?? '';
                $sv       = $stat['value'] ?? '—';
                $summable = in_array( $sl, $summable_labels, true );
                $numeric  = (float) preg_replace( '/[^0-9.]/', '', str_replace( ',', '', $sv ) );

                if ( ! isset( $g['stats'][ $sl ] ) ) {
                    $g['stats'][ $sl ] = [
                        'sum'       => 0.0,
                        'last'      => $sv,
                        'summable'  => $summable,
                        'highlight' => $stat['highlight'] ?? false,
                    ];
                }

                if ( $summable ) {
                    $g['stats'][ $sl ]['sum'] += $numeric;
                } else {
                    $g['stats'][ $sl ]['last'] = $sv;
                }

                if ( ! empty( $stat['highlight'] ) ) {
                    $g['stats'][ $sl ]['highlight'] = true;
                }
            }

            unset( $g );
        }

        // ── Step 2: Compute hero-level totals ───────────────────────────────
        $total_runs     = count( $queue );
        $total_changes  = 0;
        $total_failures = 0;
        $catalog_size   = 0; // best-effort: from Supplier Fetch "Total Products" or max Skipped

        foreach ( $queue as $entry ) {
            if ( ( $entry['label'] ?? '' ) === 'Supplier Fetch' ) {
                foreach ( $entry['summary'] ?? [] as $stat ) {
                    if ( ( $stat['label'] ?? '' ) === 'Total Products' ) {
                        $catalog_size = (int) str_replace( ',', '', $stat['value'] ?? '0' );
                        break;
                    }
                }
            }
        }

        foreach ( $groups as $g ) {
            foreach ( $g['stats'] as $sl => $st ) {
                if ( $st['summable'] ) {
                    if ( in_array( $sl, [ 'Imported', 'Updated' ], true ) ) {
                        $total_changes += (int) $st['sum'];
                    }
                    if ( $sl === 'Failed' ) {
                        $total_failures += (int) $st['sum'];
                    }
                }
                // Fallback catalog size from import profile "Skipped" stat
                if ( ! $catalog_size && $sl === 'Skipped' && ! $st['summable'] ) {
                    $v = (int) str_replace( ',', '', $st['last'] );
                    if ( $v > $catalog_size ) {
                        $catalog_size = $v;
                    }
                }
            }
        }

        // MMI_Email_Templates ships bundled in the shared library (see bootstrap.php) —
        // this guard is kept as defensive dead code (same precedent as MMI_Settings
        // guards elsewhere), falling back to a plain-text digest built from the
        // same $groups/$total_* data in the unreachable-in-practice case it's
        // somehow absent.
        if ( ! class_exists( 'MMI_Email_Templates' ) ) {
            $lines = [ sprintf( 'Daily digest for %s — %s', $site_name, $today ) ];
            $lines[] = sprintf(
                'Products monitored: %s | Processes run: %d | Product changes: %d | Failures: %d',
                $catalog_size > 0 ? number_format( $catalog_size ) : '—',
                $total_runs,
                $total_changes,
                $total_failures
            );
            foreach ( $groups as $g ) {
                $stat_bits = [];
                foreach ( $g['stats'] as $sl => $st ) {
                    $stat_bits[] = $sl . ': ' . ( $st['summable'] ? (string) (int) $st['sum'] : $st['last'] );
                }
                $lines[] = sprintf(
                    '- %s (%s, %d run%s): %s',
                    $g['label'],
                    $g['status'] === 'partial' ? 'completed with warnings' : 'completed successfully',
                    $g['runs'],
                    $g['runs'] === 1 ? '' : 's',
                    implode( ', ', $stat_bits )
                );
                if ( ! empty( $g['warnings'] ) ) {
                    $lines[] = '    Warnings: ' . self::describe_group_warnings( $g );
                }
            }
            foreach ( $stale_listeners as $sl ) {
                $lines[] = sprintf(
                    '- WARNING: %s has not logged a successful run in %s (expected every %s)',
                    $sl['label'],
                    $sl['age_seconds'] !== null ? self::format_duration( (float) $sl['age_seconds'] ) : 'since tracking began',
                    self::format_duration( (float) $sl['interval'] )
                );
            }
            $lines[] = 'Dashboard: ' . $dashboard_url;
            wp_mail( $admin_email, sprintf( '[%s] Daily Data Pipeline Digest', $site_name ), implode( "\n", $lines ) );
            return;
        }

        $changes_color  = $total_changes  > 0 ? MMI_Email_Templates::token('primary') : MMI_Email_Templates::token('neutral');
        $failures_color = $total_failures > 0 ? MMI_Email_Templates::token('danger')  : MMI_Email_Templates::token('neutral');
        $hero_cells     =
            MMI_Email_Templates::render_hero_cell( $catalog_size > 0 ? number_format( $catalog_size ) : '—', 'Products Monitored' ) .
            MMI_Email_Templates::render_hero_cell( (string) $total_runs, 'Processes Run' ) .
            MMI_Email_Templates::render_hero_cell( number_format( $total_changes ), 'Product Changes', $changes_color ) .
            MMI_Email_Templates::render_hero_cell( number_format( $total_failures ), 'Failures', $failures_color, false );

        // ── Step 3: Build one card per group, or one table per family ───────
        // A "family" is everything before the first ": " in a group's label
        // (e.g. "Data Fetch: xchange" and "Data Fetch: skuport" share the
        // family "Data Fetch"). A family with only one member renders exactly
        // as before — a full card. A family with more than one member is
        // collapsed into a single table card (render_process_table_card())
        // instead of repeating the same card shell once per member.
        $families = [];
        foreach ( $groups as $label => $g ) {
            if ( strpos( $label, ': ' ) !== false ) {
                [ $family, $member ] = explode( ': ', $label, 2 );
            } else {
                $family = $label;
                $member = '';
            }
            $families[ $family ][] = [ 'member' => $member, 'group' => $g ];
        }

        $process_cards = '';

        // Stale-listener warnings render first, as their own cards — never
        // folded into a sibling listener's group/family card above, per the
        // whole point of this check (see get_stale_listeners()).
        foreach ( $stale_listeners as $sl ) {
            $time_range = $sl['age_seconds'] !== null
                ? 'Silent for ' . self::format_duration( (float) $sl['age_seconds'] ) . ' (expected every ' . self::format_duration( (float) $sl['interval'] ) . ')'
                : 'No successful run has ever been logged (expected every ' . self::format_duration( (float) $sl['interval'] ) . ')';

            $cells = MMI_Email_Templates::render_card_stat_cell(
                $sl['age_seconds'] !== null ? self::format_duration( (float) $sl['age_seconds'] ) : 'Never',
                'Silent For',
                MMI_Email_Templates::token( 'danger' ),
                false
            );

            $process_cards .= MMI_Email_Templates::render_process_card(
                MMI_Email_Templates::token( 'danger' ),
                esc_html( $sl['label'] ),
                'No Activity Logged',
                $time_range,
                '',
                $cells
            );
        }

        foreach ( $families as $family => $members ) {
            if ( count( $members ) === 1 ) {
                $g      = $members[0]['group'];
                $label  = esc_html( $g['label'] );
                $color  = $g['status'] === 'partial' ? MMI_Email_Templates::token('warning') : MMI_Email_Templates::token('primary');
                $badge  = $g['status'] === 'partial' ? 'Completed with Warnings' : 'Completed Successfully';
                $runs   = $g['runs'];

                // Subtitle: single run shows "Completed at TIME", multi-run shows range
                if ( $runs === 1 && $g['last_ts'] ) {
                    $time_range = 'Completed at ' . wp_date( 'g:i a', $g['last_ts'] );
                } elseif ( $runs > 1 && $g['first_ts'] && $g['last_ts'] ) {
                    $time_range = "Ran {$runs} times &middot; "
                        . wp_date( 'g:i a', $g['first_ts'] )
                        . ' &ndash; '
                        . wp_date( 'g:i a', $g['last_ts'] );
                } else {
                    $time_range = "Ran {$runs} time" . ( $runs === 1 ? '' : 's' );
                }

                // Check if this group had any actual product activity
                $group_changes  = 0;
                $group_failures = 0;
                foreach ( $g['stats'] as $sl => $st ) {
                    if ( $st['summable'] ) {
                        if ( in_array( $sl, [ 'Imported', 'Updated' ], true ) ) {
                            $group_changes += (int) $st['sum'];
                        }
                        if ( $sl === 'Failed' ) {
                            $group_failures += (int) $st['sum'];
                        }
                    }
                }
                $is_stable = ( $group_changes === 0 && $group_failures === 0 );

                // Stable banner (only shown when multiple runs produced zero activity)
                $stable_row = ( $is_stable && $runs > 1 ) ? MMI_Email_Templates::render_stable_banner( 'Catalog Stable &mdash; No Changes Made Across All Runs' ) : '';
                if ( ! empty( $g['warnings'] ) ) {
                    $stable_row = self::render_digest_warning_row( self::describe_group_warnings( $g ) ) . $stable_row;
                }

                // Stat cells — summable stats show their total; snapshot stats show last value.
                // Total cell count determines which one is last (no trailing border on it).
                $stat_labels  = array_keys( $g['stats'] );
                $total_cells  = count( $stat_labels ) + ( $runs > 1 ? 1 : 0 );
                $cell_index   = 0;
                $cells        = '';

                foreach ( $g['stats'] as $sl => $st ) {
                    $cell_index++;
                    $display = $st['summable']
                        ? number_format( (int) $st['sum'] )
                        : esc_html( $st['last'] );
                    // Always set an explicit color — render_card_stat_cell() requires one
                    // even in the default case: with <meta name="color-scheme"> in <head>,
                    // dark-mode email clients invert unstyled text to white, making it
                    // invisible against this template's light backgrounds.
                    $color_for_cell = ( $st['highlight'] && ( $st['summable'] ? $st['sum'] > 0 : true ) )
                        ? MMI_Email_Templates::token('danger')
                        : MMI_Email_Templates::token('text_dark');
                    $cells .= MMI_Email_Templates::render_card_stat_cell(
                        $display, $sl, $color_for_cell, $cell_index < $total_cells
                    );
                }

                // Append a "Runs Today" cell for grouped processes — always the last cell when present.
                if ( $runs > 1 ) {
                    $cells .= MMI_Email_Templates::render_card_stat_cell(
                        (string) $runs, 'Runs Today', MMI_Email_Templates::token('accent'), false
                    );
                }

                $process_cards .= MMI_Email_Templates::render_process_card( $color, $label, $badge, $time_range, $stable_row, $cells );
                continue;
            }

            // Multiple members sharing this family — one compact table instead
            // of N repeated card shells.
            $has_source_stat  = false;
            $stat_label_order = [];
            $any_multi_run    = false;
            $any_warnings     = false;
            $family_status    = 'success';
            foreach ( $members as $m ) {
                if ( $m['group']['status'] === 'partial' ) {
                    $family_status = 'partial';
                }
                if ( ! empty( $m['group']['warnings'] ) ) {
                    $any_warnings = true;
                }
                if ( $m['group']['runs'] > 1 ) {
                    $any_multi_run = true;
                }
                foreach ( $m['group']['stats'] as $sl => $st ) {
                    if ( strcasecmp( $sl, 'Source' ) === 0 ) {
                        $has_source_stat = true;
                    }
                    if ( ! in_array( $sl, $stat_label_order, true ) ) {
                        $stat_label_order[] = $sl;
                    }
                }
            }

            // A "Source" stat already identifies each row (its value is the
            // member's own name), so a redundant leading identity column is
            // only added when no such stat exists.
            $columns = $has_source_stat ? [] : [ 'Process' ];
            foreach ( $stat_label_order as $sl ) {
                $columns[] = $sl;
            }
            if ( $any_multi_run ) {
                $columns[] = 'Runs Today';
            }
            if ( $any_warnings ) {
                $columns[] = 'Warnings';
            }

            $rows = [];
            foreach ( $members as $m ) {
                $g     = $m['group'];
                $cells = [];

                if ( ! $has_source_stat ) {
                    $cells['Process'] = $m['member'] !== '' ? $m['member'] : $g['label'];
                }

                foreach ( $g['stats'] as $sl => $st ) {
                    $display = $st['summable']
                        ? number_format( (int) $st['sum'] )
                        : $st['last'];
                    $color = ( $st['highlight'] && ( $st['summable'] ? $st['sum'] > 0 : true ) )
                        ? MMI_Email_Templates::token('danger')
                        : MMI_Email_Templates::token('text_dark');
                    $cells[ $sl ] = [ 'value' => $display, 'color' => $color ];
                }

                if ( $any_multi_run ) {
                    $cells['Runs Today'] = (string) $g['runs'];
                }
                if ( $any_warnings ) {
                    $cells['Warnings'] = ! empty( $g['warnings'] ) ? self::describe_group_warnings( $g ) : '—';
                }

                $rows[] = [ 'cells' => $cells ];
            }

            $family_color = $family_status === 'partial' ? MMI_Email_Templates::token('warning') : MMI_Email_Templates::token('primary');
            $family_badge = $family_status === 'partial' ? 'Completed with Warnings' : 'Completed Successfully';

            $process_cards .= MMI_Email_Templates::render_process_table_card( $family_color, $family, $family_badge, $columns, $rows );
        }

        $unique_types = count( $groups );
        $subject      = sprintf( '[%s] Daily Process Summary — %d run%s (%d type%s) on %s',
            $site_name,
            $total_runs,
            $total_runs === 1 ? '' : 's',
            $unique_types,
            $unique_types === 1 ? '' : 's',
            $today
        );

        $body = MMI_Email_Templates::render_daily_digest( [
            'site_name'     => $site_name,
            'today'         => $today,
            'hero_cells'    => $hero_cells,
            'process_cards' => $process_cards,
            'dashboard_url' => $dashboard_url,
            'cta_label'     => 'View Data Pipeline Dashboard &rarr;',
            'footer_text'   => "This summary is sent once daily at 7 am by the MMI Data Pipeline on <strong>{$site_name}</strong>. Repeated runs of the same profile are grouped. Failures are reported immediately in separate alert emails. Manage settings from the Data Pipeline dashboard.",
        ] );

        wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
        MMI_Logger::info( "Daily digest sent: {$total_runs} run(s) across {$unique_types} process type(s)", [], 'sync', 'MMI_Pipeline_Cron' );
    }

    /**
     * One line naming what a digest group's warnings were, e.g.
     * "No new data: 8 of 29 runs (last 11:36 am)".
     */
    private static function describe_group_warnings( array $g ): string {
        $parts = [];
        foreach ( $g['warnings'] as $reason => $w ) {
            $count = $g['runs'] > 1
                ? sprintf( '%d of %d runs', $w['count'], $g['runs'] )
                : '';
            $last  = $w['last_ts'] ? 'last ' . wp_date( 'g:i a', $w['last_ts'] ) : '';
            $tail  = implode( ', ', array_filter( [ $count, $last ] ) );
            $parts[] = $reason . ( $tail !== '' ? " ({$tail})" : '' );
        }
        return implode( '; ', $parts );
    }

    /** Warning line inside a single process card (same shape as the stable banner). */
    private static function render_digest_warning_row( string $text ): string {
        return '<tr><td colspan="12" style="padding:6px 12px;font-size:11px;color:' . esc_attr( MMI_Email_Templates::token( 'text_dark' ) ) . ';background:' . esc_attr( MMI_Email_Templates::token( 'bg_panel' ) ) . ';">'
            . '&#9888; ' . esc_html( $text ) . '</td></tr>';
    }

    /* ── Fetch warnings (dashboard) ───────────────────────────────────────── */

    /** Reason text for a fetch that left the feed file unchanged. ASCII only (wp_mmi is latin1). */
    const FETCH_WARNING_NO_NEW_DATA = 'No new data: feed file not rewritten';

    const FETCH_WARNINGS_SETTING = 'mmi_pipeline_recent_fetch_warnings';

    /**
     * How long after a completed fetch a re-trigger of the same source counts
     * as a duplicate: 5 minutes, or half the source's interval when shorter.
     */
    private static function duplicate_fetch_window( string $supplier_id ): int {
        $schedule  = wp_get_schedule( 'mmi_pipeline_source_fetch_' . $supplier_id );
        $schedules = wp_get_schedules();
        $interval  = ( $schedule && isset( $schedules[ $schedule ]['interval'] ) )
            ? (int) $schedules[ $schedule ]['interval']
            : HOUR_IN_SECONDS;
        return max( 60, min( 5 * MINUTE_IN_SECONDS, intdiv( $interval, 2 ) ) );
    }

    /**
     * Keep a 24-hour record of fetch warnings. A source's last_fetch_status
     * is overwritten by its next run, so without this the dashboard showed
     * nothing by the time the digest reported "Completed with Warnings".
     */
    private static function record_fetch_warning( string $supplier_id, string $reason ): void {
        $log = MMI_DB::get_setting( self::FETCH_WARNINGS_SETTING, [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        $log[]  = [ 'source' => $supplier_id, 'at' => time(), 'reason' => $reason ];
        $cutoff = time() - DAY_IN_SECONDS;
        $log    = array_values( array_filter( $log, static fn( $w ) => (int) ( $w['at'] ?? 0 ) >= $cutoff ) );
        MMI_DB::set_setting( self::FETCH_WARNINGS_SETTING, array_slice( $log, -200 ) );
    }

    /**
     * Fetch warnings from the last 24 hours, grouped by source then reason.
     *
     * @return array<string, array<string, array{count:int, last:int}>>
     */
    public static function get_recent_fetch_warnings(): array {
        $log = MMI_DB::get_setting( self::FETCH_WARNINGS_SETTING, [] );
        if ( ! is_array( $log ) ) {
            return [];
        }
        $cutoff = time() - DAY_IN_SECONDS;
        $out    = [];
        foreach ( $log as $w ) {
            $at = (int) ( $w['at'] ?? 0 );
            if ( $at < $cutoff || empty( $w['source'] ) ) {
                continue;
            }
            $reason = (string) ( $w['reason'] ?? 'Warning' );
            $cur    = $out[ $w['source'] ][ $reason ] ?? [ 'count' => 0, 'last' => 0 ];
            $out[ $w['source'] ][ $reason ] = [ 'count' => $cur['count'] + 1, 'last' => max( $cur['last'], $at ) ];
        }
        return $out;
    }

    /* ── Data helpers ─────────────────────────────────────────────────────── */

    /**
     * Refresh `last_fetch_at`, `last_fetch_count`, `last_fetch_duration` for
     * every enabled source in `wp_mmi_data_sources` by reading the live JSON.
     *
     * Falls back to legacy hardcoded filenames when no `json_files` config key.
     *
     * @return array  Per-source result: [{supplier_id, name, count, status, duration_ms}]
     */
    private static function refresh_all_source_counts( ?float $fetch_started_at = null, ?string $only_supplier_id = null ): array {
        global $wpdb;

        $ds_table = $wpdb->prefix . 'mmi_data_sources';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$ds_table}'" ) !== $ds_table ) {
            return [];
        }

        // Scoped to one source when called from a per-source cron run — each
        // source can now fetch on its own cadence, so comparing EVERY row's
        // file mtime against a single tick's $fetch_started_at (the
        // unscoped/all-rows query below) would misjudge every OTHER source
        // (fetched on its own, slower schedule) as newly "stale" just
        // because this run started more recently than their last real fetch.
        if ( $only_supplier_id !== null ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT supplier_id, supplier_name, configuration FROM {$ds_table} WHERE config_status = 'validated' AND supplier_id = %s",
                    $only_supplier_id
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                "SELECT supplier_id, supplier_name, configuration FROM {$ds_table} WHERE config_status = 'validated'",
                ARRAY_A
            );
        }

        $results = [];

        foreach ( $rows as $row ) {
            $sid       = $row['supplier_id'];
            $name      = $row['supplier_name'] ?? $sid;
            $cfg       = json_decode( $row['configuration'] ?: '{}', true ) ?: [];
            $t0        = microtime( true );

            // Determine the primary JSON filename
            if ( ! empty( $cfg['json_files'] ) && is_array( $cfg['json_files'] ) ) {
                reset( $cfg['json_files'] );
                $filename = key( $cfg['json_files'] );
            } else {
                $filename = ( $sid === 'xchange' ) ? 'xchange-products.json' : "{$sid}-products.json";
            }

            $count = self::count_json_products( $filename );
            $dur_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

            // This method only ever READS whatever feed file is already on disk
            // — it does not fetch. Stamping last_fetch_at = now() here (the old
            // behaviour) therefore reported "fetched just now" for a source
            // whose updater had silently stopped running, indefinitely: XChange
            // sat frozen for 6 days while this column claimed a successful
            // fetch every 12 hours. Record the file's real mtime instead, so the
            // column answers "how fresh is this data" truthfully, and only call
            // it a success when the file was actually (re)written by the fetch
            // run that invoked us.
            $json_path  = mmi_shared_lib_json_dir();
            $file_mtime = @filemtime( $json_path . $filename ) ?: null;

            $was_written = $file_mtime !== null
                && $fetch_started_at !== null
                && $file_mtime >= (int) floor( $fetch_started_at );

            if ( $count === 0 ) {
                $status = 'empty';
            } elseif ( $fetch_started_at === null ) {
                // Called outside a fetch run (manual refresh of the counts):
                // report data age without asserting anything about a fetch.
                $status = 'success';
            } else {
                $status = $was_written ? 'success' : 'stale';
            }

            if ( $count > 0 ) {
                $wpdb->update(
                    $ds_table,
                    [
                        'last_fetch_at'          => $file_mtime
                            ? gmdate( 'Y-m-d H:i:s', $file_mtime + ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) )
                            : current_time( 'mysql' ),
                        'last_fetch_status'      => $status,
                        'last_fetch_count'       => $count,
                        'last_fetch_duration_ms' => $dur_ms,
                        'updated_at'             => current_time( 'mysql' ),
                    ],
                    [ 'supplier_id' => $sid ],
                    [ '%s', '%s', '%d', '%d', '%s' ],
                    [ '%s' ]
                );
            }

            if ( $status === 'stale' ) {
                MMI_Logger::warn(
                    sprintf(
                        'Supplier fetch produced no new data for "%s" — %s was last written %s. Its updater is failing or being skipped.',
                        $sid,
                        $filename,
                        $file_mtime ? gmdate( 'Y-m-d H:i:s', $file_mtime ) . ' UTC' : 'never'
                    ),
                    [ 'supplier_id' => $sid, 'file' => $filename, 'mtime' => $file_mtime ],
                    'sync',
                    'MMI_Pipeline_Cron'
                );
            }

            $results[] = [
                'supplier_id' => $sid,
                'name'        => $name,
                'count'       => $count,
                'status'      => $status,
                'duration_ms' => $dur_ms,
            ];
        }

        return $results;
    }

    /**
     * Count items in a supplier's JSON feed.
     *
     * @param  string      $filename  Filename relative to mmi_shared_lib_json_dir().
     * @param  string|null $key       Array key holding the product list; null for root.
     * @return int
     */
    private static function count_json_products( string $filename, ?string $key = null ): int {
        $json_path = mmi_shared_lib_json_dir();
        $file      = $json_path . $filename;

        if ( ! file_exists( $file ) ) {
            return 0;
        }

        $data = json_decode( file_get_contents( $file ), true );
        if ( ! is_array( $data ) ) {
            return 0;
        }
        if ( $key && isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
            return count( $data[ $key ] );
        }
        // Detect wrapped product arrays (e.g. {"products": [...]})
        if ( isset( $data[0] ) ) {
            return count( $data );
        }
        if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
            return count( $data['products'] );
        }
        if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
            return count( $data['data'] );
        }
        if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
            return count( $data['items'] );
        }
        // Flat SKU-keyed dict — exclude known metadata keys
        $meta_keys = [ 'wa_count', 'debug', 'timezone', 'server_zone', 'zone_offset', 'api_time', 'status', 'metadata', 'meta' ];
        return count( array_diff_key( $data, array_flip( $meta_keys ) ) );
    }

}
