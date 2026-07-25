<?php
/**
 * Orchestrates the whole "order becomes an invoice" flow.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Invoice_Service {

	/**
	 * Cached organisation profile for this request.
	 *
	 * @var array|null
	 */
	private static $profile = null;

	/**
	 * @var Nota_Inv_Api_Client
	 */
	private $client;

	/**
	 * @param Nota_Inv_Api_Client|null $client Optional client override.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof Nota_Inv_Api_Client ? $client : new Nota_Inv_Api_Client();
	}

	/**
	 * Create the invoice for an order.
	 *
	 * @param int  $order_id Order id.
	 * @param bool $force    Ignore the "already invoiced" guard.
	 * @return array|WP_Error Result payload on success.
	 */
	public function create_for_order( $order_id, $force = false ) {
		$order_id = (int) $order_id;

		if ( ! Nota_Inv_Lock::acquire( $order_id ) ) {
			Nota_Inv_Logger::debug(
				sprintf( 'Order %d is already being invoiced by another process — skipping.', $order_id )
			);

			return new WP_Error(
				'nota_inv_locked',
				__( 'An invoice for this order is already being created by another process. Please wait a moment and refresh.', 'nota-invoice-sync' )
			);
		}

		try {
			return $this->create_for_order_locked( $order_id, $force );
		} finally {
			Nota_Inv_Lock::release( $order_id );
		}
	}

	/**
	 * The actual invoice run. Only ever called while holding the order lock,
	 * which is what makes the "already invoiced" meta check below reliable:
	 * no other process can be between "check" and "write" at the same time.
	 *
	 * @param int  $order_id Order id.
	 * @param bool $force    Ignore the "already invoiced" guard.
	 * @return array|WP_Error Result payload on success.
	 */
	private function create_for_order_locked( $order_id, $force = false ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'nota_inv_no_order',
				sprintf(
					/* translators: %d: order id. */
					__( 'Order %d could not be loaded.', 'nota-invoice-sync' ),
					$order_id
				)
			);
		}

		$settings = Nota_Inv_Settings::instance();

		if ( ! $settings->is_connected() ) {
			return new WP_Error(
				'nota_inv_not_connected',
				__( 'No Lexware Office API key has been configured yet.', 'nota-invoice-sync' )
			);
		}

		$existing = $order->get_meta( NOTA_INV_META_INVOICE_ID );

		if ( ! $force && is_string( $existing ) && '' !== $existing ) {
			Nota_Inv_Logger::debug( sprintf( 'Order %d already has invoice %s — skipping.', $order->get_id(), $existing ) );
			return array(
				'skipped'    => true,
				'invoice_id' => $existing,
			);
		}

		// NOTE: a monthly usage cap was previously enforced here. Removed —
		// per WordPress.org guideline 5 ("Trialware is not permitted"),
		// functionality may not be disabled after a self-imposed quota is
		// met when the limit lives in the plugin's own code rather than a
		// genuine third-party service limit. Free invoicing is unlimited;
		// Pro is differentiated by post-finalisation automation instead
		// (payment sync, reminders, PDF delivery, bulk import at scale).

		// Test mode makes no API requests at all — not even read-only ones,
		// and in particular no contact search/create/update (contact sync
		// WRITES to Lexware, which a dry run must never do). The payload is
		// built with a placeholder contact id and the same empty-profile
		// fallback used when the profile fetch fails, so OSS-related
		// taxSubType values in the logged payload can differ from a live run.
		if ( $settings->is( 'test_mode' ) ) {
			$profile    = array();
			$contact_id = 'TEST-MODE-NO-CONTACT';
		} else {
			$profile = $this->profile();

			$contacts   = new Nota_Inv_Contact_Sync( $this->client );
			$contact_id = $contacts->get_contact_id( $order );

			if ( is_wp_error( $contact_id ) ) {
				$this->record_failure( $order, $contact_id );
				return $contact_id;
			}
		}

		$resolver = new Nota_Inv_Tax_Resolver( is_array( $profile ) ? $profile : array() );

		$builder = new Nota_Inv_Invoice_Builder( $resolver );
		$payload = $builder->build( $order, $contact_id );

		if ( is_wp_error( $payload ) ) {
			$this->record_failure( $order, $payload );
			return $payload;
		}

		if ( $settings->is( 'test_mode' ) ) {
			Nota_Inv_Logger::error(
				sprintf( 'TEST MODE — invoice for order %d was built but not sent.', $order->get_id() ),
				array( 'payload' => $payload )
			);

			return array(
				'test_mode' => true,
				'payload'   => $payload,
			);
		}

		$path = '/v1/invoices';

		if ( $settings->is( 'finalize' ) ) {
			$path .= '?finalize=true';
		}

		$response = $this->client->post( $path, $payload );

		if ( is_wp_error( $response ) ) {
			$this->record_failure( $order, $response );
			return $response;
		}

		if ( empty( $response['id'] ) ) {
			$error = new WP_Error(
				'nota_inv_invoice_no_id',
				__( 'Lexware Office did not return an id for the new invoice.', 'nota-invoice-sync' )
			);
			$this->record_failure( $order, $error );
			return $error;
		}

		return $this->record_success( $order, (string) $response['id'] );
	}

	/**
	 * Store the invoice reference on the order and note it in the timeline.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $invoice_id Lexware invoice UUID.
	 * @return array
	 */
	private function record_success( WC_Order $order, $invoice_id ) {
		$number  = '';
		$status  = '';
		$profile = '';
		$detail  = $this->client->get( '/v1/invoices/' . rawurlencode( $invoice_id ) );

		if ( ! is_wp_error( $detail ) ) {
			if ( ! empty( $detail['voucherNumber'] ) ) {
				$number = (string) $detail['voucherNumber'];
			}
			if ( ! empty( $detail['voucherStatus'] ) ) {
				$status = (string) $detail['voucherStatus'];
			}
			// Field name per Lexware's public API docs; may be absent for
			// invoices that never qualified as an e-invoice (see class-
			// admin-settings-page.php's e-invoice reminder note for why).
			if ( ! empty( $detail['electronicDocumentProfile'] ) ) {
				$profile = (string) $detail['electronicDocumentProfile'];
			}
		}

		$order->update_meta_data( NOTA_INV_META_INVOICE_ID, $invoice_id );

		if ( '' !== $number ) {
			$order->update_meta_data( NOTA_INV_META_INVOICE_NUMBER, $number );
		}

		if ( '' !== $status ) {
			$order->update_meta_data( NOTA_INV_META_INVOICE_STATUS, $status );
		}

		if ( '' !== $profile ) {
			$order->update_meta_data( NOTA_INV_META_EDOC_PROFILE, $profile );
		}

		$order->delete_meta_data( NOTA_INV_META_LAST_ERROR );
		$order->save();

		if ( Nota_Inv_Settings::instance()->is( 'write_order_note' ) ) {
			if ( 'draft' === $status ) {
				$note = '' !== $number
					? sprintf(
						/* translators: %s: invoice number. */
						__( 'Lexware Office draft invoice created: %s', 'nota-invoice-sync' ),
						$number
					)
					: __( 'Lexware Office draft invoice created.', 'nota-invoice-sync' );
			} else {
				$note = '' !== $number
					? sprintf(
						/* translators: %s: invoice number. */
						__( 'Lexware Office invoice created: %s', 'nota-invoice-sync' ),
						$number
					)
					: __( 'Lexware Office invoice created.', 'nota-invoice-sync' );
			}

			$order->add_order_note( $note );
		}

		Nota_Inv_Logger::debug(
			sprintf(
				'Order %d invoiced as %s (%s), status %s.',
				$order->get_id(),
				$number ? $number : '(no number)',
				$invoice_id,
				$status ? $status : 'unknown'
			)
		);

		return array(
			'invoice_id'     => $invoice_id,
			'invoice_number' => $number,
			'status'         => $status,
		);
	}

	/**
	 * Remember why an attempt failed so it can be shown on the order screen.
	 *
	 * @param WC_Order $order Order.
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private function record_failure( WC_Order $order, WP_Error $error ) {
		$order->update_meta_data( NOTA_INV_META_LAST_ERROR, $error->get_error_message() );
		$order->save();

		Nota_Inv_Logger::error(
			sprintf( 'Order %d could not be invoiced: %s', $order->get_id(), $error->get_error_message() )
		);
	}

	/**
	 * Organisation profile, fetched once per request.
	 *
	 * @return array|WP_Error
	 */
	private function profile() {
		if ( null === self::$profile ) {
			self::$profile = $this->client->get_profile();
		}

		return self::$profile;
	}

	/**
	 * Deeplink to view an invoice in the Lexware web app.
	 *
	 * @param string $invoice_id Invoice UUID.
	 * @return string
	 */
	public static function deeplink( $invoice_id ) {
		return 'https://app.lexware.de/permalink/invoices/view/' . rawurlencode( $invoice_id );
	}
}
