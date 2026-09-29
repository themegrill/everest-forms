<?php
/**
 * Regression tests for the smart-tag injection fix.
 *
 * Wordfence report: Everest Forms <= 3.6.1 - Unauthenticated Sensitive
 * Information Exposure via Smart-Tag Injection in Form Field Values.
 * Fixed in https://github.com/themegrill/everest-forms/pull/1667 and
 * hardened further after the reported bypass.
 *
 * @since 3.6.3
 */
class EVF_Smart_Tags_Security_Test extends WP_UnitTestCase {

	/**
	 * Post used as the smart tag's post_meta target.
	 *
	 * @var int
	 */
	protected $post_id;

	/**
	 * Post ID of the real `everest_form` used to drive EVF_Form_Task::do_task().
	 *
	 * @var int
	 */
	protected $form_id;

	/**
	 * Minimal trusted form definition, kept in sync with the form's own
	 * post_content via save_form_data().
	 *
	 * @var array
	 */
	protected $form_data;

	/**
	 * Setup test.
	 */
	public function setUp() {
		parent::setUp();

		$this->post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $this->post_id, 'mailchimp_api_key', 'mc-LIVE-SECRET-VALUE' );
		update_post_meta( $this->post_id, '_protected_secret', 'should-never-leak' );

		$this->form_id = $this->factory->post->create(
			array(
				'post_type'   => 'everest_form',
				'post_status' => 'publish',
				'post_title'  => 'Smart Tag Security Test Form',
			)
		);

		$this->form_data = array(
			'id'          => $this->form_id,
			'form_fields' => array(
				'1' => array(
					'id'            => '1',
					'type'          => 'hidden',
					'label'         => 'UTM Source',
					'meta-key'      => 'utm_source',
					'default_value' => 'organic',
				),
				'2' => array(
					'id'       => '2',
					'type'     => 'text',
					'label'    => 'Message',
					'meta-key' => 'message',
				),
			),
			'settings'    => array(
				'form_title'           => 'Smart Tag Security Test Form',
				'ajax_form_submission' => '0',
			),
		);

		$this->save_form_data();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown() {
		wp_delete_post( $this->post_id, true );
		wp_delete_post( $this->form_id, true );
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Persists $this->form_data as the form's post_content, exactly as the
	 * builder would save it.
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
	 * Submits the form through the real production entry point --
	 * EVF_Form_Task::do_task(), which on success also calls entry_save() --
	 * using the process-wide evf()->task singleton so that field-type
	 * validate()/format() hooks (which write to evf()->task->form_fields,
	 * not to an arbitrary instance) land on the same object this test reads
	 * back from.
	 *
	 * @param array $submitted_fields Field id => submitted value.
	 * @return EVF_Form_Task
	 */
	protected function submit( $submitted_fields ) {
		$_POST[ '_wpnonce' . $this->form_id ] = wp_create_nonce( 'everest-forms_process_submit' );

		$task = evf()->task;
		$task->do_task(
			array(
				'id'          => $this->form_id,
				'form_fields' => $submitted_fields,
			)
		);

		return $task;
	}

	/**
	 * Reads a persisted entry meta value back from the database, the way
	 * entry_save() actually stored it.
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $meta_key Meta key.
	 * @return string|null
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
	 * An attacker POSTing a smart tag directly into a Hidden field's value
	 * must never have it resolved -- only the field's own trusted
	 * default_value may ever reach the smart tag parser. Exercises the real
	 * do_task() -> entry_save() path, not a copy of its logic.
	 */
	public function test_hidden_field_post_payload_is_not_resolved() {
		$task = $this->submit(
			array(
				'1' => '{post_meta key=mailchimp_api_key}',
				'2' => 'hello',
			)
		);

		$this->assertEmpty( $task->errors, 'Submission unexpectedly failed validation: ' . wp_json_encode( $task->errors ) );
		$this->assertSame( '{post_meta key=mailchimp_api_key}', $task->form_fields['1']['value'] );
		$this->assertStringNotContainsString( 'mc-LIVE-SECRET-VALUE', $task->form_fields['1']['value'] );

		// entry_save() persisted the same, unresolved value -- not the secret.
		$this->assertGreaterThan( 0, $task->entry_id );
		$stored = $this->get_entry_meta_value( $task->entry_id, 'utm_source' );
		$this->assertSame( '{post_meta key=mailchimp_api_key}', $stored );
	}

	/**
	 * A hidden field whose default_value legitimately contains a smart tag
	 * (e.g. referencing another submitted field) must still resolve, end to
	 * end through do_task() -> entry_save().
	 */
	public function test_hidden_field_trusted_default_value_still_resolves() {
		$this->form_data['form_fields']['1']['default_value'] = 'ref: {field_id="2"}';
		$this->save_form_data();

		$task = $this->submit(
			array(
				'1' => 'ref: {field_id="2"}', // as rendered/submitted verbatim.
				'2' => 'user typed this',
			)
		);

		$this->assertEmpty( $task->errors, 'Submission unexpectedly failed validation: ' . wp_json_encode( $task->errors ) );
		$this->assertSame( 'ref: user typed this', $task->form_fields['1']['value'] );

		$this->assertGreaterThan( 0, $task->entry_id );
		$stored = $this->get_entry_meta_value( $task->entry_id, 'utm_source' );
		$this->assertSame( 'ref: user typed this', $stored );
	}

	/**
	 * Bypass vector: a user types a smart tag into an ordinary visible
	 * field, and an admin template embeds that field via {field_id="X"}.
	 * The embedded value must not be re-scanned for smart tags.
	 */
	public function test_field_id_embed_of_user_value_is_not_re_resolved() {
		$smart_tags = new EVF_Smart_Tags();

		$fields = array(
			'2' => array(
				'id'    => '2',
				'type'  => 'text',
				'value' => '{post_meta key=mailchimp_api_key}',
			),
		);

		$result = $smart_tags->process( 'You said: {field_id="2"}', $this->form_data, $fields, 123 );

		$this->assertSame( 'You said: {post_meta key=mailchimp_api_key}', $result );
		$this->assertStringNotContainsString( 'mc-LIVE-SECRET-VALUE', $result );
	}

	/**
	 * No regression: a legitimate admin-authored {post_meta} tag in a
	 * trusted template (not sourced from a submitted field) still resolves.
	 */
	public function test_trusted_post_meta_tag_still_resolves() {
		$smart_tags = new EVF_Smart_Tags();

		$result = $smart_tags->process( 'Key is: {post_meta key=mailchimp_api_key}', $this->form_data, array(), 123 );

		$this->assertSame( 'Key is: mc-LIVE-SECRET-VALUE', $result );
	}

	/**
	 * Protected (underscore-prefixed) meta keys stay blocked.
	 */
	public function test_protected_meta_key_stays_blocked() {
		$smart_tags = new EVF_Smart_Tags();

		$result = $smart_tags->process( 'Key is: {post_meta key=_protected_secret}', $this->form_data, array(), 123 );

		$this->assertStringNotContainsString( 'should-never-leak', $result );
	}
}
