<?php
/**
 * Tests for UCP_Url_Guard — SSRF defense for webhook callback URLs.
 *
 * Covers F-D-1 (subscribe-time validation) and the per-IP-class branches
 * in check_webhook_callback(). DNS is stubbed via the resolver-injection
 * hook on UCP_Url_Guard so we can drive every reject branch without a
 * live network. wp_parse_url is monkey-patched onto plain parse_url since
 * WP's wrapper is just a hardener.
 *
 * @package WooCommerceUCP
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/core/class-ucp-url-guard.php';

final class UrlGuardTest extends TestCase {

	/**
	 * Map host => list of A records (IPv4 strings). Missing key = host
	 * not in the table (resolver returns false).
	 *
	 * @var array<string, array<int,string>>
	 */
	private array $a_map = array();

	/**
	 * Map host => list of AAAA records (IPv6 strings).
	 *
	 * @var array<string, array<int,string>>
	 */
	private array $aaaa_map = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->a_map    = array();
		$this->aaaa_map = array();
		$a              = &$this->a_map;
		$aaaa           = &$this->aaaa_map;

		// wp_parse_url is just a hardened wrapper around parse_url — for
		// these tests delegating is sufficient.
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
			}
		);

		// Inject test resolvers so we drive DNS deterministically.
		UCP_Url_Guard::set_resolvers(
			static function ( string $host ) use ( &$a ) {
				return array_key_exists( $host, $a ) ? $a[ $host ] : false;
			},
			static function ( string $host ) use ( &$aaaa ) {
				return array_key_exists( $host, $aaaa ) ? $aaaa[ $host ] : false;
			}
		);
	}

	protected function tearDown(): void {
		UCP_Url_Guard::set_resolvers( null, null );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Scheme / parse / userinfo / port ─────────────────────────────────

	public function test_accepts_normal_https_public_ip(): void {
		$this->a_map['hooks.example.com'] = array( '8.8.8.8' );
		$this->assertNull( UCP_Url_Guard::check_webhook_callback( 'https://hooks.example.com/x' ) );
	}

	public function test_rejects_http(): void {
		$err = UCP_Url_Guard::check_webhook_callback( 'http://hooks.example.com/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_file_scheme(): void {
		$err = UCP_Url_Guard::check_webhook_callback( 'file:///etc/passwd' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_gopher_scheme(): void {
		$err = UCP_Url_Guard::check_webhook_callback( 'gopher://hooks.example.com/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_userinfo(): void {
		$this->a_map['example.com'] = array( '8.8.8.8' );
		$err                        = UCP_Url_Guard::check_webhook_callback( 'https://a:b@example.com/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_explicit_non_443_port(): void {
		$this->a_map['example.com'] = array( '8.8.8.8' );
		$err                        = UCP_Url_Guard::check_webhook_callback( 'https://example.com:8080/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_accepts_explicit_443_port(): void {
		$this->a_map['example.com'] = array( '8.8.8.8' );
		$this->assertNull( UCP_Url_Guard::check_webhook_callback( 'https://example.com:443/x' ) );
	}

	public function test_rejects_unparseable_url(): void {
		$err = UCP_Url_Guard::check_webhook_callback( 'http://:::::' );
		$this->assertInstanceOf( WP_Error::class, $err );
		// Either invalid_url or unsafe_callback_url — both indicate refusal.
		$this->assertContains(
			$err->get_error_code(),
			array( 'invalid_url', 'unsafe_callback_url' )
		);
	}

	public function test_rejects_empty_url(): void {
		$err = UCP_Url_Guard::check_webhook_callback( '' );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	// ── Literal hosts (force agents to use real DNS names) ───────────────

	public function test_rejects_ipv4_literal_host(): void {
		$err = UCP_Url_Guard::check_webhook_callback( 'https://127.0.0.1/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_ipv6_literal_host(): void {
		$err = UCP_Url_Guard::check_webhook_callback( 'https://[::1]/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	// ── IPv4 address-class rejections (resolved via injected resolver) ───

	public function test_rejects_loopback_resolution(): void {
		$this->a_map['evil.example'] = array( '127.0.0.1' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
		// Must not echo the resolved IP (info leak).
		$this->assertStringNotContainsString( '127.0.0.1', $err->get_error_message() );
	}

	public function test_rejects_rfc1918_resolution_10(): void {
		$this->a_map['evil.example'] = array( '10.0.0.5' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
		$this->assertStringNotContainsString( '10.0.0.5', $err->get_error_message() );
	}

	public function test_rejects_rfc1918_resolution_192_168(): void {
		$this->a_map['evil.example'] = array( '192.168.1.5' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_rfc1918_resolution_172_16(): void {
		$this->a_map['evil.example'] = array( '172.16.0.1' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_rfc1918_resolution_172_31(): void {
		// Boundary inside 172.16.0.0/12 (172.16-31.*.*).
		$this->a_map['evil.example'] = array( '172.31.255.254' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_accepts_172_32_public(): void {
		// Just outside 172.16.0.0/12 — first byte still 172 but second byte 32.
		$this->a_map['public.example'] = array( '172.32.0.1' );
		$this->assertNull( UCP_Url_Guard::check_webhook_callback( 'https://public.example/x' ) );
	}

	public function test_rejects_link_local_aws_imds(): void {
		$this->a_map['evil.example'] = array( '169.254.169.254' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_cgn(): void {
		$this->a_map['evil.example'] = array( '100.64.0.5' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_multicast_v4(): void {
		$this->a_map['evil.example'] = array( '224.0.0.5' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_reserved_v4(): void {
		$this->a_map['evil.example'] = array( '240.0.0.1' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_zero_network(): void {
		$this->a_map['evil.example'] = array( '0.0.0.0' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_when_any_resolved_ip_is_unsafe(): void {
		// Defense against split DNS: one A record benign, another internal.
		$this->a_map['evil.example'] = array( '8.8.8.8', '10.0.0.1' );
		$err                         = UCP_Url_Guard::check_webhook_callback( 'https://evil.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	// ── IPv6 address-class rejections (resolved via injected resolver) ───

	public function test_rejects_ipv6_loopback_resolution(): void {
		$this->aaaa_map['v6.example'] = array( '::1' );
		$err                          = UCP_Url_Guard::check_webhook_callback( 'https://v6.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_ipv6_ula_resolution(): void {
		$this->aaaa_map['v6.example'] = array( 'fc00::1' );
		$err                          = UCP_Url_Guard::check_webhook_callback( 'https://v6.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_ipv6_link_local_resolution(): void {
		$this->aaaa_map['v6.example'] = array( 'fe80::1' );
		$err                          = UCP_Url_Guard::check_webhook_callback( 'https://v6.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_ipv6_multicast_resolution(): void {
		$this->aaaa_map['v6.example'] = array( 'ff02::1' );
		$err                          = UCP_Url_Guard::check_webhook_callback( 'https://v6.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_ipv4_mapped_ipv6_loopback(): void {
		// ::ffff:127.0.0.1 — IPv4-mapped IPv6 form of 127.0.0.1.
		$this->aaaa_map['v6.example'] = array( '::ffff:127.0.0.1' );
		$err                          = UCP_Url_Guard::check_webhook_callback( 'https://v6.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_ipv4_mapped_ipv6_rfc1918(): void {
		// ::ffff:10.0.0.5 — IPv4-mapped IPv6 form of 10.0.0.5.
		$this->aaaa_map['v6.example'] = array( '::ffff:10.0.0.5' );
		$err                          = UCP_Url_Guard::check_webhook_callback( 'https://v6.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_rejects_ipv6_documentation_range(): void {
		$this->aaaa_map['v6.example'] = array( '2001:db8::1' );
		$err                          = UCP_Url_Guard::check_webhook_callback( 'https://v6.example/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'unsafe_callback_url', $err->get_error_code() );
	}

	public function test_accepts_public_ipv6(): void {
		// 2606:4700::1 (Cloudflare) — a real, public, routable v6.
		$this->aaaa_map['v6.example'] = array( '2606:4700::1' );
		$this->assertNull( UCP_Url_Guard::check_webhook_callback( 'https://v6.example/x' ) );
	}

	// ── Resolution failure ───────────────────────────────────────────────

	public function test_rejects_dns_resolution_failure(): void {
		// Both lookups absent → resolution failed.
		$err = UCP_Url_Guard::check_webhook_callback( 'https://nope.invalid/x' );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'dns_resolution_failed', $err->get_error_code() );
	}
}
