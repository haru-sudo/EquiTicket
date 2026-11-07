<?php
/**
 * 無断キャンセルの自動解放（WP-Cron）。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 締切を過ぎた未来場の予約を当日枠として解放する仕組み。
 */
final class EquiTicket_Cron {

	/**
	 * Cron フック名。
	 */
	const HOOK = 'equiticket_sweep';

	/**
	 * 独自スケジュール名。
	 */
	const SCHEDULE = 'equiticket_five_minutes';

	/**
	 * 最終実行時刻を保存するオプション名。
	 */
	const LAST_RUN_OPTION = 'equiticket_last_sweep';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 当日運営中は 5 分間隔で枠を解放する必要がある。
		add_action( self::HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * 5 分間隔のスケジュールを追加する。
	 *
	 * @param array<string,array<string,mixed>> $schedules 既存のスケジュール。
	 * @return array<string,array<string,mixed>> スケジュール。
	 */
	public static function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( '5 分ごと（EquiTicket）', 'equiticket' ),
		);

		return $schedules;
	}

	/**
	 * Cron を登録する（未登録のときだけ）。
	 *
	 * @return void
	 */
	public static function schedule() {
		// 有効化時は plugins_loaded を過ぎているため init() のフィルター登録が走らない。
		// 独自スケジュールが未登録だと wp_schedule_event() が失敗するので、ここで補う。
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 当日運営中は 5 分間隔で枠を解放する必要がある。
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Cron を解除する。
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * 締切を過ぎた未来場予約を無断キャンセルにする。
	 *
	 * @return int 解放した件数。
	 */
	public static function sweep() {
		$released = EquiTicket_Reservations::release_no_shows( EquiTicket_Utils::today() );
		update_option( self::LAST_RUN_OPTION, EquiTicket_Utils::now_for_db(), false );

		return $released;
	}

	/**
	 * WP-Cron が動いていない環境でも枠が解放されるよう、
	 * 画面表示のついでに 1 分に 1 回まで解放処理を走らせる。
	 *
	 * @return void
	 */
	public static function maybe_sweep() {
		if ( get_transient( 'equiticket_sweep_lock' ) ) {
			return;
		}
		set_transient( 'equiticket_sweep_lock', 1, MINUTE_IN_SECONDS );

		self::sweep();
	}

	/**
	 * 最終実行時刻を返す。
	 *
	 * @return string 日時（未実行なら空文字）。
	 */
	public static function last_run() {
		$value = get_option( self::LAST_RUN_OPTION, '' );

		return is_string( $value ) ? $value : '';
	}
}
