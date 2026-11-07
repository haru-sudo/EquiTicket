/**
 * docs/manual/staff/staff-manual.html から受付スタッフ用マニュアルの PDF を作る。
 *
 * 使い方: node tools/manual/build-staff-pdf.js
 * 画像を撮り直す場合は、先に render-screens.php・capture.js・capture-staff.js を実行して
 * docs/manual/staff/assets/ に置く。環境変数 EQUITICKET_CHROMIUM で Chromium を指定できる。
 */
'use strict';

const path = require( 'path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '..', '..' );
const src = path.join( root, 'docs', 'manual', 'staff', 'staff-manual.html' );
const out = path.join( root, 'docs', 'manual', 'EquiTicket-受付スタッフ用マニュアル.pdf' );

( async () => {
	const launch = { args: [ '--no-sandbox' ] };
	if ( process.env.EQUITICKET_CHROMIUM ) {
		launch.executablePath = process.env.EQUITICKET_CHROMIUM;
	}
	const browser = await chromium.launch( launch );
	const page = await browser.newPage();
	await page.goto( 'file://' + src, { waitUntil: 'load' } );
	await page.pdf( { path: out, format: 'A4', printBackground: true, preferCSSPageSize: true } );
	await browser.close();
	console.log( 'wrote ' + out );
} )();
