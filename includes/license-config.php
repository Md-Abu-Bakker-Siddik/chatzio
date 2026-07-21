<?php
/**
 * Chatzio license configuration — SINGLE source of truth for the PCL host.
 *
 * Change the PCL server URL in ONE place when the domain changes.
 *
 * Order of resolution (highest priority first):
 *   1. Constant defined in wp-config.php  (recommended for "env" style)
 *        define('CHATZIO_PCL_API_BASE', 'https://your-store.com/wp-json/pcl/v1');
 *        define('CHATZIO_PCL_PRODUCT_SLUG', 'chatzio');
 *   2. Filter override (programmatic):
 *        add_filter('chatzio_pcl_api_base',   fn() => 'https://your-store.com/wp-json/pcl/v1');
 *        add_filter('chatzio_pcl_product_slug', fn() => 'chatzio');
 *   3. The defaults below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CHATZIO_PCL_API_BASE_DEFAULT' ) ) {
	define( 'CHATZIO_PCL_API_BASE_DEFAULT', 'https://chatzio.pro/wp-json/pcl/v1' );
}

if ( ! defined( 'CHATZIO_PCL_PRODUCT_SLUG_DEFAULT' ) ) {
	define( 'CHATZIO_PCL_PRODUCT_SLUG_DEFAULT', 'chatzio' );
}

/**
 * Resolve the PCL API base URL. Always returns no trailing slash.
 */
function chatzio_pcl_api_base() {
	$base = defined( 'CHATZIO_PCL_API_BASE' ) && CHATZIO_PCL_API_BASE
		? (string) CHATZIO_PCL_API_BASE
		: (string) CHATZIO_PCL_API_BASE_DEFAULT;
	$base = apply_filters( 'chatzio_pcl_api_base', $base );
	return untrailingslashit( esc_url_raw( $base ) );
}

/**
 * Resolve the product slug used in PCL.
 */
function chatzio_pcl_product_slug() {
	$slug = defined( 'CHATZIO_PCL_PRODUCT_SLUG' ) && CHATZIO_PCL_PRODUCT_SLUG
		? (string) CHATZIO_PCL_PRODUCT_SLUG
		: (string) CHATZIO_PCL_PRODUCT_SLUG_DEFAULT;
	$slug = apply_filters( 'chatzio_pcl_product_slug', $slug );
	return sanitize_key( $slug );
}

/**
 * The site URL we send to PCL — normalized to home URL.
 */
function chatzio_pcl_site_url() {
	return apply_filters( 'chatzio_pcl_site_url', home_url( '/' ) );
}
