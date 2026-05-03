<?php
/**
 * Tests for UCP_OAuth_Server PKCE enforcement — F-C-2.
 *
 * Implements OAuth 2.1 §4.1.2.1: PKCE is mandatory at /oauth/authorize and
 * the only acceptable `code_challenge_method` is `S256`. The legacy `plain`
 * method (RFC 7636 §4.2) is forbidden. The /oauth/token exchange path also
 * verifies the supplied `code_verifier` under S256 only.
 *
 * Covers:
 *   - /authorize without `code_challenge`        → 400 pkce_required
 *   - /authorize with code_challenge_method=plain → 400 pkce_method_unsupported
 *   - /authorize with code_challenge_method=s256 (lowercase) → 400 (case-sensitive)
 *   - /authorize happy path (S256 explicit)       → 302 with code in Location
 *   - /authorize happy path (S256 default — method omitted) → 302 with code
 *   - /token exchange S256 verifier matches       → 200 with access+refresh
 *   - /token exchange S256 verifier mismatches    → 400 pkce_verification_failed
 *   - /token exchange verifier missing            → 400 pkce_verifier_required
 *
 * Runs in a separate process to avoid clashing with the fake
 * UCP_OAuth_Server class declared by other tests' stubs.
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
final class OauthPkceMandatoryTest extends TestCase {

	private const CLIENT_ID     = 'agt_pkce_client';
	private const CLIENT_SECRET = 'plaintext-secret';
	private const REDIRECT_URI  = 'https://agent.example/cb';
	private const USER_ID       = 77;

	/** @var PkceMandatoryWpdb */
	private PkceMandatoryWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$this->wpdb = new PkceMandatoryWpdb();
		$wpdb       = $this->wpdb;

		Functions\when( 'current_time' )->alias(
			static function ( $fmt, $gmt = 0 ) {
				return gmdate( 'Y-m-d H:i:s' );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_current_user_id' )->justReturn( self::USER_ID );
		Functions\when( 'wp_login_url' )->returnArg();
		Functions\when( 'rest_url' )->alias(
			static function ( $path = '' ) {
				return 'https://shop.example/wp-json/' . ltrim( (string) $path, '/' );
			}
		);

		if ( ! class_exists( 'UCP_Storage' ) ) {
			eval( 'class UCP_Storage { public static function table( string $short ): string { return "wp_ucp_" . $short; } }' );
		}

		if ( ! class_exists( 'UCP_OAuth_Clients' ) ) {
			eval(
				'class UCP_OAuth_Clients {
					public static string $expected_client_id = "";
					public static string $expected_secret = "";
					public static string $expected_redirect_uri = "";
					public static function find( string $client_id ) {
						if ( $client_id === self::$expected_client_id ) {
							return array( "client_id" => $client_id, "redirect_uris" => array( self::$expected_redirect_uri ) );
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
		\UCP_OAuth_Clients::$expected_client_id     = self::CLIENT_ID;
		\UCP_OAuth_Clients::$expected_secret        = self::CLIENT_SECRET;
		\UCP_OAuth_Clients::$expected_redirect_uri  = self::REDIRECT_URI;

		require_once __DIR__ . '/../includes/core/class-ucp-oauth-server.php';
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

	private function token_request( string $code, string $verifier = '' ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_param( 'grant_type', 'authorization_code' );
		$req->set_param( 'client_id', self::CLIENT_ID );
		$req->set_param( 'client_secret', self::CLIENT_SECRET );
		$req->set_param( 'code', $code );
		if ( '' !== $verifier ) {
			$req->set_param( 'code_verifier', $verifier );
		}
		return $req;
	}

	/**
	 * Drive the /authorize endpoint with a valid S256 challenge and pull
	 * the freshly-minted authorization code out of the redirect Location.
	 */
	private function authorize_and_get_code( string $verifier, ?string $explicit_method = 'S256' ): string {
		$challenge = self::compute_s256( $verifier );
		$params    = array( 'code_challenge' => $challenge );
		if ( null !== $explicit_method ) {
			$params['code_challenge_method'] = $explicit_method;
		}
		$resp = UCP_OAuth_Server::handle_authorize( $this->authorize_request( $params ) );
		$this->assertInstanceOf( WP_REST_Response::class, $resp, 'authorize must succeed for valid S256 PKCE' );
		$this->assertSame( 302, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'redirect_to', $body );
		$qs = parse_url( $body['redirect_to'], PHP_URL_QUERY );
		$this->assertIsString( $qs );
		parse_str( $qs, $parts );
		$this->assertArrayHasKey( 'code', $parts );
		return (string) $parts['code'];
	}

	// ── /authorize — PKCE enforcement ────────────────────────────────────

	public function test_authorize_without_code_challenge_returns_pkce_required(): void {
		$resp = UCP_OAuth_Server::handle_authorize( $this->authorize_request() );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'pkce_required', $resp->get_error_code() );
		$this->assertSame( 400, $resp->data['status'] ?? null );
	}

	public function test_authorize_with_empty_code_challenge_returns_pkce_required(): void {
		$resp = UCP_OAuth_Server::handle_authorize(
			$this->authorize_request(
				array(
					'code_challenge'        => '',
					'code_challenge_method' => 'S256',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'pkce_required', $resp->get_error_code() );
	}

	public function test_authorize_with_plain_method_is_rejected(): void {
		$verifier  = str_repeat( 'a', 43 );
		$resp      = UCP_OAuth_Server::handle_authorize(
			$this->authorize_request(
				array(
					'code_challenge'        => $verifier, // plain semantics
					'code_challenge_method' => 'plain',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'pkce_method_unsupported', $resp->get_error_code() );
		$this->assertSame( 400, $resp->data['status'] ?? null );
	}

	public function test_authorize_with_lowercase_s256_method_is_rejected(): void {
		// RFC 7636 specifies the method names case-sensitively. Accepting
		// `s256` would let a sloppy client coast through with no method
		// enforced, since the server would fall through to "unsupported".
		$verifier  = str_repeat( 'a', 43 );
		$challenge = self::compute_s256( $verifier );
		$resp      = UCP_OAuth_Server::handle_authorize(
			$this->authorize_request(
				array(
					'code_challenge'        => $challenge,
					'code_challenge_method' => 's256', // wrong case
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'pkce_method_unsupported', $resp->get_error_code() );
	}

	public function test_authorize_with_explicit_S256_method_succeeds(): void {
		$verifier = str_repeat( 'a', 43 );
		$code     = $this->authorize_and_get_code( $verifier, 'S256' );

		$this->assertNotSame( '', $code );
		$this->assertStringStartsWith( 'cod_', $code );
	}

	public function test_authorize_defaults_method_to_S256_when_omitted_with_challenge_present(): void {
		// Client supplies the challenge but no method. The server MUST
		// default to S256 (not `plain`) so the verification path stays
		// strong.
		$verifier = str_repeat( 'a', 43 );
		$code     = $this->authorize_and_get_code( $verifier, null );

		$this->assertStringStartsWith( 'cod_', $code );

		// Defensive: confirm the persisted row really stores S256, not
		// `plain` (regression — the pre-fix code defaulted to plain here).
		$row_method = null;
		foreach ( $this->wpdb->tables['wp_ucp_oauth_tokens'] ?? array() as $row ) {
			if ( ( $row['token_type'] ?? '' ) !== 'authorization_code' ) {
				continue;
			}
			$row_method = (string) ( $row['code_challenge_method'] ?? '' );
		}
		$this->assertSame( 'S256', $row_method );
	}

	// ── /token — PKCE verification ──────────────────────────────────────

	public function test_token_exchange_with_matching_S256_verifier_succeeds(): void {
		$verifier = str_repeat( 'a', 43 );
		$code     = $this->authorize_and_get_code( $verifier );

		$resp = UCP_OAuth_Server::handle_token( $this->token_request( $code, $verifier ) );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 200, $resp->get_status() );
		$body = $resp->get_data();
		$this->assertArrayHasKey( 'access_token', $body );
		$this->assertArrayHasKey( 'refresh_token', $body );
		$this->assertSame( 'Bearer', $body['token_type'] );
	}

	public function test_token_exchange_with_mismatched_verifier_returns_pkce_verification_failed(): void {
		$verifier = str_repeat( 'a', 43 );
		$code     = $this->authorize_and_get_code( $verifier );

		$resp = UCP_OAuth_Server::handle_token( $this->token_request( $code, str_repeat( 'b', 43 ) ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'pkce_verification_failed', $resp->get_error_code() );
		$this->assertSame( 400, $resp->data['status'] ?? null );
	}

	public function test_token_exchange_without_verifier_returns_pkce_verifier_required(): void {
		$verifier = str_repeat( 'a', 43 );
		$code     = $this->authorize_and_get_code( $verifier );

		// No verifier supplied at all.
		$resp = UCP_OAuth_Server::handle_token( $this->token_request( $code, '' ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'pkce_verifier_required', $resp->get_error_code() );
		$this->assertSame( 400, $resp->data['status'] ?? null );
	}

	public function test_token_exchange_with_legacy_plain_stored_method_is_rejected(): void {
		// Defensive: simulate a row left over from before mandatory PKCE
		// shipped, where `code_challenge_method` was stored as `plain`.
		// The exchange path MUST refuse to fall back to direct comparison.
		$verifier = str_repeat( 'a', 43 );
		// Inject the row directly, bypassing handle_authorize() (which now
		// won't allow `plain`). Use the issuer to get a valid bcrypt hash,
		// then mutate the stored method to `plain` to mimic legacy data.
		$issued = UCP_OAuth_Server::issue_token(
			'authorization_code',
			self::CLIENT_ID,
			self::USER_ID,
			array( 'ucp:checkout' ),
			600,
			$verifier, // intentionally plain-style: challenge = verifier
			'plain'
		);
		$this->assertNotSame( '', $issued['plaintext'] );

		// Even with the "right" verifier (which would pass plain
		// semantics), the server must reject because S256 is required.
		$resp = UCP_OAuth_Server::handle_token( $this->token_request( $issued['plaintext'], $verifier ) );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'pkce_verification_failed', $resp->get_error_code() );
	}
}

/**
 * In-memory $wpdb covering the query shapes the OAuth server emits during
 * the authorize → token exchange flow. Mirrors the simpler RefreshRotationWpdb
 * (live SELECT, INSERT, UPDATE-by-id) — we don't exercise the family-revoke
 * UPDATE here.
 */
final class PkceMandatoryWpdb { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
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

	public function query( string $query ): int {
		return 0;
	}
}

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
