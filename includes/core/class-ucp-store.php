<?php
/**
 * UCP Store endpoint — GET /wp-json/ucp/v1/store
 *
 * Returns store metadata: name, description, product count, currency,
 * Shopwalk connection status. Used by the Shopwalk probe and sync pipeline.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

final class UCP_Store {

	public static function register_routes(): void {
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/store',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_store' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function get_store(): WP_REST_Response {
		$product_counts = wp_count_posts( 'product' );
		$total          = (int) ( $product_counts->publish ?? 0 );

		// Count in-stock products
		$in_stock = (int) wc_get_products( array(
			'status'       => 'publish',
			'stock_status' => 'instock',
			'limit'        => 0,
			'return'       => 'ids',
			'paginate'     => true,
		) )->total;

		$license_key = get_option( 'shopwalk_license_key', '' );

		return new WP_REST_Response(
			array(
				'name'                => (string) get_bloginfo( 'name' ),
				'url'                 => (string) home_url(),
				'description'         => (string) get_bloginfo( 'description' ),
				'currency'            => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'product_count'       => $total,
				'in_stock_count'      => $in_stock,
				'shopwalk_connected'  => ! empty( $license_key ),
				'shopwalk_partner_id' => get_option( 'shopwalk_partner_id', '' ),
				'ucp_version'         => '1.0',
				'plugin_version'      => defined( 'SHOPWALK_AI_VERSION' ) ? SHOPWALK_AI_VERSION : '',
			),
			200
		);
	}
}
