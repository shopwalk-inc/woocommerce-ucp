<?php
/**
 * Shopwalk AI — CDN Image Serving
 * Rewrites WooCommerce product image URLs to Shopwalk CDN when enabled.
 */
defined( 'ABSPATH' ) || exit;

class Shopwalk_WC_CDN {
	public static function init(): void {
		if ( ! get_option( 'shopwalk_cdn_enabled', false ) ) {
			return;
		}
		// Rewrite image URLs to CDN
		add_filter( 'wp_get_attachment_url', [ __CLASS__, 'rewrite_url' ] );
		add_filter( 'wp_get_attachment_image_src', [ __CLASS__, 'rewrite_image_src' ] );
	}

	public static function rewrite_url( string $url ): string {
		return self::cdn_url( $url );
	}

	public static function rewrite_image_src( $image ) {
		if ( is_array( $image ) && ! empty( $image[0] ) ) {
			$image[0] = self::cdn_url( $image[0] );
		}
		return $image;
	}

	public static function cdn_url( string $original_url ): string {
		// Only rewrite http/https image URLs
		if ( ! preg_match( '/^https?:\/\//', $original_url ) ) {
			return $original_url;
		}
		// Don't double-rewrite
		if ( strpos( $original_url, 'cdn.shopwalk.com' ) !== false ) {
			return $original_url;
		}
		$hash = hash( 'sha256', $original_url );
		$ext  = strtolower( pathinfo( parse_url( $original_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( empty( $ext ) || ! in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif' ], true ) ) {
			$ext = 'jpg';
		}
		return 'https://cdn.shopwalk.com/images/' . substr( $hash, 0, 2 ) . '/' . $hash . '.' . $ext;
	}
}
