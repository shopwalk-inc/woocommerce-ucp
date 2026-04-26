<?php
/**
 * UCP Payment Router — dispatches agent-submitted payment credentials to
 * the right WooCommerce-side adapter so the plugin never has to own
 * payment configuration of its own.
 *
 * Agents submit `{ "payment": { "gateway": "stripe", ... } }` in the
 * UCP /checkout-sessions/{id}/complete body. The router looks up the
 * adapter registered for that gateway id and asks it to authorize the
 * payment against the merchant's already-configured WooCommerce payment
 * gateway (WC Stripe, WC PayPal, Square, etc.) — reusing whatever
 * credentials that gateway already has.
 *
 * Adapters are registered via the `shopwalk_ucp_payment_adapters` filter
 * so third parties can add support for additional gateways without
 * touching plugin core.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Payment_Router — central adapter lookup + dispatch.
 *
 * Implements the contract in interface-ucp-payment-adapter.php (loaded
 * by the bootstrap before this class).
 */
final class UCP_Payment_Router {

	/**
	 * Default adapter registry. Third parties extend via the
	 * `shopwalk_ucp_payment_adapters` filter.
	 *
	 * @return array<string,string> gateway id → adapter class name
	 */
	private static function defaults(): array {
		return array(
			'stripe' => UCP_Payment_Adapter_Stripe::class,
		);
	}

	/**
	 * Resolved, filter-aware adapter map. Values are class names, not
	 * instances — adapters are cheap to construct on demand.
	 *
	 * @return array<string,string>
	 */
	public static function registry(): array {
		/**
		 * Filter the registered UCP payment adapters.
		 *
		 * Example — add a PayPal adapter:
		 *
		 *     add_filter( 'shopwalk_ucp_payment_adapters', function ( $a ) {
		 *         $a['ppcp'] = 'My_PPCP_UCP_Adapter';
		 *         return $a;
		 *     } );
		 *
		 * @param array<string,string> $adapters gateway id → class name.
		 */
		$adapters = apply_filters( 'shopwalk_ucp_payment_adapters', self::defaults() );

		// Drop anything that doesn't actually resolve to a loadable class.
		return array_filter( (array) $adapters, 'class_exists' );
	}

	/**
	 * The list the discovery doc advertises. Filters out adapters that
	 * are registered but not usable right now (e.g. the gateway plugin
	 * is installed but has no keys configured).
	 *
	 * @return array<string,array>
	 */
	public static function discovery_hints(): array {
		$out = array();
		foreach ( self::registry() as $id => $class ) {
			$adapter = new $class();
			if ( ! $adapter instanceof UCP_Payment_Adapter_Interface ) {
				continue;
			}
			if ( ! $adapter->is_ready() ) {
				continue;
			}
			$out[ $id ] = $adapter->discovery_hint();
		}
		return $out;
	}

	/**
	 * Dispatch payment for a given order + UCP payment object.
	 *
	 * @param WC_Order $order   The WC order.
	 * @param array    $payment UCP payment credential.
	 * @return true|WP_Error
	 */
	public static function authorize( $order, array $payment ) {
		$gateway = isset( $payment['gateway'] ) ? (string) $payment['gateway'] : '';
		if ( '' === $gateway ) {
			return new WP_Error(
				'missing_gateway',
				'payment.gateway is required — specify which WooCommerce payment gateway to use (e.g. "stripe").',
				array( 'status' => 400 )
			);
		}

		$registry = self::registry();
		if ( ! isset( $registry[ $gateway ] ) ) {
			$supported = array_keys( $registry );
			return new WP_Error(
				'unsupported_gateway',
				sprintf( 'No adapter registered for payment gateway "%s". This store accepts: %s.', $gateway, implode( ', ', $supported ) ?: 'none' ),
				array( 'status' => 422 )
			);
		}

		$adapter = new $registry[ $gateway ]();
		if ( ! $adapter instanceof UCP_Payment_Adapter_Interface ) {
			return new WP_Error( 'invalid_adapter', 'Adapter class does not implement UCP_Payment_Adapter_Interface.', array( 'status' => 500 ) );
		}
		if ( ! $adapter->is_ready() ) {
			return new WP_Error(
				'gateway_not_ready',
				sprintf( 'Payment gateway "%s" is registered but not configured on this store.', $gateway ),
				array( 'status' => 503 )
			);
		}

		return $adapter->authorize( $order, $payment );
	}
}
