<?php
/**
 * Invoice status column on the order list, plus a Pro-labelled entry point
 * for bulk invoice creation.
 *
 * The status column is a plain read-only display and stays in the free
 * version. Actually queueing invoices for many orders at once is a Pro
 * feature (see handle_bulk_action()) — no invoicing code runs from this
 * file in this version.
 *
 * Supports both the legacy post-based order screen and the HPOS
 * (custom order tables) order screen, since either can be active.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Admin_Order_List {

	const BULK_ACTION = 'nota_inv_bulk_create';
	const REVIEW_NONCE = 'nota_inv_review_notice';

	/**
	 * @var Nota_Inv_Admin_Order_List|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Legacy (post-based) order list.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 10, 2 );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );

		// HPOS (custom order tables) order list.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column_hpos' ), 10, 2 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'maybe_show_bulk_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_review_request' ) );
		add_action( 'admin_post_nota_inv_review_click', array( $this, 'handle_review_click' ) );
		add_action( 'admin_post_nota_inv_dismiss_review_request', array( $this, 'handle_dismiss_review_request' ) );
	}

	/**
	 * Insert the invoice column right after the order status column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$new['nota_inv_invoice'] = __( 'Lexware invoice', 'nota-invoice-sync' );
			}
		}

		if ( ! isset( $new['nota_inv_invoice'] ) ) {
			$new['nota_inv_invoice'] = __( 'Lexware invoice', 'nota-invoice-sync' );
		}

		return $new;
	}

	/**
	 * Column content — legacy order list (receives a post id).
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public function render_column_legacy( $column, $post_id ) {
		if ( 'nota_inv_invoice' !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );

		if ( $order instanceof WC_Order ) {
			$this->render_cell( $order );
		}
	}

	/**
	 * Column content — HPOS order list (receives the order object).
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order  Order.
	 * @return void
	 */
	public function render_column_hpos( $column, $order ) {
		if ( 'nota_inv_invoice' !== $column ) {
			return;
		}

		if ( $order instanceof WC_Order ) {
			$this->render_cell( $order );
		}
	}

	/**
	 * The actual cell content, shared by both list screens.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private function render_cell( WC_Order $order ) {
		$invoice_id = (string) $order->get_meta( NOTA_INV_META_INVOICE_ID );
		$number     = (string) $order->get_meta( NOTA_INV_META_INVOICE_NUMBER );
		$status     = (string) $order->get_meta( NOTA_INV_META_INVOICE_STATUS );
		$error      = (string) $order->get_meta( NOTA_INV_META_LAST_ERROR );

		if ( '' !== $invoice_id ) {
			$label = '' !== $number ? $number : __( '(draft)', 'nota-invoice-sync' );
			$color = 'draft' === $status ? '#996800' : '#1d6b28';

			printf(
				'<a href="%s" target="_blank" rel="noopener" style="color:%s;font-weight:600;">%s</a>',
				esc_url( Nota_Inv_Invoice_Service::deeplink( $invoice_id ) ),
				esc_attr( $color ),
				esc_html( $label )
			);
			return;
		}

		if ( '' !== $error ) {
			printf(
				'<span style="color:#b32d2e;" title="%s">%s</span>',
				esc_attr( $error ),
				esc_html__( 'Error', 'nota-invoice-sync' )
			);
			return;
		}

		echo '&ndash;';
	}

	/**
	 * Add a "Create Lexware invoice" entry to the bulk actions dropdown.
	 *
	 * Bulk/staggered invoicing is a Pro feature (queued, rate-limit-aware
	 * processing of many orders at once). Rather than hide the option
	 * entirely, it stays visible and clearly marked so it can be found —
	 * selecting it does not invoice anything in this version, see
	 * handle_bulk_action().
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_action( $actions ) {
		$actions[ self::BULK_ACTION ] = __( 'Create Lexware invoice (Pro)', 'nota-invoice-sync' );
		return $actions;
	}

	/**
	 * Selecting the bulk action never invoices anything in this version —
	 * it only points to where bulk/staggered invoicing is available. No
	 * order is touched, nothing is scheduled.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action key.
	 * @param array  $order_ids   Selected order (or post) ids.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $order_ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect_to;
		}

		return add_query_arg( 'nota_inv_bulk_pro_notice', '1', $redirect_to );
	}

	/**
	 * Point to the Pro upgrade page after the bulk action is selected.
	 *
	 * @return void
	 */
	public function maybe_show_bulk_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only decides whether to show a static Pro-upsell notice, no state change.
		if ( ! isset( $_GET['nota_inv_bulk_pro_notice'] ) ) {
			return;
		}

		echo '<div class="notice notice-info is-dismissible"><p>';
		esc_html_e( 'Bulk invoice creation is available in Nota Invoice Sync Pro.', 'nota-invoice-sync' );
		echo ' <a href="https://www.wp-nota.com/lexware-invoice-sync" target="_blank" rel="noopener">';
		esc_html_e( 'Learn more', 'nota-invoice-sync' );
		echo ' &rarr;</a></p></div>';
	}

	/**
	 * One-time "would you leave us a review" nudge, shown on the order list
	 * screen only (not site-wide) once the shop has created a meaningful
	 * number of invoices through this plugin. Unlike the Pro-upsell nudge
	 * in class-admin-order-metabox.php, this never repeats — once shown and
	 * either dismissed or clicked through, the "asked" flag is permanent.
	 * Free edition only; no equivalent exists in Pro.
	 *
	 * @return void
	 */
	public function maybe_show_review_request() {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		if ( 'yes' === get_option( 'nota_inv_review_asked', 'no' ) ) {
			return;
		}

		$total = (int) get_option( 'nota_inv_total_manual_invoices', 0 );

		/**
		 * Filter the lifetime manual-invoice count that triggers the
		 * one-time review request.
		 *
		 * @param int $threshold Default 20.
		 */
		$threshold = (int) apply_filters( 'nota_inv_review_request_threshold', 20 );

		if ( $total < $threshold ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a> &middot; <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of invoices created with the plugin. */
					__( "You've created %d invoices with Nota Invoice Sync. If it's been useful, a review would mean a lot.", 'nota-invoice-sync' ),
					$total
				)
			),
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=nota_inv_review_click' ),
					self::REVIEW_NONCE
				)
			),
			esc_html__( 'Leave a review →', 'nota-invoice-sync' ),
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=nota_inv_dismiss_review_request' ),
					self::REVIEW_NONCE
				)
			),
			esc_html__( 'Dismiss', 'nota-invoice-sync' )
		);
	}

	/**
	 * "Leave a review" was clicked — mark as asked (so this never shows
	 * again) and forward on to the WordPress.org review form.
	 *
	 * @return void
	 */
	public function handle_review_click() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nota-invoice-sync' ) );
		}

		check_admin_referer( self::REVIEW_NONCE );

		update_option( 'nota_inv_review_asked', 'yes', false );

		// phpcs:ignore WordPress.Security.SafeRedirect -- deliberate external destination (WordPress.org), not derived from user input.
		wp_redirect( 'https://wordpress.org/support/plugin/nota-invoice-sync/reviews/#new-post' );
		exit;
	}

	/**
	 * "Dismiss" on the review-request notice — also permanent, same as
	 * clicking through, since the point is to ask once and then leave the
	 * shop owner alone either way.
	 *
	 * @return void
	 */
	public function handle_dismiss_review_request() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nota-invoice-sync' ) );
		}

		check_admin_referer( self::REVIEW_NONCE );

		update_option( 'nota_inv_review_asked', 'yes', false );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}
}
