<?php
/**
 * UCP OAuth Client registry — CRUD on the wp_ucp_oauth_clients table.
 *
 * Agents register themselves with the store either:
 *   1. Automatically via UCP discovery doc cross-fetch (Phase 1) — when
 *      an agent's UCP profile is verified, the store creates an OAuth
 *      client record with the agent's redirect URIs and signing keys.
 *   2. Manually via WP-CLI for development/testing.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_OAuth_Clients — registered OAuth client records.
 */
final class UCP_OAuth_Clients {

	/**
	 * Register a new OAuth client. Returns the client_id + plaintext
	 * client_secret on first creation; subsequent calls with the same
	 * ucp_profile_url update redirect_uris/scopes but do NOT return the
	 * existing secret (rotate via DELETE + create if needed).
	 *
	 * @param array{
	 *     name:string,
	 *     redirect_uris:array<int,string>,
	 *     scopes_allowed?:array<int,string>,
	 *     ucp_profile_url?:string,
	 *     signing_jwk?:string,
	 * } $args Client metadata.
	 * @return array{client_id:string, client_secret:?string} Plaintext secret only on first create.
	 */
	public static function register( array $args ): array {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_clients' );

		$now             = current_time( 'mysql', true );
		$ucp_profile_url = (string) ( $args['ucp_profile_url'] ?? '' );

		// If a client already exists for this UCP profile, update it.
		if ( '' !== $ucp_profile_url ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT client_id FROM {$table} WHERE ucp_profile_url = %s LIMIT 1",
					$ucp_profile_url
				)
			);
			if ( $existing ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array(
						'name'           => (string) $args['name'],
						'redirect_uris'  => wp_json_encode( $args['redirect_uris'] ),
						'scopes_allowed' => wp_json_encode( $args['scopes_allowed'] ?? array( 'ucp:checkout', 'ucp:orders', 'ucp:webhooks' ) ),
						'signing_jwk'    => (string) ( $args['signing_jwk'] ?? '' ),
						'updated_at'     => $now,
					),
					array( 'client_id' => $existing->client_id )
				);
				return array(
					'client_id'     => $existing->client_id,
					'client_secret' => null,
				);
			}
		}

		// New client.
		$client_id     = self::generate_id( 'agt_' );
		$client_secret = self::generate_secret();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'client_id'       => $client_id,
				'client_secret'   => password_hash( $client_secret, PASSWORD_BCRYPT ),
				'name'            => (string) $args['name'],
				'redirect_uris'   => wp_json_encode( $args['redirect_uris'] ),
				'scopes_allowed'  => wp_json_encode( $args['scopes_allowed'] ?? array( 'ucp:checkout', 'ucp:orders', 'ucp:webhooks' ) ),
				'signing_jwk'     => (string) ( $args['signing_jwk'] ?? '' ),
				'ucp_profile_url' => $ucp_profile_url,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}

	/**
	 * Look up a client by client_id.
	 *
	 * @param string $client_id The opaque client identifier.
	 * @return array<string,mixed>|null
	 */
	public static function find( string $client_id ): ?array {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE client_id = %s LIMIT 1",
				$client_id
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Verify a client_id + client_secret pair against the bcrypt hash.
	 *
	 * @param string $client_id    The opaque client identifier.
	 * @param string $client_secret The plaintext secret to check.
	 * @return bool
	 */
	public static function verify_secret( string $client_id, string $client_secret ): bool {
		$row = self::find( $client_id );
		if ( ! $row ) {
			return false;
		}
		return password_verify( $client_secret, (string) $row['client_secret'] );
	}

	/**
	 * Rotate a client's secret. Generates a new secret and replaces the hash.
	 * The old secret is immediately invalid.
	 *
	 * @param string $client_id The opaque client identifier.
	 * @return array{client_secret:string}|WP_Error
	 */
	public static function rotate_secret( string $client_id ) {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_clients' );

		$row = self::find( $client_id );
		if ( ! $row ) {
			return new WP_Error( 'not_found', "Client '{$client_id}' not found" );
		}

		$new_secret = self::generate_secret();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'client_secret' => password_hash( $new_secret, PASSWORD_BCRYPT ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'client_id' => $client_id )
		);

		return array( 'client_secret' => $new_secret );
	}

	/**
	 * Whether the given redirect URI is registered for this client.
	 *
	 * @param array  $client Client row from find().
	 * @param string $uri    The redirect_uri to validate.
	 * @return bool
	 */
	public static function is_valid_redirect_uri( array $client, string $uri ): bool {
		$registered = json_decode( (string) $client['redirect_uris'], true );
		if ( ! is_array( $registered ) ) {
			return false;
		}
		foreach ( $registered as $candidate ) {
			if ( hash_equals( (string) $candidate, $uri ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generate an opaque ID with the given prefix.
	 *
	 * @param string $prefix e.g. "agt_", "tok_", "chk_", "wh_".
	 * @return string
	 */
	public static function generate_id( string $prefix ): string {
		try {
			return $prefix . bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			return $prefix . wp_generate_password( 32, false, false );
		}
	}

	/**
	 * Generate a high-entropy secret string.
	 *
	 * @return string
	 */
	public static function generate_secret(): string {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $e ) {
			return wp_generate_password( 64, false, false );
		}
	}
}
