/**
 * The Chrome extension page (ExtBridge::render()): finds the MMI Product Data extension in this browser,
 * connects it (POST /ext/pair, then the token and this store's route address go to the extension through its
 * bridge.js on this page: the extension is then this store's) and lists
 * the connected browsers with Disconnect. A store's own parts of the page (an updater for unpacked copies) bring
 * their own script.
 *
 * A key handed out but not taken (the page was left mid-connect) is taken back as the page goes (keepalive), so no
 * "Not yet" row is left behind. The extension's bridge comes back by itself after an install or update (its service
 * worker puts it into this page), and announces itself again.
 *
 *   window.mmptExtConnect = { rest, nonce, protocol, version }
 */
( function () {
	'use strict';

	const CFG = window.mmptExtConnect;
	if ( ! CFG ) {
		return;
	}
	const TAG = 'mmiExt';
	const LIMITS = { pingMs: 500, pings: 12, pairMs: 15000 };
	const MESSAGES = {
		missing: 'The extension isn’t in this browser yet. Install it (step 1), then reload this page. Already installed from the Chrome Web Store? Click MMI Product Data in Chrome’s toolbar on this page.',
		found: 'Found MMI Product Data %v in this browser. Connect it to start.',
		connected: 'Connected as %u. Open a maker’s product page and click MMI Product Data in Chrome’s toolbar.',
		elsewhere: 'Found MMI Product Data %v, connected to %h. Connecting it here moves it to this store.',
		stale: 'This browser has version %v; %n is available. A copy from the Chrome Web Store updates by itself. A copy loaded by hand: download it and unzip it, remove the old card in chrome://extensions, Load unpacked the new folder (named with its version), then connect it here.',
		twoCopies: 'Two copies of the extension are loaded in this browser (%v). Remove the older card in chrome://extensions: both put a panel on every page.',
		connecting: 'Connecting…',
		noAnswer: 'The extension didn’t take the key. Reload this page and connect again.',
		failed: 'Connecting failed: %s',
		again: 'Connect again',
		connect: 'Connect this browser',
		none: 'No browser is connected.',
		thisOne: 'this browser',
		disconnect: 'Disconnect',
		never: 'Not yet',
		noVersion: 'Unknown',
	};

	const $ = ( id ) => document.getElementById( id );
	const btn = $( 'mmpt-ext-connect' );
	const status = $( 'mmpt-ext-status' );
	const list = $( 'mmpt-ext-tokens' );
	let ext = null; // what the extension last said about itself: { version, protocol, paired, tokenId, user, store }
	const copies = new Set(); // versions whose bridge answered on this page (two loaded copies both answer)
	let waiting = null; // a pairing waiting for the extension's answer

	const post = ( data ) => window.postMessage( Object.assign( { [ TAG ]: true, from: 'page' }, data ), location.origin );

	function api( route, method, body, keepalive ) {
		return window.fetch( CFG.rest + route, {
			method,
			keepalive: !! keepalive,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CFG.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( async ( r ) => {
			const d = await r.json().catch( () => ( {} ) );
			if ( ! r.ok ) {
				throw new Error( d.message || String( r.status ) );
			}
			return d;
		} );
	}

	function say( kind, text ) {
		status.className = 'notice inline notice-' + kind;
		const p = document.createElement( 'p' );
		p.textContent = text;
		status.replaceChildren( p );
	}

	/** Newer manifest version on the server than in this browser. */
	function older( have, want ) {
		const a = String( have ).split( '.' ).map( Number );
		const b = String( want ).split( '.' ).map( Number );
		for ( let i = 0; i < 3; i++ ) {
			if ( ( a[ i ] || 0 ) !== ( b[ i ] || 0 ) ) {
				return ( a[ i ] || 0 ) < ( b[ i ] || 0 );
			}
		}
		return false;
	}

	function paint() {
		if ( ! ext ) {
			btn.disabled = true;
			say( 'warning', MESSAGES.missing );
			return;
		}
		btn.disabled = false;
		const here = ext.paired && ( ! ext.store || ext.store === location.origin ); // an extension before 0.10 doesn't say which store
		btn.lastChild.textContent = ' ' + ( here ? MESSAGES.again : MESSAGES.connect );
		btn.classList.toggle( 'button-primary', ! here );
		if ( copies.size > 1 ) {
			say( 'warning', MESSAGES.twoCopies.replace( '%v', [ ...copies ].join( ', ' ) ) );
		} else if ( older( ext.version, CFG.version ) ) {
			say( 'warning', MESSAGES.stale.replace( '%v', ext.version ).replace( '%n', CFG.version ) );
		} else if ( ext.paired && ! here ) {
			say( 'info', MESSAGES.elsewhere.replace( '%v', ext.version ).replace( '%h', ext.store.replace( /^https?:\/\//, '' ) ) );
		} else if ( ext.paired ) {
			say( 'success', MESSAGES.connected.replace( '%u', ext.user || '' ) );
		} else {
			say( 'info', MESSAGES.found.replace( '%v', ext.version ) );
		}
	}

	function when( iso ) {
		if ( ! iso ) {
			return MESSAGES.never;
		}
		return new Date( iso.replace( ' ', 'T' ) + 'Z' ).toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
	}

	function rows( tokens ) {
		list.replaceChildren();
		if ( ! tokens.length ) {
			const tr = document.createElement( 'tr' );
			const td = document.createElement( 'td' );
			td.colSpan = 5;
			td.textContent = MESSAGES.none;
			tr.append( td );
			list.append( tr );
			return;
		}
		tokens.forEach( ( t ) => {
			const tr = document.createElement( 'tr' );
			const name = document.createElement( 'td' );
			name.textContent = t.label + ( ext && ext.tokenId === t.id ? ' (' + MESSAGES.thisOne + ')' : '' );
			const made = document.createElement( 'td' );
			made.textContent = when( t.created );
			const used = document.createElement( 'td' );
			used.textContent = when( t.used );
			const ver = document.createElement( 'td' );
			ver.textContent = t.version || MESSAGES.noVersion;
			const cell = document.createElement( 'td' );
			const b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'button button-small';
			b.textContent = MESSAGES.disconnect;
			b.addEventListener( 'click', () => {
				b.disabled = true;
				api( 'ext/revoke', 'POST', { id: t.id } )
					.then( ( d ) => {
						post( { op: 'forget', id: t.id } );
						rows( d.tokens );
					} )
					.catch( ( e ) => {
						b.disabled = false;
						say( 'error', e.message );
					} );
			} );
			cell.append( b );
			tr.append( name, made, used, ver, cell );
			list.append( tr );
		} );
	}

	const refresh = () => api( 'ext/tokens', 'GET' ).then( ( d ) => rows( d.tokens ) ).catch( ( e ) => say( 'error', e.message ) );

	window.addEventListener( 'message', ( e ) => {
		const d = e.data;
		if ( e.source !== window || e.origin !== location.origin || ! d || d[ TAG ] !== true || d.from !== 'extension' ) {
			return;
		}
		if ( d.op === 'here' ) {
			copies.add( String( d.version ) );
			const first = ! ext || ext.version !== d.version; // a reloaded or updated extension announces itself again
			ext = { version: d.version, protocol: d.protocol, paired: !! d.paired, tokenId: d.tokenId || '', user: d.user || '', store: d.store || '' };
			paint();
			if ( first ) {
				refresh();
			}
		} else if ( d.op === 'paired' && waiting && waiting.id === d.id ) {
			clearTimeout( waiting.timer );
			waiting = null;
			btn.disabled = false;
			if ( d.ok ) {
				ext = Object.assign( {}, ext, { paired: true, tokenId: d.id, user: d.user } );
				paint();
			} else {
				say( 'error', MESSAGES.failed.replace( '%s', d.message || MESSAGES.noAnswer ) );
			}
			refresh();
		}
	} );

	btn.addEventListener( 'click', () => {
		btn.disabled = true;
		say( 'info', MESSAGES.connecting );
		const label = 'Chrome' + ( navigator.userAgentData && navigator.userAgentData.platform ? ' · ' + navigator.userAgentData.platform : '' );
		api( 'ext/pair', 'POST', { label } )
			.then( ( d ) => {
				waiting = {
					id: d.id,
					timer: setTimeout( () => { // never left behind: a key the extension didn't take is taken back
						waiting = null;
						btn.disabled = false;
						say( 'error', MESSAGES.noAnswer );
						api( 'ext/revoke', 'POST', { id: d.id } ).then( ( r ) => rows( r.tokens ) ).catch( () => {} );
					}, LIMITS.pairMs ),
				};
				post( { op: 'pair', token: d.token, id: d.id, rest: CFG.rest } );
			} )
			.catch( ( e ) => {
				btn.disabled = false;
				say( 'error', MESSAGES.failed.replace( '%s', e.message ) );
			} );
	} );

	/** Leaving mid-connect: the key the extension hasn't taken is taken back. */
	window.addEventListener( 'pagehide', () => {
		if ( waiting ) {
			api( 'ext/revoke', 'POST', { id: waiting.id }, true ).catch( () => {} );
		}
	} );

	/** The bridge announces itself on load; ask a few times in case this script ran first. */
	let n = 0;
	const ping = setInterval( () => {
		if ( ext || ++n > LIMITS.pings ) {
			clearInterval( ping );
			if ( ! ext ) {
				paint();
				refresh();
			}
			return;
		}
		post( { op: 'ping' } );
	}, LIMITS.pingMs );
	post( { op: 'ping' } );
}() );
