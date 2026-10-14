/**
 * Nonce refresh (shared lib 1.46.11, 2026-10-14): keeps the nonces a page
 * printed at load usable for as long as the page stays open.
 *
 *   MMINonces.watch( action, target, key = 'nonce' )   keep target[key] fresh for this nonce action
 *   MMINonces.get( action )                             the current value
 *   MMINonces.fresh( action )                           Promise of a value that is safe to send now
 *   MMINonces.refresh()                                 fetch new values for every watched action
 *   MMINonces.post( action, url, data )                 jQuery POST with data.nonce filled in
 *   MMINonces.ajax( action, settings )                  same, any $.ajax settings (data: plain object;
 *                                                         use .done/.fail/.always, not success/error)
 *   MMINonces.fetch( action, url, body, init )          fetch() POST of a FormData/URLSearchParams body
 *
 * post/ajax/fetch send a fresh nonce, and when the server answers 403 they
 * refresh once and resend if the nonce changed. A handler must reject a bad
 * nonce with 403 (check_ajax_referer() does) before doing anything else.
 *
 * Why: WordPress nonces last 12-24 h, so a tab left open longer failed every
 * request. mmi-hub refreshed them through Heartbeat until it was deleted
 * (2026-09-17). Heartbeat isn't used here: it doesn't run on the front end,
 * where the admin bar polls too, and it suspends after 10 idle minutes.
 * A nonce is refreshed only when a request needs it and it is a quarter of
 * its life old, so this adds one request per open tab every ~6 hours.
 *
 * Server side: the mmi_refresh_nonces AJAX action (bootstrap.php) returns
 * only actions a plugin registered with the 'mmi_refreshable_nonces' filter,
 * and only to users that registration allows.
 */
( function ( $ ) {
	'use strict';

	const CONFIG = window.mmiNonceRefresh || {};
	const FIELD = 'nonce';
	const FORBIDDEN = 403;
	const DEFAULT_LIFE_SECONDS = 86400;
	// A nonce stays valid for at least half of nonce_life after it is issued.
	const FRESH_FRACTION = 0.25;

	const freshForMs = ( parseInt( CONFIG.lifeSeconds, 10 ) || DEFAULT_LIFE_SECONDS ) * 1000 * FRESH_FRACTION;
	const entries = {}; // action => { value, issuedAt, targets: [ [ object, key ] ] }
	let inFlight = null;

	function watch( action, target, key = FIELD ) {
		if ( ! target || ! target[ key ] ) {
			return;
		}
		if ( ! entries[ action ] ) {
			entries[ action ] = { value: target[ key ], issuedAt: Date.now(), targets: [] };
		}
		const entry = entries[ action ];
		if ( ! entry.targets.some( ( [ obj, k ] ) => obj === target && k === key ) ) {
			entry.targets.push( [ target, key ] );
		}
		target[ key ] = entry.value;
	}

	function get( action ) {
		return entries[ action ] ? entries[ action ].value : '';
	}

	function apply( nonces ) {
		Object.keys( nonces || {} ).forEach( ( action ) => {
			const entry = entries[ action ];
			if ( ! entry || typeof nonces[ action ] !== 'string' ) {
				return;
			}
			entry.value = nonces[ action ];
			entry.issuedAt = Date.now();
			entry.targets.forEach( ( [ obj, key ] ) => {
				obj[ key ] = entry.value;
			} );
		} );
	}

	/** One request for every watched action; concurrent callers share it. */
	function refresh() {
		const actions = Object.keys( entries );
		if ( inFlight || ! actions.length || ! CONFIG.ajaxUrl ) {
			return inFlight || Promise.resolve();
		}
		const body = new URLSearchParams( { action: CONFIG.action } );
		actions.forEach( ( action ) => body.append( 'actions[]', action ) );
		inFlight = window
			.fetch( CONFIG.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } )
			.then( ( res ) => ( res.ok ? res.json() : null ) )
			.then( ( json ) => apply( json && json.success && json.data ? json.data.nonces : null ) )
			.catch( () => {} )
			.finally( () => {
				inFlight = null;
			} );
		return inFlight;
	}

	function fresh( action ) {
		const entry = entries[ action ];
		if ( entry && Date.now() - entry.issuedAt < freshForMs ) {
			return Promise.resolve( entry.value );
		}
		return refresh().then( () => get( action ) );
	}

	/** The nonce to resend with after a 403, or '' when a retry can't help. */
	function retryNonce( action, sent ) {
		return refresh().then( () => {
			const next = get( action );
			return next && next !== sent ? next : '';
		} );
	}

	function ajax( action, settings ) {
		const opts = $.extend( { type: 'POST' }, settings );
		const send = ( nonce ) => $.ajax( $.extend( {}, opts, { data: $.extend( {}, opts.data, { [ FIELD ]: nonce } ) } ) );
		const result = $.Deferred();
		const pass = ( jqPromise ) => jqPromise.then( result.resolve, result.reject );

		fresh( action ).then( ( nonce ) => {
			send( nonce ).then( result.resolve, ( xhr, ...rest ) => {
				if ( xhr.status !== FORBIDDEN ) {
					result.reject( xhr, ...rest );
					return;
				}
				retryNonce( action, nonce ).then( ( next ) => ( next ? pass( send( next ) ) : result.reject( xhr, ...rest ) ) );
			} );
		} );
		return result.promise();
	}

	function post( action, url, data ) {
		return ajax( action, { url, type: 'POST', data } );
	}

	function fetchWithNonce( action, url, body, init = {} ) {
		const send = ( nonce ) => {
			body.set( FIELD, nonce );
			return window.fetch( url, Object.assign( { method: 'POST', credentials: 'same-origin' }, init, { body } ) );
		};
		return fresh( action ).then( ( nonce ) =>
			send( nonce ).then( ( res ) => {
				if ( res.status !== FORBIDDEN ) {
					return res;
				}
				return retryNonce( action, nonce ).then( ( next ) => ( next ? send( next ) : res ) );
			} )
		);
	}

	window.MMINonces = { watch, get, fresh, refresh, ajax, post, fetch: fetchWithNonce };
} )( jQuery );
