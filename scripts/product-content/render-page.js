/**
 * Renders one vendor page in headless Chromium with everything opened up, for
 * the page store (Product Titles → PageStore::render()).
 *
 *   node render-page.js <url> <out.html>
 *
 * Opens every disclosure a visitor could open — <details>, aria-expanded
 * toggles, "compare / show all / see more" buttons — scrolls to the bottom
 * for lazy sections, and clicks through every tab list. A tab panel that only
 * exists while its tab is selected is copied, labelled with the tab's text,
 * into <div data-mmi-tab-panel data-mmi-tab="…"> at the end of <body>, so the
 * stored HTML holds every edition's panel. Writes the HTML to <out.html> and
 * prints one JSON line: {status, code, title, bytes, opened, tabs, ms}.
 *
 * Never submits a form or follows a link: clicks are limited to buttons and
 * tab/disclosure roles, and navigations away from the page get an empty 204,
 * which keeps the loaded page (abort() would swap in Chromium's "This site
 * can't be reached" page: a site's silent sign-in check, 2026-10-10).
 * A browser error page is never written: status `nav_error`.
 * One page per run; the caller runs it serially (PHP side checks load first).
 *
 * Needs playwright-core (NODE_PATH, ~/.cache/mmi-browser-checks) and
 * MMI_RENDER_CHROME (the Playwright Chromium binary).
 */
'use strict';

const fs = require( 'fs' );
const { chromium } = require( 'playwright-core' );

const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const TIMEOUTS = { navigation: 30000, settle: 1500, click: 400, total: 75000 };
const LIMITS = { clicks: 120, tabs: 60 };
const OPENER_TEXT = /^(compare|show (all|more)|see (all|more)|view (all|more)|expand( all)?|more features|all features|full (specs|comparison|feature list)|\+)/i;
const SKIP_TEXT = /(buy|cart|checkout|sign ?in|log ?in|subscribe|newsletter|accept|reject|cookie|download|try|demo|play|video|close)/i;

const [ , , url, out ] = process.argv;
const started = Date.now();
const result = { status: 'error', code: 0, title: '', bytes: 0, opened: 0, tabs: 0, ms: 0 };

function finish( code ) {
	result.ms = Date.now() - started;
	process.stdout.write( JSON.stringify( result ) + '\n' );
	process.exit( code );
}

if ( ! url || ! out || ! /^https?:\/\//.test( url ) ) {
	result.status = 'bad_args';
	finish( 2 );
}
setTimeout( () => {
	result.status = 'timeout';
	finish( 1 );
}, TIMEOUTS.total ).unref();

( async () => {
	const browser = await chromium.launch( {
		executablePath: process.env.MMI_RENDER_CHROME || undefined,
		args: [ '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu' ],
	} );
	try {
		const context = await browser.newContext( { userAgent: UA, viewport: { width: 1440, height: 1000 }, locale: 'en-US' } );
		const page = await context.newPage();
		// Media and fonts are never needed for text extraction. Once the page has
		// loaded, any top-level navigation away from it (a click on a hidden link, a
		// script's sign-in redirect) is answered with an empty 204 so the page stays.
		let homePath = null;
		await page.route( '**/*', ( route ) => {
			const req = route.request();
			if ( homePath !== null && req.isNavigationRequest() && req.frame() === page.mainFrame() && new URL( req.url() ).pathname !== homePath ) {
				result.blocked = ( result.blocked || 0 ) + 1;
				return route.fulfill( { status: 204, body: '' } );
			}
			return [ 'image', 'media', 'font' ].includes( req.resourceType() ) ? route.abort() : route.continue();
		} );
		const response = await page.goto( url, { waitUntil: 'domcontentloaded', timeout: TIMEOUTS.navigation } );
		result.code = response ? response.status() : 0;
		if ( result.code !== 200 ) {
			result.status = 'http_' + result.code;
			return finish( 0 );
		}
		homePath = new URL( page.url() ).pathname;
		await page.waitForLoadState( 'networkidle', { timeout: 10000 } ).catch( () => {} );

		// Lazy sections: scroll down in steps.
		await page.evaluate( async () => {
			for ( let y = 0; y < document.body.scrollHeight; y += 800 ) {
				window.scrollTo( 0, y );
				await new Promise( ( r ) => setTimeout( r, 120 ) );
			}
			window.scrollTo( 0, 0 );
		} );
		await page.waitForTimeout( TIMEOUTS.settle );

		// Disclosures: <details>, collapsed aria toggles, "compare / show all" buttons.
		result.opened = await page.evaluate( ( { opener, skip, max } ) => {
			const openerRe = new RegExp( opener, 'i' );
			const skipRe = new RegExp( skip, 'i' );
			let n = 0;
			document.querySelectorAll( 'details:not([open])' ).forEach( ( d ) => {
				d.open = true;
				n++;
			} );
			const candidates = [
				...document.querySelectorAll( '[aria-expanded="false"]:not([role="tab"])' ),
				...[ ...document.querySelectorAll( 'button, [role="button"]' ) ].filter( ( b ) => openerRe.test( ( b.textContent || '' ).trim() ) ),
			];
			for ( const el of new Set( candidates ) ) {
				if ( n >= max ) {
					break;
				}
				const text = ( el.textContent || '' ).trim();
				if ( el.closest( 'a[href], form, nav, header, footer' ) || skipRe.test( text ) ) {
					continue;
				}
				try {
					el.click();
					n++;
				} catch ( e ) {}
			}
			return n;
		}, { opener: OPENER_TEXT.source, skip: SKIP_TEXT.source, max: LIMITS.clicks } );
		await page.waitForTimeout( TIMEOUTS.settle );

		// Tabs: click each tab; copy its panel when the panel only exists while selected.
		const tabs = await page.$$( '[role="tab"]' );
		for ( const tab of tabs.slice( 0, LIMITS.tabs ) ) {
			const label = ( ( await tab.textContent() ) || '' ).replace( /\s+/g, ' ' ).trim();
			if ( ! label || SKIP_TEXT.test( label ) || ! ( await tab.isVisible().catch( () => false ) ) ) {
				continue;
			}
			await tab.click( { timeout: 2000 } ).catch( () => {} );
			await page.waitForTimeout( TIMEOUTS.click );
			const copied = await tab.evaluate( ( t, text ) => {
				const id = t.getAttribute( 'aria-controls' );
				const panel = ( id && document.getElementById( id ) ) || document.querySelector( '[role="tabpanel"]:not([hidden])' );
				if ( ! panel || ! panel.textContent.trim() ) {
					return false;
				}
				const box = document.createElement( 'div' );
				box.setAttribute( 'data-mmi-tab-panel', '' );
				box.setAttribute( 'data-mmi-tab', text );
				box.innerHTML = panel.innerHTML;
				document.body.appendChild( box );
				return true;
			}, label );
			result.tabs += copied ? 1 : 0;
		}

		if ( page.url().startsWith( 'chrome-error:' ) || await page.$( '#main-frame-error' ) ) {
			result.status = 'nav_error';
			return finish( 0 );
		}
		const html = await page.content();
		fs.writeFileSync( out, html );
		result.bytes = Buffer.byteLength( html );
		result.title = await page.title();
		result.status = 'ok';
	} catch ( e ) {
		result.status = /timeout/i.test( String( e ) ) ? 'timeout' : 'error';
		result.error = String( e.message || e ).slice( 0, 200 );
	} finally {
		await browser.close().catch( () => {} );
	}
	finish( 0 );
} )();
