<?php
/**
 * 当日予約（受付スタッフ用）の画面。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 会場で希望された方を、今日の空いている枠にその場で入れるための画面。
 *
 * スマートフォンで使う前提で、枠と馬を選んで人数を決めるだけで登録できる。
 * 名前は任意で、登録と同時に来場済みにする。
 */
final class EquiTicket_Admin_Walkin {

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_equiticket_quick_walkin', array( __CLASS__, 'handle_register' ) );
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
	 * 当日予約を登録する（登録と同時に来場済みにする）。
	 *
	 * @return void
	 */
	public static function handle_register() {
		self::require_cap();
		check_admin_referer( 'equiticket_quick_walkin' );

		$back = EquiTicket_Admin::page_url( 'equiticket-walkin' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 直前の check_admin_referer で検証済み。
		if ( ! empty( $_POST['show_all'] ) ) {
			$back = add_query_arg( array( 'all' => 1 ), $back );
		}

		$reservation = EquiTicket_Reservations::create(
			array(
				'slot_id'           => isset( $_POST['slot_id'] ) ? (int) $_POST['slot_id'] : 0,
				'horse_id'          => isset( $_POST['horse_id'] ) ? (int) $_POST['horse_id'] : 0,
				'people_count'      => isset( $_POST['people_count'] ) ? (int) $_POST['people_count'] : 1,
				'name'              => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'source'            => 'staff',
				'bypass_time_check' => true,
			)
		);

		if ( is_wp_error( $reservation ) ) {
			EquiTicket_Admin::add_notice( $reservation->get_error_message(), 'error' );
			wp_safe_redirect( $back );
			exit;
		}

		EquiTicket_Reservations::mark_checked_in( $reservation );

		wp_safe_redirect( add_query_arg( array( 'done' => $reservation->id ), $back ) );
		exit;
	}

	/**
	 * 画面を出力する。
	 *
	 * @return void
	 */
	public static function render() {
		self::require_cap();

		EquiTicket_Cron::maybe_sweep();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示条件の読み取りのみ。
		$show_all = ! empty( $_GET['all'] );
		$done_id  = isset( $_GET['done'] ) ? (int) $_GET['done'] : 0;
		// phpcs:enable

		$today = EquiTicket_Utils::today();
		$now   = EquiTicket_Utils::now();
		$slots = EquiTicket_Slots::query( array( 'date' => $today ) );

		echo '<div class="wrap equiticket-wrap equiticket-walkin">';
		echo '<h1>' . esc_html__( '当日予約', 'equiticket' ) . '</h1>';
		echo '<p class="description">' . esc_html__( '会場で希望された方を、今日の空いている枠に入れます。乗る馬の行で人数を選んで「登録」を押すと、その場で来場済みになります。名前は空欄でも構いません。', 'equiticket' ) . '</p>';

		if ( $done_id ) {
			self::render_done( $done_id );
		}

		$visible = array();
		$hidden  = 0;
		foreach ( $slots as $slot ) {
			if ( 'cancelled' === $slot->status ) {
				continue;
			}
			if ( ! $show_all && $slot->ends_at && $slot->ends_at <= $now ) {
				$hidden++;
				continue;
			}
			$visible[] = $slot;
		}

		if ( ! $visible ) {
			echo '<p class="equiticket-empty">' . esc_html__( '今日、これから受け付けられる時間枠はありません。', 'equiticket' ) . '</p>';
		}

		foreach ( $visible as $slot ) {
			self::render_slot( $slot, $show_all );
		}

		if ( $hidden > 0 ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( EquiTicket_Admin::page_url( 'equiticket-walkin', array( 'all' => 1 ) ) ),
				esc_html(
					sprintf(
						/* translators: %d: 終了した時間枠の数 */
						__( '終了した時間枠も表示する（%d 枠）', 'equiticket' ),
						$hidden
					)
				)
			);
		} elseif ( $show_all ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( EquiTicket_Admin::page_url( 'equiticket-walkin' ) ),
				esc_html__( 'これからの時間枠だけを表示する', 'equiticket' )
			);
		}

		self::render_recent( $today );

		echo '</div>';
	}

	/**
	 * 登録できたことを大きく表示する。
	 *
	 * @param int $reservation_id 登録した予約。
	 * @return void
	 */
	private static function render_done( $reservation_id ) {
		$reservation = EquiTicket_Reservations::get( $reservation_id );
		$slot        = $reservation ? EquiTicket_Slots::get( $reservation->slot_id ) : null;
		if ( ! $reservation || ! $slot ) {
			return;
		}

		echo '<div class="equiticket-walkin__done">';
		echo '<p class="equiticket-walkin__done-headline">' . esc_html__( '✓ 登録しました', 'equiticket' ) . '</p>';
		echo '<p class="equiticket-walkin__done-main">' . esc_html(
			sprintf(
				/* translators: 1: 時間帯, 2: 馬の種類, 3: 人数 */
				__( '%1$s ／ %2$s ／ %3$d 名', 'equiticket' ),
				EquiTicket_Utils::time_label( $slot->start_time ) . '〜' . EquiTicket_Utils::time_label( $slot->end_time ),
				EquiTicket_Reservations::horse_label( $reservation ),
				$reservation->people_count
			)
		) . '</p>';
		if ( $reservation->fee_amount > 0 ) {
			echo '<p class="equiticket-walkin__done-fee">' . esc_html(
				sprintf(
					/* translators: %s: 金額 */
					__( '集金: %s 円', 'equiticket' ),
					number_format_i18n( $reservation->fee_amount * $reservation->people_count )
				)
			) . '</p>';
		}
		echo '<p class="equiticket-walkin__done-sub">' . esc_html(
			sprintf(
				/* translators: 1: 受付番号, 2: 名前 */
				__( '%1$s・%2$s 様', 'equiticket' ),
				EquiTicket_Mailer::ticket_number( $reservation ),
				$reservation->name
			)
		) . '</p>';
		echo '</div>';
	}

	/**
	 * 時間枠 1 つ分（馬ごとの登録ボタン）。
	 *
	 * @param object $slot     時間枠。
	 * @param bool   $show_all 終了した枠も表示しているか。
	 * @return void
	 */
	private static function render_slot( $slot, $show_all ) {
		$max_people = max( 1, (int) EquiTicket_Options::get( 'max_people_per_reservation' ) );

		echo '<div class="equiticket-walkin__slot">';
		echo '<h2 class="equiticket-walkin__time">' . esc_html( EquiTicket_Utils::time_label( $slot->start_time ) . '〜' . EquiTicket_Utils::time_label( $slot->end_time ) );
		if ( 'open' !== $slot->status ) {
			echo ' <span class="equiticket-tag">' . esc_html( EquiTicket_Utils::slot_status_label( $slot->status ) ) . '</span>';
		}
		echo '</h2>';

		if ( ! $slot->horses ) {
			echo '<p class="equiticket-muted">' . esc_html__( 'この枠には馬が設定されていません。', 'equiticket' ) . '</p>';
		}

		foreach ( $slot->horses as $horse_id => $entry ) {
			if ( $entry->capacity <= 0 ) {
				continue;
			}

			$horse = EquiTicket_Horses::get( $horse_id );
			$fee   = $horse ? EquiTicket_Horses::fee_label( $horse ) : '';
			$limit = min( $max_people, $entry->remaining );

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="equiticket-walkin__row' . ( $limit > 0 ? '' : ' is-full' ) . '">';
			echo '<input type="hidden" name="action" value="equiticket_quick_walkin" />';
			echo '<input type="hidden" name="slot_id" value="' . esc_attr( (string) $slot->id ) . '" />';
			echo '<input type="hidden" name="horse_id" value="' . esc_attr( (string) $horse_id ) . '" />';
			if ( $show_all ) {
				echo '<input type="hidden" name="show_all" value="1" />';
			}
			wp_nonce_field( 'equiticket_quick_walkin' );

			echo '<div class="equiticket-walkin__horse">';
			echo '<strong>' . esc_html( $entry->name ) . '</strong>';
			if ( '' !== $fee ) {
				echo ' <span class="equiticket-walkin__fee">' . esc_html( $fee ) . '</span>';
			}
			echo '<span class="equiticket-walkin__remaining">' . esc_html(
				$limit > 0
					/* translators: %d: 残り人数 */
					? sprintf( __( '残り %d 名', 'equiticket' ), $entry->remaining )
					: __( '満員', 'equiticket' )
			) . '</span>';
			echo '</div>';

			if ( $limit > 0 ) {
				echo '<div class="equiticket-walkin__controls">';
				echo '<label class="equiticket-walkin__people">' . esc_html__( '人数', 'equiticket' ) . ' <select name="people_count">';
				for ( $count = 1; $count <= $limit; $count++ ) {
					echo '<option value="' . (int) $count . '">' . (int) $count . '</option>';
				}
				echo '</select></label>';
				echo '<input type="text" name="name" maxlength="50" class="equiticket-walkin__name" placeholder="' . esc_attr__( 'お名前（任意）', 'equiticket' ) . '" />';
				echo '<button type="submit" class="button button-primary button-large">' . esc_html__( '登録', 'equiticket' ) . '</button>';
				echo '</div>';
			}

			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * 今日登録した当日予約の一覧（間違えたときに取り消せる）。
	 *
	 * @param string $today 今日の日付。
	 * @return void
	 */
	private static function render_recent( $today ) {
		$reservations = array_filter(
			EquiTicket_Reservations::query(
				array(
					'date'  => $today,
					'order' => 'recent',
					'limit' => 50,
				)
			),
			static function ( $reservation ) {
				return 'staff' === $reservation->source && in_array( $reservation->status, array( 'reserved', 'checked_in' ), true );
			}
		);

		echo '<h2>' . esc_html__( '今日の当日予約', 'equiticket' ) . '</h2>';
		if ( ! $reservations ) {
			echo '<p class="equiticket-muted">' . esc_html__( 'まだありません。', 'equiticket' ) . '</p>';

			return;
		}

		echo '<ul class="equiticket-walkin__recent">';
		foreach ( array_slice( $reservations, 0, 15 ) as $reservation ) {
			echo '<li><span>' . esc_html(
				sprintf(
					/* translators: 1: 時間帯, 2: 馬の種類, 3: 人数, 4: 名前 */
					__( '%1$s・%2$s・%3$d 名・%4$s 様', 'equiticket' ),
					EquiTicket_Utils::time_label( $reservation->start_time ),
					EquiTicket_Reservations::horse_label( $reservation ),
					$reservation->people_count,
					$reservation->name
				)
			) . '</span> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="equiticket_reservation_action" />';
			echo '<input type="hidden" name="reservation_id" value="' . esc_attr( (string) $reservation->id ) . '" />';
			echo '<input type="hidden" name="op" value="cancel" />';
			wp_nonce_field( 'equiticket_reservation_action' );
			echo '<button type="submit" class="button button-small" data-equiticket-confirm="' . esc_attr__( 'この当日予約を取り消して、枠を空けます。よろしいですか？', 'equiticket' ) . '">' . esc_html__( '取消', 'equiticket' ) . '</button>';
			echo '</form></li>';
		}
		echo '</ul>';
	}
}
