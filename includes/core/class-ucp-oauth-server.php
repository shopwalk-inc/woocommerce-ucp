<?php
/**
 * UCP OAuth 2.0 Server — authorize / token / revoke / userinfo.
 *
 * Implements the authorization_code grant with the WordPress user account
 * as the buyer identity (which is also the WooCommerce customer record).
 * Refresh tokens are long-lived (30 days), access tokens are short (1 hour).
 *
 * Token storage: tokens are stored hashed with bcrypt in
 * wp_ucp_oauth_tokens. The plaintext token is only ever returned to the
 * agent on issuance — subsequent verifications hash the candidate and
 * compare against the stored hash.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_OAuth_Server — REST endpoints + token issuance/validation.
 */
final class UCP_OAuth_Server {

	/**
	 * Access token TTL in seconds.
	 */
	private const ACCESS_TTL = 3600;

	/**
	 * Refresh token TTL in seconds.
	 */
	private const REFRESH_TTL = 2592000; // 30 days

	/**
	 * Authorization code TTL in seconds.
	 */
	private const CODE_TTL = 600; // 10 min

	/**
	 * Register all OAuth REST routes under the UCP namespace.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/oauth/authorize',
			array(
				array(
					'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
					'callback'            => array( __CLASS__, 'handle_authorize' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			UCP_REST_NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			UCP_REST_NAMESPACE,
			'/oauth/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_revoke' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			UCP_REST_NAMESPACE,
			'/oauth/userinfo',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_userinfo' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// ── /authorize — interactive auth-code grant ─────────────────────────

	/**
	 * Authorization endpoint. The buyer must be logged into a WordPress
	 * user account; if not, this returns an HTML login redirect to
	 * `wp-login.php` with a return URL back to this endpoint.
	 *
	 * For Phase 1 the consent step is implicit — if the agent's redirect
	 * URI matches a registered URI on the agent's client record, the
	 * authorization code is minted directly. A real consent screen with
	 * scope confirmation lives in Phase 2 work.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_authorize( WP_REST_Request $request ) {
		$client_id     = (string) $request->get_param( 'client_id' );
		$redirect_uri  = (string) $request->get_param( 'redirect_uri' );
		$state         = (string) $request->get_param( 'state' );
		$scope         = (string) $request->get_param( 'scope' );
		$response_type = (string) $request->get_param( 'response_type' );

		if ( 'code' !== $response_type ) {
			return new WP_Error( 'unsupported_response_type', 'Only response_type=code is supported', array( 'status' => 400 ) );
		}

		$client = UCP_OAuth_Clients::find( $client_id );
		if ( ! $client ) {
			return new WP_Error( 'invalid_client', 'Unknown client_id', array( 'status' => 400 ) );
		}
		if ( ! UCP_OAuth_Clients::is_valid_redirect_uri( $client, $redirect_uri ) ) {
			return new WP_Error( 'invalid_redirect_uri', 'redirect_uri not registered for this client', array( 'status' => 400 ) );
		}

		// Buyer must be authenticated.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$login_url = wp_login_url( rest_url( UCP_REST_NAMESPACE . '/oauth/authorize' ) . '?' . http_build_query( $request->get_query_params() ) );
			return new WP_REST_Response(
				array(
					'login_required' => true,
					'login_url'      => $login_url,
				),
				401
			);
		}

		// PKCE — store code_challenge if provided (RFC 7636).
		$code_challenge        = (string) $request->get_param( 'code_challenge' );
		$code_challenge_method = (string) $request->get_param( 'code_challenge_method' );
		if ( '' !== $code_challenge && '' === $code_challenge_method ) {
			$code_challenge_method = 'plain'; // RFC 7636 §4.3 default
		}
		if ( '' !== $code_challenge_method && 'S256' !== $code_challenge_method && 'plain' !== $code_challenge_method ) {
			return new WP_Error( 'invalid_request', 'code_challenge_method must be S256 or plain', array( 'status' => 400 ) );
		}

		// Mint an authorization_code (10 min TTL).
		$code = self::issue_token(
			'authorization_code',
			$client_id,
			$user_id,
			'' !== $scope ? explode( ' ', $scope ) : array( 'ucp:checkout', 'ucp:orders' ),
			self::CODE_TTL,
			$code_challenge,
			$code_challenge_method
		);

		// Redirect back to the agent with code + state in the query string.
		$separator = str_contains( $redirect_uri, '?' ) ? '&' : '?';
		$location  = $redirect_uri . $separator . http_build_query(
			array(
				'code'  => $code['plaintext'],
				'state' => $state,
			)
		);
		return new WP_REST_Response( array( 'redirect_to' => $location ), 302, array( 'Location' => $location ) );
	}

	// ── /token — exchange code or refresh token for access token ─────────

	/**
	 * Token endpoint. Supports `authorization_code` and `refresh_token`
	 * grant types per RFC 6749. Client credentials are passed in the
	 * request body (Basic auth header support is a Phase 2 follow-up).
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_token( WP_REST_Request $request ) {
		$grant_type    = (string) $request->get_param( 'grant_type' );
		$client_id     = (string) $request->get_param( 'client_id' );
		$client_secret = (string) $request->get_param( 'client_secret' );

		if ( ! UCP_OAuth_Clients::verify_secret( $client_id, $client_secret ) ) {
			return new WP_Error( 'invalid_client', 'Bad client credentials', array( 'status' => 401 ) );
		}

		switch ( $grant_type ) {
			case 'authorization_code':
				return self::exchange_authorization_code( $request, $client_id );
			case 'refresh_token':
				return self::exchange_refresh_token( $request, $client_id );
		}
		return new WP_Error( 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported', array( 'status' => 400 ) );
	}

	/**
	 * authorization_code grant exchange.
	 *
	 * @param WP_REST_Request $request   The token request.
	 * @param string          $client_id The validated client.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function exchange_authorization_code( WP_REST_Request $request, string $client_id ) {
		$code = (string) $request->get_param( 'code' );
		$row  = self::lookup_token( $code, 'authorization_code' );
		if ( ! $row || $row['client_id'] !== $client_id ) {
			return new WP_Error( 'invalid_grant', 'Invalid or expired authorization code', array( 'status' => 400 ) );
		}

		// PKCE verification (RFC 7636) — if a code_challenge was stored,
		// the token request MUST include a matching code_verifier.
		$stored_challenge = $row['code_challenge'] ?? '';
		$stored_method    = $row['code_challenge_method'] ?? '';
		if ( '' !== $stored_challenge ) {
			$code_verifier = (string) $request->get_param( 'code_verifier' );
			if ( '' === $code_verifier ) {
				return new WP_Error( 'invalid_grant', 'code_verifier required (PKCE)', array( 'status' => 400 ) );
			}
			if ( 'S256' === $stored_method ) {
				$computed = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for PKCE S256 code challenge per RFC 7636.
			} else {
				$computed = $code_verifier; // plain
			}
			if ( ! hash_equals( $stored_challenge, $computed ) ) {
				self::revoke_token( (int) $row['id'] );
				return new WP_Error( 'invalid_grant', 'PKCE verification failed', array( 'status' => 400 ) );
			}
		}

		// One-time use — revoke the code immediately.
		self::revoke_token( (int) $row['id'] );

		$scopes  = json_decode( (string) $row['scopes'], true ) ?: array();
		$user_id = (int) $row['user_id'];

		$access  = self::issue_token( 'access', $client_id, $user_id, $scopes, self::ACCESS_TTL );
		$refresh = self::issue_token( 'refresh', $client_id, $user_id, $scopes, self::REFRESH_TTL );

		return new WP_REST_Response(
			array(
				'access_token'  => $access['plaintext'],
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $refresh['plaintext'],
				'scope'         => implode( ' ', $scopes ),
			),
			200
		);
	}

	/**
	 * refresh_token grant exchange.
	 *
	 * @param WP_REST_Request $request   The token request.
	 * @param string          $client_id The validated client.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function exchange_refresh_token( WP_REST_Request $request, string $client_id ) {
		$refresh = (string) $request->get_param( 'refresh_token' );
		$row     = self::lookup_token( $refresh, 'refresh' );
		if ( ! $row || $row['client_id'] !== $client_id ) {
			return new WP_Error( 'invalid_grant', 'Invalid or expired refresh token', array( 'status' => 400 ) );
		}

		$scopes  = json_decode( (string) $row['scopes'], true ) ?: array();
		$user_id = (int) $row['user_id'];

		$access = self::issue_token( 'access', $client_id, $user_id, $scopes, self::ACCESS_TTL );

		return new WP_REST_Response(
			array(
				'access_token' => $access['plaintext'],
				'token_type'   => 'Bearer',
				'expires_in'   => self::ACCESS_TTL,
				'scope'        => implode( ' ', $scopes ),
			),
			200
		);
	}

	// ── /revoke — RFC 7009 ───────────────────────────────────────────────

	/**
	 * Token revocation endpoint per RFC 7009. Always returns 200 even when
	 * the token is unknown — this is required by the spec to prevent
	 * client enumeration of tokens.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_revoke( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'token' );
		if ( '' !== $token ) {
			$row = self::lookup_token_any_type( $token );
			if ( $row ) {
				self::revoke_token( (int) $row['id'] );
			}
		}
		return new WP_REST_Response( array(), 200 );
	}

	// ── /userinfo — OIDC ─────────────────────────────────────────────────

	/**
	 * OIDC userinfo endpoint — returns the WooCommerce customer linked to
	 * the access token.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_userinfo( WP_REST_Request $request ) {
		$ctx = self::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$user = get_userdata( $ctx['user_id'] );
		if ( ! $user ) {
			return new WP_Error( 'unknown_user', 'No such user', array( 'status' => 404 ) );
		}
		return new WP_REST_Response(
			array(
				'sub'                => (string) $user->ID,
				'email'              => (string) $user->user_email,
				'name'               => (string) $user->display_name,
				'preferred_username' => (string) $user->user_login,
			),
			200
		);
	}

	// ── Token issuance + lookup ──────────────────────────────────────────

	/**
	 * Issue a fresh token of the given type. Returns the plaintext token
	 * to the caller — the table stores the bcrypt hash.
	 *
	 * @param string $type      'access' | 'refresh' | 'authorization_code'.
	 * @param string $client_id Owning client.
	 * @param int    $user_id   Owning user (WP/WC customer).
	 * @param array  $scopes    Granted scopes.
	 * @param int    $ttl       TTL in seconds.
	 * @return array{plaintext:string, hash:string}
	 */
	public static function issue_token( string $type, string $client_id, int $user_id, array $scopes, int $ttl, string $code_challenge = '', string $code_challenge_method = '' ): array {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_tokens' );

		$prefix = match ( $type ) {
			'authorization_code' => 'cod_',
			'refresh'            => 'rt_',
			default              => 'at_',
		};
		try {
			$plaintext = $prefix . bin2hex( random_bytes( 24 ) );
		} catch ( \Exception $e ) {
			$plaintext = $prefix . wp_generate_password( 48, false, false );
		}
		$hash = password_hash( $plaintext, PASSWORD_BCRYPT );

		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$row = array(
			'token_type' => $type,
			'token_hash' => $hash,
			'client_id'  => $client_id,
			'user_id'    => $user_id,
			'scopes'     => wp_json_encode( $scopes ),
			'expires_at' => $expires,
			'created_at' => $now,
		);
		// PKCE: store challenge alongside the authorization code
		if ( '' !== $code_challenge ) {
			$row['code_challenge']        = $code_challenge;
			$row['code_challenge_method'] = $code_challenge_method;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert( $table, $row );
		return array(
			'plaintext' => $plaintext,
			'hash'      => $hash,
		);
	}

	/**
	 * Look up a token by plaintext + type. Verifies the bcrypt hash and
	 * returns the row only if non-expired and non-revoked.
	 *
	 * @param string $plaintext The token from the wire.
	 * @param string $type      Required token type.
	 * @return array<string,mixed>|null
	 */
	public static function lookup_token( string $plaintext, string $type ): ?array {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_tokens' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE token_type = %s AND revoked_at IS NULL AND expires_at > %s",
				$type,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return null;
		}
		foreach ( $rows as $row ) {
			if ( password_verify( $plaintext, (string) $row['token_hash'] ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Look up a token of any type — used by /revoke which doesn't know
	 * the type up front.
	 *
	 * @param string $plaintext The token from the wire.
	 * @return array<string,mixed>|null
	 */
	private static function lookup_token_any_type( string $plaintext ): ?array {
		foreach ( array( 'access', 'refresh', 'authorization_code' ) as $type ) {
			$row = self::lookup_token( $plaintext, $type );
			if ( $row ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Mark a token row as revoked.
	 *
	 * @param int $id Token row id.
	 * @return void
	 */
	public static function revoke_token( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			UCP_Storage::table( 'oauth_tokens' ),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id )
		);
	}

	// ── Authentication helper for resource endpoints ────────────────────

	/**
	 * Verify a Bearer access token and return the (client_id, user_id, scopes)
	 * triple. Returns a WP_Error on failure suitable for direct return from
	 * a resource handler.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return array{client_id:string, user_id:int, scopes:array<int,string>}|WP_Error
	 */
	public static function authenticate_request( WP_REST_Request $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header || ! str_starts_with( $header, 'Bearer ' ) ) {
			return new WP_Error( 'unauthorized', 'Bearer token required', array( 'status' => 401 ) );
		}
		$token = substr( $header, 7 );
		$row   = self::lookup_token( $token, 'access' );
		if ( ! $row ) {
			return new WP_Error( 'invalid_token', 'Token not recognized or expired', array( 'status' => 401 ) );
		}
		$scopes = json_decode( (string) $row['scopes'], true ) ?: array();
		return array(
			'client_id' => (string) $row['client_id'],
			'user_id'   => (int) $row['user_id'],
			'scopes'    => $scopes,
		);
	}
}
