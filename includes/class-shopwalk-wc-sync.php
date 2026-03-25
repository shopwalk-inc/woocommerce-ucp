<?php
/**
 * Outbound catalog sync — pushes product data TO Shopwalk API.
 * Plugin pushes, Shopwalk never pulls. Works behind any WAF.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Sync class.
 */
class Shopwalk_WC_Sync {

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
	 * Constructor — registers WC hooks and cron.
	 */
	private function __construct() {
		// Real-time sync hooks.
		add_action( 'woocommerce_update_product', array( $this, 'queue_product_sync' ) );
		add_action( 'woocommerce_new_product', array( $this, 'queue_product_sync' ) );
		add_action( 'before_delete_post', array( $this, 'handle_product_delete' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'handle_stock_change' ), 10, 3 );

		// Cron queue flush.
		add_action( 'shopwalk_flush_queue', array( $this, 'flush_queue' ) );

		// Initial sync after license activation.
		add_action( 'shopwalk_license_activated', array( $this, 'full_sync' ) );

		// AJAX: manual sync.
		add_action( 'wp_ajax_shopwalk_manual_sync', array( $this, 'ajax_manual_sync' ) );
	}

	/**
	 * Queue a product for sync.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return void
	 */
	public function queue_product_sync( int $product_id ): void {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( ! in_array( $product_id, $queue, true ) ) {
			$queue[] = $product_id;
			if ( count( $queue ) > self::QUEUE_CAP ) {
				$queue = array_slice( $queue, - self::QUEUE_CAP );
			}
			update_option( self::QUEUE_OPTION, $queue );
		}
	}

	/**
	 * Handle product deletion.
	 *
	 * @param int $post_id Post ID being deleted.
	 * @return void
	 */
	public function handle_product_delete( int $post_id ): void {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		$this->push_delete( (string) $post_id );

		// Remove from queue.
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = array_filter( $queue, fn( $id ) => (int) $id !== $post_id );
		update_option( self::QUEUE_OPTION, array_values( $queue ) );
	}

	/**
	 * Handle stock status change.
	 *
	 * @param WC_Product $product         Product object.
	 * @param string     $stock_status    New stock status.
	 * @param string     $old_stock_status Previous stock status.
	 * @return void
	 */
	public function handle_stock_change( WC_Product $product, string $stock_status, string $old_stock_status ): void {
		if ( $stock_status !== $old_stock_status ) {
			$this->queue_product_sync( $product->get_id() );
		}
	}

	/**
	 * Flush the sync queue — send queued products to Shopwalk API.
	 * Called by WP-Cron every 5 minutes.
	 *
	 * @return void
	 */
	public function flush_queue(): void {
		$queue = get_option( self::QUEUE_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}

		$batch_ids = array_splice( $queue, 0, self::BATCH_SIZE );
		update_option( self::QUEUE_OPTION, $queue );

		$products = array();
		foreach ( $batch_ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product && 'publish' === $product->get_status() ) {
				$products[] = $product;
			}
		}

		if ( ! empty( $products ) ) {
			$this->push_batch( $products );
		}
	}

	/**
	 * Full sync — push all published products to Shopwalk.
	 * Triggered on license activation and manual sync.
	 *
	 * @return void
	 */
	public function full_sync(): void {
		$page     = 1;
		$per_page = self::BATCH_SIZE;

		do {
			$products = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => $per_page,
					'page'    => $page,
					'return'  => 'objects',
				)
			);

			if ( empty( $products ) ) {
				break;
			}

			$this->push_batch( $products );
			$page++;

			if ( count( $products ) === $per_page ) {
				sleep( 1 );
			}
		} while ( count( $products ) === $per_page );

		update_option( 'shopwalk_last_sync_at', gmdate( 'c' ) );
	}

	/**
	 * Push a batch of products to the Shopwalk API.
	 *
	 * @param WC_Product[] $wc_products Array of WC_Product objects.
	 * @return bool True on success.
	 */
	public function push_batch( array $wc_products ): bool {
		$key    = get_option( 'shopwalk_license_key', '' );
		$domain = get_option( 'shopwalk_site_domain', '' );

		if ( empty( $key ) || empty( $domain ) ) {
			return false;
		}

		$products = array_map( array( 'Shopwalk_WC_Products', 'format_for_sync' ), $wc_products );

		$response = wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/pro/sync/batch',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-SW-License-Key' => $key,
					'X-SW-Domain'      => $domain,
				),
				'body'    => wp_json_encode( array( 'products' => $products ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			$count = (int) get_option( 'shopwalk_synced_count', 0 );
			update_option( 'shopwalk_synced_count', $count + count( $products ) );
			update_option( 'shopwalk_last_sync_at', gmdate( 'c' ) );
			return true;
		}

		return false;
	}

	/**
	 * Push a product deletion to the Shopwalk API.
	 *
	 * @param string $external_id Product ID.
	 * @return void
	 */
	private function push_delete( string $external_id ): void {
		$key    = get_option( 'shopwalk_license_key', '' );
		$domain = get_option( 'shopwalk_site_domain', '' );

		if ( empty( $key ) || empty( $domain ) ) {
			return;
		}

		wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/pro/sync/delete',
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-SW-License-Key' => $key,
					'X-SW-Domain'      => $domain,
				),
				'body'    => wp_json_encode( array( 'external_id' => $external_id ) ),
			)
		);
	}

	/**
	 * AJAX: manual sync triggered from WP Admin dashboard.
	 *
	 * @return void
	 */
	public function ajax_manual_sync(): void {
		check_ajax_referer( 'shopwalk_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}

		$this->full_sync();

		wp_send_json_success(
			array(
				'synced_count' => (int) get_option( 'shopwalk_synced_count', 0 ),
				'last_sync_at' => (string) get_option( 'shopwalk_last_sync_at', '' ),
				'queue_count'  => count( get_option( self::QUEUE_OPTION, array() ) ),
			)
		);
	}
}
