<?php
/**
 * Works out which Lexware tax treatment applies to a WooCommerce order.
 *
 * All the rules live here so they can be reasoned about — and corrected — in
 * one place rather than being scattered through the payload builder.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Tax_Resolver {

	/**
	 * Rates Lexware accepts on a voucher that is not flagged as a distance
	 * sale — domestic orders, and EU B2C orders when the organisation's
	 * distance sales principle is ORIGIN.
	 */
	const DOMESTIC_RATES = array( 0.0, 7.0, 19.0 );

	/**
	 * EU member states (ISO 3166 alpha-2).
	 */
	const EU_COUNTRIES = array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
		'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
		'SI', 'ES', 'SE',
	);

	/**
	 * Organisation profile as returned by /v1/profile, if available.
	 *
	 * @var array
	 */
	private $profile;

	/**
	 * @param array $profile Profile payload (may be empty).
	 */
	public function __construct( array $profile = array() ) {
		$this->profile = $profile;
	}

	/**
	 * Resolve the tax treatment for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array {
	 *     @type array  $tax_conditions Payload fragment for taxConditions.
	 *     @type bool   $zero_rated     True when every line must carry 0%.
	 *     @type string $reason         Human-readable explanation for logs.
	 * }
	 */
	public function resolve( WC_Order $order ) {
		$country = strtoupper( $order->get_billing_country() );
		$base    = strtoupper( WC()->countries ? WC()->countries->get_base_country() : 'DE' );

		// Organisations flagged as small business never charge VAT.
		if ( ! empty( $this->profile['smallBusiness'] ) ) {
			return array(
				'tax_conditions' => array( 'taxType' => 'vatfree' ),
				'zero_rated'     => true,
				'reason'         => 'Organisation is a small business (§19 UStG).',
			);
		}

		// Domestic.
		if ( $country === $base ) {
			return array(
				'tax_conditions' => array( 'taxType' => 'net' ),
				'zero_rated'     => false,
				'reason'         => 'Domestic order.',
			);
		}

		$in_eu = in_array( $country, self::EU_COUNTRIES, true );

		// Outside the EU — export delivery, no VAT.
		if ( ! $in_eu ) {
			return array(
				'tax_conditions' => array( 'taxType' => 'thirdPartyCountryDelivery' ),
				'zero_rated'     => true,
				'reason'         => sprintf( 'Export to third country %s.', $country ),
			);
		}

		// EU B2B with reverse charge: WooCommerce already zero-rated the order
		// and a VAT ID is on file. We trust that decision rather than
		// re-validating against VIES.
		if ( $this->is_reverse_charge( $order ) ) {
			return array(
				'tax_conditions' => array( 'taxType' => 'intraCommunitySupply' ),
				'zero_rated'     => true,
				'reason'         => sprintf( 'Intra-community supply to %s (reverse charge).', $country ),
			);
		}

		// EU B2C. Whether the destination rate may be used depends on the
		// organisation's distance sales principle in Lexware.
		if ( 'DESTINATION' === $this->distance_sales_principle() ) {
			$digital = $this->is_digital_only( $order );

			return array(
				'tax_conditions' => array(
					'taxType'    => 'net',
					// Electronically supplied services (downloads, SaaS, etc.)
					// have used destination-country VAT since 2015 (the old
					// MOSS scheme, folded into OSS in 2021) — there was never
					// an "origin" option for them the way there historically
					// was for goods, so this does not additionally check
					// distance_sales_principle(). What is NOT independently
					// confirmed against Lexware's own docs or a live test: an
					// order for a digital service on an account that is
					// otherwise ORIGIN never reaches this branch at all (it
					// falls through to the ORIGIN-only-domestic-rates branch
					// below), which may or may not match how Lexware itself
					// expects electronicServices vouchers to be produced.
					'taxSubType' => $digital ? 'electronicServices' : 'distanceSales',
				),
				'zero_rated'     => false,
				'reason'         => sprintf(
					$digital
						? 'EU sale of digital services to %s, destination VAT (OSS).'
						: 'EU distance sale to %s, destination VAT (OSS).',
					$country
				),
			);
		}

		// Lexware is on ORIGIN: only domestic rates are acceptable. If the shop
		// charged a foreign rate anyway, the caller needs to know it will fail.
		return array(
			'tax_conditions' => array( 'taxType' => 'net' ),
			'zero_rated'     => false,
			'reason'         => sprintf(
				'EU order to %s billed under German VAT (Lexware distance sales principle is ORIGIN).',
				$country
			),
		);
	}

	/**
	 * Does this order look like an intra-community reverse-charge supply?
	 *
	 * Requires both a VAT ID on the order and a zero tax total — that
	 * combination is what any of the common EU VAT plugins produce.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function is_reverse_charge( WC_Order $order ) {
		if ( '' === $this->get_vat_id( $order ) ) {
			return false;
		}

		if ( (float) $order->get_total_tax() > 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Read the customer's VAT ID from any of the meta keys commonly used by
	 * EU VAT plugins.
	 *
	 * The list of keys itself lives in the `vat_id_meta_keys` setting (comma-
	 * separated, editable on the settings page) rather than being hard-coded
	 * here, so a site can add its own VAT plugin's field name without a code
	 * snippet — the `nota_inv_vat_id_meta_keys` filter still runs afterwards
	 * for anyone who prefers that route.
	 *
	 * @param WC_Order $order Order.
	 * @return string Empty string when absent.
	 */
	public function get_vat_id( WC_Order $order ) {
		$configured = (string) Nota_Inv_Settings::instance()->get( 'vat_id_meta_keys' );

		$keys = array();
		foreach ( explode( ',', $configured ) as $key ) {
			$key = trim( $key );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		/**
		 * Filter the meta keys searched for a customer VAT ID.
		 *
		 * @param string[] $keys  Meta keys.
		 * @param WC_Order $order Order.
		 */
		$keys = apply_filters( 'nota_inv_vat_id_meta_keys', $keys, $order );

		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}

			$normalised = strtoupper( preg_replace( '/\s+/', '', trim( $value ) ) );

			// A real VAT-ID always starts with a two-letter country code
			// (DE, AT, FR, ...). Some checkout fields get filled with
			// unrelated data by mistake (a phone number, a customer number)
			// — sending that to Lexware as-is fails the whole invoice with
			// a confusing "country code must be uppercase" error, when the
			// real problem is that there is no country code at all. Treat
			// anything that doesn't even look like a VAT-ID as absent,
			// same as if the field had been empty, and keep checking the
			// remaining configured meta keys.
			if ( ! preg_match( '/^[A-Z]{2}[A-Z0-9]+$/', $normalised ) ) {
				Nota_Inv_Logger::debug(
					sprintf( 'Ignoring value in VAT-ID field "%s" for order %d — does not look like a VAT-ID (no two-letter country code): %s', $key, $order->get_id(), $normalised )
				);
				continue;
			}

			return $normalised;
		}

		return '';
	}

	/**
	 * Does this order's billing country fall outside the EU (and outside
	 * the shop's own base country)? Mirrors the exact domestic/EU check
	 * resolve() uses for its thirdPartyCountryDelivery branch, exposed
	 * separately so callers that need just this verdict (contact sync's
	 * allowTaxFreeInvoices flag — see class-contact-sync.php) don't have
	 * to re-derive it from resolve()'s full return shape.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public function is_third_country_export( WC_Order $order ) {
		$country = strtoupper( $order->get_billing_country() );
		$base    = strtoupper( WC()->countries ? WC()->countries->get_base_country() : 'DE' );

		if ( $country === $base ) {
			return false;
		}

		return ! in_array( $country, self::EU_COUNTRIES, true );
	}

	/**
	 * ORIGIN or DESTINATION, per the Lexware organisation profile.
	 *
	 * @return string
	 */
	public function distance_sales_principle() {
		return isset( $this->profile['distanceSalesPrinciple'] )
			? (string) $this->profile['distanceSalesPrinciple']
			: '';
	}

	/**
	 * Whether this resolution may only use a rate from self::DOMESTIC_RATES.
	 *
	 * False for zero-rated vouchers (reverse charge, export, small business —
	 * the rate is always 0 regardless) and for distance sales under the
	 * DESTINATION principle, where a foreign rate is exactly what Lexware
	 * expects. True for domestic orders and for EU B2C orders billed under
	 * German VAT because the organisation is on ORIGIN.
	 *
	 * @param array $resolution Result of resolve().
	 * @return bool
	 */
	public function requires_domestic_rate( array $resolution ) {
		if ( ! empty( $resolution['zero_rated'] ) ) {
			return false;
		}

		$sub_type = isset( $resolution['tax_conditions']['taxSubType'] ) ? $resolution['tax_conditions']['taxSubType'] : '';

		return ! in_array( $sub_type, array( 'distanceSales', 'electronicServices' ), true );
	}

	/**
	 * Whether every product line on the order is virtual and downloadable —
	 * WooCommerce's own proxy for "nothing physical changes hands", which is
	 * as close as this plugin can get to the EU VAT definition of an
	 * "electronically supplied service" (automated delivery, minimal human
	 * involvement) without inventing a judgement WooCommerce itself does not
	 * expose. Only "virtual" is not used on its own because that also covers
	 * in-person services (e.g. a booked consultation) that are not
	 * electronically supplied. An order with no product line items at all
	 * (e.g. a fee-only order) is not a digital sale of anything.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function is_digital_only( WC_Order $order ) {
		$has_line_item = false;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item instanceof WC_Order_Item_Product ? $item->get_product() : false;

			if ( ! $product instanceof WC_Product || ! $product->is_virtual() || ! $product->is_downloadable() ) {
				return false;
			}

			$has_line_item = true;
		}

		return $has_line_item;
	}

	/**
	 * Tax rate to send for a single order line.
	 *
	 * Lexware only accepts 0, 7 and 19 for domestic vouchers; destination
	 * rates are permitted when the voucher is flagged as a distance sale.
	 *
	 * @param float $line_total Net line total.
	 * @param float $line_tax   Tax on that line.
	 * @param array $resolution Result of resolve().
	 * @return float
	 */
	public function rate_for_line( $line_total, $line_tax, array $resolution ) {
		if ( ! empty( $resolution['zero_rated'] ) ) {
			return 0.0;
		}

		$line_total = (float) $line_total;
		$line_tax   = (float) $line_tax;

		if ( $line_total <= 0 ) {
			return 0.0;
		}

		$raw = ( $line_tax / $line_total ) * 100;

		// Snap to the nearest rate the shop actually has configured, so that
		// per-line rounding noise never produces something like 18.97.
		$candidates = $this->known_rates();
		$best       = 0.0;
		$distance   = PHP_INT_MAX;

		foreach ( $candidates as $candidate ) {
			$delta = abs( $candidate - $raw );
			if ( $delta < $distance ) {
				$distance = $delta;
				$best     = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Every tax rate configured in the shop, plus the German defaults.
	 *
	 * @return float[]
	 */
	private function known_rates() {
		static $rates = null;

		if ( null !== $rates ) {
			return $rates;
		}

		// Cached across requests too (short-lived): the configured tax rates
		// rarely change, and there is no WooCommerce API for "every distinct
		// rate configured", only per-country/class lookups.
		$cache_key = 'nota_inv_known_tax_rates';
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			$rates = $cached;
			return $rates;
		}

		global $wpdb;

		$rates = array( 0.0, 7.0, 19.0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no WooCommerce API exposes distinct configured tax rates; cached via transient above/below.
		$found = $wpdb->get_col( "SELECT DISTINCT tax_rate FROM {$wpdb->prefix}woocommerce_tax_rates" );

		if ( is_array( $found ) ) {
			foreach ( $found as $rate ) {
				$rates[] = round( (float) $rate, 2 );
			}
		}

		$rates = array_values( array_unique( $rates ) );
		sort( $rates );

		set_transient( $cache_key, $rates, HOUR_IN_SECONDS );

		return $rates;
	}
}
