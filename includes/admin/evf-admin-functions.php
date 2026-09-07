<?php

/**
 * EverestForms Admin Functions
 *
 * @package EverestForms/Admin/Functions
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Get all EverestForms screen ids.
 *
 * @return array
 */
function evf_get_screen_ids()
{
	$evf_screen_id = sanitize_title(esc_html__('Everest Forms', 'everest-forms'));
	$screen_ids    = array(
		'toplevel_page_' . $evf_screen_id,
		$evf_screen_id . '_page_evf-dashboard',
		$evf_screen_id . '_page_evf-builder',
		$evf_screen_id . '_page_evf-entries',
		$evf_screen_id . '_page_evf-settings',
		$evf_screen_id . '_page_evf-tools',
		$evf_screen_id . '_page_evf-addons',
		$evf_screen_id . '_page_evf-email-templates',
		$evf_screen_id . '_page_smart-smtp',
		$evf_screen_id . '_page_evf-smart-smtp',
		$evf_screen_id . '_page_evf-analytics',
		$evf_screen_id . '_page_evf-payment-log',
	);

	return apply_filters('everest_forms_screen_ids', $screen_ids);
}

/**
 * SmartSMTP admin URL: primary connection with Google Workspace setup highlighted.
 *
 * @since 3.3.0
 *
 * @return string
 */
function evf_get_smart_smtp_google_workspace_setup_url() {
	$url = add_query_arg(
		array(
			'page'      => 'smart-smtp',
			'evf_setup' => 'google_workspace',
		),
		admin_url( 'admin.php' )
	);

	return apply_filters( 'everest_forms_smart_smtp_google_workspace_setup_url', $url . '#/primary-connection' );
}

/**
 * Create a page and store the ID in an option.
 *
 * @param mixed  $slug         Slug for the new page.
 * @param string $option       Option name to store the page's ID.
 * @param string $page_title   (default: '') Title for the new page.
 * @param string $page_content (default: '') Content for the new page.
 * @param int    $post_parent  (default: 0) Parent for the new page.
 *
 * @return int page ID
 */
function evf_create_page($slug, $option = '', $page_title = '', $page_content = '', $post_parent = 0)
{
	global $wpdb;

	$option_value = get_option($option);
	$page_object  = get_post($option_value);

	if ($option_value > 0 && $page_object) {
		if ('page' === $page_object->post_type && ! in_array(
			$page_object->post_status,
			array(
				'pending',
				'trash',
				'future',
				'auto-draft',
			),
			true
		)) {
			// Valid page is already in place.
			return $page_object->ID;
		}
	}

	if (strlen($page_content) > 0) {
		// Search for an existing page with the specified page content (typically a shortcode).
		$valid_page_found = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status NOT IN ( 'pending', 'trash', 'future', 'auto-draft' ) AND post_content LIKE %s LIMIT 1;", "%{$page_content}%"));
	} else {
		// Search for an existing page with the specified page slug.
		$valid_page_found = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status NOT IN ( 'pending', 'trash', 'future', 'auto-draft' )  AND post_name = %s LIMIT 1;", $slug));
	}

	$valid_page_found = apply_filters('everest_forms_create_page_id', $valid_page_found, $slug, $page_content);

	if ($valid_page_found) {
		if ($option) {
			update_option($option, $valid_page_found);
		}

		return $valid_page_found;
	}

	// Search for a matching valid trashed page.
	if (strlen($page_content) > 0) {
		// Search for an existing page with the specified page content (typically a shortcode).
		$trashed_page_found = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status = 'trash' AND post_content LIKE %s LIMIT 1;", "%{$page_content}%"));
	} else {
		// Search for an existing page with the specified page slug.
		$trashed_page_found = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='page' AND post_status = 'trash' AND post_name = %s LIMIT 1;", $slug));
	}

	if ($trashed_page_found) {
		$page_id   = $trashed_page_found;
		$page_data = array(
			'ID'          => $page_id,
			'post_status' => 'publish',
		);
		wp_update_post($page_data);
	} else {
		$page_data = array(
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_author'    => 1,
			'post_name'      => $slug,
			'post_title'     => $page_title,
			'post_content'   => $page_content,
			'post_parent'    => $post_parent,
			'comment_status' => 'closed',
		);
		$page_id   = wp_insert_post($page_data);
	}

	if ($option) {
		update_option($option, $page_id);
	}

	return $page_id;
}

/**
 * Output admin fields.
 *
 * Loops though the EverestFormsoptions array and outputs each field.
 *
 * @param array[] $options Opens array to output.
 */
function everest_forms_admin_fields($options)
{
	if (! class_exists('EVF_Admin_Settings', false)) {
		include __DIR__ . '/class-evf-admin-settings.php';
	}

	EVF_Admin_Settings::output_fields($options);
}

/**
 * Update all settings which are passed.
 *
 * @param array $options Options array to output.
 * @param array $data    Optional. Data to use for saving. Defaults to $_POST.
 */
function everest_forms_update_options($options, $data = null)
{
	if (! class_exists('EVF_Admin_Settings', false)) {
		include __DIR__ . '/class-evf-admin-settings.php';
	}

	EVF_Admin_Settings::save_fields($options, $data);
}

/**
 * Get a setting from the settings API.
 *
 * @param string $option_name Option name.
 * @param mixed  $default     Default value.
 *
 * @return string
 */
function everest_forms_settings_get_option($option_name, $default = '')
{
	if (! class_exists('EVF_Admin_Settings', false)) {
		include __DIR__ . '/class-evf-admin-settings.php';
	}

	return EVF_Admin_Settings::get_option($option_name, $default);
}
/**
 * Resolve the parent path.
 * like 'settings' or 'settings['conneciton'].
 *
 * @since 3.4.0
 *
 * @param [type] $form_data The form data.
 * @param [type] $parent The parent.
 */
function resolve_parent_path($form_data, $parent)
{
	$keys = array();

	if (strpos($parent, '[') !== false) {
		// Handle 'settings[connection]' to ['settings', 'connection']
		$parent = str_replace(']', '', $parent);
		$keys   = explode('[', $parent);
	} else {
		$keys[] = $parent;
	}

	// Traverse the form_data with the resolved keys
	foreach ($keys as $key) {
		if (is_array($form_data) && isset($form_data[$key])) {
			$form_data = $form_data[$key];
		} else {
			return null;
		}
	}

	return $form_data;
}
/**
 * Outputs fields to be used on panels (settings etc).
 *
 * @param string  $option Option.
 * @param string  $panel  Panel.
 * @param string  $field  Field.
 * @param array   $form_data Form data.
 * @param string  $label  Label.
 * @param array   $args   Arguments.
 * @param boolean $echo   True to echo else return.
 *
 * @return string
 */
function everest_forms_panel_field($option, $panel, $field, $form_data, $label, $args = array(), $echo = true)
{
	// Required params.
	if (empty($option) || empty($panel) || empty($field)) {
		return '';
	}
	// Setup basic vars.
	$panel       = esc_attr($panel);
	$field       = esc_attr($field);
	$panel_id    = sanitize_html_class($panel);
	$parent      = ! empty($args['parent']) ? esc_attr($args['parent']) : '';
	$subsection  = ! empty($args['subsection']) ? esc_attr($args['subsection']) : '';
	$label       = ! empty($label) ? $label : '';
	$class       = ! empty($args['class']) ? esc_attr($args['class']) : '';
	$input_class = ! empty($args['input_class']) ? esc_attr($args['input_class']) : '';
	$default     = isset($args['default']) ? $args['default'] : '';
	$tinymce     = isset($args['tinymce']) ? $args['tinymce'] : '';
	$placeholder = ! empty($args['placeholder']) ? esc_attr($args['placeholder']) : '';
	$min_value   = ! empty($args['min_value']) ? esc_attr($args['min_value']) : '';
	$data_attr   = '';
	$output      = '';

	// Check if we should store values in a parent array.
	if (! empty($parent)) {
		$parent_data = resolve_parent_path($form_data, $parent);
		if (! empty($subsection)) {
			$field_name = sprintf('%s[%s][%s][%s]', $parent, $panel, $subsection, $field);

			if (isset($parent_data[$panel][$subsection][$field])) {
				$value = $parent_data[$panel][$subsection][$field];
			} else {
				$value = $default;
			}

			$panel_id = sanitize_html_class($panel . '-' . $subsection);
		} else {
			$field_name = sprintf('%s[%s][%s]', $parent, $panel, $field);

			if (isset($parent_data[$panel][$field])) {
				$value = $parent_data[$panel][$field];
			} else {
				$value = $default;
			}
		}
	} else {

		$field_name = sprintf('%s[%s]', $panel, $field);
		$value      = isset($form_data[$panel][$field]) ? $form_data[$panel][$field] : $default;
	}

	// Check for data attributes.
	if (! empty($args['data'])) {
		foreach ($args['data'] as $key => $val) {
			if (is_array($val)) {
				$val = wp_json_encode($val);
			}
			$data_attr .= ' data-' . $key . '=\'' . $val . '\'';
		}
	}

	// Check for the custom attributes.
	$custom_attributes = '';
	if (! empty($args['custom_attributes'])) {
		foreach ($args['custom_attributes'] as $attribute => $attribute_value) {
			if (is_array($attribute_value)) {
				$attribute_value = wp_json_encode($attribute_value);
			}
			$custom_attributes .= ' ' . $attribute . '=\'' . $attribute_value . '\'';
		}
	}

	// Determine what field type to output.
	switch ($option) {

		// Text input.
		case 'number':
		case 'text':
			$output = sprintf(
				'<input type="%s" id="everest-forms-panel-field-%s-%s" name="%s" value="%s" placeholder="%s" min=%d class="widefat %s" %s %s>',
				$option,
				sanitize_html_class($panel_id),
				sanitize_html_class($field),
				$field_name,
				esc_attr($value),
				$placeholder,
				$min_value,
				$input_class,
				$data_attr,
				$custom_attributes
			);
			break;

		// Textarea.
		case 'textarea':
			$rows   = ! empty($args['rows']) ? (int) $args['rows'] : '3';
			$output = sprintf(
				'<textarea id="everest-forms-panel-field-%s-%s" name="%s" rows="%d" placeholder="%s" class="widefat %s" %s>%s</textarea>',
				sanitize_html_class($panel_id),
				sanitize_html_class($field),
				$field_name,
				$rows,
				$placeholder,
				$input_class,
				$data_attr,
				esc_textarea($value)
			);
			break;

		// TinyMCE.
		case 'tinymce':
			$arguments                  = wp_parse_args(
				$tinymce,
				array(
					'media_buttons' => false,
					'tinymce'       => false,
				)
			);
			$arguments['textarea_name'] = $field_name;
			$arguments['teeny']         = true;
			$id                         = 'everest-forms-panel-field-' . sanitize_html_class($panel_id) . '-' . sanitize_html_class($field);
			$id                         = str_replace('-', '_', $id);
			ob_start();
			wp_editor($value, $id, $arguments);
			$output = ob_get_clean();
			if ( ! empty( $args['after'] ) ) {
				$pos = strrpos( $output, '</div>' );
				if ( false !== $pos ) {
					$output = substr( $output, 0, $pos ) . $args['after'] . substr( $output, $pos );
				}
				$args['after'] = '';
			}
			break;

		// Checkbox.
		case 'checkbox':
			$checked   = checked('1', $value, false);
			$checkbox  = sprintf(
				'<input type="hidden" name="%s" value="0" class="widefat %s" %s %s>',
				$field_name,
				$input_class,
				$checked,
				$data_attr
			);
			$checkbox .= sprintf(
				'<input type="checkbox" id="everest-forms-panel-field-%s-%s" name="%s" value="1" class="%s" %s %s>',
				sanitize_html_class($panel_id),
				sanitize_html_class($field),
				$field_name,
				$input_class,
				$checked,
				$data_attr
			);
			$output    = sprintf(
				'<label for="everest-forms-panel-field-%s-%s" class="inline">%s',
				sanitize_html_class($panel_id),
				sanitize_html_class($field),
				$checkbox . $label
			);
			if (! empty($args['tooltip'])) {
				$output .= sprintf(' <i class="dashicons dashicons-editor-help everest-forms-help-tooltip" title="%s"></i>', esc_attr($args['tooltip']));
			}
			$output .= '</label>';
			break;

		// Radio.
		case 'radio':
			$options = $args['options'];
			$x       = 1;
			$output  = '';
			foreach ($options as $key => $item) {
				if (empty($item['label'])) {
					continue;
				}
				$checked = checked($key, $value, false);
				$output .= sprintf(
					'<span class="row"><input type="radio" id="everest-forms-panel-field-%s-%s-%d" name="%s" value="%s" class="widefat %s" %s %s>',
					sanitize_html_class($panel_id),
					sanitize_html_class($field),
					$x,
					$field_name,
					$key,
					$input_class,
					$checked,
					$data_attr
				);
				$output .= sprintf(
					'<label for="everest-forms-panel-field-%s-%s-%d" class="inline">%s',
					sanitize_html_class($panel_id),
					sanitize_html_class($field),
					$x,
					$item['label']
				);
				if (! empty($item['tooltip'])) {
					$output .= sprintf(' <i class="dashicons dashicons-editor-help everest-forms-help-tooltip" title="%s"></i>', esc_attr($item['tooltip']));
				}
				$output .= '</label></span>';
				++$x;
			}
			break;

		// Select.
		case 'select':
			$is_multiple = isset($args['multiple']) && true === $args['multiple'];

			$input_class    = isset( $args['input_class'] ) ? (string) $args['input_class'] : '';
			$is_tags_select = false !== strpos( $input_class, 'form-tags-select2' );

			if ( ! $is_tags_select && empty( $args['options'] ) && empty( $args['field_map'] ) ) {
				return '';
			}

			if (true === $is_multiple && is_string($value)) {
				$value = ! empty($value) ? json_decode($value, true) : array();
			}

			if (! empty($args['field_map'])) {
				$options          = array();
				$available_fields = evf_get_form_fields($form_data, $args['field_map']);
				if (! empty($available_fields)) {
					foreach ($available_fields as $id => $available_field) {
						$lbl            = ! empty($available_field['label']) ? esc_attr($available_field['label']) : esc_html__('Field #', 'everest-forms') . $id;
						$options[$id] = $lbl;
					}
				}
				$input_class .= ' everest-forms-field-map-select';
				$data_attr   .= ' data-field-map-allowed="' . implode(' ', $args['field_map']) . '"';
				if (! empty($placeholder)) {
					$data_attr .= ' data-field-map-placeholder="' . esc_attr($placeholder) . '"';
				}
			} else {
				$options = $args['options'];
			}

			if (true === $is_multiple) {
				$multiple    = 'multiple';
				$field_name .= '[]';
			} else {
				$multiple = '';
			}

			$output = sprintf(
				'<select id="everest-forms-panel-field-%s-%s" name="%s" class="widefat %s" %s ' . $multiple . '>',
				sanitize_html_class($panel_id),
				sanitize_html_class($field),
				$field_name,
				$input_class,
				$data_attr
			);

			if (! empty($placeholder)) {
				$output .= '<option value="">' . $placeholder . '</option>';
			}

			foreach ($options as $key => $item) {
				if (true === $is_multiple && is_array($value)) {
					$output .= sprintf('<option value="%s" %s>%s</option>', esc_attr($key), selected(in_array($key, $value, true), true, false), $item);
				} else {
					$output .= sprintf('<option value="%s" %s>%s</option>', esc_attr($key), selected($key, $value, false), $item);
				}
			}
			$output .= '</select>';
			break;
		// Toggle input.
		case 'toggle':
			if ('yes' === $value) {
				$checked = checked('yes', $value, false);
			} else {
				$checked = checked('1', $value, false);
			}
			$output  = sprintf(
				'<div class="evf-toggle-section"><span class="everest-forms-toggle-form"><input type="hidden" name="%s" value="0" class="widefat %s" %s %s>',
				$field_name,
				$input_class,
				$checked,
				$data_attr
			);
			$output .= sprintf(
				'<input type="checkbox" id="everest-forms-panel-field-%s-%s" name="%s" value="1" placeholder="%s" class="widefat %s" %s %s><span class="slider round"></span></span></div>',
				sanitize_html_class($panel_id),
				sanitize_html_class($field),
				$field_name,
				$placeholder,
				$input_class,
				$data_attr,
				$checked
			);
			break;

		// Radio image inputs.
		case 'radio-image':
			$options = $args['options'];
			$x       = 1;
			$output  = '<div class="everest-forms-layout">';
			foreach ($options as $key => $item) {
				$checked = checked($key, $value, false);
				$output .= sprintf(
					'<label for="everest-forms-panel-field-%s-%s-%d" class="inline">',
					sanitize_html_class($panel_id),
					sanitize_html_class($field),
					$x
				);
				if (! empty($item['tooltip'])) {
					$output .= sprintf(' <i class="dashicons dashicons-editor-help everest-forms-help-tooltip" title="%s"></i>', esc_attr($item['tooltip']));
				}
				$output .= sprintf(
					'<input type="radio" id="everest-forms-panel-field-%s-%s-%d" name="%s" value="%s" class="widefat %s" %s %s><img src="%s">',
					sanitize_html_class($panel_id),
					sanitize_html_class($field),
					$x,
					$field_name,
					$key,
					$input_class,
					$checked,
					$data_attr,
					esc_html($item['image'])
				);
				$output .= '</label>';
				++$x;
			}
			$output .= '</div>';
			break;
		case 'image':
			if ( '' !== $value ) {
				$upload_dir = wp_upload_dir();
				$local_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $value );

				if ( file_exists( $local_path ) ) {
					$file_type = wp_check_filetype( $local_path );

					if ( 0 !== strpos( (string) $file_type['type'], 'image/' ) ) {
						$value = '';
					}
				} else {
					$response = wp_remote_head( $value );

					// Only a 2xx response is evidence about the resource; a blocked, timed out or
					// intercepted request must not wipe an already saved image.
					if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
						$content_type = wp_remote_retrieve_header( $response, 'content-type' );
						$content_type = is_array( $content_type ) ? implode( ' ', $content_type ) : (string) $content_type;

						if ( '' !== $content_type && false === strpos( $content_type, 'image/' ) ) {
							$value = '';
						}
					}
				}
			}

			$hidden_class = empty($value) ? 'everest-forms-hidden' : '';
			$alt          = isset($args['image']['alt']) ? $args['image']['alt'] : 'Unknown';
			$button_text  = isset($args['image']['button-text']) ? $args['image']['button-text'] : 'Upload Image';
			$output       = sprintf('<div class="everest-forms-custom-image-container ' . esc_attr($hidden_class) . '">');
			/* translators: %2$s : Image Alt Text. */
			$output .= sprintf('<a href="#" class="everest-forms-custom-image-delete"><i class="evf-icon evf-icon-delete"></i><img src="%1$s" alt="' . __(' %2$s', 'everest-forms') . '" class="evf-custom-image-uploader %3$s" height="100" width="auto">', esc_attr($value), esc_attr($alt), (empty($value) ? 'everest-forms-hidden' : '')); // phpcs:ignore
			$output .= sprintf('</a></div>');
			/* translators: %2$s : Upload Image button Text. */
			$output .= sprintf('<div class="everest-forms-custom-image-button"><button type="button" class="evf-custom-image-uploader-button evf-custom-image-button %1$s">' . __('%2$s', 'everest-forms') . '</button>', (empty($value) ? 'button-secondary' : 'everest-forms-hidden'), esc_html($button_text)); // phpcs:ignore
			$output .= sprintf(
				'<input type="hidden" id="everest-forms-panel-field-%s-%s" name="%s" value="%s" placeholder="%s" class="widefat %s" %s></div>',
				sanitize_html_class($panel_id),
				sanitize_html_class($field),
				$field_name,
				esc_attr($value),
				$placeholder,
				$input_class,
				$data_attr
			);
			wp_enqueue_script('jquery');
			wp_enqueue_media();
			wp_enqueue_script('evf-file-uploader');
			break;
	}

	$smarttags_class = ! empty($args['smarttags']) ? 'evf_smart_tag' : '';

	// Put the pieces together....
	$field_open  = sprintf(
		'<div id="everest-forms-panel-field-%s-%s-wrap" class="everest-forms-panel-field %s %s %s">',
		sanitize_html_class($panel_id),
		sanitize_html_class($field),
		$class,
		$smarttags_class,
		'everest-forms-panel-field-' . sanitize_html_class($option)
	);
	$field_open .= ! empty($args['before']) ? $args['before'] : '';
	if (! in_array($option, array('checkbox'), true) && ! empty($label)) {
		$field_label = sprintf(
			'<label for="everest-forms-panel-field-%s-%s">%s',
			sanitize_html_class($panel_id),
			sanitize_html_class($field),
			$label
		);
		if (! empty($args['before_tooltip'])) {
			$field_label .= $args['before_tooltip'];
		}
		if (! empty($args['tooltip'])) {
			$field_label .= sprintf(' <i class="dashicons dashicons-editor-help everest-forms-help-tooltip" title="%s"></i>', esc_attr($args['tooltip']));
		}
		if (! empty($args['after_tooltip'])) {
			$field_label .= $args['after_tooltip'];
		}
		if (! empty($args['smarttags'])) {
			$smart_tag = '';

			$type        = ! empty($args['smarttags']['type']) ? esc_attr($args['smarttags']['type']) : 'form_fields';
			$form_fields = ! empty($args['smarttags']['form_fields']) ? esc_attr($args['smarttags']['form_fields']) : '';

			$smart_tag .= '<a href="#" class="evf-toggle-smart-tag-display" data-type="' . $type . '" data-fields="' . $form_fields . '"><span class="dashicons dashicons-editor-code"></span></a>';
			$smart_tag .= '<div class="evf-smart-tag-lists" style="display: none">';
			$smart_tag .= '<div class="smart-tag-title">';
			$smart_tag .= esc_html__('Available Fields', 'everest-forms');
			$smart_tag .= '</div><ul class="evf-fields"></ul>';
			if ('all' === $type || 'other' === $type) {
				$smart_tag .= '<div class="smart-tag-title other-tag-title">';
				$smart_tag .= esc_html__('Others', 'everest-forms');
				$smart_tag .= '</div><ul class="evf-others"></ul>';
			}
			$smart_tag .= '</div>';
		} else {
			$smart_tag = '';
		}

		$field_label .= '</label>';
		if (! empty($args['after_label'])) {
			$field_label .= $args['after_label'];
		}
	} else {
		$field_label = '';
		$smart_tag   = '';
	}
	$field_close  = ! empty($args['after']) ? $args['after'] : '';
	$field_close .= '</div>';
	$output       = $field_open . $field_label . $output . $smart_tag . $field_close;

	// Wash our hands.
	if ($echo) {
		echo wp_kses($output, evf_get_allowed_html_tags('builder'));
	} else {
		return $output;
	}
}
add_action('admin_init', 'check_version_compatibility_for_form_confirmation');

/**
 * Check the version compatibility for form confirmation.
 */
function check_version_compatibility_for_form_confirmation()
{
	if (! defined('EFP_VERSION')) {
		return;
	}

	if (version_compare(EFP_VERSION, '1.9.6', '<')) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error is-dismissible">';
				echo '<p>' . sprintf(
					__('Everest Forms is up to date, but Everest Forms Pro is not. Please update Everest Forms Pro to v1.9.6 or higher to avoid compatibility issues.', 'everest-forms')
				) . '</p>';
				echo '</div>';
			}
		);
	}
}
