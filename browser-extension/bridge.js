/**
 * MMI Product Data: the bridge on the store's admin pages (the manifest's for a copy downloaded from the store,
 * registered by the service worker otherwise, or put on the store's Chrome extension page by the toolbar button).
 *
 * The page and the extension talk with window messages here. On the Chrome extension page
 * (admin.php?page=mmi-product-extension, ext-connect.js) the page asks whether the extension is installed and
 * connected, hands it a new token after Connect, and tells it when this browser was disconnected. On any admin page a
 * site's Send (brand-sites.js) asks it to open and send a site, or to stop, and hears how the send goes (sendsay).
 * On the Chrome extension page the Reference library section (library.js) asks which reference sites Chrome lets
 * the extension onto (libstate) and asks Chrome for one (liballow).
 * Only messages from this same page are taken.
 *
 * After the extension is installed, updated or reloaded, the service worker puts this script back into the page
 * (Chrome leaves the old copy cut off from the extension): the old copy stops answering, the new one announces itself.
 */
( function () {
	'use strict';

	const CONNECT_PAGE = new URLSearchParams( location.search ).get( 'page' ) === 'mmi-product-extension';
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
		} else if ( d.op === 'send' ) {
			const r = await ask( { type: 'opensend', host: String( d.host || '' ) } );
			post( { op: 'sending', host: d.host, ok: !! ( r && r.ok ), allowed: !! ( r && r.data && r.data.allowed ), message: ( r && r.data && r.data.message ) || '' } );
		} else if ( d.op === 'sendstop' ) {
			ask( { type: 'sendstop', host: String( d.host || '' ) } );
		} else if ( ! CONNECT_PAGE ) {
			return;
		} else if ( d.op === 'pair' ) {
			const r = await ask( { type: 'pair', token: d.token, id: d.id, rest: String( d.rest || '' ) } );
			post( { op: 'paired', ok: !! ( r && r.ok ), user: r && r.user || '', message: r && r.message || '', id: d.id } );
		} else if ( d.op === 'forget' ) {
			await ask( { type: 'forget', id: d.id } );
			announce();
		} else if ( d.op === 'libstate' ) {
			const r = await ask( { type: 'libstate' } );
			post( { op: 'libstate', paired: !! ( r && r.paired ), allowed: ( r && r.allowed ) || {} } );
		} else if ( d.op === 'liballow' ) {
			ask( { type: 'liballow', site: String( d.site || '' ) } );
		}
	} );

	chrome.runtime.onMessage.addListener( ( m ) => {
		if ( m && m.type === 'sendsay' && alive() ) {
			post( { op: 'sendsay', host: m.host, text: m.text, kind: m.kind, done: m.done } );
		}
	} );

	announce();
}() );
