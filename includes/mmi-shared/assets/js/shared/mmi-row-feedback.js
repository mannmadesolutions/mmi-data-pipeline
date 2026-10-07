/**
 * Row feedback (shared lib 1.46.7, 2026-10-07): what a table row shows after
 * its own action, inside the row, with no table reload and no inserted
 * notice rows.
 *
 *   MMIRowFeedback.busy( tr, true|false )        dim and lock the row while its request runs
 *   MMIRowFeedback.message( cell, text, type )   a small message inside a cell (success|info|warning|error);
 *                                                 non-error messages clear on their own
 *   MMIRowFeedback.settle( tr, { text, type, cell, leave } )
 *                                                 the row's outcome shown in `cell` (default: its last cell);
 *                                                 with leave (default true) it fades out and is removed
 *   MMIRowFeedback.leave( tr )                   fade the row out and remove it (Promise)
 *
 * Why: a table that reloads after every row action loses the reviewer's place,
 * and a notice row inserted between rows pushes the table around. Product
 * Titles' Vendor URLs tab (2026-10-07, owner) is the first adopter. CSS:
 * mmi-suite-common.css → "Row feedback".
 */
( function () {
	'use strict';

	const CLASSES = {
		busy: 'mmi-row-busy',
		done: 'mmi-row-done',
		leaving: 'mmi-row-leaving',
		msg: 'mmi-row-msg',
	};
	const TIMING = { settleMs: 1400, leaveMs: 300, messageMs: 6000 };
	const TYPES = [ 'success', 'info', 'warning', 'error' ];

	function busy( tr, on ) {
		if ( tr ) {
			tr.classList.toggle( CLASSES.busy, !! on );
			tr.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		}
	}

	function message( cell, text, type = 'error', opts = {} ) {
		if ( ! cell ) {
			return null;
		}
		cell.querySelectorAll( `:scope > .${ CLASSES.msg }` ).forEach( ( m ) => m.remove() );
		const el = document.createElement( 'div' );
		el.className = `${ CLASSES.msg } is-${ TYPES.includes( type ) ? type : 'info' }`;
		el.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		el.textContent = text;
		cell.append( el );
		const ms = opts.ms ?? ( type === 'error' ? 0 : TIMING.messageMs );
		if ( ms > 0 ) {
			window.setTimeout( () => el.remove(), ms );
		}
		return el;
	}

	function leave( tr ) {
		return new Promise( ( resolve ) => {
			if ( ! tr || ! tr.isConnected ) {
				resolve();
				return;
			}
			tr.classList.add( CLASSES.leaving );
			window.setTimeout( () => {
				tr.remove();
				resolve();
			}, TIMING.leaveMs );
		} );
	}

	function settle( tr, { text = '', type = 'success', cell = null, leave: go = true, delayMs = TIMING.settleMs } = {} ) {
		if ( ! tr ) {
			return Promise.resolve();
		}
		busy( tr, false );
		tr.classList.add( CLASSES.done );
		const target = cell || tr.lastElementChild;
		if ( text && target ) {
			target.replaceChildren();
			message( target, text, type, { ms: 0 } );
		}
		if ( ! go ) {
			return Promise.resolve();
		}
		return new Promise( ( resolve ) => window.setTimeout( () => leave( tr ).then( resolve ), delayMs ) );
	}

	window.MMIRowFeedback = { busy, message, settle, leave };
}() );
