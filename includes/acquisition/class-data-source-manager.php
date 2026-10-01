<?php
/**
 * Data_Source_Manager — fetch mechanism for url/dropbox/gdrive data sources.
 *
 * Rebuilt 2026-09-18. The original lived only in mmi-hub (deleted 2026-09-17)
 * and was never vendored anywhere before that — every call site across this
 * plugin (RunSupplierFetchController, SourceFetchController,
 * ImportSettingsController, class-pipeline-cron.php) referenced it by fully
 * qualified name and silently no-op'd via class_exists() when it was missing,
 * so no url/dropbox/gdrive source has ever actually been fetchable. This
 * rebuild is inferred from those call sites' usage (fetch_from_supplier()
 * returning the parsed records array) and from mmi_test_data_source() in
 * DataSourceController.php, which already implements real connectivity
 * checks for every source type this class covers — no original source
 * survived to copy from.
 *
 * Covers exactly the 3 source types that have no dedicated fetcher already:
 * - 'url' / 'api' (non-legacy): generic HTTP endpoint + configurable auth
 * - 'dropbox': Dropbox Content API file download
 * - 'gdrive': Google Drive public-link download
 *
 * Legacy suppliers (xchange/skuport/plugivery) use their own dedicated
 * updater classes and never reach this class. 'upload' sources use
 * MMI_Pipeline_Upload_Source_Fetcher, which also needed no network access —
 * this class reuses its parsing/cache-write logic (see parse_file_with_config()
 * and write_cache()) rather than duplicating it.
 *
 * @package MannMade\DataPipeline
 */

namespace MannMade\Integrations\Acquisition;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Data_Source_Manager {

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Fetch a data source's feed, parse it per its file_config, write the
     * result to the canonical {supplier_id}-products.json cache, and return
     * the parsed records. Throws on any failure — callers already catch
     * \Throwable and surface the message (see RunSupplierFetchController,
     * class-pipeline-cron.php's run_scheduled_source_fetch()).
     *
     * @return array The parsed records — same content written to the cache file.
     * @throws \RuntimeException on any condition that prevents a fetch from succeeding.
     */
    public function fetch_from_supplier( string $supplier_id ): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'mmi_data_sources';
        $source = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE supplier_id = %s", $supplier_id ),
            ARRAY_A
        );

        if ( ! $source ) {
            throw new \RuntimeException( "Data source \"{$supplier_id}\" not found." );
        }

        $source_type = $source['source_type'] ?? 'api';
        if ( ! in_array( $source_type, [ 'api', 'url', 'dropbox', 'gdrive' ], true ) ) {
            throw new \RuntimeException( "Data_Source_Manager does not handle source type \"{$source_type}\" for \"{$supplier_id}\" — it covers url/api/dropbox/gdrive only." );
        }

        $start_time = microtime( true );

        try {
            // Every outbound call from this class routes through the throttler
            // (per AGENTS.md's API Rate Limiting rule) — a generic per-supplier
            // key since these are arbitrary, admin-configured 3rd-party feeds
            // that can't be pre-registered in MMI_API_Throttler::KNOWN_APIS;
            // resolve_profile() already falls back to a safe 1s-min-gap default
            // for any unknown key.
            \MMI_API_Throttler::throttle( 'data-source-' . $supplier_id );

            $body = $this->download_body( $supplier_id, $source, $source_type );

            $file_config = json_decode( (string) ( $source['file_config'] ?? '{}' ), true ) ?: [];
            $records     = $this->parse_response_body( $body, $file_config );

            \MMI_Pipeline_Upload_Source_Fetcher::write_cache( $supplier_id, $records );

            $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
            $this->record_result( $supplier_id, true, count( $records ), $duration_ms, '' );

            \MMI_Logger::info(
                "Fetched {$supplier_id} ({$source_type}) — " . count( $records ) . ' records',
                [ 'supplier_id' => $supplier_id, 'source_type' => $source_type, 'duration_ms' => $duration_ms ],
                'integrations',
                'Data_Source_Manager'
            );

            return $records;
        } catch ( \Throwable $e ) {
            $duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );
            $this->record_result( $supplier_id, false, 0, $duration_ms, $e->getMessage() );

            \MMI_Logger::error(
                "Fetch failed for {$supplier_id} ({$source_type}): " . $e->getMessage(),
                [ 'supplier_id' => $supplier_id, 'source_type' => $source_type ],
                'integrations',
                'Data_Source_Manager'
            );

            throw $e;
        }
    }

    /* ── Download ─────────────────────────────────────────────────────────── */

    private function download_body( string $supplier_id, array $source, string $source_type ): string {
        switch ( $source_type ) {
            case 'dropbox':
                return $this->download_dropbox( $supplier_id, $source );
            case 'gdrive':
                return $this->download_gdrive( $source );
            default: // 'url' / 'api'
                return $this->download_http( $supplier_id, $source );
        }
    }

    /**
     * Generic HTTP fetch for 'url'/'api' source types — resolves the same
     * primary-endpoint-then-base_url chain, and builds the same auth headers
     * via mmi_ds_build_auth_headers(), that mmi_test_data_source() in
     * DataSourceController.php already uses for its connectivity check. Reused
     * rather than reimplemented so a source's real fetch and its "Test
     * Connection" check can never silently diverge on auth handling.
     */
    private function download_http( string $supplier_id, array $source ): string {
        $configuration = json_decode( (string) ( $source['configuration'] ?? '{}' ), true ) ?: [];
        $auth_config   = json_decode( (string) ( $source['auth_config'] ?? '{}' ), true ) ?: [];

        $url = $this->resolve_endpoint_url( $source, $configuration );
        if ( $url === '' ) {
            throw new \RuntimeException( "No endpoint URL configured for \"{$supplier_id}\"." );
        }

        $headers = \MannMade\DataPipeline\Controllers\AJAX\mmi_ds_build_auth_headers( $supplier_id, $auth_config );
        if ( ! empty( $configuration['custom_headers'] ) && is_array( $configuration['custom_headers'] ) ) {
            foreach ( $configuration['custom_headers'] as $key => $val ) {
                if ( $key !== '' && $val !== '' ) {
                    $headers[ $key ] = $val;
                }
            }
        }

        $timeout  = max( 5, min( 300, (int) ( $configuration['timeout'] ?? 60 ) ) );
        $response = wp_safe_remote_get( $url, [
            'timeout'   => $timeout,
            'headers'   => $headers,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Request failed: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            if ( $code === 429 ) {
                \MMI_API_Throttler::penalize( 'data-source-' . $supplier_id, (int) wp_remote_retrieve_header( $response, 'retry-after' ) );
            }
            throw new \RuntimeException( "HTTP error {$code}: " . wp_remote_retrieve_response_message( $response ) );
        }

        return (string) wp_remote_retrieve_body( $response );
    }

    private function resolve_endpoint_url( array $source, array $configuration ): string {
        global $wpdb;
        $endpoints_table = $wpdb->prefix . 'mmi_data_source_endpoints';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$endpoints_table}'" ) === $endpoints_table ) {
            $ep = $wpdb->get_row(
                $wpdb->prepare( "SELECT endpoint_url FROM {$endpoints_table} WHERE source_id = %d AND is_primary = 1 LIMIT 1", $source['id'] ),
                ARRAY_A
            );
            if ( ! $ep ) {
                $ep = $wpdb->get_row(
                    $wpdb->prepare( "SELECT endpoint_url FROM {$endpoints_table} WHERE source_id = %d AND enabled = 1 ORDER BY id ASC LIMIT 1", $source['id'] ),
                    ARRAY_A
                );
            }
            if ( $ep && ! empty( $ep['endpoint_url'] ) ) {
                return $ep['endpoint_url'];
            }
        }

        return $configuration['base_url'] ?? '';
    }

    /**
     * Dropbox Content API file download — mmi_test_data_source() only ever
     * calls the Metadata API (confirms the file exists), never the actual
     * download endpoint, so this is the first real content fetch for a
     * Dropbox source.
     */
    private function download_dropbox( string $supplier_id, array $source ): string {
        $configuration = json_decode( (string) ( $source['configuration'] ?? '{}' ), true ) ?: [];
        $path          = $configuration['dropbox_file_path'] ?? '';
        if ( $path === '' ) {
            throw new \RuntimeException( "Dropbox file path is not configured for \"{$supplier_id}\"." );
        }

        $token = $this->resolve_vault_credential( $supplier_id . '-dropbox_access_token' );
        if ( $token === '' ) {
            throw new \RuntimeException( "Dropbox access token not found in credential vault for \"{$supplier_id}\"." );
        }

        $response = wp_remote_post( 'https://content.dropboxapi.com/2/files/download', [
            'timeout' => 60,
            'headers' => [
                'Authorization'   => 'Bearer ' . $token,
                'Dropbox-API-Arg' => wp_json_encode( [ 'path' => $path ] ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Dropbox download failed: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            $body    = wp_remote_retrieve_body( $response );
            $decoded = json_decode( $body, true );
            $msg     = $decoded['error_summary'] ?? $body;
            throw new \RuntimeException( "Dropbox download error ({$code}): " . wp_trim_words( (string) $msg, 20 ) );
        }

        return (string) wp_remote_retrieve_body( $response );
    }

    /**
     * Google Drive public-link download. Handles the large-file (>25MB)
     * virus-scan interstitial the same way mmi_test_data_source()'s comment
     * already documents but never implemented: an HTML confirmation page
     * carrying a one-time `confirm=` token is re-requested with that token
     * to get the real file content.
     */
    private function download_gdrive( array $source ): string {
        $configuration = json_decode( (string) ( $source['configuration'] ?? '{}' ), true ) ?: [];
        $file_id       = $configuration['gdrive_file_id'] ?? '';
        if ( $file_id === '' ) {
            throw new \RuntimeException( 'Google Drive File ID is not configured.' );
        }

        $url      = 'https://drive.google.com/uc?export=download&id=' . rawurlencode( $file_id );
        $response = wp_remote_get( $url, [ 'timeout' => 60, 'redirection' => 5 ] );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'Google Drive request failed: ' . $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            throw new \RuntimeException( "Google Drive returned HTTP {$code}." );
        }

        $body         = (string) wp_remote_retrieve_body( $response );
        $content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );

        if ( str_contains( strtolower( $content_type ), 'text/html' ) && preg_match( '/confirm=([0-9A-Za-z_-]+)/', $body, $m ) ) {
            $confirm_url      = 'https://drive.google.com/uc?export=download&confirm=' . $m[1] . '&id=' . rawurlencode( $file_id );
            $confirm_response = wp_remote_get( $confirm_url, [ 'timeout' => 60, 'redirection' => 5 ] );

            if ( is_wp_error( $confirm_response ) ) {
                throw new \RuntimeException( 'Google Drive confirmation request failed: ' . $confirm_response->get_error_message() );
            }
            if ( (int) wp_remote_retrieve_response_code( $confirm_response ) !== 200 ) {
                throw new \RuntimeException( 'Google Drive confirmation request returned HTTP ' . wp_remote_retrieve_response_code( $confirm_response ) . '.' );
            }

            return (string) wp_remote_retrieve_body( $confirm_response );
        }

        return $body;
    }

    private function resolve_vault_credential( string $field_name ): string {
        global $wpdb;
        $mmi_table = class_exists( 'MMI_Settings' ) ? \MMI_Settings::ensure_table() : $wpdb->prefix . 'mmi';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$mmi_table}'" ) !== $mmi_table ) {
            return '';
        }
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT field_value FROM {$mmi_table} WHERE tab_name = 'Credentials & API Keys' AND field_name = %s",
            $field_name
        ) );
    }

    /* ── Parsing & persistence ────────────────────────────────────────────── */

    /**
     * Writes the downloaded body to a temp file and reuses
     * MMI_Pipeline_Upload_Source_Fetcher's own format-detection/parsing logic
     * unchanged — a URL/Dropbox/Google Drive feed and an uploaded file are
     * parsed identically once the bytes are on disk, so this deliberately
     * does not duplicate that logic (per AGENTS.md's Elegance rule against
     * two files independently deciding the same thing).
     */
    private function parse_response_body( string $body, array $file_config ): array {
        $tmp_path = wp_tempnam( 'mmi-ds-fetch' );
        if ( $tmp_path === false || file_put_contents( $tmp_path, $body ) === false ) {
            throw new \RuntimeException( 'Could not stage downloaded content for parsing.' );
        }

        try {
            return \MMI_Pipeline_Upload_Source_Fetcher::parse_file_with_config( $tmp_path, $file_config );
        } finally {
            @unlink( $tmp_path );
        }
    }

    private function record_result( string $supplier_id, bool $success, int $count, int $duration_ms, string $error ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'mmi_data_sources';

        $current               = $wpdb->get_row( $wpdb->prepare( "SELECT fetch_count, consecutive_failures FROM {$table} WHERE supplier_id = %s", $supplier_id ), ARRAY_A );
        $fetch_count           = (int) ( $current['fetch_count'] ?? 0 ) + 1;
        $consecutive_failures  = $success ? 0 : ( (int) ( $current['consecutive_failures'] ?? 0 ) + 1 );

        $update = [
            'last_fetch_at'          => current_time( 'mysql' ),
            'last_fetch_status'      => $success ? 'success' : 'failed',
            'last_fetch_duration'    => (int) round( $duration_ms / 1000 ),
            'last_fetch_duration_ms' => $duration_ms,
            'last_fetch_error'       => $success ? '' : $error,
            'fetch_count'            => $fetch_count,
            'consecutive_failures'   => $consecutive_failures,
            'updated_at'             => current_time( 'mysql' ),
        ];
        if ( $success ) {
            $update['last_fetch_count'] = $count;
        }

        $wpdb->update( $table, $update, [ 'supplier_id' => $supplier_id ] );
    }
}
