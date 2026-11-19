<?php
/**
 * Regression tests for the plugin install/activate REST authorization.
 *
 * @package EverestForms\Tests
 * @since   3.6.5
 */

/**
 * EVF_Plugin_Status_Permissions_Test class.
 */
class EVF_Plugin_Status_Permissions_Test extends WP_UnitTestCase {

	/**
	 * Controller under test.
	 *
	 * @var Everest_Forms_Plugin_Status
	 */
	protected $controller;

	/**
	 * Create the controller.
	 *
	 * @before
	 */
	public function create_fixtures() {
		$this->controller = new Everest_Forms_Plugin_Status();
	}

	/**
	 * Build a request carrying a valid REST nonce for the current user.
	 *
	 * @return WP_REST_Request
	 */
	protected function request() {
		$request = new WP_REST_Request( 'POST', '/everest-forms/v1/plugin/activate' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		return $request;
	}

	/**
	 * A user who can build forms but cannot activate plugins is rejected.
	 */
	public function test_form_creator_cannot_manage_plugins() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'everest_forms_create_forms' );
		wp_set_current_user( $user->ID );

		$result = $this->controller->check_plugin_management_permissions( $this->request() );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A user with the activate_plugins capability is allowed.
	 */
	public function test_admin_can_manage_plugins() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( $this->controller->check_plugin_management_permissions( $this->request() ) );
	}

	/**
	 * Non-cookie auth (no REST nonce) still works for a capable user, so
	 * Application Passwords and OAuth are not broken by the permission check.
	 */
	public function test_admin_without_nonce_is_allowed() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/everest-forms/v1/plugin/activate' );

		$this->assertTrue( $this->controller->check_plugin_management_permissions( $request ) );
	}

	/**
	 * Dispatch a POST to one of the plugin routes through the REST server.
	 *
	 * @param string $route Route path after the namespace, e.g. 'plugin/upgrade'.
	 * @param array  $body  Request body parameters.
	 * @return WP_REST_Response
	 */
	protected function dispatch( $route, $body ) {
		$server = rest_get_server();
		$this->controller->register_routes();

		$request = new WP_REST_Request( 'POST', '/everest-forms/v1/' . $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( $body );

		return $server->dispatch( $request );
	}

	/**
	 * A form creator must still reach the read-only Pro check that form
	 * templates call first, or "Use this template" fails for them.
	 */
	public function test_form_creator_can_check_pro_status_for_templates() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$user->add_cap( 'everest_forms_create_forms' );
		wp_set_current_user( $user->ID );

		$response = $this->dispatch( 'plugin/upgrade', array( 'requiredPlugins' => array( 'everest-forms-pro' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'plugin_status', $response->get_data() );
	}

	/**
	 * The same form creator is still refused by the route that activates plugins.
	 */
	public function test_form_creator_cannot_activate_through_rest() {
		$user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$user->add_cap( 'everest_forms_create_forms' );
		wp_set_current_user( $user->ID );

		$response = $this->dispatch(
			'plugin/activate',
			array(
				'moduleData' => array(
					array(
						'type' => 'addon',
						'slug' => 'akismet',
					),
				),
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * The allowlist contains Everest Forms addons but not arbitrary plugins.
	 */
	public function test_allowlist_excludes_arbitrary_plugins() {
		$allowed = EVF_Modules::get_allowed_addon_slugs();

		// The security invariant: the allowlist exists and never contains an
		// arbitrary non-addon plugin such as Akismet.
		$this->assertNotEmpty( $allowed );
		$this->assertNotContains( 'akismet', $allowed );
	}

	/**
	 * Installing/activating a slug that is not an Everest Forms addon is refused.
	 */
	public function test_install_rejects_non_addon_slug() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$status = Everest_Forms_Plugin_Status::install_individual_addon(
			'akismet',
			'akismet/akismet.php',
			'Akismet',
			array(
				'install' => 'plugin',
				'slug'    => 'akismet',
			)
		);

		$this->assertFalse( $status['success'] );
		$this->assertSame( 'invalid_addon_slug', $status['errorCode'] );
		$this->assertFalse( is_plugin_active( 'akismet/akismet.php' ) );
	}
}
