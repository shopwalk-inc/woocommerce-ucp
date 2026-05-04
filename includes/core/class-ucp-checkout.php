<?php
/**
 * UCP Checkout — checkout-sessions REST endpoints + lifecycle.
 *
 * Status transitions per UCP spec:
 *   incomplete → ready_for_complete → completed
 *                                   → canceled
 *                                   → requires_escalation (error)
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Checkout — checkout session lifecycle.
 */
final class UCP_Checkout {

	/**
	 * Session TTL in seconds (30 min).
	 */
	private const SESSION_TTL = 1800;

	/**
	 * Register checkout-session REST routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/checkout-sessions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_session' ),
				'permission_callback' => array( __CLASS__, 'permission_optional_buyer' ),
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/checkout-sessions/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_session' ),
					'permission_callback' => array( __CLASS__, 'permission_optional_buyer' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_session' ),
					'permission_callback' => array( __CLASS__, 'permission_optional_buyer' ),
				),
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/checkout-sessions/(?P<id>[A-Za-z0-9_-]+)/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'complete_session' ),
				'permission_callback' => array( __CLASS__, 'permission_optional_buyer' ),
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/checkout-sessions/(?P<id>[A-Za-z0-9_-]+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'cancel_session' ),
				'permission_callback' => array( __CLASS__, 'permission_optional_buyer' ),
			)
		);

		// Cron handler — clean up expired sessions hourly.
		add_action( 'shopwalk_session_cleanup', array( __CLASS__, 'cleanup_expired' ) );
	}

	/**
	 * Permission callback that allows both anonymous (Phase 1, agent
	 * proxies an unauthenticated checkout for the buyer) and authenticated
	 * (OAuth Bearer access token) requests. The handler decides whether
	 * the request is allowed based on session ownership.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool
	 */
	public static function permission_optional_buyer( WP_REST_Request $request ): bool {
		// Always permit; ownership is enforced inside each handler.
		unset( $request );
		return true;
	}

	// ── CREATE ───────────────────────────────────────────────────────────

	/**
	 * POST /checkout-sessions — create a new session from line items.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_session( WP_REST_Request $request ) {
		// Idempotency-Key support: return cached response if present.
		$idempotency_key = $request->get_header( 'Idempotency-Key' );
		if ( $idempotency_key ) {
			$cache_key = 'ucp_idem_' . md5( $idempotency_key );
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return new WP_REST_Response( $cached['body'], $cached['status'] );
			}
		}

		$body       = $request->get_json_params() ?: array();
		$line_items = $body['line_items'] ?? array();
		if ( ! is_array( $line_items ) || count( $line_items ) === 0 ) {
			return UCP_Response::error( 'invalid_request', 'line_items[] is required', 'recoverable', 400 );
		}

		$client_id = self::resolve_client_id( $request );
		$user_id   = self::resolve_user_id( $request );

		$id      = UCP_OAuth_Clients::generate_id( 'chk_' );
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );

		$totals = self::calculate_totals( $line_items );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			UCP_Storage::table( 'checkout_sessions' ),
			array(
				'id'          => $id,
				'client_id'   => $client_id,
				'user_id'     => $user_id ? $user_id : null,
				'status'      => 'incomplete',
				'line_items'  => wp_json_encode( $line_items ),
				'buyer'       => wp_json_encode( $body['buyer'] ?? null ),
				'fulfillment' => wp_json_encode( $body['fulfillment'] ?? null ),
				'payment'     => wp_json_encode( $body['payment'] ?? null ),
				'totals'      => wp_json_encode( $totals ),
				'messages'    => wp_json_encode( array() ),
				'created_at'  => $now,
				'updated_at'  => $now,
				'expires_at'  => $expires,
			)
		);

		$response_body = self::session_to_object( $id );

		// Cache for idempotency if key was provided.
		if ( ! empty( $idempotency_key ) ) {
			set_transient(
				$cache_key,
				array(
					'body'   => $response_body,
					'status' => 201,
				),
				DAY_IN_SECONDS
			);
		}

		return new WP_REST_Response( $response_body, 201 );
	}

	// ── READ ─────────────────────────────────────────────────────────────

	/**
	 * GET /checkout-sessions/{id}
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_session( WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$obj = self::session_to_object( $id );
		if ( ! $obj ) {
			return UCP_Response::error( 'not_found', 'Session not found', 'recoverable', 404 );
		}
		return new WP_REST_Response( $obj, 200 );
	}

	// ── UPDATE ───────────────────────────────────────────────────────────

	/**
	 * PUT /checkout-sessions/{id} — update buyer / address / fulfillment / payment.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_session( WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$row = self::find( $id );
		if ( ! $row ) {
			return UCP_Response::error( 'not_found', 'Session not found', 'recoverable', 404 );
		}
		if ( 'incomplete' !== $row['status'] && 'ready_for_complete' !== $row['status'] ) {
			return UCP_Response::error( 'invalid_state', 'Session cannot be updated in its current status', 'recoverable', 409 );
		}

		$body    = $request->get_json_params() ?: array();
		$updates = array(
			'updated_at' => current_time( 'mysql', true ),
		);
		foreach ( array( 'buyer', 'fulfillment', 'payment' ) as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$updates[ $field ] = wp_json_encode( $body[ $field ] );
			}
		}
		if ( isset( $body['line_items'] ) && is_array( $body['line_items'] ) ) {
			$updates['line_items'] = wp_json_encode( $body['line_items'] );
			$updates['totals']     = wp_json_encode( self::calculate_totals( $body['line_items'] ) );
		}

		// If buyer + fulfillment + payment are all set, advance to ready_for_complete.
		$buyer       = $body['buyer'] ?? json_decode( (string) $row['buyer'], true );
		$fulfillment = $body['fulfillment'] ?? json_decode( (string) $row['fulfillment'], true );
		$payment     = $body['payment'] ?? json_decode( (string) $row['payment'], true );
		if ( $buyer && $fulfillment && $payment ) {
			$updates['status'] = 'ready_for_complete';
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( UCP_Storage::table( 'checkout_sessions' ), $updates, array( 'id' => $id ) );

		return new WP_REST_Response( self::session_to_object( $id ), 200 );
	}

	// ── COMPLETE ─────────────────────────────────────────────────────────

	/**
	 * POST /checkout-sessions/{id}/complete — finalize the session, charge
	 * payment via the Shopwalk_UCP_Payment_Gateway, create a WC order.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function complete_session( WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$row = self::find( $id );
		if ( ! $row ) {
			return UCP_Response::error( 'not_found', 'Session not found', 'recoverable', 404 );
		}
		if ( 'ready_for_complete' !== $row['status'] ) {
			return UCP_Response::error( 'invalid_state', 'Session is not ready_for_complete', 'recoverable', 409 );
		}

		// Create the WC order.
		$order = self::build_wc_order_from_session( $row );
		if ( is_wp_error( $order ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				UCP_Storage::table( 'checkout_sessions' ),
				array(
					'status'     => 'requires_escalation',
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $id )
			);
			return $order;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			UCP_Storage::table( 'checkout_sessions' ),
			array(
				'status'      => 'completed',
				'wc_order_id' => $order->get_id(),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);

		return new WP_REST_Response( self::session_to_object( $id ), 200 );
	}

	// ── CANCEL ───────────────────────────────────────────────────────────

	/**
	 * POST /checkout-sessions/{id}/cancel — release reserved stock + mark canceled.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel_session( WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$row = self::find( $id );
		if ( ! $row ) {
			return UCP_Response::error( 'not_found', 'Session not found', 'recoverable', 404 );
		}
		if ( 'completed' === $row['status'] ) {
			return UCP_Response::error( 'invalid_state', 'Cannot cancel a completed session', 'recoverable', 409 );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			UCP_Storage::table( 'checkout_sessions' ),
			array(
				'status'     => 'canceled',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
		return new WP_REST_Response( self::session_to_object( $id ), 200 );
	}

	// ── Helpers ─────────────────────────────────────────────────────────

	/**
	 * Look up a session row.
	 *
	 * @param string $id The session id.
	 * @return array<string,mixed>|null
	 */
	private static function find( string $id ): ?array {
		global $wpdb;
		$table = UCP_Storage::table( 'checkout_sessions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %s LIMIT 1",
				$id
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Cron handler — delete sessions whose expires_at is in the past and
	 * which have not been completed.
	 *
	 * @return void
	 */
	public static function cleanup_expired(): void {
		global $wpdb;
		$table = UCP_Storage::table( 'checkout_sessions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE status != 'completed' AND expires_at < %s",
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Format a session row as the UCP Checkout Object shape.
	 *
	 * @param string $id The session id.
	 * @return array<string,mixed>|null
	 */
	private static function session_to_object( string $id ): ?array {
		$row = self::find( $id );
		if ( ! $row ) {
			return null;
		}

		$raw_line_items = json_decode( (string) $row['line_items'], true ) ?: array();
		$raw_totals     = json_decode( (string) $row['totals'], true ) ?: array();
		$fulfillment_in = $row['fulfillment'] ? json_decode( (string) $row['fulfillment'], true ) : null;
		$wc_order_id    = $row['wc_order_id'] ? (int) $row['wc_order_id'] : null;

		// Build line items — if WC order exists, use WC items for full data.
		$line_items    = array();
		$line_item_ids = array();
		if ( $wc_order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $wc_order_id );
			if ( $order ) {
				foreach ( array_values( $order->get_items() ) as $idx => $wc_item ) {
					$li              = UCP_Response::build_line_item( $wc_item, $idx );
					$line_items[]    = $li;
					$line_item_ids[] = $li['id'];
				}
			}
		}
		if ( empty( $line_items ) ) {
			// Fallback: build from raw stored data.
			foreach ( $raw_line_items as $idx => $item ) {
				$li_id           = 'li_' . ( $idx + 1 );
				$line_items[]    = array(
					'id'       => $li_id,
					'item'     => array(
						'id'    => strval( $item['product_id'] ?? '' ),
						'title' => $item['name'] ?? '',
						'price' => UCP_Response::to_cents( $item['price'] ?? 0 ),
					),
					'quantity' => $item['quantity'] ?? 0,
				);
				$line_item_ids[] = $li_id;
			}
		}

		// Build typed totals.
		if ( $wc_order_id && function_exists( 'wc_get_order' ) ) {
			$order = $order ?? wc_get_order( $wc_order_id );
			if ( $order ) {
				$totals   = UCP_Response::build_totals(
					$order->get_subtotal(),
					$order->get_shipping_total(),
					$order->get_total_tax(),
					$order->get_discount_total(),
					$order->get_total()
				);
				$currency = $order->get_currency();
			} else {
				$totals   = UCP_Response::build_totals( $raw_totals['subtotal'] ?? 0, 0, 0, 0, $raw_totals['subtotal'] ?? 0 );
				$currency = $raw_totals['currency'] ?? 'USD';
			}
		} else {
			$totals   = UCP_Response::build_totals( $raw_totals['subtotal'] ?? 0, 0, 0, 0, $raw_totals['subtotal'] ?? 0 );
			$currency = $raw_totals['currency'] ?? 'USD';
		}

		// Build fulfillment model.
		$fulfillment = null;
		if ( $fulfillment_in ) {
			$address_data = $fulfillment_in['shipping_address'] ?? $fulfillment_in;
			$fulfillment  = array(
				'methods' => array(
					array(
						'id'                      => 'fm_1',
						'type'                    => 'shipping',
						'line_item_ids'           => $line_item_ids,
						'selected_destination_id' => 'dest_1',
						'destinations'            => array( UCP_Response::to_destination( $address_data ) ),
						'groups'                  => array(),
					),
				),
			);
		}

		$data = array(
			'id'          => (string) $row['id'],
			'object'      => 'checkout_session',
			'client_id'   => (string) $row['client_id'],
			'status'      => (string) $row['status'],
			'line_items'  => $line_items,
			'buyer'       => $row['buyer'] ? json_decode( (string) $row['buyer'], true ) : null,
			'fulfillment' => $fulfillment,
			'payment'     => $row['payment'] ? json_decode( (string) $row['payment'], true ) : null,
			'totals'      => $totals,
			'currency'    => $currency,
			'messages'    => json_decode( (string) $row['messages'], true ) ?: array(),
			'created_at'  => (string) $row['created_at'],
			'updated_at'  => (string) $row['updated_at'],
			'expires_at'  => (string) $row['expires_at'],
		);

		// On completed sessions, include order reference + payment URL so the
		// agent can hand control to the buyer for native-checkout payment.
		if ( 'completed' === $row['status'] && $wc_order_id ) {
			$order         = $order ?? ( function_exists( 'wc_get_order' ) ? wc_get_order( $wc_order_id ) : null );
			$data['order'] = array(
				'id'            => strval( $wc_order_id ),
				'permalink_url' => $order ? $order->get_view_order_url() : '',
				'payment_url'   => $order ? $order->get_checkout_payment_url() : '',
				'status'        => $order ? $order->get_status() : '',
			);
		}

		return UCP_Response::ok( $data, array( 'dev.ucp.shopping.checkout' ) );
	}

	/**
	 * Compute totals from a line_items array. Each line item must have
	 * at least { product_id, quantity }.
	 *
	 * @param array $line_items UCP line item array.
	 * @return array{subtotal:float, currency:string}
	 */
	private static function calculate_totals( array $line_items ): array {
		$subtotal = 0.0;
		foreach ( $line_items as $item ) {
			$pid = (int) ( $item['product_id'] ?? $item['item']['id'] ?? 0 );
			$qty = (int) ( $item['quantity'] ?? 0 );
			if ( $pid <= 0 || $qty <= 0 ) {
				continue;
			}
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( $product ) {
				$subtotal += (float) $product->get_price() * $qty;
			}
		}
		return array(
			'subtotal' => round( $subtotal, 2 ),
			'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
		);
	}

	/**
	 * Build a real WC order from a session row. Sets order meta linking
	 * back to the session id so subsequent webhook events can correlate.
	 *
	 * @param array $row Session row from find().
	 * @return WC_Order|WP_Error
	 */
	private static function build_wc_order_from_session( array $row ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return UCP_Response::error( 'wc_unavailable', 'WooCommerce is not active', 'fatal', 503 );
		}
		$order = wc_create_order(
			array(
				'customer_id' => $row['user_id'] ? (int) $row['user_id'] : 0,
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$line_items = json_decode( (string) $row['line_items'], true ) ?: array();
		foreach ( $line_items as $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			$qty = (int) ( $item['quantity'] ?? 0 );
			if ( $pid <= 0 || $qty <= 0 ) {
				continue;
			}
			$product = wc_get_product( $pid );
			if ( $product ) {
				$order->add_product( $product, $qty );
			}
		}

		$buyer = json_decode( (string) $row['buyer'], true );
		if ( is_array( $buyer ) ) {
			$order->set_billing_email( (string) ( $buyer['email'] ?? '' ) );
			$order->set_billing_first_name( (string) ( $buyer['first_name'] ?? '' ) );
			$order->set_billing_last_name( (string) ( $buyer['last_name'] ?? '' ) );
		}

		$fulfillment = json_decode( (string) $row['fulfillment'], true );
		if ( is_array( $fulfillment ) ) {
			$ship = null;
			// Support new UCP fulfillment model (methods[].destinations[]).
			if ( isset( $fulfillment['methods'][0]['destinations'][0] ) ) {
				$dest = $fulfillment['methods'][0]['destinations'][0];
				$ship = array(
					'line1'       => $dest['street_address'] ?? '',
					'line2'       => '',
					'city'        => $dest['address_locality'] ?? '',
					'state'       => $dest['address_region'] ?? '',
					'postal_code' => $dest['postal_code'] ?? '',
					'country'     => $dest['address_country'] ?? '',
				);
			} elseif ( isset( $fulfillment['shipping_address'] ) ) {
				// Legacy flat shipping_address format.
				$ship = $fulfillment['shipping_address'];
			}

			if ( $ship ) {
				$order->set_shipping_address_1( (string) ( $ship['line1'] ?? $ship['address_1'] ?? '' ) );
				$order->set_shipping_address_2( (string) ( $ship['line2'] ?? $ship['address_2'] ?? '' ) );
				$order->set_shipping_city( (string) ( $ship['city'] ?? '' ) );
				$order->set_shipping_state( (string) ( $ship['state'] ?? '' ) );
				$order->set_shipping_postcode( (string) ( $ship['postal_code'] ?? $ship['postcode'] ?? '' ) );
				$order->set_shipping_country( (string) ( $ship['country'] ?? '' ) );
			}
		}

		$order->set_payment_method( 'shopwalk_ucp' );
		$order->set_payment_method_title( 'Pay via UCP' );
		$order->update_meta_data( '_ucp_session_id', (string) $row['id'] );
		$order->update_meta_data( '_ucp_checkout_session_id', (string) $row['id'] );
		$order->update_meta_data( '_ucp_client_id', (string) $row['client_id'] );
		$order->calculate_totals();

		// Dispatch agent-native payment through the router. The router picks
		// the adapter matching `payment.gateway` and the adapter reuses the
		// merchant's already-configured WooCommerce gateway credentials —
		// the plugin itself owns no payment keys.
		//
		// If payment is omitted or the selected gateway can't auto-authorize
		// (e.g. Stripe requires 3DS), the order stays in `pending` and the
		// session's `order.payment_url` is returned so the agent can hand
		// the buyer off to native checkout.
		$payment = json_decode( (string) $row['payment'], true );
		$payment = is_array( $payment ) ? $payment : array();

		if ( ! empty( $payment['gateway'] ) ) {
			$result = UCP_Payment_Router::authorize( $order, $payment );

			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();
				// `stripe_requires_action` and similar soft failures are
				// recoverable via the payment_url handoff — keep the order
				// in pending so the buyer can complete 3DS on native checkout.
				if ( 'stripe_requires_action' === $code ) {
					$order->update_status( 'pending', 'UCP payment deferred to buyer (3DS required): ' . $result->get_error_message() );
					return $order;
				}
				$order->update_status( 'failed', sprintf( 'UCP payment (%s) failed: %s', $code, $result->get_error_message() ) );
				return $result;
			}
			// Adapter already advanced the order state via payment_complete().
			return $order;
		}

		// No payment credential supplied — fall back to the payment_url
		// handoff. The agent can still hand the buyer to native checkout.
		$order->update_status( 'pending', 'UCP session completed; awaiting buyer payment.' );
		return $order;
	}

	/**
	 * Resolve which OAuth client created the session. If no Authorization
	 * header is present we attribute the session to the anonymous client
	 * ID `agt_anonymous` so the table FK is always populated.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return string
	 */
	private static function resolve_client_id( WP_REST_Request $request ): string {
		$ctx = UCP_OAuth_Server::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return 'agt_anonymous';
		}
		return $ctx['client_id'];
	}

	/**
	 * Resolve which buyer the session belongs to. Returns 0 (anonymous)
	 * when no Bearer access token is present.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return int
	 */
	private static function resolve_user_id( WP_REST_Request $request ): int {
		$ctx = UCP_OAuth_Server::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return 0;
		}
		return (int) $ctx['user_id'];
	}
}
