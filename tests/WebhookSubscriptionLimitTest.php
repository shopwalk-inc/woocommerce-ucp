<?php
/**
 * Tests for UCP_Webhook_Subscriptions per-client cap — F-D-4 (DoS).
 *
 * The 50th create per OAuth client must succeed; the 51st must be
 * rejected with a 429-class WP_Error so an agent cannot inflate the
 * per-event fan-out and degrade every WC order-status transition.
 *
 * Also covers the subscribe-time URL-guard short-circuit so the test
 * doesn't depend on a live DNS resolver.
 *
 * @package WooCommerceUCP
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );
defined( 'UCP_REST_NAMESPACE' ) || define( 'UCP_REST_NAMESPACE', 'shopwalk-ucp-agent/v1' );

require_once __DIR__ . '/stubs/wp_rest_stubs.php';
require_once __DIR__ . '/stubs/checkout_collaborator_stubs.php';
require_once __DIR__ . '/../includes/core/class-ucp-url-guard.php';
require_once __DIR__ . '/../includes/core/class-ucp-webhook-secret-crypto.php';
require_once __DIR__ . '/../includes/core/class-ucp-webhook-subscriptions.php';

final class WebhookSubscriptionLimitTest extends TestCase {

	private SubscriptionsCountWpdb $wpdb;

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset OAuth state (set by the checkout_collaborator_stubs fake).
		UCP_OAuth_Server::$next_auth_result = array(
			'client_id' => 'agt_capacity_test',
			'user_id'   => 0,
			'scopes'    => array( 'ucp:webhooks' ),
		);

		// In-memory $wpdb that supports COUNT(*) and insert/get for the
		// webhook_subscriptions table.
		global $wpdb;
		$this->wpdb = new SubscriptionsCountWpdb();
		$wpdb       = $this->wpdb;

		// Stub WP option store for the crypto helper's key persistence.
		$this->options = array();
		$opts          = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$opts ) {
				return array_key_exists( $name, $opts ) ? $opts[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload = null ) use ( &$opts ) {
				$opts[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-fixed-salt' );

		// WP function stubs used by the create handler.
		Functions\when( 'current_time' )->alias(
			static function ( $fmt, $gmt = 0 ) {
				return gmdate( 'Y-m-d H:i:s' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);

		// Bypass URL guard (it does live DNS by default — we don't want
		// that in unit tests). Inject deterministic resolvers that put
		// example.com on a public IP.
		UCP_Url_Guard::set_resolvers(
			static function ( string $host ) {
				return array( '8.8.8.8' );
			},
			static function ( string $host ) {
				return false;
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
			}
		);
	}

	protected function tearDown(): void {
		UCP_Url_Guard::set_resolvers( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_request(): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_json_params(
			array(
				'callback_url' => 'https://hooks.example.com/agent',
				'event_types'  => array( 'order.created' ),
			)
		);
		return $req;
	}

	public function test_50th_create_succeeds_and_51st_is_rejected_with_429(): void {
		// Create 50 successful subscriptions.
		for ( $i = 1; $i <= 50; $i++ ) {
			$resp = UCP_Webhook_Subscriptions::create_subscription( $this->make_request() );
			$this->assertInstanceOf( WP_REST_Response::class, $resp, "create #{$i} should succeed" );
			$this->assertSame( 201, $resp->get_status() );
		}
		$this->assertCount( 50, $this->wpdb->rows, 'expected 50 subscription rows' );

		// 51st must fail.
		$resp = UCP_Webhook_Subscriptions::create_subscription( $this->make_request() );
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'subscription_limit_exceeded', $resp->get_error_code() );
		$this->assertSame( 429, $resp->data['status'] ?? null );

		// And no 51st row was inserted.
		$this->assertCount( 50, $this->wpdb->rows );
	}

	/**
	 * F-D-4 SQL shape check. The fix replaces `SELECT * FROM <t>` (full
	 * scan + PHP-side filter) with a prepared `WHERE JSON_CONTAINS(...)`
	 * pushdown. We can't drive a real MySQL in a unit test, so verify the
	 * built query string carries JSON_CONTAINS over the event_types
	 * column with the JSON-quoted needle as the right-hand side.
	 */
	public function test_find_by_event_uses_json_contains_pushdown(): void {
		UCP_Webhook_Subscriptions::find_by_event( 'order.created' );

		$this->assertStringContainsString(
			'JSON_CONTAINS(event_types',
			$this->wpdb->seen_get_results_query,
			'find_by_event must push the event filter down to MySQL via JSON_CONTAINS, not pull every row'
		);
		// And the needle is JSON-quoted so the right-hand side is a JSON
		// scalar string, which is what JSON_CONTAINS expects.
		$this->assertSame( '"order.created"', $this->wpdb->seen_get_results_args[0] ?? null );
	}

	public function test_other_clients_are_not_affected_by_first_clients_cap(): void {
		// Fill client A.
		for ( $i = 1; $i <= 50; $i++ ) {
			$resp = UCP_Webhook_Subscriptions::create_subscription( $this->make_request() );
			$this->assertInstanceOf( WP_REST_Response::class, $resp );
		}

		// Switch to client B.
		UCP_OAuth_Server::$next_auth_result = array(
			'client_id' => 'agt_other_client',
			'user_id'   => 0,
			'scopes'    => array( 'ucp:webhooks' ),
		);

		$resp = UCP_Webhook_Subscriptions::create_subscription( $this->make_request() );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 201, $resp->get_status() );
	}
}

/**
 * In-memory $wpdb that supports the subset of queries the create handler
 * issues against the webhook_subscriptions table:
 *  - SELECT COUNT(*) FROM <table> WHERE client_id = %s   (cap check)
 *  - INSERT INTO <table>                                  (the create)
 */
final class SubscriptionsCountWpdb { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	public string $prefix     = 'wp_';
	public array $rows        = array();
	public string $seen_get_results_query = '';
	public array $seen_get_results_args   = array();
	private array $last_args  = array();
	private string $last_query = '';

	public function prepare( string $query, ...$args ): string {
		$this->last_query = $query;
		$this->last_args  = $args;
		return $query;
	}

	public function get_var( string $query ) {
		$this->last_query = $query;
		if ( preg_match( '/SELECT\s+COUNT\(\*\)\s+FROM\s+\S+\s+WHERE\s+client_id\s*=\s*%s/i', $query ) ) {
			$client_id = (string) ( $this->last_args[0] ?? '' );
			$count     = 0;
			foreach ( $this->rows as $row ) {
				if ( ( $row['client_id'] ?? '' ) === $client_id ) {
					++$count;
				}
			}
			return (string) $count;
		}
		return null;
	}

	public function get_row( string $query, $output = ARRAY_A ) {
		return null;
	}

	public function get_results( string $query, $output = ARRAY_A ): array {
		$this->seen_get_results_query = $query;
		$this->seen_get_results_args  = $this->last_args;
		return array();
	}

	public function insert( string $table, array $data ): int {
		$this->rows[] = $data;
		return 1;
	}

	public function update( string $table, array $data, array $where ): int {
		return 0;
	}

	public function delete( string $table, array $where ): int {
		return 0;
	}

	public function query( string $query ): int {
		return 0;
	}
}
