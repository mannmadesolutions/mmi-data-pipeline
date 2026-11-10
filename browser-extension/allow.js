/**
 * MMI Product Data: "Keep it open as you browse" (sw.js askAllow()). Chrome only asks for a site's permission
 * after a click in one of the extension's own pages, so this small window holds the click. Allowed, the panel comes
 * back on each page of the site while it is open; nothing runs there unless the panel is.
 */
( function () {
	'use strict';

	const o = new URLSearchParams( location.search ).get( 'o' ) || '';
	const host = o.replace( /^https?:\/\//, '' ).replace( /\/\*$/, '' );
	const said = document.getElementById( 'said' );
	document.getElementById( 'yes' ).textContent = `Allow on ${ host }`;
	document.getElementById( 'what' ).textContent = `Chrome asks next. Once allowed, the Product Data panel comes back on each page of ${ host } you open while it is open, until you close it. Nothing runs on the site unless the panel is open.`;
	document.getElementById( 'no' ).addEventListener( 'click', () => window.close() );
	document.getElementById( 'yes' ).addEventListener( 'click', async () => {
		if ( ! /^https?:\/\/[^/]+\/\*$/.test( o ) ) {
			said.textContent = 'That isn’t a site address.';
			return;
		}
		const yes = await chrome.permissions.request( { origins: [ o ] } ).catch( () => false );
		if ( yes ) {
			const s = await chrome.storage.local.get( 'declined' );
			chrome.storage.local.set( { declined: ( s.declined || [] ).filter( ( x ) => x !== o ) } );
		}
		said.textContent = yes ? 'Allowed. This window closes now.' : 'Not allowed. The panel opens from the toolbar button on each page.';
		window.setTimeout( () => window.close(), yes ? 1200 : 2500 );
	} );
}() );
