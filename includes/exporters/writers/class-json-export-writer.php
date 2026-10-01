<?php
/**
 * JSON Export Writer
 *
 * Streaming-array writer: opens with a leading '[', appends comma-separated
 * JSON objects per batch (reopened in append mode each batch, same
 * cross-request constraint as the CSV writer), and the exporter closes the
 * array with a trailing ']' only on the final batch via close( $is_final ).
 * A naive "encode the whole array and write once" approach would require
 * holding every row in memory for the whole run, which defeats the
 * batching/memory-budget contract the exporter is built around.
 *
 * @package MannMade\DataPipeline\Exporters
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_JSON_Export_Writer implements MMI_Export_Writer {

    /** @var resource|null */
    private $handle = null;

    /** @var bool Whether at least one row has been written (controls comma placement). */
    private bool $wrote_row = false;

    public function open( string $file_path, array $columns, bool $is_new ): void {
        $this->handle = fopen( $file_path, $is_new ? 'w' : 'a' );
        if ( $this->handle === false ) {
            $this->handle = null;
            return;
        }
        if ( $is_new ) {
            fwrite( $this->handle, '[' );
        } else {
            // Resuming a prior batch — a row was already written, so the next
            // append_row() call must prepend a comma.
            $this->wrote_row = true;
        }
    }

    public function append_row( array $row ): void {
        if ( ! $this->handle ) {
            return;
        }
        if ( $this->wrote_row ) {
            fwrite( $this->handle, ',' );
        }
        fwrite( $this->handle, wp_json_encode( $row ) );
        $this->wrote_row = true;
    }

    /**
     * Closes the file handle only — does NOT write the closing ']', since
     * that must only happen once, on the final batch. Callers finalize the
     * array via finalize().
     */
    public function close(): void {
        if ( $this->handle ) {
            fclose( $this->handle );
            $this->handle = null;
        }
    }

    /**
     * Append the closing ']' — call exactly once, after the last batch of a
     * run has been written and closed.
     */
    public function finalize( string $file_path ): void {
        $handle = fopen( $file_path, 'a' );
        if ( $handle ) {
            fwrite( $handle, ']' );
            fclose( $handle );
        }
    }

    public function get_extension(): string {
        return 'json';
    }
}
