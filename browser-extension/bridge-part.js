/**
 * Product Data's part of the bridge on the store's admin pages (after the shell's bridge.js, which gives
 * window.__mmiExtBridge). On any admin page a site's Send (brand-sites.js) asks it to open and send a site, or to stop,
 * and hears how the send goes (sendsay); a maker site's ↗ in the Product Workbench's Makers (overview) asks it to open
 * the site with the panel on its overview, and a map step there (pick) to open one of the site's pages with the mapper
 * on the step's part. On the Chrome extension page the Reference library section (library.js) asks which reference
 * sites Chrome lets the extension onto (libstate) and asks Chrome for one (liballow).
 */
( function () {
	'use strict';
	const B = window.__mmiExtBridge;
	if ( ! B ) {
		return;
	}
	const said = ( r ) => ( { ok: !! ( r && r.ok ), allowed: !! ( r && r.data && r.data.allowed ), message: ( r && r.data && r.data.message ) || '' } );
	B.on( 'send', async ( d, { post, ask } ) => {
		const r = await ask( { type: 'opensend', host: String( d.host || '' ) } );
		post( Object.assign( { op: 'sending', host: d.host }, said( r ) ) );
	} );
	B.on( 'overview', async ( d, { post, ask } ) => {
		const r = await ask( { type: 'openoverview', host: String( d.host || '' ) } );
		post( Object.assign( { op: 'overviewing', host: d.host }, said( r ) ) );
	} );
	B.on( 'pick', async ( d, { post, ask } ) => {
		const r = await ask( { type: 'openpick', url: String( d.url || '' ), part: String( d.part || '' ), scope: String( d.scope || '' ) } );
		post( Object.assign( { op: 'picking', url: d.url }, said( r ) ) );
	} );
	B.on( 'sendstop', ( d, { ask } ) => {
		ask( { type: 'sendstop', host: String( d.host || '' ) } );
	} );
	if ( B.connectPage ) {
		B.on( 'libstate', async ( d, { post, ask } ) => {
			const r = await ask( { type: 'libstate' } );
			post( { op: 'libstate', paired: !! ( r && r.paired ), allowed: ( r && r.allowed ) || {} } );
		} );
		B.on( 'liballow', ( d, { ask } ) => {
			ask( { type: 'liballow', site: String( d.site || '' ) } );
		} );
	}
	const live = window.__mmiExtSendsayAlive;
	if ( ! live || ! live() ) { // one listener a page from a live copy: one left from before a reload can't hear any more
		window.__mmiExtSendsayAlive = window.__mmiExtBridgeAlive;
		chrome.runtime.onMessage.addListener( ( m ) => {
			const now = window.__mmiExtBridge;
			if ( m && m.type === 'sendsay' && now ) {
				now.post( { op: 'sendsay', host: m.host, text: m.text, kind: m.kind, done: m.done } );
			}
		} );
	}
}() );
