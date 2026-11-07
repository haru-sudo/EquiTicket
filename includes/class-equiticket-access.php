<?php
/**
 * スタッフ用アカウント（EquiTicket の画面だけを使えるユーザー）まわり。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * スタッフ同士で共有するアカウントを作り、その人が管理画面で迷わないようにするクラス。
 *
 * 受付スタッフ／運営スタッフのロールしか持たないユーザーは、
 * EquiTicket のページ以外を開こうとしても当日ダッシュボードへ戻す。
 */
final class EquiTicket_Access {

	/**
	 * 受付だけを行うロール。
	 */
	const ROLE_STAFF = 'equiticket_staff';

	/**
	 * 時間枠や設定も触れるロール。
	 */
	const ROLE_MANAGER = 'equiticket_manager';

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'restrict_admin_pages' ) );
		add_action( 'admin_menu', array( __CLASS__, 'clean_admin_menu' ), 999 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'maybe_hide_admin_bar' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_action( 'admin_post_equiticket_create_user', array( __CLASS__, 'handle_create_user' ) );
	}

	/**
	 * EquiTicket の権限だけを持つユーザーかどうか。
	 *
	 * 投稿や設定など、サイト本体を触れる権限が 1 つでもあれば「通常の管理者・編集者」とみなす。
	 *
	 * @param WP_User|null $user 判定するユーザー（省略時はログイン中のユーザー）。
	 * @return bool EquiTicket 専用アカウントなら true。
	 */
	public static function is_equiticket_only_user( $user = null ) {
		$user = $user ? $user : wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		foreach ( array( 'manage_options', 'edit_posts', 'upload_files', 'edit_pages', 'list_users' ) as $capability ) {
			if ( user_can( $user, $capability ) ) {
				return false;
			}
		}

		return user_can( $user, EquiTicket_Utils::CAP_CHECKIN ) || user_can( $user, EquiTicket_Utils::CAP_MANAGE );
	}

	/**
	 * EquiTicket 以外の管理画面を開いたら、当日ダッシュボードへ戻す。
	 *
	 * @return void
	 */
	public static function restrict_admin_pages() {
		global $pagenow;

		if ( wp_doing_ajax() || ! is_admin() || ! self::is_equiticket_only_user() ) {
			return;
		}

		// フォーム送信やプロフィール（パスワード変更）は通す。
		$allowed = array( 'admin-post.php', 'admin-ajax.php', 'profile.php' );
		if ( in_array( $pagenow, $allowed, true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示中のページを見ているだけ。
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'admin.php' === $pagenow && 0 === strpos( $page, 'equiticket' ) ) {
			return;
		}

		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-dashboard' ) );
		exit;
	}

	/**
	 * 使わないメニューを消して、EquiTicket だけが並ぶようにする。
	 *
	 * @return void
	 */
	public static function clean_admin_menu() {
		if ( ! self::is_equiticket_only_user() ) {
			return;
		}

		remove_menu_page( 'index.php' );
	}

	/**
	 * スタッフ用アカウントでは、フロント側の管理バーを隠す（受付画面を広く使うため）。
	 *
	 * @param bool $show 表示するかどうか。
	 * @return bool 表示するかどうか。
	 */
	public static function maybe_hide_admin_bar( $show ) {
		return self::is_equiticket_only_user() ? false : $show;
	}

	/**
	 * ログイン後の遷移先を当日ダッシュボードにする。
	 *
	 * @param string           $redirect 既定の遷移先。
	 * @param string           $request  要求された遷移先。
	 * @param WP_User|WP_Error $user     ログインしたユーザー。
	 * @return string 遷移先 URL。
	 */
	public static function login_redirect( $redirect, $request, $user ) {
		unset( $request );

		if ( $user instanceof WP_User && self::is_equiticket_only_user( $user ) ) {
			return admin_url( 'admin.php?page=equiticket-dashboard' );
		}

		return $redirect;
	}

	/**
	 * 選べるロールの一覧。
	 *
	 * @return array<string,string> ロール名 => 説明。
	 */
	public static function roles() {
		return array(
			self::ROLE_STAFF   => __( '受付スタッフ（受付・名簿の閲覧と、予約の取り消し・メールの再送まで。削除と設定の変更はできない）', 'equiticket' ),
			self::ROLE_MANAGER => __( '運営スタッフ（時間枠の登録や設定も変更できる）', 'equiticket' ),
		);
	}

	/**
	 * EquiTicket 用のアカウント一覧を返す。
	 *
	 * @return array<int,WP_User> ユーザーの配列。
	 */
	public static function list_users() {
		$users = get_users(
			array(
				'role__in' => array_keys( self::roles() ),
				'orderby'  => 'user_login',
				'number'   => 50,
			)
		);

		return $users ? $users : array();
	}

	/**
	 * スタッフ用アカウントを作る。
	 *
	 * @return void
	 */
	public static function handle_create_user() {
		if ( ! current_user_can( 'create_users' ) ) {
			wp_die( esc_html__( 'ユーザーを作成する権限がありません。', 'equiticket' ), 403 );
		}
		check_admin_referer( 'equiticket_create_user' );

		$login    = isset( $_POST['user_login'] ) ? sanitize_user( wp_unslash( $_POST['user_login'] ), true ) : '';
		$email    = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
		$password = isset( $_POST['user_pass'] ) ? (string) wp_unslash( $_POST['user_pass'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- パスワードは加工せずそのまま使う。
		$role     = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : self::ROLE_STAFF;

		if ( ! array_key_exists( $role, self::roles() ) ) {
			$role = self::ROLE_STAFF;
		}

		$errors = array();
		if ( '' === $login ) {
			$errors[] = __( 'ユーザー名を入力してください。', 'equiticket' );
		} elseif ( username_exists( $login ) ) {
			$errors[] = __( 'そのユーザー名はすでに使われています。', 'equiticket' );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			$errors[] = __( 'メールアドレスを正しく入力してください（パスワードを忘れたときの再設定に使います）。', 'equiticket' );
		} elseif ( email_exists( $email ) ) {
			$errors[] = __( 'そのメールアドレスは、すでに別のアカウントで使われています。', 'equiticket' );
		}
		if ( strlen( $password ) < 8 ) {
			$errors[] = __( 'パスワードは 8 文字以上にしてください。', 'equiticket' );
		}

		if ( $errors ) {
			foreach ( $errors as $error ) {
				EquiTicket_Admin::add_notice( $error, 'error' );
			}

			wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-settings' ) );
			exit;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'role'         => $role,
				'display_name' => $login,
				'show_admin_bar_front' => 'false',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			EquiTicket_Admin::add_notice( $user_id->get_error_message(), 'error' );
		} else {
			EquiTicket_Admin::add_notice(
				sprintf(
					/* translators: 1: ユーザー名, 2: ロールの説明 */
					__( 'アカウント「%1$s」を作成しました（%2$s）。ユーザー名とパスワードを、共有する方にお伝えください。', 'equiticket' ),
					$login,
					self::roles()[ $role ]
				),
				'success'
			);
		}

		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-settings' ) );
		exit;
	}
}
