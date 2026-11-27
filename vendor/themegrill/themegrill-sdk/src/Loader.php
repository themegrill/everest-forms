<?php
/**
 * The main loader class for ThemeGrill SDK
 *
 * @package     ThemeGrillSDK
 * @subpackage  Loader
 * @copyright   Copyright (c) 2025, ThemeGrill
 * @license     http://opensource.org/licenses/gpl-3.0.php GNU Public License
 * @since       1.0.0
 */

namespace ThemeGrillSDK;

use ThemeGrillSDK\Common\ModuleFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton loader for ThemeGrill SDK.
 */
final class Loader {
	/**
	 * Singleton instance.
	 *
	 * @var Loader instance The singleton instance
	 */
	private static $instance;
	/**
	 * Current loader version.
	 *
	 * @var string $version The class version.
	 */
	private static $version = '1.0.5';
	/**
	 * Holds registered products.
	 *
	 * @var array<Product> The products which use the SDK.
	 */
	private static $products = [];
	/**
	 * Holds available modules to load.
	 *
	 * @var array The modules which SDK will be using.
	 */
	private static $available_modules = [
		'script_loader',
		'rollback',
		'uninstall_feedback',
		'logger',
		'notification',
		'announcements',
		'licenser',
		'updater',
	];
	/**
	 * Holds the labels for the modules.
	 *
	 * @var array The labels for the modules.
	 */
	public static $labels = [
		'announcements' => [
			'notice_link_label' => 'See the Offer',
			'max_savings'       => 'Our biggest sale of the year: <strong>Up to %s OFF on our products!</strong>  Don\'t miss this limited-time offer.',
			'black_friday'      => 'Black Friday Sale',
			'time_left'         => '%s left',
			'dont_show_again'   => 'Don\'t show again',
		],
		'uninstall'     => [
			'heading_plugin' => 'What\'s wrong?',
			'heading_theme'  => 'What does not work for you in {theme}?',
			'submit'         => 'Submit',
			'cta_info'       => 'What info do we collect?',
			'button_submit'  => 'Submit &amp; Deactivate',
			'button_cancel'  => 'Skip &amp; Deactivate',
			'disclosure'     => [
				'title'   => 'Below is a detailed view of all data that ThemeGrill will receive if you fill in this survey. No email address or IP addresses are transmitted after you submit the survey.',
				'version' => '%s %s version %s %s %s %s',
				'website' => '%sCurrent website:%s %s %s %s',
				'usage'   => '%sUsage time:%s %s %s%s',
				'reason'  => '%s Uninstall reason %s %s Selected reason from the above survey %s ',
			],

			'options'        => [
				'id3'   => [
					'title'       => 'I found a better plugin',
					'placeholder' => 'What\'s the plugin\'s name?',
				],
				'id4'   => [
					'title'       => 'I could not get the plugin to work',
					'placeholder' => 'What problem are you experiencing?',
				],
				'id5'   => [
					'title'       => 'I no longer need the plugin',
					'placeholder' => 'If you could improve one thing about our product, what would it be?',
				],
				'id6'   => [
					'title'       => 'It\'s a temporary deactivation. I\'m just debugging an issue.',
					'placeholder' => 'What problem are you experiencing?',
				],
				'id7'   => [
					'title' => 'I don\'t know how to make it look like demo',
				],
				'id8'   => [

					'placeholder' => 'What option is missing?',
					'title'       => 'It lacks options',
				],
				'id9'   => [
					'title'       => 'Is not working with a plugin that I need',
					'placeholder' => 'What is the name of the plugin',
				],
				'id10'  => [
					'title' => 'I want to try a new design, I don\'t like {theme} style',
				],
				'id999' => [
					'title'       => 'Other',
					'placeholder' => 'What can we do better?',
				],
			],
		],
		'rollback'      => [
			'cta' => 'Rollback to v%s',
		],
		'logger'        => [
			'notice' => 'Do you enjoy <b>{product}</b>? Become a contributor by opting in to our anonymous data tracking. We guarantee no sensitive data is collected.',
			'cta_y'  => 'Sure, I would love to help.',
			'cta_n'  => 'No, thanks.',
		],
	];

	/**
	 * Initialize the sdk logic.
	 */
	public static function init() {
		/**
		 * This filter can be used to localize the labels inside each product.
		 */
		self::$labels = apply_filters( 'themegrill_sdk_labels', self::$labels );
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Loader ) ) {
			self::$instance = new Loader();
			$modules        = array_merge( self::$available_modules, apply_filters( 'themegrill_sdk_modules', [] ) );
			foreach ( $modules as $key => $module ) {
				if ( ! class_exists( 'ThemeGrillSDK\\Modules\\' . str_replace( ' ', '', ucwords( str_replace( '_', ' ', $module ) ) ) ) ) {
					unset( $modules[ $key ] );
				}
			}
			self::$available_modules = $modules;

			add_action( 'themegrill_sdk_first_activation', array( __CLASS__, 'activate' ) );
		}
	}

	/**
	 * Get cache token used in API requests.
	 *
	 * @return string Cache token.
	 */
	public static function get_cache_token() {
		$cache_token = get_transient( 'themegrill_sdk_cache_token' );
		if ( false === $cache_token ) {
			$cache_token = wp_generate_password( 6, false );
			set_transient( 'themegrill_sdk_cache_token', $cache_token, WEEK_IN_SECONDS );
		}

		return $cache_token;
	}

	/**
	 * Clear cache token.
	 */
	public static function clear_cache_token() {
		delete_transient( 'themegrill_sdk_cache_token' );
	}

	/**
	 * Register product into SDK.
	 *
	 * @param string $base_file The product base file.
	 *
	 * @return Loader The singleton object.
	 */
	public static function add_product( $base_file ) {
		if ( ! is_file( $base_file ) ) {
			return self::$instance;
		}
		$product = new Product( $base_file );

		ModuleFactory::attach( $product, self::get_modules() );

		self::$products[ $product->get_slug() ] = $product;

		return self::$instance;
	}

	/**
	 * Activate the product routine.
	 *
	 * @param string $file The base file of the product.
	 *
	 * @return void
	 */
	public static function activate( $file ) {

		$dirname = trailingslashit( dirname( ( $file ) ) );
		if ( ! file_exists( $dirname . '_reference.php' ) ) {
			return;
		}
		$reference_data = require_once $dirname . '_reference.php';
		if ( ! is_array( $reference_data ) ||
		! isset( $reference_data['key'] ) ||
		! isset( $reference_data['value'] ) ||
		! preg_match( '/^[a-zA-Z0-9_]+_reference_key$/', $reference_data['key'] ) ) {
			return;
		}
		add_option( $reference_data['key'], sanitize_key( $reference_data['value'] ) );
	}
	/**
	 * Get all registered modules by the SDK.
	 *
	 * @return array Modules available.
	 */
	public static function get_modules() {
		return self::$available_modules;
	}

	/**
	 * Get all products using the SDK.
	 *
	 * @return array<Product> Products available.
	 */
	public static function get_products() {
		return self::$products;
	}

	/**
	 * Get the version of the SDK.
	 *
	 * @return string The version.
	 */
	public static function get_version() {
		return self::$version;
	}
}
