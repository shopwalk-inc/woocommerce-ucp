<?php
/**
 * UCP Direct Checkout — creates WooCommerce orders and returns a payment URL
 * so the customer completes payment on the store's native checkout page.
 *
 * Replaces the Stripe Connect flow — checkout routes to the store's own
 * payment gateway. The Shopwalk agent receives a payment_url that it presents
 * to the buyer.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Direct_Checkout — direct-to-store checkout.
 */
final class UCP_Direct_Checkout {

	/**
	 * Order TTL in seconds (30 min). Pending Shopwalk orders older than
	 * this are automatically cancelled by the hourly cron.
	 */
	private const ORDER_TTL = 1800;

	/**
	 * Cron hook name for cancelling expired orders.
	 */
	private const CRON_HOOK = 'shopwalk_ucp_direct_checkout_cleanup';

	/**
	 * Register REST routes, hooks, and cron.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'shopwalk-ucp/v1',
			'/checkout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_order' ),
				'permission_callback' => array( __CLASS__, 'check_license_key' ),
			)
		);

		// Order status change webhook to Shopwalk.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 10, 4 );

		// Redirect back to Shopwalk return_url for Shopwalk-originated orders.
		add_filter( 'woocommerce_get_return_url', array( __CLASS__, 'filter_return_url' ), 10, 2 );

		// Hourly cron — cancel expired pending Shopwalk orders.
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup_expired_orders' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'hourly', self::CRON_HOOK );
		}
	}

	// ── Permission ──────────────────────────────────────────────────────────

	/**
	 * Permission callback — validates the license key header.
	 *
	 * Accepts either `X-License-Key` or `X-SW-License-Key`.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error
	 */
	public static function check_license_key( WP_REST_Request $request ) {
		$header_key = $request->get_header( 'X-License-Key' );
		if ( ! $header_key ) {
			$header_key = $request->get_header( 'X-SW-License-Key' );
		}
		if ( ! $header_key || $header_key === '' ) {
			return new WP_Error(
				'missing_license_key',
				'A valid X-License-Key or X-SW-License-Key header is required.',
				array( 'status' => 401 )
			);
		}

		$stored_key = get_option( 'shopwalk_license_key', '' );
		if ( $stored_key === '' || $header_key !== $stored_key ) {
			return new WP_Error(
				'invalid_license_key',
				'License key does not match.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	// ── CREATE ORDER ────────────────────────────────────────────────────────

	/**
	 * POST /wp-json/shopwalk-ucp/v1/checkout — create a WooCommerce order
	 * and return a payment URL.
	 *
	 * Expected JSON body:
	 * {
	 *   "items": [
	 *     { "product_id": 42, "variant_id": 0, "quantity": 2 }
	 *   ],
	 *   "customer": {
	 *     "email": "...",
	 *     "first_name": "...",
	 *     "last_name": "...",
	 *     "phone": "..."
	 *   },
	 *   "shipping_address": {
	 *     "address_1": "...", "address_2": "", "city": "...",
	 *     "state": "...", "postcode": "...", "country": "US"
	 *   },
	 *   "shopwalk_agent_id": "agt_...",
	 *   "shopwalk_order_id": "uuid",
	 *   "return_url": "https://myshopwalk.com/orders/..."
	 * }
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_order( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'wc_unavailable', 'WooCommerce is not active.', array( 'status' => 503 ) );
		}

		$body  = $request->get_json_params() ?: array();
		$items = $body['items'] ?? array();

		if ( ! is_array( $items ) || count( $items ) === 0 ) {
			return new WP_Error( 'invalid_request', 'items[] is required and must be non-empty.', array( 'status' => 400 ) );
		}

		// ── Validate all products before creating the order ──────────────
		$validated = array();
		foreach ( $items as $idx => $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$variant_id = (int) ( $item['variant_id'] ?? 0 );
			$quantity   = (int) ( $item['quantity'] ?? 1 );

			if ( $product_id <= 0 ) {
				return new WP_Error(
					'invalid_product',
					sprintf( 'items[%d].product_id is required and must be a positive integer.', $idx ),
					array( 'status' => 400 )
				);
			}
			if ( $quantity <= 0 ) {
				return new WP_Error(
					'invalid_quantity',
					sprintf( 'items[%d].quantity must be at least 1.', $idx ),
					array( 'status' => 400 )
				);
			}

			// Resolve product — if variant_id is given, use that (it's a WC variation).
			$resolve_id = $variant_id > 0 ? $variant_id : $product_id;
			$product    = wc_get_product( $resolve_id );

			if ( ! $product ) {
				return new WP_Error(
					'product_not_found',
					sprintf( 'Product %d not found.', $resolve_id ),
					array( 'status' => 404 )
				);
			}
			if ( ! $product->is_purchasable() ) {
				return new WP_Error(
					'product_not_purchasable',
					sprintf( 'Product %d (%s) is not purchasable.', $resolve_id, $product->get_name() ),
					array( 'status' => 422 )
				);
			}
			if ( ! $product->is_in_stock() ) {
				return new WP_Error(
					'out_of_stock',
					sprintf( 'Product %d (%s) is out of stock.', $resolve_id, $product->get_name() ),
					array( 'status' => 422 )
				);
			}
			if ( $product->managing_stock() && $product->get_stock_quantity() < $quantity ) {
				return new WP_Error(
					'insufficient_stock',
					sprintf(
						'Product %d (%s) has only %d in stock (requested %d).',
						$resolve_id,
						$product->get_name(),
						$product->get_stock_quantity(),
						$quantity
					),
					array( 'status' => 422 )
				);
			}

			$validated[] = array(
				'product'  => $product,
				'quantity' => $quantity,
			);
		}

		// ── Create WooCommerce order ────────────────────────────────────
		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return new WP_Error( 'order_creation_failed', $order->get_error_message(), array( 'status' => 500 ) );
		}

		$response_items = array();
		foreach ( $validated as $entry ) {
			$product  = $entry['product'];
			$quantity = $entry['quantity'];
			$order->add_product( $product, $quantity );

			$response_items[] = array(
				'product_id' => $product->get_id(),
				'name'       => $product->get_name(),
				'quantity'   => $quantity,
				'unit_price' => self::to_cents( (float) $product->get_price() ),
				'subtotal'   => self::to_cents( (float) $product->get_price() * $quantity ),
			);
		}

		// ── Customer info ───────────────────────────────────────────────
		$customer = $body['customer'] ?? array();
		if ( is_array( $customer ) ) {
			if ( ! empty( $customer['email'] ) ) {
				$order->set_billing_email( sanitize_email( $customer['email'] ) );
			}
			if ( ! empty( $customer['first_name'] ) ) {
				$order->set_billing_first_name( sanitize_text_field( $customer['first_name'] ) );
			}
			if ( ! empty( $customer['last_name'] ) ) {
				$order->set_billing_last_name( sanitize_text_field( $customer['last_name'] ) );
			}
			if ( ! empty( $customer['phone'] ) ) {
				$order->set_billing_phone( sanitize_text_field( $customer['phone'] ) );
			}
		}

		// ── Shipping address ────────────────────────────────────────────
		$shipping = $body['shipping_address'] ?? array();
		if ( is_array( $shipping ) ) {
			$order->set_shipping_address_1( sanitize_text_field( $shipping['address_1'] ?? '' ) );
			$order->set_shipping_address_2( sanitize_text_field( $shipping['address_2'] ?? '' ) );
			$order->set_shipping_city( sanitize_text_field( $shipping['city'] ?? '' ) );
			$order->set_shipping_state( sanitize_text_field( $shipping['state'] ?? '' ) );
			$order->set_shipping_postcode( sanitize_text_field( $shipping['postcode'] ?? '' ) );
			$order->set_shipping_country( sanitize_text_field( $shipping['country'] ?? '' ) );

			// Copy to billing address if billing isn't set separately.
			if ( ! $order->get_billing_address_1() ) {
				$order->set_billing_address_1( sanitize_text_field( $shipping['address_1'] ?? '' ) );
				$order->set_billing_address_2( sanitize_text_field( $shipping['address_2'] ?? '' ) );
				$order->set_billing_city( sanitize_text_field( $shipping['city'] ?? '' ) );
				$order->set_billing_state( sanitize_text_field( $shipping['state'] ?? '' ) );
				$order->set_billing_postcode( sanitize_text_field( $shipping['postcode'] ?? '' ) );
				$order->set_billing_country( sanitize_text_field( $shipping['country'] ?? '' ) );
			}
		}

		// ── Shopwalk metadata ───────────────────────────────────────────
		$order->update_meta_data( '_shopwalk_source', 'direct_checkout' );
		$order->update_meta_data( '_shopwalk_agent_id', sanitize_text_field( $body['shopwalk_agent_id'] ?? '' ) );
		$order->update_meta_data( '_shopwalk_order_id', sanitize_text_field( $body['shopwalk_order_id'] ?? '' ) );
		$order->update_meta_data( '_shopwalk_return_url', esc_url_raw( $body['return_url'] ?? '' ) );
		$order->update_meta_data( '_shopwalk_expires_at', gmdate( 'Y-m-d\TH:i:s\Z', time() + self::ORDER_TTL ) );

		// ── Totals ──────────────────────────────────────────────────────
		$order->calculate_shipping();
		$order->calculate_totals();

		// Set status to pending (awaiting payment at the store's checkout).
		$order->set_status( 'pending', 'Shopwalk Direct Checkout — awaiting payment at store.' );
		$order->save();

		$currency   = $order->get_currency();
		$expires_at = gmdate( 'Y-m-d\TH:i:s\Z', time() + self::ORDER_TTL );

		return new WP_REST_Response(
			array(
				'order_id'       => $order->get_id(),
				'order_key'      => $order->get_order_key(),
				'status'         => $order->get_status(),
				'payment_url'    => $order->get_checkout_payment_url(),
				'subtotal'       => self::to_cents( (float) $order->get_subtotal() ),
				'shipping_total' => self::to_cents( (float) $order->get_shipping_total() ),
				'tax_total'      => self::to_cents( (float) $order->get_total_tax() ),
				'total'          => self::to_cents( (float) $order->get_total() ),
				'currency'       => $currency,
				'items'          => $response_items,
				'expires_at'     => $expires_at,
			),
			201
		);
	}

	// ── Order Status Webhook ────────────────────────────────────────────────

	/**
	 * Fires when a WooCommerce order changes status. If the order originated
	 * from Shopwalk Direct Checkout, POST a webhook to shopwalk-api.
	 *
	 * @param int      $order_id   WC order ID.
	 * @param string   $from       Previous status (without wc- prefix).
	 * @param string   $to         New status (without wc- prefix).
	 * @param WC_Order $order      The order object.
	 * @return void
	 */
	public static function on_order_status_changed( int $order_id, string $from, string $to, $order ): void {
		$shopwalk_order_id = $order->get_meta( '_shopwalk_order_id' );
		$shopwalk_source   = $order->get_meta( '_shopwalk_source' );

		// Only fire for Shopwalk-originated orders.
		if ( $shopwalk_source !== 'direct_checkout' || $shopwalk_order_id === '' ) {
			return;
		}

		$license_key = get_option( 'shopwalk_license_key', '' );
		if ( $license_key === '' ) {
			return;
		}

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
			'shopwalk_order_id' => $shopwalk_order_id,
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

	// ── Return URL Filter ───────────────────────────────────────────────────

	/**
	 * Filter the WooCommerce "thank you" return URL. For Shopwalk orders
	 * that have a return_url stored, redirect back to Shopwalk instead of
	 * the default WC thank-you page.
	 *
	 * @param string   $return_url Default WC return URL.
	 * @param WC_Order $order      The order object.
	 * @return string
	 */
	public static function filter_return_url( string $return_url, $order ): string {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return $return_url;
		}

		$shopwalk_return = $order->get_meta( '_shopwalk_return_url' );
		if ( $shopwalk_return !== '' ) {
			// Append order_id + status as query params for the Shopwalk UI.
			$shopwalk_return = add_query_arg(
				array(
					'wc_order_id' => $order->get_id(),
					'status'      => $order->get_status(),
				),
				$shopwalk_return
			);
			return $shopwalk_return;
		}

		return $return_url;
	}

	// ── Expired Order Cleanup ───────────────────────────────────────────────

	/**
	 * Cron handler — cancel pending Shopwalk orders older than ORDER_TTL.
	 *
	 * @return void
	 */
	public static function cleanup_expired_orders(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::ORDER_TTL );

		$orders = wc_get_orders(
			array(
				'status'       => 'pending',
				'meta_key'     => '_shopwalk_source',       // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => 'direct_checkout',        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'date_created' => '<' . $cutoff,
				'limit'        => 50,
			)
		);

		foreach ( $orders as $order ) {
			$order->update_status(
				'cancelled',
				'Shopwalk Direct Checkout order expired (unpaid for 30+ minutes).'
			);
		}
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

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
