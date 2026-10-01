/**
 * MMI Universal Column Resizer
 * 
 * Automatically enables column resizing for all wp-list-table elements
 * Persists column widths to localStorage per-table
 * 
 * @package MMI
 * @version 1.0.0
 */

(function($) {
    'use strict';
    
    // Auto-initialize on document ready
    $(document).ready(function() {
        MMI_ColumnResizer.init();
    });
    
    window.MMI_ColumnResizer = {
        
        /**
         * Initialize column resizing for all tables
         */
        init: function() {
            // Opt-in only: only initialize tables that explicitly request resizing.
            // Previously targeted .wp-list-table globally, which forced table-layout:fixed
            // on all WP core admin tables (products, plugins, posts, etc.) and corrupted
            // their native column-width distribution. Now only .mmi-data-table and
            // tables with [data-mmi-resizable] are initialized.
            const $tables = $([
                '.mmi-data-table',
                '[data-mmi-resizable]'
            ].join(', '));
            
            if (!$tables.length) return;
            
            let initializedCount = 0;
            $tables.each(function() {
                const $table = $(this);
                
                // Skip if already initialized, is a form table, or explicitly opts out
                if ($table.hasClass('form-table') || $table.data('resizer-init') || $table.attr('data-no-resize')) {
                    return;
                }
                
                MMI_ColumnResizer.initTable($table);
                initializedCount++;
            });
            
            // Only inject global styles when column resizing is actually active on this page
            if (initializedCount > 0) {
                this.addStyles();
            }
        },
        
        /**
         * Initialize a single table
         */
        initTable: function($table) {
            const tableId = this.getTableId($table);
            const storageKey = 'mmi_column_widths_' + tableId;
            
            let isResizing = false;
            let currentColumn = null;
            let startX = 0;
            let startWidth = 0;
            // Prefer closest scroll/wrapper ancestor; avoid climbing to .wrap / #wpbody-content
            // which would make cursor:col-resize and user-select:none apply to the whole page.
            let $wrapper = $table.closest('[class*="-scroll-"], [class*="-wrapper"]');
            if (!$wrapper.length) { $wrapper = $table.parent(); }
            // Per-table event namespace prevents document-level handlers from accumulating
            // across multiple tables and ensures cleanup only affects this table's handlers.
            const namespace = '.mmi-resizer-' + tableId;
            
            // Mark as initialized
            $table.data('resizer-init', true);
            
            // Ensure table has fixed layout
            if (!$table.css('table-layout') || $table.css('table-layout') === 'auto') {
                $table.css('table-layout', 'fixed');
            }
            
            // Add resize handles to all headers
            $table.find('thead th, thead td').each(function(index) {
                const $th = $(this);
                
                // Skip if checkbox column or already has handle
                if ($th.find('.resize-handle').length || $th.hasClass('check-column')) {
                    return;
                }
                
                const $handle = $('<div class="mmi-resize-handle"></div>');
                // Preserve sticky positioning — only apply relative if the th is not already sticky
                if (window.getComputedStyle($th[0]).position !== 'sticky') {
                    $th.css('position', 'relative');
                }
                $th.append($handle);
                
                // Store column index
                $th.attr('data-column-index', index);
            });
            
            // Load saved widths
            this.loadColumnWidths($table, storageKey);
            
            // Mouse down on resize handle
            $table.on('mousedown', '.mmi-resize-handle', function(e) {
                e.preventDefault();
                e.stopPropagation();

                isResizing = true;
                currentColumn = $(this).parent();
                startX = e.pageX;
                startWidth = currentColumn.outerWidth();

                $(this).addClass('resizing');
                $wrapper.addClass('mmi-resizing');
                $wrapper.addClass('mmi-no-select');
            });

            // "click" is a separate event from mousedown/mouseup — stopping
            // mousedown's propagation above does not stop a click from also firing
            // and bubbling into a sortable header's delegated click handler. Without
            // this, resizing a column doubles as triggering a sort on it.
            $table.on('click', '.mmi-resize-handle', function(e) {
                e.stopPropagation();
            });

            // Mouse move - resize column
            $(document).on('mousemove' + namespace, function(e) {
                if (!isResizing) return;
                
                const diff = e.pageX - startX;
                const newWidth = Math.max(40, startWidth + diff);
                
                currentColumn.css('width', newWidth + 'px');
            });
            
            // Mouse up - finish resizing
            $(document).on('mouseup' + namespace, function() {
                if (!isResizing) return;
                
                isResizing = false;
                $('.mmi-resize-handle').removeClass('resizing');
                $wrapper.removeClass('mmi-resizing');
                $wrapper.removeClass('mmi-no-select');
                
                // Save column widths
                MMI_ColumnResizer.saveColumnWidths($table, storageKey);
                
                currentColumn = null;
            });

            // Cancel resize if mouse exits the browser window (prevents stuck resize state)
            $(document).on('mouseleave' + namespace, function(e) {
                if (!isResizing || e.relatedTarget !== null) return;
                isResizing = false;
                $('.mmi-resize-handle').removeClass('resizing');
                $wrapper.removeClass('mmi-resizing');
                $wrapper.removeClass('mmi-no-select');
                MMI_ColumnResizer.saveColumnWidths($table, storageKey);
                currentColumn = null;
            });
        },
        
        /**
         * Get unique identifier for table
         */
        getTableId: function($table) {
            // Use table ID if available
            if ($table.attr('id')) {
                return $table.attr('id');
            }
            
            // Use class name
            const classes = $table.attr('class');
            if (classes) {
                const match = classes.match(/[a-zA-Z0-9_-]+/);
                if (match) return match[0];
            }
            
            // Use parent wrapper ID/class
            const $wrapper = $table.closest('[id], [class*="-wrapper"]');
            if ($wrapper.attr('id')) {
                return $wrapper.attr('id') + '_table';
            }
            
            // Fallback to page identifier
            const page = new URLSearchParams(window.location.search).get('page') || 'unknown';
            const tab = new URLSearchParams(window.location.search).get('tab') || 'default';
            return page + '_' + tab + '_table';
        },
        
        /**
         * Save column widths to localStorage
         */
        saveColumnWidths: function($table, storageKey) {
            const widths = {};
            
            $table.find('thead th, thead td').each(function() {
                const index = $(this).data('column-index');
                if (index !== undefined) {
                    widths[index] = $(this).outerWidth();
                }
            });
            
            try {
                localStorage.setItem(storageKey, JSON.stringify(widths));
            } catch (e) {
                console.warn('Could not save column widths:', e);
            }
        },
        
        /**
         * Load column widths from localStorage
         */
        loadColumnWidths: function($table, storageKey) {
            try {
                const savedWidths = localStorage.getItem(storageKey);
                if (!savedWidths) return;
                
                const widths = JSON.parse(savedWidths);
                
                $table.find('thead th, thead td').each(function() {
                    const index = $(this).data('column-index');
                    if (index !== undefined && widths[index]) {
                        $(this).css('width', widths[index] + 'px');
                    }
                });
            } catch (e) {
                console.warn('Could not load column widths:', e);
            }
        },
        
        /**
         * Add global styles
         */
        addStyles: function() {
            if ($('#mmi-column-resizer-styles').length) return;
            
            $('<style id="mmi-column-resizer-styles">').text(`
                /* MMI Column Resizer Styles */
                .mmi-resize-handle {
                    position: absolute;
                    right: 0;
                    top: 0;
                    bottom: 0;
                    width: 5px;
                    cursor: col-resize;
                    z-index: 10;
                    background: transparent;
                }
                
                .mmi-resize-handle:hover,
                .mmi-resize-handle.resizing {
                    background: #2271b1;
                }
                
                .mmi-resizing {
                    cursor: col-resize !important;
                    user-select: none !important;
                }
                
                .mmi-resizing * {
                    cursor: col-resize !important;
                    user-select: none !important;
                }
                
                .mmi-no-select {
                    user-select: none !important;
                    -webkit-user-select: none !important;
                    -moz-user-select: none !important;
                    -ms-user-select: none !important;
                }
                
                /* Ensure headers can contain resize handles. */
                .mmi-data-table th,
                [data-mmi-resizable] th {
                    position: relative;
                }
            `).appendTo('head');
        }
    };
    
})(jQuery);
