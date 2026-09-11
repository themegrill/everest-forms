<?php
/**
 * EverestForms Admin Settings Class
 *
 * @package EverestForms\Admin
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'EVF_Admin_Settings', false ) ) :

	/**
	 * EVF_Admin_Settings Class.
	 */
	class EVF_Admin_Settings {

		/**
		 * Setting pages.
		 *
		 * @var array
		 */
		private static $settings = array();

		/**
		 * Error messages.
		 *
		 * @var array
		 */
		private static $errors = array();

		/**
		 * Update messages.
		 *
		 * @var array
		 */
		private static $messages = array();

		/**
		 * Include the settings page classes.
		 */
		public static function get_settings_pages() {

			$settings = array();

			include_once __DIR__ . '/settings/class-evf-settings-page.php';

			$settings[] = include 'settings/class-evf-settings-general.php';
			$settings[] = include 'settings/class-evf-settings-security.php';
			$settings[] = include 'settings/class-evf-settings-email.php';
			$settings[] = include 'settings/class-evf-settings-integrations.php';
			$settings[] = include 'settings/class-evf-settings-payments.php';
			$settings[] = include 'settings/class-evf-setting-utilities.php';
			$settings[] = include 'settings/class-evf-settings-advanced.php';

			// Store settings pages before and after filter so reorder_settings_tabs
			// can reference them to check has_real_sections().
			self::$settings = $settings;

			$settings = apply_filters( 'everest_forms_get_settings_pages', $settings );

			self::$settings = $settings;

			add_filter( 'everest_forms_settings_tabs_array', array( __CLASS__, 'reorder_settings_tabs' ), 9999 );

			return $settings;
		}

		/**
		 * Reorder settings tabs and hide tabs with no real content.
		 *
		 * Removes the Utilities tab entirely when the only available sections
		 * are upsell placeholders (i.e. Pro is active but no utility addons
		 * are installed).
		 *
		 * @param array $tabs Registered settings tabs.
		 * @return array
		 */
		public static function reorder_settings_tabs( $tabs ) {

			// Hide Payments tab if it has no real (non-upsell) sections.
			if ( isset( $tabs['payment'] ) ) {
				$payments_page = null;

				foreach ( self::$settings as $page ) {
					if ( isset( $page->id ) && 'payment' === $page->id ) {
						$payments_page = $page;
						break;
					}
				}

				if ( $payments_page && method_exists( $payments_page, 'has_real_sections' ) ) {
					if ( ! $payments_page->has_real_sections() ) {
						unset( $tabs['payment'] );
					}
				}
			}

			// Hide Utilities tab if it has no real (non-upsell) sections.
			if ( isset( $tabs['utilities'] ) ) {
				$utilities_page = null;

				foreach ( self::$settings as $page ) {
					if ( isset( $page->id ) && 'utilities' === $page->id ) {
						$utilities_page = $page;
						break;
					}
				}

				if ( $utilities_page && method_exists( $utilities_page, 'has_real_sections' ) ) {
					if ( ! $utilities_page->has_real_sections() ) {
						unset( $tabs['utilities'] );
					}
				}
			}

			// Keep Advanced and License always at the end.
			$advanced = null;
			$license  = null;

			if ( isset( $tabs['advanced'] ) ) {
				$advanced = $tabs['advanced'];
				unset( $tabs['advanced'] );
			}

			if ( isset( $tabs['license'] ) ) {
				$license = $tabs['license'];
				unset( $tabs['license'] );
			}

			if ( null !== $advanced ) {
				$tabs['advanced'] = $advanced;
			}

			if ( null !== $license ) {
				$tabs['license'] = $license;
			}

			return $tabs;
		}

		/**
		 * Save the settings.
		 */
		public static function save() {
			global $current_tab;

			check_admin_referer( 'everest-forms-settings' );

			// Trigger actions.
			do_action( 'everest_forms_settings_save_' . $current_tab );
			do_action( 'everest_forms_update_options_' . $current_tab );
			do_action( 'everest_forms_update_options' );
			$flag = apply_filters( 'show_everest_forms_setting_message', true );
			if ( $flag ) {
				self::add_message( esc_html__( 'Your settings have been saved.', 'everest-forms' ) );
			}

			// Clear any unwanted data and flush rules.
			update_option( 'everest_forms_queue_flush_rewrite_rules', 'yes' );

			do_action( 'everest_forms_settings_saved' );
		}

		/**
		 * Add a message.
		 *
		 * @param string $text Message.
		 */
		public static function add_message( $text ) {
			self::$messages[] = $text;
		}

		/**
		 * Add an error.
		 *
		 * @param string $text Message.
		 */
		public static function add_error( $text ) {
			self::$errors[] = $text;
		}

		/**
		 * Output messages + errors.
		 */
		public static function show_messages() {
			if ( count( self::$errors ) > 0 ) {
				foreach ( self::$errors as $error ) {
					echo '<div id="message" class="error inline"><p><strong>' . wp_kses_post( $error ) . '</strong></p></div>';
				}
			} elseif ( count( self::$messages ) > 0 ) {
				foreach ( self::$messages as $message ) {
					echo '<div id="message" class="updated inline"><p><strong>' . esc_html( $message ) . '</strong></p></div>';
				}
			}
		}

		/**
		 * Settings page.
		 *
		 * Handles the display of the main everest-forms settings page in admin.
		 */
		public static function output() {
			global $current_section, $current_tab;

			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			do_action( 'everest_forms_settings_start' );

			wp_enqueue_script( 'jquery-confirm' );
			wp_enqueue_script( 'wp-color-picker' );

			wp_enqueue_script(
				'everest_forms_settings',
				evf()->plugin_url() . '/assets/js/admin/settings' . $suffix . '.js',
				array(
					'jquery',
					'jquery-confirm',
					'jquery-ui-datepicker',
					'jquery-ui-sortable',
					'wp-color-picker',
					'selectWoo',
				),
				evf()->version,
				true
			);

			wp_localize_script(
				'everest_forms_settings',
				'everest_forms_settings_params',
				array(
					'i18n_nav_warning' => __( 'The changes you made will be lost if you navigate away from this page.', 'everest-forms' ),
				)
			);

			// Get tabs for the settings page.
			$tabs = apply_filters( 'everest_forms_settings_tabs_array', array() );

			include __DIR__ . '/views/html-admin-settings.php';
		}

		/**
		 * Get a setting from the settings API.
		 *
		 * @param string $option_name Option name.
		 * @param mixed  $default     Default value.
		 * @return mixed
		 */
		public static function get_option( $option_name, $default = '' ) {
			if ( ! $option_name ) {
				return $default;
			}

			// Array value.
			if ( strstr( $option_name, '[' ) ) {

				parse_str( $option_name, $option_array );

				// Option name is first key.
				$option_name = current( array_keys( $option_array ) );

				// Get value.
				$option_values = get_option( $option_name, '' );

				$key = key( $option_array[ $option_name ] );

				if ( isset( $option_values[ $key ] ) ) {
					$option_value = $option_values[ $key ];
				} else {
					$option_value = null;
				}
			} else {
				// Single value.
				$option_value = get_option( $option_name, null );
			}

			if ( is_array( $option_value ) ) {
				$option_value = array_map( 'stripslashes', $option_value );
			} elseif ( ! is_null( $option_value ) ) {
				$option_value = stripslashes( $option_value );
			}

			return ( null === $option_value ) ? $default : $option_value;
		}

		/**
		 * Output admin fields.
		 *
		 * Loops though the everest-forms options array and outputs each field.
		 *
		 * @param array[] $options Opens array to output.
		 */
		public static function output_fields( $options ) {
			$settings = '';
			foreach ( $options as $value ) {
				if ( ! isset( $value['type'] ) ) {
					continue;
				}
				if ( ! isset( $value['id'] ) ) {
					$value['id'] = '';
				}
				if ( ! isset( $value['title'] ) ) {
					$value['title'] = isset( $value['name'] ) ? $value['name'] : '';
				}
				if ( ! isset( $value['class'] ) ) {
					$value['class'] = '';
				}
				if ( ! isset( $value['css'] ) ) {
					$value['css'] = '';
				}
				if ( ! isset( $value['default'] ) ) {
					$value['default'] = '';
				}
				if ( ! isset( $value['desc'] ) ) {
					$value['desc'] = '';
				}
				if ( ! isset( $value['desc_tip'] ) ) {
					$value['desc_tip'] = false;
				}
				if ( ! isset( $value['placeholder'] ) ) {
					$value['placeholder'] = '';
				}
				if ( ! isset( $value['suffix'] ) ) {
					$value['suffix'] = '';
				}
				if ( ! isset( $value['value'] ) ) {
					$value['value'] = self::get_option( $value['id'], $value['default'] );
				}

				// Custom attribute handling.
				$custom_attributes = array();

				if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
					foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
						$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
					}
				}

				// Description handling.
				$field_description = self::get_field_description( $value );
				$description       = $field_description['description'];
				$tooltip_html      = $field_description['tooltip_html'];
				// Switch based on type.
				switch ( $value['type'] ) {

					// Section Titles.
					case 'title':
						if ( ! empty( $value['title'] ) ) {
							$tabs        = apply_filters( 'everest_forms_settings_tabs_array', array() );
							$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
							$tabs_array  = array();
							if ( isset( $tabs[ $current_tab ] ) ) {
								$tabs_array[ $current_tab ] = isset( $tabs_array[ $current_tab ] ) ? $tabs_array[ $current_tab ] : array();
							}

							$class_for_title = isset( $value['id'] ) && ! empty( $value['id'] ) ? 'everest-forms-settings-title_' . $value['id'] : '';

							echo '<div class="everest-forms-options-header ' . esc_attr( $class_for_title ) . '">
							<div class="everest-forms-options-header--top">';

							if ( isset( $value['image_name'] ) && ! empty( $value['image_name'] ) ) {
								// Icon for Settings tab with different icon.
								// echo '<span class="evf-forms-options-header-header--top-icon">' . evf_file_get_contents( '/assets/images/settings-icons/' . $value['image_name'] . '.svg' ) . '</span>';
							} else {
								foreach ( $tabs_array as $icon_key => $icon_value ) {
									echo '<span class="evf-forms-options-header-header--top-icon">' . evf_file_get_contents( '/assets/images/settings-icons/' . $icon_key . '.svg' ) . '</span>'; //phpcs:ignore
								}
							}

							echo '<h3>' . esc_html( $value['title'] ) . '</h3>
						  </div>
						</div>';

						}
						if ( ! empty( $value['desc'] ) ) {
							echo wp_kses_post( wpautop( wptexturize( $value['desc'] ) ) );
						}
						echo '<div class="everest-forms-card">' . "\n\n";
						if ( ! empty( $value['id'] ) ) {
							do_action( 'everest_forms_settings_' . sanitize_title( $value['id'] ) );
						}
						break;

					// Section Ends.
					case 'sectionend':
						if ( ! empty( $value['id'] ) ) {
							do_action( 'everest_forms_settings_' . sanitize_title( $value['id'] ) . '_end' );
						}
						echo '</div>';
						if ( ! empty( $value['id'] ) ) {
							do_action( 'everest_forms_settings_' . sanitize_title( $value['id'] ) . '_after' );
						}
						break;

					// Standard text inputs and subtypes like 'number'.
					case 'text':
					case 'password':
					case 'datetime':
					case 'datetime-local':
					case 'date':
					case 'date-time':
					case 'month':
					case 'time':
					case 'week':
					case 'number':
					case 'email':
					case 'url':
					case 'tel':
						$option_value     = $value['value'];
						$visibility_class = array();

						if ( isset( $value['is_visible'] ) ) {
							$visibility_class[] = $value['is_visible'] ? 'everest-forms-visible' : 'everest-forms-hidden';
						}

						if ( empty( $option_value ) ) {
							$option_value = $value['default'];
						}

						?><div class="everest-forms-global-settings <?php echo esc_attr( implode( ' ', $visibility_class ) ); ?>">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
		<input name="<?php echo esc_attr( $value['id'] ); ?>" id="<?php echo esc_attr( $value['id'] ); ?>"
			type="<?php echo esc_attr( $value['type'] ); ?>" style="<?php echo esc_attr( $value['css'] ); ?>"
			value="<?php echo esc_attr( $option_value ); ?>" class="<?php echo esc_attr( $value['class'] ); ?>"
			placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>"
									<?php
									if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
										foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
											echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
										}
									}
									?>
						/><?php echo esc_html( $value['suffix'] ); ?> <?php echo wp_kses_post( $description ); ?>
	</div>
</div>
						<?php
						break;
					case 'image':
						$option_value = $value['value'];
						if ( empty( $option_value ) ) {
							$option_value = $value['default'];
						}
						$visibility_class = array();
						if ( isset( $value['is_visible'] ) ) {
							$visibility_class[] = $value['is_visible'] ? 'everest-forms-visible' : 'everest-forms-hidden';
						}

						$upload_text = __( 'Upload Logo', 'everest-forms' );
						$alt_text    = __( 'Header Logo', 'everest-forms' );
						if ( 'everest_forms_pdf_background_image' === $value['id'] ) {
							$upload_text = __( 'Upload Image', 'everest-forms' );
							$alt_text    = __( 'Background Image', 'everest-forms' );
						}

						?>
<div class="everest-forms-global-settings <?php echo esc_attr( implode( ' ', $visibility_class ) ); ?>">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
		<div class="evf-image-container " <?php echo empty( $option_value ) ? 'style=display:none' : ''; ?>>
			<i class="evf-icon evf-icon-delete"></i>
			<img src="<?php echo esc_attr( $option_value ); ?>" alt="<?php echo esc_attr( $alt_text ); ?>"
				class="evf-button-form-image-delete <?php echo empty( $option_value ) ? 'everest-forms-hidden' : ''; ?>"
				height="100" width="auto">
		</div>
		<button type="button" class="evf-button-for-image-upload evf-button button-secondary"
						<?php echo empty( $option_value ) ? '' : 'style="display:none"'; ?>><?php echo esc_html( $upload_text ); ?></button>
		<input name="<?php echo esc_attr( $value['id'] ); ?>" id="<?php echo esc_attr( $value['id'] ); ?>"
			value="<?php echo esc_attr( $option_value ); ?>" type="hidden">
	</div>
</div>
						<?php
						// Adding scripts.
						wp_enqueue_script( 'jquery' );
						wp_enqueue_media();
						wp_enqueue_script( 'evf-file-uploader' );
						break;

					// Color picker.
					case 'color':
						$option_value = $value['value'];

						?>
<div class="everest-forms-global-settings <?php echo esc_attr( implode( ' ', $visibility_class ) ); ?>">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div class="everest-forms-global-settings--field ">
		<input name="<?php echo esc_attr( $value['id'] ); ?>" id="<?php echo esc_attr( $value['id'] ); ?>" type="text"
			dir="ltr" style="<?php echo esc_attr( $value['css'] ); ?>" value="<?php echo esc_attr( $option_value ); ?>"
			class="<?php echo esc_attr( $value['class'] ); ?>evf-colorpicker"
			placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>"
									<?php
									if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
										foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
											echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
										}
									}
									?>
						/>&lrm; <?php echo wp_kses_post( $description ); ?>
		<div id="colorPickerDiv_<?php echo esc_attr( $value['id'] ); ?>" class="colorpickdiv"
			style="z-index: 100;background:#eee;border:1px solid #ccc;position:absolute;display:none;"></div>
	</div>
</div>
						<?php
						break;

					// Textarea.
					case 'textarea':
						$option_value = $value['value'];

						?>
<div class="everest-forms-global-settings <?php echo esc_attr( implode( ' ', $visibility_class ) ); ?>">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
						<?php echo wp_kses_post( $description ); ?>

		<textarea name="<?php echo esc_attr( $value['id'] ); ?>" id="<?php echo esc_attr( $value['id'] ); ?>"
			style="<?php echo esc_attr( $value['css'] ); ?>" class="<?php echo esc_attr( $value['class'] ); ?>"
			placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>"
									<?php
									if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
										foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
											echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
										}
									}
									?>
						><?php echo esc_textarea( $option_value ); ?></textarea>
	</div>
</div>
						<?php
						break;

					// TinyMCE.
					case 'tinymce':
						$option_value = $value['value'];
						?>
<div class="everest-forms-global-settings <?php echo esc_attr( implode( ' ', $visibility_class ) ); ?>">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
						<?php
							$arguments                                  = array(
								'media_buttons'    => false,
								'tinymce'          => isset( $value['tinymce'] ) ? (bool) $value['tinymce'] : false,
								'textarea_rows'    => get_option( 'default_post_edit_rows', 10 ),
								'editor_class'     => 'everest_forms_tinymce_class',
								'textarea_content' => true,
								'teeny'            => true,
							);
											$arguments['textarea_name'] = $value['id'];
											$arguments['teeny']         = true;
											$id                         = $value['id'];
											$content                    = html_entity_decode( $option_value );
											ob_start();
											wp_editor( $content, $id, $arguments );
											$output = ob_get_clean();
											echo wp_kses_post( $output );
											echo '<em>' . wp_kses_post( $description ) . '</em>';
							?>
	</div>
</div>

						<?php
						break;

					// Select boxes.
					case 'select':
					case 'multiselect':
						$option_value = $value['value'];

						?>
<div class="everest-forms-global-settings">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
		<select
			name="<?php echo esc_attr( $value['id'] ); ?><?php echo ( 'multiselect' === $value['type'] ) ? '[]' : ''; ?>"
			id="<?php echo esc_attr( $value['id'] ); ?>" style="<?php echo esc_attr( $value['css'] ); ?>"
			class="<?php echo esc_attr( $value['class'] ); ?>"
								<?php
								if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
									foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
										echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
									}
								}
								?>
						<?php echo 'multiselect' === $value['type'] ? 'multiple="multiple"' : ''; ?>>
						<?php
						foreach ( $value['options'] as $key => $val ) {
							?>
			<option value="<?php echo esc_attr( $key ); ?>"
										<?php

										if ( is_array( $option_value ) ) {
											selected( in_array( (string) $key, $option_value, true ), true );
										} else {
											selected( $option_value, (string) $key );
										}

										?>
							>
							<?php echo esc_html( $val ); ?></option>
							<?php
						}
						?>
		</select> <?php echo wp_kses_post( $description ); ?>
	</div>
</div>
						<?php
						break;

					// Radio inputs.
					case 'radio':
						$option_value = $value['value'];

						?>
<div class="everest-forms-global-settings">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
		<fieldset>
						<?php echo wp_kses_post( $description ); ?>
			<ul class="<?php echo esc_attr( $value['class'] ); ?>">
						<?php
						foreach ( $value['options'] as $key => $val ) {
							?>
				<li>
					<label><input name="<?php echo esc_attr( $value['id'] ); ?>"
							id="<?php echo esc_attr( $value['id'] ); ?>" value="<?php echo esc_attr( $key ); ?>"
							type="radio" style="<?php echo esc_attr( $value['css'] ); ?>"
							class="<?php echo esc_attr( $value['class'] ); ?>"
												<?php
												if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
													foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
														echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
													}
												}
												?>
							<?php checked( $key, $option_value ); ?> /> <?php echo esc_html( $val ); ?></label>
				</li>
							<?php
						}
						?>
			</ul>
		</fieldset>
	</div>
</div>
						<?php
						break;

					// Toggle input.
					case 'toggle':
						$option_value = $value['value'];

						if ( empty( $option_value ) ) {
							$option_value = $value['default'];
						}
						?>
							<div class="everest-forms-global-settings">
								<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?> <?php echo wp_kses_post( $tooltip_html ); ?></label>
								<div class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>   ">
						<?php echo wp_kses_post( $description ); ?>
		<div class="evf-toggle-section">
			<span class="everest-forms-toggle-form">
				<input type="checkbox" name="<?php echo esc_attr( $value['id'] ); ?>"
					id="<?php echo esc_attr( $value['id'] ); ?>" style="<?php echo esc_attr( $value['css'] ); ?>"
					class="<?php echo esc_attr( $value['class'] ); ?>" value="yes"
						<?php checked( 'yes', $option_value, true ); ?>>
				<span class="slider round"></span>
			</span>
		</div>
	</div>
</div>

						<?php
						break;

					// Radio image inputs.
					case 'radio-image':
						$option_value = $value['value'];
						if ( isset( $value['id'] ) && 'everest_forms_recaptcha_type' === $value['id'] ) {
							$class = 'everest-forms-recaptcha-settings';
						} else {
							$class = '';
						}

						?>
							<div class="everest-forms-global-settings">
									<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?> <?php echo wp_kses_post( $tooltip_html ); ?></label>
									<div class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?> <?php echo esc_attr( $class ); ?>">
									<fieldset>
										<ul>
							<?php
							foreach ( $value['options'] as $key => $val ) {
								?>
											<li>
											<input
													name="<?php echo esc_attr( $value['id'] ); ?>"
													value="<?php echo esc_attr( $key ); ?>"
													type="radio"
													style="<?php echo esc_attr( $value['css'] ); ?>"
													class="<?php echo esc_attr( $value['class'] ); ?>"
													id="evf-global-settings-<?php echo esc_attr( str_replace( ' ', '-', strtolower( $val['name'] ) ) ); ?>"
								<?php
								if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
									foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
										echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
									}
								}
								?>
								<?php checked( $key, $option_value ); ?>
													/>
													<label for="evf-global-settings-<?php echo esc_attr( str_replace( ' ', '-', strtolower( $val['name'] ) ) ); ?>">
													<img src="<?php echo esc_html( $val['image'] ); ?>">
								<?php echo esc_html( $val['name'] ); ?>
													</label>
											</li>
									<?php
							}
							?>
										</ul>
							<?php echo wp_kses_post( $description ); ?>
									</fieldset>
								</div>
							</div>
							<?php
						break;

					// Checkbox input.
					case 'checkbox':
						$option_value     = $value['value'];
						$visibility_class = array();

						if ( ! isset( $value['hide_if_checked'] ) ) {
							$value['hide_if_checked'] = false;
						}
						if ( ! isset( $value['show_if_checked'] ) ) {
							$value['show_if_checked'] = false;
						}
						if ( 'yes' === $value['hide_if_checked'] || 'yes' === $value['show_if_checked'] ) {
							$visibility_class[] = 'hidden_option';
						}
						if ( 'option' === $value['hide_if_checked'] ) {
							$visibility_class[] = 'hide_options_if_checked';
						}
						if ( 'option' === $value['show_if_checked'] ) {
							$visibility_class[] = 'show_options_if_checked';
						}
						if ( isset( $value['is_visible'] ) ) {
							$visibility_class[] = $value['is_visible'] ? 'everest-forms-visible' : 'everest-forms-hidden';
						}

						if ( ! isset( $value['checkboxgroup'] ) || 'start' === $value['checkboxgroup'] ) {
							?>
<div class="everest-forms-global-settings <?php echo esc_attr( implode( ' ', $visibility_class ) ); ?>">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
							<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div class="everest-forms-global-settings--field">
		<fieldset>
							<?php
						} else {
							?>
			<fieldset class="<?php echo esc_attr( implode( ' ', $visibility_class ) ); ?>">
							<?php
						}

						if ( ! empty( $value['title'] ) ) {
							?>
				<legend class="screen-reader-text"><span><?php echo esc_html( $value['title'] ); ?></span></legend>
							<?php
						}

						?>
				<label for="<?php echo esc_attr( $value['id'] ); ?>">
					<input name="<?php echo esc_attr( $value['id'] ); ?>" id="<?php echo esc_attr( $value['id'] ); ?>"
						type="checkbox"
						class="<?php echo esc_attr( isset( $value['class'] ) ? $value['class'] : '' ); ?>" value="1"
						<?php checked( $option_value, 'yes' ); ?>
						<?php
						if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
							foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
								echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
							}
						}
						?>
						/>
						<?php echo wp_kses_post( $description ); ?>
				</label> <?php echo wp_kses_post( $tooltip_html ); ?>
						<?php

						if ( ! isset( $value['checkboxgroup'] ) || 'end' === $value['checkboxgroup'] ) {
							?>
			</fieldset>
	</div>
</div>
							<?php
						} else {
							?>
</fieldset>
							<?php
						}
						break;

					// Single page selects.
					case 'single_select_page':
						$args = array(
							'name'             => $value['id'],
							'id'               => $value['id'],
							'sort_column'      => 'menu_order',
							'sort_order'       => 'ASC',
							'show_option_none' => ' ',
							'class'            => $value['class'],
							'echo'             => false,
							'selected'         => absint( $value['value'] ),
							'post_status'      => 'publish,private,draft',
						);

						if ( isset( $value['args'] ) ) {
							$args = wp_parse_args( $value['args'], $args );
						}

						?>
<div class="everest-forms-global-settings single_select_page"">
								<label><?php echo esc_html( $value['title'] ); ?> <?php echo wp_kses_post( $tooltip_html ); ?></label>
								<div class=" everest-forms-global-settings--field
	forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
						<?php echo wp_kses_post( str_replace( ' id=', " data-placeholder='" . esc_attr__( 'Select a page&hellip;', 'everest-forms' ) . "' style='" . $value['css'] . "' class='" . $value['class'] . "' id=", wp_dropdown_pages( $args ) ) ); ?>
						<?php echo wp_kses_post( $description ); ?>
</div>
</div>
						<?php
						break;

					// Days/months/years selector.
					case 'relative_date_selector':
						$periods      = array(
							'days'   => __( 'Day(s)', 'everest-forms' ),
							'weeks'  => __( 'Week(s)', 'everest-forms' ),
							'months' => __( 'Month(s)', 'everest-forms' ),
							'years'  => __( 'Year(s)', 'everest-forms' ),
						);
						$option_value = evf_parse_relative_date_option( $value['value'] );
						?>
<div class="everest-forms-global-settings">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
		<input name="<?php echo esc_attr( $value['id'] ); ?>[number]" id="<?php echo esc_attr( $value['id'] ); ?>"
			type="number" style="width: 80px;" value="<?php echo esc_attr( $option_value['number'] ); ?>"
			class="<?php echo esc_attr( $value['class'] ); ?>"
			placeholder="<?php echo esc_attr( $value['placeholder'] ); ?>" step="1" min="1"
									<?php
									if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
										foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
											echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
										}
									}
									?>
						/>&nbsp;
		<select name="<?php echo esc_attr( $value['id'] ); ?>[unit]" style="width: auto;">
						<?php
						foreach ( $periods as $value => $label ) {
							echo '<option value="' . esc_attr( $value ) . '"' . selected( $option_value['unit'], $value, false ) . '>' . esc_html( $label ) . '</option>';
						}
						?>
		</select> <?php echo ( $description ) ? wp_kses_post( $description ) : ''; ?>
	</div>
</div>
						<?php
						break;

					// For anchor tag.
					case 'link':
						?>
<div class="everest-forms-global-settings">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo isset( $value['type'] ) ? esc_attr( sanitize_title( $value['type'] ) ) : ''; ?>">
						<?php
						if ( isset( $value['buttons'] ) && is_array( $value['buttons'] ) ) {
							foreach ( $value['buttons'] as $button ) {
								?>
		<a href="<?php echo isset( $button['href'] ) ? esc_url( $button['href'] ) : ''; ?>"
			class="button <?php echo isset( $button['class'] ) ? esc_attr( $button['class'] ) : ''; ?>"
			style="<?php echo isset( $value['css'] ) ? esc_attr( $value['css'] ) : ''; ?>"
								<?php
								if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
									foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
										echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
									}
								}
								?>
										>
								<?php echo isset( $button['title'] ) ? esc_html( $button['title'] ) : ''; ?>
		</a>
								<?php
							}
						}
						?>
						<?php echo isset( $value['suffix'] ) ? esc_html( $value['suffix'] ) : ''; ?>
						<?php echo isset( $description ) ? wp_kses_post( $description ) : ''; ?>
	</div>
</div>
						<?php
						break;

					case 'input_test_button':
						$option_value     = $value['value'];
						$visibility_class = array();

						if ( isset( $value['is_visible'] ) ) {
							$visibility_class[] = $value['is_visible'] ? 'everest-forms-visible' : 'everest-forms-hidden';
						}

						if ( empty( $option_value ) ) {
							$option_value = $value['default'];
						}

						?>
<div class="everest-forms-global-settings <?php echo esc_attr( implode( ' ', $visibility_class ) ); ?>">
	<label for="<?php echo esc_attr( $value['input_id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>">
		<input name="<?php echo isset( $value['input_id'] ) ? esc_attr( $value['input_id'] ) : ''; ?>"
			id="<?php echo isset( $value['input_id'] ) ? esc_attr( $value['input_id'] ) : ''; ?>"
			type="<?php echo isset( $value['input_type'] ) ? esc_attr( $value['input_type'] ) : ''; ?>"
			style="<?php echo isset( $value['input_css'] ) ? esc_attr( $value['input_css'] ) : ''; ?>"
			value="<?php echo isset( $option_value ) ? esc_attr( $option_value ) : ''; ?>"
			class="<?php echo isset( $value['class'] ) ? esc_attr( $value['class'] ) : ''; ?>"
			placeholder="<?php echo isset( $value['placeholder'] ) ? esc_attr( $value['placeholder'] ) : ''; ?>"
									<?php
									if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
										foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
											echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
										}
									}
									?>
						/><?php echo isset( $value['suffix'] ) ? esc_html( $value['suffix'] ) : ''; ?>
						<?php echo isset( $description ) ? wp_kses_post( $description ) : ''; ?>
						<?php
						if ( isset( $value['buttons'] ) && is_array( $value['buttons'] ) ) {
							foreach ( $value['buttons'] as $button ) {
								?>
		<a href="<?php echo esc_url( $button['href'] ); ?>" class="button <?php echo esc_attr( $button['class'] ); ?>"
			style="<?php echo isset( $value['button_css'] ) ? esc_attr( $value['button_css'] ) : ''; ?>"
								<?php
								if ( ! empty( $value['custom_attributes'] ) && is_array( $value['custom_attributes'] ) ) {
									foreach ( $value['custom_attributes'] as $attribute => $attribute_value ) {
										echo esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
									}
								}
								?>
										>
								<?php echo esc_html( $button['title'] ); ?>
		</a>
								<?php
							}
						}
						?>
						<?php echo esc_html( $value['suffix'] ); ?> <?php echo wp_kses_post( $description ); ?>
	</div>
</div>
						<?php
						break;

					case 'restapi_key':
						$key                   = $value['value'];
						$key                   = $value['value'];
						$restapi_enabled       = get_option( 'everest_forms_enable_restapi', 'no' );
						$restapi_wrapper_style = ( 'yes' === $restapi_enabled ) ? '' : 'display:none;';

						?>
							<div class="everest-forms-global-settings evf-restapi-key-wrapper" style="<?php echo esc_attr( $restapi_wrapper_style ); ?>">
							<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
												<?php echo wp_kses_post( $tooltip_html ); ?></label>
							<div class="everest-forms-global-settings--field forminp-<?php echo esc_attr( sanitize_title( $value['type'] ) ); ?>"
								style="display:flex; gap:2px">
												<?php echo wp_kses_post( $description ); ?>
								<input type="text" style="" id="<?php echo esc_attr( $value['id'] ); ?>"
									name="<?php echo esc_attr( $value['id'] ); ?>" style="<?php echo esc_attr( $value['css'] ); ?> "
									class="<?php echo esc_attr( $value['class'] ); ?> help_tip tooltipstered"
									value="<?php echo esc_attr( $key ); ?>" data-tip="Copy ApiKey" data-copied="Copied!" readonly />
								<div>
												<?php
												$unique_id = isset( $value['id'] ) ? $value['id'] : '';
												if ( '' === $key ) {
													echo '<button type="button" id="' . $unique_id . '" data-id="' . $unique_id . '" class="everest-forms-btn everest-forms-btn-primary  everest-forms-generate-api-key">generate</button>';
												} else {
													echo '<button type="button" id="' . $unique_id . '" data-id="' . $unique_id . '" class="everest-forms-btn everest-forms-btn-primary  everest-forms-generate-api-key ' . $unique_id . '">regenerate</button>';
												}
												?>
								</div>
							</div>
						</div>
						<?php
						break;

					case 'display_div':
						?>
<div class="everest-forms-global-settings">
	<label for="<?php echo esc_attr( $value['id'] ); ?>"><?php echo esc_html( $value['title'] ); ?>
						<?php echo wp_kses_post( $tooltip_html ); ?></label>
	<div
		class="everest-forms-global-settings--field forminp-<?php echo isset( $value['type'] ) ? esc_attr( sanitize_title( $value['type'] ) ) : ''; ?>">
						<?php
						echo ! empty( $value['value'] ) ? esc_html( $value['value'] ) : '';
						?>
	</div>
</div>
						<?php
						break;

					case 'accordion':
						if ( ! isset( $value['items'] ) || ! is_array( $value['items'] ) ) {
							break;
						}
						?>
	<div class="everest-forms-accordion-wrapper">
						<?php foreach ( $value['items'] as $index => $item ) : ?>
							<?php
							$is_connected = false;
							if ( isset( $item['is_enabled'] ) ) {
								$is_connected = $item['is_enabled'];
							} elseif ( isset( $item['connection_check'] ) ) {
								$connection_check = $item['connection_check'];

								// Handle grouped checks (test OR live credentials).
								if ( isset( $connection_check['groups'] ) && is_array( $connection_check['groups'] ) ) {
									$mode         = isset( $connection_check['mode'] ) ? $connection_check['mode'] : 'any_group';
									$is_connected = false;

									foreach ( $connection_check['groups'] as $group_name => $group_fields ) {
										$group_complete = true;
										foreach ( $group_fields as $field_id ) {
											$field_value = get_option( $field_id, '' );
											if ( empty( $field_value ) ) {
												$group_complete = false;
												break;
											}
										}

										if ( $group_complete ) {
											$is_connected = true;
											if ( 'any_group' === $mode ) {
												break;
											}
										}
									}
								} elseif ( is_array( $connection_check ) ) {
									$is_connected = true;
									foreach ( $connection_check as $field_id ) {
										$field_value = get_option( $field_id, '' );
										if ( empty( $field_value ) ) {
											$is_connected = false;
											break;
										}
									}
								}
							}

							$item_classes = array( 'everest-forms-accordion-item' );
							if ( $is_connected ) {
								$item_classes[] = 'is-connected';
							}

							$show_status_dot = isset( $item['is_enabled'] ) || isset( $item['connection_check'] );
							?>
			<div class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>" data-accordion-index="<?php echo esc_attr( $index ); ?>">

				<div class="everest-forms-accordion-header">
							<?php if ( $show_status_dot ) : ?>
					<div class="everest-forms-accordion-status">
						<span class="toggle-switch-outer <?php echo $is_connected ? 'connected' : 'disconnected'; ?>"></span>
					</div>
					<?php endif; ?>

							<?php if ( isset( $item['icon'] ) ) : ?>
						<span class="everest-forms-accordion-icon">
							<img src="<?php echo esc_url( $item['icon'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>">
						</span>
					<?php endif; ?>

					<h3 class="everest-forms-accordion-title">
							<?php
							echo esc_html( $item['title'] );
							if ( ! empty( $item['badge'] ) && ! empty( $item['badge']['label'] ) ) {
								$badge_type = isset( $item['badge']['type'] ) ? $item['badge']['type'] : 'default';
								echo '<span class="everest-forms-accordion-badge everest-forms-accordion-badge--' . esc_attr( $badge_type ) . '">' . esc_html( $item['badge']['label'] ) . '</span>';
							}
							?>
					</h3>

					<span class="everest-forms-accordion-toggle">
						<svg width="20" height="20" viewBox="0 0 20 20" fill="none">
							<path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</span>
				</div>
				<div class="everest-forms-accordion-content">
					<div class="everest-forms-accordion-content-inner">
							<?php
							if ( ! empty( $item['desc'] ) ) {
								echo wp_kses_post( wpautop( wptexturize( $item['desc'] ) ) );
							}
							if ( isset( $item['fields'] ) && is_array( $item['fields'] ) ) {
								self::output_fields( $item['fields'] );
							}
							?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
						<?php
						break;

					default:
						do_action( 'everest_forms_admin_field_' . $value['type'], $value );
						break;
				}
			}
		}

		/**
		 * Helper function to get the formatted description and tip HTML for a
		 * given form field. Plugins can call this when implementing their own custom
		 * settings types.
		 *
		 * @param  array $value The form field value array.
		 * @return array The description and tip as a 2 element array.
		 */
		public static function get_field_description( $value ) {
			$description  = '';
			$tooltip_html = '';

			if ( true === $value['desc_tip'] ) {
				$tooltip_html = $value['desc'];
			} elseif ( ! empty( $value['desc_tip'] ) ) {
				$description  = $value['desc'];
				$tooltip_html = $value['desc_tip'];
			} elseif ( ! empty( $value['desc'] ) ) {
				$description = $value['desc'];
			}

			if ( $description && in_array( $value['type'], array( 'textarea', 'radio' ), true ) ) {
				$description = '<p style="margin-top:0">' . wp_kses_post( $description ) . '</p>';
			} elseif ( $description && in_array( $value['type'], array( 'checkbox' ), true ) ) {
				$description = wp_kses_post( $description );
			} elseif ( $description ) {
				$description = '<p class="description">' . wp_kses_post( $description ) . '</p>';
			}

			if ( $tooltip_html && in_array( $value['type'], array( 'checkbox' ), true ) ) {
				$tooltip_html = '<p class="description">' . $tooltip_html . '</p>';
			} elseif ( $tooltip_html ) {
				$tooltip_html = evf_help_tip( $tooltip_html );
			}

			return array(
				'description'  => $description,
				'tooltip_html' => $tooltip_html,
			);
		}

		/**
		 * Save admin fields.
		 *
		 * Loops though the everest-forms options array and outputs each field.
		 *
		 * @param array $options Options array to output.
		 * @param array $data    Optional. Data to use for saving. Defaults to $_POST.
		 * @return bool
		 */
		public static function save_fields( $options, $data = null ) {
			if ( is_null( $data ) ) {
				$data = $_POST; // phpcs:ignore WordPress.Security.NonceVerification
			}
			if ( empty( $data ) ) {
				return false;
			}

			$update_options   = array();
			$autoload_options = array();

			foreach ( $options as $option ) {

				if ( isset( $option['type'] ) && 'accordion' === $option['type'] ) {
					if ( isset( $option['items'] ) && is_array( $option['items'] ) ) {
						foreach ( $option['items'] as $item ) {
							if ( isset( $item['fields'] ) && is_array( $item['fields'] ) ) {
								self::save_fields( $item['fields'], $data );
							}
						}
					}
					continue;
				}

				if ( ! isset( $option['id'] ) || ! isset( $option['type'] ) || ( isset( $option['is_option'] ) && false === $option['is_option'] ) ) {
					continue;
				}

				// Get posted value.
				if ( strstr( $option['id'], '[' ) ) {
					parse_str( $option['id'], $option_name_array );
					$option_name  = current( array_keys( $option_name_array ) );
					$setting_name = key( $option_name_array[ $option_name ] );
					$raw_value    = isset( $data[ $option_name ][ $setting_name ] ) ? wp_unslash( $data[ $option_name ][ $setting_name ] ) : null;
				} else {
					$option_name  = $option['id'];
					$setting_name = '';
					$raw_value    = isset( $data[ $option['id'] ] ) ? wp_unslash( $data[ $option['id'] ] ) : null;
				}

				// Format the value based on option type.
				switch ( $option['type'] ) {
					case 'checkbox':
						$value = '1' === $raw_value || 'yes' === $raw_value ? 'yes' : 'no';
						break;
					case 'toggle':
						$value = '1' === $raw_value || 'yes' === $raw_value ? 'yes' : 'no';
						break;
					case 'textarea':
					case 'tinymce':
						$value = wp_kses_post( trim( $raw_value ) );
						break;
					case 'select':
						$allowed_values = empty( $option['options'] ) ? array() : array_map( 'strval', array_keys( $option['options'] ) );
						if ( empty( $option['default'] ) && empty( $allowed_values ) ) {
							$value = null;
							break;
						}
						$default = ( empty( $option['default'] ) ? $allowed_values[0] : $option['default'] );
						$value   = in_array( $raw_value, $allowed_values, true ) ? $raw_value : $default;
						break;
					default:
						if ( is_string( $raw_value ) ) {
							$decoded_value = html_entity_decode( html_entity_decode( $raw_value ) );
							$value         = wp_kses_post( $decoded_value );
						} else {
							$value = evf_clean( $raw_value );
						}
						break;
				}

				/**
				 * Sanitize the value of an option.
				 *
				 * @since 1.0.0
				 */
				$value = apply_filters( 'everest_forms_admin_settings_sanitize_option', $value, $option, $raw_value );

				/**
				 * Sanitize the value of an option by option name.
				 *
				 * @since 1.0.0
				 */
				$value = apply_filters( "everest_forms_admin_settings_sanitize_option_$option_name", $value, $option, $raw_value );

				if ( is_null( $value ) ) {
					continue;
				}

				// Check if option is an array and handle that differently to single values.
				if ( $option_name && $setting_name ) {
					if ( ! isset( $update_options[ $option_name ] ) ) {
						$update_options[ $option_name ] = get_option( $option_name, array() );
					}
					if ( ! is_array( $update_options[ $option_name ] ) ) {
						$update_options[ $option_name ] = array();
					}
					$update_options[ $option_name ][ $setting_name ] = $value;
				} else {
					$update_options[ $option_name ] = $value;
				}

				$autoload_options[ $option_name ] = isset( $option['autoload'] ) ? (bool) $option['autoload'] : true;

				/**
				 * Fire an action before saved.
				 *
				 * @deprecated 1.2.0 - doesn't allow manipulation of values!
				 */
				do_action( 'everest_forms_update_option', $option );
			}

			// Save all options in our array.
			foreach ( $update_options as $name => $value ) {
				update_option( $name, $value, $autoload_options[ $name ] ? 'yes' : 'no' );
			}

			return true;
		}
	}

endif;
