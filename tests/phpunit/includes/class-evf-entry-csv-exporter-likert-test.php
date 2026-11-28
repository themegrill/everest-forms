<?php
/**
 * Regression tests for exporting Likert answers after the field's answer style changes.
 *
 * @package EverestForms\Tests
 * @since   3.6.6
 */

/**
 * EVF_Entry_CSV_Exporter_Likert_Test class.
 */
class EVF_Entry_CSV_Exporter_Likert_Test extends WP_UnitTestCase {

	/**
	 * Form ID used across the tests.
	 *
	 * @var int
	 */
	protected $form_id;

	/**
	 * Entry IDs created by the test.
	 *
	 * @var array
	 */
	protected $entry_ids = array();

	/**
	 * Saved Likert answers per answer style, as the Likert field stores them.
	 *
	 * @var array
	 */
	protected $saved = array(
		'radio'    => array(
			1 => 1,
			3 => 3,
		),
		'checkbox' => array(
			1 => array( 1, 2 ),
			3 => array( 3 ),
		),
		'select'   => array(
			1 => array(
				1 => 'Option B',
				2 => 'Option A',
			),
			3 => array( 3 => 'Option B' ),
		),
	);

	/**
	 * Expected export per saved style, whatever the field's current style is.
	 *
	 * @var array
	 */
	protected $expected = array(
		'radio'    => array( 'Yes', 'No' ),
		'checkbox' => array( 'Yes, Maybe', 'No' ),
		'select'   => array( 'Option B, Option A', 'Option B' ),
	);

	/**
	 * Create a form with a Likert field.
	 *
	 * @before
	 */
	public function create_fixtures() {
		include_once EVF_ABSPATH . 'includes/export/class-evf-entry-csv-exporter.php';

		$this->form_id = evf()->form->create( 'Likert export test form' );
		$this->set_input_type( 'radio' );
	}

	/**
	 * Remove the fixtures.
	 *
	 * @after
	 */
	public function remove_fixtures() {
		global $wpdb;

		foreach ( $this->entry_ids as $entry_id ) {
			$wpdb->delete( $wpdb->prefix . 'evf_entrymeta', array( 'entry_id' => $entry_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'evf_entries', array( 'entry_id' => $entry_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( $this->form_id ) {
			wp_delete_post( $this->form_id, true );
		}

		$this->entry_ids = array();
	}

	/**
	 * Set the Likert field's answer style.
	 *
	 * @param string $input_type radio, checkbox or select.
	 */
	protected function set_input_type( $input_type ) {
		$data = json_decode( get_post_field( 'post_content', $this->form_id ), true );

		$data['form_fields'] = array(
			'lk' => array(
				'id'                => 'lk',
				'type'              => 'likert',
				'label'             => 'Survey',
				'meta-key'          => 'likert_qa',
				'input_type'        => $input_type,
				'likert_rows'       => array(
					1 => 'Row One',
					3 => 'Row Three',
				),
				'likert_columns'    => array(
					1 => 'Yes',
					2 => 'Maybe',
					3 => 'No',
				),
				'drop_down_choices' => array(
					1 => array( 'label' => 'Option A' ),
					2 => array( 'label' => 'Option B' ),
				),
			),
		);

		wp_update_post(
			array(
				'ID'           => $this->form_id,
				'post_content' => evf_encode( $data ),
			)
		);
		wp_cache_flush();
	}

	/**
	 * Store an entry with the given Likert answers.
	 *
	 * @param array $value_raw Saved Likert answers.
	 * @return int Entry ID.
	 */
	protected function create_entry( $value_raw ) {
		global $wpdb;

		$field = array(
			'name'      => 'Survey',
			'value'     => 'stored',
			'value_raw' => $value_raw,
			'id'        => 'lk',
			'type'      => 'likert',
			'meta_key'  => 'likert_qa', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'evf_entries',
			array(
				'form_id'      => $this->form_id,
				'user_id'      => 0,
				'user_device'  => '',
				'referer'      => '',
				'fields'       => wp_json_encode( array( 'lk' => $field ) ),
				'status'       => 'publish',
				'viewed'       => 0,
				'date_created' => current_time( 'mysql', true ),
			)
		);
		$entry_id = (int) $wpdb->insert_id;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'evf_entrymeta',
			array(
				'entry_id'   => $entry_id,
				'meta_key'   => 'likert_qa', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => wp_json_encode( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					array(
						'value'     => 'stored',
						'value_raw' => $value_raw,
					)
				),
			)
		);

		$this->entry_ids[] = $entry_id;

		return $entry_id;
	}

	/**
	 * Export one entry and return its two Likert row columns.
	 *
	 * @param int $entry_id Entry ID.
	 * @return array
	 */
	protected function export_rows( $entry_id ) {
		$exporter = new EVF_Entry_CSV_Exporter( $this->form_id, $entry_id );
		$rows     = $exporter->prepare_data_to_export();

		return array( $rows[0]['lk-likert-1'], $rows[0]['lk-likert-3'] );
	}

	/**
	 * Every saved style exports its labels or answer text under every current style.
	 */
	public function test_likert_export_does_not_depend_on_current_answer_style() {
		$entries = array();
		foreach ( $this->saved as $style => $value_raw ) {
			$entries[ $style ] = $this->create_entry( $value_raw );
		}

		foreach ( array( 'radio', 'checkbox', 'select' ) as $current_style ) {
			$this->set_input_type( $current_style );

			foreach ( $entries as $saved_style => $entry_id ) {
				$this->assertSame(
					$this->expected[ $saved_style ],
					$this->export_rows( $entry_id ),
					"Entry saved as {$saved_style}, exported with the field set to {$current_style}."
				);
			}
		}
	}

	/**
	 * A radio answer saved before the field was switched to Dropdown exports the column labels.
	 */
	public function test_radio_entry_exports_labels_after_switch_to_dropdown() {
		$entry_id = $this->create_entry( $this->saved['radio'] );

		$this->set_input_type( 'select' );

		$this->assertSame( array( 'Yes', 'No' ), $this->export_rows( $entry_id ) );
	}

	/**
	 * An unanswered row exports an empty cell.
	 */
	public function test_unanswered_row_exports_empty() {
		$entry_id = $this->create_entry( array( 1 => 2 ) );

		$this->assertSame( array( 'Maybe', '' ), $this->export_rows( $entry_id ) );
	}
}
