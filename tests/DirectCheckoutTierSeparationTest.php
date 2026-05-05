<?php
/**
 * Tests for F-B-1 — strict Tier 1 / Tier 2 separation on the Direct Checkout
 * order-status webhook path.
 *
 * Structural invariant: `includes/core/class-ucp-direct-checkout.php` must
 *   - emit a generic `ucp_direct_checkout_order_status_changed` action
 *   - NOT make any outbound HTTP calls
 *   - NOT reference the Shopwalk API URL or any `api.shopwalk.com` endpoint
 *
 * Behavioural invariant: when the action fires AND license status is `active`,
 * the Tier 2 listener (`Shopwalk_Direct_Checkout_Notifier`) calls
 * `wp_remote_post` to the Shopwalk API. When status is anything else, no
 * outbound call is made.
 *
 * @package WooCommerceUCP
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '3.1.1-test' );

require_once __DIR__ . '/../includes/core/class-ucp-direct-checkout.php';
require_once __DIR__ . '/../includes/shopwalk/class-shopwalk-license.php';
require_once __DIR__ . '/../includes/shopwalk/class-shopwalk-direct-checkout-notifier.php';

/**
 * Minimal test double for WC_Order — only what the Tier 1 emit path and
 * Tier 2 dispatch path actually call.
 */
final class FakeWcOrderForTierSeparation { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	/** @var array<string,mixed> */
	private array $meta;
	private float $total;
	private string $currency;

	public function __construct( array $meta = array(), float $total = 0.0, string $currency = 'USD' ) {
		$this->meta     = $meta;
		$this->total    = $total;
		$this->currency = $currency;
	}

	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}

	public function get_total(): float {
		return $this->total;
	}

	public function get_currency(): string {
		return $this->currency;
	}
}

final class DirectCheckoutTierSeparationTest extends TestCase { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var array<int,array{action:string,args:array}> */
	private array $actions_done = array();

	/** @var array<int,array{url:string,args:array}> */
	private array $remote_posts = array();

	/** @var array<string,array<int,callable>> */
	private array $action_listeners = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options          = array();
		$this->actions_done     = array();
		$this->remote_posts     = array();
		$this->action_listeners = array();

		$opts          = &$this->options;
		$done          = &$this->actions_done;
		$posts         = &$this->remote_posts;
		$listeners     = &$this->action_listeners;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$opts ) {
				$opts[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $key ) use ( &$opts ) {
				unset( $opts[ $key ] );
				return true;
			}
		);

		// add_action / do_action: a tiny in-memory dispatcher. Records every
		// dispatched action with its args, and synchronously invokes any
		// listeners registered via add_action so we can drive the Tier 2
		// listener from the Tier 1 emit.
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( &$listeners ) {
				$listeners[ $hook ][] = $callback;
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			static function ( $hook, ...$args ) use ( &$done, &$listeners ) {
				$done[] = array(
					'action' => $hook,
					'args'   => $args,
				);
				foreach ( $listeners[ $hook ] ?? array() as $cb ) {
					call_user_func_array( $cb, $args );
				}
			}
		);

		// wp_remote_post — record every call. Tier 1 must NEVER call this on
		// the order-status path; Tier 2 calls it conditionally on license status.
		Functions\when( 'wp_remote_post' )->alias(
			static function ( $url, $args = array() ) use ( &$posts ) {
				$posts[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array( 'response' => array( 'code' => 200 ) );
			}
		);

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $opts = 0, $depth = 512 ) {
				return json_encode( $data, $opts, $depth );
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( '00000000-0000-4000-8000-000000000000' );
		Functions\when( 'get_site_url' )->justReturn( 'https://merchant.example' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function fire_status_change( FakeWcOrderForTierSeparation $order, int $order_id = 42, string $from = 'pending', string $to = 'processing' ): void {
		UCP_Direct_Checkout::on_order_status_changed( $order_id, $from, $to, $order );
	}

	// ── 1. Tier 1 emits the generic action ─────────────────────────────────

	public function test_tier1_emits_generic_action_with_expected_args(): void {
		$order = new FakeWcOrderForTierSeparation(
			array(
				'_shopwalk_source'   => 'direct_checkout',
				'_shopwalk_order_id' => 'sw_order_xyz',
			),
			49.99,
			'USD'
		);

		$this->fire_status_change( $order, 42, 'pending', 'processing' );

		$matching = array_values(
			array_filter(
				$this->actions_done,
				static fn( $entry ) => $entry['action'] === 'ucp_direct_checkout_order_status_changed'
			)
		);

		$this->assertCount( 1, $matching, 'Tier 1 must fire ucp_direct_checkout_order_status_changed exactly once.' );
		$args = $matching[0]['args'];
		$this->assertSame( $order, $args[0], 'arg0 must be the WC_Order instance.' );
		$this->assertSame( 42, $args[1], 'arg1 must be the WC order id.' );
		$this->assertSame( 'pending', $args[2], 'arg2 must be the old status.' );
		$this->assertSame( 'processing', $args[3], 'arg3 must be the new status.' );
		$this->assertSame( 'sw_order_xyz', $args[4], 'arg4 must be the external_order_id from order meta.' );
	}

	public function test_tier1_does_not_emit_for_non_direct_checkout_orders(): void {
		// An order created outside the Direct Checkout flow (no _shopwalk_source).
		$order = new FakeWcOrderForTierSeparation( array(), 10.00, 'USD' );

		$this->fire_status_change( $order );

		$matching = array_filter(
			$this->actions_done,
			static fn( $entry ) => $entry['action'] === 'ucp_direct_checkout_order_status_changed'
		);
		$this->assertCount( 0, $matching, 'Tier 1 must not emit for non-Direct-Checkout orders.' );
	}

	// ── 2. Tier 1 makes zero outbound HTTP calls ───────────────────────────

	public function test_tier1_makes_no_outbound_http_when_no_listener(): void {
		// Direct Checkout-originated order, but NO Tier 2 listener registered.
		// This is what happens when the shopwalk/ directory is removed.
		$order = new FakeWcOrderForTierSeparation(
			array(
				'_shopwalk_source'   => 'direct_checkout',
				'_shopwalk_order_id' => 'sw_order_xyz',
			),
			49.99,
			'USD'
		);

		// Simulate a stored license key — Tier 1 must STILL not call out, because
		// Tier 1 owns no outbound dispatch at all.
		$this->options['shopwalk_license_key'] = 'sw_site_abc123';

		$this->fire_status_change( $order );

		$this->assertCount(
			0,
			$this->remote_posts,
			'Tier 1 must never call wp_remote_post on the order-status path; outbound dispatch lives entirely in Tier 2.'
		);
	}

	// ── 3. Tier 2 listener fires wp_remote_post when license is active ─────

	public function test_tier2_listener_dispatches_webhook_when_license_active(): void {
		// Tier 2 is loaded — register the listener.
		Shopwalk_Direct_Checkout_Notifier::instance();

		// License present + status active.
		$this->options['shopwalk_license_key']    = 'sw_site_abc123';
		$this->options['shopwalk_license_status'] = 'active';

		$order = new FakeWcOrderForTierSeparation(
			array(
				'_shopwalk_source'   => 'direct_checkout',
				'_shopwalk_order_id' => 'sw_order_xyz',
			),
			49.99,
			'USD'
		);

		$this->fire_status_change( $order, 42, 'pending', 'processing' );

		$this->assertCount( 1, $this->remote_posts, 'Tier 2 listener must dispatch exactly one webhook when license is active.' );
		$call = $this->remote_posts[0];

		// URL must target the Shopwalk webhook endpoint. Default base is
		// https://api.shopwalk.com (per the SHOPWALK_API_URL constant default
		// in the notifier — the test environment doesn't define the override).
		$this->assertStringContainsString( 'api.shopwalk.com', $call['url'] );
		$this->assertStringContainsString( '/api/v1/ucp/webhooks/orders', $call['url'] );

		// Body must carry the order/event payload.
		$this->assertArrayHasKey( 'body', $call['args'] );
		$decoded = json_decode( $call['args']['body'], true );
		$this->assertSame( 'order.status_changed', $decoded['event'] );
		$this->assertSame( 42, $decoded['order_id'] );
		$this->assertSame( 'sw_order_xyz', $decoded['shopwalk_order_id'] );
		$this->assertSame( 'pending', $decoded['from_status'] );
		$this->assertSame( 'processing', $decoded['to_status'] );
		$this->assertSame( 4999, $decoded['total'] );
		$this->assertSame( 'USD', $decoded['currency'] );

		// Headers must include the HMAC signature + content digest.
		$this->assertArrayHasKey( 'headers', $call['args'] );
		$this->assertArrayHasKey( 'X-License-Key', $call['args']['headers'] );
		$this->assertSame( 'sw_site_abc123', $call['args']['headers']['X-License-Key'] );
		$this->assertArrayHasKey( 'Content-Digest', $call['args']['headers'] );
		$this->assertArrayHasKey( 'Signature', $call['args']['headers'] );
		$this->assertArrayHasKey( 'Signature-Input', $call['args']['headers'] );
	}

	// ── 4. Tier 2 listener does NOT fire when license is not active ────────

	public function test_tier2_listener_skips_when_license_revoked(): void {
		Shopwalk_Direct_Checkout_Notifier::instance();

		// Key present but status revoked — the Wave 6 gate must short-circuit.
		$this->options['shopwalk_license_key']    = 'sw_site_abc123';
		$this->options['shopwalk_license_status'] = 'revoked';

		$order = new FakeWcOrderForTierSeparation(
			array(
				'_shopwalk_source'   => 'direct_checkout',
				'_shopwalk_order_id' => 'sw_order_xyz',
			),
			49.99,
			'USD'
		);

		$this->fire_status_change( $order );

		$this->assertCount( 0, $this->remote_posts, 'Revoked license must not push outbound (Wave 6 revocation safety).' );
	}

	public function test_tier2_listener_skips_when_license_expired(): void {
		Shopwalk_Direct_Checkout_Notifier::instance();

		$this->options['shopwalk_license_key']    = 'sw_site_abc123';
		$this->options['shopwalk_license_status'] = 'expired';

		$order = new FakeWcOrderForTierSeparation(
			array(
				'_shopwalk_source'   => 'direct_checkout',
				'_shopwalk_order_id' => 'sw_order_xyz',
			)
		);

		$this->fire_status_change( $order );

		$this->assertCount( 0, $this->remote_posts, 'Expired license must not push outbound.' );
	}

	public function test_tier2_listener_skips_when_no_license_key(): void {
		Shopwalk_Direct_Checkout_Notifier::instance();

		// No license key at all (also implies status() === 'unlicensed').
		$order = new FakeWcOrderForTierSeparation(
			array(
				'_shopwalk_source'   => 'direct_checkout',
				'_shopwalk_order_id' => 'sw_order_xyz',
			)
		);

		$this->fire_status_change( $order );

		$this->assertCount( 0, $this->remote_posts, 'Unlicensed state must not push outbound.' );
	}

	// ── 5. Structural: source-file scan ────────────────────────────────────

	public function test_tier1_source_file_contains_no_shopwalk_api_references(): void {
		$path = __DIR__ . '/../includes/core/class-ucp-direct-checkout.php';
		$src  = file_get_contents( $path );
		$this->assertNotFalse( $src, 'Tier 1 source file must be readable.' );

		// Outbound API: no references to the Shopwalk API host or constant.
		$this->assertStringNotContainsString( 'api.shopwalk.com', $src, 'Tier 1 must not reference api.shopwalk.com.' );
		$this->assertStringNotContainsString( 'SHOPWALK_API_URL', $src, 'Tier 1 must not reference SHOPWALK_API_URL.' );
		$this->assertStringNotContainsString( 'SHOPWALK_API_BASE', $src, 'Tier 1 must not reference SHOPWALK_API_BASE.' );

		// And no outbound HTTP from this file.
		$this->assertStringNotContainsString( 'wp_remote_post', $src, 'Tier 1 must not call wp_remote_post.' );
		$this->assertStringNotContainsString( 'wp_remote_get', $src, 'Tier 1 must not call wp_remote_get.' );
		$this->assertStringNotContainsString( 'wp_remote_request', $src, 'Tier 1 must not call wp_remote_request.' );
	}
}
