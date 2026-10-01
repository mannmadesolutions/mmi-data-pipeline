/**
 * Rule Builder — the action picker's settings panel (taxonomy/term/mode,
 * price, sale dates, copy-to-meta) shared by Catalog Maintenance's Custom
 * Rules and Product Workbench.
 *
 * Conditions are the suite-wide condition builder now — markup
 * MMI_Condition_Builder, behavior mmi-condition-builder.js (shared library,
 * window.MMIConditionBuilder), styles mmi-suite-common.css — which Field
 * Mapping also uses. The condition helpers are re-exported on
 * window.MMIRuleBuilder so existing callers keep working.
 *
 * Exposes window.MMIRuleBuilder.
 */

(function($) {
    'use strict';

    const CB = window.MMIConditionBuilder;

    /* ── Selectors ──────────────────────────────────────────────────────── */

    const SELECTORS = Object.assign({}, CB.SELECTORS, {
        ACTION_SEL:         '.mmi-custom-rule-action',
        PARAM_GROUP:        '.mmi-crp-group',
        TAX_SELECT:         '.mmi-crp-taxonomy',
        TERM_SELECT:        '.mmi-crp-term',
        TAX_MODE_SELECT:    '.mmi-crp-tax-mode',
        PRICE_INPUT:        '.mmi-crp-price',
        DATE_FROM_INPUT:    '.mmi-crp-date-from',
        DATE_TO_INPUT:      '.mmi-crp-date-to',
        SOURCE_FIELD_INPUT: '.mmi-crp-source-field',
        META_KEY_INPUT:     '.mmi-crp-meta-key',
    });

    const CSS = {
        CASCADE_LOADING: 'mmi-cascade-loading',
    };

    function ajaxUrl() {
        return (window.mmiRuleBuilder && window.mmiRuleBuilder.ajaxUrl)
            || (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php';
    }
    function nonce() {
        return (window.mmiRuleBuilder && window.mmiRuleBuilder.nonce)
            || (window.mmiImportSettings && window.mmiImportSettings.nonce)
            || (window.mmiProductImportData && window.mmiProductImportData.nonce) || '';
    }

    function escHtml(str) {
        return window.MMIEscapeHtml(str);
    }

    /* ── Action-specific extra parameters (taxonomy/term/mode, price, sale
       dates) — only the group matching the row's currently selected action
       is shown; every other group (and its inputs) is hidden and disabled
       so it never gets collected/submitted. ── */

    const taxonomyTermsCache = {};

    function updateActionParamsVisibility($scope) {
        const action = $scope.find(SELECTORS.ACTION_SEL).val() || '';
        $scope.find(SELECTORS.PARAM_GROUP).each(function() {
            const types = ($(this).data('action-types') || '').toString().split(',');
            const show  = types.indexOf(action) !== -1;
            $(this).toggle(show).find('input, select').prop('disabled', !show);
        });
    }

    function loadTaxonomyTerms($paramGroup, taxonomy, savedTermId) {
        const $termSel = $paramGroup.find(SELECTORS.TERM_SELECT);

        if (!taxonomy) {
            $termSel.html('<option value="">Term…</option>').prop('disabled', true);
            return;
        }

        const preserved = $termSel.val() || savedTermId || '';
        $termSel.prop('disabled', true).addClass(CSS.CASCADE_LOADING);

        const applyTerms = function(terms) {
            $termSel.removeClass(CSS.CASCADE_LOADING);
            let opts = '<option value="">Term…</option>';
            (terms || []).forEach(function(t) {
                opts += '<option value="' + escHtml(t.id) + '"' + (String(t.id) === String(preserved) ? ' selected' : '') + '>' + escHtml(t.name) + '</option>';
            });
            $termSel.html(opts).prop('disabled', false);
        };

        if (Object.prototype.hasOwnProperty.call(taxonomyTermsCache, taxonomy)) {
            applyTerms(taxonomyTermsCache[taxonomy]);
            return;
        }

        $.post(ajaxUrl(), {
            action:   'mmi_pipeline_get_taxonomy_terms',
            taxonomy: taxonomy,
            nonce:    nonce(),
        }).done(function(response) {
            const terms = (response.success && response.data.terms) ? response.data.terms : [];
            taxonomyTermsCache[taxonomy] = terms;
            applyTerms(terms);
        }).fail(function() {
            $termSel.removeClass(CSS.CASCADE_LOADING).html('<option value="">Term…</option>').prop('disabled', false);
        });
    }

    function collectActionParams($scope) {
        return {
            taxonomy:     $scope.find(SELECTORS.TAX_SELECT).val()        || '',
            term_id:      $scope.find(SELECTORS.TERM_SELECT).val()       || '',
            mode:         $scope.find(SELECTORS.TAX_MODE_SELECT).val()   || 'replace',
            value:        $scope.find(SELECTORS.PRICE_INPUT).val()       || '',
            date_from:    $scope.find(SELECTORS.DATE_FROM_INPUT).val()   || '',
            date_to:      $scope.find(SELECTORS.DATE_TO_INPUT).val()     || '',
            source_field: $scope.find(SELECTORS.SOURCE_FIELD_INPUT).val() || '',
            meta_key:     $scope.find(SELECTORS.META_KEY_INPUT).val()     || '',
        };
    }


    /* ── Event bindings (document-delegated) ────────────────────────────── */

    let bound = false;
    function bind() {
        if (bound) return;
        bound = true;
        $(document).on('change', SELECTORS.TAX_SELECT, function() {
            const $group   = $(this).closest(SELECTORS.PARAM_GROUP);
            const taxonomy = $(this).val() || '';
            loadTaxonomyTerms($group, taxonomy, '');
        });
    }

    window.MMIRuleBuilder = {
        SELECTORS,
        CHANGE_EVENT:         CB.CHANGE_EVENT,
        bind,
        // Conditions — the shared builder.
        initConditionCascade: CB.initConditionCascade,
        initSavedConditions:  CB.initSavedConditions,
        appendConditionRow:   CB.appendConditionRow,
        updateOriginBadge:    CB.updateOriginBadge,
        updateValueVisibility: CB.updateValueVisibility,
        collectConditions:    CB.collectConditions,
        isConditionComplete:  CB.isConditionComplete,
        // Actions.
        updateActionParamsVisibility,
        loadTaxonomyTerms,
        collectActionParams,
    };

    $(bind);

}(jQuery));
