/**
 * Import Pipeline — CSV & Autosave
 * Extends window.MMIDataPipeline with methods for this concern.
 */
(function($) {
    'use strict';

    const ajaxUrl = (window.mmiImportSettings && window.mmiImportSettings.ajaxurl)
                    || (window.mmiGlobal && window.mmiGlobal.ajaxUrl)
                    || window.ajaxurl
                    || '/wp-admin/admin-ajax.php';
    const nonce = (window.mmiImportSettings && window.mmiImportSettings.nonce) || '';

    Object.assign(window.MMIDataPipeline, {
        autosaveImportRule: function(setting, value) {
            const self = this;
            $.ajax({
                url: ajaxUrl, method: 'POST',
                data: { action: 'mmi_autosave_import_rule', setting: setting, value: value, nonce: nonce },
                success: function(r) { self.showSaveIndicator(r.success ? 'success' : 'error'); }
            });
        },

        autosaveSchedule: function(process, frequency, $select, timeOfDay, $timeSelect) {
            const self = this;
            const importNonce = (window.mmiImportSettings && window.mmiImportSettings.importNonce) || '';

            const revert = function() {
                if ($select && $select.length) {
                    const prev = $select.data('mmi-prev-value');
                    if (prev !== undefined) {
                        $select.val(prev);
                        self.updateScheduleRowDisabledState($select);
                    }
                }
                if ($timeSelect && $timeSelect.length) {
                    const prevTime = $timeSelect.data('mmi-prev-value');
                    if (prevTime !== undefined) {
                        $timeSelect.val(prevTime);
                    }
                }
            };

            const doSave = function() {
                $.ajax({
                    url: ajaxUrl, method: 'POST',
                    data: { action: 'mmi_autosave_schedule', process: process, frequency: frequency, time: timeOfDay || '', nonce: nonce },
                    success: function(r) {
                        if ( ! r.success ) {
                            // A rejected save (e.g. MMI_Pipeline_Cron::find_stagger_conflict()'s
                            // 5-minute stagger check) must not leave the control showing the
                            // value that was actually rejected — same revert() the "declined
                            // config-issue confirmation" path below already uses.
                            self.showSaveIndicator('error', (r.data && r.data.message) ? ('✗ ' + r.data.message) : '✗ Schedule save failed');
                            revert();
                            return;
                        }
                        self.showSaveIndicator('success', '✓ Schedule saved');
                        if ( r.data && r.data.next_scheduled ) {
                            const p     = r.data.process;
                            const label = r.data.next_scheduled;
                            let $cell;
                            // Each data source now has its own independent fetch
                            // schedule/hook (process = 'source_fetch_{supplier_id}',
                            // see MMI_Pipeline_Cron::get_schedulable_sources()) —
                            // same per-row update as profiles below, no shared
                            // topbar badge to keep live-synced anymore since there's
                            // no longer one single "Data Fetch" schedule.
                            if ( p.indexOf('source_fetch_') === 0 ) {
                                const sid = p.replace('source_fetch_', '');
                                $cell = $(`#sched-source-${sid}-next`);
                            } else if ( p.indexOf('profile_') === 0 ) {
                                const pid = p.replace('profile_', '');
                                $cell = $(`#sched-profile-${pid}-next`);
                            } else if ( p === 'catalog_update' ) {
                                $cell = $('#sched-catalog-update-next');
                            }
                            if ( $cell && $cell.length ) {
                                $cell.text(label);
                            }
                        }
                    }
                });
            };

            // Enabling a PROFILE schedule (frequency !== 'disabled') runs an
            // actual import unattended — pre-flight check its config first so
            // the user isn't surprised by empty/broken products from a
            // scheduled run. A source's own Data Fetch schedule (process
            // "source_fetch_{supplier}") only re-downloads that supplier's
            // raw feed JSON — it never touches WooCommerce products, so this
            // profile-shaped warning doesn't apply and would be validating an
            // unrelated profile's config against an unrelated control.
            const isProfileSchedule = (process || '').indexOf('profile_') === 0;
            if (frequency === 'disabled' || !isProfileSchedule) {
                doSave();
                return;
            }

            const profile = $('#mmi-import-profile').val() || 'default';
            $.ajax({
                url: ajaxUrl, method: 'POST',
                data: { action: 'mmi_pipeline_validate_profile_config', profile: profile, nonce: importNonce },
                success: function(response) {
                    const data     = (response.success && response.data) ? response.data : {};
                    const critical = data.critical || [];
                    const warning  = data.warning  || [];

                    if (critical.length > 0) {
                        const messages = critical.map(function(i) { return '• ' + i.message; }).join('\n');
                        if (!window.confirm(
                            'Configuration issues were found for this profile:\n\n' + messages
                            + '\n\nScheduled imports will likely create incomplete or empty products.'
                            + ' Enable the schedule anyway?'
                        )) {
                            self.showSaveIndicator('error', '✗ Schedule not changed');
                            revert();
                            return;
                        }
                    } else if (warning.length > 0) {
                        const messages = warning.map(function(i) { return '• ' + i.message; }).join('\n');
                        if (!window.confirm('Configuration warnings were found for this profile:\n\n' + messages + '\n\nEnable the schedule anyway?')) {
                            self.showSaveIndicator('error', '✗ Schedule not changed');
                            revert();
                            return;
                        }
                    }

                    doSave();
                },
                error: function() {
                    // Validation endpoint failure shouldn't block scheduling.
                    doSave();
                },
            });
        },

        /**
         * Per-profile "Skip if no new data" schedule gate — see
         * MMI_Pipeline_Cron::run_scheduled_profile_import(). Uses the same
         * mmi_pipeline_import_settings-nonced endpoint family as
         * autosaveSchedule() above, not a dedicated nonce/data object.
         */
        toggleScheduleSkipIfNoData: function(profileId, enabled) {
            const self = this;
            $.ajax({
                url: ajaxUrl, method: 'POST',
                data: {
                    action: 'mmi_pipeline_toggle_skip_if_no_data',
                    profile_id: profileId,
                    enabled: enabled ? 1 : 0,
                    nonce: nonce,
                },
                success: function(r) {
                    self.showSaveIndicator(r.success ? 'success' : 'error', r.success ? '✓ Saved' : '✗ Save failed');
                },
                error: function() { self.showSaveIndicator('error', '✗ Save failed'); },
            });
        },

        /**
         * Links or unlinks ONE (profile, source) pair — see MMI_Pipeline_Cron's
         * "Fetch → Import Linking" section. profileId + supplierId are both
         * always required now (a profile can link to more than one of its
         * own sources, so every call names the exact pair being toggled).
         * The toggle switch itself already reflects the new on/off state the
         * instant the user flips it (that's what checkbox :checked means
         * before this handler even runs) — this is the follow-through: save
         * it server-side, then patch this row plus the profile-wide Next Run
         * cell/locked controls/staleness note to match via
         * applyProfileLinkState() (import-pipeline-ui.js), no page reload.
         * On failure the switch is flipped back to its pre-click state.
         *
         * @param {string}  profileId
         * @param {string}  supplierId
         * @param {boolean} link
         * @param {jQuery}  $toggle    The checkbox that was just changed.
         */
        toggleProfileLink: function(profileId, supplierId, link, $toggle) {
            const self = this;
            self.showSaveIndicator('saving', 'Saving…');
            $.ajax({
                url: ajaxUrl, method: 'POST',
                data: {
                    action: 'mmi_pipeline_toggle_profile_link',
                    profile_id: profileId,
                    supplier_id: supplierId || '',
                    link: link ? 1 : 0,
                    nonce: nonce,
                },
                success: function(r) {
                    if (!r.success) {
                        if ($toggle && $toggle.length) { $toggle.prop('checked', !link); }
                        self.showSaveIndicator('error', (r.data && r.data.message) ? ('✗ ' + r.data.message) : '✗ Save failed');
                        return;
                    }
                    self.showSaveIndicator('success', '✓ Schedule saved');
                    self.applyProfileLinkState(profileId, r.data || {});
                },
                error: function() {
                    if ($toggle && $toggle.length) { $toggle.prop('checked', !link); }
                    self.showSaveIndicator('error', '✗ Save failed');
                },
            });
        },

        /**
         * Fetches (or force-recomputes) one profile's pending create/update
         * stats — backs the Import Profiles grid's Pending column. Uses
         * mmiProductImportData's nonce/ajaxurl since the endpoint itself
         * lives in class-import-preview.php, the same file generate_preview()
         * (the computation this wraps) already lives in — see AGENTS.md's
         * "one clear owner per concern" Elegance principle.
         *
         * @param {string}   profileId
         * @param {boolean}  forceRefresh
         * @param {Function} callback     (stats|null, errorMessage|null)
         */
        fetchProfilePendingStats: function(profileId, forceRefresh, callback) {
            const importData  = window.mmiProductImportData || {};
            const previewUrl   = importData.ajaxurl || ajaxUrl;
            const previewNonce = importData.nonce || '';
            $.ajax({
                url: previewUrl, method: 'POST',
                data: {
                    action: 'mmi_pipeline_get_profile_pending_stats',
                    profile_id: profileId,
                    force_refresh: forceRefresh ? 1 : 0,
                    nonce: previewNonce,
                },
                success: function(r) {
                    if (r.success && r.data) { callback(r.data, null); }
                    else { callback(null, (r.data && r.data.message) || 'Request failed'); }
                },
                error: function() { callback(null, 'Request failed'); },
            });
        },

        // ─── CSV Mapping Methods (unchanged) ────────────────────────────

        autosaveCSVMapping: function(supplier) {
            const self = this;
            const mappings = {};
            $(`.mmi-csv-column-input[data-supplier="${supplier}"]`).each(function() {
                const field   = $(this).data('field');
                const column  = $(this).val();
                const transform = $(`.mmi-csv-transform-select[data-supplier="${supplier}"][data-field="${field}"]`).val();
                if (column) mappings[field] = { column: column, transform: transform };
            });
            $.ajax({
                url: ajaxUrl, method: 'POST',
                data: { action: 'mmi_save_csv_mapping', supplier: supplier, mappings: JSON.stringify(mappings), nonce: nonce },
                success: function(r) { self.showSaveIndicator(r.success ? 'success' : 'error'); }
            });
        },

        saveCSVConfig: function(supplier) {
            const delimiter = $(`.mmi-csv-delimiter-select[data-supplier="${supplier}"]`).val();
            const hasHeader = $(`.mmi-csv-has-header-checkbox[data-supplier="${supplier}"]`).is(':checked');
            $.ajax({
                url: ajaxUrl, method: 'POST',
                data: { action: 'mmi_save_csv_config', supplier: supplier, delimiter: delimiter, has_header: hasHeader ? '1' : '0', nonce: nonce },
                success: function(r) { if (r.success) MMIDataPipeline.showSaveIndicator('success'); }
            });
        },

        loadCSVPreview: function(supplier) {
            const self = this;
            const $btn = $(`.mmi-btn-load-csv-preview[data-supplier="${supplier}"]`);
            const $preview = $(`#csv-preview-${supplier}`);
            $btn.prop('disabled', true).text('Loading...');
            $.ajax({
                url: ajaxUrl, method: 'POST',
                data: { action: 'mmi_load_csv_preview', supplier: supplier, nonce: nonce },
                success: function(r) {
                    if (r.success) { $preview.html(r.data.html).slideDown(); self.showNotification('CSV preview loaded', 'success'); }
                    else { self.showNotification(r.data?.message || 'Failed to load CSV preview', 'error'); }
                },
                error: function() { self.showNotification('Server error', 'error'); },
                complete: function() { $btn.prop('disabled', false).html('<span class="dashicons dashicons-visibility"></span> Load CSV Preview'); }
            });
        },

        testCSVMapping: function(supplier) {
            const self = this;
            self.showNotification('Testing CSV mapping...', 'info');
            $.ajax({
                url: ajaxUrl, method: 'POST',
                data: { action: 'mmi_test_csv_mapping', supplier: supplier, nonce: nonce },
                success: function(r) {
                    if (r.success) {
                        const d = r.data;
                        self.showNotification(`Test successful! Sample: ${d.sample.name} / SKU: ${d.sample.sku}`, 'success');
                        Object.keys(d.sample).forEach(function(field) {
                            $(`.mmi-csv-preview-value[data-field="${field}"]`).text(d.sample[field] || '—');
                        });
                    } else { self.showNotification(r.data?.message || 'Mapping test failed', 'error'); }
                },
                error: function() { self.showNotification('Server error', 'error'); }
            });
        },

        autoDetectCSVColumns: function(supplier) {
            const self = this;
            self.showNotification('Auto-detecting columns...', 'info');
            $.ajax({
                url: ajaxUrl, method: 'POST',
                data: { action: 'mmi_auto_detect_csv_columns', supplier: supplier, nonce: nonce },
                success: function(r) {
                    if (r.success) {
                        const m = r.data.mappings;
                        Object.keys(m).forEach(function(field) {
                            $(`.mmi-csv-column-input[data-supplier="${supplier}"][data-field="${field}"]`).val(m[field]);
                        });
                        self.showNotification(`Auto-detected ${Object.keys(m).length} column mappings`, 'success');
                        self.autosaveCSVMapping(supplier);
                    } else { self.showNotification(r.data?.message || 'Auto-detection failed', 'error'); }
                },
                error: function() { self.showNotification('Server error', 'error'); }
            });
        }

        // ─── UI Helpers ─────────────────────────────────────────────────
    });

})(jQuery);
