<?php
/**
 * 名簿印刷（システム障害時のアナログ運用用）。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 当日朝に印刷して持参する時間帯別の予約者名簿。
 * 通信障害やサーバー停止のときは、この紙の名簿で受付を続けられる。
 */
final class EquiTicket_Admin_Roster {

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_equiticket_export_roster', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * 権限を確認する。
	 *
	 * @return void
	 */
	private static function require_cap() {
		if ( ! EquiTicket_Utils::can_check_in() ) {
			wp_die( esc_html__( 'この画面を表示する権限がありません。', 'equiticket' ), 403 );
		}
	}

	/**
	 * CSV を書き出す。
	 *
	 * @return void
	 */
	public static function handle_export() {
		self::require_cap();
		check_admin_referer( 'equiticket_export_roster' );

		$date = isset( $_POST['date'] ) ? EquiTicket_Utils::sanitize_date( wp_unslash( $_POST['date'] ) ) : '';
		if ( '' === $date ) {
			$date = EquiTicket_Utils::today();
		}

		$reservations = EquiTicket_Reservations::query( array( 'date' => $date ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="equiticket-' . $date . '.csv"' );

		$output = fopen( 'php://output', 'w' );

		// Excel で開いたときに文字化けしないよう BOM を付ける。
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		fputcsv(
			$output,
			array(
				__( '受付番号', 'equiticket' ),
				__( '日付', 'equiticket' ),
				__( '開始', 'equiticket' ),
				__( '終了', 'equiticket' ),
				__( 'お名前', 'equiticket' ),
				__( '乗る馬', 'equiticket' ),
				__( '身長cm', 'equiticket' ),
				__( '体重kg', 'equiticket' ),
				__( '年齢', 'equiticket' ),
				__( '人数', 'equiticket' ),
				__( '支払額', 'equiticket' ),
				__( 'メールアドレス', 'equiticket' ),
				__( '状態', 'equiticket' ),
				__( '受付時刻', 'equiticket' ),
				__( '受付経路', 'equiticket' ),
				__( 'トークン末尾', 'equiticket' ),
				__( '写真', 'equiticket' ),
				__( '備考', 'equiticket' ),
			)
		);

		foreach ( $reservations as $reservation ) {
			fputcsv(
				$output,
				array_map(
					array( 'EquiTicket_Utils', 'csv_cell' ),
					array(
						EquiTicket_Mailer::ticket_number( $reservation ),
						$reservation->event_date,
						EquiTicket_Utils::time_label( $reservation->start_time ),
						EquiTicket_Utils::time_label( $reservation->end_time ),
						$reservation->name,
						EquiTicket_Reservations::horse_label( $reservation ),
						$reservation->height_cm ? $reservation->height_cm : '',
						$reservation->weight_kg ? $reservation->weight_kg : '',
						$reservation->age ? $reservation->age : '',
						$reservation->people_count,
						$reservation->fee_label,
						$reservation->email,
						EquiTicket_Utils::status_label( $reservation->status ),
						$reservation->checked_in_at ? $reservation->checked_in_at : '',
						'staff' === $reservation->source ? __( '当日枠', 'equiticket' ) : __( 'Web 予約', 'equiticket' ),
						self::token_suffix( $reservation->qr_token ),
						$reservation->photo_optout ? __( '写真掲載NG', 'equiticket' ) : '',
						$reservation->note,
					)
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * 画面を出力する。
	 *
	 * @return void
	 */
	public static function render() {
		self::require_cap();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示する日付の読み取りのみ。
		$date = isset( $_GET['date'] ) ? EquiTicket_Utils::sanitize_date( wp_unslash( $_GET['date'] ) ) : '';
		if ( '' === $date ) {
			$date = EquiTicket_Utils::today();
		}

		$slots = EquiTicket_Slots::query( array( 'date' => $date ) );

		echo '<div class="wrap equiticket-wrap equiticket-roster">';
		echo '<h1>' . esc_html__( '名簿印刷（バックアップ）', 'equiticket' ) . '</h1>';
		echo '<p class="description equiticket-noprint">' . esc_html__( '当日朝にこの名簿を印刷して受付に置いてください。通信障害でシステムが使えないときは、この紙で照合して手書きでチェックします。', 'equiticket' ) . '</p>';

		echo '<div class="equiticket-noprint equiticket-toolbar">';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="page" value="equiticket-roster" />';
		echo '<input type="date" name="date" value="' . esc_attr( $date ) . '" /> ';
		echo '<button type="submit" class="button">' . esc_html__( '表示', 'equiticket' ) . '</button>';
		echo '</form> ';

		echo '<button type="button" class="button button-primary" onclick="window.print();">' . esc_html__( '印刷', 'equiticket' ) . '</button> ';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="action" value="equiticket_export_roster" />';
		echo '<input type="hidden" name="date" value="' . esc_attr( $date ) . '" />';
		wp_nonce_field( 'equiticket_export_roster' );
		echo '<button type="submit" class="button">' . esc_html__( 'CSV を書き出す', 'equiticket' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<h2 class="equiticket-roster__title">' . esc_html(
			sprintf(
				/* translators: 1: イベント名, 2: 日付 */
				__( '%1$s 予約者名簿（%2$s）', 'equiticket' ),
				(string) EquiTicket_Options::get( 'event_name' ),
				EquiTicket_Utils::date_label( $date )
			)
		) . '</h2>';

		if ( ! $slots ) {
			echo '<p>' . esc_html__( 'この日の時間枠は登録されていません。', 'equiticket' ) . '</p></div>';

			return;
		}

		foreach ( $slots as $slot ) {
			$reservations = EquiTicket_Reservations::query( array( 'slot_id' => $slot->id ) );

			echo '<div class="equiticket-roster__slot">';
			echo '<h3>' . esc_html(
				sprintf(
					/* translators: 1: 時間帯, 2: 馬ごとの頭数, 3: 合計定員, 4: 予約人数 */
					__( '%1$s ／ %2$s ／ 定員 %3$d 名 ／ 予約 %4$d 名', 'equiticket' ),
					EquiTicket_Utils::time_label( $slot->start_time ) . '〜' . EquiTicket_Utils::time_label( $slot->end_time ),
					/* translators: 1: 馬の種類, 2: 頭数 */
					EquiTicket_Slots::horses_summary( $slot, __( '%1$s %2$d 頭', 'equiticket' ) ),
					$slot->capacity,
					$slot->booked_people
				)
			) . '</h3>';

			if ( ! $reservations ) {
				echo '<p>' . esc_html__( '（予約なし）', 'equiticket' ) . '</p></div>';

				continue;
			}

			echo '<table class="widefat equiticket-roster__table"><thead><tr>';
			echo '<th>' . esc_html__( 'チェック', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( '受付番号', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( 'お名前', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( '乗る馬', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( '体格', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( '人数', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( '支払額', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( '状態', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( 'トークン末尾', 'equiticket' ) . '</th>';
			echo '<th>' . esc_html__( '備考', 'equiticket' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $reservations as $reservation ) {
				echo '<tr>';
				echo '<td class="equiticket-roster__check">☐</td>';
				echo '<td>' . esc_html( EquiTicket_Mailer::ticket_number( $reservation ) ) . '</td>';
				echo '<td>' . esc_html( $reservation->name );
				if ( $reservation->photo_optout ) {
					echo '<br />' . esc_html__( '（写真掲載NG）', 'equiticket' );
				}
				echo '</td>';
				echo '<td>' . esc_html( EquiTicket_Reservations::horse_label( $reservation ) ) . '</td>';
				echo '<td>' . esc_html(
					( $reservation->height_cm || $reservation->weight_kg || $reservation->age )
						? sprintf( '%dcm/%dkg/%d歳', $reservation->height_cm, $reservation->weight_kg, $reservation->age )
						: '—'
				) . '</td>';
				echo '<td>' . esc_html( (string) $reservation->people_count ) . '</td>';
				echo '<td>' . esc_html( '' !== (string) $reservation->fee_label ? $reservation->fee_label : '—' ) . '</td>';
				echo '<td>' . esc_html( EquiTicket_Utils::status_label( $reservation->status ) ) . '</td>';
				echo '<td>' . esc_html( self::token_suffix( $reservation->qr_token ) ) . '</td>';
				echo '<td>' . esc_html( (string) $reservation->note ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table></div>';
		}

		echo '</div>';
	}

	/**
	 * トークンの末尾 6 文字（手動照合の手がかり）。
	 *
	 * @param string $token トークン。
	 * @return string 末尾 6 文字。
	 */
	private static function token_suffix( $token ) {
		return strtoupper( substr( (string) $token, -6 ) );
	}
}
