<?php
/**
 * Tests for UCP_Response — UCP envelope + totals/destination helpers.
 *
 * Pure helpers, no WordPress runtime required beyond the WP_Error stub in
 * tests/bootstrap.php.
 *
 * @package ShopwalkWooCommerce
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/core/class-ucp-response.php';

final class ResponseTest extends TestCase {

	public function test_ok_wraps_in_envelope_with_spec_version(): void {
		$result = UCP_Response::ok( array( 'foo' => 'bar' ) );

		$this->assertArrayHasKey( 'ucp', $result );
		$this->assertSame( UCP_Response::VERSION, $result['ucp']['version'] );
		$this->assertSame( 'ok', $result['ucp']['status'] );
		$this->assertSame( 'bar', $result['foo'] );
	}

	public function test_ok_defaults_capability_when_none_given(): void {
		$result = UCP_Response::ok( array() );
		$this->assertSame( array( 'dev.ucp.shopping.checkout' ), $result['ucp']['capabilities'] );
	}

	public function test_ok_accepts_custom_capabilities(): void {
		$result = UCP_Response::ok( array(), array( 'dev.ucp.shopping.orders', 'dev.ucp.shopping.webhooks' ) );
		$this->assertSame( array( 'dev.ucp.shopping.orders', 'dev.ucp.shopping.webhooks' ), $result['ucp']['capabilities'] );
	}

	public function test_error_returns_wp_error_with_envelope(): void {
		$err = UCP_Response::error( 'invalid_request', 'missing field', 'fatal', 422 );

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_request', $err->get_error_code() );
		$this->assertSame( 422, $err->get_error_data()['status'] );
		$this->assertSame( 'error', $err->get_error_data()['ucp']['status'] );
		$this->assertSame( 'fatal', $err->get_error_data()['messages'][0]['severity'] );
	}

	public function test_to_cents_rounds_correctly(): void {
		$this->assertSame( 1999, UCP_Response::to_cents( 19.99 ) );
		$this->assertSame( 100, UCP_Response::to_cents( '1.00' ) );
		$this->assertSame( 0, UCP_Response::to_cents( 0 ) );
		// Classic floating-point rounding: 19.995 → 2000 (round half away from zero).
		$this->assertSame( 2000, UCP_Response::to_cents( 19.995 ) );
	}

	public function test_build_totals_omits_zero_and_negative_lines(): void {
		$totals = UCP_Response::build_totals( 100.00, 0, 0, 0, 100.00 );
		$types  = array_column( $totals, 'type' );

		$this->assertSame( array( 'subtotal', 'total' ), $types );
		$this->assertSame( 10000, $totals[0]['amount'] );
		$this->assertSame( 10000, $totals[1]['amount'] );
	}

	public function test_build_totals_includes_shipping_tax_discount_when_positive(): void {
		$totals = UCP_Response::build_totals( 100.00, 5.00, 8.25, 10.00, 103.25 );
		$map    = array();
		foreach ( $totals as $t ) {
			$map[ $t['type'] ] = $t['amount'];
		}

		$this->assertSame( 10000, $map['subtotal'] );
		$this->assertSame( 500, $map['shipping'] );
		$this->assertSame( 825, $map['tax'] );
		$this->assertSame( -1000, $map['discount'] ); // discounts are negative
		$this->assertSame( 10325, $map['total'] );
	}

	public function test_to_destination_handles_wc_style_keys(): void {
		$addr = array(
			'address_1' => '100 Main St',
			'address_2' => 'Apt 4',
			'city'      => 'Boston',
			'state'     => 'MA',
			'postcode'  => '02108',
			'country'   => 'US',
		);
		$dest = UCP_Response::to_destination( $addr );

		$this->assertSame( '100 Main St Apt 4', $dest['street_address'] );
		$this->assertSame( 'Boston', $dest['address_locality'] );
		$this->assertSame( 'MA', $dest['address_region'] );
		$this->assertSame( '02108', $dest['postal_code'] );
		$this->assertSame( 'US', $dest['address_country'] );
		$this->assertSame( 'dest_1', $dest['id'] );
	}

	public function test_to_destination_handles_ucp_style_keys(): void {
		$addr = array(
			'line1'            => '100 Main St',
			'address_locality' => 'Boston',
			'address_region'   => 'MA',
			'postal_code'      => '02108',
			'address_country'  => 'US',
		);
		$dest = UCP_Response::to_destination( $addr, 'dest_42' );

		$this->assertSame( '100 Main St', $dest['street_address'] );
		$this->assertSame( 'dest_42', $dest['id'] );
	}
}
