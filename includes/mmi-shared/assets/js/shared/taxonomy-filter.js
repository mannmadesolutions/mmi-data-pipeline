/**
 * MMI Taxonomy Filter — Shared Module
 *
 * Reads data-taxonomy-filters='["slug1","slug2"]' from any table element,
 * fetches terms from the mmi_get_taxonomy_terms AJAX endpoint, and dynamically
 * injects a .mmi-filter-section into the nearest .mmi-filters-row.
 *
 * The injected HTML matches the existing .mmi-multiselect pattern used across
 * all MMI admin filter bars so the existing pill/open/close event delegates in
 * admin.js pick it up automatically — no extra event wiring needed here.
 *
 * Input IDs:   filter-taxonomy-{slug}
 * POST params: filter_taxonomy_{slug}
 *
 * Public API (window.mmiTaxonomyFilters):
 *   collectParams()         — returns { filter_taxonomy_slug: "val,val2" } for
 *                             every injected filter; call from collectFilterParams()
 *                             or loadProductsTable to include taxonomy values.
 *   reinit($table)          — re-initialize for a specific table element.
 */
( function ( $ ) {
    'use strict';

    /* ── Constants ──────────────────────────────────────────────────────── */
    const SECTION_SLUG  = 'mmi-taxonomy-dynamic';
    const INPUT_PREFIX  = 'filter-taxonomy-';
    const PARAM_PREFIX  = 'filter_taxonomy_';

    /* ── Tiny HTML-escape helpers (no lodash / DOMPurify needed) ─────────── */
    function escAttr( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /"/g, '&quot;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' );
    }

    function escHtml( str ) {
        return window.MMIEscapeHtml( str );
    }

    /* ── Collect all active taxonomy filter values ───────────────────────── */
    /**
     * Returns an object like { filter_taxonomy_product_brand: "fender,gibson" }
     * for every injected hidden input. Intended to be merged into the main
     * AJAX data object inside loadProductsTable() / collectFilterParams().
     */
    function collectParams() {
        var params = {};
        $( 'input[id^="' + INPUT_PREFIX + '"]' ).each( function () {
            var paramKey   = PARAM_PREFIX + this.id.slice( INPUT_PREFIX.length );
            params[ paramKey ] = $( this ).val() || '';
        } );
        return params;
    }

    /* ── Build .mmi-multiselect HTML for one taxonomy ────────────────────── */
    function buildMultiselect( slug, label, terms, selectedValues ) {
        var inputId = INPUT_PREFIX + slug;
        var selSet  = {};
        selectedValues.forEach( function ( v ) { selSet[ v ] = true; } );

        var optionsHtml = '';
        terms.forEach( function ( term ) {
            var isSel = !! selSet[ term.slug ];
            optionsHtml +=
                '<button type="button" class="mmi-ms-option' + ( isSel ? ' is-selected' : '' ) + '"' +
                ' data-value="' + escAttr( term.slug ) + '">' +
                '<span class="mmi-ms-opt-label">' + escHtml( term.name ) + '</span>' +
                '<span class="mmi-ms-opt-count"></span>' +
                '</button>';
        } );

        /* Badge text: how many are already selected (for initial page-load state) */
        var badgeHtml = selectedValues.length
            ? '<span class="mmi-ms-badge">' + selectedValues.length + '</span>'
            : '<span class="mmi-ms-badge" hidden></span>';

        var triggerLabel = selectedValues.length
            ? escHtml( label ) + ' (' + selectedValues.length + ')'
            : escHtml( label );

        return (
            '<div class="mmi-multiselect" data-filter="' + escAttr( inputId ) + '" data-label="' + escAttr( label ) + '">' +
                '<button type="button" class="mmi-ms-trigger" aria-expanded="false">' +
                    '<span class="mmi-ms-label">' + triggerLabel + '</span>' +
                    badgeHtml +
                    '<span class="mmi-ms-caret">&#9662;</span>' +
                '</button>' +
                '<div class="mmi-ms-menu" hidden>' + optionsHtml + '</div>' +
                '<input type="hidden" id="' + escAttr( inputId ) + '" value="' + escAttr( selectedValues.join( ',' ) ) + '">' +
            '</div>'
        );
    }

    /* ── Inject the taxonomy filter section into a .mmi-filters-row ──────── */
    function injectSection( $filtersRow, taxonomyData, initialValues ) {
        /* Remove any previously-injected taxonomy section */
        $filtersRow.find( '.mmi-filter-section[data-section="' + SECTION_SLUG + '"]' ).remove();

        var slugs = Object.keys( taxonomyData );
        if ( ! slugs.length ) {
            return;
        }

        /* The server renders a static #filter-taxonomy-{slug} placeholder per slug
         * (see readInitialValues() below) purely so this module can seed initial
         * state before the terms AJAX call resolves. buildMultiselect() below
         * renders its own #filter-taxonomy-{slug} input as the live, authoritative
         * control — leaving the static one in place would create a duplicate ID.
         * With two elements sharing one ID, admin.js's click handler (`$('#'+id)`)
         * always resolves to this first (static) one, while collectParams()'s
         * `input[id^=...]` prefix selector matches both and the never-updated
         * duplicate wins the last-write in its loop — so every AJAX reload
         * (filtering AND sorting alike) silently sends a stale/frozen value.
         * Removing the placeholder here guarantees exactly one input per slug. */
        slugs.forEach( function ( slug ) {
            $( '#' + INPUT_PREFIX + slug ).remove();
        } );

        var bodyHtml = '';
        slugs.forEach( function ( slug ) {
            var info    = taxonomyData[ slug ];
            var selVals = ( initialValues[ slug ] || '' ).split( ',' ).filter( Boolean );
            bodyHtml += buildMultiselect( slug, info.label, info.terms, selVals );
        } );

        var $section = $(
            '<div class="mmi-filter-section" data-section="' + SECTION_SLUG + '">' +
                '<span class="mmi-filter-section-heading">Taxonomies</span>' +
                '<div class="mmi-filter-section-body">' + bodyHtml + '</div>' +
            '</div>'
        );

        $filtersRow.append( $section );
    }

    /* ── Sync the filter-customize panel ─────────────────────────────────── */
    function updateCustomizePanel( $context ) {
        var $panel = $context.find( '#mmi-filter-customize-panel' );
        if ( ! $panel.length ) {
            return;
        }
        /* Remove any previously injected taxonomy entry */
        $panel.find( '.mmi-fcp-item[data-taxonomy-entry]' ).remove();
        $panel.append(
            '<label class="mmi-fcp-item" data-taxonomy-entry>' +
                '<input type="checkbox" data-section-toggle="' + SECTION_SLUG + '"> Taxonomies' +
            '</label>'
        );
    }

    /* ── Read initial hidden-input values written by PHP server-side ──────── */
    function readInitialValues( slugs ) {
        var vals = {};
        slugs.forEach( function ( slug ) {
            var $input = $( '#' + INPUT_PREFIX + slug );
            vals[ slug ] = $input.length ? ( $input.val() || '' ) : '';
        } );
        return vals;
    }

    /* ── Bootstrap taxonomy filters for one table element ────────────────── */
    function initTable( $table ) {
        var slugs;
        try {
            slugs = JSON.parse( $table.attr( 'data-taxonomy-filters' ) || '[]' );
        } catch ( e ) {
            return;
        }
        if ( ! Array.isArray( slugs ) || ! slugs.length ) {
            return;
        }

        /* Walk up to find the surrounding tab/page wrapper that contains
           the .mmi-filters-row and the filter-customize panel. */
        var $context = $table.closest( '.mmi-reverb-products-tab, .mmi-pipeline-tab, .wrap' );
        if ( ! $context.length ) {
            $context = $table.parent();
        }

        var $filtersRow = $context.find( '.mmi-filters-row' ).first();
        if ( ! $filtersRow.length ) {
            return;
        }

        var initialValues = readInitialValues( slugs );

        var ajaxUrl = ( typeof mmiGlobal !== 'undefined' && mmiGlobal.ajaxUrl )
            ? mmiGlobal.ajaxUrl
            : ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' );

        var nonce = ( typeof mmiGlobal !== 'undefined' && mmiGlobal.nonce )
            ? mmiGlobal.nonce
            : '';

        $.post(
            ajaxUrl,
            {
                action:     'mmi_get_taxonomy_terms',
                nonce:      nonce,
                taxonomies: JSON.stringify( slugs ),
            },
            function ( response ) {
                if ( ! response || ! response.success ) {
                    return;
                }
                injectSection( $filtersRow, response.data, initialValues );
                updateCustomizePanel( $context );

                /* Trigger the filter-visibility system so new section respects prefs */
                $( document ).trigger( 'mmi:taxonomy:injected', [ $context ] );
            }
        );
    }

    /* ── Auto-init all matching tables on DOM ready ───────────────────────── */
    $( function () {
        $( '[data-taxonomy-filters]' ).each( function () {
            initTable( $( this ) );
        } );
    } );

    /* ── Public API ─────────────────────────────────────────────────────── */
    window.mmiTaxonomyFilters = {
        /**
         * Collect active taxonomy filter values.
         * @returns {Object} e.g. { filter_taxonomy_product_brand: "fender" }
         */
        collectParams: collectParams,
        /**
         * Re-initialize taxonomy filters for a specific table element.
         * @param {jQuery|HTMLElement} tableEl
         */
        reinit: function ( tableEl ) {
            initTable( $( tableEl ) );
        },
    };

} )( jQuery );
