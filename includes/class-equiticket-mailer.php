<?php
/**
 * 予約確認メールの送信。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * wp_mail() を使って予約確認メールなどを送るクラス。
 *
 * QR 画像は添付せず、整理券（QR が表示されるページ）の URL を本文に入れる。
 */
final class EquiTicket_Mailer {

	/**
	 * 予約確認メールを送る。
	 *
	 * @param object $reservation 予約。
	 * @param object $slot        時間枠。
	 * @return bool 送信できたら true。
	 */
	public static function send_confirmation( $reservation, $slot ) {
		if ( empty( $reservation->email ) ) {
			return false;
		}

		$ticket_url = EquiTicket_Frontend::ticket_url( $reservation->qr_token );
		$subject    = self::build_subject( $reservation, $slot );
		$text_body  = self::build_text_body( $reservation, $slot, $ticket_url );

		// QR 画像は添付せず、整理券（QR が表示されるページ）の URL だけを載せる。
		$html_body = self::build_html_body( $reservation, $slot, $ticket_url );

		$alt_body = static function ( $phpmailer ) use ( $text_body ) {
			$phpmailer->AltBody = $text_body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.NotSnakeCaseMemberVar
		};

		$content_type = static function () {
			return 'text/html';
		};

		add_action( 'phpmailer_init', $alt_body );
		add_filter( 'wp_mail_content_type', $content_type );

		$sent = wp_mail( $reservation->email, $subject, $html_body, self::headers() );

		remove_filter( 'wp_mail_content_type', $content_type );
		remove_action( 'phpmailer_init', $alt_body );

		EquiTicket_Reservations::set_mail_sent_at( $reservation->id, $sent ? EquiTicket_Utils::now_for_db() : null );

		return (bool) $sent;
	}

	/**
	 * 送信テスト用のメールを送る。
	 *
	 * @param string $to 宛先。
	 * @return bool 送信できたら true。
	 */
	public static function send_test( $to ) {
		if ( ! is_email( $to ) ) {
			return false;
		}

		$body = sprintf(
			/* translators: %s: サイト名 */
			__( "これは %s の体験乗馬 整理券システムからのテストメールです。\nこのメールが届いていれば、予約確認メールも同じ経路で送信されます。", 'equiticket' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		return (bool) wp_mail( $to, __( '【テスト】体験乗馬 整理券システム', 'equiticket' ), $body, self::headers() );
	}

	/**
	 * メールヘッダーを組み立てる。
	 *
	 * @return array<int,string> ヘッダー配列。
	 */
	private static function headers() {
		$headers = array();
		$from    = (string) EquiTicket_Options::get( 'mail_from_email' );
		$name    = (string) EquiTicket_Options::get( 'mail_from_name' );

		if ( '' !== $from && is_email( $from ) ) {
			$headers[] = '' !== $name
				? sprintf( 'From: %s <%s>', $name, $from )
				: sprintf( 'From: %s', $from );
		}

		return $headers;
	}

	/**
	 * 時間枠を中止にしたことを予約者に知らせる（テキストメール）。
	 *
	 * @param object $reservation 予約。
	 * @param object $slot        時間枠。
	 * @return bool 送信に成功したら true。
	 */
	public static function send_slot_cancelled( $reservation, $slot ) {
		if ( empty( $reservation->email ) ) {
			return false;
		}

		$subject = strtr(
			(string) EquiTicket_Options::get( 'cancel_mail_subject' ),
			array(
				'{event}' => (string) EquiTicket_Options::get( 'event_name' ),
				'{date}'  => EquiTicket_Utils::date_label( $slot->event_date ),
				'{start}' => EquiTicket_Utils::time_label( $slot->start_time ),
				'{end}'   => EquiTicket_Utils::time_label( $slot->end_time ),
				'{name}'  => (string) $reservation->name,
			)
		);

		/* translators: %s: 予約者名 */
		$lines   = array( sprintf( __( '%s 様', 'equiticket' ), $reservation->name ), '' );
		$message = EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'cancel_mail_text' ) );
		if ( '' !== $message ) {
			$lines[] = $message;
			$lines[] = '';
		}
		$lines[] = __( '■ 中止になった時間枠', 'equiticket' );
		/* translators: %s: 日時 */
		$lines[] = sprintf( __( '日時: %s', 'equiticket' ), EquiTicket_Utils::slot_label( $slot ) );
		/* translators: %s: 馬の種類 */
		$lines[] = sprintf( __( '乗る馬: %s', 'equiticket' ), EquiTicket_Reservations::horse_label( $reservation ) );
		/* translators: %d: 人数 */
		$lines[] = sprintf( __( '人数: %d 名', 'equiticket' ), $reservation->people_count );
		/* translators: %s: 受付番号 */
		$lines[] = sprintf( __( '受付番号: %s', 'equiticket' ), self::ticket_number( $reservation ) );

		$footer = (string) EquiTicket_Options::get( 'mail_footer' );
		if ( '' !== $footer ) {
			$lines[] = '';
			$lines[] = $footer;
		}

		return (bool) wp_mail( $reservation->email, $subject, implode( "\n", $lines ), self::headers() );
	}

	/**
	 * 一斉メールの差し込みを、予約ごとの値に置き換える。
	 *
	 * @param string $text        件名または本文。
	 * @param object $reservation 予約。
	 * @param object $slot        時間枠。
	 * @return string 置き換え後の文。
	 */
	public static function fill_placeholders( $text, $reservation, $slot ) {
		return strtr(
			(string) $text,
			array(
				'{event}'      => (string) EquiTicket_Options::get( 'event_name' ),
				'{name}'       => (string) $reservation->name,
				'{date}'       => EquiTicket_Utils::date_label( $slot->event_date ),
				'{start}'      => EquiTicket_Utils::time_label( $slot->start_time ),
				'{end}'        => EquiTicket_Utils::time_label( $slot->end_time ),
				'{horse}'      => EquiTicket_Reservations::horse_label( $reservation ),
				'{people}'     => (string) (int) $reservation->people_count,
				'{number}'     => self::ticket_number( $reservation ),
				'{ticket_url}' => EquiTicket_Frontend::ticket_url( $reservation->qr_token ),
			)
		);
	}

	/**
	 * 一斉メールを 1 件送る（テキストメール。署名は設定の「メール末尾の署名」）。
	 *
	 * @param string $to          送信先。
	 * @param object $reservation 差し込みに使う予約。
	 * @param object $slot        予約の時間枠。
	 * @param string $subject     件名（差し込み可）。
	 * @param string $body        本文（差し込み可）。
	 * @return bool 送信に成功したら true。
	 */
	public static function send_bulk( $to, $reservation, $slot, $subject, $body ) {
		if ( '' === (string) $to ) {
			return false;
		}

		$text   = self::fill_placeholders( $body, $reservation, $slot );
		// 本文に署名をすでに書いている場合は、設定の署名を重ねて付けない。
		$footer = trim( (string) EquiTicket_Options::get( 'mail_footer' ) );
		if ( '' !== $footer && false === strpos( $text, $footer ) ) {
			$text .= "\n\n" . $footer;
		}

		return (bool) wp_mail( $to, self::fill_placeholders( $subject, $reservation, $slot ), $text, self::headers() );
	}

	/**
	 * 件名を組み立てる。
	 *
	 * @param object $reservation 予約。
	 * @param object $slot        時間枠。
	 * @return string 件名。
	 */
	private static function build_subject( $reservation, $slot ) {
		$template = (string) EquiTicket_Options::get( 'mail_subject' );

		return strtr(
			$template,
			array(
				'{event}' => (string) EquiTicket_Options::get( 'event_name' ),
				'{date}'  => EquiTicket_Utils::date_label( $slot->event_date ),
				'{start}' => EquiTicket_Utils::time_label( $slot->start_time ),
				'{end}'   => EquiTicket_Utils::time_label( $slot->end_time ),
				'{name}'  => (string) $reservation->name,
			)
		);
	}

	/**
	 * テキスト版の本文を組み立てる。
	 *
	 * @param object $reservation 予約。
	 * @param object $slot        時間枠。
	 * @param string $ticket_url  整理券（確認）URL。
	 * @return string 本文。
	 */
	private static function build_text_body( $reservation, $slot, $ticket_url ) {
		$lines = array();

		$lines[] = sprintf(
			/* translators: %s: 予約者名 */
			__( '%s 様', 'equiticket' ),
			$reservation->name
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: イベント名 */
			__( '%s の整理券（事前予約）をお受けしました。', 'equiticket' ),
			(string) EquiTicket_Options::get( 'event_name' )
		);
		$lines[] = '';
		$lines[] = __( '■ ご予約内容', 'equiticket' );
		$lines[] = sprintf( __( '受付番号: %s', 'equiticket' ), self::ticket_number( $reservation ) );
		$lines[] = sprintf( __( '日時: %s', 'equiticket' ), EquiTicket_Utils::slot_label( $slot ) );
		$lines[] = sprintf( __( '乗る馬: %s', 'equiticket' ), EquiTicket_Reservations::horse_label( $reservation ) );
		$lines[] = sprintf( __( '人数: %d 名', 'equiticket' ), $reservation->people_count );

		if ( '' !== (string) $reservation->fee_label ) {
			$lines[] = sprintf( __( '当日のお支払い: %s', 'equiticket' ), $reservation->fee_label );
		}

		$payment = EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'payment_text' ) );
		if ( '' !== $payment ) {
			$lines[] = $payment;
		}

		$lines[] = '';
		$lines[] = __( '■ 当日の受付', 'equiticket' );
		$lines[] = __( '下記の URL を開くと整理券（QR コード）が表示されます。受付でご提示ください。', 'equiticket' );
		$lines[] = $ticket_url;
		$lines[] = '';
		$lines[] = __( '※ 会場の通信状況によっては表示に時間がかかることがあります。事前にスクリーンショットの保存をおすすめします。', 'equiticket' );

		$venue = EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'venue_text' ) );
		if ( '' !== $venue ) {
			$lines[] = '';
			$lines[] = __( '■ 会場', 'equiticket' );
			$lines[] = $venue;
		}

		$notice = EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'notice_text' ) );
		if ( '' !== $notice ) {
			$lines[] = '';
			$lines[] = __( '■ 注意事項', 'equiticket' );
			$lines[] = $notice;
		}

		$cancel = EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'cancel_policy_text' ) );
		if ( '' !== $cancel ) {
			$lines[] = '';
			$lines[] = __( '■ キャンセル・中止について', 'equiticket' );
			$lines[] = $cancel;
		}

		$terms = EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'terms_text' ) );
		if ( '' !== $terms ) {
			$lines[] = '';
			$lines[] = __( '■ 同意いただいた事項', 'equiticket' );
			$lines[] = $terms;
		}

		$footer = (string) EquiTicket_Options::get( 'mail_footer' );
		if ( '' !== $footer ) {
			$lines[] = '';
			$lines[] = $footer;
		}

		return implode( "\n", $lines );
	}

	/**
	 * HTML 版の本文を組み立てる。
	 *
	 * @param object $reservation 予約。
	 * @param object $slot        時間枠。
	 * @param string $ticket_url  整理券（確認）URL。
	 * @return string 本文（HTML）。
	 */
	private static function build_html_body( $reservation, $slot, $ticket_url ) {
		$rows = array(
			__( '受付番号', 'equiticket' ) => self::ticket_number( $reservation ),
			__( '日時', 'equiticket' )     => EquiTicket_Utils::slot_label( $slot ),
			__( '乗る馬', 'equiticket' )   => EquiTicket_Reservations::horse_label( $reservation ),
			__( '人数', 'equiticket' )     => sprintf( __( '%d 名', 'equiticket' ), $reservation->people_count ),
		);

		if ( '' !== (string) $reservation->fee_label ) {
			$rows[ __( '当日のお支払い', 'equiticket' ) ] = $reservation->fee_label;
		}

		$html  = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Hiragino Sans\',\'Noto Sans JP\',sans-serif;font-size:15px;line-height:1.7;color:#222;">';
		$html .= '<p>' . esc_html( sprintf( __( '%s 様', 'equiticket' ), $reservation->name ) ) . '</p>';
		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: %s: イベント名 */
				__( '%s の整理券（事前予約）をお受けしました。', 'equiticket' ),
				(string) EquiTicket_Options::get( 'event_name' )
			)
		) . '</p>';

		$html .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:16px 0;">';
		foreach ( $rows as $label => $value ) {
			$html .= sprintf(
				'<tr><th align="left" style="background:#f3f4f6;border:1px solid #e5e7eb;white-space:nowrap;">%s</th><td style="border:1px solid #e5e7eb;">%s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}
		$html .= '</table>';

		$html .= '<p style="margin-top:16px;">' . esc_html__( '下のリンクを開くと整理券（QR コード）が表示されます。当日は受付でご提示ください。', 'equiticket' ) . '<br />';
		$html .= sprintf( '<a href="%1$s">%1$s</a></p>', esc_url( $ticket_url ) );
		$html .= '<p style="color:#666;">' . esc_html__( '※ 会場の通信状況によっては表示に時間がかかることがあります。事前にスクリーンショットの保存をおすすめします。', 'equiticket' ) . '</p>';

		$blocks = array(
			__( '当日のお支払い', 'equiticket' )         => EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'payment_text' ) ),
			__( '会場', 'equiticket' )                   => EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'venue_text' ) ),
			__( '注意事項', 'equiticket' )               => EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'notice_text' ) ),
			__( 'キャンセル・中止について', 'equiticket' ) => EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'cancel_policy_text' ) ),
			__( '同意いただいた事項', 'equiticket' )     => EquiTicket_Options::render_text( (string) EquiTicket_Options::get( 'terms_text' ) ),
		);
		foreach ( $blocks as $heading => $text ) {
			if ( '' === $text ) {
				continue;
			}
			$html .= '<h3 style="font-size:15px;margin:20px 0 4px;">' . esc_html( $heading ) . '</h3>';
			$html .= '<div style="white-space:pre-wrap;">' . esc_html( $text ) . '</div>';
		}

		$footer = (string) EquiTicket_Options::get( 'mail_footer' );
		if ( '' !== $footer ) {
			$html .= '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;" />';
			$html .= '<div style="white-space:pre-wrap;color:#666;font-size:13px;">' . esc_html( $footer ) . '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * 受付番号（表示用）を作る。
	 *
	 * @param object $reservation 予約。
	 * @return string 受付番号。
	 */
	public static function ticket_number( $reservation ) {
		return sprintf( 'No.%04d', (int) $reservation->id );
	}
}
