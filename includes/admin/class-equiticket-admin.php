<?php
/**
 * 管理画面の土台（メニュー・通知・アセット）。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 管理メニューを組み立て、各ページの登録と共通処理を行うクラス。
 */
final class EquiTicket_Admin {

	/**
	 * 管理通知を一時保存する transient のプレフィックス。
	 */
	const NOTICE_TRANSIENT = 'equiticket_notices_';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_setup_hints' ) );
		add_action( 'admin_post_equiticket_run_sweep', array( __CLASS__, 'handle_run_sweep' ) );
		add_action( 'admin_post_equiticket_create_page', array( __CLASS__, 'handle_create_page' ) );

		EquiTicket_Admin_Horses::init();
		EquiTicket_Admin_Slots::init();
		EquiTicket_Admin_Dashboard::init();
		EquiTicket_Admin_Walkin::init();
		EquiTicket_Admin_Mail::init();
		EquiTicket_Admin_Roster::init();
		EquiTicket_Admin_Settings::init();
	}

	/**
	 * 管理メニューを登録する。
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( '体験乗馬 整理券', 'equiticket' ),
			__( '体験乗馬', 'equiticket' ),
			EquiTicket_Utils::CAP_CHECKIN,
			'equiticket-dashboard',
			array( 'EquiTicket_Admin_Dashboard', 'render' ),
			'dashicons-tickets-alt',
			56
		);

		add_submenu_page(
			'equiticket-dashboard',
			__( '当日ダッシュボード', 'equiticket' ),
			__( '当日ダッシュボード', 'equiticket' ),
			EquiTicket_Utils::CAP_CHECKIN,
			'equiticket-dashboard',
			array( 'EquiTicket_Admin_Dashboard', 'render' )
		);

		add_submenu_page(
			'equiticket-dashboard',
			__( '過去の記録', 'equiticket' ),
			__( '過去の記録', 'equiticket' ),
			EquiTicket_Utils::CAP_CHECKIN,
			'equiticket-records',
			array( 'EquiTicket_Admin_Dashboard', 'render_records' )
		);

		add_submenu_page(
			'equiticket-dashboard',
			__( '受付（QR チェックイン）', 'equiticket' ),
			__( '受付', 'equiticket' ),
			EquiTicket_Utils::CAP_CHECKIN,
			'equiticket-checkin',
			array( __CLASS__, 'render_checkin_page' )
		);

		add_submenu_page(
			'equiticket-dashboard',
			__( '当日予約', 'equiticket' ),
			__( '当日予約', 'equiticket' ),
			EquiTicket_Utils::CAP_CHECKIN,
			'equiticket-walkin',
			array( 'EquiTicket_Admin_Walkin', 'render' )
		);

		add_submenu_page(
			'equiticket-dashboard',
			__( '馬の種類', 'equiticket' ),
			__( '馬の種類', 'equiticket' ),
			EquiTicket_Utils::CAP_MANAGE,
			'equiticket-horses',
			array( 'EquiTicket_Admin_Horses', 'render' )
		);

		add_submenu_page(
			'equiticket-dashboard',
			__( '時間枠の管理', 'equiticket' ),
			__( '時間枠', 'equiticket' ),
			EquiTicket_Utils::CAP_MANAGE,
			'equiticket-slots',
			array( 'EquiTicket_Admin_Slots', 'render' )
		);

		add_submenu_page(
			'equiticket-dashboard',
			__( '名簿印刷（バックアップ）', 'equiticket' ),
			__( '名簿印刷', 'equiticket' ),
			EquiTicket_Utils::CAP_CHECKIN,
			'equiticket-roster',
			array( 'EquiTicket_Admin_Roster', 'render' )
		);

		add_submenu_page(
			'equiticket-dashboard',
			__( '一斉メール', 'equiticket' ),
			__( '一斉メール', 'equiticket' ),
			EquiTicket_Utils::CAP_MANAGE,
			'equiticket-mail',
			array( 'EquiTicket_Admin_Mail', 'render' )
		);

		add_submenu_page(
			'equiticket-dashboard',
			__( 'EquiTicket 設定', 'equiticket' ),
			__( '設定', 'equiticket' ),
			EquiTicket_Utils::CAP_MANAGE,
			'equiticket-settings',
			array( 'EquiTicket_Admin_Settings', 'render' )
		);
	}

	/**
	 * 管理画面用のスタイルとスクリプトを読み込む。
	 *
	 * @param string $hook_suffix 現在の管理画面。
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'equiticket' ) ) {
			return;
		}

		wp_enqueue_style( 'equiticket-front', EQUITICKET_URL . 'assets/css/front.css', array(), EQUITICKET_VERSION );
		wp_add_inline_style( 'equiticket-front', EquiTicket_Options::theme_css() );
		wp_enqueue_style( 'equiticket-admin', EQUITICKET_URL . 'assets/css/admin.css', array( 'equiticket-front' ), EQUITICKET_VERSION );

		if ( false !== strpos( $hook_suffix, 'equiticket-settings' ) ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'wp-color-picker' );
			wp_add_inline_script(
				'wp-color-picker',
				'jQuery( function ( $ ) { $( ".equiticket-color-field" ).wpColorPicker(); } );'
			);
		}

		if ( false !== strpos( $hook_suffix, 'equiticket-checkin' ) ) {
			wp_register_script( 'equiticket-jsqr', EQUITICKET_URL . 'assets/js/vendor/jsqr.min.js', array(), '1.4.0', true );
			wp_enqueue_script( 'equiticket-checkin', EQUITICKET_URL . 'assets/js/checkin.js', array( 'equiticket-jsqr' ), EQUITICKET_VERSION, true );
			EquiTicket_Checkin::localize_checkin_script();
		}

		wp_enqueue_script( 'equiticket-admin', EQUITICKET_URL . 'assets/js/admin.js', array(), EQUITICKET_VERSION, true );
	}

	/**
	 * 受付ページを出力する。
	 *
	 * @return void
	 */
	public static function render_checkin_page() {
		if ( ! EquiTicket_Utils::can_check_in() ) {
			wp_die( esc_html__( '受付操作の権限がありません。', 'equiticket' ), 403 );
		}

		$redirect = admin_url( 'admin.php?page=equiticket-checkin' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 検索条件の引き継ぎのみ。
		if ( ! empty( $_GET['et_search'] ) ) {
			$redirect = add_query_arg( 'et_search', sanitize_text_field( wp_unslash( $_GET['et_search'] ) ), $redirect );
		}

		echo '<div class="wrap equiticket-wrap"><div class="equiticket equiticket--checkin">';
		echo EquiTicket_Checkin::render_screen( $redirect ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部で個別にエスケープ済み。
		echo '</div></div>';
	}

	/**
	 * 管理通知を追加する。
	 *
	 * @param string $message メッセージ。
	 * @param string $type    success / error / warning / info。
	 * @return void
	 */
	public static function add_notice( $message, $type = 'success' ) {
		$key      = self::NOTICE_TRANSIENT . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);

		set_transient( $key, $notices, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * 溜まっている管理通知を表示する。
	 *
	 * @return void
	 */
	public static function render_notices() {
		$key     = self::NOTICE_TRANSIENT . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || ! $notices ) {
			return;
		}
		delete_transient( $key );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * 初期設定が済んでいない場合のヒントを表示する。
	 *
	 * @return void
	 */
	public static function render_setup_hints() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'equiticket' ) ) {
			return;
		}
		if ( ! EquiTicket_Utils::can_manage() ) {
			return;
		}

		// 設定画面では「はじめにやること」の一覧に同じボタンを出すので、ここでは出さない。
		$on_settings = false !== strpos( (string) $screen->id, 'equiticket-settings' );
		if ( ! $on_settings && ! self::has_reservation_page() ) {
			echo '<div class="notice notice-warning"><p>';
			if ( current_user_can( 'publish_pages' ) ) {
				echo esc_html__( '来場者が予約するためのページがまだありません。下のボタンを押すと自動で作成し、設定まで済ませます。', 'equiticket' );
				echo '</p>';
				echo self::create_page_button(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 内部で個別にエスケープ済み。
			} else {
				// ページを公開できない運営スタッフには、管理者への依頼だけを案内する。
				echo esc_html__( '来場者が予約するためのページがまだありません。サイトの管理者に、設定画面の「予約ページを自動で作成する」ボタンを押してもらってください。', 'equiticket' );
				echo '</p>';
			}
			echo '</div>';
		}

		if ( ! wp_next_scheduled( EquiTicket_Cron::HOOK ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( '無断キャンセルの自動解放（WP-Cron）が登録されていません。プラグインを再有効化するか、ダッシュボードの「未来場を解放」ボタンで手動実行してください。', 'equiticket' )
			);
		}
	}

	/**
	 * 予約ページ（来場者用）が設定済みで、公開されているかを返す。
	 *
	 * @return bool 設定済みで公開中なら true。
	 */
	public static function has_reservation_page() {
		$page_id = (int) EquiTicket_Options::get( 'page_id' );

		return $page_id > 0 && 'publish' === get_post_status( $page_id );
	}

	/**
	 * 「予約ページを自動で作成する」ボタンのフォームを返す。
	 *
	 * パソコンに不慣れな人でも、固定ページの作成とショートコードの貼り付け、
	 * 設定画面での選択を 1 回のクリックで済ませられるようにするためのもの。
	 *
	 * @return string HTML。
	 */
	public static function create_page_button() {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 8px;">';
		$html .= '<input type="hidden" name="action" value="equiticket_create_page" />';
		$html .= wp_nonce_field( 'equiticket_create_page', '_wpnonce', true, false );
		$html .= '<button type="submit" class="button button-primary">' . esc_html__( '予約ページを自動で作成する', 'equiticket' ) . '</button>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * 「予約ページを自動で作成する」ボタンの処理。
	 *
	 * ショートコード [equiticket_reservation] を本文に入れた固定ページを公開し、
	 * そのページを設定の「予約ページ」に登録する。
	 *
	 * @return void
	 */
	public static function handle_create_page() {
		if ( ! EquiTicket_Utils::can_manage() || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'ページを作成する権限がありません。サイトの管理者に依頼してください。', 'equiticket' ), 403 );
		}
		check_admin_referer( 'equiticket_create_page' );

		// すでに設定済みなら、二重に作らない。
		if ( self::has_reservation_page() ) {
			self::add_notice( __( '予約ページはすでに設定されています。', 'equiticket' ), 'info' );
			wp_safe_redirect( self::page_url( 'equiticket-settings' ) );
			exit;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( '体験乗馬のご予約', 'equiticket' ),
				'post_content' => "<!-- wp:shortcode -->\n[equiticket_reservation]\n<!-- /wp:shortcode -->",
			),
			true
		);

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			self::add_notice( __( '予約ページを作成できませんでした。「固定ページ」→「新規追加」から手動で作成してください。', 'equiticket' ), 'error' );
			wp_safe_redirect( self::page_url( 'equiticket-settings' ) );
			exit;
		}

		EquiTicket_Options::update( array( 'page_id' => (int) $page_id ) );

		self::add_notice(
			sprintf(
				/* translators: %s: 作成したページの URL */
				__( '予約ページを作成して設定しました。ポスターやチラシの QR コードは次の URL にしてください: %s', 'equiticket' ),
				get_permalink( $page_id )
			),
			'success'
		);

		wp_safe_redirect( self::page_url( 'equiticket-settings' ) );
		exit;
	}

	/**
	 * 「未来場を解放」ボタンの処理。
	 *
	 * @return void
	 */
	public static function handle_run_sweep() {
		if ( ! EquiTicket_Utils::can_check_in() ) {
			wp_die( esc_html__( '操作の権限がありません。', 'equiticket' ), 403 );
		}
		check_admin_referer( 'equiticket_run_sweep' );

		$released = EquiTicket_Reservations::release_no_shows( EquiTicket_Utils::today() );
		update_option( EquiTicket_Cron::LAST_RUN_OPTION, EquiTicket_Utils::now_for_db(), false );

		self::add_notice(
			sprintf(
				/* translators: %d: 解放した予約件数 */
				__( '締切を過ぎた未来場の予約を %d 件、当日枠として解放しました。', 'equiticket' ),
				$released
			),
			'success'
		);

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=equiticket-dashboard' ) );
		exit;
	}

	/**
	 * 管理画面内での遷移先 URL を作る。
	 *
	 * @param string                   $page ページスラッグ。
	 * @param array<string,string|int> $args 追加のクエリ引数。
	 * @return string URL。
	 */
	public static function page_url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}
}
