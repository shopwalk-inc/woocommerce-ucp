<?php
/**
 * UCP Payment Gateway — registers "Pay via UCP" with WooCommerce.
 *
 * Orders created by UCP_Checkout::complete_session are labeled with this
 * gateway so WC reports, refunds, and the admin Orders table recognize the
 * order source. The plugin itself does NOT process payment — payment is
 * completed by the buyer on the store's native checkout page using whatever
 * WooCommerce payment gateway the merchant has configured (WC Stripe, WC
 * PayPal, Square, Authorize.net, Amazon Pay, …). The session object's
 * `order.payment_url` hands off that completion step.
 *
 * The gateway is hidden from the storefront — `is_available` returns false
 * because UCP-initiated orders arrive pre-built via the REST API and don't
 * use the WC checkout form's payment method selector.
 *
 * The actual gateway class extends WC_Payment_Gateway, which doesn't exist
 * until WooCommerce has loaded. So we declare it inside a `woocommerce_init`
 * callback rather than at file-parse time.
 *
 * @package ShopwalkWooCommerce
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
			$this->method_title       = __( 'Pay via UCP', 'shopwalk-for-woocommerce' );
			$this->method_description = __( 'Universal Commerce Protocol — orders placed by AI shopping agents through the UCP checkout-sessions API.', 'shopwalk-for-woocommerce' );
			$this->title              = __( 'Pay via UCP', 'shopwalk-for-woocommerce' );
			$this->description        = __( 'AI agent checkout via UCP. Used by Shopwalk and other UCP-compliant agents.', 'shopwalk-for-woocommerce' );
			$this->has_fields         = false;
			$this->supports           = array( 'products', 'refunds' );
			$this->enabled            = (string) get_option( 'shopwalk_gateway_enabled', 'yes' );

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
					'title'   => __( 'Enable / Disable', 'shopwalk-for-woocommerce' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Pay via UCP', 'shopwalk-for-woocommerce' ),
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
