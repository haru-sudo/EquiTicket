<?php
/**
 * テーブル名の解決。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * プラグインが使うテーブル名を提供するクラス。
 */
final class EquiTicket_DB {

	/**
	 * 時間枠テーブル名を返す。
	 *
	 * @return string テーブル名。
	 */
	public static function slots_table() {
		global $wpdb;

		return $wpdb->prefix . 'equiticket_time_slots';
	}

	/**
	 * 予約テーブル名を返す。
	 *
	 * @return string テーブル名。
	 */
	public static function reservations_table() {
		global $wpdb;

		return $wpdb->prefix . 'equiticket_reservations';
	}

	/**
	 * 馬の種類テーブル名を返す。
	 *
	 * @return string テーブル名。
	 */
	public static function horses_table() {
		global $wpdb;

		return $wpdb->prefix . 'equiticket_horses';
	}

	/**
	 * 時間枠ごとの馬（頭数・定員）テーブル名を返す。
	 *
	 * @return string テーブル名。
	 */
	public static function slot_horses_table() {
		global $wpdb;

		return $wpdb->prefix . 'equiticket_slot_horses';
	}
}
