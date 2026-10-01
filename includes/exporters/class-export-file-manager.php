<?php
/**
 * Export File Manager
 *
 * Storage/protection/download for generated export files. Exports can hold
 * customer/order data, so they live in the suite's private directory
 * (mmi_shared_lib_private_subdir(), an unguessable folder name — .htaccess
 * deny rules alone do nothing on nginx) and are served only through a
 * capability+nonce-gated admin-post.php endpoint that confines the path with
 * realpath() and writes an 'export.download' audit entry.
 *
 * @package MannMade\DataPipeline\Exporters
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Export_File_Manager {

    const RETENTION_SETTING_KEY = 'mmi_pipeline_export_retention_days';
    const DEFAULT_RETENTION_DAYS = 7;
    const CLEANUP_HOOK = 'mmi_pipeline_cleanup_exports';

    /**
     * The only extensions any export writer produces. Validated against
     * directly rather than run through sanitize_file_name() — that function
     * is built for full filenames, and its "no dot + string matches a known
     * MIME extension" branch rewrites a bare extension like "csv" to
     * "unnamed-file.csv", which then leaked into every export's actual
     * filename (both the file written to disk and the downloaded copy).
     */
    const ALLOWED_EXTENSIONS = [ 'csv', 'json', 'xml' ];

    /**
     * Returns the absolute path to the exports directory (no trailing
     * slash), creating it if absent. Private since the public-repo security
     * pass: the old public uploads/mmi-data-pipeline/exports/ folder is moved
     * in by mmi_shared_lib_private_subdir() on first call and removed.
     */
    public static function get_exports_dir(): string {
        $legacy = wp_upload_dir()['basedir'] . '/mmi-data-pipeline/exports';
        $dir    = function_exists( 'mmi_shared_lib_private_subdir' )
            ? untrailingslashit( mmi_shared_lib_private_subdir( 'data-pipeline-exports', $legacy ) )
            : '';
        if ( $dir === '' ) {
            $dir = $legacy;
        }

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\nOrder deny,allow\nDeny from all\n" );
        }

        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }

        return $dir;
    }

    /**
     * Deterministic path for a given export run.
     */
    public static function file_path( string $run_id, string $extension ): string {
        $safe_id  = sanitize_file_name( $run_id );
        $safe_ext = in_array( strtolower( $extension ), self::ALLOWED_EXTENSIONS, true ) ? strtolower( $extension ) : 'csv';
        return self::get_exports_dir() . '/' . $safe_id . '.' . $safe_ext;
    }

    /**
     * Human-readable run ID for a manual or scheduled export — doubles as
     * the downloaded file's actual name (see file_path()/handle_download()),
     * so it's built from the profile's display name rather than its
     * internal profile_id (which carries a random uniqueness suffix
     * meaningless to whoever opens the downloaded file, e.g.
     * "export_product_categories_7f3a9c3") and a compact date_time stamp
     * instead of a bare digit run (Ymd-His) that reads as noise.
     * Same-day re-runs of one profile intentionally overwrite the earlier
     * file — there is no concurrent-export case to disambiguate against,
     * since Batch_Export_State's lock already prevents two exports running
     * at once (see ExportController::run_manual_export()).
     */
    public static function build_run_id( string $profile_name ): string {
        $slug = sanitize_title( $profile_name );
        if ( $slug === '' ) {
            $slug = 'export';
        }
        return $slug . '_' . gmdate( 'Y-m-d_H-i' );
    }

    /**
     * Register the daily retention cleanup cron (idempotent — safe to call
     * on every request).
     */
    public static function init(): void {
        add_action( self::CLEANUP_HOOK, [ __CLASS__, 'cleanup_expired' ] );

        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    /**
     * Delete export files older than the configured retention window.
     * Exports are regenerable artifacts, not permanent business data, so
     * unbounded accumulation is a pure liability on a disk/RAM-constrained
     * server.
     */
    public static function cleanup_expired(): void {
        $retention_days = (int) MMI_DB::get_setting( self::RETENTION_SETTING_KEY, self::DEFAULT_RETENTION_DAYS );
        if ( $retention_days <= 0 ) {
            return;
        }

        $dir = self::get_exports_dir();
        $cutoff = time() - ( $retention_days * DAY_IN_SECONDS );

        $files = glob( $dir . '/*' ) ?: [];
        $deleted = 0;
        foreach ( $files as $file ) {
            if ( ! is_file( $file ) ) {
                continue;
            }
            $basename = basename( $file );
            if ( $basename === '.htaccess' || $basename === 'index.php' ) {
                continue;
            }
            if ( filemtime( $file ) < $cutoff ) {
                unlink( $file );
                $deleted++;
            }
        }

        if ( $deleted > 0 ) {
            MMI_Logger::info( "Cleaned up {$deleted} expired export file(s)", [ 'retention_days' => $retention_days ], 'data-pipeline', 'MMI_Export_File_Manager' );
        }
    }

    /**
     * admin-post.php handler — streams a finished export file for download.
     * Never accessed via direct uploads URL (blocked by .htaccess above).
     */
    public static function handle_download(): void {
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'mmi-data-pipeline' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'mmi_pipeline_download_export' );

        $run_id        = isset( $_GET['run_id'] ) ? sanitize_text_field( wp_unslash( $_GET['run_id'] ) ) : '';
        $raw_extension = isset( $_GET['ext'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['ext'] ) ) ) : 'csv';
        $extension     = in_array( $raw_extension, self::ALLOWED_EXTENSIONS, true ) ? $raw_extension : 'csv';

        if ( $run_id === '' ) {
            wp_die( esc_html__( 'Missing export run ID.', 'mmi-data-pipeline' ) );
        }

        $path     = realpath( self::file_path( $run_id, $extension ) );
        $real_dir = realpath( self::get_exports_dir() );
        if ( ! $path || ! $real_dir || strpos( $path, $real_dir . DIRECTORY_SEPARATOR ) !== 0 || ! is_file( $path ) ) {
            wp_die( esc_html__( 'Export file not found — it may have expired.', 'mmi-data-pipeline' ), '', [ 'response' => 404 ] );
        }

        $mime_map = [
            'csv'  => 'text/csv',
            'json' => 'application/json',
            'xml'  => 'application/xml',
        ];
        $mime = $mime_map[ $extension ] ?? 'application/octet-stream';

        // Send both the legacy filename="..." (ASCII fallback) and the RFC
        // 6266 filename*=UTF-8''... form — some browsers, Safari included,
        // are more reliable at using the intended name (rather than a
        // "Unnamed file" placeholder) when both are present.
        $download_name = sanitize_file_name( $run_id . '.' . $extension );

        mmi_data_pipeline_audit( 'export.download', [
            'object_type' => 'export_file',
            'object_id'   => $run_id,
            'outcome'     => 'success',
            'details'     => [ 'format' => $extension, 'bytes' => (int) filesize( $path ) ],
        ] );

        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: ' . $mime );
        header(
            'Content-Disposition: attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . rawurlencode( $download_name )
        );
        header( 'Content-Length: ' . filesize( $path ) );

        readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
        exit;
    }
}
