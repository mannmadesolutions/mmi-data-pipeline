/**
 * MMI Universal Table Manager
 *
 * Provides standardised sort behaviour for all MMI admin data tables.
 * Two modes are supported:
 *
 *   • 'url'  — server-side sorted tables (paginated, full-page reload).
 *              Reads data-orderby from th.sortable, updates URL params, navigates.
 *
 *   • 'dom'  — client-side sorted tables (fully-loaded in-memory).
 *              Reads data-sort-key from th.sortable, data-sort-value from td,
 *              then re-orders <tbody> rows in place.
 *
 * Sort indicator classes (.sorted-asc / .sorted-desc) and the ⇅ / ▲ / ▼
 * pseudo-element arrows are defined in mmi-suite-common.css.
 *
 * @package MMI_Hub
 * @version 1.0.0
 */

(function($) {
    'use strict';

    // ── Constants ─────────────────────────────────────────────────────────────

    const SORT = {
        ASC:        'asc',
        DESC:       'desc',
        DEFAULT:    'asc',

        CLASS_ASC:  'sorted-asc',
        CLASS_DESC: 'sorted-desc',
    };

    const PARAMS = {
        ORDERBY: 'orderby',
        ORDER:   'order',
        PAGED:   'paged',
    };

    const SELECTORS = {
        SORTABLE_TH: 'thead th.sortable',
        TBODY_ROW:   'tbody tr',
    };

    // ── Public API ────────────────────────────────────────────────────────────

    window.MMI_TableManager = {

        /**
         * URL-mode sort — for server-side paginated tables.
         *
         * Clicking a th.sortable updates URL ?orderby=X&order=asc|desc and
         * resets paged to 1, then navigates.  All other existing URL params
         * (filters, search, tab, page) are preserved.
         *
         * @param {string} tableSelector  CSS selector identifying the table.
         */
        initUrlSort: function(tableSelector) {
            const $table = $(tableSelector);
            if (!$table.length) return;

            const currentParams = new URLSearchParams(window.location.search);
            const activeOrderby = currentParams.get(PARAMS.ORDERBY) || '';
            const activeOrder   = (currentParams.get(PARAMS.ORDER) || SORT.DEFAULT).toLowerCase();

            // Reflect server-rendered sort state in the UI on page load.
            if (activeOrderby) {
                $table.find(SELECTORS.SORTABLE_TH).each(function() {
                    if ($(this).data('orderby') === activeOrderby) {
                        $(this).addClass(activeOrder === SORT.ASC ? SORT.CLASS_ASC : SORT.CLASS_DESC);
                    }
                });
            }

            $table.on('click', SELECTORS.SORTABLE_TH, function() {
                const $th      = $(this);
                const column   = $th.data('orderby');
                if (!column) return;

                const params   = new URLSearchParams(window.location.search);
                const curCol   = params.get(PARAMS.ORDERBY) || '';
                const curOrder = (params.get(PARAMS.ORDER) || SORT.DEFAULT).toLowerCase();

                // Toggle direction when clicking the already-active column;
                // default to ASC when switching to a new column.
                let newOrder;
                if (curCol === column) {
                    newOrder = curOrder === SORT.ASC ? SORT.DESC : SORT.ASC;
                } else {
                    newOrder = SORT.DEFAULT;
                }

                params.set(PARAMS.ORDERBY, column);
                params.set(PARAMS.ORDER,   newOrder);
                params.set(PARAMS.PAGED,   '1');

                window.location.href = window.location.pathname + '?' + params.toString();
            });
        },

        /**
         * DOM-mode sort — for fully-loaded client-side tables.
         *
         * Reads data-sort-key from th.sortable to identify which column to sort.
         * Reads data-sort-value from the matching td in each row; falls back to
         * the cell's trimmed text content.
         *
         * Numeric detection: if all non-empty sort values parse as finite numbers
         * the column is sorted numerically; otherwise lexicographically.
         *
         * @param {string} tableSelector  CSS selector identifying the table.
         */
        initDomSort: function(tableSelector) {
            const $table = $(tableSelector);
            if (!$table.length) return;

            $table.on('click', SELECTORS.SORTABLE_TH, function() {
                const $th      = $(this);
                const sortKey  = $th.data('sort-key');
                const colIndex = $th.index();
                const isAsc    = $th.hasClass(SORT.CLASS_ASC);
                const newOrder = isAsc ? SORT.DESC : SORT.ASC;

                MMI_TableManager._updateSortIndicators($table, $th, newOrder);

                const $tbody = $table.find('tbody').first();
                // children('tr'): SELECTORS.TBODY_ROW is 'tbody tr', which
                // searched *inside* the tbody, matched nothing and made
                // every DOM-mode sort a silent no-op (arrows flipped, rows
                // never moved) until 2026-09-29.
                const rows   = $tbody.children('tr').get();

                // Collect sort values for all rows.
                const getValue = function(row) {
                    // The keyed cell when the row marks one, else the cell in
                    // the heading's column — some tables key only the <th>.
                    let $td = sortKey ? $(row).find(`[data-sort-key="${sortKey}"]`) : $();
                    if (!$td.length) {
                        $td = $(row).children('td, th').eq(colIndex);
                    }

                    return $td.length
                        ? ($td.attr('data-sort-value') || $td.text().trim())
                        : '';
                };

                // Detect numeric column (all non-empty values must be finite numbers).
                const isNumeric = rows.every(function(row) {
                    const v = getValue(row);
                    return v === '' || isFinite(Number(v));
                });

                rows.sort(function(a, b) {
                    let aVal = getValue(a);
                    let bVal = getValue(b);

                    if (isNumeric) {
                        aVal = aVal === '' ? (newOrder === SORT.ASC ? Infinity : -Infinity) : Number(aVal);
                        bVal = bVal === '' ? (newOrder === SORT.ASC ? Infinity : -Infinity) : Number(bVal);
                        return newOrder === SORT.ASC ? aVal - bVal : bVal - aVal;
                    }

                    aVal = String(aVal).toLowerCase();
                    bVal = String(bVal).toLowerCase();

                    if (aVal < bVal) return newOrder === SORT.ASC ? -1 : 1;
                    if (aVal > bVal) return newOrder === SORT.ASC ? 1 : -1;
                    return 0;
                });

                $.each(rows, function(i, row) { $tbody.append(row); });
            });
        },

        /**
         * Update sort indicator classes on column headers.
         *
         * Removes .sorted-asc / .sorted-desc from all th in the table,
         * then applies the appropriate class to the clicked th.
         *
         * @param {jQuery} $table    The table element.
         * @param {jQuery} $clickedTh  The header that was clicked.
         * @param {string} newOrder  'asc' or 'desc'.
         */
        _updateSortIndicators: function($table, $clickedTh, newOrder) {
            $table.find('thead th')
                .removeClass(`${SORT.CLASS_ASC} ${SORT.CLASS_DESC}`);

            $clickedTh.addClass(
                newOrder === SORT.ASC ? SORT.CLASS_ASC : SORT.CLASS_DESC
            );
        },
    };

})(jQuery);
