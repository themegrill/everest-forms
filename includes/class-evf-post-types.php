<?php
/**
 * Post Types
 *
 * Registers post types and taxonomies.
 *
 * @package EverestForms\Classes
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Post types Class.
 */
class EVF_Post_Types {

	/**
	 * Tags taxonomy.
	 *
	 * @since 3.2.0
	 */
	const TAGS_TAXONOMY = 'evf_form_tags';

	/**
	 * Hook in methods.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_menus' ), 100 );
		add_action( 'everest_forms_after_register_post_type', array( __CLASS__, 'maybe_flush_rewrite_rules' ) );
		add_action( 'everest_forms_flush_rewrite_rules', array( __CLASS__, 'flush_rewrite_rules' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'preserve_custom_code' ), 10, 2 );
	}

	/**
	 * Keep a form's stored custom CSS and JavaScript when the saving user cannot add unfiltered HTML.
	 *
	 * @since 3.6.4
	 *
	 * @param array $data    Slashed post data to be saved.
	 * @param array $postarr Slashed post data passed to wp_insert_post().
	 * @return array
	 */
	public static function preserve_custom_code( $data, $postarr ) {
		if ( 'everest_form' !== $data['post_type'] || ! is_user_logged_in() || current_user_can( 'unfiltered_html' ) ) {
			return $data;
		}

		$form_data = json_decode( wp_unslash( $data['post_content'] ), true );

		if ( ! is_array( $form_data ) || ( isset( $form_data['settings'] ) && ! is_array( $form_data['settings'] ) ) ) {
			return $data;
		}

		$stored  = empty( $postarr['ID'] ) ? array() : evf_decode( get_post_field( 'post_content', absint( $postarr['ID'] ), 'raw' ) );
		$changed = false;

		foreach ( array( 'evf-enable-custom-css', 'evf-custom-css', 'evf-enable-custom-js', 'evf-custom-js' ) as $key ) {
			$old = isset( $stored['settings'][ $key ] ) ? $stored['settings'][ $key ] : null;
			$new = isset( $form_data['settings'][ $key ] ) ? $form_data['settings'][ $key ] : null;

			if ( $old === $new ) {
				continue;
			}

			$changed = true;

			if ( null === $old ) {
				unset( $form_data['settings'][ $key ] );
			} else {
				$form_data['settings'][ $key ] = $old;
			}
		}

		if ( $changed ) {
			$data['post_content'] = evf_encode( $form_data );
		}

		return $data;
	}

	/**
	 * Register core post types.
	 */
	public static function register_post_types() {
		if ( ! is_blog_installed() || post_type_exists( 'everest_form' ) ) {
			return;
		}

		do_action( 'everest_forms_register_post_type' );

		register_post_type(
			'everest_form',
			apply_filters(
				'everest_forms_register_post_type_product',
				array(
					'labels'              => array(
						'name'                  => __( 'Everest Forms', 'everest-forms' ),
						'singular_name'         => __( 'Form', 'everest-forms' ),
						'all_items'             => __( 'All Forms', 'everest-forms' ),
						'menu_name'             => _x( 'Forms', 'Admin menu name', 'everest-forms' ),
						'add_new'               => __( 'Add New', 'everest-forms' ),
						'add_new_item'          => __( 'Add new form', 'everest-forms' ),
						'edit'                  => __( 'Edit', 'everest-forms' ),
						'edit_item'             => __( 'Edit form', 'everest-forms' ),
						'new_item'              => __( 'New form', 'everest-forms' ),
						'view_item'             => __( 'View form', 'everest-forms' ),
						'search_items'          => __( 'Search forms', 'everest-forms' ),
						'not_found'             => __( 'No forms found', 'everest-forms' ),
						'not_found_in_trash'    => __( 'No forms found in trash', 'everest-forms' ),
						'parent'                => __( 'Parent forms', 'everest-forms' ),
						'featured_image'        => __( 'Form image', 'everest-forms' ),
						'set_featured_image'    => __( 'Set form image', 'everest-forms' ),
						'remove_featured_image' => __( 'Remove form image', 'everest-forms' ),
						'use_featured_image'    => __( 'Use as form image', 'everest-forms' ),
						'insert_into_item'      => __( 'Insert into form', 'everest-forms' ),
						'uploaded_to_this_item' => __( 'Uploaded to this form', 'everest-forms' ),
						'filter_items_list'     => __( 'Filter forms', 'everest-forms' ),
						'items_list_navigation' => __( 'Forms navigation', 'everest-forms' ),
						'items_list'            => __( 'Forms list', 'everest-forms' ),
					),
					'public'              => false,
					'show_ui'             => true,
					'description'         => __( 'This is where you can add new forms.', 'everest-forms' ),
					'capability_type'     => 'post',
					'capabilities'        => array(
						'create_posts'           => 'everest_forms_create_forms',
						'publish_posts'          => 'everest_forms_create_forms',
						'edit_posts'             => 'everest_forms_edit_forms',
						'edit_published_posts'   => 'everest_forms_edit_forms',
						'edit_others_posts'      => 'everest_forms_edit_others_forms',
						'edit_private_posts'     => 'everest_forms_edit_others_forms',
						'read_private_posts'     => 'everest_forms_view_others_forms',
						'delete_posts'           => 'everest_forms_delete_forms',
						'delete_published_posts' => 'everest_forms_delete_forms',
						'delete_others_posts'    => 'everest_forms_delete_others_forms',
						'delete_private_posts'   => 'everest_forms_delete_others_forms',
					),
					'map_meta_cap'        => true,
					'publicly_queryable'  => false,
					'exclude_from_search' => true,
					'show_in_rest'        => true,
					'show_in_menu'        => false,
					'hierarchical'        => false,
					'rewrite'             => false,
					'query_var'           => false,
					'supports'            => false,
					'show_in_nav_menus'   => false,
					'show_in_admin_bar'   => false,
				)
			)
		);

		do_action( 'everest_forms_after_register_post_type' );
	}

	/**
	 * Add "Everest Forms" link in admin bar main menu.
	 *
	 * @since 1.2.0
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public static function admin_bar_menus( $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_everest_forms' ) ) {
			return;
		}

		// Show only when the user is a member of this site, or they're a super admin.
		if ( ! is_user_member_of_blog() && ! is_super_admin() ) {
			return;
		}

		// Add an option to create new form.
		if ( apply_filters( 'everest_forms_show_admin_bar_menus', true ) ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'new-content',
					'id'     => 'everest-forms',
					'title'  => __( 'Everest Forms', 'everest-forms' ),
					'href'   => admin_url( 'admin.php?page=evf-builder&create-form=1' ),
				)
			);
		}
	}

	/**
	 * Flush rules if the event is queued.
	 *
	 * @since 1.2.0
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( 'yes' === get_option( 'everest_forms_queue_flush_rewrite_rules' ) ) {
			update_option( 'everest_forms_queue_flush_rewrite_rules', 'no' );
			self::flush_rewrite_rules();
		}
	}

	/**
	 * Flush rewrite rules.
	 */
	public static function flush_rewrite_rules() {
		flush_rewrite_rules();
	}


	/**
	 * Register the new taxonomy for tags.
	 *
	 * @since 3.2.0
	 */
	public static function register_taxonomy() {

		/**
		 * Filters Tags taxonomy arguments.
		 *
		 * @since 3.2.0
		 *
		 * @param array $args Arguments.
		 */
		$args = apply_filters(
			'evf_form_handler_register_taxonomy_args',
			array(
				'hierarchical' => false,
				'rewrite'      => false,
				'public'       => false,
			)
		);

		register_taxonomy( self::TAGS_TAXONOMY, 'everest_form', $args );
	}

}

EVF_Post_Types::init();
