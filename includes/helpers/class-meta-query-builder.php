<?php
/**
 * Meta Query Builder
 *
 * Converts the Export Step 1 "Custom Field Filters" condition rows
 * (key/operator/value triples, saved under scope key 'meta_conditions')
 * into a WP_Query-compatible `meta_query` array. Shared by every
 * postmeta-backed data type handler (Product, generic Post/Page/CPT) so the
 * operator-to-`compare`-value mapping only exists once.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Meta_Query_Builder {

    private const OPERATOR_COMPARE_MAP = [
        'equals'       => '=',
        'not_equals'   => '!=',
        'contains'     => 'LIKE',
        'not_contains' => 'NOT LIKE',
        'greater_than' => '>',
        'less_than'    => '<',
        'exists'       => 'EXISTS',
        'not_exists'   => 'NOT EXISTS',
    ];

    /**
     * @param array $conditions Array of ['key' => string, 'operator' => string, 'value' => string].
     * @return array WP_Query 'meta_query' array — empty if no valid conditions.
     */
    public static function build( array $conditions ): array {
        $clauses = [];

        foreach ( $conditions as $condition ) {
            $key      = sanitize_key( (string) ( $condition['key'] ?? '' ) );
            $operator = (string) ( $condition['operator'] ?? 'equals' );
            $value    = (string) ( $condition['value'] ?? '' );

            if ( $key === '' || ! isset( self::OPERATOR_COMPARE_MAP[ $operator ] ) ) {
                continue;
            }

            $compare = self::OPERATOR_COMPARE_MAP[ $operator ];
            $clause  = [ 'key' => $key, 'compare' => $compare ];

            if ( ! in_array( $compare, [ 'EXISTS', 'NOT EXISTS' ], true ) ) {
                if ( $value === '' ) {
                    continue; // A value-requiring operator with no value is not a usable filter.
                }
                $clause['value'] = in_array( $compare, [ '>', '<' ], true ) ? (float) $value : $value;
                if ( in_array( $compare, [ '>', '<' ], true ) ) {
                    $clause['type'] = 'NUMERIC';
                }
            }

            $clauses[] = $clause;
        }

        if ( empty( $clauses ) ) {
            return [];
        }
        if ( count( $clauses ) > 1 ) {
            $clauses['relation'] = 'AND';
        }
        return $clauses;
    }
}
