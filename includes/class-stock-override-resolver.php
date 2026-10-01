<?php
/**
 * Stock Override Resolver
 *
 * Determines the final stock status for a product during import by checking two tiers
 * of admin-configured overrides before falling back to the supplier source value:
 *
 *   1. Per-product postmeta  (_mmi_stock_override) — highest priority
 *   2. Bulk rules            (mmi_stock_override_rules, evaluated first-match)
 *   3. Source data           — unchanged fallback
 *
 * Bulk rule shape (new multi-condition format):
 *   [
 *     'supplier'    => 'xchange'  (or 'all'),
 *     'match_logic' => 'all'      (or 'any')  — AND vs OR across conditions,
 *     'conditions'  => [
 *       [ 'field' => 'is_hardware', 'operator' => 'equals', 'value' => 'yes' ],
 *       [ 'field' => 'qty',        'operator' => 'greater_than', 'value' => '0' ],
 *     ],
 *     'override'    => 'force_outofstock' | 'force_instock',
 *   ]
 *
 * Supported operators: equals, not_equals, contains, not_contains,
 *   starts_with, ends_with, is_empty, is_not_empty, greater_than, less_than.
 *
 * Empty conditions array = match all products (same as old condition='all').
 *
 * Old flat format (condition/flag/flag_value) is still accepted and
 * auto-migrated on the fly via normalize_rule().
 *
 * @package MannMade\DataPipeline
 */

namespace MannMade\DataPipeline;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Stock_Override_Resolver {

    /* ── Allowed override values ─────────────────────────────────────────── */

    const VALID_OVERRIDES = [ 'force_instock', 'force_outofstock' ];

    const VALID_OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains',
        'starts_with', 'ends_with', 'is_empty', 'is_not_empty',
        'greater_than', 'less_than', 'in_list', 'not_in_list',
    ];

    /**
     * Delimiter joining multiple selected values for the 'in_list'/
     * 'not_in_list' operators, written by the condition row's checklist UI
     * (import-pipeline-catalog-maintenance.js's joinListValue()) and read
     * back via split_list_value() below. A pipe survives sanitize_text_field()
     * unmangled — unlike a newline, which that function collapses to a
     * space — and essentially never appears in a real taxonomy term name or
     * postmeta value.
     */
    const LIST_VALUE_DELIMITER = '|';

    /**
     * Negative operator => its positive twin. On a taxonomy a product has
     * many terms, so "is none of X" means "no term is X" — the complement of
     * the positive match — not "some term isn't X" (which every product
     * with a second term satisfies).
     */
    const NEGATED_OPERATORS = [ 'not_equals' => 'equals', 'not_contains' => 'contains', 'not_in_list' => 'in_list' ];

    /**
     * Reserved condition `source` value meaning "match against a live
     * WordPress/WooCommerce taxonomy on the product itself" rather than a
     * supplier's raw feed data — the app-data side of a condition, as
     * opposed to every other `source` value (a real supplier id), which is
     * always source-data. `field` holds the taxonomy slug (e.g.
     * 'product_brand') when this is the source. Retired the old, separate
     * "Brand out-of-stock rule" row type in favor of this — see
     * Catalog_Phase_Runner's own note on the same removal.
     */
    const TAXONOMY_SOURCE = 'wp_taxonomy';

    /**
     * Reserved condition `source` value meaning "match against a WordPress
     * post field or postmeta value on the product itself" — the other half
     * of the app-data side of a condition, alongside TAXONOMY_SOURCE. `field`
     * is either one of POST_FIELD_MAP's keys (a real wp_posts column, e.g.
     * '__post_title') or a literal postmeta key (e.g. '_sku', 'is_hardware',
     * or any custom tracking meta) — the same `__post_title`/literal-key
     * naming convention MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key()
     * already uses for the identical "WP column vs. postmeta key" distinction.
     */
    const POSTMETA_SOURCE = 'wp_postmeta';

    /**
     * Reserved condition `source` value meaning "this field's own value" —
     * only offered by Field Mapping's per-field conditions, where it's the
     * field's mapped (post-transform) value for the record being imported,
     * passed in via rule_matches_product()'s $context['field_value']. Keeps
     * the check Field Mapping's old own-value-only builder made, now as one
     * source among the shared builder's others. `field` is always 'value'.
     */
    const FIELD_VALUE_SOURCE = 'mmi_field_value';

    /**
     * POSTMETA_SOURCE's reserved `field` values that mean a real wp_posts
     * column rather than a postmeta key. Anything else is treated as a
     * literal postmeta key.
     */
    const POST_FIELD_MAP = [
        '__post_title'   => 'post_title',
        '__post_content' => 'post_content',
        '__post_excerpt' => 'post_excerpt',
        '__post_name'    => 'post_name',
        '__post_status'  => 'post_status',
    ];

    /**
     * Post statuses the matcher's queries cover. Custom Rules only ever act
     * on published products, so 'publish' is the default every existing
     * caller gets; Product Workbench widens it for one call at a time via
     * with_statuses() (drafts are exactly where stub products live).
     *
     * @var string[]
     */
    private static array $status_scope = [ 'publish' ];

    /** Every status with_statuses() accepts. */
    const SEARCHABLE_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    /**
     * Run $fn with the matcher scoped to $statuses, then restore the
     * previous scope — even if $fn throws.
     *
     * @param  string[] $statuses Subset of SEARCHABLE_STATUSES.
     * @param  callable $fn
     * @return mixed    Whatever $fn returns.
     */
    public static function with_statuses( array $statuses, callable $fn ) {
        $statuses = array_values( array_intersect( self::SEARCHABLE_STATUSES, $statuses ) );
        $previous = self::$status_scope;
        self::$status_scope = $statuses ?: [ 'publish' ];
        try {
            return $fn();
        } finally {
            self::$status_scope = $previous;
        }
    }

    /**
     * SQL fragment for the current status scope. Values come only from
     * SEARCHABLE_STATUSES (see with_statuses()), never from request input.
     *
     * @param string $alias Table alias, or '' for an unaliased column.
     */
    private static function status_sql( string $alias = 'p' ): string {
        $column = $alias === '' ? 'post_status' : $alias . '.post_status';
        $quoted = array_map( static fn( $s ) => "'" . esc_sql( $s ) . "'", self::$status_scope );
        return $column . ' IN (' . implode( ',', $quoted ) . ')';
    }

    /**
     * Product IDs matching a rule-shaped filter (supplier scope, match_logic,
     * conditions) within the given statuses. A filter with no conditions
     * returns every product in the supplier scope.
     *
     * @param  array    $filter   {supplier, match_logic, conditions[]}.
     * @param  string[] $statuses Subset of SEARCHABLE_STATUSES.
     * @return int[]
     */
    public static function find_product_ids( array $filter, array $statuses = [ 'publish' ] ): array {
        return self::with_statuses( $statuses, static fn() => self::get_matched_product_ids_for_rule( $filter ) );
    }

    /**
     * Sanitize one condition from request input into the stored shape, or
     * null when it can't be evaluated. Shared by the Custom Rules save
     * handler and Product Workbench so both accept exactly the same thing.
     *
     * @param  mixed $cond Raw condition.
     * @return array|null
     */
    public static function sanitize_condition( $cond ): ?array {
        if ( ! is_array( $cond ) ) {
            return null;
        }
        $field    = sanitize_key( $cond['field'] ?? '' );
        $operator = sanitize_text_field( $cond['operator'] ?? 'equals' );
        if ( $field === '' || ! in_array( $operator, self::VALID_OPERATORS, true ) ) {
            return null;
        }
        $clean = [
            'source'   => sanitize_text_field( $cond['source'] ?? '' ),
            'field'    => $field,
            'operator' => $operator,
            'value'    => sanitize_text_field( $cond['value'] ?? '' ),
        ];
        // Only stored when on, so every rule saved before this option
        // existed keeps an identical shape.
        if ( ! empty( $cond['case_sensitive'] ) && $cond['case_sensitive'] !== '0' ) {
            $clean['case_sensitive'] = true;
        }
        return $clean;
    }

    /**
     * The raw supplier-feed record for one product ('' feeds → []). Loads
     * each feed once per request. Used where a single product is handled
     * outside a whole-catalog pass (Product Workbench's Copy Source Field
     * to Meta).
     *
     * @return array
     */
    public static function raw_feed_item( int $product_id ): array {
        static $lookup = null;
        if ( $lookup === null ) {
            // Any non-empty condition list makes the helper load every feed.
            $lookup = self::build_feed_lookup_for_rules( [ [ 'conditions' => [ [ 'field' => '_' ] ] ] ] );
        }
        $detected = self::detect_product_supplier( $product_id );
        if ( $detected['feed_meta_key'] === '' || $detected['supplier_sku_val'] === '' ) {
            return [];
        }
        return $lookup[ $detected['feed_meta_key'] ][ $detected['supplier_sku_val'] ] ?? [];
    }

    /** Supplier id a product is tracked under ('all' when none). */
    public static function product_supplier( int $product_id ): string {
        return self::detect_product_supplier( $product_id )['supplier'];
    }

    /** Configured supplier ids, in the order product_supplier() checks them. */
    public static function supplier_ids(): array {
        return self::get_all_supplier_ids();
    }

    /* ── Supplier scope resolution (generic — any configured source) ────────
     * The Custom Rule builder's supplier <select> (panel-catalog-maintenance.php)
     * already loops over every real configured data source, not just Xchange/
     * SkuPort — these three helpers are the single place that resolves "which
     * products belong to supplier X" for every consumer in this class
     * (get_product_ids_by_supplier(), count_products_by_supplier(),
     * detect_product_supplier(), and the legacy per-item scan's file lookup),
     * so a rule scoped to any configured supplier works correctly rather than
     * only the two that happened to exist when this class was first written.
     */

    /**
     * Every currently-configured supplier id (Data Sources table), in the
     * same order MMI_Pipeline_Admin::get_configured_suppliers() returns —
     * used as the precedence order for detect_product_supplier() below.
     * Falls back to the two original suppliers if the registry class isn't
     * loaded (shouldn't happen in production; keeps this class from hard-
     * depending on MMI_Pipeline_Admin for basic operation).
     *
     * @return string[]
     */
    private static function get_all_supplier_ids(): array {
        if ( class_exists( 'MMI_Pipeline_Admin' ) ) {
            return array_keys( \MMI_Pipeline_Admin::get_configured_suppliers() );
        }
        return [ 'xchange', 'skuport' ];
    }

    /**
     * The supplier-SKU-tracking postmeta key convention every import path in
     * this plugin already uses (see MMI_Pipeline_Field_Resolver::
     * find_product_ids_by_primary_keys() and get_product_ids_by_source_field_value()
     * below, which independently arrived at the identical convention).
     */
    private static function get_supplier_sku_meta_key( string $supplier ): string {
        return '_mmi_supplier_sku_' . $supplier;
    }

    /**
     * Meta keys to exclude a product by for "native" (no tracked supplier at
     * all) scope — every configured supplier's SKU meta key, not just
     * Xchange/SkuPort's, so a product tracked by a newer supplier is never
     * miscounted as native.
     *
     * @return string[]
     */
    private static function native_exclusion_meta_keys(): array {
        return array_map( [ self::class, 'get_supplier_sku_meta_key' ], self::get_all_supplier_ids() );
    }

    /**
     * Detect which configured supplier "owns" a product — by whichever
     * supplier SKU-tracking meta is populated, first match wins in
     * get_all_supplier_ids() order — plus the feed-lookup key/value needed
     * to find that product's raw source record. Single implementation
     * shared by apply_to_catalog() and apply_catalog_page(), replacing what
     * used to be their own separately-duplicated, Xchange/SkuPort-only
     * inline detection.
     *
     * @param  int $product_id
     * @return array{supplier:string, feed_meta_key:string, supplier_sku_val:string}
     */
    private static function detect_product_supplier( int $product_id ): array {
        foreach ( self::get_all_supplier_ids() as $sid ) {
            $meta_key = self::get_supplier_sku_meta_key( $sid );
            $sku      = get_post_meta( $product_id, $meta_key, true );
            if ( $sku !== '' ) {
                return [ 'supplier' => $sid, 'feed_meta_key' => $meta_key, 'supplier_sku_val' => $sku ];
            }
        }
        return [ 'supplier' => 'all', 'feed_meta_key' => '', 'supplier_sku_val' => '' ];
    }

    /* ── Rule normalisation (old → new format migration) ─────────────────── */

    /**
     * Normalise a single rule from either the old flat format or the new
     * multi-condition format so the rest of the class only sees one shape.
     *
     * Old keys accepted:  condition ('all' | 'flag_match'), flag, flag_value.
     * New keys:           match_logic ('all' | 'any'), conditions[].
     *
     * @param  array $rule
     * @return array  Normalised rule.
     */
    public static function normalize_rule( array $rule ): array {
        // Already in new format.
        if ( isset( $rule['conditions'] ) ) {
            if ( ! isset( $rule['match_logic'] ) ) {
                $rule['match_logic'] = 'all';
            }
            // Migrate the old 'override' key (every rule ever saved before
            // the Custom Rule action expansion) to 'action' — same
            // on-the-fly migration pattern as the flat-format migration
            // below, just for a field rename instead of a shape change.
            if ( ! isset( $rule['action'] ) && isset( $rule['override'] ) ) {
                $rule['action'] = $rule['override'];
            }
            if ( ! isset( $rule['action_params'] ) || ! is_array( $rule['action_params'] ) ) {
                $rule['action_params'] = [];
            }
            $rule['name']    = isset( $rule['name'] ) ? (string) $rule['name'] : '';
            // Absent 'enabled' means "saved before this key existed" — treat
            // as enabled so a pre-existing rule keeps running exactly as it
            // always has, rather than silently going inert on the next save.
            $rule['enabled'] = ! isset( $rule['enabled'] ) || (bool) $rule['enabled'];
            return $rule;
        }

        // Migrate from old flat format.
        $condition  = $rule['condition'] ?? 'all';
        $conditions = [];

        if ( $condition === 'flag_match' ) {
            $flag       = $rule['flag']       ?? '';
            $flag_value = $rule['flag_value'] ?? '';
            if ( $flag !== '' ) {
                $conditions[] = [
                    'field'    => $flag,
                    'operator' => 'equals',
                    'value'    => $flag_value,
                ];
            }
        }
        // 'all' leaves conditions empty → matches every product.

        return [
            'supplier'      => $rule['supplier'] ?? 'all',
            'match_logic'   => 'all',
            'conditions'    => $conditions,
            'action'        => $rule['action'] ?? ( $rule['override'] ?? '' ),
            'action_params' => [],
            'name'          => isset( $rule['name'] ) ? (string) $rule['name'] : '',
            'enabled'       => ! isset( $rule['enabled'] ) || (bool) $rule['enabled'],
        ];
    }

    /**
     * Normalise an array of rules.
     *
     * @param  array $rules
     * @return array
     */
    public static function normalize_rules( array $rules ): array {
        return array_map( [ self::class, 'normalize_rule' ], $rules );
    }

    /* ── Matched product ID helpers ──────────────────────────────────────── */

    /**
     * Count how many published WC products would be matched by a rule.
     * Fast SQL COUNT for no-condition rules; feed scan for condition-based rules.
     *
     * @param  array $rule  Single rule array (new or old format).
     * @return int
     */
    public static function count_matched_products_for_rule( array $rule ): int {
        $norm       = self::normalize_rule( $rule );
        $conditions = $norm['conditions'] ?? [];

        if ( empty( $conditions ) ) {
            return self::count_products_by_supplier( $norm['supplier'] ?? 'all' );
        }

        return count( self::get_matched_product_ids_for_rule( $rule ) );
    }

    /**
     * Per-condition match breakdown — for debugging why a multi-condition
     * rule matches fewer (or more) products than expected: each condition's
     * own match set (as if it were the rule's only condition), plus the
     * rule's real combined result, each with a capped ID+title sample.
     * Legacy-format conditions (no 'source' key) aren't broken out
     * individually — the legacy per-item feed scan only ever evaluates all
     * of them together — so those return a zero-count placeholder with a
     * note rather than a wrong/misleading count.
     *
     * @param  array $rule          Rule array (old or new format).
     * @param  int   $sample_limit  Max ID+title pairs returned per condition/combined.
     * @return array{conditions: array<int,array{count:int,sample:array,unsupported?:bool}>, combined: array{count:int,sample:array}}
     */
    public static function get_condition_breakdown( array $rule, int $sample_limit = 100 ): array {
        $norm       = self::normalize_rule( $rule );
        $conditions = $norm['conditions'] ?? [];

        // Per-condition FULL id sets (uncapped) — needed so the union table's
        // per-row "which conditions matched" membership is correct even for a
        // condition with more matches than fit in the display cap, not just
        // reconstructable from independently-capped samples.
        $condition_id_sets = [];
        $condition_summary = [];

        foreach ( $conditions as $cond ) {
            $source = $cond['source'] ?? '';
            $field  = $cond['field']  ?? '';

            if ( $field === '' || $source === '' ) {
                $condition_id_sets[] = [];
                $condition_summary[] = [ 'count' => 0, 'unsupported' => true ];
                continue;
            }

            $operator = $cond['operator'] ?? 'equals';
            $value    = (string) ( $cond['value'] ?? '' );

            if ( $source === self::TAXONOMY_SOURCE ) {
                $ids = self::get_product_ids_by_taxonomy_condition( $field, $operator, $value, ! empty( $cond['case_sensitive'] ) );
            } elseif ( $source === self::POSTMETA_SOURCE ) {
                $ids = self::get_product_ids_by_postmeta_condition( $field, $operator, $value, ! empty( $cond['case_sensitive'] ) );
            } else {
                $ids = self::get_product_ids_by_source_field_value( $source, $field, $operator, $value, ! empty( $cond['case_sensitive'] ) );
            }

            $condition_id_sets[] = $ids;
            $condition_summary[] = [ 'count' => count( $ids ), 'unsupported' => false ];
        }

        // Union across every condition, tracking per-product which condition
        // index(es) matched it — the basis for the unified table's per-row
        // color-coded condition badges.
        $membership = [];
        foreach ( $condition_id_sets as $i => $ids ) {
            foreach ( $ids as $id ) {
                $membership[ $id ][] = $i;
            }
        }

        $combined_ids = self::get_matched_product_ids_for_rule( $rule );
        $combined_map = array_fill_keys( $combined_ids, true );

        $union_ids = array_slice( array_keys( $membership ), 0, $sample_limit );
        $rows      = [];
        foreach ( $union_ids as $id ) {
            $title   = get_the_title( $id );
            $rows[]  = [
                'id'         => (int) $id,
                'title'      => $title !== '' ? $title : '(no title)',
                'conditions' => array_values( $membership[ $id ] ),
                'in_result'  => isset( $combined_map[ $id ] ),
            ];
        }

        return [
            'conditions'     => $condition_summary,
            'match_logic'    => $norm['match_logic'] ?? 'all',
            'union_total'    => count( $membership ),
            'union_shown'    => count( $union_ids ),
            'combined_count' => count( $combined_ids ),
            'rows'           => $rows,
        ];
    }

    /**
     * Return the WC product IDs that would be affected by a given rule.
     * Rules with no conditions return all products in the supplier scope.
     *
     * When conditions use the source-cascade format {source, field, value} each
     * condition is resolved independently; results combined via match_logic.
     * Old-format conditions {field, operator, value} use the legacy per-item scan.
     *
     * @param  array $rule  Single rule array (new or old format).
     * @return int[]        Array of WooCommerce product IDs.
     */
    public static function get_matched_product_ids_for_rule( array $rule ): array {
        global $wpdb;
        $status_sql = self::status_sql();

        $norm       = self::normalize_rule( $rule );
        $supplier   = $norm['supplier']   ?? 'all';
        $conditions = $norm['conditions'] ?? [];

        if ( empty( $conditions ) ) {
            return self::get_product_ids_by_supplier( $supplier );
        }

        // Detect whether conditions use the new source-cascade format.
        $has_source_conditions = false;
        foreach ( $conditions as $cond ) {
            if ( isset( $cond['source'] ) && $cond['source'] !== '' ) {
                $has_source_conditions = true;
                break;
            }
        }

        if ( $has_source_conditions ) {
            // A condition's own `source` decides WHAT to check (a supplier
            // field, a taxonomy, a post field/meta) — it says nothing about
            // WHICH products the rule is allowed to touch at all. The rule's
            // own `supplier` is that separate, real scope, and must still
            // apply here: apply_to_catalog()/apply_catalog_page()'s
            // per-product rule_matches_supplier() gate already enforces it
            // during actual execution, so leaving it unapplied here would
            // let this preview/count/breakdown path show a product as
            // "matching" that a real Apply Now or scheduled run would
            // silently skip — exactly the preview/reality divergence this
            // project's own incident history warns against.
            return self::filter_ids_by_supplier_scope( self::get_ids_by_source_conditions( $norm ), $supplier );
        }

        // Legacy path: scan the rule-level supplier feed per item.
        $json_path    = mmi_shared_lib_json_dir();
        $supplier_map = [];
        foreach ( self::get_all_supplier_ids() as $sid ) {
            $supplier_map[ $sid ] = [ 'file' => $sid . '-products.json', 'sku_meta' => self::get_supplier_sku_meta_key( $sid ) ];
        }

        $sources = ( $supplier !== 'all' && isset( $supplier_map[ $supplier ] ) )
            ? [ $supplier => $supplier_map[ $supplier ] ]
            : $supplier_map;

        $skus_by_meta = [];

        foreach ( $sources as $sup_info ) {
            $file = $json_path . $sup_info['file'];
            if ( ! file_exists( $file ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $data = json_decode( file_get_contents( $file ), true );
            if ( ! is_array( $data ) ) {
                continue;
            }

            $items    = $data['products'] ?? $data['items'] ?? ( isset( $data[0] ) ? $data : [] );
            $meta_key = $sup_info['sku_meta'];

            foreach ( $items as $item ) {
                if ( ! self::evaluate_conditions( $norm, (array) $item ) ) {
                    continue;
                }
                $sku = $item['sku'] ?? $item['product']['sku'] ?? $item['id'] ?? null;
                if ( $sku === null || $sku === '' ) {
                    continue;
                }
                $skus_by_meta[ $meta_key ][] = (string) $sku;
            }

            if ( ! empty( $skus_by_meta[ $meta_key ] ) ) {
                $skus_by_meta[ $meta_key ] = array_unique( $skus_by_meta[ $meta_key ] );
            }
        }

        if ( empty( $skus_by_meta ) ) {
            return [];
        }

        $or_clauses   = [];
        $prepare_args = [];

        foreach ( $skus_by_meta as $meta_key => $skus ) {
            $in_placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );
            $or_clauses[]    = "( pm.meta_key = %s AND pm.meta_value IN ({$in_placeholders}) )";
            $prepare_args[]  = $meta_key;
            $prepare_args    = array_merge( $prepare_args, $skus );
        }

        $supplier_clause = implode( ' OR ', $or_clauses );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND ( {$supplier_clause} )
             WHERE p.post_type = 'product' AND {$status_sql}",
            ...$prepare_args
        );
        // phpcs:enable

        return array_map( 'intval', $wpdb->get_col( $sql ) );
    }

    /**
     * SQL COUNT of published WC products in a supplier scope.
     *
     * @param  string $supplier  'all', 'xchange', or 'skuport'.
     * @return int
     */

    /**
     * Resolve matched product IDs for rules using source-cascade conditions
     * {source, field, value}. Each condition is evaluated by scanning its source
     * JSON file for items where item[field] === value, then resolving to WC IDs
     * via the supplier SKU postmeta. Results are combined per match_logic.
     *
     * @param  array $norm  Normalised rule.
     * @return int[]
     */
    /**
     * Intersect a condition-matched ID set with the rule's own supplier
     * scope ('all' = no restriction; 'xchange'/'skuport'/'native' = only
     * products this SKU-tracking/absence check identifies). Reuses
     * get_product_ids_by_supplier() — the same scope resolution
     * rule_matches_supplier() ultimately mirrors per-product during real
     * execution — so this stays a single source of truth for what each
     * supplier value means, not a second, parallel definition of it.
     *
     * @param  int[]  $ids
     * @param  string $supplier
     * @return int[]
     */
    private static function filter_ids_by_supplier_scope( array $ids, string $supplier ): array {
        if ( $supplier === 'all' || empty( $ids ) ) {
            return $ids;
        }
        $scoped = self::get_product_ids_by_supplier( $supplier );
        return array_values( array_intersect( $ids, $scoped ) );
    }

    private static function get_ids_by_source_conditions( array $norm ): array {
        $conditions  = $norm['conditions']  ?? [];
        $match_logic = $norm['match_logic'] ?? 'all';

        $sets = [];
        foreach ( $conditions as $cond ) {
            $source   = $cond['source']   ?? '';
            $field    = $cond['field']    ?? '';
            $operator = $cond['operator'] ?? 'equals';
            $value    = (string) ( $cond['value'] ?? '' );
            if ( $source === '' || $field === '' ) {
                continue;
            }
            if ( $source === self::TAXONOMY_SOURCE ) {
                $sets[] = self::get_product_ids_by_taxonomy_condition( $field, $operator, $value, ! empty( $cond['case_sensitive'] ) );
            } elseif ( $source === self::POSTMETA_SOURCE ) {
                $sets[] = self::get_product_ids_by_postmeta_condition( $field, $operator, $value, ! empty( $cond['case_sensitive'] ) );
            } else {
                $sets[] = self::get_product_ids_by_source_field_value( $source, $field, $operator, $value, ! empty( $cond['case_sensitive'] ) );
            }
        }

        if ( empty( $sets ) ) {
            return [];
        }

        if ( $match_logic === 'any' ) {
            $merged = [];
            foreach ( $sets as $set ) {
                foreach ( $set as $id ) {
                    $merged[ $id ] = true;
                }
            }
            return array_keys( $merged );
        }

        // AND: intersect all sets.
        $result = $sets[0];
        for ( $i = 1, $n = count( $sets ); $i < $n; $i++ ) {
            $result = array_values( array_intersect( $result, $sets[ $i ] ) );
            if ( empty( $result ) ) {
                return [];
            }
        }
        return $result;
    }

    /**
     * Scan a supplier's product JSON file for items where item[$field] satisfies
     * $operator against $value, then return the matching WC product IDs via the
     * supplier SKU postmeta.
     *
     * @param  string $source    Supplier slug (e.g. 'xchange').
     * @param  string $field     JSON item field name.
     * @param  string $operator  Comparison operator (see VALID_OPERATORS).
     * @param  string $value     Value to compare against.
     * @return int[]
     */
    private static function get_product_ids_by_source_field_value( string $source, string $field, string $operator, string $value, bool $case_sensitive = false ): array {
        global $wpdb;
        $status_sql = self::status_sql();

        $json_dir  = mmi_shared_lib_json_dir();
        $json_file = $json_dir . $source . '-products.json';

        if ( ! file_exists( $json_file ) ) {
            return [];
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = json_decode( file_get_contents( $json_file ), true );
        if ( ! is_array( $data ) ) {
            return [];
        }

        $items    = $data['products'] ?? $data['items'] ?? ( isset( $data[0] ) ? $data : [] );
        $sku_meta = '_mmi_supplier_sku_' . $source;
        $skus     = [];

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $field_val = isset( $item[ $field ] ) ? (string) $item[ $field ] : null;
            if ( self::eval_operator( $field_val, $operator, $value, $case_sensitive ) ) {
                $pk = $item['sku'] ?? $item['id'] ?? null;
                if ( $pk !== null && (string) $pk !== '' ) {
                    $skus[] = (string) $pk;
                }
            }
        }

        $skus = array_values( array_unique( $skus ) );
        if ( empty( $skus ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                AND pm.meta_key = %s AND pm.meta_value IN ({$placeholders})
             WHERE p.post_type = 'product' AND {$status_sql}",
            ...array_merge( [ $sku_meta ], $skus )
        );
        // phpcs:enable

        return array_map( 'intval', $wpdb->get_col( $sql ) );
    }

    /**
     * Resolve matched product IDs for a condition sourced from a live WP/WC
     * taxonomy (TAXONOMY_SOURCE) rather than a supplier feed — the app-data
     * counterpart to get_product_ids_by_source_field_value(). $field is the
     * taxonomy slug (e.g. 'product_brand', 'product_cat', or any custom
     * product taxonomy); $value is matched against each term's own name and
     * slug using the same operator semantics as every other condition
     * (eval_operator()), so 'equals' matches a term by its display name OR
     * its slug — the Value input is freeform text, a user may type either.
     *
     * Term counts for product taxonomies are small (hundreds at most), so
     * this fetches every term and filters in PHP with the existing
     * eval_operator() helper rather than building a second, parallel SQL
     * LIKE/prefix/suffix implementation — mirrors how the supplier-feed path
     * already scans every item and filters in PHP for the identical reason.
     *
     * @param  string $taxonomy  Taxonomy slug.
     * @param  string $operator  One of VALID_OPERATORS.
     * @param  string $value     Value to compare against (unused for is_empty/is_not_empty).
     * @return int[]
     */
    private static function get_product_ids_by_taxonomy_condition( string $taxonomy, string $operator, string $value, bool $case_sensitive = false ): array {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return [];
        }

        if ( isset( self::NEGATED_OPERATORS[ $operator ] ) ) {
            $excluded = array_flip( self::get_product_ids_by_taxonomy_condition( $taxonomy, self::NEGATED_OPERATORS[ $operator ], $value, $case_sensitive ) );
            return array_values( array_filter( self::get_product_ids_by_supplier( 'all' ), static fn( $id ) => ! isset( $excluded[ $id ] ) ) );
        }

        if ( $operator === 'is_empty' || $operator === 'is_not_empty' ) {
            $ids = get_posts( [
                'post_type'      => 'product',
                'post_status'    => self::$status_scope,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    'taxonomy' => $taxonomy,
                    'operator' => $operator === 'is_not_empty' ? 'EXISTS' : 'NOT EXISTS',
                ] ],
            ] );
            return array_map( 'intval', $ids );
        }

        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        $matched_term_ids = [];
        foreach ( $terms as $term ) {
            if ( self::eval_operator( $term->name, $operator, $value, $case_sensitive )
                || self::eval_operator( $term->slug, $operator, $value, $case_sensitive ) ) {
                $matched_term_ids[] = (int) $term->term_id;
            }
        }

        if ( empty( $matched_term_ids ) ) {
            return [];
        }

        $ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => self::$status_scope,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                'taxonomy'         => $taxonomy,
                'field'            => 'term_id',
                'terms'            => $matched_term_ids,
                // A product's own terms only, like evaluate_taxonomy_condition().
                // WP's default would also pull in every child term's products.
                'include_children' => false,
            ] ],
        ] );

        return array_map( 'intval', $ids );
    }

    /**
     * Resolve matched product IDs for a condition sourced from a WordPress
     * post field or postmeta value (POSTMETA_SOURCE) — the other app-data
     * condition source alongside get_product_ids_by_taxonomy_condition().
     * $field is either a POST_FIELD_MAP key (a real wp_posts column) or a
     * literal postmeta key. Fetches every published product's value for the
     * field/key in one query and filters in PHP with eval_operator() — the
     * same shape as the supplier-feed path (get_product_ids_by_source_field_value())
     * and the taxonomy path, both of which scan every candidate and filter
     * in PHP rather than building a per-operator SQL LIKE/comparison clause.
     *
     * @param  string $field     POST_FIELD_MAP key or a literal postmeta key.
     * @param  string $operator  One of VALID_OPERATORS.
     * @param  string $value     Value to compare against (unused for is_empty/is_not_empty).
     * @return int[]
     */
    private static function get_product_ids_by_postmeta_condition( string $field, string $operator, string $value, bool $case_sensitive = false ): array {
        global $wpdb;
        $status_sql = self::status_sql();
        $status_sql_bare = self::status_sql( '' );

        if ( $field === '' ) {
            return [];
        }

        if ( isset( self::POST_FIELD_MAP[ $field ] ) ) {
            $column = self::POST_FIELD_MAP[ $field ];
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                "SELECT ID, {$column} AS val FROM {$wpdb->posts} WHERE post_type = 'product' AND {$status_sql_bare}",
                ARRAY_A
            );
            // phpcs:enable
        } else {
            // Literal postmeta key — LEFT JOIN so a product with no row at all
            // for this key still appears once with val = NULL (needed for
            // is_empty/is_not_empty to distinguish "absent" from "empty string",
            // matching eval_operator()'s existing null-means-absent convention).
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, pm.meta_value AS val
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
                     WHERE p.post_type = 'product' AND {$status_sql}",
                    $field
                ),
                ARRAY_A
            );
        }

        $matched = [];
        foreach ( $rows as $row ) {
            $val = $row['val'] === null ? null : (string) $row['val'];
            if ( self::eval_operator( $val, $operator, $value, $case_sensitive ) ) {
                $matched[] = (int) $row['ID'];
            }
        }

        return array_values( array_unique( $matched ) );
    }

    /**
     * Evaluate a single condition against a live product's own post field or
     * postmeta value — the real-time counterpart to
     * get_product_ids_by_postmeta_condition(), used by resolve() and by
     * apply_to_catalog()/apply_catalog_page()'s Custom Rule action pass so a
     * postmeta condition's actual effect matches what the match-count preview
     * and apply paths already computed via that method.
     *
     * @param  int   $product_id  WooCommerce product ID.
     * @param  array $cond        Condition array with 'field', 'operator', 'value'.
     * @return bool
     */
    private static function evaluate_postmeta_condition( int $product_id, array $cond ): bool {
        if ( $product_id <= 0 ) {
            return false;
        }

        $field    = $cond['field']    ?? '';
        $operator = $cond['operator'] ?? 'equals';
        $value    = (string) ( $cond['value'] ?? '' );

        if ( $field === '' ) {
            return false;
        }

        if ( isset( self::POST_FIELD_MAP[ $field ] ) ) {
            $post = get_post( $product_id );
            $val  = $post ? (string) $post->{ self::POST_FIELD_MAP[ $field ] } : null;
            return self::eval_operator( $val, $operator, $value, ! empty( $cond['case_sensitive'] ) );
        }

        $exists = metadata_exists( 'post', $product_id, $field );
        $val    = $exists ? (string) get_post_meta( $product_id, $field, true ) : null;
        return self::eval_operator( $val, $operator, $value, ! empty( $cond['case_sensitive'] ) );
    }

    /**
     * Evaluate a single condition against a live product's own taxonomy
     * terms — the real-time counterpart to get_product_ids_by_taxonomy_condition(),
     * used by resolve() (the scheduled-run / bulk-apply write path) so a
     * taxonomy condition's actual effect matches what the "N matching"
     * preview and the count/single-rule-apply paths already computed via
     * that method. Both must agree — see this project's own AGENTS.md
     * incident history on preview/reality divergence for why.
     *
     * @param  int   $product_id  WooCommerce product ID.
     * @param  array $cond        Condition array with 'field' (taxonomy slug), 'operator', 'value'.
     * @return bool
     */
    private static function evaluate_taxonomy_condition( int $product_id, array $cond ): bool {
        if ( $product_id <= 0 ) {
            return false;
        }

        $taxonomy = $cond['field'] ?? '';
        $operator = $cond['operator'] ?? 'equals';
        $value    = (string) ( $cond['value'] ?? '' );

        if ( $taxonomy === '' || ! taxonomy_exists( $taxonomy ) ) {
            return false;
        }

        if ( $operator === 'is_empty' || $operator === 'is_not_empty' ) {
            $has_terms = ! empty( wp_get_object_terms( $product_id, $taxonomy, [ 'fields' => 'ids' ] ) );
            return $operator === 'is_not_empty' ? $has_terms : ! $has_terms;
        }

        if ( isset( self::NEGATED_OPERATORS[ $operator ] ) ) {
            return ! self::evaluate_taxonomy_condition( $product_id, [ 'operator' => self::NEGATED_OPERATORS[ $operator ] ] + $cond );
        }

        $terms = wp_get_object_terms( $product_id, $taxonomy );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            // No terms at all — same "field absent" semantics eval_operator()
            // already applies to a supplier feed record missing this field.
            return self::eval_operator( null, $operator, $value );
        }

        foreach ( $terms as $term ) {
            if ( self::eval_operator( $term->name, $operator, $value, ! empty( $cond['case_sensitive'] ) )
                || self::eval_operator( $term->slug, $operator, $value, ! empty( $cond['case_sensitive'] ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Write a product's stock status directly to postmeta and WC's lookup table,
     * bypassing WC_Product::save() / validate_props() which recalculates
     * _stock_status from stock_quantity for managed-stock products and would
     * override any outofstock value when qty > 0.
     *
     * @param int    $product_id WooCommerce product ID.
     * @param string $status     'instock', 'outofstock', or 'onbackorder'.
     */
    public static function force_stock_status( int $product_id, string $status ): void {
        global $wpdb;

        // 1. Write the postmeta value directly.
        update_post_meta( $product_id, '_stock_status', $status );

        // 2. Keep WC's lookup table in sync so queries against it are correct.
        $wpdb->update(
            $wpdb->prefix . 'wc_product_meta_lookup',
            [ 'stock_status' => $status ],
            [ 'product_id'   => $product_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // 2b. A managed-stock product forced out of stock also gets quantity 0. Left at the
        // feed's 9999, the next ordinary WC save (admin edit, import) recalculates the status
        // from the quantity and silently puts it back in stock (seen on Carbon Pre #56258).
        if ( 'outofstock' === $status && 'yes' === get_post_meta( $product_id, '_manage_stock', true ) ) {
            update_post_meta( $product_id, '_stock', '0' );
            $wpdb->update( $wpdb->prefix . 'wc_product_meta_lookup', [ 'stock_quantity' => 0 ], [ 'product_id' => $product_id ], [ '%d' ], [ '%d' ] );
        }

        // 2c. The product_visibility "outofstock" term is what "Hide out of stock items" filters on.
        if ( 'outofstock' === $status ) {
            wp_set_object_terms( $product_id, 'outofstock', 'product_visibility', true );
        } else {
            wp_remove_object_terms( $product_id, 'outofstock', 'product_visibility' );
        }

        // 3. Purge the product object cache so the next wc_get_product() call
        //    reads the fresh postmeta rather than a stale cached object.
        wc_delete_product_transients( $product_id );
        wp_cache_delete( 'wc_product_' . $product_id, 'product' );

        \MMI_Logger::info(
            "Stock status forced: product_id={$product_id} → {$status}",
            [ 'product_id' => $product_id, 'status' => $status ],
            'sync',
            'Stock_Override_Resolver'
        );
    }

    private static function count_products_by_supplier( string $supplier ): int {
        global $wpdb;
        $status_sql = self::status_sql();

        if ( $supplier === 'native' ) {
            // "Live App Data" — products with no tracked supplier SKU at all
            // (manually created, or from a channel this plugin doesn't track).
            $meta_keys = self::native_exclusion_meta_keys();
            if ( empty( $meta_keys ) ) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                return (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     WHERE p.post_type = 'product' AND {$status_sql}"
                );
                // phpcs:enable
            }
            $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 WHERE p.post_type = 'product' AND {$status_sql}
                   AND p.ID NOT IN (
                       SELECT post_id FROM {$wpdb->postmeta}
                       WHERE meta_key IN ({$placeholders})
                         AND meta_value != ''
                   )",
                ...$meta_keys
            ) );
            // phpcs:enable
        }

        if ( $supplier === 'all' || $supplier === '' ) {
            // 'all' — every published product, supplier-tracked or not. This
            // must match apply_to_catalog()/apply_catalog_page()'s own scan
            // (wc_get_products() with no supplier filter).
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 WHERE p.post_type = 'product' AND {$status_sql}"
            );
            // phpcs:enable
        }

        // Any real, configured supplier id — generic, not just xchange/skuport.
        $meta_key = self::get_supplier_sku_meta_key( $supplier );
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                AND pm.meta_key = %s AND pm.meta_value != ''
             WHERE p.post_type = 'product' AND {$status_sql}",
            $meta_key
        ) );
        // phpcs:enable
    }

    /**
     * Return product IDs for all published WC products in a supplier scope.
     *
     * @param  string $supplier  'all', 'xchange', or 'skuport'.
     * @return int[]
     */
    private static function get_product_ids_by_supplier( string $supplier ): array {
        global $wpdb;
        $status_sql = self::status_sql();

        if ( $supplier === 'native' ) {
            // "Live App Data" — products with no tracked supplier SKU at all.
            $meta_keys = self::native_exclusion_meta_keys();
            if ( empty( $meta_keys ) ) {
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $ids = $wpdb->get_col(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     WHERE p.post_type = 'product' AND {$status_sql}"
                );
                // phpcs:enable
                return array_map( 'intval', $ids ?? [] );
            }
            $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 WHERE p.post_type = 'product' AND {$status_sql}
                   AND p.ID NOT IN (
                       SELECT post_id FROM {$wpdb->postmeta}
                       WHERE meta_key IN ({$placeholders})
                         AND meta_value != ''
                   )",
                ...$meta_keys
            ) );
            // phpcs:enable
            return array_map( 'intval', $ids ?? [] );
        }

        if ( $supplier === 'all' || $supplier === '' ) {
            // Every published product, supplier-tracked or not (see the
            // identical note in count_products_by_supplier()).
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $ids = $wpdb->get_col(
                "SELECT p.ID FROM {$wpdb->posts} p
                 WHERE p.post_type = 'product' AND {$status_sql}"
            );
            // phpcs:enable
            return array_map( 'intval', $ids ?? [] );
        }

        // Any real, configured supplier id — generic, not just xchange/skuport.
        $meta_key = self::get_supplier_sku_meta_key( $supplier );
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                AND pm.meta_key = %s AND pm.meta_value != ''
             WHERE p.post_type = 'product' AND {$status_sql}",
            $meta_key
        ) );
        // phpcs:enable

        return array_map( 'intval', $ids ?? [] );
    }

    /* ── Condition evaluation ─────────────────────────────────────────────── */

    /**
     * Return true when a raw feed item (and, for TAXONOMY_SOURCE conditions,
     * the live product itself) satisfies the conditions of a normalised
     * rule. Empty conditions array = unconditional match.
     *
     * @param  array $rule       Normalised rule (from normalize_rule()).
     * @param  array $raw_item   Raw supplier JSON record.
     * @param  int   $product_id WooCommerce product ID — only needed for
     *                           TAXONOMY_SOURCE conditions; 0 is fine for
     *                           rules that don't use any (the legacy
     *                           per-item scan caller never has one to pass).
     * @return bool
     */
    /**
     * Public entry point for evaluate_conditions() — whether a rule (any
     * shape normalize_rule() accepts) matches a given product/raw feed item.
     * Used by apply_to_catalog()/apply_catalog_page()'s Custom Rule action
     * pass to decide whether a non-stock rule applies to a product, the same
     * matching logic resolve() already uses for stock rules.
     *
     * @param  array $rule       Rule array (old or new format).
     * @param  int   $product_id WooCommerce product ID.
     * @param  array $raw_item   Raw supplier feed record for this product.
     * @return bool
     */
    public static function rule_matches_product( array $rule, int $product_id, array $raw_item, array $context = [] ): bool {
        return self::evaluate_conditions( self::normalize_rule( $rule ), $raw_item, $product_id, $context );
    }

    /**
     * @param array $context Extra values a condition can read: 'field_value'
     *                       for FIELD_VALUE_SOURCE (Field Mapping only).
     */
    private static function evaluate_conditions( array $rule, array $raw_item, int $product_id = 0, array $context = [] ): bool {
        $conditions  = $rule['conditions']  ?? [];
        $match_logic = $rule['match_logic'] ?? 'all';

        if ( empty( $conditions ) ) {
            return true; // no conditions = apply to all
        }

        $results = [];
        foreach ( $conditions as $cond ) {
            $cond_source = $cond['source'] ?? '';
            if ( $cond_source === self::TAXONOMY_SOURCE ) {
                $results[] = self::evaluate_taxonomy_condition( $product_id, $cond );
                continue;
            }
            if ( $cond_source === self::POSTMETA_SOURCE ) {
                $results[] = self::evaluate_postmeta_condition( $product_id, $cond );
                continue;
            }
            if ( $cond_source === self::FIELD_VALUE_SOURCE ) {
                $own = $context['field_value'] ?? null;
                $results[] = self::eval_operator(
                    ( $own === null || is_array( $own ) ) ? null : (string) $own,
                    $cond['operator'] ?? 'equals',
                    (string) ( $cond['value'] ?? '' ),
                    ! empty( $cond['case_sensitive'] )
                );
                continue;
            }

            $field    = $cond['field']    ?? '';
            $operator = $cond['operator'] ?? 'equals';
            $value    = (string) ( $cond['value'] ?? '' );

            // An array-shaped feed value (e.g. Xchange's "categories"/
            // "top_features") has no flat string representation this
            // operator set can meaningfully compare against — treated as
            // absent (null), same as a field missing entirely, rather than
            // letting PHP's (string) cast silently coerce it to the literal
            // string "Array" and compare against that. See
            // Custom_Rule_Actions::apply_copy_source_field_to_meta() for the
            // sibling instance of this same bug in the action layer.
            $raw_field_val = $raw_item[ $field ] ?? null;
            $field_val     = ( $raw_field_val !== null && ! is_array( $raw_field_val ) ) ? (string) $raw_field_val : null;

            $results[] = self::eval_operator( $field_val, $operator, $value, ! empty( $cond['case_sensitive'] ) );
        }

        return $match_logic === 'any'
            ? in_array( true, $results, true )
            : ! in_array( false, $results, true );
    }

    /**
     * Evaluate a single operator comparison.
     *
     * @param  string|null $field_val  Value from the raw item (null = field absent).
     * @param  string      $operator   One of VALID_OPERATORS.
     * @param  string      $value      Target comparison value.
     * @return bool
     */
    private static function eval_operator( ?string $field_val, string $operator, string $value, bool $case_sensitive = false ): bool {
        // String comparisons are case-insensitive unless the condition asks
        // otherwise — a marketing tag like "PROMO" can appear as
        // "Promo"/"promo" depending on which supplier or admin typed the
        // title, and a case-sensitive "contains" silently returning 0
        // matches (while WP's own admin search, which IS case-insensitive,
        // finds the same products) is a confusing trap for that common case.
        // A condition's "Match case" box sets $case_sensitive when the
        // distinction matters (e.g. "Bundle" the product type vs. "bundle"
        // inside a description).
        switch ( $operator ) {
            case 'equals':
                return $field_val !== null && self::str_compare( $field_val, $value, $case_sensitive ) === 0;
            case 'not_equals':
                return $field_val === null || self::str_compare( $field_val, $value, $case_sensitive ) !== 0;
            case 'contains':
                return $field_val !== null && $value !== '' && self::str_find( $field_val, $value, $case_sensitive ) !== false;
            case 'not_contains':
                return $field_val === null || $value === '' || self::str_find( $field_val, $value, $case_sensitive ) === false;
            case 'starts_with':
                return $field_val !== null && self::str_find( $field_val, $value, $case_sensitive ) === 0;
            case 'ends_with':
                // Compare the tail directly: the old first-occurrence
                // position check missed "Bundle Pro Bundle", and mixed a
                // multibyte character offset with a byte length.
                return $field_val !== null && $value !== ''
                    && self::str_compare( self::str_tail( $field_val, $value ), $value, $case_sensitive ) === 0;
            case 'is_empty':
                return $field_val === null || $field_val === '';
            case 'is_not_empty':
                return $field_val !== null && $field_val !== '';
            case 'greater_than':
                return $field_val !== null && is_numeric( $field_val )
                    && ( (float) $field_val > (float) $value );
            case 'less_than':
                return $field_val !== null && is_numeric( $field_val )
                    && ( (float) $field_val < (float) $value );
            case 'in_list':
                if ( $field_val === null ) {
                    return false;
                }
                foreach ( self::split_list_value( $value ) as $item ) {
                    if ( self::str_compare( $field_val, $item, $case_sensitive ) === 0 ) {
                        return true;
                    }
                }
                return false;
            case 'not_in_list':
                if ( $field_val === null ) {
                    return true;
                }
                foreach ( self::split_list_value( $value ) as $item ) {
                    if ( self::str_compare( $field_val, $item, $case_sensitive ) === 0 ) {
                        return false;
                    }
                }
                return true;
            default:
                return false;
        }
    }

    /**
     * Split a LIST_VALUE_DELIMITER-joined value (as written by the
     * condition row checklist UI) into trimmed, non-empty parts.
     *
     * @param  string $value
     * @return string[]
     */
    private static function split_list_value( string $value ): array {
        return array_values( array_filter(
            array_map( 'trim', explode( self::LIST_VALUE_DELIMITER, $value ) ),
            static fn( $v ) => $v !== ''
        ) );
    }

    /**
     * strpos()/stripos(), multibyte-safe when possible. Returns the same
     * false|int contract as strpos().
     */
    private static function str_find( string $haystack, string $needle, bool $case_sensitive ) {
        if ( $case_sensitive ) {
            return function_exists( 'mb_strpos' ) ? mb_strpos( $haystack, $needle ) : strpos( $haystack, $needle );
        }
        return function_exists( 'mb_stripos' ) ? mb_stripos( $haystack, $needle ) : stripos( $haystack, $needle );
    }

    /** strcmp()/strcasecmp() — 0 when equal. */
    private static function str_compare( string $a, string $b, bool $case_sensitive ): int {
        return $case_sensitive ? strcmp( $a, $b ) : strcasecmp( $a, $b );
    }

    /** The last N characters of $haystack, N being $needle's length. */
    private static function str_tail( string $haystack, string $needle ): string {
        return function_exists( 'mb_substr' )
            ? mb_substr( $haystack, -mb_strlen( $needle ) )
            : substr( $haystack, -strlen( $needle ) );
    }

    /* ── Constructor ─────────────────────────────────────────────────────── */

    /**
     * @param array $bulk_rules  Array of rule arrays (see shape above).
     *                           Pass an empty array to skip bulk evaluation.
     */
    public function __construct( private array $bulk_rules = [] ) {}

    /* ── Public API ──────────────────────────────────────────────────────── */

    /**
     * Resolve the final WooCommerce stock status for a single product.
     *
     * @param int    $product_id    WooCommerce post ID; 0 for a product not yet created.
     * @param string $source_status Stock status from supplier data ('instock'|'outofstock').
     * @param array  $raw_item      The raw supplier JSON record (for flag_match rules).
     * @param string $supplier      Supplier identifier string (e.g. 'xchange', 'skuport').
     *
     * @return string Final stock status — 'instock' or 'outofstock'.
     */
    public function resolve( int $product_id, string $source_status, array $raw_item, string $supplier ): string {

        // ── Tier 1: per-product postmeta ──────────────────────────────────
        if ( $product_id > 0 ) {
            $meta = get_post_meta( $product_id, '_mmi_stock_override', true );
            if ( in_array( $meta, self::VALID_OVERRIDES, true ) ) {
                return $this->override_to_status( $meta );
            }
        }

        // ── Tier 2: bulk rules (first match wins) ─────────────────────────
        foreach ( $this->bulk_rules as $rule ) {
            if ( ! $this->rule_matches_supplier( $rule, $supplier ) ) {
                continue;
            }

            $normalised = self::normalize_rule( $rule );
            $action     = $normalised['action'] ?? '';

            if ( empty( $normalised['enabled'] ) ) {
                continue;
            }

            // Only stock actions participate in this tiered, mutually-exclusive
            // real-time resolution — every other Custom Rule action type is a
            // post-import catalog-maintenance action, applied only via
            // apply_to_catalog()/apply_catalog_page()'s additive second pass.
            if ( ! in_array( $action, self::VALID_OVERRIDES, true ) ) {
                continue;
            }

            if ( self::evaluate_conditions( $normalised, $raw_item, $product_id ) ) {
                return $this->override_to_status( $action );
            }
        }

        // ── Tier 3: source data ───────────────────────────────────────────
        return $source_status;
    }

    /* ── Bulk catalog application ────────────────────────────────────────── */

    /**
     * Apply override rules to every WooCommerce product in the database.
     *
     * Used by the catalog-update override_stock phase. Loads supplier JSON feeds
     * so that flag_match rules are evaluated against real product data — the same
     * behaviour as apply_catalog_page().  Previously this method passed an empty
     * raw_item array, which silently skipped all flag_match rules and caused
     * overrides to appear as though they weren't being respected after a
     * feed_sync phase had restored products to in-stock.
     *
     * @param  array $rules  Array of rule arrays (same shape as the constructor).
     * @return array         ['processed' => int, 'changed' => int]
     */
    public static function apply_to_catalog( array $rules ): array {
        if ( empty( $rules ) ) {
            return [ 'processed' => 0, 'changed' => 0 ];
        }

        wp_raise_memory_limit( 'wc' );

        // Load supplier feed data once so flag_match rules can be evaluated.
        $feed_lookup = self::build_feed_lookup_for_rules( $rules );

        $resolver  = new self( $rules );
        $processed = 0;
        $changed   = 0;
        $page      = 1;

        do {
            $products = wc_get_products( [
                'status'  => 'publish',
                'limit'   => 200,
                'page'    => $page,
                'return'  => 'objects',
            ] );

            if ( empty( $products ) ) {
                break;
            }

            foreach ( $products as $product ) {
                $product_id     = $product->get_id();
                $current_status = $product->get_stock_status();

                // Detect supplier and look up the matching raw feed item so that
                // flag_match rules can be evaluated (same logic as apply_catalog_page).
                $detected         = self::detect_product_supplier( $product_id );
                $supplier         = $detected['supplier'];
                $feed_meta_key    = $detected['feed_meta_key'];
                $supplier_sku_val = $detected['supplier_sku_val'];

                $raw_item = ( $feed_meta_key !== '' && $supplier_sku_val !== '' )
                    ? ( $feed_lookup[ $feed_meta_key ][ $supplier_sku_val ] ?? [] )
                    : [];

                $resolved = $resolver->resolve( $product_id, $current_status, $raw_item, $supplier );
                $processed++;

                if ( $resolved !== $current_status ) {
                    // Write _stock_status directly to postmeta + lookup table
                    // so WC's validate_props() — which recalculates from qty
                    // during save() — never runs and cannot override our value.
                    self::force_stock_status( $product_id, $resolved );
                    $changed++;
                }

                if ( self::apply_custom_rule_actions( $rules, $product_id, $raw_item, $supplier ) ) {
                    $changed++;
                }
            }

            // Every product object, its postmeta and its terms stay in WP's
            // in-process object cache for the rest of the request — ~10 MB
            // per 200-product page, never released. Across the full catalog
            // that crossed the 512 MB limit around page 22 and killed every
            // scheduled override_stock phase from 2026-09-27 on. Only the
            // per-request copy is dropped; Redis is untouched.
            $page_size = count( $products );
            unset( $products );
            wp_cache_flush_runtime();

            $page++;
        } while ( $page_size === 200 );

        return [ 'processed' => $processed, 'changed' => $changed ];
    }

    /**
     * The additive second pass behind apply_to_catalog()/apply_catalog_page():
     * every non-stock Custom Rule action (taxonomy, post status, pricing,
     * managed stock, backorders, ...) whose conditions match this product is
     * applied independently — unlike the stock tier system above, these
     * aren't mutually exclusive with each other, so every matching rule
     * applies rather than only the first.
     *
     * @param  array  $rules      Full rule list for this bulk-apply run.
     * @param  int    $product_id
     * @param  array  $raw_item   Raw supplier feed record for this product.
     * @param  string $supplier
     * @return bool               True if any rule actually changed the product.
     */
    private static function apply_custom_rule_actions( array $rules, int $product_id, array $raw_item, string $supplier ): bool {
        $changed = false;

        foreach ( $rules as $rule ) {
            $rule_supplier = is_array( $rule ) ? ( $rule['supplier'] ?? 'all' ) : 'all';
            if ( $rule_supplier !== 'all' && $rule_supplier !== $supplier ) {
                continue;
            }

            $normalised = self::normalize_rule( (array) $rule );
            $action     = $normalised['action'] ?? '';

            if ( empty( $normalised['enabled'] ) ) {
                continue;
            }

            if ( $action === '' || Custom_Rule_Actions::is_stock_action( $action ) || ! Custom_Rule_Actions::is_valid( $action ) ) {
                continue;
            }

            if ( ! self::rule_matches_product( $normalised, $product_id, $raw_item ) ) {
                continue;
            }

            if ( Custom_Rule_Actions::apply( $product_id, $action, $normalised['action_params'] ?? [], $raw_item ) ) {
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Apply override rules to a single page of WooCommerce products.
     *
     * Used by the "Apply Now" AJAX button to process large catalogs in chunks
     * without hitting PHP execution time limits. The caller should increment
     * `$page` and repeat until `has_more` is false.
     *
     * On `$page === 1` a total product count is included in the response so the
     * caller can display a deterministic progress bar.
     *
     * @param  array $rules       Array of rule arrays (same shape as the constructor).
     * @param  int   $page        1-based page number.
     * @param  int   $per_page    Products per page (default 200).
     * @param  int[] $product_ids Optional pre-filtered product ID list. When provided,
     *                            only these products are processed (paginated by slicing
     *                            the array). Omit or pass [] for the normal full-catalog scan.
     * @return array {
     *   processed_this_page: int,
     *   changed_this_page:   int,
     *   total_products:      int|null  — always set when $product_ids provided; page 1 only for full scan,
     *   has_more:            bool,
     *   error?:              string    — present only on exception,
     * }
     */
    public static function apply_catalog_page( array $rules, int $page = 1, int $per_page = 200, array $product_ids = [] ): array {
        if ( empty( $rules ) ) {
            return [
                'processed_this_page' => 0,
                'changed_this_page'   => 0,
                'total_products'      => 0,
                'has_more'            => false,
            ];
        }

        try {
            wp_raise_memory_limit( 'wc' );

            $feed_lookup    = self::build_feed_lookup_for_rules( $rules );
            $resolver       = new self( $rules );
            $processed      = 0;
            $changed        = 0;
            $using_filter   = ! empty( $product_ids );
            $total_products = null;

            if ( $using_filter ) {
                // ── Pre-filtered mode: slice the ID array for this page ───
                $total_products = count( $product_ids );
                $offset         = ( $page - 1 ) * $per_page;
                $chunk          = array_slice( $product_ids, $offset, $per_page );

                if ( empty( $chunk ) ) {
                    return [
                        'processed_this_page' => 0,
                        'changed_this_page'   => 0,
                        'total_products'      => $total_products,
                        'has_more'            => false,
                    ];
                }

                $products = wc_get_products( [
                    'status'  => 'publish',
                    'limit'   => -1,
                    'include' => $chunk,
                    'return'  => 'objects',
                ] );
                $has_more = ( $offset + $per_page ) < $total_products;
            } else {
                // ── Full-catalog scan mode ─────────────────────────────── 
                if ( $page === 1 ) {
                    $count_result   = wc_get_products( [
                        'status'   => 'publish',
                        'limit'    => 1,
                        'paginate' => true,
                    ] );
                    $total_products = (int) ( $count_result->total ?? 0 );
                }

                $products = wc_get_products( [
                    'status'  => 'publish',
                    'limit'   => $per_page,
                    'page'    => $page,
                    'return'  => 'objects',
                ] );
                $has_more = count( $products ) === $per_page;
            }

            if ( ! empty( $products ) ) {
                foreach ( $products as $product ) {
                    $product_id     = $product->get_id();
                    $current_status = $product->get_stock_status();

                    // Detect supplier and retrieve the matching raw feed item
                    // so that flag_match rules can be evaluated correctly.
                    $detected         = self::detect_product_supplier( $product_id );
                    $supplier         = $detected['supplier'];
                    $feed_meta_key    = $detected['feed_meta_key'];
                    $supplier_sku_val = $detected['supplier_sku_val'];

                    $raw_item = ( $feed_meta_key !== '' && $supplier_sku_val !== '' )
                        ? ( $feed_lookup[ $feed_meta_key ][ $supplier_sku_val ] ?? [] )
                        : [];

                    $resolved = $resolver->resolve( $product_id, $current_status, $raw_item, $supplier );
                    $processed++;

                    if ( $resolved !== $current_status ) {
                        // Write _stock_status directly to postmeta + lookup table
                        // so WC's validate_props() — which recalculates from qty
                        // during save() — never runs and cannot override our value.
                        self::force_stock_status( $product_id, $resolved );
                        $changed++;
                    }

                    if ( self::apply_custom_rule_actions( $rules, $product_id, $raw_item, $supplier ) ) {
                        $changed++;
                    }
                }
            }

            return [
                'processed_this_page' => $processed,
                'changed_this_page'   => $changed,
                'total_products'      => $total_products,
                'has_more'            => $has_more,
            ];

        } catch ( \Throwable $e ) {
            return [
                'processed_this_page' => 0,
                'changed_this_page'   => 0,
                'total_products'      => null,
                'has_more'            => false,
                'error'               => $e->getMessage(),
            ];
        }
    }

    /**
     * Return a per-rule compliance report for the dashboard.
     *
     * For each rule with `condition = 'all'`, a direct DB query counts how many
     * published WooCommerce products in the rule's supplier scope already have the
     * expected stock status, producing compliant / non-compliant / overridden totals.
     *
     * Flag-match rules are marked 'unavailable' because compliance requires live feed
     * data that is only present during an import run.
     *
     * @param  array $rules  Array of rule arrays (same shape as the constructor).
     * @return array         Indexed 0…n matching rule positions:
     *                       - status = 'unavailable' | 'ok' | 'error'
     *                       - (ok)  total, compliant, non_compliant, overridden, pct
     *                       - (error) message
     */
    public static function get_compliance_report( array $rules ): array {
        global $wpdb;

        $report = [];

        foreach ( $rules as $idx => $rule ) {
            $norm       = self::normalize_rule( $rule );
            $conditions = $norm['conditions'] ?? [];
            $action     = $norm['action'] ?? '';

            // A "compliant %" figure only means anything for the two stock
            // actions — every other Custom Rule action type (taxonomy,
            // price, post status, ...) has no notion of a live stock-status
            // compliance percentage, so it's marked unavailable rather than
            // computing a meaningless number.
            if ( ! in_array( $action, self::VALID_OVERRIDES, true ) ) {
                $report[ $idx ] = [ 'status' => 'unavailable' ];
                continue;
            }

            if ( ! empty( $conditions ) ) {
                // Single equals condition → feed-based compliance (same as old flag_match).
                $simple = count( $conditions ) === 1
                    && ( $conditions[0]['operator'] ?? '' ) === 'equals';

                if ( $simple ) {
                    $compat_rule = [
                        'supplier'    => $norm['supplier'],
                        'condition'   => 'flag_match',
                        'flag'        => $conditions[0]['field'],
                        'flag_value'  => $conditions[0]['value'] ?? '',
                        'action'      => $action,
                    ];
                    try {
                        $report[ $idx ] = self::get_flag_match_compliance( $compat_rule );
                    } catch ( \Throwable $e ) {
                        $report[ $idx ] = [ 'status' => 'error', 'message' => $e->getMessage() ];
                    }
                } else {
                    // Multi-condition or non-equals operator requires live feed data.
                    $report[ $idx ] = [ 'status' => 'unavailable' ];
                }
                continue;
            }

            // No conditions = apply to all products → SQL-based compliance check.
            try {
                $supplier        = $norm['supplier'] ?? 'all';
                $expected_status = ( $action === 'force_instock' ) ? 'instock' : 'outofstock';

                // Build supplier scope subquery — generic across every configured
                // supplier, not just Xchange/SkuPort (see get_all_supplier_ids()).
                // Note: 'native' isn't distinguished from 'all' here (pre-existing
                // gap, not introduced by this generalization) — both currently
                // require "has at least one tracked supplier SKU," which is the
                // opposite of what 'native' ("no supplier at all") should mean.
                if ( $supplier !== 'all' && $supplier !== 'native' && in_array( $supplier, self::get_all_supplier_ids(), true ) ) {
                    $meta_key      = self::get_supplier_sku_meta_key( $supplier );
                    $supplier_join = $wpdb->prepare(
                        "INNER JOIN {$wpdb->postmeta} pm_sup
                               ON pm_sup.post_id = p.ID
                              AND pm_sup.meta_key = %s
                              AND pm_sup.meta_value != ''",
                        $meta_key
                    );
                } else {
                    // 'all' (and, for now, 'native') — product must have at least
                    // one tracked supplier SKU, from any configured supplier.
                    $meta_keys = self::native_exclusion_meta_keys();
                    if ( empty( $meta_keys ) ) {
                        $supplier_join = '';
                    } else {
                        $placeholders  = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
                        $supplier_join = $wpdb->prepare(
                            "INNER JOIN {$wpdb->postmeta} pm_sup
                                   ON pm_sup.post_id = p.ID
                                  AND pm_sup.meta_key IN ({$placeholders})
                                  AND pm_sup.meta_value != ''",
                            ...$meta_keys
                        );
                    }
                }

                $expected = esc_sql( $expected_status );

                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $row = $wpdb->get_row(
                    "SELECT
                         COUNT(DISTINCT p.ID)                                                    AS total,
                         COUNT(DISTINCT CASE WHEN st.meta_value = '{$expected}'
                                             AND ppo.meta_value IS NULL  THEN p.ID END)          AS compliant,
                         COUNT(DISTINCT CASE WHEN st.meta_value != '{$expected}'
                                             AND ppo.meta_value IS NULL  THEN p.ID END)          AS non_compliant,
                         COUNT(DISTINCT CASE WHEN ppo.meta_value IS NOT NULL THEN p.ID END)      AS overridden
                     FROM {$wpdb->posts} p
                     {$supplier_join}
                     LEFT JOIN {$wpdb->postmeta} st
                           ON st.post_id  = p.ID
                          AND st.meta_key = '_stock_status'
                     LEFT JOIN {$wpdb->postmeta} ppo
                           ON ppo.post_id  = p.ID
                          AND ppo.meta_key = '_mmi_stock_override'
                          AND ppo.meta_value IN ('force_instock','force_outofstock')
                     WHERE p.post_type   = 'product'
                       AND p.post_status = 'publish'",
                    ARRAY_A
                );
                // phpcs:enable

                $total        = (int) ( $row['total']        ?? 0 );
                $compliant    = (int) ( $row['compliant']    ?? 0 );
                $non_compliant = (int) ( $row['non_compliant'] ?? 0 );
                $overridden   = (int) ( $row['overridden']   ?? 0 );
                $pct          = $total > 0 ? (int) round( ( $compliant / $total ) * 100 ) : 0;

                $report[ $idx ] = [
                    'status'        => 'ok',
                    'total'         => $total,
                    'compliant'     => $compliant,
                    'non_compliant' => $non_compliant,
                    'overridden'    => $overridden,
                    'pct'           => $pct,
                ];

            } catch ( \Throwable $e ) {
                $report[ $idx ] = [
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $report;
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    /**
     * Build a sku → raw-item lookup table from supplier JSON feeds, but only
     * when at least one rule uses condition = 'flag_match'.  Called once per
     * apply_catalog_page() AJAX request so the feed file is read at most once
     * per chunk regardless of how many products are in that chunk.
     *
     * Return shape:
     *   [
     *     '_mmi_supplier_sku_xchange' => [ 'SKU123' => [...raw item...], ... ],
     *     '_mmi_supplier_sku_skuport' => [ 'SKU456' => [...raw item...], ... ],
     *   ]
     *
     * Returns an empty array when no flag_match rules exist (fast path).
     *
     * @param  array $rules  Array of rule arrays.
     * @return array
     */
    private static function build_feed_lookup_for_rules( array $rules ): array {
        $needs_feed = false;
        foreach ( $rules as $rule ) {
            if ( ! empty( self::normalize_rule( $rule )['conditions'] ) ) {
                $needs_feed = true;
                break;
            }
        }
        if ( ! $needs_feed ) {
            return [];
        }

        $json_path = mmi_shared_lib_json_dir();

        $supplier_map = [];
        foreach ( self::get_all_supplier_ids() as $sid ) {
            $supplier_map[ self::get_supplier_sku_meta_key( $sid ) ] = $sid . '-products.json';
        }

        $lookup = [];

        foreach ( $supplier_map as $meta_key => $filename ) {
            $file = $json_path . $filename;
            if ( ! file_exists( $file ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $data = json_decode( file_get_contents( $file ), true );
            if ( ! is_array( $data ) ) {
                continue;
            }

            $items = $data['products'] ?? $data['items'] ?? ( isset( $data[0] ) ? $data : [] );

            foreach ( $items as $item ) {
                $sku = $item['sku'] ?? $item['product']['sku'] ?? $item['id'] ?? null;
                if ( $sku !== null && $sku !== '' ) {
                    $lookup[ $meta_key ][ (string) $sku ] = $item;
                }
            }
        }

        return $lookup;
    }

    /**
     * Compute compliance for a single flag_match rule by scanning the supplier
     * JSON feed(s) to find products whose flag field matches the rule's target
     * value, then querying the DB for those products' current stock status.
     *
     * Uses the same flag comparison (string cast) as the live resolver.
     *
     * @param  array $rule  A single rule array with condition = 'flag_match'.
     * @return array        Same shape as get_compliance_report()'s per-rule result.
     */
    private static function get_flag_match_compliance( array $rule ): array {
        global $wpdb;

        $supplier          = $rule['supplier']    ?? 'all';
        $flag              = $rule['flag']        ?? '';
        $flag_value_target = $rule['flag_value']  ?? '';
        $action            = $rule['action']      ?? '';
        $expected_status   = ( $action === 'force_instock' ) ? 'instock' : 'outofstock';

        // Cannot compute without a flag field name
        if ( $flag === '' ) {
            return [ 'status' => 'unavailable' ];
        }

        $json_path = mmi_shared_lib_json_dir();

        // Map supplier slug → [file, sku_meta_key] — every configured supplier,
        // not just Xchange/SkuPort.
        $supplier_map = [];
        foreach ( self::get_all_supplier_ids() as $sid ) {
            $supplier_map[ $sid ] = [ 'file' => $sid . '-products.json', 'sku_meta' => self::get_supplier_sku_meta_key( $sid ) ];
        }

        $sources = ( $supplier !== 'all' && isset( $supplier_map[ $supplier ] ) )
            ? [ $supplier => $supplier_map[ $supplier ] ]
            : $supplier_map;

        // Collect SKUs of products that match the flag condition, grouped by
        // the postmeta key used to identify them in the WC database.
        // [ '_mmi_supplier_sku_xchange' => ['sku1','sku2',...], ... ]
        $skus_by_meta = [];

        foreach ( $sources as $sup_info ) {
            $file = $json_path . $sup_info['file'];
            if ( ! file_exists( $file ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $data = json_decode( file_get_contents( $file ), true );
            if ( ! is_array( $data ) ) {
                continue;
            }

            $items    = $data['products'] ?? $data['items'] ?? ( isset( $data[0] ) ? $data : [] );
            $meta_key = $sup_info['sku_meta'];

            foreach ( $items as $item ) {
                // Match using the same string-cast logic as resolve()'s flag_match branch
                $item_flag_val = (string) ( $item[ $flag ] ?? '' );
                if ( $item_flag_val !== (string) $flag_value_target ) {
                    continue;
                }

                $sku = $item['sku'] ?? $item['product']['sku'] ?? $item['id'] ?? null;
                if ( $sku === null || $sku === '' ) {
                    continue;
                }

                $skus_by_meta[ $meta_key ][] = (string) $sku;
            }

            if ( ! empty( $skus_by_meta[ $meta_key ] ) ) {
                $skus_by_meta[ $meta_key ] = array_unique( $skus_by_meta[ $meta_key ] );
            }
        }

        if ( empty( $skus_by_meta ) ) {
            return [
                'status'        => 'ok',
                'total'         => 0,
                'compliant'     => 0,
                'non_compliant' => 0,
                'overridden'    => 0,
                'pct'           => 0,
            ];
        }

        // Build dynamic IN clauses for each meta_key → SKU set.
        // e.g. ( pm_sup.meta_key = %s AND pm_sup.meta_value IN (%s,%s,...) )
        $or_clauses = [];
        $prepare_args = [];

        foreach ( $skus_by_meta as $meta_key => $skus ) {
            $in_placeholders  = implode( ',', array_fill( 0, count( $skus ), '%s' ) );
            $or_clauses[]     = "( pm_sup.meta_key = %s AND pm_sup.meta_value IN ({$in_placeholders}) )";
            $prepare_args[]   = $meta_key;
            $prepare_args     = array_merge( $prepare_args, $skus );
        }

        $supplier_clause = implode( ' OR ', $or_clauses );
        $expected        = esc_sql( $expected_status );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT
                 COUNT(DISTINCT p.ID)                                                                   AS total,
                 COUNT(DISTINCT CASE WHEN st.meta_value = '{$expected}'
                                     AND ppo.meta_value IS NULL  THEN p.ID END)                         AS compliant,
                 COUNT(DISTINCT CASE WHEN (st.meta_value IS NULL OR st.meta_value != '{$expected}')
                                     AND ppo.meta_value IS NULL  THEN p.ID END)                         AS non_compliant,
                 COUNT(DISTINCT CASE WHEN ppo.meta_value IS NOT NULL THEN p.ID END)                     AS overridden
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_sup
                    ON pm_sup.post_id = p.ID
                   AND ( {$supplier_clause} )
             LEFT JOIN {$wpdb->postmeta} st
                    ON st.post_id  = p.ID
                   AND st.meta_key = '_stock_status'
             LEFT JOIN {$wpdb->postmeta} ppo
                    ON ppo.post_id  = p.ID
                   AND ppo.meta_key = '_mmi_stock_override'
                   AND ppo.meta_value IN ('force_instock','force_outofstock')
             WHERE p.post_type   = 'product'
               AND p.post_status = 'publish'",
            ...$prepare_args
        );
        // phpcs:enable

        $row           = $wpdb->get_row( $sql, ARRAY_A );
        $total         = (int) ( $row['total']         ?? 0 );
        $compliant     = (int) ( $row['compliant']     ?? 0 );
        $non_compliant = (int) ( $row['non_compliant'] ?? 0 );
        $overridden    = (int) ( $row['overridden']    ?? 0 );
        $pct           = $total > 0 ? (int) round( ( $compliant / $total ) * 100 ) : 0;

        return [
            'status'        => 'ok',
            'total'         => $total,
            'compliant'     => $compliant,
            'non_compliant' => $non_compliant,
            'overridden'    => $overridden,
            'pct'           => $pct,
        ];
    }

    /**
     * Returns true when the rule applies to the given supplier.
     * A rule with supplier = 'all' matches every supplier.
     */
    private function rule_matches_supplier( array $rule, string $supplier ): bool {
        $rule_supplier = $rule['supplier'] ?? '';
        // A rule scoped to 'native' ("Live App Data") matches only products
        // apply_to_catalog()/apply_catalog_page() detected as having no
        // tracked supplier SKU — those callers pass 'all' as $supplier for
        // exactly that case (see their own supplier-detection comment).
        if ( $rule_supplier === 'native' ) {
            return $supplier === 'all';
        }
        return $rule_supplier === 'all' || $rule_supplier === $supplier;
    }

    /**
     * Map an override string to a WooCommerce stock status string.
     * Falls back to 'outofstock' for unrecognised values.
     */
    private function override_to_status( string $override ): string {
        return match ( $override ) {
            'force_instock'    => 'instock',
            'force_outofstock' => 'outofstock',
            default            => 'outofstock',
        };
    }
}
