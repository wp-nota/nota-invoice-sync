<?php
/**
 * Enqueues the plugin's own admin stylesheet and script, only on the
 * screens it actually renders something on — the settings page and the
 * order edit screens (classic and HPOS) where the invoice metabox lives.
 * The script is settings-page-only (it only backs the "Copy diagnostics
 * for support" button there). Purely presentational: no markup, form
 * fields or business logic live here.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Admin_Assets {

	/**
	 * @var Nota_Inv_Admin_Assets|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	/**
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function maybe_enqueue( $hook_suffix ) {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		$is_settings_page  = false !== strpos( (string) $hook_suffix, 'nota-invoice-sync' );
		$is_order_screen   = in_array( $screen_id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
		$is_product_screen = 'product' === $screen_id;

		if ( ! $is_settings_page && ! $is_order_screen && ! $is_product_screen ) {
			return;
		}

		wp_enqueue_style(
			'nota-inv-admin',
			NOTA_INV_URL . 'assets/css/admin.css',
			array(),
			NOTA_INV_VERSION
		);

		// Only the settings page has anything for this script to do (the
		// "Copy diagnostics for support" button in the Diagnostics card).
		if ( $is_settings_page ) {
			wp_enqueue_script(
				'nota-inv-admin',
				NOTA_INV_URL . 'assets/js/admin.js',
				array(),
				NOTA_INV_VERSION,
				true
			);
		}
	}
}
