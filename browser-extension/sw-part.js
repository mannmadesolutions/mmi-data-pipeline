/**
 * Product Data's part of the extension's service worker (loaded by the shell's sw.js through parts.js; the shell is
 * MMI_Browser_Extension's browser-extension/ in the shared library). The panel on a maker's page and on our own product
 * pages, the live mapper, the picture picker, Send this site, Next and the product in hand, map steps, and the reference
 * library. Every call goes to Product Data's routes through the shell (mmiExt.api), which holds the store and the key.
 *
 * Messages, from the scripts on a maker's page (panel.js, mapper.js, pictures.js, send.js): page / snapshot / map / write
 * / undo / pictures / veto / products / link / next / send / … (each one of ExtBridge's routes); inject, which puts the
 * live mapper, the picture picker or Send this site on that page; carry, a map step's part for the page the panel moves
 * this tab to; focus, which remembers the product Next opened in this tab; hand, the product in hand. From bridge-part.js
 * on the store's admin pages: opensend / sendstop / sendsay, openoverview (Makers' ↗) and openpick (a map step), libstate
 * and liballow (the Reference library section).
 */
( function () {
	'use strict';

	const ID = 'product-data';
	const DIR = 'parts/product-data/';
	const { saved, storeNow, storeTabs, allowed, askAllow, originOf, bareHost, flash, startPanel, TITLE } = self.mmiExt;
	const MESSAGES = Object.assign( {}, self.mmiExt.MESSAGES, {
		sendClosed: 'Its tab was closed before it finished. What was sent is kept.',
	} );
	/** A call to one of Product Data's routes ("ext/page"). */
	const api = ( route, body ) => self.mmiExt.api( ID, route, body );
	/** What inject may put on a page, in order (theme.js and dock.js are the shell's; map-select.js and live-mapper.js come from the module's assets/js). */
	const SETS = {
		mapper: [ `${ DIR }page-copy.js`, `${ DIR }map-select.js`, 'theme.js', 'dock.js', `${ DIR }mapper.js`, `${ DIR }live-mapper.js` ],
		pictures: [ `${ DIR }page-copy.js`, `${ DIR }map-select.js`, 'theme.js', `${ DIR }pictures.js` ],
		send: [ `${ DIR }send.js` ],
	};
	const PANEL_FILES = [ `${ DIR }page-copy.js`, 'theme.js', 'dock.js', `${ DIR }panel.js` ]; // theme.js: the extension's colours; dock.js: where the panels sit (one setting for the panel and the mapper)
	const FOCUS_MS = 15 * 60 * 1000; // a product Next opened is remembered this long
	/** A map part a step may open the mapper on (VendorMaps::FIELDS' keys). */
	const PARTS = /^[a-z_]{2,20}$/;

	/** Messages that change a product, with the ops of theirs that only read. */
	const CHANGES = { write: [], undo: [], snapshot: [], scrape: [], link: [], veto: [], disclaimer: [], layoutmap: [], pictures: [ 'check' ], mapping: [ 'list', 'brands' ] };

	/** An answer that changed the product (a check only changes nothing). */
	function changes( msg, res ) {
		const reads = CHANGES[ msg.type ];
		return !! reads && ! reads.includes( String( msg.op || '' ) ) && !! res && res.ok && ! ( res.data && res.data.dry );
	}

	/**
	 * The panels open in other tabs on product `id` show it again (owner 2026-11-16: our product page's panel follows a
	 * commit made on the maker's page, and the other way round). Only tabs whose panel is open are asked (panelstate).
	 */
	async function tellOthers( sender, id ) {
		const from = sender.tab ? sender.tab.id : 0;
		const open = id > 0 ? await chrome.storage.session.get( null ).catch( () => ( {} ) ) : {};
		for ( const [ key, v ] of Object.entries( open ) ) {
			const tabId = Number( ( /^panel(\d+)$/.exec( key ) || [] )[ 1 ] );
			if ( tabId && tabId !== from && v && v.open ) {
				chrome.scripting.executeScript( { target: { tabId }, func: ( p ) => window.__mmiExtPanel && window.__mmiExtPanel.changed && window.__mmiExtPanel.changed( p ), args: [ id ] } ).catch( () => {} );
			}
		}
	}

	/** Next opened this product's page in this tab: remembered (this browser session only) for the panel there. */
	async function keepFocus( sender, product, url, title ) {
		if ( ! sender.tab || ! sender.tab.id || ! product ) {
			return { ok: false };
		}
		await chrome.storage.session.set( { [ `focus${ sender.tab.id }` ]: { product, host: bareHost( url ), title, at: Date.now() } } );
		return { ok: true };
	}

	/**
	 * A product "in hand": its right page is being looked for on the site, in a tab of its own, and the panel there offers
	 * every page for it until one is used, Stop is pressed or the tab closes. start: opens the site, or $url, a maker's page
	 * from one of our product pages. visit: opens the product's page with the panel on that product. get, stop: that tab's.
	 */
	async function inHand( sender, op, product, title, url ) {
		const tab = sender.tab;
		if ( ! tab || ! tab.id ) {
			return { ok: false };
		}
		const key = `hand${ tab.id }`;
		if ( op === 'start' || op === 'visit' ) {
			const to = url ? ( originOf( url ) ? url : '' ) : new URL( tab.url ).origin + '/';
			if ( ! to ) {
				return { ok: false, data: { message: MESSAGES.badPage } };
			}
			const made = await chrome.tabs.create( { url: to, index: tab.index + 1 } );
			const carry = op === 'start' ? { [ `hand${ made.id }` ]: { product, title } } : { [ `focus${ made.id }` ]: { product, host: bareHost( to ), title, at: Date.now() } };
			await chrome.storage.session.set( { ...carry, [ `panel${ made.id }` ]: { open: true, folded: false } } );
			return { ok: true };
		}
		if ( op === 'stop' ) {
			await chrome.storage.session.remove( key );
			return { ok: true };
		}
		return { ok: true, data: ( await chrome.storage.session.get( key ) )[ key ] || null };
	}

	/** The product Next opened in this tab, once, when the panel there asks without one. */
	async function takeFocus( sender, url ) {
		const key = sender.tab && sender.tab.id ? `focus${ sender.tab.id }` : '';
		if ( ! key ) {
			return 0;
		}
		const f = ( await chrome.storage.session.get( key ) )[ key ];
		await chrome.storage.session.remove( key );
		chrome.action.setBadgeText( { tabId: sender.tab.id, text: '' } );
		chrome.action.setTitle( { tabId: sender.tab.id, title: TITLE } );
		return f && f.host === bareHost( url ) && Date.now() - f.at < FOCUS_MS ? f.product : 0;
	}

	/** The live mapper, the picture picker or Send this site on the page the panel asking is on. */
	async function inject( sender, set ) {
		if ( ! SETS[ set ] || ! sender.tab || ! sender.tab.id ) {
			return { ok: false, data: { message: MESSAGES.badPage } };
		}
		try {
			await chrome.scripting.executeScript( { target: { tabId: sender.tab.id, frameIds: [ sender.frameId || 0 ] }, files: SETS[ set ] } );
			return { ok: true };
		} catch ( e ) {
			return { ok: false, data: { message: MESSAGES.noAccess } };
		}
	}

	/**
	 * A site's Send on Product Data (owner 2026-11-03: the extension replaces the Send bookmark): the site opens in its own
	 * tab and is sent from there (send.js), one click per site. Where Chrome already lets the extension onto the site it
	 * starts by itself; otherwise the first click of the toolbar button there starts it. Each line of the send goes back to
	 * the store's admin tabs (bridge.js), and a finished site's tab closes. Kept for the browser session: tab id → { host, started }.
	 */
	const SEND_KEY = 'sendTabs';
	const SEND_CLOSE_MS = 4000;
	const sendTabs = async () => ( await chrome.storage.session.get( SEND_KEY ) )[ SEND_KEY ] || {};
	const keepSends = ( tabs ) => chrome.storage.session.set( { [ SEND_KEY ]: tabs } );

	async function openSend( host ) {
		const s = await storeNow();
		if ( ! /^[a-z0-9-]+(\.[a-z0-9-]+)+$/.test( host ) || ( s && host === new URL( s.origin ).hostname ) ) {
			return { ok: false, data: { message: MESSAGES.badPage } };
		}
		if ( ! s || ! ( await saved() ).token ) {
			return { ok: false, data: { message: MESSAGES.notConnected } };
		}
		const url = `https://${ host }/`;
		const tab = await chrome.tabs.create( { url } );
		await keepSends( { ...( await sendTabs() ), [ tab.id ]: { host, started: false } } );
		chrome.action.setBadgeBackgroundColor( { tabId: tab.id, color: '#2271b1' } );
		chrome.action.setBadgeText( { tabId: tab.id, text: '▶' } );
		chrome.action.setTitle( { tabId: tab.id, title: 'Click to send this site to your store' } );
		return { ok: true, data: { allowed: await allowed( url ) } };
	}

	/**
	 * A maker's site from the store (Makers' ↗ beside a site, through bridge.js; owner 2026-11-14): its home page in a new tab
	 * beside the store's, with the panel open on the site overview (its pages, layouts and the work left). Where Chrome
	 * already lets the extension onto the site the panel comes up as the page loads (reopen()); otherwise the button shows
	 * "+" and its first click opens it there. Not connected: the site opens, nothing more.
	 */
	async function openOverview( sender, host, page = '', part = '', scope = '' ) {
		const s = await storeNow();
		if ( page ) { // openpick (a site's map step): that page of the maker's site, the mapper on $part there
			host = originOf( page ) ? new URL( page ).hostname : '';
		}
		if ( ! /^[a-z0-9-]+(\.[a-z0-9-]+)+$/.test( host ) || ( s && host === new URL( s.origin ).hostname ) || ( page && part && ! PARTS.test( part ) ) ) {
			return { ok: false, data: { message: MESSAGES.badPage } };
		}
		const url = page || `https://${ host }/`;
		const tab = await chrome.tabs.create( sender.tab ? { url, index: sender.tab.index + 1 } : { url } );
		if ( ! s || ! ( await saved() ).token ) {
			return { ok: true, data: { allowed: false, message: MESSAGES.notConnected } };
		}
		const flag = page ? ( part ? { [ `pick${ tab.id }` ]: { part, scope: scope === 'site' ? 'site' : '', host: bareHost( url ), at: Date.now() } } : {} ) : { [ `site${ tab.id }` ]: { host } }; // no part: the page with its panel (a layout's map)
		await chrome.storage.session.set( { [ `panel${ tab.id }` ]: { open: true, folded: false }, ...flag } );
		return { ok: true, data: { allowed: await allowed( url ) } };
	}

	/**
	 * A map step on another page of the site (the panel's gopick): the panel there opens the mapper on $part as it loads,
	 * once. Kept for this tab until then (FOCUS_MS at most).
	 */
	async function carryPick( sender, url, part, scope ) {
		if ( ! sender.tab || ! sender.tab.id || ! originOf( url ) || ! PARTS.test( part ) ) {
			return { ok: false, data: { message: MESSAGES.badPage } };
		}
		await chrome.storage.session.set( { [ `pick${ sender.tab.id }` ]: { part, scope: scope === 'site' ? 'site' : '', host: bareHost( url ), at: Date.now() } } );
		return { ok: true };
	}

	/** The part a map step asked for on this tab's page, once: { part, scope } or null. */
	async function takePick( tabId, url ) {
		const key = `pick${ tabId }`;
		const p = ( await chrome.storage.session.get( key ) )[ key ];
		if ( ! p ) {
			return null;
		}
		await chrome.storage.session.remove( key );
		return p.host === bareHost( url ) && Date.now() - p.at < FOCUS_MS ? { part: p.part, scope: p.scope } : null;
	}

	/** Send starts on this tab, once, if a Send opened it. The panel is on the page by now. */
	async function startSend( tabId ) {
		const tabs = await sendTabs();
		const s = tabs[ tabId ];
		if ( ! s || s.started ) {
			return;
		}
		s.started = true;
		await keepSends( tabs );
		chrome.action.setBadgeText( { tabId, text: '' } );
		chrome.action.setTitle( { tabId, title: TITLE } );
		await chrome.scripting.executeScript( { target: { tabId }, files: SETS.send } );
	}

	/** A line of a send, to the store's admin tabs; a finished one's tab closes unless it stopped with something to read. */
	async function relaySend( sender, msg ) {
		const tabId = sender.tab && sender.tab.id;
		const tabs = await sendTabs();
		const s = tabs[ tabId ];
		const line = { type: 'sendsay', host: s ? s.host : bareHost( ( sender.tab && sender.tab.url ) || '' ), text: String( msg.text || '' ), kind: String( msg.kind || 'info' ), done: !! msg.done };
		for ( const t of await storeTabs() ) {
			chrome.tabs.sendMessage( t.id, line ).catch( () => {} );
		}
		if ( s && line.done ) {
			delete tabs[ tabId ];
			await keepSends( tabs );
			if ( line.kind === 'ok' ) {
				setTimeout( () => chrome.tabs.remove( tabId ).catch( () => {} ), SEND_CLOSE_MS );
			}
		}
		return { ok: true };
	}

	/** Stop on Product Data: a send under way stops after its page; one still waiting for its click closes its tab. */
	async function stopSend( host ) {
		const tabs = await sendTabs();
		const id = Object.keys( tabs ).find( ( k ) => tabs[ k ].host === host );
		if ( ! id ) {
			return { ok: false };
		}
		if ( tabs[ id ].started ) {
			await chrome.scripting.executeScript( { target: { tabId: Number( id ) }, func: () => window.__mmiExtSendStop && window.__mmiExtSendStop() } ).catch( () => {} );
		} else {
			await chrome.tabs.remove( Number( id ) ).catch( () => {} ); // onRemoved says so
		}
		return { ok: true };
	}

	/** A Send's tab loaded: it starts by itself where Chrome already allows the site. */
	chrome.tabs.onUpdated.addListener( async ( tabId, change, tab ) => {
		if ( change.status !== 'complete' ) {
			return;
		}
		const s = ( await sendTabs() )[ tabId ];
		if ( s && ! s.started && await allowed( tab.url || '' ) ) {
			await startPanel( tabId, false ).then( () => startSend( tabId ) ).catch( () => {} );
		}
	} );

	/** A Send's tab closed before it finished: Product Data stops waiting for it. */
	chrome.tabs.onRemoved.addListener( async ( tabId ) => {
		const tabs = await sendTabs();
		if ( tabs[ tabId ] ) {
			await relaySend( { tab: { id: tabId } }, { text: MESSAGES.sendClosed, kind: 'warn', done: true } );
		}
	} );

	/**
	 * The panel on a tab, told this run's build (a panel left from before a self-update is replaced, not toggled), the
	 * store it works with (its links and words), and, once each, the site overview (Makers' ↗) or a map step's part.
	 */
	async function panelOn( tabId, folded, url ) {
		const s = await storeNow();
		const site = !! ( await chrome.storage.session.get( `site${ tabId }` ) )[ `site${ tabId }` ]; // opened from Makers' ↗: once
		const pick = await takePick( tabId, url ); // a map step: the mapper on its part, once
		await chrome.scripting.executeScript( { target: { tabId }, func: ( f, b, st, so, pk ) => {
			window.__mmiExtStart = { folded: f, build: b, store: st, site: so, pick: pk };
		}, args: [ folded, await self.mmiExt.buildId(), s ? { origin: s.origin, base: s.base } : null, site, pick ] } );
		if ( site ) {
			await chrome.storage.session.remove( `site${ tabId }` );
		}
		await chrome.scripting.executeScript( { target: { tabId }, files: PANEL_FILES } );
	}

	/** The button's title on a page Chrome hasn't allowed yet, while the panel is open in that tab. */
	async function reopenTitle( tabId ) {
		const site = ( await chrome.storage.session.get( `site${ tabId }` ) )[ `site${ tabId }` ];
		const pick = ( await chrome.storage.session.get( `pick${ tabId }` ) )[ `pick${ tabId }` ];
		return pick ? `${ TITLE }: click to pick ${ pick.part } on this page` : site ? `${ TITLE }: click to see ${ site.host }’s overview` : '';
	}

	/** A page loaded: kept if it is on a reference site; the page Next opened says there is a product to carry on with. */
	async function tabLoaded( tabId, url ) {
		keepIfLibrary( tabId, url );
		const f = ( await chrome.storage.session.get( `focus${ tabId }` ) )[ `focus${ tabId }` ];
		if ( f && Date.now() - f.at < FOCUS_MS ) {
			chrome.action.setBadgeBackgroundColor( { tabId, color: '#2271b1' } );
			chrome.action.setBadgeText( { tabId, text: '1' } );
			chrome.action.setTitle( { tabId, title: `${ TITLE }: click to carry on with ${ f.title || 'the next product' }` } );
		}
	}

	/**
	 * The reference library. The store's reference sites ("host" or "host/path-prefix", Library::sites()) are asked for at
	 * most every LIB.refreshMs, and at once when the store's Chrome extension page changes them (libstate). A page loaded on
	 * one of them, where Chrome lets the extension onto the site, is read as drawn (after LIB.settleMs, for pages that fill
	 * in by script) and sent to the store, once per LIB.againMs per address. The button shows ✓ briefly when it is kept.
	 */
	const LIB = { refreshMs: 10 * 60 * 1000, againMs: 30 * 60 * 1000, settleMs: 1500, maxBytes: 7500000, remember: 300, badgeMs: 3000 };

	async function libSites( force ) {
		const { lib } = await chrome.storage.local.get( 'lib' );
		if ( ! force && lib && Date.now() - lib.at < LIB.refreshMs ) {
			return lib.sites;
		}
		if ( ! ( await saved() ).token ) {
			return lib ? lib.sites : [];
		}
		const r = await api( 'ext/library', { op: 'sites' } );
		let sites = lib ? lib.sites : []; // the store didn't answer: the list it last gave is kept
		if ( r.ok && Array.isArray( r.data.sites ) ) {
			sites = r.data.sites.map( String );
		} else if ( r.status === 404 || r.status === 401 ) {
			sites = []; // a store without the library, or this browser no longer connected: nothing is kept
		}
		await chrome.storage.local.set( { lib: { sites, at: Date.now() } } );
		return sites;
	}

	const libBare = ( h ) => String( h || '' ).toLowerCase().replace( /^www\./, '' );

	/** The reference site an address falls under ('' when none): the same rule as Library::site_for(). */
	function libSiteFor( url, sites ) {
		let u;
		try {
			u = new URL( url );
		} catch ( e ) {
			return '';
		}
		if ( ! /^https?:$/.test( u.protocol ) ) {
			return '';
		}
		const path = u.pathname.toLowerCase();
		let best = '';
		for ( const e of sites ) {
			const at = e.indexOf( '/' );
			const host = libBare( at < 0 ? e : e.slice( 0, at ) );
			const pre = at < 0 ? '' : e.slice( at );
			if ( host === libBare( u.hostname ) && ( ! pre || path.startsWith( pre ) ) && e.length > best.length ) {
				best = e;
			}
		}
		return best;
	}

	/** A page finished loading: kept when it is on a reference site Chrome lets the extension onto. */
	async function keepIfLibrary( tabId, url ) {
		if ( ! /^https?:/.test( url ) ) {
			return;
		}
		const sites = await libSites( false );
		if ( ! sites.length || ! libSiteFor( url, sites ) || ! ( await allowed( url ) ) ) {
			return;
		}
		const key = url.split( '#' )[ 0 ];
		const kept = ( await chrome.storage.session.get( 'libKept' ) ).libKept || {};
		if ( kept[ key ] && Date.now() - kept[ key ] < LIB.againMs ) {
			return;
		}
		let got = null;
		try {
			const res = await chrome.scripting.executeScript( { target: { tabId }, func: async ( ms ) => {
				await new Promise( ( r ) => setTimeout( r, ms ) );
				return { url: location.href, html: '<!DOCTYPE html>\n' + document.documentElement.outerHTML };
			}, args: [ LIB.settleMs ] } );
			got = res && res[ 0 ] && res[ 0 ].result;
		} catch ( e ) {
			return; // the tab moved on or closed
		}
		if ( ! got || ! got.html || got.html.length > LIB.maxBytes || ! libSiteFor( got.url, sites ) ) {
			return;
		}
		const r = await api( 'ext/library', { op: 'keep', url: got.url, html: got.html } );
		if ( r.ok || r.status === 422 ) { // kept, or refused for a reason another try won't change (a search page, a bot check)
			kept[ key ] = Date.now();
			const keys = Object.keys( kept ).sort( ( a, b ) => kept[ a ] - kept[ b ] );
			for ( const k of keys.slice( 0, Math.max( 0, keys.length - LIB.remember ) ) ) {
				delete kept[ k ];
			}
			await chrome.storage.session.set( { libKept: kept } );
		}
		if ( r.ok ) {
			chrome.action.setBadgeBackgroundColor( { tabId, color: '#00a32a' } ).catch( () => {} );
			chrome.action.setBadgeText( { tabId, text: '✓' } ).catch( () => {} );
			chrome.action.setTitle( { tabId, title: `${ TITLE }: ${ r.data.message || 'Kept in the reference library.' }` } ).catch( () => {} );
			setTimeout( () => {
				chrome.action.setBadgeText( { tabId, text: '' } ).catch( () => {} );
				chrome.action.setTitle( { tabId, title: TITLE } ).catch( () => {} );
			}, LIB.badgeMs );
		} else if ( r.status !== 422 ) {
			flash( tabId, r.data.message || MESSAGES.offline );
		}
	}

	/** The store's Chrome extension page asks: connected? which reference sites does Chrome let the extension onto? */
	async function libState( sender ) {
		const st = await storeNow();
		if ( ! st || sender.origin !== st.origin || ! ( await saved() ).token ) {
			return { paired: false, allowed: {} };
		}
		const out = {};
		for ( const e of await libSites( true ) ) {
			const host = e.split( '/' )[ 0 ];
			out[ e ] = await chrome.permissions.contains( { origins: [ `https://${ host }/*` ] } ).catch( () => false );
		}
		return { paired: true, allowed: out };
	}

	/** "Allow in Chrome" on the store's page: the small window where a click lets Chrome ask for that site. */
	async function libAllow( sender, site ) {
		const st = await storeNow();
		const host = site.split( '/' )[ 0 ];
		if ( ! st || sender.origin !== st.origin || ! /^[a-z0-9-]+(\.[a-z0-9-]+)+$/.test( host ) ) {
			return { ok: false };
		}
		return askAllow( `https://${ host }/`, 'library' );
	}

	self.mmiExt.part( {
		id: ID,
		can: [ 'send', 'overview', 'pick' ],
		busy: '#mmpt-live-mapper, mmi-product-data-picker', // the live mapper or the picture picker: their picks aren't saved yet
		startPanel: panelOn,
		onClick: ( tab ) => startSend( tab.id ),
		reopenTitle,
		onTabLoaded: tabLoaded,
		onTabClosed: ( tabId ) => chrome.storage.session.remove( [ `focus${ tabId }`, `hand${ tabId }`, `site${ tabId }`, `pick${ tabId }` ] ),
		afterReply: ( msg, sender, res ) => {
			if ( changes( msg, res ) ) {
				tellOthers( sender, Number( msg.product ) );
			}
		},
		messages: {
			page: async ( msg, sender ) => api( 'ext/page', { url: String( msg.url || '' ), product: Number( msg.product ) || ( await takeFocus( sender, String( msg.url || '' ) ) ), site: msg.site ? 1 : 0, look: msg.look ? 1 : 0 } ),
			scrape: ( msg, sender ) => api( 'ext/scrape', { url: String( msg.url || '' ), product: Number( msg.product ) || 0 } ),
			snapshot: ( msg, sender ) => api( 'ext/snapshot', { url: String( msg.url || '' ), html: String( msg.html || '' ), product: Number( msg.product ) || 0 } ),
			map: ( msg, sender ) => api( 'ext/map', { url: String( msg.url || '' ), op: String( msg.op || '' ), field: String( msg.field || '' ), sel: String( msg.sel || '' ), mode: String( msg.mode || '' ), html: String( msg.html || '' ), warn: String( msg.warn || '' ), where: String( msg.where || '' ), layout: Number.isInteger( msg.layout ) ? msg.layout : '', lines: ( msg.lines || [] ).slice( 0, 20 ).map( String ), offset: Number( msg.offset ) || 0, proposal: Number( msg.proposal ) || 0, decision: String( msg.decision || '' ) } ),
			write: ( msg, sender ) => api( 'ext/write', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, expect: ( msg.expect || [] ).map( String ), only: ( msg.only || [] ).map( String ), html: String( msg.html || '' ) } ),
			undo: ( msg, sender ) => api( 'ext/undo', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, what: String( msg.what || '' ) } ),
			pictures: ( msg, sender ) => api( 'ext/pictures', { op: String( msg.op || '' ), url: String( msg.url || '' ), product: Number( msg.product ) || 0, pictures: ( msg.pictures || [] ).map( String ), videos: ( msg.videos || [] ).map( String ), audio: ( msg.audio || [] ).map( String ), html: String( msg.html || '' ) } ),
			veto: ( msg, sender ) => api( 'ext/veto', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, picture: String( msg.picture || '' ) } ),
			products: ( msg, sender ) => api( 'ext/products', { url: String( msg.url || '' ), q: String( msg.q || '' ) } ),
			link: ( msg, sender ) => api( 'ext/link', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, confirm: msg.confirm ? 1 : 0, html: String( msg.html || '' ) } ),
			next: ( msg, sender ) => api( 'ext/next', { url: String( msg.url || '' ), product: Number( msg.product ) || 0 } ),
			send: ( msg, sender ) => api( 'ext/send', { url: String( msg.url || '' ), op: String( msg.op || '' ), urls: ( msg.urls || [] ).map( String ), page: String( msg.page || '' ), html: String( msg.html || '' ), gone: Number( msg.gone ) || 0, ids: ( msg.ids || [] ).map( Number ), mode: String( msg.mode || '' ) } ),
			site: ( msg, sender ) => api( 'ext/site', { url: String( msg.url || '' ), op: String( msg.op || '' ), product: Number( msg.product ) || 0 } ),
			layout: ( msg, sender ) => api( 'ext/layout', { url: String( msg.url || '' ), op: String( msg.op || '' ), part: String( msg.part || '' ), offset: Number( msg.offset ) || 0, product: Number( msg.product ) || 0, batch: String( msg.batch || '' ), fresh: msg.fresh ? 1 : 0 } ),
			mapping: ( msg, sender ) => api( 'ext/mapping', { url: String( msg.url || '' ), op: String( msg.op || '' ), product: Number( msg.product ) || 0, was: String( msg.was || '' ), target: String( msg.target || '' ), term: Number( msg.term ) || 0, q: String( msg.q || '' ), html: String( msg.html || '' ), panel: msg.panel ? 1 : 0 } ),
			layoutmap: ( msg, sender ) => api( 'ext/layoutmap', { url: String( msg.url || '' ), op: String( msg.op || '' ), layout: Number( msg.layout ) || 0, part: String( msg.part || '' ), undo: msg.undo ? 1 : 0, product: Number( msg.product ) || 0, dry: msg.dry ? 1 : 0 } ),
			sitepart: ( msg, sender ) => api( 'ext/sitepart', { url: String( msg.url || '' ), op: String( msg.op || '' ), part: String( msg.part || '' ), offset: Number( msg.offset ) || 0, product: Number( msg.product ) || 0, batch: String( msg.batch || '' ), fresh: msg.fresh ? 1 : 0 } ),
			held: ( msg, sender ) => api( 'ext/held', { url: String( msg.url || '' ), product: Number( msg.product ) || 0 } ),
			hand: ( msg, sender ) => inHand( sender, String( msg.op || '' ), Number( msg.product ) || 0, String( msg.title || '' ), String( msg.url || '' ) ),
			disclaimer: ( msg, sender ) => api( 'ext/disclaimer', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, text: String( msg.text || '' ) } ),
			example: ( msg, sender ) => api( 'ext/example', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, parts: ( msg.parts || [] ).map( String ), scope: String( msg.scope || '' ), stays: msg.stays ? 1 : 0 } ),
			focus: ( msg, sender ) => keepFocus( sender, Number( msg.product ) || 0, String( msg.url || '' ), String( msg.title || '' ) ),
			inject: ( msg, sender ) => inject( sender, String( msg.set || '' ) ),
			opensend: ( msg, sender ) => openSend( String( msg.host || '' ) ),
			openoverview: ( msg, sender ) => openOverview( sender, String( msg.host || '' ) ),
			openpick: ( msg, sender ) => openOverview( sender, '', String( msg.url || '' ), String( msg.part || '' ), String( msg.scope || '' ) ),
			carry: ( msg, sender ) => carryPick( sender, String( msg.url || '' ), String( msg.part || '' ), String( msg.scope || '' ) ),
			sendstop: ( msg, sender ) => stopSend( String( msg.host || '' ) ),
			sendsay: ( msg, sender ) => relaySend( sender, msg ),
			libstate: ( msg, sender ) => libState( sender ),
			liballow: ( msg, sender ) => libAllow( sender, String( msg.site || '' ) ),
		},
	} );
}() );
