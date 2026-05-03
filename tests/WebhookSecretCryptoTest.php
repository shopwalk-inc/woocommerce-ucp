<?php
/**
 * Tests for UCP_Webhook_Secret_Crypto — F-D-5 (encrypt webhook HMAC
 * secrets at rest).
 *
 * Covers round-trip, IV freshness, tamper detection (corrupted
 * ciphertext), the lazy-migration path for legacy plaintext secrets, and
 * key stability across calls.
 *
 * @package WooCommerceUCP
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );

require_once __DIR__ . '/stubs/wp_rest_stubs.php';
require_once __DIR__ . '/../includes/core/class-ucp-webhook-secret-crypto.php';

final class WebhookSecretCryptoTest extends TestCase {

	/**
	 * Backing store for stubbed WP options.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

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

		// In-memory $wpdb so the lazy-migration helper's update() call
		// doesn't fatal when class_exists() check passes.
		global $wpdb;
		$wpdb = new MigrationWpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Round-trip ───────────────────────────────────────────────────────

	public function test_encrypt_then_decrypt_yields_original_plaintext(): void {
		$plaintext = 'hmac_secret_' . str_repeat( 'a', 60 );

		$blob = UCP_Webhook_Secret_Crypto::encrypt( $plaintext );
		$this->assertNotSame( '', $blob );

		$decrypted = UCP_Webhook_Secret_Crypto::decrypt( $blob );
		$this->assertSame( $plaintext, $decrypted );
	}

	public function test_encrypt_empty_returns_empty(): void {
		$this->assertSame( '', UCP_Webhook_Secret_Crypto::encrypt( '' ) );
	}

	public function test_decrypt_empty_returns_empty(): void {
		$this->assertSame( '', UCP_Webhook_Secret_Crypto::decrypt( '' ) );
	}

	// ── IV freshness ─────────────────────────────────────────────────────

	public function test_two_encryptions_of_same_plaintext_produce_different_ciphertexts(): void {
		$plaintext = 'same_plaintext_input';

		$first  = UCP_Webhook_Secret_Crypto::encrypt( $plaintext );
		$second = UCP_Webhook_Secret_Crypto::encrypt( $plaintext );

		$this->assertNotSame( '', $first );
		$this->assertNotSame( '', $second );
		$this->assertNotSame( $first, $second, 'Each encryption must use a fresh random IV.' );

		// Both must still round-trip to the same plaintext.
		$this->assertSame( $plaintext, UCP_Webhook_Secret_Crypto::decrypt( $first ) );
		$this->assertSame( $plaintext, UCP_Webhook_Secret_Crypto::decrypt( $second ) );
	}

	// ── Tamper / corruption ──────────────────────────────────────────────

	public function test_decrypt_of_corrupted_ciphertext_returns_empty_no_exception(): void {
		$plaintext = 'pristine_secret';
		$blob      = UCP_Webhook_Secret_Crypto::encrypt( $plaintext );
		$this->assertNotSame( '', $blob );

		// Flip a byte in the middle of the ciphertext (after IV+tag).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw                              = base64_decode( $blob, true );
		$raw[ strlen( $raw ) - 1 ]        = chr( ( ord( $raw[ strlen( $raw ) - 1 ] ) + 1 ) % 256 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$tampered                         = base64_encode( $raw );

		$this->assertSame( '', UCP_Webhook_Secret_Crypto::decrypt( $tampered ) );
	}

	public function test_decrypt_of_garbage_string_returns_empty(): void {
		$this->assertSame( '', UCP_Webhook_Secret_Crypto::decrypt( '!!!not-base64-or-anything!!!' ) );
	}

	public function test_decrypt_of_short_blob_returns_empty(): void {
		// A valid base64 string but too short to contain IV + tag + 1 byte.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$short = base64_encode( random_bytes( 8 ) );
		$this->assertSame( '', UCP_Webhook_Secret_Crypto::decrypt( $short ) );
	}

	// ── Lazy migration (F-D-5 rollout path) ──────────────────────────────

	public function test_decrypt_or_migrate_returns_legacy_plaintext_unchanged(): void {
		// Pre-migration row in the DB: secret column holds a raw plaintext
		// secret. decrypt() returns empty (it's not valid base64+GCM), the
		// helper falls back to treating the value as plaintext and hands
		// it back so signing can proceed.
		$legacy = 'legacy_plaintext_hmac_secret_xyz';

		$result = UCP_Webhook_Secret_Crypto::decrypt_or_migrate( 'wh_legacy_1', $legacy );

		$this->assertSame( $legacy, $result );
	}

	public function test_decrypt_or_migrate_passes_through_encrypted_blob(): void {
		$plaintext = 'fresh_secret';
		$blob      = UCP_Webhook_Secret_Crypto::encrypt( $plaintext );
		$this->assertNotSame( '', $blob );

		$result = UCP_Webhook_Secret_Crypto::decrypt_or_migrate( 'wh_new_1', $blob );

		$this->assertSame( $plaintext, $result );
	}

	// ── Key stability ────────────────────────────────────────────────────

	public function test_key_is_stable_across_calls(): void {
		// Encrypt twice; both blobs must decrypt with the same key.
		$plaintext = 'key_stability_check';

		$first  = UCP_Webhook_Secret_Crypto::encrypt( $plaintext );
		$second = UCP_Webhook_Secret_Crypto::encrypt( $plaintext );

		$this->assertSame( $plaintext, UCP_Webhook_Secret_Crypto::decrypt( $first ) );
		$this->assertSame( $plaintext, UCP_Webhook_Secret_Crypto::decrypt( $second ) );

		// And the option store must hold exactly one persisted key.
		$this->assertArrayHasKey( 'shopwalk_ucp_webhook_secret_key', $this->options );
		$this->assertNotEmpty( $this->options['shopwalk_ucp_webhook_secret_key'] );
	}

	public function test_key_persisted_once_then_reused(): void {
		// Trigger key generation.
		UCP_Webhook_Secret_Crypto::encrypt( 'first' );
		$persisted = $this->options['shopwalk_ucp_webhook_secret_key'] ?? null;
		$this->assertNotNull( $persisted );

		// Subsequent calls must reuse the same persisted key — overwrite
		// it with a known value and confirm encrypt() still uses what's
		// in the option, not a fresh random key.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->options['shopwalk_ucp_webhook_secret_key'] = base64_encode( str_repeat( 'X', 32 ) );

		$blob_with_known_key = UCP_Webhook_Secret_Crypto::encrypt( 'roundtrip' );
		$this->assertSame( 'roundtrip', UCP_Webhook_Secret_Crypto::decrypt( $blob_with_known_key ) );

		// The option value did not get rewritten.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( base64_encode( str_repeat( 'X', 32 ) ), $this->options['shopwalk_ucp_webhook_secret_key'] );
	}
}

/**
 * In-memory $wpdb stub. Only needs to be a class with an update() method
 * — the lazy-migration helper checks class_exists('UCP_Storage') AND
 * isset($wpdb) before calling update(). UCP_Storage is intentionally NOT
 * declared in this file so the migration update is a no-op here (we
 * verify the migration *return value*, not the DB write — the DB write
 * is best-effort).
 */
final class MigrationWpdb { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	public string $prefix = 'wp_';
	public function update( string $table, array $data, array $where ): int {
		return 1;
	}
	public function prepare( string $query, ...$args ): string {
		return $query;
	}
}
