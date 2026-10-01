/**
 * MMI Condition Builder — the suite-wide condition builder's behavior.
 * Markup: MMI_Condition_Builder (class-condition-builder.php). Styles:
 * mmi-suite-common.css → "Condition builder".
 *
 * Drives every .mmi-cb on the page, including ones inserted later by AJAX.
 * Saved rows are restored lazily, when their builder first becomes visible
 * (a collapsed rule or field costs nothing), and the cascade's AJAX runs at
 * most 2 at a time with identical requests shared — AGENTS.md's page-load
 * fan-out limit. collect() works on a not-yet-restored builder too: the
 * server renders each saved value as the selected option.
 *   - the Source → Field → Operator → Value cascade, the "is any of" value
 *     checklist, the equals/not-equals value picker and Match case;
 *   - Add Condition / remove, and the empty hint;
 *   - "Load saved conditions": every condition set saved anywhere in the
 *     suite (the 'mmi_condition_library' PHP filter), copied in with
 *     Replace or Add.
 *
 * Any change that could change what matches fires a bubbling
 * `mmi:conditions-change` event from the row (or from the .mmi-cb root when
 * a row is removed or a set is loaded). Consumers listen on their own
 * container and read conditions back with MMIConditionBuilder.collect().
 *
 * AJAX for the cascade comes from window.mmiConditionBuilder
 * ({ajaxUrl, nonce, actions: {fields, values, terms}}), localized by the
 * consuming plugin, or a builder's own data-cb-endpoints override.
 *
 * Exposes window.MMIConditionBuilder.
 */

(function($) {
    'use strict';

    const SELECTORS = {
        ROOT:                         '.mmi-cb',
        LIST:                         '.mmi-conditions-list',
        CONDITION_ROW:                '.mmi-condition-row',
        ROW_TEMPLATE:                 'template.mmi-cb-row-template',
        LEGACY_TEMPLATE:              '#mmi-condition-row-template',
        ADD_BTN:                      '.mmi-add-condition-btn',
        REMOVE_BTN:                   '.mmi-remove-condition-btn',
        EMPTY_HINT:                   '.mmi-cb-empty-hint',
        MATCH_LOGIC:                  '.mmi-cb-match-logic',
        NOTICE:                       '.mmi-cb-notice',
        LIBRARY_BTN:                  '.mmi-cb-library-btn',
        LIBRARY:                      '.mmi-cb-library',
        LIBRARY_SEARCH:               '.mmi-cb-library-search',
        LIBRARY_LIST:                 '.mmi-cb-library-list',
        LIBRARY_ITEM:                 '.mmi-cb-library-item',
        LIBRARY_APPLY:                '.mmi-cb-library-apply',

        COND_SOURCE_SEL:              '.mmi-cond-source',
        COND_FIELD_SEL:               '.mmi-cond-field',
        COND_OPERATOR_SEL:            '.mmi-cond-operator',
        COND_VALUE_SEL:               '.mmi-cond-value',
        COND_VALUE_SELECT:            '.mmi-cond-value-select',
        COND_CASE:                    '.mmi-cond-case',
        COND_CASE_INPUT:              '.mmi-cond-case-input',
        COND_VALUE_CHECKLIST:         '.mmi-cond-value-checklist',
        COND_VALUE_CHECKLIST_TRIGGER: '.mmi-cvc-trigger',
        COND_VALUE_CHECKLIST_LABEL:   '.mmi-cvc-label',
        COND_VALUE_CHECKLIST_PANEL:   '.mmi-cvc-panel',
        COND_VALUE_CHECKLIST_SEARCH:  '.mmi-cvc-search',
        COND_VALUE_CHECKLIST_OPTIONS: '.mmi-cvc-options',
        COND_VALUE_CHECKLIST_OPTION:  '.mmi-cvc-option',
        COND_ORIGIN_BADGE:            '.mmi-cond-origin-badge',
    };

    // Mirror MMI_Condition_Builder::TAXONOMY_SOURCE/POSTMETA_SOURCE.
    const TAXONOMY_SOURCE = 'wp_taxonomy';
    const POSTMETA_SOURCE = 'wp_postmeta';
    const WP_APP_SOURCES  = [ TAXONOMY_SOURCE, POSTMETA_SOURCE ];

    const LIST_OPERATORS       = [ 'in_list', 'not_in_list' ];
    const PICK_OPERATORS       = [ 'equals', 'not_equals' ];
    const LIST_VALUE_DELIMITER = '|';

    // Mirror MMI_Condition_Builder::CASE_OPERATORS.
    const CASE_OPERATORS = [ 'equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'in_list', 'not_in_list' ];

    const OPERATOR_WORDS = {
        equals: 'equals', not_equals: 'not equals', contains: 'contains', not_contains: 'doesn’t contain',
        starts_with: 'starts with', ends_with: 'ends with', in_list: 'is any of', not_in_list: 'is none of',
        is_empty: 'is empty', is_not_empty: 'is not empty', greater_than: '>', less_than: '<',
    };

    const ORIGIN = { SOURCE: 'source', APP: 'app' };

    const CSS = {
        HIDDEN:          'mmi-hidden',
        CASCADE_LOADING: 'mmi-cascade-loading',
        CASE_NA:         'mmi-cond-case--na',
        READY_ATTR:      'data-cb-ready',
    };

    const CHANGE_EVENT = 'mmi:conditions-change';

    const MAX_CONCURRENT_REQUESTS = 2;

    /* ── AJAX: at most MAX_CONCURRENT_REQUESTS in flight; identical requests
       share one response. ── */

    const requestQueue = [];
    const inFlight     = {};
    let activeRequests = 0;

    function pumpQueue() {
        while (activeRequests < MAX_CONCURRENT_REQUESTS && requestQueue.length) {
            requestQueue.shift()();
        }
    }

    /** $.post through the queue; returns a promise. */
    function queuedPost(url, data) {
        const key = url + '?' + $.param(data);
        if (inFlight[key]) return inFlight[key];
        const deferred = $.Deferred();
        requestQueue.push(function() {
            activeRequests++;
            $.post(url, data)
                .done(deferred.resolve)
                .fail(deferred.reject)
                .always(function() {
                    activeRequests--;
                    delete inFlight[key];
                    pumpQueue();
                });
        });
        inFlight[key] = deferred.promise();
        pumpQueue();
        return inFlight[key];
    }

    function escHtml(str) {
        return window.MMIEscapeHtml ? window.MMIEscapeHtml(str)
            : String(str == null ? '' : str).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
    }

    /* ── Endpoints ───────────────────────────────────────────────────────── */

    function globalConfig() {
        return window.mmiConditionBuilder || {};
    }

    /** {ajaxUrl, nonce, fields, values, terms} for the builder owning $el. */
    function endpoints($el) {
        const g       = globalConfig();
        const actions = g.actions || {};
        let own = {};
        const raw = $el.closest(SELECTORS.ROOT).attr('data-cb-endpoints');
        if (raw) {
            try { own = JSON.parse(raw) || {}; } catch (e) { own = {}; }
        }
        return {
            ajaxUrl: own.ajaxUrl || g.ajaxUrl || (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php',
            nonce:   own.nonce   || g.nonce   || '',
            fields:  own.fields  || actions.fields || '',
            values:  own.values  || actions.values || '',
            terms:   own.terms   || actions.terms  || '',
        };
    }

    function fieldPlaceholder(source) {
        if (!source) return 'What to check…';
        if (source === TAXONOMY_SOURCE) return 'Taxonomy…';
        if (source === POSTMETA_SOURCE) return 'Field or meta key…';
        return 'Feed field…';
    }

    function rootOf($el) {
        return $el.closest(SELECTORS.ROOT);
    }

    function notifyChange($el) {
        $el.trigger(CHANGE_EVENT);
    }

    /* ── Source→Field→Operator cascade ───────────────────────────────────── */

    const sourceFieldsCache = {};

    function sourceOption($condRow, source) {
        return $condRow.find(SELECTORS.COND_SOURCE_SEL + ' option').filter(function() {
            return this.value === source;
        }).first();
    }

    /** Label a row Source Data vs WP/WC Data from its Source option. */
    function updateOriginBadge($condRow, source) {
        let origin = '';
        if (source) {
            origin = sourceOption($condRow, source).attr('data-cb-origin')
                || (WP_APP_SOURCES.indexOf(source) !== -1 ? ORIGIN.APP : ORIGIN.SOURCE);
        }
        $condRow.find(SELECTORS.COND_ORIGIN_BADGE)
            .attr('data-origin', origin)
            .toggleClass('error', origin === ORIGIN.APP)
            .toggleClass('info', origin === ORIGIN.SOURCE);
    }

    function initConditionCascade($condRow, savedSource, savedField, savedOperator, savedValue) {
        $condRow.attr(CSS.READY_ATTR, '1');
        const $sourceSel   = $condRow.find(SELECTORS.COND_SOURCE_SEL);
        const $fieldSel    = $condRow.find(SELECTORS.COND_FIELD_SEL);
        const $operatorSel = $condRow.find(SELECTORS.COND_OPERATOR_SEL);
        const $valueInput  = $condRow.find(SELECTORS.COND_VALUE_SEL);

        if (savedSource) {
            $sourceSel.val(savedSource);
        }

        const source = $sourceSel.val() || '';
        updateOriginBadge($condRow, source);

        if (!source) {
            $fieldSel.html(`<option value="">${fieldPlaceholder('')}</option>`).prop('disabled', true);
            $operatorSel.prop('disabled', true).val('equals');
            $valueInput.prop('disabled', true).val('').show();
            return;
        }

        loadCascadeFields($condRow, source, savedField, savedOperator, savedValue);
    }

    function loadCascadeFields($condRow, source, savedField, savedOperator, savedValue) {
        const $fieldSel    = $condRow.find(SELECTORS.COND_FIELD_SEL);
        const $operatorSel = $condRow.find(SELECTORS.COND_OPERATOR_SEL);
        const $valueInput  = $condRow.find(SELECTORS.COND_VALUE_SEL);

        $operatorSel.prop('disabled', true);
        $valueInput.prop('disabled', true).show();

        const preservedField = $fieldSel.val() || savedField || '';
        if (!preservedField) {
            $fieldSel.html('<option value="">Loading…</option>');
        }
        $fieldSel.prop('disabled', true).addClass(CSS.CASCADE_LOADING);

        const applyFields = function(fields) {
            $fieldSel.removeClass(CSS.CASCADE_LOADING);
            if (fields && fields.length > 0) {
                // A source with exactly one field (e.g. Field Mapping's
                // "This field's value") picks it for you.
                const selectField = preservedField || savedField || (fields.length === 1 ? String(typeof fields[0] === 'object' ? fields[0].value : fields[0]) : '');
                let opts = `<option value="">${fieldPlaceholder(source)}</option>`;
                fields.forEach(function(f) {
                    // Supplier fields are plain strings (value === label);
                    // WP/WC and static fields are {value, label}.
                    const isObj  = f !== null && typeof f === 'object';
                    const fValue = String(isObj ? f.value : f);
                    const fLabel = isObj ? f.label : f;
                    opts += '<option value="' + escHtml(fValue) + '"' + (fValue === selectField ? ' selected' : '') + '>' + escHtml(fLabel) + '</option>';
                });
                $fieldSel.html(opts).prop('disabled', false);
                if (selectField) {
                    if (savedOperator) {
                        $operatorSel.val(savedOperator);
                    }
                    $operatorSel.prop('disabled', false);
                    if (savedValue !== undefined) {
                        $valueInput.val(savedValue);
                    }
                    updateValueVisibility($condRow);
                    $valueInput.prop('disabled', false);
                }
            } else {
                $fieldSel.html('<option value="">No fields available</option>').prop('disabled', true);
            }
            notifyChange($condRow);
        };

        // A source option may carry its own field list (no AJAX needed).
        const staticFields = sourceOption($condRow, source).attr('data-cb-fields');
        if (staticFields) {
            let parsed = [];
            try { parsed = JSON.parse(staticFields) || []; } catch (e) { parsed = []; }
            applyFields(parsed);
            return;
        }

        const ep       = endpoints($condRow);
        const cacheKey = ep.fields + '::' + source;
        if (Object.prototype.hasOwnProperty.call(sourceFieldsCache, cacheKey)) {
            applyFields(sourceFieldsCache[cacheKey]);
            return;
        }
        if (!ep.fields) {
            applyFields([]);
            return;
        }

        queuedPost(ep.ajaxUrl, {
            action:   ep.fields,
            supplier: source,
            nonce:    ep.nonce,
        }).done(function(response) {
            const fields = (response && response.success && response.data.fields) ? response.data.fields : [];
            sourceFieldsCache[cacheKey] = fields;
            applyFields(fields);
        }).fail(function() {
            $fieldSel.removeClass(CSS.CASCADE_LOADING)
                     .html(`<option value="">${fieldPlaceholder(source)}</option>`).prop('disabled', true);
        });
    }

    function updateValueVisibility($condRow) {
        const op          = $condRow.find(SELECTORS.COND_OPERATOR_SEL).val() || '';
        $condRow.find(SELECTORS.COND_CASE).toggleClass(CSS.CASE_NA, CASE_OPERATORS.indexOf(op) === -1);
        const $valueInput = $condRow.find(SELECTORS.COND_VALUE_SEL);
        const $checklist  = $condRow.find(SELECTORS.COND_VALUE_CHECKLIST);
        const noValue     = (op === 'is_empty' || op === 'is_not_empty');

        $condRow.find(SELECTORS.COND_VALUE_SELECT).addClass(CSS.HIDDEN);

        if (noValue) {
            $valueInput.hide().val('');
            closeChecklist($condRow);
            $checklist.addClass(CSS.HIDDEN);
            return;
        }

        const source = $condRow.find(SELECTORS.COND_SOURCE_SEL).val() || '';
        const field  = $condRow.find(SELECTORS.COND_FIELD_SEL).val()  || '';
        const isApp  = !!(source && field && WP_APP_SOURCES.indexOf(source) !== -1);

        if (PICK_OPERATORS.indexOf(op) !== -1 && isApp) {
            closeChecklist($condRow);
            $checklist.addClass(CSS.HIDDEN);
            loadValuePicker($condRow, source, field);
            return;
        }

        if (LIST_OPERATORS.indexOf(op) === -1) {
            closeChecklist($condRow);
            $checklist.addClass(CSS.HIDDEN);
            $valueInput.show().attr('placeholder', 'Value…');
            return;
        }

        // 'is any of' / 'is none of' — only WP/WC sources have a real,
        // boundable set of values to check off; a supplier feed keeps the
        // plain input and takes a delimiter-joined list.
        if (isApp) {
            loadFieldValuesForChecklist($condRow, source, field);
            return;
        }

        closeChecklist($condRow);
        $checklist.addClass(CSS.HIDDEN);
        $valueInput.show().attr('placeholder', 'value1' + LIST_VALUE_DELIMITER + 'value2' + LIST_VALUE_DELIMITER + '…');
    }

    /* ── Value checklist / picker. The text input stays the single source of
       truth for the value; the checklist and picker only write into it. ── */

    const conditionFieldValuesCache = {};

    function splitListValue(str) {
        return String(str || '').split(LIST_VALUE_DELIMITER)
            .map(function(v) { return v.trim(); })
            .filter(function(v) { return v !== ''; });
    }

    function joinListValue(values) {
        return values.join(LIST_VALUE_DELIMITER);
    }

    function closeChecklist($condRow) {
        $condRow.find(SELECTORS.COND_VALUE_CHECKLIST_PANEL).addClass(CSS.HIDDEN);
    }

    function syncChecklistLabel($condRow) {
        const n = splitListValue($condRow.find(SELECTORS.COND_VALUE_SEL).val()).length;
        $condRow.find(SELECTORS.COND_VALUE_CHECKLIST_LABEL).text(n > 0 ? (n + ' selected') : 'Select values…');
    }

    function renderChecklistOptions($condRow, values) {
        const $options = $condRow.find(SELECTORS.COND_VALUE_CHECKLIST_OPTIONS);
        const selected = splitListValue($condRow.find(SELECTORS.COND_VALUE_SEL).val())
            .map(function(v) { return v.toLowerCase(); });

        if (!values.length) {
            $options.html('<span class="mmi-cvc-empty">No values found</span>');
            return;
        }

        let html = '';
        values.forEach(function(v) {
            const checked = selected.indexOf(String(v).toLowerCase()) !== -1 ? ' checked' : '';
            html += '<label class="mmi-cvc-option"><input type="checkbox" value="' + escHtml(v) + '"' + checked + '> <span>' + escHtml(v) + '</span></label>';
        });
        $options.html(html);
    }

    /**
     * The real values a WP/WC field can hold — a taxonomy's term names, or a
     * post field's / meta key's distinct values when that set is bounded.
     * Cached per endpoint+source+field; calls done(values, unsupported).
     */
    function fetchFieldValues($condRow, source, field, done) {
        const ep       = endpoints($condRow);
        const cacheKey = ep.terms + '|' + ep.values + '::' + source + '::' + field;
        if (Object.prototype.hasOwnProperty.call(conditionFieldValuesCache, cacheKey)) {
            const cached = conditionFieldValuesCache[cacheKey];
            done(cached.values, cached.unsupported);
            return;
        }
        const store = function(values, unsupported) {
            conditionFieldValuesCache[cacheKey] = { values: values, unsupported: unsupported };
            done(values, unsupported);
        };
        if (source === TAXONOMY_SOURCE) {
            if (!ep.terms) { done([], true); return; }
            queuedPost(ep.ajaxUrl, { action: ep.terms, taxonomy: field, nonce: ep.nonce })
                .done(function(response) {
                    const terms  = (response && response.success && response.data.terms) ? response.data.terms : [];
                    const values = terms.map(function(t) { return t.name; });
                    store(values, values.length === 0);
                })
                .fail(function() { done([], true); });
        } else {
            if (!ep.values) { done([], true); return; }
            queuedPost(ep.ajaxUrl, { action: ep.values, field: field, nonce: ep.nonce })
                .done(function(response) {
                    const data   = (response && response.success && response.data) ? response.data : {};
                    const values = data.values || [];
                    store(values, !!data.unsupported || !!data.truncated || values.length === 0);
                })
                .fail(function() { done([], true); });
        }
    }

    function loadValuePicker($condRow, source, field) {
        const $valueInput = $condRow.find(SELECTORS.COND_VALUE_SEL);
        const $select     = $condRow.find(SELECTORS.COND_VALUE_SELECT);
        fetchFieldValues($condRow, source, field, function(values, unsupported) {
            const op = $condRow.find(SELECTORS.COND_OPERATOR_SEL).val() || '';
            if (PICK_OPERATORS.indexOf(op) === -1) return; // operator changed while loading
            if (unsupported || !values.length) {
                $select.addClass(CSS.HIDDEN);
                $valueInput.show().attr('placeholder', 'Value…');
                return;
            }
            const current = String($valueInput.val() || '');
            const match   = values.find(function(v) { return String(v).toLowerCase() === current.toLowerCase(); });
            let opts = '<option value="">Select…</option>';
            if (current && match === undefined) {
                opts += `<option value="${escHtml(current)}">${escHtml(current)}</option>`;
            }
            values.forEach(function(v) { opts += `<option value="${escHtml(v)}">${escHtml(v)}</option>`; });
            $select.html(opts).val(match !== undefined ? match : current).prop('disabled', $valueInput.prop('disabled'));
            $valueInput.hide();
            $select.removeClass(CSS.HIDDEN);
        });
    }

    function loadFieldValuesForChecklist($condRow, source, field) {
        const $valueInput = $condRow.find(SELECTORS.COND_VALUE_SEL);
        const $checklist  = $condRow.find(SELECTORS.COND_VALUE_CHECKLIST);
        fetchFieldValues($condRow, source, field, function(values, unsupported) {
            if (unsupported || !values || !values.length) {
                closeChecklist($condRow);
                $checklist.addClass(CSS.HIDDEN);
                $valueInput.show().attr('placeholder', 'value1' + LIST_VALUE_DELIMITER + 'value2' + LIST_VALUE_DELIMITER + '…');
                return;
            }
            const op = $condRow.find(SELECTORS.COND_OPERATOR_SEL).val() || '';
            if (LIST_OPERATORS.indexOf(op) === -1) return; // operator changed while loading
            renderChecklistOptions($condRow, values);
            syncChecklistLabel($condRow);
            $valueInput.hide();
            $checklist.removeClass(CSS.HIDDEN);
        });
    }

    /* ── Reading conditions back out ────────────────────────────────────── */

    /** True when a condition row has both a Source and a Field picked. */
    function isConditionComplete($condRow) {
        return !!($condRow.find(SELECTORS.COND_SOURCE_SEL).val() && $condRow.find(SELECTORS.COND_FIELD_SEL).val());
    }

    /**
     * Every complete condition inside $scope, in row order:
     * {source, field, operator, value, case_sensitive?}. Incomplete rows are
     * skipped — callers that must not silently drop one check
     * isConditionComplete() first.
     */
    function collectConditions($scope) {
        const conditions = [];
        $scope.find(SELECTORS.CONDITION_ROW).each(function() {
            const $row = $(this);
            if (!isConditionComplete($row)) return;
            const cond = {
                source:   $row.find(SELECTORS.COND_SOURCE_SEL).val(),
                field:    $row.find(SELECTORS.COND_FIELD_SEL).val(),
                operator: $row.find(SELECTORS.COND_OPERATOR_SEL).val() || 'equals',
                value:    $row.find(SELECTORS.COND_VALUE_SEL).val() || '',
            };
            if ($row.find(SELECTORS.COND_CASE_INPUT).is(':checked')) {
                cond.case_sensitive = 1;
            }
            conditions.push(cond);
        });
        return conditions;
    }

    /** {match_logic, conditions} for one builder root. */
    function collect($root) {
        const $ml = $root.find(SELECTORS.MATCH_LOGIC);
        return {
            match_logic: $ml.length ? ($ml.val() || 'all') : null,
            conditions:  collectConditions($root.find(SELECTORS.LIST)),
        };
    }

    /* ── Rows ────────────────────────────────────────────────────────────── */

    function rowTemplate($list) {
        const own = rootOf($list).children(SELECTORS.ROW_TEMPLATE)[0];
        return own || document.querySelector(SELECTORS.LEGACY_TEMPLATE);
    }

    /** Append a blank condition row to $list (from its builder's template). */
    function appendConditionRow($list) {
        const tpl = rowTemplate($list);
        if (!tpl || !tpl.content.firstElementChild) return null;
        const $row = $(tpl.content.firstElementChild.cloneNode(true));
        $row.attr(CSS.READY_ATTR, '1');
        $list.append($row);
        updateEmptyHint(rootOf($list));
        return $row;
    }

    function updateEmptyHint($root) {
        if (!$root.length) return;
        const n = $root.find(SELECTORS.LIST + ' ' + SELECTORS.CONDITION_ROW).length;
        $root.find(SELECTORS.EMPTY_HINT).toggleClass(CSS.HIDDEN, n > 0);
    }

    /** Restore every server-rendered saved row inside $scope not yet restored. */
    function initSavedConditions($scope) {
        $scope.find(SELECTORS.CONDITION_ROW + '[data-saved-source]:not([' + CSS.READY_ATTR + '])').each(function() {
            // Rows inside a <template> are inert clones-to-be, never live.
            if ($(this).closest('template').length) return;
            const $row = $(this);
            initConditionCascade($row, $row.data('saved-source') || '', String($row.data('saved-field') || ''),
                $row.data('saved-operator') || 'equals', String($row.data('saved-value') ?? ''));
        });
    }

    /** Replace or extend a builder's conditions with a saved set. */
    function loadConditions($root, conditions, matchLogic, replace) {
        const $list = $root.find(SELECTORS.LIST).first();
        if (replace) {
            $list.children(SELECTORS.CONDITION_ROW).remove();
        }
        let loaded = 0;
        const skipped = [];
        (conditions || []).forEach(function(cond) {
            const $row = appendConditionRow($list);
            if (!$row) return;
            if (!sourceOption($row, cond.source).length) {
                // This builder doesn't offer that Source (e.g. a supplier
                // not configured here) — say so rather than load a row that
                // silently matches nothing.
                $row.remove();
                skipped.push(cond.field);
                return;
            }
            $row.find(SELECTORS.COND_CASE_INPUT).prop('checked', !!cond.case_sensitive);
            initConditionCascade($row, cond.source, cond.field, cond.operator || 'equals', cond.value || '');
            loaded++;
        });
        if (replace && matchLogic) {
            $root.find(SELECTORS.MATCH_LOGIC).val(matchLogic).trigger('change');
        }
        updateEmptyHint($root);
        notifyChange($root);
        return { loaded: loaded, skipped: skipped };
    }

    /* ── Passing notice beside the builder's buttons ───────────────────── */

    function showNotice($root, text) {
        const $n = $root.find(SELECTORS.NOTICE).first();
        clearTimeout($n.data('timer'));
        $n.text(text).addClass('is-visible');
        $n.data('timer', setTimeout(function() { $n.removeClass('is-visible'); }, 6000));
    }

    /* ── Saved-conditions library ───────────────────────────────────────── */

    let librarySets = null; // one fetch per page view; reopened panels reuse it

    function describeCondition(c) {
        const noValue = c.operator === 'is_empty' || c.operator === 'is_not_empty';
        return c.field + ' ' + (OPERATOR_WORDS[c.operator] || c.operator) + (noValue ? '' : ' "' + c.value + '"');
    }

    function renderLibrary($root) {
        const $list   = $root.find(SELECTORS.LIBRARY_LIST);
        const term    = ($root.find(SELECTORS.LIBRARY_SEARCH).val() || '').toLowerCase();
        const context = $root.attr('data-cb-context') || '';
        const sets    = (librarySets || []).filter(function(s) {
            if (!term) return true;
            const hay = (s.group + ' ' + s.label + ' ' + s.conditions.map(describeCondition).join(' ')).toLowerCase();
            return hay.indexOf(term) !== -1;
        });

        if (!sets.length) {
            $list.html('<p class="mmi-cb-library-empty">' + (librarySets && librarySets.length ? 'No saved conditions match that search.' : 'No saved conditions anywhere yet.') + '</p>');
            return;
        }

        let html = '';
        let group = null;
        sets.forEach(function(s) {
            if (s.group !== group) {
                group = s.group;
                html += '<div class="mmi-cb-library-group">' + escHtml(group) + '</div>';
            }
            const idx     = librarySets.indexOf(s);
            const isSelf  = context && s.id === context;
            const logic   = s.conditions.length > 1 ? (s.match_logic === 'any' ? ' · any' : ' · all') : '';
            html += '<div class="mmi-cb-library-item" data-index="' + idx + '">' +
                '<div class="mmi-cb-library-text">' +
                    '<span class="mmi-cb-library-name">' + escHtml(s.label) + (isSelf ? ' <em>(this one)</em>' : '') + '</span>' +
                    '<span class="mmi-cb-library-summary">' + escHtml(s.conditions.map(describeCondition).join('; ')) +
                        ' <span class="mmi-cb-library-meta">(' + s.conditions.length + ' condition' + (s.conditions.length === 1 ? '' : 's') + logic + ')</span></span>' +
                '</div>' +
                '<button type="button" class="button button-small mmi-cb-library-apply" data-mode="replace" title="Clear this builder\'s conditions and use these">Replace</button>' +
                '<button type="button" class="button button-small mmi-cb-library-apply" data-mode="add" title="Keep this builder\'s conditions and add these">Add</button>' +
            '</div>';
        });
        $list.html(html);
    }

    function openLibrary($root) {
        const $panel = $root.find(SELECTORS.LIBRARY);
        const $btn   = $root.find(SELECTORS.LIBRARY_BTN);
        const wasOpen = !$panel.hasClass(CSS.HIDDEN);
        $(SELECTORS.LIBRARY).addClass(CSS.HIDDEN);
        $(SELECTORS.LIBRARY_BTN).attr('aria-expanded', 'false');
        if (wasOpen) return;

        $panel.removeClass(CSS.HIDDEN);
        $btn.attr('aria-expanded', 'true');
        $panel.find(SELECTORS.LIBRARY_SEARCH).val('').trigger('focus');

        if (librarySets) {
            renderLibrary($root);
            return;
        }
        const $list = $panel.find(SELECTORS.LIBRARY_LIST).html('<p class="mmi-cb-library-empty">Loading…</p>');
        $.post(endpoints($root).ajaxUrl, { action: 'mmi_condition_library', nonce: $root.attr('data-cb-library-nonce') || '' })
            .done(function(response) {
                if (response && response.success) {
                    librarySets = response.data.sets || [];
                    renderLibrary($root);
                } else {
                    $list.html('<p class="mmi-cb-library-empty">' + escHtml((response && response.data && response.data.message) || 'Could not load saved conditions.') + '</p>');
                }
            })
            .fail(function() {
                $list.html('<p class="mmi-cb-library-empty">Network error — please try again.</p>');
            });
    }

    /* ── Init: restore a builder's saved rows the first time it's visible ── */

    function initRoot(root) {
        const $root = $(root);
        initSavedConditions($root);
        updateEmptyHint($root);
    }

    let visibilityObserver = null;

    function watchRoot(root) {
        if (root.hasAttribute('data-cb-watched') || $(root).closest('template').length) return;
        root.setAttribute('data-cb-watched', '1');
        updateEmptyHint($(root));
        if (!visibilityObserver) {
            initRoot(root);
            return;
        }
        visibilityObserver.observe(root);
    }

    function initWithin(el) {
        const scope = el || document;
        $(scope).find(SELECTORS.ROOT).addBack(SELECTORS.ROOT).each(function() { watchRoot(this); });
    }

    function observe() {
        if (window.IntersectionObserver) {
            visibilityObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (!entry.isIntersecting) return;
                    visibilityObserver.unobserve(entry.target);
                    initRoot(entry.target);
                });
            });
        }
        if (window.MutationObserver && document.body) {
            new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    Array.prototype.forEach.call(m.addedNodes, function(n) {
                        if (n.nodeType === 1) initWithin(n);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    /* ── Event bindings (document-delegated) ────────────────────────────── */

    let bound = false;
    function bind() {
        if (bound) return;
        bound = true;
        $(document)
            .on('click', SELECTORS.ROOT + ' ' + SELECTORS.ADD_BTN, function() {
                const $root = rootOf($(this));
                const $row  = appendConditionRow($root.find(SELECTORS.LIST).first());
                if ($row) {
                    initConditionCascade($row);
                    $row.find(SELECTORS.COND_SOURCE_SEL).trigger('focus');
                }
                notifyChange($root);
            })

            .on('click', SELECTORS.ROOT + ' ' + SELECTORS.REMOVE_BTN, function() {
                const $root = rootOf($(this));
                $(this).closest(SELECTORS.CONDITION_ROW).remove();
                updateEmptyHint($root);
                notifyChange($root);
            })

            .on('change', SELECTORS.ROOT + ' ' + SELECTORS.MATCH_LOGIC, function() {
                notifyChange(rootOf($(this)));
            })

            .on('change', SELECTORS.COND_SOURCE_SEL, function() {
                const $row   = $(this).closest(SELECTORS.CONDITION_ROW);
                const source = $(this).val() || '';
                updateOriginBadge($row, source);
                $row.find(SELECTORS.COND_FIELD_SEL).val('');
                if (source) {
                    loadCascadeFields($row, source);
                } else {
                    $row.find(SELECTORS.COND_FIELD_SEL).html(`<option value="">${fieldPlaceholder('')}</option>`).prop('disabled', true);
                    $row.find(SELECTORS.COND_OPERATOR_SEL).prop('disabled', true).val('equals');
                    $row.find(SELECTORS.COND_VALUE_SEL).val('').prop('disabled', true).show();
                    $row.find(SELECTORS.COND_VALUE_SELECT).addClass(CSS.HIDDEN);
                    closeChecklist($row);
                    $row.find(SELECTORS.COND_VALUE_CHECKLIST).addClass(CSS.HIDDEN);
                }
                notifyChange($row);
            })

            .on('change', SELECTORS.COND_FIELD_SEL, function() {
                const $row  = $(this).closest(SELECTORS.CONDITION_ROW);
                const field = $(this).val() || '';
                const $op   = $row.find(SELECTORS.COND_OPERATOR_SEL);
                const $val  = $row.find(SELECTORS.COND_VALUE_SEL);
                if (field) {
                    $op.prop('disabled', false);
                    $val.prop('disabled', false);
                    updateValueVisibility($row);
                } else {
                    $op.prop('disabled', true).val('equals');
                    $val.val('').prop('disabled', true).show();
                    $row.find(SELECTORS.COND_VALUE_SELECT).addClass(CSS.HIDDEN);
                    closeChecklist($row);
                    $row.find(SELECTORS.COND_VALUE_CHECKLIST).addClass(CSS.HIDDEN);
                }
                notifyChange($row);
            })

            .on('change', SELECTORS.COND_OPERATOR_SEL, function() {
                const $row = $(this).closest(SELECTORS.CONDITION_ROW);
                updateValueVisibility($row);
                notifyChange($row);
            })

            .on('change', SELECTORS.COND_VALUE_SELECT, function() {
                $(this).closest(SELECTORS.CONDITION_ROW).find(SELECTORS.COND_VALUE_SEL).val($(this).val()).trigger('input');
            })

            .on('input', SELECTORS.COND_VALUE_SEL, function() {
                notifyChange($(this).closest(SELECTORS.CONDITION_ROW));
            })

            .on('change', SELECTORS.COND_CASE_INPUT, function() {
                notifyChange($(this).closest(SELECTORS.CONDITION_ROW));
            })

            // Value checklist — one open at a time on the page.
            .on('click', SELECTORS.COND_VALUE_CHECKLIST_TRIGGER, function(e) {
                e.stopPropagation();
                const $condRow = $(this).closest(SELECTORS.CONDITION_ROW);
                const $panel   = $condRow.find(SELECTORS.COND_VALUE_CHECKLIST_PANEL);
                const wasOpen  = !$panel.hasClass(CSS.HIDDEN);
                $(SELECTORS.COND_VALUE_CHECKLIST_PANEL).addClass(CSS.HIDDEN);
                if (!wasOpen) {
                    $panel.removeClass(CSS.HIDDEN);
                    $panel.find(SELECTORS.COND_VALUE_CHECKLIST_SEARCH).val('').trigger('input');
                }
            })

            .on('change', SELECTORS.COND_VALUE_CHECKLIST_OPTIONS + ' input[type="checkbox"]', function() {
                const $condRow = $(this).closest(SELECTORS.CONDITION_ROW);
                const checked  = [];
                $condRow.find(SELECTORS.COND_VALUE_CHECKLIST_OPTIONS + ' input[type="checkbox"]:checked').each(function() {
                    checked.push($(this).val());
                });
                $condRow.find(SELECTORS.COND_VALUE_SEL).val(joinListValue(checked)).trigger('input');
                syncChecklistLabel($condRow);
            })

            .on('input', SELECTORS.COND_VALUE_CHECKLIST_SEARCH, function() {
                const term = $(this).val().toLowerCase();
                $(this).closest(SELECTORS.COND_VALUE_CHECKLIST_PANEL)
                    .find(SELECTORS.COND_VALUE_CHECKLIST_OPTION)
                    .each(function() {
                        $(this).toggle($(this).text().toLowerCase().indexOf(term) !== -1);
                    });
            })

            // Saved-conditions library.
            .on('click', SELECTORS.ROOT + ' ' + SELECTORS.LIBRARY_BTN, function(e) {
                e.stopPropagation();
                openLibrary(rootOf($(this)));
            })

            .on('input', SELECTORS.LIBRARY_SEARCH, function() {
                renderLibrary(rootOf($(this)));
            })

            .on('click', SELECTORS.LIBRARY_APPLY, function() {
                const $root = rootOf($(this));
                const set   = (librarySets || [])[parseInt($(this).closest(SELECTORS.LIBRARY_ITEM).data('index'), 10)];
                if (!set) return;
                const result = loadConditions($root, set.conditions, set.match_logic, $(this).data('mode') === 'replace');
                $root.find(SELECTORS.LIBRARY).addClass(CSS.HIDDEN);
                $root.find(SELECTORS.LIBRARY_BTN).attr('aria-expanded', 'false');
                let msg = 'Loaded ' + result.loaded + ' condition' + (result.loaded === 1 ? '' : 's') + ' from “' + set.label + '”.';
                if (result.skipped.length) {
                    msg += ' Skipped ' + result.skipped.length + ' (' + result.skipped.join(', ') + ') — that source isn’t available here.';
                }
                showNotice($root, msg);
            })

            // Click outside closes an open checklist / library panel.
            .on('click', function(e) {
                if (!$(e.target).closest(SELECTORS.COND_VALUE_CHECKLIST).length) {
                    $(SELECTORS.COND_VALUE_CHECKLIST_PANEL).addClass(CSS.HIDDEN);
                }
                if (!$(e.target).closest(SELECTORS.LIBRARY).length) {
                    $(SELECTORS.LIBRARY).addClass(CSS.HIDDEN);
                    $(SELECTORS.LIBRARY_BTN).attr('aria-expanded', 'false');
                }
            })
            ;
    }

    window.MMIConditionBuilder = {
        SELECTORS,
        CHANGE_EVENT,
        bind,
        init: initWithin,
        initRoot,
        collect,
        collectConditions,
        isConditionComplete,
        appendConditionRow,
        initConditionCascade,
        initSavedConditions,
        loadConditions,
        updateEmptyHint,
        updateOriginBadge,
        updateValueVisibility,
        showNotice,
        splitListValue,
        joinListValue,
    };

    bind();
    $(function() {
        observe();
        initWithin(document);
    });

}(jQuery));
