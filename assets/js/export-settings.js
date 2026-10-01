/**
 * MMI Data Pipeline — Export Tab Controller
 *
 * Handles: data-type switching (loads per-type scope fields via AJAX),
 * profile create/edit/duplicate/delete, profile card switching, the
 * schedule toggle panel, and the JS-driven batch-export run/poll loop.
 * Structural sibling of import-settings.js / import-pipeline.js but scoped
 * to the Export tab only — deliberately mirrors that file's patterns
 * (profile cards, wizard modal, schedule toggle) since import and export
 * are two sides of the same coin and should behave like it.
 *
 * Field-mapping (enabled/output-column/transform per field) used to be
 * autosaved from here via a standalone "Step 2" table — that table was
 * folded into export-preview.js's Columns popover (it was redundant with,
 * and could drift out of sync with, the preview's own column controls).
 * That save logic now lives in export-preview.js alongside the popover
 * that drives it.
 *
 * @package MannMade\DataPipeline
 */

(function ($) {
    'use strict';

    const SELECTORS = {
        DATA_TYPE_SELECT:   '#mmi-export-data-type',
        SCOPE_FIELDS_WRAP:  '#mmi-export-scope-fields',
        SCOPE_FIELD:        '.mmi-scope-field',
        SCOPE_INNER:        '.mmi-export-scope-inner',
        SCHEDULE_FREQUENCY: '#mmi-export-schedule-frequency',
        SCHEDULE_FORMAT:    '#mmi-export-schedule-format',
        SCHEDULE_TOGGLE:    '#mmi-export-schedule-toggle',
        SCHEDULE_PANEL:     '#mmi-export-topbar-schedule-panel',
        STATUS_PANEL:       '#manual-export-status',
        RUN_EXPORT_BTN:     '#run-manual-export',

        // Profile cards / hidden select
        HIDDEN_SELECT:      '#mmi-export-profile',
        PROFILE_CARD:       '#mmi-export-profile-cards-grid .mmi-profile-grid-card:not(.mmi-pgc-new-card)',
        NEW_CARD:           '#mmi-export-pgc-new-card',

        // New profile wizard
        NEW_PROFILE_BTN:     '#new-export-profile-btn, #no-export-profiles-create-btn-grid, #mmi-export-pgc-new-card',
        NEW_PROFILE_MODAL:   '#new-export-profile-modal',
        NEW_PROFILE_CLOSE:   '#new-export-profile-modal-close, #new-export-profile-modal-cancel',
        NEW_PROFILE_CREATE:  '#new-export-profile-modal-create',
        NEW_PROFILE_NAME:    '#new-export-profile-name',
        NEW_PROFILE_NEXT:    '#mmi-export-wizard-next',
        NEW_PROFILE_BACK:    '#mmi-export-wizard-back',
        NEW_PROFILE_BREADCRUMB: '#mmi-export-wizard-breadcrumb',
        DATA_TYPE_CARD:      '#mmi-export-data-type-cards .mmi-scope-card',

        // Edit profile modal
        EDIT_BTN:    '#edit-export-profile-btn',
        EDIT_MODAL:  '#edit-export-profile-modal',
        EDIT_CLOSE:  '#eep-modal-close, #eep-modal-cancel',
        EDIT_SAVE:   '#eep-modal-save',
        EDIT_NAME:   '#eep-profile-name',
        EDIT_TYPE:   '#eep-data-type',

        DELETE_PROFILE_BTN:    '#delete-export-profile-btn',
        DUPLICATE_PROFILE_BTN: '#duplicate-export-profile-btn',
    };

    // Ordered wizard step panel IDs — fixed 2 steps (Name, Data Type), no
    // conditional steps the way the Import wizard has (its 3rd/4th steps
    // depend on scope/mode choices; export has no scope/mode step in this
    // modal at all — scope lives in the tab's own Step 1).
    const WIZARD_STEPS = [ 'mmi-export-wizard-p1', 'mmi-export-wizard-p2' ];

    const SCOPE_SAVE_DEBOUNCE_MS = 400;
    const POLL_INTERVAL_MS = 1500;
    const TOGGLE_ANIM_MS = 200;

    // This server runs a limited PHP-FPM worker pool under heavy background
    // polling load (live admin-bar metrics, heartbeat nonce refresh, etc.),
    // which occasionally starves a single request into a bare 500 with no
    // application-level error behind it. A transport-level failure (as
    // opposed to a well-formed {success:false} response) is retried a few
    // times with backoff before we tell the user it actually failed.
    const TRANSPORT_RETRY_LIMIT = 3;
    const TRANSPORT_RETRY_DELAY_MS = 2500;

    const SPINNER_HTML  = '<span class="mmi-loading"></span>';
    const LABEL_RUNNING = SPINNER_HTML + ' Exporting…';
    const LABEL_IDLE    = '<span class="dashicons dashicons-controls-play"></span> Run Export Now';

    let pollTimer = null;
    let wizardCurrentPanel = WIZARD_STEPS[0];
    let batchTransportRetries = 0;
    let runTransportRetries = 0;

    function getSettings() {
        return window.mmiExportSettings || {};
    }

    function ajaxPost(action, data) {
        const settings = getSettings();
        return $.post(settings.ajaxUrl, Object.assign({ action: action, nonce: settings.nonce }, data));
    }

    function navigateToProfile(profileId) {
        const url = new URL(window.location.href);
        if (profileId) {
            url.searchParams.set('profile', profileId);
        } else {
            url.searchParams.delete('profile');
        }
        window.location.href = url.toString();
    }

    /* ── Data type switching (Step 1 preview-only selector) ───────────── */

    function initDataTypeSwitch() {
        $(document).on('change', SELECTORS.DATA_TYPE_SELECT, function () {
            const dataType = $(this).val();
            ajaxPost('mmi_pipeline_get_scope_fields_for_type', { data_type: dataType }).done(function (resp) {
                if (resp && resp.success) {
                    $(SELECTORS.SCOPE_FIELDS_WRAP).html(resp.data.html);
                    $(document).trigger('mmi:pipeline-export-preview-stale');
                }
            });
        });
    }

    /* ── Scope filter autosave ────────────────────────────────────────── */

    function collectScopeValues() {
        const scope = {};
        $(SELECTORS.SCOPE_INNER).find(SELECTORS.SCOPE_FIELD).each(function () {
            const $field = $(this);
            const key = $field.data('scope-key');
            if (!key) {
                return;
            }
            if ($field.data('scope-tree')) {
                // Hierarchical taxonomy checkbox tree (see
                // MMI_Taxonomy_Tree_Renderer / class-taxonomy-tree-renderer.php)
                // — the interactive elements are nested checkboxes, not the
                // .mmi-scope-field element itself, so .val() doesn't apply.
                scope[key] = $field.find('.mmi-taxonomy-tree-checkbox:checked').map(function () {
                    return this.value;
                }).get();
            } else if ($field.data('scope-meta-conditions')) {
                // Custom Field Filters condition-row builder (see
                // export-scope-meta-conditions.php) — one object per row,
                // rows with an empty key are dropped (an empty row is just
                // the default "add your first filter" placeholder state).
                scope[key] = $field.find('.mmi-meta-condition-row').map(function () {
                    const $row = $(this);
                    const condKey = $row.find('.mmi-meta-cond-key').val().trim();
                    if (!condKey) {
                        return null;
                    }
                    return {
                        key: condKey,
                        operator: $row.find('.mmi-meta-cond-operator').val(),
                        value: $row.find('.mmi-meta-cond-value').val(),
                    };
                }).get().filter(Boolean);
            } else if ($field.data('scope-checkbox')) {
                scope[key] = $field.is(':checked');
            } else if ($field.data('scope-multi')) {
                scope[key] = $field.val() || [];
            } else {
                scope[key] = $field.val() || '';
            }
        });
        return scope;
    }

    function saveScope() {
        const $typeSelect = $(SELECTORS.DATA_TYPE_SELECT);
        // The Data Type <select> can be switched to preview a different type
        // "without switching your saved profile" (see tab-export.php) — its
        // scope-fields panel re-renders for whatever type is selected, but
        // the profile's own real, saved data type never changes with it.
        // Persisting values collected while previewing a mismatched type
        // would write that other type's filter shape onto this profile's
        // product_identifier column (save_export_scope() always saves under
        // the PROFILE's own data_type) — silently corrupting its real scope
        // with fields it doesn't understand. Only autosave when the two
        // agree; a mismatched selection is preview-only and never persisted.
        if ($typeSelect.length && $typeSelect.val() !== $typeSelect.data('current-value')) {
            $(document).trigger('mmi:pipeline-export-preview-stale');
            return;
        }

        const settings = getSettings();
        ajaxPost('mmi_pipeline_save_export_scope', {
            profile: settings.currentProfile,
            scope: JSON.stringify(collectScopeValues()),
        }).done(function () {
            $(document).trigger('mmi:pipeline-export-preview-stale');
        });
    }

    function initScopeAutosave() {
        let debounceTimer = null;
        $(document).on('change', SELECTORS.SCOPE_FIELD, function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(saveScope, SCOPE_SAVE_DEBOUNCE_MS);
        });
    }

    /* ── Hierarchical taxonomy checkbox tree (Category, etc.) ─────────────
     * Checking/unchecking a parent term cascades to every descendant
     * checkbox in the same <li> subtree — matches the proven pattern in
     * mmi-reverb-integration's admin-bulk-updates.js (bindParentCheckboxes),
     * reimplemented here rather than shared, since that plugin's JS isn't a
     * dependency of this one. The cascade handler deliberately does NOT stop
     * propagation — the change event still bubbles to the delegated
     * .mmi-scope-field listener in initScopeAutosave() above, so no separate
     * save call is needed here. Select All / Clear All set every checkbox's
     * .prop() directly (no per-checkbox 'change' events, avoiding redundant
     * cascades on large trees) then fire one synthetic 'change' on the tree
     * container itself to trigger a single autosave. */
    function initTaxonomyTreeControls() {
        $(document).on('change', '.mmi-taxonomy-tree-checkbox', function () {
            const $checkbox = $(this);
            const isChecked = $checkbox.is(':checked');
            $checkbox.closest('li').find('.mmi-taxonomy-tree-checkbox').prop('checked', isChecked);
        });

        $(document).on('click', '.mmi-taxonomy-tree-select-all', function () {
            const $field = $(this).closest('.mmi-modal-field');
            $field.find('.mmi-taxonomy-tree-checkbox').prop('checked', true);
            $field.find('.mmi-taxonomy-tree').trigger('change');
        });

        $(document).on('click', '.mmi-taxonomy-tree-clear-all', function () {
            const $field = $(this).closest('.mmi-modal-field');
            $field.find('.mmi-taxonomy-tree-checkbox').prop('checked', false);
            $field.find('.mmi-taxonomy-tree').trigger('change');
        });
    }

    /* ── Custom Field Filters (post meta conditions) ──────────────────────
     * Repeatable key/operator/value row builder — see
     * export-scope-meta-conditions.php for the markup this drives. A native
     * 'change' on any row's key/operator/value input already bubbles up
     * through .mmi-meta-conditions.mmi-scope-field to the delegated
     * autosave listener in initScopeAutosave(), so only Add/Remove (which
     * mutate the DOM without firing a real 'change' event) need an explicit
     * trigger here. */
    function initMetaConditionRows() {
        $(document).on('click', '.mmi-meta-cond-add', function () {
            const $container = $(this).closest('.mmi-modal-field').find('.mmi-meta-conditions');
            const $lastRow = $container.find('.mmi-meta-condition-row').last();
            $lastRow.clone().find('.mmi-meta-cond-key, .mmi-meta-cond-value').val('').end()
                .find('.mmi-meta-cond-operator').val('equals').end()
                .appendTo($container);
        });

        $(document).on('click', '.mmi-meta-cond-remove', function () {
            const $container = $(this).closest('.mmi-meta-conditions');
            const $rows = $container.find('.mmi-meta-condition-row');
            if ($rows.length > 1) {
                $(this).closest('.mmi-meta-condition-row').remove();
            } else {
                // Always leave exactly one (empty) row rather than none —
                // matches the "Add Filter" button always having somewhere
                // to clone from.
                $rows.find('.mmi-meta-cond-key, .mmi-meta-cond-value').val('');
                $rows.find('.mmi-meta-cond-operator').val('equals');
            }
            $container.trigger('change');
        });
    }

    /* ── Schedule: toggle panel + autosave ────────────────────────────── */

    function initScheduleToggle() {
        $(document).on('click', SELECTORS.SCHEDULE_TOGGLE, function () {
            const $panel  = $(SELECTORS.SCHEDULE_PANEL);
            const $toggle = $(this);
            $panel.slideToggle(TOGGLE_ANIM_MS, function () {
                $toggle.toggleClass('is-active', $panel.is(':visible'));
            });
        });
    }

    // Deep-link support for the header's "Next scheduled run" link (main.php) —
    // ?open_schedule=1 opens the Schedules panel and scrolls it into view so a
    // user arriving from another tab lands directly on it.
    function maybeOpenScheduleFromUrl() {
        if (new URLSearchParams(window.location.search).get('open_schedule') !== '1') {
            return;
        }
        const $panel = $(SELECTORS.SCHEDULE_PANEL);
        $panel.slideDown(TOGGLE_ANIM_MS, function () {
            $(SELECTORS.SCHEDULE_TOGGLE).addClass('is-active');
            $panel[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }

    function initScheduleAutosave() {
        $(document).on('change', SELECTORS.SCHEDULE_FREQUENCY + ', ' + SELECTORS.SCHEDULE_FORMAT, function () {
            const profile = $(SELECTORS.SCHEDULE_FREQUENCY).data('profile');
            ajaxPost('mmi_pipeline_save_export_schedule', {
                profile: profile,
                frequency: $(SELECTORS.SCHEDULE_FREQUENCY).val(),
                format: $(SELECTORS.SCHEDULE_FORMAT).val(),
            });
        });
    }

    /* ── Profile card switching ───────────────────────────────────────── */

    function initProfileCardSwitch() {
        $(document).on('click keydown', SELECTORS.PROFILE_CARD, function (e) {
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
                return;
            }
            e.preventDefault();
            const profileId = $(this).data('profile-id');
            $(SELECTORS.HIDDEN_SELECT).val(profileId);
            navigateToProfile(profileId);
        });
    }

    /* ── New export profile wizard ────────────────────────────────────── */

    function wizardShowPanel(panelId) {
        wizardCurrentPanel = panelId;
        const idx = WIZARD_STEPS.indexOf(panelId);

        // Panel 2 starts with the "mmi-hidden" class in the static markup
        // (mmi-suite-common.css: "display:none !important"), which a plain
        // jQuery .show() cannot override — !important in a stylesheet always
        // beats a non-important inline style. Must explicitly remove it here
        // or the panel container becomes visible but its content stays
        // display:none (a blank step) — same bug found and fixed in the
        // Import wizard's identical wizardShowPanel(), see import-settings.js.
        $('.mmi-wizard-panel').hide().addClass('mmi-hidden');
        $('#' + panelId).show().removeClass('mmi-hidden');

        $(SELECTORS.NEW_PROFILE_BREADCRUMB).find('.mmi-wizard-crumb').each(function () {
            const crumbStepIdx = parseInt($(this).data('crumb'), 10) - 1;
            $(this)
                .toggleClass('mmi-is-active', crumbStepIdx === idx)
                .toggleClass('mmi-is-done', crumbStepIdx < idx);
        });

        // #mmi-export-wizard-back and #new-export-profile-modal-create both
        // start with the "mmi-hidden" class in the static markup — same
        // !important-vs-inline-style conflict as the panels above, so a
        // plain .toggle(true) can never reveal them. NEW_PROFILE_NEXT is
        // given the same class-based hiding here too (previously relied on
        // the inline style alone, its one footer sibling with no !important
        // backup) — see the identical hardening + full reasoning on the
        // Import wizard's own wizardSyncFooterButtons() in import-settings.js.
        const isFirst = idx === 0;
        const isLast  = idx === WIZARD_STEPS.length - 1;
        $(SELECTORS.NEW_PROFILE_BACK).toggleClass('mmi-hidden', isFirst).toggle(!isFirst);
        $(SELECTORS.NEW_PROFILE_NEXT).toggleClass('mmi-hidden', isLast).toggle(!isLast);
        $(SELECTORS.NEW_PROFILE_CREATE).toggleClass('mmi-hidden', !isLast).toggle(isLast);

        if (panelId === WIZARD_STEPS[0]) {
            setTimeout(function () { $(SELECTORS.NEW_PROFILE_NAME).focus(); }, 150);
        }
    }

    function wizardValidateCurrentPanel() {
        if (wizardCurrentPanel === WIZARD_STEPS[0]) {
            const name = $(SELECTORS.NEW_PROFILE_NAME).val().trim();
            if (!name) {
                $(SELECTORS.NEW_PROFILE_NAME).focus().addClass('mmi-field-error');
                return false;
            }
            $(SELECTORS.NEW_PROFILE_NAME).removeClass('mmi-field-error');
        }
        return true;
    }

    function openNewProfileWizard() {
        $(SELECTORS.NEW_PROFILE_NAME).val('').removeClass('mmi-field-error');
        $(SELECTORS.DATA_TYPE_CARD).removeClass('mmi-is-selected');
        $(SELECTORS.DATA_TYPE_CARD).filter('[data-data-type="product"]').addClass('mmi-is-selected');
        $('input[name="new_export_data_type"][value="product"]').prop('checked', true);
        wizardShowPanel(WIZARD_STEPS[0]);
        MMIModal.open('new-export-profile-modal');
    }

    function initNewProfileModal() {
        $(document).on('click', SELECTORS.NEW_PROFILE_BTN, function () {
            openNewProfileWizard();
        });
        // Backdrop-click/Esc/[data-close] are now owned by MMIModal.init()
        // (called above) — NEW_PROFILE_CLOSE's own buttons already carry
        // data-close (see modal-new-export-profile.php).

        $(document).on('click', SELECTORS.NEW_PROFILE_NEXT, function () {
            if (!wizardValidateCurrentPanel()) { return; }
            const idx = WIZARD_STEPS.indexOf(wizardCurrentPanel);
            if (idx < WIZARD_STEPS.length - 1) { wizardShowPanel(WIZARD_STEPS[idx + 1]); }
        });
        $(document).on('click', SELECTORS.NEW_PROFILE_BACK, function () {
            const idx = WIZARD_STEPS.indexOf(wizardCurrentPanel);
            if (idx > 0) { wizardShowPanel(WIZARD_STEPS[idx - 1]); }
        });

        // Data-type card visual selection
        $(document).on('change', 'input[name="new_export_data_type"]', function () {
            $(SELECTORS.DATA_TYPE_CARD).removeClass('mmi-is-selected');
            $(this).closest(SELECTORS.DATA_TYPE_CARD).addClass('mmi-is-selected');
        });
        $(document).on('click', SELECTORS.DATA_TYPE_CARD, function () {
            $(this).find('input[type="radio"]').prop('checked', true).trigger('change');
        });

        $(document).on('click', SELECTORS.NEW_PROFILE_CREATE, function () {
            const name = $(SELECTORS.NEW_PROFILE_NAME).val().trim();
            const dataType = $('input[name="new_export_data_type"]:checked').val() || 'product';
            if (!name) {
                return;
            }
            ajaxPost('mmi_pipeline_create_export_profile', { name: name, data_type: dataType }).done(function (resp) {
                if (resp && resp.success) {
                    navigateToProfile(resp.data.profile_id);
                }
            });
        });
    }

    /* ── Edit export profile modal ────────────────────────────────────── */

    function initEditProfileModal() {
        $(document).on('click', SELECTORS.EDIT_BTN, function () {
            const settings = getSettings();
            $(SELECTORS.EDIT_NAME).val($('#mmi-export-topbar-active-name').text().trim());
            $(SELECTORS.EDIT_TYPE).val($('#mmi-export-profile-datatype-badge').data('data-type') || 'product');
            $(SELECTORS.EDIT_MODAL).data('profile', settings.currentProfile);
            MMIModal.open('edit-export-profile-modal');
        });
        // Backdrop-click/Esc/[data-close] are now owned by MMIModal.init()
        // (called above) — EDIT_CLOSE's own buttons already carry data-close
        // (see modal-edit-export-profile.php).
        $(document).on('click', SELECTORS.EDIT_SAVE, function () {
            const profile = $(SELECTORS.EDIT_MODAL).data('profile');
            const name = $(SELECTORS.EDIT_NAME).val().trim();
            if (!name) {
                $(SELECTORS.EDIT_NAME).focus().addClass('mmi-field-error');
                return;
            }
            ajaxPost('mmi_pipeline_update_export_profile', {
                profile: profile,
                name: name,
                data_type: $(SELECTORS.EDIT_TYPE).val(),
            }).done(function (resp) {
                if (resp && resp.success) {
                    window.location.reload();
                }
            });
        });
    }

    /* ── Duplicate profile ────────────────────────────────────────────── */

    function initDuplicateProfile() {
        $(document).on('click', SELECTORS.DUPLICATE_PROFILE_BTN, function () {
            const settings = getSettings();
            ajaxPost('mmi_pipeline_duplicate_export_profile', { profile: settings.currentProfile }).done(function (resp) {
                if (resp && resp.success) {
                    navigateToProfile(resp.data.profile_id);
                }
            });
        });
    }

    /* ── Delete profile ──────────────────────────────────────────────── */

    function initDeleteProfile() {
        $(document).on('click', SELECTORS.DELETE_PROFILE_BTN, function () {
            const settings = getSettings();
            if (!window.confirm('Delete this export profile? This cannot be undone.')) {
                return;
            }
            ajaxPost('mmi_pipeline_delete_export_profile', { profile: settings.currentProfile }).done(function (resp) {
                if (resp && resp.success) {
                    navigateToProfile(null);
                }
            });
        });
    }

    /* ── Run export + poll ───────────────────────────────────────────── */

    function setRunningState($btn, isRunning) {
        $btn.prop('disabled', isRunning)
            .toggleClass('mmi-is-loading', isRunning)
            .html(isRunning ? LABEL_RUNNING : LABEL_IDLE);
    }

    function escHtml(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    function renderStatus(progress) {
        const $panel = $(SELECTORS.STATUS_PANEL);
        if (!progress || progress.status === 'idle') {
            $panel.empty();
            return;
        }
        if (progress.status === 'running') {
            const total = progress.total || 0;
            const exported = progress.exported || 0;
            const pct = total > 0 ? Math.min(100, Math.round((exported / total) * 100)) : 0;
            $panel.html(
                '<div class="mmi-export-progress" style="--export-pct:' + pct + '%">' +
                '<div class="mmi-export-progress-bar"></div>' +
                '<span>' + exported + ' / ' + total + ' exported…</span>' +
                '</div>'
            );
        } else if (progress.status === 'complete') {
            $panel.html(
                '<div class="mmi-export-complete">' +
                '<span class="dashicons dashicons-yes-alt"></span> Export complete — ' + (progress.exported || 0) + ' record(s).' +
                (progress.download_url ? ' <a class="button button-primary mmi-action-btn" href="' + escHtml(progress.download_url) + '">Download</a>' : '') +
                '</div>'
            );
        } else if (progress.status === 'aborted') {
            $panel.html('<div class="mmi-export-aborted">Export aborted.</div>');
        }
    }

    function pollBatch() {
        ajaxPost('mmi_pipeline_process_export_batch', {}).done(function (resp) {
            if (!resp || !resp.success) {
                stopPolling();
                setRunningState($(SELECTORS.RUN_EXPORT_BTN), false);
                $(SELECTORS.STATUS_PANEL).html('<div class="mmi-export-error">' + (resp && resp.data && resp.data.message ? resp.data.message : 'Export failed while processing.') + '</div>');
                return;
            }
            batchTransportRetries = 0;
            renderStatus(resp.data);
            if (resp.data.status === 'running') {
                pollTimer = setTimeout(pollBatch, POLL_INTERVAL_MS);
            } else {
                stopPolling();
                setRunningState($(SELECTORS.RUN_EXPORT_BTN), false);
            }
        }).fail(function (jqXHR) {
            // A 403 here means check_ajax_referer() rejected our nonce — almost
            // always because this tab has been open long enough for the nonce
            // to expire (nonces are only valid ~12-24h), not a transient server
            // hiccup. Retrying with the same stale nonce will never succeed, so
            // stop immediately and tell the user to reload instead of burning
            // through the transport-retry budget and reporting a misleading
            // "lost connection" error.
            if (jqXHR && jqXHR.status === 403) {
                stopPolling();
                setRunningState($(SELECTORS.RUN_EXPORT_BTN), false);
                $(SELECTORS.STATUS_PANEL).html('<div class="mmi-export-error">Your session has expired. Please reload this page and try again.</div>');
                return;
            }
            // A transport-level failure (server-returned 500, dropped connection)
            // rather than a well-formed error response — this server occasionally
            // starves a single request under background polling load, so retry a
            // few times before surfacing it as a real failure.
            if (batchTransportRetries < TRANSPORT_RETRY_LIMIT) {
                batchTransportRetries++;
                pollTimer = setTimeout(pollBatch, TRANSPORT_RETRY_DELAY_MS);
                return;
            }
            stopPolling();
            setRunningState($(SELECTORS.RUN_EXPORT_BTN), false);
            $(SELECTORS.STATUS_PANEL).html('<div class="mmi-export-error">Lost connection while processing the export after several retries. Please try again.</div>');
        });
    }

    function stopPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function startExportRun($btn, settings, format) {
        ajaxPost('mmi_pipeline_run_manual_export', { profile: settings.currentProfile, format: format }).done(function (resp) {
            if (!resp || !resp.success) {
                setRunningState($btn, false);
                $(SELECTORS.STATUS_PANEL).html('<div class="mmi-export-error">' + (resp && resp.data && resp.data.message ? resp.data.message : 'Export failed to start.') + '</div>');
                return;
            }
            runTransportRetries = 0;
            batchTransportRetries = 0;
            pollBatch();
        }).fail(function (jqXHR) {
            // See pollBatch()'s fail handler — a 403 means the nonce embedded in
            // this page load has expired, so retrying is pointless until reload.
            if (jqXHR && jqXHR.status === 403) {
                setRunningState($btn, false);
                $(SELECTORS.STATUS_PANEL).html('<div class="mmi-export-error">Your session has expired. Please reload this page and try again.</div>');
                return;
            }
            // Same transient-500 tolerance as pollBatch() — retry before giving up.
            if (runTransportRetries < TRANSPORT_RETRY_LIMIT) {
                runTransportRetries++;
                setTimeout(function () {
                    startExportRun($btn, settings, format);
                }, TRANSPORT_RETRY_DELAY_MS);
                return;
            }
            setRunningState($btn, false);
            $(SELECTORS.STATUS_PANEL).html('<div class="mmi-export-error">Could not reach the server to start the export after several retries. Please try again.</div>');
        });
    }

    function initRunExport() {
        $(document).on('click', SELECTORS.RUN_EXPORT_BTN, function () {
            const settings = getSettings();
            const $btn = $(this);
            const format = $('#mmi-export-format').val();

            runTransportRetries = 0;
            setRunningState($btn, true);
            startExportRun($btn, settings, format);
        });
    }

    $(function () {
        MMIModal.init();
        initDataTypeSwitch();
        initScopeAutosave();
        initTaxonomyTreeControls();
        initMetaConditionRows();
        initScheduleToggle();
        initScheduleAutosave();
        initProfileCardSwitch();
        initNewProfileModal();
        initEditProfileModal();
        initDuplicateProfile();
        initDeleteProfile();
        initRunExport();
        maybeOpenScheduleFromUrl();
    });

})(jQuery);
