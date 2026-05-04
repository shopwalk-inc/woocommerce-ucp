<?php
/**
 * UCP Response Helper — builds spec-compliant response envelopes.
 *
 * Every UCP endpoint should use UCP_Response::ok() or UCP_Response::error()
 * so that the outer envelope stays consistent with the spec version.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Response — static helpers for the UCP response envelope.
 */
final class UCP_Response {

	/**
	 * UCP spec version this plugin implements.
	 */
	const VERSION = '2026-04-08';

	/**
	 * Wrap data in a UCP response envelope.
	 *
	 * @param array $data         Payload keys merged into the top level.
	 * @param array $capabilities Capability URNs included in the envelope.
	 * @return array
	 */
	public static function ok( $data, $capabilities = array() ) {
		if ( empty( $capabilities ) ) {
			$capabilities = array( 'dev.ucp.shopping.checkout' );
		}
		return array_merge(
			array(
				'ucp' => array(
					'version'      => self::VERSION,
					'capabilities' => $capabilities,
					'status'       => 'ok',
				),
			),
			$data
		);
	}

	/**
	 * Build an error response with UCP envelope.
	 *
	 * @param string $code     Machine-readable error code.
	 * @param string $message  Human-readable message.
	 * @param string $severity One of 'recoverable', 'fatal'.
	 * @param int    $status   HTTP status code.
	 * @return WP_Error
	 */
	public static function error( $code, $message, $severity = 'recoverable', $status = 400 ) {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'   => $status,
				'ucp'      => array(
					'version' => self::VERSION,
					'status'  => 'error',
				),
				'messages' => array(
					array(
						'type'     => 'error',
						'code'     => $code,
						'content'  => $message,
						'severity' => $severity,
					),
				),
			)
		);
	}

	/**
	 * Convert a WC price (float) to minor units (cents).
	 *
	 * @param float|string $amount Price in major units.
	 * @return int
	 */
	public static function to_cents( $amount ) {
		return intval( round( floatval( $amount ) * 100 ) );
	}

	/**
	 * Build a typed totals array from WC order or checkout values.
	 *
	 * @param float|string $subtotal Subtotal in major units.
	 * @param float|string $shipping Shipping in major units.
	 * @param float|string $tax      Tax in major units.
	 * @param float|string $discount Discount in major units.
	 * @param float|string $total    Total in major units.
	 * @return array
	 */
	public static function build_totals( $subtotal, $shipping, $tax, $discount, $total ) {
		$totals   = array();
		$totals[] = array(
			'type'   => 'subtotal',
			'amount' => self::to_cents( $subtotal ),
		);
		if ( floatval( $shipping ) > 0 ) {
			$totals[] = array(
				'type'   => 'shipping',
				'amount' => self::to_cents( $shipping ),
			);
		}
		if ( floatval( $tax ) > 0 ) {
			$totals[] = array(
				'type'   => 'tax',
				'amount' => self::to_cents( $tax ),
			);
		}
		if ( floatval( $discount ) > 0 ) {
			$totals[] = array(
				'type'   => 'discount',
				'amount' => -1 * self::to_cents( $discount ),
			);
		}
		$totals[] = array(
			'type'   => 'total',
			'amount' => self::to_cents( $total ),
		);
		return $totals;
	}

	/**
	 * Convert a WC address to a UCP Destination object.
	 *
	 * @param array  $address WC or UCP address array.
	 * @param string $id      Destination identifier.
	 * @return array
	 */
	public static function to_destination( $address, $id = 'dest_1' ) {
		$street = trim( ( $address['address_1'] ?? $address['line1'] ?? '' ) . ' ' . ( $address['address_2'] ?? $address['line2'] ?? '' ) );
		return array(
			'id'               => $id,
			'street_address'   => $street,
			'address_locality' => $address['city'] ?? $address['address_locality'] ?? '',
			'address_region'   => $address['state'] ?? $address['address_region'] ?? '',
			'postal_code'      => $address['postcode'] ?? $address['postal_code'] ?? '',
			'address_country'  => $address['country'] ?? $address['address_country'] ?? '',
		);
	}

	/**
	 * Build a UCP line item from a WC order item.
	 *
	 * @param WC_Order_Item_Product $wc_item WC line item.
	 * @param int                   $index   Zero-based index.
	 * @return array
	 */
	public static function build_line_item( $wc_item, $index = 0 ) {
		$product   = $wc_item->get_product();
		$image_url = '';
		if ( $product ) {
			$image_id = $product->get_image_id();
			if ( $image_id ) {
				$image_url = wp_get_attachment_url( $image_id );
			}
		}

		return array(
			'id'       => 'li_' . ( $index + 1 ),
			'item'     => array(
				'id'        => strval( $wc_item->get_product_id() ),
				'title'     => $wc_item->get_name(),
				'price'     => self::to_cents( $wc_item->get_total() / max( $wc_item->get_quantity(), 1 ) ),
				'image_url' => $image_url,
			),
			'quantity' => $wc_item->get_quantity(),
			'totals'   => array(
				array(
					'type'   => 'subtotal',
					'amount' => self::to_cents( $wc_item->get_subtotal() ),
				),
				array(
					'type'   => 'total',
					'amount' => self::to_cents( $wc_item->get_total() ),
				),
			),
		);
	}

	/**
	 * Build a UCP order line item with quantity tracking for order responses.
	 *
	 * @param WC_Order_Item_Product $wc_item       WC line item.
	 * @param int                   $index         Zero-based index.
	 * @param int                   $fulfilled_qty Number of units fulfilled.
	 * @return array
	 */
	public static function build_order_line_item( $wc_item, $index = 0, $fulfilled_qty = 0 ) {
		$base      = self::build_line_item( $wc_item, $index );
		$total_qty = $wc_item->get_quantity();

		// Replace scalar quantity with quantity object.
		$base['quantity'] = array(
			'original'  => $total_qty,
			'total'     => $total_qty,
			'fulfilled' => $fulfilled_qty,
		);

		// Derive status from quantities.
		if ( 0 === $total_qty ) {
			$base['status'] = 'removed';
		} elseif ( $fulfilled_qty >= $total_qty ) {
			$base['status'] = 'fulfilled';
		} elseif ( $fulfilled_qty > 0 ) {
			$base['status'] = 'partial';
		} else {
			$base['status'] = 'processing';
		}

		return $base;
	}
}
