/**
 * Renders the WordPress.org banner and icon, and the GitHub social preview, from
 * .wordpress-org/src/*.html into PNGs.
 *
 * Usage: node bin/render-assets.mjs [browser-channel]   (default msedge; uses the installed browser)
 */
import { chromium } from 'playwright-core';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const CHANNEL = process.argv[ 2 ] || 'msedge';
const DIR = resolve( '.wordpress-org' );

const outputs = [
	{ source: 'banner.html', selector: '.banner', file: 'banner-1544x500.png', scale: 1 },
	{ source: 'banner.html', selector: '.banner', file: 'banner-772x250.png', scale: 0.5 },
	{ source: 'icon.html', selector: 'svg', file: 'icon-256x256.png', scale: 1 },
	{ source: 'icon.html', selector: 'svg', file: 'icon-128x128.png', scale: 0.5 },
	{ source: 'social.html', selector: '.banner', file: '../.github/social-preview.png', scale: 1 },
];

const browser = await chromium.launch( { channel: CHANNEL } );

for ( const output of outputs ) {
	const page = await browser.newPage( { deviceScaleFactor: output.scale, viewport: { width: 1600, height: 600 } } );
	await page.goto( pathToFileURL( resolve( DIR, 'src', output.source ) ).href );
	await page.locator( output.selector ).screenshot( { path: resolve( DIR, output.file ), omitBackground: true } );
	await page.close();
	console.log( `wrote ${ resolve( DIR, output.file ) }` );
}

await browser.close();
