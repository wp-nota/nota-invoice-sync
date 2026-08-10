<?php
/**
 * Lexware API HTTP client.
 *
 * Responsibilities:
 *  - attach auth headers from the configured provider
 *  - respect the documented rate limit of 2 requests per second
 *  - retry on 429 / 5xx with exponential backoff
 *  - turn API error payloads into readable WP_Error objects
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Api_Client {

	/**
	 * Production API gateway. The former api.lexoffice.io host was retired
	 * after the Lexware rebranding.
	 */
	const BASE_URL = 'https://api.lexware.io';

	/**
	 * Documented limit: 2 requests per second. We stay a little under it.
	 */
	const MIN_INTERVAL_MS = 550;

	/**
	 * How often a throttled or temporarily failing call is repeated.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Transient holding the timestamp (ms) of the last outbound request.
	 */
	const PACING_KEY = 'nota_inv_last_request_ms';

	/**
	 * @var Nota_Inv_Auth_Provider
	 */
	private $auth;

	/**
	 * @param Nota_Inv_Auth_Provider|null $auth Optional provider override.
	 */
	public function __construct( $auth = null ) {
		$this->auth = $auth instanceof Nota_Inv_Auth_Provider ? $auth : Nota_Inv_Auth_Provider::make();
	}

	/**
	 * GET request.
	 *
	 * @param string $path           Path beginning with a slash, e.g. '/v1/profile'.
	 * @param array  $query          Query arguments.
	 * @param array  $quiet_statuses HTTP status codes the caller already knows are a normal,
	 *                               expected outcome here (e.g. 406 when polling a draft
	 *                               invoice's payment status) — logged at debug level instead
	 *                               of error, so the error log stays a reliable "something
	 *                               actually needs attention" signal. Empty by default:
	 *                               every other caller keeps today's behaviour unchanged.
	 * @return array|WP_Error Decoded body.
	 */
	public function get( $path, array $query = array(), array $quiet_statuses = array() ) {
		if ( ! empty( $query ) ) {
			$path .= ( false === strpos( $path, '?' ) ? '?' : '&' ) . http_build_query( $query );
		}
		return $this->request( 'GET', $path, null, $quiet_statuses );
	}

	/**
	 * POST request with a JSON body.
	 *
	 * @param string $path Path beginning with a slash.
	 * @param array  $body Payload.
	 * @return array|WP_Error Decoded body.
	 */
	public function post( $path, array $body ) {
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * PUT request with a JSON body.
	 *
	 * Lexware uses optimistic locking on updatable resources: the body must
	 * include the current "version" or the API answers 409, so callers are
	 * responsible for fetching the latest version first.
	 *
	 * @param string $path Path beginning with a slash.
	 * @param array  $body Full replacement payload, including "version".
	 * @return array|WP_Error Decoded body.
	 */
	public function put( $path, array $body ) {
		return $this->request( 'PUT', $path, $body );
	}

	/**
	 * Perform a request, handling pacing and retries.
	 *
	 * @param string     $method         HTTP verb.
	 * @param string     $path           Path with optional query string.
	 * @param array|null $body           Optional JSON body.
	 * @param array      $quiet_statuses See get() — passed through to error_from_response().
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = null, array $quiet_statuses = array() ) {
		$headers = $this->auth->get_auth_headers();

		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge(
				$headers,
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'NotaInvoiceSync/' . NOTA_INV_VERSION . '; ' . home_url( '/' ),
				)
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url          = self::BASE_URL . $path;
		$attempt      = 0;
		$last_error   = null;
		$refreshed    = false;

		while ( $attempt < self::MAX_ATTEMPTS ) {
			$attempt++;

			$this->pace();

			Nota_Inv_Logger::debug(
				sprintf( '%s %s (attempt %d)', $method, $path, $attempt ),
				null === $body ? array() : array( 'body' => $body )
			);

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				// Transport-level failure (DNS, timeout, TLS). Worth retrying.
				$last_error = $response;
				$this->backoff( $attempt );
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = wp_remote_retrieve_body( $response );

			if ( 429 === $status || $status >= 500 ) {
				$last_error = $this->error_from_response( $status, $raw, $quiet_statuses );
				$this->backoff( $attempt );
				continue;
			}

			if ( 401 === $status && ! $refreshed && $this->auth->refresh() ) {
				// Token providers get exactly one chance to refresh and retry.
				$refreshed       = true;
				$fresh           = $this->auth->get_auth_headers();
				if ( ! is_wp_error( $fresh ) ) {
					$args['headers'] = array_merge( $args['headers'], $fresh );
				}
				continue;
			}

			if ( $status >= 400 ) {
				return $this->error_from_response( $status, $raw, $quiet_statuses );
			}

			if ( 204 === $status || '' === trim( (string) $raw ) ) {
				return array();
			}

			$decoded = json_decode( $raw, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error(
					'nota_inv_bad_json',
					__( 'Lexware Office returned a response that could not be read.', 'nota-invoice-sync' ),
					array( 'status' => $status, 'body' => substr( (string) $raw, 0, 500 ) )
				);
			}

			return is_array( $decoded ) ? $decoded : array();
		}

		return $last_error instanceof WP_Error
			? $last_error
			: new WP_Error( 'nota_inv_request_failed', __( 'The request to Lexware Office failed.', 'nota-invoice-sync' ) );
	}

	/**
	 * Keep outbound calls under the documented rate limit by spacing them out.
	 *
	 * @return void
	 */
	private function pace() {
		$now  = (int) round( microtime( true ) * 1000 );
		$last = (int) get_transient( self::PACING_KEY );

		if ( $last > 0 ) {
			$elapsed = $now - $last;
			if ( $elapsed < self::MIN_INTERVAL_MS ) {
				usleep( ( self::MIN_INTERVAL_MS - $elapsed ) * 1000 );
			}
		}

		set_transient( self::PACING_KEY, (int) round( microtime( true ) * 1000 ), 60 );
	}

	/**
	 * Exponential backoff between retries.
	 *
	 * @param int $attempt 1-based attempt counter.
	 * @return void
	 */
	private function backoff( $attempt ) {
		// 1s, 2s, 4s ...
		$seconds = min( 8, pow( 2, $attempt - 1 ) );
		sleep( $seconds );
	}

	/**
	 * Translate an API error response into a WP_Error.
	 *
	 * Lexware uses two shapes: a legacy one with `message`, and a regular one
	 * with `IssueList[]` entries carrying field-level details.
	 *
	 * @param int    $status         HTTP status.
	 * @param string $raw            Raw body.
	 * @param array  $quiet_statuses See get() — statuses the caller already
	 *                                expects, logged as debug instead of error.
	 * @return WP_Error
	 */
	private function error_from_response( $status, $raw, array $quiet_statuses = array() ) {
		$decoded = json_decode( (string) $raw, true );
		$parts   = array();

		if ( is_array( $decoded ) ) {
			if ( ! empty( $decoded['message'] ) ) {
				$parts[] = (string) $decoded['message'];
			}

			if ( ! empty( $decoded['IssueList'] ) && is_array( $decoded['IssueList'] ) ) {
				foreach ( $decoded['IssueList'] as $issue ) {
					$field = isset( $issue['source'] ) ? $issue['source'] : '';
					$text  = isset( $issue['i18nKey'] ) ? $issue['i18nKey'] : '';
					if ( isset( $issue['additionalData'] ) && is_string( $issue['additionalData'] ) ) {
						$text .= ' (' . $issue['additionalData'] . ')';
					}
					$parts[] = trim( $field . ': ' . $text, ': ' );
				}
			}

			if ( ! empty( $decoded['error_description'] ) ) {
				$parts[] = (string) $decoded['error_description'];
			}
		}

		if ( empty( $parts ) ) {
			$parts[] = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Lexware Office responded with HTTP %d.', 'nota-invoice-sync' ),
				$status
			);
		}

		$message = implode( ' | ', $parts );

		if ( in_array( $status, $quiet_statuses, true ) ) {
			// The caller already knows this status is a normal outcome here
			// (e.g. 406 while polling a draft invoice's payment status) —
			// still recorded, just not under "error", so that log level
			// stays a reliable signal that something needs attention.
			Nota_Inv_Logger::debug( sprintf( 'API response %d (expected): %s', $status, $message ) );
		} else {
			Nota_Inv_Logger::error( sprintf( 'API error %d: %s', $status, $message ) );
		}

		return new WP_Error(
			'nota_inv_api_error_' . $status,
			$message,
			array( 'status' => $status )
		);
	}

	/**
	 * Fetch organisation profile. Also doubles as the connection test.
	 *
	 * @return array|WP_Error
	 */
	public function get_profile() {
		return $this->get( '/v1/profile' );
	}
}
