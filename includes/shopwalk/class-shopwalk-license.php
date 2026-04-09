<?php
/**
 * Shopwalk_License — license key validation + heartbeat.
 *
 * Tier 2 (Shopwalk integration) only. Loaded by Shopwalk_AI::load_shopwalk()
 * when a valid `shopwalk_license_key` option is present.
 *
 * @package Shopwalk
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
	 * Check whether a valid license key is present in WP options.
	 *
	 * Accepts both `sw_lic_*` (legacy free-license format) and `sw_site_*`
	 * (current per-site format).
	 *
	 * @return bool
	 */
	public static function is_valid(): bool {
		$key = (string) get_option( self::OPTION_KEY, '' );
		return $key !== '' && (
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
	 * Activate the plugin against shopwalk-api. POSTs the license key +
	 * site_url to /api/v1/plugin/activate and stores the returned
	 * partner_id in WP options. Returns true on success.
	 *
	 * @param string $license_key The license key entered by the merchant.
	 * @return array{ok:bool, message:string, partner_id?:string}
	 */
	public static function activate( string $license_key ): array {
		$response = wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/activate',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-SW-License-Key' => $license_key,
					'User-Agent'      => 'shopwalk-ai-plugin/' . SHOPWALK_AI_VERSION,
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
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 300 ) {
			return array( 'ok' => false, 'message' => 'Shopwalk API returned HTTP ' . $status );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$pid  = (string) ( $body['partner_id'] ?? '' );

		update_option( self::OPTION_KEY, $license_key, false );
		if ( $pid !== '' ) {
			update_option( self::OPTION_PARTNER_ID, $pid, false );
		}
		do_action( 'shopwalk_license_activated', $license_key, $pid );

		return array( 'ok' => true, 'message' => 'Activated', 'partner_id' => $pid );
	}

	/**
	 * Deactivate the local license. Best-effort POST to
	 * /api/v1/plugin/deactivate; clears WP options regardless of API result.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$key = self::key();
		if ( $key !== '' ) {
			wp_remote_post(
				SHOPWALK_API_BASE . '/plugin/deactivate',
				array(
					'timeout' => 5,
					'headers' => array(
						'Content-Type'    => 'application/json',
						'X-SW-License-Key' => $key,
					),
					'body'    => wp_json_encode( array( 'site_url' => home_url() ) ),
				)
			);
		}
		delete_option( self::OPTION_KEY );
		delete_option( self::OPTION_PARTNER_ID );
		do_action( 'shopwalk_license_deactivated' );
	}
}
