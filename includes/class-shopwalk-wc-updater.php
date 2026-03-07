<?php
/**
 * Auto-updater — checks shopwalk.com for plugin updates.
 *
 * Hooks into WP's plugin update transient and plugins_api filter so the plugin
 * appears in the standard WP Updates screen and can be updated with one click.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Updater class.
 */
class Shopwalk_WC_Updater {

	/**
	 * Instance.
	 *
	 * @var self
	 */
	private static ?self $instance = null;
	/**
	 * Plugin Slug.
	 *
	 * @var string
	 */
	private string $plugin_slug = 'shopwalk-ai';
	/**
	 * Plugin File.
	 *
	 * @var string
	 */
	private string $plugin_file;
	/**
	 * Update Url.
	 *
	 * @var string
	 */
	private string $update_url = 'https://api.shopwalk.com/api/v1/plugin/check-update';

	/**
	 * Get or create the singleton instance.
	 *
	 * @return self Singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct.
	 */
	private function __construct() {
		$this->plugin_file = $this->plugin_slug . '/' . $this->plugin_slug . '.php';
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
	}

	/**
	 * Compare installed version vs remote and inject update info if needed.
	 *
	 * @param mixed $transient Parameter.
	 */
	public function check_for_update( mixed $transient ): mixed {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->fetch_remote_info();
		if ( empty( $remote ) || empty( $remote['new_version'] ) ) {
			return $transient;
		}

		$installed_version = $transient->checked[ $this->plugin_file ] ?? SHOPWALK_AI_VERSION;
		if ( version_compare( $remote['new_version'], $installed_version, '>' ) ) {
			$transient->response[ $this->plugin_file ] = (object) array(
				'id'          => $this->plugin_slug . '/' . $this->plugin_slug . '.php',
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_file,
				'new_version' => $remote['new_version'],
				'url'         => $remote['url'] ?? 'https://shopwalk.com/plugin',
				'package'     => $remote['package'] ?? '',
				'icons'       => array(),
				'banners'     => array(),
			);
		}

		return $transient;
	}

	/**
	 * Provide plugin details for the "View version X details" popup.
	 *
	 * @param mixed  $result Parameter.
	 * @param string $action Parameter.
	 * @param object $args Parameter.
	 */
	public function plugin_info( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$remote = $this->fetch_remote_info();
		if ( empty( $remote ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Shopwalk AI',
			'slug'          => $this->plugin_slug,
			'version'       => $remote['new_version'] ?? SHOPWALK_AI_VERSION,
			'author'        => '<a href="https://shopwalk.com">Shopwalk, Inc.</a>',
			'homepage'      => 'https://shopwalk.com/plugin',
			'download_link' => $remote['package'] ?? '',
			'requires'      => $remote['requires'] ?? '6.0',
			'tested'        => $remote['tested'] ?? '6.7',
			'requires_php'  => $remote['requires_php'] ?? '8.0',
			'sections'      => $remote['sections'] ?? array(),
		);
	}

	/**
	 * Fetch version info from Shopwalk API (cached 12h).
	 */
	private function fetch_remote_info(): ?array {
		$cache_key = 'shopwalk_wc_update_info';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$plugin_key = get_option( 'shopwalk_wc_plugin_key', '' );
		$url        = add_query_arg( 'plugin_key', $plugin_key, $this->update_url );

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data ) ) {
			return null;
		}

		set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}
}
