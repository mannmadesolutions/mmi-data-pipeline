/**
 * Decodes one image this server's PHP can't read (AVIF: no GD AVIF, no
 * Imagick) with headless Chromium, which reads it natively, and writes it as
 * a lossless PNG for PHP to finish (Product Titles → ImageConvert::decode();
 * ImageConvert::normalize() then makes the 800×800 JPEG).
 *
 *   node convert-image.js <in-file> <out.png>
 *
 * The file is decoded in an <img> from a data: URL (no network), drawn to a
 * canvas at its natural size and encoded with canvas.toBlob('image/png').
 * Prints one JSON line: {status, width, height, bytes, ms}.
 *
 * Needs playwright-core (NODE_PATH, ~/.cache/mmi-browser-checks) and
 * MMI_RENDER_CHROME (the Playwright Chromium binary).
 */
'use strict';

const fs = require( 'fs' );
const { chromium } = require( 'playwright-core' );

const TIMEOUT_MS = 45000;
const MIME = { avif: 'image/avif', webp: 'image/webp', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif' };

const [ , , input, out ] = process.argv;
const started = Date.now();
const result = { status: 'error', width: 0, height: 0, bytes: 0, ms: 0 };

function finish( code ) {
	result.ms = Date.now() - started;
	process.stdout.write( JSON.stringify( result ) + '\n' );
	process.exit( code );
}

if ( ! input || ! out || ! fs.existsSync( input ) ) {
	result.status = 'bad_args';
	finish( 2 );
}
setTimeout( () => {
	result.status = 'timeout';
	finish( 1 );
}, TIMEOUT_MS ).unref();

( async () => {
	const buf = fs.readFileSync( input );
	// The caller names the real type (it sniffs the bytes); the extension is only a fallback.
	const type = process.env.MMI_CONVERT_MIME || MIME[ ( input.split( '.' ).pop() || '' ).toLowerCase() ] || 'image/avif';
	const browser = await chromium.launch( {
		executablePath: process.env.MMI_RENDER_CHROME || undefined,
		args: [ '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu' ],
	} );
	try {
		const page = await browser.newPage();
		await page.route( '**/*', ( r ) => ( r.request().url().startsWith( 'data:' ) ? r.continue() : r.abort() ) );
		const res = await page.evaluate( async ( { b64, mime } ) => {
			const img = new Image();
			img.src = `data:${ mime };base64,${ b64 }`;
			await img.decode();
			const c = document.createElement( 'canvas' );
			c.width = img.naturalWidth;
			c.height = img.naturalHeight;
			c.getContext( '2d' ).drawImage( img, 0, 0 );
			const blob = await new Promise( ( resolve ) => c.toBlob( resolve, 'image/png' ) );
			if ( ! blob || blob.type !== 'image/png' ) {
				return { error: 'encode' };
			}
			const bytes = new Uint8Array( await blob.arrayBuffer() );
			let bin = '';
			for ( let i = 0; i < bytes.length; i += 0x8000 ) {
				bin += String.fromCharCode.apply( null, bytes.subarray( i, i + 0x8000 ) );
			}
			return { width: c.width, height: c.height, data: btoa( bin ) };
		}, { b64: buf.toString( 'base64' ), mime: type } );
		if ( res.error || ! res.data ) {
			result.status = res.error || 'decode';
		} else {
			const png = Buffer.from( res.data, 'base64' );
			fs.writeFileSync( out, png );
			Object.assign( result, { status: 'ok', width: res.width, height: res.height, bytes: png.length } );
		}
	} catch ( e ) {
		result.status = /decode|EncodingError/i.test( String( e && e.message ) ) ? 'decode' : 'error';
	} finally {
		await browser.close();
	}
	finish( result.status === 'ok' ? 0 : 1 );
} )();
