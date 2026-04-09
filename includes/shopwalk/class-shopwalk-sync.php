<?php
/**
 * Shopwalk_Sync — outbound product push to shopwalk-api.
 *
 * Tier 2 (Shopwalk integration) only. Hooks WooCommerce product save/delete
 * actions, queues changes in a WP option, and flushes the queue every
 * 5 minutes via WP-Cron. Each batch is POSTed to
 * `https://api.shopwalk.com/api/v1/plugin/sync/batch` (license-authenticated).
 *
 * Removing the `shopwalk/` directory from the plugin leaves Tier 1 (UCP)
 * fully functional — this class has no callers from `core/`.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Sync — push-only sync queue.
 */
final class Shopwalk_Sync {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Batch size for API pushes.
	 */
	private const BATCH_SIZE = 25;

	/**
	 * Queue option name.
	 */
	private const QUEUE_OPTION = 'shopwalk_sync_queue';

	/**
	 * Max queue size.
	 */
	private const QUEUE_CAP = 500;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up WC product save/delete hooks + the WP-Cron flush.
	 */
	private function __construct() {
		add_action( 'save_post_product', array( $this, 'enqueue_save' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'enqueue_trash' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'enqueue_delete' ), 10, 1 );
		add_action( 'shopwalk_flush_queue', array( $this, 'flush' ) );
	}

	/**
	 * Enqueue a product save event.
	 *
	 * @param int $post_id WP post id.
	 * @return void
	 */
	public function enqueue_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}
		$this->push_to_queue( array( 'op' => 'upsert', 'product_id' => $post_id ) );
	}

	/**
	 * Enqueue a product trash event.
	 *
	 * @param int $post_id WP post id.
	 * @return void
	 */
	public function enqueue_trash( int $post_id ): void {
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}
		$this->push_to_queue( array( 'op' => 'delete', 'product_id' => $post_id ) );
	}

	/**
	 * Enqueue a permanent product delete event.
	 *
	 * @param int $post_id WP post id.
	 * @return void
	 */
	public function enqueue_delete( int $post_id ): void {
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}
		$this->push_to_queue( array( 'op' => 'delete', 'product_id' => $post_id ) );
	}

	/**
	 * Push an event onto the queue, dropping the oldest entries if we
	 * exceed QUEUE_CAP.
	 *
	 * @param array{op:string, product_id:int} $event Queue event.
	 * @return void
	 */
	private function push_to_queue( array $event ): void {
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		$queue[] = $event;
		if ( count( $queue ) > self::QUEUE_CAP ) {
			$queue = array_slice( $queue, -self::QUEUE_CAP );
		}
		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * WP-Cron flush handler. Pulls up to BATCH_SIZE events from the
	 * queue, formats them as a sync batch, and POSTs to shopwalk-api.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( ! Shopwalk_License::is_valid() ) {
			return;
		}
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		if ( count( $queue ) === 0 ) {
			return;
		}
		$batch = array_splice( $queue, 0, self::BATCH_SIZE );
		update_option( self::QUEUE_OPTION, $queue, false );

		$products = array();
		foreach ( $batch as $event ) {
			$pid = (int) ( $event['product_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			$op = (string) ( $event['op'] ?? 'upsert' );
			if ( $op === 'delete' ) {
				$products[] = array( 'external_id' => (string) $pid, 'op' => 'delete' );
				continue;
			}
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( ! $product ) {
				continue;
			}
			$products[] = array(
				'external_id'      => (string) $pid,
				'name'             => (string) $product->get_name(),
				'description'      => (string) $product->get_description(),
				'short_description' => (string) $product->get_short_description(),
				'sku'              => (string) $product->get_sku(),
				'price'            => (float) $product->get_price(),
				'compare_at_price' => (float) $product->get_regular_price(),
				'currency'         => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'in_stock'         => (bool) $product->is_in_stock(),
				'source_url'       => (string) get_permalink( $pid ),
				'op'               => 'upsert',
			);
		}
		if ( count( $products ) === 0 ) {
			return;
		}
		wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/sync/batch',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-SW-License-Key' => Shopwalk_License::key(),
					'User-Agent'      => 'shopwalk-ai-plugin/' . SHOPWALK_AI_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'site_url' => home_url(),
						'products' => $products,
					)
				),
			)
		);
	}

	/**
	 * Trigger a full catalog sync. Called by Shopwalk_Connector after
	 * activation, and from the dashboard "Sync now" button.
	 *
	 * @return int Number of products queued.
	 */
	public function full_sync(): int {
		$pids  = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		foreach ( (array) $pids as $pid ) {
			$queue[] = array( 'op' => 'upsert', 'product_id' => (int) $pid );
		}
		if ( count( $queue ) > self::QUEUE_CAP ) {
			$queue = array_slice( $queue, -self::QUEUE_CAP );
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		return count( (array) $pids );
	}
}
