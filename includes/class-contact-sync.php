<?php
/**
 * Finds or creates the Lexware contact for an order.
 *
 * Invoices always reference a real contact rather than a one-time address,
 * because the vat-free tax types (intra-community supply, third country
 * delivery) are only accepted with a referenced contact.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Contact_Sync {

	/**
	 * @var Nota_Inv_Api_Client
	 */
	private $client;

	/**
	 * @param Nota_Inv_Api_Client $client API client.
	 */
	public function __construct( Nota_Inv_Api_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Return a Lexware contact id for the order, creating one if needed.
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error Contact UUID.
	 */
	public function get_contact_id( WC_Order $order ) {
		$stored = $order->get_meta( NOTA_INV_META_CONTACT_ID );

		if ( is_string( $stored ) && '' !== $stored ) {
			Nota_Inv_Logger::debug( 'Reusing stored contact id for order ' . $order->get_id() );
			$this->sync_if_changed( $stored, $order );
			return $stored;
		}

		$email = trim( (string) $order->get_billing_email() );

		if ( '' !== $email && strlen( $email ) >= 3 ) {
			$found = $this->find_by_email( $email );

			if ( is_wp_error( $found ) ) {
				return $found;
			}

			if ( '' !== $found ) {
				$order->update_meta_data( NOTA_INV_META_CONTACT_ID, $found );
				$order->save();
				$this->sync_if_changed( $found, $order );
				return $found;
			}
		}

		return $this->create( $order );
	}

	/**
	 * Keep an existing Lexware contact in sync with the order's current
	 * billing details.
	 *
	 * Lexware uses optimistic locking (a "version" field on every updatable
	 * resource): the current version must be fetched first and sent back
	 * with the update, or the API answers 409 Conflict. This does a
	 * best-effort GET + compare + PUT — a failure here is logged but never
	 * blocks invoice creation, since the contact id is already known either
	 * way.
	 *
	 * @param string   $contact_id Lexware contact UUID.
	 * @param WC_Order $order      Order.
	 * @return void
	 */
	private function sync_if_changed( $contact_id, WC_Order $order ) {
		$current = $this->client->get( '/v1/contacts/' . rawurlencode( $contact_id ) );

		if ( is_wp_error( $current ) ) {
			Nota_Inv_Logger::debug(
				sprintf( 'Could not fetch contact %s to check for changes: %s', $contact_id, $current->get_error_message() )
			);
			return;
		}

		$desired = $this->build_payload( $order );

		if ( ! $this->differs( $current, $desired ) ) {
			return;
		}

		// Optimistic locking: send the version we just read, not our own.
		$desired['version'] = isset( $current['version'] ) ? (int) $current['version'] : 0;

		// A contact can hold both a "company" and a "person" role in Lexware;
		// only replace the parts our payload actually manages, so we never
		// wipe out fields (like a manually added second address) that this
		// plugin does not know about.
		$update = $current;
		foreach ( array( 'company', 'person', 'addresses', 'emailAddresses', 'phoneNumbers' ) as $key ) {
			if ( isset( $desired[ $key ] ) ) {
				$update[ $key ] = $desired[ $key ];
			}
		}
		$update['version'] = $desired['version'];

		$result = $this->client->put( '/v1/contacts/' . rawurlencode( $contact_id ), $update );

		if ( is_wp_error( $result ) ) {
			Nota_Inv_Logger::error(
				sprintf( 'Could not update contact %s for order %d: %s', $contact_id, $order->get_id(), $result->get_error_message() )
			);
			return;
		}

		Nota_Inv_Logger::debug( sprintf( 'Updated contact %s from order %d (billing details had changed).', $contact_id, $order->get_id() ) );
	}

	/**
	 * Compare the name/address/contact fields that matter for invoicing.
	 *
	 * Deliberately narrow: only the fields this plugin itself writes are
	 * compared, so unrelated Lexware-side edits (tags, notes, custom fields)
	 * never trigger a write.
	 *
	 * @param array $current Contact as currently stored in Lexware.
	 * @param array $desired Freshly built payload from the order.
	 * @return bool
	 */
	private function differs( array $current, array $desired ) {
		$fields = array( 'company', 'person', 'addresses' );

		foreach ( $fields as $field ) {
			$a = isset( $current[ $field ] ) ? $current[ $field ] : null;
			$b = isset( $desired[ $field ] ) ? $desired[ $field ] : null;

			if ( $this->normalise( $a ) !== $this->normalise( $b ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Canonical form for a loose comparison (ignores key order and Lexware's
	 * own read-only additions like contact-person ids).
	 *
	 * @param mixed $value Value to normalise.
	 * @return string
	 */
	private function normalise( $value ) {
		if ( is_array( $value ) ) {
			unset( $value['id'], $value['contactId'] );
			foreach ( $value as $k => $v ) {
				$value[ $k ] = is_array( $v ) ? $this->normalise( $v ) : $v;
			}
			ksort( $value );
		}

		return wp_json_encode( $value );
	}

	/**
	 * Search for an existing customer contact by email address.
	 *
	 * The email filter does substring matching, so the result is verified
	 * against the exact address before it is accepted.
	 *
	 * @param string $email Email address.
	 * @return string|WP_Error Contact UUID, or empty string when not found.
	 */
	private function find_by_email( $email ) {
		$response = $this->client->get(
			'/v1/contacts',
			array(
				'email'    => $email,
				'customer' => 'true',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['content'] ) || ! is_array( $response['content'] ) ) {
			return '';
		}

		$needle = strtolower( $email );

		foreach ( $response['content'] as $contact ) {
			if ( empty( $contact['id'] ) ) {
				continue;
			}

			if ( ! empty( $contact['archived'] ) ) {
				continue;
			}

			foreach ( $this->collect_emails( $contact ) as $candidate ) {
				if ( strtolower( $candidate ) === $needle ) {
					return (string) $contact['id'];
				}
			}
		}

		return '';
	}

	/**
	 * Every email address attached to a contact record.
	 *
	 * @param array $contact Contact payload.
	 * @return string[]
	 */
	private function collect_emails( array $contact ) {
		$emails = array();

		if ( ! empty( $contact['emailAddresses'] ) && is_array( $contact['emailAddresses'] ) ) {
			foreach ( $contact['emailAddresses'] as $list ) {
				if ( is_array( $list ) ) {
					$emails = array_merge( $emails, $list );
				}
			}
		}

		if ( ! empty( $contact['company']['contactPersons'] ) && is_array( $contact['company']['contactPersons'] ) ) {
			foreach ( $contact['company']['contactPersons'] as $person ) {
				if ( ! empty( $person['emailAddress'] ) ) {
					$emails[] = $person['emailAddress'];
				}
			}
		}

		return array_filter( array_map( 'strval', $emails ) );
	}

	/**
	 * Create a new customer contact from the order's billing details.
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error Contact UUID.
	 */
	private function create( WC_Order $order ) {
		$payload = $this->build_payload( $order );

		Nota_Inv_Logger::debug( 'Creating Lexware contact for order ' . $order->get_id(), array( 'payload' => $payload ) );

		$response = $this->client->post( '/v1/contacts', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['id'] ) ) {
			return new WP_Error(
				'nota_inv_contact_no_id',
				__( 'Lexware Office did not return an id for the new contact.', 'nota-invoice-sync' )
			);
		}

		$id = (string) $response['id'];

		$order->update_meta_data( NOTA_INV_META_CONTACT_ID, $id );
		$order->save();

		return $id;
	}

	/**
	 * Assemble the contact payload.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function build_payload( WC_Order $order ) {
		$company    = trim( (string) $order->get_billing_company() );
		$first      = trim( (string) $order->get_billing_first_name() );
		$last       = trim( (string) $order->get_billing_last_name() );
		$email      = trim( (string) $order->get_billing_email() );
		$phone      = trim( (string) $order->get_billing_phone() );

		$payload = array(
			'version' => 0,
			'roles'   => array( 'customer' => new stdClass() ),
		);

		if ( '' !== $company ) {
			$person = array(
				'lastName' => '' !== $last ? $last : $first,
			);

			if ( '' !== $first && '' !== $last ) {
				$person['firstName'] = $first;
			}

			$person['primary'] = true;

			if ( '' !== $email ) {
				$person['emailAddress'] = $email;
			}
			if ( '' !== $phone ) {
				$person['phoneNumber'] = $phone;
			}

			$payload['company'] = array(
				'name' => $company,
			);

			// A contact person is only useful when we actually have a name.
			if ( '' !== $person['lastName'] ) {
				$payload['company']['contactPersons'] = array( $person );
			}

			$tax = new Nota_Inv_Tax_Resolver();
			$vat = $tax->get_vat_id( $order );

			if ( '' !== $vat ) {
				$payload['company']['vatRegistrationId'] = $vat;
			}

			// allowTaxFreeInvoices is a persistent flag on the contact, not
			// just this one order — only set it when the order genuinely
			// needs a zero-tax invoice type that Lexware requires the
			// contact to be flagged for. A domestic B2B customer who has a
			// VAT-ID on file but was still charged normal VAT on this order
			// must not have their contact flagged as tax-free.
			//
			// 30.08.2026 live finding (werbeduft.com): a Swiss (third-
			// country, non-EU) customer's invoice was rejected by Lexware —
			// "Invalid combination of tax type thirdPartyCountryDelivery
			// ... and contact id ..." — because this flag was only ever set
			// for the EU reverse-charge case above, gated behind a VAT-ID
			// being present. A non-EU customer has no EU VAT-ID at all, so
			// that condition never ran for them, and the zero-rated export
			// tax type Tax_Resolver::resolve() correctly picked was
			// rejected by Lexware without it. Extended to also cover
			// third-country exports — deliberately NOT widened to the
			// small-business (§19 UStG) zero-rated case too, since that
			// path has never been live-tested and this fix's scope is the
			// confirmed bug only.
			if ( $tax->is_reverse_charge( $order ) || $tax->is_third_country_export( $order ) ) {
				$payload['company']['allowTaxFreeInvoices'] = true;
			}
		} else {
			$payload['person'] = array(
				'lastName' => '' !== $last ? $last : ( '' !== $first ? $first : __( 'Customer', 'nota-invoice-sync' ) ),
			);

			if ( '' !== $first && '' !== $last ) {
				$payload['person']['firstName'] = $first;
			}
		}

		$address = $this->build_address( $order );

		if ( ! empty( $address ) ) {
			$payload['addresses'] = array( 'billing' => array( $address ) );
		}

		if ( '' !== $email ) {
			$payload['emailAddresses'] = array( 'business' => array( $email ) );
		}

		if ( '' !== $phone ) {
			$payload['phoneNumbers'] = array( 'business' => array( $phone ) );
		}

		$payload['note'] = sprintf(
			/* translators: %s: order number. */
			__( 'Created automatically from WooCommerce order %s.', 'nota-invoice-sync' ),
			$order->get_order_number()
		);

		return $payload;
	}

	/**
	 * Billing address fragment.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function build_address( WC_Order $order ) {
		$country = strtoupper( trim( (string) $order->get_billing_country() ) );

		if ( '' === $country ) {
			return array();
		}

		$street = trim( (string) $order->get_billing_address_1() );
		$extra  = trim( (string) $order->get_billing_address_2() );

		// Address line 2 is appended to the street rather than sent as
		// Lexware's separate "supplement" field. The supplement field is
		// printed between the company name and the contact person on the
		// invoice, which looks wrong when the line 2 content is really a
		// delivery note ("Glastür im Hof") rather than a genuine address
		// component (floor, unit number). Appending it to the street keeps
		// it in its natural reading position and avoids that placement
		// entirely, at the cost of not being a distinct structured field.
		if ( '' !== $street && '' !== $extra ) {
			$street = $street . ', ' . $extra;
		} elseif ( '' !== $extra ) {
			$street = $extra;
		}

		$address = array( 'countryCode' => $country );

		if ( '' !== $street ) {
			$address['street'] = $street;
		}
		if ( '' !== trim( (string) $order->get_billing_postcode() ) ) {
			$address['zip'] = trim( (string) $order->get_billing_postcode() );
		}
		if ( '' !== trim( (string) $order->get_billing_city() ) ) {
			$address['city'] = trim( (string) $order->get_billing_city() );
		}

		return $address;
	}
}
