<?php
/**
 * Privacy integration: WooCommerce's Personal Data (GDPR) export/erase
 * tools and a suggested text for the shop's privacy policy.
 *
 * The plugin itself stores no personal data in WordPress beyond reference
 * ids (Lexware contact id, invoice id) — the actual name/address/email
 * lives in Lexware Office. But a finalised invoice there is an accounting
 * record that German law requires to be kept for eight years (§ 147 AO,
 * § 14b UStG — ten years until the Viertes Bürokratieentlastungsgesetz
 * shortened it on 1 January 2025), so it cannot be erased on request the
 * way WooCommerce's own order data can.
 *
 * Without this, a shop owner running WooCommerce's "Erase Personal Data"
 * tool would have no way of knowing that a Lexware invoice for that
 * customer still exists elsewhere, retained under a legal exception —
 * they might wrongly believe the data is fully gone everywhere.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Privacy {

	/**
	 * @var Nota_Inv_Privacy|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Suggest text for the shop's privacy policy (Settings → Privacy →
	 * Policy Guide).
	 *
	 * @return void
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Nota Invoice Sync creates invoices for your WooCommerce orders in your own Lexware Office account. The text below is a suggestion: adapt it to your shop, add Haufe-Lexware\'s full postal address, and make sure a data processing agreement (Auftragsverarbeitungsvertrag) with Haufe-Lexware is in place for your Lexware Office account.', 'nota-invoice-sync' ) . '</p>';
		$content .= '<strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'nota-invoice-sync' ) . ' </strong>';
		$content .= '<p>' . esc_html__( 'We create our invoices with Lexware Office, an accounting service of Haufe-Lexware GmbH & Co. KG, Germany. When we create an invoice for your order, we transmit your name, billing address, email address, the ordered items with prices and tax rates, shipping costs, the order number and the order and delivery dates to Lexware Office. Lexware Office processes this data on our behalf as a processor (Art. 28 GDPR).', 'nota-invoice-sync' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Legal basis: Art. 6(1)(b) GDPR (performance of the contract) and Art. 6(1)(c) GDPR (our legal obligation to issue invoices and keep accounting records).', 'nota-invoice-sync' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Invoices, credit notes and other accounting records are kept for eight years as required by German tax and commercial law (§ 147 AO, § 14b UStG, § 257 HGB) and cannot be deleted before this period ends.', 'nota-invoice-sync' ) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Nota Invoice Sync for Lexware Office', 'nota-invoice-sync' ),
			wp_kses_post( $content )
		);
	}

	/**
	 * Register the exporter shown on Tools → Export Personal Data.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['nota-invoice-sync'] = array(
			'exporter_friendly_name' => __( 'Nota Invoice Sync for Lexware Office', 'nota-invoice-sync' ),
			'callback'                => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser shown on Tools → Erase Personal Data.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['nota-invoice-sync'] = array(
			'eraser_friendly_name' => __( 'Nota Invoice Sync for Lexware Office', 'nota-invoice-sync' ),
			'callback'              => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Report which of the customer's orders have a Lexware Office invoice.
	 *
	 * This does not export the invoice contents themselves — those are
	 * held in Lexware Office and outside WordPress's data export scope —
	 * only the fact that one exists and where to find it.
	 *
	 * @param string $email Requester's email.
	 * @param int    $page  Page number (WooCommerce paginates by order).
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$orders = $this->orders_for_email( $email, $page );
		$data   = array();

		foreach ( $orders as $order ) {
			$invoice_id = $order->get_meta( NOTA_INV_META_INVOICE_ID );

			if ( '' === (string) $invoice_id ) {
				continue;
			}

			$number = (string) $order->get_meta( NOTA_INV_META_INVOICE_NUMBER );

			$data[] = array(
				'group_id'    => 'nota_inv_invoices',
				'group_label' => __( 'Lexware Office invoices', 'nota-invoice-sync' ),
				'item_id'     => 'nota-inv-invoice-' . $order->get_id(),
				'data'        => array(
					array(
						'name'  => __( 'Order', 'nota-invoice-sync' ),
						'value' => $order->get_order_number(),
					),
					array(
						'name'  => __( 'Lexware invoice number', 'nota-invoice-sync' ),
						'value' => '' !== $number ? $number : __( '(draft, not yet numbered)', 'nota-invoice-sync' ),
					),
					array(
						'name'  => __( 'Note', 'nota-invoice-sync' ),
						'value' => __( 'The full invoice (name, address, line items) is stored in Lexware Office, not in WordPress. Request an export directly from Lexware Office if needed.', 'nota-invoice-sync' ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $orders ) < $this->page_size(),
		);
	}

	/**
	 * Explain, rather than perform, erasure for orders with a Lexware
	 * invoice — a finalised invoice is an accounting record and must be
	 * retained for eight years regardless of an erasure request.
	 *
	 * WooCommerce's own eraser still runs and anonymises what it can in
	 * WordPress; this only prevents a false impression that the Lexware
	 * side was cleared too.
	 *
	 * @param string $email Requester's email.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		$orders   = $this->orders_for_email( $email, $page );
		$retained = 0;
		$messages = array();

		foreach ( $orders as $order ) {
			$invoice_id = $order->get_meta( NOTA_INV_META_INVOICE_ID );
			$status     = (string) $order->get_meta( NOTA_INV_META_INVOICE_STATUS );

			if ( '' === (string) $invoice_id ) {
				continue;
			}

			$retained++;

			if ( 'draft' !== $status ) {
				$messages[] = sprintf(
					/* translators: %s: order number. */
					__( 'Order %s has a finalised Lexware Office invoice. German law requires accounting records to be kept for eight years (§ 147 AO, § 14b UStG), so it was not erased.', 'nota-invoice-sync' ),
					$order->get_order_number()
				);
			}
		}

		return array(
			'items_removed'  => false,
			'items_retained' => $retained > 0,
			'messages'       => $messages,
			'done'           => count( $orders ) < $this->page_size(),
		);
	}

	/**
	 * Orders for an email address, one page at a time.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return WC_Order[]
	 */
	private function orders_for_email( $email, $page ) {
		return wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => $this->page_size(),
				'page'          => max( 1, (int) $page ),
				'orderby'       => 'ID',
				'order'         => 'ASC',
			)
		);
	}

	/**
	 * Orders processed per page, matching WooCommerce's own default.
	 *
	 * @return int
	 */
	private function page_size() {
		return 10;
	}
}
