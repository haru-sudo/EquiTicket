<?php
/**
 * 時間枠管理画面。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 時間枠の一覧・追加・編集・一括作成を扱う管理ページ。
 */
final class EquiTicket_Admin_Slots {

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_equiticket_save_slot', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_equiticket_delete_slot', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_equiticket_bulk_slots', array( __CLASS__, 'handle_bulk' ) );
	}

	/**
	 * 権限を確認する。
	 *
	 * @return void
	 */
	private static function require_cap() {
		if ( ! EquiTicket_Utils::can_manage() ) {
			wp_die( esc_html__( '時間枠を管理する権限がありません。', 'equiticket' ), 403 );
		}
	}

	/**
	 * 時間枠の保存（新規・更新兼用）。
	 *
	 * @return void
	 */
	public static function handle_save() {
		self::require_cap();
		check_admin_referer( 'equiticket_save_slot' );

		$slot_id = isset( $_POST['slot_id'] ) ? (int) $_POST['slot_id'] : 0;
		$data    = array(
			'event_date' => isset( $_POST['event_date'] ) ? sanitize_text_field( wp_unslash( $_POST['event_date'] ) ) : '',
			'start_time' => isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '',
			'end_time'   => isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '',
			'horses'     => self::posted_horses(),
			'status'     => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'open',
			'note'       => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
		);

		$before = $slot_id > 0 ? EquiTicket_Slots::get( $slot_id ) : null;
		$result = $slot_id > 0
			? EquiTicket_Slots::update( $slot_id, $data )
			: EquiTicket_Slots::create( $data );

		if ( is_wp_error( $result ) ) {
			EquiTicket_Admin::add_notice( $result->get_error_message(), 'error' );
		} else {
			EquiTicket_Admin::add_notice(
				$slot_id > 0 ? __( '時間枠を更新しました。', 'equiticket' ) : __( '時間枠を追加しました。', 'equiticket' ),
				'success'
			);

			// 「中止」に変えたときは、その枠の予約者にお知らせメールを送る（チェックを外せば送らない）。
			if ( $before && 'cancelled' !== $before->status && 'cancelled' === $data['status'] && ! empty( $_POST['notify_cancel'] ) ) {
				self::notify_cancelled( $slot_id );
			}
		}

		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-slots', array( 'date' => $data['event_date'] ) ) );
		exit;
	}

	/**
	 * 時間枠の削除。
	 *
	 * @return void
	 */
	public static function handle_delete() {
		self::require_cap();
		check_admin_referer( 'equiticket_delete_slot' );

		$slot_id = isset( $_POST['slot_id'] ) ? (int) $_POST['slot_id'] : 0;
		$result  = EquiTicket_Slots::delete( $slot_id );

		if ( is_wp_error( $result ) ) {
			EquiTicket_Admin::add_notice( $result->get_error_message(), 'error' );
		} else {
			EquiTicket_Admin::add_notice( __( '時間枠を削除しました。', 'equiticket' ), 'success' );
		}

		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-slots' ) );
		exit;
	}

	/**
	 * 時間枠の一括作成。
	 *
	 * @return void
	 */
	public static function handle_bulk() {
		self::require_cap();
		check_admin_referer( 'equiticket_bulk_slots' );

		$args = array(
			'event_date'    => isset( $_POST['event_date'] ) ? sanitize_text_field( wp_unslash( $_POST['event_date'] ) ) : '',
			'start_time'    => isset( $_POST['start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['start_time'] ) ) : '',
			'end_time'      => isset( $_POST['end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['end_time'] ) ) : '',
			'slot_minutes'  => isset( $_POST['slot_minutes'] ) ? (int) $_POST['slot_minutes'] : 0,
			'break_minutes' => isset( $_POST['break_minutes'] ) ? (int) $_POST['break_minutes'] : 0,
			'horses'        => self::posted_horses(),
			'note'          => isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '',
		);

		$result = EquiTicket_Slots::bulk_create( $args );

		if ( is_wp_error( $result ) ) {
			EquiTicket_Admin::add_notice( $result->get_error_message(), 'error' );
		} else {
			EquiTicket_Admin::add_notice(
				sprintf(
					/* translators: %d: 作成した時間枠の数 */
					__( '時間枠を %d 件作成しました。', 'equiticket' ),
					$result
				),
				'success'
			);
		}

		wp_safe_redirect( EquiTicket_Admin::page_url( 'equiticket-slots', array( 'date' => $args['event_date'] ) ) );
		exit;
	}

	/**
	 * 画面を出力する。
	 *
	 * @return void
	 */
	public static function render() {
		self::require_cap();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示条件の読み取りのみ。
		$filter_date = isset( $_GET['date'] ) ? EquiTicket_Utils::sanitize_date( wp_unslash( $_GET['date'] ) ) : '';
		$edit_id     = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$archive     = ! empty( $_GET['archive'] );
		// phpcs:enable

		// 昨日までの日は「アーカイブ」に分け、ふだんはこれからの時間枠だけを並べる。
		$today = EquiTicket_Utils::today();
		if ( '' !== $filter_date ) {
			$archive = $filter_date < $today;
		}

		$all_dates = EquiTicket_Slots::dates();
		$upcoming  = array_values(
			array_filter(
				$all_dates,
				static function ( $date ) use ( $today ) {
					return $date >= $today;
				}
			)
		);
		// アーカイブは新しい日から並べる。
		$past      = array_reverse( array_values( array_diff( $all_dates, $upcoming ) ) );

		if ( '' !== $filter_date ) {
			$slots = EquiTicket_Slots::query( array( 'date' => $filter_date ) );
		} elseif ( $archive ) {
			$slots = array_values(
				array_filter(
					EquiTicket_Slots::query(),
					static function ( $slot ) use ( $today ) {
						return $slot->event_date < $today;
					}
				)
			);
			// 新しい日から、同じ日の中は時刻順に並べる。
			usort(
				$slots,
				static function ( $a, $b ) {
					return $a->event_date === $b->event_date
						? strcmp( $a->start_time, $b->start_time )
						: strcmp( $b->event_date, $a->event_date );
				}
			);
		} else {
			$slots = EquiTicket_Slots::query( array( 'from_date' => $today ) );
		}
		$edit_slot = $edit_id > 0 ? EquiTicket_Slots::get( $edit_id ) : null;

		echo '<div class="wrap equiticket-wrap">';
		echo '<h1>' . esc_html( $archive ? __( '時間枠の管理（アーカイブ）', 'equiticket' ) : __( '時間枠の管理', 'equiticket' ) ) . '</h1>';
		echo '<p class="description">' . esc_html(
			$archive
				? __( '昨日までの時間枠です。来年の参考に残しています。', 'equiticket' )
				: __( '時間帯ごとに出す頭数と定員を設定します。馬の体調で頭数が変わった場合も、この画面でその場で修正できます。昨日までの時間枠は「アーカイブ」に分けています。', 'equiticket' )
		) . '</p>';

		self::render_date_filter( $archive ? $past : $upcoming, $filter_date, $archive, count( $archive ? $upcoming : $past ) );
		self::render_table( $slots );

		// アーカイブでは、編集するときだけフォームを出す（新しい枠はこれからの日に作る）。
		if ( ! $archive || $edit_slot ) {
			self::render_form( $edit_slot, $filter_date );
		}
		if ( ! $archive ) {
			self::render_bulk_form( $filter_date );
		}

		echo '</div>';
	}

	/**
	 * 日付の絞り込みリンクと、アーカイブとの切り替え。
	 *
	 * @param array<int,string> $dates       並べる日付（これからの日、またはアーカイブの日）。
	 * @param string            $filter_date 選択中の日付。
	 * @param bool              $archive     アーカイブを表示しているか。
	 * @param int               $other_count もう一方（アーカイブ／これから）の日数。
	 * @return void
	 */
	private static function render_date_filter( array $dates, $filter_date, $archive, $other_count ) {
		echo '<ul class="subsubsub">';
		$base = $archive ? array( 'archive' => 1 ) : array();
		printf(
			'<li><a href="%1$s" class="%2$s">%3$s</a>%4$s</li>',
			esc_url( EquiTicket_Admin::page_url( 'equiticket-slots', $base ) ),
			esc_attr( '' === $filter_date ? 'current' : '' ),
			esc_html( $archive ? __( 'アーカイブのすべて', 'equiticket' ) : __( 'これからの枠すべて', 'equiticket' ) ),
			$dates ? ' |' : ''
		);
		$last = count( $dates ) - 1;
		foreach ( array_values( $dates ) as $index => $date ) {
			printf(
				'<li><a href="%1$s" class="%2$s">%3$s</a>%4$s</li>',
				esc_url( EquiTicket_Admin::page_url( 'equiticket-slots', array( 'date' => $date ) ) ),
				esc_attr( $filter_date === $date ? 'current' : '' ),
				// アーカイブは年をまたぐので、年も出す。
				esc_html( ( $archive ? substr( $date, 0, 4 ) . '年' : '' ) . EquiTicket_Utils::date_label( $date ) ),
				$index === $last ? '' : ' |'
			);
		}
		echo '</ul>';

		if ( ! $archive && 0 === $other_count ) {
			echo '<div class="clear"></div>';

			return;
		}

		printf(
			'<p class="equiticket-archive-link"><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( EquiTicket_Admin::page_url( 'equiticket-slots', $archive ? array() : array( 'archive' => 1 ) ) ),
			esc_html(
				$archive
					? __( '← これからの時間枠に戻る', 'equiticket' )
					: sprintf(
						/* translators: %d: アーカイブの日数 */
						__( '過去の時間枠（アーカイブ・%d 日分）を見る', 'equiticket' ),
						$other_count
					)
			)
		);
		echo '<div class="clear"></div>';
	}

	/**
	 * 時間枠の一覧表。
	 *
	 * @param array<int,object> $slots 時間枠。
	 * @return void
	 */
	private static function render_table( array $slots ) {
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( '日付', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '時間', 'equiticket' ) . '</th>';
		echo '<th style="width:30%;">' . esc_html__( '馬（頭数／定員／残り）', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '予約', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '来場', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '状態', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '備考', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '操作', 'equiticket' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $slots ) {
			echo '<tr><td colspan="8">' . esc_html__( '時間枠がまだ登録されていません。下のフォームから追加してください。', 'equiticket' ) . '</td></tr>';
		}

		foreach ( $slots as $slot ) {
			echo '<tr>';
			// 今年以外の日（去年のアーカイブなど）は年も出す。
			$year = substr( $slot->event_date, 0, 4 ) !== substr( EquiTicket_Utils::today(), 0, 4 ) ? substr( $slot->event_date, 0, 4 ) . '年' : '';
			echo '<td>' . esc_html( $year . EquiTicket_Utils::date_label( $slot->event_date ) ) . '</td>';
			echo '<td>' . esc_html( EquiTicket_Utils::time_label( $slot->start_time ) . '〜' . EquiTicket_Utils::time_label( $slot->end_time ) ) . '</td>';
			echo '<td>';
			foreach ( $slot->horses as $entry ) {
				echo esc_html( sprintf( '%s %d / %d / %d', $entry->name, $entry->horse_count, $entry->capacity, $entry->remaining ) ) . '<br />';
			}
			if ( ! $slot->horses ) {
				echo esc_html__( '馬が未設定', 'equiticket' );
			}
			echo '</td>';
			echo '<td>' . esc_html( sprintf( '%d / %d', $slot->booked_people, $slot->capacity ) ) . '</td>';
			echo '<td>' . esc_html( (string) $slot->checked_in_people ) . '</td>';
			echo '<td>' . esc_html( EquiTicket_Utils::slot_status_label( $slot->status ) ) . '</td>';
			echo '<td>' . esc_html( (string) $slot->note ) . '</td>';
			echo '<td>';
			printf(
				'<a href="%s">%s</a> | ',
				esc_url( EquiTicket_Admin::page_url( 'equiticket-slots', array( 'edit' => $slot->id ) ) ),
				esc_html__( '編集', 'equiticket' )
			);
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
			echo '<input type="hidden" name="action" value="equiticket_delete_slot" />';
			echo '<input type="hidden" name="slot_id" value="' . esc_attr( (string) $slot->id ) . '" />';
			wp_nonce_field( 'equiticket_delete_slot' );
			echo '<button type="submit" class="button-link delete" data-equiticket-confirm="' . esc_attr__( 'この時間枠を削除します。よろしいですか？', 'equiticket' ) . '">'
				. esc_html__( '削除', 'equiticket' ) . '</button>';
			echo '</form>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * 追加・編集フォーム。
	 *
	 * @param object|null $slot        編集対象の時間枠。
	 * @param string      $filter_date 選択中の日付（新規作成の初期値に使う）。
	 * @return void
	 */
	private static function render_form( $slot, $filter_date ) {
		$is_edit = (bool) $slot;
		$values  = array(
			'event_date'     => $is_edit ? $slot->event_date : ( $filter_date ? $filter_date : EquiTicket_Utils::today() ),
			'start_time'     => $is_edit ? EquiTicket_Utils::time_label( $slot->start_time ) : '10:00',
			'end_time'       => $is_edit ? EquiTicket_Utils::time_label( $slot->end_time ) : '10:20',
			'status'         => $is_edit ? $slot->status : 'open',
			'note'           => $is_edit ? $slot->note : '',
		);

		echo '<h2>' . esc_html( $is_edit ? __( '時間枠を編集', 'equiticket' ) : __( '時間枠を追加', 'equiticket' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="equiticket-admin-form">';
		echo '<input type="hidden" name="action" value="equiticket_save_slot" />';
		if ( $is_edit ) {
			echo '<input type="hidden" name="slot_id" value="' . esc_attr( (string) $slot->id ) . '" />';
		}
		wp_nonce_field( 'equiticket_save_slot' );

		echo '<table class="form-table"><tbody>';
		self::field_row( __( '日付', 'equiticket' ), '<input type="date" name="event_date" value="' . esc_attr( $values['event_date'] ) . '" required />' );
		self::field_row( __( '開始時刻', 'equiticket' ), '<input type="time" name="start_time" value="' . esc_attr( $values['start_time'] ) . '" required />' );
		self::field_row( __( '終了時刻', 'equiticket' ), '<input type="time" name="end_time" value="' . esc_attr( $values['end_time'] ) . '" required />' );
		self::field_row(
			__( '出す馬', 'equiticket' ),
			self::horses_fields( $is_edit ? $slot->horses : null )
			. '<p class="description">' . esc_html__( 'この枠で出す馬ごとに、頭数と定員（受け入れる人数）を入れます。定員を 0 にした馬は、この枠では受付をしません。合計定員は馬ごとの定員の合計になります。馬の種類は「馬の種類」画面で登録します。', 'equiticket' ) . '</p>'
		);

		$status_select = '<select name="status">';
		foreach ( array( 'open', 'closed', 'cancelled' ) as $status ) {
			$status_select .= '<option value="' . esc_attr( $status ) . '"' . selected( $values['status'], $status, false ) . '>'
				. esc_html( EquiTicket_Utils::slot_status_label( $status ) ) . '</option>';
		}
		$status_select .= '</select>';
		$notify = '';
		if ( $is_edit && 'cancelled' !== $slot->status ) {
			$notify = '<p><label><input type="checkbox" name="notify_cancel" value="1" checked /> '
				. esc_html(
					sprintf(
						/* translators: %d: 予約済みの人数 */
						__( '「中止」にしたら、この枠の予約済みの人（%d 名）に中止のお知らせメールを送る', 'equiticket' ),
						$slot->waiting_people
					)
				) . '</label></p>';
		}
		self::field_row(
			__( '状態', 'equiticket' ),
			$status_select . '<p class="description">' . esc_html__( '荒天中止の場合は「中止」にすると、来場者の一覧に中止として表示されます。', 'equiticket' ) . '</p>' . $notify
		);
		self::field_row( __( '備考', 'equiticket' ), '<input type="text" name="note" class="regular-text" maxlength="255" value="' . esc_attr( (string) $values['note'] ) . '" />' );
		echo '</tbody></table>';

		submit_button( $is_edit ? __( '更新する', 'equiticket' ) : __( '追加する', 'equiticket' ) );

		if ( $is_edit ) {
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( EquiTicket_Admin::page_url( 'equiticket-slots' ) ),
				esc_html__( '編集をやめる', 'equiticket' )
			);
		}

		echo '</form>';
	}

	/**
	 * 一括作成フォーム。
	 *
	 * @param string $filter_date 選択中の日付。
	 * @return void
	 */
	private static function render_bulk_form( $filter_date ) {
		echo '<hr />';
		echo '<h2>' . esc_html__( '時間枠の一括作成', 'equiticket' ) . '</h2>';
		echo '<p class="description">' . esc_html__( '開始時刻から終了時刻まで、一定の長さで枠をまとめて作ります（前日の準備用）。', 'equiticket' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="equiticket-admin-form">';
		echo '<input type="hidden" name="action" value="equiticket_bulk_slots" />';
		wp_nonce_field( 'equiticket_bulk_slots' );

		echo '<table class="form-table"><tbody>';
		self::field_row( __( '日付', 'equiticket' ), '<input type="date" name="event_date" value="' . esc_attr( $filter_date ? $filter_date : EquiTicket_Utils::today() ) . '" required />' );
		self::field_row( __( '開始時刻', 'equiticket' ), '<input type="time" name="start_time" value="10:00" required />' );
		self::field_row( __( '終了時刻', 'equiticket' ), '<input type="time" name="end_time" value="15:00" required />' );
		self::field_row( __( '1 枠の長さ（分）', 'equiticket' ), '<input type="number" name="slot_minutes" min="5" max="240" value="20" required />' );
		self::field_row( __( '枠と枠の間の休憩（分）', 'equiticket' ), '<input type="number" name="break_minutes" min="0" max="240" value="0" />' );
		self::field_row( __( '出す馬', 'equiticket' ), self::horses_fields( null ) . '<p class="description">' . esc_html__( '作るすべての枠に同じ頭数・定員を入れます。定員を 0 にした馬は出しません。', 'equiticket' ) . '</p>' );
		self::field_row( __( '備考', 'equiticket' ), '<input type="text" name="note" class="regular-text" maxlength="255" value="" />' );
		echo '</tbody></table>';

		submit_button( __( 'まとめて作成する', 'equiticket' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * 中止にした時間枠の予約者（予約済みの人）に、お知らせメールを送る。
	 *
	 * @param int $slot_id 時間枠 ID。
	 * @return void
	 */
	private static function notify_cancelled( $slot_id ) {
		$slot = EquiTicket_Slots::get( $slot_id );
		if ( ! $slot ) {
			return;
		}

		$reservations = EquiTicket_Reservations::query(
			array(
				'slot_id'  => $slot_id,
				'statuses' => array( 'reserved' ),
			)
		);
		if ( ! $reservations ) {
			EquiTicket_Admin::add_notice( __( 'この枠に予約済みの人はいないため、中止のお知らせは送っていません。', 'equiticket' ), 'info' );

			return;
		}

		$sent    = 0;
		$failed  = 0;
		$no_mail = 0;
		foreach ( $reservations as $reservation ) {
			if ( '' === (string) $reservation->email ) {
				$no_mail++;
			} elseif ( EquiTicket_Mailer::send_slot_cancelled( $reservation, $slot ) ) {
				$sent++;
			} else {
				$failed++;
			}
		}

		$message = sprintf(
			/* translators: %d: 送信した件数 */
			__( '中止のお知らせメールを %d 件送りました。', 'equiticket' ),
			$sent
		);
		if ( $no_mail > 0 ) {
			/* translators: %d: メールアドレスのない予約の件数 */
			$message .= sprintf( __( '（メールアドレスのない予約 %d 件には送っていません。会場でお知らせしてください）', 'equiticket' ), $no_mail );
		}
		EquiTicket_Admin::add_notice( $message, $failed > 0 ? 'warning' : 'success' );

		if ( $failed > 0 ) {
			EquiTicket_Admin::add_notice(
				sprintf(
					/* translators: %d: 送信に失敗した件数 */
					__( '%d 件はメールを送れませんでした。設定画面の「テストメールの送信」で送信できるか確認してください。', 'equiticket' ),
					$failed
				),
				'error'
			);
		}
	}

	/**
	 * 馬ごとの頭数・定員の入力欄（表）。
	 *
	 * @param array<int,object>|null $current 編集中の枠の馬（新規なら null）。
	 * @return string HTML。
	 */
	private static function horses_fields( $current ) {
		$horses = EquiTicket_Horses::all();
		if ( ! $horses ) {
			return '<p>' . sprintf(
				/* translators: %s: 馬の種類画面へのリンク */
				esc_html__( '馬の種類がまだ登録されていません。先に %s で登録してください。', 'equiticket' ),
				'<a href="' . esc_url( EquiTicket_Admin::page_url( 'equiticket-horses' ) ) . '">' . esc_html__( '馬の種類', 'equiticket' ) . '</a>'
			) . '</p>';
		}

		$html  = '<table class="widefat striped" style="max-width:40em;"><thead><tr>';
		$html .= '<th>' . esc_html__( '馬', 'equiticket' ) . '</th>';
		$html .= '<th>' . esc_html__( '頭数', 'equiticket' ) . '</th>';
		$html .= '<th>' . esc_html__( '定員（人）', 'equiticket' ) . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $horses as $horse ) {
			$entry = ( is_array( $current ) && isset( $current[ $horse->id ] ) ) ? $current[ $horse->id ] : null;

			// 受付をやめた馬は、この枠で使っていなければ出さない。
			if ( ! $horse->active && ! $entry ) {
				continue;
			}

			// 新規の枠は、受付中の馬に 1 頭・4 名を入れておく。
			$count    = $entry ? $entry->horse_count : ( null === $current ? 1 : 0 );
			$capacity = $entry ? $entry->capacity : ( null === $current ? 4 : 0 );
			$name     = 'horses[' . (int) $horse->id . ']';

			$html .= '<tr><td>' . esc_html( $horse->name );
			if ( ! $horse->active ) {
				$html .= ' <span class="equiticket-muted">' . esc_html__( '（受付停止中）', 'equiticket' ) . '</span>';
			}
			$html .= '<br /><span class="equiticket-muted">' . esc_html( EquiTicket_Horses::conditions_label( $horse ) ) . '</span></td>';
			$html .= '<td><input type="number" name="' . esc_attr( $name . '[horse_count]' ) . '" min="0" max="50" value="' . esc_attr( (string) $count ) . '" style="width:5em;" /></td>';
			$html .= '<td><input type="number" name="' . esc_attr( $name . '[capacity]' ) . '" min="0" max="999" value="' . esc_attr( (string) $capacity ) . '" style="width:5em;" /></td>';
			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * 送信された馬ごとの頭数・定員を取り出す。
	 *
	 * @return array<int,array<string,int>> 馬の ID => horse_count / capacity。
	 */
	private static function posted_horses() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 呼び出し元で nonce を検証済み。値は下で整数にする。
		$raw    = isset( $_POST['horses'] ) && is_array( $_POST['horses'] ) ? wp_unslash( $_POST['horses'] ) : array();
		$horses = array();
		foreach ( $raw as $horse_id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$horses[ (int) $horse_id ] = array(
				'horse_count' => isset( $entry['horse_count'] ) ? (int) $entry['horse_count'] : 0,
				'capacity'    => isset( $entry['capacity'] ) ? (int) $entry['capacity'] : 0,
			);
		}

		return $horses;
	}

	/**
	 * form-table の 1 行を出力する。
	 *
	 * @param string $label 見出し。
	 * @param string $field 入力欄の HTML。
	 * @return void
	 */
	private static function field_row( $label, $field ) {
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			$field // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 呼び出し側で組み立て済みの HTML。
		);
	}
}
