<?php
/**
 * UCP REST Endpoints — 100% free, no license required, no Shopwalk account needed.
 *
 * Endpoints:
 *   GET /wp-json/shopwalk/v1/products      — paginated product catalog
 *   GET /wp-json/shopwalk/v1/products/{id} — single product detail
 *   GET /wp-json/shopwalk/v1/store         — store metadata
 *   GET /wp-json/shopwalk/v1/categories    — product categories
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_UCP class.
 */
class Shopwalk_WC_UCP {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * REST API namespace.
	 */
	private const NAMESPACE = 'shopwalk/v1';

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
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register UCP REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// Products list.
		register_rest_route(
			self::NAMESPACE,
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_products' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'in_stock' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'category' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'min_price' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'max_price' => array(
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'orderby'  => array(
						'default'           => 'date',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order'    => array(
						'default'           => 'desc',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Single product.
		register_rest_route(
			self::NAMESPACE,
			'/products/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_product' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Store info.
		register_rest_route(
			self::NAMESPACE,
			'/store',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_store' ),
				'permission_callback' => '__return_true',
			)
		);

		// Categories.
		register_rest_route(
			self::NAMESPACE,
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_categories' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /wp-json/shopwalk/v1/products
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_products( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$search   = sanitize_text_field( $request->get_param( 'search' ) );
		$in_stock = $request->get_param( 'in_stock' );
		$category = sanitize_text_field( $request->get_param( 'category' ) );
		$min_price = $request->get_param( 'min_price' );
		$max_price = $request->get_param( 'max_price' );
		$orderby  = sanitize_text_field( $request->get_param( 'orderby' ) );
		$order    = strtoupper( sanitize_text_field( $request->get_param( 'order' ) ) );

		$allowed_orderby = array( 'date', 'price', 'title', 'rating', 'popularity' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'date';
		}
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$args = array(
			'status'   => 'publish',
			'limit'    => $per_page,
			'page'     => $page,
			'return'   => 'objects',
			'orderby'  => $orderby,
			'order'    => $order,
			'paginate' => true,
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		if ( '1' === $in_stock || 'true' === $in_stock ) {
			$args['stock_status'] = 'instock';
		}

		if ( ! empty( $category ) ) {
			$term = get_term_by( 'slug', $category, 'product_cat' );
			if ( ! $term ) {
				$term = get_term_by( 'name', $category, 'product_cat' );
			}
			if ( $term ) {
				$args['category'] = array( $term->slug );
			}
		}

		if ( ! empty( $min_price ) || ! empty( $max_price ) ) {
			$args['meta_query'] = array( 'relation' => 'AND' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			if ( ! empty( $min_price ) ) {
				$args['meta_query'][] = array(
					'key'     => '_price',
					'value'   => floatval( $min_price ),
					'compare' => '>=',
					'type'    => 'NUMERIC',
				);
			}
			if ( ! empty( $max_price ) ) {
				$args['meta_query'][] = array(
					'key'     => '_price',
					'value'   => floatval( $max_price ),
					'compare' => '<=',
					'type'    => 'NUMERIC',
				);
			}
		}

		$result   = wc_get_products( $args );
		$products = array_map( array( 'Shopwalk_WC_Products', 'format' ), $result->products );

		return new WP_REST_Response(
			array(
				'products'    => $products,
				'total'       => (int) $result->total,
				'total_pages' => (int) $result->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			),
			200
		);
	}

	/**
	 * GET /wp-json/shopwalk/v1/products/{id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_product( WP_REST_Request $request ) {
		$id      = absint( $request->get_param( 'id' ) );
		$product = wc_get_product( $id );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'shopwalk-ai' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( Shopwalk_WC_Products::format( $product ), 200 );
	}

	/**
	 * GET /wp-json/shopwalk/v1/store
	 *
	 * @return WP_REST_Response
	 */
	public function get_store(): WP_REST_Response {
		$product_counts = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => -1,
				'return'  => 'ids',
				'paginate' => true,
			)
		);

		$in_stock_count = wc_get_products(
			array(
				'status'       => 'publish',
				'stock_status' => 'instock',
				'limit'        => -1,
				'return'       => 'ids',
				'paginate'     => true,
			)
		);

		$is_licensed  = str_starts_with( (string) get_option( 'shopwalk_license_key', '' ), 'sw_lic_' );
		$partner_id   = get_option( 'shopwalk_partner_id', null );

		return new WP_REST_Response(
			array(
				'name'               => get_bloginfo( 'name' ),
				'url'                => get_site_url(),
				'description'        => wp_strip_all_tags( get_bloginfo( 'description' ) ),
				'currency'           => get_woocommerce_currency(),
				'currency_symbol'    => get_woocommerce_currency_symbol(),
				'language'           => get_bloginfo( 'language' ),
				'platform'           => 'woocommerce',
				'platform_version'   => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'product_count'      => (int) $product_counts->total,
				'in_stock_count'     => (int) $in_stock_count->total,
				'shopwalk_connected' => $is_licensed,
				'shopwalk_partner_id' => $is_licensed ? $partner_id : null,
				'ucp_version'        => '1.0',
				'plugin_version'     => SHOPWALK_VERSION,
			),
			200
		);
	}

	/**
	 * GET /wp-json/shopwalk/v1/categories
	 *
	 * @return WP_REST_Response
	 */
	public function get_categories(): WP_REST_Response {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$categories = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'id'        => $term->term_id,
					'name'      => $term->name,
					'slug'      => $term->slug,
					'count'     => $term->count,
					'parent_id' => $term->parent,
				);
			}
		}

		return new WP_REST_Response( array( 'categories' => $categories ), 200 );
	}
}
