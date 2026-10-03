<?php
/**
 * Product Workbench
 *
 * Find products with the Custom Rule condition engine (supplier feed fields,
 * taxonomies, post fields/meta — any post status, optional Match case) and
 * apply any one-off Custom Rule action to the results: Replace/Add/Remove a
 * taxonomy term, change status, price, backorders and so on.
 *
 * Matching is Stock_Override_Resolver's (find_product_ids()) and writing is
 * Custom_Rule_Actions::apply(), so a Workbench search matches exactly what a
 * Custom Rule with the same conditions would, and an apply writes exactly
 * what that rule's action would. What this class adds is the per-action
 * snapshot (state()) that makes preview, the change log and Undo possible.
 *
 * @package MannMade\DataPipeline\Workbench
 */

namespace MannMade\DataPipeline\Workbench;

use MannMade\DataPipeline\Custom_Rule_Actions;
use MannMade\DataPipeline\Stock_Override_Resolver;
use MMI_Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Product_Workbench {

    const PER_PAGE_OPTIONS = [ 25, 50, 100, 200 ];
    const BATCH_SIZE       = 50;
    const UNDO_BATCH_SIZE  = 100;
    const PREVIEW_SAMPLE   = 25;
    const MAX_SELECTION    = 20000;
    const SEARCH_CACHE_TTL = 15 * MINUTE_IN_SECONDS;
    const FACET_LIMIT      = 40;

    /** Every sortable results column (whitelist for the request's `sort`). */
    const SORTS = [ 'title', 'sku', 'categories', 'brand', 'status', 'stock', 'supplier', 'health', 'id', 'modified' ];

    const QUICK_SEARCH_IN = [ 'title_sku', 'title', 'sku' ];

    const STOCK_STATUSES = [ 'instock', 'outofstock', 'onbackorder' ];

    /** Health filter modes: fails any / every chosen check, or passes them all. */
    const HEALTH_MODES = [ 'any', 'all', 'none' ];

    /* ── Input ─────────────────────────────────────────────────────────── */

    /**
     * Normalize a search filter from request input.
     *
     * @return array{supplier:string,match_logic:string,conditions:array,statuses:array,stock:array,health:array,health_mode:string,q:string,q_case:bool,q_in:string}
     */
    public static function sanitize_filter( array $raw ): array {
        $conditions = [];
        foreach ( (array) ( $raw['conditions'] ?? [] ) as $cond ) {
            $clean = Stock_Override_Resolver::sanitize_condition( $cond );
            if ( $clean !== null ) {
                $conditions[] = $clean;
            }
        }
        $statuses = array_values( array_intersect(
            Stock_Override_Resolver::SEARCHABLE_STATUSES,
            array_map( 'sanitize_key', (array) ( $raw['statuses'] ?? [ 'publish' ] ) )
        ) );
        $q_in        = sanitize_key( $raw['q_in'] ?? 'title_sku' );
        $health_mode = sanitize_key( $raw['health_mode'] ?? 'any' );
        return [
            'supplier'    => sanitize_text_field( $raw['supplier'] ?? 'all' ) ?: 'all',
            'match_logic' => ( $raw['match_logic'] ?? 'all' ) === 'any' ? 'any' : 'all',
            'conditions'  => $conditions,
            'statuses'    => $statuses ?: [ 'publish' ],
            // Empty = no stock / health filter.
            'stock'       => array_values( array_intersect( self::STOCK_STATUSES, array_map( 'sanitize_key', (array) ( $raw['stock'] ?? [] ) ) ) ),
            'health'      => array_values( array_intersect( array_keys( Health_Checks::all() ), array_map( 'sanitize_key', (array) ( $raw['health'] ?? [] ) ) ) ),
            'health_mode' => in_array( $health_mode, self::HEALTH_MODES, true ) ? $health_mode : 'any',
            'q'           => trim( sanitize_text_field( $raw['q'] ?? '' ) ),
            'q_case'      => ! empty( $raw['q_case'] ) && $raw['q_case'] !== '0',
            'q_in'        => in_array( $q_in, self::QUICK_SEARCH_IN, true ) ? $q_in : 'title_sku',
        ];
    }

    /* ── Search ────────────────────────────────────────────────────────── */

    /**
     * Every product ID matching the filter: the rule-engine conditions AND
     * the quick search AND the stock / health filters, within the chosen
     * statuses and supplier scope.
     *
     * @return int[]
     */
    public static function find_ids( array $filter ): array {
        $ids = Stock_Override_Resolver::find_product_ids( [
            'supplier'    => $filter['supplier'],
            'match_logic' => $filter['match_logic'],
            'conditions'  => $filter['conditions'],
        ], $filter['statuses'] );

        if ( ! empty( $filter['stock'] ) && $ids ) {
            $stock_ids = Stock_Override_Resolver::find_product_ids( [
                'supplier'    => 'all',
                'match_logic' => 'all',
                'conditions'  => [ [ 'source' => Stock_Override_Resolver::POSTMETA_SOURCE, 'field' => '_stock_status', 'operator' => 'in_list', 'value' => implode( '|', $filter['stock'] ) ] ],
            ], $filter['statuses'] );
            $ids = array_values( array_intersect( $ids, $stock_ids ) );
        }

        if ( ! empty( $filter['health'] ) && $ids ) {
            $ids = self::filter_by_health( $ids, $filter['health'], $filter['health_mode'] ?? 'any', $filter['statuses'] );
        }

        if ( $filter['q'] === '' ) {
            return $ids;
        }

        $quick = [];
        $base  = [ 'source' => Stock_Override_Resolver::POSTMETA_SOURCE, 'operator' => 'contains', 'value' => $filter['q'] ];
        if ( $filter['q_case'] ) {
            $base['case_sensitive'] = true;
        }
        if ( $filter['q_in'] !== 'sku' ) {
            $quick[] = $base + [ 'field' => '__post_title' ];
        }
        if ( $filter['q_in'] !== 'title' ) {
            $quick[] = $base + [ 'field' => '_sku' ];
        }
        $quick_ids = Stock_Override_Resolver::find_product_ids( [
            'supplier'    => 'all',
            'match_logic' => 'any',
            'conditions'  => $quick,
        ], $filter['statuses'] );

        return array_values( array_intersect( $ids, $quick_ids ) );
    }

    /**
     * Narrow $ids by data-health checks: 'any' keeps products failing at
     * least one chosen check, 'all' those failing every one, 'none' those
     * passing them all. One catalog match per check, not per product.
     *
     * @param  int[]    $ids
     * @param  string[] $checks
     * @param  string[] $statuses
     * @return int[]
     */
    private static function filter_by_health( array $ids, array $checks, string $mode, array $statuses ): array {
        $fails = [];
        foreach ( $checks as $check_id ) {
            foreach ( Health_Checks::matching_ids( $check_id, $statuses ) as $id ) {
                $fails[ $id ] = ( $fails[ $id ] ?? 0 ) + 1;
            }
        }
        $need = count( $checks );
        return array_values( array_filter( $ids, static function ( $id ) use ( $fails, $mode, $need ) {
            $n = $fails[ $id ] ?? 0;
            return $mode === 'all' ? $n === $need : ( $mode === 'none' ? $n === 0 : $n > 0 );
        } ) );
    }

    /**
     * One page of results, plus the total and category counts across the
     * whole result set. The sorted ID list is cached per user and filter so
     * paging doesn't re-run the match; Search (fresh = true) always does.
     */
    public static function search( array $filter, int $page, int $per_page, string $sort, string $dir, bool $fresh ): array {
        $sort     = in_array( $sort, self::SORTS, true ) ? $sort : 'title';
        $dir      = $dir === 'desc' ? 'DESC' : 'ASC';
        $per_page = in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ? $per_page : 50;

        $cache_key = self::cache_key( [ $filter, $sort, $dir ] );
        $cached    = $fresh ? false : get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            $ids    = $cached['ids'];
            $facets = $cached['facets'];
        } else {
            $ids    = $sort === 'health'
                ? self::sort_ids_by_health( self::find_ids( $filter ), $filter['statuses'], $dir )
                : self::sort_ids( self::find_ids( $filter ), $sort, $dir );
            $facets = self::category_facets( $ids );
            set_transient( $cache_key, [ 'ids' => $ids, 'facets' => $facets ], self::SEARCH_CACHE_TTL );
        }

        $total = count( $ids );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        $page  = min( max( 1, $page ), $pages );

        return [
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $per_page,
            'rows'     => self::rows( array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ) ),
            'facets'   => $facets,
            'checks'   => array_map(
                static fn( $c ) => [ 'chip' => $c['chip'], 'label' => $c['label'] ],
                Health_Checks::chip_checks()
            ),
        ];
    }

    /**
     * Search cache key, scoped to the user and to a generation counter that
     * every apply/undo bumps — so results never show pre-change data.
     */
    private static function cache_key( array $parts ): string {
        $generation = (int) get_transient( 'mmi_wb_generation' );
        return 'mmi_wb_s_' . md5( wp_json_encode( $parts ) . '|' . get_current_user_id() . '|' . $generation );
    }

    public static function invalidate_search_cache(): void {
        set_transient( 'mmi_wb_generation', (int) get_transient( 'mmi_wb_generation' ) + 1, DAY_IN_SECONDS );
    }

    /**
     * SQL for one sort column, evaluated per product row `p`. Each matches
     * what that column displays: Categories/Brand sort by the product's
     * alphabetically first term, Supplier by the same first-configured
     * supplier Stock_Override_Resolver::product_supplier() reports.
     */
    private static function sort_expression( string $sort ): string {
        global $wpdb;
        $first_term = static fn( string $taxonomy ): string => "(SELECT MIN(t.name) FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = '" . esc_sql( $taxonomy ) . "'
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tr.object_id = p.ID)";
        switch ( $sort ) {
            case 'id':         return 'p.ID';
            case 'status':     return 'p.post_status';
            case 'modified':   return 'p.post_modified';
            case 'sku':        return "(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_sku' LIMIT 1)";
            case 'stock':      return "(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_stock_status' LIMIT 1)";
            case 'categories': return $first_term( 'product_cat' );
            case 'brand':      return taxonomy_exists( 'product_brand' ) ? $first_term( 'product_brand' ) : "''";
            case 'supplier':
                $case = [];
                foreach ( Stock_Override_Resolver::supplier_ids() as $sid ) {
                    $key    = esc_sql( '_mmi_supplier_sku_' . $sid );
                    $case[] = "WHEN EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '{$key}' AND meta_value != '') THEN '" . esc_sql( $sid ) . "'";
                }
                return $case ? 'CASE ' . implode( ' ', $case ) . " ELSE '' END" : "''";
            default:           return 'p.post_title';
        }
    }

    /**
     * $ids by how many health checks each fails — most problems first when
     * descending — then by ID, so the order is stable between pages.
     *
     * @return int[]
     */
    private static function sort_ids_by_health( array $ids, array $statuses, string $dir ): array {
        $counts = Health_Checks::failure_counts( $ids, $statuses );
        $sign   = $dir === 'DESC' ? -1 : 1;
        uksort( $counts, static fn( $a, $b ) => ( ( $counts[ $a ] <=> $counts[ $b ] ) ?: ( $a <=> $b ) ) * $sign );
        return array_keys( $counts );
    }

    /** @return int[] $ids in the requested order, empty values last either way. */
    private static function sort_ids( array $ids, string $sort, string $dir ): array {
        if ( empty( $ids ) ) {
            return [];
        }
        global $wpdb;
        $expr = self::sort_expression( $sort );
        $in   = implode( ',', array_map( 'intval', $ids ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints + whitelisted expressions.
        $sorted = $wpdb->get_col(
            "SELECT p.ID FROM (SELECT p.ID, {$expr} AS sort_value FROM {$wpdb->posts} p WHERE p.ID IN ({$in})) p
             ORDER BY (p.sort_value IS NULL OR p.sort_value = '') ASC, p.sort_value {$dir}, p.ID {$dir}"
        );
        // phpcs:enable
        return array_map( 'intval', $sorted );
    }

    /**
     * How the matched products are currently categorized — the fastest way
     * to spot a wrong category across a result set. "(none)" counts products
     * with no product_cat term at all.
     *
     * @return list<array{id:int,name:string,count:int}>
     */
    public static function category_facets( array $ids ): array {
        if ( empty( $ids ) || ! taxonomy_exists( 'product_cat' ) ) {
            return [];
        }
        global $wpdb;
        $in = implode( ',', array_map( 'intval', $ids ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ints only.
        $rows = $wpdb->get_results(
            "SELECT tt.term_id, COUNT(DISTINCT tr.object_id) AS n
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
             WHERE tr.object_id IN ({$in})
             GROUP BY tt.term_id ORDER BY n DESC LIMIT " . (int) self::FACET_LIMIT,
            ARRAY_A
        );
        $with_any = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT tr.object_id)
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
             WHERE tr.object_id IN ({$in})"
        );
        // phpcs:enable
        $out = [];
        foreach ( $rows ?: [] as $r ) {
            $term = get_term( (int) $r['term_id'], 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $out[] = [ 'id' => (int) $term->term_id, 'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ), 'count' => (int) $r['n'] ];
            }
        }
        $none = count( $ids ) - $with_any;
        if ( $none > 0 ) {
            $out[] = [ 'id' => 0, 'name' => '(none)', 'count' => $none ];
        }
        return $out;
    }

    /**
     * Table rows for a page of IDs, with caches primed in one pass each.
     *
     * @return list<array>
     */
    public static function rows( array $ids ): array {
        if ( empty( $ids ) ) {
            return [];
        }
        _prime_post_caches( $ids, false, true );
        update_object_term_cache( $ids, 'product' );

        // Featured images, primed in one pass for the thumbnail column.
        $thumbs = [];
        foreach ( $ids as $id ) {
            $thumbs[ $id ] = (int) get_post_meta( $id, '_thumbnail_id', true );
        }
        _prime_post_caches( array_values( array_filter( $thumbs ) ), false, true );

        $failures  = Health_Checks::failures( $ids );
        $brand_tax = taxonomy_exists( 'product_brand' ) ? 'product_brand' : '';
        $locks     = class_exists( 'MMI_Pipeline_Field_Locks' );
        $rows      = [];
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) {
                continue;
            }
            $rows[] = [
                'id'         => $id,
                'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
                'status'     => $post->post_status,
                'sku'        => (string) get_post_meta( $id, '_sku', true ),
                'stock'      => (string) get_post_meta( $id, '_stock_status', true ),
                'price'      => (string) get_post_meta( $id, '_price', true ),
                'categories' => self::term_list( $id, 'product_cat' ),
                'brands'     => $brand_tax ? self::term_list( $id, $brand_tax ) : [],
                'supplier'   => Stock_Override_Resolver::product_supplier( $id ),
                'health'     => $failures[ $id ] ?? [],
                // Which editable taxonomies imports may not overwrite.
                'locked'     => $locks ? array_values( array_intersect( self::EDITABLE_TAXONOMIES, \MMI_Pipeline_Field_Locks::get( $id ) ) ) : [],
                'thumb'      => $thumbs[ $id ] ? (string) wp_get_attachment_image_url( $thumbs[ $id ], 'thumbnail' ) : '',
                'edit_url'   => get_edit_post_link( $id, 'raw' ),
                'view_url'   => get_permalink( $id ),
            ];
        }
        return $rows;
    }

    /** @return list<array{id:int,name:string}> */
    private static function term_list( int $id, string $taxonomy ): array {
        $terms = get_the_terms( $id, $taxonomy );
        if ( ! is_array( $terms ) ) {
            return [];
        }
        return array_map( static fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) ], $terms );
    }

    /* ── Selection ─────────────────────────────────────────────────────── */

    /**
     * Resolve what the user selected to product IDs: either explicit IDs, or
     * "every product matching this filter" minus any unticked ones —
     * re-matched now, so the set is current when the job starts.
     *
     * @return int[]|\WP_Error
     */
    public static function resolve_selection( array $raw ) {
        if ( ( $raw['mode'] ?? '' ) === 'filter' ) {
            $filter  = self::sanitize_filter( (array) ( $raw['filter'] ?? [] ) );
            $exclude = array_flip( array_map( 'absint', (array) ( $raw['exclude'] ?? [] ) ) );
            $ids     = array_values( array_filter( self::find_ids( $filter ), static fn( $id ) => ! isset( $exclude[ $id ] ) ) );
        } else {
            $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw['ids'] ?? [] ) ) ) ) );
        }
        if ( empty( $ids ) ) {
            return new \WP_Error( 'empty', 'No products selected.' );
        }
        if ( count( $ids ) > self::MAX_SELECTION ) {
            return new \WP_Error( 'too_many', sprintf( 'That is %s products; the limit per run is %s. Narrow the search first.', number_format( count( $ids ) ), number_format( self::MAX_SELECTION ) ) );
        }
        return $ids;
    }

    /**
     * Validate a one-off action + its settings.
     *
     * @return array{action:string,params:array}|\WP_Error
     */
    public static function sanitize_action( string $action, array $raw_params ) {
        if ( ! Custom_Rule_Actions::is_valid( $action ) ) {
            return new \WP_Error( 'action', 'Pick what to change.' );
        }
        if ( Custom_Rule_Actions::is_stock_action( $action ) ) {
            return new \WP_Error( 'action', 'Force In/Out of Stock only holds as a Custom Rule (Catalog Maintenance); a one-off change would be undone by the next feed sync.' );
        }
        $params = Custom_Rule_Actions::needs_params( $action ) ? Custom_Rule_Actions::sanitize_params( $action, $raw_params ) : [];
        if ( Custom_Rule_Actions::needs_params( $action ) && empty( $params ) ) {
            return new \WP_Error( 'params', sprintf( '"%s" needs its settings filled in.', Custom_Rule_Actions::label_for( $action ) ) );
        }
        return [ 'action' => $action, 'params' => $params ];
    }

    /** Human summary of an action, e.g. "Product categories → Bundles (replace)". */
    public static function action_summary( string $action, array $params ): string {
        $label = Custom_Rule_Actions::label_for( $action );
        if ( $action === 'set_taxonomy_term' ) {
            $tax  = get_taxonomy( $params['taxonomy'] );
            $term = get_term( (int) $params['term_id'] );
            $verb = [ 'replace' => 'Replace with', 'add' => 'Add', 'remove' => 'Remove' ][ $params['mode'] ] ?? 'Set';
            return sprintf( '%s: %s "%s"', $tax ? $tax->labels->name : $params['taxonomy'], $verb, ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $params['term_id'] );
        }
        if ( isset( $params['value'] ) && in_array( $action, [ 'set_regular_price', 'set_sale_price' ], true ) ) {
            return $label . ' ' . $params['value'];
        }
        if ( $action === 'set_sale_dates' ) {
            return sprintf( '%s %s → %s', $label, $params['date_from'] ?: '…', $params['date_to'] ?: '…' );
        }
        if ( $action === 'copy_source_field_to_meta' ) {
            return sprintf( 'Copy feed "%s" to meta "%s"', $params['source_field'], $params['meta_key'] );
        }
        return $label;
    }

    /* ── Snapshots — what an action reads and writes on one product ─────── */

    /**
     * The part of a product an action touches, in a comparable, restorable
     * shape. Undo writes `before` back only while the product still equals
     * `after`, so an edit made since the job ran is never clobbered.
     */
    public static function state( int $id, string $action, array $params ): array {
        switch ( $action ) {
            case 'set_taxonomy_term':
                $terms = wp_get_object_terms( $id, $params['taxonomy'], [ 'fields' => 'ids' ] );
                $terms = is_wp_error( $terms ) ? [] : array_map( 'intval', $terms );
                sort( $terms );
                return [ 'terms' => $terms ];

            case 'set_status_publish':
            case 'set_status_draft':
            case 'set_status_pending':
                return [ 'status' => (string) get_post_status( $id ) ];

            case 'set_regular_price':
            case 'set_sale_price':
            case 'clear_sale_price':
                return [
                    'regular' => (string) get_post_meta( $id, '_regular_price', true ),
                    'sale'    => (string) get_post_meta( $id, '_sale_price', true ),
                ];

            case 'set_sale_dates':
                // Y-m-d, the same form Custom_Rule_Actions::apply_sale_dates()
                // compares and writes.
                $product = wc_get_product( $id );
                $from    = $product ? $product->get_date_on_sale_from( 'edit' ) : null;
                $to      = $product ? $product->get_date_on_sale_to( 'edit' ) : null;
                return [ 'from' => $from ? $from->date( 'Y-m-d' ) : '', 'to' => $to ? $to->date( 'Y-m-d' ) : '' ];

            case 'set_stock_status_backorder':
                return [ 'stock_status' => (string) get_post_meta( $id, '_stock_status', true ) ];

            case 'set_backorders_yes':
            case 'set_backorders_notify':
            case 'set_backorders_no':
                return [ 'backorders' => (string) get_post_meta( $id, '_backorders', true ) ];

            case 'enable_manage_stock':
            case 'disable_manage_stock':
                return [ 'manage_stock' => (string) get_post_meta( $id, '_manage_stock', true ) ];

            case 'copy_source_field_to_meta':
                $key = $params['meta_key'];
                return [
                    'exists' => metadata_exists( 'post', $id, $key ),
                    'value'  => (string) get_post_meta( $id, $key, true ),
                ];
        }
        return [];
    }

    /**
     * What state() would be after the action, without writing — for the
     * preview. Mirrors Custom_Rule_Actions::apply()'s own logic per action.
     */
    public static function predict( int $id, array $before, string $action, array $params ): array {
        switch ( $action ) {
            case 'set_taxonomy_term':
                $term  = (int) $params['term_id'];
                $terms = $before['terms'];
                if ( $params['mode'] === 'remove' ) {
                    $terms = array_values( array_diff( $terms, [ $term ] ) );
                } elseif ( $params['mode'] === 'add' ) {
                    $terms = array_values( array_unique( array_merge( $terms, [ $term ] ) ) );
                } else {
                    $terms = [ $term ];
                }
                sort( $terms );
                return [ 'terms' => $terms ];

            case 'set_status_publish':
            case 'set_status_draft':
            case 'set_status_pending':
                return [ 'status' => substr( $action, strlen( 'set_status_' ) ) ];

            case 'set_regular_price':
                return [ 'regular' => (string) $params['value'], 'sale' => $before['sale'] ];
            case 'set_sale_price':
                return [ 'regular' => $before['regular'], 'sale' => (string) $params['value'] ];
            case 'clear_sale_price':
                return [ 'regular' => $before['regular'], 'sale' => '' ];

            case 'set_sale_dates':
                return [ 'from' => $params['date_from'], 'to' => $params['date_to'] ];

            case 'set_stock_status_backorder':
                return [ 'stock_status' => 'onbackorder' ];

            case 'set_backorders_yes':
            case 'set_backorders_notify':
            case 'set_backorders_no':
                return [ 'backorders' => substr( $action, strlen( 'set_backorders_' ) ) ];

            case 'enable_manage_stock':
                return [ 'manage_stock' => 'yes' ];
            case 'disable_manage_stock':
                return [ 'manage_stock' => 'no' ];

            case 'copy_source_field_to_meta':
                $raw = Stock_Override_Resolver::raw_feed_item( $id )[ $params['source_field'] ] ?? null;
                if ( $raw === null || is_array( $raw ) ) {
                    return $before; // Nothing to copy; apply() leaves it alone too.
                }
                return [ 'exists' => true, 'value' => (string) $raw ];
        }
        return $before;
    }

    /** Short display text for a state, e.g. "Plugins, Software Bundles". */
    public static function describe( array $state, string $action ): string {
        if ( isset( $state['terms'] ) ) {
            if ( empty( $state['terms'] ) ) {
                return '—';
            }
            $names = [];
            foreach ( $state['terms'] as $tid ) {
                $t       = get_term( (int) $tid );
                $names[] = ( $t && ! is_wp_error( $t ) ) ? html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) : '#' . $tid;
            }
            return implode( ', ', $names );
        }
        if ( isset( $state['regular'] ) ) {
            return 'Regular ' . ( $state['regular'] !== '' ? $state['regular'] : '—' ) . ' · Sale ' . ( $state['sale'] !== '' ? $state['sale'] : '—' );
        }
        if ( array_key_exists( 'from', $state ) ) {
            return ( $state['from'] ?: '…' ) . ' → ' . ( $state['to'] ?: '…' );
        }
        if ( array_key_exists( 'exists', $state ) ) {
            return $state['exists'] ? ( $state['value'] !== '' ? $state['value'] : '(empty)' ) : '(not set)';
        }
        $value = (string) reset( $state );
        return $value !== '' ? $value : '—';
    }

    /* ── Preview ───────────────────────────────────────────────────────── */

    /**
     * How many of $ids the action would change, with before → after for the
     * first PREVIEW_SAMPLE that would.
     */
    public static function preview( array $ids, string $action, array $params ): array {
        $will_change = 0;
        $sample      = [];
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            update_object_term_cache( $chunk, 'product' );
            update_meta_cache( 'post', $chunk );
            foreach ( $chunk as $id ) {
                $before = self::state( $id, $action, $params );
                $after  = self::predict( $id, $before, $action, $params );
                if ( $after == $before ) { // phpcs:ignore Universal.Operators.StrictComparisons -- array value equality.
                    continue;
                }
                $will_change++;
                if ( count( $sample ) < self::PREVIEW_SAMPLE ) {
                    $sample[] = [
                        'id'     => $id,
                        'title'  => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
                        'before' => self::describe( $before, $action ),
                        'after'  => self::describe( $after, $action ),
                    ];
                }
            }
            wp_cache_flush_runtime();
        }
        return [
            'selected'    => count( $ids ),
            'will_change' => $will_change,
            'unchanged'   => count( $ids ) - $will_change,
            'summary'     => self::action_summary( $action, $params ),
            'sample'      => $sample,
        ];
    }

    /* ── Apply (job) ───────────────────────────────────────────────────── */

    public static function start_job( array $ids, string $action, array $params, string $filter_label ): array {
        Workbench_Change_Log::ensure_table();
        $job = [
            'id'           => wp_generate_uuid4(),
            'created_at'   => current_time( 'mysql' ),
            'user'         => wp_get_current_user()->display_name,
            'action'       => $action,
            'params'       => $params,
            'summary'      => self::action_summary( $action, $params ),
            'filter_label' => mb_substr( $filter_label, 0, 200 ),
            'total'        => count( $ids ),
            'cursor'       => 0,
            'changed'      => 0,
            'unchanged'    => 0,
            'failed'       => 0,
            'status'       => 'running',
            'undo_cursor'  => 0,
            'undone'       => 0,
            'conflicts'    => 0,
        ];
        Workbench_Change_Log::save_job_ids( $job['id'], $ids );
        Workbench_Change_Log::save_job( $job );
        MMI_Logger::info( "Workbench job {$job['id']} started: {$job['summary']} on {$job['total']} product(s)", [ 'filter' => $job['filter_label'] ], 'sync', 'Product_Workbench' );
        if ( function_exists( 'mmi_data_pipeline_audit' ) ) {
            mmi_data_pipeline_audit( 'workbench.apply', [
                'object_type' => 'workbench_job',
                'object_id'   => $job['id'],
                'outcome'     => 'success',
                'details'     => [ 'action' => $action, 'summary' => $job['summary'], 'filter' => $job['filter_label'], 'products' => $job['total'] ],
            ] );
        }
        return $job;
    }

    /**
     * Process the job's next batch. One request per batch (the client loops),
     * so no single request runs long; a job left half-done can be resumed.
     */
    public static function run_batch( string $job_id, bool $resume = false ): array {
        $job = Workbench_Change_Log::job( $job_id );
        if ( ! $job ) {
            return [ 'error' => 'Unknown job.' ];
        }
        if ( $resume && $job['status'] === 'stopped' && $job['cursor'] < $job['total'] ) {
            $job['status'] = 'running';
            MMI_Logger::info( "Workbench job {$job_id} resumed at {$job['cursor']} of {$job['total']}", [], 'sync', 'Product_Workbench' );
        }
        if ( $job['status'] !== 'running' ) {
            return [ 'job' => $job ];
        }
        $lock = 'mmi_wb_lock_' . $job_id;
        if ( get_transient( $lock ) ) {
            return [ 'job' => $job, 'busy' => true ];
        }
        set_transient( $lock, 1, MINUTE_IN_SECONDS );

        try {
            $ids   = array_slice( Workbench_Change_Log::job_ids( $job_id ), (int) $job['cursor'], self::BATCH_SIZE );
            $rows  = [];
            update_object_term_cache( $ids, 'product' );
            update_meta_cache( 'post', $ids );
            foreach ( $ids as $id ) {
                try {
                    $before = self::state( $id, $job['action'], $job['params'] );
                    $raw    = $job['action'] === 'copy_source_field_to_meta' ? Stock_Override_Resolver::raw_feed_item( $id ) : [];
                    Custom_Rule_Actions::apply( $id, $job['action'], $job['params'], $raw );
                    clean_post_cache( $id );
                    wp_cache_delete( $id, 'post_meta' );
                    $after = self::state( $id, $job['action'], $job['params'] );
                    if ( $after == $before ) { // phpcs:ignore Universal.Operators.StrictComparisons -- array value equality.
                        $job['unchanged']++;
                    } else {
                        $job['changed']++;
                        $rows[] = [ 'product_id' => $id, 'before' => $before, 'after' => $after ];
                    }
                } catch ( \Throwable $e ) {
                    $job['failed']++;
                    MMI_Logger::error( "Workbench job {$job_id}: product {$id} failed: " . $e->getMessage(), [], 'sync', 'Product_Workbench' );
                }
            }
            Workbench_Change_Log::record( $job_id, $rows );
            $job['cursor'] += count( $ids );
            if ( $job['cursor'] >= $job['total'] || empty( $ids ) ) {
                $job['status'] = 'done';
                MMI_Logger::info( "Workbench job {$job_id} done: {$job['changed']} changed, {$job['unchanged']} unchanged, {$job['failed']} failed", [], 'sync', 'Product_Workbench' );
            }
            Workbench_Change_Log::save_job( $job );
            self::invalidate_search_cache();
            wp_cache_flush_runtime();
        } finally {
            delete_transient( $lock );
        }
        return [ 'job' => $job ];
    }

    /** Stop a running job where it is (already-changed products stay changed and can be undone). */
    public static function stop_job( string $job_id ): ?array {
        $job = Workbench_Change_Log::job( $job_id );
        if ( $job && $job['status'] === 'running' ) {
            $job['status'] = 'stopped';
            Workbench_Change_Log::save_job( $job );
        }
        return $job;
    }

    /* ── Undo ──────────────────────────────────────────────────────────── */

    public static function undo_batch( string $job_id ): array {
        $job = Workbench_Change_Log::job( $job_id );
        if ( ! $job ) {
            return [ 'error' => 'Unknown job.' ];
        }
        if ( $job['status'] === 'running' ) {
            return [ 'error' => 'Stop or finish this run before undoing it.' ];
        }
        if ( $job['status'] === 'undone' ) {
            return [ 'job' => $job ];
        }
        $lock = 'mmi_wb_lock_' . $job_id;
        if ( get_transient( $lock ) ) {
            return [ 'job' => $job, 'busy' => true ];
        }
        set_transient( $lock, 1, MINUTE_IN_SECONDS );

        try {
            if ( $job['status'] !== 'undoing' ) {
                $job['status'] = 'undoing';
                MMI_Logger::info( "Workbench job {$job_id} undo started", [], 'sync', 'Product_Workbench' );
                if ( function_exists( 'mmi_data_pipeline_audit' ) ) {
                    mmi_data_pipeline_audit( 'workbench.undo', [
                        'object_type' => 'workbench_job',
                        'object_id'   => $job_id,
                        'outcome'     => 'success',
                        'details'     => [ 'action' => $job['action'], 'summary' => $job['summary'] ?? '' ],
                    ] );
                }
            }
            $rows = Workbench_Change_Log::rows_to_undo( $job_id, (int) $job['undo_cursor'], self::UNDO_BATCH_SIZE );
            foreach ( $rows as $row ) {
                $current = self::state( $row['product_id'], $job['action'], $job['params'] );
                if ( $current == $row['after'] ) { // phpcs:ignore Universal.Operators.StrictComparisons -- array value equality.
                    self::restore( $row['product_id'], $job['action'], $job['params'], $row['before'] );
                    Workbench_Change_Log::mark( $row['id'], Workbench_Change_Log::ROW_UNDONE );
                    $job['undone']++;
                } else {
                    Workbench_Change_Log::mark( $row['id'], Workbench_Change_Log::ROW_CONFLICT );
                    $job['conflicts']++;
                }
                $job['undo_cursor'] = $row['id'];
            }
            if ( count( $rows ) < self::UNDO_BATCH_SIZE ) {
                $job['status'] = 'undone';
                MMI_Logger::info( "Workbench job {$job_id} undone: {$job['undone']} restored, {$job['conflicts']} left alone (changed since)", [], 'sync', 'Product_Workbench' );
            }
            Workbench_Change_Log::save_job( $job );
            self::invalidate_search_cache();
            wp_cache_flush_runtime();
        } finally {
            delete_transient( $lock );
        }
        return [ 'job' => $job ];
    }

    /** Write a before-state back. */
    private static function restore( int $id, string $action, array $params, array $state ): void {
        switch ( $action ) {
            case 'set_taxonomy_term':
                wp_set_object_terms( $id, array_map( 'intval', $state['terms'] ), $params['taxonomy'], false );
                // A row edit locked the field; undoing it releases a lock it added.
                if ( ! empty( $params['locked_here'] ) && class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
                    \MMI_Pipeline_Field_Locks::unlock( $id, $params['taxonomy'] );
                }
                break;

            case 'set_status_publish':
            case 'set_status_draft':
            case 'set_status_pending':
                wp_update_post( [ 'ID' => $id, 'post_status' => $state['status'] ] );
                break;

            case 'set_regular_price':
            case 'set_sale_price':
            case 'clear_sale_price':
                $product = wc_get_product( $id );
                if ( $product ) {
                    $product->set_regular_price( $state['regular'] );
                    $product->set_sale_price( $state['sale'] );
                    $product->save();
                }
                break;

            case 'set_sale_dates':
                $product = wc_get_product( $id );
                if ( $product ) {
                    $product->set_date_on_sale_from( $state['from'] !== '' ? $state['from'] : null );
                    $product->set_date_on_sale_to( $state['to'] !== '' ? $state['to'] : null );
                    $product->save();
                }
                break;

            case 'set_stock_status_backorder':
                Stock_Override_Resolver::force_stock_status( $id, $state['stock_status'] ?: 'instock' );
                break;

            case 'set_backorders_yes':
            case 'set_backorders_notify':
            case 'set_backorders_no':
                $product = wc_get_product( $id );
                if ( $product ) {
                    $product->set_backorders( $state['backorders'] ?: 'no' );
                    $product->save();
                }
                break;

            case 'enable_manage_stock':
            case 'disable_manage_stock':
                $product = wc_get_product( $id );
                if ( $product ) {
                    $product->set_manage_stock( $state['manage_stock'] === 'yes' );
                    $product->save();
                }
                break;

            case 'copy_source_field_to_meta':
                if ( $state['exists'] ) {
                    update_post_meta( $id, $params['meta_key'], $state['value'] );
                } else {
                    delete_post_meta( $id, $params['meta_key'] );
                }
                break;
        }
        clean_post_cache( $id );
    }

    /* ── Row edit: one product's categories or brand ───────────────────── */

    /** Taxonomies the results table can edit in place. */
    const EDITABLE_TAXONOMIES = [ 'product_cat', 'product_brand' ];

    /**
     * Set one product's terms in a taxonomy, from the results table. Locks
     * the field against imports (Field Locks), as Product Titles' category
     * picker does, so the next supplier import can't put the old terms back,
     * and records a one-product job so it appears in Recent changes with Undo.
     *
     * @param  int[] $term_ids
     * @return array|\WP_Error The product's refreshed results row.
     */
    public static function set_product_terms( int $id, string $taxonomy, array $term_ids ) {
        if ( ! in_array( $taxonomy, self::EDITABLE_TAXONOMIES, true ) || ! taxonomy_exists( $taxonomy ) ) {
            return new \WP_Error( 'taxonomy', 'That field can’t be edited here.' );
        }
        if ( get_post_type( $id ) !== 'product' ) {
            return new \WP_Error( 'product', 'Unknown product.' );
        }
        $term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
        if ( $taxonomy === 'product_cat' && empty( $term_ids ) ) {
            return new \WP_Error( 'empty', 'Choose at least one category.' );
        }
        foreach ( $term_ids as $term_id ) {
            if ( ! term_exists( $term_id, $taxonomy ) ) {
                return new \WP_Error( 'term', 'One of those terms no longer exists. Reload and try again.' );
            }
        }

        $params = [ 'taxonomy' => $taxonomy ];
        $before = self::state( $id, 'set_taxonomy_term', $params );
        $set    = wp_set_object_terms( $id, $term_ids, $taxonomy, false );
        if ( is_wp_error( $set ) ) {
            return $set;
        }
        clean_object_term_cache( $id, 'product' );
        $after = self::state( $id, 'set_taxonomy_term', $params );

        if ( $after != $before ) { // phpcs:ignore Universal.Operators.StrictComparisons -- array value equality.
            if ( class_exists( 'MMI_Pipeline_Field_Locks' ) && ! \MMI_Pipeline_Field_Locks::is_locked( $id, $taxonomy ) ) {
                \MMI_Pipeline_Field_Locks::lock( $id, $taxonomy );
                $params['locked_here'] = true;
            }
            wc_delete_product_transients( $id );

            Workbench_Change_Log::ensure_table();
            $tax   = get_taxonomy( $taxonomy );
            $title = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
            $job   = [
                'id'           => wp_generate_uuid4(),
                'created_at'   => current_time( 'mysql' ),
                'user'         => wp_get_current_user()->display_name,
                'action'       => 'set_taxonomy_term',
                'params'       => $params,
                'summary'      => sprintf( '%s → %s', $tax ? $tax->labels->name : $taxonomy, self::describe( $after, 'set_taxonomy_term' ) ),
                'filter_label' => mb_substr( sprintf( 'Edited in the table: #%d %s', $id, $title ), 0, 200 ),
                'total'        => 1,
                'cursor'       => 1,
                'changed'      => 1,
                'unchanged'    => 0,
                'failed'       => 0,
                'status'       => 'done',
                'undo_cursor'  => 0,
                'undone'       => 0,
                'conflicts'    => 0,
            ];
            Workbench_Change_Log::save_job_ids( $job['id'], [ $id ] );
            Workbench_Change_Log::record( $job['id'], [ [ 'product_id' => $id, 'before' => $before, 'after' => $after ] ] );
            Workbench_Change_Log::save_job( $job );
            self::invalidate_search_cache();
            MMI_Logger::info( "Workbench row edit {$job['id']}: product {$id} {$job['summary']}", [ 'before' => $before['terms'] ], 'sync', 'Product_Workbench' );
            if ( function_exists( 'mmi_data_pipeline_audit' ) ) {
                mmi_data_pipeline_audit( 'workbench.edit', [
                    'object_type' => 'product',
                    'object_id'   => (string) $id,
                    'outcome'     => 'success',
                    'details'     => [ 'taxonomy' => $taxonomy, 'job' => $job['id'] ],
                ] );
            }
        }

        return self::rows( [ $id ] )[0] ?? new \WP_Error( 'product', 'Unknown product.' );
    }

    /** Recent jobs for the Recent Changes table. */
    public static function job_list(): array {
        return array_values( array_map( static function ( $job ) {
            unset( $job['params'] );
            return $job;
        }, Workbench_Change_Log::jobs() ) );
    }

    /* ── Saved searches ────────────────────────────────────────────────── */

    const SAVED_SEARCHES_SETTING = 'mmi_workbench_saved_searches';
    const SAVED_SEARCH_PREFIX    = 'wb:';
    const SAVED_SEARCH_DELETE    = 'mmi_workbench_delete_search';
    const MAX_SAVED_SEARCHES     = 100;

    /** @return array<string,array{label:string,match_logic:string,conditions:array}> slug => search. */
    private static function saved_searches(): array {
        $saved = \MMI_DB::get_setting( self::SAVED_SEARCHES_SETTING, [] );
        return is_array( $saved ) ? $saved : [];
    }

    /**
     * Save a search's conditions under a name. Saving under a name already
     * used replaces that search.
     *
     * @return string|\WP_Error The library id ("wb:slug").
     */
    public static function save_search( string $label, string $match_logic, array $raw_conditions ) {
        $label = trim( sanitize_text_field( $label ) );
        $slug  = sanitize_title( $label );
        if ( $label === '' || $slug === '' ) {
            return new \WP_Error( 'name', 'Give the search a name.' );
        }
        $conditions = array_values( array_filter( array_map( [ Stock_Override_Resolver::class, 'sanitize_condition' ], $raw_conditions ) ) );
        if ( empty( $conditions ) ) {
            return new \WP_Error( 'empty', 'Add at least one condition — a saved search keeps the conditions, not the title/SKU box.' );
        }
        $saved = self::saved_searches();
        if ( ! isset( $saved[ $slug ] ) && count( $saved ) >= self::MAX_SAVED_SEARCHES ) {
            return new \WP_Error( 'full', sprintf( 'There are already %d saved searches. Delete one first.', self::MAX_SAVED_SEARCHES ) );
        }
        $saved[ $slug ] = [
            'label'       => $label,
            'match_logic' => $match_logic === 'any' ? 'any' : 'all',
            'conditions'  => $conditions,
        ];
        \MMI_DB::set_setting( self::SAVED_SEARCHES_SETTING, $saved );
        return self::SAVED_SEARCH_PREFIX . $slug;
    }

    /** Delete a saved search by its library id. False when there was none. */
    public static function delete_search( string $id ): bool {
        if ( strpos( $id, self::SAVED_SEARCH_PREFIX ) !== 0 ) {
            return false;
        }
        $slug  = sanitize_title( substr( $id, strlen( self::SAVED_SEARCH_PREFIX ) ) );
        $saved = self::saved_searches();
        if ( ! isset( $saved[ $slug ] ) ) {
            return false;
        }
        unset( $saved[ $slug ] );
        \MMI_DB::set_setting( self::SAVED_SEARCHES_SETTING, $saved );
        return true;
    }

    /** 'mmi_condition_library' contribution: the Workbench's saved searches. */
    public static function library_sets( array $sets ): array {
        if ( ! class_exists( 'MMI_DB' ) ) {
            return $sets;
        }
        foreach ( self::saved_searches() as $slug => $search ) {
            $sets[] = [
                'id'            => self::SAVED_SEARCH_PREFIX . $slug,
                'group'         => 'Product Workbench',
                'label'         => (string) ( $search['label'] ?? $slug ),
                'match_logic'   => $search['match_logic'] ?? 'all',
                'conditions'    => (array) ( $search['conditions'] ?? [] ),
                'delete_action' => self::SAVED_SEARCH_DELETE,
            ];
        }
        return $sets;
    }
}

add_filter( 'mmi_condition_library', [ Product_Workbench::class, 'library_sets' ] );
