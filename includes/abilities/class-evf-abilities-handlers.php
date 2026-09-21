<?php
/**
 * Ability handler callbacks.
 *
 * @package EverestForms\Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static handlers for each registered ability.
 *
 * Every callback receives the validated input array and returns a plain
 * array/scalar on success or a WP_Error on failure.
 */
class EVF_Abilities_Handlers {

	/* ------------------------------------------------------------------
	 * Permission helpers
	 *
	 * The per-object checks below (singular method names, taking $input)
	 * pass the relevant form_id/entry_id straight to current_user_can() so
	 * WordPress's map_meta_cap (EVF_Install::filter_map_meta_cap) can apply
	 * the plugin's own-vs-others ownership resolution. Checking a bare
	 * plural capability (e.g. 'everest_forms_view_entries') with no object
	 * id skips that resolution entirely, so it must never be used to gate
	 * an ability that targets one specific object.
	 *
	 * The plural, no-arg methods remain for abilities with no single
	 * object to check against a site-wide listing/count that the handler
	 * itself scopes by author, or a genuinely global capability.
	 * ------------------------------------------------------------------ */

	public static function can_view_forms() {
		return current_user_can( 'everest_forms_view_forms' ) || current_user_can( 'manage_everest_forms' );
	}

	public static function can_create_forms() {
		return current_user_can( 'everest_forms_create_forms' );
	}

	public static function can_delete_entries() {
		return current_user_can( 'everest_forms_delete_entries' ) || current_user_can( 'manage_everest_forms' );
	}

	public static function can_activate_plugins() {
		return current_user_can( 'activate_plugins' );
	}

	public static function can_view_form( $input ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		return current_user_can( 'everest_forms_view_form', $form_id ) || current_user_can( 'manage_everest_forms' );
	}

	public static function can_edit_form( $input ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		return current_user_can( 'everest_forms_edit_form', $form_id ) || current_user_can( 'manage_everest_forms' );
	}

	public static function can_delete_form( $input ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		return current_user_can( 'everest_forms_delete_form', $form_id ) || current_user_can( 'manage_everest_forms' );
	}

	public static function can_view_form_entries( $input ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		return current_user_can( 'everest_forms_view_form_entries', $form_id ) || current_user_can( 'manage_everest_forms' );
	}

	public static function can_edit_form_entries( $input ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		return current_user_can( 'everest_forms_edit_form_entries', $form_id ) || current_user_can( 'manage_everest_forms' );
	}

	public static function can_view_entry( $input ) {
		$entry_id = isset( $input['entry_id'] ) ? (int) $input['entry_id'] : 0;
		return current_user_can( 'everest_forms_view_entry', $entry_id ) || current_user_can( 'manage_everest_forms' );
	}

	public static function can_edit_entry( $input ) {
		$entry_id = isset( $input['entry_id'] ) ? (int) $input['entry_id'] : 0;
		return current_user_can( 'everest_forms_edit_entry', $entry_id ) || current_user_can( 'manage_everest_forms' );
	}

	public static function can_delete_entry( $input ) {
		$entry_id = isset( $input['entry_id'] ) ? (int) $input['entry_id'] : 0;
		return current_user_can( 'everest_forms_delete_entry', $entry_id ) || current_user_can( 'manage_everest_forms' );
	}

	/**
	 * count-entries / analytics-summary accept form_id=0 to mean "site-wide".
	 * A single object-id check can't gate that, so this only confirms the
	 * caller may call the ability at all; the handlers additionally scope
	 * their own queries to the caller's forms when form_id is 0 and the
	 * caller lacks the "others" capability.
	 */
	public static function can_view_entries_scope( $input ) {
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		if ( $form_id > 0 ) {
			return current_user_can( 'everest_forms_view_form_entries', $form_id ) || current_user_can( 'manage_everest_forms' );
		}
		return current_user_can( 'everest_forms_view_entries' ) || current_user_can( 'everest_forms_view_others_entries' ) || current_user_can( 'manage_everest_forms' );
	}

	/**
	 * Whether a site-wide (form_id=0) entries query must be restricted to
	 * forms the current user owns, i.e. they lack both the "others" entries
	 * capability and manage_everest_forms.
	 */
	protected static function needs_own_entries_scope() {
		return ! current_user_can( 'everest_forms_view_others_entries' ) && ! current_user_can( 'manage_everest_forms' );
	}

	/* ------------------------------------------------------------------
	 * Form abilities
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $input Args.
	 * @return array
	 */
	public static function list_forms( $input ) {
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$status = isset( $input['status'] ) ? (string) $input['status'] : 'publish';

		$query_args = array(
			'post_type'      => 'everest_form',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( 'any' !== $status ) {
			$query_args['post_status'] = $status;
		} else {
			$query_args['post_status'] = array( 'publish', 'draft', 'trash' );
		}

		if ( ! current_user_can( 'everest_forms_view_others_forms' ) && ! current_user_can( 'manage_everest_forms' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$forms = get_posts( $query_args );
		$out   = array();
		foreach ( $forms as $form ) {
			$out[] = array(
				'id'      => (int) $form->ID,
				'title'   => $form->post_title,
				'status'  => $form->post_status,
				'created' => $form->post_date_gmt,
			);
		}
		return $out;
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function get_form( $input ) {
		$form_id = (int) $input['form_id'];
		$form    = evf()->form->get( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'evf_form_not_found', 'Form not found.', array( 'status' => 404 ) );
		}
		$data = is_string( $form->post_content ) ? json_decode( $form->post_content, true ) : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		// Surface the parts of the post_content the LLM needs to make sensible
		// follow-up edits: settings (incl. multi-part/conversational toggles),
		// fields config, the structure map that says where each field lives,
		// and the multi_part array when present. Without structure+multi_part
		// the LLM can't reliably know how a form is laid out.
		return array(
			'id'         => (int) $form->ID,
			'title'      => $form->post_title,
			'status'     => $form->post_status,
			'settings'   => isset( $data['settings'] ) ? $data['settings'] : array(),
			'fields'     => isset( $data['form_fields'] ) ? $data['form_fields'] : array(),
			'structure'  => isset( $data['structure'] ) ? $data['structure'] : array(),
			'multi_part' => isset( $data['multi_part'] ) ? $data['multi_part'] : array(),
		);
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function create_form( $input ) {
		$title    = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : '';
		$template = isset( $input['template'] ) ? sanitize_key( $input['template'] ) : 'blank';
		$dry_run  = ! empty( $input['dry_run'] );
		// Default to inactive so AI-built forms don't go live until the user
		// explicitly publishes them. EVF marks a form inactive with BOTH a
		// custom `inactive` post_status AND `form_enabled = 0` in the form
		// data (the toggle in the All Forms list reads form_enabled). Accept
		// "draft" as an alias for "inactive".
		$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'inactive';
		if ( 'draft' === $status ) {
			$status = 'inactive';
		}
		if ( ! in_array( $status, array( 'inactive', 'publish' ), true ) ) {
			$status = 'inactive';
		}
		$is_active = ( 'publish' === $status );

		if ( '' === $title ) {
			return new WP_Error( 'evf_invalid_title', 'A non-empty title is required.', array( 'status' => 400 ) );
		}

		$descriptor = array_intersect_key( $input, array_flip( array( 'title', 'description', 'settings', 'fields', 'layout', 'multi_part', 'conversational' ) ) );

		// Validate the descriptor against the schema registry BEFORE touching
		// the database, so a bad field type doesn't leave an empty form on
		// disk. dry_run can use the same throwaway build to return a preview.
		$preview = EVF_Form_Builder::build( array(), $descriptor );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		if ( $dry_run ) {
			return array(
				'dry_run' => true,
				'preview' => array(
					'title'       => isset( $preview['settings']['form_title'] ) ? $preview['settings']['form_title'] : $title,
					'fields'      => self::summarize_fields( $preview ),
					'field_count' => isset( $preview['form_fields'] ) ? count( $preview['form_fields'] ) : 0,
					'structure'   => isset( $preview['structure'] ) ? $preview['structure'] : array(),
				),
			);
		}

		$form_id = evf()->form->create( $title, $template );
		if ( ! $form_id ) {
			return new WP_Error( 'evf_create_failed', 'Form creation failed (insufficient capabilities or invalid template).', array( 'status' => 500 ) );
		}

		// Merge what the template produced (if any) with what we built. This
		// is the canonical build — produces the final shape, validates again
		// in case the template added something incompatible (shouldn't, but
		// defensive). If this fails, clean up the empty form we just made.
		$created = evf()->form->get( $form_id );
		$base    = is_string( $created->post_content ) ? json_decode( $created->post_content, true ) : array();
		if ( ! is_array( $base ) ) {
			$base = array();
		}
		$merged = EVF_Form_Builder::build( $base, $descriptor );
		if ( is_wp_error( $merged ) ) {
			wp_delete_post( $form_id, true );
			return $merged;
		}
		// EVF's "active/inactive" state lives in the top-level `form_enabled`
		// flag (the toggle in the All Forms list reads it, defaulting to 1).
		// Set it in the form data so the merged save persists the right state.
		$merged['form_enabled'] = $is_active ? 1 : 0;
		$merged['id']           = (int) $form_id;
		evf()->form->update( $form_id, $merged );

		// EVF's form->create() always publishes. Mirror the active/inactive
		// state into post_status too (EVF uses a custom `inactive` status,
		// not `draft`), so both the toggle and the status filter agree.
		wp_update_post( array( 'ID' => (int) $form_id, 'post_status' => $is_active ? 'publish' : 'inactive' ) );

		return array(
			'id'       => (int) $form_id,
			'title'    => isset( $merged['settings']['form_title'] ) ? $merged['settings']['form_title'] : $title,
			'status'   => $status,
			'active'   => $is_active,
			'fields'   => self::summarize_fields( $merged ),
			'edit_url' => admin_url( 'admin.php?page=evf-builder&tab=fields&form_id=' . (int) $form_id ),
		);
	}

	/**
	 * Summarize fields for a response envelope (no internal IDs in the verbose form).
	 *
	 * @param array $data Built form-data array.
	 * @return array
	 */
	protected static function summarize_fields( $data ) {
		$out = array();
		if ( empty( $data['form_fields'] ) ) {
			return $out;
		}
		foreach ( $data['form_fields'] as $fid => $f ) {
			$out[] = array(
				'id'    => (string) $fid,
				'type'  => isset( $f['type'] ) ? $f['type'] : '',
				'label' => isset( $f['label'] ) ? $f['label'] : '',
			);
		}
		return $out;
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function update_form( $input ) {
		$form_id = (int) $input['form_id'];
		$dry_run = ! empty( $input['dry_run'] );
		$form    = evf()->form->get( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'evf_form_not_found', 'Form not found.', array( 'status' => 404 ) );
		}

		$existing = is_string( $form->post_content ) ? json_decode( $form->post_content, true ) : array();
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$descriptor = array_intersect_key( $input, array_flip( array( 'title', 'description', 'settings', 'fields', 'layout', 'form_fields_patch', 'multi_part', 'conversational' ) ) );

		$next = EVF_Form_Builder::build( $existing, $descriptor );
		if ( is_wp_error( $next ) ) {
			return $next;
		}
		$next['id'] = $form_id;

		if ( $dry_run ) {
			return array(
				'dry_run' => true,
				'form_id' => $form_id,
				'preview' => array(
					'title'       => isset( $next['settings']['form_title'] ) ? $next['settings']['form_title'] : '',
					'fields'      => self::summarize_fields( $next ),
					'field_count' => isset( $next['form_fields'] ) ? count( $next['form_fields'] ) : 0,
					'structure'   => isset( $next['structure'] ) ? $next['structure'] : array(),
				),
			);
		}

		$result = evf()->form->update( $form_id, $next );
		if ( ! $result ) {
			return new WP_Error( 'evf_form_update_failed', 'Form update failed (likely a capability check).', array( 'status' => 403 ) );
		}

		return array(
			'form_id'  => (int) $result,
			'title'    => isset( $next['settings']['form_title'] ) ? $next['settings']['form_title'] : '',
			'fields'   => self::summarize_fields( $next ),
			'edit_url' => admin_url( 'admin.php?page=evf-builder&tab=fields&form_id=' . (int) $result ),
		);
	}

	/**
	 * @param array $input Args.
	 * @return array
	 */
	/**
	 * Activate an installed Everest Forms addon.
	 *
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function activate_addon( $input ) {
		$plugin = isset( $input['plugin'] ) ? (string) $input['plugin'] : '';
		if ( '' === $plugin || false === strpos( $plugin, '/' ) ) {
			return new WP_Error( 'evf_invalid_plugin', 'Plugin file is required, e.g. "everest-forms-survey-polls-quiz/everest-forms-survey-polls-quiz.php".', array( 'status' => 400 ) );
		}

		// Refuse to activate anything outside the EVF family — this ability is
		// scoped to Everest Forms addons, not a general plugin activator.
		if ( 0 !== strpos( $plugin, 'everest-forms' ) ) {
			return new WP_Error( 'evf_not_an_evf_addon', 'This ability only activates Everest Forms addons.', array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = (array) get_plugins();
		if ( ! isset( $installed[ $plugin ] ) ) {
			return new WP_Error(
				'evf_addon_not_installed',
				sprintf( 'Addon "%s" is not installed on this site. The user needs to install/purchase it from their Everest Forms account, or upgrade their plan if it is not included.', $plugin ),
				array(
					'status'      => 404,
					'plugin'      => $plugin,
					'action'      => 'install_or_upgrade',
					'account_url' => 'https://everestforms.net/my-account/downloads/',
				)
			);
		}

		if ( is_plugin_active( $plugin ) ) {
			return array(
				'plugin'  => $plugin,
				'active'  => true,
				'message' => 'Addon was already active.',
			);
		}

		// Explicit-confirmation gate. Activating a plugin changes site state,
		// so we never do it on the first call. The caller must come back with
		// confirm:true after the user has actually agreed. This guarantees a
		// confirmation step server-side, independent of whether the MCP client
		// shows its own approval dialog (the user may have "always allow" set).
		$confirmed = ! empty( $input['confirm'] ) && true === filter_var( $input['confirm'], FILTER_VALIDATE_BOOLEAN );
		if ( ! $confirmed ) {
			$name = isset( $installed[ $plugin ]['Name'] ) ? $installed[ $plugin ]['Name'] : $plugin;
			return array(
				'status'             => 'confirmation_required',
				'plugin'             => $plugin,
				'addon'              => $name,
				'requires_confirm'   => true,
				'message'            => sprintf( 'Activating the "%s" addon will change this site. Ask the user to confirm, then call activate-addon again with confirm:true to proceed. Do NOT activate without the user\'s explicit agreement.', $name ),
			);
		}

		$result = activate_plugin( $plugin, '', false, false );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'evf_addon_activation_failed',
				$result->get_error_message(),
				array( 'status' => 500, 'plugin' => $plugin )
			);
		}

		// EVF has a second gate beyond WP plugin activation: the slug must be
		// present in the `everest_forms_enabled_features` option for some addon
		// field types (payment-coupon, payment-square, etc.) to be registered.
		// Derive the slug from the plugin file (folder name) and add it.
		$slug             = dirname( $plugin );
		$enabled_features = (array) get_option( 'everest_forms_enabled_features', array() );
		$feature_added    = false;
		if ( $slug && ! in_array( $slug, $enabled_features, true ) ) {
			$enabled_features[] = $slug;
			update_option( 'everest_forms_enabled_features', $enabled_features );
			$feature_added = true;
		}

		return array(
			'plugin'        => $plugin,
			'active'        => true,
			'feature_slug'  => $slug,
			'feature_added' => $feature_added,
			'name'          => isset( $installed[ $plugin ]['Name'] ) ? $installed[ $plugin ]['Name'] : $plugin,
			'version'       => isset( $installed[ $plugin ]['Version'] ) ? $installed[ $plugin ]['Version'] : null,
			'message'       => 'Addon activated and registered in Everest Forms enabled-features. Retry the original create-form/update-form call now.',
		);
	}

	public static function list_addons( $input ) {
		$known = array(
			'everest-forms-pro/everest-forms-pro.php'                                     => 'Everest Forms PRO',
			'everest-forms-survey-polls-quiz/everest-forms-survey-polls-quiz.php'         => 'Survey, Polls and Quiz',
			'everest-forms-multi-part/everest-forms-multi-part.php'                       => 'Multi-Part Forms',
			'everest-forms-repeater-fields/everest-forms-repeater-fields.php'             => 'Repeater Fields',
			'everest-forms-conversational-forms/everest-forms-conversational-forms.php'   => 'Conversational Forms',
			'everest-forms-coupons/everest-forms-coupons.php'                             => 'Coupons',
			'everest-forms-captcha/everest-forms-captcha.php'                             => 'Captcha',
			'everest-forms-calculations/everest-forms-calculations.php'                   => 'Calculations',
			'everest-forms-save-and-continue/everest-forms-save-and-continue.php'         => 'Save and Continue',
			'everest-forms-pdf-submission/everest-forms-pdf-submission.php'               => 'PDF Submission',
			'everest-forms-google-sheets/everest-forms-google-sheets.php'                 => 'Google Sheets',
			'everest-forms-mailchimp/everest-forms-mailchimp.php'                         => 'Mailchimp',
			'everest-forms-zapier/everest-forms-zapier.php'                               => 'Zapier',
			'everest-forms-stripe/everest-forms-stripe.php'                               => 'Stripe',
			'everest-forms-paypal-standard/everest-forms-paypal-standard.php'             => 'PayPal Standard',
			'everest-forms-form-analytics/everest-forms-form-analytics.php'               => 'Form Analytics',
			'everest-forms-user-registration/everest-forms-user-registration.php'         => 'User Registration',
			'everest-forms-post-submissions/everest-forms-post-submissions.php'           => 'Post Submissions',
			'everest-forms-email-templates/everest-forms-email-templates.php'             => 'Email Templates',
			'everest-forms-frontend-listing/everest-forms-frontend-listing.php'           => 'Frontend Listing',
			'everest-forms-style-customizer/everest-forms-style-customizer.php'           => 'Style Customizer',
			'everest-forms-sms-notifications/everest-forms-sms-notifications.php'         => 'SMS Notifications',
		);

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Addons whose field types are gated by EVF's `everest_forms_enabled_features`
		// option in addition to WP plugin activation. For these, the slug MUST be
		// in enabled_features for the addon's fields to register. For everything
		// else (PRO core, Multi-Part, Captcha, Conversational, etc.) the WP plugin
		// active state alone determines operationality.
		$feature_gated_slugs = array( 'everest-forms-coupons', 'everest-forms-square' );

		$installed        = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
		$enabled_features = (array) get_option( 'everest_forms_enabled_features', array() );
		$out              = array();
		foreach ( $known as $file => $name ) {
			$is_installed = isset( $installed[ $file ] );
			$slug         = dirname( $file );
			$active       = function_exists( 'is_plugin_active' ) ? is_plugin_active( $file ) : false;
			$feature_on   = in_array( $slug, $enabled_features, true );
			$gated        = in_array( $slug, $feature_gated_slugs, true );

			// Compute correctly: feature-gated addons need installed+active+feature_on;
			// non-gated addons just need installed+active.
			$fully = $is_installed && $active && ( ! $gated || $feature_on );

			$out[] = array(
				'plugin'            => $file,
				'name'              => $name,
				'installed'         => $is_installed,
				'active'            => $active,
				'feature_gated'     => $gated,
				'evf_feature_on'    => $feature_on,
				'fully_operational' => $fully,
				'version'           => $is_installed && isset( $installed[ $file ]['Version'] ) ? $installed[ $file ]['Version'] : null,
			);
		}
		return $out;
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function describe_field_type( $input ) {
		$type   = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : '';
		if ( '' === $type ) {
			return new WP_Error( 'evf_invalid_type', 'Missing "type".', array( 'status' => 400 ) );
		}
		$registered = EVF_Field_Schemas::registered_types();
		$schema     = EVF_Field_Schemas::for_type( $type );
		$addon      = EVF_Field_Schemas::addon_for( $type );

		// `available` reflects whether the field class is registered in core
		// (true even for likert/yes-no/rating whose stubs live in core but
		// whose real behavior ships in an addon). `usable_now` is the more
		// useful "will the form builder accept this right now?" flag,
		// matching exactly what validate_field() in the builder enforces:
		// class registered AND (no addon dependency OR addon plugin active).
		$class_registered = isset( $registered[ $type ] );
		$addon_active     = true;
		if ( $addon && ! empty( $addon['plugin'] ) ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$addon_active = function_exists( 'is_plugin_active' ) ? is_plugin_active( $addon['plugin'] ) : false;
		}

		return array(
			'type'             => $type,
			'available'        => $class_registered,
			'usable_now'       => $class_registered && $addon_active,
			'addon'            => $addon,
			'addon_active'     => $addon_active,
			'accepted_keys'    => $schema ? $schema['accepted_keys'] : array(),
			'requires_choices' => $schema ? $schema['requires_choices'] : false,
			'known_to_builder' => null !== $schema,
		);
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function update_form_status( $input ) {
		$form_id = (int) $input['form_id'];
		$status  = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : '';
		if ( ! in_array( $status, array( 'publish', 'draft', 'trash' ), true ) ) {
			return new WP_Error( 'evf_invalid_status', 'Status must be one of: publish, draft, trash.', array( 'status' => 400 ) );
		}
		$post = get_post( $form_id );
		if ( ! $post || 'everest_form' !== $post->post_type ) {
			return new WP_Error( 'evf_form_not_found', 'Form not found.', array( 'status' => 404 ) );
		}
		if ( $post->post_status === $status ) {
			return array( 'form_id' => $form_id, 'status' => $status, 'changed' => false );
		}
		$result = wp_update_post( array( 'ID' => $form_id, 'post_status' => $status ), true );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'evf_form_status_update_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}
		return array( 'form_id' => $form_id, 'status' => $status, 'changed' => true );
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function bulk_delete_entries( $input ) {
		global $wpdb;
		$ids       = isset( $input['entry_ids'] ) && is_array( $input['entry_ids'] ) ? array_values( array_unique( array_filter( array_map( 'intval', $input['entry_ids'] ) ) ) ) : array();
		$permanent = array_key_exists( 'permanent', $input ) ? (bool) $input['permanent'] : true;

		if ( empty( $ids ) ) {
			return new WP_Error( 'evf_no_ids', 'Provide one or more entry_ids.', array( 'status' => 400 ) );
		}

		$requested = count( $ids );

		// permission_callback only confirms the caller may delete entries at
		// all (an array of ids can't be gated as one object); check each id
		// individually here, the same way the single-entry delete-entry
		// ability does via its own per-object permission_callback.
		$can_manage_all = current_user_can( 'manage_everest_forms' );
		$unauthorized   = array();
		$ids            = array_values(
			array_filter(
				$ids,
				function ( $entry_id ) use ( $can_manage_all, &$unauthorized ) {
					if ( $can_manage_all || current_user_can( 'everest_forms_delete_entry', $entry_id ) ) {
						return true;
					}
					$unauthorized[] = $entry_id;
					return false;
				}
			)
		);

		if ( empty( $ids ) ) {
			return new WP_Error( 'evf_forbidden', 'Permission denied for all provided entry_ids.', array( 'status' => 403 ) );
		}

		$deleted = 0;
		$skipped = $unauthorized;

		if ( ! $permanent ) {
			// Single UPDATE for the soft-delete case — much cheaper than N round-trips.
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$query        = "UPDATE {$wpdb->prefix}evf_entries SET status = 'trash' WHERE entry_id IN ({$placeholders})";
			$result       = $wpdb->query( $wpdb->prepare( $query, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $result ) {
				return new WP_Error( 'evf_bulk_trash_failed', 'Bulk trash failed.', array( 'status' => 500 ) );
			}
			return array(
				'trashed'    => (int) $result,
				'requested'  => $requested,
				'skipped'    => $skipped,
				'permanent'  => false,
			);
		}

		foreach ( $ids as $entry_id ) {
			$d = $wpdb->delete( $wpdb->prefix . 'evf_entries', array( 'entry_id' => $entry_id ), array( '%d' ) );
			if ( false === $d || 0 === (int) $d ) {
				$skipped[] = $entry_id;
				continue;
			}
			$wpdb->delete( $wpdb->prefix . 'evf_entrymeta', array( 'entry_id' => $entry_id ), array( '%d' ) );
			do_action( 'everest_forms_delete_entry', $entry_id );
			$deleted++;
		}

		return array(
			'deleted'   => $deleted,
			'requested' => $requested,
			'skipped'   => $skipped,
			'permanent' => true,
		);
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function delete_form( $input ) {
		$form_id = (int) $input['form_id'];
		$post    = get_post( $form_id );
		if ( ! $post || 'everest_form' !== $post->post_type ) {
			return new WP_Error( 'evf_form_not_found', 'Form not found.', array( 'status' => 404 ) );
		}
		// We've already gated on `can_delete_form` in the permission_callback,
		// so bypass EVF_Form_Handler::delete()'s 'everest_forms_delete' cap check
		// (singular cap that isn't granted by default) and delete directly.
		$deleted = wp_delete_post( $form_id, true );
		if ( ! $deleted ) {
			return new WP_Error( 'evf_form_delete_failed', 'wp_delete_post failed.', array( 'status' => 500 ) );
		}
		do_action( 'everest_forms_delete_form', array( $form_id ) );
		return array( 'deleted' => true, 'form_id' => $form_id );
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function duplicate_form( $input ) {
		$form_id = (int) $input['form_id'];
		// permission_callback (can_create_forms) only confirms the caller may
		// create new forms at all -- it says nothing about the SOURCE form
		// being copied, which is a different object. Check that separately.
		if ( ! current_user_can( 'everest_forms_view_form', $form_id ) && ! current_user_can( 'manage_everest_forms' ) ) {
			return new WP_Error( 'evf_form_forbidden', 'Not allowed to duplicate this form.', array( 'status' => 403 ) );
		}
		$result  = evf()->form->duplicate( array( $form_id ) );
		if ( ! $result ) {
			return new WP_Error( 'evf_form_duplicate_failed', 'Form could not be duplicated.', array( 'status' => 500 ) );
		}
		$new_id = is_array( $result ) ? (int) reset( $result ) : (int) $result;
		return array(
			'source_form_id' => $form_id,
			'new_form_id'    => $new_id,
		);
	}

	/**
	 * @param array $input Args.
	 * @return array
	 */
	public static function list_field_types( $input ) {
		$out      = array();
		$registry = evf()->form_fields;
		$groups   = ( is_object( $registry ) && isset( $registry->form_fields ) ) ? (array) $registry->form_fields : array();

		foreach ( $groups as $group => $fields ) {
			foreach ( (array) $fields as $field ) {
				if ( ! is_object( $field ) ) {
					continue;
				}
				$out[] = array(
					'type'  => isset( $field->type ) ? $field->type : '',
					'name'  => isset( $field->name ) ? $field->name : '',
					'group' => (string) $group,
					'icon'  => isset( $field->icon ) ? $field->icon : '',
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Entry abilities
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $input Args.
	 * @return array
	 */
	public static function list_entries( $input ) {
		$args = array(
			'form_id' => (int) $input['form_id'],
			'limit'   => isset( $input['limit'] ) ? (int) $input['limit'] : 25,
			'offset'  => isset( $input['offset'] ) ? (int) $input['offset'] : 0,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		if ( ! empty( $input['status'] ) ) {
			$args['status'] = (string) $input['status'];
		}
		$ids = evf_search_entries( $args );
		$out = array();
		foreach ( (array) $ids as $entry_id ) {
			$entry = function_exists( 'evf_get_entry' ) ? evf_get_entry( (int) $entry_id ) : null;
			if ( ! $entry ) {
				continue;
			}
			$out[] = array(
				'entry_id' => (int) $entry->entry_id,
				'form_id'  => (int) $entry->form_id,
				'status'   => isset( $entry->status ) ? $entry->status : '',
				'date'     => isset( $entry->date_created ) ? $entry->date_created : '',
			);
		}
		return $out;
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function get_entry( $input ) {
		$entry_id = (int) $input['entry_id'];
		$entry    = function_exists( 'evf_get_entry' ) ? evf_get_entry( $entry_id, true ) : null;
		if ( ! $entry ) {
			return new WP_Error( 'evf_entry_not_found', 'Entry not found.', array( 'status' => 404 ) );
		}
		$meta = isset( $entry->meta ) ? $entry->meta : array();
		return array(
			'entry_id' => (int) $entry->entry_id,
			'form_id'  => (int) $entry->form_id,
			'status'   => isset( $entry->status ) ? $entry->status : '',
			'date'     => isset( $entry->date_created ) ? $entry->date_created : '',
			'fields'   => $meta,
		);
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function delete_entry( $input ) {
		global $wpdb;
		$entry_id  = (int) $input['entry_id'];
		$permanent = array_key_exists( 'permanent', $input ) ? (bool) $input['permanent'] : true;

		if ( ! $permanent ) {
			$updated = $wpdb->update(
				$wpdb->prefix . 'evf_entries',
				array( 'status' => 'trash' ),
				array( 'entry_id' => $entry_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'evf_entry_trash_failed', 'Entry could not be moved to trash.', array( 'status' => 500 ) );
			}
			return array( 'trashed' => true, 'entry_id' => $entry_id );
		}

		$deleted = $wpdb->delete( $wpdb->prefix . 'evf_entries', array( 'entry_id' => $entry_id ), array( '%d' ) );
		if ( false === $deleted || 0 === (int) $deleted ) {
			return new WP_Error( 'evf_entry_delete_failed', 'Entry could not be deleted (not found or DB error).', array( 'status' => 404 ) );
		}
		$wpdb->delete( $wpdb->prefix . 'evf_entrymeta', array( 'entry_id' => $entry_id ), array( '%d' ) );
		do_action( 'everest_forms_delete_entry', $entry_id );
		return array( 'deleted' => true, 'entry_id' => $entry_id );
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function update_entry_status( $input ) {
		global $wpdb;
		$entry_id = (int) $input['entry_id'];
		$status   = sanitize_key( $input['status'] );
		$updated  = $wpdb->update(
			$wpdb->prefix . 'evf_entries',
			array( 'status' => $status ),
			array( 'entry_id' => $entry_id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'evf_entry_status_failed', 'Could not update entry status.', array( 'status' => 500 ) );
		}
		do_action( 'everest_forms_update_entry_status', $entry_id, $status );
		return array( 'entry_id' => $entry_id, 'status' => $status, 'updated' => (int) $updated );
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function set_entry_starred( $input ) {
		return self::set_entry_flag( $input, 'starred' );
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function set_entry_viewed( $input ) {
		return self::set_entry_flag( $input, 'viewed' );
	}

	/**
	 * Shared implementation for boolean entry flags (starred/viewed).
	 *
	 * @param array  $input Input args.
	 * @param string $col   Column name.
	 * @return array|WP_Error
	 */
	protected static function set_entry_flag( $input, $col ) {
		global $wpdb;
		$entry_id = (int) $input['entry_id'];
		$value    = ! empty( $input[ $col ] ) ? 1 : 0;
		$updated  = $wpdb->update(
			$wpdb->prefix . 'evf_entries',
			array( $col => $value ),
			array( 'entry_id' => $entry_id ),
			array( '%d' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'evf_entry_flag_failed', "Could not update entry {$col}.", array( 'status' => 500 ) );
		}
		return array( 'entry_id' => $entry_id, $col => (bool) $value, 'updated' => (int) $updated );
	}

	/**
	 * @param array $input Args.
	 * @return array
	 */
	public static function count_entries( $input ) {
		global $wpdb;
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		$table   = $wpdb->prefix . 'evf_entries';

		if ( $form_id > 0 ) {
			$where = $wpdb->prepare( 'WHERE form_id = %d', $form_id );
		} elseif ( self::needs_own_entries_scope() ) {
			// Site-wide (form_id=0) request from a caller who only holds the
			// own-scoped capability -- restrict to entries whose form they
			// own instead of exposing every user's entry counts.
			$where = $wpdb->prepare(
				"WHERE form_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'everest_form' AND post_author = %d )",
				get_current_user_id()
			);
		} else {
			$where = '';
		}

		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		$unread   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where} " . ( '' === $where ? 'WHERE' : 'AND' ) . " viewed = 0 AND status <> 'trash'" );
		$starred  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where} " . ( '' === $where ? 'WHERE' : 'AND' ) . " starred = 1 AND status <> 'trash'" );

		$by_status = array();
		foreach ( array( 'publish', 'approved', 'denied', 'pending', 'spam', 'trash' ) as $st ) {
			$status_clause     = '' === $where ? 'WHERE' : $where . ' AND';
			$by_status[ $st ]  = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$status_clause} status = %s", $st )
			);
		}

		return array(
			'form_id'   => $form_id,
			'total'     => $total,
			'unread'    => $unread,
			'starred'   => $starred,
			'by_status' => $by_status,
		);
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function create_entry( $input ) {
		global $wpdb;
		$form_id     = (int) $input['form_id'];
		$input_flds  = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		$status      = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';
		$fire_hooks  = ! isset( $input['fire_hooks'] ) || ! empty( $input['fire_hooks'] ); // default true

		if ( empty( $input_flds ) ) {
			return new WP_Error( 'evf_no_fields', 'No fields provided.', array( 'status' => 400 ) );
		}
		$post = get_post( $form_id );
		if ( ! $post || 'everest_form' !== $post->post_type ) {
			return new WP_Error( 'evf_form_not_found', 'Form not found.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'everest_forms_view_form_entries', $form_id ) && ! current_user_can( 'manage_everest_forms' ) ) {
			return new WP_Error( 'evf_form_forbidden', 'Not allowed to create entries for this form.', array( 'status' => 403 ) );
		}

		// Decode the form config so we can:
		// (a) translate caller-friendly meta-key inputs into EVF's rich $fields
		//     shape ({ field_id => { id, name, value, type, meta_key } }) that
		//     upstream listeners (slot booking, email notifications, Zapier,
		//     etc.) require, and
		// (b) hand the same $form_data array to listeners.
		$form_data = array();
		if ( is_string( $post->post_content ) ) {
			$decoded = json_decode( $post->post_content, true );
			if ( is_array( $decoded ) ) {
				$form_data = $decoded;
			}
		}
		$form_fields_config = isset( $form_data['form_fields'] ) && is_array( $form_data['form_fields'] ) ? $form_data['form_fields'] : array();

		// Build a meta-key → field-config index so the caller can refer to
		// fields by either their generated meta-key ("email_4") or their
		// human label ("Email") and we figure out the right field-id.
		$by_meta_key = array();
		$by_label    = array();
		foreach ( $form_fields_config as $fid => $fcfg ) {
			if ( ! is_array( $fcfg ) ) {
				continue;
			}
			if ( isset( $fcfg['meta-key'] ) ) {
				$by_meta_key[ (string) $fcfg['meta-key'] ] = $fid;
			}
			if ( isset( $fcfg['label'] ) ) {
				$by_label[ strtolower( (string) $fcfg['label'] ) ] = $fid;
			}
		}

		// Build EVF-shaped $fields ({ field_id => array(...) }) plus a meta-key
		// map ({ meta_key => string-value }) for the entry row's `fields` blob.
		$rich_fields = array();
		$meta_map    = array();
		foreach ( $input_flds as $key => $value ) {
			$lookup = (string) $key;
			$fid    = isset( $by_meta_key[ $lookup ] ) ? $by_meta_key[ $lookup ] : ( isset( $by_label[ strtolower( $lookup ) ] ) ? $by_label[ strtolower( $lookup ) ] : null );

			$str_val = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );

			if ( null !== $fid && isset( $form_fields_config[ $fid ] ) ) {
				$fcfg                  = $form_fields_config[ $fid ];
				$meta_key              = isset( $fcfg['meta-key'] ) ? (string) $fcfg['meta-key'] : sanitize_key( $lookup );
				$rich_fields[ $fid ]   = array(
					'id'       => $fid,
					'name'     => isset( $fcfg['label'] ) ? (string) $fcfg['label'] : '',
					'value'    => $str_val,
					'value_raw'=> $str_val,
					'type'     => isset( $fcfg['type'] ) ? (string) $fcfg['type'] : '',
					'meta_key' => $meta_key,
				);
				$meta_map[ $meta_key ] = $str_val;
			} else {
				// Caller passed a key that doesn't match any field. Persist
				// the meta row but skip the rich-field hook payload entry
				// (so downstream listeners that index by field_id don't trip).
				$meta_key             = sanitize_key( $lookup );
				$meta_map[ $meta_key ] = $str_val;
			}
		}

		// EVF stores the rich field shape ({ field_id: {meta_key, value, ...} })
		// in `evf_entries.fields` as JSON. evf_get_entry($id, true) decodes that
		// and walks each entry expecting `meta_key`/`value` keys — if we stored
		// a flat map here, get-entry would return empty fields.
		$entry_row = array(
			'user_id'         => get_current_user_id(),
			'user_device'     => '',
			'user_ip_address' => '',
			'form_id'         => $form_id,
			'referer'         => '',
			'fields'          => wp_json_encode( $rich_fields ),
			'status'          => $status,
			'viewed'          => 0,
			'starred'         => 0,
			'date_created'    => gmdate( 'Y-m-d H:i:s' ),
		);

		$inserted = $wpdb->insert( $wpdb->prefix . 'evf_entries', $entry_row );
		if ( ! $inserted ) {
			return new WP_Error( 'evf_entry_create_failed', 'Could not insert entry.', array( 'status' => 500 ) );
		}
		$entry_id = (int) $wpdb->insert_id;

		$meta_table = $wpdb->prefix . 'evf_entrymeta';
		foreach ( $meta_map as $meta_key => $meta_value ) {
			$wpdb->insert(
				$meta_table,
				array(
					'entry_id'   => $entry_id,
					'meta_key'   => $meta_key,
					'meta_value' => $meta_value,
				),
				array( '%d', '%s', '%s' )
			);
		}

		// Fire the full upstream save action so email notifications, Zapier,
		// Google Sheets, slot-booking, etc. all run normally. Signature must
		// match: ($entry_id, $fields, $entry, $form_id, $form_data) — passing
		// 4 args triggers a fatal in EVF_Form_Task::update_slot_booking_value().
		if ( $fire_hooks ) {
			$entry_for_action = (object) array(
				'entry_id' => $entry_id,
				'form_id'  => $form_id,
				'fields'   => $rich_fields,
				'status'   => $status,
			);
			do_action( 'everest_forms_complete_entry_save', $entry_id, $rich_fields, $entry_for_action, $form_id, $form_data );
		}

		return array(
			'entry_id' => $entry_id,
			'form_id'  => $form_id,
			'status'   => $status,
		);
	}

	/**
	 * @param array $input Args.
	 * @return array|WP_Error
	 */
	public static function update_entry_fields( $input ) {
		global $wpdb;
		$entry_id = (int) $input['entry_id'];
		$fields   = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
		if ( empty( $fields ) ) {
			return new WP_Error( 'evf_no_fields', 'No fields provided.', array( 'status' => 400 ) );
		}

		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$wpdb->prefix}evf_entries WHERE entry_id = %d", $entry_id ) );
		if ( ! $exists ) {
			return new WP_Error( 'evf_entry_not_found', 'Entry not found.', array( 'status' => 404 ) );
		}

		$meta_table = $wpdb->prefix . 'evf_entrymeta';
		$changed    = array();
		foreach ( $fields as $field_id => $value ) {
			$meta_key   = sanitize_key( (string) $field_id );
			$meta_value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );

			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$meta_table} WHERE entry_id = %d AND meta_key = %s", $entry_id, $meta_key ) );
			if ( $existing ) {
				$wpdb->update( $meta_table, array( 'meta_value' => $meta_value ), array( 'meta_id' => (int) $existing ), array( '%s' ), array( '%d' ) );
			} else {
				$wpdb->insert( $meta_table, array( 'entry_id' => $entry_id, 'meta_key' => $meta_key, 'meta_value' => $meta_value ), array( '%d', '%s', '%s' ) );
			}
			$changed[ $meta_key ] = $meta_value;
		}

		do_action( 'everest_forms_update_entry_fields', $entry_id, $changed );
		return array( 'entry_id' => $entry_id, 'updated_fields' => $changed );
	}

	/* ------------------------------------------------------------------
	 * Analytics
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $input Args.
	 * @return array
	 */
	public static function analytics_summary( $input ) {
		global $wpdb;
		$days    = isset( $input['days'] ) ? max( 1, (int) $input['days'] ) : 30;
		$form_id = isset( $input['form_id'] ) ? (int) $input['form_id'] : 0;
		$since   = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$table = $wpdb->prefix . 'evf_entries';
		$scope = ( 0 === $form_id ) && self::needs_own_entries_scope();

		$forms_scope = $scope ? $wpdb->prepare( ' AND post_author = %d', get_current_user_id() ) : '';
		$total_forms = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'everest_form' AND post_status = 'publish'{$forms_scope}" );

		if ( $form_id > 0 ) {
			$total_entries   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id ) );
			$recent_entries  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND date_created >= %s", $form_id, $since ) );
			$top_forms       = array();
		} else {
			// form_id=0 means "site-wide"; restrict to the caller's own forms
			// unless they hold the "others" entries capability or manage_everest_forms.
			$entries_scope = $scope
				? $wpdb->prepare(
					" AND form_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'everest_form' AND post_author = %d )",
					get_current_user_id()
				)
				: '';

			$total_entries  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE 1=1{$entries_scope}" );
			$recent_entries = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE date_created >= %s", $since ) . $entries_scope );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT form_id, COUNT(*) AS cnt FROM {$table} WHERE date_created >= %s{$entries_scope} GROUP BY form_id ORDER BY cnt DESC LIMIT 5",
					$since
				)
			);
			$top_forms = array();
			foreach ( (array) $rows as $row ) {
				$post = get_post( (int) $row->form_id );
				$top_forms[] = array(
					'form_id'    => (int) $row->form_id,
					'title'      => $post ? $post->post_title : '',
					'entries'    => (int) $row->cnt,
				);
			}
		}

		return array(
			'window_days'    => $days,
			'total_forms'    => $total_forms,
			'total_entries'  => $total_entries,
			'recent_entries' => $recent_entries,
			'top_forms'      => $top_forms,
			'scope_form_id'  => $form_id,
		);
	}
}
