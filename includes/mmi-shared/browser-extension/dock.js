/**
 * Where the extension's panels sit on a page. One setting for the panel (panel.js) and the live mapper
 * (live-mapper.js, through mapper.js's bridge): docked right (the usual place), left, top or bottom, and how wide (left,
 * right) or tall (top, bottom) it is, dragged at the panel's inner edge. Kept in chrome.storage.local, so every open tab
 * follows a change at once (owner 2026-11-15: "allow the user to adjust the width and docking location").
 *
 * A panel calls attach( host ): the host gets data-dock="<side>" and the sizes as --mmi-dw / --mmi-dh, and the panel's own
 * shadow-root CSS places its boxes from those (:host([data-dock="left"]) .p { … }). grip() and menu() are the controls;
 * CSS styles them and goes into each shadow root that uses them.
 */
( function () {
	'use strict';

	if ( window.__mmiExtDock ) {
		return;
	}

	const KEY = 'mmiDock';
	const SIDES = [ 'right', 'left', 'top', 'bottom' ];
	const SIZE = { w: 380, h: 320, minW: 300, maxW: 960, minH: 160, keep: 120, step: 16 }; // keep: page left showing beside a dragged panel
	const MESSAGES = {
		dock: 'Where the panel sits',
		grip: 'Drag to resize the panel. Arrow keys resize it too; double-click puts the usual size back.',
		sides: { right: 'Dock right', left: 'Dock left', top: 'Dock top', bottom: 'Dock bottom' },
		reset: 'Usual size',
	};
	const GLYPHS = { right: '◨', left: '◧', top: '⬒', bottom: '⬓' };

	const CSS = `
		.dock-grip { position: absolute; z-index: 3; touch-action: none; background: transparent; }
		.dock-grip:hover, .dock-grip:focus-visible, .dock-grip.on { background: var(--mmi-accent-grip); outline: none; }
		:host(:not([data-dock])) .dock-grip, :host([data-dock="right"]) .dock-grip { left: 0; top: 0; bottom: 0; width: 6px; cursor: ew-resize; }
		:host([data-dock="left"]) .dock-grip { right: 0; top: 0; bottom: 0; width: 6px; cursor: ew-resize; }
		:host([data-dock="top"]) .dock-grip { left: 0; right: 0; bottom: 0; height: 6px; cursor: ns-resize; }
		:host([data-dock="bottom"]) .dock-grip { left: 0; right: 0; top: 0; height: 6px; cursor: ns-resize; }
		.dock-menu { position: fixed; left: 0; top: 0; transform: translate(var(--mx, 0), var(--my, 0)); z-index: 2147483647; min-width: 150px; padding: 4px; box-sizing: border-box; background: #fff; color: #1d2327; border: 1px solid #c3c4c7; border-radius: 6px; box-shadow: 0 6px 20px rgba(0,0,0,.2); font: 13px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; text-align: left; }
		.dock-menu[hidden] { display: none; }
		.dock-menu button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 6px 8px; border: 0; border-radius: 4px; background: none; color: inherit; font: inherit; text-align: left; cursor: pointer; }
		.dock-menu button:hover, .dock-menu button:focus-visible { background: var(--mmi-accent-soft); outline: none; }
		.dock-menu button[aria-pressed="true"] { color: var(--mmi-accent); font-weight: 600; }
		.dock-menu .g { width: 16px; font-size: 15px; line-height: 1; text-align: center; }
		.dock-menu hr { margin: 4px 0; border: 0; border-top: 1px solid #dcdcde; }
	`;

	const norm = ( d ) => {
		const x = d && typeof d === 'object' ? d : {};
		const num = ( v, def, min, max ) => ( Number.isFinite( Number( v ) ) ? Math.round( Math.min( max, Math.max( min, Number( v ) ) ) ) : def );
		return {
			side: SIDES.includes( x.side ) ? x.side : 'right',
			w: num( x.w, SIZE.w, SIZE.minW, SIZE.maxW ),
			h: num( x.h, SIZE.h, SIZE.minH, 4000 ),
		};
	};

	let cur = norm( null );
	const subs = new Set();
	const tell = () => subs.forEach( ( fn ) => fn( cur ) );

	try {
		chrome.storage.local.get( KEY ).then( ( r ) => {
			if ( r && r[ KEY ] ) {
				cur = norm( r[ KEY ] );
				tell();
			}
		} ).catch( () => {} );
		chrome.storage.onChanged.addListener( ( ch, area ) => { // another tab moved it: this one follows
			if ( area === 'local' && ch[ KEY ] ) {
				cur = norm( ch[ KEY ].newValue );
				tell();
			}
		} );
	} catch ( e ) {
		// No extension storage (a test page): the setting lasts while the page does.
	}

	/** The host's side and sizes, as its shadow CSS reads them. */
	function apply( host, d ) {
		host.dataset.dock = d.side;
		host.style.setProperty( '--mmi-dw', `${ d.w }px` );
		host.style.setProperty( '--mmi-dh', `${ d.h }px` );
	}

	function set( patch ) {
		cur = norm( Object.assign( {}, cur, patch ) );
		tell();
		try {
			chrome.storage.local.set( { [ KEY ]: cur } ).catch( () => {} );
		} catch ( e ) {
			// as above
		}
	}

	/** The edge to drag: the panel's inner edge, wherever it is docked. Dragging changes this panel at once; letting go saves it. */
	function grip( host ) {
		const g = document.createElement( 'div' );
		g.className = 'dock-grip';
		g.tabIndex = 0;
		g.title = MESSAGES.grip;
		g.setAttribute( 'role', 'separator' );
		g.setAttribute( 'aria-label', MESSAGES.grip );
		const sizeAt = ( x, y ) => {
			const side = cur.side;
			if ( side === 'right' || side === 'left' ) {
				const w = side === 'right' ? window.innerWidth - 12 - x : x - 12;
				return { w: Math.min( w, window.innerWidth - SIZE.keep ) };
			}
			const h = side === 'bottom' ? window.innerHeight - 12 - y : y - 12;
			return { h: Math.min( h, window.innerHeight - SIZE.keep ) };
		};
		let live = null;
		g.addEventListener( 'pointerdown', ( e ) => {
			if ( e.button !== 0 ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			g.setPointerCapture( e.pointerId );
			g.classList.add( 'on' );
			live = cur;
		} );
		g.addEventListener( 'pointermove', ( e ) => {
			if ( live ) {
				live = norm( Object.assign( {}, cur, sizeAt( e.clientX, e.clientY ) ) );
				apply( host, live );
			}
		} );
		const end = () => {
			if ( live ) {
				g.classList.remove( 'on' );
				const done = live;
				live = null;
				set( { w: done.w, h: done.h } );
			}
		};
		g.addEventListener( 'pointerup', end );
		g.addEventListener( 'pointercancel', end );
		g.addEventListener( 'dblclick', () => set( { w: SIZE.w, h: SIZE.h } ) );
		g.addEventListener( 'keydown', ( e ) => {
			const grow = { right: 'ArrowLeft', left: 'ArrowRight', top: 'ArrowDown', bottom: 'ArrowUp' };
			const shrink = { right: 'ArrowRight', left: 'ArrowLeft', top: 'ArrowUp', bottom: 'ArrowDown' };
			const by = e.key === grow[ cur.side ] ? SIZE.step : ( e.key === shrink[ cur.side ] ? -SIZE.step : 0 );
			if ( by ) {
				e.preventDefault();
				set( cur.side === 'right' || cur.side === 'left' ? { w: cur.w + by } : { h: cur.h + by } );
			}
		} );
		return g;
	}

	/**
	 * The header button and its menu: the four sides and the usual size. The menu goes into the shadow root (fixed, placed
	 * under the button); it closes on a choice, Escape, or focus leaving it.
	 */
	function menu( cls ) {
		const btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = `${ cls } dock-btn`;
		btn.title = MESSAGES.dock;
		btn.setAttribute( 'aria-label', MESSAGES.dock );
		btn.setAttribute( 'aria-haspopup', 'true' );
		btn.setAttribute( 'aria-expanded', 'false' );
		const list = document.createElement( 'div' );
		list.className = 'dock-menu';
		list.setAttribute( 'role', 'group' );
		list.setAttribute( 'aria-label', MESSAGES.dock );
		list.hidden = true;
		const item = ( glyph, text, on ) => {
			const b = document.createElement( 'button' );
			b.type = 'button';
			const g = document.createElement( 'span' );
			g.className = 'g';
			g.setAttribute( 'aria-hidden', 'true' );
			g.textContent = glyph;
			b.append( g, document.createTextNode( text ) );
			b.addEventListener( 'click', () => {
				close();
				on();
			} );
			return b;
		};
		const sides = SIDES.map( ( s ) => {
			const b = item( GLYPHS[ s ], MESSAGES.sides[ s ], () => set( { side: s } ) );
			b.dataset.side = s;
			return b;
		} );
		list.append( ...sides, document.createElement( 'hr' ), item( '↺', MESSAGES.reset, () => set( { w: SIZE.w, h: SIZE.h } ) ) );
		const close = () => {
			list.hidden = true;
			btn.setAttribute( 'aria-expanded', 'false' );
		};
		const open = () => {
			sides.forEach( ( b ) => b.setAttribute( 'aria-pressed', String( b.dataset.side === cur.side ) ) );
			list.hidden = false;
			btn.setAttribute( 'aria-expanded', 'true' );
			const r = btn.getBoundingClientRect();
			const mw = list.offsetWidth;
			const mh = list.offsetHeight;
			const x = Math.max( 8, Math.min( r.right - mw, window.innerWidth - mw - 8 ) );
			const y = r.bottom + 4 + mh <= window.innerHeight - 8 ? r.bottom + 4 : Math.max( 8, r.top - 4 - mh );
			list.style.setProperty( '--mx', `${ x }px` );
			list.style.setProperty( '--my', `${ y }px` );
			( sides.find( ( b ) => b.dataset.side === cur.side ) || sides[ 0 ] ).focus();
		};
		btn.addEventListener( 'click', ( e ) => {
			e.stopPropagation();
			if ( list.hidden ) {
				open();
			} else {
				close();
			}
		} );
		list.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' ) {
				e.stopPropagation();
				close();
				btn.focus();
			}
		} );
		list.addEventListener( 'focusout', ( e ) => {
			if ( ! list.contains( e.relatedTarget ) && e.relatedTarget !== btn ) {
				close();
			}
		} );
		const show = ( d ) => {
			btn.textContent = GLYPHS[ d.side ];
		};
		show( cur );
		subs.add( show );
		return { button: btn, list, close, stop: () => subs.delete( show ) };
	}

	window.__mmiExtDock = {
		CSS,
		get: () => cur,
		set,
		/** Keeps host placed by the setting; returns the function that stops it (a panel taken away). */
		attach( host ) {
			const fn = ( d ) => apply( host, d );
			subs.add( fn );
			fn( cur );
			return () => subs.delete( fn );
		},
		grip,
		menu,
	};
}() );
