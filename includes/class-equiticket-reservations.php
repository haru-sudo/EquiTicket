<?php
/**
 * 予約（reservations）の操作。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 予約の作成・取消・チェックインと、定員超過を防ぐ排他制御。
 */
final class EquiTicket_Reservations {

	/**
	 * 予約を 1 件取得する。
	 *
	 * @param int $reservation_id 予約 ID。
	 * @return object|null 予約。存在しなければ null。
	 */
	public static function get( $reservation_id ) {
		global $wpdb;

		$table = EquiTicket_DB::reservations_table();
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $reservation_id )
		);

		return $row ? self::decorate( $row ) : null;
	}

	/**
	 * QR トークンから予約を取得する。
	 *
	 * @param string $token トークン。
	 * @return object|null 予約。存在しなければ null。
	 */
	public static function get_by_token( $token ) {
		global $wpdb;

		$token = EquiTicket_Utils::sanitize_token( $token );
		if ( '' === $token ) {
			return null;
		}

		$table = EquiTicket_DB::reservations_table();
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
			$wpdb->prepare( "SELECT * FROM {$table} WHERE qr_token = %s", $token )
		);

		return $row ? self::decorate( $row ) : null;
	}

	/**
	 * 条件に合う予約を取得する。
	 *
	 * @param array<string,mixed> $args 検索条件（slot_id / date / statuses / search / limit / order）。
	 * @return array<int,object> 予約の配列。
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$reservations = EquiTicket_DB::reservations_table();
		$slots        = EquiTicket_DB::slots_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['slot_id'] ) ) {
			$where[]  = 'r.slot_id = %d';
			$params[] = (int) $args['slot_id'];
		}
		if ( ! empty( $args['date'] ) ) {
			$where[]  = 's.event_date = %s';
			$params[] = $args['date'];
		}
		if ( ! empty( $args['statuses'] ) && is_array( $args['statuses'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $args['statuses'] ), '%s' ) );
			$where[]      = "r.status IN ({$placeholders})";
			$params       = array_merge( $params, array_values( $args['statuses'] ) );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '( r.name LIKE %s OR r.email LIKE %s OR r.qr_token LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$limit = '';
		if ( ! empty( $args['limit'] ) ) {
			$limit = sprintf( ' LIMIT %d', EquiTicket_Utils::clamp_int( $args['limit'], 1, 1000, 100 ) );
		}

		$order = ( isset( $args['order'] ) && 'recent' === $args['order'] )
			? 'r.created_at DESC, r.id DESC'
			: 's.event_date ASC, s.start_time ASC, r.id ASC';

		$sql = "SELECT r.*, s.event_date, s.start_time, s.end_time, s.status AS slot_status, s.capacity
			FROM {$reservations} AS r
			INNER JOIN {$slots} AS s ON s.id = r.slot_id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY {$order}{$limit}";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- プレースホルダーを組み立ててから prepare で値を埋める。
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}
		$rows = $wpdb->get_results( $sql );
		// phpcs:enable

		return array_map( array( __CLASS__, 'decorate' ), $rows ? $rows : array() );
	}

	/**
	 * 行に型変換を施す。
	 *
	 * @param object $row DB の行。
	 * @return object 加工済みの予約。
	 */
	private static function decorate( $row ) {
		$row->id               = (int) $row->id;
		$row->slot_id          = (int) $row->slot_id;
		$row->people_count     = (int) $row->people_count;
		$row->height_cm        = (int) $row->height_cm;
		$row->weight_kg        = (int) $row->weight_kg;
		$row->age              = (int) $row->age;
		$row->guardian_consent = (int) $row->guardian_consent;
		$row->photo_optout     = (int) $row->photo_optout;
		$row->fee_amount       = (int) $row->fee_amount;
		$row->horse_id         = isset( $row->horse_id ) ? (int) $row->horse_id : 0;
		$row->horse_name       = isset( $row->horse_name ) ? (string) $row->horse_name : '';

		return $row;
	}

	/**
	 * 予約の「乗る馬」の表示名。
	 *
	 * いまの馬の名前を使う。馬の種類が削除されている場合は、予約時に残した名前を使う。
	 *
	 * @param object $reservation 予約。
	 * @return string 表示名。
	 */
	public static function horse_label( $reservation ) {
		$horse = $reservation->horse_id ? EquiTicket_Horses::get( $reservation->horse_id ) : null;
		if ( $horse ) {
			return $horse->name;
		}

		return '' !== (string) $reservation->horse_name ? $reservation->horse_name : '—';
	}

	/**
	 * 予約を作成する。
	 *
	 * 定員オーバーを防ぐため、トランザクション内で対象の時間枠行を
	 * SELECT ... FOR UPDATE でロックし、残数を再計算してから INSERT する
	 * （同時に申し込まれても定員を超えないための排他制御）。馬ごとの定員も同時に確認する。
	 *
	 * @param array<string,mixed> $args 予約内容。
	 *                                  slot_id / name / email / people_count / note /
	 *                                  height_cm / weight_kg / age /
	 *                                  horse_id（乗る馬の ID。Web 予約は体格の判定で選べるものだけ）/
	 *                                  consent / guardian_consent / photo_optout /
	 *                                  source ('web' or 'staff') / bypass_time_check。
	 * @return object|WP_Error 作成した予約、または検証エラー。
	 */
	public static function create( array $args ) {
		global $wpdb;

		$source     = ( isset( $args['source'] ) && 'staff' === $args['source'] ) ? 'staff' : 'web';
		$slot_id    = isset( $args['slot_id'] ) ? (int) $args['slot_id'] : 0;
		$name       = sanitize_text_field( isset( $args['name'] ) ? $args['name'] : '' );
		$email      = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		$note       = sanitize_text_field( isset( $args['note'] ) ? $args['note'] : '' );
		$max_people = (int) EquiTicket_Options::get( 'max_people_per_reservation' );

		// 当日枠（スタッフ登録）は家族連れなどをまとめて入れることがあるため、Web 予約の上限に縛らない。
		$people_limit = ( 'staff' === $source ) ? max( 1, $max_people ) : 1;
		$requested    = isset( $args['people_count'] ) ? $args['people_count'] : 1;

		// Web 予約は「1 申込み＝1 名」。身長・体重で乗る馬が変わるため、乗る人ごとに申し込んでもらう。
		// 当日枠（スタッフ登録）は、同じ馬でまとめて受けられるよう人数を指定できる。
		$people = ( 'web' === $source ) ? 1 : ( is_numeric( $requested ) ? (int) $requested : 0 );
		if ( $people < 1 || $people > $people_limit ) {
			$people = 0;
		}

		// 当日枠（スタッフ登録）は名前を省略できる。
		if ( '' === $name && 'staff' === $source ) {
			$name = __( '当日受付', 'equiticket' );
		}
		if ( '' === $name ) {
			return new WP_Error( 'equiticket_name_required', __( 'お名前を入力してください。', 'equiticket' ) );
		}
		if ( mb_strlen( $name ) > 50 ) {
			return new WP_Error( 'equiticket_name_too_long', __( 'お名前が長すぎます（50 文字以内）。', 'equiticket' ) );
		}
		if ( 0 === $people ) {
			return new WP_Error(
				'equiticket_people_invalid',
				sprintf(
					/* translators: %d: 1 予約あたりの上限人数 */
					__( '人数は 1〜%d 人で入力してください。', 'equiticket' ),
					$people_limit
				)
			);
		}

		// 身長・体重・年齢から乗る馬を決める。スタッフ登録のときは直接指定する。
		$profile     = null;
		$horse_id    = isset( $args['horse_id'] ) && is_numeric( $args['horse_id'] ) ? (int) $args['horse_id'] : 0;
		$has_profile = isset( $args['height_cm'], $args['weight_kg'], $args['age'] )
			&& '' !== $args['height_cm'] && '' !== $args['weight_kg'] && '' !== $args['age'];

		if ( 'staff' === $source ) {
			// 体格は記録のためだけに残す（判定はスタッフが目の前で行う）。
			if ( $has_profile ) {
				$screened = EquiTicket_Screening::assign( $args['height_cm'], $args['weight_kg'], $args['age'], EquiTicket_Horses::all() );
				if ( ! is_wp_error( $screened ) ) {
					$profile = $screened;
				}
			}
		} else {
			$screened = EquiTicket_Screening::assign(
				isset( $args['height_cm'] ) ? $args['height_cm'] : '',
				isset( $args['weight_kg'] ) ? $args['weight_kg'] : '',
				isset( $args['age'] ) ? $args['age'] : '',
				null,
				EquiTicket_Slots::offered_types()
			);
			if ( is_wp_error( $screened ) ) {
				return $screened;
			}

			// 2 種類以上から選べる体格の場合は、選ばれた馬でなければ通さない。
			$resolved = EquiTicket_Screening::resolve_type( $screened, $horse_id );
			if ( null === $resolved ) {
				return new WP_Error( 'equiticket_type_required', __( '乗る馬を選んでください。', 'equiticket' ) );
			}

			$profile  = $screened;
			$horse_id = $resolved;
		}

		$guardian_consent = ! empty( $args['guardian_consent'] ) ? 1 : 0;
		$photo_optout     = ! empty( $args['photo_optout'] ) ? 1 : 0;

		if ( 'web' === $source ) {
			if ( empty( $args['consent'] ) ) {
				return new WP_Error( 'equiticket_consent_required', __( '同意事項への同意にチェックしてください。', 'equiticket' ) );
			}
			if ( $profile && ! empty( $profile['requires_guardian'] ) && ! $guardian_consent ) {
				return new WP_Error(
					'equiticket_guardian_required',
					sprintf(
						/* translators: %d: 成人年齢 */
						__( '%d 歳未満の方は、保護者の同意の確認にチェックしてください。', 'equiticket' ),
						(int) EquiTicket_Options::get( 'minor_age' )
					)
				);
			}
			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error( 'equiticket_email_invalid', __( 'メールアドレスを正しく入力してください。', 'equiticket' ) );
			}
			$throttle = self::check_throttle();
			if ( is_wp_error( $throttle ) ) {
				return $throttle;
			}
		} elseif ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'equiticket_email_invalid', __( 'メールアドレスを正しく入力してください。', 'equiticket' ) );
		}

		$slot = EquiTicket_Slots::get( $slot_id );
		if ( ! $slot ) {
			return new WP_Error( 'equiticket_slot_missing', __( '時間枠を選び直してください。', 'equiticket' ) );
		}

		// スタッフ登録で馬を選んでいない場合、枠の馬が 1 種類ならそれにする。
		if ( 0 === $horse_id && 1 === count( $slot->horses ) ) {
			$horse_id = (int) key( $slot->horses );
		}
		$horse = EquiTicket_Horses::get( $horse_id );
		if ( ! $horse || ! isset( $slot->horses[ $horse_id ] ) ) {
			return new WP_Error( 'equiticket_type_unavailable', __( 'この時間枠では、選んだ馬の受付をしていません。', 'equiticket' ) );
		}

		// 当日の支払額は、乗る馬ごとに「馬の種類」画面で決めた金額。
		$fee = array(
			'amount' => $horse->fee_amount,
			'label'  => EquiTicket_Horses::fee_label( $horse ),
		);

		if ( empty( $args['bypass_time_check'] ) ) {
			$reservable = EquiTicket_Slots::check_reservable( $slot, $horse_id );
			if ( is_wp_error( $reservable ) ) {
				return $reservable;
			}
		} elseif ( 'cancelled' === $slot->status ) {
			return new WP_Error( 'equiticket_slot_cancelled', __( 'この時間枠は中止になりました。', 'equiticket' ) );
		}

		if ( '' !== $email && ! EquiTicket_Options::get( 'allow_duplicate_email' ) ) {
			$duplicate = self::find_active_by_email( $slot_id, $email );
			if ( $duplicate ) {
				return new WP_Error(
					'equiticket_duplicate',
					__( 'このメールアドレスでは、同じ時間枠の予約がすでに登録されています。整理券はメールからご確認ください。', 'equiticket' )
				);
			}
		}

		$table          = EquiTicket_DB::reservations_table();
		$slots_table    = EquiTicket_DB::slots_table();
		$now            = EquiTicket_Utils::now_for_db();
		$reservation_id = 0;
		$error          = null;
		$type_capacity  = 0;

		$wpdb->query( 'START TRANSACTION' );

		// 枠の行をロックし、残数を確定させる。
		$locked = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
				"SELECT id, capacity, status FROM {$slots_table} WHERE id = %d FOR UPDATE",
				$slot_id
			)
		);

		if ( ! $locked ) {
			$error = new WP_Error( 'equiticket_slot_missing', __( '時間枠を選び直してください。', 'equiticket' ) );
		} elseif ( 'cancelled' === $locked->status ) {
			$error = new WP_Error( 'equiticket_slot_cancelled', __( 'この時間枠は中止になりました。', 'equiticket' ) );
		} else {
			$type_capacity = self::horse_capacity( $slot_id, $horse_id );
			$booked        = self::booked_people( $slot_id );
			$booked_type   = self::booked_people( $slot_id, $horse_id );

			if ( $booked + $people > (int) $locked->capacity ) {
				$error = new WP_Error(
					'equiticket_slot_full',
					__( '申し訳ありません。ちょうど満席になりました。別の時間枠をお選びください。', 'equiticket' )
				);
			} elseif ( $booked_type + $people > $type_capacity ) {
				$error = new WP_Error(
					'equiticket_type_full',
					sprintf(
						/* translators: %s: 馬の種類 */
						__( '申し訳ありません。この時間枠の %s はちょうどいっぱいになりました。別の時間枠をお選びください。', 'equiticket' ),
						$horse->name
					)
				);
			} else {
				$inserted = false;
				for ( $attempt = 0; $attempt < 5 && ! $inserted; $attempt++ ) {
					$inserted = (bool) $wpdb->insert(
						$table,
						array(
							'slot_id'          => $slot_id,
							'name'             => $name,
							'email'            => $email,
							'people_count'     => $people,
							'horse_id'         => $horse_id,
							'horse_name'       => $horse->name,
							'fee_amount'       => $fee['amount'],
							'fee_label'        => $fee['label'],
							'height_cm'        => $profile ? (int) $profile['height_cm'] : 0,
							'weight_kg'        => $profile ? (int) $profile['weight_kg'] : 0,
							'age'              => $profile ? (int) $profile['age'] : 0,
							'guardian_consent' => $guardian_consent,
							'photo_optout'     => $photo_optout,
							'qr_token'         => self::generate_token(),
							'status'           => 'reserved',
							'source'           => $source,
							'note'             => $note,
							'created_at'       => $now,
							'updated_at'       => $now,
						),
						array( '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
					);
				}

				if ( $inserted ) {
					$reservation_id = (int) $wpdb->insert_id;
				} else {
					$error = new WP_Error( 'equiticket_insert_failed', __( '予約の保存に失敗しました。もう一度お試しください。', 'equiticket' ) );
				}
			}
		}

		if ( $error ) {
			$wpdb->query( 'ROLLBACK' );

			return $error;
		}

		$wpdb->query( 'COMMIT' );

		// MyISAM などトランザクションが効かない環境向けの保険。
		// コミット後にもう一度合計を確認し、超過していたら取り消す。
		if ( self::booked_people( $slot_id ) > (int) $locked->capacity
			|| self::booked_people( $slot_id, $horse_id ) > $type_capacity ) {
			$wpdb->delete( $table, array( 'id' => $reservation_id ), array( '%d' ) );

			return new WP_Error(
				'equiticket_slot_full',
				__( '申し訳ありません。ちょうど満席になりました。別の時間枠をお選びください。', 'equiticket' )
			);
		}

		$reservation = self::get( $reservation_id );

		/**
		 * 予約が確定した直後に発火する。
		 *
		 * @param object $reservation 予約。
		 * @param object $slot        時間枠。
		 */
		do_action( 'equiticket_reservation_created', $reservation, $slot );

		return $reservation;
	}

	/**
	 * 枠に入っている人数（予約済み＋来場済み）を数える。
	 *
	 * @param int      $slot_id  時間枠 ID。
	 * @param int|null $horse_id 馬の種類の ID。null なら全体。
	 * @return int 人数。
	 */
	private static function booked_people( $slot_id, $horse_id = null ) {
		global $wpdb;

		$table = EquiTicket_DB::reservations_table();

		if ( null === $horse_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
					"SELECT COALESCE( SUM( people_count ), 0 ) FROM {$table} WHERE slot_id = %d AND status IN ( 'reserved', 'checked_in' )",
					(int) $slot_id
				)
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
				"SELECT COALESCE( SUM( people_count ), 0 ) FROM {$table} WHERE slot_id = %d AND status IN ( 'reserved', 'checked_in' ) AND horse_id = %d",
				(int) $slot_id,
				(int) $horse_id
			)
		);
	}

	/**
	 * 時間枠での、その馬の定員。
	 *
	 * @param int $slot_id  時間枠 ID。
	 * @param int $horse_id 馬の種類の ID。
	 * @return int 定員（受付していなければ 0）。
	 */
	private static function horse_capacity( $slot_id, $horse_id ) {
		global $wpdb;

		$table = EquiTicket_DB::slot_horses_table();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
				"SELECT capacity FROM {$table} WHERE slot_id = %d AND horse_id = %d",
				(int) $slot_id,
				(int) $horse_id
			)
		);
	}

	/**
	 * 同じ時間枠・同じメールアドレスの有効な予約を探す。
	 *
	 * @param int    $slot_id 時間枠 ID。
	 * @param string $email   メールアドレス。
	 * @return object|null 予約。なければ null。
	 */
	public static function find_active_by_email( $slot_id, $email ) {
		global $wpdb;

		$table = EquiTicket_DB::reservations_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
				"SELECT * FROM {$table} WHERE slot_id = %d AND email = %s AND status IN ( 'reserved', 'checked_in' ) LIMIT 1",
				(int) $slot_id,
				$email
			)
		);

		return $row ? self::decorate( $row ) : null;
	}

	/**
	 * 指定した日の集金予定額を合計する。
	 *
	 * @param string             $date     日付（Y-m-d）。
	 * @param array<int,string>  $statuses 対象のステータス。
	 * @return int 合計金額。
	 */
	public static function fee_total( $date, array $statuses = array( 'reserved', 'checked_in' ) ) {
		global $wpdb;

		if ( ! $statuses ) {
			return 0;
		}

		$reservations = EquiTicket_DB::reservations_table();
		$slots        = EquiTicket_DB::slots_table();
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT COALESCE( SUM( r.fee_amount * r.people_count ), 0 )
			FROM {$reservations} AS r
			INNER JOIN {$slots} AS s ON s.id = r.slot_id
			WHERE s.event_date = %s AND r.status IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- プレースホルダーを組み立ててから prepare で値を埋める。
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $date ), array_values( $statuses ) ) ) );
	}

	/**
	 * 予約を来場済みにする。
	 *
	 * @param object $reservation 予約。
	 * @return bool 更新できたら true。
	 */
	public static function mark_checked_in( $reservation ) {
		global $wpdb;

		$now     = EquiTicket_Utils::now_for_db();
		$updated = $wpdb->update(
			EquiTicket_DB::reservations_table(),
			array(
				'status'        => 'checked_in',
				'checked_in_at' => $now,
				'updated_at'    => $now,
			),
			array( 'id' => (int) $reservation->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		/**
		 * チェックイン完了時に発火する。
		 *
		 * @param object $reservation 予約。
		 */
		do_action( 'equiticket_reservation_checked_in', self::get( $reservation->id ) );

		return true;
	}

	/**
	 * 予約を取り消す。
	 *
	 * @param object $reservation 予約。
	 * @param string $status      'cancelled'（自己キャンセル）または 'no_show'（無断キャンセル）。
	 * @return bool 更新できたら true。
	 */
	public static function cancel( $reservation, $status = 'cancelled' ) {
		global $wpdb;

		if ( ! in_array( $status, array( 'cancelled', 'no_show' ), true ) ) {
			$status = 'cancelled';
		}

		$now     = EquiTicket_Utils::now_for_db();
		$updated = $wpdb->update(
			EquiTicket_DB::reservations_table(),
			array(
				'status'       => $status,
				'cancelled_at' => $now,
				'updated_at'   => $now,
			),
			array( 'id' => (int) $reservation->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * 予約を「予約済み」に戻す（誤操作のリカバリー用）。
	 *
	 * @param object $reservation 予約。
	 * @return bool 更新できたら true。
	 */
	public static function restore( $reservation ) {
		global $wpdb;

		$updated = $wpdb->update(
			EquiTicket_DB::reservations_table(),
			array(
				'status'        => 'reserved',
				'checked_in_at' => null,
				'cancelled_at'  => null,
				'updated_at'    => EquiTicket_Utils::now_for_db(),
			),
			array( 'id' => (int) $reservation->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * メール送信日時を記録する。
	 *
	 * @param int    $reservation_id 予約 ID。
	 * @param string $sent_at        送信日時（失敗時は null を渡す）。
	 * @return void
	 */
	public static function set_mail_sent_at( $reservation_id, $sent_at ) {
		global $wpdb;

		$wpdb->update(
			EquiTicket_DB::reservations_table(),
			array(
				'mail_sent_at' => $sent_at,
				'updated_at'   => EquiTicket_Utils::now_for_db(),
			),
			array( 'id' => (int) $reservation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * 締切時刻を過ぎた未来場の予約を無断キャンセルにする。
	 *
	 * @param string|null $date 対象日（既定は今日）。
	 * @return int 無断キャンセルにした件数。
	 */
	public static function release_no_shows( $date = null ) {
		global $wpdb;

		$date    = $date ? $date : EquiTicket_Utils::today();
		$minutes = (int) EquiTicket_Options::get( 'no_show_minutes' );
		$now     = EquiTicket_Utils::now();

		$slots    = EquiTicket_Slots::query( array( 'date' => $date ) );
		$released = 0;

		foreach ( $slots as $slot ) {
			// 中止にした枠の予約は、来なくて当然なので無断キャンセルにしない。
			if ( ! $slot->starts_at || 'cancelled' === $slot->status ) {
				continue;
			}
			$deadline = $slot->starts_at->modify( sprintf( '+%d minutes', $minutes ) );
			if ( $now < $deadline ) {
				continue;
			}

			$updated = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
					'UPDATE ' . EquiTicket_DB::reservations_table() . " SET status = 'no_show', cancelled_at = %s, updated_at = %s WHERE slot_id = %d AND status = 'reserved'",
					EquiTicket_Utils::now_for_db(),
					EquiTicket_Utils::now_for_db(),
					$slot->id
				)
			);

			if ( $updated ) {
				$released += (int) $updated;
			}
		}

		return $released;
	}

	/**
	 * 予約をまとめて削除する。
	 *
	 * リハーサルのあとに本番データへ入れ替えるときなどに使う。
	 * 時間枠は残すので、同じ枠のまま受付をやり直せる。
	 *
	 * @param string $date 対象の日付（Y-m-d）。空ならすべて。
	 * @return int 削除した件数。
	 */
	public static function delete_all( $date = '' ) {
		global $wpdb;

		$reservations = EquiTicket_DB::reservations_table();
		$date         = EquiTicket_Utils::sanitize_date( $date );

		if ( '' === $date ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- テーブル名は内部の固定値。
			$deleted = $wpdb->query( "DELETE FROM {$reservations}" );

			return $deleted ? (int) $deleted : 0;
		}

		$slots   = EquiTicket_DB::slots_table();
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
				"DELETE r FROM {$reservations} AS r INNER JOIN {$slots} AS s ON s.id = r.slot_id WHERE s.event_date = %s",
				$date
			)
		);

		return $deleted ? (int) $deleted : 0;
	}

	/**
	 * メールアドレスごとの無断キャンセルの回数（すべての日の記録から数える）。
	 *
	 * @param array<int,string> $emails 調べるメールアドレス。
	 * @return array<string,int> 小文字のメールアドレス => 回数（0 回の人は含まない）。
	 */
	public static function no_show_counts( array $emails ) {
		global $wpdb;

		$emails = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'strval', $emails ) ) ) ) );
		if ( ! $emails ) {
			return array();
		}

		$table        = EquiTicket_DB::reservations_table();
		$slots        = EquiTicket_DB::slots_table();
		$placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );

		// 中止にした枠の予約は数えない（本人のせいではないため）。
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- テーブル名は内部の固定値、値は prepare で埋める。
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER( r.email ) AS email, COUNT(*) AS times FROM {$table} AS r
					INNER JOIN {$slots} AS s ON s.id = r.slot_id
					WHERE r.status = 'no_show' AND s.status <> 'cancelled' AND LOWER( r.email ) IN ({$placeholders})
					GROUP BY LOWER( r.email )",
				$emails
			)
		);
		// phpcs:enable

		$counts = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$counts[ (string) $row->email ] = (int) $row->times;
		}

		return $counts;
	}

	/**
	 * 予約を 1 件削除する（記録も残さない）。来場済みの予約も消せる。
	 *
	 * @param object $reservation 予約。
	 * @return bool 削除できたら true。
	 */
	public static function delete( $reservation ) {
		global $wpdb;

		$deleted = $wpdb->delete( EquiTicket_DB::reservations_table(), array( 'id' => (int) $reservation->id ), array( '%d' ) );

		return (bool) $deleted;
	}

	/**
	 * 推測できないランダムなトークンを作る（連番の ID を使うと他人の整理券を推測できてしまうため）。
	 *
	 * @return string 40 文字のトークン。
	 */
	public static function generate_token() {
		try {
			return bin2hex( random_bytes( 20 ) );
		} catch ( Exception $e ) {
			return wp_generate_password( 40, false, false );
		}
	}

	/**
	 * 短時間に大量送信されていないか確認する（簡易スロットリング）。
	 *
	 * @return true|WP_Error 問題なければ true。
	 */
	private static function check_throttle() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return true;
		}

		// 会場の Wi-Fi や携帯回線では多くの来場者が同じ IP アドレスを共有するため、
		// 通常の混雑では引っかからない程度（10 分間に 30 件）に留める。
		$key   = 'equiticket_throttle_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return new WP_Error(
				'equiticket_throttled',
				__( '短時間に多くの予約操作が行われました。数分おいてからもう一度お試しください。', 'equiticket' )
			);
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}
}
