<?php
/**
 * Shopwalk_Updater — self-hosted plugin auto-update via shopwalk-api.
 *
 * Hooks into WordPress's plugin update system to check for new versions
 * from the Shopwalk API. When a newer version is available, WordPress
 * shows the update notification in WP Admin and allows one-click update.
 *
 * Also enables WordPress automatic background updates for this plugin.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

final class Shopwalk_Updater {

	/**
	 * API endpoint that returns { version, download_url, changelog }.
	 */
	private const VERSION_ENDPOINT = SHOPWALK_API_BASE . '/plugin/version';

	/**
	 * Cache key for the version check.
	 */
	private const CACHE_KEY = 'shopwalk_plugin_update_check';

	/**
	 * Cache TTL — check at most every 4 hours.
	 */
	private const CACHE_TTL = 4 * HOUR_IN_SECONDS;

	/**
	 * Plugin basename (e.g. woocommerce-ucp/woocommerce-ucp.php).
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
		$this->plugin_basename = plugin_basename( WOOCOMMERCE_UCP_PLUGIN_FILE );
		$this->plugin_slug     = dirname( $this->plugin_basename );

		// Inject update info into WordPress's update check.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

		// Provide plugin info for the "View Details" popup.
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );

		// Fix directory name after extracting the update zip.
		add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );

		// Add license key to the download URL so WordPress's updater can authenticate.
		add_filter( 'upgrader_pre_download', array( $this, 'add_auth_to_download' ), 10, 3 );

		// Enable automatic updates for this plugin.
		add_filter( 'auto_update_plugin', array( $this, 'enable_auto_update' ), 10, 2 );

		// Clear the update cache when the plugin is updated.
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Check for plugin updates.
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

		$current_version = WOOCOMMERCE_UCP_VERSION;

		if ( version_compare( $remote['version'], $current_version, '>' ) ) {
			// Build download URL with license key as query param so WP's
			// built-in updater can authenticate (it doesn't send custom headers).
			$download_url = $this->get_download_url();

			$transient->response[ $this->plugin_basename ] = (object) array(
				'slug'         => $this->plugin_slug,
				'plugin'       => $this->plugin_basename,
				'new_version'  => $remote['version'],
				'package'      => $download_url,
				'url'          => 'https://shopwalk.com/woocommerce',
				'tested'       => $remote['tested_wp'] ?? '6.7',
				'requires'     => '6.0',
				'requires_php' => '8.0',
				'icons'        => array(
					'default' => 'https://shopwalk.com/icon-256x256.png',
				),
			);
		} else {
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
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== $this->plugin_slug ) {
			return $result;
		}

		$remote = $this->get_remote_version();
		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WooCommerce UCP — Universal Commerce Protocol',
			'slug'          => $this->plugin_slug,
			'version'       => $remote['version'] ?? WOOCOMMERCE_UCP_VERSION,
			'author'        => '<a href="https://shopwalk.com">Shopwalk, Inc.</a>',
			'homepage'      => 'https://shopwalk.com/woocommerce',
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'tested'        => $remote['tested_wp'] ?? '6.7',
			'download_link' => $this->get_download_url(),
			'sections'      => array(
				'description' => 'Make your WooCommerce store discoverable and purchasable by AI shopping agents. Implements the Universal Commerce Protocol (UCP).',
				'changelog'   => $remote['changelog'] ?? '<p>See <a href="https://github.com/shopwalk-inc/woocommerce-ucp/releases">GitHub releases</a> for the full changelog.</p>',
			),
			'banners'       => array(
				'high' => 'https://shopwalk.com/banner-1544x500.png',
				'low'  => 'https://shopwalk.com/banner-772x250.png',
			),
		);
	}

	/**
	 * Fix the extracted directory name after update.
	 *
	 * GitHub release zips extract to various directory names.
	 * WordPress expects the directory to match the existing plugin slug.
	 *
	 * @param string $source        File source location.
	 * @param string $remote_source Remote file source location.
	 * @param object $upgrader      WP_Upgrader instance.
	 * @param array  $hook_extra    Extra arguments.
	 * @return string|WP_Error
	 */
	public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		global $wp_filesystem;
		$expected = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

		if ( $source !== $expected && $wp_filesystem && $wp_filesystem->exists( $source ) ) {
			$wp_filesystem->move( $source, $expected );
			return $expected;
		}

		return $source;
	}

	/**
	 * Intercept the download to add authentication headers.
	 *
	 * WordPress's built-in updater calls download_url() which does a plain GET.
	 * We intercept and add the license key as a query parameter.
	 *
	 * @param bool|WP_Error $reply    Whether to bail without returning the package.
	 * @param string        $package  The package URL.
	 * @param object        $upgrader The WP_Upgrader instance.
	 * @return bool|WP_Error
	 */
	public function add_auth_to_download( $reply, $package, $upgrader ) {
		// Only intercept our own plugin downloads.
		if ( ! str_contains( $package, 'api.shopwalk.com' ) ) {
			return $reply;
		}

		// The URL already has the license key as a query param (set in get_authenticated_download_url).
		// WordPress will download it normally. No interception needed.
		return $reply;
	}

	/**
	 * Enable automatic updates for this plugin.
	 *
	 * @param bool|null $update Whether to update. Null = use default.
	 * @param object    $item   The plugin item being evaluated.
	 * @return bool|null
	 */
	public function enable_auto_update( $update, $item ) {
		if ( isset( $item->slug ) && $item->slug === $this->plugin_slug ) {
			return true; // Always auto-update WooCommerce UCP plugin.
		}
		return $update;
	}

	/**
	 * Clear the cached version check after an update completes.
	 *
	 * @param object $upgrader WP_Upgrader instance.
	 * @param array  $options  Upgrade options.
	 * @return void
	 */
	public function clear_cache( $upgrader, $options ): void {
		if ( 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Get the download URL from the cached version check.
	 *
	 * The repo is public — the download URL is a direct GitHub release
	 * asset link. No authentication needed.
	 *
	 * @return string
	 */
	private function get_download_url(): string {
		$remote = $this->get_remote_version();
		if ( $remote && ! empty( $remote['download_url'] ) ) {
			return $remote['download_url'];
		}
		// Fallback: latest release asset directly from GitHub.
		return 'https://github.com/shopwalk-inc/woocommerce-ucp/releases/latest/download/woocommerce-ucp.zip';
	}

	/**
	 * Fetch the latest version info from shopwalk-api. Cached.
	 *
	 * @return array|null { version, download_url, changelog, tested_wp }
	 */
	private function get_remote_version(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$headers = array(
			'User-Agent' => 'woocommerce-ucp-plugin/' . WOOCOMMERCE_UCP_VERSION,
		);

		// Include license key for authenticated version check.
		if ( class_exists( 'Shopwalk_License' ) && Shopwalk_License::key() ) {
			$headers['X-API-Key'] = Shopwalk_License::key();
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
