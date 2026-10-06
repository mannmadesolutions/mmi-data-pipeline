<?php
/**
 * Import Run Insights — what happened in one import run, and why records failed
 *
 *   mmi_pipeline_run_insights           — one history row's summary, per-source
 *                                          breakdown and diagnosed failures
 *   mmi_pipeline_dismiss_process_error  — clear one Data Pipeline page notice
 *
 * A failed record used to surface only as a count plus the first exception
 * message ("1035-2515 (xchange): Invalid or duplicated SKU. (+4 more)"). That
 * message is WooCommerce's, and it does not say which product already holds
 * the SKU, or that the real cause is two store products tracked as the same
 * supplier item. diagnose_failures() looks each failed key up in the store and
 * says which products are involved, what went wrong, and what to change.
 *
 * Reads are bounded: a run records at most MAX_FAILURE_DETAILS failures, and
 * every lookup is one chunked WHERE-IN query per supplier.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Run_Insights {

    /** Process-error setting keys a notice can be dismissed for (main.php reads the same keys). */
    const PROFILE_ERROR_PREFIX = 'mmi_pipeline_process_error_profile_';
    const FETCH_ERROR_PREFIX   = 'mmi_pipeline_process_error_source_fetch_';
    const GLOBAL_ERROR_KEYS    = [
        'mmi_pipeline_process_error_supplier_fetch',
        'mmi_pipeline_process_error_catalog_import',
    ];

    /** Key the batch-level catch in MMI_Pipeline_Cron records when a whole batch stops. */
    const BATCH_FAILURE_KEY = '(entire batch)';

    public static function init(): void {
        add_action( 'wp_ajax_mmi_pipeline_run_insights', [ __CLASS__, 'ajax_run_insights' ] );
        add_action( 'wp_ajax_mmi_pipeline_dismiss_process_error', [ __CLASS__, 'ajax_dismiss_process_error' ] );
    }

    /* ── AJAX ─────────────────────────────────────────────────────────── */

    public static function ajax_run_insights(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
        }

        $history_id = absint( $_POST['history_id'] ?? 0 );
        $insights   = $history_id ? self::build( $history_id ) : null;
        if ( null === $insights ) {
            wp_send_json_error( [ 'message' => 'That import run is no longer in the history.' ] );
        }
        wp_send_json_success( $insights );
    }

    public static function ajax_dismiss_process_error(): void {
        check_ajax_referer( 'mmi_pipeline_nonce', 'nonce' );
        if ( ! mmi_data_pipeline_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
        }

        $key = sanitize_key( wp_unslash( $_POST['error_key'] ?? '' ) );
        if ( ! self::is_dismissible_key( $key ) ) {
            wp_send_json_error( [ 'message' => 'Unknown notice.' ] );
        }

        MMI_DB::delete_setting( $key );
        mmi_data_pipeline_audit( 'pipeline.notice.dismiss', [
            'object_type' => 'process_error',
            'object_id'   => $key,
            'outcome'     => 'success',
        ] );
        wp_send_json_success();
    }

    /**
     * Only real process-error keys can be deleted through the dismiss endpoint,
     * never an arbitrary setting name.
     */
    public static function is_dismissible_key( string $key ): bool {
        if ( in_array( $key, self::GLOBAL_ERROR_KEYS, true ) ) {
            return true;
        }
        if ( str_starts_with( $key, self::FETCH_ERROR_PREFIX ) ) {
            $supplier_id = substr( $key, strlen( self::FETCH_ERROR_PREFIX ) );
            return array_key_exists( $supplier_id, MMI_Pipeline_Admin::get_configured_suppliers() );
        }
        if ( str_starts_with( $key, self::PROFILE_ERROR_PREFIX ) ) {
            $profile_id = substr( $key, strlen( self::PROFILE_ERROR_PREFIX ) );
            return array_key_exists( $profile_id, (array) MMI_DB::get_profiles() );
        }
        return false;
    }

    /**
     * Every Data Pipeline process notice currently set, keyed by its setting
     * key, each with what main.php needs to render it usefully.
     *
     * Profile notices carry their run (history_id; older notices fall back to
     * the profile's newest run with errors) and that run's counts and top
     * reasons, read from the history row's own notes — no store lookups, so
     * this is safe on page render.
     *
     * @return array<string,array>
     */
    public static function get_page_notices(): array {
        $notices = [];

        foreach ( array_keys( (array) MMI_DB::get_profiles() ) as $profile_id ) {
            $key = self::PROFILE_ERROR_PREFIX . $profile_id;
            $err = MMI_DB::get_setting( $key );
            if ( empty( $err ) || ! is_array( $err ) ) {
                continue;
            }
            $history_id = (int) ( $err['history_id'] ?? 0 ) ?: self::latest_failed_run_id( (string) $profile_id );
            $run        = $history_id ? self::get_run( $history_id ) : null;
            $notes      = $run ? json_decode( (string) ( $run['notes'] ?? '' ), true ) : null;
            $notes      = is_array( $notes ) ? $notes : [];
            $status     = (string) ( $run['status'] ?? '' );

            $notices[ $key ] = $err + [
                'kind'       => 'profile',
                'history_id' => $history_id,
                // Partial = the run finished and only some records failed.
                'partial'    => in_array( $status, [ 'Partial', 'Partial Success' ], true ),
                'counts'     => $run ? [
                    'imported' => (int) $run['imported'],
                    'updated'  => (int) $run['updated'],
                    'skipped'  => (int) $run['skipped'],
                    'errors'   => (int) $run['errors'],
                ] : null,
                'reasons'    => array_slice( self::reason_counts( array_values( array_filter( (array) ( $notes['failures'] ?? [] ), 'is_array' ) ) ), 0, 3 ),
            ];
        }

        foreach ( MMI_Pipeline_Admin::get_configured_suppliers() as $supplier_id => $source ) {
            $key = self::FETCH_ERROR_PREFIX . $supplier_id;
            $err = MMI_DB::get_setting( $key );
            if ( empty( $err ) || ! is_array( $err ) ) {
                continue;
            }
            $notices[ $key ] = $err + [
                'kind'          => 'fetch',
                'supplier_id'   => (string) $supplier_id,
                'supplier_name' => (string) ( $source['supplier_name'] ?? $supplier_id ),
            ];
            $notices[ $key ]['process'] = 'Data Fetch: ' . $notices[ $key ]['supplier_name'];
        }

        foreach ( self::GLOBAL_ERROR_KEYS as $key ) {
            $err = MMI_DB::get_setting( $key );
            if ( ! empty( $err ) && is_array( $err ) ) {
                $notices[ $key ] = $err + [ 'kind' => 'global' ];
            }
        }

        return $notices;
    }

    /* ── Run lookup ───────────────────────────────────────────────────── */

    public static function get_run( int $history_id ): ?array {
        global $wpdb;
        $table = MMI_DB::import_history_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from MMI_DB.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $history_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Newest history row for a profile that recorded failures — the run a
     * page notice is about, for notices stored before they carried history_id.
     */
    public static function latest_failed_run_id( string $profile_id ): int {
        global $wpdb;
        $table = MMI_DB::import_history_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from MMI_DB.
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE profile_id = %s AND errors > 0 ORDER BY id DESC LIMIT 1",
            $profile_id
        ) );
    }

    /* ── Insights ─────────────────────────────────────────────────────── */

    /**
     * Everything the Run Insights modal shows for one history row.
     *
     * @return array|null Null when the row does not exist.
     */
    public static function build( int $history_id ): ?array {
        $run = self::get_run( $history_id );
        if ( ! $run ) {
            return null;
        }

        $notes      = json_decode( (string) ( $run['notes'] ?? '' ), true );
        $notes      = is_array( $notes ) ? $notes : [];
        $profile_id = (string) ( $run['profile_id'] ?? 'default' );
        $profiles   = (array) MMI_DB::get_profiles();

        $failures = array_values( array_filter( (array) ( $notes['failures'] ?? [] ), 'is_array' ) );
        $errors   = (int) ( $run['errors'] ?? 0 );

        return [
            'run'          => [
                'id'           => (int) $run['id'],
                'profile_id'   => $profile_id,
                'profile_name' => $profiles[ $profile_id ]['name'] ?? ucfirst( $profile_id ),
                'status'       => (string) ( $run['status'] ?? '' ),
                'started_at'   => (string) ( $run['started_at'] ?? '' ),
                'completed_at' => (string) ( $run['completed_at'] ?? '' ),
                'duration'     => isset( $notes['duration_seconds'] ) ? MMI_Pipeline_Cron::format_duration( (float) $notes['duration_seconds'] ) : '',
                'imported'     => (int) ( $run['imported'] ?? 0 ),
                'updated'      => (int) ( $run['updated'] ?? 0 ),
                'skipped'      => (int) ( $run['skipped'] ?? 0 ),
                'errors'       => $errors,
            ],
            // Run-level problems (no per-record detail): an abandoned batch
            // chain, a manual run's fatal, or a user abort.
            'run_error'    => (string) ( $notes['last_error'] ?? $notes['error'] ?? '' ),
            'aborted'      => ! empty( $notes['aborted'] ) && empty( $notes['abandoned'] ),
            'abandoned'    => ! empty( $notes['abandoned'] ),
            'per_supplier' => self::per_supplier( $notes ),
            'supplier_names' => array_map(
                static fn( $source ) => (string) ( $source['supplier_name'] ?? '' ),
                MMI_Pipeline_Admin::get_configured_suppliers()
            ),
            'failures'     => self::diagnose_failures( $failures ),
            'reasons'      => self::reason_counts( $failures ),
            // Failure detail is capped per run; say so instead of implying
            // the list is complete.
            'detail_cap'   => [
                'recorded' => count( $failures ),
                'total'    => $errors,
            ],
        ];
    }

    private static function per_supplier( array $notes ): array {
        // Scheduled runs store per_supplier; an abandoned run stores the
        // partial state under partial_state with the same per-source shape.
        $source = $notes['per_supplier'] ?? $notes['partial_state'] ?? [];
        $rows   = [];
        foreach ( (array) $source as $supplier => $s ) {
            if ( ! is_array( $s ) ) {
                continue;
            }
            $rows[] = [
                'supplier' => (string) $supplier,
                'imported' => (int) ( $s['imported'] ?? 0 ),
                'updated'  => (int) ( $s['updated'] ?? 0 ),
                'skipped'  => (int) ( $s['skipped'] ?? 0 ),
                'failed'   => (int) ( $s['failed'] ?? 0 ),
            ];
        }
        return $rows;
    }

    /** [ {reason, count} ], most frequent first. */
    public static function reason_counts( array $failures ): array {
        $counts = [];
        foreach ( $failures as $f ) {
            $reason            = (string) ( $f['reason'] ?? 'Unknown error' );
            $counts[ $reason ] = ( $counts[ $reason ] ?? 0 ) + 1;
        }
        arsort( $counts );
        $out = [];
        foreach ( $counts as $reason => $count ) {
            $out[] = [ 'reason' => $reason, 'count' => $count ];
        }
        return $out;
    }

    /**
     * Look every failed key up in the store and explain the failure.
     *
     * Per supplier: every product carrying the key in that supplier's tracking
     * meta (all of them, not the importer's first match), plus every product
     * already holding the key as its SKU. Two queries per supplier, plus one
     * to load the products involved.
     *
     * @param array $failures [ {supplier, key, reason, title?, product_id?}, ... ]
     */
    public static function diagnose_failures( array $failures ): array {
        $keys_by_supplier = [];
        foreach ( $failures as $f ) {
            $key = (string) ( $f['key'] ?? '' );
            if ( $key === '' || $key === '(unknown)' || $key === self::BATCH_FAILURE_KEY ) {
                continue;
            }
            $keys_by_supplier[ (string) ( $f['supplier'] ?? '' ) ][] = $key;
        }

        $tracked   = []; // supplier => key => [product ids]
        $pk_meta   = []; // supplier => tracking meta key
        $sku_owner = []; // key => [product ids]
        $all_keys  = [];
        foreach ( $keys_by_supplier as $supplier => $keys ) {
            $pk_meta[ $supplier ] = (string) MMI_DB::get_primary_key( $supplier, 'wc', '_sku' );
            $tracked[ $supplier ] = self::find_all_by_meta( $pk_meta[ $supplier ], $keys );
            $all_keys             = array_merge( $all_keys, $keys );
        }
        if ( $all_keys ) {
            $sku_owner = self::find_all_by_meta( '_sku', $all_keys );
        }

        $product_ids = [];
        foreach ( $failures as $f ) {
            if ( ! empty( $f['product_id'] ) ) {
                $product_ids[] = (int) $f['product_id'];
            }
        }
        foreach ( $tracked as $by_key ) {
            foreach ( $by_key as $ids ) {
                $product_ids = array_merge( $product_ids, $ids );
            }
        }
        foreach ( $sku_owner as $ids ) {
            $product_ids = array_merge( $product_ids, $ids );
        }
        $products = self::load_products( $product_ids );

        $out = [];
        foreach ( $failures as $f ) {
            $supplier   = (string) ( $f['supplier'] ?? '' );
            $key        = (string) ( $f['key'] ?? '' );
            $reason     = (string) ( $f['reason'] ?? '' );
            $tracked_ids = $tracked[ $supplier ][ $key ] ?? [];
            $owner_ids   = $sku_owner[ $key ] ?? [];
            $matched_id  = (int) ( $f['product_id'] ?? 0 );

            $diagnosis = self::classify( $key, $reason, $tracked_ids, $owner_ids, $matched_id, $pk_meta[ $supplier ] ?? '' );

            $involved = array_values( array_unique( array_merge(
                $matched_id ? [ $matched_id ] : [],
                $tracked_ids,
                $owner_ids
            ) ) );

            $out[] = [
                'supplier'   => $supplier,
                'key'        => $key,
                'title'      => (string) ( $f['title'] ?? '' ),
                'reason'     => $reason,
                'matched_id' => $matched_id,
                'diagnosis'  => $diagnosis,
                'products'   => array_values( array_filter( array_map(
                    static function ( $id ) use ( $products, $tracked_ids, $owner_ids, $matched_id ) {
                        if ( ! isset( $products[ $id ] ) ) {
                            return null;
                        }
                        return $products[ $id ] + [
                            'tracked'   => in_array( $id, $tracked_ids, true ),
                            'sku_owner' => in_array( $id, $owner_ids, true ),
                            'matched'   => $id === $matched_id,
                        ];
                    },
                    $involved
                ) ) ),
            ];
        }
        return $out;
    }

    /**
     * Name the cause. Codes drive the modal's chip colors; text is what the
     * admin reads.
     */
    private static function classify( string $key, string $reason, array $tracked_ids, array $owner_ids, int $matched_id, string $pk_meta ): array {
        $is_sku_error = stripos( $reason, 'sku' ) !== false;
        $id_list      = static fn( array $ids ) => implode( ', ', array_map( static fn( $id ) => '#' . $id, $ids ) );

        if ( $key === self::BATCH_FAILURE_KEY ) {
            return [
                'code'    => 'batch',
                'label'   => 'Batch stopped',
                'explain' => 'An error outside any single record stopped this batch, so the rest of this source was not processed in this run.',
                'fix'     => 'The next scheduled run starts this source again from the beginning. If it fails the same way, check the sync log for this time.',
            ];
        }

        if ( count( $tracked_ids ) > 1 ) {
            $owner_note = '';
            if ( $is_sku_error && $owner_ids ) {
                $owner_note = sprintf( ' %s already has SKU %s, so WooCommerce rejects giving it to %s.',
                    $id_list( $owner_ids ),
                    $key,
                    $matched_id ? '#' . $matched_id : 'the other one'
                );
            }
            return [
                'code'    => 'duplicate_products',
                'label'   => 'Duplicate store products',
                'explain' => sprintf( '%d store products are linked to this one supplier item: %s each have %s = %s. The import can only update one of them.%s',
                    count( $tracked_ids ),
                    $id_list( $tracked_ids ),
                    $pk_meta,
                    $key,
                    $owner_note
                ),
                'fix'     => 'Keep one product and trash the other (or clear its supplier key so it stops claiming this item). The next import then updates the one you keep.',
            ];
        }

        if ( $is_sku_error && $owner_ids && array_diff( $owner_ids, $tracked_ids ) ) {
            return [
                'code'    => 'sku_taken',
                'label'   => 'SKU used by another product',
                'explain' => sprintf( 'SKU %s already belongs to %s, which is not linked to this supplier item.', $key, $id_list( $owner_ids ) ),
                'fix'     => sprintf( 'If %s is the same product, set its %s to %s so the import updates it. Otherwise change one of the SKUs.', $id_list( $owner_ids ), $pk_meta, $key ),
            ];
        }

        if ( ! $tracked_ids && ! $matched_id ) {
            return [
                'code'    => 'create_failed',
                'label'   => 'New product not created',
                'explain' => 'No store product is linked to this supplier item, and creating one failed with the error shown.',
                'fix'     => 'Check this record in the source data for missing or malformed values.',
            ];
        }

        return [
            'code'    => 'record_error',
            'label'   => 'Record error',
            'explain' => 'The linked product could not be updated from this record.',
            'fix'     => 'Compare this record in the source data with the product, using the error shown.',
        ];
    }

    /**
     * Every live product/variation whose $meta_key equals one of $values.
     *
     * Unlike MMI_Pipeline_Field_Resolver::find_product_ids_by_primary_keys(),
     * which keeps only the first match, this keeps every match — that is
     * the point of the diagnosis. Special keys (__post_id/__post_title)
     * cannot hold a duplicate the same way and are not looked up.
     *
     * @return array<string,int[]> value => product IDs
     */
    private static function find_all_by_meta( string $meta_key, array $values ): array {
        global $wpdb;

        if ( $meta_key === '' || str_starts_with( $meta_key, '__' ) ) {
            return [];
        }
        $values = array_values( array_unique( array_map( 'strval', $values ) ) );
        if ( ! $values ) {
            return [];
        }

        $map = [];
        foreach ( array_chunk( $values, 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $sql = "SELECT pm.post_id, pm.meta_value
                      FROM {$wpdb->postmeta} pm
                      INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = %s
                       AND p.post_type IN ('product','product_variation')
                       AND p.post_status != 'trash'
                       AND pm.meta_value IN ($placeholders)
                     ORDER BY pm.meta_id ASC";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( [ $meta_key ], $chunk ) ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                $map[ $row['meta_value'] ][] = (int) $row['post_id'];
            }
        }
        foreach ( $map as $value => $ids ) {
            $map[ $value ] = array_values( array_unique( $ids ) );
        }
        return $map;
    }

    /** id => {id, title, status, type, sku, edit_url, created} for the products named in a diagnosis. */
    private static function load_products( array $ids ): array {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( ! $ids ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT p.ID, p.post_title, p.post_status, p.post_type, p.post_parent, p.post_date, sku.meta_value AS sku
                  FROM {$wpdb->posts} p
                  LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
                 WHERE p.ID IN ($placeholders)";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $id        = (int) $row['ID'];
            // A variation is edited on its parent's screen.
            $edit_id   = $row['post_type'] === 'product_variation' && $row['post_parent'] ? (int) $row['post_parent'] : $id;
            $out[ $id ] = [
                'id'       => $id,
                'title'    => html_entity_decode( (string) $row['post_title'], ENT_QUOTES ),
                'status'   => (string) $row['post_status'],
                'type'     => $row['post_type'] === 'product_variation' ? 'variation' : 'product',
                'sku'      => (string) ( $row['sku'] ?? '' ),
                'created'  => (string) $row['post_date'],
                'edit_url' => admin_url( 'post.php?post=' . $edit_id . '&action=edit' ),
            ];
        }
        return $out;
    }
}

MMI_Pipeline_Run_Insights::init();
