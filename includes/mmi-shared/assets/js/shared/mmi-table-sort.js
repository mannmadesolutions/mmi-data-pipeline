/**
 * MMI Table Sort — every heading of every MMI admin table sorts.
 *
 * Loaded on every admin page by the shared library; acts only inside
 * body.mmi-page. Tables are picked up automatically, including ones rendered
 * later by AJAX, so a new table sorts with no code of its own.
 *
 * Which tables it handles, per table:
 *   - A table that already sorts some of its own headings (data-col,
 *     data-orderby, .sortable, .sort-icon, a WP orderby link, …) is owned by
 *     its plugin's code and left alone — that code sorts the columns it knows,
 *     usually server-side.
 *   - data-mmi-sort="server": a page-linked (?paged=) table. Headings with a
 *     data-sort-key reload the page with ?orderby=<key>&order=asc|desc, which
 *     that table's PHP applies to its query — so the sort covers every record,
 *     not just the page on screen.
 *   - data-mmi-sort="page": rows come one page at a time from somewhere that
 *     can't sort (e.g. a live third-party API). Sorted here, in the browser,
 *     with each heading's tooltip saying it sorts this page only.
 *   - data-mmi-sort="off": left alone.
 *   - Anything else with all its rows on the page: sorted here, in the
 *     browser. If pagination is found next to such a table it is skipped
 *     (sorting one page would mislead) until it's given data-mmi-sort="server".
 *
 * Classes match the suite's shared sortable-header CSS (mmi-suite-common.css
 * "Sortable column headers"): th.sortable / .sorted-asc / .sorted-desc.
 * A resize drag never sorts: mmi-resize-sort-guard.js swallows that click
 * before it reaches this handler.
 */
(function () {
    'use strict';

    if (window.MMITableSort) return;

    const ATTR = {
        READY: 'data-mmi-sort-ready',
        MODE:  'data-mmi-sort',
        KEY:   'data-sort-key',
        VALUE: 'data-sort-value',
    };

    const CSS = {
        SORTABLE: 'sortable',
        ASC:      'sorted-asc',
        DESC:     'sorted-desc',
        AUTO:     'mmi-sort-auto',
    };

    // A heading its own plugin already sorts.
    const OWNED_HEADING = '[data-col], [data-sort], [data-orderby], .sortable, .sorted, .sorted-asc, .sorted-desc, .mmi-wb-sortable, .mmi-wb-job-sortable';
    const OWNED_INSIDE  = '.sort-icon, .sorting-indicator, a[href*="orderby="]';

    // Headings with nothing to sort by.
    const SKIP_HEADING_CLASS = /(^|\s)(check-column|column-cb|column-actions|column-thumb|mmi-no-sort)(\s|$)/;
    const SKIP_HEADING_TEXT  = /^(actions?|image|thumbnail|thumb|photo|select)$/i;

    // Pagination next to a client-sorted table means only one page is loaded.
    const PAGINATION = '.tablenav-pages .page-numbers, .tablenav-pages a, .mmi-pagination [data-dir], .mmi-pagination a';

    /* ── Setup ───────────────────────────────────────────────────────────── */

    function headerCells(table) {
        const row = table.tHead && table.tHead.rows[table.tHead.rows.length - 1];
        return row ? Array.from(row.cells) : [];
    }

    function isOwned(table) {
        return headerCells(table).some(function (th) {
            return th.matches(OWNED_HEADING) || th.querySelector(OWNED_INSIDE);
        });
    }

    function isSkippedHeading(th) {
        if (th.getAttribute(ATTR.MODE) === 'off') return true;
        if (SKIP_HEADING_CLASS.test(th.className)) return true;
        const text = th.textContent.trim();
        return text === '' || SKIP_HEADING_TEXT.test(text);
    }

    function hasPagination(table) {
        // The table's own section only — a pager elsewhere on the page is
        // some other table's.
        const scope = table.closest('.mmi-section-content, .mmi-process-section, .mmi-card, .postbox')
            || (table.parentElement && table.parentElement.parentElement);
        return !!(scope && scope.querySelector(PAGINATION));
    }

    function markHeading(th) {
        th.classList.add(CSS.SORTABLE, CSS.AUTO);
        th.setAttribute('tabindex', '0');
        th.setAttribute('aria-sort', 'none');
    }

    function setup(table) {
        if (!table.tHead) return;
        if (table.getAttribute(ATTR.READY) === 'client') {
            // Header row re-rendered by its plugin: mark the new headings.
            headerCells(table).forEach(function (th) {
                if (!th.classList.contains(CSS.AUTO) && !isSkippedHeading(th)) markHeading(th);
            });
            return;
        }
        if (table.hasAttribute(ATTR.READY)) return;
        const mode = table.getAttribute(ATTR.MODE);
        if (mode === 'off') return;

        if (mode === 'server') {
            table.setAttribute(ATTR.READY, 'server');
            const params  = new URLSearchParams(window.location.search);
            const current = params.get('orderby');
            const dir     = (params.get('order') || 'asc').toLowerCase() === 'desc' ? 'desc' : 'asc';
            headerCells(table).forEach(function (th) {
                const key = th.getAttribute(ATTR.KEY);
                if (!key) return;
                markHeading(th);
                if (key === current) setIndicator(table, th, dir);
            });
            return;
        }

        if (mode === 'page') {
            table.setAttribute(ATTR.READY, 'client');
            headerCells(table).forEach(function (th) {
                if (isSkippedHeading(th)) return;
                markHeading(th);
                if (!th.title) th.title = 'Sorts the rows on this page';
            });
            return;
        }

        if (isOwned(table)) {
            table.setAttribute(ATTR.READY, 'owned');
            return;
        }
        if (hasPagination(table)) {
            table.setAttribute(ATTR.READY, 'paged');
            return;
        }
        table.setAttribute(ATTR.READY, 'client');
        headerCells(table).forEach(function (th) {
            if (!isSkippedHeading(th)) markHeading(th);
        });
    }

    function setIndicator(table, th, dir) {
        headerCells(table).forEach(function (cell) {
            cell.classList.remove(CSS.ASC, CSS.DESC);
            if (cell.classList.contains(CSS.AUTO)) cell.setAttribute('aria-sort', 'none');
        });
        th.classList.add(dir === 'desc' ? CSS.DESC : CSS.ASC);
        th.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
    }

    /* ── Client sort ─────────────────────────────────────────────────────── */

    const state = new WeakMap(); // table => { index, dir }
    let reordering = false;

    function cellValue(cell) {
        if (!cell) return '';
        if (cell.hasAttribute(ATTR.VALUE)) return cell.getAttribute(ATTR.VALUE);
        const control = cell.querySelector('input, select, textarea');
        if (control && cell.textContent.trim() === '') {
            if (control.type === 'checkbox' || control.type === 'radio') return control.checked ? '1' : '0';
            if (control.tagName === 'SELECT') return control.selectedOptions.length ? control.selectedOptions[0].text : '';
            return control.value;
        }
        // innerText, not textContent: only what's visible — a cell may also
        // hold a hidden inline editor ("Save Cancel") or helper markup.
        const text = (cell.innerText !== undefined ? cell.innerText : cell.textContent).replace(/\s+/g, ' ').trim();
        // "—", "–", "-", "n/a": a placeholder for no value — sorts with the empties.
        return PLACEHOLDER.test(text) ? '' : text;
    }

    const PLACEHOLDER = /^(—|–|-|n\/a|none)$/i;
    const NUMBER = /^[-−+]?[$€£¥]?\s?[-−]?[\d,]*\.?\d+\s?%?$/;
    function toNumber(v) {
        return parseFloat(v.replace(/[−]/g, '-').replace(/[^0-9.\-]/g, ''));
    }

    /** 'number', 'date' or 'text' — whichever fits nearly every non-empty value. */
    function columnType(values) {
        const filled = values.filter(function (v) { return v !== ''; });
        if (!filled.length) return 'text';
        const share = function (test) { return filled.filter(test).length / filled.length; };
        if (share(function (v) { return NUMBER.test(v); }) >= 0.9) return 'number';
        if (share(function (v) { return /\d/.test(v) && !isNaN(Date.parse(v)); }) >= 0.9) return 'date';
        return 'text';
    }

    /**
     * Rows that belong to the row above them — an expandable detail row, or a
     * single cell spanning the table — move with it.
     */
    function isAttachedRow(tr, columns) {
        if (/(^|\s)[\w-]*(detail|child|expan|sub-row)[\w-]*(\s|$)/.test(tr.className)) return true;
        return tr.cells.length === 1 && tr.cells[0].colSpan > 1 && tr.cells[0].colSpan >= Math.ceil(columns / 2);
    }

    function sortClient(table, index, dir) {
        const columns = headerCells(table).length;
        reordering = true;
        Array.from(table.tBodies).forEach(function (tbody) {
            const groups = [];
            Array.from(tbody.rows).forEach(function (tr) {
                if (groups.length && isAttachedRow(tr, columns)) {
                    groups[groups.length - 1].rows.push(tr);
                } else {
                    groups.push({ rows: [tr], value: cellValue(tr.cells[index]) });
                }
            });
            if (groups.length < 2) return;
            const type = columnType(groups.map(function (g) { return g.value; }));
            const sign = dir === 'desc' ? -1 : 1;
            groups.forEach(function (g, i) { g.order = i; });
            groups.sort(function (a, b) {
                const ea = a.value === '', eb = b.value === '';
                if (ea !== eb) return ea ? 1 : -1; // empty values last, both directions
                let cmp;
                if (type === 'number')    cmp = toNumber(a.value) - toNumber(b.value);
                else if (type === 'date') cmp = Date.parse(a.value) - Date.parse(b.value);
                else cmp = a.value.localeCompare(b.value, undefined, { numeric: true, sensitivity: 'base' });
                return (cmp * sign) || (a.order - b.order);
            });
            const frag = document.createDocumentFragment();
            groups.forEach(function (g) { g.rows.forEach(function (tr) { frag.appendChild(tr); }); });
            tbody.appendChild(frag);
        });
        // Let the observer's callback for our own reorder pass before re-arming.
        setTimeout(function () { reordering = false; }, 0);
    }

    function onActivate(th) {
        const table = th.closest('table');
        if (!table) return;
        const mode = table.getAttribute(ATTR.READY);

        if (mode === 'server') {
            const params = new URLSearchParams(window.location.search);
            const key    = th.getAttribute(ATTR.KEY);
            const dir    = params.get('orderby') === key && (params.get('order') || 'asc') === 'asc' ? 'desc' : 'asc';
            params.set('orderby', key);
            params.set('order', dir);
            params.delete('paged');
            window.location.search = params.toString();
            return;
        }

        if (mode !== 'client') return;
        const index = Array.from(th.parentNode.cells).indexOf(th);
        const prev  = state.get(table);
        const dir   = prev && prev.index === index && prev.dir === 'asc' ? 'desc' : 'asc';
        state.set(table, { index, dir });
        setIndicator(table, th, dir);
        sortClient(table, index, dir);
    }

    /* ── Events ──────────────────────────────────────────────────────────── */

    function autoHeading(target) {
        if (!(target instanceof Element)) return null;
        if (target.closest('input, select, button, a, label, textarea')) return null;
        return target.closest('th.' + CSS.AUTO);
    }

    document.addEventListener('click', function (e) {
        const th = autoHeading(e.target);
        if (th) onActivate(th);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const th = autoHeading(e.target);
        if (!th) return;
        e.preventDefault();
        onActivate(th);
    });

    /* ── Discovery: now, and for tables added or re-rendered later ────────── */

    let scheduled = false;
    const resort = new Set();

    function scan() {
        scheduled = false;
        document.querySelectorAll('body.mmi-page table').forEach(setup);
        resort.forEach(function (table) {
            const s = state.get(table);
            const th = s && headerCells(table)[s.index];
            if (th) {
                setIndicator(table, th, s.dir);
                sortClient(table, s.index, s.dir);
            }
        });
        resort.clear();
    }

    function schedule() {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(scan);
    }

    function start() {
        if (!document.body || !document.body.classList.contains('mmi-page')) return;
        scan();
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                const table = m.target instanceof Element ? m.target.closest('table') : null;
                // A table whose rows were replaced (AJAX re-render) keeps its sort.
                if (table && !reordering && state.has(table) && m.target.closest('tbody')) resort.add(table);
            });
            schedule();
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    window.MMITableSort = { refresh: schedule };
}());
