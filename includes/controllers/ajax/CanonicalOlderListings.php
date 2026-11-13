<?php
/**
 * Duplicates, source 'older': an old listing of a product we still sell under a newer listing.
 *
 * A distributor can replace its SKUs and rewrite its titles, which leaves the old listing behind:
 * out of stock, gone from every feed, and named differently ("Restoration Suite 2 Audio Restoration
 * and Noise Reduction Plug-Ins" beside "Restoration Suite 2"). The feed scan can't see the pair, as
 * one side has left the feeds, and an exact name match misses it, so nothing links them and every
 * screen that reads the catalog shows the product twice.
 *
 * Linking such a pair changes nothing a customer can buy (the old listing is already off sale), so
 * it can be done in bulk, here and from other screens. That makes the match strict: a pair that
 * isn't certain is left out, and a person can still link it by hand.
 *
 * @package MannMade\DataPipeline
 */

defined( 'ABSPATH' ) || exit;

/** Words that make a title a different product or edition when they follow the shared name. */
const MMI_DUPES_OLDER_NOT_DESCRIPTIVE = [
    'pro', 'premium', 'standard', 'ultimate', 'lite', 'elements', 'essential', 'essentials', 'advanced', 'artist', 'studio',
    'producer', 'le', 'se', 'mini', 'plus', 'max', 'complete', 'bundle', 'collection', 'suite', 'expansion', 'pack', 'library',
    'promo', 'loyalty', 'with', 'for', 'nfr', 'vol', 'volume', 'part', 'ii', 'iii', 'iv',
];

/** The last word of a tail that describes a product instead of naming one. */
const MMI_DUPES_OLDER_GENERIC_NOUNS = [
    'software', 'plugin', 'instrument', 'instruments', 'synthesizer', 'synth', 'processor', 'effect', 'effects',
    'reverb', 'delay', 'equalizer', 'compressor', 'limiter', 'sampler', 'workstation',
];

/** Name words that identify nothing. */
const MMI_DUPES_OLDER_FILLER = [ 'a', 'an', 'the', 'edition', 'version' ];

/** Bracket words that say how a listing is sold, not what it is. */
const MMI_DUPES_OLDER_SALE_WORDS = [ 'download', 'downloads', 'digital', 'esd', 'dld', 'perpetual', 'license', 'licence', 'retail', 'plugin', 'plug', 'in', 'competitive', 'subscription', 'for', 'of', 'and' ];

/** Words of a "from" clause that identify nothing. */
const MMI_DUPES_OLDER_FROM_FILLER = [ 'any', 'all', 'or', 'and', 'previous', 'prior', 'earlier', 'older', 'version', 'versions', 'edition', 'the' ];

/**
 * A title's parts, for comparing two listings of one brand.
 *
 * @return array{words: string[], nums: string[], head: int, joined: bool, verb: string, audience: string, format: string, plan: bool, term: string, from: string, extra: string}
 *         words: the name without numbers. head: name words before a ":" or " - " (a descriptor
 *         follows), PHP_INT_MAX with neither. joined: "+", "&" or "|" in the name. extra: what the
 *         brackets say beyond the offer, such as a cable's length or a bundle's year.
 */
function mmi_canonical_candidates_older_parts( string $title, string $brand ): array {
    $title  = strtolower( remove_accents( html_entity_decode( $title, ENT_QUOTES ) ) );
    $brand  = strtolower( remove_accents( html_entity_decode( $brand, ENT_QUOTES ) ) );
    $detail = preg_match_all( '/\(([^)]*)\)/', $title, $m ) ? implode( ', ', $m[1] ) : '';
    $core   = trim( (string) preg_replace( '/\([^)]*\)|\[[^\]]*\]/', ' ', $title ) );
    if ( $brand !== '' && strpos( $core, $brand ) === 0 ) {
        $core = ltrim( substr( $core, strlen( $brand ) ) );
    }

    // The offer's wording can sit outside the brackets ("… Crossgrade (Download)", "(2024.4): Upgrade").
    $verb = preg_match( '/\b(upgrade|update|crossgrade|xgrade|renewal|reinstatement|conversion|loyalty)\b/', $title, $v ) ? str_replace( 'xgrade', 'crossgrade', $v[1] ) : '';
    $from = preg_match( '/\bfrom\s+([^,)]+)/', $detail . ',', $f ) ? trim( $f[1] ) : '';
    $core = (string) preg_replace( '/\b(upgrade|update|crossgrade|xgrade|renewal|reinstatement|conversion)\b/', ' ', $core );

    $bracket = (string) preg_replace( '/\bfrom\s+[^,)]+/', ' ', $detail );
    $bracket = (string) preg_replace( '/\b\d+\s*-?\s*(?:year|yr|month|mo)s?\b|\b(?:upgrade|update|crossgrade|xgrade|renewal|reinstatement|conversion|loyalty|student|teacher|edu|educational|academic|institution|boxed|box|year|month|monthly|annual|plan)\b/', ' ', $bracket );
    $extra   = array_diff( preg_split( '/[^a-z0-9.\'"]+/', $bracket, -1, PREG_SPLIT_NO_EMPTY ), MMI_DUPES_OLDER_SALE_WORDS );
    sort( $extra );

    $core = (string) preg_replace( '/(\d+)\.x\b/', '$1', $core ); // "7.x" is version 7
    $core = (string) preg_replace( '/\bplug-?ins?\b/', 'plugin', $core );
    $core = (string) preg_replace( '/\b([a-z]{3,})(\d{1,2})\b/', '$1 $2', $core ); // "Immersive2"
    $core = (string) preg_replace( '/\bpro audio\b/', 'audio', $core ); // a description, not the Pro edition

    $head  = PHP_INT_MAX;
    $words = [];
    $nums  = [];
    foreach ( preg_split( '/(:|\s[-–—]\s)|[^a-z0-9.]+/u', $core, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE ) as $w ) {
        $w = trim( $w );
        if ( $w === ':' || $w === '-' || $w === '–' || $w === '—' ) {
            $head = min( $head, count( $words ) );
            continue;
        }
        $w = trim( $w, '.' );
        if ( $w === '' || in_array( $w, MMI_DUPES_OLDER_FILLER, true ) ) {
            continue;
        }
        if ( is_numeric( $w ) ) {
            $nums[] = $w;
        } else {
            $words[] = $w;
        }
    }

    return [
        'words'    => $words,
        'nums'     => $nums,
        'head'     => $head,
        'joined'   => (bool) preg_match( '/[+&|]/', $core ),
        'verb'     => $verb,
        'audience' => preg_match( '/\b(student|teacher|edu|educational|academic|institution)\b/', $title ) ? 'edu' : 'retail',
        'format'   => preg_match( '/\bbox(ed)?\b/', $title ) ? 'box' : 'download',
        'plan'     => (bool) preg_match( '/\b(subscription|year|month|monthly|annual|plan)\b/', $detail ),
        'term'     => preg_match( '/(\d+)\s*-?\s*(year|yr|month|mo)\b/', $detail, $t ) ? $t[1] . $t[2][0] : '',
        'from'     => $from,
        'extra'    => implode( ' ', $extra ),
    ];
}

/** Do two "from" clauses name the same starting product? Either may name none. */
function mmi_canonical_candidates_older_from_agrees( string $old, string $live, array $name_words ): bool {
    if ( $old === '' || $live === '' ) {
        return true;
    }
    $read = static function ( string $s ) use ( $name_words ): array {
        $words = preg_split( '/[^a-z0-9.]+/', $s, -1, PREG_SPLIT_NO_EMPTY );
        return array_values( array_diff( $words, MMI_DUPES_OLDER_FROM_FILLER, $name_words ) );
    };
    $o = $read( $old );
    $l = $read( $live );
    // "Version 2" sits inside "1 or 2", "Predator PL" doesn't. Either side may be the fuller one.
    return ! array_diff( $o, $l ) || ! array_diff( $l, $o );
}

/**
 * Is the old listing's title the live listing's, with at most a description added? Same numbers,
 * same offer, the live name word for word at its start, and what follows only describes it.
 */
function mmi_canonical_candidates_older_matches( array $old, array $live ): bool {
    foreach ( [ 'verb', 'audience', 'format', 'plan', 'term', 'extra' ] as $field ) {
        if ( $old[ $field ] !== $live[ $field ] ) {
            return false;
        }
    }
    $n = count( $live['words'] );
    if ( ! $n || array_diff( $old['nums'], $live['nums'] ) || array_diff( $live['nums'], $old['nums'] ) ) {
        return false;
    }
    if ( array_slice( $old['words'], 0, $n ) !== $live['words'] ) {
        return false;
    }
    if ( $old['verb'] !== '' && ! mmi_canonical_candidates_older_from_agrees( $old['from'], $live['from'], $live['words'] ) ) {
        return false;
    }
    $tail = array_values( array_diff( array_slice( $old['words'], $n ), $live['words'] ) );
    if ( ! $tail ) {
        return true;
    }
    if ( ( $old['joined'] && ! $live['joined'] ) || count( $tail ) > 9 ) {
        return false;
    }
    // "Name: three or more words" is a tagline, even with an edition word in it ("Pro Surround Reverb Software").
    if ( $old['head'] <= $n && count( $tail ) >= 3 ) {
        return true;
    }
    if ( array_intersect( $tail, MMI_DUPES_OLDER_NOT_DESCRIPTIVE ) ) {
        return false;
    }
    return $old['head'] <= $n || in_array( end( $tail ), MMI_DUPES_OLDER_GENERIC_NOUNS, true );
}

/**
 * Every old listing that matches a listing on sale.
 *
 * @param array $feed_index From mmi_canonical_candidates_build_feed_index().
 * @return array<int,array{title: string, sku: string, brand: string, old: array<int,array{title: string, sku: string}>}> live product id => its older listings.
 */
function mmi_canonical_candidates_older_pairs( array $feed_index ): array {
    global $wpdb;

    // A supplier we couldn't read could still sell any listing, so nothing can be called gone.
    if ( array_diff( array_keys( mmi_canonical_candidates_feed_configs() ), array_keys( $feed_index ) ) ) {
        return [];
    }
    $in_feed  = [];
    $listings = mmi_canonical_candidates_listing_map();
    foreach ( $feed_index as $supplier => $items ) {
        foreach ( $items as $sku => $item ) {
            $in_feed[ 'sku:' . $sku ] = true;
            if ( isset( $listings['ids'][ $supplier ][ (string) $sku ] ) ) {
                $in_feed[ 'id:' . $listings['ids'][ $supplier ][ (string) $sku ] ] = true;
            }
        }
    }

    $rows = $wpdb->get_results(
        "SELECT p.ID id, p.post_title title,
                MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) sku,
                MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) stock,
                MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) price,
                MAX(CASE WHEN pm.meta_key = 'canonical' THEN pm.meta_value END) canonical
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_sku', '_stock_status', '_price', 'canonical')
         WHERE p.post_type = 'product' AND p.post_status = 'publish'
         GROUP BY p.ID",
        ARRAY_A
    );

    // A listing already in a group has its answer: the canonical of one is never someone's old listing.
    $grouped = [];
    if ( class_exists( 'MMI_Pipeline_Catalog_Updater' ) ) {
        foreach ( MMI_Pipeline_Catalog_Updater::duplicate_groups() as $canonical => $ids ) {
            $grouped[ (int) $canonical ] = true;
            foreach ( $ids as $id ) {
                $grouped[ (int) $id ] = true;
            }
        }
    }
    $dismissed = array_flip( (array) MMI_DB::get_setting( 'mmi_canonical_candidates_dismissed', [] ) );

    $live = [];
    $old  = [];
    foreach ( $rows as $r ) {
        $id    = (int) $r['id'];
        $brand = $listings['brands'][ $id ] ?? '';
        if ( $brand === '' || (string) $r['sku'] === '' || (string) $r['canonical'] === 'FALSE' ) {
            continue;
        }
        $r['parts'] = mmi_canonical_candidates_older_parts( (string) $r['title'], $brand );
        $key        = strtolower( $brand );
        if ( $r['stock'] === 'instock' && (float) $r['price'] > 0 ) {
            $live[ $key ][ $id ] = $r + [ 'brand' => $brand ];
        } elseif ( $r['stock'] !== 'instock' && ! isset( $grouped[ $id ] ) && ! isset( $in_feed[ 'id:' . $id ] ) && ! isset( $in_feed[ 'sku:' . $r['sku'] ] ) ) {
            $old[ $key ][ $id ] = $r;
        }
    }

    $pairs = [];
    foreach ( $old as $key => $list ) {
        foreach ( $list as $id => $o ) {
            $hits = [];
            foreach ( $live[ $key ] ?? [] as $lid => $l ) {
                if ( mmi_canonical_candidates_older_matches( $o['parts'], $l['parts'] ) ) {
                    $hits[ $lid ] = count( $l['parts']['words'] );
                }
            }
            if ( ! $hits ) {
                continue;
            }
            // The closest name wins: "Verberate Immersive 2" over "Verberate 2".
            $best = array_keys( $hits, max( $hits ), true );
            sort( $best );
            $pick = $best[0];
            if ( count( $best ) > 1 ) {
                // Two live listings with one name are one product (Fresh match says so); different
                // offers ("from Standard", "from Premium") leave the old listing's offer unknown.
                $from = array_unique( array_map( static fn( $lid ) => $live[ $key ][ $lid ]['parts']['from'], $best ) );
                if ( count( $from ) > 1 ) {
                    continue;
                }
                foreach ( $best as $lid ) {
                    if ( isset( $grouped[ $lid ] ) ) {
                        $pick = $lid;
                        break;
                    }
                }
            }
            if ( isset( $dismissed[ "older-{$pick}-{$id}" ] ) ) {
                continue;
            }
            $l = $live[ $key ][ $pick ];
            $pairs[ $pick ]['title']      = html_entity_decode( (string) $l['title'], ENT_QUOTES );
            $pairs[ $pick ]['sku']        = (string) $l['sku'];
            $pairs[ $pick ]['brand']      = (string) $l['brand'];
            $pairs[ $pick ]['old'][ $id ] = [ 'title' => html_entity_decode( (string) $o['title'], ENT_QUOTES ), 'sku' => (string) $o['sku'] ];
        }
    }
    return $pairs;
}

/**
 * The pairs as queue groups, one row per listing on sale.
 *
 * @param array $feed_index From mmi_canonical_candidates_build_feed_index().
 */
function mmi_canonical_candidates_older_groups( array $feed_index ): array {
    $groups = [];
    foreach ( mmi_canonical_candidates_older_pairs( $feed_index ) as $live_id => $pair ) {
        $members = [ mmi_canonical_candidates_build_existing_member( (int) $live_id, get_post_meta( $live_id, 'canonical', true ) === 'TRUE', $feed_index ) ];
        foreach ( array_keys( $pair['old'] ) as $old_id ) {
            $members[] = mmi_canonical_candidates_build_existing_member( (int) $old_id, false, $feed_index );
        }
        foreach ( $members as $i => &$member ) {
            $member['is_recommended'] = $i === 0; // the one on sale, whatever the old listing cost
        }
        unset( $member );
        mmi_canonical_candidates_price_summary( $members ); // each member's own price, for its row
        // An old listing's price is a leftover nobody can pay (often a $55 placeholder), so the group's
        // price is the one on sale: no false "prices differ", and the queue's spread sort stays honest.
        $price    = (float) $members[0]['list_price'];
        $groups[] = [
            'group_key'    => 'older-' . $live_id,
            'source'       => 'older',
            'brand'        => $pair['brand'],
            'name'         => $pair['title'],
            'members'      => $members,
            'price_min'    => $price,
            'price_max'    => $price,
            'price_spread' => 0.0,
            'price_count'  => $price > 0 ? 1 : 0,
            'price_match'  => false,
        ] + [
            'already_linked'         => false,
            'recommended_product_id' => (int) $live_id,
            'brand_thumbnail_url'    => mmi_canonical_candidates_brand_thumbnail_url( $pair['brand'] ),
            'image_url'              => mmi_canonical_candidates_group_image_url( array_merge( [ (int) $live_id ], array_keys( $pair['old'] ) ) ),
        ];
    }
    return $groups;
}

/**
 * Refreshes the review index's older listings without building the queue's rows, for a job that
 * only needs the index to be current (a Product Paths build). Returns the groups found.
 */
function mmi_canonical_candidates_older_refresh_index(): int {
    $groups = [];
    foreach ( mmi_canonical_candidates_older_pairs( mmi_canonical_candidates_build_feed_index() ) as $live_id => $pair ) {
        $members = [ [ 'wc_product_id' => (int) $live_id, 'sku' => $pair['sku'] ] ];
        foreach ( $pair['old'] as $old_id => $old ) {
            $members[] = [ 'wc_product_id' => (int) $old_id, 'sku' => $old['sku'] ];
        }
        $groups[] = [ 'group_key' => 'older-' . $live_id, 'name' => $pair['title'], 'members' => $members, 'recommended_product_id' => (int) $live_id ];
    }
    mmi_canonical_candidates_save_review_index( $groups, 'older' );
    return count( $groups );
}

/**
 * Links old listings to the listing on sale that the last scan paired them with. Only pairs in the
 * review index are linked, and a screen can refuse one it holds person-set data on (the
 * `mmi_duplicates_older_link_blocked` filter returns why), so a bulk click can't lose a decision.
 *
 * @param int   $live_id
 * @param int[] $old_ids
 * @return array{linked: int[], skipped: array<int,string>}
 */
function mmi_canonical_candidates_link_older( int $live_id, array $old_ids ): array {
    $key   = 'older-' . $live_id;
    $index = mmi_canonical_candidates_review_index();
    $group = $index['groups'][ $key ] ?? null;
    $known = $group ? array_diff( array_map( 'intval', (array) $group['product_ids'] ), [ $live_id ] ) : [];

    $link    = [];
    $skipped = [];
    foreach ( array_unique( array_map( 'intval', $old_ids ) ) as $old_id ) {
        // audit-ok: haystack is one older-listings group's product IDs, a handful
        if ( ! in_array( $old_id, $known, true ) ) {
            $skipped[ $old_id ] = 'No longer paired with this listing.';
            continue;
        }
        if ( get_post_meta( $old_id, '_stock_status', true ) === 'instock' ) {
            $skipped[ $old_id ] = 'Back in stock since the last scan.';
            continue;
        }
        $reason = (string) apply_filters( 'mmi_duplicates_older_link_blocked', '', $old_id, (string) get_post_meta( $old_id, '_sku', true ) );
        if ( $reason !== '' ) {
            $skipped[ $old_id ] = $reason;
            continue;
        }
        $link[] = $old_id;
    }
    if ( ! $link ) {
        return [ 'linked' => [], 'skipped' => $skipped ];
    }
    if ( get_post_status( $live_id ) !== 'publish' || get_post_meta( $live_id, '_stock_status', true ) !== 'instock' ) {
        return [ 'linked' => [], 'skipped' => $skipped + array_fill_keys( $link, 'The listing on sale has gone out of stock.' ) ];
    }

    $prior = array_map( static fn( $id ) => [ 'id' => $id, 'canonical' => (string) get_post_meta( $id, 'canonical', true ) ], $link );
    $done  = mmi_canonical_candidates_link_group( $live_id, $link, [], false );

    $left = array_values( array_diff( array_map( 'intval', (array) $group['product_ids'] ), $link ) );
    $now  = mmi_canonical_candidates_review_index(); // the link just rewrote it
    if ( isset( $now['groups'][ $key ] ) ) {
        if ( count( $left ) > 1 ) {
            $now['groups'][ $key ]['product_ids'] = $left;
        } else {
            unset( $now['groups'][ $key ] );
        }
        MMI_DB::set_setting( 'mmi_canonical_candidates_review_index', [ 'scanned_at' => $now['scanned_at'], 'groups' => $now['groups'] ] );
    }

    mmi_data_pipeline_audit( 'canonical.link_older', [
        'object_type' => 'product',
        'object_id'   => $live_id,
        'outcome'     => 'success',
        'details'     => [ 'linked' => $prior, 'associated_product_ids' => $done['associated'], 'suppressed' => $done['suppressed'] ],
    ] );
    return [ 'linked' => $link, 'skipped' => $skipped ];
}

/** "Not the same product": these pairs stay out of the queue, whatever the next scan reads. */
function mmi_canonical_candidates_dismiss_older( int $live_id, array $old_ids ): void {
    $dismissed = (array) MMI_DB::get_setting( 'mmi_canonical_candidates_dismissed', [] );
    foreach ( array_map( 'intval', $old_ids ) as $old_id ) {
        // audit-ok: loop is one listing's few old IDs, so the dismissed list is scanned a handful of times
        if ( $old_id && $old_id !== $live_id && ! in_array( "older-{$live_id}-{$old_id}", $dismissed, true ) ) {
            $dismissed[] = "older-{$live_id}-{$old_id}";
        }
    }
    MMI_DB::set_setting( 'mmi_canonical_candidates_dismissed', $dismissed );

    $key   = 'older-' . $live_id;
    $index = mmi_canonical_candidates_review_index();
    if ( isset( $index['groups'][ $key ] ) ) {
        $left = array_values( array_diff( array_map( 'intval', (array) $index['groups'][ $key ]['product_ids'] ), array_map( 'intval', $old_ids ) ) );
        if ( count( $left ) > 1 ) {
            $index['groups'][ $key ]['product_ids'] = $left;
        } else {
            unset( $index['groups'][ $key ] );
        }
        MMI_DB::set_setting( 'mmi_canonical_candidates_review_index', [ 'scanned_at' => $index['scanned_at'], 'groups' => $index['groups'] ] );
    }
    delete_transient( 'mmi_canonical_existing_groups_scan_cache' );
    do_action( 'mmi_duplicate_groups_changed' );
}

/* ── Link older listings in bulk (Duplicates queue) ─────────────────────────── */
add_action( 'wp_ajax_mmi_link_older_listings', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }
    $index   = mmi_canonical_candidates_review_index();
    $linked  = 0;
    $skipped = [];
    foreach ( array_map( 'sanitize_text_field', (array) ( $_POST['group_keys'] ?? [] ) ) as $key ) {
        if ( ! preg_match( '/^older-(\d+)$/', $key, $m ) || ! isset( $index['groups'][ $key ] ) ) {
            continue;
        }
        $live   = (int) $m[1];
        $result = mmi_canonical_candidates_link_older( $live, array_diff( array_map( 'intval', (array) $index['groups'][ $key ]['product_ids'] ), [ $live ] ) );
        $linked += count( $result['linked'] );
        $skipped += $result['skipped'];
    }
    wp_send_json_success( [ 'linked' => $linked, 'skipped' => $skipped ] );
} );
