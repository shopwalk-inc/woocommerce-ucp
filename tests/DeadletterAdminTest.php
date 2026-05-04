<?php
/**
 * Tests for WooCommerce_Shopwalk_Admin_Deadletter — F-D-6 (admin + WP-CLI surface for the
 * outbound webhook dead-letter queue).
 *
 * Covers:
 *  - retry_row clears failed_at, resets attempts to 0, sets next_attempt_at.
 *  - discard_row deletes the row.
 *  - fetch_failed orders by failed_at DESC and respects the limit.
 *  - ajax_retry without a valid nonce → 403.
 *  - ajax_retry without `manage_woocommerce` → 403.
 *  - ajax_retry happy path — capability + nonce satisfied, row reset.
 *  - ajax_discard happy path — capability + nonce satisfied, row deleted.
 *
 * @package WooCommerceUCP
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'UCP_TABLE_PREFIX' ) || define( 'UCP_TABLE_PREFIX', 'ucp_' );

// Other tests in the suite ship a fake UCP_Storage stub via
// checkout_collaborator_stubs.php; only load the real one when nothing
// else has claimed the class name yet.
if ( ! class_exists( 'UCP_Storage' ) ) {
	require_once __DIR__ . '/../includes/core/class-ucp-storage.php';
}
require_once __DIR__ . '/../includes/admin/class-deadletter-admin.php';

/**
 * Thrown by the wp_send_json_* / wp_die stubs in place of the real
 * exit, so each AJAX call can be asserted against in a test.
 */
final class DeadletterAjaxHalt extends RuntimeException {
	public bool $success;
	public $payload;
	public ?int $status;
	public function __construct( bool $success, $payload, ?int $status = null ) {
		parent::__construct( $success ? 'json_success' : 'json_error' );
		$this->success = $success;
		$this->payload = $payload;
		$this->status  = $status;
	}
}

final class DeadletterAdminTest extends TestCase {

	private DeadletterWpdb $wpdb;

	/**
	 * Whether check_ajax_referer should report a valid nonce.
	 */
	private bool $nonce_valid = true;

	/**
	 * Whether current_user_can('manage_woocommerce') should pass.
	 */
	private bool $cap_ok = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$this->wpdb = new DeadletterWpdb();
		$wpdb       = $this->wpdb;

		Functions\when( 'current_time' )->alias(
			static function ( $fmt, $gmt = 0 ) {
				return gmdate( 'Y-m-d H:i:s' );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		// Capability + nonce gates — flipped by individual tests.
		$nonce_valid = &$this->nonce_valid;
		$cap_ok      = &$this->cap_ok;
		Functions\when( 'check_ajax_referer' )->alias(
			static function ( $action, $field = false, $die = true ) use ( &$nonce_valid ) {
				if ( ! $nonce_valid ) {
					if ( $die ) {
						throw new DeadletterAjaxHalt( false, array( 'message' => 'bad nonce' ), 403 );
					}
					return false;
				}
				return 1;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) use ( &$cap_ok ) {
				return (bool) $cap_ok;
			}
		);

		// wp_send_json_* throw so we can assert the response shape without
		// hitting `exit;`.
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null, $status = null ) {
				throw new DeadletterAjaxHalt( true, $data, $status );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = null ) {
				throw new DeadletterAjaxHalt( false, $data, $status );
			}
		);

		// Cron stub — admin retry pings wp_schedule_single_event so the
		// flush drains in seconds. We don't care about the arg, just that
		// it doesn't fatal.
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function table(): string {
		return UCP_Storage::table( 'webhook_queue' );
	}

	/**
	 * Seed N failed rows. failed_at is offset by $i seconds so DESC order
	 * has the highest id first.
	 */
	private function seed_failed_rows( int $n ): void {
		$table                       = $this->table();
		$this->wpdb->tables[ $table ] = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$this->wpdb->tables[ $table ][ $i ] = array(
				'id'              => $i,
				'subscription_id' => 'sub_' . $i,
				'event_type'      => 'order.created',
				'attempts'        => 5,
				'next_attempt_at' => '2024-01-01 00:00:00',
				'failed_at'       => gmdate( 'Y-m-d H:i:s', strtotime( '2024-01-01 00:00:00' ) + $i ),
				'last_error'      => 'HTTP 500',
				'created_at'      => '2024-01-01 00:00:00',
				'payload'         => '{}',
			);
		}
	}

	// ── Pure helpers ─────────────────────────────────────────────────────

	public function test_retry_row_clears_failed_resets_attempts_and_sets_next_attempt(): void {
		$this->seed_failed_rows( 1 );
		$table = $this->table();

		$affected = WooCommerce_Shopwalk_Admin_Deadletter::retry_row( 1 );
		$this->assertSame( 1, $affected );

		$row = $this->wpdb->tables[ $table ][1];
		$this->assertNull( $row['failed_at'], 'failed_at must be cleared' );
		$this->assertSame( 0, $row['attempts'], 'attempts must reset to 0' );
		$this->assertNull( $row['last_error'], 'last_error must be cleared' );
		$this->assertNotEmpty( $row['next_attempt_at'], 'next_attempt_at must be set' );

		// Within ~5s of "now" so cron picks it up immediately.
		$delta = abs( time() - strtotime( $row['next_attempt_at'] ) );
		$this->assertLessThan( 5, $delta, 'next_attempt_at should be ~now' );
	}

	public function test_discard_row_removes_the_row(): void {
		$this->seed_failed_rows( 2 );
		$table = $this->table();

		$affected = WooCommerce_Shopwalk_Admin_Deadletter::discard_row( 1 );
		$this->assertSame( 1, $affected );

		$this->assertArrayNotHasKey( 1, $this->wpdb->tables[ $table ] );
		$this->assertArrayHasKey( 2, $this->wpdb->tables[ $table ], 'unrelated row preserved' );
	}

	public function test_fetch_failed_orders_by_failed_at_desc_and_respects_limit(): void {
		$this->seed_failed_rows( 5 );

		$rows = WooCommerce_Shopwalk_Admin_Deadletter::fetch_failed( 3 );
		$this->assertCount( 3, $rows );
		// failed_at = base + i, so id 5 is most recent.
		$ids = array_map( static fn( $r ) => (int) $r['id'], $rows );
		$this->assertSame( array( 5, 4, 3 ), $ids );
	}

	// ── AJAX retry ───────────────────────────────────────────────────────

	public function test_ajax_retry_without_valid_nonce_returns_403(): void {
		$this->seed_failed_rows( 1 );
		$_POST['nonce'] = 'wrong';
		$_POST['id']    = '1';
		$this->nonce_valid = false;
		$this->cap_ok      = true;

		try {
			WooCommerce_Shopwalk_Admin_Deadletter::instance()->ajax_retry();
			$this->fail( 'expected halt' );
		} catch ( DeadletterAjaxHalt $halt ) {
			$this->assertFalse( $halt->success );
			$this->assertSame( 403, $halt->status );
		}

		// Row was NOT touched — failed_at preserved.
		$table = $this->table();
		$this->assertNotNull( $this->wpdb->tables[ $table ][1]['failed_at'] );
	}

	public function test_ajax_retry_without_capability_returns_403(): void {
		$this->seed_failed_rows( 1 );
		$_POST['nonce'] = 'ok';
		$_POST['id']    = '1';
		$this->nonce_valid = true;
		$this->cap_ok      = false;

		try {
			WooCommerce_Shopwalk_Admin_Deadletter::instance()->ajax_retry();
			$this->fail( 'expected halt' );
		} catch ( DeadletterAjaxHalt $halt ) {
			$this->assertFalse( $halt->success );
			$this->assertSame( 403, $halt->status );
		}

		$table = $this->table();
		$this->assertNotNull( $this->wpdb->tables[ $table ][1]['failed_at'] );
	}

	public function test_ajax_retry_happy_path_clears_failed_at(): void {
		$this->seed_failed_rows( 1 );
		$_POST['nonce'] = 'ok';
		$_POST['id']    = '1';
		$this->nonce_valid = true;
		$this->cap_ok      = true;

		try {
			WooCommerce_Shopwalk_Admin_Deadletter::instance()->ajax_retry();
			$this->fail( 'expected halt' );
		} catch ( DeadletterAjaxHalt $halt ) {
			$this->assertTrue( $halt->success );
			$this->assertSame( 1, $halt->payload['id'] );
		}

		$table = $this->table();
		$this->assertNull( $this->wpdb->tables[ $table ][1]['failed_at'] );
		$this->assertSame( 0, $this->wpdb->tables[ $table ][1]['attempts'] );
	}

	public function test_ajax_retry_unknown_id_returns_404(): void {
		$this->seed_failed_rows( 1 );
		$_POST['nonce'] = 'ok';
		$_POST['id']    = '999';
		$this->nonce_valid = true;
		$this->cap_ok      = true;

		try {
			WooCommerce_Shopwalk_Admin_Deadletter::instance()->ajax_retry();
			$this->fail( 'expected halt' );
		} catch ( DeadletterAjaxHalt $halt ) {
			$this->assertFalse( $halt->success );
			$this->assertSame( 404, $halt->status );
		}
	}

	// ── AJAX discard ─────────────────────────────────────────────────────

	public function test_ajax_discard_happy_path_deletes_row(): void {
		$this->seed_failed_rows( 2 );
		$_POST['nonce'] = 'ok';
		$_POST['id']    = '1';
		$this->nonce_valid = true;
		$this->cap_ok      = true;

		try {
			WooCommerce_Shopwalk_Admin_Deadletter::instance()->ajax_discard();
			$this->fail( 'expected halt' );
		} catch ( DeadletterAjaxHalt $halt ) {
			$this->assertTrue( $halt->success );
		}

		$table = $this->table();
		$this->assertArrayNotHasKey( 1, $this->wpdb->tables[ $table ] );
	}

	public function test_ajax_discard_without_valid_nonce_returns_403(): void {
		$this->seed_failed_rows( 1 );
		$_POST['nonce'] = 'wrong';
		$_POST['id']    = '1';
		$this->nonce_valid = false;
		$this->cap_ok      = true;

		try {
			WooCommerce_Shopwalk_Admin_Deadletter::instance()->ajax_discard();
			$this->fail( 'expected halt' );
		} catch ( DeadletterAjaxHalt $halt ) {
			$this->assertFalse( $halt->success );
			$this->assertSame( 403, $halt->status );
		}

		$table = $this->table();
		$this->assertArrayHasKey( 1, $this->wpdb->tables[ $table ] );
	}
}

/**
 * In-memory $wpdb covering the operations the deadletter helpers issue:
 *  - get_results with a SELECT from webhook_queue WHERE failed_at IS NOT NULL,
 *    ORDER BY failed_at DESC LIMIT %d
 *  - get_var with COUNT(*) FROM webhook_queue WHERE failed_at IS NOT NULL
 *  - update WHERE id = %d  (retry_row)
 *  - delete WHERE id = %d  (discard_row)
 *  - query "UPDATE … WHERE failed_at IS NOT NULL"  (retry_all)
 *  - query "DELETE FROM … WHERE failed_at IS NOT NULL"  (discard_all)
 */
final class DeadletterWpdb { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

	/** @var array<string, array<int, array<string,mixed>>> */
	public array $tables = array();
	public string $prefix = 'wp_';

	private array $last_args   = array();
	private string $last_query = '';

	public function prepare( string $query, ...$args ): string {
		$this->last_query = $query;
		$this->last_args  = $args;
		return $query;
	}

	public function get_results( string $query, $output = ARRAY_A ): array {
		$this->last_query = $query;
		if ( ! preg_match( '/FROM\s+(\S+)/i', $query, $m ) ) {
			return array();
		}
		$table = $m[1];
		$rows  = $this->tables[ $table ] ?? array();

		if ( stripos( $query, 'WHERE failed_at IS NOT NULL' ) !== false ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( $r ) => null !== ( $r['failed_at'] ?? null )
				)
			);
		}

		// ORDER BY failed_at DESC.
		if ( stripos( $query, 'ORDER BY failed_at DESC' ) !== false ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					return strcmp( (string) ( $b['failed_at'] ?? '' ), (string) ( $a['failed_at'] ?? '' ) );
				}
			);
		}

		// LIMIT %d  → first arg.
		if ( stripos( $query, 'LIMIT' ) !== false && isset( $this->last_args[0] ) ) {
			$limit = (int) $this->last_args[0];
			if ( $limit > 0 ) {
				$rows = array_slice( $rows, 0, $limit );
			}
		}

		return $rows;
	}

	public function get_var( string $query ) {
		$this->last_query = $query;
		if ( preg_match( '/FROM\s+(\S+)/i', $query, $m ) ) {
			$table = $m[1];
			$rows  = $this->tables[ $table ] ?? array();
			if ( stripos( $query, 'WHERE failed_at IS NOT NULL' ) !== false ) {
				$rows = array_filter(
					$rows,
					static fn( $r ) => null !== ( $r['failed_at'] ?? null )
				);
			}
			return (string) count( $rows );
		}
		return null;
	}

	public function get_row( string $query, $output = ARRAY_A ) {
		return null;
	}

	public function insert( string $table, array $data ): int {
		$id = (int) ( $data['id'] ?? ( count( $this->tables[ $table ] ?? array() ) + 1 ) );
		$this->tables[ $table ][ $id ] = $data;
		return 1;
	}

	public function update( string $table, array $data, array $where ): int {
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->tables[ $table ][ $id ] ) ) {
			return 0;
		}
		$this->tables[ $table ][ $id ] = array_merge(
			$this->tables[ $table ][ $id ],
			$data
		);
		return 1;
	}

	public function delete( string $table, array $where ): int {
		$id = (int) ( $where['id'] ?? 0 );
		if ( ! isset( $this->tables[ $table ][ $id ] ) ) {
			return 0;
		}
		unset( $this->tables[ $table ][ $id ] );
		return 1;
	}

	public function query( string $query ): int {
		// Naive UPDATE … WHERE failed_at IS NOT NULL handler used by retry_all.
		if ( preg_match( '/^UPDATE\s+(\S+)/i', $query, $m ) ) {
			$table = $m[1];
			$rows  = $this->tables[ $table ] ?? array();
			$count = 0;
			foreach ( $rows as $id => $row ) {
				if ( null === ( $row['failed_at'] ?? null ) ) {
					continue;
				}
				$this->tables[ $table ][ $id ]['failed_at']       = null;
				$this->tables[ $table ][ $id ]['attempts']        = 0;
				$this->tables[ $table ][ $id ]['last_error']      = null;
				$this->tables[ $table ][ $id ]['next_attempt_at'] = gmdate( 'Y-m-d H:i:s' );
				++$count;
			}
			return $count;
		}
		if ( preg_match( '/^DELETE FROM\s+(\S+)/i', $query, $m ) ) {
			$table = $m[1];
			$rows  = $this->tables[ $table ] ?? array();
			$count = 0;
			foreach ( $rows as $id => $row ) {
				if ( null === ( $row['failed_at'] ?? null ) ) {
					continue;
				}
				unset( $this->tables[ $table ][ $id ] );
				++$count;
			}
			return $count;
		}
		return 0;
	}
}
