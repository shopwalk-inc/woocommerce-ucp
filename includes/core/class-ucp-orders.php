<?php
/**
 * UCP Orders — REST endpoints for order retrieval.
 *
 * GET /orders             list orders for the OAuth-authenticated buyer
 * GET /orders/{id}        order detail
 * GET /orders/{id}/events fulfillment event log
 *
 * Orders are read directly from WooCommerce via wc_get_orders, scoped
 * to the buyer's customer ID resolved from the OAuth Bearer token.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Orders — order endpoints.
 */
final class UCP_Orders {

	/**
	 * Register order REST routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_orders' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_order' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/orders/(?P<id>\d+)/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_order_events' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /orders — list orders for the OAuth-authenticated buyer.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_orders( WP_REST_Request $request ) {
		$ctx = UCP_OAuth_Server::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'wc_unavailable', 'WooCommerce is not active', array( 'status' => 503 ) );
		}

		$limit  = max( 1, min( 100, (int) $request->get_param( 'limit' ) ?: 25 ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) ?: 1 );

		$orders = wc_get_orders(
			array(
				'customer_id' => (int) $ctx['user_id'],
				'limit'       => $limit,
				'page'        => $page,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$out = array();
		foreach ( $orders as $order ) {
			$out[] = self::format_order( $order );
		}
		return new WP_REST_Response(
			array(
				'object' => 'list',
				'data'   => $out,
				'page'   => $page,
				'limit'  => $limit,
			),
			200
		);
	}

	/**
	 * GET /orders/{id}
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_order( WP_REST_Request $request ) {
		$ctx = UCP_OAuth_Server::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$id    = (int) $request->get_param( 'id' );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		if ( ! $order || (int) $order->get_customer_id() !== (int) $ctx['user_id'] ) {
			return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( self::format_order( $order ), 200 );
	}

	/**
	 * GET /orders/{id}/events — fulfillment events log.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_order_events( WP_REST_Request $request ) {
		$ctx = UCP_OAuth_Server::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$id    = (int) $request->get_param( 'id' );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		if ( ! $order || (int) $order->get_customer_id() !== (int) $ctx['user_id'] ) {
			return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
		}

		$notes = function_exists( 'wc_get_order_notes' ) ? wc_get_order_notes( array( 'order_id' => $id ) ) : array();
		$events = array();
		foreach ( $notes as $note ) {
			$events[] = array(
				'id'         => (string) $note->id,
				'type'       => 'note',
				'message'    => (string) $note->content,
				'created_at' => $note->date_created instanceof \WC_DateTime ? $note->date_created->format( 'c' ) : '',
			);
		}
		return new WP_REST_Response(
			array(
				'object'   => 'list',
				'order_id' => $id,
				'data'     => $events,
			),
			200
		);
	}

	/**
	 * Map a WC_Order to the UCP Order Object shape.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<string,mixed>
	 */
	private static function format_order( $order ): array {
		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$line_items[] = array(
				'product_id' => (int) $item->get_product_id(),
				'name'       => (string) $item->get_name(),
				'quantity'   => (int) $item->get_quantity(),
				'subtotal'   => (float) $item->get_subtotal(),
				'total'      => (float) $item->get_total(),
			);
		}
		return array(
			'id'         => (int) $order->get_id(),
			'object'     => 'order',
			'status'     => (string) $order->get_status(),
			'currency'   => (string) $order->get_currency(),
			'line_items' => $line_items,
			'totals'     => array(
				'subtotal' => (float) $order->get_subtotal(),
				'shipping' => (float) $order->get_shipping_total(),
				'tax'      => (float) $order->get_total_tax(),
				'total'    => (float) $order->get_total(),
			),
			'buyer' => array(
				'email'      => (string) $order->get_billing_email(),
				'first_name' => (string) $order->get_billing_first_name(),
				'last_name'  => (string) $order->get_billing_last_name(),
			),
			'shipping_address' => array(
				'line1'       => (string) $order->get_shipping_address_1(),
				'line2'       => (string) $order->get_shipping_address_2(),
				'city'        => (string) $order->get_shipping_city(),
				'state'       => (string) $order->get_shipping_state(),
				'postal_code' => (string) $order->get_shipping_postcode(),
				'country'     => (string) $order->get_shipping_country(),
			),
			'created_at' => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
		);
	}
}
