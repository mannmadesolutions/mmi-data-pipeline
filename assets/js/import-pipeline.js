/**
 * Import Pipeline Orchestrator
 * Manages 3-step navigation, data source configuration modal, dynamic auth fields,
 * endpoint editor, add/delete supplier, enable-toggle validation, and test connection.
 */

(function($) {
    'use strict';

    const ajaxUrl = (window.mmiImportSettings && window.mmiImportSettings.ajaxurl)
                    || (window.mmiGlobal && window.mmiGlobal.ajaxUrl)
                    || window.ajaxurl
                    || '/wp-admin/admin-ajax.php';

    const nonce = (window.mmiImportSettings && window.mmiImportSettings.nonce) || '';

    // Preconfigured API integration templates (matches PHP mmi_ds_get_preconfigured_templates)
    const preconfiguredApiTemplates = {
        xchange: { label: 'Xchange', supplierName: 'Xchange' },
        skuport: { label: 'SkuPort',        supplierName: 'SkuPort' },
    };

    // Supplier name lookup for preconfigured templates (convenience shorthand)
    const preconfiguredNames = Object.fromEntries(
        Object.entries(preconfiguredApiTemplates).map(([k, v]) => [k, v.supplierName])
    );

    // ─── Source preview: freshness + on-demand fetch ────────────────────────

    const SOURCE_PREVIEW = {
        // Polling while a queued fetch runs. Bounded (AGENTS.md Rule 10) so a
        // job that dies without releasing its lock stops the UI rather than
        // polling for the rest of the session: 3s x 60 covers the 5-minute
        // server-side lock TTL exactly, then gives up and says so.
        POLL_INTERVAL_MS: 3000,
        MAX_POLLS: 60,
    };

    const SOURCE_PREVIEW_SELECTORS = {
        freshness:     '#mmi-source-preview-freshness',
        freshnessText: '#mmi-source-preview-freshness-text',
        fetchBtn:      '#mmi-source-preview-fetch-btn',
        fetchStatus:   '#mmi-source-preview-fetch-status',
    };

    const SOURCE_PREVIEW_LABELS = {
        FETCH_IDLE:    '<span class="dashicons dashicons-update"></span> Fetch Now',
        FETCH_RUNNING: '<span class="mmi-loading"></span> Fetching…',
    };

    const SOURCE_PREVIEW_MESSAGES = {
        queued:       'Fetch queued… this can take a minute for large catalogs.',
        completed:    'Fetch complete — preview updated.',
        alreadyFresh: 'Already up to date — the source had nothing newer.',
        noNewData:    'Fetch returned no new data and this feed is still stale — check the sync log.',
        timedOut:     'Still running after several minutes. Close this and check the Sources table.',
        unavailable:  'Could not reach the server to queue a fetch.',
    };

    // ─── Endpoint row template ──────────────────────────────────────────────

    function endpointRowHtml(ep) {
        ep = ep || {};
        return `
        <div class="mmi-endpoint-row">
            <div class="mmi-endpoint-fields">
                <div class="mmi-config-field mmi-ep-name">
                    <label>Name</label>
                    <input type="text" class="ep-name" value="${escAttr(ep.endpoint_name || 'Products')}" placeholder="Products">
                </div>
                <div class="mmi-config-field mmi-ep-url">
                    <label>URL</label>
                    <input type="url" class="ep-url" value="${escAttr(ep.endpoint_url || '')}" placeholder="https://api.supplier.com/products">
                </div>
                <div class="mmi-config-field mmi-ep-method">
                    <label>Method</label>
                    <select class="ep-method">
                        <option value="GET" ${ep.http_method === 'GET' || !ep.http_method ? 'selected' : ''}>GET</option>
                        <option value="POST" ${ep.http_method === 'POST' ? 'selected' : ''}>POST</option>
                    </select>
                </div>
                <div class="mmi-config-field mmi-ep-format">
                    <label>Format</label>
                    <select class="ep-format">
                        <option value="json" ${ep.response_format === 'json' || !ep.response_format ? 'selected' : ''}>JSON</option>
                        <option value="csv" ${ep.response_format === 'csv' ? 'selected' : ''}>CSV</option>
                        <option value="xml" ${ep.response_format === 'xml' ? 'selected' : ''}>XML</option>
                    </select>
                </div>
                <div class="mmi-config-field mmi-ep-root">
                    <label>Data Root</label>
                    <input type="text" class="ep-root" value="${escAttr(ep.data_root_path || '')}" placeholder="products">
                </div>
                <div class="mmi-config-field mmi-ep-primary">
                    <label><input type="radio" name="ep-primary" class="ep-primary" ${ep.is_primary == 1 ? 'checked' : ''}> Primary</label>
                </div>
                <div class="mmi-config-field mmi-ep-actions">
                    <button type="button" class="button button-small mmi-remove-endpoint-btn" title="Remove endpoint">&times;</button>
                </div>
            </div>
        </div>`;
    }

    function kvRowHtml(key, value) {
        return `<div class="mmi-kv-row">
            <input type="text" class="mmi-kv-key" placeholder="Header Name" value="${escAttr(key || '')}">
            <input type="text" class="mmi-kv-value" placeholder="Header Value" value="${escAttr(value || '')}">
            <button type="button" class="button button-small mmi-kv-remove">&times;</button>
        </div>`;
    }

    function escAttr(val) {
        return $('<div>').text(val || '').html().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * Render the Add Data Source upload preview table (header row from
     * `columns`, up to a handful of sample rows from `sampleRows`). Cell
     * values come straight from the uploaded file (or, for the Data Source
     * Preview modal, a source's own JSON feed — which can carry nested
     * arrays/objects a flat CSV never would), so every value is escaped via
     * escAttr() before insertion, and non-scalar values are JSON-stringified
     * first so the cell shows real content instead of "[object Object]".
     */
    function renderUploadPreview(columns, sampleRows) {
        var headHtml = '';
        columns.forEach(function (col) {
            headHtml += '<th>' + escAttr(col) + '</th>';
        });

        var bodyHtml = '';
        (sampleRows || []).forEach(function (row) {
            bodyHtml += '<tr>';
            columns.forEach(function (col) {
                var val = (row && row[col] !== undefined && row[col] !== null) ? row[col] : '';
                if (val !== null && typeof val === 'object') {
                    try { val = JSON.stringify(val); } catch (e) { val = String(val); }
                }
                bodyHtml += '<td>' + escAttr(val) + '</td>';
            });
            bodyHtml += '</tr>';
        });

        return { headHtml: headHtml, bodyHtml: bodyHtml };
    }

    // ─── Main Module ────────────────────────────────────────────────────────

    const MMIDataPipeline = {

        init: function() {
            MMIModal.init();
            this.bindEvents();
            this.initializeAllModules();
        },

        // ─── Event Binding ──────────────────────────────────────────────

        bindEvents: function() {
            const self = this;

            // ── Config Modal ────────────────────────────────────────────

            // Open config modal — button lives in .mmi-process-actions now
            // (2026-08-31), acting on whichever row is active (see
            // activateSupplierRow() in import-pipeline-sources.js), not a
            // per-row button anymore.
            $(document).on('click', '#mmi-configure-source-btn', function(e) {
                e.preventDefault();
                if ($(this).is(':disabled')) { return; }
                if (typeof self.openConfigModal !== 'function') {
                    console.error('MMI Data Pipeline: openConfigModal not found — import-pipeline-config.js may have failed to load.');
                    return;
                }
                self.openConfigModal($(this).data('supplier'));
            });

            // Open data source preview modal (real sample records from this
            // source's cached feed, so an admin can confirm what's actually
            // available before wiring up Field Mapping). Toolbar button now
            // (2026-08-31), acting on whichever row is active.
            $(document).on('click', '#mmi-preview-source-btn', function(e) {
                e.preventDefault();
                if ($(this).is(':disabled')) { return; }
                self.openSourcePreviewModal($(this).data('supplier'), $(this).data('supplier-name'));
            });
            $(document).on('click', '#mmi-source-preview-modal .mmi-config-modal-close, #mmi-source-preview-close-btn', function() {
                self.stopSourceFetchPolling();
                self.closeModal('#mmi-source-preview-modal');
            });

            // On-demand refresh of an API source's cached feed.
            $(document).on('click', '#mmi-source-preview-fetch-btn', function() {
                self.fetchSourceNow($(this).data('supplier'));
            });
            $(document).on('click', '#mmi-source-preview-modal', function(e) {
                if ($(e.target).is('#mmi-source-preview-modal, .mmi-config-modal-backdrop')) {
                    self.stopSourceFetchPolling();
                    self.closeModal('#mmi-source-preview-modal');
                }
            });
            $(document).on('change', '#mmi-source-preview-file-select', function() {
                self.loadSourcePreviewData($(this).data('supplier'), $(this).val());
            });

            // Tab switching
            $(document).on('click', '.mmi-config-tab', function() {
                const tab = $(this).data('tab');
                $('.mmi-config-tab').removeClass('active');
                $(this).addClass('active');
                $('.mmi-config-tab-content').removeClass('active');
                $(`.mmi-config-tab-content[data-tab="${tab}"]`).addClass('active');
            });

            // Close the config modal (header X or Close button, scoped to config modal only)
            $(document).on('click', '#mmi-config-modal .mmi-config-modal-close, #mmi-config-cancel-btn', function() {
                clearTimeout(self._autoSaveTimer);
                self._autoSaveTimer = null;
                self._configDirty = false;
                // Clean up preconfigured lock state so next open starts fresh
                $('#mmi-preconfigured-lock-banner').remove();
                $('.mmi-config-tab').show();
                self.closeModal('#mmi-config-modal');
                // If this config modal followed a wizard handoff (Add Data
                // Source → configure new source), return to the wizard now
                // that setup is done rather than stranding the user here.
                self.reopenWizardIfPending();
            });

            // Backdrop click closes without saving
            $(document).on('click', '#mmi-config-modal', function(e) {
                if ($(e.target).is('#mmi-config-modal, .mmi-config-modal-backdrop')) {
                    clearTimeout(self._autoSaveTimer);
                    self._autoSaveTimer = null;
                    self._configDirty = false;
                    // Clean up preconfigured lock state so next open starts fresh
                    $('#mmi-preconfigured-lock-banner').remove();
                    $('.mmi-config-tab').show();
                    self.closeModal('#mmi-config-modal');
                }
            });
            // Prevent clicks inside the dialog from bubbling to backdrop
            $(document).on('click', '.mmi-config-modal-dialog', function(e) {
                e.stopPropagation();
            });
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    clearTimeout(self._autoSaveTimer);
                    self._autoSaveTimer = null;
                    self._configDirty = false;
                    // Clean up preconfigured lock state so next open starts fresh
                    $('#mmi-preconfigured-lock-banner').remove();
                    $('.mmi-config-tab').show();
                    self.closeModal('.mmi-config-modal:visible');
                }
            });

            // Auth type change → dynamic fields
            $(document).on('change', '#cfg-auth-type', function() {
                self.loadAuthFields($(this).val());
            });

            // Validate Test Connection button whenever a URL or auth field changes
            $(document).on('input', '#cfg-base-url', function() {
                self.validateTestConnectionBtn();
            });
            $(document).on('input', '#mmi-endpoints-list', function(e) {
                if ($(e.target).hasClass('ep-url')) {
                    self.validateTestConnectionBtn();
                }
            });
            $(document).on('input change', '#mmi-auth-fields-container .mmi-auth-input', function() {
                self.validateTestConnectionBtn();
            });
            $(document).on('click', '#mmi-add-endpoint-btn, .mmi-remove-endpoint-btn', function() {
                // Endpoint list changed — re-validate after DOM settles
                setTimeout(function() { self.validateTestConnectionBtn(); }, 50);
            });

            // Secret auth inputs — type="text" always, no browser credential-manager prompts.
            // data-vault-mask  = original bullet string from vault (proportional to real key length)
            // data-real-value  = value typed this session; read by save/test instead of the mask
            $(document).on('focus', '#mmi-auth-fields-container .mmi-auth-input[data-orig-type="password"]', function() {
                var $el = $(this);
                if (!/^[\u2022]+$/.test($el.val())) return; // not masked, already shows real text
                var realVal = $el.attr('data-real-value');
                if (realVal !== undefined && realVal !== '') {
                    // Typed this session and masked on blur — restore the real text for re-editing
                    $el.val(realVal);
                } else {
                    // Vault-loaded mask: keep the bullets visible so the user can see a value
                    // is saved, then select-all so typing naturally replaces the whole value.
                    $el.attr('data-was-masked', '1');
                }
                // Select all text so typing replaces the entire value without needing Ctrl+A
                if (this.select) { this.select(); }
            }).on('blur', '#mmi-auth-fields-container .mmi-auth-input[data-orig-type="password"]', function() {
                var $el = $(this);
                var val = $el.val();
                if (val.length > 0 && /^[\u2022]+$/.test(val)) return; // already masked — nothing changed
                if (val === '' && $el.attr('data-was-masked') === '1') {
                    // User focused a vault-masked field but cleared it without typing anything —
                    // restore the original mask so the saved value appears to still be there.
                    $el.val($el.attr('data-vault-mask') || '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022');
                } else if (val !== '') {
                    // User entered a real value — store it and show proportional bullet mask
                    $el.attr('data-real-value', val).val('\u2022'.repeat(val.length));
                }
                $el.removeAttr('data-was-masked');
            });

            // Response format change → show/hide CSV / Excel options
            $(document).on('change', '#cfg-response-format', function() {
                const fmt = $(this).val();
                const isCsv     = fmt === 'csv' || fmt === 'tsv';
                const isExcel   = fmt === 'excel' || fmt === 'numbers';
                const isSpread  = isCsv || isExcel;

                $('.mmi-parsing-csv-options').toggle(isCsv);
                $('.mmi-parsing-excel-options').toggle(isExcel);

                // Format-specific hint notes
                $('#cfg-format-note-excel').toggle(fmt === 'excel');
                $('#cfg-format-note-numbers').toggle(fmt === 'numbers');
            });

            // Auto-save: debounce any field change inside the config modal
            $(document).on('input change', '#mmi-config-modal input, #mmi-config-modal select, #mmi-config-modal textarea', function() {
                // Do not trigger auto-save for the source type select (locked after creation)
                if ($(this).is('#cfg-source-type')) return;
                self.scheduleAutoSave();
            });

            // Test connection
            $(document).on('click', '#mmi-test-connection-btn', function() {
                self.testConnection();
            });

            // Tab-jump links inside test result hints — clicking a .mmi-tab-jump
            // switches to the target tab without closing the modal.
            $(document).on('click', '.mmi-tab-jump[data-jump-tab]', function(e) {
                e.preventDefault();
                const tab = $(this).data('jump-tab');
                $(`.mmi-config-tab[data-tab="${tab}"]`).trigger('click');
            });

            // Endpoint management
            $(document).on('click', '#mmi-add-endpoint-btn', function() {
                $('#mmi-endpoints-list').append(endpointRowHtml());
            });
            $(document).on('click', '.mmi-remove-endpoint-btn', function() {
                $(this).closest('.mmi-endpoint-row').remove();
            });

            // Key-value pair rows (custom headers)
            $(document).on('click', '.mmi-add-kv-row-btn', function() {
                const target = $(this).data('target');
                $('#' + target).append(kvRowHtml());
            });
            $(document).on('click', '.mmi-kv-remove', function() {
                $(this).closest('.mmi-kv-row').remove();
            });

            // ── File Upload source panel ─────────────────────────────────

            // Open the WP media library to pick a file
            $(document).on('click', '#mmi-upload-choose-btn', function(e) {
                e.preventDefault();
                var frame = wp.media({
                    title:    'Select Data File',
                    button:   { text: 'Use this file' },
                    library:  { type: ['text/csv','text/tab-separated-values','application/json','text/xml','application/xml','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.ms-excel'] },
                    multiple: false,
                });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#cfg-upload-attachment-id').val(attachment.id);
                    $('#cfg-upload-filename').val(attachment.filename || attachment.title || '');
                    $('#mmi-upload-clear-btn').removeClass('mmi-is-hidden');
                    self.scheduleAutoSave();
                    self.validateTestConnectionBtn();
                });
                frame.open();
            });

            // Clear the selected file
            $(document).on('click', '#mmi-upload-clear-btn', function() {
                $('#cfg-upload-attachment-id').val('');
                $('#cfg-upload-filename').val('');
                $(this).addClass('mmi-is-hidden');
                self.validateTestConnectionBtn();
            });

            // Re-validate test button when non-HTTP fields change
            $(document).on('input change', '#cfg-dropbox-file-path, #cfg-dropbox-access-token, #cfg-gdrive-file-id', function() {
                self.validateTestConnectionBtn();
            });

            // ── Add Data Source — open modal ─────────────────────────────

            $(document).on('click', '#mmi-add-data-source-btn', function() {
                self.resetAddSourceModal();
                self.openModal('#mmi-add-source-modal');
            });

            // ── Source type tile selection ───────────────────────────────
            $(document).on('click', '.mmi-source-tile', function() {
                const type = $(this).data('type');
                $('.mmi-source-tile').removeClass('active');
                $(this).addClass('active');
                $('#add-source-type').val(type);
                $('.mmi-add-source-panel').addClass('mmi-is-hidden');
                $('#add-panel-' + type).removeClass('mmi-is-hidden');
                self.validateAddSourceBtn();
            });

            // ── GDrive smart URL-to-ID extraction ───────────────────────
            $(document).on('input', '#add-gdrive-input', function() {
                const extracted = self.extractGDriveFileId($(this).val());
                if (extracted) {
                    $('#add-gdrive-id-value').text(extracted);
                    $('#add-gdrive-id-display').removeClass('mmi-is-hidden');
                } else {
                    $('#add-gdrive-id-display').addClass('mmi-is-hidden');
                }
                self.validateAddSourceBtn();
            });

            // ── Generic input validation for all panels ──────────────────
            $(document).on('input change',
                '#add-url-input, #add-url-supplier-name, ' +
                '#add-dropbox-path, #add-dropbox-token, #add-dropbox-supplier-name, ' +
                '#add-gdrive-supplier-name, #add-supplier-name, #add-api-integration',
                function() {
                    self.validateAddSourceBtn();
                }
            );

            // ── File upload drop zone ────────────────────────────────────

            // Click anywhere in idle zone OR on the browse button → open file picker
            $(document).on('click', '#add-upload-drop-zone', function(e) {
                if ($(e.target).closest('#add-drop-zone-result, #add-drop-zone-error').length) return;
                $('#add-upload-file-input').trigger('click');
            });

            // Prevent browse button from also triggering the zone click above.
            // Scoped to this specific drop zone — three separate upload zones
            // now share the .mmi-drop-browse-btn class (this one, Quick Import's,
            // and the wizard Source step's), and an unscoped selector here would
            // fire for all three at once, triggering the wrong hidden file input.
            $(document).on('click', '#add-upload-drop-zone .mmi-drop-browse-btn', function(e) {
                e.stopPropagation();
                $('#add-upload-file-input').trigger('click');
            });

            // File selected via picker
            $(document).on('change', '#add-upload-file-input', function() {
                const file = this.files[0];
                if (file) { self.analyzeUploadedFile(file); }
            });

            // Replace / Retry buttons reset the drop zone
            $(document).on('click', '#add-upload-replace-btn, #add-upload-retry-btn', function(e) {
                e.stopPropagation();
                self.resetDropZone();
                $('#add-upload-file-input').val('');
                $('#add-upload-file-input').trigger('click');
            });

            // Drag-and-drop support
            $(document).on('dragover dragenter', '#add-upload-drop-zone', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).addClass('mmi-drop-zone-over');
            });
            $(document).on('dragleave dragend', '#add-upload-drop-zone', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('mmi-drop-zone-over');
            });
            $(document).on('drop', '#add-upload-drop-zone', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).removeClass('mmi-drop-zone-over');
                const file = e.originalEvent.dataTransfer.files[0];
                if (file) { self.analyzeUploadedFile(file); }
            });

            $(document).on('click', '#mmi-add-source-modal .mmi-config-modal-close', function() {
                self.closeModal('#mmi-add-source-modal');
                self.reopenWizardIfPending();
            });
            // Close add source modal when clicking backdrop
            $(document).on('click', '#mmi-add-source-modal', function(e) {
                if ($(e.target).is('#mmi-add-source-modal, .mmi-config-modal-backdrop')) {
                    self.closeModal('#mmi-add-source-modal');
                    self.reopenWizardIfPending();
                }
            });
            $(document).on('click', '#mmi-add-source-confirm-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.addDataSource();
            });

            // ── Delete Data Source ───────────────────────────────────────

            // Toolbar button now (2026-08-31), acting on whichever row is active.
            $(document).on('click', '#mmi-delete-source-btn', function(e) {
                e.preventDefault();
                if ($(this).is(':disabled')) { return; }
                const sid = $(this).data('supplier');
                if (confirm('Are you sure you want to delete this data source? This cannot be undone.')) {
                    self.deleteDataSource(sid);
                }
            });

            // Enable/Disable toggle removed — a source is enabled the moment
            // it's validated, with no separate manual step (see AGENTS.md's
            // "Enabled Toggle Eliminated" entry). Nothing to bind here anymore.

            // ── Supplier Row Selection (2026-08-31) ──────────────────────
            // Mirrors initProfileCardsGrid()'s card-click behavior exactly —
            // clicking a row makes it the active selection the toolbar's
            // Configure/Preview Data/Delete buttons act on. Excludes clicks
            // on the row's own interactive controls (batch-select checkbox,
            // Web Assets / Taxonomy Mapping toggle switches, the Web Assets
            // info icon) — flipping one of those is a distinct action from
            // "select this row for the toolbar," not a trigger for it.
            $(document).on('click keydown', '.mmi-supplier-row', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') { return; }
                if ($(e.target).is('input, label, .mmi-toggle-slider, .mmi-info-icon, .mmi-taxmap-quick-link')
                    || $(e.target).closest('label, form, .mmi-taxmap-quick-link').length) {
                    return;
                }
                e.preventDefault();
                const supplierId = $(this).data('supplier');
                if (!supplierId) { return; }
                self.activateSupplierRow(supplierId);
            });

            // ── Per-Row "Taxonomy Mapping" Quick Link (2026-08-31) ───────
            // Answers the user's own framing directly: a data source row
            // should show whether it has a mapping in place, and clicking
            // through should be the easy way to assign/view one — opens the
            // Taxonomy Mapping panel (in the Supplier Data Sources toolbar,
            // expanding it if collapsed) pre-filtered to just this source.
            $(document).on('click', '.mmi-taxmap-quick-link', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const supplierId   = $(this).data('taxmap-open-supplier');
                const supplierName = $(this).data('taxmap-supplier-name') || supplierId;
                if (supplierId && window.MMITaxMapping && typeof window.MMITaxMapping.openForSupplier === 'function') {
                    window.MMITaxMapping.openForSupplier(supplierId, supplierName);
                }
            });

            // ── Per-Source Taxonomy Mapping Toggle (2026-08-31) ──────────
            // Narrower than the toggle removed above — this doesn't affect
            // whether the source imports at all, only whether its brand/
            // category values are resolved through Taxonomy Mapping's alias
            // table (see MMI_Pipeline_Admin::get_configured_suppliers()'s
            // 'taxonomy_mapping_enabled' key).
            $(document).on('change', '.mmi-source-taxmap-toggle', function() {
                self.toggleSourceTaxonomyMapping($(this).data('supplier'), $(this).is(':checked'), $(this));
            });

            // ── Fetch Selected Checkbox ─────────────────────────────────
            // NOTE: Checkbox state + run-button management (including #run-selected-import)
            // is handled by product-import.js (MMIProductImport.handleSelectAll /
            // handleCheckboxChange / updateActionButtons). Binding here was duplicating
            // that work and interfering with the select-all sync.

            $(document).on('change', '.mmi-csv-column-input, .mmi-csv-transform-select', function() {
                const supplier = $(this).data('supplier');
                self.autosaveCSVMapping(supplier);
            });
            $(document).on('change', '.mmi-csv-delimiter-select, .mmi-csv-has-header-checkbox', function() {
                self.saveCSVConfig($(this).data('supplier'));
            });
            $(document).on('click', '.mmi-btn-load-csv-preview', function(e) {
                e.preventDefault();
                self.loadCSVPreview($(this).data('supplier'));
            });
            $(document).on('click', '.mmi-btn-test-csv-mapping', function(e) {
                e.preventDefault();
                self.testCSVMapping($(this).data('supplier'));
            });
            $(document).on('click', '.mmi-btn-auto-detect-columns', function(e) {
                e.preventDefault();
                self.autoDetectCSVColumns($(this).data('supplier'));
            });

            // Autosave for import rules and scheduling
            $(document).on('change', '.mmi-autosave-field', function() {
                const setting = $(this).data('setting');
                const value   = $(this).is(':checkbox') ? ($(this).is(':checked') ? '1' : '') : $(this).val();
                self.autosaveImportRule(setting, value);
            });
            // Track the previous value so autosaveSchedule can revert the
            // <select>/time <input> if the user declines a config-issue
            // confirmation. Both controls for a given process live in the
            // same .mmi-schedule-controls wrapper (see tab-pipeline.php).
            $(document).on('focus', '.mmi-autosave-schedule, .mmi-autosave-schedule-time', function() {
                $(this).data('mmi-prev-value', $(this).val());
            });
            $(document).on('change', '.mmi-autosave-schedule', function() {
                self.updateScheduleRowDisabledState($(this));
                // Swap the time control to/from the hourly-only minute select
                // BEFORE reading its value — updateScheduleTimeControlKind()
                // returns the (possibly newly-replaced) element either way.
                const $time = self.updateScheduleTimeControlKind($(this));
                // Re-evaluate this row's Fetch → Import Linking row(s)
                // against the newly-chosen frequency — a no-op on a Data
                // Fetch source row (no .mmi-tsched-link-row there).
                self.updateLinkSuggestionMatches($(this));
                self.autosaveSchedule($(this).data('process'), $(this).val(), $(this), $time.val(), $time);
            });
            // Time-of-day picker — saves alongside whichever frequency is
            // currently selected for this same process, so the two controls
            // never drift out of sync (see MMI_Pipeline_Cron::compute_schedule_anchor()).
            $(document).on('change', '.mmi-autosave-schedule-time', function() {
                const $freq = $(this).closest('.mmi-schedule-controls').find('.mmi-autosave-schedule');
                self.autosaveSchedule($(this).data('process'), $freq.val(), $freq, $(this).val(), $(this));
            });
            // Per-profile "Skip if no new data" schedule gate.
            $(document).on('change', '.mmi-schedule-skip-toggle', function() {
                self.toggleScheduleSkipIfNoData($(this).data('profile'), $(this).is(':checked'));
            });
            // Fetch → Import Linking — see MMI_Pipeline_Cron's "Fetch →
            // Import Linking" section. One .mmi-toggle-switch checkbox per
            // (profile, source) row; its own :checked state after the click
            // IS the link/unlink action (checked → link, unchecked →
            // unlink) — a profile can have more than one row checked at
            // once, each fully independent, unlike the old single-link
            // version's separate link/unlink toggle classes. toggleProfileLink()
            // (import-pipeline-csv.js) does the AJAX call; applyProfileLinkState()
            // (import-pipeline-ui.js) patches the DOM from its response —
            // no page reload either way.
            $(document).on('change', '.mmi-tsched-link-toggle', function() {
                self.toggleProfileLink($(this).data('profile'), $(this).data('supplier'), $(this).is(':checked'), $(this));
            });
        },

        // ─── Module Initialization ──────────────────────────────────────

        // Sub-modules (MMIProductImport, MMIImportPreview, MMIImportSettings)
        // all initialize themselves via their own document.ready handlers.
        initializeAllModules: function() {},

        // ─── Profile Cards Grid ─────────────────────────────────────────

        initProfileCardsGrid: function() {
            const $select = $('#mmi-import-profile');
            const $grid   = $('#mmi-profile-cards-grid');

            if (!$grid.length) { return; }

            /** Mark the clicked card as active + sync the hidden select.
             *  The Import Profiles section header is a static title now (not
             *  mirrored from whichever card is selected) — this only needs to
             *  toggle the .is-active row highlight itself. */
            function activateCard(profileId) {
                const escapedId = CSS.escape ? CSS.escape(profileId) : profileId;
                $grid.find('.mmi-profile-grid-card')
                    .removeClass('is-active')
                    .attr('aria-pressed', 'false');
                $grid.find('.mmi-profile-grid-card[data-profile-id="' + escapedId + '"]')
                    .addClass('is-active').attr('aria-pressed', 'true');
            }

            // Card body click: activate profile
            $(document).on('click keydown', '.mmi-profile-grid-card:not(.mmi-pgc-new-card)', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') { return; }
                e.preventDefault();
                const profileId = $(this).data('profile-id');
                if (!profileId) { return; }
                activateCard(profileId);
                $select.val(profileId).trigger('change');
            });

            // Table empty-state button → open the unified profile wizard
            // (same one #new-profile-btn opens in the section bar above).
            $(document).on('click keydown', '#no-profiles-create-btn-grid', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') { return; }
                e.preventDefault();
                $('#new-profile-btn').trigger('click');
            });

            // Keep grid in sync when hidden select changes from other sources
            $select.on('change.gridSync', function() {
                activateCard($(this).val());
            });

            // Watch hidden select for option additions/removals (profile create/delete)
            if ($select.length && typeof MutationObserver !== 'undefined') {
                new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType !== 1 || node.tagName !== 'OPTION') { return; }
                            const id   = node.value;
                            const name = node.textContent.trim();
                            if ($grid.find('[data-profile-id="' + id + '"]').length) { return; }
                            // $grid is the <tbody> (see initProfileCardsGrid() above) — a
                            // freshly-created profile has no mode configured yet, so that
                            // cell stays empty here too, matching the div-card version's
                            // prior behavior of omitting the mode badge on a brand-new row.
                            const $row = $('<tr>', {
                                'class': 'mmi-profile-grid-card',
                                'data-profile-id': id,
                                'data-profile-name': name,
                                role: 'button',
                                tabIndex: 0,
                                'aria-pressed': 'false',
                            }).append(
                                $('<td class="mmi-pgc-col-name">').append(
                                    $('<span class="mmi-pgc-name">').text(name)
                                ),
                                // Type, like Mode below, isn't known yet at this point in
                                // profile creation (the wizard's Step 1 data-type pick
                                // hasn't run), so it stays blank here too.
                                $('<td class="mmi-pgc-col-type">'),
                                $('<td class="mmi-pgc-col-mode">'),
                                $('<td class="mmi-pgc-col-sources mmi-pgc-sources">').append(
                                    $('<span class="mmi-pgc-no-sources">').text('No sources assigned')
                                ),
                                // Matches tab-pipeline.php's server-rendered empty-cache
                                // state (no pending-stats row yet for a brand-new
                                // profile) — kept in sync deliberately, same as every
                                // other cell in this template, per the "Wizard Source
                                // List Went Stale" incident's own lesson about these
                                // two templates drifting apart.
                                $('<td class="mmi-pgc-col-pending">').append(
                                    $('<span class="mmi-pgc-pending-wrap">')
                                ),
                                $('<td class="mmi-pgc-col-refresh">').append(
                                    $('<button type="button" class="mmi-pgc-pending-check-btn">')
                                        .attr('data-profile-id', id)
                                        .attr('title', 'Check the source data for pending create/update work')
                                        .append('<span class="dashicons dashicons-search"></span> Check')
                                ),
                                $('<td class="mmi-pgc-col-schedule">')
                            );
                            $grid.append($row);
                        });
                        mutation.removedNodes.forEach(function(node) {
                            if (node.nodeType !== 1 || node.tagName !== 'OPTION') { return; }
                            $grid.find('[data-profile-id="' + node.value + '"]').remove();
                        });
                    });
                    activateCard($select.val());
                }).observe($select[0], { childList: true });
            }
        },

        // ─── Profile Pending Stats (Import Profiles grid's "Pending" column) ──
        //
        // Renders whatever tab-pipeline.php already had cached at page-render
        // time (possibly nothing, possibly stale — see MMI_Import_Preview::
        // get_cached_profile_pending_stats(), which never computes). This
        // sweep fills in/refreshes those cells AFTER load, one profile at a
        // time (never more than one in-flight request at once — well within
        // AGENTS.md's "≤2 simultaneous auto-fired AJAX" limit) rather than
        // firing one request per row simultaneously.
        initProfilePendingStats: function() {
            const self = this;

            // Stats live in .mmi-pgc-col-pending, but the Check/Refresh button
            // that drives them lives one cell over, in its own .mmi-pgc-col-refresh
            // (split out so the refresh icon isn't crammed in next to whichever
            // stat tile happens to be widest — see AGENTS.md UI-symmetry notes on
            // this grid). Both are looked up from the shared row so a click in
            // either cell can find the other.
            function renderRow($row, profileId, stats) {
                const $wrap = $row.find('.mmi-pgc-pending-wrap');
                const relevantType = $wrap.data('relevant-type') || 'both';
                const create = parseInt((stats && stats.will_create) || 0, 10);
                const update = parseInt((stats && stats.will_update) || 0, 10);
                const isStale = !!(stats && stats.stale);
                const computedAt = (stats && stats.computed_at) || '';

                let html = '';
                if (relevantType === 'create' || relevantType === 'both') {
                    const clickable = create > 0 ? ' mmi-stat-clickable' : '';
                    const title = create > 0
                        ? create + ' new product(s) ready to create — click to review in Review & Compare'
                        : 'No new products pending';
                    html += '<span class="mmi-pgc-pending-stat mmi-stat-create' + clickable + '" data-profile-id="' + profileId + '" title="' + title + '">'
                        + '<span class="mmi-stat-value">' + create.toLocaleString() + '</span>'
                        + '<span class="mmi-pgc-pending-label">new</span></span>';
                }
                if (relevantType === 'update' || relevantType === 'both') {
                    html += '<span class="mmi-pgc-pending-stat mmi-stat-update" title="' + update + ' product(s) with changed data pending">'
                        + '<span class="mmi-stat-value">' + update.toLocaleString() + '</span>'
                        + '<span class="mmi-pgc-pending-label">changed</span></span>';
                }

                $wrap.empty().append(
                    $('<span class="mmi-pgc-pending-stats' + (isStale ? ' mmi-pgc-pending-stale' : '') + '">')
                        .attr('title', computedAt ? ('Last checked ' + computedAt) : '')
                        .html(html)
                );

                // First real check for this row: swap the "Check" CTA for the
                // icon-only refresh button now that there's something to refresh.
                const $refreshCell = $row.find('.mmi-pgc-col-refresh');
                if (!$refreshCell.find('.mmi-pgc-pending-refresh-btn').length) {
                    $refreshCell.empty().append(
                        $('<button type="button" class="mmi-pgc-pending-refresh-btn" title="Refresh now">')
                            .attr('data-profile-id', profileId)
                            .append('<span class="dashicons dashicons-update"></span>')
                    );
                }
            }

            function checkOne(profileId, $row, forceRefresh, onDone) {
                const $btn = $row.find('.mmi-pgc-pending-refresh-btn, .mmi-pgc-pending-check-btn');
                $btn.addClass('mmi-is-loading').prop('disabled', true);
                self.fetchProfilePendingStats(profileId, forceRefresh, function(stats, err) {
                    if (stats) { renderRow($row, profileId, stats); }
                    $row.find('.mmi-pgc-pending-refresh-btn, .mmi-pgc-pending-check-btn')
                        .removeClass('mmi-is-loading').prop('disabled', false);
                    if (onDone) { onDone(); }
                });
            }

            // Background sweep on page load: every row whose action cell shipped
            // with no cached snapshot (the "Check" button state) gets one,
            // sequentially — a stale-but-present snapshot is left for the
            // user to refresh manually rather than auto-recomputed on every
            // visit, since it's already shown (just muted) and this sweep's
            // job is filling gaps, not keeping every row maximally fresh.
            const $needsCheck = $('.mmi-pgc-col-refresh').filter(function() {
                return $(this).find('.mmi-pgc-pending-check-btn').length > 0;
            });
            let i = 0;
            (function next() {
                if (i >= $needsCheck.length) { return; }
                const $cell = $needsCheck.eq(i++);
                checkOne($cell.data('profile-id'), $cell.closest('tr'), false, next);
            })();

            // Manual refresh / initial check click.
            $(document).on('click', '.mmi-pgc-pending-refresh-btn, .mmi-pgc-pending-check-btn', function(e) {
                e.preventDefault();
                const $row = $(this).closest('tr');
                checkOne($(this).data('profile-id'), $row, true, null);
            });

            // Clicking a "will create" tile: jump to this profile in Review &
            // Compare (same-page, no reload — mirrors MMIDupes.openForCollision()'s
            // established expand+scroll pattern for a different section).
            $(document).on('click', '.mmi-pgc-pending-stat.mmi-stat-create.mmi-stat-clickable', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const profileId = $(this).data('profile-id');
                if (!profileId) { return; }
                const $row = $('.mmi-profile-grid-card[data-profile-id="' + profileId + '"]');
                if ($row.length && !$row.hasClass('is-active')) {
                    $row.trigger('click');
                }
                const $section = $('#mmi-review-compare-section');
                $section.removeClass('collapsed');
                $section[0] && $section[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        },

        // ─── Sortable Sections (retired) ──────────────────────────────────
        //
        // Drag-to-reorder was removed: section order on this tab is now fixed
        // (Data Sources → Profile → Review & Compare → Process Logs → Import
        // History) and no longer user-rearrangeable. Sections still collapse/
        // expand via their header — that's handled independently in
        // import-preview.js's '.mmi-section-header, .mmi-collapse-toggle'
        // click handler and isn't affected by this. Any order a user
        // previously dragged into is discarded here (one-time cleanup) so the
        // fixed order above always wins.
        initSortableSections: function() {
            try { localStorage.removeItem('mmi_pipeline_section_order'); } catch (e) {}
        },

        // ─── Modal Helpers ──────────────────────────────────────────────

        // Now thin wrappers around the shared MMIModal (MOD-DPL-01) — was a
        // bespoke mmi-is-hidden-class + inline-style-animation toggle, one
        // copy shared by all 3 .mmi-config-modal dialogs (Config, Source
        // Preview, Add Source). `selector` is usually a real id (`#foo`),
        // but one Esc-key call site passes '.mmi-config-modal:visible' —
        // not valid as a CSS selector for MMIModal's own querySelector-based
        // resolve(), so that gets treated the same as "close whichever is
        // open" (MMIModal tracks that internally; only one is ever open at
        // once, same assumption the old code made).
        openModal: function(selector) {
            MMIModal.open(selector);
            $('html, body, #wpwrap, #wpcontent').css('overflow', 'hidden');
        },

        closeModal: function(selector) {
            if (selector && selector.indexOf(':visible') === -1) {
                MMIModal.close(selector);
            } else {
                MMIModal.close();
            }
            $('html, body, #wpwrap, #wpcontent').css('overflow', '');
        },

        // ─── Data Source Preview Modal ──────────────────────────────────
        //
        // Reads a supplier's real cached JSON feed straight from the browser
        // (same static file the Field Mapping tab's "Browse" button reads)
        // and renders the first handful of records as a columns × rows table
        // — lets an admin confirm what a source actually contains (and its
        // real field names) without ever leaving this tab, and without any
        // new backend reporting endpoint.

        openSourcePreviewModal: function(supplier, supplierName) {
            const self = this;

            $('#mmi-source-preview-title').text('Preview: ' + (supplierName || supplier));
            $('#mmi-source-preview-meta').text('');
            $('#mmi-source-preview-count').text('');
            $('#mmi-source-preview-head, #mmi-source-preview-body').empty();
            $('#mmi-source-preview-error, #mmi-source-preview-empty').addClass('mmi-is-hidden');
            $('#mmi-source-preview-wrap').addClass('mmi-is-hidden');
            $('#mmi-source-preview-loading').removeClass('mmi-is-hidden');

            $(SOURCE_PREVIEW_SELECTORS.freshness).addClass('mmi-is-hidden');
            $(SOURCE_PREVIEW_SELECTORS.fetchStatus).text('').removeClass('mmi-fetch-status-error');
            this.stopSourceFetchPolling();

            const suppliers = (window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) || [];
            const info = suppliers.find(s => s.supplier_id === supplier) || {};
            const fileOptions = info.fileOptions || {};

            // Only API sources have a remote endpoint to re-pull on demand.
            const $fetchBtn = $(SOURCE_PREVIEW_SELECTORS.fetchBtn)
                .data('supplier', supplier)
                .prop('disabled', false)
                .removeClass('mmi-is-loading')
                .html(SOURCE_PREVIEW_LABELS.FETCH_IDLE);
            $fetchBtn.toggleClass('mmi-is-hidden', info.source_type !== 'api');
            const filenames = Object.keys(fileOptions);

            const $fileSelect = $('#mmi-source-preview-file-select').data('supplier', supplier);
            const $filePicker = $('.mmi-source-preview-file-picker');
            $fileSelect.empty();
            if (filenames.length > 1) {
                filenames.forEach(f => $fileSelect.append($('<option>').attr('value', f).text(fileOptions[f] || f)));
                $filePicker.removeClass('mmi-is-hidden');
            } else {
                if (filenames.length === 1) {
                    $fileSelect.append($('<option>').attr('value', filenames[0]).text(fileOptions[filenames[0]] || filenames[0]));
                }
                $filePicker.addClass('mmi-is-hidden');
            }

            this.openModal('#mmi-source-preview-modal');

            const filename = filenames[0] || (supplier + '-products.json');
            self.loadSourcePreviewData(supplier, filename);
        },

        loadSourcePreviewData: function(supplier, filename) {
            const self = this;
            if (!filename) {
                $('#mmi-source-preview-loading').addClass('mmi-is-hidden');
                $('#mmi-source-preview-empty').removeClass('mmi-is-hidden');
                return;
            }

            $('#mmi-source-preview-loading').removeClass('mmi-is-hidden');
            $('#mmi-source-preview-error, #mmi-source-preview-empty').addClass('mmi-is-hidden');
            $('#mmi-source-preview-wrap').addClass('mmi-is-hidden');

            // Feed files are private (ADR-0012): read through admin-ajax, not a
            // public uploads URL.
            const basePath = ajaxUrl + '?action=mmi_pipeline_source_file&nonce=' + encodeURIComponent(nonce)
                + '&filename=' + encodeURIComponent(filename);

            const renderFrom = function (data) {
                let items = data;
                if (data && !Array.isArray(data) && typeof data === 'object') {
                    // Xchange-style {products: [...]} (or any single array-valued
                    // container key) wrapper — unwrap it the same way the Field
                    // Mapping tab's field browser does.
                    const arrayKey = Object.keys(data).find(k => Array.isArray(data[k]));
                    items = arrayKey ? data[arrayKey] : [data];
                }
                if (!Array.isArray(items)) { items = [items]; }

                if (!items.length) {
                    $('#mmi-source-preview-loading').addClass('mmi-is-hidden');
                    $('#mmi-source-preview-empty').removeClass('mmi-is-hidden');
                    return;
                }

                const sampleSize = 15;
                const sample = items.slice(0, sampleSize);

                // Column set = union of every sampled record's own top-level
                // keys (not a deep recursive scan — this is a raw "what does a
                // record look like" preview, not a field-mapping picker).
                const columns = [];
                sample.forEach(function (item) {
                    if (item && typeof item === 'object') {
                        Object.keys(item).forEach(function (k) {
                            if (columns.indexOf(k) === -1) { columns.push(k); }
                        });
                    }
                });

                const preview = renderUploadPreview(columns, sample);
                $('#mmi-source-preview-head').html(preview.headHtml);
                $('#mmi-source-preview-body').html(preview.bodyHtml);
                $('#mmi-source-preview-count').text('(first ' + sample.length + ' of ' + items.length.toLocaleString() + ' records)');
                $('#mmi-source-preview-meta').text(filename);
                $('#mmi-source-preview-loading').addClass('mmi-is-hidden');
                $('#mmi-source-preview-wrap').removeClass('mmi-is-hidden');
            };

            // No cache file yet — ask the server to fetch this source once, on
            // demand, then retry the same static read exactly once. This mirrors
            // the Field Mapping tab's Browse-button fallback; it's an acceptable
            // one-time side effect here because opening this modal is an explicit
            // user action, not something that runs on page load.
            const onFeedMissing = function () {
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: { action: 'mmi_pipeline_ensure_source_cache', nonce: nonce, supplier_id: supplier }
                }).then(function (response) {
                    if (!response || !response.success) {
                        $('#mmi-source-preview-loading').addClass('mmi-is-hidden');
                        $('#mmi-source-preview-error').removeClass('mmi-is-hidden')
                            .find('p').text((response && response.data && response.data.message) || 'Could not fetch data for this source.');
                        return;
                    }
                    $.ajax({ url: basePath, dataType: 'json', cache: false }).then(renderFrom, function () {
                        $('#mmi-source-preview-loading').addClass('mmi-is-hidden');
                        $('#mmi-source-preview-empty').removeClass('mmi-is-hidden');
                    });
                    // The file now exists — re-read its age so the freshness
                    // stamp reflects what was just written, not its absence.
                    self.refreshSourceFreshness(supplier, filename);
                }, function () {
                    $('#mmi-source-preview-loading').addClass('mmi-is-hidden');
                    $('#mmi-source-preview-error').removeClass('mmi-is-hidden').find('p').text('Could not request data for this source.');
                });
            };

            // Ask the server for the file's age first, then read the file itself
            // stamped with that mtime. Ordering matters twice over: the records
            // can never appear without the date qualifying them, and the mtime in
            // the URL is what lets the browser cache a 13MB feed while still being
            // guaranteed to re-download it the moment a fetch replaces it.
            // (Depth-1 cascade, explicit user action — within the AJAX limits.)
            this.refreshSourceFreshness(supplier, filename).always(function (status) {
                const mtime = status && status.file_mtime;
                const url   = mtime ? (basePath + '&v=' + mtime) : basePath;
                $.ajax({ url: url, dataType: 'json', cache: true }).then(renderFrom, onFeedMissing);
            });
        },

        // ─── Source Freshness & On-Demand Fetch ─────────────────────────
        //
        // The age shown here is the previewed file's own mtime, resolved
        // server-side. It deliberately does not reuse the Sources table's
        // stored "last fetch" value: that timestamp records when a run
        // happened, not whether the run replaced anything, and a fetch that
        // silently no-ops leaves it looking current while the data underneath
        // is days old.

        refreshSourceFreshness: function(supplier, filename) {
            const self  = this;
            const $wrap = $(SOURCE_PREVIEW_SELECTORS.freshness);
            const $text = $(SOURCE_PREVIEW_SELECTORS.freshnessText);

            return $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mmi_pipeline_source_status',
                    nonce: nonce,
                    supplier_id: supplier,
                    filename: filename
                }
            }).then(function(response) {
                const data = response && response.success ? response.data : null;
                if (!data || !data.file_mtime) {
                    $wrap.addClass('mmi-is-hidden');
                    return data;
                }

                self._sourceFreshness = data;
                $text.text(`Source data from ${data.fetched_display} (${data.fetched_ago})`);
                $wrap.removeClass('mmi-is-hidden')
                     .toggleClass('mmi-freshness-stale', !!data.is_stale)
                     .attr('title', data.is_stale
                        ? `Older than ${data.stale_after_hours}h — importing now would use out-of-date data.`
                        : '');
                return data;
            }, function() {
                // Never leave a stale timestamp on screen if we can't confirm it.
                $wrap.addClass('mmi-is-hidden');
            });
        },

        stopSourceFetchPolling: function() {
            clearTimeout(this._sourceFetchPollTimer);
            this._sourceFetchPollTimer = null;
        },

        setSourceFetchBusy: function(isBusy) {
            $(SOURCE_PREVIEW_SELECTORS.fetchBtn)
                .prop('disabled', isBusy)
                .toggleClass('mmi-is-loading', isBusy)
                .html(isBusy ? SOURCE_PREVIEW_LABELS.FETCH_RUNNING : SOURCE_PREVIEW_LABELS.FETCH_IDLE);
        },

        setSourceFetchStatus: function(message, isError) {
            $(SOURCE_PREVIEW_SELECTORS.fetchStatus)
                .text(message || '')
                .toggleClass('mmi-fetch-status-error', !!isError);
        },

        /**
         * Queue an on-demand fetch for the previewed API source.
         *
         * The server queues the work and returns immediately rather than
         * fetching inline, so this holds no PHP-FPM worker open for the
         * duration; the polling below is what turns that into a finished state.
         */
        fetchSourceNow: function(supplier) {
            const self = this;

            self.setSourceFetchBusy(true);
            self.setSourceFetchStatus('');
            self._sourceFetchMtimeBefore = self._sourceFreshness && self._sourceFreshness.file_mtime;

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: { action: 'mmi_pipeline_fetch_source_now', nonce: nonce, supplier_id: supplier }
            }).then(function(response) {
                if (response && response.success) {
                    self.setSourceFetchStatus(SOURCE_PREVIEW_MESSAGES.queued);
                    self.pollSourceFetch(supplier, 0);
                    return;
                }

                // Rate-limited is a normal answer, not a failure: the API's own
                // minimum gap hasn't elapsed. Say when it will have, and let the
                // button come back on its own rather than making the user guess.
                const data = (response && response.data) || {};
                self.setSourceFetchStatus(data.message || SOURCE_PREVIEW_MESSAGES.unavailable, true);

                if (data.code === 'rate_limited' && data.retry_in_ms > 0) {
                    setTimeout(function() {
                        self.setSourceFetchBusy(false);
                        self.setSourceFetchStatus('');
                    }, data.retry_in_ms);
                    return;
                }

                if (data.code === 'already_running') {
                    self.pollSourceFetch(supplier, 0);
                    return;
                }

                self.setSourceFetchBusy(false);
            }, function() {
                self.setSourceFetchStatus(SOURCE_PREVIEW_MESSAGES.unavailable, true);
                self.setSourceFetchBusy(false);
            });
        },

        pollSourceFetch: function(supplier, attempt) {
            const self = this;

            if (attempt >= SOURCE_PREVIEW.MAX_POLLS) {
                self.setSourceFetchStatus(SOURCE_PREVIEW_MESSAGES.timedOut, true);
                self.setSourceFetchBusy(false);
                return;
            }

            // Captured before the fetch so "did anything change?" is answered by
            // the file itself advancing, not by trusting a status string.
            const mtimeBefore = self._sourceFetchMtimeBefore;

            self._sourceFetchPollTimer = setTimeout(function() {
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'mmi_pipeline_source_status',
                        nonce: nonce,
                        supplier_id: supplier,
                        filename: $('#mmi-source-preview-file-select').val() || ''
                    }
                }).then(function(response) {
                    const data = response && response.success ? response.data : null;

                    if (data && data.fetching) {
                        self.pollSourceFetch(supplier, attempt + 1);
                        return;
                    }

                    self.setSourceFetchBusy(false);

                    const isStale  = !!(data && data.last_fetch_status === 'stale');
                    const advanced = !!(data && data.file_mtime && data.file_mtime !== mtimeBefore);
                    self.setSourceFetchStatus(
                        isStale ? SOURCE_PREVIEW_MESSAGES.noNewData
                            : (advanced ? SOURCE_PREVIEW_MESSAGES.completed
                                        : SOURCE_PREVIEW_MESSAGES.alreadyFresh),
                        isStale
                    );

                    // Reflect this fetch on the main Supplier Data Sources
                    // table's Last Fetch/Records cells too — this modal's own
                    // status line updating was previously the only place this
                    // fetch was visible; the table row behind the modal stayed
                    // on whatever it showed at page load until a full reload.
                    if (data && window.MMIDataPipeline && window.MMIDataPipeline.applySourceStatusToRow) {
                        window.MMIDataPipeline.applySourceStatusToRow(supplier, data);
                    }

                    // Re-read the feed to show what the fetch actually produced.
                    self.loadSourcePreviewData(supplier, $('#mmi-source-preview-file-select').val()
                        || (data && data.filename)
                        || (supplier + '-products.json'));
                }, function() {
                    self.pollSourceFetch(supplier, attempt + 1);
                });
            }, SOURCE_PREVIEW.POLL_INTERVAL_MS);
        },

        // ─── Add Source Wizard Helpers ─────────────────────────────────

        /**
         * Reset the "Add New Data Source" modal to its default state
         * (Upload tile selected, all fields cleared).
         */
        resetAddSourceModal: function() {
            // Select "Upload File" tile by default
            $('.mmi-source-tile').removeClass('active');
            $('.mmi-source-tile[data-type="upload"]').addClass('active');
            $('#add-source-type').val('upload');

            // Show upload panel, hide all others
            $('.mmi-add-source-panel').addClass('mmi-is-hidden');
            $('#add-panel-upload').removeClass('mmi-is-hidden');

            // Reset the drop zone
            this.resetDropZone();

            // Clear all other panel fields
            $('#add-url-input, #add-url-supplier-name').val('');
            $('#add-dropbox-path, #add-dropbox-token, #add-dropbox-supplier-name').val('');
            $('#add-gdrive-input, #add-gdrive-supplier-name').val('');
            $('#add-gdrive-id-display').addClass('mmi-is-hidden');
            $('#add-api-integration').val('');

            // Confirm button starts disabled
            $('#mmi-add-source-confirm-btn').prop('disabled', true);
        },

        /** Reset the file upload drop zone to idle state. */
        resetDropZone: function() {
            $('#add-upload-attachment-id, #add-upload-detected-format').val('');
            $('#add-supplier-name').val('');
            $('#add-upload-name-row').addClass('mmi-is-hidden');
            $('#add-upload-drop-zone').removeClass('mmi-drop-zone-over');
            $('#add-drop-zone-idle').removeClass('mmi-is-hidden');
            $('#add-drop-zone-analyzing, #add-drop-zone-result, #add-drop-zone-error').addClass('mmi-is-hidden');
            $('#add-upload-preview').addClass('mmi-is-hidden');
            $('#add-upload-preview-head, #add-upload-preview-body').empty();
        },

        /**
         * Validate and toggle the "Create Data Source" button based on
         * which panel is active and whether all required fields are filled.
         */
        validateAddSourceBtn: function() {
            const type = $('#add-source-type').val() || 'upload';
            let ok = false;

            switch (type) {
                case 'upload':
                    ok = !!$('#add-upload-attachment-id').val() &&
                         !!$('#add-supplier-name').val().trim();
                    break;
                case 'url':
                    ok = !!$('#add-url-input').val().trim() &&
                         !!$('#add-url-supplier-name').val().trim();
                    break;
                case 'dropbox':
                    ok = !!$('#add-dropbox-path').val().trim() &&
                         !!$('#add-dropbox-token').val().trim() &&
                         !!$('#add-dropbox-supplier-name').val().trim();
                    break;
                case 'gdrive':
                    ok = !!this.extractGDriveFileId($('#add-gdrive-input').val()) &&
                         !!$('#add-gdrive-supplier-name').val().trim();
                    break;
                case 'api':
                    ok = !!$('#add-api-integration').val();
                    break;
            }

            $('#mmi-add-source-confirm-btn').prop('disabled', !ok);
        },

        /**
         * Extract a Google Drive file ID from a share URL or bare ID string.
         * Returns the ID string or null if not detectable.
         */
        extractGDriveFileId: function(input) {
            if (!input) { return null; }
            input = input.trim();
            // /d/FILE_ID/ pattern (Drive file view URLs + Sheets/Docs)
            var m = input.match(/\/d\/([a-zA-Z0-9_-]{20,})/);
            if (m) { return m[1]; }
            // ?id=FILE_ID or &id=FILE_ID
            m = input.match(/[?&]id=([a-zA-Z0-9_-]{20,})/);
            if (m) { return m[1]; }
            // Bare file ID
            if (/^[a-zA-Z0-9_-]{20,}$/.test(input)) { return input; }
            return null;
        },

        /**
         * Upload a file via AJAX, analyze it, and update the drop zone UI.
         * On success stores the attachment_id and detected format in hidden fields.
         *
         * `opts` is optional -- omitting it entirely reproduces the exact prior
         * behavior (hardcoded `add-*` drop-zone IDs, calls self.validateAddSourceBtn()
         * on success). The unified Import Profile wizard's inline Source-step upload
         * (import-settings.js) passes its own `wizard-*` selector map plus
         * onSuccess/onError callbacks instead of the Add Source modal's
         * validate-button call, since it drives a different UI with different
         * next-step logic -- this is the ONE upload/analyze implementation every
         * upload entry point shares, not a separate copy of the same ~65 lines.
         *
         * @param {File} file
         * @param {Object} [opts]
         * @param {Object} [opts.selectors] Override any of the default `add-*` element IDs.
         * @param {Function} [opts.onSuccess] function(data, file) -- called instead of
         *        the default supplier-name-field/validate-button handling when provided.
         * @param {Function} [opts.onError] function(message) -- called instead of the
         *        default error-state handling when provided.
         */
        analyzeUploadedFile: function(file, opts) {
            var self = this;
            opts = opts || {};
            var sel = $.extend({
                idleId:            'add-drop-zone-idle',
                resultId:          'add-drop-zone-result',
                errorId:           'add-drop-zone-error',
                analyzingId:       'add-drop-zone-analyzing',
                errorMsgId:        'add-drop-zone-error-msg',
                attachmentInputId:'add-upload-attachment-id',
                formatInputId:     'add-upload-detected-format',
                resultFilenameId:  'add-upload-result-filename',
                resultMetaId:      'add-upload-result-meta',
                supplierNameId:    'add-supplier-name',
                nameRowId:         'add-upload-name-row',
            }, opts.selectors || {});

            var ajaxUrl = (window.mmiImportSettings && window.mmiImportSettings.ajaxurl) ||
                          (window.mmiGlobal && window.mmiGlobal.ajaxUrl) ||
                          window.ajaxurl ||
                          '/wp-admin/admin-ajax.php';
            var nonce   = (window.mmiImportSettings && window.mmiImportSettings.nonce) || '';

            // Show analyzing state
            $('#' + sel.idleId + ', #' + sel.resultId + ', #' + sel.errorId).addClass('mmi-is-hidden');
            $('#' + sel.analyzingId).removeClass('mmi-is-hidden');
            $('#' + sel.attachmentInputId).val('');
            if (!opts.onSuccess) {
                $('#mmi-add-source-confirm-btn').prop('disabled', true);
            }

            var formData = new FormData();
            formData.append('file', file);
            formData.append('action', 'mmi_analyze_uploaded_file');
            formData.append('nonce', nonce);

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        var d = response.data;

                        // Persist attachment info in hidden fields
                        $('#' + sel.attachmentInputId).val(d.attachment_id);
                        $('#' + sel.formatInputId).val(d.format || 'csv');

                        // Build meta line: "CSV - 1,247 rows - 8 columns"
                        var metaParts = [(d.format || 'csv').toUpperCase()];
                        if (d.row_count !== null && d.row_count > 0) {
                            metaParts.push(Number(d.row_count).toLocaleString() + ' rows');
                        }
                        if (d.columns && d.columns.length > 0) {
                            metaParts.push(d.columns.length + ' columns');
                        }

                        $('#' + sel.resultFilenameId).text(d.filename || file.name);
                        $('#' + sel.resultMetaId).text(metaParts.join(' • '));

                        $('#' + sel.analyzingId).addClass('mmi-is-hidden');
                        $('#' + sel.resultId).removeClass('mmi-is-hidden');

                        if (opts.onSuccess) {
                            opts.onSuccess(d, file);
                        } else {
                            // Default (Add Source modal) behavior, unchanged.
                            if (d.suggested_name) { $('#' + sel.supplierNameId).val(d.suggested_name); }
                            $('#' + sel.nameRowId).removeClass('mmi-is-hidden');
                            $('#' + sel.supplierNameId).trigger('focus');
                            self.validateAddSourceBtn();

                            // Data preview so the user can confirm they uploaded the
                            // right file before creating the source. Only rendered on
                            // this default path (Add Source modal) — Quick Import
                            // supplies its own onSuccess and doesn't use this table.
                            if (d.columns && d.columns.length > 0) {
                                var preview = renderUploadPreview(d.columns, d.sample_rows);
                                $('#add-upload-preview-head').html(preview.headHtml);
                                $('#add-upload-preview-body').html(preview.bodyHtml);
                                $('#add-upload-preview-count').text(
                                    '(first ' + (d.sample_rows ? d.sample_rows.length : 0) + ' of ' +
                                    Number(d.row_count || 0).toLocaleString() + ' rows)'
                                );
                                $('#add-upload-preview').removeClass('mmi-is-hidden');
                            } else {
                                $('#add-upload-preview').addClass('mmi-is-hidden');
                            }
                        }

                    } else {
                        var msg = (response.data && response.data.message) || 'Failed to analyze file.';
                        $('#' + sel.analyzingId).addClass('mmi-is-hidden');
                        if (opts.onError) {
                            opts.onError(msg);
                        } else {
                            $('#' + sel.errorId).removeClass('mmi-is-hidden');
                            $('#' + sel.errorMsgId).text(msg);
                        }
                    }
                },
                error: function() {
                    var msg = 'Server error during upload. Please try again.';
                    $('#' + sel.analyzingId).addClass('mmi-is-hidden');
                    if (opts.onError) {
                        opts.onError(msg);
                    } else {
                        $('#' + sel.errorId).removeClass('mmi-is-hidden');
                        $('#' + sel.errorMsgId).text(msg);
                    }
                }
            });
        }

    };

    // ─── Expose template helpers for module files ──────────────────────────────
    MMIDataPipeline._endpointRowHtml        = endpointRowHtml;
    MMIDataPipeline._kvRowHtml              = kvRowHtml;
    MMIDataPipeline._escAttr                = escAttr;
    MMIDataPipeline._preconfiguredNames     = preconfiguredNames;
    MMIDataPipeline._preconfiguredApiTemplates = preconfiguredApiTemplates;

    $(document).ready(function() {
        MMIDataPipeline.init();
        MMIDataPipeline.initProfileCardsGrid();
        MMIDataPipeline.initProfilePendingStats();
        MMIDataPipeline.initSortableSections();
    });

    window.MMIDataPipeline = MMIDataPipeline;

})(jQuery);
