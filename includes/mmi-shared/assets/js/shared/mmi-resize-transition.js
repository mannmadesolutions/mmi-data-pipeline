/**
 * Softens a container's rendered-height change when an alert/badge insert
 * makes it taller or shorter — e.g. a header status badge growing from
 * "Not yet checked" to "✓ Connected — token retrieved successfully.",
 * which otherwise causes the header (and everything below it) to jump
 * instantly. Reference case: mmi-xchange-integration's header connection/
 * order-API badges.
 *
 * Not needed for an element that's toggling fully hidden <-> visible —
 * jQuery's .slideDown()/.slideUp() (already used by, e.g.,
 * mmi-reverb-integration's #mmi-test-result panel) already animate that
 * case correctly on their own. This helper is for the different case: an
 * already-visible container whose CONTENT changes size while it stays
 * visible the whole time, which .slideDown()/.slideUp() don't apply to.
 *
 * Plain CSS can't do this by itself — a `transition: height` never
 * animates between two `auto` values, only between two explicit ones — so
 * this uses the standard FLIP measurement: read the height before the
 * change, apply the change, read the height after, then animate from the
 * first number to the second.
 */
window.mmiAnimateResize = function (container, mutate) {
    const el = (container && container.jquery) ? container[0] : container;

    if (!el) {
        mutate();
        return;
    }

    const RESET_DELAY_MS = 350; // matches --mmi-transition-slow (0.3s) + margin

    const startHeight = el.getBoundingClientRect().height;

    mutate();

    const endHeight = el.getBoundingClientRect().height;

    if (startHeight === endHeight) {
        return;
    }

    el.style.height = startHeight + 'px';
    el.style.overflow = 'hidden';

    // Force a reflow so the browser registers the starting height as a real
    // rendered value before the end height is applied — without this the
    // two assignments collapse into one and nothing animates.
    void el.offsetHeight;

    el.style.transition = 'height var(--mmi-transition-slow, 0.3s ease)';
    el.style.height = endHeight + 'px';

    window.setTimeout(function () {
        el.style.height = '';
        el.style.overflow = '';
        el.style.transition = '';
    }, RESET_DELAY_MS);
};
