<?php
/**
 * Shopwalk_Dashboard_Panel — renders the connected-state panel for the
 * WP Admin dashboard. Tier 2 (Shopwalk integration) only.
 *
 * The unconnected-state CTA is rendered by the Tier 1 admin dashboard
 * directly so it can be shown without loading any Shopwalk-specific code.
 *
 * @package Shopwalk
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
		$pid    = Shopwalk_License::partner_id();
		$queued = count( (array) get_option( 'shopwalk_sync_queue', array() ) );
		?>
		<div class="ucp-card">
			<h2><?php esc_html_e( 'Shopwalk', 'shopwalk-ai' ); ?> <span class="status-pill ok">✅ Connected</span></h2>
			<?php if ( $pid !== '' ) : ?>
				<p>
					<strong><?php esc_html_e( 'Partner ID:', 'shopwalk-ai' ); ?></strong>
					<code><?php echo esc_html( $pid ); ?></code>
				</p>
			<?php endif; ?>
			<p>
				<?php esc_html_e( 'Sync queue:', 'shopwalk-ai' ); ?>
				<?php echo (int) $queued; ?> / 500
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/dashboard' ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Manage in Shopwalk portal →', 'shopwalk-ai' ); ?>
				</a>
				<button type="button" class="button" id="shopwalk-sync-now">
					<?php esc_html_e( 'Sync now', 'shopwalk-ai' ); ?>
				</button>
				<button type="button" class="button button-link-delete" id="shopwalk-disconnect">
					<?php esc_html_e( 'Disconnect', 'shopwalk-ai' ); ?>
				</button>
			</p>
		</div>
		<?php
	}
}
