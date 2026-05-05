<?php
/**
 * UCP URL Guard — SSRF defense for outbound webhook callback URLs.
 *
 * Agents POST a `callback_url` when they subscribe to webhooks. Without
 * validation, that URL can point at:
 *   - non-https schemes (file://, gopher://, …)
 *   - loopback (127.0.0.1, ::1, localhost-resolving names)
 *   - RFC 1918 / link-local / cloud metadata endpoints (169.254.169.254)
 *   - IPv6 ULA / link-local / IPv4-mapped variants of all of the above
 *
 * When the cron worker fires, wp_remote_post would happily talk to any of
 * those — that's classic SSRF. This class bundles the parse + DNS-resolve
 * + IP-class checks behind a single static call so subscribe-time AND
 * delivery-time can both gate on the same logic. The delivery-time re-
 * check is the TOCTOU defense: DNS can flip between subscribe and fire.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Url_Guard — single-purpose SSRF gate for webhook callback URLs.
 */
final class UCP_Url_Guard {

	/**
	 * IPv4 ranges considered unsafe as webhook targets.
	 *
	 * Each entry is [ network, prefix-length ]. We compare with ip2long +
	 * bitmask — cheap and exact for /n prefixes.
	 *
	 * @var array<int,array{0:string,1:int}>
	 */
	private const IPV4_DENY = array(
		array( '0.0.0.0', 8 ),         // "this network" — RFC 1122.
		array( '10.0.0.0', 8 ),        // RFC 1918 private.
		array( '100.64.0.0', 10 ),     // RFC 6598 carrier-grade NAT.
		array( '127.0.0.0', 8 ),       // Loopback.
		array( '169.254.0.0', 16 ),    // Link-local — incl. AWS/GCP/Azure metadata.
		array( '172.16.0.0', 12 ),     // RFC 1918 private.
		array( '192.0.0.0', 24 ),      // IETF protocol assignments.
		array( '192.0.2.0', 24 ),      // TEST-NET-1 (docs).
		array( '192.168.0.0', 16 ),    // RFC 1918 private.
		array( '198.18.0.0', 15 ),     // Network benchmark — RFC 2544.
		array( '198.51.100.0', 24 ),   // TEST-NET-2 (docs).
		array( '203.0.113.0', 24 ),    // TEST-NET-3 (docs).
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- "224.0.0.0/4" in the trailing comment is prose, not commented-out code.
		array( '224.0.0.0', 4 ),       // Multicast (224.0.0.0/4).
		array( '240.0.0.0', 4 ),       // Reserved (240.0.0.0/4 — incl. broadcast).
	);

	/**
	 * Optional resolver overrides for tests. Production code never sets
	 * these; tests inject deterministic stubs via set_resolvers().
	 *
	 * @var (callable(string):(array<int,string>|false))|null
	 */
	private static $ipv4_resolver = null;

	/**
	 * @var (callable(string):(array<int,string>|false))|null
	 */
	private static $ipv6_resolver = null;

	/**
	 * Inject resolver callables. Used by unit tests so we don't have to
	 * monkey-patch dns_get_record / gethostbynamel (Patchwork can't
	 * reliably override built-ins). Pass null to restore production lookup.
	 *
	 * @param (callable(string):(array<int,string>|false))|null $ipv4 IPv4 resolver returning a list of A records.
	 * @param (callable(string):(array<int,string>|false))|null $ipv6 IPv6 resolver returning a list of AAAA records.
	 * @return void
	 */
	public static function set_resolvers( $ipv4, $ipv6 ): void {
		self::$ipv4_resolver = $ipv4;
		self::$ipv6_resolver = $ipv6;
	}

	/**
	 * Returns null if the URL is safe to use as a webhook callback target,
	 * or a WP_Error explaining why not.
	 *
	 * Rejects: non-https scheme, userinfo, non-default port, IPv4/IPv6
	 * literal hosts, hostnames whose A/AAAA records resolve into private/
	 * loopback/link-local/multicast/reserved space, and (by extension)
	 * cloud metadata endpoints. DNS lookups happen here so callers don't
	 * have to repeat them.
	 *
	 * @param string $url URL to validate.
	 * @return WP_Error|null Null if safe, WP_Error otherwise.
	 */
	public static function check_webhook_callback( string $url ): ?WP_Error {
		if ( '' === $url ) {
			return new WP_Error( 'invalid_url', 'callback_url is empty' );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return new WP_Error( 'invalid_url', 'callback_url could not be parsed' );
		}

		// Scheme — https only. Reject http, file, gopher, ftp, data, javascript, …
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		if ( 'https' !== $scheme ) {
			return new WP_Error( 'unsafe_callback_url', 'callback_url scheme must be https' );
		}

		// Userinfo — embedding credentials in the URL is a SSRF/redirect smuggling vector.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'unsafe_callback_url', 'callback_url must not contain userinfo' );
		}

		// Port — accept omitted (default 443) or explicit 443; reject anything else.
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return new WP_Error( 'unsafe_callback_url', 'callback_url must use the default https port (443)' );
		}

		$host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		if ( '' === $host ) {
			return new WP_Error( 'unsafe_callback_url', 'callback_url is missing a host' );
		}

		// Reject IPv6 literal hosts: parse_url leaves the brackets off but
		// the colons remain. Force agents to use a real DNS name so we
		// always have a hostname to re-resolve at delivery time.
		if ( false !== strpos( $host, ':' ) ) {
			return new WP_Error( 'unsafe_callback_url', 'callback_url must use a DNS hostname (IPv6 literal hosts are not allowed)' );
		}

		// Reject IPv4 literal hosts.
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return new WP_Error( 'unsafe_callback_url', 'callback_url must use a DNS hostname (IPv4 literal hosts are not allowed)' );
		}

		// Resolve A and AAAA. We require AT LEAST ONE record. We then
		// reject if ANY resolved record is in a denied class (defense
		// against split-DNS where one record is benign and another is
		// internal).
		$ipv4 = self::resolve_ipv4( $host );
		$ipv6 = self::resolve_ipv6( $host );

		if ( count( $ipv4 ) === 0 && count( $ipv6 ) === 0 ) {
			return new WP_Error( 'dns_resolution_failed', 'callback_url host did not resolve' );
		}

		foreach ( $ipv4 as $ip ) {
			$reason = self::classify_ipv4( (string) $ip );
			if ( null !== $reason ) {
				return new WP_Error( 'unsafe_callback_url', sprintf( 'callback_url resolves into a forbidden address class (%s)', $reason ) );
			}
		}

		foreach ( $ipv6 as $ip ) {
			$reason = self::classify_ipv6( (string) $ip );
			if ( null !== $reason ) {
				return new WP_Error( 'unsafe_callback_url', sprintf( 'callback_url resolves into a forbidden address class (%s)', $reason ) );
			}
		}

		return null;
	}

	/**
	 * Resolve A records for $host. Returns a list of IPv4 strings; empty
	 * on failure.
	 *
	 * @param string $host Hostname.
	 * @return array<int,string>
	 */
	private static function resolve_ipv4( string $host ): array {
		$resolver = self::$ipv4_resolver;
		$result   = is_callable( $resolver ) ? $resolver( $host ) : gethostbynamel( $host );
		return is_array( $result ) ? array_values( array_map( 'strval', $result ) ) : array();
	}

	/**
	 * Resolve AAAA records for $host. Returns a list of IPv6 strings;
	 * empty on failure.
	 *
	 * @param string $host Hostname.
	 * @return array<int,string>
	 */
	private static function resolve_ipv6( string $host ): array {
		$resolver = self::$ipv6_resolver;
		if ( is_callable( $resolver ) ) {
			$result = $resolver( $host );
			return is_array( $result ) ? array_values( array_map( 'strval', $result ) ) : array();
		}
		// Production: dns_get_record(DNS_AAAA) returns array of records,
		// each with an 'ipv6' key. Some hosts/resolvers misbehave — fail
		// closed by treating any non-array result as "no v6 found".
		$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS lookup may emit warnings on transient failure; we treat any failure as "no AAAA".
		if ( ! is_array( $records ) ) {
			return array();
		}
		$out = array();
		foreach ( $records as $rec ) {
			if ( isset( $rec['ipv6'] ) && '' !== $rec['ipv6'] ) {
				$out[] = (string) $rec['ipv6'];
			}
		}
		return $out;
	}

	/**
	 * Returns a short label naming the forbidden class an IPv4 falls into,
	 * or null if the address is OK to talk to.
	 *
	 * @param string $ip IPv4 address.
	 * @return string|null
	 */
	private static function classify_ipv4( string $ip ): ?string {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			// Not a parseable v4 — refuse to guess.
			return 'invalid';
		}

		// 255.255.255.255 — limited broadcast.
		if ( '255.255.255.255' === $ip ) {
			return 'broadcast';
		}

		$long = ip2long( $ip );
		if ( false === $long ) {
			return 'invalid';
		}

		foreach ( self::IPV4_DENY as $entry ) {
			list( $net, $prefix ) = $entry;
			$net_long             = ip2long( $net );
			if ( false === $net_long ) {
				continue;
			}
			// Mask of `prefix` leading bits. PHP 8.1+ is 64-bit on every
			// reasonable host, so a plain shift + truncation works.
			$mask = 0 === $prefix ? 0 : ( -1 << ( 32 - $prefix ) ) & 0xFFFFFFFF;
			if ( ( $long & $mask ) === ( $net_long & $mask ) ) {
				return self::label_for_ipv4_net( $net );
			}
		}

		return null;
	}

	/**
	 * Returns a human-readable class label for an IPv4 deny-list network.
	 *
	 * Used so the WP_Error message can name WHY the address was refused
	 * without echoing the resolved IP back (info leak).
	 *
	 * @param string $net Network base from IPV4_DENY.
	 * @return string
	 */
	private static function label_for_ipv4_net( string $net ): string {
		switch ( $net ) {
			case '127.0.0.0':
				return 'loopback';
			case '10.0.0.0':
			case '172.16.0.0':
			case '192.168.0.0':
				return 'private';
			case '169.254.0.0':
				return 'link-local';
			case '100.64.0.0':
				return 'carrier-grade-nat';
			case '224.0.0.0':
				return 'multicast';
			case '240.0.0.0':
				return 'reserved';
			case '0.0.0.0':
				return 'this-network';
			case '192.0.0.0':
			case '192.0.2.0':
			case '198.51.100.0':
			case '203.0.113.0':
				return 'documentation';
			case '198.18.0.0':
				return 'benchmark';
			default:
				return 'reserved';
		}
	}

	/**
	 * Returns a short label naming the forbidden class an IPv6 falls into,
	 * or null if the address is OK to talk to.
	 *
	 * Compares leading bytes via inet_pton + bin2hex. Also unwraps IPv4-
	 * mapped IPv6 (::ffff:0:0/96) and re-runs the v4 classifier on the
	 * embedded address.
	 *
	 * @param string $ip IPv6 address.
	 * @return string|null
	 */
	private static function classify_ipv6( string $ip ): ?string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton emits an E_WARNING on malformed input; we deliberately suppress and check the false return on the next line.
		$packed = @inet_pton( $ip );
		if ( false === $packed || strlen( $packed ) !== 16 ) {
			return 'invalid';
		}
		$hex = bin2hex( $packed );

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- "::1" and "::" in the next comment are IPv6 literals, not commented-out code.
		// IPv6 ::1 (loopback) and :: (unspecified).
		if ( '00000000000000000000000000000001' === $hex ) {
			return 'loopback';
		}
		if ( '00000000000000000000000000000000' === $hex ) {
			return 'unspecified';
		}

		// IPv4-mapped IPv6 ::ffff:0:0/96 — unwrap and re-check the embedded v4.
		if ( str_starts_with( $hex, '00000000000000000000ffff' ) ) {
			$v4_hex = substr( $hex, 24 );
			$octets = array(
				hexdec( substr( $v4_hex, 0, 2 ) ),
				hexdec( substr( $v4_hex, 2, 2 ) ),
				hexdec( substr( $v4_hex, 4, 2 ) ),
				hexdec( substr( $v4_hex, 6, 2 ) ),
			);
			$v4     = implode( '.', $octets );
			$nested = self::classify_ipv4( $v4 );
			if ( null !== $nested ) {
				return 'ipv4-mapped-' . $nested;
			}
			// IPv4-mapped form pointing at a public address is itself
			// suspicious (an attacker forcing v4 through a v6 path) —
			// reject as a defensive default.
			return 'ipv4-mapped';
		}

		// fc00::/7 — Unique Local Address (ULA). First byte is 0xfc or 0xfd.
		$first_byte = hexdec( substr( $hex, 0, 2 ) );
		if ( ( $first_byte & 0xFE ) === 0xFC ) {
			return 'unique-local';
		}

		// fe80::/10 — link-local. First 10 bits are 1111111010.
		// In hex: first byte 0xfe, second byte's top 2 bits are 10.
		if ( 0xFE === $first_byte ) {
			$second_byte = hexdec( substr( $hex, 2, 2 ) );
			if ( ( $second_byte & 0xC0 ) === 0x80 ) {
				return 'link-local';
			}
		}

		// ff00::/8 — multicast.
		if ( 0xFF === $first_byte ) {
			return 'multicast';
		}

		// 2001:db8::/32 — documentation.
		if ( str_starts_with( $hex, '20010db8' ) ) {
			return 'documentation';
		}

		// 64:ff9b::/96 — NAT64 well-known prefix.
		if ( str_starts_with( $hex, '0064ff9b00000000000000000000' ) ) {
			return 'nat64';
		}

		return null;
	}
}
