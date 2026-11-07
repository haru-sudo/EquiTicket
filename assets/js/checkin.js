/**
 * 受付（QR チェックイン）画面のスクリプト。
 *
 * カメラ映像からのリアルタイム読み取りを主経路にしている。
 *   1. ブラウザー標準の BarcodeDetector（対応していれば軽くて速い）
 *   2. 同梱の jsQR（iOS Safari や Windows の Chrome など、1 が使えない環境）
 *
 * iOS Safari では次の点でつまずきやすいため、それぞれ対策している。
 *   - muted / playsinline / webkit-playsinline が無いと、その場で再生できない
 *   - play() の直後は videoWidth が 0 のままなので、映像の大きさが分かるまで待つ
 *   - 再生が中断されることがあるので、止まっていたら再生し直す
 *   - フレーム取得は requestVideoFrameCallback（なければ requestAnimationFrame）で行う
 *
 * それでも動かない端末向けに「写真で読み取る」と手動照合を残し、
 * 状況を伝えてもらえるよう「カメラの状態」に内部の値を出している。
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-equiticket-scanner]' );
	if ( ! root || typeof window.equiticketCheckin === 'undefined' ) {
		return;
	}

	var config = window.equiticketCheckin;
	var video = root.querySelector( '[data-equiticket-video]' );
	var statusText = root.querySelector( '[data-equiticket-status]' );
	var resultBox = root.querySelector( '[data-equiticket-result]' );
	var startButton = root.querySelector( '[data-equiticket-scan-start]' );
	var stopButton = root.querySelector( '[data-equiticket-scan-stop]' );
	var scanFrame = root.querySelector( '[data-equiticket-frame]' );
	var photoInput = root.querySelector( '[data-equiticket-photo]' );
	var photoWrap = root.querySelector( '[data-equiticket-photo-wrap]' );
	var diagBody = root.querySelector( '[data-equiticket-diag-body]' );
	var flash = root.querySelector( '[data-equiticket-flash]' );

	// jsQR に渡すフレームを描くための作業用キャンバス（画面には出さない）。
	var canvas = document.createElement( 'canvas' );
	var context = canvas.getContext( '2d', { willReadFrequently: true } );

	var stream = null;
	var detect = null;
	var decoderName = '—';
	var running = false;
	var busy = false;
	var frames = 0;
	var lastError = '';
	var lastAnalyzedAt = 0;
	var startedAt = 0;
	var hintShown = false;
	var lastToken = '';
	var lastTokenAt = 0;
	var rafId = null;
	var flashTimer = null;
	var holdUntil = 0;
	var jsqrLoading = null;
	var rvfcSupported = 'function' === typeof window.HTMLVideoElement
		&& window.HTMLVideoElement.prototype
		&& 'function' === typeof window.HTMLVideoElement.prototype.requestVideoFrameCallback;

	function setStatus( message ) {
		statusText.textContent = message;
	}

	function isIos() {
		var ua = navigator.userAgent || '';

		return /iPad|iPhone|iPod/.test( ua )
			|| ( 'MacIntel' === navigator.platform && navigator.maxTouchPoints > 1 );
	}

	function hasCameraApi() {
		return !! ( navigator.mediaDevices && 'function' === typeof navigator.mediaDevices.getUserMedia );
	}

	/**
	 * jsQR を探す。
	 *
	 * 他のプラグインが AMD ローダーや exports を定義していると、
	 * 置き場所が変わることがあるため、考えられる場所をすべて見る。
	 *
	 * @return {Function|null} 見つかった関数。
	 */
	function resolveJsQr() {
		var candidates = [
			window.jsQR,
			window.jsQR && window.jsQR.default,
			window.jsqr,
			window.exports && window.exports.jsQR
		];

		for ( var i = 0; i < candidates.length; i++ ) {
			if ( 'function' === typeof candidates[ i ] ) {
				return candidates[ i ];
			}
		}

		return null;
	}

	/**
	 * 読み取りに使えるものがあるか。
	 *
	 * @return {boolean} あれば true。
	 */
	function hasAnyDecoder() {
		return 'BarcodeDetector' in window || null !== resolveJsQr();
	}

	/**
	 * 読み取り用のライブラリを用意する。
	 *
	 * ページ最適化プラグインの読み込み順や結合の影響で jsQR が読めていない場合に備え、
	 * その場で読み込み直す。
	 *
	 * @return {Promise} 使えるようになったら true に解決する Promise。
	 */
	function ensureDecoderLibrary() {
		if ( hasAnyDecoder() ) {
			return Promise.resolve( true );
		}
		if ( ! config.jsqrUrl ) {
			return Promise.resolve( false );
		}
		if ( jsqrLoading ) {
			return jsqrLoading;
		}

		jsqrLoading = new Promise( function ( resolve ) {
			var script = document.createElement( 'script' );
			var limit = window.setTimeout( function () {
				lastError = 'jsqr: timeout';
				resolve( hasAnyDecoder() );
			}, 15000 );

			script.src = config.jsqrUrl;
			script.async = true;
			script.onload = function () {
				window.clearTimeout( limit );
				resolve( hasAnyDecoder() );
			};
			script.onerror = function () {
				window.clearTimeout( limit );
				lastError = 'jsqr: load error';
				resolve( false );
			};

			document.head.appendChild( script );
		} );

		return jsqrLoading;
	}

	/**
	 * 現在の状態を「カメラの状態」欄に書き出す。
	 *
	 * @return {void}
	 */
	function updateDiag() {
		if ( ! diagBody ) {
			return;
		}

		var track = stream && stream.getVideoTracks ? stream.getVideoTracks()[ 0 ] : null;
		var rows = [
			[ config.i18n.diagSecure, ( false === window.isSecureContext ? config.i18n.diagNo : config.i18n.diagYes ) + '（' + window.location.protocol + '）' ],
			[ config.i18n.diagCameraApi, hasCameraApi() ? config.i18n.diagYes : config.i18n.diagNo ],
			[ config.i18n.diagJsqr, resolveJsQr() ? config.i18n.diagYes : config.i18n.diagNo ],
			[ config.i18n.diagDecoder, decoderName ],
			[ config.i18n.diagVideo, video.videoWidth + ' × ' + video.videoHeight + ( video.paused ? ' (paused)' : '' ) ],
			[ config.i18n.diagTrack, track ? ( ( track.label || 'camera' ) + ' / ' + track.readyState ) : config.i18n.diagNo ],
			[ config.i18n.diagFrames, String( frames ) ],
			[ config.i18n.diagError, lastError || '—' ]
		];

		diagBody.textContent = '';
		rows.forEach( function ( row ) {
			var dt = document.createElement( 'dt' );
			dt.textContent = row[ 0 ];
			var dd = document.createElement( 'dd' );
			dd.textContent = row[ 1 ];
			diagBody.appendChild( dt );
			diagBody.appendChild( dd );
		} );
	}

	/**
	 * カメラ映像が使えない理由を返す。使える場合は空文字。
	 *
	 * @return {string} 理由のメッセージ。
	 */
	function cameraBlockedReason() {
		// getUserMedia は https（または localhost）でしか使えない。
		if ( ! hasCameraApi() ) {
			return ( false === window.isSecureContext || 'https:' !== window.location.protocol )
				? config.i18n.insecure
				: config.i18n.unsupported;
		}

		return '';
	}

	/**
	 * 読み取りに使う仕組みを決める。
	 *
	 * @return {Promise} 読み取り関数（video を受け取り、文字列か null を返す）に解決する Promise。
	 */
	function createDetector() {
		if ( 'BarcodeDetector' in window && 'function' === typeof window.BarcodeDetector.getSupportedFormats ) {
			return window.BarcodeDetector.getSupportedFormats().then( function ( formats ) {
				if ( formats.indexOf( 'qr_code' ) === -1 ) {
					return jsQrDetector();
				}

				var native = new window.BarcodeDetector( { formats: [ 'qr_code' ] } );
				decoderName = 'BarcodeDetector';

				return function ( source ) {
					return native.detect( source ).then( function ( codes ) {
						return ( codes && codes.length ) ? codes[ 0 ].rawValue : null;
					} );
				};
			} ).catch( function () {
				return jsQrDetector();
			} );
		}

		return Promise.resolve( jsQrDetector() );
	}

	/**
	 * jsQR を使う読み取り関数を作る。
	 *
	 * @return {Function|null} 読み取り関数。jsQR が無い場合は null。
	 */
	function jsQrDetector() {
		var jsqr = resolveJsQr();

		if ( ! jsqr ) {
			decoderName = '—';

			return null;
		}

		decoderName = 'jsQR';

		return function ( source ) {
			var width = source.videoWidth;
			var height = source.videoHeight;

			if ( ! width || ! height ) {
				return Promise.resolve( null );
			}

			// 端末によってはフレームが大きすぎて重いので、長辺 640px 相当まで縮めてから解析する。
			var scale = Math.min( 1, 640 / Math.max( width, height ) );
			canvas.width = Math.round( width * scale );
			canvas.height = Math.round( height * scale );
			context.drawImage( source, 0, 0, canvas.width, canvas.height );

			var image = context.getImageData( 0, 0, canvas.width, canvas.height );
			var code = jsqr( image.data, image.width, image.height, { inversionAttempts: 'dontInvert' } );

			return Promise.resolve( code ? code.data : null );
		};
	}

	/**
	 * 映像の大きさが分かるまで待つ。
	 *
	 * iOS Safari は play() が解決した直後も videoWidth が 0 のままで、
	 * そのまま解析を始めても何も読み取れないため、メタデータを待ってから始める。
	 *
	 * @return {Promise} 準備できたら true、待っても始まらなければ false に解決する Promise。
	 */
	function waitForVideo() {
		if ( video.videoWidth > 0 ) {
			return Promise.resolve( true );
		}

		return new Promise( function ( resolve ) {
			var settled = false;

			function done( ready ) {
				if ( settled ) {
					return;
				}
				settled = true;
				video.removeEventListener( 'loadedmetadata', onReady );
				video.removeEventListener( 'canplay', onReady );
				video.removeEventListener( 'playing', onReady );
				window.clearInterval( poll );
				window.clearTimeout( limit );
				resolve( ready );
			}

			function onReady() {
				if ( video.videoWidth > 0 ) {
					done( true );
				}
			}

			video.addEventListener( 'loadedmetadata', onReady );
			video.addEventListener( 'canplay', onReady );
			video.addEventListener( 'playing', onReady );

			var poll = window.setInterval( function () {
				// 再生が中断されていたら、もう一度再生を試す（iOS でときどき起きる）。
				if ( video.paused && stream ) {
					tryPlay();
				}
				onReady();
			}, 250 );

			var limit = window.setTimeout( function () {
				done( video.videoWidth > 0 );
			}, 6000 );
		} );
	}

	/**
	 * 映像の再生を試す。拒否されても処理は続ける。
	 *
	 * @return {Promise} 常に解決する Promise。
	 */
	function tryPlay() {
		var playing;

		try {
			playing = video.play();
		} catch ( error ) {
			lastError = 'play: ' + ( error && error.name ? error.name : 'error' );

			return Promise.resolve();
		}

		if ( playing && playing.catch ) {
			return playing.catch( function ( error ) {
				lastError = 'play: ' + ( error && error.name ? error.name : 'error' );
			} );
		}

		return Promise.resolve();
	}

	/**
	 * フレームごとの読み取りループを始める。
	 *
	 * requestVideoFrameCallback があれば新しいフレームが来たときだけ解析する。
	 * 無い場合は requestAnimationFrame で間隔を空けて解析する。
	 *
	 * @return {void}
	 */
	function startLoop() {
		running = true;
		startedAt = Date.now();
		hintShown = false;

		if ( rvfcSupported ) {
			var onFrame = function () {
				if ( ! running ) {
					return;
				}
				analyze();
				video.requestVideoFrameCallback( onFrame );
			};
			video.requestVideoFrameCallback( onFrame );

			return;
		}

		var loop = function () {
			if ( ! running ) {
				return;
			}
			analyze();
			rafId = window.requestAnimationFrame( loop );
		};
		rafId = window.requestAnimationFrame( loop );
	}

	function stopLoop() {
		running = false;
		if ( rafId ) {
			window.cancelAnimationFrame( rafId );
			rafId = null;
		}
	}

	/**
	 * 1 フレーム解析する（間隔を空けて呼ばれる）。
	 *
	 * @return {void}
	 */
	function analyze() {
		var now = Date.now();

		if ( busy || ! detect || ! stream ) {
			return;
		}
		// 結果を重ねて表示している間は読み取らない（次の人の QR を誤って読まないように）。
		if ( now < holdUntil ) {
			return;
		}
		// 1 秒間に 8 回程度に抑える。これ以上速くしても読み取り精度は上がらない。
		if ( now - lastAnalyzedAt < 120 ) {
			return;
		}
		lastAnalyzedAt = now;

		if ( video.readyState < 2 || ! video.videoWidth ) {
			if ( video.paused ) {
				tryPlay();
			}

			return;
		}

		frames++;
		if ( ! hintShown && now - startedAt > 8000 ) {
			hintShown = true;
			setStatus( config.i18n.scanHint );
		}
		if ( 0 === frames % 16 ) {
			updateDiag();
		}

		detect( video ).then( function ( value ) {
			if ( value ) {
				submit( value );
			}
		} ).catch( function ( error ) {
			lastError = 'detect: ' + ( error && error.name ? error.name : 'error' );
		} );
	}

	/**
	 * getUserMedia の失敗理由を、そのまま読める日本語にする。
	 *
	 * @param {Error} error 失敗理由。
	 * @return {string} メッセージ。
	 */
	function cameraErrorMessage( error ) {
		var name = error && error.name ? error.name : '';
		lastError = name || 'error';

		if ( 'NotAllowedError' === name || 'SecurityError' === name ) {
			return isIos() ? config.i18n.permissionIos : config.i18n.permissionDenied;
		}
		if ( 'NotFoundError' === name || 'OverconstrainedError' === name || 'DevicesNotFoundError' === name ) {
			return config.i18n.noCamera;
		}
		if ( 'NotReadableError' === name || 'TrackStartError' === name ) {
			return config.i18n.cameraBusy;
		}

		// 原因が分からない場合は、問い合わせのときに手がかりが残るよう名前も出す。
		return name ? config.i18n.cameraError + '（' + name + '）' : config.i18n.cameraError;
	}

	function stop() {
		stopLoop();

		if ( stream ) {
			stream.getTracks().forEach( function ( track ) {
				track.stop();
			} );
			stream = null;
		}
		video.srcObject = null;
		root.classList.remove( 'equiticket-scanner--live' );
		if ( scanFrame ) {
			scanFrame.hidden = true;
		}
		stopButton.hidden = true;
		startButton.hidden = false;
		updateDiag();
	}

	function start() {
		var blocked = cameraBlockedReason();
		if ( blocked ) {
			setStatus( blocked );
			updateDiag();

			return;
		}

		setStatus( config.i18n.cameraStart );
		startButton.disabled = true;

		ensureDecoderLibrary().then( function ( ready ) {
			if ( ! ready ) {
				startButton.disabled = false;
				setStatus( config.i18n.decoderMissing );
				updateDiag();

				return;
			}

			openCamera();
		} );
	}

	/**
	 * カメラを開いて読み取りループを始める。
	 *
	 * @return {void}
	 */
	function openCamera() {

		// iOS Safari はこれらが揃っていないと、その場での再生を拒否する。
		video.muted = true;
		video.defaultMuted = true;
		video.playsInline = true;
		video.setAttribute( 'muted', '' );
		video.setAttribute( 'playsinline', '' );
		video.setAttribute( 'webkit-playsinline', '' );
		video.setAttribute( 'autoplay', '' );

		navigator.mediaDevices.getUserMedia( {
			video: {
				facingMode: { ideal: 'environment' },
				width: { ideal: 1280 },
				height: { ideal: 720 }
			},
			audio: false
		} ).then( function ( mediaStream ) {
			stream = mediaStream;
			video.srcObject = mediaStream;
			updateDiag();

			return tryPlay();
		} ).then( function () {
			return waitForVideo();
		} ).then( function ( ready ) {
			updateDiag();

			if ( ! ready ) {
				stop();
				setStatus( config.i18n.cameraSlow );

				return null;
			}

			return detect ? detect : createDetector();
		} ).then( function ( ready ) {
			if ( ! ready ) {
				if ( stream ) {
					stop();
					setStatus( config.i18n.unsupported );
				}

				return;
			}

			detect = ready;
			frames = 0;
			setStatus( config.i18n.cameraReady );
			startButton.hidden = true;
			stopButton.hidden = false;
			root.classList.add( 'equiticket-scanner--live' );
			if ( scanFrame ) {
				scanFrame.hidden = false;
			}
			startLoop();
			updateDiag();
		} ).catch( function ( error ) {
			stop();
			setStatus( cameraErrorMessage( error ) );
			updateDiag();
		} ).then( function () {
			startButton.disabled = false;
		} );
	}

	/**
	 * 撮ってもらった写真 1 枚から QR を読み取る（カメラ映像が使えない端末向け）。
	 *
	 * @param {File} file 画像ファイル。
	 * @return {void}
	 */
	function readPhoto( file ) {
		if ( ! file ) {
			return;
		}

		setStatus( config.i18n.photoReading );

		ensureDecoderLibrary().then( function ( ready ) {
			if ( ! ready ) {
				setStatus( config.i18n.decoderMissing );
				updateDiag();

				return;
			}

			decodePhoto( file );
		} );
	}

	/**
	 * 画像ファイルから QR を読み取る。
	 *
	 * @param {File} file 画像ファイル。
	 * @return {void}
	 */
	function decodePhoto( file ) {

		var url = URL.createObjectURL( file );
		var image = new Image();

		image.onload = function () {
			var found = null;

			// 大きい写真のままだと重く、小さすぎると読めないため、2 段階で試す。
			[ 1600, 800 ].forEach( function ( limit ) {
				if ( found ) {
					return;
				}

				var scale = Math.min( 1, limit / Math.max( image.width, image.height ) );
				canvas.width = Math.max( 1, Math.round( image.width * scale ) );
				canvas.height = Math.max( 1, Math.round( image.height * scale ) );
				context.drawImage( image, 0, 0, canvas.width, canvas.height );

				var jsqr = resolveJsQr();
				if ( jsqr ) {
					var data = context.getImageData( 0, 0, canvas.width, canvas.height );
					var code = jsqr( data.data, data.width, data.height, { inversionAttempts: 'attemptBoth' } );
					if ( code ) {
						found = code.data;
					}
				}
			} );

			URL.revokeObjectURL( url );

			if ( found ) {
				submit( found, false, true );
			} else {
				setStatus( config.i18n.photoFailed );
			}
		};

		image.onerror = function () {
			URL.revokeObjectURL( url );
			setStatus( config.i18n.photoFailed );
		};

		image.src = url;
	}

	/**
	 * 同じ QR を連続で読んでしまうのを防ぎつつ、照合リクエストを送る。
	 *
	 * @param {string}  token    QR の内容（整理券 URL またはトークン）。
	 * @param {boolean} override 無断キャンセル扱いの予約も受け付けるか。
	 * @param {boolean} force    同じ内容でも送る（写真からの読み取り時）。
	 * @return {void}
	 */
	function submit( token, override, force ) {
		var now = Date.now();
		if ( ! override && ! force && token === lastToken && now - lastTokenAt < 5000 ) {
			return;
		}

		lastToken = token;
		lastTokenAt = now;
		busy = true;
		setStatus( config.i18n.processing );

		var body = new FormData();
		body.append( 'action', 'equiticket_checkin' );
		body.append( 'nonce', config.nonce );
		body.append( 'token', token );
		if ( override ) {
			body.append( 'override', '1' );
		}

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			busy = false;
			if ( ! payload || ! payload.success ) {
				setStatus( ( payload && payload.data && payload.data.message ) || config.i18n.networkError );

				return;
			}

			render( payload.data );
			hintShown = true;

			if ( force ) {
				setStatus( config.i18n.photoDone );
			} else {
				setStatus( stream ? config.i18n.scanAgain : config.i18n.cameraReady );
			}
		} ).catch( function () {
			busy = false;
			setStatus( config.i18n.networkError );
		} );
	}

	function render( result ) {
		resultBox.className = 'equiticket-scanner__result equiticket-result equiticket-result--' + result.code;
		resultBox.textContent = '';

		var headline = document.createElement( 'p' );
		headline.className = 'equiticket-result__headline';
		headline.textContent = result.headline;
		resultBox.appendChild( headline );

		[
			[ 'equiticket-result__person', result.person ],
			[ 'equiticket-result__horse', result.horse ],
			[ 'equiticket-result__fee', result.fee ],
			[ 'equiticket-result__slot', result.slot_label ],
			[ 'equiticket-result__detail', result.detail ]
		].forEach( function ( row ) {
			if ( ! row[ 1 ] ) {
				return;
			}
			var line = document.createElement( 'p' );
			line.className = row[ 0 ];
			line.textContent = row[ 1 ];
			resultBox.appendChild( line );
		} );

		if ( result.can_override && result.token ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'equiticket-button';
			button.textContent = config.i18n.overrideButton;
			button.addEventListener( 'click', function () {
				submit( result.token, true );
			} );
			resultBox.appendChild( button );
		}

		showFlash( result );

		if ( navigator.vibrate ) {
			navigator.vibrate( 'ok' === result.code ? 80 : [ 60, 60, 60 ] );
		}
	}

	/**
	 * 結果をカメラ映像の上に数秒だけ大きく表示する。
	 * 受付できたときは緑で約 3 秒、それ以外は色を変えて少し長めに出す。タップで閉じられる。
	 *
	 * @param {Object} result 照合結果。
	 * @return {void}
	 */
	function showFlash( result ) {
		if ( ! flash ) {
			return;
		}

		var ok = 'ok' === result.code;
		var duration = ok ? 3000 : 5000;

		flash.className = 'equiticket-scanner__flash equiticket-scanner__flash--' + result.code;
		flash.textContent = '';

		[
			[ 'equiticket-scanner__flash-headline', ok ? '✓ ' + config.i18n.flashOk : result.headline ],
			[ 'equiticket-scanner__flash-person', result.person ],
			[ 'equiticket-scanner__flash-line', result.horse ],
			[ 'equiticket-scanner__flash-line', result.fee ],
			[ 'equiticket-scanner__flash-detail', ok ? '' : result.detail ],
			[ 'equiticket-scanner__flash-hint', config.i18n.flashDismiss ]
		].forEach( function ( row ) {
			if ( ! row[ 1 ] ) {
				return;
			}
			var line = document.createElement( 'p' );
			line.className = row[ 0 ];
			line.textContent = row[ 1 ];
			flash.appendChild( line );
		} );

		flash.hidden = false;
		holdUntil = Date.now() + duration;

		if ( flashTimer ) {
			window.clearTimeout( flashTimer );
		}
		flashTimer = window.setTimeout( hideFlash, duration );
	}

	/**
	 * 重ねて表示した結果を消し、読み取りを再開する。
	 *
	 * @return {void}
	 */
	function hideFlash() {
		if ( flashTimer ) {
			window.clearTimeout( flashTimer );
			flashTimer = null;
		}
		holdUntil = 0;
		if ( flash ) {
			flash.hidden = true;
		}
	}

	if ( flash ) {
		flash.addEventListener( 'click', hideFlash );
	}

	startButton.addEventListener( 'click', start );
	stopButton.addEventListener( 'click', stop );
	window.addEventListener( 'pagehide', stop );

	// 画面を離れて戻ってきたときに、再生が止まっていることがある。
	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && stream && video.paused ) {
			tryPlay();
		}
	} );

	if ( photoInput ) {
		photoInput.addEventListener( 'change', function () {
			readPhoto( photoInput.files && photoInput.files[ 0 ] );

			// 同じ写真を撮り直したときにも change が起きるようにする。
			photoInput.value = '';
		} );
	}

	// 写真からの読み取りは jsQR だけで動くので、カメラ映像が使えない端末でも使える。
	if ( photoWrap && ( resolveJsQr() || config.jsqrUrl ) ) {
		photoWrap.hidden = false;
	}

	var initialBlocked = cameraBlockedReason();
	if ( initialBlocked ) {
		setStatus( initialBlocked );
		startButton.disabled = true;
	}

	if ( resolveJsQr() ) {
		decoderName = 'jsQR';
	}
	if ( 'BarcodeDetector' in window ) {
		decoderName = 'BarcodeDetector?';
	}
	updateDiag();
} )();
