<?php
/**
 * UCP_Payment_Adapter_Interface — the contract every payment adapter
 * implements. Lives in its own file per WordPress convention; loaded by
 * the bootstrap before class-ucp-payment-router.php which uses it.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contract every payment adapter implements.
 */
interface UCP_Payment_Adapter_Interface {

	/**
	 * Short, stable identifier ("stripe", "ppcp", "square", …).
	 */
	public function id(): string;

	/**
	 * Whether this adapter is usable right now. Typically checks that the
	 * corresponding WC gateway plugin is installed and has credentials set.
	 */
	public function is_ready(): bool;

	/**
	 * Authorize payment for a WooCommerce order using an agent-supplied
	 * credential. MUST return `true` on success or a `WP_Error` on failure.
	 * MUST advance the WC order payment state (via `$order->payment_complete()`
	 * or `$order->update_status()`) so WC and downstream webhook listeners
	 * observe the transition.
	 *
	 * @param WC_Order $order   The already-built order.
	 * @param array    $payment The UCP session's payment object.
	 * @return true|WP_Error
	 */
	public function authorize( $order, array $payment );

	/**
	 * Discovery hint published at /.well-known/ucp so agents can pick a
	 * gateway this store accepts before creating a session.
	 *
	 * @return array
	 */
	public function discovery_hint(): array;
}
