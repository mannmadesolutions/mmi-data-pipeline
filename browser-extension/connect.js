/**
 * MMI Product Data: which store the extension works with (connect.html; also its Options page). A copy downloaded
 * from a store's Chrome extension page already knows its store; a copy from the Chrome Web Store asks here. Chrome is
 * asked for the site while the click still counts, then the service worker puts the bridge on the store's admin pages
 * (connectto) and the store's Chrome extension page opens, where the key is handed over.
 */
( function () {
	'use strict';

	const MESSAGES = {
		now: 'Connected to %h as %u. To use another store, enter its address: connecting there moves the extension to it.',
		known: 'This copy works with %h. Continue opens its Chrome extension page, or enter another store’s address.',
		bad: 'That isn’t a web address. Enter the address of your store’s home page, such as https://your-store.com.',
		denied: 'Chrome wasn’t allowed to let the extension reach %h, so it can’t connect there. Press Continue and allow it.',
		opening: 'Opening %h…',
		failed: 'The extension couldn’t get ready for %h. Reload this page and try again.',
	};
	const $ = ( id ) => document.getElementById( id );
	const said = $( 'said' );
	const field = $( 'url' );
	const say = ( text, bad ) => {
		said.textContent = text;
		said.classList.toggle( 'bad', !! bad );
	};

	/** The address typed, as a URL (https:// added when there is no scheme), or null. */
	function typed() {
		const raw = field.value.trim();
		if ( raw === '' ) {
			return null;
		}
		try {
			const u = new URL( /^[a-z][a-z0-9+.-]*:\/\//i.test( raw ) ? raw : `https://${ raw }` );
			return /^https?:$/.test( u.protocol ) && u.hostname.includes( '.' ) ? u : null;
		} catch ( e ) {
			return null;
		}
	}

	chrome.runtime.sendMessage( { type: 'status' } ).then( ( s ) => {
		if ( ! s || ! s.store ) {
			return;
		}
		const host = new URL( s.store ).hostname;
		$( 'now' ).textContent = ( s.paired ? MESSAGES.now : MESSAGES.known ).replace( '%h', host ).replace( '%u', s.user || '' );
		field.value = s.store;
	} ).catch( () => {} );

	$( 'form' ).addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		const u = typed();
		if ( ! u ) {
			say( MESSAGES.bad, true );
			return;
		}
		// Asked at once, while Chrome still counts the click.
		chrome.permissions.request( { origins: [ `${ u.origin }/*` ] } ).then( async ( yes ) => {
			if ( ! yes ) {
				say( MESSAGES.denied.replace( '%h', u.hostname ), true );
				return;
			}
			const r = await chrome.runtime.sendMessage( { type: 'connectto', url: u.href } ).catch( () => null );
			if ( ! r || ! r.ok || ! r.data || ! r.data.connect ) {
				say( MESSAGES.failed.replace( '%h', u.hostname ), true );
				return;
			}
			say( MESSAGES.opening.replace( '%h', u.hostname ) );
			location.href = r.data.connect;
		}, () => say( MESSAGES.denied.replace( '%h', u.hostname ), true ) );
	} );
}() );
