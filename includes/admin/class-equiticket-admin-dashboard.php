<?php
/**
 * 当日ダッシュボード。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 枠ごとの予約数・来場数をまとめて確認し、当日の調整を行う管理ページ。
 *
 * 当日ダッシュボードは今日以降の日だけを扱い、過去の日は「過去の記録」ページで見る
 * （来年の参考に残しておくため）。人数が多くても見やすいよう、時間枠を 1 つずつ切り替えて表示する。
 */
final class EquiTicket_Admin_Dashboard {

	/**
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_equiticket_reservation_action', array( __CLASS__, 'handle_reservation_action' ) );
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
	 * 予約 1 件に対する操作（来場・取消・戻す・メール再送・削除）。
	 *
	 * @return void
	 */
	public static function handle_reservation_action() {
		self::require_cap();
		check_admin_referer( 'equiticket_reservation_action' );

		$reservation_id = isset( $_POST['reservation_id'] ) ? (int) $_POST['reservation_id'] : 0;
		$operation      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$reservation    = EquiTicket_Reservations::get( $reservation_id );

		if ( ! $reservation ) {
			EquiTicket_Admin::add_notice( __( '対象の予約が見つかりませんでした。', 'equiticket' ), 'error' );
			self::redirect_back();
		}

		switch ( $operation ) {
			case 'checkin':
				$result = EquiTicket_Checkin::process( $reservation->qr_token, array( 'override' => true ) );
				EquiTicket_Admin::add_notice(
					sprintf(
						/* translators: 1: 照合結果, 2: 予約者名 */
						__( '%1$s：%2$s 様', 'equiticket' ),
						$result['headline'],
						$reservation->name
					),
					'ok' === $result['code'] ? 'success' : 'warning'
				);
				break;

			case 'cancel':
				EquiTicket_Reservations::cancel( $reservation, 'cancelled' );
				EquiTicket_Admin::add_notice( __( '予約を取り消しました。', 'equiticket' ), 'success' );
				break;

			case 'no_show':
				EquiTicket_Reservations::cancel( $reservation, 'no_show' );
				EquiTicket_Admin::add_notice( __( '無断キャンセルにして、枠を解放しました。', 'equiticket' ), 'success' );
				break;

			case 'restore':
				EquiTicket_Reservations::restore( $reservation );
				EquiTicket_Admin::add_notice( __( '予約済みの状態に戻しました。', 'equiticket' ), 'success' );
				break;

			case 'resend_mail':
				$slot = EquiTicket_Slots::get( $reservation->slot_id );
				$sent = $slot ? EquiTicket_Mailer::send_confirmation( $reservation, $slot ) : false;
				EquiTicket_Admin::add_notice(
					$sent ? __( '確認メールを再送しました。', 'equiticket' ) : __( 'メールを送信できませんでした。送信設定をご確認ください。', 'equiticket' ),
					$sent ? 'success' : 'error'
				);
				break;

			case 'delete':
				// 記録ごと消すので、時間枠や設定を変更できる運営側だけに許可する。
				if ( ! EquiTicket_Utils::can_manage() ) {
					EquiTicket_Admin::add_notice( __( '予約を削除する権限がありません。', 'equiticket' ), 'error' );
					break;
				}
				$deleted = EquiTicket_Reservations::delete( $reservation );
				EquiTicket_Admin::add_notice(
					$deleted
						/* translators: %s: 予約者名 */
						? sprintf( __( '%s 様の予約を削除しました。', 'equiticket' ), $reservation->name )
						: __( '予約を削除できませんでした。', 'equiticket' ),
					$deleted ? 'success' : 'error'
				);
				break;

			default:
				EquiTicket_Admin::add_notice( __( '不明な操作です。', 'equiticket' ), 'error' );
				break;
		}

		self::redirect_back();
	}

	/**
	 * 直前の画面に戻る。
	 *
	 * @return void
	 */
	private static function redirect_back() {
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : EquiTicket_Admin::page_url( 'equiticket-dashboard' ) );
		exit;
	}

	/**
	 * 当日ダッシュボード（今日以降の日）を出力する。
	 *
	 * @return void
	 */
	public static function render() {
		self::render_page( false );
	}

	/**
	 * 過去の記録（昨日までの日）を出力する。
	 *
	 * @return void
	 */
	public static function render_records() {
		self::render_page( true );
	}

	/**
	 * ダッシュボードと過去の記録の共通部分。
	 *
	 * @param bool $past 過去の記録なら true。
	 * @return void
	 */
	private static function render_page( $past ) {
		self::require_cap();
		EquiTicket_Cron::maybe_sweep();

		$page  = $past ? 'equiticket-records' : 'equiticket-dashboard';
		$today = EquiTicket_Utils::today();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示条件の読み取りのみ。
		$date    = isset( $_GET['date'] ) ? EquiTicket_Utils::sanitize_date( wp_unslash( $_GET['date'] ) ) : '';
		$slot_id = isset( $_GET['slot'] ) ? sanitize_key( wp_unslash( $_GET['slot'] ) ) : '';
		$search  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:enable

		// 当日ダッシュボードは今日以降、過去の記録は昨日までの日だけを選べる。
		$dates = array_values(
			array_filter(
				EquiTicket_Slots::dates(),
				static function ( $item ) use ( $past, $today ) {
					return $past ? $item < $today : $item >= $today;
				}
			)
		);
		if ( '' === $date || ! in_array( $date, $dates, true ) ) {
			$date = $past ? ( $dates ? end( $dates ) : '' ) : $today;
		}

		echo '<div class="wrap equiticket-wrap">';
		echo '<h1>' . esc_html( $past ? __( '過去の記録', 'equiticket' ) : __( '当日ダッシュボード', 'equiticket' ) ) . '</h1>';
		if ( $past ) {
			echo '<p class="description">' . esc_html__( '昨日までの予約と来場の記録です。来年の参考にできるよう、記録はそのまま残しています。', 'equiticket' ) . '</p>';
		}

		self::render_search_form( $page, $date, $search );

		if ( '' !== $search ) {
			self::render_search_results( $search );
			echo '</div>';

			return;
		}

		if ( '' === $date ) {
			echo '<p>' . esc_html__( '過去の記録はまだありません。', 'equiticket' ) . '</p></div>';

			return;
		}

		self::render_date_nav( $page, $dates ? $dates : array( $date ), $date );
		if ( ! $past ) {
			self::render_toolbar( $date );
		}

		$slots = EquiTicket_Slots::query( array( 'date' => $date ) );
		self::render_summary( $slots, $date );

		if ( ! $slots ) {
			echo '<p>' . esc_html__( 'この日の時間枠は登録されていません。', 'equiticket' ) . '</p>';
			echo '</div>';

			return;
		}

		// 表示する時間枠。指定がなければ、今の枠（なければ次の枠）を開く。過去の記録は最初の枠。
		$selected = null;
		if ( 'all' !== $slot_id ) {
			foreach ( $slots as $slot ) {
				if ( (string) $slot->id === $slot_id ) {
					$selected = $slot;
				}
			}
			if ( ! $selected ) {
				$selected = $past ? $slots[0] : self::current_slot( $slots );
			}
		}

		self::render_waiting_matrix( $page, $date, $slots, $selected, $past );
		self::render_slot_tabs( $page, $date, $slots, $selected );

		foreach ( $slots as $slot ) {
			if ( ! $selected || $selected->id === $slot->id ) {
				self::render_slot_card( $slot );
			}
		}

		if ( ! $past ) {
			printf(
				'<p class="equiticket-walkin-link"><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( EquiTicket_Admin::page_url( 'equiticket-walkin' ) ),
				esc_html__( '当日予約（予約のない方を登録する）を開く', 'equiticket' )
			);
		}

		echo '</div>';
	}

	/**
	 * 今の時刻の枠を返す。なければ次に始まる枠、それもなければ最後の枠。
	 *
	 * @param array<int,object> $slots その日の時間枠（開始順）。
	 * @return object 時間枠。
	 */
	private static function current_slot( array $slots ) {
		$now = EquiTicket_Utils::now();
		foreach ( $slots as $slot ) {
			if ( $slot->ends_at && $slot->ends_at > $now ) {
				return $slot;
			}
		}

		return end( $slots );
	}

	/**
	 * 日付の切り替え。
	 *
	 * @param string            $page  画面のスラッグ。
	 * @param array<int,string> $dates 選べる日付。
	 * @param string            $date  表示中の日付。
	 * @return void
	 */
	private static function render_date_nav( $page, array $dates, $date ) {
		echo '<ul class="subsubsub">';
		$last = count( $dates ) - 1;
		foreach ( array_values( $dates ) as $index => $item ) {
			printf(
				'<li><a href="%1$s" class="%2$s">%3$s</a>%4$s</li>',
				esc_url( EquiTicket_Admin::page_url( $page, array( 'date' => $item ) ) ),
				esc_attr( $date === $item ? 'current' : '' ),
				esc_html( EquiTicket_Utils::date_label( $item ) ),
				$index === $last ? '' : ' |'
			);
		}
		echo '</ul><div class="clear"></div>';
	}

	/**
	 * 操作ボタン（解放・自動更新・受付画面）。
	 *
	 * @param string $date 表示中の日付。
	 * @return void
	 */
	private static function render_toolbar( $date ) {
		echo '<div class="equiticket-toolbar">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="action" value="equiticket_run_sweep" />';
		wp_nonce_field( 'equiticket_run_sweep' );
		echo '<button type="submit" class="button">' . esc_html__( '未来場を解放（無断キャンセル処理）', 'equiticket' ) . '</button>';
		echo '</form> ';

		printf(
			'<a class="button" href="%s">%s</a> ',
			esc_url( EquiTicket_Admin::page_url( 'equiticket-checkin' ) ),
			esc_html__( '受付画面を開く', 'equiticket' )
		);
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( EquiTicket_Admin::page_url( 'equiticket-roster', array( 'date' => $date ) ) ),
			esc_html__( '名簿を印刷する', 'equiticket' )
		);

		echo '<label class="equiticket-autorefresh"><input type="checkbox" data-equiticket-autorefresh /> '
			. esc_html__( '30 秒ごとに自動更新', 'equiticket' ) . '</label>';

		$last_run = EquiTicket_Cron::last_run();
		if ( '' !== $last_run ) {
			echo '<span class="equiticket-lastrun">' . esc_html(
				sprintf(
					/* translators: %s: 最終実行日時 */
					__( '最後の自動解放: %s', 'equiticket' ),
					$last_run
				)
			) . '</span>';
		}

		echo '</div>';
	}

	/**
	 * 時間枠の切り替え（時刻ごとのボタン）。
	 *
	 * @param string            $page     画面のスラッグ。
	 * @param string            $date     表示中の日付。
	 * @param array<int,object> $slots    その日の時間枠。
	 * @param object|null       $selected 表示中の枠（null ならすべて）。
	 * @return void
	 */
	private static function render_slot_tabs( $page, $date, array $slots, $selected ) {
		echo '<div class="equiticket-slot-tabs">';
		foreach ( $slots as $slot ) {
			$current = $selected && $selected->id === $slot->id;
			printf(
				'<a class="equiticket-slot-tabs__item%1$s%2$s" href="%3$s">%4$s<span>%5$s</span></a>',
				$current ? ' is-current' : '',
				'cancelled' === $slot->status ? ' is-cancelled' : '',
				esc_url( EquiTicket_Admin::page_url( $page, array( 'date' => $date, 'slot' => $slot->id ) ) ),
				esc_html( EquiTicket_Utils::time_label( $slot->start_time ) ),
				esc_html(
					sprintf(
						/* translators: 1: 来場人数, 2: 予約人数 */
						__( '来場 %1$d／%2$d', 'equiticket' ),
						$slot->checked_in_people,
						$slot->booked_people
					)
					. ( $slot->no_show_people > 0
						/* translators: %d: 無断キャンセルの人数 */
						? sprintf( __( '・無断 %d', 'equiticket' ), $slot->no_show_people )
						: '' )
				)
			);
		}
		printf(
			'<a class="equiticket-slot-tabs__item%1$s" href="%2$s">%3$s</a>',
			$selected ? '' : ' is-current',
			esc_url( EquiTicket_Admin::page_url( $page, array( 'date' => $date, 'slot' => 'all' ) ) ),
			esc_html__( 'すべて', 'equiticket' )
		);
		echo '</div>';
	}

	/**
	 * 人の検索欄（名前・メールアドレス・受付番号）。
	 *
	 * @param string $page   画面のスラッグ。
	 * @param string $date   表示中の日付。
	 * @param string $search 検索語。
	 * @return void
	 */
	private static function render_search_form( $page, $date, $search ) {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="equiticket-search-box">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $page ) . '" />';
		if ( '' !== $date ) {
			echo '<input type="hidden" name="date" value="' . esc_attr( $date ) . '" />';
		}
		echo '<input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( '名前・メールアドレス・受付番号で探す', 'equiticket' ) . '" /> ';
		echo '<button type="submit" class="button">' . esc_html__( '検索', 'equiticket' ) . '</button>';
		if ( '' !== $search ) {
			printf(
				' <a href="%1$s">%2$s</a>',
				esc_url( EquiTicket_Admin::page_url( $page, '' !== $date ? array( 'date' => $date ) : array() ) ),
				esc_html__( '検索をやめる', 'equiticket' )
			);
		}
		echo '</form>';
	}

	/**
	 * 検索結果（すべての日の予約から探す）。
	 *
	 * @param string $search 検索語。
	 * @return void
	 */
	private static function render_search_results( $search ) {
		$results = EquiTicket_Reservations::query(
			array(
				'search' => $search,
				'limit'  => 100,
			)
		);

		// 「No.0012」「12」のような受付番号でも探せるようにする。
		$number = (int) preg_replace( '/[^0-9]/', '', $search );
		if ( $number > 0 && preg_match( '/^\s*(no\.?\s*)?\d+\s*$/i', $search ) ) {
			$by_number = EquiTicket_Reservations::get( $number );
			if ( $by_number && ! in_array( $by_number->id, wp_list_pluck( $results, 'id' ), true ) ) {
				$full = EquiTicket_Reservations::query( array( 'slot_id' => $by_number->slot_id ) );
				foreach ( $full as $row ) {
					if ( $row->id === $by_number->id ) {
						array_unshift( $results, $row );
					}
				}
			}
		}

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: 1: 検索語, 2: 件数 */
				__( '「%1$s」の検索結果（%2$d 件）', 'equiticket' ),
				$search,
				count( $results )
			)
		) . '</h2>';

		if ( ! $results ) {
			echo '<p class="equiticket-empty">' . esc_html__( '見つかりませんでした。名前の一部（名字だけなど）でも探せます。', 'equiticket' ) . '</p>';

			return;
		}

		self::render_reservation_table( $results, true );
	}

	/**
	 * 集計（定員・予約・来場など）。
	 *
	 * @param array<int,object> $slots その日の時間枠。
	 * @param string            $date  日付。
	 * @return void
	 */
	private static function render_summary( array $slots, $date ) {
		$totals = array(
			'capacity'        => 0,
			'booked'          => 0,
			'checked_in'      => 0,
			'waiting'         => 0,
			'no_show'         => 0,
			'remaining'       => 0,
		);

		foreach ( $slots as $slot ) {
			$totals['capacity']        += $slot->capacity;
			$totals['booked']          += $slot->booked_people;
			$totals['checked_in']      += $slot->checked_in_people;
			$totals['waiting']         += $slot->waiting_people;
			$totals['no_show']         += $slot->no_show_people;
			$totals['remaining']       += $slot->remaining;
		}

		$boxes = array(
			__( '定員合計', 'equiticket' )       => $totals['capacity'],
			__( '予約人数', 'equiticket' )       => $totals['booked'],
			__( '来場済み', 'equiticket' )       => $totals['checked_in'],
			__( '未来場', 'equiticket' )         => $totals['waiting'],
			__( '無断キャンセル', 'equiticket' ) => $totals['no_show'],
		);

		$fee_total    = EquiTicket_Reservations::fee_total( $date );
		$fee_received = EquiTicket_Reservations::fee_total( $date, array( 'checked_in' ) );
		if ( $fee_total > 0 || $fee_received > 0 ) {
			/* translators: %s: 金額 */
			$boxes[ __( '集金予定額', 'equiticket' ) ] = sprintf( __( '%s 円', 'equiticket' ), number_format_i18n( $fee_total ) );
			/* translators: %s: 金額 */
			$boxes[ __( '来場済み分', 'equiticket' ) ] = sprintf( __( '%s 円', 'equiticket' ), number_format_i18n( $fee_received ) );
		}

		echo '<div class="equiticket-summary">';
		foreach ( $boxes as $label => $value ) {
			echo '<div class="equiticket-summary__box">';
			echo '<span class="equiticket-summary__label">' . esc_html( $label ) . '</span>';
			echo '<span class="equiticket-summary__value">' . esc_html( (string) $value ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * 時間ごと・馬ごとの未来場の表（縦に時間枠、横に馬）。
	 *
	 * 各マスに未来場の人数を大きく出し、来場・予約・残りを小さく添える。
	 * 開始時刻を過ぎても未来場の人がいるマスは、目立つ色にする。
	 *
	 * @param string            $page     画面のスラッグ。
	 * @param string            $date     表示中の日付。
	 * @param array<int,object> $slots    その日の時間枠。
	 * @param object|null       $selected 表示中の枠。
	 * @param bool              $past     過去の記録なら true。
	 * @return void
	 */
	private static function render_waiting_matrix( $page, $date, array $slots, $selected, $past ) {
		// その日に出す馬（馬の種類の表示順）。
		$horses = array();
		foreach ( EquiTicket_Horses::all() as $horse_id => $horse ) {
			foreach ( $slots as $slot ) {
				if ( isset( $slot->horses[ $horse_id ] ) ) {
					$horses[ $horse_id ] = $horse->name;
					break;
				}
			}
		}
		if ( ! $horses ) {
			return;
		}

		$now    = EquiTicket_Utils::now();
		$totals = array();

		echo '<h2>' . esc_html( $past ? __( '時間ごと・馬ごとの状況', 'equiticket' ) : __( '時間ごと・馬ごとの未来場', 'equiticket' ) ) . '</h2>';
		echo '<table class="equiticket-matrix"><thead><tr><th>' . esc_html__( '時間', 'equiticket' ) . '</th>';
		foreach ( $horses as $name ) {
			echo '<th>' . esc_html( $name ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $slots as $slot ) {
			$started = $slot->starts_at && $slot->starts_at <= $now;
			$classes = array();
			if ( $selected && $selected->id === $slot->id ) {
				$classes[] = 'is-current';
			}
			if ( 'cancelled' === $slot->status ) {
				$classes[] = 'is-cancelled';
			}

			echo '<tr class="' . esc_attr( implode( ' ', $classes ) ) . '">';
			printf(
				'<th scope="row"><a href="%1$s">%2$s</a></th>',
				esc_url( EquiTicket_Admin::page_url( $page, array( 'date' => $date, 'slot' => $slot->id ) ) ),
				esc_html( EquiTicket_Utils::time_label( $slot->start_time ) . '〜' . EquiTicket_Utils::time_label( $slot->end_time ) )
			);

			foreach ( $horses as $horse_id => $name ) {
				if ( ! isset( $slot->horses[ $horse_id ] ) ) {
					echo '<td class="is-none">—</td>';
					continue;
				}

				$entry = $slot->horses[ $horse_id ];
				foreach ( array( 'waiting', 'checked_in', 'booked', 'no_show', 'remaining' ) as $key ) {
					$totals[ $horse_id ][ $key ] = ( isset( $totals[ $horse_id ][ $key ] ) ? $totals[ $horse_id ][ $key ] : 0 ) + $entry->$key;
				}

				$cell = 'equiticket-matrix__cell';
				if ( $entry->waiting > 0 && 'cancelled' !== $slot->status ) {
					$cell .= $started ? ' is-late' : ' is-waiting';
				}
				echo '<td class="' . esc_attr( $cell ) . '">';
				echo self::matrix_cell( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中でエスケープ済み。
				echo '</td>';
			}
			echo '</tr>';
		}

		echo '<tr class="equiticket-matrix__total"><th scope="row">' . esc_html__( '合計', 'equiticket' ) . '</th>';
		foreach ( $horses as $horse_id => $name ) {
			echo '<td class="equiticket-matrix__cell">' . self::matrix_cell( (object) $totals[ $horse_id ] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中でエスケープ済み。
		}
		echo '</tr></tbody></table>';

		if ( ! $past ) {
			echo '<p class="description">' . esc_html__( '大きい数字が未来場（予約済みでまだ来ていない人数）です。黄色は未来場あり、赤は開始時刻を過ぎても未来場の人がいる枠です。時間を押すとその枠の名簿が開きます。', 'equiticket' ) . '</p>';
		}
	}

	/**
	 * 表の 1 マス分（未来場を大きく、来場・予約・残り・無断キャンセルを小さく）。
	 *
	 * @param object $entry waiting / checked_in / booked / no_show / remaining を持つ値。
	 * @return string HTML。
	 */
	private static function matrix_cell( $entry ) {
		$html  = '<span class="equiticket-matrix__waiting">' . esc_html(
			sprintf(
				/* translators: %d: 未来場の人数 */
				__( '未来場 %d', 'equiticket' ),
				$entry->waiting
			)
		) . '</span>';
		$html .= '<span class="equiticket-matrix__sub">' . esc_html(
			sprintf(
				/* translators: 1: 来場人数, 2: 予約人数, 3: 残り */
				__( '来場 %1$d／予約 %2$d・残り %3$d', 'equiticket' ),
				$entry->checked_in,
				$entry->booked,
				$entry->remaining
			)
		) . '</span>';
		if ( $entry->no_show > 0 ) {
			$html .= '<span class="equiticket-matrix__noshow">' . esc_html(
				sprintf(
					/* translators: %d: 無断キャンセルの人数 */
					__( '無断キャンセル %d', 'equiticket' ),
					$entry->no_show
				)
			) . '</span>';
		}

		return $html;
	}

	/**
	 * 時間枠ごとのカード（予約一覧つき）。
	 *
	 * @param object $slot 時間枠。
	 * @return void
	 */
	private static function render_slot_card( $slot ) {
		$reservations = EquiTicket_Reservations::query( array( 'slot_id' => $slot->id ) );

		echo '<div class="equiticket-card">';
		echo '<div class="equiticket-card__header">';
		echo '<h2>' . esc_html( EquiTicket_Utils::time_label( $slot->start_time ) . '〜' . EquiTicket_Utils::time_label( $slot->end_time ) ) . '</h2>';
		echo '<p class="equiticket-card__meta">' . esc_html( EquiTicket_Slots::horses_summary( $slot ) ) . '</p>';
		echo '<p class="equiticket-card__meta">' . esc_html(
			sprintf(
				/* translators: 1: 予約人数, 2: 来場人数, 3: 状態 */
				__( '予約 %1$d 名／来場 %2$d 名／%3$s', 'equiticket' ),
				$slot->booked_people,
				$slot->checked_in_people,
				EquiTicket_Utils::slot_status_label( $slot->status )
			)
		) . '</p>';
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( EquiTicket_Admin::page_url( 'equiticket-slots', array( 'edit' => $slot->id ) ) ),
			esc_html__( '頭数・定員を変更', 'equiticket' )
		);
		echo '</div>';

		if ( ! $reservations ) {
			echo '<p class="equiticket-empty">' . esc_html__( 'この枠の予約はまだありません。', 'equiticket' ) . '</p>';
			echo '</div>';

			return;
		}

		self::render_reservation_table( $reservations, false );
		echo '</div>';
	}

	/**
	 * 予約の一覧表（時間枠のカードと検索結果で共通）。
	 *
	 * 過去に無断キャンセルしたことがある人（同じメールアドレス）には印を付ける。
	 *
	 * @param array<int,object> $reservations 予約。
	 * @param bool              $with_slot    日時の列を出すなら true（検索結果）。
	 * @return void
	 */
	private static function render_reservation_table( array $reservations, $with_slot ) {
		$no_shows = EquiTicket_Reservations::no_show_counts( wp_list_pluck( $reservations, 'email' ) );

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		if ( $with_slot ) {
			echo '<th>' . esc_html__( '日時', 'equiticket' ) . '</th>';
		}
		echo '<th>' . esc_html__( '受付番号', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( 'お名前', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '乗る馬', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '体格', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '人数', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '支払', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '状態', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '受付時刻', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( 'メール', 'equiticket' ) . '</th>';
		echo '<th>' . esc_html__( '操作', 'equiticket' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $reservations as $reservation ) {
			echo '<tr' . ( 'no_show' === $reservation->status ? ' class="equiticket-row--no-show"' : '' ) . '>';
			if ( $with_slot ) {
				echo '<td>' . esc_html( EquiTicket_Utils::date_label( $reservation->event_date ) . ' ' . EquiTicket_Utils::time_label( $reservation->start_time ) ) . '</td>';
			}
			echo '<td>' . esc_html( EquiTicket_Mailer::ticket_number( $reservation ) ) . '</td>';
			echo '<td>' . esc_html( $reservation->name );
			if ( 'staff' === $reservation->source ) {
				echo ' <span class="equiticket-tag">' . esc_html__( '当日枠', 'equiticket' ) . '</span>';
			}
			if ( '' !== (string) $reservation->note ) {
				echo '<br /><span class="equiticket-muted">' . esc_html( $reservation->note ) . '</span>';
			}
			if ( $reservation->photo_optout ) {
				echo '<br /><span class="equiticket-tag">' . esc_html__( '写真掲載NG', 'equiticket' ) . '</span>';
			}
			$key = strtolower( (string) $reservation->email );
			// この予約自体を除いた、ほかの予約での無断キャンセルの回数。
			$past = ( '' !== $key && isset( $no_shows[ $key ] ) ) ? $no_shows[ $key ] - ( 'no_show' === $reservation->status ? 1 : 0 ) : 0;
			if ( $past > 0 ) {
				echo '<br /><span class="equiticket-tag equiticket-tag--warning">' . esc_html(
					sprintf(
						/* translators: %d: 無断キャンセルの回数 */
						__( '無断キャンセル歴あり（%d 回）', 'equiticket' ),
						$past
					)
				) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( EquiTicket_Reservations::horse_label( $reservation ) ) . '</td>';
			echo '<td>' . esc_html( self::profile_label( $reservation ) ) . '</td>';
			echo '<td>' . esc_html( (string) $reservation->people_count ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) $reservation->fee_label ? $reservation->fee_label : '—' ) . '</td>';
			echo '<td><span class="equiticket-status equiticket-status--' . esc_attr( $reservation->status ) . '">'
				. esc_html( EquiTicket_Utils::status_label( $reservation->status ) ) . '</span></td>';
			echo '<td>' . esc_html( $reservation->checked_in_at ? EquiTicket_Utils::time_label( substr( (string) $reservation->checked_in_at, 11 ) ) : '—' ) . '</td>';
			echo '<td>';
			if ( '' === (string) $reservation->email ) {
				echo '<span class="equiticket-muted">' . esc_html__( 'なし', 'equiticket' ) . '</span>';
			} else {
				echo esc_html( $reservation->email ) . '<br />';
				echo '<span class="equiticket-muted">' . esc_html(
					$reservation->mail_sent_at
						? __( '送信済み', 'equiticket' )
						: __( '未送信', 'equiticket' )
				) . '</span>';
			}
			echo '</td>';
			echo '<td class="equiticket-row-actions">';

			if ( in_array( $reservation->status, array( 'reserved', 'no_show' ), true ) ) {
				self::action_button( $reservation->id, 'checkin', __( '来場済みにする', 'equiticket' ) );
			}
			if ( 'reserved' === $reservation->status ) {
				self::action_button( $reservation->id, 'no_show', __( '無断キャンセル', 'equiticket' ) );
				self::action_button( $reservation->id, 'cancel', __( '取消', 'equiticket' ) );
			}
			if ( in_array( $reservation->status, array( 'checked_in', 'cancelled', 'no_show' ), true ) ) {
				self::action_button( $reservation->id, 'restore', __( '予約済みに戻す', 'equiticket' ) );
			}
			if ( '' !== (string) $reservation->email ) {
				self::action_button( $reservation->id, 'resend_mail', __( 'メール再送', 'equiticket' ) );
			}

			printf(
				'<a class="button button-small" href="%s" target="_blank" rel="noopener">%s</a> ',
				esc_url( EquiTicket_Frontend::ticket_url( $reservation->qr_token ) ),
				esc_html__( '整理券を表示', 'equiticket' )
			);
			if ( EquiTicket_Utils::can_manage() ) {
				self::action_button(
					$reservation->id,
					'delete',
					__( '削除', 'equiticket' ),
					sprintf(
						/* translators: %s: 予約者名 */
						__( '%s 様の予約を削除します。来場の記録も消え、元に戻せません。よろしいですか？', 'equiticket' ),
						$reservation->name
					)
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * 体格の表示（身長・体重・年齢）。
	 *
	 * @param object $reservation 予約。
	 * @return string 表示用テキスト。
	 */
	private static function profile_label( $reservation ) {
		if ( ! $reservation->height_cm && ! $reservation->weight_kg && ! $reservation->age ) {
			return '—';
		}

		return sprintf(
			/* translators: 1: 身長, 2: 体重, 3: 年齢 */
			__( '%1$dcm / %2$dkg / %3$d歳', 'equiticket' ),
			$reservation->height_cm,
			$reservation->weight_kg,
			$reservation->age
		);
	}

	/**
	 * 予約操作ボタン（1 つの form）。
	 *
	 * @param int    $reservation_id 予約 ID。
	 * @param string $operation      操作名。
	 * @param string $label          ボタンのラベル。
	 * @param string $confirm        押したときに確認する文（空なら確認しない）。
	 * @return void
	 */
	private static function action_button( $reservation_id, $operation, $label, $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		echo '<input type="hidden" name="action" value="equiticket_reservation_action" />';
		echo '<input type="hidden" name="reservation_id" value="' . esc_attr( (string) $reservation_id ) . '" />';
		echo '<input type="hidden" name="op" value="' . esc_attr( $operation ) . '" />';
		wp_nonce_field( 'equiticket_reservation_action' );
		echo '<button type="submit" class="button button-small' . ( '' !== $confirm ? ' button-link-delete' : '' ) . '"'
			. ( '' !== $confirm ? ' data-equiticket-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button>';
		echo '</form> ';
	}
}
