<?php
/**
 * Minimal $wpdb stub for unit tests. Treats the database as an in-memory
 * table-keyed array; supports the `WHERE id = %s LIMIT 1` lookup pattern
 * and basic insert/update used by checkout handlers.
 *
 * @package WooCommerceUCP
 */

if ( ! class_exists( 'FakeWpdb' ) ) {
	class FakeWpdb { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		/** @var array<string, array<string, array<string,mixed>>> */
		public array $tables = array();
		public string $prefix = 'wp_';
		public string $last_query = '';
		public ?string $last_pk = null;

		public function prepare( string $query, ...$args ): string {
			// Crude — store the substituted PK so get_row can dispatch.
			if ( ! empty( $args ) ) {
				$this->last_pk = (string) $args[0];
			}
			$this->last_query = $query;
			return $query;
		}

		public function get_row( string $query, $output = ARRAY_A ) {
			$this->last_query = $query;
			// Match `SELECT * FROM {$table} WHERE id = %s LIMIT 1`.
			if ( preg_match( '/FROM\s+(\S+)\s+WHERE\s+id\s*=\s*%s/i', $query, $m ) ) {
				$table = $m[1];
				$id    = (string) ( $this->last_pk ?? '' );
				$row   = $this->tables[ $table ][ $id ] ?? null;
				return $row ? $row : null;
			}
			return null;
		}

		public function insert( string $table, array $data ): int {
			if ( ! isset( $this->tables[ $table ] ) ) {
				$this->tables[ $table ] = array();
			}
			$id = (string) ( $data['id'] ?? '' );
			if ( '' === $id ) {
				$id = (string) ( count( $this->tables[ $table ] ) + 1 );
			}
			// Mirror real `wp_ucp_checkout_sessions` shape — columns absent
			// from the insert payload should still exist as NULL on read.
			$defaults = array(
				'wc_order_id' => null,
			);
			$this->tables[ $table ][ $id ] = array_merge( $defaults, $data );
			return 1;
		}

		public function update( string $table, array $data, array $where ): int {
			$id = (string) ( $where['id'] ?? '' );
			if ( '' === $id ) {
				return 0;
			}
			if ( isset( $this->tables[ $table ][ $id ] ) ) {
				$this->tables[ $table ][ $id ] = array_merge(
					$this->tables[ $table ][ $id ],
					$data
				);
				return 1;
			}
			return 0;
		}

		public function query( string $query ): int {
			return 0;
		}
	}
}

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
