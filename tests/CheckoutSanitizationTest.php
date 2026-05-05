<?php
/**
 * Tests for UCP_Checkout::sanitize_session_payload() — F-B-5.
 *
 * The create + update boundary must:
 *   - drop unknown keys entirely
 *   - sanitize known string fields (sanitize_text_field / sanitize_email)
 *   - cast known integer fields (quantity, ids) and reject negatives, cap quantity
 *   - cast known float/money fields and reject NaN/Infinity
 *   - normalize country to A-Z uppercase
 *   - recurse on nested arrays (line_items[], destinations[], etc.)
 *
 * @package WooCommerceUCP
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );
defined( 'UCP_REST_NAMESPACE' ) || define( 'UCP_REST_NAMESPACE', 'shopwalk-ucp-agent/v1' );

require_once __DIR__ . '/stubs/wp_rest_stubs.php';
require_once __DIR__ . '/../includes/core/class-ucp-checkout.php';

final class CheckoutSanitizationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $s ) {
				$s = is_scalar( $s ) ? (string) $s : '';
				// Strip tags + trim whitespace + collapse internal NULs (mirrors WP).
				$s = strip_tags( $s );
				$s = preg_replace( '/[\x00-\x1F\x7F]/', '', $s );
				return trim( $s );
			}
		);
		Functions\when( 'sanitize_email' )->alias(
			static function ( $s ) {
				$s = is_scalar( $s ) ? (string) $s : '';
				return filter_var( $s, FILTER_SANITIZE_EMAIL ) ?: '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function sanitize( array $body ): array {
		$ref = new ReflectionMethod( UCP_Checkout::class, 'sanitize_session_payload' );
		$ref->setAccessible( true );
		return (array) $ref->invoke( null, $body );
	}

	public function test_drops_unknown_top_level_keys(): void {
		$out = $this->sanitize(
			array(
				'evil'  => 'bad',
				'buyer' => array( 'email' => 'a@b.com' ),
			)
		);
		$this->assertArrayNotHasKey( 'evil', $out );
		$this->assertArrayHasKey( 'buyer', $out );
	}

	public function test_drops_unknown_keys_in_buyer(): void {
		$out = $this->sanitize(
			array(
				'buyer' => array(
					'email'        => 'a@b.com',
					'first_name'   => 'Ada',
					'attacker_key' => '<script>x</script>',
				),
			)
		);
		$this->assertSame( 'a@b.com', $out['buyer']['email'] );
		$this->assertSame( 'Ada', $out['buyer']['first_name'] );
		$this->assertArrayNotHasKey( 'attacker_key', $out['buyer'] );
	}

	public function test_strips_html_from_string_fields(): void {
		$out = $this->sanitize(
			array(
				'buyer' => array(
					'first_name' => '<script>alert(1)</script>Ada',
				),
			)
		);
		$this->assertSame( 'alert(1)Ada', $out['buyer']['first_name'] );
	}

	public function test_normalizes_country_uppercase_strip_non_alpha(): void {
		$out = $this->sanitize(
			array(
				'buyer' => array(
					'country' => 'us-1',
				),
			)
		);
		$this->assertSame( 'US', $out['buyer']['country'] );
	}

	public function test_casts_quantity_to_int_and_caps_at_max(): void {
		$out = $this->sanitize(
			array(
				'line_items' => array(
					array(
						'product_id' => '42',
						'quantity'   => '999999999',
					),
				),
			)
		);
		$this->assertSame( 42, $out['line_items'][0]['product_id'] );
		// Cap at 10000 per the spec.
		$this->assertSame( 10000, $out['line_items'][0]['quantity'] );
	}

	public function test_rejects_negative_quantity(): void {
		$out = $this->sanitize(
			array(
				'line_items' => array(
					array(
						'product_id' => 1,
						'quantity'   => -5,
					),
				),
			)
		);
		// Negative coerced to 0.
		$this->assertSame( 0, $out['line_items'][0]['quantity'] );
	}

	public function test_recurses_into_line_items_and_drops_unknown_per_item(): void {
		$out = $this->sanitize(
			array(
				'line_items' => array(
					array(
						'product_id' => 5,
						'quantity'   => 2,
						'name'       => 'Widget',
						'evil_key'   => 'bad',
					),
					array(
						'product_id' => 6,
						'quantity'   => 1,
					),
				),
			)
		);
		$this->assertCount( 2, $out['line_items'] );
		$this->assertArrayNotHasKey( 'evil_key', $out['line_items'][0] );
		$this->assertSame( 'Widget', $out['line_items'][0]['name'] );
	}

	public function test_casts_money_fields_to_float(): void {
		$out = $this->sanitize(
			array(
				'line_items' => array(
					array(
						'product_id' => 1,
						'quantity'   => 1,
						'unit_price' => '12.50',
					),
				),
			)
		);
		$this->assertSame( 12.5, $out['line_items'][0]['unit_price'] );
	}

	public function test_rejects_nan_money(): void {
		$out = $this->sanitize(
			array(
				'line_items' => array(
					array(
						'product_id' => 1,
						'quantity'   => 1,
						'unit_price' => NAN,
					),
				),
			)
		);
		// NaN/Inf coerced to 0.
		$this->assertSame( 0.0, $out['line_items'][0]['unit_price'] );
	}

	public function test_rejects_infinity_money(): void {
		$out = $this->sanitize(
			array(
				'line_items' => array(
					array(
						'product_id' => 1,
						'quantity'   => 1,
						'unit_price' => INF,
					),
				),
			)
		);
		$this->assertSame( 0.0, $out['line_items'][0]['unit_price'] );
	}

	public function test_email_is_sanitized(): void {
		$out = $this->sanitize(
			array(
				'buyer' => array(
					'email' => 'a"<>b@example.com',
				),
			)
		);
		// FILTER_SANITIZE_EMAIL strips angle brackets/quotes.
		$this->assertSame( 'ab@example.com', $out['buyer']['email'] );
	}

	public function test_handles_missing_top_level_keys_gracefully(): void {
		$out = $this->sanitize( array() );
		$this->assertSame( array(), $out );
	}

	public function test_recurses_fulfillment_destinations(): void {
		$out = $this->sanitize(
			array(
				'fulfillment' => array(
					'methods' => array(
						array(
							'id'           => 'fm_1',
							'type'         => 'shipping',
							'destinations' => array(
								array(
									'street_address'   => "<b>1 Main St</b>",
									'address_locality' => 'NYC',
									'address_region'   => 'NY',
									'postal_code'      => '10001',
									'address_country'  => 'us',
									'evil_key'         => 'bad',
								),
							),
						),
					),
				),
			)
		);
		$dest = $out['fulfillment']['methods'][0]['destinations'][0];
		$this->assertSame( '1 Main St', $dest['street_address'] );
		$this->assertSame( 'US', $dest['address_country'] );
		$this->assertArrayNotHasKey( 'evil_key', $dest );
	}

	public function test_payment_known_keys_only(): void {
		$out = $this->sanitize(
			array(
				'payment' => array(
					'gateway'  => 'stripe',
					'token'    => 'tok_abc',
					'evil_key' => 'bad',
				),
			)
		);
		$this->assertSame( 'stripe', $out['payment']['gateway'] );
		$this->assertSame( 'tok_abc', $out['payment']['token'] );
		$this->assertArrayNotHasKey( 'evil_key', $out['payment'] );
	}
}
