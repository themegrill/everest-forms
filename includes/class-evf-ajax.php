<?php

/**
 * EverestForms EVF_AJAX. AJAX Event Handlers.
 *
 * @class   EVF_AJAX
 * @package EverestForms/Classes
 */

use EverestForms\Helpers\FormHelper;

defined( 'ABSPATH' ) || exit;

/**
 * EVF_AJAX class.
 */
class EVF_AJAX {


	/**
	 * Background update class.
	 *
	 * @var object
	 */
	private static $background_process;

	/**
	 * Hook in ajax handlers.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'define_ajax' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'do_evf_ajax' ), 0 );
		self::add_ajax_events();
		add_action( 'init', array( __CLASS__, 'init_background_process' ), 5 );
	}

	/**
	 * Init background process.
	 */
	public static function init_background_process() {
		include_once EVF_ABSPATH . 'includes/class-evf-background-process-import-entries.php';

		self::$background_process = new \EVF_Background_Process_Import_Entries();
	}

	/**
	 * Set EVF AJAX constant and headers.
	 */
	public static function define_ajax() { 		// @codingStandardsIgnoreStart
		if (! empty($_GET['evf-ajax'])) {
			evf_maybe_define_constant('DOING_AJAX', true);
			evf_maybe_define_constant('EVF_DOING_AJAX', true);
			if (! WP_DEBUG || (WP_DEBUG && ! WP_DEBUG_DISPLAY)) {
				@ini_set('display_errors', 0); // Turn off display_errors during AJAX events to prevent malformed JSON.
			}
			$GLOBALS['wpdb']->hide_errors();
		}
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Send headers for EVF Ajax Requests.
	 *
	 * @since 1.0.0
	 */
	private static function evf_ajax_headers() {
		if ( ! headers_sent() ) {
			send_origin_headers();
			send_nosniff_header();
			evf_nocache_headers();
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
			header( 'X-Robots-Tag: noindex' );
			status_header( 200 );
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			headers_sent( $file, $line );
			trigger_error("evf_ajax_headers cannot set headers - headers already sent by {$file} on line {$line}", E_USER_NOTICE); // @codingStandardsIgnoreLine
		}
	}

	/**
	 * Check for EVF Ajax request and fire action.
	 */
	public static function do_evf_ajax() {
		global $wp_query;

		if ( ! empty( $_GET['evf-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$wp_query->set( 'evf-ajax', sanitize_text_field( wp_unslash( $_GET['evf-ajax'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		$action = $wp_query->get( 'evf-ajax' );

		if ( $action ) {
			self::evf_ajax_headers();
			$action = sanitize_text_field( $action );
			do_action( 'evf_ajax_' . $action );
			wp_die();
		}
	}

	/**
	 * Hook in methods - uses WordPress ajax handlers (admin-ajax).
	 */
	public static function add_ajax_events() {
		$ajax_events = array(
			'save_form'                       => false,
			'create_form'                     => false,
			'get_next_id'                     => false,
			'install_extension'               => false,
			'integration_connect'             => false,
			'new_email_add'                   => false,
			'integration_disconnect'          => false,
			'rated'                           => false,
			'review_dismiss'                  => false,
			'survey_dismiss'                  => false,
			'allow_usage_dismiss'             => false,
			'php_notice_dismiss'              => false,
			'email_failed_notice_dismiss'     => false,
			'enabled_form'                    => false,
			'import_form_action'              => false,
			'template_licence_check'          => false,
			'template_activate_addon'         => false,
			'ajax_form_submission'            => true,
			'send_test_email'                 => false,
			'locate_form_action'              => false,
			'slot_booking'                    => true,
			'active_addons'                   => false,
			'get_local_font_url'              => false,
			'form_migrator_forms_list'        => false,
			'form_migrator'                   => false,
			'fm_dismiss_notice'               => false,
			'email_duplicate'                 => false,
			'form_entry_migrator'             => false,
			'embed_form'                      => false,
			'goto_edit_page'                  => false,
			'send_routine_report_test_email'  => false,
			'send_manual_report'              => false,
			'map_csv'                         => false,
			'import_entries'                  => false,
			'generate_restapi_key'            => false,
			'install_and_activate_smart_smtp' => false,
			'form_preview_save'               => false,
			'delete_form_tags'                => false,
			'update_tags_in_bulk'             => false,
			'save_clean_talk_settings'        => true,
			'get_form_update_nonce'           => true,
		);

		foreach ( $ajax_events as $ajax_event => $nopriv ) {
			add_action( 'wp_ajax_everest_forms_' . $ajax_event, array( __CLASS__, $ajax_event ) );

			if ( $nopriv ) {
				add_action( 'wp_ajax_nopriv_everest_forms_' . $ajax_event, array( __CLASS__, $ajax_event ) );

				// EVF AJAX can be used for frontend ajax requests.
				add_action( 'evf_ajax_' . $ajax_event, array( __CLASS__, $ajax_event ) );
			}
		}
	}

	/**
	 * Ajax handler to get next form ID.
	 */
	public static function get_next_id() {
		// Run a security check.
		check_ajax_referer( 'everest_forms_get_next_id', 'security' );

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		if ( $form_id < 1 ) {
			wp_send_json_error(
				array(
					'error' => esc_html__( 'Invalid form', 'everest-forms' ),
				)
			);
		}

		// Check permisssions.
		if ( ! current_user_can( 'everest_forms_edit_form', $form_id ) ) {
			wp_send_json_error();
		}

		if ( isset( $_POST['fields'] ) ) {
			$fields_data = array();
			for ( $i = 0; $i < $_POST['fields']; $i++ ) {
				$field_key      = evf()->form->field_unique_key( $form_id );
				$field_id_array = explode( '-', $field_key );
				$new_field_id   = ( $field_id_array[ count( $field_id_array ) - 1 ] + 1 );
				$fields_data[]  = array(
					'field_id'  => $new_field_id,
					'field_key' => $field_key,
				);
			}
			wp_send_json_success(
				$fields_data
			);
		} else {
			$field_key      = evf()->form->field_unique_key( $form_id );
			$field_id_array = explode( '-', $field_key );
			$new_field_id   = ( $field_id_array[ count( $field_id_array ) - 1 ] + 1 );
			wp_send_json_success(
				array(
					'field_id'  => $new_field_id,
					'field_key' => $field_key,
				)
			);
		}
	}

	/**
	 * AJAX create new form.
	 */
	public static function create_form() {
		ob_start();

		check_ajax_referer( 'everest_forms_create_form', 'security' );

		// Check permissions.
		if ( ! current_user_can( 'everest_forms_create_forms' ) ) {
			wp_die( -1 );
		}

		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : esc_html__( 'Blank Form', 'everest-forms' );
		$template = isset( $_POST['template'] ) ? sanitize_text_field( wp_unslash( $_POST['template'] ) ) : 'blank';

		$form_id = evf()->form->create( $title, $template );

		if ( $form_id ) {
			$data = array(
				'id'       => $form_id,
				'redirect' => add_query_arg(
					array(
						'tab'     => 'fields',
						'form_id' => $form_id,
					),
					admin_url( 'admin.php?page=evf-builder' )
				),
			);

			wp_send_json_success( $data );
		}

		wp_send_json_error(
			array(
				'error' => esc_html__( 'Something went wrong, please try again later', 'everest-forms' ),
			)
		);
	}

	/**
	 * AJAX Form save.
	 */
	public static function save_form() {
		check_ajax_referer( 'everest_forms_save_form', 'security' );

		$logger = evf_get_logger();

		// Check permissions.
		$logger->info(
			__( 'Checking permissions.', 'everest-forms' ),
			array( 'source' => 'form-save' )
		);
		if ( ! current_user_can( 'everest_forms_edit_forms' ) ) {
			$logger->critical(
				__( 'You do not have permission.', 'everest-forms' ),
				array( 'source' => 'form-save' )
			);
			die( esc_html__( 'You do not have permission.', 'everest-forms' ) );
		}

		// Check for form data.
		$logger->info(
			__( 'Checking for form data.', 'everest-forms' ),
			array( 'source' => 'form-save' )
		);
		if ( empty( $_POST['form_data'] ) ) {
			$logger->critical(
				__( 'No data provided.', 'everest-forms' ),
				array( 'source' => 'form-save' )
			);
			die( esc_html__( 'No data provided', 'everest-forms' ) );
		}

		$form_post = evf_sanitize_builder( json_decode( wp_unslash( $_POST['form_data'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		$data = array();

		if ( ! is_null( $form_post ) && $form_post ) {
			foreach ( $form_post as $post_index => $post_input_data ) {
				// For input names that are arrays (e.g. `menu-item-db-id[3][4][5]`),
				// derive the array path keys via regex and set the value in $_POST.
				preg_match( '#([^\[]*)(\[(.+)\])?#', $post_input_data->name, $matches );

				$array_bits = array( $matches[1] );

				if ( isset( $matches[3] ) ) {
					$array_bits = array_merge( $array_bits, explode( '][', $matches[3] ) );
				}

				$new_post_data = array();

				// Build the new array value from leaf to trunk.
				for ( $i = count( $array_bits ) - 1; $i >= 0; $i-- ) {
					if ( count( $array_bits ) - 1 === $i ) {
						if ( '' === $array_bits[ $i ] ) {
							$new_post_data[ $post_index ] = wp_slash( $post_input_data->value );
						} else {
							$new_post_data[ $array_bits[ $i ] ] = wp_slash( $post_input_data->value );
						}
					} else {
						$new_post_data = array(
							$array_bits[ $i ] => $new_post_data,
						);
					}
				}
				$data = array_replace_recursive( $data, $new_post_data );
			}
		}

		$data = apply_filters( 'everest_forms_builder_save_form_data', $data );

		if ( is_array( $data ) ) {
			// First builder save: form is no longer "fresh"; add-ons can show full/legacy UI if needed.
			$data['is_new_form'] = false;
		}

		// Check for empty meta key.
		$logger->info(
			__( 'Check for empty meta key.', 'everest-forms' ),
			array( 'source' => 'form-save' )
		);

		// Check for empty field label.
		$logger->info(
			__( 'Checking if the field label is empty or not.', 'everest-forms' ),
			array( 'source' => 'form-save' )
		);

		$empty_meta_data   = array();
		$list_of_meta_keys = array();
		$empty_field_label = array();

		// Calculation backward compatibility.
		$old_calculation_format = 0;
		$new_calculation_format = 0;
		$not_supported_operator = 0;

		$check_recurring_period = isset( $data['payments'] ) && isset( $data['payments']['stripe'] ) && isset( $data['payments']['stripe']['interval_count'] )
			? ( 0 === $data['payments']['stripe']['interval_count'] || empty(
				$data['payments']['stripe']['interval_count']
				? true
				: false
			) )
			: false;

		$is_recurring_subscription_enabled = isset( $data['payments'] ) && isset( $data['payments']['stripe'] ) && isset( $data['payments']['stripe']['recurring'] ) && 1 == $data['payments']['stripe']['recurring']
			? true
			: false;

		if ( $is_recurring_subscription_enabled && $check_recurring_period ) {
			$logger->error(
				__( 'Recurring period required.', 'everest-forms' ),
				array( 'source' => 'form-save' )
			);
			wp_send_json_error(
				array(
					'errorTitle'   => esc_html__( 'Recurring period required.', 'everest-forms' ),
					/* translators: %s: empty meta data */
					'errorMessage' => esc_html__( 'Recurring Period is required. Please enter a valid value before saving the form.', 'everest-forms' ),
				)
			);
		}

		if ( ! empty( $data['form_fields'] ) ) {
			foreach ( $data['form_fields'] as $field_key => $field ) {
				if ( ! empty( $field['label'] ) ) {
					// Only allow specific html in label.
					$data['form_fields'][ $field_key ]['label'] = wp_kses(
						$field['label'],
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
					);

					// Register string for translation.
					evf_string_translation( $data['id'], $field['id'], $field['label'] );
				}

				if ( ! empty( $field['meta-key'] ) ) {

					$sanitized_meta_key = sanitize_key( $field['meta-key'] );

					if ( $sanitized_meta_key !== $field['meta-key'] ) {
						$logger->error(
							__( 'Invalid meta-key characters detected.', 'everest-forms' ),
							array( 'source' => 'form-save' )
						);
						wp_send_json_error(
							array(
								'errorTitle'   => esc_html__( 'Invalid Meta Key', 'everest-forms' ),
								'errorMessage' => sprintf( esc_html__( 'Meta key for field "%s" contains invalid characters. Only lowercase letters, numbers, hyphens, and underscores are allowed.', 'everest-forms' ), '<strong>' . $field['label'] . '</strong>' ),
							)
						);
					}

					$list_of_meta_keys[] = $sanitized_meta_key;

					$field['meta-key'] = $sanitized_meta_key;
				}
				$unique_meta_keys = array_unique( $list_of_meta_keys );

				if ( ! in_array( $field['type'], array( 'html', 'title', 'captcha', 'divider', 'reset', 'recaptcha', 'hcaptcha', 'turnstile' ), true ) && count( $unique_meta_keys ) < count( $list_of_meta_keys ) ) {
					$logger->error(
						__( 'Duplicate Meta Key.', 'everest-forms' ),
						array( 'source' => 'form-save' )
					);
					wp_send_json_error(
						array(
							'errorTitle'   => esc_html__( 'Duplicate Meta Key', 'everest-forms' ),
							/* translators: %s: empty meta data */
							'errorMessage' => sprintf( esc_html__( '%s field has duplicate meta_key.', 'everest-forms' ), '<strong>' . $field['label'] . '</strong>' ),
						)
					);
				}

				if ( empty( $field['meta-key'] ) && ! in_array( $field['type'], array( 'html', 'title', 'captcha', 'divider', 'reset', 'recaptcha', 'hcaptcha', 'turnstile', 'payment_summary', 'payment-gateway-selector', 'payment-coupon' ), true ) ) {
					$empty_meta_data[] = ! empty( $field['label'] ) ? $field['label'] : sprintf( /* translators: %s: field id */ esc_html__( 'Field #%s', 'everest-forms' ), isset( $field['id'] ) ? $field['id'] : '' );
				}

				if ( empty( $field['label'] ) && ! in_array( $field['type'], array( 'html', 'title', 'captcha', 'divider', 'reset', 'recaptcha', 'hcaptcha', 'turnstile', 'payment_summary', 'payment-gateway-selector', 'payment-coupon' ), true ) ) {
					$empty_field_label[] = $field['id'];
				}

				if ( isset( $field['enable_calculation'] ) && ! empty( $field['enable_calculation'] ) ) {
					if ( isset( $field['calculation_field'] ) && ! empty( $field['calculation_field'] ) ) {
						$formula             = stripslashes( $field['calculation_field'] );
						$old_formula_pattern = '/\{field_id="([^"]+)"\}/';
						preg_match_all( $old_formula_pattern, $formula, $matches );

						if ( ! empty( $matches[0] ) ) {
							++$old_calculation_format;
						}

						preg_match_all( '/\^/', $formula, $operator );

						if ( ! empty( $operator[0] ) ) {
							++$not_supported_operator;
						}

						$new_formula_pattern = '/\$FIELD_(\d+)/';
						preg_match_all( $new_formula_pattern, $formula, $new_matches );
						if ( ! empty( $new_matches[0] ) ) {
							++$new_calculation_format;
						}
					}
				}
			}

			if ( ! empty( $empty_meta_data ) ) {
				$logger->error(
					__( 'Meta Key missing.', 'everest-forms' ),
					array( 'source' => 'form-save' )
				);
				wp_send_json_error(
					array(
						'errorTitle'   => esc_html__( 'Meta Key missing', 'everest-forms' ),
						/* translators: %s: empty meta data */
						'errorMessage' => sprintf( esc_html__( 'Please add Meta key for fields: %s', 'everest-forms' ), '<strong>' . implode( ', ', $empty_meta_data ) . '</strong>' ),
					)
				);
			}

			if ( ! empty( $empty_field_label ) ) {
				$logger->error(
					__( 'Empty Field Label.', 'everest-forms' ),
					array( 'source' => 'form-save' )
				);
				wp_send_json_error(
					array(
						'errorTitle'   => esc_html__( 'Empty Field Label.', 'everest-forms' ),
						/* translators: %s: empty field label */
						'errorMessage' => sprintf( wp_kses_post( __( 'Please add label for fields: %s.<br>To hide the field please Enable Hide Label option from Advanced Options > Hide Label', 'everest-forms' ) ), '<strong>' . implode( ', ', $empty_field_label ) . '</strong>' ),
					)
				);
			}

			if ( ! empty( $old_calculation_format ) && ! empty( $new_calculation_format ) ) {
				$logger->error(
					__( 'Formula update error.', 'everest-forms' ),
					array( 'source' => 'form-save' )
				);
				wp_send_json_error(
					array(
						'errorTitle'   => esc_html__( 'Heads Up!', 'everest-forms' ),
						/* translators: %s: empty meta data */
						'errorMessage' => sprintf( esc_html__( 'Seems like your formula is not up to date. We suggest you update your formula.', 'everest-forms' ) ),
					)
				);
			}

			if ( ! empty( $not_supported_operator ) && ! empty( $new_calculation_format ) ) {
				$logger->error(
					__( 'Not supported operator.', 'everest-forms' ),
					array( 'source' => 'form-save' )
				);
				wp_send_json_error(
					array(
						'errorTitle'   => esc_html__( 'Heads Up!', 'everest-forms' ),
						/* translators: %s: empty meta data */
						'errorMessage' => sprintf( esc_html__( 'The ^ sign is now replaced with pow(). Please update accordingly. Tip: pow(a,b) = a^b', 'everest-forms' ) ),
					)
				);
			}
		}

		/**
		 * Save CleanTalk settings.
		 *
		 * @since 3.3.0
		 */
		if ( isset( $data['settings']['clean_talk_access_key'] ) && ! empty( $data['settings']['clean_talk_access_key'] ) ) {
			$logger->info(
				__( 'Saving CleanTalk settings.', 'everest-forms' ),
				array( 'source' => 'form-save' )
			);

			$access_key          = sanitize_text_field( $data['settings']['clean_talk_access_key'] );
			$is_valid_access_key = FormHelper::evf_save_clean_talk_settings( $access_key );

			if ( ! $is_valid_access_key ) {
				$logger->error(
					__( 'Invalid CleanTalk Access Key.', 'everest-forms' ),
					array( 'source' => 'form-save' )
				);
				wp_send_json_error(
					array(
						'errorTitle'   => esc_html__( 'Invalid CleanTalk Access Key', 'everest-forms' ),
						/* translators: %s: empty meta data */
						'errorMessage' => esc_html__( 'Please enter a valid CleanTalk Access Key.', 'everest-forms' ),
					)
				);
			}
			unset( $data['settings']['clean_talk_access_key'] );
		}

		if ( ! empty( $data['payments']['square']['enable_square'] ) ) {
			$form_fields            = $data['form_fields'];
			$has_square_credit_card = false;

			foreach ( $form_fields as $field ) {
				if ( empty( $field['type'] ) ) {
					continue;
				}
				if ( 'square-payment' === $field['type'] ) {
					$has_square_credit_card = true;
					break;
				}
			}

			if ( ! $has_square_credit_card ) {
				wp_send_json_error(
					array(
						'errorTitle'   => esc_html__( 'Square payment requires a credit card.', 'everest-forms' ),
						/* translators: %s: empty meta data */
						'errorMessage' => esc_html__( 'Please add a Square credit card field.', 'everest-forms' ),
					)
				);
			}
		}

		/**
		 * Creating the form tags taxonomy.
		 *
		 * @since 3.2.0
		 */
		if ( ! empty( $data['settings']['form_tags'] ) ) {
			$term_ids = array();

			foreach ( $data['settings']['form_tags'] as $tag_name ) {
				if ( is_numeric( $tag_name ) ) {
					$term = get_term( absint( $tag_name ), EVF_Post_Types::TAGS_TAXONOMY );
					if ( $term && ! is_wp_error( $term ) ) {
						$term_ids[] = $term->term_id;
						continue;
					}
				}

				$term = term_exists( $tag_name, EVF_Post_Types::TAGS_TAXONOMY );
				if ( $term === null ) {
					$term = wp_insert_term( sanitize_text_field( $tag_name ), EVF_Post_Types::TAGS_TAXONOMY );
				}
				if ( ! is_wp_error( $term ) ) {
					$term_ids[] = is_array( $term ) ? (int) $term['term_id'] : $term;
				}
			}
			if ( ! empty( $term_ids ) ) {
				wp_set_post_terms( absint( $data['id'] ), $term_ids, EVF_Post_Types::TAGS_TAXONOMY, false );
			}
		} else {
			wp_set_post_terms( absint( $data['id'] ), array(), EVF_Post_Types::TAGS_TAXONOMY, false );
		}

		// Fix for sorting field ordering.
		$logger->info(
			__( 'Fix for sorting field ordering.', 'everest-forms' ),
			array( 'source' => 'form-save' )
		);
		if ( isset( $data['structure'], $data['form_fields'] ) ) {
			$structure           = evf_flatten_array( $data['structure'] );
			$data['form_fields'] = array_merge( array_intersect_key( array_flip( $structure ), $data['form_fields'] ), $data['form_fields'] );
		}

		$form_id = evf()->form->update( $data['id'], $data );

		/**
		 * Save immediate feedback to non logged in user option in post meta.
		 *
		 * @since xx.xx.xx
		 */
		if ( isset( $data['settings']['show_immediate_feedback_to_guests'] ) && ! empty( $data['settings']['show_immediate_feedback_to_guests'] ) ) {
			$show_or_hide = sanitize_text_field( $data['settings']['show_immediate_feedback_to_guests'] );
			if ( $show_or_hide ) {
				update_post_meta( $form_id, 'everest_forms_show_immediate_feedback_to_guests', $show_or_hide );
			}
		} else {
			update_post_meta( $form_id, 'everest_forms_show_immediate_feedback_to_guests', '' );
		}

		// To track the new confirmation.
		if ( ! get_post_meta( $form_id, 'updated_form_confirmation', true ) ) {
			update_post_meta( $form_id, 'updated_form_confirmation', true );
		}

		$form_styles = get_option( 'everest_forms_styles', array() );
		$logger->info(
			__( 'Saving form.', 'everest-forms' ),
			array( 'source' => 'form-save' )
		);
		do_action( 'everest_forms_save_form', $form_id, $data, array(), ! empty( $form_styles[ $form_id ] ) );

		if ( ! $form_id ) {
			$logger->error(
				__( 'An error occurred while saving the form.', 'everest-forms' ),
				array( 'source' => 'form-save' )
			);
			wp_send_json_error(
				array(
					'errorTitle'   => esc_html__( 'Form not found', 'everest-forms' ),
					'errorMessage' => esc_html__( 'An error occurred while saving the form.', 'everest-forms' ),
				)
			);
		} else {
			$logger->info(
				__( 'Form Saved successfully.', 'everest-forms' ),
				array( 'source' => 'form-save' )
			);
			wp_send_json_success(
				apply_filters(
					'everest_forms_save_form_data',
					array(
						'form_name'    => esc_html( $data['settings']['form_title'] ),
						'redirect_url' => admin_url( 'admin.php?page=evf-builder' ),
					),
					$form_id,
					$data
				)
			);
		}
	}

	/**
	 * Ajax handler for form submission.
	 */
	public static function ajax_form_submission() {
		// check_ajax_referer( 'everest_forms_ajax_form_submission', 'security' );

		if ( ! empty( $_POST['everest_forms']['id'] ) ) {
			$process = evf()->task->ajax_form_submission( evf_sanitize_entry( wp_unslash( $_POST['everest_forms'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( isset( $process['response'] ) && 'success' === $process['response'] ) {
				wp_send_json_success( $process );
			}

			wp_send_json_error( $process );
		}
	}

	/**
	 * Ajax handler for template required addon activation.
	 */
	public static function template_activate_addon() {
		check_ajax_referer( 'everest_forms_template_licence_check', 'security' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error(
				array(
					'errorCode'    => 'invalid_permission',
					'errorMessage' => esc_html__( 'You do not have permission to activate plugins.', 'everest-forms' ),
				)
			);
		}

		if ( empty( $_POST['addon'] ) ) {
			wp_send_json_error(
				array(
					'errorCode'    => 'no_addon_specified',
					'errorMessage' => esc_html__( 'No Addon specified.', 'everest-forms' ),
				)
			);
		}

		$addon = sanitize_text_field( wp_unslash( $_POST['addon'] ) );

		// Restrict activation to genuine Everest Forms addons so this endpoint
		// cannot be used to activate arbitrary installed plugins.
		if ( ! in_array( $addon, EVF_Modules::get_allowed_addon_slugs(), true ) ) {
			wp_send_json_error(
				array(
					'errorCode'    => 'invalid_addon_slug',
					'errorMessage' => esc_html__( 'The requested module is not a valid Everest Forms addon.', 'everest-forms' ),
				)
			);
		}

		$activate = activate_plugin( $addon . '/' . $addon . '.php' );

		if ( is_wp_error( $activate ) ) {
			wp_send_json_error(
				array(
					'errorCode'    => 'addon_not_active',
					'errorMessage' => esc_html__( 'Addon can not be activate. Please try again.', 'everest-forms' ),
				)
			);
		} else {
			wp_send_json_success( 'Addon sucessfully activated.' );
		}
	}

	/**
	 * Ajax handler for licence check.
	 *
	 * @global WP_Filesystem_Base $wp_filesystem Subclass
	 */
	public static function template_licence_check() {
		check_ajax_referer( 'everest_forms_template_licence_check', 'security' );

		if ( empty( $_POST['plan'] ) ) {
			wp_send_json_error(
				array(
					'plan'         => '',
					'errorCode'    => 'no_plan_specified',
					'errorMessage' => esc_html__( 'No Plan specified.', 'everest-forms' ),
				)
			);
		}

		$addons        = array();
		$template_data = EVF_Admin_Form_Templates::get_template_data();
		$template_data = is_array( $template_data ) ? $template_data : array();
		if ( ! empty( $template_data ) ) {
			foreach ( $template_data as $template ) {
				if ( isset( $_POST['slug'] ) && $template->slug === $_POST['slug'] && in_array( trim( $_POST['plan'] ), $template->plan, true ) ) {
					$addons = $template->addons;
				}
			}
		}

		$output  = '<div class="everest-forms-recommend-addons">';
		$output .= '<p class="desc plugins-info">' . esc_html__( 'This form template requires the following addons.', 'everest-forms' ) . '</p>';
		$output .= '<table class="plugins-list-table widefat striped">';
		$output .= '<thead><tr><th scope="col" class="manage-column required-plugins" colspan="2">Required Addons</th></tr></thead><tbody id="the-list">';
		$output .= '</div>';

		$activated = true;
		foreach ( $addons as $slug => $addon ) {
			if ( is_plugin_active( $slug . '/' . $slug . '.php' ) ) {
				$class        = 'active';
				$parent_class = '';
			} elseif ( file_exists( WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php' ) ) {
				$class        = 'activate-now';
				$parent_class = 'inactive';
				$activated    = false;
			} else {
				$class        = 'install-now';
				$parent_class = 'inactive';
				$activated    = false;
			}
			$output .= '<tr class="plugin-card-' . $slug . ' plugin ' . $parent_class . '" data-slug="' . $slug . '" data-plugin="' . $slug . '/' . $slug . '.php" data-name="' . $addon . '">';
			$output .= '<td class="plugin-name">' . $addon . '</td>';
			$output .= '<td class="plugin-status"><span class="' . esc_attr( $class ) . '"></span></td>';
			$output .= '</tr>';
		}
		$output .= '</tbody></table></div>';

		wp_send_json_success(
			array(
				'html'     => $output,
				'activate' => $activated,
			)
		);
	}

	/**
	 * Ajax handler for installing a extension.
	 *
	 * @since 1.2.0
	 *
	 * @see Plugin_Upgrader
	 *
	 * @global WP_Filesystem_Base $wp_filesystem Subclass
	 */
	public static function install_extension() {
		check_ajax_referer( 'updates' );

		if ( empty( $_POST['slug'] ) ) {
			wp_send_json_error(
				array(
					'slug'         => '',
					'errorCode'    => 'no_plugin_specified',
					'errorMessage' => esc_html__( 'No plugin specified.', 'everest-forms' ),
				)
			);
		}

		$slug   = sanitize_key( wp_unslash( $_POST['slug'] ) );
		$plugin = plugin_basename( sanitize_text_field( wp_unslash( $_POST['slug'] . '/' . $_POST['slug'] . '.php' ) ) );
		$status = array(
			'install' => 'plugin',
			'slug'    => sanitize_key( wp_unslash( $_POST['slug'] ) ),
		);

		if ( ! current_user_can( 'install_plugins' ) ) {
			$status['errorMessage'] = esc_html__( 'Sorry, you are not allowed to install plugins on this site.', 'everest-forms' );
			wp_send_json_error( $status );
		}

		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		if ( file_exists( WP_PLUGIN_DIR . '/' . $slug ) ) {
			$plugin_data          = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
			$status['plugin']     = $plugin;
			$status['pluginName'] = $plugin_data['Name'];

			if ( current_user_can( 'activate_plugin', $plugin ) && is_plugin_inactive( $plugin ) ) {
				$result = activate_plugin( $plugin );

				if ( is_wp_error( $result ) ) {
					$status['errorCode']    = $result->get_error_code();
					$status['errorMessage'] = $result->get_error_message();
					wp_send_json_error( $status );
				}

				wp_send_json_success( $status );
			}
		}

		$api = json_decode(
			EVF_Updater_Key_API::version(
				array(
					'license'   => get_option( 'everest-forms-pro_license_key' ),
					'item_name' => ! empty( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				)
			)
		);

		if ( is_wp_error( $api ) ) {
			$status['errorMessage'] = $api->get_error_message();
			wp_send_json_error( $status );
		}

		$status['pluginName'] = $api->name;

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$status['debug'] = $skin->get_upgrade_messages();
		}

		if ( is_wp_error( $result ) ) {
			$status['errorCode']    = $result->get_error_code();
			$status['errorMessage'] = $result->get_error_message();
			wp_send_json_error( $status );
		} elseif ( is_wp_error( $skin->result ) ) {
			$status['errorCode']    = $skin->result->get_error_code();
			$status['errorMessage'] = $skin->result->get_error_message();
			wp_send_json_error( $status );
		} elseif ( $skin->get_errors()->get_error_code() ) {
			$status['errorMessage'] = $skin->get_error_messages();
			wp_send_json_error( $status );
		} elseif ( is_null( $result ) ) {
			global $wp_filesystem;

			$status['errorCode']    = 'unable_to_connect_to_filesystem';
			$status['errorMessage'] = esc_html__( 'Unable to connect to the filesystem. Please confirm your credentials.', 'everest-forms' );

			// Pass through the error from WP_Filesystem if one was raised.
			if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->get_error_code() ) {
				$status['errorMessage'] = esc_html( $wp_filesystem->errors->get_error_message() );
			}

			wp_send_json_error( $status );
		}

		$install_status = install_plugin_install_status( $api );

		if ( current_user_can( 'activate_plugin', $install_status['file'] ) && is_plugin_inactive( $install_status['file'] ) ) {
			if ( isset( $_POST['page'] ) && 'everest-forms_page_evf-builder' === $_POST['page'] ) {
				activate_plugin( $install_status['file'] );
			} else {
				$status['activateUrl'] =
					esc_url_raw(
						add_query_arg(
							array(
								'action'   => 'activate',
								'plugin'   => $install_status['file'],
								'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $install_status['file'] ),
							),
							admin_url( 'admin.php?page=evf-addons' )
						)
					);
			}
		}

		wp_send_json_success( $status );
	}

	/**
	 * AJAX Integration connect.
	 */
	public static function integration_connect() {
		check_ajax_referer( 'process-ajax-nonce', 'security' );

		// Check permissions.
		if ( ! current_user_can( 'everest_forms_edit_forms' ) ) {
			wp_die( -1 );
		}

		if ( empty( $_POST ) ) {
			wp_send_json_error(
				array(
					'error' => esc_html__( 'Missing data', 'everest-forms' ),
				)
			);
		}

		// EVF_Integrations is normally loaded on current_screen (admin page loads only).
		// During AJAX requests that hook never fires, so integration action hooks are never
		// registered. Instantiate it here so provider connect handlers are available.
		if ( ! EVF()->integrations ) {
			EVF()->integrations = new EVF_Integrations();
		}

		do_action( 'everest_forms_integration_account_connect_' . ( isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '' ), $_POST );
	}

	/**
	 * AJAX Email Add.
	 */
	public static function new_email_add() {
		check_ajax_referer( 'process-ajax-nonce', 'security' );

		// Check permissions.
		if ( ! current_user_can( 'everest_forms_edit_forms' ) ) {
			wp_die( -1 );
		}

		$connection_id = 'connection_' . uniqid();
		$preview_url   = add_query_arg(
			array(
				'evf_email_preview' => $connection_id,
				'form_id'           => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
			),
			home_url()
		);
		wp_send_json_success(
			array(
				'connection_id' => $connection_id,
				'preview_url'   => $preview_url,
			)
		);
	}

	/**
	 * AJAX Email Duplicate.
	 */
	public static function email_duplicate() {
		check_ajax_referer( 'process-ajax-nonce', 'security' );

		// Check permissions.
		if ( ! current_user_can( 'everest_forms_edit_forms' ) ) {
			wp_die( -1 );
		}

		$connection_id = 'connection_' . uniqid();
		$preview_url   = '';
		$preview_url   = add_query_arg(
			array(
				'evf_email_preview' => $connection_id,
				'form_id'           => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
				'preview_url'       => $preview_url,
			),
			home_url()
		);

		wp_send_json_success(
			array(
				'connection_id'      => $connection_id,
				'prev_connection_id' => isset( $_POST['prev_connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['prev_connection_id'] ) ) : '',
				'preview_url'        => $preview_url,
			)
		);
	}


	/**
	 * AJAX Integration disconnect.
	 */
	public static function integration_disconnect() {
		check_ajax_referer( 'process-ajax-nonce', 'security' );

		// Check permissions.
		if ( ! current_user_can( 'everest_forms_edit_forms' ) ) {
			wp_die( -1 );
		}

		if ( empty( $_POST ) ) {
			wp_send_json_error(
				array(
					'error' => esc_html__( 'Missing data', 'everest-forms' ),
				)
			);
		}

		if ( ! EVF()->integrations ) {
			EVF()->integrations = new EVF_Integrations();
		}

		do_action( 'everest_forms_integration_account_disconnect_' . ( isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '' ), $_POST );

		$connected_accounts = get_option( 'everest_forms_integrations', false );

		if ( ! empty( $connected_accounts[ $_POST['source'] ][ $_POST['key'] ] ) ) {
			unset( $connected_accounts[ $_POST['source'] ][ $_POST['key'] ] );
			update_option( 'everest_forms_integrations', $connected_accounts );
			wp_send_json_success( array( 'remove' => true ) );
		} else {
			wp_send_json_error(
				array(
					'error' => esc_html__( 'Connection missing', 'everest-forms' ),
				)
			);
		}
	}

	/**
	 * Triggered when clicking the rating footer.
	 */
	public static function rated() {
		check_ajax_referer( 'everest_forms_rated', 'security' );

		if ( ! current_user_can( 'manage_everest_forms' ) ) {
			wp_die( -1 );
		}
		update_option( 'everest_forms_admin_footer_text_rated', 1 );
		wp_die();
	}

	/**
	 * Triggered when clicking the review notice button.
	 */
	public static function review_dismiss() {
		check_ajax_referer( 'everest_forms_review_dismiss', 'security' );

		if ( ! current_user_can( 'manage_everest_forms' ) ) {
			wp_die( -1 );
		}
		$review              = get_option( 'everest_forms_review', array() );
		$review['time']      = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$review['dismissed'] = true;
		update_option( 'everest_forms_review', $review );
		wp_die();
	}

	/**
	 * Triggered when clicking the survey notice button.
	 */
	public static function survey_dismiss() {
		check_ajax_referer( 'everest_forms_survey_dismiss', 'security' );

		if ( ! current_user_can( 'manage_everest_forms' ) ) {
			wp_die( -1 );
		}
		$survey              = get_option( 'everest_forms_survey', array() );
		$survey['dismissed'] = true;
		update_option( 'everest_forms_survey', $survey );
		wp_die();
	}

	/**
	 * Triggered when clicking the allow usage notice allow or deny buttons.
	 */
	public static function allow_usage_dismiss() {
		check_ajax_referer( 'allow_usage_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_everest_forms' ) ) {
			wp_die( -1 );
		}

		$allow_usage_tracking = isset( $_POST['allow_usage_tracking'] ) ? sanitize_text_field( wp_unslash( $_POST['allow_usage_tracking'] ) ) : false;

		update_option( 'everest_forms_allow_usage_notice_shown', true );

		if ( 'true' === $allow_usage_tracking ) {
			update_option( 'everest_forms_allow_usage_tracking', 'yes' );
		}

		wp_die();
	}

	/**
	 * Triggered when clicking the PHP deprecation notice.
	 */
	public static function php_notice_dismiss() {
		check_ajax_referer( 'php_notice_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_everest_forms' ) ) {
			wp_die( -1 );
		}
		$current_date = gmdate( 'Y-m-d' );
		$prompt_count = get_option( 'everest_forms_php_deprecated_notice_prompt_count', 0 );

		update_option( 'everest_forms_php_deprecated_notice_last_prompt_date', $current_date );
		update_option( 'everest_forms_php_deprecated_notice_prompt_count', ++$prompt_count );
		wp_die();
	}


	/**
	 * Triggered when clicking the email failed notice.
	 */
	public static function email_failed_notice_dismiss() {
		check_ajax_referer( 'email_failed_nonce', '_wpnonce' );

		if ( ! current_user_can( 'manage_everest_forms' ) ) {
			wp_die( -1 );
		}
		update_option( 'everest_forms_email_send_notice_dismiss', true );
		wp_die();
	}

	/**
	 * Triggered when clicking the form toggle.
	 */
	public static function enabled_form() {
		// Run a security check.
		check_ajax_referer( 'everest_forms_enabled_form', 'security' );

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$enabled = isset( $_POST['enabled'] ) ? absint( $_POST['enabled'] ) : 0;

		if ( ! current_user_can( 'everest_forms_edit_form', $form_id ) ) {
			wp_die( -1 );
		}

		/**
		 * Update the form status in post table.
		 *
		 * @since 3.2.0
		 */
		$new_status = $enabled ? 'publish' : 'inactive';

		$result = wp_update_post(
			array(
				'ID'          => $form_id,
				'post_status' => $new_status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 500 );
		}

		$form_data = evf()->form->get( absint( $form_id ), array( 'content_only' => true ) );

		$form_data['form_enabled'] = $enabled;

		evf()->form->update( $form_id, $form_data );
	}

	/**
	 * Import Form ajax.
	 */
	public static function import_form_action() {
		try {
			check_ajax_referer( 'process-import-ajax-nonce', 'security' );
			EVF_Admin_Import_Export::import_forms();
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Send test email.
	 */
	public static function send_test_email() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'everest-forms' ) ), 401 );
			}

			check_ajax_referer( 'process-ajax-nonce', 'security' );
			$from  = esc_attr( get_bloginfo( 'name', 'display' ) );
			$email = sanitize_email( isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '' );

			/* translators: %s: from address */
			$subject    = 'Everest Form: ' . sprintf( esc_html__( 'Test email from %s', 'everest-forms' ), $from );
			$header     = "Reply-To: {{from}} \r\n";
			$header    .= 'Content-Type: text/html; charset=UTF-8';
			$message    = sprintf(
				'%s <br /> %s <br /> %s <br /> %s <br /> %s',
				__( 'Congratulations,', 'everest-forms' ),
				__( 'Your test email has been received successfully.', 'everest-forms' ),
				__( 'We thank you for trying out Everest Forms and joining our mission to make sure you get your emails delivered.', 'everest-forms' ),
				__( 'Regards,', 'everest-forms' ),
				__( 'Everest Forms Team', 'everest-forms' )
			);
				$status = wp_mail( $email, $subject, $message, $header );
			if ( $status ) {
				wp_send_json_success( array( 'message' => __( 'Test email was sent successfully! Please check your inbox to make sure it is delivered.', 'everest-forms' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Test email was unsuccessful! Something went wrong.', 'everest-forms' ) ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Send stat routine test email.
	 *
	 * @since 2.0.9
	 */
	public static function send_routine_report_test_email() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'everest-forms' ) ), 401 );
			}

			check_ajax_referer( 'process-ajax-nonce', 'security' );

			$frequency = get_option( 'everest_forms_entries_reporting_frequency', 'Weekly' );
			if ( ! in_array( $frequency, array( 'Daily', 'Weekly', 'Monthly' ), true ) ) {
				$frequency = 'Weekly';
			}

			$test_email = sanitize_email( get_option( 'everest_forms_routine_report_send_email_test_to', '' ) );
			if ( empty( $test_email ) ) {
				$test_email = sanitize_email( get_bloginfo( 'admin_email' ) );
			}

			$subject = get_option( 'everest_forms_entries_reporting_subject', __( 'Everest Forms - Entries summary statistics', 'everest-forms' ) );
			if ( empty( trim( $subject ) ) ) {
				$subject = __( 'Everest Forms - Entries summary statistics', 'everest-forms' );
			}
			$subject = sprintf( __( '[TEST] %s', 'everest-forms' ), $subject );

			$email_builder = new EVF_Email_Entries_Report( $frequency, null, true );
			$html_message  = $email_builder->render_html();

			$headers = array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . wp_specialchars_decode( get_bloginfo( 'name' ) ) . ' <' . get_option( 'admin_email' ) . '>',
			);

			$sent = wp_mail( $test_email, $subject, $html_message, $headers );

			if ( $sent ) {
				wp_send_json_success(
					array(
						'message' => sprintf(
							__( 'Test report sent to %s. Please check your inbox.', 'everest-forms' ),
							$test_email
						),
					)
				);
			} else {
				wp_send_json_error( array( 'message' => __( 'Test email could not be sent. Please check your site email configuration.', 'everest-forms' ) ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

		/**
		 * Manually trigger a full entries report send immediately.
		 *
		 * Sends to the configured scheduled recipient (not the test address).
		 * Logged to report history as type 'manual'.
		 *
		 * @since 2.0.9
		 */
	public static function send_manual_report() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'everest-forms' ) ), 401 );
			}

			check_ajax_referer( 'process-ajax-nonce', 'security' );

			$cron = new EVF_Report_Cron();
			$sent = $cron->evf_report_form_statistics_send( false );

			if ( $sent ) {
				wp_send_json_success( array( 'message' => __( 'Report sent successfully.', 'everest-forms' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Report could not be sent. Please check your settings and email configuration.', 'everest-forms' ) ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Locate form.
	 */
	public static function locate_form_action() {
		global $wpdb;
		try {
			check_ajax_referer( 'process-locate-ajax-nonce', 'security' );
			$id                     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
			$everest_form_shortcode = '%[everest_form id="' . $id . '"%';
			$form_id_shortcode      = '%{"formId":"' . $id . '"%';
			$pages                  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}posts WHERE post_content LIKE %s OR post_content LIKE %s", $everest_form_shortcode, $form_id_shortcode ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$page_list              = array();
			foreach ( $pages as $page ) {
				if ( 'page' === $page->post_type || 'post' === $page->post_type ) {
					$page_title               = $page->post_title;
					$page_guid                = get_permalink( $page->ID );
					$page_list[ $page_title ] = $page_guid;
				}
			}
			wp_send_json_success( $page_list );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}
	/**
	 * Slot booking.
	 */
	public static function slot_booking() {
		try {
			check_ajax_referer( 'everest_forms_slot_booking_nonce', 'security' );
			$datetime_value  = isset( $_POST['data-time-value'] ) ? sanitize_text_field( wp_unslash( $_POST['data-time-value'] ) ) : '';
			$datetime_format = isset( $_POST['data-time-format'] ) ? sanitize_text_field( wp_unslash( $_POST['data-time-format'] ) ) : '';
			$date_format     = isset( $_POST['data-format'] ) ? sanitize_text_field( wp_unslash( $_POST['data-format'] ) ) : '';
			$mode            = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';
			$form_id         = isset( $_POST['form-id'] ) ? sanitize_text_field( wp_unslash( $_POST['form-id'] ) ) : '';
			$time_interval   = isset( $_POST['time-interval'] ) ? sanitize_text_field( wp_unslash( $_POST['time-interval'] ) ) : '';
			$datetime_arr    = parse_datetime_values( $datetime_value, $datetime_format, $date_format, $mode, $time_interval );

			if ( empty( $datetime_arr ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Please select at least one date time.', 'everest-forms' ),
					)
				);
			}
			$booked_slot = evf_maybe_unserialize( get_option( 'evf_booked_slot', '' ) );
			$is_booked   = false;
			if ( ! empty( $booked_slot ) && array_key_exists( $form_id, $booked_slot ) ) {
				foreach ( $datetime_arr as $arr ) {

					foreach ( $booked_slot[ $form_id ] as $key => $slot ) {
						if ( $arr[0] >= $slot[0] && $arr[1] <= $slot[1] ) {
							$is_booked = true;
							break;
						} elseif ( $arr[0] >= $slot[0] && $arr[0] < $slot[1] && $arr[1] >= $slot[1] ) {
							$is_booked = true;
							break;
						}
					}
				}
			}
			if ( $is_booked ) {
				wp_send_json_success(
					array(
						'message' => __( 'This slot is already booked. Please choose other slot', 'everest-forms' ),
					)
				);
			}
			wp_send_json_error(
				array(
					'message' => __( 'This slot is not booked.', 'everest-forms' ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => __( 'Something went wrong.', 'everest-forms' ),
				)
			);
		}
	}

	/**
	 * Activate addons from builder.
	 */
	public static function active_addons() {
		try {
			check_ajax_referer( 'evf_active_nonce', 'security' );

			if ( ! current_user_can( 'activate_plugins' ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'You do not have permission to activate plugins.', 'everest-forms' ),
					)
				);
			}

			$plugin = isset( $_POST['plugin_file'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_file'] ) ) : '';
			$slug   = dirname( $plugin );

			// Restrict activation to genuine Everest Forms addons so this endpoint
			// cannot be used to activate arbitrary installed plugins.
			if ( '' === $plugin || ! in_array( $slug, EVF_Modules::get_allowed_addon_slugs(), true ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'The requested module is not a valid Everest Forms addon.', 'everest-forms' ),
					)
				);
			}

			$activate = activate_plugin( $plugin );
			if ( is_wp_error( $activate ) ) {
				$activation_error = $activate->get_error_message();
				wp_send_json_error(
					array(
						'message' => $activation_error,
					)
				);
			} else {
				wp_send_json_success(
					array(
						'message' => __( 'Activated successfully', 'everest-forms' ),
					)
				);
			}
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Download the provided font and return the url for font file.
	 *
	 * @since 2.0.8
	 */
	public static function get_local_font_url() {
		$font_url = isset( $_POST['font_url'] ) ? sanitize_text_field( wp_unslash( $_POST['font_url'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification

		$allowed_urls = array(
			'https://fonts.googleapis.com',
		);

		if ( ! in_array( $font_url, $allowed_urls ) ) {
			return;
		}

		if ( str_contains( $font_url, 'https://fonts.googleapis.com' ) ) {
			$font_url = evf_maybe_get_local_font_url( $font_url );
		}

		return wp_send_json_success( $font_url );
	}

	/**
	 * Forms list for form migrator.
	 *
	 * @since 2.0.8
	 *
	 * @throws Exception If there is an error.
	 */
	public static function form_migrator_forms_list() {
		try {
			check_ajax_referer( 'evf_form_migrator_forms_list_nonce', 'security' );

			$form_slug = isset( $_POST['form_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['form_slug'] ) ) : '';
			if ( '' === $form_slug ) {
				wp_send_json_error(
					array(
						'message' => __( 'Missing form slug !', 'everest-forms' ),
					)
				);
			}

			// Creating the form instance and getting the form list.
			$class_name = 'EVF_Fm_' . ucfirst( trim( str_replace( '-', '', $form_slug ) ) );

			if ( ! class_exists( $class_name ) ) {
				$except_message = sprintf( '<b><i>%s</i></b> %s', $class_name, esc_html__( 'does not exist.', 'everest-forms' ) );
				throw new Exception( $except_message );
			}

			$form_instance = new $class_name();
			$forms_list    = $form_instance->get_forms();

			if ( empty( $forms_list ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html( 'No forms are currently available in the list !!!', 'everest-forms' ),
					)
				);
			}
			$row               = 0;
			$form_per_page     = 5;
			$ceil              = ceil( count( $forms_list ) / $form_per_page );
			$forms_list_table  = '<div class="evf-fm-forms-table-wrapper">';
			$forms_list_table .= '<h4>' . sprintf( '%s %s', esc_html__( 'Import', 'everest-forms' ), $form_instance->name ) . '</h4>';
			$forms_list_table .= '<table class="evf-fm-forms-table" data-form-slug="' . esc_attr( $form_slug ) . '">';
			$forms_list_table .= '<tr class="evf-th-title"><th><input id="evf-fm-select-all" type="checkbox" name="fm_select_all_form" /></th><th>' . esc_html__( 'Form	Name', 'everest-forms' ) . '</th><th>' . esc_html__( 'Imported', 'everest-forms' ) . '</th><th>' . esc_html__( 'Action', 'everest-forms' ) . '</th></tr>';
			$hidden            = '';
			$imported          = get_option( 'evf_fm_' . $form_slug . '_imported_form_list', array() );
			foreach ( $forms_list as $form_id => $form_name ) {
				++$row;
				if ( in_array( $form_id, $imported ) ) {
					$is_imported   = true;
					$imported_text = esc_html__( 'Yes', 'everest-forms' );
				} else {
					$is_imported   = false;
					$imported_text = esc_html__( 'No', 'everest-forms' );
				}
				$forms_list_table .= '<tr id="evf-fm-row-' . esc_attr( $row ) . '" class="evf-fm-row ' . esc_attr( $hidden ) . '"><td><input class="evf-fm-select-single" type="checkbox" name="fm_select_single_form_' . esc_attr( $form_id ) . '" data-form-id="' . esc_attr( $form_id ) . '" /></td><td>' . esc_html__( $form_name, 'everest-forms' ) . '</td><td><p class="evf-fm-imported" data-form-id="' . esc_attr( $form_id ) . '">' . esc_attr( $imported_text ) . '<p></td>';
				$forms_list_table .= '<td>';
				$forms_list_table .= '<div class="evf-fm-import-actions"><button class="evf-fm-import-single" data-form-id="' . esc_attr( $form_id ) . '">' . esc_html( 'Import Form' ) . '</button>';
				if ( 'contact-form-7' !== $form_slug ) {
					$disabled          = $is_imported ? '' : 'disabled';
					$forms_list_table .= '<button class="evf-fm-import-entry" data-form-id="' . esc_attr( $form_id ) . '"' . esc_attr( $disabled ) . '>' . esc_html( 'Import Entry' ) . '</button>';
				}
				$forms_list_table .= '</div></td></tr>';
				if ( $row === $form_per_page ) {
					$hidden = 'evf-fm-hide-row';
				}
			}
			$forms_list_table .= '</table>';
			$forms_list_table .= '<div class="evf-fm-import-selected-wrapper"><button class="evf-fm-import-selected-btn">' . esc_html( 'Import Selected Forms' ) . '</button>';
			$forms_list_table .= '<div data-total-page="' . esc_attr( count( $forms_list ) ) . '" data-fm-ceil="' . esc_attr( $ceil ) . '"  data-form-per-page="' . esc_attr( $form_per_page ) . '" class="evf-fm-pagination">';

			for ( $page = 1; $page <= $ceil; $page++ ) {
				$active = '';
				if ( 1 === $page ) {
					$active = 'evf-fm-btn-active';
				}
				$forms_list_table .= '<button class="evf-fm-page ' . esc_attr( $active ) . '" data-page="' . esc_attr( $page ) . '">' . esc_attr( $page ) . '</button>';
			}
			$forms_list_table .= '</div></div>';
			$forms_list_table .= '</div>';
			wp_send_json_success(
				array(
					'message'          => esc_html__( 'All Forms List', 'everest-forms' ),
					'forms_list_table' => $forms_list_table,
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Form migrator.
	 *
	 * @since 2.0.8
	 *
	 * @throws Exception If there is an error.
	 */
	public static function form_migrator() {
		try {
			check_ajax_referer( 'evf_form_migrator_nonce', 'security' );
			$form_slug = isset( $_POST['form_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['form_slug'] ) ) : '';
			$form_ids  = isset( $_POST['form_ids'] ) ? wp_unslash( $_POST['form_ids'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' === $form_ids ) {
				wp_send_json_error(
					array(
						'message' => __( 'Missing Form ID !!!', 'everest-forms' ),
					)
				);
			}

			$class_name = 'EVF_Fm_' . ucfirst( trim( str_replace( '-', '', $form_slug ) ) );

			if ( ! class_exists( $class_name ) ) {
				$except_message = sprintf( '<b><i>%s</i></b> %s', $class_name, esc_html__( 'does not exist.', 'everest-forms' ) );
				throw new Exception( $except_message );
			}
			// Create the instance of class.
			$form_instance = new $class_name();
			$forms_data    = $form_instance->get_fm_mapped_form_data( $form_ids );

			if ( 1 === count( $forms_data ) ) {
				wp_send_json_success(
					array(
						'message'   => sprintf( '%s <a href="%s" target="_blank">%s</a>', __( 'Imported Successfully.', 'everest-forms' ), esc_url( $forms_data[ $form_ids[0] ]['edit'] ), __( 'View Form', 'everest-forms' ) ),
						'form_data' => $forms_data,
					)
				);
			} else {
				wp_send_json_success(
					array(
						'message'   => __( 'Imported Successfully', 'everest-forms' ),
						'form_data' => $forms_data,
					)
				);
			}
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}
	/**
	 * Dismiss Form migrator notice.
	 *
	 * @since 2.0.8
	 */
	public static function fm_dismiss_notice() {
		try {
			check_ajax_referer( 'evf_fm_dismiss_notice_nonce', 'security' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'everest-forms' ) ) );
			}

			$option_id = isset( $_POST['option_id'] ) ? sanitize_text_field( wp_unslash( $_POST['option_id'] ) ) : '';

			$allowed_notices = apply_filters(
				'everest_forms_fm_dismissible_notice_options',
				array(
					'evf_fm_dismiss_xnotice_contact-form-7',
					'evf_fm_dismiss_xnotice_wpforms',
				)
			);

			if ( ! in_array( $option_id, $allowed_notices, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid notice.', 'everest-forms' ) ) );
			}

			update_option( $option_id, true );

			wp_send_json_success(
				array(
					'message' => __( 'Updated !', 'everest-forms' ),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}
	/**
	 * Form entry migrator.
	 *
	 * @since 2.0.8
	 *
	 * @throws Exception If there is an error.
	 */
	public static function form_entry_migrator() {
		try {
			check_ajax_referer( 'evf_form_entry_migrator_nonce', 'security' );
			if ( ! wpforms()->is_pro() ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Entries not available in WPForms Lite.', 'everest-forms' ),
					)
				);
			}
			$form_id   = isset( $_POST['form_id'] ) ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
			$form_slug = isset( $_POST['form_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['form_slug'] ) ) : '';

			if ( empty( $form_id ) || empty( $form_slug ) ) {

				wp_send_json_error(
					array(
						'message' => __( 'Invalid Request !!', 'everest-forms' ),
					)
				);
			}

			$migrated_form_list = get_option( 'evf_fm_' . $form_slug . '_imported_form_list', array() );
			$evf_form_id        = array_search( $form_id, $migrated_form_list );

			if ( ! $evf_form_id ) {
				wp_send_json_error(
					array(
						'message' => esc_html__( 'Please migrate the form before importing the entry', 'everest-forms' ),
					)
				);
			}
			// Creating the form instance and getting the form list.
			$class_name = 'EVF_Fm_' . ucfirst( trim( str_replace( '-', '', $form_slug ) ) );

			if ( ! class_exists( $class_name ) ) {
				$except_message = sprintf( '<b><i>%s</i></b> %s', $class_name, esc_html__( 'does not exist.', 'everest-forms' ) );
				throw new Exception( $except_message );
			}
			// Create the instance of class.
			$form_instance = new $class_name();
			$evf_entries   = $form_instance->migrate_entry( $evf_form_id, $form_id );

			$success   = array();
			$unsuccess = array();
			foreach ( $evf_entries as $key => $entry ) {
				if ( ! $entry ) {
					$unsuccess[] = $key;
					continue;
				}
				$success[] = $key;
			}
			if ( count( $unsuccess ) === 0 ) {
				$response = array(
					'message'   => esc_html__( 'All entries are migrated successfully!!', 'everest-forms' ),
					'success'   => $success,
					'unsuccess' => $unsuccess,
				);
			} elseif ( count( $unsuccess ) > 0 && count( $success ) === 0 ) {
				$response = array(
					'message'   => esc_html__( 'Entry migration failed!!', 'everest-forms' ),
					'success'   => $success,
					'unsuccess' => $unsuccess,
				);
			} elseif ( count( $unsuccess ) > 0 && count( $success ) > 0 ) {
				$response = array(
					'message'   => esc_html__( 'Only some entries are migrated successfully!!', 'everest-forms' ),
					'success'   => $success,
					'unsuccess' => $unsuccess,
				);
			}
			wp_send_json_success(
				$response
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}
	/**
	 * Everest Forms - Embed Form.
	 *
	 * @since 2.0.8
	 */
	public static function embed_form() {
		check_ajax_referer( 'everest_forms_embed_form', 'security' );
		$args  = array(
			'post_status' => 'publish',
			'post_type'   => 'page',
		);
		$pages = get_pages( $args );

		wp_send_json_success( $pages );
	}

	/**
	 * Get page edit link
	 *
	 * @since 2.0.8
	 */
	public static function goto_edit_page() {
		check_ajax_referer( 'everest_forms_goto_edit_page', 'security' );

		$page_id = empty( $_POST['page_id'] ) ? 0 : sanitize_text_field( absint( $_POST['page_id'] ) );

		if ( empty( $page_id ) ) {
			$url  = add_query_arg( 'post_type', 'page', admin_url( 'post-new.php' ) );
			$meta = array(
				'embed_page'       => 0,
				'embed_page_title' => ! empty( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : '',
			);
		} else {
			$url  = get_edit_post_link( $page_id, '' );
			$meta = array(
				'embed_page' => $page_id,
			);
		}
		$page_url        = add_query_arg(
			array(
				'form' => 'everest-forms',
			),
			esc_url_raw( $url )
		);
		$meta['form_id'] = ! empty( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		EVF_Admin_Embed_Wizard::set_meta( $meta );

		wp_send_json_success( $page_url );
	}

	/**
	 * Map csv fields with form fields.
	 *
	 * @since 3.0.0
	 */
	public static function map_csv() {
		check_ajax_referer( 'evf-import-entries', 'security' );

		if ( empty( $_FILES ) ) {
			wp_send_json_error(
				array(
					'message' => 'Please upload csv file.',
				)
			);
		}

		$form_id     = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0; //phpcs:ignore
		$form_fields = evf_get_form_fields( $form_id );

		$csv_header = self::get_csv_header( $_FILES );

		$output  = '';
		$output .= '<div class="evf-map-entries-to-form">';
		$output .= '<form id="evf-import-entries-form">';
		$output .= '<p>' . esc_html__( 'Map CSV fields to Form fields.', 'everest-forms' ) . '</p>';
		$output .= '<div class ="evf-form-fields-and-csv-fields" style="display: flex;">';
		$output .= '<div class="evf-form-fields" style="width: 50%;">';
		$output .= '<h4>' . esc_html__( 'Form Fields', 'everest-forms' ) . '</h4>';
		$output .= '</div>';
		$output .= '<div class="evf-csv-fields" style="width: 50%;">';
		$output .= '<h4>' . esc_html__( 'CSV Fields', 'everest-forms' ) . '</h4>';
		$output .= '</div>';
		$output .= '</div>';
		$output .= '<div class = "evf-map-entries-to-form-wrapper" style="display: flex;">';
		$output .= '<div class="evf-form-fields" style="width: 50%;">';
		$output .= '<select name="map-entries-to-form" class="evf-form-fields-csv" style="min-width: 90%;">';

		if ( ! empty( $form_fields ) ) {
			foreach ( $form_fields as $key => $value ) {
				$output .= '<option value="' . esc_attr( $key ) . '">' . esc_html( $value['label'] ) . '</option>';
			}
		} else {
			$output .= '<option value="">' . esc_html__( 'No form fields', 'everest-forms' ) . '</option>';
		}

		$output .= '</select>';
		$output .= '</div>';
		$output .= '<div class="evf-csv-fields" style="width: 50%;">';
		$output .= '<select name="map-entries-to-csv" style="min-width: 90%;">';

		if ( ! empty( $csv_header ) ) {
			foreach ( $csv_header as $value ) {

				$output .= '<option value="' . esc_attr( $value ) . '">' . esc_html( str_replace( '"', '', $value ) ) . '</option>';
			}
		} else {
			$output .= '<option value="">' . esc_html__( 'No csv fields', 'everest-forms' ) . '</option>';
		}

		$output .= '</select>';
		$output .= '</div>';
		$output .= '<span class="actions" style="display: flex; align-items: center; justify-content: space-between;"><a class="evf-add-clone" href="#" style="text-decoration: none;"><i class="dashicons dashicons-plus"></i></a><a class="evf-remove-clone everest-forms-hidden" href="#" style="text-decoration: none;"><i class="dashicons dashicons-minus"></i></a></span>';
		$output .= '</div>';
		$output .= '<input type="hidden" name="form_id" value="' . esc_attr( $form_id ) . '">';
		$output .= '<span class="evf_import_entries_btn"><input type="submit" class="everest-forms-btn everest-forms-btn-primary evf-import-entries-btn" value="' . esc_html__( 'Import Entries', 'everest-forms' ) . '"></span>';
		$output .= '</form>';
		$output .= '</div>';

		wp_send_json_success(
			array(
				'html' => $output,
			)
		);
	}

	/**
	 * Retrieves the header of a CSV file.
	 *
	 * The function reads the contents of the CSV file, splits it into an array of lines, and retrieves
	 * the first line, which represents the header of the CSV file. It then sends a JSON success
	 * response with the header as an array of values.
	 *
	 * @since 3.0.0
	 *
	 * @param array $csv_data The CSV data containing the file to be processed.
	 */
	public static function get_csv_header( $csv_data ) {

		if ( ! isset( $csv_data['csvfile'] ) ) {
			wp_send_json_error(
				array(
					'message' => 'Please upload csv file.',
				)
			);
		}

		$file_extension = strtolower( pathinfo( $csv_data['csvfile']['name'], PATHINFO_EXTENSION ) );

		if ( 'csv' !== $file_extension ) {
			wp_send_json_error(
				array(
					'message' => 'File must be a CSV file.',
				)
			);
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload_dir = wp_upload_dir();
		$filename   = 'import_entries_data.csv';
		$csv_url    = $upload_dir['basedir'] . $upload_dir['subdir'] . '/' . $filename;

		// Remove existing file if already exists
		if ( ! empty( $csv_url ) && file_exists( $csv_url ) ) {
			@unlink( $csv_url );
		}

		// Move uploaded file to uploads directory
		$csv_file    = $csv_data['csvfile']['tmp_name'];
		$upload_file = move_uploaded_file( $csv_file, $csv_url );

		if ( ! $upload_file || ! file_exists( $csv_url ) ) {
			wp_send_json_error(
				array(
					'message' => 'File upload failed.',
				)
			);
		}

		// Use fgetcsv to safely parse the header
		$handle = fopen( $csv_url, 'r' );
		if ( ! $handle ) {
			wp_send_json_error(
				array(
					'message' => 'Unable to open uploaded CSV file.',
				)
			);
		}

		$csv_header = fgetcsv( $handle );
		fclose( $handle );

		if ( empty( $csv_header ) ) {
			wp_send_json_error(
				array(
					'message' => 'CSV file doesn\'t contain any header row.',
				)
			);
		}

		return $csv_header;
	}


	/**
	 * Import entries from the CSV file and process them in the background.
	 *
	 * This function checks the AJAX referer and retrieves the mapping fields array from the POST data.
	 * It then reads the CSV file and processes each row, updating the options and pushing the data to the background process queue.
	 * Finally, it sends a JSON success response with a message and a link to the imported entries.
	 *
	 * @since 3.0.0
	 *
	 * @throws Exception If an error occurs during the import process.
	 */
	public static function import_entries() {
		try {
			check_ajax_referer( 'evf-import-entries', 'security' );

			$map_fields_array = array();

			if ( ! isset( $_POST['data'] ) ) {
				wp_send_json_error(
					array(
						'message' => 'Something went wrong. Please try again.',
					)
				);
			}

			$data = ! empty($_POST['data']) ? $_POST['data'] : array(); //phpcs:ignore

			if ( empty( $data ) ) {
				wp_send_json_error(
					array(
						'message' => 'Something went wrong. Please try again.',
					)
				);
			}

			foreach ( $data as $key => $map_fields ) {
				if ( count( $data ) - 1 === $key ) {
					$map_fields_array['form_id'] = sanitize_text_field(wp_unslash($map_fields['value'])); //phpcs:ignore
					continue;
				}

				if ( 0 != $key % 2 ) {
					continue;
				}

				$map_fields_array[ $key ] = array(
					'field_id'       => sanitize_text_field(wp_unslash($map_fields['value'])), //phpcs:ignore
					'map_csv_column' => sanitize_text_field(wp_unslash($data[++$key]['value'])), //phpcs:ignore
				);
			}

			$upload_dir = wp_upload_dir();
			$filename   = 'import_entries_data.csv';
			$csv_url    = $upload_dir['basedir'] . $upload_dir['subdir'] . '/' . $filename;

			if ( ! empty( $csv_url ) && file_exists( $csv_url ) ) {
				$csv_file = fopen( $csv_url, 'r' );
				$row      = 0;
				update_option( 'everest_forms_mapping_fields_array', $map_fields_array );
				while ( ( $row_data = fgetcsv( $csv_file, 0, ',' ) ) !== false ) {
					if ( strlen( implode( $row_data ) ) != 0 ) {
						if ( 0 === $row ) {
							update_option( 'everest_forms_csv_titles', $row_data );
						} else {
							self::$background_process->push_to_queue( $row_data );
						}
						++$row;
					}
				}
				fclose( $csv_file );
				unlink( $csv_url );
				$test = self::$background_process->save()->dispatch();
			}

			wp_send_json_success(
				array(
					'message'     => 'Your data is currently being imported in the background. Please check the imported entries shortly.',
					'entry_link'  => admin_url( 'admin.php?page=evf-entries&form_id=' . $map_fields_array['form_id'] ),
					'button_text' => 'View Entries',
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}
	/**
	 * Generate the restapi key
	 *
	 * @since 3.0.5
	 */
	public static function generate_restapi_key() {
		try {
			check_ajax_referer( 'process-restapi-api-ajax-nonce', 'security' );
			$key = generate_api_key();
			wp_send_json_success( $key );
		} catch ( Exception $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Installs and activates the Smart SMTP plugin.
	 *
	 * @since 3.0.9
	 *
	 * @return void Outputs a JSON response.
	 */
	public static function install_and_activate_smart_smtp() {
		try {
			check_ajax_referer( 'everest-forms-smart-smtp-installation-nonce', 'security' );
			if ( ! current_user_can( 'manage_everest_forms' ) ) {
				wp_send_json_error( array( 'message' => 'You do not have permission to install plugins.' ) );
			}

			if ( is_plugin_active( 'smart-smtp/smart-smtp.php' ) ) {
				wp_send_json_success( array( 'message' => 'SmartSMTP plugin is already activated!' ) );
			}

			$installed_plugins = get_plugins();

			if ( array_key_exists( 'smart-smtp/smart-smtp.php', $installed_plugins ) ) {
				$activate_result = activate_plugin( 'smart-smtp/smart-smtp.php' );
				if ( is_wp_error( $activate_result ) ) {
					$error_message = $activate_result->get_error_message();
					wp_send_json_error(
						array(
							/* translators: %s: Error message explaining the activation failure */
							'message' => esc_html__( 'Sorry, we are unable to activate SmartSMTP because of %s', 'everest-forms' ),
							esc_html( $error_message ),
						)
					);
				}
			} else {
				require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

				$plugin_info = plugins_api( 'plugin_information', array( 'slug' => 'smart-smtp' ) );
				if ( is_wp_error( $plugin_info ) ) {
					wp_send_json_error( array( 'message' => esc_html__( 'Sorry, your internet connection was interrupted', 'everest-forms' ) ) );
				}

				$upgrader       = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
				$install_result = $upgrader->install( $plugin_info->download_link );
				if ( is_wp_error( $install_result ) ) {
					$installation_error = $install_result->get_error_message();
					wp_send_json_error(
						array(
							/* translators: %s: Error message explaining the installation failure */
							'message' => esc_html__( 'Plugin installation failed due to %s', 'everest-forms' ),
							esc_html( $installation_error ),
						)
					);
				}

				$activate_result = activate_plugin( 'smart-smtp/smart-smtp.php' );
			}

			if ( is_wp_error( $activate_result ) ) {
				wp_send_json_error( array( 'message' => 'Plugin activation failed: ' . $activate_result->get_error_message() ) );
			}

			wp_send_json_success(
				array(
					'message'         => 'SmartSMTP plugin installed and activated successfully!',
					'redirection_url' => evf_get_smart_smtp_google_workspace_setup_url(),
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Get form settings theme styles
	 */
	public static function form_preview_save() {
		check_ajax_referer( 'evf_form_preview_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'everest-forms' ) ) );
			wp_die( -1 );
		}
		$form_id = isset( $_POST['id'] ) ? sanitize_text_field( $_POST['id'] ) : '';
		$theme   = isset( $_POST['theme'] ) ? sanitize_text_field( $_POST['theme'] ) : '';

		if ( empty( $form_id ) || empty( $theme ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient information', 'everest-forms' ) ) );
		}

		$default_theme = ( 'default' === $theme ) ? 'default' : 'theme';
		update_post_meta( $form_id, 'everest_forms_enable_theme_style', $default_theme );

		wp_send_json_success( array( 'message' => __( 'Saved', 'everest-forms' ) ) );
	}
	/**
	 * Delete the tags.
	 *
	 * @since 3.2.0
	 */
	public static function delete_form_tags() {
		check_ajax_referer( 'ajax_manage_tags_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'everest-forms' ) ) );
			wp_die( -1 );
		}

		$tags = isset( $_POST['tags'] ) ? array_map( 'absint', $_POST['tags'] ) : array();

		if ( empty( $tags ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing tags.', 'everest-forms' ) ) );
		}

		foreach ( $tags as $tag ) {
			wp_delete_term( $tag, EVF_Post_Types::TAGS_TAXONOMY );
		}

		wp_send_json_success( array( 'message' => __( 'Tags are deleted successfully.', 'everest-forms' ) ) );
	}

	/**
	 * Update tags in bulk.
	 *
	 * @since 3.2.0
	 */
	public static function update_tags_in_bulk() {
		check_ajax_referer( 'ajax_manage_tags_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'everest-forms' ) ) );
			wp_die( -1 );
		}

		$tags  = isset( $_POST['tags'] ) ? $_POST['tags'] : array();
		$forms = isset( $_POST['forms'] ) ? array_map( 'absint', $_POST['forms'] ) : array();

		if ( empty( $forms ) ) {
			wp_send_json_error( array( 'message' => __( 'Please select atleast on form to updat the tags.', 'everest-forms' ) ) );
		}

		$term_ids = array();

		foreach ( $tags as $tag_name ) {
			if ( is_numeric( $tag_name ) ) {
				$term = get_term( absint( $tag_name ), EVF_Post_Types::TAGS_TAXONOMY );
				if ( $term && ! is_wp_error( $term ) ) {
					$term_ids[] = $term->term_id;
					continue;
				}
			}

			$term = term_exists( $tag_name, EVF_Post_Types::TAGS_TAXONOMY );
			if ( $term === null ) {
				$term = wp_insert_term( sanitize_text_field( $tag_name ), EVF_Post_Types::TAGS_TAXONOMY );
			}
			if ( ! is_wp_error( $term ) ) {
				$term_ids[] = is_array( $term ) ? (int) $term['term_id'] : $term;
			}
		}

		foreach ( $forms as $id ) {
			wp_set_post_terms( $id, $term_ids, EVF_Post_Types::TAGS_TAXONOMY, false );
		}

		wp_send_json_success( array( 'message' => __( 'Tags are updated successfully.', 'everest-forms' ) ) );
	}

	/**
	 * Save the clean talk settings.
	 *
	 * @since 3.2.0
	 */
	public static function save_clean_talk_settings() {
		check_ajax_referer( 'everest_forms_clean_talk_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'everest-forms' ) ) );
			wp_die( -1 );
		}

		if ( isset( $_POST['action'] ) && 'everest_forms_save_clean_talk_settings' != $_POST['action'] ) {
			wp_send_json_error( array( 'message' => __( 'Action doesn\'t match.', 'everest-forms' ) ) );
			wp_die( -1 );
		}

		$form_data = isset( $_POST['form_data'] ) ? $_POST['form_data'] : '';

		if ( empty( $form_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient information', 'everest-forms' ) ) );
		}

		$access_key = isset( $form_data['access_key'] ) ? sanitize_text_field( wp_unslash( $form_data['access_key'] ) ) : '';

		$output = '';
		if ( empty( $access_key ) ) {
			$output .= '<span class="everest-forms-clean-talk-icon-box">
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M8.125 8.125L11.875 11.875M11.875 8.125L8.125 11.875M17.5 10C17.5 10.9849 17.306 11.9602 16.9291 12.8701C16.5522 13.7801 15.9997 14.6069 15.3033 15.3033C14.6069 15.9997 13.7801 16.5522 12.8701 16.9291C11.9602 17.306 10.9849 17.5 10 17.5C9.01509 17.5 8.03982 17.306 7.12987 16.9291C6.21993 16.5522 5.39314 15.9997 4.6967 15.3033C4.00026 14.6069 3.44781 13.7801 3.0709 12.8701C2.69399 11.9602 2.5 10.9849 2.5 10C2.5 8.01088 3.29018 6.10322 4.6967 4.6967C6.10322 3.29018 8.01088 2.5 10 2.5C11.9891 2.5 13.8968 3.29018 15.3033 4.6967C16.7098 6.10322 17.5 8.01088 17.5 10Z" stroke="#F75259" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</span>';
			$output .= '<span class="everest-forms-clean-talk-message-box">Please enter the CleanTalk access key.</span>';
			$output .= '</div>';
			wp_send_json_error(
				array(
					'error' => 'empty',
					'html'  => $output,
				)
			);
		}

		$clean_talk_request = array(
			'method_name' => 'notice_paid_till',
			'auth_key'    => $access_key,
		);

		$response = wp_remote_post(
			'https://api.cleantalk.org/',
			array(
				'body'    => \http_build_query( $clean_talk_request, true ),
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
			)
		);
		$response = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $response->data->moderate == 1 && $response->data->valid == 1 && $response->data->product_id == 1 ) {
			update_option( 'everest_forms_recaptcha_cleantalk_access_key', $access_key );

			$output .= '<span class="everest-forms-clean-talk-icon-box">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M7.5 10.625L9.375 12.5L12.5 8.125M17.5 10C17.5 10.9849 17.306 11.9602 16.9291 12.8701C16.5522 13.7801 15.9997 14.6069 15.3033 15.3033C14.6069 15.9997 13.7801 16.5522 12.8701 16.9291C11.9602 17.306 10.9849 17.5 10 17.5C9.01509 17.5 8.03982 17.306 7.12987 16.9291C6.21993 16.5522 5.39314 15.9997 4.6967 15.3033C4.00026 14.6069 3.44781 13.7801 3.0709 12.8701C2.69399 11.9602 2.5 10.9849 2.5 10C2.5 8.01088 3.29018 6.10322 4.6967 4.6967C6.10322 3.29018 8.01088 2.5 10 2.5C11.9891 2.5 13.8968 3.29018 15.3033 4.6967C16.7098 6.10322 17.5 8.01088 17.5 10Z" stroke="#4CC741" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</span>';
			$output .= '<span class="everest-forms-clean-talk-message-box">Settings saved successfully.</span>';
			$output .= '</div>';

			wp_send_json_success(
				array(
					'html' => $output,
				)
			);
		} else {
			$output .= '<span class="everest-forms-clean-talk-icon-box">
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M8.125 8.125L11.875 11.875M11.875 8.125L8.125 11.875M17.5 10C17.5 10.9849 17.306 11.9602 16.9291 12.8701C16.5522 13.7801 15.9997 14.6069 15.3033 15.3033C14.6069 15.9997 13.7801 16.5522 12.8701 16.9291C11.9602 17.306 10.9849 17.5 10 17.5C9.01509 17.5 8.03982 17.306 7.12987 16.9291C6.21993 16.5522 5.39314 15.9997 4.6967 15.3033C4.00026 14.6069 3.44781 13.7801 3.0709 12.8701C2.69399 11.9602 2.5 10.9849 2.5 10C2.5 8.01088 3.29018 6.10322 4.6967 4.6967C6.10322 3.29018 8.01088 2.5 10 2.5C11.9891 2.5 13.8968 3.29018 15.3033 4.6967C16.7098 6.10322 17.5 8.01088 17.5 10Z" stroke="#F75259" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</span>';
			$output .= '<span class="everest-forms-clean-talk-message-box">Invalid CleanTalk access key.</span>';
			$output .= '</div>';
			wp_send_json_error(
				array(
					'error' => 'invalid',
					'html'  => $output,
				)
			);
		}
	}
	/**
	 * This callback give the update nonce of the form.
	 *
	 * @since 3.3.0
	 */
	public static function get_form_update_nonce() {
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( empty( $form_id ) ) {
			wp_die( __( 'Form ID is missing!', 'everest-forms' ), 403 );
		}

		$form = evf()->form->get( $form_id );

		if ( empty( $form ) ) {
			wp_die( __( 'Form not found!', 'everest-forms' ), 403 );
		}

		// Strict referer verification
		$referer      = wp_get_referer();
		$allowed_host = parse_url( home_url(), PHP_URL_HOST );
		$referer_host = parse_url( $referer, PHP_URL_HOST );

		if ( ! $referer || $referer_host !== $allowed_host ) {
			wp_die( __( 'Invalid form submission source.', 'everest-forms' ), 403 );
		}

		wp_send_json_success( wp_create_nonce( 'everest-forms_process_submit' ) );
	}
}

EVF_AJAX::init();
