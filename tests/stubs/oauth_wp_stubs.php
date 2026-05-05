<?php
/**
 * Shared WP function stubs for unit tests that load class-ucp-oauth-server.php.
 *
 * Centralizes the get_option / transient / nonce / escape / redirect stubs
 * the OAuth server depends on so that every OAuth-related test file gets
 * a consistent in-process WP surface.
 *
 * Call ucp_oauth_install_wp_stubs() from each test's setUp() AFTER
 * Brain\Monkey\setUp().
 *
 * @package WooCommerceUCP
 */

use Brain\Monkey\Functions;

if ( ! function_exists( 'ucp_oauth_install_wp_stubs' ) ) {
	/**
	 * Install the shared WP function stubs needed by the OAuth server.
	 *
	 * @return void
	 */
	function ucp_oauth_install_wp_stubs(): void {
		// In-process options table.
		$GLOBALS['ucp_test_options']    = array();
		$GLOBALS['ucp_test_transients'] = array();

		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return $GLOBALS['ucp_test_options'][ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $name, $value, $autoload = true ) {
				$GLOBALS['ucp_test_options'][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $name ) {
				unset( $GLOBALS['ucp_test_options'][ $name ] );
				return true;
			}
		);

		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) {
				$row = $GLOBALS['ucp_test_transients'][ $key ] ?? null;
				if ( ! is_array( $row ) ) {
					return false;
				}
				if ( $row['expires'] < time() ) {
					unset( $GLOBALS['ucp_test_transients'][ $key ] );
					return false;
				}
				return $row['value'];
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $value, int $ttl = 0 ) {
				$GLOBALS['ucp_test_transients'][ $key ] = array(
					'value'   => $value,
					'expires' => time() + max( 1, $ttl ),
				);
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) {
				unset( $GLOBALS['ucp_test_transients'][ $key ] );
				return true;
			}
		);

		// Escape helpers — pass-through is fine for unit tests; the real
		// WP escape behavior is exercised at integration level.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		// Translatable sprintf helper (we don't need real sprintf here).
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);

		// Nonce helpers — keyed lookup table so the test can drive both
		// good-nonce and bad-nonce paths deterministically.
		$GLOBALS['ucp_test_nonces'] = array();
		Functions\when( 'wp_create_nonce' )->alias(
			static function ( string $action ) {
				$nonce                                  = bin2hex( random_bytes( 8 ) );
				$GLOBALS['ucp_test_nonces'][ $nonce ]   = $action;
				return $nonce;
			}
		);
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce, string $action ) {
				return ( $GLOBALS['ucp_test_nonces'][ (string) $nonce ] ?? null ) === $action ? 1 : false;
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true ) {
				$nonce                                  = bin2hex( random_bytes( 8 ) );
				$GLOBALS['ucp_test_nonces'][ $nonce ]   = $action;
				$html                                   = '<input type="hidden" name="' . $name . '" value="' . $nonce . '" />';
				if ( $echo ) {
					echo $html;
				}
				return $html;
			}
		);

		// REST URL helper.
		Functions\when( 'rest_url' )->alias(
			static function ( $path = '' ) {
				return 'https://shop.example/wp-json/' . ltrim( (string) $path, '/' );
			}
		);

		// Redirect / status — only invoked if `testing_no_exit` is false.
		// Keep these as Brain\Monkey stubs so any accidental call in tests
		// is observable rather than calling the real PHP functions.
		Functions\when( 'wp_redirect' )->justReturn( true );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );
		Functions\when( 'status_header' )->justReturn( null );
	}
}
