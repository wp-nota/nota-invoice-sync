<?php
/**
 * Settings screen under WooCommerce.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Admin_Settings_Page {

	const SLUG  = 'nota-invoice-sync';
	const NONCE = 'nota_inv_save_settings';

	/**
	 * @var Nota_Inv_Admin_Settings_Page|null
	 */
	private static $instance = null;

	/**
	 * Result of a connection test triggered in this request.
	 *
	 * @var array|WP_Error|null
	 */
	private $test_result = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_post' ) );
	}

	/**
	 * Add the submenu entry under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Nota Invoice Sync', 'nota-invoice-sync' ),
			__( 'Lexware Invoices', 'nota-invoice-sync' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle form submissions before any output.
	 *
	 * @return void
	 */
	public function maybe_handle_post() {
		if ( ! isset( $_POST['nota_inv_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['nota_inv_action'] ) );

		if ( 'save' === $action || 'save_and_test' === $action ) {
			$this->save_from_post();
			add_settings_error(
				self::SLUG,
				'saved',
				__( 'Settings saved.', 'nota-invoice-sync' ),
				'success'
			);
		}

		if ( 'test' === $action || 'save_and_test' === $action ) {
			$client            = new Nota_Inv_Api_Client();
			$this->test_result = $client->get_profile();

			// Refresh the daily health-check status with this same result
			// (no extra API call), so the connection badge doesn't keep
			// showing a stale failure right after a successful manual test.
			Nota_Inv_Health_Check::instance()->run( $this->test_result );
		}
	}

	/**
	 * Read, sanitise and store the submitted values.
	 *
	 * @return void
	 */
	private function save_from_post() {
		// Nonce already verified by the caller (maybe_handle_post(), via
		// check_admin_referer( self::NONCE )) before this method is ever
		// reached. Every individual field below is sanitised on its own
		// (sanitize_text_field/sanitize_textarea_field/absint/sanitize_key)
		// as it is read out of $posted further down.
		$settings = Nota_Inv_Settings::instance();
		$current  = $settings->all();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posted   = isset( $_POST['nota_inv'] ) && is_array( $_POST['nota_inv'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? wp_unslash( $_POST['nota_inv'] )
			: array();

		$yes_no = function ( $key ) use ( $posted ) {
			return isset( $posted[ $key ] ) && '1' === $posted[ $key ] ? 'yes' : 'no';
		};

		$text = function ( $key, $default = '' ) use ( $posted ) {
			return isset( $posted[ $key ] ) ? sanitize_text_field( $posted[ $key ] ) : $default;
		};

		$values = array(
			'auth_method'          => 'apikey',
			// Never clear a stored key just because the field was left blank.
			'api_key'              => isset( $posted['api_key'] ) && '' !== trim( $posted['api_key'] )
				? sanitize_text_field( trim( $posted['api_key'] ) )
				: $current['api_key'],
			'vat_id_meta_keys'     => $text( 'vat_id_meta_keys', $current['vat_id_meta_keys'] ),

			// trigger_statuses intentionally not read from $_POST — automatic
			// invoicing on order status is a Pro feature, the fieldset for it
			// is disabled and submits nothing in this version.
			'finalize'             => $yes_no( 'finalize' ),
			'language'             => 'en' === $text( 'language' ) ? 'en' : 'de',
			'language_auto_detect' => $yes_no( 'language_auto_detect' ),
			'payment_term_days'    => '' === $text( 'payment_term_days' )
				? ''
				: (string) max( 0, absint( $posted['payment_term_days'] ) ),
			'supply_date_offset'   => isset( $posted['supply_date_offset'] ) && '' !== trim( (string) $posted['supply_date_offset'] )
				? (string) intval( $posted['supply_date_offset'] )
				: '0',
			'supply_date_offset_end' => isset( $posted['supply_date_offset_end'] ) && '' !== trim( (string) $posted['supply_date_offset_end'] )
				? (string) intval( $posted['supply_date_offset_end'] )
				: '',
			'shipping_as_line'     => $yes_no( 'shipping_as_line' ),
			'write_order_note'     => $yes_no( 'write_order_note' ),

			'invoice_title'        => $text( 'invoice_title' ),
			'invoice_introduction' => isset( $posted['invoice_introduction'] )
				? sanitize_textarea_field( $posted['invoice_introduction'] )
				: '',
			'invoice_remark'       => isset( $posted['invoice_remark'] )
				? sanitize_textarea_field( $posted['invoice_remark'] )
				: '',

			'test_mode'            => $yes_no( 'test_mode' ),
			'log_level'            => in_array( $text( 'log_level' ), array( 'off', 'error', 'debug' ), true )
				? $text( 'log_level' )
				: 'error',
			'uninstall_remove_all' => $yes_no( 'uninstall_remove_all' ),
		);

		$settings->save( $values );
	}

	/**
	 * Small coloured pill summarising the connection state, without making an
	 * API call of its own — reads the existing daily health-check result
	 * (Nota_Inv_Health_Check::status(), already stored regardless of this
	 * page) so it reflects reality without needing a fresh test on load.
	 *
	 * @return void
	 */
	private function render_status_badge() {
		$settings = Nota_Inv_Settings::instance();

		if ( ! $settings->is_connected() ) {
			echo '<span class="nota-inv-badge is-neutral">' . esc_html__( 'Not connected yet', 'nota-invoice-sync' ) . '</span>';
			return;
		}

		$status = class_exists( 'Nota_Inv_Health_Check' ) ? Nota_Inv_Health_Check::status() : array();

		if ( empty( $status['checked_at'] ) ) {
			echo '<span class="nota-inv-badge is-neutral">' . esc_html__( 'API key saved — not checked yet', 'nota-invoice-sync' ) . '</span>';
			return;
		}

		if ( true === $status['connected'] ) {
			echo '<span class="nota-inv-badge is-success">' . esc_html__( 'Connected', 'nota-invoice-sync' ) . '</span>';
			return;
		}

		if ( false === $status['connected'] ) {
			echo '<span class="nota-inv-badge is-error">' . esc_html__( 'Connection failing', 'nota-invoice-sync' ) . '</span>';
		}
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render() {
		$settings = Nota_Inv_Settings::instance();
		$s        = $settings->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Nota Invoice Sync for Lexware Office', 'nota-invoice-sync' ); ?></h1>
			<p class="nota-inv-status-line"><?php $this->render_status_badge(); ?></p>

			<?php settings_errors( self::SLUG ); ?>
			<?php $this->render_test_result(); ?>

			<nav class="nota-inv-nav">
				<a href="#nota-inv-section-connection"><?php esc_html_e( 'Connection', 'nota-invoice-sync' ); ?></a>
				<a href="#nota-inv-section-trigger"><?php esc_html_e( 'When to create invoices', 'nota-invoice-sync' ); ?></a>
				<a href="#nota-inv-section-content"><?php esc_html_e( 'Invoice content', 'nota-invoice-sync' ); ?></a>
				<a href="#nota-inv-section-automation"><?php esc_html_e( 'Automation', 'nota-invoice-sync' ); ?></a>
				<a href="#nota-inv-section-diagnostics"><?php esc_html_e( 'Diagnostics', 'nota-invoice-sync' ); ?></a>
			</nav>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>

				<div class="nota-inv-card" id="nota-inv-section-connection">
				<h2 class="title"><span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'Connection', 'nota-invoice-sync' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'When an invoice is created, the customer\'s name, address, email and order contents are sent to Lexware Office (Haufe-Lexware GmbH & Co. KG), where they are stored under Lexware\'s own privacy policy and, once finalised, retained for ten years as required by German accounting law (GoBD).', 'nota-invoice-sync' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="nota_inv_api_key"><?php esc_html_e( 'API key', 'nota-invoice-sync' ); ?></label>
						</th>
						<td>
							<?php if ( $settings->api_key_is_constant() ) : ?>
								<p><code>NOTA_INV_API_KEY</code> <?php esc_html_e( 'is defined in wp-config.php and takes precedence over this field.', 'nota-invoice-sync' ); ?></p>
							<?php else : ?>
								<input
									type="password"
									class="regular-text"
									id="nota_inv_api_key"
									name="nota_inv[api_key]"
									value=""
									autocomplete="off"
									placeholder="<?php echo esc_attr( '' !== $s['api_key'] ? __( 'A key is stored — leave blank to keep it', 'nota-invoice-sync' ) : __( 'Paste your key here', 'nota-invoice-sync' ) ); ?>"
								/>
								<p class="description">
									<?php
									printf(
										/* translators: %s: link to the Lexware public API page. */
										esc_html__( 'Create a personal key at %s. It is only visible once, so copy it straight away.', 'nota-invoice-sync' ),
										'<a href="https://app.lexware.de/addons/public-api" target="_blank" rel="noopener">app.lexware.de</a>'
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				</div>

				<div class="nota-inv-card" id="nota-inv-section-trigger">
				<h2 class="title"><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'When to create invoices', 'nota-invoice-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Create an invoice when an order reaches', 'nota-invoice-sync' ); ?></th>
						<td>
							<fieldset disabled="disabled" style="opacity:.55;">
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" /> <?php esc_html_e( 'Processing — payment received', 'nota-invoice-sync' ); ?>
								</label>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" /> <?php esc_html_e( 'On hold — awaiting bank transfer', 'nota-invoice-sync' ); ?>
								</label>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" /> <?php esc_html_e( 'Completed', 'nota-invoice-sync' ); ?>
								</label>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" /> <?php esc_html_e( 'Pending payment — includes pay-on-invoice orders', 'nota-invoice-sync' ); ?>
								</label>
							</fieldset>
							<p class="description">
								<?php esc_html_e( 'This version creates invoices only by hand, from the button on the order screen.', 'nota-invoice-sync' ); ?>
							</p>
							<p>
								<strong>
									<a href="https://www.wp-nota.com/lexware-invoice-sync" target="_blank" rel="noopener">
										<?php esc_html_e( 'Automatic invoicing on order status is available in Nota Invoice Sync Pro', 'nota-invoice-sync' ); ?> →
									</a>
								</strong>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Document mode', 'nota-invoice-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nota_inv[finalize]" value="1" <?php checked( $s['finalize'], 'yes' ); ?> />
								<?php esc_html_e( 'Finalise invoices immediately', 'nota-invoice-sync' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Unchecked, invoices arrive in Lexware Office as drafts ("Entwurf") — they can still be edited or deleted there, are not booked, and carry no e-invoice document. Checked, Lexware finalises them straight away: the document becomes permanent, is recorded for GoBD, and the e-invoice (ZUGFeRD) is generated. A finalised invoice can only be reversed with a credit note.', 'nota-invoice-sync' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'A finalised invoice only becomes a real e-invoice (required for B2B from 2027) when two things are both true: your own Lexware Office account has "E-Rechnung erstellen" turned on under its own Settings — this plugin cannot turn that on for you — and the order has a billing company name, which this plugin already uses to create a "Firma" contact rather than a private one in Lexware. A private customer\'s invoice stays a plain PDF, which is correct for most B2C orders.', 'nota-invoice-sync' ); ?>
							</p>
						</td>
					</tr>
				</table>
				</div>

				<div class="nota-inv-card" id="nota-inv-section-content">
				<h2 class="title"><span class="dashicons dashicons-media-document"></span> <?php esc_html_e( 'Invoice content', 'nota-invoice-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Language', 'nota-invoice-sync' ); ?></th>
						<td>
							<select name="nota_inv[language]">
								<option value="de" <?php selected( $s['language'], 'de' ); ?>><?php esc_html_e( 'German', 'nota-invoice-sync' ); ?></option>
								<option value="en" <?php selected( $s['language'], 'en' ); ?>><?php esc_html_e( 'English', 'nota-invoice-sync' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Used as-is, unless order-language matching below is on and detects something different.', 'nota-invoice-sync' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Multilingual shops', 'nota-invoice-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nota_inv[language_auto_detect]" value="1" <?php checked( $s['language_auto_detect'], 'yes' ); ?> />
								<?php esc_html_e( 'Match the invoice language to the order\'s language (WPML or Polylang)', 'nota-invoice-sync' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Only German and English invoice text exist in this plugin; any other detected language falls back to the setting above. Detection depends on how your multilingual plugin stores the order language, which can vary — if it doesn\'t pick up correctly, the "nota_inv_detected_order_language" filter can supply it directly.', 'nota-invoice-sync' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="nota_inv_payment_term_days"><?php esc_html_e( 'Payment term (days)', 'nota-invoice-sync' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" step="1" class="small-text" id="nota_inv_payment_term_days"
								name="nota_inv[payment_term_days]" value="<?php echo esc_attr( $s['payment_term_days'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty to use the default configured in Lexware Office.', 'nota-invoice-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="nota_inv_supply_date_offset"><?php esc_html_e( 'Supply date (Leistungsdatum)', 'nota-invoice-sync' ); ?></label>
						</th>
						<td>
							<input type="number" step="1" class="small-text" id="nota_inv_supply_date_offset"
								name="nota_inv[supply_date_offset]" value="<?php echo esc_attr( $s['supply_date_offset'] ); ?>" />
							<?php esc_html_e( 'days after the order/payment date', 'nota-invoice-sync' ); ?>
							<p class="description">
								<?php esc_html_e( '0 means the supply date matches the order (or payment) date — correct for most shops. If your goods are produced to order and delivered weeks later, set the typical lead time here; the invoice date itself is never affected, only the delivery/service date shown on the document.', 'nota-invoice-sync' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="nota_inv_supply_date_offset_end"><?php esc_html_e( 'Delivery range end (optional)', 'nota-invoice-sync' ); ?></label>
						</th>
						<td>
							<input type="number" step="1" class="small-text" id="nota_inv_supply_date_offset_end"
								name="nota_inv[supply_date_offset_end]" value="<?php echo esc_attr( $s['supply_date_offset_end'] ); ?>" />
							<?php esc_html_e( 'days after the order date', 'nota-invoice-sync' ); ?>
							<p class="description">
								<?php esc_html_e( 'Leave empty to show a single supply date. Set a value to show a delivery window instead (e.g. "06.07.2026 bis 14.07.2026"), from the date above to this one.', 'nota-invoice-sync' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shipping', 'nota-invoice-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nota_inv[shipping_as_line]" value="1" <?php checked( $s['shipping_as_line'], 'yes' ); ?> />
								<?php esc_html_e( 'Show shipping cost as its own line item', 'nota-invoice-sync' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="nota_inv_invoice_title"><?php esc_html_e( 'Title', 'nota-invoice-sync' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="nota_inv_invoice_title"
								name="nota_inv[invoice_title]" value="<?php echo esc_attr( $s['invoice_title'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty to use the Lexware Office default.', 'nota-invoice-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="nota_inv_invoice_introduction"><?php esc_html_e( 'Introduction', 'nota-invoice-sync' ); ?></label>
						</th>
						<td>
							<textarea class="large-text" rows="2" id="nota_inv_invoice_introduction"
								name="nota_inv[invoice_introduction]"><?php echo esc_textarea( $s['invoice_introduction'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Leave empty to reference the order number automatically.', 'nota-invoice-sync' ); ?>
								<?php esc_html_e( 'Placeholders:', 'nota-invoice-sync' ); ?>
								<code>{order_number}</code> <code>{order_date}</code> <code>{payment_method}</code> <code>{customer_name}</code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="nota_inv_invoice_remark"><?php esc_html_e( 'Closing remark', 'nota-invoice-sync' ); ?></label>
						</th>
						<td>
							<textarea class="large-text" rows="2" id="nota_inv_invoice_remark"
								name="nota_inv[invoice_remark]"><?php echo esc_textarea( $s['invoice_remark'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Leave empty to record the payment method automatically. The same placeholders apply.', 'nota-invoice-sync' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="nota_inv_vat_id_meta_keys"><?php esc_html_e( 'VAT-ID meta keys', 'nota-invoice-sync' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								class="large-text"
								id="nota_inv_vat_id_meta_keys"
								name="nota_inv[vat_id_meta_keys]"
								value="<?php echo esc_attr( $s['vat_id_meta_keys'] ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'This plugin does not validate EU VAT numbers against VIES. It reads whatever VAT-ID a dedicated VAT compliance plugin has already saved on the order to determine reverse-charge treatment. If your shop needs live VAT-ID validation or automatic tax exemption at checkout, use a plugin built for that.', 'nota-invoice-sync' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Order meta keys checked for a VAT-ID, in order (comma-separated). The list above already covers the most common VAT compliance plugins — edit it or add your own if a different plugin is in use; check Custom Fields on an order screen if you are not sure of the field name.', 'nota-invoice-sync' ); ?>
							</p>
						</td>
					</tr>
				</table>
				</div>

				<?php
				// Pro teaser only: this section mirrors where the Pro edition
				// keeps its automation settings. Every control is disabled and
				// carries no name attribute — nothing here is read on save and
				// none of the features below have any code in this edition.
				?>
				<div class="nota-inv-card" id="nota-inv-section-automation">
				<h2 class="title"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Automation', 'nota-invoice-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'PDF delivery', 'nota-invoice-sync' ); ?></th>
						<td>
							<fieldset disabled="disabled" style="opacity:.55;">
								<label>
									<input type="checkbox" />
									<?php esc_html_e( 'Attach the invoice PDF to WooCommerce order emails', 'nota-invoice-sync' ); ?>
								</label>
								<br />
								<label>
									<input type="checkbox" />
									<?php esc_html_e( 'Let customers download the invoice PDF from My Account', 'nota-invoice-sync' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment reminders', 'nota-invoice-sync' ); ?></th>
						<td>
							<fieldset disabled="disabled" style="opacity:.55;">
								<label>
									<input type="checkbox" />
									<?php esc_html_e( 'Automatically send payment reminders for overdue finalised invoices', 'nota-invoice-sync' ); ?>
								</label>
								<p class="description nota-inv-inline-fields">
									<label>
										<?php esc_html_e( 'Stage 1 after', 'nota-invoice-sync' ); ?>
										<input type="number" class="small-text" value="7" />
										<?php esc_html_e( 'days overdue', 'nota-invoice-sync' ); ?>
									</label>
									<label>
										<?php esc_html_e( 'Stage 2 after', 'nota-invoice-sync' ); ?>
										<input type="number" class="small-text" value="14" />
										<?php esc_html_e( 'days', 'nota-invoice-sync' ); ?>
									</label>
									<label>
										<?php esc_html_e( 'Stage 3 after', 'nota-invoice-sync' ); ?>
										<input type="number" class="small-text" value="21" />
										<?php esc_html_e( 'days', 'nota-invoice-sync' ); ?>
									</label>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment status sync', 'nota-invoice-sync' ); ?></th>
						<td>
							<fieldset disabled="disabled" style="opacity:.55;">
								<label>
									<input type="checkbox" />
									<?php esc_html_e( 'Check twice daily whether Lexware Office already shows an invoice as paid', 'nota-invoice-sync' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
				<p class="description">
					<strong>
						<a href="https://www.wp-nota.com/lexware-invoice-sync" target="_blank" rel="noopener">
							<?php esc_html_e( 'These automation features are available in Nota Invoice Sync Pro', 'nota-invoice-sync' ); ?> →
						</a>
					</strong>
				</p>
				</div>

				<div class="nota-inv-card" id="nota-inv-section-diagnostics">
				<h2 class="title"><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Diagnostics', 'nota-invoice-sync' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Order notes', 'nota-invoice-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nota_inv[write_order_note]" value="1" <?php checked( $s['write_order_note'], 'yes' ); ?> />
								<?php esc_html_e( 'Add the invoice number as an order note', 'nota-invoice-sync' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test mode', 'nota-invoice-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nota_inv[test_mode]" value="1" <?php checked( $s['test_mode'], 'yes' ); ?> />
								<?php esc_html_e( 'Build the invoice payload and write it to the log, but do not send it', 'nota-invoice-sync' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logging', 'nota-invoice-sync' ); ?></th>
						<td>
							<select name="nota_inv[log_level]">
								<option value="off" <?php selected( $s['log_level'], 'off' ); ?>><?php esc_html_e( 'Off', 'nota-invoice-sync' ); ?></option>
								<option value="error" <?php selected( $s['log_level'], 'error' ); ?>><?php esc_html_e( 'Errors only', 'nota-invoice-sync' ); ?></option>
								<option value="debug" <?php selected( $s['log_level'], 'debug' ); ?>><?php esc_html_e( 'Everything (debug)', 'nota-invoice-sync' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Logs appear under WooCommerce → Status → Logs, source "nota-invoice-sync".', 'nota-invoice-sync' ); ?>
								<?php esc_html_e( 'Debug logging includes customer names, addresses and invoice contents in plain text — turn it off once things are working.', 'nota-invoice-sync' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'nota-invoice-sync' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="nota_inv[uninstall_remove_all]" value="1" <?php checked( $s['uninstall_remove_all'], 'yes' ); ?> />
								<?php esc_html_e( 'Remove all plugin data, including the order ↔ invoice references', 'nota-invoice-sync' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'By default, deleting the plugin only removes its settings — the record of which order maps to which Lexware invoice number is kept, so that history is not lost if the plugin is reinstalled later. Enable this only if you want a completely clean uninstall.', 'nota-invoice-sync' ); ?>
								<br />
								<?php esc_html_e( 'Either way, nothing is ever changed or deleted in Lexware Office itself.', 'nota-invoice-sync' ); ?>
							</p>
						</td>
					</tr>
				</table>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary" name="nota_inv_action" value="save">
						<?php esc_html_e( 'Save changes', 'nota-invoice-sync' ); ?>
					</button>
					<button type="submit" class="button" name="nota_inv_action" value="save_and_test">
						<?php esc_html_e( 'Save and test connection', 'nota-invoice-sync' ); ?>
					</button>
				</p>
			</form>

			<?php
			// Pro teaser only: every control below is disabled and carries no
			// name attribute, so nothing is ever posted — no export code
			// exists anywhere in this edition.
			?>
			<div class="nota-inv-card">
			<h2 class="title"><span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e( 'Compliance export', 'nota-invoice-sync' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'A CSV listing every order with a finalised Lexware Office invoice — invoice number, status, e-invoice format, amounts, payment date and any credit notes, ready to hand to your Steuerberater or keep as a GoBD audit reference.', 'nota-invoice-sync' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Order date range', 'nota-invoice-sync' ); ?></th>
					<td>
						<input type="date" class="regular-text" style="opacity:.55;" disabled="disabled" />
						<?php esc_html_e( 'to', 'nota-invoice-sync' ); ?>
						<input type="date" class="regular-text" style="opacity:.55;" disabled="disabled" />
					</td>
				</tr>
			</table>
			<p>
				<button type="button" class="button" disabled="disabled"><?php esc_html_e( 'Export compliance report', 'nota-invoice-sync' ); ?></button>
			</p>
			<p class="description">
				<strong>
					<a href="https://www.wp-nota.com/lexware-invoice-sync" target="_blank" rel="noopener">
						<?php esc_html_e( 'The compliance export is available in Nota Invoice Sync Pro', 'nota-invoice-sync' ); ?> →
					</a>
				</strong>
			</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Show the outcome of a connection test, including the OSS compatibility
	 * check that compares the Lexware distance-sales principle with the shop.
	 *
	 * @return void
	 */
	private function render_test_result() {
		if ( null === $this->test_result ) {
			return;
		}

		if ( is_wp_error( $this->test_result ) ) {
			echo '<div class="notice notice-error"><p><strong>';
			esc_html_e( 'Connection failed:', 'nota-invoice-sync' );
			echo '</strong> ' . esc_html( $this->test_result->get_error_message() ) . '</p></div>';
			return;
		}

		$profile      = $this->test_result;
		$company      = isset( $profile['companyName'] ) ? $profile['companyName'] : '';
		$principle    = isset( $profile['distanceSalesPrinciple'] ) ? $profile['distanceSalesPrinciple'] : '';
		$small_biz    = ! empty( $profile['smallBusiness'] );

		echo '<div class="notice notice-success"><p><strong>';
		esc_html_e( 'Connected to Lexware Office.', 'nota-invoice-sync' );
		echo '</strong> ';
		if ( $company ) {
			echo esc_html(
				sprintf(
					/* translators: %s: Lexware organisation name. */
					__( 'Organisation: %s', 'nota-invoice-sync' ),
					$company
				)
			);
		}
		echo '</p>';

		if ( $small_biz ) {
			echo '<p>' . esc_html__( 'This organisation is flagged as a small business (Kleinunternehmer, §19 UStG). Invoices will be created without VAT.', 'nota-invoice-sync' ) . '</p>';
		}

		echo '</div>';

		$this->render_oss_notice( $principle );
	}

	/**
	 * Compare the Lexware distance-sales principle against the shop's tax
	 * behaviour and warn when the two disagree.
	 *
	 * This is the mismatch that silently breaks cross-border invoices: the
	 * shop charges destination-country VAT while Lexware expects German VAT.
	 *
	 * @param string $principle ORIGIN or DESTINATION.
	 * @return void
	 */
	private function render_oss_notice( $principle ) {
		if ( '' === $principle ) {
			return;
		}

		$shop_uses_oss = $this->shop_charges_destination_vat();

		if ( 'DESTINATION' === $principle ) {
			echo '<div class="notice notice-info"><p>';
			esc_html_e( 'Lexware Office is set to destination-country VAT (One Stop Shop). Cross-border B2C orders will be sent with the destination country\'s tax rate.', 'nota-invoice-sync' );
			echo '</p></div>';
			return;
		}

		// ORIGIN.
		if ( $shop_uses_oss ) {
			echo '<div class="notice notice-warning"><p><strong>';
			esc_html_e( 'Tax setup mismatch', 'nota-invoice-sync' );
			echo '</strong></p><p>';
			esc_html_e( 'Your shop applies destination-country VAT to EU orders (One Stop Shop), but your Lexware Office account is set to German VAT ("Deutsche Umsatzsteuer"). Cross-border B2C orders cannot be invoiced correctly while these two disagree — Lexware will reject the foreign tax rate.', 'nota-invoice-sync' );
			echo '</p><p>';
			esc_html_e( 'Either switch Lexware Office to destination-country VAT, or stop charging destination VAT in WooCommerce. Please discuss this with your tax adviser before changing anything. Domestic orders are unaffected.', 'nota-invoice-sync' );
			echo '</p></div>';
			return;
		}

		echo '<div class="notice notice-info"><p>';
		esc_html_e( 'Lexware Office is set to German VAT for EU distance sales. This matches your current shop configuration.', 'nota-invoice-sync' );
		echo '</p></div>';
	}

	/**
	 * Detect whether the shop is charging destination-country VAT for the EU.
	 *
	 * Rather than sniffing for a specific plugin, this looks at the actual tax
	 * rate table: if EU countries other than the shop base country have their
	 * own standard rates defined, the shop is effectively on OSS.
	 *
	 * @return bool
	 */
	private function shop_charges_destination_vat() {
		global $wpdb;

		$base = WC()->countries ? WC()->countries->get_base_country() : 'DE';

		$eu = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE' );
		$eu = array_diff( $eu, array( $base ) );

		if ( empty( $eu ) ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $eu ), '%s' ) );

		// Cached briefly: this queries WooCommerce's own tax rate table
		// directly (there is no dedicated API for "which countries have a
		// standard rate configured"), and the answer changes rarely enough
		// that a short cache avoids a direct query on every settings-page
		// load without risking a stale OSS-mismatch warning for long.
		$cache_key = 'nota_inv_oss_check_' . md5( $base . implode( ',', $eu ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a string of %s repeated exactly count($eu) times, matched 1:1 by array_values($eu) below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- no WooCommerce API exposes tax rate country lookups; result is cached above via transient.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates
				 WHERE tax_rate_country IN ( {$placeholders} )
				 AND tax_rate > 0",
				array_values( $eu )
			)
		);
		// phpcs:enable

		$result = $count > 0;
		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

		return $result;
	}
}
