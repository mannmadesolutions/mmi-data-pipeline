/**
 * MMI WC Product Filter Bar
 *
 * Shared behaviour module for the standard WooCommerce product filter bar rendered
 * by mmi-hub/includes/admin/views/partials/wc-product-filter-bar.php.
 *
 * Usage (in the plugin's own admin JS):
 *
 *   MMI_WcFilterBar.init({
 *     onChanged: function(params) {
 *       // params is a plain object with the current filter values:
 *       //   { s, filter_stock, filter_featured_image, filter_price_min, filter_price_max }
 *       myPlugin.loadTable(1, params);
 *     }
 *   });
 *
 *   // Retrieve params at any time:
 *   const params = MMI_WcFilterBar.getParams();
 *
 *   // Reset all filters (and fire onChanged):
 *   MMI_WcFilterBar.reset();
 *
 * The module attaches to elements rendered by the PHP partial (IDs prefixed with 'wc-').
 * It does NOT inject HTML — all UI is server-rendered.
 *
 * @package MMI_Hub
 */

/* global jQuery, mmiGlobal */
window.MMI_WcFilterBar = (function ($) {
    'use strict';

    // ── Constants ─────────────────────────────────────────────────────────────
    const SELECTORS = {
        bar:            '[data-wc-filter-bar]',
        searchInput:    '#wc-product-search-input',
        searchHidden:   '#wc-filter-search',
        pills:          '[data-wc-filter-bar] .mmi-filter-pills',
        stockHidden:    '#wc-filter-stock',
        imageHidden:    '#wc-filter-featured-image',
        priceMin:       '#wc-filter-price-min',
        priceMax:       '#wc-filter-price-max',
    };

    const DEBOUNCE_MS   = 800;
    const COUNTS_KEY    = 'mmi_wc_filter_counts_cache';
    const COUNTS_TTL_MS = 5 * 60 * 1000;

    // ── State ─────────────────────────────────────────────────────────────────
    let _onChanged    = null;
    let _searchTimer  = null;
    let _priceTimer   = null;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Initialise the filter bar.
     *
     * @param {Object}   config
     * @param {Function} config.onChanged  Called with the current params object whenever
     *                                     any filter changes.
     */
    function init(config) {
        _onChanged = (config && typeof config.onChanged === 'function') ? config.onChanged : null;

        _attachPillHandlers();
        _attachSearchHandler();
        _attachPriceHandlers();
        _loadFilterCounts();
    }

    /**
     * Return the current filter param values.
     *
     * @returns {Object}
     */
    function getParams() {
        return {
            s:                     $(SELECTORS.searchHidden).val()  || '',
            filter_stock:          $(SELECTORS.stockHidden).val()   || '',
            filter_featured_image: $(SELECTORS.imageHidden).val()   || '',
            filter_price_min:      $(SELECTORS.priceMin).val()      || '',
            filter_price_max:      $(SELECTORS.priceMax).val()      || '',
        };
    }

    /**
     * Reset all filter bar controls to empty and fire onChanged.
     */
    function reset() {
        $(SELECTORS.searchInput).val('');
        $(SELECTORS.searchHidden).val('');
        $(SELECTORS.stockHidden).val('');
        $(SELECTORS.imageHidden).val('');
        $(SELECTORS.priceMin).val('').removeClass('mmi-price-active');
        $(SELECTORS.priceMax).val('').removeClass('mmi-price-active');
        $(SELECTORS.pills).find('.mmi-pill').removeClass('active');
        _fireChanged();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    function _attachPillHandlers() {
        $(document).on('click', SELECTORS.bar + ' .mmi-pill', function () {
            const $pill    = $(this);
            const $pills   = $pill.closest('.mmi-filter-pills');
            const filterId = $pills.data('filter');

            if ($pill.hasClass('active')) {
                $pill.removeClass('active');
                $('#' + filterId).val('');
            } else {
                $pills.find('.mmi-pill').removeClass('active');
                $pill.addClass('active');
                $('#' + filterId).val($pill.data('value'));
            }

            _fireChanged();
        });
    }

    function _attachSearchHandler() {
        $(document).on('input', SELECTORS.searchInput, function () {
            const val = $(this).val();
            $(SELECTORS.searchHidden).val(val);
            clearTimeout(_searchTimer);
            _searchTimer = setTimeout(_fireChanged, DEBOUNCE_MS);
        });

        $(document).on('keypress', SELECTORS.searchInput, function (e) {
            if (e.which === 13) {
                e.preventDefault();
                clearTimeout(_searchTimer);
                $(SELECTORS.searchHidden).val($(this).val());
                _fireChanged();
            }
        });
    }

    function _attachPriceHandlers() {
        $(document).on('input', SELECTORS.priceMin + ', ' + SELECTORS.priceMax, function () {
            $(this).toggleClass('mmi-price-active', $(this).val() !== '');
            clearTimeout(_priceTimer);
            _priceTimer = setTimeout(_fireChanged, DEBOUNCE_MS);
        });
    }

    function _fireChanged() {
        if (typeof _onChanged === 'function') {
            _onChanged(getParams());
        }
    }

    /**
     * Load global product counts for the filter dimensions and update pill badges.
     * Results are cached in sessionStorage for 5 minutes to avoid redundant AJAX calls.
     */
    function _loadFilterCounts() {
        // Only bother if the bar is actually on the page.
        if (!$(SELECTORS.bar).length) { return; }

        const ajaxUrl = (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl;
        const nonce   = (window.mmiGlobal && window.mmiGlobal.nonce)   || '';
        if (!ajaxUrl || !nonce) { return; }

        // Check sessionStorage cache.
        try {
            const cached = JSON.parse(sessionStorage.getItem(COUNTS_KEY) || 'null');
            if (cached && cached._ts && (Date.now() - cached._ts) < COUNTS_TTL_MS) {
                _applyFilterCounts(cached);
                return;
            }
        } catch (e) { /* ignore */ }

        $.post(ajaxUrl, { action: 'mmi_get_wc_product_filter_counts', nonce: nonce }, function (response) {
            if (!response.success || !response.data) { return; }
            const counts = Object.assign({}, response.data, { _ts: Date.now() });
            try { sessionStorage.setItem(COUNTS_KEY, JSON.stringify(counts)); } catch (e) { /* ignore */ }
            _applyFilterCounts(counts);
        });
    }

    /**
     * Write count badges onto pill buttons inside the filter bar.
     *
     * @param {Object} counts  { stock: {in_stock, out_of_stock}, image: {yes, no} }
     */
    function _applyFilterCounts(counts) {
        if (!counts) { return; }

        _updatePillCounts('[data-counts-key="stock"]', {
            in_stock:     counts.stock && counts.stock.in_stock,
            out_of_stock: counts.stock && counts.stock.out_of_stock,
        });

        _updatePillCounts('[data-counts-key="image"]', {
            yes: counts.image && counts.image.yes,
            no:  counts.image && counts.image.no,
        });
    }

    function _updatePillCounts(pillsSelector, countMap) {
        $(SELECTORS.bar).find(pillsSelector).find('.mmi-pill').each(function () {
            const val = $(this).data('value');
            const cnt = countMap[val];
            if (cnt === undefined || cnt === null) { return; }
            const $lbl = $(this).find('.mmi-pill-label');
            const baseText = $lbl.length
                ? $lbl.text()
                : $(this).text().replace(/\s*\(\d[\d,]*\)$/, '').trim();
            // Built as nodes, not an HTML string: baseText came from .text(),
            // which decodes entities, so re-inserting it as HTML could run markup.
            $(this).empty().append(
                $('<span class="mmi-pill-label"></span>').text(baseText),
                ' ',
                $('<span class="mmi-pill-count"></span>').text('(' + Number(cnt).toLocaleString() + ')')
            );
        });
    }

    // ── Public surface ────────────────────────────────────────────────────────
    return { init: init, getParams: getParams, reset: reset };

}(jQuery));
