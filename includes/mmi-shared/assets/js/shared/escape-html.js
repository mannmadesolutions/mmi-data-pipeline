/**
 * Canonical HTML-content escape, shared across the MMI Suite.
 *
 * Was previously reimplemented independently as escapeHtml()/escHtml()/esc()
 * in ~15 files across 8 plugins, with inconsistent falsy-input handling (some
 * returned '', some produced the literal string "undefined", one — a plain
 * regex .replace() with no guard — threw on null/undefined) and inconsistent
 * completeness (some skipped quote or apostrophe escaping). This is the one
 * place that behavior is now decided; every plugin's local escapeHtml()/
 * escHtml()/esc() delegates to it so a future correction only has to happen
 * here.
 *
 * Deliberately NOT consolidated with escAttr() found during the same audit —
 * that name covers two genuinely different jobs in different plugins (a
 * quote-only partial escape in one, a full content escape in another) and
 * merging them would silently change behavior at call sites nobody has
 * re-verified against.
 */
window.MMIEscapeHtml = function (str) {
    if (str === null || str === undefined) {
        return '';
    }
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};
