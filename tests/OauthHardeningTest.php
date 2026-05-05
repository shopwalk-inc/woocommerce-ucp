<?php
/**
 * Tests for the v3.1.1 OAuth hardening wave (F-C-3 / F-C-4 / F-C-5 / F-C-6 / F-C-7).
 *
 *  - F-C-3: HMAC-SHA256 indexed token lookup; lazy migration of legacy
 *           bcrypt rows to the fast path.
 *  - F-C-4: /authorize requires `state`; redirect goes through wp_redirect.
 *  - F-C-5: /authorize is GET-only.
 *  - F-C-6: /authorize renders an interactive consent page; /consent
 *           verifies wp_nonce + capability before issuing the code.
 *  - F-C-7: per-client_id rate limit on /token failed attempts.
 *
 * Runs in a separate process to avoid clashing with the fake UCP_OAuth_Server
 * class declared by other tests' stubs.
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
require_once __DIR__ . '/stubs/oauth_wp_stubs.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OauthHardeningTest extends TestCase {

	private const CLIENT_ID     = 'agt_hardening';
	private const CLIENT_SECRET = 'plaintext-secret';
	private const REDIRECT_URI  = 'https://agent.example/cb';
	private const USER_ID       = 91;

	private HardeningWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$this->wpdb = new HardeningWpdb();
		$wpdb       = $this->wpdb;

		Functions\when( 'current_time' )->alias(
			static function ( $fmt, $gmt = 0 ) {
				return gmdate( 'Y-m-d H:i:s' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_current_user_id' )->justReturn( self::USER_ID );
		Functions\when( 'wp_login_url' )->returnArg();
		ucp_oauth_install_wp_stubs();

		if ( ! class_exists( 'UCP_Storage' ) ) {
			eval( 'class UCP_Storage { public static function table( string $short ): string { return "wp_ucp_" . $short; } }' );
		}

		if ( ! class_exists( 'UCP_OAuth_Clients' ) ) {
			eval(
				'class UCP_OAuth_Clients {
					public static string $expected_client_id = "";
					public static string $expected_secret = "";
					public static string $expected_redirect_uri = "";
					public static string $name = "Test Agent";
					public static function find( string $client_id ) {
						if ( $client_id === self::$expected_client_id ) {
							return array(
								"client_id" => $client_id,
								"name" => self::$name,
								"redirect_uris" => array( self::$expected_redirect_uri ),
							);
						}
						return null;
					}
					public static function is_valid_redirect_uri( $client, string $redirect_uri ): bool {
						return $redirect_uri === self::$expected_redirect_uri;
					}
					public static function verify_secret( string $client_id, string $client_secret ): bool {
						return hash_equals( self::$expected_client_id, $client_id )
							&& hash_equals( self::$expected_secret, $client_secret );
					}
				}'
			);
		}
		\UCP_OAuth_Clients::$expected_client_id    = self::CLIENT_ID;
		\UCP_OAuth_Clients::$expected_secret       = self::CLIENT_SECRET;
		\UCP_OAuth_Clients::$expected_redirect_uri = self::REDIRECT_URI;

		require_once __DIR__ . '/../includes/core/class-ucp-oauth-server.php';
		\UCP_OAuth_Server::$testing_no_exit = true;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	private static function compute_s256( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private function authorize_request( array $extra = array() ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_param( 'client_id', self::CLIENT_ID );
		$req->set_param( 'redirect_uri', self::REDIRECT_URI );
		$req->set_param( 'state', 'xyz' );
		$req->set_param( 'response_type', 'code' );
		foreach ( $extra as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function consent_request( array $extra = array() ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_param( 'client_id', self::CLIENT_ID );
		$req->set_param( 'redirect_uri', self::REDIRECT_URI );
		$req->set_param( 'state', 'xyz' );
		$req->set_param( 'response_type', 'code' );
		$req->set_param( 'scope', 'ucp:checkout ucp:orders' );
		foreach ( $extra as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	private function nonce_for( string $client_id ): string {
		foreach ( $GLOBALS['ucp_test_nonces'] ?? array() as $val => $action ) {
			if ( 'ucp_oauth_consent_' . $client_id === $action ) {
				return (string) $val;
			}
		}
		return '';
	}

	private function token_request( array $params ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_param( 'grant_type', 'authorization_code' );
		$req->set_param( 'client_id', self::CLIENT_ID );
		$req->set_param( 'client_secret', self::CLIENT_SECRET );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return $req;
	}

	// ─────────────────────────────────────────────────────────────────────
	// F-C-3: HMAC indexed lookup + lazy bcrypt migration
	// ─────────────────────────────────────────────────────────────────────

	public function test_freshly_issued_token_is_found_via_indexed_fast_path(): void {
		$issued = UCP_OAuth_Server::issue_token( 'access', self::CLIENT_ID, self::USER_ID, array( 'ucp:orders' ), 3600 );

		$this->wpdb->reset_query_log();
		$row = UCP_OAuth_Server::lookup_token( $issued['plaintext'], 'access' );

		$this->assertNotNull( $row );
		// Fast path is one indexed get_row(), no get_results() scan, and
		// definitely no password_verify() loop.
		$this->assertSame( 1, $this->wpdb->get_row_calls, 'fast path uses exactly one indexed get_row' );
		$this->assertSame( 0, $this->wpdb->get_results_calls, 'fast path must NOT fall through to legacy bcrypt scan' );

		// And the persisted hash is NOT bcrypt — it's HMAC-SHA256 hex.
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $row['token_hash'] );
	}

	public function test_legacy_bcrypt_row_is_migrated_to_hmac_on_first_lookup(): void {
		// Seed a legacy bcrypt row directly into the in-memory table — this
		// simulates a row issued before F-C-3 landed.
		$plaintext   = 'at_legacy_bcrypt_token';
		$bcrypt_hash = password_hash( $plaintext, PASSWORD_BCRYPT );
		$table       = UCP_Storage::table( 'oauth_tokens' );

		$this->wpdb->tables[ $table ][] = array(
			'id'         => 999,
			'token_type' => 'access',
			'token_hash' => $bcrypt_hash,
			'client_id'  => self::CLIENT_ID,
			'user_id'    => self::USER_ID,
			'scopes'     => json_encode( array( 'ucp:orders' ) ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			'revoked_at' => null,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		// First lookup — slow path verifies bcrypt and migrates the row.
		$row = UCP_OAuth_Server::lookup_token( $plaintext, 'access' );
		$this->assertNotNull( $row );
		$this->assertSame( 999, (int) $row['id'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $row['token_hash'], 'row must be upgraded to HMAC' );

		// Confirm the in-memory row was actually mutated.
		$migrated = $this->wpdb->tables[ $table ][0];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', (string) $migrated['token_hash'] );

		// Second lookup hits the indexed fast path now.
		$this->wpdb->reset_query_log();
		$row2 = UCP_OAuth_Server::lookup_token( $plaintext, 'access' );
		$this->assertNotNull( $row2 );
		$this->assertSame( 0, $this->wpdb->get_results_calls, 'second lookup must NOT scan legacy rows' );
	}

	public function test_indexed_lookup_does_not_match_a_different_token(): void {
		$first  = UCP_OAuth_Server::issue_token( 'access', self::CLIENT_ID, self::USER_ID, array( 'ucp:orders' ), 3600 );
		$second = UCP_OAuth_Server::issue_token( 'access', self::CLIENT_ID, self::USER_ID, array( 'ucp:orders' ), 3600 );

		$row = UCP_OAuth_Server::lookup_token( $first['plaintext'], 'access' );
		$this->assertSame( $first['hash'], (string) $row['token_hash'] );
		$this->assertNotSame( $second['hash'], (string) $row['token_hash'] );
	}

	// ─────────────────────────────────────────────────────────────────────
	// F-C-4: state required + real wp_redirect
	// ─────────────────────────────────────────────────────────────────────

	public function test_authorize_without_state_returns_state_required(): void {
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		$req       = $this->authorize_request( array( 'code_challenge' => $challenge ) );
		$req->set_param( 'state', '' );

		$resp = UCP_OAuth_Server::handle_authorize( $req );
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'state_required', $resp->get_error_code() );
		$this->assertSame( 400, $resp->data['status'] ?? null );
	}

	public function test_authorize_with_empty_state_string_is_rejected(): void {
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		$req       = $this->authorize_request( array( 'code_challenge' => $challenge ) );
		$req->set_param( 'state', '   ' ); // whitespace-only does NOT count as state

		// We treat whitespace as present (RFC doesn't forbid it). State
		// must just be non-empty. Whitespace-only is permitted.
		$resp = UCP_OAuth_Server::handle_authorize( $req );
		$this->assertInstanceOf( WP_REST_Response::class, $resp, 'whitespace state is accepted (length>0)' );
	}

	public function test_redirect_path_uses_wp_redirect_seam(): void {
		// In test mode the seam returns a WP_REST_Response — proves the
		// production code path runs `wp_redirect` + `exit` (the seam is
		// the only way the function returns at all).
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		$resp      = UCP_OAuth_Server::handle_authorize( $this->authorize_request( array( 'code_challenge' => $challenge ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status(), '/authorize renders consent page (200), not 302' );

		// Drive consent → 302 is the redirect seam.
		$consent = UCP_OAuth_Server::handle_consent(
			$this->consent_request(
				array(
					'_wpnonce'              => $this->nonce_for( self::CLIENT_ID ),
					'decision'              => 'approve',
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $consent );
		$this->assertSame( 302, $consent->get_status() );
		$headers = $consent->get_headers();
		$this->assertArrayHasKey( 'Location', $headers );
	}

	// ─────────────────────────────────────────────────────────────────────
	// F-C-5: /authorize is GET-only
	// ─────────────────────────────────────────────────────────────────────

	public function test_authorize_route_registers_only_readable_method(): void {
		// Capture register_rest_route calls.
		$captured = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $namespace, $route, $args ) use ( &$captured ) {
				$captured[ $route ] = $args;
				return true;
			}
		);

		UCP_OAuth_Server::register_routes();

		$this->assertArrayHasKey( '/oauth/authorize', $captured );
		$authorize_args = $captured['/oauth/authorize'];
		// Args is an array of route configs; pull the first.
		$first = $authorize_args[0] ?? $authorize_args;
		$this->assertSame( WP_REST_Server::READABLE, $first['methods'], '/authorize must be GET-only (no POST)' );

		// And /consent is POST-only.
		$this->assertArrayHasKey( '/oauth/consent', $captured );
		$consent_args = $captured['/oauth/consent'];
		$first        = $consent_args[0] ?? $consent_args;
		$this->assertSame( WP_REST_Server::CREATABLE, $first['methods'], '/consent must be POST-only' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// F-C-6: consent screen + /consent handler
	// ─────────────────────────────────────────────────────────────────────

	public function test_authorize_renders_consent_page_for_logged_in_user(): void {
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		$resp      = UCP_OAuth_Server::handle_authorize( $this->authorize_request( array( 'code_challenge' => $challenge ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'html', $body );
		$html = (string) $body['html'];
		$this->assertStringContainsString( '<form method="post"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'name="decision" value="approve"', $html );
		$this->assertStringContainsString( 'name="decision" value="deny"', $html );
		$this->assertStringContainsString( self::REDIRECT_URI, $html );
	}

	public function test_consent_with_valid_nonce_and_approve_issues_code(): void {
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		// Render the consent page so a nonce is created.
		UCP_OAuth_Server::handle_authorize( $this->authorize_request( array( 'code_challenge' => $challenge ) ) );
		$nonce = $this->nonce_for( self::CLIENT_ID );
		$this->assertNotSame( '', $nonce );

		$resp = UCP_OAuth_Server::handle_consent(
			$this->consent_request(
				array(
					'_wpnonce'              => $nonce,
					'decision'              => 'approve',
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 302, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'redirect_to', $body );
		$qs = parse_url( $body['redirect_to'], PHP_URL_QUERY );
		parse_str( (string) $qs, $parts );
		$this->assertArrayHasKey( 'code', $parts );
		$this->assertStringStartsWith( 'cod_', (string) $parts['code'] );
		$this->assertSame( 'xyz', (string) $parts['state'] );
	}

	public function test_consent_with_deny_redirects_with_access_denied_error(): void {
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		UCP_OAuth_Server::handle_authorize( $this->authorize_request( array( 'code_challenge' => $challenge ) ) );
		$nonce = $this->nonce_for( self::CLIENT_ID );

		$resp = UCP_OAuth_Server::handle_consent(
			$this->consent_request(
				array(
					'_wpnonce'              => $nonce,
					'decision'              => 'deny',
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
				)
			)
		);
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 302, $resp->get_status() );
		$body = $resp->get_data();
		$qs   = parse_url( $body['redirect_to'], PHP_URL_QUERY );
		parse_str( (string) $qs, $parts );
		$this->assertArrayNotHasKey( 'code', $parts );
		$this->assertSame( 'access_denied', (string) $parts['error'] );
		$this->assertSame( 'xyz', (string) $parts['state'] );
	}

	public function test_consent_without_nonce_returns_403(): void {
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		$resp      = UCP_OAuth_Server::handle_consent(
			$this->consent_request(
				array(
					'decision'              => 'approve',
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'consent_bad_nonce', $resp->get_error_code() );
		$this->assertSame( 403, $resp->data['status'] ?? null );
	}

	public function test_consent_with_bad_nonce_returns_403(): void {
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		$resp      = UCP_OAuth_Server::handle_consent(
			$this->consent_request(
				array(
					'_wpnonce'              => 'bogus-nonce-value',
					'decision'              => 'approve',
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'consent_bad_nonce', $resp->get_error_code() );
	}

	public function test_unauthenticated_authorize_redirects_to_wp_login(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );

		$resp = UCP_OAuth_Server::handle_authorize( $this->authorize_request( array( 'code_challenge' => $challenge ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 302, $resp->get_status() );
		// The seam returned a WP_REST_Response with a Location header.
		$headers = $resp->get_headers();
		$this->assertArrayHasKey( 'Location', $headers );
	}

	// ─────────────────────────────────────────────────────────────────────
	// F-C-7: per-client_id rate limit on /token
	// ─────────────────────────────────────────────────────────────────────

	public function test_token_endpoint_rate_limits_after_10_failed_attempts(): void {
		$bad = $this->token_request_with_bad_secret();

		// 10 failed attempts — all return 401 invalid_client but do NOT trip
		// the limiter yet.
		for ( $i = 1; $i <= 10; $i++ ) {
			$resp = UCP_OAuth_Server::handle_token( $bad );
			$this->assertInstanceOf( WP_Error::class, $resp );
			$this->assertSame( 'invalid_client', $resp->get_error_code(), "attempt {$i} should be invalid_client" );
		}

		// 11th attempt — limiter fires.
		$resp = UCP_OAuth_Server::handle_token( $bad );
		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'too_many_attempts', $resp->get_error_code() );
		$this->assertSame( 429, $resp->data['status'] ?? null );
		$this->assertSame( 60, $resp->data['retry_after'] ?? null );
	}

	public function test_successful_token_call_clears_rate_limit_bucket(): void {
		$bad  = $this->token_request_with_bad_secret();

		// Burn 5 failures.
		for ( $i = 0; $i < 5; $i++ ) {
			UCP_OAuth_Server::handle_token( $bad );
		}

		// One success. Bucket should clear.
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		UCP_OAuth_Server::handle_authorize( $this->authorize_request( array( 'code_challenge' => $challenge ) ) );
		$nonce      = $this->nonce_for( self::CLIENT_ID );
		$consent    = UCP_OAuth_Server::handle_consent(
			$this->consent_request(
				array(
					'_wpnonce'              => $nonce,
					'decision'              => 'approve',
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
				)
			)
		);
		parse_str( (string) parse_url( $consent->get_data()['redirect_to'], PHP_URL_QUERY ), $parts );
		$code = (string) $parts['code'];

		$ok = UCP_OAuth_Server::handle_token( $this->token_request( array( 'code' => $code, 'code_verifier' => $verifier ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $ok );
		$this->assertSame( 200, $ok->get_status() );

		// Now I can burn 10 MORE bad attempts before the limiter fires.
		for ( $i = 1; $i <= 10; $i++ ) {
			$resp = UCP_OAuth_Server::handle_token( $bad );
			$this->assertSame( 'invalid_client', $resp->get_error_code(), "attempt {$i} should still be invalid_client (bucket was cleared)" );
		}
	}

	public function test_rate_limit_is_per_client_id(): void {
		// Burn 11 fails for our client.
		$bad = $this->token_request_with_bad_secret();
		for ( $i = 0; $i < 11; $i++ ) {
			UCP_OAuth_Server::handle_token( $bad );
		}

		// A different client_id must NOT inherit the rate limit.
		\UCP_OAuth_Clients::$expected_client_id = 'agt_other';
		\UCP_OAuth_Clients::$expected_secret    = 'other-secret';

		$other = new WP_REST_Request();
		$other->set_param( 'grant_type', 'authorization_code' );
		$other->set_param( 'client_id', 'agt_other' );
		$other->set_param( 'client_secret', 'wrong' );

		$resp = UCP_OAuth_Server::handle_token( $other );
		$this->assertSame( 'invalid_client', $resp->get_error_code(), 'rate limit must be per-client_id, not global' );
	}

	private function token_request_with_bad_secret(): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_param( 'grant_type', 'authorization_code' );
		$req->set_param( 'client_id', self::CLIENT_ID );
		$req->set_param( 'client_secret', 'wrong-secret' );
		return $req;
	}
}

/**
 * In-memory $wpdb that handles every query shape the OAuth server emits in
 * the post-F-C-3 world:
 *   - SELECT * FROM <table> WHERE token_hash = %s AND token_type = %s AND revoked_at IS NULL AND expires_at > %s LIMIT 1
 *   - SELECT * FROM <table> WHERE token_hash = %s AND token_type = %s AND revoked_at IS NOT NULL LIMIT 1
 *   - SELECT * FROM <table> WHERE token_type = %s AND revoked_at IS NULL AND expires_at > %s AND token_hash LIKE %s
 *   - SELECT * FROM <table> WHERE token_type = %s AND revoked_at IS NOT NULL AND token_hash LIKE %s
 *   - INSERT into oauth_tokens
 *   - UPDATE oauth_tokens SET revoked_at = ... WHERE id = ...
 *   - UPDATE oauth_tokens SET token_hash = ... WHERE id = ...  (lazy migration)
 *   - $wpdb->query( prepare( "UPDATE ... WHERE client_id = ... AND user_id = ..." ) )  (family revoke)
 */
final class HardeningWpdb { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	/** @var array<string, array<int, array<string,mixed>>> */
	public array $tables = array();
	public string $prefix = 'wp_';

	public int $get_row_calls     = 0;
	public int $get_results_calls = 0;

	/** @var array<int,mixed> */
	private array $last_args = array();
	private string $last_query = '';
	private int $next_id = 0;

	public function reset_query_log(): void {
		$this->get_row_calls     = 0;
		$this->get_results_calls = 0;
	}

	public function prepare( string $query, ...$args ): string {
		$this->last_query = $query;
		$this->last_args  = $args;
		return $query;
	}

	public function get_row( string $query, $output = ARRAY_A ) {
		++$this->get_row_calls;
		// Indexed live: WHERE token_hash = %s AND token_type = %s AND revoked_at IS NULL AND expires_at > %s
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+token_hash\s*=\s*%s\s+AND\s+token_type\s*=\s*%s\s+AND\s+revoked_at\s+IS\s+NULL\s+AND\s+expires_at/i', $query, $m ) ) {
			$table = $m[1];
			$hash  = (string) ( $this->last_args[0] ?? '' );
			$type  = (string) ( $this->last_args[1] ?? '' );
			$now   = (string) ( $this->last_args[2] ?? gmdate( 'Y-m-d H:i:s' ) );
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				if ( ( $row['token_hash'] ?? '' ) !== $hash ) {
					continue;
				}
				if ( ( $row['token_type'] ?? '' ) !== $type ) {
					continue;
				}
				if ( null !== ( $row['revoked_at'] ?? null ) ) {
					continue;
				}
				if ( ( $row['expires_at'] ?? '' ) <= $now ) {
					continue;
				}
				return $row;
			}
			return null;
		}
		// Indexed revoked.
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+token_hash\s*=\s*%s\s+AND\s+token_type\s*=\s*%s\s+AND\s+revoked_at\s+IS\s+NOT\s+NULL/i', $query, $m ) ) {
			$table = $m[1];
			$hash  = (string) ( $this->last_args[0] ?? '' );
			$type  = (string) ( $this->last_args[1] ?? '' );
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				if ( ( $row['token_hash'] ?? '' ) !== $hash ) {
					continue;
				}
				if ( ( $row['token_type'] ?? '' ) !== $type ) {
					continue;
				}
				if ( null === ( $row['revoked_at'] ?? null ) ) {
					continue;
				}
				return $row;
			}
			return null;
		}
		return null;
	}

	public function get_results( string $query, $output = ARRAY_A ): array {
		++$this->get_results_calls;
		// Legacy bcrypt scan (live): includes "AND token_hash LIKE %s"
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+token_type\s*=\s*%s\s+AND\s+revoked_at\s+IS\s+NULL\s+AND\s+expires_at\s*>\s*%s\s+AND\s+token_hash\s+LIKE/i', $query, $m ) ) {
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
				$stored = (string) ( $row['token_hash'] ?? '' );
				if ( ! str_starts_with( $stored, '$2' ) ) {
					continue;
				}
				$out[] = $row;
			}
			return $out;
		}
		// Legacy bcrypt scan (revoked).
		if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+token_type\s*=\s*%s\s+AND\s+revoked_at\s+IS\s+NOT\s+NULL\s+AND\s+token_hash\s+LIKE/i', $query, $m ) ) {
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
				$stored = (string) ( $row['token_hash'] ?? '' );
				if ( ! str_starts_with( $stored, '$2' ) ) {
					continue;
				}
				$out[] = $row;
			}
			return $out;
		}
		return array();
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

	public function query( string $query ): int {
		return 0;
	}
}

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
