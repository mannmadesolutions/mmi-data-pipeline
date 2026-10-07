/**
 * Import Preview JavaScript
 * Shows live import table with detailed field comparisons
 */

(function($) {
    'use strict';

    // Mirrors the 0.3s mmiSlideInUp entrance animation on .mmi-config-panel
    // (import-settings.css) so the close — mmiSlideOutDown, the same
    // animation reversed — finishes before display is set back to none.
    const PANEL_CLOSE_MS = 300;

    // Plays the slide-down/fade-out close animation (the entrance reversed)
    // and hides the panel once it completes. Background scroll position is
    // never read or changed: the overlay's CSS overscroll-behavior/touch-action
    // containment keeps the page behind it from moving, so there's nothing to
    // restore — and nothing that can jump the page to the top — on close.
    function closeConfigPanel($panel) {
        if (!$panel.is(':visible')) { return; }
        $panel.stop(true).css('opacity', '').addClass('mmi-panel-closing');
        setTimeout(function () {
            $panel.removeClass('mmi-panel-closing').css('display', 'none');
        }, PANEL_CLOSE_MS);
    }

    // Set for the duration of a column-resize drag (and briefly after mouseup)
    // so the sortable-header click handler below can tell a resize drag apart
    // from an actual header click — the browser still fires a native 'click'
    // on mouseup after a drag even though the user never intended to sort.
    let isResizingColumn = false;

    const MMIImportPreview = {
        refreshTimeout: null,
        // In-flight guard: refreshStats() fans out one sequential AJAX chain
        // across every enabled supplier. Several independent triggers
        // (supplier checkbox, field-mapping change, WC filter bar, page-size
        // select) can each call it within the same window — without this guard
        // a second overlapping chain starts before the first finishes, doubling
        // the concurrent mmi_generate_import_preview load per supplier.
        isRefreshing: false,
        refreshPending: false,
        currentSuppliers: [],
        allResults: [],
        currentFilter: 'all',
        currentStatusFilter: 'all',
        currentSort: { column: 'id', direction: 'asc' },
        importMode: 'create-and-update', // Track the current profile's import mode
        pagination: {
            currentPage: 1,
            pageSize: 200,
            totalItems: 0,
            totalPages: 1
        },
        filters: {
            suppliers: ['skuport', 'xchange', 'plugivery'],
            status: 'all',
            // Single search box: matches against BOTH the product name and the
            // primary key/SKU (previously two separate inputs — merged per UX
            // feedback since they overlapped and "Primary Key" wasn't self-explanatory).
            search: '',
            // Taxonomy checkbox filters: { fieldName: ['term one', 'term two'] } —
            // lowercased selected term values, OR'd within a taxonomy, AND'd
            // across taxonomies. Replaces the old free-text "dynamicFields"
            // per-column filters (those duplicated the WC Search/Stock pills).
            taxonomyFilters: {},
            // Native WP-app filter groups (Author/Orders/Coupon) — see
            // updateAuthorFilterAvailability()/updateOrdersFilterAvailability()
            // below and applyFilters()'s matching predicates. Coupon reads the
            // #mmi-filter-coupon hidden input directly (same pattern as
            // Stock/Image) rather than duplicating its value here.
            authorIds: {},
            orderStatuses: {},
            ordersSince: '',
            // "Create Only" source-data filters (see
            // updateSourceFieldFilterAvailability()) — checkbox selections keyed
            // by field path, plus a parallel live-text-search map for fields
            // flagged high_cardinality in the calculated schema.
            sourceFieldFilters: {},
            sourceFieldSearch: {}
        },
        // Per-supplier filterable-fields schema captured from each
        // generate_preview() AJAX response's 'filterable_fields' key — see
        // loadSupplierStats() and updateSourceFieldFilterAvailability().
        filterableFieldsSchema: {},
        // Set by updateFilterBarAvailability() — true when every loaded row
        // is a create, so field cells can skip the redundant "NEW:" label.
        allRowsAreCreates: false,
        editedValues: {}, // Store inline edits: { 'supplier_primaryKey_fieldName': newValue }

        init: function() {
            MMIModal.init();
            this.bindEvents();
            this.initWcFilterBar();
            this.deferInitialStatsUntilVisible();
        },

        /**
         * Initialise the shared WC product filter bar on the review step.
         * Schedules a preview refresh whenever a real WC-native filter
         * (stock/image/price) changes.
         *
         * Deliberately does NOT refresh on search ('s') changes — search is
         * already fully handled client-side against the already-loaded full
         * feed (see the #wc-product-search-input 'input' listener below,
         * which matches each row's own product_name/primary_key). The
         * server-side search this shared module drives only knows
         * WooCommerce's native post_title/_sku — this pipeline's own
         * primary-key match for a row can be (and often is) a completely
         * different postmeta key per supplier (e.g. _mmi_supplier_sku_xchange),
         * so a value that correctly matches a row's real primary key can
         * still be a genuine miss against _sku/post_title. Forwarding it to
         * generate_preview() as `s` would silently wipe out every row whose
         * WC-native fields don't happen to contain the typed text — exactly
         * the "found it, then it vanished after Recalculating..." bug this
         * comment exists to prevent from being reintroduced.
         */
        initWcFilterBar: function() {
            if (typeof MMI_WcFilterBar === 'undefined') { return; }
            this._lastWcFilterParams = MMI_WcFilterBar.getParams();
            MMI_WcFilterBar.init({
                onChanged: (params) => {
                    const prev = this._lastWcFilterParams || {};
                    const relevantChanged = (
                        params.filter_stock !== prev.filter_stock ||
                        params.filter_featured_image !== prev.filter_featured_image ||
                        params.filter_price_min !== prev.filter_price_min ||
                        params.filter_price_max !== prev.filter_price_max
                    );
                    this._lastWcFilterParams = params;
                    if (relevantChanged) {
                        MMIImportPreview.scheduleRefresh(800);
                    }
                }
            });
        },

        bindEvents: function() {
            $(document).on('change', '.enabled-supplier-checkbox', () => {
                this.scheduleRefresh();
            });

            // Refresh after a manual import completes so stats reflect actual results.
            $(document).on('mmi:importComplete', () => {
                this.scheduleRefresh(1500);
            });
            
            $(document).on('change', 'input[name^="field_mappings"], select[name^="field_mappings"]', () => {
                this.scheduleRefresh(2000);
            });

            // The multi-supplier Source Field <select> (.mmi-source-path-input)
            // deliberately carries no [name] attribute (see its own comment in
            // panel-field-mapping.php), so it isn't caught by the name-based
            // handler above — it's the one real way "which fields are mapped"
            // changes without also touching a named field_mappings[...] input.
            $(document).on('change', '.mmi-source-path-input', () => {
                this.scheduleRefresh(1500);
            });

            $(document).on('change', '#supplier-filter', (e) => {
                this.currentFilter = $(e.target).val();
                this.renderTable();
            });
            
            $(document).on('change', '#status-filter', (e) => {
                this.currentStatusFilter = $(e.target).val();
                this.renderTable();
            });
            
            // "UPDATE (n)" status badge doubles as a one-click spot-check: import
            // just THIS product right now with the profile's live field mappings,
            // so the user can verify import settings against a real record
            // without running/waiting on a full batch import.
            $(document).on('click keydown', '.mmi-badge-clickable', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                MMIImportPreview.spotCheckImportItem($(this));
            });

            // "⚠ N products share this key" badge — deep-links into the
            // Duplicates section's Confirmed Collisions mode (duplicate-products.js)
            // instead of resolving the ambiguity here; a merge/survivor decision
            // belongs in that dedicated review UI, not a click on this table.
            $(document).on('click keydown', '.mmi-pk-collision-trigger', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                e.stopPropagation();
                const $badge = $(this);
                // duplicate-products.js loads on first use (shared mmiLazyScripts).
                const loaded = window.mmiLazyScripts ? window.mmiLazyScripts.load('mmi-pipeline-dupes-js') : Promise.resolve();
                loaded.then(function () {
                    if (window.MMIDupes && typeof window.MMIDupes.openForCollision === 'function') {
                        window.MMIDupes.openForCollision($badge.data('supplier'), String($badge.data('pk-value')));
                    }
                });
            });

            // "🔍" next to a row's Primary Key — inspect the raw source data
            // behind this one record (complete raw item, taxonomy-field
            // resolution status, and the original CSV row for a CSV-backed
            // upload source), so a bad Taxonomy Mapping match or missing field
            // can be diagnosed without manually opening the source file.
            $(document).on('click', '.mmi-inspect-source-btn', function(e) {
                e.preventDefault();
                MMIImportPreview.openSourceInspectModal($(this).data('supplier'), $(this).data('primary-key'));
            });

            // [data-close]/backdrop-click/Esc are owned by MMIModal.init()
            // (called in init() above) now.

            $(document).on('click', '.mmi-source-inspect-copy-btn', function() {
                const $btn = $(this);
                const text = $('#mmi-source-inspect-raw-json').text();
                navigator.clipboard.writeText(text).then(() => {
                    const original = $btn.text();
                    $btn.text('Copied!');
                    setTimeout(() => $btn.text(original), 1500);
                });
            });

            $(document).on('click', '.mmi-sortable-header', function(e) {
                // Ignore the click that a column-resize drag generates on mouseup —
                // the user was adjusting column width, not asking to sort it.
                if (isResizingColumn) return;
                const column = $(this).data('column');
                if (MMIImportPreview.currentSort.column === column) {
                    MMIImportPreview.currentSort.direction = MMIImportPreview.currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    MMIImportPreview.currentSort.column = column;
                    MMIImportPreview.currentSort.direction = 'asc';
                }
                MMIImportPreview.renderTable();
            });
            
            // Filter bar event handlers
            $(document).on('click', '.mmi-filter-status-btn', function(e) {
                e.preventDefault();
                $('.mmi-filter-status-btn').removeClass('active');
                $(this).addClass('active');
                MMIImportPreview.filters.status = $(this).data('status');
                MMIImportPreview.renderTable();
            });

            // Stat tiles inside "mmi-stats-grid mmi-is-exact" (Will Create, Will
            // Update, Unchanged, SKU Conflicts) double as click-to-filter toggles —
            // click once to show only matching rows, click again to clear it.
            // Only one of these filters can be active at a time (filters.status
            // is a single value), so selecting a new one silently replaces
            // whichever was active before.
            $(document).on('click keydown', '.mmi-stat-clickable', function(e) {
                if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
                e.preventDefault();
                const status = $(this).data('status');
                $('.mmi-filter-status-btn').removeClass('active');
                if (MMIImportPreview.filters.status === status) {
                    MMIImportPreview.filters.status = 'all';
                    $('.mmi-filter-status-btn[data-status="all"]').addClass('active');
                } else {
                    MMIImportPreview.filters.status = status;
                }
                MMIImportPreview.pagination.currentPage = 1;
                MMIImportPreview.renderTable();
                MMIImportPreview.renderSummary();
            });

            // Per-row "Clear" button on a SKU-conflict row — dismisses just
            // that conflict warning; the row is then treated as a plain create.
            $(document).on('click', '.mmi-conflict-clear-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                MMIImportPreview.dismissSkuConflicts([$(this).data('sku')], $(this));
            });

            // Bulk "Clear All" button on the SKU Conflicts stat tile —
            // dismisses every conflict currently loaded across all suppliers.
            $(document).on('click', '.mmi-clear-all-conflicts-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const skus = new Set();
                MMIImportPreview.allResults.forEach(result => {
                    result.data.items.forEach(item => {
                        if (item.sku_conflict && item.sku_conflict.sku) {
                            skus.add(item.sku_conflict.sku);
                        }
                    });
                });
                if (skus.size === 0) return;

                if (!window.confirm(`Permanently skip importing ${skus.size} record${skus.size !== 1 ? 's' : ''} whose SKU already belongs to an existing product? They will no longer be attempted on future imports.`)) {
                    return;
                }
                MMIImportPreview.dismissSkuConflicts(Array.from(skus), $(this));
            });
            
            // Single shared search box (#wc-product-search-input). The shared
            // wc-product-filter-bar.js module already reads this same input to
            // drive its own debounced server-side search (matches EXISTING WC
            // products only — see wc_allowed_ids in class-import-preview.php,
            // which explicitly skips "create" rows since they have no WC
            // product yet). This second listener applies the same typed text
            // client-side against product_name/primary_key on every already-
            // loaded item, so "create" rows the server-side search can't reach
            // are still narrowed by the same box. Replaces the old separate
            // #filter-search input, which was redundant with this one.
            $(document).on('input', '#wc-product-search-input', function() {
                clearTimeout(MMIImportPreview.filterTimeout);
                MMIImportPreview.filterTimeout = setTimeout(() => {
                    MMIImportPreview.filters.search = $(this).val().toLowerCase();
                    MMIImportPreview.renderTable();
                }, 300);
            });
            
            // Taxonomy term checkboxes — multiple may be checked per taxonomy
            // (OR match within that taxonomy); collect every checked box in the
            // same <details> group rather than just the one that changed.
            $(document).on('change', '.mmi-taxonomy-filter-checkbox', function() {
                const taxonomy = $(this).data('taxonomy');
                const $details = $(this).closest('.mmi-taxonomy-filter-details');
                const selected = [];
                $details.find('.mmi-taxonomy-filter-checkbox:checked').each(function() {
                    selected.push(String($(this).val()).toLowerCase());
                });
                MMIImportPreview.setTaxonomyFilter(taxonomy, selected, $details);
            });

            // A taxonomy with more distinct terms actually present in the
            // current preview than MAX_OPTIONS renders as a native <select
            // multiple> instead of individual checkboxes — see
            // updateTaxonomyFilterAvailability().
            $(document).on('change', '.mmi-taxonomy-filter-select', function() {
                const taxonomy = $(this).data('taxonomy');
                const $details = $(this).closest('.mmi-taxonomy-filter-details');
                const selected = ($(this).val() || []).map(v => String(v).toLowerCase());
                MMIImportPreview.setTaxonomyFilter(taxonomy, selected, $details);
            });

            // Author filter — multiple may be checked (OR match), same
            // collect-every-checked-box approach as the taxonomy checkboxes
            // above, just against one flat group instead of one per taxonomy.
            $(document).on('change', '.mmi-author-filter-checkbox', function() {
                const selected = {};
                $('.mmi-author-filter-checkbox:checked').each(function() {
                    selected[$(this).val()] = true;
                });
                MMIImportPreview.filters.authorIds = selected;
                MMIImportPreview.renderTable();
            });

            // Orders filter — status checkboxes (OR match) plus an optional
            // "since" date floor on the product's last order date.
            $(document).on('change', '.mmi-order-status-filter-checkbox', function() {
                const selected = {};
                $('.mmi-order-status-filter-checkbox:checked').each(function() {
                    selected[$(this).val()] = true;
                });
                MMIImportPreview.filters.orderStatuses = selected;
                MMIImportPreview.renderTable();
            });
            $(document).on('change', '#mmi-filter-orders-since', function() {
                MMIImportPreview.filters.ordersSince = $(this).val() || '';
                MMIImportPreview.renderTable();
            });

            // "Create Only" source-data field checkboxes (OR within a field,
            // collected per <details> group exactly like the taxonomy
            // checkboxes above) and the live-text-search fallback for a
            // field flagged high_cardinality in the calculated schema.
            $(document).on('change', '.mmi-source-field-filter-checkbox', function() {
                const path = $(this).data('source-field');
                const $details = $(this).closest('.mmi-taxonomy-filter-details');
                const selected = [];
                $details.find('.mmi-source-field-filter-checkbox:checked').each(function() {
                    selected.push(String($(this).val()));
                });
                MMIImportPreview.filters.sourceFieldFilters[path] = selected;
                MMIImportPreview.renderTable();
            });
            $(document).on('input', '.mmi-source-field-filter-search', function() {
                const path = $(this).data('source-field');
                const needle = $(this).val().toLowerCase();
                clearTimeout(MMIImportPreview.sourceFieldSearchTimeout);
                MMIImportPreview.sourceFieldSearchTimeout = setTimeout(() => {
                    MMIImportPreview.filters.sourceFieldSearch[path] = needle;
                    MMIImportPreview.renderTable();
                }, 300);
            });

            // Native <details> has no built-in click-outside-to-close behaviour —
            // without this, an open taxonomy filter panel stays open until its own
            // <summary> is clicked again, even after the user clicks elsewhere on
            // the page. Close any open panel(s) whenever a click lands outside all
            // of them; clicks on the summary/checkboxes themselves are inside
            // .mmi-taxonomy-filter-details so they're unaffected.
            $(document).on('click', function(e) {
                if ($(e.target).closest('.mmi-taxonomy-filter-details').length === 0) {
                    $('.mmi-taxonomy-filter-details[open]').removeAttr('open');
                }
            });
            
            $(document).on('click', '#btn-clear-filters', function(e) {
                e.preventDefault();
                MMIImportPreview.clearAllFilters();
            });
            
            $(document).on('click', '.mmi-panel-close', function(e) {
                e.preventDefault();
                const panelId = $(this).data('panel');
                MMIImportPreview.hidePanel(panelId);
            });
            
            $(document).on('click', '.mmi-panel-overlay', function(e) {
                MMIImportPreview.hideAllPanels();
            });
            
            // ESC key to close panels
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    MMIImportPreview.hideAllPanels();
                }
            });

            // Prevent scroll from leaking through panel content to the page behind.
            // Uses a native listener so stopPropagation is applied before jQuery's
            // delegated handlers (and before WordPress admin's scroll container sees it).
            //
            // Guard: only intercept when the panel-content element is actually scrollable.
            // Topbar-embedded panels (Stock Overrides, Catalog Rules) use .mmi-panel-content
            // but have no height constraint, so their scrollHeight === clientHeight. Without
            // this guard every wheel event in those panels would hit preventDefault() and
            // block the page from scrolling entirely.
            document.addEventListener('wheel', function(e) {
                const content = e.target.closest('.mmi-panel-content');
                if (!content) return;
                if (content.scrollHeight <= content.clientHeight) return; // not scrollable — let it bubble
                const atTop    = content.scrollTop === 0 && e.deltaY < 0;
                const atBottom = content.scrollTop + content.clientHeight >= content.scrollHeight && e.deltaY > 0;
                if (atTop || atBottom) {
                    e.preventDefault();
                } else {
                    e.stopPropagation();
                }
            }, { passive: false });
            
            // Accordion functionality
            $(document).on('click', '.mmi-section-header, .mmi-collapse-toggle', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const $section = $(this).closest('.mmi-collapsible-section');
                $section.toggleClass('collapsed');
            });
            
            // Pagination controls — use class selector since the control is rendered
            // twice (top + bottom) and duplicate IDs are invalid / unreliable.
            // stopImmediatePropagation prevents any other script (e.g. import-settings-save-
            // indicator.js form-change tracker, WordPress core, or other plugins) from
            // intercepting this event and triggering a full page reload.
            $(document).on('change', '.mmi-page-size-select', function(e) {
                e.stopImmediatePropagation();
                e.stopPropagation();
                e.preventDefault();
                const newSize = parseInt($(this).val());
                if (isNaN(newSize) || newSize === MMIImportPreview.pagination.pageSize) return;
                MMIImportPreview.pagination.pageSize = newSize;
                MMIImportPreview.pagination.currentPage = 1;
                // Cancel any pending scheduled refresh, then immediately fire a fresh
                // AJAX fetch so preview-table-content is updated via AJAX (not page reload).
                clearTimeout(MMIImportPreview.refreshTimeout);
                MMIImportPreview.refreshStats();
            });
            
            $(document).on('click', '.mmi-pagination-btn', function(e) {
                e.preventDefault();
                const action = $(this).data('action');
                MMIImportPreview.handlePagination(action);
            });
            
            $(document).on('click', '.mmi-page-number', function(e) {
                e.preventDefault();
                const page = parseInt($(this).data('page'));
                MMIImportPreview.pagination.currentPage = page;
                MMIImportPreview.renderTable();
            });
            
            // Column resizing
            $(document).on('mousedown', '.col-resizer', (e) => {
                e.preventDefault();
                e.stopPropagation();
                isResizingColumn = true;
                const $resizer = $(e.target);
                const colIndex = parseInt($resizer.data('col'));
                const $th = $resizer.parent();
                const $table = $th.closest('table');
                const startX = e.pageX;
                const startWidth = $th.width();

                const onMouseMove = (e) => {
                    const diff = e.pageX - startX;
                    const newWidth = Math.max(50, startWidth + diff);
                    $th.css('width', newWidth + 'px');
                    $table.find('tbody tr').each(function() {
                        $(this).find('td').eq(colIndex).css('width', newWidth + 'px');
                    });
                };

                const onMouseUp = () => {
                    $(document).off('mousemove', onMouseMove);
                    $(document).off('mouseup', onMouseUp);
                    // The mouseup that ends the drag is immediately followed by a
                    // synthetic 'click' on whatever's under the pointer (often the
                    // header itself) — keep the guard up through that same tick
                    // and clear it right after so real clicks still sort normally.
                    setTimeout(() => { isResizingColumn = false; }, 0);
                };

                $(document).on('mousemove', onMouseMove);
                $(document).on('mouseup', onMouseUp);
            });

            // A plain click directly on the resizer handle (no drag) shouldn't
            // sort the column either — stop it before it bubbles to the
            // .mmi-sortable-header delegated handler above.
            $(document).on('click', '.col-resizer', function(e) {
                e.stopPropagation();
            });

            // Inline editing event handlers
            $(document).on('click', '.mmi-field-val-editable', function(e) {
                e.stopPropagation();
                if ($(this).hasClass('mmi-editing')) return;
                MMIImportPreview.enterEditMode($(this));
            });

            $(document).on('click', '.mmi-edit-save-btn', function(e) {
                e.stopPropagation();
                const $container = $(this).closest('.mmi-field-edit-mode');
                MMIImportPreview.saveInlineEdit($container);
            });

            $(document).on('click', '.mmi-edit-cancel-btn', function(e) {
                e.stopPropagation();
                const $container = $(this).closest('.mmi-field-edit-mode');
                MMIImportPreview.cancelInlineEdit($container);
            });

            $(document).on('keydown', '.mmi-edit-input', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const $container = $(this).closest('.mmi-field-edit-mode');
                    MMIImportPreview.saveInlineEdit($container);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    const $container = $(this).closest('.mmi-field-edit-mode');
                    MMIImportPreview.cancelInlineEdit($container);
                }
            });

            $(document).on('click', '.mmi-edit-revert-btn', function(e) {
                e.stopPropagation();
                const editKey = $(this).data('edit-key');
                MMIImportPreview.revertEdit(editKey);
            });

            $(document).on('click', '#mmi-clear-all-edits', function(e) {
                e.stopPropagation();
                MMIImportPreview.editedValues = {};
                MMIImportPreview.renderTable();
            });

            // Re-fetch when the allow-create-products toolbar toggle is flipped.
            // The value is persisted by import-settings.js; we just need the
            // preview table to re-query so item counts and row set update.
            $(document).on('click', '.mmi-filter-toggle-label[data-profile-setting="allow_create_products"]', function() {
                // Delay slightly so the AJAX autosave fires first and the DB has
                // the new value before our fetch reads it.
                MMIImportPreview.scheduleRefresh(1500);
            });
        },
        
        showPanel: function(panelId) {
            this.hideAllPanels();

            // Create overlay if it doesn't exist
            if ($('.mmi-panel-overlay').length === 0) {
                $('body').append('<div class="mmi-panel-overlay"></div>');
            }

            $('.mmi-panel-overlay').addClass('active');
            // Use css display:flex + opacity animation instead of fadeIn().
            // jQuery's fadeIn() sets display:block, which breaks the flexbox
            // layout of .mmi-config-panel and prevents .mmi-panel-content from scrolling.
            // The slide-up entrance itself comes from the mmiSlideInUp CSS
            // animation on .mmi-config-panel, which (re)plays on display change.
            $('#' + panelId).removeClass('mmi-panel-closing').css({'display': 'flex', 'opacity': 0}).animate({opacity: 1}, 300);
        },

        hidePanel: function(panelId) {
            closeConfigPanel($('#' + panelId));
            $('.mmi-panel-overlay').removeClass('active');
        },

        hideAllPanels: function() {
            $('.mmi-config-panel').each(function () { closeConfigPanel($(this)); });
            $('.mmi-panel-overlay').removeClass('active');
        },
        
        loadInitialStats: function() {
            setTimeout(() => {
                this.refreshStats();
            }, 500);
        },

        // Review & Compare's mmi_generate_import_preview call is the single
        // most expensive request this tab fires on page load — it scans the
        // WHOLE matched-item set (limit=999999, deliberately not capped: the
        // will_create/will_update/etc. stats have to reflect the full catalog
        // or they lie, see the 2026-08-30 Incident History entry — "Import
        // Preview Ignored product_scope"), returning a response that can run
        // past 900KB for a several-thousand-product feed. On the embedded
        // tab-pipeline.php layout, Review & Compare is the 5th section down
        // (after Import Profiles, Supplier Data Sources, Catalog Maintenance,
        // Duplicate Products) — reliably below the fold — so firing this the
        // instant the page loads spends that cost even when the admin never
        // scrolls anywhere near it this visit. Deferred with an
        // IntersectionObserver instead of the accordion collapse mechanism
        // (unlike Catalog Maintenance below) because this section stays
        // expanded by default — it's meant to read as an always-current
        // dashboard once seen, just not worth computing before it's seen.
        //
        // #mmi-review-compare-section only exists on that embedded layout;
        // the standalone multi-step wizard's own "Step 2: Review" view (see
        // import-pipeline.js's initializeStepModules(2)) has no such wrapper
        // — the admin has already explicitly navigated to that step, so
        // there's nothing to defer there and this falls through to the
        // original eager behavior.
        deferInitialStatsUntilVisible: function() {
            // Non-Product profiles render a plain record count instead of
            // this rich preview (pipeline-step-2-review.php, Milestone 5 —
            // DATA_PIPELINE_PHASE2_SCOPING.md) — that markup carries no
            // .enabled-supplier-checkbox elements, but getEnabledSuppliers()
            // falls back to the server-localized supplier list when none are
            // found in the DOM, so without this guard the expensive
            // mmi_generate_import_preview request would still fire and
            // compute entirely Product-shaped stats against non-Product data.
            if ($('#import-preview-main.mmi-generic-preview').length) { return; }

            const $section = $('#mmi-review-compare-section');

            if ($section.length === 0 || typeof IntersectionObserver === 'undefined') {
                this.loadInitialStats();
                return;
            }

            const observer = new IntersectionObserver((entries) => {
                const isVisible = entries.some((entry) => entry.isIntersecting);
                if (!isVisible) { return; }
                observer.disconnect();
                this.loadInitialStats();
            }, {
                // Start the fetch a little before the section's top edge
                // actually reaches the viewport, so the table is ready (or
                // close to it) by the time a scrolling admin arrives at it
                // rather than making them wait after they've already stopped.
                rootMargin: '200px 0px'
            });

            observer.observe($section.get(0));
        },
        
        scheduleRefresh: function(delay = 1000) {
            clearTimeout(this.refreshTimeout);
            // Immediately signal a pending update so the user knows the table
            // will recalculate. Only shown when the table already has content.
            if ($('#preview-table-content').find('table tbody tr').length > 0) {
                $('#preview-table-content').addClass('mmi-preview-stale');
                $('#mmi-refresh-notice').show();
            }
            this.refreshTimeout = setTimeout(() => {
                this.refreshStats();
            }, delay);
        },
        
        getEnabledSuppliers: function() {
            const suppliers = [];
            $('.enabled-supplier-checkbox:checked').each(function() {
                suppliers.push($(this).val());
            });
            // Fallback: if no DOM checkboxes present (e.g. pipeline Step 2 view),
            // use the server-provided list of enabled suppliers.
            let pool = suppliers;
            if (pool.length === 0 && mmiProductImportData && Array.isArray(mmiProductImportData.enabledSuppliers) && mmiProductImportData.enabledSuppliers.length > 0) {
                pool = mmiProductImportData.enabledSuppliers;
            }

            // Scope down to the ACTIVE profile's own configured sources. An
            // empty sources list on the profile is a deliberate, valid choice
            // ("use every currently-enabled source" — see the profile
            // wizard's own help text), so only restrict when the active
            // profile actually names specific sources. Without this, the
            // preview would show every globally-enabled supplier regardless
            // of which ones this profile is actually set up to import from.
            const profileSources = $('.mmi-profile-grid-card.is-active').data('sources');
            if (Array.isArray(profileSources) && profileSources.length > 0) {
                return pool.filter(supplier => profileSources.includes(supplier));
            }

            return pool;
        },
        
        // Every currently-mapped field, in Field Mapping table order — a field
        // no longer needs a separate "Show in Preview" toggle to earn a
        // column here; mapping it in the Field Mapping table already implies
        // enabling and previewing it (see panel-field-mapping.php).
        getPreviewFields: function() {
            // post_title is always rendered as the fixed "Product Name" column;
            // allowing it here would create a redundant duplicate column.
            const FIXED_COLUMN_FIELDS = new Set(['post_title']);

            const fields = [];
            $('.field-mapping-row').each(function() {
                const $row = $(this);
                const fieldName = $row.data('field');
                if (!fieldName || FIXED_COLUMN_FIELDS.has(fieldName)) return;

                const $sourceRows = $row.find('.supplier-source-row');
                let mapped;
                if ($sourceRows.length) {
                    // Recomputed live via the same check import-settings.js's
                    // rowHasMapping() uses (shared via window.MMIFieldMapping,
                    // with an inline fallback here in case that script hasn't
                    // loaded) rather than trusting a rendered "Not mapped"
                    // pill's DOM state, which is only ever created/removed on
                    // demand and can't be relied on to always be present.
                    mapped = (window.MMIFieldMapping && typeof window.MMIFieldMapping.rowHasMapping === 'function')
                        ? window.MMIFieldMapping.rowHasMapping($row)
                        // :visible excludes a supplier this profile doesn't assign
                        // (mmi-supplier-not-in-profile, display:none) — same fix as
                        // rowHasMapping() itself, kept in sync here since this is
                        // only a fallback for that function being unavailable.
                        : $sourceRows.filter(':visible').toArray().some(function (sr) {
                            const $sr = $(sr);
                            if ($sr.find('.use-constant-checkbox').is(':checked')) return true;
                            const $select = $sr.find('.mmi-source-path-input');
                            return $select.length ? !!$select.val() : !!$sr.find('[class*="field-source-"]').val();
                        });
                } else {
                    // Read-only rows with no editable per-supplier controls at
                    // all (product_brand's static alias-status summary) —
                    // mapped state never changes without a page reload, so the
                    // server-rendered "Not mapped" pill is still accurate.
                    mapped = !$row.find('.mmi-field-not-mapped-pill').length;
                }
                if (!mapped) return;

                fields.push({
                    name: fieldName,
                    label: $row.find('.mmi-woo-field-name').first().text() || fieldName,
                    type: $row.data('field-type') || ''
                });
            });
            return fields;
        },

        /**
         * Shared by both the checkbox and <select multiple> taxonomy filter
         * controls: stores the selected term slugs for a taxonomy (or clears
         * it if none are selected) and updates that taxonomy's <summary>
         * badge without disturbing the other group's open/closed state.
         */
        setTaxonomyFilter: function(taxonomy, selectedSlugs, $details) {
            if (selectedSlugs.length > 0) {
                this.filters.taxonomyFilters[taxonomy] = selectedSlugs;
            } else {
                delete this.filters.taxonomyFilters[taxonomy];
            }

            const label = $details.data('label') || '';
            $details.find('summary').first().html(
                selectedSlugs.length > 0
                    ? this.escapeHtml(label) + ' <span class="mmi-taxonomy-filter-count">' + selectedSlugs.length + '</span>'
                    : this.escapeHtml(label)
            );

            this.renderTable();
        },
        
        clearAllFilters: function() {
            // Reset status filter
            $('.mmi-filter-status-btn').removeClass('active');
            $('.mmi-filter-status-btn[data-status="all"]').addClass('active');
            $('.mmi-stat-conflict').removeClass('mmi-stat-active');
            this.filters.status = 'all';
            
            // Reset text filters (the input's own value is cleared by
            // MMI_WcFilterBar.reset() below, since it's the same shared box)
            this.filters.search = '';
            
            // Reset taxonomy checkbox/select filters
            $('.mmi-taxonomy-filter-checkbox').prop('checked', false);
            $('.mmi-taxonomy-filter-select').val([]);
            $('.mmi-taxonomy-filter-details').each(function() {
                $(this).find('summary').first().text($(this).data('label') || '');
            });
            this.filters.taxonomyFilters = {};

            // Reset the new native/source filter groups. Author/Orders-status/
            // source-field checkboxes are also rebuilt fresh (and their
            // this.filters entries reset to {}) by the next
            // updateFilterBarAvailability() call the refresh below triggers —
            // but Coupon and the Orders "since" date are plain manually-set
            // values updateWcFactFilterAvailability() only clears when
            // hasUpdateRows is false, so Clear must reset them explicitly
            // regardless of that state.
            $('#mmi-filter-coupon').val('');
            $('[data-filter="mmi-filter-coupon"] .mmi-pill').removeClass('active');
            $('#mmi-filter-orders-since').val('');
            this.filters.ordersSince = '';

            // Reset WC filters (stock, image, price, search). reset()'s own
            // onChanged only schedules a server refresh when a real WC-native
            // filter (stock/image/price) actually changed — search is
            // client-only now (see initWcFilterBar()), so a search-only Clear
            // wouldn't trigger anything without this explicit renderTable()
            // call. Always call it here rather than relying on reset() to:
            // cheap, and correctly reflects the just-cleared search state
            // immediately regardless of whether a server refresh also fires.
            if (typeof MMI_WcFilterBar !== 'undefined') {
                MMI_WcFilterBar.reset();
            }

            this.renderTable();
        },
        
        handlePagination: function(action) {
            switch(action) {
                case 'first':
                    this.pagination.currentPage = 1;
                    break;
                case 'prev':
                    this.pagination.currentPage = Math.max(1, this.pagination.currentPage - 1);
                    break;
                case 'next':
                    this.pagination.currentPage = Math.min(this.pagination.totalPages, this.pagination.currentPage + 1);
                    break;
                case 'last':
                    this.pagination.currentPage = this.pagination.totalPages;
                    break;
            }
            this.renderTable();
        },
        
        paginateItems: function(items) {
            this.pagination.totalItems = items.length;
            this.pagination.totalPages = Math.ceil(items.length / this.pagination.pageSize);
            
            // Ensure current page is valid
            if (this.pagination.currentPage > this.pagination.totalPages) {
                this.pagination.currentPage = Math.max(1, this.pagination.totalPages);
            }
            
            const startIndex = (this.pagination.currentPage - 1) * this.pagination.pageSize;
            const endIndex = startIndex + this.pagination.pageSize;
            
            return items.slice(startIndex, endIndex);
        },
        
        renderPaginationControls: function() {
            const { currentPage, totalPages, totalItems, pageSize } = this.pagination;
            const startItem = ((currentPage - 1) * pageSize) + 1;
            const endItem = Math.min(currentPage * pageSize, totalItems);
            
            let html = '<div class="mmi-pagination-container">';
            
            // Page size selector (no id — rendered twice top+bottom, use class only)
            html += `
                <div class="mmi-pagination-size">
                    <label>Show:</label>
                    <select class="mmi-page-size-select">
                        <option value="50" ${pageSize === 50 ? 'selected' : ''}>50</option>
                        <option value="200" ${pageSize === 200 ? 'selected' : ''}>200</option>
                        <option value="500" ${pageSize === 500 ? 'selected' : ''}>500</option>
                    </select>
                    <span class="mmi-pagination-info">items per page</span>
                </div>
            `;
            
            // Pagination info
            html += `
                <div class="mmi-pagination-info-text">
                    Showing ${startItem.toLocaleString()}-${endItem.toLocaleString()} of ${totalItems.toLocaleString()} items
                </div>
            `;

            // Pagination buttons
            html += '<div class="mmi-pagination-buttons">';
            
            // First button
            html += `<button type="button" class="mmi-pagination-btn" data-action="first" ${currentPage === 1 ? 'disabled' : ''}>
                <span class="dashicons dashicons-controls-skipback"></span>
            </button>`;
            
            // Previous button
            html += `<button type="button" class="mmi-pagination-btn" data-action="prev" ${currentPage === 1 ? 'disabled' : ''}>
                <span class="dashicons dashicons-arrow-left-alt2"></span>
            </button>`;
            
            // Page numbers
            const maxPageButtons = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxPageButtons / 2));
            let endPage = Math.min(totalPages, startPage + maxPageButtons - 1);
            
            if (endPage - startPage < maxPageButtons - 1) {
                startPage = Math.max(1, endPage - maxPageButtons + 1);
            }
            
            if (startPage > 1) {
                html += '<span class="mmi-pagination-ellipsis">...</span>';
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += `<button type="button" class="mmi-page-number ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
            }
            
            if (endPage < totalPages) {
                html += '<span class="mmi-pagination-ellipsis">...</span>';
            }
            
            // Next button
            html += `<button type="button" class="mmi-pagination-btn" data-action="next" ${currentPage === totalPages ? 'disabled' : ''}>
                <span class="dashicons dashicons-arrow-right-alt2"></span>
            </button>`;
            
            // Last button
            html += `<button type="button" class="mmi-pagination-btn" data-action="last" ${currentPage === totalPages ? 'disabled' : ''}>
                <span class="dashicons dashicons-controls-skipforward"></span>
            </button>`;
            
            html += '</div></div>';
            
            return html;
        },
        
        applyFilters: function(items) {
            const enabledSuppliers = this.getEnabledSuppliers();
            const couponFilter = $('#mmi-filter-coupon').val();

            // Read the DOM state of the allow-create-products toolbar toggle.
            // The PHP/server already excludes creates when the setting is false, but
            // items already cached in this.allResults still need client-side gating
            // so toggling takes effect instantly without a full re-fetch.
            const $createToggle = $('.mmi-filter-toggle-label[data-profile-setting="allow_create_products"] .mmi-filter-toggle-slider');
            const allowCreate = $createToggle.length === 0 || $createToggle.attr('data-enabled') === 'true';
            
            return items.filter(item => {
                // Supplier filter (from toolbar)
                if (!enabledSuppliers.includes(item.supplier)) {
                    return false;
                }

                // Respect the allow-create-products toolbar toggle immediately,
                // before any status filter, so the "Create" button in the status
                // filter bar also becomes irrelevant when disabled.
                if (!allowCreate && item.action === 'create') {
                    return false;
                }
                
                // Status filter
                if (this.filters.status !== 'all') {                    
                    // If allow_create is off, skip the 'create' sub-filter silently
                    // (there are no create rows to match anyway).
                    if (!allowCreate && this.filters.status === 'create') {
                        return false;
                    }
                    if (this.filters.status === 'create' && item.action !== 'create') {
                        return false;
                    }
                    if (this.filters.status === 'update' && (item.action !== 'update' || item.change_count === 0)) {
                        return false;
                    }
                    if (this.filters.status === 'unchanged' && (item.action !== 'update' || item.change_count > 0)) {
                        return false;
                    }
                    if (this.filters.status === 'conflict' && !item.sku_conflict) {
                        return false;
                    }
                }
                
                // Search filter (matches product name, primary key/SKU, or WC product ID —
                // the input's own placeholder promises all three, but ID matching was
                // previously missing here).
                if (this.filters.search) {
                    const nameMatch = item.product_name.toLowerCase().includes(this.filters.search);
                    const keyMatch = String(item.primary_key).toLowerCase().includes(this.filters.search);
                    const idMatch = item.product_id && String(item.product_id).toLowerCase().includes(this.filters.search);
                    if (!nameMatch && !keyMatch && !idMatch) {
                        return false;
                    }
                }
                
                // Taxonomy checkbox/select filters — matched against the REAL WP
                // term slugs assigned to the existing product (item.current_terms,
                // computed server-side in class-import-preview.php from EVERY
                // show_ui product taxonomy, not just ones this profile maps as an
                // import field). An item passes a given taxonomy's filter if ANY
                // checked term slug is among its assigned slugs for that taxonomy
                // (OR within the taxonomy); every taxonomy with an active filter
                // must pass (AND across taxonomies). "create" rows have no WC
                // product yet, so there's nothing to check them against — they're
                // left visible rather than hidden, mirroring the WC search box's
                // existing create-row exemption.
                if (item.action !== 'create') {
                    for (const [taxonomy, selectedSlugs] of Object.entries(this.filters.taxonomyFilters)) {
                        if (!selectedSlugs || selectedSlugs.length === 0) continue;

                        const assignedSlugs = (item.current_terms?.[taxonomy] || []).map(t => String(t.slug).toLowerCase());
                        const isMatch = selectedSlugs.some(slug => assignedSlugs.includes(slug));
                        if (!isMatch) {
                            return false;
                        }
                    }
                }

                // Author filter — same create-row exemption as Categories above
                // (a 'create' row has no post_author yet to check).
                if (item.action !== 'create' && this.filters.authorIds && Object.keys(this.filters.authorIds).length > 0) {
                    if (!item.author_id || !this.filters.authorIds[item.author_id]) {
                        return false;
                    }
                }

                // Orders filter (status OR within the group, plus an optional
                // "since" date floor on the product's last order date).
                if (item.action !== 'create' && this.filters.orderStatuses && Object.keys(this.filters.orderStatuses).length > 0) {
                    const itemStatuses = item.order_statuses || [];
                    const isMatch = itemStatuses.some(s => this.filters.orderStatuses[s]);
                    if (!isMatch) {
                        return false;
                    }
                }
                if (item.action !== 'create' && this.filters.ordersSince) {
                    if (!item.last_order_date || item.last_order_date.slice(0, 10) < this.filters.ordersSince) {
                        return false;
                    }
                }

                // Coupon filter — simple boolean match.
                if (item.action !== 'create' && couponFilter) {
                    const wantsInCoupon = couponFilter === 'yes';
                    if (Boolean(item.in_active_coupon) !== wantsInCoupon) {
                        return false;
                    }
                }

                // Source-data field filters ("Create Only" group) — OR within one
                // field's selected checkbox values or its live text search, AND
                // across different fields. Applied to EVERY row (create and
                // update alike), since item.source_filter_values comes from the
                // raw feed record regardless of WC match status.
                if (this.filters.sourceFieldFilters) {
                    for (const [path, selectedValues] of Object.entries(this.filters.sourceFieldFilters)) {
                        if (!selectedValues || selectedValues.length === 0) continue;
                        const itemValue = (item.source_filter_values || {})[path];
                        if (!itemValue || !selectedValues.includes(itemValue)) {
                            return false;
                        }
                    }
                }
                if (this.filters.sourceFieldSearch) {
                    for (const [path, needle] of Object.entries(this.filters.sourceFieldSearch)) {
                        if (!needle) continue;
                        const itemValue = ((item.source_filter_values || {})[path] || '').toLowerCase();
                        if (!itemValue.includes(needle)) {
                            return false;
                        }
                    }
                }

                return true;
            });
        },

        /**
         * Spot-check: immediately import (create-or-update) the single product
         * behind a clicked "UPDATE (n)" badge, using the profile's live field
         * mappings — hits the same Product_Import_Worker::import_single_product()
         * the real batch import uses, so the result is trustworthy, then
         * refreshes the whole preview so the row reflects the new state.
         * THIS WRITES DIRECTLY TO THE STORE — it is not a preview action.
         */
        spotCheckImportItem: function($badge) {
            if ($badge.hasClass('mmi-badge-loading')) {
                return; // already in flight — ignore repeat clicks/keypresses
            }

            const supplier = $badge.data('supplier');
            const primaryKey = $badge.data('primary-key');
            if (!supplier || primaryKey === undefined || primaryKey === null || primaryKey === '') {
                return;
            }

            const profile = $('#mmi-import-profile').val() || mmiProductImportData?.currentProfile || 'default';
            // #mmi-import-profile's value is the immutable profile ID/slug
            // (e.g. "test") — NOT the current display name, which can be
            // renamed later via the profile wizard without changing the ID.
            // The wizard keeps the selected <option>'s TEXT in sync with the
            // live name on rename (see import-settings.js), so use that for
            // anything user-facing; the ID is still what's sent to the server.
            const profileLabel = $('#mmi-import-profile option:selected').text().trim() || profile;

            if (!window.confirm(
                `Import this product now using the "${profileLabel}" profile's current field mappings?\n\n` +
                'This writes directly to the store immediately — it is not a preview.'
            )) {
                return;
            }

            const originalHtml = $badge.html();
            const loadingLabel = $badge.hasClass('mmi-badge-create') ? '⏳ Creating…' : '⏳ Updating…';
            $badge.addClass('mmi-badge-loading').attr('aria-busy', 'true').html(loadingLabel);

            $.ajax({
                url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                method: 'POST',
                data: {
                    action: 'mmi_import_single_item',
                    supplier: supplier,
                    primary_key: primaryKey,
                    profile: profile,
                    nonce: mmiProductImportData?.nonce || ''
                }
            }).done((response) => {
                if (response.success) {
                    const data = response.data || {};
                    // Re-fetch the full preview so this row (and any stats that
                    // depend on it) reflect the product's new, actual state —
                    // THEN show the success toast. Showing it immediately (as
                    // soon as the import AJAX call returns) was misleading: the
                    // import itself completes quickly, but the row still shows
                    // the stale "UPDATE" badge/CURRENT value until this refresh
                    // finishes rendering, so the toast appeared to lie about
                    // completion. refreshStats() now returns its promise chain
                    // specifically so this can wait on it.
                    Promise.resolve(this.refreshStats()).then(() => {
                        this.showToast(data.message || 'Done.', 'success');
                    });
                } else {
                    $badge.removeClass('mmi-badge-loading').removeAttr('aria-busy').html(originalHtml);
                    this.showToast(response.data?.message || 'Import failed.', 'error');
                }
            }).fail(() => {
                $badge.removeClass('mmi-badge-loading').removeAttr('aria-busy').html(originalHtml);
                this.showToast('Import failed — network or server error.', 'error');
            });
        },

        /**
         * Confirm one or more SKU conflicts are genuine duplicates of an
         * existing product and permanently skip importing them for the
         * current profile — they're excluded from the preview/import
         * entirely on the next refresh, never retried as a create.
         */
        dismissSkuConflicts: function(skus, $trigger) {
            skus = (skus || []).filter(Boolean);
            if (skus.length === 0) return;

            const profile = $('#mmi-import-profile').val() || mmiProductImportData?.currentProfile || 'default';
            const isBulk = skus.length > 1;
            const originalHtml = $trigger ? $trigger.html() : null;
            if ($trigger) {
                $trigger.prop('disabled', true).attr('aria-busy', 'true').text('⏳ Skipping…');
            }

            $.ajax({
                url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                method: 'POST',
                data: {
                    action: isBulk ? 'mmi_dismiss_sku_conflicts_bulk' : 'mmi_dismiss_sku_conflict',
                    profile: profile,
                    sku: skus[0],
                    skus: JSON.stringify(skus),
                    nonce: mmiProductImportData?.nonce || ''
                }
            }).done((response) => {
                if (response.success) {
                    Promise.resolve(this.refreshStats()).then(() => {
                        this.showToast(response.data?.message || 'Product(s) will no longer be imported.', 'success');
                    });
                } else {
                    if ($trigger) $trigger.prop('disabled', false).removeAttr('aria-busy').html(originalHtml);
                    this.showToast(response.data?.message || 'Failed to skip conflict.', 'error');
                }
            }).fail(() => {
                if ($trigger) $trigger.prop('disabled', false).removeAttr('aria-busy').html(originalHtml);
                this.showToast('Failed to skip conflict — network or server error.', 'error');
            });
        },

        /**
         * Small, self-dismissing fixed-position notice — this plugin has no
         * shared toast/notice utility, so a minimal one lives here rather than
         * pulling in a dependency for a single use case.
         */
        showToast: function(message, type = 'success') {
            const $toast = $(`<div class="mmi-spot-check-toast mmi-spot-check-toast--${type}">${this.escapeHtml(message)}</div>`);
            $('body').append($toast);
            requestAnimationFrame(() => $toast.addClass('mmi-is-visible'));
            setTimeout(() => {
                $toast.removeClass('mmi-is-visible');
                setTimeout(() => $toast.remove(), 300);
            }, 4000);
        },

        /**
         * "🔍" next to a row's Primary Key — fetches and shows the raw source
         * data behind that one record: the complete raw feed item, whether
         * each taxonomy field's source value currently resolves via Taxonomy
         * Mapping, and (for a CSV/TSV upload source) the original file row —
         * replacing the standard troubleshooting process of manually opening
         * the source file and searching for an identifying string.
         */
        openSourceInspectModal: function(supplier, primaryKey) {
            if (!supplier || primaryKey === undefined || primaryKey === null || primaryKey === '') {
                return;
            }

            this.ensureSourceInspectModal();

            const profile = $('#mmi-import-profile').val() || mmiProductImportData?.currentProfile || 'default';

            $('#mmi-source-inspect-modal-title').text(`Inspecting: ${primaryKey}`);
            $('#mmi-source-inspect-modal-body').html('<p class="mmi-source-inspect-loading">⏳ Loading source data…</p>');
            MMIModal.open('mmi-source-inspect-modal');

            $.ajax({
                url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                method: 'POST',
                data: {
                    action: 'mmi_inspect_source_record',
                    supplier: supplier,
                    primary_key: primaryKey,
                    profile: profile,
                    nonce: mmiProductImportData?.nonce || ''
                }
            }).done((response) => {
                if (response.success) {
                    this.renderSourceInspectModal(response.data || {});
                } else {
                    $('#mmi-source-inspect-modal-body').html(
                        `<p class="mmi-source-inspect-error">⚠ ${this.escapeHtml(response.data?.message || 'Failed to load source data.')}</p>`
                    );
                }
            }).fail(() => {
                $('#mmi-source-inspect-modal-body').html('<p class="mmi-source-inspect-error">⚠ Request failed. Please try again.</p>');
            });
        },

        closeSourceInspectModal: function() {
            MMIModal.close('mmi-source-inspect-modal');
        },

        ensureSourceInspectModal: function() {
            if ($('#mmi-source-inspect-modal').length > 0) {
                return;
            }
            $('body').append(`
                <div class="mmi-modal-backdrop mmi-source-inspect-overlay" id="mmi-source-inspect-modal" hidden role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="mmi-source-inspect-modal-title">
                    <div class="mmi-modal-box mmi-source-inspect-box">
                        <div class="mmi-modal-header">
                            <div class="mmi-modal-header-top">
                                <h3 class="mmi-modal-title" id="mmi-source-inspect-modal-title">Inspect Source Data</h3>
                                <button type="button" class="mmi-modal-close" id="mmi-source-inspect-modal-close" data-close aria-label="Close">&times;</button>
                            </div>
                        </div>
                        <div class="mmi-modal-body" id="mmi-source-inspect-modal-body"></div>
                    </div>
                </div>
            `);
        },

        renderSourceInspectModal: function(data) {
            const supplier = this.escapeHtml(data.supplier || '');
            const productLink = data.product_id
                ? `<a href="${this.escapeHtml(data.product_url)}" target="_blank" class="mmi-product-link">#${this.escapeHtml(data.product_id)}</a>`
                : '<span class="mmi-product-new">New — no matching product in the store</span>';

            let html = `
                <div class="mmi-source-inspect-summary">
                    <div><strong>Source:</strong> ${supplier} &nbsp;(<code>${this.escapeHtml(data.json_file || '')}</code>, fetched ${this.escapeHtml(data.file_modified || '')})</div>
                    <div><strong>WooCommerce product:</strong> ${productLink}</div>
                </div>
            `;

            // Taxonomy fields — the direct answer to "why did this get matched wrong".
            if (Array.isArray(data.taxonomy_fields) && data.taxonomy_fields.length > 0) {
                html += `
                    <div class="mmi-source-inspect-section">
                        <h4>Taxonomy Fields</h4>
                        <table class="mmi-source-inspect-tax-table">
                            <thead>
                                <tr><th>Taxonomy</th><th>Source Field</th><th>Raw Value</th><th>Resolution</th></tr>
                            </thead>
                            <tbody>
                                ${data.taxonomy_fields.map((f) => `
                                    <tr>
                                        <td>${this.escapeHtml(f.label)}</td>
                                        <td>${f.source_field ? `<code>${this.escapeHtml(f.source_field)}</code>` : '<em class="mmi-value-null">not configured</em>'}</td>
                                        <td>${f.raw_value ? this.escapeHtml(String(f.raw_value)) : '<em class="mmi-value-null">empty</em>'}</td>
                                        <td>${f.mapped
                                            ? `<span class="mmi-tax-resolution mmi-tax-resolution--mapped">✓ ${this.escapeHtml(f.mapped_terms || '')}</span>`
                                            : `<span class="mmi-tax-resolution mmi-tax-resolution--unmapped">✗ Unmapped</span> ${this.escapeHtml(({
                                                skipped: 'marked Skip: left as is',
                                                skip:    'left as is',
                                                match:   'an existing term with this name is used',
                                                create:  'a new term is created',
                                            })[f.unmapped_policy] || '')}`}
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }

            // Original CSV/TSV row, when the source is a CSV-backed upload —
            // the literal record a manual troubleshooting session would have
            // opened the file and searched for.
            if (data.original_row && data.original_row.fields) {
                const fields = data.original_row.fields;
                html += `
                    <div class="mmi-source-inspect-section">
                        <h4>Original ${this.escapeHtml(String(data.original_row.format).toUpperCase())} Row <span class="mmi-source-inspect-row-num">(row ${this.escapeHtml(data.original_row.row_number)} of the uploaded file)</span></h4>
                        <table class="mmi-source-inspect-tax-table">
                            <tbody>
                                ${Object.keys(fields).map((key) => `
                                    <tr>
                                        <td class="mmi-source-inspect-csv-key">${this.escapeHtml(key)}</td>
                                        <td>${fields[key] !== '' ? this.escapeHtml(String(fields[key])) : '<em class="mmi-value-null">empty</em>'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }

            // Complete raw record — the full parsed source item, exactly as the
            // pipeline reads it (promo data already merged in, same as the
            // real import/preview).
            const rawJson = JSON.stringify(data.raw_record ?? {}, null, 2);
            html += `
                <div class="mmi-source-inspect-section">
                    <div class="mmi-source-inspect-section-header">
                        <h4>Complete Raw Record</h4>
                        <button type="button" class="mmi-source-inspect-copy-btn">Copy</button>
                    </div>
                    <pre class="mmi-source-inspect-raw-json" id="mmi-source-inspect-raw-json">${this.escapeHtml(rawJson)}</pre>
                </div>
            `;

            $('#mmi-source-inspect-modal-body').html(html);
        },

        refreshStats: function() {
            // Non-Product profiles never have real preview stats to compute —
            // see deferInitialStatsUntilVisible()'s identical guard for why.
            // This second check covers the other entry points into
            // refreshStats() (mmi:importComplete, scheduleRefresh() from a
            // field-mapping/WC-filter change) that bypass the page-load path.
            if ($('#import-preview-main.mmi-generic-preview').length) { return; }

            // Guard against overlapping refreshes: if a chain is already running,
            // remember that another one was requested and run it once this one
            // finishes, rather than starting a second chain in parallel.
            if (this.isRefreshing) {
                this.refreshPending = true;
                return;
            }
            this.isRefreshing = true;

            const suppliers = this.getEnabledSuppliers();
            const $content = $('#preview-table-content');

            // Reset import mode — will be set from the first successful AJAX response.
            // Null avoids incorrectly treating the profile as create-and-update before data arrives.
            this.importMode = null;

            // Clear any previous warning messages
            $('.mmi-supplier-warning').remove();

            if (suppliers.length === 0) {
                $content.html(`
                    <div class="mmi-preview-empty">
                        <span class="dashicons dashicons-info"></span>
                        <h3>No Suppliers Enabled</h3>
                        <p>Enable at least one supplier above to see import preview</p>
                    </div>
                `);
                $('#preview-stats-summary').hide();
                this.finishRefresh();
                return;
            }
            
            if (JSON.stringify(suppliers) !== JSON.stringify(this.currentSuppliers)) {
                $content.html(`
                    <div class="mmi-preview-empty">
                        <span class="mmi-spinner"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640"><path d="M286.7 96.1C291.7 113 282.1 130.9 265.2 135.9C185.9 159.5 128.1 233 128.1 320C128.1 426 214.1 512 320.1 512C426.1 512 512.1 426 512.1 320C512.1 233.1 454.3 159.6 375 135.9C358.1 130.9 348.4 113 353.5 96.1C358.6 79.2 376.4 69.5 393.3 74.6C498.9 106.1 576 204 576 320C576 461.4 461.4 576 320 576C178.6 576 64 461.4 64 320C64 204 141.1 106.1 246.9 74.6C263.8 69.6 281.7 79.2 286.7 96.1z"/></svg></span>
                        <p>Analyzing feed data...</p>
                        <p>This may take a moment for large datasets</p>
                    </div>
                `);
            }
            
            this.currentSuppliers = suppliers;
            const previewFields = this.getPreviewFields();
            this.previewFields = previewFields;
            
            // Load suppliers sequentially rather than firing one concurrent AJAX
            // request per supplier (AGENTS.md: auto-fired requests capped at 2
            // simultaneous). Each request already reads a full multi-MB feed file
            // server-side (cached now, but still real CPU/memory per call) — an
            // unbounded fan-out here was the same shape as the documented AJAX
            // Cascade Outage. Still resolves to the same allSettled-shaped array
            // so the downstream handling below is unchanged.
            const loadSequentially = (remaining, results) => {
                if (remaining.length === 0) {
                    return Promise.resolve(results);
                }
                const [supplier, ...rest] = remaining;
                return this.loadSupplierStats(supplier, previewFields)
                    .then(value => results.concat([{ status: 'fulfilled', value }]))
                    .catch(reason => results.concat([{ status: 'rejected', reason }]))
                    .then(nextResults => loadSequentially(rest, nextResults));
            };

            // Returned (rather than fire-and-forget) so callers — e.g.
            // spotCheckImportItem() — can wait for the refreshed rows to
            // actually render before acting on the new state.
            return loadSequentially(suppliers, [])
                .then(results => {
                    // Separate successful and failed results
                    const successfulResults = [];
                    const failedSuppliers = [];
                    
                    results.forEach((result, index) => {
                        if (result.status === 'fulfilled') {
                            successfulResults.push(result.value);
                        } else {
                            failedSuppliers.push({
                                supplier: suppliers[index],
                                error: result.reason
                            });
                        }
                    });
                    
                    // Show warnings for failed suppliers but continue with successful ones
                    if (failedSuppliers.length > 0) {
                        console.warn('Some suppliers failed to load:', failedSuppliers);
                        
                        // Show warning notification
                        const failedNames = failedSuppliers.map(f => f.supplier).join(', ');
                        const failedDetails = failedSuppliers.map(f => 
                            `<li><strong>${this.escapeHtml(f.supplier)}:</strong> ${this.escapeHtml(f.error)}</li>`
                        ).join('');
                        
                        const $warning = $(`
                            <div class="notice notice-warning mmi-supplier-warning">
                                <p><strong>Warning:</strong> Failed to load data from some suppliers:</p>
                                <ul class="mmi-supplier-warning-list">
                                    ${failedDetails}
                                </ul>
                                <p class="mmi-supplier-warning-note"><em>Showing data from ${successfulResults.length} supplier${successfulResults.length !== 1 ? 's' : ''} that loaded successfully.</em></p>
                            </div>
                        `);
                        
                        $('#import-preview-main').prepend($warning);
                    }
                    
                    // Continue with successful results
                    if (successfulResults.length > 0) {
                        this.allResults = successfulResults;

                        // this.previewFields (sent to the server above, and what
                        // renderTable()/renderFieldComparison() actually read to
                        // decide which dynamic columns to draw) is the CLIENT's
                        // own DOM-derived list from getPreviewFields() — empty
                        // whenever the Field Mapping wizard step hasn't been
                        // opened yet this page load (see generate_preview()'s own
                        // docblock comment on this exact scenario). When that's
                        // the case, the server independently derives its own
                        // correct, per-supplier column list from the profile's
                        // real effective mappings and returns it as
                        // response.data.preview_fields on every result — but
                        // nothing was ever reading that back into
                        // this.previewFields, so every mapped field's column
                        // silently never rendered even though each item's own
                        // preview_fields payload had real, correct data for it.
                        // Reconcile here: union the server's per-supplier lists
                        // (a field can be enabled for one supplier and not
                        // another) by field name before rendering.
                        if (!this.previewFields || this.previewFields.length === 0) {
                            const merged = [];
                            const seen = new Set();
                            successfulResults.forEach(result => {
                                (result.data?.preview_fields || []).forEach(field => {
                                    if (!seen.has(field.name)) {
                                        seen.add(field.name);
                                        merged.push(field);
                                    }
                                });
                            });
                            this.previewFields = merged;
                        }

                        // Rebuild the filter bar's available controls before
                        // rendering — it may reset this.filters.status/
                        // taxonomyFilters back to their defaults, and the
                        // table render right after needs to see that.
                        this.updateFilterBarAvailability();
                        this.renderSummary();
                        this.renderTable();
                    } else {
                        // All suppliers failed
                        const errorDetails = failedSuppliers.map(f => 
                            `<li><strong>${this.escapeHtml(f.supplier)}:</strong> ${this.escapeHtml(f.error)}</li>`
                        ).join('');
                        
                        $content.html(`
                            <div class="mmi-preview-error">
                                <span class="dashicons dashicons-warning"></span>
                                <h3>Error Loading All Supplier Data</h3>
                                <p>All enabled suppliers failed to load. Details:</p>
                                <ul class="mmi-preview-error-list">
                                    ${errorDetails}
                                </ul>
                                <p class="mmi-preview-error-causes"><strong>Common causes:</strong></p>
                                <ul class="mmi-preview-error-list">
                                    <li>JSON feed files have syntax errors</li>
                                    <li>JSON feed files are missing or empty</li>
                                    <li>Supplier data has not been fetched yet</li>
                                </ul>
                                <p>Check the console for more details or try fetching supplier data again.</p>
                            </div>
                        `);
                    }
                })
                .finally(() => this.finishRefresh());
        },

        /**
         * Clear the in-flight guard and, if another refresh was requested
         * while this one was running, run it now (rather than while the
         * previous chain's own rendering was still in progress).
         */
        finishRefresh: function() {
            this.isRefreshing = false;
            if (this.refreshPending) {
                this.refreshPending = false;
                this.refreshStats();
            }
        },

        loadSupplierStats: function(supplier, previewFields) {
            const wcFilterParams = (typeof MMI_WcFilterBar !== 'undefined') ? MMI_WcFilterBar.getParams() : {};
            // Search is handled entirely client-side — see initWcFilterBar()'s
            // docblock. Never forward it to generate_preview(): the server-side
            // search only matches WC-native post_title/_sku, which can silently
            // disagree with this pipeline's own per-supplier primary-key match.
            delete wcFilterParams.s;
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: (window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl,
                    method: 'POST',
                    data: Object.assign({
                        action: 'mmi_generate_import_preview',
                        supplier: supplier,
                        limit: 999999, // Get all items
                        preview_fields: JSON.stringify(previewFields || []),
                        // Read from the live dropdown first so swapping profiles without a
                        // page reload sends the correct profile to the server.
                        profile: $('#mmi-import-profile').val() || mmiProductImportData?.currentProfile || 'default',
                        nonce: mmiProductImportData?.nonce || ''
                    }, wcFilterParams),
                    success: (response) => {
                        if (response.success) {
                            // Capture import_mode from the first successful response (it's the same for all suppliers)
                            if (response.data?.import_mode) {
                                MMIImportPreview.importMode = response.data.import_mode;
                            }
                            // Unlike import_mode, filterable_fields genuinely differs
                            // per supplier (each has its own feed/fields) — captured
                            // per supplier, consumed by updateSourceFieldFilterAvailability().
                            if (response.data?.filterable_fields) {
                                MMIImportPreview.filterableFieldsSchema[supplier] = response.data.filterable_fields;
                            }
                            resolve({
                                supplier: supplier,
                                data: response.data
                            });
                        } else {
                            const errorMsg = response.data?.message || 'Failed to load data';
                            console.error(`Supplier ${supplier} failed:`, errorMsg);
                            reject(errorMsg);
                        }
                    },
                    error: (xhr, status, error) => {
                        const errorMsg = `Server error (${status}): ${error}`;
                        console.error(`Supplier ${supplier} AJAX failed:`, errorMsg, xhr);
                        reject(errorMsg);
                    }
                });
            });
        },
        
        renderSummary: function() {
            const $summary = $('#preview-stats-summary');
            
            let totalInFeed = 0;
            let totalCreate = 0;
            let totalUpdate = 0;
            let totalUnchanged = 0;
            let totalConflicts = 0;
            // fully_sampled = PHP processed every item in the feed (no truncation).
            // When true, stats are exact counts and should NOT be extrapolated.
            let allFullySampled = true;
            
            this.allResults.forEach(result => {
                const data = result.data;
                totalInFeed += data.total_in_feed;
                // Trust server-side stats — PHP has already applied import-mode filters.
                // Recounting from items would bypass those filters and show wrong numbers.
                totalCreate   += data.stats?.will_create  ?? 0;
                totalUpdate   += data.stats?.will_update  ?? 0;
                totalUnchanged += data.stats?.unchanged   ?? 0;
                totalConflicts += data.stats?.sku_conflicts ?? 0;
                if ( !data.fully_sampled ) {
                    allFullySampled = false;
                }
            });
            
            const sampleSize = this.allResults.reduce((sum, r) => sum + r.data.preview_count, 0);

            // If the entire feed was processed by PHP, show exact counts without the ~ prefix.
            // Otherwise extrapolate from the sample proportionally.
            let dispCreate, dispUpdate, dispUnchanged, isExact;
            if ( allFullySampled ) {
                dispCreate    = totalCreate.toLocaleString();
                dispUpdate    = totalUpdate.toLocaleString();
                dispUnchanged = totalUnchanged.toLocaleString();
                isExact       = true;
            } else {
                dispCreate    = '~' + Math.round(totalCreate    * (totalInFeed / Math.max(sampleSize, 1))).toLocaleString();
                dispUpdate    = '~' + Math.round(totalUpdate    * (totalInFeed / Math.max(sampleSize, 1))).toLocaleString();
                dispUnchanged = '~' + Math.round(totalUnchanged * (totalInFeed / Math.max(sampleSize, 1))).toLocaleString();
                isExact       = false;
            }
            
            let totalSkipped = 0;
            let totalSkippedConflicts = 0;
            this.allResults.forEach(result => {
                totalSkipped += result.data.stats?.skipped_new_products ?? 0;
                totalSkippedConflicts += result.data.stats?.skipped_conflicts ?? 0;
            });

            const skippedHtml = (isExact && totalSkipped > 0) ? `
                    <div class="mmi-stat-skipped">
                        <div class="mmi-stat-value">${totalSkipped.toLocaleString()}</div>
                        <div class="mmi-stat-label">Skipped (New)</div>
                    </div>` : '';

            const skippedConflictsHtml = (isExact && totalSkippedConflicts > 0) ? `
                    <div class="mmi-stat-skipped" title="Confirmed duplicates of an existing product — excluded from import, will never be attempted">
                        <div class="mmi-stat-value">${totalSkippedConflicts.toLocaleString()}</div>
                        <div class="mmi-stat-label">Skipped (Conflict)</div>
                    </div>` : '';

            // SKU conflicts: create rows whose SKU already exists on another store
            // product — these will fail at import unless skipped. Shown whenever any were found.
            const conflictDisp = isExact
                ? totalConflicts.toLocaleString()
                : '~' + Math.round(totalConflicts * (totalInFeed / Math.max(sampleSize, 1))).toLocaleString();
            const conflictFilterActive = this.filters.status === 'conflict';
            const conflictHtml = (totalConflicts > 0) ? `
                    <div class="mmi-stat-conflict mmi-stat-clickable${conflictFilterActive ? ' mmi-stat-active' : ''}" role="button" tabindex="0" data-status="conflict"
                         title="${conflictFilterActive ? 'Click to clear the filter' : 'Click to show only SKU-conflict rows'} — this SKU already belongs to another existing product; creating it will fail">
                        <div class="mmi-stat-value">${conflictDisp}</div>
                        <div class="mmi-stat-label">SKU Conflicts</div>
                        <button type="button" class="mmi-clear-all-conflicts-btn" title="Permanently skip importing every SKU-conflicted product currently loaded — confirms these are genuine duplicates of existing products">Skip All</button>
                    </div>` : '';

            // "Will Create"/"Will Update"/"Unchanged" only double as click-to-filter
            // toggles when isExact — counts are approximate/extrapolated from a
            // sample otherwise, and filtering to the sampled subset wouldn't match
            // what the tile displays.
            const createFilterActive = this.filters.status === 'create';
            const updateFilterActive = this.filters.status === 'update';
            const unchangedFilterActive = this.filters.status === 'unchanged';
            const createTileAttrs = isExact
                ? ` mmi-stat-clickable${createFilterActive ? ' mmi-stat-active' : ''}" role="button" tabindex="0" data-status="create" title="${createFilterActive ? 'Click to clear the filter' : 'Click to show only rows that will be created'}`
                : '';
            const updateTileAttrs = isExact
                ? ` mmi-stat-clickable${updateFilterActive ? ' mmi-stat-active' : ''}" role="button" tabindex="0" data-status="update" title="${updateFilterActive ? 'Click to clear the filter' : 'Click to show only rows that will be updated'}`
                : '';
            const unchangedTileAttrs = isExact
                ? ` mmi-stat-clickable${unchangedFilterActive ? ' mmi-stat-active' : ''}" role="button" tabindex="0" data-status="unchanged" title="${unchangedFilterActive ? 'Click to clear the filter' : 'Click to show only unchanged rows'}`
                : '';

            const html = `
                <div class="mmi-stats-grid${isExact ? ' mmi-is-exact' : ''}">
                    <div class="mmi-stat-total">
                        <div class="mmi-stat-value">${totalInFeed.toLocaleString()}</div>
                        <div class="mmi-stat-label">Total in Feeds</div>
                    </div>
                    <div class="mmi-stat-create${createTileAttrs}">
                        <div class="mmi-stat-value">${dispCreate}</div>
                        <div class="mmi-stat-label">Will Create</div>
                    </div>
                    <div class="mmi-stat-update${updateTileAttrs}">
                        <div class="mmi-stat-value">${dispUpdate}</div>
                        <div class="mmi-stat-label">Will Update</div>
                    </div>
                    <div class="mmi-stat-unchanged${unchangedTileAttrs}">
                        <div class="mmi-stat-value">${dispUnchanged}</div>
                        <div class="mmi-stat-label">Unchanged</div>
                    </div>
                    ${skippedHtml}
                    ${skippedConflictsHtml}
                    ${conflictHtml}
                    <div class="mmi-stat-sample">
                        <div class="mmi-stat-value">${isExact ? '✓' : sampleSize}</div>
                        <div class="mmi-stat-label">${isExact ? 'Full Feed' : 'Sample Size'}</div>
                    </div>
                </div>
            `;
            
            $summary.html(html).addClass('active');

            // Opportunistic cache refresh: this full (isExact) scan just
            // computed, per-supplier, the exact same will_create/will_update
            // numbers MMI_Import_Preview::compute_profile_pending_stats()
            // would — relay the sum back so the Import Profiles grid's
            // "Pending" column (a separate cached snapshot, refreshed only by
            // a manual click or an optional cron toggle) doesn't keep
            // showing a stale number just because nobody happened to hit
            // "Refresh now" after visiting Review & Compare. Server-side
            // rejects this unless the reported source list exactly matches
            // the profile's real enabled sources, so a partial/failed-supplier
            // scan can never overwrite a good cached count with an incomplete one.
            //
            // Also gated on the WC filter bar being at its default (empty)
            // state — compute_profile_pending_stats() always calls
            // generate_preview() with an empty $wc_filters array, so a scan
            // narrowed by e.g. "Out of Stock" or a price range answers a
            // DIFFERENT, smaller question than the cache is supposed to
            // represent; reporting that back would silently understate real
            // pending work (and could wrongly convince the "skip if no new
            // data" schedule toggle there's nothing to do).
            const wcFilterParams = (typeof MMI_WcFilterBar !== 'undefined') ? MMI_WcFilterBar.getParams() : {};
            const noFiltersActive = Object.values(wcFilterParams).every(v => !v);
            if (isExact && noFiltersActive) {
                this.reportLivePendingStats(totalCreate, totalUpdate, totalInFeed);
            }
        },

        /**
         * Fire-and-forget: see the call site in renderSummary() above for why.
         * Never surfaces an error to the user — a rejected/failed report just
         * leaves the grid's cached number exactly as stale as it already was,
         * no worse off than before this existed.
         */
        reportLivePendingStats: function(willCreate, willUpdate, totalInFeed) {
            const profile = $('#mmi-import-profile').val() || mmiProductImportData?.currentProfile || 'default';
            const sources = this.allResults.map(r => r.supplier);
            $.post((window.mmiGlobal && window.mmiGlobal.ajaxUrl) || window.ajaxurl, {
                action: 'mmi_pipeline_report_live_pending_stats',
                nonce: mmiProductImportData?.nonce || '',
                profile_id: profile,
                sources: JSON.stringify(sources),
                will_create: willCreate,
                will_update: willUpdate,
                total_in_feed: totalInFeed
            });
        },

        // Every loaded item across every supplier, flattened into one array
        // with its supplier attached — the raw, unfiltered dataset every
        // other computation (stats, filters, the table itself) starts from.
        flattenAllResults: function() {
            const allItems = [];
            this.allResults.forEach(result => {
                result.data.items.forEach(item => {
                    allItems.push({
                        ...item,
                        supplier: result.supplier
                    });
                });
            });
            return allItems;
        },

        /**
         * Rebuilds the Review filter bar so it only offers a filter that
         * could actually match something in the CURRENT preview, instead of
         * a fixed set assumed up front regardless of what's loaded. A
         * "Create Only — new products" profile's rows are all brand-new —
         * none of them have a WooCommerce product yet, so Stock/Image/Price
         * (real facts about an existing product) and Categories (real terms
         * assigned to one) can never match anything, and the Status filter's
         * "Changed"/"Unchanged" pills are equally meaningless. Previously
         * every one of those rendered unconditionally either way.
         *
         * Driven entirely by the real loaded item data (item.action,
         * item.current_terms), not by the profile's stored import_mode/
         * product_scope config — a profile can have product_scope='new_only'
         * with import_mode still saved as 'create-and-update' from before
         * that axis existed (see the 2026-08-30 "Import Preview Ignored
         * product_scope" Incident History entry), so config alone isn't a
         * reliable signal for what the loaded rows actually are.
         *
         * Called once per real data load (see the call site in
         * refreshStats()) — never from renderTable() itself, which runs on
         * every filter/sort/page click and would otherwise wipe out
         * whatever taxonomy checkboxes the user just checked.
         */
        updateFilterBarAvailability: function() {
            const items = this.flattenAllResults();

            const hasCreateRows    = items.some(i => i.action === 'create');
            const hasUpdateRows    = items.some(i => i.action === 'update');
            const hasChangedRows   = items.some(i => i.action === 'update' && i.change_count > 0);
            const hasUnchangedRows = items.some(i => i.action === 'update' && !(i.change_count > 0));

            // Every loaded row is a create (a "Create Only — new products"
            // profile, or a filtered-to-create-only view) — read by
            // renderFieldComparison() to skip the "NEW:" field label. It's
            // implied by every field in every row once there's nothing else
            // on screen to distinguish it from, and dropping it saves a line
            // of height per field cell.
            this.allRowsAreCreates = hasCreateRows && !hasUpdateRows;

            this.updateStatusFilterAvailability(hasCreateRows, hasChangedRows, hasUnchangedRows);
            // Coarse gate first (hide/show the whole native-facts group by
            // hasUpdateRows), THEN the precise per-control functions — each of
            // those narrows further based on real loaded data (e.g. Categories
            // stays hidden even when hasUpdateRows is true if no matched
            // product actually carries a term) and runs last, so its result is
            // what's actually left on screen once this function returns.
            this.updateWcFactFilterAvailability(hasUpdateRows);
            this.updateAuthorFilterAvailability(items);
            this.updateOrdersFilterAvailability(items);
            this.updateTaxonomyFilterAvailability(items);
            this.updateSourceFieldFilterAvailability(items, hasCreateRows);
        },

        updateStatusFilterAvailability: function(hasCreateRows, hasChangedRows, hasUnchangedRows) {
            $('#mmi-filter-status-create').toggleClass('mmi-is-hidden', !hasCreateRows);
            $('.mmi-filter-status-btn[data-status="update"]').toggleClass('mmi-is-hidden', !hasChangedRows);
            $('.mmi-filter-status-btn[data-status="unchanged"]').toggleClass('mmi-is-hidden', !hasUnchangedRows);

            const active = this.filters.status;
            const stillValid = active === 'all'
                || (active === 'create' && hasCreateRows)
                || (active === 'update' && hasChangedRows)
                || (active === 'unchanged' && hasUnchangedRows);

            if (!stillValid) {
                this.filters.status = 'all';
                $('.mmi-filter-status-btn').removeClass('active');
                $('.mmi-filter-status-btn[data-status="all"]').addClass('active');
            }
        },

        // Stock/Image/Price/Author/Orders/Categories/Coupon are all facts
        // about an EXISTING, already-matched WooCommerce product — they only
        // ever affect 'update' rows (a 'create' row has no WC product yet to
        // check any of them against). Every one of those controls carries the
        // shared "mmi-native-filter-group" class (see pipeline-step-2-review.php)
        // so they can be toggled as one set here, rather than one selector per
        // control the way this function used to before Author/Orders/Coupon
        // were added — Categories/Author/Orders each additionally narrow this
        // further via their own dynamic-availability function, called right
        // after this one in updateFilterBarAvailability().
        updateWcFactFilterAvailability: function(hasUpdateRows) {
            $('.mmi-native-filter-group').toggleClass('mmi-is-hidden', !hasUpdateRows);

            if (!hasUpdateRows) {
                $('#wc-filter-stock').val('');
                $('#wc-filter-featured-image').val('');
                $('#wc-filter-price-min').val('').removeClass('mmi-price-active');
                $('#wc-filter-price-max').val('').removeClass('mmi-price-active');
                $('#mmi-filter-coupon').val('');
                $('#mmi-filter-orders-since').val('');
                this.filters.ordersSince = '';
                $('[data-filter="wc-filter-stock"] .mmi-pill, [data-filter="wc-filter-featured-image"] .mmi-pill, [data-filter="mmi-filter-coupon"] .mmi-pill').removeClass('active');
            }
        },

        // Builds the "Author" filter group entirely from post_author data
        // actually present on the loaded rows (item.author_id/author_name —
        // real WP post_author on an existing matched product, see
        // class-import-preview.php's batch author map) — mirrors
        // updateTaxonomyFilterAvailability()'s own dynamic-option-building
        // approach exactly, just for a single flat list instead of one
        // group per taxonomy.
        updateAuthorFilterAvailability: function(items) {
            const authors = new Map(); // author_id -> display name
            items.forEach(item => {
                if (item.author_id && item.author_name) {
                    authors.set(item.author_id, item.author_name);
                }
            });

            const $container = $('#mmi-filter-author-container');
            const $inline = $('#mmi-filter-author-options');

            if (authors.size === 0) {
                $container.addClass('mmi-is-hidden');
                $inline.empty();
                this.filters.authorIds = {};
                return;
            }

            const sorted = Array.from(authors.entries()).sort((a, b) => a[1].localeCompare(b[1]));
            const html = sorted.map(([id, name]) => `
                    <label class="mmi-taxonomy-filter-option">
                        <input type="checkbox" class="mmi-author-filter-checkbox" value="${this.escapeHtml(id)}">
                        ${this.escapeHtml(name)}
                    </label>`).join('');

            $inline.html(html);
            $container.removeClass('mmi-is-hidden');

            // Freshly-built, unchecked controls — a selection against the
            // previous set no longer corresponds to anything rendered.
            this.filters.authorIds = {};
        },

        // Turns a WC order status slug ('wc-completed') into a readable label
        // ('Completed') without needing wc_get_order_statuses() localized to
        // JS — good enough for this filter's purpose since it's built from
        // whichever statuses actually occur among the loaded rows' matched
        // products, not a fixed list needing pixel-perfect WC copy.
        humanizeOrderStatus: function(slug) {
            return String(slug).replace(/^wc-/, '').split(/[-_]/)
                .map(w => w.charAt(0).toUpperCase() + w.slice(1))
                .join(' ');
        },

        // Builds the "Orders" filter group (status checkboxes + an optional
        // "since" date) from order-status/date data actually present on the
        // loaded rows (item.order_statuses/last_order_date — see
        // class-import-preview.php's batch order map, HPOS wc_order_product_lookup
        // joined against wc_orders.status). Same dynamic-option-building
        // pattern as Categories/Author — only offers a status that could
        // actually match something currently loaded.
        updateOrdersFilterAvailability: function(items) {
            const statuses = new Map(); // slug -> label
            items.forEach(item => {
                (item.order_statuses || []).forEach(status => {
                    if (!statuses.has(status)) {
                        statuses.set(status, this.humanizeOrderStatus(status));
                    }
                });
            });

            const $container = $('#mmi-filter-orders-container');
            const $inline = $('#mmi-filter-orders-options');

            if (statuses.size === 0) {
                $container.addClass('mmi-is-hidden');
                $inline.empty();
                this.filters.orderStatuses = {};
                return;
            }

            const sorted = Array.from(statuses.entries()).sort((a, b) => a[1].localeCompare(b[1]));
            const html = sorted.map(([slug, label]) => `
                    <label class="mmi-taxonomy-filter-option">
                        <input type="checkbox" class="mmi-order-status-filter-checkbox" value="${this.escapeHtml(slug)}">
                        ${this.escapeHtml(label)}
                    </label>`).join('');

            $inline.html(html);
            $container.removeClass('mmi-is-hidden');
            this.filters.orderStatuses = {};
        },

        // Builds the "Create Only" source-data filter group from each enabled
        // supplier's calculated filterable-fields schema (this.filterableFieldsSchema,
        // captured per supplier from generate_preview()'s 'filterable_fields'
        // response key — see MMI_Pipeline_Config_Validator::calculate_filterable_fields())
        // combined with whichever values actually appear in item.source_filter_values
        // across the loaded rows — same "only offer what could actually match
        // something currently loaded" principle as Categories/Author/Orders.
        // Shown only when hasCreateRows (a brand-new product has no WC
        // identity yet, so this is the only real way to filter it).
        updateSourceFieldFilterAvailability: function(items, hasCreateRows) {
            const $container = $('#mmi-source-filters-group');
            const $inline = $('#mmi-source-field-filters');

            if (!hasCreateRows) {
                $container.addClass('mmi-is-hidden');
                $inline.empty();
                this.filters.sourceFieldFilters = {};
                this.filters.sourceFieldSearch = {};
                return;
            }

            // field path -> { label, high_cardinality, values: Map<value, true> }
            // Only label/high_cardinality are taken from the calculated schema —
            // its own static 'values' array (present whenever a field isn't
            // high_cardinality) is deliberately NOT merged in here: this group's
            // options are always built live from whichever values actually
            // appear in the currently-loaded rows (same "only offer what could
            // actually match something loaded" principle as Categories/Author/
            // Orders), and Object.assign()'ing the schema's plain array directly
            // into this 'values' key would silently replace the Map below with
            // an Array, which has no .set() method for the loop further down.
            const fields = new Map();
            Object.values(this.filterableFieldsSchema || {}).forEach(supplierFields => {
                Object.keys(supplierFields || {}).forEach(path => {
                    if (!fields.has(path)) {
                        const schemaField = supplierFields[path] || {};
                        fields.set(path, {
                            label: schemaField.label,
                            high_cardinality: schemaField.high_cardinality,
                            values: new Map()
                        });
                    }
                });
            });

            items.forEach(item => {
                const vals = item.source_filter_values || {};
                Object.keys(vals).forEach(path => {
                    if (fields.has(path)) {
                        fields.get(path).values.set(vals[path], true);
                    }
                });
            });

            const activeFields = Array.from(fields.entries()).filter(([, f]) => f.values.size > 0);

            if (activeFields.length === 0) {
                $container.addClass('mmi-is-hidden');
                $inline.empty();
                this.filters.sourceFieldFilters = {};
                this.filters.sourceFieldSearch = {};
                return;
            }

            activeFields.sort((a, b) => (a[1].label || a[0]).localeCompare(b[1].label || b[0]));

            const html = activeFields.map(([path, field]) => {
                const label = field.label || path;
                let optionsHtml;
                if (field.high_cardinality) {
                    optionsHtml = `
                            <input type="text" class="mmi-source-field-filter-search mmi-filter-input" data-source-field="${this.escapeHtml(path)}" placeholder="Type to filter ${this.escapeHtml(label)}…" autocomplete="off">
                            <span class="description mmi-taxonomy-filter-hint">${field.values.size} distinct values in this preview — too many to list, type to filter.</span>`;
                } else {
                    const sortedVals = Array.from(field.values.keys()).sort();
                    optionsHtml = sortedVals.map(v => `
                            <label class="mmi-taxonomy-filter-option">
                                <input type="checkbox" class="mmi-source-field-filter-checkbox" data-source-field="${this.escapeHtml(path)}" value="${this.escapeHtml(v)}">
                                ${this.escapeHtml(v)}
                            </label>`).join('');
                }

                return `
                    <details class="mmi-taxonomy-filter-details" data-source-field="${this.escapeHtml(path)}">
                        <summary>${this.escapeHtml(label)}</summary>
                        <div class="mmi-taxonomy-filter-options">${optionsHtml}</div>
                    </details>`;
            }).join('');

            $inline.html(html);
            $container.removeClass('mmi-is-hidden');

            // Freshly-built, unchecked/empty controls — a selection against
            // the previous set no longer corresponds to anything rendered.
            this.filters.sourceFieldFilters = {};
            this.filters.sourceFieldSearch = {};
        },

        // Builds the "Categories" filter group entirely from taxonomy/term
        // data actually present on the loaded rows (item.current_terms —
        // real assigned terms on an existing matched product, see
        // class-import-preview.php) instead of every show_ui taxonomy the
        // site happens to have registered. Works for whatever taxonomies
        // this particular preview's matched products actually carry — not
        // hardcoded to "Category"/"Brand" or any fixed list, since a
        // taxonomy-bearing post type's real taxonomy set varies by what's
        // being imported.
        updateTaxonomyFilterAvailability: function(items) {
            // taxonomy -> Map<lowercased slug, display name>
            const taxonomies = new Map();
            items.forEach(item => {
                const currentTerms = item.current_terms || {};
                Object.keys(currentTerms).forEach(taxonomy => {
                    let terms = taxonomies.get(taxonomy);
                    if (!terms) {
                        terms = new Map();
                        taxonomies.set(taxonomy, terms);
                    }
                    (currentTerms[taxonomy] || []).forEach(term => {
                        if (term && term.slug) {
                            terms.set(String(term.slug).toLowerCase(), term.name || term.slug);
                        }
                    });
                });
            });

            const $container = $('#taxonomy-field-filters-container');
            const $inline = $('#taxonomy-field-filters');

            // Nothing in this preview has a real term assigned to it at all
            // (e.g. every row is a brand-new product) — there is no
            // meaningful "Categories" filter to offer.
            if (taxonomies.size === 0) {
                $container.addClass('mmi-is-hidden');
                $inline.empty();
                this.filters.taxonomyFilters = {};
                return;
            }

            const taxonomyLabels = (window.mmiProductImportData && window.mmiProductImportData.taxonomyLabels) || {};
            // Pure DOM-size safety valve, not a meaningful UX cap — unlike
            // the old static render (which had to cap against a taxonomy's
            // entire site-wide term list, sometimes 1,000+ terms), this list
            // is already bounded by how many DISTINCT terms are actually
            // assigned across the loaded preview rows, which in practice is
            // rarely more than a few dozen.
            const MAX_OPTIONS = 500;

            const sortedTaxonomies = Array.from(taxonomies.keys()).sort((a, b) => {
                const labelA = taxonomyLabels[a] || a;
                const labelB = taxonomyLabels[b] || b;
                return labelA.localeCompare(labelB);
            });

            let html = '';
            sortedTaxonomies.forEach(taxonomy => {
                const termsMap = taxonomies.get(taxonomy);
                const label = taxonomyLabels[taxonomy] || taxonomy;
                const sortedTerms = Array.from(termsMap.entries()).sort((a, b) => a[1].localeCompare(b[1]));
                const overLimit = sortedTerms.length > MAX_OPTIONS;
                const visibleTerms = overLimit ? sortedTerms.slice(0, MAX_OPTIONS) : sortedTerms;

                html += `
                    <details class="mmi-taxonomy-filter-details" data-taxonomy="${this.escapeHtml(taxonomy)}" data-label="${this.escapeHtml(label)}">
                        <summary>${this.escapeHtml(label)}</summary>
                        <div class="mmi-taxonomy-filter-options">`;

                if (overLimit) {
                    html += `
                            <select class="mmi-taxonomy-filter-select" data-taxonomy="${this.escapeHtml(taxonomy)}" multiple size="8">
                                ${visibleTerms.map(([slug, name]) => `<option value="${this.escapeHtml(slug)}">${this.escapeHtml(name)}</option>`).join('')}
                            </select>
                            <span class="description mmi-taxonomy-filter-hint">Showing ${visibleTerms.length} of ${sortedTerms.length} values found in this preview.</span>`;
                } else {
                    html += visibleTerms.map(([slug, name]) => `
                            <label class="mmi-taxonomy-filter-option">
                                <input type="checkbox" class="mmi-taxonomy-filter-checkbox" data-taxonomy="${this.escapeHtml(taxonomy)}" value="${this.escapeHtml(slug)}">
                                ${this.escapeHtml(name)}
                            </label>`).join('');
                }

                html += `
                        </div>
                    </details>`;
            });

            $inline.html(html);
            $container.removeClass('mmi-is-hidden');

            // The checkboxes above are freshly built and unchecked — whatever
            // was selected against the previous set of options no longer
            // corresponds to a rendered control, so keeping it would filter
            // rows out with nothing left on screen to un-check.
            this.filters.taxonomyFilters = {};
        },

        renderTable: function() {
            const $content = $('#preview-table-content');

            let allItems = this.flattenAllResults();

            // Same for the SKU-conflict filter: once every conflict on this page
            // has been skipped (e.g. via "Skip All"), none remain to match it —
            // fall back to "all" instead of showing an empty table.
            if (this.filters.status === 'conflict' && !allItems.some(item => item.sku_conflict)) {
                this.filters.status = 'all';
                $('.mmi-stat-conflict').removeClass('mmi-stat-active');
                $('.mmi-filter-status-btn').removeClass('active');
                $('.mmi-filter-status-btn[data-status="all"]').addClass('active');
            }

            // Apply all filters
            allItems = this.applyFilters(allItems);

            // Apply sorting
            allItems.sort((a, b) => {
                let aVal, bVal;
                
                switch(this.currentSort.column) {
                    case 'id':
                        aVal = a.product_id || 0;
                        bVal = b.product_id || 0;
                        break;
                    case 'supplier':
                        aVal = a.supplier;
                        bVal = b.supplier;
                        break;
                    case 'status':
                        aVal = a.action + (a.change_count || 0);
                        bVal = b.action + (b.change_count || 0);
                        break;
                    case 'name':
                        aVal = a.product_name || '';
                        bVal = b.product_name || '';
                        break;
                    case 'primary_key':
                        aVal = a.primary_key || '';
                        bVal = b.primary_key || '';
                        break;
                    case 'changes':
                        aVal = a.change_count || 0;
                        bVal = b.change_count || 0;
                        break;
                    default:
                        aVal = a[this.currentSort.column] || '';
                        bVal = b[this.currentSort.column] || '';
                }
                
                if (typeof aVal === 'string') {
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }
                
                if (aVal < bVal) return this.currentSort.direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return this.currentSort.direction === 'asc' ? 1 : -1;
                return 0;
            });
            
            if (allItems.length === 0) {
                $content.html(`
                    <div class="mmi-preview-empty">
                        <span class="dashicons dashicons-warning"></span>
                        <h3>No Items to Display</h3>
                        <p>Try selecting a different supplier filter</p>
                    </div>
                `);
                return;
            }
            
            // Apply pagination AFTER filtering and sorting
            const paginatedItems = this.paginateItems(allItems);
            const totalFiltered = allItems.length;
            
            const getSortIcon = (column) => {
                if (this.currentSort.column === column) {
                    return this.currentSort.direction === 'asc' ? ' ▲' : ' ▼';
                }
                return '';
            };
            
            let html = `
                ${this.renderPaginationControls()}
                <div class="mmi-table-container">
                    <table class="mmi-resizable-table mmi-uniform-table">
                        <thead>
                            <tr>
                                <th class="mmi-col-id mmi-sortable-header" data-column="id">
                                    ID${getSortIcon('id')}
                                    <div class="col-resizer" data-col="0"></div>
                                </th>
                                <th class="mmi-col-source mmi-sortable-header" data-column="supplier">
                                    Source${getSortIcon('supplier')}
                                    <div class="col-resizer" data-col="1"></div>
                                </th>
                                <th class="mmi-col-status mmi-sortable-header" data-column="status">
                                    Status${getSortIcon('status')}
                                    <div class="col-resizer" data-col="2"></div>
                                </th>
                                <th class="mmi-col-name mmi-sortable-header" data-column="name">
                                    Product Name${getSortIcon('name')}
                                    <div class="col-resizer" data-col="3"></div>
                                </th>
                                <th class="mmi-col-key mmi-sortable-header" data-column="primary_key">
                                    Primary Key${getSortIcon('primary_key')}
                                    <div class="col-resizer" data-col="4"></div>
                                </th>`;
            
            // Add dynamic field columns
            let colIndex = 5;
            if (this.previewFields && this.previewFields.length > 0) {
                this.previewFields.forEach((field) => {
                    html += `
                                <th class="mmi-col-field mmi-sortable-header" data-column="${this.escapeHtml(field.name)}">
                                    ${this.escapeHtml(field.label)}${getSortIcon(field.name)}
                                    <div class="col-resizer" data-col="${colIndex}"></div>
                                </th>`;
                    colIndex++;
                });
            }
            
            html += `
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            paginatedItems.forEach((item, index) => {
                const supplierClass = `mmi-badge-supplier-${this.escapeHtml(item.supplier)}`;
                const supplierRowClass = `mmi-row-${this.escapeHtml(item.supplier)}`;
                
                let statusBadge, statusClass, rowClass, statusTitle = '';
                if (item.sku_conflict) {
                    const c = item.sku_conflict;
                    statusBadge = '⚠ SKU CONFLICT';
                    statusClass = 'mmi-badge-conflict';
                    rowClass = 'mmi-row-conflict';
                    statusTitle = `SKU "${c.sku}" already belongs to product #${c.product_id}${c.product_name ? ' (' + c.product_name + ')' : ''} — creating this product will fail with "Invalid or duplicated SKU"`
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
                } else if (item.action === 'create') {
                    statusBadge = '+ CREATE';
                    statusClass = 'mmi-badge-create';
                    rowClass = 'mmi-row-create';
                } else if (item.change_count > 0) {
                    statusBadge = `↻ UPDATE (${item.change_count})`;
                    statusClass = 'mmi-badge-update';
                    rowClass = 'mmi-row-update';
                } else {
                    statusBadge = '✓ UP TO DATE';
                    statusClass = 'mmi-badge-unchanged';
                    rowClass = 'mmi-row-unchanged';
                }

                // The "UPDATE (n)" and "+ CREATE" badges double as a spot-check
                // action button — clicking one immediately imports (creates or
                // updates, matching the badge's own action) just THIS one
                // product using the profile's live field mappings, so the user can
                // verify import settings work correctly on a real record without
                // waiting for/running a full batch import. See the delegated
                // '.mmi-badge-clickable' click handler in bindEvents().
                const isSpotCheckable = statusClass === 'mmi-badge-update' || statusClass === 'mmi-badge-create';
                const badgeAttrs = isSpotCheckable
                    ? ` data-supplier="${this.escapeHtml(item.supplier)}" data-primary-key="${this.escapeHtml(item.primary_key)}" role="button" tabindex="0"`
                    : '';
                const badgeTitle = statusTitle
                    ? ` title="${statusTitle}"`
                    : (isSpotCheckable ? ' title="Click to import this product now using the current field mappings"' : '');
                const badgeClass = `mmi-badge mmi-badge-truncate mmi-badge-status ${statusClass}${isSpotCheckable ? ' mmi-badge-clickable' : ''}`;
                
                // A primary-key collision (this raw value matches MORE than one
                // live WC product — see class-import-preview.php's own
                // $pk_collision computation) is a fact about the value itself,
                // independent of $action/product_id above — shown regardless of
                // which status badge this row otherwise gets.
                let pkCollisionBadge = '';
                if (item.pk_collision) {
                    const otherIds = item.pk_collision.products
                        .map(p => `#${p.product_id}${p.product_name ? ' (' + p.product_name + ')' : ''}`)
                        .join(', ');
                    const title = `This value matches ${item.pk_collision.products.length} WooCommerce products: ${otherIds} — only one is ever reachable by an import run. Click to review in Duplicates.`
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
                    pkCollisionBadge = `<span class="mmi-badge mmi-badge-truncate mmi-badge-pk-collision mmi-pk-collision-trigger" data-supplier="${this.escapeHtml(item.supplier)}" data-pk-value="${this.escapeHtml(item.pk_collision.value)}" role="button" tabindex="0" title="${title}">⚠ ${item.pk_collision.products.length} products share this key</span>`;
                }

                html += `
                    <tr class="${rowClass} ${supplierRowClass}">
                        <td>
                            ${item.product_id
                                ? `<a href="${this.escapeHtml(item.product_url)}" target="_blank" class="mmi-product-link">#${this.escapeHtml(item.product_id)}</a>`
                                : (item.sku_conflict && item.sku_conflict.product_url
                                    ? `<a href="${this.escapeHtml(item.sku_conflict.product_url)}" target="_blank" class="mmi-product-link mmi-conflict-link" title="Existing product using this SKU">#${this.escapeHtml(item.sku_conflict.product_id)} ⚠</a>
                                       <div class="mmi-conflict-name">${this.escapeHtml(item.sku_conflict.product_name || '(untitled)')}</div>`
                                    : '<span class="mmi-product-new">New</span>')}
                            ${pkCollisionBadge}
                        </td>
                        <td>
                            <span class="mmi-badge mmi-badge-truncate ${supplierClass}" title="${this.escapeHtml(item.supplier)}">${this.escapeHtml(item.supplier)}</span>
                        </td>
                        <td>
                            <span class="${badgeClass}"${badgeTitle}${badgeAttrs}>${statusBadge}</span>
                            ${item.sku_conflict
                                ? `<button type="button" class="mmi-conflict-clear-btn" data-sku="${this.escapeHtml(item.sku_conflict.sku)}" title="Confirm this is a genuine duplicate and permanently skip importing it — it will no longer be attempted">🚫 Skip Import</button>`
                                : ''}
                        </td>
                        <td>
                            <strong class="mmi-product-name">${this.escapeHtml(item.product_name)}</strong>
                        </td>
                        <td>
                            <code class="mmi-primary-key">${this.escapeHtml(item.primary_key)}</code>
                            <button type="button" class="mmi-inspect-source-btn" data-supplier="${this.escapeHtml(item.supplier)}" data-primary-key="${this.escapeHtml(item.primary_key)}" title="Inspect the raw source data behind this record">🔍</button>
                        </td>`;
                
                // Add dynamic field columns
                if (this.previewFields && this.previewFields.length > 0) {
                    this.previewFields.forEach((field) => {
                        html += `<td>${this.renderFieldComparison(item, field.name)}</td>`;
                    });
                }
                
                html += `
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
                ${this.renderPaginationControls()}
            `;
            
            // Clear the pending-refresh indicator before swapping in new HTML
            // (the class lives on the container itself, not inside it).
            $content.removeClass('mmi-preview-stale');
            $('#mmi-refresh-notice').hide();
            $content.html(html);
            this.updateEditWarningBanner();
        },
        
        renderFieldComparison: function(item, fieldName) {
            if (!item.preview_fields || !item.preview_fields[fieldName]) {
                return '<span class="mmi-value-null">—</span>';
            }

            const fieldData = item.preview_fields[fieldName];
            const currentVal = this.formatValue(fieldData.current);
            // A plain hover title carrying the raw (untruncated) value — the
            // cell itself truncates with an ellipsis at whatever width the
            // column currently is (see .mmi-field-val in import-preview.css),
            // so this is the only way to read a long value without resizing
            // the column first.
            const currentTitle = (fieldData.current === null || fieldData.current === undefined || fieldData.current === '')
                ? ''
                : ` title="${this.escapeHtml(String(fieldData.current))}"`;
            let sourceVal = fieldData.source;

            // Check if this field has been edited
            const hasEdit = this.hasEdit(item.supplier, item.primary_key, fieldName);
            const editedValue = hasEdit ? this.getEditedValue(item.supplier, item.primary_key, fieldName) : null;
            const editKey = `${item.supplier}_${item.primary_key}_${fieldName}`;

            // Use edited value if available
            const displaySourceVal = hasEdit ? editedValue : sourceVal;
            const formattedSourceVal = this.formatValue(displaySourceVal);

            // Server-reported changed flag, overridden client-side for semantic
            // equivalence (e.g. '49' vs '49.00', null vs '', etc.).
            const isChanged = fieldData.changed && !this.semanticRawEqual(fieldData.current, displaySourceVal);

            // A field can be mapped for one supplier and not another (e.g.
            // Xchange maps a field, SkuPort doesn't) — this column still
            // exists for every row since another supplier needs it, but this
            // particular item's supplier has nothing configured for it.
            // class-import-preview.php's own 'enabled' key mirrors exactly
            // what the real importer checks (class-product-import-worker.php)
            // before ever touching a field, so there's genuinely nothing
            // pending to compare here — treat it the same as no data at all.
            if (fieldData.enabled === false) {
                return '<span class="mmi-value-null">—</span>';
            }

            // Not in Taxonomy Mapping and the field is set to leave terms as
            // they are: the import writes nothing here (class-import-preview.php).
            if (fieldData.unmapped !== undefined && fieldData.unmapped !== null && fieldData.unmapped !== '') {
                const keptVal = (fieldData.current === null || fieldData.current === undefined || fieldData.current === '')
                    ? '<span class="mmi-value-null">none</span>'
                    : this.escapeHtml(String(fieldData.current));
                return `
                    <div class="mmi-field-comparison">
                        <div class="mmi-field-value">
                            <div class="mmi-field-val" title="${this.escapeHtml(String(fieldData.unmapped))}: not in Taxonomy Mapping, left as is. Map it in the Taxonomy Mapping tab.">${keptVal}</div>
                            <span class="mmi-tax-resolution mmi-tax-resolution--unmapped">✗ Unmapped: ${this.escapeHtml(String(fieldData.unmapped))}</span>
                        </div>
                    </div>
                `;
            }

            // Build editable source value HTML. The title carries the raw
            // value ahead of the click-to-edit hint for the same reason as
            // currentTitle above — this box truncates with an ellipsis too.
            // NOTE: Edits here are PREVIEW ONLY — they do not persist and will
            // not affect what the import engine writes to the database.
            const sourceTitlePrefix = (sourceVal === null || sourceVal === undefined || sourceVal === '')
                ? ''
                : `${this.escapeHtml(String(sourceVal))} — `;
            const editableSourceHtml = `
                <div class="mmi-field-val mmi-field-val-editable ${hasEdit ? 'mmi-edited' : ''}"
                     data-supplier="${this.escapeHtml(item.supplier)}"
                     data-primary-key="${this.escapeHtml(item.primary_key)}"
                     data-field-name="${this.escapeHtml(fieldName)}"
                     data-original-value="${this.escapeHtml(sourceVal)}"
                     title="${sourceTitlePrefix}Click to edit (preview only — not saved to import)">
                    ${formattedSourceVal}
                    ${hasEdit ? '<span class="mmi-edit-indicator" title="Preview edit — not saved to import">✏️</span>' : ''}
                </div>
                ${hasEdit ? `<button type="button" class="button-link mmi-edit-revert-btn" data-edit-key="${this.escapeHtml(editKey)}" title="Discard this preview edit">↩ Discard</button>` : ''}
            `;

            if (item.action === 'create') {
                // For new products, just show the editable source value.
                return `
                    <div class="mmi-field-comparison">
                        <div class="mmi-field-value mmi-field-new">
                            ${editableSourceHtml}
                        </div>
                    </div>
                `;
            } else if (isChanged || hasEdit) {
                // For updates with changes or edits, show side-by-side comparison —
                // the arrow alone conveys "current → source", no text label needed.
                return `
                    <div class="mmi-field-comparison mmi-field-changed">
                        <div class="mmi-field-value mmi-field-current">
                            <div class="mmi-field-val"${currentTitle}>${currentVal}</div>
                        </div>
                        <div class="mmi-field-arrow">→</div>
                        <div class="mmi-field-value mmi-field-source">
                            ${editableSourceHtml}
                        </div>
                    </div>
                `;
            } else {
                // No change — values are semantically equal.
                const rawCurrent = fieldData.current;
                const rawSource  = displaySourceVal;

                // Both null/empty → nothing meaningful to compare; suppress the cell.
                if ( (rawCurrent === null || rawCurrent === undefined || rawCurrent === '') &&
                     (rawSource  === null || rawSource  === undefined || rawSource  === '') ) {
                    return '<span class="mmi-value-null">—</span>';
                }

                // Normalize source for display so '49.00' shows as '49' when
                // current is '49' — avoids a confusing visual difference.
                const normSource          = this.semanticNormalizeForDisplay(rawSource, rawCurrent);
                const normFormattedSource = this.formatValue(normSource);
                const matchTitlePrefix = (normSource === null || normSource === undefined || normSource === '')
                    ? ''
                    : `${this.escapeHtml(String(normSource))} — `;

                const matchSourceHtml = `
                    <div class="mmi-field-val mmi-field-val-editable ${hasEdit ? 'mmi-edited' : ''}"
                         data-supplier="${this.escapeHtml(item.supplier)}"
                         data-primary-key="${this.escapeHtml(item.primary_key)}"
                         data-field-name="${this.escapeHtml(fieldName)}"
                         data-original-value="${this.escapeHtml(sourceVal)}"
                         title="${matchTitlePrefix}Click to edit (preview only — not saved to import)">
                        ${normFormattedSource}
                        ${hasEdit ? '<span class="mmi-edit-indicator" title="Preview edit — not saved to import">✏️</span>' : ''}
                    </div>
                    ${hasEdit ? `<button type="button" class="button-link mmi-edit-revert-btn" data-edit-key="${this.escapeHtml(editKey)}" title="Discard this preview edit">↩ Discard</button>` : ''}
                `;

                // No text label here either — the checkmark arrow already
                // conveys "matches source, no change" on its own.
                return `
                    <div class="mmi-field-comparison mmi-field-match">
                        <div class="mmi-field-value mmi-field-current">
                            <div class="mmi-field-val"${currentTitle}>${currentVal}</div>
                        </div>
                        <div class="mmi-field-arrow mmi-field-arrow-match" title="Matches source — no change">&#10003;</div>
                        <div class="mmi-field-value mmi-field-source">
                            ${matchSourceHtml}
                        </div>
                    </div>
                `;
            }
        },

        escapeHtml: function(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        },
        
        /**
         * Semantic equality for raw field values. Treats null/undefined/'' as
         * the same absent value, and compares numeric strings by magnitude so
         * '49' === '49.00'. Used to override the server-side changed flag and
         * to suppress confusing null/null comparisons in the match display.
         */
        semanticRawEqual: function(a, b) {
            if (a === b) return true;
            const emptyA = (a === null || a === undefined || a === '');
            const emptyB = (b === null || b === undefined || b === '');
            if (emptyA && emptyB) return true;
            if (emptyA !== emptyB) return false;
            const numA = parseFloat(String(a));
            const numB = parseFloat(String(b));
            if (!isNaN(numA) && !isNaN(numB)) {
                return numA === numB;
            }
            return String(a) === String(b);
        },

        /**
         * When source and current are numerically equal but formatted differently
         * (e.g. '49.00' vs '49'), adopt current's format so both sides show the
         * same string in the IMPORT WILL SET display.
         */
        semanticNormalizeForDisplay: function(source, current) {
            if (source === null || source === undefined || current === null || current === undefined) {
                return source;
            }
            const numSrc = parseFloat(String(source));
            const numCur = parseFloat(String(current));
            if (!isNaN(numSrc) && !isNaN(numCur) && numSrc === numCur) {
                return current;
            }
            return source;
        },

        formatValue: function(value) {
            if (value === null || value === undefined) {
                return '<em class="mmi-value-null">null</em>';
            }
            if (value === '') {
                return '<em class="mmi-value-null">empty</em>';
            }
            if (typeof value === 'object') {
                return '<code class="mmi-value-code">' + this.escapeHtml(JSON.stringify(value).substring(0, 50)) + '...</code>';
            }
            const str = String(value);
            if (str.length > 60) {
                return '<span title="' + this.escapeHtml(str) + '">' + this.escapeHtml(str.substring(0, 60)) + '...</span>';
            }
            return this.escapeHtml(str);
        },

        // Inline editing methods
        enterEditMode: function($element) {
            const supplier = $element.data('supplier');
            const primaryKey = $element.data('primary-key');
            const fieldName = $element.data('field-name');
            const currentValue = $element.data('original-value');
            const editKey = `${supplier}_${primaryKey}_${fieldName}`;

            // Get edited value if exists, otherwise use current
            const valueToEdit = this.editedValues[editKey] !== undefined
                ? this.editedValues[editKey]
                : currentValue;

            $element.addClass('mmi-editing');

            const $editContainer = $('<div class="mmi-field-edit-mode"></div>')
                .data('edit-key', editKey)
                .data('supplier', supplier)
                .data('primary-key', primaryKey)
                .data('field-name', fieldName)
                .data('original-value', currentValue);

            const $input = $('<input type="text" class="mmi-edit-input">')
                .val(valueToEdit);

            const $buttons = $('<div class="mmi-edit-buttons"></div>');
            $buttons.append('<button type="button" class="button mmi-edit-save-btn" title="Apply to preview only (not saved to import)"><span class="dashicons dashicons-yes"></span></button>');
            $buttons.append('<button type="button" class="button mmi-edit-cancel-btn" title="Cancel"><span class="dashicons dashicons-no"></span></button>');

            $editContainer.append($input).append($buttons);
            $element.html($editContainer);
            $input.focus().select();
        },

        saveInlineEdit: function($container) {
            const editKey = $container.data('edit-key');
            const supplier = $container.data('supplier');
            const primaryKey = $container.data('primary-key');
            const fieldName = $container.data('field-name');
            const originalValue = $container.data('original-value');
            const newValue = $container.find('.mmi-edit-input').val();

            // Store the edited value
            if (newValue !== originalValue) {
                this.editedValues[editKey] = newValue;
            } else {
                // If changed back to original, remove the edit
                delete this.editedValues[editKey];
            }

            // Re-render the table to show the updated value
            this.renderTable();
        },

        cancelInlineEdit: function($container) {
            // Simply re-render to restore previous state
            this.renderTable();
        },

        revertEdit: function(editKey) {
            delete this.editedValues[editKey];
            this.renderTable();
        },

        getEditedValue: function(supplier, primaryKey, fieldName) {
            const editKey = `${supplier}_${primaryKey}_${fieldName}`;
            return this.editedValues[editKey];
        },

        hasEdit: function(supplier, primaryKey, fieldName) {
            const editKey = `${supplier}_${primaryKey}_${fieldName}`;
            return this.editedValues[editKey] !== undefined;
        },

        // Shows or removes the persistent banner warning that inline edits are
        // preview-only and will not be written to the database by the importer.
        updateEditWarningBanner: function() {
            const editCount = Object.keys(this.editedValues).length;
            const $existing = $('#mmi-edit-preview-banner');

            if (editCount === 0) {
                $existing.remove();
                return;
            }

            const bannerHtml = `
                <div id="mmi-edit-preview-banner" class="notice notice-warning mmi-edit-preview-banner">
                    <span class="dashicons dashicons-edit"></span>
                    <span>
                        <strong>${editCount} preview edit${editCount !== 1 ? 's' : ''} active.</strong>
                        These values are <em>display-only</em> and will <strong>not</strong> affect what the import writes to the database.
                    </span>
                    <button type="button" class="button button-small mmi-clear-edits-btn" id="mmi-clear-all-edits">
                        Discard All
                    </button>
                </div>
            `;

            if ($existing.length) {
                $existing.replaceWith(bannerHtml);
            } else {
                $('#preview-table-content').before(bannerHtml);
            }
        }
    };

    $(document).ready(function() {
        // Guard: import-pipeline.js also calls init() via initializeStepModules(2).
        // Without this check, navigating to Step 2 would call init() a second time,
        // doubling event bindings and firing loadInitialStats() twice.
        if (!MMIImportPreview._initialized) {
            MMIImportPreview.init();
            MMIImportPreview._initialized = true;
        }
        
        // Create panel overlay (idempotent)
        if ($('.mmi-panel-overlay').length === 0) {
            $('body').append('<div class="mmi-panel-overlay"></div>');
        }
    });
    
    window.MMIImportPreview = MMIImportPreview;

})(jQuery);
