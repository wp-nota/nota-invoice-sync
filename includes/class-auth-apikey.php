<?php
/**
 * API key authentication (Lexware Public API).
 *
 * The user generates a personal key at https://app.lexware.de/addons/public-api
 * and it is sent as a bearer token. Keys do not expire and are scoped to a
 * single Lexware organisation.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Auth_Apikey extends Nota_Inv_Auth_Provider {

	public function get_id() {
		return 'apikey';
	}

	public function is_configured() {
		return '' !== trim( Nota_Inv_Settings::instance()->get_api_key() );
	}

	public function get_auth_headers() {
		$key = trim( Nota_Inv_Settings::instance()->get_api_key() );

		if ( '' === $key ) {
			return new WP_Error(
				'nota_inv_no_credentials',
				__( 'No Lexware Office API key has been configured yet.', 'nota-invoice-sync' )
			);
		}

		return array(
			'Authorization' => 'Bearer ' . $key,
		);
	}
}
