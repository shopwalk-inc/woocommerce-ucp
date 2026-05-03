<?php
/**
 * UCP Products endpoint — GET /wp-json/ucp/v1/products
 *
 * Returns paginated product data for the Shopwalk sync pipeline.
 * This is the endpoint shopwalk-api calls to fetch products during sync.
 *
 * Variable products carry a `variations[]` array so downstream consumers
 * (shopwalk-sync → Scylla) can ingest per-variant SKU/price/stock and the
 * checkout endpoint can resolve `variant_id` → WC variation post_id.
 * Non-variable products omit the `variations` key entirely.
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

		$query = wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => $per_page,
				'page'     => $page,
				'paginate' => true,
				'orderby'  => 'ID',
				'order'    => 'ASC',
			)
		);

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

			/*
			 * Variable products — emit one entry per variation.
			 *
			 * Performance choice: full detail inline (SKU, price, stock,
			 * attributes), not a separate endpoint. Rationale:
			 *   - The `/products` listing is paginated (default 100, max 250)
			 *     and is called once per page during a sync run, not per
			 *     request. shopwalk-sync would otherwise have to make N+1
			 *     calls to a `/products/:id/variations` route, which is
			 *     strictly worse for both wall-clock and HTTP overhead.
			 *   - `WC_Product_Variable::get_children()` + per-child
			 *     `wc_get_product()` is the same N queries either way.
			 *   - Sites with thousands of variations per product can lower
			 *     `per_page` to amortize; this matches existing pagination
			 *     guidance for the catalog sync.
			 * Non-variable products skip this branch entirely so the response
			 * shape is unchanged for the simple-product 99% case.
			 */
			if ( $product instanceof WC_Product_Variable ) {
				$variations = self::extract_variations( $product );
				if ( ! empty( $variations ) ) {
					$data['variations'] = $variations;
				}
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

	/**
	 * Extract the variations array for a variable WC product.
	 *
	 * Pulled out as a static helper so it can be unit-tested without a full
	 * WP/WC bootstrap (the test suite uses Brain\Monkey for WP function
	 * stubs and stdClass-based product doubles).
	 *
	 * Each entry shape:
	 *   variation_id   int         WC variation post_id (the int passed to
	 *                              DirectCheckoutItem.VariantID at checkout)
	 *   sku            string      may be empty if merchant left it blank
	 *   price          float|null  current price (sale or regular); null if unset
	 *   regular_price  float|null
	 *   sale_price     float|null  null if not on sale
	 *   stock_status   string      'instock' | 'outofstock' | 'onbackorder'
	 *   stock_quantity int|null    null if managed at parent or unlimited
	 *   attributes     array<string,string>  normalized name → value
	 *                                         ('attribute_pa_color' => 'red'
	 *                                         becomes 'color' => 'red')
	 *
	 * Returns an empty array for a variable product with no children defined,
	 * which the caller treats as "omit the variations key entirely" — so
	 * an empty variable product looks identical to a simple one on the wire.
	 *
	 * @param WC_Product_Variable $product Variable product to extract from.
	 * @return array<int,array<string,mixed>>
	 */
	public static function extract_variations( $product ): array {
		$child_ids = $product->get_children();
		if ( empty( $child_ids ) ) {
			return array();
		}

		$variations = array();
		foreach ( $child_ids as $child_id ) {
			$variation = wc_get_product( (int) $child_id );
			if ( ! $variation || ! ( $variation instanceof WC_Product_Variation ) ) {
				continue;
			}

			$sale_price    = $variation->get_sale_price();
			$regular_price = $variation->get_regular_price();
			$price         = $variation->get_price();

			$variations[] = array(
				'variation_id'   => (int) $variation->get_id(),
				'sku'            => (string) $variation->get_sku(),
				'price'          => '' === $price || null === $price ? null : (float) $price,
				'regular_price'  => '' === $regular_price || null === $regular_price ? null : (float) $regular_price,
				'sale_price'     => '' === $sale_price || null === $sale_price ? null : (float) $sale_price,
				'stock_status'   => (string) $variation->get_stock_status(),
				'stock_quantity' => $variation->get_stock_quantity(), // null if unmanaged.
				'attributes'     => self::normalize_variation_attributes( $variation->get_variation_attributes() ),
			);
		}

		return $variations;
	}

	/**
	 * Normalize WC's `attribute_pa_color => "red"` style into bare
	 * `color => "red"` pairs.
	 *
	 * WC encodes variation attributes with two prefixes:
	 *   - `attribute_pa_<slug>` for global product attributes (taxonomies)
	 *   - `attribute_<slug>` for local "custom product attributes"
	 * Both prefixes are stripped; the value is left as-is (slug for
	 * taxonomy-backed terms, free text for custom attributes).
	 *
	 * Empty values (when a variation matches "any value" of an attribute)
	 * are preserved as empty strings — downstream consumers can decide
	 * whether to treat them as wildcards.
	 *
	 * @param array<string,string> $raw From WC_Product_Variation::get_variation_attributes().
	 * @return array<string,string>
	 */
	public static function normalize_variation_attributes( array $raw ): array {
		$out = array();
		foreach ( $raw as $key => $value ) {
			$name = (string) $key;
			if ( str_starts_with( $name, 'attribute_pa_' ) ) {
				$name = substr( $name, strlen( 'attribute_pa_' ) );
			} elseif ( str_starts_with( $name, 'attribute_' ) ) {
				$name = substr( $name, strlen( 'attribute_' ) );
			}
			$out[ $name ] = (string) $value;
		}
		return $out;
	}
}
