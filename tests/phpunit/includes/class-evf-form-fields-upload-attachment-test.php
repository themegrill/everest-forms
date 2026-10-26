<?php
/**
 * Regression tests for entry file attachments staying inside uploads.
 *
 * @package EverestForms\Tests
 * @since   3.6.4
 */

/**
 * EVF_Form_Fields_Upload_Attachment_Test class.
 */
class EVF_Form_Fields_Upload_Attachment_Test extends WP_UnitTestCase {

	/**
	 * Upload field instance under test.
	 *
	 * @var EVF_Field_File_Upload
	 */
	protected $field;

	/**
	 * Real uploads base directory.
	 *
	 * @var string
	 */
	protected $uploads_dir;

	/**
	 * Real file outside the uploads directory.
	 *
	 * @var string
	 */
	protected $outside_file;

	/**
	 * Paths to remove after each test, deepest first.
	 *
	 * @var array
	 */
	protected $cleanup = array();

	/**
	 * Create the fixtures.
	 *
	 * @before
	 */
	public function create_fixtures() {
		$reflection  = new ReflectionClass( 'EVF_Field_File_Upload' );
		$this->field = $reflection->newInstanceWithoutConstructor();
		$upload_dir  = wp_get_upload_dir();

		wp_mkdir_p( $upload_dir['basedir'] );

		$this->uploads_dir  = wp_normalize_path( realpath( $upload_dir['basedir'] ) );
		$this->outside_file = wp_normalize_path( wp_tempnam( 'evf-outside-file' ) );

		file_put_contents( $this->outside_file, 'secret' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test fixture.
		$this->cleanup[] = $this->outside_file;
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

		file_put_contents( $path, 'png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test fixture.
		$this->cleanup[] = $path;

		return $path;
	}

	/**
	 * Call the protected path resolver.
	 *
	 * @param mixed $path Path to resolve.
	 * @return string|false
	 */
	protected function resolve( $path ) {
		$method = new ReflectionMethod( $this->field, 'resolve_uploads_file_path' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return $method->invoke( $this->field, $path );
	}

	/**
	 * Store an entry whose fields hold the given meta, and return its ID.
	 *
	 * @param array $meta Meta key to value map.
	 * @return int
	 */
	protected function create_entry( $meta ) {
		global $wpdb;

		$fields = array();

		foreach ( $meta as $meta_key => $value ) {
			$fields[] = array(
				'meta_key' => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'value'    => $value,
			);
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'evf_entries',
			array(
				'form_id'      => 1,
				'user_id'      => 0,
				'user_device'  => '',
				'referer'      => '',
				'fields'       => wp_json_encode( $fields ),
				'status'       => 'publish',
				'viewed'       => 1,
				'date_created' => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * A file directly inside uploads resolves to its real path.
	 */
	public function test_file_inside_uploads_is_accepted() {
		$file = $this->make_file( $this->uploads_dir . '/evf-attach-test.png' );

		$this->assertSame( $file, $this->resolve( $file ) );
	}

	/**
	 * A file in a nested uploads directory resolves to its real path.
	 */
	public function test_file_in_nested_uploads_directory_is_accepted() {
		$file = $this->make_file( $this->uploads_dir . '/everest_forms_uploads/evf-attach-test/signature/signature_1.png' );

		$this->assertSame( $file, $this->resolve( $file ) );
	}

	/**
	 * An existing file outside uploads is rejected.
	 */
	public function test_file_outside_uploads_is_rejected() {
		$this->assertFalse( $this->resolve( $this->outside_file ) );
		$this->assertFalse( $this->resolve( __FILE__ ) );
	}

	/**
	 * A path that starts in uploads but traverses out of it is rejected.
	 */
	public function test_directory_traversal_is_rejected() {
		$traversal = $this->uploads_dir . str_repeat( '/..', substr_count( $this->uploads_dir, '/' ) ) . $this->outside_file;

		$this->assertFileExists( $traversal );
		$this->assertFalse( $this->resolve( $traversal ) );
	}

	/**
	 * Missing files, directories, and non-string values are rejected.
	 */
	public function test_invalid_values_are_rejected() {
		$this->assertFalse( $this->resolve( $this->uploads_dir . '/evf-attach-test-missing.png' ) );
		$this->assertFalse( $this->resolve( $this->uploads_dir ) );
		$this->assertFalse( $this->resolve( '' ) );
		$this->assertFalse( $this->resolve( array( $this->outside_file ) ) );
		$this->assertFalse( $this->resolve( $this->uploads_dir . "/evf-attach-test.png\0.txt" ) );
	}

	/**
	 * A symlink inside uploads that points outside uploads is rejected.
	 */
	public function test_symlink_pointing_outside_uploads_is_rejected() {
		$link = $this->uploads_dir . '/evf-attach-test-link.png';

		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->outside_file, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->markTestSkipped( 'Symlinks are not supported here.' );
		}

		$this->cleanup[] = $link;

		$this->assertFalse( $this->resolve( $link ) );
	}

	/**
	 * A sibling directory that shares the uploads path as a string prefix is rejected.
	 */
	public function test_sibling_directory_sharing_uploads_prefix_is_rejected() {
		$file = $this->make_file( $this->uploads_dir . '-evf-attach-test/secret.png' );

		$this->assertFalse( $this->resolve( $file ) );
	}

	/**
	 * Stored signature paths outside uploads are not attached; legitimate ones still are.
	 */
	public function test_attach_entry_files_filters_signature_paths() {
		$signature = $this->make_file( $this->uploads_dir . '/everest_forms_uploads/evf-attach-test/signature/signature_1.png' );
		$upload    = $this->make_file( $this->uploads_dir . '/everest_forms_uploads/evf-attach-test/upload.png' );
		$entry_id  = $this->create_entry(
			array(
				'signature_valid'     => $signature,
				'signature_outside'   => $this->outside_file,
				'signature_traversal' => $this->uploads_dir . '/../../../../../../../../../..' . $this->outside_file,
				'signature_config'    => __FILE__,
				'file_upload_1'       => wp_get_upload_dir()['baseurl'] . '/everest_forms_uploads/evf-attach-test/upload.png',
			)
		);

		$this->assertSame( array( $signature, $upload ), $this->field->attach_entry_files( $entry_id ) );
	}

	/**
	 * Submitted signature paths outside uploads are not attached when entries are disabled.
	 */
	public function test_attach_entry_files_upload_filters_signature_paths() {
		$signature = $this->make_file( $this->uploads_dir . '/everest_forms_uploads/evf-attach-test/signature/signature_2.png' );

		$attachments = $this->field->attach_entry_files_upload(
			array(
				'signature_valid'   => $signature,
				'signature_outside' => $this->outside_file,
				'signature_config'  => __FILE__,
			)
		);

		$this->assertSame( array( $signature ), $attachments );
	}
}
