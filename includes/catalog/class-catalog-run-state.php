<?php
/**
 * Catalog Run State
 *
 * Sole owner of the catalog-update lock and the persisted phase-run record
 * shared by the AJAX button path, the WP-Cron/Action-Scheduler dispatch
 * chain, and WP-CLI. Per this project's Operational Continuity Rule 11
 * ("one lock, every call site, when a stateful resource has more than one
 * entry point"), no other file may touch the `mmi_catalog_updater_lock`
 * job-state key directly — every entry point into a catalog run goes
 * through try_acquire()/reacquire()/release() here.
 *
 * Historically MMI_Pipeline_Catalog_Updater::run() held this same lock key
 * via a plain get_job_state()-then-set_job_state() pair released by a
 * register_shutdown_function() — safe for a single synchronous CLI/cron
 * process, but useless for the AJAX phase runner, where each phase is its
 * own HTTP request and the lock must survive between them. This class
 * replaces that pattern with a token-scoped lock that:
 *   - is acquired atomically (INSERT IGNORE on the PRIMARY KEY state_key,
 *     not a read-then-write pair — see try_acquire()),
 *   - is re-entrant only for the process holding the matching run_id
 *     (reacquire()), so a stale browser tab can never heartbeat a run it
 *     didn't start, and
 *   - has a TTL sized to the longest single PHASE (900s), not the whole
 *     run — heartbeated at every phase boundary via reacquire(), per Rule 4
 *     ("lock TTLs must match actual runtime, not a safe-feeling
 *     overestimate"). The prior 3300s TTL meant an abandoned browser tab
 *     blocked the next scheduled run for 55 minutes.
 *
 * @package MannMade\DataPipeline\Catalog
 */

namespace MannMade\DataPipeline\Catalog;

use MMI_DB;
use MMI_Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Catalog_Run_State {

    /** Job-state key used as the processing lock — same key MMI_Pipeline_Catalog_Updater::run() used historically. */
    const LOCK_KEY = 'mmi_catalog_updater_lock';

    /** Job-state key storing the persisted phase list, current index, and accumulated results. */
    const STATE_KEY = 'mmi_catalog_run_state';

    /**
     * Lock TTL in seconds. Sized to cover the longest single phase
     * (override_stock/categories/brands walk the full catalog), not the
     * whole run — the lock is heartbeated at every phase boundary via
     * reacquire(), so the run's TOTAL duration is unbounded but never
     * unguarded for more than one phase's worth of silence.
     */
    const LOCK_TTL = 900;

    /**
     * Attempt to start a new run. Returns a new run_id token on success,
     * or null if a run is already in progress (lock not expired).
     *
     * Atomic by construction: INSERT IGNORE against state_key's PRIMARY KEY
     * is the single decisive step — $wpdb->rows_affected tells us whether we
     * won the race, unlike a get_job_state() read followed by a separate
     * set_job_state() write, which two concurrent callers can both pass.
     *
     * @param string $owner 'ajax' | 'cron' | 'cli' — recorded for diagnostics only.
     * @return string|null
     */
    public static function try_acquire( string $owner ): ?string {
        global $wpdb;

        $table = MMI_DB::job_state_table();

        // Reap an expired lock first — UTC_TIMESTAMP(), not NOW(): MMI_DB
        // writes expires_at via gmdate(), so a local-time NOW() comparison
        // on a non-UTC server would treat a live lock as already expired.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE state_key = %s AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()",
                self::LOCK_KEY
            )
        );

        $run_id     = wp_generate_uuid4();
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL );
        $value      = maybe_serialize( [
            'run_id'     => $run_id,
            'owner'      => $owner,
            'started_at' => current_time( 'mysql' ),
        ] );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (state_key, state_value, expires_at, created_at, updated_at)
                 VALUES (%s, %s, %s, NOW(), NOW())",
                self::LOCK_KEY,
                $value,
                $expires_at
            )
        );

        if ( (int) $wpdb->rows_affected !== 1 ) {
            return null; // Someone else holds it.
        }

        MMI_Logger::info( "Catalog run lock acquired [{$owner}] run_id={$run_id}", [], 'sync', 'Catalog_Run_State' );
        return $run_id;
    }

    /**
     * Refresh the lock's TTL for a run already in progress. Fails (returns
     * false) if the stored lock's run_id doesn't match — this is what makes
     * the lock re-entrant only for its own owner, not for a second run that
     * happens to fire while this one is still heartbeating.
     */
    public static function reacquire( string $run_id ): bool {
        global $wpdb;

        $lock = self::get_lock_row();
        if ( ! $lock || ( $lock['run_id'] ?? null ) !== $run_id ) {
            return false;
        }

        $table      = MMI_DB::job_state_table();
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL );
        $wpdb->update(
            $table,
            [ 'expires_at' => $expires_at, 'updated_at' => current_time( 'mysql', true ) ],
            [ 'state_key' => self::LOCK_KEY ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        return true;
    }

    /**
     * Release the lock. No-op (does not delete) if the stored run_id doesn't
     * match $run_id — a stale tab or a delayed retry can never drop a live
     * run's lock out from under it.
     */
    public static function release( string $run_id ): void {
        $lock = self::get_lock_row();
        if ( ! $lock || ( $lock['run_id'] ?? null ) !== $run_id ) {
            return;
        }

        MMI_DB::delete_job_state( self::LOCK_KEY );
        MMI_Logger::info( "Catalog run lock released run_id={$run_id}", [], 'sync', 'Catalog_Run_State' );
    }

    /** @return array{run_id:string,owner:string,started_at:string}|null */
    public static function get_lock_row(): ?array {
        $value = MMI_DB::get_job_state( self::LOCK_KEY );
        return is_array( $value ) ? $value : null;
    }

    public static function is_locked(): bool {
        return self::get_lock_row() !== null;
    }

    /**
     * Persist the phase list + progress for one run. TTL matches the lock's
     * TTL so the two expire together rather than one outliving the other.
     */
    public static function save_state( string $run_id, array $state ): void {
        $state['run_id'] = $run_id;
        MMI_DB::set_job_state( self::STATE_KEY . '_' . $run_id, $state, self::LOCK_TTL );
    }

    public static function get_state( string $run_id ): ?array {
        $value = MMI_DB::get_job_state( self::STATE_KEY . '_' . $run_id );
        return is_array( $value ) ? $value : null;
    }

    public static function delete_state( string $run_id ): void {
        MMI_DB::delete_job_state( self::STATE_KEY . '_' . $run_id );
    }
}
