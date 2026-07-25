<?php
/**
 * Abstract authentication provider.
 *
 * The rest of the plugin never touches credentials directly — it only asks a
 * provider for request headers. That keeps the door open for an OAuth2
 * provider later on (Lexware Partner API) without touching the API client,
 * the invoice builder, or anything else.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Nota_Inv_Auth_Provider {

	/**
	 * Build the provider configured in the settings.
	 *
	 * @return Nota_Inv_Auth_Provider
	 */
	public static function make() {
		$settings = Nota_Inv_Settings::instance();

		/**
		 * Allows a future add-on (e.g. an OAuth module) to supply its own
		 * provider without modifying core plugin files.
		 *
		 * @param Nota_Inv_Auth_Provider|null $provider Provider instance or null.
		 * @param string                      $method   Configured auth method.
		 */
		$custom = apply_filters( 'nota_inv_auth_provider', null, $settings->get( 'auth_method' ) );

		if ( $custom instanceof self ) {
			return $custom;
		}

		return new Nota_Inv_Auth_Apikey();
	}

	/**
	 * Machine name of this provider.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Whether usable credentials are present.
	 *
	 * @return bool
	 */
	abstract public function is_configured();

	/**
	 * Headers to merge into every API request.
	 *
	 * @return array<string,string>|WP_Error
	 */
	abstract public function get_auth_headers();

	/**
	 * Called when the API answers 401, so token-based providers can refresh
	 * and let the client retry once. Static credentials simply return false.
	 *
	 * @return bool True when a retry is worthwhile.
	 */
	public function refresh() {
		return false;
	}
}
