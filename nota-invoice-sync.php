<?php
/**
 * Plugin Name:       Nota Invoice Sync for Lexware Office
 * Plugin URI:        https://www.wp-nota.com/lexware-invoice-sync/
 * Description:       Creates invoices in Lexware Office directly from WooCommerce orders — no third-party middleware. Tax amounts are calculated by Lexware itself, which avoids rounding mismatches.
 * Version:           0.1.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            wp-nota.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nota-invoice-sync
 * Domain Path:       /languages
 *
 * Lexware and Lexware Office are trademarks of Haufe-Lexware GmbH & Co. KG.
 * This plugin is an independent product and is not affiliated with or
 * endorsed by Haufe-Lexware.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Pro-edition handover. The Pro edition shares this plugin's class, function
 * and constant names, and its folder name sorts BEFORE this one, so when both
 * are active the Pro edition has already fully loaded by the time this file
 * runs. Define nothing in that case — redeclaring anything would fatal. The
 * Pro edition deactivates this plugin on the next admin page load; settings
 * and order meta are shared between editions, so nothing is lost.
 */
if ( defined( 'NOTA_INV_VERSION' ) ) {
	return;
}

define( 'NOTA_INV_VERSION', '0.1.5' );
define( 'NOTA_INV_FILE', __FILE__ );
define( 'NOTA_INV_PATH', plugin_dir_path( __FILE__ ) );
define( 'NOTA_INV_URL', plugin_dir_url( __FILE__ ) );
define( 'NOTA_INV_OPTION', 'nota_inv_settings' );

/**
 * Order meta keys used by this plugin.
 */
define( 'NOTA_INV_META_INVOICE_ID', '_nota_inv_invoice_id' );
define( 'NOTA_INV_META_INVOICE_NUMBER', '_nota_inv_invoice_number' );
define( 'NOTA_INV_META_INVOICE_STATUS', '_nota_inv_invoice_status' );
define( 'NOTA_INV_META_CONTACT_ID', '_nota_inv_contact_id' );
define( 'NOTA_INV_META_LAST_ERROR', '_nota_inv_last_error' );
define( 'NOTA_INV_META_EDOC_PROFILE', '_nota_inv_edoc_profile' );

/**
 * Simple PSR-4-ish autoloader for the plugin's own classes.
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'Nota_Inv_' ) ) {
			return;
		}

		$slug = strtolower( str_replace( array( 'Nota_Inv_', '_' ), array( '', '-' ), $class ) );

		foreach ( array( 'includes', 'admin' ) as $dir ) {
			$file = NOTA_INV_PATH . $dir . '/class-' . $slug . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

/**
 * Load the plugin's own translation file. Not optional here: this plugin is
 * not (yet) distributed through WordPress.org, so there is no GlotPress
 * translation pack for WordPress to auto-load — without this call, a bundled
 * languages/nota-invoice-sync-de_DE.mo would simply never be read. Hooked on
 * init rather than called directly from the plugins_loaded bootstrap below,
 * per the WP 6.7+ "load translations no earlier than init" guidance.
 *
 * @return void
 */
function nota_inv_load_textdomain() {
	load_plugin_textdomain(
		'nota-invoice-sync',
		false,
		dirname( plugin_basename( NOTA_INV_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'nota_inv_load_textdomain' );

/**
 * Bail out with an admin notice if WooCommerce is missing.
 */
function nota_inv_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>';
	esc_html_e( 'Nota Invoice Sync for Lexware Office requires WooCommerce to be installed and active.', 'nota-invoice-sync' );
	echo '</p></div>';
}

/**
 * Boot the plugin once all plugins are loaded.
 */
function nota_inv_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'nota_inv_missing_woocommerce_notice' );
		return;
	}

	Nota_Inv_Settings::instance();
	// Automatic invoicing on order status change is a Pro feature and its
	// code does not exist in this edition. This version only invoices via
	// the manual button on the order screen (Nota_Inv_Admin_Order_Metabox),
	// which calls Nota_Inv_Invoice_Service directly and needs no scheduler.
	Nota_Inv_Health_Check::instance();
	Nota_Inv_Privacy::instance();

	if ( is_admin() ) {
		Nota_Inv_Admin_Settings_Page::instance();
		Nota_Inv_Admin_Order_Metabox::instance();
		Nota_Inv_Admin_Order_List::instance();
		Nota_Inv_Admin_Product_Fields::instance();
		Nota_Inv_Admin_Assets::instance();
	}
}
add_action( 'plugins_loaded', 'nota_inv_bootstrap' );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				NOTA_INV_FILE,
				true
			);
		}
	}
);
