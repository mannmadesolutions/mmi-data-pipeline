/**
 * Staff side of the order message thread (MMI_Order_Conversation): every
 * `.mmi-conv-staff` panel on the page — the order screen's Conversation box,
 * or a thread opened in MMI Admin → Customer Messages. Delegated, so panels
 * added later (the inbox loads them on demand) work without binding.
 *
 *  - Send: the text plus the attachments picked (chips), to the reply action.
 *  - Draft answer: queue a draft, ask again every few seconds until it's back.
 *  - Link a product / Coupon: search inline under the box (or make a new
 *    coupon there), each pick becomes a chip; the server rebuilds every
 *    attachment from its id when the message is sent.
 *  - A sent message fires `mmi-conv-sent` on the panel ({ orderId }).
 *
 * Config: window.mmiOrderConversation (MMI_Order_Conversation::enqueue_staff_assets()).
 */
( function () {
	'use strict';

	const CONFIG = window.mmiOrderConversation || {};
	const T = CONFIG.i18n || {};
	const SEARCH_MS = 300;
	const DRAFT_POLL_MS = 4000;
	const DRAFT_TRIES = 60;

	const SELECTORS = {
		panel: '.mmi-conv-staff',
		send: '.mmi-conv-send',
		draft: '.mmi-conv-draft',
		field: '.mmi-conv-input',
		status: '.mmi-conv-status',
		thread: '.mmi-conv-thread-wrap',
		chips: '.mmi-conv-chips',
		tool: '.mmi-conv-tool',
		pane: '.mmi-conv-pane',
		search: '.mmi-conv-search',
		results: '.mmi-conv-results',
		couponTab: '.mmi-conv-coupon-tab',
		couponNew: '.mmi-conv-coupon-new',
		couponCreate: '.mmi-conv-coupon-create',
	};

	/** Pending attachments per panel element. */
	const pending = new WeakMap();
	const timers = new WeakMap();
	/** Staff text an improvement replaced, per panel, for Undo. */
	const undos = new WeakMap();

	function post( action, panel, data, nonceKey ) {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'order_id', panel.dataset.order );
		body.append( 'nonce', panel.dataset[ nonceKey || 'nonce' ] );
		Object.keys( data || {} ).forEach( ( k ) => body.append( k, data[ k ] ) );
		return fetch( CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
			.then( ( r ) => r.json() )
			.then( ( res ) => {
				if ( ! res || ! res.success ) {
					throw new Error( res && res.data ? String( res.data ) : T.failed );
				}
				return res.data;
			} );
	}

	function el( tag, attrs, ...kids ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => {
			if ( k === 'text' ) {
				node.textContent = v;
			} else if ( v !== false && v !== null && v !== undefined ) {
				node.setAttribute( k, v === true ? '' : v );
			}
		} );
		kids.forEach( ( kid ) => kid && node.append( kid ) );
		return node;
	}

	function setStatus( panel, text ) {
		panel.querySelector( SELECTORS.status ).textContent = text || '';
	}

	function busy( panel, on ) {
		panel.querySelectorAll( `${ SELECTORS.send }, ${ SELECTORS.draft }` ).forEach( ( b ) => {
			b.disabled = on;
		} );
	}

	/* ── Attachments (chips) ────── */

	function list( panel ) {
		if ( ! pending.has( panel ) ) {
			pending.set( panel, [] );
		}
		return pending.get( panel );
	}

	function renderChips( panel ) {
		const box = panel.querySelector( SELECTORS.chips );
		const items = list( panel );
		box.hidden = ! items.length;
		box.replaceChildren( ...items.map( ( item, i ) => el( 'span', { class: 'mmi-conv-chip' },
			el( 'span', { class: `dashicons dashicons-${ item.type === 'coupon' ? 'tickets-alt' : 'products' }`, 'aria-hidden': 'true' } ),
			el( 'span', { text: item.label } ),
			el( 'button', { type: 'button', class: 'mmi-conv-chip-remove', 'data-index': String( i ), 'aria-label': T.remove, text: '×' } )
		) ) );
	}

	function addChip( panel, row ) {
		const items = list( panel );
		if ( items.some( ( x ) => x.type === row.type && Number( x.id ) === Number( row.id ) ) ) {
			return;
		}
		if ( items.length >= ( CONFIG.maxAttachments || 6 ) ) {
			setStatus( panel, T.tooMany );
			return;
		}
		items.push( { type: row.type, id: row.id, label: row.label } );
		renderChips( panel );
		setStatus( panel, T.attached );
	}

	/* ── Tools pane (search, new coupon) ────── */

	function closePane( panel ) {
		panel.querySelector( SELECTORS.pane ).hidden = true;
		panel.querySelectorAll( SELECTORS.tool ).forEach( ( b ) => b.setAttribute( 'aria-expanded', 'false' ) );
	}

	function openPane( panel, tool ) {
		const pane = panel.querySelector( SELECTORS.pane );
		const same = ! pane.hidden && pane.dataset.tool === tool;
		panel.querySelectorAll( SELECTORS.tool ).forEach( ( b ) => b.setAttribute( 'aria-expanded', 'false' ) );
		if ( same ) {
			closePane( panel );
			return;
		}
		pane.dataset.tool = tool;
		pane.hidden = false;
		panel.querySelector( `${ SELECTORS.tool }[data-tool="${ tool }"]` ).setAttribute( 'aria-expanded', 'true' );
		pane.querySelector( SELECTORS.couponNew ).hidden = true;
		pane.querySelectorAll( SELECTORS.couponTab ).forEach( ( t ) => {
			t.hidden = tool !== 'coupon';
			t.classList.toggle( 'active', t.dataset.tab === 'existing' );
		} );
		const search = pane.querySelector( SELECTORS.search );
		search.hidden = false;
		pane.querySelector( SELECTORS.results ).hidden = false;
		search.value = '';
		search.placeholder = tool === 'coupon' ? T.searchCoupons : T.searchProducts;
		search.focus();
		find( panel, '' );
	}

	function find( panel, q ) {
		const pane = panel.querySelector( SELECTORS.pane );
		const results = pane.querySelector( SELECTORS.results );
		results.replaceChildren( el( 'li', { class: 'mmi-conv-result-note', text: T.searching } ) );
		post( CONFIG.actions.find, panel, { kind: pane.dataset.tool, q }, 'toolsNonce' )
			.then( ( data ) => {
				const rows = data.rows || [];
				if ( ! rows.length ) {
					results.replaceChildren( el( 'li', { class: 'mmi-conv-result-note', text: q ? T.noMatch : T.typeToSearch } ) );
					return;
				}
				results.replaceChildren( ...rows.map( ( row ) => el( 'li', {},
					el( 'button', { type: 'button', class: 'mmi-conv-result', disabled: row.disabled ? true : null, 'data-row': JSON.stringify( row ) },
						el( 'strong', { text: row.label } ),
						el( 'span', { text: row.detail || '' } )
					)
				) ) );
			} )
			.catch( ( e ) => results.replaceChildren( el( 'li', { class: 'mmi-conv-result-note', text: e.message } ) ) );
	}

	function createCoupon( panel ) {
		const form = panel.querySelector( SELECTORS.couponNew );
		const val = ( name ) => form.querySelector( `[name="${ name }"]` );
		const button = form.querySelector( SELECTORS.couponCreate );
		button.disabled = true;
		setStatus( panel, T.creatingCoupon );
		post( CONFIG.actions.coupon, panel, {
			discount_type: val( 'discount_type' ).value,
			amount: val( 'amount' ).value,
			days: val( 'days' ).value,
			code: val( 'code' ).value,
			only_customer: val( 'only_customer' ).checked ? '1' : '',
			one_use: val( 'one_use' ).checked ? '1' : '',
		}, 'toolsNonce' )
			.then( ( data ) => {
				addChip( panel, data.row );
				val( 'code' ).value = '';
				closePane( panel );
				setStatus( panel, T.couponCreated.replace( '%s', data.row.label ) );
			} )
			.catch( ( e ) => setStatus( panel, e.message ) )
			.finally( () => {
				button.disabled = false;
			} );
	}

	/* ── Send and draft ────── */

	function send( panel ) {
		const field = panel.querySelector( SELECTORS.field );
		const items = list( panel );
		if ( ! field.value.trim() && ! items.length ) {
			field.focus();
			return;
		}
		busy( panel, true );
		setStatus( panel, T.sending );
		post( CONFIG.actions.reply, panel, {
			body: field.value,
			attachments: JSON.stringify( items.map( ( x ) => ( { type: x.type, id: x.id } ) ) ),
		} )
			.then( ( data ) => {
				panel.querySelector( SELECTORS.thread ).innerHTML = data.thread;
				field.value = '';
				undos.delete( panel );
				syncAiLabel( panel );
				pending.set( panel, [] );
				renderChips( panel );
				closePane( panel );
				setStatus( panel, data.message );
				panel.dispatchEvent( new CustomEvent( 'mmi-conv-sent', { bubbles: true, detail: { orderId: Number( panel.dataset.order ) } } ) );
			} )
			.catch( ( e ) => setStatus( panel, e.message || T.notSent ) )
			.finally( () => busy( panel, false ) );
	}

	/** The AI button reads "Improve…" once there is text to improve (when the provider offers it). */
	function syncAiLabel( panel ) {
		const button = panel.querySelector( SELECTORS.draft );
		if ( ! button || ! button.dataset.labelImprove ) {
			return;
		}
		const improve = !! panel.querySelector( SELECTORS.field ).value.trim();
		button.textContent = improve ? button.dataset.labelImprove : button.dataset.labelDraft;
		button.dataset.mode = improve ? 'improve' : 'draft';
	}

	/** After an improvement: an Undo beside the message, until staff edit the text. */
	function offerUndo( panel, original ) {
		undos.set( panel, original );
		panel.querySelector( SELECTORS.status ).append( ' ', el( 'button', { type: 'button', class: 'mmi-conv-undo', text: T.undo } ) );
	}

	function undo( panel ) {
		if ( ! undos.has( panel ) ) {
			return;
		}
		const field = panel.querySelector( SELECTORS.field );
		field.value = undos.get( panel );
		undos.delete( panel );
		field.focus();
		syncAiLabel( panel );
		setStatus( panel, T.restored );
	}

	function draft( panel ) {
		const field = panel.querySelector( SELECTORS.field );
		const original = field.value;
		const improve = panel.querySelector( SELECTORS.draft ).dataset.mode === 'improve' && !! original.trim();
		if ( ! improve && original.trim() && ! window.confirm( T.replaceDraft ) ) {
			return;
		}
		undos.delete( panel );
		let tries = 0;
		const ask = ( request ) => post( CONFIG.actions.draft, panel, request ? { request } : ( improve ? { text: original } : {} ) ).then( ( data ) => {
			if ( data.pending ) {
				setStatus( panel, data.message );
				if ( ++tries > DRAFT_TRIES ) {
					throw new Error( T.draftSlow );
				}
				return new Promise( ( done ) => setTimeout( done, DRAFT_POLL_MS ) ).then( () => ask( data.pending ) );
			}
			field.value = data.text;
			field.focus();
			syncAiLabel( panel );
			setStatus( panel, data.message );
			if ( data.improved ) {
				offerUndo( panel, original );
			}
		} );
		busy( panel, true );
		setStatus( panel, improve ? T.improving : T.drafting );
		ask( '' ).catch( ( e ) => setStatus( panel, e.message || T.noDraft ) ).finally( () => busy( panel, false ) );
	}

	/* ── Wiring ────── */

	// A box the browser refilled on reload gets the right label.
	document.querySelectorAll( SELECTORS.panel ).forEach( syncAiLabel );

	document.addEventListener( 'click', ( ev ) => {
		const panel = ev.target.closest( SELECTORS.panel );
		if ( ! panel ) {
			return;
		}
		const target = ev.target;
		if ( target.closest( SELECTORS.send ) ) {
			send( panel );
		} else if ( target.closest( SELECTORS.draft ) ) {
			draft( panel );
		} else if ( target.closest( '.mmi-conv-undo' ) ) {
			undo( panel );
		} else if ( target.closest( SELECTORS.tool ) ) {
			openPane( panel, target.closest( SELECTORS.tool ).dataset.tool );
		} else if ( target.closest( '.mmi-conv-result' ) ) {
			addChip( panel, JSON.parse( target.closest( '.mmi-conv-result' ).dataset.row ) );
		} else if ( target.closest( '.mmi-conv-chip-remove' ) ) {
			list( panel ).splice( Number( target.closest( '.mmi-conv-chip-remove' ).dataset.index ), 1 );
			renderChips( panel );
		} else if ( target.closest( SELECTORS.couponTab ) ) {
			const tab = target.closest( SELECTORS.couponTab ).dataset.tab;
			const pane = panel.querySelector( SELECTORS.pane );
			pane.querySelectorAll( SELECTORS.couponTab ).forEach( ( t ) => t.classList.toggle( 'active', t.dataset.tab === tab ) );
			pane.querySelector( SELECTORS.couponNew ).hidden = tab !== 'new';
			pane.querySelector( SELECTORS.search ).hidden = tab === 'new';
			pane.querySelector( SELECTORS.results ).hidden = tab === 'new';
			if ( tab === 'existing' ) {
				find( panel, pane.querySelector( SELECTORS.search ).value.trim() );
			}
		} else if ( target.closest( SELECTORS.couponCreate ) ) {
			createCoupon( panel );
		}
	} );

	document.addEventListener( 'input', ( ev ) => {
		if ( ev.target.matches( SELECTORS.field ) ) {
			const panel = ev.target.closest( SELECTORS.panel );
			if ( panel ) {
				syncAiLabel( panel );
				if ( undos.delete( panel ) ) {
					panel.querySelector( '.mmi-conv-undo' )?.remove();
				}
			}
			return;
		}
		if ( ! ev.target.matches( SELECTORS.search ) ) {
			return;
		}
		const panel = ev.target.closest( SELECTORS.panel );
		window.clearTimeout( timers.get( panel ) );
		timers.set( panel, window.setTimeout( () => find( panel, ev.target.value.trim() ), SEARCH_MS ) );
	} );
}() );
