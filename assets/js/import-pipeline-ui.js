/**
 * Import Pipeline — UI Helpers
 * Extends window.MMIDataPipeline with methods for this concern.
 */
(function($) {
    'use strict';

    const ajaxUrl = (window.mmiImportSettings && window.mmiImportSettings.ajaxurl)
                    || (window.mmiGlobal && window.mmiGlobal.ajaxUrl)
                    || window.ajaxurl
                    || '/wp-admin/admin-ajax.php';
    const nonce = (window.mmiImportSettings && window.mmiImportSettings.nonce) || '';

    // Slide-up entrance is defined on .mmi-config-panel via the mmiSlideInUp
    // CSS animation (import-settings.css). PANEL_CLOSE_MS mirrors that 0.3s
    // duration so the close (mmiSlideOutDown — the same animation reversed)
    // finishes before display is set back to none.
    const PANEL_CLOSE_MS = 300;

    function escAttr(val) { return $('<div>').text(val || '').html().replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    // Plays the slide-down/fade-out close animation (the entrance reversed)
    // and hides the panel once it completes. No scroll position is read or
    // changed here — the overlay's CSS overscroll-behavior/touch-action
    // containment is what keeps the background page from moving while a
    // panel is open, so there is nothing to restore on close.
    function closeConfigPanel($panel) {
        if (!$panel.is(':visible')) { return; }
        $panel.stop(true).css('opacity', '').addClass('mmi-panel-closing');
        setTimeout(function () {
            $panel.removeClass('mmi-panel-closing').css('display', 'none');
        }, PANEL_CLOSE_MS);
    }

    Object.assign(window.MMIDataPipeline, {
        showNotification: function(message, type) {
            // Remove any existing inline pipeline notifications
            $('.mmi-pipeline-notice').remove();

            const bgClass = type === 'success' ? 'mmi-pipeline-notice-success'
                          : type === 'error'   ? 'mmi-pipeline-notice-error'
                          : 'mmi-pipeline-notice-info';
            const icon = type === 'success' ? '&#10003;' : type === 'error' ? '&#10007;' : '&#9432;';
            const $n = $(
                '<div class="mmi-pipeline-notice ' + bgClass + '" role="alert">' +
                    '<span class="mmi-pipeline-notice-icon">' + icon + '</span> ' +
                    escAttr(message) +
                    '<button class="mmi-pipeline-notice-close" aria-label="Dismiss">&times;</button>' +
                '</div>'
            );

            // If a .mmi-config-modal is open (Add Data Source, Configure Data
            // Source, etc.), the notice must render inside its body — the
            // modal's backdrop sits above the page, so a page-level notice
            // (the fallback below) ends up rendered behind the modal and is
            // easy to miss or entirely hidden.
            const $openModalBody = $('.mmi-config-modal:not(.mmi-is-hidden)').first().find('.mmi-config-modal-body');
            if ($openModalBody.length) {
                $openModalBody.prepend($n);
                // .mmi-config-modal-body scrolls independently (overflow-y: auto)
                // — if the user had scrolled down, the prepended notice would sit
                // above the visible area. Snap back to the top so it's seen.
                $openModalBody.scrollTop(0);
            } else {
                // Prefer inserting at the top of the first visible mmi-process-section.
                // Fall back to prepending to the pipeline container or wrap div.
                const $section = $('.mmi-process-section:visible').first();
                if ($section.length) {
                    $section.prepend($n);
                } else {
                    const $container = $('.mmi-data-pipeline-container, .wrap').first();
                    $container.prepend($n);
                }
            }

            // Dismiss on close button click
            $n.on('click', '.mmi-pipeline-notice-close', function() {
                $n.remove();
            });

            // Auto-remove after 6 seconds
            setTimeout(function() { $n.fadeOut(300, function() { $(this).remove(); }); }, 6000);
        },

        // Real implementation lives in import-settings.js (showAutosaveIndicator,
        // exposed as MMIDataPipeline.showAutosaveIndicator) — the same floating
        // toast the wizard's field-mapping/attributes autosave already uses.
        // This wrapper previously called a `window.MMISaveIndicator` global
        // that was never defined anywhere in the codebase, so every autosave
        // in the schedule/rules topbar (frequency, time-of-day, "Skip if no
        // new data", import rules) had been showing nothing on save at all —
        // see AGENTS.md's changelog for the date this was found and fixed.
        showSaveIndicator: function(type, message) {
            if (typeof window.MMIDataPipeline.showAutosaveIndicator !== 'function') {
                return;
            }
            const msg = message || (
                type === 'error'  ? '✗ Save failed' :
                type === 'saving' ? 'Saving…'        :
                                     '✓ Saved'
            );
            window.MMIDataPipeline.showAutosaveIndicator(msg, type || 'success');
        },

        // Keeps a Schedules-panel row's Details cell (source type label, or
        // the per-profile "Skip if no new data" toggle) in sync with its own
        // Frequency select: greyed out and non-interactive whenever that
        // select reads "Disabled". Server-render (tab-pipeline.php) sets the
        // same initial state so there's no flash on page load; this is what
        // keeps it correct after the user changes the dropdown (including a
        // revert back to its previous value if a schedule save is declined —
        // see autosaveSchedule()'s revert() in import-pipeline-csv.js).
        updateScheduleRowDisabledState: function($select) {
            const $row = $select.closest('.mmi-tsched-row');
            if (!$row.length) {
                return;
            }
            const isDisabled = $select.val() === 'disabled';
            $row.toggleClass('mmi-sched-is-disabled', isDisabled);
            $row.find('.mmi-tsched-detail :input').prop('disabled', isDisabled);
        },

        // "Every Hour" only has a meaningful MINUTE component (see
        // tab-pipeline.php's mmi_pipeline_render_schedule_time_control()
        // docblock) — swaps the row's time control between the minute-only
        // <select> and the full <input type="time"> whenever the Frequency
        // select changes to/from 'hourly' without a page reload, mirroring
        // that same PHP function's markup so server render and live JS never
        // disagree about which control a given frequency gets. A no-op when
        // the control already matches (e.g. any non-hourly → non-hourly
        // change). Returns the (possibly new) time control jQuery object so
        // the caller can read its current value immediately.
        updateScheduleTimeControlKind: function($select) {
            const $controls = $select.closest('.mmi-schedule-controls');
            const $time     = $controls.find('.mmi-autosave-schedule-time');
            const process   = $select.data('process');
            const wantMinuteSelect = $select.val() === 'hourly';
            const isMinuteSelect   = $time.is('select');

            if (wantMinuteSelect === isMinuteSelect) {
                return $time;
            }

            let $replacement;
            if (wantMinuteSelect) {
                const MINUTE_OPTIONS = ['00', '05', '10', '15', '20', '25', '30', '35', '40', '45', '50', '55'];
                $replacement = $('<select class="mmi-schedule-minute-select mmi-autosave-schedule-time" title="Minute past each hour this fetch runs at — stagger sources to avoid overlapping runs">');
                MINUTE_OPTIONS.forEach(function(mm) {
                    $replacement.append($('<option>').attr('value', '00:' + mm).text(':' + mm));
                });
                $replacement.val('00:00');
            } else {
                $replacement = $('<input type="time" step="300" class="mmi-schedule-time-input mmi-autosave-schedule-time" title="Time of day (site timezone) this schedule anchors to — stagger jobs to spread out server load">');
            }
            $replacement.attr('data-process', process);

            $time.replaceWith($replacement);
            return $replacement;
        },

        // Patches Fetch → Import Linking state after an AJAX link/unlink of
        // ONE (profile, source) pair (toggleProfileLink() in import-pipeline-
        // csv.js) — no page reload. See MMI_Pipeline_Cron's "Fetch → Import
        // Linking" section. `data` is the AJAX response's `data` object:
        // { supplier_id, linked, any_linked, next_label, staleness_message }.
        //
        // Two effect scopes, deliberately kept separate — collapsing them
        // into one was the original bug (toggling one source's row hid a
        // SIBLING source's row, because the old single-link version had one
        // status block shared by the whole profile instead of one row per
        // pair):
        //  - per-pair: only the row named by data.supplier_id — its own
        //    checked state and which of its three text spans shows.
        //  - per-profile: every row for this profile shares the Next Run
        //    cell, the locked/unlocked Frequency+time controls, and the
        //    staleness note, since those all depend on the profile's FULL
        //    linked set, not just the one pair that was just toggled.
        applyProfileLinkState: function(profileId, data) {
            const $next = $('#sched-profile-' + profileId + '-next');
            const $row  = $next.closest('.mmi-tsched-row');
            if (!$row.length) {
                return;
            }

            // ── Per-pair ──
            const $pairRow = $row.find('.mmi-tsched-link-row[data-supplier="' + data.supplier_id + '"]');
            if ($pairRow.length) {
                const linked = !!data.linked;
                $pairRow.find('.mmi-tsched-link-toggle').prop('checked', linked);
                $pairRow.find('.dashicons')
                    .toggleClass('dashicons-admin-links mmi-tsched-link-row-icon--linked', linked)
                    .toggleClass('dashicons-lightbulb mmi-tsched-link-row-icon--suggestion', !linked);
                // A row can only ever be toggled when matches === true (a
                // mismatched row's checkbox is disabled — see tab-pipeline.php),
                // so after any successful link/unlink its correct resting
                // text is always "linked" or "match," never "mismatch."
                $pairRow.find('.mmi-tsched-link-row-linked').prop('hidden', !linked);
                $pairRow.find('.mmi-tsched-link-row-match').prop('hidden', linked);
                $pairRow.find('.mmi-tsched-link-row-mismatch').prop('hidden', true);
            }

            // ── Per-profile ──
            $next.text(data.next_label || '—');

            const $controls = $row.find('.mmi-schedule-controls');
            $controls.toggleClass('mmi-schedule-controls-locked', !!data.any_linked);
            $controls.find('.mmi-autosave-schedule, .mmi-autosave-schedule-time').prop('disabled', !!data.any_linked);

            const $staleness = $row.find('.mmi-tsched-link-staleness');
            $staleness.find('.mmi-tsched-link-staleness-text').text(data.staleness_message || '');
            $staleness.prop('hidden', !data.staleness_message);
        },

        // Keeps a not-yet-linked row's Fetch → Import Linking copy in sync
        // with its own Frequency select the instant it changes — see
        // MMI_Pipeline_Cron::get_link_rows()'s docblock on why a row is
        // shown even when it doesn't yet match (New Products at Twice Daily
        // against an hourly source, Web Assets while Disabled, etc.): each
        // row already carries its source's frequency in `data-source-
        // frequency` (tab-pipeline.php), so matching is a pure client-side
        // comparison against the new value — no AJAX round trip needed just
        // to tell the user their new frequency now lines up. Mirrors
        // server-side matching exactly: non-disabled AND equal. Skips any
        // row that's currently linked — in practice the Frequency select is
        // disabled the entire time any row for this profile is linked (see
        // .mmi-schedule-controls-locked), so this can't fire for one, but
        // the guard makes that assumption explicit rather than relying on
        // it silently.
        updateLinkSuggestionMatches: function($select) {
            const $row    = $select.closest('.mmi-tsched-row');
            const newFreq = $select.val();

            $row.find('.mmi-tsched-link-row').each(function() {
                const $linkRow = $(this);
                if ($linkRow.find('.mmi-tsched-link-toggle').is(':checked')) {
                    return;
                }
                const matches = newFreq !== 'disabled' && newFreq === $linkRow.data('source-frequency');
                $linkRow.find('.mmi-tsched-link-toggle').prop('disabled', !matches);
                $linkRow.find('.mmi-tsched-link-row-match').prop('hidden', !matches);
                $linkRow.find('.mmi-tsched-link-row-mismatch').prop('hidden', matches);
            });
        },

        showPanel: function(panelId) {
            // Use display:flex + opacity animation — jQuery fadeIn() sets display:block
            // which breaks the flexbox layout of .mmi-config-panel. The slide-up
            // entrance itself comes from the mmiSlideInUp CSS animation on
            // .mmi-config-panel, which (re)plays whenever display changes from none.
            $('#' + panelId).removeClass('mmi-panel-closing').css({'display': 'flex', 'opacity': 0}).animate({opacity: 1}, 300);
            if ($('.mmi-panel-overlay').length === 0) {
                $('body').append('<div class="mmi-panel-overlay"></div>');
            }
            $('.mmi-panel-overlay').addClass('active');
        },

        hidePanel: function(panelId) {
            closeConfigPanel($('#' + panelId));
            $('.mmi-panel-overlay').removeClass('active');
        },

        hideAllPanels: function() {
            $('.mmi-config-panel').each(function () { closeConfigPanel($(this)); });
            $('.mmi-panel-overlay').removeClass('active');
        },

        // The Import Profile wizard's "+ Add a Data Source" link hides the
        // wizard (not close/destroy — slideUp only) and hands off to the
        // full Add Data Source modal for source types the wizard's own inline
        // upload can't handle (API/URL/Dropbox/Google Drive). Whichever way
        // that modal exits — Cancel/close, backdrop click, or a successful
        // create — the wizard must come back exactly as the user left it,
        // not strand them on the underlying page. See import-settings.js's
        // #new-profile-add-source-link handler for where the flag is set.
        reopenWizardIfPending: function() {
            if (!window._mmiWizardPendingSourceHandoff) { return; }
            window._mmiWizardPendingSourceHandoff = false;
            const $wizardSection = $('#new-profile-modal');
            $wizardSection.slideDown(200);
            $wizardSection[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    // Close any open config panel when clicking the overlay backdrop.
    // stopPropagation prevents WP admin's own ESC/click handlers from
    // also calling jQuery .hide() on the panel (which would set a stale
    // inline style that conflicts with the next showPanel() call).
    $(document).on('click', '.mmi-panel-overlay', function () {
        window.MMIDataPipeline.hideAllPanels();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('.mmi-config-panel:visible').length) {
            e.stopPropagation();
            window.MMIDataPipeline.hideAllPanels();
        }
    });

})(jQuery);
