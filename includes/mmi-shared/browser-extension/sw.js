/**
 * The extension's shell: the service worker (mmi-admin/docs/BROWSER_EXTENSION_PLATFORM_SCOPE.md).
 *
 * The extension is this shell plus parts, one per MMI plugin that brings one (parts/{id}/, loaded by parts.js, which
 * the store writes into the download: MMI_Browser_Extension::parts_js()). The shell holds what no part owns: the store
 * and its key, every call to the store, each tab's panel state, Chrome's per-site permission, the toolbar button, the
 * bridge on the store's admin pages, and self-update of a folder copy. A part registers its message handlers and hooks
 * with mmiExt.part() from its sw-part.js; the shell routes the messages it doesn't own to the part that registered them.
 *
 * The key stays here: calls send no cookies (credentials: 'omit'), the key alone signs in, and only on the routes of the
 * parts the store has (MMI_Browser_Extension on the server). Which parts this person may use, and where each one's routes
 * are, come from the store's hello; a part whose plugin isn't on the store isn't there, so its panel never opens.
 *
 * The store: no address is built in. A copy downloaded from a store's Chrome extension page names that store in
 * build.json (its manifest also gets the store's host permission and the bridge on its admin pages). Any other copy, such
 * as the Chrome Web Store's, learns it when connecting: the toolbar button on a store's Chrome extension page, or the
 * address typed on connect.html. The key handed over on a store's page connects the extension to that store; connecting
 * on another store's page moves it there.
 *
 * The panel stays with the owner as they browse (owner 2026-11-02): each tab's panel state (open, folded) is kept for the
 * browser session (panelstate), and when the tab loads another page the panel is put back, on sites Chrome lets the
 * extension onto (asked per site at the first toolbar click there, or from a panel's "Keep it open as you browse").
 * Closing the panel (× or the button) turns it off for that tab. Which part's panel a page gets: the part whose sites
 * name the page's host, else the part that takes any page ('*').
 *
 * Every call says which download this is (X-MMI-Ext-Build, from build.json), so a panel can say when a newer one is out.
 */
'use strict';

const SHELL_ROUTES = 'wp-json/mmi-ext/v1/';
const LEGACY_ROUTES = 'mmpt/v1/'; // where copies from before the shell sent every call (Product Data's routes)
const CONNECT_SLUG = 'mmi-chrome-extension';
const CONNECT_SLUGS = [ CONNECT_SLUG, 'mmi-product-extension' ]; // the second: the page's address before the shell
const CONNECT_PATH = `wp-admin/admin.php?page=${ CONNECT_SLUG }`;
const BRIDGE_ID = 'mmi-bridge';
const SHELL_API = 1; // MMI_Browser_Extension::API
const LIMITS = { callMs: 60000, partsMs: 10 * 60 * 1000 };
const TITLE = chrome.runtime.getManifest().name;
const MESSAGES = {
	notConnected: 'This browser isn’t connected to your store yet.',
	offline: 'The store didn’t answer. Check the connection and try again.',
	failed: 'The store answered with an error (%s).',
	noRoutes: 'Your store doesn’t answer this part of the extension: the plugin that brings it needs updating, or isn’t active there.',
	notThisSite: `This page didn’t ask the extension to connect here. Click ${ TITLE } in Chrome’s toolbar on this page, then connect again.`,
	noHost: `Chrome hasn’t let the extension reach %h. Click ${ TITLE } in Chrome’s toolbar on this page and allow it, then connect again.`,
	badPage: 'The panel can’t run on this page.',
	nothing: 'Nothing on your store works with this page.',
	noAccess: `Chrome no longer lets the extension work on this page (it was reloaded or left). Click ${ TITLE } in the toolbar again.`,
};

/* ── Parts ───────────────────────────────────────────────────────────── */

/** The parts this copy carries (parts.js), each with what its sw-part.js registered. id → definition. */
const parts = new Map();

/**
 * What a part's sw-part.js uses, and how it registers. A part's definition (all optional but id):
 *   messages:     { type: ( msg, sender ) => answer } for messages from its own page scripts and bridge
 *   startPanel:   ( tabId, folded, url ) => Promise, its panel on a tab
 *   onClick:      ( tab ) => Promise, after its panel is started by the toolbar button
 *   reopenTitle:  async ( tabId ) => the button's title on a page Chrome hasn't allowed yet ('' for the usual one)
 *   onTabLoaded:  ( tabId, url, tab ) => void, a page finished loading in a tab
 *   onTabClosed:  ( tabId ) => void
 *   busy:         a CSS selector: a page showing it holds work a self-update must not cut off
 *   afterReply:   ( msg, sender, res ) => void, after any of its messages was answered
 */
const mmiExt = {
	part( def ) {
		if ( def && typeof def.id === 'string' ) {
			parts.set( def.id, def );
		}
	},
	api: ( partId, route, body ) => partApi( partId, route, body ),
	saved: () => saved(),
	storeNow: () => storeNow(),
	storeTabs: () => storeTabs(),
	allowed: ( url ) => allowed( url ),
	askAllow: ( url, why ) => askAllow( url, why ),
	originOf: ( url ) => originOf( url ),
	bareHost: ( url ) => bareHost( url ),
	buildId: () => buildId(),
	flash: ( tabId, title ) => flash( tabId, title ),
	startPanel: ( tabId, folded ) => startPanel( tabId, folded ),
	MESSAGES,
	TITLE,
};
self.mmiExt = mmiExt; // a part's sw-part.js reaches the shell through this

/* ── The store and its key ───────────────────────────────────────────── */

/** Sites where the owner said no to Chrome's prompt: not asked again from the toolbar (a panel still offers it). */
let declined = new Set();
chrome.storage.local.get( 'declined' ).then( ( s ) => {
	declined = new Set( s.declined || [] );
} );
chrome.storage.onChanged.addListener( ( ch, area ) => {
	if ( area === 'local' && ch.declined ) {
		declined = new Set( ch.declined.newValue || [] ); // allow.html allowed a site
	}
} );

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
 * A store as the extension keeps it: { origin, base, rest, shell, connect }, every address on the store's own origin, or
 * null. base is where WordPress is (it may sit under a path); shell is the shell's routes; rest the routes copies from
 * before the shell called (a store kept by one of those has no shell: it is worked out from rest); connect its Chrome
 * extension page.
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
		const rest = String( x.rest || `${ base }/wp-json/${ LEGACY_ROUTES }` );
		const shell = String( x.shell || ( rest.includes( LEGACY_ROUTES ) ? rest.replace( LEGACY_ROUTES, 'mmi-ext/v1/' ) : `${ base }/${ SHELL_ROUTES }` ) );
		const connect = String( x.connect || `${ base }/${ CONNECT_PATH }` );
		const out = { origin: o.origin, base, rest, shell, connect };
		return [ rest, shell, connect ].every( ( a ) => a.startsWith( `${ o.origin }/` ) ) ? out : null;
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
		return /^https?:$/.test( u.protocol ) && /\/wp-admin\/admin\.php$/.test( u.pathname ) && CONNECT_SLUGS.includes( u.searchParams.get( 'page' ) ) ? u.origin : '';
	} catch ( e ) {
		return '';
	}
}

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

/** The scripts that go on the store's admin pages: the shell's bridge and each part's (parts.js). */
const bridgeFiles = () => ( Array.isArray( self.MMI_EXT_BRIDGE ) && self.MMI_EXT_BRIDGE.length ? self.MMI_EXT_BRIDGE : [ 'bridge.js' ] );

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
		await chrome.scripting.registerContentScripts( [ { id: BRIDGE_ID, matches, js: bridgeFiles(), runAt: 'document_idle' } ] ).catch( () => {} );
	}
}

/** A store being connected: its Chrome extension page may hand over a key. */
async function addPending( origin ) {
	const { pending = [] } = await chrome.storage.local.get( 'pending' );
	if ( ! pending.includes( origin ) ) {
		await chrome.storage.local.set( { pending: [ ...pending, origin ].slice( -5 ) } );
	}
}

/** The store's admin tabs (the bridge's), for the bridge after an update and for a part's news to them. */
async function storeTabs() {
	const s = await storeNow();
	return s ? chrome.tabs.query( { url: `${ s.origin }/*wp-admin/*` } ).catch( () => [] ) : [];
}

/**
 * The build this run of the extension was loaded from (MMI_Browser_Extension::build()). Kept for the browser session:
 * Chrome stops an idle worker and starts it again, and by then the folder may hold a newer build than the code running.
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
	await chrome.storage.local.remove( [ 'token', 'tokenId', 'user', 'parts' ] );
}

/**
 * A call to the store: POST $base + $route with the key. Resolves { ok, status, data }; a refused key is forgotten
 * (unless it was one being tried, $token). $protocol: the calling part's, told to the store.
 */
async function call( base, route, body, protocol, token ) {
	const key = token || ( await saved() ).token;
	if ( ! key || ! base ) {
		return { ok: false, status: 0, data: { message: MESSAGES.notConnected, connect: true } };
	}
	const ctl = new AbortController();
	const timer = setTimeout( () => ctl.abort(), LIMITS.callMs );
	try {
		const res = await fetch( base + route, {
			method: 'POST',
			credentials: 'omit',
			cache: 'no-store',
			signal: ctl.signal,
			headers: { 'Content-Type': 'application/json', 'X-MMI-Ext-Token': key, 'X-MMI-Ext-Protocol': String( protocol || 0 ), 'X-MMI-Ext-Build': await buildId(), 'X-MMI-Ext-Version': chrome.runtime.getManifest().version },
			body: JSON.stringify( body || {} ),
		} );
		const data = await res.json().catch( () => ( {} ) );
		if ( res.status === 401 && ! token ) {
			await forget();
			data.connect = true;
		}
		if ( res.status === 404 && data.code === 'rest_no_route' ) {
			data.noRoute = true;
			data.message = MESSAGES.noRoutes; // an older plugin, or none: WordPress's own "no route" says nothing to a person
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

/** Parts as the store's hello gives them: [ { id, name, rest, protocol, sites, locked } ]. */
const partsOf = ( list ) => ( Array.isArray( list ) ? list : [] ).filter( ( p ) => p && typeof p.id === 'string' ).map( ( p ) => ( { id: p.id, name: String( p.name || p.id ), rest: String( p.rest || '' ), protocol: Number( p.protocol ) || 0, sites: ( p.sites || [] ).map( String ), locked: String( p.locked || '' ) } ) );

/**
 * The store's hello, through the shell's routes. A store from before the shell has none: it answers only Product Data's
 * old hello, and that is its one part (every page, its old routes).
 */
async function hello( at, token ) {
	const r = await call( at.shell, 'hello', {}, SHELL_API, token );
	if ( r.ok || ! r.data.noRoute ) {
		return { ok: r.ok, data: { ...r.data, parts: partsOf( r.data.parts ) } };
	}
	const old = await call( at.rest, 'ext/hello', {}, 0, token );
	return { ok: old.ok, data: { ...old.data, parts: old.ok ? [ { id: 'product-data', name: 'Product Data', rest: at.rest, protocol: Number( old.data.protocol ) || 0, sites: [ '*' ], locked: '' } ] : [] } };
}

/** The parts this person may use on the store, asked again after LIMITS.partsMs (or now, $fresh). */
async function storeParts( fresh ) {
	const { parts: kept } = await chrome.storage.local.get( 'parts' );
	if ( ! fresh && kept && Date.now() - kept.at < LIMITS.partsMs ) {
		return kept.list;
	}
	const s = await storeNow();
	if ( ! s || ! ( await saved() ).token ) {
		return kept ? kept.list : [];
	}
	const h = await hello( s );
	if ( ! h.ok ) {
		return kept ? kept.list : []; // the store didn't answer: what it last said is kept (a refused key was forgotten)
	}
	await chrome.storage.local.set( { parts: { at: Date.now(), list: h.data.parts } } );
	return h.data.parts;
}

/** A call to one of a part's routes ("ext/page"): its routes' address from the store's hello. */
async function partApi( partId, route, body ) {
	let p = ( await storeParts( false ) ).find( ( x ) => x.id === partId );
	if ( ! p ) {
		p = ( await storeParts( true ) ).find( ( x ) => x.id === partId );
	}
	if ( ! p ) {
		return { ok: false, status: 404, data: { message: ( await saved() ).token ? MESSAGES.noRoutes : MESSAGES.notConnected, connect: ! ( await saved() ).token } };
	}
	return call( p.rest, route, body, p.protocol );
}

const bareHost = ( u ) => {
	try {
		return new URL( u ).hostname.toLowerCase().replace( /^www\./, '' );
	} catch ( e ) {
		return '';
	}
};

/**
 * The part a page belongs to: one this copy carries and the store has for this person, whose sites name the page's host
 * (or a parent domain of it), else the one that takes any page ('*'). null when none.
 */
async function partFor( url ) {
	const host = bareHost( url );
	if ( ! host ) {
		return null;
	}
	const list = ( await storeParts( false ) ).filter( ( p ) => parts.has( p.id ) );
	const named = list.find( ( p ) => p.sites.some( ( s ) => s !== '*' && ( host === bareHost( `https://${ s }/` ) || host.endsWith( `.${ bareHost( `https://${ s }/` ) }` ) ) ) );
	return named || list.find( ( p ) => p.sites.includes( '*' ) ) || null;
}

/**
 * A key from a store's Chrome extension page (bridge.js there): kept only when that store knows it, and the extension
 * is then that store's. Only the store it is connected to, or one being connected, may hand one over, and only where
 * Chrome lets the extension reach it. $rest: the routes copies from before the shell call (the page hands it over for
 * them); $shell: the shell's.
 */
async function pair( token, id, rest, shell, sender ) {
	const origin = sender.origin || '';
	const s = await storeNow();
	const { pending = [] } = await chrome.storage.local.get( 'pending' );
	if ( ! origin || ! ( ( s && s.origin === origin ) || pending.includes( origin ) ) ) {
		return { ok: false, message: MESSAGES.notThisSite };
	}
	if ( ! ( await chrome.permissions.contains( { origins: [ `${ origin }/*` ] } ).catch( () => false ) ) ) {
		return { ok: false, message: MESSAGES.noHost.replace( '%h', new URL( origin ).hostname ) };
	}
	const mine = ( a ) => ( a.startsWith( `${ origin }/` ) ? a : '' );
	const at = storeOf( { base: baseOf( sender.url || '' ) || origin, rest: mine( rest ), shell: mine( shell ) } );
	if ( ! at ) {
		return { ok: false, message: MESSAGES.notThisSite };
	}
	const h = await hello( at, token );
	if ( ! h.ok ) {
		return { ok: false, message: h.data.message };
	}
	store = at;
	await chrome.storage.local.set( { token, tokenId: id, user: h.data.user || '', store: at, pending: [], parts: { at: Date.now(), list: h.data.parts } } );
	await bridgeOn();
	return { ok: true, user: h.data.user || '' };
}

/** What the extension says about itself to the store's pages and connect.html. */
async function status() {
	const s = await saved();
	const st = await storeNow();
	const can = [ ...parts.values() ].flatMap( ( p ) => p.can || [] );
	return { name: TITLE, version: chrome.runtime.getManifest().version, api: SHELL_API, parts: [ ...parts.keys() ], paired: !! s.token, tokenId: s.tokenId || '', user: s.user || '', store: st ? st.origin : '', can };
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

/* ── Pages, permission and panels ────────────────────────────────────── */

/** An origin's match pattern ("https://example.com/*"), or '' for a page a panel can't ask Chrome for (the store's own included). */
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
		return true; // the store's own site: there is nothing to ask for
	}
	const o = originOf( url );
	return o !== '' && chrome.permissions.contains( { origins: [ o ] } ).catch( () => false );
}

/** A panel's "Keep it open as you browse": a small extension window where the owner's click lets Chrome ask. */
async function askAllow( url, why ) {
	const o = originOf( url );
	if ( ! o ) {
		return { ok: false, data: { message: MESSAGES.badPage } };
	}
	await chrome.windows.create( { url: chrome.runtime.getURL( `allow.html?o=${ encodeURIComponent( o ) }${ why ? `&w=${ encodeURIComponent( why ) }` : '' }` ), type: 'popup', width: 460, height: 300 } );
	return { ok: true };
}

/** A panel says whether it is open (and folded) in its tab: kept for the browser session. */
async function panelState( sender, open, folded ) {
	if ( ! sender.tab || ! sender.tab.id ) {
		return { ok: false };
	}
	const key = `panel${ sender.tab.id }`;
	await ( open ? chrome.storage.session.set( { [ key ]: { open, folded } } ) : chrome.storage.session.remove( key ) );
	return { ok: true };
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

/** The panel of the part this tab's page belongs to. Throws where there is none, or the page can't take one. */
async function startPanel( tabId, folded ) {
	const tab = await chrome.tabs.get( tabId );
	const p = await partFor( tab.url || '' );
	const def = p && parts.get( p.id );
	if ( ! p || ! def || ! def.startPanel ) {
		throw new Error( MESSAGES.nothing );
	}
	if ( p.locked ) {
		flash( tabId, p.locked );
		return;
	}
	await def.startPanel( tabId, folded, tab.url || '' );
}

/** A page loaded in a tab whose panel is open: the panel comes back (folded if it was), where Chrome allows it. */
async function reopen( tabId, url ) {
	const st = ( await chrome.storage.session.get( `panel${ tabId }` ) )[ `panel${ tabId }` ];
	if ( ! st || ! st.open || ! ( await saved() ).token ) {
		return;
	}
	if ( ! ( await allowed( url ) ) ) {
		const p = await partFor( url );
		const def = p && parts.get( p.id );
		const said = def && def.reopenTitle ? await def.reopenTitle( tabId ) : '';
		chrome.action.setBadgeBackgroundColor( { tabId, color: '#2271b1' } );
		chrome.action.setBadgeText( { tabId, text: '+' } );
		chrome.action.setTitle( { tabId, title: said || `${ TITLE }: click to bring the panel back on this site` } );
		return;
	}
	try {
		await startPanel( tabId, !! st.folded );
	} catch ( e ) {
		// a page the extension can't run on (Chrome's own, the Web Store), or one no part takes: the button stays as it is
	}
}

/* ── Install, update, self-update ────────────────────────────────────── */

/**
 * Installed, updated or reloaded: Chrome leaves the old bridge dead in the store's tabs already open, so the Chrome
 * extension page there couldn't connect until it was reloaded (owner 2026-11-02). The bridge goes back in, and stays
 * registered on the store's admin pages. A new copy that knows no store opens connect.html to ask for it.
 */
chrome.runtime.onInstalled.addListener( async ( details ) => {
	await chrome.storage.session.set( { runningBuild: await onDisk() } );
	await bridgeOn();
	for ( const t of await storeTabs() ) {
		await chrome.scripting.executeScript( { target: { tabId: t.id }, files: bridgeFiles() } ).catch( () => {} );
	}
	if ( details.reason === 'update' ) {
		await storeParts( true );
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
 * Automatic updates (owner 2026-11-03). The Mac's updater (ExtUpdater on the server) copies a new build into this folder,
 * build.json last. Every minute the worker compares the folder's build.json with the build it runs; when they differ it
 * keeps the open panels (a reload empties storage.session), reloads itself from the folder (the same folder, so the same
 * extension: its connection stays) and puts the panels back. Not while a part's page holds unsaved work (its busy).
 */
const UPDATE = { alarm: 'folder', everyMin: 1, restoreMs: 5 * 60 * 1000 };
if ( chrome.alarms ) { // a folder copy only: the Chrome Web Store's copy updates through the store and has no alarms
	chrome.alarms.create( UPDATE.alarm, { periodInMinutes: UPDATE.everyMin } );
	chrome.alarms.onAlarm.addListener( ( a ) => {
		if ( a.name === UPDATE.alarm ) {
			selfUpdate();
		}
	} );
}

/** A page holding a part's unsaved work. Tabs the extension can't reach don't count. */
async function busy() {
	const sel = [ ...parts.values() ].map( ( p ) => p.busy ).filter( Boolean ).join( ', ' );
	if ( ! sel ) {
		return false;
	}
	for ( const t of await chrome.tabs.query( {} ).catch( () => [] ) ) {
		const r = await chrome.scripting.executeScript( { target: { tabId: t.id }, func: ( s ) => !! document.querySelector( s ), args: [ sel ] } ).catch( () => null );
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
		chrome.action.setTitle( { title: `${ TITLE }: a new version is ready. It loads when the work open on your page is saved or closed.` } );
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

/* ── Tabs and the toolbar button ─────────────────────────────────────── */

chrome.tabs.onRemoved.addListener( ( tabId ) => {
	chrome.storage.session.remove( `panel${ tabId }` );
	for ( const p of parts.values() ) {
		if ( p.onTabClosed ) {
			p.onTabClosed( tabId );
		}
	}
} );

/** A page loaded: its panel comes back if it was open, and each part hears of it. */
chrome.tabs.onUpdated.addListener( ( tabId, info, tab ) => {
	if ( info.status !== 'complete' ) {
		return;
	}
	reopen( tabId, tab.url || '' );
	for ( const p of parts.values() ) {
		if ( p.onTabLoaded ) {
			p.onTabLoaded( tabId, tab.url || '', tab );
		}
	}
} );

/**
 * The toolbar button: the panel of the part this page belongs to (again: shown or hidden). Not connected yet: on a
 * store's Chrome extension page the bridge goes on that page, so it can connect there; anywhere else the connect page opens.
 */
chrome.action.onClicked.addListener( async ( tab ) => {
	// First, while Chrome still counts the click: ask once per site to keep the panel as the owner browses it.
	const o = originOf( tab.url || '' );
	const asked = o && ! declined.has( o ) ? chrome.permissions.request( { origins: [ o ] } ).then( ( yes ) => {
		if ( ! yes ) {
			declined.add( o );
			chrome.storage.local.set( { declined: [ ...declined ] } );
		}
	}, () => {} ) : null; // no prompt from here: a panel's "Keep it open as you browse" asks instead
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
	const p = await partFor( tab.url || '' );
	const def = p && parts.get( p.id );
	if ( ! def ) {
		flash( tab.id, MESSAGES.nothing );
		return;
	}
	startPanel( tab.id, false ).then( () => ( def.onClick ? def.onClick( tab ) : null ) ).catch( () => flash( tab.id, MESSAGES.badPage ) );
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
	await chrome.scripting.executeScript( { target: { tabId: tab.id }, files: bridgeFiles() } ).catch( () => {} ); // the click lets it onto this page (activeTab)
}

/* ── Messages ────────────────────────────────────────────────────────── */

/** The shell's own messages: from bridge.js on the store, connect.html, allow.html and any part's panel. */
const SHELL_MESSAGES = {
	status: () => status(),
	pair: ( msg, sender ) => pair( String( msg.token || '' ), String( msg.id || '' ), String( msg.rest || '' ), String( msg.shell || '' ), sender ),
	connectto: ( msg, sender ) => connectTo( sender, String( msg.url || '' ) ),
	forget: async ( msg, sender ) => {
		const s = await saved();
		const st = await storeNow();
		if ( st && sender.origin === st.origin && ( ! msg.id || msg.id === s.tokenId ) ) {
			await forget();
		}
		return status();
	},
	panelstate: ( msg, sender ) => panelState( sender, !! msg.open, !! msg.folded ),
	perm: ( msg ) => allowed( String( msg.url || '' ) ).then( ( ok ) => ( { ok: true, data: { allowed: ok } } ) ),
	allow: ( msg ) => askAllow( String( msg.url || '' ) ),
	connect: async () => chrome.tabs.create( { url: await connectPage() } ).then( () => ( { ok: true } ) ),
};

chrome.runtime.onMessage.addListener( ( msg, sender, reply ) => {
	if ( sender.id !== chrome.runtime.id || ! msg || typeof msg.type !== 'string' ) {
		return false;
	}
	let owner = null;
	let run = Object.prototype.hasOwnProperty.call( SHELL_MESSAGES, msg.type ) ? SHELL_MESSAGES[ msg.type ] : null;
	if ( ! run ) {
		for ( const p of parts.values() ) {
			if ( p.messages && Object.prototype.hasOwnProperty.call( p.messages, msg.type ) ) {
				owner = p;
				run = p.messages[ msg.type ];
				break;
			}
		}
	}
	if ( ! run ) {
		return false;
	}
	Promise.resolve( run( msg, sender ) ).then( ( res ) => {
		reply( res );
		if ( owner && owner.afterReply ) {
			owner.afterReply( msg, sender, res );
		}
	}, () => reply( { ok: false, data: { message: MESSAGES.offline } } ) );
	return true; // the answer comes later
} );

/* ── The parts this copy carries ─────────────────────────────────────── */

try {
	importScripts( 'parts.js' ); // MMI_EXT_PARTS, MMI_EXT_BRIDGE, and each part's sw-part.js
} catch ( e ) {
	// a copy without parts.js (a bare folder): the shell alone, which can connect and says no part takes the page
}
