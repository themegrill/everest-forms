<?php
/**
 * Regression tests for sanitizing submitted Likert answers.
 *
 * @package EverestForms\Tests
 * @since   3.6.6
 */

/**
 * EVF_Sanitize_Entry_Likert_Test class.
 */
class EVF_Sanitize_Entry_Likert_Test extends WP_UnitTestCase {

	/**
	 * Form ID used across the tests.
	 *
	 * @var int
	 */
	protected $form_id;

	/**
	 * Create a form with a Likert field.
	 *
	 * @before
	 */
	public function create_fixtures() {
		$this->form_id = evf()->form->create( 'Likert sanitize test form' );
		$data          = json_decode( get_post_field( 'post_content', $this->form_id ), true );

		$data['form_fields'] = array(
			'lk' => array(
				'id'             => 'lk',
				'type'           => 'likert',
				'label'          => 'Survey',
				'input_type'     => 'checkbox',
				'likert_rows'    => array(
					1 => 'Row One',
					3 => 'Row Three',
				),
				'likert_columns' => array(
					1 => 'Yes',
					2 => 'Maybe',
					3 => 'No',
				),
			),
		);

		wp_update_post(
			array(
				'ID'           => $this->form_id,
				'post_content' => evf_encode( $data ),
			)
		);
	}

	/**
	 * Remove the fixtures.
	 *
	 * @after
	 */
	public function remove_fixtures() {
		if ( $this->form_id ) {
			wp_delete_post( $this->form_id, true );
		}
	}

	/**
	 * Sanitize a submitted Likert value.
	 *
	 * @param mixed $likert Submitted Likert value.
	 * @return mixed
	 */
	protected function sanitize( $likert ) {
		$entry = evf_sanitize_entry(
			array(
				'id'          => $this->form_id,
				'form_fields' => array( 'lk' => $likert ),
			)
		);

		return $entry['form_fields']['lk'];
	}

	/**
	 * Radio answers (one column ID per row) are kept.
	 */
	public function test_radio_answers_are_kept() {
		$this->assertSame(
			array(
				1 => '1',
				3 => '3',
			),
			$this->sanitize(
				array(
					1 => '1',
					3 => '3',
				)
			)
		);
	}

	/**
	 * Checkbox answers (a list of column IDs per row) are kept.
	 */
	public function test_checkbox_answers_are_kept() {
		$this->assertSame(
			array(
				1 => array( '1', '2' ),
				3 => array( '3' ),
			),
			$this->sanitize(
				array(
					1 => array( '1', '2' ),
					3 => array( '3' ),
				)
			)
		);
	}

	/**
	 * Dropdown answers (column ID => chosen text per row) are kept.
	 */
	public function test_dropdown_answers_are_kept() {
		$this->assertSame(
			array(
				1 => array(
					1 => 'Option B',
					2 => 'Option A',
				),
			),
			$this->sanitize(
				array(
					1 => array(
						1 => 'Option B',
						2 => 'Option A',
					),
				)
			)
		);
	}

	/**
	 * Markup in keys and answers is stripped, and deeper nesting is dropped.
	 */
	public function test_markup_and_nesting_are_sanitized() {
		$this->assertSame(
			array(
				1 => array(
					1   => 'Option B',
					'9' => 'xkey',
					2   => '',
				),
			),
			$this->sanitize(
				array(
					1 => array(
						1          => '<script>alert(1)</script>Option B<img src=x onerror=alert(2)>',
						'<b>9</b>' => '<i>x</i>key',
						2          => array( 'deep' => 'nested' ),
					),
				)
			)
		);
	}
}
