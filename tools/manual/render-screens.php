<?php
/**
 * プラグインの描画処理をサンプルデータで実行し、画面ごとの HTML を書き出す。
 *
 * 使い方: php tools/manual/render-screens.php <出力ディレクトリ>
 * 出力した HTML は tools/manual/capture.js が画像にする。
 *
 * @package EquiTicket
 */

// phpcs:disable -- 開発用のスクリプトのため、コーディング規約の対象外。

require __DIR__ . '/wp-stubs.php';

$out = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : __DIR__ . '/out';
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}

foreach ( array_merge( glob( ABSPATH . 'includes/*.php' ), glob( ABSPATH . 'includes/qr/*.php' ), glob( ABSPATH . 'includes/admin/*.php' ) ) as $file ) {
	require_once $file;
}

// 当日の午前 10 時 25 分という想定で画面を作る。
$event_date = et_freeze_clock( '10:25' );

// 自動の無断キャンセル処理は、サンプルの状態を崩さないよう止めておく。
set_transient( 'equiticket_sweep_lock', 1 );

/* ------------------------------------------------------------------ *
 * 設定
 * ------------------------------------------------------------------ */

$GLOBALS['et_options'] = array_merge(
	EquiTicket_Options::defaults(),
	array(
		'event_name'   => 'さくら祭 体験乗馬コーナー',
		'page_id'      => 12,
		'ride_minutes' => 5,
		'venue_text'   => "中央公園 多目的広場（正面入口から入って右手）\n受付テントは馬場の入口にあります。",
		'mail_from_name'  => 'サンプル乗馬クラブ',
		'mail_from_email' => 'info@example.jp',
		'mail_footer'     => "サンプル乗馬クラブ\nお問い合わせ: info@example.jp",
	)
);

/* ------------------------------------------------------------------ *
 * 馬の種類（初期のポニーと馬を登録し、説明を入れる）
 * ------------------------------------------------------------------ */

EquiTicket_Install::seed_horses();
$horse_ids = array();
foreach ( EquiTicket_Horses::all() as $horse ) {
	$horse_ids[ $horse->sort_order <= 10 ? 'pony' : 'horse' ] = $horse->id;
}
$pony_id  = $horse_ids['pony'];
$horse_id = $horse_ids['horse'];

/* ------------------------------------------------------------------ *
 * 時間枠（一括作成を実際の関数で行う）
 * ------------------------------------------------------------------ */

EquiTicket_Slots::bulk_create(
	array(
		'event_date'     => $event_date,
		'start_time'     => '10:00',
		'end_time'       => '12:00',
		'slot_minutes'   => 20,
		'break_minutes'  => 0,
		'horses'         => array(
			$pony_id  => array( 'horse_count' => 1, 'capacity' => 4 ),
			$horse_id => array( 'horse_count' => 1, 'capacity' => 4 ),
		),
	)
);
EquiTicket_Slots::bulk_create(
	array(
		'event_date'     => $event_date,
		'start_time'     => '13:00',
		'end_time'       => '14:00',
		'slot_minutes'   => 20,
		'break_minutes'  => 0,
		'horses'         => array(
			$pony_id  => array( 'horse_count' => 1, 'capacity' => 3 ),
			$horse_id => array( 'horse_count' => 2, 'capacity' => 6 ),
		),
	)
);

$slots = EquiTicket_Slots::query( array( 'date' => $event_date ) );
$by_time = array();
foreach ( $slots as $slot ) {
	$by_time[ substr( $slot->start_time, 0, 5 ) ] = $slot;
}

// 11:40 の枠は午後の準備のため受付停止、13:40 の枠は雨天時中止の例。
EquiTicket_Slots::update( $by_time['11:40']->id, array( 'event_date' => $event_date, 'start_time' => '11:40', 'end_time' => '12:00', 'horses' => array( $horse_id => array( 'horse_count' => 1, 'capacity' => 2 ) ), 'status' => 'closed', 'note' => '馬の休憩のため受付停止' ) );
EquiTicket_Slots::update( $by_time['13:40']->id, array( 'event_date' => $event_date, 'start_time' => '13:40', 'end_time' => '14:00', 'horses' => array( $pony_id => array( 'horse_count' => 1, 'capacity' => 3 ), $horse_id => array( 'horse_count' => 2, 'capacity' => 6 ) ), 'status' => 'open', 'note' => '雨天時は中止' ) );

/* ------------------------------------------------------------------ *
 * 予約（実際の予約処理で作る）。時間チェックは当日なので外す。
 * ------------------------------------------------------------------ */

$people = array(
	// 時刻, 名前, 身長, 体重, 年齢, 希望の馬, 支払い, 写真NG
	array( '10:00', '山田 太郎', 118, 22, 7, 'pony', 1, 0 ),
	array( '10:00', '佐藤 花子', 162, 52, 20, 'horse', 0, 0 ),
	array( '10:00', '鈴木 一郎', 175, 68, 45, 'horse', 0, 1 ),
	array( '10:00', '高橋 さくら', 128, 27, 9, 'pony', 1, 0 ),
	array( '10:20', '田中 陽向', 135, 31, 11, 'horse', 0, 0 ),
	array( '10:20', '伊藤 結衣', 158, 48, 16, 'horse', 0, 0 ),
	array( '10:20', '渡辺 蓮', 112, 19, 6, 'pony', 1, 0 ),
	array( '10:40', '中村 美咲', 160, 50, 34, 'horse', 0, 0 ),
	array( '10:40', '小林 大和', 124, 24, 8, 'pony', 1, 1 ),
	array( '10:40', '加藤 真央', 166, 55, 19, 'horse', 0, 0 ),
	array( '11:00', '吉田 悠真', 142, 36, 12, 'horse', 0, 0 ),
	array( '11:00', '山本 葵', 131, 29, 10, 'pony', 1, 0 ),
	array( '11:20', '松本 健', 178, 72, 50, 'horse', 0, 0 ),
	array( '13:00', '井上 陽菜', 120, 23, 7, 'pony', 1, 0 ),
	array( '13:00', '木村 翔', 170, 63, 21, 'horse', 0, 0 ),
	array( '13:20', '林 美月', 155, 45, 14, 'horse', 0, 0 ),
);

$created = array();
foreach ( $people as $index => $p ) {
	$slot = $by_time[ $p[0] ];
	$r    = EquiTicket_Reservations::create(
		array(
			'slot_id'           => $slot->id,
			'name'              => $p[1],
			'email'             => 'guest' . ( $index + 1 ) . '@example.jp',
			'height_cm'         => $p[2],
			'weight_kg'         => $p[3],
			'age'               => $p[4],
			'horse_id'          => 'pony' === $p[5] ? $pony_id : $horse_id,
			'photo_optout'      => $p[7],
			'guardian_consent'  => $p[4] < 18 ? 1 : 0,
			'consent'           => 1,
			'source'            => 'web',
			'bypass_time_check' => true,
		)
	);
	if ( is_wp_error( $r ) ) {
		fwrite( STDERR, "予約の作成に失敗: {$p[1]} " . $r->get_error_message() . "\n" );
		continue;
	}
	$created[ $p[1] ] = $r;
}

/** 予約の状態と日時を直接整える（サンプル用）。 */
function et_set_status( $reservation, $status, $time = null, $mail = true ) {
	global $wpdb, $event_date;
	$data = array( 'status' => $status, 'mail_sent_at' => $mail ? $event_date . ' 08:00:00' : null );
	if ( 'checked_in' === $status ) {
		$data['checked_in_at'] = $event_date . ' ' . $time . ':00';
	}
	if ( in_array( $status, array( 'cancelled', 'no_show' ), true ) ) {
		$data['cancelled_at'] = $event_date . ' ' . $time . ':00';
	}
	$wpdb->update( 'wp_equiticket_reservations', $data, array( 'id' => $reservation->id ) );
}

foreach ( $created as $r ) {
	et_set_status( $r, 'reserved' );
}
et_set_status( $created['山田 太郎'], 'checked_in', '09:52' );
et_set_status( $created['佐藤 花子'], 'checked_in', '09:55' );
et_set_status( $created['鈴木 一郎'], 'checked_in', '09:58' );
et_set_status( $created['高橋 さくら'], 'no_show', '10:10' );
et_set_status( $created['田中 陽向'], 'checked_in', '10:14' );
et_set_status( $created['伊藤 結衣'], 'checked_in', '10:17' );
et_set_status( $created['中村 美咲'], 'cancelled', '08:30' );
et_set_status( $created['松本 健'], 'reserved', null, false );

// 当日枠（スタッフ登録）の例。
$walkin = EquiTicket_Reservations::create(
	array(
		'slot_id'           => $by_time['10:20']->id,
		'name'              => '清水 家族',
		'people_count'      => 2,
		'horse_id'          => $pony_id,
		'note'              => '姉妹で参加',
		'source'            => 'staff',
		'bypass_time_check' => true,
	)
);
if ( ! is_wp_error( $walkin ) ) {
	et_set_status( $walkin, 'checked_in', '10:21', false );
}

/* ------------------------------------------------------------------ *
 * 描画
 * ------------------------------------------------------------------ */

/** 描画結果を保存する。 */
function et_capture( $name, callable $render ) {
	global $out;

	// 前の画面のエラー表示が残らないよう、来場者画面のエラーを毎回空にする。
	$errors = new ReflectionProperty( 'EquiTicket_Frontend', 'errors' );
	$errors->setAccessible( true );
	$errors->setValue( null, array() );

	ob_start();
	$html = $render();
	$echoed = ob_get_clean();
	file_put_contents( "{$out}/{$name}.html", $echoed . ( is_string( $html ) ? $html : '' ) );
}

function et_reset_request() {
	$_GET  = array();
	$_POST = array();
}

// 管理画面: 当日ダッシュボード
et_reset_request();
$_GET['date'] = $event_date;
et_capture( 'admin-dashboard', function () { EquiTicket_Admin_Dashboard::render(); } );

// 管理画面: 当日予約（直前に登録した当日枠の確認表示つき）
et_reset_request();
$_GET['done'] = $walkin->id;
et_capture( 'admin-walkin', function () { EquiTicket_Admin_Walkin::render(); } );

// 管理画面: 馬の種類
et_reset_request();
et_capture( 'admin-horses', function () { EquiTicket_Admin_Horses::render(); } );

// 管理画面: 時間枠（一覧と追加フォーム）
et_reset_request();
$_GET['date'] = $event_date;
et_capture( 'admin-slots', function () { EquiTicket_Admin_Slots::render(); } );

// 管理画面: 時間枠（編集）
et_reset_request();
$_GET['edit'] = $by_time['10:40']->id;
et_capture( 'admin-slots-edit', function () { EquiTicket_Admin_Slots::render(); } );

// 管理画面: 名簿印刷
et_reset_request();
$_GET['date'] = $event_date;
et_capture( 'admin-roster', function () { EquiTicket_Admin_Roster::render(); } );

// 管理画面: 設定
et_reset_request();
$GLOBALS['et_users'] = array(
	new WP_User( 5, 'bajutsu-uketsuke', 'club@example.jp', array( 'equiticket_staff' ) ),
	new WP_User( 6, 'bajutsu-unei', 'club-lead@example.jp', array( 'equiticket_manager' ) ),
);
et_capture( 'admin-settings', function () { EquiTicket_Admin_Settings::render(); } );

// 受付画面（照合結果 OK ＋ 氏名検索の結果）
$result_method = new ReflectionMethod( 'EquiTicket_Checkin', 'result' );
$result_method->setAccessible( true );
$ok = $result_method->invoke( null, 'ok', 'OK', '', EquiTicket_Reservations::get( $created['山田 太郎']->id ), $by_time['10:00'] );
et_reset_request();
set_transient( 'equiticket_checkin_result_1', $ok );
$_GET['et_search'] = '渡辺';
et_capture( 'checkin', function () { return EquiTicket_Checkin::render_screen( admin_url( 'admin.php?page=equiticket-checkin' ) ); } );

// 受付画面の結果パターン
$patterns = array(
	'already'   => array( 'already', '使用済み', 'すでに 09:52 に受付済みです。', $created['山田 太郎'] ),
	'not_found' => array( 'not_found', '該当なし', 'この QR は当システムの整理券ではありません。名簿で手動照合してください。', null ),
	'no_show'   => array( 'no_show', '時間切れ', '受付締切を過ぎたため当日枠として解放されています。空きがあれば「それでも来場済みにする」で受付できます。', $created['高橋 さくら'] ),
);
foreach ( $patterns as $key => $p ) {
	$res = $p[3] ? EquiTicket_Reservations::get( $p[3]->id ) : null;
	$data = $result_method->invoke( null, $p[0], $p[1], $p[2], $res, $res ? EquiTicket_Slots::get( $res->slot_id ) : null );
	set_transient( 'equiticket_checkin_result_1', $data );
	$box = new ReflectionMethod( 'EquiTicket_Checkin', 'render_result_box' );
	$box->setAccessible( true );
	et_capture( 'checkin-result-' . $key, function () use ( $box ) {
		return $box->invoke( null, get_transient( 'equiticket_checkin_result_1' ), admin_url( 'admin.php?page=equiticket-checkin' ) );
	} );
}

// 来場者の画面は、時間枠の一覧に空きが見えるよう、受付開始直後（午前 10 時 5 分）の想定にする。
if ( et_freeze_clock( '10:05' ) !== $event_date ) {
	et_freeze_clock( '10:25' );
}

// 来場者画面: ステップ 1
et_reset_request();
et_capture( 'front-step1', function () { return EquiTicket_Frontend::render_reservation(); } );

// 来場者画面: ステップ 1 の入力エラー（体重超過）
et_reset_request();
$_POST = array( 'equiticket_action' => 'screen', 'equiticket_nonce' => 'x', 'height_cm' => '172', 'weight_kg' => '86', 'age' => '40' );
et_capture( 'front-step1-error', function () { return EquiTicket_Frontend::render_reservation(); } );

// 来場者画面: 乗る馬の選択（どちらも可）
et_reset_request();
$_POST = array( 'equiticket_action' => 'screen', 'equiticket_nonce' => 'x', 'height_cm' => '130', 'weight_kg' => '30', 'age' => '10' );
et_capture( 'front-choice', function () { return EquiTicket_Frontend::render_reservation(); } );

// 来場者画面: 時間枠の一覧（ポニーを選んだ場合）
et_reset_request();
$_POST = array( 'equiticket_action' => 'screen', 'equiticket_nonce' => 'x', 'height_cm' => '130', 'weight_kg' => '30', 'age' => '10', 'horse_id' => $pony_id );
et_capture( 'front-step2', function () { return EquiTicket_Frontend::render_reservation(); } );

// 来場者画面: 申込み・同意
et_reset_request();
$_POST = array( 'equiticket_action' => 'choose', 'equiticket_nonce' => 'x', 'height_cm' => '130', 'weight_kg' => '30', 'age' => '10', 'horse_id' => $pony_id, 'slot_id' => $by_time['10:40']->id );
et_capture( 'front-step3', function () { return EquiTicket_Frontend::render_reservation(); } );

// 来場者画面: 整理券
et_reset_request();
$ticket = EquiTicket_Reservations::get( $created['小林 大和']->id );
$_GET = array( 'et_token' => $ticket->qr_token, 'et_msg' => 'reserved' );
et_capture( 'front-ticket', function () { return EquiTicket_Frontend::render_reservation(); } );

// 確認メール（HTML 版）
$mail = new ReflectionMethod( 'EquiTicket_Mailer', 'build_html_body' );
$mail->setAccessible( true );
$ticket_slot = EquiTicket_Slots::get( $ticket->slot_id );
$qr_img = '<img src="' . EquiTicket_QR::data_uri( EquiTicket_Frontend::ticket_url( $ticket->qr_token ) ) . '" alt="" width="200" height="200" style="display:block;border:1px solid #ddd;padding:8px;background:#fff;" />';
et_capture( 'mail-html', function () use ( $mail, $ticket, $ticket_slot, $qr_img ) {
	return $mail->invoke( null, $ticket, $ticket_slot, EquiTicket_Frontend::ticket_url( $ticket->qr_token ), $qr_img );
} );

// 画面の説明に使う値
file_put_contents(
	"{$out}/meta.json",
	json_encode(
		array(
			'event_date'  => $event_date,
			'event_label' => EquiTicket_Utils::date_label( $event_date ),
			'theme_css'   => EquiTicket_Options::theme_css(),
		),
		JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
	)
);

echo "screens rendered to {$out}\n";
