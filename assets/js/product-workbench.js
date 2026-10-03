/**
 * Product Workbench — find products, change them in bulk, undo.
 *
 * Conditions use the shared rule builder (rule-builder.js). Search results
 * are paged server-side (mmi_workbench_search); an apply is a server job the
 * page drives one batch per request (mmi_workbench_run) so no request runs
 * long and a closed tab leaves a resumable job, not a half-known state.
 *
 * Selection is either explicit product IDs, or "every product matching the
 * last search" minus any unticked — the server re-matches that set when the
 * job starts, so a 5,000-product selection never travels as 5,000 IDs.
 */

(function($) {
    'use strict';

    const RB = window.MMIRuleBuilder;

    /* ── Selectors ──────────────────────────────────────────────────────── */

    const SELECTORS = {
        ROOT:               '#mmi-workbench',
        EDIT_TERMS:         '.mmi-wb-edit-terms',
        TERM_EDITOR_TR:     '.mmi-wb-term-editor-tr',
        TERM_SEARCH:        '.mmi-wb-term-search',
        TERM_OPTION:        '.mmi-wb-term-option',
        TERM_SAVE:          '.mmi-wb-term-save',
        TERM_CANCEL:        '.mmi-wb-term-cancel',
        TERM_NOTICE:        '.mmi-wb-term-notice',
        QUERY:              '#mmi-wb-q',
        QUERY_CASE:         '#mmi-wb-q-case',
        QUERY_IN:           '#mmi-wb-q-in',
        SUPPLIER:           '#mmi-wb-supplier',
        STATUS_CB:          '.mmi-wb-status',
        STOCK_CB:           '.mmi-wb-stock',
        HEALTH_CB:          '.mmi-wb-health',
        HEALTH_MODE:        '#mmi-wb-health-mode',
        FILTER_MS:          '.mmi-wb-filter',
        FILTER_TRIGGER:     '.mmi-wb-filter .mmi-ms-trigger',
        FILTERS_CLEAR:      '#mmi-wb-filters-clear',
        HEALTH_CHIP:        '.mmi-wb-col-health .mmi-health-chip',
        MATCH_LOGIC:        '#mmi-wb-match-logic',
        CONDITIONS:         '#mmi-wb-conditions',
        FIND_SECTION:       '#mmi-wb-find',
        CONDITION_BUILDER:  '#mmi-wb-cb',
        SEARCH_BTN:         '#mmi-wb-search',
        RESET_BTN:          '#mmi-wb-reset',
        STALE_NOTE:         '#mmi-wb-stale',
        SAVE_SEARCH_BTN:    '#mmi-wb-save-search',
        SAVE_SEARCH_FORM:   '#mmi-wb-save-search-form',
        SAVE_SEARCH_NAME:   '#mmi-wb-save-search-name',
        SAVE_SEARCH_OK:     '#mmi-wb-save-search-confirm',
        SAVE_SEARCH_CANCEL: '#mmi-wb-save-search-cancel',

        EMPTY:              '#mmi-wb-empty',
        RESULTS:            '#mmi-wb-results',
        FACETS:             '#mmi-wb-facets',
        FACET:              '.mmi-wb-facet',
        COUNT:              '#mmi-wb-count',
        SELECTED:           '#mmi-wb-selected',
        SELECT_ALL_MATCHING:'#mmi-wb-select-all-matching',
        CLEAR_SELECTION:    '#mmi-wb-clear-selection',
        TABLE:              '#mmi-wb-table',
        ROWS:               '#mmi-wb-rows',
        ROW_CB:             '.mmi-wb-row-cb',
        SELECT_PAGE:        '#mmi-wb-select-page',
        PER_PAGE:           '#mmi-wb-per-page',
        SORTABLE_TH:        '.mmi-wb-sortable',
        PAGER:              '#mmi-wb-pager',

        ACTION_SCOPE:       '#mmi-wb-action-scope',
        ACTION:             '#mmi-wb-action',
        PREVIEW_BTN:        '#mmi-wb-preview',
        APPLY_BTN:          '#mmi-wb-apply',
        TARGET:             '#mmi-wb-target',
        PROGRESS:           '#mmi-wb-progress',
        PROGRESS_FILL:      '#mmi-wb-progress-fill',
        PROGRESS_TEXT:      '#mmi-wb-progress-text',
        STOP_BTN:           '#mmi-wb-stop',
        MESSAGE:            '#mmi-wb-message',
        PREVIEW_OUT:        '#mmi-wb-preview-out',

        JOBS_ROWS:          '#mmi-wb-jobs-rows',
        JOB_SORTABLE_TH:    '.mmi-wb-job-sortable',
        JOB_UNDO:           '.mmi-wb-job-undo',
        JOB_RESUME:         '.mmi-wb-job-resume',
    };

    const CSS = {
        HIDDEN:      'mmi-hidden',
        IS_LOADING:  'mmi-is-loading',
        SORT_ACTIVE: 'is-active',
        SORT_DESC:   'is-desc',
        FACET_ON:    'is-active',
        MSG_ERROR:   'is-error',
        MSG_OK:      'is-success',
    };

    const DEFAULT_PER_PAGE = 50; // matches the Per page select's server-rendered default
    const STATUS_LABELS = { publish: 'Published', draft: 'Draft', pending: 'Pending', private: 'Private' };
    const STOCK_LABELS  = { instock: 'In stock', outofstock: 'Out of stock', onbackorder: 'Backorder' };
    const JOB_STATUS_LABELS = {
        running: 'Running', done: 'Done', stopped: 'Stopped', undoing: 'Undoing', undone: 'Undone',
    };

    const LABELS = {
        SEARCH_IDLE:    '<span class="dashicons dashicons-search"></span> Search',
        SEARCH_RUNNING: '<span class="mmi-loading"></span> Searching…',
        PREVIEW_IDLE:   '<span class="dashicons dashicons-visibility"></span> Preview',
        PREVIEW_RUNNING:'<span class="mmi-loading"></span> Checking…',
        APPLY_IDLE:     '<span class="dashicons dashicons-yes"></span> Apply',
        APPLY_RUNNING:  '<span class="mmi-loading"></span> Applying…',
    };

    // Taxonomies the results table edits in place (Product_Workbench::EDITABLE_TAXONOMIES).
    const TERM_EDIT = {
        product_cat:   { label: 'Categories', hierarchical: true },
        product_brand: { label: 'Brand',      hierarchical: false },
    };
    const NOTICE_MS = 6000;

    const MESSAGES = {
        TERMS_LOADING:    'Loading…',
        TERMS_SEARCH:     'Search %label%…',
        TERMS_TITLE:      '%label% for “%title%”',
        TERMS_HINT:       'Saving locks %label% against imports, so the next supplier import keeps your choice. Undo from Recent changes.',
        TERMS_SAVED:      '%label% saved. Undo it from Recent changes.',
        TERMS_NO_CAT:     'Choose at least one category.',
        TERMS_LOCKED:     'Locked against imports',
        NOTHING_SELECTED: 'Nothing selected yet.',
        TARGET_IDS:       '%count% selected product%s% will be checked.',
        TARGET_FILTER:    'All %count% product%s% matching the search will be checked%excl%.',
        CONFIRM_APPLY:    '%summary%\n\nApply to %count% product%s%? You can undo this from Recent changes.',
        CONFIRM_UNDO:     'Undo "%summary%"?\n\nEach product gets its previous value back, unless it has been edited again since.',
        INCOMPLETE:       'Condition %n%: pick a Source and a Field, or remove it.',
        SAVED_SEARCH:     'Saved “%name%”. Load it from Load saved conditions, here or in Catalog Maintenance.',
        NETWORK:          'Network error — please try again.',
        DONE:             'Done: %changed% changed, %unchanged% already matched%failed%.',
        STOPPED:          'Stopped after %cursor% of %total%. Resume it from Recent changes.',
        UNDONE:           'Undone: %undone% restored%conflicts%.',
    };

    /* ── State ──────────────────────────────────────────────────────────── */

    const state = {
        filter:     null,   // the filter of the last search (what "all matching" means)
        filterText: '',
        total:      0,
        page:       1,
        perPage:    DEFAULT_PER_PAGE,
        sort:       'title',
        dir:        'asc',
        pageIds:    [],
        mode:       'ids',  // 'ids' | 'filter'
        ids:        new Set(),
        exclude:    new Set(),
        running:    null,   // job id while an apply/undo loop is in progress
        stopRequested: false,
        checks:     {},     // health check id => {chip, label}, from the last search
        rowsById:   {},     // the rows on screen, by product id (for the row editor)
    };
    let pager = null;

    // Recent changes: ≤30 rows, all in hand — sorted in memory, never re-fetched.
    const jobsView = { rows: [], sort: 'created_at', dir: 'desc' };
    const JOB_NUMERIC = [ 'changed' ];

    /* ── Helpers ────────────────────────────────────────────────────────── */

    function ajaxUrl() { return window.mmiRuleBuilder.ajaxUrl; }
    function nonce()   { return window.mmiRuleBuilder.nonce; }
    function esc(str)  { return window.MMIEscapeHtml(str == null ? '' : String(str)); }

    function fill(template, values) {
        return template.replace(/%(\w+)%/g, (m, key) => (key in values ? values[key] : m));
    }
    function plural(n) { return n === 1 ? '' : 's'; }
    function num(n)    { return Number(n).toLocaleString(); }

    function post(action, data) {
        return $.post(ajaxUrl(), Object.assign({ action, nonce: nonce() }, data || {}));
    }

    function showMessage(text, kind) {
        $(SELECTORS.MESSAGE).text(text || '')
            .toggleClass(CSS.MSG_ERROR, kind === 'error')
            .toggleClass(CSS.MSG_OK, kind === 'ok');
    }

    /* ── Filter ─────────────────────────────────────────────────────────── */

    function checkedValues(selector) {
        return $(selector + ':checked').map(function() { return this.value; }).get();
    }

    function collectFilter() {
        const statuses = checkedValues(SELECTORS.STATUS_CB);
        return {
            stock:       checkedValues(SELECTORS.STOCK_CB),
            health:      checkedValues(SELECTORS.HEALTH_CB),
            health_mode: $(SELECTORS.HEALTH_MODE).val(),
            q:           $(SELECTORS.QUERY).val().trim(),
            q_case:      $(SELECTORS.QUERY_CASE).is(':checked') ? 1 : 0,
            q_in:        $(SELECTORS.QUERY_IN).val(),
            supplier:    $(SELECTORS.SUPPLIER).val(),
            match_logic: $(SELECTORS.MATCH_LOGIC).val(),
            statuses:    statuses.length ? statuses : [ 'publish' ],
            conditions:  RB.collectConditions($(SELECTORS.CONDITIONS)),
        };
    }

    /** The first incomplete condition's 1-based position, or 0. */
    function firstIncompleteCondition() {
        let pos = 0;
        $(SELECTORS.CONDITIONS).find(RB.SELECTORS.CONDITION_ROW).each(function(i) {
            if (!RB.isConditionComplete($(this))) { pos = i + 1; return false; }
            return true;
        });
        return pos;
    }

    /** Readable one-liner of a filter, stored with each job. */
    function describeFilter(f) {
        const parts = [];
        if (f.q) {
            const where = { title_sku: 'Title/SKU', title: 'Title', sku: 'SKU' }[f.q_in];
            parts.push(`${where} contains "${f.q}"${f.q_case ? ' (match case)' : ''}`);
        }
        $(SELECTORS.CONDITIONS).find(RB.SELECTORS.CONDITION_ROW).each(function() {
            const $r = $(this);
            if (!RB.isConditionComplete($r)) return;
            const field = $r.find(RB.SELECTORS.COND_FIELD_SEL + ' option:selected').text();
            // Option text without its leading symbol ("= equals" → "equals").
            const op    = $r.find(RB.SELECTORS.COND_OPERATOR_SEL + ' option:selected').text().replace(/^[=≠<>]\s*/, '');
            const $pick = $r.find(RB.SELECTORS.COND_VALUE_SELECT + ':not(.' + CSS.HIDDEN + ') option:selected');
            const val   = $pick.length && $r.find(RB.SELECTORS.COND_OPERATOR_SEL).val() === 'matches_pattern'
                ? $pick.text() : $r.find(RB.SELECTORS.COND_VALUE_SEL).val();
            const join  = parts.length && $r.find(RB.SELECTORS.COND_JOIN).attr('aria-pressed') === 'true' ? 'or ' : '';
            parts.push(`${join}${field} ${op} ${val ? `"${val}"` : ''}`.trim());
        });
        if (parts.length > 1 && f.match_logic === 'any') parts[0] = `Any of: ${parts[0]}`;
        if (f.supplier !== 'all') parts.push(`Supplier: ${$(SELECTORS.SUPPLIER + ' option:selected').text()}`);
        parts.push(f.statuses.map((s) => STATUS_LABELS[s] || s).join(', '));
        if (f.stock.length) parts.push(f.stock.map((s) => STOCK_LABELS[s] || s).join(', '));
        if (f.health.length) {
            const names = f.health.map((id) => $(SELECTORS.HEALTH_CB + '[value="' + id + '"]').closest('label').text().trim());
            parts.push({ any: 'Fails any of: ', all: 'Fails all of: ', none: 'Passes: ' }[f.health_mode] + names.join(', '));
        }
        return parts.join(' · ');
    }

    /* ── Filter dropdowns (shared .mmi-multiselect markup) ───────────────── */

    /** Trigger text: the filter name, the one ticked option, or "Name (n)". */
    function renderFilterLabel($ms) {
        const $on   = $ms.find('input[type=checkbox]:checked');
        const name  = $ms.data('label');
        const label = $on.length === 1 ? `${name}: ${$on.closest('label').text().trim()}` : name;
        $ms.find('.mmi-ms-label').text(label);
        $ms.find('.mmi-ms-badge').text($on.length).prop('hidden', $on.length < 2);
        $ms.toggleClass('has-value', $on.length > 0);
    }

    function closeFilterMenus($except) {
        $(SELECTORS.FILTER_MS).not($except || []).removeClass('is-open')
            .find('.mmi-ms-menu').prop('hidden', true).end()
            .find('.mmi-ms-trigger').attr('aria-expanded', 'false');
    }

    function initFilterMenus() {
        $(SELECTORS.FILTER_MS).each(function() { renderFilterLabel($(this)); });
        $(document)
            .on('click', SELECTORS.FILTER_TRIGGER, function(e) {
                e.stopPropagation();
                const $ms  = $(this).closest(SELECTORS.FILTER_MS);
                const open = !$ms.hasClass('is-open');
                closeFilterMenus($ms);
                $ms.toggleClass('is-open', open).find('.mmi-ms-menu').prop('hidden', !open);
                $(this).attr('aria-expanded', open ? 'true' : 'false');
            })
            .on('click', function(e) {
                if (!$(e.target).closest(SELECTORS.FILTER_MS).length) closeFilterMenus();
            })
            .on('keydown', function(e) { if (e.key === 'Escape') closeFilterMenus(); })
            .on('change', SELECTORS.FILTER_MS + ' input[type=checkbox]', function() {
                renderFilterLabel($(this).closest(SELECTORS.FILTER_MS));
            })
            .on('click', SELECTORS.FILTERS_CLEAR, function() {
                $(SELECTORS.STOCK_CB + ', ' + SELECTORS.HEALTH_CB).prop('checked', false);
                $(SELECTORS.STATUS_CB).each(function() { this.checked = this.value === 'publish' || this.value === 'draft'; });
                $(SELECTORS.HEALTH_MODE).val('any');
                $(SELECTORS.FILTER_MS).each(function() { renderFilterLabel($(this)); });
                markStale(true);
            })
            // A Health chip filters to products failing that check.
            .on('click', SELECTORS.HEALTH_CHIP, function() {
                const id = $(this).data('check');
                if (!id) return;
                $(SELECTORS.HEALTH_CB).each(function() { this.checked = this.value === id; });
                $(SELECTORS.HEALTH_MODE).val('any');
                renderFilterLabel($('#mmi-wb-health-ms'));
                search(true);
            });
    }

    /** A passing notice beside the condition builder's buttons. */
    function searchNotice(text) {
        window.MMIConditionBuilder.showNotice($(SELECTORS.CONDITION_BUILDER), text);
    }

    function markStale(stale) {
        $(SELECTORS.STALE_NOTE).toggleClass(CSS.HIDDEN, !stale || state.filter === null);
    }

    /* ── Search + results ───────────────────────────────────────────────── */

    function search(fresh) {
        if (fresh) {
            const bad = firstIncompleteCondition();
            if (bad) {
                searchNotice(fill(MESSAGES.INCOMPLETE, { n: bad }));
                return;
            }
            state.filter     = collectFilter();
            state.filterText = describeFilter(state.filter);
            state.page       = 1;
            clearSelection();
            markStale(false);
            $(SELECTORS.PREVIEW_OUT).addClass(CSS.HIDDEN).empty();
        }
        if (!state.filter) return;

        const $btn = $(SELECTORS.SEARCH_BTN);
        $btn.prop('disabled', true).addClass(CSS.IS_LOADING).html(LABELS.SEARCH_RUNNING);
        $(SELECTORS.ROWS).addClass('mmi-wb-loading');

        post('mmi_workbench_search', {
            filter: state.filter, page: state.page, per_page: state.perPage,
            sort: state.sort, dir: state.dir, fresh: fresh ? 1 : 0,
        }).done(function(resp) {
            if (!resp.success) { searchNotice((resp.data && resp.data.message) || MESSAGES.NETWORK); return; }
            renderResults(resp.data);
        }).fail(function() {
            searchNotice(MESSAGES.NETWORK);
        }).always(function() {
            $btn.prop('disabled', false).removeClass(CSS.IS_LOADING).html(LABELS.SEARCH_IDLE);
            $(SELECTORS.ROWS).removeClass('mmi-wb-loading');
        });
    }

    function renderResults(data) {
        state.rowsById = {};
        (data.rows || []).forEach((r) => { state.rowsById[r.id] = r; });
        state.checks  = data.checks || {};
        state.total   = data.total;
        state.page    = data.page;
        state.pageIds = data.rows.map((r) => r.id);

        $(SELECTORS.EMPTY).toggleClass(CSS.HIDDEN, true);
        $(SELECTORS.RESULTS).removeClass(CSS.HIDDEN);
        $(SELECTORS.COUNT).text(`${num(data.total)} product${plural(data.total)} found`);

        renderFacets(data.facets);
        renderRows(data.rows);

        if (!pager) {
            // Page size lives in the selection toolbar (#mmi-wb-per-page), not
            // the pager: the pager rebuilds its container on every page change.
            pager = window.MMIPagination.init({
                container: SELECTORS.PAGER,
                totalPages: data.pages,
                page: data.page,
                onPageChange: function(page) { state.page = page; search(false); },
            });
        } else {
            pager.setTotalPages(data.pages);
            pager.setPage(data.page);
        }
        renderSortState();
        renderSelection();
    }

    function renderFacets(facets) {
        const active = activeCategoryFacets();
        const html = (facets || []).map((f) => {
            const on = active.indexOf(f.name) !== -1 ? ` ${CSS.FACET_ON}` : '';
            return `<button type="button" class="mmi-wb-facet${on}" data-name="${esc(f.name)}" data-none="${f.id === 0 ? 1 : 0}"
                title="Narrow to products in ${esc(f.name)}"><span>${esc(f.name)}</span> <b>${num(f.count)}</b></button>`;
        }).join('');
        $(SELECTORS.FACETS).html(html ? `<span class="mmi-wb-label">Categories in results</span>${html}` : '');
    }

    /** Category names already used as `product_cat equals …` conditions. */
    function activeCategoryFacets() {
        return ((state.filter && state.filter.conditions) || [])
            .filter((c) => c.source === 'wp_taxonomy' && c.field === 'product_cat' && c.operator === 'equals')
            .map((c) => c.value);
    }

    function renderRows(rows) {
        if (!rows.length) {
            $(SELECTORS.ROWS).html('<tr><td colspan="11" class="mmi-wb-empty-cell">No products match.</td></tr>');
            return;
        }
        $(SELECTORS.ROWS).html(rows.map(rowHtml).join(''));
    }

    /** A Categories/Brand cell: its terms, a lock mark, and the edit button. */
    function termCell(r, taxonomy, terms) {
        const names = taxonomy === 'product_cat'
            ? terms.map((t) => `<span class="mmi-wb-term">${esc(t.name)}</span>`).join('')
            : terms.map((t) => esc(t.name)).join(', ');
        const lock  = (r.locked || []).indexOf(taxonomy) !== -1
            ? `<span class="dashicons dashicons-lock mmi-wb-term-lock" title="${MESSAGES.TERMS_LOCKED}" aria-label="${MESSAGES.TERMS_LOCKED}"></span>` : '';
        const label = TERM_EDIT[taxonomy].label;
        return `<span class="mmi-wb-term-names">${names || '<span class="mmi-wb-none">—</span>'}</span>${lock}`
            + `<button type="button" class="mmi-wb-edit-terms" data-tax="${taxonomy}" title="Change ${label.toLowerCase()}" aria-label="Change ${label.toLowerCase()}"><span class="dashicons dashicons-edit"></span></button>`;
    }

    function rowHtml(r) {
        const cats   = termCell(r, 'product_cat', r.categories);
        const brands = termCell(r, 'product_brand', r.brands);
        const status = `<span class="mmi-badge ${r.status === 'publish' ? 'success' : 'warning'} inline">${esc(STATUS_LABELS[r.status] || r.status)}</span>`;
        const thumb  = r.thumb ? `<img src="${esc(r.thumb)}" alt="" loading="lazy">` : '<span class="mmi-wb-no-thumb" title="No featured image"></span>';
        const health = (r.health || []).map((id) => {
            const c = state.checks[id] || { chip: id, label: id };
            return `<button type="button" class="mmi-health-chip" data-check="${esc(id)}" title="${esc(c.label)} — click to show only these">${esc(c.chip)}</button>`;
        }).join('') || '<span class="mmi-wb-health-ok">OK</span>';
        return `<tr data-id="${r.id}">
            <th scope="row" class="check-column"><input type="checkbox" class="mmi-wb-row-cb" value="${r.id}" aria-label="Select ${esc(r.title)}"></th>
            <td class="mmi-wb-col-id"><a href="${esc(r.view_url)}" target="_blank" rel="noopener">${r.id}</a></td>
            <td class="mmi-wb-col-thumb">${thumb}</td>
            <td class="mmi-wb-col-title"><a href="${esc(r.edit_url)}" target="_blank" rel="noopener">${esc(r.title)}</a></td>
            <td class="mmi-wb-col-sku">${esc(r.sku)}</td>
            <td class="mmi-wb-col-cats">${cats}</td>
            <td class="mmi-wb-col-brand">${brands}</td>
            <td class="mmi-wb-col-status">${status}</td>
            <td class="mmi-wb-col-stock">${esc(STOCK_LABELS[r.stock] || r.stock)}</td>
            <td class="mmi-wb-col-supplier">${r.supplier === 'all' ? '<span class="mmi-wb-none">—</span>' : esc(r.supplier)}</td>
            <td class="mmi-wb-col-health">${health}</td>
        </tr>`;
    }

    /* ── Row edit: categories / brand ───────────────────────────────────── */

    const termsCache = {}; // taxonomy => [{id, name, depth, path}], tree order

    /** Terms in tree order, each with its depth and "Parent › Child" path. */
    function termTree(terms, hierarchical) {
        if (!hierarchical) {
            return terms.map((t) => ({ id: t.id, name: t.name, depth: 0, path: t.name }));
        }
        const kids = {};
        terms.forEach((t) => { (kids[t.parent || 0] = kids[t.parent || 0] || []).push(t); });
        const out  = [];
        const walk = (parent, depth, path) => (kids[parent] || []).forEach((t) => {
            const p = path ? `${path} › ${t.name}` : t.name;
            out.push({ id: t.id, name: t.name, depth, path: p });
            walk(t.id, depth + 1, p);
        });
        walk(0, 0, '');
        return out;
    }

    function loadTerms(taxonomy) {
        if (termsCache[taxonomy]) return $.Deferred().resolve(termsCache[taxonomy]).promise();
        return post('mmi_pipeline_get_taxonomy_terms', { taxonomy }).then(function(resp) {
            if (!resp.success) return $.Deferred().reject((resp.data && resp.data.message) || MESSAGES.NETWORK).promise();
            termsCache[taxonomy] = termTree(resp.data.terms || [], TERM_EDIT[taxonomy].hierarchical);
            return termsCache[taxonomy];
        });
    }

    function closeTermEditor() {
        $(SELECTORS.TERM_EDITOR_TR).remove();
    }

    /** Open the picker for one product's categories or brand, under its row. */
    function openTermEditor($btn) {
        const $tr      = $btn.closest('tr[data-id]');
        const id       = parseInt($tr.data('id'), 10);
        const taxonomy = String($btn.data('tax'));
        const label    = TERM_EDIT[taxonomy].label;
        const reopen   = $tr.next(SELECTORS.TERM_EDITOR_TR).data('tax') === taxonomy;
        closeTermEditor();
        if (reopen) return; // a second click on the same button closes it

        const row      = state.rowsById[id] || {};
        const current  = (taxonomy === 'product_cat' ? row.categories : row.brands) || [];
        const selected = new Set(current.map((t) => t.id));
        const cols     = $(SELECTORS.TABLE).find('thead tr').first().children().length;
        const $editor  = $(`<tr class="mmi-wb-term-editor-tr" data-tax="${taxonomy}"><td colspan="${cols}"><div class="mmi-wb-term-editor">
            <div class="mmi-wb-term-editor-head">
                <strong>${esc(fill(MESSAGES.TERMS_TITLE, { label, title: row.title || `#${id}` }))}</strong>
                <input type="search" class="mmi-wb-term-search" placeholder="${esc(fill(MESSAGES.TERMS_SEARCH, { label: label.toLowerCase() }))}" aria-label="${esc(fill(MESSAGES.TERMS_SEARCH, { label: label.toLowerCase() }))}">
                <button type="button" class="button button-primary button-small mmi-wb-term-save">Save</button>
                <button type="button" class="button-link mmi-wb-term-cancel">Cancel</button>
            </div>
            <p class="mmi-wb-term-hint">${esc(fill(MESSAGES.TERMS_HINT, { label: label.toLowerCase() }))}</p>
            <div class="mmi-wb-term-list" role="group" aria-label="${esc(label)}"><p class="mmi-wb-none">${MESSAGES.TERMS_LOADING}</p></div>
            <span class="mmi-wb-term-notice" role="status"></span>
        </div></td></tr>`).data({ id, taxonomy });
        $tr.after($editor);

        loadTerms(taxonomy).done(function(terms) {
            // Ticked terms first, so the current choice is visible without scrolling.
            const ordered = terms.filter((t) => selected.has(t.id)).concat(terms.filter((t) => !selected.has(t.id)));
            $editor.find('.mmi-wb-term-list').html(ordered.map((t) => `<label class="mmi-wb-term-option" style="--depth: ${selected.has(t.id) ? 0 : t.depth}" title="${esc(t.path)}" data-path="${esc(t.path.toLowerCase())}">
                <input type="checkbox" value="${t.id}"${selected.has(t.id) ? ' checked' : ''}> <span>${esc(t.name)}</span>${t.depth ? `<span class="mmi-wb-term-path">${esc(t.path.split(' › ').slice(0, -1).join(' › '))}</span>` : ''}
            </label>`).join(''));
            $editor.find(SELECTORS.TERM_SEARCH).trigger('focus');
        }).fail(function(msg) {
            $editor.find('.mmi-wb-term-list').html(`<p class="mmi-wb-none">${esc(typeof msg === 'string' ? msg : MESSAGES.NETWORK)}</p>`);
        });
    }

    function termNotice($editor, text) {
        $editor.find(SELECTORS.TERM_NOTICE).text(text);
    }

    function saveTerms($editor) {
        const id       = $editor.data('id');
        const taxonomy = $editor.data('taxonomy');
        const termIds  = $editor.find('.mmi-wb-term-list input:checked').map(function() { return this.value; }).get();
        if (taxonomy === 'product_cat' && !termIds.length) { termNotice($editor, MESSAGES.TERMS_NO_CAT); return; }
        const $save = $editor.find(SELECTORS.TERM_SAVE).prop('disabled', true);
        post('mmi_workbench_set_terms', { product_id: id, taxonomy, term_ids: termIds })
            .done(function(resp) {
                if (!resp.success) { termNotice($editor, (resp.data && resp.data.message) || MESSAGES.NETWORK); return; }
                // Replace the row in place: it stays visible even if it no longer
                // matches the search, so you can see what you just did.
                const row  = resp.data.row;
                state.rowsById[row.id] = row;
                const $old = $(SELECTORS.ROWS).find(`tr[data-id="${row.id}"]`);
                const $new = $(rowHtml(row));
                $old.replaceWith($new);
                closeTermEditor();
                renderSelection();
                const cols    = $(SELECTORS.TABLE).find('thead tr').first().children().length;
                const $notice = $(`<tr class="mmi-wb-row-notice"><td colspan="${cols}">${esc(fill(MESSAGES.TERMS_SAVED, { label: TERM_EDIT[taxonomy].label }))}</td></tr>`);
                $new.after($notice);
                setTimeout(() => $notice.remove(), NOTICE_MS);
                markStale(true);
                loadJobs();
            })
            .fail(() => termNotice($editor, MESSAGES.NETWORK))
            .always(() => $save.prop('disabled', false));
    }

    function renderSortState() {
        $(SELECTORS.SORTABLE_TH).each(function() {
            const on = $(this).data('col') === state.sort;
            $(this).toggleClass(CSS.SORT_ACTIVE, on).toggleClass(CSS.SORT_DESC, on && state.dir === 'desc')
                .attr('aria-sort', on ? (state.dir === 'desc' ? 'descending' : 'ascending') : 'none');
        });
    }

    /* ── Selection ──────────────────────────────────────────────────────── */

    function isSelected(id) {
        return state.mode === 'filter' ? !state.exclude.has(id) : state.ids.has(id);
    }

    function selectedCount() {
        return state.mode === 'filter' ? state.total - state.exclude.size : state.ids.size;
    }

    function clearSelection() {
        state.mode = 'ids';
        state.ids.clear();
        state.exclude.clear();
        renderSelection();
    }

    function setRowSelected(id, on) {
        if (state.mode === 'filter') {
            if (on) { state.exclude.delete(id); } else { state.exclude.add(id); }
        } else if (on) {
            state.ids.add(id);
        } else {
            state.ids.delete(id);
        }
    }

    function renderSelection() {
        const n = selectedCount();
        $(SELECTORS.ROWS).find(SELECTORS.ROW_CB).each(function() {
            $(this).prop('checked', isSelected(parseInt(this.value, 10)));
        });
        const pageAll = state.pageIds.length > 0 && state.pageIds.every(isSelected);
        $(SELECTORS.SELECT_PAGE).prop('checked', pageAll);

        $(SELECTORS.SELECTED).text(n ? `${num(n)} selected` : '');
        const showAllMatching = state.mode !== 'filter' && pageAll && state.total > state.pageIds.length;
        $(SELECTORS.SELECT_ALL_MATCHING).toggleClass(CSS.HIDDEN, !showAllMatching)
            .text(`Select all ${num(state.total)} matching products`);
        $(SELECTORS.CLEAR_SELECTION).toggleClass(CSS.HIDDEN, n === 0);

        let target = MESSAGES.NOTHING_SELECTED;
        if (n && state.mode === 'filter') {
            const excl = state.exclude.size ? `, except ${num(state.exclude.size)} unticked` : '';
            target = fill(MESSAGES.TARGET_FILTER, { count: num(state.total), s: plural(state.total), excl });
        } else if (n) {
            target = fill(MESSAGES.TARGET_IDS, { count: num(n), s: plural(n) });
        }
        $(SELECTORS.TARGET).text(target);
        const busy = state.running !== null;
        $(SELECTORS.PREVIEW_BTN).prop('disabled', !n || busy);
        $(SELECTORS.APPLY_BTN).prop('disabled', !n || busy);
    }

    function selectionPayload() {
        if (state.mode === 'filter') {
            return { mode: 'filter', filter: state.filter, exclude: Array.from(state.exclude) };
        }
        return { mode: 'ids', ids: Array.from(state.ids) };
    }

    /* ── Action ─────────────────────────────────────────────────────────── */

    function actionPayload() {
        const $scope = $(SELECTORS.ACTION_SCOPE);
        return { wb_action: $(SELECTORS.ACTION).val(), params: RB.collectActionParams($scope) };
    }

    function preview() {
        const $btn = $(SELECTORS.PREVIEW_BTN);
        $btn.prop('disabled', true).html(LABELS.PREVIEW_RUNNING);
        showMessage('', '');
        post('mmi_workbench_preview', Object.assign({ selection: selectionPayload() }, actionPayload()))
            .done(function(resp) {
                if (!resp.success) { showMessage(resp.data && resp.data.message, 'error'); return; }
                renderPreview(resp.data);
            })
            .fail(() => showMessage(MESSAGES.NETWORK, 'error'))
            .always(function() { $btn.html(LABELS.PREVIEW_IDLE); renderSelection(); });
    }

    function renderPreview(d) {
        const rows = d.sample.map((s) => `<tr>
            <td><a href="post.php?post=${s.id}&action=edit" target="_blank" rel="noopener">${esc(s.title)}</a></td>
            <td class="mmi-wb-before">${esc(s.before)}</td>
            <td class="mmi-wb-arrow" aria-hidden="true">→</td>
            <td class="mmi-wb-after">${esc(s.after)}</td>
        </tr>`).join('');
        const more = d.will_change > d.sample.length ? `<p class="mmi-wb-more">…and ${num(d.will_change - d.sample.length)} more.</p>` : '';
        $(SELECTORS.PREVIEW_OUT).removeClass(CSS.HIDDEN).html(`
            <p class="mmi-wb-preview-summary"><strong>${esc(d.summary)}</strong>:
                ${num(d.will_change)} of ${num(d.selected)} would change; ${num(d.unchanged)} already match.</p>
            ${rows ? `<div class="mmi-table-scroll-wrapper"><table class="widefat striped mmi-wb-preview-table">
                <thead><tr><th>Product</th><th>Now</th><th></th><th>After</th></tr></thead><tbody>${rows}</tbody></table></div>${more}` : ''}`);
    }

    function apply() {
        const n = selectedCount();
        const summary = `${$(SELECTORS.ACTION + ' option:selected').text()}${paramsHint()}`;
        if (!window.confirm(fill(MESSAGES.CONFIRM_APPLY, { summary, count: num(n), s: plural(n) }))) return;

        showMessage('', '');
        $(SELECTORS.APPLY_BTN).prop('disabled', true).html(LABELS.APPLY_RUNNING);
        post('mmi_workbench_start', Object.assign({ selection: selectionPayload(), filter_label: state.filterText }, actionPayload()))
            .done(function(resp) {
                if (!resp.success) {
                    showMessage(resp.data && resp.data.message, 'error');
                    $(SELECTORS.APPLY_BTN).html(LABELS.APPLY_IDLE);
                    renderSelection();
                    return;
                }
                runLoop(resp.data.job, 'mmi_workbench_run');
            })
            .fail(function() {
                showMessage(MESSAGES.NETWORK, 'error');
                $(SELECTORS.APPLY_BTN).html(LABELS.APPLY_IDLE);
                renderSelection();
            });
    }

    /** " → Software Bundles (Replace)" for a taxonomy action; '' otherwise. */
    function paramsHint() {
        const $scope = $(SELECTORS.ACTION_SCOPE);
        if ($(SELECTORS.ACTION).val() !== 'set_taxonomy_term') return '';
        const term = $scope.find(RB.SELECTORS.TERM_SELECT + ' option:selected').text();
        const mode = $scope.find(RB.SELECTORS.TAX_MODE_SELECT + ' option:selected').text();
        return ` → ${term} (${mode})`;
    }

    /**
     * Drive a job one batch per request until it's done (apply) or undone
     * (undo). The server holds all progress, so Stop just stops asking.
     */
    function runLoop(job, endpoint, firstData) {
        state.running = job.id;
        state.stopRequested = false;
        renderSelection();
        const undo = endpoint === 'mmi_workbench_undo';
        $(SELECTORS.PROGRESS).removeClass(CSS.HIDDEN);
        $(SELECTORS.STOP_BTN).toggleClass(CSS.HIDDEN, undo);
        updateProgress(job, undo);

        let extra = firstData || {};
        const step = function() {
            if (state.stopRequested) {
                post('mmi_workbench_stop', { job_id: job.id }).always(function(resp) {
                    const j = (resp && resp.success && resp.data.job) || job;
                    finish(fill(MESSAGES.STOPPED, { cursor: num(j.cursor), total: num(j.total) }), 'ok');
                });
                return;
            }
            const data = Object.assign({ job_id: job.id }, extra);
            extra = {};
            post(endpoint, data).done(function(resp) {
                if (!resp.success) { finish((resp.data && resp.data.message) || MESSAGES.NETWORK, 'error'); return; }
                const j = resp.data.job;
                updateProgress(j, undo);
                const finished = undo ? j.status === 'undone' : j.status !== 'running';
                if (!finished) { setTimeout(step, resp.data.busy ? 1500 : 0); return; }
                if (undo) {
                    const conflicts = j.conflicts ? `; ${num(j.conflicts)} left alone because they were edited again since` : '';
                    finish(fill(MESSAGES.UNDONE, { undone: num(j.undone), conflicts }), 'ok');
                } else {
                    const failed = j.failed ? `, ${num(j.failed)} failed (see the sync log)` : '';
                    finish(fill(MESSAGES.DONE, { changed: num(j.changed), unchanged: num(j.unchanged), failed }), j.failed ? 'error' : 'ok');
                }
            }).fail(function() {
                finish(MESSAGES.NETWORK + ' The run is saved — resume it from Recent changes.', 'error');
            });
        };
        step();
    }

    function updateProgress(job, undo) {
        const done  = undo ? job.undone + job.conflicts : job.cursor;
        const total = undo ? Math.max(job.changed, 1) : Math.max(job.total, 1);
        const pct   = Math.min(100, Math.round((done / total) * 100));
        $(SELECTORS.PROGRESS_FILL).css('--pct', `${pct}%`);
        $(SELECTORS.PROGRESS_TEXT).text(undo
            ? `Undoing… ${num(done)} of ${num(job.changed)}`
            : `${num(job.cursor)} of ${num(job.total)} · ${num(job.changed)} changed`);
    }

    function finish(message, kind) {
        state.running = null;
        $(SELECTORS.PROGRESS).addClass(CSS.HIDDEN);
        $(SELECTORS.APPLY_BTN).html(LABELS.APPLY_IDLE);
        $(SELECTORS.PREVIEW_OUT).addClass(CSS.HIDDEN).empty();
        showMessage(message, kind);
        loadJobs();
        // The job is finished with these products: drop the selection, or
        // ones that no longer match the search stay selected out of sight
        // and get counted into the next change. A failed run keeps it, so
        // the same products can be retried.
        if (kind !== 'error') clearSelection();
        if (state.filter) search(false); // results now show the new values
        renderSelection();
    }

    /* ── Recent changes ─────────────────────────────────────────────────── */

    function loadJobs() {
        post('mmi_workbench_jobs').done(function(resp) {
            if (!resp.success) return;
            renderJobs(resp.data.jobs);
        });
    }

    function sortJobs(jobs) {
        const key = jobsView.sort;
        const sign = jobsView.dir === 'desc' ? -1 : 1;
        return jobs.slice().sort(function(a, b) {
            const av = a[key], bv = b[key];
            const cmp = JOB_NUMERIC.indexOf(key) !== -1
                ? (Number(av) || 0) - (Number(bv) || 0)
                : String(av || '').localeCompare(String(bv || ''), undefined, { sensitivity: 'base', numeric: true });
            return cmp * sign;
        });
    }

    function renderJobSortState() {
        $(SELECTORS.JOB_SORTABLE_TH).each(function() {
            const on = $(this).data('col') === jobsView.sort;
            $(this).toggleClass(CSS.SORT_ACTIVE, on).toggleClass(CSS.SORT_DESC, on && jobsView.dir === 'desc')
                .attr('aria-sort', on ? (jobsView.dir === 'desc' ? 'descending' : 'ascending') : 'none');
        });
    }

    function renderJobs(jobsIn) {
        jobsView.rows = jobsIn;
        renderJobSortState();
        const jobs = sortJobs(jobsIn);
        if (!jobs.length) {
            $(SELECTORS.JOBS_ROWS).html('<tr><td colspan="6" class="mmi-wb-empty-cell">No changes yet.</td></tr>');
            return;
        }
        $(SELECTORS.JOBS_ROWS).html(jobs.map((j) => {
            let btn = '';
            if (['done', 'stopped'].indexOf(j.status) !== -1 && j.changed > 0) {
                btn += `<button type="button" class="button button-small mmi-wb-job-undo" data-id="${esc(j.id)}" data-summary="${esc(j.summary)}"><span class="dashicons dashicons-undo"></span> Undo</button>`;
            }
            if (j.status === 'undoing') {
                btn += `<button type="button" class="button button-small mmi-wb-job-undo" data-id="${esc(j.id)}" data-summary="${esc(j.summary)}" data-resume="1">Finish undo</button>`;
            }
            if (['running', 'stopped'].indexOf(j.status) !== -1 && j.cursor < j.total) {
                btn += ` <button type="button" class="button button-small mmi-wb-job-resume" data-id="${esc(j.id)}">Resume</button>`;
            }
            const changed = j.status === 'undone'
                ? `${num(j.undone)} restored${j.conflicts ? `, ${num(j.conflicts)} kept` : ''}`
                : `${num(j.changed)} of ${num(j.total)}`;
            const kind = { done: 'success', undone: 'info', running: 'warning', undoing: 'warning', stopped: 'warning' }[j.status] || '';
            return `<tr>
                <td>${esc(j.created_at)}<br><span class="mmi-wb-none">${esc(j.user)}</span></td>
                <td>${esc(j.summary)}</td>
                <td class="mmi-wb-job-filter">${esc(j.filter_label)}</td>
                <td>${changed}</td>
                <td><span class="mmi-badge ${kind} inline">${esc(JOB_STATUS_LABELS[j.status] || j.status)}</span></td>
                <td class="mmi-wb-job-actions">${btn}</td>
            </tr>`;
        }).join(''));
    }

    /* ── Saved searches ─────────────────────────────────────────────────── */

    function toggleSaveForm(open) {
        $(SELECTORS.SAVE_SEARCH_FORM).toggleClass(CSS.HIDDEN, !open);
        $(SELECTORS.SAVE_SEARCH_BTN).toggleClass(CSS.HIDDEN, open);
        if (open) $(SELECTORS.SAVE_SEARCH_NAME).val('').trigger('focus');
    }

    function saveSearch() {
        const $root = $(SELECTORS.CONDITION_BUILDER);
        const bad   = firstIncompleteCondition();
        if (bad) {
            searchNotice(fill(MESSAGES.INCOMPLETE, { n: bad }));
            return;
        }
        const name = $(SELECTORS.SAVE_SEARCH_NAME).val().trim();
        const $ok  = $(SELECTORS.SAVE_SEARCH_OK).prop('disabled', true);
        post('mmi_workbench_save_search', {
            label:       name,
            match_logic: $(SELECTORS.MATCH_LOGIC).val(),
            conditions:  RB.collectConditions($(SELECTORS.CONDITIONS)),
        }).done(function(resp) {
            if (!resp.success) {
                window.MMIConditionBuilder.showNotice($root, (resp.data && resp.data.message) || MESSAGES.NETWORK);
                return;
            }
            window.MMIConditionBuilder.refreshLibrary();
            toggleSaveForm(false);
            window.MMIConditionBuilder.showNotice($root, fill(MESSAGES.SAVED_SEARCH, { name }));
        }).fail(function() {
            window.MMIConditionBuilder.showNotice($root, MESSAGES.NETWORK);
        }).always(function() {
            $ok.prop('disabled', false);
        });
    }

    /* ── Init ───────────────────────────────────────────────────────────── */

    function init() {
        if (!$(SELECTORS.ROOT).length || !RB) return;

        initFilterMenus();
        RB.updateActionParamsVisibility($(SELECTORS.ACTION_SCOPE));
        RB.loadTaxonomyTerms($(SELECTORS.ACTION_SCOPE).find(RB.SELECTORS.PARAM_GROUP).first(), 'product_cat', '');
        loadJobs();

        $(document)
            .on('click', SELECTORS.SEARCH_BTN, () => search(true))
            .on('click', SELECTORS.EDIT_TERMS, function() { openTermEditor($(this)); })
            .on('click', SELECTORS.TERM_CANCEL, closeTermEditor)
            .on('click', SELECTORS.TERM_SAVE, function() { saveTerms($(this).closest(SELECTORS.TERM_EDITOR_TR)); })
            .on('keydown', SELECTORS.TERM_EDITOR_TR, function(e) {
                if (e.key === 'Escape') closeTermEditor();
                if (e.key === 'Enter' && $(e.target).is(SELECTORS.TERM_SEARCH)) { e.preventDefault(); saveTerms($(this)); }
            })
            .on('input', SELECTORS.TERM_SEARCH, function() {
                const q = $(this).val().trim().toLowerCase();
                $(this).closest(SELECTORS.TERM_EDITOR_TR).find(SELECTORS.TERM_OPTION).each(function() {
                    $(this).toggleClass(CSS.HIDDEN, q !== '' && String($(this).data('path')).indexOf(q) === -1);
                });
            })
            .on('click', SELECTORS.SAVE_SEARCH_BTN, () => toggleSaveForm(true))
            .on('click', SELECTORS.SAVE_SEARCH_CANCEL, () => toggleSaveForm(false))
            .on('click', SELECTORS.SAVE_SEARCH_OK, saveSearch)
            .on('keydown', SELECTORS.SAVE_SEARCH_NAME, function(e) {
                if (e.key === 'Enter') { e.preventDefault(); saveSearch(); }
                if (e.key === 'Escape') { toggleSaveForm(false); }
            })
            .on('keydown', SELECTORS.QUERY, function(e) { if (e.key === 'Enter') { e.preventDefault(); search(true); } })
            .on('click', SELECTORS.RESET_BTN, function() {
                $(SELECTORS.QUERY).val('');
                $(SELECTORS.QUERY_CASE).prop('checked', false);
                $(SELECTORS.CONDITIONS).empty();
                window.MMIConditionBuilder.updateEmptyHint($(SELECTORS.CONDITION_BUILDER));
                markStale(true);
            })
            // Add/Remove/Load saved belong to the shared condition builder;
            // any change there marks the results stale.
            .on(RB.CHANGE_EVENT, SELECTORS.CONDITION_BUILDER, () => markStale(true))
            .on('input change', [SELECTORS.QUERY, SELECTORS.QUERY_CASE, SELECTORS.QUERY_IN, SELECTORS.SUPPLIER, SELECTORS.STATUS_CB, SELECTORS.STOCK_CB, SELECTORS.HEALTH_CB, SELECTORS.HEALTH_MODE, SELECTORS.MATCH_LOGIC].join(', '), () => markStale(true))

            // Category chip → narrow to that category (or to uncategorized).
            .on('click', SELECTORS.FACET, function() {
                const $row = RB.appendConditionRow($(SELECTORS.CONDITIONS));
                if (!$row) return;
                const none = String($(this).data('none')) === '1';
                RB.initConditionCascade($row, 'wp_taxonomy', 'product_cat', none ? 'is_empty' : 'equals', none ? '' : String($(this).data('name')));
                $(SELECTORS.MATCH_LOGIC).val('all');
                // The field list loads asynchronously; search once it has.
                const wait = setInterval(function() {
                    if ($row.find(RB.SELECTORS.COND_FIELD_SEL).val()) { clearInterval(wait); search(true); }
                }, 100);
                setTimeout(() => clearInterval(wait), 10000);
            })

            // A resize drag never reaches this: the shared mmi-resize-sort-guard.js swallows it.
            .on('click', SELECTORS.SORTABLE_TH, function() {
                const col = $(this).data('col');
                state.dir  = state.sort === col && state.dir === 'asc' ? 'desc' : 'asc';
                state.sort = col;
                state.page = 1;
                search(false);
            })
            .on('change', SELECTORS.ROW_CB, function() {
                setRowSelected(parseInt(this.value, 10), this.checked);
                renderSelection();
            })
            .on('change', SELECTORS.SELECT_PAGE, function() {
                const on = this.checked;
                state.pageIds.forEach((id) => setRowSelected(id, on));
                renderSelection();
            })
            .on('click', SELECTORS.SELECT_ALL_MATCHING, function() {
                state.mode = 'filter';
                state.exclude.clear();
                renderSelection();
            })
            .on('click', SELECTORS.CLEAR_SELECTION, clearSelection)
            .on('change', SELECTORS.PER_PAGE, function() {
                state.perPage = parseInt($(this).val(), 10) || DEFAULT_PER_PAGE;
                state.page = 1;
                search(false);
            })

            .on('change', SELECTORS.ACTION, function() {
                RB.updateActionParamsVisibility($(SELECTORS.ACTION_SCOPE));
                $(SELECTORS.PREVIEW_OUT).addClass(CSS.HIDDEN).empty();
            })
            .on('change', SELECTORS.ACTION_SCOPE + ' select, ' + SELECTORS.ACTION_SCOPE + ' input', function() {
                $(SELECTORS.PREVIEW_OUT).addClass(CSS.HIDDEN).empty();
            })
            .on('click', SELECTORS.PREVIEW_BTN, preview)
            .on('click', SELECTORS.APPLY_BTN, apply)
            .on('click', SELECTORS.STOP_BTN, function() { state.stopRequested = true; })

            .on('click', SELECTORS.JOB_SORTABLE_TH, function() {
                const col = $(this).data('col');
                jobsView.dir  = jobsView.sort === col && jobsView.dir === 'asc' ? 'desc' : 'asc';
                jobsView.sort = col;
                renderJobs(jobsView.rows);
            })

            .on('click', SELECTORS.JOB_UNDO, function() {
                if (state.running) return;
                const resume = String($(this).data('resume')) === '1';
                if (!resume && !window.confirm(fill(MESSAGES.CONFIRM_UNDO, { summary: $(this).data('summary') }))) return;
                runLoop({ id: String($(this).data('id')), undone: 0, conflicts: 0, changed: 0, cursor: 0, total: 0 }, 'mmi_workbench_undo');
            })
            .on('click', SELECTORS.JOB_RESUME, function() {
                if (state.running) return;
                runLoop({ id: String($(this).data('id')), undone: 0, conflicts: 0, changed: 0, cursor: 0, total: 0 }, 'mmi_workbench_run', { resume: 1 });
            });
    }

    $(init);

}(jQuery));
