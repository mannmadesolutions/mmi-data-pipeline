/**
 * Import Settings - Save Status Indicator
 * Adds visual feedback when form has unsaved changes
 */

jQuery(document).ready(function($) {
    let hasUnsavedChanges = false;
    
    // Track changes to form inputs (except those with autosave).
    // .mmi-page-size-select is excluded because it is a UI-only pagination
    // control rendered dynamically — it is not a saveable settings field and
    // must never be caught here (which would also block stopImmediatePropagation).
    $('form input, form select:not(.mmi-page-size-select), form textarea').on('change', function() {
        if (!hasUnsavedChanges) {
            hasUnsavedChanges = true;
            showUnsavedIndicator();
        }
    });
    
    // Clear flag when form is submitted
    $('form').on('submit', function() {
        hasUnsavedChanges = false;
        hideUnsavedIndicator();
    });
    
    // Note: beforeunload is handled centrally by import-settings.js to respect
    // the isProfileSwitching flag. A second handler here would fire even during
    // intentional profile switches, so it has been removed.
    
    function showUnsavedIndicator() {
        $('.mmi-pipeline-save-buttons button').addClass('unsaved-changes');
        $('.mmi-pipeline-save-buttons p').html('<strong class="mmi-text-error">⚠️ You have unsaved changes!</strong> Click the button above to save.');
    }
    
    function hideUnsavedIndicator() {
        $('.mmi-pipeline-save-buttons button').removeClass('unsaved-changes');
        $('.mmi-pipeline-save-buttons p').html('<strong>Note:</strong> Some field mappings auto-save as you change them. Click this button to save Import Rules, Scheduling, and other settings.');
    }
});
