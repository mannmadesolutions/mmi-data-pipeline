/**
 * Product Content — one part in the Vendor maps editor's Product page view (ProductPreview::mark()): every place
 * the page shows the part (the template's elements, wrapped in .mmpt-pv-part, which draws no box) is outlined with
 * the part's name, and the first is scrolled into view. When the page shows it nowhere, the preview label says so.
 */
( function () {
	const DATA = window.mmptPreview || {};
	const SELECTORS = { part: '.mmpt-pv-part', label: '.mmpt-preview-label' };
	const BOX_CLASS = 'mmpt-pv-box';
	const THRESHOLDS = { settleMs: 800, minPx: 4 };

	/** The boxes an element's children draw, looking through those that draw none (a loop's per-item markers). */
	const drawn = ( el ) => [ ...el.children ].flatMap( ( c ) => ( window.getComputedStyle( c ).display === 'contents' ? drawn( c ) : [ c.getBoundingClientRect() ] ) );

	/** The page's own boxes inside a marker, as one: a marker itself draws none. */
	function rectOf( marker ) {
		const rects = drawn( marker ).filter( ( r ) => r.width >= THRESHOLDS.minPx && r.height >= THRESHOLDS.minPx );
		if ( ! rects.length ) {
			return null;
		}
		const top = Math.min( ...rects.map( ( r ) => r.top ) );
		const left = Math.min( ...rects.map( ( r ) => r.left ) );
		return { top: top + window.scrollY, left: left + window.scrollX, width: Math.max( ...rects.map( ( r ) => r.right ) ) - left, height: Math.max( ...rects.map( ( r ) => r.bottom ) ) - top };
	}

	/** Outermost markers only: a loop inside a marked container is the same place. */
	const places = () => [ ...document.querySelectorAll( SELECTORS.part ) ].filter( ( m ) => ! m.parentElement.closest( SELECTORS.part ) );

	function draw() {
		document.querySelectorAll( `.${ BOX_CLASS }` ).forEach( ( b ) => b.remove() );
		const boxes = places().map( rectOf ).filter( Boolean ).map( ( r ) => {
			const box = document.createElement( 'div' );
			box.className = BOX_CLASS;
			box.dataset.label = DATA.label || '';
			box.style.setProperty( '--top', `${ r.top }px` );
			box.style.setProperty( '--left', `${ r.left }px` );
			box.style.setProperty( '--width', `${ r.width }px` );
			box.style.setProperty( '--height', `${ r.height }px` );
			document.body.appendChild( box );
			return box;
		} );
		return boxes;
	}

	function start() {
		const boxes = draw();
		const label = document.querySelector( SELECTORS.label );
		if ( ! boxes.length ) {
			if ( label && DATA.missing ) {
				label.textContent = DATA.missing;
				label.classList.add( 'is-missing' );
			}
			return;
		}
		boxes[ 0 ].scrollIntoView( { block: 'center' } );
		// Pictures and fonts arriving move the page: the outlines follow.
		window.addEventListener( 'resize', draw );
		window.addEventListener( 'load', draw );
		window.setTimeout( draw, THRESHOLDS.settleMs );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
