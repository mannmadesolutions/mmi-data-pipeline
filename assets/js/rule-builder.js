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
        META_VALUE_INPUT:   '.mmi-crp-meta-value',
        FIND_INPUT:         '.mmi-crp-find',
        REPLACE_INPUT:      '.mmi-crp-replace',
        CASE_INPUT:         '.mmi-crp-case-input',
        WC_FIELD_SELECT:    '.mmi-crp-wc-field',
        WC_VALUE:           '.mmi-crp-wc-value',
        WC_CHOICE:          '.mmi-crp-wc-choice',
        WC_INPUT:           '.mmi-crp-wc-input',
    });

    // Placeholder for "Set WooCommerce Field"'s value box, by field type.
    const WC_INPUT_PLACEHOLDERS = { decimal: 'e.g. 2.5, blank clears', integer: 'Whole number', text: 'Text' };

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
        syncWcFieldValue($scope);
    }

    /**
     * "Set WooCommerce Field": show the value picker for the chosen field
     * (or the text box for sizes, numbers and text) and disable the rest,
     * so only the shown one is collected.
     */
    function syncWcFieldValue($scope) {
        const $field = $scope.find(SELECTORS.WC_FIELD_SELECT);
        if (!$field.length) return;
        const field  = $field.val() || '';
        const type   = $field.find('option:selected').attr('data-wc-type') || '';
        const active = !$field.prop('disabled');
        $scope.find(SELECTORS.WC_CHOICE).each(function() {
            const show = $(this).attr('data-wc-field') === field;
            $(this).prop('hidden', !show).prop('disabled', !show || !active);
        });
        const isInput = !!field && type !== 'choice' && type !== 'yesno';
        $scope.find(SELECTORS.WC_INPUT).prop('hidden', !isInput).prop('disabled', !isInput || !active)
            .attr('placeholder', WC_INPUT_PLACEHOLDERS[type] || '');
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
            meta_value:   $scope.find(SELECTORS.META_VALUE_INPUT).val()   || '',
            find:         $scope.find(SELECTORS.FIND_INPUT).val()         || '',
            replace:      $scope.find(SELECTORS.REPLACE_INPUT).val()      || '',
            case_sensitive: $scope.find(SELECTORS.CASE_INPUT).is(':checked') ? '1' : '',
            wc_field:     $scope.find(SELECTORS.WC_FIELD_SELECT).val()    || '',
            wc_value:     $scope.find(SELECTORS.WC_VALUE + ':not(:disabled)').val() || '',
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
        $(document).on('change', SELECTORS.WC_FIELD_SELECT, function() {
            const $group = $(this).closest(SELECTORS.PARAM_GROUP);
            // A new field starts from a blank value, not the last field's.
            $group.find(SELECTORS.WC_INPUT).val('');
            $group.find(SELECTORS.WC_CHOICE).val('');
            syncWcFieldValue($group);
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
