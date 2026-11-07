<?php
/**
 * 共通ユーティリティ。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 日時整形・入力値検証などの小さなヘルパー群。
 */
final class EquiTicket_Utils {

	/**
	 * 管理権限（時間枠・設定の操作）のケイパビリティ。
	 */
	const CAP_MANAGE = 'equiticket_manage';

	/**
	 * 受付権限（チェックイン操作）のケイパビリティ。
	 */
	const CAP_CHECKIN = 'equiticket_checkin';

	/**
	 * サイトのタイムゾーンでの現在時刻を返す。
	 *
	 * @return DateTimeImmutable 現在時刻。
	 */
	public static function now() {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * サイトのタイムゾーンでの「今日」の日付（Y-m-d）。
	 *
	 * @return string 日付。
	 */
	public static function today() {
		return self::now()->format( 'Y-m-d' );
	}

	/**
	 * DB 保存用の現在日時（Y-m-d H:i:s、サイトのタイムゾーン）。
	 *
	 * @return string 日時。
	 */
	public static function now_for_db() {
		return self::now()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * 日付文字列を検証して Y-m-d 形式に正規化する。
	 *
	 * @param string $value 入力値。
	 * @return string 正しければ Y-m-d、不正なら空文字。
	 */
	public static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * 時刻文字列を検証して H:i:s 形式に正規化する。
	 *
	 * @param string $value 入力値（H:i または H:i:s）。
	 * @return string 正しければ H:i:s、不正なら空文字。
	 */
	public static function sanitize_time( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])(:([0-5][0-9]))?$/', $value, $matches ) ) {
			return sprintf( '%s:%s:%s', $matches[1], $matches[2], isset( $matches[4] ) ? $matches[4] : '00' );
		}

		return '';
	}

	/**
	 * 日付と時刻から DateTimeImmutable を作る。
	 *
	 * @param string $date 日付（Y-m-d）。
	 * @param string $time 時刻（H:i:s）。
	 * @return DateTimeImmutable|null 日時。作れない場合は null。
	 */
	public static function make_datetime( $date, $time ) {
		$datetime = DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s',
			$date . ' ' . $time,
			wp_timezone()
		);

		return $datetime ? $datetime : null;
	}

	/**
	 * 時刻を H:i で表示する。
	 *
	 * @param string $time 時刻（H:i:s）。
	 * @return string 表示用文字列。
	 */
	public static function time_label( $time ) {
		return substr( (string) $time, 0, 5 );
	}

	/**
	 * 日付を「9月19日（土）」形式で表示する。
	 *
	 * @param string $date 日付（Y-m-d）。
	 * @return string 表示用文字列。
	 */
	public static function date_label( $date ) {
		$datetime = self::make_datetime( $date, '00:00:00' );
		if ( ! $datetime ) {
			return (string) $date;
		}

		return wp_date( 'n月j日(D)', $datetime->getTimestamp() );
	}

	/**
	 * 時間枠の見出し（日付＋時間帯）を返す。
	 *
	 * @param object $slot 時間枠。
	 * @return string 表示用文字列。
	 */
	public static function slot_label( $slot ) {
		return sprintf(
			'%s %s〜%s',
			self::date_label( $slot->event_date ),
			self::time_label( $slot->start_time ),
			self::time_label( $slot->end_time )
		);
	}

	/**
	 * 数値を範囲内に収める。
	 *
	 * @param mixed $value   値。
	 * @param int   $min     最小値。
	 * @param int   $max     最大値。
	 * @param int   $default 既定値。
	 * @return int 範囲内の整数。
	 */
	public static function clamp_int( $value, $min, $max, $default = 0 ) {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		$value = (int) $value;

		return max( $min, min( $max, $value ) );
	}

	/**
	 * 予約ステータスの表示ラベル。
	 *
	 * @param string $status ステータス。
	 * @return string ラベル。
	 */
	public static function status_label( $status ) {
		$labels = array(
			'reserved'   => __( '予約済み', 'equiticket' ),
			'checked_in' => __( '来場済み', 'equiticket' ),
			'cancelled'  => __( '自己キャンセル', 'equiticket' ),
			'no_show'    => __( '無断キャンセル', 'equiticket' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * 時間枠ステータスの表示ラベル。
	 *
	 * @param string $status ステータス。
	 * @return string ラベル。
	 */
	public static function slot_status_label( $status ) {
		$labels = array(
			'open'      => __( '受付中', 'equiticket' ),
			'closed'    => __( '受付停止', 'equiticket' ),
			'cancelled' => __( '中止', 'equiticket' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * 現在のユーザーが受付操作を行えるか。
	 *
	 * @return bool 行えるなら true。
	 */
	public static function can_check_in() {
		return current_user_can( self::CAP_CHECKIN ) || current_user_can( self::CAP_MANAGE );
	}

	/**
	 * 現在のユーザーが管理操作を行えるか。
	 *
	 * @return bool 行えるなら true。
	 */
	public static function can_manage() {
		return current_user_can( self::CAP_MANAGE );
	}

	/**
	 * トークンとして妥当な文字列かどうか。
	 *
	 * @param string $token トークン。
	 * @return string 妥当なら正規化したトークン、そうでなければ空文字。
	 */
	public static function sanitize_token( $token ) {
		$token = trim( (string) $token );

		// QR を読み取った URL がそのまま貼り付けられた場合にも対応する。
		if ( false !== strpos( $token, 'et_token=' ) ) {
			$query = wp_parse_url( $token, PHP_URL_QUERY );
			if ( $query ) {
				parse_str( $query, $vars );
				// et_token[]=… のような配列は受け付けない（PHP 8 で preg_match が例外になるため）。
				if ( isset( $vars['et_token'] ) && is_string( $vars['et_token'] ) ) {
					$token = $vars['et_token'];
				}
			}
		}

		return preg_match( '/^[A-Za-z0-9]{16,64}$/', $token ) ? $token : '';
	}

	/**
	 * CSV の 1 セルを、表計算ソフトで式として動かないようにする。
	 *
	 * 来場者が入力した名前などが = + - @ やタブ・改行で始まると、Excel などで開いたときに
	 * 式として実行されてしまうため、先頭に ' を付けて文字列として扱わせる。
	 *
	 * @param mixed $value セルの値。
	 * @return mixed 文字列なら無害化した値、それ以外はそのまま。
	 */
	public static function csv_cell( $value ) {
		if ( is_string( $value ) && '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
