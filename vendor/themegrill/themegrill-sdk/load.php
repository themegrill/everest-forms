<?php
/**
 * Loader for the ThemeGrillSDK
 *
 * Logic for loading always the latest SDK from the installed themes/plugins.
 *
 * @package     ThemeGrillSDK
 * @copyright   Copyright (c) 2025, ThemeGrill
 * @license     http://opensource.org/licenses/gpl-3.0.php GNU Public License
 * @since       1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Current SDK version and path.
$themegrill_sdk_version = '1.0.6';
$themegrill_sdk_path    = __DIR__;

global $themegrill_sdk_max_version;
global $themegrill_sdk_max_path;

if (
	( is_null( $themegrill_sdk_max_path ) ||
	version_compare( $themegrill_sdk_version, $themegrill_sdk_max_version ) === 0 ) &&
	apply_filters( 'themegrill_sdk_should_overwrite_path', false, $themegrill_sdk_path, $themegrill_sdk_max_path )
	) {
	$themegrill_sdk_max_path = $themegrill_sdk_path;
}

if (
	is_null( $themegrill_sdk_max_version ) ||
	version_compare( $themegrill_sdk_version, $themegrill_sdk_max_version ) > 0
) {
	$themegrill_sdk_max_version = $themegrill_sdk_version;
	$themegrill_sdk_max_path    = $themegrill_sdk_path;
}

// Load the latest sdk version from the active ThemeGrill products.
if ( ! function_exists( 'themegrill_sdk_load_latest' ) ) :
	/**
	 * Always load the latest sdk version.
	 */
	function themegrill_sdk_load_latest() {
		/**
		 * Don't load the library if we are on < 7.2.24.
		 */
		if ( version_compare( PHP_VERSION, '7.2.24', '<' ) ) {
			return;
		}
		global $themegrill_sdk_max_path;
		require_once $themegrill_sdk_max_path . '/start.php';
	}
endif;

add_action( 'init', 'themegrill_sdk_load_latest' );


if ( ! function_exists( 'tgsdk_utmify' ) ) {
	/**
	 * Utmify a link.
	 *
	 * @param string $url URL to add utms.
	 * @param string $area Area in page where this is used ( CTA, image, section name).
	 * @param string $location Location, such as customizer, about page.
	 *
	 * @return string
	 */
	function tgsdk_utmify( $url, $area, $location = null ) {
		static $current_page = null;
		if ( $location === null && $current_page === null ) {
			global $pagenow;
			$screen       = function_exists( 'get_current_screen' ) ? get_current_screen() : $pagenow;
			$current_page = isset( $screen->id ) ? $screen->id : ( ( $screen === null ) ? 'non-admin' : $screen );
			$current_page = sanitize_key( str_replace( '.php', '', $current_page ) );
		}
		$location        = $location === null ? $current_page : $location;
		$content         = sanitize_key(
			trim(
				str_replace(
					apply_filters(
						'tgsdk_utmify_replace',
						[
							'https://',
							'themegrill.com',
							'/themes/',
							'/plugins/',
							'/upgrade',
						]
					),
					'',
					$url
				),
				'/'
			)
		);
		$filter_key      = sanitize_key( $content );
		$url_args        = [
			'utm_source'   => 'wpadmin',
			'utm_medium'   => $location,
			'utm_campaign' => $area,
			'utm_content'  => $content,
		];
		$query_arguments = apply_filters( 'tgsdk_utmify_' . $filter_key, $url_args, $url );
		$utmify_url      = esc_url_raw(
			add_query_arg(
				$query_arguments,
				$url
			)
		);

		return apply_filters( 'tgsdk_utmify_url_' . $filter_key, $utmify_url, $url );
	}

	add_filter( 'tgsdk_utmify', 'tgsdk_utmify', 10, 3 );
}
