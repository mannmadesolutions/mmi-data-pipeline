/**
 * MMI Product Data: Send this site, from the maker's own tab (owner 2026-10-19: one click per site; it replaced
 * the Send bookmark, retired 2026-11-03). The site's product pages from its sitemap (unless a list already came from a
 * browser), then every product page the server can't read,
 * each fetched here (same site, your browser's own session) and stored through /ext/send, a pause between them. The
 * products whose pages came in, and any whose read still says refused over a good stored copy, are read again from
 * them; pages this tab can't read go back to the server.
 *
 * It stops at a bot check (never solves or waits one out: owner 2026-10-26), when Stop is pressed, or when the tab
 * goes. Progress shows in the panel (window.__mmiExtPanel.progress()), and in the status bar on Product Data (a Send
 * started there: the service worker passes each line on to the store's admin tabs).
 */
( function () {
	'use strict';

	if ( window.__mmiExtSending ) {
		return; // one send at a time in this tab
	}

	const LIMITS = { maps: 40, urls: 20000, pages: 300, gapMs: 1200, fetchMs: 30000, wallBytes: 200000 };
	const MESSAGES = {
		list: 'Reading %host%’s sitemap: %n% files, %p% pages so far',
		listSaved: 'Page list stored: %n% product pages.',
		listKept: 'Page list already sent from a browser: %n% product pages.',
		listEmpty: 'Its sitemap lists no product pages.',
		listed: 'Page list read in your browser: %n% product pages kept.',
		page: 'Sending page %i% of %n% (%ok% sent)',
		none: 'Nothing to send: the store has every product page of %host% it needs.',
		done: 'Sent %ok% of %n% pages from %host%.',
		gone: ' Gone (404 or 410): %gone%; their products get new page proposals.',
		failed: ' %failed% couldn’t be read here: the server tries them again.',
		reread: ' %reread% more products are read again from pages stored earlier.',
		stopped: 'Stopped after %ok% pages. What was sent is kept.',
		wall: '%host% is showing a bot check. Nothing more was sent. If you get through it yourself, press Send this site again.',
		error: 'Send stopped: %m%',
		reloaded: 'The extension was reloaded. Reload this page, then press Send this site again.',
	};
	const WALL_RE = /_Incapsula_Resource|Incapsula incident ID|px-captcha|captcha-delivery\.com|DataDome|challenge-platform|cf-chl-|<title>Just a moment/i;

	const fill = ( tpl, vars ) => Object.entries( vars ).reduce( ( s, [ k, v ] ) => s.split( `%${ k }%` ).join( v ), tpl );
	const fmt = ( n ) => Number( n || 0 ).toLocaleString();
	const wait = ( ms ) => new Promise( ( done ) => window.setTimeout( done, ms ) );
	const bare = ( h ) => String( h || '' ).toLowerCase().replace( /^www\./, '' );
	const host = bare( location.hostname );
	const panel = window.__mmiExtPanel;
	const listOnly = window.__mmiExtSendOnly === 'list'; // the site view's "Read its page list in this browser"
	window.__mmiExtSendOnly = '';
	let stop = false;

	const halt = () => {
		stop = true;
	};
	window.__mmiExtSendStop = halt; // Stop on Product Data, through the service worker
	const say = ( text, kind, stoppable ) => {
		chrome.runtime.sendMessage( { type: 'sendsay', text, kind: kind || 'info', done: ! stoppable } ).catch( () => {} );
		return panel && panel.progress( text, kind, stoppable ? halt : null );
	};

	/** A call to /ext/send; throws with the server's words when it refuses. */
	async function send( op, body = {} ) {
		const res = await chrome.runtime.sendMessage( { type: 'send', op, url: location.href, ...body } ).catch( () => null );
		if ( ! res ) {
			throw new Error( MESSAGES.reloaded );
		}
		if ( ! res.ok ) {
			throw new Error( ( res.data && res.data.message ) || `HTTP ${ res.status }` );
		}
		return res.data;
	}

	/** One of this site's own addresses, on this tab's origin ("example.com/x" from "www.example.com"). */
	function own( u ) {
		try {
			const a = new URL( u, location.origin );
			return bare( a.hostname ) === host ? location.origin + a.pathname + a.search : '';
		} catch ( e ) {
			return '';
		}
	}

	/** An address of this site, fetched with this browser's session: { status, html } (status 0: unreadable here). */
	async function fetchOwn( u ) {
		const ctl = new AbortController();
		const t = window.setTimeout( () => ctl.abort(), LIMITS.fetchMs );
		try {
			const r = await fetch( u, { credentials: 'include', redirect: 'follow', signal: ctl.signal } );
			if ( bare( new URL( r.url ).hostname ) !== host ) {
				return { status: 0, html: '' }; // sent to another site: the server reads it
			}
			return { status: r.status, html: await r.text() };
		} catch ( e ) {
			return { status: 0, html: '' };
		} finally {
			window.clearTimeout( t );
		}
	}

	const isWall = ( r ) => r && r.html && r.html.length <= LIMITS.wallBytes && WALL_RE.test( r.html );

	/** The site's product pages from its sitemap (robots.txt's Sitemap lines, else the usual names). */
	async function readList() {
		const robots = await fetchOwn( `${ location.origin }/robots.txt` );
		let queue = robots.status === 200 ? [ ...String( robots.html ).matchAll( /^\s*sitemap:\s*(\S+)/gim ) ].map( ( m ) => own( m[ 1 ] ) ).filter( Boolean ) : [];
		const guesses = queue.length ? [] : [ '/sitemap_index.xml', '/sitemap-index.xml', '/sitemap.xml' ].map( ( x ) => location.origin + x );
		queue = queue.length ? queue : guesses.slice();
		const urls = new Set();
		for ( let i = 0; i < queue.length && i < LIMITS.maps && ! stop; i++ ) {
			say( fill( MESSAGES.list, { host, n: i + 1, p: fmt( urls.size ) } ), 'info', true );
			const r = await fetchOwn( queue[ i ] );
			if ( isWall( r ) ) {
				throw new Error( 'wall' );
			}
			if ( r.status !== 200 || ! r.html ) {
				continue;
			}
			const doc = new window.DOMParser().parseFromString( r.html, 'application/xml' );
			const locs = Array.from( doc.getElementsByTagName( 'loc' ) ).map( ( l ) => l.textContent.trim() );
			if ( ! locs.length ) {
				continue;
			}
			if ( guesses.includes( queue[ i ] ) ) {
				queue = queue.filter( ( u, j ) => j <= i || ! guesses.includes( u ) ); // found it: the other usual names aren't read
			}
			if ( doc.getElementsByTagName( 'sitemapindex' ).length ) {
				locs.map( own ).filter( ( u ) => u && ! queue.includes( u ) ).forEach( ( u ) => queue.push( u ) );
			} else {
				locs.forEach( ( u ) => urls.size < LIMITS.urls && urls.add( u ) );
			}
			await wait( LIMITS.gapMs );
		}
		return [ ...urls ];
	}

	/** The page as stored: scripts and styles left out, JSON-LD kept (parsed inert: nothing in it runs). */
	function clean( html ) {
		const doc = new window.DOMParser().parseFromString( html, 'text/html' );
		doc.querySelectorAll( 'script:not([type="application/ld+json"]),style,noscript,iframe,svg' ).forEach( ( e ) => e.remove() );
		return `<!DOCTYPE html>${ doc.documentElement.outerHTML }`;
	}

	async function run() {
		const out = { ok: 0, gone: 0, failed: 0, n: 0, reread: 0, listed: -1 };
		if ( listOnly ) {
			const urls = await readList();
			out.listed = urls.length && ! stop ? ( await send( 'list', { urls } ) ).kept : 0;
			return out;
		}
		const info = await send( 'site' );
		const pages = ( info.pages || [] ).slice( 0, LIMITS.pages );
		out.n = pages.length;
		if ( ! info.list.count || info.list.source !== 'browser' || ! pages.length ) {
			const urls = await readList();
			if ( urls.length ) {
				const kept = ( await send( 'list', { urls } ) ).kept;
				say( fill( MESSAGES.listSaved, { n: fmt( kept ) } ), 'info', true );
			} else if ( ! stop ) {
				say( MESSAGES.listEmpty, 'info', true );
			}
		} else {
			say( fill( MESSAGES.listKept, { n: fmt( info.list.count ) } ), 'info', true );
		}
		const ids = new Set();
		const unread = new Set();
		for ( let i = 0; i < pages.length && ! stop; i++ ) {
			const pg = pages[ i ];
			say( fill( MESSAGES.page, { i: fmt( i + 1 ), n: fmt( pages.length ), ok: fmt( out.ok ) } ), 'info', true );
			const r = await fetchOwn( own( pg.url ) || pg.url );
			if ( isWall( r ) ) {
				throw new Error( 'wall' );
			}
			if ( r.status === 200 && /<html/i.test( r.html ) ) {
				await send( 'page', { page: pg.url, html: clean( r.html ) } ).then( () => {
					out.ok++;
					pg.ids.forEach( ( x ) => ids.add( x ) );
				} ).catch( () => out.failed++ );
			} else if ( [ 404, 410 ].includes( r.status ) ) {
				out.gone++;
				await send( 'page', { page: pg.url, gone: r.status } ).then( () => pg.ids.forEach( ( x ) => ids.add( x ) ) ).catch( () => {} );
			} else {
				out.failed++;
				pg.ids.forEach( ( x ) => unread.add( x ) );
			}
			await wait( LIMITS.gapMs ); // a person's pace, not a crawler's
		}
		( info.reread || [] ).forEach( ( x ) => ids.add( x ) );
		out.reread = ( info.reread || [] ).length;
		if ( ids.size && ! stop ) {
			await send( 'queue', { ids: [ ...ids ], mode: 'scrape' } );
		}
		if ( unread.size && ! stop ) {
			await send( 'queue', { ids: [ ...unread ], mode: 'read' } );
		}
		return out;
	}

	window.__mmiExtSending = true;
	say( fill( MESSAGES.list, { host, n: 0, p: 0 } ), 'info', true );
	run().then( ( out ) => {
		if ( out.listed >= 0 ) {
			say( out.listed ? fill( MESSAGES.listed, { n: fmt( out.listed ) } ) : MESSAGES.listEmpty, out.listed ? 'ok' : 'warn' );
		} else if ( stop ) {
			say( fill( MESSAGES.stopped, { ok: fmt( out.ok ) } ), 'warn' );
		} else if ( ! out.n ) {
			say( fill( MESSAGES.none, { host } ) + ( out.reread ? fill( MESSAGES.reread, out ) : '' ), 'ok' );
		} else {
			say( fill( MESSAGES.done, { ok: fmt( out.ok ), n: fmt( out.n ), host } ) + ( out.gone ? fill( MESSAGES.gone, out ) : '' ) + ( out.failed ? fill( MESSAGES.failed, out ) : '' ) + ( out.reread ? fill( MESSAGES.reread, out ) : '' ), out.failed ? 'warn' : 'ok' );
		}
	}, ( e ) => {
		say( e.message === 'wall' ? fill( MESSAGES.wall, { host } ) : fill( MESSAGES.error, { m: e.message } ), e.message === 'wall' ? 'warn' : 'error' );
	} ).finally( () => {
		window.__mmiExtSending = false;
		if ( panel ) {
			panel.refresh();
		}
	} );
}() );
