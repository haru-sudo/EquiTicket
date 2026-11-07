<?php
/**
 * 一斉メールの画面。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 予約済みの人に、まとめてメールを送る管理ページ。
 *
 * 前日のリマインダー（「来られなくなったら取り消してください」というお願い）などに使う。
 * 宛先は日付・時間枠・馬で絞り込み、件名と本文には予約ごとの差し込みが使える。
 */
final class EquiTicket_Admin_Mail {

	/**
	 * 送る前に一覧で見せる宛先の上限（画面が長くなりすぎないように）。
	 */
	const PREVIEW_LIMIT = 200;

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_equiticket_bulk_mail', array( __CLASS__, 'handle_send' ) );
	}

	/**
	 * 権限を確認する。
	 *
	 * @return void
	 */
	private static function require_cap() {
		if ( ! EquiTicket_Utils::can_manage() ) {
			wp_die( esc_html__( '一斉メールを送る権限がありません。', 'equiticket' ), 403 );
		}
	}

	/**
	 * 件名の初期値。
	 *
	 * @return string 件名。
	 */
	public static function default_subject() {
		return __( '【{event}】ご予約のキャンセルについてのお願い（{date} {start}〜）', 'equiticket' );
	}

	/**
	 * 本文の初期値（無断キャンセルを減らすためのお願い）。
	 *
	 * @return string 本文。
	 */
	public static function default_body() {
		return implode(
			"\n",
			array(
				__( '{name} 様', 'equiticket' ),
				'',
				__( '{event}の整理券（事前予約）をお申し込みいただき、ありがとうございます。', 'equiticket' ),
				__( '{event}の運営スタッフです。', 'equiticket' ),
				'',
				__( '現在、ご連絡のないまま当日お越しにならない「無断キャンセル」が多く見受けられます。', 'equiticket' ),
				__( '体験乗馬は予約枠ごとに馬とスタッフを用意しており、枠数にも限りがあります。', 'equiticket' ),
				__( 'キャンセルいただいた枠は、体験を希望されている他の方にご案内いたします。', 'equiticket' ),
				'',
				__( 'ご都合が悪くなった場合は、当日でも、以下のリンクから「予約を取り消す」を押してください。', 'equiticket' ),
				'{ticket_url}',
				__( '整理券のリンクは、お申し込み時にお送りした「体験乗馬 整理券のご案内」メールに記載しています。', 'equiticket' ),
				'',
				__( 'ご理解とご協力をよろしくお願いいたします。', 'equiticket' ),
				__( '当日、馬たちと一緒にお待ちしております。', 'equiticket' ),
				'',
				__( '{event} 運営スタッフ', 'equiticket' ),
			)
		);
	}

	/**
	 * 宛先の絞り込み条件を読み取る（GET の表示条件、POST の送信条件で共通）。
	 *
	 * @param array<string,mixed> $source $_GET または $_POST。
	 * @return array<string,mixed> 条件（date / slot_id / horse_id）。
	 */
	private static function read_filters( array $source ) {
		return array(
			'date'     => isset( $source['date'] ) ? EquiTicket_Utils::sanitize_date( wp_unslash( $source['date'] ) ) : '',
			'slot_id'  => isset( $source['slot_id'] ) ? (int) $source['slot_id'] : 0,
			'horse_id' => isset( $source['horse_id'] ) ? (int) $source['horse_id'] : 0,
		);
	}

	/**
	 * 条件に合う宛先（予約済みで、メールアドレスのある予約）。
	 *
	 * @param array<string,mixed> $filters 条件。
	 * @return array<int,object> 予約。
	 */
	public static function recipients( array $filters ) {
		$args = array( 'statuses' => array( 'reserved' ) );
		if ( '' !== $filters['date'] ) {
			$args['date'] = $filters['date'];
		}
		if ( $filters['slot_id'] > 0 ) {
			$args['slot_id'] = $filters['slot_id'];
		}

		return array_values(
			array_filter(
				EquiTicket_Reservations::query( $args ),
				static function ( $reservation ) use ( $filters ) {
					// 中止した枠の予約者には、中止のお知らせ（時間枠の画面）で知らせるので送らない。
					if ( '' === (string) $reservation->email || 'cancelled' === $reservation->slot_status ) {
						return false;
					}

					return ! $filters['horse_id'] || (int) $reservation->horse_id === (int) $filters['horse_id'];
				}
			)
		);
	}

	/**
	 * 一斉メールを送る（または自分宛てにテスト送信する）。
	 *
	 * @return void
	 */
	public static function handle_send() {
		self::require_cap();
		check_admin_referer( 'equiticket_bulk_mail' );

		$filters = self::read_filters( $_POST );
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		$test    = ! empty( $_POST['send_test'] );
		$back    = EquiTicket_Admin::page_url( 'equiticket-mail', array_filter( $filters ) );

		// 送信後や入力ミスで戻ったときに、書いた件名と本文が消えないようにする。
		set_transient(
			'equiticket_bulk_mail_draft_' . get_current_user_id(),
			array(
				'subject' => $subject,
				'body'    => $body,
			),
			DAY_IN_SECONDS
		);

		if ( '' === $subject || '' === $body ) {
			EquiTicket_Admin::add_notice( __( '件名と本文を入力してください。', 'equiticket' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		$recipients = self::recipients( $filters );
		if ( ! $recipients ) {
			EquiTicket_Admin::add_notice( __( '条件に合う宛先がありません。', 'equiticket' ), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		if ( $test ) {
			// 1 件目の予約の内容で差し込みをして、ログイン中の自分に送る。
			$first = $recipients[0];
			$slot  = EquiTicket_Slots::get( $first->slot_id );
			$me    = wp_get_current_user();
			$sent  = $slot && EquiTicket_Mailer::send_bulk( $me->user_email, $first, $slot, '[テスト] ' . $subject, $body );

			EquiTicket_Admin::add_notice(
				$sent
					/* translators: %s: 自分のメールアドレス */
					? sprintf( __( '%s にテストメールを送りました（差し込みは 1 件目の宛先の内容です）。届いた内容を確認してから送信してください。', 'equiticket' ), $me->user_email )
					: __( 'テストメールを送れませんでした。設定画面の「テストメールの送信」で送信できるか確認してください。', 'equiticket' ),
				$sent ? 'success' : 'error'
			);
			wp_safe_redirect( $back );
			exit;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- 共用サーバーで無効にされている場合があるため。
		}

		$slots  = array();
		$sent   = 0;
		$failed = 0;
		foreach ( $recipients as $reservation ) {
			if ( ! isset( $slots[ $reservation->slot_id ] ) ) {
				$slots[ $reservation->slot_id ] = EquiTicket_Slots::get( $reservation->slot_id );
			}
			$slot = $slots[ $reservation->slot_id ];
			if ( $slot && EquiTicket_Mailer::send_bulk( $reservation->email, $reservation, $slot, $subject, $body ) ) {
				$sent++;
			} else {
				$failed++;
			}
		}

		EquiTicket_Admin::add_notice(
			sprintf(
				/* translators: %d: 送信した件数 */
				__( '一斉メールを %d 件送りました。', 'equiticket' ),
				$sent
			),
			$failed ? 'warning' : 'success'
		);
		if ( $failed ) {
			EquiTicket_Admin::add_notice(
				sprintf(
					/* translators: %d: 送れなかった件数 */
					__( '%d 件は送れませんでした。', 'equiticket' ),
					$failed
				),
				'error'
			);
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * 画面を出力する。
	 *
	 * @return void
	 */
	public static function render() {
		self::require_cap();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示条件の読み取りのみ。
		$filters    = self::read_filters( $_GET );
		$reset      = ! empty( $_GET['reset'] );
		// phpcs:enable
		$recipients = self::recipients( $filters );
		$draft      = $reset ? null : get_transient( 'equiticket_bulk_mail_draft_' . get_current_user_id() );
		$subject    = is_array( $draft ) && '' !== $draft['subject'] ? $draft['subject'] : self::default_subject();
		$body       = is_array( $draft ) && '' !== $draft['body'] ? $draft['body'] : self::default_body();

		echo '<div class="wrap equiticket-wrap">';
		echo '<h1>' . esc_html__( '一斉メール', 'equiticket' ) . '</h1>';
		echo '<p class="description">' . esc_html__( '予約済みの人（まだ来場していない人）に、まとめてメールを送ります。前日に「来られなくなったら取り消してください」とお願いしておくと、無断キャンセルを減らせます。来場済み・取消済みの人と、メールアドレスのない当日予約には送りません。', 'equiticket' ) . '</p>';

		self::render_filters( $filters );
		self::render_recipients( $recipients );
		self::render_compose( $filters, $recipients, $subject, $body );

		echo '</div>';
	}

	/**
	 * 宛先の絞り込み（日付・時間枠・馬）。
	 *
	 * @param array<string,mixed> $filters 条件。
	 * @return void
	 */
	private static function render_filters( array $filters ) {
		echo '<h2>' . esc_html__( '1. 宛先を選ぶ', 'equiticket' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="equiticket-toolbar">';
		echo '<input type="hidden" name="page" value="equiticket-mail" />';

		echo '<label>' . esc_html__( '日付', 'equiticket' ) . ' <select name="date" onchange="this.form.slot_id.value=\'0\';this.form.submit();">';
		echo '<option value="">' . esc_html__( 'すべての日', 'equiticket' ) . '</option>';
		foreach ( EquiTicket_Slots::dates() as $date ) {
			echo '<option value="' . esc_attr( $date ) . '"' . selected( $filters['date'], $date, false ) . '>' . esc_html( EquiTicket_Utils::date_label( $date ) ) . '</option>';
		}
		echo '</select></label> ';

		echo '<label>' . esc_html__( '時間枠', 'equiticket' ) . ' <select name="slot_id">';
		echo '<option value="0">' . esc_html__( 'すべての枠', 'equiticket' ) . '</option>';
		if ( '' !== $filters['date'] ) {
			foreach ( EquiTicket_Slots::query( array( 'date' => $filters['date'] ) ) as $slot ) {
				echo '<option value="' . esc_attr( (string) $slot->id ) . '"' . selected( $filters['slot_id'], $slot->id, false ) . '>'
					. esc_html( EquiTicket_Utils::time_label( $slot->start_time ) . '〜' . EquiTicket_Utils::time_label( $slot->end_time ) ) . '</option>';
			}
		}
		echo '</select></label> ';

		echo '<label>' . esc_html__( '馬', 'equiticket' ) . ' <select name="horse_id">';
		echo '<option value="0">' . esc_html__( 'すべての馬', 'equiticket' ) . '</option>';
		foreach ( EquiTicket_Horses::all() as $horse ) {
			echo '<option value="' . esc_attr( (string) $horse->id ) . '"' . selected( $filters['horse_id'], $horse->id, false ) . '>' . esc_html( $horse->name ) . '</option>';
		}
		echo '</select></label> ';

		echo '<button type="submit" class="button">' . esc_html__( '絞り込む', 'equiticket' ) . '</button>';
		echo '</form>';
		if ( '' === $filters['date'] ) {
			echo '<p class="description">' . esc_html__( '時間枠で絞り込むときは、先に日付を選んでください。', 'equiticket' ) . '</p>';
		}
	}

	/**
	 * 宛先の一覧。
	 *
	 * @param array<int,object> $recipients 宛先の予約。
	 * @return void
	 */
	private static function render_recipients( array $recipients ) {
		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: %d: 宛先の件数 */
				__( '宛先: %d 件', 'equiticket' ),
				count( $recipients )
			)
		) . '</strong></p>';

		if ( ! $recipients ) {
			echo '<p class="equiticket-empty">' . esc_html__( '条件に合う予約済みの人はいません。', 'equiticket' ) . '</p>';

			return;
		}

		echo '<details' . ( count( $recipients ) <= 10 ? ' open' : '' ) . '><summary>' . esc_html__( '宛先の一覧を見る', 'equiticket' ) . '</summary>';
		echo '<table class="wp-list-table widefat striped" style="max-width:60em;"><thead><tr>';
		echo '<th>' . esc_html__( '日時', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( 'お名前', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '乗る馬', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( 'メールアドレス', 'equiticket' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $recipients, 0, self::PREVIEW_LIMIT ) as $reservation ) {
			echo '<tr>';
			echo '<td>' . esc_html( EquiTicket_Utils::date_label( $reservation->event_date ) . ' ' . EquiTicket_Utils::time_label( $reservation->start_time ) ) . '</td>';
			echo '<td>' . esc_html( $reservation->name ) . '</td>';
			echo '<td>' . esc_html( EquiTicket_Reservations::horse_label( $reservation ) ) . '</td>';
			echo '<td>' . esc_html( $reservation->email ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( count( $recipients ) > self::PREVIEW_LIMIT ) {
			/* translators: %d: 一覧に出している件数 */
			echo '<p class="description">' . esc_html( sprintf( __( '一覧には最初の %d 件だけを表示しています（送信はすべての宛先に行います）。', 'equiticket' ), self::PREVIEW_LIMIT ) ) . '</p>';
		}
		echo '</details>';
	}

	/**
	 * 件名・本文の入力と送信ボタン。
	 *
	 * @param array<string,mixed> $filters    条件。
	 * @param array<int,object>   $recipients 宛先。
	 * @param string              $subject    件名。
	 * @param string              $body       本文。
	 * @return void
	 */
	private static function render_compose( array $filters, array $recipients, $subject, $body ) {
		echo '<h2>' . esc_html__( '2. 文面を確認して送る', 'equiticket' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="equiticket-admin-form">';
		echo '<input type="hidden" name="action" value="equiticket_bulk_mail" />';
		foreach ( $filters as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		wp_nonce_field( 'equiticket_bulk_mail' );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="equiticket-mail-subject">' . esc_html__( '件名', 'equiticket' ) . '</label></th><td>';
		echo '<input type="text" id="equiticket-mail-subject" name="subject" class="large-text" value="' . esc_attr( $subject ) . '" required /></td></tr>';
		echo '<tr><th scope="row"><label for="equiticket-mail-body">' . esc_html__( '本文', 'equiticket' ) . '</label></th><td>';
		echo '<textarea id="equiticket-mail-body" name="body" class="large-text" rows="18" required>' . esc_textarea( $body ) . '</textarea>';
		echo '<p class="description">' . esc_html__( '予約ごとに置き換わる差し込み: {name}（お名前） {date}（日付） {start} {end}（時刻） {horse}（乗る馬） {people}（人数） {number}（受付番号） {ticket_url}（整理券の URL。ここから取り消せます） {event}（イベント名）。設定の「メール末尾の署名」は、本文に同じ文が書かれていなければ最後に付きます。', 'equiticket' ) . '</p>';
		echo '<p><a href="' . esc_url( EquiTicket_Admin::page_url( 'equiticket-mail', array_merge( array_filter( $filters ), array( 'reset' => 1 ) ) ) ) . '">'
			. esc_html__( '最初の文面に戻す', 'equiticket' ) . '</a></p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		$disabled = $recipients ? '' : ' disabled';
		echo '<p>';
		echo '<button type="submit" name="send_test" value="1" class="button"' . $disabled . '>' . esc_html__( '自分宛てにテスト送信', 'equiticket' ) . '</button> ';
		echo '<button type="submit" class="button button-primary"' . $disabled . ' data-equiticket-confirm="' . esc_attr(
			sprintf(
				/* translators: %d: 宛先の件数 */
				__( '%d 件の宛先に一斉メールを送ります。送信すると取り消せません。よろしいですか？', 'equiticket' ),
				count( $recipients )
			)
		) . '">' . esc_html(
			sprintf(
				/* translators: %d: 宛先の件数 */
				__( '%d 件に送信する', 'equiticket' ),
				count( $recipients )
			)
		) . '</button>';
		echo '</p>';
		echo '</form>';
	}
}
