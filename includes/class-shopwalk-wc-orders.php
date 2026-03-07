<?php
/**
 * Orders API — exposes order status, tracking, and refunds for completed checkouts.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Orders class.
 */
class Shopwalk_WC_Orders {

	/**
	 * Maps WooCommerce order statuses to UCP statuses.
	 */
	private const STATUS_MAP = array(
		'pending'    => 'processing',
		'on-hold'    => 'processing',
		'processing' => 'processing',
		'completed'  => 'delivered',
		'cancelled'  => 'canceled',
		'refunded'   => 'refunded',
		'failed'     => 'canceled',
		'shipped'    => 'shipped',
		'in-transit' => 'in_transit',
	);

	/**
	 * Register Routes.
	 *
	 * @param string $namespace Parameter.
	 */
	public function register_routes( string $namespace ): void {
		// Get order by Shopwalk order ID.
		register_rest_route(
			$namespace,
			'/orders/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_permission' ),
			)
		);

		// List orders for a buyer (by email).
		register_rest_route(
			$namespace,
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_orders' ),
				'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_permission' ),
				'args'                => array(
					'email'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
					),
				),
			)
		);

		// Dedicated refund endpoint — POST /orders/{id}/refund.
		register_rest_route(
			$namespace,
			'/orders/(?P<id>[a-zA-Z0-9_-]+)/refund',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_refund' ),
				'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_permission' ),
			)
		);

		// Legacy adjustments endpoint (kept for backward compat).
		register_rest_route(
			$namespace,
			'/orders/(?P<id>[a-zA-Z0-9_-]+)/adjustments',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_adjustment' ),
				'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_permission' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Endpoint handlers.
	// -------------------------------------------------------------------------

	/**
	 * Get order details.
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function get_order( WP_REST_Request $request ): WP_REST_Response {
		$order = $this->find_shopwalk_order( $request->get_param( 'id' ) );
		if ( ! $order ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => SHOPWALK_ERR_SESSION_NOT_FOUND,
						'message' => 'Order not found',
					),
				),
				404
			);
		}

		return new WP_REST_Response( $this->format_order( $order ), 200 );
	}

	/**
	 * List orders for a buyer email.
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function list_orders( WP_REST_Request $request ): WP_REST_Response {
		$email    = $request->get_param( 'email' );
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );

		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => $per_page,
				'page'          => $page,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'meta_key'      => '_shopwalk_status',   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'        => 'completed',           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$items = array_map( array( $this, 'format_order' ), $orders );

		return new WP_REST_Response(
			array(
				'items'      => $items,
				'pagination' => array(
					'page'     => $page,
					'per_page' => $per_page,
				),
			),
			200
		);
	}

	/**
	 * Create a refund — POST /orders/{id}/refund
	 *
	 * Body: { "reason": "...", "amount_cents": 1000 }
	 * Omit amount_cents for a full refund.
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function create_refund( WP_REST_Request $request ): WP_REST_Response {
		$order = $this->find_shopwalk_order( $request->get_param( 'id' ) );
		if ( ! $order ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => SHOPWALK_ERR_SESSION_NOT_FOUND,
						'message' => 'Order not found',
					),
				),
				404
			);
		}

		$body          = $request->get_json_params();
		$reason        = isset( $body['reason'] ) ? sanitize_text_field( $body['reason'] ) : '';
		$amount_cents  = isset( $body['amount_cents'] ) ? absint( $body['amount_cents'] ) : null;
		$refund_amount = null !== $amount_cents
			? round( $amount_cents / 100, 2 )
			: (float) $order->get_total();

		if ( $refund_amount <= 0 ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'INVALID_REFUND_AMOUNT',
						'message' => 'Refund amount must be greater than zero.',
					),
				),
				400
			);
		}

		$max_refund = (float) wc_format_decimal( $order->get_total() - $order->get_total_refunded() );
		if ( $refund_amount > $max_refund ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'REFUND_EXCEEDS_ORDER_TOTAL',
						'message' => sprintf( 'Refund amount %.2f exceeds the maximum refundable amount %.2f.', $refund_amount, $max_refund ),
					),
				),
				400
			);
		}

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $refund_amount,
				'reason'         => $reason,
				'refund_payment' => false, // Platform handles the actual payment reversal.
			)
		);

		if ( is_wp_error( $refund ) ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'REFUND_FAILED',
						'message' => $refund->get_error_message(),
					),
				),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'id'           => 'refund_' . $refund->get_id(),
				'order_id'     => 'sw_order_' . $order->get_id(),
				'amount_cents' => (int) round( $refund_amount * 100 ),
				'reason'       => $reason,
				'status'       => 'pending',
				'created_at'   => $refund->get_date_created() ? $refund->get_date_created()->format( 'c' ) : gmdate( 'c' ),
			),
			201
		);
	}

	/**
	 * Legacy: Create a return/refund adjustment.
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function create_adjustment( WP_REST_Request $request ): WP_REST_Response {
		$order = $this->find_shopwalk_order( $request->get_param( 'id' ) );
		if ( ! $order ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => SHOPWALK_ERR_SESSION_NOT_FOUND,
						'message' => 'Order not found',
					),
				),
				404
			);
		}

		$body   = $request->get_json_params();
		$type   = isset( $body['type'] ) ? sanitize_key( $body['type'] ) : 'refund';
		$amount = isset( $body['amount'] ) ? absint( $body['amount'] ) : null;
		$reason = isset( $body['reason'] ) ? sanitize_text_field( $body['reason'] ) : '';

		if ( 'refund' === $type ) {
			$refund_amount = $amount ? $amount / 100 : (float) $order->get_total();

			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => $refund_amount,
					'reason'         => $reason,
					'refund_payment' => false,
				)
			);

			if ( is_wp_error( $refund ) ) {
				return new WP_REST_Response(
					array(
						'error' => array(
							'code'    => 'REFUND_FAILED',
							'message' => $refund->get_error_message(),
						),
					),
					500
				);
			}

			return new WP_REST_Response(
				array(
					'id'     => 'adj_' . $refund->get_id(),
					'type'   => 'refund',
					'amount' => (int) round( $refund_amount * 100 ),
					'reason' => $reason,
					'status' => 'pending',
				),
				201
			);
		}

		return new WP_REST_Response(
			array(
				'error' => array(
					'code'    => 'UNSUPPORTED_ADJUSTMENT_TYPE',
					'message' => 'Unsupported adjustment type: ' . $type,
				),
			),
			400
		);
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Find Shopwalk Order.
	 *
	 * @param string $order_id Parameter.
	 *
	 * @return WC_Order Result.
	 */
	private function find_shopwalk_order( string $order_id ): ?WC_Order {
		// Formats: ord_{id}, sw_order_{id}, sw_{id}, chk_{id}.
		$wc_order_id = (int) str_replace( array( 'ord_', 'sw_order_', 'sw_', 'chk_' ), '', $order_id );
		if ( $wc_order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $wc_order_id );
		if ( ! $order ) {
			return null;
		}
		// Accept any Shopwalk-originated order (has session meta).
		return $order->get_meta( '_shopwalk_session_id' ) ? $order : null;
	}

	/**
	 * Map WC order status to UCP status.
	 *
	 * @param string $wc_status Parameter.
	 */
	private function map_status( string $wc_status ): string {
		return self::STATUS_MAP[ $wc_status ] ?? 'pending';
	}

	/**
	 * Format Order.
	 *
	 * @param WC_Order $order Parameter.
	 *
	 * @return array Result.
	 */
	private function format_order( WC_Order $order ): array {
		$line_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product      = $item->get_product();
			$line_items[] = array(
				'id'       => (string) $item_id,
				'item'     => array(
					'id'          => (string) ( $product ? $product->get_id() : 0 ),
					'title'       => $item->get_name(),
					'price_cents' => (int) round( $item->get_subtotal() / max( $item->get_quantity(), 1 ) * 100 ),
					// Legacy.
					'price'       => (float) ( $item->get_subtotal() / max( $item->get_quantity(), 1 ) ),
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

		$ucp_status  = $this->map_status( $order->get_status() );
		$fulfillment = array(
			'status'       => $ucp_status,
			'expectations' => array(),
			'events'       => array(),
		);

		// Fulfillment events based on status.
		$event_type = match ( $ucp_status ) {
			'confirmed' => 'confirmed',
			'fulfilled' => 'delivered',
			'shipped'   => 'shipped',
			default     => 'confirmed',
		};

		$fulfillment['events'][] = array(
			'type'        => $event_type,
			'occurred_at' => $order->get_date_modified()
				? $order->get_date_modified()->format( 'c' )
				: ( $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : gmdate( 'c' ) ),
		);

		// Tracking info from meta (compatible with WooCommerce Shipment Tracking plugin).
		$tracking_number = $order->get_meta( '_tracking_number' ) ? $order->get_meta( '_tracking_number' ) : '';
		$tracking_url    = $order->get_meta( '_tracking_url' ) ? $order->get_meta( '_tracking_url' ) : '';
		$tracking_items  = $order->get_meta( '_wc_shipment_tracking_items' );

		if ( is_array( $tracking_items ) ) {
			foreach ( $tracking_items as $track ) {
				$fulfillment['events'][] = array(
					'type'            => 'shipped',
					'occurred_at'     => $track['date_shipped'] ?? '',
					'details'         => ( $track['tracking_provider'] ?? '' ) . ': ' . ( $track['tracking_number'] ?? '' ),
					'tracking_number' => $track['tracking_number'] ?? '',
					'tracking_url'    => $track['tracking_link'] ?? '',
				);
				if ( empty( $tracking_number ) ) {
					$tracking_number = $track['tracking_number'] ?? '';
				}
			}
		}

		if ( $tracking_number ) {
			$fulfillment['tracking'] = array(
				'number' => $tracking_number,
				'url'    => $tracking_url,
			);
		}

		// Shipping address.
		$shipping_info = null;
		if ( $order->get_shipping_address_1() ) {
			$shipping_info = array(
				'first_name'     => $order->get_shipping_first_name(),
				'last_name'      => $order->get_shipping_last_name(),
				'street_address' => $order->get_shipping_address_1(),
				'city'           => $order->get_shipping_city(),
				'region'         => $order->get_shipping_state(),
				'postal_code'    => $order->get_shipping_postcode(),
				'country'        => $order->get_shipping_country(),
			);
		}

		// Adjustments (refunds).
		$adjustments = array();
		foreach ( $order->get_refunds() as $refund ) {
			$adjustments[] = array(
				'id'     => 'refund_' . $refund->get_id(),
				'type'   => 'refund',
				'amount' => (int) round( abs( (float) $refund->get_total() ) * 100 ),
				'reason' => $refund->get_reason(),
			);
		}

		return array(
			'id'            => 'sw_order_' . $order->get_id(),
			'checkout_id'   => 'sw_' . $order->get_id(),
			'wc_order_id'   => $order->get_id(),
			'status'        => $ucp_status,      // UCP status.
			'wc_status'     => $order->get_status(), // Original WC status.
			'permalink_url' => $order->get_view_order_url(),
			'created_at'    => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			'updated_at'    => $order->get_date_modified() ? $order->get_date_modified()->format( 'c' ) : null,
			'buyer'         => array(
				'email'      => $order->get_billing_email(),
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
			),
			'line_items'    => $line_items,
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
			'shipping'      => $shipping_info,
			'fulfillment'   => $fulfillment,
			'adjustments'   => $adjustments,
		);
	}
}
