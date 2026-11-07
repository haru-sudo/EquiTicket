<?php
/**
 * プラグイン削除時の処理。
 *
 * 設定で「削除時にデータも消す」が有効な場合のみテーブルを削除する。
 * 既定では予約データを残し、誤操作でイベント記録を失わないようにしている。
 *
 * @package EquiTicket
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-equiticket-db.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-equiticket-utils.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-equiticket-options.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-equiticket-install.php';

$equiticket_settings = get_option( EquiTicket_Options::OPTION, array() );
if ( is_array( $equiticket_settings ) && ! empty( $equiticket_settings['delete_data_on_uninstall'] ) ) {
	EquiTicket_Install::drop_data();
}
