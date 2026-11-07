<?php
/**
 * WordPress を起動せずに動かせる範囲の自動テスト。
 *
 * 実行: php tests/run-tests.php
 *
 * QR エンコーダーは別実装（python-qrcode）で生成した期待値と
 * 1 モジュール単位で比較する。PNG 出力と入力値の検証も確認する。
 *
 * @package EquiTicket
 */

// 本体のファイルは直接アクセス防止のため ABSPATH を見ているので、テスト用に定義する。
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/wp-stubs.php';
require_once ABSPATH . 'includes/qr/class-equiticket-qr-encoder.php';
require_once ABSPATH . 'includes/qr/class-equiticket-png-writer.php';
require_once ABSPATH . 'includes/class-equiticket-utils.php';
require_once ABSPATH . 'includes/class-equiticket-horses.php';
require_once ABSPATH . 'includes/class-equiticket-screening.php';
require_once ABSPATH . 'includes/class-equiticket-options.php';
require_once ABSPATH . 'includes/class-equiticket-slots.php';

/**
 * ごく小さなテストランナー。
 */
final class EquiTicket_Test_Runner {

	/**
	 * 成功件数。
	 *
	 * @var int
	 */
	private $passed = 0;

	/**
	 * 失敗メッセージ。
	 *
	 * @var array<int,string>
	 */
	private $failures = array();

	/**
	 * 条件が真であることを確認する。
	 *
	 * @param bool   $condition 条件。
	 * @param string $label     テスト名。
	 * @return void
	 */
	public function assert_true( $condition, $label ) {
		if ( $condition ) {
			$this->passed++;

			return;
		}
		$this->failures[] = $label;
	}

	/**
	 * 2 つの値が等しいことを確認する。
	 *
	 * @param mixed  $expected 期待値。
	 * @param mixed  $actual   実際の値。
	 * @param string $label    テスト名。
	 * @return void
	 */
	public function assert_same( $expected, $actual, $label ) {
		if ( $expected === $actual ) {
			$this->passed++;

			return;
		}

		$this->failures[] = sprintf(
			"%s\n    expected: %s\n    actual:   %s",
			$label,
			self::stringify( $expected ),
			self::stringify( $actual )
		);
	}

	/**
	 * 値を表示用の文字列にする。
	 *
	 * @param mixed $value 値。
	 * @return string 文字列。
	 */
	private static function stringify( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return substr( wp_json_encode( $value ), 0, 200 );
		}

		return (string) $value;
	}

	/**
	 * 結果を出力し、終了コードを返す。
	 *
	 * @return int 終了コード。
	 */
	public function summary() {
		if ( ! $this->failures ) {
			printf( "OK: %d assertions passed.\n", $this->passed );

			return 0;
		}

		printf( "FAILED: %d passed, %d failed.\n", $this->passed, count( $this->failures ) );
		foreach ( $this->failures as $failure ) {
			printf( "  - %s\n", $failure );
		}

		return 1;
	}
}

$runner = new EquiTicket_Test_Runner();

/*
 * 1. QR エンコーダー：別実装の期待値と完全一致するか。
 */
$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/qr-vectors.json' ), true );
foreach ( $fixture['vectors'] as $index => $vector ) {
	$modules = EquiTicket_QR_Encoder::encode( $vector['text'], $vector['ecl'], $vector['mask'] );
	$rows    = array();
	foreach ( $modules as $row ) {
		$rows[] = implode( '', $row );
	}

	$runner->assert_same(
		$vector['rows'],
		$rows,
		sprintf( 'QR vector #%d (len=%d, ecl=%s, mask=%d)', $index, strlen( $vector['text'] ), $vector['ecl'], $vector['mask'] )
	);
}

/*
 * 2. バージョン選択：データ長に応じて必要最小のシンボルを選ぶか。
 */
// データ長 => 一辺のモジュール数（バージョン n の一辺は 4n+17）。
$expected_sizes = array(
	1   => 21, // v1
	14  => 21, // v1 の上限（14 バイト）
	15  => 25, // v2 に繰り上がる
	106 => 41, // v6 の上限（106 バイト）
	107 => 45, // v7 に繰り上がる
	213 => 57, // v10 の上限（213 バイト）
);
foreach ( $expected_sizes as $length => $expected ) {
	$modules = EquiTicket_QR_Encoder::encode( str_repeat( 'x', $length ), 'M' );
	$runner->assert_same(
		$expected,
		count( $modules ),
		sprintf( 'symbol size for %d bytes (ECC M)', $length )
	);
}

$too_long = false;
try {
	EquiTicket_QR_Encoder::encode( str_repeat( 'x', 214 ), 'M' );
} catch ( RuntimeException $e ) {
	$too_long = true;
}
$runner->assert_true( $too_long, 'data over capacity raises RuntimeException' );

/*
 * 3. 自動マスク選択でも、同じ入力なら常に同じ結果になるか。
 */
$first  = EquiTicket_QR_Encoder::encode( 'https://example.com/?et_token=abc', 'M' );
$second = EquiTicket_QR_Encoder::encode( 'https://example.com/?et_token=abc', 'M' );
$runner->assert_same( $first, $second, 'automatic mask selection is deterministic' );

/*
 * 4. PNG ライター：ヘッダーと画像サイズ、モジュールの明暗。
 */
$modules = EquiTicket_QR_Encoder::encode( 'EQUITICKET', 'M' );
$png     = EquiTicket_PNG_Writer::from_modules( $modules, 4, 4 );
$runner->assert_same( "\x89PNG\r\n\x1a\n", substr( $png, 0, 8 ), 'PNG signature' );

$expected_dimension = ( count( $modules ) + 8 ) * 4;
$runner->assert_same( $expected_dimension, unpack( 'N', substr( $png, 16, 4 ) )[1], 'PNG width' );
$runner->assert_same( $expected_dimension, unpack( 'N', substr( $png, 20, 4 ) )[1], 'PNG height' );

if ( function_exists( 'imagecreatefromstring' ) ) {
	$image = imagecreatefromstring( $png );
	$runner->assert_true( false !== $image, 'PNG is readable by GD' );
	if ( false !== $image ) {
		// 左上の余白は白、位置検出パターンの中心は黒。
		$margin_color = imagecolorat( $image, 2, 2 ) & 0xFF;
		$finder_color = imagecolorat( $image, ( 4 + 3 ) * 4 + 2, ( 4 + 3 ) * 4 + 2 ) & 0xFF;
		$runner->assert_same( 255, $margin_color, 'quiet zone is light' );
		$runner->assert_same( 0, $finder_color, 'finder pattern centre is dark' );
	}
}

/*
 * 5. 入力値の検証（日付・時刻・トークン）。
 */
$runner->assert_same( '2026-09-19', EquiTicket_Utils::sanitize_date( '2026-09-19' ), 'sanitize_date accepts ISO date' );
$runner->assert_same( '', EquiTicket_Utils::sanitize_date( '2026-02-30' ), 'sanitize_date rejects impossible date' );
$runner->assert_same( '', EquiTicket_Utils::sanitize_date( '2026/09/19' ), 'sanitize_date rejects other formats' );
$runner->assert_same( '10:05:00', EquiTicket_Utils::sanitize_time( '10:05' ), 'sanitize_time pads seconds' );
$runner->assert_same( '23:59:59', EquiTicket_Utils::sanitize_time( '23:59:59' ), 'sanitize_time keeps seconds' );
$runner->assert_same( '', EquiTicket_Utils::sanitize_time( '24:00' ), 'sanitize_time rejects out of range' );
$runner->assert_same( '', EquiTicket_Utils::sanitize_time( '9:5' ), 'sanitize_time rejects loose input' );

$token = str_repeat( 'a1b2', 10 );
$runner->assert_same( $token, EquiTicket_Utils::sanitize_token( $token ), 'sanitize_token accepts hex token' );
$runner->assert_same(
	$token,
	EquiTicket_Utils::sanitize_token( 'https://example.com/ticket/?et_token=' . $token . '&utm=poster' ),
	'sanitize_token extracts token from scanned URL'
);
$runner->assert_same( '', EquiTicket_Utils::sanitize_token( 'short' ), 'sanitize_token rejects short input' );
$runner->assert_same( '', EquiTicket_Utils::sanitize_token( "abcdefghijklmnop';DROP" ), 'sanitize_token rejects symbols' );
$runner->assert_same(
	'',
	EquiTicket_Utils::sanitize_token( 'https://example.com/ticket/?et_token[]=' . $token ),
	'sanitize_token rejects array token in URL'
);

$runner->assert_same( "'=HYPERLINK(\"https://x.example\")", EquiTicket_Utils::csv_cell( '=HYPERLINK("https://x.example")' ), 'csv_cell neutralizes formula' );
$runner->assert_same( "'+81", EquiTicket_Utils::csv_cell( '+81' ), 'csv_cell neutralizes plus' );
$runner->assert_same( "'-1+1", EquiTicket_Utils::csv_cell( '-1+1' ), 'csv_cell neutralizes minus' );
$runner->assert_same( "'@SUM(A1)", EquiTicket_Utils::csv_cell( '@SUM(A1)' ), 'csv_cell neutralizes at sign' );
$runner->assert_same( "'\t=1", EquiTicket_Utils::csv_cell( "\t=1" ), 'csv_cell neutralizes leading tab' );
$runner->assert_same( '山田 太郎', EquiTicket_Utils::csv_cell( '山田 太郎' ), 'csv_cell keeps normal name' );
$runner->assert_same( '', EquiTicket_Utils::csv_cell( '' ), 'csv_cell keeps empty string' );
$runner->assert_same( 120, EquiTicket_Utils::csv_cell( 120 ), 'csv_cell keeps numbers' );

$runner->assert_same( 4, EquiTicket_Utils::clamp_int( 99, 1, 4, 1 ), 'clamp_int clamps upper bound' );
$runner->assert_same( 1, EquiTicket_Utils::clamp_int( 0, 1, 4, 1 ), 'clamp_int clamps lower bound' );
$runner->assert_same( 2, EquiTicket_Utils::clamp_int( 'x', 1, 4, 2 ), 'clamp_int falls back for non numeric' );

$datetime = EquiTicket_Utils::make_datetime( '2026-09-19', '10:30:00' );
$runner->assert_same( '2026-09-19 10:30', $datetime ? $datetime->format( 'Y-m-d H:i' ) : '', 'make_datetime builds date and time' );

/*
 * 6. 乗れる馬の判定。馬の種類は管理画面で自由に登録する（ここではテスト用に組み立てる）。
 */
$limits = array(
	'min_age'   => 5,
	'minor_age' => 18,
);

/**
 * テスト用の馬の種類を作る。
 *
 * @param int                 $id     ID。
 * @param string              $name   名前。
 * @param array<string,int>   $fields 条件・支払額など。
 * @return object 馬の種類。
 */
function equiticket_test_horse( $id, $name, array $fields ) {
	return EquiTicket_Horses::decorate( (object) array_merge( array( 'id' => $id, 'name' => $name ), $fields ) );
}

$pony  = equiticket_test_horse( 1, 'ポニー', array( 'max_age' => 12, 'max_height' => 140, 'max_weight' => 40, 'fee_amount' => 300 ) );
$horse = equiticket_test_horse( 2, '馬', array( 'min_age' => 6, 'min_height' => 120, 'max_weight' => 80, 'fee_amount' => 500 ) );
$chagu = equiticket_test_horse( 3, 'ミニチュアホース', array( 'min_age' => 5, 'min_height' => 100, 'max_weight' => 80, 'fee_amount' => 500 ) );

$day1   = array( 1 => $pony, 2 => $horse );
$all    = array( 1 => $pony, 2 => $horse, 3 => $chagu );
$names  = array( 1 => 'pony', 2 => 'horse', 3 => 'chagu' );

/**
 * 判定結果を、乗れる馬の組み合わせ（またはエラーコード）で表す。
 *
 * @param int                    $height  身長。
 * @param int                    $weight  体重。
 * @param int                    $age     年齢。
 * @param array<int,object>      $horses  馬の種類。
 * @param array<int,int>|null    $offered 時間枠が用意されている馬の ID。
 * @return string 'pony' / 'pony+horse' など、またはエラーコード。
 */
function equiticket_assign_options( $height, $weight, $age, array $horses, $offered = null ) {
	global $limits, $names;

	$result = EquiTicket_Screening::assign( $height, $weight, $age, $horses, $offered, $limits );
	if ( is_wp_error( $result ) ) {
		return $result->get_error_code();
	}

	return implode(
		'+',
		array_map(
			static function ( $id ) use ( $names ) {
				return $names[ $id ];
			},
			$result['options']
		)
	);
}

// 1 日目（ポニーと馬）: ポニーのみ／どちらも可／馬のみ。
$runner->assert_same( 'pony', equiticket_assign_options( 110, 20, 5, $day1 ), '5 歳・110cm はポニーのみ' );
$runner->assert_same( 'pony', equiticket_assign_options( 119, 25, 8, $day1 ), '馬の身長の下限に 1cm 足りなければポニーのみ' );
$runner->assert_same( 'pony+horse', equiticket_assign_options( 130, 30, 10, $day1 ), '中間の体格は両方から選べる' );
$runner->assert_same( 'pony+horse', equiticket_assign_options( 140, 40, 12, $day1 ), 'ポニーの上限ちょうどは両方から選べる' );
$runner->assert_same( 'pony+horse', equiticket_assign_options( 120, 20, 6, $day1 ), '馬の下限ちょうどは両方から選べる' );
$runner->assert_same( 'horse', equiticket_assign_options( 141, 40, 12, $day1 ), '身長が 1cm 超えたら馬のみ' );
$runner->assert_same( 'horse', equiticket_assign_options( 140, 41, 12, $day1 ), '体重が 1kg 超えたら馬のみ' );
$runner->assert_same( 'horse', equiticket_assign_options( 140, 40, 13, $day1 ), '年齢が 1 歳超えたら馬のみ' );
$runner->assert_same( 'horse', equiticket_assign_options( 165, 80, 25, $day1 ), '体重の上限ちょうどは馬のみ' );

// 参加できないケース。
$runner->assert_same( 'equiticket_too_heavy', equiticket_assign_options( 170, 81, 25, $day1 ), '体重超過は参加できない' );
$runner->assert_same( 'equiticket_too_young', equiticket_assign_options( 100, 15, 4, $day1 ), '最低年齢未満は参加できない' );
$runner->assert_same( 'equiticket_no_match', equiticket_assign_options( 110, 45, 6, $day1 ), 'どの馬の条件にも合わなければ案内できない' );
$runner->assert_same( 'equiticket_height_range', equiticket_assign_options( 10, 30, 10, $day1 ), '身長の入力ミスは弾く' );
$runner->assert_same( 'equiticket_weight_range', equiticket_assign_options( 150, 300, 20, $day1 ), '体重の入力ミスは弾く' );
$runner->assert_same( 'equiticket_profile_required', equiticket_assign_options( '', '', '', $day1 ), '未入力は弾く' );
$runner->assert_same( 'equiticket_no_match', equiticket_assign_options( 130, 30, 10, array() ), '馬が登録されていなければ案内できない' );

// 3 種類登録し、時間枠のある馬だけに絞る（2・3 日目はポニーとミニチュアホース）。
$runner->assert_same( 'pony+chagu', equiticket_assign_options( 110, 20, 5, $all, array( 1, 3 ) ), '子どもはポニーとミニチュアホースから選べる' );
$runner->assert_same( 'chagu', equiticket_assign_options( 170, 65, 30, $all, array( 1, 3 ) ), '大人はミニチュアホースのみ' );
$runner->assert_same( 'pony', equiticket_assign_options( 99, 15, 5, $all, array( 1, 3 ) ), 'ミニチュアホースの身長の下限に届かなければポニーのみ' );
$runner->assert_same( 'pony+horse+chagu', equiticket_assign_options( 130, 30, 10, $all, array( 1, 2, 3 ) ), '3 種類とも枠があれば 3 つから選べる' );
$runner->assert_same( 'pony+horse+chagu', equiticket_assign_options( 130, 30, 10, $all, array() ), '枠がひとつもない場合は絞らない' );
$runner->assert_same( 'equiticket_no_match', equiticket_assign_options( 170, 65, 30, $all, array( 1 ) ), '乗れる馬の枠がなければ案内できない' );

// 条件のない項目は制限なし。体重の上限がない馬があれば、体重超過では弾かない。
$free = equiticket_test_horse( 4, '引き馬', array() );
$runner->assert_same( 0, EquiTicket_Screening::max_weight( array( $pony, $free ) ), '上限のない馬があれば体重の上限はなし' );
$runner->assert_same( 80, EquiTicket_Screening::max_weight( $day1 ), '体重の上限は登録した馬のうち最大' );
$runner->assert_true( EquiTicket_Horses::accepts( $free, 200, 150, 90 ), '条件のない馬は誰でも乗れる' );

// 選択が必要かどうかと、希望した馬の確定。
$both = EquiTicket_Screening::assign( 130, 30, 10, $day1, null, $limits );
$runner->assert_true( $both['choice_required'], '両方乗れる場合は選択が必要' );
$runner->assert_same( 'ポニーと馬のどちらでもご案内できます。乗りたい馬をお選びください。', $both['message'], '案内文に登録した名前を使う' );
$runner->assert_same( 2, EquiTicket_Screening::resolve_type( $both, 2 ), '希望した馬が選べるならそれを使う' );
$runner->assert_same( 1, EquiTicket_Screening::resolve_type( $both, '1' ), '文字列の ID も受け付ける' );
$runner->assert_same( null, EquiTicket_Screening::resolve_type( $both, 0 ), '未選択なら確定しない' );
$runner->assert_same( null, EquiTicket_Screening::resolve_type( $both, 99 ), '知らない値は無視する' );

$pony_only = EquiTicket_Screening::assign( 110, 20, 5, $day1, null, $limits );
$runner->assert_true( ! $pony_only['choice_required'], '1 種類だけなら選択は不要' );
$runner->assert_same( 1, EquiTicket_Screening::resolve_type( $pony_only, 2 ), '乗れない馬を指定されても、乗れるほうに確定する' );

$minor = EquiTicket_Screening::assign( 160, 50, 17, $day1, null, $limits );
$runner->assert_true( ! is_wp_error( $minor ) && $minor['requires_guardian'], '18 歳未満は保護者同意が必要' );

$adult = EquiTicket_Screening::assign( 160, 50, 18, $day1, null, $limits );
$runner->assert_true( ! is_wp_error( $adult ) && ! $adult['requires_guardian'], '18 歳以上は保護者同意が不要' );

/*
 * 7. 馬の種類の登録内容（条件の表示・入力の検証・支払額）。
 */
$runner->assert_same( '12歳以下・140cm以下・40kg以下', EquiTicket_Horses::conditions_label( $pony ), 'ポニーの条件の表示' );
$runner->assert_same( '6歳以上・120cm以上・80kg以下', EquiTicket_Horses::conditions_label( $horse ), '馬の条件の表示' );
$runner->assert_same( '制限なし', EquiTicket_Horses::conditions_label( $free ), '条件なしの表示' );
$runner->assert_same( '5〜12歳', EquiTicket_Horses::conditions_label( equiticket_test_horse( 5, 'x', array( 'min_age' => 5, 'max_age' => 12 ) ) ), '下限と上限の両方' );

$runner->assert_same( '300 円', EquiTicket_Horses::fee_label( $pony ), '支払額の表示' );
$runner->assert_same( '', EquiTicket_Horses::fee_label( $free ), '金額 0 は表示しない' );
$runner->assert_same( 'ポニー 300 円／馬 500 円', EquiTicket_Horses::fee_summary( $day1 ), '馬ごとの金額をまとめる' );
$runner->assert_same( '500 円', EquiTicket_Horses::fee_summary( array( $horse, $chagu ) ), '全部同じ金額なら金額だけ' );
$runner->assert_same( '', EquiTicket_Horses::fee_summary( array( $free ) ), '金額がなければ空' );

$clean = EquiTicket_Horses::sanitize( array( 'name' => ' ミニチュアホース ', 'min_age' => '5', 'max_weight' => '', 'fee_amount' => '500' ) );
$runner->assert_true( ! is_wp_error( $clean ) && 'ミニチュアホース' === $clean['name'] && 5 === $clean['min_age'] && 0 === $clean['max_weight'], '空欄の条件は 0（制限なし）として保存する' );
$runner->assert_true( is_wp_error( EquiTicket_Horses::sanitize( array( 'name' => '' ) ) ), '名前は必須' );
$runner->assert_true( is_wp_error( EquiTicket_Horses::sanitize( array( 'name' => 'x', 'min_age' => 10, 'max_age' => 5 ) ) ), '下限が上限を超えたらエラー' );

/*
 * 8. 時間枠に出す馬と、残りの計算。
 */
$slot = (object) array(
	'remaining' => 6,
	'horses'    => array(
		1 => (object) array( 'capacity' => 4, 'remaining' => 3 ),
		3 => (object) array( 'capacity' => 4, 'remaining' => 2 ),
		2 => (object) array( 'capacity' => 0, 'remaining' => 0 ),
	),
);
$runner->assert_true( EquiTicket_Slots::offers( $slot, 3 ), '定員のある馬は受け付ける' );
$runner->assert_true( ! EquiTicket_Slots::offers( $slot, 2 ), '定員 0 の馬は受け付けない' );
$runner->assert_true( ! EquiTicket_Slots::offers( $slot, 9 ), '枠に出していない馬は受け付けない' );
$runner->assert_same( 2, EquiTicket_Slots::remaining_for( $slot, 3 ), '馬ごとの残り' );
$runner->assert_same( 0, EquiTicket_Slots::remaining_for( $slot, 9 ), '枠にない馬の残りは 0' );
$runner->assert_same( 6, EquiTicket_Slots::remaining_for( $slot ), '馬を指定しなければ枠全体の残り' );

exit( $runner->summary() );
