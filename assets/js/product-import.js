/**
 * Product Import Tab JavaScript
 * Handles Data Fetch, Product Import, and Maintenance process execution
 */

(function($) {
    'use strict';

    /* ── Button label constants ────────────────────────────────────────────── */
    const SPINNER_HTML     = '<span class="mmi-loading"></span>';
    const LABEL_FETCHING   = `${SPINNER_HTML} Fetching...`;
    const LABEL_IMPORTING  = `${SPINNER_HTML} Importing...`;
    const LABEL_RUNNING    = `${SPINNER_HTML} Running...`;
    const LABEL_FETCH_IDLE   = '<span class="dashicons dashicons-download"></span> Fetch Selected';
    const LABEL_REFRESH_IDLE = '<span class="dashicons dashicons-update"></span> Refresh Selected';
    const LABEL_REFRESHING   = `${SPINNER_HTML} Refreshing...`;
    const LABEL_IMPORT_IDLE  = '<span class="dashicons dashicons-products"></span> Import Selected';
    const LABEL_CATALOG_IDLE = '<span class="dashicons dashicons-admin-tools"></span> Update Store Catalog';
    const LABEL_RUN_NOW_IDLE = '<span class="dashicons dashicons-controls-play"></span> Run Now';

    // Suppliers with dedicated CLI updater classes (SupplierFetchRunner) — these
    // are the only ones the legacy 'mmi_run_process' AJAX handler recognizes.
    // Every other data source (url/upload/dropbox/gdrive, or any newly-added API
    // source) is 'dynamic' and must be routed through 'mmi_run_supplier_fetch'
    // (Data_Source_Manager), which is the only handler that understands those
    // source types. See RunSupplierFetchController.php's own $legacy_suppliers list.
    const LEGACY_FETCH_SUPPLIERS = ['xchange', 'skuport'];

    function escHtml(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    const MMIProductImport = {
        init: function() {
            console.log('MMI Product Import: Initializing');
            this.bindEvents();
            this.maybeOpenScheduleFromUrl();
            this.bindCatalogRunAbortOnUnload();
        },

        // If the browser navigates away mid-run, tell the server so it can
        // release the shared catalog-update lock immediately rather than
        // waiting out its own TTL (Catalog_Run_State::LOCK_TTL) — sendBeacon
        // is the only request type guaranteed to actually fire during unload.
        bindCatalogRunAbortOnUnload: function() {
            const self = this;
            window.addEventListener('beforeunload', function() {
                if (!self._catalogRunId || !navigator.sendBeacon) return;
                const nonce = (window.mmiImportSettings && window.mmiImportSettings.nonce)
                           || (window.mmiProductImportData && window.mmiProductImportData.nonce)
                           || '';
                const ajaxUrl = (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl;
                const data = new FormData();
                data.append('action', 'mmi_catalog_finish_run');
                data.append('run_id', self._catalogRunId);
                data.append('aborted', '1');
                data.append('nonce', nonce);
                navigator.sendBeacon(ajaxUrl, data);
            });
        },

        // Deep-link support for the header's "Next scheduled run" link (main.php) —
        // ?open_schedule=1 opens the Schedules panel and scrolls it into view so a
        // user arriving from another tab lands directly on it instead of having to
        // find the button in this tab's topbar themselves.
        maybeOpenScheduleFromUrl: function() {
            if (new URLSearchParams(window.location.search).get('open_schedule') !== '1') {
                return;
            }
            const $panel = $('#mmi-topbar-schedule-panel');
            $panel.slideDown(200, function() {
                $('#mmi-schedule-toggle').addClass('is-active');
                $panel[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        },

        bindEvents: function() {
            // Fetch Data toggle + its picker panel (2026-08-31) — replaces the
            // old select-all-suppliers table column + per-row .supplier-checkbox
            // inputs, which were a second, un-unified selection concept
            // competing with the table's own click-to-select row model (see
            // .mmi-supplier-row in import-pipeline.js). Batch source selection
            // for fetching now lives entirely inside this panel instead.
            this.bindFetchSourcesToggle();

            // Handle "Select All" checkbox (now inside the Fetch Data panel,
            // not a table column header)
            $(document).on('change', '#mmi-fetch-select-all', this.handleSelectAll.bind(this));

            // Handle individual source checkboxes (now inside the Fetch Data
            // panel, not per-row in the table)
            $(document).on('change', '.mmi-fetch-source-checkbox', this.handleCheckboxChange.bind(this));

            // NOTE: the manual "Enabled" toggle (.supplier-toggle) was removed —
            // a source is enabled the moment it's validated, no separate step
            // (see AGENTS.md's "Enabled Toggle Eliminated" entry).

            // Handle "Fetch Selected" button
            $(document).on('click', '#run-selected-fetch', this.runSelectedFetch.bind(this));
            
            // Handle "Import Selected" button
            $(document).on('click', '#run-selected-import', this.runSelectedImport.bind(this));
            
            // Handle "View Logs" button
            $(document).on('click', '#view-logs-all', this.viewLogs.bind(this));
            
            // Handle "Run Catalog Update" button
            $(document).on('click', '#run-catalog-update', this.runCatalogUpdatePhased.bind(this));

            // Schedule panel toggle
            $(document).on('click', '#mmi-schedule-toggle', this.toggleSchedulePanel.bind(this));

            // Close Schedule panel via Escape — matches the Stock Overrides
            // and Catalog Rules panels, which already supported this.
            $(document).on('keydown.scheduleTaxmapPanels', function(e) {
                if (e.key !== 'Escape') return;
                const $schedulePanel = $('#mmi-topbar-schedule-panel');
                if ($schedulePanel.is(':visible')) {
                    $schedulePanel.slideUp(200);
                    $('#mmi-schedule-toggle').removeClass('is-active');
                }
            });

            // Auto-save unified schedule on change
            $(document).on('change', '.mmi-schedule-select[data-auto-save="true"]', this.autoSaveSchedule.bind(this));
            
            // Handle individual import schedule changes
            $(document).on('change', '.mmi-schedule-select-inline', this.saveIndividualSchedule.bind(this));
        },
        
        /**
         * Fetch Data toggle + picker panel (2026-08-31) — same button+panel
         * mechanic already used by Schedules/Stock Overrides/Catalog Rules/
         * Taxonomy Mapping (slideToggle() + an 'is-active' class on the
         * button). Deliberately does not join those others' "only one
         * topbar panel open at a time" group — this lives in the Supplier
         * Data Sources toolbar, a different context.
         */
        bindFetchSourcesToggle: function() {
            $(document).on('click', '#mmi-fetch-sources-toggle', function() {
                const $btn   = $(this);
                const $panel = $('#mmi-fetch-sources-panel');
                $panel.slideToggle(200, function() {
                    $btn.toggleClass('is-active', $panel.is(':visible'));
                });
            });
        },

        handleSelectAll: function(e) {
            const $checkbox = $(e.currentTarget);
            const isChecked = $checkbox.is(':checked');

            $('.mmi-fetch-source-checkbox').prop('checked', isChecked);
            this.updateActionButtons();
        },

        handleCheckboxChange: function(e) {
            const totalCheckboxes = $('.mmi-fetch-source-checkbox').length;
            const checkedCheckboxes = $('.mmi-fetch-source-checkbox:checked').length;
            $('#mmi-fetch-select-all').prop('checked', totalCheckboxes === checkedCheckboxes);

            this.updateActionButtons();
        },

        updateActionButtons: function() {
            const checkedCount = $('.mmi-fetch-source-checkbox:checked').length;
            $('#run-selected-fetch').prop('disabled', checkedCount === 0);
            $('#run-selected-import').prop('disabled', checkedCount === 0);
            this.updateFetchButtonLabel();
        },

        // 'upload' sources have no live remote endpoint to poll — "fetching" them
        // just re-parses the same file already on disk (see
        // Data_Source_Manager::fetch_from_upload()); no new data is retrieved.
        // Relabel the button to "Refresh" when every checked row is an upload
        // source so the action reads accurately. Any other/mixed selection keeps
        // the "Fetch" wording since those source types do pull fresh data over
        // the network (API/URL/Dropbox/Google Drive).
        updateFetchButtonLabel: function() {
            const $button = $('#run-selected-fetch');
            if ($button.prop('disabled')) {
                $button.html(LABEL_FETCH_IDLE);
                return;
            }

            $button.html(this.isUploadOnlySelection() ? LABEL_REFRESH_IDLE : LABEL_FETCH_IDLE);
        },

        isUploadOnlySelection: function() {
            let allUpload = true;
            $('.mmi-fetch-source-checkbox:checked').each(function() {
                if ($(this).data('source-type') !== 'upload') {
                    allUpload = false;
                }
            });
            return allUpload;
        },
        
        runSelectedFetch: function(e) {
            const $button = $(e.currentTarget);
            const selectedSuppliers = this.getSelectedSuppliers();
            const invalidSuppliers  = this.getInvalidSelectedSuppliers();

            if (invalidSuppliers.length > 0) {
                alert('The following selected sources are not enabled or not fully configured and will be skipped:\n\n' + invalidSuppliers.join('\n') + '\n\nEnable and configure each source before fetching.');
            }

            if (selectedSuppliers.length === 0) {
                alert('No enabled, configured suppliers selected. Please enable and configure a supplier before running a fetch.');
                return;
            }

            const isRefresh = this.isUploadOnlySelection();
            const confirmation = confirm(
                isRefresh
                    ? `Re-process the uploaded file for ${selectedSuppliers.length} source(s)? This re-parses the file already on disk — it will not prompt for a new upload.`
                    : `Fetch data for ${selectedSuppliers.length} enabled supplier(s)?`
            );
            if (!confirmation) return;
            
            $button.css('min-width', $button.outerWidth() + 'px').prop('disabled', true).addClass('mmi-is-loading').html(isRefresh ? LABEL_REFRESHING : LABEL_FETCHING);
            
            const processes = selectedSuppliers.map(s => s + '_fetch');
            this.runProcessesSequentially(processes, 0, 'fetch', $button);
        },
        
        runSelectedImport: function(e) {
            const $button = $(e.currentTarget);
            const selectedSuppliers = this.getSelectedSuppliers();
            
            if (selectedSuppliers.length === 0) {
                alert('Please select at least one supplier.');
                return;
            }
            
            const confirmation = confirm(`Import products for ${selectedSuppliers.length} selected supplier(s)?`);
            if (!confirmation) return;
            
            $button.css('min-width', $button.outerWidth() + 'px').prop('disabled', true).addClass('mmi-is-loading').html(LABEL_IMPORTING);
            
            const processes = selectedSuppliers.map(s => s + '_import');
            this.runProcessesSequentially(processes, 0, 'import', $button);
        },
        
        getSelectedSuppliers: function() {
            const suppliers = [];
            $('.mmi-fetch-source-checkbox:checked').each(function() {
                const cfgStatus = $(this).data('config-status') || 'unconfigured';
                const isEnabled  = $(this).data('enabled') === 1 || $(this).data('enabled') === '1';
                // Only include enabled, non-unconfigured sources
                if (isEnabled && cfgStatus !== 'unconfigured') {
                    suppliers.push($(this).val());
                }
            });
            return suppliers;
        },

        getInvalidSelectedSuppliers: function() {
            const invalid = [];
            $('.mmi-fetch-source-checkbox:checked').each(function() {
                const cfgStatus = $(this).data('config-status') || 'unconfigured';
                const isEnabled  = $(this).data('enabled') === 1 || $(this).data('enabled') === '1';
                if (!isEnabled || cfgStatus === 'unconfigured') {
                    const name = $(this).closest('label').text().trim() || $(this).val();
                    invalid.push(name);
                }
            });
            return invalid;
        },
        
        runProcessesSequentially: function(processes, index, type, $button) {
            if (index >= processes.length) {
                let buttonText = LABEL_IMPORT_IDLE;
                if (type === 'fetch') {
                    buttonText = this.isUploadOnlySelection() ? LABEL_REFRESH_IDLE : LABEL_FETCH_IDLE;
                }
                $button.prop('disabled', false).removeClass('mmi-is-loading').html(buttonText).css('min-width', '');
                this.showFlashMessage(`All ${type} processes completed!`, 'success', $('.mmi-import-settings-wrap'));
                return;
            }
            
            const processKey = processes[index];
            const supplier = processKey.replace('_fetch', '').replace('_import', '');
            const $row = $(`tr[data-supplier="${supplier}"]`);

            // Non-legacy sources (url/upload/dropbox/gdrive, or any newly-added API
            // source) aren't recognized by the legacy 'mmi_run_process' handler —
            // route them through 'mmi_run_supplier_fetch' (Data_Source_Manager) instead.
            if (type === 'fetch' && LEGACY_FETCH_SUPPLIERS.indexOf(supplier) === -1) {
                this.runDynamicSupplierFetch(processes, index, $row, supplier, $button);
                return;
            }
            
            // Highlight the row while the request is in flight
            $row.addClass('mmi-row-pending');
            
            $.ajax({
                url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                method: 'POST',
                data: {
                    action: 'mmi_run_process',
                    process: processKey,
                    nonce: mmiProductImportData?.nonce || ''
                },
                success: (response) => {
                    $row.removeClass('mmi-row-pending').addClass(response.success ? 'mmi-row-success' : 'mmi-row-error');

                    const supplierLabel = supplier.charAt(0).toUpperCase() + supplier.slice(1);
                    const message = response.success
                        ? `\u2713 ${supplierLabel} ${type} completed`
                        : `\u2717 ${supplierLabel} ${type} failed`;
                    this.showNotification(message, response.success ? 'success' : 'error');

                    // Update Last Fetch cell immediately from fetch response data
                    if (response.success && type === 'fetch') {
                        const count = response.data && response.data.fetch_count
                            ? ' \u00b7 ' + Number(response.data.fetch_count).toLocaleString() + ' products'
                            : '';
                        $row.find('.mmi-last-fetch-cell').html(
                            '<span class="mmi-text-success">Just now' + count + '</span>'
                        );

                        // Confirmatory refresh after brief delay — reads the
                        // authoritative, file-mtime-based freshness (see
                        // refreshLastFetchCell()'s own docblock) rather than
                        // trusting the optimistic "Just now" above forever.
                        setTimeout(() => {
                            if (window.MMIDataPipeline && window.MMIDataPipeline.refreshLastFetchCell) {
                                window.MMIDataPipeline.refreshLastFetchCell(supplier);
                            }
                        }, 1500);

                        if (typeof window.MMIImportPreview !== 'undefined' && window.MMIImportPreview.scheduleRefresh) {
                            setTimeout(() => { window.MMIImportPreview.scheduleRefresh(500); }, 1000);
                        }
                    }

                    setTimeout(() => {
                        $row.removeClass('mmi-row-pending mmi-row-success mmi-row-error');
                        this.runProcessesSequentially(processes, index + 1, type, $button);
                    }, 1000);
                },
                error: () => {
                    $row.removeClass('mmi-row-pending').addClass('mmi-row-error');
                    setTimeout(() => {
                        $row.removeClass('mmi-row-pending mmi-row-success mmi-row-error');
                        this.runProcessesSequentially(processes, index + 1, type, $button);
                    }, 1000);
                }
            });
        },

        // Fetch/refresh a single non-legacy (dynamic) data source via
        // Data_Source_Manager::fetch_from_supplier(), which supports the
        // api/url/upload/dropbox/gdrive source types. Mirrors the row
        // highlighting, notifications, and sequencing of the legacy path above
        // so the two routes are visually indistinguishable to the user.
        runDynamicSupplierFetch: function(processes, index, $row, supplier, $button) {
            const isRefresh = $row.attr('data-source-type') === 'upload';

            $row.addClass('mmi-row-pending');

            $.ajax({
                url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                method: 'POST',
                data: {
                    action: 'mmi_run_supplier_fetch',
                    suppliers: [supplier],
                    nonce: (window.mmiImportSettings && window.mmiImportSettings.nonce) || ''
                },
                success: (response) => {
                    const ok = !!(response.success && response.data && response.data.results && response.data.results[supplier] === 'success');
                    $row.removeClass('mmi-row-pending').addClass(ok ? 'mmi-row-success' : 'mmi-row-error');

                    const supplierLabel = $row.find('td strong').first().text() || supplier;
                    const verb = isRefresh ? 'refresh' : 'fetch';
                    const message = ok
                        ? `\u2713 ${supplierLabel} ${verb} completed`
                        : `\u2717 ${supplierLabel} ${verb} failed`;
                    this.showNotification(message, ok ? 'success' : 'error');

                    if (ok) {
                        // 'upload' sources aren't "fetched" (no remote endpoint) — this
                        // just re-parsed the file already on disk, so label it accordingly.
                        const justNowLabel = isRefresh ? 'Uploaded just now' : 'Just now';
                        $row.find('.mmi-last-fetch-cell').html(`<span class="mmi-text-success">${justNowLabel}</span>`);
                        setTimeout(() => {
                            if (window.MMIDataPipeline && window.MMIDataPipeline.refreshLastFetchCell) {
                                window.MMIDataPipeline.refreshLastFetchCell(supplier);
                            }
                        }, 1500);

                        if (typeof window.MMIImportPreview !== 'undefined' && window.MMIImportPreview.scheduleRefresh) {
                            setTimeout(() => { window.MMIImportPreview.scheduleRefresh(500); }, 1000);
                        }
                    }

                    setTimeout(() => {
                        $row.removeClass('mmi-row-pending mmi-row-success mmi-row-error');
                        this.runProcessesSequentially(processes, index + 1, 'fetch', $button);
                    }, 1000);
                },
                error: () => {
                    $row.removeClass('mmi-row-pending').addClass('mmi-row-error');
                    setTimeout(() => {
                        $row.removeClass('mmi-row-pending mmi-row-success mmi-row-error');
                        this.runProcessesSequentially(processes, index + 1, 'fetch', $button);
                    }, 1000);
                }
            });
        },
        
        viewLogs: function(e) {
            e.preventDefault();
            
            // Show the logs section
            $('#mmi-logs-section').slideDown();
            
            // Scroll to logs section
            $('html, body').animate({
                scrollTop: $('#mmi-logs-section').offset().top - 50
            }, 500);
            
            // Load all logs initially
            this.loadLogs('all');
            
            // Bind filter and button events if not already bound
            if (!$('#log-supplier-filter').data('bound')) {
                $('#log-supplier-filter').on('change', () => {
                    const supplier = $('#log-supplier-filter').val();
                    this.loadLogs(supplier);
                });
                
                $('#refresh-logs').on('click', () => {
                    const supplier = $('#log-supplier-filter').val();
                    this.loadLogs(supplier);
                });
                
                $('#hide-logs').on('click', () => {
                    $('#mmi-logs-section').slideUp();
                });
                
                $('#log-supplier-filter').data('bound', true);
            }
        },
        
        loadLogs: function(supplier) {
            $('#log-content').text('Loading logs' + (supplier !== 'all' ? ' for ' + supplier : '') + '...');
            
            $.ajax({
                url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                method: 'POST',
                data: {
                    action: 'mmi_get_process_log',
                    supplier: supplier,
                    nonce: mmiProductImportData?.nonce || ''
                },
                success: function(response) {
                    if (response.success) {
                        const logContent = response.data.log || 'No log entries found.';
                        $('#log-content').text(logContent);
                    } else {
                        $('#log-content').text('Error: ' + (response.data?.message || 'Failed to load log'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error, xhr.responseText);
                    $('#log-content').text('Error: Failed to communicate with server.\n' + 
                                          'Status: ' + status + '\n' + 
                                          'Error: ' + error);
                }
            });
        },
        
        toggleSchedulePanel: function() {
            const $panel  = $('#mmi-topbar-schedule-panel');
            const $toggle = $('#mmi-schedule-toggle');
            // Stock Overrides / Catalog Rules panels no longer exist as separate
            // topbar panels — merged into the Catalog Maintenance section, which
            // isn't a toggle-panel at all (it's always visible when its own
            // collapsible section is expanded), so there's nothing left to
            // mutually exclude here except the Attributes modal.
            if (window.MMIDataPipeline) {
                window.MMIDataPipeline.hideAllPanels();
            }
            $panel.slideToggle(200, function() {
                $toggle.toggleClass('is-active', $panel.is(':visible'));
            });
        },

        // Current run's id — set once mmi_catalog_start_run succeeds, cleared on
        // finish/abort. Used by the beforeunload handler below so navigating away
        // mid-run still releases the shared catalog lock instead of leaving it to
        // expire on its own TTL.
        _catalogRunId: null,

        runCatalogUpdatePhased: function(e) {
            const self    = this;
            const $button = $(e.currentTarget);
            if (!confirm('Run Catalog Update now? This will process all catalog maintenance operations shown below.')) return;

            const nonce = (window.mmiImportSettings && window.mmiImportSettings.nonce)
                       || (window.mmiProductImportData && window.mmiProductImportData.nonce)
                       || '';
            const ajaxUrl = (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl;

            $button.prop('disabled', true).addClass('mmi-is-loading');

            $.post(ajaxUrl, { action: 'mmi_catalog_start_run', nonce: nonce })
                .done(function(response) {
                    if (!response || !response.success) {
                        const err = response && response.data;
                        const msg = err && err.code === 'locked'
                            ? 'A catalog update is already running (started ' + (err.started_at || 'recently') + ').'
                            : ((err && err.message) || 'Could not start catalog update.');
                        self.showFlashMessage(msg, 'warning', $('#manual-import-status'));
                        $button.prop('disabled', false).removeClass('mmi-is-loading');
                        return;
                    }

                    const runId  = response.data.run_id;
                    const phases = response.data.phases || [];
                    self._catalogRunId = runId;

                    const $progressPanel  = $('#mmi-catalog-progress-panel');
                    const $phaseContainer = $('#mmi-catalog-progress-phases');
                    const $progressTitle  = $('#mmi-catalog-progress-title');
                    $phaseContainer.empty();
                    $progressTitle.text('Running Catalog Update… (0 / ' + phases.length + ')');

                    phases.forEach(function(p, i) {
                        $phaseContainer.append(
                            '<div class="mmi-catalog-phase-row mmi-phase-pending" data-phase-index="' + i + '">' +
                            '<span class="mmi-phase-icon dashicons dashicons-minus"></span>' +
                            '<span class="mmi-phase-label">' + escHtml(p.label) + '</span>' +
                            '<span class="mmi-phase-detail"></span>' +
                            '</div>'
                        );
                    });

                    $progressPanel.slideDown(200);
                    $button.css('min-width', $button.outerWidth() + 'px');

                    self.runCatalogPhases(runId, phases, 0, $button, $progressPanel, $progressTitle);
                })
                .fail(function() {
                    self.showFlashMessage('Network error — could not start catalog update.', 'error', $('#manual-import-status'));
                    $button.prop('disabled', false).removeClass('mmi-is-loading');
                });
        },

        runCatalogPhases: function(runId, phases, index, $button, $progressPanel, $progressTitle) {
            const self  = this;
            const nonce = (window.mmiImportSettings && window.mmiImportSettings.nonce)
                       || (window.mmiProductImportData && window.mmiProductImportData.nonce)
                       || '';
            const ajaxUrl = (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl;

            const finish = function() {
                $progressTitle.text('Finalizing…');
                $.post(ajaxUrl, { action: 'mmi_catalog_finish_run', run_id: runId, nonce: nonce })
                    .always(function() {
                        self._catalogRunId = null;
                        $button.prop('disabled', false).removeClass('mmi-is-loading').css('min-width', '');
                        $progressTitle.text('Catalog Update Complete');
                        setTimeout(function() { $progressPanel.slideUp(300); }, 2500);
                        self.showFlashMessage('Store catalog updated successfully!', 'success', $('#manual-import-status'));
                        location.reload();
                    });
            };

            if (index >= phases.length) {
                finish();
                return;
            }

            const $phaseRow = $('[data-phase-index="' + index + '"]');
            $phaseRow.removeClass('mmi-phase-pending').addClass('mmi-phase-running')
                .find('.mmi-phase-icon').attr('class', 'mmi-phase-icon mmi-loading');
            $progressTitle.text('Running Catalog Update… (' + index + ' / ' + phases.length + ')');

            $.post(ajaxUrl, { action: 'mmi_catalog_run_phase', run_id: runId, index: index, nonce: nonce })
                .done(function(response) {
                    const ok   = response && response.success;
                    const data = (ok && response.data) ? response.data : {};

                    if (!ok) {
                        // A lost/expired lock means someone else's run — or ours
                        // timing out — took over; stop advancing this chain rather
                        // than keep hammering a run_id that's no longer ours.
                        $phaseRow.removeClass('mmi-phase-running').addClass('mmi-phase-error')
                            .find('.mmi-phase-icon').attr('class', 'mmi-phase-icon dashicons dashicons-warning');
                        $phaseRow.find('.mmi-phase-detail').text((response.data && response.data.message) || 'error');
                        self.showFlashMessage('Catalog update stopped: ' + ((response.data && response.data.message) || 'unknown error'), 'error', $('#manual-import-status'));
                        self._catalogRunId = null;
                        $button.prop('disabled', false).removeClass('mmi-is-loading').css('min-width', '');
                        $progressTitle.text('Catalog Update Stopped');
                        return;
                    }

                    $phaseRow.removeClass('mmi-phase-running').addClass(data.error ? 'mmi-phase-error' : 'mmi-phase-done');
                    $phaseRow.find('.mmi-phase-icon').attr('class',
                        'mmi-phase-icon dashicons dashicons-' + (data.error ? 'warning' : 'yes'));

                    if (data.skipped) {
                        $phaseRow.find('.mmi-phase-detail').text(data.message || 'skipped');
                        $phaseRow.find('.mmi-phase-icon').attr('class', 'mmi-phase-icon dashicons dashicons-minus');
                        $phaseRow.addClass('mmi-phase-skipped');
                    } else {
                        const duration = data.duration_ms != null ? (data.duration_ms / 1000).toFixed(1) : '';
                        const items    = data.items_processed != null ? data.items_processed : '';
                        const detail   = items !== '' ? items + ' items · ' + duration + 's' : duration + 's';
                        $phaseRow.find('.mmi-phase-detail').text(detail);
                    }

                    setTimeout(function() {
                        self.runCatalogPhases(runId, phases, index + 1, $button, $progressPanel, $progressTitle);
                    }, 300);
                })
                .fail(function() {
                    // Genuine network failure — stop rather than silently continuing
                    // past a phase we don't know the result of, since a stopped
                    // chain still leaves the lock held (bounded by its own TTL)
                    // rather than a partially-run sequence reported as complete.
                    $phaseRow.removeClass('mmi-phase-running').addClass('mmi-phase-error')
                        .find('.mmi-phase-icon').attr('class', 'mmi-phase-icon dashicons dashicons-warning');
                    $phaseRow.find('.mmi-phase-detail').text('network error');
                    self.showFlashMessage('Network error — catalog update stopped mid-run. It will resume being lockable once the lock expires.', 'error', $('#manual-import-status'));
                    self._catalogRunId = null;
                    $button.prop('disabled', false).removeClass('mmi-is-loading').css('min-width', '');
                    $progressTitle.text('Catalog Update Stopped');
                });
        },
        
        saveIndividualSchedule: function(e) {
            const $select = $(e.currentTarget);
            const processKey = $select.data('process');
            const schedule = $select.val();
            
            $.ajax({
                url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                method: 'POST',
                data: {
                    action: 'mmi_save_schedule',
                    process: processKey,
                    schedule: schedule,
                    nonce: mmiProductImportData?.nonce || ''
                },
                success: (response) => {
                    if (response.success) {
                        const $row = $select.closest('tr');
                        $row.find('.import-column').addClass('mmi-row-success');
                        setTimeout(() => $row.find('.import-column').removeClass('mmi-row-success'), 1500);
                    }
                }
            });
        },
        
        showFlashMessage: function(message, type, $container) {
            const $flash = $('<div>', {
                class: `mmi-flash-message mmi-flash-${type}`,
                text: (type === 'success' ? '✅ ' : '❌ ') + message,
            });
            
            $container.prepend($flash);
            
            setTimeout(function() {
                $flash.fadeOut(400, function() {
                    $(this).remove();
                });
            }, 5000);
        },

        runProcess: function(e) {
            e.preventDefault();
            console.log('MMI Product Import: Run button clicked');
            
            const $button = $(e.currentTarget);
            const process = $button.data('process');
            const $card = $button.closest('.mmi-supplier-card');
            
            console.log('MMI Product Import: Process:', process);
            console.log('MMI Product Import: Card found:', $card.length);
            console.log('MMI Product Import: AJAX URL:', window.mmiProductImportData ? window.mmiProductImportData.ajaxurl : 'MISSING');
            console.log('MMI Product Import: Nonce:', window.mmiProductImportData ? window.mmiProductImportData.nonce : 'MISSING');
            
            if (!window.mmiProductImportData || !window.mmiProductImportData.nonce) {
                console.error('MMI Product Import: mmiProductImportData not found!');
                this.showFlashMessage('Configuration error: nonce not found', 'error', $card);
                return;
            }
            
            // Remove existing flash messages
            $card.find('> div[style*="animation"]').remove();
            
            // Update button state
            $button.css('min-width', $button.outerWidth() + 'px').prop('disabled', true).addClass('mmi-is-loading').html(LABEL_RUNNING);
            
            console.log('MMI Product Import: Making AJAX request...');
            
            // Make AJAX request
            $.ajax({
                url: window.mmiProductImportData.ajaxurl,
                method: 'POST',
                data: {
                    action: 'mmi_run_process',
                    process: process,
                    nonce: window.mmiProductImportData.nonce
                },
                beforeSend: function() {
                    console.log('MMI Product Import: AJAX request starting...');
                },
                success: function(response) {
                    console.log('MMI Product Import: AJAX response:', response);
                    
                    if (response.success) {
                        MMIProductImport.showFlashMessage(
                            response.data?.message || 'Process completed successfully!',
                            'success',
                            $card
                        );
                        
                        // Update last run time if available
                        if (response.data?.last_run) {
                            $card.find('.mmi-status-row').each(function() {
                                const $label = $(this).find('.mmi-status-label');
                                if ($label.text().toLowerCase().includes('last run')) {
                                    $(this).find('span:last').text(response.data.last_run);
                                }
                            });
                        }
                        
                        // Log details for debugging
                        if (response.data?.unique_id) {
                            console.log('MMI Product Import: Backend ID:', response.data.unique_id);
                        }
                        if (response.data?.command_run) {
                            console.log('MMI Product Import: Command:', response.data.command_run);
                        }
                    } else {
                        MMIProductImport.showFlashMessage(
                            'Error: ' + (response.data?.message || 'Unknown error'),
                            'error',
                            $card
                        );
                    }
                },
                error: function(xhr, status, error) {
                    console.error('MMI Product Import: AJAX error:', error);
                    console.error('MMI Product Import: Status:', status);
                    console.error('MMI Product Import: Response:', xhr.responseText);
                    
                    MMIProductImport.showFlashMessage(
                        'AJAX error: ' + error,
                        'error',
                        $card
                    );
                },
                complete: function() {
                    $button.prop('disabled', false).removeClass('mmi-is-loading').html(LABEL_RUN_NOW_IDLE).css('min-width', '');
                }
            });
        },

        autoSaveSchedule: function(e) {
            const $select = $(e.currentTarget);
            const name = $select.attr('name').match(/schedule\[(.*?)\]/)[1];
            const value = $select.val();
            
            if (!window.mmiProductImportData || !window.mmiProductImportData.nonce) {
                console.error('Configuration error: nonce not found');
                return;
            }
            
            const schedules = {};
            schedules[name] = value;
            
            // Visual feedback
            $select.addClass('mmi-opacity-loading');
            
            $.ajax({
                url: window.ajaxurl,
                method: 'POST',
                data: {
                    action: 'mmi_save_schedules',
                    schedules: schedules,
                    nonce: window.mmiProductImportData.nonce
                },
                success: function(response) {
                    $select.removeClass('mmi-opacity-loading');
                    if (!response.success) {
                        console.error('Error saving schedule:', response);
                    }
                },
                error: function() {
                    $select.removeClass('mmi-opacity-loading');
                    console.error('Failed to save schedule');
                }
            });
        },
        
        showNotification: function(message, type) {
            // Remove existing notifications
            $('.mmi-notification').remove();
            
            const notification = $('<div>', {
                class: `mmi-notification mmi-notification--${type}`,
                text: message,
            });
            
            $('body').append(notification);
            
            // Auto-remove after 4 seconds
            setTimeout(() => {
                notification.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 4000);
        },

        showNotification: function(message, type) {
            // Always render inside .mmi-supplier-section — the data sources table container.
            // Do NOT delegate to MMIDataPipeline.showNotification (which targets
            // .mmi-process-section:visible and finds Taxonomy Mapping first).
            const escHtml = function(s) { return $('<span>').text(String(s)).html(); };
            const bgClass = (type === 'success') ? 'mmi-pipeline-notice-success'
                          : (type === 'error')   ? 'mmi-pipeline-notice-error'
                          : 'mmi-pipeline-notice-info';
            const icon = (type === 'success') ? '&#10003;' : (type === 'error') ? '&#10007;' : '&#9432;';
            $('.mmi-supplier-section .mmi-pipeline-notice').remove();
            const $n = $(
                '<div class="mmi-pipeline-notice ' + bgClass + '" role="alert">' +
                '<span class="mmi-pipeline-notice-icon">' + icon + '</span> ' +
                escHtml(message) +
                '<button class="mmi-pipeline-notice-close" aria-label="Dismiss">&times;</button>' +
                '</div>'
            );
            const $target = $('.mmi-supplier-section').first();
            if ($target.length) {
                $target.prepend($n);
            } else {
                $('.mmi-data-pipeline-container, .wrap').first().prepend($n);
            }
            $n.on('click', '.mmi-pipeline-notice-close', function() { $n.remove(); });
            setTimeout(function() { $n.fadeOut(300, function() { $(this).remove(); }); }, 6000);
        }
    };

    // Initialize when DOM is ready (with guard to prevent double-init)
    $(document).ready(function() {
        if (!MMIProductImport._initialized) {
            MMIProductImport.init();
            MMIProductImport._initialized = true;
        }
    });

    // Export globally for pipeline orchestrator
    window.MMIProductImport = MMIProductImport;

})(jQuery);
