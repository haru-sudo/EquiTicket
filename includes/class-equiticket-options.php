<?php
/**
 * 設定値の読み書き。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * イベントごとに異なる値を、コードを触らずに管理画面から変えられるように、
 * 参加条件・締切・メール文面などをすべて設定値として持たせる。
 */
final class EquiTicket_Options {

	/**
	 * オプション名。
	 */
	const OPTION = 'equiticket_settings';

	/**
	 * 既定値。
	 *
	 * @return array<string,mixed> 既定の設定値。
	 */
	public static function defaults() {
		return array(
			'event_name'                 => '体験乗馬コーナー',
			'page_id'                    => 0,
			'no_show_minutes'            => 10,
			'reservation_cutoff_minutes' => 0,
			'max_people_per_reservation' => 4,
			'allow_duplicate_email'      => 1,
			'ride_minutes'               => 0,

			// 全体の参加条件。馬ごとの条件と支払額は「馬の種類」画面で登録する。
			'min_age'                    => 5,
			'minor_age'                  => 18,

			// 料金とキャンセルの案内。
			'payment_text'               => '当日、受付で現金をお支払いください。おつりのないようご協力をお願いします。',
			'cancel_policy_text'         => "キャンセルは前日までに、整理券の画面またはメールのリンクからお願いします。\n当日やむを得ずキャンセルされる場合は、会場のスタッフまでお声がけください。\n荒天時や馬の体調不良により、やむを得ず中止する場合があります。",

			'notice_text'                => "・ヘルメット（貸出あり）を必ず着用していただきます。\n・長ズボンと、かかとのある靴でお越しください。\n・馬の体調により、頭数や時間が変更になる場合があります。",
			'terms_text'                 => self::default_terms_text(),
			'consent_label'              => '上記すべてに同意します',
			'guardian_label'             => '18 歳未満の参加者について、保護者の同意を得ています',
			'photo_optout_label'         => '写真・動画の掲載を希望しません',

			'venue_text'                 => '',
			'mail_from_name'             => '',
			'mail_from_email'            => '',
			'mail_subject'               => '【{event}】体験乗馬 整理券のご案内（{date} {start}〜）',
			'mail_footer'                => '',
			'cancel_mail_subject'        => '【中止のお知らせ】{event}（{date} {start}〜）',
			'cancel_mail_text'           => "誠に申し訳ありませんが、荒天や馬の体調などの理由により、ご予約の時間枠を中止することになりました。\n楽しみにしてくださっていたところ、ご迷惑をおかけしますことをお詫び申し上げます。",
			// 見た目をサイトに合わせるための設定。
			'theme_color'                => '#1f3a68',
			'theme_radius'               => 'soft',
			'theme_font_inherit'         => 1,

			'delete_data_on_uninstall'   => 0,
		);
	}

	/**
	 * 同意事項の既定文面。
	 *
	 * {max_weight} {min_age} {minor_age} {fee} は表示時に設定値へ置き換わる。
	 *
	 * @return string 同意事項。
	 */
	public static function default_terms_text() {
		return implode(
			"\n",
			array(
				'1. 危険性の理解',
				'馬は生き物であり、周囲の音や動きに驚いて予測できない行動をとることがあります。落馬・転倒・踏まれる・噛まれる等により負傷する可能性があることを理解しています。',
				'',
				'2. 指示の遵守',
				'乗馬中および厩舎内では、指導者・スタッフの指示に必ず従います。指示に従わないことで生じた事故については、参加者の責任となることを理解しています。',
				'',
				'3. 服装・装備',
				'ヘルメット（貸出あり）を必ず着用し、長ズボンとかかとのある靴を着用します。',
				'',
				'4. 健康状態',
				'現在の健康状態に問題がなく、飲酒しての参加はしません。妊娠中の方、心臓・腰・首などに持病がある方、体重が {max_weight}kg を超える方はご参加いただけません。',
				'',
				'5. 事故時の対応',
				'万一の事故の際は応急処置を行い、必要に応じて救急要請を行うことに同意します。治療費等は参加者の加入する保険等でのご負担となる場合があります。主催者の責めに帰すべき事由による場合を除き、主催者は損害賠償の責任を負いません。',
				'',
				'6. 未成年者の参加',
				'{minor_age} 歳未満の方は、保護者の同意を得たうえでお申し込みください。',
				'',
				'7. 写真・動画',
				'活動記録・広報のため撮影を行う場合があります。掲載を希望されない方は、下のチェック欄でお知らせください。',
				'',
				'8. キャンセル・中止',
				'荒天時や馬の体調不良等により、やむを得ず中止する場合があります。',
				'',
				'9. 個人情報',
				'ご記入いただいた情報は、本イベントの運営・連絡・緊急時対応の目的のみに使用します。',
			)
		);
	}

	/**
	 * 設定した色・角丸・フォントから、読み込み済みのスタイルに追記する CSS を作る。
	 *
	 * 色は 1 色だけ指定してもらい、濃い色・薄い色・文字色は自動で計算する。
	 *
	 * @return string CSS。
	 */
	public static function theme_css() {
		$color  = sanitize_hex_color( (string) self::get( 'theme_color' ) );
		$color  = $color ? $color : '#1f3a68';
		$radius = (string) self::get( 'theme_radius' );

		$radii = array(
			'rounded' => array( 12, 8 ),
			'soft'    => array( 6, 4 ),
			'square'  => array( 0, 0 ),
		);
		$size  = isset( $radii[ $radius ] ) ? $radii[ $radius ] : $radii['rounded'];

		$rgb        = self::hex_to_rgb( $color );
		$strong     = self::rgb_to_hex( self::shade( $rgb, 0.78 ) );
		$soft       = self::rgb_to_hex( self::tint( $rgb, 0.12 ) );
		$on_accent  = ( self::luminance( $rgb ) > 0.62 ) ? '#1f2933' : '#ffffff';
		$focus_ring = sprintf( 'rgba(%d, %d, %d, 0.28)', $rgb[0], $rgb[1], $rgb[2] );

		$css = sprintf(
			'.equiticket{--et-accent:%1$s;--et-accent-strong:%2$s;--et-accent-soft:%3$s;--et-on-accent:%4$s;--et-focus-ring:%5$s;--et-radius:%6$dpx;--et-radius-sm:%7$dpx;}',
			$color,
			$strong,
			$soft,
			$on_accent,
			$focus_ring,
			$size[0],
			$size[1]
		);

		if ( self::get( 'theme_font_inherit' ) ) {
			// テーマの本文フォントをそのまま引き継ぐ。
			$css .= '.equiticket{font-family:inherit;}';
		}

		return $css;
	}

	/**
	 * 16 進数の色を RGB に変換する。
	 *
	 * @param string $hex 色（#rrggbb）。
	 * @return array<int,int> RGB。
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * RGB を 16 進数の色に戻す。
	 *
	 * @param array<int,int> $rgb RGB。
	 * @return string 色（#rrggbb）。
	 */
	private static function rgb_to_hex( array $rgb ) {
		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}

	/**
	 * 色を暗くする。
	 *
	 * @param array<int,int> $rgb   RGB。
	 * @param float          $ratio 残す明るさ（0〜1）。
	 * @return array<int,int> RGB。
	 */
	private static function shade( array $rgb, $ratio ) {
		return array(
			(int) round( $rgb[0] * $ratio ),
			(int) round( $rgb[1] * $ratio ),
			(int) round( $rgb[2] * $ratio ),
		);
	}

	/**
	 * 色を白に混ぜて薄くする。
	 *
	 * @param array<int,int> $rgb   RGB。
	 * @param float          $ratio 色の割合（0〜1）。
	 * @return array<int,int> RGB。
	 */
	private static function tint( array $rgb, $ratio ) {
		return array(
			(int) round( 255 - ( 255 - $rgb[0] ) * $ratio ),
			(int) round( 255 - ( 255 - $rgb[1] ) * $ratio ),
			(int) round( 255 - ( 255 - $rgb[2] ) * $ratio ),
		);
	}

	/**
	 * 明るさ（0〜1）。文字色を白と黒のどちらにするかの判断に使う。
	 *
	 * @param array<int,int> $rgb RGB。
	 * @return float 明るさ。
	 */
	private static function luminance( array $rgb ) {
		return ( 0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2] ) / 255;
	}

	/**
	 * 当日の支払額を 1 行で表す（案内文の差し込み用）。金額は「馬の種類」画面で馬ごとに決める。
	 *
	 * @return string 例: 「ポニー 300 円／馬 500 円」。
	 */
	public static function fee_summary() {
		return EquiTicket_Horses::fee_summary();
	}

	/**
	 * 文面中の差し込み（{max_weight} など）を設定値で置き換える。
	 *
	 * @param string $text 文面。
	 * @return string 置き換え後の文面。
	 */
	public static function render_text( $text ) {
		return strtr(
			(string) $text,
			array(
				'{max_weight}' => (string) ( EquiTicket_Screening::max_weight() ? EquiTicket_Screening::max_weight() : '' ),
				'{min_age}'    => (string) (int) self::get( 'min_age' ),
				'{minor_age}'  => (string) (int) self::get( 'minor_age' ),
				'{fee}'        => self::fee_summary(),
				'{event}'      => (string) self::get( 'event_name' ),
			)
		);
	}

	/**
	 * 設定値をすべて取得する。
	 *
	 * @return array<string,mixed> 設定値。
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * 設定値を 1 件取得する。
	 *
	 * @param string $key キー。
	 * @return mixed 値（未定義なら null）。
	 */
	public static function get( $key ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * 設定値を保存する（既存値にマージ）。
	 *
	 * @param array<string,mixed> $values 保存する値。
	 * @return void
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * 入力値を型・範囲に合わせて整える。
	 *
	 * @param array<string,mixed> $input 入力値（$_POST 由来）。
	 * @return array<string,mixed> 保存できる形に整えた値。
	 */
	public static function sanitize( array $input ) {
		$clean = array();

		$clean['event_name']                 = sanitize_text_field( isset( $input['event_name'] ) ? $input['event_name'] : '' );
		$clean['page_id']                    = isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
		$clean['no_show_minutes']            = EquiTicket_Utils::clamp_int( isset( $input['no_show_minutes'] ) ? $input['no_show_minutes'] : 10, 0, 240, 10 );
		$clean['reservation_cutoff_minutes'] = EquiTicket_Utils::clamp_int( isset( $input['reservation_cutoff_minutes'] ) ? $input['reservation_cutoff_minutes'] : 0, 0, 1440, 0 );
		$clean['max_people_per_reservation'] = EquiTicket_Utils::clamp_int( isset( $input['max_people_per_reservation'] ) ? $input['max_people_per_reservation'] : 4, 1, 20, 4 );
		$clean['allow_duplicate_email']      = empty( $input['allow_duplicate_email'] ) ? 0 : 1;
		$clean['ride_minutes']               = EquiTicket_Utils::clamp_int( isset( $input['ride_minutes'] ) ? $input['ride_minutes'] : 0, 0, 120, 0 );

		$clean['min_age']          = EquiTicket_Utils::clamp_int( isset( $input['min_age'] ) ? $input['min_age'] : 5, 0, 120, 5 );
		$clean['minor_age']        = EquiTicket_Utils::clamp_int( isset( $input['minor_age'] ) ? $input['minor_age'] : 18, 0, 30, 18 );

		$clean['payment_text']               = sanitize_textarea_field( isset( $input['payment_text'] ) ? $input['payment_text'] : '' );
		$clean['terms_text']                 = sanitize_textarea_field( isset( $input['terms_text'] ) ? $input['terms_text'] : '' );
		$clean['guardian_label']             = sanitize_text_field( isset( $input['guardian_label'] ) ? $input['guardian_label'] : '' );
		$clean['photo_optout_label']         = sanitize_text_field( isset( $input['photo_optout_label'] ) ? $input['photo_optout_label'] : '' );
		$clean['notice_text']                = sanitize_textarea_field( isset( $input['notice_text'] ) ? $input['notice_text'] : '' );
		$clean['consent_label']              = sanitize_text_field( isset( $input['consent_label'] ) ? $input['consent_label'] : '' );
		$clean['cancel_policy_text']         = sanitize_textarea_field( isset( $input['cancel_policy_text'] ) ? $input['cancel_policy_text'] : '' );
		$clean['venue_text']                 = sanitize_textarea_field( isset( $input['venue_text'] ) ? $input['venue_text'] : '' );
		$clean['mail_from_name']             = sanitize_text_field( isset( $input['mail_from_name'] ) ? $input['mail_from_name'] : '' );
		$clean['mail_from_email']            = isset( $input['mail_from_email'] ) ? sanitize_email( $input['mail_from_email'] ) : '';
		$clean['mail_subject']               = sanitize_text_field( isset( $input['mail_subject'] ) ? $input['mail_subject'] : '' );
		$clean['mail_footer']                = sanitize_textarea_field( isset( $input['mail_footer'] ) ? $input['mail_footer'] : '' );
		$clean['cancel_mail_subject']        = sanitize_text_field( isset( $input['cancel_mail_subject'] ) ? $input['cancel_mail_subject'] : '' );
		$clean['cancel_mail_text']           = sanitize_textarea_field( isset( $input['cancel_mail_text'] ) ? $input['cancel_mail_text'] : '' );
		$color                     = isset( $input['theme_color'] ) ? sanitize_hex_color( trim( (string) $input['theme_color'] ) ) : '';
		$clean['theme_color']      = $color ? $color : '#1f3a68';
		$clean['theme_radius']     = ( isset( $input['theme_radius'] ) && in_array( $input['theme_radius'], array( 'rounded', 'soft', 'square' ), true ) )
			? $input['theme_radius']
			: 'soft';
		$clean['theme_font_inherit'] = empty( $input['theme_font_inherit'] ) ? 0 : 1;

		$clean['delete_data_on_uninstall']   = empty( $input['delete_data_on_uninstall'] ) ? 0 : 1;

		if ( '' === $clean['mail_subject'] ) {
			$defaults              = self::defaults();
			$clean['mail_subject'] = $defaults['mail_subject'];
		}
		if ( '' === $clean['cancel_mail_subject'] ) {
			$defaults                     = self::defaults();
			$clean['cancel_mail_subject'] = $defaults['cancel_mail_subject'];
		}

		return $clean;
	}
}
