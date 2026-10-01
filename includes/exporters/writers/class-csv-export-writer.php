<?php
/**
 * CSV Export Writer
 *
 * Batches are separate AJAX requests, so no PHP file handle survives between
 * them — open() reopens in append mode ('a') on every batch, writing the
 * header row only when $is_new is true.
 *
 * @package MannMade\DataPipeline\Exporters
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_CSV_Export_Writer implements MMI_Export_Writer {

    /** @var resource|null */
    private $handle = null;

    public function open( string $file_path, array $columns, bool $is_new ): void {
        $this->handle = fopen( $file_path, $is_new ? 'w' : 'a' );
        if ( $this->handle === false ) {
            $this->handle = null;
            return;
        }
        if ( $is_new ) {
            fputcsv( $this->handle, $columns );
        }
    }

    public function append_row( array $row ): void {
        if ( ! $this->handle ) {
            return;
        }
        $flat = array_map(
            static function ( $value ) {
                if ( is_array( $value ) ) {
                    return implode( ', ', $value );
                }
                return (string) $value;
            },
            $row
        );
        fputcsv( $this->handle, $flat );
    }

    public function close(): void {
        if ( $this->handle ) {
            fclose( $this->handle );
            $this->handle = null;
        }
    }

    public function get_extension(): string {
        return 'csv';
    }

    /** CSV has no wrapping array/root element to close — nothing to do. */
    public function finalize( string $file_path ): void {
    }
}
