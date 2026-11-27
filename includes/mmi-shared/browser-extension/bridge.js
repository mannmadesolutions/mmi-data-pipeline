/**
 * The extension's shell: the bridge on the store's admin pages (the manifest's for a copy downloaded from the store,
 * registered by the service worker otherwise, or put on the store's Chrome extension page by the toolbar button).
 *
 * The page and the extension talk with window messages here. On the Chrome extension page (admin.php?page=
 * mmi-chrome-extension, browser-extension-page.js) the page asks whether the extension is installed and connected
 * (ping), hands it a new key after Connect (pair) and tells it when this browser was disconnected (forget). A part adds
 * its own messages from its bridge-part.js, loaded after this file: window.__mmiExtBridge.on( op, handler ), where the
 * handler gets the page's message and the bridge's helpers ({ post, ask }). Only messages from this same page are taken.
 *
 * After the extension is installed, updated or reloaded, the service worker puts this script back into the page
 * (Chrome leaves the old copy cut off from the extension): the old copy stops answering, the new one announces itself.
 */
( function () {
	'use strict';
	const CONNECT_PAGE = [ 'mmi-chrome-extension', 'mmi-product-extension' ].includes( new URLSearchParams( location.search ).get( 'page' ) );
	const TAG = 'mmiExt';
	/** False once the extension this copy came from was updated or reloaded (its runtime is gone). */
	const alive = () => {
		try {
			return !! ( chrome.runtime && chrome.runtime.id );
		} catch ( e ) {
			return false;
		}
	};
	// One live copy per page: a copy left from before the extension reloaded can't reach it and steps aside.
	if ( window.__mmiExtBridgeAlive && window.__mmiExtBridgeAlive() ) {
		return;
	}
	window.__mmiExtBridgeAlive = alive;
	const post = ( data ) => window.postMessage( Object.assign( { [ TAG ]: true, from: 'extension' }, data ), location.origin );
	const ask = ( msg ) => chrome.runtime.sendMessage( msg ).catch( () => null );
	/** A part's ops (bridge-part.js): op → handler( message, { post, ask } ). A part loaded again replaces its own. */
	const handlers = new Map();
	window.__mmiExtBridge = { on: ( op, fn ) => handlers.set( String( op ), fn ), post, ask, connectPage: CONNECT_PAGE };

	async function announce() {
		const s = await ask( { type: 'status' } );
		if ( s ) {
			post( Object.assign( { op: 'here' }, s ) );
		}
	}
	window.addEventListener( 'message', async function listen( e ) {
		const d = e.data;
		if ( e.source !== window || e.origin !== location.origin || ! d || d[ TAG ] !== true || d.from !== 'page' ) {
			return;
		}
		if ( ! alive() ) {
			window.removeEventListener( 'message', listen ); // a newer copy answers instead
			return;
		}
		if ( d.op === 'ping' ) {
			announce();
		} else if ( CONNECT_PAGE && d.op === 'pair' ) {
			const r = await ask( { type: 'pair', token: d.token, id: d.id, rest: String( d.rest || '' ), shell: String( d.shell || '' ) } );
			post( { op: 'paired', ok: !! ( r && r.ok ), user: r && r.user || '', message: r && r.message || '', id: d.id } );
		} else if ( CONNECT_PAGE && d.op === 'forget' ) {
			await ask( { type: 'forget', id: d.id } );
			announce();
		} else if ( handlers.has( String( d.op ) ) ) {
			handlers.get( String( d.op ) )( d, { post, ask } );
		}
	} );
	announce();
}() );
