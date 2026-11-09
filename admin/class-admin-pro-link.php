<?php
/**
 * Shared links for the "available in Pro" hints of this edition.
 *
 * Plain links only: nothing here sends a request or loads anything from a
 * remote server. Every link — the trial one included — goes to the product
 * page on wp-nota.com, which explains Pro and carries the actual trial
 * button (14 days, no payment method asked — configured on the Pro plan in
 * Freemius). Keeping the checkout link on the website means it can change
 * without a plugin update.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Admin_Pro_Link {

	const URL_EN = 'https://www.wp-nota.com/lexware-invoice-sync/';
	const URL_DE = 'https://www.wp-nota.com/lexware-invoice-sync-de/';

	/**
	 * Product page in the admin's language (German page for de_* locales).
	 *
	 * @return string
	 */
	public static function info_url() {
		$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();
		return 0 === strpos( strtolower( (string) $locale ), 'de' ) ? self::URL_DE : self::URL_EN;
	}

	/**
	 * The trial link as HTML, ready to print after a Pro hint.
	 *
	 * @return string
	 */
	public static function trial_html() {
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( self::info_url() ),
			esc_html__( 'Try Pro free for 14 days — no credit card required', 'nota-invoice-sync' )
		);
	}

	/**
	 * Echo the trial link as its own description line.
	 *
	 * @return void
	 */
	public static function render_trial_line() {
		echo '<p class="description">' . self::trial_html() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html__() in trial_html().
	}
}
