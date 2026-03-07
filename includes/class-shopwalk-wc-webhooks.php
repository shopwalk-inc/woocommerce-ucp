<?php
/**
 * Order webhooks — fires UCP Order events to Shopwalk on WC order status changes.
 *
 * All webhooks are sent to the canonical Shopwalk endpoint:
 * POST https://api.shopwalk.com/api/v1/ucp/webhooks/orders
 *
 * Payload: full UCP Order object (not a delta) with Request-Signature JWT header.
 * Signing: EC P-256 detached JWT (RFC 7797) using store's keypair from Shopwalk_WC_Profile.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Webhooks class.
 */
class Shopwalk_WC_Webhooks {

	/** Canonical Shopwalk UCP order webhook endpoint. */
	private const SHOPWALK_WEBHOOK_URL = 'https://api.shopwalk.com/api/v1/ucp/webhooks/orders';

	/**
	 * Construct.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
	}

	/**
	 * Fires when a WC order status changes.
	 * Only fires for Shopwalk-originated orders (_shopwalk_status meta is set).
	 *
	 * @param int      $order_id   WC order ID.
	 * @param string   $old_status Previous WC status (without "wc-" prefix).
	 * @param string   $new_status New WC status.
	 * @param WC_Order $order      WC order object.
	 */
	public function on_order_status_changed( int $order_id, string $old_status, string $new_status, WC_Order $order ): void {
		// Only fire for Shopwalk-originated orders.
		if ( ! $order->get_meta( '_shopwalk_session_id' ) ) {
			return;
		}

		$ucp_event = $this->map_status_to_ucp_event( $new_status );
		if ( ! $ucp_event ) {
			return;
		}

		$payload = $this->build_ucp_order_payload( $order, $ucp_event );
		$this->send_webhook( $payload );
	}

	/**
	 * Map a WC order status to a UCP fulfillment event type.
	 * Returns null if no webhook should be sent for this status.
	 *
	 * @param string $wc_status Parameter.
	 */
	private function map_status_to_ucp_event( string $wc_status ): ?string {
		return match ( $wc_status ) {
			'processing', 'on-hold' => 'processing',
			'completed'             => 'delivered',
			'cancelled'             => 'canceled',
			'refunded'              => 'refunded',
			default                 => null,
		};
	}

	/**
	 * Build a full UCP Order object for the webhook payload.
	 * Shopwalk expects the complete order on every event — not a delta.
	 *
	 * @param WC_Order $order Parameter.
	 * @param string   $event_type Parameter.
	 */
	private function build_ucp_order_payload( WC_Order $order, string $event_type ): array {
		// Line items.
		$line_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product      = $item->get_product();
			$qty          = max( $item->get_quantity(), 1 );
			$line_items[] = array(
				'id'       => (string) $item_id,
				'item'     => array(
					'id'    => (string) ( $product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : 0 ),
					'title' => $item->get_name(),
					'price' => (int) round( $item->get_subtotal() / $qty * 100 ),
				),
				'quantity' => $item->get_quantity(),
				'totals'   => array(
					array(
						'type'   => 'subtotal',
						'amount' => (int) round( $item->get_subtotal() * 100 ),
					),
					array(
						'type'   => 'tax',
						'amount' => (int) round( $item->get_subtotal_tax() * 100 ),
					),
				),
			);
		}

		// Fulfillment events.
		$fulfillment_events = array(
			array(
				'type'        => $event_type,
				'occurred_at' => gmdate( 'c' ),
			),
		);

		// Tracking info.
		$tracking_items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $tracking_items ) ) {
			foreach ( $tracking_items as $track ) {
				$fulfillment_events[] = array(
					'type'            => 'shipped',
					'occurred_at'     => $track['date_shipped'] ?? gmdate( 'c' ),
					'tracking_number' => $track['tracking_number'] ?? '',
					'tracking_url'    => $track['tracking_link'] ?? '',
					'carrier'         => $track['tracking_provider'] ?? '',
				);
			}
		}

		// Adjustments (refunds).
		$adjustments = array();
		foreach ( $order->get_refunds() as $refund ) {
			$adjustments[] = array(
				'type'   => 'refund',
				'amount' => (int) round( abs( (float) $refund->get_total() ) * 100 ),
				'reason' => $refund->get_reason(),
			);
		}

		return array(
			'ucp'           => array( 'version' => '2026-01-23' ),
			'id'            => 'ord_' . $order->get_id(),
			'checkout_id'   => $order->get_meta( '_shopwalk_session_id' ) ? $order->get_meta( '_shopwalk_session_id' ) : ( 'chk_' . $order->get_id() ),
			'permalink_url' => $order->get_view_order_url(),
			'line_items'    => $line_items,
			'fulfillment'   => array(
				'methods' => array(
					array(
						'type'   => 'shipping',
						'events' => $fulfillment_events,
					),
				),
			),
			'adjustments'   => $adjustments,
			'totals'        => array(
				array(
					'type'   => 'subtotal',
					'amount' => (int) round( (float) $order->get_subtotal() * 100 ),
				),
				array(
					'type'   => 'tax',
					'amount' => (int) round( (float) $order->get_total_tax() * 100 ),
				),
				array(
					'type'   => 'shipping',
					'amount' => (int) round( (float) $order->get_shipping_total() * 100 ),
				),
				array(
					'type'   => 'discount',
					'amount' => (int) round( (float) $order->get_discount_total() * 100 ),
				),
				array(
					'type'   => 'total',
					'amount' => (int) round( (float) $order->get_total() * 100 ),
				),
			),
			'event_id'      => wp_generate_uuid4(),
			'created_time'  => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : gmdate( 'c' ),
		);
	}

	/**
	 * Send the UCP Order payload to Shopwalk.
	 * Adds Request-Signature JWT header (detached EC P-256).
	 *
	 * @param array $payload Parameter.
	 */
	private function send_webhook( array $payload ): void {
		$plugin_key = get_option( 'shopwalk_wc_plugin_key', '' );
		if ( empty( $plugin_key ) ) {
			return; // Not connected — skip.
		}

		$json      = wp_json_encode( $payload );
		$signature = Shopwalk_WC_Profile::sign_payload( $json );

		$headers = array(
			'Content-Type'           => 'application/json',
			'X-API-Key'              => $plugin_key,
			'X-Shopwalk-Merchant-ID' => Shopwalk_WC_Profile::get_merchant_id(),
			'UCP-Agent'              => 'profile="' . home_url( '/.well-known/ucp' ) . '"',
			'X-Event-ID'             => $payload['event_id'] ?? '',
		);

		if ( ! empty( $signature ) ) {
			$headers['Request-Signature'] = $signature;
		}

		wp_remote_post(
			self::SHOPWALK_WEBHOOK_URL,
			array(
				'headers'  => $headers,
				'body'     => $json,
				'timeout'  => 10,
				'blocking' => false, // Fire-and-forget.
			)
		);
	}
}
