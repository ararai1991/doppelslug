/**
 * Drives the block editor and the Classic Editor in a real browser against a running
 * Playground, checks Doppelslug's warnings appear and update, and saves screenshots.
 *
 * Usage: node tests/browser-check.mjs [base-url] [browser-channel]
 * Defaults: http://127.0.0.1:9455, msedge (uses the installed browser; nothing to download).
 */
import { chromium } from 'playwright-core';
import { mkdirSync } from 'node:fs';

const BASE = process.argv[ 2 ] || 'http://127.0.0.1:9455';
const CHANNEL = process.argv[ 3 ] || 'msedge';
const SHOTS = process.env.SHOTS || 'tests/screenshots';
const failures = [];
const problems = [];

mkdirSync( SHOTS, { recursive: true } );

function check( label, condition, detail = '' ) {
	console.log( `${ condition ? 'PASS' : 'FAIL' }  ${ label }${ condition || ! detail ? '' : `\n      ${ detail }` }` );
	if ( ! condition ) {
		failures.push( label );
	}
}

const browser = await chromium.launch( { channel: CHANNEL } );
const page = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();

page.on( 'pageerror', ( error ) => problems.push( `pageerror: ${ error.message }` ) );
page.on( 'console', ( message ) => {
	if ( 'error' === message.type() ) {
		problems.push( `console: ${ message.text() }` );
	}
} );

const harness = async ( action, extra = '' ) =>
	( await page.request.get( `${ BASE }/doppelslug-tests/doppelslug-tests.php?action=${ action }${ extra }` ) ).text();

try {
	const ids = JSON.parse( await harness( 'setup' ) );
	await harness( 'plugin', '&slug=classic-editor&state=off' );

	await page.goto( `${ BASE }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	// Block editor: draft "How to write a blog" (how-write-blog) vs published how-write-book.
	await page.goto( `${ BASE }/wp-admin/post.php?post=${ ids.blog }&action=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	await page.evaluate( () => {
		wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		wp.data.dispatch( 'core/preferences' ).set( 'core', 'openPanels', [ 'post-status' ] );
	} );
	// The welcome guide can open a moment after load; close it if it does.
	const welcome = page.locator( '.edit-post-welcome-guide' );
	if ( await welcome.waitFor( { timeout: 5000 } ).then( () => true, () => false ) ) {
		await welcome.getByRole( 'button', { name: 'Close' } ).click();
	}

	const notice = page.locator( '.components-notice', { hasText: 'published item' } );
	await notice.waitFor( { timeout: 20000 } );
	check( 'block editor: warning notice appears', await notice.isVisible() );

	await notice.getByRole( 'button', { name: 'Show details' } ).click();
	const panel = page.locator( '.doppelslug-panel' ).first();
	await panel.locator( '.doppelslug-group' ).waitFor( { timeout: 10000 } );
	const panelText = await panel.innerText();
	check( 'block editor: "Show details" opens the panel', panelText.includes( 'how-write-b' ), panelText );
	check( 'block editor: panel links the lookalike post', ( await panel.locator( 'a', { hasText: 'How to write a book' } ).count() ) > 0 );
	await page.screenshot( { path: `${ SHOTS }/screenshot-1.png` } );

	await page.evaluate( () => wp.data.dispatch( 'core/interface' ).disableComplementaryArea( 'core' ) );
	await page.getByRole( 'button', { name: 'Publish', exact: true } ).click();
	const prePublish = page.locator( '.editor-post-publish-panel .doppelslug-panel' );
	await prePublish.locator( '.doppelslug-group' ).waitFor( { timeout: 10000 } );
	check( 'block editor: pre-publish check lists the lookalike', ( await prePublish.innerText() ).includes( 'How to write a book' ) );
	await page.screenshot( { path: `${ SHOTS }/screenshot-2.png` } );
	await page.getByRole( 'button', { name: 'Cancel' } ).click();

	await page.evaluate( () => wp.data.dispatch( 'core/editor' ).editPost( { slug: 'blog-writing-guide' } ) );
	await page.waitForFunction(
		() => ! wp.data.select( 'core/notices' ).getNotices().some( ( n ) => 'doppelslug-lookalikes' === n.id ),
		null,
		{ timeout: 15000 }
	);
	check( 'block editor: warning clears after a distinctive slug', true );

	await page.evaluate( () => wp.data.dispatch( 'core/editor' ).editPost( { slug: 'how-write-blog-tips' } ) );
	await notice.waitFor( { timeout: 15000 } );
	check( 'block editor: warning returns for a lookalike slug', await notice.isVisible() );

	// Classic Editor: draft "how-write" would take over /how-write, which leads to the book today.
	await harness( 'plugin', '&slug=classic-editor&state=on' );
	await page.goto( `${ BASE }/wp-admin/post.php?post=${ ids.takeover }&action=edit` );
	const box = page.locator( '#doppelslug .doppelslug-box' );
	await box.locator( '.doppelslug-group' ).waitFor( { timeout: 15000 } );
	check( 'classic editor: box explains the takeover', ( await box.innerText() ).includes( 'currently leads to' ), await box.innerText() );
	await page.screenshot( { path: `${ SHOTS }/screenshot-3.png` } );

	await page.click( '#edit-slug-buttons .edit-slug' );
	await page.fill( '#new-post-slug', 'writing-overview' );
	await page.click( '#edit-slug-buttons .save' );
	await page.locator( '#doppelslug.doppelslug-quiet' ).waitFor( { state: 'attached', timeout: 15000 } );
	check( 'classic editor: box hides once the slug is distinctive', ! await page.locator( '#doppelslug' ).isVisible() );

	await page.goto( `${ BASE }/wp-admin/post.php?post=${ ids.zebra }&action=edit` );
	await page.waitForLoadState( 'networkidle' );
	check( 'classic editor: no box when nothing is similar', ! await page.locator( '#doppelslug' ).isVisible() );

	await harness( 'plugin', '&slug=classic-editor&state=off' );

	// Settings screen saves through options.php.
	await page.goto( `${ BASE }/wp-admin/options-general.php?page=doppelslug` );
	await page.check( 'input[name="doppelslug_settings[guessing]"][value="unique"]' );
	await page.check( 'input[name="doppelslug_settings[sensitivity]"][value="relaxed"]' );
	await page.click( '#submit' );
	await page.getByText( 'Settings saved.' ).waitFor();
	check(
		'settings: choices are saved',
		await page.isChecked( 'input[name="doppelslug_settings[guessing]"][value="unique"]' ) &&
			await page.isChecked( 'input[name="doppelslug_settings[sensitivity]"][value="relaxed"]' )
	);
	await page.check( 'input[name="doppelslug_settings[sensitivity]"][value="balanced"]' );
	await page.click( '#submit' );
	await page.getByText( 'Settings saved.' ).waitFor();
	await page.waitForLoadState( 'networkidle' );
	await page.screenshot( { path: `${ SHOTS }/screenshot-4.png` } );
	await harness( 'mode', '&value=default' );

	// Persian: the admin's profile language switches both PHP and JavaScript strings, right-to-left.
	const faIds = JSON.parse( await harness( 'setup' ) );
	await harness( 'user_locale', '&value=fa_IR' );
	await page.goto( `${ BASE }/wp-admin/post.php?post=${ faIds.blog }&action=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() );
	check( 'persian: admin is right-to-left', 'rtl' === await page.evaluate( () => document.documentElement.dir ) );
	const faNotice = page.locator( '.components-notice', { hasText: 'کوتاه‌شده' } );
	await faNotice.waitFor( { timeout: 20000 } );
	const faWelcome = page.locator( '.edit-post-welcome-guide' );
	if ( await faWelcome.isVisible() ) {
		await page.keyboard.press( 'Escape' );
	}
	check( 'persian: notice uses the PHP and JavaScript translations', ( await faNotice.innerText() ).includes( 'نشانی' ) );
	await faNotice.getByRole( 'button', { name: 'نمایش جزئیات' } ).click();
	const faPanel = page.locator( '.doppelslug-panel' ).first();
	await faPanel.locator( '.doppelslug-group' ).waitFor( { timeout: 10000 } );
	check( 'persian: panel is translated', ( await faPanel.innerText() ).includes( 'بازدیدکنندگانی' ) );
	await page.screenshot( { path: `${ SHOTS }/fa-block-editor.png` } );

	await harness( 'plugin', '&slug=classic-editor&state=on' );
	await page.goto( `${ BASE }/wp-admin/post.php?post=${ faIds.takeover }&action=edit` );
	const faBox = page.locator( '#doppelslug .doppelslug-box' );
	await faBox.locator( '.doppelslug-group' ).waitFor( { timeout: 15000 } );
	check( 'persian: classic box is translated', ( await faBox.innerText() ).includes( 'تغییر نحوه' ) );
	await page.screenshot( { path: `${ SHOTS }/fa-classic-editor.png` } );
	await harness( 'plugin', '&slug=classic-editor&state=off' );

	await page.goto( `${ BASE }/wp-admin/options-general.php?page=doppelslug` );
	check( 'persian: settings screen is translated', ( await page.locator( '.wrap' ).innerText() ).includes( 'حساسیت هشدار' ) );
	await page.screenshot( { path: `${ SHOTS }/fa-settings.png` } );
	await harness( 'user_locale', '&value=en_US' );
} catch ( error ) {
	failures.push( error.message );
	console.log( `FAIL  ${ error.message }` );
	await page.screenshot( { path: `${ SHOTS }/failure.png` } ).catch( () => {} );
} finally {
	await browser.close();
}

const ours = problems.filter( ( p ) => /doppelslug/i.test( p ) );
check( 'no JavaScript errors from Doppelslug', 0 === ours.length, ours.join( '\n      ' ) );
if ( problems.length > ours.length ) {
	console.log( `(ignored ${ problems.length - ours.length } unrelated console message(s))` );
}

console.log( failures.length ? `\n${ failures.length } FAILED` : '\nALL BROWSER CHECKS PASSED' );
process.exit( failures.length ? 1 : 0 );
