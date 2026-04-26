<?php
/**
 * Tests for UCP_Payment_Router — adapter lookup, dispatch, and the
 * `shopwalk_ucp_payment_adapters` filter surface.
 *
 * @package WooCommerceUCP
 */

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/core/interface-ucp-payment-adapter.php';
require_once __DIR__ . '/../includes/core/class-ucp-payment-router.php';

// Test doubles — one ready, one not-ready, both record calls for assertions.

final class ReadyTestAdapter implements UCP_Payment_Adapter_Interface {
	public static int $authorize_calls = 0;
	public static $last_order          = null;
	public static array $last_payment  = array();

	public function id(): string {
		return 'ready_test'; }
	public function is_ready(): bool {
		return true; }
	public function discovery_hint(): array {
		return array( 'gateway' => 'ready_test' ); }
	public function authorize( $order, array $payment ) {
		++self::$authorize_calls;
		self::$last_order   = $order;
		self::$last_payment = $payment;
		return true;
	}
	public static function reset(): void {
		self::$authorize_calls = 0;
		self::$last_order      = null;
		self::$last_payment    = array();
	}
}

final class NotReadyTestAdapter implements UCP_Payment_Adapter_Interface {
	public function id(): string {
		return 'not_ready_test'; }
	public function is_ready(): bool {
		return false; }
	public function discovery_hint(): array {
		return array(); }
	public function authorize( $order, array $payment ) {
		return true; // should never be called
	}
}

final class PaymentRouterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		ReadyTestAdapter::reset();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_authorize_requires_gateway(): void {
		Filters\expectApplied( 'shopwalk_ucp_payment_adapters' )->andReturn( array() );

		$err = UCP_Payment_Router::authorize( new stdClass(), array() );

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'missing_gateway', $err->get_error_code() );
	}

	public function test_authorize_rejects_unregistered_gateway(): void {
		Filters\expectApplied( 'shopwalk_ucp_payment_adapters' )
			->andReturn( array( 'ready_test' => ReadyTestAdapter::class ) );

		$err = UCP_Payment_Router::authorize( new stdClass(), array( 'gateway' => 'nope' ) );

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsupported_gateway', $err->get_error_code() );
		// The error message should list what IS supported, so the agent knows what to retry with.
		$this->assertStringContainsString( 'ready_test', $err->get_error_message() );
	}

	public function test_authorize_rejects_not_ready_adapter(): void {
		Filters\expectApplied( 'shopwalk_ucp_payment_adapters' )
			->andReturn( array( 'not_ready_test' => NotReadyTestAdapter::class ) );

		$err = UCP_Payment_Router::authorize( new stdClass(), array( 'gateway' => 'not_ready_test' ) );

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'gateway_not_ready', $err->get_error_code() );
	}

	public function test_authorize_dispatches_to_matching_adapter(): void {
		Filters\expectApplied( 'shopwalk_ucp_payment_adapters' )
			->andReturn( array( 'ready_test' => ReadyTestAdapter::class ) );

		$order   = new stdClass();
		$payment = array(
			'gateway'           => 'ready_test',
			'payment_method_id' => 'pm_xxx',
		);

		$result = UCP_Payment_Router::authorize( $order, $payment );

		$this->assertTrue( $result );
		$this->assertSame( 1, ReadyTestAdapter::$authorize_calls );
		$this->assertSame( $order, ReadyTestAdapter::$last_order );
		$this->assertSame( 'pm_xxx', ReadyTestAdapter::$last_payment['payment_method_id'] );
	}

	public function test_registry_drops_non_existent_classes(): void {
		Filters\expectApplied( 'shopwalk_ucp_payment_adapters' )
			->andReturn(
				array(
					'ready_test' => ReadyTestAdapter::class,
					'ghost'      => 'Class_That_Does_Not_Exist_Anywhere',
				)
			);

		$registry = UCP_Payment_Router::registry();

		$this->assertArrayHasKey( 'ready_test', $registry );
		$this->assertArrayNotHasKey( 'ghost', $registry );
	}

	public function test_discovery_hints_only_include_ready_adapters(): void {
		Filters\expectApplied( 'shopwalk_ucp_payment_adapters' )
			->andReturn(
				array(
					'ready_test'     => ReadyTestAdapter::class,
					'not_ready_test' => NotReadyTestAdapter::class,
				)
			);

		$hints = UCP_Payment_Router::discovery_hints();

		$this->assertArrayHasKey( 'ready_test', $hints );
		$this->assertArrayNotHasKey( 'not_ready_test', $hints );
		$this->assertSame( 'ready_test', $hints['ready_test']['gateway'] );
	}
}
