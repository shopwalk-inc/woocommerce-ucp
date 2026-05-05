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
 * @package ShopwalkWooCommerce
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
				'permission_callback' => array( 'UCP_OAuth_Server', 'permission_require_oauth' ),
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_order' ),
				'permission_callback' => array( 'UCP_OAuth_Server', 'permission_require_oauth' ),
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/orders/(?P<id>\d+)/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_order_events' ),
				'permission_callback' => array( 'UCP_OAuth_Server', 'permission_require_oauth' ),
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
			return UCP_Response::error( 'wc_unavailable', 'WooCommerce is not active', 'fatal', 503 );
		}

		$limit = max( 1, min( 100, (int) $request->get_param( 'limit' ) ?: 25 ) );
		$page  = max( 1, (int) $request->get_param( 'page' ) ?: 1 );

		$orders = wc_get_orders(
			array(
				'customer_id' => (int) $ctx['user_id'],
				'limit'       => $limit,
				'page'        => $page,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		$out    = array();
		foreach ( $orders as $order ) {
			$out[] = self::format_order( $order );
		}
		return new WP_REST_Response(
			UCP_Response::ok(
				array(
					'object' => 'list',
					'data'   => $out,
					'page'   => $page,
					'limit'  => $limit,
				),
				array( 'dev.ucp.shopping.order' )
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
			return UCP_Response::error( 'not_found', 'Order not found', 'recoverable', 404 );
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
			return UCP_Response::error( 'not_found', 'Order not found', 'recoverable', 404 );
		}

		$events = self::build_fulfillment_events( $order );

		return new WP_REST_Response(
			UCP_Response::ok(
				array(
					'object'   => 'list',
					'order_id' => strval( $id ),
					'data'     => $events,
				),
				array( 'dev.ucp.shopping.order' )
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
		// Build line items with quantity tracking.
		$line_items = array();
		$wc_items   = array_values( $order->get_items() );
		foreach ( $wc_items as $idx => $wc_item ) {
			$fulfilled_qty = 0;
			// Check if order is completed — all items are fulfilled.
			if ( 'completed' === $order->get_status() ) {
				$fulfilled_qty = $wc_item->get_quantity();
			}
			$line_items[] = UCP_Response::build_order_line_item( $wc_item, $idx, $fulfilled_qty );
		}

		// Build fulfillment expectations from WC shipping methods.
		$expectations = self::build_fulfillment_expectations( $order, $wc_items );
		$events       = self::build_fulfillment_events( $order );
		$adjustments  = self::build_adjustments( $order );

		return UCP_Response::ok(
			array(
				'id'            => strval( $order->get_id() ),
				'object'        => 'order',
				'label'         => '#' . $order->get_order_number(),
				'checkout_id'   => $order->get_meta( '_ucp_checkout_session_id' ) ?: null,
				'permalink_url' => $order->get_view_order_url(),
				'status'        => (string) $order->get_status(),
				'currency'      => (string) $order->get_currency(),
				'line_items'    => $line_items,
				'buyer'         => array(
					'email'      => (string) $order->get_billing_email(),
					'first_name' => (string) $order->get_billing_first_name(),
					'last_name'  => (string) $order->get_billing_last_name(),
				),
				'fulfillment'   => array(
					'expectations' => $expectations,
					'events'       => $events,
				),
				'adjustments'   => $adjustments,
				'totals'        => UCP_Response::build_totals(
					$order->get_subtotal(),
					$order->get_shipping_total(),
					$order->get_total_tax(),
					$order->get_discount_total(),
					$order->get_total()
				),
				'messages'      => array(),
				'created_at'    => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
			),
			array( 'dev.ucp.shopping.order' )
		);
	}

	/**
	 * Build fulfillment expectations from WC shipping methods.
	 *
	 * @param WC_Order $order    WooCommerce order.
	 * @param array    $wc_items Array of WC_Order_Item_Product items.
	 * @return array
	 */
	private static function build_fulfillment_expectations( $order, array $wc_items ): array {
		$expectations     = array();
		$shipping_methods = $order->get_shipping_methods();

		if ( empty( $shipping_methods ) ) {
			// No shipping methods — create a single expectation covering all items.
			$expectations[] = array(
				'id'             => 'exp_1',
				'line_items'     => array_map(
					function ( $li, $idx ) {
						return array(
							'id'       => 'li_' . ( $idx + 1 ),
							'quantity' => $li->get_quantity(),
						);
					},
					$wc_items,
					array_keys( $wc_items )
				),
				'method_type'    => 'shipping',
				'destination'    => UCP_Response::to_destination(
					array(
						'address_1' => $order->get_shipping_address_1(),
						'address_2' => $order->get_shipping_address_2(),
						'city'      => $order->get_shipping_city(),
						'state'     => $order->get_shipping_state(),
						'postcode'  => $order->get_shipping_postcode(),
						'country'   => $order->get_shipping_country(),
					)
				),
				'description'    => 'Standard shipping',
				'fulfillable_on' => 'now',
			);
			return $expectations;
		}

		foreach ( array_values( $shipping_methods ) as $i => $method ) {
			$expectations[] = array(
				'id'             => 'exp_' . ( $i + 1 ),
				'line_items'     => array_map(
					function ( $li, $idx ) {
						return array(
							'id'       => 'li_' . ( $idx + 1 ),
							'quantity' => $li->get_quantity(),
						);
					},
					$wc_items,
					array_keys( $wc_items )
				),
				'method_type'    => 'shipping',
				'destination'    => UCP_Response::to_destination(
					array(
						'address_1' => $order->get_shipping_address_1(),
						'address_2' => $order->get_shipping_address_2(),
						'city'      => $order->get_shipping_city(),
						'state'     => $order->get_shipping_state(),
						'postcode'  => $order->get_shipping_postcode(),
						'country'   => $order->get_shipping_country(),
					)
				),
				'description'    => $method->get_method_title(),
				'fulfillable_on' => 'now',
			);
		}

		return $expectations;
	}

	/**
	 * Build fulfillment events from WC order notes.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function build_fulfillment_events( $order ): array {
		$events = array();
		$notes  = function_exists( 'wc_get_order_notes' )
			? wc_get_order_notes(
				array(
					'order_id' => $order->get_id(),
					'type'     => 'any',
				)
			)
			: array();

		$tracking = $order->get_meta( '_tracking_number' );

		foreach ( $notes as $note ) {
			$event = array(
				'id'          => 'evt_' . $note->id,
				'occurred_at' => $note->date_created instanceof \WC_DateTime
					? $note->date_created->format( 'c' )
					: ( is_object( $note->date_created ) && method_exists( $note->date_created, 'date' )
						? $note->date_created->date( 'c' )
						: '' ),
				'type'        => 'processing',
				'description' => (string) $note->content,
			);

			// Check for tracking info in note content.
			if ( $tracking && stripos( $note->content, 'shipped' ) !== false ) {
				$event['type']            = 'shipped';
				$event['tracking_number'] = $tracking;
				$event['tracking_url']    = $order->get_meta( '_tracking_url' ) ?: '';
				$event['carrier']         = $order->get_meta( '_tracking_provider' ) ?: '';
			}

			$events[] = $event;
		}

		return $events;
	}

	/**
	 * Build adjustments (refunds) from WC order refunds.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	private static function build_adjustments( $order ): array {
		$adjustments = array();
		$refunds     = $order->get_refunds();

		foreach ( $refunds as $refund ) {
			$adjustments[] = array(
				'id'          => 'adj_' . $refund->get_id(),
				'type'        => 'refund',
				'occurred_at' => $refund->get_date_created() ? $refund->get_date_created()->format( 'c' ) : '',
				'status'      => 'completed',
				'totals'      => array(
					array(
						'type'   => 'total',
						'amount' => UCP_Response::to_cents( $refund->get_amount() ) * -1,
					),
				),
				'description' => $refund->get_reason() ?: 'Refund',
			);
		}

		return $adjustments;
	}
}
