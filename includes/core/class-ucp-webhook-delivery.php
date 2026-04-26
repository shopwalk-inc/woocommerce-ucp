<?php
/**
 * UCP Webhook Delivery — outbound webhook publisher.
 *
 * Two halves:
 *  1. Event capture — WC order status hooks enqueue UCP events into
 *     wp_ucp_webhook_queue with one queue row per (event, subscription)
 *     pair.
 *  2. Delivery worker — WP-Cron job (`shopwalk_ucp_webhook_flush`) runs
 *     every minute, pops pending rows, signs the payload with the
 *     subscription's HMAC secret, POSTs to the agent's callback URL,
 *     and retries with exponential backoff on 5xx.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Webhook_Delivery — event capture + delivery worker.
 */
final class UCP_Webhook_Delivery {

	/**
	 * Max delivery attempts before giving up.
	 */
	private const MAX_ATTEMPTS = 5;

	/**
	 * Max queue rows to process per cron tick.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Wire up event capture + the cron handler.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		// WC order status → UCP event mapping.
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_order_created' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_processing' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_delivered' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order_canceled' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_order_refunded' ), 10, 2 );

		// Cron worker.
		add_action( 'shopwalk_ucp_webhook_flush', array( __CLASS__, 'flush_queue' ) );
	}

	// ── Event capture (WC hooks → enqueue) ───────────────────────────────

	/**
	 * @param int $order_id WC order id.
	 * @return void
	 */
	public static function on_order_created( int $order_id ): void {
		self::enqueue_event_for_order( 'order.created', $order_id );
	}

	/**
	 * @param int      $order_id WC order id.
	 * @param WC_Order $order    The order object.
	 * @return void
	 */
	public static function on_order_processing( int $order_id, $order ): void {
		unset( $order );
		self::enqueue_event_for_order( 'order.processing', $order_id );
	}

	/**
	 * @param int      $order_id WC order id.
	 * @param WC_Order $order    The order object.
	 * @return void
	 */
	public static function on_order_delivered( int $order_id, $order ): void {
		unset( $order );
		self::enqueue_event_for_order( 'order.delivered', $order_id );
	}

	/**
	 * @param int      $order_id WC order id.
	 * @param WC_Order $order    The order object.
	 * @return void
	 */
	public static function on_order_canceled( int $order_id, $order ): void {
		unset( $order );
		self::enqueue_event_for_order( 'order.canceled', $order_id );
	}

	/**
	 * @param int $order_id WC order id.
	 * @param int $refund_id WC refund id.
	 * @return void
	 */
	public static function on_order_refunded( int $order_id, int $refund_id ): void {
		unset( $refund_id );
		self::enqueue_event_for_order( 'order.refunded', $order_id );
	}

	/**
	 * Build a full UCP order payload and fan it out to every subscription
	 * interested in this event type.
	 *
	 * @param string $event_type e.g. "order.created".
	 * @param int    $order_id   WC order id.
	 * @return void
	 */
	private static function enqueue_event_for_order( string $event_type, int $order_id ): void {
		$subs = UCP_Webhook_Subscriptions::find_by_event( $event_type );
		if ( count( $subs ) === 0 ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$payload = wp_json_encode( self::build_order_payload( $order, $event_type ) );
		$now     = current_time( 'mysql', true );

		global $wpdb;
		foreach ( $subs as $sub ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				UCP_Storage::table( 'webhook_queue' ),
				array(
					'subscription_id' => (string) $sub['id'],
					'event_type'      => $event_type,
					'payload'         => $payload,
					'attempts'        => 0,
					'next_attempt_at' => $now,
					'created_at'      => $now,
				)
			);
		}

		// Drain ASAP via single-event. WP-Cron loopback fires this on the
		// next pageload — typically within seconds — instead of waiting up
		// to 5 minutes for the recurring backstop. Built-in dedup folds
		// rapid bursts into one fire.
		if ( ! wp_next_scheduled( 'shopwalk_ucp_webhook_flush' ) || wp_next_scheduled( 'shopwalk_ucp_webhook_flush' ) > time() + 30 ) {
			wp_schedule_single_event( time() + 5, 'shopwalk_ucp_webhook_flush' );
		}
	}

	/**
	 * Build a full UCP-spec order object for webhook delivery.
	 *
	 * @param WC_Order $order      The WC order.
	 * @param string   $event_type The UCP event type.
	 * @return array
	 */
	private static function build_order_payload( $order, string $event_type ): array {
		$line_items = array();
		foreach ( $order->get_items() as $idx => $item ) {
			$fulfilled    = ( 'completed' === $order->get_status() ) ? $item->get_quantity() : 0;
			$line_items[] = UCP_Response::build_order_line_item( $item, $idx, $fulfilled );
		}

		// Map WC status to a UCP fulfillment expectation.
		$wc_status    = $order->get_status();
		$expectations = array();
		if ( in_array( $wc_status, array( 'processing', 'on-hold' ), true ) ) {
			$expectations[] = array(
				'type'   => 'shipping',
				'status' => 'pending',
				'label'  => 'Order is being processed',
			);
		} elseif ( 'completed' === $wc_status ) {
			$expectations[] = array(
				'type'   => 'shipping',
				'status' => 'complete',
				'label'  => 'Order has been fulfilled',
			);
		}

		// Build fulfillment events from order notes/status history.
		$fulfillment_events   = array();
		$fulfillment_events[] = array(
			'type'        => 'status_change',
			'status'      => $wc_status,
			'occurred_at' => $order->get_date_modified()
				? $order->get_date_modified()->date( 'c' )
				: gmdate( 'c' ),
		);

		// Collect order adjustments (refunds, coupons).
		$adjustments = array();
		foreach ( $order->get_refunds() as $refund ) {
			$adjustments[] = array(
				'type'   => 'refund',
				'amount' => UCP_Response::to_cents( abs( (float) $refund->get_total() ) ),
				'reason' => $refund->get_reason() ?: 'Refund',
			);
		}
		foreach ( $order->get_coupon_codes() as $code ) {
			$adjustments[] = array(
				'type' => 'coupon',
				'code' => $code,
			);
		}

		return array(
			'ucp'           => array(
				'version' => UCP_Response::VERSION,
				'status'  => 'ok',
			),
			'event'         => $event_type,
			'id'            => strval( $order->get_id() ),
			'label'         => '#' . $order->get_order_number(),
			'permalink_url' => $order->get_view_order_url(),
			'currency'      => $order->get_currency(),
			'line_items'    => $line_items,
			'fulfillment'   => array(
				'expectations' => $expectations,
				'events'       => $fulfillment_events,
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
		);
	}

	// ── Delivery worker (WP-Cron) ────────────────────────────────────────

	/**
	 * Pull pending rows and POST them. Runs every minute via WP-Cron.
	 *
	 * @return void
	 */
	public static function flush_queue(): void {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$queue}
				 WHERE delivered_at IS NULL AND failed_at IS NULL AND next_attempt_at <= %s
				 ORDER BY id ASC LIMIT %d",
				current_time( 'mysql', true ),
				self::BATCH_SIZE
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			self::deliver_one( $row );
		}
	}

	/**
	 * Attempt a single delivery. Updates the queue row with the result.
	 *
	 * @param array $row Queue row.
	 * @return void
	 */
	private static function deliver_one( array $row ): void {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );

		$sub = UCP_Webhook_Subscriptions::find( (string) $row['subscription_id'] );
		if ( ! $sub ) {
			// Subscription gone — drop the row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$queue,
				array(
					'failed_at'  => current_time( 'mysql', true ),
					'last_error' => 'subscription deleted',
				),
				array( 'id' => (int) $row['id'] )
			);
			return;
		}

		$payload    = (string) $row['payload'];
		$secret     = (string) $sub['secret'];
		$timestamp  = time();
		$webhook_id = 'evt_' . wp_generate_uuid4();

		// Content-Digest (SHA-256 of body).
		$digest = base64_encode( hash( 'sha256', $payload, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for Content-Digest header per RFC 9530.

		// HMAC signature over the signed content per UCP spec.
		$signed_content = $webhook_id . '.' . $timestamp . '.' . $payload;
		$signature      = base64_encode( hash_hmac( 'sha256', $signed_content, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HMAC-SHA256 webhook signature.

		$response = wp_remote_post(
			(string) $sub['callback_url'],
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'Webhook-Timestamp' => strval( $timestamp ),
					'Webhook-Id'        => $webhook_id,
					'UCP-Agent'         => 'profile="' . get_site_url() . '/.well-known/ucp"',
					'Content-Digest'    => 'sha-256=:' . $digest . ':',
					'Signature-Input'   => 'sig1=("content-digest" "webhook-id" "webhook-timestamp");keyid="store-hmac";alg="hmac-sha256"',
					'Signature'         => 'sig1=:' . $signature . ':',
				),
				'body'    => $payload,
			)
		);

		$attempts = (int) $row['attempts'] + 1;
		if ( is_wp_error( $response ) ) {
			self::record_failure( (int) $row['id'], $attempts, $response->get_error_message() );
			return;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$queue,
				array(
					'delivered_at' => current_time( 'mysql', true ),
					'attempts'     => $attempts,
				),
				array( 'id' => (int) $row['id'] )
			);
			return;
		}
		if ( $status >= 400 && $status < 500 ) {
			// 4xx — give up immediately, no retry (the agent has given an unrecoverable error).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$queue,
				array(
					'failed_at'  => current_time( 'mysql', true ),
					'attempts'   => $attempts,
					'last_error' => 'HTTP ' . $status,
				),
				array( 'id' => (int) $row['id'] )
			);
			return;
		}
		// 5xx — schedule a retry.
		self::record_failure( (int) $row['id'], $attempts, 'HTTP ' . $status );
	}

	/**
	 * Record a transient failure and schedule the next retry, or move
	 * the row to permanent failure if MAX_ATTEMPTS has been reached.
	 *
	 * @param int    $row_id   Queue row id.
	 * @param int    $attempts Updated attempts count.
	 * @param string $error    Error message to persist.
	 * @return void
	 */
	private static function record_failure( int $row_id, int $attempts, string $error ): void {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$queue,
				array(
					'failed_at'  => current_time( 'mysql', true ),
					'attempts'   => $attempts,
					'last_error' => $error,
				),
				array( 'id' => $row_id )
			);
			return;
		}
		// Exponential backoff: 1m, 2m, 4m, 8m, 16m.
		$delay = pow( 2, $attempts - 1 ) * 60;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$queue,
			array(
				'attempts'        => $attempts,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'last_error'      => $error,
			),
			array( 'id' => $row_id )
		);
	}
}
