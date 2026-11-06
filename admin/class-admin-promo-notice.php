<?php
/**
 * Time-limited, dismissible admin notice for the Black Friday 2026 offer on
 * Nota Invoice Sync Pro.
 *
 * Deliberately narrow:
 * - The dates are fixed in this file. Nothing is fetched from a server, so the
 *   notice adds no network request and sends no data anywhere.
 * - Outside the two windows below the class does nothing at all.
 * - Only users who can manage WooCommerce see it, and only on the Dashboard,
 *   the Plugins screen, WooCommerce screens and this plugin's settings page —
 *   never on post, page or media editing screens.
 * - Each phase is dismissed per user, permanently, with one click. A user who
 *   dismissed the announcement sees the notice once more when the offer
 *   actually starts (the code only works from then on), never again after
 *   dismissing that one.
 * - Sites can switch it off entirely with the `nota_inv_show_promo_notice`
 *   filter.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Admin_Promo_Notice {

	const COUPON       = 'FSBFCM2026';
	const USER_META    = 'nota_inv_promo_dismissed';
	const AJAX_ACTION  = 'nota_inv_dismiss_promo';
	const NONCE_ACTION = 'nota_inv_promo';
	const URL_EN       = 'https://www.wp-nota.com/lexware-invoice-sync/';
	const URL_DE       = 'https://www.wp-nota.com/lexware-invoice-sync-de/';

	/**
	 * @var Nota_Inv_Admin_Promo_Notice|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Past the last window the notice can never show again — register
		// nothing, so the class costs nothing on every later request.
		if ( self::now() >= self::last_end() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * The notice windows, in UTC. Start inclusive, end exclusive.
	 * 'teaser' announces the offer; 'live' is when the coupon actually works
	 * (the Freemius coupon runs from 27 Nov 2026 00:00 GMT).
	 *
	 * @return array<string, array{0:int,1:int}>
	 */
	public static function phases() {
		return array(
			'bfcm2026_teaser' => array( strtotime( '2026-11-24 00:00:00 UTC' ), strtotime( '2026-11-27 00:00:00 UTC' ) ),
			'bfcm2026_live'   => array( strtotime( '2026-11-27 00:00:00 UTC' ), strtotime( '2026-12-02 00:00:00 UTC' ) ),
		);
	}

	/**
	 * Current time used for the windows. Filterable only so the notice can
	 * be previewed before its dates (e.g. in a test environment).
	 *
	 * @return int
	 */
	private static function now() {
		/**
		 * Filters the timestamp the Pro offer notice compares its dates with.
		 *
		 * @param int $timestamp Default time().
		 */
		return (int) apply_filters( 'nota_inv_promo_now', time() );
	}

	/**
	 * Which phase is active at a given time, or null if none.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string|null
	 */
	public static function phase_at( $timestamp ) {
		foreach ( self::phases() as $key => $window ) {
			if ( $timestamp >= $window[0] && $timestamp < $window[1] ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * End of the last window.
	 *
	 * @return int
	 */
	private static function last_end() {
		$end = 0;
		foreach ( self::phases() as $window ) {
			$end = max( $end, $window[1] );
		}
		return $end;
	}

	/**
	 * German-speaking admins get the German product page.
	 *
	 * @param string $locale WordPress locale, e.g. de_DE, de_DE_formal, en_US.
	 * @return string
	 */
	public static function url_for_locale( $locale ) {
		return 0 === strpos( strtolower( (string) $locale ), 'de' ) ? self::URL_DE : self::URL_EN;
	}

	/**
	 * The phase to show to the current user on the current screen, or null.
	 *
	 * @return string|null
	 */
	private function phase_to_show() {
		/**
		 * Filters whether the Black Friday notice for Nota Invoice Sync Pro may
		 * be shown at all. Return false to hide it on this site.
		 *
		 * @param bool $show Default true.
		 */
		if ( ! apply_filters( 'nota_inv_show_promo_notice', true ) ) {
			return null;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return null;
		}

		$phase = self::phase_at( self::now() );
		if ( null === $phase ) {
			return null;
		}

		$dismissed = get_user_meta( get_current_user_id(), self::USER_META, true );
		if ( is_array( $dismissed ) && in_array( $phase, $dismissed, true ) ) {
			return null;
		}

		return $this->is_allowed_screen() ? $phase : null;
	}

	/**
	 * Dashboard, Plugins, WooCommerce screens and this plugin's settings page.
	 *
	 * @return bool
	 */
	private function is_allowed_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		$id = (string) $screen->id;

		if ( in_array( $id, array( 'dashboard', 'plugins' ), true ) ) {
			return true;
		}

		if ( false !== strpos( $id, 'nota-invoice-sync' ) ) {
			return true;
		}

		if ( function_exists( 'wc_get_screen_ids' ) && in_array( $id, (array) wc_get_screen_ids(), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Small script that remembers the dismissal (WordPress's own "×" only
	 * hides the notice until the next page load).
	 *
	 * @return void
	 */
	public function maybe_enqueue() {
		if ( null === $this->phase_to_show() ) {
			return;
		}

		wp_enqueue_script(
			'nota-inv-promo-notice',
			NOTA_INV_URL . 'assets/js/promo-notice.js',
			array(),
			NOTA_INV_VERSION,
			true
		);

		wp_add_inline_script(
			'nota-inv-promo-notice',
			'window.notaInvPromo = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => self::AJAX_ACTION,
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * @return void
	 */
	public function maybe_render() {
		$phase = $this->phase_to_show();
		if ( null === $phase ) {
			return;
		}

		if ( 'bfcm2026_teaser' === $phase ) {
			/* translators: %s: coupon code. */
			$text = __( 'Black Friday is coming: 40%% off your first year of Nota Invoice Sync Pro with code %s — 27 Nov to 1 Dec.', 'nota-invoice-sync' );
		} else {
			/* translators: %s: coupon code. */
			$text = __( 'Black Friday: 40%% off your first year of Nota Invoice Sync Pro with code %s — until 1 Dec.', 'nota-invoice-sync' );
		}

		$message = sprintf( esc_html( $text ), '<strong>' . esc_html( self::COUPON ) . '</strong>' );

		printf(
			'<div class="notice notice-info is-dismissible nota-inv-promo" data-phase="%1$s"><p>🎉 %2$s <a href="%3$s" target="_blank" rel="noopener">%4$s</a></p></div>',
			esc_attr( $phase ),
			$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above; only the <strong> wrapper is markup.
			esc_url( self::url_for_locale( get_user_locale() ) ),
			esc_html__( 'See Pro features', 'nota-invoice-sync' )
		);
	}

	/**
	 * AJAX: remember that the current user dismissed a phase.
	 *
	 * @return void
	 */
	public function handle_dismiss() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( null, 403 );
		}

		$phase = isset( $_POST['phase'] ) ? sanitize_key( wp_unslash( $_POST['phase'] ) ) : '';
		if ( ! array_key_exists( $phase, self::phases() ) ) {
			wp_send_json_error( null, 400 );
		}

		$user_id   = get_current_user_id();
		$dismissed = get_user_meta( $user_id, self::USER_META, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : array();

		if ( ! in_array( $phase, $dismissed, true ) ) {
			$dismissed[] = $phase;
			update_user_meta( $user_id, self::USER_META, $dismissed );
		}

		wp_send_json_success();
	}
}
