<?php
/**
 * Everest Forms blocks.
 *
 * @since 2.0.9
 * @package everest-forms
 */

defined( 'ABSPATH' ) || exit;
/**
 * Everest Forms blocks class.
 */
class EVF_Blocks {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Init hooks.
	 *
	 * @since 2.0.9
	 */
	private function init_hooks() {
		$this->includes();
		add_filter( 'block_categories_all', array( $this, 'block_categories' ), PHP_INT_MAX, 2 );
		add_action( 'init', array( $this, 'register_block_types' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
	}
	/**
	 * Includes the block type files.
	 *
	 * @since 0
	 */
	private function includes() {
		include_once EVF_ABSPATH . 'includes/blocks/block-types/class-evf-blocks-abstract.php';
		include_once EVF_ABSPATH . 'includes/blocks/block-types/class-evf-blocks-form-selector.php';
		include_once EVF_ABSPATH . 'includes/blocks/block-types/class-evf-blocks-frontend-listing.php';
		include_once EVF_ABSPATH . 'includes/blocks/block-types/class-evf-blocks-user-login.php';
	}
	/**
	 * Enqueue Block Editor Assets.
	 *
	 * @return void.
	 */
	public function enqueue_block_editor_assets() {
		wp_register_style(
			'everest-forms-block-editor',
			evf()->plugin_url() . '/assets/css/everest-forms.css',
			array( 'wp-edit-blocks' ),
			evf()->version
		);
		wp_enqueue_style( 'everest-forms-block-editor' );
		$enqueue_script = array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-editor', 'wp-components', 'react', 'react-dom' );
		wp_register_script(
			'everest-forms-block-editor',
			evf()->plugin_url() . '/dist/blocks.min.js',
			$enqueue_script,
			evf()->version,
			true
		);
		wp_register_script(
			'everest-forms-shortcode-embed-form',
			evf()->plugin_url() . '/assets/js/admin/shortcode-form-embed.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-dom-ready', 'wp-edit-post', 'jquery', 'jquery-blockui', 'jquery-ui-sortable', 'jquery-ui-widget', 'jquery-ui-core', 'tooltipster', 'wp-color-picker', 'perfect-scrollbar' ),
			defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? filemtime( evf()->plugin_path() . '/assets/js/admin/shortcode-form-embed.js' ) : EVF_VERSION,
			true
		);
		$forms = array();
		foreach ( (array) evf()->form->get_multiple( array( 'order' => 'DESC' ) ) as $form ) {
			$forms[] = array(
				'ID'         => $form->ID,
				'post_title' => $form->post_title,
			);
		}

		$form_block_data = array(
			'evfRestApiNonce'          => wp_create_nonce( 'wp_rest' ),
			'restURL'                  => rest_url(),
			'forms'                    => $forms,
			'isPro'                    => defined( 'EFP_VERSION' ) && version_compare( EFP_VERSION, '1.7.3', '>=' ) ? true : false,
			'isFrontendListingActive'  => defined( 'EVF_FRONTEND_LISTING_VERSION' ) && version_compare( EVF_FRONTEND_LISTING_VERSION, '1.0.0', '>=' ) ? true : false,
			'isUserRegistrationActive' => defined( 'EVF_USER_REGISTRATION_VERSION' ) && version_compare( EVF_USER_REGISTRATION_VERSION, '1.1.3', '>=' ) ? true : false,
		);

		wp_localize_script( 'everest-forms-block-editor', '_EVF_BLOCKS_', $form_block_data );
		$action_page = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( 'edit' === $action_page ) {
			wp_enqueue_script( 'everest-forms-shortcode-embed-form' );
		}
		wp_enqueue_script( 'everest-forms-block-editor' );
	}

	/**
	 * Add "Everest Forms" category to the blocks listing in post edit screen.
	 *
	 * @param array $block_categories All registered block categories.
	 * @return array
	 * @since 2.0.9
	 */
	public function block_categories( array $block_categories ) {

		return array_merge(
			array(
				array(
					'slug'  => 'everest-forms',
					'title' => esc_html__( 'Everest Forms', 'everest-forms' ),
				),
			),
			$block_categories
		);
	}
	/**
	 * Register block types.
	 *
	 * @return void
	 */
	public function register_block_types() {
		$block_types = $this->get_block_types();
		foreach ( $block_types as $block_type ) {
			new $block_type();
		}
	}

	/**
	 * Get block types.
	 *
	 * @return AbstractBlock[]
	 */
	private function get_block_types() {
		$is_pro                     = defined( 'EFP_VERSION' ) && version_compare( EFP_VERSION, '1.7.3', '>=' ) ? true : false;
		$is_frontendlisting_active  = defined( 'EVF_FRONTEND_LISTING_VERSION' ) && version_compare( EVF_FRONTEND_LISTING_VERSION, '1.0.0', '>=' ) ? true : false;
		$is_use_registration_active = defined( 'EVF_USER_REGISTRATION_VERSION' ) && version_compare( EVF_USER_REGISTRATION_VERSION, '1.1.3', '>=' ) ? true : false;
		$class                      = array(
			EVF_Blocks_Form_Selector::class, //phpcs:ignore;
		);
		if ( $is_pro && $is_frontendlisting_active ) {
			$class[]= EVF_Blocks_Frontend_Listing::class; //phpcs:ignore;
		}
		if ( $is_pro && $is_use_registration_active ) {
			$class[]= EVF_Blocks_User_Login::class; //phpcs:ignore;
		}
		return apply_filters(
			'everest_forms_block_types',
			$class
		);
	}
}
return new EVF_Blocks();
