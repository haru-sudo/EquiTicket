<?php
/**
 * Plugin Name:       EquiTicket – 体験乗馬 整理券システム
 * Plugin URI:        https://github.com/haru-sudo/EquiTicket2
 * Description:       体験乗馬（文化祭・地域のお祭り・乗馬クラブの体験会など）向けの整理券システム。来場者はスマートフォンで時間枠を予約して QR 付きの整理券を受け取り、当日は受付でスマートフォンのカメラを使って QR を読み取るだけで受付できます。
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            HAL-JP
 * Text Domain:       equiticket
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (C) 2026 HAL-JP
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * このプラグインは GNU GPL v2 またはそれ以降のバージョン（GPL-2.0-or-later）で公開しています。
 * 全文は同梱の LICENSE を参照してください。
 * 同梱の jsQR（assets/js/vendor/）は Apache License 2.0 です。
 *
 * @package EquiTicket
 */

defined( 'ABSPATH' ) || exit;

define( 'EQUITICKET_VERSION', '1.0.0' );
define( 'EQUITICKET_DB_VERSION', '7' );
define( 'EQUITICKET_FILE', __FILE__ );
define( 'EQUITICKET_PATH', plugin_dir_path( __FILE__ ) );
define( 'EQUITICKET_URL', plugin_dir_url( __FILE__ ) );

require_once EQUITICKET_PATH . 'includes/class-equiticket-db.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-utils.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-options.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-install.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-horses.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-screening.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-slots.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-reservations.php';
require_once EQUITICKET_PATH . 'includes/qr/class-equiticket-qr-encoder.php';
require_once EQUITICKET_PATH . 'includes/qr/class-equiticket-png-writer.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-qr.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-mailer.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-checkin.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-frontend.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-cron.php';
require_once EQUITICKET_PATH . 'includes/class-equiticket-access.php';

if ( is_admin() ) {
	require_once EQUITICKET_PATH . 'includes/admin/class-equiticket-admin.php';
	require_once EQUITICKET_PATH . 'includes/admin/class-equiticket-admin-horses.php';
	require_once EQUITICKET_PATH . 'includes/admin/class-equiticket-admin-slots.php';
	require_once EQUITICKET_PATH . 'includes/admin/class-equiticket-admin-dashboard.php';
	require_once EQUITICKET_PATH . 'includes/admin/class-equiticket-admin-walkin.php';
	require_once EQUITICKET_PATH . 'includes/admin/class-equiticket-admin-mail.php';
	require_once EQUITICKET_PATH . 'includes/admin/class-equiticket-admin-roster.php';
	require_once EQUITICKET_PATH . 'includes/admin/class-equiticket-admin-settings.php';
}

register_activation_hook( __FILE__, array( 'EquiTicket_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EquiTicket_Install', 'deactivate' ) );

/**
 * プラグインの各機能を WordPress に登録する。
 *
 * @return void
 */
function equiticket_bootstrap() {
	EquiTicket_Install::maybe_upgrade();
	EquiTicket_Cron::init();
	EquiTicket_Frontend::init();
	EquiTicket_Checkin::init();
	EquiTicket_Access::init();

	if ( is_admin() ) {
		EquiTicket_Admin::init();
	}
}
add_action( 'plugins_loaded', 'equiticket_bootstrap' );
