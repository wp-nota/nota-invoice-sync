<?php
/**
 * Daily health check: is the API key still valid, and are there orders
 * that repeatedly failed to invoice? Surfaces problems the shop owner would
 * otherwise only discover when a customer complains about a missing invoice.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Health_Check {

	const CRON_HOOK   = 'nota_inv_health_check';
	const OPTION_KEY   = 'nota_inv_health_status';

	/**
	 * @var Nota_Inv_Health_Check|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( $this, 'maybe_schedule' ) );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		}

		register_deactivation_hook( NOTA_INV_FILE, array( __CLASS__, 'unschedule' ) );
	}

	/**
	 * Make sure the daily event is scheduled. Cheap to call repeatedly —
	 * wp_schedule_event() is a no-op if an event with this hook already
	 * exists.
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled event on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * The actual check, run once a day by cron. Also called directly from the
	 * settings page after a manual "Save and test connection", passing the
	 * profile result already fetched for the on-screen notice — so the
	 * status badge reflects that same result immediately instead of waiting
	 * for the next cron run and showing a stale failure in the meantime.
	 *
	 * @param array|WP_Error|null $profile Already-fetched profile result, or
	 *                                      null to fetch one here (cron path).
	 * @return void
	 */
	public function run( $profile = null ) {
		$settings = Nota_Inv_Settings::instance();

		if ( ! $settings->is_connected() ) {
			$this->store( array( 'connected' => null ) );
			return;
		}

		// Test mode = no API traffic at all, so the daily connection ping is
		// skipped too. The last stored result is left in place rather than
		// overwritten with an artificial state.
		if ( $settings->is( 'test_mode' ) ) {
			Nota_Inv_Logger::debug( 'TEST MODE — daily health check skipped, no API request made.' );
			return;
		}

		if ( null === $profile ) {
			$client  = new Nota_Inv_Api_Client();
			$profile = $client->get_profile();
		}

		$result = array(
			'checked_at'    => time(),
			'connected'     => ! is_wp_error( $profile ),
			'error'         => is_wp_error( $profile ) ? $profile->get_error_message() : '',
			'failed_orders' => $this->count_recent_failures(),
		);

		$this->store( $result );

		Nota_Inv_Logger::debug( 'Health check: ' . wp_json_encode( $result ) );
	}

	/**
	 * Orders with a recorded error and no invoice, from the last 7 days.
	 *
	 * A lightweight count for the admin notice — not exhaustive, just enough
	 * to say "something needs attention" without a heavy query.
	 *
	 * @return int
	 */
	private function count_recent_failures() {
		$orders = wc_get_orders(
			array(
				'limit'      => 50,
				'date_after' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => NOTA_INV_META_LAST_ERROR,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => NOTA_INV_META_INVOICE_ID,
						'compare' => 'NOT EXISTS',
					),
				),
				'return'     => 'ids',
			)
		);

		return is_array( $orders ) ? count( $orders ) : 0;
	}

	/**
	 * Persist the latest result.
	 *
	 * @param array $data Result data.
	 * @return void
	 */
	private function store( array $data ) {
		update_option( self::OPTION_KEY, $data, false );
	}

	/**
	 * Latest stored result.
	 *
	 * @return array
	 */
	public static function status() {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Warn on the settings page (only there — this is diagnostic, not
	 * urgent enough to follow the admin around the whole dashboard).
	 *
	 * @return void
	 */
	public function maybe_show_notice() {
		$screen = get_current_screen();

		if ( ! $screen || false === strpos( (string) $screen->id, 'nota-invoice-sync' ) ) {
			return;
		}

		$status = self::status();

		if ( empty( $status ) || empty( $status['checked_at'] ) ) {
			return;
		}

		// Stale check (cron may not be running) — not itself an error, skip.
		if ( ( time() - (int) $status['checked_at'] ) > 2 * DAY_IN_SECONDS ) {
			return;
		}

		if ( false === $status['connected'] ) {
			echo '<div class="notice notice-error"><p><strong>';
			esc_html_e( 'Nota Invoice Sync: the connection to Lexware Office is currently failing.', 'nota-invoice-sync' );
			echo '</strong> ' . esc_html( $status['error'] ) . '</p></div>';
		}

		if ( ! empty( $status['failed_orders'] ) && $status['failed_orders'] > 0 ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: number of orders. */
					_n(
						'%d order from the last 7 days failed to invoice and has no invoice yet.',
						'%d orders from the last 7 days failed to invoice and have no invoice yet.',
						$status['failed_orders'],
						'nota-invoice-sync'
					),
					$status['failed_orders']
				)
			);
			echo ' ' . esc_html__( 'Check the log for details.', 'nota-invoice-sync' ) . '</p></div>';
		}
	}
}
