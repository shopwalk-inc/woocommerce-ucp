<?php
/**
 * Shopwalk AI — Merchant Dashboard
 *
 * Shows store health, products indexed, AI agent activity, and last sync time.
 * This is the retention hook that keeps merchants from uninstalling the free plugin.
 *
 * @package ShopwalkAI
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shopwalk_WC_Dashboard {

    private const DASHBOARD_ENDPOINT = 'https://api.shopwalk.com/api/v1/plugin/dashboard';
    private const CACHE_KEY           = 'shopwalk_wc_dashboard_cache';
    private const CACHE_TTL           = 300; // 5 minutes

    public function __construct() {
        add_action( 'wp_ajax_shopwalk_fetch_dashboard', [ $this, 'ajax_fetch_dashboard' ] );
    }

    /**
     * Render the dashboard page in WP Admin.
     * Hooked by Shopwalk_WC_Settings when a plugin key exists.
     */
    public function render(): void {
        $plugin_key = get_option( 'shopwalk_wc_plugin_key', '' );

        if ( empty( $plugin_key ) ) {
            echo '<div class="notice notice-warning inline"><p>' .
                esc_html__( 'Connect your store first to see dashboard stats.', 'shopwalk-ai' ) .
                '</p></div>';
            return;
        }

        $nonce = wp_create_nonce( 'shopwalk_dashboard' );
        ?>
        <style>
        .sw-dashboard { max-width: 900px; margin: 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .sw-dashboard .sw-header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
        .sw-dashboard .sw-logo { width: 36px; height: 36px; }
        .sw-dashboard .sw-title { font-size: 22px; font-weight: 700; color: #1d2327; margin: 0; }
        .sw-dashboard .sw-subtitle { font-size: 13px; color: #646970; margin: 0; }
        .sw-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .sw-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; position: relative; }
        .sw-card .sw-card-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #646970; margin-bottom: 8px; }
        .sw-card .sw-card-value { font-size: 32px; font-weight: 700; color: #1d2327; line-height: 1; }
        .sw-card .sw-card-sub { font-size: 12px; color: #646970; margin-top: 6px; }
        .sw-card.sw-card-green { border-left: 4px solid #00a32a; }
        .sw-card.sw-card-yellow { border-left: 4px solid #dba617; }
        .sw-card.sw-card-red { border-left: 4px solid #d63638; }
        .sw-card.sw-card-blue { border-left: 4px solid #2271b1; }
        .sw-status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .sw-status-badge.ok { background: #edfaef; color: #1a7335; }
        .sw-status-badge.error { background: #fcf0f1; color: #8a1f1f; }
        .sw-status-badge.checking { background: #f0f6fc; color: #1d6fa4; }
        .sw-status-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
        .sw-pro-banner { background: linear-gradient(135deg, #1d2327 0%, #2c3338 100%); color: #fff; border-radius: 8px; padding: 20px 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .sw-pro-banner h3 { margin: 0 0 4px; font-size: 16px; font-weight: 700; }
        .sw-pro-banner p { margin: 0; font-size: 13px; opacity: 0.8; }
        .sw-pro-btn { background: #f0b429; color: #1d2327; border: none; border-radius: 6px; padding: 10px 20px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; text-decoration: none; }
        .sw-pro-btn:hover { background: #e6a817; color: #1d2327; }
        .sw-loading { opacity: 0.4; pointer-events: none; }
        .sw-refresh-btn { float: right; margin-top: -2px; }
        </style>

        <div class="sw-dashboard" id="sw-dashboard">
            <div class="sw-header">
                <div>
                    <h2 class="sw-title">Shopwalk AI Dashboard</h2>
                    <p class="sw-subtitle">
                        <span id="sw-status-badge" class="sw-status-badge checking">
                            <span class="sw-status-dot"></span>
                            <?php esc_html_e( 'Checking…', 'shopwalk-ai' ); ?>
                        </span>
                        <button type="button" class="button button-small sw-refresh-btn" id="sw-refresh-btn">
                            ↻ <?php esc_html_e( 'Refresh', 'shopwalk-ai' ); ?>
                        </button>
                    </p>
                </div>
            </div>

            <div class="sw-cards" id="sw-cards">
                <div class="sw-card sw-card-blue">
                    <div class="sw-card-label"><?php esc_html_e( 'Products Indexed', 'shopwalk-ai' ); ?></div>
                    <div class="sw-card-value" id="sw-product-count">—</div>
                    <div class="sw-card-sub" id="sw-last-synced"><?php esc_html_e( 'Loading…', 'shopwalk-ai' ); ?></div>
                </div>
                <div class="sw-card sw-card-green">
                    <div class="sw-card-label"><?php esc_html_e( 'AI Agent Requests', 'shopwalk-ai' ); ?></div>
                    <div class="sw-card-value" id="sw-ucp-requests">—</div>
                    <div class="sw-card-sub" id="sw-ucp-last"><?php esc_html_e( 'Loading…', 'shopwalk-ai' ); ?></div>
                </div>
                <div class="sw-card sw-card-blue">
                    <div class="sw-card-label"><?php esc_html_e( 'UCP Endpoint', 'shopwalk-ai' ); ?></div>
                    <div class="sw-card-value" style="font-size:18px;" id="sw-ucp-status"><?php esc_html_e( 'Checking…', 'shopwalk-ai' ); ?></div>
                    <div class="sw-card-sub" id="sw-ucp-endpoint" style="word-break:break-all;font-size:11px;">—</div>
                </div>
                <div class="sw-card sw-card-blue">
                    <div class="sw-card-label"><?php esc_html_e( 'Plugin Version', 'shopwalk-ai' ); ?></div>
                    <div class="sw-card-value" style="font-size:24px;"><?php echo esc_html( SHOPWALK_AI_VERSION ); ?></div>
                    <div class="sw-card-sub" id="sw-update-note">—</div>
                </div>
            </div>

            <div class="sw-pro-banner">
                <div>
                    <h3><?php esc_html_e( '✨ Upgrade to Shopwalk Pro', 'shopwalk-ai' ); ?></h3>
                    <p><?php esc_html_e( 'Add an AI shopping assistant to your store. Your customers browse and buy by chatting — no traffic from Shopwalk required.', 'shopwalk-ai' ); ?></p>
                </div>
                <a href="https://shopwalk.com/pro?ref=plugin_dashboard" class="sw-pro-btn" target="_blank">
                    <?php esc_html_e( 'Learn More →', 'shopwalk-ai' ); ?>
                </a>
            </div>
        </div>

        <script>
        (function($) {
            var nonce = <?php echo wp_json_encode( $nonce ); ?>;

            function timeAgo(iso) {
                if (!iso) return '<?php esc_html_e( 'Never', 'shopwalk-ai' ); ?>';
                var d = new Date(iso), now = new Date();
                var sec = Math.floor((now - d) / 1000);
                if (sec < 60) return '<?php esc_html_e( 'Just now', 'shopwalk-ai' ); ?>';
                if (sec < 3600) return Math.floor(sec/60) + '<?php esc_html_e( 'm ago', 'shopwalk-ai' ); ?>';
                if (sec < 86400) return Math.floor(sec/3600) + '<?php esc_html_e( 'h ago', 'shopwalk-ai' ); ?>';
                return Math.floor(sec/86400) + '<?php esc_html_e( 'd ago', 'shopwalk-ai' ); ?>';
            }

            function fetchDashboard() {
                $('#sw-dashboard').addClass('sw-loading');
                $.post(ajaxurl, { action: 'shopwalk_fetch_dashboard', nonce: nonce }, function(resp) {
                    $('#sw-dashboard').removeClass('sw-loading');
                    if (!resp.success || !resp.data) return;
                    var d = resp.data;

                    // Products
                    $('#sw-product-count').text(d.product_count !== undefined ? d.product_count.toLocaleString() : '—');
                    $('#sw-last-synced').text(d.last_synced_at ? '<?php esc_html_e( 'Last sync', 'shopwalk-ai' ); ?> ' + timeAgo(d.last_synced_at) : '<?php esc_html_e( 'Not synced yet', 'shopwalk-ai' ); ?>');

                    // AI agent requests
                    $('#sw-ucp-requests').text(d.ucp_request_count !== undefined ? d.ucp_request_count.toLocaleString() : '0');
                    $('#sw-ucp-last').text(d.ucp_last_request_at ? '<?php esc_html_e( 'Last request', 'shopwalk-ai' ); ?> ' + timeAgo(d.ucp_last_request_at) : '<?php esc_html_e( 'Awaiting first request', 'shopwalk-ai' ); ?>');

                    // UCP health
                    var ucpOk = d.ucp_healthy;
                    $('.sw-card.sw-card-blue').last().removeClass('sw-card-blue sw-card-green sw-card-red')
                        .addClass(ucpOk ? 'sw-card-green' : (d.ucp_health === 'not_configured' ? 'sw-card-yellow' : 'sw-card-red'));
                    $('#sw-ucp-status').text(ucpOk ? '✓ Online' : (d.ucp_health === 'not_configured' ? '⚠ Not set' : '✗ Error'));
                    $('#sw-ucp-endpoint').text(d.ucp_endpoint || '<?php esc_html_e( 'Not configured', 'shopwalk-ai' ); ?>');

                    // Global status badge
                    var badge = $('#sw-status-badge');
                    badge.removeClass('ok error checking');
                    if (ucpOk) {
                        badge.addClass('ok').html('<span class="sw-status-dot"></span> <?php esc_html_e( 'Connected & Active', 'shopwalk-ai' ); ?>');
                    } else {
                        badge.addClass('error').html('<span class="sw-status-dot"></span> <?php esc_html_e( 'UCP Unreachable', 'shopwalk-ai' ); ?>');
                    }

                    // Version note
                    var updateNote = '<?php esc_html_e( 'Up to date', 'shopwalk-ai' ); ?>';
                    $('#sw-update-note').text(updateNote);
                });
            }

            fetchDashboard();
            $('#sw-refresh-btn').on('click', fetchDashboard);

        }(jQuery));
        </script>
        <?php
    }

    /**
     * AJAX handler — fetches dashboard data from Shopwalk API.
     * Uses a 5-minute transient cache to avoid hammering the API.
     */
    public function ajax_fetch_dashboard(): void {
        check_ajax_referer( 'shopwalk_dashboard', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $plugin_key = get_option( 'shopwalk_wc_plugin_key', '' );
        if ( empty( $plugin_key ) ) {
            wp_send_json_error( [ 'message' => 'No plugin key configured.' ], 400 );
        }

        // Return cached data if fresh
        $cached = get_transient( self::CACHE_KEY );
        if ( $cached !== false ) {
            wp_send_json_success( $cached );
        }

        $response = wp_remote_get( self::DASHBOARD_ENDPOINT, [
            'headers' => [
                'X-API-Key'    => $plugin_key,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => 'Could not reach Shopwalk API.' ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['dashboard'] ) ) {
            wp_send_json_error( [ 'message' => $body['message'] ?? 'Failed to load dashboard.' ] );
        }

        $data = $body['dashboard'];
        set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
        wp_send_json_success( $data );
    }
}
