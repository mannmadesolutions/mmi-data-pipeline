<?php
/**
 * Product Workbench — change log
 *
 * Every Workbench apply is a "job": a header (what was done, to how many
 * products, how far it got) stored as an MMI setting, plus one row per
 * product it actually changed in {prefix}mmi_workbench_changes holding that
 * product's state before and after. The before-state is what Undo restores.
 *
 * The table creates itself on first use (dbDelta, versioned by a setting),
 * so there is no activation step to miss.
 *
 * @package MannMade\DataPipeline\Workbench
 */

namespace MannMade\DataPipeline\Workbench;

use MMI_DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Workbench_Change_Log {

    const SCHEMA_VERSION     = 1;
    const SCHEMA_SETTING     = 'mmi_workbench_schema_version';
    const JOBS_SETTING       = 'mmi_workbench_jobs';
    const JOB_IDS_PREFIX     = 'mmi_workbench_job_ids_';
    const MAX_JOBS           = 30;

    /** Change-row states. */
    const ROW_APPLIED  = 0;
    const ROW_UNDONE   = 1;
    const ROW_CONFLICT = 2; // edited again since the job ran — left alone by Undo.

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mmi_workbench_changes';
    }

    public static function ensure_table(): void {
        if ( (int) MMI_DB::get_setting( self::SCHEMA_SETTING, 0 ) >= self::SCHEMA_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // No SQL comments in here: dbDelta() splits on them (AGENTS.md,
        // Standalone Independence pattern 6).
        dbDelta( 'CREATE TABLE ' . self::table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id VARCHAR(40) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            before_state LONGTEXT NOT NULL,
            after_state LONGTEXT NOT NULL,
            state TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY job_row (job_id, id)
        ) " . $wpdb->get_charset_collate() . ';' );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) );
        if ( $exists === self::table() ) {
            MMI_DB::set_setting( self::SCHEMA_SETTING, self::SCHEMA_VERSION );
        }
    }

    /* ── Jobs ──────────────────────────────────────────────────────────── */

    /** @return array<string,array> job_id => header, newest first. */
    public static function jobs(): array {
        $jobs = MMI_DB::get_setting( self::JOBS_SETTING, [] );
        return is_array( $jobs ) ? $jobs : [];
    }

    public static function job( string $job_id ): ?array {
        return self::jobs()[ $job_id ] ?? null;
    }

    public static function save_job( array $job ): void {
        $jobs = self::jobs();
        $jobs[ $job['id'] ] = $job;
        uasort( $jobs, static fn( $a, $b ) => strcmp( $b['created_at'], $a['created_at'] ) );
        // Oldest jobs age out, with their product lists and change rows.
        foreach ( array_slice( array_keys( $jobs ), self::MAX_JOBS ) as $old ) {
            self::purge_job( $old );
            unset( $jobs[ $old ] );
        }
        MMI_DB::set_setting( self::JOBS_SETTING, $jobs );
    }

    /** The product IDs a job was started on, in processing order. */
    public static function job_ids( string $job_id ): array {
        $ids = MMI_DB::get_setting( self::JOB_IDS_PREFIX . $job_id, [] );
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }

    public static function save_job_ids( string $job_id, array $ids ): void {
        MMI_DB::set_setting( self::JOB_IDS_PREFIX . $job_id, array_values( array_map( 'intval', $ids ) ) );
    }

    private static function purge_job( string $job_id ): void {
        global $wpdb;
        MMI_DB::delete_setting( self::JOB_IDS_PREFIX . $job_id );
        $wpdb->delete( self::table(), [ 'job_id' => $job_id ], [ '%s' ] );
    }

    /* ── Change rows ───────────────────────────────────────────────────── */

    /** @param list<array{product_id:int,before:array,after:array}> $changes */
    public static function record( string $job_id, array $changes ): void {
        if ( empty( $changes ) ) {
            return;
        }
        global $wpdb;
        $values = [];
        $args   = [];
        foreach ( $changes as $c ) {
            $values[] = '(%s, %d, %s, %s, %d)';
            array_push( $args, $job_id, (int) $c['product_id'], wp_json_encode( $c['before'] ), wp_json_encode( $c['after'] ), self::ROW_APPLIED );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
        $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . self::table() . ' (job_id, product_id, before_state, after_state, state) VALUES ' . implode( ',', $values ),
            ...$args
        ) );
    }

    /**
     * The next batch of still-applied rows for Undo, after row id $after_id.
     *
     * @return list<array{id:int,product_id:int,before:array,after:array}>
     */
    public static function rows_to_undo( string $job_id, int $after_id, int $limit ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, product_id, before_state, after_state FROM ' . self::table() . '
             WHERE job_id = %s AND id > %d AND state = %d ORDER BY id ASC LIMIT %d',
            $job_id, $after_id, self::ROW_APPLIED, $limit
        ), ARRAY_A );
        return array_map( static fn( $r ) => [
            'id'         => (int) $r['id'],
            'product_id' => (int) $r['product_id'],
            'before'     => (array) json_decode( $r['before_state'], true ),
            'after'      => (array) json_decode( $r['after_state'], true ),
        ], $rows ?: [] );
    }

    public static function mark( int $row_id, int $state ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'state' => $state ], [ 'id' => $row_id ], [ '%d' ], [ '%d' ] );
    }

    /** A few changed products for the Recent Changes detail view. */
    public static function sample( string $job_id, int $limit = 20 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT product_id, before_state, after_state, state FROM ' . self::table() . '
             WHERE job_id = %s ORDER BY id ASC LIMIT %d',
            $job_id, $limit
        ), ARRAY_A );
        return $rows ?: [];
    }
}
