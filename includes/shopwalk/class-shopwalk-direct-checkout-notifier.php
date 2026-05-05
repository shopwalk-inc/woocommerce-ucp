<?php
/**
 * Shopwalk_Direct_Checkout_Notifier — Tier 2 listener for the generic
 * `ucp_direct_checkout_order_status_changed` action emitted by Tier 1.
 *
 * Builds the signed webhook payload (HMAC-SHA256 + Content-Digest per RFC 9530)
 * and POSTs it to shopwalk-api. This file owns the URL, the wire format, and
 * the signing — Tier 1 (`includes/core/class-ucp-direct-checkout.php`) emits a
 * generic action and knows nothing about how subscribers notify their backends.
 *
 * Removing the `shopwalk/` directory leaves Tier 1 functional with zero
 * outbound HTTP traffic — the action simply has no listener.
 *
 * Wave 6 revocation safety: gated on `Shopwalk_License::status() === 'active'`,
 * matching the gate Wave 6 established in Shopwalk_Sync. Revoked/expired
 * licenses must not push outbound.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Direct_Checkout_Notifier — listens for the generic action and
 * dispatches the order-status webhook to shopwalk-api.
 */
final class Shopwalk_Direct_Checkout_Notifier {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up the action listener.
	 */
	private function __construct() {
		add_action(
			'ucp_direct_checkout_order_status_changed',
			array( $this, 'on_order_status_changed' ),
			10,
			5
		);
	}

	/**
	 * Action handler — dispatched by Tier 1 when a Shopwalk-originated
	 * Direct Checkout order changes status.
	 *
	 * @param object $order             WC_Order instance.
	 * @param int    $order_id          WC order ID.
	 * @param string $from              Previous WC status.
	 * @param string $to                New WC status.
	 * @param string $external_order_id The agent-side order id (currently the
	 *                                  Shopwalk order id, read from order meta).
	 * @return void
	 */
	public function on_order_status_changed( $order, int $order_id, string $from, string $to, string $external_order_id ): void {
		// Wave 6 revocation gate. Mirrors Shopwalk_Sync::flush(): even with a
		// stored license key, a revoked/expired license must not push outbound.
		if ( ! class_exists( 'Shopwalk_License' ) || 'active' !== Shopwalk_License::status() ) {
			return;
		}

		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}

		$license_key = (string) get_option( 'shopwalk_license_key', '' );
		if ( '' === $license_key ) {
			return;
		}

		self::dispatch_webhook( $order, $order_id, $from, $to, $external_order_id, $license_key );
	}

	/**
	 * Build the signed webhook payload and POST it to shopwalk-api.
	 *
	 * The wire format (payload fields, headers, signature scheme) MUST NOT
	 * change — this is a pure relocation from Tier 1 to Tier 2. The receiving
	 * end (shopwalk-api) expects exactly what was previously sent.
	 *
	 * @param object $order             WC_Order instance.
	 * @param int    $order_id          WC order ID.
	 * @param string $from              Previous WC status.
	 * @param string $to                New WC status.
	 * @param string $external_order_id The agent-side order id.
	 * @param string $license_key       The Shopwalk license key, used as the HMAC secret.
	 * @return void
	 */
	private static function dispatch_webhook( $order, int $order_id, string $from, string $to, string $external_order_id, string $license_key ): void {
		$api_url = defined( 'SHOPWALK_API_URL' ) ? SHOPWALK_API_URL : 'https://api.shopwalk.com';

		// Attempt to get tracking info from common tracking plugins.
		$tracking_number = '';
		$carrier         = '';

		// Support WooCommerce Shipment Tracking plugin.
		$tracking_items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $tracking_items ) && ! empty( $tracking_items ) ) {
			$last            = end( $tracking_items );
			$tracking_number = $last['tracking_number'] ?? '';
			$carrier         = $last['tracking_provider'] ?? '';
		}

		$payload = array(
			'event'             => 'order.status_changed',
			'order_id'          => $order_id,
			'shopwalk_order_id' => $external_order_id,
			'from_status'       => $from,
			'to_status'         => $to,
			'total'             => self::to_cents( (float) $order->get_total() ),
			'currency'          => $order->get_currency(),
			'tracking_number'   => $tracking_number,
			'carrier'           => $carrier,
		);

		$body       = wp_json_encode( $payload );
		$timestamp  = time();
		$webhook_id = 'evt_' . wp_generate_uuid4();
		$digest     = base64_encode( hash( 'sha256', $body, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for Content-Digest header per RFC 9530.

		// HMAC signature over the signed content using the license key.
		$signed_content = $webhook_id . '.' . $timestamp . '.' . $body;
		$signature      = base64_encode( hash_hmac( 'sha256', $signed_content, $license_key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HMAC-SHA256 webhook signature.

		wp_remote_post(
			$api_url . '/api/v1/ucp/webhooks/orders',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'X-License-Key'     => $license_key,
					'Webhook-Timestamp' => strval( $timestamp ),
					'Webhook-Id'        => $webhook_id,
					'UCP-Agent'         => 'profile="' . get_site_url() . '/.well-known/ucp"',
					'Content-Digest'    => 'sha-256=:' . $digest . ':',
					'Signature-Input'   => 'sig1=("content-digest" "webhook-id" "webhook-timestamp");keyid="store-hmac";alg="hmac-sha256"',
					'Signature'         => 'sig1=:' . $signature . ':',
				),
				'body'    => $body,
			)
		);
	}

	/**
	 * Convert a float dollar amount to integer cents.
	 *
	 * @param float $amount Dollar amount.
	 * @return int
	 */
	private static function to_cents( float $amount ): int {
		return (int) round( $amount * 100 );
	}
}
