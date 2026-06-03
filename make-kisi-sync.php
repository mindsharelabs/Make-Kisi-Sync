<?php
/**
 * Plugin Name: Make Kisi Sync
 * Plugin URI:  https://makesantafe.org
 * Description: Syncs WooCommerce Memberships with Kisi access control. Grants door access when a membership becomes active and revokes it when cancelled or expired.
 * Version:     1.0.0
 * Author:      Make Santa Fe
 * Author URI:  https://makesantafe.org
 * License:     GPL-2.0+
 * Text Domain: make-kisi-sync
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAKE_KISI_SYNC_VERSION', '1.0.0' );
define( 'MAKE_KISI_SYNC_DIR', plugin_dir_path( __FILE__ ) );

require_once MAKE_KISI_SYNC_DIR . 'includes/class-kisi-api.php';
require_once MAKE_KISI_SYNC_DIR . 'includes/class-kisi-sync.php';
require_once MAKE_KISI_SYNC_DIR . 'includes/class-admin.php';

/**
 * Boot at priority 20 so WooCommerce Memberships (priority 10) has already
 * run its plugins_loaded callback and defined the WC_Memberships class.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Memberships' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Kisi Access Sync</strong> requires WooCommerce Memberships to be active.</p></div>';
		} );
		return;
	}

	$api_key = get_option( 'kisi_sync_api_key', '' );

	if ( $api_key ) {
		$api  = new Make_Kisi_API( $api_key );
		$sync = new Make_Kisi_Sync( $api );
		$sync->register_hooks();
	}

	$admin = new Make_Kisi_Admin();
	$admin->register_hooks();
}, 20 );
