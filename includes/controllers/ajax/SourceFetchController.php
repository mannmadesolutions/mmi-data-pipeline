<?php
/**
 * Per-source freshness + on-demand fetch.
 *
 * The Sources table's "last fetch" column answers "when did the schedule last
 * touch this source". This controller answers the narrower question the preview
 * modal actually needs: "how old is the data I am looking at right now, and can
 * I refresh it without leaving this screen".
 *
 * Freshness is read from the cached feed file's mtime rather than from a stored
 * timestamp, for the same reason MMI_Pipeline_Cron::refresh_all_source_counts()
 * now does: a stored "fetched at" can be written by a run that fetched nothing,
 * and was — the Sources table claimed a 1-hour-old fetch for a 157-hour-old feed.
 * The file's own mtime cannot lie about the file.
 */

namespace MannMade\DataPipeline\Controllers\AJAX;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MMI_Logger;
use MMI_API_Throttler;
use MMI_Pipeline_Config_Validator;
use MMI_Pipeline_Supplier_Fetch_Runner;

/** Action Scheduler group + hook for on-demand single-source fetches. */
const FETCH_NOW_HOOK  = 'mmi_pipeline_fetch_source_now';
const FETCH_NOW_GROUP = 'mmi-pipeline-source-fetch';

/**
 * Lock TTL for one on-demand fetch.
 *
 * Per AGENTS.md Rule 4 this matches real runtime, not a comfortable
 * overestimate: a full Xchange pull (vendors + products) measures ~30s, and the
 * 5-minute ceiling for a per-job lock leaves headroom without letting a crashed
 * job block the button for the rest of the day.
 */
const FETCH_LOCK_TTL = 300;

/** Absolute path to the shared feed-cache directory. */
function json_dir(): string {
    return mmi_shared_lib_json_dir();
}

/** Transient key for the per-source fetch lock. */
function fetch_lock_key( string $supplier_id ): string {
    return 'mmi_pipeline_fetch_now_' . $supplier_id;
}

/**
 * Resolve a feed filename to a real path inside the feed-cache directory.
 *
 * Returns null for anything that escapes that directory — the filename arrives
 * from the browser, so basename() alone is not a sufficient guard against a
 * crafted value reaching filemtime()/read paths.
 */
function resolve_feed_path( string $filename ): ?string {
    $filename = basename( sanitize_file_name( $filename ) );

    if ( $filename === '' || ! str_ends_with( strtolower( $filename ), '.json' ) ) {
        return null;
    }

    $dir  = realpath( json_dir() );
    $path = realpath( $dir . DIRECTORY_SEPARATOR . $filename );

    if ( $dir === false || $path === false || ! str_starts_with( $path, $dir . DIRECTORY_SEPARATOR ) ) {
        return null;
    }

    return $path;
}

/**
 * Freshness snapshot for one source's cached feed.
 *
 * @return array<string, mixed>
 */
function build_source_status( string $supplier_id, string $filename ): array {
    global $wpdb;

    $path  = resolve_feed_path( $filename );
    $mtime = ( $path && file_exists( $path ) ) ? filemtime( $path ) : null;

    $ds_table = $wpdb->prefix . 'mmi_data_sources';
    $row      = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT source_type, last_fetch_count, last_fetch_status FROM {$ds_table} WHERE supplier_id = %s",
            $supplier_id
        ),
        ARRAY_A
    ) ?: [];

    $source_type = $row['source_type'] ?? 'api';
    $stale_after = class_exists( MMI_Pipeline_Config_Validator::class )
        ? MMI_Pipeline_Config_Validator::STALE_FEED_HOURS
        : 48;

    // Uploads are excluded from age-based staleness for the same reason the
    // validator and the Sources table exclude them: a static uploaded file being
    // old is the user's intent, not a fault.
    $age_hours = $mtime ? ( time() - $mtime ) / HOUR_IN_SECONDS : null;
    $is_stale  = $age_hours !== null && $source_type !== 'upload' && $age_hours > $stale_after;

    return [
        'supplier_id'      => $supplier_id,
        'filename'         => $path ? basename( $path ) : $filename,
        'source_type'      => $source_type,
        'file_mtime'       => $mtime,
        'fetched_display'  => $mtime ? wp_date( 'M j, Y \a\t g:i a', $mtime ) : null,
        'fetched_ago'      => $mtime ? human_time_diff( $mtime, time() ) . ' ago' : null,
        'age_hours'        => $age_hours !== null ? round( $age_hours, 1 ) : null,
        'is_stale'         => $is_stale,
        'stale_after_hours' => $stale_after,
        'record_count'      => isset( $row['last_fetch_count'] ) ? (int) $row['last_fetch_count'] : null,
        'last_fetch_status' => $row['last_fetch_status'] ?? null,
        'can_fetch'        => $source_type === 'api',
        'fetching'         => (bool) get_transient( fetch_lock_key( $supplier_id ) )
            || ( class_exists( 'MMI_Pipeline_Cron' ) && \MMI_Pipeline_Cron::is_fetch_running( $supplier_id ) ),
        // Throttler profiles are keyed by supplier ID (MMI_API_Throttler::KNOWN_APIS);
        // an unregistered source falls through to that class's conservative
        // default rather than going unthrottled.
        'throttle_wait_ms' => $source_type === 'api'
            ? ( class_exists( MMI_API_Throttler::class ) ? MMI_API_Throttler::wait_ms( $supplier_id ) : 0 )
            : 0,
    ];
}

/* ── Endpoints ────────────────────────────────────────────────────────────── */

/**
 * Freshness of one source's cached feed.
 *
 * Also polled while an on-demand fetch runs — deliberately cheap (one indexed
 * row + one filemtime()) so a few seconds of polling costs nothing measurable.
 */
add_action( 'wp_ajax_mmi_pipeline_source_status', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $supplier_id = sanitize_key( $_POST['supplier_id'] ?? '' );
    $filename    = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );

    if ( $supplier_id === '' ) {
        wp_send_json_error( [ 'message' => 'Supplier ID required' ] );
    }

    if ( $filename === '' ) {
        $filename = $supplier_id . '-products.json';
    }

    wp_send_json_success( build_source_status( $supplier_id, $filename ) );
} );

/**
 * Stream one cached feed file to an admin's browser (Source Preview modal).
 *
 * The feed directory is private since ADR-0012 — it no longer has a public
 * URL — so the browser reads the file through here instead. Streams the file
 * as-is (no json_decode of a 13MB feed in a web worker). With ?v=<mtime> the
 * response is privately cacheable, so re-opening the modal doesn't download
 * it again until a fetch replaces the file.
 */
add_action( 'wp_ajax_mmi_pipeline_source_file', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
    }

    $filename = sanitize_file_name( wp_unslash( $_REQUEST['filename'] ?? '' ) );
    $dir      = realpath( mmi_shared_lib_json_dir() );
    $path     = $filename !== '' && $dir ? realpath( $dir . '/' . $filename ) : false;

    if ( ! $path || substr( $path, -5 ) !== '.json' || strpos( $path, $dir . DIRECTORY_SEPARATOR ) !== 0 || ! is_file( $path ) ) {
        wp_send_json_error( [ 'message' => 'Feed file not found', 'not_found' => true ], 404 );
    }

    // admin-ajax.php already sent no-cache headers; replace them only when
    // the URL is versioned by the file's mtime.
    if ( isset( $_REQUEST['v'] ) ) {
        header_remove( 'Expires' );
        header_remove( 'Pragma' );
        header( 'Cache-Control: private, max-age=31536000' );
    }
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Length: ' . filesize( $path ) );
    readfile( $path );
    exit;
} );

/**
 * Queue an immediate fetch for one API source.
 *
 * Dispatches rather than fetching inline (AGENTS.md Rule 9): a full supplier
 * pull runs tens of seconds and grows with the catalog, which is far too long to
 * hold a web-facing PHP-FPM worker — the scarcer resource — especially when
 * nothing stops several admins clicking at once.
 */
add_action( 'wp_ajax_mmi_pipeline_fetch_source_now', function () {
    check_ajax_referer( 'mmi_pipeline_import_settings', 'nonce' );

    if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $supplier_id = sanitize_key( $_POST['supplier_id'] ?? '' );
    if ( $supplier_id === '' ) {
        wp_send_json_error( [ 'message' => 'Supplier ID required' ] );
    }

    global $wpdb;
    $ds_table    = $wpdb->prefix . 'mmi_data_sources';
    $source_type = $wpdb->get_var(
        $wpdb->prepare( "SELECT source_type FROM {$ds_table} WHERE supplier_id = %s", $supplier_id )
    );

    if ( $source_type === null ) {
        wp_send_json_error( [ 'message' => 'Unknown data source.' ] );
    }

    if ( $source_type !== 'api' ) {
        wp_send_json_error( [
            'message' => 'Only API sources can be fetched on demand. Upload and file-based sources are refreshed by replacing the file.',
        ] );
    }

    // Rate limiting, answered rather than waited out. throttle() would enforce
    // the same gap by sleeping, which is correct inside the background worker
    // that actually calls the API — but here it would pin a request thread for
    // the whole backoff window just to tell the user "not yet".
    if ( class_exists( MMI_API_Throttler::class ) ) {
        $wait_ms = MMI_API_Throttler::wait_ms( $supplier_id );
        if ( $wait_ms > 0 ) {
            $wait_s = max( 1, (int) ceil( $wait_ms / 1000 ) );
            wp_send_json_error( [
                'code'        => 'rate_limited',
                'retry_in_ms' => $wait_ms,
                'message'     => sprintf(
                    /* translators: %s: number of seconds to wait. */
                    _n(
                        'Rate limit for this API — retrying in %s second.',
                        'Rate limit for this API — retrying in %s seconds.',
                        $wait_s,
                        'mmi-data-pipeline'
                    ),
                    number_format_i18n( $wait_s )
                ),
            ] );
        }
    }

    // One path for every manual fetch (MMI_Pipeline_Cron::queue_manual_fetch()):
    // XChange's stepped chain or one queued fetch, under the schedule's lock.
    $queued = \MMI_Pipeline_Cron::queue_manual_fetch( $supplier_id );
    if ( empty( $queued['started'] ) ) {
        wp_send_json_error( [
            'code'    => ( $queued['reason'] ?? '' ) === 'running' ? 'already_running' : 'unavailable',
            'message' => ( $queued['reason'] ?? '' ) === 'running'
                ? 'A fetch for this source is already running.'
                : 'Action Scheduler is unavailable — cannot queue a fetch.',
        ] );
    }

    mmi_data_pipeline_audit( 'source.fetch', [
        'object_type' => 'data_source',
        'object_id'   => $supplier_id,
        'outcome'     => 'success',
        'details'     => [ 'trigger' => 'manual_queued' ],
    ] );

    MMI_Logger::info(
        sprintf( 'On-demand fetch queued for source "%s"', $supplier_id ),
        [ 'user_id' => get_current_user_id() ],
        'sync',
        'MMI_Pipeline_Source_Fetch'
    );

    wp_send_json_success( [
        'queued'  => true,
        'message' => 'Fetch queued. This can take a minute for large catalogs.',
    ] );
} );

/**
 * Worker: fetch one source, then release the lock.
 *
 * Single attempt by design (AGENTS.md Rule 10) — a failure is recorded and
 * stops there. This is a button the user can press again, so a self-rescheduling
 * retry loop would only hide a broken credential behind repeated silent tries.
 */
add_action( FETCH_NOW_HOOK, function ( $supplier_id ) {
    $supplier_id = sanitize_key( (string) $supplier_id );
    $lock_key    = fetch_lock_key( $supplier_id );
    $started     = microtime( true );

    global $wpdb;
    $ds_table = $wpdb->prefix . 'mmi_data_sources';

    try {
        $runner_class = MMI_Pipeline_Supplier_Fetch_Runner::class;

        if ( class_exists( $runner_class )
            && in_array( $supplier_id, $runner_class::SUPPORTED_SUPPLIERS, true ) ) {
            // Dedicated updater (Xchange/SkuPort/Plugivery). Its own HTTP layer
            // routes through MMI_API_Throttler, so per-call pacing is enforced
            // inside this worker where sleeping is the right thing to do.
            //
            // These updaters echo progress for their CLI origins. The buffer is
            // discarded in a finally block, not after the call — a throwing
            // updater would otherwise leave its output buffered and flush it
            // into whatever response the Action Scheduler runner is writing.
            ob_start();
            try {
                ( new $runner_class() )->runOne( $supplier_id );
            } finally {
                ob_end_clean();
            }
        } elseif ( class_exists( 'MMI_Pipeline_Upload_Source_Fetcher' ) && \MMI_Pipeline_Upload_Source_Fetcher::is_upload_source( $supplier_id ) ) {
            // Upload sources need no network fetch — the file is already on
            // disk via its WP attachment — so they don't wait on
            // Data_Source_Manager (see that class's docblock for why).
            \MMI_Pipeline_Upload_Source_Fetcher::fetch( $supplier_id );
        } elseif ( class_exists( 'MannMade\\Integrations\\Acquisition\\Data_Source_Manager' ) ) {
            \MannMade\Integrations\Acquisition\Data_Source_Manager::instance()->fetch_from_supplier( $supplier_id );
        } else {
            throw new \RuntimeException( 'No fetch mechanism available for this source.' );
        }

        // Count from the file the fetch just wrote, so the recorded count and
        // the recorded timestamp describe the same bytes.
        $path  = resolve_feed_path( $supplier_id . '-products.json' );
        $mtime = ( $path && file_exists( $path ) ) ? filemtime( $path ) : null;
        $count = 0;
        if ( $path && file_exists( $path ) ) {
            $data = json_decode( (string) file_get_contents( $path ), true );
            if ( is_array( $data ) ) {
                $items = isset( $data[0] ) ? $data : ( $data['products'] ?? $data['data'] ?? $data['items'] ?? null );
                $count = is_array( $items ) ? count( $items ) : count( $data );
            }
        }

        // Did this run actually replace the file? An updater that silently
        // no-ops leaves a perfectly parseable old file behind, so counting
        // records is not evidence that anything was fetched.
        $rewritten = $mtime !== null && $mtime >= (int) floor( $started );

        // Not rewriting is only a fault if the file left behind is old. Several
        // updaters deliberately skip when their feed is minutes fresh (Xchange
        // returns early under 10 minutes, precisely to respect the API's rate
        // limit) — reporting that as a failure would train the user to ignore
        // the warning that matters.
        $stale_after = class_exists( MMI_Pipeline_Config_Validator::class )
            ? MMI_Pipeline_Config_Validator::STALE_FEED_HOURS
            : 48;
        $is_stale = ! $rewritten
            && ( $mtime === null || ( time() - $mtime ) / HOUR_IN_SECONDS > $stale_after );

        $wpdb->update( $ds_table, [
            'last_fetch_at'     => $mtime
                ? gmdate( 'Y-m-d H:i:s', $mtime + ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) )
                : current_time( 'mysql' ),
            'last_fetch_status' => $is_stale ? 'stale' : 'success',
            'last_fetch_count'  => $count,
            'updated_at'        => current_time( 'mysql' ),
        ], [ 'supplier_id' => $supplier_id ] );

        $duration = round( microtime( true ) - $started, 2 );

        if ( $is_stale ) {
            MMI_Logger::error(
                sprintf( 'On-demand fetch for "%s" produced NO NEW DATA and the feed on disk is still stale.', $supplier_id ),
                [ 'duration_seconds' => $duration ], 'sync', 'MMI_Pipeline_Source_Fetch'
            );
        } else {
            MMI_Logger::info(
                sprintf(
                    'On-demand fetch for "%s" %s in %ss (%d records)',
                    $supplier_id,
                    $rewritten ? 'completed' : 'found the feed already current',
                    $duration,
                    $count
                ),
                [], 'sync', 'MMI_Pipeline_Source_Fetch'
            );
        }
    } catch ( \Throwable $e ) {
        $wpdb->update( $ds_table, [
            'last_fetch_status' => 'failed',
            'updated_at'        => current_time( 'mysql' ),
        ], [ 'supplier_id' => $supplier_id ] );

        MMI_Logger::error(
            sprintf( 'On-demand fetch for "%s" failed: %s', $supplier_id, $e->getMessage() ),
            [], 'sync', 'MMI_Pipeline_Source_Fetch'
        );
    } finally {
        delete_transient( $lock_key );
    }
}, 10, 1 );
