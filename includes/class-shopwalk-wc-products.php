<?php
/**
 * Catalog / Products API — exposes WooCommerce products for Shopwalk AI.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Products class.
 */
class Shopwalk_WC_Products {

	/** REST namespace constant for status checks. */
	const REST_NAMESPACE = 'shopwalk-wc/v1';

	/**
	 * Register Routes.
	 *
	 * @param string $namespace Parameter.
	 */
	public function register_routes( string $namespace ): void {
		// List products (public catalog).
		register_rest_route(
			$namespace,
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_products' ),
				'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_public_permission' ),
				'args'                => array(
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'search'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'category'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'min_price' => array(
						'type'    => 'number',
						'default' => null,
					),
					'max_price' => array(
						'type'    => 'number',
						'default' => null,
					),
					'in_stock'  => array(
						'type'    => 'boolean',
						'default' => null,
					),
				),
			)
		);

		// Get single product (public).
		register_rest_route(
			$namespace,
			'/products/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product' ),
				'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_public_permission' ),
			)
		);

		// Product availability (public — used by AI agents before adding to cart).
		register_rest_route(
			$namespace,
			'/products/(?P<id>\d+)/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_availability' ),
				'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_public_permission' ),
			)
		);

		// List categories (public).
		register_rest_route(
			$namespace,
			'/categories',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_categories' ),
				'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_public_permission' ),
			)
		);

		// Product reviews (protected — requires Inbound API Key).
		register_rest_route(
			$namespace,
			'/reviews',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_reviews' ),
					'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_permission' ),
					'args'                => array(
						'product_id' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'submit_review' ),
					'permission_callback' => array( Shopwalk_WC_Auth::class, 'check_permission' ),
					'args'                => array(
						'product_id'        => array(
							'type'     => 'integer',
							'required' => true,
						),
						'rating'            => array(
							'type'     => 'integer',
							'required' => true,
						),
						'content'           => array(
							'type'     => 'string',
							'required' => true,
						),
						'author_name'       => array(
							'type'     => 'string',
							'required' => true,
						),
						'verified_purchase' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Endpoint handlers.
	// -------------------------------------------------------------------------

	/**
	 * List products in UCP normalized format.
	 * Supports: page, per_page, search, category, min_price, max_price, in_stock
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function list_products( WP_REST_Request $request ): WP_REST_Response {
		$page      = (int) $request->get_param( 'page' );
		$per_page  = (int) $request->get_param( 'per_page' );
		$search    = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$category  = sanitize_text_field( $request->get_param( 'category' ) ?? '' );
		$min_price = $request->get_param( 'min_price' );
		$max_price = $request->get_param( 'max_price' );
		$in_stock  = $request->get_param( 'in_stock' );

		$args = array(
			'status'  => 'publish',
			'limit'   => $per_page,
			'page'    => $page,
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		if ( $category ) {
			$term = get_term_by( 'slug', $category, 'product_cat' );
			if ( $term ) {
				$args['category'] = array( $term->term_id );
			}
		}

		// in_stock filter.
		if ( null !== $in_stock ) {
			$args['stock_status'] = $in_stock ? 'instock' : 'outofstock';
		}

		// price range filter — WC_Product_Query doesn't reliably pass meta_query,.
		// so we fetch all and post-filter by price in PHP.
		$needs_price_filter = ( null !== $min_price || null !== $max_price );

		$query    = new WC_Product_Query( $args );
		$products = $query->get_products();

		// Post-filter by price if needed.
		if ( $needs_price_filter ) {
			$products = array_values(
				array_filter(
					$products,
					function ( $p ) use ( $min_price, $max_price ) {
						$price = (float) $p->get_price();
						if ( null !== $min_price && $price < (float) $min_price ) {
							return false;
						}
						if ( null !== $max_price && $price > (float) $max_price ) {
							return false;
						}
						return true;
					}
				)
			);
		}

		// Get total count for pagination — re-run without paging for accurate total.
		$count_args           = $args;
		$count_args['limit']  = -1;
		$count_args['return'] = 'objects';
		unset( $count_args['page'] );
		$all_for_count = ( new WC_Product_Query( $count_args ) )->get_products();
		if ( $needs_price_filter ) {
			$all_for_count = array_filter(
				$all_for_count,
				function ( $p ) use ( $min_price, $max_price ) {
					$price = (float) $p->get_price();
					if ( null !== $min_price && $price < (float) $min_price ) {
						return false;
					}
					if ( null !== $max_price && $price > (float) $max_price ) {
						return false;
					}
					return true;
				}
			);
		}
		$total = count( $all_for_count );

		$items = array_map( array( $this, 'format_product' ), $products );

		return new WP_REST_Response(
			array(
				'items'      => $items,
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => (int) ceil( $total / max( $per_page, 1 ) ),
				),
			),
			200
		);
	}

	/**
	 * Get a single product (detailed).
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function get_product( WP_REST_Request $request ): WP_REST_Response {
		$product = wc_get_product( (int) $request->get_param( 'id' ) );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'PRODUCT_NOT_FOUND',
						'message' => 'Product not found.',
					),
				),
				404
			);
		}

		return new WP_REST_Response( $this->format_product( $product, true ), 200 );
	}

	/**
	 * Product Availability Endpoint.
	 * Returns real-time stock and pricing for a product (and all variants if variable).
	 * Used by AI agents before adding a product to cart.
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function get_availability( WP_REST_Request $request ): WP_REST_Response {
		$product = wc_get_product( (int) $request->get_param( 'id' ) );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => 'PRODUCT_NOT_FOUND',
						'message' => 'Product not found.',
					),
				),
				404
			);
		}

		$currency   = get_woocommerce_currency();
		$price      = (float) $product->get_price();
		$sale_price = $product->get_sale_price() ? (float) $product->get_sale_price() : null;

		$availability = array(
			'id'               => $product->get_id(),
			'name'             => $product->get_name(),
			'sku'              => $product->get_sku(),
			'currency'         => $currency,
			'in_stock'         => $product->is_in_stock(),
			'stock_status'     => $product->get_stock_status(),
			'quantity'         => $product->get_stock_quantity(),
			'manage_stock'     => $product->managing_stock(),
			'price_cents'      => (int) round( $price * 100 ),
			'sale_price_cents' => null !== $sale_price ? (int) round( $sale_price * 100 ) : null,
			'backorders'       => $product->get_backorders(),
		);

		// Variable product — return each variation's availability.
		if ( $product->is_type( 'variable' ) ) {
			// @var WC_Product_Variable $product -- phpcs:ignore Squiz.Commenting.InlineComment
			$variants = array();
			foreach ( $product->get_available_variations() as $var_data ) {
				$variation = wc_get_product( $var_data['variation_id'] );
				if ( ! $variation ) {
					continue;
				}
				$var_sale   = $variation->get_sale_price() ? (float) $variation->get_sale_price() : null;
				$variants[] = array(
					'id'               => $variation->get_id(),
					'sku'              => $variation->get_sku(),
					'attributes'       => $var_data['attributes'],
					'in_stock'         => $variation->is_in_stock(),
					'stock_status'     => $variation->get_stock_status(),
					'quantity'         => $variation->get_stock_quantity(),
					'manage_stock'     => $variation->managing_stock(),
					'price_cents'      => (int) round( (float) $variation->get_price() * 100 ),
					'sale_price_cents' => null !== $var_sale ? (int) round( $var_sale * 100 ) : null,
					'backorders'       => $variation->get_backorders(),
				);
			}
			$availability['variants'] = $variants;
		}

		return new WP_REST_Response( $availability, 200 );
	}

	/**
	 * List product categories.
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function list_categories( WP_REST_Request $request ): WP_REST_Response {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		$categories = array();
		foreach ( $terms as $term ) {
			$thumbnail_id = get_term_meta( $term->term_id, 'thumbnail_id', true );
			$image_url    = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : null;

			$categories[] = array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
				'parent_id'   => $term->parent ? $term->parent : null,
				'count'       => $term->count,
				'image'       => $image_url,
			);
		}

		return new WP_REST_Response( array( 'items' => $categories ), 200 );
	}

	/**
	 * Reviews Endpoint.
	 * Returns the most recent approved reviews for a given product.
	 * Protected by Inbound API Key (Authorization: Bearer <key>).
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function get_reviews( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product_id = intval( $request->get_param( 'product_id' ) );
		if ( ! $product_id ) {
			return new WP_Error( 'missing_param', 'product_id required', array( 'status' => 400 ) );
		}

		$reviews = get_comments(
			array(
				'post_id' => $product_id,
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => 10,
				'orderby' => 'comment_date',
				'order'   => 'DESC',
			)
		);

		$data = array();
		foreach ( $reviews as $review ) {
			$rating = intval( get_comment_meta( $review->comment_ID, 'rating', true ) );
			$data[] = array(
				'id'       => $review->comment_ID,
				'rating'   => $rating,
				'author'   => $review->comment_author,
				'date'     => $review->comment_date,
				'content'  => wp_strip_all_tags( $review->comment_content ),
				'verified' => (bool) wc_review_is_from_verified_owner( $review->comment_ID ),
			);
		}

		return rest_ensure_response(
			array(
				'reviews' => $data,
				'total'   => count( $data ),
			)
		);
	}

	/**
	 * Submit Review Endpoint.
	 * Allows Shopwalk users to post a review to the merchant's WooCommerce store.
	 * Review is held for merchant moderation (comment_status = 'hold').
	 * Protected by Inbound API Key (Authorization: Bearer <key>).
	 *
	 * @param WP_REST_Request $request Parameter.
	 */
	public function submit_review( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$product_id        = intval( $request->get_param( 'product_id' ) );
		$rating            = intval( $request->get_param( 'rating' ) );
		$content           = sanitize_textarea_field( $request->get_param( 'content' ) );
		$author_name       = sanitize_text_field( $request->get_param( 'author_name' ) );
		$verified_purchase = (bool) $request->get_param( 'verified_purchase' );

		// Validate required fields.
		if ( ! $product_id || ! $rating || ! $content || ! $author_name ) {
			return new WP_Error( 'missing_params', 'product_id, rating, content, and author_name are required', array( 'status' => 400 ) );
		}

		// Validate rating range.
		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'invalid_rating', 'Rating must be between 1 and 5', array( 'status' => 400 ) );
		}

		// Check product exists.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) );
		}

		// Create the WC review (as a comment), held for merchant moderation.
		$comment_data = array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => $author_name,
			'comment_author_email' => 'shopwalk-review-' . $product_id . '@shopwalk.com',
			'comment_content'      => $content,
			'comment_type'         => 'review',
			'comment_status'       => 'hold',
			'comment_meta'         => array(
				'rating'          => $rating,
				'verified'        => $verified_purchase ? 1 : 0,
				'shopwalk_review' => 1,
			),
		);

		$comment_id = wp_insert_comment( $comment_data );

		if ( ! $comment_id || is_wp_error( $comment_id ) ) {
			return new WP_Error( 'insert_failed', 'Failed to create review', array( 'status' => 500 ) );
		}

		// Add comment meta separately (wp_insert_comment doesn't support comment_meta in all WP versions).
		update_comment_meta( $comment_id, 'rating', $rating );
		update_comment_meta( $comment_id, 'verified', $verified_purchase ? 1 : 0 );
		update_comment_meta( $comment_id, 'shopwalk_review', 1 );

		return rest_ensure_response(
			array(
				'id'                => $comment_id,
				'product_id'        => $product_id,
				'rating'            => $rating,
				'author'            => $author_name,
				'content'           => $content,
				'verified_purchase' => $verified_purchase,
				'status'            => 'pending',
				'created_at'        => current_time( 'c' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Format a WC_Product into UCP-normalized JSON.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @param bool       $detailed Include description, attributes, and variants.
	 */
	public function format_product( WC_Product $product, bool $detailed = false ): array {
		$images         = array();
		$attachment_ids = $product->get_gallery_image_ids();
		$main_image_id  = $product->get_image_id();

		if ( $main_image_id ) {
			array_unshift( $attachment_ids, $main_image_id );
		}

		foreach ( array_unique( $attachment_ids ) as $i => $img_id ) {
			$url = wp_get_attachment_image_url( $img_id, 'large' );
			$alt = get_post_meta( $img_id, '_wp_attachment_image_alt', true );
			if ( $url ) {
				$images[] = array(
					'url'      => $url,
					'alt'      => $alt ? $alt : $product->get_name(),
					'position' => $i,
				);
			}
		}

		$categories = array();
		foreach ( $product->get_category_ids() as $cat_id ) {
			$term = get_term( $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$categories[] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}

		$price      = (float) $product->get_price();
		$sale_price = $product->get_sale_price() ? (float) $product->get_sale_price() : null;

		$item = array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'sku'               => $product->get_sku(),
			// UCP standard: price in cents (integer).
			'price_cents'       => (int) round( $price * 100 ),
			'sale_price_cents'  => null !== $sale_price ? (int) round( $sale_price * 100 ) : null,
			// Legacy float fields kept for backward compatibility.
			'price'             => $price,
			'regular_price'     => (float) $product->get_regular_price(),
			'sale_price'        => $sale_price,
			'currency'          => get_woocommerce_currency(),
			'in_stock'          => $product->is_in_stock(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'categories'        => $categories,
			'images'            => $images,
			'url'               => $product->get_permalink(),
			'average_rating'    => (float) $product->get_average_rating(),
			'rating_count'      => (int) $product->get_rating_count(),
		);

		if ( $detailed ) {
			$item['description'] = wp_strip_all_tags( $product->get_description() );
			$item['weight']      = $product->get_weight();
			$item['dimensions']  = array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			);

			// Attributes.
			$attributes = array();
			foreach ( $product->get_attributes() as $attr ) {
				if ( $attr instanceof WC_Product_Attribute ) {
					$attributes[] = array(
						'name'    => wc_attribute_label( $attr->get_name() ),
						'options' => $attr->get_options(),
					);
				}
			}
			$item['attributes'] = $attributes;

			// Variations (if variable product).
			if ( $product->is_type( 'variable' ) ) {
				$variations = array();
				foreach ( $product->get_available_variations() as $var ) {
					$var_sale = $var['display_price'] !== $var['display_regular_price']
						? (int) round( (float) $var['display_price'] * 100 )
						: null;

					$variations[] = array(
						'id'               => $var['variation_id'],
						'sku'              => $var['sku'],
						'price_cents'      => (int) round( (float) $var['display_price'] * 100 ),
						'sale_price_cents' => $var_sale,
						// Legacy float fields.
						'price'            => (float) $var['display_price'],
						'regular_price'    => (float) $var['display_regular_price'],
						'in_stock'         => $var['is_in_stock'],
						'attributes'       => $var['attributes'],
						'image'            => $var['image']['url'] ?? null,
					);
				}
				$item['variants'] = $variations;
			}
		}

		return $item;
	}
}
