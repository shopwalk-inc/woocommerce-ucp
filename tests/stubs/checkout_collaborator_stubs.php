<?php
/**
 * Collaborator stubs for UCP_Checkout — stand in for OAuth server, storage,
 * response, and clients so the handler logic can be unit-tested without a
 * WordPress runtime.
 *
 * @package WooCommerceUCP
 */

if ( ! class_exists( 'UCP_Storage' ) ) {
	class UCP_Storage { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public static function table( string $short ): string {
			return 'wp_ucp_' . $short;
		}
	}
}

if ( ! class_exists( 'UCP_OAuth_Clients' ) ) {
	class UCP_OAuth_Clients { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public static int $next = 0;
		public static function generate_id( string $prefix ): string {
			++self::$next;
			return $prefix . 'fixed_' . self::$next;
		}
		public static function generate_secret(): string {
			return bin2hex( random_bytes( 32 ) );
		}
	}
}

if ( ! class_exists( 'UCP_OAuth_Server' ) ) {
	class UCP_OAuth_Server { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		/** @var array{client_id:string,user_id:int,scopes:array<int,string>}|WP_Error|null */
		public static $next_auth_result = null;

		public static function authenticate_request( $request ) {
			if ( null === self::$next_auth_result ) {
				return new WP_Error( 'unauthorized', 'Bearer token required', array( 'status' => 401 ) );
			}
			return self::$next_auth_result;
		}
	}
}

// Real UCP_Response is loaded by the test suite when needed.
require_once __DIR__ . '/../../includes/core/class-ucp-response.php';

