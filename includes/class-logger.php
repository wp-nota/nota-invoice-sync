<?php
/**
 * Logging helper built on the WooCommerce logger.
 *
 * Logs are written to WooCommerce → Status → Logs under the source
 * "nota-invoice-sync". Credentials are never written out.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Logger {

	const SOURCE = 'nota-invoice-sync';

	/**
	 * @var WC_Logger_Interface|null
	 */
	private static $logger = null;

	/**
	 * Log an error. Always recorded unless logging is switched off entirely.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Log a debug message. Only recorded when log level is "debug".
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 * @return void
	 */
	public static function debug( $message, array $context = array() ) {
		if ( 'debug' !== Nota_Inv_Settings::instance()->get( 'log_level' ) ) {
			return;
		}
		self::write( 'debug', $message, $context );
	}

	/**
	 * Write to the WooCommerce log.
	 *
	 * @param string $level   WC log level.
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 * @return void
	 */
	private static function write( $level, $message, array $context = array() ) {
		if ( 'off' === Nota_Inv_Settings::instance()->get( 'log_level' ) ) {
			return;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( null === self::$logger ) {
			self::$logger = wc_get_logger();
		}

		$line = $message;

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ) );
		}

		self::$logger->log( $level, $line, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Strip anything credential-shaped out of log context.
	 *
	 * @param mixed $data Data to clean.
	 * @return mixed
	 */
	private static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$sensitive = array( 'authorization', 'api_key', 'apikey', 'access_token', 'refresh_token', 'client_secret', 'password' );
		$clean     = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), $sensitive, true ) ) {
				$clean[ $key ] = '***';
				continue;
			}
			$clean[ $key ] = is_array( $value ) ? self::redact( $value ) : $value;
		}

		return $clean;
	}
}
