<?php
/**
 * UCP Webhook Subscriptions — REST CRUD for agent webhook subscriptions.
 *
 * Agents POST to /webhooks/subscriptions to receive UCP order events
 * (`order.created`, `order.processing`, `order.delivered`, `order.canceled`,
 * `order.refunded`). Each subscription stores its own HMAC secret used by
 * the delivery worker (UCP_Webhook_Delivery) to sign outbound payloads.
 *
 * @package ShopwalkWooCommerce
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
	 * Per-OAuth-client cap on active subscription rows.
	 *
	 * Second half of the F-D-4 DoS defense: even with the JSON_CONTAINS
	 * lookup indexed, an agent that creates millions of subscriptions
	 * still degrades every order-status transition. 50 is well above
	 * legitimate use (one per event type plus headroom for staging /
	 * canary callbacks) and small enough to bound the per-event fan-out.
	 */
	private const MAX_SUBS_PER_CLIENT = 50;

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
				'permission_callback' => array( 'UCP_OAuth_Server', 'permission_require_oauth' ),
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/webhooks/subscriptions/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_subscription' ),
					'permission_callback' => array( 'UCP_OAuth_Server', 'permission_require_oauth' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_subscription' ),
					'permission_callback' => array( 'UCP_OAuth_Server', 'permission_require_oauth' ),
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

		if ( '' === $callback_url || ! filter_var( $callback_url, FILTER_VALIDATE_URL ) ) {
			return UCP_Response::error( 'invalid_request', 'callback_url is required and must be a valid URL', 'recoverable', 400 );
		}
		// SSRF defense: reject non-https / userinfo / non-default-port /
		// IP-literal hosts and any hostname that resolves into private,
		// loopback, link-local, or cloud-metadata address space. See
		// UCP_Url_Guard for the full classification.
		$guard_err = UCP_Url_Guard::check_webhook_callback( $callback_url );
		if ( null !== $guard_err ) {
			return UCP_Response::error( 'invalid_request', $guard_err->get_error_message(), 'recoverable', 400 );
		}
		$event_types = array_values( array_intersect( $event_types, self::ALLOWED_EVENTS ) );
		if ( count( $event_types ) === 0 ) {
			return UCP_Response::error( 'invalid_request', 'At least one event_type from ' . implode( ',', self::ALLOWED_EVENTS ) . ' is required', 'recoverable', 400 );
		}

		// F-D-4 (DoS): cap subscriptions per OAuth client. Without a hard
		// cap, even with the indexed JSON_CONTAINS lookup an agent can
		// inflate the per-event fan-out and degrade every WC order-status
		// transition.
		if ( self::count_for_client( (string) $ctx['client_id'] ) >= self::MAX_SUBS_PER_CLIENT ) {
			return UCP_Response::error(
				'subscription_limit_exceeded',
				'Maximum number of webhook subscriptions per client (' . self::MAX_SUBS_PER_CLIENT . ') reached. Delete an existing subscription before creating a new one.',
				'recoverable',
				429
			);
		}

		$id     = UCP_OAuth_Clients::generate_id( 'wh_' );
		$secret = UCP_OAuth_Clients::generate_secret();
		$now    = current_time( 'mysql', true );

		// F-D-5: encrypt the HMAC secret at rest. The plaintext is returned
		// in the create response (the only time it is ever revealed to the
		// agent); the DB stores only the ciphertext blob. If encryption
		// fails (CSPRNG / openssl unavailable) we refuse the request rather
		// than silently fall back to plaintext storage.
		$stored_secret = UCP_Webhook_Secret_Crypto::encrypt( $secret );
		if ( '' === $stored_secret ) {
			return UCP_Response::error(
				'crypto_unavailable',
				'Webhook secret encryption is unavailable on this server. Please contact the site administrator.',
				'fatal',
				500
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			UCP_Storage::table( 'webhook_subscriptions' ),
			array(
				'id'           => $id,
				'client_id'    => $ctx['client_id'],
				'callback_url' => $callback_url,
				'event_types'  => wp_json_encode( $event_types ),
				'secret'       => $stored_secret,
				'created_at'   => $now,
			)
		);

		return new WP_REST_Response(
			UCP_Response::ok(
				array(
					'id'           => $id,
					'object'       => 'webhook_subscription',
					'callback_url' => $callback_url,
					'event_types'  => $event_types,
					'secret'       => $secret, // Returned only on create — agent must store.
					'created_at'   => $now,
				)
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
			return UCP_Response::error( 'not_found', 'Subscription not found', 'recoverable', 404 );
		}
		return new WP_REST_Response(
			UCP_Response::ok(
				array(
					'id'           => (string) $row['id'],
					'object'       => 'webhook_subscription',
					'callback_url' => (string) $row['callback_url'],
					'event_types'  => json_decode( (string) $row['event_types'], true ),
					'created_at'   => (string) $row['created_at'],
				)
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
			return UCP_Response::error( 'not_found', 'Subscription not found', 'recoverable', 404 );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( UCP_Storage::table( 'webhook_subscriptions' ), array( 'id' => $id ) );
		return new WP_REST_Response(
			UCP_Response::ok(
				array(
					'deleted' => true,
					'id'      => $id,
				)
			),
			200
		);
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
		return $row ? $row : null;
	}

	/**
	 * Find all subscriptions interested in a given event type.
	 *
	 * F-D-4: pushed down to MySQL with JSON_CONTAINS so we no longer pull
	 * every row into PHP on every WC order-status transition. The
	 * `event_types` column stores a JSON array of strings (e.g.
	 * `["order.created","order.processing"]`), and JSON_CONTAINS with a
	 * scalar-quoted needle returns true only when that string appears as
	 * an element. Requires MySQL 5.7+ / MariaDB 10.2.6+ — both well below
	 * WordPress's effective MySQL minimum (8.0 since WP 6.6).
	 *
	 * The query is parameterized via $wpdb->prepare(); we json_encode the
	 * needle so a value like `order.created` becomes the SQL string
	 * `"order.created"`, which is the JSON-scalar form JSON_CONTAINS
	 * expects on the right-hand side.
	 *
	 * @param string $event_type e.g. "order.created".
	 * @return array<int, array<string,mixed>>
	 */
	public static function find_by_event( string $event_type ): array {
		global $wpdb;
		$table = UCP_Storage::table( 'webhook_subscriptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from UCP_Storage::table(), not user input.
				"SELECT * FROM {$table} WHERE JSON_CONTAINS(event_types, %s)",
				wp_json_encode( $event_type )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count active subscriptions for a given OAuth client. Used to enforce
	 * the per-client cap at create time (F-D-4 DoS defense).
	 *
	 * @param string $client_id OAuth client id.
	 * @return int
	 */
	public static function count_for_client( string $client_id ): int {
		global $wpdb;
		$table = UCP_Storage::table( 'webhook_subscriptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from UCP_Storage::table(), not user input.
				"SELECT COUNT(*) FROM {$table} WHERE client_id = %s",
				$client_id
			)
		);
		return (int) $count;
	}
}
