<?php
/**
 * 馬の種類（管理画面で自由に登録する）。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 馬の種類の登録・取得と、体格の条件の判定。
 *
 * 「ポニー」「馬」「ミニチュアホース」のような種類を、名前・説明・年齢・身長・体重の
 * 条件・当日の支払額つきで管理画面から登録する。時間枠ごとに、どの馬を何頭・何人分
 * 出すかを決める（EquiTicket_Slots）。条件の数値は 0 なら「制限なし」。
 */
final class EquiTicket_Horses {

	/**
	 * 条件として持つ項目（下限・上限の組）。
	 */
	const LIMIT_FIELDS = array( 'min_age', 'max_age', 'min_height', 'max_height', 'min_weight', 'max_weight' );

	/**
	 * 1 リクエスト内のキャッシュ。
	 *
	 * @var array<int,object>|null
	 */
	private static $cache = null;

	/**
	 * 登録されている馬の種類をすべて返す（表示順）。
	 *
	 * @param bool $active_only 受付中のものだけにするなら true。
	 * @return array<int,object> 馬の種類（キーは ID）。
	 */
	public static function all( $active_only = false ) {
		global $wpdb;

		if ( null === self::$cache ) {
			$table = EquiTicket_DB::horses_table();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC" );

			self::$cache = array();
			foreach ( $rows ? $rows : array() as $row ) {
				$horse                    = self::decorate( $row );
				self::$cache[ $horse->id ] = $horse;
			}
		}

		if ( ! $active_only ) {
			return self::$cache;
		}

		return array_filter(
			self::$cache,
			static function ( $horse ) {
				return $horse->active;
			}
		);
	}

	/**
	 * 馬の種類を 1 件返す。
	 *
	 * @param int $horse_id ID。
	 * @return object|null 馬の種類。
	 */
	public static function get( $horse_id ) {
		$all = self::all();

		return isset( $all[ (int) $horse_id ] ) ? $all[ (int) $horse_id ] : null;
	}

	/**
	 * キャッシュを捨てる（保存・削除の後に呼ぶ）。
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * 行に型変換を施す。
	 *
	 * @param object $row DB の行。
	 * @return object 馬の種類。
	 */
	public static function decorate( $row ) {
		$row->id          = (int) $row->id;
		$row->name        = (string) $row->name;
		$row->description = isset( $row->description ) ? (string) $row->description : '';
		foreach ( self::LIMIT_FIELDS as $field ) {
			$row->$field = isset( $row->$field ) ? (int) $row->$field : 0;
		}
		$row->fee_amount = isset( $row->fee_amount ) ? (int) $row->fee_amount : 0;
		$row->sort_order = isset( $row->sort_order ) ? (int) $row->sort_order : 0;
		$row->active     = ! isset( $row->active ) || (int) $row->active;

		return $row;
	}

	/**
	 * 体格がその馬の条件に合うか。
	 *
	 * @param object $horse  馬の種類。
	 * @param int    $height 身長（cm）。
	 * @param int    $weight 体重（kg）。
	 * @param int    $age    年齢。
	 * @return bool 乗れるなら true。
	 */
	public static function accepts( $horse, $height, $weight, $age ) {
		$checks = array(
			array( $age, $horse->min_age, $horse->max_age ),
			array( $height, $horse->min_height, $horse->max_height ),
			array( $weight, $horse->min_weight, $horse->max_weight ),
		);

		foreach ( $checks as $check ) {
			list( $value, $min, $max ) = $check;
			if ( $min > 0 && $value < $min ) {
				return false;
			}
			if ( $max > 0 && $value > $max ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 条件を 1 行の文にする（例: 「12歳以下・140cm以下・40kg以下」）。
	 *
	 * @param object $horse 馬の種類。
	 * @return string 条件。制限がなければ「制限なし」。
	 */
	public static function conditions_label( $horse ) {
		$parts = array_filter(
			array(
				self::range_label( $horse->min_age, $horse->max_age, __( '歳', 'equiticket' ) ),
				self::range_label( $horse->min_height, $horse->max_height, 'cm' ),
				self::range_label( $horse->min_weight, $horse->max_weight, 'kg' ),
			)
		);

		return $parts ? implode( '・', $parts ) : __( '制限なし', 'equiticket' );
	}

	/**
	 * 下限・上限を「5〜12歳」「120cm以上」のような文にする。
	 *
	 * @param int    $min  下限（0 なら制限なし）。
	 * @param int    $max  上限（0 なら制限なし）。
	 * @param string $unit 単位。
	 * @return string 文。どちらも制限なしなら空文字。
	 */
	private static function range_label( $min, $max, $unit ) {
		if ( $min > 0 && $max > 0 ) {
			return sprintf( '%d〜%d%s', $min, $max, $unit );
		}
		if ( $min > 0 ) {
			/* translators: 1: 下限, 2: 単位 */
			return sprintf( __( '%1$d%2$s以上', 'equiticket' ), $min, $unit );
		}
		if ( $max > 0 ) {
			/* translators: 1: 上限, 2: 単位 */
			return sprintf( __( '%1$d%2$s以下', 'equiticket' ), $max, $unit );
		}

		return '';
	}

	/**
	 * 当日の支払額の表示（例: 「500 円」）。金額が 0 なら空文字。
	 *
	 * @param object $horse 馬の種類。
	 * @return string 表示。
	 */
	public static function fee_label( $horse ) {
		if ( ! $horse || $horse->fee_amount <= 0 ) {
			return '';
		}

		/* translators: %s: 金額 */
		return sprintf( __( '%s 円', 'equiticket' ), number_format_i18n( $horse->fee_amount ) );
	}

	/**
	 * 登録されている馬の支払額を 1 行にまとめる（例: 「ポニー 300 円／馬 500 円」）。
	 *
	 * @param array<int,object>|null $horses 馬の種類（省略時は受付中のもの）。
	 * @return string まとめ。どの馬にも金額がなければ空文字。
	 */
	public static function fee_summary( $horses = null ) {
		$horses = null === $horses ? self::all( true ) : $horses;
		$fees   = array();
		foreach ( $horses as $horse ) {
			if ( $horse->fee_amount > 0 ) {
				$fees[ $horse->name ] = $horse->fee_amount;
			}
		}

		if ( ! $fees ) {
			return '';
		}

		// どの馬も同じ金額なら、金額だけを出す。
		if ( 1 === count( array_unique( $fees ) ) && count( $fees ) === count( $horses ) ) {
			/* translators: %s: 金額 */
			return sprintf( __( '%s 円', 'equiticket' ), number_format_i18n( reset( $fees ) ) );
		}

		$parts = array();
		foreach ( $fees as $name => $amount ) {
			/* translators: 1: 馬の種類, 2: 金額 */
			$parts[] = sprintf( __( '%1$s %2$s 円', 'equiticket' ), $name, number_format_i18n( $amount ) );
		}

		return implode( '／', $parts );
	}

	/**
	 * 入力値を検証して、保存用の配列にする。
	 *
	 * @param array<string,mixed> $data 入力値。
	 * @return array<string,mixed>|WP_Error 保存用の配列、またはエラー。
	 */
	public static function sanitize( array $data ) {
		$name = sanitize_text_field( isset( $data['name'] ) ? (string) $data['name'] : '' );
		if ( '' === $name ) {
			return new WP_Error( 'equiticket_horse_name', __( '馬の名前（種類）を入力してください。', 'equiticket' ) );
		}
		if ( mb_strlen( $name ) > 50 ) {
			return new WP_Error( 'equiticket_horse_name_long', __( '馬の名前は 50 文字以内にしてください。', 'equiticket' ) );
		}

		$clean = array(
			'name'        => $name,
			'description' => mb_substr( sanitize_text_field( isset( $data['description'] ) ? (string) $data['description'] : '' ), 0, 255 ),
			'fee_amount'  => EquiTicket_Utils::clamp_int( isset( $data['fee_amount'] ) && '' !== $data['fee_amount'] ? $data['fee_amount'] : 0, 0, 65535, 0 ),
			'sort_order'  => EquiTicket_Utils::clamp_int( isset( $data['sort_order'] ) && '' !== $data['sort_order'] ? $data['sort_order'] : 0, 0, 999, 0 ),
			'active'      => empty( $data['active'] ) ? 0 : 1,
		);

		foreach ( self::LIMIT_FIELDS as $field ) {
			$max             = ( false !== strpos( $field, 'age' ) ) ? 120 : 250;
			$value           = isset( $data[ $field ] ) && '' !== $data[ $field ] ? $data[ $field ] : 0;
			$clean[ $field ] = EquiTicket_Utils::clamp_int( $value, 0, $max, 0 );
		}

		foreach ( array( 'age', 'height', 'weight' ) as $field ) {
			if ( $clean[ 'min_' . $field ] > 0 && $clean[ 'max_' . $field ] > 0 && $clean[ 'min_' . $field ] > $clean[ 'max_' . $field ] ) {
				return new WP_Error( 'equiticket_horse_range', __( '下限が上限より大きくなっている条件があります。', 'equiticket' ) );
			}
		}

		return $clean;
	}

	/**
	 * 馬の種類を保存する（新規・更新兼用）。
	 *
	 * @param array<string,mixed> $data     入力値。
	 * @param int                 $horse_id 更新する ID（0 なら新規）。
	 * @return int|WP_Error 保存した ID、またはエラー。
	 */
	public static function save( array $data, $horse_id = 0 ) {
		global $wpdb;

		$clean = self::sanitize( $data );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$table   = EquiTicket_DB::horses_table();
		$now     = EquiTicket_Utils::now_for_db();
		$formats = array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s' );

		// 列の順番を formats に揃える。
		$row = array(
			'name'        => $clean['name'],
			'description' => $clean['description'],
			'min_age'     => $clean['min_age'],
			'max_age'     => $clean['max_age'],
			'min_height'  => $clean['min_height'],
			'max_height'  => $clean['max_height'],
			'min_weight'  => $clean['min_weight'],
			'max_weight'  => $clean['max_weight'],
			'fee_amount'  => $clean['fee_amount'],
			'sort_order'  => $clean['sort_order'],
			'active'      => $clean['active'],
			'updated_at'  => $now,
		);

		self::flush();

		if ( $horse_id > 0 ) {
			$updated = $wpdb->update( $table, $row, array( 'id' => (int) $horse_id ), $formats, array( '%d' ) );
			if ( false === $updated ) {
				return new WP_Error( 'equiticket_horse_update_failed', __( '馬の種類の更新に失敗しました。', 'equiticket' ) );
			}

			return (int) $horse_id;
		}

		$row['created_at'] = $now;
		$formats[]         = '%s';
		if ( ! $wpdb->insert( $table, $row, $formats ) ) {
			return new WP_Error( 'equiticket_horse_insert_failed', __( '馬の種類の保存に失敗しました。', 'equiticket' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * 馬の種類を削除する。時間枠や予約で使われている場合は削除しない。
	 *
	 * @param int $horse_id ID。
	 * @return true|WP_Error 成功なら true。
	 */
	public static function delete( $horse_id ) {
		global $wpdb;

		$horse = self::get( $horse_id );
		if ( ! $horse ) {
			return new WP_Error( 'equiticket_horse_missing', __( '対象の馬の種類が見つかりません。', 'equiticket' ) );
		}

		$slot_horses  = EquiTicket_DB::slot_horses_table();
		$reservations = EquiTicket_DB::reservations_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
		$in_slots = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$slot_horses} WHERE horse_id = %d", (int) $horse_id ) );
		$in_resv  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reservations} WHERE horse_id = %d", (int) $horse_id ) );
		// phpcs:enable

		if ( $in_slots > 0 || $in_resv > 0 ) {
			return new WP_Error(
				'equiticket_horse_in_use',
				__( 'この馬は時間枠か予約で使われているため削除できません。受付をやめる場合は「受付する」のチェックを外してください。', 'equiticket' )
			);
		}

		$wpdb->delete( EquiTicket_DB::horses_table(), array( 'id' => (int) $horse_id ), array( '%d' ) );
		self::flush();

		return true;
	}

	/**
	 * 新規インストール時の初期の馬（ポニーと馬）。あとから自由に変更・追加できる。
	 *
	 * @return array<int,array<string,mixed>> 馬の種類の入力値。
	 */
	public static function defaults() {
		return array(
			array(
				'name'        => __( 'ポニー', 'equiticket' ),
				'description' => __( '小さめの馬です。はじめての方やお子さんでも乗りやすい高さです。', 'equiticket' ),
				'max_age'     => 12,
				'max_height'  => 140,
				'max_weight'  => 40,
				'fee_amount'  => 300,
				'sort_order'  => 10,
				'active'      => 1,
			),
			array(
				'name'        => __( '馬', 'equiticket' ),
				'description' => __( '大きい馬です。高さがあり、景色がよく見えます。', 'equiticket' ),
				'min_age'     => 6,
				'min_height'  => 120,
				'max_weight'  => 80,
				'fee_amount'  => 500,
				'sort_order'  => 20,
				'active'      => 1,
			),
		);
	}
}
