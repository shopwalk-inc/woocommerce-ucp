<?php
/**
 * Tests for Shopwalk_License — status() / is_connected() helpers.
 *
 * Covers the live-state plumbing added in 3.0.46. Uses Brain\Monkey to
 * stub get_option / update_option / delete_option as a per-test in-memory
 * options store; no WordPress runtime required.
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '3.0.46-test' );

require_once __DIR__ . '/../includes/shopwalk/class-shopwalk-license.php';

final class LicenseTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$opts          = &$this->options;

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$opts ) {
				$opts[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( &$opts ) {
				unset( $opts[ $key ] );
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'home_url' )->justReturn( 'https://merchant.example' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_status_defaults_to_unlicensed_when_no_key(): void {
		$this->assertSame( 'unlicensed', Shopwalk_License::status() );
	}

	public function test_status_defaults_to_active_when_key_present_but_status_not_set(): void {
		// Back-compat: a license that pre-dates the status field should still
		// render as active until the next API contact writes a real value.
		$this->options['shopwalk_license_key'] = 'sw_site_abc123';
		$this->assertSame( 'active', Shopwalk_License::status() );
	}

	public function test_status_returns_stored_value_when_present(): void {
		$this->options['shopwalk_license_key']    = 'sw_site_abc123';
		$this->options['shopwalk_license_status'] = 'revoked';
		$this->assertSame( 'revoked', Shopwalk_License::status() );
	}

	public function test_is_connected_false_when_status_not_active(): void {
		$this->options['shopwalk_license_key']      = 'sw_site_abc123';
		$this->options['shopwalk_license_status']   = 'revoked';
		$this->options['shopwalk_last_heartbeat_at'] = time();
		$this->assertFalse( Shopwalk_License::is_connected() );
	}

	public function test_is_connected_true_on_recent_heartbeat(): void {
		$this->options['shopwalk_license_key']       = 'sw_site_abc123';
		$this->options['shopwalk_license_status']    = 'active';
		$this->options['shopwalk_last_heartbeat_at'] = time() - 60;
		$this->assertTrue( Shopwalk_License::is_connected() );
	}

	public function test_is_connected_false_when_heartbeat_stale(): void {
		$this->options['shopwalk_license_key']       = 'sw_site_abc123';
		$this->options['shopwalk_license_status']    = 'active';
		// 25h ago — past the 24h freshness window.
		$this->options['shopwalk_last_heartbeat_at'] = time() - ( 25 * HOUR_IN_SECONDS );
		$this->assertFalse( Shopwalk_License::is_connected() );
	}

	public function test_is_connected_optimistic_when_no_heartbeat_recorded_yet(): void {
		// Fresh install, license present, status defaults to active, but
		// the cron hasn't ticked yet — don't flicker red on the first page load.
		$this->options['shopwalk_license_key']    = 'sw_site_abc123';
		$this->options['shopwalk_license_status'] = 'active';
		$this->assertTrue( Shopwalk_License::is_connected() );
	}

	public function test_status_unlicensed_when_no_key_and_no_stored_status(): void {
		$this->assertSame( 'unlicensed', Shopwalk_License::status() );
		$this->assertFalse( Shopwalk_License::is_connected() );
	}
}
