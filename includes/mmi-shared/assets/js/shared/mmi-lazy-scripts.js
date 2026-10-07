/**
 * MMI Lazy Scripts — loads an admin script on first use instead of with the page.
 *
 * A plugin registers the script as usual (deps, wp_localize_script data), then
 * calls mmi_shared_lib_lazy_script( $handle, $trigger ) instead of enqueuing it
 * (bootstrap.php). Its localized data is printed at page load as usual; only the
 * file itself waits until one of its triggers fires:
 *
 *   sections — a matching element becomes open (loses .collapsed and is shown);
 *   clicks   — a matching element is clicked: the click is held, the script
 *              loaded, then the same click replayed so the script's own handler
 *              takes it;
 *   eager    — load once the page is ready (a deep link already asks for it).
 *
 * Other code can call window.mmiLazyScripts.load( handle ) and act once the
 * returned promise resolves. An unknown handle resolves immediately, so callers
 * also work where the script was enqueued normally.
 *
 * @package MMI_Shared
 */
(function () {
    'use strict';

    const CONFIG = window.mmiLazyScriptsConfig || {};

    /* ── Selectors & constants ─────────────────────────────────────────── */
    const COLLAPSED_CLASS    = 'collapsed';
    const WATCHED_ATTRIBUTES = ['class', 'style', 'hidden'];

    const loading = {};
    const ready   = {};

    /**
     * Inject the script once. Resolves after jQuery has run the script's own
     * $(document).ready() callbacks: on an already-ready page jQuery defers
     * those to a timer queued while the script executes, so waiting two timer
     * turns after onload puts callers behind them.
     *
     * @param {string} handle
     * @returns {Promise<void>}
     */
    function load(handle) {
        if (loading[handle]) {
            return loading[handle];
        }
        const entry = CONFIG[handle];
        if (!entry || !entry.src) {
            return Promise.resolve();
        }
        loading[handle] = new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src   = entry.src;
            script.id    = `${handle}-js`;
            script.onload = function () {
                setTimeout(function () {
                    setTimeout(function () {
                        ready[handle] = true;
                        resolve();
                    }, 0);
                }, 0);
            };
            script.onerror = function () {
                delete loading[handle];
                reject(new Error(`Could not load ${handle}`));
            };
            document.body.appendChild(script);
        });
        return loading[handle];
    }

    /** @param {Element} el */
    function isOpen(el) {
        return !el.classList.contains(COLLAPSED_CLASS) && el.getClientRects().length > 0;
    }

    function watchSections(handle, selectors) {
        selectors.forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (el) {
                if (isOpen(el)) {
                    load(handle);
                    return;
                }
                const observer = new MutationObserver(function () {
                    if (isOpen(el)) {
                        observer.disconnect();
                        load(handle);
                    }
                });
                observer.observe(el, { attributes: true, attributeFilter: WATCHED_ATTRIBUTES });
            });
        });
    }

    function watchClicks(handle, selectors) {
        if (!selectors.length) {
            return;
        }
        const selector = selectors.join(',');
        let replaying = false;
        // Capture phase on document: ahead of every jQuery delegated handler.
        document.addEventListener('click', function (e) {
            if (ready[handle] || !(e.target instanceof Element)) {
                return;
            }
            const target = e.target.closest(selector);
            if (!target) {
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();
            if (replaying) {
                return;
            }
            replaying = true;
            load(handle).then(function () {
                replaying = false;
                target.click();
            }, function () {
                replaying = false;
            });
        }, true);
    }

    function init() {
        Object.keys(CONFIG).forEach(function (handle) {
            const entry = CONFIG[handle];
            if (entry.eager) {
                load(handle);
                return;
            }
            watchSections(handle, entry.sections || []);
            watchClicks(handle, entry.clicks || []);
        });
    }

    window.mmiLazyScripts = { load: load };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
