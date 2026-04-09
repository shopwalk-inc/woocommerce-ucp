<?php
/**
 * UCP Payment Gateway — registers "Pay via UCP" with WooCommerce.
 *
 * The actual payment processing happens inside the UCP_Checkout::complete_session
 * handler — it extracts the payment credential from the request body and
 * routes it to the appropriate payment processor (Stripe PaymentMethod ID,
 * etc.). This gateway exists so that the WC order has a recognizable payment
 * method assigned, which keeps WC reports, refunds, and admin UI working
 * correctly.
 *
 * The gateway is hidden from the storefront — `is_available` returns false
 * because UCP checkouts go through the `/wp-json/ucp/v1/checkout-sessions`
 * flow, never the WC native checkout page.
 *
 * The actual gateway class extends WC_Payment_Gateway, which doesn't exist
 * until WooCommerce has loaded. So we declare it inside a `woocommerce_init`
 * callback rather than at file-parse time.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap the gateway registration. Called from UCP_Bootstrap.
 *
 * @return void
 */
function shopwalk_ucp_register_payment_gateway(): void {
	add_action( 'woocommerce_init', 'shopwalk_ucp_define_payment_gateway_class' );
	add_filter(
		'woocommerce_payment_gateways',
		static function ( array $gateways ): array {
			if ( class_exists( 'Shopwalk_UCP_Payment_Gateway' ) ) {
				$gateways[] = 'Shopwalk_UCP_Payment_Gateway';
			}
			return $gateways;
		}
	);
}

/**
 * Declare Shopwalk_UCP_Payment_Gateway as a runtime class. Hooked to
 * `woocommerce_init` so WC_Payment_Gateway is available.
 *
 * @return void
 */
function shopwalk_ucp_define_payment_gateway_class(): void {
	if ( class_exists( 'Shopwalk_UCP_Payment_Gateway' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}
	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
	class Shopwalk_UCP_Payment_Gateway extends WC_Payment_Gateway {

		/**
		 * Constructor — set IDs, titles, descriptions.
		 */
		public function __construct() {
			$this->id                 = 'shopwalk_ucp';
			$this->method_title       = __( 'Pay via UCP', 'shopwalk-ai' );
			$this->method_description = __( 'Universal Commerce Protocol — orders placed by AI shopping agents through the UCP checkout-sessions API.', 'shopwalk-ai' );
			$this->title              = __( 'Pay via UCP', 'shopwalk-ai' );
			$this->description        = __( 'AI agent checkout via UCP. Used by Shopwalk and other UCP-compliant agents.', 'shopwalk-ai' );
			$this->has_fields         = false;
			$this->supports           = array( 'products', 'refunds' );
			$this->enabled            = (string) get_option( 'shopwalk_ucp_gateway_enabled', 'yes' );

			$this->init_form_fields();
			$this->init_settings();

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/**
		 * Settings form. Minimal — just an enable toggle.
		 *
		 * @return void
		 */
		public function init_form_fields(): void {
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __( 'Enable / Disable', 'shopwalk-ai' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Pay via UCP', 'shopwalk-ai' ),
					'default' => 'yes',
				),
			);
		}

		/**
		 * UCP gateway is never displayed on the WC storefront — agents use
		 * the /checkout-sessions REST flow, not the native checkout page.
		 *
		 * @return bool
		 */
		public function is_available(): bool {
			return false;
		}

		/**
		 * No-op processor. Orders created via the UCP /complete handler
		 * are already marked processing, so this is never called in the
		 * normal flow.
		 *
		 * @param int $order_id WC order id.
		 * @return array<string,mixed>
		 */
		public function process_payment( $order_id ): array {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_status( 'processing', 'UCP order auto-marked processing.' );
			}
			return array(
				'result'   => 'success',
				'redirect' => '',
			);
		}
	}
	// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
}
