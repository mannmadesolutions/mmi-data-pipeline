/**
 * Duplicate Products panel — detects products from supplier feeds that appear
 * to be the same physical item listed more than once (e.g. via different
 * vendors/distributors at different dealer costs) and lets the user link them
 * via the existing `canonical` / `associated_product_ids` postmeta that
 * CatalogUpdater.php already consumes downstream.
 */
( function ( $ ) {
    'use strict';

    const AJAX_URL = window.mmiDupes?.ajaxUrl || ajaxurl;
    const NONCE    = window.mmiDupes?.nonce   || '';

    const SELECTORS = {
        APP:            '#mmi-dupes-app',
        REFRESH_BTN:    '#mmi-dupes-refresh-btn',
        STATS:          '#mmi-dupes-stats',
        TOTAL:          '#mmi-dupes-total',
        NEW_COUNT:      '#mmi-dupes-new-count',
        LINKED_COUNT:   '#mmi-dupes-linked-count',
        NO_SALE_STAT:   '#mmi-dupes-stat-nosale',
        NO_SALE_COUNT:  '#mmi-dupes-nosale-count',
        NOT_SOLD_STAT:  '#mmi-dupes-stat-notsold',
        NOT_SOLD_COUNT: '#mmi-dupes-notsold-count',
        STAT_FILTER_BTN:'.mmi-dupes-stat',
        BRAND_FILTER_ROW:      '#mmi-dupes-brand-filter-row',
        BRAND_FILTER_INLINE:   '#mmi-dupes-brand-filters',
        BRAND_FILTER_CHECKBOX: '.mmi-dupes-brand-filter-checkbox',
        BRAND_FILTER_SELECT:   '.mmi-dupes-brand-filter-select',
        NOTICE:         '#mmi-dupes-notice',
        TABLE:          '#mmi-dupes-table',
        TABLE_WRAP:     '#mmi-dupes-table-wrap',
        TBODY:          '#mmi-dupes-tbody',
        EMPTY:          '#mmi-dupes-empty',
        LOADING:        '#mmi-dupes-loading',
        RESIZE_HANDLE:  '.mmi-dupes-th-resize',
        MASTER_TOGGLE:  '#mmi-dupes-master-toggle',
        DISABLED_NOTICE:'#mmi-dupes-disabled-notice',
        // Confirmed Collisions — a second detection mode, see this file's own
        // scanCollisions()/renderCollisionGroups() below.
        DETECTION_SELECT:       '#mmi-dupes-detection-mode',
        DESC_CANDIDATES:        '#mmi-dupes-description-candidates',
        DESC_COLLISIONS:        '#mmi-dupes-description-collisions',
        COLLISION_TABLE:        '#mmi-dupes-collision-table',
        COLLISION_TBODY:        '#mmi-dupes-collision-tbody',
        COLLISION_EMPTY:        '#mmi-dupes-collision-empty',
    };

    /** localStorage key for persisted column widths — see initColumnResize(). */
    const COLUMN_WIDTH_STORAGE_KEY = 'mmi_dupes_column_widths';

    /**
     * The "product data winner" fields a review panel lets an admin pick per
     * field, independent of which member is chosen canonical — mirrors
     * CanonicalCandidatesController.php's own mmi_canonical_candidates_asset_
     * field_defs() exactly (key + column order). Add a field to both places
     * to extend this; nothing else needs to change.
     */
    const ASSET_FIELDS = [
        { key: 'image',   label: 'Image' },
        { key: 'gallery', label: 'Gallery' },
        { key: 'content', label: 'Description' },
        { key: 'title',   label: 'Title' },
        { key: 'excerpt', label: 'Short Desc' },
    ];

    /** Whether a resolved member has real data for one asset field. */
    function memberHasAsset( member, fieldKey ) {
        const a = member.assets;
        if ( ! a ) { return false; }
        switch ( fieldKey ) {
            case 'image':   return !! a.has_image;
            case 'gallery': return ( a.gallery_count || 0 ) > 0;
            case 'content': return !! a.has_content;
            case 'title':   return !! ( a.title && a.title.trim() );
            case 'excerpt': return !! a.has_excerpt;
            default:        return false;
        }
    }

    /**
     * What makes two listings' values for a field the same thing (image and
     * gallery attachment IDs, text fingerprints from the server), so a card
     * can say "Same as #…". '' when unknown.
     */
    function assetValueKey( member, fieldKey ) {
        const a = member.assets || {};
        switch ( fieldKey ) {
            case 'image':   return a.image_id ? String( a.image_id ) : '';
            case 'gallery': return a.gallery_key || '';
            case 'content': return a.content_hash || '';
            case 'excerpt': return a.excerpt_hash || '';
            case 'title':   return ( a.title || '' ).trim();
            default:        return '';
        }
    }

    /** The small fact beside a card's ID: length or count, whichever helps judge the value. */
    function assetMeta( member, fieldKey ) {
        const a = member.assets || {};
        const plural = ( n, word ) => `${ n } ${ word }${ n === 1 ? '' : 's' }`;
        switch ( fieldKey ) {
            case 'gallery': return plural( a.gallery_count || 0, 'picture' );
            case 'content': return a.content_words ? plural( a.content_words, 'word' ) : '';
            case 'excerpt': return a.excerpt_words ? plural( a.excerpt_words, 'word' ) : '';
            case 'title':   return a.title ? plural( a.title.trim().length, 'character' ) : '';
            default:        return '';
        }
    }

    /** A card's body: the value itself, readable in full (text wraps, pictures shown). */
    function assetPreview( member, fieldKey ) {
        const a   = member.assets || {};
        const img = ( url, cls ) => $( '<img alt="" loading="lazy">' ).addClass( cls ).attr( 'src', url );
        switch ( fieldKey ) {
            case 'image':
                return a.image_thumb_url ? img( a.image_thumb_url, 'mmi-dupes-pick-image' ) : null;
            case 'gallery': {
                const thumbs = a.gallery_thumbs || [];
                if ( ! thumbs.length ) { return null; }
                const $strip = $( '<div class="mmi-dupes-pick-gallery">' ).append( thumbs.map( ( u ) => img( u, 'mmi-dupes-pick-gallery-thumb' ) ) );
                const more = ( a.gallery_count || 0 ) - thumbs.length;
                if ( more > 0 ) { $strip.append( $( '<span class="mmi-dupes-pick-more">' ).text( `+${ more }` ) ); }
                return $strip;
            }
            case 'content': return $( '<p class="mmi-dupes-pick-text">' ).text( a.content_preview || '' );
            case 'excerpt': return $( '<p class="mmi-dupes-pick-text">' ).text( a.excerpt_preview || '' );
            case 'title':   return $( '<p class="mmi-dupes-pick-text mmi-dupes-pick-text--title">' ).text( a.title || '' );
            default:        return null;
        }
    }

    /**
     * Which asset-field columns are worth showing for this group at all
     * (at least one resolved member has real data for it — avoids a column
     * of nothing but "—" when, say, not a single candidate has a gallery).
     */
    function visibleAssetFields( members ) {
        return ASSET_FIELDS.filter( f => members.some( m => m.wc_product_id && memberHasAsset( m, f.key ) ) );
    }

    /**
     * Per-field default winner: whichever member is the (soon-to-be)
     * canonical if it has the field, else the recommended (lowest-cost)
     * member if it has the field, else the first resolved member that does.
     * Never assumes a field has any winner at all — 0 means "no default,"
     * left to the admin (or simply not applied if never touched).
     */
    function computeFieldWinnerDefaults( members, preferredCanonicalId ) {
        const resolved = members.filter( m => m.wc_product_id && m.assets );
        const defaults = {};
        ASSET_FIELDS.forEach( function ( f ) {
            const withField = resolved.filter( m => memberHasAsset( m, f.key ) );
            if ( ! withField.length ) { defaults[ f.key ] = 0; return; }
            const preferred   = withField.find( m => m.wc_product_id === preferredCanonicalId );
            const recommended = withField.find( m => m.is_recommended );
            defaults[ f.key ] = ( preferred || recommended || withField[ 0 ] ).wc_product_id;
        } );
        return defaults;
    }

    /**
     * "Product data" pickers: per field, one card per listing that has a value
     * (a listing with nothing to offer isn't an option), showing the value
     * itself so the cards can be compared side by side. Each card holds a real
     * .mmi-dupes-winner-radio, so collectFieldWinners() reads them as before.
     */
    function buildFieldPickers( assetFields, members, defaults, radioGroupPrefix, hint ) {
        const $section = $( '<section class="mmi-dupes-fields">' ).append(
            $( '<div class="mmi-dupes-fields-head">' ).append(
                $( '<span class="mmi-dupes-fields-title">' ).text( 'Product data' ),
                $( '<span class="mmi-dupes-hint">' ).text( hint )
            )
        );
        assetFields.forEach( function ( f ) {
            const options = members.filter( m => m.wc_product_id && memberHasAsset( m, f.key ) );
            const keys    = options.map( m => assetValueKey( m, f.key ) );
            const allSame = options.length > 1 && keys[ 0 ] !== '' && keys.every( k => k === keys[ 0 ] );

            const $grid = $( '<div class="mmi-dupes-pick-grid" role="radiogroup">' ).attr( 'aria-label', f.label );
            options.forEach( function ( m, i ) {
                const firstSame = keys[ i ] !== '' ? options[ keys.indexOf( keys[ i ] ) ] : null;
                const $head = $( '<span class="mmi-dupes-pick-head">' ).append(
                    $( '<input type="radio" class="mmi-dupes-winner-radio">' )
                        .attr( { name: radioGroupPrefix + f.key, 'data-field': f.key, 'data-product-id': m.wc_product_id } )
                        .prop( 'checked', m.wc_product_id === defaults[ f.key ] ),
                    $( '<span class="mmi-dupes-pick-id">' ).text( '#' + m.wc_product_id ),
                    $( '<span class="mmi-dupes-pick-meta">' ).text( assetMeta( m, f.key ) )
                );
                if ( ! allSame && firstSame && firstSame !== m ) {
                    $head.append( $( '<span class="mmi-dupes-pick-same">' ).text( `Same as #${ firstSame.wc_product_id }` ) );
                }
                const $preview = assetPreview( m, f.key );
                $grid.append( $( '<label class="mmi-dupes-pick">' )
                    .toggleClass( 'is-picked', m.wc_product_id === defaults[ f.key ] )
                    .append( $head, $preview || [] ) );
            } );

            $section.append(
                $( '<div class="mmi-dupes-field">' ).append(
                    $( '<div class="mmi-dupes-field-label">' ).append(
                        $( '<span>' ).text( f.label ),
                        allSame ? $( '<span class="mmi-dupes-field-note">' ).text( 'Same on every listing' ) : []
                    ),
                    $grid
                )
            );
        } );
        return $section;
    }

    /** A listing's title: the raw post_title (assets.title), else the server's product_name. */
    function memberTitle( member ) {
        return ( member.assets && member.assets.title ) || member.product_name || '';
    }

    /**
     * The group's lowest non-zero cost listing — the server's recommendation
     * (mmi_canonical_candidates_recommend()), counted only when it has a real
     * cost. 0 when no listing has one.
     */
    function lowestCostId( members ) {
        const m = members.find( ( x ) => x.is_recommended && x.wc_product_id && Number( x.dealer_price ) > 0 );
        return m ? m.wc_product_id : 0;
    }

    /* ── Price comparison (review panel) ──────────────────────────────────────
       Each member carries list_price (the feed's MAP, else the store price;
       list_price_source says which) from mmi_canonical_candidates_price_
       summary(). Listings are compared on it, not on cost: many have no cost,
       and an identical selling price is the stronger sign of one item. */

    const VISIBILITY_LABELS = { catalog: 'shop only', search: 'search only', hidden: 'hidden' };
    const SUPPLIER_LABELS   = { xchange: 'XChange', skuport: 'SkuPort', plugivery: 'Plugivery' };

    /* ── Availability ──────────────────────────────────────────────────────────
       Whether a listing can be bought, and whether its supplier still sells it.
       member.in_feed: true = the supplier's feed lists it, false = the supplier
       dropped it, null = no feed to check (hand-added, or a feed not read here),
       which never counts as dropped. member.held_by names the stock override or
       Custom Rule that keeps a listing out of stock although its feed lists it
       (the owner's "Out of Stock Rule" holds Apogee Digital and Prism Sound). */

    /** A listing's state: key into MEMBER_AVAILABILITY. */
    function memberAvailability( member ) {
        if ( ! member.wc_product_id ) { return 'unimported'; }
        if ( member.stock_status === 'instock' )     { return 'instock'; }
        if ( member.stock_status === 'onbackorder' ) { return 'backorder'; }
        if ( member.in_feed === false ) { return 'gone'; }
        if ( member.held_by )           { return 'ruled'; }
        return member.in_feed === null ? 'out' : 'held';
    }

    const MEMBER_AVAILABILITY = {
        instock:    { badge: 'success', label: () => 'In stock',     title: () => 'Customers can buy this listing' },
        backorder:  { badge: 'success', label: () => 'On backorder', title: () => 'Customers can order this listing' },
        held:       { badge: 'warning', label: ( s ) => `Out of stock · ${ s } sells it`, title: ( s ) => `Out of stock here, but the ${ s } feed still lists it, so it can go back on sale` },
        out:        { badge: 'error',   label: () => 'Out of stock', title: () => 'Out of stock, and not from a supplier feed this screen reads' },
        gone:       { badge: '',        label: () => 'No longer sold', title: ( s ) => `Out of stock, and the ${ s } feed no longer lists it: the supplier doesn’t sell it any more` },
        ruled:      { badge: '',        label: () => 'Held out by rule', title: ( s, m ) => `Out of stock: ${ m.held_by } keeps it off sale, although the ${ s } feed lists it` },
        unimported: { badge: 'info',    label: () => 'Not imported', title: ( s ) => `In the ${ s } feed, not imported into the store yet` },
    };

    /** The shared badge for one listing's availability. */
    function buildAvailabilityBadge( member ) {
        const def      = MEMBER_AVAILABILITY[ memberAvailability( member ) ];
        const supplier = SUPPLIER_LABELS[ member.supplier ] || member.supplier || 'supplier';
        return $( '<span class="mmi-badge mmi-dupes-avail">' ).addClass( def.badge )
            .attr( 'title', def.title( supplier, member ) )
            .text( def.label( supplier ) );
    }

    /**
     * A group's state, key into GROUP_AVAILABILITY: something on sale; nothing on
     * sale but a supplier still sells a listing; nothing a supplier sells at all
     * (the canonical pick changes nothing customers can buy); otherwise out.
     */
    function groupAvailability( group ) {
        const keys = group.members.map( memberAvailability );
        if ( keys.some( ( k ) => k === 'instock' || k === 'backorder' ) ) { return 'onsale'; }
        if ( keys.some( ( k ) => k === 'held' || k === 'unimported' ) )   { return 'sellable'; }
        return keys.every( ( k ) => k === 'gone' || k === 'ruled' ) ? 'notsold' : 'out';
    }

    const GROUP_AVAILABILITY = {
        onsale:   { rank: 0, badge: 'success', label: 'In stock',         title: 'At least one listing can be bought' },
        sellable: { rank: 1, badge: 'warning', label: 'Supplier sells it', title: 'Nothing is in stock, but a supplier feed still lists one of these listings' },
        out:      { rank: 2, badge: 'error',   label: 'Out of stock',     title: 'Nothing is in stock' },
        notsold:  { rank: 3, badge: '',        label: 'Not sold',         title: 'No listing can be sold: each is gone from its supplier’s feed or held out by a stock rule, so which one is canonical changes nothing customers can buy' },
    };

    /**
     * The price listings are checked against: the most common list price,
     * ties going to the preselected canonical's price. 0 when none has one.
     */
    function referencePrice( members, canonicalId ) {
        const counts = new Map();
        members.forEach( ( m ) => {
            const p = Number( m.list_price ) || 0;
            if ( p > 0 ) { counts.set( p.toFixed( 2 ), ( counts.get( p.toFixed( 2 ) ) || 0 ) + 1 ); }
        } );
        const canon    = members.find( ( m ) => m.wc_product_id && m.wc_product_id === canonicalId );
        const canonKey = canon && Number( canon.list_price ) > 0 ? Number( canon.list_price ).toFixed( 2 ) : '';
        let best = '';
        let bestN = 0;
        counts.forEach( ( n, key ) => {
            if ( n > bestN || ( n === bestN && key === canonKey ) ) { best = key; bestN = n; }
        } );
        return Number( best ) || 0;
    }

    /** 'match' | 'off' | 'none' — how one listing's price compares with the reference. */
    function priceState( member, refPrice ) {
        const p = Number( member.list_price ) || 0;
        if ( ! p ) { return 'none'; }
        return p.toFixed( 2 ) === refPrice.toFixed( 2 ) ? 'match' : 'off';
    }

    /** Why these listings were grouped, for the verdict card's subline. */
    function matchBasis( group ) {
        if ( group.source === 'mpn' ) { return `Same ${ group.brand } part number, ${ group.match }`; }
        if ( group.source === 'legacy' ) { return `Already linked to canonical #${ group.canonical_product_id }`; }
        return 'Same brand and product name in the supplier feeds';
    }

    /**
     * Dark summary card atop the panel (the Marketplace Earnings "best pick"
     * card): what the prices say about this group, at a glance.
     */
    function buildPriceVerdict( group, refPrice ) {
        const n     = group.members.length;
        const count = Number( group.price_count ) || 0;
        const off   = group.members.filter( ( m ) => priceState( m, refPrice ) === 'off' ).length;
        let headline, figure, note;
        if ( ! count ) {
            headline = 'No prices to compare';
            figure   = '—';
            note     = 'No listing has a MAP or store price';
        } else if ( group.price_match ) {
            headline = `Same price on all ${ n } listings`;
            figure   = formatPrice( group.price_min );
            note     = 'Matching prices back up the match';
        } else if ( group.price_spread > 0 ) {
            headline = `Prices differ by ${ formatPrice( group.price_spread ) }`;
            figure   = `${ formatPrice( group.price_min ) } – ${ formatPrice( group.price_max ) }`;
            note     = `${ off } of ${ count } priced listing${ count === 1 ? '' : 's' } differ from ${ formatPrice( refPrice ) }`;
        } else {
            headline = `Same price on the ${ count } priced listing${ count === 1 ? '' : 's' }`;
            figure   = formatPrice( group.price_min );
            note     = `${ n - count } of ${ n } listings have no price`;
        }
        const tone = ! count ? 'none' : ( group.price_spread > 0 ? 'off' : 'match' );
        return $( '<div class="mmi-dupes-verdict">' ).addClass( 'is-' + tone ).append(
            $( '<div class="mmi-dupes-verdict-text">' ).append(
                $( '<span class="mmi-dupes-verdict-label">' ).text( 'Price check' ),
                $( '<span class="mmi-dupes-verdict-headline">' ).text( headline ),
                $( '<span class="mmi-dupes-verdict-note">' ).text( matchBasis( group ) )
            ),
            $( '<div class="mmi-dupes-verdict-figure">' ).append(
                $( '<span class="mmi-dupes-verdict-amount">' ).text( figure ),
                $( '<span class="mmi-dupes-verdict-note">' ).text( note )
            )
        );
    }

    /** Legend for the listing bars; the "different" key only when one differs. */
    function buildPriceLegend( group, refPrice ) {
        if ( ! ( Number( group.price_count ) || 0 ) ) { return null; }
        const states = new Set( group.members.map( ( m ) => priceState( m, refPrice ) ) );
        const $legend = $( '<div class="mmi-dupes-legend">' ).append(
            $( '<span class="mmi-dupes-key">' ).text( `Price ${ formatPrice( refPrice ) }` )
        );
        if ( states.has( 'off' ) ) { $legend.append( $( '<span class="mmi-dupes-key mmi-dupes-key--off">' ).text( 'Different price' ) ); }
        if ( states.has( 'none' ) ) { $legend.append( $( '<span class="mmi-dupes-key mmi-dupes-key--none">' ).text( 'No price' ) ); }
        $legend.append( $( '<span class="mmi-dupes-legend-note">' ).text( 'MAP from the supplier feed, else the store price' ) );
        return $legend;
    }

    /**
     * One listing row: thumbnail, title + facts, a bar sized against the
     * group's highest price, the price, and the row's controls ($controls).
     */
    function buildListingRow( member, ctx, $controls ) {
        const resolved = !! member.wc_product_id;
        const state    = priceState( member, ctx.refPrice );
        const price    = Number( member.list_price ) || 0;
        const thumb    = member.assets && member.assets.image_thumb_url;
        const title    = memberTitle( member );

        const $title = resolved
            ? $( '<a class="mmi-dupes-listing-title" target="_blank">' )
                .attr( 'href', `/wp-admin/post.php?post=${ member.wc_product_id }&action=edit` )
                .text( title || `#${ member.wc_product_id }` )
            : $( '<span class="mmi-dupes-listing-title">' ).text( title || 'Not imported yet' );

        const facts = [ member.supplier, member.vendor, member.sku ? `SKU ${ member.sku }` : '' ];
        if ( resolved && member.visibility && member.visibility !== 'visible' ) {
            facts.push( VISIBILITY_LABELS[ member.visibility ] || member.visibility );
        }

        const cost     = Number( member.dealer_price ) || 0;
        const isLowest = !! ctx.lowestId && member.wc_product_id === ctx.lowestId;
        const subParts = [ member.list_price_source === 'map' ? 'MAP' : ( member.list_price_source === 'store' ? 'Store price' : 'No price' ) ];
        const store    = Number( member.store_price ) || 0;
        if ( member.list_price_source === 'map' && store > 0 && store.toFixed( 2 ) !== price.toFixed( 2 ) ) {
            subParts.push( `store ${ formatPrice( store ) }` );
        }
        const $sub = $( '<span class="mmi-dupes-sub">' ).text( subParts.join( ' · ' ) );
        if ( cost > 0 ) {
            $sub.append( ' · ', $( '<span class="mmi-dupes-cost">' )
                .toggleClass( 'is-lowest', isLowest )
                .attr( 'title', isLowest ? 'Lowest cost in this group' : null )
                .text( `${ isLowest ? 'lowest cost' : 'cost' } ${ formatPrice( cost ) }` ) );
        }

        const width = price > 0 && ctx.maxPrice > 0 ? Math.max( 3, Math.round( price / ctx.maxPrice * 100 ) ) : 0;

        const $li = $( '<li class="mmi-dupes-listing">' )
            .addClass( 'is-price-' + state )
            .addClass( 'is-avail-' + memberAvailability( member ) )
            .toggleClass( 'is-unresolved', ! resolved )
            .toggleClass( 'is-canonical', !! resolved && member.wc_product_id === ctx.canonicalId );
        return $li.append(
            $( '<div class="mmi-dupes-listing-thumb">' ).append(
                thumb
                    ? $( '<img alt="" loading="lazy">' ).attr( 'src', thumb )
                    : $( '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>' )
            ),
            $( '<div class="mmi-dupes-listing-name">' ).append(
                $( '<span class="mmi-dupes-listing-head">' ).append(
                    $title,
                    resolved ? $( '<span class="mmi-dupes-listing-id">' ).text( '#' + member.wc_product_id ) : null,
                    buildAvailabilityBadge( member )
                ),
                $( '<span class="mmi-dupes-listing-meta">' ).text( facts.filter( Boolean ).join( ' · ' ) )
            ),
            $( '<div class="mmi-dupes-bar" aria-hidden="true">' ).append(
                $( '<span class="mmi-dupes-bar-fill">' ).css( '--mmi-dupes-bar-w', width + '%' )
            ),
            $( '<div class="mmi-dupes-figure">' ).append(
                $( '<span class="mmi-dupes-amount">' ).text( price > 0 ? formatPrice( price ) : '—' ),
                $sub
            ),
            $( '<div class="mmi-dupes-listing-controls">' ).append( $controls )
        );
    }

    /** Shared head of both review panels: verdict, hints, legend; returns the listing context. */
    function startReviewBox( $box, group, canonicalId, hints ) {
        const refPrice = referencePrice( group.members, canonicalId );
        $box.append( buildPriceVerdict( group, refPrice ) );
        hints.filter( Boolean ).forEach( ( h ) => $box.append( $( '<p class="mmi-dupes-review-hint">' ).text( h ) ) );
        const $legend = buildPriceLegend( group, refPrice );
        if ( $legend ) { $box.append( $legend ); }
        return {
            refPrice,
            canonicalId,
            lowestId: lowestCostId( group.members ),
            maxPrice: Math.max( 0, ...group.members.map( ( m ) => Number( m.list_price ) || 0 ) ),
        };
    }

    /** Reads every checked .mmi-dupes-winner-radio in a review row into {field: productId}. */
    function collectFieldWinners( $reviewRow ) {
        const winners = {};
        $reviewRow.find( '.mmi-dupes-winner-radio:checked' ).each( function () {
            const $r = $( this );
            winners[ $r.data( 'field' ) ] = parseInt( $r.attr( 'data-product-id' ), 10 ) || 0;
        } );
        return winners;
    }

    /**
     * Two independent sources feed one unified queue (CANONICAL_PRODUCTS_QUEUE_
     * HANDOFF.md): candidateGroups from the live feed-match scanner
     * (mmi_scan_duplicate_candidates, source:'candidate'), existingGroups from
     * a direct database scan of real canonical/associated_product_ids links
     * (mmi_scan_existing_canonical_groups, source:'legacy'). allGroups is
     * always the merge of the two — sort/filter/render only ever read from it.
     */
    let candidateGroups = [];
    let existingGroups = [];
    let allGroups = [];

    /** Outstanding scan requests — showLoading() only clears once both land. */
    let pendingScans = 0;

    /** Current sort state. */
    let sortState = { col: 'name', dir: 'asc' };

    /** Current status filter — driven by the stats bar. */
    let statusFilter = 'all';

    /** Selected brands (lowercased) — driven by the brand filter row; empty means no restriction. */
    let brandFilter = {};
    // A link's ?dupes_brand, held until a load includes that brand: the two scans land separately.
    let urlBrand = '';

    let hasLoadedOnce = false;

    /** Confirmed Collisions detection mode — see the SELECTORS above. */
    let detectionMode = 'candidates';
    let allCollisionGroups = [];
    let hasLoadedCollisionsOnce = false;

    /**
     * Set for the duration of a column-resize drag and briefly after mouseup,
     * so bindSortHeaders()'s click handler can tell a resize drag apart from
     * an actual header click. A native 'click' is a SEPARATE event from
     * mousedown/mouseup — stopping mousedown's propagation in
     * initColumnResize() below does not stop a click from also firing and
     * bubbling into the sortable <th>'s delegated click handler, and after a
     * real drag the click's target is almost never the thin resize handle
     * itself (it's wherever the pointer ended up, often the header cell
     * body) — so a target-based guard on the resize handle alone isn't
     * enough either. See the resize-handle's own 'click' handler in
     * initColumnResize() for the other half of this fix (the no-drag case,
     * where the click DOES land back on the handle).
     */
    let resizeJustEnded = false;

    /**
     * Guards the (expensive — a live supplier-feed scan) first load so it
     * only ever runs once per page view. Was briefly unguarded-but-moot
     * while Duplicate Products was promoted to its own full tab (content
     * visible immediately, no toggle); folded back into the Import tab as a
     * collapsible section on 2026-09-02 (see section-duplicate-products.php)
     * made this guard load-bearing again — see bindSectionExpand() below,
     * mirroring taxonomy-mapping.js's identical ensureLoaded()-on-first-
     * expand pattern.
     */
    window.MMIDupes = {
        ensureLoaded: function () {
            if ( ! isMasterEnabled() || hasLoadedOnce ) { return; }
            hasLoadedOnce = true;
            // Two independent, parallel requests (≤2 simultaneous — within the
            // Server Load section's auto-fire limit) feeding one merged queue;
            // each renders whatever it has the moment it lands rather than
            // waiting on the other, via mergeAndRender()'s own idempotent merge.
            scanCandidates( false );
            scanExistingGroups( false );
        },
        /**
         * Deep-link entry point for Review & Compare's per-row "⚠ N products
         * share this key" badge (import-preview.js) — expands this section if
         * collapsed, switches to Confirmed Collisions detection, scans if
         * needed, and highlights the one matching row. Same-page call, no
         * reload — both live on the Import tab.
         */
        openForCollision: function ( supplier, value ) {
            const $section = $( '#mmi-duplicate-products-section' );
            $section.removeClass( 'collapsed' );
            if ( ! isMasterEnabled() ) {
                return;
            }
            setDetectionMode( 'collisions' );
            $section[ 0 ]?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
            ensureCollisionsLoaded( function () {
                highlightCollisionRow( supplier, value );
            } );
        }
    };

    function setDetectionMode( mode ) {
        detectionMode = mode;
        $( SELECTORS.DETECTION_SELECT ).val( mode );
        const isCollisions = mode === 'collisions';

        $( SELECTORS.DESC_CANDIDATES ).toggleClass( 'mmi-hidden', isCollisions );
        $( SELECTORS.DESC_COLLISIONS ).toggleClass( 'mmi-hidden', ! isCollisions );

        // Candidate-mode chrome (stats bar + its own table) vs. the simpler
        // collision table — never both visible at once.
        $( SELECTORS.STATS ).toggleClass( 'mmi-hidden', isCollisions || allGroups.length === 0 );
        $( SELECTORS.TABLE ).toggleClass( 'mmi-hidden', isCollisions || allGroups.length === 0 );
        $( SELECTORS.BRAND_FILTER_ROW ).toggleClass( 'mmi-hidden', isCollisions || allGroups.length === 0 || $( SELECTORS.BRAND_FILTER_INLINE ).is( ':empty' ) );
        $( SELECTORS.EMPTY ).addClass( 'mmi-hidden' );
        $( SELECTORS.COLLISION_TABLE ).toggleClass( 'mmi-hidden', ! isCollisions || allCollisionGroups.length === 0 );
        $( SELECTORS.COLLISION_EMPTY ).addClass( 'mmi-hidden' );

        if ( isCollisions ) {
            ensureCollisionsLoaded();
        } else if ( isMasterEnabled() ) {
            window.MMIDupes.ensureLoaded();
        }
    }

    function bindDetectionModeSelect() {
        $( document ).on( 'change', SELECTORS.DETECTION_SELECT, function () {
            setDetectionMode( $( this ).val() );
        } );
    }

    /** Source of truth is the master toggle's own current checked state. */
    function isMasterEnabled() {
        return $( SELECTORS.MASTER_TOGGLE ).is( ':checked' );
    }

    /**
     * Keeps the app/notice visibility in sync with the master toggle without
     * a page reload — mirrors the old Catalog Maintenance panel's
     * identical applyMasterEnabledState(). The server (mmi_scan_duplicate_
     * candidates' own gate in CanonicalCandidatesController.php) stays the
     * real authority; this is the visual side.
     */
    function applyMasterEnabledState( enabled ) {
        $( SELECTORS.APP ).toggleClass( 'mmi-hidden', ! enabled );
        $( SELECTORS.DISABLED_NOTICE ).toggleClass( 'mmi-hidden', enabled );
        if ( enabled ) {
            window.MMIDupes.ensureLoaded();
        }
    }

    function saveMasterToggle( enabled ) {
        const $toggle = $( SELECTORS.MASTER_TOGGLE );
        $.post( AJAX_URL, {
            action:  'mmi_toggle_duplicate_products',
            nonce:   NONCE,
            enabled: enabled ? '1' : '',
        } ).done( function ( resp ) {
            if ( ! resp.success ) {
                $toggle.prop( 'checked', ! enabled );
                return;
            }
            applyMasterEnabledState( enabled );
        } ).fail( function () {
            $toggle.prop( 'checked', ! enabled );
        } );
    }

    function bindMasterToggle() {
        $( document ).on( 'change', SELECTORS.MASTER_TOGGLE, function () {
            saveMasterToggle( $( this ).is( ':checked' ) );
        } );
    }

    /**
     * The generic .mmi-collapsible-section accordion (import-preview.js)
     * just toggles a 'collapsed' class with no event of its own — so a
     * section that needs to lazy-load on first expand adds its own listener
     * on the same trigger elements, scoped to its own section, and checks
     * the resulting state on the next tick (setTimeout 0) rather than
     * assuming this handler fires after the generic one, since jQuery fires
     * same-event delegated handlers in registration order and this file's
     * load order relative to import-preview.js isn't guaranteed.
     */
    function bindSectionExpand() {
        $( document ).on( 'click', '.mmi-section-header, .mmi-collapse-toggle', function () {
            const $section = $( this ).closest( '#mmi-duplicate-products-section' );
            if ( ! $section.length ) {
                return;
            }
            setTimeout( function () {
                if ( ! $section.hasClass( 'collapsed' ) ) {
                    window.MMIDupes.ensureLoaded();
                }
            }, 0 );
        } );
    }

    /**
     * Deep-link support for the retired standalone tab's bookmarked URLs
     * (redirected by main.php) — ?open_duplicates=1 expands the section and
     * scrolls it into view, mirroring taxonomy-mapping.js's identical
     * ?open_taxonomy=1 handling.
     */
    function maybeOpenFromUrl() {
        const params = new URLSearchParams( window.location.search );
        if ( params.get( 'open_duplicates' ) !== '1' ) {
            return;
        }
        const $section = $( '#mmi-duplicate-products-section' );
        $section.removeClass( 'collapsed' );
        const status = params.get( 'dupes_status' );
        if ( status && $( `${ SELECTORS.STAT_FILTER_BTN }[data-status-filter="${ status }"]` ).length ) {
            statusFilter = status;
            $( SELECTORS.STAT_FILTER_BTN ).removeClass( 'is-active' )
                .filter( `[data-status-filter="${ status }"]` ).addClass( 'is-active' );
        }
        // ?dupes_brand=Moog: one maker's groups (the Distributors Vendors list links here).
        urlBrand = ( params.get( 'dupes_brand' ) || '' ).trim().toLowerCase();
        if ( params.get( 'detection' ) === 'collisions' ) {
            setDetectionMode( 'collisions' );
        } else {
            window.MMIDupes.ensureLoaded();
        }
        $section[ 0 ]?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
    }

    $( document ).ready( function () {
        bindRefreshButton();
        bindStatFilters();
        bindBrandFilter();
        bindSortHeaders();
        bindRowEvents();
        bindSectionExpand();
        bindMasterToggle();
        bindDetectionModeSelect();
        bindCollisionApproveButtons();
        applyStoredColumnWidths();
        initColumnResize();

        if ( isMasterEnabled() && $( SELECTORS.APP ).is( ':visible' ) ) {
            window.MMIDupes.ensureLoaded();
        }
        maybeOpenFromUrl();
    } );

    /* ── Scan ──────────────────────────────────────────────────────────────── */

    function beginScan() {
        pendingScans++;
        showLoading( true );
        hideNotice();
        $( SELECTORS.TABLE ).addClass( 'mmi-hidden' );
        $( SELECTORS.EMPTY ).addClass( 'mmi-hidden' );
        $( SELECTORS.STATS ).addClass( 'mmi-hidden' );
    }

    function endScan() {
        pendingScans = Math.max( 0, pendingScans - 1 );
        if ( pendingScans === 0 ) {
            showLoading( false );
        }
    }

    /**
     * Merges the sources and re-renders — see allGroups' own comment. A "Same MPN"
     * group (source 'mpn') replaces any Fresh match or Legacy group whose listings it
     * already contains, so one product is one row (a Fresh match and a Same MPN row both
     * holding #7403 and #18553 was the 2026-09-30 report). The replaced rows are named
     * on the MPN group as `covers`, for its review hint.
     */
    function mergeAndRender() {
        const merged = [ ...existingGroups, ...candidateGroups ];
        const idsOf  = ( g ) => ( g.members || [] ).map( ( m ) => m.wc_product_id ).filter( Boolean );
        const mpn    = merged.filter( ( g ) => g.source === 'mpn' );
        const legacy = merged.filter( ( g ) => g.source === 'legacy' );
        mpn.forEach( ( g ) => { g.covers = []; g.nothing_on_sale = false; g.sale_candidate_id = 0; } );
        const contains = ( outer, ids ) => {
            const set = new Set( idsOf( outer ) );
            return ids.every( ( id ) => set.has( id ) );
        };
        allGroups = merged.filter( ( g ) => {
            if ( g.source === 'mpn' || ! ( g.members || [] ).length || ! g.members.every( ( m ) => m.wc_product_id ) ) {
                return true; // a group with an unimported feed item can't be judged as contained
            }
            const ids   = idsOf( g );
            const owner = mpn.find( ( m ) => contains( m, ids ) );
            if ( ! owner ) {
                // A brand + name match whose listings are all already in one linked
                // group is that group (the 2026-09-30 "Avid" / "AVID" AudioScore
                // Ultimate pair): the Legacy row manages it.
                return ! ( g.source === 'candidate' && legacy.some( ( l ) => contains( l, ids ) ) );
            }
            owner.covers.push( g.source === 'legacy' ? `the existing linked group of #${ g.canonical_product_id }` : 'the brand + name match' );
            if ( g.nothing_on_sale ) {
                owner.nothing_on_sale      = true;
                owner.no_sale_canonical_id = g.canonical_product_id;
                owner.sale_candidate_id    = owner.sale_candidate_id || g.sale_candidate_id;
            }
            return false;
        } );
        allGroups.forEach( ( g ) => { g.availability = groupAvailability( g ); } );
        updateStats();
        updateBrandFilterAvailability( allGroups );
        sortAndRerender();
    }

    function scanCandidates( forceRefresh ) {
        beginScan();

        $.post( AJAX_URL, {
            action:        'mmi_scan_duplicate_candidates',
            nonce:         NONCE,
            force_refresh: forceRefresh ? 1 : 0,
        } )
        .done( function ( resp ) {
            endScan();
            if ( ! resp.success ) {
                showNotice( 'Scan failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }
            candidateGroups = ( resp.data.groups || [] ).map( function ( g ) {
                return Object.assign( { source: 'candidate' }, g );
            } );
            mergeAndRender();
        } )
        .fail( function ( xhr ) {
            endScan();
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    /**
     * Loads the real, pre-existing canonical/associated_product_ids groups
     * (the "fold legacy links into one queue" ask) — a database scan,
     * independent of scanCandidates()'s feed scan. See
     * CanonicalCandidatesController.php's mmi_scan_existing_canonical_groups.
     */
    function scanExistingGroups( forceRefresh ) {
        beginScan();

        $.post( AJAX_URL, {
            action:        'mmi_scan_existing_canonical_groups',
            nonce:         NONCE,
            force_refresh: forceRefresh ? 1 : 0,
        } )
        .done( function ( resp ) {
            endScan();
            if ( ! resp.success ) {
                showNotice( 'Existing-groups scan failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }
            existingGroups = resp.data.groups || [];
            mergeAndRender();
        } )
        .fail( function ( xhr ) {
            endScan();
            showNotice( 'AJAX error loading existing groups: ' + xhr.statusText, 'error' );
        } );
    }

    /**
     * Loads Confirmed Collisions data on first use only (same "guard the
     * expensive first load" shape as hasLoadedOnce above) — callback fires
     * once data is available, whether that meant a fresh scan or reusing
     * what's already loaded.
     */
    function ensureCollisionsLoaded( callback ) {
        if ( hasLoadedCollisionsOnce ) {
            if ( callback ) { callback(); }
            return;
        }
        hasLoadedCollisionsOnce = true;
        scanCollisions( false, callback );
    }

    function scanCollisions( forceRefresh, callback ) {
        showLoading( true );
        hideNotice();
        $( SELECTORS.COLLISION_TABLE ).addClass( 'mmi-hidden' );
        $( SELECTORS.COLLISION_EMPTY ).addClass( 'mmi-hidden' );

        $.post( AJAX_URL, {
            action:        'mmi_scan_pk_collisions',
            nonce:         NONCE,
            force_refresh: forceRefresh ? 1 : 0,
        } )
        .done( function ( resp ) {
            showLoading( false );
            if ( ! resp.success ) {
                showNotice( 'Scan failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                $( SELECTORS.COLLISION_EMPTY ).removeClass( 'mmi-hidden' );
                return;
            }
            allCollisionGroups = resp.data.groups || [];
            renderCollisionGroups( allCollisionGroups );
            if ( callback ) { callback(); }
        } )
        .fail( function ( xhr ) {
            showLoading( false );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    function renderCollisionGroups( groups ) {
        const $tbody = $( SELECTORS.COLLISION_TBODY );
        $tbody.empty();

        if ( ! groups.length ) {
            $( SELECTORS.COLLISION_TABLE ).addClass( 'mmi-hidden' );
            $( SELECTORS.COLLISION_EMPTY ).removeClass( 'mmi-hidden' );
            return;
        }

        groups.forEach( function ( group ) {
            const membersHtml = group.members.map( function ( m ) {
                // Both sides independently Reverb-synced means two LIVE
                // listings for one physical item, not just a stale catalog
                // row — see CanonicalCandidatesController.php's own comment
                // on why this is surfaced explicitly rather than left to a
                // separate lookup.
                const reverbBadge = m.reverb_sync
                    ? `<span class="mmi-badge warning" title="Independently synced to its own Reverb listing (#${ escHtml( String( m.reverb_listing_id || '' ) ) })">📻 Reverb-synced</span>`
                    : '';

                let actionHtml;
                if ( group.resolved && group.canonical_id === m.product_id ) {
                    actionHtml = '<span class="mmi-dupes-status status-linked">✓ Canonical</span>';
                } else if ( group.resolved ) {
                    actionHtml = '<span class="mmi-collision-suppressed-badge">Suppressed (out of stock, hidden)</span>';
                } else {
                    const recTitle = m.is_recommended
                        ? ' — the one every import run already resolves to'
                        : '';
                    actionHtml = `<button type="button" class="button mmi-action-btn mmi-collision-approve-btn" data-group-supplier="${ escHtml( group.supplier ) }" data-group-value="${ escHtml( group.value ) }" data-canonical-id="${ m.product_id }" title="Keep this one live; the other(s) in this group will be marked out of stock and hidden${ recTitle }">Set as canonical${ m.is_recommended ? ' <span class="mmi-collision-recommended-tag">(recommended)</span>' : '' }</button>`;
                }

                return `
                    <div class="mmi-collision-member">
                        <a href="${ escHtml( m.product_url ) }" target="_blank" class="mmi-product-link">#${ escHtml( m.product_id ) }</a>
                        <strong>${ escHtml( m.product_name || '(untitled)' ) }</strong>
                        <span class="mmi-collision-detail">SKU: ${ escHtml( m.sku || '—' ) } · $${ escHtml( String( m.regular_price || '0' ) ) } · ${ escHtml( m.stock_status || '—' ) }</span>
                        ${ reverbBadge }
                        <div class="mmi-collision-action">${ actionHtml }</div>
                    </div>`;
            } ).join( '' );

            const statusHtml = group.resolved
                ? `<span class="mmi-badge mmi-badge-truncate mmi-badge-unchanged">✓ Resolved</span>`
                : `<span class="mmi-badge mmi-badge-truncate mmi-badge-conflict">Needs review</span>`;

            $tbody.append( `
                <tr data-supplier="${ escHtml( group.supplier ) }" data-value="${ escHtml( group.value ) }">
                    <td><span class="mmi-badge mmi-badge-truncate">${ escHtml( group.supplier ) }</span></td>
                    <td><code>${ escHtml( group.value ) }</code></td>
                    <td>${ membersHtml }</td>
                    <td>${ statusHtml }</td>
                </tr>
            ` );
        } );

        $( SELECTORS.COLLISION_TABLE ).removeClass( 'mmi-hidden' );
        $( SELECTORS.COLLISION_EMPTY ).addClass( 'mmi-hidden' );
    }

    /** Briefly highlights the row a Review & Compare badge deep-linked to. */
    function highlightCollisionRow( supplier, value ) {
        const $row = $( SELECTORS.COLLISION_TBODY )
            .find( `tr[data-supplier="${ supplier }"][data-value="${ value }"]` );
        if ( ! $row.length ) { return; }
        $row.addClass( 'mmi-collision-row-highlight' );
        $row[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'center' } );
        setTimeout( function () { $row.removeClass( 'mmi-collision-row-highlight' ); }, 4000 );
    }

    /**
     * "Set as canonical" — writes the SAME canonical/associated_product_ids
     * meta pair the Candidates flow's mmi_approve_duplicate_group already
     * uses (see that endpoint's own docblock); no new resolution mechanism
     * needed. This is a real, consequential write: the endpoint forces every
     * other member out of stock + shop-only visibility at once (the same rule
     * as Catalog Maintenance's "canonical" phase), and mmi-reverb-integration's own stock sync (confirmed
     * live in class-reverb-product.php) unpublishes a product's Reverb
     * listing once it goes out of stock — so choosing a canonical here can
     * take down a live Reverb listing for whichever member isn't picked.
     * The confirm() dialog says so explicitly rather than leaving it implicit.
     */
    function bindCollisionApproveButtons() {
        $( document ).on( 'click', '.mmi-collision-approve-btn', function () {
            const $btn         = $( this );
            const supplier     = $btn.data( 'group-supplier' );
            const value        = String( $btn.data( 'group-value' ) );
            const canonicalId  = parseInt( $btn.data( 'canonical-id' ), 10 );

            const group = allCollisionGroups.find( g => g.supplier === supplier && String( g.value ) === value );
            if ( ! group ) { return; }

            const others          = group.members.filter( m => m.product_id !== canonicalId );
            const reverbAffected  = others.filter( m => m.reverb_sync );
            let confirmMsg = `Keep #${ canonicalId } as the live product. The other ${ others.length } product(s) (${ others.map( m => '#' + m.product_id ).join( ', ' ) }) will be marked out of stock and hidden from the shop.`;
            if ( reverbAffected.length ) {
                confirmMsg += `\n\n⚠ ${ reverbAffected.length } of them ${ reverbAffected.length === 1 ? 'is' : 'are' } independently synced to a live Reverb listing — that listing will be unpublished on the next Reverb sync.`;
            }
            confirmMsg += '\n\nContinue?';
            if ( ! window.confirm( confirmMsg ) ) { return; }

            $btn.prop( 'disabled', true ).text( 'Saving…' );

            $.post( AJAX_URL, {
                action:                  'mmi_approve_duplicate_group',
                nonce:                   NONCE,
                canonical_product_id:    canonicalId,
                associated_product_ids:  others.map( m => m.product_id ),
            } )
            .done( function ( resp ) {
                if ( ! resp.success ) {
                    showNotice( 'Failed to save: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                    $btn.prop( 'disabled', false ).text( 'Set as canonical' );
                    return;
                }
                showNotice( `#${ canonicalId } set as canonical — the other product(s) are now out of stock and off the shop search.`, 'success' );
                scanCollisions( true ); // Re-scan so this group's status/actions reflect the write just made.
            } )
            .fail( function ( xhr ) {
                showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
                $btn.prop( 'disabled', false ).text( 'Set as canonical' );
            } );
        } );
    }

    function bindRefreshButton() {
        $( document ).on( 'click', SELECTORS.REFRESH_BTN, function () {
            if ( ! isMasterEnabled() ) { return; }
            if ( detectionMode === 'collisions' ) {
                scanCollisions( true );
            } else {
                scanCandidates( true );
                scanExistingGroups( true );
            }
        } );
    }

    /* ── Stats / status filter ────────────────────────────────────────────── */

    function updateStats() {
        const total  = allGroups.length;
        const linked = allGroups.filter( g => g.already_linked ).length;
        $( SELECTORS.TOTAL ).text( total );
        $( SELECTORS.LINKED_COUNT ).text( linked );
        $( SELECTORS.NEW_COUNT ).text( total - linked );
        const noSale = allGroups.filter( g => g.nothing_on_sale ).length;
        $( SELECTORS.NO_SALE_COUNT ).text( noSale );
        $( SELECTORS.NO_SALE_STAT ).toggleClass( 'mmi-hidden', ! noSale && statusFilter !== 'no_sale' );
        const notSold = allGroups.filter( g => g.availability === 'notsold' ).length;
        $( SELECTORS.NOT_SOLD_COUNT ).text( notSold );
        $( SELECTORS.NOT_SOLD_STAT ).toggleClass( 'mmi-hidden', ! notSold && statusFilter !== 'not_sold' );
        $( SELECTORS.STATS ).removeClass( 'mmi-hidden' );
    }

    function bindStatFilters() {
        $( document ).on( 'click', SELECTORS.STAT_FILTER_BTN, function () {
            const $btn = $( this );
            statusFilter = $btn.data( 'status-filter' ) || 'all';
            $( SELECTORS.STAT_FILTER_BTN ).removeClass( 'is-active' );
            $btn.addClass( 'is-active' );
            renderTable( visibleGroups() );
        } );
    }

    /**
     * Builds the "Brand" filter row from whatever distinct brands actually
     * appear in the currently-loaded groups (candidates + legacy links) — no
     * separate AJAX call, mirroring import-preview.js's
     * updateTaxonomyFilterAvailability() pattern. A legacy-linked group's
     * brand can be a genuine blank (its canonical product has no
     * product_brand term) — those groups are simply excluded from the
     * option list, not bucketed under a fake "(No brand)" entry.
     */
    function updateBrandFilterAvailability( groups ) {
        const brands = new Map(); // lowercased brand -> display name
        groups.forEach( function ( g ) {
            const name = ( g.brand || '' ).trim();
            if ( name ) {
                brands.set( name.toLowerCase(), name );
            }
        } );

        const $row    = $( SELECTORS.BRAND_FILTER_ROW );
        const $inline = $( SELECTORS.BRAND_FILTER_INLINE );

        if ( brands.size === 0 ) {
            $row.addClass( 'mmi-hidden' );
            $inline.empty();
            brandFilter = {};
            return;
        }

        if ( urlBrand && brands.has( urlBrand ) ) {
            brandFilter = { [ urlBrand ]: true };
            urlBrand    = '';
        }

        const sorted = Array.from( brands.entries() ).sort( ( a, b ) => a[ 1 ].localeCompare( b[ 1 ] ) );
        // Pure DOM-size safety valve, not a meaningful UX cap — bounded by how
        // many distinct brands are actually present in the loaded groups.
        const MAX_OPTIONS = 400;
        const overLimit = sorted.length > MAX_OPTIONS;
        const visible = overLimit ? sorted.slice( 0, MAX_OPTIONS ) : sorted;

        let html = '<details class="mmi-taxonomy-filter-details">'
            + '<summary>Brand</summary>'
            + '<div class="mmi-taxonomy-filter-options">';

        if ( overLimit ) {
            html += '<select class="' + SELECTORS.BRAND_FILTER_SELECT.slice( 1 ) + '" multiple size="8">'
                + visible.map( ( [ slug, name ] ) => '<option value="' + escHtml( slug ) + '"' + ( brandFilter[ slug ] ? ' selected' : '' ) + '>' + escHtml( name ) + '</option>' ).join( '' )
                + '</select>'
                + '<span class="description mmi-taxonomy-filter-hint">Showing ' + visible.length + ' of ' + sorted.length + ' brands.</span>';
        } else {
            html += visible.map( ( [ slug, name ] ) =>
                '<label class="mmi-taxonomy-filter-option">'
                + '<input type="checkbox" class="' + SELECTORS.BRAND_FILTER_CHECKBOX.slice( 1 ) + '" value="' + escHtml( slug ) + '"' + ( brandFilter[ slug ] ? ' checked' : '' ) + '> '
                + escHtml( name )
                + '</label>'
            ).join( '' );
        }

        html += '</div></details>';

        $inline.html( html );
        $row.removeClass( 'mmi-hidden' );

        // A selection whose brand is still listed stays (checked above); one
        // with no control left on screen to un-check is dropped rather than
        // silently filtering out rows.
        brandFilter = Object.fromEntries( Object.keys( brandFilter ).filter( ( slug ) => brands.has( slug ) ).map( ( slug ) => [ slug, true ] ) );
    }

    function bindBrandFilter() {
        $( document ).on( 'change', SELECTORS.BRAND_FILTER_CHECKBOX + ', ' + SELECTORS.BRAND_FILTER_SELECT, function () {
            const selected = {};
            $( SELECTORS.BRAND_FILTER_CHECKBOX + ':checked' ).each( function () {
                selected[ $( this ).val() ] = true;
            } );
            $( SELECTORS.BRAND_FILTER_SELECT + ' option:selected' ).each( function () {
                selected[ $( this ).val() ] = true;
            } );
            brandFilter = selected;
            sortAndRerender();
        } );
    }

    function visibleGroups() {
        let groups = allGroups;
        if ( statusFilter === 'new' )    { groups = groups.filter( g => ! g.already_linked ); }
        else if ( statusFilter === 'linked' ) { groups = groups.filter( g => g.already_linked ); }
        else if ( statusFilter === 'no_sale' ) { groups = groups.filter( g => g.nothing_on_sale ); }
        else if ( statusFilter === 'not_sold' ) { groups = groups.filter( g => g.availability === 'notsold' ); }

        const brandKeys = Object.keys( brandFilter );
        if ( brandKeys.length ) {
            groups = groups.filter( g => brandFilter[ ( g.brand || '' ).toLowerCase() ] );
        }

        return groups;
    }

    /* ── Sort ──────────────────────────────────────────────────────────────── */

    /*
     * The queue's own headings only (`> thead`): each expanded group nests a
     * member table inside #mmi-dupes-table, whose headings the shared
     * mmi-table-sort.js marks .sortable too. A plain `thead th.sortable`
     * matched those as well, so clicking a member heading re-sorted and
     * re-rendered the whole queue and closed the group being reviewed.
     */
    const QUEUE_SORT_TH = SELECTORS.TABLE + ' > thead th.sortable';

    function bindSortHeaders() {
        $( document ).on( 'click', QUEUE_SORT_TH, function () {
            // A column-resize drag ending over the header (not the thin resize
            // handle itself) synthesizes a click here too — see resizeJustEnded's
            // own comment above. Ignore it so resizing a column never doubles as
            // sorting it.
            if ( resizeJustEnded ) {
                return;
            }
            const col = $( this ).data( 'col' );
            if ( sortState.col === col ) {
                sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
            } else {
                sortState.col = col;
                sortState.dir = col === 'members' ? 'desc' : 'asc';
            }
            updateSortIcons();
            sortAndRerender();
        } );
    }

    function updateSortIcons() {
        // The shared sorted-asc/-desc arrow (mmi-suite-common.css), not a second
        // text arrow in .sort-icon: the heading used to show both.
        $( QUEUE_SORT_TH ).each( function () {
            const $th    = $( this );
            const active = $th.data( 'col' ) === sortState.col;
            $th.toggleClass( 'sort-active', active )
                .toggleClass( 'sorted-asc', active && sortState.dir === 'asc' )
                .toggleClass( 'sorted-desc', active && sortState.dir === 'desc' )
                .attr( 'aria-sort', active ? ( sortState.dir === 'asc' ? 'ascending' : 'descending' ) : 'none' );
            $th.find( '.sort-icon' ).text( '' );
        } );
    }

    function sortAndRerender() {
        updateSortIcons();
        const { col, dir } = sortState;
        const sorted = [ ...visibleGroups() ].sort( ( a, b ) => {
            let va, vb;
            if ( col === 'price_spread' ) {
                // Price sorts by spread (same price first ascending); groups
                // with fewer than two prices have nothing to compare and stay
                // last in either direction.
                const na = ( Number( a.price_count ) || 0 ) < 2;
                const nb = ( Number( b.price_count ) || 0 ) < 2;
                if ( na !== nb ) { return na ? 1 : -1; }
            }
            if ( col === 'members' )      { va = a.members.length; vb = b.members.length; }
            else if ( col === 'price_spread' ) { va = a.price_spread;  vb = b.price_spread;  }
            else if ( col === 'status' )  { va = a.already_linked ? 1 : 0; vb = b.already_linked ? 1 : 0; }
            else if ( col === 'stock' )   { va = GROUP_AVAILABILITY[ a.availability ].rank; vb = GROUP_AVAILABILITY[ b.availability ].rank; }
            else if ( col === 'source' )  { va = a.source || ''; vb = b.source || ''; }
            else if ( col === 'brand' )   { va = ( a.brand || '' ).toLowerCase(); vb = ( b.brand || '' ).toLowerCase(); }
            else                           { va = ( a.name  || '' ).toLowerCase(); vb = ( b.name  || '' ).toLowerCase(); }

            if ( va < vb ) { return dir === 'asc' ? -1 :  1; }
            if ( va > vb ) { return dir === 'asc' ?  1 : -1; }
            return 0;
        } );
        renderTable( sorted );
    }

    /* ── Render ────────────────────────────────────────────────────────────── */

    function renderTable( groups ) {
        const $tbody = $( SELECTORS.TBODY );
        $tbody.empty();

        if ( ! groups.length ) {
            $( SELECTORS.EMPTY ).removeClass( 'mmi-hidden' );
            $( SELECTORS.TABLE ).addClass( 'mmi-hidden' );
            return;
        }

        $.each( groups, function ( _, group ) {
            $tbody.append( buildGroupRow( group ) );
        } );

        $( SELECTORS.EMPTY ).addClass( 'mmi-hidden' );
        $( SELECTORS.TABLE ).removeClass( 'mmi-hidden' );
    }

    function formatPrice( v ) {
        return '$' + ( Number( v ) || 0 ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
    }

    /**
     * Queue Price cell (MAP, else store price): one price with a "Same price"
     * chip when every listing matches, the range when they differ, a dash
     * when none has a price.
     */
    function buildPriceCell( group ) {
        const $td   = $( '<td class="col-spread">' );
        const count = Number( group.price_count ) || 0;
        if ( ! count ) {
            return $td.addClass( 'is-empty' ).attr( 'title', 'No listing in this group has a MAP or store price' ).text( '—' );
        }
        if ( group.price_spread > 0 ) {
            $td.text( `${ formatPrice( group.price_min ) } – ${ formatPrice( group.price_max ) }` );
        } else {
            $td.text( formatPrice( group.price_min ) );
            if ( group.price_match ) {
                $td.append( ' ', $( '<span class="mmi-dupes-price-chip">' ).text( 'Same price' ) );
            }
        }
        if ( count < group.members.length ) {
            $td.attr( 'title', `${ count } of ${ group.members.length } listings have a price` );
        }
        return $td;
    }

    /** Queue Stock cell: the group's availability, with how many listings are in stock. */
    function buildStockCell( group ) {
        const def     = GROUP_AVAILABILITY[ group.availability ];
        const inStock = group.members.filter( ( m ) => [ 'instock', 'backorder' ].includes( memberAvailability( m ) ) ).length;
        const $td     = $( '<td class="col-stock">' ).append(
            $( '<span class="mmi-badge">' ).addClass( def.badge ).attr( 'title', def.title ).text( def.label )
        );
        if ( inStock ) {
            $td.append( ' ', $( '<span class="mmi-dupes-stock-count">' )
                .attr( 'title', `${ inStock } of ${ group.members.length } listings in stock` )
                .text( `${ inStock }/${ group.members.length }` ) );
        }
        return $td;
    }

    /**
     * Renders a term/product thumbnail cell prefix, or nothing when the group
     * has no real image to show — an empty group.brand_thumbnail_url/image_url
     * (no matching term, no featured image, etc.) is common and not an error.
     */
    function thumbHtml( url, cssClass, alt ) {
        if ( ! url ) { return ''; }
        return `<img class="${ cssClass }" src="${ escHtml( url ) }" alt="${ escHtml( alt ) }" loading="lazy">`;
    }

    /**
     * The whole row is the click target (no separate Review/Manage button —
     * see the Actions-column removal below); only the arrow icon hints at
     * this visually. Dismiss (candidates only) and Unlink (legacy only) both
     * moved into the expanded review/manage panel itself, alongside the
     * group's own real member data, rather than living in a now-removed
     * Actions column.
     */
    function buildGroupRow( group ) {
        const statusClass = group.nothing_on_sale ? 'status-nosale' : ( group.already_linked ? 'status-linked' : 'status-new' );
        const statusLabel = group.nothing_on_sale ? 'Nothing on sale' : ( group.already_linked ? 'Linked' : 'New' );
        const isLegacy     = group.source === 'legacy';
        const isMpn        = group.source === 'mpn';
        const sourceClass  = isLegacy ? 'info' : ( isMpn ? 'success' : 'warning' );
        const sourceLabel  = isLegacy ? 'Legacy link' : ( isMpn ? 'Same MPN' : 'Fresh match' );
        const sourceTitle  = isLegacy
            ? 'A real, pre-existing canonical/associated_product_ids link found directly in the database'
            : ( isMpn
                ? `Same brand and manufacturer part number (${ group.match || '' }) — the strongest evidence of one product listed more than once`
                : 'Detected by matching brand + product name across live supplier feeds' );

        const $tr = $( '<tr class="mmi-dupes-row" role="button" tabindex="0">' )
            .toggleClass( 'is-not-sold', group.availability === 'notsold' )
            .attr( 'data-group-key', group.group_key )
            .attr( 'data-source', group.source || 'candidate' )
            .attr( 'title', group.already_linked ? 'Click to manage this group' : 'Click to review this group' );

        $tr.append( '<td class="col-arrow"><span class="dashicons dashicons-arrow-right-alt2 mmi-dupes-expand-icon"></span></td>' );
        $tr.append(
            $( '<td class="col-brand">' ).html(
                thumbHtml( group.brand_thumbnail_url, 'mmi-dupes-brand-thumb', group.brand ) + escHtml( group.brand )
            )
        );
        $tr.append(
            $( '<td class="col-name">' ).html(
                thumbHtml( group.image_url, 'mmi-dupes-product-thumb', group.name ) + escHtml( decodeEntities( group.name ) )
            )
        );
        $tr.append( $( '<td class="col-members">' ).text( group.members.length ) );
        $tr.append( buildPriceCell( group ) );
        $tr.append( buildStockCell( group ) );
        $tr.append( $( `<td class="col-source"><span class="mmi-badge ${ sourceClass }" title="${ escHtml( sourceTitle ) }">${ sourceLabel }</span></td>` ) );
        $tr.append( $( `<td class="col-status"><span class="mmi-dupes-status ${ statusClass }">${ statusLabel }</span></td>` ) );

        return $tr;
    }

    /* ── Review / approve ──────────────────────────────────────────────────── */

    function bindRowEvents() {
        // The whole row is the click target now (no separate Review/Manage
        // button — see buildGroupRow()'s own comment). Bails out when the
        // click just concluded a real text selection (e.g. copying a SKU out
        // of an expanded member list) rather than toggling — same guard shape
        // this project's own admin-xchange.js already uses for its row-click
        // handlers, for the identical reason: a click-drag-release to select
        // text still fires a native 'click' on mouseup with no other way to
        // tell it apart from a deliberate row click.
        $( document ).on( 'click', SELECTORS.TBODY + ' tr.mmi-dupes-row', function () {
            if ( window.getSelection().toString().length > 0 ) { return; }
            toggleReviewRow( $( this ) );
        } );
        // The picked canonical listing carries the accent outline.
        $( document ).on( 'change', SELECTORS.TBODY + ' .mmi-dupes-canon-radio', function () {
            $( this ).closest( '.mmi-dupes-listings' ).children().removeClass( 'is-canonical' );
            $( this ).closest( '.mmi-dupes-listing' ).addClass( 'is-canonical' );
        } );
        // Chip and card selected looks are classes, not :has(:checked), which
        // WebKit doesn't restyle when a radio changes. A radio change re-marks
        // every input of its group.
        $( document ).on( 'change', SELECTORS.TBODY + ' .mmi-dupes-choice input, ' + SELECTORS.TBODY + ' .mmi-dupes-pick input', function () {
            const changed = this;
            const $group  = changed.type === 'radio'
                ? $( changed ).closest( '.mmi-dupes-review-box' ).find( 'input[type="radio"]' ).filter( ( _, el ) => el.name === changed.name )
                : $( changed );
            $group.each( function () {
                $( this ).closest( '.mmi-dupes-pick' ).toggleClass( 'is-picked', this.checked );
                $( this ).closest( '.mmi-dupes-choice' ).toggleClass( 'is-on', this.checked );
            } );
        } );
        $( document ).on( 'keydown', SELECTORS.TBODY + ' tr.mmi-dupes-row', function ( e ) {
            if ( e.key === 'Enter' || e.key === ' ' ) {
                e.preventDefault();
                toggleReviewRow( $( this ) );
            }
        } );

        // Dismiss ("not actually a duplicate") now lives inside the expanded
        // review panel for a fresh candidate — see buildReviewRow()'s own
        // actions row. It resolves the group from the review row's own
        // data-group-key (mirrored onto the review <tr> by toggleReviewRow()),
        // not the closed group row, since a click inside the panel isn't
        // nested under 'tr.mmi-dupes-row'.
        $( document ).on( 'click', SELECTORS.TBODY + ' .mmi-dupes-dismiss-btn', function () {
            const $reviewRow = $( this ).closest( 'tr.mmi-dupes-review-row' );
            const groupKey   = $reviewRow.attr( 'data-group-key' );
            const $groupRow  = $reviewRow.prev( 'tr.mmi-dupes-row' );
            if ( ! confirm( 'Hide this group? It will not be flagged as a duplicate again.' ) ) { return; }
            dismissGroup( groupKey, $groupRow );
        } );

        $( document ).on( 'click', SELECTORS.TBODY + ' .mmi-dupes-approve-btn', function () {
            const $reviewRow = $( this ).closest( 'tr.mmi-dupes-review-row' );
            approveGroup( $reviewRow );
        } );

        /* ── Legacy-group membership management ───────────────────────────── */

        $( document ).on( 'click', SELECTORS.TBODY + ' .mmi-dupes-make-canonical-btn', function () {
            const $btn           = $( this );
            const canonicalId    = parseInt( $btn.data( 'canonical-id' ), 10 );
            const newCanonicalId = parseInt( $btn.data( 'new-canonical-id' ), 10 );

            if ( ! confirm( `Make product #${ newCanonicalId } the canonical listing? #${ canonicalId } and the other member(s) go out of stock and off the shop search right away.` ) ) {
                return;
            }

            $btn.prop( 'disabled', true );
            $.post( AJAX_URL, {
                action:                'mmi_canonical_reassign_canonical',
                nonce:                 NONCE,
                canonical_product_id:  canonicalId,
                new_canonical_id:      newCanonicalId,
            } )
            .done( function ( resp ) {
                if ( ! resp.success ) {
                    showNotice( 'Failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                    $btn.prop( 'disabled', false );
                    return;
                }
                applyGroupAction( resp, $btn.closest( 'tr.mmi-dupes-review-row' ).attr( 'data-group-key' ) );
            } )
            .fail( function ( xhr ) {
                showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
                $btn.prop( 'disabled', false );
            } );
        } );

        $( document ).on( 'click', SELECTORS.TBODY + ' .mmi-dupes-remove-member-btn', function () {
            const $btn        = $( this );
            const canonicalId = parseInt( $btn.data( 'canonical-id' ), 10 );
            const memberId    = parseInt( $btn.data( 'member-id' ), 10 );

            if ( ! confirm( `Remove product #${ memberId } from this group? It will reset to a plain, unlinked product.` ) ) {
                return;
            }

            $btn.prop( 'disabled', true );
            $.post( AJAX_URL, {
                action:                'mmi_canonical_remove_group_member',
                nonce:                 NONCE,
                canonical_product_id:  canonicalId,
                member_product_id:     memberId,
            } )
            .done( function ( resp ) {
                if ( ! resp.success ) {
                    showNotice( 'Failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                    $btn.prop( 'disabled', false );
                    return;
                }
                applyGroupAction( resp, $btn.closest( 'tr.mmi-dupes-review-row' ).attr( 'data-group-key' ) );
            } )
            .fail( function ( xhr ) {
                showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
                $btn.prop( 'disabled', false );
            } );
        } );

        $( document ).on( 'click', SELECTORS.TBODY + ' .mmi-dupes-apply-winners-btn', function () {
            const $btn        = $( this );
            const $reviewRow  = $btn.closest( 'tr.mmi-dupes-review-row' );
            const canonicalId = parseInt( $btn.data( 'canonical-id' ), 10 );
            const winners     = collectFieldWinners( $reviewRow );

            if ( ! Object.keys( winners ).length ) {
                showNotice( 'Nothing to apply — no winner selections found.', 'error' );
                return;
            }

            $btn.prop( 'disabled', true );
            $.post( AJAX_URL, {
                action:               'mmi_canonical_apply_field_winners',
                nonce:                NONCE,
                canonical_product_id: canonicalId,
                field_winners:        JSON.stringify( winners ),
            } )
            .done( function ( resp ) {
                if ( ! resp.success ) {
                    showNotice( resp.data?.message || 'Nothing to apply', 'error' );
                    $btn.prop( 'disabled', false );
                    return;
                }
                applyGroupAction( resp, $btn.closest( 'tr.mmi-dupes-review-row' ).attr( 'data-group-key' ) );
            } )
            .fail( function ( xhr ) {
                showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
                $btn.prop( 'disabled', false );
            } );
        } );

        $( document ).on( 'click', SELECTORS.TBODY + ' .mmi-dupes-unlink-group-btn', function () {
            const $btn        = $( this );
            const canonicalId = parseInt( $btn.data( 'canonical-id' ), 10 );

            if ( ! confirm( 'Unlink this entire group? Every member (including the current canonical) will reset to a plain, unlinked product.' ) ) {
                return;
            }

            $btn.prop( 'disabled', true );
            $.post( AJAX_URL, {
                action:                'mmi_canonical_unlink_group',
                nonce:                 NONCE,
                canonical_product_id:  canonicalId,
            } )
            .done( function ( resp ) {
                if ( ! resp.success ) {
                    showNotice( 'Failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                    $btn.prop( 'disabled', false );
                    return;
                }
                applyGroupAction( resp, $btn.closest( 'tr.mmi-dupes-review-row' ).attr( 'data-group-key' ) );
            } )
            .fail( function ( xhr ) {
                showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
                $btn.prop( 'disabled', false );
            } );
        } );
    }

    /**
     * Swaps one group's row (and its open panel) for the server's re-read of it
     * after a Duplicates action, instead of rescanning every group. newGroup null
     * = the group is gone (unlinked or dissolved): its row is removed.
     */
    function replaceGroup( oldKey, newGroup, message ) {
        const swap = ( list ) => {
            const i = list.findIndex( ( g ) => g.group_key === oldKey );
            if ( i < 0 ) { return; }
            if ( newGroup ) { list.splice( i, 1, newGroup ); } else { list.splice( i, 1 ); }
        };
        if ( newGroup ) { newGroup.availability = groupAvailability( newGroup ); }
        swap( existingGroups );
        swap( allGroups );

        const $row     = $( SELECTORS.TBODY + ' tr.mmi-dupes-row' ).filter( ( _, el ) => el.getAttribute( 'data-group-key' ) === oldKey );
        const $panel   = $row.next( 'tr.mmi-dupes-review-row' );
        const wasOpen  = $panel.length > 0;
        $panel.remove();
        if ( newGroup && $row.length ) {
            const $newRow = buildGroupRow( newGroup );
            $row.replaceWith( $newRow );
            if ( wasOpen ) {
                toggleReviewRow( $newRow );
                if ( message ) {
                    $newRow.next( 'tr.mmi-dupes-review-row' ).find( '.mmi-dupes-review-box' )
                        .prepend( $( '<div class="mmi-dupes-notice notice-success" role="status">' ).text( message ) );
                }
            }
        } else {
            $row.remove();
        }
        updateStats();
    }

    /** Shared success path of the linked-group actions: message, then the one row. */
    function applyGroupAction( resp, oldKey ) {
        showNotice( resp.data.message, 'success' );
        replaceGroup( resp.data.old_group_key || oldKey, resp.data.group || null, resp.data.message );
    }

    function toggleReviewRow( $row ) {
        const $existing = $row.next( 'tr.mmi-dupes-review-row' );
        if ( $existing.length ) {
            $existing.remove();
            $row.find( '.mmi-dupes-expand-icon' ).removeClass( 'is-open' );
            return;
        }

        // Close any other open review row — one at a time keeps the table predictable.
        $( SELECTORS.TBODY + ' tr.mmi-dupes-review-row' ).remove();
        $( SELECTORS.TBODY + ' .mmi-dupes-expand-icon' ).removeClass( 'is-open' );

        const groupKey = $row.attr( 'data-group-key' );
        const group    = allGroups.find( g => g.group_key === groupKey );
        if ( ! group ) { return; }

        $row.find( '.mmi-dupes-expand-icon' ).addClass( 'is-open' );
        // Legacy (already-linked) groups get a membership-management review row
        // (remove/reassign/unlink); fresh candidates get the existing pick-and-
        // link flow, unchanged. Scoped to source === 'legacy' rather than the
        // more general already_linked flag — a Candidates-scan group that
        // happens to already be linked has no reliable group-level
        // canonical_product_id to edit against (see this file's own note on
        // why, in the handoff doc); it keeps its existing read-only display.
        const $reviewRow = group.source === 'legacy' ? buildLegacyReviewRow( group ) : buildReviewRow( group );
        // Mirrored from the group row so the Dismiss button inside the panel
        // (buildReviewRow()'s own actions row) can resolve which group it's
        // acting on without needing 'tr.mmi-dupes-row' as an ancestor.
        $reviewRow.attr( 'data-group-key', groupKey );
        $row.after( $reviewRow );

        // The opened group goes to the top of the scroll box, under the sticky
        // header, so its whole panel is on screen at once.
        const wrap = $( SELECTORS.TABLE_WRAP )[ 0 ];
        if ( wrap ) {
            const headHeight = $( SELECTORS.TABLE ).children( 'thead' ).outerHeight() || 0;
            wrap.scrollTop += $row[ 0 ].getBoundingClientRect().top - wrap.getBoundingClientRect().top - headHeight;
        }
    }

    function buildReviewRow( group ) {
        const colspan = 8;
        const $tr  = $( '<tr class="mmi-dupes-review-row">' );
        const $td  = $( '<td>' ).attr( 'colspan', colspan );
        const $box = $( '<div class="mmi-dupes-review-box">' );

        // The lowest non-zero cost listing is preselected as canonical (user
        // rule, 2026-09-30), even over one already marked canonical; with no
        // real cost in the group, an existing canonical, else the server's pick.
        const lowestId = lowestCostId( group.members );
        const preselectedCanonicalId = lowestId
            || ( group.members.find( m => m.is_canonical === true && m.wc_product_id )
                || group.members.find( m => m.is_recommended === true )
                || {} ).wc_product_id
            || 0;

        const ctx = startReviewBox( $box, group, preselectedCanonicalId, [
            'Pick the canonical listing (the one customers see; the lowest cost is preselected) and tick every listing that is the same item.'
                + ( group.members.some( m => ! m.wc_product_id ) ? ' Listings not imported yet can’t be linked until they are.' : '' ),
            group.multiple_canonicals ? 'More than one is marked canonical: approving keeps the one you pick and folds the other’s links into it.' : '',
            ( group.covers || [] ).length ? `This row replaces ${ group.covers.join( ' and ' ) }; linking here completes it.` : '',
        ] );
        addAvailabilityAlert( $box, group );

        const $list = $( '<ul class="mmi-dupes-listings">' );
        group.members.forEach( ( member ) => {
            const resolved = !! member.wc_product_id;
            const $controls = [
                $( '<label class="mmi-dupes-choice">' ).toggleClass( 'is-on', resolved && member.wc_product_id === preselectedCanonicalId ).append(
                    $( '<input type="radio" class="mmi-dupes-canon-radio">' )
                        .attr( { name: 'mmi-dupes-canon-' + group.group_key, disabled: ! resolved } )
                        .attr( 'data-product-id', member.wc_product_id || 0 )
                        .prop( 'checked', resolved && member.wc_product_id === preselectedCanonicalId ),
                    $( '<span>' ).text( 'Canonical' )
                ),
                $( '<label class="mmi-dupes-choice">' ).toggleClass( 'is-on', resolved ).append(
                    $( '<input type="checkbox" class="mmi-dupes-include-check">' )
                        .attr( 'disabled', ! resolved )
                        .attr( 'data-product-id', member.wc_product_id || 0 )
                        .prop( 'checked', resolved ),
                    $( '<span>' ).text( 'Include' )
                ),
            ];
            $list.append( buildListingRow( member, ctx, $controls ) );
        } );
        $box.append( $list );

        const assetFields = visibleAssetFields( group.members );
        if ( assetFields.length ) {
            $box.append( buildFieldPickers(
                assetFields,
                group.members,
                computeFieldWinnerDefaults( group.members, preselectedCanonicalId ),
                'mmi-dupes-winner-' + group.group_key + '-',
                'For each field, pick whose value the canonical listing keeps, whichever listing is canonical.'
            ) );
        }

        $box.append(
            $( '<div class="mmi-dupes-review-actions">' ).append(
                // Dismiss ("not actually a duplicate") lives here now that the
                // table's own Actions column is gone — see bindRowEvents()'s
                // own comment on why it resolves the group from this review
                // row rather than a closed 'tr.mmi-dupes-row'.
                $( '<button class="mmi-dupes-pill-btn mmi-dupes-dismiss-btn" title="Not actually a duplicate — hide this group">' )
                    .html( '<span class="dashicons dashicons-no-alt"></span> Not a duplicate' ),
                $( '<button class="mmi-dupes-pill-btn mmi-dupes-pill-btn--primary mmi-dupes-approve-btn">' )
                    .html( '<span class="dashicons dashicons-yes-alt"></span> Link Selected' )
            )
        );

        $td.append( $box );
        $tr.append( $td );
        return $tr;
    }

    /**
     * Review row for an already-linked "legacy" group — membership management
     * (remove a member, reassign canonical, unlink the whole group) rather
     * than the pick-and-link flow buildReviewRow() above uses for a brand-new
     * candidate. See changelog.md 1.72.0 for the original design rationale.
     */
    function buildLegacyReviewRow( group ) {
        const colspan = 8;
        const $tr  = $( '<tr class="mmi-dupes-review-row">' );
        const $td  = $( '<td>' ).attr( 'colspan', colspan );
        const $box = $( '<div class="mmi-dupes-review-box">' );

        const ctx = startReviewBox( $box, group, group.canonical_product_id, [
            'Removing a listing resets it to a plain, unlinked product (it can re-enter this queue later); Make Canonical moves which listing customers see.',
        ] );
        addAvailabilityAlert( $box, group );

        const $list = $( '<ul class="mmi-dupes-listings">' );
        group.members.forEach( function ( member ) {
            // One look for the controls: the "✓ Canonical" badge and both buttons are
            // .mmi-dupes-status.status-linked (buttons add --btn for cursor/hover).
            const $actions = $( '<div class="mmi-dupes-member-actions">' );
            if ( member.is_canonical ) {
                $actions.append( '<span class="mmi-dupes-status status-linked">✓ Canonical</span>' );
            } else {
                $actions.append(
                    $( '<button type="button" class="mmi-dupes-status status-linked mmi-dupes-status--btn mmi-dupes-make-canonical-btn" title="Make this the canonical (customer-facing) product">' )
                        .text( 'Make Canonical' )
                        .toggleClass( 'is-suggested', member.wc_product_id === group.sale_candidate_id )
                        .attr( { 'data-canonical-id': group.canonical_product_id, 'data-new-canonical-id': member.wc_product_id } ),
                    $( '<button type="button" class="mmi-dupes-status status-linked mmi-dupes-status--btn mmi-dupes-remove-member-btn" title="Reset this product to a plain, unlinked listing">' )
                        .text( '✕ Remove' )
                        .attr( { 'data-canonical-id': group.canonical_product_id, 'data-member-id': member.wc_product_id } )
                );
            }
            $list.append( buildListingRow( member, ctx, $actions ) );
        } );
        $box.append( $list );

        const assetFields = visibleAssetFields( group.members );
        if ( assetFields.length ) {
            $box.append( buildFieldPickers(
                assetFields,
                group.members,
                computeFieldWinnerDefaults( group.members, group.canonical_product_id ),
                'mmi-dupes-legacy-winner-' + group.canonical_product_id + '-',
                'Pick a value for any field, then Apply Selected Winners copies it onto the current canonical listing.'
            ) );
        }

        const $reviewActions = $( '<div class="mmi-dupes-review-actions">' );
        if ( assetFields.length ) {
            $reviewActions.append(
                $( '<button type="button" class="mmi-dupes-pill-btn mmi-dupes-pill-btn--primary mmi-dupes-apply-winners-btn" title="Copy each selected winner’s value onto the current canonical product">' )
                    .html( '<span class="dashicons dashicons-yes-alt"></span> Apply Selected Winners' )
                    .attr( 'data-canonical-id', group.canonical_product_id )
            );
        }
        $reviewActions.append(
            $( '<button type="button" class="button mmi-action-btn mmi-action-btn--danger mmi-dupes-unlink-group-btn" title="Reset every member of this group back to a plain, unlinked product">' )
                .html( '<span class="dashicons dashicons-editor-unlink"></span> Unlink Entire Group' )
                .attr( 'data-canonical-id', group.canonical_product_id )
        );
        $box.append( $reviewActions );

        $td.append( $box );
        $tr.append( $td );
        return $tr;
    }

    /**
     * Under the verdict: a group no supplier sells gets the "not sold" note (the
     * stronger statement, so it replaces the nothing-on-sale alert); else a group
     * with nothing on sale gets that alert.
     */
    function addAvailabilityAlert( $box, group ) {
        let $alert = null;
        if ( group.availability === 'notsold' ) {
            $alert = $( '<div class="mmi-dupes-sale-alert is-not-sold" role="status">' ).append(
                $( '<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>' ),
                $( '<strong>' ).text( 'Nothing here can be sold. ' ),
                document.createTextNode( 'Every listing is out of stock and either gone from its supplier’s feed or held out by a stock rule, so which one is canonical changes nothing customers can buy. It can be left as it is.' )
            );
        } else if ( group.nothing_on_sale ) {
            $alert = buildNoSaleAlert( group );
        }
        if ( $alert ) {
            $box.children( '.mmi-dupes-verdict' ).after( $alert );
        }
    }

    /**
     * A linked group nobody can buy from: the canonical is out of stock and its
     * duplicates are held out of stock. Names the duplicate still in a supplier
     * feed (sale_candidate_id), whose Make Canonical button is highlighted.
     */
    function buildNoSaleAlert( group ) {
        const candidate = group.members.find( m => m.wc_product_id === group.sale_candidate_id );
        const $alert    = $( '<div class="mmi-dupes-sale-alert" role="status">' ).append(
            $( '<span class="dashicons dashicons-warning" aria-hidden="true"></span>' ),
            $( '<strong>' ).text( 'Nothing in this group is on sale. ' )
        );
        const isLegacy = group.source === 'legacy';
        let text = `Canonical #${ group.no_sale_canonical_id || group.canonical_product_id } is out of stock, and its duplicates are held out of stock.`;
        text += candidate
            ? ` #${ candidate.wc_product_id } is still in the ${ SUPPLIER_LABELS[ candidate.supplier ] || candidate.supplier } feed: `
                + ( isLegacy ? 'Make Canonical on it' : 'picking it as the canonical listing and linking' )
                + ' puts the product back on sale at the next Catalog Maintenance run.'
            : ' None of its listings is in a supplier feed now, so there is no listing to switch to.';
        return $alert.append( document.createTextNode( text ) );
    }

    function approveGroup( $reviewRow ) {
        const $canonRadio = $reviewRow.find( '.mmi-dupes-canon-radio:checked' );
        if ( ! $canonRadio.length ) {
            showNotice( 'Pick a canonical listing first.', 'error' );
            return;
        }
        const canonicalId = parseInt( $canonRadio.attr( 'data-product-id' ), 10 );
        const associated  = $reviewRow.find( '.mmi-dupes-include-check:checked' )
            .map( function () { return parseInt( $( this ).attr( 'data-product-id' ), 10 ); } )
            .get()
            .filter( id => id && id !== canonicalId );

        if ( ! associated.length ) {
            showNotice( 'Select at least one other product to associate.', 'error' );
            return;
        }

        const fieldWinners = collectFieldWinners( $reviewRow );

        const $groupRow = $reviewRow.prev( 'tr.mmi-dupes-row' );
        $reviewRow.addClass( 'is-saving' );

        const groupEntry = allGroups.find( g => g.group_key === $groupRow.attr( 'data-group-key' ) ) || {};
        $.post( AJAX_URL, {
            action:                   'mmi_approve_duplicate_group',
            nonce:                    NONCE,
            canonical_product_id:     canonicalId,
            associated_product_ids:   associated,
            field_winners:            JSON.stringify( fieldWinners ),
            // Same-MPN groups may hold a second canonical listing: fold it into the one picked here.
            merge_canonicals:         groupEntry.source === 'mpn' ? 1 : 0,
        } )
        .done( function ( resp ) {
            $reviewRow.removeClass( 'is-saving' );
            if ( ! resp.success ) {
                showNotice( 'Save failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }
            const fieldsApplied = resp.data.fields_applied || [];
            const message = fieldsApplied.length > 0
                ? `${ resp.data.message } — applied ${ fieldsApplied.length } winning field(s) to the canonical product.`
                : resp.data.message;
            showNotice( message, 'success' );

            const groupKey = $groupRow.attr( 'data-group-key' );
            const entry = allGroups.find( g => g.group_key === groupKey );
            if ( entry ) {
                entry.already_linked = true;
            }
            updateStats();
            sortAndRerender();
        } )
        .fail( function ( xhr ) {
            $reviewRow.removeClass( 'is-saving' );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    function dismissGroup( groupKey, $row ) {
        $row.addClass( 'is-saving' );
        $.post( AJAX_URL, {
            action:    'mmi_dismiss_duplicate_group',
            nonce:     NONCE,
            group_key: groupKey,
        } )
        .done( function ( resp ) {
            if ( ! resp.success ) {
                $row.removeClass( 'is-saving' );
                showNotice( 'Dismiss failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }
            allGroups = allGroups.filter( g => g.group_key !== groupKey );
            updateStats();
            sortAndRerender();
        } )
        .fail( function ( xhr ) {
            $row.removeClass( 'is-saving' );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    /* ── Column resize (CLAUDE.md Data Tables standard) ───────────────────── */

    function applyStoredColumnWidths() {
        let stored;
        try {
            stored = JSON.parse( localStorage.getItem( COLUMN_WIDTH_STORAGE_KEY ) );
        } catch ( e ) { /* ignore — localStorage unavailable or corrupt value */ }
        if ( ! stored || typeof stored !== 'object' ) { return; }
        Object.keys( stored ).forEach( function ( key ) {
            const width = parseInt( stored[ key ], 10 );
            if ( ! width || width < 40 ) { return; }
            $( SELECTORS.TABLE ).find( 'th[data-resize-col="' + key + '"]' ).css( 'width', width + 'px' );
        } );
    }

    function initColumnResize() {
        let $resizingTh  = null;
        let resizeStartX = 0;
        let resizeStartW = 0;

        $( document ).on( 'mousedown', SELECTORS.RESIZE_HANDLE, function ( e ) {
            // Stops the mousedown itself from bubbling (harmless on its own,
            // kept mainly to block text selection while dragging) — but does
            // NOT, on its own, stop the native 'click' the browser still fires
            // after mouseup. See resizeJustEnded's own comment above for the
            // fix that actually prevents the accidental sort.
            e.preventDefault();
            e.stopPropagation();
            $resizingTh  = $( this ).closest( 'th' );
            resizeStartX = e.pageX;
            resizeStartW = $resizingTh.outerWidth();
            $( this ).addClass( 'is-resizing' );
            $( 'body' ).css( 'cursor', 'col-resize' );
        } );

        // Covers the no/tiny-drag case: a plain click that lands back on the
        // resize handle itself. Stopped before it bubbles to the sortable
        // <th>'s delegated click handler.
        $( document ).on( 'click', SELECTORS.RESIZE_HANDLE, function ( e ) {
            e.stopPropagation();
        } );

        $( document ).on( 'mousemove', function ( e ) {
            if ( ! $resizingTh ) { return; }
            const newWidth = Math.max( 40, resizeStartW + ( e.pageX - resizeStartX ) );
            $resizingTh.css( 'width', newWidth + 'px' );
        } );

        $( document ).on( 'mouseup', function () {
            if ( ! $resizingTh ) { return; }
            const key   = $resizingTh.data( 'resize-col' );
            const width = $resizingTh.outerWidth();
            $resizingTh.find( SELECTORS.RESIZE_HANDLE ).removeClass( 'is-resizing' );
            $( 'body' ).css( 'cursor', '' );

            if ( key ) {
                let stored;
                try {
                    stored = JSON.parse( localStorage.getItem( COLUMN_WIDTH_STORAGE_KEY ) ) || {};
                } catch ( e ) { stored = {}; }
                stored[ key ] = width;
                try {
                    localStorage.setItem( COLUMN_WIDTH_STORAGE_KEY, JSON.stringify( stored ) );
                } catch ( e ) { /* ignore — localStorage unavailable (e.g. private browsing) */ }
            }
            $resizingTh = null;

            // Covers the general case: mouseup (and the click that follows it)
            // landing over the header body rather than the handle. Cleared on
            // the next tick, after that click has had a chance to fire and
            // check this flag, so a genuine later click still sorts normally.
            resizeJustEnded = true;
            setTimeout( function () { resizeJustEnded = false; }, 0 );
        } );
    }

    /* ── UI helpers ────────────────────────────────────────────────────────── */

    function showLoading( show ) {
        $( SELECTORS.LOADING ).toggleClass( 'mmi-hidden', ! show );
    }

    function showNotice( message, type ) {
        $( SELECTORS.NOTICE )
            .removeClass( 'notice-success notice-error mmi-hidden' )
            .addClass( type === 'error' ? 'notice-error' : 'notice-success' )
            .text( message );
    }

    function hideNotice() {
        $( SELECTORS.NOTICE ).addClass( 'mmi-hidden' );
    }

    function escHtml( str ) {
        return window.MMIEscapeHtml( str );
    }

    /**
     * Same-MPN and linked group names come from get_the_title(), which
     * encodes "&" as "&#038;"; decoded once here so escHtml() doesn't print
     * the entity. A <textarea> decodes without running anything.
     */
    function decodeEntities( str ) {
        const el = document.createElement( 'textarea' );
        el.innerHTML = str || '';
        return el.value;
    }

} )( jQuery );
