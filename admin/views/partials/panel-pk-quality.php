<?php
/**
 * Primary Key Data Quality — inline review/dismiss panel
 *
 * Content-only, using classes (not IDs) throughout since this partial is
 * embedded more than once on the same page — once inside the Configure
 * modal's "Data Parsing" tab, and once per source row inside the wizard's
 * inline PK editor (wizard-sources-pk-editor.php). Deliberately NOT a separate
 * overlay modal: popping a second modal on top of the Configure/wizard
 * modal that's already open created a confusing modal-within-modal stack —
 * this panel instead occupies space in whichever container it's embedded
 * in and toggles visible/hidden in place.
 *
 * Populated via AJAX and rendered client-side — see
 * assets/js/pk-quality-modal.js, which scopes every lookup to the specific
 * .mmi-pk-quality-panel instance the triggering button belongs to.
 *
 * @package MannMade\DataPipeline
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="mmi-pk-quality-panel mmi-is-hidden">
    <div class="mmi-pk-quality-subtitle"></div>

    <div class="mmi-pk-quality-loading">
        <span class="mmi-loading"></span> Scanning feed…
    </div>

    <div class="mmi-pk-quality-error notice notice-error mmi-is-hidden"></div>

    <div class="mmi-pk-quality-content mmi-is-hidden">

        <div class="mmi-pk-quality-empty mmi-is-hidden">
            <span class="dashicons dashicons-yes-alt"></span>
            No outstanding primary-key data issues for this source.
        </div>

        <div class="mmi-pk-quality-section mmi-pk-quality-blank-section mmi-is-hidden" data-group="blank">
            <div class="mmi-pk-quality-section-header">
                <label class="mmi-pk-quality-select-all-label">
                    <input type="checkbox" class="mmi-pk-quality-select-all-cb" data-group="blank">
                    Blank Values (<span class="mmi-pk-quality-count">0</span>)
                </label>
                <div class="mmi-pk-quality-section-actions">
                    <button type="button" class="button mmi-pk-quality-dismiss-selected mmi-is-hidden" data-group="blank">
                        Dismiss Selected (<span class="mmi-pk-quality-selected-count">0</span>)
                    </button>
                    <button type="button" class="button mmi-pk-quality-dismiss-all" data-group="blank">Dismiss All</button>
                </div>
            </div>
            <p class="description">
                These rows have no value in the primary-key field — they can't be
                matched to an existing product and will either be skipped or
                created without a stable identifier.
            </p>
            <ul class="mmi-pk-quality-list mmi-pk-quality-blank-list"></ul>
        </div>

        <div class="mmi-pk-quality-section mmi-pk-quality-duplicate-section mmi-is-hidden" data-group="duplicate">
            <div class="mmi-pk-quality-section-header">
                <label class="mmi-pk-quality-select-all-label">
                    <input type="checkbox" class="mmi-pk-quality-select-all-cb" data-group="duplicate">
                    Duplicate Values (<span class="mmi-pk-quality-count">0</span>)
                </label>
                <div class="mmi-pk-quality-section-actions">
                    <button type="button" class="button mmi-pk-quality-dismiss-selected mmi-is-hidden" data-group="duplicate">
                        Dismiss Selected (<span class="mmi-pk-quality-selected-count">0</span>)
                    </button>
                    <button type="button" class="button mmi-pk-quality-dismiss-all" data-group="duplicate">Dismiss All</button>
                </div>
            </div>
            <p class="description">
                These primary-key values are shared by more than one row — only
                one product per value can be matched correctly; the rest will
                overwrite it instead of their own record.
            </p>
            <div class="mmi-pk-quality-list mmi-pk-quality-duplicate-list"></div>
        </div>

    </div><!-- /.mmi-pk-quality-content -->

    <div class="mmi-pk-quality-footer">
        <span class="mmi-pk-quality-save-status"></span>
    </div>
</div><!-- /.mmi-pk-quality-panel -->
