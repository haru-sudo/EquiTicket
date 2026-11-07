<?php
/**
 * 時間枠（time_slots）の操作。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 時間枠の取得・作成・更新・削除と残り人数の計算。
 */
final class EquiTicket_Slots {

	/**
	 * 時間枠を 1 件取得する。
	 *
	 * @param int $slot_id 時間枠 ID。
	 * @return object|null 時間枠（統計値付き）。存在しなければ null。
	 */
	public static function get( $slot_id ) {
		$slots = self::query( array( 'slot_id' => (int) $slot_id ) );

		return $slots ? $slots[0] : null;
	}

	/**
	 * 条件に合う時間枠を、予約人数・来場人数付きで取得する。
	 *
	 * @param array<string,mixed> $args 検索条件。
	 *                                  slot_id: 時間枠 ID。
	 *                                  date: 日付（Y-m-d）。
	 *                                  from_date: この日以降。
	 *                                  statuses: 枠ステータスの配列。
	 * @return array<int,object> 時間枠の配列。
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$slots_table        = EquiTicket_DB::slots_table();
		$reservations_table = EquiTicket_DB::reservations_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['slot_id'] ) ) {
			$where[]  = 's.id = %d';
			$params[] = (int) $args['slot_id'];
		}
		if ( ! empty( $args['date'] ) ) {
			$where[]  = 's.event_date = %s';
			$params[] = $args['date'];
		}
		if ( ! empty( $args['from_date'] ) ) {
			$where[]  = 's.event_date >= %s';
			$params[] = $args['from_date'];
		}
		if ( ! empty( $args['statuses'] ) && is_array( $args['statuses'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $args['statuses'] ), '%s' ) );
			$where[]      = "s.status IN ({$placeholders})";
			$params       = array_merge( $params, array_values( $args['statuses'] ) );
		}

		$sql = "SELECT s.*,
				COALESCE( SUM( CASE WHEN r.status IN ( 'reserved', 'checked_in' ) THEN r.people_count ELSE 0 END ), 0 ) AS booked_people,
				COALESCE( SUM( CASE WHEN r.status = 'checked_in' THEN r.people_count ELSE 0 END ), 0 ) AS checked_in_people,
				COALESCE( SUM( CASE WHEN r.status = 'reserved' THEN r.people_count ELSE 0 END ), 0 ) AS waiting_people,
				COALESCE( SUM( CASE WHEN r.status = 'no_show' THEN r.people_count ELSE 0 END ), 0 ) AS no_show_people,
				COALESCE( SUM( CASE WHEN r.status IN ( 'reserved', 'checked_in' ) THEN 1 ELSE 0 END ), 0 ) AS reservation_count
			FROM {$slots_table} AS s
			LEFT JOIN {$reservations_table} AS r ON r.slot_id = s.id
			WHERE " . implode( ' AND ', $where ) . '
			GROUP BY s.id
			ORDER BY s.event_date ASC, s.start_time ASC, s.id ASC';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- プレースホルダーは上で組み立て、値は prepare で埋める。
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		$rows = $wpdb->get_results( $sql );
		// phpcs:enable

		$slots = array_map( array( __CLASS__, 'decorate' ), $rows ? $rows : array() );
		self::attach_horses( $slots );

		return $slots;
	}

	/**
	 * 時間枠に、出す馬ごとの頭数・定員・予約人数・残りを付ける（$slot->horses）。
	 *
	 * @param array<int,object> $slots 時間枠（decorate 済み）。
	 * @return void
	 */
	private static function attach_horses( array $slots ) {
		global $wpdb;

		if ( ! $slots ) {
			return;
		}

		$ids          = implode( ',', array_map( 'intval', wp_list_pluck( $slots, 'id' ) ) );
		$slot_horses  = EquiTicket_DB::slot_horses_table();
		$reservations = EquiTicket_DB::reservations_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- テーブル名と整数の ID だけで組み立てた固定クエリ。
		$rows = $wpdb->get_results(
			"SELECT sh.slot_id, sh.horse_id, sh.horse_count, sh.capacity,
				COALESCE( SUM( CASE WHEN r.status IN ( 'reserved', 'checked_in' ) THEN r.people_count ELSE 0 END ), 0 ) AS booked,
				COALESCE( SUM( CASE WHEN r.status = 'checked_in' THEN r.people_count ELSE 0 END ), 0 ) AS checked_in,
				COALESCE( SUM( CASE WHEN r.status = 'reserved' THEN r.people_count ELSE 0 END ), 0 ) AS waiting,
				COALESCE( SUM( CASE WHEN r.status = 'no_show' THEN r.people_count ELSE 0 END ), 0 ) AS no_show
			FROM {$slot_horses} AS sh
			LEFT JOIN {$reservations} AS r ON r.slot_id = sh.slot_id AND r.horse_id = sh.horse_id
			WHERE sh.slot_id IN ({$ids})
			GROUP BY sh.id, sh.slot_id, sh.horse_id, sh.horse_count, sh.capacity"
		);
		// phpcs:enable

		$by_slot = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$by_slot[ (int) $row->slot_id ][ (int) $row->horse_id ] = $row;
		}

		$horses = EquiTicket_Horses::all();
		foreach ( $slots as $slot ) {
			$slot->horses      = array();
			$slot->horse_total = 0;

			// 馬の種類の表示順に並べる。
			$entries = isset( $by_slot[ $slot->id ] ) ? $by_slot[ $slot->id ] : array();
			uksort(
				$entries,
				static function ( $a, $b ) use ( $horses ) {
					$order_a = isset( $horses[ $a ] ) ? $horses[ $a ]->sort_order : 9999;
					$order_b = isset( $horses[ $b ] ) ? $horses[ $b ]->sort_order : 9999;

					return ( $order_a === $order_b ) ? $a - $b : $order_a - $order_b;
				}
			);

			foreach ( $entries as $horse_id => $row ) {
				$capacity = (int) $row->capacity;
				$booked   = (int) $row->booked;

				$slot->horses[ $horse_id ] = (object) array(
					'horse_id'    => $horse_id,
					'name'        => isset( $horses[ $horse_id ] ) ? $horses[ $horse_id ]->name : __( '（削除された馬）', 'equiticket' ),
					'horse_count' => (int) $row->horse_count,
					'capacity'    => $capacity,
					'booked'      => $booked,
					'checked_in'  => (int) $row->checked_in,
					'waiting'     => (int) $row->waiting,
					'no_show'     => (int) $row->no_show,

					// 馬ごとの残りは「その馬の定員の残り」と「枠全体の残り」の少ないほう。
					'remaining'   => min( $slot->remaining, max( 0, $capacity - $booked ) ),
				);
				$slot->horse_total += (int) $row->horse_count;
			}
		}
	}

	/**
	 * 時間枠が登録されている日付の一覧を返す。
	 *
	 * @return array<int,string> 日付（Y-m-d）の配列。
	 */
	public static function dates() {
		global $wpdb;

		$slots_table = EquiTicket_DB::slots_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- テーブル名のみの固定クエリ。
		$dates = $wpdb->get_col( "SELECT DISTINCT event_date FROM {$slots_table} ORDER BY event_date ASC" );

		return $dates ? $dates : array();
	}

	/**
	 * 取得した行に型変換と計算値を付ける。
	 *
	 * @param object $row DB の行。
	 * @return object 加工済みの時間枠。
	 */
	private static function decorate( $row ) {
		$row->id                = (int) $row->id;
		$row->capacity          = (int) $row->capacity;
		$row->booked_people     = (int) $row->booked_people;
		$row->checked_in_people = (int) $row->checked_in_people;
		$row->waiting_people    = (int) $row->waiting_people;
		$row->no_show_people    = (int) $row->no_show_people;
		$row->reservation_count = (int) $row->reservation_count;
		$row->remaining         = max( 0, $row->capacity - $row->booked_people );

		$row->starts_at = EquiTicket_Utils::make_datetime( $row->event_date, $row->start_time );
		$row->ends_at   = EquiTicket_Utils::make_datetime( $row->event_date, $row->end_time );

		return $row;
	}

	/**
	 * 指定した馬で、あと何人受けられるかを返す。
	 *
	 * @param object   $slot     時間枠。
	 * @param int|null $horse_id 馬の種類の ID。null なら枠全体の残り。
	 * @return int 残り人数。
	 */
	public static function remaining_for( $slot, $horse_id = null ) {
		if ( null === $horse_id ) {
			return (int) $slot->remaining;
		}

		return isset( $slot->horses[ (int) $horse_id ] ) ? (int) $slot->horses[ (int) $horse_id ]->remaining : 0;
	}

	/**
	 * その枠で、指定した馬の受付をしているか（定員が 1 以上あるか）。
	 *
	 * @param object $slot     時間枠。
	 * @param int    $horse_id 馬の種類の ID。
	 * @return bool 受付をしていれば true。
	 */
	public static function offers( $slot, $horse_id ) {
		return isset( $slot->horses[ (int) $horse_id ] ) && $slot->horses[ (int) $horse_id ]->capacity > 0;
	}

	/**
	 * 今日以降に、時間枠が用意されている馬の ID（中止の枠と、受付をやめた馬は除く）。
	 *
	 * @return array<int,int> 馬の種類の ID。
	 */
	public static function offered_types() {
		$active  = EquiTicket_Horses::all( true );
		$offered = array();
		foreach ( self::query( array( 'from_date' => EquiTicket_Utils::today() ) ) as $slot ) {
			if ( 'cancelled' === $slot->status ) {
				continue;
			}
			foreach ( $slot->horses as $horse_id => $entry ) {
				if ( $entry->capacity > 0 && isset( $active[ $horse_id ] ) ) {
					$offered[ $horse_id ] = true;
				}
			}
		}

		return array_keys( $offered );
	}

	/**
	 * 枠の馬を 1 行にまとめる（例: 「ポニー 1 頭・定員 4 名・残り 3 名・未来場 1 名 ／ 馬 …」）。
	 *
	 * @param object $slot   時間枠。
	 * @param string $format 1 頭分の書式（name / horse_count / capacity / remaining / waiting を %1$s〜%5$d で受ける）。
	 * @return string まとめ。馬が登録されていなければ「馬が未設定」。
	 */
	public static function horses_summary( $slot, $format = '' ) {
		if ( '' === $format ) {
			/* translators: 1: 馬の種類, 2: 頭数, 3: 定員, 4: 残り, 5: 未来場 */
			$format = __( '%1$s %2$d 頭・定員 %3$d 名・残り %4$d 名・未来場 %5$d 名', 'equiticket' );
		}

		$parts = array();
		foreach ( $slot->horses as $entry ) {
			$parts[] = sprintf( $format, $entry->name, $entry->horse_count, $entry->capacity, $entry->remaining, $entry->waiting );
		}

		return $parts ? implode( ' ／ ', $parts ) : __( '馬が未設定', 'equiticket' );
	}

	/**
	 * 時間枠を作成する。
	 *
	 * @param array<string,mixed> $data 入力値。
	 * @return int|WP_Error 作成した ID、または検証エラー。
	 */
	public static function create( array $data ) {
		global $wpdb;

		$clean = self::sanitize( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$now      = EquiTicket_Utils::now_for_db();
		$inserted = $wpdb->insert(
			EquiTicket_DB::slots_table(),
			array_merge(
				$clean['slot'],
				array(
					'created_at' => $now,
					'updated_at' => $now,
				)
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'equiticket_slot_insert_failed', __( '時間枠の保存に失敗しました。', 'equiticket' ) );
		}

		$slot_id = (int) $wpdb->insert_id;
		self::save_horses( $slot_id, $clean['horses'] );

		return $slot_id;
	}

	/**
	 * 時間枠を更新する。
	 *
	 * @param int                 $slot_id 時間枠 ID。
	 * @param array<string,mixed> $data    入力値。
	 * @return true|WP_Error 成功なら true。
	 */
	public static function update( $slot_id, array $data ) {
		global $wpdb;

		$clean = self::sanitize( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$updated = $wpdb->update(
			EquiTicket_DB::slots_table(),
			array_merge( $clean['slot'], array( 'updated_at' => EquiTicket_Utils::now_for_db() ) ),
			array( 'id' => (int) $slot_id ),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'equiticket_slot_update_failed', __( '時間枠の更新に失敗しました。', 'equiticket' ) );
		}

		self::save_horses( (int) $slot_id, $clean['horses'] );

		return true;
	}

	/**
	 * 時間枠に出す馬（頭数・定員）を保存し直す。
	 *
	 * 予約が入っている馬は、定員を 0 にしても行を残す（予約の集計と表示のため）。
	 *
	 * @param int                           $slot_id 時間枠 ID。
	 * @param array<int,array<string,int>> $horses  馬の ID => horse_count / capacity。
	 * @return void
	 */
	private static function save_horses( $slot_id, array $horses ) {
		global $wpdb;

		$table        = EquiTicket_DB::slot_horses_table();
		$reservations = EquiTicket_DB::reservations_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
		$booked_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( "SELECT DISTINCT horse_id FROM {$reservations} WHERE slot_id = %d", $slot_id )
			)
		);
		$wpdb->delete( $table, array( 'slot_id' => $slot_id ), array( '%d' ) );
		// phpcs:enable

		foreach ( $booked_ids as $horse_id ) {
			if ( $horse_id > 0 && ! isset( $horses[ $horse_id ] ) ) {
				$horses[ $horse_id ] = array(
					'horse_count' => 0,
					'capacity'    => 0,
				);
			}
		}

		foreach ( $horses as $horse_id => $entry ) {
			$wpdb->insert(
				$table,
				array(
					'slot_id'     => $slot_id,
					'horse_id'    => (int) $horse_id,
					'horse_count' => (int) $entry['horse_count'],
					'capacity'    => (int) $entry['capacity'],
				),
				array( '%d', '%d', '%d', '%d' )
			);
		}
	}

	/**
	 * 時間枠を削除する。有効な予約が残っている場合は削除しない。
	 *
	 * @param int $slot_id 時間枠 ID。
	 * @return true|WP_Error 成功なら true。
	 */
	public static function delete( $slot_id ) {
		global $wpdb;

		$slot = self::get( $slot_id );
		if ( ! $slot ) {
			return new WP_Error( 'equiticket_slot_missing', __( '対象の時間枠が見つかりません。', 'equiticket' ) );
		}
		if ( $slot->reservation_count > 0 ) {
			return new WP_Error(
				'equiticket_slot_has_reservations',
				__( '予約が入っている時間枠は削除できません。中止にする場合はステータスを「中止」に変更してください。', 'equiticket' )
			);
		}

		$wpdb->delete( EquiTicket_DB::reservations_table(), array( 'slot_id' => (int) $slot_id ), array( '%d' ) );
		$wpdb->delete( EquiTicket_DB::slot_horses_table(), array( 'slot_id' => (int) $slot_id ), array( '%d' ) );
		$wpdb->delete( EquiTicket_DB::slots_table(), array( 'id' => (int) $slot_id ), array( '%d' ) );

		return true;
	}

	/**
	 * 時間枠と、その枠の予約をまとめて削除する。
	 *
	 * @param string $date 対象の日付（Y-m-d）。空ならすべて。
	 * @return array<string,int> 削除した件数（slots / reservations）。
	 */
	public static function delete_all( $date = '' ) {
		global $wpdb;

		$slots        = self::query( $date ? array( 'date' => $date ) : array() );
		$reservations = EquiTicket_Reservations::delete_all( $date );
		$removed      = 0;

		foreach ( $slots as $slot ) {
			$wpdb->delete( EquiTicket_DB::slot_horses_table(), array( 'slot_id' => $slot->id ), array( '%d' ) );
			$removed += (int) $wpdb->delete( EquiTicket_DB::slots_table(), array( 'id' => $slot->id ), array( '%d' ) );
		}

		return array(
			'slots'        => $removed,
			'reservations' => $reservations,
		);
	}

	/**
	 * 開始時刻から一定間隔で時間枠をまとめて作成する。
	 *
	 * @param array<string,mixed> $args 一括作成の条件。
	 * @return int|WP_Error 作成した件数、または検証エラー。
	 */
	public static function bulk_create( array $args ) {
		$date   = EquiTicket_Utils::sanitize_date( isset( $args['event_date'] ) ? $args['event_date'] : '' );
		$start  = EquiTicket_Utils::sanitize_time( isset( $args['start_time'] ) ? $args['start_time'] : '' );
		$end    = EquiTicket_Utils::sanitize_time( isset( $args['end_time'] ) ? $args['end_time'] : '' );
		$length = EquiTicket_Utils::clamp_int( isset( $args['slot_minutes'] ) ? $args['slot_minutes'] : 0, 5, 240, 0 );
		$break  = EquiTicket_Utils::clamp_int( isset( $args['break_minutes'] ) ? $args['break_minutes'] : 0, 0, 240, 0 );
		$horses = isset( $args['horses'] ) && is_array( $args['horses'] ) ? $args['horses'] : array();

		if ( '' === $date || '' === $start || '' === $end ) {
			return new WP_Error( 'equiticket_bulk_invalid', __( '日付・開始時刻・終了時刻を入力してください。', 'equiticket' ) );
		}
		if ( 0 === $length ) {
			return new WP_Error( 'equiticket_bulk_invalid_length', __( '1 枠の長さ（分）を 5 以上で入力してください。', 'equiticket' ) );
		}

		$cursor = EquiTicket_Utils::make_datetime( $date, $start );
		$limit  = EquiTicket_Utils::make_datetime( $date, $end );
		if ( ! $cursor || ! $limit || $cursor >= $limit ) {
			return new WP_Error( 'equiticket_bulk_invalid_range', __( '終了時刻は開始時刻より後にしてください。', 'equiticket' ) );
		}

		$created = 0;
		$guard   = 0;
		while ( $cursor < $limit && $guard < 200 ) {
			$guard++;
			$slot_end = $cursor->modify( sprintf( '+%d minutes', $length ) );
			if ( $slot_end > $limit ) {
				break;
			}

			$result = self::create(
				array(
					'event_date' => $date,
					'start_time' => $cursor->format( 'H:i:s' ),
					'end_time'   => $slot_end->format( 'H:i:s' ),
					'horses'     => $horses,
					'status'     => 'open',
					'note'       => isset( $args['note'] ) ? $args['note'] : '',
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$created++;
			$cursor = $slot_end->modify( sprintf( '+%d minutes', $break ) );
		}

		if ( 0 === $created ) {
			return new WP_Error( 'equiticket_bulk_none', __( '作成できる時間枠がありませんでした。時刻と 1 枠の長さを確認してください。', 'equiticket' ) );
		}

		return $created;
	}

	/**
	 * 入力値を検証する。
	 *
	 * @param array<string,mixed> $data 入力値。
	 * @return array<string,mixed>|WP_Error 保存用の配列、またはエラー。
	 */
	private static function sanitize( array $data ) {
		$date  = EquiTicket_Utils::sanitize_date( isset( $data['event_date'] ) ? $data['event_date'] : '' );
		$start = EquiTicket_Utils::sanitize_time( isset( $data['start_time'] ) ? $data['start_time'] : '' );
		$end   = EquiTicket_Utils::sanitize_time( isset( $data['end_time'] ) ? $data['end_time'] : '' );

		if ( '' === $date ) {
			return new WP_Error( 'equiticket_slot_date', __( '日付を YYYY-MM-DD 形式で入力してください。', 'equiticket' ) );
		}
		if ( '' === $start || '' === $end ) {
			return new WP_Error( 'equiticket_slot_time', __( '開始時刻と終了時刻を入力してください。', 'equiticket' ) );
		}
		if ( $start >= $end ) {
			return new WP_Error( 'equiticket_slot_range', __( '終了時刻は開始時刻より後にしてください。', 'equiticket' ) );
		}

		$status = isset( $data['status'] ) ? (string) $data['status'] : 'open';
		if ( ! in_array( $status, array( 'open', 'closed', 'cancelled' ), true ) ) {
			$status = 'open';
		}

		// 出す馬: 馬の ID => 頭数・定員。頭数も定員も 0 の馬は出さない。
		$horses = array();
		$total  = 0;
		$known  = EquiTicket_Horses::all();
		$input  = isset( $data['horses'] ) && is_array( $data['horses'] ) ? $data['horses'] : array();
		foreach ( $input as $horse_id => $entry ) {
			$horse_id = (int) $horse_id;
			if ( ! isset( $known[ $horse_id ] ) || ! is_array( $entry ) ) {
				continue;
			}
			$count    = EquiTicket_Utils::clamp_int( isset( $entry['horse_count'] ) && '' !== $entry['horse_count'] ? $entry['horse_count'] : 0, 0, 50, 0 );
			$capacity = EquiTicket_Utils::clamp_int( isset( $entry['capacity'] ) && '' !== $entry['capacity'] ? $entry['capacity'] : 0, 0, 999, 0 );
			if ( 0 === $count && 0 === $capacity ) {
				continue;
			}
			$horses[ $horse_id ] = array(
				'horse_count' => $count,
				'capacity'    => $capacity,
			);
			$total += $capacity;
		}

		return array(
			'slot'   => array(
				'event_date' => $date,
				'start_time' => $start,
				'end_time'   => $end,

				// 合計定員は馬ごとの定員から自動で決める（二重管理を避けるため）。
				'capacity'   => $total,
				'status'     => $status,
				'note'       => sanitize_text_field( isset( $data['note'] ) ? $data['note'] : '' ),
			),
			'horses' => $horses,
		);
	}

	/**
	 * 一般来場者が予約できる枠かどうかを判定する。
	 *
	 * @param object   $slot 時間枠。
	 * @param int|null $type 馬の種類の ID。指定すると、その馬の空きも確認する。
	 * @return true|WP_Error 予約できるなら true、できない理由を持つ WP_Error。
	 */
	public static function check_reservable( $slot, $type = null ) {
		if ( 'cancelled' === $slot->status ) {
			return new WP_Error( 'equiticket_slot_cancelled', __( 'この時間枠は中止になりました。', 'equiticket' ) );
		}
		if ( 'closed' === $slot->status ) {
			return new WP_Error( 'equiticket_slot_closed', __( 'この時間枠は受付を停止しています。', 'equiticket' ) );
		}
		if ( $slot->remaining <= 0 ) {
			return new WP_Error( 'equiticket_slot_full', __( 'この時間枠は満席です。', 'equiticket' ) );
		}
		if ( null !== $type && ! self::offers( $slot, $type ) ) {
			return new WP_Error(
				'equiticket_type_unavailable',
				sprintf(
					/* translators: %s: 馬の種類 */
					__( 'この時間枠では %s の受付をしていません。', 'equiticket' ),
					EquiTicket_Screening::type_label( $type )
				)
			);
		}
		if ( null !== $type && self::remaining_for( $slot, $type ) <= 0 ) {
			return new WP_Error(
				'equiticket_type_full',
				sprintf(
					/* translators: %s: 馬の種類 */
					__( 'この時間枠は %s の受付がいっぱいです。', 'equiticket' ),
					EquiTicket_Screening::type_label( $type )
				)
			);
		}

		// 締切は枠の終了時刻から数える（枠の途中でも、終了の◯分前までは予約できる）。
		$cutoff = (int) EquiTicket_Options::get( 'reservation_cutoff_minutes' );
		$basis  = $slot->ends_at ? $slot->ends_at : $slot->starts_at;
		if ( $basis ) {
			$deadline = $basis->modify( sprintf( '-%d minutes', $cutoff ) );
			if ( EquiTicket_Utils::now() >= $deadline ) {
				return new WP_Error( 'equiticket_slot_past', __( 'この時間枠の受付は終了しました。', 'equiticket' ) );
			}
		}

		return true;
	}
}
