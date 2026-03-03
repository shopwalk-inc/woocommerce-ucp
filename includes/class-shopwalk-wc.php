<?php
/**
 * Main plugin class — singleton orchestrator.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined('ABSPATH') || exit;

class Shopwalk_WC {

    private static ?Shopwalk_WC $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Cloning is forbidden.
     */
    public function __clone() {
        wc_doing_it_wrong(__FUNCTION__, 'Cloning is forbidden.', '1.0.0');
    }

    /**
     * Unserializing is forbidden.
     */
    public function __wakeup() {
        wc_doing_it_wrong(__FUNCTION__, 'Unserializing is forbidden.', '1.0.0');
    }

    private function init_hooks(): void {
        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_routes']);

        // Register /.well-known/ucp rewrite
        add_action('init', [$this, 'register_well_known']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_well_known']);

        // Admin settings + dashboard widget
        if (is_admin()) {
            Shopwalk_WC_Settings::instance();
            Shopwalk_WC_Dashboard::instance();
        }

        // Auto-updater (checks shopwalk.com for plugin updates)
        Shopwalk_WC_Updater::instance();

        // Register order webhook listeners
        new Shopwalk_WC_Webhooks();

        // Add version header to all Shopwalk REST responses
        add_filter('rest_post_dispatch', function($result, $server, $request) {
            $route = $request->get_route();
            if (strpos($route, '/shopwalk-wc/') !== false || strpos($route, '/shopwalk/') !== false) {
                $result->header('X-Shopwalk-WC-Version', SHOPWALK_AI_VERSION);
                $result->header('X-UCP-Version', '1.0');
            }
            return $result;
        }, 10, 3);
    }

    /**
     * Register REST API routes.
     *
     * Routes are registered under two namespaces:
     *   - shopwalk-wc/v1  (legacy — preserves backward compatibility)
     *   - shopwalk/v1     (UCP-standard path)
     */
    public function register_routes(): void {
        $namespaces = ['shopwalk-wc/v1', 'shopwalk/v1'];

        foreach ($namespaces as $namespace) {
            // Products / Catalog + Availability
            $products = new Shopwalk_WC_Products();
            $products->register_routes($namespace);

            // Checkout Sessions
            $checkout = new Shopwalk_WC_Checkout();
            $checkout->register_routes($namespace);

            // Orders + Refunds
            $orders = new Shopwalk_WC_Orders();
            $orders->register_routes($namespace);        }
    }

    /**
     * Register the /.well-known/ucp rewrite rule.
     */
    public function register_well_known(): void {
        add_rewrite_rule(
            '^\.well-known/ucp/?$',
            'index.php?shopwalk_wc_well_known=1',
            'top'
        );
    }

    public function add_query_vars(array $vars): array {
        $vars[] = 'shopwalk_wc_well_known';
        return $vars;
    }

    /**
     * Serve the Business Profile at /.well-known/ucp
     */
    public function handle_well_known(): void {
        if (!get_query_var('shopwalk_wc_well_known')) {
            return;
        }

        $profile = Shopwalk_WC_Profile::get_business_profile();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=60');
        header('Access-Control-Allow-Origin: *');
        echo wp_json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
