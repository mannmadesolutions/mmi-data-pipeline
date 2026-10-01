<?php
/**
 * Upload Source Fetcher
 *
 * Builds a data source's cached {supplier_id}-products.json directly from its
 * uploaded file, for source_type === 'upload' sources only. Every non-legacy
 * (Xchange/SkuPort/Plugivery) source's cache is otherwise built by
 * MannMade\Integrations\Acquisition\Data_Source_Manager — a class referenced
 * across this plugin (ImportSettingsController, QuickImportController,
 * SourceFetchController, RunSupplierFetchController, class-pipeline-cron.php)
 * as the mechanism for exactly this, but which does not exist anywhere in the
 * codebase, so no upload/URL/Dropbox/Google Drive source has ever been able
 * to build its cache. Upload is the one type that needs no network access at
 * all — the file is already on disk via its WP attachment — so it doesn't
 * need to wait on that missing class. URL/Dropbox/Google Drive/API sources
 * still do; this class does not attempt to cover them.
 *
 * Parses the full file using the same format-detection approach already used
 * for the "Add Data Source" upload preview (mmi_analyze_uploaded_file() in
 * DataSourceController.php), but reads every row, not just a 5-row sample,
 * and writes the result to disk instead of returning it for display.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Upload_Source_Fetcher {

    /**
     * True when $supplier_id is a configured, source_type === 'upload' data
     * source — the one condition under which fetch() below can succeed.
     */
    public static function is_upload_source( string $supplier_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_data_sources';
        $type  = $wpdb->get_var(
            $wpdb->prepare( "SELECT source_type FROM {$table} WHERE supplier_id = %s", $supplier_id )
        );
        return $type === 'upload';
    }

    /**
     * Build (or rebuild) the JSON cache for an upload-type data source.
     *
     * @return array The parsed records — same content written to the cache file.
     * @throws \RuntimeException on any condition that prevents a cache from being built.
     */
    public static function fetch( string $supplier_id ): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'mmi_data_sources';
        $source = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE supplier_id = %s", $supplier_id ),
            ARRAY_A
        );

        if ( ! $source ) {
            throw new \RuntimeException( "Data source \"{$supplier_id}\" not found." );
        }
        if ( ( $source['source_type'] ?? '' ) !== 'upload' ) {
            throw new \RuntimeException( "Data source \"{$supplier_id}\" is not an upload source." );
        }

        $configuration = json_decode( (string) ( $source['configuration'] ?? '{}' ), true ) ?: [];
        $file_config   = json_decode( (string) ( $source['file_config'] ?? '{}' ), true ) ?: [];

        $attachment_id = (int) ( $configuration['upload_attachment_id'] ?? 0 );
        if ( $attachment_id <= 0 ) {
            throw new \RuntimeException( "Data source \"{$supplier_id}\" has no uploaded file configured." );
        }

        $file_path = get_attached_file( $attachment_id );
        if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
            throw new \RuntimeException( "The uploaded file for \"{$supplier_id}\" is no longer accessible on disk. Please re-upload it." );
        }

        $records = self::parse_file_with_config( $file_path, $file_config );

        self::write_cache( $supplier_id, $records );

        // Review & Compare's "Create Only" source-data filter schema (see
        // MMI_Pipeline_Config_Validator::calculate_filterable_fields()) is
        // deliberately NOT recalculated here — it's triggered from the
        // "Verify" button (mmi_test_data_source in DataSourceController.php)
        // for every source type, a single deliberate setup-time moment
        // rather than every incidental cache rebuild (this method also runs
        // on every scheduled re-fetch, which would otherwise re-run a full
        // feed scan far more often than the schema actually needs it).
        // get_filterable_fields()'s own mtime-fingerprint check already
        // self-heals against a genuinely changed feed the next time Review &
        // Compare reads it, so nothing here needs to force that early.

        return $records;
    }

    /**
     * Parse a file already on disk per a source's file_config — the shared
     * entry point reused by Data_Source_Manager (includes/acquisition/
     * class-data-source-manager.php) for url/dropbox/gdrive sources, once
     * their downloaded content has been staged to a temp file. Single owner
     * for "how does a source's raw file become a records array," regardless
     * of whether the bytes came from an upload or a network fetch.
     */
    public static function parse_file_with_config( string $file_path, array $file_config ): array {
        $format         = sanitize_text_field( $file_config['format'] ?? 'json' );
        $delimiter      = ( $file_config['delimiter'] ?? ',' ) ?: ',';
        $has_header     = array_key_exists( 'has_header', $file_config ) ? (bool) $file_config['has_header'] : true;
        $data_root_path = trim( (string) ( $file_config['data_root_path'] ?? '' ) );

        $records = self::parse_file( $file_path, $format, $delimiter, $has_header );
        return self::apply_data_root_path( $records, $data_root_path );
    }

    private static function parse_file( string $file_path, string $format, string $delimiter, bool $has_header ): array {
        switch ( $format ) {
            case 'csv':
            case 'tsv':
                return self::parse_csv( $file_path, ( $format === 'tsv' ) ? "\t" : $delimiter, $has_header );

            case 'xml':
                return self::parse_xml( $file_path );

            case 'json':
            default:
                return self::parse_json( $file_path );
        }
    }

    private static function parse_json( string $file_path ): array {
        $content = file_get_contents( $file_path );
        if ( $content === false ) {
            throw new \RuntimeException( 'Could not read file.' );
        }
        $data = json_decode( $content, true );
        if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
            throw new \RuntimeException( 'Could not parse JSON: ' . json_last_error_msg() );
        }
        // A single top-level object (not a list) is one record — wrapped so
        // apply_data_root_path() below has a consistent $records[0] to walk
        // into for a data_root_path pointing at a nested product array.
        return ( is_array( $data ) && array_is_list( $data ) ) ? $data : [ $data ];
    }

    private static function parse_csv( string $file_path, string $delimiter, bool $has_header ): array {
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            throw new \RuntimeException( 'Could not open file.' );
        }

        $records = [];
        $headers = $has_header ? fgetcsv( $handle, 0, $delimiter ) : null;
        if ( is_array( $headers ) ) {
            $headers = array_map( 'trim', $headers );
        }

        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            if ( is_array( $headers ) ) {
                $padded    = array_pad( array_slice( $row, 0, count( $headers ) ), count( $headers ), '' );
                $records[] = array_combine( $headers, $padded );
            } else {
                $records[] = array_combine(
                    array_map( static fn( $i ) => "col{$i}", array_keys( $row ) ),
                    $row
                );
            }
        }
        fclose( $handle );

        return $records;
    }

    private static function parse_xml( string $file_path ): array {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_file( $file_path, 'SimpleXMLElement', LIBXML_NONET );
        if ( $xml === false ) {
            throw new \RuntimeException( 'Could not parse XML.' );
        }

        $records = [];
        foreach ( $xml->children() as $child ) {
            $row = [];
            foreach ( $child->children() as $key => $val ) {
                $row[ $key ] = (string) $val;
            }
            $records[] = $row;
        }

        return $records;
    }

    /**
     * Walk a dot-notation path into a parsed wrapper object before treating
     * the result as the record list — mirrors the same convention this
     * plugin already reads from Xchange's own {"products": [...]} wrapper.
     * Only meaningful for JSON's single-object case (parse_json() wraps a
     * non-list result as [$data]); a no-op for CSV/XML, which are already
     * flat row lists.
     */
    private static function apply_data_root_path( array $records, string $data_root_path ): array {
        if ( $data_root_path === '' ) {
            return $records;
        }

        $node = $records[0] ?? null;
        foreach ( explode( '.', $data_root_path ) as $segment ) {
            if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
                throw new \RuntimeException( "data_root_path \"{$data_root_path}\" not found in file." );
            }
            $node = $node[ $segment ];
        }
        if ( ! is_array( $node ) ) {
            throw new \RuntimeException( "data_root_path \"{$data_root_path}\" does not point to a list." );
        }

        return array_is_list( $node ) ? $node : [ $node ];
    }

    /**
     * Write atomically — temp file + rename — per AGENTS.md Rule 7, so a
     * mid-write failure never leaves a truncated cache where a good one used
     * to be.
     */
    public static function write_cache( string $supplier_id, array $records ): void {
        $json_dir = mmi_shared_lib_json_dir();
        $path     = $json_dir . $supplier_id . '-products.json';
        $tmp_path = $path . '.tmp-' . wp_generate_password( 8, false, false );

        $written = file_put_contents( $tmp_path, wp_json_encode( $records ) );
        if ( $written === false ) {
            throw new \RuntimeException( 'Could not write cache file.' );
        }
        if ( ! rename( $tmp_path, $path ) ) {
            @unlink( $tmp_path );
            throw new \RuntimeException( 'Could not finalize cache file.' );
        }
    }
}
