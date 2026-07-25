<?php
/**
 * Central settings store. Single source of truth for all plugin options.
 *
 * @package Nota_Invoice_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nota_Inv_Settings {

	/**
	 * @var Nota_Inv_Settings|null
	 */
	private static $instance = null;

	/**
	 * Cached settings array.
	 *
	 * @var array|null
	 */
	private $settings = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			// Connection.
			'auth_method'          => 'apikey', // 'apikey' now, 'oauth' later.
			'api_key'              => '',

			// Behaviour.
			'trigger_statuses'     => array( 'processing' ), // which statuses create an invoice.
			'finalize'             => 'no',         // no = draft, yes = finalized.
			'language'             => 'de',         // de | en. Fallback when auto-detect is off or fails.
			'language_auto_detect' => 'no',         // yes = try WPML/Polylang order language first.
			'payment_term_days'    => '',           // empty = use Lexware default.
			'supply_date_offset'   => '0',          // days added to the base date for shippingConditions/voucherDate.
			'supply_date_offset_end' => '',         // empty = single date; set = end of a delivery range (days after order).
			'shipping_as_line'     => 'yes',
			'write_order_note'     => 'yes',

			// Texts (empty = Lexware organisation default).
			'invoice_title'        => '',
			'invoice_introduction' => '',
			'invoice_remark'       => '',

			// Diagnostics.
			'test_mode'            => 'no',   // yes = build payload + log only, no API write.
			'log_level'            => 'error', // off | error | debug.

			// Uninstall.
			'uninstall_remove_all' => 'no',   // no = keep order↔invoice references, yes = remove everything.
		);
	}

	/**
	 * Get all settings, merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->settings ) {
			$stored = get_option( NOTA_INV_OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			// Migrate the original single-status setting.
			if ( isset( $stored['trigger_status'] ) && ! isset( $stored['trigger_statuses'] ) ) {
				$stored['trigger_statuses'] = 'manual' === $stored['trigger_status']
					? array()
					: array( $stored['trigger_status'] );
				unset( $stored['trigger_status'] );
			}

			$this->settings = wp_parse_args( $stored, $this->defaults() );

			if ( ! is_array( $this->settings['trigger_statuses'] ) ) {
				$this->settings['trigger_statuses'] = array();
			}
		}
		return $this->settings;
	}

	/**
	 * Should an invoice be created when an order reaches this status?
	 *
	 * @param string $status Status slug without the wc- prefix.
	 * @return bool
	 */
	public function triggers_on( $status ) {
		return in_array( $status, (array) $this->get( 'trigger_statuses', array() ), true );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value returned when key is unknown.
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Boolean helper for 'yes'/'no' settings.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is( $key ) {
		return 'yes' === $this->get( $key );
	}

	/**
	 * Persist a full settings array (already sanitised).
	 *
	 * @param array $values Settings.
	 * @return void
	 */
	public function save( array $values ) {
		$clean = wp_parse_args( $values, $this->defaults() );
		update_option( NOTA_INV_OPTION, $clean );
		$this->settings = $clean;
	}

	/**
	 * Resolve the API key.
	 *
	 * A constant in wp-config.php always wins over the database value, so
	 * site owners can keep the credential out of the database entirely:
	 *
	 *     define( 'NOTA_INV_API_KEY', 'your-key-here' );
	 *
	 * @return string
	 */
	public function get_api_key() {
		if ( defined( 'NOTA_INV_API_KEY' ) && NOTA_INV_API_KEY ) {
			return (string) NOTA_INV_API_KEY;
		}
		return (string) $this->get( 'api_key', '' );
	}

	/**
	 * Whether the API key comes from wp-config.php rather than the database.
	 *
	 * @return bool
	 */
	public function api_key_is_constant() {
		return defined( 'NOTA_INV_API_KEY' ) && NOTA_INV_API_KEY;
	}

	/**
	 * Is the plugin configured well enough to talk to the API?
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== trim( $this->get_api_key() );
	}
}
