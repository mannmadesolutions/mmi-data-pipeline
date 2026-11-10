/**
 * MMI Product Data: the page as Chrome shows it, for the page store (window.__mmiExtPageHtml). Shared by the
 * panel (Store this page, Write to this product), the picture picker and the live mapper's Test and Save, so every
 * copy sent leaves the same things out.
 *
 * Scripts out (JSON-LD kept: it is data), styles kept (the stored view draws them), the pictures Chrome chose written
 * in, video embeds kept, other frames out, the extension's own boxes out. Form values and account-area email
 * addresses are removed, so nothing of the owner's own session is sent.
 */
( function () {
	'use strict';

	if ( window.__mmiExtPageHtml ) {
		return;
	}

	/** Bot checks and challenge pages (send-runner.js WALL_RE): never stored. */
	const WALL_RE = /_Incapsula_Resource|Incapsula incident ID|px-captcha|captcha-delivery\.com|DataDome|challenge-platform|cf-chl-|<title>Just a moment/i;
	const WALL_BYTES = 200000; // a real product page is larger

	const OWN_BOXES = 'mmi-product-data-panel, mmi-product-data-picker, #mmpt-live-mapper';
	const VIDEO_SRC = /youtube(?:-nocookie)?\.com\/(?:embed|watch)|youtu\.be\/|player\.vimeo\.com\/video|vimeo\.com\/\d/i; // map-select.js VIDEO_SRC
	const EMAIL_RE = /[^\s@<>"']+@[^\s@<>"']+\.[a-z]{2,}/gi;
	const ACCOUNT_AREAS = '[class*="account" i], [id*="account" i], [class*="user" i], [id*="user" i], [class*="customer" i], [class*="profile" i]';

	window.__mmiExtPageHtml = function () {
		const clone = document.documentElement.cloneNode( true );
		const live = Array.from( document.images );
		const copies = Array.from( clone.querySelectorAll( 'img' ) );
		live.forEach( ( img, i ) => {
			const c = copies[ i ];
			const src = ( c && c.getAttribute( 'src' ) ) || '';
			if ( c && img.currentSrc && ( ! src || src.startsWith( 'data:' ) ) ) {
				c.setAttribute( 'src', img.currentSrc ); // lazy pictures: what the browser actually loaded
			}
		} );
		clone.querySelectorAll( OWN_BOXES + ', script:not([type="application/ld+json"]), noscript, template, svg' ).forEach( ( e ) => e.remove() );
		clone.querySelectorAll( 'iframe' ).forEach( ( f ) => VIDEO_SRC.test( f.getAttribute( 'src' ) || '' ) || f.remove() );
		clone.querySelectorAll( 'input[type="password"], input[type="email"], input[type="hidden"], input[type="tel"]' ).forEach( ( e ) => e.remove() );
		clone.querySelectorAll( 'input, textarea, select' ).forEach( ( e ) => {
			e.removeAttribute( 'value' );
			e.removeAttribute( 'checked' );
			if ( e.tagName === 'TEXTAREA' ) {
				e.textContent = '';
			}
		} );
		clone.querySelectorAll( ACCOUNT_AREAS ).forEach( ( area ) => {
			const walk = document.createTreeWalker( area, NodeFilter.SHOW_TEXT );
			for ( let t = walk.nextNode(); t; t = walk.nextNode() ) {
				t.nodeValue = t.nodeValue.replace( EMAIL_RE, '' );
			}
		} );
		clone.querySelectorAll( '[href^="mailto:"]' ).forEach( ( a ) => a.closest( ACCOUNT_AREAS ) && a.removeAttribute( 'href' ) );
		return `<!DOCTYPE html>${ clone.outerHTML }`;
	};

	/** The copy is a bot check, not the page (the server refuses it as well). */
	window.__mmiExtIsWall = ( html ) => html.length <= WALL_BYTES && WALL_RE.test( html );
}() );
