<?php
/**
 * Handle frontend scripts
 *
 * @class   EVF_Frontend_Scripts
 * @version 1.0.0
 * @package EverestForms/Classes/
 */

defined( 'ABSPATH' ) || exit;

/**
 * EVF_Frontend_Scripts Class.
 */
class EVF_Frontend_Scripts {

	/**
	 * Contains an array of script handles registered by EVF.
	 *
	 * @var array
	 */
	private static $scripts = array();

	/**
	 * Contains an array of script handles registered by EVF.
	 *
	 * @var array
	 */
	private static $styles = array();

	/**
	 * Contains an array of script handles localized by EVF.
	 *
	 * @var array
	 */
	private static $wp_localize_scripts = array();

	/**
	 * Hook in methods.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'load_scripts' ) );
		add_action( 'wp_print_scripts', array( __CLASS__, 'localize_printed_scripts' ), 5 );
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'localize_printed_scripts' ), 5 );
		add_action( 'everest_forms_shortcode_scripts', array( __CLASS__, 'enqueue_frontend_styles' ) );
	}

	/**
	 * Get styles for the frontend.
	 *
	 * @return array
	 */
	public static function get_styles() {
		$general_css_path = plugin_dir_path( EVF_PLUGIN_FILE ) . 'assets/css/everest-forms.css';
		$general_version  = is_readable( $general_css_path ) ? (string) filemtime( $general_css_path ) : EVF_VERSION;

		return apply_filters(
			'everest_forms_enqueue_styles',
			array(
				'everest-forms-general' => array(
					'src'     => self::get_asset_url( 'assets/css/everest-forms.css' ),
					'deps'    => '',
					'version' => $general_version,
					'media'   => 'all',
					'has_rtl' => true,
				),
			)
		);
	}

	/**
	 * Return asset URL.
	 *
	 * @param string $path Assets path.
	 *
	 * @return string
	 */
	private static function get_asset_url( $path ) {
		return apply_filters( 'everest_forms_get_asset_url', plugins_url( $path, EVF_PLUGIN_FILE ), $path );
	}

	/**
	 * Register a script for use.
	 *
	 * @uses   wp_register_script()
	 * @param  string   $handle    Name of the script. Should be unique.
	 * @param  string   $path      Full URL of the script, or path of the script relative to the WordPress root directory.
	 * @param  string[] $deps      An array of registered script handles this script depends on.
	 * @param  string   $version   String specifying script version number, if it has one, which is added to the URL as a query string for cache busting purposes. If version is set to false, a version number is automatically added equal to current installed WordPress version. If set to null, no version is added.
	 * @param  boolean  $in_footer Whether to enqueue the script before </body> instead of in the <head>. Default 'false'.
	 */
	private static function register_script( $handle, $path, $deps = array( 'jquery' ), $version = EVF_VERSION, $in_footer = true ) {
		self::$scripts[] = $handle;
		wp_register_script( $handle, $path, $deps, $version, $in_footer );
	}

	/**
	 * Register and enqueue a script for use.
	 *
	 * @uses   wp_enqueue_script()
	 * @param  string   $handle    Name of the script. Should be unique.
	 * @param  string   $path      Full URL of the script, or path of the script relative to the WordPress root directory.
	 * @param  string[] $deps      An array of registered script handles this script depends on.
	 * @param  string   $version   String specifying script version number, if it has one, which is added to the URL as a query string for cache busting purposes. If version is set to false, a version number is automatically added equal to current installed WordPress version. If set to null, no version is added.
	 * @param  boolean  $in_footer Whether to enqueue the script before </body> instead of in the <head>. Default 'false'.
	 */
	private static function enqueue_script( $handle, $path = '', $deps = array( 'jquery' ), $version = EVF_VERSION, $in_footer = true ) {
		if ( ! in_array( $handle, self::$scripts, true ) && $path ) {
			self::register_script( $handle, $path, $deps, $version, $in_footer );
		}
		wp_enqueue_script( $handle );
	}

	/**
	 * Register a style for use.
	 *
	 * @uses   wp_register_style()
	 * @param  string   $handle  Name of the stylesheet. Should be unique.
	 * @param  string   $path    Full URL of the stylesheet, or path of the stylesheet relative to the WordPress root directory.
	 * @param  string[] $deps    An array of registered stylesheet handles this stylesheet depends on.
	 * @param  string   $version String specifying stylesheet version number, if it has one, which is added to the URL as a query string for cache busting purposes. If version is set to false, a version number is automatically added equal to current installed WordPress version. If set to null, no version is added.
	 * @param  string   $media   The media for which this stylesheet has been defined. Accepts media types like 'all', 'print' and 'screen', or media queries like '(orientation: portrait)' and '(max-width: 640px)'.
	 * @param  boolean  $has_rtl If has RTL version to load too.
	 */
	private static function register_style( $handle, $path, $deps = array(), $version = EVF_VERSION, $media = 'all', $has_rtl = false ) {
		self::$styles[] = $handle;
		wp_register_style( $handle, $path, $deps, $version, $media );

		if ( $has_rtl ) {
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}
	}

	/**
	 * Register and enqueue a styles for use.
	 *
	 * @uses   wp_enqueue_style()
	 * @param  string   $handle  Name of the stylesheet. Should be unique.
	 * @param  string   $path    Full URL of the stylesheet, or path of the stylesheet relative to the WordPress root directory.
	 * @param  string[] $deps    An array of registered stylesheet handles this stylesheet depends on.
	 * @param  string   $version String specifying stylesheet version number, if it has one, which is added to the URL as a query string for cache busting purposes. If version is set to false, a version number is automatically added equal to current installed WordPress version. If set to null, no version is added.
	 * @param  string   $media   The media for which this stylesheet has been defined. Accepts media types like 'all', 'print' and 'screen', or media queries like '(orientation: portrait)' and '(max-width: 640px)'.
	 * @param  boolean  $has_rtl If has RTL version to load too.
	 */
	private static function enqueue_style( $handle, $path = '', $deps = array(), $version = EVF_VERSION, $media = 'all', $has_rtl = false ) {
		if ( ! in_array( $handle, self::$styles, true ) && $path ) {
			self::register_style( $handle, $path, $deps, $version, $media, $has_rtl );
		}
		wp_enqueue_style( $handle );
	}

	/**
	 * Register all EVF scripts.
	 */
	private static function register_scripts() {
		if ( evf_is_amp() ) {
			return;
		}

		$suffix               = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$ajax_submission_path = plugin_dir_path( EVF_PLUGIN_FILE ) . 'assets/js/frontend/ajax-submission' . $suffix . '.js';
		$ajax_submission_ver  = is_readable( $ajax_submission_path ) ? (string) filemtime( $ajax_submission_path ) : EVF_VERSION;
		$everest_forms_path   = plugin_dir_path( EVF_PLUGIN_FILE ) . 'assets/js/frontend/everest-forms' . $suffix . '.js';
		$everest_forms_ver    = is_readable( $everest_forms_path ) ? (string) filemtime( $everest_forms_path ) : EVF_VERSION;
		$register_scripts     = array(
			'inputmask'                              => array(
				'src'     => self::get_asset_url( 'assets/js/inputmask/jquery.inputmask.bundle' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '4.0.0-beta.58',
			),
			'flatpickr'                              => array(
				'src'     => self::get_asset_url( 'assets/js/flatpickr/flatpickr' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '4.6.3',
			),
			'mailcheck'                              => array(
				'src'     => self::get_asset_url( 'assets/js/mailcheck/mailcheck' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '1.1.2',
			),
			'selectWoo'                              => array(
				'src'     => self::get_asset_url( 'assets/js/selectWoo/selectWoo.full' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '1.0.8',
			),
			'jquery-intl-tel-input'                  => array(
				'src'     => self::get_asset_url( '/assets/js/intlTelInput/jquery.intlTelInput' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '16.0.7',
			),
			'jquery-validate'                        => array(
				'src'     => self::get_asset_url( 'assets/js/jquery-validate/jquery.validate' . $suffix . '.js' ),
				'deps'    => array( 'jquery' ),
				'version' => '1.21.0',
			),
			'everest-forms'                          => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/everest-forms' . $suffix . '.js' ),
				'deps'    => array( 'jquery', 'inputmask', 'jquery-validate', 'jquery-intl-tel-input', 'selectWoo' ),
				'version' => $everest_forms_ver,
			),
			'everest-forms-text-limit'               => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/text-limit' . $suffix . '.js' ),
				'deps'    => array(),
				'version' => EVF_VERSION,
			),
			'everest-forms-ajax-submission'          => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/ajax-submission' . $suffix . '.js' ),
				'deps'    => array( 'jquery', 'inputmask', 'jquery-validate' ),
				'version' => $ajax_submission_ver,
			),
			'everest-forms-survey-polls-quiz-script' => array(
				'src'     => self::get_asset_url( 'assets/js/frontend/everest-forms-survey-polls-quiz' . $suffix . '.js' ),
				'deps'    => array(),
				'version' => EVF_VERSION,
			),
		);

		foreach ( $register_scripts as $name => $props ) {

			self::register_script( $name, $props['src'], $props['deps'], $props['version'] );
		}
	}

	/**
	 * Register all EVF sty;es.
	 */
	private static function register_styles() {
		$register_styles = array(
			'evf_select2'           => array(
				'src'     => self::get_asset_url( 'assets/css/select2.css' ),
				'deps'    => array(),
				'version' => EVF_VERSION,
				'has_rtl' => false,
			),
			'flatpickr'             => array(
				'src'     => self::get_asset_url( 'assets/css/flatpickr.css' ),
				'deps'    => array(),
				'version' => EVF_VERSION,
				'has_rtl' => false,
			),
			'jquery-intl-tel-input' => array(
				'src'     => self::get_asset_url( 'assets/css/intlTelInput.css' ),
				'deps'    => array(),
				'version' => EVF_VERSION,
				'has_rtl' => false,
			),
		);
		foreach ( $register_styles as $name => $props ) {
			self::register_style( $name, $props['src'], $props['deps'], $props['version'], 'all', $props['has_rtl'] );
		}
	}

	/**
	 * Register/queue frontend scripts.
	 */
	public static function load_scripts() {
		global $post;

		if ( ! did_action( 'before_everest_forms_init' ) ) {
			return;
		}

		self::register_scripts();
		self::register_styles();

		if ( self::current_page_has_form() ) {
			// Dashicons is only used for the form-selector block's notice icon.
			wp_enqueue_style( 'dashicons' );

			self::enqueue_frontend_styles();
		}
	}

	/**
	 * Whether the current request is known to render an Everest Forms form.
	 *
	 * Scans the main post content for the `[everest_form]` shortcode, the form-selector block,
	 * or any other registered shortcode sharing the `everest_form` tag prefix (addons such as
	 * user registration/login use their own shortcode tags, e.g. `[everest_forms_user_login]`).
	 * The `everest_forms_has_form_on_page` filter can override the result.
	 *
	 * @return bool
	 */
	public static function current_page_has_form() {
		$has_form = false;
		$post     = get_post();

		if ( $post instanceof WP_Post ) {
			$content   = (string) $post->post_content;
			$shortcode = apply_filters( 'everest_form_shortcode_tag', 'everest_form' );
			$has_form  = has_shortcode( $content, $shortcode )
				|| has_block( 'everest-forms/form-selector', $post )
				|| self::content_has_everest_forms_shortcode( $content );
		}

		return (bool) apply_filters( 'everest_forms_has_form_on_page', $has_form, $post );
	}

	/**
	 * Whether content contains any registered shortcode sharing the `everest_form` tag prefix.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private static function content_has_everest_forms_shortcode( $content ) {
		if ( false === strpos( $content, '[' ) || ! preg_match_all( '/' . get_shortcode_regex() . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return false;
		}

		foreach ( $matches as $shortcode ) {
			if ( 0 === strpos( $shortcode[2], 'everest_form' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enqueue the general frontend form stylesheet(s).
	 *
	 * @return void
	 */
	public static function enqueue_frontend_styles() {
		$enqueue_styles = self::get_styles();
		if ( $enqueue_styles ) {
			foreach ( $enqueue_styles as $handle => $args ) {
				if ( ! isset( $args['has_rtl'] ) ) {
					$args['has_rtl'] = false;
				}

				self::enqueue_style( $handle, $args['src'], $args['deps'], $args['version'], $args['media'], $args['has_rtl'] );
			}

			self::maybe_print_late_styles( array_keys( $enqueue_styles ) );
		}
	}

	/**
	 * Ensure the given style handle(s) actually get printed even when they're enqueued after
	 * wp_head's style flush has already run -- e.g. a page builder, widget, or archive listing
	 * renders the form outside what current_page_has_form() can detect in advance.
	 *
	 * Calling wp_enqueue_style() alone is only picked up automatically by that flush. Anything
	 * enqueued afterward is, depending on theme type and WordPress version, either silently
	 * dropped, hoisted into <head> (WP 6.9+, classic themes only), or printed as a footer flash
	 * of unstyled content. Printing immediately here removes that dependency entirely and keeps
	 * any such flash as short as possible, since the stylesheet request starts right where the
	 * form itself renders instead of waiting for the end of the page.
	 *
	 * @param string|string[] $handles Style handle(s) to ensure get printed.
	 * @return void
	 */
	public static function maybe_print_late_styles( $handles ) {
		if ( did_action( 'wp_print_styles' ) ) {
			wp_print_styles( (array) $handles );
		}
	}

	/**
	 * Whether payment debug logging is enabled for the current request.
	 *
	 * @return bool
	 */
	private static function is_payment_debug_enabled() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Localize a EVF script once.
	 *
	 * @param string $handle Script handle the data will be attached to.
	 */
	private static function localize_script( $handle ) {
		if ( ! in_array( $handle, self::$wp_localize_scripts, true ) && wp_script_is( $handle ) ) {
			$data = self::get_script_data( $handle );

			if ( ! $data ) {
				return;
			}

			$name                        = str_replace( '-', '_', $handle ) . '_params';
			self::$wp_localize_scripts[] = $handle;
			wp_localize_script( $handle, $name, apply_filters( $name, $data ) );
		}
	}

	/**
	 * Return data for script handles.
	 *
	 * @param  string $handle Script handle the data will be attached to.
	 * @return array|bool
	 */
	private static function get_script_data( $handle ) {
		switch ( $handle ) {
			case 'everest-forms':
				$params = array(
					'ajax_url'                             => evf()->ajax_url(),
					'submit'                               => esc_html__( 'Submit', 'everest-forms' ),
					'disable_user_details'                 => get_option( 'everest_forms_disable_user_details' ),
					'everest_forms_data_save'              => wp_create_nonce( 'everest_forms_data_save_nonce' ),
					'everest_forms_slot_booking'           => wp_create_nonce( 'everest_forms_slot_booking_nonce' ),
					'i18n_messages_required'               => esc_html( get_option( 'everest_forms_required_validation' ) ),
					'i18n_messages_url'                    => esc_html( get_option( 'everest_forms_url_validation' ) ),
					'i18n_messages_email'                  => esc_html( get_option( 'everest_forms_email_validation' ) ),
					'i18n_messages_email_suggestion'       => esc_html( get_option( 'everest_forms_email_suggestion', __( 'Did you mean {suggestion}?', 'everest-forms' ) ) ),
					'i18n_messages_email_suggestion_title' => esc_attr__( 'Click to accept this suggestion.', 'everest-forms' ),
					'i18n_messages_confirm'                => esc_html( get_option( 'everest_forms_confirm_validation', __( 'Field values do not match.', 'everest-forms' ) ) ),
					'i18n_messages_check_limit'            => esc_html( get_option( 'everest_forms_check_limit_validation', __( 'You have exceeded number of allowed selections: {#}.', 'everest-forms' ) ) ),
					'i18n_messages_number'                 => esc_html( get_option( 'everest_forms_number_validation' ) ),
					'i18n_no_matches'                      => _x( 'No matches found', 'enhanced select', 'everest-forms' ),
					'mailcheck_enabled'                    => (bool) apply_filters( 'everest_forms_mailcheck_enabled', true ),
					'mailcheck_domains'                    => array_map( 'sanitize_text_field', (array) apply_filters( 'everest_forms_mailcheck_domains', array() ) ),
					'mailcheck_toplevel_domains'           => array_map( 'sanitize_text_field', (array) apply_filters( 'everest_forms_mailcheck_toplevel_domains', array( 'dev' ) ) ),
					'il8n_min_word_length_err_msg'         => esc_html__( 'Please enter at least {0} words.', 'everest-forms' ),
					'il8n_min_character_length_err_msg'    => esc_html__( 'Please enter at least {0} characters.', 'everest-forms' ),
					'plugin_url'                           => plugin_dir_url( EVF_PLUGIN_FILE ),
					'i18n_messages_phone'                  => esc_html( get_option( 'everest_forms_phone_validation', __( 'Please enter a valid phone number.', 'everest-forms' ) ) ),
					'evf_smart_phone_allowed_countries'    => apply_filters( 'everest_forms_smart_phone_allowed_countries', array() ),
					'i18n_field_rating_greater_than_max_value_error' => esc_html__( 'Please enter in a value less than 100.', 'everest-forms' ),
					'evf_checked_image_url'                => esc_url( self::get_asset_url( 'assets/images/evf-checked.png' ) ),
					'i18n_evf_success_text'                => esc_html__( 'Success!', 'everest-forms' ),
				);
				break;
			case 'everest-forms-text-limit':
				$params = array(
					'i18n_messages_limit_characters' => esc_html__( '{count} of {limit} max characters.', 'everest-forms' ),
					'i18n_messages_limit_words'      => esc_html__( '{count} of {limit} max words.', 'everest-forms' ),
				);
				break;
			case 'everest-forms-ajax-submission':
				$params = array(
					'ajax_url'              => admin_url( 'admin-ajax.php' ),
					'evf_ajax_submission'   => wp_create_nonce( 'everest_forms_ajax_form_submission' ),
					'submit'                => esc_html__( 'Submit', 'everest-forms' ),
					'error'                 => esc_html__( 'Something went wrong while making an AJAX submission', 'everest-forms' ),
					'required'              => esc_html__( 'This field is required.', 'everest-forms' ),
					'pdf_download'          => esc_html__( 'Click here to download your pdf submission', 'everest-forms' ),
					'evf_checked_image_url' => esc_url( self::get_asset_url( 'assets/images/evf-checked.png' ) ),
					'i18n_evf_success_text' => esc_html__( 'Success!', 'everest-forms' ),
					'payment_debug'         => self::is_payment_debug_enabled() ? '1' : '0',
				);
				break;
			case 'everest-forms-survey-polls-quiz-script':
				$params = array(
					'ajax_url'   => admin_url( 'admin-ajax.php', 'relative' ),
					'ajax_nonce' => wp_create_nonce( 'process-ajax-nonce' ),
					'form_id'    => isset( $_GET['form_id'] ) ? wp_unslash( $_GET['form_id'] ) : '', //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
				);
				break;
			default:
				$params = false;
		}

		return apply_filters( 'everest_forms_get_script_data', $params, $handle );
	}

	/**
	 * Localize scripts only when enqueued.
	 */
	public static function localize_printed_scripts() {
		foreach ( self::$scripts as $handle ) {
			self::localize_script( $handle );
		}
	}
}

EVF_Frontend_Scripts::init();
