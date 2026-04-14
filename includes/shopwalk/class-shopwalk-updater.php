<?php
/**
 * Shopwalk_Updater — self-hosted plugin auto-update via shopwalk-api.
 *
 * Hooks into WordPress's plugin update transient to check for new versions
 * from the Shopwalk API (GET /api/v1/partners/plugin/version). When a newer
 * version is available, WordPress shows the update notification in WP Admin
 * and allows one-click update.
 *
 * The download URL is the GitHub Release asset zip, served through
 * shopwalk-api's authenticated download endpoint.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

final class Shopwalk_Updater {

	/**
	 * API endpoint that returns { version, download_url, changelog }.
	 * Uses the public plugin version endpoint (license-authenticated).
	 */
	private const VERSION_ENDPOINT = SHOPWALK_API_BASE . '/plugin/version';

	/**
	 * Cache key for the version check (avoid hammering the API).
	 */
	private const CACHE_KEY = 'shopwalk_plugin_update_check';

	/**
	 * Cache TTL in seconds (check at most every 6 hours).
	 */
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Plugin basename (e.g. shopwalk-ai/shopwalk-ai.php).
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Plugin slug (directory name).
	 *
	 * @var string
	 */
	private string $plugin_slug;

	/**
	 * Initialize the updater.
	 */
	public function __construct() {
		$this->plugin_basename = plugin_basename( SHOPWALK_AI_PLUGIN_FILE );
		$this->plugin_slug     = dirname( $this->plugin_basename );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );
	}

	/**
	 * Check for plugin updates. Called by WordPress when refreshing the
	 * update_plugins transient.
	 *
	 * @param object $transient The update_plugins transient data.
	 * @return object Modified transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->get_remote_version();
		if ( ! $remote || empty( $remote['version'] ) ) {
			return $transient;
		}

		$current_version = SHOPWALK_AI_VERSION;

		if ( version_compare( $remote['version'], $current_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $remote['version'],
				'package'     => $remote['download_url'] ?? '',
				'url'         => 'https://shopwalk.com/woocommerce',
				'tested'      => $remote['tested_wp'] ?? '6.7',
				'requires'    => '6.0',
				'requires_php' => '8.0',
			);
		} else {
			// Tell WP this plugin is up to date (prevents "update available" false positives).
			$transient->no_update[ $this->plugin_basename ] = (object) array(
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $current_version,
				'url'         => 'https://shopwalk.com/woocommerce',
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the "View Details" popup in WP Admin.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== $this->plugin_slug ) {
			return $result;
		}

		$remote = $this->get_remote_version();
		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'            => 'Shopwalk AI — UCP Commerce Adapter',
			'slug'            => $this->plugin_slug,
			'version'         => $remote['version'] ?? SHOPWALK_AI_VERSION,
			'author'          => '<a href="https://shopwalk.com">Shopwalk, Inc.</a>',
			'homepage'        => 'https://shopwalk.com/woocommerce',
			'requires'        => '6.0',
			'requires_php'    => '8.0',
			'tested'          => $remote['tested_wp'] ?? '6.7',
			'download_link'   => $remote['download_url'] ?? '',
			'sections'        => array(
				'description' => 'Make your WooCommerce store discoverable and purchasable by AI shopping agents. Implements the Universal Commerce Protocol (UCP).',
				'changelog'   => $remote['changelog'] ?? '<p>See <a href="https://github.com/shopwalk-inc/woocommerce-ucp/releases">GitHub releases</a> for the full changelog.</p>',
			),
		);
	}

	/**
	 * Fix the extracted directory name after update.
	 *
	 * GitHub release zips extract to "woocommerce-ucp-main" or similar.
	 * WordPress expects the directory to match the plugin slug.
	 *
	 * @param string $source        File source location.
	 * @param string $remote_source Remote file source location.
	 * @param object $upgrader      WP_Upgrader instance.
	 * @param array  $hook_extra    Extra arguments passed to hooked filters.
	 * @return string|WP_Error
	 */
	public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		global $wp_filesystem;
		$expected = trailingslashit( $remote_source ) . trailingslashit( $this->plugin_slug );

		if ( $source !== $expected && $wp_filesystem ) {
			$wp_filesystem->move( $source, $expected );
			return $expected;
		}

		return $source;
	}

	/**
	 * Fetch the latest version info from shopwalk-api. Cached for 6 hours.
	 *
	 * @return array|null { version, download_url, changelog, tested_wp }
	 */
	private function get_remote_version(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$headers = array(
			'User-Agent' => 'shopwalk-ai-plugin/' . SHOPWALK_AI_VERSION,
		);

		// Include license key for authenticated version check.
		if ( class_exists( 'Shopwalk_License' ) && Shopwalk_License::key() ) {
			$headers['X-SW-License-Key'] = Shopwalk_License::key();
		}

		$response = wp_remote_get(
			self::VERSION_ENDPOINT,
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['version'] ) ) {
			return null;
		}

		$data = array(
			'version'      => $body['version'],
			'download_url' => $body['download_url'] ?? '',
			'changelog'    => $body['changelog'] ?? '',
			'tested_wp'    => $body['tested_wp'] ?? '6.7',
		);

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}
}
