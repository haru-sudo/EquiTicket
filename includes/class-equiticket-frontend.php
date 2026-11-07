<?php
/**
 * 来場者向け画面（身体情報の入力・馬の振り分け・空き状況・同意・整理券）。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * ショートコードで来場者向けの画面を出力し、予約と取消の POST を処理するクラス。
 *
 * 申込みは 4 ステップに分かれる。
 *   1. 身長・体重・年齢を入力（ここで、登録されている馬のうち乗れるものが決まる。2 つ以上なら本人が選ぶ）
 *   2. どちらも可の場合は乗る馬を選び、空き状況から時間枠を選ぶ（その馬種の空きだけを表示）
 *   3. 氏名・メールアドレスの入力と同意事項へのチェック（支払額とキャンセルの案内つき）
 *   4. 整理券（QR）の表示と確認メールの送信
 *
 * ステップ間の値は hidden で持ち回り、確定時にサーバー側で必ず判定し直す。
 */
final class EquiTicket_Frontend {

	/**
	 * すべてのフォームで使う nonce のアクション名。
	 */
	const NONCE_ACTION = 'equiticket_form';

	/**
	 * 直前の送信で発生したエラーメッセージ。
	 *
	 * @var array<int,string>
	 */
	private static $errors = array();

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'equiticket_reservation', array( __CLASS__, 'render_reservation' ) );
		add_shortcode( 'equiticket_checkin', array( 'EquiTicket_Checkin', 'render_shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prevent_page_cache' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_standalone_ticket' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_equiticket_availability', array( __CLASS__, 'ajax_availability' ) );
		add_action( 'wp_ajax_nopriv_equiticket_availability', array( __CLASS__, 'ajax_availability' ) );
		add_action( 'wp_ajax_equiticket_ticket_status', array( __CLASS__, 'ajax_ticket_status' ) );
		add_action( 'wp_ajax_nopriv_equiticket_ticket_status', array( __CLASS__, 'ajax_ticket_status' ) );
	}

	/**
	 * スタイルとスクリプトを読み込む。
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		wp_register_style( 'equiticket-front', EQUITICKET_URL . 'assets/css/front.css', array(), EQUITICKET_VERSION );

		// 設定した色・角丸・フォントをサイトに合わせて上書きする。
		wp_add_inline_style( 'equiticket-front', EquiTicket_Options::theme_css() );
		wp_register_script( 'equiticket-front', EQUITICKET_URL . 'assets/js/front.js', array(), EQUITICKET_VERSION, true );

		wp_localize_script(
			'equiticket-front',
			'equiticketFront',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'equiticket_availability' ),
			)
		);
	}

	/**
	 * 予約ページの URL を組み立てる。
	 *
	 * @param array<string,string|int> $args クエリ引数。
	 * @return string URL。
	 */
	public static function page_url( array $args = array() ) {
		$page_id = (int) EquiTicket_Options::get( 'page_id' );
		$base    = ( $page_id > 0 && get_post_status( $page_id ) ) ? get_permalink( $page_id ) : home_url( '/' );

		if ( ! $base ) {
			$base = home_url( '/' );
		}

		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/**
	 * 整理券（予約確認）URL を組み立てる。QR に埋め込むのはこの URL。
	 *
	 * @param string $token QR トークン。
	 * @return string URL。
	 */
	public static function ticket_url( $token ) {
		return self::page_url( array( 'et_token' => $token ) );
	}

	/**
	 * 予約ページと整理券をキャッシュさせない。
	 *
	 * キャッシュされるとフォームの nonce や残り人数が古いまま配信されるため、
	 * キャッシュ系プラグインが見る DONOTCACHEPAGE と no-cache ヘッダーを出す。
	 * 整理券の URL は予約ごとの鍵なので、検索エンジンにも載せない。
	 *
	 * @return void
	 */
	public static function prevent_page_cache() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示の判定のみ。
		$has_token = ! empty( $_GET['et_token'] );
		$page_id   = (int) EquiTicket_Options::get( 'page_id' );
		$is_page   = ( $page_id > 0 && is_page( $page_id ) )
			|| ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'equiticket_reservation' ) );

		if ( ! $has_token && ! $is_page ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		if ( $has_token ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
	}

	/**
	 * 予約確定・取消の POST を処理する。
	 *
	 * 画面の切り替えだけで済むステップ（1→2→3）は描画時に処理する。
	 *
	 * @return void
	 */
	public static function handle_request() {
		$action = self::current_post_action();

		if ( 'reserve' === $action ) {
			self::handle_reserve();
		} elseif ( 'cancel' === $action ) {
			self::handle_cancel();
		}
	}

	/**
	 * 最終送信（予約確定）を処理する。
	 *
	 * @return void
	 */
	private static function handle_reserve() {
		if ( ! self::verify_nonce() ) {
			self::$errors[] = __( '送信内容を確認できませんでした。お手数ですが、もう一度お試しください。', 'equiticket' );

			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 直前に verify_nonce() で検証済み。
		$reservation = EquiTicket_Reservations::create(
			array(
				'slot_id'          => isset( $_POST['slot_id'] ) ? (int) $_POST['slot_id'] : 0,
				'name'             => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'email'            => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'height_cm'        => isset( $_POST['height_cm'] ) ? sanitize_text_field( wp_unslash( $_POST['height_cm'] ) ) : '',
				'weight_kg'        => isset( $_POST['weight_kg'] ) ? sanitize_text_field( wp_unslash( $_POST['weight_kg'] ) ) : '',
				'age'              => isset( $_POST['age'] ) ? sanitize_text_field( wp_unslash( $_POST['age'] ) ) : '',
				'horse_id'         => isset( $_POST['horse_id'] ) ? (int) $_POST['horse_id'] : 0,
				'consent'          => ! empty( $_POST['consent'] ),
				'guardian_consent' => ! empty( $_POST['guardian_consent'] ),
				'photo_optout'     => ! empty( $_POST['photo_optout'] ),
				'source'           => 'web',
			)
		);
		// phpcs:enable

		if ( is_wp_error( $reservation ) ) {
			self::$errors[] = $reservation->get_error_message();

			return;
		}

		$slot = EquiTicket_Slots::get( $reservation->slot_id );
		$sent = EquiTicket_Mailer::send_confirmation( $reservation, $slot );

		wp_safe_redirect(
			self::page_url(
				array(
					'et_token' => $reservation->qr_token,
					'et_msg'   => $sent ? 'reserved' : 'reserved_mail_failed',
				)
			)
		);
		exit;
	}

	/**
	 * 来場者自身によるキャンセルを処理する。
	 *
	 * @return void
	 */
	private static function handle_cancel() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 直後に nonce を検証する。
		$token = isset( $_POST['et_token'] ) ? EquiTicket_Utils::sanitize_token( wp_unslash( $_POST['et_token'] ) ) : '';

		if ( '' === $token || ! self::verify_nonce( 'equiticket_cancel_' . $token ) ) {
			self::$errors[] = __( '送信内容を確認できませんでした。お手数ですが、もう一度お試しください。', 'equiticket' );

			return;
		}

		$reservation = EquiTicket_Reservations::get_by_token( $token );
		if ( ! $reservation ) {
			self::$errors[] = __( '整理券が見つかりませんでした。', 'equiticket' );

			return;
		}
		if ( 'reserved' !== $reservation->status ) {
			self::$errors[] = __( 'この整理券は取り消せない状態です。受付スタッフにお声がけください。', 'equiticket' );

			return;
		}

		EquiTicket_Reservations::cancel( $reservation, 'cancelled' );

		wp_safe_redirect( self::page_url( array( 'et_token' => $token, 'et_msg' => 'cancelled' ) ) );
		exit;
	}

	/**
	 * 予約ページが未設定でも QR の URL が機能するように、単体ページとして整理券を表示する。
	 *
	 * @return void
	 */
	public static function maybe_render_standalone_ticket() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 閲覧のみ。
		if ( empty( $_GET['et_token'] ) ) {
			return;
		}

		$page_id = (int) EquiTicket_Options::get( 'page_id' );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return;
		}
		if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'equiticket_reservation' ) ) {
			return;
		}

		status_header( 200 );
		nocache_headers();

		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<meta name="robots" content="noindex,nofollow" />';
		echo '<title>' . esc_html__( '体験乗馬 整理券', 'equiticket' ) . '</title>';
		echo '<link rel="stylesheet" href="' . esc_url( EQUITICKET_URL . 'assets/css/front.css?ver=' . EQUITICKET_VERSION ) . '" />';
		echo '<style>' . wp_strip_all_tags( EquiTicket_Options::theme_css() ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 色と数値だけを組み立てた CSS。
		echo '</head><body class="equiticket-standalone">';
		echo self::render_reservation(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 各行で個別にエスケープ済み。
		echo '<script src="' . esc_url( EQUITICKET_URL . 'assets/js/front.js?ver=' . EQUITICKET_VERSION ) . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- テーマを通さない単独表示のため直接読み込む。
		echo '</body></html>';
		exit;
	}

	/**
	 * ショートコード [equiticket_reservation] の出力。
	 *
	 * @return string HTML。
	 */
	public static function render_reservation() {
		wp_enqueue_style( 'equiticket-front' );
		wp_enqueue_script( 'equiticket-front' );

		// phpcs:disable WordPress.Security.NonceVerification -- 表示の切り替えに使うだけで、更新は各ハンドラーで nonce 検証済み。
		$token = isset( $_GET['et_token'] ) ? EquiTicket_Utils::sanitize_token( wp_unslash( $_GET['et_token'] ) ) : '';
		if ( '' !== $token ) {
			$message = isset( $_GET['et_msg'] ) ? sanitize_key( wp_unslash( $_GET['et_msg'] ) ) : '';

			return self::render_ticket( $token, $message );
		}

		$action = self::current_post_action();
		if ( ! in_array( $action, array( 'screen', 'choose', 'reserve' ), true ) ) {
			return self::render_step_profile( array() );
		}

		$profile_input = array(
			'height_cm' => isset( $_POST['height_cm'] ) ? sanitize_text_field( wp_unslash( $_POST['height_cm'] ) ) : '',
			'weight_kg' => isset( $_POST['weight_kg'] ) ? sanitize_text_field( wp_unslash( $_POST['weight_kg'] ) ) : '',
			'age'       => isset( $_POST['age'] ) ? sanitize_text_field( wp_unslash( $_POST['age'] ) ) : '',
		);
		$slot_id       = isset( $_POST['slot_id'] ) ? (int) $_POST['slot_id'] : 0;
		$requested     = isset( $_POST['horse_id'] ) ? (int) $_POST['horse_id'] : 0;
		$details       = array(
			'name'  => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'email' => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
		);
		// phpcs:enable

		if ( ! self::verify_nonce() ) {
			if ( ! self::$errors ) {
				self::$errors[] = __( '入力内容を確認できませんでした。はじめからやり直してください。', 'equiticket' );
			}

			return self::render_step_profile( $profile_input );
		}

		$profile = EquiTicket_Screening::assign(
			$profile_input['height_cm'],
			$profile_input['weight_kg'],
			$profile_input['age'],
			null,
			EquiTicket_Slots::offered_types()
		);
		if ( is_wp_error( $profile ) ) {
			self::$errors[] = $profile->get_error_message();

			return self::render_step_profile( $profile_input );
		}

		// 時間枠と馬は、乗れる馬の空き状況を並べた一覧から一度に選んでもらう。
		if ( 'screen' === $action ) {
			return self::render_step_slots( $profile );
		}

		$type = EquiTicket_Screening::resolve_type( $profile, $requested );
		$slot = EquiTicket_Slots::get( $slot_id );
		if ( null === $type || ! $slot ) {
			self::$errors[] = __( '時間枠と馬を選び直してください。', 'equiticket' );

			return self::render_step_slots( $profile );
		}

		return self::render_step_details( $profile, $slot, $details, $type );
	}

	/**
	 * ステップ 1: 身長・体重・年齢の入力。
	 *
	 * @param array<string,string> $values 入力済みの値。
	 * @return string HTML。
	 */
	private static function render_step_profile( array $values ) {
		$limits     = EquiTicket_Screening::limits();
		$max_weight = EquiTicket_Screening::max_weight();

		$html  = '<div class="equiticket equiticket--profile">';
		$html .= self::render_messages( '' );
		$html .= '<h2 class="equiticket__title">' . esc_html( (string) EquiTicket_Options::get( 'event_name' ) ) . '</h2>';
		$html .= self::render_steps( 1 );
		$html .= '<p class="equiticket__lead">' . esc_html__( '体格に合った馬をご用意するため、はじめに乗る方の身長・体重・年齢を入力してください。', 'equiticket' ) . '</p>';
		$html .= '<p class="equiticket-help">' . esc_html__( '身長・体重・年齢は、乗る馬を選ぶためと、今後の運営の参考のために使います。', 'equiticket' ) . '</p>';

		$html .= '<form class="equiticket-form" method="post" action="' . esc_url( self::page_url() ) . '">';
		$html .= '<input type="hidden" name="equiticket_action" value="screen" />';
		$html .= self::nonce_field();

		$html .= self::number_field( 'height_cm', __( '身長', 'equiticket' ), isset( $values['height_cm'] ) ? $values['height_cm'] : '', 50, 250, 'cm', __( '例: 120', 'equiticket' ) );
		$html .= self::number_field( 'weight_kg', __( '体重', 'equiticket' ), isset( $values['weight_kg'] ) ? $values['weight_kg'] : '', 5, 200, 'kg', __( '例: 25', 'equiticket' ) );
		$html .= self::number_field( 'age', __( '年齢', 'equiticket' ), isset( $values['age'] ) ? $values['age'] : '', 0, 120, __( '歳', 'equiticket' ), __( '例: 8', 'equiticket' ) );

		$html .= '<p class="equiticket-help">' . esc_html(
			$max_weight > 0
				? sprintf(
					/* translators: 1: 最低年齢, 2: 体重の上限 */
					__( '対象は %1$d 歳以上、体重 %2$dkg までの方です。体格と日程に合わせて、乗れる馬をご案内します。', 'equiticket' ),
					$limits['min_age'],
					$max_weight
				)
				: sprintf(
					/* translators: %d: 最低年齢 */
					__( '対象は %d 歳以上の方です。体格と日程に合わせて、乗れる馬をご案内します。', 'equiticket' ),
					$limits['min_age']
				)
		) . '</p>';

		$html .= '<p class="equiticket-actions"><button type="submit" class="equiticket-button equiticket-button--primary">'
			. esc_html__( '空き状況を見る', 'equiticket' ) . '</button></p>';
		$html .= '</form>';

		$html .= self::render_notice_block( __( '注意事項', 'equiticket' ), (string) EquiTicket_Options::get( 'notice_text' ) );
		$html .= self::render_availability_overview();
		$html .= '</div>';

		return $html;
	}

	/**
	 * ステップ 2: 空き状況から、時間枠と乗る馬を一度に選ぶ。
	 *
	 * 体格で乗れる馬のうち、その枠で出している馬ごとに「残り N 名」と予約ボタンを並べる。
	 *
	 * @param array<string,mixed> $profile 判定結果。
	 * @return string HTML。
	 */
	private static function render_step_slots( array $profile ) {
		EquiTicket_Cron::maybe_sweep();

		$types = array_map( 'intval', $profile['options'] );

		// 乗れる馬を 1 頭も出していない枠（ミニチュアホースだけの日の大人など）は出さない。
		$slots = array_values(
			array_filter(
				EquiTicket_Slots::query( array( 'from_date' => EquiTicket_Utils::today() ) ),
				static function ( $slot ) use ( $types ) {
					foreach ( $types as $type ) {
						if ( EquiTicket_Slots::offers( $slot, $type ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);

		$html  = '<div class="equiticket equiticket--list">';
		$html .= self::render_messages( '' );
		$html .= '<h2 class="equiticket__title">' . esc_html__( '時間枠と馬を選ぶ', 'equiticket' ) . '</h2>';
		$html .= self::render_steps( 2 );

		$html .= '<div class="equiticket-assignment">';
		$html .= '<p class="equiticket-assignment__type">' . esc_html( $profile['message'] ) . '</p>';
		$html .= '<p class="equiticket-assignment__message">' . esc_html(
			count( $types ) > 1
				? __( '乗りたい時間と馬の「予約する」を押してください。馬によって出ている時間が違う場合があります。', 'equiticket' )
				: __( '乗りたい時間の「予約する」を押してください。', 'equiticket' )
		) . '</p>';
		$html .= '</div>';

		// 乗れる馬の説明（説明文・条件・支払額）。
		$html .= '<div class="equiticket-horse-guide">';
		foreach ( $types as $type ) {
			$html .= '<div class="equiticket-horse-guide__item"><strong>' . esc_html( EquiTicket_Screening::type_label( $type ) ) . '</strong>' . self::type_note( $type ) . '</div>';
		}
		$html .= '</div>';

		$ride_minutes = (int) EquiTicket_Options::get( 'ride_minutes' );
		if ( $ride_minutes > 0 ) {
			/* translators: %d: 1 人あたりの体験時間（分） */
			$html .= '<p class="equiticket__meta">' . esc_html( sprintf( __( '体験時間の目安: 約 %d 分', 'equiticket' ), $ride_minutes ) ) . '</p>';
		}

		if ( ! $slots ) {
			$html .= '<p class="equiticket-empty">' . esc_html__( '現在、受付中の時間枠はありません。', 'equiticket' ) . '</p>';
			$html .= self::back_link( __( '入力し直す', 'equiticket' ) ) . '</div>';

			return $html;
		}

		$current_date = '';
		$available    = 0;

		foreach ( $slots as $slot ) {
			if ( $current_date !== $slot->event_date ) {
				if ( '' !== $current_date ) {
					$html .= '</ul>';
				}
				$current_date = $slot->event_date;
				$html        .= '<h3 class="equiticket__date">' . esc_html( EquiTicket_Utils::date_label( $slot->event_date ) ) . '</h3>';
				$html        .= '<ul class="equiticket-slots" data-equiticket-slots>';
			}

			$options = '';
			$any     = false;
			foreach ( $types as $type ) {
				if ( ! EquiTicket_Slots::offers( $slot, $type ) ) {
					continue;
				}
				$reservable = EquiTicket_Slots::check_reservable( $slot, $type );
				$is_open    = ! is_wp_error( $reservable );
				$any        = $any || $is_open;
				$fee        = EquiTicket_Horses::fee_label( EquiTicket_Horses::get( $type ) );

				$options .= '<div class="equiticket-slot__option' . ( $is_open ? '' : ' is-closed' ) . '" data-slot-id="' . esc_attr( (string) $slot->id ) . '" data-horse-id="' . esc_attr( (string) $type ) . '">';
				$options .= '<div class="equiticket-slot__horse"><strong>' . esc_html( EquiTicket_Screening::type_label( $type ) ) . '</strong>';
				if ( '' !== $fee ) {
					$options .= ' <span class="equiticket-slot__fee">' . esc_html( $fee ) . '</span>';
				}
				$options .= ' ' . ( $is_open
					? '<span class="equiticket-slot__remaining" data-equiticket-remaining>' . esc_html(
						sprintf(
							/* translators: %d: 残り人数 */
							__( '残り %d 名', 'equiticket' ),
							EquiTicket_Slots::remaining_for( $slot, $type )
						)
					) . '</span>'
					: '<span class="equiticket-slot__status" data-equiticket-remaining>' . esc_html( self::closed_label( $reservable ) ) . '</span>' );
				$options .= '</div>';

				if ( $is_open ) {
					$options .= '<form method="post" action="' . esc_url( self::page_url() ) . '" class="equiticket-slot__form">';
					$options .= '<input type="hidden" name="equiticket_action" value="choose" />';
					$options .= '<input type="hidden" name="slot_id" value="' . esc_attr( (string) $slot->id ) . '" />';
					$options .= self::profile_fields( $profile, $type ) . self::nonce_field();
					$options .= '<button type="submit" class="equiticket-button">' . esc_html__( '予約する', 'equiticket' ) . '</button>';
					$options .= '</form>';
				}
				$options .= '</div>';
			}

			if ( $any ) {
				$available++;
			}

			$html .= '<li class="equiticket-slot' . ( $any ? '' : ' equiticket-slot--disabled' ) . '">';
			$html .= '<div class="equiticket-slot__head"><span class="equiticket-slot__time">' . esc_html( EquiTicket_Utils::time_label( $slot->start_time ) . '〜' . EquiTicket_Utils::time_label( $slot->end_time ) ) . '</span>';
			if ( '' !== (string) $slot->note ) {
				$html .= ' <span class="equiticket-slot__note">' . esc_html( $slot->note ) . '</span>';
			}
			$html .= '</div>';
			$html .= $options;
			$html .= '</li>';
		}
		$html .= '</ul>';

		if ( 0 === $available ) {
			$html .= '<p class="equiticket-alert equiticket-alert--warning">' . esc_html__( '申し訳ありません。現在、ご案内できる枠が空いていません。当日の空き状況は会場でもご案内しています。', 'equiticket' ) . '</p>';
		}

		$html .= self::back_link( __( '身長・体重・年齢を入力し直す', 'equiticket' ) );
		$html .= '</div>';

		return $html;
	}

	/**
	 * 受け付けられない理由の短い表示（馬ごとの行に収まるように、満員は「満員」とだけ出す）。
	 *
	 * @param WP_Error $reason check_reservable() の結果。
	 * @return string 表示。
	 */
	private static function closed_label( $reason ) {
		if ( in_array( $reason->get_error_code(), array( 'equiticket_type_full', 'equiticket_slot_full' ), true ) ) {
			return __( '満員', 'equiticket' );
		}

		return $reason->get_error_message();
	}

	/**
	 * ステップ 3: 氏名・メールアドレスと同意事項。
	 *
	 * @param array<string,mixed>  $profile 判定結果。
	 * @param object               $slot    選んだ時間枠。
	 * @param array<string,string> $details 入力済みの氏名・メールアドレス。
	 * @param string               $type    乗る馬の種類。
	 * @return string HTML。
	 */
	private static function render_step_details( array $profile, $slot, array $details, $type ) {
		$reservable = EquiTicket_Slots::check_reservable( $slot, $type );
		$label      = EquiTicket_Screening::type_label( $type );

		$html  = '<div class="equiticket equiticket--form">';
		$html .= self::render_messages( '' );
		$html .= '<h2 class="equiticket__title">' . esc_html__( 'お申し込み内容の入力', 'equiticket' ) . '</h2>';
		$html .= self::render_steps( 3 );

		if ( is_wp_error( $reservable ) ) {
			$html .= '<p class="equiticket-alert equiticket-alert--error">' . esc_html( $reservable->get_error_message() ) . '</p>';
			$html .= self::back_to_slots( $profile, $type, __( '他の時間枠を見る', 'equiticket' ) );
			$html .= '</div>';

			return $html;
		}

		$html .= '<dl class="equiticket-details">';
		$html .= '<dt>' . esc_html__( '日時', 'equiticket' ) . '</dt><dd>' . esc_html( EquiTicket_Utils::slot_label( $slot ) ) . '</dd>';
		$html .= '<dt>' . esc_html__( '乗る馬', 'equiticket' ) . '</dt><dd>' . esc_html( $label ) . '</dd>';
		$html .= '<dt>' . esc_html__( '人数', 'equiticket' ) . '</dt><dd>' . esc_html__( '1 名', 'equiticket' ) . '</dd>';
		$fee_label = EquiTicket_Horses::fee_label( EquiTicket_Horses::get( $type ) );
		if ( '' !== $fee_label ) {
			$html .= '<dt>' . esc_html__( '当日のお支払い', 'equiticket' ) . '</dt><dd>' . esc_html( $fee_label ) . '</dd>';
		}
		$html .= '</dl>';
		$html .= '<p class="equiticket-help">' . esc_html__( '身長・体重によって乗る馬が変わるため、お1人ずつお申し込みください。', 'equiticket' ) . '</p>';

		$html .= '<form class="equiticket-form" method="post" action="' . esc_url( self::page_url() ) . '">';
		$html .= '<input type="hidden" name="equiticket_action" value="reserve" />';
		$html .= '<input type="hidden" name="slot_id" value="' . esc_attr( (string) $slot->id ) . '" />';
		$html .= self::profile_fields( $profile, $type ) . self::nonce_field();

		$html .= '<div class="equiticket-field"><label for="equiticket-name">' . esc_html__( 'お名前', 'equiticket' )
			. '<span class="equiticket-required">' . esc_html__( '必須', 'equiticket' ) . '</span></label>';
		$html .= '<input id="equiticket-name" type="text" name="name" value="' . esc_attr( $details['name'] ) . '" maxlength="50" required autocomplete="name" /></div>';

		$html .= '<div class="equiticket-field"><label for="equiticket-email">' . esc_html__( 'メールアドレス', 'equiticket' )
			. '<span class="equiticket-required">' . esc_html__( '必須', 'equiticket' ) . '</span></label>';
		$html .= '<input id="equiticket-email" type="email" name="email" value="' . esc_attr( $details['email'] ) . '" maxlength="190" required autocomplete="email" inputmode="email" />';
		$html .= '<span class="equiticket-help">' . esc_html__( '整理券（QR コード）をこのアドレスにお送りします。', 'equiticket' ) . '</span></div>';

		$html .= self::render_payment_block( $fee_label );

		$terms = EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'terms_text' ) );
		if ( '' !== $terms ) {
			$html .= '<div class="equiticket-terms"><h3>' . esc_html__( '同意事項', 'equiticket' ) . '</h3>';
			$html .= '<div class="equiticket-terms__body">' . nl2br( esc_html( $terms ) ) . '</div></div>';
		}

		$html .= '<div class="equiticket-field equiticket-field--checkbox"><label>';
		$html .= '<input type="checkbox" name="consent" value="1" required /> ';
		$html .= '<span>' . esc_html( (string) EquiTicket_Options::get( 'consent_label' ) ) . '</span></label></div>';

		if ( ! empty( $profile['requires_guardian'] ) ) {
			$html .= '<div class="equiticket-field equiticket-field--checkbox"><label>';
			$html .= '<input type="checkbox" name="guardian_consent" value="1" required /> ';
			$html .= '<span>' . esc_html( (string) EquiTicket_Options::get( 'guardian_label' ) ) . '</span></label></div>';
		}

		$photo_label = (string) EquiTicket_Options::get( 'photo_optout_label' );
		if ( '' !== $photo_label ) {
			$html .= '<div class="equiticket-field equiticket-field--checkbox"><label>';
			$html .= '<input type="checkbox" name="photo_optout" value="1" /> ';
			$html .= '<span>' . esc_html( $photo_label ) . '</span></label></div>';
		}

		$html .= '<p class="equiticket-actions"><button type="submit" class="equiticket-button equiticket-button--primary">'
			. esc_html__( '同意して整理券を受け取る', 'equiticket' ) . '</button></p>';
		$html .= '</form>';

		$html .= self::back_to_slots( $profile, $type, __( '時間枠を選び直す', 'equiticket' ) );
		$html .= '</div>';

		return $html;
	}

	/**
	 * ステップ 4: 整理券（QR 表示・確認）画面。
	 *
	 * @param string $token   QR トークン。
	 * @param string $message メッセージキー。
	 * @return string HTML。
	 */
	private static function render_ticket( $token, $message = '' ) {
		$reservation = EquiTicket_Reservations::get_by_token( $token );

		if ( ! $reservation ) {
			$html = '<div class="equiticket equiticket--ticket">';
			$html .= '<p class="equiticket-alert equiticket-alert--error">' . esc_html__( '整理券が見つかりませんでした。URL をご確認ください。', 'equiticket' ) . '</p>';
			$html .= '<p><a class="equiticket-button" href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'はじめから申し込む', 'equiticket' ) . '</a></p></div>';

			return $html;
		}

		$slot       = EquiTicket_Slots::get( $reservation->slot_id );
		$ticket_url = self::ticket_url( $reservation->qr_token );
		$checked_in = 'checked_in' === $reservation->status;

		// 予約済みの間は、受付されたらこの画面が自動で「受付済み」に切り替わるようにする（front.js）。
		$html = '<div class="equiticket equiticket--ticket"'
			. ( 'reserved' === $reservation->status
				? ' data-equiticket-ticket="' . esc_attr( $reservation->qr_token ) . '" data-equiticket-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"'
				: '' )
			. '>';

		$html .= self::render_messages( $message );

		if ( $checked_in ) {
			$html .= '<div class="equiticket-checked-in" role="status">';
			$html .= '<p class="equiticket-checked-in__headline">' . esc_html__( '✓ 受付済み', 'equiticket' ) . '</p>';
			if ( $reservation->checked_in_at ) {
				$html .= '<p class="equiticket-checked-in__time">' . esc_html(
					sprintf(
						/* translators: %s: 受付した時刻 */
						__( '%s に受付しました', 'equiticket' ),
						EquiTicket_Utils::time_label( substr( (string) $reservation->checked_in_at, 11 ) )
					)
				) . '</p>';
			}
			$html .= '<p class="equiticket-checked-in__note">' . esc_html__( '係員の案内に従ってお進みください。', 'equiticket' ) . '</p>';
			$html .= '</div>';
		}

		$html .= '<h2 class="equiticket__title">' . esc_html__( '体験乗馬 整理券', 'equiticket' ) . '</h2>';
		if ( ! $checked_in ) {
			$html .= '<p class="equiticket-status equiticket-status--' . esc_attr( $reservation->status ) . '">'
				. esc_html( EquiTicket_Utils::status_label( $reservation->status ) ) . '</p>';
		}

		$html .= '<dl class="equiticket-details">';
		$html .= '<dt>' . esc_html__( '受付番号', 'equiticket' ) . '</dt><dd>' . esc_html( EquiTicket_Mailer::ticket_number( $reservation ) ) . '</dd>';
		if ( $slot ) {
			$html .= '<dt>' . esc_html__( '日時', 'equiticket' ) . '</dt><dd>' . esc_html( EquiTicket_Utils::slot_label( $slot ) ) . '</dd>';
		}
		$html .= '<dt>' . esc_html__( 'お名前', 'equiticket' ) . '</dt><dd>' . esc_html( $reservation->name ) . '</dd>';
		$html .= '<dt>' . esc_html__( '乗る馬', 'equiticket' ) . '</dt><dd>' . esc_html( EquiTicket_Reservations::horse_label( $reservation ) ) . '</dd>';
		$html .= '<dt>' . esc_html__( '人数', 'equiticket' ) . '</dt><dd>' . esc_html(
			sprintf(
				/* translators: %d: 人数 */
				__( '%d 名', 'equiticket' ),
				$reservation->people_count
			)
		) . '</dd>';
		if ( '' !== (string) $reservation->fee_label ) {
			$html .= '<dt>' . esc_html__( '当日のお支払い', 'equiticket' ) . '</dt><dd>' . esc_html( $reservation->fee_label ) . '</dd>';
		}
		$html .= '</dl>';

		if ( 'reserved' === $reservation->status ) {
			// QR は画像ではなく SVG としてページに直接書き込む
			// （LINE などのアプリ内ブラウザで画像の QR が出ないことがあるため）。
			$qr_html = EquiTicket_QR::svg( $ticket_url, 260, 4, __( '整理券の QR コード', 'equiticket' ) );

			$html .= self::render_in_app_browser_notice( $ticket_url );
			if ( '' !== $qr_html ) {
				$html .= '<figure class="equiticket-qr">';
				$html .= $qr_html; // SVG は数値とエスケープ済みの説明だけで組み立てている。
				$html .= '<figcaption>' . esc_html__( '受付でこの QR コードをご提示ください。', 'equiticket' ) . '</figcaption>';
				$html .= '</figure>';
			}
			$html .= '<p class="equiticket-help">' . esc_html__( '会場の通信が混み合うことがあります。この画面のスクリーンショットを保存しておくと安心です。', 'equiticket' ) . '</p>';
			$html .= '<p class="equiticket-url"><span>' . esc_html__( '整理券の URL', 'equiticket' ) . '</span><br /><code>' . esc_html( $ticket_url ) . '</code></p>';
		}
		if ( in_array( $reservation->status, array( 'reserved', 'checked_in' ), true ) ) {
			$html .= self::render_payment_block( (string) $reservation->fee_label );
		}

		if ( 'reserved' === $reservation->status ) {
			$html .= '<form class="equiticket-cancel" method="post" action="' . esc_url( $ticket_url ) . '">';
			$html .= '<input type="hidden" name="equiticket_action" value="cancel" />';
			$html .= '<input type="hidden" name="et_token" value="' . esc_attr( $reservation->qr_token ) . '" />';
			$html .= wp_nonce_field( 'equiticket_cancel_' . $reservation->qr_token, 'equiticket_nonce', true, false );
			$html .= '<button type="submit" class="equiticket-button equiticket-button--quiet" data-equiticket-confirm="'
				. esc_attr__( 'この整理券を取り消します。よろしいですか？', 'equiticket' ) . '">'
				. esc_html__( '予約を取り消す', 'equiticket' ) . '</button>';
			$html .= '</form>';
		}

		$html .= self::render_notice_block( __( 'キャンセル・中止について', 'equiticket' ), (string) EquiTicket_Options::get( 'cancel_policy_text' ) );
		$html .= self::render_notice_block( __( '注意事項', 'equiticket' ), (string) EquiTicket_Options::get( 'notice_text' ) );
		$html .= self::render_notice_block( __( '会場', 'equiticket' ), (string) EquiTicket_Options::get( 'venue_text' ) );

		$html .= '<p class="equiticket-back"><a href="' . esc_url( self::page_url() ) . '">' . esc_html__( '別の方の分を申し込む', 'equiticket' ) . '</a></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * 当日の支払額とキャンセル期限の案内。
	 *
	 * @param string $fee_label 確定した支払額（空なら金額の行を出さない）。
	 * @return string HTML。
	 */
	private static function render_payment_block( $fee_label = '' ) {
		$payment = EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'payment_text' ) );
		$cancel  = EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'cancel_policy_text' ) );

		if ( '' === $fee_label && '' === $payment && '' === $cancel ) {
			return '';
		}

		$html = '<div class="equiticket-payment"><h3>' . esc_html__( '当日のお支払い・キャンセル', 'equiticket' ) . '</h3>';

		if ( '' !== $fee_label ) {
			$html .= '<p class="equiticket-payment__fee">' . esc_html(
				sprintf(
					/* translators: %s: 参加費 */
					__( '当日のお支払い: %s', 'equiticket' ),
					$fee_label
				)
			) . '</p>';
		}
		if ( '' !== $payment ) {
			$html .= '<p>' . nl2br( esc_html( $payment ) ) . '</p>';
		}
		if ( '' !== $cancel ) {
			$html .= '<p>' . nl2br( esc_html( $cancel ) ) . '</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * ステップ 1 で参考表示する、時間枠ごとの空き状況。
	 *
	 * @return string HTML。
	 */
	private static function render_availability_overview() {
		EquiTicket_Cron::maybe_sweep();

		$slots = EquiTicket_Slots::query( array( 'from_date' => EquiTicket_Utils::today() ) );
		if ( ! $slots ) {
			return '<p class="equiticket-empty">' . esc_html__( '現在、受付中の時間枠はありません。', 'equiticket' ) . '</p>';
		}

		$html         = '<div class="equiticket-overview"><h3>' . esc_html__( '空き状況（目安）', 'equiticket' ) . '</h3>';
		$current_date = '';

		foreach ( $slots as $slot ) {
			if ( $current_date !== $slot->event_date ) {
				if ( '' !== $current_date ) {
					$html .= '</ul>';
				}
				$current_date = $slot->event_date;
				$html        .= '<h4>' . esc_html( EquiTicket_Utils::date_label( $slot->event_date ) ) . '</h4><ul>';
			}

			if ( 'cancelled' === $slot->status ) {
				$state = __( '中止', 'equiticket' );
			} elseif ( $slot->remaining <= 0 ) {
				$state = __( '満席', 'equiticket' );
			} else {
				$parts = array();
				foreach ( $slot->horses as $entry ) {
					if ( $entry->capacity > 0 ) {
						/* translators: 1: 馬の種類, 2: 残り人数 */
						$parts[] = sprintf( __( '%1$s 残り %2$d 名', 'equiticket' ), $entry->name, $entry->remaining );
					}
				}
				$state = implode( ' ／ ', $parts );
			}

			$html .= '<li><span class="equiticket-overview__time">'
				. esc_html( EquiTicket_Utils::time_label( $slot->start_time ) . '〜' . EquiTicket_Utils::time_label( $slot->end_time ) )
				. '</span> <span class="equiticket-overview__state">' . esc_html( $state ) . '</span></li>';
		}

		$html .= '</ul></div>';

		return $html;
	}

	/**
	 * ステップ表示。
	 *
	 * @param int $current 現在のステップ（1〜4）。
	 * @return string HTML。
	 */
	private static function render_steps( $current ) {
		$labels = array(
			1 => __( '体格の入力', 'equiticket' ),
			2 => __( '時間枠を選ぶ', 'equiticket' ),
			3 => __( '申込み・同意', 'equiticket' ),
			4 => __( '整理券', 'equiticket' ),
		);

		$html = '<ol class="equiticket-steps">';
		foreach ( $labels as $number => $label ) {
			$state = 'equiticket-steps__item';
			if ( $number === $current ) {
				$state .= ' is-current';
			} elseif ( $number < $current ) {
				$state .= ' is-done';
			}
			$html .= '<li class="' . esc_attr( $state ) . '"><span class="equiticket-steps__number">' . (int) $number . '</span>'
				. '<span class="equiticket-steps__label">' . esc_html( $label ) . '</span></li>';
		}
		$html .= '</ol>';

		return $html;
	}

	/**
	 * 数値入力欄（単位つき）。
	 *
	 * @param string $name  フィールド名。
	 * @param string $label ラベル。
	 * @param string $value 現在値。
	 * @param int    $min   最小値。
	 * @param int    $max   最大値。
	 * @param string $unit        単位（cm / kg / 歳）。
	 * @param string $placeholder 入力例。
	 * @return string HTML。
	 */
	private static function number_field( $name, $label, $value, $min, $max, $unit = '', $placeholder = '' ) {
		$id = 'equiticket-' . sanitize_html_class( $name );

		$html  = '<div class="equiticket-field">';
		$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label )
			. '<span class="equiticket-required">' . esc_html__( '必須', 'equiticket' ) . '</span></label>';
		$html .= '<span class="equiticket-field__control">';
		$html .= '<input id="' . esc_attr( $id ) . '" type="number" inputmode="numeric" pattern="[0-9]*" name="' . esc_attr( $name ) . '"'
			. ' value="' . esc_attr( (string) $value ) . '" min="' . (int) $min . '" max="' . (int) $max . '" step="1"'
			. ' placeholder="' . esc_attr( $placeholder ) . '" required />';

		if ( '' !== $unit ) {
			$html .= '<span class="equiticket-field__unit">' . esc_html( $unit ) . '</span>';
		}

		$html .= '</span></div>';

		return $html;
	}

	/**
	 * ステップ間で持ち回る身体情報と馬種の hidden フィールド。
	 *
	 * 値は毎回サーバー側で判定し直すので、書き換えられても選べない馬種は通らない。
	 *
	 * @param array<string,mixed> $profile 判定結果。
	 * @param int                 $type    乗る馬の ID（未確定なら 0）。
	 * @return string HTML。
	 */
	private static function profile_fields( array $profile, $type = 0 ) {
		$html = '<input type="hidden" name="height_cm" value="' . esc_attr( (string) $profile['height_cm'] ) . '" />'
			. '<input type="hidden" name="weight_kg" value="' . esc_attr( (string) $profile['weight_kg'] ) . '" />'
			. '<input type="hidden" name="age" value="' . esc_attr( (string) $profile['age'] ) . '" />';

		if ( $type ) {
			$html .= '<input type="hidden" name="horse_id" value="' . esc_attr( (string) (int) $type ) . '" />';
		}

		return $html;
	}

	/**
	 * 馬を選ぶ画面で、それぞれの馬に添える説明（説明文・条件・支払額）。
	 *
	 * @param int $type 馬の ID。
	 * @return string HTML。
	 */
	private static function type_note( $type ) {
		$horse = EquiTicket_Horses::get( $type );
		if ( ! $horse ) {
			return '';
		}

		$html = '';
		if ( '' !== $horse->description ) {
			$html .= '<span class="equiticket-choice__note">' . esc_html( $horse->description ) . '</span>';
		}

		/* translators: %s: 条件 */
		$html .= '<span class="equiticket-choice__note">' . esc_html( sprintf( __( '対象: %s', 'equiticket' ), EquiTicket_Horses::conditions_label( $horse ) ) ) . '</span>';

		$fee = EquiTicket_Horses::fee_label( $horse );
		if ( '' !== $fee ) {
			/* translators: %s: 金額 */
			$html .= '<span class="equiticket-choice__note">' . esc_html( sprintf( __( '当日のお支払い: %s', 'equiticket' ), $fee ) ) . '</span>';
		}

		return $html;
	}

	/**
	 * nonce フィールド。
	 *
	 * @return string HTML。
	 */
	private static function nonce_field() {
		return wp_nonce_field( self::NONCE_ACTION, 'equiticket_nonce', true, false );
	}

	/**
	 * nonce を検証する。
	 *
	 * @param string|null $action アクション名（省略時は共通のフォーム用）。
	 * @return bool 正しければ true。
	 */
	private static function verify_nonce( $action = null ) {
		$action = $action ? $action : self::NONCE_ACTION;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- この関数自体が nonce の検証。
		if ( ! isset( $_POST['equiticket_nonce'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 同上。
		return (bool) wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['equiticket_nonce'] ) ), $action );
	}

	/**
	 * 時間枠選択に戻るフォーム。
	 *
	 * @param array<string,mixed> $profile 判定結果。
	 * @param string              $type    乗る馬の種類。
	 * @param string              $label   ボタンのラベル。
	 * @return string HTML。
	 */
	private static function back_to_slots( array $profile, $type, $label ) {
		$html  = '<form class="equiticket-back" method="post" action="' . esc_url( self::page_url() ) . '">';
		$html .= '<input type="hidden" name="equiticket_action" value="screen" />';
		$html .= self::profile_fields( $profile, $type ) . self::nonce_field();
		$html .= '<button type="submit" class="equiticket-button equiticket-button--quiet">' . esc_html( $label ) . '</button>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * 最初のステップに戻るリンク。
	 *
	 * @param string $label ラベル。
	 * @return string HTML。
	 */
	private static function back_link( $label ) {
		return '<p class="equiticket-back"><a href="' . esc_url( self::page_url() ) . '">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * 見出しつきの案内ブロック。
	 *
	 * @param string $heading 見出し。
	 * @param string $text    本文。
	 * @return string HTML。
	 */
	private static function render_notice_block( $heading, $text ) {
		$text = EquiTicket_Options::render_text( $text );
		if ( '' === $text ) {
			return '';
		}

		return '<div class="equiticket-notice"><h3>' . esc_html( $heading ) . '</h3>'
			. '<div class="equiticket-notice__body">' . nl2br( esc_html( $text ) ) . '</div></div>';
	}

	/**
	 * 直前の POST のアクション名を返す。
	 *
	 * @return string アクション名。
	 */
	private static function current_post_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 分岐のための読み取りのみ。各処理で nonce を検証する。
		return isset( $_POST['equiticket_action'] ) ? sanitize_key( wp_unslash( $_POST['equiticket_action'] ) ) : '';
	}

	/**
	 * エラー・完了メッセージを出力する。
	 *
	 * @param string $message メッセージキー。
	 * @return string HTML。
	 */
	private static function render_messages( $message ) {
		$html = '';

		foreach ( self::$errors as $error ) {
			$html .= '<p class="equiticket-alert equiticket-alert--error">' . esc_html( $error ) . '</p>';
		}

		$notices = array(
			'reserved'             => array( 'success', __( '整理券を発行しました。確認メールもお送りしています。', 'equiticket' ) ),
			'reserved_mail_failed' => array( 'warning', __( '整理券を発行しました。ただし確認メールの送信に失敗しました。この画面のスクリーンショットを保存してください。', 'equiticket' ) ),
			'cancelled'            => array( 'success', __( '予約を取り消しました。ご連絡ありがとうございました。', 'equiticket' ) ),
			'checked_in'           => array( 'success', __( '来場を記録しました。', 'equiticket' ) ),
		);

		if ( isset( $notices[ $message ] ) ) {
			$html .= '<p class="equiticket-alert equiticket-alert--' . esc_attr( $notices[ $message ][0] ) . '">'
				. esc_html( $notices[ $message ][1] ) . '</p>';
		}

		return $html;
	}

	/**
	 * LINE のアプリ内ブラウザで開いたときに出す、「ブラウザで開く」の案内。
	 *
	 * ページキャッシュの影響を受けないよう、表示するかどうかは front.js が端末の情報で決める。
	 * LINE は URL に openExternalBrowser=1 が付いていると、端末のブラウザで開き直す。
	 *
	 * @param string $ticket_url 整理券の URL。
	 * @return string HTML。
	 */
	private static function render_in_app_browser_notice( $ticket_url ) {
		return '<div class="equiticket-inapp" data-equiticket-inapp hidden>'
			. '<p>' . esc_html__( 'LINE の中で開いています。QR コードが表示されない場合や、あとで見返すときは、Safari・Chrome などのブラウザで開いてください。', 'equiticket' ) . '</p>'
			. '<p><a class="equiticket-button equiticket-button--primary" href="' . esc_url( add_query_arg( array( 'openExternalBrowser' => 1 ), $ticket_url ) ) . '">'
			. esc_html__( 'ブラウザで開く', 'equiticket' ) . '</a></p>'
			. '</div>';
	}

	/**
	 * 整理券の状態を返す Ajax エンドポイント（受付されたら整理券の画面を切り替えるため）。
	 *
	 * 整理券のトークン（推測できない 40 文字）を知っている人だけが、その予約の状態だけを読める。
	 * ページキャッシュで nonce が古くなっても動くよう、nonce は使わない（読み取りのみ）。
	 *
	 * @return void
	 */
	public static function ajax_ticket_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 読み取りのみ。トークンで対象を限定する。
		$token       = isset( $_POST['token'] ) ? EquiTicket_Utils::sanitize_token( wp_unslash( $_POST['token'] ) ) : '';
		$reservation = '' !== $token ? EquiTicket_Reservations::get_by_token( $token ) : null;

		if ( ! $reservation ) {
			wp_send_json_error( null, 404 );
		}

		wp_send_json_success( array( 'status' => $reservation->status ) );
	}

	/**
	 * 残り人数を返す Ajax エンドポイント（一覧の自動更新用）。
	 *
	 * @return void
	 */
	public static function ajax_availability() {
		check_ajax_referer( 'equiticket_availability', 'nonce' );

		EquiTicket_Cron::maybe_sweep();

		$slots  = EquiTicket_Slots::query( array( 'from_date' => EquiTicket_Utils::today() ) );
		$result = array();

		// 枠ごと・馬ごとの残りと、受け付けられるかどうか。
		foreach ( $slots as $slot ) {
			foreach ( $slot->horses as $horse_id => $entry ) {
				$reservable = EquiTicket_Slots::check_reservable( $slot, $horse_id );
				$remaining  = EquiTicket_Slots::remaining_for( $slot, $horse_id );

				$result[] = array(
					'slot'      => $slot->id,
					'horse'     => $horse_id,
					'remaining' => $remaining,
					'open'      => ! is_wp_error( $reservable ),
					'label'     => is_wp_error( $reservable )
						? self::closed_label( $reservable )
						: sprintf(
							/* translators: %d: 残り人数 */
							__( '残り %d 名', 'equiticket' ),
							$remaining
						),
				);
			}
		}

		wp_send_json_success( array( 'slots' => $result ) );
	}
}
