/**
 * Import Pipeline — Data Sources
 * Extends window.MMIDataPipeline with methods for this concern.
 */
(function($) {
    'use strict';

    const ajaxUrl = (window.mmiImportSettings && window.mmiImportSettings.ajaxurl)
                    || (window.mmiGlobal && window.mmiGlobal.ajaxUrl)
                    || window.ajaxurl
                    || '/wp-admin/admin-ajax.php';
    const nonce = (window.mmiImportSettings && window.mmiImportSettings.nonce) || '';

    function escAttr(val) { return $('<div>').text(val || '').html().replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
    const preconfiguredNames = (window.MMIDataPipeline && window.MMIDataPipeline._preconfiguredNames) || {};

    /* ── DOM Selectors ──────────────────────────────────────────────── */
    const SELECTORS = {
        suppliersWithAttr:    'tr[data-supplier]',
        supplierNameCell:     '.mmi-supplier-name-cell strong',
        enabledSuppliersInfo: '.mmi-enabled-suppliers-info',
        addSourceType:        '#add-source-type',
        addApiIntegration:    '#add-api-integration',
        addSourceConfirmBtn:  '#mmi-add-source-confirm-btn',
        addSourceModal:       '#mmi-add-source-modal',
        supplierTableEmpty:   '#mmi-supplier-table .mmi-empty-row',
        supplierTableBody:    '#mmi-supplier-table tbody',
        noSourcesNotice:      '.mmi-no-sources-notice',
        fieldMappingTable:    '.mmi-field-mapping-table',
        supplierSources:      '.mmi-field-mapping-table .supplier-sources',
        fieldGroupHeader:     '.field-group-header',
        groupSourceCell:      'th.col-source-field',
    };

    Object.assign(window.MMIDataPipeline, {
        /**
         * Reflect a source's Test Connection success client-side — a source
         * is enabled the moment it's validated, with no separate manual
         * toggle step anymore (see AGENTS.md's "Enabled Toggle Eliminated"
         * entry; the backend already persists config_status/enabled together
         * for every success path in DataSourceController.php's
         * mmi_test_data_source handler). Called from the Test Connection
         * success handlers (import-pipeline-test.js, import-pipeline-config.js)
         * in place of the old toggleSupplier() AJAX round-trip.
         */
        markSupplierValidated: function(supplierId) {
            const self = this;
            const $row = $(`tr[data-supplier="${supplierId}"]`);
            $row.attr('data-enabled', '1').attr('data-config-status', 'validated');

            // Keep the Fetch Data panel's own checkbox for this source in
            // sync — it carries its own copy of these two attributes (it's
            // not inside the row anymore, see the 2026-08-31 selection
            // redesign), so nothing else updates it automatically. Set via
            // .data(), not .attr() — product-import.js reads these back with
            // .data(), and jQuery's .data() cache doesn't see a later
            // .attr() write once it's been read once, which .attr() here
            // would risk if the Fetch Data panel was ever opened first.
            $(`.mmi-fetch-source-checkbox[value="${supplierId}"]`)
                .attr('data-enabled', '1').attr('data-config-status', 'validated')
                .data('enabled', '1').data('config-status', 'validated');

            const bm = (mmiImportSettings && mmiImportSettings.badgeMap) || {};
            const e = bm['enabled'] || { class: 'success', label: 'Active' };
            self.updateBadge(supplierId, e.class, e.label);

            // Keep mmiImportSettings.configuredSuppliers in sync so that
            // syncFieldMappingSuppliers() reads the correct enabled state.
            if (window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) {
                window.mmiImportSettings.configuredSuppliers =
                    window.mmiImportSettings.configuredSuppliers.map(function(s) {
                        return s.supplier_id === supplierId
                            ? Object.assign({}, s, { enabled: true, config_status: 'validated' })
                            : s;
                    });
            }

            // Update already-injected field-mapping rows so the
            // supplier-source-inactive class and status dot reflect reality.
            self.refreshFieldMappingSupplierState(supplierId, true);
            self.syncEnabledSuppliersInfo();

            // Update Data Fetch detail cell in topbar schedule panel.
            const $detailCell = $('#mmi-fetch-detail');
            if ( $detailCell.length ) {
                const cs = (window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) || [];
                const activeNames = cs.filter(function(s) { return s.enabled; }).map(function(s) { return s.supplier_name; });
                if ( activeNames.length ) {
                    $detailCell.text( activeNames.join(', ') );
                } else {
                    $detailCell.html('<span class="mmi-tsched-no-suppliers">No suppliers enabled</span>');
                }
            }
        },

        /**
         * Refresh one supplier row's Last Fetch + Records cells from the
         * server, so a manual fetch (whichever of this plugin's several
         * fetch entry points triggered it — Fetch Selected, the Preview
         * Data modal's on-demand "Fetch Now", a dynamic/upload source
         * refresh) is reflected immediately instead of requiring a page
         * reload. Deliberately calls SourceFetchController's
         * mmi_pipeline_source_status endpoint — the one place in this
         * plugin that derives freshness from the cached feed file's own
         * mtime rather than a stored timestamp a no-op run could have
         * written without changing anything (see that controller's own
         * docblock, and AGENTS.md's "Pipeline Stale Feed Detection"
         * incident) — rather than re-deriving a second, potentially
         * divergent notion of "how fresh is this source" here.
         */
        refreshLastFetchCell: function(supplierId, filename) {
            const self = this;
            $.post(ajaxUrl, {
                action:      'mmi_pipeline_source_status',
                nonce:       nonce,
                supplier_id: supplierId,
                filename:    filename || (supplierId + '-products.json'),
            }).done(function(response) {
                if (!response || !response.success || !response.data) return;
                self.applySourceStatusToRow(supplierId, response.data);
            });
        },

        /**
         * Apply an already-fetched mmi_pipeline_source_status payload to a
         * supplier row's Last Fetch + Records cells. Split out from
         * refreshLastFetchCell() so a caller that already polls this same
         * endpoint for its own purposes (import-pipeline.js's
         * pollSourceFetch(), driving the Preview Data modal's "Fetch Now")
         * can update the main table from that one response instead of
         * firing a second, redundant request for the same data.
         */
        applySourceStatusToRow: function(supplierId, data) {
            const $row = $(`tr[data-supplier="${supplierId}"]`);
            if (!$row.length || !data) return;

            const $fetchCell = $row.find('.mmi-last-fetch-cell');
            if ($fetchCell.length && data.fetched_ago) {
                const label = data.source_type === 'upload'
                    ? 'Uploaded ' + data.fetched_ago
                    : data.fetched_ago;
                const staleHtml = data.is_stale
                    ? ` <span class="mmi-stale-badge mmi-badge warning" title="This source's data has not been refreshed in ${escAttr(Math.round(data.age_hours))} hours. Run a Data Fetch before importing — an import now would run against stale data.">⚠ Stale</span>`
                    : '';
                $fetchCell
                    .toggleClass('mmi-fetch-stale', !!data.is_stale)
                    .html(escAttr(label) + staleHtml);
            }

            const $countCell = $row.find('.mmi-record-count-cell');
            if ($countCell.length && data.record_count !== null && data.record_count !== undefined) {
                $countCell.html(
                    data.record_count > 0
                        ? escAttr(Number(data.record_count).toLocaleString())
                        : '<span class="status-unavailable">—</span>'
                );
            }
        },

        /**
         * Mark one supplier row as the active selection and sync the
         * toolbar's Configure/Preview Data/Delete buttons to it (2026-08-31)
         * — mirrors initProfileCardsGrid()'s activateCard() in
         * import-pipeline.js exactly: those three actions used to be
         * per-row buttons inside #mmi-supplier-table; they're single
         * toolbar buttons now, acting on whichever row is active, the same
         * way Edit/Duplicate/Delete in .mmi-profile-section-bar act on
         * whichever profile card is active. See the row click handler and
         * the three button click handlers in import-pipeline.js.
         */
        activateSupplierRow: function(supplierId) {
            const escapedId = CSS.escape ? CSS.escape(supplierId) : supplierId;
            const $table = $('#mmi-supplier-table');
            $table.find('.mmi-supplier-row').removeClass('is-active').attr('aria-pressed', 'false');
            const $row = $table.find('.mmi-supplier-row[data-supplier="' + escapedId + '"]');
            $row.addClass('is-active').attr('aria-pressed', 'true');

            const hasRow = $row.length > 0;
            const cfgStatus = $row.data('config-status') || 'unconfigured';
            $('#mmi-configure-source-btn')
                .prop('disabled', !hasRow)
                .data('supplier', supplierId)
                .html('<span class="dashicons dashicons-admin-generic"></span> ' + (cfgStatus === 'unconfigured' ? 'Set Up' : 'Configure'));
            $('#mmi-preview-source-btn')
                .prop('disabled', !hasRow)
                .data('supplier', supplierId)
                .data('supplier-name', $row.data('supplier-name') || supplierId);
            $('#mmi-delete-source-btn')
                .prop('disabled', !hasRow)
                .data('supplier', supplierId);
        },

        /**
         * Persist the per-source Taxonomy Mapping toggle (2026-08-31) — see
         * DataSourceController.php's mmi_toggle_source_taxonomy_mapping
         * handler and MMI_Pipeline_Admin::get_configured_suppliers()'s
         * matching 'taxonomy_mapping_enabled' key. $toggle is the checkbox
         * itself, reverted on failure so the UI never shows a state that
         * didn't actually save.
         */
        toggleSourceTaxonomyMapping: function(supplierId, enabled, $toggle) {
            const self = this;
            $.post(ajaxUrl, {
                action:      'mmi_toggle_source_taxonomy_mapping',
                nonce:       nonce,
                supplier_id: supplierId,
                enabled:     enabled ? '1' : '',
            }).done(function(response) {
                if (!response.success) {
                    $toggle.prop('checked', !enabled);
                    self.showNotification((response.data && response.data.message) || 'Failed to save', 'error');
                }
            }).fail(function() {
                $toggle.prop('checked', !enabled);
                self.showNotification('Failed to save', 'error');
            });
        },

        // ─── Field Mapping — live supplier state refresh ──────────────────────────────

        /**
         * Update already-injected field-mapping rows when a supplier's enabled
         * state changes, without needing a full re-inject.
         */
        refreshFieldMappingSupplierState: function(supplierId, enabled) {
            const cfgStatus = (function() {
                const suppliers = (window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) || [];
                const s = suppliers.find(function(s) { return s.supplier_id === supplierId; });
                return (s && s.config_status) || 'unconfigured';
            })();

            // supplier-source-row inactive class, plus its col-source-file
            // counterpart (.supplier-file-row) — a separate <td> since the
            // file/source columns split, so it needs the identical toggle
            // applied a second time rather than being covered by the line above.
            $('.mmi-field-mapping-table .supplier-source-row[data-supplier="' + supplierId + '"]')
                .toggleClass('supplier-source-inactive', !enabled);
            $('.mmi-field-mapping-table .supplier-file-row[data-supplier="' + supplierId + '"]')
                .toggleClass('supplier-source-inactive', !enabled);

            // Group header label(s) inactive class — every group that has a
            // toggle for this supplier, not just one (there's no longer a
            // single table-wide header).
            $('.group-supplier-toggle-label[data-supplier="' + supplierId + '"]')
                .toggleClass('supplier-inactive', !enabled);

            if (enabled) {
                // Remove status dot — active suppliers don't show one
                $('.supplier-source-row[data-supplier="' + supplierId + '"] .supplier-status-dot').remove();
                $('.supplier-file-row[data-supplier="' + supplierId + '"] .supplier-status-dot').remove();
                $('.group-supplier-toggle-label[data-supplier="' + supplierId + '"] .supplier-status-dot').remove();
            } else {
                // Re-add dot where missing
                const dotHtml = '<span class="supplier-status-dot supplier-status-' + escAttr(cfgStatus) +
                    '" title="Source is ' + escAttr(cfgStatus) + ' — not currently active">●</span>';
                $('.supplier-source-row[data-supplier="' + supplierId + '"] .supplier-label').each(function() {
                    if (!$(this).find('.supplier-status-dot').length) $(this).append(dotHtml);
                });
                $('.supplier-file-row[data-supplier="' + supplierId + '"] .mmi-file-supplier-label').each(function() {
                    if (!$(this).find('.supplier-status-dot').length) $(this).append(dotHtml);
                });
                $('.group-supplier-toggle-label[data-supplier="' + supplierId + '"]').each(function () {
                    if (!$(this).find('.supplier-status-dot').length) $(this).append(dotHtml);
                });
            }
            if (window.mmiUpdateFieldMappingSingleSourceState) { window.mmiUpdateFieldMappingSingleSourceState(); }
        },

        // ─── Enabled Suppliers Info Banner ────────────────────────────────
        syncEnabledSuppliersInfo: function() {
            const enabledNames = [];
            $(SELECTORS.suppliersWithAttr).each(function() {
                if ($(this).attr('data-enabled') === '1') {
                    const name = $(this).find(SELECTORS.supplierNameCell).text().trim()
                                 || $(this).attr('data-supplier');
                    if (name) enabledNames.push(name);
                }
            });

            const $info = $(SELECTORS.enabledSuppliersInfo);
            if (enabledNames.length > 0) {
                $info.removeClass('warning')
                     .html(`<span class="dashicons dashicons-info"></span> Currently enabled: <strong>${enabledNames.map(escAttr).join(', ')}</strong>`);
            } else {
                $info.addClass('warning')
                     .html('<span class="dashicons dashicons-warning"></span> <strong>No suppliers enabled</strong> \u2014 Schedule will not run');
            }
        },

        // ─── Add Data Source ────────────────────────────────────────────

        addDataSource: function() {
            const self = this;
            const type = $(SELECTORS.addSourceType).val() || 'upload';
            const isApi = (type === 'api');

            let sid, supplierName, template = '', fileFormat = '';
            const extraData = {};

            if (isApi) {
                // API — must have a preconfigured integration selected
                template     = $(SELECTORS.addApiIntegration).val();
                if (!template) {
                    self.showNotification('Please select an API integration', 'error');
                    return;
                }
                sid          = template;
                supplierName = preconfiguredNames[template] || template;

            } else {
                // Non-API — read from type-specific panel
                switch (type) {
                    case 'upload':
                        supplierName           = $('#add-supplier-name').val().trim();
                        fileFormat             = $('#add-upload-detected-format').val() || 'csv';
                        extraData.upload_attachment_id = $('#add-upload-attachment-id').val();
                        break;
                    case 'url':
                        supplierName           = $('#add-url-supplier-name').val().trim();
                        extraData.base_url     = $('#add-url-input').val().trim();
                        break;
                    case 'dropbox':
                        supplierName                   = $('#add-dropbox-supplier-name').val().trim();
                        extraData.dropbox_file_path    = $('#add-dropbox-path').val().trim();
                        extraData.dropbox_access_token = $('#add-dropbox-token').val();
                        break;
                    case 'gdrive':
                        supplierName           = $('#add-gdrive-supplier-name').val().trim();
                        const rawGd            = $('#add-gdrive-input').val().trim();
                        extraData.gdrive_file_id = self.extractGDriveFileId ? self.extractGDriveFileId(rawGd) : rawGd;
                        break;
                }

                if (!supplierName) {
                    self.showNotification('Supplier name is required', 'error');
                    return;
                }
                if (!fileFormat) { fileFormat = 'json'; }

                // Slugify supplier name → supplier_id
                sid = supplierName
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '')
                    .substring(0, 50);
                if (!sid) {
                    self.showNotification('Could not generate a valid supplier ID from the name', 'error');
                    return;
                }
            }

            const $btn = $(SELECTORS.addSourceConfirmBtn);
            $btn.prop('disabled', true);

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: Object.assign({
                    action:        'mmi_add_data_source',
                    supplier_id:   sid,
                    supplier_name: supplierName,
                    source_type:   type,
                    template:      template,
                    file_format:   fileFormat,
                    nonce:         nonce,
                }, extraData),
                success: function(response) {
                    if (response.success) {
                        self.showNotification('Data source created! Opening configuration...', 'success');
                        self.closeModal(SELECTORS.addSourceModal);

                        // Add new row to table matching the redesigned PHP structure
                        const src = response.data.source;
                        const typeLabels = { api: 'API', url: 'Direct URL', upload: 'Upload', dropbox: 'Dropbox', gdrive: 'Google Drive' };
                        const typeLabel = typeLabels[src.source_type] || src.source_type;

                        // Remove empty-state row if present
                        $(SELECTORS.supplierTableEmpty).remove();

                        // Configure/Preview Data/Delete moved to the toolbar (2026-08-31),
                        // acting on whichever row is active — see activateSupplierRow()
                        // and the click handlers in import-pipeline.js. A freshly-added
                        // source is activated immediately below so those toolbar buttons
                        // are usable right away without an extra click.
                        const newRow = `
                        <tr class="mmi-supplier-row mmi-uniform-row--selectable" data-supplier="${escAttr(src.supplier_id)}" data-supplier-name="${escAttr(src.supplier_name)}" data-config-status="unconfigured" data-enabled="0" role="button" tabindex="0" aria-pressed="false">
                            <td class="mmi-supplier-name-cell"><strong>${escAttr(src.supplier_name)}</strong></td>
                            <td>
                                <span class="mmi-source-type-badge mmi-source-${escAttr(src.source_type)}">${escAttr(typeLabel)}</span>
                            </td>
                            <td>
                                <span class="mmi-badge mmi-status-badge">Needs Setup</span>
                            </td>
                            <td class="mmi-taxmap-toggle-cell">
                                <label class="mmi-toggle-switch" title="Resolve this source's brand/category values through Taxonomy Mapping's alias table">
                                    <input type="checkbox" class="mmi-source-taxmap-toggle" data-supplier="${escAttr(src.supplier_id)}" checked>
                                    <span class="mmi-toggle-slider"></span>
                                </label>
                                <button type="button" class="mmi-taxmap-quick-link is-unmapped" data-taxmap-open-supplier="${escAttr(src.supplier_id)}" data-taxmap-supplier-name="${escAttr(src.supplier_name)}" title="No taxonomy mapping set up for this source yet — click to assign one">
                                    <span class="dashicons dashicons-warning"></span> Not mapped
                                </button>
                            </td>
                            <td class="mmi-last-fetch-cell">—</td>
                            <td class="mmi-record-count-cell"><span class="status-unavailable">—</span></td>
                        </tr>`;

                        $(SELECTORS.supplierTableBody).append(newRow);
                        if (window.MMIDataPipeline && typeof window.MMIDataPipeline.activateSupplierRow === 'function') {
                            window.MMIDataPipeline.activateSupplierRow(src.supplier_id);
                        }

                        // Add a matching entry to the Fetch Data panel's own
                        // checklist (2026-08-31) — that panel, not the table,
                        // is now the single owner of "which sources to fetch."
                        const newFetchOption = `<label class="mmi-fetch-source-option">
                            <input type="checkbox" class="mmi-fetch-source-checkbox" value="${escAttr(src.supplier_id)}" data-config-status="unconfigured" data-enabled="0" data-source-type="${escAttr(src.source_type)}">
                            ${escAttr(src.supplier_name)}
                        </label>`;
                        $('.mmi-fetch-source-list').append(newFetchOption);

                        // Inject the new supplier into the field-mapping panel
                        const knownFiles = self._knownSupplierFiles();
                        const fileOptions = knownFiles[src.supplier_id] || { [src.supplier_id + '-products.json']: 'Products' };
                        // Declared taxonomy fields: a template source's row is
                        // seeded with them server-side (mmi_ds_complete_template_setup),
                        // a custom/uploaded source starts with none.
                        const newTaxonomyFields = (src.configuration && Array.isArray(src.configuration.taxonomy_fields))
                            ? src.configuration.taxonomy_fields
                            : [];
                        self.injectSupplierToFieldMapping(src.supplier_id, src.supplier_name, false, 'unconfigured', fileOptions, newTaxonomyFields);

                        // Keep configuredSuppliers list in sync so future syncFieldMappingSuppliers is accurate
                        if (window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) {
                            const exists = window.mmiImportSettings.configuredSuppliers.some(s => s.supplier_id === src.supplier_id);
                            if (!exists) {
                                window.mmiImportSettings.configuredSuppliers.push({
                                    supplier_id:    src.supplier_id,
                                    supplier_name:  src.supplier_name,
                                    enabled:        false,
                                    config_status:  'unconfigured',
                                    source_type:    src.source_type,
                                    fileOptions:    fileOptions,
                                    taxonomyFields: newTaxonomyFields,
                                });
                            }
                        }

                        // If this came from the wizard's "+ Add a Data Source"
                        // handoff, add the same checkable row (Step 1) + PK
                        // editor row (Step 2) so the new source is there to
                        // select once the user is back in the wizard —
                        // otherwise it would only show up on the main Data
                        // Sources tab, invisible from inside the wizard until
                        // the whole section is reopened separately.
                        if (window._mmiWizardPendingSourceHandoff && window.MMIDataPipeline.buildWizardSourceRow) {
                            const wizardRows = window.MMIDataPipeline.buildWizardSourceRow(src.supplier_id, src.supplier_name);
                            $('#new-profile-sources-list').append(wizardRows.$checklistRow);
                            $('#new-profile-pk-editors-list').append(wizardRows.$pkEditorRow);
                            wizardRows.$checklistRow.find('.np-source-check').trigger('change');
                        }

                        // Open config modal for the new source
                        setTimeout(function() { self.openConfigModal(src.supplier_id); }, 300);
                    } else {
                        self.showNotification(response.data?.message || 'Failed to create data source', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Add data source error:', { xhr, status, error });
                    self.showNotification('Server error: ' + (error || 'Unknown error'), 'error');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        },

        // ─── Delete Data Source ──────────────────────────────────────────

        deleteDataSource: function(supplierId) {
            const self = this;

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                data: {
                    action:      'mmi_delete_data_source',
                    supplier_id: supplierId,
                    nonce:       nonce,
                },
                success: function(response) {
                    if (response.success) {
                        const wasActive = $(`tr[data-supplier="${supplierId}"]`).hasClass('is-active');
                        $(`tr[data-supplier="${supplierId}"]`).fadeOut(300, function() {
                            $(this).remove();
                            // The toolbar's Configure/Preview Data/Delete buttons
                            // were pointed at this now-deleted row — hand them to
                            // whatever's left, or disable them if nothing is.
                            if (wasActive) {
                                const $next = $('#mmi-supplier-table .mmi-supplier-row').first();
                                if ($next.length) {
                                    self.activateSupplierRow($next.data('supplier'));
                                } else {
                                    $('#mmi-configure-source-btn, #mmi-preview-source-btn, #mmi-delete-source-btn').prop('disabled', true);
                                    $(SELECTORS.supplierTableBody).append(
                                        '<tr class="mmi-empty-row"><td colspan="6"><div class="mmi-empty-state">' +
                                        '<span class="dashicons dashicons-database"></span>' +
                                        '<p>No data sources yet. Click <strong>+ Add Data Source</strong> to get started.</p>' +
                                        '</div></td></tr>'
                                    );
                                }
                            }
                        });
                        // Remove supplier column from field-mapping panel
                        self.removeSupplierFromFieldMapping(supplierId);
                        // Keep configuredSuppliers list in sync
                        if (window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) {
                            window.mmiImportSettings.configuredSuppliers =
                                window.mmiImportSettings.configuredSuppliers.filter(s => s.supplier_id !== supplierId);
                        }
                        self.showNotification('Data source deleted', 'success');
                    } else {
                        self.showNotification(response.data?.message || 'Failed to delete', 'error');
                    }
                },
                error: function() {
                    self.showNotification('Server error', 'error');
                }
            });
        },

        // ─── Legacy: Save / Test Data Source (kept for backward compat) ─

        saveDataSource: function(supplier) {
            // Redirect old callers to open the new modal
            this.openConfigModal(supplier);
        },

        testDataSource: function(supplier) {
            this.openConfigModal(supplier);
        },

        // ─── Field Mapping panel — supplier column sync ─────────────────

        /**
         * Known JSON file options per built-in supplier_id — reads the
         * canonical map localized from PHP (MMI_Pipeline_Admin::
         * known_supplier_json_files()) instead of a second, hand-maintained
         * copy. A hardcoded copy here previously drifted silently out of
         * sync with the PHP source (missing xchange-web-assets.json even
         * after that file existed and was already offered by the
         * server-rendered selectors) despite its own comment claiming to
         * mirror it — this getter can't drift, since there's nothing left to
         * keep in sync by hand.
         */
        _knownSupplierFiles: function() {
            return (window.mmiImportSettings && window.mmiImportSettings.knownSupplierFiles) || {};
        },

        /**
         * Inject a supplier column into every field-mapping row.
         * Safe to call multiple times — skips rows that already have this supplier.
         */
        injectSupplierToFieldMapping: function(sid, supplierName, enabled, cfgStatus, fileOptions, taxonomyFields) {
            const self    = this;
            const esc     = escAttr;
            const rowCls  = enabled ? '' : ' supplier-source-inactive';

            // product_brand / product_cat have no editable source row — they
            // get an "Enable for X" toggle per source that declares a field
            // for that taxonomy (see panel-field-mapping.php). Synced here so
            // a source added or edited without a reload shows up there too.
            self.syncSupplierTaxonomyToggles(sid, supplierName, taxonomyFields || []);
            const dotHtml = !enabled
                ? `<span class="supplier-status-dot supplier-status-${esc(cfgStatus || 'unconfigured')}" title="Source is ${esc(cfgStatus || 'unconfigured')} — not currently active">●</span>`
                : '';

            // ── Per-group header toggles ───────────────────────────────
            // Only groups that already contain at least one multi-supplier
            // field (a .supplier-sources row) get a toggle for this new
            // supplier — mirrors $group_has_multi_supplier in
            // panel-field-mapping.php. There's no single table-wide header
            // toggle anymore; each field-group-header gets its own.
            $(SELECTORS.fieldGroupHeader).each(function () {
                const $header = $(this);
                const $rows   = $header.nextUntil(SELECTORS.fieldGroupHeader, 'tr');
                if (!$rows.find('.supplier-sources').length) return;

                const $cell = $header.find(SELECTORS.groupSourceCell);
                if ($cell.find(`.group-supplier-toggle[data-supplier="${sid}"]`).length) return;

                $cell.append(
                    `<label class="group-supplier-toggle-label${enabled ? '' : ' supplier-inactive'}" data-supplier="${esc(sid)}">
                        <span class="mmi-toggle-switch">
                            <input type="checkbox" class="group-supplier-toggle"
                                   data-supplier="${esc(sid)}"
                                   data-group="${esc($header.data('group') || '')}"
                                   title="Enable/disable ${esc(supplierName)} for this group">
                            <span class="mmi-toggle-slider"></span>
                        </span>
                        ${esc(supplierName)}${dotHtml}
                    </label>`
                );
            });

            // ── Source row in every field ──────────────────────────────
            $(SELECTORS.supplierSources).each(function() {
                const $sources  = $(this);
                const fieldName = $sources.data('field');
                // The file selector renders in its own col-source-file column
                // now — a sibling <td>'s .supplier-files, not stacked inside
                // this row's own .field-selector-group — see
                // panel-field-mapping.php's / addCustomFieldRow()'s matching
                // two-column split. Both cells share the same <tr> and the
                // same data-field, which is the join used here.
                const $files = $sources.closest('tr').find(`.supplier-files[data-field="${fieldName}"]`);

                // Skip if already present
                if ($sources.find(`.supplier-source-row[data-supplier="${sid}"]`).length > 0) return;

                // Build file selector — only render a real <select> when
                // there's an actual choice between 2+ files; a single-file
                // source carries its one filename as a hidden field instead,
                // so the source-field input below isn't gated behind
                // selecting from a dropdown with only one option (same
                // pattern as panel-field-mapping.php / addCustomFieldRow()).
                const fileKeysForSelector = fileOptions ? Object.keys(fileOptions) : [];
                let fileSelectorHtml = '';
                if (fileKeysForSelector.length > 1) {
                    let opts = `<option value="">Select file...</option>`;
                    for (const [val, lbl] of Object.entries(fileOptions)) {
                        opts += `<option value="${esc(val)}">${esc(lbl)}</option>`;
                    }
                    fileSelectorHtml = `<select class="field-file-selector"
                        name="field_mappings[${esc(fieldName)}][file][${esc(sid)}]"
                        data-supplier="${esc(sid)}"
                        data-field="${esc(fieldName)}">${opts}</select>`;
                } else if (fileKeysForSelector.length === 1) {
                    const onlyFileVal = fileKeysForSelector[0];
                    fileSelectorHtml = `<input type="hidden" class="field-file-selector"
                        name="field_mappings[${esc(fieldName)}][file][${esc(sid)}]"
                        data-supplier="${esc(sid)}"
                        data-field="${esc(fieldName)}"
                        value="${esc(onlyFileVal)}">
                        <span class="mmi-file-single-notice">${esc(fileOptions[onlyFileVal])}</span>`;
                } else {
                    fileSelectorHtml = `<span class="mmi-file-single-notice">—</span>`;
                }

                if ($files.length) {
                    $files.append(
                        `<div class="supplier-file-row mmi-label-grid-row${rowCls}" data-supplier="${esc(sid)}">
                            <span class="supplier-label mmi-file-supplier-label">${esc(supplierName.toUpperCase())}:</span>
                            <div class="mmi-label-grid-value">${fileSelectorHtml}</div>
                        </div>`
                    );
                }

                $sources.append(
                    `<div class="supplier-source-row mmi-label-grid-row${rowCls}" data-supplier="${esc(sid)}">
                        <div class="supplier-enable-wrapper">
                            <span class="mmi-supplier-mapped-dot is-unmapped" title="Not mapped"></span>
                            <span class="supplier-label">
                                ${esc(supplierName.toUpperCase())}:${dotHtml}
                            </span>
                        </div>
                        <div class="field-selector-group">
                            <input type="text"
                                   name="field_mappings[${esc(fieldName)}][source][${esc(sid)}]"
                                   value=""
                                   class="field-source-${esc(sid)}"
                                   placeholder="e.g., field_name, path.to.field">
                        </div>
                    </div>`
                );
            });

            if (window.mmiInitFieldFileSelectors) {
                window.mmiInitFieldFileSelectors($(`.mmi-field-mapping-table .field-file-selector[data-supplier="${sid}"]`));
            }
            if (window.mmiUpdateFieldMappingSingleSourceState) { window.mmiUpdateFieldMappingSingleSourceState(); }
        },

        /** Remove a supplier's column from every field-mapping row and header. */
        removeSupplierFromFieldMapping: function(supplierId) {
            $(`.mmi-field-mapping-table .supplier-source-row[data-supplier="${supplierId}"]`).remove();
            // col-source-file's own row for this supplier — a separate <td>
            // from .supplier-source-row above since the file/source columns
            // split, so it needs its own removal, not covered by the line above.
            $(`.mmi-field-mapping-table .supplier-file-row[data-supplier="${supplierId}"]`).remove();
            $(`.group-supplier-toggle-label[data-supplier="${supplierId}"]`).remove();
            if (window.mmiUpdateFieldMappingSingleSourceState) { window.mmiUpdateFieldMappingSingleSourceState(); }
        },

        /**
         * Sync field-mapping supplier columns against the current configuredSuppliers list.
         * Designed to be called when the field-mapping panel opens so it catches any
         * suppliers added/changed since the page was first rendered.
         */
        syncFieldMappingSuppliers: function() {
            const self      = this;
            const suppliers = (window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) || [];
            suppliers.forEach(function(s) {
                const fileOptions = (s.fileOptions && Object.keys(s.fileOptions).length)
                    ? s.fileOptions
                    : (self._knownSupplierFiles()[s.supplier_id] || { [s.supplier_id + '-products.json']: 'Products' });
                self.injectSupplierToFieldMapping(
                    s.supplier_id, s.supplier_name, s.enabled, s.config_status, fileOptions, s.taxonomyFields || []
                );
            });
        },

        /**
         * Bring the Field Mapping table's Taxonomy Mapping toggles for one
         * source in line with its declared taxonomy fields (2026-10-07):
         * `[{source_field, wc_taxonomy}, …]`, the same list the server
         * renders from (MMI_Pipeline_Field_Mapping_Defaults::fixed_taxonomy_sources()).
         * Each .mmi-taxonomy-enable-toggles[data-field] container is the
         * taxonomy's row on the table — product_brand / product_cat today.
         *
         * - Declared and missing: append a toggle (unchecked — turning it on
         *   is the profile's own decision, saved by the shared
         *   .field-taxonomy-mapping-toggle handler in import-settings.js).
         * - Declared and present: refresh data-source so a changed field
         *   name is what the next tick saves.
         * - Present but no longer declared, and unchecked: remove it. A
         *   checked one stays — the profile has a live source saved for it,
         *   and silently dropping the control would hide that.
         *
         * Then re-runs Step 2's scoping so a toggle for a source this
         * profile does not assign is hidden like every other row.
         */
        syncSupplierTaxonomyToggles: function(sid, supplierName, taxonomyFields) {
            const esc      = escAttr;
            const declared = {};
            (taxonomyFields || []).forEach(function(tf) {
                if (tf && tf.wc_taxonomy && tf.source_field) {
                    declared[tf.wc_taxonomy] = String(tf.source_field);
                }
            });

            $('.mmi-taxonomy-enable-toggles[data-field]').each(function() {
                const $wrap    = $(this);
                const taxonomy = String($wrap.data('field') || '');
                const $toggle  = $wrap.find(`.field-taxonomy-mapping-toggle[data-supplier="${sid}"]`);

                if (Object.prototype.hasOwnProperty.call(declared, taxonomy)) {
                    const field = declared[taxonomy];
                    if ($toggle.length) {
                        $toggle.attr('data-source', field).data('source', field);
                        $toggle.closest('.mmi-taxonomy-enable-toggle')
                            .attr('title', `Turn on Taxonomy Mapping resolution for ${supplierName.toUpperCase()}, using its '${field}' field`);
                        return;
                    }
                    $wrap.append(
                        `<label class="mmi-taxonomy-enable-toggle" data-supplier="${esc(sid)}" title="Turn on Taxonomy Mapping resolution for ${esc(supplierName.toUpperCase())}, using its '${esc(field)}' field">
                            <input type="checkbox"
                                   class="field-taxonomy-mapping-toggle"
                                   data-field="${esc(taxonomy)}"
                                   data-supplier="${esc(sid)}"
                                   data-source="${esc(field)}">
                            Enable for ${esc(supplierName.toUpperCase())}
                        </label>`
                    );
                } else if ($toggle.length && !$toggle.is(':checked')) {
                    $toggle.closest('.mmi-taxonomy-enable-toggle').remove();
                }
            });

            if (window.mmiUpdateFieldMappingSupplierScope) { window.mmiUpdateFieldMappingSupplierScope(); }
        }

        // ─── Autosave Helpers ───────────────────────────────────────────
    });

    /* ── Dropbox contextual help ─────────────────────────────────────────
     * When the user selects "Dropbox" in the Add Source modal, inject a
     * short setup guide and pre-fill the documentation_url field.
     * ─────────────────────────────────────────────────────────────────── */

    const KB_DOC_URLS = {
        dropbox: '/knowledgebase/how-to-set-up-dropbox-as-a-data-source-in-mmi-data-pipeline/',
        gdrive:  '/knowledgebase/how-to-set-up-google-drive-as-a-data-source-in-mmi-data-pipeline/',
        url:     '/knowledgebase/how-to-add-a-direct-url-data-source-in-mmi-data-pipeline/',
        upload:  '/knowledgebase/how-to-upload-a-csv-or-json-file-as-a-data-source-in-mmi-data-pipeline/',
    };

    $(document).on('change', SELECTORS.addSourceType, function () {
        const type = $(this).val();

        // Remove any previous help block.
        $('#mmi-source-help-block').remove();

        if ( type === 'dropbox' ) {
            const docUrl = KB_DOC_URLS.dropbox;
            const $help  = $(
                '<div id="mmi-source-help-block" class="mmi-source-help-block">' +
                    '<h4>Dropbox Setup Checklist</h4>' +
                    '<ol>' +
                        '<li>Go to <a href="https://www.dropbox.com/developers/apps" target="_blank" rel="noopener">dropbox.com/developers/apps</a> and create a new app.</li>' +
                        '<li>Under <strong>Permissions</strong>, enable <code>files.content.read</code>.</li>' +
                        '<li>Under <strong>OAuth 2</strong>, click <strong>Generate</strong> next to <em>Generated access token</em>.</li>' +
                        '<li>Paste the token in the <em>Access Token</em> field below.</li>' +
                        '<li>Enter the full file path inside your Dropbox (e.g. <code>/exports/products.csv</code>).</li>' +
                    '</ol>' +
                    '<a href="' + escAttr(docUrl) + '" target="_blank" rel="noopener" class="mmi-source-help-link">' +
                        'Full Dropbox setup guide →' +
                    '</a>' +
                '</div>'
            );

            // Inject after the source type selector row.
            $(SELECTORS.addSourceType).closest('.mmi-form-row, .mmi-field-row, p, div').after($help);

            // Pre-fill the documentation URL field if it exists.
            const $docField = $('#cfg-documentation-url');
            if ( $docField.length && ! $docField.val() ) {
                $docField.val( docUrl );
            }

            // Add descriptive tooltip text to the token and path fields.
            $('#add-dropbox-token').attr( 'title',
                'Your Dropbox access token — found under OAuth 2 → Generated access token in your Dropbox Developer App.' );
            $('#add-dropbox-path').attr( 'title',
                'Full path to the file inside your Dropbox, e.g. /exports/products.csv' );

        } else if ( KB_DOC_URLS[ type ] ) {
            // Auto-populate documentation_url for other known source types too.
            const $docField = $('#cfg-documentation-url');
            if ( $docField.length && ! $docField.val() ) {
                $docField.val( KB_DOC_URLS[ type ] );
            }
        }
    });

})(jQuery);
