/**
 * MMI Product Data: the live mapper's bridge inside the extension. live-mapper.js (bundled from the Product Data
 * module's assets/js with map-select.js) asks through
 * window.mmptLiveMapperBridge: here its requests go to the service worker, which signs in with this browser's key
 * and calls /ext/map. Hello, tests and saves send the page as the extension copies it (page-copy.js): hello so the
 * server can tell which layout an unstored page is (plan §13 S2). After a save, the
 * panel reloads to show what the new part gives the product; closing the mapper opens the panel again. Opened from a
 * panel row (protocol 6), it starts on that row's part alone, picking; for pictures, videos and audio it offers the
 * one-off picker for that product (`single`), which no longer has a button of its own (protocol 9).
 */
( function () {
	'use strict';

	const STORE = window.__mmiExtStore || {}; // the store the extension is connected to (panel.js, from the service worker)
	const MESSAGES = {
		failed: 'The store didn’t answer. Check the connection and try again.',
		reloaded: 'The extension was reloaded. Reload this page, then click the button again.',
	};

	if ( window.mmptLiveMapper ) {
		window.mmptLiveMapper.show( ( window.__mmiExtPickFor || {} ).part || '' ); // already open: the row clicked now
		return;
	}
	const panel = () => window.__mmiExtPanel;
	window.mmptLiveMapperBridge = {
		origin: STORE.base || STORE.origin || '',
		part: ( window.__mmiExtPickFor || {} ).part || '', // the panel row clicked: the mapper opens on that part, picking
		ask( op, payload ) {
			const page = op === 'hello' ? { html: window.__mmiExtPageHtml() } : {}; // the server finds this page's layout from it when it has no stored copy
			return chrome.runtime.sendMessage( { type: 'map', op, url: location.href, ...page, ...payload } )
				.then( ( res ) => ( res && res.ok ? { ok: true, data: res.data } : { ok: false, message: ( res && res.data && res.data.message ) || MESSAGES.failed } ) )
				.catch( () => ( { ok: false, message: MESSAGES.reloaded } ) );
		},
		pageHtml: () => window.__mmiExtPageHtml(),
		saved: () => panel() && panel().refresh(),
		closed: () => panel() && panel().unfold(),
		/** Only with a product in hand: leaves the mapper for one-off picks for that product. The panel row is the way in to both. */
		single: ( window.__mmiExtPickFor || {} ).product ? ( part ) => {
			window.mmptLiveMapperBridge.closed = () => panel() && panel().pick( part );
			window.mmptLiveMapper.close();
		} : null,
	};
}() );
