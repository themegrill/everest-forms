<?php
/**
 * Regression tests for the coupon-data field overwrite fix.
 *
 * Patchstack report (ID 39662): applied_coupons_data was appended to the stored
 * value of whatever field_id it named, letting an unauthenticated visitor write
 * arbitrary content into any field (for example a file-upload field). Coupon data
 * must only ever be applied to a field configured as `payment-coupon`.
 *
 * @package EverestForms\Tests
 * @since   3.6.5
 */

/**
 * EVF_Form_Task_Coupon_Security_Test class.
 */
class EVF_Form_Task_Coupon_Security_Test extends WP_UnitTestCase {

	/**
	 * Post ID of the real `everest_form` used to drive EVF_Form_Task::do_task().
	 *
	 * @var int
	 */
	protected $form_id;

	/**
	 * Trusted form definition, kept in sync with the form's post_content.
	 *
	 * @var array
	 */
	protected $form_data;

	/**
	 * Coupon field instance whose is_pro flag was toggled, to restore after.
	 *
	 * @var object|null
	 */
	protected $unlocked_coupon_field = null;

	/**
	 * Setup test.
	 */
	public function setUp() {
		parent::setUp();

		$this->form_id = $this->factory->post->create(
			array(
				'post_type'   => 'everest_form',
				'post_status' => 'publish',
				'post_title'  => 'Coupon Security Test Form',
			)
		);

		$this->form_data = array(
			'id'          => $this->form_id,
			'form_fields' => array(
				'msg'    => array(
					'id'       => 'msg',
					'type'     => 'text',
					'label'    => 'Message',
					'meta-key' => 'message',
				),
				'upload' => array(
					'id'       => 'upload',
					'type'     => 'file-upload',
					'label'    => 'Upload',
					'meta-key' => 'file_upload',
				),
				'cpn'    => array(
					'id'       => 'cpn',
					'type'     => 'payment-coupon',
					'label'    => 'Coupon',
					'meta-key' => 'coupon',
				),
			),
			'settings'    => array(
				'form_title'           => 'Coupon Security Test Form',
				'ajax_form_submission' => '0',
			),
		);

		$this->save_form_data();

		// Isolate the persistence behaviour under test from any coupon-validation
		// add-on, which would otherwise fail the submission before persistence runs.
		remove_all_actions( 'everest_forms_process_validate_payment-coupon' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown() {
		if ( $this->unlocked_coupon_field ) {
			$this->unlocked_coupon_field->is_pro = true;
			$this->unlocked_coupon_field         = null;
		}
		wp_delete_post( $this->form_id, true );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Simulates the Coupons add-on being active by unlocking the payment-coupon
	 * field, so it is processed instead of being stripped as a locked Pro field.
	 */
	protected function activate_coupon_field() {
		foreach ( evf()->form_fields->form_fields as $group ) {
			foreach ( $group as $field ) {
				if ( 'payment-coupon' === $field->type ) {
					$field->is_pro               = false;
					$this->unlocked_coupon_field = $field;
				}
			}
		}
	}

	/**
	 * Persists $this->form_data as the form's post_content, as the builder would.
	 */
	protected function save_form_data() {
		wp_update_post(
			array(
				'ID'           => $this->form_id,
				'post_content' => evf_encode( $this->form_data ),
			)
		);
	}

	/**
	 * Submits the form through the real entry point, EVF_Form_Task::do_task(),
	 * optionally with an applied_coupons_data payload in $_POST.
	 *
	 * @param array      $submitted_fields Field id => submitted value.
	 * @param array|null $applied_coupons  Coupon payload, or null to omit it.
	 * @return EVF_Form_Task
	 */
	protected function submit( $submitted_fields, $applied_coupons = null ) {
		$_POST                                = array();
		$_POST[ '_wpnonce' . $this->form_id ] = wp_create_nonce( 'everest-forms_process_submit' );

		if ( null !== $applied_coupons ) {
			$_POST['applied_coupons_data'] = wp_slash( wp_json_encode( $applied_coupons ) );
		}

		$task              = evf()->task;
		$task->form_fields = array();
		$task->do_task(
			array(
				'id'          => $this->form_id,
				'form_fields' => $submitted_fields,
			)
		);

		return $task;
	}

	/**
	 * Reads a persisted entry meta value back from the database.
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	protected function get_entry_meta_value( $entry_id, $meta_key ) {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}evf_entrymeta WHERE entry_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entry_id,
				$meta_key
			)
		);

		return null === $value ? null : maybe_unserialize( $value );
	}

	/**
	 * A coupon payload that targets a file-upload field must be ignored, so an
	 * attacker cannot write paths into the upload field's stored value.
	 */
	public function test_coupon_data_cannot_overwrite_file_upload_field() {
		$task = $this->submit(
			array( 'msg' => 'hello' ),
			array(
				array(
					'field_id' => 'upload',
					'code'     => "\n/a/uploads/../evil.txt\n",
				),
			)
		);

		$this->assertEmpty( $task->errors, 'Submission unexpectedly failed: ' . wp_json_encode( $task->errors ) );

		$upload_value = isset( $task->form_fields['upload']['value'] ) ? $task->form_fields['upload']['value'] : '';
		$this->assertNotContains( "\n/a/uploads/../evil.txt\n", (array) $upload_value );
		$this->assertSame( '', $upload_value );

		$this->assertGreaterThan( 0, $task->entry_id );
		$stored = $this->get_entry_meta_value( $task->entry_id, 'file_upload' );
		$this->assertNotContains( '/a/uploads/../evil.txt', (array) $stored );
	}

	/**
	 * A coupon payload that targets an ordinary text field must be ignored.
	 */
	public function test_coupon_data_cannot_overwrite_text_field() {
		$task = $this->submit(
			array( 'msg' => 'hello' ),
			array(
				array(
					'field_id' => 'msg',
					'code'     => 'INJECTED',
				),
			)
		);

		$this->assertEmpty( $task->errors, 'Submission unexpectedly failed: ' . wp_json_encode( $task->errors ) );
		$this->assertSame( 'hello', $task->form_fields['msg']['value'] );
	}

	/**
	 * A coupon payload that targets a real payment-coupon field must still be
	 * stored, so the fix does not break legitimate coupon submissions.
	 */
	public function test_coupon_data_is_applied_to_coupon_field() {
		$this->activate_coupon_field();

		$coupon = array(
			'field_id' => 'cpn',
			'code'     => 'SAVE20',
			'amount'   => 20,
		);

		$task = $this->submit(
			array(
				'msg' => 'hello',
				'cpn' => 'SAVE20',
			),
			array( $coupon )
		);

		$this->assertEmpty( $task->errors, 'Submission unexpectedly failed: ' . wp_json_encode( $task->errors ) );
		$this->assertTrue( is_array( $task->form_fields['cpn']['value'] ) );

		$stored_codes = wp_list_pluck( $task->form_fields['cpn']['value'], 'code' );
		$this->assertContains( 'SAVE20', $stored_codes );
	}
}
