/**
 * MMI Pagination — shared "Compact" pagination control (prev/next + label)
 *
 * The suite had 9 independent pagination implementations before this
 * (AGENTS.md changelog 1.118.0's usage survey), including 3 incompatible
 * ones inside mmi-import-pipeline alone. This is the "Compact" variant:
 * prev/next buttons plus a "Page X of Y" label, no numbered pages — the
 * right fit for a dataset paged via a server AJAX call per page (this
 * plugin's total isn't known client-side up front, or the row count is too
 * large to hold in memory). For an already-loaded, in-memory dataset, per
 * this suite's Data Tables convention (sort/page in memory, never re-fetch
 * to page data already in hand), a caller should slice its own array by
 * `page`/`perPage` and pass the resulting `totalPages` in — this module
 * only renders the control and reports page changes, it never fetches or
 * slices data itself.
 *
 * Usage:
 *   const pager = MMIPagination.init({
 *       container: '#my-table-pagination',      // emptied and rendered into
 *       totalPages: 5,
 *       page: 1,                                 // optional, default 1
 *       onPageChange: function (page) {          // fetch/slice/re-render, then:
 *           pager.setTotalPages(newTotalPages);  // if the count can change (e.g. filters)
 *       },
 *   });
 *   // later, e.g. after a filter changes row count server-side:
 *   pager.setTotalPages(3);
 *   pager.setPage(1);
 *
 * Two optional slots, both off by default (existing callers unaffected):
 *   showFirstLast: true          — adds First/Last buttons flanking Prev/Next.
 *                                  For a real WordPress-native First/Last
 *                                  pagination convention (DEC-07), not just
 *                                  a cosmetic add — jump-to-boundary is a
 *                                  real, different affordance than one-step.
 *   pageSizes: [25, 50, 100, 250] — adds a page-size <select>. Pair with
 *   pageSize: 50                    onPageSizeChange to react to a change;
 *   onPageSizeChange: function (newSize) { ... }
 *                                  the caller owns re-fetching/re-slicing at
 *                                  the new size and calling setTotalPages()
 *                                  with the recomputed count, same contract
 *                                  as onPageChange (DEC-02).
 *
 * Link mode (DEC-07's other half): pass getHref instead of onPageChange for
 * a WordPress-native server-GET-link pager (full-page-reload navigation, no
 * AJAX, no JS-driven state) — the exact shape of tab-sku-conflicts.php's/
 * tab-offers.php's/tab-messages.php's own pagination. Every nav control
 * renders as a real `<a href>` (or a disabled `<span>` at a boundary,
 * matching WordPress core's own .tablenav-pages convention) instead of a
 * `<button>`, and no click handler is bound at all — plain browser
 * navigation handles it:
 *   const pager = MMIPagination.init({
 *       container: '#my-table-pagination',
 *       totalPages: 5,
 *       page: 3,
 *       showFirstLast: true,
 *       getHref: function (page) { return addQueryArg('paged', page); },
 *   });
 * onPageChange/onPageSizeChange are never called in this mode — there is no
 * page-state to own client-side, the next real page load re-renders fresh
 * from the server's own $current_page/$total_pages, same as today.
 *
 * @package MannMade\Hub
 */

window.MMIPagination = (function ($) {
    'use strict';

    const MESSAGES = {
        PAGE_LABEL: 'Page %current% of %total%',
        PER_PAGE_LABEL: 'Per page:',
    };

    /**
     * One nav control (Prev/Next/First/Last). Link mode (cfg.getHref given)
     * renders a real `<a href>`, or a disabled `<span>` at a boundary — a
     * `<button disabled>` has nothing to navigate to and no server GET
     * pagination convention uses one. Button mode (the default) keeps the
     * original always-clickable-element-with-disabled-state shape so
     * MMIPagination's own click delegation can bind once to the container.
     */
    function navControl(dir, label, disabled, cfg, targetPage) {
        const classes = ['mmi-pagination-btn'];
        if (dir === 'first' || dir === 'last') {
            classes.push('mmi-pagination-btn--boundary');
        }
        if (cfg.getHref) {
            if (disabled) {
                classes.push('mmi-pagination-btn--disabled');
                return $('<span></span>').addClass(classes.join(' ')).html(label);
            }
            return $('<a></a>').addClass(classes.join(' ')).attr('href', cfg.getHref(targetPage)).html(label);
        }
        return $('<button type="button"></button>').addClass(classes.join(' ')).attr('data-dir', dir).html(label).prop('disabled', disabled);
    }

    function render($container, state, cfg) {
        const label = MESSAGES.PAGE_LABEL
            .replace('%current%', state.page)
            .replace('%total%', state.totalPages);

        const atFirst = state.page <= 1;
        const atLast = state.page >= state.totalPages;
        const $controls = $('<div class="mmi-pagination-controls"></div>');

        if (cfg.showFirstLast) {
            $controls.append(navControl('first', '&laquo; First', atFirst, cfg, 1));
        }
        $controls.append(
            navControl('prev', '&lsaquo; Prev', atFirst, cfg, state.page - 1),
            navControl('next', 'Next &rsaquo;', atLast, cfg, state.page + 1)
        );
        if (cfg.showFirstLast) {
            $controls.append(navControl('last', 'Last &raquo;', atLast, cfg, state.totalPages));
        }

        $container.empty().addClass('mmi-pagination').append(
            $('<span class="mmi-pagination-label"></span>').text(label),
            $controls
        );

        if (Array.isArray(cfg.pageSizes) && cfg.pageSizes.length) {
            const $select = $('<select class="mmi-pagination-size"></select>');
            cfg.pageSizes.forEach(function (size) {
                $select.append($('<option></option>').val(size).text(size));
            });
            $select.val(state.pageSize);
            $container.append(
                $('<label class="mmi-pagination-size-label"></label>')
                    .text(MESSAGES.PER_PAGE_LABEL)
                    .append($select)
            );
        }
    }

    /**
     * @param {object} options
     * @param {string|Element|jQuery} options.container
     * @param {number} options.totalPages
     * @param {number} [options.page] initial page, 1-indexed, default 1
     * @param {Function} options.onPageChange (page) => void — ignored/omit if options.getHref given
     * @param {boolean} [options.showFirstLast] render First/Last boundary buttons
     * @param {number[]} [options.pageSizes] render a page-size <select> with these options
     * @param {number} [options.pageSize] initial page-size selection, required if pageSizes given
     * @param {Function} [options.onPageSizeChange] (newSize) => void, required if pageSizes given
     * @param {Function} [options.getHref] (page) => url string — switches to link mode (see file header)
     */
    function createInstance(options) {
        const cfg = Object.assign({ page: 1, showFirstLast: false }, options);
        const $container = $(cfg.container);
        const state = {
            page: cfg.page,
            totalPages: Math.max(1, cfg.totalPages),
            pageSize: cfg.pageSize,
        };

        function go(page) {
            const clamped = Math.min(state.totalPages, Math.max(1, page));
            if (clamped === state.page) {
                return;
            }
            state.page = clamped;
            render($container, state, cfg);
            cfg.onPageChange(state.page);
        }

        // Link mode navigates via real hrefs — no client-side page state to
        // drive, so no click delegation or page-size change handler either.
        if (!cfg.getHref) {
            const DIR_TARGETS = {
                first: function () { return 1; },
                prev: function () { return state.page - 1; },
                next: function () { return state.page + 1; },
                last: function () { return state.totalPages; },
            };

            $container.on('click', '.mmi-pagination-btn', function () {
                go(DIR_TARGETS[$(this).data('dir')]());
            });
        }

        if (!cfg.getHref && Array.isArray(cfg.pageSizes) && cfg.pageSizes.length) {
            $container.on('change', '.mmi-pagination-size', function () {
                state.pageSize = parseInt($(this).val(), 10);
                cfg.onPageSizeChange(state.pageSize);
            });
        }

        render($container, state, cfg);

        return {
            getPage: function () { return state.page; },
            setPage: function (page) { go(page); },
            /** Update the known page count (e.g. after a filter changes row count) without firing onPageChange. */
            setTotalPages: function (totalPages) {
                state.totalPages = Math.max(1, totalPages);
                if (state.page > state.totalPages) {
                    state.page = state.totalPages;
                }
                render($container, state, cfg);
            },
        };
    }

    return { init: createInstance };
})(jQuery);
