<?php
/**
 * Pro teaser: a "Lexware" product data tab with per-product and
 * per-variation supply date override, plus a custom invoice line
 * description.
 *
 * Purely visual. Every field is disabled and carries no name attribute, so
 * nothing is ever posted or saved — no product/variation meta is read or
 * written anywhere in this edition, and class-invoice-builder.php has no
 * awareness of products at all. The real feature lives only in Pro, on the
 * same "Lexware" tab.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Admin_Product_Fields {

	/**
	 * @var Nota_Inv_Admin_Product_Fields|null
	 */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_tab_panel' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_teaser' ), 10, 1 );
	}

	/**
	 * Register the "Lexware" product data tab — same tab Pro uses, so the
	 * teaser sits exactly where the real fields would be.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public function add_tab( $tabs ) {
		$tabs['nota_inv'] = array(
			'label'    => __( 'Lexware', 'nota-invoice-sync' ),
			'target'   => 'nota_inv_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);
		return $tabs;
	}

	/**
	 * Disabled fields on the new tab, plus a single explanatory link — this
	 * is the only place the Pro link appears on this screen; variation rows
	 * just show the disabled field shape.
	 *
	 * @return void
	 */
	public function render_tab_panel() {
		echo '<div id="nota_inv_product_data" class="panel woocommerce_options_panel">';
		echo '<div class="options_group">';

		echo '<p class="form-field">';
		echo '<label>' . esc_html__( 'Lexware supply date (days)', 'nota-invoice-sync' ) . '</label>';
		echo '<input type="number" class="short" style="opacity:.55;" disabled="disabled" placeholder="0" />';
		echo '<span style="margin:0 6px;color:#8c8f94;">' . esc_html__( 'to', 'nota-invoice-sync' ) . '</span>';
		echo '<input type="number" class="short" style="opacity:.55;" disabled="disabled" placeholder="—" />';
		echo '</p>';

		echo '<p class="form-field">';
		echo '<label>' . esc_html__( 'Invoice line description', 'nota-invoice-sync' ) . '</label>';
		echo '<textarea class="short" rows="2" style="width:50%;opacity:.55;" disabled="disabled"></textarea>';
		echo '</p>';

		echo '<p class="form-field">';
		echo '<span class="description">';
		printf(
			/* translators: %s: link to Nota Invoice Sync Pro. */
			esc_html__( 'Per-product delivery date and invoice line text override — %s', 'nota-invoice-sync' ),
			'<a href="https://www.wp-nota.com/lexware-invoice-sync" target="_blank" rel="noopener">' . esc_html__( 'available in Nota Invoice Sync Pro', 'nota-invoice-sync' ) . ' →</a>'
		);
		echo '</span>';
		echo '</p>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Disabled fields on each variation row. No link here — the explanation
	 * and the one Pro link already live once on the Lexware tab above,
	 * repeating a full sentence on every variation row would be excessive.
	 *
	 * @return void
	 */
	public function render_variation_teaser() {
		echo '<div class="form-row form-row-full">';
		echo '<label>' . esc_html__( 'Lexware supply date (days)', 'nota-invoice-sync' ) . ' <span style="color:#8c8f94;">(Pro)</span></label>';
		echo '<input type="number" class="short" style="opacity:.55;" disabled="disabled" placeholder="0" />';
		echo '<span style="margin:0 6px;color:#8c8f94;">' . esc_html__( 'to', 'nota-invoice-sync' ) . '</span>';
		echo '<input type="number" class="short" style="opacity:.55;" disabled="disabled" placeholder="—" />';
		echo '</div>';
		echo '<div class="form-row form-row-full">';
		echo '<label>' . esc_html__( 'Invoice line description', 'nota-invoice-sync' ) . ' <span style="color:#8c8f94;">(Pro)</span></label>';
		echo '<textarea class="short" rows="2" style="width:100%;opacity:.55;" disabled="disabled"></textarea>';
		echo '</div>';
	}
}
