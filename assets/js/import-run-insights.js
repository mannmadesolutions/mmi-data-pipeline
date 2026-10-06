/**
 * Import Run Insights — what one import run did, and why its records failed
 *
 * Opens #mmi-run-insights-modal (partials/modal-run-insights.php) for one
 * Import History row and fills it from mmi_pipeline_run_insights: the run's
 * counts, a per-source breakdown, and each failed record with a diagnosis
 * (which store products are involved, what went wrong, what to change) and
 * a "View source record" link into the source preview, pre-filtered to that
 * record's key.
 *
 * Also owns the Data Pipeline page notices' buttons (Investigate, Check
 * source data, Dismiss), since those notices render on every tab.
 *
 * Exposes window.MMIRunInsights.open(historyId).
 *
 * @package MannMade\DataPipeline
 */

(function ($) {
    'use strict';

    const CONFIG = window.mmiRunInsights || {};

    const SELECTORS = {
        MODAL:          '#mmi-run-insights-modal',
        TITLE:          '#mmi-run-insights-title',
        BODY:           '#mmi-run-insights-body',
        OPEN_TRIGGER:   '.mmi-run-insights-open',
        SOURCE_TRIGGER: '.mmi-source-preview-open',
        VIEW_SOURCE:    '.mmi-run-insights-view-source',
        NOTICE:         '.mmi-process-notice',
        NOTICE_DISMISS: '.mmi-process-notice-dismiss',
    };

    const URL_PARAMS = {
        RUN:    'run_insights',
        SOURCE: 'source_preview',
        SEARCH: 'source_search',
        RETURN: 'source_return_run',
    };

    const MESSAGES = {
        LOADING:        'Loading run details…',
        LOAD_FAILED:    'Could not load this run.',
        TITLE:          'Import Run #%id% — %profile%',
        CAP_NOTE:       'Details were kept for the first %recorded% of %total% failed records. The rest are in the sync log for this run’s time.',
        NO_DETAIL:      'This run recorded %total% failed record(s) but no per-record detail. Runs from before per-record detail was added only kept the count.',
        NO_FAILURES:    'No records failed in this run.',
        ABANDONED:      'This run never finished. Its batches stopped without reporting back, so the counts below are whatever it had reached.',
        ABORTED:        'This run was stopped by a user.',
        DISMISS_FAILED: 'Could not dismiss this notice.',
    };

    const TAG_LABELS = {
        matched:   'Import updates this one',
        tracked:   'Linked to this supplier item',
        sku_owner: 'Holds this SKU',
    };

    /** Diagnosis code → shared .mmi-badge color modifier. */
    const DIAGNOSIS_BADGE = {
        duplicate_products: 'error',
        sku_taken:          'warning',
        create_failed:      'warning',
        record_error:       'warning',
        batch:              'error',
    };

    const SPINNER_HTML = '<span class="mmi-loading"></span>';

    const esc = (s) => window.MMIEscapeHtml(s == null ? '' : String(s));
    const fmt = (n) => Number(n || 0).toLocaleString();
    const fill = (tpl, vars) => tpl.replace(/%(\w+)%/g, (m, k) => (k in vars ? vars[k] : m));

    function supplierLabel(id, names) {
        return (names && names[id]) || id;
    }

    function formatDate(mysqlDate) {
        if (!mysqlDate) { return '—'; }
        const d = new Date(mysqlDate.replace(' ', 'T'));
        return isNaN(d.getTime())
            ? mysqlDate
            : d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    /* ── Rendering ────────────────────────────────────────────────────── */

    function renderStat(label, value, modifier) {
        return `<div class="mmi-stat-box ${modifier || ''} inline">
            <div class="mmi-stat-label">${esc(label)}</div>
            <div class="mmi-stat-value">${fmt(value)}</div>
        </div>`;
    }

    function renderPerSupplier(rows, names) {
        if (!rows.length) { return ''; }
        const body = rows.map((r) => `<tr>
            <td>${esc(supplierLabel(r.supplier, names))}</td>
            <td>${fmt(r.imported)}</td>
            <td>${fmt(r.updated)}</td>
            <td>${fmt(r.skipped)}</td>
            <td class="${r.failed > 0 ? 'mmi-run-insights-failed-cell' : ''}">${fmt(r.failed)}</td>
        </tr>`).join('');
        return `<h4 class="mmi-run-insights-heading">By source</h4>
            <div class="mmi-run-insights-table-wrap">
            <table class="mmi-uniform-table mmi-run-insights-sources">
                <thead><tr><th>Source</th><th>Created</th><th>Updated</th><th>Unchanged / skipped</th><th>Failed</th></tr></thead>
                <tbody>${body}</tbody>
            </table></div>`;
    }

    function renderProducts(products) {
        if (!products.length) {
            return '<span class="mmi-run-insights-muted">No store product is linked to this key.</span>';
        }
        return '<ul class="mmi-run-insights-products">' + products.map((p) => {
            const tags = Object.keys(TAG_LABELS)
                .filter((k) => p[k])
                .map((k) => `<span class="mmi-badge mmi-run-insights-tag">${esc(TAG_LABELS[k])}</span>`)
                .join(' ');
            return `<li>
                <a href="${esc(p.edit_url)}" target="_blank" rel="noopener">#${esc(p.id)} ${esc(p.title)}</a>
                <span class="mmi-run-insights-muted">SKU ${esc(p.sku || '—')} · ${esc(p.status)} · created ${esc(formatDate(p.created))}</span>
                ${tags ? `<span class="mmi-run-insights-tags">${tags}</span>` : ''}
            </li>`;
        }).join('') + '</ul>';
    }

    function renderFailureRow(f, names) {
        const d = f.diagnosis || {};
        const canViewSource = f.key && f.key !== '(unknown)' && d.code !== 'batch';
        return `<tr>
            <td class="mmi-run-insights-key"><code>${esc(f.key)}</code>${f.title ? `<div class="mmi-run-insights-muted">${esc(f.title)}</div>` : ''}</td>
            <td>${esc(supplierLabel(f.supplier, names))}</td>
            <td class="mmi-run-insights-problem">
                <span class="mmi-badge ${DIAGNOSIS_BADGE[d.code] || 'warning'}">${esc(d.label || 'Error')}</span>
                <div class="mmi-run-insights-reason">${esc(f.reason)}</div>
                ${d.explain ? `<p>${esc(d.explain)}</p>` : ''}
                ${d.fix ? `<p class="mmi-run-insights-fix"><strong>Fix:</strong> ${esc(d.fix)}</p>` : ''}
            </td>
            <td>${renderProducts(f.products || [])}</td>
            <td class="mmi-run-insights-actions">${canViewSource
                ? `<button type="button" class="button mmi-action-btn mmi-run-insights-view-source" data-supplier="${esc(f.supplier)}" data-key="${esc(f.key)}">
                       <span class="dashicons dashicons-search"></span> View source record
                   </button>`
                : ''}</td>
        </tr>`;
    }

    function renderFailures(data) {
        const total    = data.run.errors;
        const recorded = data.failures.length;
        if (!total && !recorded) {
            return `<p class="mmi-run-insights-muted">${esc(MESSAGES.NO_FAILURES)}</p>`;
        }

        let html = `<h4 class="mmi-run-insights-heading">Failed records (${fmt(total || recorded)})</h4>`;
        if (!recorded) {
            return html + `<p class="mmi-run-insights-muted">${esc(fill(MESSAGES.NO_DETAIL, { total: fmt(total) }))}</p>`;
        }

        html += '<ul class="mmi-run-insights-reasons">' + data.reasons.map((r) =>
            `<li><span class="mmi-run-insights-reason-text">${esc(r.reason)}</span> <span class="mmi-badge">×${fmt(r.count)}</span></li>`
        ).join('') + '</ul>';

        if (total > recorded) {
            html += `<p class="mmi-run-insights-muted">${esc(fill(MESSAGES.CAP_NOTE, { recorded: fmt(recorded), total: fmt(total) }))}</p>`;
        }

        html += `<div class="mmi-run-insights-table-wrap mmi-run-insights-table-wrap--tall">
            <table class="mmi-uniform-table mmi-run-insights-failures">
                <thead><tr>
                    <th data-resize-col="key">Key</th>
                    <th data-resize-col="source">Source</th>
                    <th data-resize-col="problem">Problem</th>
                    <th data-resize-col="products">Store products involved</th>
                    <th data-resize-col="actions" class="mmi-run-insights-actions"><span class="screen-reader-text">Actions</span></th>
                </tr></thead>
                <tbody>${data.failures.map((f) => renderFailureRow(f, data.supplier_names)).join('')}</tbody>
            </table></div>`;
        return html;
    }

    function render(data) {
        const run = data.run;
        $(SELECTORS.TITLE).text(fill(MESSAGES.TITLE, { id: run.id, profile: run.profile_name }));

        let html = `<p class="mmi-run-insights-meta">
            ${esc(formatDate(run.started_at))}${run.duration ? ` · ${esc(run.duration)}` : ''} · ${esc(run.status)}
        </p>`;

        if (data.abandoned) {
            html += `<div class="notice notice-error inline mmi-run-insights-callout"><p>${esc(MESSAGES.ABANDONED)}</p>${data.run_error ? `<p>${esc(data.run_error)}</p>` : ''}</div>`;
        } else if (data.aborted) {
            html += `<div class="notice notice-info inline mmi-run-insights-callout"><p>${esc(MESSAGES.ABORTED)}</p></div>`;
        } else if (data.run_error) {
            html += `<div class="notice notice-error inline mmi-run-insights-callout"><p>${esc(data.run_error)}</p></div>`;
        }

        html += `<div class="mmi-stats-grid mmi-run-insights-stats">
            ${renderStat('Created', run.imported, 'success')}
            ${renderStat('Updated', run.updated, 'info')}
            ${renderStat('Unchanged / skipped', run.skipped, '')}
            ${renderStat('Failed', run.errors, run.errors > 0 ? 'warning' : '')}
        </div>`;

        html += renderPerSupplier(data.per_supplier || [], data.supplier_names);
        html += renderFailures(data);

        $(SELECTORS.BODY).html(html);
    }

    /* ── Open / navigate ──────────────────────────────────────────────── */

    let currentRunId = 0;

    function open(historyId) {
        currentRunId = parseInt(historyId, 10) || 0;
        if (!currentRunId) { return; }

        $(SELECTORS.TITLE).text('Import Run #' + currentRunId);
        $(SELECTORS.BODY).html(`<p class="mmi-modal-loading">${esc(MESSAGES.LOADING)}</p>`);
        MMIModal.open($(SELECTORS.MODAL).get(0));

        $.post(CONFIG.ajaxUrl, { action: 'mmi_pipeline_run_insights', nonce: CONFIG.nonce, history_id: currentRunId })
            .done((res) => {
                if (res && res.success) {
                    render(res.data);
                } else {
                    $(SELECTORS.BODY).html(`<p class="mmi-modal-error">${esc((res && res.data && res.data.message) || MESSAGES.LOAD_FAILED)}</p>`);
                }
            })
            .fail(() => $(SELECTORS.BODY).html(`<p class="mmi-modal-error">${esc(MESSAGES.LOAD_FAILED)}</p>`));
    }

    /**
     * Open a source's preview, optionally filtered to one key. The preview
     * lives in the Import tab's pipeline core; from any other tab, go there
     * with the same request in the URL.
     */
    function openSourcePreview(supplier, supplierName, search, returnRunId) {
        if (window.MMIDataPipeline && typeof window.MMIDataPipeline.openSourcePreviewModal === 'function') {
            MMIModal.close($(SELECTORS.MODAL).get(0));
            window.MMIDataPipeline.openSourcePreviewModal(supplier, supplierName, { search: search || '', returnRunId: returnRunId || 0 });
            return;
        }
        const url = new URL(CONFIG.importTabUrl, window.location.origin);
        url.searchParams.set(URL_PARAMS.SOURCE, supplier);
        if (search)      { url.searchParams.set(URL_PARAMS.SEARCH, search); }
        if (returnRunId) { url.searchParams.set(URL_PARAMS.RETURN, returnRunId); }
        window.location.href = url.toString();
    }

    /** Honor ?run_insights= / ?source_preview= once, then drop them so a refresh doesn't reopen. */
    function openFromUrl() {
        const url    = new URL(window.location.href);
        const runId  = url.searchParams.get(URL_PARAMS.RUN);
        const source = url.searchParams.get(URL_PARAMS.SOURCE);
        if (!runId && !source) { return; }

        if (runId && $(SELECTORS.MODAL).length) {
            open(runId);
        } else if (source && window.MMIDataPipeline) {
            const names = CONFIG.supplierNames || {};
            openSourcePreview(source, names[source] || source, url.searchParams.get(URL_PARAMS.SEARCH) || '',
                parseInt(url.searchParams.get(URL_PARAMS.RETURN), 10) || 0);
        }
        Object.values(URL_PARAMS).forEach((p) => url.searchParams.delete(p));
        window.history.replaceState(window.history.state, '', url.toString());
    }

    /* ── Events ───────────────────────────────────────────────────────── */

    $(document).on('click', SELECTORS.OPEN_TRIGGER, function (e) {
        // The notice's button is a real link (works with JS off / from any
        // tab); with the modal on this page, open it in place instead.
        if (!$(SELECTORS.MODAL).length) { return; }
        e.preventDefault();
        open($(this).data('history-id'));
    });

    $(document).on('click', SELECTORS.SOURCE_TRIGGER, function (e) {
        if (!window.MMIDataPipeline) { return; } // follow the link to the Import tab
        e.preventDefault();
        openSourcePreview($(this).data('supplier'), $(this).data('supplier-name'), '', 0);
    });

    $(document).on('click', SELECTORS.VIEW_SOURCE, function () {
        const names = CONFIG.supplierNames || {};
        const supplier = $(this).data('supplier');
        openSourcePreview(supplier, names[supplier] || supplier, String($(this).data('key')), currentRunId);
    });

    $(document).on('click', SELECTORS.NOTICE_DISMISS, function () {
        const $btn    = $(this);
        const $notice = $btn.closest(SELECTORS.NOTICE);
        const label   = $btn.html();
        $btn.prop('disabled', true).addClass('mmi-is-loading').html(`${SPINNER_HTML} Dismiss`);
        $.post(CONFIG.ajaxUrl, { action: 'mmi_pipeline_dismiss_process_error', nonce: CONFIG.nonce, error_key: $btn.data('error-key') })
            .done((res) => {
                if (res && res.success) {
                    $notice.slideUp(200, () => $notice.remove());
                    return;
                }
                $btn.prop('disabled', false).removeClass('mmi-is-loading').html(label)
                    .attr('title', (res && res.data && res.data.message) || MESSAGES.DISMISS_FAILED);
            })
            .fail(() => {
                $btn.prop('disabled', false).removeClass('mmi-is-loading').html(label).attr('title', MESSAGES.DISMISS_FAILED);
            });
    });

    window.MMIRunInsights = { open: open };

    $(function () {
        MMIModal.init();
        openFromUrl();
    });

})(jQuery);
