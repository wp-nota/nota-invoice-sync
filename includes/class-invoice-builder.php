<?php
/**
 * Turns a WooCommerce order into a Lexware invoice payload.
 *
 * Note what is deliberately absent: any tax arithmetic. Only net amounts and
 * rates are sent; Lexware computes every total itself. That is what removes
 * the whole class of per-line rounding disputes.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Invoice_Builder {

	/**
	 * Lexware rejects vouchers with more than 300 line items.
	 */
	const MAX_LINE_ITEMS = 300;

	/**
	 * @var Nota_Inv_Tax_Resolver
	 */
	private $tax;

	/**
	 * @param Nota_Inv_Tax_Resolver $tax Tax resolver.
	 */
	public function __construct( Nota_Inv_Tax_Resolver $tax ) {
		$this->tax = $tax;
	}

	/**
	 * Build the payload.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $contact_id Lexware contact UUID.
	 * @return array|WP_Error
	 */
	public function build( WC_Order $order, $contact_id ) {
		$settings = Nota_Inv_Settings::instance();

		// EUR is hard-coded throughout this builder because that is what
		// every verified Lexware Office invoice in this project has used.
		// Whether the API accepts other currencies at all was never tested,
		// so rather than silently sending, say, 49.90 USD labelled as EUR —
		// a real accounting error — a non-EUR order is refused outright.
		$currency = $order->get_currency();
		if ( 'EUR' !== $currency ) {
			return new WP_Error(
				'nota_inv_unsupported_currency',
				sprintf(
					/* translators: %s: order currency code. */
					__( 'This order is in %s. Nota Invoice Sync currently only supports EUR orders.', 'nota-invoice-sync' ),
					$currency
				)
			);
		}

		$resolution = $this->tax->resolve( $order );

		Nota_Inv_Logger::debug(
			sprintf( 'Tax treatment for order %d: %s', $order->get_id(), $resolution['reason'] )
		);

		$line_items = $this->build_line_items( $order, $resolution );

		if ( is_wp_error( $line_items ) ) {
			return $line_items;
		}

		if ( empty( $line_items ) ) {
			return new WP_Error(
				'nota_inv_no_line_items',
				__( 'The order has nothing that can be put on an invoice.', 'nota-invoice-sync' )
			);
		}

		$payload = array(
			'voucherDate'        => $this->voucher_date(),
			'address'            => array( 'contactId' => $contact_id ),
			'lineItems'          => $line_items,
			'totalPrice'         => array( 'currency' => 'EUR' ),
			'taxConditions'      => $resolution['tax_conditions'],
			'shippingConditions' => $this->shipping_conditions( $order ),
			'language'           => $this->resolve_language( $order ),
		);

		$discount = $this->discount_total( $order );
		if ( $discount > 0 ) {
			$payload['totalPrice']['totalDiscountAbsolute'] = $discount;
		}

		// Lexware Office fills this field in with a contact/organisation
		// default whenever it is left out of the payload entirely — that
		// default can be any payment-term text on file, including ones that
		// contradict an order already paid via PayPal or another instant
		// gateway (which carries its own "paid via ..." closing note, see
		// payment_note() below). Sending an explicit value, in both
		// branches, is the only way to stop Lexware substituting its own.
		if ( $order->needs_payment() ) {
			$term_days = $settings->get( 'payment_term_days' );
			if ( '' !== $term_days ) {
				$payload['paymentConditions'] = array(
					'paymentTermLabel'    => $this->payment_term_label( (int) $term_days, $order ),
					'paymentTermDuration' => (int) $term_days,
				);
			}
		} else {
			$payload['paymentConditions'] = array(
				'paymentTermLabel'    => $this->paid_condition_label( $order ),
				'paymentTermDuration' => 0,
			);
		}

		foreach ( array( 'invoice_title' => 'title', 'invoice_introduction' => 'introduction', 'invoice_remark' => 'remark' ) as $option => $field ) {
			$value = trim( (string) $settings->get( $option ) );
			if ( '' !== $value ) {
				$payload[ $field ] = $this->replace_placeholders( $value, $order );
			}
		}

		// When no custom text was configured, fall back to sensible defaults that
		// carry the order reference and the payment method — both are routinely
		// needed for reconciliation.
		if ( empty( $payload['introduction'] ) ) {
			$payload['introduction'] = $this->default_introduction( $order );
		}

		if ( empty( $payload['remark'] ) ) {
			$remark = $this->payment_note( $order );
			if ( '' !== $remark ) {
				$payload['remark'] = $remark;
			}
		}

		/**
		 * Filter the invoice payload immediately before it is sent.
		 *
		 * @param array    $payload    Invoice payload.
		 * @param WC_Order $order      Order.
		 * @param array    $resolution Tax resolution.
		 */
		return apply_filters( 'nota_inv_invoice_payload', $payload, $order, $resolution );
	}

	/**
	 * Build every line item: products, fees and shipping.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $resolution Tax resolution.
	 * @return array|WP_Error
	 */
	private function build_line_items( WC_Order $order, array $resolution ) {
		$items = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$quantity = (float) $item->get_quantity();

			if ( $quantity <= 0 ) {
				continue;
			}

			$net      = (float) $item->get_total();
			$tax      = (float) $item->get_total_tax();
			$rate     = $this->resolve_rate_or_error( $net, $tax, $resolution, $order );
			$unit_net = $net / $quantity;

			if ( is_wp_error( $rate ) ) {
				return $rate;
			}

			$line = array(
				'type'      => 'custom',
				'name'      => $this->clean( $item->get_name(), 255 ),
				'quantity'  => $quantity,
				'unitName'  => __( 'Stück', 'nota-invoice-sync' ),
				'unitPrice' => array(
					'currency'          => 'EUR',
					'netAmount'         => round( $unit_net, 4 ),
					'taxRatePercentage' => $rate,
				),
			);

			$description = $this->item_description( $item );
			if ( '' !== $description ) {
				$line['description'] = $description;
			}

			$items[] = $line;
		}

		foreach ( $order->get_items( 'fee' ) as $fee ) {
			$net = (float) $fee->get_total();

			if ( 0.0 === $net ) {
				continue;
			}

			$tax  = (float) $fee->get_total_tax();
			$rate = $this->resolve_rate_or_error( $net, $tax, $resolution, $order );

			if ( is_wp_error( $rate ) ) {
				return $rate;
			}

			$items[] = array(
				'type'      => 'custom',
				'name'      => $this->clean( $fee->get_name(), 255 ),
				'quantity'  => 1,
				'unitName'  => __( 'Stück', 'nota-invoice-sync' ),
				'unitPrice' => array(
					'currency'          => 'EUR',
					'netAmount'         => round( $net, 4 ),
					'taxRatePercentage' => $rate,
				),
			);
		}

		if ( Nota_Inv_Settings::instance()->is( 'shipping_as_line' ) ) {
			$shipping_items = $order->get_items( 'shipping' );

			// Show the line whenever a shipping method was actually chosen,
			// even at 0,00 € (free shipping) — a visible "Kostenlose
			// Lieferung" line reads as an intentional, itemised offer rather
			// than a silently omitted cost. Orders with no shipping item at
			// all (e.g. local pickup with no method) get no line, same as
			// before.
			if ( ! empty( $shipping_items ) ) {
				$shipping_net = (float) $order->get_shipping_total();
				$shipping_tax = (float) $order->get_shipping_tax();
				$label        = '';

				foreach ( $shipping_items as $shipping ) {
					$label = $shipping->get_name();
					break;
				}

				if ( '' === trim( (string) $label ) ) {
					$label = __( 'Shipping', 'nota-invoice-sync' );
				}

				$shipping_rate = $this->resolve_rate_or_error( $shipping_net, $shipping_tax, $resolution, $order );

				if ( is_wp_error( $shipping_rate ) ) {
					return $shipping_rate;
				}

				$items[] = array(
					'type'      => 'custom',
					'name'      => $this->clean( $label, 255 ),
					'quantity'  => 1,
					'unitName'  => __( 'Stück', 'nota-invoice-sync' ),
					'unitPrice' => array(
						'currency'          => 'EUR',
						'netAmount'         => round( $shipping_net, 4 ),
						'taxRatePercentage' => $shipping_rate,
					),
				);
			}
		}

		if ( count( $items ) > self::MAX_LINE_ITEMS ) {
			return new WP_Error(
				'nota_inv_too_many_line_items',
				sprintf(
					/* translators: 1: number of items, 2: maximum allowed. */
					__( 'This order has %1$d line items but Lexware Office accepts at most %2$d.', 'nota-invoice-sync' ),
					count( $items ),
					self::MAX_LINE_ITEMS
				)
			);
		}

		return $items;
	}

	/**
	 * Rate for a single line, refusing the whole invoice when the shop
	 * actually charged a foreign rate that Lexware will not accept.
	 *
	 * `Tax_Resolver::rate_for_line()` snaps to the nearest rate configured
	 * anywhere in the shop (including other countries), which is correct for
	 * absorbing per-line cent rounding on domestic orders — but on a voucher
	 * that requires a domestic rate (see `requires_domestic_rate()`), landing
	 * on a non-domestic rate means the order was genuinely taxed under a
	 * different country's rate (e.g. an EU B2C order taxed at the destination
	 * country's rate while the Lexware account is on the ORIGIN distance
	 * sales principle). Sending that rate would either be silently wrong
	 * (if it happened to be close to a German rate) or rejected by Lexware
	 * with an opaque 406 (if not) — so it is refused here instead, before any
	 * API call is made.
	 *
	 * @param float    $net        Net line total.
	 * @param float    $tax        Tax on that line.
	 * @param array    $resolution Result of Tax_Resolver::resolve().
	 * @param WC_Order $order      Order (for the error message only).
	 * @return float|WP_Error
	 */
	private function resolve_rate_or_error( $net, $tax, array $resolution, WC_Order $order ) {
		$rate = $this->tax->rate_for_line( $net, $tax, $resolution );

		if ( ! $this->tax->requires_domestic_rate( $resolution ) ) {
			return $rate;
		}

		if ( in_array( $rate, Nota_Inv_Tax_Resolver::DOMESTIC_RATES, true ) ) {
			return $rate;
		}

		return new WP_Error(
			'nota_inv_tax_rate_mismatch',
			sprintf(
				/* translators: 1: order billing country code, 2: detected tax rate percentage. */
				__( 'This order was taxed at %2$s%% (billing country %1$s), but the connected Lexware Office account only accepts German rates (0%%, 7%%, 19%%) here — its distance sales principle is set to origin, not destination. Switch it to destination in Lexware Office, or create this invoice manually.', 'nota-invoice-sync' ),
				strtoupper( $order->get_billing_country() ),
				rtrim( rtrim( number_format( $rate, 2 ), '0' ), '.' )
			)
		);
	}

	/**
	 * Variation attributes and item meta, rendered as the line description.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return string
	 */
	private function item_description( $item ) {
		$parts = array();

		if ( function_exists( 'wc_display_item_meta' ) ) {
			$meta = wc_display_item_meta(
				$item,
				array(
					'before'    => '',
					'after'     => '',
					'separator' => "\n",
					'echo'      => false,
					'autop'     => false,
				)
			);

			if ( is_string( $meta ) && '' !== trim( $meta ) ) {
				$parts[] = wp_strip_all_tags( $meta );
			}
		}

		$sku = '';
		if ( method_exists( $item, 'get_product' ) ) {
			$product = $item->get_product();
			if ( $product && $product->get_sku() ) {
				$sku = $product->get_sku();
			}
		}

		if ( '' !== $sku ) {
			$parts[] = sprintf(
				/* translators: %s: product SKU. */
				__( 'SKU: %s', 'nota-invoice-sync' ),
				$sku
			);
		}

		return $this->clean( implode( "\n", array_filter( $parts ) ), 1000 );
	}

	/**
	 * Total order-level discount, as a positive number.
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	private function discount_total( WC_Order $order ) {
		$discount = (float) $order->get_discount_total();
		return $discount > 0 ? round( $discount, 2 ) : 0.0;
	}

	/**
	 * Payment term line in the language of the DOCUMENT.
	 *
	 * Deliberately not run through __()/_n(): those translate into the
	 * WordPress admin locale, but this text ends up on the customer's
	 * invoice, whose language is the plugin's "language" setting. Mixing the
	 * two produced English payment terms on German invoices.
	 *
	 * @param int      $days  Payment term in days.
	 * @param WC_Order $order Order (used to resolve the language).
	 * @return string
	 */
	private function payment_term_label( $days, WC_Order $order ) {
		$days = (int) $days;

		if ( 'en' === $this->resolve_language( $order ) ) {
			return 1 === $days
				? 'Payable within 1 day without deduction.'
				: sprintf( 'Payable within %d days without deduction.', $days );
		}

		return 1 === $days
			? 'Zahlbar innerhalb von 1 Tag ohne Abzug.'
			: sprintf( 'Zahlbar innerhalb von %d Tagen ohne Abzug.', $days );
	}

	/**
	 * paymentConditions label for an order that is already paid — see the
	 * comment at the call site for why this is sent explicitly rather than
	 * left out.
	 *
	 * @param WC_Order $order Order (used to resolve the language).
	 * @return string
	 */
	private function paid_condition_label( WC_Order $order ) {
		return 'en' === $this->resolve_language( $order )
			? 'Thank you for your payment.'
			: 'Vielen Dank für Ihre Zahlung.';
	}

	/**
	 * Opening line naming the originating order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function default_introduction( WC_Order $order ) {
		$language = $this->resolve_language( $order );
		$number   = $order->get_order_number();

		if ( 'en' === $language ) {
			return sprintf( 'For our deliveries and services relating to order %s we invoice you as follows.', $number );
		}

		return sprintf( 'Unsere Lieferungen/Leistungen aus Bestellung %s stellen wir Ihnen wie folgt in Rechnung.', $number );
	}

	/**
	 * Closing note recording how the order was paid.
	 *
	 * @param WC_Order $order Order.
	 * @return string Empty when no payment method is known.
	 */
	private function payment_note( WC_Order $order ) {
		$method = trim( (string) $order->get_payment_method_title() );

		if ( '' === $method ) {
			$method = trim( (string) $order->get_payment_method() );
		}

		$language = $this->resolve_language( $order );

		// Not settled yet — say so instead of claiming it was paid. Some
		// gateways (invoice/"Kauf auf Rechnung" in particular) fill in the paid
		// date while the order still awaits payment, so needs_payment() is the
		// only trustworthy signal here.
		if ( $order->needs_payment() ) {
			$days = (int) Nota_Inv_Settings::instance()->get( 'payment_term_days' );

			if ( $days > 0 ) {
				return 'en' === $language
					? sprintf(
						1 === $days
							? 'Payment is still outstanding. Please transfer the amount within %d day.'
							: 'Payment is still outstanding. Please transfer the amount within %d days.',
						$days
					)
					: sprintf(
						1 === $days
							? 'Der Betrag ist noch offen. Bitte überweisen Sie ihn innerhalb von %d Tag.'
							: 'Der Betrag ist noch offen. Bitte überweisen Sie ihn innerhalb von %d Tagen.',
						$days
					);
			}

			return 'en' === $language
				? 'Payment is still outstanding.'
				: 'Der Betrag ist noch offen.';
		}

		if ( '' === $method ) {
			return '';
		}

		$detail = trim( (string) $order->get_transaction_id() );

		// Some gateways store the payer's account as order meta; include it when
		// present because it is what makes reconciliation quick.
		foreach ( array( '_paypal_payer_email', 'Payer PayPal address', '_stripe_customer_email' ) as $key ) {
			$value = $order->get_meta( $key );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$detail = trim( $value );
				break;
			}
		}

		$line = '' !== $detail
			? $method . ' - ' . $detail
			: $method;

		if ( 'en' === $language ) {
			return sprintf( 'Paid by %s.', $line );
		}

		return sprintf( 'Bezahlt per %s.', $line );
	}

	/**
	 * Resolve the document language for an order.
	 *
	 * When the "match order language" setting is on and a supported
	 * multilingual plugin is active, this tries to detect the language the
	 * customer actually used at checkout, so a French/Dutch/etc. customer on
	 * a German-run shop doesn't automatically get a German invoice just
	 * because that's the plugin's fallback setting.
	 *
	 * Detection is best-effort, not authoritative: the exact meta key WPML
	 * uses to store an order's language has changed between versions and
	 * conflicting values were found even across WPML's own official pages
	 * (`wpml_language` vs `wpml_languages`) — both are tried. Polylang
	 * exposes no documented order-specific storage at all, so its general
	 * `pll_get_post_language()` API is used, which may not cover every
	 * Polylang/HPOS combination. Only "de" and "en" are ever returned —
	 * Lexware invoice text in this plugin only exists in those two
	 * languages — anything else falls back to the configured default.
	 *
	 * The `nota_inv_detected_order_language` filter lets a site override
	 * detection entirely if it knows its own setup better than this
	 * heuristic does.
	 *
	 * @param WC_Order $order Order.
	 * @return string 'de' or 'en'.
	 */
	private function resolve_language( WC_Order $order ) {
		$settings = Nota_Inv_Settings::instance();
		$fallback = 'en' === $settings->get( 'language' ) ? 'en' : 'de';

		if ( ! $settings->is( 'language_auto_detect' ) ) {
			return $fallback;
		}

		$detected = null;

		// WPML / WooCommerce Multilingual. Two different meta keys have been
		// documented on WPML's own site for different versions/storage
		// modes; try both rather than guessing which applies here.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' ) ) {
			$single = $order->get_meta( 'wpml_language' );
			if ( is_string( $single ) && '' !== $single ) {
				$detected = $single;
			}

			if ( null === $detected ) {
				$multi = $order->get_meta( 'wpml_languages' );
				if ( ! empty( $multi ) ) {
					$unserialized = is_array( $multi ) ? $multi : maybe_unserialize( $multi );
					if ( is_array( $unserialized ) && ! empty( $unserialized[0] ) ) {
						$detected = (string) $unserialized[0];
					}
				}
			}
		}

		// Polylang. No documented order-specific meta key; fall back to its
		// general-purpose language lookup if available.
		if ( null === $detected && function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $order->get_id() );
			if ( is_string( $lang ) && '' !== $lang ) {
				$detected = $lang;
			}
		}

		/**
		 * Override or supply the detected order language.
		 *
		 * @param string|null $detected Language code detected above, or null.
		 * @param WC_Order    $order    Order.
		 */
		$detected = apply_filters( 'nota_inv_detected_order_language', $detected, $order );

		if ( is_string( $detected ) ) {
			$code = strtolower( substr( $detected, 0, 2 ) );
			if ( in_array( $code, array( 'de', 'en' ), true ) ) {
				return $code;
			}
		}

		return $fallback;
	}

	/**
	 * Delivery/service date for the voucher.
	 *
	 * The API rejects an invoice without shipping conditions, so this is
	 * always sent. "service" covers the general case of goods handed over on
	 * a single date; the paid date is the closest thing WooCommerce has to a
	 * supply date, with the created date as a fallback. The "Supply date
	 * offset" setting shifts this forward for shops with a production lead
	 * time — the invoice date itself (voucherDate) is never shifted, only
	 * this delivery/service date.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function shipping_conditions( WC_Order $order ) {
		$base = $this->supply_date( $order );

		$settings    = Nota_Inv_Settings::instance();
		$offset      = (int) $settings->get( 'supply_date_offset', '0' );
		$offset_end  = $settings->get( 'supply_date_offset_end', '' );

		$start = $base;
		if ( 0 !== $offset && $start instanceof WC_DateTime ) {
			$start = clone $start;
			$start->modify( sprintf( '%+d days', $offset ) );
		}

		$formatted_start = $start
			? $start->format( 'Y-m-d\TH:i:s.000P' )
			: gmdate( 'Y-m-d\TH:i:s.000P' );

		$conditions = array(
			'shippingDate' => $formatted_start,
			'shippingType' => 'service',
		);

		// A range ("Lieferzeitraum") is only added when an end offset is
		// configured. CONFIRMED via official API schema docs: shippingType
		// is an enum with five values — service, serviceperiod, delivery,
		// deliveryperiod, none. A range needs its own enum value
		// ("deliveryperiod"), not "delivery" plus shippingEndDate — that
		// combination was tried first and the API accepted it silently but
		// rendered only the single start date (live-tested 20.07.2026,
		// order 240691 → RE2024-1729).
		if ( '' !== $offset_end && $base instanceof WC_DateTime ) {
			$end = clone $base;
			$end->modify( sprintf( '%+d days', (int) $offset_end ) );
			$conditions['shippingEndDate'] = $end->format( 'Y-m-d\TH:i:s.000P' );
			$conditions['shippingType']    = 'deliveryperiod';
		}

		return $conditions;
	}

	/**
	 * Voucher date in the format Lexware expects (RFC 3339).
	 *
	 * This is the Rechnungsdatum (§14 Abs. 4 Nr. 3 UStG) — the date the
	 * document is issued — which is a separate mandatory piece of information
	 * from the Leistungsdatum (§14 Abs. 4 Nr. 6 UStG, the delivery/service
	 * date already sent via shippingConditions). It is always "now": using
	 * the order's own date here would backdate the invoice whenever it is
	 * created after the order — the Lexware API accepts a backdated
	 * voucherDate without complaint, so a confused VAT reporting period would
	 * not be caught until an accountant or audit found it later.
	 *
	 * @return string
	 */
	private function voucher_date() {
		return gmdate( 'Y-m-d\TH:i:s.000P' );
	}

	/**
	 * The date the goods were supplied, as far as WooCommerce knows it.
	 *
	 * Only trusts the paid date when the order really is settled: invoice
	 * gateways ("Kauf auf Rechnung") fill that field in up front while payment
	 * is still outstanding, which would otherwise put a misleading supply date
	 * on the document.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_DateTime|null
	 */
	private function supply_date( WC_Order $order ) {
		if ( ! $order->needs_payment() ) {
			$paid = $order->get_date_paid();

			if ( $paid ) {
				return $paid;
			}
		}

		return $order->get_date_created();
	}

	/**
	 * Substitute order placeholders in a free-text field.
	 *
	 * @param string   $text  Text.
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function replace_placeholders( $text, WC_Order $order ) {
		return str_replace(
			array( '{order_number}', '{order_date}', '{payment_method}', '{customer_name}' ),
			array(
				$order->get_order_number(),
				$order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '',
				$order->get_payment_method_title(),
				trim( $order->get_formatted_billing_full_name() ),
			),
			$text
		);
	}

	/**
	 * Normalise a string for the API.
	 *
	 * @param string $value  Raw value.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private function clean( $value, $length ) {
		$value = wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) );
		$value = trim( preg_replace( '/[ \t]+/', ' ', $value ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $length );
		}

		return substr( $value, 0, $length );
	}
}
