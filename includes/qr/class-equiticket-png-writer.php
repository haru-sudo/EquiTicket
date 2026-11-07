<?php
/**
 * モジュール配列から PNG 画像を生成する軽量ライター。
 *
 * GD 拡張に依存せず zlib（gzcompress）だけで PNG を組み立てるため、
 * 共用サーバーの環境差の影響を受けにくい。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 1 ビット相当のモノクロ PNG を出力するクラス。
 */
final class EquiTicket_PNG_Writer {

	/**
	 * モジュール配列を PNG バイナリに変換する。
	 *
	 * @param array<int,array<int,int>> $modules モジュール配列 [y][x]（1＝暗）。
	 * @param int                       $scale   1 モジュールあたりのピクセル数。
	 * @param int                       $margin  余白（クワイエットゾーン）のモジュール数。
	 * @return string PNG バイナリ。
	 * @throws InvalidArgumentException 引数が不正な場合。
	 */
	public static function from_modules( array $modules, $scale = 6, $margin = 4 ) {
		$scale  = (int) $scale;
		$margin = (int) $margin;

		if ( empty( $modules ) ) {
			throw new InvalidArgumentException( 'Modules must not be empty.' );
		}
		if ( $scale < 1 || $scale > 40 ) {
			throw new InvalidArgumentException( 'Scale must be between 1 and 40.' );
		}
		if ( $margin < 0 || $margin > 16 ) {
			throw new InvalidArgumentException( 'Margin must be between 0 and 16.' );
		}

		$size       = count( $modules );
		$dimension  = ( $size + $margin * 2 ) * $scale;
		$light_line = str_repeat( chr( 255 ), $dimension );

		// 各行の先頭にフィルタータイプ 0 を付けた生データを作る。
		$raw = '';
		for ( $i = 0; $i < $margin * $scale; $i++ ) {
			$raw .= chr( 0 ) . $light_line;
		}
		foreach ( $modules as $row ) {
			$line = str_repeat( chr( 255 ), $margin * $scale );
			foreach ( $row as $module ) {
				$line .= str_repeat( $module ? chr( 0 ) : chr( 255 ), $scale );
			}
			$line .= str_repeat( chr( 255 ), $margin * $scale );
			for ( $i = 0; $i < $scale; $i++ ) {
				$raw .= chr( 0 ) . $line;
			}
		}
		for ( $i = 0; $i < $margin * $scale; $i++ ) {
			$raw .= chr( 0 ) . $light_line;
		}

		$ihdr = pack( 'NN', $dimension, $dimension ) . pack( 'CCCCC', 8, 0, 0, 0, 0 );

		return "\x89PNG\r\n\x1a\n"
			. self::chunk( 'IHDR', $ihdr )
			. self::chunk( 'IDAT', gzcompress( $raw, 9 ) )
			. self::chunk( 'IEND', '' );
	}

	/**
	 * PNG チャンクを組み立てる。
	 *
	 * @param string $type チャンク種別（4 文字）。
	 * @param string $data チャンクデータ。
	 * @return string チャンクのバイナリ。
	 */
	private static function chunk( $type, $data ) {
		return pack( 'N', strlen( $data ) )
			. $type
			. $data
			. pack( 'N', crc32( $type . $data ) & 0xFFFFFFFF );
	}
}
