<?php
/**
 * CSV Mapping AJAX Controller
 *
 * Handles all AJAX actions for the CSV column-mapping panel:
 *   mmi_load_csv_preview      — fetch and render a preview of the CSV file
 *   mmi_save_csv_config       — persist delimiter / has_header settings
 *   mmi_save_csv_mapping      — persist field → column mappings
 *   mmi_test_csv_mapping      — test mappings against the first data row
 *   mmi_auto_detect_csv_columns — suggest field → column mappings from headers
 *
 * Nonce: mmi_pipeline_import_settings  (matches the nonce in mmiImportSettings.nonce)
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Return the data-source row needed for CSV operations, or null on failure.
 *
 * @param  string $supplier_id
 * @return array{url:string,delimiter:string,has_header:bool}|null
 */
function mmi_pipeline_csv_source_info( string $supplier_id ): ?array {
    global $wpdb;

    $ds_table = $wpdb->prefix . 'mmi_data_sources';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT configuration, file_config FROM {$ds_table} WHERE supplier_id = %s",
            $supplier_id
        ),
        ARRAY_A
    );

    if ( ! $row ) {
        return null;
    }

    $conn = json_decode( $row['configuration'] ?: '{}', true ) ?: [];
    $file = json_decode( $row['file_config']    ?: '{}', true ) ?: [];

    return [
        'url'        => $conn['base_url'] ?? '',
        'delimiter'  => $file['delimiter']  ?? ',',
        'has_header' => (bool) ( $file['has_header'] ?? true ),
    ];
}

/**
 * Fetch raw CSV content from a URL or absolute filesystem path.
 *
 * @param  string $url        HTTP(S) URL or absolute local path.
 * @param  int    $max_bytes  Maximum bytes to retrieve (default 512 KB).
 * @return string|WP_Error
 */
function mmi_pipeline_fetch_csv( string $url, int $max_bytes = 524288 ) {
    if ( $url === '' ) {
        return new WP_Error( 'no_url', 'No URL configured for this data source.' );
    }

    // Local filesystem path — confined to the uploads tree (which holds the
    // suite's private feed directory), so a source URL can't be pointed at
    // wp-config.php or any other file on the server.
    if ( strpos( $url, '/' ) === 0 || strpos( $url, 'C:\\' ) === 0 ) {
        $real_path    = realpath( $url );
        $uploads_root = realpath( wp_upload_dir()['basedir'] );
        if ( ! $real_path || ! $uploads_root || strpos( $real_path, $uploads_root . DIRECTORY_SEPARATOR ) !== 0 || ! is_file( $real_path ) ) {
            return new WP_Error( 'not_allowed', 'Local CSV files must be inside the uploads directory.' );
        }
        if ( ! is_readable( $real_path ) ) {
            return new WP_Error( 'not_readable', 'CSV file is not readable.' );
        }
        $content = file_get_contents( $real_path, false, null, 0, $max_bytes );
        if ( $content === false ) {
            return new WP_Error( 'read_error', 'Failed to read CSV file.' );
        }
        return $content;
    }

    // Remote URL — wp_safe_remote_get() refuses non-http(s) schemes and
    // private/loopback hosts (SSRF guard).
    $response = wp_safe_remote_get( $url, [
        'timeout'   => 30,
        'sslverify' => true,
        'headers'   => [ 'Accept' => 'text/csv, text/plain, */*' ],
        'limit_response_size' => $max_bytes,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code < 200 || $code >= 300 ) {
        return new WP_Error( 'http_error', "HTTP {$code} returned from CSV URL." );
    }

    return wp_remote_retrieve_body( $response );
}

/**
 * Parse CSV content into rows.
 *
 * @param  string $content    Raw CSV text.
 * @param  string $delimiter  Field delimiter character.
 * @param  int    $max_rows   Maximum number of rows to return.
 * @return list<list<string>>
 */
function mmi_pipeline_parse_csv( string $content, string $delimiter = ',', int $max_rows = 50 ): array {
    // Normalise line endings, strip BOM.
    $content = preg_replace( '/\x{FEFF}/u', '', $content );
    $content = str_replace( "\r\n", "\n", $content );
    $content = str_replace( "\r",   "\n", $content );

    $rows   = [];
    $lines  = explode( "\n", trim( $content ) );
    $count  = 0;

    // Handle tab literal "\t" stored as a two-char string
    if ( $delimiter === '\t' ) {
        $delimiter = "\t";
    }

    foreach ( $lines as $line ) {
        if ( $line === '' ) {
            continue;
        }
        $rows[] = str_getcsv( $line, $delimiter );
        if ( ++$count >= $max_rows ) {
            break;
        }
    }

    return $rows;
}

/**
 * Field keywords used for auto-detection (field_key => candidate header substrings).
 *
 * @return array<string, list<string>>
 */
function mmi_pipeline_csv_field_keywords(): array {
    return [
        'name'              => [ 'product name', 'product_name', 'title', 'name' ],
        'sku'               => [ 'sku', 'item_number', 'item number', 'part_number', 'part number', 'mpn', 'model' ],
        'description'       => [ 'long_description', 'long description', 'description', 'body' ],
        'short_description' => [ 'short_description', 'short description', 'short_desc', 'excerpt', 'summary' ],
        'regular_price'     => [ 'regular_price', 'regular price', 'retail_price', 'retail price', 'list_price', 'list price', 'price' ],
        'sale_price'        => [ 'sale_price', 'sale price', 'sale', 'promo_price', 'promo price' ],
        '_stock_quantity'   => [ 'stock_quantity', 'stock quantity', 'quantity', 'qty', 'available', 'stock' ],
        '_stock_status'     => [ 'stock_status', 'stock status', 'availability', 'in_stock' ],
        'images'            => [ 'image_url', 'image url', 'images', 'image', 'photo', 'thumbnail_url', 'picture' ],
        'categories'        => [ 'categories', 'category', 'product_category', 'product category' ],
        'tags'              => [ 'tags', 'tag', 'product_tags', 'labels', 'keywords' ],
    ];
}

// ---------------------------------------------------------------------------
// Action: mmi_load_csv_preview
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_mmi_load_csv_preview', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $supplier = sanitize_text_field( $_POST['supplier'] ?? '' );
    if ( $supplier === '' ) {
        wp_send_json_error( [ 'message' => 'Supplier ID required.' ] );
    }

    $info = mmi_pipeline_csv_source_info( $supplier );
    if ( ! $info ) {
        wp_send_json_error( [ 'message' => 'Data source not found.' ] );
    }

    $content = mmi_pipeline_fetch_csv( $info['url'] );
    if ( is_wp_error( $content ) ) {
        wp_send_json_error( [ 'message' => $content->get_error_message() ] );
    }

    // Parse up to 6 rows (1 header + 5 data)
    $rows = mmi_pipeline_parse_csv( $content, $info['delimiter'], 6 );
    if ( empty( $rows ) ) {
        wp_send_json_error( [ 'message' => 'CSV file is empty or could not be parsed.' ] );
    }

    ob_start();
    echo '<table class="widefat mmi-csv-preview-inner mmi-uniform-table">';

    $header_row = $info['has_header'] ? array_shift( $rows ) : null;

    if ( $header_row !== null ) {
        echo '<thead><tr>';
        foreach ( $header_row as $cell ) {
            echo '<th>' . esc_html( $cell ) . '</th>';
        }
        echo '</tr></thead>';
    }

    echo '<tbody>';
    foreach ( $rows as $row ) {
        echo '<tr>';
        foreach ( $row as $cell ) {
            echo '<td>' . esc_html( mb_strimwidth( $cell, 0, 60, '…' ) ) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';

    $html = ob_get_clean();
    wp_send_json_success( [ 'html' => $html ] );
} );

// ---------------------------------------------------------------------------
// Action: mmi_save_csv_config
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_mmi_save_csv_config', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $supplier   = sanitize_text_field( $_POST['supplier']   ?? '' );
    $delimiter  = sanitize_text_field( $_POST['delimiter']  ?? ',' );
    $has_header = ( $_POST['has_header'] ?? '1' ) !== '0';

    if ( $supplier === '' ) {
        wp_send_json_error( [ 'message' => 'Supplier ID required.' ] );
    }

    $allowed_delimiters = [ ',', ';', "\t", '|', '\t' ];
    if ( ! in_array( $delimiter, $allowed_delimiters, true ) ) {
        $delimiter = ',';
    }

    global $wpdb;
    $ds_table = $wpdb->prefix . 'mmi_data_sources';

    $row = $wpdb->get_row(
        $wpdb->prepare( "SELECT file_config FROM {$ds_table} WHERE supplier_id = %s", $supplier ),
        ARRAY_A
    );

    if ( ! $row ) {
        wp_send_json_error( [ 'message' => 'Data source not found.' ] );
    }

    $file_cfg               = json_decode( $row['file_config'] ?: '{}', true ) ?: [];
    $file_cfg['delimiter']  = $delimiter;
    $file_cfg['has_header'] = $has_header;

    $result = $wpdb->update(
        $ds_table,
        [ 'file_config' => wp_json_encode( $file_cfg ) ],
        [ 'supplier_id' => $supplier ],
        [ '%s' ],
        [ '%s' ]
    );

    if ( $result === false ) {
        wp_send_json_error( [ 'message' => 'Failed to save CSV config.' ] );
    }

    wp_send_json_success();
} );

// ---------------------------------------------------------------------------
// Action: mmi_save_csv_mapping
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_mmi_save_csv_mapping', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $supplier      = sanitize_text_field( $_POST['supplier'] ?? '' );
    $mappings_json = wp_unslash( $_POST['mappings'] ?? '' );

    if ( $supplier === '' ) {
        wp_send_json_error( [ 'message' => 'Supplier ID required.' ] );
    }

    $mappings = json_decode( $mappings_json, true );
    if ( ! is_array( $mappings ) ) {
        wp_send_json_error( [ 'message' => 'Invalid mappings format.' ] );
    }

    // Sanitise each mapping entry
    $clean = [];
    $allowed_transforms = [ 'none', 'uppercase', 'lowercase', 'title_case', 'to_decimal', 'to_int', 'strip_html', 'sanitize_url', 'comma_to_array' ];
    foreach ( $mappings as $field => $map ) {
        $field  = sanitize_text_field( $field );
        $column = sanitize_text_field( $map['column'] ?? '' );
        $transform = sanitize_text_field( $map['transform'] ?? 'none' );
        if ( ! in_array( $transform, $allowed_transforms, true ) ) {
            $transform = 'none';
        }
        if ( $field !== '' && $column !== '' ) {
            $clean[ $field ] = [ 'column' => $column, 'transform' => $transform ];
        }
    }

    $ok = MMI_DB::set_csv_mappings( $supplier, $clean );
    if ( ! $ok ) {
        wp_send_json_error( [ 'message' => 'Failed to save mappings.' ] );
    }

    wp_send_json_success();
} );

// ---------------------------------------------------------------------------
// Action: mmi_test_csv_mapping
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_mmi_test_csv_mapping', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $supplier = sanitize_text_field( $_POST['supplier'] ?? '' );
    if ( $supplier === '' ) {
        wp_send_json_error( [ 'message' => 'Supplier ID required.' ] );
    }

    $info = mmi_pipeline_csv_source_info( $supplier );
    if ( ! $info ) {
        wp_send_json_error( [ 'message' => 'Data source not found.' ] );
    }

    $mappings = MMI_DB::get_csv_mappings( $supplier );
    if ( empty( $mappings ) ) {
        wp_send_json_error( [ 'message' => 'No column mappings configured yet.' ] );
    }

    $content = mmi_pipeline_fetch_csv( $info['url'] );
    if ( is_wp_error( $content ) ) {
        wp_send_json_error( [ 'message' => $content->get_error_message() ] );
    }

    $rows = mmi_pipeline_parse_csv( $content, $info['delimiter'], 3 );
    if ( empty( $rows ) ) {
        wp_send_json_error( [ 'message' => 'CSV file is empty.' ] );
    }

    // Build header-index map
    $header_map = [];
    if ( $info['has_header'] ) {
        $headers = array_shift( $rows );
        foreach ( $headers as $i => $h ) {
            $header_map[ trim( $h ) ] = $i;
        }
    } else {
        // Use numeric indices as keys
        $first = reset( $rows );
        foreach ( array_keys( $first ) as $i ) {
            $header_map[ (string) $i ] = $i;
        }
    }

    if ( empty( $rows ) ) {
        wp_send_json_error( [ 'message' => 'CSV has no data rows (only a header).' ] );
    }

    $data_row = reset( $rows );

    // Apply transforms
    $apply_transform = static function ( string $value, string $transform ): string {
        switch ( $transform ) {
            case 'uppercase':   return strtoupper( $value );
            case 'lowercase':   return strtolower( $value );
            case 'title_case':  return ucwords( strtolower( $value ) );
            case 'to_decimal':  return (string) floatval( $value );
            case 'to_int':      return (string) intval( $value );
            case 'strip_html':  return wp_strip_all_tags( $value );
            case 'sanitize_url': return esc_url_raw( $value );
            default:            return $value;
        }
    };

    $sample = [];
    foreach ( $mappings as $field => $map ) {
        $col = $map['column'] ?? '';
        $idx = $header_map[ $col ] ?? null;
        if ( $idx !== null && isset( $data_row[ $idx ] ) ) {
            $sample[ $field ] = $apply_transform( $data_row[ $idx ], $map['transform'] ?? 'none' );
        } else {
            $sample[ $field ] = '';
        }
    }

    wp_send_json_success( [ 'sample' => $sample ] );
} );

// ---------------------------------------------------------------------------
// Action: mmi_auto_detect_csv_columns
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_mmi_auto_detect_csv_columns', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized' ] );
    }

    $supplier = sanitize_text_field( $_POST['supplier'] ?? '' );
    if ( $supplier === '' ) {
        wp_send_json_error( [ 'message' => 'Supplier ID required.' ] );
    }

    $info = mmi_pipeline_csv_source_info( $supplier );
    if ( ! $info ) {
        wp_send_json_error( [ 'message' => 'Data source not found.' ] );
    }

    if ( ! $info['has_header'] ) {
        wp_send_json_error( [ 'message' => 'Auto-detection requires a CSV with a header row. Enable "CSV has header row" first.' ] );
    }

    $content = mmi_pipeline_fetch_csv( $info['url'] );
    if ( is_wp_error( $content ) ) {
        wp_send_json_error( [ 'message' => $content->get_error_message() ] );
    }

    $rows = mmi_pipeline_parse_csv( $content, $info['delimiter'], 1 );
    if ( empty( $rows ) ) {
        wp_send_json_error( [ 'message' => 'CSV file is empty or has no header row.' ] );
    }

    $headers   = array_map( 'trim', reset( $rows ) );
    $keywords  = mmi_pipeline_csv_field_keywords();
    $suggested = [];

    foreach ( $keywords as $field => $candidates ) {
        // Exact match first (case-insensitive)
        foreach ( $headers as $header ) {
            $header_lc = strtolower( $header );
            foreach ( $candidates as $candidate ) {
                if ( $header_lc === strtolower( $candidate ) ) {
                    $suggested[ $field ] = $header;
                    break 2;
                }
            }
        }
        if ( isset( $suggested[ $field ] ) ) {
            continue;
        }
        // Substring match (candidate contained in header)
        foreach ( $headers as $header ) {
            $header_lc = strtolower( $header );
            foreach ( $candidates as $candidate ) {
                if ( strpos( $header_lc, strtolower( $candidate ) ) !== false ) {
                    $suggested[ $field ] = $header;
                    break 2;
                }
            }
        }
    }

    if ( empty( $suggested ) ) {
        wp_send_json_error( [ 'message' => 'Could not detect any columns. Check that the CSV URL is correct and the file has headers.' ] );
    }

    wp_send_json_success( [ 'mappings' => $suggested ] );
} );
