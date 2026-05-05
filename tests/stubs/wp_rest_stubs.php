<?php
/**
 * Minimal WordPress REST stubs for unit tests that exercise checkout
 * handlers without a WP runtime.
 *
 * @package WooCommerceUCP
 */

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		/** @var array<string,string> */
		private array $headers = array();
		/** @var array<string,mixed> */
		private array $params = array();
		/** @var array<string,mixed>|null */
		private ?array $json = null;

		public function set_header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}

		public function get_header( string $name ) {
			return $this->headers[ strtolower( $name ) ] ?? '';
		}

		public function set_param( string $name, $value ): void {
			$this->params[ $name ] = $value;
		}

		public function get_param( string $name ) {
			return $this->params[ $name ] ?? null;
		}

		public function get_query_params(): array {
			return $this->params;
		}

		public function get_params(): array {
			return $this->params;
		}

		public function set_json_params( array $body ): void {
			$this->json = $body;
		}

		public function get_json_params() {
			return $this->json;
		}

		public function get_body() {
			return $this->json ? wp_json_encode( $this->json ) : '';
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public $data;
		public int $status;
		/** @var array<string,string> */
		public array $headers;

		public function __construct( $data = null, int $status = 200, array $headers = array() ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public const READABLE  = 'GET';
		public const CREATABLE = 'POST';
		public const EDITABLE  = 'POST, PUT, PATCH';
	}
}
