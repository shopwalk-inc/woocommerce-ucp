<?php
/**
 * Shopwalk AI — CDN Image Serving
 *
 * Rewrites WooCommerce product image URLs to the Shopwalk CDN when enabled.
 *
 * Path scheme (must match shopwalk-imgcache):
 *   https://cdn.shopwalk.com/merchants/{merchant_id}/{md5(original_url)}.{ext}
 *
 * The imgcache service caches images at this exact path in R2 when it processes
 * merchant_product_image messages. We compute the same MD5 locally — no API call needed.
 *
 * @package ShopwalkAI
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopwalk_WC_CDN {

	private const CDN_BASE = 'https://cdn.shopwalk.com';

	/**
	 * Boot CDN URL rewriting if enabled in settings.
	 * Called from shopwalk_ai_init() after all includes are loaded.
	 */
	public static function init(): void {
		if ( ! get_option( 'shopwalk_cdn_enabled', false ) ) {
			return;
		}

		$merchant_id = get_option( 'shopwalk_merchant_id', '' );
		if ( empty( $merchant_id ) ) {
			// Store not registered yet — CDN can't work without a merchant ID.
			return;
		}

		add_filter( 'wp_get_attachment_url', [ __CLASS__, 'rewrite_url' ], 20 );
		add_filter( 'wp_get_attachment_image_src', [ __CLASS__, 'rewrite_image_src' ], 20 );
		add_filter( 'wp_calculate_image_srcset', [ __CLASS__, 'rewrite_srcset' ], 20 );
	}

	/**
	 * Rewrite a single image URL.
	 *
	 * @param string $url Original attachment URL.
	 * @return string CDN URL if the image is cached, original URL otherwise.
	 */
	public static function rewrite_url( string $url ): string {
		$cdn_url = self::cdn_url( $url );
		return $cdn_url ?: $url;
	}

	/**
	 * Rewrite the primary src in an image src array [url, w, h, resized].
	 *
	 * @param array|false $image wp_get_attachment_image_src() result.
	 * @return array|false
	 */
	public static function rewrite_image_src( $image ) {
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			$cdn_url = self::cdn_url( $image[0] );
			if ( $cdn_url ) {
				$image[0] = $cdn_url;
			}
		}
		return $image;
	}

	/**
	 * Rewrite all URLs in a srcset array.
	 *
	 * @param array $sources Keyed by width, each has 'url' and 'descriptor'.
	 * @return array
	 */
	public static function rewrite_srcset( array $sources ): array {
		foreach ( $sources as $width => $source ) {
			if ( ! empty( $source['url'] ) ) {
				$cdn_url = self::cdn_url( $source['url'] );
				if ( $cdn_url ) {
					$sources[ $width ]['url'] = $cdn_url;
				}
			}
		}
		return $sources;
	}

	/**
	 * Build the CDN URL for a given original image URL.
	 *
	 * Returns null if the URL is not a recognised image type or is already
	 * pointing at the CDN.
	 *
	 * Path: merchants/{merchant_id}/{md5(original_url)}.{ext}
	 * This matches exactly what shopwalk-imgcache writes to R2.
	 *
	 * @param string $original_url
	 * @return string|null
	 */
	public static function cdn_url( string $original_url ): ?string {
		// Already a CDN URL — don't double-rewrite.
		if ( str_starts_with( $original_url, self::CDN_BASE ) ) {
			return null;
		}

		// Only rewrite http/https image URLs.
		if ( ! str_starts_with( $original_url, 'http' ) ) {
			return null;
		}

		$ext = self::ext_from_url( $original_url );
		if ( $ext === null ) {
			return null;
		}

		$merchant_id = get_option( 'shopwalk_merchant_id', '' );
		if ( empty( $merchant_id ) ) {
			return null;
		}

		// MD5 of the original URL — must match imgcache's md5.Sum([]byte(sourceURL))
		$hash = md5( $original_url );

		return sprintf( '%s/merchants/%s/%s%s', self::CDN_BASE, $merchant_id, $hash, $ext );
	}

	/**
	 * Extract a recognised image extension from a URL.
	 * Strips query string before checking. Returns null for unknown types.
	 *
	 * @param string $url
	 * @return string|null e.g. '.jpg', '.png', '.webp', or null
	 */
	private static function ext_from_url( string $url ): ?string {
		// Strip query string / fragment
		$path = strtok( $url, '?' );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		$allowed = [ 'jpg' => '.jpg', 'jpeg' => '.jpg', 'png' => '.png',
		             'gif' => '.gif', 'webp' => '.webp', 'svg' => '.svg', 'avif' => '.avif' ];

		return $allowed[ $ext ] ?? null;
	}
}
