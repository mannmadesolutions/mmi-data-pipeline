/**
 * MMI Column Customizer — shared "Columns" button/popover widget
 *
 * One implementation backing every per-table column show/hide + drag-to-
 * reorder control across the MMI Suite (mmi-reverb-integration's product
 * table, mmi-import-pipeline's export preview). Persists order + visibility
 * to localStorage under a caller-supplied key, and optionally a per-scope
 * sub-key (e.g. import-pipeline scopes by data type since its column set
 * changes per type; reverb has one fixed column set and passes no scope).
 *
 * Two calling shapes it must support:
 *   - Static columns known up front, full row markup already in the DOM
 *     (reverb: every <th data-resize-col>/<td data-col> always renders
 *     server-side; this module only toggles/reorders those existing nodes
 *     via the caller's onChange callback).
 *   - Dynamic columns discovered after an AJAX response (import-pipeline:
 *     column set depends on the selected data type). Call setColumns()
 *     whenever the known column list changes; the panel rebuilds and
 *     reconciles any previously-stored order/visibility against it.
 *
 * Depends on jquery-ui-sortable (bundled with WordPress core) for the
 * drag-and-drop reorder handle.
 *
 * @package MannMade\Hub
 */

window.MMIColumnCustomizer = (function ($) {
    'use strict';

    const SELECTORS = {
        DRAG_HANDLE: '.mmi-fcp-drag-handle',
        DRAG_ROW: '.mmi-fcp-drag-row',
        FIELD_LABEL: '.mmi-fcp-field-label',
        COLUMN_TOGGLE: '[data-column-toggle]',
        GROUP_TOGGLE: '[data-group-toggle]',
    };

    function readStorage(storageKey) {
        try {
            return JSON.parse(localStorage.getItem(storageKey)) || {};
        } catch (e) {
            return {};
        }
    }

    function writeStorage(storageKey, data) {
        try {
            localStorage.setItem(storageKey, JSON.stringify(data));
        } catch (e) { /* ignore — localStorage unavailable (e.g. private browsing) */ }
    }

    /**
     * Shared modal backdrop (.mmi-fcp-backdrop, see mmi-suite-common.css) —
     * one element, created once and reused by every MMIColumnCustomizer
     * instance on the page, since only one of these panels is ever open at
     * a time in practice. Kept separate from any one instance's markup so
     * neither plugin's template needs to add it.
     */
    let $backdrop = null;

    function ensureBackdrop() {
        if (!$backdrop || !$backdrop.length || !$.contains(document, $backdrop.get(0))) {
            $backdrop = $('<div class="mmi-fcp-backdrop" hidden></div>').appendTo('body');
        }
        return $backdrop;
    }

    function showBackdrop() {
        ensureBackdrop().removeAttr('hidden');
    }

    function hideBackdrop() {
        if ($backdrop) {
            $backdrop.attr('hidden', '');
        }
    }

    /**
     * @param {object} options
     * @param {string} options.buttonSelector
     * @param {string} options.panelSelector
     * @param {string} options.panelBodySelector
     * @param {string} [options.closeSelector]
     * @param {string} [options.selectAllSelector]
     * @param {string} [options.clearAllSelector]
     * @param {string} options.storageKey        localStorage key for this widget instance
     * @param {string|Function} [options.scopeKey] optional sub-key (or a function
     *        returning one) inside the stored object — lets one storageKey hold
     *        prefs for multiple column sets (e.g. one per data type)
     * @param {Array<{key:string,label:string,group?:string}>} [options.columns] known columns at init time
     * @param {Function} [options.defaultVisible] (key) => bool, when no stored pref exists
     * @param {Function} [options.onChange] (visibleOrderedKeys) => void, fired after a
     *        reorder, or after a visibility toggle IF options.onToggle is not supplied
     * @param {Function} [options.onToggle] (key, checked) => void. When supplied, a
     *        checkbox toggle calls this INSTEAD of the built-in localStorage
     *        visibility bookkeeping — for a caller whose "visible" concept is actually
     *        server-authoritative state (e.g. import-pipeline's export field
     *        enabled/disabled, which must persist to the profile and re-fetch data,
     *        not just hide a column client-side). Reorder/select-all/clear-all are
     *        unaffected — only the per-row checkbox handler defers to this.
     * @param {Function} [options.renderExtra] (key, $row, column) => void, called for
     *        each row after the label so a caller can append extra per-row controls
     *        (e.g. an output-column rename input, a transform <select>).
     * @param {Function} [options.groupLabel] (group) => string. When columns carry a
     *        `group` property and this is supplied, a section-header row is inserted
     *        before the first column of each new group (in current sort order).
     */
    function createInstance(options) {
        const cfg = Object.assign({
            scopeKey: null,
            defaultVisible: function () { return true; },
            onChange: function () {},
            columns: [],
        }, options);

        let columns = cfg.columns.slice();

        function currentScopeKey() {
            return typeof cfg.scopeKey === 'function' ? cfg.scopeKey() : cfg.scopeKey;
        }

        function getPrefs() {
            return readStorage(cfg.storageKey);
        }

        function savePrefs(prefs) {
            writeStorage(cfg.storageKey, prefs);
        }

        function scopeOf(prefs) {
            const scope = currentScopeKey();
            if (!scope) {
                return prefs;
            }
            prefs[scope] = prefs[scope] || {};
            return prefs[scope];
        }

        function getOrderedKeys() {
            const scope = scopeOf(getPrefs());
            const storedOrder = Array.isArray(scope.order) ? scope.order : [];
            const knownKeys = columns.map(function (c) { return c.key; });
            // Keep stored order for columns that still exist, then append any
            // columns the caller now knows about that weren't in the stored
            // order yet — never silently drop a known column.
            const kept = storedOrder.filter(function (k) { return knownKeys.indexOf(k) !== -1; });
            const missing = knownKeys.filter(function (k) { return kept.indexOf(k) === -1; });
            const flat = kept.concat(missing);

            if (typeof cfg.groupLabel !== 'function') {
                return flat;
            }

            // When grouping is on, a newly-discovered column belonging to a
            // group that already has other members must land with those
            // siblings, not get appended after every other group — otherwise
            // that group renders as two non-adjacent header sections (e.g. a
            // newly-added taxonomy field tacked on after Meta/Pricing splits
            // "Taxonomy" in two). Bucket by group, ordered by each group's
            // first appearance in `flat`; order WITHIN a group is preserved
            // from `flat`, so drag-reordering still works inside a group and
            // for moving a whole group's block relative to another's.
            const groupOrder = [];
            const buckets = {};
            flat.forEach(function (key) {
                const g = columnFor(key).group || '';
                if (!buckets[g]) {
                    buckets[g] = [];
                    groupOrder.push(g);
                }
                buckets[g].push(key);
            });
            return groupOrder.reduce(function (acc, g) { return acc.concat(buckets[g]); }, []);
        }

        function isVisible(key) {
            const scope = scopeOf(getPrefs());
            const visible = scope.visible || {};
            return Object.prototype.hasOwnProperty.call(visible, key) ? !!visible[key] : cfg.defaultVisible(key);
        }

        function setVisible(key, value) {
            const prefs = getPrefs();
            const scope = scopeOf(prefs);
            scope.visible = scope.visible || {};
            scope.visible[key] = value;
            savePrefs(prefs);
        }

        function setOrder(orderedKeys) {
            const prefs = getPrefs();
            const scope = scopeOf(prefs);
            scope.order = orderedKeys;
            savePrefs(prefs);
        }

        function getVisibleOrderedKeys() {
            return getOrderedKeys().filter(isVisible);
        }

        function columnFor(key) {
            return columns.filter(function (c) { return c.key === key; })[0] || { key: key, label: key };
        }

        function labelFor(key) {
            return columnFor(key).label || key;
        }

        function buildPanel() {
            const $body = $(cfg.panelBodySelector);
            $body.empty();

            let lastGroup;
            getOrderedKeys().forEach(function (key) {
                const col = columnFor(key);

                if (typeof cfg.groupLabel === 'function' && col.group !== lastGroup) {
                    lastGroup = col.group;
                    const $groupRow = $('<div>').addClass('mmi-fcp-group-row').appendTo($body);
                    const $groupToggle = $('<label>')
                        .addClass('mmi-toggle-switch mmi-fcp-group-toggle')
                        .attr('title', 'Show/hide every field in this group');
                    $('<input>').attr('type', 'checkbox').attr('data-group-toggle', col.group || '').appendTo($groupToggle);
                    $('<span>').addClass('mmi-toggle-slider').appendTo($groupToggle);
                    $groupRow.append($groupToggle);
                    $('<span>').addClass('mmi-fcp-group-label').text(cfg.groupLabel(col.group)).appendTo($groupRow);
                }

                const $row = $('<div>').addClass('mmi-fcp-drag-row').attr('data-col-key', key).attr('data-col-group', col.group || '');
                $('<span>')
                    .addClass('mmi-fcp-drag-handle dashicons dashicons-menu')
                    .attr('title', 'Drag to reorder')
                    .appendTo($row);

                const $toggle = $('<label>').addClass('mmi-toggle-switch mmi-fcp-row-toggle').attr('title', 'Show/include this column');
                $('<input>')
                    .attr('type', 'checkbox')
                    .attr('data-column-toggle', key)
                    .prop('checked', isVisible(key))
                    .appendTo($toggle);
                $('<span>').addClass('mmi-toggle-slider').appendTo($toggle);
                $row.append($toggle);

                $('<span>').addClass('mmi-fcp-field-label').attr('title', labelFor(key)).text(labelFor(key)).appendTo($row);

                if (typeof cfg.renderExtra === 'function') {
                    cfg.renderExtra(key, $row, col);
                }

                $body.append($row);
            });

            if ($body.hasClass('ui-sortable')) {
                $body.sortable('destroy');
            }
            $body.sortable({
                axis: 'y',
                handle: SELECTORS.DRAG_HANDLE,
                items: '> ' + SELECTORS.DRAG_ROW,
                update: function () {
                    const orderedKeys = $body.find(SELECTORS.DRAG_ROW).map(function () {
                        return $(this).attr('data-col-key');
                    }).get();
                    setOrder(orderedKeys);
                    cfg.onChange(getVisibleOrderedKeys());
                },
            });

            refreshGroupToggles();
        }

        function refreshChecks() {
            $(cfg.panelBodySelector).find('[data-column-toggle]').each(function () {
                $(this).prop('checked', isVisible($(this).attr('data-column-toggle')));
            });
            refreshGroupToggles();
        }

        /**
         * Syncs each group's master toggle to the checked state of the rows
         * actually rendered under it: checked when every row in the group is
         * visible, unchecked when none are, indeterminate when some are —
         * read directly from the row checkboxes' own current .checked state
         * (not isVisible()/localStorage) so this stays correct for an
         * onToggle-based (server-authoritative) caller too, where a row's
         * displayed checked state and its saved localStorage "visible" value
         * can legitimately disagree.
         */
        function refreshGroupToggles() {
            $(cfg.panelBodySelector).find(SELECTORS.GROUP_TOGGLE).each(function () {
                const group = $(this).attr('data-group-toggle');
                const $rows = $(cfg.panelBodySelector)
                    .find(SELECTORS.DRAG_ROW + '[data-col-group="' + group + '"]')
                    .find('[data-column-toggle]');
                const total = $rows.length;
                const checkedCount = $rows.filter(':checked').length;
                this.checked = total > 0 && checkedCount === total;
                this.indeterminate = checkedCount > 0 && checkedCount < total;
            });
        }

        function openPanel() {
            showBackdrop();
            // Reparent to <body> before showing, not just visually center via
            // CSS: the panel's original position in the DOM is deep inside
            // each caller's own markup (e.g. mmi-data-pipeline's export
            // preview nests it inside .mmi-scope-layout-preview, a
            // position:sticky column — sticky, like fixed, always creates a
            // new stacking context, which traps the panel's z-index inside
            // it regardless of how high a number it's given). The panel is
            // position:fixed either way, so moving it costs nothing visually
            // — but it does put the panel in the SAME stacking context as
            // the body-appended backdrop, which is the only way a numeric
            // z-index comparison between the two is actually meaningful.
            // Every internal interaction is bound via delegated $(document)
            // handlers (see bindPanelInteractions), so relocating the node
            // breaks nothing.
            $(cfg.panelSelector).appendTo('body').removeAttr('hidden');
        }

        function closePanel() {
            $(cfg.panelSelector).attr('hidden', '');
            hideBackdrop();
        }

        function bindPanelInteractions() {
            $(document).on('click', cfg.buttonSelector, function (e) {
                e.stopPropagation();
                if ($(cfg.panelSelector).prop('hidden')) {
                    openPanel();
                } else {
                    closePanel();
                }
            });

            // A centered modal has no on-screen anchor to go stale, so unlike
            // the button-anchored popover this replaced, it no longer needs
            // to hide itself on window scroll.
            $(document).on('click', function () {
                if (!$(cfg.panelSelector).prop('hidden')) {
                    closePanel();
                }
            });
            $(document).on('click', cfg.panelSelector, function (e) {
                e.stopPropagation();
            });
            if (cfg.closeSelector) {
                $(document).on('click', cfg.closeSelector, function () {
                    closePanel();
                });
            }

            $(document).on('change', cfg.panelBodySelector + ' ' + SELECTORS.COLUMN_TOGGLE, function () {
                const key = $(this).attr('data-column-toggle');
                const checked = $(this).is(':checked');
                if (typeof cfg.onToggle === 'function') {
                    cfg.onToggle(key, checked);
                } else {
                    setVisible(key, checked);
                    cfg.onChange(getVisibleOrderedKeys());
                }
                refreshGroupToggles();
            });

            // Group master toggle: sets every field in the group to the same
            // visible/hidden state as an individual row's own toggle would,
            // just applied once per key in the group — reuses the identical
            // onToggle/setVisible path per key so a caller only ever needs to
            // handle one "a field's visibility changed" shape, not two.
            $(document).on('change', cfg.panelBodySelector + ' ' + SELECTORS.GROUP_TOGGLE, function () {
                const group = $(this).attr('data-group-toggle');
                const checked = $(this).is(':checked');
                const $rows = $(cfg.panelBodySelector).find(SELECTORS.DRAG_ROW + '[data-col-group="' + group + '"]');
                $rows.find('[data-column-toggle]').prop('checked', checked);
                $rows.map(function () { return $(this).attr('data-col-key'); }).get().forEach(function (key) {
                    if (typeof cfg.onToggle === 'function') {
                        cfg.onToggle(key, checked);
                    } else {
                        setVisible(key, checked);
                    }
                });
                if (typeof cfg.onToggle !== 'function') {
                    cfg.onChange(getVisibleOrderedKeys());
                }
            });

            // The toggle-switch label only wraps the checkbox itself (it's a
            // fixed-size shared component, not sized to also hold row text),
            // so clicking the field-label text needs to forward to it
            // explicitly — a plain checkbox+label wrapping both would give
            // this "click anywhere on the row" affordance for free, but two
            // nested <label> elements risk double-firing the click in some
            // browsers, hence the forward instead of nesting.
            $(document).on('click', cfg.panelBodySelector + ' ' + SELECTORS.FIELD_LABEL, function () {
                // Native .click() (not jQuery's .trigger('click')) so the
                // checkbox's own default action — flipping .checked before
                // 'change' fires — is guaranteed, not just bound handlers.
                const checkbox = $(this).closest(SELECTORS.DRAG_ROW).find(SELECTORS.COLUMN_TOGGLE).get(0);
                if (checkbox) {
                    checkbox.click();
                }
            });

            if (cfg.selectAllSelector) {
                $(document).on('click', cfg.selectAllSelector, function () {
                    columns.forEach(function (c) { setVisible(c.key, true); });
                    refreshChecks();
                    cfg.onChange(getVisibleOrderedKeys());
                });
            }
            if (cfg.clearAllSelector) {
                $(document).on('click', cfg.clearAllSelector, function () {
                    columns.forEach(function (c) { setVisible(c.key, false); });
                    refreshChecks();
                    cfg.onChange(getVisibleOrderedKeys());
                });
            }
        }

        bindPanelInteractions();
        buildPanel();

        return {
            getVisibleOrderedKeys: getVisibleOrderedKeys,
            getOrderedKeys: getOrderedKeys,
            isVisible: isVisible,
            /** Replace the known column list (e.g. a data type switch) and rebuild the panel. */
            setColumns: function (newColumns) {
                columns = (newColumns || []).slice();
                buildPanel();
            },
        };
    }

    return { init: createInstance };
})(jQuery);
