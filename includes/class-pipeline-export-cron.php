<?php
/**
 * Pipeline Export Cron — Scheduled/Recurring Export Profiles
 *
 * Structural sibling of MMI_Pipeline_Cron's per-profile import scheduling
 * (run_scheduled_profile_import()/run_profile_import_batch()), applied to
 * the export direction. Deliberately a leaner implementation than the
 * import side's — that file's defensive layers (abandoned-run detection at
 * 90 minutes, cancel_pending_batch_actions() re-dispatch guard, failure
 * alert emails) were added over time in response to specific production
 * incidents (see its own docblocks) that export profiles haven't
 * experienced yet. This class keeps the two rules that are non-negotiable
 * per CLAUDE.md regardless of feature maturity — Action Scheduler for any
 * multi-batch background work (Rule 3), and a structured state record
 * (Diagnostics & State of Operations) — and defers the rest until an
 * export-specific incident actually motivates them, rather than
 * pre-emptively copying every import-side safeguard into a much smaller,
 * lower-traffic feature.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Export_Cron {

    const BATCH_ITEM_LIMIT   = 300;
    const BATCH_TIME_BUDGET  = 25.0;
    const BATCH_MEMORY_BUDGET = 200 * 1024 * 1024;

    /** Lock TTL — same reasoning as PROFILE_BATCH_LOCK_TTL: renewed every batch tick,
     *  only needs to bridge the gap between two consecutive AS batches. */
    const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

    const AS_GROUP = 'mmi-pipeline-export-profile';

    public static function init(): void {
        $profiles = MMI_DB::get_profiles_by_direction( 'export' );
        foreach ( array_keys( (array) $profiles ) as $profile_id ) {
            $hook = 'mmi_pipeline_export_profile_' . $profile_id;
            add_action( $hook, static function () use ( $profile_id ) {
                self::run_scheduled_export( $profile_id );
            } );

            // Self-heal: a frequency saved but the event missing (e.g. after a
            // scheduling change or a WP-Cron table reset) silently never fires
            // again — re-register it here so every request checks, not just
            // when the Export tab happens to be rendered.
            $freq = MMI_DB::get_setting( 'mmi_schedule_export_profile_' . $profile_id, 'disabled' );
            if ( $freq !== 'disabled' && ! wp_next_scheduled( $hook ) ) {
                $wp_schedules = wp_get_schedules();
                if ( isset( $wp_schedules[ $freq ] ) ) {
                    wp_schedule_event( time(), $freq, $hook );
                }
            }
        }

        add_action( 'mmi_pipeline_export_profile_batch', [ __CLASS__, 'run_export_profile_batch' ], 10, 3 );
    }

    /**
     * Dispatcher — fires from the profile's WP-Cron hook. Queues the first
     * Action Scheduler batch and returns immediately; never exports anything
     * itself (same reasoning as the import dispatcher: a multi-batch export
     * must never risk being hard-killed by the wp-cron.php runner's ~55s
     * timeout).
     */
    public static function run_scheduled_export( string $profile_id ): void {
        $lock_key = 'mmi_export_profile_lock_' . $profile_id;
        if ( MMI_DB::get_job_state( $lock_key ) ) {
            MMI_Logger::info( "Scheduled export skipped [{$profile_id}] — another run is in progress", [], 'sync', 'MMI_Pipeline_Export_Cron' );
            return;
        }
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            MMI_Logger::error( "Scheduled export [{$profile_id}] cannot start — Action Scheduler is not available", [], 'sync', 'MMI_Pipeline_Export_Cron' );
            return;
        }

        $profiles = MMI_DB::get_profiles();
        if ( ! isset( $profiles[ $profile_id ] ) || ( $profiles[ $profile_id ]['direction'] ?? 'import' ) !== 'export' ) {
            MMI_Logger::error( "Scheduled export [{$profile_id}] aborted — profile not found or not an export profile", [], 'sync', 'MMI_Pipeline_Export_Cron' );
            return;
        }

        // data_type/format are re-derived by run_export_profile_batch() itself for
        // every batch (including the first) rather than passed through here — the
        // dispatcher's only job is to queue that first batch action.
        $run_id = MMI_Export_File_Manager::build_run_id( $profiles[ $profile_id ]['name'] ?? $profile_id );

        MMI_DB::set_job_state( $lock_key, current_time( 'mysql' ), self::LOCK_TTL );

        $state_key = 'mmi_pipeline_export_state_' . $profile_id;
        MMI_DB::set_setting( $state_key, [
            'run_id'          => $run_id,
            'started_at'      => current_time( 'mysql' ),
            'status'          => 'dispatched',
            'completed_at'    => null,
            'total_records'   => 0,
            'records_exported' => 0,
            'records_failed'  => 0,
            'last_error'      => null,
        ] );

        try {
            as_schedule_single_action( time(), 'mmi_pipeline_export_profile_batch', [ $profile_id, $run_id, 0 ], self::AS_GROUP, false, MMI_PIPELINE_AS_PRIORITY_BATCH );
            MMI_Logger::info( "Scheduled export dispatched [{$profile_id}] run_id={$run_id}", [], 'sync', 'MMI_Pipeline_Export_Cron' );
        } catch ( \Throwable $e ) {
            MMI_DB::set_setting( $state_key, array_merge(
                MMI_DB::get_setting( $state_key, [] ),
                [ 'status' => 'failed', 'completed_at' => current_time( 'mysql' ), 'last_error' => $e->getMessage() ]
            ) );
            MMI_DB::delete_job_state( $lock_key );
            MMI_Logger::error( "Scheduled export dispatch failed [{$profile_id}]: " . $e->getMessage(), [], 'sync', 'MMI_Pipeline_Export_Cron' );
        }
    }

    /**
     * Action Scheduler batch worker — processes one time/memory-bounded
     * slice via MMI_Dynamic_Data_Exporter and re-queues itself if more
     * remains. No silent failures (per CLAUDE.md): any exception updates the
     * state record to status=failed with last_error populated before
     * returning, rather than just letting the AS action fail invisibly.
     */
    public static function run_export_profile_batch( string $profile_id, string $run_id, int $offset ): void {
        $lock_key  = 'mmi_export_profile_lock_' . $profile_id;
        $state_key = 'mmi_pipeline_export_state_' . $profile_id;

        // Renew the lock every tick — same reasoning as the import side's
        // per-batch renewal (bridges AS re-queue latency, not the whole run).
        MMI_DB::set_job_state( $lock_key, current_time( 'mysql' ), self::LOCK_TTL );

        $profiles = MMI_DB::get_profiles();
        $data_type = $profiles[ $profile_id ]['data_type'] ?? 'product';
        $format    = MMI_DB::get_setting( 'mmi_schedule_export_format_' . $profile_id, 'csv' );

        try {
            $exporter = new MMI_Dynamic_Data_Exporter( $profile_id, $data_type, $run_id, $format );
            $result   = $exporter->run( $offset, self::BATCH_ITEM_LIMIT, self::BATCH_TIME_BUDGET, self::BATCH_MEMORY_BUDGET );

            $state = MMI_DB::get_setting( $state_key, [] );
            $state['status']            = 'running';
            $state['total_records']     = $result['total_records'];
            $state['records_exported']  = (int) ( $state['records_exported'] ?? 0 ) + (int) $result['stats']['exported'];
            $state['records_failed']    = (int) ( $state['records_failed']   ?? 0 ) + (int) $result['stats']['errors'];

            if ( $result['has_more'] ) {
                MMI_DB::set_setting( $state_key, $state );
                as_schedule_single_action( time(), 'mmi_pipeline_export_profile_batch', [ $profile_id, $run_id, $result['next_offset'] ], self::AS_GROUP, false, MMI_PIPELINE_AS_PRIORITY_BATCH );
                return;
            }

            $state['status']       = 'complete';
            $state['completed_at'] = current_time( 'mysql' );
            MMI_DB::set_setting( $state_key, $state );
            MMI_DB::delete_job_state( $lock_key );

            MMI_DB::append_export_history( [
                'profile_id'       => $profile_id,
                'data_type'        => $data_type,
                'format'           => $format,
                'started_at'       => $state['started_at'] ?? $state['completed_at'],
                'completed_at'     => $state['completed_at'],
                'total_records'    => $state['total_records'],
                'records_exported' => $state['records_exported'],
                'records_failed'   => $state['records_failed'],
                'status'           => $state['records_failed'] > 0 ? 'Partial' : 'Success',
                'run_id'           => $run_id,
                'trigger'          => 'scheduled',
            ] );

            // Once per finished run (not per batch).
            if ( function_exists( 'mmi_data_pipeline_audit' ) ) {
                mmi_data_pipeline_audit( 'export.generate', [
                    'object_type' => 'export_profile',
                    'object_id'   => $profile_id,
                    'outcome'     => 'success',
                    'details'     => [ 'trigger' => 'scheduled', 'data_type' => $data_type, 'format' => $format, 'run_id' => $run_id, 'records' => (int) $state['records_exported'] ],
                ] );
            }

            MMI_Logger::info( "Scheduled export complete [{$profile_id}] run_id={$run_id} exported={$state['records_exported']}", [], 'sync', 'MMI_Pipeline_Export_Cron' );
        } catch ( \Throwable $e ) {
            $state = MMI_DB::get_setting( $state_key, [] );
            $state['status']       = 'failed';
            $state['completed_at'] = current_time( 'mysql' );
            $state['last_error']   = $e->getMessage();
            MMI_DB::set_setting( $state_key, $state );
            MMI_DB::delete_job_state( $lock_key );

            MMI_DB::append_export_history( [
                'profile_id'       => $profile_id,
                'data_type'        => $data_type,
                'format'           => $format,
                'started_at'       => $state['started_at'] ?? $state['completed_at'],
                'completed_at'     => $state['completed_at'],
                'total_records'    => $state['total_records']    ?? 0,
                'records_exported' => $state['records_exported'] ?? 0,
                'records_failed'   => $state['records_failed']   ?? 0,
                'status'           => 'Failed',
                'run_id'           => $run_id,
                'trigger'          => 'scheduled',
                'error'            => $e->getMessage(),
            ] );

            MMI_Logger::error( "Scheduled export batch failed [{$profile_id}] run_id={$run_id}: " . $e->getMessage(), [], 'sync', 'MMI_Pipeline_Export_Cron' );
        }
    }

    /**
     * Staleness check for admin UI badges — mirrors the "expected interval x 1.5"
     * rule from CLAUDE.md's Diagnostics & State of Operations section.
     */
    public static function get_profile_state( string $profile_id ): ?array {
        return MMI_DB::get_setting( 'mmi_pipeline_export_state_' . $profile_id, null );
    }
}
