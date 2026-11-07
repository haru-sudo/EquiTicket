<?php
/**
 * QR コード生成（モデル2・8ビットバイトモード）の純 PHP 実装。
 *
 * 共用レンタルサーバーでも動くよう「Composer 不要・外部サービス不要」で QR を作るため、
 * 外部ライブラリにも外部 API にも依存しない実装をプラグインに同梱する。
 * 対応バージョンは 1〜10（誤り訂正レベル M で最大 213 バイト）で、
 * 予約確認 URL を収めるには十分な容量がある。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * QR コードのモジュール配列（明=0 / 暗=1）を生成するエンコーダー。
 */
final class EquiTicket_QR_Encoder {

	const ECC_LOW    = 'L';
	const ECC_MEDIUM = 'M';

	const MIN_VERSION = 1;
	const MAX_VERSION = 10;

	/**
	 * 1 ブロックあたりの誤り訂正コード語数（添字＝バージョン）。
	 *
	 * @var array<string,array<int,int|null>>
	 */
	private static $ecc_codewords_per_block = array(
		'L' => array( null, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18 ),
		'M' => array( null, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26 ),
	);

	/**
	 * 誤り訂正ブロック数（添字＝バージョン）。
	 *
	 * @var array<string,array<int,int|null>>
	 */
	private static $num_blocks = array(
		'L' => array( null, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4 ),
		'M' => array( null, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5 ),
	);

	/**
	 * 形式情報に埋め込む誤り訂正レベルのビット値。
	 *
	 * @var array<string,int>
	 */
	private static $format_bits = array(
		'L' => 1,
		'M' => 0,
	);

	/**
	 * シンボルのバージョン（1〜10）。
	 *
	 * @var int
	 */
	private $version;

	/**
	 * 誤り訂正レベル（L または M）。
	 *
	 * @var string
	 */
	private $ecc_level;

	/**
	 * 一辺のモジュール数。
	 *
	 * @var int
	 */
	private $size;

	/**
	 * モジュール配列 [y][x]（0＝明 / 1＝暗）。
	 *
	 * @var array<int,array<int,int>>
	 */
	private $modules = array();

	/**
	 * 機能パターン（マスク対象外）かどうかの配列 [y][x]。
	 *
	 * @var array<int,array<int,bool>>
	 */
	private $is_function = array();

	/**
	 * コンストラクタ。
	 *
	 * @param int    $version   バージョン。
	 * @param string $ecc_level 誤り訂正レベル。
	 */
	private function __construct( $version, $ecc_level ) {
		$this->version   = $version;
		$this->ecc_level = $ecc_level;
		$this->size      = $version * 4 + 17;

		$row = array_fill( 0, $this->size, 0 );
		for ( $y = 0; $y < $this->size; $y++ ) {
			$this->modules[ $y ]     = $row;
			$this->is_function[ $y ] = array_fill( 0, $this->size, false );
		}
	}

	/**
	 * 文字列を QR コードのモジュール配列に変換する。
	 *
	 * @param string $text        埋め込む文字列（UTF-8 のバイト列として扱う）。
	 * @param string $ecc_level   誤り訂正レベル（L または M）。
	 * @param int    $forced_mask マスクパターンを固定する場合は 0〜7。-1 で自動選択。
	 * @return array<int,array<int,int>> モジュール配列 [y][x]。
	 * @throws InvalidArgumentException 引数が不正な場合。
	 * @throws RuntimeException         データが対応バージョンに収まらない場合。
	 */
	public static function encode( $text, $ecc_level = self::ECC_MEDIUM, $forced_mask = -1 ) {
		if ( ! isset( self::$format_bits[ $ecc_level ] ) ) {
			throw new InvalidArgumentException( 'Unsupported error correction level: ' . $ecc_level );
		}
		if ( $forced_mask < -1 || $forced_mask > 7 ) {
			throw new InvalidArgumentException( 'Mask must be between -1 and 7.' );
		}

		$bytes  = (string) $text;
		$length = strlen( $bytes );

		$version = null;
		for ( $candidate = self::MIN_VERSION; $candidate <= self::MAX_VERSION; $candidate++ ) {
			$capacity_bits = self::data_codewords( $candidate, $ecc_level ) * 8;
			$needed_bits   = 4 + self::char_count_bits( $candidate ) + $length * 8;
			if ( $needed_bits <= $capacity_bits ) {
				$version = $candidate;
				break;
			}
		}
		if ( null === $version ) {
			throw new RuntimeException( 'Data too long for QR versions 1-10.' );
		}

		$qr = new self( $version, $ecc_level );
		$qr->draw_function_patterns();
		$qr->draw_codewords( $qr->add_ecc_and_interleave( $qr->build_data_codewords( $bytes ) ) );
		$qr->apply_best_mask( $forced_mask );

		return $qr->modules;
	}

	/**
	 * データ用コード語（終端・パディングを含む）を組み立てる。
	 *
	 * @param string $bytes 埋め込むバイト列。
	 * @return array<int,int> コード語の配列。
	 */
	private function build_data_codewords( $bytes ) {
		$capacity_bits = self::data_codewords( $this->version, $this->ecc_level ) * 8;
		$bits          = array();

		self::append_bits( $bits, 4, 4 );
		self::append_bits( $bits, strlen( $bytes ), self::char_count_bits( $this->version ) );
		for ( $i = 0, $len = strlen( $bytes ); $i < $len; $i++ ) {
			self::append_bits( $bits, ord( $bytes[ $i ] ), 8 );
		}

		// 終端パターンとバイト境界までの 0 埋め。
		$terminator = min( 4, $capacity_bits - count( $bits ) );
		self::append_bits( $bits, 0, $terminator );
		self::append_bits( $bits, 0, ( 8 - count( $bits ) % 8 ) % 8 );

		// 埋め草コード語（0xEC / 0x11 の繰り返し）。
		for ( $pad = 0xEC; count( $bits ) < $capacity_bits; $pad ^= 0xEC ^ 0x11 ) {
			self::append_bits( $bits, $pad, 8 );
		}

		$codewords = array_fill( 0, count( $bits ) >> 3, 0 );
		foreach ( $bits as $index => $bit ) {
			$codewords[ $index >> 3 ] |= $bit << ( 7 - ( $index & 7 ) );
		}

		return $codewords;
	}

	/**
	 * データコード語をブロック分割し、誤り訂正符号を付けてインターリーブする。
	 *
	 * @param array<int,int> $data データコード語。
	 * @return array<int,int> 並べ替え済みコード語。
	 */
	private function add_ecc_and_interleave( array $data ) {
		$num_blocks       = self::$num_blocks[ $this->ecc_level ][ $this->version ];
		$block_ecc_len    = self::$ecc_codewords_per_block[ $this->ecc_level ][ $this->version ];
		$raw_codewords    = intdiv( self::raw_data_modules( $this->version ), 8 );
		$num_short_blocks = $num_blocks - $raw_codewords % $num_blocks;
		$short_block_len  = intdiv( $raw_codewords, $num_blocks );

		$divisor = self::reed_solomon_divisor( $block_ecc_len );
		$blocks  = array();
		$offset  = 0;

		for ( $i = 0; $i < $num_blocks; $i++ ) {
			$data_len = $short_block_len - $block_ecc_len + ( $i < $num_short_blocks ? 0 : 1 );
			$chunk    = array_slice( $data, $offset, $data_len );
			$offset  += $data_len;

			$block = array_pad( $chunk, $short_block_len + 1, 0 );
			$ecc   = self::reed_solomon_remainder( $chunk, $divisor );
			foreach ( $ecc as $index => $value ) {
				$block[ $short_block_len + 1 - $block_ecc_len + $index ] = $value;
			}
			$blocks[] = $block;
		}

		$result = array();
		for ( $i = 0; $i <= $short_block_len; $i++ ) {
			foreach ( $blocks as $j => $block ) {
				// 短いブロックの埋め草位置は読み飛ばす。
				if ( $i !== $short_block_len - $block_ecc_len || $j >= $num_short_blocks ) {
					$result[] = $block[ $i ];
				}
			}
		}

		return $result;
	}

	/**
	 * 機能パターン（位置検出・位置合わせ・タイミング・形式情報領域）を描画する。
	 *
	 * @return void
	 */
	private function draw_function_patterns() {
		for ( $i = 0; $i < $this->size; $i++ ) {
			$dark = ( 0 === $i % 2 );
			$this->set_function_module( 6, $i, $dark );
			$this->set_function_module( $i, 6, $dark );
		}

		$this->draw_finder_pattern( 3, 3 );
		$this->draw_finder_pattern( $this->size - 4, 3 );
		$this->draw_finder_pattern( 3, $this->size - 4 );

		$positions = self::alignment_pattern_positions( $this->version );
		$count     = count( $positions );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = 0; $j < $count; $j++ ) {
				$is_finder_corner = ( 0 === $i && 0 === $j )
					|| ( 0 === $i && $count - 1 === $j )
					|| ( $count - 1 === $i && 0 === $j );
				if ( ! $is_finder_corner ) {
					$this->draw_alignment_pattern( $positions[ $i ], $positions[ $j ] );
				}
			}
		}

		// マスク確定前にダミー値で領域を確保しておく。
		$this->draw_format_bits( 0 );
		$this->draw_version_bits();
	}

	/**
	 * 位置検出パターンと分離パターンを描く。
	 *
	 * @param int $cx 中心の X 座標。
	 * @param int $cy 中心の Y 座標。
	 * @return void
	 */
	private function draw_finder_pattern( $cx, $cy ) {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$distance = max( abs( $dx ), abs( $dy ) );
				$x        = $cx + $dx;
				$y        = $cy + $dy;
				if ( $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size ) {
					$this->set_function_module( $x, $y, 2 !== $distance && 4 !== $distance );
				}
			}
		}
	}

	/**
	 * 位置合わせパターンを描く。
	 *
	 * @param int $cx 中心の X 座標。
	 * @param int $cy 中心の Y 座標。
	 * @return void
	 */
	private function draw_alignment_pattern( $cx, $cy ) {
		for ( $dy = -2; $dy <= 2; $dy++ ) {
			for ( $dx = -2; $dx <= 2; $dx++ ) {
				$this->set_function_module( $cx + $dx, $cy + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
			}
		}
	}

	/**
	 * 形式情報（誤り訂正レベル＋マスク番号）を描く。
	 *
	 * @param int $mask マスク番号。
	 * @return void
	 */
	private function draw_format_bits( $mask ) {
		$data = ( self::$format_bits[ $this->ecc_level ] << 3 ) | $mask;
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( ( $rem >> 9 ) & 1 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function_module( 8, $i, self::get_bit( $bits, $i ) );
		}
		$this->set_function_module( 8, 7, self::get_bit( $bits, 6 ) );
		$this->set_function_module( 8, 8, self::get_bit( $bits, 7 ) );
		$this->set_function_module( 7, 8, self::get_bit( $bits, 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function_module( 14 - $i, 8, self::get_bit( $bits, $i ) );
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function_module( $this->size - 1 - $i, 8, self::get_bit( $bits, $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function_module( 8, $this->size - 15 + $i, self::get_bit( $bits, $i ) );
		}

		// 常に暗モジュール。
		$this->set_function_module( 8, $this->size - 8, true );
	}

	/**
	 * バージョン情報（バージョン 7 以上）を描く。
	 *
	 * @return void
	 */
	private function draw_version_bits() {
		if ( $this->version < 7 ) {
			return;
		}

		$rem = $this->version;
		for ( $i = 0; $i < 12; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( ( $rem >> 11 ) & 1 ) * 0x1F25 );
		}
		$bits = ( $this->version << 12 ) | $rem;

		for ( $i = 0; $i < 18; $i++ ) {
			$bit = self::get_bit( $bits, $i );
			$a   = $this->size - 11 + $i % 3;
			$b   = intdiv( $i, 3 );
			$this->set_function_module( $a, $b, $bit );
			$this->set_function_module( $b, $a, $bit );
		}
	}

	/**
	 * コード語をジグザグ配置でシンボルに埋め込む。
	 *
	 * @param array<int,int> $codewords 並べ替え済みコード語。
	 * @return void
	 */
	private function draw_codewords( array $codewords ) {
		$bit_index = 0;
		$total     = count( $codewords ) * 8;

		for ( $right = $this->size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $this->size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = ( 0 === ( ( $right + 1 ) & 2 ) );
					$y      = $upward ? $this->size - 1 - $vert : $vert;
					if ( ! $this->is_function[ $y ][ $x ] && $bit_index < $total ) {
						$this->modules[ $y ][ $x ] = ( $codewords[ $bit_index >> 3 ] >> ( 7 - ( $bit_index & 7 ) ) ) & 1;
						$bit_index++;
					}
					// 余剰ビットは 0（明）のままにする。
				}
			}
		}
	}

	/**
	 * マスクを適用する。指定がなければ評価値が最小のマスクを選ぶ。
	 *
	 * @param int $forced_mask 固定マスク番号。-1 で自動選択。
	 * @return void
	 */
	private function apply_best_mask( $forced_mask ) {
		$mask = $forced_mask;

		if ( -1 === $mask ) {
			$min_penalty = PHP_INT_MAX;
			for ( $candidate = 0; $candidate < 8; $candidate++ ) {
				$this->apply_mask( $candidate );
				$this->draw_format_bits( $candidate );
				$penalty = $this->penalty_score();
				if ( $penalty < $min_penalty ) {
					$min_penalty = $penalty;
					$mask        = $candidate;
				}
				$this->apply_mask( $candidate );
			}
		}

		$this->apply_mask( $mask );
		$this->draw_format_bits( $mask );
	}

	/**
	 * マスクを XOR で適用（同じマスクを 2 回適用すると元に戻る）。
	 *
	 * @param int $mask マスク番号。
	 * @return void
	 */
	private function apply_mask( $mask ) {
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( $this->is_function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$invert = ( 0 === ( $x + $y ) % 2 );
						break;
					case 1:
						$invert = ( 0 === $y % 2 );
						break;
					case 2:
						$invert = ( 0 === $x % 3 );
						break;
					case 3:
						$invert = ( 0 === ( $x + $y ) % 3 );
						break;
					case 4:
						$invert = ( 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2 );
						break;
					case 5:
						$invert = ( 0 === $x * $y % 2 + $x * $y % 3 );
						break;
					case 6:
						$invert = ( 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2 );
						break;
					default:
						$invert = ( 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2 );
						break;
				}
				if ( $invert ) {
					$this->modules[ $y ][ $x ] ^= 1;
				}
			}
		}
	}

	/**
	 * マスク選択のための評価値（JIS X 0510 の 4 つの減点規則）を計算する。
	 *
	 * @return int 評価値。
	 */
	private function penalty_score() {
		$result = 0;
		$size   = $this->size;

		// 規則 1・3: 行方向。
		for ( $y = 0; $y < $size; $y++ ) {
			$run_color  = 0;
			$run_length = 0;
			$history    = array( 0, 0, 0, 0, 0, 0, 0 );
			$padding    = true;
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $this->modules[ $y ][ $x ] === $run_color ) {
					$run_length++;
					if ( 5 === $run_length ) {
						$result += 3;
					} elseif ( $run_length > 5 ) {
						$result++;
					}
				} else {
					$this->push_run_history( $history, $run_length, $padding );
					if ( 0 === $run_color ) {
						$result += $this->count_finder_like( $history ) * 40;
					}
					$run_color  = $this->modules[ $y ][ $x ];
					$run_length = 1;
				}
			}
			$result += $this->finish_run_history( $history, $run_length, $run_color, $padding ) * 40;
		}

		// 規則 1・3: 列方向。
		for ( $x = 0; $x < $size; $x++ ) {
			$run_color  = 0;
			$run_length = 0;
			$history    = array( 0, 0, 0, 0, 0, 0, 0 );
			$padding    = true;
			for ( $y = 0; $y < $size; $y++ ) {
				if ( $this->modules[ $y ][ $x ] === $run_color ) {
					$run_length++;
					if ( 5 === $run_length ) {
						$result += 3;
					} elseif ( $run_length > 5 ) {
						$result++;
					}
				} else {
					$this->push_run_history( $history, $run_length, $padding );
					if ( 0 === $run_color ) {
						$result += $this->count_finder_like( $history ) * 40;
					}
					$run_color  = $this->modules[ $y ][ $x ];
					$run_length = 1;
				}
			}
			$result += $this->finish_run_history( $history, $run_length, $run_color, $padding ) * 40;
		}

		// 規則 2: 2×2 の同色ブロック。
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$color = $this->modules[ $y ][ $x ];
				if ( $color === $this->modules[ $y ][ $x + 1 ]
					&& $color === $this->modules[ $y + 1 ][ $x ]
					&& $color === $this->modules[ $y + 1 ][ $x + 1 ] ) {
					$result += 3;
				}
			}
		}

		// 規則 4: 暗モジュールの比率。
		$dark = 0;
		foreach ( $this->modules as $row ) {
			$dark += array_sum( $row );
		}
		$total = $size * $size;
		$k     = intdiv( abs( $dark * 20 - $total * 10 ) + $total - 1, $total ) - 1;
		$result += $k * 10;

		return $result;
	}

	/**
	 * 連長履歴を 1 つ進める。
	 *
	 * @param array<int,int> $history    連長履歴（参照渡し）。
	 * @param int            $run_length 直前の連長。
	 * @param bool           $padding    先頭の余白を加味するか（参照渡し）。
	 * @return void
	 */
	private function push_run_history( array &$history, $run_length, &$padding ) {
		if ( $padding ) {
			// 最初の明モジュール列には上下左右の余白分を足して評価する。
			$run_length += $this->size;
			$padding     = false;
		}
		array_pop( $history );
		array_unshift( $history, $run_length );
	}

	/**
	 * 行・列の末尾まで到達したときの評価値（finder 類似パターン数）を返す。
	 *
	 * @param array<int,int> $history    連長履歴。
	 * @param int            $run_length 末尾の連長。
	 * @param int            $run_color  末尾の色。
	 * @param bool           $padding    先頭余白の加味フラグ。
	 * @return int finder 類似パターンの個数。
	 */
	private function finish_run_history( array $history, $run_length, $run_color, $padding ) {
		if ( 1 === $run_color ) {
			$this->push_run_history( $history, $run_length, $padding );
			$run_length = 0;
		}
		$run_length += $this->size;
		$this->push_run_history( $history, $run_length, $padding );

		return $this->count_finder_like( $history );
	}

	/**
	 * 連長履歴に含まれる 1:1:3:1:1 比率のパターン数を数える。
	 *
	 * @param array<int,int> $history 連長履歴。
	 * @return int 個数（0〜2）。
	 */
	private function count_finder_like( array $history ) {
		$n    = $history[1];
		$core = $n > 0
			&& $history[2] === $n
			&& $history[3] === $n * 3
			&& $history[4] === $n
			&& $history[5] === $n;

		$count = 0;
		if ( $core && $history[0] >= $n * 4 && $history[6] >= $n ) {
			$count++;
		}
		if ( $core && $history[6] >= $n * 4 && $history[0] >= $n ) {
			$count++;
		}

		return $count;
	}

	/**
	 * 機能パターンのモジュールを設定する。
	 *
	 * @param int  $x    X 座標。
	 * @param int  $y    Y 座標。
	 * @param bool $dark 暗モジュールなら true。
	 * @return void
	 */
	private function set_function_module( $x, $y, $dark ) {
		if ( $x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size ) {
			return;
		}
		$this->modules[ $y ][ $x ]     = $dark ? 1 : 0;
		$this->is_function[ $y ][ $x ] = true;
	}

	/**
	 * ビット列にビットを追加する。
	 *
	 * @param array<int,int> $bits   ビット列（参照渡し）。
	 * @param int            $value  値。
	 * @param int            $length ビット数。
	 * @return void
	 */
	private static function append_bits( array &$bits, $value, $length ) {
		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}

	/**
	 * 指定位置のビットを返す。
	 *
	 * @param int $value 値。
	 * @param int $index ビット位置。
	 * @return bool ビットが 1 なら true。
	 */
	private static function get_bit( $value, $index ) {
		return 0 !== ( ( $value >> $index ) & 1 );
	}

	/**
	 * 文字数指示子のビット数（バイトモード）。
	 *
	 * @param int $version バージョン。
	 * @return int ビット数。
	 */
	private static function char_count_bits( $version ) {
		return $version <= 9 ? 8 : 16;
	}

	/**
	 * 機能パターンを除いたモジュール数（＝生データのビット数）。
	 *
	 * @param int $version バージョン。
	 * @return int モジュール数。
	 */
	private static function raw_data_modules( $version ) {
		$result = ( 16 * $version + 128 ) * $version + 64;
		if ( $version >= 2 ) {
			$num_align = intdiv( $version, 7 ) + 2;
			$result   -= ( 25 * $num_align - 10 ) * $num_align - 55;
			if ( $version >= 7 ) {
				$result -= 36;
			}
		}

		return $result;
	}

	/**
	 * データコード語数（誤り訂正コード語を除く）。
	 *
	 * @param int    $version   バージョン。
	 * @param string $ecc_level 誤り訂正レベル。
	 * @return int コード語数。
	 */
	private static function data_codewords( $version, $ecc_level ) {
		return intdiv( self::raw_data_modules( $version ), 8 )
			- self::$ecc_codewords_per_block[ $ecc_level ][ $version ] * self::$num_blocks[ $ecc_level ][ $version ];
	}

	/**
	 * 位置合わせパターンの中心座標を返す。
	 *
	 * @param int $version バージョン。
	 * @return array<int,int> 座標の配列。
	 */
	private static function alignment_pattern_positions( $version ) {
		if ( 1 === $version ) {
			return array();
		}

		$num_align = intdiv( $version, 7 ) + 2;
		$step      = intdiv( $version * 4 + $num_align * 2 + 1, $num_align * 2 - 2 ) * 2;
		$size      = $version * 4 + 17;

		$result = array();
		for ( $pos = $size - 7; count( $result ) < $num_align - 1; $pos -= $step ) {
			array_unshift( $result, $pos );
		}
		array_unshift( $result, 6 );

		return $result;
	}

	/**
	 * リード・ソロモン符号の生成多項式を作る。
	 *
	 * @param int $degree 次数（＝誤り訂正コード語数）。
	 * @return array<int,int> 生成多項式の係数。
	 */
	private static function reed_solomon_divisor( $degree ) {
		$result             = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;

		$root = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_multiply( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_multiply( $root, 0x02 );
		}

		return $result;
	}

	/**
	 * リード・ソロモン符号の剰余（＝誤り訂正コード語）を計算する。
	 *
	 * @param array<int,int> $data    データコード語。
	 * @param array<int,int> $divisor 生成多項式。
	 * @return array<int,int> 誤り訂正コード語。
	 */
	private static function reed_solomon_remainder( array $data, array $divisor ) {
		$degree = count( $divisor );
		$result = array_fill( 0, $degree, 0 );

		foreach ( $data as $byte ) {
			$factor = $byte ^ array_shift( $result );
			$result[] = 0;
			for ( $i = 0; $i < $degree; $i++ ) {
				$result[ $i ] ^= self::gf_multiply( $divisor[ $i ], $factor );
			}
		}

		return $result;
	}

	/**
	 * GF(2^8) 上の乗算。
	 *
	 * @param int $x 被乗数。
	 * @param int $y 乗数。
	 * @return int 積。
	 */
	private static function gf_multiply( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z = ( ( $z << 1 ) ^ ( ( ( $z >> 7 ) & 1 ) * 0x11D ) ) & 0xFF;
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}

		return $z & 0xFF;
	}
}
