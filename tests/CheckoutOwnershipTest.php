<?php
/**
 * Tests for UCP_Checkout — F-B-2 (per-handler ownership enforcement on
 * write routes). Anyone who knows or guesses a session id must NOT be
 * able to update / complete / cancel another agent's session.
 *
 * Anonymous semantics: sessions whose stored client_id is `agt_anonymous`
 * remain reachable by any anonymous caller (preserves create-flow). Once
 * a session is bound to a real client_id, anonymous callers can't touch it.
 *
 * @package WooCommerceUCP
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );
defined( 'UCP_REST_NAMESPACE' ) || define( 'UCP_REST_NAMESPACE', 'shopwalk-ucp-agent/v1' );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/stubs/wp_rest_stubs.php';
require_once __DIR__ . '/stubs/fake_wpdb.php';
require_once __DIR__ . '/stubs/checkout_collaborator_stubs.php';
require_once __DIR__ . '/../includes/core/class-ucp-checkout.php';

final class CheckoutOwnershipTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset OAuth state between tests.
		UCP_OAuth_Server::$next_auth_result = null;

		// In-memory $wpdb.
		global $wpdb;
		$this->wpdb = new FakeWpdb();
		$wpdb       = $this->wpdb;

		// WP function stubs used by handlers.
		Functions\when( 'current_time' )->alias(
			static function ( $fmt, $gmt = 0 ) {
				return gmdate( 'Y-m-d H:i:s' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $s ) {
				return is_scalar( $s ) ? trim( strip_tags( (string) $s ) ) : '';
			}
		);
		Functions\when( 'sanitize_email' )->alias(
			static function ( $s ) {
				return is_scalar( $s ) ? (string) $s : '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function make_request( array $body = array(), array $params = array() ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_json_params( $body );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	/**
	 * Drive create_session under a given (or no) auth context. Returns the
	 * created session id.
	 */
	private function create_session_with_auth( ?array $auth ): string {
		UCP_OAuth_Server::$next_auth_result = $auth;
		$req                                = $this->make_request(
			array(
				'line_items' => array(
					array( 'product_id' => 1, 'quantity' => 1 ),
				),
			)
		);
		$resp = UCP_Checkout::create_session( $req );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 201, $resp->get_status() );
		// Fish the row out by listing the table.
		$table = UCP_Storage::table( 'checkout_sessions' );
		$ids   = array_keys( $this->wpdb->tables[ $table ] );
		$this->assertCount( 1, $ids, 'expected exactly one session' );
		// Force the created session into a state that allows update/complete/cancel.
		$this->wpdb->tables[ $table ][ $ids[0] ]['status'] = 'ready_for_complete';
		return $ids[0];
	}

	private function call_update( string $id, ?array $auth ): \WP_REST_Response|WP_Error {
		UCP_OAuth_Server::$next_auth_result = $auth;
		return UCP_Checkout::update_session( $this->make_request( array( 'buyer' => array( 'email' => 'a@b.com' ) ), array( 'id' => $id ) ) );
	}

	private function call_complete( string $id, ?array $auth ): \WP_REST_Response|WP_Error {
		UCP_OAuth_Server::$next_auth_result = $auth;
		// build_wc_order_from_session falls back to error 503 (wc_unavailable) on
		// non-WC envs — but ownership check runs FIRST so a 403 should still win.
		return UCP_Checkout::complete_session( $this->make_request( array(), array( 'id' => $id ) ) );
	}

	private function call_cancel( string $id, ?array $auth ): \WP_REST_Response|WP_Error {
		UCP_OAuth_Server::$next_auth_result = $auth;
		return UCP_Checkout::cancel_session( $this->make_request( array(), array( 'id' => $id ) ) );
	}

	private function assert_403( $resp ): void {
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'forbidden_session_access', $resp->get_error_code() );
		$this->assertSame( 403, $resp->data['status'] ?? null );
	}

	private function assert_200( $resp ): void {
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertContains( $resp->get_status(), array( 200, 201 ) );
	}

	// ── Anonymous create + anonymous update ──────────────────────────────

	public function test_anonymous_create_then_anonymous_update_succeeds(): void {
		$id = $this->create_session_with_auth( null );
		$this->assert_200( $this->call_update( $id, null ) );
	}

	public function test_anonymous_create_then_authed_update_succeeds(): void {
		// Anonymous sessions are reachable by any caller — that's the
		// existing semantic. An authenticated caller may take ownership
		// of an anonymous session too.
		$id = $this->create_session_with_auth( null );
		$this->assert_200( $this->call_update( $id, array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() ) ) );
	}

	// ── Authenticated create — ownership enforcement ─────────────────────

	public function test_authed_create_then_anonymous_update_returns_403(): void {
		$id = $this->create_session_with_auth(
			array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() )
		);
		$this->assert_403( $this->call_update( $id, null ) );
	}

	public function test_authed_create_then_other_client_update_returns_403(): void {
		$id = $this->create_session_with_auth(
			array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() )
		);
		$this->assert_403(
			$this->call_update( $id, array( 'client_id' => 'agt_y', 'user_id' => 0, 'scopes' => array() ) )
		);
	}

	public function test_authed_create_then_same_client_update_succeeds(): void {
		$id = $this->create_session_with_auth(
			array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() )
		);
		$this->assert_200(
			$this->call_update( $id, array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() ) )
		);
	}

	// ── Complete: same matrix ────────────────────────────────────────────

	public function test_complete_authed_create_then_anonymous_returns_403(): void {
		$id = $this->create_session_with_auth(
			array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() )
		);
		$this->assert_403( $this->call_complete( $id, null ) );
	}

	public function test_complete_authed_create_then_other_client_returns_403(): void {
		$id = $this->create_session_with_auth(
			array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() )
		);
		$this->assert_403(
			$this->call_complete( $id, array( 'client_id' => 'agt_y', 'user_id' => 0, 'scopes' => array() ) )
		);
	}

	public function test_complete_anonymous_create_then_anonymous_does_not_403(): void {
		// Should not be a 403. (May still 503 because WC is not available
		// in this unit-test env — but the ownership check passed.)
		$id  = $this->create_session_with_auth( null );
		$res = $this->call_complete( $id, null );
		if ( $res instanceof WP_Error ) {
			$this->assertNotSame( 'forbidden_session_access', $res->get_error_code() );
		} else {
			$this->assertInstanceOf( WP_REST_Response::class, $res );
		}
	}

	// ── Cancel: same matrix ──────────────────────────────────────────────

	public function test_cancel_authed_create_then_anonymous_returns_403(): void {
		$id = $this->create_session_with_auth(
			array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() )
		);
		$this->assert_403( $this->call_cancel( $id, null ) );
	}

	public function test_cancel_authed_create_then_other_client_returns_403(): void {
		$id = $this->create_session_with_auth(
			array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() )
		);
		$this->assert_403(
			$this->call_cancel( $id, array( 'client_id' => 'agt_y', 'user_id' => 0, 'scopes' => array() ) )
		);
	}

	public function test_cancel_authed_create_then_same_client_succeeds(): void {
		$id = $this->create_session_with_auth(
			array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() )
		);
		$this->assert_200(
			$this->call_cancel( $id, array( 'client_id' => 'agt_x', 'user_id' => 0, 'scopes' => array() ) )
		);
	}

	public function test_cancel_anonymous_create_then_anonymous_succeeds(): void {
		$id = $this->create_session_with_auth( null );
		$this->assert_200( $this->call_cancel( $id, null ) );
	}
}
