<?php
/**
 * Shopwalk_Dashboard_Panel — renders the connected-state panel for the
 * WP Admin dashboard. Tier 2 (Shopwalk integration) only.
 *
 * The unconnected-state CTA is rendered by the Tier 1 admin dashboard
 * directly so it can be shown without loading any Shopwalk-specific code.
 *
 * @package WooCommerceUCP
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
		$pid        = Shopwalk_License::partner_id();
		$licenseKey = Shopwalk_License::key();
		$queued     = count( (array) get_option( 'shopwalk_sync_queue', array() ) );
		$syncState  = (array) get_option( 'shopwalk_sync_state', array() );
		$lastSync   = ! empty( $syncState['completed_at'] ) ? human_time_diff( (int) $syncState['completed_at'] ) . ' ago' : 'Never';
		?>
		<div class="ucp-card">
			<h2><?php esc_html_e( 'Shopwalk', 'woocommerce-ucp' ); ?> <span class="status-pill ok">✅ Connected</span></h2>
			<?php if ( '' !== $licenseKey ) : ?>
				<p>
					<strong><?php esc_html_e( 'License Key:', 'woocommerce-ucp' ); ?></strong>
					<code><?php echo esc_html( $licenseKey ); ?></code>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $pid ) : ?>
				<p>
					<strong><?php esc_html_e( 'Partner ID:', 'woocommerce-ucp' ); ?></strong>
					<code><?php echo esc_html( $pid ); ?></code>
				</p>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'Sync queue:', 'woocommerce-ucp' ); ?>
				<?php echo (int) $queued; ?> / 500
				&nbsp;·&nbsp;
				<?php esc_html_e( 'Last sync:', 'woocommerce-ucp' ); ?>
				<?php echo esc_html( $lastSync ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/dashboard' ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Manage in Shopwalk portal →', 'woocommerce-ucp' ); ?>
				</a>
				<button type="button" class="button" id="shopwalk-sync-now">
					<?php esc_html_e( 'Sync now', 'woocommerce-ucp' ); ?>
				</button>
				<button type="button" class="button button-link-delete" id="shopwalk-disconnect">
					<?php esc_html_e( 'Disconnect', 'woocommerce-ucp' ); ?>
				</button>
			</p>
		</div>
		<?php
	}
}
