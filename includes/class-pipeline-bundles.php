<?php
/**
 * MMI_Pipeline_Bundles — what a bundle is and what it contains, for every
 * plugin that asks.
 *
 * A bundle is a product sold as a set of other products. WooCommerce has no
 * such idea beyond grouped products, so plugins used to answer "is it a
 * bundle?" each their own way. This is the one answer:
 *
 *   is_bundle( $id )  a grouped product, or one with confirmed members, unless
 *                     a store's own rules (filter 'mmi_pipeline_is_bundle')
 *                     say otherwise — e.g. a store that files bundles under a
 *                     category, with exceptions per brand.
 *
 * Members live in {prefix}mmpt_bundle_members (one row per bundle and member:
 * a product id or just a name, a status — proposed, confirmed, rejected — and
 * who decided). Only confirmed members are used anywhere a shopper sees them;
 * proposed ones are a review list. Finding members (title, catalog and page
 * evidence) is a store's own work; this class only stores and reads them.
 *
 * Loaded outside the license gate: product pages read bundle contents, and a
 * lapsed license must not blank them.
 *
 * Product Data migration phase 4 (2026-11-09). Table names are the ones the
 * data was first written under, so nothing is copied or renamed.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MMI_Pipeline_Bundles {

    const DB_OPTION  = 'mmi_pipeline_bundles_db';
    const DB_VERSION = '1';
    const STATUSES   = [ 'confirmed', 'proposed', 'rejected' ];

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mmpt_bundles';
    }

    public static function members_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mmpt_bundle_members';
    }

    /** Create the two tables where they don't exist yet (a store new to bundles). */
    public static function install(): void {
        if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta( 'CREATE TABLE ' . self::table() . " (
            bundle_id bigint(20) unsigned NOT NULL,
            kind varchar(1) NOT NULL DEFAULT '',
            kind_by varchar(10) NOT NULL DEFAULT 'auto',
            complete varchar(10) NOT NULL DEFAULT 'unknown',
            pictures varchar(10) NOT NULL DEFAULT '',
            members_sold int unsigned NOT NULL DEFAULT 0,
            expected int unsigned NOT NULL DEFAULT 0,
            sources varchar(190) NOT NULL DEFAULT '',
            note varchar(255) NOT NULL DEFAULT '',
            computed_at datetime NULL,
            PRIMARY KEY  (bundle_id),
            KEY kind (kind)
        ) {$c};" );
        dbDelta( 'CREATE TABLE ' . self::members_table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bundle_id bigint(20) unsigned NOT NULL,
            member_key varchar(40) NOT NULL,
            member_id bigint(20) unsigned NOT NULL DEFAULT 0,
            member_name varchar(190) NOT NULL DEFAULT '',
            position int unsigned NOT NULL DEFAULT 0,
            sources varchar(190) NOT NULL DEFAULT '',
            evidence text NULL,
            confidence decimal(4,2) NOT NULL DEFAULT 0,
            nested tinyint(1) NOT NULL DEFAULT 0,
            status varchar(10) NOT NULL DEFAULT 'proposed',
            by_owner tinyint(1) NOT NULL DEFAULT 0,
            decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
            decided_at datetime NULL,
            found_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY member (bundle_id, member_key),
            KEY member_id (member_id)
        ) {$c};" );
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::members_table() ) ) === self::members_table() ) {
            update_option( self::DB_OPTION, self::DB_VERSION, false );
        }
    }

    private static function ready(): bool {
        static $ok = null;
        if ( $ok === null ) {
            self::install();
            global $wpdb;
            $ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::members_table() ) ) === self::members_table();
        }
        return $ok;
    }

    /** The one answer to "is this product a bundle?". */
    public static function is_bundle( int $id ): bool {
        if ( $id <= 0 || get_post_type( $id ) !== 'product' ) {
            return false;
        }
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
        $default = ( $product && $product->is_type( 'grouped' ) ) || self::confirmed_ids( $id );
        return (bool) apply_filters( 'mmi_pipeline_is_bundle', $default, $id );
    }

    /**
     * Which of these products are bundles: is_bundle()'s answer for many at once. A store whose rule needs a query per
     * product answers the whole list through the filter 'mmi_pipeline_bundle_ids' (an array of ids → true), else each
     * product is asked on its own.
     *
     * @param int[] $ids
     * @return array<int,true>
     */
    public static function which( array $ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( ! $ids ) {
            return [];
        }
        $bulk = apply_filters( 'mmi_pipeline_bundle_ids', null, $ids );
        if ( is_array( $bulk ) ) {
            return array_intersect_key( array_map( static fn() => true, $bulk ), array_flip( $ids ) );
        }
        $out = [];
        foreach ( $ids as $id ) {
            if ( self::is_bundle( $id ) ) {
                $out[ $id ] = true;
            }
        }
        return $out;
    }

    /**
     * A bundle's member rows, decided ones first, in their order.
     *
     * @param string $status '' for all, or one of STATUSES.
     * @return array<int,array<string,mixed>>
     */
    public static function members( int $bundle_id, string $status = '' ): array {
        if ( $bundle_id <= 0 || ! self::ready() ) {
            return [];
        }
        global $wpdb;
        $where = $status !== '' && in_array( $status, self::STATUSES, true ) ? $wpdb->prepare( ' AND status = %s', $status ) : '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and $where built above.
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::members_table() . " WHERE bundle_id = %d{$where} ORDER BY FIELD( status, 'confirmed', 'proposed', 'rejected' ), position", $bundle_id ), ARRAY_A ) ?: [];
    }

    /** Product ids of a bundle's confirmed members (members that are products in this store). @return int[] */
    public static function confirmed_ids( int $bundle_id ): array {
        return array_values( array_filter( array_map( static fn( $r ) => (int) $r['member_id'], self::members( $bundle_id, 'confirmed' ) ) ) );
    }

    /** Bundles a product is a confirmed member of. @return int[] */
    public static function bundles_of( int $member_id ): array {
        if ( $member_id <= 0 || ! self::ready() ) {
            return [];
        }
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT bundle_id FROM ' . self::members_table() . " WHERE member_id = %d AND status = 'confirmed' ORDER BY bundle_id", $member_id ) ) );
    }
}
