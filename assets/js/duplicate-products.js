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
        STAT_FILTER_BTN:'.mmi-dupes-stat',
        BRAND_FILTER_ROW:      '#mmi-dupes-brand-filter-row',
        BRAND_FILTER_INLINE:   '#mmi-dupes-brand-filters',
        BRAND_FILTER_CHECKBOX: '.mmi-dupes-brand-filter-checkbox',
        BRAND_FILTER_SELECT:   '.mmi-dupes-brand-filter-select',
        NOTICE:         '#mmi-dupes-notice',
        TABLE:          '#mmi-dupes-table',
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

    /** Short cell content for one member's value of one asset field. */
    function memberAssetPreviewHtml( member, fieldKey ) {
        const a = member.assets || {};
        switch ( fieldKey ) {
            case 'image':
                return a.image_thumb_url
                    ? `<img class="mmi-dupes-asset-thumb" src="${ escHtml( a.image_thumb_url ) }" alt="" loading="lazy">`
                    : '—';
            case 'gallery':
                return a.gallery_count ? `${ a.gallery_count } image(s)` : '—';
            case 'content':
                return a.content_preview ? escHtml( a.content_preview ) : '—';
            case 'title':
                return a.title ? escHtml( a.title ) : '—';
            case 'excerpt':
                return a.excerpt_preview ? escHtml( a.excerpt_preview ) : '—';
            default:
                return '—';
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

    /** One <td> for an asset-winner column — a radio when the member has data, a dash otherwise. */
    function buildWinnerCell( fieldKey, radioGroupName, member, defaultWinnerId ) {
        if ( ! member.wc_product_id || ! memberHasAsset( member, fieldKey ) ) {
            return $( '<td class="mmi-dupes-winner-cell mmi-dupes-winner-cell--empty">—</td>' );
        }
        const $td = $( '<td class="mmi-dupes-winner-cell">' );
        $td.append(
            $( '<input type="radio" class="mmi-dupes-winner-radio">' )
                .attr( { name: radioGroupName, 'data-field': fieldKey, 'data-product-id': member.wc_product_id } )
                .prop( 'checked', member.wc_product_id === defaultWinnerId )
        );
        // The title is already the Product cell's link text (both are the
        // listing's post_title), so its winner cell is just the radio.
        if ( fieldKey !== 'title' ) {
            const $preview = $( '<span class="mmi-dupes-winner-preview">' ).html( memberAssetPreviewHtml( member, fieldKey ) );
            if ( fieldKey !== 'image' ) { $preview.attr( 'title', $preview.text() ); }
            $td.append( $preview );
        }
        return $td;
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

    /** Cost cell; the lowest non-zero cost is highlighted rather than tagged in the Product cell. */
    function buildCostCell( member, lowestId ) {
        const isLowest = !! lowestId && member.wc_product_id === lowestId;
        return $( '<td class="mmi-dupes-cost">' )
            .toggleClass( 'mmi-dupes-cost--lowest', isLowest )
            .attr( 'title', isLowest ? 'Lowest cost in this group' : null )
            .text( formatPrice( member.dealer_price ) );
    }

    /** Product cell: the listing's title, linked to its edit screen, with its ID. */
    function buildProductCell( member ) {
        if ( ! member.wc_product_id ) {
            return $( '<td>' ).html( '<em>not yet imported</em>' );
        }
        const title = memberTitle( member );
        return $( '<td class="mmi-dupes-product-cell">' ).append(
            $( '<a target="_blank">' )
                .attr( 'href', `/wp-admin/post.php?post=${ member.wc_product_id }&action=edit` )
                .text( title ? `${ title } (#${ member.wc_product_id })` : `#${ member.wc_product_id }` )
        );
    }

    /**
     * Member-table headings. Each carries a resize handle (initColumnResize()
     * handles both tables) and is sorted by the shared mmi-table-sort.js; the
     * shared resize/sort guard keeps a resize drag from also sorting.
     */
    function memberTh( key, label, extraClass ) {
        return `<th class="col-mt-${ key }${ extraClass ? ' ' + extraClass : '' }" data-resize-col="mt-${ key }">${ escHtml( label ) }` +
            `<span class="mmi-dupes-th-resize" data-resize-col="mt-${ key }"></span></th>`;
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
     * a page reload — mirrors import-pipeline-catalog-maintenance.js's
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
        mpn.forEach( ( g ) => { g.covers = []; } );
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
            return false;
        } );
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
     * needed. This is a real, consequential write: the Catalog Maintenance
     * "canonical" phase (already scheduled — see class-catalog-phase-runner.php)
     * will force every other member out of stock + shop-only visibility on
     * its next run, and mmi-reverb-integration's own stock sync (confirmed
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
                showNotice( `#${ canonicalId } set as canonical — the other product(s) will be suppressed on the next Catalog Maintenance run.`, 'success' );
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
                + visible.map( ( [ slug, name ] ) => '<option value="' + escHtml( slug ) + '">' + escHtml( name ) + '</option>' ).join( '' )
                + '</select>'
                + '<span class="description mmi-taxonomy-filter-hint">Showing ' + visible.length + ' of ' + sorted.length + ' brands.</span>';
        } else {
            html += visible.map( ( [ slug, name ] ) =>
                '<label class="mmi-taxonomy-filter-option">'
                + '<input type="checkbox" class="' + SELECTORS.BRAND_FILTER_CHECKBOX.slice( 1 ) + '" value="' + escHtml( slug ) + '"> '
                + escHtml( name )
                + '</label>'
            ).join( '' );
        }

        html += '</div></details>';

        $inline.html( html );
        $row.removeClass( 'mmi-hidden' );

        // Freshly built and unchecked — a previous selection no longer maps
        // to a rendered control, so clear it rather than silently filtering
        // out rows with nothing left on screen to un-check.
        brandFilter = {};
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
                sortState.dir = ( col === 'members' || col === 'price_spread' ) ? 'desc' : 'asc';
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
            if ( col === 'members' )      { va = a.members.length; vb = b.members.length; }
            else if ( col === 'price_spread' ) { va = a.price_spread;  vb = b.price_spread;  }
            else if ( col === 'status' )  { va = a.already_linked ? 1 : 0; vb = b.already_linked ? 1 : 0; }
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
        return '$' + ( Number( v ) || 0 ).toFixed( 2 );
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
        const statusClass = group.already_linked ? 'status-linked' : 'status-new';
        const statusLabel = group.already_linked ? 'Linked' : 'New';
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
                thumbHtml( group.image_url, 'mmi-dupes-product-thumb', group.name ) + escHtml( group.name )
            )
        );
        $tr.append( $( '<td class="col-members">' ).text( group.members.length ) );
        $tr.append( $( '<td class="col-spread">' ).text(
            group.price_spread > 0
                ? `${ formatPrice( group.price_min ) } – ${ formatPrice( group.price_max ) }`
                : formatPrice( group.price_min )
        ) );
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

            if ( ! confirm( `Make product #${ newCanonicalId } the canonical listing? #${ canonicalId } and the other member(s) will be suppressed on the next Catalog Maintenance run.` ) ) {
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
                showNotice( resp.data.message, 'success' );
                scanExistingGroups( true );
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
                showNotice( resp.data.message, 'success' );
                scanExistingGroups( true );
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
                showNotice( resp.data.message, 'success' );
                scanExistingGroups( true );
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
                showNotice( resp.data.message, 'success' );
                scanExistingGroups( true );
            } )
            .fail( function ( xhr ) {
                showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
                $btn.prop( 'disabled', false );
            } );
        } );
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
        // Two frames later: the shared mmi-table-sort.js marks the new headings
        // .sortable (adding arrow padding) in the next frame; pinning widths
        // before that clipped them ("SUP…").
        const $memberTable = $reviewRow.find( '.mmi-dupes-member-table' );
        window.requestAnimationFrame( () => window.requestAnimationFrame( () => applyStoredMemberWidths( $memberTable ) ) );
    }

    function buildReviewRow( group ) {
        const colspan = 7;
        const $tr  = $( '<tr class="mmi-dupes-review-row">' );
        const $td  = $( '<td>' ).attr( 'colspan', colspan );
        const $box = $( '<div class="mmi-dupes-review-box">' );

        $box.append( '<p class="mmi-dupes-review-hint">Pick which listing is canonical (shown to customers; the lowest cost is preselected) and which others are the same item under a different vendor. For each product data field below, pick which candidate’s value should win on the canonical listing — independent of which one is canonical. Unresolved items aren’t imported as WooCommerce products yet and can’t be linked or contribute data until they are.</p>' );
        if ( group.source === 'mpn' ) {
            // Plain text only: the part number comes from product meta.
            $box.append( $( '<p class="mmi-dupes-review-hint">' ).text(
                `Every listing below carries the same ${ group.brand } part number, ${ group.match }.`
                + ( group.multiple_canonicals ? ' More than one is marked canonical: approving keeps the one you pick and folds the other’s links into it.' : '' )
                + ( ( group.covers || [] ).length ? ` This row replaces ${ group.covers.join( ' and ' ) }; linking here completes it.` : '' )
            ) );
        }

        // The lowest non-zero cost listing is preselected as canonical (user
        // rule, 2026-09-30), even over one already marked canonical; with no
        // real cost in the group, an existing canonical, else the server's pick.
        const lowestId = lowestCostId( group.members );
        const preselectedCanonicalId = lowestId
            || ( group.members.find( m => m.is_canonical === true && m.wc_product_id )
                || group.members.find( m => m.is_recommended === true )
                || {} ).wc_product_id
            || 0;

        const assetFields   = visibleAssetFields( group.members );
        const winnerDefaults = computeFieldWinnerDefaults( group.members, preselectedCanonicalId );
        const winnerGroupPrefix = 'mmi-dupes-winner-' + group.group_key + '-';

        const $table = $( '<table class="mmi-dupes-member-table">' );
        const $thead = $( '<thead><tr>' +
            memberTh( 'supplier', 'Supplier' ) +
            memberTh( 'sku', 'SKU' ) +
            memberTh( 'vendor', 'Vendor' ) +
            memberTh( 'cost', 'Cost' ) +
            memberTh( 'product', 'Product' ) +
            memberTh( 'status', 'Status' ) +
            assetFields.map( f => memberTh( 'winner-' + f.key, f.label, 'col-mt-winner' ) ).join( '' ) +
            memberTh( 'canon', 'Canonical' ) +
            memberTh( 'include', 'Include' ) +
            '</tr></thead>' );
        const $tbody = $( '<tbody>' );

        group.members.forEach( ( member, idx ) => {
            const resolved = !! member.wc_product_id;
            const $row = $( '<tr class="mmi-dupes-member-row">' ).toggleClass( 'is-unresolved', ! resolved );

            $row.append( $( '<td>' ).html( `<strong>${ escHtml( member.supplier ) }</strong>` ) );
            $row.append( $( '<td>' ).text( member.sku || '—' ) );
            $row.append( $( '<td>' ).text( member.vendor || '—' ) );
            $row.append( buildCostCell( member, lowestId ) );
            $row.append( buildProductCell( member ) );
            $row.append( $( '<td>' ).text( resolved ? ( member.stock_status || '—' ) : '—' ) );

            assetFields.forEach( function ( f ) {
                $row.append( buildWinnerCell( f.key, winnerGroupPrefix + f.key, member, winnerDefaults[ f.key ] ) );
            } );

            const radioId = `mmi-dupes-canon-${ group.group_key }-${ idx }`.replace( /[^a-zA-Z0-9_-]/g, '_' );
            $row.append( $( '<td>' ).append(
                $( '<input type="radio" class="mmi-dupes-canon-radio">' )
                    .attr( { name: 'mmi-dupes-canon-' + group.group_key, id: radioId, disabled: ! resolved } )
                    .attr( 'data-product-id', member.wc_product_id || 0 )
                    .prop( 'checked', member.wc_product_id === preselectedCanonicalId )
            ) );
            $row.append( $( '<td>' ).append(
                $( '<input type="checkbox" class="mmi-dupes-include-check">' )
                    .attr( 'disabled', ! resolved )
                    .attr( 'data-product-id', member.wc_product_id || 0 )
                    .prop( 'checked', resolved )
            ) );

            $tbody.append( $row );
        } );

        $table.append( $thead ).append( $tbody );
        $box.append( $table );

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
        const colspan = 7;
        const $tr  = $( '<tr class="mmi-dupes-review-row">' );
        const $td  = $( '<td>' ).attr( 'colspan', colspan );
        const $box = $( '<div class="mmi-dupes-review-box">' );

        $box.append( '<p class="mmi-dupes-review-hint">This group is already linked. Removing a member resets it to a plain, unlinked product (it can naturally re-enter this queue later); reassigning canonical moves which listing stays visible to customers. Picking a winner for a product data field below and clicking "Apply Selected Winners" copies that candidate’s value onto the current canonical product — independent of which one is canonical.</p>' );

        const assetFields    = visibleAssetFields( group.members );
        const winnerDefaults = computeFieldWinnerDefaults( group.members, group.canonical_product_id );
        const winnerGroupPrefix = 'mmi-dupes-legacy-winner-' + group.canonical_product_id + '-';

        const $table = $( '<table class="mmi-dupes-member-table">' );
        const lowestId = lowestCostId( group.members );
        const $thead = $( '<thead><tr>' +
            memberTh( 'supplier', 'Supplier' ) +
            memberTh( 'sku', 'SKU' ) +
            memberTh( 'vendor', 'Vendor' ) +
            memberTh( 'cost', 'Cost' ) +
            memberTh( 'product', 'Product' ) +
            memberTh( 'status', 'Status' ) +
            assetFields.map( f => memberTh( 'winner-' + f.key, f.label, 'col-mt-winner' ) ).join( '' ) +
            memberTh( 'actions', 'Actions' ) +
            '</tr></thead>' );
        const $tbody = $( '<tbody>' );

        group.members.forEach( function ( member ) {
            const $row = $( '<tr class="mmi-dupes-member-row">' );

            $row.append( $( '<td>' ).html( `<strong>${ escHtml( member.supplier ) }</strong>` ) );
            $row.append( $( '<td>' ).text( member.sku || '—' ) );
            $row.append( $( '<td>' ).text( member.vendor || '—' ) );
            $row.append( buildCostCell( member, lowestId ) );
            $row.append( buildProductCell( member ) );
            $row.append( $( '<td>' ).text( `${ member.stock_status || '—' } / ${ member.visibility || '—' }` ) );

            assetFields.forEach( function ( f ) {
                $row.append( buildWinnerCell( f.key, winnerGroupPrefix + f.key, member, winnerDefaults[ f.key ] ) );
            } );

            // One look for the column: the "✓ Canonical" badge and both buttons are
            // .mmi-dupes-status.status-linked (buttons add --btn for cursor/hover).
            const $actions = $( '<div class="mmi-dupes-member-actions">' );
            if ( member.is_canonical ) {
                $actions.append( '<span class="mmi-dupes-status status-linked">✓ Canonical</span>' );
            } else {
                $actions.append(
                    $( '<button type="button" class="mmi-dupes-status status-linked mmi-dupes-status--btn mmi-dupes-make-canonical-btn" title="Make this the canonical (customer-facing) product">' )
                        .text( 'Make Canonical' )
                        .attr( { 'data-canonical-id': group.canonical_product_id, 'data-new-canonical-id': member.wc_product_id } ),
                    $( '<button type="button" class="mmi-dupes-status status-linked mmi-dupes-status--btn mmi-dupes-remove-member-btn" title="Reset this product to a plain, unlinked listing">' )
                        .text( '✕ Remove' )
                        .attr( { 'data-canonical-id': group.canonical_product_id, 'data-member-id': member.wc_product_id } )
                );
            }
            $row.append( $( '<td class="mmi-dupes-actions-cell">' ).append( $actions ) );

            $tbody.append( $row );
        } );

        $table.append( $thead ).append( $tbody );
        $box.append( $table );

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

    /**
     * Member tables lay out automatically until a column is resized; then every
     * heading is pinned to its current pixel width and the table switches to
     * fixed layout, so dragging one column moves only that column (the table
     * grows or shrinks; its wrapper scrolls). Widths persist per column key.
     */
    function freezeMemberTable( $table ) {
        if ( $table.hasClass( 'is-resized' ) ) { return; }
        const $ths = $table.find( '> thead > tr > th' );
        const widths = $ths.map( function () { return $( this ).outerWidth(); } ).get();
        $ths.each( function ( i ) { $( this ).css( 'width', widths[ i ] + 'px' ); } );
        $table.addClass( 'is-resized' );
        syncMemberTableWidth( $table );
    }

    function syncMemberTableWidth( $table ) {
        let total = 0;
        $table.find( '> thead > tr > th' ).each( function () { total += parseFloat( this.style.width ) || $( this ).outerWidth(); } );
        $table.css( 'width', Math.round( total ) + 'px' );
    }

    function applyStoredMemberWidths( $table ) {
        if ( ! $table.length ) { return; }
        let stored;
        try {
            stored = JSON.parse( localStorage.getItem( COLUMN_WIDTH_STORAGE_KEY ) );
        } catch ( e ) { /* ignore — localStorage unavailable or corrupt value */ }
        if ( ! stored || typeof stored !== 'object' ) { return; }
        const $ths = $table.find( '> thead > tr > th' ).filter( function () {
            return parseInt( stored[ $( this ).data( 'resize-col' ) ], 10 ) >= 40;
        } );
        if ( ! $ths.length ) { return; }
        freezeMemberTable( $table );
        $ths.each( function () { $( this ).css( 'width', parseInt( stored[ $( this ).data( 'resize-col' ) ], 10 ) + 'px' ); } );
        syncMemberTableWidth( $table );
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
            const $memberTable = $resizingTh.closest( '.mmi-dupes-member-table' );
            if ( $memberTable.length ) { freezeMemberTable( $memberTable ); }
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
            const $memberTable = $resizingTh.closest( '.mmi-dupes-member-table' );
            if ( $memberTable.length ) { syncMemberTableWidth( $memberTable ); }
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

} )( jQuery );
