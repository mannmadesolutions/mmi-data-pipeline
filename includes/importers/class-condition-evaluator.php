<?php
/**
 * Condition Evaluator
 *
 * Stateless evaluation of field-mapping conditions (is_empty, equals,
 * contains, greater_than, etc.). Extracted from MMI_Dynamic_Product_Importer
 * as part of the god-class decomposition — this has no dependency on the
 * importer's instance state, so it's a pure static utility.
 *
 * @package MannMade\DataPipeline\Importers
 */

namespace MannMade\DataPipeline\Importers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Condition_Evaluator {

    /**
     * Evaluate whether a value passes all conditions defined for a field.
     *
     * Returns the value that should be used (the original $value if conditions
     * pass, or the fallback constant if they fail — or null to skip the field).
     *
     * Two condition shapes are accepted:
     *
     * Shared condition builder (MMI_Condition_Builder — what Field Mapping
     * saves today), evaluated by Stock_Override_Resolver exactly like a
     * Catalog Maintenance rule, with $match_logic ('all'|'any'):
     * [ 'source' => supplier id | 'wp_taxonomy' | 'wp_postmeta' | 'mmi_field_value',
     *   'field' => '...', 'operator' => '...', 'value' => '...', 'case_sensitive' => bool ]
     * 'mmi_field_value' tests $value itself; supplier sources read $item;
     * WP sources read the existing product ($product_id, 0 for a new one).
     *
     * Legacy (the retired own-value-only builder; nothing saved in it on
     * mannmade.us as of 2026-09-30, kept so an older install keeps working):
     * [
     *   'operator'  => 'is_not_empty' | 'is_empty' | 'equals' | 'not_equals'
     *                  | 'contains' | 'not_contains' | 'greater_than' | 'less_than',
     *   'compare'   => '...',  // comparison value (for operators that need one)
     *   'logic'     => 'AND' | 'OR',  // connects this condition to the next (ignored on last)
     * ]
     *
     * @param mixed  $value      The mapped value to test.
     * @param array  $conditions Array of condition rows (may be empty).
     * @param string $fallback   Fallback value to return when conditions fail.
     * @param bool   $use_fallback Whether to return $fallback (true) or null (false) on failure.
     * @param array  $item        The raw source record (shared-builder shape only).
     * @param int    $product_id  Existing product being updated, 0 when creating.
     * @param string $match_logic 'all'|'any' (shared-builder shape only).
     * @return array  ['pass' => bool, 'value' => mixed]
     */
    public static function evaluate_conditions( $value, array $conditions, string $fallback = '', bool $use_fallback = false, array $item = [], int $product_id = 0, string $match_logic = 'all' ): array {
        if ( empty( $conditions ) ) {
            return [ 'pass' => true, 'value' => $value ];
        }

        if ( self::is_builder_shape( $conditions ) ) {
            $pass = \MannMade\DataPipeline\Stock_Override_Resolver::rule_matches_product(
                [ 'conditions' => $conditions, 'match_logic' => 'any' === $match_logic ? 'any' : 'all' ],
                $product_id,
                $item,
                [ 'field_value' => $value ]
            );
            if ( $pass ) {
                return [ 'pass' => true, 'value' => $value ];
            }
            return [ 'pass' => false, 'value' => $use_fallback ? $fallback : null ];
        }

        // Evaluate each condition into a bool, then combine with AND/OR.
        $results = [];
        foreach ( $conditions as $cond ) {
            $op      = $cond['operator'] ?? 'is_not_empty';
            $compare = $cond['compare']  ?? '';
            $results[] = self::evaluate_single_condition( $value, $op, $compare );
        }

        // Combine: default AND; upgrade to OR when any logic connector says OR.
        $pass = $results[0];
        for ( $i = 1; $i < count( $results ); $i++ ) {
            $logic = strtoupper( $conditions[ $i - 1 ]['logic'] ?? 'AND' );
            if ( $logic === 'OR' ) {
                $pass = $pass || $results[ $i ];
            } else {
                $pass = $pass && $results[ $i ];
            }
        }

        if ( $pass ) {
            return [ 'pass' => true, 'value' => $value ];
        }

        // Conditions failed.
        if ( $use_fallback ) {
            return [ 'pass' => false, 'value' => $fallback ];
        }
        return [ 'pass' => false, 'value' => null ];
    }

    /** True when the conditions were saved by the shared condition builder. */
    public static function is_builder_shape( array $conditions ): bool {
        foreach ( $conditions as $cond ) {
            if ( is_array( $cond ) && isset( $cond['source'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Evaluate a single condition operator.
     */
    public static function evaluate_single_condition( $value, string $operator, string $compare ): bool {
        $str_value = (string) $value;
        switch ( $operator ) {
            case 'is_not_empty':
                return $value !== null && $value !== '' && $value !== [];
            case 'is_empty':
                return $value === null || $value === '' || $value === [];
            case 'equals':
                return $str_value === $compare;
            case 'not_equals':
                return $str_value !== $compare;
            case 'contains':
                return $compare !== '' && strpos( $str_value, $compare ) !== false;
            case 'not_contains':
                return $compare === '' || strpos( $str_value, $compare ) === false;
            case 'greater_than':
                return is_numeric( $value ) && floatval( $value ) > floatval( $compare );
            case 'less_than':
                return is_numeric( $value ) && floatval( $value ) < floatval( $compare );
            default:
                return true;
        }
    }
}
