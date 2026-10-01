<?php
/**
 * Duplicate Products (Canonical Candidates) AJAX Controller
 *
 * Detects products from supplier feeds that appear to be the same physical
 * item listed more than once (e.g. via different vendors/distributors at
 * different dealer costs) and lets the user review and link them using the
 * existing `canonical` / `associated_product_ids` postmeta that
 * CatalogUpdater already consumes — see class-product-meta-boxes.php and
 * mmi-hub/includes/updaters/CLI/CatalogUpdater.php. This controller only
 * automates the *discovery* of candidates; it does not change how they're
 * consumed downstream.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MannMade\DataPipeline\Importers\Product_CRUD_Manager;

/* ── Nonce used by all duplicate-products AJAX calls ──────────────────────── */
const CANONICAL_CANDIDATES_NONCE = 'mmi_pipeline_canonical_candidates';

/**
 * Per-supplier feed field map. Skuport has no distinct "vendor" signal (its
 * source data only has a developer/brand-like field), so vendor stays null
 * for it — the UI shows "—" for those rows. xchange has both.
 */
function mmi_canonical_candidates_feed_configs(): array {
    return [
        'xchange' => [
            'file'         => 'xchange-products.json',
            'sku_field'    => 'sku',
            'name_field'   => 'product',
            'brand_field'  => 'brand',
            'vendor_field' => 'vendor',
            'dealer_field' => 'dealer_price',
            'map_field'    => 'map_price',
            'msrp_field'   => 'msrp_price',
        ],
        'skuport' => [
            'file'         => 'skuport-products.json',
            'sku_field'    => 'id',
            'name_field'   => 'name',
            'brand_field'  => 'developer',
            'vendor_field' => null,
            'dealer_field' => 'cost',
            'map_field'    => 'map',
            'msrp_field'   => 'msrp',
        ],
    ];
}

/** Normalize a brand+product-name pair into a stable, comparable group key. */
function mmi_canonical_candidates_group_key( string $brand, string $name ): string {
    $norm = static fn( string $s ): string => preg_replace( '/\s+/', ' ', trim( strtolower( $s ) ) );
    return $norm( $brand ) . '|' . $norm( $name );
}

/**
 * Generic "product data winner" field registry — the customer-facing data
 * points an admin can pick a per-field winner for across a duplicate
 * group's resolved members, independent of which member is chosen as
 * canonical (promote any candidate to canonical while still using the best
 * available data, field by field, from any member — see
 * Product_CRUD_Manager::apply_field_winners()). Deliberately a short,
 * explicit list rather than every postmeta key a product carries — more
 * fields can be added here later with no other code needing to change,
 * since both the review UI (duplicate-products.js) and the apply step key
 * off this same list.
 */
function mmi_canonical_candidates_asset_field_defs(): array {
    return [
        'image'   => [ 'label' => 'Image' ],
        'gallery' => [ 'label' => 'Gallery' ],
        'content' => [ 'label' => 'Description' ],
        'title'   => [ 'label' => 'Title' ],
        'excerpt' => [ 'label' => 'Short Desc' ],
    ];
}

/**
 * Per-product snapshot of the fields mmi_canonical_candidates_asset_field_defs()
 * lists — presence + a short preview, so the review UI can show what each
 * resolved member actually has to offer without pulling the whole post body
 * over the wire. A product legitimately has none of these (the whole reason
 * this feature exists — "may or may not have been present with the data
 * source data which created whichever product is currently canonical").
 */
function mmi_canonical_candidates_asset_snapshot( int $product_id ): array {
    if ( ! $product_id ) {
        return [];
    }

    $thumb_id    = (int) get_post_thumbnail_id( $product_id );
    $gallery     = get_post_meta( $product_id, '_product_image_gallery', true );
    $gallery_ids = array_filter( array_map( 'intval', explode( ',', (string) $gallery ) ) );
    $content     = (string) get_post_field( 'post_content', $product_id );
    $excerpt     = (string) get_post_field( 'post_excerpt', $product_id );

    return [
        // Raw stored value (not get_the_title()) so the preview shown here
        // matches exactly what Product_CRUD_Manager::apply_field_winners()
        // would actually copy if this member is picked as the title winner.
        'title'           => (string) get_post_field( 'post_title', $product_id ),
        'has_image'       => $thumb_id > 0,
        'image_thumb_url' => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '',
        'gallery_count'   => count( $gallery_ids ),
        'has_content'     => trim( wp_strip_all_tags( $content ) ) !== '',
        'content_preview' => mmi_canonical_candidates_text_preview( $content ),
        'has_excerpt'     => trim( $excerpt ) !== '',
        'excerpt_preview' => mmi_canonical_candidates_text_preview( $excerpt ),
    ];
}

/** Short plain-text preview for a review-panel cell — not a full render. */
function mmi_canonical_candidates_text_preview( string $html, int $length = 60 ): string {
    $text = trim( wp_strip_all_tags( $html ) );
    if ( $text === '' ) {
        return '';
    }
    return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length ) . '…' : $text;
}

/**
 * Resolves a brand NAME (as it appears in feed data or a canonical product's
 * own product_brand terms — this controller never has a term_id directly, only
 * a display string) to its real WooCommerce term thumbnail, via the same
 * `thumbnail_id` term-meta convention WooCommerce's native Product Brands
 * taxonomy already uses for `product_cat` and `product_brand` alike (confirmed
 * live: `get_term_meta( $term_id, 'thumbnail_id', true )` → a real attachment
 * ID on this install's actual brand terms). Statically cached per request —
 * this is called once per group row, and many groups share the same brand.
 *
 * @param string $brand
 * @return string Thumbnail URL, or '' if the brand has no matching term or no image.
 */
function mmi_canonical_candidates_brand_thumbnail_url( string $brand ): string {
    static $cache = [];

    $brand = trim( $brand );
    if ( $brand === '' ) {
        return '';
    }
    $key = strtolower( $brand );
    if ( array_key_exists( $key, $cache ) ) {
        return $cache[ $key ];
    }

    $url  = '';
    $term = get_term_by( 'name', $brand, 'product_brand' );
    if ( $term instanceof WP_Term ) {
        $thumb_id = get_term_meta( $term->term_id, 'thumbnail_id', true );
        if ( $thumb_id ) {
            $url = (string) ( wp_get_attachment_image_url( (int) $thumb_id, 'thumbnail' ) ?: '' );
        }
    }

    $cache[ $key ] = $url;
    return $url;
}

/**
 * A WC product's own featured image, at thumbnail size. Statically cached per
 * request for the same reason as the brand lookup above — a group's
 * recommended/canonical member is looked up once but this whole scan can
 * touch hundreds of products.
 *
 * @param int $product_id
 * @return string Thumbnail URL, or '' if the product has no featured image.
 */
function mmi_canonical_candidates_product_thumbnail_url( int $product_id ): string {
    static $cache = [];

    if ( ! $product_id ) {
        return '';
    }
    if ( array_key_exists( $product_id, $cache ) ) {
        return $cache[ $product_id ];
    }

    $url      = '';
    $thumb_id = get_post_thumbnail_id( $product_id );
    if ( $thumb_id ) {
        $url = (string) ( wp_get_attachment_image_url( (int) $thumb_id, 'thumbnail' ) ?: '' );
    }

    $cache[ $product_id ] = $url;
    return $url;
}

/**
 * Parses associated_product_ids into a clean int[] regardless of storage
 * shape. Two real shapes exist in production data: a flat delimited string
 * (written by mmi_approve_duplicate_group below) and a nested repeater-style
 * array (['item-0' => ['associated_product_id' => '6451'], ...] — the shape
 * ~1,000 pre-existing real links on this install use, origin tool unknown).
 * $raw may already be an unserialized array (get_post_meta()'s return value
 * auto-unserializes) or a raw string (a direct $wpdb column read never does).
 * Mirrors MMI_Pipeline_Catalog_Updater::parse_associated_product_ids()
 * (class-pipeline-catalog-updater.php) — kept as a separate small function
 * here rather than a cross-file call, since that method is private and this
 * controller shouldn't reach into another class's internals for a ~15-line
 * parse. A naive `array_map('intval', $assoc)` on the nested shape silently
 * produces [1,1,1] (PHP's array-to-int truthy cast), not real product IDs —
 * confirmed live against product #18521's real associated_product_ids before
 * this fix; this was already wrong for the pre-existing $already_linked
 * check below, not just for the new recommendation logic added alongside it.
 *
 * @param mixed $raw
 * @return int[]
 */
function mmi_canonical_candidates_parse_associated_ids( $raw ): array {
    if ( is_array( $raw ) ) {
        $unserialized = $raw;
    } elseif ( is_string( $raw ) && trim( $raw ) !== '' ) {
        $unserialized = maybe_unserialize( $raw );
    } else {
        return [];
    }

    if ( is_array( $unserialized ) ) {
        $ids = [];
        foreach ( $unserialized as $item ) {
            $id = is_array( $item )
                ? (int) ( $item['associated_product_id'] ?? $item['product_id'] ?? $item['ID'] ?? $item['id'] ?? 0 )
                : (int) $item;
            if ( $id ) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    return array_filter( array_map( 'intval', preg_split( '/[,\|\s]+/', trim( (string) $unserialized ) ) ) );
}

/**
 * Recommends a group's canonical member by lowest non-zero dealer_price
 * among RESOLVED members only (a real wc_product_id — an unresolved,
 * feed-only row can't be picked as canonical, matching the review UI's own
 * resolved-only radio-button gating). Ties broken by lowest wc_product_id
 * (deterministic). Falls back to the first resolved member when every
 * resolved member's dealer_price is zero/missing, so a group with no cost
 * signal still gets a recommendation rather than none.
 *
 * @param array $members
 * @return array|null The recommended member, or null if no member resolved.
 */
function mmi_canonical_candidates_recommend( array $members ): ?array {
    $resolved = array_filter( $members, fn( $m ) => ! empty( $m['wc_product_id'] ) );
    if ( empty( $resolved ) ) {
        return null;
    }

    $with_price = array_filter( $resolved, fn( $m ) => (float) $m['dealer_price'] > 0 );
    if ( ! empty( $with_price ) ) {
        usort( $with_price, function ( $a, $b ) {
            $cmp = $a['dealer_price'] <=> $b['dealer_price'];
            return $cmp !== 0 ? $cmp : ( (int) $a['wc_product_id'] <=> (int) $b['wc_product_id'] );
        } );
        return reset( $with_price );
    }

    return reset( $resolved );
}

/* ── Scan all configured feeds for duplicate candidates ───────────────────── */
add_action( 'wp_ajax_mmi_scan_duplicate_candidates', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    if ( ! (bool) MMI_DB::get_setting( 'mmi_duplicate_products_enabled', true ) ) {
        wp_send_json_error( [ 'code' => 'disabled', 'message' => 'Duplicate Products scanning is disabled — enable it via the section toggle before scanning.' ] );
    }

    $force_refresh = ! empty( $_POST['force_refresh'] );
    if ( ! $force_refresh ) {
        $cached = get_transient( 'mmi_canonical_candidates_scan_cache' );
        if ( $cached !== false ) {
            wp_send_json_success( $cached );
        }
    }

    @set_time_limit( 120 );

    $json_dir = mmi_shared_lib_json_dir();

    $dismissed = array_flip( (array) MMI_DB::get_setting( 'mmi_canonical_candidates_dismissed', [] ) );

    // group_key => [ 'brand' => ..., 'name' => ..., 'members' => [ ...rows... ] ]
    $groups = [];

    foreach ( mmi_canonical_candidates_feed_configs() as $supplier => $cfg ) {
        $json_file = $json_dir . $cfg['file'];
        if ( ! file_exists( $json_file ) ) {
            continue;
        }
        $data = json_decode( file_get_contents( $json_file ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            continue;
        }
        $items = $data['products'] ?? $data['items'] ?? ( is_array( $data ) ? $data : [] );

        foreach ( $items as $item ) {
            $brand = (string) ( $item[ $cfg['brand_field'] ] ?? '' );
            $name  = (string) ( $item[ $cfg['name_field']  ] ?? '' );
            if ( $brand === '' || $name === '' ) {
                continue;
            }

            $key = mmi_canonical_candidates_group_key( $brand, $name );
            if ( isset( $dismissed[ $key ] ) ) {
                continue;
            }

            $sku    = (string) ( $item[ $cfg['sku_field'] ] ?? '' );
            $vendor = $cfg['vendor_field'] ? (string) ( $item[ $cfg['vendor_field'] ] ?? '' ) : '';

            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = [ 'brand' => $brand, 'name' => $name, 'members' => [] ];
            }
            $groups[ $key ]['members'][] = [
                'supplier'     => $supplier,
                'sku'          => $sku,
                'vendor'       => $vendor,
                'dealer_price' => (float) ( $item[ $cfg['dealer_field'] ] ?? 0 ),
                'map_price'    => (float) ( $item[ $cfg['map_field']    ] ?? 0 ),
                'msrp_price'   => (float) ( $item[ $cfg['msrp_field']   ] ?? 0 ),
            ];
        }
    }

    $result = [];
    foreach ( $groups as $key => $group ) {
        if ( count( $group['members'] ) < 2 ) {
            continue; // Single listing — nothing to deduplicate.
        }

        $resolved_ids = [];
        foreach ( $group['members'] as &$member ) {
            $supplier   = $member['supplier'];
            $wc_key     = MMI_DB::get_primary_key( $supplier, 'wc', '_sku' );
            $product_id = MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key( $wc_key, $member['sku'] );

            $member['wc_product_id'] = $product_id;
            $member['is_canonical']  = false;

            if ( $product_id ) {
                $resolved_ids[]         = $product_id;
                $member['is_canonical'] = get_post_meta( $product_id, 'canonical', true ) === 'TRUE';
                $member['assets']       = mmi_canonical_candidates_asset_snapshot( $product_id );
                // Matches the legacy member builder's own field so the review
                // table's Status column reads the same way for either source.
                $member['stock_status'] = (string) get_post_meta( $product_id, '_stock_status', true );
            }
        }
        unset( $member );

        // Already fully resolved when one resolved member is canonical and its
        // associated_product_ids already covers every other resolved member.
        $already_linked = false;
        foreach ( $group['members'] as $member ) {
            if ( empty( $member['is_canonical'] ) ) {
                continue;
            }
            // Real associated_product_ids storage shape is a nested repeater-
            // style array on this install (see mmi_canonical_candidates_parse_
            // associated_ids()'s own docblock) — a naive array_map('intval', ...)
            // on that shape silently produces [1,1,1], not real product IDs,
            // which previously made this check false-negative for every
            // already-linked group in that shape.
            $assoc     = get_post_meta( $member['wc_product_id'], 'associated_product_ids', true );
            $assoc_ids = mmi_canonical_candidates_parse_associated_ids( $assoc );
            $others    = array_diff( $resolved_ids, [ $member['wc_product_id'] ] );
            if ( empty( array_diff( $others, $assoc_ids ) ) ) {
                $already_linked = true;
                break;
            }
        }

        $recommended            = mmi_canonical_candidates_recommend( $group['members'] );
        $recommended_product_id = $recommended['wc_product_id'] ?? null;
        foreach ( $group['members'] as &$member ) {
            $member['is_recommended'] = $recommended_product_id !== null
                && $member['wc_product_id'] === $recommended_product_id;
        }
        unset( $member );

        $prices      = array_column( $group['members'], 'dealer_price' );
        $price_min   = $prices ? min( $prices ) : 0;
        $price_max   = $prices ? max( $prices ) : 0;

        $result[] = [
            'group_key'              => $key,
            'brand'                  => $group['brand'],
            'name'                   => $group['name'],
            'members'                => $group['members'],
            'price_min'              => $price_min,
            'price_max'              => $price_max,
            'price_spread'           => $price_max - $price_min,
            'already_linked'         => $already_linked,
            'recommended_product_id' => $recommended_product_id,
            'brand_thumbnail_url'    => mmi_canonical_candidates_brand_thumbnail_url( $group['brand'] ),
            'image_url'              => mmi_canonical_candidates_product_thumbnail_url( (int) $recommended_product_id ),
        ];
    }

    // Biggest price discrepancy first — most actionable for margin protection.
    usort( $result, fn( $a, $b ) => $b['price_spread'] <=> $a['price_spread'] );

    $response = [
        'groups' => $result,
        'total'  => count( $result ),
    ];

    set_transient( 'mmi_canonical_candidates_scan_cache', $response, 10 * MINUTE_IN_SECONDS );

    wp_send_json_success( $response );
} );

/* ── Dismiss a candidate group — not actually a duplicate ─────────────────── */
add_action( 'wp_ajax_mmi_dismiss_duplicate_group', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $group_key = sanitize_text_field( $_POST['group_key'] ?? '' );
    if ( $group_key === '' ) {
        wp_send_json_error( [ 'message' => 'Missing group_key' ] );
    }

    $dismissed = (array) MMI_DB::get_setting( 'mmi_canonical_candidates_dismissed', [] );
    if ( ! in_array( $group_key, $dismissed, true ) ) {
        $dismissed[] = $group_key;
        MMI_DB::set_setting( 'mmi_canonical_candidates_dismissed', $dismissed );
    }

    delete_transient( 'mmi_canonical_candidates_scan_cache' );

    wp_send_json_success( [ 'message' => 'Dismissed' ] );
} );

/* ── Approve a candidate group — write canonical + associated_product_ids ─── */
add_action( 'wp_ajax_mmi_approve_duplicate_group', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $canonical_id = (int) ( $_POST['canonical_product_id'] ?? 0 );
    $associated   = array_map( 'intval', (array) ( $_POST['associated_product_ids'] ?? [] ) );
    $associated   = array_values( array_unique( array_filter(
        $associated,
        fn( $id ) => $id > 0 && $id !== $canonical_id
    ) ) );

    if ( ! $canonical_id || get_post_type( $canonical_id ) !== 'product' ) {
        wp_send_json_error( [ 'message' => 'Invalid canonical product' ] );
    }
    if ( empty( $associated ) ) {
        wp_send_json_error( [ 'message' => 'Select at least one associated product' ] );
    }

    // Per-field winners — see mmi_canonical_candidates_asset_field_defs() and
    // Product_CRUD_Manager::apply_field_winners(). Donor IDs are restricted
    // to the canonical itself or a real member of $associated so a tampered
    // request can't pull data from an unrelated product.
    $allowed_donor_ids = array_merge( [ $canonical_id ], $associated );
    $field_winners_raw = json_decode( wp_unslash( (string) ( $_POST['field_winners'] ?? '' ) ), true );
    $fields_applied     = [];
    if ( is_array( $field_winners_raw ) ) {
        $winners = [];
        foreach ( $field_winners_raw as $field => $donor_id ) {
            $donor_id = (int) $donor_id;
            if ( in_array( $donor_id, $allowed_donor_ids, true ) ) {
                $winners[ (string) $field ] = $donor_id;
            }
        }
        if ( ! empty( $winners ) ) {
            $applied        = Product_CRUD_Manager::apply_field_winners( $canonical_id, $winners );
            $fields_applied = $applied['fields_applied'];
        }
    }

    // Same-MPN groups can hold a second canonical (two half-linked groups of one product). Folding it in:
    // its own links join the chosen canonical's, and it stops being a canonical (the same reset
    // mmi_canonical_remove_group_member makes), so one product never has two canonical listings.
    $merged = [];
    if ( ! empty( $_POST['merge_canonicals'] ) ) {
        foreach ( $associated as $aid ) {
            if ( get_post_meta( $aid, 'canonical', true ) !== 'TRUE' ) {
                continue;
            }
            foreach ( mmi_canonical_candidates_parse_associated_ids( get_post_meta( $aid, 'associated_product_ids', true ) ) as $sub ) {
                if ( $sub > 0 && $sub !== $canonical_id && get_post_type( $sub ) === 'product' ) {
                    $associated[] = $sub;
                }
            }
            delete_post_meta( $aid, 'canonical' );
            delete_post_meta( $aid, 'associated_product_ids' );
            $merged[] = $aid;
        }
        $associated = array_values( array_unique( $associated ) );
    }

    update_post_meta( $canonical_id, 'canonical', 'TRUE' );
    update_post_meta( $canonical_id, 'associated_product_ids', implode( ',', $associated ) );

    delete_transient( 'mmi_canonical_candidates_scan_cache' );
    delete_transient( 'mmi_canonical_existing_groups_scan_cache' );

    mmi_data_pipeline_audit( 'canonical.approve', [
        'object_type' => 'product',
        'object_id'   => $canonical_id,
        'outcome'     => 'success',
        'details'     => [ 'associated_product_ids' => array_values( $associated ) ],
    ] );
    wp_send_json_success( [
        'message'                => 'Linked ' . count( $associated ) . ' product(s) to the canonical listing' . ( $merged ? ' (folded in ' . count( $merged ) . ' former canonical listing(s): #' . implode( ', #', $merged ) . ')' : '' ),
        'canonical_product_id'   => $canonical_id,
        'associated_product_ids' => $associated,
        'fields_applied'         => $fields_applied,
    ] );
} );

/**
 * Apply per-field winners to an already-linked (legacy) group's CURRENT
 * canonical product, independent of reassigning who's canonical — see
 * mmi_canonical_candidates_asset_field_defs()/apply_field_winners() above.
 * Donor IDs are restricted to the canonical itself or a product actually
 * named in its own associated_product_ids, so this can't be used to pull
 * data from a product outside the group being reviewed.
 */
add_action( 'wp_ajax_mmi_canonical_apply_field_winners', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $canonical_id = (int) ( $_POST['canonical_product_id'] ?? 0 );
    if ( ! $canonical_id || get_post_type( $canonical_id ) !== 'product' ) {
        wp_send_json_error( [ 'message' => 'Invalid canonical product' ] );
    }

    $assoc      = get_post_meta( $canonical_id, 'associated_product_ids', true );
    $assoc_ids  = mmi_canonical_candidates_parse_associated_ids( $assoc );
    $allowed    = array_merge( [ $canonical_id ], $assoc_ids );

    $field_winners_raw = json_decode( wp_unslash( (string) ( $_POST['field_winners'] ?? '' ) ), true );
    if ( ! is_array( $field_winners_raw ) || empty( $field_winners_raw ) ) {
        wp_send_json_error( [ 'message' => 'No winners selected' ] );
    }

    $winners = [];
    foreach ( $field_winners_raw as $field => $donor_id ) {
        $donor_id = (int) $donor_id;
        if ( in_array( $donor_id, $allowed, true ) ) {
            $winners[ (string) $field ] = $donor_id;
        }
    }

    $applied = Product_CRUD_Manager::apply_field_winners( $canonical_id, $winners );

    delete_transient( 'mmi_canonical_existing_groups_scan_cache' );

    if ( empty( $applied['fields_applied'] ) ) {
        wp_send_json_error( [ 'message' => 'Nothing to apply — every selected winner already matches the canonical product' ] );
    }

    mmi_data_pipeline_audit( 'canonical.apply_field_winners', [
        'object_type' => 'product',
        'object_id'   => $canonical_id,
        'outcome'     => 'success',
    ] );
    wp_send_json_success( [
        'message'        => 'Applied ' . count( $applied['fields_applied'] ) . ' field(s) to the canonical product',
        'fields_applied' => $applied['fields_applied'],
    ] );
} );

/**
 * Confirmed Collisions — a different detection mode from the candidate scan
 * above, sharing the same section under its generalized "Duplicates" name.
 * A candidate is "these might be the same item across two vendor feeds"
 * (fuzzy brand+name match, resolved by linking as canonical/associated). A
 * collision is "two separate WC products both carry the SAME supplier
 * tracking key" — a real data-integrity defect, not a legitimate multi-
 * vendor scenario, with only one of the two ever reachable by any future
 * import run (see MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key()'s
 * unordered LIMIT 1). See the SKU 1035-2515 investigation this mode was
 * built for.
 *
 * Whole-catalog, not scoped to one import profile's feed — a collision is a
 * fact about the store's product table, independent of which profile (if
 * any) currently previews that value.
 */
add_action( 'wp_ajax_mmi_scan_pk_collisions', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    if ( ! (bool) MMI_DB::get_setting( 'mmi_duplicate_products_enabled', true ) ) {
        wp_send_json_error( [ 'code' => 'disabled', 'message' => 'Duplicates scanning is disabled — enable it via the section toggle before scanning.' ] );
    }

    $suppliers = class_exists( 'MMI_Pipeline_Admin' )
        ? array_keys( MMI_Pipeline_Admin::get_configured_suppliers() )
        : [ 'xchange', 'skuport' ];

    $collisions = MMI_Pipeline_Field_Resolver::scan_all_primary_key_collisions( $suppliers );

    $groups = [];
    foreach ( $collisions as $supplier => $values ) {
        // Same lookup the real importer uses (find_product_id_by_primary_key()'s
        // unordered LIMIT 1) — whichever product this resolves to is the one
        // that keeps receiving price/stock updates going forward regardless of
        // what a user picks below, so it's surfaced as the recommended
        // canonical rather than left for a blind guess.
        $primary_key_wc = MMI_DB::get_primary_key( $supplier, 'wc', '_sku' );

        foreach ( $values as $value => $product_ids ) {
            $recommended_id = MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key( $primary_key_wc, $value );

            // NOTE: `canonical = 'TRUE'` alone is NOT a meaningful signal here —
            // confirmed live that it's the prevailing default across the whole
            // catalog (13,359 TRUE rows vs. 8,204 total products, i.e. most
            // products carry it with no grouping at all; only 697 products are
            // ever actually suppressed via 'FALSE'). The only real signal that
            // a product was DELIBERATELY chosen as this group's canonical is
            // its own `associated_product_ids` actually naming one of the
            // OTHER members below — checked in the loop after $members is built.
            $members = [];
            foreach ( $product_ids as $product_id ) {
                $post = get_post( $product_id );
                if ( ! $post ) {
                    continue;
                }
                $members[] = [
                    'product_id'        => $product_id,
                    'product_name'      => $post->post_title,
                    'product_url'       => get_edit_post_link( $product_id ),
                    'sku'               => get_post_meta( $product_id, '_sku', true ),
                    'regular_price'     => get_post_meta( $product_id, '_regular_price', true ),
                    'stock_status'      => get_post_meta( $product_id, '_stock_status', true ),
                    'post_status'       => $post->post_status,
                    'is_recommended'    => $product_id === $recommended_id,
                    // Surfaced explicitly — a collision where BOTH sides are
                    // independently Reverb-synced means two live listings for
                    // one physical item, not just a stale catalog row. See
                    // mmi-reverb-integration's mmi_reverb_get_product_id_by_sku(),
                    // which resolves an incoming Reverb order to whichever of
                    // these products holds the matching SKU — not necessarily
                    // this one.
                    'reverb_listing_id' => get_post_meta( $product_id, 'reverb_listing_id', true ),
                    'reverb_sync'       => (bool) get_post_meta( $product_id, 'reverb_sync', true ),
                ];
            }
            if ( count( $members ) < 2 ) {
                continue; // One side got trashed/deleted since the scan query — nothing to show.
            }

            // Resolved once one member's own associated_product_ids actually
            // names every OTHER member of this exact group — the only real
            // evidence someone deliberately linked this pair (not just the
            // ambient default 'TRUE', see the note above). Mirrors
            // mmi_scan_duplicate_candidates()'s own $already_linked check.
            $canonical_id = 0;
            $resolved     = false;
            foreach ( $product_ids as $candidate_id ) {
                // associated_product_ids is stored as either a delimited string
                // or (some legacy rows) an already-serialized array — see the
                // identical duality class-pipeline-catalog-updater.php's own
                // enforce_canonical_stock_rules() already handles. A bare
                // (string) cast on the array case would silently produce the
                // literal string "Array", not an empty/no-op value.
                $assoc = get_post_meta( $candidate_id, 'associated_product_ids', true );
                if ( is_array( $assoc ) ) {
                    $assoc_ids = array_filter( array_map( 'intval', $assoc ) );
                } elseif ( is_string( $assoc ) && trim( $assoc ) !== '' ) {
                    $assoc_ids = array_filter( array_map( 'intval', preg_split( '/[,\|\s]+/', $assoc ) ) );
                } else {
                    $assoc_ids = [];
                }
                if ( empty( $assoc_ids ) ) {
                    continue;
                }
                $others = array_diff( $product_ids, [ $candidate_id ] );
                if ( empty( array_diff( $others, $assoc_ids ) ) ) {
                    $canonical_id = $candidate_id;
                    $resolved     = true;
                    break;
                }
            }

            $groups[] = [
                'supplier'           => $supplier,
                'value'              => $value,
                'members'            => $members,
                'canonical_id'       => $canonical_id,
                'resolved'           => $resolved,
                'recommended_id'     => $recommended_id,
            ];
        }
    }

    wp_send_json_success( [
        'groups' => $groups,
        'total'  => count( $groups ),
    ] );
} );

/**
 * Builds a supplier => [sku => raw feed item] index from the same feed files
 * mmi_scan_duplicate_candidates() reads, for the existing-groups scan's cost
 * enrichment below. Read once per scan (itself 10-minute transient-cached),
 * not per product — the same file-read-cost discipline the Candidates scan
 * already follows.
 *
 * @return array<string, array<string, array>>
 */
function mmi_canonical_candidates_build_feed_index(): array {
    $json_dir = mmi_shared_lib_json_dir();

    $index = [];
    foreach ( mmi_canonical_candidates_feed_configs() as $supplier => $cfg ) {
        $json_file = $json_dir . $cfg['file'];
        if ( ! file_exists( $json_file ) ) {
            continue;
        }
        $data = json_decode( file_get_contents( $json_file ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            continue;
        }
        $items = $data['products'] ?? $data['items'] ?? ( is_array( $data ) ? $data : [] );

        $by_sku = [];
        foreach ( $items as $item ) {
            $sku = (string) ( $item[ $cfg['sku_field'] ] ?? '' );
            if ( $sku !== '' ) {
                $by_sku[ $sku ] = $item;
            }
        }
        $index[ $supplier ] = $by_sku;
    }
    return $index;
}

/**
 * Builds one member row for a DATABASE-sourced group (existing canonical/
 * associated_product_ids links), converging on the same shape a Candidates-
 * scan member has (supplier/sku/vendor/dealer_price/map_price/msrp_price/
 * wc_product_id/is_canonical) plus the extra display fields a DB-sourced
 * member needs and a feed member gets for free from the review UI's existing
 * per-supplier text (product_name/stock_status/visibility) — see
 * changelog.md 1.72.0 for the original design rationale.
 *
 * Supplier is detected by whichever configured supplier's own SKU-tracking
 * postmeta (`_mmi_supplier_sku_{supplier}`, the same convention
 * Stock_Override_Resolver::detect_product_supplier() already uses
 * independently — duplicated here in miniature rather than reaching into
 * that class's private internals, matching this file's own existing
 * precedent for mmi_canonical_candidates_parse_associated_ids()) is
 * populated first. Cost basis prefers a real feed match by that supplier's
 * SKU; falls back to the stored COG postmeta (mmi_get_cog_meta_key(),
 * mmi-hub) when the product is no longer in the live feed or has no
 * detected supplier at all (a "native"/hand-added product).
 *
 * @param  int   $product_id
 * @param  bool  $is_canonical
 * @param  array $feed_index    From mmi_canonical_candidates_build_feed_index().
 * @return array
 */
function mmi_canonical_candidates_build_existing_member( int $product_id, bool $is_canonical, array $feed_index ): array {
    $configs      = mmi_canonical_candidates_feed_configs();
    $supplier_ids = class_exists( 'MMI_Pipeline_Admin' )
        ? array_keys( MMI_Pipeline_Admin::get_configured_suppliers() )
        : array_keys( $configs );

    $supplier      = '';
    $supplier_sku  = '';
    foreach ( $supplier_ids as $sid ) {
        $val = get_post_meta( $product_id, '_mmi_supplier_sku_' . $sid, true );
        if ( $val !== '' ) {
            $supplier     = $sid;
            $supplier_sku = $val;
            break;
        }
    }

    $dealer_price = 0.0;
    $map_price    = 0.0;
    $msrp_price   = 0.0;
    $vendor       = '';

    if ( $supplier !== '' && isset( $feed_index[ $supplier ][ $supplier_sku ] ) ) {
        $item = $feed_index[ $supplier ][ $supplier_sku ];
        $cfg  = $configs[ $supplier ] ?? null;
        if ( $cfg ) {
            $dealer_price = (float) ( $item[ $cfg['dealer_field'] ] ?? 0 );
            $map_price    = (float) ( $item[ $cfg['map_field']    ] ?? 0 );
            $msrp_price   = (float) ( $item[ $cfg['msrp_field']   ] ?? 0 );
            $vendor       = $cfg['vendor_field'] ? (string) ( $item[ $cfg['vendor_field'] ] ?? '' ) : '';
        }
    } elseif ( function_exists( 'mmi_get_cog_meta_key' ) ) {
        $dealer_price = (float) get_post_meta( $product_id, mmi_get_cog_meta_key(), true );
    }

    return [
        'supplier'      => $supplier !== '' ? $supplier : 'native',
        'sku'           => (string) get_post_meta( $product_id, '_sku', true ),
        'vendor'        => $vendor,
        'dealer_price'  => $dealer_price,
        'map_price'     => $map_price,
        'msrp_price'    => $msrp_price,
        'wc_product_id' => $product_id,
        'is_canonical'  => $is_canonical,
        'product_name'  => get_the_title( $product_id ) ?: '(untitled)',
        'stock_status'  => (string) get_post_meta( $product_id, '_stock_status', true ),
        'visibility'    => (string) ( get_post_meta( $product_id, '_visibility', true ) ?: 'visible' ),
        'assets'        => mmi_canonical_candidates_asset_snapshot( $product_id ),
    ];
}

/**
 * Same-brand, same-manufacturer-part-number groups: a third source for the
 * Duplicates queue (source 'mpn'), for brands whose part numbers are known to be
 * one-number-per-product. Most brands' MPNs are NOT (measured 2026-09-30: one
 * PreSonus number on 9 unrelated products, one FinalEffect code on 3 plug-ins,
 * MusicLab v3 and v6 sharing numbers, used and new Mogami cables sharing one), so
 * nothing is matched unless a site registers a brand's format:
 *
 *     add_filter( 'mmi_duplicate_mpn_formats', fn( $f ) => $f + [ 'avid' => '/^9\d{3}-\d{5}-\d{2}$/' ] );
 *
 * A database scan like the legacy-groups one (listings outside every live feed
 * count too). Only groups that aren't already one fully linked group are returned;
 * a group with more than one canonical member is flagged.
 *
 * @return array[] groups in the same shape as mmi_scan_existing_canonical_groups'
 */
function mmi_canonical_candidates_mpn_groups( array $feed_index ): array {
    $formats = (array) apply_filters( 'mmi_duplicate_mpn_formats', [] );
    if ( ! $formats ) {
        return [];
    }
    global $wpdb;
    $brands       = array_map( 'strtolower', array_keys( $formats ) );
    $placeholders = implode( ',', array_fill( 0, count( $brands ), '%s' ) );
    $rows         = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID id, t.name brand, UPPER(TRIM(m.meta_value)) mpn
         FROM {$wpdb->postmeta} m
         JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'product' AND p.post_status = 'publish'
         JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand'
         JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
         WHERE m.meta_key = 'mpn' AND TRIM(m.meta_value) <> '' AND LOWER(t.name) IN ({$placeholders})",
        ...$brands
    ), ARRAY_A );

    $dismissed = array_flip( (array) MMI_DB::get_setting( 'mmi_canonical_candidates_dismissed', [] ) );
    $by        = [];
    foreach ( $rows as $r ) {
        $rx = $formats[ strtolower( $r['brand'] ) ] ?? ( $formats[ $r['brand'] ] ?? '' );
        if ( $rx === '' || ! preg_match( $rx, $r['mpn'] ) ) {
            continue; // not in the brand's trusted format: never grouped on
        }
        $by[ $r['brand'] . '|' . $r['mpn'] ][] = (int) $r['id'];
    }

    $groups = [];
    foreach ( $by as $key => $ids ) {
        $ids = array_values( array_unique( $ids ) );
        if ( count( $ids ) < 2 || isset( $dismissed[ 'mpn-' . $key ] ) ) {
            continue;
        }
        [ $brand, $mpn ] = explode( '|', $key, 2 );
        $members = [];
        foreach ( $ids as $id ) {
            $members[] = mmi_canonical_candidates_build_existing_member( $id, get_post_meta( $id, 'canonical', true ) === 'TRUE', $feed_index );
        }
        $canonicals = array_values( array_filter( $members, static fn( $m ) => ! empty( $m['is_canonical'] ) ) );
        // Already one group: a canonical member whose links cover every other member.
        $linked = false;
        foreach ( $canonicals as $c ) {
            $assoc = mmi_canonical_candidates_parse_associated_ids( get_post_meta( $c['wc_product_id'], 'associated_product_ids', true ) );
            if ( ! array_diff( array_diff( $ids, [ $c['wc_product_id'] ] ), $assoc ) ) {
                $linked = true;
                break;
            }
        }
        if ( $linked && count( $canonicals ) === 1 ) {
            continue; // fully linked: already in the queue as a legacy group
        }
        $recommended            = mmi_canonical_candidates_recommend( $members );
        $recommended_product_id = $recommended['wc_product_id'] ?? null;
        foreach ( $members as &$member ) {
            $member['is_recommended'] = $recommended_product_id !== null && $member['wc_product_id'] === $recommended_product_id;
        }
        unset( $member );
        $prices  = array_column( $members, 'dealer_price' );
        $name_id = $canonicals ? (int) $canonicals[0]['wc_product_id'] : (int) $ids[0];
        $groups[] = [
            'group_key'              => 'mpn-' . $key,
            'source'                 => 'mpn',
            'match'                  => $mpn,
            'multiple_canonicals'    => count( $canonicals ) > 1,
            'brand'                  => $brand,
            'name'                   => get_the_title( $name_id ) ?: '(untitled)',
            'members'                => $members,
            'price_min'              => $prices ? min( $prices ) : 0,
            'price_max'              => $prices ? max( $prices ) : 0,
            'price_spread'           => $prices ? max( $prices ) - min( $prices ) : 0,
            'already_linked'         => false,
            'recommended_product_id' => $recommended_product_id,
            'brand_thumbnail_url'    => mmi_canonical_candidates_brand_thumbnail_url( $brand ),
            'image_url'              => mmi_canonical_candidates_product_thumbnail_url( (int) ( $recommended_product_id ?: $name_id ) ),
        ];
    }
    return $groups;
}

/**
 * Existing canonical/associated_product_ids groups — the real, pre-existing
 * links the legacy JetEngine-adjacent workflow (and earlier sessions' own
 * manual/scripted fixes) already created, most never surfaced anywhere in
 * this admin UI. See changelog.md 1.72.0 for the full background — this is
 * a DATABASE scan (mirrors enforce_canonical_stock_
 * rules()'s own two-query shape in class-pipeline-catalog-updater.php),
 * never a feed scan: it finds every canonical=TRUE product whose
 * associated_product_ids actually names at least one other real product,
 * completely independent of whether either side is still in any live feed.
 * Merged into the same "Duplicates" table client-side (duplicate-products.js)
 * alongside mmi_scan_duplicate_candidates()'s fresh feed-match groups, each
 * tagged by 'source' so the two origins stay visually distinguishable in one
 * unified queue.
 */
add_action( 'wp_ajax_mmi_scan_existing_canonical_groups', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    if ( ! (bool) MMI_DB::get_setting( 'mmi_duplicate_products_enabled', true ) ) {
        wp_send_json_error( [ 'code' => 'disabled', 'message' => 'Duplicates scanning is disabled — enable it via the section toggle before scanning.' ] );
    }

    $force_refresh = ! empty( $_POST['force_refresh'] );
    if ( ! $force_refresh ) {
        $cached = get_transient( 'mmi_canonical_existing_groups_scan_cache' );
        if ( $cached !== false ) {
            wp_send_json_success( $cached );
        }
    }

    @set_time_limit( 120 );

    global $wpdb;

    $true_rows = $wpdb->get_results( "
        SELECT pm.post_id
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = 'canonical' AND pm.meta_value = 'TRUE' AND p.post_type = 'product'
    " );
    $true_ids = array_map( static fn( $r ) => (int) $r->post_id, $true_rows );

    $groups = [];

    if ( ! empty( $true_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $true_ids ), '%d' ) );
        $assoc_rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'associated_product_ids' AND post_id IN ({$placeholders})",
            ...$true_ids
        ) );

        $feed_index = mmi_canonical_candidates_build_feed_index();

        foreach ( $assoc_rows as $row ) {
            $canonical_id = (int) $row->post_id;
            $assoc_ids    = mmi_canonical_candidates_parse_associated_ids( $row->meta_value );
            $assoc_ids    = array_values( array_unique( array_filter(
                $assoc_ids,
                fn( $id ) => $id > 0 && $id !== $canonical_id && get_post_type( $id ) === 'product'
            ) ) );
            if ( empty( $assoc_ids ) ) {
                continue; // canonical=TRUE with no real link — the ambient default, not a group (see this file's own already_linked note above).
            }

            $members = [ mmi_canonical_candidates_build_existing_member( $canonical_id, true, $feed_index ) ];
            foreach ( $assoc_ids as $aid ) {
                $members[] = mmi_canonical_candidates_build_existing_member( $aid, false, $feed_index );
            }

            $brand_terms = wp_get_post_terms( $canonical_id, 'product_brand', [ 'fields' => 'names' ] );
            $brand       = ( ! is_wp_error( $brand_terms ) && ! empty( $brand_terms ) ) ? $brand_terms[0] : '';

            $recommended            = mmi_canonical_candidates_recommend( $members );
            $recommended_product_id = $recommended['wc_product_id'] ?? null;
            foreach ( $members as &$member ) {
                $member['is_recommended'] = $recommended_product_id !== null
                    && $member['wc_product_id'] === $recommended_product_id;
            }
            unset( $member );

            $prices    = array_column( $members, 'dealer_price' );
            $price_min = $prices ? min( $prices ) : 0;
            $price_max = $prices ? max( $prices ) : 0;

            $groups[] = [
                'group_key'              => 'legacy-' . $canonical_id,
                'source'                 => 'legacy',
                'canonical_product_id'   => $canonical_id,
                'brand'                  => $brand,
                'name'                   => get_the_title( $canonical_id ) ?: '(untitled)',
                'members'                => $members,
                'price_min'              => $price_min,
                'price_max'              => $price_max,
                'price_spread'           => $price_max - $price_min,
                'already_linked'         => true,
                'recommended_product_id' => $recommended_product_id,
                'brand_thumbnail_url'    => mmi_canonical_candidates_brand_thumbnail_url( $brand ),
                'image_url'              => mmi_canonical_candidates_product_thumbnail_url( $canonical_id ),
            ];
        }
    }

    // Same-MPN groups ride in this response (a third request would break the ≤2 auto-fired AJAX limit).
    $groups = array_merge( $groups, mmi_canonical_candidates_mpn_groups( $feed_index ?? mmi_canonical_candidates_build_feed_index() ) );

    usort( $groups, fn( $a, $b ) => $b['price_spread'] <=> $a['price_spread'] );

    $response = [
        'groups' => $groups,
        'total'  => count( $groups ),
    ];

    set_transient( 'mmi_canonical_existing_groups_scan_cache', $response, 10 * MINUTE_IN_SECONDS );

    wp_send_json_success( $response );
} );

/**
 * Remove one member from an existing group — resets that member back to a
 * plain, unlinked product (delete_post_meta(), mirroring the cleanup
 * script's own approach documented in AGENTS.md's "What went wrong once, and
 * why it healed") rather than immediately touching its stock/visibility.
 * enforce_canonical_stock_rules() only ever acts on a product it finds in
 * its own canonical_map query — with both meta keys gone, this product is
 * simply left alone (whatever stock/visibility it currently has) until a
 * real import or a future Candidates scan naturally re-evaluates it.
 *
 * If removing this member empties the group entirely, the canonical itself
 * is also reset to blank — a "group" of one canonical with nothing
 * associated is meaningless, not a state worth leaving behind.
 */
add_action( 'wp_ajax_mmi_canonical_remove_group_member', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $canonical_id = (int) ( $_POST['canonical_product_id'] ?? 0 );
    $member_id    = (int) ( $_POST['member_product_id'] ?? 0 );

    if ( ! $canonical_id || get_post_meta( $canonical_id, 'canonical', true ) !== 'TRUE' ) {
        wp_send_json_error( [ 'message' => 'Not a canonical product' ] );
    }
    if ( ! $member_id ) {
        wp_send_json_error( [ 'message' => 'Missing member_product_id' ] );
    }

    $ids = mmi_canonical_candidates_parse_associated_ids( get_post_meta( $canonical_id, 'associated_product_ids', true ) );
    if ( ! in_array( $member_id, $ids, true ) ) {
        wp_send_json_error( [ 'message' => 'That product is not a member of this group' ] );
    }

    $remaining = array_values( array_diff( $ids, [ $member_id ] ) );

    delete_post_meta( $member_id, 'canonical' );
    delete_post_meta( $member_id, 'associated_product_ids' );

    $dissolved = empty( $remaining );
    if ( $dissolved ) {
        delete_post_meta( $canonical_id, 'canonical' );
        delete_post_meta( $canonical_id, 'associated_product_ids' );
    } else {
        update_post_meta( $canonical_id, 'associated_product_ids', implode( ',', $remaining ) );
    }

    delete_transient( 'mmi_canonical_existing_groups_scan_cache' );

    mmi_data_pipeline_audit( 'canonical.remove_member', [
        'object_type' => 'product',
        'object_id'   => $canonical_id,
        'outcome'     => 'success',
        'details'     => [ 'member_product_id' => $member_id ],
    ] );
    wp_send_json_success( [
        'message'              => 'Removed product #' . $member_id . ' from the group — it is now a plain, unlinked product.'
            . ( $dissolved ? ' The group had no members left and was dissolved.' : '' ),
        'dissolved'            => $dissolved,
        'remaining_member_ids' => $remaining,
    ] );
} );

/**
 * Reassign which member is canonical. The old canonical becomes an ordinary
 * associated member of the new one — enforce_canonical_stock_rules() will
 * correctly suppress it (canonical=FALSE, out of stock, shop-only) the next
 * time it runs because the new canonical's own associated_product_ids now
 * names it; nothing needs writing on the old canonical beyond clearing its
 * now-stale group-owner meta.
 */
add_action( 'wp_ajax_mmi_canonical_reassign_canonical', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $old_canonical_id = (int) ( $_POST['canonical_product_id'] ?? 0 );
    $new_canonical_id = (int) ( $_POST['new_canonical_id'] ?? 0 );

    if ( ! $old_canonical_id || get_post_meta( $old_canonical_id, 'canonical', true ) !== 'TRUE' ) {
        wp_send_json_error( [ 'message' => 'Not a canonical product' ] );
    }
    if ( ! $new_canonical_id || get_post_type( $new_canonical_id ) !== 'product' ) {
        wp_send_json_error( [ 'message' => 'Invalid replacement product' ] );
    }

    $ids = mmi_canonical_candidates_parse_associated_ids( get_post_meta( $old_canonical_id, 'associated_product_ids', true ) );
    if ( ! in_array( $new_canonical_id, $ids, true ) ) {
        wp_send_json_error( [ 'message' => 'That product is not a member of this group' ] );
    }

    $new_associated = array_values( array_unique( array_merge(
        array_diff( $ids, [ $new_canonical_id ] ),
        [ $old_canonical_id ]
    ) ) );

    update_post_meta( $new_canonical_id, 'canonical', 'TRUE' );
    update_post_meta( $new_canonical_id, 'associated_product_ids', implode( ',', $new_associated ) );

    delete_post_meta( $old_canonical_id, 'canonical' );
    delete_post_meta( $old_canonical_id, 'associated_product_ids' );

    delete_transient( 'mmi_canonical_existing_groups_scan_cache' );

    mmi_data_pipeline_audit( 'canonical.reassign', [
        'object_type' => 'product',
        'object_id'   => $new_canonical_id,
        'outcome'     => 'success',
        'details'     => [ 'old_canonical_id' => $old_canonical_id ],
    ] );
    wp_send_json_success( [
        'message'                => 'Product #' . $new_canonical_id . ' is now canonical — the other member(s) will be suppressed on the next Catalog Maintenance run.',
        'canonical_product_id'   => $new_canonical_id,
        'associated_product_ids' => $new_associated,
    ] );
} );

/**
 * Unlink an entire group — every member (canonical included) resets to a
 * plain, unlinked product, matching the same "reset to blank" convention
 * as the single-member removal above, extended to the whole group at once.
 */
add_action( 'wp_ajax_mmi_canonical_unlink_group', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $canonical_id = (int) ( $_POST['canonical_product_id'] ?? 0 );
    if ( ! $canonical_id || get_post_meta( $canonical_id, 'canonical', true ) !== 'TRUE' ) {
        wp_send_json_error( [ 'message' => 'Not a canonical product' ] );
    }

    $ids = mmi_canonical_candidates_parse_associated_ids( get_post_meta( $canonical_id, 'associated_product_ids', true ) );

    foreach ( $ids as $member_id ) {
        delete_post_meta( $member_id, 'canonical' );
        delete_post_meta( $member_id, 'associated_product_ids' );
    }
    delete_post_meta( $canonical_id, 'canonical' );
    delete_post_meta( $canonical_id, 'associated_product_ids' );

    delete_transient( 'mmi_canonical_existing_groups_scan_cache' );

    mmi_data_pipeline_audit( 'canonical.unlink', [
        'object_type' => 'product',
        'object_id'   => $canonical_id,
        'outcome'     => 'success',
    ] );
    wp_send_json_success( [
        'message'    => 'Group unlinked — all ' . ( count( $ids ) + 1 ) . ' product(s) reset to plain, unlinked products.',
        'member_ids' => array_merge( [ $canonical_id ], $ids ),
    ] );
} );

/**
 * Master enable/disable switch for the whole Duplicate Products section —
 * gates the one real entry point into this feature's logic, the feed scan
 * above (mmi_scan_duplicate_candidates). Dismiss/Approve are left ungated:
 * they only act on groups a scan already surfaced (nothing to run without
 * one), and blocking them mid-review over a toggle flipped elsewhere would
 * strand an in-progress decision with no benefit.
 */
add_action( 'wp_ajax_mmi_toggle_duplicate_products', function () {
    check_ajax_referer( CANONICAL_CANDIDATES_NONCE, 'nonce' );
    if ( ! mmi_data_pipeline_user_can() ) {
        wp_send_json_error( [ 'message' => 'Insufficient permissions' ] );
    }

    $enabled = ! empty( $_POST['enabled'] );
    MMI_DB::set_setting( 'mmi_duplicate_products_enabled', $enabled );

    MMI_Logger::info(
        'Duplicate Products master switch set to ' . ( $enabled ? 'enabled' : 'disabled' ),
        [], 'sync', 'CanonicalCandidatesController'
    );

    mmi_data_pipeline_audit( 'settings.update', [
        'outcome' => 'success',
        'details' => [ 'keys' => [ 'mmi_duplicate_products_enabled' ], 'enabled' => $enabled ],
    ] );
    wp_send_json_success( [ 'enabled' => $enabled ] );
} );
