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

    /* ── Editing (Product Workbench) ───────────────────────────────────── */

    /** Saved rules as stored (not normalized), with ids. */
    private static function stored(): array {
        self::all(); // assigns ids to any rule saved without one
        $raw = MMI_DB::get_setting( self::SETTING, [] );
        return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
    }

    private static function position( array $rules, string $id ): ?int {
        foreach ( $rules as $i => $rule ) {
            if ( ( $rule['id'] ?? '' ) === $id ) {
                return $i;
            }
        }
        return null;
    }

    /**
     * A rule from a Workbench search and change, sanitized, or an error that
     * says what to fix. Keeps $base's id, name and on/off when given.
     *
     * @param  array $raw_filter Search filter (Product_Workbench::sanitize_filter input).
     * @return array|\WP_Error
     */
    public static function build( array $raw_filter, string $action, array $raw_params, string $name, array $base = [] ) {
        if ( ! Custom_Rule_Actions::is_valid( $action ) ) {
            return new \WP_Error( 'action', 'Pick what the rule should change.' );
        }
        $params = Custom_Rule_Actions::needs_params( $action ) ? Custom_Rule_Actions::sanitize_params( $action, $raw_params ) : [];
        if ( Custom_Rule_Actions::needs_params( $action ) && empty( $params ) ) {
            return new \WP_Error( 'params', sprintf( '"%s" needs its settings filled in.', Custom_Rule_Actions::label_for( $action ) ) );
        }
        $filter = Product_Workbench::sanitize_filter( $raw_filter );
        $name   = mb_substr( trim( sanitize_text_field( $name ) ), 0, 80 );
        $rule   = [
            'id'            => (string) ( $base['id'] ?? '' ),
            'name'          => $name !== '' ? $name : mb_substr( Product_Workbench::action_summary( $action, $params ), 0, 80 ),
            'enabled'       => array_key_exists( 'enabled', $base ) ? (bool) $base['enabled'] : true,
            'supplier'      => $filter['supplier'],
            'match_logic'   => $filter['match_logic'],
            'conditions'    => $filter['conditions'],
            'statuses'      => $filter['statuses'],
            'action'        => $action,
            'action_params' => $params,
        ];
        $filters = self::filters_from_search( $filter );
        if ( $filters ) {
            $rule['filters'] = $filters;
        }
        return $rule;
    }

    /**
     * Save a built rule: over the saved rule with its id, or as a new rule
     * at the end. Returns the saved rule.
     */
    public static function save( array $rule ): array {
        $rules = self::stored();
        $at    = $rule['id'] !== '' ? self::position( $rules, $rule['id'] ) : null;
        if ( $at === null ) {
            $rule['id'] = self::new_id();
            $rules[]    = $rule;
        } else {
            if ( isset( $rules[ $at ]['efficacy_ack'] ) ) {
                $rule['efficacy_ack'] = $rules[ $at ]['efficacy_ack'];
            }
            $rules[ $at ] = $rule;
        }
        MMI_DB::set_setting( self::SETTING, $rules );
        return $rule;
    }

    /** Switch a rule on or off. Off releases its stock overrides now, not at the next run. */
    public static function set_enabled( string $id, bool $enabled ): bool {
        $rules = self::stored();
        $at    = self::position( $rules, $id );
        if ( $at === null ) {
            return false;
        }
        $rules[ $at ]['enabled'] = $enabled;
        MMI_DB::set_setting( self::SETTING, $rules );
        if ( ! $enabled ) {
            self::release( $id );
        }
        return true;
    }

    public static function delete( string $id ): bool {
        $rules = self::stored();
        $at    = self::position( $rules, $id );
        if ( $at === null ) {
            return false;
        }
        array_splice( $rules, $at, 1 );
        MMI_DB::set_setting( self::SETTING, $rules );
        self::release( $id );
        return true;
    }

    /** Move a rule one place earlier ($by -1) or later (+1). Order decides which stock rule wins. */
    public static function move( string $id, int $by ): bool {
        $rules = self::stored();
        $at    = self::position( $rules, $id );
        $to    = $at === null ? null : $at + ( $by < 0 ? -1 : 1 );
        if ( $at === null || $to < 0 || $to >= count( $rules ) ) {
            return false;
        }
        [ $rules[ $at ], $rules[ $to ] ] = [ $rules[ $to ], $rules[ $at ] ];
        MMI_DB::set_setting( self::SETTING, $rules );
        return true;
    }

    /**
     * Run one stock rule now (Run now in the Workbench), exactly as the
     * catalog run would: products an earlier stock rule takes stay its.
     *
     * @return array{matched:int,changed:int,released:int,deferred:int}|null Null: no such stock rule.
     */
    public static function apply_one( string $id ): ?array {
        $claimed = [];
        foreach ( self::all() as $rule ) {
            $action = (string) ( $rule['action'] ?? '' );
            if ( ! Custom_Rule_Actions::is_stock_action( $action ) ) {
                continue;
            }
            if ( $rule['id'] !== $id ) {
                if ( ! empty( $rule['enabled'] ) ) {
                    $claimed += array_fill_keys( self::matched_ids( $rule ), true );
                }
                continue;
            }
            $ids    = self::matched_ids( $rule );
            $mine   = array_values( array_diff( $ids, array_keys( $claimed ) ) );
            $result = self::hold( $id, $action, $mine, microtime( true ) + self::TIME_BUDGET );
            return [
                'matched'  => count( $ids ),
                'changed'  => count( $result['changed'] ),
                'released' => self::release( $id, $mine ),
                'deferred' => $result['deferred'],
            ];
        }
        return null;
    }

    /**
     * Every rule as the Workbench lists it: what it searches for and
     * changes in words, its search (to open or run it), its stored
     * efficacy grade and its last recorded run.
     *
     * @return list<array>
     */
    public static function listing(): array {
        $snapshot = Rule_Efficacy::snapshot();
        $history  = self::history();
        $out      = [];
        foreach ( self::all() as $i => $rule ) {
            $action = (string) ( $rule['action'] ?? '' );
            $params = (array) ( $rule['action_params'] ?? [] );
            $runs   = $history[ $rule['id'] ] ?? [];
            $out[]  = [
                'id'          => $rule['id'],
                'position'    => $i + 1,
                'name'        => $rule['name'] !== '' ? $rule['name'] : 'Rule ' . ( $i + 1 ),
                'enabled'     => ! empty( $rule['enabled'] ),
                'action'      => $action,
                'params'      => (object) $params,
                'stock'       => Custom_Rule_Actions::is_stock_action( $action ),
                'change'      => Custom_Rule_Actions::is_valid( $action ) ? Product_Workbench::action_summary( $action, $params ) : '',
                'search'      => self::describe( $rule ),
                'filter'      => self::filter_for( $rule ),
                'efficacy'    => $snapshot[ $rule['id'] ] ?? null,
                'last_run'    => $runs[0] ?? null,
            ];
        }
        return $out;
    }

    /** A rule's search in words: "Title contains "promo" or Brands is any of "Apogee Digital, Prism Sound" · Published". */
    public static function describe( array $rule ): string {
        $filter    = self::filter_for( $rule );
        $suppliers = [];
        foreach ( \MMI_Pipeline_Admin::get_configured_suppliers() as $sid => $info ) {
            $suppliers[ (string) $sid ] = (string) ( $info['supplier_name'] ?? $sid );
        }
        $parts = [];
        if ( $filter['q'] !== '' ) {
            $where   = [ 'title_sku' => 'Title or SKU', 'title' => 'Title', 'sku' => 'SKU' ][ $filter['q_in'] ];
            $parts[] = sprintf( '%s contains "%s"', $where, $filter['q'] );
        }
        $conds = [];
        foreach ( $filter['conditions'] as $c ) {
            $phrase  = trim( self::field_label( $c, $suppliers ) . ' ' . self::operator_label( $c ) );
            $conds[] = [ $phrase, ! empty( $c['or'] ) ];
        }
        if ( $conds ) {
            $joined = '';
            foreach ( $conds as $k => [ $phrase, $or ] ) {
                $joined .= $k === 0 ? $phrase : ( ( $filter['match_logic'] === 'any' || $or ) ? ' or ' : ' and ' ) . $phrase;
            }
            $parts[] = $joined;
        }
        if ( $filter['supplier'] !== 'all' ) {
            $parts[] = 'Supplier: ' . ( $filter['supplier'] === 'native' ? 'none (created here)' : ( $suppliers[ $filter['supplier'] ] ?? $filter['supplier'] ) );
        }
        $status_labels = [ 'publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending', 'private' => 'Private' ];
        $parts[]       = implode( ', ', array_map( static fn( $s ) => $status_labels[ $s ] ?? $s, $filter['statuses'] ) );
        if ( $filter['stock'] ) {
            $stock_labels = [ 'instock' => 'In stock', 'outofstock' => 'Out of stock', 'onbackorder' => 'Backorder' ];
            $parts[]      = implode( ', ', array_map( static fn( $s ) => $stock_labels[ $s ] ?? $s, $filter['stock'] ) );
        }
        if ( $filter['health'] ) {
            $checks  = Health_Checks::all();
            $mode    = [ 'any' => 'Fails any of: ', 'all' => 'Fails all of: ', 'none' => 'Passes: ' ][ $filter['health_mode'] ];
            $parts[] = $mode . implode( ', ', array_map( static fn( $id ) => $checks[ $id ]['label'] ?? $id, $filter['health'] ) );
        }
        return implode( ' · ', $parts );
    }

    private static function field_label( array $c, array $suppliers ): string {
        $field  = (string) ( $c['field'] ?? '' );
        $source = (string) ( $c['source'] ?? '' );
        if ( $source === Stock_Override_Resolver::TAXONOMY_SOURCE ) {
            $tax = get_taxonomy( $field );
            return $tax ? $tax->labels->name : $field;
        }
        if ( $source === Stock_Override_Resolver::POSTMETA_SOURCE ) {
            $post_fields = [ '__post_title' => 'Title', '__post_content' => 'Description', '__post_excerpt' => 'Short description', '__post_name' => 'Slug', '__post_status' => 'Status' ];
            return $post_fields[ $field ] ?? $field;
        }
        return trim( ( $suppliers[ $source ] ?? $source ) . ' ' . $field );
    }

    private static function operator_label( array $c ): string {
        $op    = (string) ( $c['operator'] ?? 'equals' );
        $label = class_exists( 'MMI_Condition_Builder' ) ? ( \MMI_Condition_Builder::OPERATOR_LABELS[ $op ] ?? $op ) : $op;
        $label = preg_replace( '/^[=≠<>]\s*/u', '', $label );
        if ( in_array( $op, [ 'is_empty', 'is_not_empty', 'term_default_only' ], true ) ) {
            return $label;
        }
        $value = (string) ( $c['value'] ?? '' );
        if ( $op === 'matches_pattern' ) {
            $value = Stock_Override_Resolver::PATTERNS[ $value ] ?? $value;
            return $label . ' ' . $value;
        }
        $value = str_replace( Stock_Override_Resolver::LIST_VALUE_DELIMITER, ', ', $value );
        return sprintf( '%s "%s"', $label, $value ) . ( ! empty( $c['include_children'] ) ? ' or a child term' : '' );
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
