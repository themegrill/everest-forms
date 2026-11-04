<?php
/**
 * Addons main files.
 *
 * @since 3.0.5
 * @package EverestForms\Addons\Addons
 */

namespace EverestForms\Addons;

use EverestForms\Addons\BricksBuilder\BricksBuilder;
use EverestForms\Addons\OxygenBuilder\OxygenBuilder;
use EverestForms\Addons\StyleCustomizer\StyleCustomizer;
use EverestForms\Addons\DiviBuilder\DiviBuilder;
use EverestForms\Addons\BeaverBuilder\BeaverBuilder;
use EverestForms\Addons\CleanTalk\CleanTalk;
use EverestForms\Addons\ShieldSilentCaptcha\ShieldSilentCaptcha;
use EverestForms\Addons\WPBakeryBuilder\WPBakeryBuilder;
use EverestForms\Traits\Singleton;

/**
 * Addon class.
 *
 * @since 3.0.5
 */
class Addons {

	use Singleton;

	/**
	 * Class constructor.
	 *
	 * @since 3.0.5
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'addons_init' ) );
		add_action( 'init', array( $this, 'not_addons_init' ) );
		add_filter( 'everest_forms_integrations', array( $this, 'add_integration' ) );
	}

	/**
	 * Register integration.
	 *
	 * @param array $integrations List of integrations.
	 *
	 * @since 3.2.2
	 */
	public function add_integration( $integrations ) {

		$no_module_classes = array(
			'clean-talk' => 'EverestForms\Addons\CleanTalk\Settings\Settings',
		);

		foreach ( $no_module_classes as $key => $class_name ) {
			$integrations[] = $class_name;
		}

		return $integrations;
	}
	/**
	 * Get addon list.
	 *
	 * @since 3.0.5
	 */
	public function get_addon_list() {
		/**
		 * Everest forms addon list.
		 *
		 * @since 3.0.5
		 * @return array List of addon class.
		 */
		return apply_filters(
			'everest_forms_addon_list',
			array(
				'oxygen-builder'   => OxygenBuilder::class,
				'bricks-builder'   => BricksBuilder::class,
				'divi-builder'     => DiviBuilder::class,
				'beaver-builder'   => BeaverBuilder::class,
				'wpbakery-builder' => WPBakeryBuilder::class,
				'style-customizer' => StyleCustomizer::class,
			)
		);
	}

	/**
	 * Addons but not showcase in dashboard.
	 *
	 * @since 3.2.0
	 */
	public function not_addons_init() {
		$addons = array(
			'clean-talk'            => CleanTalk::class,
			'shield-silent-captcha' => ShieldSilentCaptcha::class,
		);

		foreach ( $addons as $key => $class_name ) {
			if ( class_exists( $class_name ) ) {
				$class_name::init();
			}
		}
	}

	/**
	 * Initializes the Everest Forms addons.
	 *
	 * @since 3.0.5
	 */
	public function addons_init() {

		$classes = $this->get_addon_list();

		if ( empty( $classes ) ) {
			return;
		}

		$enabled_features = get_option( 'everest_forms_enabled_features', array() );
		$new_feature      = 'everest-forms-style-customizer';
		if ( false === get_option( 'everest_forms_style_enabled' ) && ! in_array( $new_feature, $enabled_features, true ) ) {
			$enabled_features[] = $new_feature;
			update_option( 'everest_forms_style_enabled', true );
			update_option( 'everest_forms_enabled_features', $enabled_features );
		}

		if ( empty( $enabled_features ) ) {
			return;
		}

		foreach ( $classes as $key => $class_name ) {
			$key = 'everest-forms-' . $key;
			if ( in_array( $key, $enabled_features, true ) ) {
				if ( class_exists( $class_name ) ) {
					$class_name::init();
				}
			}
		}
	}
}

// Lint pilot test.
