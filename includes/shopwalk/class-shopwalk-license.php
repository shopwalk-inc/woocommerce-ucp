<?php
/**
 * Shopwalk_License — license key validation + heartbeat.
 *
 * Tier 2 (Shopwalk integration) only. Loaded by WooCommerce_Shopwalk::load_shopwalk()
 * when a valid `shopwalk_license_key` option is present.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_License — license helpers.
 */
final class Shopwalk_License {

	/**
	 * The license key option name.
	 */
	private const OPTION_KEY = 'shopwalk_license_key';

	/**
	 * The partner_id option name (set after activation).
	 */
	private const OPTION_PARTNER_ID = 'shopwalk_partner_id';

	/**
	 * Cached plan slug (e.g. "free" | "partner_monthly" | "partner_annual").
	 */
	private const OPTION_PLAN = 'shopwalk_plan';

	/**
	 * Human-readable plan label (e.g. "Free" | "Pro").
	 */
	private const OPTION_PLAN_LABEL = 'shopwalk_plan_label';

	/**
	 * Next billing date (ISO 8601 string), or '' when not on a recurring plan.
	 */
	private const OPTION_NEXT_BILLING = 'shopwalk_next_billing_at';

	/**
	 * Last known license status — one of "active" | "expired" | "revoked"
	 * | "unlicensed".
	 */
	private const OPTION_LICENSE_STATUS = 'shopwalk_license_status';

	/**
	 * Unix timestamp of the last successful API contact (activation or poll).
	 */
	private const OPTION_LAST_HEARTBEAT = 'shopwalk_last_heartbeat_at';

	/**
	 * How long after the last heartbeat we still consider the connection live.
	 */
	private const HEARTBEAT_FRESH_SECONDS = 24 * HOUR_IN_SECONDS;

	/**
	 * Check whether a valid license key is present in WP options.
	 *
	 * Accepts both `sw_lic_*` (legacy free-license format) and `sw_site_*`
	 * (current per-site format).
	 *
	 * @return bool
	 */
	public static function is_valid(): bool {
		$key = (string) get_option( self::OPTION_KEY, '' );
		return '' !== $key && (
			str_starts_with( $key, 'sw_lic_' ) ||
			str_starts_with( $key, 'sw_site_' )
		);
	}

	/**
	 * Returns the configured license key, or '' if missing.
	 *
	 * @return string
	 */
	public static function key(): string {
		return (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Returns the partner_id stored after a successful activation, or ''.
	 *
	 * @return string
	 */
	public static function partner_id(): string {
		return (string) get_option( self::OPTION_PARTNER_ID, '' );
	}

	/**
	 * Returns the cached license status. One of:
	 *   "active"     — last API contact reported the license is good.
	 *   "expired"    — server says the license is past its expiry.
	 *   "revoked"    — server says the license was explicitly revoked.
	 *   "unlicensed" — no license has ever been activated locally.
	 *
	 * Defaults to "unlicensed" when no status has been written yet, and to
	 * "active" when a license key is present but no status was returned by
	 * the API (back-compat with older API responses that didn't include the
	 * `status` field).
	 *
	 * @return string
	 */
	public static function status(): string {
		$stored = (string) get_option( self::OPTION_LICENSE_STATUS, '' );
		if ( '' !== $stored ) {
			return $stored;
		}
		return self::is_valid() ? 'active' : 'unlicensed';
	}

	/**
	 * Returns true iff the license is currently active AND the API has been
	 * heard from within the last HEARTBEAT_FRESH_SECONDS window. Used to
	 * gate the "Connected" badge in the admin dashboard so it accurately
	 * reflects connectivity instead of just license possession.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		if ( 'active' !== self::status() ) {
			return false;
		}
		$last = (int) get_option( self::OPTION_LAST_HEARTBEAT, 0 );
		if ( $last <= 0 ) {
			// No heartbeat ever recorded — be optimistic on first install
			// so the dashboard doesn't flicker red until the first cron tick.
			return self::is_valid();
		}
		return ( time() - $last ) < self::HEARTBEAT_FRESH_SECONDS;
	}

	/**
	 * Activate the plugin against shopwalk-api. POSTs the license key +
	 * site_url to /api/v1/plugin/activate, persists the returned identity
	 * + plan fields to WP options, and returns the parsed result.
	 *
	 * The API is allowed to omit any of `plan` / `plan_label` /
	 * `next_billing_date` / `status`; missing fields read as empty strings
	 * in the result and are not written to options. This keeps the plugin
	 * forward-compatible with older API responses while letting the
	 * dashboard surface real values once the API ships them.
	 *
	 * @param string $license_key The license key entered by the merchant.
	 * @return array{
	 *   ok:bool,
	 *   message:string,
	 *   partner_id?:string,
	 *   plan?:string,
	 *   plan_label?:string,
	 *   next_billing_date?:string,
	 *   status?:string
	 * }
	 */
	public static function activate( string $license_key ): array {
		$response = wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/activate',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $license_key,
					'User-Agent'   => 'shopwalk-for-woocommerce-plugin/' . WOOCOMMERCE_SHOPWALK_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'license_key' => $license_key,
						'site_url'    => home_url(),
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
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 300 ) {
			return array(
				'ok'      => false,
				'message' => 'Shopwalk API returned HTTP ' . $status_code,
			);
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$pid          = (string) ( $body['partner_id'] ?? '' );
		$plan         = (string) ( $body['plan'] ?? '' );
		$plan_label   = (string) ( $body['plan_label'] ?? '' );
		$next_billing = (string) ( $body['next_billing_date'] ?? '' );
		// Prefer `license_status` (used by /plugin/activate, where the
		// top-level `status` field is the "ok" envelope). Fall back to
		// `status` for endpoints that don't carry an envelope. Any value
		// of "ok" is treated as missing — it's the envelope, not the
		// license state.
		$lic_status = (string) ( $body['license_status'] ?? $body['status'] ?? '' );
		if ( 'ok' === $lic_status ) {
			$lic_status = '';
		}

		update_option( self::OPTION_KEY, $license_key, false );
		if ( '' !== $pid ) {
			update_option( self::OPTION_PARTNER_ID, $pid, false );
		}
		if ( '' !== $plan ) {
			update_option( self::OPTION_PLAN, $plan, false );
		}
		if ( '' !== $plan_label ) {
			update_option( self::OPTION_PLAN_LABEL, $plan_label, false );
		}
		if ( '' !== $next_billing ) {
			update_option( self::OPTION_NEXT_BILLING, $next_billing, false );
		}
		// Default to "active" when the API didn't include an explicit status
		// (older API versions). The hourly poll will overwrite this with the
		// real value once the API ships the new field.
		update_option(
			self::OPTION_LICENSE_STATUS,
			'' !== $lic_status ? $lic_status : 'active',
			false
		);
		update_option( self::OPTION_LAST_HEARTBEAT, time(), false );

		// Fire /plugin/status right after activation. The api treats /status
		// as an implicit heartbeat — this flips the partner portal from
		// "plugin not installed" to installed within seconds instead of
		// waiting for the next cron tick.
		if ( class_exists( 'Shopwalk_Connect' ) ) {
			Shopwalk_Connect::poll_status();
		}

		do_action( 'shopwalk_license_activated', $license_key, $pid );

		return array(
			'ok'                => true,
			'message'           => 'Activated',
			'partner_id'        => $pid,
			'plan'              => $plan,
			'plan_label'        => $plan_label,
			'next_billing_date' => $next_billing,
			'status'            => '' !== $lic_status ? $lic_status : 'active',
		);
	}

	/**
	 * Deactivate the local license. Best-effort POST to
	 * /api/v1/plugin/deactivate; clears WP options regardless of API result.
	 *
	 * @return void
	 */
	/**
	 * The discovery-paused option name. Reflects whether the merchant has
	 * paused AI discoverability from the in-plugin toggle.
	 */
	private const OPTION_DISCOVERY_PAUSED = 'shopwalk_discovery_paused';

	/**
	 * Returns true when the merchant has paused AI discoverability.
	 */
	public static function is_discovery_paused(): bool {
		return (bool) get_option( self::OPTION_DISCOVERY_PAUSED, false );
	}

	/**
	 * Best-effort POST to /api/v1/plugin/discovery/disable.
	 * Sets the local option on success so the toggle reflects the API state
	 * without a round-trip on every render.
	 *
	 * @return bool true on API success.
	 */
	public static function pause_discovery(): bool {
		return self::call_discovery_endpoint( 'disable', true );
	}

	/**
	 * Inverse of pause_discovery.
	 */
	public static function resume_discovery(): bool {
		return self::call_discovery_endpoint( 'enable', false );
	}

	private static function call_discovery_endpoint( string $action, bool $next_paused ): bool {
		$key = self::key();
		if ( '' === $key ) {
			return false;
		}
		$resp = wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/discovery/' . $action,
			array(
				'timeout' => 5,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $key,
				),
				'body'    => wp_json_encode( array( 'plugin_key' => $key ) ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		if ( wp_remote_retrieve_response_code( $resp ) >= 300 ) {
			return false;
		}
		update_option( self::OPTION_DISCOVERY_PAUSED, $next_paused, false );
		return true;
	}

	public static function deactivate(): void {
		$key = self::key();
		if ( '' !== $key ) {
			wp_remote_post(
				SHOPWALK_API_BASE . '/plugin/deactivate',
				array(
					'timeout' => 5,
					'headers' => array(
						'Content-Type' => 'application/json',
						'X-API-Key'    => $key,
					),
					'body'    => wp_json_encode( array( 'site_url' => home_url() ) ),
				)
			);
		}
		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_PARTNER_ID );
		delete_option( self::OPTION_PLAN );
		delete_option( self::OPTION_PLAN_LABEL );
		delete_option( self::OPTION_NEXT_BILLING );
		update_option( self::OPTION_LICENSE_STATUS, 'unlicensed', false );
		delete_option( self::OPTION_LAST_HEARTBEAT );
		delete_option( self::OPTION_DISCOVERY_PAUSED );
		// Unschedule the hourly license-status poll. Without this the cron
		// entry persists after disconnect (it's harmless because poll_status()
		// early-returns on empty key, but it's dead weight in wp_cron).
		if ( class_exists( 'Shopwalk_Connect' ) ) {
			Shopwalk_Connect::on_deactivate();
		}
		do_action( 'shopwalk_license_deactivated' );
	}
}
