<?php
/**
 * UCP Products endpoint — GET /wp-json/ucp/v1/products
 *
 * Returns paginated product data for the Shopwalk sync pipeline.
 * This is the endpoint shopwalk-api calls to fetch products during sync.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

final class UCP_Products {

	public static function register_routes(): void {
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_products' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public static function get_products( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 250, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$query = wc_get_products( array(
			'status'   => 'publish',
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'ID',
			'order'    => 'ASC',
		) );

		$products = array();
		foreach ( $query->products as $product ) {
			/** @var WC_Product $product */
			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';

			$gallery_ids  = $product->get_gallery_image_ids();
			$gallery_urls = array();
			foreach ( $gallery_ids as $gid ) {
				$url = wp_get_attachment_url( $gid );
				if ( $url ) {
					$gallery_urls[] = $url;
				}
			}

			$categories = array();
			$terms      = get_the_terms( $product->get_id(), 'product_cat' );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$categories[] = $term->name;
				}
			}

			$data = array(
				'id'                => (string) $product->get_id(),
				'name'              => $product->get_name(),
				'slug'              => $product->get_slug(),
				'type'              => $product->get_type(),
				'status'            => $product->get_status(),
				'sku'               => $product->get_sku(),
				'description'       => $product->get_description(),
				'short_description' => $product->get_short_description(),
				'price'             => (float) $product->get_price(),
				'regular_price'     => (float) $product->get_regular_price(),
				'sale_price'        => $product->get_sale_price() ? (float) $product->get_sale_price() : null,
				'on_sale'           => $product->is_on_sale(),
				'in_stock'          => $product->is_in_stock(),
				'stock_quantity'    => $product->get_stock_quantity(),
				'stock_status'      => $product->get_stock_status(),
				'categories'        => $categories,
				'image_url'         => $image_url,
				'gallery_urls'      => $gallery_urls,
				'permalink'         => get_permalink( $product->get_id() ),
				'date_created'      => $product->get_date_created() ? $product->get_date_created()->date( 'c' ) : null,
				'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->date( 'c' ) : null,
			);

			// Add brand if available (from a brand taxonomy or attribute)
			$brand = $product->get_attribute( 'brand' );
			if ( $brand ) {
				$data['brand'] = $brand;
			}

			$products[] = $data;
		}

		$response = new WP_REST_Response(
			array(
				'products' => $products,
				'total'    => (int) $query->total,
				'pages'    => (int) $query->max_num_pages,
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);

		$response->header( 'X-WP-Total', (string) $query->total );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );

		return $response;
	}
}
