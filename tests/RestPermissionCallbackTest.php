<?php
/**
 * Tests for UCP_OAuth_Server::permission_require_oauth — F-B-3 + F-D-3.
 *
 * Routes that require OAuth Bearer auth (orders, webhook subscriptions)
 * MUST register this helper as their permission_callback rather than
 * `__return_true`. Otherwise the WP REST permission middleware
 * (rest_authentication_errors filter, capability filters) sees the route
 * as public and one forgotten auth check inside a future handler edit
 * leaves the route silently unauthenticated.
 *
 * Runs in a separate process to avoid clashing with the fake
 * UCP_OAuth_Server class declared by CheckoutOwnershipTest's stubs.
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
require_once __DIR__ . '/stubs/oauth_wp_stubs.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RestPermissionCallbackTest extends TestCase {

	private TokenLookupWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// In-memory $wpdb that handles the token-lookup query shape.
		global $wpdb;
		$this->wpdb = new TokenLookupWpdb();
		$wpdb       = $this->wpdb;

		// WP function stubs used by the real OAuth server.
		Functions\when( 'current_time' )->alias(
			static function ( $fmt, $gmt = 0 ) {
				return gmdate( 'Y-m-d H:i:s' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		ucp_oauth_install_wp_stubs();

		// Provide UCP_Storage if it isn't already (avoid double-declare).
		if ( ! class_exists( 'UCP_Storage' ) ) {
			eval( 'class UCP_Storage { public static function table( string $short ): string { return "wp_ucp_" . $short; } }' );
		}

		// Load the real OAuth server. The separate-process annotation
		// guarantees this class is loaded fresh in this process and won't
		// collide with the fake declared by other tests.
		require_once __DIR__ . '/../includes/core/class-ucp-oauth-server.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_request( string $auth_header = '' ): WP_REST_Request {
		$req = new WP_REST_Request();
		if ( '' !== $auth_header ) {
			$req->set_header( 'authorization', $auth_header );
		}
		return $req;
	}

	/**
	 * Insert a non-expired, non-revoked access token row whose bcrypt hash
	 * matches the supplied plaintext.
	 */
	private function seed_access_token( string $plaintext, string $client_id = 'agt_test', int $user_id = 7 ): void {
		$table                       = UCP_Storage::table( 'oauth_tokens' );
		$this->wpdb->tables[ $table ] = array();
		$this->wpdb->tables[ $table ][] = array(
			'id'         => 1,
			'token_type' => 'access',
			'token_hash' => password_hash( $plaintext, PASSWORD_BCRYPT ),
			'client_id'  => $client_id,
			'user_id'    => $user_id,
			'scopes'     => json_encode( array( 'ucp:orders' ) ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			'revoked_at' => null,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	// ── Cases ────────────────────────────────────────────────────────────

	public function test_no_bearer_header_returns_wp_error_401(): void {
		$result = UCP_OAuth_Server::permission_require_oauth( $this->make_request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unauthorized', $result->get_error_code() );
		$this->assertSame( 401, $result->data['status'] ?? null );
	}

	public function test_malformed_authorization_header_returns_wp_error_401(): void {
		$result = UCP_OAuth_Server::permission_require_oauth(
			$this->make_request( 'Basic dXNlcjpwYXNz' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unauthorized', $result->get_error_code() );
		$this->assertSame( 401, $result->data['status'] ?? null );
	}

	public function test_unknown_bearer_token_returns_wp_error_401(): void {
		$this->seed_access_token( 'at_realtoken' );

		$result = UCP_OAuth_Server::permission_require_oauth(
			$this->make_request( 'Bearer at_doesnotexist' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
		$this->assertSame( 401, $result->data['status'] ?? null );
	}

	public function test_valid_bearer_token_returns_true(): void {
		$this->seed_access_token( 'at_validtoken' );

		$result = UCP_OAuth_Server::permission_require_oauth(
			$this->make_request( 'Bearer at_validtoken' )
		);

		$this->assertTrue( $result );
	}
}

/**
 * In-memory $wpdb that handles the token-lookup query shape:
 *   SELECT * FROM <table> WHERE token_type = %s AND revoked_at IS NULL AND expires_at > %s
 *
 * Returns ALL non-expired, non-revoked rows for the requested type so the
 * real lookup_token() can bcrypt-verify each candidate hash.
 */
final class TokenLookupWpdb { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	/** @var array<string, array<int, array<string,mixed>>> */
	public array $tables = array();
	public string $prefix = 'wp_';

	private array $last_args = array();
	private string $last_query = '';

	public function prepare( string $query, ...$args ): string {
		$this->last_query = $query;
		$this->last_args  = $args;
		return $query;
	}

	public function get_results( string $query, $output = ARRAY_A ): array {
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+token_type\s*=\s*%s/i', $query, $m ) ) {
			$table = $m[1];
			$type  = (string) ( $this->last_args[0] ?? '' );
			$now   = (string) ( $this->last_args[1] ?? gmdate( 'Y-m-d H:i:s' ) );
			$rows  = $this->tables[ $table ] ?? array();
			$out   = array();
			foreach ( $rows as $row ) {
				if ( ( $row['token_type'] ?? '' ) !== $type ) {
					continue;
				}
				if ( null !== ( $row['revoked_at'] ?? null ) ) {
					continue;
				}
				if ( ( $row['expires_at'] ?? '' ) <= $now ) {
					continue;
				}
				$out[] = $row;
			}
			return $out;
		}
		return array();
	}

	public function get_row( string $query, $output = ARRAY_A ) {
		return null;
	}

	public function insert( string $table, array $data ): int {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array();
		}
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function update( string $table, array $data, array $where ): int {
		return 0;
	}

	public function query( string $query ): int {
		return 0;
	}
}

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
