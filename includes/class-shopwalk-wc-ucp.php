<?php
/**
 * UCP REST Endpoints — 100% free, no license required, no Shopwalk account needed.
 *
 * Endpoints:
 *   GET  /wp-json/shopwalk/v1/products                         — paginated product catalog
 *   GET  /wp-json/shopwalk/v1/products/{id}                    — single product detail
 *   GET  /wp-json/shopwalk/v1/store                            — store metadata
 *   GET  /wp-json/shopwalk/v1/categories                       — product categories
 *   POST /wp-json/shopwalk/v1/checkout-sessions                — create checkout session
 *   GET  /wp-json/shopwalk/v1/checkout-sessions/{id}           — get checkout session
 *   PUT  /wp-json/shopwalk/v1/checkout-sessions/{id}           — update checkout session
 *   POST /wp-json/shopwalk/v1/checkout-sessions/{id}/complete  — complete checkout session
 *   POST /wp-json/shopwalk/v1/checkout-sessions/{id}/cancel    — cancel checkout session
 *   GET  /wp-json/shopwalk/v1/.well-known/ucp                  — UCP discovery profile
 *   GET  /.well-known/ucp                                      — UCP discovery (well-known)
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
		add_action( 'template_redirect', array( $this, 'serve_well_known_ucp' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
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
						'sanitize_callback' => static function( $val ) { return (float) $val; },
					),
					'max_price' => array(
						'default'           => '',
						'sanitize_callback' => static function( $val ) { return (float) $val; },
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

		// ── UCP Checkout Sessions ──────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/checkout-sessions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_checkout_session' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_checkout_session' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_checkout_session' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_checkout_session' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/checkout-sessions/(?P<id>[a-zA-Z0-9_-]+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_checkout_session' ),
				'permission_callback' => '__return_true',
			)
		);

		// ── UCP Discovery ──────────────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/.well-known/ucp',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ucp_profile' ),
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

		$is_licensed  = str_starts_with( (string) get_option( 'shopwalk_license_key', '' ), 'sw_lic_' ) || str_starts_with( (string) get_option( 'shopwalk_license_key', '' ), 'sw_site_' );
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

	// ─────────────────────────────────────────────────────────────────
	// UCP Checkout Session Endpoints
	// ─────────────────────────────────────────────────────────────────

	/**
	 * POST /wp-json/shopwalk/v1/checkout-sessions
	 *
	 * Create a new checkout session backed by a WooCommerce order.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_checkout_session( WP_REST_Request $request ) {
		$body       = $request->get_json_params();
		$line_items = isset( $body['line_items'] ) ? $body['line_items'] : array();

		if ( empty( $line_items ) || ! is_array( $line_items ) ) {
			return new WP_Error(
				'invalid_line_items',
				__( 'line_items array is required.', 'shopwalk-ai' ),
				array( 'status' => 400 )
			);
		}

		$order = wc_create_order( array( 'status' => 'pending' ) );
		if ( is_wp_error( $order ) ) {
			return new WP_Error(
				'order_creation_failed',
				__( 'Could not create order.', 'shopwalk-ai' ),
				array( 'status' => 500 )
			);
		}

		foreach ( $line_items as $item ) {
			$product_id = isset( $item['item']['id'] ) ? absint( $item['item']['id'] ) : 0;
			$quantity   = isset( $item['quantity'] ) ? max( 1, absint( $item['quantity'] ) ) : 1;
			$product    = wc_get_product( $product_id );

			if ( ! $product ) {
				$order->delete( true );
				return new WP_Error(
					'product_not_found',
					/* translators: %d: product ID */
					sprintf( __( 'Product %d not found.', 'shopwalk-ai' ), $product_id ),
					array( 'status' => 404 )
				);
			}

			$order->add_product( $product, $quantity );
		}

		$order->calculate_totals();

		$session_id = wp_generate_uuid4();
		$order->update_meta_data( '_shopwalk_session_id', $session_id );
		$order->update_meta_data( '_shopwalk_checkout_status', 'open' );
		$order->save();

		return new WP_REST_Response( $this->format_checkout_session( $order, $session_id ), 201 );
	}

	/**
	 * GET /wp-json/shopwalk/v1/checkout-sessions/{id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_checkout_session( WP_REST_Request $request ) {
		$session_id = $request->get_param( 'id' );
		$order      = $this->find_order_by_session( $session_id );

		if ( ! $order ) {
			return new WP_Error(
				'session_not_found',
				__( 'Checkout session not found.', 'shopwalk-ai' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( $this->format_checkout_session( $order, $session_id ), 200 );
	}

	/**
	 * PUT /wp-json/shopwalk/v1/checkout-sessions/{id}
	 *
	 * Update buyer, fulfillment (shipping), or payment information.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_checkout_session( WP_REST_Request $request ) {
		$session_id = $request->get_param( 'id' );
		$order      = $this->find_order_by_session( $session_id );

		if ( ! $order ) {
			return new WP_Error(
				'session_not_found',
				__( 'Checkout session not found.', 'shopwalk-ai' ),
				array( 'status' => 404 )
			);
		}

		$status = $order->get_meta( '_shopwalk_checkout_status' );
		if ( 'open' !== $status ) {
			return new WP_Error(
				'session_not_open',
				__( 'Checkout session is not open.', 'shopwalk-ai' ),
				array( 'status' => 409 )
			);
		}

		$body = $request->get_json_params();

		// Update buyer information.
		if ( ! empty( $body['buyer'] ) && is_array( $body['buyer'] ) ) {
			$buyer = $body['buyer'];
			if ( isset( $buyer['email'] ) ) {
				$order->set_billing_email( sanitize_email( $buyer['email'] ) );
			}
			if ( isset( $buyer['first_name'] ) ) {
				$order->set_billing_first_name( sanitize_text_field( $buyer['first_name'] ) );
			}
			if ( isset( $buyer['last_name'] ) ) {
				$order->set_billing_last_name( sanitize_text_field( $buyer['last_name'] ) );
			}
		}

		// Update fulfillment / shipping address.
		if ( ! empty( $body['fulfillment'] ) && is_array( $body['fulfillment'] ) ) {
			$ship = $body['fulfillment'];
			if ( isset( $ship['first_name'] ) ) {
				$order->set_shipping_first_name( sanitize_text_field( $ship['first_name'] ) );
			}
			if ( isset( $ship['last_name'] ) ) {
				$order->set_shipping_last_name( sanitize_text_field( $ship['last_name'] ) );
			}
			if ( isset( $ship['address_1'] ) ) {
				$order->set_shipping_address_1( sanitize_text_field( $ship['address_1'] ) );
			}
			if ( isset( $ship['address_2'] ) ) {
				$order->set_shipping_address_2( sanitize_text_field( $ship['address_2'] ) );
			}
			if ( isset( $ship['city'] ) ) {
				$order->set_shipping_city( sanitize_text_field( $ship['city'] ) );
			}
			if ( isset( $ship['state'] ) ) {
				$order->set_shipping_state( sanitize_text_field( $ship['state'] ) );
			}
			if ( isset( $ship['postcode'] ) ) {
				$order->set_shipping_postcode( sanitize_text_field( $ship['postcode'] ) );
			}
			if ( isset( $ship['country'] ) ) {
				$order->set_shipping_country( sanitize_text_field( $ship['country'] ) );
			}
		}

		$order->calculate_totals();
		$order->save();

		return new WP_REST_Response( $this->format_checkout_session( $order, $session_id ), 200 );
	}

	/**
	 * POST /wp-json/shopwalk/v1/checkout-sessions/{id}/complete
	 *
	 * Finalize the checkout session and set order to processing.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete_checkout_session( WP_REST_Request $request ) {
		$session_id = $request->get_param( 'id' );
		$order      = $this->find_order_by_session( $session_id );

		if ( ! $order ) {
			return new WP_Error(
				'session_not_found',
				__( 'Checkout session not found.', 'shopwalk-ai' ),
				array( 'status' => 404 )
			);
		}

		$status = $order->get_meta( '_shopwalk_checkout_status' );
		if ( 'open' !== $status ) {
			return new WP_Error(
				'session_not_open',
				__( 'Checkout session is not open and cannot be completed.', 'shopwalk-ai' ),
				array( 'status' => 409 )
			);
		}

		$order->update_status( 'processing', __( 'Completed via UCP checkout session.', 'shopwalk-ai' ) );
		$order->update_meta_data( '_shopwalk_checkout_status', 'completed' );
		$order->save();

		return new WP_REST_Response(
			array(
				'id'       => $session_id,
				'status'   => 'completed',
				'order_id' => (string) $order->get_id(),
				'totals'   => array(
					array( 'type' => 'subtotal', 'amount' => (int) round( $order->get_subtotal() * 100 ) ),
					array( 'type' => 'tax',      'amount' => (int) round( $order->get_total_tax() * 100 ) ),
					array( 'type' => 'shipping',  'amount' => (int) round( (float) $order->get_shipping_total() * 100 ) ),
					array( 'type' => 'total',    'amount' => (int) round( $order->get_total() * 100 ) ),
				),
				'fulfillment' => $this->format_fulfillment( $order ),
			),
			200
		);
	}

	/**
	 * POST /wp-json/shopwalk/v1/checkout-sessions/{id}/cancel
	 *
	 * Cancel the checkout session and set order to cancelled.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_checkout_session( WP_REST_Request $request ) {
		$session_id = $request->get_param( 'id' );
		$order      = $this->find_order_by_session( $session_id );

		if ( ! $order ) {
			return new WP_Error(
				'session_not_found',
				__( 'Checkout session not found.', 'shopwalk-ai' ),
				array( 'status' => 404 )
			);
		}

		$status = $order->get_meta( '_shopwalk_checkout_status' );
		if ( 'open' !== $status ) {
			return new WP_Error(
				'session_not_open',
				__( 'Checkout session is not open and cannot be cancelled.', 'shopwalk-ai' ),
				array( 'status' => 409 )
			);
		}

		$order->update_status( 'cancelled', __( 'Cancelled via UCP checkout session.', 'shopwalk-ai' ) );
		$order->update_meta_data( '_shopwalk_checkout_status', 'cancelled' );
		$order->save();

		return new WP_REST_Response(
			array(
				'id'     => $session_id,
				'status' => 'cancelled',
			),
			200
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// UCP Discovery
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Serve the UCP business profile at /.well-known/ucp (outside REST API).
	 *
	 * @return void
	 */
	public function serve_well_known_ucp(): void {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : '';
		if ( '/.well-known/ucp' !== $request_uri ) {
			return;
		}
		header( 'Content-Type: application/json' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $this->build_ucp_profile() );
		exit;
	}

	/**
	 * GET /wp-json/shopwalk/v1/.well-known/ucp
	 *
	 * @return WP_REST_Response
	 */
	public function get_ucp_profile(): WP_REST_Response {
		return new WP_REST_Response( $this->build_ucp_profile(), 200 );
	}

	/**
	 * Build the UCP business discovery profile.
	 *
	 * @return array
	 */
	private function build_ucp_profile(): array {
		$store_url = get_site_url();
		$rest_base = rest_url( 'shopwalk/v1' );

		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => $store_url,
			'ucp'         => array(
				'version'  => '1.0',
				'services' => array(
					'dev.ucp.shopping' => array(
						array(
							'version' => '1.0',
							'spec'    => 'https://spec.ucp.dev/shopping/1.0',
							'rest'    => array(
								'endpoint' => $rest_base,
							),
						),
					),
					'dev.ucp.catalog'  => array(
						array(
							'version' => '1.0',
							'spec'    => 'https://spec.ucp.dev/catalog/1.0',
							'rest'    => array(
								'endpoint' => $rest_base,
							),
						),
					),
				),
				'capabilities'     => array(
					'dev.ucp.checkout' => array(
						array(
							'version' => '1.0',
							'spec'    => 'https://spec.ucp.dev/checkout/1.0',
						),
					),
				),
				'payment_handlers' => array(
					'dev.ucp.payment.deferred' => array(
						array(
							'id'      => 'wc_store_payment',
							'version' => '1.0',
						),
					),
				),
			),
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// Order Status Webhook
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Notify Shopwalk API when a UCP order changes status.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from     Previous status slug.
	 * @param string   $to       New status slug.
	 * @param WC_Order $order    Order object.
	 * @return void
	 */
	public function on_order_status_changed( $order_id, $from, $to, $order ): void {
		$session_id = $order->get_meta( '_shopwalk_session_id' );
		if ( empty( $session_id ) ) {
			return; // Not a Shopwalk UCP order.
		}

		$license_key = get_option( 'shopwalk_license_key' );
		$domain      = get_option( 'shopwalk_site_domain' );
		if ( empty( $license_key ) ) {
			return;
		}

		$result = wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/orders/status',
			array(
				'timeout'  => 5,
				'headers'  => array(
					'Content-Type'     => 'application/json',
					'X-SW-License-Key' => $license_key,
					'X-SW-Domain'      => $domain,
				),
				'body'     => wp_json_encode(
					array(
						'session_id'  => $session_id,
						'order_id'    => (string) $order_id,
						'from_status' => $from,
						'to_status'   => $to,
					)
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			error_log( 'Shopwalk order status push failed: ' . $result->get_error_message() );
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Find a WooCommerce order by its Shopwalk session ID.
	 *
	 * @param string $session_id UCP session ID.
	 * @return WC_Order|null
	 */
	private function find_order_by_session( string $session_id ) {
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_shopwalk_session_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $session_id,            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'limit'      => 1,
			)
		);
		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Format a WC order as a UCP CheckoutSession response.
	 *
	 * All monetary amounts are in cents (integer).
	 *
	 * @param WC_Order $order      WooCommerce order object.
	 * @param string   $session_id UCP session ID.
	 * @return array
	 */
	private function format_checkout_session( WC_Order $order, string $session_id ): array {
		$line_items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$line_items[] = array(
				'item'     => array(
					'id'    => $product ? $product->get_id() : 0,
					'title' => $item->get_name(),
					'price' => $product ? (int) round( (float) $product->get_price() * 100 ) : 0,
				),
				'quantity' => $item->get_quantity(),
				'total'    => (int) round( (float) $item->get_total() * 100 ),
			);
		}

		return array(
			'id'          => $session_id,
			'status'      => $order->get_meta( '_shopwalk_checkout_status' ) ?: 'open',
			'currency'    => $order->get_currency(),
			'buyer'       => array(
				'email'      => $order->get_billing_email() ?: null,
				'first_name' => $order->get_billing_first_name() ?: null,
				'last_name'  => $order->get_billing_last_name() ?: null,
			),
			'line_items'  => $line_items,
			'totals'      => array(
				array( 'type' => 'subtotal', 'amount' => (int) round( $order->get_subtotal() * 100 ) ),
				array( 'type' => 'tax',      'amount' => (int) round( $order->get_total_tax() * 100 ) ),
				array( 'type' => 'shipping',  'amount' => (int) round( (float) $order->get_shipping_total() * 100 ) ),
				array( 'type' => 'total',    'amount' => (int) round( $order->get_total() * 100 ) ),
			),
			'fulfillment' => $this->format_fulfillment( $order ),
		);
	}

	/**
	 * Format fulfillment / shipping information from a WC order.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array|null Shipping address or null if not set.
	 */
	private function format_fulfillment( WC_Order $order ) {
		$address_1 = $order->get_shipping_address_1();
		$city      = $order->get_shipping_city();
		$country   = $order->get_shipping_country();

		if ( empty( $address_1 ) && empty( $city ) && empty( $country ) ) {
			return null;
		}

		return array(
			'first_name' => $order->get_shipping_first_name() ?: null,
			'last_name'  => $order->get_shipping_last_name() ?: null,
			'address_1'  => $address_1 ?: null,
			'address_2'  => $order->get_shipping_address_2() ?: null,
			'city'       => $city ?: null,
			'state'      => $order->get_shipping_state() ?: null,
			'postcode'   => $order->get_shipping_postcode() ?: null,
			'country'    => $country ?: null,
		);
	}
}
