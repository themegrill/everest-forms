<?php
/**
 * Setup menus in WP admin.
 *
 * @package EverestForms\Admin
 * @version 1.2.0
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'EVF_Admin_Menus', false ) ) {
	return new EVF_Admin_Menus();
}

/**
 * EVF_Admin_Menus Class.
 */
class EVF_Admin_Menus {

	/**
	 * Hook in tabs.
	 */
	public function __construct() {
		// Add menus.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'dashboard_menu' ), 9 );
		add_action( 'admin_menu', array( $this, 'builder_menu' ), 20 );
		add_action( 'admin_menu', array( $this, 'entries_menu' ), 30 );
		add_action( 'admin_menu', array( $this, 'payments_menu' ), 55 );
		add_action( 'admin_menu', array( $this, 'analytics_menu' ), 10 );
		add_action( 'admin_menu', array( $this, 'settings_menu' ), 50 );
		add_action( 'admin_menu', array( $this, 'tools_menu' ), 60 );
		add_action( 'admin_menu', array( $this, 'smtp_menu' ), 75 );
		// Add admin topbar menu.
		add_action( 'admin_bar_menu', array( $this, 'admin_top_menu_bar' ), 100 );

		if ( apply_filters( 'everest_forms_show_addons_page', true ) ) {
			add_action( 'admin_menu', array( $this, 'addons_menu' ), 70 );
		}

		if ( ! evf_get_license_plan() ) {
			add_action( 'admin_menu', array( $this, 'upgrade_to_pro_menu' ), 80 );
		}

		add_action( 'admin_head', array( $this, 'menu_highlight' ) );
		add_action( 'admin_head', array( $this, 'custom_menu_count' ) );
		add_action( 'admin_head', array( $this, 'hide_submenu_items' ) );
		add_filter( 'custom_menu_order', array( $this, 'custom_menu_order' ) );
		add_filter( 'set-screen-option', array( $this, 'set_screen_option' ), 11, 3 );
	}

	/**
	 * Returns a base64 URL for the SVG for use in the menu.
	 *
	 * @param  string $fill   SVG Fill color code. Default: '#82878c'.
	 * @param  bool   $base64 Whether or not to return base64-encoded SVG.
	 * @return string
	 */
	public static function get_icon_svg( $fill = '#82878c', $base64 = true ) {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><g><path fill="' . $fill . '" d="M18.1 4h-3.8l1.2 2h3.9zM20.6 8h-3.9l1.2 2h3.9zM20.6 18H5.8L12 7.9l2.5 4.1H12l-1.2 2h7.3L12 4.1 2.2 20h19.6z"/></g></svg>';

		if ( $base64 ) {
			return 'data:image/svg+xml;base64,' . base64_encode( $svg );
		}

		return $svg;
	}

	/**
	 * Admin top menu bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Instance of admin bar.
	 */
	public function admin_top_menu_bar( WP_Admin_Bar $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_everest_forms' ) ) {
			return;
		}

		$wp_admin_bar->add_menu(
			array(
				'id'     => 'everest-forms-menu',
				'parent' => null,
				'group'  => null,
				'title'  => 'Everest Forms', // you can use img tag with image link. it will show the image icon Instead of the title.
				'href'   => admin_url( 'admin.php?page=evf-builder' ),
			)
		);

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'everest-forms-menu',
				'id'     => 'everest-forms-all-forms',
				'title'  => __( 'All Forms', 'everest-forms' ),
				'href'   => admin_url( 'admin.php?page=evf-builder' ),
			)
		);

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'everest-forms-menu',
				'id'     => 'everest-forms-add-new',
				'title'  => __( 'Add New', 'everest-forms' ),
				'href'   => admin_url( 'admin.php?page=evf-builder&create-form=1' ),
			)
		);

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'everest-forms-menu',
				'id'     => 'everest-forms-entries',
				'title'  => __( 'Entries', 'everest-forms' ),
				'href'   => admin_url( 'admin.php?page=evf-entries' ),
			)
		);

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'everest-forms-menu',
				'id'     => 'everest-forms-tools',
				'title'  => __( 'Tools', 'everest-forms' ),
				'href'   => admin_url( 'admin.php?page=evf-tools' ),
			)
		);

		$href = add_query_arg(
			array(
				'utm_medium'  => 'admin-bar',
				'utm_source'  => 'WordPress',
				'utm_content' => 'Documentation',
			),
			'https://docs.everestforms.net/'
		);

		$wp_admin_bar->add_menu(
			array(
				'parent' => 'everest-forms-menu',
				'id'     => 'everest-forms-docs',
				'title'  => __( 'Docs', 'everest-forms' ),
				'href'   => $href,
				'meta'   => array(
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				),
			)
		);

		do_action( 'everest_forms_top_admin_bar_menu', $wp_admin_bar );
	}

	/**
	 * Add menu items.
	 */
	public function admin_menu() {
		add_menu_page( esc_html__( 'Everest Forms', 'everest-forms' ), esc_html__( 'Everest Forms', 'everest-forms' ), 'manage_everest_forms', 'everest-forms', null, self::get_icon_svg(), '55.5' );
	}

	/**
	 * Add dashboard sub menu.
	 */
	public function dashboard_menu() {

		$site_assistant_data = get_option( 'everest_forms_site_assistant', array() );
		$all_steps_completed = isset( $site_assistant_data['all_steps_completed'] ) && $site_assistant_data['all_steps_completed'];
		add_submenu_page(
			'everest-forms',
			__( 'Site Assistant', 'everest-forms' ),
			__( 'Site Assistant', 'everest-forms' ),
			'manage_everest_forms',
			'evf-dashboard',
			array( $this, 'dashboard_page' ),
			-1
		);

	}

	/**
	 * Add menu items.
	 */
	public function builder_menu() {
		$capability = current_user_can( 'everest_forms_create_forms' ) ? 'everest_forms_create_forms' : ( current_user_can( 'manage_everest_forms' ) ? 'manage_everest_forms' : 'everest_forms_view_forms' );
		$builder_page = add_submenu_page( 'everest-forms', esc_html__( 'Everest Forms Builder', 'everest-forms' ), esc_html__( 'All Forms', 'everest-forms' ), $capability, 'evf-builder', array( $this, 'builder_page' ) );

		add_action( 'load-' . $builder_page, array( $this, 'builder_page_init' ) );

		/*
		 * Page redirects based on user's capability as 'All Forms' and 'Add New' both have same handle.
		 *
		 * - If only `everest_forms_create_forms` roles - dont show view all forms list table.
		 * - If only `everest_forms_view_forms` roles - dont show create new template selection.
		 */
		// if ( ! current_user_can( 'manage_everest_forms' ) ) {
		// 	if ( ! current_user_can( 'everest_forms_create_forms' ) ) {
		// 		if ( isset( $_GET['page'], $_GET['create-form'] ) && 'evf-builder' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		// 			wp_safe_redirect( admin_url( 'admin.php?page=evf-builder' ) );
		// 			exit;
		// 		}
		// 	} elseif ( ! current_user_can( 'everest_forms_view_forms' ) ) {
		// 		if ( ! isset( $_GET['create-form'] ) && ( ! empty( $_GET['page'] ) && 'evf-builder' === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		// 			wp_safe_redirect( admin_url( 'admin.php?page=evf-builder&create-form=1' ) );
		// 			exit;
		// 		}
		// 	}
		// }
	}

	/**
	 * Loads builder page.
	 */
	public function builder_page_init() {
		global $current_tab, $forms_table_list;

		evf()->form_fields();

		// Include builder pages.
		EVF_Admin_Builder::get_builder_pages();

		// Get current tab/section.
		$current_tab = empty( $_GET['tab'] ) ? 'fields' : sanitize_title( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! isset( $_GET['tab'], $_GET['form_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$forms_table_list = new EVF_Admin_Forms_Table_List();

			// Add screen option.
			add_screen_option(
				'per_page',
				array(
					'default' => 20,
					'option'  => 'evf_forms_per_page',
				)
			);
		}

		do_action( 'everest_forms_builder_page_init' );
	}

	/**
	 * Add menu item.
	 */
	public function entries_menu() {
		$entries_page = add_submenu_page( 'everest-forms', esc_html__( 'Everest Forms Entries', 'everest-forms' ), esc_html__( 'Entries', 'everest-forms' ), current_user_can( 'everest_forms_view_entries' ) ? 'everest_forms_view_entries' : 'everest_forms_view_others_entries', 'evf-entries', array( $this, 'entries_page' ) );
		add_action( 'load-' . $entries_page, array( $this, 'entries_page_init' ) );
	}

	/**
	 * Loads entries into memory.
	 */
	public function entries_page_init() {
		global $entries_table_list;

		if ( ! isset( $_GET['view-entry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$entries_table_list = new EVF_Admin_Entries_Table_List();

			// Add screen option.
			add_screen_option(
				'per_page',
				array(
					'default' => 20,
					'option'  => 'evf_entries_per_page',
				)
			);
		}

		do_action( 'everest_forms_entries_page_init' );
	}

	/**
	 * Add Payments sub menu (free version only; Pro plugin registers its own evf-payments page).
	 */
	public function payments_menu() {
		if ( defined( 'EFP_PLUGIN_FILE' ) ) {
			return;
		}
		add_submenu_page(
			'everest-forms',
			esc_html__( 'Payments', 'everest-forms' ),
			esc_html__( 'Payments', 'everest-forms' ),
			'manage_everest_forms',
			'evf-payment-log',
			array( $this, 'payments_page' )
		);
	}

	/**
	 * Payments upsell page callback (free version).
	 */
	public function payments_page() {
		include dirname( __FILE__ ) . '/views/html-admin-page-payments.php';
	}

	/**
	 * Add Analytics sub menu (free version only; Pro plugin registers its own evf-analytics page).
	 */
	public function analytics_menu() {
		if ( defined( 'EFP_PLUGIN_FILE' ) ) {
			return;
		}
		add_submenu_page(
			'everest-forms',
			esc_html__( 'Analytics', 'everest-forms' ),
			esc_html__( 'Analytics', 'everest-forms' ),
			'manage_everest_forms',
			'evf-analytics',
			array( $this, 'analytics_page' )
		);
	}

	/**
	 * Analytics page callback (free version).
	 */
	public function analytics_page() {
		echo '<div id="evf-react-header-root"></div><div class="wrap"><div id="evf-analytics-root"></div></div>';
	}

	/**
	 * Add menu item.
	 */
	public function settings_menu() {
		$settings_page = add_submenu_page( 'everest-forms', esc_html__( 'Everest Forms settings', 'everest-forms' ), esc_html__( 'Settings', 'everest-forms' ), 'manage_everest_forms', 'evf-settings', array( $this, 'settings_page' ) );

		add_action( 'load-' . $settings_page, array( $this, 'settings_page_init' ) );
	}

	/**
	 * Loads settings page.
	 */
	public function settings_page_init() {
		global $current_tab, $current_section;

		// Include settings pages.
		EVF_Admin_Settings::get_settings_pages();

		// Get current tab/section.
		$current_tab     = empty( $_GET['tab'] ) ? 'general' : sanitize_title( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$current_section = empty( $_REQUEST['section'] ) ? '' : sanitize_title( wp_unslash( $_REQUEST['section'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// Save settings if data has been posted.
		if ( apply_filters( '' !== $current_section ? "everest_forms_save_settings_{$current_tab}_{$current_section}" : "everest_forms_save_settings_{$current_tab}", ! empty( $_POST ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			EVF_Admin_Settings::save();
		}

		// Add any posted messages. These come from the URL, so they are plain text only — never markup.
		if ( ! empty( $_GET['evf_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			EVF_Admin_Settings::add_error( sanitize_text_field( wp_unslash( $_GET['evf_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( ! empty( $_GET['evf_message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			EVF_Admin_Settings::add_message( sanitize_text_field( wp_unslash( $_GET['evf_message'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		do_action( 'everest_forms_settings_page_init' );
	}

	/**
	 * Adds SMTP into Everest Forms Menu.
	 *
	 * @since 3.0.9
	 */
	public function smtp_menu() {
		add_submenu_page( 'everest-forms', esc_html__( 'SMTP', 'everest-forms' ), esc_html__( 'SMTP', 'everest-forms' ), 'manage_everest_forms', 'evf-smart-smtp', array( $this, 'smtp_page' ) );
	}

	/**
	 * Add menu item.
	 */
	public function tools_menu() {
		add_submenu_page( 'everest-forms', esc_html__( 'Everest Forms tools', 'everest-forms' ), esc_html__( 'Tools', 'everest-forms' ), 'manage_everest_forms', 'evf-tools', array( $this, 'tools_page' ) );
	}

	/**
	 * Add menu item.
	 */
	public function upgrade_to_pro_menu() {
		add_submenu_page(
			'everest-forms',
			esc_html__( 'Everest Forms Upgrade to Pro', 'everest-forms' ),
			sprintf(
				'<span style="color:#FF8C39; display:flex; font-weight: 600;"><svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: bottom;" ><rect x="0.5" y="0.5" width="19" height="19" rx="2.5" fill="#FF8C39" stroke="#FF8C39"/><path d="M10 5L13 13H7L10 5Z" fill="#EFEFEF"/><path fill="white" fill-rule="evenodd" d="M5 7L5.71429 13H14.2857L15 7L10 11.125L5 7ZM14.2857 13.5714H5.71427V15H14.2857V13.5714Z" clip-rule="evenodd"/></svg><span style="margin-left:5px;">%s</span></span>',
				esc_html__( 'Upgrade to Pro', 'everest-forms' )
			),
			'manage_everest_forms',
			esc_url_raw( 'https://everestforms.net/upgrade/?utm_medium=evf-dashboard&utm_source=evf-free&utm_campaign=dash-wp-sub-menu&utm_content=Upgrade%20to%20Pro' )
		);
	}

	/**
	 * Addons menu item.
	 */
	public function addons_menu() {
		add_submenu_page( 'everest-forms', esc_html__( 'Everest Forms Add-ons', 'everest-forms' ), esc_html__( 'Addons', 'everest-forms' ), 'manage_everest_forms', esc_url_raw( admin_url( 'admin.php?page=evf-dashboard#/features' ) ) );
	}

	/**
	 * Highlights the correct top level admin menu item.
	 */
	public function menu_highlight() {
		global $parent_file, $submenu_file;

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		// Check to make sure we're on a EverestForms builder setup page.
		if ( isset( $_GET['create-form'] ) && in_array( $screen_id, array( 'everest-forms_page_evf-builder' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$parent_file  = 'everest-forms'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			$submenu_file = 'evf-builder&create-form=1'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	/**
	 * Adds the custom count to the menu.
	 */
	public function custom_menu_count() {
		global $submenu;

		// Add count if user has access.
		if ( isset( $submenu['everest-forms'] ) ) {
			if ( apply_filters( 'everest_forms_include_count_in_menu', true ) && current_user_can( 'manage_everest_forms' ) ) {
				do_action( 'everest_forms_custom_menu_count' );
			}
		}
	}

	/**
	 * Hide submenu menu item if a user can't access.
	 *
	 * @since 1.7.5
	 */
	public function hide_submenu_items() {
		global $submenu;

		if ( ! isset( $submenu['everest-forms'] ) ) {
			return;
		}

		// Remove 'Everest Forms' sub menu item.
		foreach ( $submenu['everest-forms'] as $key => $item ) {
			if ( isset( $item[2] ) && 'everest-forms' === $item[2] ) {
				unset( $submenu['everest-forms'][ $key ] );
				break;
			}
		}

		// Remove 'All Forms' sub menu item if a user can't read forms.
		if ( ! current_user_can( 'everest_forms_view_forms' ) && ! current_user_can( 'everest_forms_create_forms' ) && ! current_user_can( 'manage_everest_forms' ) ) {
			foreach ( $submenu['everest-forms'] as $key => $item ) {
				if ( isset( $item[2] ) && 'evf-builder' === $item[2] ) {
					unset( $submenu['everest-forms'][ $key ] );
					break;
				}
			}
		}

		// Remove 'Add New' sub menu item if a user can't create forms.
		if ( ! current_user_can( 'everest_forms_create_forms' ) ) {
			foreach ( $submenu['everest-forms'] as $key => $item ) {
				if ( isset( $item[2] ) && 'evf-builder&create-form=1' === $item[2] ) {
					unset( $submenu['everest-forms'][ $key ] );
					break;
				}
			}
		}
	}

	/**
	 * Custom menu order.
	 *
	 * @param  bool $enabled Whether custom menu ordering is already enabled.
	 * @return bool
	 */
	public function custom_menu_order( $enabled ) {
		return $enabled || current_user_can( 'manage_everest_forms' );
	}

	/**
	 * Validate screen options on update.
	 *
	 * @param bool|int $status Screen option value. Default false to skip.
	 * @param string   $option The option name.
	 * @param int      $value  The number of rows to use.
	 */
	public function set_screen_option( $status, $option, $value ) {
		if ( in_array( $option, array( 'evf_forms_per_page', 'evf_entries_per_page' ), true ) ) {
			return $value;
		}

		return $status;
	}

	/**
	 * Init the settings page.
	 */
	public function dashboard_page() {
		EVF_Admin_Dashboard::page_output();
	}

	/**
	 * Init the settings page.
	 */
	public function builder_page() {
		EVF_Admin_Forms::page_output();
	}

	/**
	 * Init the entries page.
	 */
	public function entries_page() {
		EVF_Admin_Entries::page_output();
	}

	/**
	 * Init the settings page.
	 */
	public function settings_page() {
		EVF_Admin_Settings::output();
	}

	/**
	 * Init the status page.
	 */
	public function tools_page() {
		EVF_Admin_Tools::output();
	}

	/**
	 * Smart SMTP page
	 */
	public function smtp_page() {
		EVF_Admin_Tools::evf_smart_smtp_setup();
	}
}

return new EVF_Admin_Menus();
