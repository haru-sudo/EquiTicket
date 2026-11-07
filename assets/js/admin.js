/**
 * 管理画面の補助スクリプト（確認ダイアログと自動更新）。
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var target = ( event.target && event.target.closest )
			? event.target.closest( '[data-equiticket-confirm]' )
			: null;
		if ( ! target ) {
			return;
		}
		if ( ! window.confirm( target.getAttribute( 'data-equiticket-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	var toggle = document.querySelector( '[data-equiticket-autorefresh]' );
	if ( ! toggle ) {
		return;
	}

	var storageKey = 'equiticketAutoRefresh';
	var timer = null;

	function start() {
		// 受付中は画面を見たままにしておけるよう、一定間隔で読み込み直す。
		timer = window.setTimeout( function () {
			window.location.reload();
		}, 30000 );
	}

	function stop() {
		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}
	}

	try {
		toggle.checked = 'on' === window.localStorage.getItem( storageKey );
	} catch ( error ) {
		toggle.checked = false;
	}

	if ( toggle.checked ) {
		start();
	}

	toggle.addEventListener( 'change', function () {
		try {
			window.localStorage.setItem( storageKey, toggle.checked ? 'on' : 'off' );
		} catch ( error ) {
			// localStorage が使えない環境でも動作は継続する。
		}

		if ( toggle.checked ) {
			start();
		} else {
			stop();
		}
	} );
} )();
