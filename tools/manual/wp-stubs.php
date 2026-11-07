<?php
/**
 * マニュアル用の画面例を作るための、WordPress の最小限の代用品。
 *
 * プラグインの描画処理（管理画面・来場者画面・メール本文）を WordPress なしで
 * 実行し、実際の出力をサンプルデータで取り出すために使う。データベースは
 * SQLite（メモリ上）で代用する。本番のプラグインからは読み込まない。
 *
 * @package EquiTicket
 */

// phpcs:disable -- 開発用の代用品のため、コーディング規約の対象外。

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
date_default_timezone_set( 'Asia/Tokyo' );

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'EQUITICKET_VERSION', '1.0.0' );
define( 'EQUITICKET_DB_VERSION', '7' );
define( 'EQUITICKET_PATH', ABSPATH );
define( 'EQUITICKET_URL', 'https://example.jp/wp-content/plugins/equiticket/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/* ------------------------------------------------------------------ *
 * 状態（オプション・トランジェント・ユーザー）
 * ------------------------------------------------------------------ */

$GLOBALS['et_options']    = array();
$GLOBALS['et_transients'] = array();
$GLOBALS['et_caps']       = array( '*' => true );
$GLOBALS['et_users']      = array();

/* ------------------------------------------------------------------ *
 * データベース（SQLite で MySQL の代わりをする）
 * ------------------------------------------------------------------ */

final class ET_Fake_WPDB {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $last_error = '';
	private $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( "CREATE TABLE wp_equiticket_time_slots (
			id INTEGER PRIMARY KEY AUTOINCREMENT, event_date TEXT NOT NULL, start_time TEXT NOT NULL, end_time TEXT NOT NULL,
			capacity INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'open',
			note TEXT NOT NULL DEFAULT '', created_at TEXT, updated_at TEXT )" );
		$this->pdo->exec( "CREATE TABLE wp_equiticket_reservations (
			id INTEGER PRIMARY KEY AUTOINCREMENT, slot_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
			people_count INTEGER NOT NULL DEFAULT 1, horse_id INTEGER NOT NULL DEFAULT 0, horse_name TEXT NOT NULL DEFAULT '', fee_amount INTEGER NOT NULL DEFAULT 0,
			fee_label TEXT NOT NULL DEFAULT '', height_cm INTEGER NOT NULL DEFAULT 0, weight_kg INTEGER NOT NULL DEFAULT 0,
			age INTEGER NOT NULL DEFAULT 0, guardian_consent INTEGER NOT NULL DEFAULT 0, photo_optout INTEGER NOT NULL DEFAULT 0,
			qr_token TEXT NOT NULL DEFAULT '' UNIQUE, status TEXT NOT NULL DEFAULT 'reserved', source TEXT NOT NULL DEFAULT 'web',
			note TEXT NOT NULL DEFAULT '', created_at TEXT, updated_at TEXT, checked_in_at TEXT, cancelled_at TEXT, mail_sent_at TEXT )" );
		$this->pdo->exec( "CREATE TABLE wp_equiticket_horses (
			id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '',
			min_age INTEGER NOT NULL DEFAULT 0, max_age INTEGER NOT NULL DEFAULT 0, min_height INTEGER NOT NULL DEFAULT 0, max_height INTEGER NOT NULL DEFAULT 0,
			min_weight INTEGER NOT NULL DEFAULT 0, max_weight INTEGER NOT NULL DEFAULT 0, fee_amount INTEGER NOT NULL DEFAULT 0,
			sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT )" );
		$this->pdo->exec( "CREATE TABLE wp_equiticket_slot_horses (
			id INTEGER PRIMARY KEY AUTOINCREMENT, slot_id INTEGER NOT NULL, horse_id INTEGER NOT NULL,
			horse_count INTEGER NOT NULL DEFAULT 0, capacity INTEGER NOT NULL DEFAULT 0, UNIQUE ( slot_id, horse_id ) )" );
	}

	/** MySQL だけの書き方を SQLite 向けに直す。 */
	private function translate( $sql ) {
		$sql = preg_replace( '/\s+FOR UPDATE\b/i', '', $sql );
		$sql = preg_replace( '/^\s*START TRANSACTION\s*$/i', 'BEGIN', $sql );

		return $sql;
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$pdo = $this->pdo;
		$i   = 0;

		return preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( &$i, $args, $pdo ) {
				$value = array_key_exists( $i, $args ) ? $args[ $i ] : '';
				$i++;
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				if ( '%f' === $m[0] ) {
					return (string) (float) $value;
				}

				return $pdo->quote( (string) $value );
			},
			$query
		);
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
	}

	public function query( $sql ) {
		$sql = $this->translate( $sql );
		try {
			return $this->pdo->exec( $sql );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	private function fetch( $sql ) {
		$stmt = $this->pdo->query( $this->translate( $sql ) );

		return $stmt->fetchAll( PDO::FETCH_OBJ );
	}

	public function get_results( $sql ) {
		return $this->fetch( $sql );
	}

	public function get_row( $sql ) {
		$rows = $this->fetch( $sql );

		return $rows ? $rows[0] : null;
	}

	public function get_var( $sql ) {
		$stmt = $this->pdo->query( $this->translate( $sql ) );
		$val  = $stmt->fetchColumn();

		return false === $val ? null : $val;
	}

	public function get_col( $sql ) {
		$stmt = $this->pdo->query( $this->translate( $sql ) );

		return $stmt->fetchAll( PDO::FETCH_COLUMN );
	}

	public function insert( $table, $data, $format = null ) {
		$cols = array_keys( $data );
		$vals = array_map( function ( $v ) { return null === $v ? 'NULL' : $this->pdo->quote( (string) $v ); }, array_values( $data ) );
		try {
			$ok = $this->pdo->exec( sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $table, implode( ',', $cols ), implode( ',', $vals ) ) );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
		$this->insert_id = (int) $this->pdo->lastInsertId();

		return $ok;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set = array();
		foreach ( $data as $k => $v ) {
			$set[] = $k . '=' . ( null === $v ? 'NULL' : $this->pdo->quote( (string) $v ) );
		}
		$cond = array();
		foreach ( $where as $k => $v ) {
			$cond[] = $k . '=' . $this->pdo->quote( (string) $v );
		}

		return $this->pdo->exec( sprintf( 'UPDATE %s SET %s WHERE %s', $table, implode( ',', $set ), implode( ' AND ', $cond ) ) );
	}

	public function delete( $table, $where, $format = null ) {
		$cond = array();
		foreach ( $where as $k => $v ) {
			$cond[] = $k . '=' . $this->pdo->quote( (string) $v );
		}

		return $this->pdo->exec( sprintf( 'DELETE FROM %s WHERE %s', $table, implode( ' AND ', $cond ) ) );
	}
}

$GLOBALS['wpdb'] = new ET_Fake_WPDB();

/* ------------------------------------------------------------------ *
 * WordPress 関数の代用品
 * ------------------------------------------------------------------ */

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

class WP_User {
	public $ID;
	public $user_login;
	public $user_email;
	public $roles = array();
	public function __construct( $id, $login, $email, $roles ) { $this->ID = $id; $this->user_login = $login; $this->user_email = $email; $this->roles = $roles; }
	public function exists() { return $this->ID > 0; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return esc_html( $text ); }
function esc_attr__( $text, $domain = 'default' ) { return esc_attr( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url, $protocols = null ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function esc_url_raw( $url ) { return (string) $url; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_email( $v ) { return trim( (string) $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_user( $v, $strict = false ) { return preg_replace( '/[^A-Za-z0-9_\-\.@]/', '', (string) $v ); }
function sanitize_html_class( $v ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $v ); }
function sanitize_hex_color( $c ) { return preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $c ) ? $c : ''; }
function absint( $v ) { return abs( (int) $v ); }
function is_email( $v ) { return (bool) filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function wp_strip_all_tags( $v ) { return strip_tags( (string) $v ); }
function wp_specialchars_decode( $v ) { return htmlspecialchars_decode( (string) $v ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
function wp_list_pluck( $list, $field ) { return array_map( function ( $r ) use ( $field ) { return is_array( $r ) ? $r[ $field ] : $r->$field; }, $list ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
/**
 * サイトのタイムゾーン。
 *
 * 画面例では「イベント当日の午前 10 時 25 分」を再現したいので、実際の時刻との差を
 * 固定オフセットのタイムゾーンとして与え、プラグインから見える「今」をずらす。
 */
function wp_timezone() {
	return new DateTimeZone( isset( $GLOBALS['et_tz'] ) ? $GLOBALS['et_tz'] : 'Asia/Tokyo' );
}

/**
 * プラグインから見た「今」を、指定した時刻（H:i）になるようにずらす。
 *
 * @param string $clock 例: '10:25'。
 * @return string その日の日付（Y-m-d）。
 */
function et_freeze_clock( $clock ) {
	$now_utc = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	list( $h, $m ) = array_map( 'intval', explode( ':', $clock ) );

	foreach ( array( 0, 1, -1 ) as $shift ) {
		$target = $now_utc->setTime( $h, $m )->modify( sprintf( '%+d day', $shift ) );
		$offset = (int) round( ( $target->getTimestamp() - $now_utc->getTimestamp() ) / 60 );
		if ( $offset >= -12 * 60 && $offset <= 14 * 60 ) {
			$sign             = $offset < 0 ? '-' : '+';
			$GLOBALS['et_tz'] = sprintf( '%s%02d:%02d', $sign, intdiv( abs( $offset ), 60 ), abs( $offset ) % 60 );

			return ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
		}
	}

	return date( 'Y-m-d' );
}
function wp_date( $format, $ts = null ) {
	$dt = new DateTime( '@' . ( null === $ts ? time() : $ts ) );
	$dt->setTimezone( wp_timezone() );
	$out = $dt->format( $format );

	return strtr( $out, array( 'Sun' => '日', 'Mon' => '月', 'Tue' => '火', 'Wed' => '水', 'Thu' => '木', 'Fri' => '金', 'Sat' => '土' ) );
}
function current_time( $type ) { return wp_date( 'mysql' === $type ? 'Y-m-d H:i:s' : $type ); }
function wp_generate_password( $len = 12, $special = true, $extra = false ) { return substr( 'Kp7vQx2mN4rT9wZa', 0, $len ); }

function get_option( $name, $default = false ) {
	if ( 'equiticket_settings' === $name ) {
		return $GLOBALS['et_options'];
	}

	return $default;
}
function update_option( $name, $value, $autoload = null ) { if ( 'equiticket_settings' === $name ) { $GLOBALS['et_options'] = $value; } return true; }
function add_option( $name, $value ) { return true; }
function delete_option( $name ) { return true; }
function get_transient( $key ) { return isset( $GLOBALS['et_transients'][ $key ] ) ? $GLOBALS['et_transients'][ $key ] : false; }
function set_transient( $key, $value, $exp = 0 ) { $GLOBALS['et_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['et_transients'][ $key ] ); return true; }

function add_action() { return true; }
function add_filter() { return true; }
function remove_action() { return true; }
function remove_filter() { return true; }
function do_action() {}
function apply_filters( $tag, $value ) { return $value; }
function add_shortcode() {}
function has_filter() { return false; }

function current_user_can( $cap ) { return ! empty( $GLOBALS['et_caps']['*'] ) || ! empty( $GLOBALS['et_caps'][ $cap ] ); }
function user_can( $user, $cap ) { return current_user_can( $cap ); }
function get_current_user_id() { return 1; }
function wp_get_current_user() { return new WP_User( 1, 'admin', 'admin@example.jp', array( 'administrator' ) ); }
function get_users( $args = array() ) { return $GLOBALS['et_users']; }
function get_edit_user_link( $id ) { return 'https://example.jp/wp-admin/user-edit.php?user_id=' . (int) $id; }

function admin_url( $path = '' ) { return 'https://example.jp/wp-admin/' . ltrim( $path, '/' ); }
function home_url( $path = '' ) { return 'https://example.jp' . $path; }
function get_permalink( $id = 0 ) { return 'https://example.jp/riding/'; }
function get_post_status( $id ) { return 'publish'; }
function wp_login_url( $redirect = '' ) { return 'https://example.jp/wp-login.php'; }
function add_query_arg( $args, $url ) {
	$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';

	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $keys, $url ) { return preg_replace( '/[?&](' . implode( '|', (array) $keys ) . ')=[^&]*/', '', $url ); }

function wp_create_nonce( $action = '' ) { return 'nonce123'; }
function wp_verify_nonce( $nonce, $action = '' ) { return 1; }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce123" />';
	if ( $echo ) {
		echo $html;
	}

	return $html;
}
function selected( $a, $b, $echo = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function checked( $a, $b, $echo = true ) { $r = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function submit_button( $text = '変更を保存', $type = 'primary' ) {
	$class = 'secondary' === $type ? 'button' : 'button button-primary';
	echo '<p class="submit"><input type="submit" class="' . esc_attr( $class ) . '" value="' . esc_attr( $text ) . '" /></p>';
}
function wp_dropdown_pages( $args ) {
	echo '<select name="' . esc_attr( $args['name'] ) . '"><option value="0">— 選択してください —</option>';
	echo '<option value="12" selected="selected">体験乗馬 予約</option><option value="13">体験乗馬 受付</option></select>';
}

function wp_next_scheduled( $hook ) { return strtotime( '+3 minutes' ); }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_register_style() {}
function wp_register_script() {}
function wp_localize_script() {}
function wp_add_inline_style() {}
function wp_add_inline_script() {}
function get_current_screen() { return (object) array( 'id' => 'toplevel_page_equiticket-dashboard' ); }
function wp_doing_ajax() { return false; }
function is_admin() { return true; }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function wp_delete_file( $f ) { @unlink( $f ); }
function get_bloginfo( $k = '' ) { return 'サンプル乗馬クラブ'; }
