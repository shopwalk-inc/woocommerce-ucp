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
		$pid          = Shopwalk_License::partner_id();
		$license_key  = Shopwalk_License::key();
		$is_connected = Shopwalk_License::is_connected();
		$queued       = count( (array) get_option( 'shopwalk_sync_queue', array() ) );
		$sync_state   = (array) get_option( 'shopwalk_sync_state', array() );
		$last_sync    = ! empty( $sync_state['completed_at'] ) ? human_time_diff( (int) $sync_state['completed_at'] ) . ' ago' : __( 'Never', 'ucp-for-woocommerce' );
		?>
		<div class="ucp-card">
			<h2>
				<?php esc_html_e( 'Shopwalk', 'ucp-for-woocommerce' ); ?>
				<?php if ( $is_connected ) : ?>
					<span class="status-pill ok">✅ <?php esc_html_e( 'Connected', 'ucp-for-woocommerce' ); ?></span>
				<?php else : ?>
					<span class="status-pill warn">⚠ <?php esc_html_e( 'Not connected', 'ucp-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</h2>
			<?php if ( '' !== $license_key ) : ?>
				<p>
					<strong><?php esc_html_e( 'License Key:', 'ucp-for-woocommerce' ); ?></strong>
					<code title="<?php esc_attr_e( 'License key is hidden. Find your full key in your Shopwalk partner portal.', 'ucp-for-woocommerce' ); ?>"><?php echo esc_html( self::mask_license_key( $license_key ) ); ?></code>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $pid ) : ?>
				<p>
					<strong><?php esc_html_e( 'Partner ID:', 'ucp-for-woocommerce' ); ?></strong>
					<code><?php echo esc_html( $pid ); ?></code>
				</p>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'Sync queue:', 'ucp-for-woocommerce' ); ?>
				<?php echo (int) $queued; ?>
				&nbsp;·&nbsp;
				<?php esc_html_e( 'Last sync:', 'ucp-for-woocommerce' ); ?>
				<?php echo esc_html( $last_sync ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/dashboard' ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Manage in Shopwalk portal →', 'ucp-for-woocommerce' ); ?>
				</a>
				<button type="button" class="button" id="shopwalk-sync-now">
					<?php esc_html_e( 'Sync now', 'ucp-for-woocommerce' ); ?>
				</button>
				<button type="button" class="button button-link-delete" id="shopwalk-disconnect">
					<?php esc_html_e( 'Disconnect', 'ucp-for-woocommerce' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * Mask a license key for display. Shows first 8 chars + ellipsis + last 4.
	 *
	 * The key is a bearer credential — never display it in full. A merchant
	 * who needs the full key looks it up in the Shopwalk partner portal,
	 * not here.
	 *
	 * @param string $key The license key.
	 * @return string Masked representation safe to display.
	 */
	private static function mask_license_key( string $key ): string {
		$len = strlen( $key );
		if ( $len <= 12 ) {
			return str_repeat( '•', max( 4, $len - 4 ) ) . substr( $key, -4 );
		}
		return substr( $key, 0, 8 ) . '…' . substr( $key, -4 );
	}
}
