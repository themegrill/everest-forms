<?php
/**
 * Template Loader
 *
 * @package EverestForms\Classes
 * @version 1.3.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template loader class.
 */
class EVF_Template_Loader {

	/**
	 * Store the form ID.
	 *
	 * @var integer
	 */
	private static $form_id = 0;

	/**
	 * Store whether we're processing a form preview inside the_content filter.
	 *
	 * @var boolean
	 */
	private static $in_content_filter = false;

	/**
	 * Hook in methods.
	 */
	public static function init() {
		self::$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! is_admin() && isset( $_GET['evf_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ) );
			add_filter( 'edit_post_link', array( __CLASS__, 'edit_form_link' ) );
			add_filter( 'home_template_hierarchy', array( __CLASS__, 'template_include' ) );
			add_filter( 'frontpage_template_hierarchy', array( __CLASS__, 'template_include' ) );
			add_action( 'template_redirect', array( __CLASS__, 'form_preview_init' ) );
			add_filter( 'astra_remove_entry_header_content', '__return_true' ); // Need to remove in next version, If astra release the patches.
		} elseif ( isset( $_GET['evf_email_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['evf_email_preview'] ) ) : '' ) {
			add_filter( 'template_include', array( __CLASS__, 'email_preview_init' ) );
		} else {
			add_filter( 'template_include', array( __CLASS__, 'template_loader' ) );
		}
	}

	/**
	 * Hook into pre_get_posts to limit posts.
	 *
	 * @param WP_Query $q Query instance.
	 */
	public static function pre_get_posts( $q ) {
		// Limit one post to query.
		if ( $q->is_main_query() ) {
			$q->set( 'posts_per_page', 1 );
		}
	}

	/**
	 * Change edit link of preview page.
	 *
	 * @param string $link Edit post link.
	 */
	public static function edit_form_link( $link ) {
		if ( 0 < self::$form_id ) {
			return '<a href="' . esc_url( admin_url( 'admin.php?page=evf-builder&tab=fields&form_id=' . self::$form_id ) ) . '" class="post-edit-link">' . esc_html__( 'Edit Form', 'everest-forms' ) . '</a>';
		}

		return $link;
	}

	/**
	 *  A list of template candidates.
	 *
	 * @param array $templates A list of template candidates, in descending order of priority.
	 *
	 * @return array
	 */
	public static function template_include( $templates ) {
		$templates = apply_filters( 'everest_forms_templates_includes', array( 'page.php', 'single.php', 'index.php' ), $templates );
		return $templates;
	}

	/**
	 * Load a template.
	 *
	 * Handles template usage so that we can use our own templates instead of the themes.
	 *
	 * Templates are in the 'templates' folder. everest-forms looks for theme.
	 * overrides in /theme/everest-forms/ by default.
	 *
	 * For beginners, it also looks for a everest-forms.php template first. If the user adds.
	 * this to the theme (containing a everest-forms() inside) this will be used for all.
	 * everest-forms templates.
	 *
	 * @param string $template Template to load.
	 * @return string
	 */
	public static function template_loader( $template ) {
		if ( is_embed() ) {
			return $template;
		}

		$default_file = self::get_template_loader_default_file();

		if ( $default_file ) {
			/**
			 * Filter hook to choose which files to find before EverestForms does it's own logic.
			 *
			 * @since 1.0.0
			 * @var array
			 */
			$search_files = self::get_template_loader_files( $default_file );
			$template     = locate_template( $search_files );

			if ( ! $template || EVF_TEMPLATE_DEBUG_MODE ) {
				$template = evf()->plugin_path() . '/templates/' . $default_file;
			}
		}

		return $template;
	}

	/**
	 * Get the default filename for a template.
	 *
	 * @since  1.0.0
	 * @return string
	 */
	private static function get_template_loader_default_file() {
		return '';
	}

	/**
	 * Get an array of filenames to search for a given template.
	 *
	 * @since  1.0.0
	 * @param  string $default_file The default file name.
	 * @return string[]
	 */
	private static function get_template_loader_files( $default_file ) {
		$search_files   = apply_filters( 'everest_forms_template_loader_files', array(), $default_file );
		$search_files[] = 'everest-forms.php';

		if ( is_page_template() ) {
			$search_files[] = get_page_template_slug();
		}

		$search_files[] = $default_file;
		$search_files[] = evf()->template_path() . $default_file;

		return array_unique( $search_files );
	}

	/*
	|--------------------------------------------------------------------------
	| Form Preview Handling
	|--------------------------------------------------------------------------
	*/

	/**
	 * Hook in methods to enhance the form preview.
	 */
	public static function form_preview_init() {
		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}

		add_filter( 'template_include', array( __CLASS__, 'evf_form_preview_template' ), PHP_INT_MAX );
		// if ( 0 < self::$form_id ) {
		// add_filter( 'the_title', array( __CLASS__, 'form_preview_title_filter' ), 100, 1 );
		// add_filter( 'the_content', array( __CLASS__, 'form_preview_content_filter' ), 999 );
		// add_filter( 'get_the_excerpt', array( __CLASS__, 'form_preview_content_filter' ), 999 );
		// add_filter( 'post_thumbnail_html', '__return_empty_string' );
		// }
	}

	public static function evf_form_preview_template( $evf_form_preview_template ) {
		if ( is_embed() ) {
			return $evf_form_preview_template;
		}

		ob_start();
		if ( is_user_logged_in() && isset( $_GET['form_id'] ) ) {
			self::generate_form_preview();
		}
		$form_content = ob_get_clean();

		// Embed mode — the form ONLY, no toolbar/device-switcher/shortcode-box/side panel.
		// Used by the "Create with AI" screen's own preview iframe (see
		// src/templates/components/CreateWithAI.tsx), which already renders its own chrome
		// around the iframe; the full chrome below is for the builder toolbar's "Preview"
		// link, which deliberately opens this same route in a new tab as a standalone page.
		if ( ! empty( $_GET['evf_preview_mode'] ) && 'embed' === $_GET['evf_preview_mode'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// This route is loaded inside an iframe with no room to spare — the WP admin
			// toolbar has no purpose there and only pushes the form down/off.
			add_filter( 'show_admin_bar', '__return_false' );
			return evf_get_template( 'form-preview/evf-form-preview-embed.php', array( 'form_content' => $form_content ) );
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_register_style( 'evf-form-preview-style', evf()->plugin_url() . '/assets/css/evf-form-preview.css', array(), EVF_VERSION );
		wp_enqueue_style( 'evf-form-preview-style' );

		wp_register_script( 'evf-form-preview-script', evf()->plugin_url() . '/assets/js/frontend/evf-form-preview' . $suffix . '.js', array( 'jquery', 'tooltipster' ), EVF_VERSION );
		wp_enqueue_script( 'evf-form-preview-script' );
		wp_register_script( 'tooltipster', evf()->plugin_url() . '/assets/js/tooltipster/tooltipster.bundle' . $suffix . '.js', array( 'jquery' ), '4.6.2', true );

		wp_localize_script(
			'evf-form-preview-script',
			'everest_forms_form_preview ',
			array(
				'ajax_url'           => admin_url( 'admin-ajax.php' ),
				'form_preview_nonce' => wp_create_nonce( 'evf_form_preview_nonce' ),
				'pro_upgrade_link'   => esc_url( 'https://everestforms.net/upgrade/?utm_medium=evf-form-preview&utm_source=evf-free&utm_campaign=preview-sidebar-btn&utm_content=Upgrade%20to%20Pro' ),
			)
		);

		ob_start();
		self::side_panel_content();

		$side_panel_content = ob_get_clean();
		$template           = evf_get_template(
			'form-preview/evf-form-preview-template.php',
			array(
				'form_content'       => $form_content,
				'side_panel_content' => $side_panel_content,
			)
		);

		return $template;
	}

	/*
	|--------------------------------------------------------------------------
	| Email Preview Handling
	|--------------------------------------------------------------------------
	*/

	/**
	 * Hook in methods to enhance the form preview.
	 */
	public static function email_preview_init() {
		if ( ! is_user_logged_in() || is_admin() || ! current_user_can( 'everest_forms_edit_form', self::$form_id ) ) {
			return;
		}

		$email_preview = evf()->plugin_path() . '/templates/emails/email-preview.php';
		return $email_preview;
	}

	/**
	 * Filter the title and insert form preview title.
	 *
	 * @param  string $title Existing title.
	 * @return string
	 */
	public static function form_preview_title_filter( $title ) {
		$form = evf()->form->get(
			self::$form_id,
			array(
				'content_only' => true,
			)
		);

		if ( ! empty( $form['settings']['form_title'] ) && in_the_loop() ) {
			if ( is_customize_preview() ) {
				return esc_html( sanitize_text_field( $form['settings']['form_title'] ) );
			}

			/* translators: %s - Form name. */
			return sprintf( esc_html__( '%s &ndash; Preview', 'everest-forms' ), sanitize_text_field( $form['settings']['form_title'] ) );
		}

		return $title;
	}

	/**
	 * Filter the content and insert form preview content.
	 *
	 * @param  string $content Existing post content.
	 * @return string
	 */
	public static function form_preview_content_filter( $content ) {
		if ( ! is_user_logged_in() || ! is_main_query() ) {
			return $content;
		}

		self::$in_content_filter = true;

		// Remove the filter we're in to avoid nested calls.
		remove_filter( 'the_content', array( __CLASS__, 'form_preview_content_filter' ) );

		if ( current_user_can( 'everest_forms_view_forms', self::$form_id ) ) {
			if ( function_exists( 'apply_shortcodes' ) ) {
				$content = apply_shortcodes( '[everest_form id="' . absint( self::$form_id ) . '"]' );
			} else {
				$content = do_shortcode( '[everest_form id="' . absint( self::$form_id ) . '"]' );
			}
		}

		self::$in_content_filter = false;

		return $content;
	}


	/**
	 * Handles the preview of form.
	 *
	 * @since 3.2.2
	 */
	public static function generate_form_preview() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( isset( $_GET['form_id'] ) ) {
			$form_id = absint( $_GET['form_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			$html = '';
			$html .= '<div class="evf-preview-content">';
			// Embed mode's caller (the "Create with AI" screen) already shows the form
			// title in its OWN header above the iframe — this span would just duplicate it.
			if ( empty( $_GET['evf_preview_mode'] ) || 'embed' !== $_GET['evf_preview_mode'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$html .= '<span class="evf-form-preview-title">';
				$html .= esc_html( get_the_title( $form_id ) );
				$html .= '</span>';
			}

			$shortcode = sprintf( '[everest_form id="%d"]', $form_id );

			if ( function_exists( 'apply_shortcodes' ) ) {
				$content = apply_shortcodes( $shortcode );
			} else {
				$content = do_shortcode( $shortcode );
			}
			$html .= $content;
			$html .= '</div>';

			echo $html;
		}
	}

	/**
	 * Side panel content.
	 *
	 * @since 4.0
	 */
	public static function side_panel_content() {

		$is_pro_active = is_plugin_active( 'everest-forms-pro/everest-forms-pro.php' );
		if ( ! $is_pro_active ) {
			$heading      = esc_html__( 'Our Pro Features.', 'everest-forms' );
			$pro_features = array(
				esc_html__( 'Stripe & PayPal Integration', 'everest-forms' ),
				esc_html__( 'Style Export & Import', 'everest-forms' ),
				esc_html__( 'Conditional Email Routing', 'everest-forms' ),
				esc_html__( 'Advanced Form Fields', 'everest-forms' ),
				esc_html__( 'Quiz & Survey Forms', 'everest-forms' ),
				esc_html__( '40+ Integrations', 'everest-forms' ),
				esc_html__( 'Multi-Step Forms', 'everest-forms' ),
				esc_html__( 'SMS Notifications', 'everest-forms' ),
				esc_html__( 'Calculated Fields', 'everest-forms' ),

			);
		} else {
			$heading      = esc_html__( 'Our Top Addons', 'everest-forms' );
			$pro_features = array(
				esc_html__( 'Style Customizer', 'everest-forms' ),
				esc_html__( 'Custom Captcha', 'everest-forms' ),
				esc_html__( 'Multi Part Forms', 'everest-forms' ),
				esc_html__( 'PDF Form Submission', 'everest-forms' ),
				esc_html__( 'Email Templates', 'everest-forms' ),
				esc_html__( 'Survey, Polls and Quiz', 'everest-forms' ),
				esc_html__( 'Paypal Standard', 'everest-forms' ),
				esc_html__( 'Repeater Fields', 'everest-forms' ),
			);
		}
		$is_theme_style = get_post_meta( $_GET['form_id'], 'everest_forms_enable_theme_style', true );
		if ( 'default' === $is_theme_style ) {
			$checked    = '';
			$data_theme = 'default';
		} else {
			$checked    = 'checked';
			$data_theme = 'theme';
		}
		$html  = '';
		$html .= '<div class="evf-from-preview-theme-toggle">';
		$html .= '<label class="evf-form-preview-toggle-title">' . esc_html__( 'Apply Theme Style', 'everest-forms' ) . '</label>';
		$html .= '<span class="evf-form-preview-toggle-theme-preview">';
		$html .= '<input type="checkbox" class="evf-form-preview-theme-toggle-checkbox input-checkbox " id="evf_toggle_form_preview_theme" ' . $checked . '>';
		$html .= '<span class="slider round"></span>';
		$html .= '</span>';
		$html .= '</div>';
		$html .= '<div class="evf-form-preview-save hidden" id="evf-form-save" data-theme="' . $data_theme . '" data-id="' . absint( $_GET['form_id'] ) . '">';
		$html .= '<img src="' . esc_url( evf()->plugin_url() . '/assets/images/save-frame.svg' ) . '" alt="Save">';
		$html .= '<div class="evf-form-preview-save-title">' . esc_html__( 'Save', 'everest-forms' ) . '</div>';
		$html .= '</div>';
		$html .= '<div class="evf-form-preview-pro-features">';
		$html .= '<p class="evf-form-preview-pro-features-title">' . esc_html__( $heading, 'everest-forms' ) . '</p>';
		foreach ( $pro_features as $list ) {
			$html .= '<div class="evf-form-preview-sidebar__body--list-item">';

			$html .= '<svg xmlns="http://www.w3.org/2000/svg"  viewBox="0 0 18 18" fill="none">
						<path d="M15 5.25L6.75 13.5L3 9.75" stroke="#4CC741" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>';

			$html .= '<span>';
			$html .= wp_kses_post( $list );
			$html .= '</span>';
			$html .= '</div>';

		}
		if ( ! $is_pro_active ) {
			$html .= '<div class="evf-form-preview-upgrade  id="evf-form-save" data-theme="default" ">';
			$html .= '<img src="' . esc_url( evf()->plugin_url() . '/assets/images/upgrade-boost-icon.svg' ) . '" alt="Upgrade">';
			$html .= '<div class="evf-form-preview-upgrade-title">Upgrade to Pro</div>';
			$html .= '</div>';
		}

		echo $html; // phpcs:ignore

		?>
		<?php
	}
}

add_action( 'init', array( 'EVF_Template_Loader', 'init' ) );
