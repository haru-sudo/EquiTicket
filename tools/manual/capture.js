/**
 * render-screens.php が書き出した HTML を、WordPress 管理画面／スマートフォン画面の
 * 枠に入れて撮影し、マニュアル用の画像にする。
 *
 * 使い方: node tools/manual/capture.js <render の出力ディレクトリ> <画像の出力ディレクトリ>
 * 環境変数 EQUITICKET_CHROMIUM で Chromium の実行ファイルを指定できる。
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { chromium } = require( 'playwright' );

const root = path.resolve( __dirname, '..', '..' );
const src = path.resolve( process.argv[ 2 ] || path.join( __dirname, 'out' ) );
const dest = path.resolve( process.argv[ 3 ] || path.join( root, 'docs', 'manual', 'assets' ) );
const meta = JSON.parse( fs.readFileSync( path.join( src, 'meta.json' ), 'utf8' ) );

const css = {
	shell: fs.readFileSync( path.join( __dirname, 'admin-shell.css' ), 'utf8' ),
	front: fs.readFileSync( path.join( root, 'assets', 'css', 'front.css' ), 'utf8' ),
	admin: fs.readFileSync( path.join( root, 'assets', 'css', 'admin.css' ), 'utf8' )
};

// 画面例では書体をマニュアルと揃える（実際のサイトではテーマの書体になる）。
const fontFix = '.equiticket{font-family:"IPAPGothic","IPAGothic",sans-serif !important;}';

const read = ( name ) => fs.readFileSync( path.join( src, name + '.html' ), 'utf8' );

const menus = {
	admin: [
		[ 'ダッシュボード' ], [ '投稿' ], [ 'メディア' ], [ '固定ページ' ], [ 'コメント' ],
		[ '体験乗馬', [ '当日ダッシュボード', '過去の記録', '受付', '当日予約', '馬の種類', '時間枠', '名簿印刷', '一斉メール', '設定' ] ],
		[ '外観' ], [ 'プラグイン' ], [ 'ユーザー' ], [ 'ツール' ], [ '設定' ]
	],
	manager: [ [ '体験乗馬', [ '当日ダッシュボード', '過去の記録', '受付', '当日予約', '馬の種類', '時間枠', '名簿印刷', '一斉メール', '設定' ] ], [ 'プロフィール' ] ],
	staff: [ [ '体験乗馬', [ '当日ダッシュボード', '過去の記録', '受付', '当日予約', '名簿印刷' ] ], [ 'プロフィール' ] ]
};

function menuHtml( items, current ) {
	return '<ul id="adminmenu">' + items.map( ( [ label, children ] ) => {
		if ( ! children ) {
			return '<li><a>' + label + '</a></li>';
		}

		return '<li class="open"><a>' + label + '</a><ul>' + children.map( ( c ) =>
			'<li><a' + ( c === current ? ' class="current"' : '' ) + '>' + c + '</a></li>'
		).join( '' ) + '</ul></li>';
	} ).join( '' ) + '</ul>';
}

function adminPage( body, current, role = 'admin', user = 'admin' ) {
	return '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><style>' + css.shell + css.front + css.admin + meta.theme_css + fontFix + '</style></head>'
		+ '<body class="wp-admin"><div id="wpadminbar"><span class="wp-logo"></span><span class="site">サンプル乗馬クラブ</span><span>＋ 新規</span><span class="me">こんにちは、' + user + ' さん</span></div>'
		+ '<div id="adminmenuwrap">' + menuHtml( menus[ role ], current ) + '</div>'
		+ '<div id="wpcontent"><div id="wpbody-content">' + body + '</div></div>'
		+ '</body></html>';
}

function mobileAdminPage( body ) {
	return '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + css.shell + css.front + css.admin + meta.theme_css + fontFix + '</style></head>'
		+ '<body class="wp-admin mobile"><div id="wpadminbar"><span class="wp-logo"></span><span class="site">サンプル乗馬クラブ</span></div>'
		+ '<div id="wpcontent"><div class="wrap equiticket-wrap"><div class="equiticket equiticket--checkin">' + body + '</div></div></div></body></html>';
}

function frontPage( body ) {
	return '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + css.front + meta.theme_css + fontFix
		+ 'body{margin:0;padding:16px 12px 24px;background:#eef1f2;}</style></head><body>' + body + '</body></html>';
}

function mailPage( body ) {
	return '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><style>body{margin:0;padding:24px;background:#fff;font-family:"IPAPGothic","IPAGothic",sans-serif;}'
		+ '.mailhead{margin:0 0 16px;padding:0 0 12px;border-bottom:1px solid #ddd;color:#555;font-size:13px;line-height:1.8;}</style></head><body>'
		+ '<div class="mailhead"><b>件名:</b> 【さくら祭 体験乗馬コーナー】体験乗馬 整理券のご案内（' + meta.event_label + ' 10:40〜）<br><b>差出人:</b> サンプル乗馬クラブ &lt;info@example.jp&gt;</div>'
		+ body + '</body></html>';
}

/** n 番目（0 始まり）の要素に目印を付ける。 */
async function tag( page, selector, n, value ) {
	await page.evaluate( ( [ sel, i, v ] ) => {
		document.querySelectorAll( '[data-tag="' + v + '"]' ).forEach( ( el ) => el.removeAttribute( 'data-tag' ) );
		const el = document.querySelectorAll( sel )[ i ];
		if ( el ) {
			el.setAttribute( 'data-tag', v );
		}
	}, [ selector, n, value ] );
}

/** 2 つの要素のあいだを切り取って撮る。 */
async function clipBetween( page, file, startSel, endSel, pad = 8 ) {
	const box = await page.evaluate( ( [ s, e, p ] ) => {
		const a = document.querySelector( s );
		if ( ! a ) {
			return null;
		}
		const top = a.getBoundingClientRect().top + window.scrollY - p;
		let bottom;
		if ( e ) {
			const b = document.querySelector( e );
			bottom = b ? b.getBoundingClientRect().top + window.scrollY - 4 : document.body.scrollHeight;
		} else {
			bottom = document.body.scrollHeight;
		}

		// 画面の途中を切り取るときは、左メニューを外して本文だけにする。
		const content = 'body' === s ? null : document.querySelector( '#wpcontent' );
		const left = content ? Math.max( 0, content.getBoundingClientRect().left ) : 0;

		return { x: left, y: Math.max( 0, top ), width: document.documentElement.clientWidth - left, height: Math.max( 40, bottom - top ) };
	}, [ startSel, endSel, pad ] );

	if ( ! box ) {
		throw new Error( 'selector not found: ' + startSel );
	}
	await page.screenshot( { path: path.join( dest, file ), clip: box, fullPage: true } );
}

( async () => {
	fs.mkdirSync( dest, { recursive: true } );

	const launch = { args: [ '--no-sandbox', '--lang=ja-JP' ], env: Object.assign( {}, process.env, { LANG: 'ja_JP.UTF-8', LANGUAGE: 'ja' } ) };
	if ( process.env.EQUITICKET_CHROMIUM ) {
		launch.executablePath = process.env.EQUITICKET_CHROMIUM;
	}
	const browser = await chromium.launch( launch );
	const desk = await browser.newPage( { viewport: { width: 1180, height: 800 }, deviceScaleFactor: 1.6, locale: 'ja-JP' } );
	const phone = await browser.newPage( { viewport: { width: 400, height: 760 }, deviceScaleFactor: 2, locale: 'ja-JP' } );

	const show = async ( page, html ) => {
		await page.setContent( html, { waitUntil: 'load' } );
		await page.waitForTimeout( 80 );
	};

	/* 当日ダッシュボード */
	await show( desk, adminPage( read( 'admin-dashboard' ), '当日ダッシュボード' ) );
	await clipBetween( desk, 'admin-dashboard-top.png', 'body', '.equiticket-card', 0 );
	// ダッシュボードは時間枠を 1 つずつ表示するので、開いている枠のカードを撮る。
	await clipBetween( desk, 'admin-dashboard-card.png', '.equiticket-card', '.equiticket-walkin-link' );

	/* 当日予約（スマートフォン幅） */
	await show( phone, adminPage( read( 'admin-walkin' ), '当日予約', 'staff', 'bajutsu-uketsuke' ) );
	await phone.screenshot( { path: path.join( dest, 'admin-walkin.png' ), fullPage: true } );

	/* 馬の種類 */
	await show( desk, adminPage( read( 'admin-horses' ), '馬の種類' ) );
	await clipBetween( desk, 'admin-horses-list.png', 'body', '.equiticket-admin-form', 0 );
	await clipBetween( desk, 'admin-horses-form.png', '.equiticket-wrap > h2', null );

	/* 時間枠 */
	await show( desk, adminPage( read( 'admin-slots' ), '時間枠' ) );
	await clipBetween( desk, 'admin-slots-list.png', 'body', '.equiticket-admin-form', 0 );
	await clipBetween( desk, 'admin-slots-bulk.png', '.equiticket-wrap > hr', null );
	await show( desk, adminPage( read( 'admin-slots-edit' ), '時間枠' ) );
	await clipBetween( desk, 'admin-slots-edit.png', '.equiticket-wrap > h2', '.equiticket-wrap > hr' );

	/* 名簿印刷 */
	await show( desk, adminPage( read( 'admin-roster' ), '名簿印刷' ) );
	await tag( desk, '.equiticket-roster__slot', 3, 'roster-end' );
	await clipBetween( desk, 'admin-roster.png', 'body', '[data-tag="roster-end"]', 0 );

	/* 設定（見出しごとに切り分ける） */
	await show( desk, adminPage( read( 'admin-settings' ), '設定' ) );
	const sections = await desk.evaluate( () => Array.from( document.querySelectorAll( '.equiticket-wrap > h2, .equiticket-wrap > form > h2' ) ).map( ( h ) => h.textContent.trim() ) );
	const index = [];
	for ( let i = 0; i < sections.length; i++ ) {
		const file = 'admin-settings-' + String( i + 1 ).padStart( 2, '0' ) + '.png';
		await desk.evaluate( ( n ) => {
			document.querySelectorAll( '[data-shot]' ).forEach( ( el ) => el.removeAttribute( 'data-shot' ) );
			const list = Array.from( document.querySelectorAll( '.equiticket-wrap > h2, .equiticket-wrap > form > h2' ) );
			list[ n ].setAttribute( 'data-shot', 'start' );
			if ( list[ n + 1 ] ) {
				list[ n + 1 ].setAttribute( 'data-shot', 'end' );
			}
		}, i );
		await clipBetween( desk, file, '[data-shot="start"]', i + 1 < sections.length ? '[data-shot="end"]' : null );
		index.push( { file, title: sections[ i ] } );
	}
	fs.writeFileSync( path.join( dest, 'admin-settings-index.json' ), JSON.stringify( index, null, 2 ) );

	/* 左メニューの比較（管理者／運営スタッフ／受付スタッフ） */
	for ( const role of [ 'admin', 'manager', 'staff' ] ) {
		await show( desk, adminPage( '<div class="wrap"><h1>当日ダッシュボード</h1></div>', '当日ダッシュボード', role, 'admin' === role ? 'admin' : 'bajutsu-uketsuke' ) );
		await desk.screenshot( { path: path.join( dest, 'menu-' + role + '.png' ), clip: { x: 0, y: 0, width: 170, height: 420 } } );
	}

	/* 受付画面（スマートフォン） */
	await show( phone, mobileAdminPage( read( 'checkin' ) ) );
	// スクリプト実行後の状態（「写真で読み取る」が表示された状態）に合わせる。
	await phone.evaluate( () => {
		const wrap = document.querySelector( '[data-equiticket-photo-wrap]' );
		if ( wrap ) {
			wrap.hidden = false;
		}
		const status = document.querySelector( '[data-equiticket-status]' );
		if ( status ) {
			status.textContent = '続けてスキャンできます。';
		}
	} );
	await phone.screenshot( { path: path.join( dest, 'checkin-phone.png' ), fullPage: false } );
	await clipBetween( phone, 'checkin-manual.png', '.equiticket-manual', null );
	for ( const key of [ 'already', 'not_found', 'no_show' ] ) {
		await show( phone, frontPage( '<div class="equiticket">' + read( 'checkin-result-' + key ) + '</div>' ) );
		await phone.locator( '.equiticket-result' ).screenshot( { path: path.join( dest, 'checkin-result-' + key + '.png' ) } );
	}

	/* 来場者の画面 */
	const fronts = [ 'front-step1', 'front-step1-error', 'front-step2', 'front-ticket' ];
	for ( const name of fronts ) {
		await show( phone, frontPage( read( name ) ) );
		await phone.screenshot( { path: path.join( dest, name + '.png' ), fullPage: false } );
	}
	await show( phone, frontPage( read( 'front-step3' ) ) );
	await phone.screenshot( { path: path.join( dest, 'front-step3.png' ), fullPage: false } );
	await clipBetween( phone, 'front-step3-consent.png', '.equiticket-payment', '.equiticket-back' );
	await show( phone, frontPage( read( 'front-ticket' ) ) );
	await clipBetween( phone, 'front-ticket-bottom.png', '.equiticket-payment', null );

	/* 確認メール */
	const mailer = await browser.newPage( { viewport: { width: 640, height: 900 }, deviceScaleFactor: 1.6, locale: 'ja-JP' } );
	await show( mailer, mailPage( read( 'mail-html' ) ) );
	await mailer.screenshot( { path: path.join( dest, 'mail.png' ), fullPage: true } );

	await browser.close();
	console.log( 'captured to ' + dest );
} )();
