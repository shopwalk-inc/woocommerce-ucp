<?php
/**
 * Plugin Settings — WooCommerce settings tab for Shopwalk configuration.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined('ABSPATH') || exit;

class Shopwalk_WC_Settings {
    private Shopwalk_WC_Dashboard $dashboard;

    private static ?self $instance = null;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_shopwalk', [$this, 'settings_tab']);
        add_action('woocommerce_update_options_shopwalk', [$this, 'update_settings']);

        // Migrate legacy keys on every settings load
        add_action('admin_init', [$this, 'maybe_migrate_legacy_keys']);

        // AJAX handlers (logged-in admin)
        add_action('wp_ajax_shopwalk_wc_sync_all',         [$this, 'ajax_sync_all']);
        add_action('wp_ajax_shopwalk_wc_test_connection',  [$this, 'ajax_test_connection']);
        add_action('wp_ajax_shopwalk_auto_register',       [$this, 'ajax_auto_register']);
        $this->dashboard = new Shopwalk_WC_Dashboard();
        add_action('wp_ajax_shopwalk_save_manual_key',     [$this, 'ajax_save_manual_key']);

        // Enqueue admin JS on our settings tab
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function add_settings_tab(array $tabs): array {
        $tabs['shopwalk'] = __('Shopwalk', 'shopwalk-ai');
        return $tabs;
    }

    public function settings_tab(): void {
        $plugin_key = get_option('shopwalk_wc_plugin_key', '');
        if (empty($plugin_key)) {
            $this->render_connect_screen();
            return;
        }
        // Show dashboard stats at the top — this is the retention hook.
        $this->dashboard->render();
        echo '<hr style="margin:24px 0;">';
        echo '<h3 style="margin-bottom:8px;">' . esc_html__('Plugin Settings', 'shopwalk-ai') . '</h3>';
        woocommerce_admin_fields($this->get_settings());
    }

    /**
     * Render the "Connect your store" screen shown before any key is configured.
     */
    private function render_connect_screen(): void {
        $nonce = wp_create_nonce('shopwalk_auto_register');
        ?>
        <div id="shopwalk-connect-screen" style="max-width:600px;margin:32px 0;background:#fff;border:1px solid #ddd;border-radius:8px;padding:36px;box-shadow:0 1px 4px rgba(0,0,0,.08);">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
                <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="36" height="36" rx="8" fill="#0ea5e9"/>
                    <path d="M10 18a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm8-5a1.5 1.5 0 0 0-1.5 1.5v3.5H13a1.5 1.5 0 0 0 0 3h3.5V25a1.5 1.5 0 0 0 3 0v-3.5H23a1.5 1.5 0 0 0 0-3h-3.5V14.5A1.5 1.5 0 0 0 18 13Z" fill="#fff"/>
                </svg>
                <div>
                    <h2 style="margin:0;font-size:20px;font-weight:700;">Connect to Shopwalk AI</h2>
                    <p style="margin:4px 0 0;color:#666;font-size:13px;">AI-enable your store in seconds — free, no credit card required</p>
                </div>
            </div>

            <ul style="margin:0 0 24px 16px;padding:0;color:#444;font-size:14px;line-height:2;">
                <li>✅ Your products become discoverable by AI agents worldwide</li>
                <li>✅ Full UCP (Universal Commerce Protocol) support — AI can browse and buy</li>
                <li>✅ Real-time product sync as you add or update items</li>
                <li>✅ Free forever — no subscription required</li>
            </ul>

            <button type="button" id="shopwalk-auto-register-btn"
                    class="button button-primary"
                    style="background:#0ea5e9;border-color:#0ea5e9;font-size:15px;padding:8px 24px;height:auto;"
                    data-nonce="<?php echo esc_attr($nonce); ?>">
                Connect to Shopwalk AI — it's free
            </button>

            <p id="shopwalk-register-status" style="margin-top:12px;color:#666;font-size:13px;display:none;"></p>

            <hr style="margin:24px 0;border:none;border-top:1px solid #eee;">
            <p style="margin:0;font-size:12px;color:#999;">
                Already have a key?
                <a href="#" id="shopwalk-show-manual-key" style="color:#0ea5e9;">Enter it manually →</a>
            </p>
            <div id="shopwalk-manual-key-form" style="display:none;margin-top:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;">Plugin Key</label>
                <input type="password" id="shopwalk-manual-key-input" placeholder="sw_site_..." style="width:100%;max-width:400px;">
                <button type="button" id="shopwalk-manual-key-save" class="button button-secondary" style="margin-top:8px;">Save Key</button>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: auto-register this store with Shopwalk and store the returned API key.
     */
    public function ajax_auto_register(): void {
        check_ajax_referer('shopwalk_auto_register', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $site_url   = home_url();
        $store_name = get_bloginfo('name') ?: wp_parse_url($site_url, PHP_URL_HOST);
        $admin_email = get_option('admin_email', '');

        $response = wp_remote_post('https://api.shopwalk.com/api/v1/plugin/register', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'site_url'       => $site_url,
                'store_name'     => $store_name,
                'admin_email'    => $admin_email,
                'wc_version'     => defined('WC_VERSION') ? WC_VERSION : '',
                'plugin_version' => SHOPWALK_AI_VERSION,
            ]),
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Could not reach Shopwalk API: ' . $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['api_key'])) {
            wp_send_json_error(['message' => $body['message'] ?? 'Registration failed. Please try again.']);
        }

        // Store the key and activate
        update_option('shopwalk_wc_plugin_key', $body['api_key']);
        update_option('shopwalk_wc_license_status', 'active');
        if (!empty($body['merchant_id'])) {
            update_option('shopwalk_wc_merchant_id', $body['merchant_id']);
        }
        flush_rewrite_rules();

        wp_send_json_success([
            'message'     => 'Your store is now connected to Shopwalk AI!',
            'merchant_id' => $body['merchant_id'] ?? '',
            'registered'  => $body['registered'] ?? true,
        ]);
    }

    /**
     * Backward-compat migration: if old keys exist and plugin_key is empty, copy value over.
     */
    public function maybe_migrate_legacy_keys(): void {
        $plugin_key  = get_option('shopwalk_wc_plugin_key', '');
        if (!empty($plugin_key)) {
            return; // Already set — nothing to migrate
        }

        // Prefer the old license_key; fall back to shopwalk_api_key
        $old_license = get_option('shopwalk_wc_license_key', '');
        $old_api_key = get_option('shopwalk_wc_shopwalk_api_key', '');
        $migrate     = $old_license ?: $old_api_key;

        if (!empty($migrate)) {
            update_option('shopwalk_wc_plugin_key', $migrate);
        }
    }

    public function update_settings(): void {
        $old_plugin_key = get_option('shopwalk_wc_plugin_key', '');
        woocommerce_update_options($this->get_settings());
        $new_plugin_key = get_option('shopwalk_wc_plugin_key', '');

        // Update grouped option
        $settings = [
            'plugin_key'      => $new_plugin_key,
            'api_key'         => get_option('shopwalk_wc_api_key', ''),
            'shopwalk_api_url'=> 'https://api.shopwalk.com',
            'enable_sync'     => get_option('shopwalk_wc_enable_sync', 'yes'),
            'enable_catalog'  => get_option('shopwalk_wc_enable_catalog', 'yes'),
            'enable_checkout' => get_option('shopwalk_wc_enable_checkout', 'yes'),
            'enable_webhooks' => get_option('shopwalk_wc_enable_webhooks', 'yes'),
        ];
        update_option('shopwalk_wc_settings', $settings);

        // If plugin key was added/changed, activate it with Shopwalk
        if (!empty($new_plugin_key) && $new_plugin_key !== $old_plugin_key) {
            $this->activate_license($new_plugin_key);
        }

        // Flush rewrite rules when settings change
        flush_rewrite_rules();
    }

    /**
     * Call the Shopwalk API to activate the plugin key for this site.
     */
    private function activate_license(string $plugin_key): void {
        $response = wp_remote_post('https://api.shopwalk.com/api/v1/plugin/activate', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'plugin_key' => $plugin_key,
                'site_url'   => home_url(),
            ]),
            'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            update_option('shopwalk_wc_license_status', 'error');
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            update_option('shopwalk_wc_license_status', ($body['status'] ?? '') === 'ok' ? 'active' : 'invalid');
        }
    }

    /**
     * AJAX: save a manually entered plugin key (for users with existing keys).
     */
    public function ajax_save_manual_key(): void {
        check_ajax_referer('shopwalk_auto_register', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $key = sanitize_text_field(wp_unslash($_POST['plugin_key'] ?? ''));
        if (empty($key)) {
            wp_send_json_error(['message' => 'Plugin key is required.']);
        }

        update_option('shopwalk_wc_plugin_key', $key);
        update_option('shopwalk_wc_license_status', 'active');
        flush_rewrite_rules();

        wp_send_json_success(['message' => 'Key saved successfully.']);
    }

    /**
     * AJAX: schedule a background WP-Cron job to sync all published products.
     * Returns JSON {scheduled, message}.
     */
    public function ajax_sync_all(): void {
        check_ajax_referer('shopwalk_wc_sync_all', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $plugin_key = get_option('shopwalk_wc_plugin_key', '');
        if (empty($plugin_key)) {
            wp_send_json_error(['message' => 'No Plugin Key configured. Connect your store first.'], 400);
        }

        if (get_option('shopwalk_wc_enable_sync', 'yes') !== 'yes') {
            wp_send_json_error(['message' => 'Product sync is disabled in settings. Enable "Sync products to Shopwalk" and try again.'], 400);
        }

        // Schedule a single WP-Cron event to run the bulk sync in the background
        wp_schedule_single_event(time(), 'shopwalk_wc_bulk_sync');

        wp_send_json_success([
            'scheduled' => true,
            'message'   => 'Bulk sync queued — results will appear shortly.',
        ]);
    }

    /**
     * AJAX: re-run all AI Commerce Status checks and return JSON.
     * Also includes a recent bulk sync result if one is available (< 10 min old).
     */
    public function ajax_test_connection(): void {
        check_ajax_referer('shopwalk_wc_test_connection', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $result = $this->run_status_checks();

        // Attach the most-recent bulk sync result if it is less than 10 minutes old
        $bulk_result = get_option('shopwalk_wc_bulk_sync_result', []);
        if (!empty($bulk_result['at'])) {
            $synced_at = strtotime($bulk_result['at']);
            if ($synced_at && (time() - $synced_at) < 600) {
                $result['bulk_sync'] = $bulk_result;
            }
        }

        wp_send_json_success($result);
    }

    /**
     * Run all AI Commerce Status checks and return an array keyed by check name.
     * Each entry: ['status' => 'green'|'red'|'yellow', 'label' => string]
     */
    private function run_status_checks(): array {
        $checks = [];

        // 1. Plugin Key
        $license_status = get_option('shopwalk_wc_license_status', '');
        $checks['plugin_key'] = [
            'status' => ($license_status === 'active') ? 'green' : 'red',
            'label'  => ($license_status === 'active') ? 'Valid' : 'Not activated',
        ];

        // 2. Shopwalk API reachability
        $health_resp = wp_remote_head('https://api.shopwalk.com/api/v1/health', ['timeout' => 8]);
        $api_ok = !is_wp_error($health_resp) && wp_remote_retrieve_response_code($health_resp) === 200;
        $checks['api'] = [
            'status' => $api_ok ? 'green' : 'red',
            'label'  => $api_ok ? 'Connected' : 'Unreachable',
        ];

        // 3. Product Catalog count
        $product_ids = function_exists('wc_get_products') ? wc_get_products(['status' => 'publish', 'limit' => -1, 'return' => 'ids']) : [];
        $product_count = count($product_ids);
        $checks['catalog'] = [
            'status' => $product_count > 0 ? 'green' : 'yellow',
            'label'  => $product_count > 0 ? $product_count . ' published products' : 'No products',
        ];

        // 4. UCP Discovery
        $ucp_path  = ABSPATH . '.well-known/ucp';
        $ucp_local = file_exists($ucp_path);
        if (!$ucp_local) {
            $ucp_resp  = wp_remote_get(home_url('/.well-known/ucp'), ['timeout' => 8, 'redirection' => 0]);
            $ucp_local = !is_wp_error($ucp_resp) && in_array(wp_remote_retrieve_response_code($ucp_resp), [200, 301, 302], true);
        }
        $checks['ucp'] = [
            'status' => $ucp_local ? 'green' : 'yellow',
            'label'  => $ucp_local ? 'Live' : 'Not found',
        ];

        // 5. AI Browsing REST route
        $browsing_ok = (bool) rest_get_server()->get_routes()['/' . Shopwalk_WC_Products::REST_NAMESPACE . '/products'] ?? false;
        // Fallback: just check that rest routes include our namespace
        if (!$browsing_ok) {
            $routes = rest_get_server()->get_routes();
            foreach (array_keys($routes) as $route) {
                if (strpos($route, 'shopwalk-wc/v1/products') !== false) {
                    $browsing_ok = true;
                    break;
                }
            }
        }
        $checks['browsing'] = [
            'status' => $browsing_ok ? 'green' : 'yellow',
            'label'  => $browsing_ok ? 'Available' : 'Not registered',
        ];

        // 6. AI Checkout REST route
        $checkout_ok = false;
        $routes = rest_get_server()->get_routes();
        foreach (array_keys($routes) as $route) {
            if (strpos($route, 'shopwalk-wc/v1/checkout-sessions') !== false) {
                $checkout_ok = true;
                break;
            }
        }
        $checks['checkout'] = [
            'status' => $checkout_ok ? 'green' : 'yellow',
            'label'  => $checkout_ok ? 'Ready' : 'Not registered',
        ];

        // 7. Order Webhooks
        $webhooks_enabled = get_option('shopwalk_wc_enable_webhooks', 'yes') === 'yes';
        $checks['webhooks'] = [
            'status' => $webhooks_enabled ? 'green' : 'yellow',
            'label'  => $webhooks_enabled ? 'Active' : 'Disabled',
        ];

        return $checks;
    }

    /**
     * Build the AI Commerce Status HTML for the settings page.
     */
    private function get_ai_status_html(): string {
        $checks = $this->run_status_checks();

        $rows_map = [
            'plugin_key' => 'Plugin Key',
            'api'        => 'Shopwalk API',
            'catalog'    => 'Product Catalog',
            'ucp'        => 'UCP Discovery',
            'browsing'   => 'AI Browsing',
            'checkout'   => 'AI Checkout',
            'webhooks'   => 'Order Webhooks',
        ];

        $html  = '<style>';
        $html .= '.shopwalk-status-green { color: #46b450; font-size: 18px; }';
        $html .= '.shopwalk-status-red   { color: #dc3232; font-size: 18px; }';
        $html .= '.shopwalk-status-yellow{ color: #f56e28; font-size: 18px; }';
        $html .= '.shopwalk-status-table td { border-bottom: 1px solid #f0f0f0; }';
        $html .= '</style>';

        $html .= '<table class="shopwalk-status-table" style="border-collapse:collapse;width:100%;max-width:600px;">';
        foreach ($rows_map as $key => $name) {
            $check  = $checks[$key] ?? ['status' => 'yellow', 'label' => '—'];
            $color  = 'shopwalk-status-' . $check['status'];
            $html  .= '<tr>';
            $html  .= '<td style="padding:8px 12px;">';
            $html  .= '<span class="shopwalk-status-dot ' . esc_attr($color) . '">&#9679;</span> ';
            $html  .= '<strong>' . esc_html($name) . '</strong>';
            $html  .= '</td>';
            $html  .= '<td style="padding:8px 12px;color:#666;">' . esc_html($check['label']) . '</td>';
            $html  .= '</tr>';
        }
        $html .= '</table>';

        $html .= '<p style="margin-top:12px;">';
        $html .= '<button type="button" id="shopwalk-test-connection" class="button button-secondary">'
            . esc_html__('Test Connection', 'shopwalk-ai')
            . '</button>';
        $html .= '<button type="button" id="shopwalk-sync-now" class="button button-secondary" style="margin-left:8px;">'
            . esc_html__('Sync Products Now', 'shopwalk-ai')
            . '</button>';
        $html .= '<span id="shopwalk-connection-result" style="margin-left:10px;"></span>';
        $html .= '</p>';

        return $html;
    }

    /**
     * Enqueue admin JS only on the Shopwalk settings tab.
     */
    public function enqueue_admin_scripts(string $hook): void {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : '';
        if (($GLOBALS['current_tab'] ?? '') !== 'shopwalk' && $current_tab !== 'shopwalk') {
            return;
        }

        wp_enqueue_script(
            'shopwalk-wc-admin',
            SHOPWALK_AI_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            SHOPWALK_AI_VERSION,
            true
        );

        wp_localize_script('shopwalk-wc-admin', 'shopwalkWC', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('shopwalk_wc_sync_all'),
            'testNonce'       => wp_create_nonce('shopwalk_wc_test_connection'),
            'registerNonce'   => wp_create_nonce('shopwalk_auto_register'),
            'i18n'            => [
                'syncing'        => __('Syncing...', 'shopwalk-ai'),
                'syncNow'        => __('Sync Products Now', 'shopwalk-ai'),
                'testing'        => __('Testing...', 'shopwalk-ai'),
                'testConn'       => __('Test Connection', 'shopwalk-ai'),
                'success'        => __('Sync complete!', 'shopwalk-ai'),
                'error'          => __('Request failed. Check your Plugin Key.', 'shopwalk-ai'),
                'connecting'     => __('Connecting your store...', 'shopwalk-ai'),
                'connectSuccess' => __('Connected! Reloading...', 'shopwalk-ai'),
                'connectError'   => __('Connection failed. Please try again.', 'shopwalk-ai'),
            ],
        ]);
    }

    private function get_settings(): array {
        return [
            'section_title' => [
                'name' => __('Shopwalk Settings', 'shopwalk-ai'),
                'type' => 'title',
                'desc' => __('Connect your store to Shopwalk — the AI shopping platform that automatically syncs your products and helps customers discover and buy from you.', 'shopwalk-ai'),
                'id'   => 'shopwalk_wc_section_title',
            ],
            'plugin_key' => [
                'name'        => __('Plugin Key', 'shopwalk-ai'),
                'type'        => 'password',
                'desc'        => __('Your Shopwalk plugin key. Get one at shopwalk.com/plugin.', 'shopwalk-ai'),
                'id'          => 'shopwalk_wc_plugin_key',
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'sw_plugin_...',
            ],
            'merchant_id' => [
                'name'        => __('Merchant ID', 'shopwalk-ai'),
                'type'        => 'text',
                'desc'        => __('Override the merchant slug sent to Shopwalk. Leave blank to auto-derive from your site URL. Useful if the auto-derived ID does not match your Shopwalk dashboard.', 'shopwalk-ai'),
                'id'          => 'shopwalk_wc_merchant_id',
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => __('Auto (derived from site URL)', 'shopwalk-ai'),
            ],
            'stripe_secret_key' => [
                'name'        => __('Stripe Secret Key', 'shopwalk-ai'),
                'type'        => 'password',
                'desc'        => __('Optional — only needed if the WC Stripe gateway is not installed. Shopwalk AI uses this key to process Stripe payments during AI checkout sessions.', 'shopwalk-ai'),
                'id'          => 'shopwalk_wc_stripe_secret_key',
                'default'     => '',
                'desc_tip'    => true,
                'placeholder' => 'sk_live_... or sk_test_...',
            ],
            'api_key' => [
                'name'     => __('Inbound API Key', 'shopwalk-ai'),
                'type'     => 'text',
                'desc'     => __('Set an API key to secure checkout and order endpoints. Leave blank to allow open access (not recommended for production).', 'shopwalk-ai'),
                'id'       => 'shopwalk_wc_api_key',
                'default'  => '',
                'desc_tip' => true,
            ],
            'enable_sync' => [
                'name'    => __('Sync products to Shopwalk', 'shopwalk-ai'),
                'type'    => 'checkbox',
                'desc'    => __('Push product data (name, price, stock, images) to Shopwalk AI so your products appear in AI-powered searches. No customer or order data is ever shared. <a href="https://shopwalk.com/privacy" target="_blank">Privacy Policy</a>', 'shopwalk-ai'),
                'id'      => 'shopwalk_wc_enable_sync',
                'default' => 'yes',
            ],
            'enable_catalog' => [
                'name'    => __('Enable Catalog API', 'shopwalk-ai'),
                'type'    => 'checkbox',
                'desc'    => __('Allow Shopwalk AI to browse your product catalog.', 'shopwalk-ai'),
                'id'      => 'shopwalk_wc_enable_catalog',
                'default' => 'yes',
            ],
            'enable_checkout' => [
                'name'    => __('Enable Checkout API', 'shopwalk-ai'),
                'type'    => 'checkbox',
                'desc'    => __('Allow Shopwalk AI to create checkout sessions and place orders.', 'shopwalk-ai'),
                'id'      => 'shopwalk_wc_enable_checkout',
                'default' => 'yes',
            ],
            'enable_webhooks' => [
                'name'    => __('Enable Webhooks', 'shopwalk-ai'),
                'type'    => 'checkbox',
                'desc'    => __('Send order status notifications to Shopwalk.', 'shopwalk-ai'),
                'id'      => 'shopwalk_wc_enable_webhooks',
                'default' => 'yes',
            ],
            'section_end' => [
                'type' => 'sectionend',
                'id'   => 'shopwalk_wc_section_end',
            ],
            'ai_status_title' => [
                'name' => __('AI Commerce Status', 'shopwalk-ai'),
                'type' => 'title',
                'desc' => $this->get_ai_status_html(),
                'id'   => 'shopwalk_wc_ai_status_title',
            ],
            'status_end' => [
                'type' => 'sectionend',
                'id'   => 'shopwalk_wc_status_end',
            ],
        ];
    }
}
