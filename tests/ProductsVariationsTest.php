<?php
/**
 * Tests for UCP_Products variation extraction — the new variations[] field
 * on GET /wp-json/ucp/v1/products.
 *
 * Pure unit tests for UCP_Products::extract_variations() and
 * UCP_Products::normalize_variation_attributes(). The full REST handler
 * (UCP_Products::get_products) calls into WP/WC ($product->get_image_id(),
 * wc_get_products(), get_the_terms(), wp_get_attachment_url()...) and
 * needs a WordPress runtime to exercise — those branches are covered by
 * QIT integration runs, not this unit suite. The variation-extraction
 * helper is the only piece with non-trivial logic that's worth pinning
 * here, and it's pure (modulo wc_get_product, which we stub).
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

// Minimal class stubs so `instanceof` checks in the SUT have something to
// resolve against. These mirror the WC class names; the real WC classes
// aren't loadable in the unit-test bootstrap (no WC runtime).
if ( ! class_exists( 'WC_Product_Variable' ) ) {
	class WC_Product_Variable { // phpcs:ignore
		/** @var int[] */
		public array $children = array();
		public function get_children(): array {
			return $this->children;
		}
	}
}

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	class WC_Product_Variation { // phpcs:ignore
		public int $id                  = 0;
		public string $sku              = '';
		public ?string $price           = null;
		public ?string $regular_price   = null;
		public ?string $sale_price      = null;
		public string $stock_status     = 'instock';
		public ?int $stock_quantity     = null;
		/** @var array<string,string> */
		public array $variation_attributes = array();

		public function get_id(): int {
			return $this->id; }
		public function get_sku(): string {
			return $this->sku; }
		public function get_price() {
			return $this->price; }
		public function get_regular_price() {
			return $this->regular_price; }
		public function get_sale_price() {
			return $this->sale_price; }
		public function get_stock_status(): string {
			return $this->stock_status; }
		public function get_stock_quantity(): ?int {
			return $this->stock_quantity; }
		public function get_variation_attributes(): array {
			return $this->variation_attributes; }
	}
}

require_once __DIR__ . '/../includes/core/class-ucp-products.php';

final class ProductsVariationsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── normalize_variation_attributes ─────────────────────────────────────

	public function test_normalize_strips_attribute_pa_prefix(): void {
		$out = UCP_Products::normalize_variation_attributes(
			array(
				'attribute_pa_color' => 'red',
				'attribute_pa_size'  => 'large',
			)
		);

		$this->assertSame(
			array(
				'color' => 'red',
				'size'  => 'large',
			),
			$out
		);
	}

	public function test_normalize_strips_attribute_prefix_for_local_attrs(): void {
		// Local "custom product attributes" come back as `attribute_<slug>`
		// (no `pa_`). Both prefixes must be stripped to one shape.
		$out = UCP_Products::normalize_variation_attributes(
			array(
				'attribute_finish' => 'matte',
			)
		);

		$this->assertSame( array( 'finish' => 'matte' ), $out );
	}

	public function test_normalize_preserves_empty_any_value_attrs(): void {
		// WC encodes "any value" attributes as empty strings — keep as-is so
		// downstream consumers can decide wildcard semantics.
		$out = UCP_Products::normalize_variation_attributes(
			array(
				'attribute_pa_color' => '',
				'attribute_pa_size'  => 'L',
			)
		);

		$this->assertSame(
			array(
				'color' => '',
				'size'  => 'L',
			),
			$out
		);
	}

	public function test_normalize_handles_empty_input(): void {
		$this->assertSame( array(), UCP_Products::normalize_variation_attributes( array() ) );
	}

	// ── extract_variations ─────────────────────────────────────────────────

	public function test_extract_variations_returns_empty_for_variable_with_no_children(): void {
		$parent           = new WC_Product_Variable();
		$parent->children = array();

		$out = UCP_Products::extract_variations( $parent );

		$this->assertSame( array(), $out );
	}

	public function test_extract_variations_emits_one_entry_per_child(): void {
		$parent           = new WC_Product_Variable();
		$parent->children = array( 101, 102 );

		$v1                       = new WC_Product_Variation();
		$v1->id                   = 101;
		$v1->sku                  = 'TSHIRT-L-RED';
		$v1->price                = '21.99';
		$v1->regular_price        = '24.99';
		$v1->sale_price           = '21.99';
		$v1->stock_status         = 'instock';
		$v1->stock_quantity       = 42;
		$v1->variation_attributes = array(
			'attribute_pa_size'  => 'L',
			'attribute_pa_color' => 'red',
		);

		$v2                       = new WC_Product_Variation();
		$v2->id                   = 102;
		$v2->sku                  = 'TSHIRT-M-BLUE';
		$v2->price                = '19.99';
		$v2->regular_price        = '19.99';
		$v2->sale_price           = null;
		$v2->stock_status         = 'outofstock';
		$v2->stock_quantity       = 0;
		$v2->variation_attributes = array(
			'attribute_pa_size'  => 'M',
			'attribute_pa_color' => 'blue',
		);

		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ) use ( $v1, $v2 ) {
				return 101 === $id ? $v1 : ( 102 === $id ? $v2 : null );
			}
		);

		$out = UCP_Products::extract_variations( $parent );

		$this->assertCount( 2, $out );

		$this->assertSame( 101, $out[0]['variation_id'] );
		$this->assertSame( 'TSHIRT-L-RED', $out[0]['sku'] );
		$this->assertSame( 21.99, $out[0]['price'] );
		$this->assertSame( 24.99, $out[0]['regular_price'] );
		$this->assertSame( 21.99, $out[0]['sale_price'] );
		$this->assertSame( 'instock', $out[0]['stock_status'] );
		$this->assertSame( 42, $out[0]['stock_quantity'] );
		$this->assertSame(
			array(
				'size'  => 'L',
				'color' => 'red',
			),
			$out[0]['attributes']
		);

		$this->assertSame( 102, $out[1]['variation_id'] );
		$this->assertSame( 'TSHIRT-M-BLUE', $out[1]['sku'] );
		$this->assertSame( 19.99, $out[1]['price'] );
		$this->assertNull( $out[1]['sale_price'] );
		$this->assertSame( 'outofstock', $out[1]['stock_status'] );
		$this->assertSame( 0, $out[1]['stock_quantity'] );
	}

	public function test_extract_variations_preserves_null_stock_quantity_when_unmanaged(): void {
		// Stock managed at parent (or unlimited) → variation returns null.
		// Must round-trip as JSON null, not silently coerce to 0.
		$parent           = new WC_Product_Variable();
		$parent->children = array( 200 );

		$v                 = new WC_Product_Variation();
		$v->id             = 200;
		$v->sku            = 'NOSTOCK';
		$v->price          = '5.00';
		$v->regular_price  = '5.00';
		$v->sale_price     = null;
		$v->stock_status   = 'instock';
		$v->stock_quantity = null;

		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ) use ( $v ) {
				return 200 === $id ? $v : null;
			}
		);

		$out = UCP_Products::extract_variations( $parent );

		$this->assertCount( 1, $out );
		$this->assertNull( $out[0]['stock_quantity'] );
	}

	public function test_extract_variations_skips_children_that_dont_resolve(): void {
		// Defensive: if a child ID is in the parent's index but the post is
		// gone (orphaned variation, db drift), skip it instead of fataling.
		$parent           = new WC_Product_Variable();
		$parent->children = array( 300, 301 );

		$v                 = new WC_Product_Variation();
		$v->id             = 301;
		$v->sku            = 'ALIVE';
		$v->price          = '9.99';
		$v->regular_price  = '9.99';
		$v->stock_status   = 'instock';
		$v->stock_quantity = 1;

		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ) use ( $v ) {
				return 301 === $id ? $v : null;
			}
		);

		$out = UCP_Products::extract_variations( $parent );

		$this->assertCount( 1, $out );
		$this->assertSame( 301, $out[0]['variation_id'] );
	}

	public function test_extract_variations_handles_empty_price_strings_as_null(): void {
		// WC's get_price()/get_regular_price() return '' (not null) when
		// the price field is blank. Coerce to null so JSON consumers don't
		// see "0" for an unpriced variation.
		$parent           = new WC_Product_Variable();
		$parent->children = array( 400 );

		$v                 = new WC_Product_Variation();
		$v->id             = 400;
		$v->sku            = 'NOPRICE';
		$v->price          = '';
		$v->regular_price  = '';
		$v->sale_price     = '';
		$v->stock_status   = 'instock';
		$v->stock_quantity = null;

		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ) use ( $v ) {
				return 400 === $id ? $v : null;
			}
		);

		$out = UCP_Products::extract_variations( $parent );

		$this->assertCount( 1, $out );
		$this->assertNull( $out[0]['price'] );
		$this->assertNull( $out[0]['regular_price'] );
		$this->assertNull( $out[0]['sale_price'] );
	}
}
