<?php
/**
 * Rule efficacy — how well a standing rule will work, before and after it runs
 *
 * predict() reads the catalog (never writes) and says whether a rule will do
 * what it says: whether it matches anything, has anything to change, will be
 * undone by feed sync, an import or another rule, repeats another rule, or
 * costs more time than a catalog run has. observed() reads the rule's run
 * history for the same problems seen in practice: the same products changed
 * run after run (something keeps undoing it), nothing matched for a day, or
 * runs that take too long.
 *
 * Findings are advice: a rule is saved and runs whatever its grade. The
 * screen shows them where the rule is made and listed, and the page header
 * warns while any enabled rule grades "inefficient".
 *
 * @package MannMade\DataPipeline\Workbench
 */

namespace MannMade\DataPipeline\Workbench;

use MannMade\DataPipeline\Catalog\Catalog_Phase_Runner;
use MannMade\DataPipeline\Custom_Rule_Actions;
use MannMade\DataPipeline\Stock_Override_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rule_Efficacy {

    const OK          = 'ok';
    const INFO        = 'info';
    const REVIEW      = 'review';
    const INEFFICIENT = 'inefficient';

    const RANK = [ self::OK => 0, self::INFO => 1, self::REVIEW => 2, self::INEFFICIENT => 3 ];

    /** Share of the searched products above which a sweeping change asks for a second look. */
    const BROAD_SHARE = 0.5;

    /** Actions where matching most of the catalog by mistake is costly to put right. */
    const SWEEPING = [
        'force_instock', 'force_outofstock', 'set_status_publish', 'set_status_draft', 'set_status_pending',
        'set_regular_price', 'set_sale_price', 'clear_sale_price', 'set_sale_dates',
    ];

    /** Rough write cost per changed product, ms: overrides and meta are cheap, WooCommerce saves are not. */
    const WRITE_MS = [ 'stock' => 4, 'meta' => 6, 'terms' => 15, 'save' => 40 ];

    /** Matching alone taking this long (ms) leaves too little of a run for anything else. */
    const SLOW_MATCH_MS = 5000;

    /** `canonical` values feed sync treats as a duplicate listing and leaves alone. */
    const NOT_CANONICAL = [ 'FALSE', 'false', 'False', '0', 'not applicable', 'Not Applicable' ];

    /** Runs in a row with the same products changed before it counts as a tug-of-war. */
    const CHURN_RUNS = 3;

    /* ── Before it runs ────────────────────────────────────────────────── */

    /**
     * @param  array       $rule    The rule as it would be saved.
     * @param  list<array> $others  The other saved rules, in run order, with
     *                              this rule's own saved copy left out.
     * @param  int|null    $position Where this rule sits among $others (null = last).
     * @return array{grade:string,matched:int,will_change:int,findings:list<array>}
     */
    public static function predict( array $rule, array $others = [], ?int $position = null ): array {
        $rule     = Stock_Override_Resolver::normalize_rule( $rule );
        $action   = (string) ( $rule['action'] ?? '' );
        $params   = (array) ( $rule['action_params'] ?? [] );
        $stock    = Custom_Rule_Actions::is_stock_action( $action );
        $findings = [];

        $start   = microtime( true );
        $ids     = Standing_Rules::matched_ids( $rule );
        $match_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
        $matched = count( $ids );

        if ( $matched === 0 ) {
            $findings[] = self::finding( 'matches_nothing', self::REVIEW,
                'This rule matches no products right now, so it changes nothing.',
                'Check the conditions; it will act on products that come to match them.' );
            return self::result( $findings, 0, 0 );
        }

        // What it would change today.
        if ( $stock ) {
            $will_change = self::stock_changes( $ids, $action );
        } else {
            $preview     = Product_Workbench::preview( $ids, $action, $params );
            $will_change = (int) $preview['will_change'];
        }
        if ( $will_change === 0 ) {
            $findings[] = self::finding( 'nothing_to_change', self::INFO,
                sprintf( 'All %s matching products already look the way this rule makes them.', number_format( $matched ) ),
                'Fine for a rule that keeps new products in line; it acts only when one comes to match.' );
        }

        // Undone by something else every run.
        if ( $stock ) {
            $fight = self::feed_sync_fight( $ids, $action );
            if ( $fight > 0 ) {
                $findings[] = self::finding( 'fights_feed_sync', self::INEFFICIENT,
                    $action === 'force_outofstock'
                        ? sprintf( 'Feed sync puts %s of these products back in stock on every run, and this rule takes them out again.', number_format( $fight ) )
                        : sprintf( 'Feed sync takes %s of these products out of stock on every run (their supplier no longer lists them), and this rule puts them back.', number_format( $fight ) ),
                    $action === 'force_outofstock'
                        ? 'Holding these products with their own stock override stops it: feed sync respects that.'
                        : 'A product its supplier stopped listing can\'t be sold from that supplier; narrow the rule to products another source supplies.',
                    $fight );
            }
        }
        foreach ( \MMI_Pipeline_Field_Conflicts::for_custom_rules( [ $rule + [ 'enabled' => true ] ] )[0] ?? [] as $message ) {
            $findings[] = self::finding( 'fights_import', self::INEFFICIENT, $message,
                'Lock the field against imports on these products, or stop the profile mapping it.' );
        }

        // Other rules.
        $findings = array_merge( $findings, self::against_rules( $rule, $ids, $others, $position ) );

        // Breadth.
        $searched = self::searched_total( $rule );
        if ( in_array( $action, self::SWEEPING, true ) && $searched > 0 && $matched / $searched >= self::BROAD_SHARE ) {
            $findings[] = self::finding( 'too_broad', self::REVIEW,
                sprintf( 'This rule matches %s of %s products (%d%%) and changes their %s.', number_format( $matched ), number_format( $searched ), (int) round( 100 * $matched / $searched ), self::what_it_changes( $action ) ),
                'Make sure that is meant; a narrower search is easier to undo.' );
        }

        // Time.
        if ( $match_ms >= self::SLOW_MATCH_MS ) {
            $findings[] = self::finding( 'costly', $match_ms >= Standing_Rules::TIME_BUDGET * 1000 / 2 ? self::INEFFICIENT : self::REVIEW,
                sprintf( 'Finding this rule\'s products takes %.1f s of each catalog run.', $match_ms / 1000 ),
                self::uses_feed( $rule ) ? 'Supplier data conditions read the whole feed; add a category, brand or meta condition so fewer products are checked.' : 'Narrow the search with a category or brand condition.' );
        }
        $write_ms = $will_change * self::WRITE_MS[ self::write_kind( $action ) ];
        if ( $write_ms > Standing_Rules::TIME_BUDGET * 1000 ) {
            $runs = (int) ceil( $write_ms / ( Standing_Rules::TIME_BUDGET * 1000 ) );
            $findings[] = self::finding( 'spread_over_runs', self::INFO,
                sprintf( 'Changing %s products takes about %d catalog runs; each run does what fits in its time.', number_format( $will_change ), $runs ),
                'Use Once for the first pass if it is needed sooner.' );
        }

        return self::result( $findings, $matched, $will_change ) + [ 'match_ms' => $match_ms ];
    }

    /**
     * Products a stock rule would change: status differs, or the product
     * isn't yet held by a standing rule's override.
     */
    private static function stock_changes( array $ids, string $action ): int {
        $target = $action === 'force_instock' ? 'instock' : 'outofstock';
        $n      = 0;
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            update_meta_cache( 'post', $chunk );
            foreach ( $chunk as $id ) {
                if ( get_post_meta( $id, '_stock_status', true ) !== $target || get_post_meta( $id, '_mmi_stock_override', true ) !== $action ) {
                    $n++;
                }
            }
            wp_cache_flush_runtime();
        }
        return $n;
    }

    /**
     * Matched products feed sync reverses every run: no per-product override
     * (which feed sync respects), and the supplier's feed still lists the
     * product (Force Out) or no longer does (Force In).
     */
    private static function feed_sync_fight( array $ids, string $action ): int {
        $n = 0;
        foreach ( array_chunk( $ids, 500 ) as $chunk ) {
            update_meta_cache( 'post', $chunk );
            foreach ( $chunk as $id ) {
                if ( get_post_meta( $id, '_mmi_stock_override', true ) === $action ) {
                    continue;
                }
                // Feed sync's own scope: its feeds, the feed's distribution
                // term, and canonical listings only.
                $supplier = Stock_Override_Resolver::product_supplier( $id );
                $feed     = Catalog_Phase_Runner::FEEDS[ $supplier ] ?? null;
                if ( ! $feed || ! has_term( $feed['term_slug'], 'distribution', $id )
                    || in_array( (string) get_post_meta( $id, 'canonical', true ), self::NOT_CANONICAL, true ) ) {
                    continue;
                }
                $listed = Stock_Override_Resolver::raw_feed_item( $id ) !== [];
                if ( $action === 'force_outofstock' ? $listed : ! $listed ) {
                    $n++;
                }
            }
            wp_cache_flush_runtime();
        }
        return $n;
    }

    /** Duplicates, write conflicts and first-match shadowing among standing rules. */
    private static function against_rules( array $rule, array $ids, array $others, ?int $position ): array {
        $findings = [];
        $action   = (string) $rule['action'];
        $stock    = Custom_Rule_Actions::is_stock_action( $action );
        $keys     = $stock ? [ '_stock_status' ] : \MMI_Pipeline_Field_Conflicts::rule_write_keys( $rule );
        $mine     = array_flip( $ids );
        $position = $position ?? count( $others );

        foreach ( array_values( $others ) as $i => $other ) {
            $other = Stock_Override_Resolver::normalize_rule( (array) $other );
            if ( empty( $other['enabled'] ) ) {
                continue;
            }
            $name         = $other['name'] !== '' ? $other['name'] : 'Rule ' . ( $i + 1 );
            $other_action = (string) ( $other['action'] ?? '' );

            if ( self::same_search( $rule, $other ) && $other_action === $action && self::same_params( $rule, $other ) ) {
                $findings[] = self::finding( 'duplicate', self::INEFFICIENT,
                    sprintf( 'The rule "%s" already searches for the same products and makes the same change.', $name ),
                    'Keep one of them.' );
                continue;
            }

            $other_stock = Custom_Rule_Actions::is_stock_action( $other_action );
            $other_keys  = $other_stock ? [ '_stock_status' ] : \MMI_Pipeline_Field_Conflicts::rule_write_keys( $other );
            if ( ! array_intersect( $keys, $other_keys ) ) {
                continue;
            }
            $overlap = count( array_filter( Standing_Rules::matched_ids( $other ), static fn( $id ) => isset( $mine[ $id ] ) ) );
            if ( $overlap === 0 ) {
                continue;
            }

            if ( $stock && $other_stock ) {
                if ( $i < $position ) {
                    $findings[] = self::finding( 'shadowed', $overlap === count( $ids ) ? self::INEFFICIENT : self::REVIEW,
                        sprintf( '%s of these products are taken by the earlier stock rule "%s" (the first matching stock rule wins).', number_format( $overlap ), $name ),
                        $overlap === count( $ids ) ? 'This rule never acts; remove it or move it first.' : 'Fine if meant; otherwise reorder the rules.',
                        $overlap );
                }
                continue;
            }
            if ( $other_action === $action && self::same_params( $rule, $other ) ) {
                $findings[] = self::finding( 'overlaps_rule', self::INFO,
                    sprintf( '%s of these products also match the rule "%s", which makes the same change.', number_format( $overlap ), $name ),
                    'Harmless; merge them to keep the list short.', $overlap );
                continue;
            }
            $findings[] = self::finding( 'fights_rule', self::INEFFICIENT,
                sprintf( 'The rule "%s" changes the same field on %s of these products to something else. Whichever runs last wins, every run.', $name, number_format( $overlap ) ),
                'Narrow one search so they don\'t share products, or keep one rule.', $overlap );
        }
        return $findings;
    }

    /* ── After it has run ──────────────────────────────────────────────── */

    /**
     * Problems seen in the rule's recorded runs.
     *
     * @param list<array> $runs Newest first (Standing_Rules::history()[ id ]).
     * @return list<array>
     */
    public static function observed( array $runs ): array {
        $findings = [];
        if ( count( $runs ) >= self::CHURN_RUNS ) {
            $recent = array_slice( $runs, 0, self::CHURN_RUNS );
            $hashes = array_unique( array_column( $recent, 'ids_hash' ) );
            if ( count( $hashes ) === 1 && $hashes[0] !== '' ) {
                $findings[] = self::finding( 'churn', self::INEFFICIENT,
                    sprintf( 'The last %d runs each changed the same %s products again: something keeps undoing this rule.', self::CHURN_RUNS, number_format( (int) $recent[0]['changed'] ) ),
                    'Look for an import profile, feed sync or another rule writing the same field.', (int) $recent[0]['changed'] );
            }
        }
        if ( count( $runs ) >= Standing_Rules::HISTORY_KEEP && max( array_column( $runs, 'matched' ) ) === 0 ) {
            $findings[] = self::finding( 'dead', self::REVIEW,
                sprintf( 'This rule matched nothing in its last %d runs.', count( $runs ) ),
                'Remove it, or check its conditions still describe products you sell.' );
        }
        if ( $runs ) {
            $avg = array_sum( array_column( $runs, 'ms' ) ) / count( $runs );
            if ( $avg >= Standing_Rules::TIME_BUDGET * 1000 / 2 ) {
                $findings[] = self::finding( 'slow', self::REVIEW,
                    sprintf( 'This rule takes %.1f s per run on average, half of what a run allows for every rule.', $avg / 1000 ),
                    'Narrow the search with a category or brand condition.' );
            }
            if ( (int) ( $runs[0]['deferred'] ?? 0 ) > 0 && count( $runs ) >= self::CHURN_RUNS
                && min( array_map( static fn( $r ) => (int) ( $r['deferred'] ?? 0 ), array_slice( $runs, 0, self::CHURN_RUNS ) ) ) > 0 ) {
                $findings[] = self::finding( 'never_finishes', self::INEFFICIENT,
                    sprintf( '%s products are still waiting after %d runs: this rule has more to change than a run has time for.', number_format( (int) $runs[0]['deferred'] ), self::CHURN_RUNS ),
                    'Make the change Once from the Workbench, then keep the rule for new products.' );
            }
        }
        return $findings;
    }

    /**
     * Grade for a saved rule from its run history alone (cheap: no catalog
     * reads), for the page header and the rule list.
     */
    public static function grade_from_history( array $runs ): string {
        return self::result( self::observed( $runs ), 0, 0 )['grade'];
    }

    /* ── Stored grades (what the page reads) ───────────────────────────── */

    const SNAPSHOT = 'mmi_standing_rule_efficacy';
    const HOOK     = 'mmi_pipeline_rule_efficacy';
    const GROUP    = 'mmi-pipeline-catalog';

    /** A stored grade older than this is worked out again: the catalog moves. */
    const SNAPSHOT_TTL = DAY_IN_SECONDS;

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'refresh' ] );
    }

    /** @return array<string,array> rule id => {hash, at, grade, matched, will_change, findings} */
    public static function snapshot(): array {
        $s = \MMI_DB::get_setting( self::SNAPSHOT, [] );
        return is_array( $s ) ? $s : [];
    }

    /** Everything the run depends on: search, change, on/off and run order. */
    private static function rule_hash( array $rule, int $position ): string {
        return md5( wp_json_encode( [ Standing_Rules::filter_for( $rule ), $rule['action'] ?? '', $rule['action_params'] ?? [], ! empty( $rule['enabled'] ), $position ] ) );
    }

    /**
     * Work out every rule's grade again and store it: the prediction from
     * the catalog now, plus what its recorded runs show.
     */
    public static function refresh(): array {
        $rules   = Standing_Rules::all();
        $history = Standing_Rules::history();
        $out     = [];
        foreach ( $rules as $i => $rule ) {
            $others   = $rules;
            unset( $others[ $i ] );
            $p        = self::predict( $rule, array_values( $others ), $i );
            $merged   = self::result( array_merge( $p['findings'], self::observed( $history[ $rule['id'] ] ?? [] ) ), $p['matched'], $p['will_change'] );
            $out[ $rule['id'] ] = [ 'hash' => self::rule_hash( $rule, $i ), 'at' => time() ] + $merged;
        }
        \MMI_DB::set_setting( self::SNAPSHOT, $out );
        return $out;
    }

    /**
     * Queue one background refresh when a stored grade is missing, out of
     * date or older than a day. Never works anything out itself, so a page
     * can call it while rendering.
     */
    public static function refresh_later_if_stale(): void {
        if ( ! function_exists( 'as_enqueue_async_action' ) || as_has_scheduled_action( self::HOOK, [], self::GROUP ) ) {
            return;
        }
        $snapshot = self::snapshot();
        foreach ( Standing_Rules::all() as $i => $rule ) {
            $stored = $snapshot[ $rule['id'] ] ?? null;
            if ( ! $stored || $stored['hash'] !== self::rule_hash( $rule, $i ) || $stored['at'] < time() - self::SNAPSHOT_TTL ) {
                as_enqueue_async_action( self::HOOK, [], self::GROUP );
                return;
            }
        }
    }

    /**
     * Enabled rules whose stored grade is "inefficient", for the page header.
     *
     * @return list<array{id:string,name:string,text:string}>
     */
    public static function alerts(): array {
        $snapshot = self::snapshot();
        $out      = [];
        foreach ( Standing_Rules::all() as $i => $rule ) {
            $stored = $snapshot[ $rule['id'] ] ?? null;
            if ( empty( $rule['enabled'] ) || ! $stored || $stored['grade'] !== self::INEFFICIENT ) {
                continue;
            }
            $first = $stored['findings'][0] ?? [ 'text' => '' ];
            $out[] = [ 'id' => $rule['id'], 'name' => $rule['name'] !== '' ? $rule['name'] : 'Rule ' . ( $i + 1 ), 'text' => $first['text'] ];
        }
        return $out;
    }

    /**
     * Stored findings worth showing beside each rule (review and worse), by
     * rule position, as "what's wrong — what to do" lines.
     *
     * @return array<int,string[]>
     */
    public static function notices_by_index(): array {
        $snapshot = self::snapshot();
        $out      = [];
        foreach ( Standing_Rules::all() as $i => $rule ) {
            foreach ( $snapshot[ $rule['id'] ]['findings'] ?? [] as $f ) {
                if ( self::RANK[ $f['severity'] ] >= self::RANK[ self::REVIEW ] ) {
                    $out[ $i ][] = $f['text'] . ' ' . $f['fix'];
                }
            }
        }
        return $out;
    }

    /* ── Helpers ───────────────────────────────────────────────────────── */

    private static function finding( string $code, string $severity, string $text, string $fix, int $count = 0 ): array {
        return [ 'code' => $code, 'severity' => $severity, 'text' => $text, 'fix' => $fix, 'count' => $count ];
    }

    private static function result( array $findings, int $matched, int $will_change ): array {
        $grade = self::OK;
        foreach ( $findings as $f ) {
            if ( self::RANK[ $f['severity'] ] > self::RANK[ $grade ] ) {
                $grade = $f['severity'];
            }
        }
        usort( $findings, static fn( $a, $b ) => self::RANK[ $b['severity'] ] <=> self::RANK[ $a['severity'] ] );
        return [ 'grade' => $grade, 'matched' => $matched, 'will_change' => $will_change, 'findings' => $findings ];
    }

    /** Products in the rule's statuses and supplier scope (the denominator for "matches most"). */
    private static function searched_total( array $rule ): int {
        $scope = Standing_Rules::filter_for( $rule );
        return count( Stock_Override_Resolver::find_product_ids( [ 'supplier' => $scope['supplier'], 'match_logic' => 'all', 'conditions' => [] ], $scope['statuses'] ) );
    }

    private static function same_search( array $a, array $b ): bool {
        $key = static fn( array $r ) => wp_json_encode( Standing_Rules::filter_for( $r ) );
        return $key( $a ) === $key( $b );
    }

    private static function same_params( array $a, array $b ): bool {
        $pa = (array) ( $a['action_params'] ?? [] );
        $pb = (array) ( $b['action_params'] ?? [] );
        ksort( $pa );
        ksort( $pb );
        return $pa == $pb; // phpcs:ignore Universal.Operators.StrictComparisons -- loose: '5' and 5 are the same setting.
    }

    private static function uses_feed( array $rule ): bool {
        foreach ( (array) ( $rule['conditions'] ?? [] ) as $c ) {
            $source = (string) ( $c['source'] ?? '' );
            if ( $source !== '' && ! in_array( $source, [ Stock_Override_Resolver::TAXONOMY_SOURCE, Stock_Override_Resolver::POSTMETA_SOURCE ], true ) ) {
                return true;
            }
        }
        return false;
    }

    private static function write_kind( string $action ): string {
        if ( Custom_Rule_Actions::is_stock_action( $action ) ) {
            return 'stock';
        }
        if ( $action === 'set_taxonomy_term' ) {
            return 'terms';
        }
        if ( $action === 'copy_source_field_to_meta' ) {
            return 'meta';
        }
        return 'save';
    }

    private static function what_it_changes( string $action ): string {
        if ( Custom_Rule_Actions::is_stock_action( $action ) ) {
            return 'stock status';
        }
        if ( str_starts_with( $action, 'set_status_' ) ) {
            return 'post status';
        }
        return 'prices';
    }
}
