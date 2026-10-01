/**
 * Attribute & Variation Mapping Panel — JavaScript
 *
 * Handles all interactivity for the Attributes & Variations config panel:
 *  - Product type card selection (Simple / Variable)
 *  - Variation mode card selection (Flat / Nested)
 *  - Add / remove attribute definition rows
 *  - WC attribute dropdown status badge
 *  - "Create new WC attribute" inline flow
 *  - Auto-discover attribute candidates from sample supplier data
 *  - Debounced autosave of the full config to the server
 *  - Configuration summary / preview rendering
 */
(function ($) {
    'use strict';

    // ── Constants ────────────────────────────────────────────────────────────
    const cfg = window.mmiAttributeMapping || {};
    const NONCE        = cfg.nonce       || '';
    const AJAX_URL     = cfg.ajaxUrl     || (window.ajaxurl || '');
    const WC_ATTRS     = cfg.wcAttributes || [];   // [{slug, name, label}, …]
    const SUPPLIERS    = cfg.suppliers    || [];   // [{id, name, fileKey}, …]

    // Read the active profile fresh at each call rather than freezing it at
    // script-load time — #mmi-import-profile can change without a page reload
    // (dropdown/grid-card switch, or the unified wizard editing a different
    // profile than whatever was active when the page first rendered).
    //
    // While the wizard modal is open, #mmi-import-profile itself never
    // reflects what the wizard is actually working on — it still shows
    // whatever profile was active on the page *before* the wizard opened,
    // and never gets an option for a brand-new profile until after it's
    // created. window.MMIProfileWizard (import-settings.js) is the single
    // source of truth for "which profile is the wizard scoped to right now."
    function getProfile() {
        if (window.MMIProfileWizard && $('#new-profile-modal').is(':visible')) {
            const wizardProfile = window.MMIProfileWizard.getActiveProfileId();
            if (wizardProfile) { return wizardProfile; }
        }
        return $('#mmi-import-profile').val() || cfg.profile || 'default';
    }

    let saveTimer = null;
    const SAVE_DEBOUNCE = 800;

    // Set while loadConfigIntoUI() is repopulating the step for a newly-active
    // profile, so the change handlers it triggers (radio/checkbox state, row
    // rebuilding) don't schedule an autosave that would write the profile we
    // just loaded FROM back over the profile we just switched TO.
    let suppressAutosave = false;

    // ── Enable toggle ─────────────────────────────────────────────────────────
    $(document).on('change', '#mmi-attr-enabled', function () {
        const enabled = $(this).is(':checked');
        const $body   = $('#mmi-attr-config-body');
        $body.toggleClass('mmi-attr-disabled-overlay', !enabled);
        scheduleSave();
    });

    // ── Product type card selection ───────────────────────────────────────────
    // Use click on the card label (not change on the radio) so we can call
    // preventDefault() to stop the browser from auto-scrolling to the input.
    $(document).on('click', '.mmi-pt-card', function (e) {
        e.preventDefault();
        const $radio = $(this).find('.mmi-attr-product-type');
        $radio.prop('checked', true);
        updateProductTypeUI($radio.val());
        scheduleSave();
    });

    function updateProductTypeUI(type) {
        // Toggle card selected states
        $('.mmi-pt-card').removeClass('selected');
        $(`.mmi-pt-card input[value="${type}"]`).closest('.mmi-pt-card').addClass('selected');

        // Show/hide variable-only sections
        const isVariable = type === 'variable';
        $('#mmi-attr-sec-2').toggleClass('mmi-is-hidden', !isVariable);
        $('#mmi-attr-sec-4').toggleClass('mmi-is-hidden', !isVariable);

        // Update section 3 step number
        $('#mmi-attr-sec-3 .mmi-attr-section-num').text(isVariable ? '3' : '2');

        // Toggle "For variations" column visibility in attribute table
        $('.mmi-attr-toggle-item:has(.mmi-attr-for-var)').toggleClass('mmi-is-hidden', !isVariable);

        // Update section 1 status
        $('#mmi-attr-sec1-status').text(isVariable ? '🔀 Variable Product' : '📦 Simple Product');
    }

    // ── Variation mode card selection ─────────────────────────────────────────
    $(document).on('click', '.mmi-vm-card', function (e) {
        e.preventDefault();
        const $radio = $(this).find('.mmi-attr-variation-mode');
        $radio.prop('checked', true);
        updateVariationModeUI($radio.val());
        scheduleSave();
    });

    function updateVariationModeUI(mode) {
        $('.mmi-vm-card').removeClass('selected');
        $(`.mmi-vm-card input[value="${mode}"]`).closest('.mmi-vm-card').addClass('selected');

        $('#mmi-flat-group-settings').toggleClass('mmi-is-hidden', mode === 'nested');
        $('#mmi-nested-group-settings').toggleClass('mmi-is-hidden', mode !== 'nested');

        const label = mode === 'nested' ? '🗂 Nested variants array' : '📄 Flat list';
        $('#mmi-attr-sec2-status').text(label);
    }

    // ── Config input changes (text fields) ───────────────────────────────────
    $(document).on('input change', '.mmi-attr-config-input', function () {
        scheduleSave();
    });

    // ── WC Attribute dropdown ─────────────────────────────────────────────────
    $(document).on('change', '.mmi-wc-attr-select', function () {
        const $sel  = $(this);
        const rowId = $sel.data('row');
        const slug  = $sel.val();
        const $wrap = $sel.closest('.mmi-wc-attr-wrap');

        updateAttrStatusBadge($wrap, slug);

        // Show/hide "create new" input
        const isNew = slug === '__new__';
        $wrap.find(`.mmi-new-attr-input-wrap[data-row="${rowId}"]`).toggleClass('mmi-is-hidden', !isNew);

        scheduleSave();
    });

    function updateAttrStatusBadge($wrap, slug) {
        const $badge = $wrap.find('.mmi-wc-attr-status');
        if (!slug || slug === '__new__') {
            if (slug === '__new__') {
                $badge
                    .text('⚡ New attribute will be created on first import')
                    .attr('class', 'mmi-wc-attr-status will-create');
            } else {
                $badge.text('').attr('class', 'mmi-wc-attr-status');
            }
            return;
        }
        const exists = WC_ATTRS.some(a => a.slug === slug);
        if (exists) {
            $badge
                .text('✓ Registered in WooCommerce')
                .attr('class', 'mmi-wc-attr-status registered');
        } else {
            $badge
                .text('⚡ Will be created on first import')
                .attr('class', 'mmi-wc-attr-status will-create');
        }
    }

    // ── "Create new WC attribute" inline ─────────────────────────────────────
    $(document).on('click', '.mmi-create-attr-btn', function () {
        const $btn  = $(this);
        const rowId = $btn.data('row');
        const $wrap = $btn.closest('.mmi-wc-attr-wrap');
        const label = $wrap.find('.mmi-new-attr-name').val().trim();

        if (!label) {
            alert('Please enter an attribute label first.');
            return;
        }

        $btn.prop('disabled', true).text('Creating…');

        $.post(AJAX_URL, {
            action : 'mmi_create_wc_attribute',
            nonce  : NONCE,
            label  : label,
            slug   : label.toLowerCase().replace(/[^a-z0-9]+/g, '_'),
        })
        .done(function (res) {
            if (res.success) {
                const slug = res.data.slug;
                // Add option to the select and choose it
                const $sel = $wrap.find('.mmi-wc-attr-select');
                if (!$sel.find(`option[value="${slug}"]`).length) {
                    $sel.find('option[value="__new__"]').before(
                        `<option value="${escHtml(slug)}">${escHtml(label)} (${escHtml(slug)})</option>`
                    );
                }
                $sel.val(slug).trigger('change');
                $btn.text('Create now in WooCommerce');
                $wrap.find(`.mmi-new-attr-input-wrap[data-row="${rowId}"]`).addClass('mmi-is-hidden');
                scheduleSave();
            } else {
                alert('Error: ' + (res.data?.message || 'Unknown error'));
                $btn.text('Create now in WooCommerce');
            }
        })
        .fail(function () {
            alert('Request failed. Check your network connection.');
            $btn.text('Create now in WooCommerce');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Add attribute row ─────────────────────────────────────────────────────
    $(document).on('click', '#mmi-attr-add-row', function () {
        addAttributeRow();
    });

    function addAttributeRow(data) {
        data = data || {};
        const rowId = data.id || 'attr_' + Date.now();
        const currentType = $('input[name="mmi_product_type"]:checked').val() || 'simple';
        const isVariable  = currentType === 'variable';

        $('#mmi-attr-empty-row').remove();

        const $row = $(`
            <tr data-attr-id="${escHtml(rowId)}">
                <td class="col-label">
                    <input type="text"
                           class="mmi-attr-label-input"
                           data-row="${escHtml(rowId)}"
                           value="${escHtml(data.label || '')}"
                           placeholder="e.g. Color">
                </td>
                <td class="col-wc-attr">
                    <div class="mmi-wc-attr-wrap">
                        ${buildWcAttrSelect(rowId, data.wc_slug || '')}
                    </div>
                </td>
                <td class="col-source">
                    <div class="mmi-source-field-wrap">
                        <input type="text"
                               class="mmi-attr-source-input"
                               data-row="${escHtml(rowId)}"
                               value="${escHtml(data.source_field || '')}"
                               placeholder="e.g. color"
                               list="attr-fields-${escHtml(rowId)}">
                        <datalist id="attr-fields-${escHtml(rowId)}"></datalist>
                    </div>
                </td>
                <td class="col-options">
                    <div class="mmi-attr-toggles">
                        <label class="mmi-attr-toggle-item${isVariable ? '' : ' mmi-is-hidden'}"
                               title="Use for purchasable variations">
                            <label class="mmi-toggle-switch">
                                <input type="checkbox" class="mmi-attr-for-var"
                                       data-row="${escHtml(rowId)}" value="1"
                                       ${data.for_variations ? 'checked' : ''}>
                                <span class="mmi-toggle-slider"></span>
                            </label>
                            For variations
                        </label>
                        <label class="mmi-attr-toggle-item" title="Show on product page">
                            <label class="mmi-toggle-switch">
                                <input type="checkbox" class="mmi-attr-visible"
                                       data-row="${escHtml(rowId)}" value="1"
                                       ${data.visible !== false ? 'checked' : ''}>
                                <span class="mmi-toggle-slider"></span>
                            </label>
                            Visible
                        </label>
                        <label class="mmi-attr-toggle-item" title="Show in layered nav filters">
                            <label class="mmi-toggle-switch">
                                <input type="checkbox" class="mmi-attr-filterable"
                                       data-row="${escHtml(rowId)}" value="1"
                                       ${data.filterable ? 'checked' : ''}>
                                <span class="mmi-toggle-slider"></span>
                            </label>
                            Filterable
                        </label>
                    </div>
                </td>
                <td class="col-delete">
                    <button type="button"
                            class="button button-link-delete mmi-attr-delete-row"
                            data-row="${escHtml(rowId)}"
                            title="Remove">🗑️</button>
                </td>
            </tr>
        `);

        $('#mmi-attr-defs-tbody').append($row);
        updateAttrCount();
        scheduleSave();
        renderPreview();
    }

    function buildWcAttrSelect(rowId, currentSlug) {
        const isNew = currentSlug === '__new__';
        let opts = '<option value="">— Select WooCommerce attribute —</option>';
        WC_ATTRS.forEach(function (wa) {
            const sel = wa.slug === currentSlug ? ' selected' : '';
            opts += `<option value="${escHtml(wa.slug)}"${sel}>${escHtml(wa.label)} (${escHtml(wa.slug)})</option>`;
        });
        const newSel = isNew ? ' selected' : '';
        opts += `<option value="__new__"${newSel}>✚ Create new attribute…</option>`;

        let badge = '';
        if (currentSlug && currentSlug !== '__new__') {
            badge = '<span class="mmi-wc-attr-status registered">✓ Registered in WooCommerce</span>';
        } else if (isNew) {
            badge = '<span class="mmi-wc-attr-status will-create">⚡ New attribute will be created on first import</span>';
        } else {
            badge = '<span class="mmi-wc-attr-status"></span>';
        }

        const newWrap = `
            <div class="mmi-new-attr-input-wrap${isNew ? '' : ' mmi-is-hidden'}" data-row="${escHtml(rowId)}">
                <input type="text" class="mmi-new-attr-name" data-row="${escHtml(rowId)}"
                       placeholder="Label, e.g. Color  →  will create pa_color">
                <button type="button" class="button mmi-create-attr-btn" data-row="${escHtml(rowId)}">
                    Create now in WooCommerce
                </button>
            </div>`;

        return `<select class="mmi-wc-attr-select" data-row="${escHtml(rowId)}">${opts}</select>${badge}${newWrap}`;
    }

    // ── Delete attribute row ──────────────────────────────────────────────────
    $(document).on('click', '.mmi-attr-delete-row', function () {
        const rowId = $(this).data('row');
        $(`tr[data-attr-id="${rowId}"]`).remove();

        if ($('#mmi-attr-defs-tbody tr').length === 0) {
            $('#mmi-attr-defs-tbody').html(`
                <tr id="mmi-attr-empty-row">
                    <td colspan="5" class="mmi-attr-empty">
                        No attributes defined yet. Click <strong>+ Add Attribute</strong> below
                        or use <strong>🔍 Discover from Sample Data</strong> to auto-detect.
                    </td>
                </tr>
            `);
        }

        updateAttrCount();
        scheduleSave();
        renderPreview();
    });

    // ── Attribute label/source/toggle changes ─────────────────────────────────
    $(document).on('input change', '.mmi-attr-label-input, .mmi-attr-source-input, .mmi-attr-for-var, .mmi-attr-visible, .mmi-attr-filterable', function () {
        scheduleSave();
        renderPreview();
    });

    // ── Discover from sample data ─────────────────────────────────────────────
    $(document).on('click', '#mmi-attr-discover-btn', function () {
        const supplierId = $('#mmi-attr-discover-supplier').val();
        const $opt       = $('#mmi-attr-discover-supplier option:selected');
        const fileKey    = $opt.data('file') || '';
        const $result    = $('#mmi-attr-discover-result');

        if (!supplierId) {
            alert('Please select a supplier first.');
            return;
        }

        $(this).prop('disabled', true).text('Scanning…');
        $result.removeClass('mmi-is-hidden').html(
            '<div class="mmi-attr-discover-loading">🔍 Scanning supplier data file…</div>'
        );

        $.post(AJAX_URL, {
            action      : 'mmi_discover_attributes',
            nonce       : NONCE,
            supplier_id : supplierId,
            file_key    : fileKey,
        })
        .done(function (res) {
            if (res.success) {
                renderDiscoverResults(res.data);
            } else {
                $result.html(`<div class="mmi-attr-discover-error">⚠️ ${escHtml(res.data?.message || 'Unknown error')}</div>`);
            }
        })
        .fail(function () {
            $result.html('<div class="mmi-attr-discover-error">⚠️ Request failed.</div>');
        })
        .always(function () {
            $('#mmi-attr-discover-btn').prop('disabled', false).text('🔍 Discover from Sample Data');
        });
    });

    function renderDiscoverResults(data) {
        const $result = $('#mmi-attr-discover-result');
        const candidates = data.candidates || [];

        if (!candidates.length) {
            $result.html('<div class="mmi-attr-discover-empty">No attribute candidates found in the sample data.</div>');
            return;
        }

        let html = `
            <div class="mmi-discover-header">
                Found <strong>${candidates.length}</strong> potential attributes
                in <code>${escHtml(data.file || '')}</code>
                (scanned ${data.total_items || '?'} items).
                Click a candidate to add it, or add all:
            </div>
            <button type="button" class="button mmi-discover-add-all">Add All</button>
            <div class="mmi-discover-candidates">
        `;

        candidates.forEach(function (c, i) {
            const slug = c.wc_slug || '';
            const statusClass = slug ? 'registered' : 'will-create';
            const statusText  = slug
                ? `✓ ${escHtml(slug)}`
                : `⚡ Will create pa_${escHtml(c.path.split('.').pop())}`;

            html += `
                <div class="mmi-discover-candidate" data-index="${i}"
                     data-label="${escHtml(c.label)}"
                     data-slug="${escHtml(slug)}"
                     data-source="${escHtml(c.path)}"
                     title="Click to add this attribute">
                    <span class="mmi-dc-label">${escHtml(c.label)}</span>
                    <span class="mmi-wc-attr-status mmi-wc-attr-status--discover ${statusClass}">${statusText}</span>
                    <span class="mmi-dc-source">${escHtml(c.path)}</span>
                    <span class="mmi-dc-samples">${c.sample_vals.map(v => `<code>${escHtml(v)}</code>`).join(' ')}</span>
                    <span class="mmi-dc-count">${c.unique_count} unique values</span>
                </div>
            `;
        });

        html += '</div>';

        $result.html(html);

        // Store candidates for "Add All"
        $result.data('candidates', candidates);
    }

    $(document).on('click', '.mmi-discover-candidate', function () {
        const $c = $(this);
        addAttributeRow({
            label        : $c.data('label'),
            wc_slug      : $c.data('slug'),
            source_field : $c.data('source'),
            for_variations: false,
            visible       : true,
            filterable    : false,
        });
        $c.addClass('mmi-discover-candidate-added').off('click');
        $c.prepend('<span class="mmi-dc-check">✓ Added — </span>');
    });

    $(document).on('click', '.mmi-discover-add-all', function () {
        const candidates = $('#mmi-attr-discover-result').data('candidates') || [];
        candidates.forEach(function (c) {
            addAttributeRow({
                label        : c.label,
                wc_slug      : c.wc_slug || '',
                source_field : c.path,
                for_variations: false,
                visible       : true,
                filterable    : false,
            });
        });
        $('#mmi-attr-discover-result').addClass('mmi-is-hidden');
    });

    // ── Preview section toggle ────────────────────────────────────────────────
    $(document).on('click', '#mmi-attr-preview-toggle', function () {
        const $body = $('#mmi-attr-preview-body');
        const $icon = $(this).find('.dashicons');
        const collapsed = $body.hasClass('mmi-is-hidden');
        $body.toggleClass('mmi-is-hidden', !collapsed);
        $icon
            .toggleClass('dashicons-arrow-down-alt2',  !collapsed)
            .toggleClass('dashicons-arrow-right-alt2',  collapsed);
        if (collapsed) {
            renderPreview();
        }
    });

    // ── Render the configuration summary preview ──────────────────────────────
    function renderPreview() {
        const $container = $('#mmi-attr-preview-content');
        if (!$container.length) return;

        const config = collectConfig();
        if (!config.attributes.length) {
            $container.html('<p class="mmi-attr-empty">Add attributes above to see a summary here.</p>');
            return;
        }

        const type   = config.product_type;
        const mode   = config.variation_mode;
        const isVar  = type === 'variable';
        const forVar = config.attributes.filter(a => a.for_variations);
        const infoOnly = config.attributes.filter(a => !a.for_variations);

        let html = '<div class="mmi-attr-preview">';

        html += `<div class="mmi-preview-type-badge mmi-badge ${isVar ? 'success' : 'info'}">
            ${isVar ? '🔀 Variable Product' : '📦 Simple Product'}
        </div>`;

        if (isVar) {
            const modeLabel = mode === 'nested'
                ? `Nested mode — variants array at <code>${escHtml(config.variants_path || 'variants')}</code>`
                : `Flat mode — grouped by <code>${escHtml(config.parent_group_field || '(not set)')}</code>`;
            html += `<div class="mmi-preview-row">📐 Variation structure: ${modeLabel}</div>`;
        }

        if (config.attributes.length) {
            html += '<div class="mmi-preview-attrs">';
            html += '<strong>Attributes:</strong><ul>';
            config.attributes.forEach(function (a) {
                const slug   = a.wc_slug || '(no WC slug)';
                const source = a.source_field || '(no source field)';
                const flags  = [];
                if (a.for_variations) flags.push('🔀 for variations');
                if (a.visible)        flags.push('👁 visible');
                if (a.filterable)     flags.push('🔍 filterable');
                html += `<li><strong>${escHtml(a.label || slug)}</strong>
                    — WC: <code>${escHtml(slug)}</code>
                    from <code>${escHtml(source)}</code>
                    ${flags.length ? '<span class="mmi-preview-flags">' + flags.join('  ') + '</span>' : ''}
                </li>`;
            });
            html += '</ul></div>';
        }

        if (isVar && forVar.length) {
            html += `<div class="mmi-preview-row">
                🔀 Variations will be differentiated by:
                ${forVar.map(a => `<code>${escHtml(a.label || a.wc_slug)}</code>`).join(', ')}
            </div>`;
        }

        html += '</div>';
        $container.html(html);
    }

    // ── Collect the current config from the DOM ───────────────────────────────
    function collectConfig() {
        const attrs = [];
        $('#mmi-attr-defs-tbody tr[data-attr-id]').each(function () {
            const $row  = $(this);
            const rowId = $row.data('attr-id');
            const slug  = $row.find('.mmi-wc-attr-select').val() || '';
            attrs.push({
                id             : rowId,
                label          : $row.find('.mmi-attr-label-input').val().trim(),
                wc_slug        : slug === '__new__' ? '' : slug,
                is_global      : true,
                for_variations : $row.find('.mmi-attr-for-var').is(':checked'),
                visible        : $row.find('.mmi-attr-visible').is(':checked'),
                filterable     : $row.find('.mmi-attr-filterable').is(':checked'),
                source_field   : $row.find('.mmi-attr-source-input').val().trim(),
            });
        });

        return {
            enabled              : $('#mmi-attr-enabled').is(':checked'),
            product_type         : $('input[name="mmi_product_type"]:checked').val() || 'simple',
            variation_mode       : $('input[name="mmi_variation_mode"]:checked').val() || 'flat',
            parent_group_field   : $('#mmi-parent-group-field').val().trim(),
            variants_path        : $('#mmi-variants-path').val().trim() || 'variants',
            variation_sku_field  : $('#mmi-var-sku-field').val().trim(),
            variation_price_key  : $('#mmi-var-price-key').val().trim(),
            variation_stock_key  : $('#mmi-var-stock-key').val().trim(),
            attributes           : attrs,
        };
    }

    // ── Debounced autosave ────────────────────────────────────────────────────
    function scheduleSave() {
        if (suppressAutosave) return;
        clearTimeout(saveTimer);
        setSaveIndicator('pending');
        saveTimer = setTimeout(doSave, SAVE_DEBOUNCE);
    }

    function doSave() {
        const config = collectConfig();
        setSaveIndicator('saving');

        $.post(AJAX_URL, {
            action  : 'mmi_autosave_attribute_config',
            nonce   : NONCE,
            profile : getProfile(),
            config  : JSON.stringify(config),
        })
        .done(function (res) {
            if (res.success) {
                setSaveIndicator('saved');
            } else {
                setSaveIndicator('error', res.data?.message || 'Save failed');
            }
        })
        .fail(function () {
            setSaveIndicator('error', 'Network error');
        });
    }

    // Sends a still-pending debounced config save right now, instead of
    // waiting out the rest of SAVE_DEBOUNCE. Mirrors import-settings.js's
    // flushPendingFieldPropertyAutosave() and exists for the identical
    // reason: doSave() resolves its target profile via getProfile() at the
    // moment it actually fires, not at the moment the edit was made — and
    // that resolution depends on '#new-profile-modal' still being :visible.
    // Without this, an Attributes edit made within 800ms of Save & Close/
    // Close/Cancel/Escape/backdrop-click would fire after the modal had
    // already faded out and silently rescope to whatever profile the
    // page-level dropdown has selected (or be dropped, if none matches).
    function flushPendingAutosave() {
        if (saveTimer === null) return;
        clearTimeout(saveTimer);
        saveTimer = null;
        doSave();
    }

    function setSaveIndicator(state, msg) {
        const $indicator = $('#mmi-attr-save-indicator');
        if (!$indicator.length) return;
        const labels = { pending: '…', saving: 'Saving…', saved: '✓ Saved', error: '⚠️ ' + (msg || 'Error') };
        $indicator.text(labels[state] || '').attr('data-state', state);
    }

    // ── Attribute count badge update ──────────────────────────────────────────
    function updateAttrCount() {
        const count = $('#mmi-attr-defs-tbody tr[data-attr-id]').length;
        $('#mmi-attr-count').text(count);
        $('#mmi-attr-sec-3 .mmi-attr-section-num').toggleClass('done', count > 0);
    }

    // ── Utility: safe HTML escape ─────────────────────────────────────────────
    function escHtml(str) {
        return window.MMIEscapeHtml(str);
    }

    // ── Repopulate the step for a newly-active profile (called from
    //    import-settings.js's updateUIWithProfileData() on profile switch,
    //    mirroring updateFieldMappingsTable() for the Field Mapping step) ──
    function loadConfigIntoUI(config) {
        config = config || {};
        suppressAutosave = true;

        $('#mmi-attr-enabled').prop('checked', !!config.enabled);
        $('#mmi-attr-config-body').toggleClass('mmi-attr-disabled-overlay', !config.enabled);

        const productType = config.product_type || 'simple';
        $(`input[name="mmi_product_type"][value="${productType}"]`).prop('checked', true);
        updateProductTypeUI(productType);

        const variationMode = config.variation_mode || 'flat';
        $(`input[name="mmi_variation_mode"][value="${variationMode}"]`).prop('checked', true);
        updateVariationModeUI(variationMode);

        $('#mmi-parent-group-field').val(config.parent_group_field || '');
        $('#mmi-variants-path').val(config.variants_path || 'variants');
        $('#mmi-var-sku-field').val(config.variation_sku_field || '');
        $('#mmi-var-price-key').val(config.variation_price_key || '');
        $('#mmi-var-stock-key').val(config.variation_stock_key || '');

        // Rebuild attribute definition rows from scratch
        $('#mmi-attr-defs-tbody').empty();
        const attrs = Array.isArray(config.attributes) ? config.attributes : [];
        if (attrs.length === 0) {
            $('#mmi-attr-defs-tbody').html(`
                <tr id="mmi-attr-empty-row">
                    <td colspan="5" class="mmi-attr-empty">
                        No attributes defined yet. Click <strong>+ Add Attribute</strong> below
                        or use <strong>🔍 Discover from Sample Data</strong> to auto-detect.
                    </td>
                </tr>
            `);
        } else {
            attrs.forEach(function (attr) {
                addAttributeRow({
                    id             : attr.id,
                    label          : attr.label,
                    wc_slug        : attr.wc_slug,
                    source_field   : attr.source_field,
                    for_variations : !!attr.for_variations,
                    visible        : attr.visible !== false,
                    filterable     : !!attr.filterable,
                });
            });
        }

        updateAttrCount();
        renderPreview();
        setSaveIndicator('saved');

        suppressAutosave = false;
    }

    window.MMIAttributeMapping = { loadConfigIntoUI: loadConfigIntoUI, flushPendingAutosave: flushPendingAutosave };

    // ── Init ──────────────────────────────────────────────────────────────────
    $(function () {
        if (!$('#mmi-attr-enabled').length) return;

        // Ensure initial product type UI state
        const initialType = $('input[name="mmi_product_type"]:checked').val() || 'simple';
        updateProductTypeUI(initialType);

        const initialMode = $('input[name="mmi_variation_mode"]:checked').val() || 'flat';
        updateVariationModeUI(initialMode);

        updateAttrCount();
        renderPreview();
    });

}(jQuery));
