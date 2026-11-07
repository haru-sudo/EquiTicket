<?php
/**
 * 受付（QR チェックイン）の処理と画面。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * QR の読み取り結果を照合し、来場記録を付けるクラス。
 *
 * 管理画面の「受付」ページと、ショートコード [equiticket_checkin] の
 * どちらからでも同じ画面・同じ処理を使う。
 */
final class EquiTicket_Checkin {

	/**
	 * 直前の照合結果を一時保存する transient のプレフィックス。
	 */
	const RESULT_TRANSIENT = 'equiticket_checkin_result_';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_equiticket_checkin', array( __CLASS__, 'handle_post' ) );
		add_action( 'wp_ajax_equiticket_checkin', array( __CLASS__, 'ajax_checkin' ) );
	}

	/**
	 * トークンを照合し、必要ならチェックインする。
	 *
	 * @param string              $token トークン（QR の URL 全体でも可）。
	 * @param array<string,mixed> $args  オプション。
	 *                                   override: 無断キャンセル扱いの予約も来場済みにする。
	 *                                   dry_run:  照合のみ行い、記録は残さない。
	 * @return array<string,mixed> 照合結果。
	 */
	public static function process( $token, array $args = array() ) {
		$token = EquiTicket_Utils::sanitize_token( $token );

		if ( '' === $token ) {
			return self::result( 'invalid', __( '読み取れませんでした', 'equiticket' ), __( 'もう一度スキャンするか、手動照合に切り替えてください。', 'equiticket' ) );
		}

		$reservation = EquiTicket_Reservations::get_by_token( $token );
		if ( ! $reservation ) {
			return self::result( 'not_found', __( '該当なし', 'equiticket' ), __( 'この QR は当システムの整理券ではありません。名簿で手動照合してください。', 'equiticket' ) );
		}

		$slot = EquiTicket_Slots::get( $reservation->slot_id );

		if ( 'checked_in' === $reservation->status ) {
			return self::result(
				'already',
				__( '使用済み', 'equiticket' ),
				sprintf(
					/* translators: %s: 来場を記録した時刻 */
					__( 'すでに %s に受付済みです。', 'equiticket' ),
					EquiTicket_Utils::time_label( substr( (string) $reservation->checked_in_at, 11 ) )
				),
				$reservation,
				$slot
			);
		}

		if ( 'cancelled' === $reservation->status ) {
			return self::result( 'cancelled', __( '取消済み', 'equiticket' ), __( 'この整理券はご本人によって取り消されています。', 'equiticket' ), $reservation, $slot );
		}

		if ( 'no_show' === $reservation->status && empty( $args['override'] ) ) {
			return self::result(
				'no_show',
				__( '時間切れ', 'equiticket' ),
				__( '受付締切を過ぎたため当日枠として解放されています。空きがあれば「それでも来場済みにする」で受付できます。', 'equiticket' ),
				$reservation,
				$slot
			);
		}

		if ( ! empty( $args['dry_run'] ) ) {
			return self::result( 'ok', __( 'OK', 'equiticket' ), '', $reservation, $slot );
		}

		if ( ! EquiTicket_Reservations::mark_checked_in( $reservation ) ) {
			return self::result( 'error', __( '記録できませんでした', 'equiticket' ), __( '通信状況を確認して、もう一度お試しください。', 'equiticket' ), $reservation, $slot );
		}

		$reservation = EquiTicket_Reservations::get( $reservation->id );
		$detail      = '';

		if ( $slot && $slot->starts_at && $slot->ends_at ) {
			$now       = EquiTicket_Utils::now();
			$tolerance = 10;
			if ( $now < $slot->starts_at->modify( sprintf( '-%d minutes', $tolerance ) ) || $now > $slot->ends_at->modify( sprintf( '+%d minutes', $tolerance ) ) ) {
				$detail = sprintf(
					/* translators: %s: 予約されている時間帯 */
					__( '予約時間は %s です。時間をご確認ください。', 'equiticket' ),
					EquiTicket_Utils::slot_label( $slot )
				);
			}
		}

		return self::result( 'ok', __( 'OK', 'equiticket' ), $detail, $reservation, $slot );
	}

	/**
	 * 照合結果の配列を作る。
	 *
	 * @param string      $code        結果コード。
	 * @param string      $headline    大きく表示する文字列。
	 * @param string      $detail      補足説明。
	 * @param object|null $reservation 予約。
	 * @param object|null $slot        時間枠。
	 * @return array<string,mixed> 照合結果。
	 */
	private static function result( $code, $headline, $detail = '', $reservation = null, $slot = null ) {
		$person = '';
		if ( $reservation ) {
			$person = sprintf(
				/* translators: 1: 予約者名, 2: 人数 */
				__( '%1$s 様・%2$d 名', 'equiticket' ),
				$reservation->name,
				$reservation->people_count
			);
		}

		$horse = '';
		if ( $reservation ) {
			$horse = sprintf(
				/* translators: %s: 馬の種類 */
				__( '乗る馬: %s', 'equiticket' ),
				EquiTicket_Reservations::horse_label( $reservation )
			);
			if ( $reservation->height_cm || $reservation->weight_kg ) {
				$horse .= sprintf(
					/* translators: 1: 身長, 2: 体重 */
					__( '（%1$dcm / %2$dkg）', 'equiticket' ),
					$reservation->height_cm,
					$reservation->weight_kg
				);
			}
		}

		$fee = '';
		if ( $reservation && '' !== (string) $reservation->fee_label ) {
			$fee = sprintf(
				/* translators: %s: 集金する金額 */
				__( '集金: %s', 'equiticket' ),
				$reservation->fee_label
			);
			if ( $reservation->people_count > 1 && $reservation->fee_amount > 0 ) {
				$fee .= sprintf(
					/* translators: 1: 人数, 2: 合計金額 */
					__( ' × %1$d 名 = %2$s 円', 'equiticket' ),
					$reservation->people_count,
					number_format_i18n( $reservation->fee_amount * $reservation->people_count )
				);
			}
		}

		return array(
			'code'           => $code,
			'headline'       => $headline,
			'person'         => $person,
			'horse'          => $horse,
			'fee'            => $fee,
			'detail'         => $detail,
			'slot_label'     => $slot ? EquiTicket_Utils::slot_label( $slot ) : '',
			'ticket_number'  => $reservation ? EquiTicket_Mailer::ticket_number( $reservation ) : '',
			'token'          => $reservation ? $reservation->qr_token : '',
			'can_override'   => ( 'no_show' === $code ),
			'reservation_id' => $reservation ? $reservation->id : 0,
		);
	}

	/**
	 * 受付フォームの POST を処理する（管理画面・フロント共通）。
	 *
	 * @return void
	 */
	public static function handle_post() {
		if ( ! EquiTicket_Utils::can_check_in() ) {
			wp_die( esc_html__( '受付操作の権限がありません。', 'equiticket' ), 403 );
		}
		check_admin_referer( 'equiticket_checkin' );

		$token    = isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_token() で検証する。
		$override = ! empty( $_POST['override'] );
		$result   = self::process( $token, array( 'override' => $override ) );

		set_transient( self::RESULT_TRANSIENT . get_current_user_id(), $result, 2 * MINUTE_IN_SECONDS );

		$redirect = isset( $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_safe_redirect() で検証される。
		if ( '' === $redirect ) {
			$redirect = admin_url( 'admin.php?page=equiticket-checkin' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * スキャナー画面からの Ajax チェックイン。
	 *
	 * @return void
	 */
	public static function ajax_checkin() {
		if ( ! EquiTicket_Utils::can_check_in() ) {
			wp_send_json_error( array( 'message' => __( '受付操作の権限がありません。', 'equiticket' ) ), 403 );
		}
		check_ajax_referer( 'equiticket_checkin_ajax', 'nonce' );

		$token    = isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_token() で検証する。
		$override = ! empty( $_POST['override'] );

		wp_send_json_success( self::process( $token, array( 'override' => $override ) ) );
	}

	/**
	 * 直前の照合結果を取り出す（1 回読むと消える）。
	 *
	 * @return array<string,mixed>|null 照合結果。
	 */
	private static function pull_result() {
		$key    = self::RESULT_TRANSIENT . get_current_user_id();
		$result = get_transient( $key );
		if ( $result ) {
			delete_transient( $key );
		}

		return is_array( $result ) ? $result : null;
	}

	/**
	 * ショートコード [equiticket_checkin] の出力。
	 *
	 * 以前はこのショートコードを貼ったページでも受付できたが、受付の画面が 2 つあると
	 * 迷いやすいため、受付は管理画面の「体験乗馬 → 受付」に一本化した。
	 * すでにこのショートコードを貼ったページがあっても困らないよう、受付画面への案内だけを出す。
	 *
	 * @return string HTML。
	 */
	public static function render_shortcode() {
		wp_enqueue_style( 'equiticket-front' );

		$checkin_url = admin_url( 'admin.php?page=equiticket-checkin' );

		if ( ! EquiTicket_Utils::can_check_in() ) {
			return '<div class="equiticket"><p class="equiticket-alert equiticket-alert--error">'
				. esc_html__( '受付画面はスタッフ用です。ログインしてからご利用ください。', 'equiticket' )
				. '</p><p><a class="equiticket-button" href="' . esc_url( wp_login_url( $checkin_url ) ) . '">'
				. esc_html__( 'ログイン', 'equiticket' ) . '</a></p></div>';
		}

		return '<div class="equiticket"><p>'
			. esc_html__( '受付は管理画面の「体験乗馬 → 受付」で行います。', 'equiticket' )
			. '</p><p><a class="equiticket-button" href="' . esc_url( $checkin_url ) . '">'
			. esc_html__( '受付画面を開く', 'equiticket' ) . '</a></p></div>';
	}

	/**
	 * 受付スクリプトに必要な値を渡す。
	 *
	 * @return void
	 */
	public static function localize_checkin_script() {
		wp_localize_script(
			'equiticket-checkin',
			'equiticketCheckin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'equiticket_checkin_ajax' ),

				// 読み込みに失敗していた場合に、その場で読み込み直すための URL。
				'jsqrUrl' => EQUITICKET_URL . 'assets/js/vendor/jsqr.min.js?ver=1.4.0',
				'i18n'    => array(
					'cameraStart'      => __( 'カメラを起動しています…', 'equiticket' ),
					'cameraReady'      => __( 'QR コードをカメラに向けてください。', 'equiticket' ),
					'cameraError'      => __( 'カメラを使えませんでした。手動照合をご利用ください。', 'equiticket' ),
					'permissionDenied' => __( 'カメラの使用が許可されませんでした。ブラウザーのアドレスバーのカメラアイコン、または端末の設定から許可してください。', 'equiticket' ),
					'noCamera'         => __( 'カメラが見つかりませんでした。別の端末か、手動照合をご利用ください。', 'equiticket' ),
					'cameraBusy'       => __( 'カメラを開けませんでした。他のアプリがカメラを使っていないか確認してください。', 'equiticket' ),
					'insecure'         => __( 'この画面が http で開かれているため、カメラを使えません。https のアドレスで開き直してください（それが難しい場合は手動照合をご利用ください）。', 'equiticket' ),
					'unsupported'      => __( 'このブラウザーはカメラ機能（getUserMedia）に対応していません。別のブラウザーで開くか、「写真で読み取る」・手動照合をご利用ください。', 'equiticket' ),
					'decoderMissing'   => __( 'QR を読み取るためのファイル（assets/js/vendor/jsqr.min.js）を読み込めませんでした。プラグインのファイルが揃っているか、キャッシュ系プラグインで JavaScript を結合していないかを確認してください。', 'equiticket' ),
					'processing'       => __( '照合中…', 'equiticket' ),
					'networkError'     => __( '通信に失敗しました。もう一度お試しください。', 'equiticket' ),
					'scanAgain'        => __( '続けてスキャンできます。', 'equiticket' ),
					'flashOk'          => __( '受付しました', 'equiticket' ),
					'flashDismiss'     => __( 'タップで次の読み取りへ', 'equiticket' ),
					'overrideButton'   => __( 'それでも来場済みにする', 'equiticket' ),
					'diagSecure'       => __( 'https で表示', 'equiticket' ),
					'diagCameraApi'    => __( 'カメラ API', 'equiticket' ),
					'diagJsqr'         => __( 'QR 読み取りライブラリ', 'equiticket' ),
					'diagDecoder'      => __( '読み取り方式', 'equiticket' ),
					'diagVideo'        => __( '映像サイズ', 'equiticket' ),
					'diagTrack'        => __( 'カメラ', 'equiticket' ),
					'diagFrames'       => __( '解析したフレーム数', 'equiticket' ),
					'diagError'        => __( '最後のエラー', 'equiticket' ),
					'diagYes'          => __( 'あり', 'equiticket' ),
					'diagNo'           => __( 'なし', 'equiticket' ),
					'cameraSlow'       => __( 'カメラ映像が始まりません。ページを再読み込みしてからもう一度お試しください。それでも始まらない場合は「写真で読み取る」をご利用ください。', 'equiticket' ),
					'scanHint'         => __( '読み取り中です。QR コードを白い枠の中に、画面の半分くらいの大きさで写してください。', 'equiticket' ),
					'permissionIos'    => __( 'カメラの使用が許可されませんでした。iPhone / iPad は「設定 → Safari → カメラ」を「確認」または「許可」にして、ページを再読み込みしてください。', 'equiticket' ),
					'photoReading'     => __( '写真を解析しています…', 'equiticket' ),
					'photoDone'        => __( '写真から読み取りました。続けて別の写真も読み取れます。', 'equiticket' ),
					'photoFailed'      => __( '写真から QR を読み取れませんでした。明るい場所で、QR が画面いっぱいに写るように撮り直してください。', 'equiticket' ),
				),
			)
		);
	}

	/**
	 * 受付画面本体を出力する。
	 *
	 * @param string $redirect_to フォーム送信後に戻る URL。
	 * @return string HTML。
	 */
	public static function render_screen( $redirect_to ) {
		$result = self::pull_result();
		$html   = '';

		$html .= '<h2 class="equiticket__title">' . esc_html__( 'QR チェックイン', 'equiticket' ) . '</h2>';

		if ( $result ) {
			$html .= self::render_result_box( $result, $redirect_to );
		}

		$html .= '<div class="equiticket-scanner" data-equiticket-scanner>';
		$html .= '<div class="equiticket-scanner__viewport">';
		// webkit-playsinline は古い iOS 向け。muted と合わせて、全画面にならずその場で再生させる。
		$html .= '<video data-equiticket-video playsinline webkit-playsinline muted autoplay></video>';
		$html .= '<span class="equiticket-scanner__frame" data-equiticket-frame hidden></span>';
		// 読み取った結果を、カメラ映像の上に数秒だけ大きく重ねて出す（受付できたかをひと目でわかるように）。
		$html .= '<div class="equiticket-scanner__flash" data-equiticket-flash role="status" aria-live="assertive" hidden></div>';
		$html .= '</div>';
		$html .= '<p class="equiticket-scanner__status" data-equiticket-status>' . esc_html__( 'カメラを起動して QR コードを読み取ります。', 'equiticket' ) . '</p>';
		$html .= '<p class="equiticket-scanner__buttons">';
		$html .= '<button type="button" class="equiticket-button equiticket-button--primary" data-equiticket-scan-start>' . esc_html__( 'カメラで読み取る', 'equiticket' ) . '</button> ';
		$html .= '<button type="button" class="equiticket-button equiticket-button--quiet" data-equiticket-scan-stop hidden>' . esc_html__( '停止', 'equiticket' ) . '</button>';
		$html .= '</p>';

		// カメラ映像が使えない端末（iOS の一部、アプリ内ブラウザー、http 表示など）向けの逃げ道。
		// 写真を 1 枚撮ってもらい、その画像から QR を読み取る。
		$html .= '<p class="equiticket-scanner__photo" data-equiticket-photo-wrap hidden>';
		$html .= '<label class="equiticket-button equiticket-button--quiet">' . esc_html__( '写真で読み取る', 'equiticket' );
		$html .= '<input type="file" accept="image/*" capture="environment" data-equiticket-photo hidden />';
		$html .= '</label>';
		$html .= '</p>';
		$html .= '<div class="equiticket-scanner__result" data-equiticket-result aria-live="polite"></div>';

		// うまく動かないときに、状況をそのまま伝えてもらえるようにしておく。
		$html .= '<details class="equiticket-diag" data-equiticket-diag>';
		$html .= '<summary>' . esc_html__( 'カメラの状態（うまく動かないとき）', 'equiticket' ) . '</summary>';
		$html .= '<dl data-equiticket-diag-body></dl>';
		$html .= '</details>';

		$html .= '</div>';

		$html .= '<div class="equiticket-manual">';
		$html .= '<h3>' . esc_html__( '手動照合', 'equiticket' ) . '</h3>';
		$html .= '<p class="equiticket-help">' . esc_html__( 'QR が読み取れないときは、整理券の URL を貼り付けるか、受付番号の下にあるトークンを入力してください。', 'equiticket' ) . '</p>';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="equiticket_checkin" />';
		$html .= '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect_to ) . '" />';
		$html .= wp_nonce_field( 'equiticket_checkin', '_wpnonce', true, false );
		$html .= '<p><input type="text" name="token" class="equiticket-input" placeholder="' . esc_attr__( '例: https://example.com/?et_token=…', 'equiticket' ) . '" required /></p>';
		$html .= '<p><button type="submit" class="equiticket-button equiticket-button--primary">' . esc_html__( '照合する', 'equiticket' ) . '</button></p>';
		$html .= '</form>';
		$html .= '</div>';

		$html .= self::render_name_search( $redirect_to );
		$html .= self::render_recent_checkins();

		return $html;
	}

	/**
	 * 照合結果の表示ブロック。
	 *
	 * @param array<string,mixed> $result      照合結果。
	 * @param string              $redirect_to 戻り先 URL。
	 * @return string HTML。
	 */
	private static function render_result_box( array $result, $redirect_to ) {
		$html = '<div class="equiticket-result equiticket-result--' . esc_attr( $result['code'] ) . '">';
		$html .= '<p class="equiticket-result__headline">' . esc_html( $result['headline'] ) . '</p>';

		if ( '' !== $result['person'] ) {
			$html .= '<p class="equiticket-result__person">' . esc_html( $result['person'] ) . '</p>';
		}
		if ( ! empty( $result['horse'] ) ) {
			$html .= '<p class="equiticket-result__horse">' . esc_html( $result['horse'] ) . '</p>';
		}
		if ( ! empty( $result['fee'] ) ) {
			$html .= '<p class="equiticket-result__fee">' . esc_html( $result['fee'] ) . '</p>';
		}
		if ( '' !== $result['slot_label'] ) {
			$html .= '<p class="equiticket-result__slot">' . esc_html( $result['slot_label'] ) . '</p>';
		}
		if ( '' !== $result['detail'] ) {
			$html .= '<p class="equiticket-result__detail">' . esc_html( $result['detail'] ) . '</p>';
		}

		if ( ! empty( $result['can_override'] ) && '' !== $result['token'] ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$html .= '<input type="hidden" name="action" value="equiticket_checkin" />';
			$html .= '<input type="hidden" name="token" value="' . esc_attr( $result['token'] ) . '" />';
			$html .= '<input type="hidden" name="override" value="1" />';
			$html .= '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect_to ) . '" />';
			$html .= wp_nonce_field( 'equiticket_checkin', '_wpnonce', true, false );
			$html .= '<button type="submit" class="equiticket-button">' . esc_html__( 'それでも来場済みにする', 'equiticket' ) . '</button>';
			$html .= '</form>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * 氏名での検索フォームと結果（紙名簿の代わりに使う手動照合）。
	 *
	 * @param string $redirect_to 戻り先 URL。
	 * @return string HTML。
	 */
	private static function render_name_search( $redirect_to ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 検索条件の読み取りのみ。
		$search = isset( $_GET['et_search'] ) ? sanitize_text_field( wp_unslash( $_GET['et_search'] ) ) : '';

		$html  = '<div class="equiticket-search">';
		$html .= '<h3>' . esc_html__( '氏名で探す', 'equiticket' ) . '</h3>';
		$html .= '<form method="get" action="' . esc_url( remove_query_arg( array( 'et_search' ), $redirect_to ) ) . '">';

		$query = wp_parse_url( $redirect_to, PHP_URL_QUERY );
		if ( $query ) {
			parse_str( $query, $vars );
			foreach ( $vars as $key => $value ) {
				if ( 'et_search' === $key || ! is_string( $value ) ) {
					continue;
				}
				$html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
			}
		}

		$html .= '<p><input type="search" name="et_search" class="equiticket-input" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'お名前の一部', 'equiticket' ) . '" /> ';
		$html .= '<button type="submit" class="equiticket-button">' . esc_html__( '検索', 'equiticket' ) . '</button></p>';
		$html .= '</form>';

		if ( '' !== $search ) {
			$matches = EquiTicket_Reservations::query(
				array(
					'date'   => EquiTicket_Utils::today(),
					'search' => $search,
					'limit'  => 30,
				)
			);

			if ( ! $matches ) {
				$html .= '<p class="equiticket-empty">' . esc_html__( '該当する予約が見つかりませんでした。', 'equiticket' ) . '</p>';
			} else {
				$html .= '<ul class="equiticket-search__results">';
				foreach ( $matches as $match ) {
					$html .= '<li>';
					$html .= '<span class="equiticket-search__name">' . esc_html( $match->name ) . '</span> ';
					$html .= '<span class="equiticket-search__meta">'
						. esc_html(
							sprintf(
								/* translators: 1: 時間帯, 2: 馬の種類, 3: 人数, 4: ステータス */
								__( '%1$s・%2$s・%3$d 名・%4$s', 'equiticket' ),
								EquiTicket_Utils::time_label( $match->start_time ) . '〜' . EquiTicket_Utils::time_label( $match->end_time ),
								EquiTicket_Reservations::horse_label( $match ),
								$match->people_count,
								EquiTicket_Utils::status_label( $match->status )
							)
						)
						. '</span>';

					if ( in_array( $match->status, array( 'reserved', 'no_show' ), true ) ) {
						$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
						$html .= '<input type="hidden" name="action" value="equiticket_checkin" />';
						$html .= '<input type="hidden" name="token" value="' . esc_attr( $match->qr_token ) . '" />';
						$html .= '<input type="hidden" name="override" value="1" />';
						$html .= '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect_to ) . '" />';
						$html .= wp_nonce_field( 'equiticket_checkin', '_wpnonce', true, false );
						$html .= '<button type="submit" class="equiticket-button equiticket-button--small">' . esc_html__( '来場済みにする', 'equiticket' ) . '</button>';
						$html .= '</form>';
					}

					$html .= '</li>';
				}
				$html .= '</ul>';
			}
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * 直近のチェックイン履歴（取り違えに気づけるように表示する）。
	 *
	 * @return string HTML。
	 */
	private static function render_recent_checkins() {
		$recent = EquiTicket_Reservations::query(
			array(
				'date'     => EquiTicket_Utils::today(),
				'statuses' => array( 'checked_in' ),
				'order'    => 'recent',
				'limit'    => 10,
			)
		);

		if ( ! $recent ) {
			return '';
		}

		$html = '<div class="equiticket-recent"><h3>' . esc_html__( '直近の受付', 'equiticket' ) . '</h3><ul>';
		foreach ( $recent as $item ) {
			$html .= '<li>' . esc_html(
				sprintf(
					/* translators: 1: 受付時刻, 2: 予約者名, 3: 人数 */
					__( '%1$s %2$s 様（%3$d 名）', 'equiticket' ),
					EquiTicket_Utils::time_label( substr( (string) $item->checked_in_at, 11 ) ),
					$item->name,
					$item->people_count
				)
			) . '</li>';
		}
		$html .= '</ul></div>';

		return $html;
	}
}
