<?php
/**
 * Process form data
 *
 * @package EverestForms
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Cleantalk\Antispam\CleantalkRequest;
use EverestForms\Helpers\FormHelper;

/**
 * EVF_Form_Task class.
 */
class EVF_Form_Task {

	/**
	 * Holds errors.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	public $errors;

	/**
	 * Holds formatted fields.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	public $form_fields;

	/**
	 * Holds the ID of a successful entry.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public $entry_id = 0;

	/**
	 * Form data and settings.
	 *
	 * @since 1.5.0
	 *
	 * @var array
	 */
	public $form_data = array();

	/**
	 * Is hash validation?
	 *
	 * @var 1.7.4
	 */
	public $is_valid_hash = false;

	/**
	 * Ajax error array.
	 */
	public $ajax_err = array();

	/**
	 * Is notice print?
	 */
	public $evf_notice_print = false;

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp', array( $this, 'listen_task' ) );
		add_filter( 'everest_forms_field_properties', array( $this, 'load_previous_field_value' ), 99, 3 );
		add_action( 'everest_forms_complete_entry_save', array( $this, 'update_slot_booking_value' ), 10, 5 );
		add_action( 'everest_forms_complete_entry_save', array( $this, 'evf_set_approval_status' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'evf_admin_approve_entry' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'evf_admin_deny_entry' ) );
		add_action( 'admin_init', array( $this, 'evf_mark_entry_spam' ), 10 );
		add_action( 'admin_init', array( $this, 'evf_remove_entry_from_spam' ), 10 );
		/**
		 * Delete files.
		 *
		 * @since 3.3.0
		 */
		add_action( 'before_delete_post', array( $this, 'delete_entry_files_before_form_delete' ), 10, 1 );
		add_action( 'everest_forms_before_delete_entries', array( $this, 'delete_entry_files' ), 10, 1 );
	}

	/**
	 * Listen to see if this is a return callback or a posted form entry.
	 *
	 * @since 1.0.0
	 */
	public function listen_task() {
		if ( ! empty( $_GET['everest_forms_return'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->entry_confirmation_redirect( '', sanitize_text_field( wp_unslash( $_GET['everest_forms_return'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		$form_id = ! empty( $_POST['everest_forms']['id'] ) ? absint( $_POST['everest_forms']['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $form_id ) {
			return;
		}

		if ( ! empty( $_POST['everest_forms']['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->do_task( evf_sanitize_entry( wp_unslash( $_POST['everest_forms'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( ! evf_is_amp() ) {
			return;
		}

		$settings        = $this->form_data['settings'];
		$success_message = isset( $settings['successful_form_submission_message'] ) ? $settings['successful_form_submission_message'] : __( 'Thanks for contacting us! We will be in touch with you shortly.', 'everest-forms' );
		// Send 400 Bad Request when there are errors.
		if ( empty( $this->errors[ $form_id ] ) ) {
			wp_send_json(
				array(
					'message' => $success_message,
				),
				200
			);

			return;
		}
		$message = $this->errors[ $form_id ]['header'];

		if ( ! empty( $this->errors[ $form_id ]['footer'] ) ) {
			$message .= ' ' . $this->errors[ $form_id ]['footer'];
		}

		wp_send_json(
			array(
				'message' => $message,
			),
			400
		);
	}

	/**
	 * Do task of form entry
	 *
	 * @since 1.0.0
	 * @param array $entry $_POST object.
	 */
	public function do_task( $entry ) {
		$logger = evf_get_logger();
		try {
			$this->errors           = array();
			$this->form_fields      = array();
			$form_id                = absint( $entry['id'] );
			$form                   = evf()->form->get( $form_id );
			$honeypot               = false;
			$response_data          = array();
			$this->ajax_err         = array();
			$this->evf_notice_print = false;
			$logger                 = evf_get_logger();

			/**
			 * Filter to bypass the form nonce validation.
			 * By default it is false.
			 *
			 * @since 3.3.0
			 */
			if ( ! apply_filters( 'evf_bypass_form_nonce_validation', false, $form_id ) ) {
				// Check nonce for form submission.

				if ( empty( $_POST[ '_wpnonce' . $form_id ] ) || ! wp_verify_nonce( wp_unslash( sanitize_key( $_POST[ '_wpnonce' . $form_id ] ) ), 'everest-forms_process_submit' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					$this->errors[ $form_id ]['header'] = esc_html__( 'We were unable to process your form, please try again.', 'everest-forms' );
					$logger->error(
						$this->errors[ $form_id ]['header'],
						array( 'source' => 'form-submission' )
					);
					return $this->errors;
				}
			}

			// Validate form is real and active (published).
			if ( ! $form || 'publish' !== $form->post_status ) {
				$this->errors[ $form_id ]['header'] = esc_html__( 'Invalid form. Please check again.', 'everest-forms' );
				$logger->error(
					$this->errors[ $form_id ]['header'],
					array( 'source' => 'form-submission' )
				);
				return $this->errors;
			}

			// Check if the form is enabled or not.
			$form_enabled = evf_decode( $form->post_content );
			if ( isset( $form_enabled['form_enabled'] ) && ! $form_enabled['form_enabled'] ) {
				$this->errors[ $form_id ]['header'] = esc_html__( 'This form is disabled.', 'everest-forms' );
				$logger->error(
					$this->errors[ $form_id ]['header'],
					array( 'source' => 'form-submission' )
				);
				return $this->errors;
			}

			// Formatted form data for hooks.
			$this->form_data = apply_filters( 'everest_forms_process_before_form_data', evf_decode( $form->post_content ), $entry );

			// Remove locked (Pro/addon) fields from processing. They are shown in the
			// builder for upsell but never rendered on the published form, so they must
			// not be validated, formatted, or stored as entries (a required locked field
			// would otherwise block every submission).
			if ( ! empty( $this->form_data['form_fields'] ) ) {
				foreach ( $this->form_data['form_fields'] as $field_key => $field ) {
					if ( isset( $field['type'] ) && evf_is_field_locked( $field['type'] ) ) {
						unset( $this->form_data['form_fields'][ $field_key ] );
					}
				}
			}

			// Pre-process/validate hooks and filter. Data is not validated or cleaned yet so use with caution.
			$entry                      = apply_filters( 'everest_forms_process_before_filter', $entry, $this->form_data );
			$this->form_data['page_id'] = array_key_exists( 'post_id', $entry ) ? $entry['post_id'] : $form_id;

			$logger->info(
				__( 'Everest Forms Process Before.', 'everest-forms' ),
				array( 'source' => 'form-submission' )
			);
			do_action( 'everest_forms_process_before', $entry, $this->form_data );
			$logger->info(
				__( 'Everest Forms Process Before Form ID.', 'everest-forms' ),
				array( 'source' => 'form-submission' )
			);
			do_action( "everest_forms_process_before_{$form_id}", $entry, $this->form_data );

		$ajax_form_submission = isset( $this->form_data['settings']['ajax_form_submission'] ) ? $this->form_data['settings']['ajax_form_submission'] : 0;
		$stripe_via_selector  = function_exists( 'evf_is_gateway_in_selector_allowlist' ) && evf_is_gateway_in_selector_allowlist( array( 'form_data' => $this->form_data, 'gateway' => 'stripe' ) );
		$square_via_selector  = function_exists( 'evf_is_gateway_in_selector_allowlist' ) && evf_is_gateway_in_selector_allowlist( array( 'form_data' => $this->form_data, 'gateway' => 'square' ) );
		$paypal_via_selector  = function_exists( 'evf_is_gateway_in_selector_allowlist' ) && evf_is_gateway_in_selector_allowlist( array( 'form_data' => $this->form_data, 'gateway' => 'paypal' ) );
		if ( ( isset( $this->form_data['payments']['stripe']['enable_stripe'] ) && '1' === $this->form_data['payments']['stripe']['enable_stripe'] ) || $stripe_via_selector || ( isset( $this->form_data['payments']['square']['enable_square'] ) && '1' === $this->form_data['payments']['square']['enable_square'] ) || $square_via_selector || $paypal_via_selector ) {
			$ajax_form_submission = '1';
		}
			if ( '1' === $ajax_form_submission ) {
				// For the sake of validation we completely remove the validator option.
				update_option( 'evf_validation_error', '' );

				// Prepare fields for entry_save.
				foreach ( $this->form_data['form_fields'] as $field ) {
					if ( '' === isset( $this->form_data['form_fields']['meta-key'] ) ) {
						continue;
					}

					$field_id     = $field['id'];
					$field_type   = $field['type'];
					$field_submit = isset( $entry['form_fields'][ $field_id ] ) ? $entry['form_fields'][ $field_id ] : '';

					if ( 'signature' === $field_type ) {
						$field_submit = isset( $field_submit['signature_image'] ) ? $field_submit['signature_image'] : '';
					}

					$exclude = array( 'title', 'html', 'captcha', 'image-upload', 'file-upload', 'divider', 'reset', 'recaptcha', 'hcaptcha', 'turnstile', 'private-note', 'payment_summary' );

					if ( ! in_array( $field_type, $exclude, true ) ) {

						$this->form_fields[ $field_id ] = array(
							'id'       => $field_id,
							'name'     => sanitize_text_field( $field['label'] ),
							'meta_key' => $this->form_data['form_fields'][ $field_id ]['meta-key'],
							'type'     => $field_type,
							'value'    => evf_sanitize_textarea_field( $field_submit ),
						);
					}
				}
			}

			$this->form_data['entry'] = $entry;

			// Validate fields.
			foreach ( $this->form_data['form_fields'] as $field ) {
				$field_id        = $field['id'];
				$field_type      = $field['type'];
				$repeater_fields = array_key_exists( 'repeater-fields', $field ) ? $field['repeater-fields'] : 'no';

				$field_submit = isset( $entry['form_fields'][ $field_id ] ) ? $entry['form_fields'][ $field_id ] : '';

				if ( 'no' === $repeater_fields || 'repeater-fields' === $field_type ) {
					$logger->info(
						"Everest Forms Process Before validate {$field_type}.",
						array( 'source' => 'form-submission' )
					);
					if ( 'payment-coupon' != $field_type ) {
						do_action( "everest_forms_process_validate_{$field_type}", $field_id, $field_submit, $this->form_data, $field_type );
					}
				}

				if ( 'credit-card' === $field_type && isset( $_POST['everest_form_stripe_payment_intent_id'] ) ) {
					$this->evf_notice_print = true;
				}

				if ( 'yes' === get_option( 'evf_validation_error' ) && $ajax_form_submission ) {
					if ( count( $this->errors ) ) {
						foreach ( $this->errors as $_error ) {
							$this->ajax_err [] = $_error;
						}
					}
					update_option( 'evf_validation_error', '' );
				}
			}

			if ( function_exists( 'evf_validate_submitted_payment_gateway' ) && function_exists( 'evf_get_total_payment' ) ) {
				$payment_total = evf_sanitize_amount( evf_get_total_payment( $this->form_fields, $entry, $this->form_data ) );
				if ( $payment_total > 0 ) {
					$gateway_validation = evf_validate_submitted_payment_gateway( $this->form_data, $entry );
					if ( is_wp_error( $gateway_validation ) ) {
						$this->errors[ $form_id ]['header'] = $gateway_validation->get_error_message();
						$logger->error(
							$gateway_validation->get_error_message(),
							array( 'source' => 'form-submission' )
						);
						if ( $ajax_form_submission ) {
							$this->ajax_err[] = $this->errors[ $form_id ];
							update_option( 'evf_validation_error', 'yes' );
						}
					}
				}
			}

			// If validation issues occur, send the results accordingly.
			if ( $ajax_form_submission && count( $this->ajax_err ) ) {
				$response_data['error']    = $this->ajax_err;
				$response_data['message']  = apply_filters(	 'everest_forms_process_form_error_header', __( 'Form has not been submitted, please see the errors below.', 'everest-forms' ) );
				$response_data['response'] = 'error';
				$logger->error(
					__( 'Form has not been submitted.', 'everest-forms' ),
					array( 'source' => 'form-submission' )
				);
				return $response_data;
			}

			// reCAPTCHA check.
			if ( ! apply_filters( 'everest_forms_recaptcha_disabled', false ) ) {
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
					$theme_mode = get_option( 'everest_forms_recaptcha_turnstile_theme' );
				}
				$recaptcha_verified = false;
				$error              = '';
				foreach ( (array) $this->form_data['form_fields'] as $field ) {
					$field_type = isset( $field['type'] ) ? $field['type'] : '';
					$captcha    = array( 'recaptcha', 'hcaptcha', 'turnstile' );

					if (
						( ! empty( $site_key ) && ! empty( $secret_key ) &&
							isset( $this->form_data['settings']['recaptcha_support'] ) &&
							'1' === $this->form_data['settings']['recaptcha_support'] &&
							! isset( $_POST['__amp_form_verify'] ) &&
							( 'v3' === $recaptcha_type || ! evf_is_amp() )
						)
						||
						( ! empty( $site_key ) && ! empty( $secret_key ) && in_array( $field_type, $captcha, true ) )
					) {
						// Get the token based on CAPTCHA type
						$token = ! empty( $_POST['g-recaptcha-response'] ) ? evf_clean( wp_unslash( $_POST['g-recaptcha-response'] ) ) : false;

						if ( 'v3' === $recaptcha_type ) {
							$token = ! empty( $_POST['everest_forms']['recaptcha'] ) ? evf_clean( wp_unslash( $_POST['everest_forms']['recaptcha'] ) ) : false;
						} elseif ( 'hcaptcha' === $recaptcha_type ) {
							$token = ! empty( $_POST['h-captcha-response'] ) ? evf_clean( wp_unslash( $_POST['h-captcha-response'] ) ) : false;
						} elseif ( 'turnstile' === $recaptcha_type ) {
							$token = ! empty( $_POST['cf-turnstile-response'] ) ? evf_clean( wp_unslash( $_POST['cf-turnstile-response'] ) ) : false;
						}

						if ( ! $token ) {
							$error                              = esc_html__( 'CAPTCHA token missing. Please try again.', 'everest-forms' );
							$this->errors[ $form_id ]['header'] = $error;
							$logger->error( $error, array( 'source' => 'CAPTCHA' ) );
							return $this->errors;
						}

						// Validate the token
						if ( 'hcaptcha' === $recaptcha_type ) {
							$raw_response = wp_safe_remote_get( 'https://hcaptcha.com/siteverify?secret=' . $secret_key . '&response=' . $token );
						} elseif ( 'turnstile' === $recaptcha_type ) {
							$url          = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
							$params       = array(
								'method' => 'POST',
								'body'   => array(
									'secret'   => $secret_key,
									'response' => $token,
								),
							);
							$raw_response = wp_safe_remote_post( $url, $params );
						} else {
							$raw_response = wp_safe_remote_get( 'https://www.google.com/recaptcha/api/siteverify?secret=' . $secret_key . '&response=' . $token );
						}

						if ( ! is_wp_error( $raw_response ) ) {
							$response = json_decode( wp_remote_retrieve_body( $raw_response ) );

							$recaptcha_passed = ! empty( $response->success );

							if ( $recaptcha_passed && 'v3' === $recaptcha_type ) {
								$threshold = get_option( 'everest_forms_recaptcha_v3_threshold_score', apply_filters( 'everest_forms_recaptcha_v3_threshold', '0.5' ) );
								if ( ! isset( $response->score ) || $response->score < floatval( $threshold ) ) {
									$recaptcha_passed = false;
									if ( isset( $response->score ) ) {
										$error .= ' (' . esc_html( $response->score ) . ')';
									}
								}
							}

							if ( ! $recaptcha_passed ) {
								if ( 'hcaptcha' === $recaptcha_type ) {
									$error = esc_html__( 'hCaptcha verification failed, please try again later.', 'everest-forms' );
								} elseif ( 'turnstile' === $recaptcha_type ) {
									$error = esc_html__( 'Cloudflare Turnstile verification failed, please try again later.', 'everest-forms' );
								} else {
									$error = esc_html__( 'Google reCAPTCHA verification failed, please try again later.', 'everest-forms' );
								}

								$this->errors[ $form_id ]['header'] = $error;
								$logger->error( $error, array( 'source' => 'CAPTCHA' ) );
								return $this->errors;
							}
						}

						$recaptcha_verified = true;
						break;
					}
				}
			}

			// Initial error check.
			$errors = apply_filters( 'everest_forms_process_initial_errors', $this->errors, $this->form_data );

			// Minimum time to submit check.
			$min_submit_time = $this->form_submission_waiting_time( $this->errors, $this->form_data );
			if ( isset( $min_submit_time[ $form_id ]['header'] ) && ! empty( $min_submit_time ) ) {
				$this->errors[ $form_id ]['header'] = $min_submit_time[ $form_id ]['header'];
				$logger->error(
					$min_submit_time[ $form_id ]['header'],
					array( 'source' => 'Minimum time to submit' )
				);
				return $this->errors;
			}

			if ( isset( $_POST['__amp_form_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( empty( $errors[ $form_id ] ) ) {
					wp_send_json( array(), 200 );
				} else {
					$verify_errors = array();

					foreach ( $errors[ $form_id ] as $field_id => $error_fields ) {
						$field            = $this->form_data['fields'][ $field_id ];
						$field_properties = EVF_Shortcode_Form::get_field_properties( $field, $this->form_data );

						if ( is_string( $error_fields ) ) {

							if ( 'checkbox' === $field['type'] || 'radio' === $field['type'] || 'select' === $field['type'] ) {
								$first = current( $field_properties['inputs'] );
								$name  = $first['attr']['name'];
							} elseif ( isset( $field_properties['inputs']['primary']['attr']['name'] ) ) {
								$name = $field_properties['inputs']['primary']['attr']['name'];
							}

							$verify_errors[] = array(
								'name'    => $name,
								'message' => $error_fields,
							);
						} else {
							foreach ( $error_fields as $error_field => $error_message ) {

								if ( isset( $field_properties['inputs'][ $error_field ]['attr']['name'] ) ) {
									$name = $field_properties['inputs'][ $error_field ]['attr']['name'];
								}

								$verify_errors[] = array(
									'name'    => $name,
									'message' => $error_message,
								);
							}
						}
					}

					wp_send_json(
						array(
							'verifyErrors' => $verify_errors,
						),
						400
					);
				}
				return;
			}
			if ( ! empty( $errors[ $form_id ] ) ) {
				if ( empty( $errors[ $form_id ]['header'] ) ) {
					$errors[ $form_id ]['header'] = apply_filters( 'everest_forms_process_form_error_header', __( 'Form has not been submitted, please see the errors below.', 'everest-forms' ) );
					$logger->error(
						$errors[ $form_id ]['header'],
						array( 'source' => 'form-submission' )
					);
				}
				$this->errors = $errors;
				return $this->errors;
			}

			// Early honeypot validation - before actual processing.
			if ( isset( $this->form_data['settings']['honeypot'] ) && '1' === $this->form_data['settings']['honeypot'] && ! empty( $entry['hp'] ) ) {
				$honeypot = esc_html__( 'Everest Forms honeypot field triggered.', 'everest-forms' );
			}

			$honeypot = apply_filters( 'everest_forms_process_honeypot', $honeypot, $this->form_fields, $entry, $this->form_data );

			// If spam - return early.
			if ( $honeypot ) {
				$logger = evf_get_logger();
				$logger->notice( sprintf( 'Spam entry for Form ID %d Response: %s', absint( $this->form_data['id'] ), evf_print_r( $entry, true ) ), array( 'source' => 'honeypot' ) );
				return $this->errors;
			}

			/** Akismet anit-spam protection.
			 * If spam - return early
			 *
			 * @since 2.4.0
			 */
			if ( $this->get_akismet_validate( $entry, $form_id ) ) {
				$logger = evf_get_logger();
				$logger->notice( sprintf( 'Spam entry for Form ID %d Response: %s', absint( $this->form_data['id'] ), evf_print_r( $entry, true ) ), array( 'source' => 'akismet' ) );

				if ( isset( $this->form_data['settings']['akismet_protection_type'] ) && 'validation_failed' === $this->form_data['settings']['akismet_protection_type'] ) {

					$akismet_message              = apply_filters( 'evf_akisment_validatation_error_message', sprintf( 'Akismet anti-spam verification failed, please try again later.', 'everest-forms' ) );
					$errors[ $form_id ]['header'] = $akismet_message;
					$this->errors                 = $errors;

					return $this->errors;
				}
				$entry['evf_spam_status'] = 'spam';
			}

			/** CleanTalk anit-spam protection.
			 * If spam - return early.
			 *
			 * @since 3.2.0
			 */
			if ( $this->get_clean_talk_validate( $entry, $form_id ) ) {
				$logger = evf_get_logger();
				$logger->notice( sprintf( 'Spam entry for Form ID %d Response: %s', absint( $this->form_data['id'] ), evf_print_r( $entry, true ) ), array( 'source' => 'cleantalk' ) );
				if ( isset( $this->form_data['settings']['cleantalk_protection_type'] ) && 'validation_failed' === $this->form_data['settings']['cleantalk_protection_type'] ) {

					$cleantalk_message            = apply_filters( 'evf_cleantalk_validatation_error_message', sprintf( 'CleanTalk anti-spam verification failed, please try again later.', 'everest-forms' ) );
					$errors[ $form_id ]['header'] = $cleantalk_message;
					$this->errors                 = $errors;

					return $this->errors;
				}
				$entry['evf_spam_status'] = 'spam';
			}
			// Pass the form created date into the form data.
			$this->form_data['created'] = $form->post_date;

			// Format and Sanitize inputs.
			foreach ( (array) $this->form_data['form_fields'] as $field ) {
				$field_id     = $field['id'];
				$field_key    = isset( $field['meta-key'] ) ? $field['meta-key'] : '';
				$field_type   = $field['type'];
				$field_submit = isset( $entry['form_fields'][ $field_id ] ) ? $entry['form_fields'][ $field_id ] : array();

				// Handle file uploads for save continue.
				if ( in_array( $field_type, array( 'file-upload', 'image-upload' ), true ) ) {
					if ( is_array( $field_submit ) ) {
						unset( $field_submit['old_files'], $field_submit['new_files'] );
					}

					if ( defined( 'EVF_SAVE_AND_CONTINUE_VERSION' ) ) {
						if ( ! is_array( $field_submit ) ) {
							$field_submit = array();
						}

						$field_submit['new_files'] = isset( $_POST[ 'everest_forms_' . $form_id . '_' . $field_id ] )
							? stripslashes_deep( $_POST[ 'everest_forms_' . $form_id . '_' . $field_id ] )
							: array();
						$field_submit['old_files'] = isset( $_POST[ 'everest_forms_' . $form_id . '_old_' . $field_id ] )
							? stripslashes_deep( $_POST[ 'everest_forms_' . $form_id . '_old_' . $field_id ] )
							: array();

						$deleted_files = isset( $_POST[ 'everest_forms_' . $form_id . '_delete_' . $field_id ] )
							? stripslashes_deep( $_POST[ 'everest_forms_' . $form_id . '_delete_' . $field_id ] )
							: '';

						if ( ! empty( $deleted_files ) ) {
							$deleted_files = json_decode( $deleted_files, true );

							if ( is_array( $deleted_files ) ) {
								$upload_dir      = wp_get_upload_dir();
								$uploads_baseurl = trailingslashit( $upload_dir['baseurl'] );
								$uploads_basedir = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );

								foreach ( $deleted_files as $file ) {
									$file = json_decode( $file, true );

									if ( empty( $file['value'] ) || ! is_string( $file['value'] ) ) {
										continue;
									}

									$file_url = esc_url_raw( $file['value'] );

									if ( 0 !== strpos( $file_url, $uploads_baseurl ) ) {
										continue;
									}

									$path      = wp_parse_url( $file_url, PHP_URL_PATH );
									$base_path = wp_parse_url( $uploads_baseurl, PHP_URL_PATH );

									if ( ! is_string( $path ) || ! is_string( $base_path ) || 0 !== strpos( $path, $base_path ) ) {
										continue;
									}

									$relative_path = ltrim( substr( $path, strlen( $base_path ) ), '/' );
									$candidate     = wp_normalize_path( $uploads_basedir . $relative_path );
									$resolved_path = realpath( $candidate );

									if ( false === $resolved_path ) {
										continue;
									}

									$resolved_path = wp_normalize_path( $resolved_path );

									if ( 0 !== strpos( $resolved_path, $uploads_basedir ) ) {
										continue;
									}

									FormHelper::remove_file( $file_url );
								}
							}
						}
					}
				}

				$repeater_fields = array_key_exists( 'repeater-fields', $field ) ? $field['repeater-fields'] : 'no';

				if ( 'no' === $repeater_fields || 'repeater-fields' === $field_type ) {
					$logger->info(
						sprintf( 'Everest Forms Process Format %s.', $field_type ),
						array( 'source' => 'form-submission' )
					);
					do_action( "everest_forms_process_format_{$field_type}", $field_id, $field_submit, $this->form_data, $field_key );
				}
			}

			// This hook is for internal purposes and should not be leveraged.
			$logger->info(
				'Everest Forms Process Format After.',
				array( 'source' => 'form-submission' )
			);
			do_action( 'everest_forms_process_format_after', $this->form_data );

			// Process hooks/filter - this is where most addons should hook
			// because at this point we have completed all field validation and
			// formatted the data.
			$this->form_fields = apply_filters( 'everest_forms_process_filter', $this->form_fields, $entry, $this->form_data );

			$all_data = array(
				'form_fields' => $this->form_fields,
				'entry'       => $entry,
				'form_data'   => $this->form_data,
			);

			if ( ! empty( $_POST[ 'applied_coupons_data' ] ) ) {
				$applied_coupons_data = json_decode( wp_unslash( $_POST[ 'applied_coupons_data' ] ), true );
				$all_data['applied_coupons_data'] = $applied_coupons_data;
			}

			foreach ( $this->form_data['form_fields'] as $field ) {
				$field_id        = $field['id'];
				$field_type      = $field['type'];

				$field_submit = isset( $entry['form_fields'][ $field_id ] ) ? $entry['form_fields'][ $field_id ] : '';

				if ( 'payment-coupon' === $field_type ) {
					$logger->info(
						"Everest Forms Process coupon validating {$field_type}.",
						array( 'source' => 'form-submission' )
					);
					do_action( "everest_forms_process_validate_{$field_type}", $field_id, $field_submit, $all_data);
				}

				if ( 'yes' === get_option( 'evf_validation_error' ) && $ajax_form_submission ) {
					if ( count( $this->errors ) ) {
						foreach ( $this->errors as $_error ) {
							$this->ajax_err [] = $_error;
						}
					}
					update_option( 'evf_validation_error', '' );
				}
			}
			$logger->notice( sprintf( 'Everest Form Process: %s', evf_print_r( $this->form_fields, true ) ) );

			$logger->info(
				'Everest Forms Process.',
				array( 'source' => 'form-submission' )
			);
			do_action( 'everest_forms_process', $this->form_fields, $entry, $this->form_data );
			$logger->info(
				"Everest Forms Process {$form_id}.",
				array( 'source' => 'form-submission' )
			);
			do_action( "everest_forms_process_{$form_id}", $this->form_fields, $entry, $this->form_data );

			$this->form_fields = apply_filters( 'everest_forms_process_after_filter', $this->form_fields, $entry, $this->form_data );
			$logger->notice( sprintf( 'Everest Form Process After: %s', evf_print_r( $this->form_fields, true ) ) );

			/**
			 *  Apply smart tags to form fields values.
			 *
			 * @since 3.2.3
			 */
			foreach ( $this->form_fields as $key => $value ) {
				if ( ! empty( $value['value'] ) && is_string( $value['value'] ) && strpos( $value['value'], '{' ) !== false ) {
					$this->form_fields[ $key ]['value'] = apply_filters( 'everest_forms_process_smart_tags', $value['value'], $this->form_data, $this->form_fields );
				}
			}

			// One last error check - don't proceed if there are any errors.
			if ( ! empty( $this->errors[ $form_id ] ) ) {
				if ( empty( $this->errors[ $form_id ]['header'] ) ) {
					$this->errors[ $form_id ]['header'] = 	apply_filters( 'everest_forms_process_form_error_header', esc_html__( 'Form has not been submitted, please see the errors below.', 'everest-forms' ) );
				}
				$logger->error(
					__( 'Form has not been submitted', 'everest-forms' ),
					array( 'source' => 'form-submission' )
				);
				return $this->errors;
			}

			$logger->notice( sprintf( 'Entry is Saving to DataBase' ) );
			// Success - add entry to database.
			$logger->info(
				__( 'Entry Added to Database.', 'everest-forms' ),
				array( 'source' => 'form-submission' )
			);

			$applied_coupons_data = ! empty( $_POST['applied_coupons_data'] ) ? $_POST['applied_coupons_data'] : '';
			$this->form_data['applied_coupons_data'] = $applied_coupons_data;

			if ( ! empty( $applied_coupons_data ) ) {
				$decoded_coupons = $applied_coupons_data;
				if ( is_string( $decoded_coupons ) ) {
					$decoded = json_decode( wp_unslash( $decoded_coupons ), true );
					if ( is_array( $decoded ) ) {
						$decoded_coupons = $decoded;
					}
				}

				if ( is_array( $decoded_coupons ) ) {
					foreach ( $decoded_coupons as $coupon ) {
						$field_id = isset( $coupon['field_id'] ) ? $coupon['field_id'] : '';
						if ( ! empty( $field_id ) && isset( $this->form_fields[ $field_id ] ) ) {
							if ( ! is_array( $this->form_fields[ $field_id ]['value'] ) ) {
								$this->form_fields[ $field_id ]['value'] = array();
							}
							$this->form_fields[ $field_id ]['value'][] = $coupon;
						}
					}
				}
			}
			$entry_id = $this->entry_save( $this->form_fields, $entry, $this->form_data['id'], $this->form_data );

			do_action( 'everest_forms_process_user_registration', $this->form_fields, $entry, $this->form_data, $entry_id );

			$logger->notice( sprintf( 'Entry is Saved to DataBase' ) );

			$logger->notice( sprintf( 'Sending Email' ) );
			// Success - send email notification.
			$logger->info(
				__( 'Sent Email Notification.', 'everest-forms' ),
				array( 'source' => 'form-submission' )
			);
			$this->entry_email( $this->form_fields, $entry, $this->form_data, $entry_id, 'entry' );
			$logger->notice( sprintf( 'Successfully Send the email' ) );

			// @todo remove this way of printing notices.
			add_filter( 'everest_forms_success', array( $this, 'check_success_message' ), 10, 2 );

			// Pass completed and formatted fields in POST.
			$_POST['everest-forms']['complete'] = $this->form_fields;

			// Pass entry ID in POST.
			$_POST['everest-forms']['entry_id'] = $entry_id;

			// Post-process hooks.
			$logger->info(
				__( 'Everest Forms Process Completed.', 'everest-forms' ),
				array( 'source' => 'form-submission' )
			);

			if ( ! empty( $_POST[ 'applied_coupons_data' ] ) ) {
				$applied_coupons_data = json_decode( wp_unslash( $_POST[ 'applied_coupons_data' ] ), true );

				$this->form_data['applied_coupons_data'] = $applied_coupons_data;
			}

			do_action( 'everest_forms_process_complete', $this->form_fields, $entry, $this->form_data, $entry_id );
			$logger->info(
				"Everest Forms Process Completed {$form_id}.",
				array( 'source' => 'form-submission' )
			);
			do_action( "everest_forms_process_complete_{$form_id}", $this->form_fields, $entry, $this->form_data, $entry_id );
			do_action( 'everest_forms_process_complete_send_data_to_zapier_app', $this->form_fields, $entry, $this->form_data, $entry_id );

			// Payment gateways update entry meta during process_complete; do not show success when payment failed.
			if ( ! empty( $entry_id ) ) {
				$payment_fail = $this->get_failed_payment_submission_result( absint( $entry_id ) );
				if ( is_array( $payment_fail ) ) {
					if ( '1' === $ajax_form_submission ) {
						return $payment_fail;
					}

					if ( function_exists( 'evf_notice_count' ) && 0 === evf_notice_count( 'error' ) ) {
						evf_add_notice( $payment_fail['message'], 'error' );
					}

					delete_option( 'everest_forms_overall_feedback_is_called' );
					return $response_data;
				}
			}
		} catch ( Exception $e ) {
			$raw_message   = $e->getMessage();
			$decoded_error = json_decode( $raw_message, true );

			// Detect Google OAuth / API errors (UNAUTHENTICATED 401).
			$is_google_auth_error = (
				JSON_ERROR_NONE === json_last_error() &&
				isset( $decoded_error['error']['status'] ) &&
				'UNAUTHENTICATED' === $decoded_error['error']['status']
			) || false !== strpos( $raw_message, 'UNAUTHENTICATED' );

			if ( $is_google_auth_error ) {
				$display_message = class_exists( '\EverestForms\AuthorizeNet\Helpers' )
					? \EverestForms\AuthorizeNet\Helpers::pgw_selector_subscription_mapping_error_message()
					: esc_html__( 'Something error occur', 'everest-forms' );
			} elseif ( JSON_ERROR_NONE === json_last_error() && ! empty( $decoded_error['error']['message'] ) ) {
				$display_message = $decoded_error['error']['message'];
			} else {
				$display_message = $raw_message;
			}

			evf_add_notice( $display_message, 'error' );
			$logger->error(
				$raw_message,
				array( 'source' => 'form-submission' )
			);
			if ( '1' === $ajax_form_submission ) {
				$response_data['response'] = 'error';
				$response_data['message']  = wp_strip_all_tags( $display_message );
				$response_data['error']    = array();
				$response_data['form_id']  = $form_id;
				return $response_data;
			}
			// Non-AJAX: return early so the success message is not shown.
			return $response_data;
		}
		// For form confirmation backward compatilibity.
		$this->form_data = evf_form_confirmation_backward_compatibility( $this->form_data );
		$settings        = $this->form_data['settings'];
		$message         = isset( $settings['successful_form_submission_message'] ) ? $settings['successful_form_submission_message'] : __( 'Thanks for contacting us! We will be in touch with you shortly.', 'everest-forms' );
		$form_state_type = isset( $settings['form_state_type'] ) ? $settings['form_state_type'] : 'hide';

		if ( 'hide' === $form_state_type ) {

			$message_display_location = isset( $settings['message_display_location_of_hide'] ) ? $settings['message_display_location_of_hide'] : 'hide';
		} else {
			$message_display_location = isset( $settings['message_display_location_of_reset'] ) ? $settings['message_display_location_of_reset'] : 'top';
		}

		// $message_display_location  = isset( $settings['successful_form_submission_message_display_location'] ) ? $settings['successful_form_submission_message_display_location'] : 'hide';
		$is_pdf_submission_enabled = isset( $settings['pdf_submission']['enable_pdf_submission'] ) && ( 'yes' === $settings['pdf_submission']['enable_pdf_submission'] || '1' === $settings['pdf_submission']['enable_pdf_submission'] );
		$pdf_submission            = $is_pdf_submission_enabled ? $settings['pdf_submission'] : '';

		$is_pdf_download_after_submit   = isset( $pdf_submission['everest_forms_pdf_download_after_submit'] ) && ( 'yes' === $pdf_submission['everest_forms_pdf_download_after_submit'] || '1' === $pdf_submission['everest_forms_pdf_download_after_submit'] );
		$is_global_pdf_download_enabled = 'yes' === get_option( 'everest_forms_pdf_download_after_submit', 'no' ) || '1' === get_option( 'everest_forms_pdf_download_after_submit', 'no' );
		$should_allow_pdf_download      = $is_pdf_submission_enabled ? $is_pdf_download_after_submit : $is_global_pdf_download_enabled;

		// Check Conditional Logic and get the redirection URL.
		$submission_redirection_process = apply_filters( 'everest_forms_submission_redirection_process', array(), $this->form_fields, $this->form_data );

		$is_preview_confirmation = isset( $this->form_data['settings']['preview_confirmation'] ) ? $this->form_data['settings']['preview_confirmation'] : 0;

		if ( ! empty( $submission_redirection_process ) && 'same' == $submission_redirection_process['redirect_to'] ) {
			$is_preview_confirmation = $submission_redirection_process['settings']['preview_confirmation'];
		}
		$form_state_type = isset( $this->form_data['settings']['form_state_type'] ) ? $this->form_data['settings']['form_state_type'] : 'hide';
		// show preview of form after submission.
		if ( '1' === $is_preview_confirmation && 'hide' === $form_state_type ) {
			$preview_style = isset( $this->form_data['settings']['preview_confirmation_select'] ) ? $this->form_data['settings']['preview_confirmation_select'] : 'basic';

			if ( ! empty( $submission_redirection_process ) && 'same' == $submission_redirection_process['redirect_to'] ) {
				$preview_style = $submission_redirection_process['settings']['preview_confirmation_select'];
			}
			if ( '1' === $ajax_form_submission ) {
				$preview_form_data = $this->form_data;
				$preview_form_data['settings']['ajax_form_submission'] = '1';

				$response_data['is_preview_confirmation'] = $is_preview_confirmation;
				$response_data['preview_confirmation']    = apply_filters( 'everest_forms_preview_confirmation', $preview_form_data, $this->form_fields, $preview_style );
			} else {
				do_action( 'everest_forms_preview_confirmation', $this->form_data, $this->form_fields, $preview_style );
			}
		}

		if ( defined( 'EVF_PDF_SUBMISSION_VERSION' ) && $should_allow_pdf_download ) {
			global $__everest_form_id;
			global $__everest_form_entry_id;
			$__everest_form_id       = $form_id;
			$__everest_form_entry_id = $entry_id;
		}

		// Backward compatibility for evf form templates.
		$this->form_data['settings']['redirect_to'] = '0' === $this->form_data['settings']['redirect_to'] ? 'same' : $this->form_data['settings']['redirect_to'];

		if ( '1' === $ajax_form_submission ) {
			$response_data['message']                   = $message;
			$response_data['message_display_location']  = $message_display_location;
			$response_data['form_state_type']           = $form_state_type;
			$response_data['response']                  = 'success';
			$response_data['form_id']                   = $form_id;
			$response_data['entry_id']                  = $entry_id;
			$response_data['submission_message_scroll'] = isset( $settings['submission_message_scroll'] ) ? $settings['submission_message_scroll'] : false;
			if ( defined( 'EVF_PDF_SUBMISSION_VERSION' ) && ( 'yes' === get_option( 'everest_forms_pdf_download_after_submit', 'no' ) || ( isset( $pdf_submission['everest_forms_pdf_download_after_submit'] ) && 'yes' === $pdf_submission['everest_forms_pdf_download_after_submit'] ) ) ) {
				$response_data['pdf_download'] = true;
				$pdf_download_message          = get_option( 'everest_forms_pdf_custom_download_text', '' );

				if ( isset( $pdf_submission['everest_forms_pdf_custom_download_text'] ) ) {
					$pdf_download_message = $pdf_submission['everest_forms_pdf_custom_download_text'];
				}

				if ( empty( $pdf_download_message ) ) {
					$pdf_download_message = __( 'Download your form submission in PDF format', 'everest-forms' );
				}
				$response_data['pdf_download_message'] = $pdf_download_message;
			}

			// Backward Compatibility Check.
			switch ( $settings['redirect_to'] ) {
				case '0':
					$settings['redirect_to'] = 'same';
					break;

				case '1':
					$settings['redirect_to'] = 'custom_page';
					break;

				case '2':
					$settings['redirect_to'] = 'external_url';
					break;
			}

			// Check for Submission Redirection in Ajax Submission.
			if ( empty( $submission_redirection_process ) ) {
				if ( isset( $settings['redirect_to'] ) && 'external_url' === $settings['redirect_to'] ) {
					if ( isset( $settings['enable_redirect_query_string'] ) && '1' === $settings['enable_redirect_query_string'] ) {
						parse_str( $settings['query_string'], $output );
						$query_redirect_url = array();
						foreach ( $output as $key => $value ) {
							$query_redirect_url[ $key ] = rawurlencode( apply_filters( 'everest_forms_process_smart_tags', $value, $this->form_data, $this->form_fields ) );
						}
						$redirect_url = add_query_arg( $query_redirect_url, $settings['external_url'] );
					} else {
						$redirect_url = $settings['external_url'];
					}
					$response_data['redirect_url']               = ! empty( $redirect_url ) ? esc_url_raw( $redirect_url ) : 'undefined';
					$response_data['enable_redirect_in_new_tab'] = isset( $settings['enable_redirect_in_new_tab'] ) ? $settings['enable_redirect_in_new_tab'] : false;

				} elseif ( isset( $settings['redirect_to'] ) && 'custom_page' === $settings['redirect_to'] ) {
					if ( isset( $settings['enable_redirect_query_string'] ) && '1' === $settings['enable_redirect_query_string'] ) {
						parse_str( $settings['query_string'], $output );
						$query_redirect_url = array();
						foreach ( $output as $key => $value ) {
							$query_redirect_url[ $key ] = apply_filters( 'everest_forms_process_smart_tags', $value, $this->form_data, $this->form_fields );
						}
						$redirect_url = add_query_arg( $query_redirect_url, esc_url( get_page_link( $settings['custom_page'] ) ) );
					} else {
						$redirect_url = get_page_link( $settings['custom_page'] );
					}
					$response_data['redirect_url'] = ! empty( $redirect_url ) ? esc_url_raw( $redirect_url ) : 'undefined';

				}
			} else {
				// Overiding the default setting message
				if ( 'same' === $submission_redirection_process['redirect_to'] ) {
					$form_state_type = $submission_redirection_process['settings']['form_state_type'];
					if ( 'hide' === $form_state_type ) {

						$response_data['message_display_location'] = $submission_redirection_process['settings']['message_display_location_of_hide'];
					} else {
						$response_data['message_display_location'] = $submission_redirection_process['settings']['message_display_location_of_reset'];
					}

					$response_data['message'] = $submission_redirection_process['settings']['successful_form_submission_message'];

				} else {
					$response_data['redirect_url']               = esc_url_raw( $submission_redirection_process['external_url'] );
					$response_data['enable_redirect_in_new_tab'] = isset( $settings['enable_redirect_in_new_tab'] ) ? $settings['enable_redirect_in_new_tab'] : false;
				}
			}

			// Add notice only if credit card is populated in form fields.
			if ( isset( $this->evf_notice_print ) && $this->evf_notice_print ) {
				evf_add_notice( $message, 'success' );
			}
			// $this->entry_confirmation_redirect( $this->form_data );
			$response_data = apply_filters( 'everest_forms_after_success_ajax_message', $response_data, $this->form_data, $entry );
			delete_option( 'everest_forms_overall_feedback_is_called' );
			return $response_data;
		} elseif ( ( 'same' === $this->form_data['settings']['redirect_to'] && empty( $submission_redirection_process ) ) ) {
			if ( 'hide' === $message_display_location ) {
				evf_add_notice( $message, 'success' );
			}

			$form_state_type                 = isset( $this->form_data['settings']['form_state_type'] ) ? $this->form_data['settings']['form_state_type'] : 'hide';
			$_REQUEST['evf_form_state_type'] = sanitize_text_field( $form_state_type );

		} elseif ( ! empty( $submission_redirection_process ) && 'same' == $submission_redirection_process['redirect_to'] ) {
			$form_state_type = $submission_redirection_process['settings']['form_state_type'];
			$message         = $submission_redirection_process['settings']['successful_form_submission_message'];
			if ( 'hide' === $form_state_type ) {

				$message_display_location = isset( $submission_redirection_process['settings']['message_display_location_of_hide'] ) ? $submission_redirection_process['settings']['message_display_location_of_hide'] : 'hide';
			} else {
				$message_display_location = isset( $submission_redirection_process['settings']['message_display_location_of_reset'] ) ? $submission_redirection_process['settings']['message_display_location_of_reset'] : 'top';
			}
			if ( 'hide' === $message_display_location ) {
				evf_add_notice( $message, 'success' );
			}
			// Setting message to reflect back after page refresh.
			$_REQUEST['evf_message_display_location'] = sanitize_text_field( $message_display_location );
			$_REQUEST['evf_form_state_type']          = sanitize_text_field( $form_state_type );
			$_REQUEST['evf_popup_message']            = wp_kses_post( $message );
		}
		$logger->info(
			'Everest Forms After success Message.',
			array( 'source' => 'form-submission' )
		);

		do_action( 'everest_forms_after_success_message', $this->form_data, $entry );
		delete_option( 'everest_forms_overall_feedback_is_called' );
		$this->entry_confirmation_redirect( $this->form_data );
	}

	/**
	 * Process AJAX form submission.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $posted_data Posted data.
	 */
	public function ajax_form_submission( $posted_data ) {
		add_filter( 'wp_redirect', array( $this, 'ajax_process_redirect' ), 999 );
		$process = $this->do_task( $posted_data );
		return $process;
	}

	/**
	 * Process AJAX redirect.
	 *
	 * @since 1.6.0
	 *
	 * @param string $url Redirect URL.
	 */
	public function ajax_process_redirect( $url ) {
		$form_id = isset( $_POST['everest_forms']['id'] ) ? absint( $_POST['everest_forms']['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( empty( $form_id ) ) {
			wp_send_json_error();
		}

		$response = array(
			'form_id'      => $form_id,
			'redirect_url' => $url,
		);

		$response = apply_filters( 'everest_forms_ajax_submit_redirect', $response, $form_id, $url );

		do_action( 'everest_forms_ajax_submit_completed', $form_id, $response );
		wp_send_json_success( $response );
	}

	/**
	 * Build an error submission result when the entry was recorded as a failed payment.
	 *
	 * Gateways hook `everest_forms_process_complete` and call `evf_payment_entries()` after the entry is saved,
	 * so payment meta is only reliable after those hooks run.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array|null Error payload (response => error), or null if not a failed payment entry.
	 */
	private function get_failed_payment_submission_result( $entry_id ) {
		if ( $entry_id <= 0 || ! function_exists( 'evf_get_entry' ) ) {
			return null;
		}

		wp_cache_delete( $entry_id, 'evf-entry' );
		wp_cache_delete( $entry_id, 'evf-entrymeta' );

		$entry_obj = evf_get_entry( $entry_id );
		if ( ! $entry_obj || empty( $entry_obj->meta ) || ! is_array( $entry_obj->meta ) ) {
			return null;
		}

		$pay_type = isset( $entry_obj->meta['type'] ) ? (string) $entry_obj->meta['type'] : '';
		$status   = isset( $entry_obj->meta['status'] ) ? (string) $entry_obj->meta['status'] : '';

		if ( 'payment' !== $pay_type || 0 !== strcasecmp( 'failed', $status ) ) {
			return null;
		}

		$message = apply_filters(
			'everest_forms_payment_failed_submission_message',
			__( 'Payment could not be completed. Please try again or use a different payment method.', 'everest-forms' ),
			$entry_obj,
			$this->form_data
		);

		return array(
			'response' => 'error',
			'message'  => $message,
			'error'    => array(),
			'form_id'  => isset( $this->form_data['id'] ) ? absint( $this->form_data['id'] ) : 0,
		);
	}

	/**
	 * Check the sucessful message.
	 *
	 * @param bool $status Message status.
	 * @param int  $form_id Form ID.
	 */
	public function check_success_message( $status, $form_id ) {
		if ( isset( $this->form_data['id'] ) && absint( $this->form_data['id'] ) === $form_id ) {
			return true;
		}
		return false;
	}

	/**
	 * Validate the form return hash.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hash Base64-encoded hash of form and entry IDs.
	 * @return array|false False for invalid or form id.
	 */
	public function validate_return_hash( $hash = '' ) {
		$query_args = base64_decode( $hash );

		parse_str( $query_args, $output );

		// Verify hash matches.
		if ( wp_hash( $output['form_id'] . ',' . $output['entry_id'] ) !== $output['hash'] ) {
			return false;
		}

		// Get lead and verify it is attached to the form we received with it.
		$entry = evf_get_entry( $output['entry_id'] );

		if ( empty( $entry->form_id ) ) {
			return false;
		}

		if ( $output['form_id'] !== $entry->form_id ) {
			return false;
		}

		return array(
			'form_id'  => absint( $output['form_id'] ),
			'entry_id' => absint( $output['form_id'] ),
			'fields'   => null !== $entry && isset( $entry->fields ) ? $entry->fields : array(),
		);
	}

	/**
	 * Redirects user to a page or URL specified in the form confirmation settings.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $form_data Form data and settings.
	 * @param string $hash      Base64-encoded hash of form and entry IDs.
	 */
	public function entry_confirmation_redirect( $form_data = '', $hash = '' ) {
		$_POST = array(); // Clear fields after successful form submission.

		// Process return hash.
		if ( ! empty( $hash ) ) {
			$hash_data = $this->validate_return_hash( $hash );

			if ( ! $hash_data || ! is_array( $hash_data ) ) {
				return;
			}

			$this->is_valid_hash = true;
			$this->entry_id      = absint( $hash_data['entry_id'] );
			$this->form_fields   = json_decode( $hash_data['fields'], true );
			$this->form_data     = evf()->form->get(
				absint( $hash_data['form_id'] ),
				array(
					'content_only' => true,
				)
			);
		} else {
			$this->form_data = $form_data;
		}

		$settings = $this->form_data['settings'];

		// Backward Compatibility Check.
		switch ( $settings['redirect_to'] ) {
			case '0':
				$settings['redirect_to'] = 'same';
				break;

			case '1':
				$settings['redirect_to'] = 'custom_page';
				break;

			case '2':
				$settings['redirect_to'] = 'external_url';
				break;
		}

		$submission_redirect_process = apply_filters( 'everest_forms_submission_redirection_process', array(), $this->form_fields, $this->form_data );

		if ( ! empty( $submission_redirect_process ) ) {
			$settings['redirect_to']  = $submission_redirect_process['redirect_to'];
			$settings['external_url'] = $submission_redirect_process['external_url'];
			$settings['custom_page']  = $submission_redirect_process['custom_page'];
		}

		if ( isset( $settings['redirect_to'] ) && 'custom_page' === $settings['redirect_to'] ) {
			if ( isset( $settings['enable_redirect_query_string'] ) && '1' === $settings['enable_redirect_query_string'] ) {
				parse_str( $settings['query_string'], $output );
				$query_redirect_url = array();
				foreach ( $output as $key => $value ) {
					$query_redirect_url[ $key ] = apply_filters( 'everest_forms_process_smart_tags', $value, $this->form_data, $this->form_fields );
				}
				$redirect_url = add_query_arg( $query_redirect_url, esc_url( get_page_link( $settings['custom_page'] ) ) );
			} else {
				$redirect_url = get_page_link( $settings['custom_page'] );
			}

			?>
				<script>
				var redirect = '<?php echo esc_url_raw( $redirect_url ); ?>';
				window.setTimeout( function () {
					window.location.href = redirect;
				})
				</script>
			<?php
		} elseif ( isset( $settings['redirect_to'] ) && 'external_url' === $settings['redirect_to'] ) {
			$new_tab = ! empty( $settings['enable_redirect_in_new_tab'] ); // More reliable check

			if ( isset( $settings['enable_redirect_query_string'] ) && '1' === $settings['enable_redirect_query_string'] ) {
				parse_str( $settings['query_string'], $output );
				$query_redirect_url = array();
				foreach ( $output as $key => $value ) {
					$query_redirect_url[ $key ] = rawurlencode( apply_filters( 'everest_forms_process_smart_tags', $value, $this->form_data, $this->form_fields ) );
				}
				$redirect_url = add_query_arg( $query_redirect_url, $settings['external_url'] );
			} else {
				$redirect_url = $settings['external_url'];
			}

			// Only proceed if we have a valid URL
			if ( $redirect_url && filter_var( $redirect_url, FILTER_VALIDATE_URL ) ) {
				if ( $new_tab ) {
					?>
						<script type="text/javascript">
							document.addEventListener('DOMContentLoaded', function() {
								var a = document.createElement('a');
								a.href = '<?php echo esc_url_raw( $redirect_url ); ?>';
								a.target = '_blank';
								a.rel = 'noopener noreferrer';
								a.style.display = 'none';

								document.body.appendChild(a);

								a.click();

								// Fallback if blocked
								setTimeout(function() {
									window.location.href = '<?php echo esc_url_raw( $redirect_url ); ?>';
								}, 100);
							});
						</script>
					<?php
				} else {
					?>
					<script type="text/javascript">
					setTimeout(function() {
						window.location.replace('<?php echo esc_url_raw( $redirect_url ); ?>');
					}, 100);
					</script>
					<?php
				}
			}
		}

		// Redirect if needed, to either a page or URL, after form processing.
		if ( ! empty( $this->form_data['settings']['confirmation_type'] ) && 'message' !== $this->form_data['settings']['confirmation_type'] ) {
			if ( 'redirect' === $this->form_data['settings']['confirmation_type'] ) {
				$url = apply_filters( 'everest_forms_process_smart_tags', $this->form_data['settings']['confirmation_redirect'], $this->form_data, $this->form_fields, $this->entry_id );
			}

			if ( 'page' === $this->form_data['settings']['confirmation_type'] ) {
				$url = get_permalink( (int) $this->form_data['settings']['confirmation_page'] );
			}
		}

		if ( ! empty( $this->form_data['id'] ) ) {
			$form_id = $this->form_data['id'];
		} else {
			return;
		}
		if ( isset( $settings['submission_message_scroll'] ) && $settings['submission_message_scroll'] ) {
			add_filter( 'everest_forms_success_notice_class', array( $this, 'add_scroll_notice_class' ) );
		}

		if ( ! empty( $url ) ) {
			$url = apply_filters( 'everest_forms_process_redirect_url', $url, $form_id, $this->form_fields );
			wp_safe_redirect( esc_url_raw( $url ) );
			do_action( 'everest_forms_process_redirect', $form_id );
			do_action( "everest_forms_process_redirect_{$form_id}", $form_id );
			exit;
		}
	}

	/**
	 * Add scroll notice class.
	 *
	 * @param  array $classes Notice Classes.
	 * @return array of notice classes.
	 */
	public function add_scroll_notice_class( $classes ) {
		$classes[] = 'everest-forms-submission-scroll';

		return $classes;
	}

	/**
	 * Sends entry email notifications.
	 *
	 * @param array  $fields    List of fields.
	 * @param array  $entry     Submitted form entry.
	 * @param array  $form_data Form data and settings.
	 * @param int    $entry_id  Saved entry id.
	 * @param string $context   In which context this email is sent.
	 */
	public function entry_email( $fields, $entry, $form_data, $entry_id, $context = '' ) {
		// Provide the opportunity to override via a filter.
		if ( ! apply_filters( 'everest_forms_entry_email', true, $fields, $entry, $form_data ) ) {
			return;
		}

		// Make sure we have an entry id.
		if ( empty( $this->entry_id ) ) {
			$this->entry_id = (int) $entry_id;
		}

		$fields = apply_filters( 'everest_forms_entry_email_data', $fields, $entry, $form_data );

		if ( ! isset( $form_data['settings']['email']['connection_1'] ) ) {
			$old_email_data                                 = $form_data['settings']['email'];
			$form_data['settings']['email']                 = array();
			$form_data['settings']['email']['connection_1'] = array( 'connection_name' => __( 'Admin Notification', 'everest-forms' ) );

			$email_settings = array( 'evf_to_email', 'evf_from_name', 'evf_from_email', 'evf_reply_to', 'evf_email_subject', 'enable-ai-email-prompt', 'evf_email_message_prompt', 'evf_email_message', 'attach_pdf_to_admin_email', 'show_header_in_attachment_pdf_file', 'conditional_logic_status', 'conditional_option', 'conditionals' );
			foreach ( $email_settings as $email_setting ) {
				$form_data['settings']['email']['connection_1'][ $email_setting ] = isset( $old_email_data[ $email_setting ] ) ? $old_email_data[ $email_setting ] : '';
			}
		}

		$notifications = isset( $form_data['settings']['email'] ) ? $form_data['settings']['email'] : array();

		// Connections whose attachments still need cleaning up once every notification is sent.
		$cleanup_connections = array();

		foreach ( $notifications as $connection_id => $notification ) :

			// Don't proceed if email notification is not enabled.
			if ( isset( $notification['enable_email_notification'] ) && '1' !== $notification['enable_email_notification'] ) {
				continue;
			}

			$process_email = apply_filters( 'everest_forms_entry_email_process', true, $fields, $form_data, $context, $connection_id );

			if ( ! $process_email ) {
				continue;
			}

			$email        = array();
			$evf_to_email = isset( $notification['evf_to_email'] ) ? $notification['evf_to_email'] : '';

			// Setup email properties.
			/* translators: %s - form name. */
			$email['subject']        = ! empty( $notification['evf_email_subject'] ) ? $notification['evf_email_subject'] : sprintf( esc_html__( 'New %s Entry', 'everest-forms' ), $form_data['settings']['form_title'] );
			$email['address']        = explode( ',', apply_filters( 'everest_forms_process_smart_tags', $evf_to_email, $form_data, $fields, $this->entry_id ) );
			$email['address']        = array_map( 'sanitize_email', $email['address'] );
			$email['sender_name']    = ! empty( $notification['evf_from_name'] ) ? $notification['evf_from_name'] : get_bloginfo( 'name' );
			$email['sender_address'] = ! empty( $notification['evf_from_email'] ) ? $notification['evf_from_email'] : get_option( 'admin_email' );
			$email['reply_to']       = ! empty( $notification['evf_reply_to'] ) ? $notification['evf_reply_to'] : $email['sender_address'];
			if ( ! empty( get_option( 'everest_forms_ai_api_key' ) ) ) { // phpcs:ignore
				$email['message_ai_prompt'] = ! empty( $notification['evf_email_message_prompt'] ) ? $notification['evf_email_message_prompt'] : '';
				$email['enable_ai_prompt']  = ! empty( $notification['enable_ai_email_prompt'] ) ? $notification['enable_ai_email_prompt'] : 0;
			}
			$email['message'] = ! empty( $notification['evf_email_message'] ) ? evf_string_translation( $form_data['id'], 'evf_email_message', $notification['evf_email_message'] ) : '{all_fields}';
			$email            = apply_filters( 'everest_forms_entry_email_atts', $email, $fields, $entry, $form_data );
			$attachment       = '';

			// Create new email.
			$emails = new EVF_Emails();
			$emails->__set( 'form_data', $form_data );
			$emails->__set( 'fields', $fields );
			$emails->__set( 'entry_id', $entry_id );
			$emails->__set( 'from_name', $email['sender_name'] );
			$emails->__set( 'from_address', $email['sender_address'] );
			$emails->__set( 'reply_to', $email['reply_to'] );

			/**
			 *  This filter relies on consistent data being passed for the resultant filters to function.
			 *  The third param passed for the filter, $fields, is derived from validation routine, not the DB.
			 */
			$emails->__set( 'attachments', apply_filters( 'everest_forms_email_file_attachments', $attachment, $fields, $form_data, 'entry-email', $connection_id, $entry_id ) );

			// Maybe include Cc and Bcc email addresses.
			if ( 'yes' === get_option( 'everest_forms_enable_email_copies' ) ) {
				if ( ! empty( $notification['evf_carboncopy'] ) ) {
					$emails->__set( 'cc', $notification['evf_carboncopy'] );
				}
				if ( ! empty( $notification['evf_blindcarboncopy'] ) ) {
					$emails->__set( 'bcc', $notification['evf_blindcarboncopy'] );
				}
			}

			$emails = apply_filters( 'everest_forms_entry_email_before_send', $emails );

			// Send entry email.
			foreach ( $email['address'] as $address ) {
				$emails->send( trim( $address ), $email['subject'], $email['message'], '', $connection_id );
			}

			$cleanup_connections[] = $connection_id;
			endforeach;

		/*
		 * Clean up the attachment files only after every notification has been sent.
		 * Cleaning up inside the loop deletes the uploaded files (when entry storage is
		 * disabled) and the generated CSV while later notifications are still pending,
		 * so those notifications go out without their attachments.
		 */
		if ( isset( $attachment ) ) {
			foreach ( $cleanup_connections as $cleanup_connection_id ) {
				do_action( 'everest_forms_remove_attachments_after_send_email', $attachment, $fields, $form_data, 'entry-email', $cleanup_connection_id, $entry_id );
			}
		}
	}

	/**
	 * Saves entry to database.
	 *
	 * @param array $fields    List of form fields.
	 * @param array $entry     User submitted data.
	 * @param int   $form_id   Form ID.
	 * @param array $form_data Prepared form settings.
	 * @return int
	 */
	public function entry_save( $fields, $entry, $form_id, $form_data = array() ) {
		global $wpdb;

		// Check if form has entries disabled.
		if ( isset( $form_data['settings']['disabled_entries'] ) && '1' === $form_data['settings']['disabled_entries'] ) {
			return;
		}

		// Provide the opportunity to override via a filter.
		if ( ! apply_filters( 'everest_forms_entry_save', true, $fields, $entry, $form_data ) ) {
			return;
		}

		do_action( 'everest_forms_process_entry_save', $fields, $entry, $form_id, $form_data );

		$fields                          = apply_filters( 'everest_forms_entry_save_data', $fields, $entry, $form_data );
		$browser                         = evf_get_browser();
		$user_ip                         = evf_get_ip_address();
		$user_device                     = evf_get_user_device();
		$user_agent                      = $browser['name'] . '/' . $browser['platform'] . '/' . $user_device;
		$referer                         = ! empty( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$entry_id                        = false;
		$status                          = isset( $entry['evf_spam_status'] ) ? $entry['evf_spam_status'] : 'publish';
		$admin_approval_entries          = get_option( 'everest_forms_admin_approval_entries_enable', 'no' );
		$settings                        = isset( $form_data['settings'] ) ? $form_data['settings'] : array();
		$evf_form_admin_approval_entries = isset( $settings['enable_admin_approval_entries'] ) ? $settings['enable_admin_approval_entries'] : '0';

		if ( 'yes' === $admin_approval_entries && '1' === $evf_form_admin_approval_entries ) {
			$status = 'pending';
		}

		// GDPR enhancements - If user details are disabled globally discard the IP and UA.
		if ( 'yes' === get_option( 'everest_forms_disable_user_details' ) ) {
			$user_agent = '';
			$user_ip    = '';
		}

		$entry_data = apply_filters(
			'everest_forms_entry_data',
			array(
				'form_id'         => $form_id,
				'user_id'         => get_current_user_id(),
				'user_device'     => sanitize_text_field( $user_agent ),
				'user_ip_address' => sanitize_text_field( $user_ip ),
				'status'          => $status,
				'referer'         => $referer,
				'fields'          => wp_json_encode( $fields ),
				'date_created'    => current_time( 'mysql', true ),
			),
			$entry
		);

		if ( ! $entry_data['form_id'] ) {
			return new WP_Error( 'no-form-id', __( 'No form ID was found.', 'everest-forms' ) );
		}

		// Create entry.
		$success = $wpdb->insert( $wpdb->prefix . 'evf_entries', $entry_data );

		if ( is_wp_error( $success ) || ! $success ) {
			return new WP_Error( 'could-not-create', __( 'Could not create an entry', 'everest-forms' ) );
		}

		$entry_id = $wpdb->insert_id;

		// Create meta data.
		if ( $entry_id ) {
			foreach ( $fields as $field ) {
				$field = apply_filters( 'everest_forms_entry_save_fields', $field, $form_data, $entry_id );
				// Add only whitelisted fields to entry meta.
				if ( in_array( $field['type'], array( 'html', 'title' ), true ) ) {
					continue;
				}

				// If empty file is supplied, don't store their data nor send email.
				if ( in_array( $field['type'], array( 'image-upload', 'file-upload' ), true ) ) {

					// BW compatibility for previous file uploader.
					if ( isset( $field['value']['file_url'] ) && '' === $field['value']['file_url'] ) {
						continue;
					}
				}

				// If empty label is provided for choice field, don't store their data nor send email.
				if ( in_array( $field['type'], array( 'radio', 'payment-multiple' ), true ) ) {
					if ( isset( $field['value']['label'] ) && '' === $field['value']['label'] ) {
						continue;
					}
				} elseif ( in_array( $field['type'], array( 'checkbox', 'payment-checkbox' ), true ) ) {
					if ( isset( $field['value']['label'] ) && ( empty( $field['value']['label'] ) || '' === current( $field['value']['label'] ) ) ) {
						continue;
					}
				}

				if ( isset( $field['meta_key'], $field['value'] ) && '' !== $field['value'] ) {
					if ( ! empty( $field['value'] ) && is_string( $field['value'] ) && strpos( $field['value'], '{' ) !== false ) {
						$field['value'] = apply_filters( 'everest_forms_process_smart_tags', $field['value'], $form_data, $fields, $entry_id );
					}
					$entry_metadata = array(
						'entry_id'   => $entry_id,
						'meta_key'   => sanitize_key( $field['meta_key'] ),
						'meta_value' => maybe_serialize( $field['value'] ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					);

					// Insert entry meta.
					$wpdb->insert( $wpdb->prefix . 'evf_entrymeta', $entry_metadata );
				}
			}
		}

		$this->entry_id = $entry_id;

		// Removing Entries Cache.
		wp_cache_delete( $entry_id, 'evf-entry' );
		wp_cache_delete( $entry_id, 'evf-entrymeta' );
		wp_cache_delete( $form_id, 'evf-entries-ids' );
		wp_cache_delete( $form_id, 'evf-last-entries-count' );
		wp_cache_delete( $form_id, 'evf-search-entries' );
		wp_cache_delete( EVF_Cache_Helper::get_cache_prefix( 'entries' ) . '_unread_count', 'entries' );

		do_action( 'everest_forms_complete_entry_save', $entry_id, $fields, $entry, $form_id, $form_data );

		return $this->entry_id;
	}

	/**
	 * Insert or update the slot booking data.
	 *
	 * @param int   $entry_id Entry id.
	 * @param array $fields    List of form fields.
	 * @param array $entry     User submitted data.
	 * @param int   $form_id   Form ID.
	 * @param array $form_data Prepared form settings.
	 */
	public function update_slot_booking_value( $entry_id, $fields, $entry, $form_id, $form_data ) {
		$new_slot_booking_field_meta_key_list = array();
		$time_interval                        = 0;
		foreach ( $form_data['form_fields'] as $field ) {
			if ( ( 'date-time' === $field['type'] ) && isset( $field['slot_booking_advanced'] ) && evf_string_to_bool( $field['slot_booking_advanced'] ) ) {
				$new_slot_booking_field_meta_key_list[ $field['meta-key'] ] = array(
					$field['datetime_format'],
					$field['date_format'],
					$field['date_mode'],
				);
				$time_interval = $field['time_interval'];
			}
		}

		foreach ( $fields as $key => $value ) {
			if ( array_key_exists( $value['meta_key'], $new_slot_booking_field_meta_key_list ) ) {
				$new_value       = $value['value'];
				$datetime_format = $new_slot_booking_field_meta_key_list[ $value['meta_key'] ][0];
				$date_format     = $new_slot_booking_field_meta_key_list[ $value['meta_key'] ][1];
				$mode            = $new_slot_booking_field_meta_key_list[ $value['meta_key'] ][2];
				$datetime_arr    = parse_datetime_values( $new_value, $datetime_format, $date_format, $mode, $time_interval, $entry_id );
			}
		}
		if ( ! empty( $datetime_arr ) ) {
			$get_booked_slot = get_option( 'evf_booked_slot', array() );
			$new_booked_slot = array( $form_id => $datetime_arr );

			if ( empty( $get_booked_slot ) ) {
				$all_booked_slot = maybe_serialize( $new_booked_slot );
			} else {
				$unserialized_booked_slot = evf_maybe_unserialize( $get_booked_slot );

				if ( array_key_exists( $form_id, $unserialized_booked_slot ) ) {
					$booked_slot     = $unserialized_booked_slot[ $form_id ];
					$booked_slot     = (array) $booked_slot + $datetime_arr;
					$new_booked_slot = array( $form_id => $booked_slot );
				}

				$all_booked_slot = maybe_serialize( array_replace( $unserialized_booked_slot, $new_booked_slot ) );
			}

			update_option( 'evf_booked_slot', $all_booked_slot );
		}
	}

	/**
	 * Load Previous Field Value.
	 *
	 * @param string $properties Value.
	 * @param mixed  $field Field.
	 * @param mixed  $form_data Form Data.
	 * @return $properties Properties.
	 */
	public function load_previous_field_value( $properties, $field, $form_data ) {

		if ( ! isset( $_POST['everest_forms'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $properties;
		}
		$data = ! empty( $_POST['everest_forms']['form_fields'][ $field['id'] ] ) ? wp_unslash( $_POST['everest_forms']['form_fields'][ $field['id'] ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( 'checkbox' === $field['type'] ) {
			foreach ( $field['choices'] as $key => $option_value ) {
				$selected = ! empty( $option_value['default'] ) ? $option_value['default'] : '';
				foreach ( $data  as $value ) {
					if ( $value === $option_value['label'] ) {
						$selected                                = 1;
						$properties['inputs'][ $key ]['default'] = $selected;
					}
				}
			}
		} elseif ( 'radio' === $field['type'] || 'select' === $field['type'] ) {
			foreach ( $field['choices'] as $key => $option_value ) {
				if ( $data === $option_value['label'] ) { // phpcs:ignore WordPress.Security.NonceVerification
					$selected                                = 1;
					$properties['inputs'][ $key ]['default'] = $selected;
				}
			}
		} elseif ( 'likert' === $field['type'] ) {
			if ( count( $data ) ) {
				foreach ( $data as $row => $col ) {
					foreach ( (array) $col as $col_selected ) {
						$index = sprintf( 'rows%d_columns%d', (int) $row, (int) $col_selected );
						$properties['inputs'][ $index ]['attr']['checked'] = true;
					}
				}
			}
		} elseif ( ! is_array( $data ) ) {
			$properties['inputs']['primary']['attr']['value'] = esc_attr( $data );
		}
		return $properties;
	}

	/**
	 * Check if a form entry should be validated by Akismet for potential spam.
	 *
	 * This function checks whether the Akismet plugin is installed and configured, and if the Akismet
	 * validation option is enabled for a specific form. If validation is enabled, it prepares the
	 * necessary data for the validation request and sends it to Akismet's 'comment-check' endpoint.
	 *
	 * @param array  $entry The form entry data to validate.
	 * @param string $form_id (Optional) The identifier of the form.
	 *
	 * @return bool
	 *   - true if the form entry is potentially spam according to Akismet.
	 *   - false if Akismet validation is not enabled, the plugin is not properly configured, or the entry is not considered spam.
	 */
	public function get_akismet_validate( $entry, $form_id = '' ) {

		if ( ! file_exists( WP_PLUGIN_DIR . '/akismet/akismet.php' ) ) {
			return false;
		}

		if ( ! evf_is_akismet_configured() ) {
			return false;
		}

		if ( isset( $this->form_data['settings']['akismet'] ) && '1' === $this->form_data['settings']['akismet'] ) {
			$entry_data = $this->get_entry_data_for_akismet( $this->form_data['form_fields'], $entry );

			$entry_data = apply_filters( 'evf_entry_akismet_entry_data', $entry_data, $entry, $this->form_data );

			$request = array(
				'blog'                 => get_option( 'home' ),
				'user_ip'              => evf_get_ip_address(),
				'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : null, // phpcs:ignore
				'referrer'             => wp_get_referer() ? wp_get_referer() : null,
				'permalink'            => evf_current_url(),
				'comment_type'         => 'contact-form',
				'comment_author'       => isset( $entry_data['name'] ) ? $entry_data['name'] : '',
				'comment_author_email' => isset( $entry_data['email'] ) ? $entry_data['email'] : '',
				'comment_author_url'   => isset( $entry_data['url'] ) ? $entry_data['url'] : '',
				'comment_content'      => isset( $entry_data['content'] ) ? $entry_data['content'] : '',
				'blog_lang'            => get_locale(),
				'blog_charset'         => get_bloginfo( 'charset' ),
				'honypot_field_name'   => 'everest_forms[hp]',
			);

			$request = apply_filters( 'evf_akismet_request_data', $request, $this->form_data, $entry, $form_id );

			$response = Akismet::http_post( build_query( $request ), 'comment-check' );

			return ! empty( $response ) && isset( $response[1] ) && 'true' === trim( $response[1] );
		}

		return false;
	}

	/**
	 * Check if a form entry should be validated by CleanTalk for potential spam.
	 *
	 * @since 3.2.2
	 *
	 * @param  [type] $entry The form entry data to validate.
	 * @param  string $form_id (Optional) The identifier of the form.
	 */
	public function get_clean_talk_validate( $entry, $form_id = '' ) {

		$is_cleantalk_activated = isset( $this->form_data['settings']['cleantalk'] ) ? $this->form_data['settings']['cleantalk'] : false;

		if ( ! $is_cleantalk_activated ) {
			return false;
		}

		$mark_as_spam = false;
		$logger       = evf_get_logger();

		$access_key = get_option( 'everest_forms_recaptcha_cleantalk_access_key', '' );

		if ( empty( $access_key ) ) {
			$logger->notice( 'Missing the CleanTalk Access Key', array( 'source' => 'cleantalk' ) );

			return false;
		}

		return $this->evf_is_spam_submission_clean_talk_rest_api( $entry, $access_key );
	}

	/**
	 * Get the list of field types that are allowed to be sent to Akismet.
	 *
	 * @since 1.7.6
	 *
	 * @return array List of field types that are allowed to be sent to Akismet
	 */
	private function get_field_type_allowlist_for_akisment() {

		$field_type_allowlist = array(
			'first-name',
			'last-name',
			'text',
			'textarea',
			'email',
			'phone',
			'address',
			'url',
			'wysiwyg',
		);

		/**
		 * Filters the field types that are allowed to be sent to Akismet.
		 *
		 * @since 2.4.0
		 *
		 * @param array $field_type_allowlist Field types allowed to be sent to Akismet.
		 */
		return (array) apply_filters( 'evf_forms_akismet_get_field_type_allowlist', $field_type_allowlist );
	}

	/**
	 * Get the entry data to be sent to Akismet.
	 *
	 * @since 2.4.0
	 *
	 * @param array $fields Field data for the current form.
	 * @param array $entry  Entry data for the current entry.
	 *
	 * @return array $entry_data Entry data to be sent to Akismet.
	 */
	private function get_entry_data_for_akismet( $fields, $entry ) {
		$field_type_allowlist = $this->get_field_type_allowlist_for_akisment();
		$entry_data           = array();
		$entry_content        = array();

		foreach ( $fields as $key => $field ) {
			$field_type = $field['type'];

			if ( ! in_array( $field_type, $field_type_allowlist, true ) ) {
				continue;
			}
			if ( ! isset( $entry['form_fields'][ $key ] ) ) {
				continue;
			}
			$field_content = is_array( $entry['form_fields'][ $key ] ) ? implode( ' ', $entry['form_fields'][ $key ] ) : $entry['form_fields'][ $key ];

			if ( ! isset( $entry_data[ $field_type ] ) && in_array( $field_type, array( 'first-name', 'last-name', 'email', 'url' ), true ) ) {
				if ( 'first-name' === $field_type ) {
					$entry_data['name'] = isset( $entry_data['name'] ) ? "$field_content " . $entry_data['name'] : $field_content;
					continue;
				} elseif ( 'last-name' === $field_type ) {
					$entry_data['name'] = isset( $entry_data['name'] ) ? $entry_data['name'] . " $field_content" : $field_content;
					continue;
				}
				$entry_data[ $field_type ] = $field_content;
				continue;
			}

			$entry_content[] = $field_content;
		}

		$entry_data['content'] = implode( ' ', $entry_content );

		return $entry_data;
	}

	/**
	 * Verify the token and approve the entry if the token matches.
	 *
	 * @since 2.0.9
	 */
	public static function evf_admin_approve_entry() {
		if ( ! isset( $_GET['evf_admin_approval_entry_token'] ) || empty( $_GET['evf_admin_approval_entry_token'] ) ) {
			return;
		}

		if ( current_user_can( 'edit_users' ) ) {
			global $wpdb;
			$evf_admin_approve_entry_token_raw = sanitize_text_field( wp_unslash( $_GET['evf_admin_approval_entry_token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

			$evf_admin_approval_entry_enable = get_option( 'everest_forms_admin_approval_entries_enable', 'no' );
			$evf_admin_form_id               = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$evf_admin_entry_id              = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$evf_entry_redirect_url          = admin_url() . 'admin.php?page=evf-entries&form_id=' . $evf_admin_form_id . '&view-entry=' . $evf_admin_entry_id;
			$evf_admin_entry_saved_token     = get_option( 'everest_forms_admin_entry_approval_token', array() );
			$site_name                       = get_option( 'blogname' );

			if ( 'yes' === $evf_admin_approval_entry_enable ) {
				$evf_admin_approval_entry_token = isset( $_GET['evf_admin_approval_entry_token'] ) ? sanitize_text_field( wp_unslash( $_GET['evf_admin_approval_entry_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				if ( in_array( $evf_admin_approve_entry_token_raw, $evf_admin_entry_saved_token ) ) {
					$evf_admin_approval_approved = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}evf_entries SET status = %s WHERE entry_id = %s ", 'publish', $evf_admin_entry_id ) );
					$entry                       = evf_get_entry( $evf_admin_entry_id );
					$entry_meta                  = $entry->meta;
					$first_name                  = '';
					$last_name                   = '';
					$email                       = '';
					$entry_date                  = $entry->date_created;
					$name                        = '';

					foreach ( $entry_meta as $key => $value ) {
						if ( preg_match( '/^name/', $key ) ) {
							$name = $value;
						}

						if ( preg_match( '/^first_name_/', $key ) ) {
							$first_name = $value;
						}

						if ( preg_match( '/^last_name_/', $key ) ) {
							$last_name = $value;
						}

						if ( preg_match( '/^email/', $key ) ) {
							$email = $value;
						}

						if ( '' === $name ) {
							if ( ! empty( $first_name ) && ! empty( $last_name ) ) {
								$name = $first_name . ' ' . $last_name;
							} elseif ( ! empty( $first_name ) ) {
								$name = $first_name;
							} else {
								$name = $last_name;
							}
						}

						$subject = apply_filters( 'everest_forms_entry_submission_approval_subject', esc_html__( 'Form Entry Approved', 'everest-forms' ) );
						// translators: %s is the name of the user
						$message = sprintf( __( 'Hey, %s', 'everest-forms' ), $name ) . '<br/>';
						// translators: %s is the entry_date.
						$message .= '<br/>' . sprintf( __( 'We’re pleased to inform you that your form entry submitted on %s has been successfully approved.', 'everest-forms' ), $entry_date ) . '<br/>';
						$message .= '<br/>' . __( 'Thank you for giving us your precious time.', 'everest-forms' ) . '<br/>';
						// translators: %s is the site_name.
						$message .= '<br/>' . sprintf( __( 'From %s', 'everest-forms' ), $site_name );
						// translators: %s is the message.
						$message = apply_filters( 'everest_forms_entry_approval_message', $message, $name, $entry_date, $site_name );
					}

					$email_obj = new EVF_Emails();
					$test      = $email_obj->send( $email, $subject, $message );
					wp_redirect( $evf_entry_redirect_url );
				}
			}
		}
	}

	/**
	 * Verify the token and deny the entry if the token matches.
	 *
	 * @since 2.0.9
	 */
	public static function evf_admin_deny_entry() {
		if ( ! isset( $_GET['evf_admin_denial_entry_token'] ) || empty( $_GET['evf_admin_denial_entry_token'] ) ) {
			return;
		}

		if ( current_user_can( 'edit_users' ) ) {
			global $wpdb;

			$evf_admin_approve_entry_token_raw = isset( $_GET['evf_admin_denial_entry_token'] ) ? sanitize_text_field( wp_unslash( $_GET['evf_admin_denial_entry_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$evf_admin_approval_entry_enable   = get_option( 'everest_forms_admin_approval_entries_enable', 'no' );
			$evf_admin_form_id                 = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$evf_admin_entry_id                = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$evf_entry_redirect_url            = admin_url() . 'admin.php?page=evf-entries&form_id=' . $evf_admin_form_id . '&view-entry=' . $evf_admin_entry_id;
			$evf_admin_entry_saved_token       = get_option( 'everest_forms_admin_entry_approval_token', array() );
			$site_name                         = get_option( 'blogname' );

			if ( 'yes' === $evf_admin_approval_entry_enable ) {

				$evf_admin_denial_entry_token = isset( $_GET['evf_admin_denial_entry_token'] ) ? sanitize_text_field( wp_unslash( $_GET['evf_admin_denial_entry_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
				if ( in_array( $evf_admin_approve_entry_token_raw, $evf_admin_entry_saved_token ) ) {
					$evf_admin_approval_denied = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}evf_entries SET status = %s WHERE entry_id = %s ", 'denied', $evf_admin_entry_id ) );
					$entry                     = evf_get_entry( $evf_admin_entry_id );
					$entry_meta                = $entry->meta;
					$entry_date                = $entry->date_created;
					$first_name                = '';
					$last_name                 = '';
					$email                     = '';
					$name                      = '';

					foreach ( $entry_meta as $key => $value ) {
						if ( preg_match( '/^name/', $key ) ) {
							$name = $value;
						}

						if ( preg_match( '/^first_name_/', $key ) ) {
							$first_name = $value;
						}

						if ( preg_match( '/^last_name_/', $key ) ) {
							$last_name = $value;
						}

						if ( preg_match( '/^email/', $key ) ) {
							$email = $value;
						}

						if ( '' === $name ) {
							if ( ! empty( $first_name ) && ! empty( $last_name ) ) {
								$name = $first_name . ' ' . $last_name;
							} elseif ( ! empty( $first_name ) ) {
								$name = $first_name;
							} else {
								$name = $last_name;
							}
						}

						$subject = apply_filters( 'everest_forms_entry_submission_approval_subject', esc_html__( 'Entry Submission Denied', 'everest-forms' ) );
						// translators: %s is the name of the user
						$message = sprintf( __( 'Hey, %s', 'everest-forms' ), $name ) . '<br/>';
						// translators: %s is the entry_date.
						$message .= '<br/>' . sprintf( __( 'We’re sorry to inform you that your form entry submitted on %s has been denied.', 'everest-forms' ), $entry_date ) . '<br/>';
						$message .= '<br/>' . __( 'Thank you for giving us your precious time.', 'everest-forms' ) . '<br/>';
						// translators: %s is the site_name.
						$message .= '<br/>' . sprintf( __( 'From %s', 'everest-forms' ), $site_name );
						// translators: %s is the message.
						$message = apply_filters( 'everest_forms_entry_denial_message', $message, $name, $entry_date, $site_name );

					}
					$email_obj = new EVF_Emails();
					$email_obj->send( $email, $subject, $message );
					wp_redirect( $evf_entry_redirect_url );
				}
			}
		}
	}

	/**
	 * Set the entry approval token of the entry and update it to the options table in database.
	 *
	 * @param int   $entry_id Entry ID.
	 * @param array $form_data Form field data.
	 *
	 * @since 2.0.9
	 */
	public function evf_set_approval_status( $entry_id, $form_data ) {
		$evf_admin_approval_token_list  = array();
		$form_id                        = isset( $form_data['id'] ) ? $form_id['id'] : '';
		$evf_admin_entry_enable         = get_option( 'everest_forms_admin_approval_entries_enable', 'no' );
		$evf_admin_entry_approval_token = get_option( 'everest_forms_admin_entry_approval_token', array() );
		$evf_approval_key               = 'approval_token_' . $entry_id;

		// Checks if admin approval entry is enabled.
		if ( ! isset( $evf_admin_entry_enable ) ) {
			return;
		} else {
			$token              = evf_get_random_string( 20 );
			$evf_approval_token = array(
				$evf_approval_key => $token,
			);
			$evf_new_token      = array_merge( $evf_admin_entry_approval_token, $evf_approval_token );
			update_option( 'everest_forms_admin_entry_approval_token', $evf_new_token );
		}
	}

	/**
	 * Prevents form submission before the specified duration.
	 *
	 * @param array  $errors    Form submit errors.
	 * @param object $form_data   An object containing settings for the form.
	 */
	public function form_submission_waiting_time( $errors, $form_data ) {
		$form_submission_waiting_time_enable = isset( $form_data['settings']['form_submission_min_waiting_time'] ) ? $form_data['settings']['form_submission_min_waiting_time'] : '';
		$submission_duration                 = isset( $form_data['settings']['form_submission_min_waiting_time_input'] ) ? $form_data['settings']['form_submission_min_waiting_time_input'] : '';

		if ( isset( $form_submission_waiting_time_enable ) && '1' === $form_submission_waiting_time_enable && 0 <= absint( $submission_duration ) ) {
			$evf_submission_start_time = isset( $_POST['evf_submission_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['evf_submission_start_time'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification
			$atts                      = $form_data['id'];
			$submission_time           = time() * 1000;

			if ( $submission_duration <= 0 ) {
				$submission_duration = 1;
			}
			$waiting_time = absint( $submission_time ) - absint( $evf_submission_start_time );
			$form_id      = ! empty( $form_data['id'] ) ? $form_data['id'] : 0;

			if ( absint( $submission_time ) - absint( $evf_submission_start_time ) <= absint( $submission_duration ) * 1000 ) {
				/**
				 * Filter to modify the waiting time message content.
				 *
				 * @since 3.0.2
				 */
				$form_submission_err_msg = apply_filters(
					'everest_forms_minimum_waiting_time_form_submission',
					sprintf(
						"%s <span id='evf_submission_duration' data-duration='%s'>%s</span> %s",
						esc_html__( 'Please wait', 'everest-forms' ),
						$submission_duration,
						$submission_duration,
						esc_html__( 'seconds, security checkup is being executed.', 'everest-forms' )
					)
				);

				$errors[ $form_id ]['header'] = $form_submission_err_msg;
			}

			return $errors;
		}
	}

	/**
	 * Marks the entry as spam.
	 *
	 * @since 3.0.9
	 */
	public function evf_mark_entry_spam() {
		if ( ! isset( $_GET['spam-entry'] ) ) {
			return;
		}

		// Verify nonce for security
		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'spam-entry' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'everest-forms' ) );
		}

		if ( current_user_can( 'edit_users' ) ) {
			global $wpdb;

			$evf_admin_form_id      = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
			$evf_admin_entry_id     = isset( $_GET['spam-entry'] ) ? absint( $_GET['spam-entry'] ) : 0;
			$evf_entry_redirect_url = admin_url() . 'admin.php?page=evf-entries&form_id=' . $evf_admin_form_id . '&view-entry=' . $evf_admin_entry_id;

			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}evf_entries SET status = %s WHERE entry_id = %s ", 'spam', $evf_admin_entry_id ) );
			wp_redirect( $evf_entry_redirect_url );
		}
	}

	/**
	 * Marks the entry as spam.
	 *
	 * @since 3.0.9
	 */
	public function evf_remove_entry_from_spam() {
		if ( ! isset( $_GET['unspam-entry'] ) ) {
			return;
		}

		// Verify nonce for security
		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'unspam-entry' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'everest-forms' ) );
		}

		if ( current_user_can( 'edit_users' ) ) {
			global $wpdb;

			$evf_admin_form_id      = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
			$evf_admin_entry_id     = isset( $_GET['unspam-entry'] ) ? absint( $_GET['unspam-entry'] ) : 0;
			$evf_entry_redirect_url = admin_url() . 'admin.php?page=evf-entries&form_id=' . $evf_admin_form_id . '&view-entry=' . $evf_admin_entry_id;

			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}evf_entries SET status = %s WHERE entry_id = %s ", 'publish', $evf_admin_entry_id ) );
			wp_redirect( $evf_entry_redirect_url );
		}
	}

	/**
	 * Check if the submission is spam using CleanTalk REST API.
	 *
	 * @since 3.2.2
	 */
	public function evf_is_spam_submission_clean_talk_rest_api( $entry, $access_key ) {
		$marked_as_spam = false;

		$submit_time = isset( $this->form_data['entry']['evf_form_load_time'] ) ? time() - (int) $this->form_data['entry']['evf_form_load_time'] : null;
		$event_token = isset( $this->form_data['entry']['evf_event_token'] ) ? $this->form_data['entry']['evf_event_token'] : null;

		$entry_data = $this->evf_get_entry_data_for_cleantalk( $this->form_data['form_fields'], $entry );

		$all_headers = null;

		if ( function_exists( 'apache_request_headers' ) ) {
			$all_headers = array_filter(
				apache_request_headers(),
				function ( $value, $key ) {
					return strtolower( $key ) !== 'cookie';
				},
				ARRAY_FILTER_USE_BOTH
			);
			$all_headers = json_encode( $all_headers );
			$all_headers = false !== $all_headers ? $all_headers : null;
		}

		$clean_talk_request = array(
			'method_name'     => 'check_message',
			'all_headers'     => $all_headers,
			'auth_key'        => $access_key,
			'sender_ip'       => $_SERVER['REMOTE_ADDR'],
			'sender_info'     => json_encode(
				array(
					'REFERRER'   => $_SERVER['HTTP_REFERER'],
					'USER_AGENT' => htmlspecialchars( @$_SERVER['HTTP_USER_AGENT'] ),
				)
			),
			'js_on'           => 1,
			'submit_time'     => $submit_time,
			'event_token'     => $event_token,
			'sender_nickname' => isset( $entry_data['sender_nickname'] ) ? $entry_data['sender_nickname'] : '',
			'sender_email'    => isset( $entry_data['sender_email'] ) ? $entry_data['sender_email'] : '',
			'message'         => isset( $entry_data['message'] ) ? $entry_data['message'] : '',
			'agent'           => 'wordpress-everest-forms-' . EVF_VERSION,
			'post_info'       => array(
				'comment_type' => 'everest_forms_vendor_integration__use_api',
				'post_url'     => $_SERVER['HTTP_REFERER'],
			),
		);

		$raw_response = wp_remote_post(
			'https://moderate.cleantalk.org/api2.0',
			array(
				'body'    => json_encode( $clean_talk_request ),
				'headers' => array(
					'Content-Type' => 'application/json',
				),
			)
		);
		$response     = json_decode( wp_remote_retrieve_body( $raw_response ) );

		if ( empty( $response ) ) {
			return true;
		}

		$clean_talk_passed = $response->allow == 1 && $response->spam == 0 && $response->account_status == 1;

		if ( ! $clean_talk_passed ) {
			$marked_as_spam = true;
		}

		return $marked_as_spam;
	}

	/**
	 * Remove Files Attached to the Entry of the Form.
	 *
	 * @param int $form_id Form ID to get required form data and remove files.
	 */
	public function delete_entry_files_before_form_delete( $form_id ) {
		$entries = evf_get_entries_ids( $form_id );
		if ( ! empty( $entries ) ) {
			foreach ( $entries as $entry_id ) {
				$this->delete_entry_files( $entry_id );
			}
		}
	}

	/**
	 * Delete Attachment after removing Entry.
	 *
	 * @param int $entry_id Entry ID for which file should be removed.
	 */
	public function delete_entry_files( $entry_id ) {
		$get_entry = evf_get_entry( $entry_id, 'meta' );
		if ( empty( $get_entry->meta ) ) {
			return;
		}

		// Get form configuration
		$form_id     = $get_entry->form_id;
		$form        = evf()->form->get( $form_id, array( 'content_only' => true ) );
		$form_fields = isset( $form['form_fields'] ) ? $form['form_fields'] : array();

		// Build field type lookup by meta-key
		$field_types = array();
		foreach ( $form_fields as $field_id => $field_config ) {
			if ( isset( $field_config['meta-key'] ) && ! empty( $field_config['meta-key'] ) ) {
				$field_types[ $field_config['meta-key'] ] = $field_config['type'];
			}
		}

		$uploads           = wp_upload_dir();
		$base_dir          = realpath( $uploads['basedir'] );
		$everest_forms_dir = $base_dir ? realpath( $base_dir . '/everest_forms_uploads' ) : false;

		foreach ( $get_entry->meta as $meta_key => $meta_value ) {
			if ( empty( $meta_value ) ) {
				continue;
			}

			$field_type = isset( $field_types[ $meta_key ] ) ? $field_types[ $meta_key ] : '';

			if ( preg_match( '/signature_/', $meta_key ) || $field_type === 'signature' ) {
				$this->safe_delete_file( $meta_value, $base_dir );
			} elseif ( 'file-upload' === $field_type || 'image-upload' === $field_type ) {
				$files = explode( "\n", $meta_value );
				foreach ( $files as $file ) {
					$path_from_url = wp_parse_url( $file, PHP_URL_PATH );
					if ( ! $path_from_url ) {
						continue;
					}

					$uploaded_file = $uploads['basedir'] . preg_replace(
						'/.*uploads/',
						'/everest_forms_uploads',
						$path_from_url
					);

					$this->safe_delete_file( $uploaded_file, $base_dir );
				}
			}
		}
	}

	/**
	 * Securely delete a file with path validation
	 *
	 * @param string $path File path to delete
	 * @param string $allowed_base Base directory path (must be realpath result)
	 */
	private function safe_delete_file( $path, $allowed_base ) {
		if ( ! $allowed_base || empty( $path ) ) {
			return;
		}
		$normalized_path = wp_normalize_path( $path );
		$resolved_path   = realpath( $normalized_path );
		// Validate path is within allowed directory
		if ( $resolved_path && strpos( $resolved_path, $allowed_base ) === 0 ) {
			if ( is_file( $resolved_path ) ) {
				wp_delete_file( $resolved_path );
			}
		}
	}

	/**
	 * @param array $maybe_form_fields
	 * @param array $post_entry
	 *
	 * @since 3.3.0
	 *
	 * @return array
	 */
	private function evf_get_entry_data_for_cleantalk( $maybe_form_fields, $post_entry ) {
		$entry_data                 = array(
			'sender_nickname' => array(),
			'sender_email'    => '',
			'message'         => array(),
		);
		$list_of_ct_expected_fields = array(
			'fullname',
			'first-name',
			'last-name',
			'email',
			'text',
			'textarea',
		);
		$list_of_ct_expected_fields = apply_filters( 'evf_cleantalk_expected_fields', $list_of_ct_expected_fields, $maybe_form_fields );
		foreach ( $post_entry['form_fields'] as $key => $value ) {
			if ( isset( $maybe_form_fields[ $key ]['type'] ) ) {
				switch ( $maybe_form_fields[ $key ]['type'] ) {
					case 'first-name':
					case 'last-name':
					case 'fullname':
						$entry_data['sender_nickname'][] = isset( $value ) ? $value : '';
						break;
					case 'email':
						empty( $entry_data['sender_email'] ) && $entry_data['sender_email'] = isset( $value ) ? $value : '';
						break;
					case 'text':
					case 'textarea':
						$entry_data['message'][] = isset( $value ) ? $value : '';
						break;
					default:
						if ( in_array( $maybe_form_fields[ $key ]['type'], $list_of_ct_expected_fields, true ) ) {
							$entry_data['message'][] = isset( $value ) ? $value : '';
						}
				}
			}
		}
		$entry_data = apply_filters( 'evf_entry_cleantalk_entry_data', $entry_data, $post_entry, $maybe_form_fields );
		if ( isset( $entry_data['message'] ) && is_array( $entry_data['message'] ) ) {
			$entry_data['message'] = implode( ' ', $entry_data['message'] );
		}
		if ( isset( $entry_data['sender_nickname'] ) && is_array( $entry_data['sender_nickname'] ) ) {
			$entry_data['sender_nickname'] = implode( ' ', $entry_data['sender_nickname'] );
		}
		return $entry_data;
	}
}
