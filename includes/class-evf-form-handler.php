<?php
/**
 * Form handler.
 *
 * Contains a bunch of helper methods as well.
 *
 * @package EverestForms
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Form Handler class.
 */
class EVF_Form_Handler {

	/**
	 * Fetches forms
	 *
	 * @since  1.0.0
	 * @param  mixed $id   Form ID.
	 * @param  array $args Form Arguments.
	 * @return array|bool|null|WP_Post Form object.
	 */
	public function get( $id = '', $args = array() ) {
		$forms = array();
		$args  = apply_filters( 'everest_forms_get_form_args', $args );

		if ( false === $id ) {
			return false;
		}

		if ( ! isset( $args['cap'] ) && ( is_admin() && ! wp_doing_ajax() ) ) {
			$args['cap'] = array(
				'everest_forms_view_form',
				'everest_forms_create_forms',
			);
		}

		if ( ! empty( $id ) ) {
			if ( ! empty( $args['cap'] ) ) {
				$caps = (array) $args['cap'];
				$has_cap = false;
				foreach ( $caps as $cap ) {
					if ( current_user_can( $cap, $id ) ) {
						$has_cap = true;
						break;
					}
				}
				if ( ! $has_cap ) {
					return false;
				}
			}

			$the_post = get_post( absint( $id ) );

			if ( $the_post && 'everest_form' === $the_post->post_type ) {
				$forms = empty( $args['content_only'] ) ? $the_post : evf_decode( $the_post->post_content );
			}
		} else {
			// No ID provided, get multiple forms.
			$args = wp_parse_args(
				$args,
				array(
					'order' => 'DESC',
				)
			);

			$forms = $this->get_multiple( $args );
		}

		if ( empty( $forms ) ) {
			return false;
		}

		return $forms;
	}

	/**
	 * Fetch multiple forms.
	 *
	 * @since 1.7.0
	 *
	 * @param array $args Additional arguments array.
	 * @param bool  $content_only True to return post content only.
	 *
	 * @return array
	 */
	public function get_multiple( $args = array(), $content_only = false ) {
		$forms   = array();
		$user_id = get_current_user_id();
		$args    = apply_filters( 'everest_forms_get_multiple_forms_args', $args, $content_only );

		// No ID provided, get multiple forms.
		$defaults = array(
			'orderby'       => 'id',
			'order'         => 'ASC',
			'no_found_rows' => true,
			'nopaging'      => true,
			'status'        => 'publish',
			'post_status'   => 'publish',
			'numberposts'   => -1,
		);

		$args = wp_parse_args( $args, $defaults );

		$args['post_type'] = 'everest_form';

		// Can user interact, lets check the view capabilities?
		if ( current_user_can( 'everest_forms_view_forms' ) && ! current_user_can( 'everest_forms_view_others_forms' ) ) {
			$args['author'] = $user_id;
		}

		if ( ! current_user_can( 'everest_forms_view_forms' ) && current_user_can( 'everest_forms_view_others_forms' ) ) {
			$args['author__not_in'] = $user_id;
		}

		if ( ! current_user_can( 'everest_forms_view_forms' ) && ! current_user_can( 'everest_forms_view_others_forms' ) ) {
			if ( isset( $args['cap'] ) && ( 'everest_forms_view_conversational_forms' !== $args['cap'] && 'everest_forms_pro_view_landing_page' !== $args['cap'] ) ) {
				$args['post__in'] = array( 0 );
			}
		}

		// For cache lets unset the cap args.
		unset( $args['cap'] );

		// Fetch posts.
		$forms = get_posts( $args );

		if ( $content_only ) {
			$forms = array_map( array( $this, 'prepare_post_content' ), $forms );
		}

		return $forms;
	}

	/**
	 * Prepares post content.
	 *
	 * @param object $post Post object.
	 */
	public function prepare_post_content( $post ) {
		return ! empty( $post->post_content ) ? evf_decode( $post->post_content ) : false;
	}

	/**
	 * Delete forms.
	 *
	 * @since  1.0.0
	 * @param  array $ids Form IDs.
	 * @return boolean
	 */
	public function delete( $ids = array() ) {
		if ( ! is_array( $ids ) ) {
			$ids = array( $ids );
		}

		$ids = array_map( 'absint', $ids );

		foreach ( $ids as $id ) {

			// Check for permissions.
			if ( ! current_user_can( 'everest_forms_delete', $id ) ) {
				return false;
			}

			$form = wp_delete_post( $id, true );

			if ( ! $form ) {
				return false;
			}
		}

		do_action( 'everest_forms_delete_form', $ids );

		return true;
	}

	/**
	 * Create new form.
	 *
	 * @since  1.0.0
	 * @param  string $title    Form title.
	 * @param  string $template Form template.
	 * @param  array  $args     Form Arguments.
	 * @param  array  $data     Additional data.
	 * @return int|bool Form ID on successful creation else false.
	 */
	public function create( $title = '', $template = 'blank', $args = array(), $data = array() ) {
		if ( empty( $title ) || ! current_user_can( 'everest_forms_create_forms' ) ) {
			return false;
		}

		$args         = apply_filters( 'everest_forms_create_form_args', $args, $data );
		$form_style   = array();
		$style_needed = false;
		$form_content = array(
			'form_field_id' => '1',
			'settings'      => array(
				'form_title' => sanitize_text_field( $title ),
				'form_desc'  => '',
			),
		);

		// Prevent content filters from corrupting JSON in post_content.
		$has_kses = ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		if ( $has_kses ) {
			kses_remove_filters();
		}
		$has_targeted_link_rel_filters = ( false !== has_filter( 'content_save_pre', 'wp_targeted_link_rel' ) );
		if ( $has_targeted_link_rel_filters ) {
			wp_remove_targeted_link_rel_filters();
		}

		// Create a form.
		$form_id = wp_insert_post(
			array(
				'post_title'   => esc_html( $title ),
				'post_status'  => 'publish',
				'post_type'    => 'everest_form',
				'post_content' => '{}',
			)
		);

		$templates = EVF_Admin_Form_Templates::get_template_data();
		$templates = is_array( $templates ) ? $templates[0]->templates : array();
		if ( ! empty( $templates ) ) {
			foreach ( $templates as $template_data ) {
				if ( $template_data->slug === $template && 'blank' !== $template_data->slug ) {
					$form_content = json_decode( base64_decode( $template_data->settings ), true );
					if ( isset( $template_data->styles ) ) {
						$style_needed           = true;
						$form_style[ $form_id ] = json_decode( base64_decode( $template_data->styles ), true );
					}
				}
			}
		}

		if ( $form_id ) {
			$form_content['id']                      = $form_id;
			$form_content['settings']['form_title']  = $title;
			$form_content['imported_form_templates'] = $template;
			// Mark as a fresh builder form (not yet saved in the editor). Cleared on first builder save.
			// Add-ons may read this from encoded post_content to skip legacy setup UI for brand-new forms.
			$form_content['is_new_form']             = true;

			$form_data = wp_parse_args(
				$args,
				array(
					'ID'           => $form_id,
					'post_title'   => esc_html( $title ),
					'post_content' => evf_encode( array_merge( array( 'id' => $form_id ), $form_content ) ),
				)
			);

			wp_update_post( $form_data );

			if ( ! empty( $form_style ) ) {
				update_option( 'everest_forms_styles', $form_style );
			}
		}

		// Restore removed content filters.
		if ( $has_kses ) {
			kses_init_filters();
		}
		if ( $has_targeted_link_rel_filters ) {
			wp_init_targeted_link_rel_filters();
		}

		do_action( 'everest_forms_create_form', $form_id, $form_data, $data, $style_needed );

		return $form_id;
	}

	/**
	 * Updates form
	 *
	 * @since    1.0.0
	 *
	 * @param string|int $form_id Form ID.
	 * @param array      $data    Data retrieved from $_POST and processed.
	 * @param array      $args    Empty by default, may have custom data not intended to be saved.
	 *
	 * @return   mixed
	 * @internal param string $title
	 */
	public function update( $form_id = '', $data = array(), $args = array() ) {
		if ( empty( $data ) ) {
			return false;
		}

		if ( empty( $form_id ) ) {
			$form_id = $data['form_id'];
		}

		if ( ! isset( $args['cap'] ) ) {
			$args['cap'] = 'everest_forms_edit_form';
		}

		// Check for permissions.
		if ( ! empty( $args['cap'] ) && ! current_user_can( $args['cap'], $form_id ) ) {
			return false;
		}

		$data = wp_unslash( $data );

		if ( ! empty( $data['settings']['form_title'] ) ) {
			$title = $data['settings']['form_title'];
		} else {
			$title = get_the_title( $form_id );
		}

		if ( ! empty( $data['settings']['form_desc'] ) ) {
			$desc = $data['settings']['form_desc'];
		} else {
			$desc = '';
		}

		$data['form_field_id'] = ! empty( $data['form_field_id'] ) ? absint( $data['form_field_id'] ) : '0';

		// This filter can destroy the JSON when messing with HTML.
		remove_filter( 'content_save_pre', 'balanceTags', 50 );

		// Don't allow tags for users who do not have appropriate cap.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$data = map_deep( $data, 'wp_strip_all_tags' );
		}

		// Prevent content filters from corrupting JSON in post_content.
		$has_kses = ( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
		if ( $has_kses ) {
			kses_remove_filters();
		}
		$has_targeted_link_rel_filters = ( false !== has_filter( 'content_save_pre', 'wp_targeted_link_rel' ) );
		if ( $has_targeted_link_rel_filters ) {
			wp_remove_targeted_link_rel_filters();
		}

		$evf_form_data = evf()->form->get( $form_id, array( 'content_only' => true ) );

		if ( isset( $evf_form_data['meta']['entry_columns'] ) && ! isset( $data['meta']['entry_columns'] ) ) {
			$data['meta']['entry_columns'] = $evf_form_data['meta']['entry_columns'];
		}

		$form    = array(
			'ID'           => $form_id,
			'post_title'   => esc_html( $title ),
			'post_excerpt' => $desc,
			'post_content' => evf_encode( $data ),
		);
		$form    = apply_filters( 'everest_forms_save_form_args', $form, $data, $args );
		$form_id = wp_update_post( $form );

		// Import form styles if present.
		$style_needed = false;
		if ( ! empty( $data['form_styles'] ) ) {
			$style_needed            = true;
			$form_styles             = get_option( 'everest_forms_styles', array() );
			$form_styles[ $form_id ] = evf_decode( $data['form_styles'] );

			// Update forms styles.
			update_option( 'everest_forms_styles', $form_styles );
		}

		// Restore removed content filters.
		if ( $has_kses ) {
			kses_init_filters();
		}
		if ( $has_targeted_link_rel_filters ) {
			wp_init_targeted_link_rel_filters();
		}

		do_action( 'everest_forms_save_form', $form_id, $form, array(), $style_needed );

		return $form_id;
	}

	/**
	 * Duplicate forms.
	 *
	 * @since  1.0.0
	 *
	 * @param array $ids Form IDs to duplicate.
	 *
	 * @return boolean
	 */
	public function duplicate( $ids = array() ) {
		// Check for permissions.
		if ( ! current_user_can( 'everest_forms_create_forms' ) ) {
			return false;
		}

		if ( ! is_array( $ids ) ) {
			$ids = array( $ids );
		}

		$ids = array_map( 'absint', $ids );

		foreach ( $ids as $id ) {

			// Get original entry.
			$form = get_post( $id );

			if ( ! current_user_can( 'everest_forms_view_form', $id ) ) {
				return false;
			}

			// Confirm form exists.
			if ( ! $form || empty( $form ) ) {
				return false;
			}

			// Get the form data.
			$new_form_data = evf_decode( $form->post_content );

			// Get the form styles.
			$form_styles = get_option( 'everest_forms_styles', array() );
			if ( ! empty( $form_styles[ $id ] ) ) {
				$new_form_data['form_styles'] = wp_json_encode( $form_styles[ $id ] );
			}

			// Remove form ID from title if present.
			$new_form_data['settings']['form_title'] = str_replace( '(ID #' . absint( $id ) . ')', '', $new_form_data['settings']['form_title'] );

			// Create the duplicate form.
			$new_form    = array(
				'post_author'  => $form->post_author,
				'post_content' => evf_encode( $new_form_data ),
				'post_excerpt' => $form->post_excerpt,
				'post_status'  => $form->post_status,
				'post_title'   => $new_form_data['settings']['form_title'],
				'post_type'    => $form->post_type,
			);
			$new_form_id = wp_insert_post( $new_form );

			if ( ! $new_form_id || is_wp_error( $new_form_id ) ) {
				return false;
			}

			// Set new form name.
			$new_form_data['settings']['form_title'] .= ' (ID #' . absint( $new_form_id ) . ')';

			// Set new form ID.
			$new_form_data['id'] = absint( $new_form_id );
			// Duplicates are new posts; treat like template creation until the builder saves once.
			$new_form_data['is_new_form'] = true;

			// Update new duplicate form.
			$new_form_id = $this->update( $new_form_id, $new_form_data );

			if ( ! $new_form_id || is_wp_error( $new_form_id ) ) {
				return false;
			}

			$form_styles = get_option( 'everest_forms_styles', array() );
			if ( isset( $form_styles[ $id ] ) ) {
				$form_styles[ $new_form_id ] = $form_styles[ $id ];

				// A legacy style record needs a static per-form CSS file that duplicating never
				// generates, so it would render unstyled. Migrate to v2 instead, which compiles
				// inline (migrate_record() is idempotent, so an already-v2 record is untouched).
				if ( class_exists( '\EverestForms\Addons\StyleCustomizer\V2\Engine' )
					&& \EverestForms\Addons\StyleCustomizer\V2\Engine::enabled()
					&& ! \EverestForms\Addons\StyleCustomizer\V2\Engine::is_v2_record( $form_styles[ $new_form_id ] ) ) {
					$legacy_style                 = $form_styles[ $new_form_id ];
					$migrated                     = \EverestForms\Addons\StyleCustomizer\V2\Migrator::migrate_record( $legacy_style );
					$clean_style                  = \EverestForms\Addons\StyleCustomizer\V2\Sanitizer::sanitize_record( $migrated );
					// sanitize_record() only ever DERIVES a free-tier (palette-driven) token from a
					// registered free palette id (see EVF-2708); a source form whose legacy custom
					// colour palette matches none of them would otherwise lose that colour on
					// duplicate — restore it from the same legacy blob, exactly like a real save does.
					$form_styles[ $new_form_id ] = \EverestForms\Addons\StyleCustomizer\V2\Sanitizer::preserve_stale_pro_tokens( $clean_style, $legacy_style );
				}

				update_option( 'everest_forms_styles', $form_styles );
			}

			// duplicate() never copies post meta, so the "Apply Theme Style" toggle would
			// otherwise silently reset to its default instead of mirroring the source form.
			$theme_style_meta = get_post_meta( $id, 'everest_forms_enable_theme_style', true );
			if ( '' !== $theme_style_meta ) {
				update_post_meta( $new_form_id, 'everest_forms_enable_theme_style', $theme_style_meta );
			}

			return $new_form_id;
		}

		return true;
	}

	/**
	 * Get private meta information for a form.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $form_id Form ID.
	 * @param string $field   Field.
	 * @param array  $args    Additional arguments.
	 *
	 * @return false|array
	 */
	public function get_meta( $form_id, $field = '', $args = array() ) {
		if ( empty( $form_id ) ) {
			return false;
		}

		if ( isset( $args['cap'] ) ) {
			$defaults['cap'] = $args['cap'];
		}

		$data = $this->get(
			$form_id,
			array(
				'content_only' => true,
			)
		);

		if ( isset( $data['meta'] ) ) {
			if ( empty( $field ) ) {
				return $data['meta'];
			} elseif ( isset( $data['meta'][ $field ] ) ) {
				return $data['meta'][ $field ];
			}
		}

		return false;
	}

	/**
	 * Get the next available field ID and increment by one.
	 *
	 * @since  1.0.0
	 * @param  int $form_id  Form ID.
	 * @return mixed int or false
	 */
	public function field_unique_key( $form_id ) {
		if ( ! current_user_can( 'everest_forms_edit_form', $form_id ) ) {
			return false;
		}

		if ( empty( $form_id ) ) {
			return false;
		}

		$form = $this->get(
			$form_id,
			array(
				'content_only' => true,
			)
		);

		if ( ! empty( $form['form_field_id'] ) ) {
			$form_field_id = absint( $form['form_field_id'] );
			$form['form_field_id'] ++;
		} else {
			$form_field_id         = '0';
			$form['form_field_id'] = '1';
		}

		$this->update( $form_id, $form );

		$field_id = evf_get_random_string() . '-' . $form_field_id;

		return $field_id;
	}

	/**
	 * Get private meta information for a form field.
	 *
	 * @since  1.0.0
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $field_id Field ID.
	 * @param array  $args     Additional arguments.
	 *
	 * @return array|bool
	 */
	public function get_field( $form_id, $field_id = '', $args = array() ) {
		if ( empty( $form_id ) ) {
			return false;
		}

		if ( isset( $args['cap'] ) ) {
			$defaults['cap'] = $args['cap'];
		}

		$data = $this->get(
			$form_id,
			array(
				'content_only' => true,
			)
		);

		return isset( $data['form_fields'][ $field_id ] ) ? $data['form_fields'][ $field_id ] : false;
	}

	/**
	 * Get private meta information for a form field.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $field_id Field.
	 * @param array  $args     Additional arguments.
	 *
	 * @return bool
	 */
	public function get_field_meta( $form_id, $field_id = '', $args = array() ) {
		$field = $this->get_field( $form_id, $field_id, $args );
		if ( ! $field ) {
			return false;
		}

		return isset( $field['meta'] ) ? $field['meta'] : false;
	}
}
