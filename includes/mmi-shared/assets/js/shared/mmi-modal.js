/**
 * MMI Modal — shared centered-dialog open/close behavior
 *
 * The suite had 15 independent modal implementations across 8 plugins before
 * this (AGENTS.md changelog 1.118.0's usage survey) — each with its own
 * open/close mechanism, none with a focus trap, several with fragile
 * `[style*="display:..."]` selector hacks papering over a jQuery .show()/
 * .hide() call fighting the CSS. This is the one shared implementation going
 * forward: markup + visual states live in mmi-suite-common.css's
 * `.mmi-modal-backdrop`/`.mmi-modal-*` rules, this file owns showing/hiding,
 * Esc-to-close, backdrop-click-to-close, and a focus trap.
 *
 * Expected markup (see mmi-suite-common.css's `.mmi-modal-backdrop` comment
 * for the full class reference):
 *
 *   <div class="mmi-modal-backdrop" id="my-modal" hidden role="dialog"
 *        aria-modal="true" aria-hidden="true" aria-labelledby="my-modal-title">
 *     <div class="mmi-modal">
 *       <div class="mmi-modal-header">
 *         <h3 id="my-modal-title">Title</h3>
 *         <button type="button" class="mmi-modal-close" data-close aria-label="Close">&times;</button>
 *       </div>
 *       <div class="mmi-modal-body">...</div>
 *       <div class="mmi-modal-footer">
 *         <button type="button" class="button" data-close>Cancel</button>
 *         <button type="button" class="button button-primary">Save</button>
 *       </div>
 *     </div>
 *   </div>
 *
 * `[hidden]` is the only thing that gates visibility — never toggle display/
 * visibility directly (AGENTS.md's "[hidden] Attribute vs. a Class That Sets
 * display" rule). MMIModal.init() wires backdrop-click and `[data-close]`
 * delegation once per page (safe to call multiple times); MMIModal.open()/
 * close() manage the [hidden] attribute, the Esc listener, and the focus
 * trap for one modal at a time.
 *
 * @package MannMade\Hub
 */

window.MMIModal = (function ($) {
    'use strict';

    const SELECTORS = {
        BACKDROP: '.mmi-modal-backdrop',
        CLOSE_TRIGGER: '[data-close]',
        FOCUSABLE: 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
    };

    let inited = false;
    let openBackdropEl = null;
    let lastFocusedEl = null;

    function focusableIn(backdropEl) {
        return Array.prototype.slice
            .call(backdropEl.querySelectorAll(SELECTORS.FOCUSABLE))
            .filter(function (el) { return el.offsetParent !== null; });
    }

    function onKeydown(e) {
        if (!openBackdropEl) {
            return;
        }
        if (e.key === 'Escape') {
            close(openBackdropEl);
            return;
        }
        if (e.key === 'Tab') {
            const focusable = focusableIn(openBackdropEl);
            if (!focusable.length) {
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    function resolve(target) {
        if (typeof target === 'string') {
            const byId = document.getElementById(target);
            return byId || document.querySelector(target);
        }
        return target instanceof $ ? target.get(0) : target;
    }

    /** @param {string|Element|jQuery} target modal id, selector, element, or jQuery object for the `.mmi-modal-backdrop` */
    function open(target) {
        const el = resolve(target);
        if (!el) {
            return;
        }
        if (openBackdropEl && openBackdropEl !== el) {
            close(openBackdropEl);
        }
        lastFocusedEl = document.activeElement;
        el.removeAttribute('hidden');
        el.setAttribute('aria-hidden', 'false');
        openBackdropEl = el;
        const focusable = focusableIn(el);
        if (focusable.length) {
            focusable[0].focus();
        }
        document.addEventListener('keydown', onKeydown);
    }

    /** @param {string|Element|jQuery} [target] defaults to whichever modal is currently open */
    function close(target) {
        const el = target ? resolve(target) : openBackdropEl;
        if (!el) {
            return;
        }
        el.setAttribute('hidden', '');
        el.setAttribute('aria-hidden', 'true');
        if (openBackdropEl === el) {
            document.removeEventListener('keydown', onKeydown);
            openBackdropEl = null;
            if (lastFocusedEl && typeof lastFocusedEl.focus === 'function') {
                lastFocusedEl.focus();
            }
        }
    }

    function isOpen(target) {
        const el = resolve(target);
        return !!el && !el.hasAttribute('hidden');
    }

    /** Wires backdrop-click and [data-close] delegation once per page. Safe to call more than once. */
    function init() {
        if (inited) {
            return;
        }
        inited = true;
        $(document).on('click', SELECTORS.BACKDROP, function (e) {
            if (e.target === this) {
                close(this);
            }
        });
        $(document).on('click', SELECTORS.CLOSE_TRIGGER, function (e) {
            const backdrop = $(this).closest(SELECTORS.BACKDROP).get(0);
            if (backdrop) {
                e.preventDefault();
                close(backdrop);
            }
        });
    }

    return { init: init, open: open, close: close, isOpen: isOpen };
})(jQuery);
