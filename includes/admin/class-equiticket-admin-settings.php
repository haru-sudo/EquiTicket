<?php
/**
 * 設定画面。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 参加条件・締切・メール文面などの設定と、運用ツールをまとめた管理ページ。
 */
final class EquiTicket_Admin_Settings {

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_equiticket_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_equiticket_send_test_mail', array( __CLASS__, 'handle_test_mail' ) );
		add_action( 'admin_post_equiticket_clear_data', array( __CLASS__, 'handle_clear_data' ) );
	}

	/**
	 * 権限を確認する。
	 *
	 * @return void
	 */
	private static function require_cap() {
		if ( ! EquiTicket_Utils::can_manage() ) {
			wp_die( esc_html__( '設定を変更する権限がありません。', 'equiticket' ), 403 );
		}
	}

	/**
	 * 設定を保存する。
	 *
	 * @return void
	 */
	public static function handle_save() {
		self::require_cap();
		check_admin_referer( 'equiticket_save_settings' );

		$input = isset( $_POST['equiticket'] ) && is_array( $_POST['equiticket'] )
			? wp_unslash( $_POST['equiticket'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize() で項目ごとに検証する。
			: array();

		EquiTicket_Options::update( EquiTicket_Options::sanitize( $input ) );
		EquiTicket_Admin::add_notice( __( '設定を保存しました。', 'equiticket' ), 'success' );

		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-settings' ) );
		exit;
	}

	/**
	 * テストメールを送信する。
	 *
	 * @return void
	 */
	public static function handle_test_mail() {
		self::require_cap();
		check_admin_referer( 'equiticket_send_test_mail' );

		$to   = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		$sent = EquiTicket_Mailer::send_test( $to );

		EquiTicket_Admin::add_notice(
			$sent
				? sprintf(
					/* translators: %s: 宛先メールアドレス */
					__( '%s にテストメールを送信しました。受信できたか確認してください。', 'equiticket' ),
					$to
				)
				: __( 'テストメールを送信できませんでした。宛先と送信設定（SMTP プラグインの利用など）を確認してください。', 'equiticket' ),
			$sent ? 'success' : 'error'
		);

		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-settings' ) );
		exit;
	}

	/**
	 * 予約データ（と必要なら時間枠）を削除する。
	 *
	 * 取り消せない操作なので、確認の文字を入力してもらってから実行する。
	 *
	 * @return void
	 */
	public static function handle_clear_data() {
		self::require_cap();
		check_admin_referer( 'equiticket_clear_data' );

		$scope   = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$date    = isset( $_POST['target_date'] ) ? EquiTicket_Utils::sanitize_date( wp_unslash( $_POST['target_date'] ) ) : '';
		$confirm = isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '';
		$word    = ( 'all' === $scope ) ? __( '初期化', 'equiticket' ) : __( '削除', 'equiticket' );

		if ( $confirm !== $word ) {
			EquiTicket_Admin::add_notice(
				sprintf(
					/* translators: %s: 入力してもらう確認の文字 */
					__( '確認のため「%s」と入力してください。データは削除していません。', 'equiticket' ),
					$word
				),
				'error'
			);

			wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-settings' ) );
			exit;
		}

		if ( 'all' === $scope ) {
			$result = EquiTicket_Slots::delete_all( $date );
			EquiTicket_Admin::add_notice(
				sprintf(
					/* translators: 1: 対象範囲, 2: 時間枠の件数, 3: 予約の件数 */
					__( '%1$sの時間枠 %2$d 件と予約 %3$d 件を削除しました。', 'equiticket' ),
					'' === $date ? __( 'すべて', 'equiticket' ) : $date,
					$result['slots'],
					$result['reservations']
				),
				'success'
			);
		} else {
			$deleted = EquiTicket_Reservations::delete_all( $date );
			EquiTicket_Admin::add_notice(
				sprintf(
					/* translators: 1: 対象範囲, 2: 件数 */
					__( '%1$sの予約 %2$d 件を削除しました。時間枠はそのまま残しています。', 'equiticket' ),
					'' === $date ? __( 'すべて', 'equiticket' ) : $date,
					$deleted
				),
				'success'
			);
		}

		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-settings' ) );
		exit;
	}

	/**
	 * 画面を出力する。
	 *
	 * @return void
	 */
	public static function render() {
		self::require_cap();

		$options        = EquiTicket_Options::all();

		echo '<div class="wrap equiticket-wrap">';
		echo '<h1>' . esc_html__( 'EquiTicket 設定', 'equiticket' ) . '</h1>';

		self::render_usage();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="equiticket_save_settings" />';
		wp_nonce_field( 'equiticket_save_settings' );

		echo '<h2>' . esc_html__( '基本設定', 'equiticket' ) . '</h2>';
		echo '<table class="form-table"><tbody>';

		self::text_row( __( 'イベント名', 'equiticket' ), 'event_name', $options['event_name'] );

		echo '<tr><th scope="row">' . esc_html__( '予約ページ', 'equiticket' ) . '</th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'equiticket[page_id]',
				'selected'          => (int) $options['page_id'],
				'show_option_none'  => __( '— 選択してください —', 'equiticket' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'ショートコード [equiticket_reservation] を貼った固定ページを選びます。QR に埋め込む URL の組み立てに使います。', 'equiticket' ) . '</p>';
		echo '</td></tr>';

		self::number_row(
			__( '無断キャンセルの締切（分）', 'equiticket' ),
			'no_show_minutes',
			$options['no_show_minutes'],
			0,
			240,
			__( '枠の開始からこの分数を過ぎても受付がない予約を、無断キャンセルにして当日枠として解放します。', 'equiticket' )
		);
		self::number_row(
			__( '予約受付の締切（終了の何分前まで）', 'equiticket' ),
			'reservation_cutoff_minutes',
			$options['reservation_cutoff_minutes'],
			0,
			1440,
			__( '枠の終了時刻を基準にします。0 なら終了時刻の直前まで予約できます（例: 10:00〜10:20 の枠で 5 にすると 10:15 まで）。', 'equiticket' )
		);
		self::number_row(
			__( '当日予約 1 件あたりの上限人数', 'equiticket' ),
			'max_people_per_reservation',
			$options['max_people_per_reservation'],
			1,
			20,
			__( '「当日予約」の画面で、1 件にまとめて登録できる人数の上限です。Web からの予約は、身長・体重で乗る馬が変わるため常に 1 件＝1 名です。', 'equiticket' )
		);
		self::number_row(
			__( '1 人あたりの体験時間（分）', 'equiticket' ),
			'ride_minutes',
			$options['ride_minutes'],
			0,
			120,
			__( '0 にすると来場者画面に表示しません。', 'equiticket' )
		);

		echo '<tr><th scope="row">' . esc_html__( '同一メールの重複予約', 'equiticket' ) . '</th><td><label>';
		echo '<input type="checkbox" name="equiticket[allow_duplicate_email]" value="1" ' . checked( (int) $options['allow_duplicate_email'], 1, false ) . ' /> ';
		echo esc_html__( '同じ時間枠に同じメールアドレスで複数予約できるようにする', 'equiticket' );
		echo '</label></td></tr>';

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'デザイン（サイトに合わせる）', 'equiticket' ) . '</h2>';
		echo '<p class="description">' . esc_html__( '来場者向けの画面を、サイトの配色・書体に合わせます。メインカラーを 1 色決めれば、ボタンや見出しの濃い色・薄い色は自動で計算します。サイトのロゴやヘッダーの色をスポイトで取ると合わせやすいです。', 'equiticket' ) . '</p>';
		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row"><label for="equiticket-theme_color">%1$s</label></th><td>'
			. '<input type="text" id="equiticket-theme_color" class="equiticket-color-field" name="equiticket[theme_color]" value="%2$s" data-default-color="#1f3a68" />'
			. '<p class="description">%3$s</p></td></tr>',
			esc_html__( 'メインカラー', 'equiticket' ),
			esc_attr( (string) $options['theme_color'] ),
			esc_html__( 'ボタン・見出し・残り人数のバッジなどに使います。', 'equiticket' )
		);

		echo '<tr><th scope="row">' . esc_html__( '角の丸み', 'equiticket' ) . '</th><td><select name="equiticket[theme_radius]">';
		foreach ( array(
			'soft'    => __( '控えめ（丸み 6px）', 'equiticket' ),
			'rounded' => __( '大きめ（丸み 12px）', 'equiticket' ),
			'square'  => __( '角ばり（丸みなし）', 'equiticket' ),
		) as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $options['theme_radius'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( '書体', 'equiticket' ) . '</th><td><label>';
		echo '<input type="checkbox" name="equiticket[theme_font_inherit]" value="1" ' . checked( (int) $options['theme_font_inherit'], 1, false ) . ' /> ';
		echo esc_html__( 'サイト（テーマ）の書体をそのまま使う', 'equiticket' );
		echo '</label><p class="description">' . esc_html__( 'チェックを外すと、読みやすさ優先のゴシック体で表示します。', 'equiticket' ) . '</p></td></tr>';

		echo '</tbody></table>';

		self::render_theme_preview( $options );

		echo '<h2>' . esc_html__( '参加条件', 'equiticket' ) . '</h2>';
		echo '<p class="description">' . sprintf(
			/* translators: %s: 馬の種類画面へのリンク */
			esc_html__( '馬ごとの条件（年齢・身長・体重）と当日の支払額は %s 画面で登録します。ここでは全体に共通の条件を決めます。', 'equiticket' ),
			'<a href="' . esc_url( EquiTicket_Admin::page_url( 'equiticket-horses' ) ) . '">' . esc_html__( '馬の種類', 'equiticket' ) . '</a>'
		) . '</p>';
		echo '<table class="form-table"><tbody>';
		self::number_row( __( '参加できる最低年齢', 'equiticket' ), 'min_age', $options['min_age'], 0, 120, __( 'これより下の年齢は、どの馬にも予約できません。', 'equiticket' ) );
		self::number_row( __( '保護者の同意が必要な年齢', 'equiticket' ), 'minor_age', $options['minor_age'], 0, 30, __( 'この年齢未満の場合、申込み画面に保護者同意のチェック欄を出します。', 'equiticket' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( '料金とキャンセルの案内', 'equiticket' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		$fee_summary = EquiTicket_Horses::fee_summary();
		echo '<tr><th scope="row">' . esc_html__( '当日のお支払い額', 'equiticket' ) . '</th><td>'
			. esc_html( '' !== $fee_summary ? $fee_summary : __( '（金額が登録されていません）', 'equiticket' ) )
			. '<p class="description">' . esc_html__( '金額は「馬の種類」画面で馬ごとに決めます。来場者が選んだ馬の金額が、申込み画面・整理券・メールに表示されます。', 'equiticket' ) . '</p></td></tr>';
		self::textarea_row( __( '支払い方法の案内', 'equiticket' ), 'payment_text', $options['payment_text'] );
		self::textarea_row( __( 'キャンセル・中止について', 'equiticket' ), 'cancel_policy_text', $options['cancel_policy_text'], __( 'キャンセルの期限（前日まで等）や荒天中止の扱いを書きます。申込み画面・整理券・メールに表示されます。', 'equiticket' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( '同意事項', 'equiticket' ) . '</h2>';
		echo '<p class="description">' . esc_html__( '申込みの最後に表示し、チェックを必須にします。{max_weight} {min_age} {minor_age} {fee} {event} は設定値に置き換わります。', 'equiticket' ) . '</p>';
		echo '<table class="form-table"><tbody>';
		self::textarea_row( __( '同意事項の本文', 'equiticket' ), 'terms_text', $options['terms_text'] );
		self::text_row( __( '同意チェックの文言', 'equiticket' ), 'consent_label', $options['consent_label'] );
		self::text_row( __( '保護者同意チェックの文言', 'equiticket' ), 'guardian_label', $options['guardian_label'] );
		self::text_row( __( '写真掲載を希望しない場合のチェック文言', 'equiticket' ), 'photo_optout_label', $options['photo_optout_label'], __( '空欄にするとチェック欄を表示しません。', 'equiticket' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'その他の案内文', 'equiticket' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		self::textarea_row( __( '注意事項（服装・持ち物など）', 'equiticket' ), 'notice_text', $options['notice_text'] );
		self::textarea_row( __( '会場案内', 'equiticket' ), 'venue_text', $options['venue_text'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'メール送信', 'equiticket' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		self::text_row( __( '送信者名', 'equiticket' ), 'mail_from_name', $options['mail_from_name'] );
		self::text_row(
			__( '送信元メールアドレス', 'equiticket' ),
			'mail_from_email',
			$options['mail_from_email'],
			__( 'サイトのドメインと同じドメインのアドレスにすると迷惑メール判定されにくくなります。空欄なら WordPress の既定値を使います。', 'equiticket' )
		);
		self::text_row(
			__( '件名', 'equiticket' ),
			'mail_subject',
			$options['mail_subject'],
			__( '使える差し込み: {event} {date} {start} {end} {name}', 'equiticket' )
		);
		self::textarea_row( __( 'メール末尾の署名', 'equiticket' ), 'mail_footer', $options['mail_footer'] );
		self::text_row(
			__( '中止のお知らせの件名', 'equiticket' ),
			'cancel_mail_subject',
			$options['cancel_mail_subject'],
			__( '時間枠を「中止」にしたときに、その枠の予約者へ送るメールの件名です。使える差し込み: {event} {date} {start} {end} {name}', 'equiticket' )
		);
		self::textarea_row(
			__( '中止のお知らせの本文', 'equiticket' ),
			'cancel_mail_text',
			$options['cancel_mail_text'],
			__( '「◯◯ 様」のあとに入る文です。このあとに日時・乗る馬・人数・受付番号と署名が自動で入ります。', 'equiticket' )
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( '個人情報の取り扱い', 'equiticket' ) . '</h2>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'プラグイン削除時', 'equiticket' ) . '</th><td><label>';
		echo '<input type="checkbox" name="equiticket[delete_data_on_uninstall]" value="1" ' . checked( (int) $options['delete_data_on_uninstall'], 1, false ) . ' /> ';
		echo esc_html__( 'プラグインを削除したときに、予約データと時間枠も完全に削除する', 'equiticket' );
		echo '</label></td></tr>';
		echo '</tbody></table>';

		submit_button( __( '設定を保存', 'equiticket' ) );
		echo '</form>';

		self::render_accounts();
		self::render_tools();

		echo '</div>';
	}

	/**
	 * いまの設定でどう見えるかの簡単な見本。
	 *
	 * @param array<string,mixed> $options 設定値。
	 * @return void
	 */
	private static function render_theme_preview( array $options ) {
		unset( $options );

		echo '<div class="equiticket equiticket-theme-preview">';
		echo '<h2 class="equiticket__title">' . esc_html__( '表示の見本', 'equiticket' ) . '</h2>';
		echo '<div class="equiticket-assignment"><p class="equiticket-assignment__type">' . esc_html__( 'ご案内する馬: ポニー', 'equiticket' ) . '</p>';
		echo '<p class="equiticket-assignment__message">' . esc_html__( '体格に合わせて、ポニー（小さめの馬）でご案内します。', 'equiticket' ) . '</p></div>';
		echo '<ul class="equiticket-slots"><li class="equiticket-slot">';
		echo '<div class="equiticket-slot__time">10:00〜10:20</div>';
		echo '<div class="equiticket-slot__info"><span class="equiticket-slot__remaining">' . esc_html__( 'ポニー 残り 3 名', 'equiticket' ) . '</span></div>';
		echo '<span class="equiticket-button">' . esc_html__( '予約する', 'equiticket' ) . '</span>';
		echo '</li></ul>';
		echo '<p class="equiticket-actions"><span class="equiticket-button equiticket-button--primary">' . esc_html__( '同意して整理券を受け取る', 'equiticket' ) . '</span></p>';
		echo '</div>';
	}

	/**
	 * 使い方とシステム状態の案内。
	 *
	 * @return void
	 */
	private static function render_usage() {
		$cron_next = wp_next_scheduled( EquiTicket_Cron::HOOK );

		echo '<div class="equiticket-panel">';
		echo '<h2>' . esc_html__( 'はじめにやること', 'equiticket' ) . '</h2>';
		echo '<p class="description">' . esc_html__( '上から順に進めてください。終わった項目には「済」が付きます。', 'equiticket' ) . '</p>';

		$has_page   = EquiTicket_Admin::has_reservation_page();
		$has_horses = (bool) EquiTicket_Horses::all( true );
		$has_slots  = (bool) EquiTicket_Slots::dates();

		echo '<ol class="equiticket-setup-steps">';

		// 1. 予約ページ。
		echo '<li>' . self::step_badge( $has_page ) . ' <strong>' . esc_html__( '予約ページを用意する', 'equiticket' ) . '</strong><br />';
		if ( $has_page ) {
			$page_id = (int) EquiTicket_Options::get( 'page_id' );
			echo esc_html__( '来場者用のページ: ', 'equiticket' ) . '<a href="' . esc_url( get_permalink( $page_id ) ) . '" target="_blank" rel="noopener">' . esc_html( get_permalink( $page_id ) ) . '</a><br />';
			echo esc_html__( 'ポスターやチラシの QR コードは、この URL にしてください。', 'equiticket' );
		} else {
			if ( current_user_can( 'publish_pages' ) ) {
				echo esc_html__( 'ボタンを押すと、来場者が予約するページを自動で作成して設定します。', 'equiticket' );
				echo EquiTicket_Admin::create_page_button(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部で個別にエスケープ済み。
			} else {
				// 運営スタッフはページを公開する権限を持たないため、ボタンは出さずに案内だけにする。
				echo esc_html__( 'ページを作成する権限がないため、サイトの管理者に「予約ページを自動で作成する」ボタンを押してもらってください。', 'equiticket' );
			}
		}
		echo '</li>';

		// 2. 馬の種類。
		echo '<li>' . self::step_badge( $has_horses ) . ' <strong>' . esc_html__( '乗れる馬の種類を確認する', 'equiticket' ) . '</strong><br />';
		echo esc_html__( '名前・年齢／身長／体重の条件・当日の支払額を登録します（最初はポニーと馬が入っています）。', 'equiticket' ) . ' ';
		echo '<a href="' . esc_url( EquiTicket_Admin::page_url( 'equiticket-horses' ) ) . '">' . esc_html__( '「馬の種類」を開く', 'equiticket' ) . '</a></li>';

		// 3. 時間枠。
		echo '<li>' . self::step_badge( $has_slots ) . ' <strong>' . esc_html__( '時間枠を登録する', 'equiticket' ) . '</strong><br />';
		echo esc_html__( '当日の時間帯と、馬ごとの頭数・定員を登録します。「一括作成」を使うと 20 分刻みなどをまとめて作れます。', 'equiticket' ) . ' ';
		echo '<a href="' . esc_url( EquiTicket_Admin::page_url( 'equiticket-slots' ) ) . '">' . esc_html__( '「時間枠」を開く', 'equiticket' ) . '</a></li>';

		// 4〜6 は自動で判定できないので、案内だけを出す。
		echo '<li><strong>' . esc_html__( 'このページの下にある文面を確認する', 'equiticket' ) . '</strong><br />';
		echo esc_html__( '「基本設定」のイベント名、「料金とキャンセルの案内」「同意事項」「メール送信」を、主催者で決めた内容に書き換えて「設定を保存」を押します。', 'equiticket' ) . '</li>';

		echo '<li><strong>' . esc_html__( 'テストメールを送る', 'equiticket' ) . '</strong><br />';
		echo esc_html__( 'このページの一番下の「運用ツール」から、自分宛てにテストメールを送り、届くか確認します（迷惑メールフォルダーも確認してください）。', 'equiticket' ) . '</li>';

		echo '<li><strong>' . esc_html__( '受付スタッフ用のアカウントを作る（必要な場合）', 'equiticket' ) . '</strong><br />';
		echo esc_html__( '下の「スタッフ用アカウント」で作れます。受付スタッフは、管理画面の「受付」ページからカメラで QR を読み取って受付します。', 'equiticket' ) . '</li>';

		echo '</ol>';

		echo '<p>' . esc_html__( '来場者の申込みは、1. 身長・体重・年齢の入力 → 2. 時間枠の選択 → 3. 氏名・メールアドレスと同意 → 4. 整理券の表示、の 4 ステップです。身長・体重で乗る馬が変わるため、1 申込み＝1 名で受け付けます。', 'equiticket' ) . '</p>';

		echo '<p>' . esc_html(
			$cron_next
				? sprintf(
					/* translators: %s: 次回実行日時 */
					__( '無断キャンセルの自動解放: 登録済み（次回 %s）', 'equiticket' ),
					wp_date( 'Y-m-d H:i', $cron_next )
				)
				: __( '無断キャンセルの自動解放: 未登録（ダッシュボードの「未来場を解放」ボタンで手動実行できます）', 'equiticket' )
		) . '</p>';
		echo '</div>';
	}

	/**
	 * 「はじめにやること」の各項目に付ける「済／未」の印を返す。
	 *
	 * @param bool $done 終わっているか。
	 * @return string HTML。
	 */
	private static function step_badge( $done ) {
		return $done
			? '<span class="equiticket-badge equiticket-badge--ok">' . esc_html__( '済', 'equiticket' ) . '</span>'
			: '<span class="equiticket-badge equiticket-badge--todo">' . esc_html__( '未', 'equiticket' ) . '</span>';
	}

	/**
	 * スタッフ用アカウントの作成と一覧。
	 *
	 * スタッフ同士で 1 つのアカウントを共有する使い方を想定している。
	 *
	 * @return void
	 */
	private static function render_accounts() {
		echo '<hr />';
		echo '<h2>' . esc_html__( 'スタッフ用アカウント', 'equiticket' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'EquiTicket の画面だけを使えるアカウントです。ログインすると当日ダッシュボードが開き、記事や設定などサイト本体の画面には入れません（開こうとしても EquiTicket に戻ります）。スタッフ同士で 1 つのアカウントを共有する使い方を想定しています。', 'equiticket' ) . '</p>';

		$users = EquiTicket_Access::list_users();
		if ( $users ) {
			echo '<table class="wp-list-table widefat striped" style="max-width:720px;"><thead><tr>';
			echo '<th>' . esc_html__( 'ユーザー名', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( 'メールアドレス', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( '種類', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( '操作', 'equiticket' ) . '</th>';
			echo '</tr></thead><tbody>';

			$roles = EquiTicket_Access::roles();
			foreach ( $users as $user ) {
				$role_key   = '';
				$user_roles = (array) $user->roles;
				foreach ( $user_roles as $candidate ) {
					if ( isset( $roles[ $candidate ] ) ) {
						$role_key = $candidate;
						break;
					}
				}

				echo '<tr>';
				echo '<td><strong>' . esc_html( $user->user_login ) . '</strong></td>';
				echo '<td>' . esc_html( $user->user_email ) . '</td>';
				echo '<td>' . esc_html( isset( $roles[ $role_key ] ) ? $roles[ $role_key ] : implode( ', ', $user_roles ) ) . '</td>';
				echo '<td>';
				if ( current_user_can( 'edit_user', $user->ID ) ) {
					printf(
						'<a href="%1$s">%2$s</a>',
						esc_url( get_edit_user_link( $user->ID ) ),
						esc_html__( 'パスワードの変更など', 'equiticket' )
					);
				} else {
					echo '&mdash;';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'まだ作成されていません。', 'equiticket' ) . '</p>';
		}

		if ( ! current_user_can( 'create_users' ) ) {
			echo '<p class="description">' . esc_html__( 'アカウントの作成はサイト管理者のみ行えます。', 'equiticket' ) . '</p>';

			return;
		}

		echo '<h3>' . esc_html__( 'アカウントを作る', 'equiticket' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="equiticket-admin-form">';
		echo '<input type="hidden" name="action" value="equiticket_create_user" />';
		wp_nonce_field( 'equiticket_create_user' );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'ユーザー名', 'equiticket' ) . '</th><td>';
		echo '<input type="text" name="user_login" class="regular-text" value="" required autocomplete="off" /> ';
		echo '<p class="description">' . esc_html__( '例: bajutsu-uketsuke。ログインに使う名前で、あとから変更できません。', 'equiticket' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'メールアドレス', 'equiticket' ) . '</th><td>';
		echo '<input type="email" name="user_email" class="regular-text" value="" required autocomplete="off" /> ';
		echo '<p class="description">' . esc_html__( 'パスワードを忘れたときの再設定に使います。団体の代表アドレスなど、共有できるものを入れてください。', 'equiticket' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'パスワード', 'equiticket' ) . '</th><td>';
		echo '<input type="text" name="user_pass" class="regular-text" value="' . esc_attr( wp_generate_password( 12, false, false ) ) . '" required autocomplete="off" /> ';
		echo '<p class="description">' . esc_html__( '8 文字以上。自動で候補を入れてあります。控えてから作成してください（作成後は表示されません）。', 'equiticket' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( '種類', 'equiticket' ) . '</th><td>';
		foreach ( EquiTicket_Access::roles() as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="radio" name="role" value="%1$s"%2$s /> %3$s</label>',
				esc_attr( $value ),
				checked( EquiTicket_Access::ROLE_STAFF, $value, false ),
				esc_html( $label )
			);
		}
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'アカウントを作成する', 'equiticket' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * 運用ツール（テストメール・データの削除）。
	 *
	 * @return void
	 */
	private static function render_tools() {
		echo '<hr />';
		echo '<h2>' . esc_html__( '運用ツール', 'equiticket' ) . '</h2>';

		echo '<h3>' . esc_html__( 'テストメールの送信', 'equiticket' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="equiticket_send_test_mail" />';
		wp_nonce_field( 'equiticket_send_test_mail' );
		echo '<input type="email" name="test_email" class="regular-text" value="' . esc_attr( wp_get_current_user()->user_email ) . '" required /> ';
		echo '<button type="submit" class="button">' . esc_html__( '送信する', 'equiticket' ) . '</button>';
		echo '</form>';

		echo '<h3>' . esc_html__( 'データの削除（リセット）', 'equiticket' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'リハーサルのあとに本番データへ入れ替えるときなどに使います。取り消せません。日付を空欄にするとすべてが対象になります。', 'equiticket' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="equiticket-danger">';
		echo '<input type="hidden" name="action" value="equiticket_clear_data" />';
		wp_nonce_field( 'equiticket_clear_data' );
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( '削除する範囲', 'equiticket' ) . '</th><td>';
		echo '<label style="display:block;margin-bottom:4px;"><input type="radio" name="scope" value="reservations" checked /> '
			. esc_html__( '予約データだけを削除する（時間枠は残す）', 'equiticket' ) . '</label>';
		echo '<label style="display:block;"><input type="radio" name="scope" value="all" /> '
			. esc_html__( '時間枠もまとめて削除する（初期化）', 'equiticket' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( '対象の日付', 'equiticket' ) . '</th><td>';
		echo '<input type="date" name="target_date" value="" /> ';
		echo '<span class="description">' . esc_html__( '空欄ならすべての日付が対象です。', 'equiticket' ) . '</span>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( '確認', 'equiticket' ) . '</th><td>';
		echo '<input type="text" name="confirm" class="regular-text" placeholder="' . esc_attr__( '削除 または 初期化', 'equiticket' ) . '" required /> ';
		echo '<p class="description">' . esc_html__( '予約だけを消す場合は「削除」、時間枠も消す場合は「初期化」と入力してください。', 'equiticket' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-secondary equiticket-danger__button" data-equiticket-confirm="'
			. esc_attr__( 'データを削除します。この操作は元に戻せません。よろしいですか？', 'equiticket' ) . '">'
			. esc_html__( 'データを削除する', 'equiticket' ) . '</button></p>';
		echo '</form>';

	}

	/**
	 * テキスト入力行。
	 *
	 * @param string $label       見出し。
	 * @param string $key         設定キー。
	 * @param string $value       現在値。
	 * @param string $description 補足説明。
	 * @return void
	 */
	private static function text_row( $label, $key, $value, $description = '' ) {
		printf(
			'<tr><th scope="row"><label for="equiticket-%1$s">%2$s</label></th><td><input type="text" id="equiticket-%1$s" name="equiticket[%1$s]" class="large-text" value="%3$s" />%4$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( (string) $value ),
			'' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}

	/**
	 * 数値入力行。
	 *
	 * @param string $label       見出し。
	 * @param string $key         設定キー。
	 * @param int    $value       現在値。
	 * @param int    $min         最小値。
	 * @param int    $max         最大値。
	 * @param string $description 補足説明。
	 * @return void
	 */
	private static function number_row( $label, $key, $value, $min, $max, $description = '' ) {
		printf(
			'<tr><th scope="row"><label for="equiticket-%1$s">%2$s</label></th><td><input type="number" id="equiticket-%1$s" name="equiticket[%1$s]" min="%3$d" max="%4$d" value="%5$d" />%6$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			(int) $min,
			(int) $max,
			(int) $value,
			'' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}

	/**
	 * 複数行入力行。
	 *
	 * @param string $label       見出し。
	 * @param string $key         設定キー。
	 * @param string $value       現在値。
	 * @param string $description 補足説明。
	 * @return void
	 */
	private static function textarea_row( $label, $key, $value, $description = '' ) {
		printf(
			'<tr><th scope="row"><label for="equiticket-%1$s">%2$s</label></th><td><textarea id="equiticket-%1$s" name="equiticket[%1$s]" rows="5" class="large-text">%3$s</textarea>%4$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_textarea( (string) $value ),
			'' !== $description ? '<p class="description">' . esc_html( $description ) . '</p>' : ''
		);
	}
}
