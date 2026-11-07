<?php
/**
 * テーブル作成・権限追加などのセットアップ処理。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

/**
 * 有効化・無効化・アップグレード時の処理をまとめたクラス。
 */
final class EquiTicket_Install {

	/**
	 * DB バージョンを保存するオプション名。
	 */
	const DB_VERSION_OPTION = 'equiticket_db_version';

	/**
	 * 受付スタッフ用ロール名。
	 */
	const STAFF_ROLE = 'equiticket_staff';

	/**
	 * 運営スタッフ用ロール名（時間枠や設定も変更できる）。
	 */
	const MANAGER_ROLE = 'equiticket_manager';

	/**
	 * プラグイン有効化時の処理。
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::add_roles();
		self::seed_horses();
		add_option( EquiTicket_Options::OPTION, EquiTicket_Options::defaults() );
		update_option( self::DB_VERSION_OPTION, EQUITICKET_DB_VERSION );
		EquiTicket_Cron::schedule();
	}

	/**
	 * プラグイン無効化時の処理（データは残す）。
	 *
	 * @return void
	 */
	public static function deactivate() {
		EquiTicket_Cron::unschedule();
	}

	/**
	 * DB バージョンが古い場合にテーブル定義を更新する。
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::DB_VERSION_OPTION );
		if ( $installed === EQUITICKET_DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::add_roles();

		if ( $installed && version_compare( (string) $installed, '2', '<' ) ) {
			self::migrate_to_horse_types();
		}
		if ( $installed && version_compare( (string) $installed, '3', '<' ) ) {
			self::migrate_fee_options();
		}
		if ( $installed && version_compare( (string) $installed, '6', '<' ) ) {
			self::migrate_to_registered_horses();
		}
		if ( $installed && version_compare( (string) $installed, '7', '<' ) ) {
			self::restore_cancelled_slot_no_shows();
		}
		self::seed_horses();

		update_option( self::DB_VERSION_OPTION, EQUITICKET_DB_VERSION );
	}

	/**
	 * 馬種別の定員（バージョン 2）へ移行する。
	 *
	 * それまでの「定員」はすべて普通の馬の枠だったものとして引き継ぐ。
	 *
	 * @return void
	 */
	private static function migrate_to_horse_types() {
		global $wpdb;

		$slots = EquiTicket_DB::slots_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- テーブル名は内部の固定値。
		$wpdb->query( "UPDATE {$slots} SET horse_capacity = capacity WHERE horse_capacity = 0 AND capacity > 0" );
	}

	/**
	 * 馬の種類が 1 件もなければ、初期の馬（ポニーと馬）を登録する。
	 *
	 * @return void
	 */
	public static function seed_horses() {
		if ( EquiTicket_Horses::all() ) {
			return;
		}

		foreach ( EquiTicket_Horses::defaults() as $horse ) {
			EquiTicket_Horses::save( $horse );
		}
	}

	/**
	 * 固定の「ポニー／馬（／ミニチュアホース）」から、登録式の馬の種類（バージョン 6）へ移行する。
	 *
	 * それまでの設定の条件・時間枠の頭数と定員・予約の馬種を、そのまま新しい形に移す。
	 *
	 * @return void
	 */
	private static function migrate_to_registered_horses() {
		global $wpdb;

		$slots        = EquiTicket_DB::slots_table();
		$reservations = EquiTicket_DB::reservations_table();
		$slot_horses  = EquiTicket_DB::slot_horses_table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
		$slot_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$slots}" );
		$resv_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$reservations}" );
		if ( ! in_array( 'pony_capacity', (array) $slot_columns, true ) || EquiTicket_Horses::all() ) {
			return;
		}

		$stored = get_option( EquiTicket_Options::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$opt    = static function ( $key, $fallback ) use ( $stored ) {
			return isset( $stored[ $key ] ) ? (int) $stored[ $key ] : $fallback;
		};

		$ids = array(
			'pony'  => EquiTicket_Horses::save(
				array(
					'name'       => __( 'ポニー', 'equiticket' ),
					'max_age'    => $opt( 'pony_max_age', 12 ),
					'max_height' => $opt( 'pony_max_height', 140 ),
					'max_weight' => $opt( 'pony_max_weight', 40 ),
					'sort_order' => 10,
					'active'     => 1,
				)
			),
			'horse' => EquiTicket_Horses::save(
				array(
					'name'       => __( '馬', 'equiticket' ),
					'min_age'    => $opt( 'horse_min_age', 6 ),
					'min_height' => $opt( 'horse_min_height', 120 ),
					'max_weight' => $opt( 'horse_max_weight', 80 ),
					'sort_order' => 20,
					'active'     => 1,
				)
			),
		);

		$has_kind = in_array( 'horse_kind', (array) $slot_columns, true );
		if ( $has_kind && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$slots} WHERE horse_kind = 'chagu'" ) > 0 ) {
			$ids['chagu'] = EquiTicket_Horses::save(
				array(
					'name'       => __( 'ミニチュアホース', 'equiticket' ),
					'min_age'    => $opt( 'chagu_min_age', 5 ),
					'min_height' => $opt( 'chagu_min_height', 100 ),
					'max_weight' => $opt( 'chagu_max_weight', 80 ),
					'sort_order' => 30,
					'active'     => 1,
				)
			);
		}

		$kind_column = $has_kind ? 'horse_kind' : "'horse'";
		$rows        = $wpdb->get_results( "SELECT id, horse_count, pony_count, horse_capacity, pony_capacity, {$kind_column} AS horse_kind FROM {$slots}" );
		foreach ( $rows ? $rows : array() as $row ) {
			$big = ( 'chagu' === $row->horse_kind && isset( $ids['chagu'] ) ) ? $ids['chagu'] : $ids['horse'];
			foreach ( array( array( $ids['pony'], $row->pony_count, $row->pony_capacity ), array( $big, $row->horse_count, $row->horse_capacity ) ) as $entry ) {
				if ( ( (int) $entry[1] > 0 || (int) $entry[2] > 0 ) && ! is_wp_error( $entry[0] ) ) {
					$wpdb->insert(
						$slot_horses,
						array(
							'slot_id'     => (int) $row->id,
							'horse_id'    => (int) $entry[0],
							'horse_count' => (int) $entry[1],
							'capacity'    => (int) $entry[2],
						),
						array( '%d', '%d', '%d', '%d' )
					);
				}
			}
		}

		if ( in_array( 'horse_type', (array) $resv_columns, true ) ) {
			foreach ( $ids as $type => $horse_id ) {
				$horse = is_wp_error( $horse_id ) ? null : EquiTicket_Horses::get( $horse_id );
				if ( ! $horse ) {
					continue;
				}
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$reservations} SET horse_id = %d, horse_name = %s WHERE horse_type = %s AND horse_id = 0",
						$horse->id,
						$horse->name,
						$type
					)
				);
			}
		}
		// phpcs:enable
	}

	/**
	 * 中止にした枠なのに無断キャンセルにされていた予約を、予約済みに戻す（バージョン 7）。
	 *
	 * それまでは中止の枠も自動の無断キャンセル処理の対象になっていたため。
	 *
	 * @return void
	 */
	private static function restore_cancelled_slot_no_shows() {
		global $wpdb;

		$reservations = EquiTicket_DB::reservations_table();
		$slots        = EquiTicket_DB::slots_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名は内部の固定値。
		$wpdb->query( "UPDATE {$reservations} SET status = 'reserved', cancelled_at = NULL WHERE status = 'no_show' AND slot_id IN ( SELECT id FROM {$slots} WHERE status = 'cancelled' )" );
	}

	/**
	 * テーブルを作成（または定義差分を適用）する。
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$slots           = EquiTicket_DB::slots_table();
		$reservations    = EquiTicket_DB::reservations_table();
		$horses          = EquiTicket_DB::horses_table();
		$slot_horses     = EquiTicket_DB::slot_horses_table();

		$sql_slots = "CREATE TABLE {$slots} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	event_date date NOT NULL,
	start_time time NOT NULL,
	end_time time NOT NULL,
	capacity smallint(5) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT 'open',
	note varchar(255) NOT NULL DEFAULT '',
	created_at datetime NULL DEFAULT NULL,
	updated_at datetime NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	KEY event_date (event_date,start_time)
) ENGINE=InnoDB {$charset_collate};";

		$sql_reservations = "CREATE TABLE {$reservations} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	slot_id bigint(20) unsigned NOT NULL,
	name varchar(100) NOT NULL DEFAULT '',
	email varchar(190) NOT NULL DEFAULT '',
	people_count smallint(5) unsigned NOT NULL DEFAULT 1,
	horse_id bigint(20) unsigned NOT NULL DEFAULT 0,
	horse_name varchar(60) NOT NULL DEFAULT '',
	height_cm smallint(5) unsigned NOT NULL DEFAULT 0,
	weight_kg smallint(5) unsigned NOT NULL DEFAULT 0,
	age smallint(5) unsigned NOT NULL DEFAULT 0,
	fee_amount smallint(5) unsigned NOT NULL DEFAULT 0,
	fee_label varchar(60) NOT NULL DEFAULT '',
	guardian_consent tinyint(1) unsigned NOT NULL DEFAULT 0,
	photo_optout tinyint(1) unsigned NOT NULL DEFAULT 0,
	qr_token varchar(64) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'reserved',
	source varchar(20) NOT NULL DEFAULT 'web',
	note varchar(255) NOT NULL DEFAULT '',
	created_at datetime NULL DEFAULT NULL,
	updated_at datetime NULL DEFAULT NULL,
	checked_in_at datetime NULL DEFAULT NULL,
	cancelled_at datetime NULL DEFAULT NULL,
	mail_sent_at datetime NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY qr_token (qr_token),
	KEY slot_status (slot_id,status),
	KEY email (email)
) ENGINE=InnoDB {$charset_collate};";

		$sql_horses = "CREATE TABLE {$horses} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	name varchar(60) NOT NULL DEFAULT '',
	description varchar(255) NOT NULL DEFAULT '',
	min_age smallint(5) unsigned NOT NULL DEFAULT 0,
	max_age smallint(5) unsigned NOT NULL DEFAULT 0,
	min_height smallint(5) unsigned NOT NULL DEFAULT 0,
	max_height smallint(5) unsigned NOT NULL DEFAULT 0,
	min_weight smallint(5) unsigned NOT NULL DEFAULT 0,
	max_weight smallint(5) unsigned NOT NULL DEFAULT 0,
	fee_amount smallint(5) unsigned NOT NULL DEFAULT 0,
	sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
	active tinyint(1) unsigned NOT NULL DEFAULT 1,
	created_at datetime NULL DEFAULT NULL,
	updated_at datetime NULL DEFAULT NULL,
	PRIMARY KEY  (id)
) ENGINE=InnoDB {$charset_collate};";

		$sql_slot_horses = "CREATE TABLE {$slot_horses} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	slot_id bigint(20) unsigned NOT NULL,
	horse_id bigint(20) unsigned NOT NULL,
	horse_count smallint(5) unsigned NOT NULL DEFAULT 0,
	capacity smallint(5) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY slot_horse (slot_id,horse_id),
	KEY horse_id (horse_id)
) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql_slots );
		dbDelta( $sql_reservations );
		dbDelta( $sql_horses );
		dbDelta( $sql_slot_horses );
	}

	/**
	 * ケイパビリティと受付スタッフロールを用意する。
	 *
	 * @return void
	 */
	public static function add_roles() {
		add_role(
			self::STAFF_ROLE,
			__( '体験乗馬 受付スタッフ', 'equiticket' ),
			array(
				'read'                        => true,
				EquiTicket_Utils::CAP_CHECKIN => true,
			)
		);

		add_role(
			self::MANAGER_ROLE,
			__( '体験乗馬 運営スタッフ', 'equiticket' ),
			array(
				'read'                        => true,
				EquiTicket_Utils::CAP_CHECKIN => true,
				EquiTicket_Utils::CAP_MANAGE  => true,
			)
		);

		$staff = get_role( self::STAFF_ROLE );
		if ( $staff ) {
			$staff->add_cap( EquiTicket_Utils::CAP_CHECKIN );
		}

		$manager = get_role( self::MANAGER_ROLE );
		if ( $manager ) {
			$manager->add_cap( EquiTicket_Utils::CAP_CHECKIN );
			$manager->add_cap( EquiTicket_Utils::CAP_MANAGE );
		}

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( EquiTicket_Utils::CAP_MANAGE );
			$administrator->add_cap( EquiTicket_Utils::CAP_CHECKIN );
		}

		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( EquiTicket_Utils::CAP_CHECKIN );
		}
	}

	/**
	 * 支払額の選択肢（バージョン 3）へ移行する。
	 *
	 * それまで 1 つのテキストで持っていた参加費を、選択肢の 1 行目として引き継ぐ。
	 *
	 * @return void
	 */
	private static function migrate_fee_options() {
		$settings = get_option( EquiTicket_Options::OPTION, array() );
		if ( ! is_array( $settings ) ) {
			return;
		}
		if ( isset( $settings['fee_options'] ) && '' !== trim( (string) $settings['fee_options'] ) ) {
			return;
		}
		if ( empty( $settings['fee_text'] ) ) {
			return;
		}

		$settings['fee_options'] = (string) $settings['fee_text'];
		update_option( EquiTicket_Options::OPTION, $settings );
	}

	/**
	 * テーブルとオプションを完全に削除する（uninstall.php から呼ばれる）。
	 *
	 * @return void
	 */
	public static function drop_data() {
		global $wpdb;

		$slots        = EquiTicket_DB::slots_table();
		$reservations = EquiTicket_DB::reservations_table();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- テーブル名は内部で組み立てた固定値。
		$wpdb->query( "DROP TABLE IF EXISTS {$reservations}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$slots}" );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . EquiTicket_DB::slot_horses_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . EquiTicket_DB::horses_table() );
		// phpcs:enable

		delete_option( EquiTicket_Options::OPTION );
		delete_option( self::DB_VERSION_OPTION );
		delete_option( 'equiticket_last_sweep' );
		remove_role( self::STAFF_ROLE );
		remove_role( self::MANAGER_ROLE );

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->remove_cap( EquiTicket_Utils::CAP_MANAGE );
			$administrator->remove_cap( EquiTicket_Utils::CAP_CHECKIN );
		}

		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->remove_cap( EquiTicket_Utils::CAP_CHECKIN );
		}
	}
}
