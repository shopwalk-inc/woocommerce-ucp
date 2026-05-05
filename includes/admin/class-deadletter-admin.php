<?php
/**
 * UCP Deadletter Admin — surfaces failed webhook deliveries.
 *
 * F-D-6: rows in {$prefix}ucp_webhook_queue that hit MAX_ATTEMPTS get
 * `failed_at` stamped and parked for forensics. Without an admin/CLI
 * surface, operators are blind to failures unless they pop a SQL
 * client. This class registers a "Failed Webhooks" submenu under the
 * existing UCP top-level menu and two AJAX endpoints for retry/discard
 * row actions.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce_Shopwalk_Admin_Deadletter — submenu + AJAX handlers for the dead-letter queue.
 */
final class WooCommerce_Shopwalk_Admin_Deadletter {

	/**
	 * Cap on rows shown in the admin table. The CLI is the bulk-ops path
	 * for anything beyond this.
	 */
	private const PAGE_LIMIT = 50;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up the submenu + AJAX handlers.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'wp_ajax_ucp_webhook_retry', array( $this, 'ajax_retry' ) );
		add_action( 'wp_ajax_ucp_webhook_discard', array( $this, 'ajax_discard' ) );
	}

	/**
	 * Register the "Failed Webhooks" submenu under the UCP top-level menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'shopwalk-for-woocommerce',
			__( 'Failed Webhooks', 'shopwalk-for-woocommerce' ),
			__( 'Failed Webhooks', 'shopwalk-for-woocommerce' ),
			'manage_woocommerce',
			'ucp-webhook-deadletter',
			array( $this, 'render_page' )
		);
	}

	// ── Query helpers ────────────────────────────────────────────────────

	/**
	 * Fetch up to PAGE_LIMIT failed rows ordered by failed_at DESC.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function fetch_failed( int $limit = self::PAGE_LIMIT ): array {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $queue is from UCP_Storage::table(), not user input.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, subscription_id, event_type, attempts, failed_at, last_error, created_at
				 FROM {$queue}
				 WHERE failed_at IS NOT NULL
				 ORDER BY failed_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count of all failed rows. Used for the "showing N of M" footer.
	 */
	public static function count_failed(): int {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE failed_at IS NOT NULL" );
		return (int) $count;
	}

	/**
	 * Reset a row back into the live queue. Clears failed_at, resets
	 * attempts to 0, and sets next_attempt_at = NOW so the next cron tick
	 * picks it up. Returns rows-affected (0 if the id doesn't exist or is
	 * already live).
	 */
	public static function retry_row( int $row_id ): int {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->update(
			$queue,
			array(
				'failed_at'       => null,
				'attempts'        => 0,
				'next_attempt_at' => current_time( 'mysql', true ),
				'last_error'      => null,
			),
			array( 'id' => $row_id )
		);

		return (int) $affected;
	}

	/**
	 * Retry every currently-failed row. Returns the number of rows reset.
	 */
	public static function retry_all(): int {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $queue is from UCP_Storage::table(), not user input.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$queue}
				 SET failed_at = NULL, attempts = 0, next_attempt_at = %s, last_error = NULL
				 WHERE failed_at IS NOT NULL",
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $affected;
	}

	/**
	 * Permanently discard a single row. Returns rows-affected.
	 */
	public static function discard_row( int $row_id ): int {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->delete(
			$queue,
			array( 'id' => $row_id )
		);

		return (int) $affected;
	}

	/**
	 * Discard every currently-failed row. Returns the number deleted.
	 */
	public static function discard_all(): int {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query( "DELETE FROM {$queue} WHERE failed_at IS NOT NULL" );

		return (int) $affected;
	}

	// ── Page render ──────────────────────────────────────────────────────

	/**
	 * Render the dead-letter listing page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'shopwalk-for-woocommerce' ) );
		}

		$rows  = self::fetch_failed();
		$total = self::count_failed();
		$nonce = wp_create_nonce( 'ucp_webhook_deadletter' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Failed Webhooks', 'shopwalk-for-woocommerce' ); ?></h1>
			<p>
				<?php
				esc_html_e(
					'Outbound webhook deliveries that exhausted all retry attempts (5 by default). Retry to re-queue, or discard to drop permanently. Use WP-CLI ("wp shopwalk webhooks deadletter") for bulk operations.',
					'shopwalk-for-woocommerce'
				);
				?>
			</p>

			<?php if ( empty( $rows ) ) : ?>
				<div class="notice notice-success inline">
					<p><?php esc_html_e( 'No failed webhook deliveries.', 'shopwalk-for-woocommerce' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" id="ucp-deadletter-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Failed', 'shopwalk-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Subscription', 'shopwalk-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Event', 'shopwalk-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Attempts', 'shopwalk-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last error', 'shopwalk-for-woocommerce' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'shopwalk-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$row_id    = (int) ( $row['id'] ?? 0 );
							$failed_at = (string) ( $row['failed_at'] ?? '' );
							$failed_ts = strtotime( $failed_at );
							$ago       = $failed_ts
								? sprintf(
									/* translators: %s: human-readable time difference, e.g. "2 hours". */
									esc_html__( '%s ago', 'shopwalk-for-woocommerce' ),
									human_time_diff( $failed_ts, time() )
								)
								: esc_html__( 'unknown', 'shopwalk-for-woocommerce' );
							$sub_id     = (string) ( $row['subscription_id'] ?? '' );
							$sub_short  = strlen( $sub_id ) > 12 ? substr( $sub_id, 0, 12 ) . '…' : $sub_id;
							$last_error = (string) ( $row['last_error'] ?? '' );
							$err_short  = strlen( $last_error ) > 80 ? substr( $last_error, 0, 80 ) . '…' : $last_error;
							?>
							<tr data-row-id="<?php echo esc_attr( (string) $row_id ); ?>">
								<td>
									<span title="<?php echo esc_attr( $failed_at ); ?>">
										<?php echo esc_html( $ago ); ?>
									</span>
								</td>
								<td>
									<code title="<?php echo esc_attr( $sub_id ); ?>">
										<?php echo esc_html( $sub_short ); ?>
									</code>
								</td>
								<td><?php echo esc_html( (string) ( $row['event_type'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['attempts'] ?? '' ) ); ?></td>
								<td>
									<span title="<?php echo esc_attr( $last_error ); ?>">
										<?php echo esc_html( $err_short ); ?>
									</span>
								</td>
								<td>
									<button type="button" class="button button-small ucp-dlq-retry" data-id="<?php echo esc_attr( (string) $row_id ); ?>">
										<?php esc_html_e( 'Retry', 'shopwalk-for-woocommerce' ); ?>
									</button>
									<button type="button" class="button button-small ucp-dlq-discard" data-id="<?php echo esc_attr( (string) $row_id ); ?>">
										<?php esc_html_e( 'Discard', 'shopwalk-for-woocommerce' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total > self::PAGE_LIMIT ) : ?>
					<p>
						<em>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of rows shown, 2: total failed rows. */
									__( 'Showing %1$d of %2$d total — use WP-CLI for the full list.', 'shopwalk-for-woocommerce' ),
									self::PAGE_LIMIT,
									$total
								)
							);
							?>
						</em>
					</p>
				<?php endif; ?>

				<script>
				(function () {
					var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
					var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
					var msgRetry = <?php echo wp_json_encode( __( 'Retry this delivery?', 'shopwalk-for-woocommerce' ) ); ?>;
					var msgDrop  = <?php echo wp_json_encode( __( 'Discard this row permanently?', 'shopwalk-for-woocommerce' ) ); ?>;
					var msgFail  = <?php echo wp_json_encode( __( 'Action failed.', 'shopwalk-for-woocommerce' ) ); ?>;

					function post(action, id, cb) {
						var data = new URLSearchParams();
						data.append('action', action);
						data.append('nonce', nonce);
						data.append('id', String(id));
						fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
							.then(function (r) { return r.json(); })
							.then(function (resp) { cb(resp && resp.success); })
							.catch(function () { cb(false); });
					}

					document.querySelectorAll('.ucp-dlq-retry').forEach(function (btn) {
						btn.addEventListener('click', function () {
							if (!window.confirm(msgRetry)) { return; }
							var id  = btn.getAttribute('data-id');
							btn.disabled = true;
							post('ucp_webhook_retry', id, function (ok) {
								if (!ok) { btn.disabled = false; alert(msgFail); return; }
								var row = btn.closest('tr');
								if (row) { row.parentNode.removeChild(row); }
							});
						});
					});

					document.querySelectorAll('.ucp-dlq-discard').forEach(function (btn) {
						btn.addEventListener('click', function () {
							if (!window.confirm(msgDrop)) { return; }
							var id  = btn.getAttribute('data-id');
							btn.disabled = true;
							post('ucp_webhook_discard', id, function (ok) {
								if (!ok) { btn.disabled = false; alert(msgFail); return; }
								var row = btn.closest('tr');
								if (row) { row.parentNode.removeChild(row); }
							});
						});
					});
				})();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── AJAX handlers ────────────────────────────────────────────────────

	/**
	 * AJAX: clear a row's failed_at + reset attempts so cron retries it.
	 */
	public function ajax_retry(): void {
		check_ajax_referer( 'ucp_webhook_deadletter', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) ), 403 );
		}

		$row_id = isset( $_POST['id'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['id'] ) ) : 0;
		if ( $row_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing or invalid row id.', 'shopwalk-for-woocommerce' ) ), 400 );
		}

		$affected = self::retry_row( $row_id );
		if ( $affected < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Row not found.', 'shopwalk-for-woocommerce' ) ), 404 );
		}

		// Bump cron so the retry drains within seconds rather than waiting
		// for the next hourly backstop.
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 5, 'shopwalk_ucp_webhook_flush' );
		}

		wp_send_json_success( array( 'id' => $row_id ) );
	}

	/**
	 * AJAX: permanently delete a row.
	 */
	public function ajax_discard(): void {
		check_ajax_referer( 'ucp_webhook_deadletter', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) ), 403 );
		}

		$row_id = isset( $_POST['id'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['id'] ) ) : 0;
		if ( $row_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Missing or invalid row id.', 'shopwalk-for-woocommerce' ) ), 400 );
		}

		$affected = self::discard_row( $row_id );
		if ( $affected < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Row not found.', 'shopwalk-for-woocommerce' ) ), 404 );
		}

		wp_send_json_success( array( 'id' => $row_id ) );
	}
}
