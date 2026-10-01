/**
 * MMI Resize/Sort Guard — a column-resize drag must never also sort.
 *
 * Every sortable MMI table header listens for `click`. Dragging a column's
 * resize handle ends with a mouseup, and the browser then fires a `click` on
 * the nearest common ancestor — almost always the header cell itself, since a
 * drag rarely ends on the thin handle. That click sorted the column. Each
 * table used to guard against it on its own; the suite had ~12 resize
 * implementations with 8+ handle class names, and most guards were missing
 * or half-done (see AGENTS.md → Data Tables).
 *
 * This guard runs once per admin page, ahead of every plugin's handlers: it
 * listens on `window` in the capture phase, which fires before any
 * element-level or jQuery-delegated (bubble-phase) click handler. A header
 * click is swallowed when either:
 *   1. the press started on a resize handle — recognised by class, or by its
 *      `col-resize` cursor, which every handle design shares; or
 *   2. the pointer travelled more than DRAG_THRESHOLD_PX between press and
 *      release — a drag, not a sort click.
 *
 * Nothing needs registering: a new table, or a new handle design with the
 * col-resize cursor, is covered automatically.
 */
(function () {
    'use strict';

    if (window.MMIResizeSortGuard) return; // one per page, whichever plugin loads it

    const DRAG_THRESHOLD_PX = 4;

    // Where a sort click can land: header cells and anything marked sortable.
    const HEADER_SELECTOR = 'th, thead td, [role="columnheader"], .sortable, [data-col], [data-sort]';

    // Known handle classes across the suite; the cursor check catches the rest.
    const HANDLE_SELECTOR = [
        '.mmi-resize-handle', '.resize-handle', '.col-resizer', '.mmi-x-resize-handle',
        '.mmi-taxmap-th-resize', '.mmi-export-preview-th-resize', '.mmi-dupes-th-resize',
        '[data-resize-handle]',
    ].join(', ');

    // A click this soon after a resize drag/handle press is never a sort.
    // Time-boxed rather than cleared on the next tick: Safari can deliver the
    // click as a separate task after pointerup, so a setTimeout(0) reset
    // (the first version of this guard) let the sort through there.
    const SUPPRESS_WINDOW_MS = 1000;

    let press = null;       // { x, y, onHandle }
    let suppressUntil = 0;  // performance.now() deadline

    function isHandle(el) {
        if (!(el instanceof Element)) return false;
        if (el.closest(HANDLE_SELECTOR)) return true;
        try {
            return window.getComputedStyle(el).cursor === 'col-resize';
        } catch (e) {
            return false;
        }
    }

    function onDown(e) {
        suppressUntil = 0;
        const header = e.target instanceof Element ? e.target.closest(HEADER_SELECTOR) : null;
        press = header ? { x: e.clientX, y: e.clientY, onHandle: isHandle(e.target) } : null;
    }

    function onUp(e) {
        if (!press) return;
        const moved = Math.abs(e.clientX - press.x) > DRAG_THRESHOLD_PX
            || Math.abs(e.clientY - press.y) > DRAG_THRESHOLD_PX;
        if (press.onHandle || moved) {
            suppressUntil = performance.now() + SUPPRESS_WINDOW_MS;
        }
        press = null;
    }

    // Pointer events, with mouse events as a fallback — both are harmless if
    // both fire, since a press only resets and an up only extends the window.
    window.addEventListener('pointerdown', onDown, true);
    window.addEventListener('mousedown', function (e) { if (!press) onDown(e); }, true);
    window.addEventListener('pointerup', onUp, true);
    window.addEventListener('mouseup', onUp, true);

    window.addEventListener('click', function (e) {
        const target = e.target instanceof Element ? e.target : null;
        if (!target || !target.closest(HEADER_SELECTOR)) return;
        // A click on a resize handle only ever resizes.
        const onHandle = isHandle(target);
        const afterDrag = performance.now() < suppressUntil;
        if (!onHandle && !afterDrag) return;
        suppressUntil = 0;
        e.stopImmediatePropagation();
        e.preventDefault();
    }, true);

    window.MMIResizeSortGuard = { DRAG_THRESHOLD_PX, HEADER_SELECTOR, HANDLE_SELECTOR };
}());
