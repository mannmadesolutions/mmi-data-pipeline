<?php
/**
 * Product Workbench — data-health checks
 *
 * Each check is a named condition set ("No brand", "Thin description" …)
 * written in the ordinary Custom Rule condition shape, so one definition
 * serves three places that must agree:
 *   - "Load saved conditions" in every condition builder (group "Data
 *     health"), via the 'mmi_condition_library' filter;
 *   - the Health column in Workbench results (which checks a product fails);
 *   - sorting by that column.
 * Matching is Stock_Override_Resolver's, like every other Workbench search.
 *
 * Sites add or adjust checks through the 'mmi_pipeline_health_checks'
 * filter — a store whose GTINs live in another meta key, or a check that
 * only makes sense for its own category tree.
 *
 * @package MannMade\DataPipeline\Workbench
 */

namespace MannMade\DataPipeline\Workbench;

use MannMade\DataPipeline\Stock_Override_Resolver as Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Health_Checks {

    const LIBRARY_GROUP   = 'Data health';
    const LIBRARY_PREFIX  = 'health:';
    const MIN_DESCRIPTION = 200;

    /** @var array<string,array>|null Per-request cache of all(). */
    private static ?array $checks = null;

    /**
     * Every check, keyed by id:
     *   label       — library name and the Health chip's tooltip;
     *   chip        — short Health-column label, or '' to keep it out of that column;
     *   match_logic — 'all' | 'any';
     *   conditions  — Custom Rule conditions (an 'or' flag groups with the one before).
     *
     * @return array<string,array{label:string,chip:string,match_logic:string,conditions:array}>
     */
    public static function all(): array {
        if ( self::$checks !== null ) {
            return self::$checks;
        }

        $tax  = static fn( string $field, string $op, string $value = '' ): array => [ 'source' => Resolver::TAXONOMY_SOURCE, 'field' => $field, 'operator' => $op, 'value' => $value ];
        $meta = static fn( string $field, string $op, string $value = '' ): array => [ 'source' => Resolver::POSTMETA_SOURCE, 'field' => $field, 'operator' => $op, 'value' => $value ];
        $or   = static fn( array $cond ): array => $cond + [ 'or' => true ];
        $physical = $meta( '_virtual', 'not_equals', 'yes' );

        $checks = [
            'no_category' => [
                'label'       => 'No category (or only the default one)',
                'chip'        => 'No category',
                'match_logic' => 'any',
                'conditions'  => [ $tax( 'product_cat', 'is_empty' ), $tax( 'product_cat', 'term_default_only' ) ],
            ],
            'no_brand' => [
                'label'       => 'No brand',
                'chip'        => 'No brand',
                'match_logic' => 'all',
                'conditions'  => array_merge(
                    [ $tax( Resolver::BRAND_TAXONOMY, 'is_empty' ) ],
                    taxonomy_exists( 'pa_brand' ) ? [ $tax( 'pa_brand', 'is_empty' ) ] : []
                ),
            ],
            'no_image' => [
                'label'       => 'No featured image',
                'chip'        => 'No image',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '_thumbnail_id', 'is_empty' ) ],
            ],
            'no_gallery' => [
                'label'       => 'No gallery images',
                'chip'        => 'No gallery',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '_product_image_gallery', 'is_empty' ) ],
            ],
            'no_description' => [
                'label'       => 'No description',
                'chip'        => 'No description',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '__post_content', 'is_empty' ) ],
            ],
            'thin_description' => [
                'label'       => sprintf( 'Thin description (under %d characters)', self::MIN_DESCRIPTION ),
                'chip'        => 'Thin description',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '__post_content', 'is_not_empty' ), $meta( '__post_content', 'length_less_than', (string) self::MIN_DESCRIPTION ) ],
            ],
            'no_price' => [
                'label'       => 'No regular price (simple products)',
                'chip'        => 'No price',
                'match_logic' => 'all',
                'conditions'  => [ $tax( 'product_type', 'equals', 'simple' ), $meta( '_regular_price', 'is_empty' ) ],
            ],
            'no_sku' => [
                'label'       => 'No SKU',
                'chip'        => 'No SKU',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '_sku', 'is_empty' ) ],
            ],
            // Google Merchant Center accepts a GTIN, or brand + MPN instead.
            'no_identifier' => [
                'label'       => 'No GTIN, and no brand + MPN',
                'chip'        => 'No GTIN/MPN',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '_global_unique_id', 'is_empty' ), $meta( 'mpn', 'is_empty' ), $or( $tax( Resolver::BRAND_TAXONOMY, 'is_empty' ) ) ],
            ],
            'no_weight' => [
                'label'       => 'Physical product with no weight',
                'chip'        => 'No weight',
                'match_logic' => 'all',
                'conditions'  => [ $physical, $meta( '_weight', 'is_empty' ) ],
            ],
            'no_dimensions' => [
                'label'       => 'Physical product with no dimensions',
                'chip'        => 'No dimensions',
                'match_logic' => 'all',
                'conditions'  => [ $physical, $meta( '_length', 'is_empty' ), $or( $meta( '_width', 'is_empty' ) ), $or( $meta( '_height', 'is_empty' ) ) ],
            ],
            'no_supplier' => [
                'label'       => 'No supplier',
                'chip'        => 'No supplier',
                'match_logic' => 'all',
                'conditions'  => array_merge(
                    [ $meta( '_supplier_name', 'is_empty' ) ],
                    array_map( static fn( string $sid ): array => $meta( '_mmi_supplier_sku_' . $sid, 'is_empty' ), Resolver::supplier_ids() )
                ),
            ],
            'title_all_caps' => [
                'label'       => 'Title in ALL CAPS',
                'chip'        => 'ALL CAPS title',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '__post_title', 'matches_pattern', 'all_caps' ) ],
            ],
            'title_numeric' => [
                'label'       => 'Title is only numbers',
                'chip'        => 'Numeric title',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '__post_title', 'matches_pattern', 'numeric_only' ) ],
            ],
            'title_brand_not_first' => [
                'label'       => 'Title doesn’t start with its brand',
                'chip'        => 'Brand not first',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '__post_title', 'matches_pattern', 'brand_not_first' ) ],
            ],
            'not_published' => [
                'label'       => 'Draft, pending or private',
                'chip'        => '',
                'match_logic' => 'all',
                'conditions'  => [ $meta( '__post_status', 'in_list', 'draft|pending|private' ) ],
            ],
        ];

        /**
         * Add, change or remove data-health checks.
         *
         * @param array<string,array> $checks See all().
         */
        $checks = (array) apply_filters( 'mmi_pipeline_health_checks', $checks );

        self::$checks = array_filter( $checks, static fn( $c ) => is_array( $c ) && ! empty( $c['conditions'] ) );
        return self::$checks;
    }

    /** Checks shown as Health-column chips. */
    public static function chip_checks(): array {
        return array_filter( self::all(), static fn( $c ) => ( $c['chip'] ?? '' ) !== '' );
    }

    /**
     * Every product (within $statuses) that fails a check, or [] for an
     * unknown check id.
     *
     * @param  string[] $statuses
     * @return int[]
     */
    public static function matching_ids( string $check_id, array $statuses ): array {
        $check = self::all()[ $check_id ] ?? null;
        return $check ? array_map( 'intval', Resolver::find_product_ids( self::rule( $check ), $statuses ) ) : [];
    }

    /** A check as a rule-shaped filter Stock_Override_Resolver accepts. */
    private static function rule( array $check ): array {
        return [ 'supplier' => 'all', 'match_logic' => $check['match_logic'] ?? 'all', 'conditions' => $check['conditions'] ];
    }

    /** 'mmi_condition_library' contribution: every check, under "Data health". */
    public static function library_sets( array $sets ): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return $sets;
        }
        foreach ( self::all() as $id => $check ) {
            $sets[] = [
                'id'          => self::LIBRARY_PREFIX . $id,
                'group'       => self::LIBRARY_GROUP,
                'label'       => $check['label'],
                'match_logic' => $check['match_logic'] ?? 'all',
                'conditions'  => $check['conditions'],
            ];
        }
        return $sets;
    }

    /**
     * Which chip checks each product fails, evaluated product by product —
     * for a page of results, whose post, meta and term caches the caller
     * has already primed.
     *
     * @param  int[] $ids
     * @return array<int,string[]> product ID => failed check ids.
     */
    public static function failures( array $ids ): array {
        $out = [];
        foreach ( $ids as $id ) {
            $out[ $id ] = [];
            foreach ( self::chip_checks() as $check_id => $check ) {
                if ( Resolver::rule_matches_product( self::rule( $check ), (int) $id, [] ) ) {
                    $out[ $id ][] = $check_id;
                }
            }
        }
        return $out;
    }

    /**
     * How many chip checks each of $ids fails, set by set — one catalog
     * match per check rather than per product, for sorting a whole result
     * set by health.
     *
     * @param  int[]    $ids
     * @param  string[] $statuses Status scope the search used.
     * @return array<int,int> product ID => failed check count.
     */
    public static function failure_counts( array $ids, array $statuses ): array {
        $counts = array_fill_keys( array_map( 'intval', $ids ), 0 );
        foreach ( self::chip_checks() as $check ) {
            foreach ( Resolver::find_product_ids( self::rule( $check ), $statuses ) as $id ) {
                if ( isset( $counts[ $id ] ) ) {
                    $counts[ $id ]++;
                }
            }
        }
        return $counts;
    }
}

add_filter( 'mmi_condition_library', [ Health_Checks::class, 'library_sets' ] );
