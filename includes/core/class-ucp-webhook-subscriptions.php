<?php
/**
 * UCP Webhook Subscriptions — REST CRUD for agent webhook subscriptions.
 *
 * Agents POST to /webhooks/subscriptions to receive UCP order events
 * (`order.created`, `order.processing`, `order.delivered`, `order.canceled`,
 * `order.refunded`). Each subscription stores its own HMAC secret used by
 * the delivery worker (UCP_Webhook_Delivery) to sign outbound payloads.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Webhook_Subscriptions — subscription CRUD.
 */
final class UCP_Webhook_Subscriptions {

	/**
	 * Allowed event types per UCP spec.
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED_EVENTS = array(
		'order.created',
		'order.processing',
		'order.delivered',
		'order.canceled',
		'order.refunded',
		'order.shipped',
	);

	/**
	 * Register subscription REST routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/webhooks/subscriptions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_subscription' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/webhooks/subscriptions/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_subscription' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_subscription' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	// ── CREATE ───────────────────────────────────────────────────────────

	/**
	 * POST /webhooks/subscriptions
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_subscription( WP_REST_Request $request ) {
		$ctx = UCP_OAuth_Server::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}

		$body         = $request->get_json_params() ?: array();
		$callback_url = (string) ( $body['callback_url'] ?? '' );
		$event_types  = (array) ( $body['event_types'] ?? array() );

		if ( $callback_url === '' || ! filter_var( $callback_url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_request', 'callback_url is required and must be a valid URL', array( 'status' => 400 ) );
		}
		$event_types = array_values( array_intersect( $event_types, self::ALLOWED_EVENTS ) );
		if ( count( $event_types ) === 0 ) {
			return new WP_Error( 'invalid_request', 'At least one event_type from ' . implode( ',', self::ALLOWED_EVENTS ) . ' is required', array( 'status' => 400 ) );
		}

		$id     = UCP_OAuth_Clients::generate_id( 'wh_' );
		$secret = UCP_OAuth_Clients::generate_secret();
		$now    = current_time( 'mysql', true );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			UCP_Storage::table( 'webhook_subscriptions' ),
			array(
				'id'           => $id,
				'client_id'    => $ctx['client_id'],
				'callback_url' => $callback_url,
				'event_types'  => wp_json_encode( $event_types ),
				'secret'       => $secret,
				'created_at'   => $now,
			)
		);

		return new WP_REST_Response(
			array(
				'id'           => $id,
				'object'       => 'webhook_subscription',
				'callback_url' => $callback_url,
				'event_types'  => $event_types,
				'secret'       => $secret, // Returned only on create — agent must store.
				'created_at'   => $now,
			),
			201
		);
	}

	/**
	 * GET /webhooks/subscriptions/{id}
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_subscription( WP_REST_Request $request ) {
		$ctx = UCP_OAuth_Server::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$row = self::find( (string) $request->get_param( 'id' ) );
		if ( ! $row || $row['client_id'] !== $ctx['client_id'] ) {
			return new WP_Error( 'not_found', 'Subscription not found', array( 'status' => 404 ) );
		}
		return new WP_REST_Response(
			array(
				'id'           => (string) $row['id'],
				'object'       => 'webhook_subscription',
				'callback_url' => (string) $row['callback_url'],
				'event_types'  => json_decode( (string) $row['event_types'], true ),
				'created_at'   => (string) $row['created_at'],
			),
			200
		);
	}

	/**
	 * DELETE /webhooks/subscriptions/{id}
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_subscription( WP_REST_Request $request ) {
		$ctx = UCP_OAuth_Server::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$id  = (string) $request->get_param( 'id' );
		$row = self::find( $id );
		if ( ! $row || $row['client_id'] !== $ctx['client_id'] ) {
			return new WP_Error( 'not_found', 'Subscription not found', array( 'status' => 404 ) );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( UCP_Storage::table( 'webhook_subscriptions' ), array( 'id' => $id ) );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ), 200 );
	}

	// ── Internal helpers ─────────────────────────────────────────────────

	/**
	 * Look up a subscription row by id.
	 *
	 * @param string $id Subscription id.
	 * @return array<string,mixed>|null
	 */
	public static function find( string $id ): ?array {
		global $wpdb;
		$table = UCP_Storage::table( 'webhook_subscriptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %s LIMIT 1",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Find all subscriptions interested in a given event type.
	 *
	 * @param string $event_type e.g. "order.created".
	 * @return array<int, array<string,mixed>>
	 */
	public static function find_by_event( string $event_type ): array {
		global $wpdb;
		$table = UCP_Storage::table( 'webhook_subscriptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$events = json_decode( (string) $row['event_types'], true ) ?: array();
			if ( in_array( $event_type, $events, true ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}
}
