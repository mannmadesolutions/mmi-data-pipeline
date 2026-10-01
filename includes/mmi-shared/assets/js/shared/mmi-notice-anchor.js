/**
 * MMI Notice Anchor Guardian
 *
 * Root cause
 * ----------
 * WordPress core (wp-admin/js/common.js) relocates every .notice:not(.inline)
 * element to after the first .wp-header-end marker found inside .wrap. When
 * that marker is absent WP falls back to inserting before the first H1/H2 it
 * finds — which lives inside .mmi-header (a flex container), pulling the
 * notices visually inside the header card after page load.
 *
 * What this script does
 * ---------------------
 * 1. Runs SYNCHRONOUSLY at parse time (no defer/async) so the marker exists
 *    before WP's common.js fires its notice-relocation on DOMContentLoaded.
 * 2. Runs again on DOMContentLoaded in the capture phase (fires before normal
 *    bubbling handlers such as WP's own notice handler) as a belt-and-braces
 *    pass.
 * 3. Installs a MutationObserver so any .mmi-header injected dynamically
 *    (AJAX-loaded tabs, lazy admin panels, etc.) also gets the marker.
 *
 * Every MMI extension benefits automatically — no per-plugin work needed.
 * Extension-specific PHP templates should still include the marker in markup
 * as a static fallback (zero JS dependency).
 */
(function () {
    'use strict';

    /**
     * Ensure every .mmi-header is immediately followed by a .wp-header-end
     * sibling. Idempotent — safe to call multiple times.
     */
    function ensureNoticeAnchors() {
        var headers = document.querySelectorAll('.mmi-header');
        for (var i = 0; i < headers.length; i++) {
            var header = headers[i];
            var next   = header.nextElementSibling;
            if (!next || next.className.indexOf('wp-header-end') === -1) {
                var anchor       = document.createElement('div');
                anchor.className = 'wp-header-end';
                header.parentNode.insertBefore(anchor, header.nextSibling);
            }
        }
    }

    // ── 1. Immediate synchronous pass ────────────────────────────────────────
    // Runs at script parse time so the marker is present before any deferred
    // scripts (including WP's common.js) execute.
    ensureNoticeAnchors();

    // ── 2. DOMContentLoaded (capture phase) ──────────────────────────────────
    // Capture phase fires before bubble-phase handlers, giving us priority over
    // WP's own DOMContentLoaded notice handler in common.js.
    document.addEventListener('DOMContentLoaded', ensureNoticeAnchors, true);

    // ── 3. MutationObserver — dynamic header injection ───────────────────────
    if (typeof MutationObserver !== 'undefined') {
        var debounceTimer = null;

        var observer = new MutationObserver(function (mutations) {
            // Check whether any mutation added nodes that contain or are
            // an .mmi-header before doing a full DOM scan.
            var relevant = false;
            for (var m = 0; m < mutations.length; m++) {
                var nodes = mutations[m].addedNodes;
                for (var n = 0; n < nodes.length; n++) {
                    var node = nodes[n];
                    if (node.nodeType !== 1) continue; // element nodes only
                    if (
                        node.classList && node.classList.contains('mmi-header') ||
                        (node.querySelector && node.querySelector('.mmi-header'))
                    ) {
                        relevant = true;
                        break;
                    }
                }
                if (relevant) break;
            }
            if (!relevant) return;

            // Debounce to avoid thrashing during large DOM builds.
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(ensureNoticeAnchors, 32);
        });

        document.addEventListener('DOMContentLoaded', function () {
            if (document.body) {
                observer.observe(document.body, { childList: true, subtree: true });
            }
        });
    }
}());
