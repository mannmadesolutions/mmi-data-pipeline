/**
 * The Chrome extension page's Reference library section (Library::render_section()).
 *
 * Reference sites: listed with the pages kept from each, added and removed here (POST library/sites). For each, the
 * extension in this browser (through its bridge.js on this page) says whether Chrome lets it onto that site; "Allow in
 * Chrome" opens the extension's small window where a click lets Chrome ask (pages are kept only where it is allowed).
 *
 * Kept pages: newest first, or what a search finds (GET library?q=). A row's Text opens the kept text and earlier
 * versions under it (GET library/page); Delete asks in the row first.
 *
 *   window.mmptLibrary = { rest, nonce }
 */
( function () {
	'use strict';

	const CFG = window.mmptLibrary;
	if ( ! CFG ) {
		return;
	}
	const TAG = 'mmiExt';
	const LIMITS = { typeMs: 300, rows: 100, textChars: 200000 };
	const MESSAGES = {
		noSites: 'No reference sites yet. Add the help or knowledge-base site you read most.',
		noPages: 'Nothing kept yet. With the extension connected and the site allowed, open a page on it in Chrome.',
		noMatch: 'No kept page matches.',
		allowed: 'Allowed',
		notAllowed: 'Not allowed',
		noExtension: 'Extension not found',
		notConnected: 'Not connected',
		allow: 'Allow in Chrome',
		allowAsked: 'Chrome asks in the small window that opened. Then come back here.',
		remove: 'Remove',
		removed: 'Removed %s. Pages already kept from it stay in the library.',
		added: 'Added %s. Pages you open there in Chrome are kept once the site is allowed below.',
		text: 'Text',
		hide: 'Hide',
		del: 'Delete',
		delAsk: 'Delete this page and its earlier versions?',
		yes: 'Delete',
		no: 'Keep',
		total: '%n of %t pages',
		totalOne: '%t pages',
		notStated: 'Not stated',
		earlier: 'Earlier versions',
		failed: 'That didn’t work: %s',
		other: '%n pages kept from sites no longer listed.',
	};

	const $ = ( id ) => document.getElementById( id );
	const sitesBody = $( 'mmpt-lib-sites' );
	const pagesBody = $( 'mmpt-lib-pages' );
	const q = $( 'mmpt-lib-q' );
	const filter = $( 'mmpt-lib-filter' );
	const total = $( 'mmpt-lib-total' );
	const said = $( 'mmpt-lib-said' );
	const form = $( 'mmpt-lib-add' );
	const input = $( 'mmpt-lib-site' );
	let sites = [];
	let ext = null; // { paired, allowed: { site: bool } } from the extension, or null while it hasn't answered
	let typing = 0;

	const post = ( data ) => window.postMessage( Object.assign( { [ TAG ]: true, from: 'page' }, data ), location.origin );

	function api( route, method, body ) {
		return window.fetch( CFG.rest + route, {
			method,
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
		said.className = `notice inline notice-${ kind }`;
		said.querySelector( 'p' ).textContent = text;
		said.hidden = ! text;
	}

	const el = ( tag, props, ...kids ) => {
		const n = document.createElement( tag );
		Object.assign( n, props || {} );
		n.append( ...kids.filter( ( k ) => k !== null && k !== undefined ) );
		return n;
	};
	const day = ( s ) => ( s ? String( s ).slice( 0, 10 ) : '' );
	const message = ( row, cols, text ) => {
		row.replaceChildren( el( 'td', { colSpan: cols, className: 'description', textContent: text } ) );
	};

	/* ── Reference sites ── */

	function paintSites() {
		sitesBody.replaceChildren();
		if ( ! sites.length ) {
			message( sitesBody.appendChild( el( 'tr' ) ), 5, MESSAGES.noSites );
		}
		for ( const s of sites ) {
			const state = ! ext ? MESSAGES.noExtension : ! ext.paired ? MESSAGES.notConnected : ext.allowed && ext.allowed[ s.site ] ? MESSAGES.allowed : MESSAGES.notAllowed;
			const ok = state === MESSAGES.allowed;
			const actions = el( 'td', { className: 'mmpt-lib-actions' } );
			if ( ext && ext.paired && ! ok ) {
				actions.append( el( 'button', { type: 'button', className: 'button button-small', textContent: MESSAGES.allow, onclick: () => {
					post( { op: 'liballow', site: s.site } );
					say( 'info', MESSAGES.allowAsked );
				} } ) );
			}
			actions.append( el( 'button', { type: 'button', className: 'button button-small button-link-delete', textContent: MESSAGES.remove, onclick: () => saveSites( sites.map( ( x ) => x.site ).filter( ( x ) => x !== s.site ), MESSAGES.removed.replace( '%s', s.site ) ) } ) );
			sitesBody.append( el( 'tr', {},
				el( 'td', {}, el( 'code', { textContent: s.site } ) ),
				el( 'td', { textContent: String( s.pages ) } ),
				el( 'td', { textContent: day( s.last ) || '—' } ),
				el( 'td', {}, el( 'span', { className: `mmi-badge ${ ok ? 'success' : 'warning' }`, textContent: state } ) ),
				actions
			) );
		}
		const keep = filter.value;
		filter.replaceChildren( filter.options[ 0 ] || el( 'option', { value: '', textContent: 'Every site' } ) );
		for ( const s of sites ) {
			filter.append( el( 'option', { value: s.site, textContent: s.site } ) );
		}
		filter.value = sites.some( ( s ) => s.site === keep ) ? keep : '';
	}

	function saveSites( list, done, saved ) {
		return api( 'library/sites', 'POST', { sites: list } )
			.then( () => {
				if ( saved ) {
					saved();
				}
				say( 'success', done );
				post( { op: 'libstate' } ); // the extension learns the new list now, not at its next refresh
				return load().then( () => true );
			} )
			.catch( ( e ) => {
				say( 'error', MESSAGES.failed.replace( '%s', e.message ) );
				return false;
			} );
	}

	/* ── Kept pages ── */

	function paintPages( d ) {
		pagesBody.replaceChildren();
		total.textContent = d.total > d.rows.length ? MESSAGES.total.replace( '%n', d.rows.length ).replace( '%t', d.total ) : MESSAGES.totalOne.replace( '%t', d.total );
		if ( ! d.rows.length ) {
			message( pagesBody.appendChild( el( 'tr' ) ), 6, q.value.trim() ? MESSAGES.noMatch : MESSAGES.noPages );
			return;
		}
		for ( const r of d.rows ) {
			const tr = el( 'tr' );
			const title = el( 'td', { className: 'mmpt-lib-title' }, el( 'a', { href: r.url, target: '_blank', rel: 'noopener', textContent: r.title || r.url } ) );
			if ( r.snippet ) {
				title.append( el( 'p', { className: 'description', textContent: r.snippet } ) );
			}
			const actions = el( 'td', { className: 'mmpt-lib-actions' } );
			const textBtn = el( 'button', { type: 'button', className: 'button button-small', textContent: MESSAGES.text } );
			textBtn.onclick = () => toggleText( tr, r.id, textBtn );
			const delBtn = el( 'button', { type: 'button', className: 'button button-small button-link-delete', textContent: MESSAGES.del } );
			delBtn.onclick = () => askDelete( tr, r.id );
			actions.append( textBtn, delBtn );
			tr.append( title, el( 'td', { textContent: r.site } ), el( 'td', { textContent: r.updated_on || MESSAGES.notStated } ), el( 'td', { textContent: day( r.kept_at ) } ), el( 'td', { textContent: String( 1 + Number( r.versions || 0 ) ) } ), actions );
			pagesBody.append( tr );
		}
	}

	function toggleText( tr, id, btn ) {
		const next = tr.nextElementSibling;
		if ( next && next.classList.contains( 'mmpt-lib-textrow' ) ) {
			next.remove();
			btn.textContent = MESSAGES.text;
			return;
		}
		const cell = el( 'td', { colSpan: 6 }, el( 'span', { className: 'spinner is-active' } ) );
		tr.after( el( 'tr', { className: 'mmpt-lib-textrow' }, cell ) );
		btn.textContent = MESSAGES.hide;
		api( `library/page?id=${ id }`, 'GET' ).then( ( p ) => {
			const fields = Object.entries( p.fields || {} ).map( ( [ k, v ] ) => el( 'li', {}, el( 'strong', { textContent: `${ k }: ` } ), v ) );
			const earlier = ( p.earlier || [] ).map( ( e ) => el( 'li', { textContent: `Kept ${ day( e.kept_at ) }, replaced ${ day( e.replaced_at ) } (${ e.chars } characters) · wp mmpt library get ${ id } --version=${ e.id }` } ) );
			cell.replaceChildren(
				fields.length ? el( 'ul', { className: 'mmpt-lib-fields' }, ...fields ) : null,
				el( 'pre', { className: 'mmpt-lib-text', textContent: String( p.text || '' ).slice( 0, LIMITS.textChars ) } ),
				earlier.length ? el( 'p', { className: 'description', textContent: MESSAGES.earlier } ) : null,
				earlier.length ? el( 'ul', { className: 'mmpt-lib-fields' }, ...earlier ) : null
			);
		} ).catch( ( e ) => cell.replaceChildren( MESSAGES.failed.replace( '%s', e.message ) ) );
	}

	function askDelete( tr, id ) {
		const cell = tr.lastElementChild;
		const was = Array.from( cell.childNodes );
		cell.replaceChildren(
			el( 'span', { className: 'description', textContent: MESSAGES.delAsk + ' ' } ),
			el( 'button', { type: 'button', className: 'button button-small button-link-delete', textContent: MESSAGES.yes, onclick: () => api( 'library/delete', 'POST', { id } ).then( load ).catch( ( e ) => say( 'error', MESSAGES.failed.replace( '%s', e.message ) ) ) } ),
			el( 'button', { type: 'button', className: 'button button-small', textContent: MESSAGES.no, onclick: () => cell.replaceChildren( ...was ) } )
		);
	}

	function load() {
		const args = new URLSearchParams( { q: q.value.trim(), site: filter.value, limit: String( LIMITS.rows ) } );
		return api( `library?${ args }`, 'GET' ).then( ( d ) => {
			sites = d.sites || [];
			paintSites();
			paintPages( d );
			if ( d.other ) {
				total.textContent += ' · ' + MESSAGES.other.replace( '%n', d.other );
			}
		} ).catch( ( e ) => say( 'error', MESSAGES.failed.replace( '%s', e.message ) ) );
	}

	/* ── The extension in this browser ── */

	window.addEventListener( 'message', ( e ) => {
		const d = e.data;
		if ( e.source !== window || e.origin !== location.origin || ! d || d[ TAG ] !== true || d.from !== 'extension' ) {
			return;
		}
		if ( d.op === 'here' && ! ext ) {
			post( { op: 'libstate' } );
		} else if ( d.op === 'libstate' ) {
			ext = { paired: !! d.paired, allowed: d.allowed || {} };
			paintSites();
		}
	} );
	window.addEventListener( 'focus', () => post( { op: 'libstate' } ) ); // back from Chrome's prompt
	post( { op: 'libstate' } );

	/* ── Controls ── */

	$( 'mmpt-lib-add-open' ).addEventListener( 'click', () => {
		form.hidden = false;
		input.focus();
	} );
	$( 'mmpt-lib-add-cancel' ).addEventListener( 'click', () => {
		form.hidden = true;
	} );
	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		const v = input.value.trim();
		if ( ! v ) {
			return;
		}
		saveSites( [ ...sites.map( ( s ) => s.site ), v ], MESSAGES.added.replace( '%s', v ), () => {
			input.value = '';
			form.hidden = true;
		} );
	} );
	q.addEventListener( 'input', () => {
		clearTimeout( typing );
		typing = setTimeout( load, LIMITS.typeMs );
	} );
	filter.addEventListener( 'change', load );

	load();
}() );
