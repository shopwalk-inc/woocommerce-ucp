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
 * @package WooCommerceUCP
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

		$result = UCP_OAuth_Clients::register( array(
			'name'           => $name,
			'redirect_uris'  => array( $redirect_uri ),
			'scopes_allowed' => $scope_array,
		) );

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
				'Redirect URI' => implode( ', ', $uris ?: array() ),
				'Scopes'       => implode( ', ', $scopes ?: array() ),
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
		$wpdb->query( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$token_table} SET revoked_at = %s WHERE client_id = %s AND revoked_at IS NULL",
			current_time( 'mysql', true ),
			$client_id
		) );

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
