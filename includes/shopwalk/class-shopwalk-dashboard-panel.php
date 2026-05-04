<?php
/**
 * Shopwalk_Dashboard_Panel — renders the connected-state panel for the
 * WP Admin dashboard. Tier 2 (Shopwalk integration) only.
 *
 * The unconnected-state CTA is rendered by the Tier 1 admin dashboard
 * directly so it can be shown without loading any Shopwalk-specific code.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Dashboard_Panel — connected-state widget renderer.
 */
final class Shopwalk_Dashboard_Panel {

	/**
	 * Render the connected-state Shopwalk panel.
	 *
	 * @return void
	 */
	public static function render(): void {
		$pid          = Shopwalk_License::partner_id();
		$license_key  = Shopwalk_License::key();
		$is_connected = Shopwalk_License::is_connected();
		$queued       = count( (array) get_option( 'shopwalk_sync_queue', array() ) );
		$sync_state   = (array) get_option( 'shopwalk_sync_state', array() );
		$last_sync    = ! empty( $sync_state['completed_at'] ) ? human_time_diff( (int) $sync_state['completed_at'] ) . ' ago' : __( 'Never', 'shopwalk-for-woocommerce' );
		?>
		<div class="ucp-card">
			<h2>
				<?php esc_html_e( 'Shopwalk', 'shopwalk-for-woocommerce' ); ?>
				<?php if ( $is_connected ) : ?>
					<span class="status-pill ok">✅ <?php esc_html_e( 'Connected', 'shopwalk-for-woocommerce' ); ?></span>
				<?php else : ?>
					<span class="status-pill warn">⚠ <?php esc_html_e( 'Not connected', 'shopwalk-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</h2>
			<?php if ( '' !== $license_key ) : ?>
				<p>
					<strong><?php esc_html_e( 'License Key:', 'shopwalk-for-woocommerce' ); ?></strong>
					<code><?php echo esc_html( $license_key ); ?></code>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $pid ) : ?>
				<p>
					<strong><?php esc_html_e( 'Partner ID:', 'shopwalk-for-woocommerce' ); ?></strong>
					<code><?php echo esc_html( $pid ); ?></code>
				</p>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'Sync queue:', 'shopwalk-for-woocommerce' ); ?>
				<?php echo (int) $queued; ?>
				&nbsp;·&nbsp;
				<?php esc_html_e( 'Last sync:', 'shopwalk-for-woocommerce' ); ?>
				<?php echo esc_html( $last_sync ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/dashboard' ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Manage in Shopwalk portal →', 'shopwalk-for-woocommerce' ); ?>
				</a>
				<button type="button" class="button" id="shopwalk-sync-now">
					<?php esc_html_e( 'Sync now', 'shopwalk-for-woocommerce' ); ?>
				</button>
				<button type="button" class="button button-link-delete" id="shopwalk-disconnect">
					<?php esc_html_e( 'Disconnect', 'shopwalk-for-woocommerce' ); ?>
				</button>
			</p>
		</div>
		<?php
	}
}
