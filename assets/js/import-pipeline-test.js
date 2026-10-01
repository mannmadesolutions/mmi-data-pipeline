/**
 * Import Pipeline — Test Connection
 * Extends window.MMIDataPipeline with methods for this concern.
 */
(function($) {
    'use strict';

    const ajaxUrl = (window.mmiImportSettings && window.mmiImportSettings.ajaxurl)
                    || (window.mmiGlobal && window.mmiGlobal.ajaxUrl)
                    || window.ajaxurl
                    || '/wp-admin/admin-ajax.php';
    const nonce = (window.mmiImportSettings && window.mmiImportSettings.nonce) || '';

    // escAttr helper (duplicated from core for module scope)
    function escAttr(val) { return $('<div>').text(val || '').html().replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    Object.assign(window.MMIDataPipeline, {
        testConnection: function() {
            const self = this;
            const supplierId = $('#mmi-config-supplier-id').val();
            const $result = $('#mmi-test-result');
            const $btn = $('#mmi-test-connection-btn');

            // ── Immediately disable the button so the user has visual confirmation
            // the click registered, regardless of what happens next.
            $btn.prop('disabled', true).css('opacity', '0.6');

            // Wrap everything in try/catch so any unexpected JS error
            // surfaces as a visible message rather than a silent failure.
            try {

            // ── Pre-flight checks ────────────────────────────────────────
            // Provide immediate visual feedback on every possible failure path
            // so the user never sees a silent no-op.

            if (!nonce) {
                $result.html(
                    '<span class="mmi-test-error">&#9888; Security token missing.</span>' +
                    '<span class="mmi-test-hint">Please refresh the page and try again. ' +
                    'If this keeps happening, deactivate and reactivate the VIP plugin.</span>'
                );
                $btn.prop('disabled', false).css('opacity', '');
                return;
            }

            if (!supplierId) {
                $result.html(
                    '<span class="mmi-test-error">&#9888; Could not identify this data source.</span>' +
                    '<span class="mmi-test-hint">Close this dialog and reopen Configure from the data sources table.</span>'
                );
                $btn.prop('disabled', false).css('opacity', '');
                return;
            }

            // Gather current form state exactly like saveConfig() does
            const credentials = {};
            $('#mmi-auth-fields-container .mmi-auth-input').each(function() {
                const key = $(this).data('key');
                let val = $(this).val();
                if (val.length > 0 && /^[\u2022]+$/.test(val)) {
                    const stored = $(this).attr('data-real-value');
                    val = (stored !== undefined && stored !== '') ? stored : '';
                }
                if (key && val) credentials[key] = val;
            });
            const authCustomHeaders = {};
            $('#auth-custom-headers-list .mmi-kv-row').each(function() {
                const k = $(this).find('.mmi-kv-key').val();
                const v = $(this).find('.mmi-kv-value').val();
                if (k) authCustomHeaders[k] = v;
            });
            const endpoints = [];
            $('#mmi-endpoints-list .mmi-endpoint-row').each(function() {
                const $r = $(this);
                endpoints.push({
                    endpoint_name:   $r.find('.ep-name').val(),
                    endpoint_url:    $r.find('.ep-url').val(),
                    http_method:     $r.find('.ep-method').val(),
                    response_format: $r.find('.ep-format').val(),
                    data_root_path:  $r.find('.ep-root').val(),
                    is_primary:      $r.find('.ep-primary').is(':checked') ? 1 : 0,
                });
            });
            const customHeaders = {};
            $('#mmi-custom-headers-list .mmi-kv-row').each(function() {
                const k = $(this).find('.mmi-kv-key').val();
                const v = $(this).find('.mmi-kv-value').val();
                if (k) customHeaders[k] = v;
            });
            const currentConfig = {
                connection: {
                    supplier_name:        $('#cfg-supplier-name').val(),
                    source_type:          $('#cfg-source-type').val(),
                    base_url:             $('#cfg-base-url').val(),
                    documentation_url:    $('#cfg-documentation-url').val(),
                    notes:                $('#cfg-notes').val(),
                    // Non-HTTP source fields
                    upload_attachment_id: parseInt($('#cfg-upload-attachment-id').val() || 0, 10),
                    dropbox_file_path:    $('#cfg-dropbox-file-path').val(),
                    gdrive_file_id:       $('#cfg-gdrive-file-id').val(),
                },
                auth: {
                    type:        $('#cfg-auth-type').val(),
                    credentials: credentials,
                    custom_headers: authCustomHeaders,
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
                    custom_headers: customHeaders,
                },
            };

            // Client-side validation before firing the request
            const sourceType  = currentConfig.connection.source_type || 'api';
            const baseUrl     = currentConfig.connection.base_url || '';
            const hasEp       = currentConfig.endpoints.some(ep => ep.endpoint_url);
            const activeTab   = $('.mmi-config-tab.active').data('tab') || 'connection';
            const nonHttpLabels = { upload: 'file', dropbox: 'Dropbox file', gdrive: 'Google Drive file' };
            const isNonHttp   = !!nonHttpLabels[sourceType];

            // HTTP types require a URL before testing; non-HTTP types skip this check
            if (!isNonHttp && !baseUrl && !hasEp) {
                // Give tab-aware guidance so the user knows exactly where to go.
                let guidance = 'Enter a Base URL on the <strong>Connection</strong> tab, or add an endpoint on the <strong>Endpoints</strong> tab, then test.';
                if (activeTab === 'auth') {
                    guidance = 'Switch to the <strong>Connection</strong> tab and enter a Base URL (or add an endpoint) before testing.';
                } else if (activeTab === 'endpoints') {
                    guidance = 'Enter a URL in the endpoint row above, or go to the <strong>Connection</strong> tab and enter a Base URL.';
                }
                $result.html('<span class="mmi-test-error">' + guidance + '</span>');
                return;
            }

            $btn.prop('disabled', true);

            // Build the loading message
            if (isNonHttp) {
                $result.html('<span class="mmi-test-loading">Verifying ' + nonHttpLabels[sourceType] + '\u2026</span>');
            } else {
                // HTTP: proactive auth-state hint so the user has context while waiting
                const authType       = currentConfig.auth.type || 'none';
                const hasCredentials = Object.keys(currentConfig.auth.credentials || {})
                                            .some(k => currentConfig.auth.credentials[k]);
                let loadingHint = '';
                if (authType === 'timed_token') {
                    loadingHint = '<span class="mmi-test-hint">' +
                        'Verifying Token Key with provider, then testing the API endpoint \u2014 this may take a moment.' +
                        '</span>';
                } else if (authType === 'none' || !hasCredentials) {
                    loadingHint = '<span class="mmi-test-hint">' +
                        'No authentication configured \u2014 if this API requires credentials the result will tell you where to add them.' +
                        '</span>';
                }
                $result.html('<span class="mmi-test-loading">Testing connection\u2026</span>' + loadingHint);
            }

            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action:      'mmi_test_data_source',
                    supplier_id: supplierId,
                    config:      JSON.stringify(currentConfig),
                    nonce:       nonce,
                },
                timeout: 35000,
                success: function(response) {
                    if (response.success) {
                        const d = response.data;

                        // Source type does not support HTTP testing — show info only.
                        if (d.not_applicable) {
                            $result.html('<span class="mmi-test-hint">' + escAttr(d.message) + '</span>');
                            return;
                        }

                        // Update status badge in table row AND status card \u2014 the
                        // backend already persisted config_status = 'validated' for
                        // this row, which means enabled too (see AGENTS.md's
                        // "Enabled Toggle Eliminated" entry): no separate save-then-
                        // enable step, this source is live the moment the test passes.
                        const $row = $(`tr[data-supplier="${supplierId}"]`);
                        $row.attr('data-config-status', 'validated');
                        // Configure/Preview Data/Delete moved to the toolbar (2026-08-31) —
                        // only relabel the toolbar's "Set Up" → "Configure" button when
                        // this specific row happens to be the active one.
                        if ($row.hasClass('is-active')) {
                            $('#mmi-configure-source-btn').html('<span class="dashicons dashicons-admin-generic"></span> Configure');
                        }
                        self.markSupplierValidated(supplierId);

                        // Inline success message
                        if (isNonHttp) {
                            $result.html(
                                '<span class="mmi-test-success">&#10003; ' + escAttr(d.message || 'Verified.') + '</span>'
                            );
                        } else {
                            $result.html(
                                `<span class="mmi-test-success">&#10003; Connected \u2014 ${d.product_count} items, ${d.response_time_ms}ms</span>` +
                                '<span class="mmi-test-hint mmi-test-hint--inline">Connection verified \u2014 this source is now active.</span>'
                            );
                        }

                        // Show sample data if available
                        if (d.sample_data && d.sample_data.length) {
                            let preview = '<div class="mmi-test-preview"><strong>Sample data (first ' + d.sample_data.length + ' items):</strong><table class="mmi-preview-table"><thead><tr>';
                            const keys = Object.keys(d.sample_data[0]);
                            keys.slice(0, 6).forEach(function(k) { preview += '<th>' + escAttr(k) + '</th>'; });
                            preview += '</tr></thead><tbody>';
                            d.sample_data.forEach(function(row) {
                                preview += '<tr>';
                                keys.slice(0, 6).forEach(function(k) {
                                    let v = row[k];
                                    if (typeof v === 'object') v = JSON.stringify(v);
                                    preview += '<td>' + escAttr(String(v || '').substring(0, 60)) + '</td>';
                                });
                                preview += '</tr>';
                            });
                            preview += '</tbody></table></div>';
                            $result.append(preview);
                        }
                    } else {
                        const errMsg = response.data?.message || 'Unknown error';
                        const statusCode = response.data?.status_code || 0;
                        const errAuthType = $('#cfg-auth-type').val() || 'none';
                        const isPreflightErr = response.data?.auth_stage === 'preflight';
                        const isEndpointConfigErr = response.data?.auth_stage === 'endpoint_config';
                        let hint = '';

                        if (statusCode === 401 || statusCode === 403) {
                            if (errAuthType === 'timed_token') {
                                // Pre-flight passed so Token Key is valid — the API itself rejected it.
                                hint = '<span class="mmi-test-hint">The timed token was fetched successfully but the API rejected the request. Two likely causes: (1) your <strong>API Key</strong> is incorrect — <a class="mmi-tab-jump" data-jump-tab="auth">verify it in the Authentication tab</a>; or (2) the <strong>endpoint URL is missing a resource path</strong> (e.g. the URL should end with <code>/products</code>, not just the base URL) — <a class="mmi-tab-jump" data-jump-tab="endpoints">check the Endpoints tab</a>.</span>';
                            } else if (errAuthType === 'none') {
                                hint = '<span class="mmi-test-hint">This endpoint requires authentication but <strong>None</strong> is selected. <a class="mmi-tab-jump" data-jump-tab="auth">Open the Authentication tab</a>, choose the correct auth type, and enter your credentials.</span>';
                            } else {
                                hint = '<span class="mmi-test-hint">Credentials were rejected. <a class="mmi-tab-jump" data-jump-tab="auth">Check the Authentication tab</a> \u2014 verify each credential value and confirm the auth type matches what the API expects.</span>';
                            }
                        } else if (statusCode === 404) {
                            hint = '<span class="mmi-test-hint">Endpoint not found. The full API path \u2014 not just the base domain \u2014 must be specified. <a class="mmi-tab-jump" data-jump-tab="endpoints">Open the Endpoints tab</a> and enter the complete path (e.g. <code>/api/v2/catalog/products/</code>), or update the Base URL on the <a class="mmi-tab-jump" data-jump-tab="connection">Connection tab</a>.</span>';
                        } else if (statusCode === 429) {
                            hint = '<span class="mmi-test-hint">Rate limited by the API server \u2014 wait a moment and try again. If this keeps happening, consider reducing fetch frequency.</span>';
                        } else if (statusCode >= 500) {
                            hint = '<span class="mmi-test-hint">The remote server returned HTTP ' + statusCode + ' \u2014 this is a server-side error, not a configuration problem. The API may be temporarily unavailable; try again in a few minutes.</span>';
                        } else if (!statusCode) {
                            // Pre-flight auth failures already carry a precise, actionable message.
                            // Only add a network-level hint for genuine connectivity problems.
                            if (isEndpointConfigErr) {
                                hint = '<span class="mmi-test-hint">The endpoint URL is the base API URL with no resource path. <a class="mmi-tab-jump" data-jump-tab="endpoints">Open the Endpoints tab</a> and update the URL to include a specific resource (e.g. <code>/products</code>).</span>';
                            } else if (!isPreflightErr) {
                                const msgLower = errMsg.toLowerCase();
                                if (msgLower.includes('ssl') || msgLower.includes('certificate')) {
                                    hint = '<span class="mmi-test-hint">SSL/certificate error \u2014 verify the Base URL uses <code>https://</code> correctly and the server\u2019s certificate is valid.</span>';
                                } else if (msgLower.includes('resolve') || msgLower.includes('dns') || msgLower.includes('could not connect') || msgLower.includes('name or service')) {
                                    hint = '<span class="mmi-test-hint">Could not reach the server \u2014 the hostname may be misspelled. <a class="mmi-tab-jump" data-jump-tab="connection">Check the Base URL on the Connection tab</a>.</span>';
                                } else {
                                    hint = '<span class="mmi-test-hint">Network error \u2014 <a class="mmi-tab-jump" data-jump-tab="connection">verify the Base URL</a> and ensure the server is reachable from this host.</span>';
                                }
                            }
                        }
                        $result.html('<span class="mmi-test-error">&#10007; ' + escAttr(errMsg) + '</span>' +
                            (hint ? '<span class="mmi-test-hint-block">' + hint + '</span>' : ''));
                    }
                },
                error: function(jqXHR, textStatus) {
                    if (textStatus === 'timeout') {
                        $result.html(
                            '<span class="mmi-test-error">&#10007; Connection timed out.</span>' +
                            '<span class="mmi-test-hint">The API did not respond within 35 seconds. ' +
                            'Try increasing the timeout on the <strong>Advanced</strong> tab, or check that the Base URL is correct.</span>'
                        );
                    } else if (jqXHR.status === 0 || jqXHR.responseText === '-1' || jqXHR.responseText === '0') {
                        $result.html(
                            '<span class="mmi-test-error">&#9888; Security check failed.</span>' +
                            '<span class="mmi-test-hint">Please refresh the page and try again. ' +
                            'This usually means the page session has expired.</span>'
                        );
                    } else {
                        $result.html(
                            '<span class="mmi-test-error">&#10007; Server error \u2014 could not complete the test.</span>' +
                            '<span class="mmi-test-hint">Check the browser console for details, or try refreshing the page.</span>'
                        );
                    }
                },
                complete: function() {
                    $btn.prop('disabled', false).css('opacity', '');
                }
            });

            } catch(e) {
                // Catch any synchronous JS error that would otherwise silently swallow the click.
                $btn.prop('disabled', false).css('opacity', '');
                $result.html(
                    '<span class="mmi-test-error">&#10007; Unexpected error: ' + escAttr(e.message || String(e)) + '</span>' +
                    '<span class="mmi-test-hint">Please refresh the page and try again. If this persists, check the browser console.</span>'
                );
            }
        }

        // ─── Toggle Supplier Enabled ────────────────────────────────────

    });

})(jQuery);
