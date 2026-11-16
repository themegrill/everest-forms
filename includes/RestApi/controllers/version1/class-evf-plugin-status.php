<?php
/**
 *  Plugin Status Data Controller.
 *
 * @since 3.0.3
 *
 * @package EverestForms/Classes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everest_Forms_Plugin_Status Class.
 */
class Everest_Forms_Plugin_Status {

	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	protected $namespace = 'everest-forms/v1';

	/**
	 * The base of this controller's route.
	 *
	 * @var string
	 */
	protected $rest_base = 'plugin';

	/**
	 * Register routes.
	 *
	 * @since 3.0.3
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_plugin_status' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'plugin_activate' ),
				'permission_callback' => array( $this, 'check_plugin_management_permissions' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/upgrade',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'plugin_upgrade' ),
				'permission_callback' => array( $this, 'check_plugin_management_permissions' ),
			)
		);
	}

		/**
		 * Get Plugin Status.
		 *
		 * @since 3.0.3
		 *
		 * @return WP_REST_Response|WP_Error
		 */
	public function get_plugin_status() {
		$extension_data   = self::get_addons_data();
		$features_lists   = $extension_data->features;
		$enabled_features = get_option( 'everest_forms_enabled_features', array() );
		$plugin_statuses  = array();

		foreach ( $features_lists as $feature ) {
			$plugin_statuses[ $feature->slug ] = in_array( $feature->slug, $enabled_features, true ) ? 'active' : 'inactive';
		}

		$addons_lists = $extension_data->products;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed_plugin_slugs = array_keys( get_plugins() );

		foreach ( $addons_lists as $addon ) {
			$addon_file = $addon->slug . '/' . $addon->slug . '.php';
			if ( in_array( $addon_file, $installed_plugin_slugs, true ) ) {
				$plugin_statuses[ $addon->slug ] = is_plugin_active( $addon_file ) ? 'active' : 'inactive';
			} else {
				$plugin_statuses[ $addon->slug ] = 'not-installed';
			}
		}

		return new WP_REST_Response(
			array(
				'success'       => true,
				'plugin_status' => $plugin_statuses,
			),
			200
		);
	}

	/**
	 * plugin Upgrade
	 *
	 * @since 3.0.3
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function plugin_upgrade( $request ) {

		$required_plugins = $request->get_param( 'requiredPlugins' );
		$license_key      = get_option( 'everest-forms-pro_license_key' );
		$plugin_status    = array();
		$plugin_to_check  = 'everest-forms-pro';
		if ( in_array( $plugin_to_check, $required_plugins ) ) {
			if ( $license_key && is_plugin_active( 'everest-forms-pro/everest-forms-pro.php' ) ) {
				$plugin_status = true;
			} else {
				$plugin_status = false;
			}
		}
		return new WP_REST_Response( array( 'plugin_status' => $plugin_status ), 200 );
	}



		/**
		 * Bulk Activate modules.
		 *
		 * @since 3.0.3
		 *
		 * @param WP_REST_Request $request Full details about the request.
		 *
		 * @return WP_Error|WP_REST_Response
		 */
	public static function plugin_activate( $request ) {
		$module_data = $request->get_param( 'moduleData' );

		if ( is_string( $module_data ) ) {
			$module_data = json_decode( $module_data, true );
		}

		if ( ! is_array( $module_data ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid module data format.', 'everest-forms' ),
				),
				400
			);
		}

		$addon_slugs   = array();
		$feature_slugs = array();

		foreach ( $module_data as $addon ) {
			if ( isset( $addon['type'] ) && 'addon' === $addon['type'] ) {
				array_push( $addon_slugs, $addon );
			} else {
				$slug                   = $addon['slug'];
				$feature_slugs[ $slug ] = isset( $addon['name'] ) ? $addon['name'] : $slug;
			}
		}

		$failed_modules = array();

		if ( ! empty( $addon_slugs ) ) {
			$failed_modules = array_merge( $failed_modules, self::bulk_install_addons( $addon_slugs ) );
		}

		if ( ! empty( $feature_slugs ) ) {
			$failed_modules = array_merge( $failed_modules, self::bulk_enable_feature( $feature_slugs ) );
		}

		if ( count( $failed_modules ) > 0 ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => sprintf( __( '%1$s activation failed. Please try again later.', 'everest-forms' ), implode( ', ', $failed_modules ) ),
				),
				400
			);
		} else {
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => __( 'All of the selected modules have been activated successfully.', 'everest-forms' ),
				),
				200
			);
		}
	}



		/**
		 * Handler for installing bulk extension.
		 *
		 * @since 3.0.3
		 *
		 * @param array $addon_data Datas of addons to activate.
		 *
		 * @see Plugin_Upgrader
		 *
		 * @global WP_Filesystem_Base $wp_filesystem Subclass
		 */
	public static function bulk_install_addons( $addon_data ) {
		$failed_addons = array();

		foreach ( $addon_data as $addon ) {
			$slug   = isset( $addon['slug'] ) ? sanitize_key( wp_unslash( $addon['slug'] ) ) : '';
			$name   = isset( $addon['name'] ) ? sanitize_text_field( $addon['name'] ) : '';
			$plugin = plugin_basename( WP_PLUGIN_DIR . '/' . $slug . '/' . $slug . '.php' );
			if ( is_plugin_active( $plugin ) ) {
				continue;
			}
			$status = array(
				'install' => 'plugin',
				'slug'    => $slug,
			);

			$status = self::install_individual_addon( $slug, $plugin, $name, $status );

			if ( isset( $status['success'] ) && ! $status['success'] ) {
				$failed_addons[] = array(
					'name'    => $name,
					'message' => $status['message'],
				);
			}
		}

		return $failed_addons;
	}

		/**
		 * Bulk enable features.
		 *
		 * @since 3.0.3
		 *
		 * @param array $feature_data Data of the features to enable.
		 */
	public static function bulk_enable_feature( $feature_data ) {
		$failed_to_enable = array();
		$enabled_features = get_option( 'everest_forms_enabled_features', array() );

		foreach ( $feature_data as $slug => $name ) {
			if ( ! in_array( $slug, $enabled_features, true ) ) {
				$enabled_features[] = $slug;
			}
		}

		$update_success = update_option( 'everest_forms_enabled_features', $enabled_features );

		if ( ! $update_success ) {
			$failed_to_enable[] = __( 'Failed to update enabled features.', 'everest-forms' );
		}

		return $failed_to_enable;
	}



	/**
	 * Handler for installing a extension.
	 *
	 * @since 3.0.3
	 *
	 * @param string $slug Slug of the addon to install.
	 * @param string $plugin Plugin file of the addon to install.
	 * @param string $name Name of the addon to install.
	 * @param array  $status Staus array to track addon installation status.
	 *
	 * @see Plugin_Upgrader
	 *
	 * @global WP_Filesystem_Base $wp_filesystem Subclass
	 */
	public static function install_individual_addon( $slug, $plugin, $name, $status ) {
		// Restrict activation to genuine Everest Forms addons so this endpoint
		// cannot be used to install or activate arbitrary plugins. Checked first
		// so rejected slugs return without loading the admin upgrader includes.
		if ( ! in_array( $slug, EVF_Modules::get_allowed_addon_slugs(), true ) ) {
			$status['success']      = false;
			$status['errorCode']    = 'invalid_addon_slug';
			$status['errorMessage'] = esc_html__( 'The requested module is not a valid Everest Forms addon.', 'everest-forms' );
			return $status;
		}

		require_once ABSPATH . '/wp-admin/includes/file.php';
		include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			$plugin_data          = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
			$status['plugin']     = $plugin;
			$status['pluginName'] = $plugin_data['Name'];

			if ( is_plugin_inactive( $plugin ) ) {
				$result = activate_plugin( $plugin );

				if ( is_wp_error( $result ) ) {
					$status['errorCode']    = $result->get_error_code();
					$status['errorMessage'] = $result->get_error_message();
					$status['success']      = false;
					return $status;
				}
				$status['success'] = true;
				$status['message'] = __( 'Addons activated successfully', 'everest-forms' );
				return $status;
			}
		}

		if ( 'aicontactform' === $slug && ! evf_string_to_bool( evf_get_license_plan() ) ) {
			$args = array(
				'slug'   => 'ai-contact-form',
				'fields' => array(
					'short_description' => true,
					'sections'          => true,
					'requires'          => true,
					'tested'            => true,
					'rating'            => true,
					'downloaded'        => true,
					'last_updated'      => true,
					'added'             => true,
					'tags'              => true,
					'homepage'          => true,
					'donate_link'       => true,
					'reviews'           => true,
					'download_link'     => true,
					'screenshots'       => true,
					'active_installs'   => true,
					'version'           => true,
				),
			);
			$api  = plugins_api( 'plugin_information', $args );
		} else {
			$api = json_decode(
				EVF_Updater_Key_API::version(
					array(
						'license'   => get_option( 'everest-forms-pro_license_key' ),
						'item_name' => ! empty( $name ) ? sanitize_text_field( wp_unslash( $name ) ) : '',
					)
				)
			);
		}

		if ( is_wp_error( $api ) ) {
			$status['success']      = false;
			$status['errorMessage'] = $api['msg'];
			return $status;
		}

		$status['pluginName'] = $api->name;
		$skin                 = new WP_Ajax_Upgrader_Skin();
		$upgrader             = new Plugin_Upgrader( $skin );
		$result               = $upgrader->install( $api->download_link );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$status['debug'] = $skin->get_upgrade_messages();
		}

		if ( is_wp_error( $result ) ) {
			$status['success']      = false;
			$status['errorCode']    = $result->get_error_code();
			$status['errorMessage'] = $result->get_error_message();
			return $status;
		} elseif ( is_wp_error( $skin->result ) ) {
			$status['success']      = false;
			$status['errorCode']    = $skin->result->get_error_code();
			$status['errorMessage'] = $skin->result->get_error_message();
			return $status;
		} elseif ( $skin->get_errors()->get_error_code() ) {
			$status['success']      = false;
			$status['errorMessage'] = $skin->get_error_messages();
			return $status;
		} elseif ( is_null( $result ) ) {
			global $wp_filesystem;
			$status['success']      = false;
			$status['errorCode']    = 'unable_to_connect_to_filesystem';
			$status['errorMessage'] = esc_html__( 'Unable to connect to the filesystem. Please confirm your credentials.', 'everest-forms' );

			// Pass through the error from WP_Filesystem if one was raised.
			if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->get_error_code() ) {
				$status['errorMessage'] = esc_html( $wp_filesystem->errors->get_error_message() );
			}
			return $status;
		}

		$api->version   = isset( $api->new_version ) ? $api->new_version : '';
		$install_status = install_plugin_install_status( $api );
		activate_plugin( $plugin );
		$status['success'] = true;
		$status['message'] = __( 'Addon installed Successfully', 'everest-forms' );
		return $status;
	}

	/**
	 * Retrieve addons data.
	 *
	 * @since 3.0.3
	 *
	 * @return object
	 */
	public static function get_addons_data() {
		$addons_data = evf_get_json_file_contents( 'assets/extensions-json/sections/all_extensions.json' );

		$new_product = (object) array(
			'products' => array(
				(object) array(
					'title'          => 'Everest Forms PRO',
					'slug'           => 'everest-forms-pro',
					'name'           => 'Everest Forms PRO',
					'image'          => '',
					'excerpt'        => '',
					'link'           => '',
					'released_date'  => '',
					'plan'           => array(
						'personal',
						'agency',
						'themegrill agency',
					),
					'setting_url'    => '',
					'demo_video_url' => '',
				),
			),
		);

		if ( isset( $addons_data->products ) ) {

			$existing_products = $addons_data->products;
			$new_products      = $new_product->products;

			$merged_products = array_merge(
				json_decode( json_encode( $existing_products ), true ),
				json_decode( json_encode( $new_products ), true )
			);

			$addons_data->products = json_decode( json_encode( $merged_products ) );
		}
		return apply_filters( 'everest_forms_addons_section_data', $addons_data );
	}

	/**
	 * Check if a given request has access.
	 *
	 * @since 3.0.3
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function check_admin_permissions( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		// Nonce check.
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'You do not have permissions to perform this action.', 'everest-forms' ),
				array( 'status' => 403 )
			);
		}

		// Capability check.
		if ( ! current_user_can( 'manage_everest_forms' ) && ! current_user_can( 'everest_forms_create_forms' ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'You are not allowed to access this resource.', 'everest-forms' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check whether the request may install or activate addon plugins.
	 *
	 * Installing and activating plugins is an administrator-level action, so the
	 * form-building capabilities are not enough here: the user must be able to
	 * activate plugins, matching the Modules controller. The REST nonce is not
	 * checked explicitly, so that non-cookie authentication (Application
	 * Passwords, OAuth) keeps working; core already enforces the nonce for
	 * cookie-authenticated requests, so CSRF is still covered.
	 *
	 * @since 3.6.5
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function check_plugin_management_permissions( $request ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'You are not allowed to access this resource.', 'everest-forms' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
