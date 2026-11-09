/**
 * Screenshot of one vendor product page with the boxes of its mapped parts,
 * for Product Titles → Vendor maps (VendorMaps::shot()).
 *
 *   node map-sample.js <url> <out.jpg> '<{"pictures":{"sel":"…","mode":"img"},…}>'
 *
 * Loads the page as a visitor would (images on), closes nothing and clicks
 * nothing, waits for it to settle, and writes a 1280-wide JPEG of the top of
 * the page. Prints one JSON line: {status, width, height, boxes:{field:[x,y,w,h]}}.
 * Boxes are the first match of each selector; a "follow" field is the first
 * matching link whose text names requirements. Needs playwright-core
 * (NODE_PATH) and MMI_RENDER_CHROME.
 */
'use strict';

const { chromium } = require( 'playwright-core' );

const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const VIEW = { width: 1280, height: 1000 };
const MAX_H = 1800;
const TOTAL_MS = 70000;

const [ , , url, out, selJson ] = process.argv;
const result = { status: 'error', width: 0, height: 0, boxes: {} };
const finish = ( code ) => {
	process.stdout.write( JSON.stringify( result ) + '\n' );
	process.exit( code );
};
if ( ! url || ! out || ! /^https?:\/\//.test( url ) ) {
	result.status = 'bad_args';
	finish( 2 );
}
setTimeout( () => {
	result.status = 'timeout';
	finish( 1 );
}, TOTAL_MS ).unref();

( async () => {
	const sels = JSON.parse( selJson || '{}' );
	const browser = await chromium.launch( { executablePath: process.env.MMI_RENDER_CHROME || undefined, args: [ '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu' ] } );
	try {
		const page = await ( await browser.newContext( { userAgent: UA, viewport: VIEW, locale: 'en-US' } ) ).newPage();
		const res = await page.goto( url, { waitUntil: 'domcontentloaded', timeout: 30000 } );
		if ( ! res || res.status() !== 200 ) {
			result.status = 'http_' + ( res ? res.status() : 0 );
			return finish( 0 );
		}
		await page.waitForLoadState( 'networkidle', { timeout: 12000 } ).catch( () => {} );
		await page.waitForTimeout( 1500 );
		// A script redirect that failed (a site's silent sign-in) leaves Chromium's error page: no picture of that.
		if ( page.url().startsWith( 'chrome-error:' ) || await page.$( '#main-frame-error' ) ) {
			result.status = 'nav_error';
			return finish( 0 );
		}
		const info = await page.evaluate( ( s ) => {
			const reqText = /system\s*requirement|requirements|compatib|specification/i;
			const boxes = {};
			for ( const [ field, d ] of Object.entries( s ) ) {
				let el = null;
				try {
					const all = Array.from( document.querySelectorAll( d.sel.replace( /:tab\("[^"]*"\)/g, '' ) ) ); // a box only: :tab() is the reader's (VendorMaps::tab_step())
					el = d.mode === 'follow' ? all.find( ( e ) => reqText.test( e.textContent + ' ' + ( e.getAttribute( 'href' ) || '' ) ) ) || null : all.find( ( e ) => e.getBoundingClientRect().width > 0 ) || null;
				} catch ( e ) {
					el = null;
				}
				if ( el ) {
					const r = el.getBoundingClientRect();
					if ( r.width > 0 && r.height > 0 ) {
						boxes[ field ] = [ Math.round( r.x + scrollX ), Math.round( r.y + scrollY ), Math.round( r.width ), Math.round( r.height ) ];
					}
				}
			}
			return { boxes, height: document.documentElement.scrollHeight };
		}, sels );
		const h = Math.min( MAX_H, Math.max( VIEW.height, info.height || VIEW.height ) );
		await page.screenshot( { path: out, type: 'jpeg', quality: 68, clip: { x: 0, y: 0, width: VIEW.width, height: h }, fullPage: true } );
		result.status = 'ok';
		result.width = VIEW.width;
		result.height = h;
		result.boxes = info.boxes;
		finish( 0 );
	} catch ( e ) {
		result.status = 'error: ' + String( e.message || e ).slice( 0, 120 );
		finish( 1 );
	} finally {
		await browser.close().catch( () => {} );
	}
} )();
