/**
 * MMI Feature Gate — tooltip for locked cross-plugin features.
 *
 * Triggers are any element carrying `data-gate-slug` with one of the trigger
 * classes below, rendered by MMI_Feature_Gate (class-feature-gate.php):
 *   - `.mmi-gated-feature--overlay` — an element greyed in place (overlay()).
 *   - `.mmi-gate-badge`             — padlock for a locked panel (render_locked()).
 *   - `.mmi-works-with__chip`       — header strip chip (render_works_with()).
 * Rows built in JS use MMIGate.overlayHtml() for the same markup.
 *
 * The tooltip is one element appended to <body> and positioned with fixed
 * coordinates (CSS custom properties, never inline style rules), so a table's
 * scroll wrapper can't clip it. It opens on hover (short delay), focus, or a
 * click/tap; it stays open while the pointer moves into it so its link can be
 * clicked; Escape or a click elsewhere closes it. Copy comes from the
 * localized `mmiGateData` (plugins + features), never from markup.
 *
 * @package MannMade\SharedLib
 */

window.MMIGate = (function ($) {
	'use strict';

	const SELECTORS = {
		TRIGGER: '.mmi-gated-feature--overlay[data-gate-slug], .mmi-gate-badge[data-gate-slug], .mmi-works-with__chip[data-gate-slug]',
	};
	const CLASSES = {
		POPOVER: 'mmi-gate-popover',
		ABOVE: 'mmi-gate-popover--above',
		OPEN_TRIGGER: 'mmi-gate-is-open',
	};
	const TIMING = {
		OPEN_DELAY_MS: 120,
		CLOSE_DELAY_MS: 220,
	};
	const LAYOUT = {
		GAP_PX: 8,
		EDGE_PX: 8,
	};

	const data = window.mmiGateData || { plugins: {}, features: {}, i18n: {} };
	const i18n = data.i18n || {};

	let popoverEl = null;
	let openTrigger = null;
	let pinned = false;
	let openTimer = 0;
	let closeTimer = 0;
	let hoverSuppressed = false;
	let restoringFocus = false;
	let inited = false;

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function format(template, value) {
		return String(template || '%s').replace('%s', value);
	}

	function plugin(slug) {
		return (data.plugins && data.plugins[slug]) || { label: slug, short: slug, product_url: '', available: false };
	}

	function feature(id) {
		return (data.features && data.features[id]) || null;
	}

	/* ── Public markup helpers ────── */

	/** True when the plugin is active and not being previewed as locked. */
	function isAvailable(slug) {
		return !!plugin(slug).available;
	}

	/**
	 * Same markup as MMI_Feature_Gate::overlay(): innerHtml (already escaped)
	 * greyed in place with a lock, or returned unchanged when the feature's
	 * plugin is available.
	 */
	function overlayHtml(featureId, innerHtml, block) {
		const f = feature(featureId);
		if (!f || isAvailable(f.provider)) {
			return innerHtml;
		}
		const tag = block ? 'div' : 'span';
		const label = `${f.title} — ${format(i18n.requiresAria, plugin(f.provider).label)}`;
		return `<${tag} class="mmi-gated-feature mmi-gated-feature--overlay${block ? ' mmi-gated-feature--block' : ''} mmi-is-locked"`
			+ ` data-gate-slug="${escapeHtml(f.provider)}" data-gate-feature="${escapeHtml(featureId)}"`
			+ ` tabindex="0" role="button" aria-label="${escapeHtml(label)}">`
			+ `<${tag} class="mmi-gated-feature__content" inert>${innerHtml}</${tag}>`
			+ '<span class="mmi-gate-lock dashicons dashicons-lock" aria-hidden="true"></span>'
			+ `</${tag}>`;
	}

	/* ── Tooltip ────── */

	function ensurePopover() {
		if (popoverEl) {
			return popoverEl;
		}
		popoverEl = document.createElement('div');
		popoverEl.className = CLASSES.POPOVER;
		popoverEl.setAttribute('role', 'tooltip');
		popoverEl.id = 'mmi-gate-popover';
		popoverEl.hidden = true;
		popoverEl.addEventListener('mouseenter', cancelClose);
		popoverEl.addEventListener('mouseleave', scheduleClose);
		popoverEl.addEventListener('focusout', function (e) {
			if (!popoverEl.contains(e.relatedTarget) && e.relatedTarget !== openTrigger) {
				scheduleClose();
			}
		});
		document.body.appendChild(popoverEl);
		return popoverEl;
	}

	function featureItemHtml(f) {
		return `<li><strong>${escapeHtml(f.title)}</strong> — ${escapeHtml(f.pitch)}</li>`;
	}

	function contentFor(trigger) {
		const slug = trigger.getAttribute('data-gate-slug');
		const p = plugin(slug);
		const consumer = trigger.getAttribute('data-gate-consumer');
		const featureId = trigger.getAttribute('data-gate-feature');
		const label = trigger.getAttribute('data-gate-label');
		let body = '';

		if (consumer) {
			// Header chip: everything this plugin adds to the page's plugin.
			const items = Object.keys(data.features || {})
				.map(feature)
				.filter(function (f) { return f && f.provider === slug && f.consumers.indexOf(consumer) !== -1; });
			body = `<p>${escapeHtml(format(p.available ? i18n.activeAdds : i18n.adds, p.label))}</p>`
				+ `<ul class="mmi-gate-popover__list">${items.map(featureItemHtml).join('')}</ul>`;
		} else {
			const f = featureId ? feature(featureId) : null;
			const title = f ? f.title : label;
			body = `<p class="mmi-gate-popover__title">${escapeHtml(title)}</p>`
				+ (f ? `<p>${escapeHtml(f.pitch)}</p>` : '');
		}

		if (!p.available) {
			body += `<p class="mmi-gate-popover__requires">${escapeHtml(format(i18n.requires, p.label))}</p>`;
			if (p.product_url) {
				body += `<a class="mmi-gate-cta" href="${escapeHtml(p.product_url)}" target="_blank" rel="noopener">${escapeHtml(format(i18n.get, p.label))} →</a>`;
			}
		}
		return body;
	}

	function position(trigger) {
		const rect = trigger.getBoundingClientRect();
		const pop = popoverEl.getBoundingClientRect();
		const viewW = document.documentElement.clientWidth;
		const viewH = document.documentElement.clientHeight;

		let left = rect.left;
		left = Math.min(left, viewW - pop.width - LAYOUT.EDGE_PX);
		left = Math.max(left, LAYOUT.EDGE_PX);

		let top = rect.bottom + LAYOUT.GAP_PX;
		const above = top + pop.height > viewH - LAYOUT.EDGE_PX && rect.top - pop.height - LAYOUT.GAP_PX > LAYOUT.EDGE_PX;
		if (above) {
			top = rect.top - pop.height - LAYOUT.GAP_PX;
		}
		popoverEl.classList.toggle(CLASSES.ABOVE, above);
		popoverEl.style.setProperty('--mmi-gate-x', `${Math.round(left)}px`);
		popoverEl.style.setProperty('--mmi-gate-y', `${Math.round(top)}px`);
	}

	function open(trigger, pin) {
		clearTimeout(openTimer);
		cancelClose();
		ensurePopover();
		if (openTrigger && openTrigger !== trigger) {
			openTrigger.classList.remove(CLASSES.OPEN_TRIGGER);
			openTrigger.removeAttribute('aria-describedby');
		}
		openTrigger = trigger;
		pinned = !!pin;
		popoverEl.innerHTML = contentFor(trigger);
		popoverEl.hidden = false;
		position(trigger);
		trigger.classList.add(CLASSES.OPEN_TRIGGER);
		trigger.setAttribute('aria-describedby', popoverEl.id);
	}

	function close() {
		clearTimeout(openTimer);
		cancelClose();
		if (!popoverEl || popoverEl.hidden) {
			return;
		}
		popoverEl.hidden = true;
		if (openTrigger) {
			openTrigger.classList.remove(CLASSES.OPEN_TRIGGER);
			openTrigger.removeAttribute('aria-describedby');
		}
		openTrigger = null;
		pinned = false;
	}

	function scheduleOpen(trigger) {
		if (hoverSuppressed) {
			return;
		}
		clearTimeout(openTimer);
		openTimer = setTimeout(function () { open(trigger, false); }, TIMING.OPEN_DELAY_MS);
	}

	function scheduleClose() {
		clearTimeout(openTimer);
		if (pinned) {
			return;
		}
		cancelClose();
		closeTimer = setTimeout(close, TIMING.CLOSE_DELAY_MS);
	}

	function cancelClose() {
		clearTimeout(closeTimer);
	}

	function init() {
		if (inited) {
			return;
		}
		inited = true;

		$(document)
			.on('mouseenter', SELECTORS.TRIGGER, function () {
				if (openTrigger === this) {
					cancelClose();
					return;
				}
				if (!pinned) {
					scheduleOpen(this);
				}
			})
			.on('mouseleave', SELECTORS.TRIGGER, scheduleClose)
			.on('focusin', SELECTORS.TRIGGER, function () {
				if (restoringFocus) {
					restoringFocus = false;
					return;
				}
				if (openTrigger !== this) {
					open(this, false);
				}
			})
			.on('focusout', SELECTORS.TRIGGER, function (e) {
				if (!popoverEl || !popoverEl.contains(e.relatedTarget)) {
					scheduleClose();
				}
			})
			.on('click', SELECTORS.TRIGGER, function (e) {
				// A locked element never does its normal job; the click pins
				// (or unpins) the tooltip instead.
				e.preventDefault();
				e.stopPropagation();
				if (openTrigger === this && pinned) {
					close();
				} else {
					open(this, true);
				}
			})
			.on('keydown', SELECTORS.TRIGGER, function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					open(this, true);
				}
			});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && openTrigger) {
				const trigger = openTrigger;
				close();
				// Whatever is under the pointer now (often the next row's
				// lock) must not reopen it: hover waits for the pointer to move.
				hoverSuppressed = true;
				document.addEventListener('mousemove', function () { hoverSuppressed = false; }, { once: true });
				if (document.activeElement !== trigger) {
					restoringFocus = true;
					trigger.focus({ preventScroll: true });
				}
			}
		});
		document.addEventListener('click', function (e) {
			if (!openTrigger || (popoverEl && popoverEl.contains(e.target)) || openTrigger.contains(e.target)) {
				return;
			}
			close();
		}, true);
		// The tooltip is fixed-position: follow the trigger, or close when it
		// scrolls out of view.
		window.addEventListener('scroll', function () {
			if (!openTrigger) {
				return;
			}
			if (!document.body.contains(openTrigger)) {
				close();
				return;
			}
			const rect = openTrigger.getBoundingClientRect();
			if (rect.bottom < 0 || rect.top > document.documentElement.clientHeight) {
				close();
			} else {
				position(openTrigger);
			}
		}, true);
		window.addEventListener('resize', function () {
			if (openTrigger) {
				position(openTrigger);
			}
		});
	}

	return {
		init: init,
		open: open,
		close: close,
		isAvailable: isAvailable,
		overlayHtml: overlayHtml,
	};
})(jQuery);

jQuery(function () {
	window.MMIGate.init();
});
