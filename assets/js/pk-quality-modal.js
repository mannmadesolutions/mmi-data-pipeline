/**
 * Primary Key Data Quality — inline review/dismiss panel
 *
 * Embedded (not a separate overlay) in two places: the Configure modal's
 * "Data Parsing" tab (pipeline-step-1-acquisition.php) and, once per source
 * row, the wizard's inline PK editor (wizard-sources-pk-editor.php). Both
 * triggers share the .mmi-pk-quality-trigger class and one delegated click
 * handler below — a popup-modal-on-top-of-an-already-open-modal was
 * confusing, so this toggles a panel embedded in whichever container the
 * trigger lives in instead (see panel-pk-quality.php).
 */
(function ($) {
    'use strict';

    const AJAX_URL = (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php';
    const NONCE = (window.mmiImportSettings && window.mmiImportSettings.nonce) || '';

    function escapeHtml(text) {
        return $('<div>').text(text == null ? '' : String(text)).html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // The panel is always the next .mmi-pk-quality-panel found within the
    // trigger's nearest recognized container — .mmi-source-pk-editor for the
    // wizard (one panel per source row) or .mmi-config-field for the
    // Configure modal (one panel, shared across whichever source is open).
    function resolvePanel($btn) {
        return $btn.closest('.mmi-config-field, .mmi-source-pk-editor').find('.mmi-pk-quality-panel').first();
    }

    function isWizardTrigger($btn) {
        return $btn.closest('.mmi-source-pk-editor').length > 0;
    }

    // Live field selection for the wizard's own row — data-supplier lives on
    // the button itself there. The Configure-modal button carries neither
    // (its markup is shared across every source the modal ever opens for),
    // so it falls back to the modal's own hidden supplier field and leaves
    // pkField blank, which the report endpoint resolves to whatever's
    // actually saved for that source.
    function resolveContext($btn) {
        if (isWizardTrigger($btn)) {
            const $group = $btn.closest('.mmi-source-pk-editor');
            const $select = $group.find('.primary-key-source-input');
            let pkField = $select.val();
            if (pkField === '__custom__') {
                pkField = $group.find('.primary-key-source-custom-input').val();
            }
            return { supplier: $btn.data('supplier'), pkField: pkField };
        }
        return { supplier: $('#mmi-config-supplier-id').val(), pkField: '' };
    }

    function showStatus($panel, text, isError) {
        const $status = $panel.find('.mmi-pk-quality-save-status');
        $status.text(text).toggleClass('mmi-pk-quality-status-error', !!isError);
        if (text) {
            setTimeout(function () { $status.text(''); }, 2500);
        }
    }

    function updateSelectedCount($section) {
        const $rowCbs = $section.find('.mmi-pk-quality-row-cb');
        const $checked = $rowCbs.filter(':checked');
        $section.find('.mmi-pk-quality-selected-count').text($checked.length);
        $section.find('.mmi-pk-quality-dismiss-selected').toggleClass('mmi-is-hidden', $checked.length === 0);
        $section.find('.mmi-pk-quality-select-all-cb').prop(
            'checked', $rowCbs.length > 0 && $checked.length === $rowCbs.length
        );
    }

    function renderReport($panel, data) {
        $panel.data('supplier', data.supplier);
        $panel.data('pkField', data.pk_field);

        $panel.find('.mmi-pk-quality-subtitle').text(
            'Field: ' + data.pk_field + ' — ' + data.total + ' total records'
        );

        const blankRows = data.blank_rows || [];
        const duplicateGroups = data.duplicate_groups || [];

        const $blankSection = $panel.find('.mmi-pk-quality-blank-section');
        const $duplicateSection = $panel.find('.mmi-pk-quality-duplicate-section');

        $blankSection.find('.mmi-pk-quality-count').text(blankRows.length);
        $duplicateSection.find('.mmi-pk-quality-count').text(duplicateGroups.length);

        const $blankList = $blankSection.find('.mmi-pk-quality-blank-list').empty();
        blankRows.forEach(function (row) {
            $blankList.append(
                '<li class="mmi-pk-quality-row">' +
                    '<label class="mmi-pk-quality-row-select">' +
                        '<input type="checkbox" class="mmi-pk-quality-row-cb" value="' + row.index + '">' +
                        '<span class="mmi-pk-quality-row-label">Row ' + (row.index + 1) + ': ' + escapeHtml(row.label) + '</span>' +
                    '</label>' +
                    '<button type="button" class="button-link mmi-pk-quality-dismiss-one" data-type="blank" data-value="' + row.index + '">Dismiss</button>' +
                '</li>'
            );
        });

        const $duplicateList = $duplicateSection.find('.mmi-pk-quality-duplicate-list').empty();
        duplicateGroups.forEach(function (group) {
            const rowLabels = group.rows.map(function (r) {
                return 'Row ' + (r.index + 1) + ': ' + escapeHtml(r.label);
            }).join('<br>');
            $duplicateList.append(
                '<div class="mmi-pk-quality-group">' +
                    '<div class="mmi-pk-quality-group-header">' +
                        '<label class="mmi-pk-quality-row-select">' +
                            '<input type="checkbox" class="mmi-pk-quality-row-cb" value="' + escapeHtml(group.value) + '">' +
                            '<strong>\'' + escapeHtml(group.value) + '\'</strong> — ' + group.rows.length + ' records' +
                        '</label>' +
                        '<button type="button" class="button-link mmi-pk-quality-dismiss-one" data-type="duplicate" data-value="' + escapeHtml(group.value) + '">Dismiss</button>' +
                    '</div>' +
                    '<div class="mmi-pk-quality-group-rows">' + rowLabels + '</div>' +
                '</div>'
            );
        });

        $blankSection.toggleClass('mmi-is-hidden', blankRows.length === 0);
        $duplicateSection.toggleClass('mmi-is-hidden', duplicateGroups.length === 0);
        $panel.find('.mmi-pk-quality-empty').toggleClass('mmi-is-hidden', blankRows.length > 0 || duplicateGroups.length > 0);

        updateSelectedCount($blankSection);
        updateSelectedCount($duplicateSection);

        $panel.find('.mmi-pk-quality-loading').addClass('mmi-is-hidden');
        $panel.find('.mmi-pk-quality-error').addClass('mmi-is-hidden');
        $panel.find('.mmi-pk-quality-content').removeClass('mmi-is-hidden');
    }

    function loadReport($panel, supplier, pkField) {
        $panel.find('.mmi-pk-quality-loading').removeClass('mmi-is-hidden');
        $panel.find('.mmi-pk-quality-content').addClass('mmi-is-hidden');
        $panel.find('.mmi-pk-quality-error').addClass('mmi-is-hidden');

        return $.ajax({
            url: AJAX_URL,
            type: 'POST',
            data: {
                action: 'mmi_pipeline_get_pk_quality_report',
                nonce: NONCE,
                supplier: supplier,
                pk_field: pkField || '',
            },
        }).then(function (response) {
            if (!response || !response.success) {
                const msg = (response && response.data && response.data.message) || 'Could not load the report.';
                $panel.find('.mmi-pk-quality-loading').addClass('mmi-is-hidden');
                $panel.find('.mmi-pk-quality-error').text(msg).removeClass('mmi-is-hidden');
                return;
            }
            renderReport($panel, response.data);
        }, function () {
            $panel.find('.mmi-pk-quality-loading').addClass('mmi-is-hidden');
            $panel.find('.mmi-pk-quality-error').text('Server error while loading the report.').removeClass('mmi-is-hidden');
        });
    }

    function fetchIssueCount(supplier, pkField) {
        return $.ajax({
            url: AJAX_URL,
            type: 'POST',
            data: {
                action: 'mmi_pipeline_get_pk_quality_report',
                nonce: NONCE,
                supplier: supplier,
                pk_field: pkField || '',
            },
        }).then(function (response) {
            if (!response || !response.success) {
                return 0;
            }
            return (response.data.blank_rows || []).length + (response.data.duplicate_groups || []).length;
        }, function () {
            return 0;
        });
    }

    function setBadge($btn, count) {
        const $badge = $btn.find('.mmi-pk-quality-alert-badge');
        if (count > 0) {
            $badge.text('⚠ ' + count).removeClass('mmi-is-hidden');
        } else {
            $badge.addClass('mmi-is-hidden').text('');
        }
    }

    function checkWizardRowBadge($group) {
        const $btn = $group.find('.mmi-pk-quality-check-btn');
        const supplier = $btn.data('supplier');
        const $select = $group.find('.primary-key-source-input');
        let pkField = $select.val();
        if (pkField === '__custom__') {
            pkField = $group.find('.primary-key-source-custom-input').val();
        }
        if (!supplier || !pkField) {
            setBadge($btn, 0);
            return;
        }
        fetchIssueCount(supplier, pkField).then(function (count) { setBadge($btn, count); });
    }

    window.MMIPkQualityModal = {
        // Called directly by import-pipeline-config.js right after it sets
        // #mmi-config-supplier-id — .val() doesn't fire 'change', so this is
        // a real function call, not an event listener that would never run.
        onConfigModalOpen: function (supplierId) {
            const $panel = $('#mmi-config-modal .mmi-pk-quality-panel');
            // Collapse and blank out whatever the panel showed for a
            // previously-configured source — reopening Configure for a
            // different supplier must never leave stale content visible.
            $panel.addClass('mmi-is-hidden');
            $panel.find('.mmi-pk-quality-content, .mmi-pk-quality-loading, .mmi-pk-quality-error').addClass('mmi-is-hidden');

            const $btn = $('#cfg-check-pk-quality-btn');
            if (!supplierId) {
                setBadge($btn, 0);
                return;
            }
            fetchIssueCount(supplierId).then(function (count) { setBadge($btn, count); });
        },
    };

    // ─── Trigger: unified click handler for both entry points ────────────
    $(document).on('click', '.mmi-pk-quality-trigger', function () {
        const $btn = $(this);
        const $panel = resolvePanel($btn);
        if (!$panel.length) {
            return;
        }

        if (!$panel.hasClass('mmi-is-hidden')) {
            $panel.addClass('mmi-is-hidden'); // acts as a collapse toggle when already open
            return;
        }

        const ctx = resolveContext($btn);
        if (!ctx.supplier) {
            window.alert('Could not identify this data source.');
            return;
        }
        // The wizard context needs an actual field selection to check; the
        // Configure-modal context has no field control of its own — it
        // reviews whatever's already saved — so an empty pkField there is
        // expected, not an error.
        if (isWizardTrigger($btn) && !ctx.pkField) {
            window.alert('Choose a "Field in the file" value first, then check its data quality.');
            return;
        }

        $panel.removeClass('mmi-is-hidden');
        loadReport($panel, ctx.supplier, ctx.pkField);
    });

    // ─── Checkbox selection tracking ───────────────────────────────────────
    $(document).on('change', '.mmi-pk-quality-row-cb', function () {
        updateSelectedCount($(this).closest('.mmi-pk-quality-section'));
    });

    $(document).on('change', '.mmi-pk-quality-select-all-cb', function () {
        const $section = $(this).closest('.mmi-pk-quality-section');
        $section.find('.mmi-pk-quality-row-cb').prop('checked', $(this).is(':checked'));
        updateSelectedCount($section);
    });

    // ─── Dismiss one ────────────────────────────────────────────────────────
    $(document).on('click', '.mmi-pk-quality-dismiss-one', function () {
        const $btn = $(this);
        const $panel = $btn.closest('.mmi-pk-quality-panel');
        const supplier = $panel.data('supplier');
        const pkField = $panel.data('pkField');
        const type = $btn.data('type');
        const value = String($btn.data('value'));

        $btn.prop('disabled', true).text('…');

        $.ajax({
            url: AJAX_URL,
            type: 'POST',
            data: {
                action: 'mmi_pipeline_dismiss_pk_quality_issue',
                nonce: NONCE,
                supplier: supplier,
                pk_field: pkField,
                type: type,
                value: value,
            },
        }).then(function (response) {
            if (!response || !response.success) {
                showStatus($panel, (response && response.data && response.data.message) || 'Failed to dismiss', true);
                $btn.prop('disabled', false).text('Dismiss');
                return;
            }
            showStatus($panel, '✓ Dismissed');
            loadReport($panel, supplier, pkField);
        }, function () {
            showStatus($panel, 'Server error', true);
            $btn.prop('disabled', false).text('Dismiss');
        });
    });

    // ─── Dismiss all outstanding (one group) ────────────────────────────────
    $(document).on('click', '.mmi-pk-quality-dismiss-all', function () {
        const $btn = $(this);
        const group = $btn.data('group');
        const $panel = $btn.closest('.mmi-pk-quality-panel');
        const supplier = $panel.data('supplier');
        const pkField = $panel.data('pkField');

        if (!window.confirm('Dismiss all outstanding ' + group + ' primary-key issues for this source? This only stops the warning — it does not change the source data itself.')) {
            return;
        }

        $btn.prop('disabled', true);
        $.ajax({
            url: AJAX_URL,
            type: 'POST',
            data: {
                action: 'mmi_pipeline_dismiss_pk_quality_bulk',
                nonce: NONCE,
                supplier: supplier,
                pk_field: pkField,
            },
        }).then(function (response) {
            $btn.prop('disabled', false);
            if (!response || !response.success) {
                showStatus($panel, (response && response.data && response.data.message) || 'Failed to dismiss', true);
                return;
            }
            showStatus($panel, '✓ All outstanding issues dismissed');
            loadReport($panel, supplier, pkField);
        }, function () {
            $btn.prop('disabled', false);
            showStatus($panel, 'Server error', true);
        });
    });

    // ─── Dismiss selected (checkbox-driven) ─────────────────────────────────
    $(document).on('click', '.mmi-pk-quality-dismiss-selected', function () {
        const $btn = $(this);
        const group = $btn.data('group');
        const $section = $btn.closest('.mmi-pk-quality-section');
        const $panel = $btn.closest('.mmi-pk-quality-panel');
        const supplier = $panel.data('supplier');
        const pkField = $panel.data('pkField');

        const selected = $section.find('.mmi-pk-quality-row-cb:checked').map(function () {
            return $(this).val();
        }).get();
        if (!selected.length) {
            return;
        }

        if (!window.confirm('Dismiss the ' + selected.length + ' selected ' + group + ' issue(s)?')) {
            return;
        }

        const payload = {
            action: 'mmi_pipeline_dismiss_pk_quality_selected',
            nonce: NONCE,
            supplier: supplier,
            pk_field: pkField,
        };
        if (group === 'blank') {
            payload.blank_indices = selected;
        } else {
            payload.duplicate_values = selected;
        }

        $btn.prop('disabled', true);
        $.ajax({
            url: AJAX_URL,
            type: 'POST',
            data: payload,
        }).then(function (response) {
            $btn.prop('disabled', false);
            if (!response || !response.success) {
                showStatus($panel, (response && response.data && response.data.message) || 'Failed to dismiss', true);
                return;
            }
            showStatus($panel, '✓ Selected issues dismissed');
            loadReport($panel, supplier, pkField);
        }, function () {
            $btn.prop('disabled', false);
            showStatus($panel, 'Server error', true);
        });
    });

    // ─── Alert badges — wizard context ──────────────────────────────────────
    //
    // Re-checked whenever a source's checkbox reveals its PK editor (if a
    // field is already saved) and whenever the field selection itself
    // changes — both are genuine "the answer to 'is this OK' may have just
    // changed" moments, not just page-load-once.
    //
    // The checkbox (Step 1, wizard-sources-checklist.php) and its PK editor
    // (Step 2, wizard-sources-pk-editor.php) no longer share a common
    // ancestor — matched up by data-supplier instead, which every
    // .mmi-source-pk-editor carries directly (see that partial).
    $(document).on('change', '.np-source-check', function () {
        if (!this.checked) {
            return;
        }
        const supplierId = $(this).val();
        const $group = $('.mmi-source-pk-editor[data-supplier="' + supplierId + '"]');
        checkWizardRowBadge($group);
    });

    $(document).on('change', '.primary-key-source-input, .primary-key-source-custom-input', function () {
        const $group = $(this).closest('.mmi-source-pk-editor');
        if ($group.length) {
            checkWizardRowBadge($group);
        }
    });

})(jQuery);
