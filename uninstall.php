<?php
/**
 * Fired when the plugin is deleted via the WordPress admin (Plugins →
 * Delete). WordPress requires this exact file/location and calls it
 * directly — it must guard against direct access itself.
 *
 * By default only the plugin's own settings and scheduled jobs are
 * removed. The order↔invoice reference meta on individual orders
 * (_nota_inv_invoice_id etc.) is kept unless the user explicitly opted in
 * via "Remove all plugin data" in Settings — losing that mapping means a
 * shop owner can no longer see which order corresponds to which Lexware
 * invoice number from the order screen, even though the invoice itself
 * still exists safely in Lexware Office.
 *
 * Nothing here ever touches Lexware Office itself — no invoice, contact,
 * or any other remote record is created, changed, or deleted by this file,
 * regardless of the setting below.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove the order↔invoice reference meta this plugin wrote, on every
 * order, in both the classic (postmeta) and HPOS storage schemes.
 *
 * There is no WooCommerce API for "delete this meta key from every order
 * regardless of storage backend", so a direct query is unavoidable here —
 * this only ever runs once, at uninstall, never during normal operation.
 *
 * @return void
 */
function nota_inv_uninstall_remove_order_meta() {
	global $wpdb;

	$meta_keys = array(
		'_nota_inv_invoice_id',
		'_nota_inv_invoice_number',
		'_nota_inv_invoice_status',
		'_nota_inv_contact_id',
		'_nota_inv_last_error',
	);

	foreach ( $meta_keys as $key ) {
		// Classic (post-based) order storage.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- uninstall-only, no WooCommerce API for bulk meta removal across all orders.
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) );

		// HPOS (custom order tables), if this store uses it.
		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off schema check, uninstall-only.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- uninstall-only, no WooCommerce API for bulk meta removal across all orders.
			$wpdb->delete( $hpos_table, array( 'meta_key' => $key ) );
		}
	}
}

/**
 * Everything this file does, kept in one function so none of its working
 * variables leak into the global scope that uninstall.php otherwise runs in.
 *
 * @return void
 */
function nota_inv_run_uninstall() {
	$stored   = get_option( 'nota_inv_settings', array() );
	$keep_all = ! ( is_array( $stored ) && isset( $stored['uninstall_remove_all'] ) && 'yes' === $stored['uninstall_remove_all'] );

	if ( ! $keep_all ) {
		nota_inv_uninstall_remove_order_meta();
	}

	// Plugin settings and diagnostics are always removed — nothing useful
	// survives a reinstall for these regardless of the order-data choice above.
	delete_option( 'nota_inv_settings' );
	delete_option( 'nota_inv_health_status' );

	$timestamp = wp_next_scheduled( 'nota_inv_health_check' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'nota_inv_health_check' );
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'nota_inv_create_invoice', array(), 'nota-invoice-sync' );
	}
}

nota_inv_run_uninstall();
