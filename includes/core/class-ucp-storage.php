<?php
/**
 * UCP Storage — owns the custom DB tables and provides typed accessors.
 *
 * Tables (all `wp_ucp_*` per spec):
 *  - oauth_clients         OAuth 2.0 client registry (one per agent)
 *  - oauth_tokens          access + refresh + authorization_code tokens
 *  - checkout_sessions     UCP Checkout Object state
 *  - webhook_subscriptions agent subscriptions to order events
 *  - webhook_queue         outbound webhook delivery queue
 *
 * Schema is created on plugin activation via dbDelta(). install() is
 * idempotent — safe to call repeatedly.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Storage — DDL + low-level table accessors.
 */
final class UCP_Storage {

	/**
	 * Returns the prefixed table name for a given short name.
	 *
	 * @param string $short e.g. "oauth_clients", "checkout_sessions".
	 * @return string Full table name including the WP table prefix.
	 */
	public static function table( string $short ): string {
		global $wpdb;
		return $wpdb->prefix . UCP_TABLE_PREFIX . $short;
	}

	/**
	 * Create or upgrade all UCP tables. Called on plugin activation.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$clients          = self::table( 'oauth_clients' );
		$tokens           = self::table( 'oauth_tokens' );
		$sessions         = self::table( 'checkout_sessions' );
		$subscriptions    = self::table( 'webhook_subscriptions' );
		$queue            = self::table( 'webhook_queue' );

		dbDelta(
			"CREATE TABLE {$clients} (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				client_id       VARCHAR(64) NOT NULL,
				client_secret   VARCHAR(128) NOT NULL,
				name            VARCHAR(255) NOT NULL,
				redirect_uris   TEXT NOT NULL,
				scopes_allowed  TEXT NOT NULL,
				signing_jwk     TEXT,
				ucp_profile_url VARCHAR(512),
				created_at      DATETIME NOT NULL,
				updated_at      DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$tokens} (
				id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				token_type             VARCHAR(20) NOT NULL,
				token_hash             VARCHAR(128) NOT NULL,
				client_id              VARCHAR(64) NOT NULL,
				user_id                BIGINT UNSIGNED NOT NULL,
				scopes                 TEXT NOT NULL,
				code_challenge         VARCHAR(128),
				code_challenge_method  VARCHAR(10),
				expires_at             DATETIME NOT NULL,
				revoked_at             DATETIME NULL,
				created_at             DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY client_user (client_id, user_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$sessions} (
				id              VARCHAR(64) NOT NULL,
				client_id       VARCHAR(64) NOT NULL,
				user_id         BIGINT UNSIGNED NULL,
				status          VARCHAR(32) NOT NULL,
				line_items      LONGTEXT NOT NULL,
				buyer           LONGTEXT,
				fulfillment     LONGTEXT,
				payment         LONGTEXT,
				totals          LONGTEXT,
				messages        LONGTEXT,
				wc_order_id     BIGINT UNSIGNED NULL,
				created_at      DATETIME NOT NULL,
				updated_at      DATETIME NOT NULL,
				expires_at      DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY expires (expires_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$subscriptions} (
				id              VARCHAR(64) NOT NULL,
				client_id       VARCHAR(64) NOT NULL,
				callback_url    VARCHAR(512) NOT NULL,
				event_types     TEXT NOT NULL,
				secret          VARCHAR(128) NOT NULL,
				created_at      DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY client (client_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$queue} (
				id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				subscription_id VARCHAR(64) NOT NULL,
				event_type      VARCHAR(64) NOT NULL,
				payload         LONGTEXT NOT NULL,
				attempts        INT NOT NULL DEFAULT 0,
				next_attempt_at DATETIME NOT NULL,
				delivered_at    DATETIME NULL,
				failed_at       DATETIME NULL,
				last_error      TEXT,
				created_at      DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY next_attempt (next_attempt_at)
			) {$charset};"
		);
	}

	/**
	 * Drop all UCP tables. Used by uninstall.php.
	 *
	 * @return void
	 */
	public static function drop_all(): void {
		global $wpdb;
		foreach ( array( 'webhook_queue', 'webhook_subscriptions', 'checkout_sessions', 'oauth_tokens', 'oauth_clients' ) as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
