/**
 * 受付スタッフ用マニュアルの画像のうち、カメラ映像に結果を重ねた画面など、
 * capture.js にない画面を撮る。
 *
 * 使い方: node tools/manual/capture-staff.js <render の出力ディレクトリ> <画像の出力ディレクトリ>
 * 環境変数 EQUITICKET_CHROMIUM で Chromium の実行ファイルを指定できる。
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '..', '..' );
const src = path.resolve( process.argv[ 2 ] );
const dest = path.resolve( process.argv[ 3 ] || path.join( root, 'docs', 'manual', 'staff', 'assets' ) );
const css = fs.readFileSync( path.join( root, 'assets', 'css', 'front.css' ), 'utf8' );

const page = ( body ) => '<!doctype html><html lang="ja"><head><meta charset="utf-8"><style>' + css
	+ 'body{margin:0;padding:14px;background:#fff;font-family:"IPAPGothic","IPAGothic",sans-serif;}'
	+ '.equiticket{font-family:"IPAPGothic","IPAGothic",sans-serif !important;}'
	+ '.equiticket-scanner__viewport{background:linear-gradient(135deg,#3a3f44,#1d2023) !important;}'
	+ '</style></head><body><div class="equiticket equiticket--checkin">' + body + '</div></body></html>';

// 照合結果をカメラ映像の上に重ねた画面（checkin.js の showFlash と同じ組み立て）。
const flash = ( code, lines ) => '<div class="equiticket-scanner"><div class="equiticket-scanner__viewport">'
	+ '<div class="equiticket-scanner__flash equiticket-scanner__flash--' + code + '">'
	+ lines.map( ( l ) => '<p class="equiticket-scanner__flash-' + l[ 0 ] + '">' + l[ 1 ] + '</p>' ).join( '' )
	+ '<p class="equiticket-scanner__flash-hint">タップで次の読み取りへ</p></div></div></div>';

( async () => {
	fs.mkdirSync( dest, { recursive: true } );
	const launch = { args: [ '--no-sandbox' ] };
	if ( process.env.EQUITICKET_CHROMIUM ) {
		launch.executablePath = process.env.EQUITICKET_CHROMIUM;
	}
	const browser = await chromium.launch( launch );
	const phone = await browser.newPage( { viewport: { width: 400, height: 760 }, deviceScaleFactor: 2, locale: 'ja-JP' } );
	const shot = async ( name, html, selector ) => {
		await phone.setContent( page( html ), { waitUntil: 'load' } );
		await phone.waitForTimeout( 250 );
		await ( await phone.$( selector ) ).screenshot( { path: path.join( dest, name ) } );
	};

	// カメラ起動前の受付画面（結果の枠と手動照合より下は外す）。
	const checkin = fs.readFileSync( path.join( src, 'checkin.html' ), 'utf8' )
		.replace( /<div class="equiticket-scanner__result[^"]*"[^>]*>[\s\S]*?<\/div>/, '' );
	await phone.setContent( page( checkin ), { waitUntil: 'load' } );
	await phone.evaluate( () => {
		document.querySelectorAll( '.equiticket-result, details, .equiticket-manual, .equiticket-search, .equiticket-recent, [data-equiticket-flash]' ).forEach( ( el ) => el.remove() );
		document.querySelector( '[data-equiticket-frame]' ).hidden = false;
		document.querySelector( '[data-equiticket-photo-wrap]' ).hidden = false;
	} );
	await phone.waitForTimeout( 250 );
	await ( await phone.$( '.equiticket--checkin' ) ).screenshot( { path: path.join( dest, 'scan-start.png' ) } );

	await shot( 'flash-ok.png', flash( 'ok', [
		[ 'headline', '✓ 受付しました' ], [ 'person', '山田 太郎 様・1 名' ],
		[ 'line', '乗る馬: ポニー（118cm / 22kg）' ], [ 'line', '集金: 300 円' ]
	] ), '.equiticket-scanner__viewport' );
	await shot( 'flash-already.png', flash( 'already', [
		[ 'headline', '使用済み' ], [ 'person', '山田 太郎 様・1 名' ], [ 'line', '乗る馬: ポニー' ],
		[ 'detail', 'すでに 09:52 に受付済みです。' ]
	] ), '.equiticket-scanner__viewport' );
	await shot( 'flash-not_found.png', flash( 'not_found', [
		[ 'headline', '該当なし' ], [ 'detail', 'この QR は当システムの整理券ではありません。名簿で手動照合してください。' ]
	] ), '.equiticket-scanner__viewport' );
	await shot( 'flash-no_show.png', flash( 'no_show', [
		[ 'headline', '時間切れ' ], [ 'person', '高橋 さくら 様・1 名' ], [ 'line', '乗る馬: ポニー' ],
		[ 'detail', '受付締切を過ぎたため当日枠として解放されています。' ]
	] ), '.equiticket-scanner__viewport' );

	await browser.close();
	console.log( 'captured to ' + dest );
} )();
