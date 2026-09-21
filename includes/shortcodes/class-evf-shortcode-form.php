<?php
/**
 * Form Shortcode
 *
 * Used on the show frontend form.
 *
 * @package EverestForms\Shortcodes\Form
 * @version 1.0.0
 * @since   1.3.2
 */

defined( 'ABSPATH' ) || exit;

/**
 * Form Shortcode class.
 */
class EVF_Shortcode_Form {


	/**
	 * Contains information for multi-part forms.
	 *
	 * Forms that do not contain parts return false, otherwise returns an array
	 * that contains the number of total parts and part counter used when
	 * displaying part rows.
	 *
	 * @since 1.3.2
	 *
	 * @var array
	 */
	public static $parts = array();

	/**
	 * Hooks in tab.
	 */
	public static function hooks() {
		add_filter( 'amp_skip_post', array( 'EVF_Shortcode_Form', 'amp_skip_post' ) );
		add_action( 'everest_forms_frontend_output_success', 'evf_print_notices', 10, 2 );
		add_action( 'everest_forms_frontend_output', array( 'EVF_Shortcode_Form', 'header' ), 5, 4 );
		add_action( 'everest_forms_frontend_output', array( 'EVF_Shortcode_Form', 'fields' ), 10, 3 );
		add_action( 'everest_forms_display_field_before', array( 'EVF_Shortcode_Form', 'wrapper_start' ), 5, 2 );
		add_action( 'everest_forms_display_field_before', array( 'EVF_Shortcode_Form', 'label' ), 15, 2 );
		add_action( 'everest_forms_display_field_before', array( 'EVF_Shortcode_Form', 'description' ), 20, 2 );
		add_action( 'everest_forms_display_field_after', array( 'EVF_Shortcode_Form', 'messages' ), 3, 2 );
		add_action( 'everest_forms_display_field_after', array( 'EVF_Shortcode_Form', 'description' ), 5, 2 );
		add_action( 'everest_forms_display_field_after', array( 'EVF_Shortcode_Form', 'wrapper_end' ), 15, 2 );
		add_action( 'everest_forms_frontend_output', array( 'EVF_Shortcode_Form', 'honeypot' ), 15, 3 );
		add_action( 'everest_forms_frontend_output', array( 'EVF_Shortcode_Form', 'clean_talk' ), 15, 3 );
		if ( ! apply_filters( 'everest_forms_recaptcha_disabled', false ) ) {
			add_action( 'everest_forms_frontend_output', array( 'EVF_Shortcode_Form', 'recaptcha' ), 20, 3 );
		}
		add_action( 'everest_forms_frontend_output', array( 'EVF_Shortcode_Form', 'footer' ), 25, 3 );
		add_action( 'everest_forms_frontend_output', array( 'EVF_Shortcode_Form', 'inline_script_to_update_nonce' ), 26, 3 );

		// reCaptcha Language.
		add_filter( 'everest_forms_frontend_recaptcha_url', array( __CLASS__, 'evf_recaptcha_language' ), 10, 1 );

		// Enable for submission waiting time.
		add_filter( 'everest_forms_display_fields_before', array( 'EVF_Shortcode_Form', 'evf_form_submission_waiting_time' ) );
	}

	/**
	 * Get the amp-state ID for a given form.
	 *
	 * @param int $form_id Form ID.
	 * @return string State ID.
	 */
	protected static function get_form_amp_state_id( $form_id ) {
		return sprintf( 'evf_form_state_%d', $form_id );
	}

	/**
	 * Disable AMP if query param is detected.
	 *
	 * This allows the full form to be accessible for Pro users or sites
	 * that do not have SSL.
	 *
	 * @since 1.5.3
	 *
	 * @param bool $skip Skip AMP mode, display full post.
	 *
	 * @return bool
	 */
	public static function amp_skip_post( $skip ) {

		return isset( $_GET['nonamp'] ) ? true : $skip; // phpcs:ignore WordPress.Security.NonceVerification
	}


	/**
	 * Form footer area.
	 *
	 * @param array $form_data   Form data and settings.
	 * @param bool  $title       Whether to display form title.
	 * @param bool  $description Whether to display form description.
	 */
	public static function footer( $form_data, $title, $description ) {
		$form_id         = absint( $form_data['id'] );
		$settings        = isset( $form_data['settings'] ) ? $form_data['settings'] : array();
		$submit          = apply_filters( 'everest_forms_field_submit', isset( $settings['submit_button_text'] ) ? $settings['submit_button_text'] : __( 'Submit', 'everest-forms' ), $form_data );
		$submit_btn      = evf_string_translation( $form_data['id'], 'submit_button', $submit );
		$process         = '';
		$classes         = isset( $form_data['settings']['submit_button_class'] ) ? evf_sanitize_classes( $form_data['settings']['submit_button_class'] ) : '';
		$parts           = ! empty( self::$parts[ $form_id ] ) ? self::$parts[ $form_id ] : array();
		$visible         = ! empty( $parts ) ? 'style="display:none"' : '';
		$attrs           = array( 'aria-live' => 'assertive' );
		$data_attrs      = array();
		$evf_amp_classes = array();

		// Visibility class.
		$visibility_class = apply_filters( 'everest_forms_field_submit_visibility_class', array(), $parts, $form_data );

		// Check for submit button processing-text.
		if ( ! isset( $settings['submit_button_processing_text'] ) ) {
			$process = 'data-process-text="' . esc_attr__( 'Processing&hellip;', 'everest-forms' ) . '"';
		} elseif ( ! empty( $settings['submit_button_processing_text'] ) ) {
			if ( evf_is_amp() ) {
				$attrs['[text]'] = sprintf(
					'%s.submitting ? %s : %s',
					self::get_form_amp_state_id( $form_id ),
					wp_json_encode( $settings['submit_button_processing_text'], JSON_UNESCAPED_UNICODE ),
					wp_json_encode( $submit, JSON_UNESCAPED_UNICODE )
				);
			} else {
				$process = 'data-process-text="' . esc_attr( evf_string_translation( $form_data['id'], 'processing_text', $settings['submit_button_processing_text'] ) ) . '"';
			}
		}

		// Submit button area.
		$conditional_id = 'evf-submit-' . $form_id;
		if ( isset( $form_data['settings']['submit']['connection_1']['conditional_logic_status'] ) && '1' === $form_data['settings']['submit']['connection_1']['conditional_logic_status'] ) {
			$con_rules = array(
				'conditional_option' => isset( $form_data['settings']['submit']['connection_1']['conditional_option'] ) ? $form_data['settings']['submit']['connection_1']['conditional_option'] : '',
				'conditionals'       => isset( $form_data['settings']['submit']['connection_1']['conditionals'] ) ? $form_data['settings']['submit']['connection_1']['conditionals'] : '',
			);
		} else {
			$con_rules = '';
		}

		$conditional_rules = wp_json_encode( $con_rules );

		echo '<div class="evf-submit-container ' . esc_attr( implode( ' ', $visibility_class ) ) . '" >';

		echo '<input type="hidden" name="everest_forms[id]" value="' . absint( $form_id ) . '">';

		echo '<input type="hidden" name="everest_forms[author]" value="' . absint( get_the_author_meta( 'ID' ) ) . '">';

		if ( is_singular() ) {
			echo '<input type="hidden" name="everest_forms[post_id]" value="' . absint( get_the_ID() ) . '">';
		}

		do_action( 'everest_forms_display_submit_before', $form_data );

		printf(
			"<button type='submit' name='everest_forms[submit]' class='everest-forms-submit-button button evf-submit %s' id='evf-submit-%d' value='evf-submit' %s conditional_rules='%s' conditional_id='%s' %s %s>%s</button>",
			esc_attr( $classes ),
			esc_attr( $form_id ),
			! isset( $settings['submit_button_processing_text'] ) ? 'data-process-text="' . esc_attr__( 'Processing&hellip;', 'everest-forms' ) . '"' : ( ! empty( $settings['submit_button_processing_text'] ) ? 'data-process-text="' . esc_attr( evf_string_translation( $form_data['id'], 'processing_text', $settings['submit_button_processing_text'] ) ) . '"' : '' ),
			esc_attr( $conditional_rules ),
			esc_attr( $conditional_id ),
			( ! empty( self::$parts[ $form_id ] ) ? 'style="display:none"' : '' ),
			evf_html_attributes(
				sprintf( 'evf-submit-%d', absint( $form_id ) ),
				$evf_amp_classes,
				$data_attrs,
				$attrs
			),
			esc_html( $submit_btn )
		);

		do_action( 'everest_forms_display_submit_after', $form_data );

		echo '</div>';

		if ( evf_is_amp() ) {
			printf( '<div submit-success><template type="amp-mustache"><div class=" everest-forms-notice everest-forms-notice--success {{#redirecting}}evf-redirection-message{{/redirecting}}">{{{message}}}</div></template></div>' );
			return;
		}
	}

	/**
	 * Message.
	 *
	 * @param array $field Field.
	 * @param array $form_data Form data.
	 */
	public static function messages( $field, $form_data ) {
		$error = $field['properties']['error'];

		if ( empty( $error['value'] ) || is_array( $error['value'] ) ) {
			return;
		}

		printf(
			'<label %s>%s</label>',
			evf_html_attributes( $error['id'], $error['class'], $error['data'], $error['attr'] ),
			esc_html( $error['value'] )
		);
	}

	/**
	 * Description.
	 *
	 * @param array $field Field.
	 * @param array $form_data Form data.
	 */
	public static function description( $field, $form_data ) {
		$action = current_action();

		$description = $field['properties']['description'];

		// If the description is empty don't proceed.
		if ( empty( $description['value'] ) ) {
			return;
		}

		// Determine positioning.
		if ( 'everest_forms_display_field_before' === $action && 'before' !== $description['position'] ) {
			return;
		}
		if ( 'everest_forms_display_field_after' === $action && 'after' !== $description['position'] ) {
			return;
		}

		if ( 'before' === $description['position'] ) {
			$description['class'][] = 'evf-field-description-before';
		}

		printf(
			'<div %s>%s</div>',
			evf_html_attributes( $description['id'], $description['class'], $description['data'], $description['attr'] ),
			wp_kses_post( evf_string_translation( $form_data['id'], $field['id'], $description['value'], '-description' ) )
		);
	}

	/**
	 * Label.
	 *
	 * @param array $field Field.
	 * @param array $form_data Form data.
	 */
	public static function label( $field, $form_data ) {

		$label = $field['properties']['label'];
		// If the label is empty or disabled don't proceed.
		if ( empty( $label['value'] ) || $label['disabled'] ) {
			return;
		}

		$required    = $label['required'] ? apply_filters( 'everest_forms_field_required_label', '<abbr class="required" title="' . esc_attr__( 'Required', 'everest-forms' ) . '">' . apply_filters( 'everest_form_get_required_type', '*', $field, $form_data ) . '</abbr>' ) : '';
		$custom_tags = apply_filters( 'everest_forms_field_custom_tags', false, $field, $form_data );

		printf(
			'<label %s><span class="evf-label">%s</span> %s</label>',
			evf_html_attributes( $label['id'], $label['class'], $label['data'], $label['attr'] ),
			wp_kses(
				evf_string_translation(
					$form_data['id'],
					$field['id'],
					wp_kses(
						$label['value'],
						array(
							'a'      => array(
								'href'  => array(),
								'class' => array(),
							),
							'span'   => array(
								'class' => array(),
							),
							'em'     => array(),
							'small'  => array(),
							'strong' => array(),
						)
					)
				),
				array(
					'a'      => array(
						'href'  => array(),
						'class' => array(),
					),
					'span'   => array(
						'class' => array(),
					),
					'em'     => array(),
					'small'  => array(),
					'strong' => array(),
				)
			),
			wp_kses_post( $required ),
			wp_kses_post( $custom_tags )
		);
	}

	/**
	 * Wrapper end.
	 *
	 * @param array $field Field.
	 * @param array $form_data Form data.
	 */
	public static function wrapper_end( $field, $form_data ) {
		echo '</div>';
	}

	/**
	 * Wrapper start.
	 *
	 * @param array $field Field.
	 * @param array $form_data Form data.
	 */
	public static function wrapper_start( $field, $form_data ) {
		$container                     = $field['properties']['container'];
		$container['data']['field-id'] = esc_attr( $field['id'] );
		printf(
			'<div %s>',
			evf_html_attributes( $container['id'], $container['class'], $container['data'], $container['attr'] )
		);
	}

	/**
	 * Form header for displaying form title and description if enabled.
	 *
	 * @param array $form_data   Form data and settings.
	 * @param bool  $title       Whether to display form title.
	 * @param bool  $description Whether to display form description.
	 * @param array $errors      List of all errors during form submission.
	 */
	public static function header( $form_data, $title, $description, $errors ) {
		$settings = isset( $form_data['settings'] ) ? $form_data['settings'] : array();

		// Check if title and/or description is enabled.
		if ( true === $title || true === $description ) {
			echo '<div class="evf-title-container">';

			if ( true === $title && ! empty( $settings['form_title'] ) ) {
				echo '<div class="everest-forms--title">' . esc_html( evf_string_translation( $form_data['id'], 'form_title', $settings['form_title'] ) ) . '</div>';
			}

			if ( true === $description && ! empty( $settings['form_description'] ) ) {
				echo '<div class="everest-forms--description">' . esc_textarea( evf_string_translation( $form_data['id'], 'form_description', $settings['form_description'] ) ) . '</div>';
			}

			echo '</div>';
		}

		// Output header errors if they exist.
		if ( ! empty( $errors['header'] ) ) {
			evf_add_notice( $errors['header'], 'error' );
		}
	}

	/**
	 * Form field area.
	 *
	 * @param array $form_data   Form data and settings.
	 * @param bool  $title       Whether to display form title.
	 * @param bool  $description Whether to display form description.
	 */
	public static function fields( $form_data, $title, $description ) {
		$structure = isset( $form_data['structure'] ) ? $form_data['structure'] : array();

		// Bail if empty form fields.
		if ( empty( $form_data['form_fields'] ) ) {
			return;
		}

		// Form fields area.
		echo '<div class="evf-field-container">';

		wp_nonce_field( 'everest-forms_process_submit', '_wpnonce' . $form_data['id'] );

		/**
		 * Hook: everest_forms_display_fields_before.
		 *
		 * @hooked EverestForms_MultiPart::display_fields_before() Multi-Part markup open.
		 */
		do_action( 'everest_forms_display_fields_before', $form_data );

		foreach ( $structure as $row_key => $row ) {
			/**
			 * Hook: everest_forms_display_repeater_fields.
			 *
			 * @hooked EVF_Repeater_Fields->display_repeater_fields() Display Repeater Fields.
			 */
			$is_repeater = apply_filters( 'everest_forms_display_repeater_fields', false, $row, $form_data, true );

			/**
			 * Hook: everest_forms_display_row_before.
			 */
			do_action( 'everest_forms_display_row_before', $row_key, $form_data );

			$conditions = array();

			if ( ! empty( $form_data['settings']['form_rows'][ 'connection_' . $row_key ] ) && ! empty( $form_data['settings']['form_rows'][ 'connection_' . $row_key ]['conditional_logic_status'] ) ) {
				$conditions = ! empty( $form_data['settings']['form_rows'][ 'connection_' . $row_key ] ) ? $form_data['settings']['form_rows'][ 'connection_' . $row_key ] : array();
			}

			echo '<div class="evf-frontend-row" data-row="' . esc_attr($row_key) . '"' . esc_attr($is_repeater) . ' conditional_rules="' . (isset($conditions) ? esc_attr(wp_json_encode($conditions)) : '') . '">'; // @codingStandardsIgnoreLine

			foreach ( $row as $grid_key => $grid ) {
				$number_of_grid = count( $row );

				echo '<div class="evf-frontend-grid evf-grid-' . absint( $number_of_grid ) . '" data-grid="' . esc_attr( $grid_key ) . '">';

				if ( ! is_array( $grid ) ) {
					$grid = array();
				}

				foreach ( $grid as $field_key ) {
					$field = isset( $form_data['form_fields'][ $field_key ] ) ? $form_data['form_fields'][ $field_key ] : array();
					$field = apply_filters( 'everest_forms_field_data', $field, $form_data );

					if ( empty( $field ) || evf_is_field_locked( $field['type'] ) ) {
						continue;
					}

					$should_display_field = apply_filters( "everest_forms_should_display_field_{$field['type']}", true, $field, $form_data );

					if ( true !== $should_display_field ) {
						continue;
					}

					// Get field attributes.
					$attributes = self::get_field_attributes( $field, $form_data );

					do_action( 'everest_forms_display_before_field_wrapper', $field, $form_data );

					// Get field properties.
					$properties = self::get_field_properties( $field, $form_data, $attributes );

					// Add properties to the field so it's available everywhere.
					$field['properties'] = $properties;

					do_action( 'everest_forms_display_field_before', $field, $form_data );

					do_action( "everest_forms_display_field_{$field['type']}", $field, $attributes, $form_data );

					do_action( 'everest_forms_display_field_after', $field, $form_data );

					do_action( 'everest_forms_display_after_field_wrapper', $field, $form_data );
				}

				echo '</div>';
			}

			/**
			 * Hook: everest_forms_add_remove_buttons.
			 *
			 * @hooked EVF_Repeater_Fields->add_remove_buttons() Show Add and Remove buttons.
			 */
			do_action( 'everest_forms_add_remove_buttons', $row, $form_data, $is_repeater );

			echo '</div>';

			/**
			 * Hook: everest_forms_display_row_after.
			 *
			 * @hooked EverestForms_MultiPart::display_row_after() Multi-Part markup (close previous part, open next).
			 */
			do_action( 'everest_forms_display_row_after', $row_key, $form_data );
		}

		/**
		 * Hook: everest_forms_display_fields_after.
		 *
		 * @hooked EverestForms_MultiPart::display_fields_after() Multi-Part markup open.
		 */
		do_action( 'everest_forms_display_fields_after', $form_data );

		echo '</div>';
	}

	/**
	 * Anti-spam honeypot output if configured.
	 *
	 * @since 1.4.9
	 * @param array $form_data   Form data and settings.
	 */
	public static function honeypot( $form_data ) {
		$names = array( 'Name', 'Phone', 'Comment', 'Message', 'Email', 'Website' );

		// Output the honeypot container.
		if ( isset( $form_data['settings']['honeypot'] ) && '1' === $form_data['settings']['honeypot'] ) {
			echo '<div class="evf-honeypot-container evf-field-hp">';

			echo '<label for="evf-' . esc_attr( $form_data['id'] ) . '-field-hp" class="evf-field-label">' . esc_attr( $names[ array_rand( $names ) ] ) . '</label>';

			echo '<input type="text" name="everest_forms[hp]" id="evf-' . esc_attr( $form_data['id'] ) . '-field-hp" class="input-text">';

			echo '</div>';
		}
	}

	/**
	 * Anti-spam CleanTalk output if configured.
	 *
	 * @since 3.2.2
	 *
	 * @param  [type] $form_data Form data.
	 */
	public static function clean_talk( $form_data ) {

		$is_cleantalk_activated = isset( $form_data['settings']['cleantalk'] ) ? $form_data['settings']['cleantalk'] : false;

		if ( ! $is_cleantalk_activated ) {
			return;
		}

		$detector_js_url   = 'https://fd.cleantalk.org/ct-bot-detector-wrapper.js';
		$clean_talk_inline = <<<'JS'
		document.addEventListener("DOMContentLoaded", function () {
			var loadInput = document.querySelector('input[name="everest_forms[evf_form_load_time]"]');
			if (loadInput) {
				loadInput.value = Math.floor(Date.now() / 1000);
			}

			var maxAttempts = 10;
			var attempts = 0;
			var token = false;
			var interval = setInterval( function () {
				var lStorage = localStorage.getItem('bot_detector_event_token');
				if ( lStorage !== null ) {
					try {
						lStorage = JSON.parse( lStorage );
						if ( lStorage.hasOwnProperty( 'value' )  && typeof lStorage.value === 'string' ) {
							token = lStorage.value;
						}
					} catch ( e ) {
						token = null;
					}
				}
				if ( token ) {
					var eventInput = document.querySelector( 'input[name="everest_forms[evf_event_token]"]' );
					if ( eventInput ) {
						eventInput.value = token;
					}
					clearInterval( interval );
				}
				if ( ++attempts >= maxAttempts ) {
					clearInterval( interval );
				}
			}, 500);
		});
JS;

		// Enqueue cleanTalk scripts.
		wp_enqueue_script(
			'evf-clan-talk',
			$detector_js_url,
			array( 'jquery' ),
			EVF_VERSION,
			true
		);

		wp_add_inline_script( 'evf-clan-talk', $clean_talk_inline );

		if ( isset( $form_data['settings']['cleantalk'] ) && '1' === $form_data['settings']['cleantalk'] ) {
			echo '<input type="hidden" name="everest_forms[evf_event_token]" value="">';
			echo '<input type="hidden" name="everest_forms[evf_form_load_time]" class="evf_form_load_time" value="">';
		}
	}

	/**
	 * Google reCAPTCHA output if configured.
	 *
	 * @param array $form_data Form data and settings.
	 */
	public static function recaptcha( $form_data ) {
		$recaptcha_type      = get_option( 'everest_forms_recaptcha_type', 'v2' );
		$invisible_recaptcha = get_option( 'everest_forms_recaptcha_v2_invisible', 'no' );

		if ( 'v2' === $recaptcha_type && 'no' === $invisible_recaptcha ) {
			$site_key   = get_option( 'everest_forms_recaptcha_v2_site_key' );
			$secret_key = get_option( 'everest_forms_recaptcha_v2_secret_key' );
		} elseif ( 'v2' === $recaptcha_type && 'yes' === $invisible_recaptcha ) {
			$site_key   = get_option( 'everest_forms_recaptcha_v2_invisible_site_key' );
			$secret_key = get_option( 'everest_forms_recaptcha_v2_invisible_secret_key' );
		} elseif ( 'v3' === $recaptcha_type ) {
			$site_key   = get_option( 'everest_forms_recaptcha_v3_site_key' );
			$secret_key = get_option( 'everest_forms_recaptcha_v3_secret_key' );
		} elseif ( 'hcaptcha' === $recaptcha_type ) {
			$site_key   = get_option( 'everest_forms_recaptcha_hcaptcha_site_key' );
			$secret_key = get_option( 'everest_forms_recaptcha_hcaptcha_secret_key' );
		} elseif ( 'turnstile' === $recaptcha_type ) {
			$site_key   = get_option( 'everest_forms_recaptcha_turnstile_site_key' );
			$secret_key = get_option( 'everest_forms_recaptcha_turnstile_secret_key' );
			$theme      = get_option( 'everest_forms_recaptcha_turnstile_theme' );
			$lang       = get_option( 'everest_forms_recaptcha_recaptcha_language', 'en-GB' );
		} else {
			$site_key   = '';
			$secret_key = '';
		}

		if ( ! $site_key || ! $secret_key ) {
			return;
		}
		// Check that the CAPTCHA is configured for the specific form.
		if (
			! isset( $form_data['settings']['recaptcha_support'] ) ||
			'1' !== $form_data['settings']['recaptcha_support']
		) {
			return;
		}
		if ( evf_is_amp() ) {
			if ( 'v3' === $recaptcha_type ) {
				printf(
					'<amp-recaptcha-input name="everest_forms[recaptcha]" data-sitekey="%s" data-action="%s" layout="nodisplay"></amp-recaptcha-input>',
					esc_attr( $site_key ),
					esc_attr( 'evf_' . $form_data['id'] )
				);
			}
			return; // Only v3 is supported in AMP.
		}

		if ( isset( $form_data['settings']['recaptcha_support'] ) && '1' === $form_data['settings']['recaptcha_support'] ) {
			$form_id = isset( $form_data['id'] ) ? absint( $form_data['id'] ) : 0;
			$visible = ! empty( self::$parts[ $form_id ] ) ? 'style="display:none;"' : '';
			$data    = apply_filters(
				'everest_forms_frontend_recaptcha',
				array(
					'sitekey' => trim( sanitize_text_field( $site_key ) ),
				),
				$form_data
			);

			// Load reCAPTCHA support if form supports it.
			if ( $site_key && $secret_key ) {
				if ( 'v2' === $recaptcha_type ) {
					$recaptcha_api = apply_filters( 'everest_forms_frontend_recaptcha_url', 'https://www.google.com/recaptcha/api.js?onload=EVFRecaptchaLoad&render=explicit', $recaptcha_type, $form_id );

					if ( 'yes' === $invisible_recaptcha ) {
						$data['size']     = 'invisible';
						$recaptcha_inline = 'var EVFRecaptchaLoad = function(){jQuery(".g-recaptcha").each(function(index, el){var recaptchaID = grecaptcha.render(el,{callback:function(){EVFRecaptchaCallback(el);}},true);   el.closest("form").querySelector("button[type=submit]").recaptchaID = recaptchaID;});};
						var EVFRecaptchaCallback = function (el) {
							var $form = el.closest("form");
							if( typeof jQuery !==  "undefined" ){
								if( "1" === jQuery( $form ).attr( "data-ajax_submission" ) ) {
									el.closest( "form" ).querySelector( "button[type=submit]" ).recaptchaID = "verified";
									jQuery( $form ).find( ".evf-submit" ).trigger( "click" );
								} else {
									$form.submit();
								}
								grecaptcha.reset();
							}
						};
						';
					} else {
						$recaptcha_inline  = 'var EVFRecaptchaLoad = function(){jQuery(".g-recaptcha").each(function(index, el){var recaptchaID =  grecaptcha.render(el,{callback:function(){EVFRecaptchaCallback(el);}},true);jQuery(el).attr( "data-recaptcha-id", recaptchaID);});};';
						$recaptcha_inline .= 'var EVFRecaptchaCallback = function(el){jQuery(el).parent().find(".evf-recaptcha-hidden").val("1").trigger("change").valid();};';
					}
				} elseif ( 'v3' === $recaptcha_type ) {
					$recaptcha_api     = apply_filters( 'everest_forms_frontend_recaptcha_url', 'https://www.google.com/recaptcha/api.js?render=' . $site_key, $recaptcha_type, $form_id );
					$recaptcha_inline  = 'var EVFRecaptchaLoad = function(){grecaptcha.execute("' . esc_html( $site_key ) . '",{action:"everest_form"}).then(function(token){var f=document.getElementsByName("everest_forms[recaptcha]");for(var i=0;i<f.length;i++){f[i].value = token;}});};grecaptcha.ready(EVFRecaptchaLoad);setInterval(EVFRecaptchaLoad, 110000);';
					$recaptcha_inline .= 'grecaptcha.ready(function(){grecaptcha.execute("' . esc_html( $site_key ) . '",{action:"everest_form"}).then(function(token){var f=document.getElementsByName("everest_forms[recaptcha]");for(var i=0;i<f.length;i++){f[i].value = token;}});});';
				} elseif ( 'hcaptcha' === $recaptcha_type ) {
					$recaptcha_api     = apply_filters( 'everest_forms_frontend_recaptcha_url', 'https://hcaptcha.com/1/api.js??onload=EVFRecaptchaLoad&render=explicit', $recaptcha_type, $form_id );
					$recaptcha_inline  = 'var EVFRecaptchaLoad = function(){jQuery(".g-recaptcha").each(function(index, el){var recaptchaID =  hcaptcha.render(el,{callback:function(){EVFRecaptchaCallback(el);}},true);jQuery(el).attr( "data-recaptcha-id", recaptchaID);});};';
					$recaptcha_inline .= 'var EVFRecaptchaCallback = function(el){jQuery(el).parent().find(".evf-recaptcha-hidden").val("1").trigger("change").valid();};';
				} elseif ( 'turnstile' === $recaptcha_type ) {
					$recaptcha_api     = apply_filters( 'everest_forms_frontend_recaptcha_url', 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=EVFTurnstileLoad&render=explicit', $recaptcha_type, $form_id );
					$recaptcha_inline  = 'var EVFTurnstileLoad = function(){jQuery(".g-recaptcha").each(function(index, el){var recaptchaID =  turnstile.render(el,{theme:"' . $theme . '",language:"' . $lang . '",callback:function(){EVFRecaptchaCallback(el);}},true);jQuery(el).attr( "data-recaptcha-id", recaptchaID);});};';
					$recaptcha_inline .= 'var EVFRecaptchaCallback = function(el){jQuery(el).parent().find(".evf-recaptcha-hidden").val("1").trigger("change").valid();};';
				}

				// Enqueue reCaptcha scripts.
				wp_enqueue_script(
					'evf-recaptcha',
					$recaptcha_api,
					'v3' === $recaptcha_type ? array() : array( 'jquery' ),
					'v3' === $recaptcha_type ? '3.0.0' : '2.0.0',
					true
				);

				// Load reCaptcha callback once.
				static $count = 1;
				if ( 1 === $count ) {
					wp_add_inline_script( 'evf-recaptcha', $recaptcha_inline );
					++$count;
				}

				// Output the reCAPTCHA container.
				$class = ( 'v3' === $recaptcha_type || ( 'v2' === $recaptcha_type && 'yes' === $invisible_recaptcha ) ) ? 'recaptcha-hidden' : '';
				echo '<div class="evf-recaptcha-container ' . esc_attr( $class ) . '" style="display:' . ( ! empty( self::$parts[ $form_id ] ) ? 'none' : 'block' ) . '">';

				if ( 'v2' === $recaptcha_type || 'hcaptcha' === $recaptcha_type || 'turnstile' === $recaptcha_type ) {
					echo '<div ' . evf_html_attributes( '', array( 'g-recaptcha' ), $data ) . '></div>';

					if ( ( 'hcaptcha' === $recaptcha_type && 'no' === $invisible_recaptcha ) || 'turnstile' === $recaptcha_type ) {
						echo '<input type="text" name="g-recaptcha-hidden" class="evf-recaptcha-hidden" style="position:absolute!important;clip:rect(0,0,0,0)!important;height:1px!important;width:1px!important;border:0!important;overflow:hidden!important;padding:0!important;margin:0!important;" required>';
					}
				} else {
					echo '<input type="hidden" name="everest_forms[recaptcha]" value="">';
				}

				echo '</div>';
			}
		}
	}

	/**
	 * Get field attributes.
	 *
	 * @param array $field Field.
	 * @param array $form_data Form data.
	 *
	 * @return array
	 */
	private static function get_field_attributes( $field, $form_data ) {
		$form_id    = absint( $form_data['id'] );
		$field_id   = esc_attr( $field['id'] );
		$attributes = array(
			'field_class'       => array( 'evf-field', 'evf-field-' . sanitize_html_class( $field['type'] ), 'form-row' ),
			'field_id'          => array( sprintf( 'evf-%d-field_%s-container', $form_id, $field_id ) ),
			'field_style'       => '',
			'label_class'       => array( 'evf-field-label' ),
			'label_id'          => '',
			'description_class' => array( 'evf-field-description' ),
			'description_id'    => array(),
			'input_id'          => array( sprintf( 'evf-%d-field_%s', $form_id, $field_id ) ),
			'input_class'       => array(),
			'input_data'        => array(),
		);

		// Check user field defined classes.
		if ( ! empty( $field['css'] ) ) {
			$attributes['field_class'] = array_merge( $attributes['field_class'], evf_sanitize_classes( $field['css'], true ) );
		}

		// Check for input column layouts.
		if ( ! empty( $field['input_columns'] ) ) {
			if ( 'inline' === $field['input_columns'] ) {
				$attributes['field_class'][] = 'everest-forms-list-inline';
			} elseif ( '' !== $field['input_columns'] ) {
				$attributes['field_class'][] = 'everest-forms-list-' . $field['input_columns'] . '-columns';
			}
		}

		// Input class.
		if ( ! in_array( $field['type'], array( 'checkbox', 'radio', 'payment-checkbox', 'payment-multiple' ), true ) ) {
			$attributes['input_class'][] = 'input-text';
		}

		// Check label visibility.
		if ( ! empty( $field['label_hide'] ) ) {
			$attributes['label_class'][] = 'evf-label-hide';
		}

		// Check size.
		if ( ! empty( $field['size'] ) ) {
			$attributes['input_class'][] = 'evf-field-' . sanitize_html_class( $field['size'] );
		}

		// Check if required.
		if ( ! empty( $field['required'] ) ) {
			$attributes['field_class'][] = 'validate-required';
		}

		// Check if extra validation required.
		if ( in_array( $field['type'], array( 'email', 'phone' ), true ) ) {
			$attributes['field_class'][] = 'validate-' . esc_attr( $field['type'] );
		}

		// Check if there are errors.
		if ( isset( evf()->task->errors[ $form_id ][ $field_id ] ) ) {
			$attributes['input_class'][] = 'evf-error';
			$attributes['field_class'][] = 'everest-forms-invalid';
		}

		// This filter is deprecated, filter the properties (below) instead.
		$attributes = apply_filters( 'evf_field_atts', $attributes, $field, $form_data );

		return $attributes;
	}

	/**
	 * Return base properties for a specific field.
	 *
	 * @param array $field      Field data and settings.
	 * @param array $form_data  Form data and settings.
	 * @param array $attributes List of field attributes.
	 *
	 * @return array
	 */
	public static function get_field_properties( $field, $form_data, $attributes = array() ) {
		if ( empty( $attributes ) ) {
			$attributes = self::get_field_attributes( $field, $form_data );
		}

		// This filter is for backwards compatibility purposes.
		$types = array( 'text', 'textarea', 'number', 'email', 'hidden', 'url', 'html', 'title', 'password', 'phone', 'address', 'checkbox', 'radio', 'select' );
		if ( in_array( $field['type'], $types, true ) ) {
			$field = apply_filters( "everest_forms_{$field['type']}_field_display", $field, $attributes, $form_data );
		}

		$form_id  = absint( $form_data['id'] );
		$field_id = sanitize_text_field( $field['id'] );

		// Field container data.
		$container_data = array();

		// Embed required-field-message to the container if the field is required.
		if ( isset( $field['required'] ) && ( '1' === $field['required'] || true === $field['required'] ) ) {
			$has_sub_fields      = false;
			$sub_field_messages  = array();
			$required_validation = get_option( 'everest_forms_required_validation' );
			if ( in_array( $field['type'], array( 'number', 'email', 'url', 'phone' ), true ) ) {
				$required_validation = get_option( 'everest_forms_' . $field['type'] . '_validation' );
			}

			if ( 'likert' === $field['type'] ) {
				$has_sub_fields = true;
				$likert_rows    = isset( $field['likert_rows'] ) ? $field['likert_rows'] : array();
				$row_keys       = array();
				foreach ( $likert_rows as $row_key => $row_label ) {
					$row_keys[] = $row_key;
					$row_slug   = 'required-field-message-' . $row_key;

					$error_message                  = isset( $field[ $row_slug ] ) ? evf_string_translation( $form_data['id'], $field['id'], $field[ $row_slug ], '-' . $row_slug ) : $required_validation;
					$sub_field_messages[ $row_key ] = htmlspecialchars( wp_kses( html_entity_decode( $error_message ), array() ) );
				}
				$container_data['row-keys'] = wp_json_encode( $row_keys );
			} elseif ( 'address' === $field['type'] ) {
				$has_sub_fields     = true;
				$sub_field_messages = array(
					'address1' => isset( $field['required_field_message_setting'] ) && 'global' === $field['required_field_message_setting'] ? $required_validation : evf_string_translation( $form_data['id'], $field['id'], $field['required-field-message-address1'], '-required-field-message-address1' ),
					'city'     => isset( $field['required_field_message_setting'] ) && 'global' === $field['required_field_message_setting'] ? $required_validation : evf_string_translation( $form_data['id'], $field['id'], $field['required-field-message-city'], '-required-field-message-city' ),
					'state'    => isset( $field['required_field_message_setting'] ) && 'global' === $field['required_field_message_setting'] ? $required_validation : evf_string_translation( $form_data['id'], $field['id'], $field['required-field-message-state'], '-required-field-message-state' ),
					'postal'   => isset( $field['required_field_message_setting'] ) && 'global' === $field['required_field_message_setting'] ? $required_validation : evf_string_translation( $form_data['id'], $field['id'], $field['required-field-message-postal'], '-required-field-message-postal' ),
					'country'  => isset( $field['required_field_message_setting'] ) && 'global' === $field['required_field_message_setting'] ? $required_validation : evf_string_translation( $form_data['id'], $field['id'], $field['required-field-message-country'], '-required-field-message-country' ),
				);
			}

			if ( true === $has_sub_fields ) {
				foreach ( $sub_field_messages as $sub_field_type => $error_message ) {
					$container_data[ 'required-field-message-' . $sub_field_type ] = htmlspecialchars( wp_kses( html_entity_decode( $error_message ), array() ) );
				}
			} elseif ( isset( $field['required_field_message_setting'] ) && 'global' === $field['required_field_message_setting'] ) {
					$container_data['required-field-message'] = htmlspecialchars( wp_kses( html_entity_decode( $required_validation ), array() ) );
			} elseif ( isset( $field['required-field-message'] ) && '' !== $field['required-field-message'] ) {
				$required_data                            = evf_string_translation( $form_data['id'], $field['id'], $field['required-field-message'], '-required-field-message' );
				$container_data['required-field-message'] = htmlspecialchars( wp_kses( html_entity_decode( $required_data ), array() ) );
			} else {
				$container_data['required-field-message'] = htmlspecialchars( wp_kses( html_entity_decode( $required_validation ), array() ) );
			}
		}
		$errors   = isset( evf()->task->errors[ $form_id ][ $field_id ] ) ? evf()->task->errors[ $form_id ][ $field_id ] : '';
		$defaults   = isset($_POST['everest_forms']['form_fields'][$field_id]) && (! is_array($_POST['everest_forms']['form_fields'][$field_id]) && ! empty($_POST['everest_forms']['form_fields'][$field_id])) ? $_POST['everest_forms']['form_fields'][$field_id] : ''; // @codingStandardsIgnoreLine

		/**
		 *  Count the number of smart tags in the default value which contain form fields smart tag.
		 *
		 * @since 3.2.3
		 */
		$count         = 0;
		$default_value = '';

		if ( isset( $field['default_value'] ) ) {
			preg_match_all( '/\{field_id="(.+?)"\}/', $field['default_value'], $ids );
			if ( ! empty( $ids[1] ) ) {
				++$count;
			}
		}

		if ( $count > 0 ) {
			$is_hidden = isset( $field['hidden_field_visibility'] ) ? evf_string_to_bool( $field['hidden_field_visibility'] ) : ( isset( $field['type'] ) && 'hidden' === $field['type'] ? true : false );

			if ( $is_hidden ) {
				$default_value = isset( $field['default_value'] ) ? apply_filters( 'everest_forms_process_smart_tags', $field['default_value'], $form_data ) : $defaults;
			} else {
				$default_value = isset( $field['default_value'] ) ? '' : $defaults;
			}
		} else {
			$default_value = isset( $field['default_value'] ) ? apply_filters( 'everest_forms_process_smart_tags', $field['default_value'], $form_data ) : $defaults;
		}

		$properties = apply_filters(
			'everest_forms_field_properties_' . $field['type'],
			array(
				'container'   => array(
					'attr'  => array(
						'style' => $attributes['field_style'],
					),
					'class' => $attributes['field_class'],
					'data'  => $container_data,
					'id'    => implode( '', array_slice( $attributes['field_id'], 0 ) ),
				),
				'label'       => array(
					'attr'     => array(
						'for' => sprintf( 'evf-%d-field_%s', $form_id, $field_id ),
					),
					'class'    => $attributes['label_class'],
					'data'     => array(),
					'disabled' => ! empty( $field['label_disable'] ) ? true : false,
					'hidden'   => ! empty( $field['label_hide'] ) ? true : false,
					'id'       => $attributes['label_id'],
					'required' => ! empty( $field['required'] ) ? true : false,
					'value'    => ! empty( $field['label'] ) ? $field['label'] : '',
				),
				'inputs'      => array(
					'primary' => array(
						'attr'     => array(
							'name'        => "everest_forms[form_fields][{$field_id}]",
							'value'       => $default_value,
							'placeholder' => isset( $field['placeholder'] ) ? evf_string_translation( $form_data['id'], $field['id'], $field['placeholder'], '-placeholder' ) : '',
						),
						'class'    => $attributes['input_class'],
						'data'     => $attributes['input_data'],
						'id'       => implode( array_slice( $attributes['input_id'], 0 ) ),
						'required' => ! empty( $field['required'] ) ? 'required' : '',
					),
				),
				'error'       => array(
					'attr'  => array(
						'for' => sprintf( 'evf-%d-field_%s', $form_id, $field_id ),
					),
					'class' => array( 'evf-error' ),
					'data'  => array(),
					'id'    => '',
					'value' => ! empty( $errors ) ? $errors : '',
				),
				'description' => array(
					'attr'     => array(),
					'class'    => $attributes['description_class'],
					'data'     => array(),
					'id'       => implode( '', array_slice( $attributes['description_id'], 0 ) ),
					'position' => 'after',
					'value'    => ! empty( $field['description'] ) ? $field['description'] : '',
				),
			),
			$field,
			$form_data
		);

		return apply_filters( 'everest_forms_field_properties', $properties, $field, $form_data );
	}

	/**
	 * Output the shortcode.
	 *
	 * @param array $atts Attributes.
	 */
	public static function output( $atts ) {
		wp_enqueue_script( 'everest-forms' );

		$form_id     = isset( $atts['id'] ) ? absint( $atts['id'] ) : 0;
		$form_fields = evf_get_form_fields( $form_id );
		$form_data   = EVF()->form->get( $form_id, array( 'content_only' => true ) );
		$form_fields = isset( $form_data['form_fields'] ) ? $form_data['form_fields'] : array();

		foreach ( $form_fields as $form_field ) {
			if (
				( isset( $form_field['type'] ) && in_array( $form_field['type'], array( 'rating', 'likert', 'yes-no', 'scale-rating' ), true ) ) ||
				( isset( $form_field['survey_status'] ) && absint( $form_field['survey_status'] ) === 1 ) ||
				( isset( $form_field['quiz_status'] ) && absint( $form_field['quiz_status'] ) === 1 )
			) {
				wp_enqueue_script( 'everest-forms-survey-polls-quiz-script' );
			}
		}

		// Load jQuery flatpickr libraries. https://github.com/flatpickr/flatpickr.
		if ( isset( $atts['id'] ) && evf_is_field_exists( $atts['id'], 'date-time' ) ) {
			wp_enqueue_style( 'flatpickr' );
			wp_enqueue_script( 'flatpickr' );
		}

		// Load jQuery mailcheck library - https://github.com/mailcheck/mailcheck.
		if ( isset( $atts['id'] ) && evf_is_field_exists( $atts['id'], 'email' ) && (bool) apply_filters( 'everest_forms_mailcheck_enabled', true ) ) {
			wp_enqueue_script( 'mailcheck' );
		}

		// Load the international telephone input styles only when the form has a phone field.
		if ( isset( $atts['id'] ) && evf_is_field_exists( $atts['id'], 'phone' ) ) {
			wp_enqueue_style( 'jquery-intl-tel-input' );
			EVF_Frontend_Scripts::maybe_print_late_styles( 'jquery-intl-tel-input' );
		}

		// Add custom CSS/JS.
		if ( isset( $atts['id'] ) ) {
			self::add_custom_css_js( $atts['id'] );
		}

		$atts = shortcode_atts(
			array(
				'id'           => false,
				'type'         => false,
				'size'         => false,
				'text'         => false,
				'title'        => false,
				'description'  => false,
				'header_title' => false,
				'footer_title' => false,
				'header_desc'  => false,
				'footer_desc'  => false,
			),
			$atts,
			'output'
		);

		// Scripts load action.
		do_action( 'everest_forms_shortcode_scripts', $atts );

		ob_start();
		self::view( $atts );
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Form view.
	 *
	 * @param array $atts Attributes.
	 */
	private static function view( $atts ) {
		$id          = isset( $atts['id'] ) ? $atts['id'] : false;
		$title       = isset( $atts['title'] ) ? $atts['title'] : false;
		$description = isset( $atts['description'] ) ? $atts['description'] : false;
		$popup_type  = isset( $atts['type'] ) ? $atts['type'] : false;
		$popup_text  = isset( $atts['text'] ) ? $atts['text'] : false;
		if ( empty( $id ) ) {
			return;
		}

		// Grab the form data, if not found then we bail.
		$form = evf()->form->get( (int) $id );

		if ( empty( $form ) ) {
			return;
		}

		// A draft form may render only for a preview by a user who can manage it — e.g. the
		// Create-with-AI screen's iframe preview of a not-yet-published AI draft (see
		// EVF_AI_Form_Builder::create_form()). Everyone else's draft-status gate is unchanged.
		$is_previewable_draft = 'draft' === $form->post_status && current_user_can( 'manage_everest_forms' );
		if ( 'publish' !== $form->post_status && 'inactive' !== $form->post_status && ! $is_previewable_draft ) {
			return;
		}

		// Basic form information.
		$form_data = apply_filters( 'everest_forms_frontend_form_data', evf_decode( $form->post_content ) );
		// For form confirmation backward compatilibity.
		$form_data = evf_form_confirmation_backward_compatibility( $form_data );

		$form_id             = absint( $form->ID );
		$settings            = isset( $form_data['settings'] ) ? $form_data['settings'] : array();
		$action              = esc_url_raw( remove_query_arg( 'evf-forms' ) );
		$title               = filter_var( $title, FILTER_VALIDATE_BOOLEAN );
		$description         = filter_var( $description, FILTER_VALIDATE_BOOLEAN );
		$errors              = isset( evf()->task->errors[ $form_id ] ) ? evf()->task->errors[ $form_id ] : array();
		$form_enabled        = isset( $form_data['form_enabled'] ) ? absint( $form_data['form_enabled'] ) : 1;
		$kff_enabled         = isset( $settings['keyboard_friendly_form'] ) ? absint( $settings['keyboard_friendly_form'] ) : 0;
		$disable_message     = isset( $form_data['settings']['form_disable_message'] ) ? evf_string_translation( $form_data['id'], 'form_disable_message', $form_data['settings']['form_disable_message'] ) : __( 'This form is disabled.', 'everest-forms' );
		$stripe_via_selector = function_exists( 'evf_is_gateway_in_selector_allowlist' ) && evf_is_gateway_in_selector_allowlist(
			array(
				'form_data' => $form_data,
				'gateway'   => 'stripe',
			)
		);
		$square_via_selector = function_exists( 'evf_is_gateway_in_selector_allowlist' ) && evf_is_gateway_in_selector_allowlist(
			array(
				'form_data' => $form_data,
				'gateway'   => 'square',
			)
		);
		$paypal_via_selector = function_exists( 'evf_is_gateway_in_selector_allowlist' ) && evf_is_gateway_in_selector_allowlist(
			array(
				'form_data' => $form_data,
				'gateway'   => 'paypal',
			)
		);
		if ( ( isset( $form_data['payments']['stripe']['enable_stripe'] ) && '1' === $form_data['payments']['stripe']['enable_stripe'] ) || $stripe_via_selector || ( isset( $form_data['payments']['square']['enable_square'] ) && '1' === $form_data['payments']['square']['enable_square'] ) || $square_via_selector || $paypal_via_selector ) {
			$ajax_form_submission = 1;
		} else {
			$ajax_form_submission = isset( $settings['ajax_form_submission'] ) ? $settings['ajax_form_submission'] : 0;
		}

		if ( 0 !== evf_string_to_bool( $ajax_form_submission ) ) {
			wp_enqueue_script( 'everest-forms-ajax-submission' );
		}

		// If the form is disabled or does not contain any fields do not proceed.
		if ( empty( $form_data['form_fields'] ) ) {
			echo '<!-- Everest Forms: no fields, form hidden -->';
			return;
		} elseif ( 1 !== $form_enabled ) {
			if ( ! empty( $disable_message ) ) {
				printf( '<p class="everst-forms-form-disable-notice everest-forms-notice everest-forms-notice--info">%s</p>', esc_textarea( $disable_message ) );
			}
			return;
		}

		// We need to stop output processing in case we are on AMP page.
		if ( evf_is_amp( false ) && ( ! current_theme_supports( 'amp' ) || apply_filters( 'evfforms_amp_pro', class_exists( 'EverestForms_Pro' ) ) || ! is_ssl() || ! defined( 'AMP__VERSION' ) || version_compare( AMP__VERSION, '1.2', '<' ) ) ) {

			$full_page_url = home_url( add_query_arg( 'nonamp', '1' ) . '#evfforms-' . absint( $form->ID ) );

			/**
			 * Allow modifying the text or url for the full page on the AMP pages.
			 *
			 * @since 1.4.1.1
			 * @since 1.7.1 Added $form_id, $full_page_url, and $form_data arguments.
			 *
			 * @param int   $form_id   Form id.
			 * @param array $form_data Form data and settings.
			 *
			 * @return string
			 */
			$text = (string) apply_filters(
				'evf_frontend_shortcode_amp_text',
				sprintf( /* translators: %s - URL to a non-amp version of a page with the form. */
					__( '<a href="%s">Go to the full page</a> to view and submit the form.', 'everest-forms' ),
					esc_url( $full_page_url )
				),
				$form_id,
				$full_page_url,
				$form_data
			);

			printf(
				'<p class="evf-shortcode-amp-text">%s</p>',
				wp_kses_post( $text )
			);

			return;
		}

		$message = isset( $form_data['settings']['successful_form_submission_message'] ) ? $form_data['settings']['successful_form_submission_message'] : esc_html__( 'Thanks for contacting us! We will be in touch with you shortly.', 'everest-forms' );

		// Before output hook.
		do_action( 'everest_forms_frontend_output_before', $form_data, $form );

		// Check for return hash.
		if (
			! empty( $_GET['everest_forms_return'] ) // phpcs:ignore WordPress.Security.NonceVerification
			&& evf()->task->is_valid_hash
			&& absint( evf()->task->form_data['id'] ) === $form_id
		) {
			$query_args = base64_decode($_GET['everest_forms_return']); // phpcs:ignore
			parse_str( $query_args, $query_arg );
			$message        = isset( $form_data['settings']['successful_form_submission_message'] ) ? $form_data['settings']['successful_form_submission_message'] : esc_html__( 'Thanks for contacting us! We will be in touch with you shortly.', 'everest-forms' );
			$pdf_submission = isset( $form_data['settings']['pdf_submission']['enable_pdf_submission'] ) && 0 !== $form_data['settings']['pdf_submission']['enable_pdf_submission'] ? $form_data['settings']['pdf_submission'] : '';
			if ( defined( 'EVF_PDF_SUBMISSION_VERSION' ) && ( 'yes' === get_option( 'everest_forms_pdf_download_after_submit', 'no' ) || ( isset( $pdf_submission['everest_forms_pdf_download_after_submit'] ) && 'yes' === $pdf_submission['everest_forms_pdf_download_after_submit'] ) ) ) {
				global $__everest_form_id;
				global $__everest_form_entry_id;
				$__everest_form_id       = $form_id;
				$__everest_form_entry_id = isset( $query_arg['entry_id'] ) ? $query_arg['entry_id'] : 0;
			}

			if ( 'same' === $form_data['settings']['redirect_to'] ) {
				evf_add_notice( $message, 'success' );
			}

			do_action( 'everest_forms_frontend_output_success', evf()->task->form_data, evf()->task->form_fields, evf()->task->entry_id );
			return;
		}

		// Check the form state type.
		$form_state_type = isset( $form_data['settings']['form_state_type'] ) ? $form_data['settings']['form_state_type'] : 'hide';

		if ( 'hide' === $form_state_type ) {
			$message_display_location = isset( $form_data['settings']['message_display_location_of_hide'] ) ? $form_data['settings']['message_display_location_of_hide'] : 'hide';
		} else {
			$message_display_location = isset( $form_data['settings']['message_display_location_of_reset'] ) ? $form_data['settings']['message_display_location_of_reset'] : 'top';
		}
		// If conditional logic match then getting messag and position.
		if ( ! empty( $_REQUEST['evf_popup_message'] ) ) {
			$message = sanitize_text_field( wp_unslash( $_REQUEST['evf_popup_message'] ) );
		}
		if ( ! empty( $_REQUEST['evf_message_display_location'] ) ) {
			$message_display_location = sanitize_text_field( wp_unslash( $_REQUEST['evf_message_display_location'] ) );
		}

		$form_state_type = '';
		if ( ! empty( $_REQUEST['evf_form_state_type'] ) ) {
			$form_state_type = sanitize_text_field( wp_unslash( $_REQUEST['evf_form_state_type'] ) );
		}

		$success = apply_filters( 'everest_forms_success', false, $form_id );
		if ( $success && ! empty( $form_data ) && 'hide' === $message_display_location ) {
			$hide_classes = apply_filters( 'everest_forms_frontend_container_class', array(), $form_data );
			// The container's own background image (v1 direct background-image, or Style
			// Customizer v2's ::before layer) isn't part of the replaced content below, so it
			// needs its own class-driven CSS rule to disappear too (EVF-2684).
			$hide_classes[] = 'everest-forms-form-hidden';
			if ( ! empty( $settings['form_class'] ) ) {
				$hide_classes = array_merge( $hide_classes, explode( ' ', $settings['form_class'] ) );
			}
			if ( ! empty( $settings['layout_class'] ) ) {
				$hide_classes = array_merge( $hide_classes, explode( ' ', $settings['layout_class'] ) );
			}
			$hide_classes = evf_sanitize_classes( $hide_classes, true );

			printf( '<div class="evf-container %s" id="evf-%d">', esc_attr( $hide_classes ), absint( $form_id ) );
			do_action( 'everest_forms_frontend_output_success', $form_data );
			echo '</div><!-- .evf-container -->';
			return;
		}

		// Allow filter to return early if some condition is not meet.
		if ( ! apply_filters( 'everest_forms_frontend_load', true, $form_data ) ) {
			do_action( 'everest_forms_frontend_not_loaded', $form_data, $form );
			return;
		}

		/**
		 * BW compatiable for multi-parts form.
		 *
		 * @todo Remove in Major EVF version 1.6.0
		 */
		if ( defined( 'EVF_MULTI_PART_PLUGIN_FILE' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			$plugin_data = get_plugin_data( EVF_MULTI_PART_PLUGIN_FILE, false, false );

			if ( version_compare( $plugin_data['Version'], '1.3.0', '<' ) ) {
				$settings_defaults = array(
					'indicator'       => 'progress',
					'indicator_color' => '#7e3bd0',
					'nav_align'       => 'center',
				);

				if ( isset( $form_data['settings']['enable_multi_part'] ) && evf_string_to_bool( $form_data['settings']['enable_multi_part'] ) ) {
					$settings = isset( $form_data['settings']['multi_part'] ) ? $form_data['settings']['multi_part'] : array();

					if ( ! empty( $form_data['multi_part'] ) ) {
						self::$parts = array(
							'total'    => count( $form_data['multi_part'] ),
							'current'  => 1,
							'parts'    => array_values( $form_data['multi_part'] ),
							'settings' => wp_parse_args( $settings, $settings_defaults ),
						);
					}
				} else {
					self::$parts = array(
						'total'    => '',
						'current'  => '',
						'parts'    => array(),
						'settings' => $settings_defaults,
					);
				}
			}
		}

		// Allow Multi-Part to be customized.
		$parts                   = ! empty( self::$parts[ $form_id ] ) ? self::$parts[ $form_id ] : array();
		self::$parts[ $form_id ] = apply_filters( 'everest_forms_parts_data', $parts, $form_data, $form_id );

		// Allow final action to be customized.
		$action = apply_filters( 'everest_forms_frontend_form_action', $action, $form_data );

		// Allow form container classes to be filtered and user defined classes.
		$classes = apply_filters( 'everest_forms_frontend_container_class', array(), $form_data );
		if ( ! empty( $settings['form_class'] ) ) {
			$classes = array_merge( $classes, explode( ' ', $settings['form_class'] ) );
		}
		if ( ! empty( $settings['layout_class'] ) ) {
			$classes = array_merge( $classes, explode( ' ', $settings['layout_class'] ) );
		}
		$classes = evf_sanitize_classes( $classes, true );

		$form_atts = array(
			'id'    => sprintf( 'evf-form-%d', absint( $form_id ) ),
			'class' => array( 'everest-form' ),
			'data'  => array(
				'formid'                 => absint( $form_id ),
				'ajax_submission'        => $ajax_form_submission,
				'keyboard_friendly_form' => $kff_enabled,
			),
			'atts'  => array(
				'method'  => 'post',
				'enctype' => 'multipart/form-data',
				'action'  => esc_url( $action ),
			),
		);

		if ( evf_is_amp() ) {

			// Set submitting state.
			if ( ! isset( $form_atts['atts']['on'] ) ) {
				$form_atts['atts']['on'] = '';
			} else {
				$form_atts['atts']['on'] .= ';';
			}
			$form_atts['atts']['on'] .= sprintf(
				'submit:AMP.setState( %1$s ); submit-success:AMP.setState( %2$s ); submit-error:AMP.setState( %2$s );',
				wp_json_encode(
					array(
						self::get_form_amp_state_id( $form_id ) => array(
							'submitting' => true,
						),
					)
				),
				wp_json_encode(
					array(
						self::get_form_amp_state_id( $form_id ) => array(
							'submitting' => false,
						),
					)
				)
			);

			// Upgrade the form to be an amp-form to avoid sanitizer conversion.
			if ( isset( $form_atts['atts']['action'] ) ) {
				$form_atts['atts']['action-xhr'] = $form_atts['atts']['action'];
				unset( $form_atts['atts']['action'] );

				$form_atts['atts']['verify-xhr'] = $form_atts['atts']['action-xhr'];
			}
		}

		$form_atts = apply_filters( 'everest_forms_frontend_form_atts', $form_atts, $form_data );
		// Begin to build the output.
		do_action( 'everest_forms_frontend_output_container_before', $form_data, $form );

		printf( '<div class="evf-container %s" id="evf-%d">', esc_attr( $classes ), absint( $form_id ) );

		do_action( 'everest_forms_frontend_output_form_before', $form_data, $form, $errors );
		if ( isset( $atts['type'] ) && 'popup-button' === $popup_type ) {
			printf( "<button class='everest-forms-modal-link everest-forms-modal-link-%s'>%s</button>", esc_attr( $atts['id'] ), esc_html( $popup_text ) );
			do_action( 'everest_form_popup', $atts );
		} elseif ( isset( $atts['type'] ) && 'popup-link' === $popup_type ) {
			printf( "<a href='javascript:void(0);' class='everest-forms-modal-link everest-forms-modal-link-%s'>%s</a>", esc_attr( $atts['id'] ), esc_html( $popup_text ) );
			do_action( 'everest_form_popup', $atts );
		} elseif ( isset( $atts['type'] ) && 'popup' === $popup_type ) {
			do_action( 'everest_form_popup', $atts );
		} else {
			if ( $success && ! empty( $form_data ) && 'top' === $message_display_location ) {
				evf_add_notice( $message, 'success' );
				do_action( 'everest_forms_frontend_output_success', $form_data );
			} elseif ( $success && ! empty( $form_data ) && 'popup' === $message_display_location ) {
				$form_atts['data']['message_location'] = $message_display_location;
				$form_atts['data']['message']          = esc_html( $message );
			}
			// Adding the form state type. hide or reset.
			$form_atts['data']['form_state_type'] = $form_state_type;

			echo '<form ' . evf_html_attributes( $form_atts['id'], $form_atts['class'], $form_atts['data'], $form_atts['atts'] ) . '>';
			if ( evf_is_amp() ) {
				$state = array(
					'submitting' => false,
				);
				printf(
					'<amp-state id="%s"><script type="application/json">%s</script></amp-state>',
					self::get_form_amp_state_id( $form_id ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					wp_json_encode( $state )
				);
			}
			do_action( 'everest_forms_frontend_output', $form_data, $title, $description, $errors );

			echo '</form>';

			if ( $success && ! empty( $form_data ) && 'bottom' === $message_display_location ) {
				do_action( 'everest_forms_frontend_output_success', $form_data );
				evf_add_notice( $message, 'success' );
				do_action( 'everest_forms_frontend_output_success', $form_data );
			}
		}

		do_action( 'everest_forms_frontend_output_form_after', $form_data, $form );

		echo '</div><!-- .evf-container -->';

		// After output hook.
		do_action( 'everest_forms_frontend_output_after', $form_data, $form );

		// Debug information.
		if ( is_super_admin() ) {
			evf_debug_data( $form_data );
		}
	}

	/**
	 * ReCaptcha Langauge.
	 *
	 * @param url $url  Recaptcha URL.
	 *
	 *  @return $url
	 */
	public static function evf_recaptcha_language( $url ) {
		return esc_url_raw( add_query_arg( array( 'hl' => get_option( 'everest_forms_recaptcha_recaptcha_language', 'en-GB' ) ), $url ) );
	}

	/**
	 * Adds custom CSS and JavaScript code.
	 *
	 * @param int $form_id Form ID.
	 * @since 2.0.2
	 * @return void
	 */
	public static function add_custom_css_js( $form_id ) {
		$form_id = absint( $form_id );
		if ( $form_id <= 0 ) {
			return;
		}

		$form = evf()->form->get( $form_id );
		// Check the form_data exist or not.
		if ( ! $form ) {
			return;
		}

		$hook = false;

		if ( ! did_action( 'wp_head' ) ) {
			$hook = 'wp_head';
		} elseif ( ! did_action( 'wp_footer' ) ) {
			$hook = 'wp_footer';
		}

		$form_data = apply_filters( 'everest_forms_frontend_form_data', evf_decode( $form->post_content ) );
		$settings  = isset( $form_data['settings'] ) ? $form_data['settings'] : array();

		if ( isset( $settings['evf-enable-custom-css'] ) && evf_string_to_bool( $settings['evf-enable-custom-css'] ) ) {
			$custom_css = isset( $settings['evf-custom-css'] ) ? $settings['evf-custom-css'] : '';
			$custom_css = preg_match( '#</?\w+#', $custom_css ) ? '' : $custom_css;
			if ( ! empty( $custom_css ) ) {
				if ( $hook ) {
					add_action(
						$hook,
						function () use ( $custom_css, $form_id ) {
							?>
						<style id="<?php echo esc_attr( 'evf-custom-css-' . $form_id ); ?>">
							<?php echo esc_attr( $custom_css ); ?>
						</style>
							<?php
						}
					);
				} else {
					?>
					<style id="<?php echo esc_attr( 'evf-custom-css-' . $form_id ); ?>">
						<?php echo esc_attr( $custom_css ); ?>
					</style>
					<?php
				}
			}
		}

		if ( isset( $settings['evf-enable-custom-js'] ) && evf_string_to_bool( $settings['evf-enable-custom-js'] ) ) {
			$custom_js = isset( $settings['evf-custom-js'] ) ? wp_specialchars_decode( wp_kses_decode_entities( $settings['evf-custom-js'] ) ) : '';
			if ( ! empty( $custom_js ) ) {
				$custom_js = sprintf(
					'( function( $ ) {
						$(document).ready( function() {
							try {
								%s
							}
							catch( err ) {}
						});
					})( jQuery )',
					$custom_js
				);

				if ( ! wp_script_is( 'evf-custom', 'registered' ) ) {
					wp_register_script(
						'evf-custom',
						'',
						array( 'jquery' ),
						EVF_VERSION,
						true
					);
				}

				if ( ! wp_script_is( 'evf-custom', 'enqueued' ) ) {
					wp_add_inline_script( 'evf-custom', $custom_js );
					wp_enqueue_script( 'evf-custom' );
				}
			}
		}
	}

	/**
	 * Function to enable the minimum form submission waiting time.
	 *
	 * @since 3.0.2
	 *
	 * @param array $form_data Form Data.
	 */
	public static function evf_form_submission_waiting_time( $form_data ) {
		$form_submission_waiting_time_enable = isset( $form_data['settings']['form_submission_min_waiting_time'] ) ? $form_data['settings']['form_submission_min_waiting_time'] : '';
		$submission_duration                 = isset( $form_data['settings']['form_submission_min_waiting_time_input'] ) ? $form_data['settings']['form_submission_min_waiting_time_input'] : '';

		if ( '1' === $form_submission_waiting_time_enable ) {
			echo "<input type='hidden' id='evf_submission_start_time' name='evf_submission_start_time'/>";
		} else {
			return '';
		}
	}
	/**
	 * This fuction add the inline script to update the nonce to avoid nonce from caching.
	 *
	 * @since 3.3.0
	 *
	 * @param [type] $form_data The form data.
	 * @param [type] $title The title for the form.
	 * @param [type] $description The Form description.
	 * @return void
	 */
	public static function inline_script_to_update_nonce( $form_data, $title, $description ) {
		$form_id = $form_data['id'];
		$form    = evf()->form->get( absint( $form_id ) );

		if ( empty( $form ) ) {
			return;
		}

		add_action(
			'wp_footer',
			function () use ( $form_id ) {
				echo '<script type="text/javascript">jQuery(function() {'
					. 'jQuery.ajax({'
					. "url: '" . esc_url( admin_url( 'admin-ajax.php', is_ssl() ? 'https' : 'http' ) ) . "',"
					. 'type: "POST",'
					. 'data: {'
					. 'action: "everest_forms_get_form_update_nonce",'
					. 'form_id: "' . (int) $form_id . '",'
					. '},'
					. 'success: function (response) {'
					. "jQuery('#_wpnonce" . (int) $form_id . "').val( response.data );"
					. '}'
					. '});'
					. '})</script>';
			},
			99
		);
	}
}
