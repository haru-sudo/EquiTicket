<?php
/**
 * 馬の種類の管理画面。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 馬の種類（名前・説明・体格の条件・支払額）の一覧・追加・編集・削除を扱う管理ページ。
 */
final class EquiTicket_Admin_Horses {

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_equiticket_save_horse', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_equiticket_delete_horse', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * 権限を確認する。
	 *
	 * @return void
	 */
	private static function require_cap() {
		if ( ! EquiTicket_Utils::can_manage() ) {
			wp_die( esc_html__( '馬の種類を管理する権限がありません。', 'equiticket' ), 403 );
		}
	}

	/**
	 * 馬の種類の保存（新規・更新兼用）。
	 *
	 * @return void
	 */
	public static function handle_save() {
		self::require_cap();
		check_admin_referer( 'equiticket_save_horse' );

		$horse_id = isset( $_POST['horse_id'] ) ? (int) $_POST['horse_id'] : 0;
		$data     = array(
			'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'description' => isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '',
			'fee_amount'  => isset( $_POST['fee_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['fee_amount'] ) ) : '',
			'sort_order'  => isset( $_POST['sort_order'] ) ? sanitize_text_field( wp_unslash( $_POST['sort_order'] ) ) : '',
			'active'      => ! empty( $_POST['active'] ),
		);
		foreach ( EquiTicket_Horses::LIMIT_FIELDS as $field ) {
			$data[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}

		$result = EquiTicket_Horses::save( $data, $horse_id );

		if ( is_wp_error( $result ) ) {
			EquiTicket_Admin::add_notice( $result->get_error_message(), 'error' );
			wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-horses', $horse_id ? array( 'edit' => $horse_id ) : array() ) );
			exit;
		}

		EquiTicket_Admin::add_notice( $horse_id ? __( '馬の種類を更新しました。', 'equiticket' ) : __( '馬の種類を追加しました。', 'equiticket' ), 'success' );
		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-horses' ) );
		exit;
	}

	/**
	 * 馬の種類の削除。
	 *
	 * @return void
	 */
	public static function handle_delete() {
		self::require_cap();
		check_admin_referer( 'equiticket_delete_horse' );

		$result = EquiTicket_Horses::delete( isset( $_POST['horse_id'] ) ? (int) $_POST['horse_id'] : 0 );

		if ( is_wp_error( $result ) ) {
			EquiTicket_Admin::add_notice( $result->get_error_message(), 'error' );
		} else {
			EquiTicket_Admin::add_notice( __( '馬の種類を削除しました。', 'equiticket' ), 'success' );
		}

		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-horses' ) );
		exit;
	}

	/**
	 * 画面を出力する。
	 *
	 * @return void
	 */
	public static function render() {
		self::require_cap();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示条件の読み取りのみ。
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$editing = $edit_id > 0 ? EquiTicket_Horses::get( $edit_id ) : null;

		echo '<div class="wrap equiticket-wrap">';
		echo '<h1>' . esc_html__( '馬の種類', 'equiticket' ) . '</h1>';
		echo '<p class="description">' . esc_html__( '体験で出す馬の種類（ポニー、馬、ミニチュアホースなど）を登録します。来場者が入力した身長・体重・年齢が条件に合う馬だけを案内し、2 つ以上に合う場合は来場者が選びます。どの馬を何頭・何人分出すかは「時間枠」画面で枠ごとに決めます。', 'equiticket' ) . '</p>';

		self::render_table();
		self::render_form( $editing );

		echo '</div>';
	}

	/**
	 * 登録済みの馬の一覧表。
	 *
	 * @return void
	 */
	private static function render_table() {
		$horses = EquiTicket_Horses::all();

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( '名前', 'equiticket' ) . '</th>';
		echo '<th style="width:30%;">' . esc_html__( '説明', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '乗れる条件', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '当日のお支払い', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '受付', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '操作', 'equiticket' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $horses ) {
			echo '<tr><td colspan="6">' . esc_html__( '馬の種類がまだ登録されていません。下のフォームから追加してください。', 'equiticket' ) . '</td></tr>';
		}

		foreach ( $horses as $horse ) {
			$fee = EquiTicket_Horses::fee_label( $horse );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $horse->name ) . '</strong></td>';
			echo '<td>' . esc_html( '' !== $horse->description ? $horse->description : '—' ) . '</td>';
			echo '<td>' . esc_html( EquiTicket_Horses::conditions_label( $horse ) ) . '</td>';
			echo '<td>' . esc_html( '' !== $fee ? $fee : '—' ) . '</td>';
			echo '<td>' . esc_html( $horse->active ? __( '受付する', 'equiticket' ) : __( '停止中', 'equiticket' ) ) . '</td>';
			echo '<td>';
			printf(
				'<a href="%s">%s</a> | ',
				esc_url( EquiTicket_Admin::page_url( 'equiticket-horses', array( 'edit' => $horse->id ) ) ),
				esc_html__( '編集', 'equiticket' )
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="equiticket_delete_horse" />';
			echo '<input type="hidden" name="horse_id" value="' . esc_attr( (string) $horse->id ) . '" />';
			wp_nonce_field( 'equiticket_delete_horse' );
			echo '<button type="submit" class="button-link delete" data-equiticket-confirm="' . esc_attr__( 'この馬の種類を削除します。よろしいですか？', 'equiticket' ) . '">'
				. esc_html__( '削除', 'equiticket' ) . '</button>';
			echo '</form>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * 追加・編集フォーム。
	 *
	 * @param object|null $horse 編集対象（新規なら null）。
	 * @return void
	 */
	private static function render_form( $horse ) {
		$is_edit = (bool) $horse;

		echo '<h2>' . esc_html( $is_edit ? __( '馬の種類を編集', 'equiticket' ) : __( '馬の種類を追加', 'equiticket' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="equiticket-admin-form">';
		echo '<input type="hidden" name="action" value="equiticket_save_horse" />';
		if ( $is_edit ) {
			echo '<input type="hidden" name="horse_id" value="' . esc_attr( (string) $horse->id ) . '" />';
		}
		wp_nonce_field( 'equiticket_save_horse' );

		echo '<table class="form-table"><tbody>';

		self::row(
			__( '名前', 'equiticket' ),
			'<input type="text" name="name" class="regular-text" maxlength="50" required value="' . esc_attr( $is_edit ? $horse->name : '' ) . '" />'
			. '<p class="description">' . esc_html__( '例: ポニー、馬、ミニチュアホース。来場者の画面・整理券・メールにこの名前で表示されます。', 'equiticket' ) . '</p>'
		);
		self::row(
			__( '説明', 'equiticket' ),
			'<input type="text" name="description" class="large-text" maxlength="255" value="' . esc_attr( $is_edit ? $horse->description : '' ) . '" />'
			. '<p class="description">' . esc_html__( '馬を選ぶ画面で、ボタンの下に表示します（任意）。', 'equiticket' ) . '</p>'
		);

		$ranges = array(
			'age'    => array( __( '年齢', 'equiticket' ), __( '歳', 'equiticket' ), 120 ),
			'height' => array( __( '身長', 'equiticket' ), 'cm', 250 ),
			'weight' => array( __( '体重', 'equiticket' ), 'kg', 250 ),
		);
		foreach ( $ranges as $key => $range ) {
			list( $label, $unit, $max ) = $range;
			$min_value = $is_edit && $horse->{'min_' . $key} > 0 ? (string) $horse->{'min_' . $key} : '';
			$max_value = $is_edit && $horse->{'max_' . $key} > 0 ? (string) $horse->{'max_' . $key} : '';

			self::row(
				$label,
				'<input type="number" name="min_' . esc_attr( $key ) . '" min="0" max="' . (int) $max . '" style="width:6em;" value="' . esc_attr( $min_value ) . '" /> ' . esc_html( $unit ) . ' '
				. esc_html__( '以上', 'equiticket' ) . '　〜　'
				. '<input type="number" name="max_' . esc_attr( $key ) . '" min="0" max="' . (int) $max . '" style="width:6em;" value="' . esc_attr( $max_value ) . '" /> ' . esc_html( $unit ) . ' '
				. esc_html__( '以下', 'equiticket' )
			);
		}
		echo '<tr><th scope="row"></th><td><p class="description" style="margin-top:-1em;">'
			. esc_html__( '空欄（または 0）の項目は制限なしです。たとえばポニーは「12 歳以下・身長 140cm 以下・体重 40kg 以下」、馬は「6 歳以上・身長 120cm 以上・体重 80kg 以下」のように、必要なところだけ入れます。', 'equiticket' )
			. '</p></td></tr>';

		self::row(
			__( '当日のお支払い（円）', 'equiticket' ),
			'<input type="number" name="fee_amount" min="0" max="65535" style="width:8em;" value="' . esc_attr( $is_edit && $horse->fee_amount > 0 ? (string) $horse->fee_amount : '' ) . '" />'
			. '<p class="description">' . esc_html__( 'この馬に乗る方の当日の支払額。申込み画面・整理券・メールに表示し、ダッシュボードの集金額の計算にも使います。空欄なら金額を表示しません。', 'equiticket' ) . '</p>'
		);
		self::row(
			__( '表示順', 'equiticket' ),
			'<input type="number" name="sort_order" min="0" max="999" style="width:6em;" value="' . esc_attr( $is_edit ? (string) $horse->sort_order : (string) ( 10 * ( count( EquiTicket_Horses::all() ) + 1 ) ) ) . '" />'
			. '<p class="description">' . esc_html__( '小さい順に並びます。', 'equiticket' ) . '</p>'
		);
		self::row(
			__( '受付', 'equiticket' ),
			'<label><input type="checkbox" name="active" value="1" ' . checked( ! $is_edit || $horse->active, true, false ) . ' /> '
			. esc_html__( 'この馬の予約を受け付ける', 'equiticket' ) . '</label>'
			. '<p class="description">' . esc_html__( 'チェックを外すと、来場者の画面に出なくなります（入っている予約はそのまま残ります）。', 'equiticket' ) . '</p>'
		);

		echo '</tbody></table>';

		submit_button( $is_edit ? __( '更新する', 'equiticket' ) : __( '追加する', 'equiticket' ) );

		if ( $is_edit ) {
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( EquiTicket_Admin::page_url( 'equiticket-horses' ) ),
				esc_html__( '編集をやめる', 'equiticket' )
			);
		}

		echo '</form>';
	}

	/**
	 * form-table の 1 行を出力する。
	 *
	 * @param string $label 見出し。
	 * @param string $field 入力欄の HTML。
	 * @return void
	 */
	private static function row( $label, $field ) {
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			$field // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 呼び出し側で組み立て済みの HTML。
		);
	}
}
