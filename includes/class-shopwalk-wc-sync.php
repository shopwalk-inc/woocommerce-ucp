<?php
/**
 * Product Sync — pushes product changes to Shopwalk API.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Sync class.
 */
class Shopwalk_WC_Sync {

	/**
	 * Instance.
	 *
	 * @var self
	 */
	private static ?self $instance = null;

	/**
	 * Store API — canonical product push endpoint (sw_site_* keys, X-Site-Key header).
	 * Used for product upserts and full product syncs.
	 */
	private const STORE_PRODUCTS_ENDPOINT = 'https://api.shopwalk.com/api/v1/store/products';

	/**
	 * Legacy sync endpoint — retained for coupon events only.
	 * Product events now go through STORE_PRODUCTS_ENDPOINT.
	 */
	private const SYNC_ENDPOINT = 'https://api.shopwalk.com/api/v1/sync/event';

	/** Queue option name. */
	private const QUEUE_OPTION = 'shopwalk_wc_sync_queue';

	/** Max queue size (events). */
	private const QUEUE_CAP = 500;

	/** Max events to flush per cron run. */
	private const QUEUE_FLUSH_BATCH = 50;

	/** WP-Cron hook name for queue flush. */
	private const CRON_FLUSH_HOOK = 'shopwalk_wc_queue_flush';

	/** Bulk sync batch size (products per API burst). */
	private const BULK_BATCH_SIZE = 25;

	/**
	 * In-request deduplication: track product IDs already sent a delete event
	 * this request to prevent double-fire from wp_trash_post + before_delete_post.
	 *
	 * @var int[]
	 */
	private static array $deleted_this_request = array();

	/**
	 * Get or create the singleton instance.
	 *
	 * @return self Singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		// Sync on product save (create/update).
		add_action( 'woocommerce_update_product', array( $this, 'sync_product' ), 10, 2 );
		add_action( 'woocommerce_new_product', array( $this, 'sync_product' ), 10, 2 );

		// Sync on product delete/trash.
		add_action( 'woocommerce_delete_product', array( $this, 'delete_product' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'trash_product' ), 10, 1 );

		// Fallback: catch permanent deletes for variable products and other edge cases.
		// Uses $deleted_this_request to avoid double-firing with woocommerce_delete_product.
		add_action( 'before_delete_post', array( $this, 'maybe_delete_product' ), 10, 1 );

		// Stock-only updates (skip vector re-embedding on the API side).
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'sync_stock_update' ), 10, 3 );
		add_action( 'woocommerce_product_set_stock', array( $this, 'sync_stock_update_quantity' ), 10, 3 );

		// Price/sale updates.
		add_action( 'woocommerce_product_object_updated_props', array( $this, 'sync_price_update' ), 10, 2 );

		// Coupon sync.
		add_action( 'woocommerce_new_coupon', array( $this, 'sync_coupon_upsert' ), 10, 2 );
		add_action( 'woocommerce_update_coupon', array( $this, 'sync_coupon_upsert' ), 10, 2 );
		add_action( 'woocommerce_delete_coupon', array( $this, 'sync_coupon_delete' ), 10, 2 );

		// Queue flush runs via WP-Cron every 5 minutes — NOT on every init.
		// Scheduled on plugin activation; see shopwalk_ai_activate() in shopwalk-ai.php.
		add_action( self::CRON_FLUSH_HOOK, array( $this, 'flush_sync_queue' ) );
	}

	// =========================================================================
	// Cron scheduling helpers (called from plugin activation/deactivation).
	// =========================================================================

	/**
	 * Schedule the queue-flush cron job if not already scheduled.
	 * Call from the plugin activation hook.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_FLUSH_HOOK ) ) {
			wp_schedule_event( time(), 'shopwalk_every_5min', self::CRON_FLUSH_HOOK );
		}
	}

	/**
	 * Remove the queue-flush cron job.
	 * Call from the plugin deactivation hook.
	 */
	public static function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( self::CRON_FLUSH_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_FLUSH_HOOK );
		}
	}

	// =========================================================================
	// Configuration helpers.
	// =========================================================================

	/**
	 * Get the Shopwalk plugin key from settings (unified key).
	 */
	protected function get_api_key(): string {
		if ( defined( 'SHOPWALK_AI_PREFILLED_LICENSE' ) && ! empty( SHOPWALK_AI_PREFILLED_LICENSE ) ) {
			return SHOPWALK_AI_PREFILLED_LICENSE;
		}
		return get_option( 'shopwalk_wc_plugin_key', '' );
	}

	/**
	 * Get the partner ID.
	 * Uses the configured option, or auto-derives from the site URL.
	 */
	protected function get_partner_id(): string {
		$configured = get_option( 'shopwalk_wc_partner_id', '' );
		if ( ! empty( $configured ) ) {
			return $configured;
		}
		// Auto-derive from site URL.
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return str_replace( '.', '-', $host ?? 'unknown' );
	}

	// =========================================================================
	// Transport layer.
	// =========================================================================

	/**
	 * POST a product payload to POST /api/v1/store/products using X-Site-Key.
	 * This is the canonical path for all product upserts from the WC plugin.
	 * Does NOT use the retry queue — WC hooks will re-fire on the next save.
	 *
	 * @param  array $product_input Payload matching the ProductInput struct.
	 * @return bool  true on success, false on failure.
	 */
	protected function send_product_push( array $product_input ): bool {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return false;
		}

		if ( get_option( 'shopwalk_wc_key_invalid', 0 ) ) {
			return false;
		}

		$response = wp_remote_post(
			self::STORE_PRODUCTS_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-Site-Key'   => $api_key,
				),
				'body'    => wp_json_encode( $product_input ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			update_option( 'shopwalk_wc_key_invalid', 1 );
			update_option( 'shopwalk_wc_sync_status', 'Error: invalid API key (401)' );
			return false;
		}

		return ( $code >= 200 && $code < 300 );
	}

	/**
	 * Send DELETE /api/v1/store/products/{external_id} using X-Site-Key.
	 * Used for product trash and permanent delete events.
	 * Does NOT use the retry queue — acceptable to miss a delete on transient failure.
	 *
	 * @param  string $external_id WC product ID (as string).
	 * @return bool   true on success, false on failure.
	 */
	protected function send_product_delete( string $external_id ): bool {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return false;
		}

		if ( get_option( 'shopwalk_wc_key_invalid', 0 ) ) {
			return false;
		}

		$url = self::STORE_PRODUCTS_ENDPOINT . '/' . rawurlencode( $external_id );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-Site-Key'   => $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			update_option( 'shopwalk_wc_key_invalid', 1 );
			update_option( 'shopwalk_wc_sync_status', 'Error: invalid API key (401)' );
			return false;
		}

		// 404 is acceptable — product may not exist on the API side yet.
		return ( $code >= 200 && $code < 300 ) || 404 === $code;
	}

	/**
	 * POST a coupon/misc event to the legacy sync endpoint using X-API-Key.
	 * Retained for coupon sync only. On failure, queues for retry.
	 * On 401, marks the key invalid and does NOT queue.
	 *
	 * @param  array $payload Structured event array.
	 * @return bool  true on success, false on failure.
	 */
	protected function send_event( array $payload ): bool {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return false;
		}

		$body = wp_json_encode( $payload );

		$response = wp_remote_post(
			self::SYNC_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $api_key,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->push_to_queue( $body );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		// 401: invalid/expired key — mark invalid, do NOT queue (would retry forever).
		if ( 401 === $code ) {
			update_option( 'shopwalk_wc_key_invalid', 1 );
			update_option( 'shopwalk_wc_sync_status', 'Error: invalid API key (401)' );
			return false;
		}

		if ( $code < 200 || $code >= 300 ) {
			$this->push_to_queue( $body );
			return false;
		}

		return true;
	}

	// =========================================================================
	// Retry queue.
	// =========================================================================

	/**
	 * Push a failed payload JSON string onto the retry queue.
	 * Silently drops the event when the queue is at capacity.
	 *
	 * @param string $payload_json JSON-encoded event payload.
	 */
	protected function push_to_queue( string $payload_json ): void {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		if ( count( $queue ) >= self::QUEUE_CAP ) {
			return; // Queue full — drop event rather than unbounded growth.
		}

		$queue[] = $payload_json;
		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Flush up to QUEUE_FLUSH_BATCH events from the retry queue.
	 * Runs via WP-Cron every 5 minutes (not on every page load).
	 * Successfully sent events are removed; failures stay in the queue.
	 */
	public function flush_sync_queue(): void {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		// Stop retrying if key has been flagged invalid (e.g. after a 401 response).
		if ( get_option( 'shopwalk_wc_key_invalid', 0 ) ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return;
		}

		$batch     = array_slice( $queue, 0, self::QUEUE_FLUSH_BATCH );
		$remaining = array_slice( $queue, self::QUEUE_FLUSH_BATCH );
		$failed    = array();

		foreach ( $batch as $payload_json ) {
			if ( ! $this->flush_one( $api_key, $payload_json ) ) {
				$failed[] = $payload_json; // Keep for next attempt.
			}
		}

		// Put failures back in front, then the untouched tail.
		$new_queue = array_merge( $failed, $remaining );
		update_option( self::QUEUE_OPTION, $new_queue, false );
	}

	/**
	 * Send a single queued payload. Extracted from flush_sync_queue for testability.
	 * Detects 401 Unauthorized and marks the key as invalid to stop infinite retries.
	 *
	 * @param  string $api_key      The plugin API key.
	 * @param  string $payload_json JSON-encoded event payload.
	 * @return bool   true on success, false on any failure.
	 */
	protected function flush_one( string $api_key, string $payload_json ): bool {
		$response = wp_remote_post(
			self::SYNC_ENDPOINT,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $api_key,
				),
				'body'    => $payload_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			update_option( 'shopwalk_wc_key_invalid', 1 );
			update_option( 'shopwalk_wc_sync_status', 'Error: invalid API key (401)' );
			return false;
		}

		return ( $code >= 200 && $code < 300 );
	}

	// =========================================================================
	// Product sync handlers.
	// =========================================================================

	/**
	 * Sync a product to Shopwalk via POST /store/products.
	 *
	 * @param int   $product_id Parameter.
	 * @param mixed $product Parameter.
	 */
	public function sync_product( int $product_id, $product = null ): void {
		if ( get_option( 'shopwalk_wc_enable_sync', 'yes' ) !== 'yes' ) {
			return;
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		if ( ! $product ) {
			$product = wc_get_product( $product_id );
		}
		if ( ! $product || ! $product instanceof WC_Product ) {
			return;
		}

		// Skip drafts and private products.
		if ( 'publish' !== $product->get_status() ) {
			return;
		}

		$payload = $this->build_product_input( $product );
		$success = $this->send_product_push( $payload );

		// Record sync status.
		update_option( 'shopwalk_wc_last_sync', current_time( 'Y-m-d H:i:s' ) );
		update_option( 'shopwalk_wc_sync_status', $success ? 'OK' : 'Error: sync failed' );
	}

	/**
	 * Notify Shopwalk when a product is permanently deleted.
	 * Sends DELETE /store/products/{external_id}.
	 * Deduplicates within the same request via $deleted_this_request.
	 *
	 * @param int $product_id Parameter.
	 */
	public function delete_product( int $product_id ): void {
		if ( get_option( 'shopwalk_wc_enable_sync', 'yes' ) !== 'yes' ) {
			return;
		}

		if ( empty( $this->get_api_key() ) ) {
			return;
		}

		// Deduplicate: woocommerce_delete_product and before_delete_post both fire.
		if ( in_array( $product_id, self::$deleted_this_request, true ) ) {
			return;
		}
		self::$deleted_this_request[] = $product_id;

		$this->send_product_delete( (string) $product_id );
	}

	/**
	 * Handle trashed products — treat as a delete signal.
	 * Sends DELETE /store/products/{external_id}.
	 * Also deduplicates via $deleted_this_request.
	 *
	 * @param int $post_id Parameter.
	 */
	public function trash_product( int $post_id ): void {
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		// Deduplicate: wp_trash_post and maybe_delete_product can both fire.
		if ( in_array( $post_id, self::$deleted_this_request, true ) ) {
			return;
		}
		self::$deleted_this_request[] = $post_id;

		if ( get_option( 'shopwalk_wc_enable_sync', 'yes' ) !== 'yes' ) {
			return;
		}

		if ( empty( $this->get_api_key() ) ) {
			return;
		}

		$this->send_product_delete( (string) $post_id );
	}

	/**
	 * Fallback permanent-delete hook.
	 * Catches variable products and any type missed by woocommerce_delete_product.
	 * Skips if delete_product() or trash_product() already handled this ID.
	 *
	 * @param int $post_id Parameter.
	 */
	public function maybe_delete_product( int $post_id ): void {
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		// Skip if already handled this request (deduplication).
		if ( in_array( $post_id, self::$deleted_this_request, true ) ) {
			return;
		}

		$this->delete_product( $post_id );
	}

	// =========================================================================
	// Stock update handlers.
	// =========================================================================

	/**
	 * Sync a stock-status change via full product push to POST /store/products.
	 * The API's ingest pipeline detects price/stock-only changes via checksum
	 * and skips re-embedding, so this is still a fast path on the server side.
	 * Hooked to woocommerce_product_set_stock_status.
	 *
	 * @param int        $product_id Parameter.
	 * @param string     $status Parameter.
	 * @param WC_Product $product Parameter.
	 */
	public function sync_stock_update( int $product_id, string $status, WC_Product $product ): void {
		if ( get_option( 'shopwalk_wc_enable_sync', 'yes' ) !== 'yes' ) {
			return;
		}

		if ( empty( $this->get_api_key() ) ) {
			return;
		}

		$payload = $this->build_product_input( $product );
		$this->send_product_push( $payload );
	}

	/**
	 * Sync a stock-quantity change via full product push to POST /store/products.
	 * Hooked to woocommerce_product_set_stock.
	 *
	 * @param mixed $product Parameter.
	 * @param mixed $stock_quantity Parameter.
	 * @param mixed $old_stock Parameter.
	 */
	public function sync_stock_update_quantity( $product, $stock_quantity, $old_stock ): void {
		if ( get_option( 'shopwalk_wc_enable_sync', 'yes' ) !== 'yes' ) {
			return;
		}

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		if ( empty( $this->get_api_key() ) ) {
			return;
		}

		$payload = $this->build_product_input( $product );
		$this->send_product_push( $payload );
	}

	// =========================================================================
	// Price update handler.
	// =========================================================================

	/**
	 * Sync a price/sale change via full product push to POST /store/products.
	 * Only fires if price-related props were among the changed properties.
	 * The API's ingest pipeline detects price-only changes and skips re-embedding.
	 *
	 * @param WC_Product $product Parameter.
	 * @param array      $updated_props Parameter.
	 */
	public function sync_price_update( WC_Product $product, array $updated_props ): void {
		$price_props = array( 'regular_price', 'sale_price', 'price' );
		if ( empty( array_intersect( $price_props, $updated_props ) ) ) {
			return; // No price-related change — skip.
		}

		if ( get_option( 'shopwalk_wc_enable_sync', 'yes' ) !== 'yes' ) {
			return;
		}

		if ( empty( $this->get_api_key() ) ) {
			return;
		}

		$payload = $this->build_product_input( $product );
		$this->send_product_push( $payload );
	}

	// =========================================================================
	// Coupon sync handlers.
	// =========================================================================

	/**
	 * Sync a coupon create or update (product.coupon_update / action: upsert).
	 *
	 * @param int   $coupon_id Parameter.
	 * @param mixed $coupon Parameter.
	 */
	public function sync_coupon_upsert( int $coupon_id, $coupon = null ): void {
		if ( get_option( 'shopwalk_wc_enable_sync', 'yes' ) !== 'yes' ) {
			return;
		}

		if ( empty( $this->get_api_key() ) ) {
			return;
		}

		if ( ! $coupon instanceof WC_Coupon ) {
			$coupon = new WC_Coupon( $coupon_id );
		}

		$expiry = $coupon->get_date_expires();

		$payload = array(
			'event_type'  => 'product.coupon_update',
			'source'      => 'plugin',
			'partner_id' => $this->get_partner_id(),
			'coupon'      => array(
				'id'            => (string) $coupon_id,
				'code'          => $coupon->get_code(),
				'discount_type' => $coupon->get_discount_type(),
				'amount'        => $coupon->get_amount(),
				'expiry_date'   => $expiry ? $expiry->date( 'Y-m-d' ) : null,
				'action'        => 'upsert',
			),
		);

		$this->send_event( $payload );
	}

	/**
	 * Sync a coupon deletion (product.coupon_update / action: delete).
	 *
	 * @param int   $coupon_id Parameter.
	 * @param mixed $coupon Parameter.
	 */
	public function sync_coupon_delete( int $coupon_id, $coupon = null ): void {
		if ( get_option( 'shopwalk_wc_enable_sync', 'yes' ) !== 'yes' ) {
			return;
		}

		if ( empty( $this->get_api_key() ) ) {
			return;
		}

		// Retrieve coupon code before the record is gone.
		$code = '';
		if ( $coupon instanceof WC_Coupon ) {
			$code = $coupon->get_code();
		} else {
			$post = get_post( $coupon_id );
			$code = $post ? $post->post_title : '';
		}

		$payload = array(
			'event_type'  => 'product.coupon_update',
			'source'      => 'plugin',
			'partner_id' => $this->get_partner_id(),
			'coupon'      => array(
				'id'     => (string) $coupon_id,
				'code'   => $code,
				'action' => 'delete',
			),
		);

		$this->send_event( $payload );
	}

	// =========================================================================
	// Bulk sync (WP-Cron background job).
	// =========================================================================

	/**
	 * Bulk-sync all published products. Invoked by the 'shopwalk_wc_bulk_sync' cron event.
	 * Processes in batches of BULK_BATCH_SIZE with a 1-second pause between batches
	 * to avoid rate-limiting on large stores.
	 */
	public static function run_bulk_sync(): void {
		$all_ids = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'ids',
			)
		);
		$batches = array_chunk( $all_ids, self::BULK_BATCH_SIZE );
		$synced  = 0;
		$failed  = 0;

		foreach ( $batches as $batch_index => $batch ) {
			// Pause 1 second between batches (not before the first).
			if ( $batch_index > 0 ) {
				sleep( 1 );
			}

			foreach ( $batch as $id ) {
				$instance = self::instance();
				try {
					$instance->sync_product( $id );
					++$synced;
				} catch ( \Throwable $e ) {
					++$failed;
				}
			}
		}

		update_option(
			'shopwalk_wc_bulk_sync_result',
			array(
				'synced' => $synced,
				'failed' => $failed,
				'total'  => count( $all_ids ),
				'at'     => current_time( 'Y-m-d H:i:s' ),
			)
		);
	}

	// =========================================================================
	// Payload builders.
	// =========================================================================

	/**
	 * Build a ProductInput payload for POST /api/v1/store/products.
	 * Field names match the API's ingest.ProductInput struct (JSON tags).
	 *
	 * @param WC_Product $product Parameter.
	 */
	protected function build_product_input( WC_Product $product ): array {
		// Categories.
		$categories = array();
		$terms      = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$categories = array_values( array_map( fn( $t ) => $t->name, $terms ) );
		}

		// Images (featured + gallery).
		$images   = array();
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$url = wp_get_attachment_url( $image_id );
			if ( $url ) {
				$images[] = array(
					'url'      => $url,
					'alt'      => get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ?: $product->get_name(),
					'position' => 0,
				);
			}
		}
		foreach ( $product->get_gallery_image_ids() as $i => $gid ) {
			$url = wp_get_attachment_url( $gid );
			if ( $url ) {
				$images[] = array(
					'url'      => $url,
					'alt'      => get_post_meta( $gid, '_wp_attachment_image_alt', true ) ?: '',
					'position' => $i + 1,
				);
			}
		}

		// Pricing.
		$regular_price = (float) $product->get_regular_price();
		$sale_price    = $product->get_sale_price();
		$on_sale       = $product->is_on_sale();
		$price         = ( $on_sale && '' !== $sale_price ) ? (float) $sale_price : $regular_price;
		$compare_at    = ( $on_sale && $regular_price > 0 ) ? $regular_price : 0.0;

		return array(
			'external_id'       => (string) $product->get_id(),
			'title'             => $product->get_name(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'sku'               => $product->get_sku(),
			'price'             => $price,
			'compare_at_price'  => $compare_at,
			'currency'          => get_woocommerce_currency(),
			'in_stock'          => $product->is_in_stock(),
			'source_url'        => get_permalink( $product->get_id() ) ?: '',
			'provider'          => 'woocommerce',
			'categories'        => $categories,
			'images'            => $images,
			'average_rating'    => (float) $product->get_average_rating(),
			'rating_count'      => (int) $product->get_rating_count(),
		);
	}

	/**
	 * Build the legacy product.upsert event payload for the /sync/event endpoint.
	 * Retained for reference; product sync now uses build_product_input().
	 *
	 * @param WC_Product $product Parameter.
	 * @deprecated Use build_product_input() + send_product_push() instead.
	 */
	protected function build_upsert_event( WC_Product $product ): array {
		// Categories.
		$categories = array();
		$terms      = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$categories = array_map( fn( $t ) => $t->name, $terms );
		}

		// Images (featured + gallery).
		$images   = array();
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$url = wp_get_attachment_url( $image_id );
			if ( $url ) {
				$images[] = array(
					'url'      => $url,
					'alt_text' => get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : $product->get_name(),
					'position' => 0,
				);
			}
		}

		foreach ( $product->get_gallery_image_ids() as $i => $gid ) {
			$url = wp_get_attachment_url( $gid );
			if ( $url ) {
				$images[] = array(
					'url'      => $url,
					'alt_text' => get_post_meta( $gid, '_wp_attachment_image_alt', true ) ? get_post_meta( $gid, '_wp_attachment_image_alt', true ) : '',
					'position' => $i + 1,
				);
			}
		}

		// Pricing.
		$regular_price = $product->get_regular_price();
		$on_sale       = $product->is_on_sale();

		return array(
			'event_type'  => 'product.upsert',
			'source'      => 'plugin',
			'partner_id' => $this->get_partner_id(),
			'product'     => array(
				'external_id'       => (string) $product->get_id(),
				'provider'          => 'woocommerce',
				'name'              => $product->get_name(),
				'description'       => $product->get_description(),
				'short_description' => $product->get_short_description(),
				'category'          => ! empty( $categories ) ? $categories[0] : '',
				'base_price'        => (float) $product->get_price(),
				'compare_at_price'  => ( $on_sale && '' !== $regular_price ) ? (float) $regular_price : null,
				'currency'          => get_woocommerce_currency(),
				'in_stock'          => $product->is_in_stock(),
				'stock_quantity'    => (int) ( $product->get_stock_quantity() ?? 0 ),
				'on_sale'           => $on_sale,
				'source_url'        => get_permalink( $product->get_id() ),
				'images'            => $images,
				'average_rating'    => (float) $product->get_average_rating(),
				'rating_count'      => (int) $product->get_rating_count(),
			),
		);
	}
}

// Register custom 5-minute cron interval.
add_filter(
	'cron_schedules',
	function ( array $schedules ): array {
		if ( ! isset( $schedules['shopwalk_every_5min'] ) ) {
			$schedules['shopwalk_every_5min'] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 Minutes (Shopwalk)', 'shopwalk-ai' ),
			);
		}
		return $schedules;
	}
);

// Boot the sync singleton.
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			Shopwalk_WC_Sync::instance();
		}
	},
	20
);

// Register the WP-Cron bulk-sync callback.
add_action( 'shopwalk_wc_bulk_sync', array( Shopwalk_WC_Sync::class, 'run_bulk_sync' ) );
