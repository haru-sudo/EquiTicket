<?php
/**
 * QR 画像の生成。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 予約確認 URL を QR 画像に変換するクラス。
 *
 * 同梱の純 PHP エンコーダーでサーバー側生成する。外部の QR 生成サービスは使わないので、
 * 整理券の URL（予約を特定する鍵）がサイトの外に出ることはない。
 */
final class EquiTicket_QR {

	/**
	 * PNG バイナリを返す。
	 *
	 * @param string $text   QR に埋め込む文字列。
	 * @param int    $scale  1 モジュールのピクセル数。
	 * @param int    $margin 余白のモジュール数。
	 * @return string PNG バイナリ。失敗時は空文字。
	 */
	public static function png( $text, $scale = 8, $margin = 4 ) {
		try {
			$modules = EquiTicket_QR_Encoder::encode( $text, EquiTicket_QR_Encoder::ECC_MEDIUM );

			return EquiTicket_PNG_Writer::from_modules( $modules, $scale, $margin );
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * ページに直接書き込む SVG の QR を返す。
	 *
	 * 画像ファイルや data URI を使わないので、LINE などのアプリ内ブラウザや、
	 * 画像を書き換える最適化系プラグインの影響を受けにくい。
	 *
	 * @param string $text   QR に埋め込む文字列。
	 * @param int    $size   表示する大きさ（px）。
	 * @param int    $margin 余白のモジュール数。
	 * @param string $label  読み上げ用の説明。
	 * @return string SVG。失敗時は空文字。
	 */
	public static function svg( $text, $size = 260, $margin = 4, $label = '' ) {
		try {
			$modules = EquiTicket_QR_Encoder::encode( $text, EquiTicket_QR_Encoder::ECC_MEDIUM );
		} catch ( Exception $e ) {
			return '';
		}

		$count = count( $modules );
		$total = $count + 2 * $margin;
		$path  = '';

		// 横に続く黒いモジュールを 1 本の矩形にまとめて、パスを短くする。
		foreach ( $modules as $y => $row ) {
			$x = 0;
			while ( $x < $count ) {
				if ( empty( $row[ $x ] ) ) {
					$x++;
					continue;
				}
				$start = $x;
				while ( $x < $count && ! empty( $row[ $x ] ) ) {
					$x++;
				}
				$path .= sprintf( 'M%d %dh%dv1h-%dz', $start + $margin, $y + $margin, $x - $start, $x - $start );
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges" role="img" aria-label="%3$s"><rect width="%1$d" height="%1$d" fill="#fff"/><path fill="#000" d="%4$s"/></svg>',
			$total,
			(int) $size,
			esc_attr( $label ),
			$path
		);
	}

	/**
	 * data URI 形式の PNG を返す。
	 *
	 * @param string $text   QR に埋め込む文字列。
	 * @param int    $scale  1 モジュールのピクセル数。
	 * @param int    $margin 余白のモジュール数。
	 * @return string data URI。失敗時は空文字。
	 */
	public static function data_uri( $text, $scale = 8, $margin = 4 ) {
		$png = self::png( $text, $scale, $margin );
		if ( '' === $png ) {
			return '';
		}

		return 'data:image/png;base64,' . base64_encode( $png );
	}
}
