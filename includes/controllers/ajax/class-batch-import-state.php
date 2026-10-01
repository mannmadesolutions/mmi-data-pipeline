<?php
/**
 * Batch Import State
 *
 * Lock/progress/history plumbing for the JS-driven batch-import loop —
 * extracted from ProductImportController as part of the god-class
 * decomposition. The controller's AJAX handlers no longer need to know the
 * literal job-state keys or TTL values; they call into this class instead.
 *
 * @package MannMade\DataPipeline\AJAX
 */

namespace MannMade\DataPipeline\AJAX;

use MMI_DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Batch_Import_State {

    /** Job-state key used as the processing lock. */
    const LOCK_KEY = 'mmi_manual_import_running';

    /** Job-state key that stores live progress for the JS polling loop. */
    const PROGRESS_KEY = 'mmi_manual_import_progress';

    /** Lock TTL in seconds. Sized to cover the longest expected full import. */
    const LOCK_TTL = 3600; // 1 hour

    /** Max wall-clock seconds to spend processing products per cron batch. */
    const TIME_BUDGET = 8;

    /**
     * Max per-product failure detail rows kept in job state / history notes.
     * The failure COUNT is always accurate; only the detail list is capped so
     * a pathological run (thousands of failures) can't bloat the job-state row
     * returned to the JS polling loop on every batch.
     */
    const MAX_FAILURE_DETAILS = 25;

    public static function is_locked(): bool {
        return (bool) MMI_DB::get_job_state( self::LOCK_KEY );
    }

    public static function acquire_lock(): void {
        MMI_DB::set_job_state( self::LOCK_KEY, 1, self::LOCK_TTL );
    }

    public static function release_lock(): void {
        MMI_DB::delete_job_state( self::LOCK_KEY );
    }

    public static function get_progress(): ?array {
        return MMI_DB::get_job_state( self::PROGRESS_KEY );
    }

    public static function set_progress( array $progress ): void {
        MMI_DB::set_job_state( self::PROGRESS_KEY, $progress, self::LOCK_TTL );
    }

    /**
     * Merge a batch's field-change stats into the running accumulator.
     * Both arrays have the shape: [ supplier ][ field_name ] = [ changed, unchanged ].
     */
    public static function merge_field_stats( array $acc, array $batch ): array {
        foreach ( $batch as $supplier => $fields ) {
            foreach ( $fields as $field => $counts ) {
                $acc[ $supplier ][ $field ]['changed']   = ( $acc[ $supplier ][ $field ]['changed']   ?? 0 ) + ( $counts['changed']   ?? 0 );
                $acc[ $supplier ][ $field ]['unchanged'] = ( $acc[ $supplier ][ $field ]['unchanged'] ?? 0 ) + ( $counts['unchanged'] ?? 0 );
            }
        }
        return $acc;
    }
}
