<?php
/**
 * Standing rules — Product Workbench changes that keep applying
 *
 * A standing rule is a Workbench search plus one change, re-applied by every
 * catalog run (the override_stock phase). They are stored where Custom Rules
 * always were (MMI setting `mmi_stock_override_rules`), so import-time stock
 * resolution and every existing reader keep working.
 *
 * Matching is the Workbench's own: Product_Workbench::find_ids() on the rule's
 * search. The run therefore changes exactly the products the rule's preview
 * listed, instead of re-evaluating every published product one at a time.
 *
 * Force In / Out of Stock rules don't write stock status directly any more:
 * they set the product's own `_mmi_stock_override` and mark it as theirs
 * (OWNER_META = rule id). Feed sync and imports already respect that
 * override, so a rule no longer flips products back out of stock after feed
 * sync puts them in, every run. A product whose override a person or Embargo
 * set is left alone, and a rule only ever removes overrides it owns.
 *
 * @package MannMade\DataPipeline\Workbench
 */

namespace MannMade\DataPipeline\Workbench;

use MannMade\DataPipeline\Custom_Rule_Actions;
use MannMade\DataPipeline\Embargo;
use MannMade\DataPipeline\Stock_Override_Resolver;
use MMI_DB;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Standing_Rules {

    const SETTING         = 'mmi_stock_override_rules';
    const HISTORY_SETTING = 'mmi_standing_rule_history';
    const HISTORY_KEEP    = 24;

    /** The rule id that set a product's `_mmi_stock_override`. */
    const OWNER_META = '_mmi_stock_override_rule';

    /** Rule id whose override a person removed: that rule leaves the product alone. */
    const OPTOUT_META = '_mmi_stock_override_optout';

    /**
     * Seconds one run may spend writing. Every write is idempotent, so what
     * is left over is picked up by the next run. Keeps override_stock well
     * under the 45 s wp-cron ceiling (operational-continuity Rule 12).
     */
    const TIME_BUDGET = 20.0;

    /** Search filters a rule can carry beyond its conditions (Product_Workbench::sanitize_filter keys). */
    const FILTER_KEYS = [ 'stock', 'health', 'health_mode', 'q', 'q_case', 'q_in' ];

    /* ── Stored rules ──────────────────────────────────────────────────── */

    /**
     * Every saved rule, normalized, each with a stable id. Rules saved
     * before ids existed get one here, written back once.
     *
     * @return list<array>
     */
    public static function all(): array {
        $raw   = MMI_DB::get_setting( self::SETTING, [] );
        $raw   = is_array( $raw ) ? array_values( $raw ) : [];
        $dirty = false;
        foreach ( $raw as $i => $rule ) {
            if ( is_array( $rule ) && empty( $rule['id'] ) ) {
                $raw[ $i ]['id'] = self::new_id();
                $dirty           = true;
            }
        }
        if ( $dirty ) {
            MMI_DB::set_setting( self::SETTING, $raw );
        }
        return Stock_Override_Resolver::normalize_rules( array_filter( $raw, 'is_array' ) );
    }

    public static function new_id(): string {
        return 'r' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
    }

    /**
     * Fields a rule keeps that the rule editor doesn't send (statuses, search
     * filters, efficacy acknowledgements): carried over from the saved rule
     * with the same id, so saving from an editor that predates them never
     * drops them.
     *
     * @param  list<array> $incoming Sanitized rules about to be saved.
     * @return list<array>
     */
    public static function carry_over( array $incoming ): array {
        $saved = [];
        foreach ( (array) MMI_DB::get_setting( self::SETTING, [] ) as $rule ) {
            if ( is_array( $rule ) && ! empty( $rule['id'] ) ) {
                $saved[ $rule['id'] ] = $rule;
            }
        }
        foreach ( $incoming as $i => $rule ) {
            if ( empty( $rule['id'] ) || ! isset( $saved[ $rule['id'] ] ) ) {
                $incoming[ $i ]['id'] = ! empty( $rule['id'] ) ? $rule['id'] : self::new_id();
                continue;
            }
            foreach ( [ 'statuses', 'filters', 'efficacy_ack' ] as $key ) {
                if ( ! array_key_exists( $key, $rule ) && array_key_exists( $key, $saved[ $rule['id'] ] ) ) {
                    $incoming[ $i ][ $key ] = $saved[ $rule['id'] ][ $key ];
                }
            }
        }
        return $incoming;
    }

    /** A sanitized rule id, or '' when it isn't one. */
    public static function sanitize_id( $id ): string {
        $id = is_scalar( $id ) ? (string) $id : '';
        return preg_match( '/^r[a-f0-9]{12}$/', $id ) ? $id : '';
    }

    /* ── Matching ──────────────────────────────────────────────────────── */

    /**
     * The Workbench search a rule stands for.
     *
     * @return array Product_Workbench::sanitize_filter() shape.
     */
    public static function filter_for( array $rule ): array {
        $rule    = Stock_Override_Resolver::normalize_rule( $rule );
        $filters = array_intersect_key( (array) ( $rule['filters'] ?? [] ), array_flip( self::FILTER_KEYS ) );
        return Product_Workbench::sanitize_filter( [
            'supplier'    => $rule['supplier'] ?? 'all',
            'match_logic' => $rule['match_logic'] ?? 'all',
            'conditions'  => $rule['conditions'] ?? [],
            'statuses'    => Stock_Override_Resolver::rule_statuses( $rule ),
        ] + $filters );
    }

    /**
     * The search filters worth storing on a rule: only those that narrow
     * anything, so a rule built from a plain condition search stays the
     * same shape as one saved in the rule editor.
     */
    public static function filters_from_search( array $filter ): array {
        $out = [];
        if ( ! empty( $filter['stock'] ) ) {
            $out['stock'] = $filter['stock'];
        }
        if ( ! empty( $filter['health'] ) ) {
            $out['health']      = $filter['health'];
            $out['health_mode'] = $filter['health_mode'];
        }
        if ( ( $filter['q'] ?? '' ) !== '' ) {
            $out['q']      = $filter['q'];
            $out['q_case'] = (bool) $filter['q_case'];
            $out['q_in']   = $filter['q_in'];
        }
        return $out;
    }

    /** @return int[] */
    public static function matched_ids( array $rule ): array {
        return Product_Workbench::find_ids( self::filter_for( $rule ) );
    }

    /* ── Catalog run ───────────────────────────────────────────────────── */

    /**
     * Apply every standing rule, in order. Stock rules: first match wins
     * (a product an earlier stock rule took isn't offered to a later one).
     * Every other rule applies to all it matches. Releases the overrides of
     * stock rules that are off, deleted, or no longer match a product.
     *
     * @return array{changed:int,deferred:int,rules:array<string,array>}
     */
    public static function apply_all(): array {
        wp_raise_memory_limit( 'wc' );

        $deadline = microtime( true ) + self::TIME_BUDGET;
        $rules    = self::all();
        $claimed  = [];
        $live     = [];
        $stats    = [];
        $changed  = 0;
        $deferred = 0;

        foreach ( $rules as $rule ) {
            $id     = (string) $rule['id'];
            $action = (string) ( $rule['action'] ?? '' );
            $stock  = Custom_Rule_Actions::is_stock_action( $action );
            if ( empty( $rule['enabled'] ) || ! Custom_Rule_Actions::is_valid( $action ) ) {
                continue;
            }

            $start = microtime( true );
            $ids   = self::matched_ids( $rule );
            $ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
            $done  = [];
            $left  = 0;

            if ( $stock ) {
                $live[] = $id;
                $mine   = array_values( array_diff( $ids, array_keys( $claimed ) ) );
                $result = self::hold( $id, $action, $mine, $deadline );
                $done   = $result['changed'];
                $left   = $result['deferred'];
                self::release( $id, $mine );
                foreach ( $mine as $pid ) {
                    $claimed[ $pid ] = true;
                }
            } else {
                $params = (array) ( $rule['action_params'] ?? [] );
                foreach ( array_chunk( $ids, 200 ) as $chunk ) {
                    if ( microtime( true ) > $deadline ) {
                        $left += count( $chunk );
                        continue;
                    }
                    update_meta_cache( 'post', $chunk );
                    foreach ( $chunk as $pid ) {
                        if ( microtime( true ) > $deadline ) {
                            $left++;
                            continue;
                        }
                        $raw = $action === 'copy_source_field_to_meta' ? Stock_Override_Resolver::raw_feed_item( $pid ) : [];
                        if ( Custom_Rule_Actions::apply( $pid, $action, $params, $raw ) ) {
                            $done[] = $pid;
                        }
                    }
                    wp_cache_flush_runtime();
                }
            }

            $changed  += count( $done );
            $deferred += $left;
            $stats[ $id ] = [
                'matched'  => count( $ids ),
                'changed'  => count( $done ),
                'deferred' => $left,
                'ms'       => (int) round( ( microtime( true ) - $start ) * 1000 ),
                'match_ms' => $ms,
                'ids_hash' => $done ? md5( implode( ',', $done ) ) : '',
            ];
        }

        self::release_orphans( $live );
        self::record_run( $stats );

        return [ 'changed' => $changed, 'deferred' => $deferred, 'rules' => $stats ];
    }

    /**
     * Give $ids the rule's stock override and stock status.
     *
     * @return array{changed:int[],deferred:int}
     */
    private static function hold( string $rule_id, string $action, array $ids, float $deadline ): array {
        $status  = $action === 'force_instock' ? 'instock' : 'outofstock';
        $changed = [];
        $left    = 0;
        foreach ( array_chunk( $ids, 200 ) as $chunk ) {
            update_meta_cache( 'post', $chunk );
            foreach ( $chunk as $pid ) {
                if ( microtime( true ) > $deadline ) {
                    $left++;
                    continue;
                }
                if ( ! self::may_hold( $pid, $rule_id ) ) {
                    continue;
                }
                $did = false;
                if ( get_post_meta( $pid, '_mmi_stock_override', true ) !== $action || get_post_meta( $pid, self::OWNER_META, true ) !== $rule_id ) {
                    update_post_meta( $pid, '_mmi_stock_override', $action );
                    update_post_meta( $pid, self::OWNER_META, $rule_id );
                    $did = true;
                }
                if ( get_post_meta( $pid, '_stock_status', true ) !== $status ) {
                    Stock_Override_Resolver::force_stock_status( $pid, $status );
                    $did = true;
                }
                if ( $did ) {
                    $changed[] = $pid;
                }
            }
            wp_cache_flush_runtime();
        }
        return [ 'changed' => $changed, 'deferred' => $left ];
    }

    /**
     * Whether a rule may set this product's override: not while Embargo
     * holds it, not after a person removed this rule's override, and never
     * over an override a person set (one with no rule owner).
     */
    private static function may_hold( int $pid, string $rule_id ): bool {
        if ( metadata_exists( 'post', $pid, Embargo::META_UNTIL ) ) {
            return false;
        }
        if ( get_post_meta( $pid, self::OPTOUT_META, true ) === $rule_id ) {
            return false;
        }
        $current = (string) get_post_meta( $pid, '_mmi_stock_override', true );
        return $current === '' || (string) get_post_meta( $pid, self::OWNER_META, true ) !== '';
    }

    /**
     * Remove this rule's override from products it owns outside $keep.
     * Stock status is left for feed sync and imports to set, as it always
     * was when a rule stopped matching.
     *
     * @param int[] $keep
     */
    public static function release( string $rule_id, array $keep = [] ): int {
        global $wpdb;
        $owned = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            self::OWNER_META,
            $rule_id
        ) ) );
        $drop = array_diff( $owned, $keep );
        foreach ( $drop as $pid ) {
            self::drop_override( $pid );
        }
        return count( $drop );
    }

    /** Release overrides whose rule is gone or no longer a live stock rule. */
    private static function release_orphans( array $live_ids ): void {
        global $wpdb;
        $owners = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::OWNER_META
        ) );
        foreach ( array_diff( $owners, $live_ids ) as $owner ) {
            self::release( (string) $owner );
        }
    }

    private static function drop_override( int $pid ): void {
        delete_post_meta( $pid, self::OWNER_META );
        if ( metadata_exists( 'post', $pid, Embargo::META_UNTIL ) ) {
            // Embargo took over and remembered this override as the one to
            // put back on release; that should now be none.
            $prev = (array) get_post_meta( $pid, Embargo::META_PREV, true );
            if ( ( $prev['override'] ?? '' ) !== '' ) {
                $prev['override'] = '';
                update_post_meta( $pid, Embargo::META_PREV, $prev );
            }
            return;
        }
        delete_post_meta( $pid, '_mmi_stock_override' );
    }

    /**
     * A person changed a product's override by hand: the rule that owned it
     * lets go, and won't set it again if the person cleared it.
     */
    public static function person_changed_override( int $pid, string $new_value ): void {
        $owner = (string) get_post_meta( $pid, self::OWNER_META, true );
        if ( $owner === '' ) {
            return;
        }
        delete_post_meta( $pid, self::OWNER_META );
        if ( $new_value === '' ) {
            update_post_meta( $pid, self::OPTOUT_META, $owner );
        }
    }

    /** Name of the rule that owns a product's override, or ''. */
    public static function owner_name( int $pid ): string {
        $owner = (string) get_post_meta( $pid, self::OWNER_META, true );
        if ( $owner === '' ) {
            return '';
        }
        foreach ( self::all() as $i => $rule ) {
            if ( $rule['id'] === $owner ) {
                return $rule['name'] !== '' ? $rule['name'] : 'Rule ' . ( $i + 1 );
            }
        }
        return '';
    }

    /* ── Run history ───────────────────────────────────────────────────── */

    /** @return array<string,list<array>> rule id => newest-first runs. */
    public static function history(): array {
        $h = MMI_DB::get_setting( self::HISTORY_SETTING, [] );
        return is_array( $h ) ? $h : [];
    }

    private static function record_run( array $stats ): void {
        $history = self::history();
        $now     = time();
        foreach ( $stats as $id => $s ) {
            $runs = $history[ $id ] ?? [];
            array_unshift( $runs, [ 'at' => $now ] + $s );
            $history[ $id ] = array_slice( $runs, 0, self::HISTORY_KEEP );
        }
        // Rules that no longer exist drop their history.
        $ids     = array_column( self::all(), 'id' );
        $history = array_intersect_key( $history, array_flip( $ids ) );
        MMI_DB::set_setting( self::HISTORY_SETTING, $history );
    }
}
