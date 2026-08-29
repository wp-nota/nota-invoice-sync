<?php
/**
 * Invoice panel on the order edit screen.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Admin_Order_Metabox {

	const NONCE  = 'nota_inv_order_action';
	const ACTION = 'nota_inv_order_action';
	const PAYMENT_ACTION = 'nota_inv_check_payment';
	const REFRESH_ACTION = 'nota_inv_refresh_status';
	const DISMISS_NUDGE_ACTION = 'nota_inv_dismiss_nudge';

	/**
	 * Rolling window the Pro-upsell nudge counts manual invoices over.
	 */
	const NUDGE_WINDOW = 7 * DAY_IN_SECONDS;

	/**
	 * How long the nudge stays quiet after being shown, or after being
	 * explicitly dismissed — dismissing is a stronger "not now" signal, so
	 * it buys a longer quiet period than simply having been shown once.
	 */
	const NUDGE_AUTO_SNOOZE  = 7 * DAY_IN_SECONDS;
	const NUDGE_DISMISS_SNOOZE = 30 * DAY_IN_SECONDS;

	/**
	 * @var Nota_Inv_Admin_Order_Metabox|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ), 30, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
		add_action( 'admin_post_' . self::PAYMENT_ACTION, array( $this, 'handle_payment_check' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( $this, 'handle_refresh_status' ) );
		add_action( 'admin_post_' . self::DISMISS_NUDGE_ACTION, array( $this, 'handle_dismiss_nudge' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
	}

	/**
	 * Register the meta box for both legacy and HPOS order screens.
	 *
	 * @param string $screen_id Screen id.
	 * @param mixed  $object    Post or order.
	 * @return void
	 */
	public function register( $screen_id, $object = null ) {
		$screens = array( 'shop_order', 'woocommerce_page_wc-orders' );

		if ( ! in_array( $screen_id, $screens, true ) ) {
			return;
		}

		add_meta_box(
			'nota-inv-invoice',
			__( 'Lexware Office invoice', 'nota-invoice-sync' ),
			array( $this, 'render' ),
			$screen_id,
			'side',
			'default'
		);
	}

	/**
	 * Render the panel.
	 *
	 * @param mixed $object Post or order object.
	 * @return void
	 */
	public function render( $object ) {
		$order = $object instanceof WC_Order ? $object : wc_get_order( is_object( $object ) ? $object->ID : $object );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$invoice_id = (string) $order->get_meta( NOTA_INV_META_INVOICE_ID );
		$number     = (string) $order->get_meta( NOTA_INV_META_INVOICE_NUMBER );
		$status     = (string) $order->get_meta( NOTA_INV_META_INVOICE_STATUS );
		$error      = (string) $order->get_meta( NOTA_INV_META_LAST_ERROR );
		$edoc_profile = (string) $order->get_meta( NOTA_INV_META_EDOC_PROFILE );
		$settings   = Nota_Inv_Settings::instance();

		if ( '' !== $invoice_id ) {
			echo '<p>';

			if ( '' !== $number ) {
				echo '<strong>' . esc_html( $number ) . '</strong><br />';
			}

			if ( 'draft' === $status ) {
				echo '<span class="nota-inv-badge is-warning">' . esc_html__( 'Draft', 'nota-invoice-sync' ) . '</span><br />';
				echo '<span class="description">' . esc_html__( 'Editable and removable in Lexware Office. Not booked, no e-invoice yet.', 'nota-invoice-sync' ) . '</span>';
			} elseif ( '' !== $status ) {
				printf(
					'<span class="nota-inv-badge is-success">%s</span><br />',
					esc_html(
						sprintf(
							/* translators: %s: voucher status such as open or paid. */
							__( 'Finalised (%s)', 'nota-invoice-sync' ),
							$status
						)
					)
				);
				echo '<span class="description">' . esc_html__( 'Permanent, can only be reversed with a credit note.', 'nota-invoice-sync' ) . '</span>';

				if ( '' !== $edoc_profile ) {
					printf(
						'<br /><span class="nota-inv-badge is-success">%s</span>',
						esc_html( $edoc_profile )
					);
				}
			}

			echo '</p>';

			echo '<div class="nota-inv-actions">';

			printf(
				'<a href="%s" target="_blank" rel="noopener" class="button button-small">%s</a>',
				esc_url( Nota_Inv_Invoice_Service::deeplink( $invoice_id ) ),
				esc_html__( 'Open in Lexware Office', 'nota-invoice-sync' )
			);

			printf(
				'<a href="%s" class="button button-small">%s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=' . self::REFRESH_ACTION . '&order_id=' . $order->get_id() ),
						self::NONCE
					)
				),
				esc_html__( 'Refresh invoice status', 'nota-invoice-sync' )
			);

			printf(
				'<a href="%s" class="button button-small">%s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=' . self::PAYMENT_ACTION . '&order_id=' . $order->get_id() ),
						self::NONCE
					)
				),
				esc_html__( 'Check payment status', 'nota-invoice-sync' )
			);

			echo '</div>';

			// Pro teaser: real PDF download (admin + customer My Account) has
			// no code in this edition, only this muted, non-clickable label —
			// same finalised-only gate the Pro edition itself uses.
			if ( '' !== $status && 'draft' !== $status ) {
				printf(
					'<p><span style="color:#8c8f94;">%s</span> <a href="%s" target="_blank" rel="noopener">(%s)</a></p>',
					esc_html__( 'Download PDF', 'nota-invoice-sync' ),
					esc_url( 'https://www.wp-nota.com/lexware-invoice-sync' ),
					esc_html__( 'Pro', 'nota-invoice-sync' )
				);
			}

			$payment_info = get_transient( 'nota_inv_payment_' . $order->get_id() );

			if ( is_array( $payment_info ) && ! empty( $payment_info['summary'] ) ) {
				echo '<p class="nota-inv-panel-box">';
				echo '<strong>' . esc_html__( 'Lexware payment status:', 'nota-invoice-sync' ) . '</strong><br />';
				echo esc_html( $payment_info['summary'] );
				echo '</p>';
			}
		} else {
			echo '<p>' . esc_html__( 'No invoice has been created for this order yet.', 'nota-invoice-sync' ) . '</p>';
		}

		if ( '' !== $error ) {
			echo '<div class="nota-inv-notice-inline"><strong>' . esc_html__( 'Last error:', 'nota-invoice-sync' ) . '</strong><br />';
			echo esc_html( $error ) . '</div>';
		}

		if ( $settings->is( 'test_mode' ) ) {
			echo '<p><em>' . esc_html__( 'Test mode is on — payloads are logged, nothing is sent.', 'nota-invoice-sync' ) . '</em></p>';
		}

		$label = '' !== $invoice_id
			? __( 'Create again', 'nota-invoice-sync' )
			: __( 'Create invoice now', 'nota-invoice-sync' );

		printf(
			'<p><a href="%s" class="button %s">%s</a></p>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=' . self::ACTION . '&order_id=' . $order->get_id() ),
					self::NONCE
				)
			),
			'' === $invoice_id ? 'button-primary' : '',
			esc_html( $label )
		);

		if ( '' !== $invoice_id ) {
			echo '<p class="description">' . esc_html__( 'Creating again produces a second document in Lexware Office. The old one is not removed.', 'nota-invoice-sync' ) . '</p>';
		}

		$this->render_credit_note_teaser( $invoice_id );
	}

	/**
	 * Pro teaser: shown exactly where the Pro edition renders its credit note
	 * (Gutschrift) section. Purely visual — the button is disabled, nothing is
	 * wired to it, and no credit note code exists in this edition at all.
	 *
	 * @param string $invoice_id Invoice UUID, empty if none yet.
	 * @return void
	 */
	private function render_credit_note_teaser( $invoice_id ) {
		if ( '' === $invoice_id ) {
			return;
		}

		echo '<div class="nota-inv-subcard">';
		echo '<p><strong>' . esc_html__( 'Credit note', 'nota-invoice-sync' ) . '</strong></p>';
		echo '<p><button type="button" class="button button-small" disabled="disabled">' . esc_html__( 'Create credit note (full amount)', 'nota-invoice-sync' ) . '</button></p>';
		printf(
			'<p class="description"><strong><a href="%s" target="_blank" rel="noopener">%s →</a></strong></p>',
			esc_url( 'https://www.wp-nota.com/lexware-invoice-sync' ),
			esc_html__( 'Credit notes (Gutschrift) are available in Nota Invoice Sync Pro', 'nota-invoice-sync' )
		);
		echo '</div>';
	}

	/**
	 * Re-fetch GET /v1/invoices/{id} and update the stored status/number if
	 * they no longer match Lexware Office.
	 *
	 * The plugin only ever records the voucher status once, right when the
	 * invoice is created — nothing else watches for it changing later. If a
	 * draft invoice is finalised directly in Lexware Office rather than
	 * through this plugin, this button is the only way to catch up.
	 *
	 * @return void
	 */
	public function handle_refresh_status() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nota-invoice-sync' ) );
		}

		check_admin_referer( self::NONCE );

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'nota-invoice-sync' ) );
		}

		$invoice_id = (string) $order->get_meta( NOTA_INV_META_INVOICE_ID );

		if ( '' === $invoice_id ) {
			wp_safe_redirect( $order->get_edit_order_url() );
			exit;
		}

		// Test mode = no API traffic at all, including this read-only query.
		if ( Nota_Inv_Settings::instance()->is( 'test_mode' ) ) {
			wp_safe_redirect(
				add_query_arg( 'nota_inv_result', 'refresh_test', $order->get_edit_order_url() )
			);
			exit;
		}

		$client = new Nota_Inv_Api_Client();
		$detail = $client->get( '/v1/invoices/' . rawurlencode( $invoice_id ) );

		if ( is_wp_error( $detail ) ) {
			Nota_Inv_Logger::error(
				sprintf( 'Could not refresh status for invoice %s (order %d): %s', $invoice_id, $order_id, $detail->get_error_message() )
			);
			wp_safe_redirect(
				add_query_arg( 'nota_inv_result', 'refresh_error', $order->get_edit_order_url() )
			);
			exit;
		}

		$changed = false;

		if ( ! empty( $detail['voucherStatus'] ) && (string) $detail['voucherStatus'] !== (string) $order->get_meta( NOTA_INV_META_INVOICE_STATUS ) ) {
			$order->update_meta_data( NOTA_INV_META_INVOICE_STATUS, (string) $detail['voucherStatus'] );
			$changed = true;
		}

		if ( ! empty( $detail['voucherNumber'] ) && (string) $detail['voucherNumber'] !== (string) $order->get_meta( NOTA_INV_META_INVOICE_NUMBER ) ) {
			$order->update_meta_data( NOTA_INV_META_INVOICE_NUMBER, (string) $detail['voucherNumber'] );
			$changed = true;
		}

		if ( ! empty( $detail['electronicDocumentProfile'] ) && (string) $detail['electronicDocumentProfile'] !== (string) $order->get_meta( NOTA_INV_META_EDOC_PROFILE ) ) {
			$order->update_meta_data( NOTA_INV_META_EDOC_PROFILE, (string) $detail['electronicDocumentProfile'] );
			$changed = true;
		}

		if ( $changed ) {
			$order->save();
			Nota_Inv_Logger::debug( sprintf( 'Order %d: invoice status refreshed from Lexware Office.', $order_id ) );
		}

		wp_safe_redirect(
			add_query_arg( 'nota_inv_result', $changed ? 'refresh_ok' : 'refresh_unchanged', $order->get_edit_order_url() )
		);
		exit;
	}

	/**
	 * Query GET /v1/payments/{invoiceId} and record what came back.
	 *
	 * Read-only against the Lexware API. The raw response is written to the
	 * log so the exact schema can be inspected; a short human summary is kept
	 * in a transient for the panel.
	 *
	 * @return void
	 */
	public function handle_payment_check() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nota-invoice-sync' ) );
		}

		check_admin_referer( self::NONCE );

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'nota-invoice-sync' ) );
		}

		$invoice_id = (string) $order->get_meta( NOTA_INV_META_INVOICE_ID );

		if ( '' === $invoice_id ) {
			wp_safe_redirect( $order->get_edit_order_url() );
			exit;
		}

		// Test mode = no API traffic at all, including this read-only query.
		if ( Nota_Inv_Settings::instance()->is( 'test_mode' ) ) {
			set_transient(
				'nota_inv_payment_' . $order_id,
				array( 'summary' => __( 'Test mode is on — no API request was sent to Lexware Office.', 'nota-invoice-sync' ) ),
				10 * MINUTE_IN_SECONDS
			);

			wp_safe_redirect( $order->get_edit_order_url() );
			exit;
		}

		$client   = new Nota_Inv_Api_Client();
		$response = $client->get( '/v1/payments/' . rawurlencode( $invoice_id ) );

		if ( is_wp_error( $response ) ) {
			$summary = sprintf(
				/* translators: %s: error message. */
				__( 'Not available: %s', 'nota-invoice-sync' ),
				$response->get_error_message()
			);

			Nota_Inv_Logger::error(
				sprintf( 'Payment status for invoice %s (order %d): %s', $invoice_id, $order_id, $response->get_error_message() )
			);
		} else {
			// Log the full raw structure once so the real schema is on record.
			Nota_Inv_Logger::error(
				sprintf( 'Payment status raw response for invoice %s (order %d)', $invoice_id, $order_id ),
				array( 'response' => $response )
			);

			$parts = array();

			if ( isset( $response['paymentStatus'] ) ) {
				$parts[] = sprintf( 'Status: %s', $response['paymentStatus'] );
			}
			if ( isset( $response['openAmount'] ) ) {
				$parts[] = sprintf( 'Open: %s %s', $response['openAmount'], isset( $response['currency'] ) ? $response['currency'] : '' );
			}
			if ( isset( $response['paidDate'] ) && $response['paidDate'] ) {
				$parts[] = sprintf( 'Paid on: %s', substr( (string) $response['paidDate'], 0, 10 ) );
			}
			if ( isset( $response['paymentItems'] ) && is_array( $response['paymentItems'] ) ) {
				$parts[] = sprintf( 'Payment items: %d', count( $response['paymentItems'] ) );
			}

			$summary = empty( $parts )
				? __( 'Response received — see the log for details.', 'nota-invoice-sync' )
				: implode( ' · ', $parts );
		}

		set_transient( 'nota_inv_payment_' . $order_id, array( 'summary' => $summary ), 10 * MINUTE_IN_SECONDS );

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Handle the manual create button.
	 *
	 * @return void
	 */
	public function handle_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nota-invoice-sync' ) );
		}

		check_admin_referer( self::NONCE );

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'nota-invoice-sync' ) );
		}

		$service = new Nota_Inv_Invoice_Service();
		$result  = $service->create_for_order( $order_id, true );

		$status = 'ok';

		if ( is_wp_error( $result ) ) {
			$status = 'error';
		} elseif ( ! empty( $result['test_mode'] ) ) {
			$status = 'test';
		} else {
			// A genuine new invoice (the metabox button always passes
			// $force = true, so a real "already invoiced" skip never
			// reaches here — see Invoice_Service::create_for_order_locked()).
			$this->record_manual_invoice();
		}

		wp_safe_redirect(
			add_query_arg(
				'nota_inv_result',
				$status,
				$order->get_edit_order_url()
			)
		);
		exit;
	}

	/**
	 * Record that a manual invoice was just created — feeds two independent,
	 * free-edition-only nudges: the Pro-upsell notice below (rolling 7-day
	 * count), and the one-time review request on the order list screen (see
	 * class-admin-order-list.php — lifetime total). Nothing about the order
	 * or customer is recorded, only a timestamp and a running count.
	 *
	 * @return void
	 */
	private function record_manual_invoice() {
		$log = get_option( 'nota_inv_manual_invoice_log', array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = time();

		$cutoff = time() - self::NUDGE_WINDOW;
		$log    = array_values(
			array_filter(
				$log,
				static function ( $timestamp ) use ( $cutoff ) {
					return (int) $timestamp >= $cutoff;
				}
			)
		);

		update_option( 'nota_inv_manual_invoice_log', $log, false );

		$total = (int) get_option( 'nota_inv_total_manual_invoices', 0 );
		update_option( 'nota_inv_total_manual_invoices', $total + 1, false );
	}

	/**
	 * How many manual invoices were created in the last 7 days, or 0 if
	 * that count doesn't clear the nudge threshold, or the nudge is
	 * currently snoozed. 0 doubles as "don't show the nudge" throughout.
	 *
	 * @return int
	 */
	private function recent_manual_invoice_count() {
		if ( time() < (int) get_option( 'nota_inv_nudge_suppressed_until', 0 ) ) {
			return 0;
		}

		$log = get_option( 'nota_inv_manual_invoice_log', array() );

		if ( ! is_array( $log ) ) {
			return 0;
		}

		$cutoff = time() - self::NUDGE_WINDOW;
		$count  = 0;

		foreach ( $log as $timestamp ) {
			if ( (int) $timestamp >= $cutoff ) {
				$count++;
			}
		}

		/**
		 * Filter how many manual invoices in the last 7 days trigger the
		 * Pro-upsell nudge on the order screen.
		 *
		 * @param int $threshold Default 10.
		 */
		$threshold = (int) apply_filters( 'nota_inv_manual_invoice_nudge_threshold', 10 );

		return $count >= $threshold ? $count : 0;
	}

	/**
	 * Pro-upsell nudge, shown right after a successful manual invoice
	 * creation once the weekly count clears the threshold. Snoozes itself
	 * for 7 days as soon as it is shown, or 30 days if explicitly
	 * dismissed, so it surfaces at most a few times a month even for a
	 * shop that keeps hitting the threshold every week. Free edition only
	 * — no equivalent code exists in Pro.
	 *
	 * @return void
	 */
	private function maybe_show_pro_nudge() {
		$count = $this->recent_manual_invoice_count();

		if ( 0 === $count ) {
			return;
		}

		update_option( 'nota_inv_nudge_suppressed_until', time() + self::NUDGE_AUTO_SNOOZE, false );

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s" target="_blank" rel="noopener">%s</a> &middot; <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of invoices created by hand in the last 7 days. */
					_n(
						"That's %d invoice you've created by hand this week.",
						"That's %d invoices you've created by hand this week.",
						$count,
						'nota-invoice-sync'
					),
					$count
				)
			),
			esc_url( 'https://www.wp-nota.com/lexware-invoice-sync' ),
			esc_html__( 'Pro creates them automatically →', 'nota-invoice-sync' ),
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=' . self::DISMISS_NUDGE_ACTION ),
					self::NONCE
				)
			),
			esc_html__( 'Dismiss', 'nota-invoice-sync' )
		);
	}

	/**
	 * "Dismiss" on the Pro-upsell nudge — snoozes it for 30 days rather
	 * than the usual 7, since an explicit dismissal is a stronger signal
	 * than simply having seen it once.
	 *
	 * @return void
	 */
	public function handle_dismiss_nudge() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nota-invoice-sync' ) );
		}

		check_admin_referer( self::NONCE );

		update_option( 'nota_inv_nudge_suppressed_until', time() + self::NUDGE_DISMISS_SNOOZE, false );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Show the outcome after a manual run.
	 *
	 * @return void
	 */
	public function maybe_show_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only selects which static notice text to display, no state change.
		if ( empty( $_GET['nota_inv_result'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$result = sanitize_key( wp_unslash( $_GET['nota_inv_result'] ) );

		if ( 'ok' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Invoice created in Lexware Office.', 'nota-invoice-sync' ) .
				'</p></div>';
			$this->maybe_show_pro_nudge();
			return;
		}

		if ( 'test' === $result ) {
			echo '<div class="notice notice-info is-dismissible"><p>' .
				esc_html__( 'Test mode: the invoice payload was written to the log but nothing was sent to Lexware Office.', 'nota-invoice-sync' ) .
				'</p></div>';
			return;
		}

		if ( 'refresh_ok' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Invoice status refreshed from Lexware Office.', 'nota-invoice-sync' ) .
				'</p></div>';
			return;
		}

		if ( 'refresh_unchanged' === $result ) {
			echo '<div class="notice notice-info is-dismissible"><p>' .
				esc_html__( 'Checked Lexware Office — the invoice status is unchanged.', 'nota-invoice-sync' ) .
				'</p></div>';
			return;
		}

		if ( 'refresh_test' === $result ) {
			echo '<div class="notice notice-info is-dismissible"><p>' .
				esc_html__( 'Test mode is on — no API request was sent to Lexware Office.', 'nota-invoice-sync' ) .
				'</p></div>';
			return;
		}

		if ( 'refresh_error' === $result ) {
			echo '<div class="notice notice-error is-dismissible"><p>' .
				esc_html__( 'Could not refresh the invoice status. Check the log for details.', 'nota-invoice-sync' ) .
				'</p></div>';
			return;
		}

		echo '<div class="notice notice-error is-dismissible"><p>' .
			esc_html__( 'The invoice could not be created. See the panel on the order for details.', 'nota-invoice-sync' ) .
			'</p></div>';
	}
}
