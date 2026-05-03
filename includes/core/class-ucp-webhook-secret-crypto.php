<?php
/**
 * UCP Webhook Secret Crypto — at-rest encryption for webhook HMAC secrets.
 *
 * Webhook subscriptions store a per-subscription HMAC secret used by the
 * delivery worker to sign outbound payloads. The signing path needs the
 * plaintext, so we cannot one-way-hash the value (as we do for OAuth
 * client secrets). Instead we encrypt-at-rest with AES-256-GCM keyed on a
 * per-install random key and decrypt to plaintext only briefly in process
 * memory at delivery time.
 *
 * Threat model: a read-only DB compromise (SQLi elsewhere, backup leak,
 * replica access) yields ciphertext without the encryption key, which
 * lives in a separate `wp_options` row (not derived from the ciphertext).
 *
 * Stored format: base64( iv (12) || tag (16) || ciphertext ) — the IV is
 * 12 bytes per the AES-GCM spec recommendation, the auth tag prevents
 * tamper. Each encrypt() call generates a fresh random IV so two
 * encryptions of the same plaintext produce different ciphertexts.
 *
 * Migration: existing subscriptions in the DB hold plaintext secrets.
 * Lazy migration is done at the call site (see
 * UCP_Webhook_Delivery::deliver_one) — if decrypt() returns empty, the
 * stored value is treated as plaintext, encrypted in place, and used.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Webhook_Secret_Crypto — encrypt/decrypt webhook HMAC secrets at rest.
 */
final class UCP_Webhook_Secret_Crypto {

	/**
	 * Option name for the per-install AES-256-GCM key (raw 32 bytes).
	 *
	 * The option is autoload=false so it is only read on demand at delivery
	 * or subscribe time, not on every page load.
	 */
	private const KEY_OPTION = 'shopwalk_ucp_webhook_secret_key';

	/**
	 * AES-GCM IV length per NIST SP 800-38D recommendation.
	 */
	private const IV_LEN = 12;

	/**
	 * AES-GCM auth tag length (max 16 bytes).
	 */
	private const TAG_LEN = 16;

	/**
	 * Encrypt a plaintext secret for at-rest storage.
	 *
	 * Returns base64( iv || tag || ciphertext ). On any failure (key
	 * unavailable, openssl error) returns the empty string so the caller
	 * can refuse to store rather than persist plaintext.
	 *
	 * @param string $plaintext The plaintext HMAC secret.
	 * @return string Base64 blob, or empty string on failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		$key = self::key();
		if ( '' === $key ) {
			return '';
		}
		try {
			$iv = random_bytes( self::IV_LEN );
		} catch ( \Exception $e ) {
			return '';
		}
		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LEN
		);
		if ( false === $ciphertext ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required to make raw IV/tag/ciphertext fit a VARCHAR column.
		return base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt an at-rest blob produced by encrypt(). Returns the empty
	 * string on any failure (corrupted blob, wrong key, tag mismatch, or
	 * the value is actually a legacy plaintext secret) — the caller is
	 * expected to treat empty as "decrypt failed" and decide how to react
	 * (lazy migration, error log, etc).
	 *
	 * @param string $stored Base64 blob from encrypt() or a legacy plaintext.
	 * @return string Decrypted plaintext, or empty string on failure.
	 */
	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reverse of encrypt(); strict mode rejects non-b64 (legacy plaintext) input.
		$raw = base64_decode( $stored, true );
		if ( false === $raw || strlen( $raw ) < self::IV_LEN + self::TAG_LEN + 1 ) {
			return '';
		}
		$key = self::key();
		if ( '' === $key ) {
			return '';
		}
		$iv         = substr( $raw, 0, self::IV_LEN );
		$tag        = substr( $raw, self::IV_LEN, self::TAG_LEN );
		$ciphertext = substr( $raw, self::IV_LEN + self::TAG_LEN );
		$plaintext  = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);
		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Lazy migration helper: decrypt a stored value, falling back to
	 * treating the value as legacy plaintext if decrypt fails. When the
	 * fallback path fires this also re-encrypts the secret into the
	 * subscriptions table so the next read goes through the encrypted
	 * branch — pre-existing rows from before F-D-5 carry plaintext.
	 *
	 * Returns the plaintext secret (which may be empty if the value is
	 * genuinely corrupted — neither valid ciphertext nor a usable
	 * plaintext).
	 *
	 * @param string $subscription_id Row id, used by the in-place rewrite.
	 * @param string $stored          The raw value from the secret column.
	 * @return string Plaintext HMAC secret, or empty string on full failure.
	 */
	public static function decrypt_or_migrate( string $subscription_id, string $stored ): string {
		$plain = self::decrypt( $stored );
		if ( '' !== $plain ) {
			return $plain;
		}
		// Lazy migration: assume the stored value is a legacy plaintext
		// secret left in the DB before F-D-5 landed. Re-encrypt it in
		// place so subsequent reads go through the normal decrypt path.
		// If encryption itself fails (no key, openssl error) we still
		// return the plaintext for this delivery — the next attempt will
		// retry the migration.
		$reencrypted = self::encrypt( $stored );
		if ( '' !== $reencrypted && '' !== $subscription_id && class_exists( 'UCP_Storage' ) ) {
			global $wpdb;
			if ( isset( $wpdb ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					UCP_Storage::table( 'webhook_subscriptions' ),
					array( 'secret' => $reencrypted ),
					array( 'id' => $subscription_id )
				);
			}
		}
		return $stored;
	}

	/**
	 * Get-or-create the install-wide AES-256 key (raw 32 bytes).
	 *
	 * Stored as a base64 string in a non-autoloaded option so it doesn't
	 * land in the default options cache on every request. Generated lazily
	 * with random_bytes(32) on first need; on a CSPRNG failure returns the
	 * empty string and callers refuse to encrypt/decrypt.
	 *
	 * Intentionally not derived from wp_salt() alone — site admins can
	 * rotate auth salts without expecting webhook secrets to break, and a
	 * rotation would silently corrupt every stored secret. The salt is
	 * mixed in (HKDF-style via hash_hmac) when generating the key on first
	 * call, but the persisted key is independent of future salt rotations.
	 *
	 * @return string Raw 32-byte key, or empty string on failure.
	 */
	private static function key(): string {
		$stored = function_exists( 'get_option' ) ? (string) get_option( self::KEY_OPTION, '' ) : '';
		if ( '' !== $stored ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Reverse of stored encoding.
			$key = base64_decode( $stored, true );
			if ( false !== $key && 32 === strlen( $key ) ) {
				return $key;
			}
		}
		try {
			$seed = random_bytes( 32 );
		} catch ( \Exception $e ) {
			return '';
		}
		// Mix in wp_salt('auth') if available so two installs with leaked
		// random_bytes streams still differ. The persisted key does NOT
		// re-derive from the salt on subsequent reads — we store the
		// post-mix bytes — so a salt rotation later is harmless.
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt( 'auth' ) : '';
		$key  = hash_hmac( 'sha256', $seed, $salt, true );
		if ( function_exists( 'update_option' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Persisting raw key bytes in a string column.
			update_option( self::KEY_OPTION, base64_encode( $key ), false );
		}
		return $key;
	}
}
