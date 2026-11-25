<?php
/**
 * Creating a document in Lexware Office without ever creating it twice.
 *
 * A POST that times out or ends in a server error has an unknown outcome:
 * the API docs say about 504 "It is possible that your request has been
 * processed successfully". Sending it again blindly can therefore create a
 * second invoice or credit note — and a finalised one can never be deleted.
 * So instead of repeating such a request, this class first waits a moment
 * and asks Lexware Office whether the document exists (voucherlist, by
 * contact and creation time, plus the amount where there is one). Only when
 * nothing is found is the request sent once more.
 *
 * While the outcome is still unknown, a marker is kept on the order, so a
 * later attempt (a retry, the button clicked again) looks for the document
 * first instead of creating another one.
 *
 * 429 is not affected: the docs say the call is then not performed at all,
 * and the API client keeps retrying those itself.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Uncertain_Post {

	/**
	 * Seconds to wait before asking whether the document was created.
	 *
	 * @var int
	 */
	public static $wait_seconds = 5;

	/**
	 * A document created this long before the request started still counts
	 * (clock difference between this server and Lexware Office).
	 */
	const CLOCK_TOLERANCE = 120;

	/**
	 * Order meta prefix for "outcome still unknown since <timestamp>".
	 */
	const META_PREFIX = '_nota_inv_uncertain_';

	/**
	 * Error code for "not certain whether the document was created".
	 */
	const ERROR_CODE = 'nota_inv_uncertain';

	/**
	 * POST a document, never repeating it blindly.
	 *
	 * @param Nota_Inv_Api_Client $client  API client.
	 * @param string              $path    Path incl. query string.
	 * @param array               $payload Request body.
	 * @param callable            $find    function( int $since ): string|null|WP_Error — the id of the
	 *                                     document this request created, null when there is none,
	 *                                     WP_Error when it cannot be told.
	 * @param WC_Order|null       $order   Order to keep the "outcome unknown" marker on.
	 * @param string              $scope   Marker scope, e.g. "invoice".
	 * @return array|WP_Error Decoded response, array( 'id' => …, 'nota_inv_adopted' => true ) when an
	 *                        already created document was found, or WP_Error.
	 */
	public static function post( Nota_Inv_Api_Client $client, $path, array $payload, $find, $order = null, $scope = '' ) {
		$since = time();

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$response = $client->post( $path, $payload );

			if ( ! Nota_Inv_Api_Client::is_uncertain( $response ) ) {
				if ( ! is_wp_error( $response ) ) {
					self::clear( $order, $scope );
				}
				return $response;
			}

			self::mark( $order, $scope, $since );

			Nota_Inv_Logger::error( sprintf( 'POST %s: outcome unknown (%s) — checking Lexware Office before anything is sent again.', strtok( $path, '?' ), $response->get_error_message() ) );

			sleep( self::$wait_seconds );

			$found = call_user_func( $find, $since );

			if ( is_string( $found ) && '' !== $found ) {
				self::clear( $order, $scope );
				Nota_Inv_Logger::error( sprintf( 'POST %s: the document was created after all (%s) — using it, nothing sent again.', strtok( $path, '?' ), $found ) );
				return array(
					'id'               => $found,
					'nota_inv_adopted' => true,
				);
			}

			if ( is_wp_error( $found ) ) {
				return self::unknown_error( $found );
			}

			// Nothing there: sending it (once more) cannot create a duplicate.
		}

		return self::unknown_error();
	}

	/**
	 * Before creating a document: if an earlier attempt for it ended with an
	 * unknown outcome, look for that document first.
	 *
	 * @param WC_Order|null $order Order.
	 * @param string        $scope Marker scope.
	 * @param callable      $find  See post().
	 * @return array|WP_Error|null Same as post() when it was found or cannot be told; null to go ahead and create.
	 */
	public static function resolve_pending( $order, $scope, $find ) {
		$since = self::pending_since( $order, $scope );

		if ( null === $since ) {
			return null;
		}

		$found = call_user_func( $find, $since );

		if ( is_string( $found ) && '' !== $found ) {
			self::clear( $order, $scope );
			Nota_Inv_Logger::error( sprintf( 'Order %d: the %s from an earlier unanswered request exists in Lexware Office (%s) — using it.', $order->get_id(), $scope, $found ) );
			return array(
				'id'               => $found,
				'nota_inv_adopted' => true,
			);
		}

		if ( is_wp_error( $found ) ) {
			return self::unknown_error( $found );
		}

		self::clear( $order, $scope );
		return null;
	}

	/**
	 * Since when the outcome of a request for this order and scope is
	 * unknown, or null.
	 *
	 * @param WC_Order|null $order Order.
	 * @param string        $scope Marker scope.
	 * @return int|null
	 */
	public static function pending_since( $order, $scope ) {
		if ( ! $order instanceof WC_Order || '' === $scope ) {
			return null;
		}

		$since = (int) $order->get_meta( self::META_PREFIX . $scope );

		return $since > 0 ? $since : null;
	}

	/**
	 * The one recently created document of a type for a contact.
	 *
	 * @param Nota_Inv_Api_Client $client     API client.
	 * @param string              $type       voucherlist voucherType: invoice, creditnote, deliverynote.
	 * @param string              $contact_id Lexware contact id.
	 * @param int                 $since      Unix time the first request was sent.
	 * @param float|null          $amount     Expected total (gross), or null to not compare amounts.
	 * @param string[]            $exclude    Ids that are already known and must not count.
	 * @return string|null|WP_Error Id when exactly one matches, null when none, WP_Error when it cannot be told.
	 */
	public static function find_one( Nota_Inv_Api_Client $client, $type, $contact_id, $since, $amount = null, array $exclude = array() ) {
		if ( '' === (string) $contact_id ) {
			return new WP_Error( self::ERROR_CODE, 'no contact to search by' );
		}

		$from = new DateTime( '@' . ( (int) $since - self::CLOCK_TOLERANCE ) );
		$from->setTimezone( new DateTimeZone( 'Europe/Berlin' ) );

		$list = $client->get(
			'/v1/voucherlist',
			array(
				'voucherType'     => $type,
				'voucherStatus'   => 'any',
				'contactId'       => (string) $contact_id,
				'createdDateFrom' => $from->format( 'Y-m-d' ),
				'sort'            => 'createdDate,DESC',
				'size'            => 50,
			)
		);

		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$content = isset( $list['content'] ) && is_array( $list['content'] ) ? $list['content'] : array();
		$matches = array();

		foreach ( $content as $voucher ) {
			if ( ! is_array( $voucher ) || empty( $voucher['id'] ) || in_array( (string) $voucher['id'], $exclude, true ) ) {
				continue;
			}

			$created = isset( $voucher['createdDate'] ) ? strtotime( (string) $voucher['createdDate'] ) : false;

			if ( false === $created || $created < (int) $since - self::CLOCK_TOLERANCE ) {
				continue;
			}

			if ( null !== $amount && ! self::same_amount( isset( $voucher['totalAmount'] ) ? $voucher['totalAmount'] : null, $amount ) ) {
				continue;
			}

			$matches[] = (string) $voucher['id'];
		}

		if ( 1 === count( $matches ) ) {
			return $matches[0];
		}

		if ( count( $matches ) > 1 ) {
			return new WP_Error( self::ERROR_CODE, 'more than one matching document' );
		}

		return null;
	}

	/**
	 * Gross total a sales voucher payload adds up to, from its line items
	 * and its total discount (Lexware rounds per document, so compare with
	 * same_amount()). Null when the payload has no priced lines (e.g. a
	 * delivery note). A total discount is taken off proportionally across
	 * the lines, as Lexware applies it to the whole document (Help Center
	 * 547984); an absolute one is net unless the prices are gross.
	 *
	 * @param array $payload Payload.
	 * @return float|null
	 */
	public static function expected_gross( array $payload ) {
		$gross_prices = isset( $payload['taxConditions']['taxType'] ) && 'gross' === $payload['taxConditions']['taxType'];
		$total        = 0.0;
		$net_total    = 0.0;
		$priced       = false;

		foreach ( isset( $payload['lineItems'] ) && is_array( $payload['lineItems'] ) ? $payload['lineItems'] : array() as $line ) {
			if ( ! is_array( $line ) || empty( $line['unitPrice'] ) || ! is_array( $line['unitPrice'] ) ) {
				continue;
			}

			$quantity = isset( $line['quantity'] ) ? (float) $line['quantity'] : 0.0;
			$price    = $line['unitPrice'];
			$rate     = isset( $price['taxRatePercentage'] ) ? (float) $price['taxRatePercentage'] : 0.0;

			if ( $gross_prices && isset( $price['grossAmount'] ) ) {
				$unit = (float) $price['grossAmount'];
			} elseif ( isset( $price['netAmount'] ) ) {
				$unit = (float) $price['netAmount'] * ( 1 + $rate / 100 );
			} else {
				continue;
			}

			$discount   = isset( $line['discountPercentage'] ) ? (float) $line['discountPercentage'] : 0.0;
			$line_gross = $quantity * $unit * ( 1 - $discount / 100 );
			$total     += $line_gross;
			$net_total += $line_gross / ( 1 + $rate / 100 );
			$priced     = true;
		}

		if ( ! $priced ) {
			return null;
		}

		$totals     = isset( $payload['totalPrice'] ) && is_array( $payload['totalPrice'] ) ? $payload['totalPrice'] : array();
		$percentage = isset( $totals['totalDiscountPercentage'] ) ? (float) $totals['totalDiscountPercentage'] : 0.0;
		$absolute   = isset( $totals['totalDiscountAbsolute'] ) ? (float) $totals['totalDiscountAbsolute'] : 0.0;

		if ( $percentage > 0 ) {
			$total     *= 1 - $percentage / 100;
			$net_total *= 1 - $percentage / 100;
		}

		if ( $absolute > 0 ) {
			if ( $gross_prices ) {
				$total -= $absolute;
			} elseif ( $net_total > 0 ) {
				$total *= max( 0.0, 1 - $absolute / $net_total );
			}
		}

		return round( max( 0.0, $total ), 2 );
	}

	/**
	 * Amounts equal within rounding: 10 cents or 1 %, whichever is larger.
	 *
	 * @param mixed $actual   Lexware total.
	 * @param float $expected Expected total.
	 * @return bool
	 */
	private static function same_amount( $actual, $expected ) {
		if ( ! is_numeric( $actual ) ) {
			return false;
		}

		return abs( (float) $actual - (float) $expected ) <= max( 0.10, abs( (float) $expected ) * 0.01 );
	}

	/**
	 * @param WP_Error|null $cause Why it could not be told, for the log.
	 * @return WP_Error
	 */
	private static function unknown_error( $cause = null ) {
		if ( $cause instanceof WP_Error ) {
			Nota_Inv_Logger::error( 'Could not tell whether the document was created: ' . $cause->get_error_message() );
		}

		return new WP_Error(
			self::ERROR_CODE,
			__( 'Lexware Office did not answer in time, so it is not certain whether the document was created. Nothing was sent again automatically — please check in Lexware Office before trying again.', 'nota-invoice-sync' )
		);
	}

	/**
	 * @param WC_Order|null $order Order.
	 * @param string        $scope Marker scope.
	 * @param int           $since Unix time.
	 * @return void
	 */
	private static function mark( $order, $scope, $since ) {
		if ( ! $order instanceof WC_Order || '' === $scope || null !== self::pending_since( $order, $scope ) ) {
			return;
		}

		$order->update_meta_data( self::META_PREFIX . $scope, (int) $since );
		$order->save();
	}

	/**
	 * @param WC_Order|null $order Order.
	 * @param string        $scope Marker scope.
	 * @return void
	 */
	public static function clear( $order, $scope ) {
		if ( ! $order instanceof WC_Order || '' === $scope || null === self::pending_since( $order, $scope ) ) {
			return;
		}

		$order->delete_meta_data( self::META_PREFIX . $scope );
		$order->save();
	}
}
