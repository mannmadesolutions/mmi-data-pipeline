/**
 * Catalog Maintenance Section
 *
 * Replaces three previously-separate UIs — the Stock Overrides topbar panel
 * (import-pipeline-stock-overrides.js, retired), the Catalog Rules topbar
 * panel (import-pipeline-catalog-rules.js, retired), and the standalone
 * "Update Store Catalog" button — with one always-visible table inside the
 * "Catalog Maintenance" collapsible section. Row order in the table IS the
 * real execution order (see MannMade\DataPipeline\Catalog\Catalog_Phase_Runner),
 * not a separate description of it that could drift.
 *
 * Row types, each with a different edit surface in-row:
 *   - Fixed operations (cleanup / canonical / categories / brands / feed_sync):
 *     a single checkbox toggle (feed_sync has no toggle — gated by data availability).
 *   - Custom Rules (formerly "Override Rules" — stock-status-only in/out-of-stock
 *     forcing has grown into a general post-import data-cleanup action set, see
 *     MannMade\DataPipeline\Custom_Rule_Actions): header row (supplier/match/action)
 *     + an expandable detail row holding the action-specific extra parameters
 *     (taxonomy/term/mode, price, sale dates) and the full condition-cascade
 *     editor, reusing the exact condition-row markup/AJAX contract the retired
 *     panel used. Each condition's Source can be a supplier (source data) or,
 *     as of 2026-09-02, 'wp_taxonomy' (app data) — the former separate "Brand
 *     Rule" row type was retired in favor of a Source='WordPress/WooCommerce'
 *     condition targeting the product_brand taxonomy, discovered the same
 *     way any other taxonomy is. See Stock_Override_Resolver::TAXONOMY_SOURCE.
 *
 * Rule selection: click a custom-rule row to select it (matches this
 * project's established .mmi-uniform-row--selectable contract, e.g.
 * initProfileCardsGrid() in import-pipeline.js) — the shared Delete button in
 * the section bar acts on whichever row is selected. Fixed-operation rows are
 * not selectable (nothing to delete).
 *
 * Extends window.MMIDataPipeline (loaded after import-pipeline.js core).
 */

(function($) {
    'use strict';

    /* ── Selectors ──────────────────────────────────────────────────────── */

    const SELECTORS = {
        TBODY:                   '#mmi-catalog-ops-tbody',
        CUSTOM_RULE_ROW:         '.mmi-cog-row--custom-rule',
        DETAIL_ROW:              '.mmi-cog-detail-row',
        SELECTABLE_ROW:          '.mmi-uniform-row--selectable',
        ADD_CUSTOM_RULE_BTN:     '#mmi-cog-add-custom-rule',
        DELETE_ROW_BTN:          '#mmi-cog-delete-rule',
        CUSTOM_RULE_ROW_TEMPLATE: '#mmi-cog-custom-rule-row-template',
        EXPAND_TOGGLE:           '.mmi-cog-expand-toggle',
        COND_COUNT:              '.mmi-cog-cond-count',
        RULE_NAME_INPUT:         '.mmi-cog-rule-name-input',
        RULE_ENABLED_TOGGLE:     '.mmi-cog-rule-enabled-toggle',
        SECTION_HEAD:            '.mmi-crb-section-head',

        TOGGLE_CB:        '.mmi-catalog-toggle',

        SUPPLIER_SEL:         '.mmi-custom-rule-supplier',
        MATCH_LOGIC_SEL:      '.mmi-custom-rule-match-logic',
        ACTION_SEL:           '.mmi-custom-rule-action',
        CONDITIONS_LIST:      '.mmi-conditions-list',
        CONDITION_ROW:        '.mmi-condition-row',
        COND_SOURCE_SEL:      '.mmi-cond-source',
        COND_FIELD_SEL:       '.mmi-cond-field',
        COMPLIANCE_CELL:      '.mmi-rule-compliance',
        CONDITION_BUILDER:    '.mmi-cb',
        RULE_APPLY_STATUS:    '.mmi-rule-apply-status',
        SHOW_BREAKDOWN_BTN:   '.mmi-show-breakdown-btn',
        BREAKDOWN_CONTAINER:  '.mmi-cond-breakdown',
        BREAKDOWN_CHIP:       '.mmi-cbd-chip[data-cbd-filter]',


        SAVE_BTN:        '#mmi-save-catalog-rules',
        SAVE_STATUS:     '#mmi-catalog-rules-save-status',
        APPLY_NOW_BTN:   '#mmi-apply-overrides-now',
        RUN_INDICATOR:   '#mmi-catalog-run-indicator',
        RUN_BTN:         '#run-catalog-update',

        MASTER_TOGGLE:   '#mmi-catalog-maintenance-master-toggle',
        DISABLED_NOTICE: '#mmi-cog-disabled-notice',

        TYPE_SORT_TH:    '#mmi-cog-type-sort',
        GROUP_DIVIDER:   '.mmi-cog-group-divider',
        FIXED_ROW:       '.mmi-cog-row--fixed',

        // Elements a click inside must never also trigger row (de)selection.
        CLICK_EXCLUDE: 'input, select, button, label, a, .mmi-condition-row',
    };

    // Condition cascade + action settings (shared with Product Workbench).
    const RB = window.MMIRuleBuilder;

    // The two legacy stock actions — still resolved in real time during
    // import (Stock_Override_Resolver::resolve()'s tier system), unlike
    // every other Custom Rule action, which is bulk-apply only. Used purely
    // for this file's own display wording (in/out-of-stock specific labels);
    // the server is the actual source of truth for what's a stock action.
    const STOCK_ACTIONS = [ 'force_instock', 'force_outofstock' ];

    const LABELS = {
        SAVE_IDLE:     '<span class="dashicons dashicons-yes"></span> Save Rules',
        SAVE_RUNNING:  '<span class="mmi-loading"></span> Saving…',
        SAVED:         '<span class="dashicons dashicons-yes"></span> Saved',
        APPLY_IDLE:    '<span class="dashicons dashicons-update"></span> Apply Selected Rule Now',
        APPLY_RUNNING: '<span class="mmi-loading"></span> Applying…',
        APPLY_DONE:    '<span class="dashicons dashicons-yes"></span> Done',
    };

    const CSS = {
        FIELD_ERROR:     'mmi-cond-incomplete',
        HIDDEN:          'mmi-hidden',
        IS_LOADING:      'mmi-is-loading',
        IS_ACTIVE:       'is-active',
        RULE_DISABLED:   'mmi-cog-rule-disabled',
    };

    function ajaxUrl() {
        return (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php';
    }
    function nonce() {
        return (window.mmiImportSettings && window.mmiImportSettings.nonce)
            || (window.mmiProductImportData && window.mmiProductImportData.nonce) || '';
    }

    /**
     * A custom rule's fields (supplier/match/action) live on the header
     * <tr>; its action parameters and conditions live in the very next
     * <tr class="mmi-cog-detail-row">. Every place the retired panel called
     * ".find()" against one wrapping ".mmi-rule-card" element now calls it
     * against this combined 2-row set — jQuery's .find() on a multi-element
     * collection searches both and merges results, so the cascade/preview/
     * apply-now logic below is otherwise unchanged from the original panel.
     */
    function ruleScope($headerRow) {
        return $headerRow.add($headerRow.next(SELECTORS.DETAIL_ROW));
    }

    /* ── Row selection (shared Delete button acts on whichever row is active) ── */

    function activateRow($row) {
        $(SELECTORS.TBODY).find(SELECTORS.SELECTABLE_ROW).removeClass(CSS.IS_ACTIVE).attr('aria-pressed', 'false');
        $row.addClass(CSS.IS_ACTIVE).attr('aria-pressed', 'true');
        $(SELECTORS.DELETE_ROW_BTN).prop('disabled', false);
        $(SELECTORS.APPLY_NOW_BTN).prop('disabled', !$row.is(SELECTORS.CUSTOM_RULE_ROW));
    }

    function clearActiveRow() {
        $(SELECTORS.TBODY).find(SELECTORS.SELECTABLE_ROW).removeClass(CSS.IS_ACTIVE).attr('aria-pressed', 'false');
        $(SELECTORS.DELETE_ROW_BTN).prop('disabled', true);
        $(SELECTORS.APPLY_NOW_BTN).prop('disabled', true);
    }

    /** The custom-rule header <tr> for any element inside its row or its detail row. */
    function headerRowOf($el) {
        const $header = $el.closest(SELECTORS.CUSTOM_RULE_ROW);
        if ($header.length) return $header;
        return $el.closest(SELECTORS.DETAIL_ROW).prev(SELECTORS.CUSTOM_RULE_ROW);
    }

    /* ── Add custom rule row ────────────────────────────────────────────── */

    /**
     * A configured supplier/taxonomy name is arbitrary admin-typed text with
     * no length cap ("Mogami Packaged Dealer 7 1 2026") — the Scope select
     * is sized generously (see CSS) but can still truncate an extreme name.
     * Mirroring the selected option's own text into the <select>'s title
     * attribute guarantees the full name is always readable via a hover
     * tooltip regardless of how the box itself renders.
     */
    function syncSelectTitle($select) {
        $select.attr('title', $select.find('option:selected').text());
    }

    function addCustomRuleRow() {
        const $template = $(SELECTORS.CUSTOM_RULE_ROW_TEMPLATE)[0];
        if (!$template) return;
        const $clone = $(document.importNode($template.content, true));
        $(SELECTORS.TBODY).append($clone);
        const $header = $(SELECTORS.TBODY).find(SELECTORS.CUSTOM_RULE_ROW).last();
        syncSelectTitle($header.find(SELECTORS.SUPPLIER_SEL));
        RB.updateActionParamsVisibility(ruleScope($header));
        previewMatchCount(ruleScope($header));
    }

    /**
     * The expand-toggle's "N conditions" label reflects the number of
     * condition rows physically present, updated the instant one is added
     * or removed — not just at page-render time — so it never lags behind
     * what the user is actually looking at.
     */
    function updateConditionCount($header) {
        const $scope = ruleScope($header);
        const n = $scope.find(SELECTORS.CONDITION_ROW).length;
        $header.find(SELECTORS.COND_COUNT).text(n + (n === 1 ? ' condition' : ' conditions'));
    }

    /* ── Type-column grouping toggle — a browsing convenience only. The
       section's own description promises rows "run in the order shown
       below," which is this table's real execution order (see
       Catalog_Phase_Runner) — grouping by Type would misrepresent that if
       it became the default, so it stays an explicit, reversible toggle:
       click "Type" to group, click again to restore the true run order.
       Hardwired phases and user-added custom rules are always kept in their
       own separate group, never interleaved. ── */

    let originalRowOrder = null;
    let isGroupedByType  = false;

    /**
     * Guards the per-custom-rule AJAX preview (initConditionCascade() +
     * previewMatchCount(), one mmi_get_custom_rule_source_fields +
     * mmi_preview_custom_rule_match_count round trip per condition/rule) so
     * it only ever runs once per page view, and only once the section is
     * actually opened — mirrors duplicate-products.js's identical
     * hasLoadedOnce/ensureLoaded() pattern. This section was folded back to
     * collapsed-by-default (panel-catalog-maintenance.php) after a browser
     * performance recording showed it firing several concurrent AJAX calls
     * on every single Import tab page load regardless of whether an admin
     * ever opened it — see bindSectionExpand() below.
     */
    let hasLoadedRulePreviews = false;

    function captureOriginalRowOrder() {
        originalRowOrder = $(SELECTORS.TBODY).children().toArray();
    }

    function rowTypeLabel($header) {
        if ($header.is(SELECTORS.CUSTOM_RULE_ROW)) {
            return $header.find(SELECTORS.ACTION_SEL + ' option:selected').text() || '';
        }
        return $header.find('.mmi-pgc-mode-badge').first().text().trim();
    }

    function groupRowsByType() {
        const $tbody = $(SELECTORS.TBODY);
        const units  = [];

        $tbody.children().each(function() {
            const $row = $(this);
            if ($row.is(SELECTORS.DETAIL_ROW) || $row.is(SELECTORS.GROUP_DIVIDER)) return;
            const $detail = $row.next(SELECTORS.DETAIL_ROW);
            units.push({
                $header:   $row,
                $detail:   $detail.length ? $detail : null,
                isFixed:   $row.is(SELECTORS.FIXED_ROW),
                typeLabel: rowTypeLabel($row),
            });
        });

        const byType = function(a, b) {
            return a.typeLabel.localeCompare(b.typeLabel, undefined, { sensitivity: 'base' });
        };
        const fixedUnits  = units.filter(function(u) { return u.isFixed; }).sort(byType);
        const customUnits = units.filter(function(u) { return !u.isFixed; }).sort(byType);

        $tbody.find(SELECTORS.GROUP_DIVIDER).remove();
        fixedUnits.concat(customUnits).forEach(function(u) {
            $tbody.append(u.$header);
            if (u.$detail) $tbody.append(u.$detail);
        });
        if (fixedUnits.length && customUnits.length) {
            $('<tr class="mmi-cog-group-divider"><td colspan="4"></td></tr>')
                .insertBefore(customUnits[0].$header);
        }
    }

    function restoreRunOrder() {
        if (!originalRowOrder) return;
        const $tbody = $(SELECTORS.TBODY);
        const attached = originalRowOrder.filter(function(el) { return el.parentNode === $tbody[0]; });
        const extra = $tbody.children().toArray().filter(function(el) {
            return originalRowOrder.indexOf(el) === -1 && !$(el).is(SELECTORS.GROUP_DIVIDER);
        });
        $tbody.find(SELECTORS.GROUP_DIVIDER).remove();
        attached.concat(extra).forEach(function(el) { $tbody.append(el); });
    }

    /* ── Live match-count preview (verbatim logic, re-scoped) ────────────── */

    function previewMatchCount($scope) {
        const supplier   = $scope.find(SELECTORS.SUPPLIER_SEL).val()    || 'all';
        const matchLogic = $scope.find(SELECTORS.MATCH_LOGIC_SEL).val() || 'all';
        const conditions = RB.collectConditions($scope);

        const $badge = $scope.find(SELECTORS.COMPLIANCE_CELL);
        $badge.html('<span class="mmi-cpl-loading">Calculating</span>');

        const postData = { action: 'mmi_preview_custom_rule_match_count', nonce: nonce(), supplier, match_logic: matchLogic };
        conditions.forEach(function(cond, j) {
            postData[`conditions[${j}][source]`]   = cond.source;
            postData[`conditions[${j}][field]`]    = cond.field;
            postData[`conditions[${j}][operator]`] = cond.operator;
            postData[`conditions[${j}][value]`]    = cond.value;
            if (cond.case_sensitive) postData[`conditions[${j}][case_sensitive]`] = 1;
        });

        $.post(ajaxUrl(), postData)
            .done(function(response) {
                if (response && response.success) {
                    const count = response.data.count || 0;
                    $badge.html(
                        '<button type="button" class="mmi-compliance-badge mmi-badge info mmi-match-review-btn" data-compliance="match">' +
                            count.toLocaleString() + ' matching' +
                            '<span class="mmi-badge-arrow">&#9654;</span>' +
                        '</button>'
                    );
                } else {
                    $badge.empty();
                }
            })
            .fail(function() {
                $badge.empty();
            });
    }

    /* ── Per-condition match breakdown — debugging aid for "why does this
       rule match N products." One unified table listing every product
       matched by ANY condition, each row's Conditions column color-coded to
       show exactly which condition(s) it satisfies (a shared color = an
       overlap between conditions), plus a header dashboard summarizing each
       condition's own count and the rule's real combined (match_logic-aware)
       result — recomputed fresh every time this is opened, so it always
       reflects the conditions as currently edited. ── */

    const CONDITION_COLORS = [ '#2563eb', '#d97706', '#16a34a', '#db2777', '#7c3aed', '#0891b2', '#dc2626', '#65a30d' ];

    function conditionColor(i) {
        return CONDITION_COLORS[i % CONDITION_COLORS.length];
    }

    function renderConditionBreakdown($container, data, conditions) {
        var conditionResults = data.conditions || [];
        var multiCondition   = conditionResults.length > 1;

        var html = '<div class="mmi-cbd-header">';
        conditionResults.forEach(function(condResult, i) {
            var cond  = conditions[i] || {};
            var label = 'Condition ' + (i + 1) + ': ' + (cond.field || '?') + ' ' + (cond.operator || '') + ' "' + (cond.value || '') + '"';
            var body  = '<span class="mmi-cbd-dot"></span>' + escHtml(label) + ' — ' +
                (condResult.unsupported ? 'unsupported' : condResult.count + ' matching');
            // Clickable (filters the table below to this condition's rows)
            // unless unsupported — an unsupported condition has no rows.
            html += condResult.unsupported
                ? '<span class="mmi-cbd-chip" style="--cbd-color:' + conditionColor(i) + '">' + body + '</span>'
                : '<button type="button" class="mmi-cbd-chip" data-cbd-filter="' + i + '" aria-pressed="false"' +
                    ' style="--cbd-color:' + conditionColor(i) + '" title="Show only rows matched by Condition ' + (i + 1) + '">' + body + '</button>';
        });
        if (multiCondition) {
            html += '<button type="button" class="mmi-cbd-chip mmi-cbd-chip--result" data-cbd-filter="result" aria-pressed="false"' +
                ' title="Show only rows in the combined result">' +
                data.combined_count + ' matching combined (' + (data.match_logic === 'any' ? 'Any' : 'All') + ' conditions)</button>';
        }
        html += '</div>';
        html += '<div class="mmi-cbd-scope mmi-hidden"></div>';

        var rows = data.rows || [];
        if (!rows.length) {
            html += '<span class="mmi-cbd-empty">No matches</span>';
        } else {
            html += '<div class="mmi-cbd-table-wrap"><table class="mmi-cbd-table"><thead><tr>' +
                '<th>ID</th><th>Product</th><th>Matched by</th>' +
                (multiCondition ? '<th>In result</th>' : '') +
                '</tr></thead><tbody>';
            rows.forEach(function(row) {
                var dots = row.conditions.map(function(i) {
                    return '<span class="mmi-cbd-row-dot" style="--cbd-color:' + conditionColor(i) + '" title="Condition ' + (i + 1) + '"></span>';
                }).join('');
                html += '<tr data-cbd-conds="' + row.conditions.join(' ') + '"' +
                    ' data-cbd-in-result="' + (row.in_result ? 1 : 0) + '"' +
                    (multiCondition && row.in_result ? ' class="mmi-cbd-row--in-result"' : '') + '>' +
                    '<td>#' + row.id + '</td>' +
                    '<td>' + escHtml(row.title) + '</td>' +
                    '<td class="mmi-cbd-dots">' + dots + '</td>' +
                    (multiCondition ? '<td>' + (row.in_result ? '✓' : '') + '</td>' : '') +
                    '</tr>';
            });
            html += '</tbody></table></div>';
            if (data.union_total > data.union_shown) {
                html += '<div class="mmi-cbd-more">…and ' + (data.union_total - data.union_shown) + ' more not shown</div>';
            }
        }

        $container.html(html);
    }

    /* Chip click → filter the breakdown table to the rows that chip
       describes (one condition's matches, or the combined result). Single
       select: clicking the active chip, or another chip, replaces the
       filter; clicking the active one again clears it. Client-side only,
       so it filters the rows already loaded — the scope line says so when
       the server capped the sample (union_total > union_shown). */
    function applyBreakdownFilter($chip) {
        var $container = $chip.closest(SELECTORS.BREAKDOWN_CONTAINER);
        var $chips     = $container.find(SELECTORS.BREAKDOWN_CHIP);
        var $rows      = $container.find('.mmi-cbd-table tbody tr');
        var $scopeLine = $container.find('.mmi-cbd-scope');
        var clearing   = $chip.hasClass(CSS.IS_ACTIVE);
        var filter     = String($chip.data('cbd-filter'));

        $chips.removeClass(CSS.IS_ACTIVE).attr('aria-pressed', 'false');

        if (clearing) {
            $rows.removeClass(CSS.HIDDEN);
            $scopeLine.addClass(CSS.HIDDEN).empty();
            return;
        }

        $chip.addClass(CSS.IS_ACTIVE).attr('aria-pressed', 'true');

        var shown = 0;
        $rows.each(function() {
            var $row  = $(this);
            var match = filter === 'result'
                ? $row.attr('data-cbd-in-result') === '1'
                : ($row.attr('data-cbd-conds') || '').split(' ').indexOf(filter) !== -1;
            $row.toggleClass(CSS.HIDDEN, !match);
            if (match) shown++;
        });

        var what = filter === 'result' ? 'the combined result' : 'Condition ' + (parseInt(filter, 10) + 1);
        $scopeLine.removeClass(CSS.HIDDEN).html(
            'Showing <strong>' + shown + '</strong> row' + (shown === 1 ? '' : 's') + ' matching <strong>' + escHtml(what) + '</strong>' +
            ($container.find('.mmi-cbd-more').length ? ' (of the rows loaded below)' : '') +
            ' — click the chip again to show all.'
        );
    }

    function loadConditionBreakdown($header, $scope) {
        var $container = $scope.find(SELECTORS.BREAKDOWN_CONTAINER);
        var supplier   = $header.find(SELECTORS.SUPPLIER_SEL).val()    || 'all';
        var matchLogic = $header.find(SELECTORS.MATCH_LOGIC_SEL).val() || 'all';
        var conditions = RB.collectConditions($scope);

        if (!conditions.length) {
            $container.html('<span class="mmi-cbd-empty">Add at least one condition first.</span>');
            return;
        }

        $container.html('<span class="mmi-cpl-loading">Loading…</span>');

        var postData = { action: 'mmi_get_custom_rule_condition_breakdown', nonce: nonce(), supplier: supplier, match_logic: matchLogic };
        conditions.forEach(function(cond, j) {
            postData[`conditions[${j}][source]`]   = cond.source;
            postData[`conditions[${j}][field]`]    = cond.field;
            postData[`conditions[${j}][operator]`] = cond.operator;
            postData[`conditions[${j}][value]`]    = cond.value;
            if (cond.case_sensitive) postData[`conditions[${j}][case_sensitive]`] = 1;
        });

        $.post(ajaxUrl(), postData)
            .done(function(response) {
                if (response && response.success) {
                    renderConditionBreakdown($container, response.data, conditions);
                } else {
                    $container.html('<span class="mmi-cbd-empty">' + escHtml((response && response.data && response.data.message) || 'Load failed') + '</span>');
                }
            })
            .fail(function() {
                $container.html('<span class="mmi-cbd-empty">Network error — please try again</span>');
            });
    }

    function debouncedPreview($scope) {
        const $header = $scope.first().is(SELECTORS.CUSTOM_RULE_ROW) ? $scope.first() : headerRowOf($scope.first());
        const existing = $header.data('preview-timer');
        if (existing) {
            clearTimeout(existing);
        }
        $header.data('preview-timer', setTimeout(function() {
            $header.removeData('preview-timer');
            previewMatchCount(ruleScope($header));
        }, 500));
    }

    /* ── Apply Selected Rule Now — the footer button acts on whichever
       custom rule row is selected (same select-then-act model as Delete),
       driven by this one function whether that selection came from a click
       or from the shared button itself. ── */

    function applyRuleNow($scope) {
        const $applyBtn    = $(SELECTORS.APPLY_NOW_BTN);
        const $applyStatus = $scope.find(SELECTORS.RULE_APPLY_STATUS);

        const supplier   = $scope.find(SELECTORS.SUPPLIER_SEL).val()    || '';
        const matchLogic = $scope.find(SELECTORS.MATCH_LOGIC_SEL).val() || 'all';
        const ruleAction = $scope.find(SELECTORS.ACTION_SEL).val()      || '';

        if (!supplier || !ruleAction) {
            $applyStatus.text('Incomplete rule — supplier and action are required.').show();
            return;
        }

        const conditions = RB.collectConditions($scope);

        const actionParams = RB.collectActionParams($scope);

        $applyBtn.prop('disabled', true).addClass(CSS.IS_LOADING).html(LABELS.APPLY_RUNNING);
        $applyStatus.text('Computing matched products…').show();

        let token  = '';
        let totals = { processed: 0, changed: 0, total: null };

        function buildPostData(page) {
            const d = {
                // 'action' is WordPress's own AJAX dispatch key — the rule's
                // own action type goes in 'rule_action' to avoid colliding
                // with it (see the identical note server-side).
                action: 'mmi_apply_single_custom_rule_now', nonce: nonce(), page, per_page: 200,
                supplier, match_logic: matchLogic, rule_action: ruleAction,
            };
            if (token) d.token = token;
            conditions.forEach(function(cond, j) {
                d[`conditions[${j}][source]`]   = cond.source;
                d[`conditions[${j}][field]`]    = cond.field;
                d[`conditions[${j}][operator]`] = cond.operator;
                d[`conditions[${j}][value]`]    = cond.value;
                if (cond.case_sensitive) d[`conditions[${j}][case_sensitive]`] = 1;
            });
            Object.keys(actionParams).forEach(function(k) {
                d[`action_params[${k}]`] = actionParams[k];
            });
            return d;
        }

        function processChunk(page) {
            $.post(ajaxUrl(), buildPostData(page))
                .done(function(response) {
                    if (!response || !response.success) {
                        const msg = (response && response.data && response.data.message) || 'Apply failed';
                        $applyStatus.text('Error: ' + msg);
                        $applyBtn.prop('disabled', false).removeClass(CSS.IS_LOADING).html(LABELS.APPLY_IDLE);
                        return;
                    }

                    const data = response.data;
                    if (data.token && !token) token = data.token;
                    totals.processed += data.processed_this_page || 0;
                    totals.changed   += data.changed_this_page   || 0;
                    if (page === 1) totals.total = data.total_products;

                    const labelText = totals.total != null
                        ? 'Processed ' + totals.processed + ' of ' + totals.total + '…'
                        : 'Processed ' + totals.processed + '…';
                    $applyStatus.text(labelText);

                    if (data.has_more) {
                        processChunk(page + 1);
                    } else {
                        const matched   = totals.total != null ? totals.total : totals.processed;
                        const unchanged = matched - totals.changed;
                        const actionVal = $scope.find(SELECTORS.ACTION_SEL).val();
                        const isStock   = STOCK_ACTIONS.indexOf(actionVal) !== -1;
                        const targetLabel = isStock
                            ? (actionVal === 'force_instock' ? 'in stock' : 'out of stock')
                            : ($scope.find(SELECTORS.ACTION_SEL + ' option:selected').text() || 'the target state').toLowerCase();
                        let statusText = totals.changed + ' product' + (totals.changed !== 1 ? 's' : '') + ' updated';
                        if (matched > 0) {
                            statusText += ' out of ' + matched + ' matched';
                            if (isStock && unchanged > 0) {
                                statusText += ' — ' + unchanged + ' already ' + targetLabel;
                            }
                        }
                        $applyStatus.text(statusText);
                        $applyBtn.prop('disabled', false).removeClass(CSS.IS_LOADING)
                            .html(LABELS.APPLY_DONE).data('mmi-state', 'done');
                        previewMatchCount($scope);
                    }
                })
                .fail(function() {
                    $applyStatus.text('Network error — please try again');
                    $applyBtn.prop('disabled', false).removeClass(CSS.IS_LOADING).html(LABELS.APPLY_IDLE);
                });
        }

        processChunk(1);
    }

    /* ── Match review modal (verbatim from the retired panel) ────────────── */

    var _modalState = { token: '', page: 1, hasMore: false, $scope: null };

    function openMatchModal($scope) {
        _modalState.$scope = $scope;
        _modalState.token = '';
        _modalState.page  = 1;

        var $modal = $('#mmi-match-review-modal');
        var actionLabel = $scope.find(SELECTORS.ACTION_SEL + ' option:selected').text() || 'Custom Rule';
        $modal.find('.mmi-match-modal-title').text('Matched Products — ' + actionLabel);
        $modal.find('#mmi-match-modal-body').html('<div class="mmi-match-modal-loading">Loading…</div>');
        $modal.find('#mmi-match-modal-info').text('');
        $modal.find('#mmi-match-modal-page-label').text('');
        $modal.find('#mmi-match-modal-prev, #mmi-match-modal-next').prop('disabled', true);
        MMIModal.open('mmi-match-review-modal');
        loadModalPage(1);
    }

    function loadModalPage(page) {
        var $scope = _modalState.$scope;
        if (!$scope) return;

        var supplier   = $scope.find(SELECTORS.SUPPLIER_SEL).val()    || 'all';
        var matchLogic = $scope.find(SELECTORS.MATCH_LOGIC_SEL).val() || 'all';
        var ruleAction = $scope.find(SELECTORS.ACTION_SEL).val()      || 'force_outofstock';
        var conditions = RB.collectConditions($scope);

        var postData = {
            action: 'mmi_get_matched_products_for_custom_rule', nonce: nonce(),
            supplier: supplier, match_logic: matchLogic, rule_action: ruleAction, page: page,
        };
        if (_modalState.token) postData.token = _modalState.token;
        conditions.forEach(function(cond, j) {
            postData['conditions[' + j + '][source]']   = cond.source;
            postData['conditions[' + j + '][field]']    = cond.field;
            postData['conditions[' + j + '][operator]'] = cond.operator;
            postData['conditions[' + j + '][value]']    = cond.value;
            if (cond.case_sensitive) postData['conditions[' + j + '][case_sensitive]'] = 1;
        });

        $.post(ajaxUrl(), postData)
            .done(function(response) {
                if (!response || !response.success) {
                    $('#mmi-match-modal-body').html('<p class="mmi-match-modal-error">' + escHtml(response && response.data && response.data.message || 'Load failed') + '</p>');
                    return;
                }
                var data = response.data;
                _modalState.token   = data.token || _modalState.token;
                _modalState.page    = data.page;
                _modalState.hasMore = data.has_more;

                renderModalTable(data);

                var total    = data.total;
                var perPage  = 20;
                var from     = (data.page - 1) * perPage + 1;
                var to       = Math.min(data.page * perPage, total);
                var already  = data.is_stock_action ? data.products.filter(function(p){ return p.already_correct; }).length : 0;
                var infoText = total.toLocaleString() + ' matched products';
                if (data.is_stock_action && already > 0 && data.page === 1) {
                    infoText += ' — ' + already + ' on this page already at target status';
                }
                $('#mmi-match-modal-info').text(infoText);
                $('#mmi-match-modal-page-label').text(from + '–' + to + ' of ' + total.toLocaleString());
                $('#mmi-match-modal-prev').prop('disabled', data.page <= 1);
                $('#mmi-match-modal-next').prop('disabled', !data.has_more);
            })
            .fail(function() {
                $('#mmi-match-modal-body').html('<p class="mmi-match-modal-error">Network error</p>');
            });
    }

    function renderModalTable(data) {
        var products     = data.products;
        var isStockAction = data.is_stock_action;
        var targetStatus  = data.target_status;
        var targetLabel   = targetStatus === 'instock' ? 'In Stock' : 'Out of Stock';
        var actionLabel   = data.action_label || 'this action';

        if (!products || !products.length) {
            $('#mmi-match-modal-body').html('<p class="mmi-match-modal-empty">No products matched.</p>');
            return;
        }

        var html = '<table id="mmi-match-modal-table"><thead><tr>' +
            '<th class="col-id">#</th><th>Product</th><th class="col-sku">SKU</th>' +
            '<th class="col-status">Current Status</th><th class="col-outcome">Outcome</th>' +
            '</tr></thead><tbody>';

        products.forEach(function(p) {
            var statusCls = p.stock_status === 'instock' ? 'instock' : (p.stock_status === 'outofstock' ? 'outofstock' : 'onbackorder');
            var statusLbl = p.stock_status === 'instock' ? 'In Stock' : (p.stock_status === 'outofstock' ? 'Out of Stock' : 'On Backorder');
            // Maps to the shared .mmi-badge success/error/warning variants (mmi-suite-common.css)
            var statusVariant = p.stock_status === 'instock' ? 'success' : (p.stock_status === 'outofstock' ? 'error' : 'warning');
            var outcome;
            if (isStockAction) {
                outcome = p.already_correct
                    ? '<span class="mmi-outcome-ok">✓ Already ' + targetLabel + '</span>'
                    : '<span class="mmi-outcome-chg">→ Will set ' + targetLabel + '</span>';
            } else {
                outcome = '<span class="mmi-outcome-chg">→ Will apply: ' + escHtml(actionLabel) + '</span>';
            }
            var titleHtml = p.edit_url
                ? '<a href="' + escHtml(p.edit_url) + '" target="_blank" rel="noopener">' + escHtml(p.title) + '</a>'
                : escHtml(p.title);

            html += '<tr><td class="col-id">' + p.id + '</td><td>' + titleHtml + '</td>' +
                '<td class="col-sku">' + escHtml(p.sku || '—') + '</td>' +
                '<td class="col-status"><span class="mmi-stock-pill mmi-badge ' + statusVariant + ' ' + statusCls + '">' + statusLbl + '</span></td>' +
                '<td class="col-outcome">' + outcome + '</td></tr>';
        });

        html += '</tbody></table>';
        $('#mmi-match-modal-body').html(html);
    }

    function escHtml(str) {
        return window.MMIEscapeHtml(str);
    }

    /* ── Collect + save (both catalog rules and custom rules, from one
       shared "Save Rules" button — the two used to have separate save
       buttons in separate panels; one merged table gets one save action) ── */

    function collectToggles() {
        const toggles = {};
        $(SELECTORS.TOGGLE_CB).each(function() {
            const key = $(this).data('rule');
            if (key) toggles[key] = $(this).is(':checked') ? '1' : '0';
        });
        return toggles;
    }

    function collectCustomRules() {
        const rules = [];
        $(SELECTORS.TBODY).find(SELECTORS.CUSTOM_RULE_ROW).each(function() {
            const $header    = $(this);
            const $scope     = ruleScope($header);
            const supplier   = $header.find(SELECTORS.SUPPLIER_SEL).val()    || '';
            const matchLogic = $header.find(SELECTORS.MATCH_LOGIC_SEL).val() || 'all';
            const action     = $header.find(SELECTORS.ACTION_SEL).val()      || '';
            const name       = $header.find(SELECTORS.RULE_NAME_INPUT).val().trim();
            const enabled    = $header.find(SELECTORS.RULE_ENABLED_TOGGLE).is(':checked');
            if (!supplier || !action) return;

            const conditions = RB.collectConditions($scope);

            rules.push({ supplier, match_logic: matchLogic, conditions, action, action_params: RB.collectActionParams($scope), name, enabled });
        });
        return rules;
    }

    function updateRunIndicator() {
        const toggles     = collectToggles();
        const activeToggles = Object.values(toggles).filter(v => v === '1').length;
        const customRules = collectCustomRules();
        const activeCustomRules = customRules.filter(function(r) { return r.enabled; }).length;
        const total = activeToggles + activeCustomRules;
        const label = total + (total === 1 ? ' rule' : ' rules');
        const $indicator = $(SELECTORS.RUN_INDICATOR);
        if (total > 0) {
            $indicator.text(label).removeClass(CSS.HIDDEN);
        } else {
            $indicator.addClass(CSS.HIDDEN);
        }
    }

    /**
     * collectCustomRules() skips a condition with no Source or Field, and a
     * rule left with no conditions matches every product — so a half-picked
     * condition on a "Force Out of Stock" rule would silently take the whole
     * catalog out of stock. Stop the save and point at it instead.
     *
     * @return {?{$header: jQuery, message: string}} First problem, or null.
     */
    function findIncompleteCondition() {
        let problem = null;
        $(SELECTORS.TBODY).find(SELECTORS.CONDITION_ROW).removeClass(CSS.FIELD_ERROR);
        $(SELECTORS.TBODY).find(SELECTORS.CUSTOM_RULE_ROW).each(function() {
            const $header = $(this);
            ruleScope($header).find(SELECTORS.CONDITION_ROW).each(function(i) {
                const source = $(this).find(SELECTORS.COND_SOURCE_SEL).val() || '';
                const field  = $(this).find(SELECTORS.COND_FIELD_SEL).val()  || '';
                if (source && field) return;
                $(this).addClass(CSS.FIELD_ERROR);
                if (!problem) {
                    const name = $header.find(SELECTORS.RULE_NAME_INPUT).val().trim() || 'Custom Rule';
                    problem = { $header, message: `"${name}", condition ${i + 1}: pick a Source and a Field, or remove the condition.` };
                }
            });
        });
        return problem;
    }

    function showRuleProblem($header) {
        activateRow($header);
        $header.next(SELECTORS.DETAIL_ROW).removeClass(CSS.HIDDEN);
        $header.find(SELECTORS.SECTION_HEAD).removeClass(CSS.HIDDEN);
        $header[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    function saveAll() {
        const $btn    = $(SELECTORS.SAVE_BTN);
        const $status = $(SELECTORS.SAVE_STATUS);

        const incomplete = findIncompleteCondition();
        if (incomplete) {
            $status.text('Not saved: ' + incomplete.message);
            showRuleProblem(incomplete.$header);
            return;
        }

        const toggles     = collectToggles();
        const customRules = collectCustomRules();

        $btn.prop('disabled', true).addClass(CSS.IS_LOADING).html(LABELS.SAVE_RUNNING);
        $status.text('');

        const catalogPost = { action: 'mmi_save_catalog_rules', nonce: nonce() };
        $.each(toggles, function(key, val) { catalogPost['toggles[' + key + ']'] = val; });

        const customRulesPost = { action: 'mmi_save_custom_rules', nonce: nonce() };
        if (customRules.length === 0) {
            customRulesPost['rules'] = [];
        } else {
            customRules.forEach(function(rule, i) {
                customRulesPost[`rules[${i}][supplier]`]    = rule.supplier;
                customRulesPost[`rules[${i}][match_logic]`] = rule.match_logic;
                customRulesPost[`rules[${i}][action]`]      = rule.action;
                customRulesPost[`rules[${i}][name]`]        = rule.name;
                customRulesPost[`rules[${i}][enabled]`]     = rule.enabled ? '1' : '0';
                Object.keys(rule.action_params || {}).forEach(function(k) {
                    customRulesPost[`rules[${i}][action_params][${k}]`] = rule.action_params[k];
                });
                rule.conditions.forEach(function(cond, j) {
                    customRulesPost[`rules[${i}][conditions][${j}][source]`]   = cond.source;
                    customRulesPost[`rules[${i}][conditions][${j}][field]`]    = cond.field;
                    customRulesPost[`rules[${i}][conditions][${j}][operator]`] = cond.operator;
                    customRulesPost[`rules[${i}][conditions][${j}][value]`]    = cond.value;
                    if (cond.case_sensitive) customRulesPost[`rules[${i}][conditions][${j}][case_sensitive]`] = 1;
                });
            });
        }

        $.when($.post(ajaxUrl(), catalogPost), $.post(ajaxUrl(), customRulesPost))
            .done(function(catalogResp, customRulesResp) {
                const catalogOk = catalogResp[0] && catalogResp[0].success;
                const rulesOk   = customRulesResp[0] && customRulesResp[0].success;
                if (catalogOk && rulesOk) {
                    $btn.html(LABELS.SAVED);
                    $status.text('Saved');
                    updateRunIndicator();
                    setTimeout(function() { $status.text(''); $btn.html(LABELS.SAVE_IDLE); }, 3000);
                } else {
                    $btn.html(LABELS.SAVE_IDLE);
                    const rulesData = customRulesResp[0] && customRulesResp[0].data;
                    const msg = (!catalogOk && catalogResp[0].data && catalogResp[0].data.message)
                        || (!rulesOk && rulesData && rulesData.message)
                        || 'Save failed';
                    // Errors stay on screen until the next save — they name
                    // what to fix, so they can't vanish after 3 seconds.
                    $status.text('Error: ' + msg);
                    if (!rulesOk && rulesData && typeof rulesData.rule_index === 'number') {
                        const $header = $(SELECTORS.TBODY).find(SELECTORS.CUSTOM_RULE_ROW).eq(rulesData.rule_index);
                        if ($header.length) showRuleProblem($header);
                    }
                }
            })
            .fail(function() {
                $btn.html(LABELS.SAVE_IDLE);
                $status.text('Network error — please try again');
            })
            .always(function() {
                $btn.prop('disabled', false).removeClass(CSS.IS_LOADING);
            });
    }

    /* ── Master enable/disable toggle ─────────────────────────────────── */

    /**
     * Keeps the Run button and disabled-notice banner in sync with the
     * master toggle without a page reload. The server (Catalog_Phase_Runner::
     * is_enabled(), checked at every real entry point — see that method's
     * own docblock) stays the actual authority; this is purely a visual
     * mirror so a toggle flipped mid-session doesn't need a refresh to be
     * reflected in the button's own disabled/title state.
     */
    function applyMasterEnabledState(enabled) {
        $(SELECTORS.DISABLED_NOTICE).toggleClass(CSS.HIDDEN, enabled);
        const $runBtn = $(SELECTORS.RUN_BTN);
        if (enabled) {
            $runBtn.prop('disabled', false).removeAttr('title');
        } else {
            $runBtn.prop('disabled', true).attr('title', 'Catalog Maintenance is disabled — turn it back on above to run');
        }
    }

    function saveMasterToggle(enabled) {
        const $toggle = $(SELECTORS.MASTER_TOGGLE);
        $.post(ajaxUrl(), {
            action:  'mmi_toggle_catalog_maintenance',
            nonce:   nonce(),
            enabled: enabled ? '1' : '',
        }).done(function(response) {
            if (!response || !response.success) {
                $toggle.prop('checked', !enabled);
                return;
            }
            applyMasterEnabledState(enabled);
        }).fail(function() {
            $toggle.prop('checked', !enabled);
        });
    }

    /* ── Init & event binding ─────────────────────────────────────────── */

    /**
     * Restores cascade state + fires an initial match-count preview for
     * every custom rule already rendered server-side — one
     * mmi_get_custom_rule_source_fields + mmi_preview_custom_rule_match_count
     * round trip per condition/rule. Expensive enough, and common enough
     * (most profiles accumulate several custom rules over time), that it's
     * gated behind the section actually being opened — see
     * window.MMICatalogMaintenance.ensureLoaded() / bindSectionExpand().
     */
    function loadExistingRulePreviews() {
        $(SELECTORS.TBODY).find(SELECTORS.CUSTOM_RULE_ROW).each(function() {
            const $header = $(this);
            const $scope  = ruleScope($header);
            syncSelectTitle($header.find(SELECTORS.SUPPLIER_SEL));
            RB.updateActionParamsVisibility($scope);
            RB.initSavedConditions($scope);
            previewMatchCount($scope);
        });
    }

    window.MMICatalogMaintenance = {
        ensureLoaded: function () {
            if (hasLoadedRulePreviews) { return; }
            hasLoadedRulePreviews = true;
            loadExistingRulePreviews();
        }
    };

    /**
     * The generic .mmi-collapsible-section accordion (import-preview.js)
     * just toggles a 'collapsed' class with no event of its own — mirrors
     * duplicate-products.js's identical bindSectionExpand().
     */
    function bindSectionExpand() {
        $(document).on('click', '.mmi-section-header, .mmi-collapse-toggle', function () {
            const $section = $(this).closest('#mmi-catalog-maintenance-section');
            if (!$section.length) { return; }
            setTimeout(function () {
                if (!$section.hasClass('collapsed')) {
                    window.MMICatalogMaintenance.ensureLoaded();
                }
            }, 0);
        });
    }

    function initCatalogMaintenance() {
        if (!$(SELECTORS.TBODY).length) return;

        MMIModal.init();
        captureOriginalRowOrder();
        bindSectionExpand();

        updateRunIndicator();

        $(document)

            // Row selection — click anywhere on a custom rule row (except a
            // real control inside it) selects it for the shared Delete button.
            .on('click keydown', SELECTORS.SELECTABLE_ROW, function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                if ($(e.target).is(SELECTORS.CLICK_EXCLUDE) || $(e.target).closest(SELECTORS.CLICK_EXCLUDE).length) return;
                e.preventDefault();
                activateRow($(this));
            })

            // Expand/collapse a custom rule's condition editor. The "What
            // happens"/"Which products" captions live in the header row
            // itself (SECTION_HEAD, next to the action/scope controls they
            // explain) and toggle in lockstep with the detail row below —
            // both start in the same hidden/visible state server-side, so
            // toggling each independently here keeps them in sync.
            .on('click', SELECTORS.EXPAND_TOGGLE, function(e) {
                e.stopPropagation();
                const $header = $(this).closest(SELECTORS.CUSTOM_RULE_ROW);
                $header.next(SELECTORS.DETAIL_ROW).toggleClass(CSS.HIDDEN);
                $header.find(SELECTORS.SECTION_HEAD).toggleClass(CSS.HIDDEN);
            })

            .on('click', SELECTORS.ADD_CUSTOM_RULE_BTN, addCustomRuleRow)

            // A condition edit, add, remove or load in the rule's condition
            // builder (shared MMIConditionBuilder, which owns Add/Remove) —
            // refresh that rule's condition count and "N matching" count.
            .on(RB.CHANGE_EVENT, SELECTORS.TBODY + ' ' + SELECTORS.CONDITION_BUILDER, function() {
                const $header = headerRowOf($(this));
                updateConditionCount($header);
                debouncedPreview(ruleScope($header));
            })

            // Enable/disable a custom rule in place — mirrors the fixed-
            // operation rows' own .mmi-catalog-toggle, but scoped to one
            // rule rather than a whole maintenance phase. Doesn't touch
            // row selection (the toggle's <label>/<input> are already in
            // CLICK_EXCLUDE) and doesn't autosave — like every other
            // control in this row, it only takes effect on "Save Rules".
            .on('change', SELECTORS.RULE_ENABLED_TOGGLE, function() {
                const $row = $(this).closest(SELECTORS.CUSTOM_RULE_ROW);
                $row.toggleClass(CSS.RULE_DISABLED, !$(this).is(':checked'));
                updateRunIndicator();
            })

            .on('click keydown', SELECTORS.TYPE_SORT_TH, function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                isGroupedByType = !isGroupedByType;
                $(this).toggleClass(CSS.IS_ACTIVE, isGroupedByType).attr(
                    'title',
                    isGroupedByType
                        ? 'Showing grouped by Type — click to restore run order'
                        : 'Group rows by Type (click again to restore run order)'
                );
                if (isGroupedByType) {
                    groupRowsByType();
                } else {
                    restoreRunOrder();
                }
            })

            .on('click', SELECTORS.DELETE_ROW_BTN, function() {
                const $active = $(SELECTORS.TBODY).find(SELECTORS.SELECTABLE_ROW + '.' + CSS.IS_ACTIVE);
                if (!$active.length) return;
                if (!window.confirm('Remove this custom rule? This isn\'t permanent until you click "Save Rules" — but once saved, it\'s gone for good.')) {
                    return;
                }
                if ($active.is(SELECTORS.CUSTOM_RULE_ROW)) {
                    $active.next(SELECTORS.DETAIL_ROW).remove();
                }
                $active.remove();
                clearActiveRow();
                updateRunIndicator();
            })

            .on('click', SELECTORS.SHOW_BREAKDOWN_BTN, function() {
                const $header    = headerRowOf($(this));
                const $scope     = ruleScope($header);
                const $container = $scope.find(SELECTORS.BREAKDOWN_CONTAINER);
                const wasHidden  = $container.hasClass(CSS.HIDDEN);
                $container.toggleClass(CSS.HIDDEN);
                if (wasHidden) {
                    loadConditionBreakdown($header, $scope);
                }
            })

            .on('click', SELECTORS.BREAKDOWN_CHIP, function() {
                applyBreakdownFilter($(this));
            })

            .on('change', SELECTORS.SUPPLIER_SEL, function() {
                syncSelectTitle($(this));
                const $row = $(this).closest(SELECTORS.CUSTOM_RULE_ROW);
                if ($row.length) debouncedPreview(ruleScope($row));
            })
            .on('change', SELECTORS.MATCH_LOGIC_SEL, function() {
                const $row = $(this).closest(SELECTORS.CUSTOM_RULE_ROW);
                if ($row.length) debouncedPreview(ruleScope($row));
            })

            .on('change', SELECTORS.ACTION_SEL, function() {
                const $row = $(this).closest(SELECTORS.CUSTOM_RULE_ROW);
                if (!$row.length) return;
                RB.updateActionParamsVisibility(ruleScope($row));
            })

            .on('change', SELECTORS.TOGGLE_CB, updateRunIndicator)

            .on('change', SELECTORS.MASTER_TOGGLE, function() {
                saveMasterToggle($(this).is(':checked'));
            })

            .on('click', SELECTORS.SAVE_BTN, saveAll)

            .on('click', SELECTORS.APPLY_NOW_BTN, function() {
                if ($(this).data('mmi-state') === 'done') {
                    $(this).removeData('mmi-state').html(LABELS.APPLY_IDLE);
                    return;
                }
                const $active = $(SELECTORS.TBODY).find(SELECTORS.CUSTOM_RULE_ROW + '.' + CSS.IS_ACTIVE);
                if (!$active.length) {
                    $(SELECTORS.SAVE_STATUS).text('Select a custom rule row first.');
                    return;
                }
                applyRuleNow(ruleScope($active));
            })

            .on('click', '.mmi-match-review-btn', function() {
                var $header = headerRowOf($(this));
                openMatchModal(ruleScope($header));
            })

            // [data-close]/backdrop-click/Esc are owned by MMIModal.init() now.
            .on('click', '#mmi-match-modal-prev', function() {
                if (_modalState.page > 1) {
                    $('#mmi-match-modal-body').html('<div class="mmi-match-modal-loading">Loading…</div>');
                    loadModalPage(_modalState.page - 1);
                }
            })
            .on('click', '#mmi-match-modal-next', function() {
                if (_modalState.hasMore) {
                    $('#mmi-match-modal-body').html('<div class="mmi-match-modal-loading">Loading…</div>');
                    loadModalPage(_modalState.page + 1);
                }
            });
    }

    if (typeof window.MMIDataPipeline !== 'undefined') {
        Object.assign(window.MMIDataPipeline, { initCatalogMaintenance: initCatalogMaintenance });
    }

    $(function() {
        if (window.MMIDataPipeline && typeof window.MMIDataPipeline.initCatalogMaintenance === 'function') {
            window.MMIDataPipeline.initCatalogMaintenance();
        } else {
            initCatalogMaintenance();
        }
    });

}(jQuery));
