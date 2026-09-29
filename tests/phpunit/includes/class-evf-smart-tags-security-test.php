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
	 * Minimal trusted form definition.
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

		$this->form_data = array(
			'id'          => 999999,
			'form_fields' => array(
				'1' => array(
					'id'            => '1',
					'type'          => 'hidden',
					'label'         => 'UTM Source',
					'meta-key'      => 'utm_source',
					'default_value' => 'organic',
				),
				'2' => array(
					'id'    => '2',
					'type'  => 'text',
					'label' => 'Message',
				),
			),
			'settings'    => array(),
		);
	}

	/**
	 * Tear down test.
	 */
	public function tearDown() {
		wp_delete_post( $this->post_id, true );
		parent::tearDown();
	}

	/**
	 * Replicates the (fixed) hidden-field resolution loop from
	 * EVF_Form_Task::do_task() / entry_save(): smart tags are resolved
	 * from the field's own configured default_value, never from the
	 * submitted value.
	 */
	protected function resolve_hidden_fields( $submitted_fields ) {
		foreach ( $submitted_fields as $key => $value ) {
			if ( 'hidden' !== $value['type'] ) {
				continue;
			}

			$default_value = isset( $this->form_data['form_fields'][ $key ]['default_value'] )
				? $this->form_data['form_fields'][ $key ]['default_value']
				: '';

			if ( is_string( $default_value ) && '' !== $default_value && strpos( $default_value, '{' ) !== false ) {
				$submitted_fields[ $key ]['value'] = apply_filters( 'everest_forms_process_smart_tags', $default_value, $this->form_data, $submitted_fields );
			}
		}

		return $submitted_fields;
	}

	/**
	 * An attacker POSTing a smart tag directly into a Hidden field's value
	 * must never have it resolved -- only the field's own trusted
	 * default_value may ever reach the smart tag parser.
	 */
	public function test_hidden_field_post_payload_is_not_resolved() {
		$submitted               = $this->form_data['form_fields'];
		$submitted['1']['value'] = '{post_meta key=mailchimp_api_key}';
		$submitted['2']['value'] = 'hello';

		$resolved = $this->resolve_hidden_fields( $submitted );

		$this->assertSame( '{post_meta key=mailchimp_api_key}', $resolved['1']['value'] );
		$this->assertStringNotContainsString( 'mc-LIVE-SECRET-VALUE', $resolved['1']['value'] );
	}

	/**
	 * A hidden field whose default_value legitimately contains a smart tag
	 * (e.g. referencing another submitted field) must still resolve.
	 */
	public function test_hidden_field_trusted_default_value_still_resolves() {
		$this->form_data['form_fields']['1']['default_value'] = 'ref: {field_id="2"}';

		$submitted               = $this->form_data['form_fields'];
		$submitted['1']['value'] = 'ref: {field_id="2"}';
		$submitted['2']['value'] = 'user typed this';

		$resolved = $this->resolve_hidden_fields( $submitted );

		$this->assertSame( 'ref: user typed this', $resolved['1']['value'] );
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
