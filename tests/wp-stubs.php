<?php
/**
 * テスト用の最小限の WordPress 関数スタブ。
 *
 * WordPress を読み込まずに、純粋な計算処理（QR 生成・入力値の検証）だけを
 * 単体で確かめるために用意している。プラグイン本体では使わない。
 *
 * @package EquiTicket
 */

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * サイトのタイムゾーン（テストでは Asia/Tokyo 固定）。
	 *
	 * @return DateTimeZone タイムゾーン。
	 */
	function wp_timezone() {
		return new DateTimeZone( 'Asia/Tokyo' );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * 翻訳関数のスタブ。
	 *
	 * @param string $text   文字列。
	 * @param string $domain テキストドメイン。
	 * @return string そのままの文字列。
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * 日時整形のスタブ。
	 *
	 * @param string   $format    フォーマット。
	 * @param int|null $timestamp タイムスタンプ。
	 * @return string 整形済み文字列。
	 */
	function wp_date( $format, $timestamp = null ) {
		return gmdate( $format, null === $timestamp ? time() : $timestamp );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * URL 解析のスタブ。
	 *
	 * @param string $url       URL。
	 * @param int    $component 取り出す要素。
	 * @return mixed 解析結果。
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * 権限判定のスタブ。
	 *
	 * @param string $capability ケイパビリティ。
	 * @return bool 常に false。
	 */
	function current_user_can( $capability ) {
		unset( $capability );

		return false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON エンコードのスタブ。
	 *
	 * @param mixed $data データ。
	 * @return string JSON。
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * WP_Error の最小限のスタブ。
	 */
	class WP_Error {

		/**
		 * エラーコード。
		 *
		 * @var string
		 */
		public $code;

		/**
		 * エラーメッセージ。
		 *
		 * @var string
		 */
		public $message;

		/**
		 * コンストラクタ。
		 *
		 * @param string $code    エラーコード。
		 * @param string $message メッセージ。
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * エラーコードを返す。
		 *
		 * @return string コード。
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * メッセージを返す。
		 *
		 * @return string メッセージ。
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * WP_Error かどうかを判定するスタブ。
	 *
	 * @param mixed $thing 判定する値。
	 * @return bool WP_Error なら true。
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * 数値整形のスタブ。
	 *
	 * @param float $number   数値。
	 * @param int   $decimals 小数点以下の桁数。
	 * @return string 整形済み文字列。
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( $number, $decimals );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * 1 行テキストの簡易サニタイズ（タグと改行を除き、前後の空白を落とす）。
	 *
	 * @param string $text 入力。
	 * @return string 結果。
	 */
	function sanitize_text_field( $text ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
	}
}
