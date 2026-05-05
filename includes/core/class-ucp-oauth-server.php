<?php
/**
 * UCP OAuth 2.0 Server — authorize / token / revoke / userinfo / consent.
 *
 * Implements the authorization_code grant with the WordPress user account
 * as the buyer identity (which is also the WooCommerce customer record).
 * Refresh tokens are long-lived (30 days), access tokens are short (1 hour).
 *
 * Token storage (F-C-3): tokens are stored as HMAC-SHA256 of the plaintext
 * keyed by a per-install pepper (WP option `shopwalk_ucp_oauth_token_pepper`).
 * The HMAC is deterministic per token, so the unique `token_hash` index is
 * actually useful — lookup is O(1). Constant-time `hash_equals()` confirm
 * runs after the indexed lookup as defense in depth. Legacy bcrypt rows from
 * before this fix landed are migrated lazily on first lookup.
 *
 * @package ShopwalkWooCommerce
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
	 * Per-client token-endpoint failed-attempt budget (F-C-7).
	 */
	private const TOKEN_RATE_LIMIT_MAX    = 10;
	private const TOKEN_RATE_LIMIT_WINDOW = 60; // seconds

	/**
	 * WP option key for the per-install token pepper (F-C-3).
	 */
	private const PEPPER_OPTION = 'shopwalk_ucp_oauth_token_pepper';

	/**
	 * Test hook — when true, redirect/HTML helpers do NOT exit and instead
	 * return a `WP_REST_Response` so unit tests can inspect the outcome.
	 *
	 * Production never sets this; only the PHPUnit harness flips it.
	 *
	 * @var bool
	 */
	public static bool $testing_no_exit = false;

	/**
	 * Cached pepper bytes — pulled from the WP option on first use.
	 *
	 * @var string|null
	 */
	private static ?string $cached_pepper = null;

	/**
	 * Register all OAuth REST routes under the UCP namespace.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// /authorize is GET-only (F-C-5). POST + implicit consent + no nonce
		// would be a CSRF account-linking primitive. The interactive consent
		// gate (F-C-6) lives at the sibling /oauth/consent route, which is
		// POST-only with a wp_nonce check.
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/oauth/authorize',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'handle_authorize' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			UCP_REST_NAMESPACE,
			'/oauth/consent',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'handle_consent' ),
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
	 * user account; if not, they are redirected to wp-login.php with a
	 * return URL back to /authorize (standard WP pattern).
	 *
	 * Once logged in, the user sees a server-rendered consent page (F-C-6)
	 * listing the requesting agent + redirect_uri + requested scopes, with
	 * Approve / Deny buttons. The form submits to /oauth/consent — code
	 * issuance happens there, not here. /authorize never silently mints a
	 * code; doing so + accepting POST + having no nonce would be a
	 * one-click CSRF account-linking primitive.
	 *
	 * PKCE is MANDATORY (OAuth 2.1 §4.1.2.1). The client MUST send a
	 * `code_challenge` parameter; missing/empty values are rejected with
	 * `pkce_required`. Only `S256` is accepted as `code_challenge_method`
	 * — the legacy `plain` method is forbidden by OAuth 2.1, and the
	 * comparison is case-sensitive (RFC 7636 specifies uppercase). When
	 * `code_challenge_method` is omitted but a challenge is present, it
	 * defaults to `S256` (NOT `plain`, which would silently re-introduce
	 * the weakness this check exists to prevent).
	 *
	 * `state` is REQUIRED (F-C-4). Missing/empty state is rejected with
	 * 400 `state_required`. RFC 6749 §10.12 makes state the primary CSRF
	 * defense the client uses to bind the auth response back to its
	 * request — accepting requests without it punishes secure clients
	 * and rewards lazy ones.
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

		// F-C-4: state is required (RFC 6749 §10.12 CSRF defense).
		if ( '' === $state ) {
			return new WP_Error( 'state_required', 'state is required (RFC 6749 §10.12)', array( 'status' => 400 ) );
		}

		$client = UCP_OAuth_Clients::find( $client_id );
		if ( ! $client ) {
			return new WP_Error( 'invalid_client', 'Unknown client_id', array( 'status' => 400 ) );
		}
		if ( ! UCP_OAuth_Clients::is_valid_redirect_uri( $client, $redirect_uri ) ) {
			return new WP_Error( 'invalid_redirect_uri', 'redirect_uri not registered for this client', array( 'status' => 400 ) );
		}

		// Buyer must be authenticated — bounce through wp-login if not.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$return_to = rest_url( UCP_REST_NAMESPACE . '/oauth/authorize' ) . '?' . http_build_query( $request->get_query_params() );
			$login_url = wp_login_url( $return_to );
			return self::do_redirect( $login_url );
		}

		// PKCE — MANDATORY S256 only (OAuth 2.1 §4.1.2.1, RFC 7636).
		// `plain` is forbidden; absence of `code_challenge` is a hard error.
		$code_challenge        = (string) $request->get_param( 'code_challenge' );
		$code_challenge_method = (string) $request->get_param( 'code_challenge_method' );
		if ( '' === $code_challenge ) {
			return new WP_Error( 'pkce_required', 'code_challenge is required (PKCE is mandatory; OAuth 2.1 §4.1.2.1)', array( 'status' => 400 ) );
		}
		// Default the method to S256 ONLY when the client omitted it but
		// supplied a challenge. We must NOT default to `plain` here — that
		// would silently downgrade and re-open the weakness PKCE exists
		// to close.
		if ( '' === $code_challenge_method ) {
			$code_challenge_method = 'S256';
		}
		// Strict, case-sensitive S256 check. RFC 7636 §4.3 specifies the
		// method names with this exact casing; accepting `s256` would let a
		// buggy/forged client coast through with no method enforced.
		if ( 'S256' !== $code_challenge_method ) {
			return new WP_Error( 'pkce_method_unsupported', 'code_challenge_method must be S256 (case-sensitive); plain is forbidden by OAuth 2.1', array( 'status' => 400 ) );
		}

		// F-C-6: render the consent page. Code issuance happens at
		// /oauth/consent after Approve + nonce verify.
		$scopes_arr = '' !== $scope ? array_values( array_filter( explode( ' ', $scope ) ) ) : array( 'ucp:checkout', 'ucp:orders' );
		$html       = self::render_consent_page(
			array(
				'client'                => $client,
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'state'                 => $state,
				'scopes'                => $scopes_arr,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'response_type'         => $response_type,
			)
		);
		return self::send_html( $html, 200 );
	}

	// ── /consent — POST-only handler that completes the auth-code grant ──

	/**
	 * Consent endpoint (F-C-6). POST-only. Verifies the wp_nonce that was
	 * embedded in the consent page form, then either:
	 *   - decision=approve → mint authorization_code + 302 to redirect_uri
	 *   - decision=deny    → 302 to redirect_uri with error=access_denied
	 *
	 * The browser-driven nature of this endpoint is why /consent uses
	 * `wp_redirect` + `exit` rather than a `WP_REST_Response` 302 — WP REST's
	 * 302 path doesn't reliably set Location for browser navigation.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_consent( WP_REST_Request $request ) {
		$client_id             = (string) $request->get_param( 'client_id' );
		$redirect_uri          = (string) $request->get_param( 'redirect_uri' );
		$state                 = (string) $request->get_param( 'state' );
		$scope                 = (string) $request->get_param( 'scope' );
		$code_challenge        = (string) $request->get_param( 'code_challenge' );
		$code_challenge_method = (string) $request->get_param( 'code_challenge_method' );
		$decision              = (string) $request->get_param( 'decision' );
		$nonce                 = (string) $request->get_param( '_wpnonce' );

		if ( '' === $client_id || '' === $redirect_uri || '' === $state || '' === $code_challenge ) {
			return new WP_Error( 'consent_missing_params', 'Required consent parameters missing', array( 'status' => 400 ) );
		}
		if ( '' === $code_challenge_method ) {
			$code_challenge_method = 'S256';
		}
		if ( 'S256' !== $code_challenge_method ) {
			return new WP_Error( 'pkce_method_unsupported', 'code_challenge_method must be S256', array( 'status' => 400 ) );
		}

		// User must still be logged in.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'consent_unauthenticated', 'Not logged in', array( 'status' => 401 ) );
		}

		// Nonce is the CSRF guard — the consent page issued this nonce
		// scoped to ucp_oauth_consent_<client_id>.
		if ( ! wp_verify_nonce( $nonce, 'ucp_oauth_consent_' . $client_id ) ) {
			return new WP_Error( 'consent_bad_nonce', 'Invalid or expired consent nonce', array( 'status' => 403 ) );
		}

		// Re-verify client + redirect_uri server-side — the form fields
		// could have been tampered with after the consent page rendered.
		$client = UCP_OAuth_Clients::find( $client_id );
		if ( ! $client ) {
			return new WP_Error( 'invalid_client', 'Unknown client_id', array( 'status' => 400 ) );
		}
		if ( ! UCP_OAuth_Clients::is_valid_redirect_uri( $client, $redirect_uri ) ) {
			return new WP_Error( 'invalid_redirect_uri', 'redirect_uri not registered for this client', array( 'status' => 400 ) );
		}

		// Deny path — bounce back to the agent with error=access_denied so
		// the agent can show its own "you cancelled" UX. RFC 6749 §4.1.2.1.
		if ( 'approve' !== $decision ) {
			$separator = str_contains( $redirect_uri, '?' ) ? '&' : '?';
			$location  = $redirect_uri . $separator . http_build_query(
				array(
					'error' => 'access_denied',
					'state' => $state,
				)
			);
			return self::do_redirect( $location );
		}

		// Approve path — mint the authorization code and 302.
		$scopes = '' !== $scope ? array_values( array_filter( explode( ' ', $scope ) ) ) : array( 'ucp:checkout', 'ucp:orders' );
		$code   = self::issue_token(
			'authorization_code',
			$client_id,
			$user_id,
			$scopes,
			self::CODE_TTL,
			$code_challenge,
			$code_challenge_method
		);

		$separator = str_contains( $redirect_uri, '?' ) ? '&' : '?';
		$location  = $redirect_uri . $separator . http_build_query(
			array(
				'code'  => $code['plaintext'],
				'state' => $state,
			)
		);
		return self::do_redirect( $location );
	}

	// ── /token — exchange code or refresh token for access token ─────────

	/**
	 * Token endpoint. Supports `authorization_code` and `refresh_token`
	 * grant types per RFC 6749. Client credentials are passed in the
	 * request body (Basic auth header support is a Phase 2 follow-up).
	 *
	 * Per-client rate limit (F-C-7): 10 failed `verify_secret` attempts
	 * per minute per client_id triggers a 429 with Retry-After: 60. The
	 * counter only increments on FAILED secret verification; success
	 * clears the bucket. Rate is per-client so noisy traffic from one
	 * agent doesn't lock out a separate agent on the same site.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_token( WP_REST_Request $request ) {
		$grant_type    = (string) $request->get_param( 'grant_type' );
		$client_id     = (string) $request->get_param( 'client_id' );
		$client_secret = (string) $request->get_param( 'client_secret' );

		// F-C-7: pre-flight rate limit check.
		if ( '' !== $client_id && self::is_rate_limited( $client_id ) ) {
			if ( ! headers_sent() ) {
				header( 'Retry-After: ' . self::TOKEN_RATE_LIMIT_WINDOW );
			}
			return new WP_Error(
				'too_many_attempts',
				'Too many failed token-endpoint attempts; try again shortly',
				array(
					'status'      => 429,
					'retry_after' => self::TOKEN_RATE_LIMIT_WINDOW,
				)
			);
		}

		if ( ! UCP_OAuth_Clients::verify_secret( $client_id, $client_secret ) ) {
			if ( '' !== $client_id ) {
				self::record_token_attempt_failure( $client_id );
			}
			return new WP_Error( 'invalid_client', 'Bad client credentials', array( 'status' => 401 ) );
		}

		// Successful auth — clear the failure bucket.
		self::clear_token_attempts( $client_id );

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
	 * PKCE is MANDATORY (OAuth 2.1 §4.1.2.1). Every authorization code
	 * issued by handle_authorize() carries a stored `code_challenge` and
	 * `code_challenge_method = S256`. The token request MUST include a
	 * matching `code_verifier` and we ONLY verify under S256. The legacy
	 * `plain` method is rejected outright — even if a row somehow still
	 * carries `code_challenge_method = 'plain'` (legacy data from before
	 * this fix landed; not expected in production), we treat it as a PKCE
	 * failure rather than fall back to direct comparison. A row with no
	 * stored challenge (also legacy) is likewise rejected: a code minted
	 * post-fix always has one.
	 *
	 * Breaking change in v3.1.1 (F-C-2): clients that previously used
	 * `plain` PKCE or no PKCE at all will now hit `pkce_verifier_required`
	 * / `pkce_verification_failed` here.
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

		// PKCE verification — mandatory and S256-only.
		$stored_challenge = (string) ( $row['code_challenge'] ?? '' );
		$stored_method    = (string) ( $row['code_challenge_method'] ?? '' );

		// Defensive: a legacy row with no stored challenge, or a stored
		// `plain` method (from before mandatory-PKCE shipped), is treated
		// as a PKCE failure. Burn the code so it can't be retried.
		if ( '' === $stored_challenge || 'S256' !== $stored_method ) {
			self::revoke_token( (int) $row['id'] );
			return new WP_Error( 'pkce_verification_failed', 'Authorization code is missing a usable S256 PKCE challenge', array( 'status' => 400 ) );
		}

		$code_verifier = (string) $request->get_param( 'code_verifier' );
		if ( '' === $code_verifier ) {
			return new WP_Error( 'pkce_verifier_required', 'code_verifier is required (PKCE)', array( 'status' => 400 ) );
		}

		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for PKCE S256 code challenge per RFC 7636.
		if ( ! hash_equals( $stored_challenge, $computed ) ) {
			self::revoke_token( (int) $row['id'] );
			return new WP_Error( 'pkce_verification_failed', 'PKCE verification failed', array( 'status' => 400 ) );
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
	 * Implements refresh-token rotation with reuse-detection family
	 * revocation per OAuth 2.1 / draft-ietf-oauth-security-topics §4.12.
	 *
	 * Behavior:
	 *  - A successful exchange revokes the supplied refresh row and mints a
	 *    NEW access+refresh pair. The old refresh row's client_id + user_id
	 *    are carried over so the family stays linked. Previously-issued
	 *    access tokens are NOT revoked (they expire on their own short TTL).
	 *  - If the supplied refresh token is found in revoked state, this is a
	 *    reuse-detection event: someone is replaying an already-rotated
	 *    refresh token. The entire token family for (client_id, user_id) is
	 *    revoked and 401 `refresh_token_revoked` is returned. Aggressive but
	 *    correct — either the legitimate client lost the rotation race or
	 *    the token was leaked; the safe response is to log out the whole
	 *    session and force re-auth.
	 *  - If no row matches at all, return 401 `invalid_grant`.
	 *  - If the row's client_id doesn't match the authenticated caller's
	 *    client_id, return 401 `invalid_grant` (regression-tested: refresh
	 *    tokens are bound to the issuing client).
	 *
	 * @param WP_REST_Request $request   The token request.
	 * @param string          $client_id The validated client.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function exchange_refresh_token( WP_REST_Request $request, string $client_id ) {
		$refresh = (string) $request->get_param( 'refresh_token' );

		// First try the live (non-revoked, non-expired) lookup.
		$row = self::lookup_token( $refresh, 'refresh' );

		if ( ! $row ) {
			// Reuse-detection: was this refresh token previously issued and
			// then revoked (rotated)? If so, treat as a leak and revoke the
			// entire family for that (client_id, user_id) pair.
			$revoked_row = self::lookup_revoked_token( $refresh, 'refresh' );
			if ( $revoked_row ) {
				if ( (string) $revoked_row['client_id'] !== $client_id ) {
					return new WP_Error( 'invalid_grant', 'Refresh token does not belong to this client', array( 'status' => 401 ) );
				}
				self::revoke_token_family( (string) $revoked_row['client_id'], (int) $revoked_row['user_id'] );
				return new WP_Error( 'refresh_token_revoked', 'Refresh token has already been used; session revoked', array( 'status' => 401 ) );
			}
			return new WP_Error( 'invalid_grant', 'Invalid or expired refresh token', array( 'status' => 401 ) );
		}

		if ( (string) $row['client_id'] !== $client_id ) {
			return new WP_Error( 'invalid_grant', 'Refresh token does not belong to this client', array( 'status' => 401 ) );
		}

		$scopes  = json_decode( (string) $row['scopes'], true ) ?: array();
		$user_id = (int) $row['user_id'];

		// Rotate: revoke the old refresh row, mint a NEW refresh+access
		// pair. We do NOT revoke the family here — the legitimate caller
		// still holds valid access tokens that should keep working until
		// they expire on their own short TTL.
		self::revoke_token( (int) $row['id'] );

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

	// ── Token issuance + lookup (F-C-3 HMAC + lazy bcrypt migration) ────

	/**
	 * Lazily load (or create) the per-install token pepper. 32 random bytes
	 * stored hex-encoded in the WP option `shopwalk_ucp_oauth_token_pepper`.
	 *
	 * The pepper is what makes HMAC-SHA256 of an opaque token resistant to
	 * offline rainbow-table style attacks if the tokens table ever leaks —
	 * an attacker who only has the database row can't compute the HMAC of
	 * a candidate token without the pepper.
	 *
	 * @return string The raw 32-byte pepper.
	 */
	private static function pepper(): string {
		if ( null !== self::$cached_pepper ) {
			return self::$cached_pepper;
		}
		$stored = get_option( self::PEPPER_OPTION, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			try {
				$bytes = random_bytes( 32 );
			} catch ( \Exception $e ) {
				$bytes = hash( 'sha256', wp_generate_password( 64, true, true ), true );
			}
			$stored = bin2hex( $bytes );
			// autoload=false: this option is only read by OAuth code on
			// /authorize + /token + /userinfo — not on every page load.
			update_option( self::PEPPER_OPTION, $stored, false );
		}
		$raw                 = (string) hex2bin( $stored );
		self::$cached_pepper = '' !== $raw ? $raw : (string) $stored;
		return self::$cached_pepper;
	}

	/**
	 * Compute the indexed (HMAC-SHA256) hash for a plaintext token. Output
	 * is hex (64 chars) so it fits cleanly into the existing VARCHAR(128)
	 * `token_hash` column.
	 *
	 * @param string $plaintext The opaque token from the wire.
	 * @return string
	 */
	private static function indexed_hash( string $plaintext ): string {
		return hash_hmac( 'sha256', $plaintext, self::pepper() );
	}

	/**
	 * Detect whether a stored token_hash is a legacy bcrypt value. bcrypt
	 * outputs always start with `$2y$` or `$2b$` (sometimes `$2a$` on very
	 * old PHP). HMAC-SHA256 hex output is `[0-9a-f]{64}` and never starts
	 * with `$`.
	 *
	 * @param string $stored Stored token_hash column value.
	 * @return bool
	 */
	private static function is_bcrypt_hash( string $stored ): bool {
		return str_starts_with( $stored, '$2y$' )
			|| str_starts_with( $stored, '$2b$' )
			|| str_starts_with( $stored, '$2a$' );
	}

	/**
	 * Issue a fresh token of the given type. Returns the plaintext token
	 * to the caller. The table stores the HMAC-SHA256 indexed hash (F-C-3)
	 * — deterministic per token, indexed, O(1) lookup.
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
		$hash = self::indexed_hash( $plaintext );

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
	 * Look up a live token (non-revoked, non-expired) by plaintext + type.
	 *
	 * Fast path (F-C-3): O(1) indexed lookup on the deterministic HMAC.
	 * Constant-time `hash_equals` confirm runs as defense in depth.
	 *
	 * Slow legacy path: if no row matched, walk the rows with bcrypt-format
	 * `token_hash` values (rows issued before F-C-3 landed) and password_verify
	 * each one. On match, upgrade that row's `token_hash` to the HMAC value
	 * so subsequent lookups hit the fast path. After ~30 days of organic
	 * traffic this drains; the bcrypt path can be retired in v3.2.0.
	 *
	 * @param string $plaintext The token from the wire.
	 * @param string $type      Required token type.
	 * @return array<string,mixed>|null
	 */
	public static function lookup_token( string $plaintext, string $type ): ?array {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_tokens' );
		$now   = current_time( 'mysql', true );

		// Fast path — indexed equality on HMAC.
		$indexed = self::indexed_hash( $plaintext );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE token_hash = %s AND token_type = %s AND revoked_at IS NULL AND expires_at > %s LIMIT 1",
				$indexed,
				$type,
				$now
			),
			ARRAY_A
		);
		if ( $row && hash_equals( (string) $row['token_hash'], $indexed ) ) {
			return $row;
		}

		// Slow legacy path — bcrypt rows from before F-C-3.
		$legacy = self::lookup_legacy_bcrypt_token( $plaintext, $type, false );
		return $legacy;
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

	/**
	 * Look up a token of a given type that has already been revoked. Used
	 * by refresh-token rotation to detect reuse of a previously-rotated
	 * refresh token (which signals either a lost rotation race or a leak).
	 *
	 * Mirrors lookup_token() but inverts the revoked_at filter and ignores
	 * expiry — a revoked refresh token presented after its TTL is still a
	 * reuse attempt worth catching.
	 *
	 * @param string $plaintext The token from the wire.
	 * @param string $type      Required token type.
	 * @return array<string,mixed>|null
	 */
	public static function lookup_revoked_token( string $plaintext, string $type ): ?array {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_tokens' );

		// Fast path — indexed equality on HMAC.
		$indexed = self::indexed_hash( $plaintext );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE token_hash = %s AND token_type = %s AND revoked_at IS NOT NULL LIMIT 1",
				$indexed,
				$type
			),
			ARRAY_A
		);
		if ( $row && hash_equals( (string) $row['token_hash'], $indexed ) ) {
			return $row;
		}

		// Slow legacy path — bcrypt revoked rows.
		return self::lookup_legacy_bcrypt_token( $plaintext, $type, true );
	}

	/**
	 * Walk the legacy bcrypt rows of the given type and return the first
	 * row whose hash matches `password_verify`. On match, upgrade that
	 * row's `token_hash` to the HMAC value so subsequent lookups hit the
	 * indexed fast path.
	 *
	 * Production scope: drains over ~30 days of organic OAuth traffic post
	 * v3.1.1. Once the rate of bcrypt-format rows falls to zero, the
	 * `password_verify` walk can be removed in v3.2.0.
	 *
	 * @param string $plaintext  The token from the wire.
	 * @param string $type       Required token type.
	 * @param bool   $revoked    True for revoked-row lookup, false for live.
	 * @return array<string,mixed>|null
	 */
	private static function lookup_legacy_bcrypt_token( string $plaintext, string $type, bool $revoked ): ?array {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_tokens' );
		$now   = current_time( 'mysql', true );

		if ( $revoked ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE token_type = %s AND revoked_at IS NOT NULL AND token_hash LIKE %s",
					$type,
					'$2%'
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE token_type = %s AND revoked_at IS NULL AND expires_at > %s AND token_hash LIKE %s",
					$type,
					$now,
					'$2%'
				),
				ARRAY_A
			);
		}
		if ( ! $rows ) {
			return null;
		}
		foreach ( $rows as $row ) {
			$stored = (string) $row['token_hash'];
			if ( ! self::is_bcrypt_hash( $stored ) ) {
				continue;
			}
			if ( password_verify( $plaintext, $stored ) ) {
				// Upgrade this row to the HMAC fast path. Best-effort —
				// failure here just means the slow path runs again next
				// time; nothing security-critical.
				$indexed = self::indexed_hash( $plaintext );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array( 'token_hash' => $indexed ),
					array( 'id' => (int) $row['id'] )
				);
				$row['token_hash'] = $indexed;
				return $row;
			}
		}
		return null;
	}

	/**
	 * Revoke every non-revoked token (any type) belonging to the given
	 * (client_id, user_id) pair. Used by the refresh-token reuse-detection
	 * path to forcibly tear down an entire OAuth session when a leaked or
	 * already-rotated refresh token is replayed.
	 *
	 * @param string $client_id Owning client.
	 * @param int    $user_id   Owning user.
	 * @return int Number of rows revoked.
	 */
	public static function revoke_token_family( string $client_id, int $user_id ): int {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_tokens' );
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET revoked_at = %s WHERE client_id = %s AND user_id = %d AND revoked_at IS NULL",
				$now,
				$client_id,
				$user_id
			)
		);
		return (int) $affected;
	}

	// ── F-C-7: per-client rate limit on /token ───────────────────────────

	/**
	 * Has this client_id exhausted its /token failure budget in the
	 * current 60s window?
	 *
	 * @param string $client_id Client identifier from the wire.
	 * @return bool
	 */
	private static function is_rate_limited( string $client_id ): bool {
		$attempts = self::current_token_attempts( $client_id );
		return count( $attempts ) >= self::TOKEN_RATE_LIMIT_MAX;
	}

	/**
	 * Pull the current attempt timestamps for a client_id, dropping any
	 * outside the 60s window.
	 *
	 * @param string $client_id Client identifier from the wire.
	 * @return int[]
	 */
	private static function current_token_attempts( string $client_id ): array {
		$key  = self::rate_limit_transient_key( $client_id );
		$raw  = get_transient( $key );
		$rows = is_array( $raw ) ? $raw : array();
		$now  = time();
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $ts ) use ( $now ) {
					return is_int( $ts ) && ( $now - $ts ) < self::TOKEN_RATE_LIMIT_WINDOW;
				}
			)
		);
		return $rows;
	}

	/**
	 * Append a failure timestamp for this client_id to the rate-limit
	 * bucket.
	 *
	 * @param string $client_id Client identifier from the wire.
	 * @return void
	 */
	private static function record_token_attempt_failure( string $client_id ): void {
		$attempts   = self::current_token_attempts( $client_id );
		$attempts[] = time();
		set_transient(
			self::rate_limit_transient_key( $client_id ),
			$attempts,
			self::TOKEN_RATE_LIMIT_WINDOW
		);
	}

	/**
	 * Wipe the failure bucket on successful client auth.
	 *
	 * @param string $client_id Client identifier from the wire.
	 * @return void
	 */
	private static function clear_token_attempts( string $client_id ): void {
		delete_transient( self::rate_limit_transient_key( $client_id ) );
	}

	/**
	 * Build the per-client transient key. Hashed so an attacker-supplied
	 * client_id can't smuggle WP options-table characters into the key.
	 *
	 * @param string $client_id Client identifier from the wire.
	 * @return string
	 */
	private static function rate_limit_transient_key( string $client_id ): string {
		return 'ucp_token_attempts_' . substr( hash( 'sha256', $client_id ), 0, 32 );
	}

	// ── F-C-6: consent screen rendering ──────────────────────────────────

	/**
	 * Static, human-readable scope labels rendered on the consent page.
	 * Unknown scopes still render — they fall back to their raw string.
	 *
	 * @return array<string,string>
	 */
	private static function scope_labels(): array {
		return array(
			'openid'       => __( 'Verify your account identity', 'shopwalk-for-woocommerce' ),
			'profile'      => __( 'Read your basic profile (name, email)', 'shopwalk-for-woocommerce' ),
			'email'        => __( 'Read your email address', 'shopwalk-for-woocommerce' ),
			'ucp:checkout' => __( 'Place orders on your behalf at this store', 'shopwalk-for-woocommerce' ),
			'ucp:orders'   => __( 'Read your order history at this store', 'shopwalk-for-woocommerce' ),
			'ucp:webhooks' => __( 'Receive notifications about your orders', 'shopwalk-for-woocommerce' ),
		);
	}

	/**
	 * Render the consent screen HTML. All caller-controlled values are
	 * escaped at the boundary with esc_html / esc_attr / esc_url. The
	 * page POSTs to /oauth/consent with a wp_nonce_field tied to the
	 * client_id.
	 *
	 * @param array{
	 *     client:array<string,mixed>,
	 *     client_id:string,
	 *     redirect_uri:string,
	 *     state:string,
	 *     scopes:array<int,string>,
	 *     code_challenge:string,
	 *     code_challenge_method:string,
	 *     response_type:string,
	 * } $ctx Consent context.
	 * @return string
	 */
	private static function render_consent_page( array $ctx ): string {
		$client_name = (string) ( $ctx['client']['name'] ?? $ctx['client_id'] );
		$labels      = self::scope_labels();

		$scope_lis = '';
		foreach ( $ctx['scopes'] as $scope ) {
			$label      = $labels[ $scope ] ?? $scope;
			$scope_lis .= '<li><code>' . esc_html( $scope ) . '</code> &mdash; ' . esc_html( $label ) . '</li>';
		}

		$consent_action = esc_url( rest_url( UCP_REST_NAMESPACE . '/oauth/consent' ) );
		$nonce_field    = wp_nonce_field( 'ucp_oauth_consent_' . $ctx['client_id'], '_wpnonce', true, false );

		$hidden = '';
		foreach (
			array(
				'client_id'             => $ctx['client_id'],
				'redirect_uri'          => $ctx['redirect_uri'],
				'state'                 => $ctx['state'],
				'scope'                 => implode( ' ', $ctx['scopes'] ),
				'code_challenge'        => $ctx['code_challenge'],
				'code_challenge_method' => $ctx['code_challenge_method'],
				'response_type'         => $ctx['response_type'],
			) as $name => $value
		) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		}

		$title = esc_html__( 'Authorize access', 'shopwalk-for-woocommerce' );
		$intro = esc_html(
			sprintf(
				/* translators: %s: agent / client display name */
				__( '%s is requesting access to your account at this store.', 'shopwalk-for-woocommerce' ),
				$client_name
			)
		);
		$redirect_lbl  = esc_html__( 'After you decide, you will be returned to:', 'shopwalk-for-woocommerce' );
		$scopes_lbl    = esc_html__( 'It will be allowed to:', 'shopwalk-for-woocommerce' );
		$approve_label = esc_html__( 'Approve', 'shopwalk-for-woocommerce' );
		$deny_label    = esc_html__( 'Deny', 'shopwalk-for-woocommerce' );
		$redirect_html = esc_html( $ctx['redirect_uri'] );

		// Minimal, self-contained HTML — no theme dependency. The OAuth
		// consent gate must work even on stores running broken themes.
		// Nowdoc + strtr (not heredoc) keeps WP.org Plugin Check happy:
		// it forbids <<<HEREDOC syntax with variable interpolation; nowdoc
		// is treated as a literal string and is fine.
		$tpl = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title>{TITLE}</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 480px; margin: 4em auto; padding: 0 1em; color: #1d2327; }
h1 { font-size: 1.5em; margin-bottom: 0.5em; }
ul { padding-left: 1.25em; }
li { margin: 0.4em 0; }
code { background: #f0f0f1; padding: 0 0.3em; border-radius: 3px; font-size: 0.9em; }
.redirect { word-break: break-all; background: #f6f7f7; padding: 0.5em 0.75em; border-radius: 4px; font-family: monospace; font-size: 0.9em; }
.actions { margin-top: 2em; display: flex; gap: 0.75em; }
button { font-size: 1em; padding: 0.6em 1.2em; border-radius: 4px; cursor: pointer; border: 1px solid transparent; }
button.approve { background: #2271b1; color: #fff; border-color: #2271b1; }
button.deny { background: #fff; color: #50575e; border-color: #c3c4c7; }
</style>
</head>
<body>
<h1>{TITLE}</h1>
<p>{INTRO}</p>
<p><strong>{SCOPES_LBL}</strong></p>
<ul>{SCOPE_LIS}</ul>
<p><strong>{REDIRECT_LBL}</strong></p>
<p class="redirect">{REDIRECT_HTML}</p>
<form method="post" action="{CONSENT_ACTION}">
{NONCE_FIELD}
{HIDDEN}
<div class="actions">
<button class="approve" type="submit" name="decision" value="approve">{APPROVE_LABEL}</button>
<button class="deny" type="submit" name="decision" value="deny">{DENY_LABEL}</button>
</div>
</form>
</body>
</html>
HTML;
		return strtr(
			$tpl,
			array(
				'{TITLE}'          => $title,
				'{INTRO}'          => $intro,
				'{SCOPES_LBL}'     => $scopes_lbl,
				'{SCOPE_LIS}'      => $scope_lis,
				'{REDIRECT_LBL}'   => $redirect_lbl,
				'{REDIRECT_HTML}'  => $redirect_html,
				'{CONSENT_ACTION}' => $consent_action,
				'{NONCE_FIELD}'    => $nonce_field,
				'{HIDDEN}'         => $hidden,
				'{APPROVE_LABEL}'  => $approve_label,
				'{DENY_LABEL}'     => $deny_label,
			)
		);
	}

	// ── F-C-4: real wp_redirect (not WP_REST_Response 302) + test seam ──

	/**
	 * Issue a real wp_redirect + exit. /authorize and /consent are
	 * browser-driven flows where WP REST's WP_REST_Response 302 path is
	 * unreliable for setting the actual Location header on the response.
	 *
	 * In test mode (`self::$testing_no_exit = true`), returns a
	 * `WP_REST_Response` carrying the would-be Location so callers can
	 * inspect it without halting the test process.
	 *
	 * @param string $location Absolute URL to redirect to.
	 * @return WP_REST_Response|null
	 */
	private static function do_redirect( string $location ): ?WP_REST_Response {
		if ( self::$testing_no_exit ) {
			return new WP_REST_Response(
				array( 'redirect_to' => $location ),
				302,
				array( 'Location' => $location )
			);
		}
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- /authorize redirects to externally registered redirect_uri values that have already been verified against UCP_OAuth_Clients::is_valid_redirect_uri(); wp_safe_redirect would block them.
		wp_redirect( $location, 302 );
		exit;
	}

	/**
	 * Emit a server-rendered HTML page (consent screen) with the right
	 * Content-Type. Same test seam as do_redirect().
	 *
	 * @param string $html   The full HTML document.
	 * @param int    $status HTTP status (default 200).
	 * @return WP_REST_Response|null
	 */
	private static function send_html( string $html, int $status = 200 ): ?WP_REST_Response {
		if ( self::$testing_no_exit ) {
			return new WP_REST_Response(
				array( 'html' => $html ),
				$status,
				array( 'Content-Type' => 'text/html; charset=utf-8' )
			);
		}
		if ( ! headers_sent() ) {
			status_header( $status );
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is built in render_consent_page() with esc_html / esc_attr / esc_url at every interpolation boundary.
		exit;
	}

	// ── Authentication helper for resource endpoints ────────────────────

	/**
	 * permission_callback shim that runs authenticate_request() and returns
	 * `true` on success or the WP_Error on failure. Use this on every REST
	 * route that requires OAuth Bearer auth so the WP REST permission
	 * middleware (rest_authentication_errors filter, capability filters)
	 * sees the route as protected. Without this, registering routes with
	 * `'permission_callback' => '__return_true'` and enforcing auth inside
	 * the handler bypasses the middleware and is a single forgotten check
	 * away from a silently unauthenticated route.
	 *
	 * Handlers that need the (client_id, user_id, scopes) context still call
	 * authenticate_request() themselves; the duplicate work is intentional
	 * defense-in-depth.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error
	 */
	public static function permission_require_oauth( WP_REST_Request $request ) {
		$ctx = self::authenticate_request( $request );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		return true;
	}

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
