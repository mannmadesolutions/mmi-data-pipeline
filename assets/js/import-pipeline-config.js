/**
 * Import Pipeline — Config Modal
 * Extends window.MMIDataPipeline with methods for this concern.
 */
(function($) {
    'use strict';

    var ajaxUrl = (window.mmiImportSettings && window.mmiImportSettings.ajaxurl)
                    || (window.mmiGlobal && window.mmiGlobal.ajaxUrl)
                    || window.ajaxurl
                    || '/wp-admin/admin-ajax.php';
    var nonce = (window.mmiImportSettings && window.mmiImportSettings.nonce) || '';

    var endpointRowHtml = (window.MMIDataPipeline && window.MMIDataPipeline._endpointRowHtml)
                          || function() { return ''; };
    var kvRowHtml = (window.MMIDataPipeline && window.MMIDataPipeline._kvRowHtml)
                    || function() { return ''; };
    // Must be captured here — used in openConfigModal AJAX success callback.
    // import-pipeline.js exposes this after it assigns window.MMIDataPipeline,
    // but the IIFE-local capture at module load time is needed because this
    // file runs synchronously and the reference would otherwise be undefined.
    var preconfiguredNames = (window.MMIDataPipeline && window.MMIDataPipeline._preconfiguredNames) || {};

    function escHtml(str) { return window.MMIEscapeHtml(str); }
    // Attribute-safe escaping for the Taxonomies tab row builder (same helper
    // import-pipeline.js / import-pipeline-sources.js each keep locally).
    function escAttr(val) { return $("<div>").text(val == null ? "" : String(val)).html().replace(/"/g, "&quot;").replace(/'/g, "&#39;"); }

    if (!window.MMIDataPipeline) {
        console.error('MMI Data Pipeline: core object not found — import-pipeline-config.js cannot extend it.');
        return;
    }

    Object.assign(window.MMIDataPipeline, {
        openConfigModal: function(supplierId) {
            const self = this;

            // Block auto-save until the modal is fully populated
            self._loadingConfig = true;
            self._configDirty = false;
            if (self._autoSaveTimer) {
                clearTimeout(self._autoSaveTimer);
                self._autoSaveTimer = null;
            }
            $('#mmi-autosave-status').html('');

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: { action: 'mmi_get_data_source', supplier_id: supplierId, nonce: nonce },
                success: function(response) {
                    if (!response.success) {
                        self.showNotification(response.data?.message || 'Failed to load configuration', 'error');
                        return;
                    }

                    const s = response.data;
                    const config = s.configuration || {};
                    const auth   = s.auth_config || {};
                    const file   = s.file_config || {};

                    // Detect preconfigured API integration — credentials-only mode.
                    // Fall back to supplierId match so rows created before the
                    // preconfigured_template key was introduced are also locked.
                    const tpl = config.preconfigured_template ||
                        (preconfiguredNames.hasOwnProperty(supplierId) ? supplierId : '');
                    self._configTemplate = tpl;
                    const managedByXchange = !!s.managed_by_xchange_plugin;

                    // Reset modal tabs: preconfigured → auth tab only; custom → all tabs from Connection
                    $('.mmi-config-tab').removeClass('active');
                    $('.mmi-config-tab-content').removeClass('active');
                    if (tpl) {
                        // Preconfigured API: hide all tabs except Authentication
                        // and Taxonomies (the declared brand/category fields
                        // apply to a template source as much as a custom one).
                        $('.mmi-config-tab').hide();
                        $('.mmi-config-tab[data-tab="auth"]').show().addClass('active');
                        $('.mmi-config-tab[data-tab="taxonomies"]').show();
                        $('.mmi-config-tab-content[data-tab="auth"]').addClass('active');
                    } else {
                        // Custom source: show all tabs, start at Connection
                        $('.mmi-config-tab').show();
                        $('.mmi-config-tab').first().addClass('active');
                        $('.mmi-config-tab-content').first().addClass('active');
                    }
                    $('#mmi-config-supplier-id').val(supplierId);
                    // Also collapse/reset the inline PK Data Quality panel from
                    // whatever supplier it was last showing — see
                    // pk-quality-modal.js. .val() doesn't fire 'change', so this
                    // is a direct call rather than an event listener.
                    if (window.MMIPkQualityModal && typeof window.MMIPkQualityModal.onConfigModalOpen === 'function') {
                        window.MMIPkQualityModal.onConfigModalOpen(supplierId);
                    }
                    if (tpl) {
                        // Preconfigured API: only credentials matter
                        $('#mmi-test-result').html(
                            '<span class="mmi-test-setup-guide">' +
                            '<span class="mmi-setup-step">Enter your API credentials below, then click <strong>Test Connection</strong>.</span>' +
                            '</span>'
                        );
                    } else {
                        $('#mmi-test-result').html(
                            '<span class="mmi-test-setup-guide">' +
                            '<span class="mmi-setup-step"><strong>1.</strong> <a class="mmi-tab-jump" data-jump-tab="connection">Connection</a> &mdash; Base URL</span>' +
                            '<span class="mmi-setup-step"><strong>2.</strong> <a class="mmi-tab-jump" data-jump-tab="auth">Authentication</a> &mdash; auth type &amp; credentials</span>' +
                            '<span class="mmi-setup-step"><strong>3.</strong> <a class="mmi-tab-jump" data-jump-tab="endpoints">Endpoints</a> &mdash; API path</span>' +
                            '<span class="mmi-setup-step"><strong>4.</strong> Click <strong>Test Connection</strong> below</span>' +
                            '</span>'
                        );
                    }

                    $('#mmi-config-modal-title').text('Configure: ' + s.supplier_name);

                    // ── Connection tab
                    $('#cfg-supplier-name').val(s.supplier_name);

                    // Lock source type — cannot be changed after creation
                    const sourceTypeLabels = { api: 'API', url: 'Direct URL', upload: 'File Upload', dropbox: 'Dropbox', gdrive: 'Google Drive' };
                    const sourceTypeLabel = sourceTypeLabels[s.source_type] || s.source_type;
                    $('#cfg-source-type').val(s.source_type).hide();
                    $('#cfg-source-type-locked').show().html(
                        '<span class="mmi-source-type-badge mmi-source-' + escHtml(s.source_type) + '">' + escHtml(sourceTypeLabel) + '</span>'
                    );
                    $('.mmi-source-type-lock-note').show();

                    $('#cfg-base-url').val(config.base_url || '');
                    // JS-side safety: if base_url still missing, migrate legacy api_url from timed_token auth_config
                    if (!$('#cfg-base-url').val() && auth.api_url) {
                        $('#cfg-base-url').val(auth.api_url);
                    }
                    $('#cfg-documentation-url').val(s.documentation_url || '');
                    $('#cfg-notes').val(s.notes || '');

                    // ── Taxonomies tab: declared taxonomy fields. The row
                    // normally carries configuration.taxonomy_fields (seeded
                    // from its template; see mmi_ds_complete_template_setup);
                    // fall back to the template list for a row the migration
                    // has not reached yet, and to nothing for a custom source.
                    const tplDefs = (window.mmiImportSettings && window.mmiImportSettings.preconfiguredTemplates) || {};
                    self._taxonomyTemplateFields = (tpl && tplDefs[tpl] && Array.isArray(tplDefs[tpl].taxonomyFields))
                        ? tplDefs[tpl].taxonomyFields
                        : [];
                    const declaredTaxonomyFields = Array.isArray(config.taxonomy_fields)
                        ? config.taxonomy_fields
                        : self._taxonomyTemplateFields;
                    self.renderTaxonomyFieldRows(declaredTaxonomyFields);
                    $('#mmi-reset-taxonomy-fields-btn').toggleClass('mmi-is-hidden', self._taxonomyTemplateFields.length === 0);
                    $('#mmi-taxonomy-field-suggestions').addClass('mmi-is-hidden').empty();

                    // ── Non-HTTP source type panels (upload / dropbox / gdrive)
                    self.applySourceTypePanels(s.source_type);
                    if (s.source_type === 'upload') {
                        var attId   = parseInt(config.upload_attachment_id || 0, 10);
                        var attName = config.upload_filename || '';
                        $('#cfg-upload-attachment-id').val(attId || '');
                        if (attName) {
                            $('#cfg-upload-filename').val(attName);
                            $('#mmi-upload-clear-btn').removeClass('mmi-is-hidden');
                        } else {
                            $('#cfg-upload-filename').val('');
                            $('#mmi-upload-clear-btn').addClass('mmi-is-hidden');
                        }
                    }
                    if (s.source_type === 'dropbox') {
                        $('#cfg-dropbox-file-path').val(config.dropbox_file_path || '');
                        $('#cfg-dropbox-access-token').val(
                            config.dropbox_has_token ? '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022' : ''
                        );
                    }
                    if (s.source_type === 'gdrive') {
                        $('#cfg-gdrive-file-id').val(config.gdrive_file_id || '');
                    }

                    // ── Preconfigured API lock banner (credentials-only notice)
                    $('#mmi-preconfigured-lock-banner').remove();
                    if (tpl) {
                        const intName = $('<span>').text(s.supplier_name || tpl).html();
                        let bannerText = '<strong>' + intName + '</strong> is a preconfigured API integration. ' +
                            'Connection settings, endpoints, and parsing rules are managed automatically &mdash; ' +
                            'only your API credentials need to be entered below.';
                        if (managedByXchange) {
                            bannerText += ' <strong>Credentials are managed by the MMI Xchange plugin</strong> &mdash; ' +
                                'edit them there; the fields below are read-only.';
                        }
                        $('.mmi-config-tabs').before(
                            '<div id="mmi-preconfigured-lock-banner" class="mmi-preconfigured-banner">' +
                            '<span class="dashicons dashicons-lock"></span> ' + bannerText +
                            '</div>'
                        );
                    }

                    // Open the modal now — all visible fields are populated, no flash
                    self.openModal('#mmi-config-modal');

                    // Disable test button until required fields are validated
                    $('#mmi-test-connection-btn').prop('disabled', true);
                    // (validateTestConnectionBtn is also called after auth fields finish loading)

                    // ── Auth tab
                    $('#cfg-auth-type').val(auth.type || 'none');
                    // Merge non-credential auth config fields (stored unmasked directly in auth_config)
                    // with masked credential resolved_values. Config values are only injected when
                    // resolved_values doesn't already contain a real (non-placeholder) value, so that
                    // a corrupted auth_config field can't overwrite a good wp_mmi-resolved value.
                    var authValues = Object.assign({}, auth.resolved_values || {});
                    ['token_url', 'api_url', 'scope', 'auth_url', 'redirect_uri', 'header_name', 'param_name', 'algorithm'].forEach(function(k) {
                        if (auth[k] && (!authValues[k] || /^[\u2022]+$/.test(authValues[k]))) {
                            authValues[k] = auth[k];
                        }
                    });
                    self.loadAuthFields(auth.type || 'none', authValues, managedByXchange);

                    // ── Endpoints tab
                    $('#mmi-endpoints-list').empty();
                    const endpoints = s.endpoints || [];
                    if (endpoints.length === 0) {
                        // Add one empty row
                        $('#mmi-endpoints-list').append(endpointRowHtml({ is_primary: 1 }));
                    } else {
                        endpoints.forEach(function(ep) {
                            $('#mmi-endpoints-list').append(endpointRowHtml(ep));
                        });
                    }

                    // ── Parsing tab
                    const fmt = file.format || 'json';
                    const isCsvFmt   = (fmt === 'csv' || fmt === 'tsv');
                    const isExcelFmt = (fmt === 'excel' || fmt === 'numbers');
                    // .val() only, no .trigger('change'): the modal's input/change
                    // autosave listener caught that event, so merely opening
                    // Configure saved (and rebuilt) the source's row. The lines
                    // below do everything the change handler would.
                    $('#cfg-response-format').val(fmt);
                    $('.mmi-parsing-csv-options').toggle(isCsvFmt);
                    $('.mmi-parsing-excel-options').toggle(isExcelFmt);
                    $('#cfg-format-note-excel').toggle(fmt === 'excel');
                    $('#cfg-format-note-numbers').toggle(fmt === 'numbers');

                    $('#cfg-data-root-path').val(file.data_root_path || '');
                    $('#cfg-delimiter').val(file.delimiter || ',');
                    $('#cfg-enclosure').val(file.enclosure || '"');
                    $('#cfg-has-header').prop('checked', file.has_header !== false);
                    $('#cfg-excel-has-header').prop('checked', file.has_header !== false);
                    $('#cfg-encoding').val(file.encoding || 'UTF-8');
                    $('#cfg-skip-rows').val(file.skip_rows || 0);
                    $('#cfg-excel-skip-rows').val(file.skip_rows || 0);
                    $('#cfg-sheet-name').val(file.sheet_name || '');

                    // ── Advanced tab
                    $('#cfg-timeout').val(config.timeout || 60);
                    $('#cfg-retries').val(config.retries || 3);
                    $('#cfg-cache-duration').val(config.cache_duration || 0);
                    $('#cfg-detect-changes').prop('checked', config.detect_changes !== false);

                    // Custom headers
                    $('#mmi-custom-headers-list').empty();
                    const customHeaders = config.custom_headers || {};
                    const headerKeys = Object.keys(customHeaders);
                    if (headerKeys.length) {
                        headerKeys.forEach(function(k) {
                            $('#mmi-custom-headers-list').append(kvRowHtml(k, customHeaders[k]));
                        });
                    }
                },
                error: function() {
                    self.showNotification('Failed to load data source configuration', 'error');
                }
            });
        },

        // ─── Load Auth Fields Dynamically ───────────────────────────────

        loadAuthFields: function(authType, existingValues, readOnly) {
            const self = this;
            existingValues = existingValues || {};

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: { action: 'mmi_get_auth_fields', auth_type: authType, nonce: nonce },
                success: function(response) {
                    if (response.success) {
                        $('#mmi-auth-fields-container').html(response.data.html || '');

                        // Populate existing values. Tag vault bullet-masks with data-vault-mask
                        // so the blur handler can restore the original mask on focus-with-no-change.
                        if (Object.keys(existingValues).length) {
                            $.each(existingValues, function(key, val) {
                                const $input = $(`#mmi-auth-fields-container [data-key="${key}"]`);
                                if ($input.length && val) {
                                    if ($input.attr('data-orig-type') === 'password' && val && !/^[\u2022]+$/.test(val)) {
                                        // Real value arrived from server — mask for display and store
                                        // in data-real-value so the focus handler can reveal it on click.
                                        $input.attr('data-real-value', val).val('\u2022'.repeat(val.length));
                                    } else {
                                        $input.val(val);
                                        if (/^[\u2022]+$/.test(val)) {
                                            // Legacy vault-mask path (server sent pre-masked bullets).
                                            $input.attr('data-vault-mask', val);
                                        }
                                    }
                                }
                            });
                        }

                        if (readOnly) {
                            $('#mmi-auth-fields-container').find('input, select, textarea').prop('disabled', true);
                        }

                        // Settings the integration fixes (e.g. Plugivery's token
                        // parameter name) are not the admin's to enter: hide them
                        // and drop [required] so Test Connection is not blocked
                        // on a field the server ignores (the template wins on save).
                        const tplInfo = (window.mmiImportSettings && window.mmiImportSettings.preconfiguredTemplates || {})[self._configTemplate] || {};
                        (tplInfo.fixedAuthKeys || []).forEach(function(key) {
                            $(`#mmi-auth-fields-container [data-key="${key}"]`)
                                .prop('required', false)
                                .closest('.mmi-auth-field').addClass('mmi-is-hidden');
                        });
                    }
                },
                complete: function() {
                    // Modal is now fully populated — allow auto-save
                    self._loadingConfig = false;
                    // Re-evaluate whether the Test Connection button should be enabled
                    self.validateTestConnectionBtn();
                }
            });
        },

        // ─── Save Configuration ─────────────────────────────────────────

        saveConfig: function(onComplete) {
            const self = this;
            const supplierId = $('#mmi-config-supplier-id').val();

            if (!supplierId) {
                if (typeof onComplete === 'function') onComplete(false);
                return;
            }
            // Prevent concurrent saves
            if (self._isSaving) {
                if (typeof onComplete === 'function') onComplete(false);
                return;
            }
            self._isSaving = true;
            const credentials = {};
            $('#mmi-auth-fields-container .mmi-auth-input').each(function() {
                const key = $(this).data('key');
                let val = $(this).val();
                // Any run of bullet chars is a mask (vault-loaded or session-typed).
                // • data-real-value set  → typed this session, send it to PHP to persist
                // • data-real-value absent → value lives in vault, send nothing (PHP preserves it)
                if (val.length > 0 && /^[\u2022]+$/.test(val)) {
                    const stored = $(this).attr('data-real-value');
                    val = (stored !== undefined && stored !== '') ? stored : '';
                }
                if (key && val) {
                    credentials[key] = val;
                }
            });

            // Gather custom auth headers
            const authCustomHeaders = {};
            $('#auth-custom-headers-list .mmi-kv-row').each(function() {
                const k = $(this).find('.mmi-kv-key').val();
                const v = $(this).find('.mmi-kv-value').val();
                if (k) authCustomHeaders[k] = v;
            });

            // Gather endpoints
            const endpoints = [];
            $('#mmi-endpoints-list .mmi-endpoint-row').each(function() {
                const $row = $(this);
                endpoints.push({
                    endpoint_name:   $row.find('.ep-name').val(),
                    endpoint_url:    $row.find('.ep-url').val(),
                    http_method:     $row.find('.ep-method').val(),
                    response_format: $row.find('.ep-format').val(),
                    data_root_path:  $row.find('.ep-root').val(),
                    is_primary:      $row.find('.ep-primary').is(':checked') ? 1 : 0,
                });
            });

            // Gather custom headers
            const customHeaders = {};
            $('#mmi-custom-headers-list .mmi-kv-row').each(function() {
                const k = $(this).find('.mmi-kv-key').val();
                const v = $(this).find('.mmi-kv-value').val();
                if (k) customHeaders[k] = v;
            });

            const configPayload = {
                connection: {
                    supplier_name:     $('#cfg-supplier-name').val(),
                    source_type:       $('#cfg-source-type').val(),
                    base_url:          $('#cfg-base-url').val(),
                    documentation_url: $('#cfg-documentation-url').val(),
                    notes:             $('#cfg-notes').val(),
                    // Non-HTTP source fields
                    upload_attachment_id: parseInt($('#cfg-upload-attachment-id').val() || 0, 10),
                    dropbox_file_path:    $('#cfg-dropbox-file-path').val(),
                    dropbox_access_token: (function() {
                        var v = $('#cfg-dropbox-access-token').val();
                        return (v && !/^[\u2022]+$/.test(v)) ? v : '';
                    }()),
                    gdrive_file_id:       $('#cfg-gdrive-file-id').val(),
                },
                auth: {
                    type:        $('#cfg-auth-type').val(),
                    credentials: credentials,
                    custom_headers: authCustomHeaders,
                    // OAuth2/timed-token specific
                    token_url:    credentials.token_url || '',
                    api_url:      credentials.api_url || '',
                    scope:        credentials.scope || '',
                    auth_url:     credentials.auth_url || '',
                    redirect_uri: credentials.redirect_uri || '',
                    header_name:  credentials.header_name || '',
                    param_name:   credentials.param_name || '',
                    algorithm:    credentials.algorithm || '',
                },
                endpoints: endpoints,
                parsing: {
                    format:         $('#cfg-response-format').val(),
                    data_root_path: $('#cfg-data-root-path').val(),
                    delimiter:      $('#cfg-delimiter').val(),
                    enclosure:      $('#cfg-enclosure').val(),
                    has_header:     $('#cfg-has-header').is(':checked') || $('#cfg-excel-has-header').is(':checked'),
                    encoding:       $('#cfg-encoding').val(),
                    skip_rows:      parseInt($('#cfg-skip-rows').val() || $('#cfg-excel-skip-rows').val() || 0, 10),
                    sheet_name:     $('#cfg-sheet-name').val(),
                },
                advanced: {
                    timeout:        $('#cfg-timeout').val(),
                    retries:        $('#cfg-retries').val(),
                    cache_duration: $('#cfg-cache-duration').val(),
                    detect_changes: $('#cfg-detect-changes').is(':checked'),
                    custom_headers: customHeaders,
                },
                // Taxonomies tab — see renderTaxonomyFieldRows(). Sent even
                // when empty: an emptied list is the answer "this feed has
                // no brand/category field", which the server stores as []
                // (DataSourceController.php, mmi_save_data_source).
                taxonomy_fields: self.collectTaxonomyFields(),
            };

            const $status = $('#mmi-autosave-status');
            $status.html('<span class="mmi-autosave-saving"><span class="dashicons dashicons-update mmi-spin"></span> Saving…</span>');

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action:      'mmi_save_data_source',
                    supplier_id: supplierId,
                    config:      JSON.stringify(configPayload),
                    nonce:       nonce,
                },
                success: function(response) {
                    if (response.success) {
                        // Update the row in the table
                        const $row = $(`tr[data-supplier="${supplierId}"]`);
                        const src  = response.data.source || {};
                        const cfgStatus = response.data.config_status || 'configured';

                        // Update supplier name
                        $row.find('.mmi-supplier-name-cell strong').text(src.supplier_name || '');

                        // Update source type badge
                        const typeLabels = { api: 'API', url: 'Direct URL', upload: 'Upload', dropbox: 'Dropbox', gdrive: 'Google Drive' };
                        $row.find('.mmi-source-type-badge')
                            .attr('class', 'mmi-source-type-badge mmi-source-' + (src.source_type || 'api'))
                            .text(typeLabels[src.source_type] || src.source_type);
                        $row.attr('data-source-type', src.source_type || 'api');

                        // Keep the Fetch Data panel's own checkbox for this
                        // source in sync (see markSupplierValidated()'s
                        // identical comment for why both .attr() and .data()
                        // are set) — source type can change here even when
                        // this save doesn't reach 'validated' below.
                        $(`.mmi-fetch-source-checkbox[value="${supplierId}"]`)
                            .attr('data-source-type', src.source_type || 'api')
                            .data('source-type', src.source_type || 'api');

                        // Update status badge and configure button.
                        // Badge definitions live in mmiImportSettings.badgeMap (set in class-pipeline-admin.php via wp_localize_script).
                        const bm = (mmiImportSettings && mmiImportSettings.badgeMap) || {};
                        const bmEntry = bm[cfgStatus] || bm['unconfigured'] || { class: 'warning', label: cfgStatus };
                        self.updateBadge(supplierId, bmEntry.class, bmEntry.label);
                        // Configure/Preview Data/Delete moved to the toolbar (2026-08-31) —
                        // only relabel the toolbar's button when this row is the active one.
                        if ($row.hasClass('is-active')) {
                            $('#mmi-configure-source-btn').html('<span class="dashicons dashicons-admin-generic"></span> ' + (cfgStatus === 'unconfigured' ? 'Set Up' : 'Configure'));
                        }
                        $row.attr('data-config-status', cfgStatus);

                        // A source is enabled the moment it's validated — no
                        // separate manual toggle step anymore (see AGENTS.md's
                        // "Enabled Toggle Eliminated" entry). Never treat a row
                        // that was already validated by a successful Test
                        // Connection run as un-validated here; the save response
                        // may carry a stale 'configured' status if the save raced
                        // with the test AJAX request.
                        const priorStatus = $row.attr('data-config-status');
                        if (cfgStatus === 'validated' || priorStatus === 'validated') {
                            self.markSupplierValidated(supplierId);
                        } else {
                            $row.attr('data-config-status', cfgStatus);
                            $(`.mmi-fetch-source-checkbox[value="${supplierId}"]`)
                                .attr('data-config-status', cfgStatus)
                                .data('config-status', cfgStatus);
                        }

                        // Declared taxonomy fields → every in-page consumer
                        // (2026-10-07): the localized supplier list (what
                        // syncFieldMappingSuppliers() re-injects from) and the
                        // Field Mapping table's "Enable for X" toggles, if the
                        // wizard's Fields step is on screen. Taxonomy Mapping's
                        // own pills are server-rendered and pick this up on
                        // its next Reload/open.
                        const savedTaxonomyFields = (src.configuration && Array.isArray(src.configuration.taxonomy_fields))
                            ? src.configuration.taxonomy_fields
                            : [];
                        if (window.mmiImportSettings && Array.isArray(window.mmiImportSettings.configuredSuppliers)) {
                            window.mmiImportSettings.configuredSuppliers.forEach(function(cs) {
                                if (cs.supplier_id === supplierId) { cs.taxonomyFields = savedTaxonomyFields; }
                            });
                        }
                        if (typeof self.syncSupplierTaxonomyToggles === 'function') {
                            self.syncSupplierTaxonomyToggles(supplierId, src.supplier_name || supplierId, savedTaxonomyFields);
                        }
                        self.updateTaxonomyQuickLinkTitle(supplierId, savedTaxonomyFields);

                        // Mark config as clean after a confirmed save
                        self._configDirty = false;
                        self._isSaving = false;

                        // Update auto-save status indicator
                        $status.html('<span class="mmi-autosave-saved"><span class="dashicons dashicons-yes"></span> Saved</span>');
                        setTimeout(function() { $status.html(''); }, 2200);

                        // Update inline result with next-step prompt if not yet validated
                        if (cfgStatus !== 'validated') {
                            $('#mmi-test-result').html(
                                '<span class="mmi-test-hint">Saved \u2014 click <strong>Test Connection</strong> below to verify. ' +
                                'A successful test is required before this source can be enabled.</span>'
                            );
                        }

                        if (typeof onComplete === 'function') onComplete(true);
                    } else {
                        self._isSaving = false;
                        self.showNotification(response.data?.message || 'Failed to save configuration', 'error');
                        if (typeof onComplete === 'function') onComplete(false);
                    }
                },
                error: function() {
                    self._isSaving = false;
                    self.showNotification('Server error while saving configuration', 'error');
                    $status.html('<span class="mmi-autosave-error"><span class="dashicons dashicons-warning"></span> Save failed</span>');
                    setTimeout(function() { $status.html(''); }, 3000);
                    if (typeof onComplete === 'function') onComplete(false);
                },
                complete: function() {
                    // Status already updated in success/error handlers
                }
            });
        },

        // ─── Taxonomies tab (declared taxonomy fields, 2026-10-07) ──────
        //
        // One .mmi-kv-row per declared field: a WooCommerce taxonomy select
        // (mmiImportSettings.productTaxonomies) + the raw source field. The
        // list is the source's configuration.taxonomy_fields — what Field
        // Mapping's "Enable for X" toggles, Taxonomy Mapping's source list and
        // the Data Sources table read. Every input lives inside #mmi-config-
        // modal, so the modal's existing delegated input/change listener
        // autosaves edits here exactly like any other field.

        taxonomyFieldRowHtml: function(wcTaxonomy, sourceField) {
            const taxonomies = (window.mmiImportSettings && window.mmiImportSettings.productTaxonomies) || [];
            let opts = '<option value="">— WooCommerce taxonomy —</option>';
            let seen = false;
            taxonomies.forEach(function(t) {
                const sel = (t.slug === wcTaxonomy) ? ' selected' : '';
                if (sel) { seen = true; }
                opts += `<option value="${escAttr(t.slug)}"${sel}>${escHtml(t.label)} (${escHtml(t.slug)})</option>`;
            });
            if (wcTaxonomy && !seen) {
                // A taxonomy no longer registered (plugin off?) — keep the
                // saved value visible rather than silently dropping it.
                opts += `<option value="${escAttr(wcTaxonomy)}" selected>${escHtml(wcTaxonomy)}</option>`;
            }
            return `<div class="mmi-kv-row mmi-taxonomy-field-row">
                <select class="mmi-taxonomy-field-taxonomy" title="WooCommerce taxonomy this field feeds">${opts}</select>
                <input type="text" class="mmi-taxonomy-field-source" placeholder="Source field, e.g. brand or master_category+sub_category" value="${escAttr(sourceField || '')}">
                <button type="button" class="button button-small mmi-kv-remove mmi-taxonomy-field-remove" title="Remove">&times;</button>
            </div>`;
        },

        renderTaxonomyFieldRows: function(fields) {
            const self  = this;
            const $list = $('#mmi-taxonomy-fields-list');
            $list.empty();
            (fields || []).forEach(function(tf) {
                if (!tf) { return; }
                $list.append(self.taxonomyFieldRowHtml(tf.wc_taxonomy || '', tf.source_field || ''));
            });
        },

        /** Current rows → [{source_field, wc_taxonomy}], one per taxonomy, blanks skipped. */
        collectTaxonomyFields: function() {
            const out  = [];
            const seen = {};
            $('#mmi-taxonomy-fields-list .mmi-taxonomy-field-row').each(function() {
                const tax   = String($(this).find('.mmi-taxonomy-field-taxonomy').val() || '').trim();
                const field = String($(this).find('.mmi-taxonomy-field-source').val() || '').trim();
                if (!tax || !field || seen[tax]) { return; }
                seen[tax] = true;
                out.push({ source_field: field, wc_taxonomy: tax });
            });
            return out;
        },

        /**
         * "Suggest from feed": mmi_discover_taxonomy_candidates samples the
         * source's fetched feed for low-cardinality text fields (built
         * 2026-08-30 as a backend with no UI moment chosen; this is it).
         * Each candidate renders as an "Add" button that appends a row.
         */
        suggestTaxonomyFields: function(supplierId) {
            const self = this;
            const $out = $('#mmi-taxonomy-field-suggestions');
            const $btn = $('#mmi-suggest-taxonomy-fields-btn');
            const taxNonce = (window.mmiImportSettings && window.mmiImportSettings.taxmapNonce) || '';
            $btn.prop('disabled', true);
            $out.removeClass('mmi-is-hidden').html('<span class="dashicons dashicons-update mmi-spin"></span> Sampling the fetched feed…');
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: { action: 'mmi_discover_taxonomy_candidates', supplier_id: supplierId, nonce: taxNonce },
            }).done(function(response) {
                if (!response || !response.success) {
                    $out.html('<span class="dashicons dashicons-warning"></span> ' + escHtml((response && response.data && response.data.message) || 'Could not sample this source.'));
                    return;
                }
                const cands = response.data.candidates || [];
                if (!cands.length) {
                    $out.html('No brand- or category-shaped field found in <code>' + escHtml(response.data.file || 'the feed') + '</code> beyond those already declared or mapped.');
                    return;
                }
                let html = '<div>Likely taxonomy fields in <code>' + escHtml(response.data.file || '') + '</code> (sampled ' + escHtml(String(response.data.total_items || 0)) + ' records):</div>';
                cands.forEach(function(c) {
                    const samples = (c.sample_vals || []).slice(0, 3).join(', ');
                    html += `<span class="mmi-taxonomy-field-suggestion">
                        <button type="button" class="button button-small mmi-taxonomy-field-suggest-add"
                                data-path="${escAttr(c.path)}" data-taxonomy="${escAttr(c.suggested_taxonomy || '')}"
                                title="Add '${escAttr(c.path)}' as a taxonomy field">
                            <span class="dashicons dashicons-plus"></span> ${escHtml(c.path)}
                        </button>
                        <span class="mmi-suggestion-samples">${escHtml(String(c.unique_count))} values · ${escHtml(samples)}</span>
                    </span>`;
                });
                $out.html(html);
            }).fail(function(xhr) {
                $out.html('<span class="dashicons dashicons-warning"></span> Request failed: ' + escHtml(xhr.statusText || 'error'));
            }).always(function() {
                $btn.prop('disabled', false);
            });
        },

        /** Keep the Data Sources table's mapped-count tooltip naming the declared fields. */
        updateTaxonomyQuickLinkTitle: function(supplierId, fields) {
            const $link = $(`.mmi-taxmap-quick-link[data-taxmap-open-supplier="${supplierId}"]`);
            if (!$link.length) { return; }
            const taxonomies = (window.mmiImportSettings && window.mmiImportSettings.productTaxonomies) || [];
            const labelOf = function(slug) {
                const t = taxonomies.find(function(x) { return x.slug === slug; });
                return t ? t.label : slug;
            };
            const base  = String($link.attr('title') || '').split(/\s(?:Fields:|No taxonomy field declared)/)[0];
            const parts = (fields || []).map(function(tf) { return tf.source_field + ' → ' + labelOf(tf.wc_taxonomy); });
            $link.attr('title', base + (parts.length ? ' Fields: ' + parts.join(', ') + '.' : ' No taxonomy field declared for this source yet (Configure › Taxonomies).'));
        },

        // ─── Schedule Auto-Save (debounced) ─────────────────────────────

        scheduleAutoSave: function() {
            const self = this;
            // Note: jQuery .val(x) never fires input/change events, so programmatic
            // population during load cannot reach here. The _loadingConfig guard that
            // was previously here blocked legitimate user keystrokes typed before the
            // auth-fields AJAX completed. It has been removed.
            self._configDirty = true;
            if (self._autoSaveTimer) {
                clearTimeout(self._autoSaveTimer);
            }
            // Show a pending indicator
            $('#mmi-autosave-status').html('<span class="mmi-autosave-pending">Unsaved changes…</span>');
            self._autoSaveTimer = setTimeout(function() {
                self._autoSaveTimer = null;
                self.saveConfig();
            }, 800);
        },

        // ─── Validate Test Connection Button ────────────────────────────

        /**
         * Enable or disable the Test Connection button based on whether the
         * minimum required fields are present for the current source type and
         * authentication type.
         *
         * Rules:
         *  • Non-HTTP source types (upload, dropbox, gdrive): always disabled.
         *  • HTTP types (api, url): need a Base URL on the Connection tab OR at
         *    least one endpoint URL on the Endpoints tab.
         *  • Additionally, every [required] auth input must have a non-empty value
         *    (masked placeholder ••••••••  counts as filled).
         */
        // ─── Shared badge updater (keeps table row + status card in sync) ────

        /**
         * Update the status badge in both the supplier table row AND the
         * corresponding status card at the top of the page.
         *
         * @param {string} supplierId
         * @param {string} badgeClass  a shared .mmi-badge modifier, e.g. 'success' — or '' for the bare/neutral state
         * @param {string} badgeLabel  e.g. 'Ready'
         */
        updateBadge: function(supplierId, badgeClass, badgeLabel) {
            // .mmi-status-badge is kept as a selector hook only (no CSS of its own
            // anymore) — this row also carries a separate .mmi-source-type-badge,
            // so a bare .find('.mmi-badge') here would ambiguously match both once
            // that one is also converted, and .attr('class', ...) would clobber it too.
            $(`tr[data-supplier="${supplierId}"]`).find('.mmi-status-badge')
                .attr('class', 'mmi-badge mmi-status-badge ' + badgeClass)
                .text(badgeLabel);
        },

        // ─── Apply Source-Type-Specific Panels ─────────────────────────

        /**
         * Show/hide Connection tab panels and tabs based on the source type.
         * Called once when the config modal opens (source type is locked after creation).
         *
         * @param {string} sourceType  api | url | upload | dropbox | gdrive
         */
        applySourceTypePanels: function(sourceType) {
            const isHttp = (sourceType === 'api' || sourceType === 'url');

            // Source-specific panels: show only the matching one
            $('.mmi-source-panel').addClass('mmi-is-hidden');
            if (!isHttp && sourceType) {
                $('[data-source-panel="' + sourceType + '"]').removeClass('mmi-is-hidden');
            }

            // HTTP-only fields (Base URL, Documentation URL)
            $('.mmi-field-http-only').toggle(isHttp);

            // HTTP-only tabs (Authentication, Endpoints)
            if (isHttp) {
                $('.mmi-tab-http-only').show();
            } else {
                $('.mmi-tab-http-only').hide();
                // If the currently active tab is an HTTP-only tab, jump to Connection
                if ($('.mmi-config-tab.active').hasClass('mmi-tab-http-only')) {
                    $('.mmi-config-tab').removeClass('active');
                    $('.mmi-config-tab[data-tab="connection"]').addClass('active');
                    $('.mmi-config-tab-content').removeClass('active');
                    $('.mmi-config-tab-content[data-tab="connection"]').addClass('active');
                }
            }

            // Update test button label
            const $testBtn = $('#mmi-test-connection-btn');
            if (sourceType === 'upload') {
                $testBtn.html('<span class="dashicons dashicons-media-text"></span> Verify File');
            } else {
                $testBtn.html('<span class="dashicons dashicons-admin-links"></span> Test Connection');
            }
        },

        validateTestConnectionBtn: function() {
            const $btn = $('#mmi-test-connection-btn');
            if (!$btn.length) return;

            const sourceType = $('#cfg-source-type').val() || 'api';

            // Non-HTTP source types have per-type enabling rules
            if (sourceType === 'upload') {
                var hasAttachment = !!$('#cfg-upload-attachment-id').val();
                $btn.prop('disabled', !hasAttachment)
                    .attr('title', hasAttachment ? '' : 'Choose a file first.');
                return;
            }
            if (sourceType === 'dropbox') {
                var hasDbPath  = !!$('#cfg-dropbox-file-path').val().trim();
                var hasDbToken = !!$('#cfg-dropbox-access-token').val().trim();
                var dbReady    = hasDbPath && hasDbToken;
                $btn.prop('disabled', !dbReady)
                    .attr('title', dbReady ? '' : 'Enter Dropbox file path and access token.');
                return;
            }
            if (sourceType === 'gdrive') {
                var hasGdFileId = !!$('#cfg-gdrive-file-id').val().trim();
                $btn.prop('disabled', !hasGdFileId)
                    .attr('title', hasGdFileId ? '' : 'Enter a Google Drive file ID.');
                return;
            }

            // Must have Base URL or at least one endpoint URL.
            // Exception: preconfigured integrations manage their URLs server-side, so skip this check.
            const isPreconfigured = $('#mmi-preconfigured-lock-banner').length > 0;
            if (!isPreconfigured) {
                const hasBaseUrl = !!$('#cfg-base-url').val().trim();
                let hasEndpointUrl = false;
                $('#mmi-endpoints-list .ep-url').each(function() {
                    if ($(this).val().trim()) { hasEndpointUrl = true; return false; }
                });

                if (!hasBaseUrl && !hasEndpointUrl) {
                    $btn.prop('disabled', true)
                        .attr('title', 'Enter a Base URL on the Connection tab, or add an Endpoint URL.');
                    return;
                }
            }

            // All required auth fields must be filled (any bullet-mask counts as filled)
            let allAuthFilled = true;
            $('#mmi-auth-fields-container .mmi-auth-input[required]').each(function() {
                const val = $(this).val();
                const isMasked = val.length > 0 && /^[\u2022]+$/.test(val);
                if (!val || (!isMasked && !val.trim())) {
                    allAuthFilled = false;
                    return false; // break
                }
            });

            if (!allAuthFilled) {
                $btn.prop('disabled', true)
                    .attr('title', 'Fill in all required authentication fields before testing.');
                return;
            }

            $btn.prop('disabled', false).attr('title', '');
        }

        // ─── Test Connection ────────────────────────────────────────────

    });

    // ─── Taxonomies tab buttons ─────────────────────────────────────────
    // Delegated like the modal's other row controls (import-pipeline.js).
    // Adding/removing a row changes nothing until a field is filled, so
    // only the remove, suggestion-add and reset paths trigger the modal's
    // debounced autosave explicitly; typing in a row already does.
    $(document).on('click', '#mmi-add-taxonomy-field-btn', function() {
        $('#mmi-taxonomy-fields-list').append(window.MMIDataPipeline.taxonomyFieldRowHtml('', ''));
        $('#mmi-taxonomy-fields-list .mmi-taxonomy-field-row:last .mmi-taxonomy-field-taxonomy').trigger('focus');
    });
    $(document).on('click', '.mmi-taxonomy-field-remove', function() {
        $(this).closest('.mmi-taxonomy-field-row').remove();
        window.MMIDataPipeline.scheduleAutoSave();
    });
    $(document).on('click', '#mmi-suggest-taxonomy-fields-btn', function() {
        window.MMIDataPipeline.suggestTaxonomyFields($('#mmi-config-supplier-id').val());
    });
    $(document).on('click', '.mmi-taxonomy-field-suggest-add', function() {
        const path     = $(this).data('path');
        const taxonomy = $(this).data('taxonomy') || '';
        $('#mmi-taxonomy-fields-list').append(window.MMIDataPipeline.taxonomyFieldRowHtml(taxonomy, path));
        $(this).prop('disabled', true);
        if (taxonomy) {
            window.MMIDataPipeline.scheduleAutoSave();
        } else {
            $('#mmi-taxonomy-fields-list .mmi-taxonomy-field-row:last .mmi-taxonomy-field-taxonomy').trigger('focus');
        }
    });
    $(document).on('click', '#mmi-reset-taxonomy-fields-btn', function() {
        const tplFields = window.MMIDataPipeline._taxonomyTemplateFields || [];
        window.MMIDataPipeline.renderTaxonomyFieldRows(tplFields);
        window.MMIDataPipeline.scheduleAutoSave();
    });

})(jQuery);
