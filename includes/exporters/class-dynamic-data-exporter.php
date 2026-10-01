<?php
/**
 * Dynamic Data Exporter
 *
 * Structural mirror of MMI_Dynamic_Product_Importer::run() — same resumable
 * batch contract (offset/limit/time_budget/memory_budget in, next_offset/
 * has_more out) — but reading FROM the DB via a MMI_Data_Type_Handler and
 * writing TO a file via a MMI_Export_Writer, instead of reading a supplier
 * feed and writing to WooCommerce.
 *
 * @package MannMade\DataPipeline\Exporters
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Dynamic_Data_Exporter {

    private string $profile_id;
    private string $data_type;
    private string $run_id;
    private string $format;

    private array $profile;
    private array $scope;
    private array $field_mappings;
    private ?MMI_Data_Type_Handler $handler;
    private MMI_Export_Writer $writer;

    private array $stats = [ 'exported' => 0, 'errors' => 0 ];
    private array $log_entries = [];
    private int $total_records = 0;
    private int $next_offset = 0;
    private bool $has_more = false;

    public function __construct( string $profile_id, string $data_type, string $run_id, string $format = 'csv' ) {
        $this->profile_id = $profile_id;
        $this->data_type  = $data_type;
        $this->run_id     = $run_id;
        $this->format     = $format;

        $profiles       = MMI_DB::get_profiles();
        $this->profile  = $profiles[ $profile_id ] ?? [];
        $decoded_scope  = $this->profile['product_identifier'] ?? [];
        $this->scope    = is_array( $decoded_scope ) ? $decoded_scope : [];

        $this->field_mappings = MMI_Pipeline_Field_Schema_Resolver::get_effective( $profile_id, $data_type, 'export' );
        $this->handler         = MMI_Data_Type_Registry::get( $data_type );

        $this->writer = self::make_writer( $format );
    }

    private static function make_writer( string $format ): MMI_Export_Writer {
        switch ( $format ) {
            case 'json':
                return new MMI_JSON_Export_Writer();
            case 'xml':
                return new MMI_XML_Export_Writer();
            case 'csv':
            default:
                return new MMI_CSV_Export_Writer();
        }
    }

    private function log( string $message, string $type = 'info' ): void {
        $this->log_entries[] = [ 'message' => $message, 'type' => $type, 'time' => current_time( 'mysql' ) ];
        if ( $type === 'error' ) {
            MMI_Logger::error( $message, [ 'run_id' => $this->run_id ], 'data-pipeline', 'MMI_Dynamic_Data_Exporter' );
        } else {
            MMI_Logger::info( $message, [ 'run_id' => $this->run_id ], 'data-pipeline', 'MMI_Dynamic_Data_Exporter' );
        }
    }

    /**
     * @return string[] Ordered list of enabled output columns.
     */
    private function get_enabled_columns(): array {
        $columns = [];
        foreach ( $this->field_mappings as $field => $config ) {
            if ( ! empty( $config['enabled'] ) ) {
                $columns[] = $field;
            }
        }
        return $columns;
    }

    /**
     * Mirrors MMI_Dynamic_Product_Importer::run()'s signature exactly.
     *
     * @return array Same shape as MMI_Dynamic_Product_Importer::get_results().
     */
    public function run( int $offset = 0, ?int $limit = null, ?float $time_budget_seconds = null, ?int $memory_budget_bytes = null ): array {
        $start_time = microtime( true );
        $limit      = $limit ?? 300;

        // Memory budget is measured as GROWTH since this call started, not an
        // absolute memory_get_usage() ceiling — a fixed ceiling assumes every
        // execution context boots WordPress at roughly the same baseline
        // footprint, which is false in practice (confirmed on this install:
        // WP-CLI alone boots at ~255MB with the full plugin suite loaded,
        // already above a 200MB absolute ceiling before a single record is
        // processed). An absolute check that trips before any work happens
        // makes every batch report "memory budget reached" at offset 0
        // forever — a silent, permanently-stuck job, not a safety net.
        $memory_baseline = memory_get_usage( true );

        if ( ! $this->handler ) {
            $this->log( "No handler registered for data type '{$this->data_type}'", 'error' );
            $this->has_more = false;
            return $this->get_results();
        }

        $columns = $this->get_enabled_columns();
        if ( empty( $columns ) ) {
            $this->log( 'No fields enabled for export — nothing to write.', 'error' );
            $this->has_more = false;
            return $this->get_results();
        }

        $result        = $this->handler->query_records( $this->scope, $limit, $offset );
        $records       = $result['records'];
        $this->total_records = $result['total'];

        $file_path = MMI_Export_File_Manager::file_path( $this->run_id, $this->writer->get_extension() );
        $this->writer->open( $file_path, $columns, $offset === 0 );

        $processed = 0;
        foreach ( $records as $record ) {
            if ( $time_budget_seconds !== null && ( microtime( true ) - $start_time ) >= $time_budget_seconds ) {
                break;
            }
            if ( $memory_budget_bytes !== null && ( memory_get_usage( true ) - $memory_baseline ) >= $memory_budget_bytes ) {
                $this->log( 'Memory budget reached — pausing batch.', 'warning' );
                break;
            }

            try {
                $flat = $this->handler->resolve_record( $record );
                $row  = [];
                foreach ( $columns as $field ) {
                    $config     = $this->field_mappings[ $field ] ?? [];
                    $transform  = $config['transform'] ?? 'none';
                    $params     = is_array( $config['transform_params'] ?? null ) ? $config['transform_params'] : [];
                    $value      = $flat[ $field ] ?? '';
                    $row[ $field ] = MMI_Pipeline_Field_Resolver::apply_transform( $value, $transform, $params, $flat );
                }
                $this->writer->append_row( $row );
                $this->stats['exported']++;
            } catch ( \Throwable $e ) {
                $this->stats['errors']++;
                $this->log( 'Row export failed: ' . $e->getMessage(), 'error' );
            }

            $processed++;
        }

        $this->writer->close();

        $this->next_offset = $offset + $processed;
        $this->has_more     = $this->next_offset < $this->total_records;

        // JSON/XML writers stream an array/root-element across batches — the
        // closing bracket/tag can only be appended once, after the true final
        // batch. finalize() is a no-op for writers with no such wrapper (CSV) —
        // see MMI_Export_Writer::finalize().
        if ( ! $this->has_more ) {
            $this->writer->finalize( $file_path );
        }

        $this->log( "Exported batch: {$processed} record(s), offset {$offset} -> {$this->next_offset} of {$this->total_records}" );

        return $this->get_results();
    }

    public function get_results(): array {
        return [
            'success'       => true,
            'profile'       => $this->profile_id,
            'data_type'     => $this->data_type,
            'run_id'        => $this->run_id,
            'format'        => $this->format,
            'stats'         => $this->stats,
            'log'           => $this->log_entries,
            'total_records' => $this->total_records,
            'next_offset'   => $this->next_offset,
            'has_more'      => $this->has_more,
        ];
    }
}
