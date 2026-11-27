/**
 * The extension's colours, in one place (owner 2026-11-15: "Change all native WordPress CSS styling to
 * MMI styling '#00591a'"). WordPress's admin blue (#2271b1 and its shades) was the panel's, the mapper's and the
 * picker's accent; MMI green replaces it, kept dark enough for small text on white (#00591a: 8.9:1).
 *
 * Every surface reads these as CSS custom properties: the panel (panel.js), the live mapper (live-mapper.js), the dock
 * controls (dock.js) and the picture picker (pictures.js) put CSS at the top of their shadow root's style sheet; the
 * extension's own pages (connect.html, allow.html) load this file, which adds it to their <head>. A colour that has to
 * be a plain value (a page element's outline, set from a script) is `TOKENS`.
 */
( function () {
	'use strict';

	if ( window.__mmiExtTheme ) {
		return;
	}

	const TOKENS = {
		accent: '#00591a',       // links, buttons, the chosen tab, focus rings
		accentHover: '#00420f',  // a primary button under the pointer
		accentInk: '#003d12',    // text on a green tint (chips, captions)
		accentLine: '#8fbf9c',   // borders on a tint, a hovered row's outline
		accentSoft: '#eef6ef',   // a hovered or open row, notices, progress boxes
		accentTint: '#d9eedd',   // chips that say something changes
		accentTrack: '#c8e3ce',  // a progress bar's empty part
		accentGrip: 'rgba(0,89,26,.35)', // the dock's resize edge, under the pointer
		peek: '#1f8a3b',         // the outline drawn on the page round what a row reads
		peekFill: 'rgba(31,138,59,.12)',
		darkAccent: '#7fc28f',   // the accent on the extension's own pages in dark mode
	};

	const CSS = `
		:host, :root {
			--mmi-accent: ${ TOKENS.accent };
			--mmi-accent-hover: ${ TOKENS.accentHover };
			--mmi-accent-ink: ${ TOKENS.accentInk };
			--mmi-accent-line: ${ TOKENS.accentLine };
			--mmi-accent-soft: ${ TOKENS.accentSoft };
			--mmi-accent-tint: ${ TOKENS.accentTint };
			--mmi-accent-track: ${ TOKENS.accentTrack };
			--mmi-accent-grip: ${ TOKENS.accentGrip };
			--mmi-peek: ${ TOKENS.peek };
			--mmi-peek-fill: ${ TOKENS.peekFill };
			--mmi-accent-dark-mode: ${ TOKENS.darkAccent };
		}
	`;

	window.__mmiExtTheme = { CSS, TOKENS };

	if ( location.protocol === 'chrome-extension:' && document.head ) { // connect.html, allow.html
		const style = document.createElement( 'style' );
		style.textContent = CSS;
		document.head.prepend( style );
	}
}() );
