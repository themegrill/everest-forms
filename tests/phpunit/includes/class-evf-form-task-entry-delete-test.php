<?php
/**
 * Regression tests for entry deletion only removing the entry's own upload files.
 *
 * @package EverestForms\Tests
 * @since   3.6.5
 */

/**
 * EVF_Form_Task_Entry_Delete_Test class.
 */
class EVF_Form_Task_Entry_Delete_Test extends WP_UnitTestCase {

	/**
	 * Real uploads base directory.
	 *
	 * @var string
	 */
	protected $uploads_dir;

	/**
	 * Form ID used across the tests.
	 *
	 * @var int
	 */
	protected $form_id;

	/**
	 * Paths to remove after each test, deepest first.
	 *
	 * @var array
	 */
	protected $cleanup = array();

	/**
	 * Create a form with signature, text and upload fields, plus the uploads base.
	 *
	 * @before
	 */
	public function create_fixtures() {
		$upload_dir = wp_get_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$this->uploads_dir = wp_normalize_path( realpath( $upload_dir['basedir'] ) );

		$this->form_id = evf()->form->create( 'Entry delete test form' );
		$data          = json_decode( get_post_field( 'post_content', $this->form_id ), true );

		// A text field whose meta key matches the old /signature_/ pattern, a real
		// signature field, and a file-upload field.
		$data['form_fields'] = array(
			'note'   => array(
				'id'       => 'note',
				'type'     => 'text',
				'label'    => 'Signature Note',
				'meta-key' => 'signature_note',
			),
			'sig'    => array(
				'id'       => 'sig',
				'type'     => 'signature',
				'label'    => 'Signature',
				'meta-key' => 'signature_1',
			),
			'upload' => array(
				'id'       => 'upload',
				'type'     => 'file-upload',
				'label'    => 'Upload',
				'meta-key' => 'file_upload',
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
		foreach ( array_reverse( $this->cleanup ) as $path ) {
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test fixture.
			} elseif ( is_dir( $path ) ) {
				rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test fixture.
			}
		}

		$this->cleanup = array();

		if ( $this->form_id ) {
			wp_delete_post( $this->form_id, true );
		}
	}

	/**
	 * Create a file and track it, and any directories it needs, for cleanup.
	 *
	 * @param string $path File path.
	 * @return string Normalized file path.
	 */
	protected function make_file( $path ) {
		$path    = wp_normalize_path( $path );
		$missing = array();
		$dir     = dirname( $path );

		while ( ! is_dir( $dir ) ) {
			$missing[] = $dir;
			$dir       = dirname( $dir );
		}

		wp_mkdir_p( dirname( $path ) );
		$this->cleanup = array_merge( $this->cleanup, array_reverse( $missing ) );

		file_put_contents( $path, 'data' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test fixture.
		$this->cleanup[] = $path;

		return $path;
	}

	/**
	 * Store an entry whose meta holds the given key/value map, then delete it
	 * through the real deletion flow.
	 *
	 * @param array $meta Meta key to value map.
	 */
	protected function delete_entry_with_meta( $meta ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'evf_entries',
			array(
				'form_id'      => $this->form_id,
				'user_id'      => 0,
				'user_device'  => '',
				'referer'      => '',
				'fields'       => null,
				'status'       => 'publish',
				'viewed'       => 1,
				'date_created' => current_time( 'mysql', true ),
			)
		);
		$entry_id = (int) $wpdb->insert_id;

		foreach ( $meta as $meta_key => $value ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prefix . 'evf_entrymeta',
				array(
					'entry_id'   => $entry_id,
					'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
		}

		wp_cache_delete( $entry_id, 'evf-entry' );
		wp_cache_delete( $entry_id, 'evf-entrymeta' );

		// The same hook EVF_Admin_Entries::remove_entry() fires on every entry delete
		// (single, bulk, empty trash and form deletion).
		do_action( 'everest_forms_before_delete_entries', $entry_id );
	}

	/**
	 * A text field holding an arbitrary path must not delete that file, even when
	 * its meta key matches the old "signature_" pattern.
	 */
	public function test_text_field_path_is_not_deleted() {
		$outside = $this->make_file( $this->uploads_dir . '/evf-delete-victim.txt' );

		$this->delete_entry_with_meta( array( 'signature_note' => $outside ) );

		$this->assertFileExists( $outside );
	}

	/**
	 * A file in a sibling "uploads-*" directory must not be deleted.
	 */
	public function test_sibling_directory_file_is_not_deleted() {
		$sibling = $this->make_file( $this->uploads_dir . '-evf-sibling/secret.txt' );

		$this->delete_entry_with_meta( array( 'signature_note' => $sibling ) );

		$this->assertFileExists( $sibling );
	}

	/**
	 * A signature value pointing at another form's signature directory is ignored.
	 */
	public function test_other_form_signature_is_not_deleted() {
		$other = $this->make_file( $this->uploads_dir . '/everest_forms_uploads/999999/signature/signature_x.png' );

		$this->delete_entry_with_meta( array( 'signature_1' => $other ) );

		$this->assertFileExists( $other );
	}

	/**
	 * This form's own signature file is cleaned up as before.
	 */
	public function test_own_signature_file_is_deleted() {
		$own = $this->make_file(
			$this->uploads_dir . '/everest_forms_uploads/' . $this->form_id . '/signature/signature_ok.png'
		);

		$this->delete_entry_with_meta( array( 'signature_1' => $own ) );

		// PHPUnit 7.5 (pinned in composer.lock) names this assertFileNotExists().
		$this->assertFileNotExists( $own );
	}

	/**
	 * A genuinely uploaded file in this form's own folder is still removed.
	 */
	public function test_uploaded_file_is_deleted() {
		$file = $this->make_file( $this->form_upload_dir() . '/report.pdf' );
		$url  = wp_get_upload_dir()['baseurl'] . '/everest_forms_uploads/' . $this->form_folder() . '/report.pdf';

		$this->delete_entry_with_meta( array( 'file_upload' => $url ) );

		$this->assertFileNotExists( $file );
	}

	/**
	 * A file stored under another form's upload folder must not be deleted,
	 * even though it is inside everest_forms_uploads.
	 */
	public function test_other_form_upload_is_not_deleted() {
		$other     = $this->make_file( $this->uploads_dir . '/everest_forms_uploads/424242-deadbeef/report.pdf' );
		$other_url = wp_get_upload_dir()['baseurl'] . '/everest_forms_uploads/424242-deadbeef/report.pdf';

		$this->delete_entry_with_meta( array( 'file_upload' => $other_url ) );

		$this->assertFileExists( $other );
	}

	/**
	 * This form's hashed upload folder, matching EVF_Form_Fields_Upload::get_form_files_dir().
	 *
	 * @return string
	 */
	protected function form_folder() {
		return $this->form_id . '-' . wp_hash( $this->form_id . get_post( $this->form_id )->post_date );
	}

	/**
	 * Absolute path to this form's hashed upload folder.
	 *
	 * @return string
	 */
	protected function form_upload_dir() {
		return $this->uploads_dir . '/everest_forms_uploads/' . $this->form_folder();
	}
}
