<?php
/**
 * WP-CLI commands for Shopwalk UCP OAuth client management.
 *
 * Commands:
 *   wp shopwalk client create --name="My Agent" --redirect-uri="https://example.com/callback"
 *   wp shopwalk client list
 *   wp shopwalk client delete <client_id>
 *   wp shopwalk client rotate-secret <client_id>
 *
 * @package ShopwalkWooCommerce
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class UCP_CLI {

	/**
	 * Create a new OAuth client.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Client display name.
	 *
	 * --redirect-uri=<uri>
	 * : Allowed redirect URI.
	 *
	 * [--scopes=<scopes>]
	 * : Comma-separated scopes (default: ucp:checkout,ucp:orders,ucp:webhooks).
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopwalk client create --name="Shopwalk Agent" --redirect-uri="https://shopwalk.com/callback"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function create( array $args, array $assoc_args ): void {
		$name         = $assoc_args['name'] ?? '';
		$redirect_uri = $assoc_args['redirect-uri'] ?? '';
		$scopes       = $assoc_args['scopes'] ?? 'ucp:checkout,ucp:orders,ucp:webhooks';

		if ( empty( $name ) || empty( $redirect_uri ) ) {
			WP_CLI::error( '--name and --redirect-uri are required.' );
		}

		$scope_array = array_map( 'trim', explode( ',', $scopes ) );

		$result = UCP_OAuth_Clients::register(
			array(
				'name'           => $name,
				'redirect_uris'  => array( $redirect_uri ),
				'scopes_allowed' => $scope_array,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Client created.' );
		WP_CLI::line( '' );
		WP_CLI::line( "  Client ID:     {$result['client_id']}" );
		WP_CLI::line( "  Client Secret: {$result['client_secret']}" );
		WP_CLI::line( "  Redirect URI:  {$redirect_uri}" );
		WP_CLI::line( "  Scopes:        {$scopes}" );
		WP_CLI::line( '' );
		WP_CLI::warning( 'Save the client secret now — it cannot be retrieved again.' );
	}

	/**
	 * List all OAuth clients.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopwalk client list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		global $wpdb;
		$table = UCP_Storage::table( 'oauth_clients' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$clients = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from UCP_Storage::table(), not user input.
			"SELECT client_id, name, redirect_uris, scopes_allowed, created_at FROM {$table} ORDER BY created_at DESC",
			ARRAY_A
		);

		if ( empty( $clients ) ) {
			WP_CLI::line( 'No OAuth clients found.' );
			return;
		}

		$rows = array();
		foreach ( $clients as $c ) {
			$uris   = json_decode( $c['redirect_uris'] ?? '[]', true );
			$scopes = json_decode( $c['scopes_allowed'] ?? '[]', true );
			$rows[] = array(
				'Client ID'    => $c['client_id'],
				'Name'         => $c['name'],
				'Redirect URI' => implode( ', ', $uris ? $uris : array() ),
				'Scopes'       => implode( ', ', $scopes ? $scopes : array() ),
				'Created'      => $c['created_at'],
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'Client ID', 'Name', 'Redirect URI', 'Scopes', 'Created' ) );
	}

	/**
	 * Delete an OAuth client.
	 *
	 * ## OPTIONS
	 *
	 * <client_id>
	 * : The client_id to delete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopwalk client delete agt_abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function delete( array $args, array $assoc_args ): void {
		global $wpdb;
		$client_id = $args[0] ?? '';

		if ( empty( $client_id ) ) {
			WP_CLI::error( 'client_id is required.' );
		}

		$table = UCP_Storage::table( 'oauth_clients' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete( $table, array( 'client_id' => $client_id ) );

		if ( ! $deleted ) {
			WP_CLI::error( "Client '{$client_id}' not found." );
		}

		// Also revoke all tokens for this client
		$token_table = UCP_Storage::table( 'oauth_tokens' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$token_table} SET revoked_at = %s WHERE client_id = %s AND revoked_at IS NULL",
				current_time( 'mysql', true ),
				$client_id
			)
		);

		WP_CLI::success( "Client '{$client_id}' deleted and all tokens revoked." );
	}

	/**
	 * Rotate an OAuth client's secret.
	 *
	 * ## OPTIONS
	 *
	 * <client_id>
	 * : The client_id to rotate.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopwalk client rotate-secret agt_abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function rotate_secret( array $args, array $assoc_args ): void {
		$client_id = $args[0] ?? '';

		if ( empty( $client_id ) ) {
			WP_CLI::error( 'client_id is required.' );
		}

		$result = UCP_OAuth_Clients::rotate_secret( $client_id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Client secret rotated.' );
		WP_CLI::line( '' );
		WP_CLI::line( "  Client ID:     {$client_id}" );
		WP_CLI::line( "  New Secret:    {$result['client_secret']}" );
		WP_CLI::line( '' );
		WP_CLI::warning( 'Save the new secret now — the old secret is immediately invalid.' );
	}
}

WP_CLI::add_command( 'shopwalk client', 'UCP_CLI' );

/**
 * WP-CLI commands for inspecting and managing the outbound webhook
 * dead-letter queue (F-D-6).
 *
 * Commands:
 *   wp shopwalk webhooks deadletter list [--limit=<N>] [--format=<table|json|csv>]
 *   wp shopwalk webhooks deadletter retry <id-or-all>
 *   wp shopwalk webhooks deadletter discard <id-or-all>
 *
 * The admin UI at Tools → Failed Webhooks shows the most recent 50; the
 * CLI is the bulk-ops path for ops engineers.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Co-located with the primary CLI command class for the same subsystem.
class UCP_Webhook_Deadletter_CLI {

	/**
	 * List failed webhook deliveries.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max rows to return. Default: 100. Pass 0 for "no limit".
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format. Accepts table, json, csv, yaml, ids. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopwalk webhooks deadletter list
	 *     wp shopwalk webhooks deadletter list --limit=500 --format=json
	 *
	 * @subcommand deadletter-list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function deadletter_list( array $args, array $assoc_args ): void {
		global $wpdb;
		$queue = UCP_Storage::table( 'webhook_queue' );
		$limit = (int) ( $assoc_args['limit'] ?? 100 );
		$fmt   = (string) ( $assoc_args['format'] ?? 'table' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $queue from UCP_Storage::table().
		if ( $limit > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, subscription_id, event_type, attempts, failed_at, last_error
					 FROM {$queue}
					 WHERE failed_at IS NOT NULL
					 ORDER BY failed_at DESC
					 LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				"SELECT id, subscription_id, event_type, attempts, failed_at, last_error
				 FROM {$queue}
				 WHERE failed_at IS NOT NULL
				 ORDER BY failed_at DESC",
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			WP_CLI::line( __( 'No failed webhook deliveries.', 'shopwalk-for-woocommerce' ) );
			return;
		}

		WP_CLI\Utils\format_items(
			$fmt,
			$rows,
			array( 'id', 'subscription_id', 'event_type', 'attempts', 'failed_at', 'last_error' )
		);
	}

	/**
	 * Retry one or all failed webhook deliveries.
	 *
	 * Clears failed_at, resets attempts to 0, sets next_attempt_at = NOW
	 * so the next cron tick (or the single-event scheduled here) picks
	 * the row up.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The queue row id to retry, or "all" to retry every failed row.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopwalk webhooks deadletter retry 42
	 *     wp shopwalk webhooks deadletter retry all
	 *
	 * @subcommand deadletter-retry
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function deadletter_retry( array $args, array $assoc_args ): void {
		$target = $args[0] ?? '';
		if ( '' === $target ) {
			WP_CLI::error( __( 'Pass a queue row id, or "all".', 'shopwalk-for-woocommerce' ) );
		}

		if ( 'all' === $target ) {
			$n = WooCommerce_Shopwalk_Admin_Deadletter::retry_all();
			if ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time() + 5, 'shopwalk_ucp_webhook_flush' );
			}
			WP_CLI::success(
				sprintf(
					/* translators: %d: number of rows requeued. */
					_n( 'Requeued %d row.', 'Requeued %d rows.', $n, 'shopwalk-for-woocommerce' ),
					$n
				)
			);
			return;
		}

		$row_id = (int) $target;
		if ( $row_id <= 0 ) {
			WP_CLI::error( __( 'Invalid row id.', 'shopwalk-for-woocommerce' ) );
		}
		$n = WooCommerce_Shopwalk_Admin_Deadletter::retry_row( $row_id );
		if ( $n < 1 ) {
			WP_CLI::error(
				sprintf(
					/* translators: %d: queue row id. */
					__( 'Row %d not found (or already live).', 'shopwalk-for-woocommerce' ),
					$row_id
				)
			);
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 5, 'shopwalk_ucp_webhook_flush' );
		}
		WP_CLI::success(
			sprintf(
				/* translators: %d: queue row id. */
				__( 'Row %d requeued.', 'shopwalk-for-woocommerce' ),
				$row_id
			)
		);
	}

	/**
	 * Discard one or all failed webhook deliveries.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The queue row id to delete, or "all" to delete every failed row.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopwalk webhooks deadletter discard 42
	 *     wp shopwalk webhooks deadletter discard all
	 *
	 * @subcommand deadletter-discard
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function deadletter_discard( array $args, array $assoc_args ): void {
		$target = $args[0] ?? '';
		if ( '' === $target ) {
			WP_CLI::error( __( 'Pass a queue row id, or "all".', 'shopwalk-for-woocommerce' ) );
		}

		if ( 'all' === $target ) {
			$n = WooCommerce_Shopwalk_Admin_Deadletter::discard_all();
			WP_CLI::success(
				sprintf(
					/* translators: %d: number of rows deleted. */
					_n( 'Discarded %d row.', 'Discarded %d rows.', $n, 'shopwalk-for-woocommerce' ),
					$n
				)
			);
			return;
		}

		$row_id = (int) $target;
		if ( $row_id <= 0 ) {
			WP_CLI::error( __( 'Invalid row id.', 'shopwalk-for-woocommerce' ) );
		}
		$n = WooCommerce_Shopwalk_Admin_Deadletter::discard_row( $row_id );
		if ( $n < 1 ) {
			WP_CLI::error(
				sprintf(
					/* translators: %d: queue row id. */
					__( 'Row %d not found.', 'shopwalk-for-woocommerce' ),
					$row_id
				)
			);
		}
		WP_CLI::success(
			sprintf(
				/* translators: %d: queue row id. */
				__( 'Row %d discarded.', 'shopwalk-for-woocommerce' ),
				$row_id
			)
		);
	}
}

// Register each subcommand against an instance method via a closure so
// the user-facing path is `wp shopwalk webhooks deadletter <verb>`
// rather than the `class-method` shape WP-CLI auto-routes.
$ucp_dlq_cli = new UCP_Webhook_Deadletter_CLI();

WP_CLI::add_command(
	'shopwalk webhooks deadletter list',
	function ( array $args, array $assoc_args ) use ( $ucp_dlq_cli ): void {
		$ucp_dlq_cli->deadletter_list( $args, $assoc_args );
	},
	array(
		'shortdesc' => 'List failed webhook deliveries (rows with failed_at NOT NULL).',
		'synopsis'  => array(
			array(
				'type'        => 'assoc',
				'name'        => 'limit',
				'description' => 'Max rows to return (0 for no limit).',
				'optional'    => true,
				'default'     => 100,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'format',
				'description' => 'Output format.',
				'optional'    => true,
				'default'     => 'table',
				'options'     => array( 'table', 'json', 'csv', 'yaml', 'ids' ),
			),
		),
	)
);

WP_CLI::add_command(
	'shopwalk webhooks deadletter retry',
	function ( array $args, array $assoc_args ) use ( $ucp_dlq_cli ): void {
		$ucp_dlq_cli->deadletter_retry( $args, $assoc_args );
	},
	array(
		'shortdesc' => 'Requeue one failed webhook delivery (or all of them).',
		'synopsis'  => array(
			array(
				'type'        => 'positional',
				'name'        => 'id',
				'description' => 'Queue row id, or "all" for every failed row.',
				'optional'    => false,
			),
		),
	)
);

WP_CLI::add_command(
	'shopwalk webhooks deadletter discard',
	function ( array $args, array $assoc_args ) use ( $ucp_dlq_cli ): void {
		$ucp_dlq_cli->deadletter_discard( $args, $assoc_args );
	},
	array(
		'shortdesc' => 'Permanently delete one failed webhook delivery (or all of them).',
		'synopsis'  => array(
			array(
				'type'        => 'positional',
				'name'        => 'id',
				'description' => 'Queue row id, or "all" for every failed row.',
				'optional'    => false,
			),
		),
	)
);
