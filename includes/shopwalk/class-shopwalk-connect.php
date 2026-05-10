<?php
/**
 * Shopwalk_Connect — OAuth connect flow + Pro upgrade launcher + hourly tier poller.
 *
 * Drives the three-state install model (unlicensed / free / pro) on the
 * WordPress side. Paired with:
 *   - shopwalk-api POST /api/v1/plugin/oauth/token  (mint license from code)
 *   - shopwalk-api GET  /api/v1/plugin/upgrade-url (Stripe Checkout URL)
 *   - shopwalk-api GET  /api/v1/plugin/status      (tier + status)
 *   - shopwalk-web /partners/oauth/plugin/authorize (merchant approves)
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class Shopwalk_Connect {

	private const STATE_TRANSIENT  = 'shopwalk_oauth_state';
	private const STATE_TTL        = 10 * MINUTE_IN_SECONDS;
	private const CRON_HOOK        = 'shopwalk_status_poll';
	private const OPTION_PLAN      = 'shopwalk_plan';
	private const OPTION_STATUS    = 'shopwalk_subscription_status';
	private const OPTION_LAST_POLL = 'shopwalk_last_status_poll';

	public static function init(): void {
		// OAuth callback — merchant lands back here from shopwalk.com with ?code&state.
		add_action( 'admin_init', array( __CLASS__, 'handle_oauth_callback' ) );

		// Hourly tier refresh.
		add_action( self::CRON_HOOK, array( __CLASS__, 'poll_status' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) && class_exists( 'Shopwalk_License' ) && Shopwalk_License::is_valid() ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}

		// Poll immediately after activation so the local tier is fresh.
		add_action( 'shopwalk_license_activated', array( __CLASS__, 'poll_status' ) );
	}

	public static function on_deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	// ── OAuth connect flow ──────────────────────────────────────────────────

	/**
	 * Returns the URL the "Connect to Shopwalk" button sends the merchant to.
	 * Stashes a fresh state nonce in a transient so we can verify the callback.
	 *
	 * Routes through `/partners/signup` (not `/partners/oauth/plugin/authorize`
	 * directly) so a brand-new merchant who installed the plugin from WP.org
	 * has a working signup → activate path. shopwalk-web's signup page
	 * supports a `?next=<relative-path>` redirect that survives the
	 * email-magic-link round-trip via the `sw_login_next` cookie:
	 *
	 *   - Logged-in merchant: signup page detects active session, redirects
	 *     immediately to the `next` URL (the OAuth authorize page).
	 *   - New merchant: signup form persists `next` to the cookie before
	 *     submit. After they click the magic link, `/auth/verify` reads the
	 *     cookie and continues to the OAuth authorize URL — license arrives
	 *     in WP via the same callback as the logged-in path.
	 *
	 * `next` must be a same-origin relative path per shopwalk-web's
	 * `validateNextPath()`, so we build a path-only string here.
	 */
	public static function connect_url(): string {
		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_TRANSIENT, $state, self::STATE_TTL );

		// Pass the OAuth params as flat top-level query params on the
		// signup URL rather than nesting the OAuth URL inside a `next=`
		// value. Nesting would force double-percent-encoding of the inner
		// reserved characters (%2526, %253A …) — some WAFs and host-side
		// firewalls flag repeated `%25` sequences as URL-encoding-evasion
		// attempts and block the request, leaving the merchant's "Connect
		// to Shopwalk" click silently dead.
		//
		// shopwalk-web's signup page recognises `source=plugin` plus the
		// `p_*` params and reconstructs the OAuth approve URL on its side
		// before feeding it into the same `next=` / sw_login_next-cookie
		// chain that the magic-link flow uses, so the activation path is
		// unchanged from v3.1.6 — only the URL shape changes.
		$query = http_build_query(
			array(
				'source'     => 'plugin',
				'p_site_url' => home_url(),
				'p_state'    => $state,
				'p_callback' => admin_url( 'admin.php?page=shopwalk-for-woocommerce&action=oauth-callback' ),
			)
		);
		return SHOPWALK_SIGNUP_URL . '?' . $query;
	}

	/**
	 * Runs on admin_init. If we are the OAuth-callback landing, validate
	 * state, exchange code for license, then redirect to a clean admin URL.
	 */
	public static function handle_oauth_callback(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- state nonce is the CSRF guard here.
		$page  = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Match on page + presence of OAuth payload, NOT on a separate
		// `action=oauth-callback` marker. The action param was getting stripped
		// somewhere in the round trip (suspect: WP redirect_canonical or a
		// host-side security plugin sanitising `action=` because it's a common
		// attack-vector parameter), so a merchant approving the connect flow
		// landed at `?page=...&code=...&state=...` with no action marker and
		// the handler bailed silently. The state nonce itself is the CSRF
		// guard — adding `code` + `state` presence as the dispatch trigger
		// keeps the gate tight and removes one round-trip strip vector.
		if ( 'shopwalk-for-woocommerce' !== $page ) {
			return;
		}
		if ( '' === $code && '' === $error ) {
			// Normal admin page load — not a callback. Don't touch the transient.
			return;
		}

		$expected = (string) get_transient( self::STATE_TRANSIENT );

		$redirect = add_query_arg(
			array( 'page' => 'shopwalk-for-woocommerce' ),
			admin_url( 'admin.php' )
		);

		if ( '' !== $error ) {
			delete_transient( self::STATE_TRANSIENT );
			wp_safe_redirect( add_query_arg( 'sw_connect', 'declined', $redirect ) );
			exit;
		}
		if ( '' === $code || '' === $state || ! hash_equals( $expected, $state ) ) {
			// Don't delete the transient on mismatch — a retry (e.g. user lands
			// twice via login bounce) should be allowed to succeed within TTL.
			wp_safe_redirect( add_query_arg( 'sw_connect', 'state_mismatch', $redirect ) );
			exit;
		}

		// State validated — consume the transient now so a replay can't reuse it.
		delete_transient( self::STATE_TRANSIENT );

		$result = self::exchange_code( $code );
		if ( ! $result['ok'] ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'sw_connect' => 'exchange_failed',
						'sw_reason'  => rawurlencode( $result['message'] ),
					),
					$redirect
				)
			);
			exit;
		}

		wp_safe_redirect( add_query_arg( 'sw_connect', 'ok', $redirect ) );
		exit;
	}

	/**
	 * POSTs the one-shot code to shopwalk-api and, on success, writes the
	 * returned license key into the same options Shopwalk_License reads from.
	 * Leaves partner_id + plan seeded so the dashboard renders the right tier
	 * before the first hourly poll.
	 *
	 * @param string $code
	 * @return array{ok:bool,message:string}
	 */
	private static function exchange_code( string $code ): array {
		$response = wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/oauth/token',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'shopwalk-for-woocommerce-plugin/' . WOOCOMMERCE_SHOPWALK_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'code'     => $code,
						'site_url' => home_url(),
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 300 ) {
			return array(
				'ok'      => false,
				'message' => 'api_http_' . $status,
			);
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$key  = (string) ( $body['license_key'] ?? '' );
		$tier = (string) ( $body['tier'] ?? 'free' );
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => 'missing_license_key',
			);
		}

		update_option( 'shopwalk_license_key', $key, false );
		update_option( self::OPTION_PLAN, $tier, false );

		// Schedule the hourly poll now that we have a license.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}

		do_action( 'shopwalk_license_activated', $key, (string) ( $body['partner_id'] ?? '' ) );
		return array(
			'ok'      => true,
			'message' => 'connected',
		);
	}

	// ── Hourly tier + subscription poll ─────────────────────────────────────

	/**
	 * Cron handler: fetch /plugin/status and cache tier locally so the admin
	 * dashboard can gate Pro features without a live API call per page view.
	 */
	public static function poll_status(): void {
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			return;
		}
		$key = Shopwalk_License::key();
		if ( '' === $key ) {
			return;
		}

		$response = wp_remote_get(
			SHOPWALK_API_BASE . '/plugin/status',
			array(
				'timeout' => 10,
				'headers' => array(
					'X-API-Key'  => $key,
					'User-Agent' => 'shopwalk-for-woocommerce-plugin/' . WOOCOMMERCE_SHOPWALK_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return;
		}
		if ( (int) wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return;
		}

		$tier    = isset( $body['tier'] ) && 'pro' === $body['tier'] ? 'pro' : 'free';
		$substat = (string) ( $body['subscription_status'] ?? '' );

		update_option( self::OPTION_PLAN, $tier, false );
		update_option( self::OPTION_STATUS, $substat, false );
		update_option( self::OPTION_LAST_POLL, time(), false );

		// Mirror the API's license-state fields onto the options
		// Shopwalk_License::status() / ::is_connected() read from. The
		// `license_status` field is the source of truth for the dashboard
		// pill; `last_heartbeat_at` drives the "Connected" badge freshness
		// check. We keep the old subscription_status write above for
		// back-compat, but the new options are what the License helpers
		// consult.
		//
		// /plugin/status returns the license state on the top-level
		// `status` field (no envelope), but newer responses also carry
		// `license_status` for symmetry with /plugin/activate. Prefer
		// `license_status` when present; fall back to `status`.
		$license_status = (string) ( $body['license_status'] ?? $body['status'] ?? 'active' );
		if ( '' === $license_status || 'ok' === $license_status ) {
			$license_status = 'active';
		}
		update_option( 'shopwalk_license_status', $license_status, false );
		update_option( 'shopwalk_last_heartbeat_at', time(), false );
		if ( ! empty( $body['plan_label'] ) ) {
			update_option( 'shopwalk_plan_label', (string) $body['plan_label'], false );
		}
		if ( ! empty( $body['next_billing_date'] ) ) {
			update_option( 'shopwalk_next_billing_at', (string) $body['next_billing_date'], false );
		}
	}
}
