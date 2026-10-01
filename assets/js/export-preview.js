/**
 * MMI Data Pipeline — Export Preview Table
 *
 * Renders a live "what will be exported" sample pulled directly from the DB
 * (mmi_generate_export_preview). Deliberately a lighter-weight, standalone
 * renderer rather than a refactor of import-preview.js — that file drives
 * the production Import tab (diffing against existing products, inline
 * edit, column persistence) and must not be touched to avoid any regression
 * risk on the existing import workflow. This preview only needs to answer
 * "what does the data look like," but it still gets the plugin's standard
 * Data Tables treatment (sort, resizable columns) per CLAUDE.md.
 *
 * Only the record's identifier column (`id_field` from the AJAX response,
 * e.g. 'ID'/'term_id'/'comment_ID' depending on data type) is visible by
 * default — every other column is opt-in via the "Columns" popover, the
 * same interaction pattern as mmi-reverb-integration's
 * #mmi-column-customize-btn on tab=products. Reverb's column set is static
 * (hardcoded per-plugin list), so it toggles DOM visibility on server-
 * rendered <th>/<td> elements; this table's column set is dynamic per data
 * type, so instead the "visible" column list is filtered client-side before
 * renderHead()/renderBody() build any markup — no hidden DOM to toggle.
 *
 * The Columns popover doubles as the export field-mapping editor — what
 * used to be a separate "Step 2: Field Mapping" section (its own table,
 * one row per field, Include/Output Column/Transform) was redundant with
 * this popover's own visibility+order controls, and the two could disagree
 * (a field could be enabled for real export but hidden from the preview, or
 * vice versa). Toggling a column's switch here IS the field's real
 * "enabled for export" flag (persisted server-side via
 * mmi_pipeline_save_export_field_mapping / mmi_pipeline_toggle_all_export_fields,
 * the same endpoints Step 2 used) — it is not a separate client-only
 * display preference the way column width/order still are. Each row also
 * carries an output-column rename input and a transform <select>, appended
 * via MMIColumnCustomizer's renderExtra hook. See saveFieldConfig() below.
 *
 * There is no pagination. The table infinite-scrolls: as the user nears the
 * bottom of #mmi-export-preview-table-scroll, the next "Rows per page"
 * batch is fetched (offset-based) and appended, until every record matching
 * the profile's scope has been loaded.
 *
 * Reactivity: loads once automatically when the page is ready — a single
 * request, well inside CLAUDE.md's "max 2 simultaneous AJAX requests on
 * page/panel open" budget (that rule caps concurrent auto-fired requests,
 * it doesn't forbid firing one) — so opening or creating a profile shows
 * real data immediately instead of an empty panel. From then on, a
 * `mmi:pipeline-export-preview-stale` document event triggers a debounced
 * refresh — fired by export-settings.js on a scope filter or Data Type
 * change, and by this file itself (saveFieldConfig()/toggleAllFields())
 * after a field-mapping save completes. Either way it's a single request
 * per debounce window, never a cascade. The manual "Load Preview" button
 * stays for a forced refresh. Subsequent infinite-scroll batches only fire
 * on the user's own scroll interaction.
 *
 * @package MannMade\DataPipeline
 */

(function ($) {
    'use strict';

    const SELECTORS = {
        WRAP:              '#mmi-export-preview-wrap',
        LOAD_BTN:          '#mmi-load-export-preview',
        PAGE_SIZE:         '#mmi-export-preview-page-size',
        SUMMARY:           '#mmi-export-preview-summary',
        STALE_NOTE:        '#mmi-export-preview-stale-note',
        EMPTY_STATE:       '#mmi-export-preview-empty',
        TABLE_SCROLL:      '#mmi-export-preview-table-scroll',
        TABLE:             '#mmi-export-preview-table',
        HEAD:              '#mmi-export-preview-head',
        BODY:              '#mmi-export-preview-body',
        SCROLL_SENTINEL:   '#mmi-export-preview-scroll-sentinel',
        SORTABLE_TH:       '#mmi-export-preview-table thead th.sortable',
        RESIZE_HANDLE:     '.mmi-export-preview-th-resize',
        COLUMN_BTN:        '#mmi-export-preview-column-customize-btn',
        COLUMN_PANEL:      '#mmi-export-preview-column-customize-panel',
        COLUMN_PANEL_BODY: '#mmi-export-preview-ccp-body',
        COLUMN_PANEL_CLOSE:'#mmi-export-preview-ccp-close',
        COLUMN_SELECT_ALL: '#mmi-export-preview-ccp-select-all',
        COLUMN_CLEAR_ALL:  '#mmi-export-preview-ccp-clear-all',
        OUTPUT_COLUMN_INPUT: '.mmi-export-output-column',
        TRANSFORM_SELECT:  '.mmi-export-transform',
        CELL_CONTENT:      '#mmi-export-preview-table .mmi-preview-cell-content',
        DATA_TYPE_SELECT:  '#mmi-export-data-type',
    };

    const LOADING_HTML = '<span class="mmi-loading"></span> Loading preview…';

    const STALE_REFRESH_DEBOUNCE_MS = 700;
    const OUTPUT_COLUMN_DEBOUNCE_MS = 400;
    const DEFAULT_BATCH_SIZE = 200;
    const MIN_COLUMN_WIDTH = 60;
    const SCROLL_NEAR_BOTTOM_PX = 150;
    const COLUMN_WIDTH_STORAGE_KEY = 'mmi_export_preview_column_widths';
    // v2: v1 stored a client-only "hide from preview" preference; that
    // concept no longer exists now the toggle IS the field's real export-
    // enabled flag (server-authoritative — see saveFieldConfig()). Bumping
    // the key drops any stale v1 visibility overrides that would otherwise
    // permanently shadow the fresh server truth on every load. Only column
    // *order* is still stored under this key.
    const COLUMN_VISIBILITY_STORAGE_KEY = 'mmi_export_preview_column_prefs_v2';
    const TITLE_LINK_COLUMN = 'post_title';
    const TRANSFORM_CHOICES = [
        { value: 'none', label: 'None' },
        { value: 'uppercase', label: 'Uppercase' },
        { value: 'lowercase', label: 'Lowercase' },
        { value: 'trim', label: 'Trim' },
        { value: 'date_format', label: 'Date Format (Y-m-d)' },
    ];

    let allColumns = [];
    let idField = '';
    let dataType = '';
    let allRows = [];
    let totalRecords = 0;
    let staleDebounceTimer = null;
    // Keyed by field: {label, group, enabled, output_column, transform} —
    // populated fresh from resp.data.field_config on every successful load.
    let fieldConfig = {};

    const sortState = { col: null, dir: 'asc' };
    const batchState = { size: DEFAULT_BATCH_SIZE, loading: false, done: false };

    /**
     * Set for the duration of a column-resize drag and briefly after mouseup,
     * so the sortable-header click handler below can tell a resize drag apart
     * from an actual header click. A native 'click' is a SEPARATE event from
     * mousedown/mouseup — stopping mousedown's propagation in
     * initColumnResize() does not stop a click from also firing and bubbling
     * into the sortable <th>'s delegated click handler, and after a real drag
     * the click's target is almost never the thin resize handle itself (it's
     * wherever the pointer ended up, often the header cell body) — so a
     * target-based guard on the resize handle alone isn't enough either. See
     * the resize-handle's own 'click' handler in initColumnResize() for the
     * other half of this fix (the no-drag case, where the click DOES land
     * back on the handle).
     */
    let resizeJustEnded = false;

    function getSettings() {
        return window.mmiExportSettings || {};
    }

    function escHtml(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    /* ── Empty-state / table visibility toggle ─────────────────────────────
     * #mmi-export-preview-table uses table-layout:fixed for the drag-to-
     * resize columns (export.css) — a lone placeholder <tr><td> in an
     * otherwise header-less table renders as a collapsed/misaligned box
     * under that layout mode. Keeping the placeholder as a plain <p>
     * sibling and toggling which element is visible avoids that entirely. */
    function showEmptyState(html) {
        $(SELECTORS.EMPTY_STATE).html(html).removeClass('mmi-hidden').show();
        $(SELECTORS.TABLE_SCROLL).addClass('mmi-hidden').hide();
    }

    function showTable() {
        $(SELECTORS.EMPTY_STATE).addClass('mmi-hidden').hide();
        $(SELECTORS.TABLE_SCROLL).removeClass('mmi-hidden').show();
    }

    /* ── Field config: save + bulk toggle ───────────────────────────────────
     * Persists to the exact same endpoints the old Step 2 table used
     * (ExportController::save_export_field_mapping() /
     * toggle_all_export_fields()) — this popover replaces that table's UI,
     * not its backing storage. Every save re-sends the field's full known
     * state (enabled + output_column + transform), never just the one
     * changed value — the endpoint replaces the whole per-field row, so
     * omitting the others would silently reset them (e.g. renaming a
     * column's output name while sending no `enabled` would disable it). */
    function saveFieldConfig(key, overrides) {
        fieldConfig[key] = Object.assign({}, fieldConfig[key], overrides);
        const cfg = fieldConfig[key];
        const settings = getSettings();
        $.post(settings.ajaxUrl, {
            action: 'mmi_pipeline_save_export_field_mapping',
            nonce: settings.nonce,
            profile: settings.currentProfile,
            field: key,
            enabled: cfg.enabled ? 1 : 0,
            output_column: cfg.output_column,
            transform: cfg.transform,
        }).done(function () {
            $(document).trigger('mmi:pipeline-export-preview-stale');
        });
    }

    function toggleAllFields(enabled) {
        const settings = getSettings();
        $.post(settings.ajaxUrl, {
            action: 'mmi_pipeline_toggle_all_export_fields',
            nonce: settings.nonce,
            profile: settings.currentProfile,
            enabled: enabled ? 1 : 0,
        }).done(function () {
            $(document).trigger('mmi:pipeline-export-preview-stale');
        });
    }

    /* ── Columns popover (order + visibility + field mapping) ───────────────
     * Backed by the shared MMIColumnCustomizer widget (mmi-hub/assets/js/
     * shared/mmi-column-customizer.js) — the same drag-to-reorder + show/
     * hide popover also backs mmi-reverb-integration's product table.
     * Unlike reverb's fixed column set, this table's columns depend on the
     * selected data type, so `columns` is rebuilt via setColumns() on every
     * successful load rather than passed once at init, sourced from the
     * FULL field_config map (every schema field, not just currently-enabled
     * ones) so a disabled field can still be found and re-enabled here.
     * Order is still a client-only convenience (COLUMN_VISIBILITY_STORAGE_KEY,
     * scoped per data type) — only visibility is server-authoritative now,
     * via onToggle below.
     *
     * `idField` is deliberately excluded from the customizer's column list —
     * same treatment as reverb's structural checkbox/actions columns — so it
     * can never be hidden or dragged out of place; getVisibleColumns()
     * always prepends it first. */
    let columnCustomizer = null;

    function initColumnCustomize() {
        columnCustomizer = window.MMIColumnCustomizer.init({
            buttonSelector: SELECTORS.COLUMN_BTN,
            panelSelector: SELECTORS.COLUMN_PANEL,
            panelBodySelector: SELECTORS.COLUMN_PANEL_BODY,
            closeSelector: SELECTORS.COLUMN_PANEL_CLOSE,
            storageKey: COLUMN_VISIBILITY_STORAGE_KEY,
            scopeKey: function () { return dataType; },
            columns: [],
            groupLabel: function (group) {
                return (group || 'meta').replace(/_/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); });
            },
            defaultVisible: function (key) {
                return !!(fieldConfig[key] && fieldConfig[key].enabled);
            },
            onToggle: function (key, checked) {
                saveFieldConfig(key, { enabled: checked });
            },
            renderExtra: function (key, $row) {
                const cfg = fieldConfig[key] || {};
                $('<input>')
                    .attr('type', 'text')
                    .addClass('mmi-export-output-column')
                    .attr('data-field', key)
                    .val(cfg.output_column || key)
                    .appendTo($row);

                const $select = $('<select>').addClass('mmi-export-transform').attr('data-field', key);
                TRANSFORM_CHOICES.forEach(function (choice) {
                    $('<option>')
                        .val(choice.value)
                        .text(choice.label)
                        .prop('selected', (cfg.transform || 'none') === choice.value)
                        .appendTo($select);
                });
                $select.appendTo($row);
            },
            onChange: renderAll,
        });
    }

    /* Select All / Clear All bypass the widget's own built-in loop (that
     * writes N localStorage entries and re-renders once) — these buttons
     * mean "enable/disable every field for export," so they go through the
     * same single batched AJAX call as everywhere else that touches many
     * fields at once (see ExportController::toggle_all_export_fields()'s
     * docblock for why a per-field fan-out is a server-load problem here). */
    function initFieldConfigEditing() {
        $(document).on('click', SELECTORS.COLUMN_SELECT_ALL, function () { toggleAllFields(true); });
        $(document).on('click', SELECTORS.COLUMN_CLEAR_ALL, function () { toggleAllFields(false); });

        $(document).on('change', SELECTORS.TRANSFORM_SELECT, function () {
            saveFieldConfig($(this).data('field'), { transform: $(this).val() });
        });

        let debounceTimer = null;
        $(document).on('input', SELECTORS.OUTPUT_COLUMN_INPUT, function () {
            const key = $(this).data('field');
            const value = $(this).val();
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                saveFieldConfig(key, { output_column: value });
            }, OUTPUT_COLUMN_DEBOUNCE_MS);
        });
    }

    function getVisibleColumns() {
        const hideable = columnCustomizer
            ? columnCustomizer.getVisibleOrderedKeys()
            : allColumns.filter(function (col) { return col !== idField; });
        return idField ? [idField].concat(hideable) : hideable;
    }

    /* ── Fetch ─────────────────────────────────────────────────────────── */

    function updateSummary() {
        $(SELECTORS.SUMMARY).text(
            'Showing ' + allRows.length + ' of ' + totalRecords + ' matching record(s)'
        );
    }

    function fetchBatch(offset) {
        const settings = getSettings();
        return $.post(settings.ajaxUrl, {
            action: 'mmi_generate_export_preview',
            nonce: settings.nonce,
            profile: settings.currentProfile,
            limit: batchState.size,
            offset: offset,
            // The Data Type <select> on Step 1 lets a user preview a
            // different type "without switching your saved profile" — send
            // whatever's currently selected so the backend can honor it.
            // When it matches the profile's own saved data type (the normal
            // case), the server-side override is a no-op.
            preview_data_type: $(SELECTORS.DATA_TYPE_SELECT).val() || '',
        });
    }

    function loadPreview() {
        const $btn = $(SELECTORS.LOAD_BTN);

        allRows = [];
        batchState.done = false;
        sortState.col = null;
        sortState.dir = 'asc';

        $btn.prop('disabled', true).addClass('mmi-is-loading');
        $(SELECTORS.STALE_NOTE).removeClass('mmi-hidden').show();
        showEmptyState(LOADING_HTML);

        fetchBatch(0).done(function (resp) {
            if (resp && resp.success) {
                allColumns = resp.data.columns || [];
                idField = resp.data.id_field || '';
                dataType = resp.data.data_type || '';
                fieldConfig = resp.data.field_config || {};
                allRows = resp.data.items || [];
                totalRecords = resp.data.total_records || 0;
                batchState.done = !!resp.data.fully_sampled;
                // Sourced from the full field_config map (every schema field,
                // enabled or not) rather than `columns` (enabled-only) so a
                // currently-disabled field still shows up here and can be
                // re-enabled — see the Columns popover's docblock above.
                const hideableFields = Object.keys(fieldConfig).filter(function (key) { return key !== idField; });
                columnCustomizer.setColumns(hideableFields.map(function (key) {
                    const cfg = fieldConfig[key] || {};
                    return { key: key, label: cfg.label || key, group: cfg.group || 'meta' };
                }));
                renderAll();
                updateSummary();
            } else {
                allColumns = [];
                allRows = [];
                $(SELECTORS.SUMMARY).text('');
                showEmptyState(resp && resp.data && resp.data.message ? escHtml(resp.data.message) : 'Failed to load preview.');
            }
        }).fail(function () {
            allColumns = [];
            allRows = [];
            $(SELECTORS.SUMMARY).text('');
            showEmptyState('Failed to load preview.');
        }).always(function () {
            $btn.prop('disabled', false).removeClass('mmi-is-loading');
            $(SELECTORS.STALE_NOTE).addClass('mmi-hidden').hide();
        });
    }

    function loadNextBatch() {
        if (batchState.loading || batchState.done) {
            return;
        }
        batchState.loading = true;
        $(SELECTORS.SCROLL_SENTINEL).removeClass('mmi-hidden').show();

        fetchBatch(allRows.length).done(function (resp) {
            if (resp && resp.success) {
                allRows = allRows.concat(resp.data.items || []);
                totalRecords = resp.data.total_records || totalRecords;
                batchState.done = !!resp.data.fully_sampled;
                renderBody();
                updateSummary();
            }
        }).always(function () {
            batchState.loading = false;
            $(SELECTORS.SCROLL_SENTINEL).addClass('mmi-hidden').hide();
        });
    }

    function scheduleStaleRefresh() {
        if (!$(SELECTORS.WRAP).length) {
            return;
        }
        $(SELECTORS.STALE_NOTE).removeClass('mmi-hidden').show();
        clearTimeout(staleDebounceTimer);
        staleDebounceTimer = setTimeout(loadPreview, STALE_REFRESH_DEBOUNCE_MS);
    }

    /* ── Render: header (sort + resize handles) ───────────────────────── */

    function applyStoredColumnWidths() {
        let stored;
        try {
            stored = JSON.parse(localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY));
        } catch (e) { /* ignore — localStorage unavailable or corrupt value */ }
        if (!stored || typeof stored !== 'object') {
            return;
        }
        Object.keys(stored).forEach(function (key) {
            const width = parseInt(stored[key], 10);
            if (!width || width < MIN_COLUMN_WIDTH) {
                return;
            }
            $(SELECTORS.TABLE).find('th[data-resize-col="' + key + '"]').css('width', width + 'px');
        });
    }

    function renderHead() {
        const visibleColumns = getVisibleColumns();
        if (sortState.col && visibleColumns.indexOf(sortState.col) === -1) {
            sortState.col = null;
        }

        const $head = $(SELECTORS.HEAD);
        $head.empty();

        visibleColumns.forEach(function (col) {
            const isActive = sortState.col === col;
            const icon = isActive ? (sortState.dir === 'asc' ? ' ↑' : ' ↓') : '';
            // Header shows the configured output-column name (what will
            // actually appear in the exported file), not the raw internal
            // field key — data-col/data-resize-col still key off the raw
            // field so sorting/row-lookup/width-persistence stay stable
            // even when the user renames the output column.
            const fieldCfg = fieldConfig[col];
            const headerLabel = (fieldCfg && fieldCfg.output_column) || col;
            const $th = $('<th>')
                .addClass('sortable')
                .toggleClass('sort-active', isActive)
                .attr('data-col', col)
                .attr('data-resize-col', col);
            $('<span>').addClass('mmi-preview-th-label').text(headerLabel).appendTo($th);
            $('<span>').addClass('sort-icon').text(icon).appendTo($th);
            $('<span>').addClass('mmi-export-preview-th-resize').appendTo($th);
            $head.append($th);
        });

        applyStoredColumnWidths();
    }

    /* ── Render: body (loaded rows, sorted) ────────────────────────────── */

    function getSortedRows() {
        if (!sortState.col) {
            return allRows;
        }
        const col = sortState.col;
        const dir = sortState.dir;
        return [...allRows].sort(function (a, b) {
            const va = (a[col] !== undefined && a[col] !== null) ? String(a[col]).toLowerCase() : '';
            const vb = (b[col] !== undefined && b[col] !== null) ? String(b[col]).toLowerCase() : '';
            const na = parseFloat(va);
            const nb = parseFloat(vb);
            let cmp;
            if (va !== '' && vb !== '' && !isNaN(na) && !isNaN(nb) && String(na) === va && String(nb) === vb) {
                cmp = na - nb;
            } else {
                cmp = va < vb ? -1 : (va > vb ? 1 : 0);
            }
            return dir === 'asc' ? cmp : -cmp;
        });
    }

    function renderBody() {
        const $body = $(SELECTORS.BODY);
        $body.empty();

        if (!allRows.length) {
            showEmptyState('No records match this profile\'s scope.');
            return;
        }

        showTable();
        const visibleColumns = getVisibleColumns();
        const sorted = getSortedRows();

        sorted.forEach(function (row) {
            const $tr = $('<tr>');
            const editUrl = row.__mmi_edit_url;
            visibleColumns.forEach(function (col) {
                const text = row[col] !== undefined && row[col] !== null ? row[col] : '';
                // The ID column and, when present, post_title link out to
                // the record's own wp-admin edit screen — every data type
                // has an ID; only product/post-type/coupon-shaped rows have
                // a post_title, so that column simply won't be a link for
                // the other data types.
                const isLinkColumn = editUrl && (col === idField || col === TITLE_LINK_COLUMN);
                const $cell = $(isLinkColumn ? '<a>' : '<div>')
                    .addClass('mmi-preview-cell-content')
                    .toggleClass('mmi-preview-cell-link', isLinkColumn)
                    .text(text);
                if (isLinkColumn) {
                    $cell.attr({ href: editUrl, target: '_blank', rel: 'noopener noreferrer' });
                }
                $tr.append($('<td>').append($cell));
            });
            $body.append($tr);
        });
    }

    /* Cells default to a 2-line clamp with ellipsis (SELECTORS.CELL_CONTENT
     * in export.css) — click toggles full-wrap so long values (descriptions,
     * HTML content columns) stay scannable without permanently truncating
     * data the user actually needs to read. Link cells (ID/post_title)
     * navigate instead — they open their own edit screen, so the expand
     * toggle doesn't apply to them. */
    function initCellExpand() {
        $(document).on('click', SELECTORS.CELL_CONTENT, function () {
            if ($(this).is('a')) {
                return;
            }
            $(this).toggleClass('is-expanded');
        });
    }

    function renderAll() {
        renderHead();
        renderBody();
    }

    /* ── Sort / resize / infinite-scroll bindings (delegated, bound once) ── */

    function initSortHeaders() {
        $(document).on('click', SELECTORS.SORTABLE_TH, function () {
            // A column-resize drag ending over the header (not the thin resize
            // handle itself) synthesizes a click here too — see resizeJustEnded's
            // own comment above. Ignore it so resizing a column never doubles as
            // sorting it.
            if (resizeJustEnded) {
                return;
            }
            const col = $(this).data('col');
            if (sortState.col === col) {
                sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
            } else {
                sortState.col = col;
                sortState.dir = 'asc';
            }
            renderAll();
        });
    }

    function initColumnResize() {
        let $resizingTh = null;
        let resizeStartX = 0;
        let resizeStartW = 0;

        $(document).on('mousedown', SELECTORS.RESIZE_HANDLE, function (e) {
            // Stops the mousedown itself from bubbling (harmless on its own,
            // kept mainly to block text selection while dragging) — but does
            // NOT, on its own, stop the native 'click' the browser still fires
            // after mouseup. See resizeJustEnded's own comment above for the
            // fix that actually prevents the accidental sort.
            e.preventDefault();
            e.stopPropagation();

            $resizingTh = $(this).closest('th');
            resizeStartX = e.pageX;
            resizeStartW = $resizingTh.outerWidth();
            $(this).addClass('is-resizing');
            $('body').css('cursor', 'col-resize');
        });

        // Covers the no/tiny-drag case: a plain click that lands back on the
        // resize handle itself. Stopped before it bubbles to the sortable
        // <th>'s delegated click handler.
        $(document).on('click', SELECTORS.RESIZE_HANDLE, function (e) {
            e.stopPropagation();
        });

        $(document).on('mousemove', function (e) {
            if (!$resizingTh) {
                return;
            }
            const newWidth = Math.max(MIN_COLUMN_WIDTH, resizeStartW + (e.pageX - resizeStartX));
            $resizingTh.css('width', newWidth + 'px');
        });

        $(document).on('mouseup', function () {
            if (!$resizingTh) {
                return;
            }
            const key = $resizingTh.data('resize-col');
            const width = $resizingTh.outerWidth();

            $resizingTh.find(SELECTORS.RESIZE_HANDLE).removeClass('is-resizing');
            $('body').css('cursor', '');

            if (key) {
                let stored;
                try {
                    stored = JSON.parse(localStorage.getItem(COLUMN_WIDTH_STORAGE_KEY)) || {};
                } catch (e) { stored = {}; }
                stored[key] = width;
                try {
                    localStorage.setItem(COLUMN_WIDTH_STORAGE_KEY, JSON.stringify(stored));
                } catch (e) { /* ignore — localStorage unavailable (e.g. private browsing) */ }
            }

            $resizingTh = null;

            // Covers the general case: mouseup (and the click that follows it)
            // landing over the header body rather than the handle. Cleared on
            // the next tick, after that click has had a chance to fire and
            // check this flag, so a genuine later click still sorts normally.
            resizeJustEnded = true;
            setTimeout(function () { resizeJustEnded = false; }, 0);
        });
    }

    function initInfiniteScroll() {
        // Scroll events don't bubble, so this must bind directly to the
        // container rather than delegate from document — the container is
        // static markup rendered once by export-preview-table.php, so it
        // exists at $(document).ready time.
        $(SELECTORS.TABLE_SCROLL).on('scroll', function () {
            const el = this;
            const nearBottom = (el.scrollHeight - el.scrollTop - el.clientHeight) < SCROLL_NEAR_BOTTOM_PX;
            if (nearBottom) {
                loadNextBatch();
            }
        });
    }

    function initRowsPerPage() {
        $(document).on('change', SELECTORS.PAGE_SIZE, function () {
            batchState.size = parseInt($(this).val(), 10) || DEFAULT_BATCH_SIZE;
            loadPreview();
        });
    }

    $(function () {
        $(document).on('click', SELECTORS.LOAD_BTN, loadPreview);
        $(document).on('mmi:pipeline-export-preview-stale', scheduleStaleRefresh);
        initSortHeaders();
        initColumnResize();
        initInfiniteScroll();
        initColumnCustomize();
        initFieldConfigEditing();
        initRowsPerPage();
        initCellExpand();

        // Single auto-fired request on page ready — opening or creating a
        // profile should show real data immediately, not an empty panel.
        if ($(SELECTORS.WRAP).length) {
            batchState.size = parseInt($(SELECTORS.PAGE_SIZE).val(), 10) || DEFAULT_BATCH_SIZE;
            loadPreview();
        }
    });

})(jQuery);
