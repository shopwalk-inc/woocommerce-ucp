<?php
/**
 * Tests for UCP_OAuth_Server::exchange_refresh_token — F-C-1.
 *
 * Implements OAuth 2.1 / draft-ietf-oauth-security-topics §4.12 — refresh
 * token rotation with reuse-detection family revocation:
 *   - Successful refresh: the old refresh row is revoked, a NEW
 *     access+refresh pair is minted and returned, scoped to the same
 *     (client_id, user_id) so the family stays linked.
 *   - Replay of an already-rotated refresh token: detect via the row's
 *     revoked_at column and revoke EVERY token (access + refresh) for
 *     that (client_id, user_id) pair. Returns 401 refresh_token_revoked.
 *   - Unknown refresh token: 401 invalid_grant.
 *   - Refresh from a different client_id than the row's: 401 invalid_grant
 *     (regression — refresh tokens are bound to the issuing client).
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
defined( 'UCP_TABLE_PREFIX' ) || define( 'UCP_TABLE_PREFIX', 'ucp_' );

require_once __DIR__ . '/stubs/wp_rest_stubs.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OauthRefreshRotationTest extends TestCase {

	private const CLIENT_ID     = 'agt_test_client';
	private const CLIENT_SECRET = 'plaintext-secret';
	private const USER_ID       = 42;

	private RefreshRotationWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// In-memory $wpdb that handles the token-lookup + UPDATE shapes the
		// real OAuth server emits for the refresh-rotation flow.
		global $wpdb;
		$this->wpdb = new RefreshRotationWpdb();
		$wpdb       = $this->wpdb;

		// WP function stubs used by the real OAuth server.
		Functions\when( 'current_time' )->alias(
			static function ( $fmt, $gmt = 0 ) {
				return gmdate( 'Y-m-d H:i:s' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Provide UCP_Storage if it isn't already (avoid double-declare).
		if ( ! class_exists( 'UCP_Storage' ) ) {
			eval( 'class UCP_Storage { public static function table( string $short ): string { return "wp_ucp_" . $short; } }' );
		}

		// Provide UCP_OAuth_Clients stub — only verify_secret is exercised
		// by the token endpoint; the real implementation does a DB lookup
		// + bcrypt compare and isn't relevant to refresh-rotation logic.
		if ( ! class_exists( 'UCP_OAuth_Clients' ) ) {
			eval(
				'class UCP_OAuth_Clients {
					public static string $expected_client_id = "";
					public static string $expected_secret = "";
					public static function verify_secret( string $client_id, string $client_secret ): bool {
						return hash_equals( self::$expected_client_id, $client_id )
							&& hash_equals( self::$expected_secret, $client_secret );
					}
				}'
			);
		}
		\UCP_OAuth_Clients::$expected_client_id = self::CLIENT_ID;
		\UCP_OAuth_Clients::$expected_secret    = self::CLIENT_SECRET;

		// Load the real OAuth server. The separate-process annotation
		// guarantees this class is loaded fresh in this process and won't
		// collide with the fake declared by other tests.
		require_once __DIR__ . '/../includes/core/class-ucp-oauth-server.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	private function token_request( string $refresh_token, string $client_id = self::CLIENT_ID, string $client_secret = self::CLIENT_SECRET ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_param( 'grant_type', 'refresh_token' );
		$req->set_param( 'client_id', $client_id );
		$req->set_param( 'client_secret', $client_secret );
		$req->set_param( 'refresh_token', $refresh_token );
		return $req;
	}

	/**
	 * Mint a fresh refresh token via the real issuer so the bcrypt hash
	 * matches the in-memory row exactly.
	 */
	private function seed_refresh_token( string $client_id = self::CLIENT_ID, int $user_id = self::USER_ID, array $scopes = array( 'ucp:checkout', 'ucp:orders' ) ): string {
		$issued = UCP_OAuth_Server::issue_token( 'refresh', $client_id, $user_id, $scopes, 2592000 );
		return $issued['plaintext'];
	}

	private function table(): string {
		return UCP_Storage::table( 'oauth_tokens' );
	}

	private function rows(): array {
		return $this->wpdb->tables[ $this->table() ] ?? array();
	}

	/**
	 * Count active (non-revoked) rows of a given type for a (client, user) pair.
	 */
	private function count_active( string $type, string $client_id, int $user_id ): int {
		$n = 0;
		foreach ( $this->rows() as $row ) {
			if ( ( $row['token_type'] ?? '' ) !== $type ) {
				continue;
			}
			if ( ( $row['client_id'] ?? '' ) !== $client_id ) {
				continue;
			}
			if ( (int) ( $row['user_id'] ?? 0 ) !== $user_id ) {
				continue;
			}
			if ( null !== ( $row['revoked_at'] ?? null ) ) {
				continue;
			}
			++$n;
		}
		return $n;
	}

	// ── Cases ────────────────────────────────────────────────────────────

	public function test_happy_path_rotation_revokes_old_refresh_and_returns_new_pair(): void {
		$old_refresh = $this->seed_refresh_token();

		$resp = UCP_OAuth_Server::handle_token( $this->token_request( $old_refresh ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );

		$body = $resp->get_data();
		$this->assertArrayHasKey( 'access_token', $body );
		$this->assertArrayHasKey( 'refresh_token', $body );
		$this->assertNotSame( $old_refresh, $body['refresh_token'], 'rotation MUST mint a new refresh token' );
		$this->assertSame( 'Bearer', $body['token_type'] );
		$this->assertSame( 3600, $body['expires_in'] );

		// Old refresh row is now revoked.
		$old_row = null;
		foreach ( $this->rows() as $row ) {
			if ( password_verify( $old_refresh, (string) $row['token_hash'] ) ) {
				$old_row = $row;
				break;
			}
		}
		$this->assertNotNull( $old_row, 'old refresh row must still exist' );
		$this->assertNotNull( $old_row['revoked_at'], 'old refresh row must be revoked after rotation' );

		// New refresh row is active and bound to the same (client, user).
		$this->assertSame( 1, $this->count_active( 'refresh', self::CLIENT_ID, self::USER_ID ) );

		// New access token works (lookup_token returns the row).
		$access_row = UCP_OAuth_Server::lookup_token( $body['access_token'], 'access' );
		$this->assertNotNull( $access_row );
		$this->assertSame( self::CLIENT_ID, (string) $access_row['client_id'] );
		$this->assertSame( self::USER_ID, (int) $access_row['user_id'] );

		// New refresh token also works as a refresh token.
		$new_refresh_row = UCP_OAuth_Server::lookup_token( $body['refresh_token'], 'refresh' );
		$this->assertNotNull( $new_refresh_row );
	}

	public function test_old_refresh_after_rotation_is_rejected_as_revoked(): void {
		$old_refresh = $this->seed_refresh_token();

		// First exchange — succeeds, old refresh is now revoked.
		$first = UCP_OAuth_Server::handle_token( $this->token_request( $old_refresh ) );
		$this->assertInstanceOf( WP_REST_Response::class, $first );

		// Replay the OLD refresh token.
		$replay = UCP_OAuth_Server::handle_token( $this->token_request( $old_refresh ) );

		$this->assertInstanceOf( WP_Error::class, $replay );
		$this->assertSame( 'refresh_token_revoked', $replay->get_error_code() );
		$this->assertSame( 401, $replay->data['status'] ?? null );
	}

	public function test_reuse_detection_revokes_entire_token_family(): void {
		$old_refresh = $this->seed_refresh_token();

		// First exchange — issues NEW access + refresh.
		$first = UCP_OAuth_Server::handle_token( $this->token_request( $old_refresh ) );
		$this->assertInstanceOf( WP_REST_Response::class, $first );
		$first_body         = $first->get_data();
		$new_access_token   = $first_body['access_token'];
		$new_refresh_token  = $first_body['refresh_token'];

		// Sanity: between the rotation and the replay, the new pair is live.
		$this->assertSame( 1, $this->count_active( 'access', self::CLIENT_ID, self::USER_ID ) );
		$this->assertSame( 1, $this->count_active( 'refresh', self::CLIENT_ID, self::USER_ID ) );

		// Replay the OLD refresh — triggers family revocation.
		$replay = UCP_OAuth_Server::handle_token( $this->token_request( $old_refresh ) );
		$this->assertInstanceOf( WP_Error::class, $replay );
		$this->assertSame( 'refresh_token_revoked', $replay->get_error_code() );

		// EVERY token for (client, user) is now revoked — including the
		// freshly-issued pair from the first exchange.
		$this->assertSame( 0, $this->count_active( 'access', self::CLIENT_ID, self::USER_ID ) );
		$this->assertSame( 0, $this->count_active( 'refresh', self::CLIENT_ID, self::USER_ID ) );

		// Concretely: the new access token no longer authenticates.
		$this->assertNull( UCP_OAuth_Server::lookup_token( $new_access_token, 'access' ) );
		$this->assertNull( UCP_OAuth_Server::lookup_token( $new_refresh_token, 'refresh' ) );
	}

	public function test_unknown_refresh_token_returns_invalid_grant(): void {
		$resp = UCP_OAuth_Server::handle_token( $this->token_request( 'rt_neverissued' ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'invalid_grant', $resp->get_error_code() );
		$this->assertSame( 401, $resp->data['status'] ?? null );
	}

	public function test_refresh_token_from_different_client_id_is_rejected(): void {
		// Token issued to a different client.
		$refresh = $this->seed_refresh_token( 'agt_other_client' );

		// Caller authenticates as agt_test_client. Both clients pretend to
		// share the same secret (test stub), so the only thing standing
		// between the caller and a valid response is the client_id check
		// inside exchange_refresh_token.
		$req = $this->token_request( $refresh, self::CLIENT_ID, self::CLIENT_SECRET );

		$resp = UCP_OAuth_Server::handle_token( $req );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'invalid_grant', $resp->get_error_code() );
		$this->assertSame( 401, $resp->data['status'] ?? null );

		// The token must NOT be revoked as a side-effect of a wrong-client
		// probe — that would let an attacker DOS legitimate sessions just
		// by guessing client_ids.
		$this->assertSame( 1, $this->count_active( 'refresh', 'agt_other_client', self::USER_ID ) );
	}

	public function test_family_revocation_is_scoped_to_client_user_pair(): void {
		// Two clients, same user — one client's reuse must NOT revoke the
		// other client's tokens.
		$other_refresh = $this->seed_refresh_token( 'agt_other_client', self::USER_ID );
		$our_refresh   = $this->seed_refresh_token( self::CLIENT_ID, self::USER_ID );

		// Rotate ours, then replay → family revocation for OUR (client, user).
		$first = UCP_OAuth_Server::handle_token( $this->token_request( $our_refresh ) );
		$this->assertInstanceOf( WP_REST_Response::class, $first );
		$replay = UCP_OAuth_Server::handle_token( $this->token_request( $our_refresh ) );
		$this->assertInstanceOf( WP_Error::class, $replay );

		// Our family is wiped...
		$this->assertSame( 0, $this->count_active( 'refresh', self::CLIENT_ID, self::USER_ID ) );
		// ...but the other client's tokens are untouched.
		$this->assertSame( 1, $this->count_active( 'refresh', 'agt_other_client', self::USER_ID ) );
		$this->assertNotNull( UCP_OAuth_Server::lookup_token( $other_refresh, 'refresh' ) );
	}
}

/**
 * In-memory $wpdb that handles every query shape the OAuth server emits
 * during refresh-token rotation:
 *
 *   - SELECT * FROM <table> WHERE token_type = %s AND revoked_at IS NULL AND expires_at > %s
 *   - SELECT * FROM <table> WHERE token_type = %s AND revoked_at IS NOT NULL
 *   - INSERT into oauth_tokens
 *   - UPDATE oauth_tokens SET revoked_at = ... WHERE id = ...
 *   - $wpdb->query( prepare( "UPDATE ... SET revoked_at = %s WHERE client_id = %s AND user_id = %d AND revoked_at IS NULL" ) )
 *
 * Rows are stored as a plain list (auto-increment id assigned on insert)
 * so that update-by-id and the family UPDATE both have something to mutate.
 */
final class RefreshRotationWpdb { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	/** @var array<string, array<int, array<string,mixed>>> */
	public array $tables = array();
	public string $prefix = 'wp_';

	/** @var array<int,mixed> */
	private array $last_args = array();
	private string $last_query = '';
	private int $next_id = 0;

	public function prepare( string $query, ...$args ): string {
		$this->last_query = $query;
		$this->last_args  = $args;
		return $query;
	}

	public function get_results( string $query, $output = ARRAY_A ): array {
		// "Live" lookup — non-revoked, non-expired rows of a given type.
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+token_type\s*=\s*%s\s+AND\s+revoked_at\s+IS\s+NULL\s+AND\s+expires_at/i', $query, $m ) ) {
			$table = $m[1];
			$type  = (string) ( $this->last_args[0] ?? '' );
			$now   = (string) ( $this->last_args[1] ?? gmdate( 'Y-m-d H:i:s' ) );
			$out   = array();
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
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

		// "Revoked" lookup — only revoked rows of a given type, ignoring expiry.
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+token_type\s*=\s*%s\s+AND\s+revoked_at\s+IS\s+NOT\s+NULL/i', $query, $m ) ) {
			$table = $m[1];
			$type  = (string) ( $this->last_args[0] ?? '' );
			$out   = array();
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				if ( ( $row['token_type'] ?? '' ) !== $type ) {
					continue;
				}
				if ( null === ( $row['revoked_at'] ?? null ) ) {
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
		++$this->next_id;
		$data['id']               = $this->next_id;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function update( string $table, array $data, array $where ): int {
		$id = (int) ( $where['id'] ?? 0 );
		if ( 0 === $id || ! isset( $this->tables[ $table ] ) ) {
			return 0;
		}
		foreach ( $this->tables[ $table ] as $idx => $row ) {
			if ( (int) ( $row['id'] ?? 0 ) !== $id ) {
				continue;
			}
			$this->tables[ $table ][ $idx ] = array_merge( $row, $data );
			return 1;
		}
		return 0;
	}

	/**
	 * Handles the family-revocation UPDATE emitted via $wpdb->query( prepare( ... ) ):
	 *   UPDATE <table> SET revoked_at = %s WHERE client_id = %s AND user_id = %d AND revoked_at IS NULL
	 */
	public function query( string $query ): int {
		if ( preg_match( '/UPDATE\s+(\S+)\s+SET\s+revoked_at\s*=\s*%s\s+WHERE\s+client_id\s*=\s*%s\s+AND\s+user_id\s*=\s*%d\s+AND\s+revoked_at\s+IS\s+NULL/i', $query, $m ) ) {
			$table     = $m[1];
			$now       = (string) ( $this->last_args[0] ?? gmdate( 'Y-m-d H:i:s' ) );
			$client_id = (string) ( $this->last_args[1] ?? '' );
			$user_id   = (int) ( $this->last_args[2] ?? 0 );
			$count     = 0;
			foreach ( $this->tables[ $table ] ?? array() as $idx => $row ) {
				if ( ( $row['client_id'] ?? '' ) !== $client_id ) {
					continue;
				}
				if ( (int) ( $row['user_id'] ?? 0 ) !== $user_id ) {
					continue;
				}
				if ( null !== ( $row['revoked_at'] ?? null ) ) {
					continue;
				}
				$this->tables[ $table ][ $idx ]['revoked_at'] = $now;
				++$count;
			}
			return $count;
		}
		return 0;
	}
}

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
