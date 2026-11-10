/**
 * MMI Product Data: the service worker.
 *
 * Holds this browser's token and makes every call to the store it is connected to, so no credential ever reaches a
 * maker's page.
 * Calls send no cookies (credentials: 'omit'): the token alone signs in, on Product Data's extension routes only
 * (ExtBridge on the server). The toolbar button puts the panel on the page it is clicked on (activeTab); nothing
 * runs on a maker's page until then.
 *
 * Messages: from bridge.js (the store's Chrome extension page) status / pair / forget; from connect.html connectto; from the scripts on a
 * maker's page (panel.js, mapper.js, pictures.js, send.js) page / snapshot / map / write / undo / pictures / veto /
 * products / link / next / send / connect; inject, which puts the live mapper, the picture picker or Send this site
 * on that page (activeTab: the page the button was used on); and focus, which remembers the product Next opened in
 * this tab, so the panel opens on it when the button is clicked there (the extension can't act on the new page by
 * itself: activeTab ends when the tab moves on).
 *
 * Every call says which download this is (X-MMI-Ext-Build, from build.json in the zip), so the panel can say when
 * a newer one is out.
 *
 * The panel stays with the owner as they browse (owner 2026-11-02: "the user should decide when to open/close the
 * extension"): each tab's panel state (open, folded) is kept for the browser session (panelstate), and when the tab
 * loads another page the panel is put back, on sites Chrome lets the extension onto. That is asked per site, by Chrome's
 * own prompt: at the first toolbar click on a site, or from the panel's "Keep it open as you browse" (allow.html).
 * Closing the panel (× or the button) turns it off for that tab.
 *
 * The store (migration phase 10b: offered to any store running MMI Data Pipeline): no address is built in. A copy
 * downloaded from a store's Chrome extension page names that store in build.json (ExtBridge::zip_file() also gives its
 * manifest the store's host permission and the bridge on its admin pages). Any other copy, such as the Chrome Web
 * Store's, learns it when connecting: the toolbar button on a store's Chrome extension page, or the address typed on
 * connect.html. Either way Chrome is asked for that site, and the bridge is registered on its admin pages
 * (chrome.scripting.registerContentScripts). The key handed over on a store's page connects the extension to that
 * store; connecting on another store's page moves it there.
 */
'use strict';

const ROUTES = 'wp-json/mmpt/v1/';
const CONNECT_PATH = 'wp-admin/admin.php?page=mmi-product-extension';
const CONNECT_SLUG = 'mmi-product-extension';
const BRIDGE_ID = 'mmi-bridge';
const EXT_PROTOCOL = 10; // ExtBridge::PROTOCOL
/** What inject may put on a page, in order (map-select.js and live-mapper.js come from mmi-admin's assets/js). */
const SETS = {
	mapper: [ 'page-copy.js', 'map-select.js', 'mapper.js', 'live-mapper.js' ],
	pictures: [ 'page-copy.js', 'map-select.js', 'pictures.js' ],
	send: [ 'send.js' ],
};
const FOCUS_MS = 15 * 60 * 1000; // a product Next opened is remembered this long
const PANEL_FILES = [ 'page-copy.js', 'panel.js' ];
/** Sites where the owner said no to Chrome's prompt: not asked again from the toolbar (the panel still offers it). */
let declined = new Set();
chrome.storage.local.get( 'declined' ).then( ( s ) => {
	declined = new Set( s.declined || [] );
} );
chrome.storage.onChanged.addListener( ( ch, area ) => {
	if ( area === 'local' && ch.declined ) {
		declined = new Set( ch.declined.newValue || [] ); // allow.html allowed a site
	}
} );
const LIMITS = { callMs: 60000 };
const MESSAGES = {
	notConnected: 'This browser isn’t connected to your store yet.',
	offline: 'The store didn’t answer. Check the connection and try again.',
	failed: 'The store answered with an error (%s).',
	notThisSite: 'This page didn’t ask the extension to connect here. Click MMI Product Data in Chrome’s toolbar on this page, then connect again.',
	noHost: 'Chrome hasn’t let the extension reach %h. Click MMI Product Data in Chrome’s toolbar on this page and allow it, then connect again.',
	badPage: 'The panel can’t run on this page.',
	sendClosed: 'Its tab was closed before it finished. What was sent is kept.',
	noAccess: 'Chrome no longer lets the extension work on this page (it was reloaded or left). Click MMI Product Data in the toolbar again.',
};
const TITLE = 'MMI Product Data';

async function saved() {
	return chrome.storage.local.get( [ 'token', 'tokenId', 'user' ] );
}

/** build.json in the extension's folder now ({} when there is none): { build, version, store }. */
async function diskInfo() {
	try {
		const j = await ( await fetch( chrome.runtime.getURL( 'build.json' ), { cache: 'no-store' } ) ).json();
		return j && typeof j === 'object' ? j : {};
	} catch ( e ) {
		return {};
	}
}

/** The build in the extension's folder now ('' when there is none). */
async function onDisk() {
	return String( ( await diskInfo() ).build || '' );
}

/**
 * A store as the extension keeps it: { origin, base, rest, connect }, every address on the store's own origin, or null.
 * base is where WordPress is (it may sit under a path); rest is its mmpt/v1 routes; connect its Chrome extension page.
 */
function storeOf( x ) {
	if ( ! x || typeof x !== 'object' ) {
		return null;
	}
	try {
		const o = new URL( String( x.base || x.origin || '' ) );
		if ( ! /^https?:$/.test( o.protocol ) ) {
			return null;
		}
		const base = ( o.origin + o.pathname ).replace( /\/+$/, '' );
		const out = { origin: o.origin, base, rest: String( x.rest || `${ base }/${ ROUTES }` ), connect: String( x.connect || `${ base }/${ CONNECT_PATH }` ) };
		return out.rest.startsWith( `${ o.origin }/` ) && out.connect.startsWith( `${ o.origin }/` ) ? out : null;
	} catch ( e ) {
		return null;
	}
}

/**
 * Where WordPress is, from an address under it ("https://example.com/shop/wp-admin/admin.php?…" →
 * "https://example.com/shop"), or from its home page's address as typed.
 */
function baseOf( url ) {
	try {
		const u = new URL( url );
		const at = u.pathname.indexOf( '/wp-admin/' );
		return u.origin + ( at >= 0 ? u.pathname.slice( 0, at ) : u.pathname ).replace( /\/+$/, '' );
	} catch ( e ) {
		return '';
	}
}

/** A store's Chrome extension page: its origin, else ''. */
function connectPageOrigin( url ) {
	try {
		const u = new URL( url );
		return /^https?:$/.test( u.protocol ) && /\/wp-admin\/admin\.php$/.test( u.pathname ) && u.searchParams.get( 'page' ) === CONNECT_SLUG ? u.origin : '';
	} catch ( e ) {
		return '';
	}
}

/**
 * The store this browser works with (null until it is known). Kept here for the calls that can't wait (the toolbar
 * click asks Chrome before anything else); storeNow() waits for it.
 */
let store = null;
const storeLoaded = ( async () => {
	const s = await chrome.storage.local.get( 'store' );
	store = storeOf( s.store );
	if ( ! store ) {
		// A copy downloaded from a store (or an older copy updated to this one): the store build.json names is kept, so a
		// later build in the folder without it (or for another store) can't leave a connected copy without its store.
		store = storeOf( ( await diskInfo() ).store );
		if ( store ) {
			await chrome.storage.local.set( { store } );
		}
	}
} )();
chrome.storage.onChanged.addListener( ( ch, area ) => {
	if ( area === 'local' && ch.store && ch.store.newValue ) {
		store = storeOf( ch.store.newValue ) || store;
	}
} );
async function storeNow() {
	await storeLoaded;
	return store;
}

/** Origins the manifest already puts the bridge on (a copy downloaded from a store). */
const manifestBridge = () => ( chrome.runtime.getManifest().content_scripts || [] ).flatMap( ( c ) => c.matches || [] ).map( ( m ) => {
	try {
		return new URL( m.replace( /\*/g, 'x' ) ).origin;
	} catch ( e ) {
		return '';
	}
} );

/**
 * The bridge on the admin pages of the store, and of a store being connected (pending), where Chrome lets the
 * extension in and the manifest doesn't already put it.
 */
async function bridgeOn() {
	const s = await storeNow();
	const { pending = [] } = await chrome.storage.local.get( 'pending' );
	const fixed = manifestBridge();
	const matches = [];
	for ( const o of new Set( [ s && s.origin, ...pending ].filter( Boolean ) ) ) {
		if ( ! fixed.includes( o ) && await chrome.permissions.contains( { origins: [ `${ o }/*` ] } ).catch( () => false ) ) {
			matches.push( `${ o }/*wp-admin/*` );
		}
	}
	await chrome.scripting.unregisterContentScripts( { ids: [ BRIDGE_ID ] } ).catch( () => {} );
	if ( matches.length ) {
		await chrome.scripting.registerContentScripts( [ { id: BRIDGE_ID, matches, js: [ 'bridge.js' ], runAt: 'document_idle' } ] ).catch( () => {} );
	}
}

/** A store being connected: its Chrome extension page may hand over a key. */
async function addPending( origin ) {
	const { pending = [] } = await chrome.storage.local.get( 'pending' );
	if ( ! pending.includes( origin ) ) {
		await chrome.storage.local.set( { pending: [ ...pending, origin ].slice( -5 ) } );
	}
}

/** The store's admin tabs (the bridge's), for the bridge after an update and for Send's progress. */
async function storeTabs() {
	const s = await storeNow();
	return s ? chrome.tabs.query( { url: `${ s.origin }/*wp-admin/*` } ).catch( () => [] ) : [];
}

/**
 * The build this run of the extension was loaded from (ExtBridge::build()). Kept for the browser session: Chrome
 * stops an idle worker and starts it again, and by then the folder may hold a newer build than the code running.
 */
async function buildId() {
	const s = await chrome.storage.session.get( 'runningBuild' );
	if ( typeof s.runningBuild === 'string' ) {
		return s.runningBuild;
	}
	const b = await onDisk();
	await chrome.storage.session.set( { runningBuild: b } );
	return b;
}

async function forget() {
	await chrome.storage.local.remove( [ 'token', 'tokenId', 'user' ] );
}

/** A call to a token route of the store (`at` while connecting). Resolves { ok, status, data }; a refused token is forgotten. */
async function api( route, body, token, at ) {
	const key = token || ( await saved() ).token;
	const s = at || await storeNow();
	if ( ! key || ! s ) {
		return { ok: false, status: 0, data: { message: MESSAGES.notConnected, connect: true } };
	}
	const ctl = new AbortController();
	const timer = setTimeout( () => ctl.abort(), LIMITS.callMs );
	try {
		const res = await fetch( s.rest + route, {
			method: 'POST',
			credentials: 'omit',
			cache: 'no-store',
			signal: ctl.signal,
			headers: { 'Content-Type': 'application/json', 'X-MMI-Ext-Token': key, 'X-MMI-Ext-Protocol': String( EXT_PROTOCOL ), 'X-MMI-Ext-Build': await buildId(), 'X-MMI-Ext-Version': chrome.runtime.getManifest().version },
			body: JSON.stringify( body || {} ),
		} );
		const data = await res.json().catch( () => ( {} ) );
		if ( res.status === 401 && ! token ) {
			await forget();
			data.connect = true;
		}
		if ( ! res.ok && ! data.message ) {
			data.message = MESSAGES.failed.replace( '%s', res.status );
		}
		return { ok: res.ok, status: res.status, data };
	} catch ( e ) {
		return { ok: false, status: 0, data: { message: MESSAGES.offline } };
	} finally {
		clearTimeout( timer );
	}
}

/**
 * A key from a store's Chrome extension page (bridge.js there): kept only when that store knows it, and the extension
 * is then that store's. Only the store it is connected to, or one being connected, may hand one over, and only where
 * Chrome lets the extension reach it.
 */
async function pair( token, id, rest, sender ) {
	const origin = sender.origin || '';
	const s = await storeNow();
	const { pending = [] } = await chrome.storage.local.get( 'pending' );
	if ( ! origin || ! ( ( s && s.origin === origin ) || pending.includes( origin ) ) ) {
		return { ok: false, message: MESSAGES.notThisSite };
	}
	if ( ! ( await chrome.permissions.contains( { origins: [ `${ origin }/*` ] } ).catch( () => false ) ) ) {
		return { ok: false, message: MESSAGES.noHost.replace( '%h', new URL( origin ).hostname ) };
	}
	const at = storeOf( { base: baseOf( sender.url || '' ) || origin, rest: rest.startsWith( `${ origin }/` ) ? rest : '' } );
	if ( ! at ) {
		return { ok: false, message: MESSAGES.notThisSite };
	}
	const hello = await api( 'ext/hello', {}, token, at );
	if ( ! hello.ok ) {
		return { ok: false, message: hello.data.message };
	}
	store = at;
	await chrome.storage.local.set( { token, tokenId: id, user: hello.data.user || '', store: at, pending: [] } );
	await bridgeOn();
	return { ok: true, user: hello.data.user || '' };
}

async function status() {
	const s = await saved();
	const st = await storeNow();
	return { version: chrome.runtime.getManifest().version, protocol: EXT_PROTOCOL, paired: !! s.token, tokenId: s.tokenId || '', user: s.user || '', store: st ? st.origin : '' };
}

/**
 * connect.html: the store's address typed there (Chrome has just been asked for it). The bridge goes on its admin
 * pages, and the answer is its Chrome extension page, where the key is handed over.
 */
async function connectTo( sender, url ) {
	if ( ! sender.url || ! sender.url.startsWith( chrome.runtime.getURL( '' ) ) ) {
		return { ok: false };
	}
	const at = storeOf( { base: baseOf( url ) } );
	if ( ! at ) {
		return { ok: false, data: { message: MESSAGES.badPage } };
	}
	await addPending( at.origin );
	await bridgeOn();
	return { ok: true, data: { connect: at.connect } };
}

/** Where connecting starts: the store's Chrome extension page, or connect.html while no store is known. */
async function connectPage() {
	const s = await storeNow();
	return s ? s.connect : chrome.runtime.getURL( 'connect.html' );
}

/** Messages from the extension's own scripts: bridge.js on the store, connect.html, and panel.js on the page the button was clicked on. */
chrome.runtime.onMessage.addListener( ( msg, sender, reply ) => {
	if ( sender.id !== chrome.runtime.id || ! msg || typeof msg.type !== 'string' ) {
		return false;
	}
	const run = {
		status: () => status(),
		pair: () => pair( String( msg.token || '' ), String( msg.id || '' ), String( msg.rest || '' ), sender ),
		connectto: () => connectTo( sender, String( msg.url || '' ) ),
		forget: async () => {
			const s = await saved();
			const st = await storeNow();
			if ( st && sender.origin === st.origin && ( ! msg.id || msg.id === s.tokenId ) ) {
				await forget();
			}
			return status();
		},
		page: async () => api( 'ext/page', { url: String( msg.url || '' ), product: Number( msg.product ) || ( await takeFocus( sender, String( msg.url || '' ) ) ), site: msg.site ? 1 : 0, look: msg.look ? 1 : 0 } ),
		scrape: () => api( 'ext/scrape', { url: String( msg.url || '' ), product: Number( msg.product ) || 0 } ),
		snapshot: () => api( 'ext/snapshot', { url: String( msg.url || '' ), html: String( msg.html || '' ), product: Number( msg.product ) || 0 } ),
		map: () => api( 'ext/map', { url: String( msg.url || '' ), op: String( msg.op || '' ), field: String( msg.field || '' ), sel: String( msg.sel || '' ), mode: String( msg.mode || '' ), html: String( msg.html || '' ), warn: String( msg.warn || '' ), where: String( msg.where || '' ), layout: Number.isInteger( msg.layout ) ? msg.layout : '', lines: ( msg.lines || [] ).slice( 0, 20 ).map( String ), offset: Number( msg.offset ) || 0, proposal: Number( msg.proposal ) || 0, decision: String( msg.decision || '' ) } ),
		write: () => api( 'ext/write', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, expect: ( msg.expect || [] ).map( String ), only: ( msg.only || [] ).map( String ), html: String( msg.html || '' ) } ),
		undo: () => api( 'ext/undo', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, what: String( msg.what || '' ) } ),
		pictures: () => api( 'ext/pictures', { op: String( msg.op || '' ), url: String( msg.url || '' ), product: Number( msg.product ) || 0, pictures: ( msg.pictures || [] ).map( String ), videos: ( msg.videos || [] ).map( String ), audio: ( msg.audio || [] ).map( String ), html: String( msg.html || '' ) } ),
		veto: () => api( 'ext/veto', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, picture: String( msg.picture || '' ) } ),
		products: () => api( 'ext/products', { url: String( msg.url || '' ), q: String( msg.q || '' ) } ),
		link: () => api( 'ext/link', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, confirm: msg.confirm ? 1 : 0, html: String( msg.html || '' ) } ),
		next: () => api( 'ext/next', { url: String( msg.url || '' ), product: Number( msg.product ) || 0 } ),
		send: () => api( 'ext/send', { url: String( msg.url || '' ), op: String( msg.op || '' ), urls: ( msg.urls || [] ).map( String ), page: String( msg.page || '' ), html: String( msg.html || '' ), gone: Number( msg.gone ) || 0, ids: ( msg.ids || [] ).map( Number ), mode: String( msg.mode || '' ) } ),
		site: () => api( 'ext/site', { url: String( msg.url || '' ), op: String( msg.op || '' ), product: Number( msg.product ) || 0 } ),
		layout: () => api( 'ext/layout', { url: String( msg.url || '' ), op: String( msg.op || '' ), part: String( msg.part || '' ), offset: Number( msg.offset ) || 0, product: Number( msg.product ) || 0, batch: String( msg.batch || '' ), fresh: msg.fresh ? 1 : 0 } ),
		mapping: () => api( 'ext/mapping', { url: String( msg.url || '' ), op: String( msg.op || '' ), product: Number( msg.product ) || 0, was: String( msg.was || '' ), target: String( msg.target || '' ), term: Number( msg.term ) || 0, q: String( msg.q || '' ), html: String( msg.html || '' ), panel: msg.panel ? 1 : 0 } ),
		sitepart: () => api( 'ext/sitepart', { url: String( msg.url || '' ), op: String( msg.op || '' ), part: String( msg.part || '' ), offset: Number( msg.offset ) || 0, product: Number( msg.product ) || 0, batch: String( msg.batch || '' ), fresh: msg.fresh ? 1 : 0 } ),
		held: () => api( 'ext/held', { url: String( msg.url || '' ), product: Number( msg.product ) || 0 } ),
		hand: () => inHand( sender, String( msg.op || '' ), Number( msg.product ) || 0, String( msg.title || '' ), String( msg.url || '' ) ),
		disclaimer: () => api( 'ext/disclaimer', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, text: String( msg.text || '' ) } ),
		example: () => api( 'ext/example', { url: String( msg.url || '' ), product: Number( msg.product ) || 0, parts: ( msg.parts || [] ).map( String ), scope: String( msg.scope || '' ), stays: msg.stays ? 1 : 0 } ),
		focus: () => keepFocus( sender, Number( msg.product ) || 0, String( msg.url || '' ), String( msg.title || '' ) ),
		panelstate: () => panelState( sender, !! msg.open, !! msg.folded ),
		perm: () => allowed( String( msg.url || '' ) ).then( ( ok ) => ( { ok: true, data: { allowed: ok } } ) ),
		allow: () => askAllow( String( msg.url || '' ) ),
		inject: () => inject( sender, String( msg.set || '' ) ),
		opensend: () => openSend( String( msg.host || '' ) ),
		sendstop: () => stopSend( String( msg.host || '' ) ),
		sendsay: () => relaySend( sender, msg ),
		connect: async () => chrome.tabs.create( { url: await connectPage() } ).then( () => ( { ok: true } ) ),
	}[ msg.type ];
	if ( ! run ) {
		return false;
	}
	Promise.resolve( run() ).then( reply, () => reply( { ok: false, data: { message: MESSAGES.offline } } ) );
	return true; // the answer comes later
} );

const bareHost = ( u ) => {
	try {
		return new URL( u ).hostname.toLowerCase().replace( /^www\./, '' );
	} catch ( e ) {
		return '';
	}
};

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

/** An origin's match pattern ("https://example.com/*"), or '' for a page the panel can't go on (the store's own included). */
const originOf = ( u ) => {
	try {
		const x = new URL( u );
		return /^https?:$/.test( x.protocol ) && ! ( store && x.origin === store.origin ) ? `${ x.origin }/*` : '';
	} catch ( e ) {
		return '';
	}
};

/** Chrome lets the extension onto this page's site without a click (the per-site permission). */
async function allowed( url ) {
	const s = await storeNow();
	if ( s && url.startsWith( `${ s.origin }/` ) ) {
		return true; // the store's own site: the panel there says so, there is nothing to ask for
	}
	const o = originOf( url );
	return o !== '' && chrome.permissions.contains( { origins: [ o ] } ).catch( () => false );
}

/** The panel's "Keep it open as you browse": a small extension window where the owner's click lets Chrome ask. */
async function askAllow( url ) {
	const o = originOf( url );
	if ( ! o ) {
		return { ok: false, data: { message: MESSAGES.badPage } };
	}
	await chrome.windows.create( { url: chrome.runtime.getURL( `allow.html?o=${ encodeURIComponent( o ) }` ), type: 'popup', width: 460, height: 300 } );
	return { ok: true };
}

/** The panel says whether it is open (and folded) in its tab: kept for the browser session. */
async function panelState( sender, open, folded ) {
	if ( ! sender.tab || ! sender.tab.id ) {
		return { ok: false };
	}
	const key = `panel${ sender.tab.id }`;
	await ( open ? chrome.storage.session.set( { [ key ]: { open, folded } } ) : chrome.storage.session.remove( key ) );
	return { ok: true };
}

/**
 * Installed, updated or reloaded: Chrome leaves the old bridge.js dead in the store's tabs already open, so the
 * Chrome extension page there couldn't connect until it was reloaded (owner 2026-11-02). The bridge goes back in, and
 * stays registered on the store's admin pages. A new copy that knows no store opens connect.html to ask for it.
 */
chrome.runtime.onInstalled.addListener( async ( details ) => {
	await chrome.storage.session.set( { runningBuild: await onDisk() } );
	await bridgeOn();
	for ( const t of await storeTabs() ) {
		await chrome.scripting.executeScript( { target: { tabId: t.id }, files: [ 'bridge.js' ] } ).catch( () => {} );
	}
	if ( details.reason === 'update' ) {
		await restorePanels();
	} else if ( details.reason === 'install' && ! ( await storeNow() ) ) {
		chrome.tabs.create( { url: chrome.runtime.getURL( 'connect.html' ) } );
	}
} );
chrome.runtime.onStartup.addListener( async () => {
	await chrome.storage.session.set( { runningBuild: await onDisk() } );
	await bridgeOn();
} );

/**
 * Automatic updates (owner 2026-11-03: "downloading, removing the old extension, uploading the new one, reloading
 * webpages, reconnecting gets tedious"). The Mac's updater (ExtUpdater on the server) copies a new build into this
 * folder, build.json last. Every minute the worker compares the folder's build.json with the build it runs; when they
 * differ it keeps the open panels (a reload empties storage.session), reloads itself from the folder (the same folder,
 * so the same extension: its connection stays) and puts the panels back. Not while the live mapper or the picture
 * picker is open on a page: their picks aren't saved yet, so the reload waits until they close.
 */
const UPDATE = { alarm: 'folder', everyMin: 1, restoreMs: 5 * 60 * 1000 };
chrome.alarms.create( UPDATE.alarm, { periodInMinutes: UPDATE.everyMin } );
chrome.alarms.onAlarm.addListener( ( a ) => {
	if ( a.name === UPDATE.alarm ) {
		selfUpdate();
	}
} );

/** A page where the live mapper or the picture picker is open. Tabs the extension can't reach don't count. */
async function busy() {
	for ( const t of await chrome.tabs.query( {} ).catch( () => [] ) ) {
		const r = await chrome.scripting.executeScript( { target: { tabId: t.id }, func: () => !! document.querySelector( '#mmpt-live-mapper, mmi-product-data-picker' ) } ).catch( () => null );
		if ( r && r[ 0 ] && r[ 0 ].result ) {
			return true;
		}
	}
	return false;
}

let updating = false;
async function selfUpdate() {
	const [ running, disk ] = [ await buildId(), await onDisk() ];
	if ( updating || ! running || ! disk || running === disk ) {
		return;
	}
	if ( await busy() ) {
		chrome.action.setBadgeBackgroundColor( { color: '#2271b1' } );
		chrome.action.setBadgeText( { text: '↻' } );
		chrome.action.setTitle( { title: `${ TITLE }: a new version is ready. It loads when the mapper or picker on your page closes.` } );
		return;
	}
	updating = true;
	await chrome.storage.local.set( { restore: { at: Date.now(), session: await chrome.storage.session.get( null ) } } );
	chrome.runtime.reload();
}

/** After a self-update: the open panels' state comes back, and each panel is put back on its page. */
async function restorePanels() {
	const { restore } = await chrome.storage.local.get( 'restore' );
	await chrome.storage.local.remove( 'restore' );
	if ( ! restore || Date.now() - restore.at > UPDATE.restoreMs ) {
		return;
	}
	const kept = Object.assign( {}, restore.session || {} );
	delete kept.runningBuild;
	await chrome.storage.session.set( kept );
	for ( const k of Object.keys( kept ) ) {
		const m = /^panel(\d+)$/.exec( k );
		const tab = m ? await chrome.tabs.get( Number( m[ 1 ] ) ).catch( () => null ) : null;
		if ( tab ) {
			await reopen( tab.id, tab.url || '' );
		}
	}
}

chrome.tabs.onRemoved.addListener( ( tabId ) => chrome.storage.session.remove( [ `panel${ tabId }`, `focus${ tabId }`, `hand${ tabId }` ] ) );

/** A page loaded in a tab whose panel is open: the panel comes back (folded if it was), where Chrome allows it. */
async function reopen( tabId, url ) {
	const st = ( await chrome.storage.session.get( `panel${ tabId }` ) )[ `panel${ tabId }` ];
	if ( ! st || ! st.open || ! ( await saved() ).token ) {
		return;
	}
	if ( ! ( await allowed( url ) ) ) {
		chrome.action.setBadgeBackgroundColor( { tabId, color: '#2271b1' } );
		chrome.action.setBadgeText( { tabId, text: '+' } );
		chrome.action.setTitle( { tabId, title: `${ TITLE }: click to bring the panel back on this site` } );
		return;
	}
	try {
		await startPanel( tabId, !! st.folded );
	} catch ( e ) {
		// a page the extension can't run on (Chrome's own, the Web Store): the button stays as it is
	}
}

/** A page loaded: the panel comes back if it was open; the page Next opened says there is a product to carry on with. */
chrome.tabs.onUpdated.addListener( async ( tabId, info, tab ) => {
	if ( info.status !== 'complete' ) {
		return;
	}
	reopen( tabId, tab.url || '' );
	const f = ( await chrome.storage.session.get( `focus${ tabId }` ) )[ `focus${ tabId }` ];
	if ( f && Date.now() - f.at < FOCUS_MS ) {
		chrome.action.setBadgeBackgroundColor( { tabId, color: '#2271b1' } );
		chrome.action.setBadgeText( { tabId, text: '1' } );
		chrome.action.setTitle( { tabId, title: `${ TITLE }: click to carry on with ${ f.title || 'the next product' }` } );
	}
} );

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
 * The toolbar button: the panel on this page (again: shown or hidden). Not connected yet: on a store's Chrome extension
 * page the bridge goes on that page, so it can connect there; anywhere else the connect page opens.
 */
chrome.action.onClicked.addListener( async ( tab ) => {
	// First, while Chrome still counts the click: ask once per site to keep the panel as the owner browses it.
	const o = originOf( tab.url || '' );
	const asked = o && ! declined.has( o ) ? chrome.permissions.request( { origins: [ o ] } ).then( ( yes ) => {
		if ( ! yes ) {
			declined.add( o );
			chrome.storage.local.set( { declined: [ ...declined ] } );
		}
	}, () => {} ) : null; // no prompt from here: the panel's "Keep it open as you browse" asks instead
	chrome.action.setBadgeText( { tabId: tab.id, text: '' } );
	if ( ! ( await saved() ).token ) {
		await asked;
		await connectFrom( tab );
		return;
	}
	if ( ! tab.id || ! /^https?:/.test( tab.url || '' ) ) {
		flash( tab.id, MESSAGES.badPage );
		return;
	}
	await asked;
	startPanel( tab.id, false ).then( () => startSend( tab.id ) ).catch( () => flash( tab.id, MESSAGES.badPage ) );
} );

/** Not connected, the toolbar button clicked: see the listener above. */
async function connectFrom( tab ) {
	const here = connectPageOrigin( tab.url || '' );
	if ( ! here || ! tab.id ) {
		chrome.tabs.create( { url: await connectPage() } );
		return;
	}
	await addPending( here );
	await bridgeOn();
	await chrome.scripting.executeScript( { target: { tabId: tab.id }, files: [ 'bridge.js' ] } ).catch( () => {} ); // the click lets it onto this page (activeTab)
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
 * The panel on a tab, told this run's build (a panel left from before a self-update is replaced, not toggled) and the
 * store it works with (its links and words).
 */
async function startPanel( tabId, folded ) {
	const s = await storeNow();
	await chrome.scripting.executeScript( { target: { tabId }, func: ( f, b, st ) => {
		window.__mmiExtStart = { folded: f, build: b, store: st };
	}, args: [ folded, await buildId(), s ? { origin: s.origin, base: s.base } : null ] } );
	await chrome.scripting.executeScript( { target: { tabId }, files: PANEL_FILES } );
}

/** A short "!" on the button, with the reason as its tooltip, where the panel can't go (Chrome's own pages, the Web Store). */
function flash( tabId, title ) {
	if ( ! tabId ) {
		return;
	}
	chrome.action.setBadgeText( { tabId, text: '!' } );
	chrome.action.setTitle( { tabId, title } );
	setTimeout( () => {
		chrome.action.setBadgeText( { tabId, text: '' } );
		chrome.action.setTitle( { tabId, title: TITLE } );
	}, 4000 );
}
