/**
 * Import Settings JavaScript
 * Extracted from inline script in tab-import-settings.php
 */

jQuery(document).ready(function($) {

    function escHtml(str) { return window.MMIEscapeHtml(str); }
    // Current active import profile - read from the dropdown after DOM is ready
    let currentProfile = 'default';
    
    // State tracking for unsaved changes warning
    let isProfileSwitching = false;
    let originalFormData = null;

    /* currentProfileMeta holds the full meta of the active profile so the
     * Edit Profile modal can be pre-filled without an extra AJAX call.      */
    let currentProfileMeta = (window.mmiImportSettings && mmiImportSettings.initialProfileMeta)
        ? mmiImportSettings.initialProfileMeta
        : null;
    
    // Wait for dropdown to be available
    if ($('#mmi-import-profile').length) {
        currentProfile = $('#mmi-import-profile').val() || 'default';
        
        // Set initial history state for browser navigation and write ?profile= into
        // the URL so that a page refresh re-renders the same profile from PHP.
        const currenturl = new URL(window.location);
        if (currentProfile && currentProfile !== 'default') {
            currenturl.searchParams.set('profile', currentProfile);
        } else {
            currenturl.searchParams.delete('profile');
        }
        history.replaceState({profile: currentProfile}, '', currenturl.toString());
        
        // Capture original form data to track changes
        setTimeout(() => {
            originalFormData = new FormData($('form[method=\"post\"]')[0]);
        }, 1000);
    }
    
    // Handle beforeunload to prevent accidental navigation during profile switch
    $(window).on('beforeunload', function(e) {
        if (isProfileSwitching) {
            // Allow profile switching without warning
            return undefined;
        }
        
        // Check if form has actually changed (optional - WordPress may handle this)
        // For now, let WordPress handle the standard unsaved changes warning
        return undefined;
    });
    
    // Autosave debounce timers — both keyed per-target (a composite string key
    // built from whatever identifies the specific thing being saved), NOT a
    // single shared timer/pending-value. A single shared timer means editing
    // ANY field/property/supplier clears the pending save for whatever OTHER
    // field/property/supplier was already queued — that edit is discarded
    // silently, before it's ever sent, well before any wizard-close race even
    // enters into it. Confirmed live as the cause of "changes aren't saving"
    // reports spanning most of the wizard's Field Mapping/Source steps — see
    // AGENTS.md's 2026-09-01 "Wizard Field-Property Autosaves Overwrote Each
    // Other" entry. Per-key timers still collapse rapid repeated edits to the
    // SAME target into one request (the debounce's actual intended job) —
    // they just no longer cancel a DIFFERENT target's pending save.
    // pendingFieldPropertySaves — Field Mapping's text/select/checkbox properties
    //                             (source, transform, constant value, conditions, …)
    // pendingPrimaryKeySaves    — primary-key text inputs, per supplier + field type
    const pendingFieldPropertySaves = new Map();
    const pendingPrimaryKeySaves    = new Map();

    /**
     * Slugify a profile name into its eventual profile_id — the single
     * definition shared by wizardSaveProfile() (final save) and
     * wizardActiveProfileId() (live resolution during autosave) so both
     * agree on what a not-yet-created profile's id will be.
     */
    function slugifyProfileName(name) {
        return (name || '').trim().toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .substring(0, 50);
    }

    /**
     * The profile id the wizard (mmi-modal-box.mmi-wizard-modal) is currently
     * scoped to — editing an existing profile's real id, or the live slug of
     * whatever name is typed in Step 2 while creating a new one. Returns null
     * only when the wizard is closed, or open in create mode with no name
     * entered yet (Step 2 blocks progression past itself without one).
     */
    function wizardActiveProfileId() {
        if (_wizardEditProfileId) return _wizardEditProfileId;
        if (!$('#new-profile-modal').is(':visible')) return null;
        return slugifyProfileName($('#new-profile-name').val()) || null;
    }

    /**
     * Which profile id an autosave call anywhere on this tab should target:
     * the wizard's own profile while its modal is open (create or edit),
     * falling back to the page-level active profile otherwise. Field Mapping
     * (panel-field-mapping.php) and Attributes (panel-attribute-mapping.php)
     * only ever render ONE copy of their markup on the page — inside the
     * wizard modal — so without this, autosaving from inside the wizard
     * always wrote to `currentProfile` (whatever was active on the page
     * *before* the wizard opened) instead of the profile actually being
     * worked on — silently corrupting an unrelated profile whenever the
     * wizard edited/created anything other than the page's current profile.
     */
    function autosaveScopeProfile() {
        if ($('#new-profile-modal').is(':visible')) {
            const wizardProfile = wizardActiveProfileId();
            if (wizardProfile) return wizardProfile;
        }
        return currentProfile;
    }

    /* ── Field conflicts ─────────────────────────────────────────────── */

    const FIELD_CONFLICT = {
        ROW:    'tr.field-mapping-row',
        CELL:   'td.col-woo-field',
        NOTICE: '.mmi-field-conflict',
        CLASS:  'mmi-field-conflict notice notice-warning inline',
    };

    /**
     * Warn, inside a field's own row, that another import profile or
     * Catalog Maintenance also writes this field (server:
     * MMI_Pipeline_Field_Conflicts). Stays until a later save clears it —
     * it names something to fix, so it must not fade on its own.
     *
     * @param {string}   fieldName
     * @param {string[]} messages  Empty clears the warning.
     */
    function renderFieldConflicts(fieldName, messages) {
        const $row = $(`${FIELD_CONFLICT.ROW}[data-field="${CSS.escape(fieldName)}"]`);
        $row.find(FIELD_CONFLICT.NOTICE).remove();
        if (!messages || !messages.length) { return; }
        const $notice = $('<div>', { class: FIELD_CONFLICT.CLASS, role: 'status' });
        messages.forEach(function (message) { $notice.append($('<p>').text(message)); });
        $row.find(FIELD_CONFLICT.CELL).first().append($notice);
    }

    /** @param {Object<string, string[]>} conflicts field => messages */
    function renderAllFieldConflicts(conflicts) {
        $(FIELD_CONFLICT.ROW).find(FIELD_CONFLICT.NOTICE).remove();
        Object.keys(conflicts || {}).forEach(function (field) {
            renderFieldConflicts(field, conflicts[field]);
        });
    }

    // Exposed for import-pipeline-attributes.js (a separate IIFE) so its own
    // autosave resolves the same wizard-scoped profile id instead of only
    // ever reading the page-level #mmi-import-profile dropdown.
    window.MMIProfileWizard = { getActiveProfileId: wizardActiveProfileId };

    // Fires the actual save request immediately — shared by the debounced
    // single-input path below and by bulk/programmatic callers (e.g. "Copy
    // constant to all sources") that must not lose all-but-the-last write to
    // the shared debounce timer (see autosaveFieldProperty).
    function autosaveFieldPropertyNow(fieldName, property, value, supplier) {
        showAutosaveIndicator('💾 Saving...', 'saving');
        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_autosave_field_property',
                nonce: mmiImportSettings.nonce,
                field_name: fieldName,
                property: property,
                value: value,
                supplier: supplier,
                profile: autosaveScopeProfile(),
                // Lets the server spot conflicts for a profile still being
                // created (no saved sources yet) — see MMI_Pipeline_Field_Conflicts.
                sources: $('#new-profile-modal').is(':visible') ? JSON.stringify(wizardSelectedSources()) : ''
            },
            success: function(response) {
                if (response.success) {
                    showAutosaveIndicator('✓ Saved', 'success');
                    renderFieldConflicts(fieldName, response.data && response.data.conflicts);
                } else {
                    showAutosaveIndicator('✗ Failed', 'error');
                }
                // Keep the wizard's inline issues panel current as the user
                // edits — otherwise it would only ever reflect whatever state
                // existed when Next/Save was last clicked.
                if (property !== 'file' && $('#new-profile-modal').is(':visible') && _wizardCurrentPanel === 'mmi-wizard-p4') {
                    wizardValidateFieldMappings(function () {});
                }
            },
            error: function() {
                showAutosaveIndicator('✗ Error', 'error');
            }
        });
    }

    // Generic autosave for field mapping properties — debounced per (field,
    // property, supplier) so rapid repeated edits to the SAME target collapse
    // to one request, without a different target's edit ever cancelling this
    // one's still-pending save (see pendingFieldPropertySaves above for why a
    // single shared timer/pending-value was a real, confirmed data-loss bug,
    // not just a close-timing race). Bulk-copy loops still must use
    // autosaveFieldPropertyNow() directly — collapsing N sequential targets
    // sharing the exact same key is still the debounce's job.
    //
    // Each Map entry mirrors whatever's currently queued for that key, so
    // flushPendingFieldPropertyAutosave() (below) can send it immediately
    // instead of waiting out the rest of the 800ms — required before anything
    // that closes the wizard modal or resets _wizardEditProfileId, since
    // autosaveFieldPropertyNow() resolves which profile to save to via
    // autosaveScopeProfile() at the moment it actually fires, not at the
    // moment the edit was made. Without flushing first, an edit made shortly
    // before Save & Close/Cancel/Escape/backdrop-click is dispatched only
    // after the modal has faded out — autosaveScopeProfile() then sees
    // '#new-profile-modal' as no longer :visible and silently rescopes the
    // save to whatever profile the page-level dropdown happens to have
    // selected (or drops it, if none matches), instead of the profile
    // actually being edited.
    function autosaveFieldProperty(fieldName, property, value, supplier = '') {
        const key = fieldName + '|' + property + '|' + supplier;
        const existing = pendingFieldPropertySaves.get(key);
        if (existing) {
            clearTimeout(existing.timer);
        }
        const entry = { fieldName, property, value, supplier, timer: null };
        entry.timer = setTimeout(function() {
            pendingFieldPropertySaves.delete(key);
            autosaveFieldPropertyNow(fieldName, property, value, supplier);
        }, 800);
        pendingFieldPropertySaves.set(key, entry);
    }

    // Sends every still-pending debounced field-property edit right now — see
    // the comment on pendingFieldPropertySaves above for why every
    // wizard-close path (Save & Close, Close, Cancel, Escape, backdrop click)
    // must call this before doing anything else. Snapshots the queued entries
    // before dispatching any of them, so a field this loop is in the middle of
    // flushing can't itself be re-queued and skipped.
    function flushPendingFieldPropertyAutosave() {
        if (pendingFieldPropertySaves.size === 0) {
            return;
        }
        const entries = Array.from(pendingFieldPropertySaves.values());
        pendingFieldPropertySaves.clear();
        entries.forEach(function(entry) {
            clearTimeout(entry.timer);
            autosaveFieldPropertyNow(entry.fieldName, entry.property, entry.value, entry.supplier);
        });
    }

    // Flushes every wizard-step autosave that could still have something
    // queued when the wizard closes — Field Mapping and primary-key edits
    // (both this file) and Attributes (import-pipeline-attributes.js, a
    // separate debounce timer with the identical close-timing race). Any
    // future step-level autosave added to the wizard needs the same
    // treatment: expose a flush hook and add it here.
    function flushAllPendingWizardAutosaves() {
        flushPendingFieldPropertyAutosave();
        flushPendingPrimaryKeyAutosave();
        if (window.MMIAttributeMapping && typeof window.MMIAttributeMapping.flushPendingAutosave === 'function') {
            window.MMIAttributeMapping.flushPendingAutosave();
        }
    }
    
    // Show autosave indicator
    // Styling lives entirely in import-settings.css — this function only
    // swaps classes and content; no inline CSS.
    function showAutosaveIndicator(message, type) {
        let $indicator = $('#autosave-indicator');
        if (!$indicator.length) {
            $indicator = $('<div id="autosave-indicator"></div>');
            $('body').append($indicator);
        }

        // Show the floating toast
        $indicator
            .stop(true)
            .removeClass('saving success error info')
            .addClass(type)
            .text(message)
            .css('opacity', 0)
            .show()
            .animate({ opacity: 1 }, 200);

        // Auto-hide the toast after a short delay (non-saving states only)
        if (type !== 'saving') {
            setTimeout(function() {
                $indicator.stop(true).animate({ opacity: 0 }, 300, function() {
                    $(this).hide();
                });
            }, 2000);
        }
    }

    // Exposed so other modules (import-pipeline.js / import-pipeline-csv.js's
    // topbar schedule/rule autosave) can show the SAME real toast the wizard's
    // own field-mapping autosave uses, instead of each module growing its own
    // divergent indicator. See MMIDataPipeline.showSaveIndicator() in
    // import-pipeline-ui.js, which was calling a `window.MMISaveIndicator`
    // global that was never actually defined anywhere — every topbar autosave
    // call has been silently showing nothing since it was written.
    window.MMIDataPipeline = window.MMIDataPipeline || {};
    window.MMIDataPipeline.showAutosaveIndicator = showAutosaveIndicator;

    // Shared by updateMappedIndicators() below and a few other call sites
    // that need "every row belonging to this group" — a group's rows are
    // simply every <tr> between one field-group-header and the next.
    function getGroupRows($header) {
        return $header.nextUntil('.field-group-header', 'tr');
    }

    // Initialize whatever's already on the page.
    updateFieldMappingSingleSourceState();

    // The file selector (.field-file-selector) now renders in its own
    // col-source-file column — a sibling <td> of col-source-field, not nested
    // inside .field-selector-group above the value-swap the way it used to
    // be. Anything that needs to go from one to the other (or vice versa) can
    // no longer use a simple .closest('.field-selector-group')/.find() —
    // both still live under the same <tr> (.field-mapping-row) and share the
    // same data-supplier, which .supplier-source-row is keyed on, so that's
    // the join used instead. Two small shared helpers rather than repeating
    // this lookup at each of the three call sites that need it.
    function fileSelectorFor($anyElInRow, supplier) {
        return $anyElInRow.closest('.field-mapping-row').find('.field-file-selector[data-supplier="' + supplier + '"]');
    }
    function supplierSourceRowFor($fileSelector, supplier) {
        return $fileSelector.closest('.field-mapping-row').find('.supplier-source-row[data-supplier="' + supplier + '"]');
    }

    // Per-source Constant checkbox: swaps that one supplier row's source-path
    // input for its constant-value input IN PLACE (same slot, same width) —
    // deliberately no slideDown/slideUp animation and no new flex sibling, since
    // that's what previously made .mmi-source-field-row wrap and jump height on
    // toggle. Exposed as a named function so the preset-loader can re-run the
    // same visual sync after setting .prop('checked') programmatically (which
    // fires no native 'change' event) via the 'mmi:sync-visual-state' event.
    function syncPerSourceConstantVisualState($checkbox) {
        const checked = $checkbox.is(':checked');
        const $group = $checkbox.closest('.field-selector-group');
        const $swap = $group.find('.mmi-source-value-swap');

        // 'hidden' alone (display:none) already blocks interaction, but a
        // disabled attribute additionally strips these controls from
        // focus/tab order and guarantees the inactive side can never receive
        // a value — a value is only ever settable on the side the toggle
        // selects. A source-path select can independently be disabled by the
        // "choose a file first" gate (.field-file-selector's change handler)
        // when this field has more than one file to pick from — re-enabling
        // it on uncheck must respect that, not just always flip disabled=false.
        const $fileSelector  = fileSelectorFor($checkbox, $checkbox.data('supplier'));
        const fileNotChosen  = $fileSelector.length > 0 && $fileSelector.val() === '';

        $swap.find('.mmi-source-path-input').toggleClass('hidden', checked)
            .prop('disabled', checked || fileNotChosen);
        $swap.find('.mmi-source-constant-input').toggleClass('hidden', !checked)
            .find('input, select').prop('disabled', !checked);

        // The file selector only exists to pick which file's field names get
        // suggested when browsing the Source box above — with Const on,
        // there's no Source box being browsed, so leaving it interactive is
        // misleading (nothing it does has any effect while a constant is in
        // use). No other gate ever disables this control, so Const is the
        // only condition to restore on uncheck.
        $fileSelector.prop('disabled', checked);
    }
    // Turning Const on for a taxonomy-type field swaps in a
    // .mmi-constant-taxonomy-select (see panel-field-mapping.php) — load its
    // real term options now rather than eagerly for every taxonomy field on
    // page load, since most Const toggles are never used in a given session.
    // Shared by both the native 'change' handler (a real user click) and
    // 'mmi:sync-visual-state' below (checkbox state set programmatically —
    // e.g. restoring a saved profile — which fires no native 'change').
    function maybePreloadConstantTaxonomyTerms($checkbox) {
        if (!$checkbox.is(':checked')) {
            return;
        }
        const $taxSelect = $checkbox.closest('.field-selector-group').find('.mmi-constant-taxonomy-select');
        if ($taxSelect.length) {
            loadConstantTaxonomyTerms($taxSelect.data('taxonomy'));
        }
    }
    $(document).on('change', '.use-constant-checkbox', function() {
        const $checkbox = $(this);
        const fieldName = $checkbox.data('field');
        const supplier = $checkbox.data('supplier');
        const checked = $checkbox.is(':checked');
        syncPerSourceConstantVisualState($checkbox);
        autosaveFieldProperty(fieldName, 'use_constant_value', checked ? 1 : 0, supplier);
        maybePreloadConstantTaxonomyTerms($checkbox);
    });
    $(document).on('mmi:sync-visual-state', '.use-constant-checkbox', function() {
        syncPerSourceConstantVisualState($(this));
        maybePreloadConstantTaxonomyTerms($(this));
    });

    // Auto-save: "Array holds all images" toggle on _product_image_url
    // (per source) — see MMI_Pipeline_Field_Resolver::assemble_product_images().
    $(document).on('change', '.image-array-mode-checkbox', function() {
        const $checkbox = $(this);
        autosaveFieldProperty($checkbox.data('field'), 'image_array_mode', $checkbox.is(':checked') ? 1 : 0, $checkbox.data('supplier'));
    });

    // Auto-save: Constant Value input/select (per source)
    $(document).on('change input', '.constant-value-select, .constant-value-input-text', function() {
        const $input = $(this);
        const name = $input.attr('name') || '';
        const matches = name.match(/field_mappings\[(.+?)\]\[constant_value\](?:\[(.+?)\])?/);
        if (!matches) return;
        const fieldName = matches[1];
        const supplier = matches[2] || '';
        autosaveFieldProperty(fieldName, 'constant_value', $input.val(), supplier);
    });

    // "Copy constant to all sources" — one-shot convenience action, not a
    // persisted mode: copies the first supplier row's Constant checkbox state
    // and value to every other configured supplier row for this field, then
    // autosaves each so it behaves identically to the user checking/typing
    // each row by hand. Keeps the per-source data shape as the only shape
    // (see resolve_constant() in class-pipeline-field-mapping-defaults.php)
    // rather than introducing a second "shared value" concept to persist.
    $(document).on('click', '.mmi-copy-constant-to-all', function() {
        const fieldName = $(this).data('field');
        const $rows = $(this).closest('.supplier-sources').find('.supplier-source-row');
        const $first = $rows.first();
        const checked = $first.find('.use-constant-checkbox').is(':checked');
        const value = $first.find('.constant-value-select, .constant-value-input-text').val() || '';

        $rows.slice(1).each(function() {
            const $row = $(this);
            const supplier = $row.data('supplier');

            const $checkbox = $row.find('.use-constant-checkbox');
            $checkbox.prop('checked', checked);
            syncPerSourceConstantVisualState($checkbox);
            maybePreloadConstantTaxonomyTerms($checkbox);
            autosaveFieldPropertyNow(fieldName, 'use_constant_value', checked ? 1 : 0, supplier);

            const $valueInput = $row.find('.constant-value-select, .constant-value-input-text');
            if ($valueInput.length) {
                // A taxonomy <select> may not have its real term options
                // loaded yet (the preload above is async) — seed a
                // temporary option matching the copied value so it isn't
                // silently dropped; loadConstantTaxonomyTerms() reconciles
                // it against the real list (and removes it) once that
                // request resolves, same as populateSourceFieldSelect()'s
                // own matchExists pattern.
                if ($valueInput.is('select.mmi-constant-taxonomy-select') && value && !$valueInput.find('option[value="' + value + '"]').length) {
                    $valueInput.append($('<option></option>').val(value).text(value));
                }
                $valueInput.val(value);
                autosaveFieldPropertyNow(fieldName, 'constant_value', value, supplier);
            }
        });

        showAutosaveIndicator('Copied to all sources', 'success');
    });
    
    // Auto-save: Hierarchical taxonomy toggle (also shows/hides the delimiter
    // + leaf-only options right below it)
    $(document).on('change', '.mmi-tax-hierarchical-toggle', function() {
        const $checkbox = $(this);
        const fieldName = $checkbox.data('field');
        const checked = $checkbox.is(':checked');
        $checkbox.closest('.mmi-tax-hierarchy-settings').find('.mmi-tax-hierarchy-options').toggleClass('hidden', !checked);
        autosaveFieldProperty(fieldName, 'tax_hierarchical', checked ? 1 : 0);
    });
    
    // Auto-save: Hierarchy path separator
    $(document).on('change input', '.mmi-tax-hierarchy-delim', function() {
        const $input = $(this);
        const fieldName = $input.data('field');
        autosaveFieldProperty(fieldName, 'tax_hierarchical_delim', $input.val());
    });
    
    // Auto-save: "Only assign the leaf term" toggle
    $(document).on('change', '.mmi-tax-hierarchy-leaf-only', function() {
        const $checkbox = $(this);
        const fieldName = $checkbox.data('field');
        autosaveFieldProperty(fieldName, 'tax_hierarchical_leaf_only', $checkbox.is(':checked') ? 1 : 0);
    });
    
    // Auto-save: legacy single-source field (no per-supplier file, so never
    // converted to the <select> below — still a plain free-typed input, name
    // attribute intact) — use attribute selector to catch it regardless of
    // field name.
    $(document).on('change', '[name*="[source]"]', function() {
        const $input = $(this);
        const value = $input.val();
        const name = $input.attr('name');

        // Extract field name and supplier from name attribute
        const matches = name.match(/field_mappings\[(.+?)\]\[source\](?:\[(.+?)\])?/);
        if (matches) {
            const fieldName = matches[1];
            const supplier = matches[2] || ''; // Empty if single source
            autosaveFieldProperty(fieldName, 'source', value, supplier);
        }
    });

    // Auto-save: Source field <select> (per supplier) — no name attribute
    // (unlike the legacy input above), so it's targeted by class/data-
    // attribute rather than the generic [name*="[source]"] selector. Every
    // option is a real field from this supplier's feed (see
    // populateSourceFieldSelect()), so whatever value lands here is
    // guaranteed valid — no sentinel/custom-value branch needed.
    $(document).on('change', '.mmi-source-path-input', function() {
        const $select = $(this);
        if (!$select.is('select')) { return; }
        autosaveFieldProperty($select.data('field'), 'source', $select.val(), $select.data('supplier'));
    });

    // Auto-save: Taxonomy Mapping enable/disable toggle (product_brand,
    // product_cat) — these fields have no editable source picker (the raw
    // feed field is fixed per supplier, see panel-field-mapping.php's
    // $mmi_taxonomy_mapping_only_fields card), so this checkbox IS the only
    // configuration: checking it writes the fixed source value (carried in
    // data-source) into 'source' for this supplier, unchecking blanks it.
    // Reuses the plain 'source' autosave property — no new backend property
    // needed — so 'enabled' keeps auto-deriving from 'source' presence
    // exactly like every other field (mmi_pipeline_derive_field_enabled_state()
    // in ImportSettingsController.php), and Taxonomy_Mapping_Handler::
    // apply_taxonomy_mappings()'s existing non-empty-source gate is what
    // actually turns real import resolution on/off.
    $(document).on('change', '.field-taxonomy-mapping-toggle', function() {
        const $checkbox = $(this);
        const fieldName = $checkbox.data('field');
        const supplier = $checkbox.data('supplier');
        const value = $checkbox.is(':checked') ? $checkbox.data('source') : '';
        autosaveFieldProperty(fieldName, 'source', value, supplier);
    });

    // Auto-save: File selector dropdowns
    $(document).on('change', '.field-file-selector', function() {
        const $select = $(this);
        const fieldName = $select.data('field');
        const supplier = $select.data('supplier');
        const value = $select.val();
        autosaveFieldProperty(fieldName, 'file', value, supplier);
    });

    // Group/profile-wide "default file" pickers (panel-field-mapping.php's
    // field-group-header row and its toolbar) — a one-shot convenience
    // action, not a persisted mode of their own, same shape as
    // .mmi-copy-constant-to-all above: sets every matching real
    // .field-file-selector's value and fires its own native 'change' so the
    // existing per-row autosave/reload-fields handlers do the actual work,
    // exactly as if each row had been changed by hand. Never touches a
    // .field-file-selector whose supplier has no matching <option> for the
    // chosen value (a hidden single-file input, or a select the newly-picked
    // file doesn't apply to) — silently skipped, not an error, since "this
    // group also has an SkuPort file picker with different options" is
    // normal, not a fault. Purely a suggestion default: never changes which
    // file the import itself reads (see the toolbar's own tooltip).
    function applyDefaultFileTo($fileSelectors, value) {
        let applied = 0;
        $fileSelectors.each(function () {
            const $fileSel = $(this);
            if ($fileSel.is('select') && $fileSel.find('option[value="' + value + '"]').length) {
                $fileSel.val(value).trigger('change');
                applied++;
            }
        });
        return applied;
    }
    $(document).on('change', '.group-file-default', function() {
        const $picker = $(this);
        const value = $picker.val();
        if (!value) { return; } // placeholder "— SUPPLIER —" option, nothing to apply
        const supplier = $picker.data('supplier');
        const $rows = getGroupRows($picker.closest('.field-group-header'));
        const applied = applyDefaultFileTo($rows.find('.field-file-selector[data-supplier="' + supplier + '"]'), value);
        showAutosaveIndicator(applied ? ('✓ Applied default file to ' + applied + ' field' + (applied === 1 ? '' : 's') + ' in this section') : 'No matching fields in this section', applied ? 'success' : 'info');
        $picker.val('');
    });
    $(document).on('change', '.profile-file-default', function() {
        const $picker = $(this);
        const value = $picker.val();
        if (!value) { return; }
        const supplier = $picker.data('supplier');
        const applied = applyDefaultFileTo($('.mmi-field-mapping-table .field-file-selector[data-supplier="' + supplier + '"]'), value);
        showAutosaveIndicator(applied ? ('✓ Applied default file to ' + applied + ' field' + (applied === 1 ? '' : 's') + ' in this profile') : 'No matching fields in this profile', applied ? 'success' : 'info');
        $picker.val('');
    });

    // Auto-save: Transform dropdown
    $(document).on('change', '.field-transform', function() {
        const $select = $(this);
        const fieldName = $select.data('field');
        const value = $select.val();
        if (!fieldName) return;

        // Show/hide transform param groups for this field
        const $params = $('.mmi-transform-params[data-field="' + fieldName + '"]');
        $params.find('.mmi-tp-group').each(function() {
            const forTransform = $(this).data('for-transform');
            if (forTransform === value) {
                $(this).removeClass('mmi-is-hidden');
            } else {
                $(this).addClass('mmi-is-hidden');
            }
        });

        autosaveFieldProperty(fieldName, 'transform', value);
    });

    // ── Transform params autosave ──────────────────────────────────────────────
    // Collect all param inputs for a field into one JSON object and autosave.
    function autosaveTransformParams(fieldName) {
        const $params = $('.mmi-transform-params[data-field="' + fieldName + '"]');
        const paramsObj = {};
        $params.find('.mmi-tp-input').each(function() {
            const param = $(this).data('param');
            if (param) {
                paramsObj[param] = $(this).val();
            }
        });
        autosaveFieldProperty(fieldName, 'transform_params', JSON.stringify(paramsObj));
    }

    $(document).on('input change', '.mmi-tp-input', function() {
        const fieldName = $(this).data('field');
        if (fieldName) autosaveTransformParams(fieldName);
    });

    // ── Per-field Conditions ─────────────────────────────────────────────────
    // The suite-wide condition builder (MMI_Condition_Builder /
    // mmi-condition-builder.js — same as Catalog Maintenance). Evaluated at
    // import time by Condition_Evaluator via Stock_Override_Resolver; a
    // field whose conditions fail is skipped for that record (existing value
    // untouched) unless the fallback below is enabled.
    //
    // Autosave only follows a real edit: the builder also fires its change
    // event while restoring saved rows on load, and a row whose field list
    // is still loading reads as incomplete — saving then would drop it.
    const FM_COND = {
        ROOT:      '.mmi-fm-conditions .mmi-cb',
        TOUCHED:   'fmTouched',
        BASELINE:  'fmBaseline',
    };

    function fmConditionsSignature(data) {
        return JSON.stringify([data.match_logic || 'all', data.conditions.map(function(c) {
            return [c.source, c.field, c.operator, c.value, !!c.case_sensitive, !!c.or];
        })]);
    }

    /** What was saved when this builder rendered (its rows' data-saved-*). */
    function fmSavedSignature($root) {
        const conditions = [];
        $root.find('.mmi-condition-row[data-saved-source]').each(function() {
            const $row = $(this);
            conditions.push({
                source:   String($row.attr('data-saved-source') || ''),
                field:    String($row.attr('data-saved-field') || ''),
                operator: String($row.attr('data-saved-operator') || 'equals'),
                value:    String($row.attr('data-saved-value') || ''),
                case_sensitive: $row.find('.mmi-cond-case-input').prop('defaultChecked'),
                or:       conditions.length > 0 && $row.attr('data-saved-or') === '1',
            });
        });
        const $ml = $root.find('.mmi-cb-match-logic option[selected]');
        return fmConditionsSignature({ match_logic: $ml.length ? $ml.val() : 'all', conditions: conditions });
    }

    function syncConditionsToggle(fieldName, count) {
        $('.mmi-fm-conditions-toggle[data-field="' + fieldName + '"]')
            .toggleClass('has-conditions', count > 0)
            .text('Conditions' + (count > 0 ? ' (' + count + ')' : ''));
    }

    $(document).on('click', '.mmi-fm-conditions-toggle', function() {
        const fieldName = $(this).data('field');
        // The builder lives in its own full-width row right after this
        // field's row (not nested in this narrow Transform cell — a
        // fixed-width table column can't grow to fit a multi-row builder,
        // see panel-field-mapping.php's comment on this row).
        $('tr.mmi-fm-conditions-row[data-field="' + fieldName + '"]').toggleClass('mmi-is-hidden');
    });

    // A real (user-originated) interaction inside a builder marks it
    // editable. Capture phase, so it's marked before the builder's own
    // (bubble-phase) handlers fire their change event for the same click.
    ['input', 'change', 'click'].forEach(function(type) {
        document.addEventListener(type, function(e) {
            const root = e.isTrusted && e.target.closest ? e.target.closest(FM_COND.ROOT) : null;
            if (root) {
                $(root).data(FM_COND.TOUCHED, true);
            }
        }, true);
    });

    $(document).on(window.MMIConditionBuilder.CHANGE_EVENT, FM_COND.ROOT, function(e) {
        const $root = $(e.currentTarget);
        if (!$root.data(FM_COND.TOUCHED)) return;
        if ($root.find('.mmi-cascade-loading').length) return; // fires again once loaded
        if ($root.data(FM_COND.BASELINE) === undefined) {
            $root.data(FM_COND.BASELINE, fmSavedSignature($root));
        }
        const data      = window.MMIConditionBuilder.collect($root);
        const signature = fmConditionsSignature(data);
        if (signature === $root.data(FM_COND.BASELINE)) return;

        const fieldName = String($root.attr('data-field') || '');
        const before    = JSON.parse($root.data(FM_COND.BASELINE));
        autosaveFieldProperty(fieldName, 'conditions', JSON.stringify(data.conditions));
        if (before[0] !== (data.match_logic || 'all')) {
            autosaveFieldProperty(fieldName, 'condition_match_logic', data.match_logic || 'all');
        }
        $root.data(FM_COND.BASELINE, signature);
        syncConditionsToggle(fieldName, data.conditions.length);
    });

    $(document).on('change', '.mmi-cond-fallback-enabled', function() {
        const $checkbox = $(this);
        const fieldName = $checkbox.data('field');
        const checked = $checkbox.is(':checked');
        $checkbox.closest('.mmi-fm-conditions').find('.mmi-cond-fallback-value').toggleClass('mmi-is-hidden', !checked);
        autosaveFieldProperty(fieldName, 'condition_fallback_enabled', checked ? 1 : 0);
    });

    $(document).on('input change', '.mmi-cond-fallback-value', function() {
        const $input = $(this);
        const fieldName = $input.data('field');
        autosaveFieldProperty(fieldName, 'condition_fallback_value', $input.val());
    });

    // ===== AUTO-SAVE FOR PRIMARY KEYS CONFIGURATION =====

    // Auto-save: Primary key source field select — populated with the file's
    // real detected fields by loadFieldsFromFile() (see the .pk-file-selector
    // handling further below). "__custom__" reveals the plain-text fallback
    // instead of autosaving the sentinel itself.
    $(document).on('change', '.primary-key-source-input', function() {
        const $select = $(this);
        if (!$select.is('select')) { return; }
        const supplier = $select.data('supplier');
        const value = $select.val();
        const $customInput = $select.siblings('.primary-key-source-custom-input');

        if (value === '__custom__') {
            $customInput.removeClass('mmi-is-hidden').trigger('focus');
            const customValue = $customInput.val();
            if (customValue) {
                autosavePrimaryKey(supplier, 'source', customValue);
            }
            return;
        }

        $customInput.addClass('mmi-is-hidden');
        autosavePrimaryKey(supplier, 'source', value);
    });

    // Auto-save: Primary key source custom-field fallback (only relevant
    // while "Custom / Other…" is selected in the select above).
    $(document).on('change input', '.primary-key-source-custom-input', function() {
        const $input = $(this);
        const supplier = $input.data('supplier');
        const value = $input.val();
        if (!value) {
            return;
        }
        autosavePrimaryKey(supplier, 'source', value);
    });

    // Auto-save: Primary key "match against" select (SKU / Post ID / Post Title /
    // Custom Field). The stored 'wc' value is either a reserved sentinel
    // ('_sku', '__post_id', '__post_title') or, when "Custom Field" is chosen,
    // whatever meta key is picked/typed in the sibling custom-value controls —
    // see MMI_Pipeline_Field_Resolver::find_product_id_by_primary_key() for how
    // each is resolved at import time.
    $(document).on('change', '.primary-key-wc-select', function() {
        const $select = $(this);
        const supplier = $select.data('supplier');
        const value = $select.val();
        const $customSelect = $select.siblings('.primary-key-wc-custom-select');
        const $customInput  = $select.siblings('.primary-key-wc-custom-input');

        if (value === 'custom') {
            $customSelect.removeClass('mmi-is-hidden').trigger('focus');
            const selectValue = $customSelect.val();
            if (selectValue && selectValue !== '__custom__') {
                autosavePrimaryKey(supplier, 'wc', selectValue);
            } else if ($customInput.is(':visible') && $customInput.val()) {
                autosavePrimaryKey(supplier, 'wc', $customInput.val());
            }
            return;
        }

        $customSelect.addClass('mmi-is-hidden');
        $customInput.addClass('mmi-is-hidden');
        autosavePrimaryKey(supplier, 'wc', value);
    });

    // Auto-save: Primary key custom meta-key select — populated with the
    // store's known product meta keys from mmiImportSettings.productMetaKeys
    // (see the population loop further below). "__custom__" reveals the
    // plain-text fallback for a meta key that doesn't exist yet (importing is
    // what will create it).
    $(document).on('change', '.primary-key-wc-custom-select', function() {
        const $select = $(this);
        const supplier = $select.data('supplier');
        const value = $select.val();
        const $customInput = $select.siblings('.primary-key-wc-custom-input');

        if (value === '__custom__') {
            $customInput.removeClass('mmi-is-hidden').trigger('focus');
            const customValue = $customInput.val();
            if (customValue) {
                autosavePrimaryKey(supplier, 'wc', customValue);
            }
            return;
        }

        $customInput.addClass('mmi-is-hidden');
        autosavePrimaryKey(supplier, 'wc', value);
    });

    // Auto-save: Primary key custom meta-key input (only relevant while
    // "Custom / Other…" is selected in the select above). Ignores an empty
    // value instead of saving over a working config mid-keystroke, since the
    // field starts empty for a fresh "Custom Field" selection.
    $(document).on('change input', '.primary-key-wc-custom-input', function() {
        const $input = $(this);
        const supplier = $input.data('supplier');
        const value = $input.val();
        if (!value) {
            return;
        }
        autosavePrimaryKey(supplier, 'wc', value);
    });

    // Autosave primary key function — debounced per (supplier, fieldType), for
    // the identical reason autosaveFieldProperty() is above: a single shared
    // timer here meant editing SkuPort's primary key right after editing
    // Xchange's (well within 800ms — an entirely ordinary back-to-back edit,
    // not a stress case) silently cancelled Xchange's still-pending save
    // before it was ever sent. See AGENTS.md's 2026-09-01 changelog.
    function autosavePrimaryKeyNow(supplier, fieldType, value) {
        // Show saving indicator only when the debounce actually fires
        showAutosaveIndicator('💾 Saving...', 'saving');
        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_autosave_primary_key',
                nonce: mmiImportSettings.nonce,
                supplier: supplier,
                field_type: fieldType,
                value: value
            },
            success: function(response) {
                if (response.success) {
                    showAutosaveIndicator('✓ Saved', 'success');
                } else {
                    showAutosaveIndicator('✗ Failed', 'error');
                }
            },
            error: function() {
                showAutosaveIndicator('✗ Error', 'error');
            }
        });
    }

    function autosavePrimaryKey(supplier, fieldType, value) {
        const key = supplier + '|' + fieldType;
        const existing = pendingPrimaryKeySaves.get(key);
        if (existing) {
            clearTimeout(existing.timer);
        }
        const entry = { supplier, fieldType, value, timer: null };
        entry.timer = setTimeout(function() {
            pendingPrimaryKeySaves.delete(key);
            autosavePrimaryKeyNow(supplier, fieldType, value);
        }, 800);
        pendingPrimaryKeySaves.set(key, entry);
    }

    // Sends every still-pending debounced primary-key edit right now — wired
    // into flushAllPendingWizardAutosaves() alongside the field-property and
    // Attributes flushes, for the same "don't lose an edit made just before
    // the wizard closes" reason.
    function flushPendingPrimaryKeyAutosave() {
        if (pendingPrimaryKeySaves.size === 0) {
            return;
        }
        const entries = Array.from(pendingPrimaryKeySaves.values());
        pendingPrimaryKeySaves.clear();
        entries.forEach(function(entry) {
            clearTimeout(entry.timer);
            autosavePrimaryKeyNow(entry.supplier, entry.fieldType, entry.value);
        });
    }

    // ===== Inline primary-key quick-set (New Profile wizard, Source step) =====
    // Primary keys are supplier-scoped, not profile-scoped, so this reuses the
    // exact .primary-key-source-input/.primary-key-wc-select/
    // .primary-key-wc-custom-input classes above verbatim — those delegated
    // autosave handlers already fire regardless of where in the DOM the
    // elements live, keyed only by data-supplier. No new save endpoint needed.
    // (.primary-key-wc-select is now a radio group, not a <select> — the
    // shared handler above already reads $(this).val() and does
    // $(this).siblings('.primary-key-wc-custom-input'), both of which work
    // identically for a radio input as for a <select>, since every radio in
    // the group and the custom-input are still flat siblings in the DOM.)

    // Editing a source's primary key only matters once you're actually using
    // that source in this profile — the editor (now its own Step 3 row,
    // matched by data-supplier rather than DOM nesting — see
    // wizard-sources-pk-editor.php) is hidden until its Step 2 checkbox is
    // checked, and hides again if unchecked.
    $(document).on('change', '#new-profile-sources-list .np-source-check', function () {
        const supplierId = $(this).val();
        $('#new-profile-pk-editors-list .mmi-source-row[data-supplier="' + supplierId + '"]')
            .toggleClass('mmi-is-hidden', !this.checked);
        updateFieldMappingSupplierScope();
        refreshPresetDropdownForContext();

        // Clear the "select at least one source" error as soon as one is
        // checked, rather than waiting for the next Next-click validation.
        if ($('#new-profile-sources-list .np-source-check:checked').length) {
            $('#new-profile-source-error').addClass('mmi-is-hidden');
            $('#new-profile-sources-list').removeClass('mmi-field-error');
        }
    });

    // Field Mapping's per-supplier columns should only ever show the
    // source(s) this profile is actually scoped to in Step 3 — not every
    // supplier configured anywhere on the site. An empty selection keeps
    // Step 3's own stated default ("all currently-enabled sources"), so
    // nothing is hidden in that case. Also covers the group/profile-wide
    // default-file pickers (.group-file-default/.profile-file-default) —
    // same reasoning: a picker for a supplier not part of this profile has
    // nothing to apply itself to (every one of that supplier's
    // .field-file-selector rows is itself hidden by this same pass).
    function updateFieldMappingSupplierScope() {
        const checked = $('#new-profile-sources-list .np-source-check:checked')
            .map(function () { return this.value; }).get();
        const restrictToAssigned = checked.length > 0;
        $('.group-supplier-toggle-label, .supplier-source-row, .supplier-file-row, .group-file-default, .profile-file-default').each(function () {
            const $el = $(this);
            const sid = $el.data('supplier');
            const isAssigned = !restrictToAssigned || checked.indexOf(sid) !== -1;
            $el.toggleClass('mmi-supplier-not-in-profile', !isAssigned);
        });

        // The toolbar/group-header wrapper around those pickers has its own
        // static label ("Default file:") — hide the whole wrapper (not just
        // the now-empty-looking selects inside it) once every picker it
        // contains is scoped out, rather than leaving a label with nothing
        // next to it.
        $('.mmi-default-file-toolbar, .mmi-group-file-defaults').each(function () {
            const $wrap = $(this);
            const anyVisible = $wrap.find('select').not('.mmi-supplier-not-in-profile').length > 0;
            $wrap.toggleClass('mmi-is-hidden', !anyVisible);
        });

        updateFieldMappingSingleSourceState();
    }

    /**
     * Toggle a compact rendering mode on the Field Mapping table when the
     * profile currently has exactly one EFFECTIVE data source — see the
     * .mmi-single-source CSS rules in import-settings.css for what that
     * hides. Effective = assigned to this profile (or nothing explicitly
     * assigned) and not a disabled/unconfigured source. Computed from the
     * localized supplier list rather than .group-supplier-toggle-label
     * elements — those only render for groups that actually contain a
     * multi-supplier field, so a table where no group happens to have one
     * would otherwise never detect single-source mode.
     */
    function updateFieldMappingSingleSourceState() {
        const suppliers = (window.mmiImportSettings && mmiImportSettings.configuredSuppliers) || [];
        const checked = $('#new-profile-sources-list .np-source-check:checked')
            .map(function () { return this.value; }).get();
        const restrictToAssigned = checked.length > 0;
        const effectiveSuppliers = suppliers.filter(function (s) {
            return s.enabled && (!restrictToAssigned || checked.indexOf(s.supplier_id) !== -1);
        });
        const isSingle = effectiveSuppliers.length === 1;
        $('.mmi-field-mapping-table').toggleClass('mmi-single-source', isSingle);
    }
    // Exposed so import-pipeline-sources.js can recompute this after adding,
    // removing, or enabling/disabling a data source outside the wizard (the
    // main Data Sources tab).
    window.mmiUpdateFieldMappingSingleSourceState = updateFieldMappingSingleSourceState;

    // Optimistic status-line update — the actual persistence already happens
    // via the shared autosave handlers above; this just keeps the "Primary
    // Key: not set" / "✓ SKU" line (in Step 2's checklist,
    // wizard-sources-checklist.php) in sync without waiting for a wizard
    // reopen. Looked up by supplier id (every PK-editor input already
    // carries its own data-supplier — see wizard-sources-pk-editor.php)
    // rather than DOM traversal from the editor, since Step 2 (checklist +
    // status) and Step 3 (the editor itself) are no longer in the same
    // subtree.
    const PK_STATUS_LABELS = { _sku: 'SKU', __post_id: 'Post ID', __post_title: 'Post Title' };
    function updateSourcePkStatus(supplierId, displayValue) {
        const $status = $('#new-profile-sources-list .mmi-source-pk-status[data-supplier="' + supplierId + '"]');
        const label   = PK_STATUS_LABELS[displayValue] || displayValue || 'not set';
        const $statusEl = $('<span class="mmi-source-pk-ok"><span class="dashicons dashicons-yes-alt"></span></span>')
            .append(document.createTextNode(' Primary Key: ' + label));
        $status.find('.mmi-source-pk-missing, .mmi-source-pk-ok').remove();
        $status.append($statusEl);
    }
    $(document).on('change', '#new-profile-pk-editors-list .primary-key-wc-select', function () {
        const $radio = $(this);
        const value  = $radio.val();
        if (value !== 'custom') {
            updateSourcePkStatus($radio.data('supplier'), value);
        }
    });
    $(document).on('change', '#new-profile-pk-editors-list .primary-key-wc-custom-select', function () {
        const $select = $(this);
        const value = $select.val();
        if (value && value !== '__custom__') {
            updateSourcePkStatus($select.data('supplier'), value);
        }
    });
    $(document).on('change', '#new-profile-pk-editors-list .primary-key-wc-custom-input', function () {
        const $input = $(this);
        if ($input.val()) {
            updateSourcePkStatus($input.data('supplier'), $input.val());
        }
    });

    // NOTE: The .use-constant-checkbox change handler (UI + autosave) is defined
    // above in the AUTO-SAVE section. A second handler was previously registered
    // here, causing both to fire on every change. It has been removed.
    
    // Tab switching
    $('.mmi-import-tab-btn').on('click', function() {
        const tabName = $(this).data('tab');
        
        $('.mmi-import-tab-btn').removeClass('active');
        $(this).addClass('active');
        
        $('.mmi-import-tab-content').removeClass('active');
        $(`.mmi-import-tab-content[data-tab-content="${tabName}"]`).addClass('active');
    });
    
    // Close panel handler
    $('.mmi-close-panel').on('click', function() {
        const panelId = $(this).data('panel');
        $('#' + panelId).fadeOut(300);
    });
    
    // ── Import batch loop ─────────────────────────────────────────────────────
    // Functions are module-scoped so the loop can be resumed on page reload
    // without needing the user to click "Run Import Now" again.

    const IMPORT_AJAX_URL        = (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl;
    const BTN_LABEL_RUNNING      = '<span class="mmi-loading"></span> Starting import...';
    const BTN_LABEL_DEFAULT      = '<span class="dashicons dashicons-controls-play"></span> Run Import Now';

    // Mutable state shared between the click handler and the resume-on-load path
    let importRunId  = 0;
    let importAborted = false;
    let batchErrorCount = 0;          // consecutive AJAX errors — give up after threshold
    const MAX_BATCH_ERRORS = 5;       // 5 consecutive errors ≈ 10+ seconds of failures

    const $importBtn    = $('#run-manual-import');
    const $importStatus = $('#manual-import-status');

    /**
     * Process one time-bounded batch on the server, update the UI, then call
     * itself again until the import is complete, failed, or aborted.
     */
    function continueBatch() {
        if (importAborted) return;

        $.ajax({
            url:    IMPORT_AJAX_URL,
            method: 'POST',
            // 5-minute hard timeout: each batch is bounded by 8 s on the server;
            // this guards against a hung PHP-FPM worker never sending a response.
            timeout: 300000,
            data: {
                action: 'mmi_pipeline_process_import_batch',
                nonce:  (window.mmiImportSettings && window.mmiImportSettings.importNonce) || '',
            },
            success: function(response) {
                batchErrorCount = 0;    // reset on any successful response
                if (!response.success || !response.data) {
                    finishImport('failed', null);
                    return;
                }
                const d = response.data;

                if (d.status === 'running') {
                    renderImportProgress(d);
                    // Small yield so the browser can repaint, then continue immediately
                    setTimeout(continueBatch, 50);
                    return;
                }

                finishImport(d.status, d);
            },
            error: function() {
                batchErrorCount++;
                if (batchErrorCount >= MAX_BATCH_ERRORS) {
                    finishImport('failed', null);
                    return;
                }
                // Transient network error — wait 2 s and retry rather than aborting
                setTimeout(continueBatch, 2000);
            },
        });
    }

    /** Render live progress bar and status text. */
    function renderImportProgress(d) {
        const offset = d.offset || 0;
        const total  = d.total  || 0;
        const pct    = total > 0 ? Math.round((offset / total) * 100) : 0;
        const updated   = d.updated   || 0;
        const unchanged = d.unchanged || 0;
        const skipped   = d.skipped   || 0;

        // Show a "working" label while a batch is in-flight (offset = 0 before first result)
        const statusLabel = d.starting
            ? '<span class="mmi-loading"></span> <strong>Working\u2026</strong> scanning products'
            : '<strong>Processing\u2026</strong> ' + offset + '\u202f/\u202f' + total + ' (' + pct + '%)'
                + ' &mdash; <span class="mmi-import-progress-updated">' + updated + ' updated</span>'
                + ', <span class="mmi-import-progress-muted">' + (unchanged + skipped) + ' unchanged/skipped</span>';

        $importStatus.html(
            '<div class="mmi-mb-sm">' + statusLabel + '</div>'
            + '<div class="mmi-progress-track">'
                + '<div class="mmi-progress-fill" style="--pct:' + (d.starting ? 2 : pct) + '%"></div>'
            + '</div>'
            + '<div class="mmi-mt-sm">'
                + '<button id="mmi-abort-import" class="button mmi-abort-btn">'
                + '\u23f9 Abort Import</button>'
            + '</div>'
        );
    }

    /** Handle import completion (complete, failed, aborted). */
    function finishImport(status, d) {
        $importBtn.prop('disabled', false).removeClass('mmi-is-loading').html(BTN_LABEL_DEFAULT).css('min-width', '');

        if (status === 'complete') {
            const imported  = d ? (d.imported  || 0) : 0;
            const updated   = d ? (d.updated   || 0) : 0;
            const unchanged = d ? (d.unchanged || 0) : 0;
            const skipped   = d ? (d.skipped   || 0) : 0;
            const failed    = d ? (d.failed    || 0) : 0;

            let html = '<div class="mmi-result-card mmi-result-card--success">';
            html += '<h4 class="mmi-m-0 mmi-text-success">\u2705 Import Completed Successfully</h4>';
            if (imported  > 0) html += '<p><strong>Created:</strong> '   + imported   + ' new products</p>';
            if (updated   > 0) html += '<p><strong>Updated:</strong> '   + updated    + ' products</p>';
            if (unchanged > 0) html += '<p><strong>Unchanged:</strong> ' + unchanged  + ' products (skipped \u2014 no changes needed)</p>';
            if (skipped   > 0) html += '<p><strong>Skipped:</strong> '   + skipped    + ' products</p>';
            if (failed    > 0) html += '<p class="mmi-text-error"><strong>Failed:</strong> '    + failed     + ' products</p>';

            // Per-product failure reasons (server caps the detail list; count above is exact)
            const failures = d && Array.isArray(d.failures) ? d.failures : [];
            if (failures.length > 0) {
                const failuresLabel = failures.length < failed
                    ? 'Failure details (first ' + failures.length + ' of ' + failed + ')'
                    : 'Failure details (' + failures.length + ' product' + (failures.length !== 1 ? 's' : '') + ')';
                html += '<details class="mmi-result-details">';
                html += '<summary class="mmi-result-summary mmi-text-error">'
                      + failuresLabel
                      + '</summary>';
                html += '<table class="mmi-result-table">';
                html += '<thead><tr class="mmi-result-thead--error">'
                      + '<th class="mmi-th-compact mmi-text-left">Product</th>'
                      + '<th class="mmi-th-compact mmi-text-left">Supplier</th>'
                      + '<th class="mmi-th-compact mmi-text-left">Reason</th>'
                      + '</tr></thead><tbody>';
                failures.forEach(function(row) {
                    const productLabel = row.title
                        ? row.title + ' (' + (row.key || '?') + ')'
                        : (row.key || '(unknown)');
                    html += '<tr class="mmi-result-row--error">'
                          + '<td class="mmi-td-compact"><code class="mmi-code-pill mmi-badge error mmi-code-pill--error">'
                          + $('<div>').text(productLabel).html() + '</code></td>'
                          + '<td class="mmi-td-compact mmi-text-neutral">' + $('<div>').text(row.supplier || '').html() + '</td>'
                          + '<td class="mmi-td-compact mmi-text-failure">' + $('<div>').text(row.reason || 'Unknown error').html() + '</td>'
                          + '</tr>';
                });
                html += '</tbody></table></details>';
            }

            // Field-change breakdown (absorbed from post-mortem section)
            const topChanges = d && Array.isArray(d.top_changes) ? d.top_changes : [];
            const fcRunId    = d && d.run_id ? d.run_id : importRunId;
            if (topChanges.length > 0) {
                html += '<details class="mmi-result-details">';
                html += '<summary class="mmi-result-summary mmi-text-success">'
                      + 'Field changes (' + topChanges.length + ' field' + (topChanges.length !== 1 ? 's' : '') + ')'
                      + '</summary>';
                html += '<table class="mmi-result-table">';
                html += '<thead><tr class="mmi-result-thead--success">'
                      + '<th class="mmi-th-compact mmi-text-left">Field</th>'
                      + '<th class="mmi-th-compact mmi-text-left">Supplier</th>'
                      + '<th class="mmi-th-compact mmi-text-right">Changed</th>'
                      + '<th class="mmi-th-compact mmi-text-right">of Total</th>'
                      + '<th class="mmi-th-compact mmi-th-compact--wide"></th>'
                      + '</tr></thead><tbody>';
                topChanges.forEach(function(row) {
                    const pct = row.total > 0 ? ((row.changed / row.total) * 100).toFixed(1) : '0.0';
                    const barW = Math.min(parseFloat(pct), 100);
                    const canSpotCheck = fcRunId && row.changed > 0;
                    html += '<tr class="mmi-result-row--success'
                          + (canSpotCheck ? ' mmi-fc-row--expandable' : '') + '"'
                          + (canSpotCheck
                                ? ' role="button" tabindex="0" data-run-id="' + fcRunId + '"'
                                  + ' data-supplier="' + escHtml(row.supplier) + '"'
                                  + ' data-field="' + escHtml(row.field) + '"'
                                : '')
                          + '>'
                          + '<td class="mmi-td-compact">'
                          + (canSpotCheck ? '<span class="dashicons dashicons-arrow-right-alt2 mmi-fc-chevron"></span> ' : '')
                          + '<code class="mmi-code-pill mmi-badge success mmi-code-pill--success">'
                          + $('<div>').text(row.field).html() + '</code></td>'
                          + '<td class="mmi-td-compact mmi-text-neutral">' + $('<div>').text(row.supplier).html() + '</td>'
                          + '<td class="mmi-td-compact mmi-text-right mmi-text-success-strong">' + (row.changed || 0).toLocaleString() + '</td>'
                          + '<td class="mmi-td-compact mmi-text-right mmi-text-muted">' + pct + '%</td>'
                          + '<td class="mmi-td-compact">'
                          + '<div class="mmi-mini-progress-track">'
                          + '<div class="mmi-mini-progress-fill" style="--bar-pct:' + barW + '%"></div>'
                          + '</div></td>'
                          + '</tr>';
                    if (canSpotCheck) {
                        html += '<tr class="mmi-fc-detail-row mmi-is-hidden">'
                              + '<td class="mmi-td-compact" colspan="5"><div class="mmi-fc-detail-body"></div></td>'
                              + '</tr>';
                    }
                });
                html += '</tbody></table></details>';
            }

            html += '</div>';
            $importStatus.html(html);
            $(document).trigger('mmi:importComplete', [d ? d.run_id || importRunId : importRunId]);
            refreshHistoryTable();

        } else if (status === 'aborted') {
            const updated   = d ? (d.updated   || 0) : 0;
            const unchanged = d ? (d.unchanged || 0) : 0;
            $importStatus.html(
                '<div class="mmi-result-card mmi-result-card--warning">'
                + '<h4 class="mmi-m-0 mmi-text-warning">\u23f9 Import Aborted</h4>'
                + '<p>' + updated + ' products updated, ' + (unchanged) + ' unchanged before abort.</p>'
                + '</div>'
            );
            refreshHistoryTable();

        } else {
            $importStatus.html(
                '<div class="mmi-result-card mmi-result-card--error">'
                + '<p class="mmi-text-error mmi-m-0"><strong>Import failed.</strong> Check the process logs for details.</p></div>'
            );
        }
    }

    /* ── Field-change row-expand spot-check ─────────────────────────────
     * Lets an admin expand any "Field changes" row (mmi-result-row--success)
     * to see exactly which products it affected — a paginated, filtered view
     * over MMI_DB::get_field_change_items(), similar to checking a log but
     * visual and scoped to just this one field's changes. */
    const FC_PAGE_SIZE = 25;
    const fcPageState   = {}; // cache key -> current page number, so re-collapsing/re-expanding remembers where you were

    function fcCacheKey(runId, supplier, field) {
        return runId + '|' + supplier + '|' + field;
    }

    function fcRenderPage($body, runId, supplier, field, page) {
        $body.html('<div class="mmi-fc-loading"><span class="mmi-loading"></span> Loading products…</div>');

        $.ajax({
            url:    IMPORT_AJAX_URL,
            method: 'POST',
            data: {
                action:   'mmi_pipeline_get_field_change_products',
                nonce:    (window.mmiImportSettings && window.mmiImportSettings.importNonce) || '',
                run_id:   runId,
                supplier: supplier,
                field:    field,
                page:     page,
            },
            success: function(response) {
                if (!response.success || !response.data) {
                    $body.html('<p class="mmi-text-error mmi-m-0">Could not load products for this field.</p>');
                    return;
                }
                fcPageState[fcCacheKey(runId, supplier, field)] = page;

                const items      = response.data.items || [];
                const total      = response.data.total || 0;
                const totalPages = response.data.total_pages || 1;

                if (items.length === 0) {
                    $body.html('<p class="mmi-text-muted mmi-m-0">No product detail was recorded for this field (older run, or the change count exceeded the detail limit).</p>');
                    return;
                }

                let rows = '';
                items.forEach(function(item) {
                    const title = item.title || '(untitled)';
                    const sku   = item.sku || '';
                    const link  = item.edit_url
                        ? '<a href="' + escHtml(item.edit_url) + '" target="_blank" rel="noopener noreferrer">' + $('<div>').text(title).html() + '</a>'
                        : $('<div>').text(title).html();
                    rows += '<tr>'
                          + '<td class="mmi-td-compact">' + link + (sku ? ' <span class="mmi-text-muted">(' + $('<div>').text(sku).html() + ')</span>' : '') + '</td>'
                          + '<td class="mmi-td-compact mmi-text-muted">' + $('<div>').text(item.old_value || '—').html() + '</td>'
                          + '<td class="mmi-td-compact mmi-text-success-strong">' + $('<div>').text(item.new_value || '—').html() + '</td>'
                          + '</tr>';
                });

                let html = '<table class="mmi-fc-detail-table">'
                         + '<thead><tr><th class="mmi-th-compact mmi-text-left">Product</th>'
                         + '<th class="mmi-th-compact mmi-text-left">Old value</th>'
                         + '<th class="mmi-th-compact mmi-text-left">New value</th></tr></thead>'
                         + '<tbody>' + rows + '</tbody></table>';

                html += '<div class="mmi-fc-total-label">' + total.toLocaleString() + ' total</div>';
                if (totalPages > 1) {
                    html += '<div class="mmi-fc-pagination-mount"></div>';
                }

                $body.html(html);

                /* Shared "Compact" pagination component (mmi-pagination.js) —
                   reinitialized fresh on every render since $body.html()
                   above destroys and recreates the mount each time; this
                   file's own fcPageState still owns "which page was open"
                   across collapse/re-expand, MMIPagination only owns the
                   prev/next click handling and disabled-state rendering. */
                if (totalPages > 1) {
                    MMIPagination.init({
                        container: $body.find('.mmi-fc-pagination-mount'),
                        totalPages: totalPages,
                        page: page,
                        onPageChange: function (nextPage) {
                            fcRenderPage($body, runId, supplier, field, nextPage);
                        },
                    });
                }
            },
            error: function() {
                $body.html('<p class="mmi-text-error mmi-m-0">Could not load products for this field.</p>');
            },
        });
    }

    /* Toggle on row click (mirrors this project's other click-to-expand rows) */
    $importStatus.on('click keydown', '.mmi-fc-row--expandable', function(e) {
        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') {
            return;
        }
        e.preventDefault();

        const $row    = $(this);
        const $detail = $row.next('.mmi-fc-detail-row');
        const $body   = $detail.find('.mmi-fc-detail-body');
        const runId   = $row.data('run-id');
        const supplier = String($row.data('supplier'));
        const field     = String($row.data('field'));

        $row.toggleClass('mmi-is-active');
        $detail.toggleClass('mmi-is-hidden');

        if (!$detail.hasClass('mmi-is-hidden') && $body.is(':empty')) {
            const startPage = fcPageState[fcCacheKey(runId, supplier, field)] || 1;
            fcRenderPage($body, runId, supplier, field, startPage);
        }
    });

    /* Pagination clicks inside an expanded detail row are handled by the
       MMIPagination instance created in fcRenderPage() itself — see there. */

    /* ── Abort handler (delegated so it works on dynamically inserted button) */
    $importStatus.on('click', '#mmi-abort-import', function() {
        const $abortBtn = $(this);
        $abortBtn.prop('disabled', true).text('Aborting\u2026');
        importAborted = true;

        $.ajax({
            url:    IMPORT_AJAX_URL,
            method: 'POST',
            data: { action: 'mmi_pipeline_abort_import', nonce: (window.mmiImportSettings && window.mmiImportSettings.importNonce) || '' },
            success: function(response) {
                const d = response.success ? response.data : null;
                finishImport('aborted', d);
            },
            error: function() {
                // If the abort request itself fails, still stop the client loop
                finishImport('aborted', null);
            },
        });
    });

    // ── History table refresh ─────────────────────────────────────────────────
    /**
     * Reload the import history tbody via AJAX — after an import finishes,
     * or on demand from the "Load Full History" button (see the initial-page
     * fetch-size comment in tab-pipeline.php).
     *
     * @param {function():void} [onDone] Called once the tbody has actually
     *   been repopulated (or the request settled without new rows) — the
     *   "Load Full History" button uses this to remove itself only once the
     *   real data has arrived, instead of disappearing before the request
     *   completes.
     */
    function refreshHistoryTable(onDone) {
        const $tbody = $('#mmi-history-tbody');
        if (!$tbody.length) { if (onDone) onDone(); return; }

        $.ajax({
            url:    IMPORT_AJAX_URL,
            method: 'POST',
            data: {
                action: 'mmi_pipeline_get_import_history',
                nonce:  (window.mmiImportSettings && window.mmiImportSettings.importNonce) || '',
            },
            complete: function() { if (onDone) onDone(); },
            success: function(response) {
                if (!response.success || !response.data || !response.data.history) return;

                const history      = response.data.history;
                const profileNames = (window.mmiImportSettings && window.mmiImportSettings.profileNames) || {};

                /** Escape a string for use inside an HTML attribute value. */
                function escAttr(s) { return $('<div>').text(String(s || '')).html().replace(/"/g, '&quot;'); }
                /** Escape a string for HTML text content. */
                function esc(s)     { return window.MMIEscapeHtml(s); }

                function ucfirst(str) {
                    if (!str) return '';
                    return str.charAt(0).toUpperCase() + str.slice(1);
                }

                function formatDuration(secs) {
                    if (!secs || secs <= 0) return '\u2014';
                    secs = Math.round(secs);
                    const h = Math.floor(secs / 3600);
                    const m = Math.floor((secs % 3600) / 60);
                    const s = secs % 60;
                    if (h > 0) return h + 'h ' + m + 'm ' + s + 's';
                    if (m > 0) return m + 'm ' + s + 's';
                    return s + 's';
                }

                function formatDate(mysqlDate) {
                    if (!mysqlDate) return '\u2014';
                    const d = new Date(mysqlDate.replace(' ', 'T'));
                    if (isNaN(d.getTime())) return mysqlDate;
                    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                    const h    = d.getHours();
                    const ampm = h >= 12 ? 'pm' : 'am';
                    const h12  = h % 12 || 12;
                    const min  = String(d.getMinutes()).padStart(2, '0');
                    return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear()
                        + ' ' + h12 + ':' + min + ' ' + ampm;
                }

                function fmtNum(n) { return parseInt(n || 0, 10).toLocaleString(); }

                const rows = history.map(function(entry) {
                    const notes = (function() {
                        try { return entry.notes ? JSON.parse(entry.notes) : {}; }
                        catch(e) { return {}; }
                    })();

                    let durSecs = notes.duration_seconds ? parseInt(notes.duration_seconds, 10) : null;
                    if (durSecs === null && entry.completed_at && entry.started_at) {
                        const diff = (new Date(entry.completed_at.replace(' ', 'T')) - new Date(entry.started_at.replace(' ', 'T'))) / 1000;
                        if (diff > 0) { durSecs = Math.round(diff); }
                    }
                    const duration = formatDuration(durSecs);

                    const profileId   = entry.profile_id || 'default';
                    const profileName = profileNames[profileId] || ucfirst(profileId);

                    const status   = entry.status || 'Unknown';
                    const imported = parseInt(entry.imported || 0, 10);
                    const updated  = parseInt(entry.updated  || 0, 10);
                    const skipped  = parseInt(entry.skipped  || 0, 10);
                    const errors   = parseInt(entry.errors   || 0, 10);
                    const totOps   = imported + updated + skipped + errors;

                    // A profile with the "Skip if no new data" schedule toggle
                    // on (see MMI_Pipeline_Cron::run_scheduled_profile_import())
                    // never even started a real run \u2014 a deliberate, expected
                    // outcome, not the same thing 'isNoData' below means (a run
                    // that DID execute but found zero products from its
                    // suppliers, usually a config problem worth flagging).
                    const isSkipped = status === 'Skipped';
                    const isNoData  = ! isSkipped && (status === 'Success' || status === 'Partial Success') && totOps === 0;

                    let displayStatus;
                    if (isSkipped)                                            { displayStatus = 'Skipped'; }
                    else if (isNoData)                                        { displayStatus = 'No Data'; }
                    else if (status === 'Partial' || status === 'Partial Success') { displayStatus = 'Partial'; }
                    else                                                      { displayStatus = status; }

                    let badgeClass;
                    if (isSkipped)                                            { badgeClass = 'no-data'; }
                    else if (isNoData)                                        { badgeClass = 'no-data'; }
                    else if (status === 'Success')                            { badgeClass = 'success'; }
                    else if (status === 'Partial' || status === 'Partial Success') { badgeClass = 'partial'; }
                    else if (status === 'Aborted')                            { badgeClass = 'aborted'; }
                    else if (status === 'Failed' || status === 'Error')       { badgeClass = 'error'; }
                    else if (status === 'In Progress')                        { badgeClass = 'in-progress'; }
                    else                                                      { badgeClass = 'unknown'; }

                    if ((status === 'Partial' || status === 'Partial Success') && errors > 0) {
                        displayStatus += ' (' + errors.toLocaleString() + ' failed)';
                    }

                    let badgeTitle = '';
                    if (isSkipped && notes.reason === 'no_new_data') {
                        badgeTitle = 'Skipped \u2014 no new/changed source data was pending for this profile.';
                    } else if (isSkipped)          { badgeTitle = 'Skipped.'; }
                    else if (isNoData)             { badgeTitle = 'No products found \u2014 check enabled suppliers and data fetch status.'; }
                    else if (notes.error)          { badgeTitle = 'Error: ' + notes.error; }
                    else if (notes.aborted)        { badgeTitle = 'Stopped by user.'; }
                    else if (status === 'In Progress') { badgeTitle = 'Import is currently running.'; }

                    const titleAttr = badgeTitle ? ' title="' + escAttr(badgeTitle) + '"' : '';

                    return '<tr data-profile="' + escAttr(profileId) + '">'
                         + '<td><abbr title="' + escAttr(entry.started_at || '') + '">' + esc(formatDate(entry.started_at)) + '</abbr></td>'
                         + '<td>' + esc(profileName) + '</td>'
                         + '<td>' + esc(duration) + '</td>'
                         + '<td>' + fmtNum(imported) + '</td>'
                         + '<td>' + fmtNum(updated) + '</td>'
                         + '<td>' + fmtNum(skipped) + '</td>'
                         + '<td>' + fmtNum(errors) + '</td>'
                         + '<td><span class="history-status-badge mmi-badge ' + badgeClass + '"' + titleAttr + '>' + esc(displayStatus) + '</span></td>'
                         + '</tr>';
                });

                $tbody.html(rows.join(''));

                // Re-apply any active filter pill.
                const $activeBtn = $('.mmi-history-filter-btn.is-active');
                if ($activeBtn.length) {
                    const activeProfile = $activeBtn.data('filter-profile');
                    if (activeProfile !== '') {
                        $tbody.find('tr').each(function() {
                            $(this).toggleClass('mmi-hidden', $(this).data('profile') !== activeProfile);
                        });
                    }
                }
            },
        });
    }

    // "Load Full History" — the page renders only a small initial slice of
    // import history (see tab-pipeline.php's $mmi_history_initial_limit),
    // since this section starts collapsed and a full 500-row render was
    // shipping ~400KB of table markup on every single page load. Reuses
    // refreshHistoryTable() (already built for post-import refreshes) to
    // pull the AJAX endpoint's own 50-row default on demand instead.
    $(document).on('click', '#mmi-history-load-full', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).text('Loading…');
        refreshHistoryTable(function() { $btn.remove(); });
    });

    // ── History filter pills ──────────────────────────────────────────────────
    $(document).on('click', '.mmi-history-filter-btn', function() {
        $('.mmi-history-filter-btn').removeClass('is-active');
        $(this).addClass('is-active');
        const filterProfile = $(this).data('filter-profile');
        $('#mmi-history-tbody tr').each(function() {
            $(this).toggleClass('mmi-hidden', filterProfile !== '' && $(this).data('profile') !== filterProfile);
        });
    });

    // ── Page-load resume ──────────────────────────────────────────────────────
    // If a import was running when the user refreshed, pick up where it left off.
    if ($importBtn.length && $importStatus.length) {
        $.ajax({
            url:    IMPORT_AJAX_URL,
            method: 'POST',
            data: { action: 'mmi_pipeline_import_status', nonce: (window.mmiImportSettings && window.mmiImportSettings.importNonce) || '' },
            success: function(response) {
                if (!response.success || !response.data) return;
                const d = response.data;
                if (d.status === 'running') {
                    // An import is mid-flight — show the current progress and rejoin the loop
                    importRunId  = d.run_id || 0;
                    importAborted = false;
                    batchErrorCount = 0;
                    $importBtn.css('min-width', $importBtn.outerWidth() + 'px').prop('disabled', true).addClass('mmi-is-loading').html(BTN_LABEL_RUNNING);
                    renderImportProgress(d);
                    continueBatch();
                }
            },
        });
    }

    /**
     * Run mmi_pipeline_run_manual_import for the given profile, then drive the
     * batch loop to completion. Assumes the button is already in its loading state.
     */
    function startImport(profile) {
        $importStatus.html('<p>Starting import, please wait\u2026</p>');

        /* ── register the import and get run_id + total ──── */
        $.ajax({
            url:    IMPORT_AJAX_URL,
            method: 'POST',
            data: {
                action:  'mmi_pipeline_run_manual_import',
                nonce:   (window.mmiImportSettings && window.mmiImportSettings.importNonce) || '',
                profile: profile,
            },
            success: function(response) {
                if (!response.success) {
                    $importBtn.prop('disabled', false).removeClass('mmi-is-loading').html(BTN_LABEL_DEFAULT).css('min-width', '');
                    const data = response.data || {};
                    let boxClass, msg;
                    if (data.no_products) {
                        boxClass = 'mmi-result-card mmi-result-card--warning';
                        msg = '\u26a0\ufe0f <strong>No products found.</strong> '
                            + escHtml(data.message || 'Please run a Data Fetch first and verify your data sources are enabled.');
                    } else if (data.already_running) {
                        boxClass = 'mmi-result-card mmi-result-card--error';
                        msg = '\u26a0\ufe0f An import is already running. Please wait for it to finish before starting another.';
                    } else {
                        boxClass = 'mmi-result-card mmi-result-card--error';
                        msg = '<strong>Error:</strong> ' + escHtml(data.message || 'Unknown error');
                    }
                    $importStatus.html('<div class="' + boxClass + '"><p class="mmi-m-0">' + msg + '</p></div>');
                    return;
                }

                const d = response.data;
                importRunId = d.run_id || 0;
                renderImportProgress({ status: 'running', offset: 0, total: d.total || 0, starting: true });

                // kick off the first batch immediately
                continueBatch();
            },
            error: function() {
                $importBtn.prop('disabled', false).removeClass('mmi-is-loading').html(BTN_LABEL_DEFAULT).css('min-width', '');
                $importStatus.html(
                    '<div class="mmi-result-card mmi-result-card--error">'
                    + '<p class="mmi-text-error mmi-m-0">AJAX request failed \u2014 could not start import.</p></div>'
                );
            },
        });
    }

    /**
     * Render a list of pre-flight config issues into $importStatus \u2014 the same
     * classed panel markup MMI_Pipeline_Config_Validator::render_html() and
     * the wizard's own renderWizardFieldIssues() already use, rather than a
     * separate ad-hoc inline-styled block, so all three read as one visual
     * language (see mmi-config-issues-* in import-settings.css).
     *
     * Each issue that names a `field` gets a "Go to field \u2192" link, jumping
     * straight to that field's row in the profile's Field Mapping step
     * (openProfileWizardToField()) \u2014 the alert used to be a wall of text with
     * no way to act on it beyond a plain OK/Cancel. Non-blocking (warning)
     * issues also get explicit "Run Import Anyway"/"Cancel" buttons in place
     * of the previous window.confirm() dialog, so proceeding or backing out
     * is a deliberate, visible choice made from the same panel that explains
     * what's wrong, not a bare native prompt.
     *
     * @param {Array}    issues   Array of {message, field?, severity?} objects.
     * @param {boolean}  blocking If true, render as a hard "cannot run" error
     *                            with no proceed option.
     * @param {string}   profile  The profile these issues were validated
     *                            against \u2014 needed for the "Go to field" link.
     * @param {function} [onProceed] Called if the user clicks "Run Import
     *                            Anyway". Only offered when !blocking.
     */
    function renderConfigIssues(issues, blocking, profile, onProceed) {
        const panelClass = blocking ? 'mmi-config-issues-critical' : 'mmi-config-issues-warning';
        const heading = blocking
            ? 'Cannot run import \u2014 configuration issues found'
            : 'Configuration warnings \u2014 review before running';

        let html = '<div class="mmi-config-issues-panel ' + panelClass + '">';
        html += '<h4 class="mmi-config-issues-heading"><span class="dashicons dashicons-warning"></span> ' + heading + '</h4>';
        html += '<ul class="mmi-config-issues-list">';
        issues.forEach(function (issue) {
            const issueClass = (issue.severity === 'critical') ? 'mmi-config-issue-critical' : 'mmi-config-issue-warning';
            const icon       = (issue.severity === 'critical') ? 'dashicons-dismiss' : 'dashicons-flag';
            html += '<li class="mmi-config-issue ' + issueClass + '"><span class="dashicons ' + icon + '"></span><span>';
            html += $('<div>').text(issue.message).html();
            if (issue.field) {
                html += ' <a href="#" class="mmi-config-issue-goto-field" data-field="'
                      + escHtml(issue.field) + '">Go to field \u2192</a>';
            }
            html += '</span></li>';
        });
        html += '</ul>';
        html += '<p class="mmi-config-issues-footer">Fix these in <strong>Field Mappings</strong> '
              + '(or <strong>Data Sources</strong> for primary key/feed issues)'
              + (blocking ? ' before running this import.' : ' \u2014 or proceed if you\u2019re confident they don\u2019t apply.')
              + '</p>';

        if (!blocking) {
            html += '<div class="mmi-config-issues-actions">'
                  + '<button type="button" class="mmi-btn-profile mmi-btn-profile--primary" id="mmi-config-issues-proceed">Run Import Anyway</button>'
                  + '<button type="button" class="mmi-btn-profile" id="mmi-config-issues-cancel">Cancel</button>'
                  + '</div>';
        }
        html += '</div>';
        $importStatus.html(html);

        $importStatus.find('.mmi-config-issue-goto-field').on('click', function (e) {
            e.preventDefault();
            openProfileWizardToField(profile, $(this).data('field'));
        });

        if (!blocking) {
            $importStatus.find('#mmi-config-issues-proceed').on('click', function () {
                if (typeof onProceed === 'function') { onProceed(); }
            });
            $importStatus.find('#mmi-config-issues-cancel').on('click', function () {
                $importBtn.prop('disabled', false).removeClass('mmi-is-loading').html(BTN_LABEL_DEFAULT).css('min-width', '');
                $importStatus.html('');
            });
        }
    }

    // ── Run manual import (click handler) ─────────────────────────────────────
    // Flow: pre-flight config check → start → server sets up state → JS calls
    // mmi_pipeline_process_import_batch repeatedly until status is 'complete',
    // 'failed', or 'aborted'.
    $importBtn.on('click', function() {
        $importBtn.css('min-width', $importBtn.outerWidth() + 'px').prop('disabled', true).addClass('mmi-is-loading').html(BTN_LABEL_RUNNING);
        $importStatus.html('<p>Checking configuration\u2026</p>');
        importAborted = false;
        batchErrorCount = 0;

        const profile = (typeof currentProfile !== 'undefined') ? currentProfile : 'default';

        /* ── Step 0: pre-flight config validation ──── */
        $.ajax({
            url:    IMPORT_AJAX_URL,
            method: 'POST',
            data: {
                action:  'mmi_pipeline_validate_profile_config',
                nonce:   (window.mmiImportSettings && window.mmiImportSettings.importNonce) || '',
                profile: profile,
            },
            success: function(response) {
                const data     = (response.success && response.data) ? response.data : {};
                const critical = data.critical || [];
                const warning  = data.warning  || [];

                if (critical.length > 0) {
                    $importBtn.prop('disabled', false).removeClass('mmi-is-loading').html(BTN_LABEL_DEFAULT).css('min-width', '');
                    renderConfigIssues(critical, true, profile);
                    return;
                }

                if (warning.length > 0) {
                    $importBtn.prop('disabled', false).removeClass('mmi-is-loading').html(BTN_LABEL_DEFAULT).css('min-width', '');
                    renderConfigIssues(warning, false, profile, function () {
                        $importBtn.css('min-width', $importBtn.outerWidth() + 'px').prop('disabled', true).addClass('mmi-is-loading').html(BTN_LABEL_RUNNING);
                        startImport(profile);
                    });
                    return;
                }

                startImport(profile);
            },
            error: function() {
                // Validation endpoint itself failing shouldn't block the import —
                // proceed and let the normal run/error handling take over.
                startImport(profile);
            },
        });
    });
    
    // Reset to defaults. Delegated — #reset-default-mappings lives inside
    // the lazily-AJAX-loaded field mapping table (see
    // wizardMaybeLoadFieldMappingPanel()).
    $(document).on('click', '#reset-default-mappings', function() {
        if (confirm('Are you sure you want to reset all field mappings to their default values? This cannot be undone.')) {
            location.reload(); // Simple reset by reloading page
        }
    });

    /* Field Mapping presets — explicit, named snapshots a user can save from
     * one profile and apply to another. See ImportSettingsController.php's
     * mmi_save/apply/delete_field_mapping_preset AJAX actions. Presets
     * themselves are the only thing shared across profiles, and only when
     * the user explicitly asks for it — the mappings underneath stay
     * profile-scoped exactly as before.
     *
     * Every preset carries a data_type + sources[] captured automatically
     * from the wizard's own state at save time — a preset built for
     * Product+Xchange has no meaningful use while working on a Taxonomy
     * profile, or one with a different source checked. refreshPresetDropdownForContext()
     * re-filters the full list (stored once here, not re-fetched per change)
     * against wizardCurrentDataType()/wizardSelectedSources() so the dropdown
     * never offers a preset the current wizard state can't actually use. */
    let _wizardAllFieldMappingPresets = [];

    function presetMatchesWizardContext(preset) {
        if ((preset.data_type || 'product') !== wizardCurrentDataType()) {
            return false;
        }
        const required = preset.sources || [];
        if (!required.length) {
            return true; // Unrestricted by source (legacy preset, or built that way on purpose).
        }
        const checked = wizardSelectedSources();
        return required.every(function (sid) { return checked.indexOf(sid) !== -1; });
    }

    function refreshPresetDropdownForContext() {
        const $select = $('#field-mapping-preset-select');
        if (!$select.length) { return; } // Not on Panel 3 (or the generic field-mapping panel) right now.

        const previousValue = $select.val();
        $select.empty().append('<option value="">— Apply a saved preset —</option>');
        _wizardAllFieldMappingPresets.filter(presetMatchesWizardContext).forEach(function (p) {
            $select.append(
                $('<option>').attr('value', p.id).attr('data-builtin', p.builtin ? '1' : '0').text(p.name)
            );
        });
        // Keep the current selection if it's still eligible; otherwise reset —
        // never leave a stale selection whose preset no longer matches this context.
        if (previousValue && $select.find('option[value="' + previousValue + '"]').length) {
            $select.val(previousValue);
        }
        $select.trigger('change');
    }

    function populatePresetDropdown(presets) {
        _wizardAllFieldMappingPresets = presets || [];
        refreshPresetDropdownForContext();
    }
    populatePresetDropdown((window.mmiImportSettings && mmiImportSettings.fieldMappingPresets) || []);

    $(document).on('change', '#field-mapping-preset-select', function () {
        const hasSelection = !!$(this).val();
        const isBuiltin    = $(this).find('option:selected').attr('data-builtin') === '1';
        $('#apply-field-mapping-preset').prop('disabled', !hasSelection);
        $('#delete-field-mapping-preset').prop('disabled', !hasSelection || isBuiltin);
    });

    $(document).on('click', '#save-field-mapping-preset', function () {
        const name = prompt('Name this preset (e.g. "Standard Product Feed"):');
        if (!name || !name.trim()) { return; }

        const $btn = $(this);
        $btn.prop('disabled', true).text('Saving…');

        $.post((window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl, {
            action: 'mmi_save_field_mapping_preset',
            nonce: mmiImportSettings.nonce,
            name: name.trim(),
            profile: autosaveScopeProfile(),
            data_type: wizardCurrentDataType(),
            sources: wizardSelectedSources()
        }).done(function (response) {
            if (response.success) {
                populatePresetDropdown(response.data.presets);
                $('#field-mapping-preset-select').val(response.data.preset.id).trigger('change');
                showAutosaveIndicator('✓ Preset "' + name.trim() + '" saved', 'success');
            } else {
                alert('Error: ' + ((response.data && response.data.message) || 'Could not save preset'));
            }
        }).fail(function () {
            alert('Request failed. Check your network connection.');
        }).always(function () {
            $btn.prop('disabled', false).html('💾 Save as Preset&hellip;');
        });
    });

    $(document).on('click', '#apply-field-mapping-preset', function () {
        const presetId   = $('#field-mapping-preset-select').val();
        const presetName = $('#field-mapping-preset-select option:selected').text();
        if (!presetId) { return; }
        if (!confirm('Apply preset "' + presetName + '"? This replaces all current field mappings for this profile.')) {
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true).text('Applying…');

        $.post((window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl, {
            action: 'mmi_apply_field_mapping_preset',
            nonce: mmiImportSettings.nonce,
            preset_id: presetId,
            profile: autosaveScopeProfile()
        }).done(function (response) {
            if (response.success) {
                updateFieldMappingsTable(response.data.mappings);
                showAutosaveIndicator('✓ Preset applied', 'success');
            } else {
                alert('Error: ' + ((response.data && response.data.message) || 'Could not apply preset'));
            }
        }).fail(function () {
            alert('Request failed. Check your network connection.');
        }).always(function () {
            $btn.prop('disabled', false).text('Apply');
        });
    });

    $(document).on('click', '#delete-field-mapping-preset', function () {
        const presetId   = $('#field-mapping-preset-select').val();
        const presetName = $('#field-mapping-preset-select option:selected').text();
        if (!presetId) { return; }
        if (!confirm('Delete preset "' + presetName + '"? This cannot be undone.')) {
            return;
        }

        $.post((window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl, {
            action: 'mmi_delete_field_mapping_preset',
            nonce: mmiImportSettings.nonce,
            preset_id: presetId
        }).done(function (response) {
            if (response.success) {
                populatePresetDropdown(response.data.presets);
                showAutosaveIndicator('✓ Preset deleted', 'success');
            } else {
                alert('Error: ' + ((response.data && response.data.message) || 'Could not delete preset'));
            }
        }).fail(function () {
            alert('Request failed. Check your network connection.');
        });
    });

    /**
     * Build and append a field-mapping table row for a chosen meta key or taxonomy.
     *
     * @param {string} fieldName  - Meta key or taxonomy slug
     * @param {string} fieldType  - 'meta' | 'taxonomy'
     * @param {string} fieldLabel - Human-readable label shown as the row title
     */
    function addCustomFieldRow(fieldName, fieldType, fieldLabel) {
        fieldType  = fieldType  || 'meta';
        fieldLabel = fieldLabel || fieldName;

        if ($('tr[data-field="' + fieldName + '"]').length > 0) {
            alert('"' + fieldName + '" is already in the mapping table.');
            return;
        }

        const sources = (window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) || [];
        let supplierSourceRows = '';
        // Parallel to supplierSourceRows above — populates the new, separate
        // col-source-file column instead of stacking the file selector inside
        // .field-selector-group. Mirrors panel-field-mapping.php's own two-loop
        // split (col-source-file / col-source-field) for the exact same reason:
        // each <td> needs to be a complete, independently-built cell.
        let supplierFileRows = '';

        if (sources.length === 0) {
            supplierSourceRows = '<p class="mmi-no-sources-inline">No data sources configured.</p>';
        } else {
            sources.forEach(function(s) {
                const sid         = s.supplier_id;
                const sname       = s.supplier_name.toUpperCase();
                const fileOptions = s.fileOptions || {};
                const fileKeys    = Object.keys(fileOptions);
                const hasFiles    = fileKeys.length > 0;
                // Only render a real <select> when there's an actual choice
                // between 2+ files; a single-file source carries its one
                // filename as a hidden field instead, so the source-field
                // input below isn't gated behind picking from a dropdown
                // with only one option (same pattern as panel-field-mapping.php).
                const hasFileChoice = fileKeys.length > 1;
                let fileSelectHtml = '';
                if (hasFileChoice) {
                    // Plain file/source labels, matching panel-field-mapping.php —
                    // this is a field-browsing convenience only, not a required
                    // routing step (see the .field-file-selector title/comment there).
                    // No separate blank "(default)" option — a blank selection
                    // isn't offered here at all (falls back to the first file
                    // wherever this value is actually consumed, see
                    // resolveSupplierFilename()); an earlier version of this
                    // duplicated the first file's label into both a blank-value
                    // option AND its own real option, showing the same choice
                    // listed twice (e.g. "Products (Full)" appearing once as an
                    // empty-value placeholder and again as the real xchange-
                    // products.json option) — exactly the broken-dropdown shape
                    // panel-field-mapping.php's own PHP-rendered version already
                    // avoids.
                    fileSelectHtml = '<select class="field-file-selector" name="field_mappings[' + escHtml(fieldName) + '][file][' + escHtml(sid) + ']" data-supplier="' + escHtml(sid) + '" data-field="' + escHtml(fieldName) + '" title="Which file\'s field names to suggest when browsing the Source box — the import itself always reads this supplier\'s main feed either way.">';
                    fileKeys.forEach(function(fk) {
                        fileSelectHtml += '<option value="' + escHtml(fk) + '">' + escHtml(fileOptions[fk]) + '</option>';
                    });
                    fileSelectHtml += '</select>';
                } else if (hasFiles) {
                    fileSelectHtml = '<input type="hidden" class="field-file-selector" name="field_mappings[' + escHtml(fieldName) + '][file][' + escHtml(sid) + ']" data-supplier="' + escHtml(sid) + '" data-field="' + escHtml(fieldName) + '" value="' + escHtml(fileKeys[0]) + '">'
                        + '<span class="mmi-file-single-notice">' + escHtml(fileOptions[fileKeys[0]]) + '</span>';
                } else {
                    fileSelectHtml = '<span class="mmi-file-single-notice">—</span>';
                }
                const inactiveClass    = s.enabled ? '' : ' supplier-source-inactive';
                const inputDisabled    = hasFileChoice ? 'disabled' : '';
                supplierFileRows += `
                    <div class="supplier-file-row mmi-label-grid-row${inactiveClass}" data-supplier="${escHtml(sid)}">
                        <span class="supplier-label mmi-file-supplier-label">${escHtml(sname)}:</span>
                        <div class="mmi-label-grid-value">${fileSelectHtml}</div>
                    </div>`;
                // Mirrors panel-field-mapping.php's per-source Constant swap.
                // Taxonomy fields get a real <select>, restricted to that
                // taxonomy's existing terms only (no free-text entry) —
                // populated on demand by loadConstantTaxonomyTerms() once this
                // row's Const toggle is first checked, same as the PHP-rendered
                // version. Every other custom field type has no server-known
                // specific type yet (just 'meta'), so those keep the generic
                // plain-text constant input, matching the 'type' => 'string'
                // fallback merge() gives any custom field with no saved
                // override (see class-pipeline-field-mapping-defaults.php).
                const constantInputHtml = fieldType === 'taxonomy'
                    ? `<select name="field_mappings[${escHtml(fieldName)}][constant_value][${escHtml(sid)}]" class="constant-value-select mmi-constant-taxonomy-select" data-taxonomy="${escHtml(fieldName)}" disabled><option value="">-- Select a term --</option></select>`
                    : `<input type="text" name="field_mappings[${escHtml(fieldName)}][constant_value][${escHtml(sid)}]" class="constant-value-input-text" placeholder="Constant value" disabled>`;
                supplierSourceRows += `
                    <div class="supplier-source-row mmi-label-grid-row${inactiveClass}" data-supplier="${escHtml(sid)}">
                        <div class="supplier-enable-wrapper">
                            <span class="mmi-supplier-mapped-dot is-unmapped" title="Not mapped"></span>
                            <span class="supplier-label">${escHtml(sname)}:</span>
                        </div>
                        <div class="field-selector-group">
                            <div class="mmi-source-value-swap">
                                <select class="field-source-${escHtml(sid)} mmi-source-path-input" data-field="${escHtml(fieldName)}" data-supplier="${escHtml(sid)}" ${inputDisabled}>
                                    <option value="">— Select a field —</option>
                                </select>
                                <div class="mmi-source-constant-input hidden">
                                    ${constantInputHtml}
                                </div>
                            </div>
                            <label class="mmi-per-source-constant-toggle" title="Use a fixed value for ${escHtml(sname)} instead of mapping a source field">
                                <input type="checkbox" name="field_mappings[${escHtml(fieldName)}][use_constant_value][${escHtml(sid)}]" value="1" class="use-constant-checkbox" data-field="${escHtml(fieldName)}" data-supplier="${escHtml(sid)}">
                                Const
                            </label>
                        </div>
                    </div>`;
            });
        }
        // Icon-only, matching panel-field-mapping.php's server-rendered button —
        // see that file's own comment on .mmi-copy-constant-to-all for why this
        // was reduced from an in-flow text button (it overlapped the row below).
        const copyConstantButton = sources.length > 1
            ? '<button type="button" class="mmi-copy-constant-to-all" data-field="' + escHtml(fieldName) + '" title="Copy the first source\'s Constant checkbox and value to every other source for this field">⧉</button>'
            : '';

        const newRow = `
            <tr data-field="${escHtml(fieldName)}" class="field-mapping-row custom-field-row">
                <td class="col-enabled"></td>
                <td class="col-preview-toggle"></td>
                <td>
                    <strong>${escHtml(fieldLabel)}</strong>
                    <span class="mmi-badge">${escHtml(fieldType)}</span>
                    <span class="mmi-field-not-mapped-pill mmi-badge" title="No source or constant value is configured for any supplier yet — this field is skipped on import.">Not mapped</span>
                    ${fieldType === 'taxonomy' ? '<a href="' + location.pathname + '?page=mmi-data-pipeline&pipeline_tab=taxonomy" class="mmi-source-help-link mmi-taxonomy-mapping-link" title="Map this taxonomy\'s raw supplier values to specific WooCommerce terms — including alias rules for spelling variants — in Taxonomy Mapping."><span class="dashicons dashicons-admin-links"></span> Alias mapping</a>' : ''}
                    <br>
                    <code class="mmi-pipeline-field-code">${escHtml(fieldName)}</code>
                </td>
                <td class="col-source-file">
                    <div class="supplier-files mmi-label-grid" data-field="${escHtml(fieldName)}">
                        ${supplierFileRows}
                    </div>
                </td>
                <td>
                    <div class="supplier-sources mmi-label-grid" data-field="${escHtml(fieldName)}">
                        ${copyConstantButton}
                        ${supplierSourceRows}
                    </div>
                </td>
                <td class="col-transform">
                    <select name="field_mappings[${escHtml(fieldName)}][transform]" class="field-transform" data-field="${escHtml(fieldName)}">
                        <optgroup label="— Text —">
                            <option value="none">None</option>
                            <option value="uppercase">UPPERCASE</option>
                            <option value="lowercase">lowercase</option>
                            <option value="title_case">Title Case</option>
                            <option value="ucwords">Ucwords</option>
                            <option value="trim">Trim Whitespace</option>
                            <option value="strip_html">Strip HTML</option>
                            <option value="html_decode">HTML Decode</option>
                            <option value="sanitize_url">Sanitize URL</option>
                            <option value="str_replace">String Replace ✎</option>
                            <option value="regex_replace">Regex Replace ✎</option>
                            <option value="prefix">Add Prefix ✎</option>
                            <option value="suffix">Add Suffix ✎</option>
                            <option value="template">Template (multi-field) ✎</option>
                        </optgroup>
                        <optgroup label="— Numeric —">
                            <option value="to_integer">To Integer</option>
                            <option value="to_decimal">To Decimal</option>
                            <option value="boolean">To Boolean</option>
                            <option value="price_multiply">× Multiply Price ✎</option>
                            <option value="price_add">+ Add to Price ✎</option>
                            <option value="price_subtract">− Subtract from Price ✎</option>
                        </optgroup>
                        <optgroup label="— Date —">
                            <option value="to_datetime">To DateTime</option>
                            <option value="date_format">Format Date ✎</option>
                        </optgroup>
                        <optgroup label="— Other —">
                            <option value="map_stock_status">Map Stock Status</option>
                        </optgroup>
                    </select>
                    <div class="mmi-transform-params" data-field="${escHtml(fieldName)}">
                        <div class="mmi-tp-group mmi-is-hidden" data-for-transform="str_replace">
                            <label class="mmi-tp-label">Find <input type="text" class="mmi-tp-input" data-param="find" data-field="${escHtml(fieldName)}" placeholder="text to find"></label>
                            <label class="mmi-tp-label">Replace <input type="text" class="mmi-tp-input" data-param="replace" data-field="${escHtml(fieldName)}" placeholder="replacement text"></label>
                        </div>
                        <div class="mmi-tp-group mmi-is-hidden" data-for-transform="regex_replace">
                            <label class="mmi-tp-label">Pattern <input type="text" class="mmi-tp-input" data-param="pattern" data-field="${escHtml(fieldName)}" placeholder="/pattern/flags"></label>
                            <label class="mmi-tp-label">Replacement <input type="text" class="mmi-tp-input" data-param="replacement" data-field="${escHtml(fieldName)}" placeholder="replacement (use $1 for groups)"></label>
                        </div>
                        <div class="mmi-tp-group mmi-is-hidden" data-for-transform="prefix">
                            <label class="mmi-tp-label">Prefix text <input type="text" class="mmi-tp-input" data-param="text" data-field="${escHtml(fieldName)}" placeholder="text to prepend"></label>
                        </div>
                        <div class="mmi-tp-group mmi-is-hidden" data-for-transform="suffix">
                            <label class="mmi-tp-label">Suffix text <input type="text" class="mmi-tp-input" data-param="text" data-field="${escHtml(fieldName)}" placeholder="text to append"></label>
                        </div>
                        <div class="mmi-tp-group mmi-is-hidden" data-for-transform="template">
                            <label class="mmi-tp-label">Template <input type="text" class="mmi-tp-input mmi-tp-wide" data-param="template" data-field="${escHtml(fieldName)}" placeholder="{field.path} — text — {other.field}"></label>
                        </div>
                        <div class="mmi-tp-group mmi-is-hidden" data-for-transform="price_multiply">
                            <label class="mmi-tp-label">Multiplier <input type="number" step="0.0001" class="mmi-tp-input mmi-tp-narrow" data-param="multiplier" data-field="${escHtml(fieldName)}" placeholder="e.g. 1.4"></label>
                        </div>
                        <div class="mmi-tp-group mmi-is-hidden" data-for-transform="price_add">
                            <label class="mmi-tp-label">Amount <input type="number" step="0.01" class="mmi-tp-input mmi-tp-narrow" data-param="amount" data-field="${escHtml(fieldName)}" placeholder="e.g. 5.00"></label>
                        </div>
                        <div class="mmi-tp-group mmi-is-hidden" data-for-transform="price_subtract">
                            <label class="mmi-tp-label">Amount <input type="number" step="0.01" class="mmi-tp-input mmi-tp-narrow" data-param="amount" data-field="${escHtml(fieldName)}" placeholder="e.g. 5.00"></label>
                        </div>
                        <div class="mmi-tp-group mmi-is-hidden" data-for-transform="date_format">
                            <label class="mmi-tp-label">Format <input type="text" class="mmi-tp-input" data-param="format" data-field="${escHtml(fieldName)}" placeholder="Y-m-d  /  d/m/Y  /  U"></label>
                        </div>
                    </div>
                </td>
            </tr>
        `;

        // Insert into the correct group (Taxonomies vs. Custom Meta) instead of
        // always appending to the very end of the table — a blind tbody.append()
        // happened to land under "Custom Meta" only because that group is
        // always rendered last (see $group_labels in panel-field-mapping.php),
        // silently mis-filing every taxonomy-typed custom field into it.
        const $newRow      = $(newRow);
        const targetGroup  = fieldType === 'taxonomy' ? 'taxonomy' : 'meta';
        const $groupHeader = $('.field-group-header[data-group="' + targetGroup + '"]');
        const $groupRows   = getGroupRows($groupHeader);
        if ($groupRows.length) {
            $groupRows.last().after($newRow);
        } else if ($groupHeader.length) {
            $groupHeader.after($newRow);
        } else {
            $('.mmi-field-mapping-table tbody').append($newRow);
        }

        initFieldFileSelectors($('tr[data-field="' + fieldName + '"]'));

        if (fieldType === 'taxonomy') {
            // Persist both immediately (not debounced) — without 'type', a
            // page refresh loses it entirely (merge()'s fallback for any
            // custom field with no saved 'type' is 'string'), and the
            // taxonomy-term <select> above would silently revert to a plain,
            // unbacked text box on every subsequent load. Without 'group',
            // panel-field-mapping.php's section grouping — which reads
            // $mapping['group'] exclusively, not 'type' — keeps filing the
            // field under "Custom Meta" (merge()'s fallback defaults 'group'
            // to 'meta') even though it correctly renders as a taxonomy field
            // once loaded. The <select> itself needs no shared element
            // created here — each supplier row's own
            // <select data-taxonomy="..."> is found and populated directly
            // by loadConstantTaxonomyTerms() once Const is checked.
            autosaveFieldPropertyNow(fieldName, 'type', 'taxonomy');
            autosaveFieldPropertyNow(fieldName, 'group', 'taxonomy');
        }

        // A newly-added row's supplier sub-rows must respect the same "not
        // assigned to this profile" scoping every other row already gets —
        // reuses the exact mechanism Step 2's source checkboxes already
        // drive, rather than re-deriving assigned sources here.
        updateFieldMappingSupplierScope();
        $('html, body').animate({
            scrollTop: $('tr[data-field="' + fieldName + '"]').offset().top - 100
        }, 500);
    }

    // Meta keys come back branded by which plugin/system owns them (see
    // mmi_pipeline_classify_meta_key() in ImportSettingsController.php) —
    // WooCommerce/WordPress core, JetEngine (the one meta-registering plugin
    // active on this site), this suite's own __mmi_*/_mmi_* keys, or null
    // (Custom — no known registry claims it, the catch-all). Fetched once and
    // memoized: both the Add Custom Field modal and any inline meta-key
    // picker (.mmi-meta-key-input) use the same data.
    const META_KEY_SOURCE_GROUPS = [
        { id: 'wc',       label: '🛒 WooCommerce' },
        { id: 'jetengine', label: '⚡ JetEngine' },
        { id: 'mannmade', label: '🏢 MannMade' },
        { id: 'custom',   label: '📝 Custom' },
    ];
    function metaKeySourceGroupId(source) {
        if (source === 'WooCommerce') return 'wc';
        if (source === 'JetEngine') return 'jetengine';
        if (source === 'MannMade') return 'mannmade';
        return 'custom';
    }
    let productMetaKeysPromise = null;
    function fetchProductMetaKeysAndTaxonomies() {
        if (productMetaKeysPromise) { return productMetaKeysPromise; }
        productMetaKeysPromise = $.ajax({
            url:    (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            method: 'POST',
            data: {
                action: 'mmi_get_product_meta_keys',
                nonce:  mmiImportSettings.nonce
            }
        }).fail(function () {
            // Let the next call retry instead of caching a failure forever.
            productMetaKeysPromise = null;
        });
        return productMetaKeysPromise;
    }

    // ── Add Custom Field — searchable meta key / taxonomy picker ───────
    // Anchored dropdown under the button (.mmi-field-dropdown, appended into
    // .mmi-action-buttons — see its position:relative in import-settings.css),
    // not the #test-modal overlay this used to open. That overlay stacked a
    // second popup on top of the wizard modal for what's fundamentally a
    // pick-from-a-list action — the exact "difficult to use" shape that
    // openMetaKeyDropdown() already replaced elsewhere in this file for the
    // same reason (see its own comment). Reuses that function's close-on-
    // outside-click/Escape pattern under its own '.mmiAddCustomFieldDropdown'
    // namespace rather than sharing it — matches this file's existing
    // convention of one dedicated open/close pair per dropdown meaning.
    function closeAddCustomFieldDropdown() {
        $('#add-custom-field-dropdown').remove();
        $(document).off('click.mmiAddCustomFieldDropdown keydown.mmiAddCustomFieldDropdown');
    }

    // Keeps the footer's "N selected" text and the Add Selected button's
    // enabled state in sync with however many checkboxes are currently checked.
    function updateAddCustomFieldSelectionCount($dropdown) {
        const count = $dropdown.find('.meta-key-item-checkbox:checked').length;
        $dropdown.find('.field-list-selected-count').text(count + ' selected');
        $dropdown.find('#add-custom-field-selected').prop('disabled', count === 0);
    }

    function openAddCustomFieldDropdown($button) {
        closeMetaKeyDropdown();
        closeAddCustomFieldDropdown();

        const $dropdown = $('<div class="mmi-field-dropdown" id="add-custom-field-dropdown"></div>')
            .html('<div class="field-browser"><p class="field-browser-meta">Loading available product fields…</p></div>');
        $button.closest('.mmi-action-buttons').append($dropdown);

        $(document).on('click.mmiAddCustomFieldDropdown', function (e) {
            if (!$(e.target).closest('#add-custom-field-dropdown, #add-custom-field').length) {
                closeAddCustomFieldDropdown();
            }
        });
        $(document).on('keydown.mmiAddCustomFieldDropdown', function (e) {
            if (e.key === 'Escape') { closeAddCustomFieldDropdown(); }
        });

        fetchProductMetaKeysAndTaxonomies().then(function(response) {
                if (!response.success) {
                    $dropdown.html('<div class="field-browser"><p class="mmi-error-text">Error: ' + escHtml(response.data ? response.data.message : 'Unknown error') + '</p></div>');
                    return;
                }

                const allMetaKeys   = response.data.meta_keys  || [];
                const allTaxonomies = response.data.taxonomies  || [];

                // Filter out keys that are already present in the mapping table
                const mapped = [];
                $('tr[data-field]').each(function() { mapped.push(String($(this).data('field'))); });

                const availableMeta = allMetaKeys.filter(function(m) { return !mapped.includes(m.key); });
                const availableTax  = allTaxonomies.filter(function(t) { return !mapped.includes(t.key); });
                const total         = availableMeta.length + availableTax.length;

                let html = '<div class="field-browser">'
                    + '<p class="field-browser-meta">Select a meta key or taxonomy to add as a custom field mapping. '
                    + '<strong>' + total + ' available</strong> (' + mapped.length + ' already mapped).</p>'
                    + '<input type="text" id="meta-key-search" class="field-search" placeholder="🔍 Filter fields…">'
                    + '<div class="field-list" id="meta-key-list">';

                if (availableTax.length > 0) {
                    html += '<div class="field-group-section-header" data-section="tax">🏷️ Product Taxonomies <span class="field-section-count">' + availableTax.length + '</span></div>';
                    availableTax.forEach(function(t) {
                        html += '<div class="field-item meta-key-item" data-field="' + escHtml(t.key) + '" data-label="' + escHtml(t.label) + '" data-type="taxonomy" data-section="tax">'
                            + '<input type="checkbox" class="meta-key-item-checkbox">'
                            + '<span class="meta-key-item-body"><code>' + escHtml(t.key) + '</code><span class="field-sample">' + escHtml(t.label) + '</span></span></div>';
                    });
                }

                META_KEY_SOURCE_GROUPS.forEach(function(group) {
                    const items = availableMeta.filter(function(m) { return metaKeySourceGroupId(m.source) === group.id; });
                    if (!items.length) { return; }
                    html += '<div class="field-group-section-header" data-section="' + group.id + '">' + group.label + ' <span class="field-section-count">' + items.length + '</span></div>';
                    items.forEach(function(m) {
                        const sample = m.label !== m.key ? '<span class="field-sample">' + escHtml(m.label) + '</span>' : '';
                        html += '<div class="field-item meta-key-item" data-field="' + escHtml(m.key) + '" data-label="' + escHtml(m.label) + '" data-type="meta" data-section="' + group.id + '">'
                            + '<input type="checkbox" class="meta-key-item-checkbox">'
                            + '<span class="meta-key-item-body"><code>' + escHtml(m.key) + '</code>' + sample + '</span></div>';
                    });
                });

                if (total === 0) {
                    html += '<p class="mmi-no-results">All available fields are already mapped, or no products/taxonomies found.</p>';
                }

                html += '</div>';
                // Selecting doesn't add immediately or close the dropdown — pick as many
                // as needed, then confirm with this button (see the click handlers below).
                html += '<div class="field-list-footer">'
                    + '<span class="field-list-selected-count">0 selected</span>'
                    + '<button type="button" class="mmi-btn-secondary" id="add-custom-field-selected" disabled>➕ Add Selected</button>'
                    + '</div>';
                html += '</div>';
                $dropdown.html(html);

                // Live filter — searches against the key and label
                $('#meta-key-search').on('input', function() {
                    const term = $(this).val().toLowerCase();
                    const sectCounts = { tax: 0, wc: 0, jetengine: 0, mannmade: 0, custom: 0 };

                    $('.meta-key-item').each(function() {
                        const sec     = String($(this).data('section'));
                        const keyTxt  = String($(this).data('field')).toLowerCase();
                        const lblTxt  = String($(this).data('label')).toLowerCase();
                        const matches = !term || keyTxt.includes(term) || lblTxt.includes(term);
                        $(this).toggle(matches);
                        if (matches && sectCounts[sec] !== undefined) sectCounts[sec]++;
                    });

                    $('.field-group-section-header').each(function() {
                        const sec = String($(this).data('section'));
                        const cnt = sectCounts[sec] !== undefined ? sectCounts[sec] : 0;
                        $(this).toggle(!term || cnt > 0);
                        $(this).find('.field-section-count').text(cnt > 0 || !term ? cnt : 0);
                    });
                }).focus();

                // Click an item to toggle its checkbox — selecting doesn't add the
                // row immediately or close the dropdown, so multiple fields can be
                // picked in one pass before confirming with "Add Selected" below.
                $dropdown.on('click', '.meta-key-item', function(e) {
                    const $checkbox = $(this).find('.meta-key-item-checkbox');
                    if (e.target !== $checkbox[0]) {
                        $checkbox.prop('checked', !$checkbox.prop('checked'));
                    }
                    $(this).toggleClass('mmi-is-selected', $checkbox.prop('checked'));
                    updateAddCustomFieldSelectionCount($dropdown);
                });

                // Adds every checked item as a custom field mapping row, then closes.
                $dropdown.on('click', '#add-custom-field-selected', function() {
                    const $selected = $dropdown.find('.meta-key-item-checkbox:checked').closest('.meta-key-item');
                    $selected.each(function() {
                        const $item = $(this);
                        addCustomFieldRow(String($item.data('field')), String($item.data('type')), String($item.data('label')));
                    });
                    closeAddCustomFieldDropdown();
                });
        }, function() {
            $dropdown.html('<div class="field-browser"><p class="mmi-error-text">Failed to load fields. Please try again.</p></div>');
        });
    }

    // Delegated — #add-custom-field lives inside the lazily-AJAX-loaded
    // field mapping table (see wizardMaybeLoadFieldMappingPanel()).
    $(document).on('click', '#add-custom-field', function() {
        openAddCustomFieldDropdown($(this));
    });

    
    // Field Browser: Load available fields from JSON files
    const fieldCache = {};
    
    // Function to extract all keys from a JSON object (including nested) with sample values
    // Fetch field names + one sample value per field for a supplier's cached
    // source-data file — server-side (mmi_pipeline_get_fields_from_file),
    // not a raw fetch of the file itself. That file can be tens of MB for a
    // live catalog cache (confirmed via a real HAR capture: shipping it to
    // the browser just to list ~40 field names was over half this page's
    // total transfer weight); the server already has the file on local disk
    // and returns only the small extracted {name,sample}[] list. Rejects the
    // promise with `{not_found: true}` when the file doesn't exist yet, so
    // callers can distinguish "ask the server to fetch it first" from a real
    // failure — mirrors the previous client-side-fetch behavior exactly.
    function fetchFieldsFromFile(filename) {
        return $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_pipeline_get_fields_from_file',
                nonce: mmiImportSettings.nonce,
                filename: filename,
            },
        }).then(function (response) {
            if (response && response.success) {
                return response.data.fields;
            }
            const err = new Error((response && response.data && response.data.message) || 'Failed to load fields');
            err.notFound = !!(response && response.data && response.data.not_found);
            throw err;
        });
    }

    // Load JSON file and extract fields with samples
    function loadFieldsFromFile(supplier, filename) {
        const cacheKey = `${supplier}-${filename}`;

        // fieldCache[cacheKey] holds either the resolved {name,sample}[] array
        // (a prior call already finished) or an in-flight Promise for the same
        // key — every WC field mapped to this supplier+file (often 20-30+ per
        // profile) calls this on page load, and without caching the in-flight
        // promise too, each one fired its own redundant concurrent request
        // for the identical file, which under real network/browser
        // connection-limit pressure caused some of those requests to silently
        // fail while others succeeded (some fields correctly get an
        // autocomplete datalist, others stay empty despite an identical file).
        if (fieldCache[cacheKey]) {
            return Promise.resolve(fieldCache[cacheKey]);
        }

        // jQuery's .fail() is a side-effect hook, not a recovery branch — the
        // promise it's attached to stays rejected, so every .then() a caller
        // chains onto loadFieldsFromFile() would silently never fire on a
        // missing file. Using .then(success, failure) here lets the failure
        // branch resolve into a real fallback value instead.
        const promise = fetchFieldsFromFile(filename).then(function (fields) {
            fieldCache[cacheKey] = fields;
            return fields;
        }, function (err) {
            if (!err.notFound) {
                console.error('Failed to load fields for:', filename, err.message);
                return [];
            }
            // No cache file yet — normal for a source that's never been
            // fetched (Xchange/SkuPort already have this from their
            // scheduled syncs and never reach this branch; only
            // Upload/URL/Dropbox/Google Drive sources do, since nothing
            // automatically "syncs" an already-local upload). Ask the
            // server to fetch it once, on demand, then retry exactly once.
            return $.ajax({
                url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                type: 'POST',
                data: {
                    action: 'mmi_pipeline_ensure_source_cache',
                    nonce: mmiImportSettings.nonce,
                    supplier_id: supplier,
                },
            }).then(function (response) {
                if (!response || !response.success) {
                    console.error('Could not fetch source data for field discovery:', supplier, response && response.data && response.data.message);
                    return [];
                }
                return fetchFieldsFromFile(filename).then(function (fields) {
                    fieldCache[cacheKey] = fields;
                    return fields;
                }, function () {
                    console.error('Failed to load fields after fetch:', filename);
                    return [];
                });
            }, function () {
                console.error('Failed to request source fetch for:', supplier);
                return [];
            });
        });

        // Cache the in-flight promise immediately (not just its eventual
        // result) so concurrent callers reuse this same request. On an empty
        // result (network hiccup, aborted request, etc.) drop the cache entry
        // so a later explicit retry (Browse button, re-opening the wizard)
        // isn't permanently stuck with a transient failure.
        fieldCache[cacheKey] = promise;
        promise.then(function (fields) {
            if (!fields || !fields.length) {
                delete fieldCache[cacheKey];
            }
        });

        return promise;
    }

    // Read-only variant used for passive priming (page load) — a plain
    // server-side read of whatever JSON cache already exists, with NO
    // "fetch it now" fallback. Priming should never trigger a real remote
    // fetch just because the settings page was opened; that fallback stays
    // reserved for explicit user actions (Auto-Map, or clicking into a
    // source field to open its dropdown).
    function loadFieldsFromFileIfCached(supplier, filename) {
        const cacheKey = `${supplier}-${filename}`;
        if (fieldCache[cacheKey]) { return Promise.resolve(fieldCache[cacheKey]); }

        const promise = fetchFieldsFromFile(filename).then(function (fields) {
            fieldCache[cacheKey] = fields;
            return fields;
        }, function () {
            return []; // not fetched yet — Auto-Map / clicking into the field will fetch on demand instead
        });

        fieldCache[cacheKey] = promise;
        promise.then(function (fields) {
            if (!fields || !fields.length) {
                delete fieldCache[cacheKey];
            }
        });

        return promise;
    }

    /* ── Auto-Map Source Fields ──────────────────────────────────────────
     * Solves the "a supplier got enabled for a field but its Source Field
     * box was left blank" gap that causes fields to silently show up empty
     * in Import Preview: instead of requiring the admin to type/know the
     * exact JSON key for every WC field × supplier combination, this scans
     * each connected source's real sample data and fills in blank boxes
     * automatically — first by exact field-name match (this plugin's own
     * export/re-import feeds literally reuse the WC field name as the JSON
     * key, e.g. `product_cat`), then by common naming-pattern keywords
     * (mirrors the CSV importer's own auto-detect keywords in
     * CsvMappingController.php::mmi_pipeline_csv_field_keywords()). It never
     * overwrites a value that's already set. Anything it can't confidently
     * match stays blank — see updateMappedIndicators()'s "Not mapped" pill
     * below — instead of failing silently. */
    const MMI_FIELD_AUTOMAP_KEYWORDS = {
        'post_title':              [ 'post_title', 'product_name', 'title', 'name' ],
        '_sku':                    [ 'sku', 'item_number', 'part_number', 'mpn', 'model' ],
        'post_content':            [ 'post_content', 'long_description', 'description', 'body' ],
        'post_excerpt':            [ 'post_excerpt', 'short_description', 'short_desc', 'excerpt', 'summary' ],
        '_regular_price':          [ 'regular_price', 'retail_price', 'list_price', 'price' ],
        '_price':                  [ 'price' ],
        '_sale_price':             [ 'sale_price', 'promo_price' ],
        '_stock':                  [ 'stock_quantity', 'quantity', 'qty', 'stock' ],
        '_stock_status':           [ 'stock_status', 'availability', 'in_stock' ],
        'product_cat':             [ 'product_cat', 'categories', 'category', 'product_category' ],
        'product_cat_ids':         [ 'product_cat_ids' ],
        'product_tag':             [ 'product_tag', 'tags', 'tag', 'labels', 'keywords' ],
        'product_tag_ids':         [ 'product_tag_ids' ],
        '_product_image_url':     [ 'image_url', 'image', 'photo', 'thumbnail_url', 'picture' ],
        '_product_gallery_urls':  [ 'gallery_urls', 'gallery' ],
    };

    // Best matching real field name for a WC field, given the {name, sample}
    // list loadFieldsFromFile() resolved for one source file. Returns null
    // when nothing confident is found — caller leaves the box blank rather
    // than guess.
    function findAutoMapMatch(fieldName, availableFields) {
        const exact = availableFields.find(f => f.name.toLowerCase() === fieldName.toLowerCase());
        if (exact) { return exact.name; }

        const keywords = MMI_FIELD_AUTOMAP_KEYWORDS[fieldName];
        if (!keywords) { return null; }

        for (const kw of keywords) {
            const hit = availableFields.find(f => f.name.toLowerCase().includes(kw));
            if (hit) { return hit.name; }
        }
        return null;
    }

    // Which JSON filename to scan for a supplier — prefers a file explicitly
    // selected in one of its rows already, else falls back to the first file
    // this supplier is known to publish (correct for the common single-file
    // dynamic sources — Upload/URL/Dropbox/Google Drive — which have no file
    // selector at all, just a hidden input carrying the one filename).
    function resolveSupplierFilename(supplier) {
        const $anySelector = $(`.field-file-selector[data-supplier="${supplier}"]`).filter(function () { return $(this).val(); });
        if ($anySelector.length) { return $anySelector.first().val(); }

        const suppliers = (window.mmiImportSettings && mmiImportSettings.configuredSuppliers) || [];
        const info = suppliers.find(s => s.supplier_id === supplier);
        const fileOptions = (info && info.fileOptions) || {};
        return Object.keys(fileOptions)[0] || `${supplier}-products.json`;
    }

    // Live mapped/not-mapped recompute — a field is enabled purely by having
    // a real source or constant mapped for at least one supplier. The
    // backend derives and persists this on every save (see
    // ImportSettingsController.php's mmi_autosave_field_property), but the
    // "Not mapped" pill, each row's per-supplier dots, each group's "N of M
    // mapped" chip, and the table-wide summary all need their own live
    // recompute so they update instantly on input instead of only after the
    // next page load. Exposed on window so import-preview.js's
    // getPreviewFields() can reuse the exact same "is this row mapped"
    // check instead of re-deriving it (and re-risking the same class of
    // per-supplier/enabled-flag bug this file's own history is full of).
    function rowHasMapping($row) {
        let mapped = false;
        // .mmi-supplier-not-in-profile rows are hidden (display:none —
        // import-settings.css) for a supplier this profile doesn't assign,
        // but their inputs still live in the DOM and can still carry a real
        // value (e.g. left over from before the Data Sources step was last
        // changed) — excluded here via :visible so a phantom, invisible
        // supplier can't make the row/chip read as mapped when every control
        // the user can actually see is empty. Mirrors the same fix applied
        // to $mmi_is_field_enabled in panel-field-mapping.php.
        $row.find('.supplier-source-row:visible').each(function () {
            const $sr = $(this);
            const usingConstant = $sr.find('.use-constant-checkbox').is(':checked');
            const $select = $sr.find('.mmi-source-path-input');
            const hasValue = $select.length
                ? !!$select.val()
                : !!$sr.find('[class*="field-source-"]').val();
            const supplierMapped = usingConstant || hasValue;
            $sr.find('.mmi-supplier-mapped-dot')
                .toggleClass('is-mapped', supplierMapped)
                .toggleClass('is-unmapped', !supplierMapped);
            if (supplierMapped) { mapped = true; }
        });
        return mapped;
    }
    window.MMIFieldMapping = window.MMIFieldMapping || {};
    window.MMIFieldMapping.rowHasMapping = rowHasMapping;

    function updateMappedIndicators() {
        // Pass 1: refresh each editable row's own "Not mapped" pill + its
        // per-supplier dots. A row with neither .supplier-source-row (the
        // normal per-supplier source picker) nor .field-taxonomy-mapping-toggle
        // (product_brand/product_cat's fixed-source Taxonomy Mapping card —
        // see panel-field-mapping.php's $mmi_taxonomy_mapping_only_fields) has
        // nothing editable here to recompute — left exactly as server-rendered.
        $('.field-mapping-row').each(function () {
            const $row = $(this);
            const $tmToggles = $row.find('.field-taxonomy-mapping-toggle');
            let mapped;
            if ($row.find('.supplier-source-row').length) {
                mapped = rowHasMapping($row);
            } else if ($tmToggles.length) {
                // "Mapped" the same way the server's $mmi_is_field_enabled does
                // for an array-shaped 'enabled' (panel-field-mapping.php): any
                // one supplier's toggle checked is enough — but only a toggle
                // the user can actually see. A supplier this profile doesn't
                // assign renders its toggle with mmi-supplier-not-in-profile
                // (display:none), yet the checkbox's checked state still
                // reflects whatever 'source' DEFAULTS happens to fall back to
                // for it — :visible excludes that phantom entry the same way
                // rowHasMapping() above does for the editable-source case.
                mapped = $tmToggles.filter(':visible').is(':checked');
            } else {
                return;
            }
            // The pill only exists in the initial server-rendered markup when
            // the row started out unmapped (see panel-field-mapping.php) — a
            // row that started mapped has nothing here to toggle back on if a
            // later edit clears its source, so create it on demand rather
            // than assuming it's already in the DOM.
            let $pill = $row.find('.mmi-field-not-mapped-pill');
            if (!mapped && !$pill.length) {
                $pill = $('<span class="mmi-field-not-mapped-pill mmi-badge" title="No source or constant value is configured for any supplier — this field is skipped on import.">Not mapped</span>');
                $row.find('.mmi-woo-field-name').first().after($pill);
            }
            $pill.toggle(!mapped);
        });

        // Pass 2: recompute every group's "N of M mapped" chip and the
        // table-wide summary from each row's now-current pill visibility —
        // uniform across editable and read-only rows alike, since pass 1
        // already brought every editable row's pill up to date.
        let totalMapped = 0;
        let totalFields = 0;
        $('.field-group-header').each(function () {
            const $header = $(this);
            const $rows   = getGroupRows($header).filter('.field-mapping-row');
            const total   = $rows.length;
            let mapped    = 0;
            $rows.each(function () {
                if (!$(this).find('.mmi-field-not-mapped-pill').is(':visible')) { mapped++; }
            });
            totalMapped += mapped;
            totalFields += total;

            const $chip = $header.find('.mmi-group-mapped-chip');
            if ($chip.length) {
                const isFull    = total > 0 && mapped === total;
                const isPartial = mapped > 0 && mapped < total;
                $chip.text(mapped + ' of ' + total + ' mapped');
                $chip.toggleClass('is-full', isFull);
                $chip.toggleClass('is-partial', isPartial);
                $chip.toggleClass('is-empty', mapped === 0);
                // Keep the shared .mmi-badge success/warning variants (mmi-suite-common.css)
                // in sync with is-full/is-partial above.
                $chip.toggleClass('success', isFull);
                $chip.toggleClass('warning', isPartial);
            }
        });

        $('.mmi-fm-total-mapped').text(totalMapped + ' mapped');
        $('.mmi-fm-total-skipped').text((totalFields - totalMapped) + ' skipped');
    }
    $(document).on('change', '.use-constant-checkbox', updateMappedIndicators);
    $(document).on('input change', '[name*="[source]"]', updateMappedIndicators);
    $(document).on('change', '.mmi-source-path-input', updateMappedIndicators);
    $(document).on('change', '.field-taxonomy-mapping-toggle', updateMappedIndicators);
    updateMappedIndicators(); // compute once for whatever's already on the page

    function autoMapSourceFields() {
        const suppliers = ((window.mmiImportSettings && mmiImportSettings.configuredSuppliers) || []).filter(s => s.enabled);
        if (!suppliers.length) {
            showAutosaveIndicator('No active data sources to scan', 'error');
            return;
        }

        const $btn = $('#auto-map-source-fields');
        $btn.prop('disabled', true).html('🪄 Scanning…');

        let matched = 0;
        const unmatched = [];

        const promises = suppliers.map(function (info) {
            const supplier = info.supplier_id;
            const filename = resolveSupplierFilename(supplier);
            if (!filename) { return Promise.resolve(); }

            return loadFieldsFromFile(supplier, filename).then(function (fields) {
                if (!fields.length) { return; }

                $(`.supplier-source-row[data-supplier="${supplier}"]`).each(function () {
                    const $row = $(this);
                    const $field = $row.closest('.supplier-sources');
                    const fieldName = $field.data('field');
                    const $input = $row.find('[class*="field-source-"], .field-source-input');

                    if (!$input.length || $input.val() || $input.prop('disabled')) {
                        return; // never overwrite an existing value or a disabled/inapplicable row
                    }

                    const match = findAutoMapMatch(fieldName, fields);
                    if (match) {
                        // A <select> only accepts .val(match) once a matching
                        // <option> exists — populate it from this same fields
                        // list (already loaded above) rather than assuming
                        // some earlier pass already did.
                        if ($input.is('select')) { populateSourceFieldSelect($input, fields); }
                        $input.val(match).trigger('input').trigger('change');
                        $input.addClass('mmi-automap-applied');
                        setTimeout(() => $input.removeClass('mmi-automap-applied'), 2500);
                        matched++;
                    } else {
                        const label = $field.closest('tr').find('.col-woo-field strong').first().text() || fieldName;
                        unmatched.push(`${label} (${info.supplier_name})`);
                    }
                });
            });
        });

        Promise.all(promises).then(function () {
            updateMappedIndicators();
            $btn.prop('disabled', false).html('🪄 Auto-Map Source Fields');

            if (matched === 0 && unmatched.length === 0) {
                showAutosaveIndicator('Nothing to map — every enabled field already has a source', 'info');
                return;
            }

            let msg = matched > 0
                ? `✓ Auto-mapped ${matched} field${matched === 1 ? '' : 's'}`
                : 'No confident matches found';
            if (unmatched.length) {
                msg += ` — ${unmatched.length} need${unmatched.length === 1 ? 's' : ''} manual review (flagged below)`;
                console.info('MMI Auto-Map: fields needing manual review:', unmatched);
            }
            showAutosaveIndicator(msg, unmatched.length ? 'info' : 'success');
        });
    }
    $(document).on('click', '#auto-map-source-fields', autoMapSourceFields);

    // Warm the field cache for single-file sources so the list is already
    // there on first click instead of a visible empty-to-populated flash.
    // Uses .find() scoped to .supplier-source-row (a real ancestor of the
    // source select), not .siblings() — the source select lives inside
    // .field-selector-group's .mmi-source-value-swap, a nephew of the file
    // selector, not a direct sibling of anything at this level.
    //
    // Deliberately NOT an eager IIFE run at script-parse time: the elements
    // this populates (.supplier-source-row) only exist inside the Field
    // Mapping wizard modal (section-profile-wizard.php), which is display:none
    // until "New/Edit Profile" is clicked — running this on every page load
    // fired one mmi_pipeline_get_fields_from_file AJAX call per globally-
    // enabled supplier (site-wide, not scoped to the active profile) against
    // completely hidden DOM. A browser performance recording of the Import
    // tab caught this as part of a 10-call AJAX fan-out on page load — see
    // AGENTS.md's Server Load & PHP-FPM Impact section. Now runs once, the
    // first time the wizard is actually opened, via openProfileWizard().
    let hasPrimedSourceFieldCache = false;
    function primeSourceFieldCache() {
        if (hasPrimedSourceFieldCache) { return; }
        hasPrimedSourceFieldCache = true;

        const suppliers = (window.mmiImportSettings && mmiImportSettings.configuredSuppliers) || [];
        suppliers.filter(s => s.enabled).forEach(function (info) {
            const supplier = info.supplier_id;
            const filename = resolveSupplierFilename(supplier);
            if (!filename) { return; }

            loadFieldsFromFileIfCached(supplier, filename).then(function (fields) {
                if (!fields.length) { return; }
                $(`.supplier-source-row[data-supplier="${supplier}"]`).each(function () {
                    const $group = $(this).find('.field-selector-group');
                    const $sourceSelect = $group.find('.mmi-source-path-input');
                    if (!$sourceSelect.length) { return; } // legacy single-source row — nothing to prime
                    const usingConstant = $group.find('.use-constant-checkbox').is(':checked');
                    populateSourceFieldSelect($sourceSelect, fields);
                    $sourceSelect.prop('disabled', usingConstant);
                });
            });
        });
    }

    // Repopulate the source <select>'s options when file selection changes,
    // and warm the field cache for the newly selected file.
    $(document).on('change', '.field-file-selector', function() {
        const $select = $(this);
        const filename = $select.val();
        const supplier = $select.data('supplier');
        const $supplierRow = supplierSourceRowFor($select, supplier);
        const $sourceSelect = $supplierRow.find('.mmi-source-path-input');
        const usingConstant = $supplierRow.find('.use-constant-checkbox').is(':checked');

        if (!filename) {
            $sourceSelect.prop('disabled', true);
            updateMappedIndicators();
            return;
        }

        // Disabled until the real options for the newly-selected file are
        // in — avoids a moment where the select still shows the PREVIOUS
        // file's options/selection as if they were still valid.
        $sourceSelect.prop('disabled', true);

        loadFieldsFromFile(supplier, filename).then(function(fields) {
            populateSourceFieldSelect($sourceSelect, fields);
            $sourceSelect.prop('disabled', usingConstant);

            // populateSourceFieldSelect() resets the select's value via
            // .val() when the previous file's selection isn't one of the new
            // file's real fields (the common case right after switching
            // files) — a setter, which never fires 'change', so
            // updateMappedIndicators()'s own delegated .mmi-source-path-input
            // listener never sees it. Without this, switching a supplier's
            // file left a now-unmapped row's "Not mapped" pill stale until
            // some unrelated action happened to recompute it.
            updateMappedIndicators();
        });
    });
    
    /* ── Destination meta-key picker (.mmi-meta-key-input) ───────────────────
     * This browses the site's own known DESTINATION meta keys,
     * grouped by which plugin/system owns each one (see
     * META_KEY_SOURCE_GROUPS above). Different data shape, different meaning
     * ("where does this come from" vs "where does this get written to") —
     * sharing one function across both would mean branching on a `mode` flag
     * inside otherwise-unrelated render/filter logic. The input stays free
     * text throughout: picking an item fills it in, but nothing stops typing
     * an arbitrary key the list doesn't know about (the catch-all "Custom"
     * case is really just "didn't pick from the list"). */
    function closeMetaKeyDropdown() {
        $('.mmi-meta-key-dropdown').remove();
        $('.meta-key-dropdown-open')
            .removeClass('meta-key-dropdown-open')
            .off('input.mmiMetaKeyDropdown');
        $(document).off('click.mmiMetaKeyDropdown keydown.mmiMetaKeyDropdown');
    }

    // In-flow inside .mmi-pipeline-field-meta-key-label (position:relative),
    // appended directly rather than portaled to <body>, so it scrolls with
    // its input and never goes stale on scroll/modal animation. Meta-key
    // inputs (e.g. the COG row's) are often much narrower than source-field
    // inputs, so .mmi-meta-key-dropdown carries its own CSS min-width rather
    // than matching $input's width exactly — a dropdown that narrow would be
    // too cramped to read grouped labels/badges in.
    function openMetaKeyDropdown($input, groupedMeta) {
        closeMetaKeyDropdown();
        closeAddCustomFieldDropdown();

        const total = groupedMeta.reduce(function (n, g) { return n + g.items.length; }, 0);
        // .field-search is the same dedicated, auto-focused filter input the
        // sibling Add Custom Field dropdown already uses (openAddCustomFieldDropdown()
        // above) — added here so filtering is discoverable from inside this
        // popup itself, not only by typing into $input (the trigger field,
        // often narrow and visually well outside the popup's own bounds).
        // $input's own typing still filters too (see filterItems() below) —
        // it also doubles as the actual value field for a literal custom key,
        // so that behavior is additive, not replaced.
        let html = '<div class="field-browser">'
            + '<input type="text" class="field-search mmi-meta-key-search" placeholder="🔍 Filter meta keys…">'
            + '<p class="field-browser-meta" id="meta-key-count-display">Showing all ' + total + ' meta keys</p>'
            + '<div class="field-list">';
        groupedMeta.forEach(function (group) {
            if (!group.items.length) { return; }
            html += '<div class="field-group-section-header" data-section="' + group.id + '">' + group.label + ' <span class="field-section-count">' + group.items.length + '</span></div>';
            group.items.forEach(function (m) {
                const sample = m.label !== m.key ? '<span class="field-sample">' + escHtml(m.label) + '</span>' : '';
                html += '<div class="field-item" data-field="' + escHtml(m.key) + '" data-label="' + escHtml(m.label) + '" data-section="' + group.id + '"><code>' + escHtml(m.key) + '</code>' + sample + '</div>';
            });
        });
        html += '</div></div>';

        const $dropdown = $('<div class="mmi-field-dropdown mmi-meta-key-dropdown"></div>').html(html);
        $input.closest('.mmi-pipeline-field-meta-key-label').append($dropdown);
        $input.addClass('meta-key-dropdown-open');

        const $search = $dropdown.find('.mmi-meta-key-search');

        // Takes an explicit term rather than reading $input.val() itself, so
        // both the internal search box and $input's own typing (a literal
        // custom key, not just a filter) can drive the same filtering logic
        // without fighting over which one is "the" term.
        function filterItems(rawTerm) {
            const term = (rawTerm || '').toLowerCase();
            let visibleCount = 0;
            const sectCounts = {};

            $dropdown.find('.field-item').each(function () {
                const sec    = String($(this).data('section'));
                const keyTxt = String($(this).data('field')).toLowerCase();
                const lblTxt = String($(this).data('label')).toLowerCase();
                const match  = !term || keyTxt.includes(term) || lblTxt.includes(term);
                $(this).toggle(match);
                if (match) {
                    visibleCount++;
                    sectCounts[sec] = (sectCounts[sec] || 0) + 1;
                }
            });

            $dropdown.find('.field-group-section-header').each(function () {
                const sec = String($(this).data('section'));
                const cnt = sectCounts[sec] || 0;
                $(this).toggle(!term || cnt > 0);
                $(this).find('.field-section-count').text(cnt);
            });

            $dropdown.find('#meta-key-count-display').text(
                term
                    ? ('Showing ' + visibleCount + ' of ' + total + ' meta keys matching “' + rawTerm + '”')
                    : ('Showing all ' + total + ' meta keys')
            );

            $dropdown.find('.no-results-message').remove();
            if (visibleCount === 0 && term) {
                $dropdown.find('.field-list').append('<div class="no-results-message"><p>😕 No meta keys match your search</p><p>Keep typing to use it as a custom key</p></div>');
            }
        }
        $input.on('input.mmiMetaKeyDropdown', function () { filterItems($input.val()); });
        $search.on('input', function () { filterItems($search.val()); }).focus();

        $dropdown.on('click', '.field-item', function () {
            $input.val($(this).data('field')).trigger('input');
            closeMetaKeyDropdown();
        });

        $(document).on('click.mmiMetaKeyDropdown', function (e) {
            if (!$(e.target).closest('.mmi-meta-key-dropdown, .meta-key-dropdown-open').length) {
                closeMetaKeyDropdown();
            }
        });
        $(document).on('keydown.mmiMetaKeyDropdown', function (e) {
            if (e.key === 'Escape') { closeMetaKeyDropdown(); }
        });
    }

    // Clicking a destination meta-key input (e.g. the COG row's) opens the
    // branded picker built from the same mmi_get_product_meta_keys data the
    // Add Custom Field modal uses. fetchProductMetaKeysAndTaxonomies() caches
    // its result, so only the FIRST click in a page session pays for the real
    // AJAX round trip — but that first click had no visual feedback at all
    // between click and the dropdown appearing, which read as unresponsive.
    // .mmi-cog-meta-key-loading (a .mmi-loading spinner, see AGENTS.md's
    // Button Loading States convention) fills that gap.
    $(document).on('click', '.mmi-meta-key-input', function () {
        const $input = $(this);
        if ($input.prop('disabled') || $input.hasClass('meta-key-dropdown-open')) { return; }

        const $spinner = $input.siblings('.mmi-cog-meta-key-loading');
        $spinner.removeClass('mmi-is-hidden');

        fetchProductMetaKeysAndTaxonomies().then(function (response) {
            $spinner.addClass('mmi-is-hidden');
            if (!response.success) {
                showAutosaveIndicator('✗ Failed to load meta keys: ' + ((response.data && response.data.message) || 'Unknown error'), 'error');
                return;
            }
            const allMetaKeys = response.data.meta_keys || [];
            const groupedMeta = META_KEY_SOURCE_GROUPS.map(function (group) {
                return {
                    id: group.id,
                    label: group.label,
                    items: allMetaKeys.filter(function (m) { return metaKeySourceGroupId(m.source) === group.id; }),
                };
            });
            openMetaKeyDropdown($input, groupedMeta);
        }, function () {
            $spinner.addClass('mmi-is-hidden');
            showAutosaveIndicator('✗ Error loading meta keys — try again', 'error');
        });
    });

    // Build/rebuild a field-mapping row's Source <select> options from the
    // supplier's real detected fields ONLY — no "Custom / Other…" escape
    // hatch (unlike populatePkFieldSelect() below, which has one for the
    // Primary Key field). The sample value is folded into each option's own
    // label rather than a title tooltip, since this list is typically
    // browsed by scanning many rows rather than one at a time.
    function populateSourceFieldSelect($select, fields) {
        const currentValue = $select.val();
        $select.empty();
        $select.append($('<option></option>').val('').text('— Select a field —'));
        fields.forEach(function (fieldObj) {
            const label = fieldObj.sample ? (fieldObj.name + ' — ' + fieldObj.sample) : fieldObj.name;
            $select.append($('<option></option>').val(fieldObj.name).text(label));
        });

        // A saved value that isn't one of these real fields (a mapping that
        // predates this feed, or the feed has since changed) has nothing to
        // select here — it falls back to the blank placeholder rather than
        // a synthetic option, and MMI_Pipeline_Config_Validator's pre-flight
        // check still flags it before an import/schedule run relies on it.
        const matchExists = currentValue && fields.some(function (f) { return f.name === currentValue; });
        $select.val(matchExists ? currentValue : '');
    }

    // Build/rebuild the primary-key "Field in the file" <select>'s options
    // from the file's real detected fields, preserving whichever value is
    // currently selected if it's still in the list. Sample value is folded
    // into the option's own label (matching populateSourceFieldSelect()'s
    // "name — sample" format above) rather than a hover-only title, so the
    // user can see real data at a glance without hovering every option.
    // Falls back to "Custom / Other…" (revealing the sibling text input) for
    // a saved dot-path the sample scan didn't surface — mmi_pipeline_extract_field_names()
    // (ImportSettingsController.php) only recurses 2 levels deep, so a field
    // nested deeper than that is a real, if rare, gap this covers.
    function populatePkFieldSelect($select, fields) {
        const currentValue = $select.val();
        $select.empty();
        fields.forEach(function (fieldObj) {
            const hasSample = fieldObj.sample && fieldObj.sample !== '(no sample)';
            const label = hasSample ? (fieldObj.name + ' — ' + fieldObj.sample) : fieldObj.name;
            $select.append($('<option></option>').val(fieldObj.name).text(label));
        });
        $select.append($('<option>').attr('value', '__custom__').text('✎ Custom / Other…'));

        const $customInput = $select.siblings('.primary-key-source-custom-input');
        const matchExists  = currentValue && fields.some(function (f) { return f.name === currentValue; });
        if (matchExists) {
            $select.val(currentValue);
            $customInput.addClass('mmi-is-hidden');
        } else if (currentValue && currentValue !== '__custom__') {
            $select.val('__custom__');
            $customInput.removeClass('mmi-is-hidden').val(currentValue);
        }
    }

    // Primary key — file selector: repopulate the source-field select for
    // whichever feed file is selected (separate classes/handler from the
    // field-mapping panel's own .field-file-selector so the two autosave
    // paths — this one writes the primary key, that one writes
    // field_mappings — never both fire off a single change event).
    $(document).on('change', '.pk-file-selector', function() {
        const $select    = $(this);
        const supplier   = $select.data('supplier');
        const filename   = $select.val();
        const $group     = $select.closest('.field-selector-group');
        const $fieldSelect = $group.find('.primary-key-source-input');

        if (!filename) {
            return;
        }

        loadFieldsFromFile(supplier, filename).then(function(fields) {
            populatePkFieldSelect($fieldSelect, fields);
        });
    });

    // Primary key — preload each row's source-field select from its
    // default-selected file, same as the field-mapping panel's init loop
    // below but scoped to .pk-file-selector. Scoped by $scope (like
    // initFieldFileSelectors() below) so it can be re-run against
    // just-injected rows — e.g. refreshWizardSourcesList() replacing
    // #new-profile-sources-list with fresh server HTML on every wizard open —
    // instead of only ever running once at page load.
    function initPkFileSelectors($scope) {
        ($scope && $scope.length ? $scope.find('.pk-file-selector').addBack('.pk-file-selector') : $('.pk-file-selector')).each(function() {
            const $select    = $(this);
            const filename   = $select.val();
            const supplier   = $select.data('supplier');
            const $group     = $select.closest('.field-selector-group');
            const $fieldSelect = $group.find('.primary-key-source-input');

            if (!filename) {
                return;
            }

            loadFieldsFromFile(supplier, filename).then(function(fields) {
                populatePkFieldSelect($fieldSelect, fields);
            });
        });
    }
    initPkFileSelectors();

    // Primary key — populate every row's "Custom Field" meta-key select from
    // the store's known product meta keys (same data source as the Scope
    // step's identifier meta-key datalist: mmiImportSettings.productMetaKeys).
    // Available synchronously, unlike the file-based field selects above, so
    // this runs immediately rather than via a promise.
    function populatePkCustomSelect($select) {
        const metaKeys = (window.mmiImportSettings && mmiImportSettings.productMetaKeys) || [];
        const currentValue = $select.val();
        $select.empty();
        metaKeys.forEach(function (key) {
            $select.append($('<option>').attr('value', key).text(key));
        });
        $select.append($('<option>').attr('value', '__custom__').text('✎ Custom / Other…'));

        const $customInput = $select.siblings('.primary-key-wc-custom-input');
        const matchExists  = currentValue && metaKeys.indexOf(currentValue) !== -1;
        if (matchExists) {
            $select.val(currentValue);
            $customInput.addClass('mmi-is-hidden');
        } else if (currentValue && currentValue !== '__custom__') {
            $select.val('__custom__');
            $customInput.removeClass('mmi-is-hidden');
        }
    }
    // Scoped like initPkFileSelectors() above, for the same just-injected-rows reason.
    function initPkCustomSelectors($scope) {
        ($scope && $scope.length ? $scope.find('.primary-key-wc-custom-select').addBack('.primary-key-wc-custom-select') : $('.primary-key-wc-custom-select')).each(function () {
            populatePkCustomSelect($(this));
        });
    }
    initPkCustomSelectors();

    // Set input disabled/placeholder state, then (for any file selector that
    // already has a value — either a pre-selected <select> or the hidden
    // single-file input rendered when a source has only one real file to
    // choose between, see panel-field-mapping.php) warm the field cache for
    // it. Direct call to loadFieldsFromFile avoids hitting the autosave
    // handler (which would show a misleading 'Saved' toast). Scoped so it can
    // be re-run against just-injected rows (custom fields, newly
    // added/synced sources) instead of only ever running once at page load.
    function initFieldFileSelectors($scope) {
        ($scope && $scope.length ? $scope.find('.field-file-selector').addBack('.field-file-selector') : $('.field-file-selector')).each(function() {
            const $select = $(this);
            const supplier = $select.data('supplier');
            // Joined via the shared .field-mapping-row + data-supplier, not a
            // DOM-nesting .find() — see fileSelectorFor()/supplierSourceRowFor()'s
            // own comment for why (.field-file-selector now lives in its own
            // col-source-file column, a sibling <td> of col-source-field).
            const $supplierRow = supplierSourceRowFor($select, supplier);
            const $sourceSelect = $supplierRow.find('.mmi-source-path-input');
            if (!$sourceSelect.length) { return; } // legacy single-source row — nothing to init
            // A row whose Constant toggle is already on keeps its path select
            // disabled regardless of file choice — syncPerSourceConstantVisualState()
            // already set that; this loop's own disabled toggle below is only
            // the file-choice gate and must not override it back to enabled.
            const usingConstant = $supplierRow.find('.use-constant-checkbox').is(':checked');

            // A blank file selector does NOT mean "nothing to browse" — most
            // rows never have this dropdown touched at all (their source value
            // was typed directly, restored from a saved profile, or set via
            // Auto-Map, none of which go through this control), so this used
            // to disable the select on every page load for any field whose
            // file was never explicitly chosen — exactly the fields most
            // likely to need browsing. Fall back to the same "first available
            // file" resolution Auto-Map/priming already use instead. This is
            // a local, client-side fallback only — it never writes back to
            // $select or triggers an autosave.
            const filename = $select.val() || (supplier ? resolveSupplierFilename(supplier) : '');

            if (!filename) {
                $sourceSelect.prop('disabled', true);
                return;
            }

            $sourceSelect.prop('disabled', true);
            loadFieldsFromFile(supplier, filename).then(function(fields) {
                populateSourceFieldSelect($sourceSelect, fields);
                $sourceSelect.prop('disabled', usingConstant);

                // populateSourceFieldSelect() resets the select's value via
                // .val() (a setter — never fires 'change') whenever the
                // previously-selected value isn't one of this file's real
                // fields. updateMappedIndicators()'s own delegated
                // .mmi-source-path-input listener never sees that, so every
                // caller of this function (initial page load, a freshly-added
                // custom field, restoring a saved profile) needs this called
                // explicitly once priming settles, or a now-unmapped row's
                // "Not mapped" pill stays stale until an unrelated action
                // happens to recompute it.
                updateMappedIndicators();
            });
        });
    }
    // Deliberately NOT called eagerly here: every element this targets
    // (.field-file-selector) lives inside the Field Mapping wizard modal,
    // display:none until "New/Edit Profile" is clicked — see
    // primeSourceFieldCache()'s identical reasoning a few hundred lines up.
    // Runs once, the first time the wizard actually opens (openProfileWizard()).
    //
    // Exposed so import-pipeline-sources.js can run the same preload against
    // rows it injects dynamically (adding a source / toggling one live) —
    // that call is unaffected by this change, since it already only ever
    // fires in response to a real, later DOM change, not page load.
    window.mmiInitFieldFileSelectors = initFieldFileSelectors;
    
    // ===== IMPORT PROFILE MANAGEMENT =====
    
    // Load profile when selected
    $(document).on('change', '#mmi-import-profile', function() {
        const profileId = $(this).val();
        const oldProfile = currentProfile;
        
        // Only proceed if profile actually changed
        if (profileId === oldProfile) {
            return;
        }
        
        currentProfile = profileId;
        
        // Load profile data
        loadProfile(profileId, true);
    });
    
    // Handle browser back/forward buttons
    window.addEventListener('popstate', function(event) {
        if (event.state && event.state.profile) {
            const profileId = event.state.profile;
            if (profileId !== currentProfile) {
                // Update dropdown and trigger load
                currentProfile = profileId;
                $('#mmi-import-profile').val(profileId);
                
                // Load the profile data
                loadProfile(profileId, false); // false = don't update URL
            }
        }
    });
    
    /**
     * Suppress WordPress's built-in "unsaved changes" form-dirty detection
     * during profile switches so the browser doesn't prompt on reload/navigate.
     */
    function suppressWordPressUnsavedChanges() {
        if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
            try { wp.data.dispatch('core/editor').resetPost(); } catch (e) {}
        }
        // Detach any jQuery wp-check-for-changes handler on the settings form
        $('form[method="post"]').off('change.wp-check-for-changes');
    }

    /**
     * Re-attach WordPress form-dirty detection after a profile switch completes.
     */
    function restoreWordPressUnsavedChanges() {
        $('form[method="post"]').on('change.wp-check-for-changes', function() {
            if (!isProfileSwitching) {
                const el = this;
                if (el.dataset) { el.dataset.wpFormDirty = 'true'; }
            }
        });
    }

    /**
     * Load profile data via AJAX
     */
    function loadProfile(profileId, updateUrl = true) {
        isProfileSwitching = true; // Prevent unsaved changes warning
        
        // Suppress WordPress's unsaved changes detection during profile switch
        suppressWordPressUnsavedChanges();
        
        showAutosaveIndicator('Loading profile...', 'info');
        
        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_load_import_profile',
                nonce: mmiImportSettings.nonce,
                profile: profileId
            },
            success: function(response) {
                if (response.success) {
                    updateUIWithProfileData(response.data);
                    
                    if (updateUrl) {
                        // Update URL without page reload
                        const url = new URL(window.location);
                        if (profileId === 'default') {
                            url.searchParams.delete('profile');
                        } else {
                            url.searchParams.set('profile', profileId);
                        }
                        history.pushState({profile: profileId}, '', url.toString());
                    }
                    
                    showAutosaveIndicator('✓ Profile loaded', 'success');
                    
                    // Reset form tracking after profile switch
                    setTimeout(() => {
                        isProfileSwitching = false;
                        
                        // Reset WordPress form dirty state
                        restoreWordPressUnsavedChanges();
                        
                        // Capture new baseline form data
                        if ($('form[method=\"post\"]').length) {
                            originalFormData = new FormData($('form[method=\"post\"]')[0]);
                        }
                    }, 500);
                    
                    return true;
                } else {
                    isProfileSwitching = false;
                    restoreWordPressUnsavedChanges();
                    showAutosaveIndicator('✗ Failed to load profile', 'error');
                    console.error('Profile load failed:', response.data.message);
                    return false;
                }
            },
            error: function(xhr, status, error) {
                isProfileSwitching = false;
                restoreWordPressUnsavedChanges();
                showAutosaveIndicator('✗ Error loading profile', 'error');
                console.error('AJAX error:', status, error);
                return false;
            }
        });
    }

    /* ── PROFILE CREATION WIZARD ──────────────────────────────────────────── */

    // Ordered step panel IDs. The by_identifier filter config lives inline
    // inside panel 6 (Scope) now, not as its own panel — see mmi-scope-id-config
    // in section-profile-wizard.php — so it never appears in this list. Mode +
    // missing-action are likewise a sub-section of panel 6 (not their own
    // panel 7) — see #mmi-wizard-mode-section, hidden/shown by the
    // scope-change handler below rather than skipped as a whole step.
    //
    // Panel 1 (Type) is CREATE-only — filtered out below whenever
    // _wizardEditProfileId is set, same treatment as Panel 5 (Attributes,
    // Product-only) gets for a non-Product data type. Both are whole-step
    // skips (excluded from this array + their breadcrumb crumb hidden), not
    // a sub-section hidden within a shared panel — see
    // wizardSyncTypeCrumbVisibility()/wizardSyncAttributesCrumbVisibility()
    // and openProfileWizardShow()'s call into the former. Panel 5's
    // Product-only restriction: attributes are a WooCommerce-specific
    // concept (WC attribute taxonomies) with no meaningful analogue for a
    // generic post/taxonomy/order/etc. import (Milestone 3), so it's skipped
    // entirely rather than shown as a dead/disabled step.
    function wizardCurrentDataType() {
        return $('input[name="new_profile_data_type"]:checked').val() || 'product';
    }
    function wizardIsGenericType() {
        return wizardCurrentDataType() !== 'product';
    }
    /** Hides the Attributes breadcrumb crumb (+ its trailing separator) for
     * a non-Product data type — the crumb BEFORE it (Fields) keeps its own
     * separator, so exactly one `›` remains between Fields and Scope & Mode
     * once Attributes is skipped, rather than a doubled or missing one. */
    function wizardSyncAttributesCrumbVisibility() {
        const hide = wizardIsGenericType();
        $('#mmi-wizard-crumb-attributes, #mmi-wizard-crumb-sep-attributes').toggleClass('mmi-hidden', hide);
        wizardRenumberCrumbs();
    }

    /** Hides the Type breadcrumb crumb (+ its trailing separator) when
     * editing an existing profile — same "hide the crumb, keep the
     * separator on the OTHER side of it" trick as the Attributes crumb
     * above, except Type is the FIRST crumb (nothing precedes it), so its
     * own trailing separator is what has to go with it instead. Only needs
     * checking once per wizard open (edit vs. create doesn't change while
     * the wizard is open), so this is called from openProfileWizardShow()
     * rather than on every wizardShowPanel() step change the way the
     * Attributes sync is (data type CAN change mid-session). */
    function wizardSyncTypeCrumbVisibility() {
        const hide = !!_wizardEditProfileId;
        $('#mmi-wizard-crumb-type, #mmi-wizard-crumb-sep-type').toggleClass('mmi-hidden', hide);
    }

    /** Numbers every currently-VISIBLE crumb 1, 2, 3... contiguously, in DOM
     * order — called any time a crumb's visibility changes (data type
     * picked/changed, create vs. edit mode) so a hidden crumb (Type in edit
     * mode, Attributes for a non-Product type) never leaves a gap like
     * "3 • Fields, 5 • Scope & Mode" with no 4. See
     * section-profile-wizard.php's own comment on the breadcrumb markup for
     * why numbers aren't hardcoded there at all. */
    function wizardRenumberCrumbs() {
        let n = 0;
        $('.mmi-wizard-crumb').each(function () {
            if ($(this).hasClass('mmi-hidden')) { return; }
            n++;
            $(this).find('.mmi-wizard-crumb-num').text(n);
        });
    }
    function wizardSteps() {
        const steps = ['mmi-wizard-p1', 'mmi-wizard-p2', 'mmi-wizard-p3', 'mmi-wizard-p4', 'mmi-wizard-p5', 'mmi-wizard-p6'];
        return steps.filter(function (id) {
            if (id === 'mmi-wizard-p1' && _wizardEditProfileId) { return false; }
            if (id === 'mmi-wizard-p5' && wizardIsGenericType()) { return false; }
            return true;
        });
    }

    let _wizardCurrentPanel = 'mmi-wizard-p1';

    /** Base modal title (no profile name) — depends only on create vs edit mode. */
    function wizardBaseTitle() {
        return _wizardEditProfileId ? '⚙️ Edit Import Profile' : '➕ Create Import Profile';
    }

    /* Step 2 already asks for the name, so the title there (and on Step 1,
     * before the name field has even been reached) stays generic — anywhere
     * past it, show whatever name is currently entered so a user who has
     * switched profiles or opened several wizards in a row (Edit, New, Edit
     * again) always has a clear answer to "which profile am I looking at
     * right now?" without needing to click back to Step 2. Reads the live
     * input value (not just the profile's saved name) so it stays correct
     * even mid-edit, before Save has been clicked. */
    function updateWizardModalTitle(panelId) {
        const base = wizardBaseTitle();
        if (panelId === 'mmi-wizard-p1' || panelId === 'mmi-wizard-p2') {
            $('#new-profile-modal-title').text(base);
            return;
        }
        const name = $('#new-profile-name').val().trim();
        $('#new-profile-modal-title').text(name ? `${base}: ${name}` : base);
    }

    /**
     * Show/hide the wizard's Back/Next/Create footer buttons for the given
     * position. Extracted from wizardShowPanel() as its own hardening pass:
     * #mmi-wizard-back and #new-profile-modal-create were already hidden two
     * ways (a "mmi-hidden" !important CSS class AND jQuery's own inline
     * style), so either one alone is enough to keep them correctly hidden.
     * #mmi-wizard-next relied on the inline style ALONE — the one footer
     * button with no class-based backup — so anything that ever left a stale
     * inline style in place (a skipped .toggle() call, a duplicate handler
     * firing out of order, browser dev-tools state, a future edit that sets
     * .show()/.css('display', ...) directly on it) had nothing to fall back
     * on, and it could end up visible together with the Create/Save button
     * on the last step with no error or visible cause. Giving it the same
     * two-layer hiding its siblings already had removes that single point
     * of failure rather than just re-deriving the same one-layer toggle.
     */
    function wizardSyncFooterButtons(isFirst, isLast) {
        $('#mmi-wizard-back').toggleClass('mmi-hidden', isFirst).toggle(!isFirst);
        $('#mmi-wizard-next').toggleClass('mmi-hidden', isLast).toggle(!isLast);
        $('#new-profile-modal-create').toggleClass('mmi-hidden', !isLast).toggle(isLast);
    }

    /** Show a specific panel, hide all others, update breadcrumb + buttons. */
    function wizardShowPanel(panelId) {
        _wizardCurrentPanel = panelId;
        const steps = wizardSteps();
        const idx   = steps.indexOf(panelId);

        updateWizardModalTitle(panelId);

        // Toggle panels. Panels 2+ start with the "mmi-hidden" class in the
        // static markup (mmi-suite-common.css defines it as
        // "display:none !important"), which a plain jQuery .show() cannot
        // override — !important in a stylesheet always beats a non-important
        // inline style. Without removeClass() here, the panel container
        // becomes visible but its content stays display:none, producing a
        // blank step. addClass() it back on the panels being hidden so the
        // class stays in sync with actual visibility state.
        $('.mmi-wizard-panel').hide().addClass('mmi-hidden');
        $('#' + panelId).show().removeClass('mmi-hidden');

        // Reset the scroll region (.mmi-wizard-panel-wrap) to the top on every
        // step change — otherwise a long step (Field Mapping, Attributes)
        // left scrolled down leaves the next step opening mid-scroll instead
        // of at its own top.
        $('.mmi-wizard-panel-wrap').scrollTop(0);

        // Field Mapping/Attributes must reflect the WIZARD's own target
        // profile, not whatever the shared table last happened to show —
        // see wizardMaybeLoadMappings(). No-ops if already loaded for the
        // current wizard target.
        //
        // Field Mapping's table markup itself (not just its values) is now
        // also lazily AJAX-loaded the first time this step is opened, rather
        // than always being present in the page's initial HTML — see
        // wizardMaybeLoadFieldMappingPanel() and panel-field-mapping.php's
        // own $mmi_field_mapping_render_now docblock. It triggers
        // wizardMaybeLoadMappings() itself once the real table is in the DOM,
        // so p4 doesn't need to also call it directly here.
        if (panelId === 'mmi-wizard-p4') {
            wizardMaybeLoadFieldMappingPanel();
        } else if (panelId === 'mmi-wizard-p5') {
            wizardMaybeLoadMappings();
        }

        // Hide the Mode sub-section for new_only scope, whose behavior is
        // fixed (create new, never touch existing) — it's no longer a
        // separate, skippable step. Show the locked-mode notice in its place
        // so the fixed behavior is explained rather than silently hidden.
        const scope = $('input[name="new_profile_scope"]:checked').val() || 'all_products';
        const scopeIsNewOnly = scope === 'new_only';
        $('#mmi-wizard-mode-section').toggleClass('mmi-hidden', scopeIsNewOnly);
        $('#mmi-wizard-mode-locked-notice').toggleClass('mmi-hidden', !scopeIsNewOnly);

        wizardSyncAttributesCrumbVisibility();

        $('.mmi-wizard-crumb').each(function () {
            const thisPanelId = $(this).data('panel');
            const thisIdx     = steps.indexOf(thisPanelId);
            $(this)
                .toggleClass('mmi-is-active', thisPanelId === panelId)
                .toggleClass('mmi-is-done',   thisIdx !== -1 && thisIdx < idx);
        });

        const isFirst = idx === 0;
        const isLast  = idx === steps.length - 1;
        wizardSyncFooterButtons(isFirst, isLast);
        // Save & Close: lets an already-existing profile be saved and the
        // modal closed from any step, not just the last one — the final
        // step's own Save/Create button already covers that case there, so
        // this is hidden on the last step to avoid showing two buttons that
        // do the same thing.
        $('#mmi-wizard-save-exit').toggleClass('mmi-hidden', !_wizardEditProfileId || isLast).toggle(!!_wizardEditProfileId && !isLast);
    }

    /** Validate the current panel before advancing. Returns true if OK. */
    function wizardValidatePanel(panelId) {
        if (panelId === 'mmi-wizard-p2') {
            const name = $('#new-profile-name').val().trim();
            if (!name) {
                $('#new-profile-name').focus().addClass('mmi-field-error');
                return false;
            }
            $('#new-profile-name').removeClass('mmi-field-error');

            // A profile with no source configured has nothing to import from —
            // block progression the same way a missing name does, rather than
            // letting the user reach later steps (Field Mapping, Attributes)
            // that assume at least one source is in scope.
            const hasSource = $('#new-profile-sources-list .np-source-check:checked').length > 0;
            if (!hasSource) {
                $('#new-profile-source-error').removeClass('mmi-is-hidden');
                $('#new-profile-sources-list').addClass('mmi-field-error');
                $('#new-profile-source-error')[0].scrollIntoView({ block: 'nearest' });
                return false;
            }
            $('#new-profile-source-error').addClass('mmi-is-hidden');
            $('#new-profile-sources-list').removeClass('mmi-field-error');
        }
        // The identifier/filter config is an inline sub-section of panel 6
        // (Scope), not its own panel — only validate it when that panel is
        // being left AND "by_identifier" is the selected scope.
        if (panelId === 'mmi-wizard-p6') {
            const scope = $('input[name="new_profile_scope"]:checked').val();
            if (scope === 'by_identifier') {
                const idType = $('input[name="new_profile_id_type"]:checked').val();
                if (idType === 'taxonomy') {
                    const slug  = $('#new-profile-tax-slug').val() || '';
                    const terms = $('#new-profile-tax-term').val();   // array or null for multi-select
                    const hasTerm = terms && terms.length > 0;
                    if (!slug || !hasTerm) {
                        if (!slug) { $('#new-profile-tax-slug').trigger('focus').addClass('mmi-field-error'); }
                        else       { $('#new-profile-tax-term').addClass('mmi-field-error'); }
                        return false;
                    }
                    $('#new-profile-tax-slug').removeClass('mmi-field-error');
                    $('#new-profile-tax-term').removeClass('mmi-field-error');
                } else {
                    const key = $('#new-profile-meta-key').val().trim();
                    const val = $('#new-profile-meta-value').val().trim();
                    if (!key || !val) {
                        if (!key) { $('#new-profile-meta-key').focus().addClass('mmi-field-error'); }
                        else      { $('#new-profile-meta-value').focus().addClass('mmi-field-error'); }
                        return false;
                    }
                    $('#new-profile-meta-key, #new-profile-meta-value').removeClass('mmi-field-error');
                }
            }
        }
        return true;
    }

    // Tracks whether the wizard is editing an existing profile (its id) or
    // creating a new one (null) — read by the save handler to decide whether
    // to POST mmi_save_import_profile (create) or mmi_update_import_profile
    // (edit), and by the pre-fill logic below.
    let _wizardEditProfileId = null;

    // Which profile id's data the Field Mapping/Attributes steps currently
    // display — reset on every wizard open so wizardMaybeLoadMappings() always
    // re-syncs, and updated by that function once a load completes. Guards
    // against re-fetching on every Back/Next between steps 3-4-5.
    let _wizardLoadedProfileId = null;

    // Which profile id the Field Mapping table's own STRUCTURE (its columns —
    // which suppliers get a column, driven by that profile's assigned
    // sources) was last fetched for. Separate from _wizardLoadedProfileId,
    // which tracks the table's VALUES — the structure only needs re-fetching
    // when it's never been loaded at all, or when the wizard's target
    // profile changes to one with different assigned sources.
    let _wizardFieldMappingPanelLoadedFor = null;

    // Set by openProfileWizardToField() just before opening the wizard;
    // consumed (and cleared) the moment the Field Mapping panel for that
    // profile is actually in the DOM — either immediately, if it was already
    // loaded, or from wizardMaybeLoadFieldMappingPanel()'s AJAX success
    // callback otherwise. Lets a "Go to field" link from a config-issue panel
    // land the user on the exact row that triggered the issue, instead of
    // just opening the wizard to its default first step.
    let _pendingFieldFocus = null;

    /**
     * Scrolls to and briefly highlights a Field Mapping row inside the
     * currently-open wizard modal. No-ops quietly if the row isn't present
     * (e.g. the field was renamed/removed since the issue was reported).
     */
    function focusFieldMappingRow(fieldName) {
        const $row = $('#new-profile-modal .field-mapping-row[data-field="' + fieldName + '"]');
        if (!$row.length) { return; }
        $row[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
        $row.removeClass('mmi-field-row-highlight');
        // Force reflow so re-adding the class restarts the CSS animation if
        // the same row was just highlighted a moment ago.
        void $row[0].offsetWidth;
        $row.addClass('mmi-field-row-highlight');
    }

    /**
     * Opens the profile wizard directly to its Field Mapping step, focused on
     * one specific field — the "Go to field" action offered by pre-flight
     * config-issue panels (see renderConfigIssues()).
     */
    function openProfileWizardToField(profileId, fieldName) {
        _pendingFieldFocus = fieldName || null;
        openProfileWizard(profileId, function () {
            wizardShowPanel('mmi-wizard-p4');
            // wizardMaybeLoadFieldMappingPanel() no-ops (no AJAX, no success
            // callback) when the panel is already loaded for this profile —
            // handle that case here instead of waiting for a callback that
            // will never fire.
            if (_pendingFieldFocus && _wizardFieldMappingPanelLoadedFor === profileId) {
                const field = _pendingFieldFocus;
                _pendingFieldFocus = null;
                setTimeout(function () { focusFieldMappingRow(field); }, 50);
            }
        });
    }

    /**
     * Field Mapping's table markup (panel-field-mapping.php) is no longer
     * inlined into the page's initial HTML — see panel-field-mapping.php's
     * own $mmi_field_mapping_render_now docblock for why (it was ~1MB+ of
     * HTML shipped on every page load regardless of whether this wizard step
     * was ever opened). Fetches the real table via mmi_render_field_mapping_panel
     * the first time Step 4 opens for a given target profile, replaces the
     * lightweight placeholder with it, then forces wizardMaybeLoadMappings()
     * to (re)run against the freshly-inserted DOM so the table's VALUES get
     * populated too — mirroring exactly how the old inline-rendered table
     * always got its values filled in via that same function.
     */
    function wizardMaybeLoadFieldMappingPanel() {
        const profileId = wizardActiveProfileId();
        if (!profileId || profileId === _wizardFieldMappingPanelLoadedFor) { return; }

        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_render_field_mapping_panel',
                nonce: mmiImportSettings.nonce,
                profile: profileId,
                // Only meaningful server-side when profileId has no saved
                // profile row yet (mid-creation in this wizard, not saved
                // until the final step is submitted) — the server has no
                // other way to know which data type's fields to render for
                // a profile that doesn't exist in the database yet. See
                // FieldMappingPanelController.php's own comment on why it no
                // longer falls back to an arbitrary EXISTING profile here.
                data_type: wizardCurrentDataType(),
                // Same reasoning, for which supplier rows to render — without
                // this, a not-yet-saved profile has no saved 'sources' list
                // for the server to filter against, so every configured
                // supplier in the system renders as a row, not just the ones
                // actually checked on Step 2.
                sources: JSON.stringify(wizardSelectedSources())
            },
            success: function (response) {
                if (!response.success || !response.data || !response.data.html) { return; }
                // .html(), not .replaceWith() — #mmi-wizard-field-mapping-container
                // is a stable wrapper (section-profile-wizard.php) neither this nor
                // any other code path ever destroys, so this swap can't
                // silently stop finding its target the way a .replaceWith()
                // on the id-bearing element itself once did.
                $('#mmi-wizard-field-mapping-container').html(response.data.html);
                _wizardFieldMappingPanelLoadedFor = profileId;
                renderAllFieldConflicts(response.data.conflicts);

                // The Generic Field Mapping panel (non-Product data types,
                // panel-field-mapping-generic.php) has none of the Product-
                // specific DOM this section exists to populate/sync — it's
                // already fully rendered server-side with real saved values,
                // and mmi_load_import_profile's field_mappings response is
                // always Product-shaped regardless of the profile's actual
                // data type (see that handler's own comment), so calling
                // either function here would just be wasted work against
                // elements that don't exist. See DATA_PIPELINE_PHASE2_SCOPING.md
                // Milestone 2.
                if ($('#mmi-generic-field-mapping').length) {
                    if (_pendingFieldFocus && profileId === wizardActiveProfileId()) {
                        _pendingFieldFocus = null;
                    }
                    return;
                }

                // The table just replaced was rendered fresh for profileId, so
                // its values already reflect that profile server-side — but
                // force wizardMaybeLoadMappings() to run anyway (it no-ops
                // when profileId === _wizardLoadedProfileId) since the newly
                // inserted DOM elements haven't actually been populated by it
                // yet, and updateFieldMappingSingleSourceState() needs the
                // real table present to correctly set the .mmi-single-source
                // class (normally kept in sync only by Step 2's source
                // checkboxes changing, which may not fire again this session).
                _wizardLoadedProfileId = null;
                wizardMaybeLoadMappings();
                updateFieldMappingSingleSourceState();
                // The 'sources' POST param above scopes the server's initial
                // render correctly, but this freshly-inserted DOM still needs
                // the same live-toggle mechanism every other entry point into
                // this scoping already uses, so a Step 2 checkbox change
                // after this point keeps working against the new rows too.
                updateFieldMappingSupplierScope();
                // The freshly-inserted #field-mapping-preset-select is a brand
                // new element with none of the prior filtering applied — resync
                // it against the current data type/sources immediately.
                refreshPresetDropdownForContext();

                if (_pendingFieldFocus && profileId === wizardActiveProfileId()) {
                    const field = _pendingFieldFocus;
                    _pendingFieldFocus = null;
                    setTimeout(function () { focusFieldMappingRow(field); }, 50);
                }
            }
        });
    }

    /**
     * Field Mapping (panel-field-mapping.php) and Attributes
     * (panel-attribute-mapping.php) render exactly ONE copy of their markup
     * on the page, inside the wizard modal — so whatever profile's data they
     * last displayed just sits there until something explicitly refreshes it.
     * Called on entering Steps 3/4 (and via the breadcrumb jump in edit mode)
     * to pull the wizard's OWN target profile's real saved mappings —
     * defaults for a brand-new profile that's never been saved, or the
     * existing saved data when editing — via the same mmi_load_import_profile
     * endpoint the page-level profile switcher already uses.
     */
    function wizardMaybeLoadMappings() {
        const profileId = wizardActiveProfileId();
        if (!profileId || profileId === _wizardLoadedProfileId) { return; }
        _wizardLoadedProfileId = profileId;

        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_load_import_profile',
                nonce: mmiImportSettings.nonce,
                profile: profileId
            },
            success: function (response) {
                if (!response.success) { return; }
                if (response.data.field_mappings) {
                    updateFieldMappingsTable(response.data.field_mappings);
                }
                if (window.MMIAttributeMapping && typeof window.MMIAttributeMapping.loadConfigIntoUI === 'function') {
                    window.MMIAttributeMapping.loadConfigIntoUI(response.data.attribute_config || {});
                }
            }
        });
    }

    // Sources currently checked on Step 2 — sent as an override so the
    // validator can score a profile that hasn't been saved yet (a brand-new
    // profile mid-creation has no DB row for MMI_Pipeline_Config_Validator to
    // read sources/mode from otherwise; see validate()'s $overrides param).
    function wizardSelectedSources() {
        const sources = [];
        $('#new-profile-sources-list .np-source-check:checked').each(function () {
            sources.push($(this).val());
        });
        return sources;
    }

    let _wizardFieldValidateXhr = null;

    /**
     * Re-runs MMI_Pipeline_Config_Validator against the wizard's own current
     * selections (same check the Import tab's "Run Import" button and
     * schedule-enable toggle already run — see mmi_pipeline_validate_profile_config)
     * and renders the field-mapping-scoped subset of any issues found into
     * #mmi-wizard-field-warnings. Only issues carrying a `field` key are kept —
     * primary-key and source-feed-file issues belong to Data Sources, not this
     * step, so surfacing them here would block Field Mapping progress over
     * something this step can't fix.
     *
     * @param {function(Array):void} onDone Called with the field-scoped issues
     *   array (empty = clean) once validation completes or is skipped.
     */
    function wizardValidateFieldMappings(onDone) {
        const profileId = wizardActiveProfileId();
        if (!profileId) {
            renderWizardFieldIssues([]);
            onDone([]);
            return;
        }

        // MMI_Pipeline_Config_Validator is still entirely Product-shaped
        // (unconditionally reads MMI_Pipeline_Field_Mapping_Defaults'
        // 29-field Product schema regardless of the profile's real data
        // type — generalizing it is out of Milestone 2's scope, see
        // DATA_PIPELINE_PHASE2_SCOPING.md). Running it against a non-Product
        // profile's real assigned source would compare Product's field list
        // to a feed that was never meant to satisfy it, generating false
        // "field not found" issues that could block Next for no real reason.
        // Skip it entirely when the Generic Field Mapping panel is the one
        // loaded — same signal wizardMaybeLoadFieldMappingPanel() uses above.
        if ($('#mmi-generic-field-mapping').length) {
            renderWizardFieldIssues([]);
            onDone([]);
            return;
        }

        if (_wizardFieldValidateXhr) { _wizardFieldValidateXhr.abort(); }
        _wizardFieldValidateXhr = $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_pipeline_validate_profile_config',
                nonce: mmiImportSettings.nonce,
                profile: profileId,
                sources: JSON.stringify(wizardSelectedSources()),
                import_mode: $('input[name="new_profile_mode"]:checked').val() || 'update-only',
            },
            success: function (response) {
                const issues = (response.success && response.data && response.data.issues) || [];
                const fieldIssues = issues.filter(function (i) { return !!i.field; });
                renderWizardFieldIssues(fieldIssues);
                // Only 'critical' issues block Next/Save — MMI_Pipeline_Config_Validator
                // itself distinguishes the two (critical = a required/create-capable
                // field with no usable source; warning = everything else, e.g. a
                // stale promo-field mapping that no longer exists in the live feed).
                // This used to gate on fieldIssues.length alone, treating a handful
                // of long-standing, sometimes permanently-unfixable warnings (a
                // supplier's feed dropping a field the profile isn't even relying on)
                // identically to a real blocker — silently preventing ANY future edit
                // to the profile from ever being saved. Warnings still render above,
                // just don't block.
                const blockingIssues = fieldIssues.filter(function (i) { return i.severity === 'critical'; });
                onDone(blockingIssues);
            },
            error: function (jqXHR) {
                // A superseded request (aborted by a newer call) isn't a real
                // failure — the newer call's own success/error owns the outcome.
                if (jqXHR.statusText === 'abort') { return; }
                renderWizardFieldIssues([]);
                onDone([]);
            },
        });
    }

    /** Render (or clear) the Field Mapping step's inline issues panel. */
    function renderWizardFieldIssues(issues) {
        const $box = $('#mmi-wizard-field-warnings');
        if (!issues.length) {
            $box.addClass('mmi-is-hidden').empty();
            return;
        }

        const critical = issues.filter(function (i) { return i.severity === 'critical'; });
        const warning  = issues.filter(function (i) { return i.severity !== 'critical'; });

        $box.toggleClass('mmi-config-issues-critical', critical.length > 0)
            .toggleClass('mmi-config-issues-warning', critical.length === 0);

        // "fix before continuing" is only true when a critical issue is
        // present — warnings render here too (they're still worth seeing)
        // but no longer block Next/Save, so the heading shouldn't claim
        // they do.
        const heading = critical.length > 0
            ? 'Field Mapping issues — fix before continuing'
            : 'Field Mapping notices';
        let html = '<h4 class="mmi-config-issues-heading"><span class="dashicons dashicons-warning"></span> '
            + heading + '</h4>';
        html += '<ul class="mmi-config-issues-list">';
        critical.concat(warning).forEach(function (issue) {
            const issueClass = issue.severity === 'critical' ? 'mmi-config-issue-critical' : 'mmi-config-issue-warning';
            const icon       = issue.severity === 'critical' ? 'dashicons-dismiss' : 'dashicons-flag';
            html += '<li class="mmi-config-issue ' + issueClass + '"><span class="dashicons ' + icon + '"></span>'
                + $('<div>').text(issue.message).html() + '</li>';
        });
        html += '</ul>';
        $box.removeClass('mmi-is-hidden').html(html);
    }

    // Byte-for-byte the same markup as panel-field-mapping.php's own
    // placeholder branch (minus the id, which now lives on the stable
    // wrapper — see that file's comment). Used to synchronously reset the
    // Field Mapping panel back to a genuine loading state on every wizard
    // open, so a previous profile's real content can never be visible even
    // for a moment while the fresh AJAX request for the new profile is
    // still in flight.
    const FIELD_MAPPING_LOADING_HTML =
        '<div class="mmi-panel-content mmi-field-mapping-placeholder">' +
        '<div class="mmi-preview-loading">' +
        '<span class="mmi-spinner"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M286.7 96.1C291.7 113 282.1 130.9 265.2 135.9C185.9 159.5 128.1 233 128.1 320C128.1 426 214.1 512 320.1 512C426.1 512 512.1 426 512.1 320C512.1 233.1 454.3 159.6 375 135.9C358.1 130.9 348.4 113 353.5 96.1C358.6 79.2 376.4 69.5 393.3 74.6C498.9 106.1 576 204 576 320C576 461.4 461.4 576 320 576C178.6 576 64 461.4 64 320C64 204 141.1 106.1 246.9 74.6C263.8 69.6 281.7 79.2 286.7 96.1z"/></svg></span>' +
        'Loading field mappings&hellip;' +
        '</div>' +
        '</div>';

    /**
     * Open the profile wizard. Omit profileId to create a new profile (the
     * previous behavior, unchanged). Pass an existing profileId to edit it —
     * this replaces the old separate Edit Profile modal; the wizard pre-fills
     * every step from currentProfileMeta (already the correct profile's meta,
     * since Edit only ever targets the currently active profile) instead of
     * resetting to blank defaults.
     *
     * @param {string}   [profileId]
     * @param {function} [onShown] Called once the modal is actually visible
     *   (after openProfileWizardShow() runs) — e.g. openProfileWizardToField()
     *   uses this to jump straight to a specific step instead of the default
     *   first one.
     */
    function openProfileWizard(profileId, onShown) {
        _wizardEditProfileId = profileId || null;
        _wizardLoadedProfileId = null;
        // Reset on every open, matching _wizardLoadedProfileId's own reset
        // right above — previously only cleared from the Data Type radio's
        // click handler, which meant a fresh wizard open could still treat
        // a DIFFERENT, previously-viewed profile's id as "already loaded"
        // and skip firing a new request entirely.
        _wizardFieldMappingPanelLoadedFor = null;
        // Synchronous, before any AJAX round-trip — closes the flash window
        // itself (see the "stale field-mapping content flash" fix): even a
        // slow response can no longer leave a previous profile's real
        // toggles/values visible, only this loading state.
        $('#mmi-wizard-field-mapping-container').html(FIELD_MAPPING_LOADING_HTML);
        const meta = _wizardEditProfileId ? currentProfileMeta : null;
        if (_wizardEditProfileId && !meta) {
            alert('Profile data not yet loaded — please wait a moment and try again.');
            _wizardEditProfileId = null;
            return;
        }

        // First real opportunity to warm the Field Mapping panel's file/field
        // caches — see primeSourceFieldCache()'s own comment for why this
        // isn't done eagerly at page load instead (the modal is display:none
        // until now, so there was nothing to actually show for that cost
        // until this exact moment).
        primeSourceFieldCache();
        initFieldFileSelectors();

        refreshWizardSourcesList(function () {
            openProfileWizardShow(meta);
            if (typeof onShown === 'function') { onShown(); }
        });
    }

    // Re-fetches #new-profile-sources-list from the server before every wizard
    // open. The page's own inline render of this list is baked in at page load
    // and has no other refresh path — a source added via the Data Sources tab
    // (or any route besides the wizard's own inline upload widget) would
    // otherwise stay invisible to the wizard until a full page reload. See
    // "Wizard Source List Went Stale" in AGENTS.md.
    function refreshWizardSourcesList(callback) {
        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_pipeline_get_wizard_sources_html',
                nonce: mmiImportSettings.nonce
            },
            success: function (response) {
                if (response.success && response.data) {
                    if (typeof response.data.checklist_html === 'string') {
                        $('#new-profile-sources-list').html(response.data.checklist_html);
                    }
                    if (typeof response.data.pk_editor_html === 'string') {
                        // The freshly-injected rows' primary-key selects (file
                        // choice + "Field in the file" + "Custom Field" meta-key)
                        // only ever got populated with real detected fields by
                        // the page-load-once init loops above — without this,
                        // any row this refresh adds/replaces is stuck showing
                        // only the PHP bootstrap placeholder option plus
                        // "Custom / Other…".
                        const $pkList = $('#new-profile-pk-editors-list').html(response.data.pk_editor_html);
                        initPkFileSelectors($pkList);
                        initPkCustomSelectors($pkList);
                    }
                }
                callback();
            },
            error: function () {
                // Fall back to whatever the page already rendered rather than
                // blocking the wizard from opening at all.
                callback();
            }
        });
    }

    function openProfileWizardShow(meta) {
        // Always reopen at the start of the flow, never inside whatever nested
        // popup the user happened to abandon last time. Any layer that opens on
        // top of the wizard gets dismissed here so its leftover state can't
        // resurface over a freshly-opened wizard.
        if (window.MMIDataPipeline && typeof window.MMIDataPipeline.hideAllPanels === 'function') {
            window.MMIDataPipeline.hideAllPanels();
        }

        $('#new-profile-modal-title').text(wizardBaseTitle());
        $('#new-profile-modal-create').text(_wizardEditProfileId ? '💾 Save Changes' : '➕ Create Profile');

        // Breadcrumb steps are only jump-clickable when editing an existing,
        // already-valid profile. For a brand-new profile there's nothing saved
        // yet to jump to — Next/Back are the only way through so required
        // fields (e.g. Name) can't be skipped.
        $('#mmi-wizard-breadcrumb').toggleClass('mmi-edit-mode', !!_wizardEditProfileId);

        // Panel 1 (Type) is create-only — a whole-step skip, not just its
        // content, so its breadcrumb crumb is hidden here too (see
        // wizardSteps()/wizardSyncTypeCrumbVisibility()). Only needs setting
        // once per open, unlike the Attributes crumb, since edit-vs-create
        // can't change mid-session. Renumbering happens below, once
        // wizardShowPanel() runs at the end of this function and calls
        // wizardSyncAttributesCrumbVisibility() with the data type radio
        // already set to its final value for this open — no need to
        // renumber here too against a still-stale data type/crumb state.
        wizardSyncTypeCrumbVisibility();
        const dataType = (meta && meta.data_type) || 'product';
        $('.mmi-scope-card[data-data-type]').removeClass('mmi-is-selected');
        $(`.mmi-scope-card[data-data-type="${dataType}"]`).addClass('mmi-is-selected');
        $(`input[name="new_profile_data_type"][value="${dataType}"]`).prop('checked', true);

        const scope        = meta ? (meta.product_scope || 'all_products') : 'all_products';
        const pid          = meta ? meta.product_identifier : null;
        const idType       = pid ? (pid.storage_type || 'taxonomy') : 'taxonomy';
        const mode         = meta ? (meta.import_mode || 'update-only') : 'update-only';
        const missingAction = (meta && meta.mode_settings && meta.mode_settings.availability_action) || 'none';

        // Reset/populate all fields
        $('#new-profile-name').val(meta ? (meta.name || '') : '').removeClass('mmi-field-error');
        $(`input[name="new_profile_scope"][value="${scope}"]`).prop('checked', true);
        $(`input[name="new_profile_id_type"][value="${idType}"]`).prop('checked', true);
        $(`input[name="new_profile_mode"][value="${mode}"]`).prop('checked', true);
        $(`input[name="new_profile_missing_action"][value="${missingAction}"]`).prop('checked', true);

        $('#new-profile-tax-slug').removeClass('mmi-field-error');
        $('#new-profile-tax-term').removeClass('mmi-field-error');
        if (idType === 'taxonomy') {
            $('#new-profile-meta-key, #new-profile-meta-value').val('').removeClass('mmi-field-error');
        } else {
            $('#new-profile-meta-key').val(pid && pid.storage_config ? (pid.storage_config.meta_key || '') : '').removeClass('mmi-field-error');
            $('#new-profile-meta-value').val(pid && pid.storage_config ? (pid.storage_config.meta_value || '') : '').removeClass('mmi-field-error');
        }
        $('#new-profile-id-auto-apply').prop('checked', pid ? !!pid.auto_apply_to_new : true);
        $('input[name="mmi_id_plugin[]"]').prop('checked', false);
        if (pid && pid.onboarded_plugins) {
            pid.onboarded_plugins.forEach(function (p) {
                $(`input[name="mmi_id_plugin[]"][value="${p}"]`).prop('checked', true);
            });
        }

        // Sources checklist + Step 3 primary-key editor visibility.
        // .trigger('change') reuses the existing np-source-check change
        // handler to reveal each selected source's PK editor row (matched by
        // data-supplier, not DOM nesting — see that handler's own comment),
        // rather than duplicating that logic here.
        $('#new-profile-sources-list .np-source-check').prop('checked', false);
        $('#new-profile-pk-editors-list .mmi-source-row').addClass('mmi-is-hidden');
        // Defaults to ON — advanced fields (sale pricing, dimensions, gallery,
        // custom) are common enough that hiding them by default just adds an
        // extra click for most profiles; the toggle still lets anyone collapse
        // back to the curated set.
        $('#wizard-show-advanced-fields').prop('checked', true);
        $('#mmi-wizard-p4').addClass('mmi-show-advanced-fields');
        // Preset selection doesn't carry over between profiles — Apply/Delete
        // should always require a fresh, deliberate re-selection.
        $('#field-mapping-preset-select').val('').trigger('change');
        const assignedSources = meta && Array.isArray(meta.sources) ? meta.sources : [];
        assignedSources.forEach(function (srcId) {
            $('#new-profile-sources-list .np-source-check[value="' + srcId + '"]').prop('checked', true).trigger('change');
        });
        // Explicit call covers the empty-assignedSources case (no per-checkbox
        // 'change' fires to trigger it otherwise) — re-syncs Field Mapping's
        // supplier-column visibility to "all enabled" for this profile.
        updateFieldMappingSupplierScope();

        // Scope card visual selection
        $('.mmi-scope-card').removeClass('mmi-is-selected');
        $(`.mmi-scope-card[data-scope="${scope}"]`).addClass('mmi-is-selected');
        $('#mmi-wizard-mode-section').toggleClass('mmi-hidden', scope === 'new_only');
        $('#mmi-wizard-mode-locked-notice').toggleClass('mmi-hidden', scope !== 'new_only');
        $('#mmi-scope-id-config').toggleClass('mmi-is-hidden', scope !== 'by_identifier');

        // Mode card visual selection
        $('.mmi-mode-card').removeClass('mmi-is-selected');
        $(`.mmi-mode-card[data-mode="${mode}"]`).addClass('mmi-is-selected');

        // Identifier type card visual selection
        $('.mmi-identifier-type-card').removeClass('mmi-is-active');
        $('#mmi-id-type-card-' + (idType === 'taxonomy' ? 'taxonomy' : 'meta')).addClass('mmi-is-active');
        $('#mmi-id-fields-taxonomy').toggle(idType === 'taxonomy');
        $('#mmi-id-fields-meta').toggleClass('mmi-hidden', idType !== 'post_meta').toggle(idType === 'post_meta');

        // Populate taxonomy select from server-provided list, pre-selecting the
        // saved taxonomy + fetching/pre-selecting its saved terms when editing.
        const taxes = (window.mmiImportSettings && mmiImportSettings.productTaxonomies) || [];
        const savedTaxSlug = (idType === 'taxonomy' && pid && pid.storage_config) ? (pid.storage_config.taxonomy_slug || '') : '';
        const $taxSelect = $('#new-profile-tax-slug').empty().append('<option value="">— select a taxonomy —</option>');
        taxes.forEach(function (t) {
            const label = t.label ? `${t.label} (${t.slug})` : t.slug;
            $taxSelect.append(`<option value="${escHtml(t.slug)}">${escHtml(label)}</option>`);
        });
        if (savedTaxSlug) {
            $taxSelect.val(savedTaxSlug);
            const savedTermSlugs = (pid.storage_config.term_slug_or_id || '')
                .split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            loadTaxonomyTerms(savedTaxSlug, $('#new-profile-tax-term'), savedTermSlugs);
        } else {
            $('#new-profile-tax-term')
                .empty()
                .append('<option value="" disabled>Select a taxonomy first…</option>')
                .prop('disabled', true)
                .val(null);
        }

        // Populate meta-key suggestions from server-provided list (Custom Post Meta).
        // meta_key values come from the DB (wp_postmeta), not trusted code-registered
        // strings like the taxonomy slugs above, so escape before building HTML.
        const metaKeys = (window.mmiImportSettings && mmiImportSettings.productMetaKeys) || [];
        const $metaKeyList = $('#new-profile-meta-key-list').empty();
        metaKeys.forEach(function (k) {
            $metaKeyList.append($('<option>').attr('value', k));
        });
        const savedMetaKey = (idType === 'post_meta' && pid && pid.storage_config) ? (pid.storage_config.meta_key || '') : '';
        loadMetaValueSuggestions(savedMetaKey, $('#new-profile-meta-value-list'));

        // Panel 1 (Type) is CREATE-only and excluded from wizardSteps() when
        // editing (see wizardSyncTypeCrumbVisibility() above) — opening
        // straight into it in edit mode would show a step wizardShowPanel()
        // itself never treats as reachable (idx -1: neither "first" nor
        // "last", so the footer buttons would come out wrong), so edit mode
        // starts at Panel 2 (Name & Source) instead, the actual first
        // reachable step for an existing profile.
        wizardShowPanel(_wizardEditProfileId ? 'mmi-wizard-p2' : 'mmi-wizard-p1');
        updateMissingActionForMode(mode);

        // Inline section, not a modal overlay — expand into page flow and
        // scroll it into view instead of fadeIn(), and there's no backdrop
        // to dim behind it. See IMPORT_WIZARD_INLINE_SECTION_HANDOFF.md.
        const $wizardSection = $('#new-profile-modal');
        $wizardSection.slideDown(200);
        $wizardSection[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Open wizard – "New Profile" button (profile bar)
    $(document).on('click', '#new-profile-btn', function () {
        openProfileWizard();
    });

    // Open wizard – "Create Your First Profile" empty-state button
    $(document).on('click', '#no-profiles-create-btn', function () {
        openProfileWizard();
    });

    // Wizard: Next button
    $(document).on('click', '#mmi-wizard-next', function () {
        if (!wizardValidatePanel(_wizardCurrentPanel)) { return; }
        const steps = wizardSteps();
        const idx   = steps.indexOf(_wizardCurrentPanel);

        // Leaving the Field Mapping step: re-check against each mapped
        // source's real feed data and block advancing while any field-mapping
        // issue remains — surfaced inline in #mmi-wizard-field-warnings rather
        // than left to appear later as a popup on the Import tab.
        if (_wizardCurrentPanel === 'mmi-wizard-p4') {
            const $next = $(this).prop('disabled', true);
            wizardValidateFieldMappings(function (fieldIssues) {
                $next.prop('disabled', false);
                if (fieldIssues.length > 0) { return; }
                if (idx < steps.length - 1) { wizardShowPanel(steps[idx + 1]); }
            });
            return;
        }

        if (idx < steps.length - 1) { wizardShowPanel(steps[idx + 1]); }
    });

    // Wizard: Back button
    $(document).on('click', '#mmi-wizard-back', function () {
        const steps = wizardSteps();
        const idx   = steps.indexOf(_wizardCurrentPanel);
        if (idx > 0) { wizardShowPanel(steps[idx - 1]); }
    });

    // Wizard: breadcrumb jump-navigation — edit mode only (see the
    // mmi-edit-mode toggle in openProfileWizard()). A new profile isn't saved
    // yet, so there's nothing valid to jump to; Next/Back enforce the
    // required-field order instead.
    $(document).on('click', '.mmi-wizard-crumb', function () {
        if (!_wizardEditProfileId) { return; }
        const panelId = $(this).data('panel');
        if (!panelId || wizardSteps().indexOf(panelId) === -1) { return; }
        // Jumping away from Step 2 must pass the same name/source check as
        // the Next button — otherwise an edit-mode user could clear the name
        // or uncheck every source on Step 2 and skip validation entirely by
        // clicking straight to a later crumb.
        if (_wizardCurrentPanel === 'mmi-wizard-p2' && panelId !== 'mmi-wizard-p2' && !wizardValidatePanel('mmi-wizard-p2')) {
            return;
        }
        wizardShowPanel(panelId);
    });

    // Data Type radio click → visual card selection, plus invalidate the
    // Field Mapping panel's load-cache. Bound to 'click', not 'change': a
    // browser never fires 'change' on a radio when it's clicked while
    // already checked (no state transition to report), so a 'change'-only
    // binding left the default-selected first card (Product) unable to
    // re-apply its own 'mmi-is-selected' class on a direct click — it only
    // ever worked after picking a different type first, whose real
    // checked-state transition fires 'change' normally. 'click' fires on
    // every click regardless of prior state, closing that gap; the checked
    // property is already updated by the time a label's associated-control
    // click reaches this handler, so nothing downstream needed to change.
    //
    // wizardMaybeLoadFieldMappingPanel() keys its "already loaded, don't
    // re-fetch" check purely on the wizard's profile-name slug — which
    // doesn't change when the Data Type radio does — so without this,
    // changing type AFTER already viewing Step 4 once in the same wizard
    // session (Back to Step 1, pick a different type, Next back to Step 4)
    // would silently keep showing the PREVIOUS type's field table instead of
    // re-fetching for the new one.
    $(document).on('click', 'input[name="new_profile_data_type"]', function () {
        $('.mmi-scope-card[data-data-type]').removeClass('mmi-is-selected');
        $(this).closest('.mmi-scope-card').addClass('mmi-is-selected');
        wizardSyncAttributesCrumbVisibility();
        refreshPresetDropdownForContext();
        _wizardFieldMappingPanelLoadedFor = null;
    });

    // Scope radio change → toggle the Mode sub-section + identifier config
    // Bound to 'click', not 'change' — see the identical fix/reasoning on the
    // Data Type radio's own handler above: 'change' never fires when a radio
    // that's already checked is clicked again, which left the default-
    // selected card unable to re-apply 'mmi-is-selected' on a direct click.
    $(document).on('click', 'input[name="new_profile_scope"]', function () {
        const val = $(this).val();
        // Visual card selection
        $('.mmi-scope-card').removeClass('mmi-is-selected');
        $(this).closest('.mmi-scope-card').addClass('mmi-is-selected');
        // Show/hide the identifier filter config inline, right under its card —
        // no panel navigation involved, it's a sub-section of this same panel.
        $('#mmi-scope-id-config').toggleClass('mmi-is-hidden', val !== 'by_identifier');
        // Mode + missing-action are a sub-section of this same panel now (not
        // their own step) — hide them entirely for new_only, whose behavior
        // is fixed (create new, never touch existing), and show the locked-mode
        // notice in their place so the fixed behavior is explained.
        $('#mmi-wizard-mode-section').toggleClass('mmi-hidden', val === 'new_only');
        $('#mmi-wizard-mode-locked-notice').toggleClass('mmi-hidden', val !== 'new_only');
    });

    // Identifier type radio click → toggle field groups. 'click', not
    // 'change' — same reasoning as the Data Type/Scope handlers above.
    $(document).on('click', 'input[name="new_profile_id_type"]', function () {
        const type = $(this).val();
        $('.mmi-identifier-type-card').removeClass('mmi-is-active');
        $(this).closest('.mmi-identifier-type-card').addClass('mmi-is-active');
        $('#mmi-id-fields-taxonomy').toggle(type === 'taxonomy');
        // #mmi-id-fields-meta starts with the "mmi-hidden" class in the
        // static markup (unlike #mmi-id-fields-taxonomy) — toggleClass must
        // accompany .toggle() here for the same reason as elsewhere in this file.
        $('#mmi-id-fields-meta').toggleClass('mmi-hidden', type !== 'post_meta').toggle(type === 'post_meta');
    });

    /* ── Mode-change: guide missing-action choices ───────────────── */

    /**
     * Per-mode rules that drive the missing-action guidance UI.
     * - `autoSelect`   : auto-switch the radio to this value when mode changes
     * - `recommended`  : show a "Recommended" badge on this option
     * - `cautious`     : show a "Stronger option" badge (available but flagged)
     * - `incompatible` : dim + visually de-emphasise (user can still override)
     * - `hint`         : contextual sentence shown above the choices
     */
    const MODE_MISSING_ACTION_RULES = {
        'create-and-update': {
            autoSelect:     null,
            recommended:    [],
            cautious:       [],
            incompatible:   [],
            hint:           '',
        },
        'create-only': {
            autoSelect:     null,
            recommended:    [],
            cautious:       [],
            incompatible:   [],
            hint:           '',
        },
        'update-only': {
            autoSelect:     null,
            recommended:    [],
            cautious:       [],
            incompatible:   [],
            hint:           '',
        },
        'availability-sync': {
            autoSelect:     'stock_zero',
            recommended:    ['stock_zero'],
            cautious:       ['unpublish'],
            incompatible:   ['none'],
            hint:           'This mode is built around reacting to disappearing products — leaving them alone would defeat the purpose.',
        },
    };

    /**
     * Update the missing-action section to guide users toward sensible
     * combinations based on the currently selected import mode.
     *
     * @param {string} mode - The selected import mode value.
     */
    function updateMissingActionForMode(mode) {
        const rules = MODE_MISSING_ACTION_RULES[mode] || MODE_MISSING_ACTION_RULES['update-only'];

        // Auto-select a sensible default when the mode changes
        if (rules.autoSelect) {
            $(`input[name="new_profile_missing_action"][value="${rules.autoSelect}"]`).prop('checked', true);
        }

        // Update each missing-action row
        $('.mmi-missing-radio').each(function () {
            const $row    = $(this);
            const action  = $row.data('action');
            const $badge  = $row.find('.mmi-action-badge');

            // Clear previous states. .mmi-action-badge/#mmi-missing-action-hint
            // both start with the "mmi-hidden" class in the static markup —
            // same !important-vs-inline-style conflict as the wizard panels;
            // .show() alone can never reveal them, so removeClass('mmi-hidden')
            // must accompany every .show() below.
            $row.removeClass('mmi-action-recommended mmi-action-cautious mmi-action-incompatible');
            // .mmi-badge itself (shared shell, mmi-suite-common.css) stays; only the
            // color modifier gets cleared.
            $badge.addClass('mmi-hidden').hide().text('').removeClass('success warning');

            if (rules.recommended.includes(action)) {
                $row.addClass('mmi-action-recommended');
                $badge.addClass('mmi-badge success').text('Recommended').removeClass('mmi-hidden').show();
            } else if (rules.cautious.includes(action)) {
                $row.addClass('mmi-action-cautious');
                $badge.addClass('mmi-badge warning').text('Stronger option').removeClass('mmi-hidden').show();
            } else if (rules.incompatible.includes(action)) {
                $row.addClass('mmi-action-incompatible');
                $badge.addClass('mmi-badge').text('Not recommended').removeClass('mmi-hidden').show();
            }
        });

        // Show or hide the contextual hint
        const $hint = $('#mmi-missing-action-hint');
        if (rules.hint) {
            $hint.text(rules.hint).removeClass('mmi-hidden').show();
        } else {
            $hint.addClass('mmi-hidden').hide().text('');
        }
    }

    // Sync guidance + selected-card highlight whenever a mode card radio is
    // clicked (mirrors the equivalent input[name="new_profile_scope"]
    // handler). Bound to 'click', not 'change' — same reasoning as the Data
    // Type/Scope/Identifier-Type handlers above.
    $(document).on('click', 'input[name="new_profile_mode"]', function () {
        $('.mmi-mode-card').removeClass('mmi-is-selected');
        $(this).closest('.mmi-mode-card').addClass('mmi-is-selected');
        updateMissingActionForMode($(this).val());
    });

    /**
     * Load taxonomy terms into a <select multiple> via AJAX, optionally
     * pre-selecting some of them (used both by the taxonomy dropdown's own
     * change handler below, and by the wizard's edit-mode pre-fill, which
     * needs to restore whatever terms were already saved on this profile).
     */
    function loadTaxonomyTerms(slug, $termSelect, selectedTermSlugs) {
        selectedTermSlugs = selectedTermSlugs || [];
        $termSelect.empty().prop('disabled', true).val(null);

        if (!slug) {
            $termSelect.append('<option value="" disabled>Select a taxonomy first…</option>');
            return;
        }

        $termSelect.append('<option value="" disabled>Loading terms…</option>');

        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action:   'mmi_pipeline_get_taxonomy_terms',
                nonce:    mmiImportSettings.nonce,
                taxonomy: slug,
            },
            success: function (response) {
                $termSelect.empty();
                if (response.success && response.data.terms.length) {
                    response.data.terms.forEach(function (t) {
                        const label = `${t.name} (${t.slug})${t.count ? ` — ${t.count} products` : ''}`;
                        $termSelect.append(`<option value="${escHtml(t.slug)}">${escHtml(label)}</option>`);
                    });
                    $termSelect.prop('disabled', false);
                    if (selectedTermSlugs.length) {
                        $termSelect.val(selectedTermSlugs);
                    }
                } else {
                    $termSelect
                        .append('<option value="" disabled>No terms found for this taxonomy</option>')
                        .prop('disabled', true);
                }
            },
            error: function () {
                $termSelect
                    .empty()
                    .append('<option value="" disabled>Failed to load terms — please try again</option>')
                    .prop('disabled', true);
            },
        });
    }

    // Existing-term picker for a taxonomy field's Const value (see
    // .mmi-constant-taxonomy-select in panel-field-mapping.php) — a locked
    // <select> restricted to that taxonomy's real terms only, no free-text
    // entry (a constant here must be an existing term, same restriction
    // loadTaxonomyTerms() above already enforces for the identifier picker).
    // Cached per taxonomy slug so checking Const on multiple supplier rows
    // for the same field (or re-checking it) only fetches once; every
    // matching <select> across all supplier rows for that taxonomy is
    // populated from the one response.
    const constantTaxonomyTermsLoaded = {};
    function loadConstantTaxonomyTerms(slug) {
        if (!slug || constantTaxonomyTermsLoaded[slug]) {
            return;
        }
        const $selects = $(`select.mmi-constant-taxonomy-select[data-taxonomy="${slug}"]`);
        if (!$selects.length) {
            return;
        }
        constantTaxonomyTermsLoaded[slug] = true;

        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action:   'mmi_pipeline_get_taxonomy_terms',
                nonce:    mmiImportSettings.nonce,
                taxonomy: slug,
            },
            success: function (response) {
                if (!response.success) {
                    return;
                }
                const terms = response.data.terms || [];
                $selects.each(function () {
                    const $select      = $(this);
                    const currentValue = $select.val();
                    $select.empty().append($('<option></option>').val('').text('-- Select a term --'));
                    terms.forEach(function (t) {
                        // Value is the term NAME, not slug — resolve_term_ids_smart()
                        // (server-side) matches an existing term by name.
                        $select.append($('<option></option>').val(t.name).text(t.name));
                    });
                    // A previously-saved value only survives if it's a real
                    // term in this taxonomy — mirrors populateSourceFieldSelect()'s
                    // own matchExists reconciliation. A stale/legacy value
                    // (e.g. free-typed before this became a locked select)
                    // falls back to the blank placeholder rather than a
                    // synthetic option.
                    const matchExists = currentValue && terms.some(function (t) { return t.name === currentValue; });
                    $select.val(matchExists ? currentValue : '');
                });
            },
            error: function () {
                // Allow a retry the next time this taxonomy's Const toggle fires.
                constantTaxonomyTermsLoaded[slug] = false;
            },
        });
    }

    // Preload real terms for any Const-enabled taxonomy row already visible
    // at render time (initial page load, or after a profile/preset load
    // rebuilds the table) — mirrors initFieldFileSelectors()'s same
    // "re-check what's already on screen" pattern below, since a value set
    // via .val() rather than a real user click fires no 'change' event for
    // the handler above to catch. Bounded by the number of distinct
    // taxonomy-type fields in the schema (a handful), not by row/product
    // count, so this stays well under the "no per-row AJAX fan-out" concern
    // that pattern exists to avoid.
    function preloadVisibleConstantTaxonomySelects($scope) {
        (($scope && $scope.length) ? $scope.find('.mmi-constant-taxonomy-select') : $('.mmi-constant-taxonomy-select')).each(function () {
            const $select = $(this);
            if (!$select.prop('disabled')) {
                loadConstantTaxonomyTerms($select.data('taxonomy'));
            }
        });
    }
    preloadVisibleConstantTaxonomySelects();

    // Taxonomy select change → load terms via AJAX
    $(document).on('change', '#new-profile-tax-slug', function () {
        loadTaxonomyTerms($(this).val(), $('#new-profile-tax-term'));
    });

    // Meta key change (New Profile wizard + Edit Profile modal) → suggest
    // existing values for that key via AJAX. Shared helper since both modals
    // need the identical fetch, just against a different target datalist.
    function loadMetaValueSuggestions(metaKey, $datalist) {
        $datalist.empty();
        if (!metaKey) {
            return;
        }
        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action:   'mmi_pipeline_get_meta_values',
                nonce:    mmiImportSettings.nonce,
                meta_key: metaKey,
            },
            success: function (response) {
                if (response.success && response.data.values) {
                    response.data.values.forEach(function (v) {
                        $datalist.append($('<option>').attr('value', v));
                    });
                }
            },
        });
    }
    $(document).on('change', '#new-profile-meta-key', function () {
        loadMetaValueSuggestions($(this).val().trim(), $('#new-profile-meta-value-list'));
    });

    // "+ Add a Data Source" link on the Source step — for API/URL/Dropbox/
    // Google Drive sources (upload is handled inline below, so this is now
    // only the escape hatch for the less common, credential-requiring types).
    // Those still require the full 5-tile modal, so this still closes the
    // wizard and hands off rather than trying to live-append a row for them.
    $(document).on('click', '#new-profile-add-source-link', function () {
        window._mmiWizardPendingSourceHandoff = true;
        $('#new-profile-modal').slideUp(200);
        $('#mmi-add-data-source-btn').trigger('click');
    });

    /**
     * Build one Step 2 checklist row + one Step 3 PK-editor row, exactly
     * matching wizard-sources-checklist.php / wizard-sources-pk-editor.php's
     * PHP-rendered templates, for a source just created inline via upload
     * (see the wizard-upload-create-btn handler below). Always "not
     * configured yet" since it's brand new — matches the PHP templates' own
     * defaults (source field "id", no match type pre-checked) for that
     * state. No radio starts checked: a radio's 'change' event only fires on
     * a real click, so a pre-checked default here would silently never
     * autosave — looking identical to a deliberately-confirmed choice while
     * actually being unconfigured (see the identical fix in
     * wizard-sources-pk-editor.php). Returns both rows separately since
     * Step 2 and Step 3 no longer share a common ancestor.
     */
    function buildSourceRowHtml(supplierId, supplierName) {
        const $checklistRow = $(
            '<div class="mmi-source-row">' +
                '<label class="mmi-ep-source-checkbox">' +
                    '<input type="checkbox" class="np-source-check" checked>' +
                    '<span class="mmi-ep-source-name"></span>' +
                    '<span class="mmi-pgc-source-pill mmi-pgc-source-pill--spaced">UPLOAD</span>' +
                '</label>' +
                '<div class="mmi-source-pk-status">' +
                    '<span class="mmi-source-pk-missing">Primary Key: not set</span>' +
                '</div>' +
            '</div>'
        );
        $checklistRow.find('.mmi-ep-source-name').text(supplierName);
        $checklistRow.find('.np-source-check').val(supplierId);
        $checklistRow.attr('data-supplier', supplierId);
        $checklistRow.find('.mmi-source-pk-status').attr('data-supplier', supplierId);

        const $pkEditorRow = $(
            '<div class="mmi-source-row mmi-is-hidden">' +
                '<div class="mmi-source-pk-editor-heading">' +
                    '<strong class="mmi-source-pk-editor-name"></strong>' +
                    '<span class="mmi-pgc-source-pill mmi-pgc-source-pill--spaced">UPLOAD</span>' +
                '</div>' +
                '<div class="mmi-source-pk-editor">' +
                    '<div class="mmi-pk-relationship-row">' +
                        '<div class="mmi-pk-source-col field-selector-group">' +
                            '<label class="mmi-pk-field-label">Field in the file</label>' +
                            // No file-selector here — a source just created via inline
                            // upload has no known file_options yet (that only exists
                            // for already-configured sources, same guard the PHP
                            // template uses). Field names aren't discoverable yet
                            // either, so this starts as a bare "id" guess plus the
                            // Custom/Other escape hatch, same shape as the PHP template.
                            '<select class="primary-key-source-input" data-field-type="source">' +
                                '<option value="id">id</option>' +
                                '<option value="__custom__">✎ Custom / Other…</option>' +
                            '</select>' +
                            '<input type="text" class="primary-key-source-custom-input mmi-is-hidden" placeholder="e.g. attributes.mpn">' +
                        '</div>' +
                        '<span class="mmi-pk-relationship-arrow" aria-hidden="true">→</span>' +
                        '<div class="mmi-pk-target-col pk-match-group">' +
                            '<span class="mmi-pk-field-label">Match against</span>' +
                            '<div class="mmi-pk-match-radios">' +
                                '<input type="radio" class="primary-key-wc-select" value="_sku"><label>SKU</label>' +
                                '<input type="radio" class="primary-key-wc-select" value="__post_id"><label>Post ID</label>' +
                                '<input type="radio" class="primary-key-wc-select" value="__post_title"><label>Post Title</label>' +
                                '<input type="radio" class="primary-key-wc-select" value="custom"><label>Custom Field</label>' +
                                '<select class="primary-key-wc-custom-select mmi-is-hidden">' +
                                    '<option value="__custom__">✎ Custom / Other…</option>' +
                                '</select>' +
                                '<input type="text" class="primary-key-wc-custom-input mmi-is-hidden" placeholder="_supplier_part_number">' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        $pkEditorRow.attr('data-supplier', supplierId);
        $pkEditorRow.find('.mmi-source-pk-editor-name').text(supplierName);
        $pkEditorRow.find('.mmi-source-pk-editor').attr('data-supplier', supplierId);
        $pkEditorRow.find('[data-field-type="source"]').attr('data-supplier', supplierId);
        $pkEditorRow.find('.primary-key-source-input, .primary-key-source-custom-input, .primary-key-wc-select, .primary-key-wc-custom-select, .primary-key-wc-custom-input')
            .attr('data-supplier', supplierId);
        $pkEditorRow.find('.primary-key-wc-select').each(function (i) {
            $(this).attr('name', 'pk_match_' + supplierId).attr('id', 'pk-match-' + i + '-' + supplierId);
            $(this).next('label').attr('for', 'pk-match-' + i + '-' + supplierId);
        });
        populatePkCustomSelect($pkEditorRow.find('.primary-key-wc-custom-select'));
        return { $checklistRow, $pkEditorRow };
    }
    // Exposed so the "Add Data Source" modal's success handler
    // (import-pipeline-sources.js) can add the same checkable row when it
    // was opened via the wizard's "+ Add a Data Source" handoff.
    window.MMIDataPipeline.buildWizardSourceRow = buildSourceRowHtml;

    // ── Inline "upload a new file" on the Source step (replaces having to
    // leave the wizard for the Quick Import wizard or the Add Data Source
    // modal's Upload tile just for this — the single most common case) ──
    $(document).on('click', '#wizard-upload-drop-zone', function (e) {
        if ($(e.target).closest('#wizard-drop-zone-result, #wizard-drop-zone-error').length) return;
        $('#wizard-upload-file-input').trigger('click');
    });
    $(document).on('click', '#wizard-upload-drop-zone .mmi-drop-browse-btn', function (e) {
        e.stopPropagation();
        $('#wizard-upload-file-input').trigger('click');
    });
    $(document).on('change', '#wizard-upload-file-input', function () {
        const file = this.files[0];
        if (file) { analyzeWizardUpload(file); }
    });
    $(document).on('click', '#wizard-upload-replace-btn, #wizard-upload-retry-btn', function (e) {
        e.stopPropagation();
        $('#wizard-drop-zone-idle').removeClass('mmi-is-hidden');
        $('#wizard-drop-zone-result, #wizard-drop-zone-error, #wizard-upload-name-row').addClass('mmi-is-hidden');
        $('#wizard-upload-file-input').val('').trigger('click');
    });
    $(document).on('dragover dragenter', '#wizard-upload-drop-zone', function (e) {
        e.preventDefault(); e.stopPropagation();
        $(this).addClass('mmi-drop-zone-over');
    });
    $(document).on('dragleave dragend', '#wizard-upload-drop-zone', function (e) {
        e.preventDefault(); e.stopPropagation();
        $(this).removeClass('mmi-drop-zone-over');
    });
    $(document).on('drop', '#wizard-upload-drop-zone', function (e) {
        e.preventDefault(); e.stopPropagation();
        $(this).removeClass('mmi-drop-zone-over');
        const file = e.originalEvent.dataTransfer.files[0];
        if (file) { analyzeWizardUpload(file); }
    });

    function analyzeWizardUpload(file) {
        window.MMIDataPipeline.analyzeUploadedFile(file, {
            selectors: {
                idleId:            'wizard-drop-zone-idle',
                resultId:          'wizard-drop-zone-result',
                errorId:           'wizard-drop-zone-error',
                analyzingId:       'wizard-drop-zone-analyzing',
                errorMsgId:        'wizard-drop-zone-error-msg',
                attachmentInputId: 'wizard-upload-attachment-id',
                formatInputId:     'wizard-upload-detected-format',
                resultFilenameId:  'wizard-upload-result-filename',
                resultMetaId:      'wizard-upload-result-meta',
            },
            onSuccess: function (d) {
                $('#wizard-source-name').val(d.suggested_name || '');
                $('#wizard-upload-name-row').removeClass('mmi-is-hidden');
            },
            onError: function (msg) {
                $('#wizard-drop-zone-error').removeClass('mmi-is-hidden');
                $('#wizard-drop-zone-error-msg').text(msg);
            },
        });
    }

    $(document).on('click', '#wizard-upload-create-btn', function () {
        const attachmentId = $('#wizard-upload-attachment-id').val();
        const format       = $('#wizard-upload-detected-format').val() || 'csv';
        const supplierName = $('#wizard-source-name').val().trim();
        if (!attachmentId) { return; }
        if (!supplierName) {
            $('#wizard-source-name').focus().addClass('mmi-field-error');
            return;
        }
        $('#wizard-source-name').removeClass('mmi-field-error');

        const $btn = $(this);
        $btn.prop('disabled', true).text('Adding…');

        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action:        'mmi_pipeline_quick_create_source',
                nonce:         mmiImportSettings.nonce,
                attachment_id: attachmentId,
                format:        format,
                supplier_name: supplierName,
            },
            success: function (response) {
                $btn.prop('disabled', false).text('Add as New Source');
                if (!response.success) {
                    alert('Could not create the source: ' + (response.data?.message || 'Unknown error'));
                    return;
                }
                const supplierId = response.data.supplier_id;

                // Keep the shared configuredSuppliers lookup (used to render
                // grid-card source pills after save) in sync with this
                // brand-new source, the same way the Add Data Source flow does.
                if (window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) {
                    window.mmiImportSettings.configuredSuppliers.push({
                        supplier_id: supplierId, supplier_name: supplierName,
                    });
                }

                const built = buildSourceRowHtml(supplierId, supplierName);
                $('#new-profile-sources-list').append(built.$checklistRow);
                $('#new-profile-pk-editors-list').append(built.$pkEditorRow);
                built.$checklistRow.find('.np-source-check').trigger('change');

                // Reset the drop zone so another file can be added if needed.
                $('#wizard-drop-zone-idle').removeClass('mmi-is-hidden');
                $('#wizard-drop-zone-result, #wizard-drop-zone-error, #wizard-upload-name-row').addClass('mmi-is-hidden');
                $('#wizard-upload-file-input, #wizard-upload-attachment-id, #wizard-upload-detected-format, #wizard-source-name').val('');
            },
            error: function (xhr, status, error) {
                $btn.prop('disabled', false).text('Add as New Source');
                alert('Failed to create the source: ' + error);
            },
        });
    });

    // "Show Advanced Fields" toggle on the Field Mapping step — purely a CSS
    // class toggle. The rows themselves (and which ones are "advanced") are
    // decided server-side in panel-field-mapping.php via .mmi-field-advanced,
    // not duplicated here.
    $(document).on('change', '#wizard-show-advanced-fields', function () {
        $('#mmi-wizard-p4').toggleClass('mmi-show-advanced-fields', this.checked);
    });

    // Abandoning a brand-new (never-created) profile can leave a placeholder
    // row behind: MMI_DB::set_field_mappings() upserts one into
    // wp_mmi_vip_profiles the moment the wizard's Field Mapping/Attributes
    // autosave first fires during creation, so those edits have somewhere to
    // land before "Create Profile" runs. Closing/cancelling without ever
    // reaching that button left that row permanently orphaned — a real
    // profile in the selector with only field_mappings set. Reuses the
    // existing mmi_delete_import_profile endpoint — it doesn't distinguish a
    // placeholder from a finalized profile, but this function only ever runs
    // when _wizardEditProfileId is null (create mode), and
    // wizardSaveProfile()'s success handler closes the modal via a
    // completely separate code path that never calls this function — so a
    // just-finalized profile can never be targeted here. Delayed slightly so
    // any autosave still in flight from
    // flushAllPendingWizardAutosaves() has landed before the delete runs —
    // otherwise a delete that finds nothing yet could be followed moments
    // later by the flushed write creating the very orphan this is meant to
    // prevent.
    function cleanupAbandonedNewProfile() {
        if (_wizardEditProfileId) { return; }
        const profileId = wizardActiveProfileId();
        if (!profileId) { return; }
        setTimeout(function () {
            $.post((window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl, {
                action: 'mmi_delete_import_profile',
                nonce: mmiImportSettings.nonce,
                profile_id: profileId
            });
        }, 500);
    }

    // Close/collapse handlers. Each flushes every still-debounced wizard-step
    // autosave BEFORE sliding the section closed — see
    // flushAllPendingWizardAutosaves() / pendingFieldPropertySaves above:
    // this UI's whole premise is "every change is saved automatically," so
    // Close/Cancel closing the section must not be able to silently drop (or
    // misattribute to whatever profile the page dropdown happens to have
    // selected) whatever was typed/toggled in the last 800ms.
    //
    // No backdrop-click or Escape-to-close here — this is an inline section
    // now, not a modal overlay, so there's no backdrop to click past and no
    // reason to intercept Escape (see IMPORT_WIZARD_INLINE_SECTION_HANDOFF.md,
    // build order step 3). #new-profile-modal-close (the header's ×) IS this
    // section's own collapse-toggle now — same flush/cleanup path as Cancel.
    $(document).on('click', '#new-profile-modal-close, #new-profile-modal-cancel', function () {
        flushAllPendingWizardAutosaves();
        cleanupAbandonedNewProfile();
        $('#new-profile-modal').slideUp(200);
    });
    // Create profile (final step) and Save & Close (any step, edit mode
    // only — see the button's toggle logic in wizardShowPanel()) both save
    // the exact same set of fields, since every step's inputs already live
    // in the DOM regardless of which panel is currently visible; only the
    // triggering button differs, so both handlers share this one function.
    // wizardSaveProfile()'s own AJAX payload only ever covers Steps 1/4 (name,
    // sources, scope, mode) — Field Mapping/Attributes edits are NOT part of
    // it and rely entirely on their own autosave having already been sent.
    // Flushing here, before wizardSaveProfile() does anything, guarantees the
    // most recent Field Mapping/Attributes edit is dispatched while
    // _wizardEditProfileId still correctly identifies this profile — see
    // flushAllPendingWizardAutosaves()'s comment for what breaks without
    // this.
    $(document).on('click', '#new-profile-modal-create, #mmi-wizard-save-exit', function () {
        flushAllPendingWizardAutosaves();
        wizardSaveProfile($(this));
    });

    /**
     * tab-pipeline.php's Import Profiles table (column headers, the
     * Edit/Duplicate/Delete/New Profile action bar vs. the single "Create
     * Your First Import Profile" button, the empty-state row) and the
     * Review & Compare step's #import-preview-main container are both
     * branched server-side on `empty($profiles)` at page-load time — there
     * is no client-side template to re-render that branch from. Reloading
     * with the target profile already selected re-renders everything
     * correctly from PHP in one step, which is simpler and more robust than
     * hand-patching each of those pieces in JS to match what the PHP would
     * have produced. Used only for the empty→non-empty and non-empty→empty
     * transitions (first profile created, last profile deleted) — adding to
     * or removing from an already-non-empty grid is handled in place by the
     * #mmi-import-profile MutationObserver (import-pipeline.js).
     */
    function reloadWithProfile(profileId) {
        const url = new URL(window.location);
        if (profileId && profileId !== 'default') {
            url.searchParams.set('profile', profileId);
        } else {
            url.searchParams.delete('profile');
        }
        window.location.href = url.toString();
    }

    function wizardSaveProfile($btn, skipFieldValidation) {
        const name = $('#new-profile-name').val().trim();
        const hasSource = $('#new-profile-sources-list .np-source-check:checked').length > 0;
        if (!name || !hasSource) {
            wizardShowPanel('mmi-wizard-p2');
            wizardValidatePanel('mmi-wizard-p2');
            return;
        }

        // Field Mapping issues must be resolved before this profile is
        // persisted — re-checked here (not just on the Fields step's own Next
        // button) since Save & Close can trigger a save from any step.
        // Skipped on the recursive re-entry below once a clean result for the
        // current selections has already been confirmed.
        if (!skipFieldValidation) {
            $btn.prop('disabled', true);
            wizardValidateFieldMappings(function (fieldIssues) {
                if (fieldIssues.length > 0) {
                    $btn.prop('disabled', false);
                    wizardShowPanel('mmi-wizard-p4');
                    return;
                }
                wizardSaveProfile($btn, true);
            });
            return;
        }

        const productScope  = $('input[name="new_profile_scope"]:checked').val() || 'all_products';
        // new_only scope has a fixed mode: always create missing products, never touch existing ones.
        const importMode    = productScope === 'new_only'
            ? 'create-and-update'
            : ($('input[name="new_profile_mode"]:checked').val() || 'update-only');
        const missingAction = $('input[name="new_profile_missing_action"]:checked').val() || 'none';

        const modeSettings = { availability_action: missingAction };

        // Build identifier payload (only when scope = by_identifier)
        let productIdentifier = null;
        if (productScope === 'by_identifier') {
            const idType        = $('input[name="new_profile_id_type"]:checked').val() || 'taxonomy';
            const autoApply     = $('#new-profile-id-auto-apply').is(':checked');
            const pluginsChecked = [];
            $('input[name="mmi_id_plugin[]"]:checked').each(function () { pluginsChecked.push($(this).val()); });

            const storageConfig = {};
            if (idType === 'taxonomy') {
                storageConfig.taxonomy_slug    = $('#new-profile-tax-slug').val() || '';
                // multi-select returns an array; store as comma-separated for back-compat
                const selectedTerms = $('#new-profile-tax-term').val();
                storageConfig.term_slug_or_id  = Array.isArray(selectedTerms)
                    ? selectedTerms.join(',')
                    : (selectedTerms || '');
            } else {
                storageConfig.meta_key   = $('#new-profile-meta-key').val().trim();
                storageConfig.meta_value = $('#new-profile-meta-value').val().trim();
            }

            productIdentifier = {
                storage_type:      idType,
                storage_config:    storageConfig,
                auto_apply_to_new: autoApply,
                onboarded_plugins: pluginsChecked,
            };
        }

        const isEditing = !!_wizardEditProfileId;

        // Editing keeps the existing profile_id (renaming shouldn't change it);
        // creating slugifies a fresh one from the name, as before — the same
        // slugifyProfileName() that wizardActiveProfileId() already used live
        // during Steps 3/4, so autosave and this final save agree on the id.
        const profileId = isEditing ? _wizardEditProfileId : slugifyProfileName(name);

        if (!profileId) {
            alert('Invalid profile name. Please use letters and numbers.');
            return;
        }

        const selectedSources = [];
        $('#new-profile-sources-list .np-source-check:checked').each(function () {
            selectedSources.push($(this).val());
        });

        const idleLabel = isEditing ? '\u{1F4BE} Save Changes' : '➕ Create Profile';
        $btn.prop('disabled', true).text(isEditing ? 'Saving…' : 'Creating…');

        // Data Type is create-only (see wizardSteps() above) — mmi_update_import_profile
        // doesn't read a data_type field at all, so the profile's existing
        // value is left untouched on every edit regardless of what's sent.
        const dataType = $('input[name="new_profile_data_type"]:checked').val() || 'product';

        // Same payload shape for both -- mmi_update_import_profile accepts the
        // identical fields mmi_save_import_profile does, just applied to an
        // existing profile_id instead of inserting a new one.
        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action:             isEditing ? 'mmi_update_import_profile' : 'mmi_save_import_profile',
                nonce:              mmiImportSettings.nonce,
                profile_id:         profileId,
                name:               name,
                description:        '',
                import_mode:        importMode,
                mode_settings:      JSON.stringify(modeSettings),
                product_scope:      productScope,
                product_identifier: productIdentifier ? JSON.stringify(productIdentifier) : '',
                sources:            JSON.stringify(selectedSources),
                data_type:          dataType,
            },
            success: function (response) {
                $btn.prop('disabled', false).text(idleLabel);
                if (!response.success) {
                    alert('Error saving profile: ' + (response.data?.message || 'Unknown error'));
                    return;
                }

                $('#new-profile-modal').slideUp(200);
                _wizardEditProfileId = null;

                // First profile ever created — see reloadWithProfile()'s own
                // comment for why this reloads instead of patching the DOM.
                if (!isEditing && $('#mmi-profile-cards-grid .mmi-pgc-empty-state').length) {
                    reloadWithProfile(profileId);
                    return;
                }

                if (!isEditing) {
                    // Reveals #import-preview-main (Review & Compare's preview
                    // container, hidden server-side via `empty($profiles)` —
                    // see pipeline-step-2-review.php). The guard this used to
                    // have (`if ($('#mmi-no-profiles-state')...)`) checked an
                    // ID that belonged to a retired "Import Settings" tab and
                    // no longer exists anywhere on the surviving page, so it
                    // was always false and this never ran — leaving the
                    // preview container hidden even after a profile existed.
                    // Safe to call unconditionally: a no-op once the
                    // container is already visible.
                    hideNoProfilesState();
                    $('#mmi-import-profile').append(`<option value="${escHtml(profileId)}">${escHtml(name)}</option>`);
                }
                $('#mmi-import-profile').val(profileId);
                $(`#mmi-import-profile option[value="${profileId}"]`).text(name);
                if (isEditing) {
                    currentProfileMeta = Object.assign({}, currentProfileMeta, response.data.meta, { profile_id: profileId });
                    $('#mmi-topbar-active-name').text(name);
                } else {
                    currentProfile = profileId;
                }
                loadProfile(profileId, true);

                // The #mmi-import-profile MutationObserver (import-pipeline.js)
                // reacts to a newly-appended <option> and inserts a placeholder
                // grid row -- but it only ever sees that option's id/text, not
                // this profile's actual mode/sources, so it always renders an
                // empty mode cell and "No sources assigned" regardless of what
                // was actually just chosen in this wizard. setTimeout(...,0)
                // defers this correction until after that MutationObserver
                // callback (a microtask) has run, so the row already exists by
                // the time we go looking for it. When editing, the row already
                // existed before this handler ran, so the same correction just
                // applies immediately without needing to wait on the observer --
                // but deferring it either way is harmless and keeps one code path.
                setTimeout(function () {
                    const $gridRow = $('#mmi-profile-cards-grid .mmi-profile-grid-card[data-profile-id="' + profileId + '"]');
                    if (!$gridRow.length) { return; }

                    $gridRow.attr('data-import-mode', importMode)
                        .attr('data-profile-name', name)
                        .attr('data-sources', JSON.stringify(selectedSources))
                        // Also update jQuery's own .data() cache directly — if
                        // .data('sources') was already read once on this element
                        // (e.g. by getEnabledSuppliers()), jQuery caches that
                        // value and won't notice the attr() change above on its own.
                        .data('sources', selectedSources);
                    $gridRow.find('.mmi-pgc-name').text(name);

                    // Same modeLabels/color pairs the old Edit Profile save handler
                    // used, kept here since the icon is baked into the label string
                    // (unlike tab-pipeline.php's PHP render, which combines separate
                    // icon/label fields into the same markup shape).
                    const modeLabels = {
                        'create-and-update': { label: '➕🔄 Create & Update', color: '45, 122, 45' },
                        'create-only':       { label: '➕ Create Only',       color: '5, 150, 105' },
                        'update-only':       { label: '🔄 Update Only',       color: '71, 85, 105' },
                        'availability-sync': { label: '📍 Availability Sync', color: '180, 83, 9' },
                    };
                    const modeInfo = modeLabels[importMode] || modeLabels['update-only'];
                    const $modeBadge = $('<span class="mmi-pgc-mode-badge">').text(modeInfo.label);
                    if ($modeBadge[0]) { $modeBadge[0].style.setProperty('--badge-raw-color', modeInfo.color); }
                    $gridRow.find('.mmi-pgc-col-mode').empty().append($modeBadge);

                    const supplierLookup = {};
                    ((window.mmiImportSettings && window.mmiImportSettings.configuredSuppliers) || []).forEach(function (s) {
                        supplierLookup[s.supplier_id] = s.supplier_name;
                    });
                    const $sourcesCell = $gridRow.find('.mmi-pgc-col-sources').empty();
                    if (selectedSources.length) {
                        selectedSources.forEach(function (srcId) {
                            if (supplierLookup[srcId]) {
                                $sourcesCell.append($('<span class="mmi-pgc-source-pill">').text(supplierLookup[srcId]));
                            }
                        });
                    } else {
                        $sourcesCell.append($('<span class="mmi-pgc-no-sources">').text('No sources assigned'));
                    }
                }, 0);
            },
            error: function (xhr, status, error) {
                $btn.prop('disabled', false).text(idleLabel);
                alert('Failed to save profile: ' + error);
            }
        });
    }

    /* ── END PROFILE CREATION WIZARD ─────────────────────────────────────── */
    

    // Duplicate profile
    $(document).on('click', '#duplicate-profile-btn', function() {
        const currentProfileId = $('#mmi-import-profile').val();
        const currentProfileName = $('#mmi-import-profile option:selected').text();
        const name = prompt('Enter new profile name:', currentProfileName + ' (Copy)');
        if (!name || name.trim() === '') return;
        
        // Generate a clean profile ID
        const profileId = name.toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .substring(0, 50);
        
        if (!profileId) {
            alert('Invalid profile name. Please use letters and numbers.');
            return;
        }
        
        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_save_import_profile',
                nonce: mmiImportSettings.nonce,
                profile_id: profileId,
                name: name.trim(),
                description: '',
                copy_from: currentProfileId
            },
            success: function(response) {
                if (response.success) {
                    // Add duplicated profile to dropdown
                    $('#mmi-import-profile').append(`<option value="${escHtml(profileId)}">${escHtml(name.trim())}</option>`);
                    
                    // Switch to the duplicated profile using AJAX
                    $('#mmi-import-profile').val(profileId);
                    currentProfile = profileId;
                    loadProfile(profileId, true);
                } else {
                    alert('Error duplicating profile: ' + (response.data?.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Failed to duplicate profile: ' + error);
            }
        });
    });
    
    // Open the unified wizard in edit mode for the currently active profile
    // (replaces the old separate Edit Profile modal).
    $(document).on('click', '#edit-profile-btn', function () {
        openProfileWizard(currentProfile);
    });

    // Delete profile
    $(document).on('click', '#delete-profile-btn', function() {
        const profileId = $('#mmi-import-profile').val();
        const $select = $('#mmi-import-profile');

        if (!confirm('Are you sure you want to delete "' + $select.find('option:selected').text().trim() + '"? This cannot be undone.')) {
            return;
        }

        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_delete_import_profile',
                nonce: mmiImportSettings.nonce,
                profile_id: profileId
            },
            success: function(response) {
                if (response.success) {
                    // Remove the deleted option from the dropdown
                    $select.find('option[value="' + profileId + '"]').remove();

                    if (response.data.profiles_empty || $select.find('option').length === 0) {
                        // No profiles remain — see reloadWithProfile()'s own
                        // comment for why this reloads rather than trying to
                        // restore tab-pipeline.php's server-rendered
                        // empty-state markup (action bar, empty-state row)
                        // via showNoProfilesState(), which only ever
                        // targeted #mmi-no-profiles-state — an ID that
                        // belonged to a retired "Import Settings" tab and no
                        // longer exists anywhere on the surviving page.
                        reloadWithProfile('');
                    } else {
                        // Switch to the first remaining profile
                        const nextProfileId = $select.find('option:first').val();
                        $select.val(nextProfileId).trigger('change');
                    }
                } else {
                    alert('Error deleting profile: ' + response.data.message);
                }
            }
        });
    });

    /**
     * Reveal Review & Compare's #import-preview-main container — hidden
     * server-side via `empty($profiles)` (see pipeline-step-2-review.php) —
     * once the page's first profile has just been created. The dead
     * `showNoProfilesState()` counterpart (targeting #mmi-no-profiles-state,
     * an ID belonging to a retired "Import Settings" tab) was removed here;
     * reloadWithProfile() handles that direction instead (see its comment).
     */
    function hideNoProfilesState() {
        $('#import-preview-main').removeClass('mmi-is-hidden');
    }

    // Profile-specific toggle switches (e.g. Allow Creating New Products)
    // Click-driven via data-enabled attribute — no hidden checkbox dependency.
    $(document).on('click', '.mmi-filter-toggle-label', function() {
        const $label      = $(this);
        const $slider     = $label.find('.mmi-filter-toggle-slider');
        const settingName = $label.data('profile-setting');
        if (!settingName) return;
        const newEnabled  = $slider.attr('data-enabled') !== 'true';
        $slider.attr('data-enabled', newEnabled ? 'true' : 'false');

        $.ajax({
            url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
            type: 'POST',
            data: {
                action: 'mmi_autosave_profile_setting',
                nonce: mmiImportSettings.nonce,
                setting_name: settingName,
                enabled: newEnabled ? 1 : 0,
                profile: currentProfile
            },
            success: function(response) {
                if (response.success) {
                    showAutosaveIndicator('✓ Saved', 'success');
                    if (settingName === 'allow_create_products') {
                        $('input[name="allow_create_products"]').prop('checked', newEnabled);
                    }
                    if (typeof refreshImportStats === 'function') {
                        refreshImportStats();
                    }
                } else {
                    $slider.attr('data-enabled', newEnabled ? 'false' : 'true'); // revert on failure
                    showAutosaveIndicator('✗ Failed: ' + (response.data.message || 'Unknown error'), 'error');
                    console.error('Save failed:', response);
                }
            },
            error: function(xhr, status, error) {
                $slider.attr('data-enabled', newEnabled ? 'false' : 'true'); // revert on error
                showAutosaveIndicator('✗ Error saving setting', 'error');
                console.error('AJAX error:', status, error);
            }
        });
    });
    
    /**
     * Update UI elements with new profile data  
     */
    function updateUIWithProfileData(profileData) {
        // Update field mappings table
        if (profileData.field_mappings) {
            updateFieldMappingsTable(profileData.field_mappings);
        }
        updateFieldMappingSingleSourceState();

        // Update the wizard's Attributes step (mirrors the Field Mapping table
        // refresh above — see import-pipeline-attributes.js for why this is
        // needed: that step's fields are otherwise frozen to whichever profile
        // was active when the page first rendered).
        if (window.MMIAttributeMapping && typeof window.MMIAttributeMapping.loadConfigIntoUI === 'function') {
            window.MMIAttributeMapping.loadConfigIntoUI(profileData.attribute_config || {});
        }

        // Update profile-specific settings
        if (profileData.settings) {
            updateProfileSettings(profileData.settings);
        }

        // Cache full profile meta — read by openProfileWizard() to pre-fill
        // the wizard when editing this profile
        if (profileData.profile_meta) {
            currentProfileMeta = profileData.profile_meta;
            // Update section bar active name + the Review & Compare toolbar's
            // own "Previewing: <name>" label (pipeline-step-2-review.php)
            if (profileData.profile_meta.name) {
                $('#mmi-topbar-active-name').text(profileData.profile_meta.name);
                $('#mmi-toolbar-profile-name').text(profileData.profile_meta.name);
            }
        }

        // Refresh the Review & Compare preview (table + #preview-stats-summary) for the
        // newly-selected profile. MMIImportPreview.refreshStats() reads
        // $('#mmi-import-profile').val() itself, does a full per-supplier scan
        // (limit:999999), and re-renders both the summary stats and the table —
        // this is the single source of truth for #preview-stats-summary.
        //
        // Previously this called updateImportStats(profileData.stats), a separate
        // "quick estimate" (count($data) on the raw JSON, ~20%/80% create/update
        // split) that wrote a different layout into the same #preview-stats-summary
        // element and was never followed by a refresh of the table below it —
        // leaving the table showing the previous profile's rows/mode indefinitely.
        if (window.MMIImportPreview && typeof window.MMIImportPreview.refreshStats === 'function') {
            window.MMIImportPreview.refreshStats();
        }
    }
    
    /**
     * Update field mappings table with new profile data
     */
    function updateFieldMappingsTable(mappings) {
        // Temporarily disable ALL form change tracking to prevent dirty form warnings
        const $form = $('form[method=\"post\"]');
        let formChangeHandlers = [];
        let originalFormChangeHandler = null;
        
        // Store and remove WordPress form change handler
        if ($form.length) {
            const formElement = $form[0];
            originalFormChangeHandler = formElement.onchange;
            formElement.onchange = null;
            
            // Also remove any jQuery event handlers
            $form.off('change.wp-check-for-changes');
        }
        
        $('.field-mapping-row').each(function() {
            const $row = $(this);
            const fieldName = $row.data('field');
            const mapping = mappings[fieldName];
            
            // Disable change events temporarily
            $row.find('input, select').each(function() {
                const $element = $(this);
                const handlers = $._data(this, 'events');
                if (handlers && handlers.change) {
                    formChangeHandlers.push({element: this, handlers: handlers.change.slice()});
                    $element.off('change');
                }
            });
            
            if (mapping) {
                // Restore the file selector(s) FIRST — the field-selector-group's
                // source input's enabled/disabled state and available-fields
                // datalist both derive from which file is selected (see
                // initFieldFileSelectors()), so this must happen before that
                // re-init call below, and before the source value is set.
                //
                // Every [file] control in the row is set unconditionally (never
                // gated behind `if (mapping.file)`) and cleared when this
                // profile has no value for it — otherwise a supplier/file combo
                // the previous profile had selected stays selected here too.
                $row.find('[name*="[file]"]').each(function () {
                    const $input = $(this);
                    const supplierMatch = ($input.attr('name') || '').match(/\[file\]\[([^\]]+)\]/);
                    if (supplierMatch) {
                        const supplier = supplierMatch[1];
                        let val = (mapping.file && typeof mapping.file === 'object') ? (mapping.file[supplier] || '') : '';
                        // A blank value is legitimate ("this field's file was
                        // never explicitly chosen") but the <select> renders
                        // no blank <option> (see panel-field-mapping.php's
                        // "first available file" comment) — .val('') then
                        // deselects everything and the dropdown looks blank/
                        // broken to the admin. Fall back to the same
                        // resolution PHP and resolveSupplierFilename() already
                        // use so the control keeps showing a real selection.
                        if (!val) {
                            val = resolveSupplierFilename(supplier);
                        }
                        $input.val(val);
                    } else {
                        $input.val(typeof mapping.file === 'string' ? mapping.file : '');
                    }
                });

                // Update source fields. Only the LEGACY single-supplier control
                // (panel-field-mapping.php's <input class="field-source">, name
                // attribute present) is a plain text input — the real
                // multi-supplier "Source Field (Per Supplier)" control
                // (<select class="field-source-{supplier} mmi-source-path-input">)
                // is handled separately below, since it deliberately carries no
                // [name] attribute at all (a value there can only come from
                // picking a real detected field — see that select's own
                // comment in panel-field-mapping.php).
                //
                // Every [source] control in the row is set unconditionally and
                // cleared when this profile has no value for it — previously
                // this only ran `if (mapping.source)`, so a field this profile
                // never customized (mapping.source empty/absent) kept showing
                // whichever profile was displayed before it, making edits look
                // like they leaked across profiles.
                $row.find('[name*="[source]"]').each(function () {
                    const $input = $(this);
                    const supplierMatch = ($input.attr('name') || '').match(/\[source\]\[([^\]]+)\]/);
                    if (supplierMatch) {
                        const supplier = supplierMatch[1];
                        const val = (mapping.source && typeof mapping.source === 'object') ? (mapping.source[supplier] || '') : '';
                        $input.val(val);
                    } else {
                        $input.val(typeof mapping.source === 'string' ? mapping.source : '');
                    }
                });

                // The multi-supplier .mmi-source-path-input <select> has no
                // [name] to match above, so without this it kept whatever
                // value the page's initial PHP render happened to seed it
                // with (the profile active on the underlying page at load
                // time) — indefinitely, since populateSourceFieldSelect()
                // only ever reads/preserves whatever is already selected, it
                // never resets it. This is what made a brand-new profile look
                // pre-populated with another profile's (or the plugin's
                // built-in) field mappings. Re-seeded with a single option
                // carrying the new mapping's raw value (mirroring
                // panel-field-mapping.php's own initial-render shape) so
                // initFieldFileSelectors()'s loadFieldsFromFile() chain right
                // below can reconcile it against this profile's real
                // per-supplier sample list via populateSourceFieldSelect()'s
                // own matchExists check.
                $row.find('.mmi-source-path-input').each(function () {
                    const $select  = $(this);
                    const supplier = $select.data('supplier');
                    const val = (mapping.source && typeof mapping.source === 'object') ? (mapping.source[supplier] || '') : '';
                    $select.empty();
                    $select.append(
                        val
                            ? $('<option></option>').val(val).text(val)
                            : $('<option></option>').val('').text('— Select a field —')
                    );
                    $select.val(val);
                });

                // Re-derive the source input's disabled state, placeholder and
                // available-fields datalist from the just-restored file
                // selection — without this, the input stayed disabled (if it
                // loaded as disabled before any profile was ever selected) and
                // its datalist stayed empty forever, since setting .val()
                // directly above fires no 'change' event.
                initFieldFileSelectors($row);

                // Update other properties — unconditionally, same reasoning as
                // source/file above: a falsy/absent value here must clear the
                // control, not leave the previous profile's value in place.
                $row.find('select[name*="[transform]"]').val(mapping.transform || 'none');
                $row.find('input[name*="[default_value]"]').val(mapping.default_value || '');
                // use_constant_value/constant_value are per-supplier now (see
                // panel-field-mapping.php) — same object-or-scalar shape and
                // name-attribute matching pattern as [source]/[file] above. A
                // legacy scalar value (saved before per-source constants
                // existed) still applies identically to every supplier row.
                $row.find('input[name*="[use_constant_value]"]').each(function () {
                    const $input = $(this);
                    const supplierMatch = ($input.attr('name') || '').match(/\[use_constant_value\]\[([^\]]+)\]/);
                    const checked = supplierMatch
                        ? !!(mapping.use_constant_value && typeof mapping.use_constant_value === 'object' ? mapping.use_constant_value[supplierMatch[1]] : mapping.use_constant_value)
                        : !!mapping.use_constant_value;
                    $input.prop('checked', checked).trigger('mmi:sync-visual-state');
                });
                $row.find('input[name*="[constant_value]"], select[name*="[constant_value]"]').each(function () {
                    const $input = $(this);
                    const supplierMatch = ($input.attr('name') || '').match(/\[constant_value\]\[([^\]]+)\]/);
                    const val = supplierMatch
                        ? ((mapping.constant_value && typeof mapping.constant_value === 'object') ? (mapping.constant_value[supplierMatch[1]] || '') : (mapping.constant_value || ''))
                        : (mapping.constant_value || '');
                    // A taxonomy <select>'s real term options load asynchronously
                    // (just triggered above, via the use_constant_value restore's
                    // 'mmi:sync-visual-state' → maybePreloadConstantTaxonomyTerms())
                    // and won't have arrived yet — seed a temporary option so the
                    // saved value isn't silently dropped; loadConstantTaxonomyTerms()
                    // reconciles it against the real list once that request
                    // resolves, same as populateSourceFieldSelect()'s matchExists.
                    if ($input.is('select.mmi-constant-taxonomy-select') && val && !$input.find('option[value="' + val + '"]').length) {
                        $input.append($('<option></option>').val(val).text(val));
                    }
                    $input.val(val);
                });
            } else {
                // Reset row if no mapping
                $row.find('input[type="checkbox"]').prop('checked', false);
                $row.find('select').prop('selectedIndex', 0);
                $row.find('input[type="text"]').val('');
            }
            
            // Re-attach change handlers after a brief delay
            setTimeout(() => {
                formChangeHandlers.forEach(item => {
                    const $element = $(item.element);
                    item.handlers.forEach(handler => {
                        $element.on('change', handler.handler);
                    });
                });
                
                // Restore WordPress form change handler
                if (originalFormChangeHandler && $form.length) {
                    $form[0].onchange = originalFormChangeHandler;
                }
                
                // Re-enable WordPress change detection
                $form.on('change.wp-check-for-changes', function() {
                    // Only mark as dirty if not currently profile switching
                    if (!isProfileSwitching) {
                        const formElement = this;
                        if (formElement.dataset) {
                            formElement.dataset.wpFormDirty = 'true';
                        }
                    }
                });
            }, 150);
        });

        // The .prop('checked'/.val', ...) calls above (all set programmatically
        // here, restoring the newly-active profile's real saved values) don't
        // fire 'change', so updateMappedIndicators()'s own delegated handler
        // never sees this profile switch — recompute once for the whole table
        // now, or a row's "Not mapped" pill/group chip that was correct for
        // the previous profile stays stale until the next manual edit.
        updateMappedIndicators();
    }

    /**
     * Update profile-specific settings
     */
    function updateProfileSettings(settings) {
        // Update allow_create_products toggle via data-enabled attribute (legacy toolbar toggle still in some themes)
        if (typeof settings.allow_create_products !== 'undefined') {
            const enabled = !!settings.allow_create_products;
            $('.mmi-filter-toggle-slider').attr('data-enabled', enabled ? 'true' : 'false');
            $('input[name="allow_create_products"]').prop('checked', enabled);
        }

        // Update mode badge in profile selector bar and toolbar
        if (settings.import_mode) {
            // color is an "R, G, B" triplet, matching tab-pipeline.php's own
            // $mode_labels \u2014 .mmi-pgc-mode-badge (import-pipeline.css) reads
            // it via rgba(var(--badge-raw-color, ...), .12); a hex string
            // here silently never applied.
            const modeLabels = {
                'create-and-update': { label: '\u2795\ud83d\udd04 Create & Update', color: '45, 122, 45' },
                'create-only':       { label: '\u2795 Create Only',       color: '5, 150, 105' },
                'update-only':       { label: '\ud83d\udd04 Update Only',       color: '71, 85, 105' },
                'availability-sync': { label: '\ud83d\udccd Availability Sync', color: '180, 83, 9' },
            };
            // Scope beats mode for the badge, same as it does for the real import \u2014
            // new_only always forces import_mode to 'create-and-update' on save (see
            // wizardSaveProfile()), which would otherwise render identically to an
            // ordinary all-products Create & Update profile despite behaving very
            // differently (existing products are never touched under new_only).
            const isNewOnlyScope = settings.product_scope === 'new_only';
            const info = isNewOnlyScope
                ? { label: '\u2728 Create Only \u2014 new products', color: '5, 150, 105' }
                : (modeLabels[settings.import_mode] || modeLabels['update-only']);
            const $toolbarBadge = $('#mmi-toolbar-mode-badge');
            $('.mmi-profile-grid-card.is-active').attr('data-import-mode', settings.import_mode);
            $toolbarBadge.text(info.label);
            if ($toolbarBadge[0]) { $toolbarBadge[0].style.setProperty('--mmi-mode-color', info.color); }

            // Update the toolbar mode hint text
            const isCreateMode = settings.import_mode === 'create-and-update' || settings.import_mode === 'create-only';
            $('#mmi-toolbar-mode-hint').text(
                isNewOnlyScope
                    ? '\u2014 new products created; existing products never modified'
                    : (isCreateMode ? '\u2014 new products will be created' : '\u2014 new products will be skipped')
            );

            // Show or hide the "Create" status filter button based on mode.
            // For update-only and availability-sync, new/create records never appear
            // in the preview table, so the filter button is irrelevant and misleading.
            applyModeFilters(settings.import_mode);
        }
    }

    /**
     * Show or hide preview filter/stat elements that only make sense in
     * create-and-update or create-only mode.
     *
     * @param {string} mode  One of 'create-and-update', 'create-only', 'update-only', 'availability-sync'
     */
    function applyModeFilters(mode) {
        const isCreateMode = mode === 'create-and-update' || mode === 'create-only';

        // "Create" status filter button
        const $createBtn = $('#mmi-filter-status-create');
        if (isCreateMode) {
            $createBtn.removeClass('mmi-is-hidden');
        } else {
            // If it was active, reset to "All" to avoid a stuck filter
            if ($createBtn.hasClass('active')) {
                $createBtn.removeClass('active');
                $('.mmi-filter-status-btn[data-status="all"]').addClass('active');
                // Re-trigger filtering if a refresh function is available
                if (typeof applyFilters === 'function') { applyFilters(); }
            }
            $createBtn.addClass('mmi-is-hidden');
        }

        // "Will Create" stat in preview-stats-summary — hide the column for non-create modes.
        // The summary itself is rebuilt by MMIImportPreview.renderSummary(); this class
        // just flags the container so its CSS can hide the "Will Create" stat item.
        $('#preview-stats-summary').toggleClass('mmi-no-create-mode', !isCreateMode);
    }


    // ===== INIT: apply mode-aware UI state from PHP-rendered data attributes =====
    (function initModeFilters() {
        // The active profile's row in the cards grid carries the initial
        // import mode via PHP-rendered data-import-mode (the Import Profiles
        // section header no longer mirrors this — it's a static title now).
        const initialMode = $('.mmi-profile-grid-card.is-active').data('import-mode') || 'update-only';
        applyModeFilters(initialMode);
    })();

    // ===== COG Meta Key autosave =====
    // Debounced save for the configurable cost-of-goods meta key setting.
    // The input lives in panel-field-mapping.php; it is always present when the
    // field mapping panel is open regardless of which profile is active.
    let cogMetaKeyTimer = null;
    $(document).on('input', '.mmi-cog-meta-key-input', function () {
        const $input  = $(this);
        const $status = $input.siblings('.mmi-cog-meta-key-save-status');
        clearTimeout(cogMetaKeyTimer);
        cogMetaKeyTimer = setTimeout(function () {
            const metaKey = $input.val().trim() || 'cog';
            $status.text('Saving…');
            $.ajax({
                url:  (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                type: 'POST',
                data: {
                    action:   'mmi_save_cog_meta_key',
                    nonce:    mmiImportSettings.nonce,
                    meta_key: metaKey,
                },
                success: function (response) {
                    if (response.success) {
                        $status.text('✓ Saved');
                        showAutosaveIndicator('✓ COG meta key saved', 'success');
                    } else {
                        $status.text('✗ Failed');
                        showAutosaveIndicator('✗ Failed to save COG key', 'error');
                    }
                },
                error: function () {
                    $status.text('✗ Error');
                    showAutosaveIndicator('✗ Error saving COG key', 'error');
                },
            });
        }, 800);
    });

    /* ── Generic Field Mapping panel (non-Product data types, Milestone 2) ──
     * panel-field-mapping-generic.php's simple {enabled, source, transform}
     * table — deliberately its own small autosave block rather than reusing
     * autosaveFieldProperty() above, since that's built around Product's
     * per-supplier sub-array shape (a `supplier`
     * param, a shared debounce keyed by field|property|supplier) that this
     * flat, single-source shape doesn't have. Delegated off `document` like
     * every other wizard handler in this file, since the panel itself is
     * injected via AJAX (wizardMaybeLoadFieldMappingPanel()) and won't exist
     * in the DOM at page-load time.
     */
    (function () {
        const SELECTOR_ROOT       = '#mmi-generic-field-mapping';
        const SELECTOR_ROW        = '.generic-fm-row';
        const SELECTOR_SOURCE     = '.generic-fm-source';
        const SELECTOR_TRANSFORM  = '.generic-fm-transform';
        const SOURCE_DEBOUNCE_MS  = 500;

        let sourceDebounceTimers = {}; // keyed by field name — a per-field Map, not one shared timer, so editing two different fields in quick succession can't drop one (see this file's own "Wizard Save & Exit Race" fix above for why a single shared timer is the wrong shape here).

        function showStatus(text) {
            $(SELECTOR_ROOT + ' #mmi-generic-fm-status').text(text);
        }

        // Enabled is no longer sent from here (2026-09-12) — the server
        // derives it from whether $source is non-empty (or the field is
        // required), same rule as the Product Field Mapping table. This
        // just keeps the "Not mapped" pill and the table-wide summary in
        // sync with whatever's currently typed, without waiting on the
        // debounced save to round-trip.
        function updateGenericMappedIndicators($row) {
            const required = $row.data('required') == 1 || $row.data('required') === true;
            const mapped   = required || !!$row.find(SELECTOR_SOURCE).val();
            $row.find('.mmi-field-not-mapped-pill').toggle(!mapped);

            const $table = $(SELECTOR_ROOT);
            const $rows  = $table.find(SELECTOR_ROW);
            let totalMapped = 0;
            $rows.each(function () {
                const $r = $(this);
                const req = $r.data('required') == 1 || $r.data('required') === true;
                if (req || !!$r.find(SELECTOR_SOURCE).val()) { totalMapped++; }
            });
            $table.find('.mmi-fm-total-mapped').text(totalMapped + ' mapped');
            $table.find('.mmi-fm-total-skipped').text(($rows.length - totalMapped) + ' skipped');
        }

        function saveGenericField($row, overrides) {
            const field = $row.data('field');
            const required = $row.data('required') == 1 || $row.data('required') === true;
            const payload = Object.assign({
                action:  'mmi_pipeline_save_generic_field_mapping',
                nonce:   mmiImportSettings.nonce,
                profile: $(SELECTOR_ROOT).data('profile'),
                field:   field,
                required: required ? 1 : 0,
                source:  $row.find(SELECTOR_SOURCE).val(),
                transform: $row.find(SELECTOR_TRANSFORM).val(),
            }, overrides || {});

            updateGenericMappedIndicators($row);
            showStatus('Saving…');
            $.post((window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl, payload)
                .done(function (resp) {
                    showStatus(resp && resp.success ? '✓ Saved' : '✗ ' + (resp && resp.data && resp.data.message || 'Failed'));
                })
                .fail(function () {
                    showStatus('✗ Error saving');
                });
        }

        $(document).on('change', SELECTOR_ROOT + ' ' + SELECTOR_TRANSFORM, function () {
            saveGenericField($(this).closest(SELECTOR_ROW));
        });

        $(document).on('input', SELECTOR_ROOT + ' ' + SELECTOR_SOURCE, function () {
            const $row  = $(this).closest(SELECTOR_ROW);
            const field = $row.data('field');
            updateGenericMappedIndicators($row);
            clearTimeout(sourceDebounceTimers[field]);
            sourceDebounceTimers[field] = setTimeout(function () {
                saveGenericField($row);
            }, SOURCE_DEBOUNCE_MS);
        });
    })();

    // ─── Expose for cross-file reuse ────────────────────────────────────────
    // This file has no exported namespace object (unlike import-pipeline.js's
    // MMIDataPipeline._xxx convention) — attached directly to window so other
    // wizard code sharing this same field-discovery implementation (rather
    // than a second copy targeting different element IDs) can call it,
    // currently unused pending the Field Mapping step of the unified wizard.
    window.loadFieldsFromFile = loadFieldsFromFile;
});
