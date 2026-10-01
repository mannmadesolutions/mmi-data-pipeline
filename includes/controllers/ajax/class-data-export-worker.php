<?php
/**
 * Data Export Worker
 *
 * Per-batch execution logic invoked by ExportController's AJAX handler.
 * Mirrors class-product-import-worker.php's role on the import side.
 *
 * @package MannMade\DataPipeline\AJAX
 */

namespace MannMade\DataPipeline\AJAX;

use MMI_DB;
use MMI_Dynamic_Data_Exporter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Data_Export_Worker {

    /**
     * Process one time-bounded batch of the running export and persist
     * updated progress. Returns the current progress array.
     */
    public static function process_batch(): array {
        $progress = Batch_Export_State::get_progress();

        if ( ! $progress || $progress['status'] !== 'running' ) {
            return $progress ?? [ 'status' => 'idle' ];
        }

        $profile_id = $progress['profile']   ?? 'default';
        $data_type  = $progress['data_type'] ?? 'product';
        $run_id     = $progress['run_id']    ?? '';
        $format     = $progress['format']    ?? 'csv';
        $offset     = (int) ( $progress['offset'] ?? 0 );

        $exporter = new MMI_Dynamic_Data_Exporter( $profile_id, $data_type, $run_id, $format );
        $result   = $exporter->run( $offset, 300, Batch_Export_State::TIME_BUDGET );

        $progress['offset']   = $result['next_offset'];
        $progress['total']    = $result['total_records'];
        $progress['exported'] = (int) ( $progress['exported'] ?? 0 ) + (int) ( $result['stats']['exported'] ?? 0 );
        $progress['errors']   = (int) ( $progress['errors']   ?? 0 ) + (int) ( $result['stats']['errors']   ?? 0 );

        if ( $result['has_more'] ) {
            $progress['status'] = 'running';
        } else {
            $progress['status']       = 'complete';
            $progress['completed_at'] = current_time( 'mysql' );
            $progress['download_url'] = self::build_download_url( $run_id, $format );
            Batch_Export_State::release_lock();

            MMI_DB::append_export_history( [
                'profile_id'       => $profile_id,
                'data_type'        => $data_type,
                'format'           => $format,
                'started_at'       => $progress['started_at'] ?? $progress['completed_at'],
                'completed_at'     => $progress['completed_at'],
                'total_records'    => $progress['total'],
                'records_exported' => $progress['exported'],
                'records_failed'   => $progress['errors'],
                'status'           => $progress['errors'] > 0 ? 'Partial' : 'Success',
                'run_id'           => $run_id,
                'trigger'          => 'manual',
            ] );
        }

        Batch_Export_State::set_progress( $progress );

        return $progress;
    }

    public static function build_download_url( string $run_id, string $format ): string {
        return add_query_arg(
            [
                'action'  => 'mmi_pipeline_download_export',
                'run_id'  => $run_id,
                'ext'     => $format,
                '_wpnonce' => wp_create_nonce( 'mmi_pipeline_download_export' ),
            ],
            admin_url( 'admin-post.php' )
        );
    }
}
