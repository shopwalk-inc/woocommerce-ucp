<?php
/**
 * UCP Payment Adapter — Stripe.
 *
 * Reuses the merchant's WooCommerce Stripe Gateway credentials — the
 * plugin itself never asks the merchant for a Stripe secret key. Agents
 * submit a Stripe PaymentMethod id (`pm_xxx`) they've already tokenized
 * on their side; this adapter authorizes it as a PaymentIntent with
 * manual capture against the merchant's existing Stripe connection.
 *
 * On success the WC order advances to `processing` via `payment_complete()`
 * so WC's own order lifecycle, reports, and webhook hooks all behave
 * identically to a native Stripe checkout — the only difference is how
 * the session was created.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stripe adapter.
 */
final class UCP_Payment_Adapter_Stripe implements UCP_Payment_Adapter_Interface {

	/**
	 * WC Stripe Gateway option key. Both the official WC Stripe plugin
	 * and WooPayments-derived forks write to this option.
	 */
	private const WC_STRIPE_SETTINGS = 'woocommerce_stripe_settings';

	/**
	 * {@inheritdoc}
	 */
	public function id(): string {
		return 'stripe';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_ready(): bool {
		return '' !== $this->secret_key();
	}

	/**
	 * {@inheritdoc}
	 */
	public function discovery_hint(): array {
		$settings = (array) get_option( self::WC_STRIPE_SETTINGS, array() );
		$testmode = ( $settings['testmode'] ?? 'no' ) === 'yes';

		return array(
			'gateway'         => 'stripe',
			'credential'      => 'payment_method_id',
			'tokenize_from'   => 'https://js.stripe.com/v3/',
			'publishable_key' => $testmode
				? (string) ( $settings['test_publishable_key'] ?? '' )
				: (string) ( $settings['publishable_key'] ?? '' ),
			'mode'            => $testmode ? 'test' : 'live',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function authorize( $order, array $payment ) {
		$secret_key = $this->secret_key();
		if ( '' === $secret_key ) {
			return new WP_Error(
				'stripe_not_configured',
				'WooCommerce Stripe Gateway is not installed or has no secret key configured.',
				array( 'status' => 503 )
			);
		}

		$pm_id = (string) (
			$payment['payment_method_id']
				?? $payment['stripe_payment_method_id']
				?? $payment['credential']['payment_method_id']
				?? ''
		);
		if ( '' === $pm_id ) {
			return new WP_Error(
				'missing_payment_method',
				'payment.payment_method_id (a Stripe PaymentMethod id like "pm_…") is required for the stripe gateway.',
				array( 'status' => 400 )
			);
		}

		$amount   = (int) round( $order->get_total() * 100 );
		$currency = strtolower( $order->get_currency() );

		$body = array(
			'amount'             => $amount,
			'currency'           => $currency,
			'payment_method'     => $pm_id,
			'capture_method'     => 'manual',
			'confirm'            => 'true',
			'description'        => sprintf( 'Order #%d via UCP', $order->get_id() ),
			'metadata[order_id]' => (string) $order->get_id(),
			'metadata[source]'   => 'ucp',
		);

		$customer_id = (string) ( $payment['customer_id'] ?? $payment['stripe_customer_id'] ?? '' );
		if ( '' !== $customer_id ) {
			$body['customer'] = $customer_id;
		}

		// Idempotency-Key prevents duplicate PaymentIntents on retry. Derived
		// from order id + payment-method id so genuine retries of the same
		// (order, pm) tuple collapse to one charge, but a different pm (e.g.
		// the buyer corrected card details after a decline) gets a fresh
		// intent. Stripe stores the idempotency response for 24h and replays
		// it on subsequent identical requests.
		$idempotency_key = 'ucp_' . $order->get_id() . '_' . $pm_id;

		$response = wp_remote_post(
			'https://api.stripe.com/v1/payment_intents',
			array(
				'headers' => array(
					'Authorization'   => 'Bearer ' . trim( $secret_key ),
					'Content-Type'    => 'application/x-www-form-urlencoded',
					'Idempotency-Key' => $idempotency_key,
				),
				'body'    => $body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'stripe_unreachable',
				'Stripe API unreachable: ' . $response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 || ! is_array( $result ) || empty( $result['id'] ) ) {
			return new WP_Error(
				'stripe_declined',
				(string) ( $result['error']['message'] ?? 'Stripe declined the payment.' ),
				array( 'status' => 402 )
			);
		}

		if ( 'requires_capture' !== $result['status'] && 'succeeded' !== $result['status'] ) {
			// 3DS / off-session step required — not supported in fully-automated
			// agent flows without the buyer present. Surface cleanly so the
			// agent can fall back to a payment_url handoff if needed.
			return new WP_Error(
				'stripe_requires_action',
				'Payment requires additional buyer action (3D Secure). Resubmit with an already-confirmed PaymentMethod or hand the buyer order.payment_url.',
				array(
					'status'            => 402,
					'payment_intent_id' => (string) $result['id'],
					'next_action'       => $result['next_action'] ?? null,
				)
			);
		}

		$order->update_meta_data( '_stripe_payment_intent_id', (string) $result['id'] );
		$order->update_meta_data( '_stripe_charge_captured', 'no' ); // manual capture — captured at fulfillment
		$order->payment_complete( (string) $result['id'] );
		$order->add_order_note( sprintf( 'Authorized via UCP → WC Stripe credentials. PaymentIntent: %s (manual capture).', $result['id'] ) );

		return true;
	}

	/**
	 * Pull the WC Stripe Gateway's configured secret key for the active mode.
	 * Returns empty string if the gateway isn't installed / configured.
	 */
	private function secret_key(): string {
		$settings = get_option( self::WC_STRIPE_SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		$testmode = ( $settings['testmode'] ?? 'no' ) === 'yes';
		$key      = $testmode ? ( $settings['test_secret_key'] ?? '' ) : ( $settings['secret_key'] ?? '' );
		return is_string( $key ) ? $key : '';
	}
}
