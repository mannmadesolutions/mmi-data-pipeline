<?php
/**
 * Batch Export State
 *
 * Lock/progress plumbing for the JS-driven batch-export loop — same shape
 * as Batch_Import_State (its direct sibling) with export-specific job-state
 * keys.
 *
 * @package MannMade\DataPipeline\AJAX
 */

namespace MannMade\DataPipeline\AJAX;

use MMI_DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Batch_Export_State {

    /** Job-state key used as the processing lock. */
    const LOCK_KEY = 'mmi_manual_export_running';

    /** Job-state key that stores live progress for the JS polling loop. */
    const PROGRESS_KEY = 'mmi_manual_export_progress';

    /** Lock TTL in seconds. */
    const LOCK_TTL = 3600; // 1 hour

    /** Max wall-clock seconds to spend per batch. */
    const TIME_BUDGET = 8;

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
}
