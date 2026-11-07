/**
 * 来場者向け画面の補助スクリプト。
 * JavaScript が無効でも予約・取消はできる作りにしてあり、ここでの処理は上乗せ。
 */
( function () {
	'use strict';

	/**
	 * 取り消しなど、押し間違いを防ぎたいボタンに確認ダイアログを付ける。
	 */
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

	/**
	 * LINE のアプリ内ブラウザで開いたときだけ、「ブラウザで開く」の案内を出す。
	 */
	var inApp = document.querySelector( '[data-equiticket-inapp]' );
	if ( inApp && /\bLine\//i.test( navigator.userAgent || '' ) ) {
		inApp.hidden = false;
	}

	/**
	 * 整理券の画面: 予約済みの間は数秒ごとに状態を確かめ、受付されたら読み込み直して「受付済み」を出す。
	 */
	var ticket = document.querySelector( '[data-equiticket-ticket]' );
	if ( ticket && window.fetch && window.FormData ) {
		var watchTicket = function () {
			if ( document.hidden ) {
				return;
			}

			var body = new FormData();
			body.append( 'action', 'equiticket_ticket_status' );
			body.append( 'token', ticket.getAttribute( 'data-equiticket-ticket' ) );

			window.fetch( ticket.getAttribute( 'data-equiticket-ajax' ), {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( payload ) {
				if ( payload && payload.success && payload.data && 'reserved' !== payload.data.status ) {
					window.location.reload();
				}
			} ).catch( function () {
				// 通信に失敗しても次の確認を待つ。
			} );
		};

		window.setInterval( watchTicket, 5000 );
		document.addEventListener( 'visibilitychange', watchTicket );
	}

	var slotList = document.querySelector( '[data-equiticket-slots]' );
	if ( ! slotList || typeof window.equiticketFront === 'undefined' ) {
		return;
	}

	var config = window.equiticketFront;

	/**
	 * 枠ごと・馬ごとの残り人数を定期的に取り込み、表示を更新する。
	 * 直前に他の人が予約した枠を、更新前に選んでしまうのを減らすのが目的。
	 */
	function refresh() {
		var body = new FormData();
		body.append( 'action', 'equiticket_availability' );
		body.append( 'nonce', config.nonce );

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			if ( ! payload || ! payload.success || ! payload.data || ! payload.data.slots ) {
				return;
			}

			payload.data.slots.forEach( function ( entry ) {
				var option = document.querySelector( '.equiticket-slot__option[data-slot-id="' + entry.slot + '"][data-horse-id="' + entry.horse + '"]' );
				if ( ! option ) {
					return;
				}

				var label = option.querySelector( '[data-equiticket-remaining]' );
				if ( label ) {
					label.textContent = entry.label;
				}

				if ( ! entry.open ) {
					// 満席になった馬は、その場で押せないようにする。
					option.classList.add( 'is-closed' );
					var button = option.querySelector( 'button.equiticket-button' );
					if ( button ) {
						button.disabled = true;
						button.classList.add( 'equiticket-button--disabled' );
					}
				}
			} );
		} ).catch( function () {
			// 通信に失敗しても表示はそのままにする（会場の回線が不安定な想定）。
		} );
	}

	window.setInterval( refresh, 30000 );
} )();
