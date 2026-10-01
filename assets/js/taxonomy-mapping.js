/**
 * MMI VIP — Taxonomy Mapping UI
 *
 * Handles brand/category mapping to WooCommerce taxonomy terms.
 * Communicates with TaxonomyMappingController.php via AJAX.
 */
( function ( $ ) {
    'use strict';

    /* ── Constants ─────────────────────────────────────────────────────── */

    const AJAX_URL = window.mmiTaxMapping?.ajaxUrl || ajaxurl;
    const NONCE    = window.mmiTaxMapping?.nonce   || '';

    /* ── Selectors ──────────────────────────────────────────────────────── */

    const SELECTORS = {
        APP:              '#mmi-taxmap-app',
        FILTER_PILLS:     '#mmi-taxmap-filter-pills',
        PILL_BTN:         '.mmi-taxmap-pill',
        CUSTOM_BTN:       '#mmi-taxmap-custom-btn',
        CONFIG_PANEL:     '#mmi-taxmap-config-panel',
        SUPPLIER:         '#mmi-taxmap-supplier',
        SOURCE_FIELD:     '#mmi-taxmap-source-field',
        WC_TAXONOMY:      '#mmi-taxmap-wc-taxonomy',
        SCAN_BTN:         '#mmi-taxmap-scan-btn',
        REFRESH_BTN:      '#mmi-taxmap-refresh-btn',
        APPLY_BTN:        '#mmi-taxmap-apply-btn',
        PROGRESS:         '#mmi-taxmap-progress',
        PROGRESS_FILL:    '#mmi-taxmap-progress-fill',
        PROGRESS_TEXT:    '#mmi-taxmap-progress-text',
        NOTICE:           '#mmi-taxmap-notice',
        STATS:            '#mmi-taxmap-stats',
        TOTAL:            '#mmi-taxmap-total',
        MAPPED_COUNT:     '#mmi-taxmap-mapped-count',
        UNMAPPED_COUNT:   '#mmi-taxmap-unmapped-count',
        TABLE:            '#mmi-taxmap-table',
        TBODY:            '#mmi-taxmap-tbody',
        EMPTY:            '#mmi-taxmap-empty',
        LOADING:          '#mmi-taxmap-loading',
        STAT_FILTER_BTN:  '.mmi-taxmap-stat[data-status-filter]',
        SEARCH_INPUT:     '#mmi-taxmap-search-input',
        AUTOCOMPLETE:     '#mmi-taxmap-autocomplete',
        AC_HEADER:        '#mmi-taxmap-ac-header',
        AC_LIST:          '#mmi-taxmap-autocomplete-list',
        AC_CREATE:        '#mmi-taxmap-autocomplete-create',

        RULES_BTN:        '#mmi-taxmap-rules-btn',
        RULES_PANEL:      '#mmi-taxmap-rules-panel',
        RULES_BADGE:      '#mmi-taxmap-rules-badge',
        RULES_TBODY:      '#mmi-taxmap-rules-tbody',
        RULES_ADD_BTN:    '#mmi-taxmap-rules-add-btn',
        RULES_SAVE_BTN:   '#mmi-taxmap-rules-save-btn',
        RULE_TERM_INPUT:  '.mmi-rule-term-input',

        TABLE_WRAP:       '#mmi-taxmap-table-wrap',
        RESIZE_HANDLE:    '.mmi-taxmap-th-resize',

        SUPPLIER_SUMMARY: '#mmi-taxmap-supplier-summary',

        PROFILE_OPTIONS_TEMPLATE: '#mmi-taxmap-profile-options-template',

        VARIANT_ROW:              '.mmi-taxmap-variant-row',
        VARIANT_TOGGLE:           '.mmi-taxmap-variant-toggle',
        VARIANT_PROFILE_SELECT:   '.mmi-taxmap-variant-profile-select',
        VARIANT_TERM_INPUT:       '.mmi-taxmap-variant-term-input',
        VARIANT_DELETE_BTN:       '.mmi-taxmap-variant-delete-btn',

        VARIATIONS_BTN:           '#mmi-taxmap-variations-btn',
        VARIATIONS_PANEL:         '#mmi-taxmap-variations-panel',
        VARIATIONS_BADGE:         '#mmi-taxmap-variations-badge',
        VARIATIONS_TBODY:         '#mmi-taxmap-variations-tbody',
        VARIATIONS_TABLE:         '#mmi-taxmap-variations-table',
        VARIATIONS_EMPTY:         '#mmi-taxmap-variations-empty',
        VARIATIONS_PROFILE_FILTER:'#mmi-taxmap-variations-profile-filter',
        VARIATIONS_WIPE_BTN:      '#mmi-taxmap-variations-wipe-btn',
        VARIATIONS_DELETE_SEL_BTN:'#mmi-taxmap-variations-delete-selected-btn',
        VARIATIONS_SEL_COUNT:     '#mmi-taxmap-variations-selected-count',
        VARIATIONS_REFRESH_BTN:   '#mmi-taxmap-variations-refresh-btn',
        VARIATIONS_SELECT_ALL:    '#mmi-taxmap-variations-select-all',
        VARIATIONS_ROW_CB:        '.mmi-taxmap-variations-row-cb',
        VARIATIONS_ROW_DELETE_BTN:'.mmi-taxmap-variations-row-delete-btn',
    };

    /**
     * Every mapping saved via the main term-search input is Global — it
     * applies to every Import Profile. There is no ambient "editing scope"
     * on this page (see the 2026-08-30 Incident History entry, "Taxonomy
     * Mapping Table Hardcoded profile_id === ''...", and the redesign entry
     * that followed it): a profile-specific override is always an explicit,
     * per-value action taken from that row's own "+ Add variation" control
     * (see buildVariantRow()), never a page-wide mode.
     *
     * @return string '' always — kept as a named default so a change here
     *   only needs to happen in one place.
     */
    function defaultMappingProfile() {
        return '';
    }

    /**
     * Which profile a row's "+ Add variation" picker should default to — a
     * convenience default only, never a page-wide scope (Taxonomy Mapping's
     * underlying data stays Global-by-default with explicit variations
     * regardless of this value; see the 2026-08-30 redesign entry in
     * AGENTS.md). Since the 2026-08-31 merge into Review & Compare, this
     * section lives inside the same profile-aware container Field Mapping
     * does, so it prefers the page's LIVE active-profile selector
     * (#mmi-import-profile, switched via the Import Profile cards with no
     * reload — see import-settings.js's loadProfile()) over the one-time
     * ?profile= deep-link (set by a specific profile's Field Mapping "Alias
     * mapping" link) this used exclusively before the merge — reading it
     * fresh on every call rather than caching means it stays correct across
     * any number of in-page profile switches with no extra event wiring.
     * Falls back to the static deep-link attribute only when the live
     * selector isn't present at all (shouldn't happen post-merge, but keeps
     * this function safe if it's ever rendered somewhere that selector
     * doesn't exist).
     */
    function deepLinkProfile() {
        const $live = $( '#mmi-import-profile' );
        if ( $live.length && $live.val() ) {
            return $live.val();
        }
        return $( SELECTORS.PROFILE_OPTIONS_TEMPLATE ).attr( 'data-deep-link' ) || '';
    }

    /**
     * Clones the server-rendered profile <option> list into a fresh <select>,
     * optionally excluding profiles that already have a variant for this row
     * (no reason to offer creating a second one — edit or delete the existing
     * one instead). One shared template (rendered once in section-taxonomy-mapping.php)
     * is the single owner of "what profiles exist," matching this project's own
     * one-owner-per-concern convention rather than a second, drifting copy here.
     *
     * @param {string[]} excludeProfileIds
     * @return {jQuery}
     */
    function buildProfilePicker( excludeProfileIds ) {
        const $select = $( '<select class="mmi-taxmap-variant-profile-select">' );
        $( SELECTORS.PROFILE_OPTIONS_TEMPLATE + ' option' ).each( function () {
            const val = $( this ).val();
            if ( excludeProfileIds && excludeProfileIds.indexOf( val ) !== -1 ) { return; }
            $select.append( $( this ).clone() );
        } );
        const deepLink = deepLinkProfile();
        if ( deepLink && ! ( excludeProfileIds && excludeProfileIds.indexOf( deepLink ) !== -1 ) ) {
            $select.val( deepLink );
        }
        return $select;
    }

    /** localStorage key for persisted column widths — see initColumnResize(). */
    const COLUMN_WIDTH_STORAGE_KEY = 'mmi_taxmap_column_widths';

    /** Operators offered on each alias rule row. */
    const RULE_OPERATORS = [
        { value: 'contains',    label: 'Contains' },
        { value: 'starts_with', label: 'Starts with' },
        { value: 'ends_with',   label: 'Ends with' },
        { value: 'equals',      label: 'Equals (exact)' },
        { value: 'regex',       label: 'Regex (advanced)' },
    ];

    /**
     * Fixed colour palette for the per-taxonomy chip (col-target, rule rows, the
     * autocomplete header). Deliberately distinct from the status-badge colours
     * (green=mapped, red=unmapped, blue=skip) so the two indicators never collide.
     * A taxonomy slug always hashes to the same entry, so "Brand" is always the
     * same colour everywhere on the page without hardcoding specific slugs.
     */
    const TAXONOMY_CHIP_PALETTE = [
        { bg: '#ede7f6', text: '#4527a0' },
        { bg: '#e0f2f1', text: '#00695c' },
        { bg: '#fff3e0', text: '#e65100' },
        { bg: '#fce4ec', text: '#ad1457' },
        { bg: '#e8eaf6', text: '#283593' },
        { bg: '#efebe9', text: '#4e342e' },
    ];

    /* ── Runtime state ──────────────────────────────────────────────────── */

    /**
     * Config used only by the Advanced / Custom Mapping panel.
     * @type {{ supplier: string, sourceField: string, wcTaxonomy: string } | null}
     */
    let customConfig = null;

    /** Full scanned rows (all values from all sources) */
    let allRows = [];

    /** Active source filter: { supplier: string, sourceField: string } — both '' means show all */
    let activeFilter = { supplier: '', sourceField: '' };

    /** Active status filter, driven by clicking the Total/Mapped/Unmapped stat — 'all' | 'mapped' | 'unmapped' */
    let statusFilter = 'all';

    /** Current sort state: { col: string, dir: 'asc'|'desc' } */
    let sortState = { col: 'count', dir: 'desc' };

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

    /** Currently active autocomplete input element */
    let $activeInput = null;

    /** jQuery reference to the row the autocomplete is attached to */
    let $activeInputRow = null;

    /** Set instead of $activeInputRow when the autocomplete was opened from an alias rule row. */
    let $activeRuleRow = null;

    /** Timer for autocomplete debounce */
    let acTimer = null;

    /** Debounce timer for live rule match-count recompute. */
    let ruleMatchTimer = null;

    /* ── Init ───────────────────────────────────────────────────────────── */

    /** Whether the initial scan has fired yet this page load. */
    let hasLoadedOnce = false;

    /**
     * Fires the initial feed scan exactly once. This markup's home has moved
     * several times: originally a collapsible sub-panel inside the Import
     * tab, then a standalone tab, then a section nested inside Review &
     * Compare (2026-08-30), then a compact toggle button there instead
     * (2026-08-31), then relocated again into the Supplier Data Sources
     * toolbar once Taxonomy Mapping's real ownership (per data source, not
     * per import profile) was settled — see AGENTS.md's Incident History
     * for the full reasoning at each step.
     */
    window.MMITaxMapping = {
        ensureLoaded: function () {
            if ( hasLoadedOnce ) { return $.Deferred().resolve().promise(); }
            hasLoadedOnce = true;
            return scanAllSources();
        },

        /**
         * Open the panel (if closed), ensure its data is loaded, then filter
         * the table down to just this one supplier's values — the mechanism
         * behind each supplier row's "N mapped" quick-link in
         * pipeline-step-1-acquisition.php's mmi-taxmap-toggle-cell. Reuses
         * the exact same activeFilter/applyTableFilter() a preset pill click
         * already drives (see bindFilterPills() below), with sourceField
         * left empty so it matches every taxonomy field for this supplier,
         * not just one preset pill's specific field.
         */
        openForSupplier: function ( supplierId, supplierName ) {
            const $toggle = $( '#mmi-taxonomy-mapping-toggle' );
            const $panel  = $( '#mmi-taxonomy-mapping-section' );

            const applyFilter = () => {
                $( SELECTORS.PILL_BTN ).removeClass( 'is-active' );
                activeFilter = { supplier: supplierId, sourceField: '' };
                applyTableFilter();
                updateSupplierScopedBanner( supplierId, supplierName || supplierId );
                $panel[ 0 ]?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
            };

            const loaded = window.MMITaxMapping.ensureLoaded();
            if ( ! $panel.is( ':visible' ) ) {
                $panel.slideDown( 200, function () {
                    $toggle.addClass( 'is-active' );
                    loaded.always( applyFilter );
                } );
            } else {
                loaded.always( applyFilter );
            }
        }
    };

    /**
     * Toggle button + panel, mirroring import-pipeline-stock-overrides.js's
     * togglePanel() exactly (slideToggle the panel, mirror its visibility
     * onto the button's 'is-active' class, fire the first-expand load).
     * Deliberately does NOT join the Schedules/Stock Overrides/Catalog Rules
     * "only one topbar panel open at a time" group — this button lives
     * inside Review & Compare, a different context, not that topbar's own
     * mutually-exclusive tab set.
     */
    function bindPanelToggle() {
        $( document ).on( 'click', '#mmi-taxonomy-mapping-toggle', function () {
            const $btn   = $( this );
            const $panel = $( '#mmi-taxonomy-mapping-section' );
            $panel.slideToggle( 200, function () {
                const isOpen = $panel.is( ':visible' );
                $btn.toggleClass( 'is-active', isOpen );
                if ( isOpen ) {
                    window.MMITaxMapping.ensureLoaded();
                }
            } );
        } );
    }

    /**
     * Deep-link support for Field Mapping's "Alias mapping" link
     * (panel-field-mapping.php) and the old standalone tab's bookmarked URLs
     * (redirected by main.php) — ?open_taxonomy=1 opens the panel and
     * scrolls it into view, mirroring product-import.js's existing
     * ?open_schedule=1 pattern exactly.
     */
    function maybeOpenFromUrl() {
        if ( new URLSearchParams( window.location.search ).get( 'open_taxonomy' ) !== '1' ) {
            return;
        }
        const $panel = $( '#mmi-taxonomy-mapping-section' );
        $panel.show();
        $( '#mmi-taxonomy-mapping-toggle' ).addClass( 'is-active' );
        window.MMITaxMapping.ensureLoaded();
        $panel[ 0 ]?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
    }

    $( document ).ready( function () {
        bindFilterPills();
        bindCustomButton();
        bindScanButton();
        bindRefreshButton();
        bindApplyButton();
        bindStatFilters();
        bindSearchInput();
        bindSortHeaders();
        bindGlobalDocumentHandlers();
        bindVariantEvents();

        bindRulesButton();
        bindRulesToolbar();
        bindRuleRowEvents();
        renderInitialRuleRows();

        bindVariationsPanel();
        bindPanelToggle();

        applyStoredColumnWidths();
        initColumnResize();

        if ( $( SELECTORS.APP ).is( ':visible' ) ) {
            window.MMITaxMapping.ensureLoaded();
        }
        maybeOpenFromUrl();
    } );

    /* ── Filter pill bindings ───────────────────────────────────────────── */

    function bindFilterPills() {
        $( document ).on( 'click', SELECTORS.PILL_BTN, function () {
            const $btn = $( this );
            $( SELECTORS.PILL_BTN ).removeClass( 'is-active' );
            $btn.addClass( 'is-active' );

            activeFilter = {
                supplier:    $btn.data( 'filter-supplier' ) ?? '',
                sourceField: $btn.data( 'filter-field' )    ?? '',
            };
            applyTableFilter();
        } );
    }

    function bindCustomButton() {
        $( document ).on( 'click', SELECTORS.CUSTOM_BTN, function () {
            $( SELECTORS.CONFIG_PANEL ).toggleClass( 'mmi-hidden' );
        } );
    }

    function bindRulesButton() {
        $( document ).on( 'click', SELECTORS.RULES_BTN, function () {
            $( SELECTORS.RULES_PANEL ).toggleClass( 'mmi-hidden' );
        } );
    }

    /* ── Manage Variations panel ──────────────────────────────────────────
     * Bulk review/removal of every saved profile-specific override, flat —
     * the per-row "+ Add profile variation" control (buildVariantRow()) is
     * still where a new one is created; this panel is for everything else:
     * reviewing what's already saved, deleting several at once, or wiping
     * an entire profile's overrides in one action. */

    let variationsLoaded = false;

    function bindVariationsPanel() {
        $( document ).on( 'click', SELECTORS.VARIATIONS_BTN, function () {
            $( SELECTORS.VARIATIONS_PANEL ).toggleClass( 'mmi-hidden' );
            if ( ! variationsLoaded && ! $( SELECTORS.VARIATIONS_PANEL ).hasClass( 'mmi-hidden' ) ) {
                loadVariations();
            }
        } );

        $( document ).on( 'click', SELECTORS.VARIATIONS_REFRESH_BTN, function () {
            loadVariations();
        } );

        $( document ).on( 'change', SELECTORS.VARIATIONS_PROFILE_FILTER, function () {
            renderVariationsTable();
            $( SELECTORS.VARIATIONS_WIPE_BTN ).prop( 'disabled', ! $( this ).val() );
        } );

        $( document ).on( 'change', SELECTORS.VARIATIONS_SELECT_ALL, function () {
            $( SELECTORS.VARIATIONS_TBODY + ' ' + SELECTORS.VARIATIONS_ROW_CB + ':visible' )
                .prop( 'checked', $( this ).prop( 'checked' ) );
            updateVariationsSelectedCount();
        } );

        $( document ).on( 'change', SELECTORS.VARIATIONS_ROW_CB, function () {
            updateVariationsSelectedCount();
        } );

        $( document ).on( 'click', SELECTORS.VARIATIONS_ROW_DELETE_BTN, function () {
            const mappingId = parseInt( $( this ).closest( 'tr' ).attr( 'data-mapping-id' ), 10 );
            if ( ! mappingId ) { return; }
            bulkDeleteVariations( { mapping_ids: [ mappingId ] } );
        } );

        $( document ).on( 'click', SELECTORS.VARIATIONS_DELETE_SEL_BTN, function () {
            const ids = getSelectedVariationIds();
            if ( ! ids.length ) { return; }
            if ( ! confirm( `Delete ${ ids.length } selected variation${ ids.length === 1 ? '' : 's' }? This can't be undone.` ) ) { return; }
            bulkDeleteVariations( { mapping_ids: ids } );
        } );

        $( document ).on( 'click', SELECTORS.VARIATIONS_WIPE_BTN, function () {
            const profileId = $( SELECTORS.VARIATIONS_PROFILE_FILTER ).val();
            const profileLabel = $( SELECTORS.VARIATIONS_PROFILE_FILTER + ' option:selected' ).text();
            if ( ! profileId ) { return; }
            if ( ! confirm( `Delete EVERY variation saved for "${ profileLabel }"? This removes all of that profile's overrides at once and can't be undone.` ) ) { return; }
            bulkDeleteVariations( { wipe_profile_id: profileId } );
        } );
    }

    function getSelectedVariationIds() {
        return $( SELECTORS.VARIATIONS_TBODY + ' ' + SELECTORS.VARIATIONS_ROW_CB + ':checked' )
            .map( function () { return parseInt( $( this ).closest( 'tr' ).attr( 'data-mapping-id' ), 10 ); } )
            .get();
    }

    function updateVariationsSelectedCount() {
        const count = getSelectedVariationIds().length;
        $( SELECTORS.VARIATIONS_SEL_COUNT ).text( count );
        $( SELECTORS.VARIATIONS_DELETE_SEL_BTN ).toggleClass( 'mmi-hidden', count === 0 );
    }

    let allVariations = [];

    function loadVariations() {
        $( SELECTORS.VARIATIONS_TBODY ).html( '<tr><td colspan="7"><span class="mmi-loading"></span> Loading…</td></tr>' );

        $.post( AJAX_URL, {
            action: 'mmi_get_taxonomy_variations',
            nonce:  NONCE,
        } )
        .done( function ( resp ) {
            if ( ! resp.success ) {
                showNotice( 'Could not load variations: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }
            variationsLoaded  = true;
            allVariations     = resp.data.variations || [];

            const $filter = $( SELECTORS.VARIATIONS_PROFILE_FILTER );
            const currentVal = $filter.val();
            $filter.find( 'option:not(:first)' ).remove();
            Object.keys( resp.data.profiles || {} ).forEach( function ( profileId ) {
                $filter.append( $( '<option>' ).val( profileId ).text( resp.data.profiles[ profileId ] ) );
            } );
            if ( currentVal && $filter.find( `option[value="${ currentVal }"]` ).length ) {
                $filter.val( currentVal );
            }
            $( SELECTORS.VARIATIONS_WIPE_BTN ).prop( 'disabled', ! $filter.val() );

            renderVariationsTable();
        } )
        .fail( function ( xhr ) {
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    function renderVariationsTable() {
        const $tbody       = $( SELECTORS.VARIATIONS_TBODY );
        const profileFilter = $( SELECTORS.VARIATIONS_PROFILE_FILTER ).val();
        const rows = profileFilter
            ? allVariations.filter( v => v.profile_id === profileFilter )
            : allVariations;

        $tbody.empty();
        $( SELECTORS.VARIATIONS_SELECT_ALL ).prop( 'checked', false );
        updateVariationsSelectedCount();

        $( SELECTORS.VARIATIONS_EMPTY ).toggleClass( 'mmi-hidden', rows.length > 0 );
        $( SELECTORS.VARIATIONS_TABLE ).toggleClass( 'mmi-hidden', rows.length === 0 );

        rows.forEach( function ( v ) {
            const termLabel = v.wc_term_id === -1 ? '(skip)' : ( v.wc_term_name || '—' );
            const $tr = $( '<tr>' ).attr( 'data-mapping-id', v.mapping_id );
            $tr.append( $( '<td class="col-select">' ).append( '<input type="checkbox" class="mmi-taxmap-variations-row-cb">' ) );
            $tr.append( $( '<td class="col-value">' ).text( v.source_value ) );
            $tr.append( $( '<td class="col-supplier">' ).text( v.supplier_id || '— any —' ) );
            $tr.append( $( '<td class="col-taxonomy">' ).text( v.wc_taxonomy_label ) );
            $tr.append( $( '<td class="col-profile">' ).text( v.profile_label ) );
            $tr.append( $( '<td class="col-term">' ).text( termLabel ) );
            $tr.append(
                $( '<td class="col-actions">' ).append(
                    $( '<button class="button mmi-action-btn mmi-action-btn--danger mmi-button-small mmi-taxmap-variations-row-delete-btn" title="Delete this variation">' )
                        .html( '<span class="dashicons dashicons-no-alt"></span>' )
                )
            );
            $tbody.append( $tr );
        } );

        updateVariationsBadge();
    }

    function updateVariationsBadge() {
        const $badge = $( SELECTORS.VARIATIONS_BADGE );
        if ( allVariations.length > 0 ) {
            $badge.text( allVariations.length ).removeClass( 'mmi-hidden' );
        } else {
            $badge.addClass( 'mmi-hidden' );
        }
    }

    /**
     * @param {{mapping_ids?: number[], wipe_profile_id?: string}} payload
     */
    function bulkDeleteVariations( payload ) {
        $.post( AJAX_URL, Object.assign( {
            action: 'mmi_bulk_delete_taxonomy_variations',
            nonce:  NONCE,
        }, payload ) )
        .done( function ( resp ) {
            if ( ! resp.success ) {
                showNotice( 'Delete failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }
            showNotice( resp.data.message || 'Deleted.', 'success' );
            loadVariations();
            // Keep the main table's per-row "N profile variations" badges
            // accurate too — cheap, since it reuses the raw-scan cache and
            // only re-resolves mapping status, not a full feed re-read.
            scanAllSources();
        } )
        .fail( function ( xhr ) {
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    function bindScanButton() {
        $( document ).on( 'click', SELECTORS.SCAN_BTN, function () {
            const sf = $( SELECTORS.SOURCE_FIELD ).val().trim();
            if ( ! sf ) {
                showNotice( 'Please enter a source field name.', 'error' );
                return;
            }
            customConfig = {
                supplier:    $( SELECTORS.SUPPLIER ).val(),
                sourceField: sf,
                wcTaxonomy:  $( SELECTORS.WC_TAXONOMY ).val(),
            };
            scanCustomSource( customConfig );
        } );
    }

    function bindRefreshButton() {
        $( document ).on( 'click', SELECTORS.REFRESH_BTN, function () {
            // Explicit user-initiated reload always bypasses the server-side cache.
            scanAllSources( true );
        } );
    }

    function bindApplyButton() {
        $( document ).on( 'click', SELECTORS.APPLY_BTN, function () {
            if ( ! confirm(
                'Apply all saved taxonomy mappings to existing WooCommerce products?\n\n' +
                'This assigns the mapped terms to every product with a matching source value. ' +
                'Safe to run multiple times.'
            ) ) { return; }
            runBatchApplyAll();
        } );
    }

    /** Total/Mapped/Unmapped stat counters double as the status filter — click to toggle. */
    function bindStatFilters() {
        $( document ).on( 'click', SELECTORS.STAT_FILTER_BTN, function () {
            const $btn = $( this );
            statusFilter = $btn.data( 'status-filter' ) || 'all';
            $( SELECTORS.STAT_FILTER_BTN ).removeClass( 'is-active' );
            $btn.addClass( 'is-active' );
            applyTableFilter();
        } );
    }

    function bindSearchInput() {
        $( document ).on( 'input', SELECTORS.SEARCH_INPUT, debounce( function () {
            applyTableFilter();
        }, 200 ) );
    }

    function bindSortHeaders() {
        $( document ).on( 'click', SELECTORS.TABLE + ' thead th.sortable', function () {
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
                sortState.dir = col === 'count' ? 'desc' : 'asc';
            }
            updateSortIcons();
            sortAndRerender();
        } );
    }

    function updateSortIcons() {
        $( SELECTORS.TABLE + ' thead th.sortable' ).each( function () {
            const $th    = $( this );
            const col    = $th.data( 'col' );
            const $icon  = $th.find( '.sort-icon' );
            const active = col === sortState.col;
            $th.toggleClass( 'sort-active', active );
            $icon.text( active ? ( sortState.dir === 'asc' ? ' ↑' : ' ↓' ) : '' );
        } );
    }

    /* ── Column resize ──────────────────────────────────────────────────── */

    /**
     * Apply any widths the user previously dragged, restoring them by the
     * stable data-resize-col key (not column position, so a future column
     * reorder wouldn't silently apply the wrong saved width to the wrong column).
     */
    function applyStoredColumnWidths() {
        let stored;
        try {
            stored = JSON.parse( localStorage.getItem( COLUMN_WIDTH_STORAGE_KEY ) );
        } catch ( e ) { /* ignore — localStorage unavailable or corrupt value */ }
        if ( ! stored || typeof stored !== 'object' ) {
            return;
        }
        Object.keys( stored ).forEach( function ( key ) {
            const width = parseInt( stored[ key ], 10 );
            if ( ! width || width < 40 ) {
                return;
            }
            $( SELECTORS.TABLE ).find( 'th[data-resize-col="' + key + '"]' ).css( 'width', width + 'px' );
        } );
    }

    /**
     * Drag-to-resize for table columns. Resizing a <th> in a table-layout:fixed
     * table resizes the whole column for every row automatically — no need to
     * touch individual <td> elements.
     */
    function initColumnResize() {
        let $resizingTh   = null;
        let resizeStartX  = 0;
        let resizeStartW  = 0;

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
            if ( ! $resizingTh ) {
                return;
            }
            const newWidth = Math.max( 40, resizeStartW + ( e.pageX - resizeStartX ) );
            $resizingTh.css( 'width', newWidth + 'px' );
        } );

        $( document ).on( 'mouseup', function () {
            if ( ! $resizingTh ) {
                return;
            }
            const key = $resizingTh.data( 'resize-col' );
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

    /**
     * Sort allRows according to sortState, then re-render visible rows
     * while preserving the current filter state.
     */
    function sortAndRerender() {
        const { col, dir } = sortState;
        const sorted = [ ...allRows ].sort( ( a, b ) => {
            let va, vb;
            if ( col === 'count' ) {
                va = a.count;
                vb = b.count;
            } else if ( col === 'status' ) {
                // order: unmapped < skip < mapped
                const rank = r => r.wc_term_id === 0 ? 0 : r.wc_term_id === -1 ? 1 : 2;
                va = rank( a );
                vb = rank( b );
            } else if ( col === 'source_file' ) {
                va = ( a.source_file || '' ).toLowerCase();
                vb = ( b.source_file || '' ).toLowerCase();
            } else {
                // source_value (default)
                va = ( a.source_value || '' ).toLowerCase();
                vb = ( b.source_value || '' ).toLowerCase();
            }
            if ( va < vb ) { return dir === 'asc' ? -1 :  1; }
            if ( va > vb ) { return dir === 'asc' ?  1 : -1; }
            return 0;
        } );

        // Re-render (renderTable replaces all rows; filter reapplied after)
        renderTable( sorted );
        applyTableFilter();
        recomputeAllRuleMatchCounts();
    }

    /** Refresh every alias rule row's live "Matches" count against the current allRows. */
    function recomputeAllRuleMatchCounts() {
        $( SELECTORS.RULES_TBODY + ' tr' ).each( function () {
            computeRuleMatchCount( $( this ) );
        } );
    }

    /* ── Alias rules ───────────────────────────────────────────────────────
     * A lightweight rules tier between exact-value mapping and "unmapped":
     * one rule (e.g. source value contains "roland") catches every supplier
     * spelling instead of mapping each one by hand. Row order in the table
     * IS priority order — first matching rule wins, both here and on the
     * PHP side (MMI_DB::match_taxmap_alias_rule()), which this mirrors.
     * ────────────────────────────────────────────────────────────────────── */

    function ruleValueMatches( operator, ruleValue, sourceValue ) {
        if ( ! ruleValue ) { return false; }

        if ( operator === 'regex' ) {
            try {
                const m  = ruleValue.match( /^\/(.*)\/([a-z]*)$/i );
                const re = m ? new RegExp( m[ 1 ], m[ 2 ] ) : new RegExp( ruleValue );
                return re.test( sourceValue );
            } catch ( e ) {
                return false;
            }
        }

        const hay    = sourceValue.toLowerCase();
        const needle = ruleValue.toLowerCase();

        switch ( operator ) {
            case 'equals':      return hay === needle;
            case 'starts_with': return hay.startsWith( needle );
            case 'ends_with':   return hay.endsWith( needle );
            case 'contains':
            default:            return hay.includes( needle );
        }
    }

    function readRuleRow( $tr ) {
        const $term = $tr.find( SELECTORS.RULE_TERM_INPUT );
        return {
            label:        $tr.find( '.mmi-rule-label' ).val().trim(),
            supplier_id:  $tr.find( '.mmi-rule-supplier' ).val(),
            source_field: $tr.find( '.mmi-rule-field' ).val().trim(),
            operator:     $tr.find( '.mmi-rule-operator' ).val(),
            value:        $tr.find( '.mmi-rule-value' ).val().trim(),
            wc_taxonomy:  $tr.find( '.mmi-rule-taxonomy' ).val(),
            wc_term_id:   parseInt( $term.attr( 'data-term-id' ), 10 ) || 0,
            wc_term_name: $term.val(),
        };
    }

    function computeRuleMatchCount( $tr ) {
        const rule = readRuleRow( $tr );
        const $out = $tr.find( '.mmi-rule-match-count' );

        if ( ! rule.value || ! rule.wc_taxonomy ) {
            $out.text( '—' );
            return;
        }

        let total = 0;
        let unmapped = 0;
        allRows.forEach( row => {
            if ( row.wc_taxonomy !== rule.wc_taxonomy ) { return; }
            if ( rule.supplier_id && row.supplier !== rule.supplier_id ) { return; }
            if ( rule.source_field && row.source_field !== rule.source_field ) { return; }
            if ( ! ruleValueMatches( rule.operator, rule.value, row.source_value ) ) { return; }
            total++;
            if ( row.wc_term_id === 0 ) { unmapped++; }
        } );

        $out.text( total === 0 ? '0 matches' : `${ total } match${ total === 1 ? '' : 'es' } (${ unmapped } unmapped)` );
    }

    function updateRulesBadge() {
        const count = $( SELECTORS.RULES_TBODY ).find( 'tr' ).length;
        $( SELECTORS.RULES_BADGE ).text( count ).toggleClass( 'mmi-hidden', count === 0 );
    }

    /** Build a <tr> for one alias rule, cloning supplier/taxonomy <option>s from the existing selects. */
    function buildRuleRow( rule ) {
        rule = rule || {};

        const $tr = $( '<tr class="mmi-taxmap-rule-row">' );

        $tr.append( $( '<td class="col-order">' ).html(
            '<button type="button" class="mmi-rule-move-up" title="Move up">▲</button>' +
            '<button type="button" class="mmi-rule-move-down" title="Move down">▼</button>'
        ) );

        $tr.append( $( '<td class="col-label">' ).append(
            $( '<input type="text" class="mmi-rule-label" placeholder="e.g. Roland family">' ).val( rule.label || '' )
        ) );

        const $supplier = $( '<select class="mmi-rule-supplier">' )
            .html( $( SELECTORS.SUPPLIER ).html() );
        $supplier.val( rule.supplier_id || '' );
        $tr.append( $( '<td class="col-supplier">' ).append( $supplier ) );

        $tr.append( $( '<td class="col-field">' ).append(
            $( '<input type="text" class="mmi-rule-field" placeholder="any field">' ).val( rule.source_field || '' )
        ) );

        const $operator = $( '<select class="mmi-rule-operator">' );
        RULE_OPERATORS.forEach( op => {
            $operator.append( $( '<option>' ).attr( 'value', op.value ).text( op.label ) );
        } );
        $operator.val( rule.operator || 'contains' );
        $tr.append( $( '<td class="col-operator">' ).append( $operator ) );

        $tr.append( $( '<td class="col-value">' ).append(
            $( '<input type="text" class="mmi-rule-value" placeholder="roland">' ).val( rule.value || '' )
        ) );

        const $taxonomy = $( '<select class="mmi-rule-taxonomy">' )
            .html( $( SELECTORS.WC_TAXONOMY ).html() );
        $taxonomy.val( rule.wc_taxonomy || $taxonomy.find( 'option' ).first().val() );
        $tr.append( $( '<td class="col-taxonomy">' ).append( $taxonomy ) );

        const termVal = rule.wc_term_id === -1 ? '(skipped)' : ( rule.wc_term_name || '' );
        const $term   = $( '<input type="text" class="mmi-taxmap-term-input mmi-rule-term-input" placeholder="Type to search terms…">' )
            .val( termVal )
            .attr( 'data-term-id', rule.wc_term_id || 0 );
        const $termTd = $( '<td class="col-term">' )
            .append( `<span class="mmi-taxmap-taxonomy-chip mmi-rule-taxonomy-chip"></span>` )
            .append( $term );
        $tr.append( $termTd );
        updateRuleTaxonomyChip( $tr );

        $tr.append( $( '<td class="col-matches">' ).html( '<span class="mmi-rule-match-count">—</span>' ) );

        $tr.append( $( '<td class="col-actions">' ).append(
            $( '<button type="button" class="button mmi-button-small mmi-rule-delete-btn" title="Delete rule">' )
                .html( '<span class="dashicons dashicons-no-alt"></span>' )
        ) );

        return $tr;
    }

    function renderInitialRuleRows() {
        const rules = window.mmiTaxMapping?.aliasRules || [];
        rules.forEach( rule => {
            $( SELECTORS.RULES_TBODY ).append( buildRuleRow( rule ) );
        } );
        updateRulesBadge();
    }

    function bindRulesToolbar() {
        $( document ).on( 'click', SELECTORS.RULES_ADD_BTN, function () {
            const $tr = buildRuleRow( {} );
            $( SELECTORS.RULES_TBODY ).append( $tr );
            updateRulesBadge();
            $tr.find( '.mmi-rule-label' ).trigger( 'focus' );
        } );

        $( document ).on( 'click', SELECTORS.RULES_SAVE_BTN, function () {
            saveAliasRules();
        } );

        $( document ).on( 'click', SELECTORS.RULES_TBODY + ' .mmi-rule-delete-btn', function () {
            $( this ).closest( 'tr' ).remove();
            updateRulesBadge();
        } );

        $( document ).on( 'click', SELECTORS.RULES_TBODY + ' .mmi-rule-move-up', function () {
            const $tr = $( this ).closest( 'tr' );
            const $prev = $tr.prev();
            if ( $prev.length ) { $tr.insertBefore( $prev ); }
        } );

        $( document ).on( 'click', SELECTORS.RULES_TBODY + ' .mmi-rule-move-down', function () {
            const $tr = $( this ).closest( 'tr' );
            const $next = $tr.next();
            if ( $next.length ) { $tr.insertAfter( $next ); }
        } );

        $( document ).on(
            'input change',
            SELECTORS.RULES_TBODY + ' .mmi-rule-supplier, ' +
            SELECTORS.RULES_TBODY + ' .mmi-rule-field, ' +
            SELECTORS.RULES_TBODY + ' .mmi-rule-operator, ' +
            SELECTORS.RULES_TBODY + ' .mmi-rule-value, ' +
            SELECTORS.RULES_TBODY + ' .mmi-rule-taxonomy',
            function () {
                const $tr = $( this ).closest( 'tr' );
                if ( $( this ).hasClass( 'mmi-rule-taxonomy' ) ) {
                    updateRuleTaxonomyChip( $tr );
                }
                clearTimeout( ruleMatchTimer );
                ruleMatchTimer = setTimeout( () => computeRuleMatchCount( $tr ), 200 );
            }
        );
    }

    function saveAliasRules() {
        const rules = $( SELECTORS.RULES_TBODY + ' tr' ).map( function () {
            return readRuleRow( $( this ) );
        } ).get();

        $( SELECTORS.RULES_SAVE_BTN ).prop( 'disabled', true );

        $.post( AJAX_URL, {
            action: 'mmi_save_taxonomy_alias_rules',
            nonce:  NONCE,
            rules:  JSON.stringify( rules ),
        } )
        .done( function ( resp ) {
            $( SELECTORS.RULES_SAVE_BTN ).prop( 'disabled', false );
            if ( ! resp.success ) {
                showNotice( 'Save failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }

            $( SELECTORS.RULES_TBODY ).empty();
            ( resp.data.rules || [] ).forEach( rule => {
                $( SELECTORS.RULES_TBODY ).append( buildRuleRow( rule ) );
            } );
            updateRulesBadge();
            showNotice( 'Alias rules saved', 'success' );

            // Re-scan so the main table reflects values now covered by rules.
            scanAllSources( true );
        } )
        .fail( function ( xhr ) {
            $( SELECTORS.RULES_SAVE_BTN ).prop( 'disabled', false );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    /** Term-search autocomplete for alias rule rows — shares the #mmi-taxmap-autocomplete
     *  dropdown with the main table, but tracked via $activeRuleRow instead of
     *  $activeInputRow so selection updates the rule row, not a saved mapping. */
    function bindRuleRowEvents() {
        $( document ).on( 'focus', SELECTORS.RULES_TBODY + ' ' + SELECTORS.RULE_TERM_INPUT, function () {
            $activeInputRow = null;
            $activeRuleRow  = $( this ).closest( 'tr' );
            $activeInput    = $( this );
            triggerRuleAutocomplete( $( this ) );
        } );

        $( document ).on( 'input', SELECTORS.RULES_TBODY + ' ' + SELECTORS.RULE_TERM_INPUT, function () {
            $activeInputRow = null;
            $activeRuleRow  = $( this ).closest( 'tr' );
            $activeInput    = $( this );
            clearTimeout( acTimer );
            acTimer = setTimeout( () => triggerRuleAutocomplete( $( this ) ), 250 );
        } );
    }

    function triggerRuleAutocomplete( $input ) {
        const $tr      = $input.closest( 'tr' );
        const taxonomy = $tr.find( '.mmi-rule-taxonomy' ).val();
        const query    = $input.val();

        $.post( AJAX_URL, {
            action:   'mmi_search_wc_terms',
            nonce:    NONCE,
            taxonomy: taxonomy,
            search:   query,
        } )
        .done( function ( resp ) {
            if ( $activeInput && $activeInput.is( $input ) ) {
                renderAutocomplete( resp.data?.terms || [], query, $input, taxonomy );
            }
        } );
    }

    /* ── Scan all sources (unified auto-load) ───────────────────────────── */

    function scanAllSources( forceRefresh ) {
        hideNotice();
        showLoading( true );
        $( SELECTORS.TABLE ).addClass( 'mmi-hidden' );
        $( SELECTORS.EMPTY ).addClass( 'mmi-hidden' );
        $( SELECTORS.STATS ).addClass( 'mmi-hidden' );

        return $.post( AJAX_URL, {
            action: 'mmi_scan_all_sources',
            nonce:  NONCE,
            force_refresh: forceRefresh ? 1 : 0,
        } )
        .done( function ( resp ) {
            showLoading( false );
            if ( ! resp.success ) {
                showNotice( 'Scan failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                $( SELECTORS.EMPTY ).removeClass( 'mmi-hidden' );
                return;
            }

            allRows = resp.data.values || [];
            updateStats( resp.data.total, resp.data.mapped );
            updateSortIcons();
            sortAndRerender();
        } )
        .fail( function ( xhr ) {
            showLoading( false );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    /**
     * Scan a custom source field and merge new rows into allRows.
     * Rows from the same (supplier + source_field) are replaced to avoid duplicates.
     */
    function scanCustomSource( cfg ) {
        showLoading( true );

        $.post( AJAX_URL, {
            action:       'mmi_get_taxonomy_source_values',
            nonce:        NONCE,
            supplier:     cfg.supplier,
            source_field: cfg.sourceField,
            wc_taxonomy:  cfg.wcTaxonomy,
        } )
        .done( function ( resp ) {
            showLoading( false );
            if ( ! resp.success ) {
                showNotice( 'Scan failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }

            // Attach source_field / wc_taxonomy / supplier to each row returned
            const newRows = ( resp.data.values || [] ).map( r => ( {
                ...r,
                source_field: cfg.sourceField,
                wc_taxonomy:  cfg.wcTaxonomy,
                supplier:     cfg.supplier,
            } ) );

            // Remove existing rows for this supplier + source_field combination
            allRows = allRows.filter(
                r => ! ( r.supplier === cfg.supplier && r.source_field === cfg.sourceField )
            );
            allRows = allRows.concat( newRows );

            updateStats( allRows.length, allRows.filter( r => r.wc_term_id !== 0 ).length );
            updateSortIcons();
            sortAndRerender();
            showNotice( `Added ${ newRows.length } values from ${ cfg.sourceField }`, 'success' );
        } )
        .fail( function ( xhr ) {
            showLoading( false );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    /* ── Table rendering ────────────────────────────────────────────────── */

    function renderTable( rows ) {
        const $tbody = $( SELECTORS.TBODY );
        $tbody.empty();

        if ( ! rows || rows.length === 0 ) {
            $( SELECTORS.EMPTY ).removeClass( 'mmi-hidden' );
            $( SELECTORS.TABLE ).addClass( 'mmi-hidden' );
            return;
        }

        $.each( rows, function ( _, row ) {
            $tbody.append( buildRow( row ) );
            $tbody.append( buildVariantRow( row ) );
        } );

        $( SELECTORS.EMPTY ).addClass( 'mmi-hidden' );
        $( SELECTORS.TABLE ).removeClass( 'mmi-hidden' );
        $( SELECTORS.STATS ).removeClass( 'mmi-hidden' );
    }

    /**
     * Build a single table row for a source value.
     *
     * @param {{ source_value: string, source_field: string, wc_taxonomy: string, supplier: string,
     *            count: number, mapping_id: number, wc_term_id: number, wc_term_name: string,
     *            auto_create: boolean, source_file: string, sample_products: string[] }} row
     * @returns {jQuery}
     */
    function buildRow( row ) {
        const isMapped  = row.wc_term_id !== 0;
        const isSkipped = row.wc_term_id === -1;
        const viaRule   = !! row.via_rule;

        const statusClass = isSkipped ? 'status-skip'
            : isMapped ? 'status-mapped' : 'status-unmapped';
        const statusLabel = isSkipped ? 'Skip'
            : isMapped ? ( viaRule ? 'Mapped (rule)' : 'Mapped' ) : 'Unmapped';

        const termInputVal = isSkipped ? '' : ( row.wc_term_name || '' );

        const $tr = $( '<tr>' )
            .addClass( 'mmi-taxmap-row' )
            .addClass( isSkipped ? 'is-skipped' : isMapped ? 'is-mapped' : 'is-unmapped' )
            .attr( 'data-source-value', row.source_value )
            .attr( 'data-source-field', row.source_field  || '' )
            .attr( 'data-wc-taxonomy',  row.wc_taxonomy   || '' )
            .attr( 'data-supplier',     row.supplier      || '' )
            .attr( 'data-mapping-id',   row.mapping_id || 0 )
            .attr( 'data-wc-term-id',   row.wc_term_id || 0 )
            .attr( 'data-sample-products', ( row.sample_products || [] ).join( '\n' ) );

        // Source value
        $tr.append(
            $( '<td class="col-source">' ).text( row.source_value )
        );

        // Source context: filename chip + field tag
        const fieldLabel   = escHtml( row.source_field  || '' );
        const fileChipHtml = row.source_file
            ? `<span class="mmi-taxmap-file-chip">${ escHtml( row.source_file ) }</span>`
            : '';
        const fieldTagHtml = fieldLabel
            ? `<span class="mmi-taxmap-field-tag">${ fieldLabel }</span>`
            : '';
        $tr.append(
            $( '<td class="col-source-file">' ).html( fileChipHtml + fieldTagHtml )
        );

        // Product count badge
        $tr.append(
            $( '<td class="col-count">' ).html(
                `<span class="mmi-taxmap-count">${ row.count }</span>`
            )
        );

        // Sample products — show up to 3 names as small stacked lines
        const samples = row.sample_products || [];
        const $samplesCell = $( '<td class="col-samples">' );
        if ( samples.length ) {
            const $list = $( '<ul class="mmi-taxmap-samples">' );
            samples.forEach( name => {
                $list.append( $( '<li>' ).text( name ) );
            } );
            $samplesCell.append( $list );
        }
        $tr.append( $samplesCell );

        $tr.append( '<td class="col-arrow"><span class="dashicons dashicons-arrow-right-alt"></span></td>' );

        // Term input cell — the taxonomy select lets the user redirect a misclassified
        // value (e.g. a category name that the fixed supplier->taxonomy scan profile
        // happened to put in the Brand column) to the correct taxonomy before picking
        // a term. Color-coded the same way the old static chip was, just interactive.
        const $taxonomySelect = $( '<select class="mmi-taxmap-taxonomy-select">' )
            .html( $( SELECTORS.WC_TAXONOMY ).html() )
            .val( row.wc_taxonomy || '' );
        applyTaxonomySelectColor( $taxonomySelect, row.wc_taxonomy );
        const $termCell  = $( '<td class="col-target">' ).append( $taxonomySelect );
        const $termInput = $( '<input type="text" class="mmi-taxmap-term-input">' )
            .attr( 'placeholder', isSkipped ? '(skipped)' : 'Type to search terms…' )
            .val( termInputVal )
            .attr( 'disabled', isSkipped );
        $termCell.append( $termInput );

        // Profile-specific overrides for this value are opt-in and per-value —
        // see buildVariantRow() and the 2026-08-30 redesign entry in
        // AGENTS.md's Incident History for why this replaced a page-wide
        // "editing scope" selector. The toggle always shows a count (0 reads
        // as a plain "+ Add variation" invite) so a value with real per-profile
        // differences is visibly distinct from one that's the same everywhere.
        const variantCount = ( row.variants || [] ).length;
        $termCell.append(
            $( '<button type="button" class="mmi-taxmap-variant-toggle">' )
                .toggleClass( 'has-variants', variantCount > 0 )
                .html(
                    variantCount > 0
                        ? `<span class="dashicons dashicons-randomize"></span> ${ variantCount } profile variation${ variantCount === 1 ? '' : 's' }`
                        : '<span class="dashicons dashicons-plus-alt2"></span> Add profile variation'
                )
        );
        $tr.append( $termCell );

        // Status cell
        $tr.append(
            $( `<td class="col-status"><span class="mmi-taxmap-status ${ statusClass }${ viaRule ? ' status-via-rule' : '' }">${ statusLabel }</span></td>` )
        );

        // Actions cell — via-rule rows have no flat mapping row to skip/clear here;
        // edit the rule itself in the Alias Rules panel, or type a different term
        // above to save an explicit override (which takes priority over the rule).
        const $actions = $( '<td class="col-actions">' );
        if ( ! isMapped && ! isSkipped && ! viaRule ) {
            $actions.append( createTermButtonHtml() );
        }
        if ( ! isSkipped && ! viaRule ) {
            $actions.append(
                $( '<button class="button mmi-action-btn mmi-button-small mmi-taxmap-skip-btn" title="Skip this value — do not map">' )
                    .html( '<span class="dashicons dashicons-minus"></span>' )
            );
        }
        if ( ( isMapped || isSkipped ) && ! viaRule ) {
            $actions.append(
                $( '<button class="button mmi-action-btn mmi-action-btn--danger mmi-button-small mmi-taxmap-clear-btn" title="Clear mapping">' )
                    .html( '<span class="dashicons dashicons-no-alt"></span>' )
            );
        }
        $tr.append( $actions );

        return $tr;
    }

    /**
     * Builds the collapsed-by-default sibling row holding one value's
     * profile-specific overrides — its existing variants (each editable via
     * the same autocomplete the main mapping uses, deletable individually)
     * plus a picker for adding a new one. Mirrors the Field Mapping panel's
     * own pattern the user asked for: pick a scope (there, a data source;
     * here, a profile), then map into it — rather than a page-wide mode.
     *
     * @param {object} row - Same shape buildRow() receives; only .variants,
     *   .source_value/.source_field/.supplier/.wc_taxonomy are read here.
     * @returns {jQuery}
     */
    function buildVariantRow( row ) {
        const $tr = $( '<tr>' )
            .addClass( 'mmi-taxmap-variant-row mmi-hidden' )
            .attr( 'data-source-value', row.source_value )
            .attr( 'data-source-field', row.source_field || '' )
            .attr( 'data-wc-taxonomy',  row.wc_taxonomy  || '' )
            .attr( 'data-supplier',     row.supplier     || '' );

        const $td = $( '<td colspan="8">' );
        const $panel = $( '<div class="mmi-taxmap-variant-panel">' );
        $panel.append( $( '<ul class="mmi-taxmap-variant-list">' ) );

        const excludeIds = ( row.variants || [] ).map( v => v.profile_id );
        const $addRow = $( '<div class="mmi-taxmap-variant-add">' );
        $addRow.append( buildProfilePicker( excludeIds ) );
        $addRow.append(
            $( '<input type="text" class="mmi-taxmap-term-input mmi-taxmap-variant-term-input" placeholder="Type to search terms…">' )
        );
        $panel.append( $addRow );

        $td.append( $panel );
        $tr.append( $td );

        renderVariantList( $tr, row.variants || [] );

        return $tr;
    }

    /**
     * (Re)renders just the <ul> of existing variants inside a variant row —
     * called on initial build and after every add/delete, so the row never
     * needs a full table re-render for a single value's override list.
     *
     * @param {jQuery} $variantTr
     * @param {object[]} variants
     */
    function renderVariantList( $variantTr, variants ) {
        const $list = $variantTr.find( '.mmi-taxmap-variant-list' );
        $list.empty();

        if ( ! variants.length ) {
            $list.append( $( '<li class="mmi-taxmap-variant-empty">' ).text( 'No profile-specific overrides yet — this value resolves the same way for every profile.' ) );
            return;
        }

        variants.forEach( function ( v ) {
            const termLabel = v.wc_term_id === -1 ? '(skip)' : ( v.wc_term_name || '—' );
            const $li = $( '<li class="mmi-taxmap-variant-item">' )
                .attr( 'data-mapping-id', v.mapping_id );
            $li.append( $( '<span class="mmi-taxmap-variant-profile">' ).text( v.profile_label ) );
            $li.append( $( '<span class="dashicons dashicons-arrow-right-alt2">' ) );
            $li.append( $( '<span class="mmi-taxmap-variant-term">' ).text( termLabel ) );
            $li.append(
                $( '<button type="button" class="mmi-taxmap-variant-delete-btn" title="Remove this override">' )
                    .html( '<span class="dashicons dashicons-no-alt"></span>' )
            );
            $list.append( $li );
        } );
    }

    /**
     * Reads the row's current variants back out of allRows (kept in sync by
     * addOrUpdateVariantEntry()/removeVariantEntry() below) and re-renders
     * both the variant list and the main row's toggle badge/count — the two
     * places a variant count is displayed.
     *
     * @param {jQuery} $mainTr - The value's main (non-variant) row.
     */
    function refreshVariantDisplay( $mainTr ) {
        const entry = allRows.find( r =>
            r.supplier     === $mainTr.attr( 'data-supplier' )     &&
            r.source_field === $mainTr.attr( 'data-source-field' ) &&
            r.source_value === $mainTr.attr( 'data-source-value' )
        );
        const variants = entry ? ( entry.variants || [] ) : [];

        const $variantTr = $mainTr.next( '.mmi-taxmap-variant-row' );
        renderVariantList( $variantTr, variants );

        const excludeIds = variants.map( v => v.profile_id );
        $variantTr.find( '.mmi-taxmap-variant-add' )
            .empty()
            .append( buildProfilePicker( excludeIds ) )
            .append( '<input type="text" class="mmi-taxmap-term-input mmi-taxmap-variant-term-input" placeholder="Type to search terms…">' );

        const $toggle = $mainTr.find( '.mmi-taxmap-variant-toggle' );
        $toggle.toggleClass( 'has-variants', variants.length > 0 );
        $toggle.html(
            variants.length > 0
                ? `<span class="dashicons dashicons-randomize"></span> ${ variants.length } profile variation${ variants.length === 1 ? '' : 's' }`
                : '<span class="dashicons dashicons-plus-alt2"></span> Add profile variation'
        );
    }

    /* ── Table filter ───────────────────────────────────────────────────── */

    function applyTableFilter() {
        const searchText = ( $( SELECTORS.SEARCH_INPUT ).val() || '' ).toLowerCase().trim();

        $( SELECTORS.TBODY + ' tr' ).each( function () {
            const $tr         = $( this );
            const srcValue    = ( $tr.attr( 'data-source-value' )    || '' ).toLowerCase();
            const sampleNames = ( $tr.attr( 'data-sample-products' ) || '' ).toLowerCase();
            const isMapped    = $tr.hasClass( 'is-mapped' );
            const isSkipped   = $tr.hasClass( 'is-skipped' );
            const isUnmapped  = $tr.hasClass( 'is-unmapped' );

            // Status filter — driven by clicking the Total/Mapped/Unmapped stat counter
            let showByFilter = true;
            if ( statusFilter === 'mapped' )   { showByFilter = isMapped || isSkipped; }
            if ( statusFilter === 'unmapped' ) { showByFilter = isUnmapped;             }

            // Source pill filter
            let showByPill = true;
            if ( activeFilter.supplier || activeFilter.sourceField ) {
                const rowSupplier = ( $tr.attr( 'data-supplier' )     || '' );
                const rowField    = ( $tr.attr( 'data-source-field' ) || '' );
                showByPill = (
                    ( ! activeFilter.supplier    || rowSupplier === activeFilter.supplier    ) &&
                    ( ! activeFilter.sourceField || rowField    === activeFilter.sourceField )
                );
            }

            // Text search (source value + sample product names)
            const showBySearch = ! searchText
                || srcValue.includes( searchText )
                || sampleNames.includes( searchText );

            $tr.toggle( showByFilter && showByPill && showBySearch );
        } );
    }

    /* ── Row event delegation ───────────────────────────────────────────── */

    $( document ).on( 'focus', SELECTORS.TBODY + ' .mmi-taxmap-term-input', function () {
        $activeInput    = $( this );
        $activeInputRow = $( this ).closest( 'tr' );
        triggerAutocomplete( $( this ), $( this ).val() );
    } );

    $( document ).on( 'input', SELECTORS.TBODY + ' .mmi-taxmap-term-input', function () {
        $activeInput    = $( this );
        $activeInputRow = $( this ).closest( 'tr' );
        clearTimeout( acTimer );
        acTimer = setTimeout( () => triggerAutocomplete( $( this ), $( this ).val() ), 250 );
    } );

    $( document ).on( 'keydown', SELECTORS.TBODY + ' .mmi-taxmap-term-input', function ( e ) {
        const $list = $( SELECTORS.AC_LIST );
        const $items = $list.find( 'li:visible' );
        if ( $items.length === 0 ) { return; }

        const $active = $items.filter( '.ac-active' );

        if ( e.key === 'ArrowDown' ) {
            e.preventDefault();
            const $next = $active.length ? $active.removeClass( 'ac-active' ).next() : $items.first();
            $next.addClass( 'ac-active' );
        } else if ( e.key === 'ArrowUp' ) {
            e.preventDefault();
            const $prev = $active.length ? $active.removeClass( 'ac-active' ).prev() : $items.last();
            $prev.addClass( 'ac-active' );
        } else if ( e.key === 'Enter' ) {
            e.preventDefault();
            if ( $active.length ) {
                $active.trigger( 'click' );
            }
        } else if ( e.key === 'Escape' ) {
            hideAutocomplete();
        }
    } );

    /**
     * Redirect a row to a different taxonomy — e.g. the fixed supplier->taxonomy
     * scan profile put a category name (like "effects") in the Brand scan, and
     * the user needs to send it to Product Categories instead. Any mapping
     * already saved for this row was saved against the OLD taxonomy and is no
     * longer valid for the new one, so it's deleted (not left behind as a
     * stale, silently-still-active mapping) and the row resets to unmapped so
     * the user picks a fresh term under the new taxonomy.
     */
    $( document ).on( 'change', SELECTORS.TBODY + ' .mmi-taxmap-taxonomy-select', function () {
        const $select      = $( this );
        const $tr          = $select.closest( 'tr' );
        const newTaxonomy  = $select.val();
        const oldMappingId = parseInt( $tr.attr( 'data-mapping-id' ), 10 );

        applyTaxonomySelectColor( $select, newTaxonomy );
        $tr.attr( 'data-wc-taxonomy', newTaxonomy );
        updateAllRowsEntry(
            $tr.attr( 'data-supplier' ),
            $tr.attr( 'data-source-field' ),
            $tr.attr( 'data-source-value' ),
            0, '', newTaxonomy
        );

        if ( ! oldMappingId ) {
            resetRowToUnmapped( $tr );
            return;
        }

        $tr.addClass( 'is-saving' );
        $.post( AJAX_URL, {
            action:     'mmi_delete_taxonomy_mapping',
            nonce:      NONCE,
            mapping_id: oldMappingId,
        } ).always( function () {
            $tr.removeClass( 'is-saving' );
            resetRowToUnmapped( $tr );
        } );
    } );

    $( document ).on( 'click', SELECTORS.TBODY + ' .mmi-taxmap-create-btn', function () {
        const $tr         = $( this ).closest( 'tr' );
        const sourceValue = $tr.attr( 'data-source-value' );
        // term_id=0 + a non-empty term_name makes mmi_save_taxonomy_mapping find-or-create
        // server-side — same path the autocomplete's "Create term" option already uses.
        $tr.find( '.mmi-taxmap-term-input' ).val( sourceValue );
        saveMapping( $tr, 0, sourceValue, false );
    } );

    $( document ).on( 'click', SELECTORS.TBODY + ' .mmi-taxmap-skip-btn', function () {
        const $tr = $( this ).closest( 'tr' );
        saveMapping( $tr, -1, '__skip__', true );
    } );

    $( document ).on( 'click', SELECTORS.TBODY + ' .mmi-taxmap-clear-btn', function () {
        const $tr = $( this ).closest( 'tr' );
        clearMapping( $tr );
    } );

    /* ── Autocomplete ───────────────────────────────────────────────────── */

    function triggerAutocomplete( $input, query ) {
        // Read the WC taxonomy from the row, fall back to customConfig
        const $tr     = $input.closest( 'tr' );
        const taxonomy = $tr.attr( 'data-wc-taxonomy' ) || ( customConfig?.wcTaxonomy ) || 'product_brand';

        $.post( AJAX_URL, {
            action:   'mmi_search_wc_terms',
            nonce:    NONCE,
            taxonomy: taxonomy,
            search:   query,
        } )
        .done( function ( resp ) {
            if ( $activeInput && $activeInput.is( $input ) ) {
                renderAutocomplete( resp.data?.terms || [], query, $input, taxonomy );
            }
        } );
    }

    function renderAutocomplete( terms, query, $input, taxonomy ) {
        const $ac     = $( SELECTORS.AUTOCOMPLETE );
        const $list   = $( SELECTORS.AC_LIST );
        const $crOpt  = $( SELECTORS.AC_CREATE );
        const $header = $( SELECTORS.AC_HEADER );

        if ( taxonomy ) {
            const color = taxonomyChipColor( taxonomy );
            $header.text( 'Searching: ' + taxonomyLabel( taxonomy ) )
                .attr( 'style', `--chip-bg:${ color.bg }; --chip-text:${ color.text };` )
                .removeClass( 'mmi-hidden' );
        } else {
            $header.addClass( 'mmi-hidden' );
        }

        $list.empty();

        if ( terms.length ) {
            $.each( terms, function ( _, term ) {
                const $li = $( '<li class="ac-item">' )
                    .html(
                        `<span class="ac-name">${ escHtml( term.name ) }</span>` +
                        `<span class="ac-count">${ term.count }</span>`
                    )
                    .attr( 'data-term-id', term.id )
                    .attr( 'data-term-name', term.name );
                $list.append( $li );
            } );
        }

        // "Create term" option — only when query is at least 2 chars and no exact match
        const exactMatch = terms.find( t => t.name.toLowerCase() === query.toLowerCase() );
        if ( query.length >= 2 && ! exactMatch ) {
            $crOpt.find( '.create-term-name' ).text( query );
            $crOpt.removeClass( 'mmi-hidden' ).attr( 'data-create-name', query );
        } else {
            $crOpt.addClass( 'mmi-hidden' ).removeAttr( 'data-create-name' );
        }

        if ( ! terms.length && query.length < 2 ) {
            $ac.addClass( 'mmi-hidden' );
            return;
        }

        // Position dropdown under the input. #mmi-taxmap-autocomplete is `position: fixed`
        // (viewport-relative), so this MUST use getBoundingClientRect(), not .offset()
        // (document-relative) — using .offset() here rendered the dropdown off-screen
        // any time the page was scrolled, which looked exactly like "no results".
        const rect = $input[ 0 ].getBoundingClientRect();
        $ac.css( {
            top:   rect.bottom + 4,
            left:  rect.left,
            width: Math.max( $input.outerWidth(), 220 ),
        } ).removeClass( 'mmi-hidden' );
    }

    $( document ).on( 'click', '#mmi-taxmap-autocomplete-list .ac-item', function () {
        const termId   = parseInt( $( this ).data( 'term-id' ), 10 );
        const termName = $( this ).data( 'term-name' );

        if ( $activeInput && $activeInput.hasClass( 'mmi-taxmap-variant-term-input' ) ) {
            saveVariantFromActiveInput( termId, termName );
        } else if ( $activeRuleRow ) {
            $activeRuleRow.find( SELECTORS.RULE_TERM_INPUT ).val( termName ).attr( 'data-term-id', termId );
            computeRuleMatchCount( $activeRuleRow );
        } else if ( $activeInput ) {
            $activeInput.val( termName );
            const $tr = $activeInputRow || $activeInput.closest( 'tr' );
            saveMapping( $tr, termId, termName, false );
        }
        hideAutocomplete();
    } );

    $( document ).on( 'click', '#mmi-taxmap-autocomplete-create .mmi-taxmap-create-term-btn', function () {
        const termName = $( SELECTORS.AC_CREATE ).attr( 'data-create-name' );
        if ( ! termName ) { hideAutocomplete(); return; }

        if ( $activeInput && $activeInput.hasClass( 'mmi-taxmap-variant-term-input' ) ) {
            saveVariantFromActiveInput( 0, termName ); // term_id=0 triggers create-or-find in controller
        } else if ( $activeRuleRow ) {
            const taxonomy = $activeRuleRow.find( '.mmi-rule-taxonomy' ).val();
            createWcTermForRule( $activeRuleRow, taxonomy, termName );
        } else if ( $activeInput ) {
            $activeInput.val( termName );
            const $tr = $activeInputRow || $activeInput.closest( 'tr' );
            saveMapping( $tr, 0, termName, false ); // term_id=0 triggers create-or-find in controller
        }
        hideAutocomplete();
    } );

    /**
     * Resolves the profile picker + main row for whichever variant-add input
     * is currently focused, then hands off to saveVariantMapping(). Shared by
     * both autocomplete-commit handlers above (picking an existing term, or
     * confirming "Create term: X").
     *
     * @param {number} termId
     * @param {string} termName
     */
    function saveVariantFromActiveInput( termId, termName ) {
        const $variantTr = $activeInput.closest( SELECTORS.VARIANT_ROW );
        const profileId  = $variantTr.find( SELECTORS.VARIANT_PROFILE_SELECT ).val() || '';
        const $mainTr    = $variantTr.prev( '.mmi-taxmap-row' );

        if ( ! profileId ) {
            showNotice( 'Choose which profile this variation is for first.', 'error' );
            return;
        }

        $activeInput.val( termName );
        saveVariantMapping( $mainTr, profileId, termId, termName );
    }

    /** Create-or-find a WC term for an alias rule row (no flat mapping row is written). */
    function createWcTermForRule( $tr, taxonomy, termName ) {
        $.post( AJAX_URL, {
            action:    'mmi_create_wc_term',
            nonce:     NONCE,
            taxonomy:  taxonomy,
            term_name: termName,
        } )
        .done( function ( resp ) {
            if ( ! resp.success ) {
                showNotice( 'Could not create term: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }
            $tr.find( SELECTORS.RULE_TERM_INPUT )
                .val( resp.data.wc_term_name )
                .attr( 'data-term-id', resp.data.wc_term_id );
            computeRuleMatchCount( $tr );
        } )
        .fail( function ( xhr ) {
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    function hideAutocomplete() {
        $( SELECTORS.AUTOCOMPLETE ).addClass( 'mmi-hidden' );
        $activeInput    = null;
        $activeInputRow = null;
        $activeRuleRow  = null;
    }

    function bindGlobalDocumentHandlers() {
        $( document ).on( 'click', function ( e ) {
            if (
                ! $( e.target ).closest( SELECTORS.AUTOCOMPLETE ).length &&
                ! $( e.target ).closest( SELECTORS.TBODY + ' .mmi-taxmap-term-input' ).length
            ) {
                hideAutocomplete();
            }
        } );
    }

    /* ── Save / Clear mapping ───────────────────────────────────────────── */

    /**
     * Persist a mapping for the given row.
     * Config (supplier / sourceField / wcTaxonomy) is read from the row's data attributes.
     *
     * @param {jQuery} $tr         - The table row
     * @param {number} termId      - WC term ID (0=create/find by name, -1=skip)
     * @param {string} termName    - WC term name
     * @param {boolean} isSkip     - Whether this is a "skip" action
     */
    function saveMapping( $tr, termId, termName, isSkip ) {
        const sourceValue = $tr.attr( 'data-source-value' );
        const supplier    = $tr.attr( 'data-supplier' )     || '';
        const sourceField = $tr.attr( 'data-source-field' ) || '';
        const wcTaxonomy  = $tr.attr( 'data-wc-taxonomy' )  || '';

        $tr.addClass( 'is-saving' );

        $.post( AJAX_URL, {
            action:        'mmi_save_taxonomy_mapping',
            nonce:         NONCE,
            supplier_id:   supplier,
            source_field:  sourceField,
            source_value:  sourceValue,
            wc_taxonomy:   wcTaxonomy,
            wc_term_id:    termId,
            term_name:     termName,
            auto_create:   0,
            profile_id:    defaultMappingProfile(),
        } )
        .done( function ( resp ) {
            $tr.removeClass( 'is-saving' );
            if ( ! resp.success ) {
                showNotice( 'Save failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }

            const savedTermId   = resp.data.wc_term_id;
            const savedTermName = resp.data.wc_term_name;

            // Update row state. data-mapping-id previously only ever got set
            // from the initial server render — clicking "Clear" on a row
            // mapped THIS session (never reloaded) saw a stale 0 and silently
            // skipped the actual delete call, leaving an orphaned DB row a
            // future reload would show as already-mapped. resp.data.mapping_id
            // (added alongside the profile-variant work) closes that gap.
            $tr.attr( 'data-mapping-id', resp.data.mapping_id || 0 );
            $tr.attr( 'data-wc-term-id', savedTermId );
            $tr.removeClass( 'is-mapped is-unmapped is-skipped' );

            if ( isSkip || savedTermId === -1 ) {
                $tr.addClass( 'is-skipped' );
                $tr.find( '.mmi-taxmap-term-input' ).val( '' ).prop( 'disabled', true );
                $tr.find( '.col-status .mmi-taxmap-status' )
                   .removeClass( 'status-mapped status-unmapped status-skip' )
                   .addClass( 'status-skip' ).text( 'Skip' );
            } else if ( savedTermId > 0 ) {
                $tr.addClass( 'is-mapped' );
                $tr.find( '.mmi-taxmap-term-input' ).val( savedTermName ).prop( 'disabled', false );
                $tr.find( '.col-status .mmi-taxmap-status' )
                   .removeClass( 'status-mapped status-unmapped status-skip' )
                   .addClass( 'status-mapped' ).text( 'Mapped' );
            }

            // Update skip/clear buttons in actions cell
            rebuildActionButtons( $tr );

            // Update row in allRows array
            updateAllRowsEntry( supplier, sourceField, sourceValue, savedTermId, savedTermName );
            refreshStats();
        } )
        .fail( function ( xhr ) {
            $tr.removeClass( 'is-saving' );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    function clearMapping( $tr ) {
        const mappingId = parseInt( $tr.attr( 'data-mapping-id' ), 10 );
        if ( ! mappingId ) {
            resetRowToUnmapped( $tr );
            return;
        }

        $tr.addClass( 'is-saving' );
        $.post( AJAX_URL, {
            action:     'mmi_delete_taxonomy_mapping',
            nonce:      NONCE,
            mapping_id: mappingId,
        } )
        .done( function ( resp ) {
            $tr.removeClass( 'is-saving' );
            if ( resp.success ) {
                resetRowToUnmapped( $tr );
                updateAllRowsEntry(
                    $tr.attr( 'data-supplier' ),
                    $tr.attr( 'data-source-field' ),
                    $tr.attr( 'data-source-value' ),
                    0, ''
                );
                refreshStats();
            } else {
                showNotice( 'Delete failed: ' + ( resp.data?.message || '' ), 'error' );
            }
        } )
        .fail( function ( xhr ) {
            $tr.removeClass( 'is-saving' );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    function resetRowToUnmapped( $tr ) {
        $tr.attr( 'data-mapping-id', 0 ).attr( 'data-wc-term-id', 0 );
        $tr.removeClass( 'is-mapped is-skipped' ).addClass( 'is-unmapped' );
        $tr.find( '.mmi-taxmap-term-input' ).val( '' ).prop( 'disabled', false );
        $tr.find( '.col-status .mmi-taxmap-status' )
           .removeClass( 'status-mapped status-skip' )
           .addClass( 'status-unmapped' ).text( 'Unmapped' );
        rebuildActionButtons( $tr );
    }

    /* ── Profile-specific variations ───────────────────────────────────────
     * The explicit, per-value counterpart to saveMapping()'s always-Global
     * save — see the "+ Add profile variation" control built in
     * buildVariantRow(). Never touches the main row's mapped/unmapped state;
     * only the variant panel and its toggle badge. */

    /**
     * Saves one profile-specific override for a value. $mainTr identifies
     * WHICH value (via its data-* attributes, same as saveMapping()); the
     * profile scope comes from the caller, not any page-wide state.
     *
     * @param {jQuery} $mainTr   - The value's main (non-variant) row.
     * @param {string} profileId
     * @param {number} termId
     * @param {string} termName
     */
    function saveVariantMapping( $mainTr, profileId, termId, termName ) {
        const sourceValue = $mainTr.attr( 'data-source-value' );
        const supplier    = $mainTr.attr( 'data-supplier' )     || '';
        const sourceField = $mainTr.attr( 'data-source-field' ) || '';
        const wcTaxonomy  = $mainTr.attr( 'data-wc-taxonomy' )  || '';

        const $variantTr = $mainTr.next( '.mmi-taxmap-variant-row' );
        $variantTr.addClass( 'is-saving' );

        $.post( AJAX_URL, {
            action:        'mmi_save_taxonomy_mapping',
            nonce:         NONCE,
            supplier_id:   supplier,
            source_field:  sourceField,
            source_value:  sourceValue,
            wc_taxonomy:   wcTaxonomy,
            wc_term_id:    termId,
            term_name:     termName,
            auto_create:   0,
            profile_id:    profileId,
        } )
        .done( function ( resp ) {
            $variantTr.removeClass( 'is-saving' );
            if ( ! resp.success ) {
                showNotice( 'Save failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }

            const profileLabel = $( SELECTORS.PROFILE_OPTIONS_TEMPLATE + ` option[value="${ profileId }"]` ).text() || profileId;

            addOrUpdateVariantEntry( supplier, sourceField, sourceValue, {
                mapping_id:    resp.data.mapping_id,
                profile_id:    profileId,
                profile_label: profileLabel,
                wc_term_id:    resp.data.wc_term_id,
                wc_term_name:  resp.data.wc_term_name,
            } );
            refreshVariantDisplay( $mainTr );
        } )
        .fail( function ( xhr ) {
            $variantTr.removeClass( 'is-saving' );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    /** Deletes one profile-specific override by its own mapping row id. */
    function deleteVariantMapping( $mainTr, mappingId ) {
        const $variantTr = $mainTr.next( '.mmi-taxmap-variant-row' );
        $variantTr.addClass( 'is-saving' );

        $.post( AJAX_URL, {
            action:     'mmi_delete_taxonomy_mapping',
            nonce:      NONCE,
            mapping_id: mappingId,
        } )
        .done( function ( resp ) {
            $variantTr.removeClass( 'is-saving' );
            if ( ! resp.success ) {
                showNotice( 'Delete failed: ' + ( resp.data?.message || 'Unknown error' ), 'error' );
                return;
            }
            removeVariantEntry(
                $mainTr.attr( 'data-supplier' ),
                $mainTr.attr( 'data-source-field' ),
                $mainTr.attr( 'data-source-value' ),
                mappingId
            );
            refreshVariantDisplay( $mainTr );
        } )
        .fail( function ( xhr ) {
            $variantTr.removeClass( 'is-saving' );
            showNotice( 'AJAX error: ' + xhr.statusText, 'error' );
        } );
    }

    /** Adds or replaces (by profile_id) one variant entry in allRows. */
    function addOrUpdateVariantEntry( supplier, sourceField, sourceValue, variant ) {
        const entry = allRows.find( r =>
            r.supplier     === supplier    &&
            r.source_field === sourceField &&
            r.source_value === sourceValue
        );
        if ( ! entry ) { return; }
        entry.variants = entry.variants || [];
        const existingIdx = entry.variants.findIndex( v => v.profile_id === variant.profile_id );
        if ( existingIdx !== -1 ) {
            entry.variants[ existingIdx ] = variant;
        } else {
            entry.variants.push( variant );
        }
    }

    /** Removes one variant entry (by mapping id) from allRows. */
    function removeVariantEntry( supplier, sourceField, sourceValue, mappingId ) {
        const entry = allRows.find( r =>
            r.supplier     === supplier    &&
            r.source_field === sourceField &&
            r.source_value === sourceValue
        );
        if ( ! entry || ! entry.variants ) { return; }
        entry.variants = entry.variants.filter( v => v.mapping_id !== mappingId );
    }

    /** Toggle expand/collapse + delete-one-variant delegated handlers. */
    function bindVariantEvents() {
        $( document ).on( 'click', SELECTORS.VARIANT_TOGGLE, function () {
            $( this ).closest( '.mmi-taxmap-row' ).next( SELECTORS.VARIANT_ROW ).toggleClass( 'mmi-hidden' );
        } );

        $( document ).on( 'click', SELECTORS.VARIANT_DELETE_BTN, function () {
            const $li       = $( this ).closest( '.mmi-taxmap-variant-item' );
            const mappingId = parseInt( $li.attr( 'data-mapping-id' ), 10 );
            const $mainTr   = $( this ).closest( SELECTORS.VARIANT_ROW ).prev( '.mmi-taxmap-row' );
            if ( mappingId ) {
                deleteVariantMapping( $mainTr, mappingId );
            }
        } );
    }

    function rebuildActionButtons( $tr ) {
        const isMapped  = $tr.hasClass( 'is-mapped' );
        const isSkipped = $tr.hasClass( 'is-skipped' );
        const $cell     = $tr.find( '.col-actions' ).empty();

        if ( ! isMapped && ! isSkipped ) {
            $cell.append( createTermButtonHtml() );
        }
        if ( ! isSkipped ) {
            $cell.append(
                $( '<button class="button mmi-action-btn mmi-button-small mmi-taxmap-skip-btn" title="Skip this value">' )
                    .html( '<span class="dashicons dashicons-minus"></span>' )
            );
        }
        if ( isMapped || isSkipped ) {
            $cell.append(
                $( '<button class="button mmi-action-btn mmi-action-btn--danger mmi-button-small mmi-taxmap-clear-btn" title="Clear mapping">' )
                    .html( '<span class="dashicons dashicons-no-alt"></span>' )
            );
        }
    }

    /**
     * "Create term" button — one click creates a new WC term named exactly as the
     * source value (under the row's currently selected taxonomy) and maps it,
     * skipping the search-and-select step entirely. Added because most new brand
     * values need a term that's identical to the source text — typing it into the
     * search box and confirming "Create term: X" from the autocomplete dropdown
     * for every single one was unnecessary friction for the common case.
     */
    function createTermButtonHtml() {
        return $( '<button class="button mmi-action-btn mmi-button-small mmi-taxmap-create-btn" title="Create a term matching this source value and map it">' )
            .html( '<span class="dashicons dashicons-plus-alt2"></span>' );
    }

    /* ── Batch apply ALL mappings to existing products ──────────────────── */

    function runBatchApplyAll() {
        $( SELECTORS.APPLY_BTN ).prop( 'disabled', true );
        $( SELECTORS.PROGRESS ).removeClass( 'mmi-hidden' );
        setProgress( 0, 'Applying all mappings to existing products…' );

        $.post( AJAX_URL, {
            action: 'mmi_apply_all_taxonomy_mappings',
            nonce:  NONCE,
        } )
        .done( function ( resp ) {
            $( SELECTORS.APPLY_BTN ).prop( 'disabled', false );

            if ( ! resp.success ) {
                setProgress( 0, 'Error: ' + ( resp.data?.message || 'Unknown error' ) );
                showNotice( resp.data?.message || 'Apply failed', 'error' );
                return;
            }

            setProgress( 100, resp.data.message );
            showNotice( resp.data.message, 'success' );
            setTimeout( () => $( SELECTORS.PROGRESS ).addClass( 'mmi-hidden' ), 3000 );
        } )
        .fail( function ( xhr ) {
            $( SELECTORS.APPLY_BTN ).prop( 'disabled', false );
            setProgress( 0, 'AJAX error: ' + xhr.statusText );
            showNotice( 'AJAX error during apply', 'error' );
        } );
    }

    /* ── Stats ──────────────────────────────────────────────────────────── */

    function updateStats( total, mapped ) {
        $( SELECTORS.TOTAL ).text( total );
        $( SELECTORS.MAPPED_COUNT ).text( mapped );
        $( SELECTORS.UNMAPPED_COUNT ).text( total - mapped );
    }

    function refreshStats() {
        const total   = allRows.length;
        const mapped  = allRows.filter( r => r.wc_term_id !== 0 ).length;
        updateStats( total, mapped );
    }

    /**
     * The one thing that WAS missing a real link back to a specific data
     * source: this table (and the "Viewing:"/"For {profile}" banner above
     * it) has always covered every supplier's values in one place, scoped
     * only by Import Profile — there was never a per-supplier-row summary,
     * which is what made the banner look wrong regardless of which row was
     * selected in the Supplier Data Sources table. Called by
     * MMITaxMapping.openForSupplier() once the panel is open and the table
     * filtered down to one supplier; computes real per-taxonomy alias counts
     * from the already-loaded allRows instead of a fresh AJAX call.
     */
    function updateSupplierScopedBanner( supplierId, supplierName ) {
        const $el = $( SELECTORS.SUPPLIER_SUMMARY );
        if ( ! $el.length ) { return; }
        if ( ! supplierId ) {
            $el.addClass( 'mmi-hidden' ).empty();
            return;
        }
        const bySupplier = allRows.filter( r => r.supplier === supplierId );
        const byTaxonomy = {};
        bySupplier.forEach( r => {
            if ( r.wc_term_id === 0 ) { return; }
            byTaxonomy[ r.wc_taxonomy ] = ( byTaxonomy[ r.wc_taxonomy ] || 0 ) + 1;
        } );
        const taxParts = Object.keys( byTaxonomy )
            .map( slug => `${ escHtml( taxonomyLabel( slug ) ) } (${ byTaxonomy[ slug ] } aliased)` );

        const safeName = $( '<div>' ).text( supplierName ).html();
        const brk      = '<br class="mmi-inline-break">';

        if ( bySupplier.length === 0 ) {
            $el.removeClass( 'mmi-hidden' ).html(
                `${ brk }<span class="dashicons dashicons-warning"></span> <strong>${ safeName }</strong> has no taxonomy-mapped fields yet.`
            );
        } else if ( taxParts.length === 0 ) {
            $el.removeClass( 'mmi-hidden' ).html(
                `${ brk }For <strong>${ safeName }</strong> (this data source): ${ bySupplier.length } source values seen, none mapped yet.`
            );
        } else {
            $el.removeClass( 'mmi-hidden' ).html(
                `${ brk }For <strong>${ safeName }</strong> (this data source): ${ taxParts.join( ', ' ) }.`
            );
        }
    }

    function updateAllRowsEntry( supplier, sourceField, sourceValue, termId, termName, wcTaxonomy ) {
        const entry = allRows.find( r =>
            r.supplier     === supplier    &&
            r.source_field === sourceField &&
            r.source_value === sourceValue
        );
        if ( entry ) {
            entry.wc_term_id   = termId;
            entry.wc_term_name = termName;
            if ( wcTaxonomy !== undefined ) {
                entry.wc_taxonomy = wcTaxonomy;
            }
        }
    }

    /* ── UI helpers ─────────────────────────────────────────────────────── */

    function showLoading( show ) {
        $( SELECTORS.LOADING ).toggleClass( 'mmi-hidden', ! show );
    }

    function setProgress( pct, text ) {
        $( SELECTORS.PROGRESS_FILL ).css( 'width', pct + '%' );
        $( SELECTORS.PROGRESS_TEXT ).text( text );
    }

    function showNotice( message, type ) {
        const $n = $( SELECTORS.NOTICE );
        $n.removeClass( 'notice-success notice-error is-success is-error mmi-hidden' )
          .addClass( type === 'success' ? 'is-success' : 'is-error' )
          .text( message );
        if ( type === 'success' ) {
            setTimeout( () => $n.addClass( 'mmi-hidden' ), 4000 );
        }
    }

    function hideNotice() {
        $( SELECTORS.NOTICE ).addClass( 'mmi-hidden' ).text( '' );
    }

    /* ── Taxonomy chip (which taxonomy a row/rule/search targets) ────────── */

    /** Human label for a taxonomy slug, read from the existing <select> so it's never duplicated. */
    function taxonomyLabel( slug ) {
        const $opt = $( SELECTORS.WC_TAXONOMY + ` option[value="${ slug }"]` );
        return $opt.length ? $opt.text() : ( slug || '—' );
    }

    /** Deterministic colour for a taxonomy slug — same slug always gets the same colour. */
    function taxonomyChipColor( slug ) {
        let hash = 0;
        for ( let i = 0; i < slug.length; i++ ) {
            hash = ( hash * 31 + slug.charCodeAt( i ) ) % TAXONOMY_CHIP_PALETTE.length;
        }
        return TAXONOMY_CHIP_PALETTE[ Math.abs( hash ) ];
    }

    /** Colour a row's taxonomy <select> the same way the old static chip was coloured. */
    function applyTaxonomySelectColor( $select, slug ) {
        if ( ! slug ) { $select.attr( 'style', '' ); return; }
        const color = taxonomyChipColor( slug );
        $select.attr( 'style', `--chip-bg:${ color.bg }; --chip-text:${ color.text };` );
    }

    /** Refresh an alias rule row's taxonomy chip after its taxonomy <select> changes. */
    function updateRuleTaxonomyChip( $tr ) {
        const slug  = $tr.find( '.mmi-rule-taxonomy' ).val();
        const color = slug ? taxonomyChipColor( slug ) : null;
        const $chip = $tr.find( '.mmi-rule-taxonomy-chip' );
        if ( ! slug || ! color ) {
            $chip.text( '' ).attr( 'style', '' );
            return;
        }
        $chip.text( taxonomyLabel( slug ) )
            .attr( 'style', `--chip-bg:${ color.bg }; --chip-text:${ color.text };` );
    }

    /* ── Utility ────────────────────────────────────────────────────────── */

    function escHtml( str ) {
        return window.MMIEscapeHtml( str );
    }

    function debounce( fn, delay ) {
        let timer;
        return function ( ...args ) {
            clearTimeout( timer );
            timer = setTimeout( () => fn.apply( this, args ), delay );
        };
    }

} )( jQuery );
