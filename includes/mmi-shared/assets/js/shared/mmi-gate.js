/**
 * MMI Feature Gate — dismissible popover for a locked `.mmi-gate-badge`
 *
 * Pairs with `MMI_Feature_Gate::render_locked()` (class-feature-gate.php) and
 * `.mmi-gated-feature`/`.mmi-gate-badge`/`.mmi-gate-popover` in
 * mmi-suite-common.css. `[hidden]` is the only thing that gates the popover's
 * visibility — never toggled via display/visibility directly (AGENTS.md's
 * "[hidden] Attribute vs. a Class That Sets display" rule) — so any CSS rule
 * that sets `display` on `.mmi-gate-popover` must be written as
 * `.mmi-gate-popover:not([hidden])`, matching mmi-suite-common.css.
 *
 * One popover open at a time; dismissed on outside click, Escape, or opening
 * a different badge. Delegated at the document level, wired once per page.
 *
 * @package MannMade\SharedLib
 */

window.MMIGate = (function ($) {
	'use strict';

	const SELECTORS = {
		BADGE: '.mmi-gate-badge',
		POPOVER: '.mmi-gate-popover',
	};

	let inited = false;
	let openPopoverEl = null;

	function popoverFor(badgeEl) {
		const next = badgeEl.nextElementSibling;
		return next && next.matches(SELECTORS.POPOVER) ? next : null;
	}

	function close() {
		if (!openPopoverEl) {
			return;
		}
		openPopoverEl.setAttribute('hidden', '');
		openPopoverEl = null;
		document.removeEventListener('keydown', onKeydown);
		document.removeEventListener('click', onDocumentClick, true);
	}

	function open(badgeEl) {
		const popoverEl = popoverFor(badgeEl);
		if (!popoverEl) {
			return;
		}
		if (openPopoverEl && openPopoverEl !== popoverEl) {
			close();
		}
		popoverEl.removeAttribute('hidden');
		openPopoverEl = popoverEl;
		document.addEventListener('keydown', onKeydown);
		document.addEventListener('click', onDocumentClick, true);
	}

	function toggle(badgeEl) {
		const popoverEl = popoverFor(badgeEl);
		if (popoverEl && popoverEl === openPopoverEl) {
			close();
		} else {
			open(badgeEl);
		}
	}

	function onKeydown(e) {
		if (e.key === 'Escape') {
			close();
		}
	}

	function onDocumentClick(e) {
		if (!openPopoverEl) {
			return;
		}
		const badgeEl = openPopoverEl.previousElementSibling;
		if (openPopoverEl.contains(e.target) || (badgeEl && badgeEl.contains(e.target))) {
			return;
		}
		close();
	}

	function init() {
		if (inited) {
			return;
		}
		inited = true;

		$(document).on('click', SELECTORS.BADGE, function (e) {
			e.stopPropagation();
			toggle(this);
		});
		$(document).on('keydown', SELECTORS.BADGE, function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				toggle(this);
			}
		});
	}

	return { init: init, open: open, close: close };
})(jQuery);

jQuery(function () {
	window.MMIGate.init();
});
