<?php
/**
 * PHPUnit bootstrap for the WooCommerce UCP plugin.
 *
 * Defines ABSPATH so plugin files can be included, then pulls in Composer
 * autoload + Brain\Monkey (used by tests that need to stub WordPress core
 * functions like get_option / wp_json_encode / wp_remote_post).
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );

require_once __DIR__ . '/../vendor/autoload.php';

// Minimal WP_Error stub so UCP_Response::error() doesn't fatal when used from
// unit tests that aren't running inside a WP bootstrap.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore
		public string $code;
		public string $message;
		public array $data;
		public function __construct( string $code = '', string $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = is_array( $data ) ? $data : array();
		}
		public function get_error_code(): string {
			return $this->code; }
		public function get_error_message(): string {
			return $this->message; }
		public function get_error_data() {
			return $this->data; }
	}
}
