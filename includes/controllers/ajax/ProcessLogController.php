<?php
/**
 * Process Log & Recent Activities AJAX Controller
 *
 * Self-contained import log handler for mmi-data-pipeline.
 * Key fixes:
 *   - Uses $entry['created_at']   (was $entry['timestamp']   — column doesn't exist)
 *   - Uses $entry['activity_type'] (was $entry['title']        — column doesn't exist)
 *   - Builds rich log output from wp_mmi_pipeline_import_history (real stats)
 *     supplemented by wp_mmi_pipeline_activities
 *
 * Nonce: mmi_product_importer_nonce  (matches mmiProductImportData.nonce in JS)
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Process_Log_Controller {

    public static function init(): void {
        add_action( 'wp_ajax_mmi_get_process_log',       [ __CLASS__, 'handle_get_process_log' ] );
        add_action( 'wp_ajax_mmi_get_recent_activities', [ __CLASS__, 'handle_get_recent_activities' ] );
    }

    /* ── Process Log ──────────────────────────────────────────────────────── */

    /**
     * Return a text-formatted process log for the log viewer `<pre>` block.
     *
     * POST params:
     *   supplier  (string)  Supplier slug or 'all'
     *   nonce     (string)
     */
    public static function handle_get_process_log(): void {
        check_ajax_referer( 'mmi_product_importer_nonce', 'nonce' );

        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'mmi-data-pipeline' ) ] );
        }

        $supplier = sanitize_text_field( $_POST['supplier'] ?? 'all' );
        $sections = [];

        /* ── 1. Import history (richest data source) ── */
        $history = MMI_DB::get_import_history( 50 );

        if ( ! empty( $history ) ) {
            // Filter by supplier when requested
            if ( $supplier !== 'all' ) {
                $history = array_filter( $history, fn( $r ) =>
                    stripos( $r['supplier_id'] ?? '', $supplier ) !== false
                );
            }

            $history = array_values( $history );
            $lines   = [];

            foreach ( array_slice( $history, 0, 30 ) as $entry ) {
                $started   = $entry['started_at'] ?? null;
                $completed = $entry['completed_at'] ?? null;

                $ts_label = $started
                    ? wp_date( 'Y-m-d H:i:s', strtotime( $started ) )
                    : '—';

                // Duration
                $dur_label = '—';
                if ( $started && $completed ) {
                    $secs = strtotime( $completed ) - strtotime( $started );
                    $dur_label = $secs >= 60
                        ? sprintf( '%d min %d sec', intdiv( $secs, 60 ), $secs % 60 )
                        : $secs . ' sec';
                }

                // Status badge
                $status = strtoupper( $entry['status'] ?? 'UNKNOWN' );

                $line  = "[$ts_label] IMPORT — {$status}\n";
                $line .= sprintf(
                    "  Profile: %s | Supplier: %s\n",
                    $entry['profile_id'] ?? 'default',
                    $entry['supplier_id'] ?? 'unknown'
                );
                $line .= sprintf(
                    "  Imported: %s | Updated: %s | Skipped: %s | Errors: %s\n",
                    number_format( (int) ( $entry['imported'] ?? 0 ) ),
                    number_format( (int) ( $entry['updated']  ?? 0 ) ),
                    number_format( (int) ( $entry['skipped']  ?? 0 ) ),
                    number_format( (int) ( $entry['errors']   ?? 0 ) )
                );
                $line .= "  Duration: {$dur_label}";

                // Per-supplier breakdown from notes JSON
                if ( ! empty( $entry['notes'] ) ) {
                    $notes = json_decode( $entry['notes'], true );
                    if ( is_array( $notes ) ) {
                        if ( isset( $notes['duration_seconds'] ) ) {
                            $line .= " ({$notes['duration_seconds']}s)";
                        }
                        if ( ! empty( $notes['per_supplier'] ) && is_array( $notes['per_supplier'] ) ) {
                            $line .= "\n\n  Per-supplier breakdown:";
                            foreach ( $notes['per_supplier'] as $sid => $stats ) {
                                $line .= sprintf(
                                    "\n    %-12s imported:%-6s updated:%-6s skipped:%-6s failed:%s",
                                    $sid,
                                    number_format( $stats['imported'] ?? 0 ),
                                    number_format( $stats['updated']  ?? 0 ),
                                    number_format( $stats['skipped']  ?? 0 ),
                                    number_format( $stats['failed']   ?? 0 )
                                );
                            }
                        }
                    }
                }

                $lines[] = $line;
            }

            if ( ! empty( $lines ) ) {
                $sections[] = "=== IMPORT HISTORY (" . count( $lines ) . " run" . ( count( $lines ) > 1 ? 's' : '' ) . ") ===\n\n"
                            . implode( "\n\n", $lines );
            }
        }

        /* ── 2. Activity log (supplemental — fetch events, errors, etc.) ── */
        $activities = MMI_DB::get_recent_activities( 30 );

        if ( ! empty( $activities ) ) {
            if ( $supplier !== 'all' ) {
                $activities = array_filter( $activities, fn( $a ) =>
                    stripos( ( $a['activity_type'] ?? '' ) . ( $a['message'] ?? '' ), $supplier ) !== false
                );
            }

            $activities = array_values( $activities );
            $act_lines  = [];

            foreach ( array_slice( $activities, 0, 30 ) as $entry ) {
                // KEY FIX: use 'created_at' and 'activity_type' — not 'timestamp' or 'title'
                $ts   = $entry['created_at'] ?? null;
                $type = strtoupper( $entry['activity_type'] ?? 'UNKNOWN' );
                $msg  = $entry['message'] ?? '';

                $ts_label = $ts
                    ? wp_date( 'Y-m-d H:i:s', strtotime( $ts ) )
                    : '—';

                $line = "[$ts_label] {$type}";
                if ( $msg ) {
                    $line .= "\n  {$msg}";
                }

                // Show any context data concisely
                $ctx = $entry['context'] ?? [];
                if ( is_array( $ctx ) && ! empty( $ctx ) ) {
                    unset( $ctx['duration_seconds'], $ctx['per_supplier'] ); // already in import history
                    foreach ( $ctx as $k => $v ) {
                        if ( ! is_array( $v ) && ! is_object( $v ) ) {
                            $line .= "\n  {$k}: {$v}";
                        }
                    }
                }

                $act_lines[] = $line;
            }

            if ( ! empty( $act_lines ) ) {
                $sections[] = "=== ACTIVITY LOG (" . count( $act_lines ) . " " . ( count( $act_lines ) === 1 ? 'entry' : 'entries' ) . ") ===\n\n"
                            . implode( "\n\n", $act_lines );
            }
        }

        /* ── 3. Read physical log files from MMI_Logger ── */
        // Must agree with wherever MMI_Logger actually writes — that's
        // mmi_shared_lib_log_dir() (ADR-0006), not an independently-guessed
        // fallback. A mismatched literal here previously caused a real
        // cross-plugin bug (mmi-cloudflare-integration/mmi-realtime-server-
        // monitor's incident-flag path, see incident-history.md).
        $log_dir = function_exists( 'mmi_shared_lib_log_dir' )
            ? mmi_shared_lib_log_dir()
            : ( defined( 'MMI_HUB_LOG_DIR' ) ? MMI_HUB_LOG_DIR : ( WP_CONTENT_DIR . '/mmi-logs' ) );

        // Map of log label → filename
        $log_map = [
            'CATALOG UPDATE' => 'catalog-update.log',
            'SYNC'           => 'sync.log',
            'MMI'            => 'mmi.log',
        ];

        // Add supplier-specific log files when filtering
        if ( $supplier !== 'all' ) {
            $log_map[ strtoupper( $supplier ) . ' SYNC' ] = "{$supplier}-sync.log";
        }

        foreach ( $log_map as $label => $filename ) {
            $file = $log_dir . $filename;
            if ( ! file_exists( $file ) ) {
                continue;
            }
            $content = file_get_contents( $file );
            if ( empty( $content ) ) {
                continue;
            }

            // Filter log file lines by supplier if requested
            $lines = explode( "\n", $content );
            if ( $supplier !== 'all' ) {
                $lines = array_filter( $lines, fn( $l ) => stripos( $l, $supplier ) !== false || stripos( $l, 'ERROR' ) !== false );
            }
            $recent = array_slice( $lines, -75 ); // last 75 lines

            if ( ! empty( array_filter( $recent ) ) ) {
                $sections[] = "=== {$label} LOG (last " . count( array_filter( $recent ) ) . " lines) ===\n\n"
                            . implode( "\n", $recent );
            }
        }

        /* ── 4. Empty state ── */
        if ( empty( $sections ) ) {
            $msg  = "No log entries found";
            $msg .= $supplier !== 'all' ? " for supplier: {$supplier}" : '';
            $msg .= ".\n\n";
            $msg .= "Tip: Logs appear after fetch and import operations run.\n";
            $msg .= "Check Data Pipeline > Data Sources to verify supplier configuration.";
            wp_send_json_success( [ 'log' => $msg ] );
            return;
        }

        wp_send_json_success( [ 'log' => implode( "\n\n" . str_repeat( '─', 60 ) . "\n\n", $sections ) ] );
    }

    /* ── Recent Activities (dashboard widget) ─────────────────────────────── */

    /**
     * Return HTML for the recent-activities sidebar/widget.
     *
     * POST params:
     *   nonce (string)
     */
    public static function handle_get_recent_activities(): void {
        check_ajax_referer( 'mmi_product_importer_nonce', 'nonce' );

        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'mmi-data-pipeline' ) ], 403 );
        }

        $activities = MMI_DB::get_recent_activities( 10 );
        $html       = '';

        if ( empty( $activities ) ) {
            $html = '<div class="no-activity"><span class="dashicons dashicons-info"></span> No recent activity</div>';
        } else {
            foreach ( $activities as $activity ) {
                // KEY FIX: use 'activity_type' and 'created_at' — not 'title' or 'timestamp'
                $type       = $activity['activity_type'] ?? 'unknown';
                $message    = $activity['message'] ?? '';
                $created_at = $activity['created_at'] ?? null;

                // Derive a display title from activity_type
                $title_map = [
                    'import'        => 'Catalog Import',
                    'import_error'  => 'Import Error',
                    'fetch'         => 'Supplier Fetch',
                    'fetch_error'   => 'Fetch Error',
                    'catalog'       => 'Catalog Update',
                    'error'         => 'Error',
                    'info'          => 'Info',
                ];
                $display_title = $title_map[ strtolower( $type ) ] ?? ucwords( str_replace( '_', ' ', $type ) );

                // Icon and status class
                $is_error = stripos( $type, 'error' ) !== false;
                $is_warn  = stripos( $type, 'warn' )  !== false;
                if ( $is_error ) {
                    $icon_class  = 'dismiss';
                    $status_class = 'error';
                } elseif ( $is_warn ) {
                    $icon_class  = 'warning';
                    $status_class = 'warning';
                } else {
                    $icon_class  = 'yes-alt';
                    $status_class = 'success';
                }

                $age = $created_at ? human_time_diff( strtotime( $created_at ) ) . ' ago' : '—';

                $html .= '<div class="activity-item status-' . esc_attr( $status_class ) . '">';
                $html .= '<div class="activity-icon"><span class="dashicons dashicons-' . esc_attr( $icon_class ) . '"></span></div>';
                $html .= '<div class="activity-content">';
                $html .= '<div class="activity-title">' . esc_html( $display_title ) . '</div>';
                $html .= '<div class="activity-message">' . esc_html( $message ) . '</div>';
                $html .= '</div>';
                $html .= '<div class="activity-time">' . esc_html( $age ) . '</div>';
                $html .= '</div>';
            }
        }

        wp_send_json_success( [ 'html' => $html ] );
    }
}
