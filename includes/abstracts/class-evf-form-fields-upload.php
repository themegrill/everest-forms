<?php
/**
 * Abstract uploader helper class
 *
 * @package EverestForms_Pro\Abstracts
 * @version 1.3.0
 * @since   1.0.0
 */

use EverestForms\Helpers\FormHelper;

defined( 'ABSPATH' ) || exit;

/**
 * EVF_Form_Fields_Upload
 */
abstract class EVF_Form_Fields_Upload extends EVF_Form_Fields {

	/**
	 * Files that are not allowed.
	 *
	 * @var array
	 */
	protected $blacklist = array( 'ade', 'adp', 'app', 'asp', 'bas', 'bat', 'cer', 'cgi', 'chm', 'cmd', 'com', 'cpl', 'crt', 'csh', 'csr', 'dll', 'drv', 'exe', 'fxp', 'flv', 'hlp', 'hta', 'htaccess', 'htm', 'htpasswd', 'inf', 'ins', 'isp', 'jar', 'js', 'jse', 'jsp', 'ksh', 'lnk', 'mdb', 'mde', 'mdt', 'mdw', 'msc', 'msi', 'msp', 'mst', 'ops', 'pcd', 'php', 'pif', 'pl', 'prg', 'ps1', 'ps2', 'py', 'rb', 'reg', 'scr', 'sct', 'sh', 'shb', 'shs', 'sys', 'swf', 'tmp', 'torrent', 'url', 'vb', 'vbe', 'vbs', 'vbscript', 'wsc', 'wsf', 'wsf', 'wsh' );

	/**
	 * Files that are not allowed to be deleted.
	 *
	 * @var array
	 */
	protected $protected_files = array( 'index.html', 'index.php', '.htaccess', '.htpasswd' );

	/**
	 * Hook in tabs.
	 */
	public function init_hooks() {
		add_action( 'everest_forms_shortcode_scripts', array( $this, 'load_assets' ) );
		add_filter( 'everest_forms_html_field_value', array( $this, 'html_field_value' ), 10, 5 );
		add_filter( 'everest_forms_plaintext_field_value', array( $this, 'plaintext_field_value' ), 10, 4 );
		add_filter( 'everest_forms_field_exporter_' . $this->type, array( $this, 'field_exporter' ) );
		add_filter( 'everest_forms_email_file_attachments', array( $this, 'send_file_as_email_attachment' ), 99, 6 );
		add_filter( 'everest_forms_email_file_attachments', array( $this, 'send_csv_file_as_email_attachment' ), 100, 6 );
		add_action( 'everest_forms_remove_attachments_after_send_email', array( $this, 'remove_csv_file_after_email_send' ), 10, 6 );
		add_action( 'everest_forms_woocommerce_js', array( $this, 'load_assets' ) );

		if ( is_callable( array( $this, 'field_properties' ) ) ) {
			add_filter( 'everest_forms_field_properties_' . $this->type, array( $this, 'field_properties' ), 5, 3 );
		}

		// AJAX Events.
		$this->add_ajax_events();
	}

	/**
	 * Register/queue frontend scripts.
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public function load_assets( $atts ) {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_register_script( 'dropzone', plugins_url( "/assets/js/dropzone/dropzone{$suffix}.js", EVF_PLUGIN_FILE ), array( 'jquery' ), '5.5.0', true );
		wp_register_script( 'everest-forms-file-upload', plugins_url( "/assets/js/frontend/everest-forms-file-upload{$suffix}.js", EVF_PLUGIN_FILE ), array( 'dropzone', 'wp-util' ), EVF_VERSION, true );
		wp_localize_script(
			'everest-forms-file-upload',
			'everest_forms_upload_parms',
			array(
				'url'             => admin_url( 'admin-ajax.php' ),
				'errors'          => array(
					'file_not_uploaded' => esc_html__( 'This file was not uploaded.', 'everest-forms' ),
					'file_limit'        => esc_html__( 'File limit has been reached ({fileLimit}).', 'everest-forms' ),
					'file_extension'    => get_option( 'everest_forms_fileextension_validation' ),
					'file_size'         => get_option( 'everest_forms_filesize_validation', __( 'File exceeds max size allowed.', 'everest-forms' ) ),
					'post_max_size'     => sprintf(
						/* translators: %s: Max upload size */
						esc_html__( 'File exceeds the upload limit allowed (%s).', 'everest-forms' ),
						evf_max_upload()
					),
				),
				'max_timeout'     => apply_filters( 'evf_fileupload_max_timeout', absint( 30000 ) ),
				'loading_message' => esc_html__( 'Do not submit the form until the upload process is finished', 'everest-forms' ),
			)
		);

		if ( 'everest_forms_woocommerce_js' === current_action() ) {
			wp_enqueue_script( 'dropzone' );
			wp_enqueue_script( 'everest-forms-file-upload' );
			return;
		}

		$form_id     = is_array( $atts ) && isset( $atts['id'] ) ? absint( $atts['id'] ) : ( is_numeric( $atts ) ? absint( $atts ) : 0 );
		$form_fields = evf_get_form_fields( $form_id );
		$form_data   = EVF()->form->get( $form_id, array( 'content_only' => true ) );
		$form_fields = isset( $form_data['form_fields'] ) ? $form_data['form_fields'] : array();

		foreach ( $form_fields as $form_field ) {
			if ( 'file-upload' === $form_field['type'] || 'image-upload' === $form_field['type'] ) {
				wp_enqueue_script( 'dropzone' );
				wp_enqueue_script( 'everest-forms-file-upload' );
				break;
			}
		}
	}

	/**
	 * Hook in methods - uses WordPress ajax handlers (admin-ajax).
	 */
	public function add_ajax_events() {
		$ajax_events = array(
			'upload_file',
			'remove_file',
		);

		foreach ( $ajax_events as $ajax_event ) {
			add_action( 'wp_ajax_everest_forms_' . $ajax_event, array( $this, $ajax_event ) );
			add_action( 'wp_ajax_nopriv_everest_forms_' . $ajax_event, array( $this, $ajax_event ) );
		}
	}

	/**
	 * Remove the file from the temporary directory.
	 *
	 * @since 1.3.0
	 */
	public function remove_file() {
		$default_error = esc_html__( 'Something went wrong while removing the file.', 'everest-forms' );

		$validated_form_field = $this->ajax_validate_form_field();
		if ( empty( $validated_form_field ) ) {
			wp_send_json_error( $default_error );
		}

		if ( empty( $_POST['file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_send_json_error( $default_error );
		}

		$file = sanitize_file_name( wp_unslash( $_POST['file'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// Strip any path traversal sequences and null bytes
		$file = str_replace( array( '../', '..\\', '\\', "\0" ), '', $file );

		if ( in_array( strtolower( $file ), array_map( 'strtolower', $this->protected_files ), true ) ) {
			wp_send_json_error( esc_html__( 'This file cannot be deleted for security reasons.', 'everest-forms' ) );
		}

		// Only allow deletion from whitelisted directories
		$allowed_dirs = array(
			$this->get_tmp_dir(),
			$this->get_form_files_dir()['path'],
		);

		$tmp_path = wp_normalize_path( $this->get_tmp_dir() . '/' . $file );

		// Validate that the target path is within allowed directories
		$is_allowed = false;
		foreach ( $allowed_dirs as $allowed_dir ) {
			$allowed_dir = wp_normalize_path( $allowed_dir );
			if ( strpos( $tmp_path, $allowed_dir ) === 0 ) {
				$is_allowed = true;
				break;
			}
		}

		if ( ! $is_allowed ) {
			wp_send_json_error( esc_html__( 'Access denied: File location not allowed.', 'everest-forms' ) );
		}

		// Requested file does not exist, which is good.
		if ( ! is_file( $tmp_path ) ) {
			wp_send_json_success( $file );
		}

		if ( @unlink( $tmp_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_send_json_success( $file );
		}

		wp_send_json_error( $default_error );
	}

	/**
	 * Ajax handler for file upload.
	 *
	 * @since 1.3.0
	 */
	public function upload_file() {
		$default_error = esc_html__( 'Something went wrong, please try again.', 'everest-forms' );

		$validated_form_field = $this->ajax_validate_form_field();
		if ( empty( $validated_form_field ) ) {
			wp_send_json_error( $default_error );
		}

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( esc_html__( 'No file was uploaded', 'everest-forms' ) );
		}

		if ( isset( $_FILES['file']['error'] ) && UPLOAD_ERR_OK !== $_FILES['file']['error'] ) {
			$error         = $_FILES['file']['error']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$error_message = $this->get_upload_validation_errors( $error );
			wp_send_json_error( $error_message );
		}

		// Make sure we have required values from $_FILES.
		if ( empty( $_FILES['file']['name'] ) ) {
			wp_send_json_error( $default_error );
		}
		if ( empty( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( $default_error );
		}

		// Make data available always.
		$this->form_data  = $validated_form_field['form_data'];
		$this->form_id    = $this->form_data['id'];
		$this->field_id   = $validated_form_field['field_id'];
		$this->field_data = $this->form_data['form_fields'][ $this->field_id ];

		$error        = empty( $_FILES['file']['error'] ) ? UPLOAD_ERR_OK : intval( $_FILES['file']['error'] );
		$path         = $_FILES['file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$name         = sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$extension    = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$errors       = $this->ajax_validate( $error, $extension, $path, $name );
		if ( isset( $this->field_data['custom_file_name'] ) && ! empty( $this->field_data['custom_file_name'] ) ) {
			$custom_file_name = apply_filters( 'everest_forms_process_smart_tags', $this->field_data['custom_file_name'], $this->form_data );
			$name_of_file     = sanitize_file_name( $custom_file_name ) . '_' . uniqid( '', true ) . '.' . $extension;
		} else {
			$name_of_file = sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		}

		if ( count( $errors ) ) {
			wp_send_json_error( implode( ',', $errors ), 400 );
		}

		$tmp_dir  = $this->get_tmp_dir();
		$tmp_name = $this->get_tmp_file_name( $extension );
		$tmp_path = wp_normalize_path( $tmp_dir . '/' . $tmp_name );
		$tmp      = $this->move_file( $path, $tmp_path );

		if ( ! $tmp ) {
			wp_send_json_error( $default_error );
		}

		$this->clean_tmp_files();

		wp_send_json_success(
			array(
				'file' => pathinfo( $tmp, PATHINFO_FILENAME ) . '.' . pathinfo( $tmp, PATHINFO_EXTENSION ),
				'name' => $name_of_file,
			)
		);
	}

	/**
	 * Clean up the tmp folder - remove all old files every day (filterable interval).
	 */
	protected function clean_tmp_files() {
		$files = glob( trailingslashit( $this->get_tmp_dir() ) . '*' );

		if ( ! is_array( $files ) || empty( $files ) ) {
			return;
		}

		$lifespan = (int) apply_filters( 'everest_forms_field_' . $this->type . '_clean_tmp_files_lifespan', DAY_IN_SECONDS );

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			// In some cases filemtime() can return false, in that case - pretend this is a new file and do nothing.
			$modified = (int) filemtime( $file );
			if ( empty( $modified ) ) {
				$modified = time();
			}

			if ( ( time() - $modified ) >= $lifespan ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * Ajax handler for errors.
	 *
	 * @param int    $error Errors.
	 * @param string $ext   Extension.
	 * @param string $path  Path to a newly uploaded file.
	 * @param string $name  Name of a newly uploaded file.
	 *
	 * @return array of errors.
	 */
	public function ajax_validate( $error, $ext, $path, $name ) {
		$errors = array();

		// Basic file upload validation.
		if ( UPLOAD_ERR_OK !== $error ) {
			$upload_error_message = $this->get_upload_validation_errors( $error );
			if ( is_string( $upload_error_message ) ) {
				/* translators: %s - error text. */
				$errors[] = sprintf( esc_html__( 'File upload error. %s', 'everest-forms' ), $upload_error_message );
			}
		}

		if ( 'pdf' === $ext ) {

			$content  = file_get_contents( $path );
			$patterns = array(
				'/\/JavaScript\b/',
				'/eval\(/i',
				'/app\.alert/i',
				'/console\.log\b/i',
				'/document\.write\b/i',
				'/\/SubmitForm\b/i',
				'/\/Launch\b/i',
				'/\/RichMedia\b/i',
				'/\/EmbeddedFile\b/i',
				'/\/FileAttachment\b/i',
				'/\/GoTo\b/i',
			);

			// Initialize errors array
			$errors = array();

			// Check for malicious patterns
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $content ) ) {
					$errors[] = esc_html__( ' Malicious file detected', 'everest-forms' );
				}
			}
		}

		// Validate file size.
		$max_size = min( wp_max_upload_size(), $this->max_file_size() );

		if ( ! empty( $_FILES ) ) {
			foreach ( $_FILES as $file ) {
				if ( $file['size'] > $max_size ) {
					$errors[] = sprintf(
						/* translators: %s: max upload size */
						esc_html__( 'File exceeds max size allowed (%s).', 'everest-forms' ),
						evf_size_to_megabytes( $max_size )
					);
				}
			}
		}

		// Make sure file has an extension first.
		if ( empty( $ext ) ) {
			$errors[] = esc_html__( 'File must have an extension.', 'everest-forms' );
		}

		// Validate extension against all allowed values.
		if ( ! in_array( $ext, $this->get_extensions(), true ) ) {
			$errors[] = esc_html__( 'File type is not allowed.', 'everest-forms' );
		}

		/*
		 * Validate file against what WordPress is set to allow.
		 * At the end of the day, if you try to upload a file that WordPress
		 * doesn't allow, we won't allow it either. Users can use a plugin to
		 * filter the allowed mime types in WordPress if this is an issue.
		 */
		if ( ! defined( 'ALLOW_UNFILTERED_UPLOADS' ) || false === ALLOW_UNFILTERED_UPLOADS ) {
			$wp_filetype = wp_check_filetype_and_ext( $path, $name );

			$ext             = empty( $wp_filetype['ext'] ) ? '' : $wp_filetype['ext'];
			$type            = empty( $wp_filetype['type'] ) ? '' : $wp_filetype['type'];
			$proper_filename = empty( $wp_filetype['proper_filename'] ) ? '' : $wp_filetype['proper_filename'];

			if ( $proper_filename || ! $ext || ! $type ) {
				$errors[] = esc_html__( 'File type is not allowed.', 'everest-forms' );
			}
		}

		return $errors;
	}

	/**
	 * Get all allowed extensions.
	 * Check against user-entered extensions.
	 *
	 * @since 1.3.1
	 *
	 * @param mixed $ext_type Extension type.
	 *
	 * @return array of allowed extensions.
	 */
	protected function get_extensions( $ext_type = '' ) {
		$ext_types  = wp_get_ext_types();
		$mime_types = wp_get_mime_types();

		if ( ! empty( $this->field_data['extensions'] ) ) {
			// User provided specific extensions.
			$extensions = array_diff( explode( ',', strtolower( preg_replace( '/[^A-Za-z0-9,]/', '', $this->field_data['extensions'] ) ) ), $this->blacklist );
		} elseif ( '' !== $ext_type ) {
			$extensions = array_diff( $ext_types[ $ext_type ], $this->blacklist );
		} else {
			// Get default extensions supported by WordPress.
			$extensions = array_diff( explode( '|', implode( '|', array_keys( $mime_types ) ) ), $this->blacklist );
		}

		return $extensions;
	}

	/**
	 * Move file to a permanent location.
	 *
	 * @since 1.3.0
	 *
	 * @param string $filename    The filename of the uploaded file.
	 * @param string $destination The destination of the moved file.
	 *
	 * @return false|string False on error.
	 */
	protected function move_file( $filename, $destination ) {
		$this->create_dir( dirname( $destination ) );

		if ( false === move_uploaded_file( $filename, $destination ) ) {
			$logger = evf_get_logger();
			$logger->error(
				sprintf( 'Upload Error, could not upload file: %s', $filename ),
				array(
					'source' => 'file-upload',
				)
			);

			return false;
		}

		$this->set_file_fs_permissions( $destination );

		return $destination;
	}

	/**
	 * Create both the directory and index.html file in it if any of them doesn't exist.
	 *
	 * @since 1.3.0
	 *
	 * @param string $path Path to the directory.
	 *
	 * @return string Path to the newly created directory.
	 */
	protected function create_dir( $path ) {
		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( $path );
		}

		$index = wp_normalize_path( $path . '/index.html' );

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $path;
	}

	/**
	 * Set correct file permissions in the file system.
	 *
	 * @since 1.3.0
	 *
	 * @param string $path File to set permissions for.
	 */
	protected function set_file_fs_permissions( $path ) {
		// Set correct file permissions.
		$stat = stat( dirname( $path ) );

		@chmod( $path, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Get tmp file name.
	 *
	 * @since 1.3.0
	 *
	 * @param string $extension File extension.
	 *
	 * @return string
	 */
	protected function get_tmp_file_name( $extension ) {
		return wp_hash( wp_rand() . microtime() . $this->form_id . $this->field_id ) . '.' . $extension;
	}

	/**
	 * Get tmp dir for files.
	 *
	 * @since 1.3.0
	 *
	 * @return string
	 */
	protected function get_tmp_dir() {
		$uploads  = wp_upload_dir();
		$tmp_root = untrailingslashit( $uploads['basedir'] ) . '/everest_forms_uploads/tmp';

		if ( ! file_exists( $tmp_root ) || ! wp_is_writable( $tmp_root ) ) {
			wp_mkdir_p( $tmp_root );
		}

		$index = trailingslashit( $tmp_root ) . 'index.html';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $tmp_root;
	}

	/**
	 * Ajax validation for form fields.
	 *
	 * @since 1.3.0
	 */
	protected function ajax_validate_form_field() {
		if ( empty( $_POST['form_id'] ) || empty( $_POST['field_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return array();
		}

		$field_id  = sanitize_text_field( wp_unslash( $_POST['field_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$form_data = evf()->form->get(
			(int) $_POST['form_id'], // phpcs:ignore WordPress.Security.NonceVerification
			array(
				'content_only' => true,
			)
		);

		if ( empty( $form_data ) || ! is_array( $form_data ) || ( isset( $form_data['form_enabled'] ) && 1 !== absint( $form_data['form_enabled'] ) ) ) {
			return array();
		}

		// For checking form is published or not.
		$the_post = get_post( absint( $form_data['id'] ) );
		if ( $the_post && 'publish' !== $the_post->post_status ) {
			return array();
		}

		return array(
			'form_data' => $form_data,
			'field_id'  => $field_id,
		);
	}

	/**
	 * Allowed Extensions field option.
	 *
	 * @param array $field Field data.
	 */
	public function extensions( $field ) {
		$lbl  = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'extensions',
				'value'   => esc_html__( 'Allowed File Extensions', 'everest-forms' ),
				'tooltip' => esc_html__( 'Enter the extensions you would like to allow, comma separated.', 'everest-forms' ),
			),
			false
		);
		$fld  = $this->field_element(
			'text',
			$field,
			array(
				'slug'  => 'extensions',
				'value' => ! empty( $field['extensions'] ) ? $field['extensions'] : '',
			),
			false
		);
		$args = array(
			'slug'    => 'extensions',
			'content' => $lbl . $fld,
		);
		$this->field_element( 'row', $field, $args );
	}

	/**
	 * Upload message field option.
	 *
	 * @param array $field Field data.
	 */
	public function upload_message( $field ) {
		$max_file_number = ! empty( $field['max_file_number'] ) ? max( 1, absint( $field['max_file_number'] ) ) : 1;
		$lbl             = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'upload_message',
				'value'   => esc_html__( 'File Upload Message', 'everest-forms' ),
				'tooltip' => esc_html__( 'Enter text to be displayed as file upload message', 'everest-forms' ),
			),
			false
		);
		$fld             = $this->field_element(
			'text',
			$field,
			array(
				'slug'  => 'upload_message',
				'value' => ! empty( $field['upload_message'] ) ? $field['upload_message'] : esc_html( sprintf( _n( 'Drop your file here or click here to upload', 'Drop your files here or click here to upload', (int) $max_file_number, 'everest-forms' ), (int) $max_file_number ) ),
			),
			false
		);
		$args            = array(
			'slug'    => 'upload_message',
			'content' => $lbl . $fld,
		);
		$this->field_element( 'row', $field, $args );
	}

	/**
	 * Get the custom user defined file name
	 *
	 * @param array $field Field data.
	 */
	public function custom_file_name( $field ) {
		$lbl  = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'custom_file_name',
				'value'   => esc_html__( 'Custom File Name', 'everest-forms' ),
				'tooltip' => esc_html__( 'Enter text to be displayed as file name. Supports smart tags.', 'everest-forms' ),
			),
			false
		);
		$fld  = $this->field_element(
			'text',
			$field,
			array(
				'slug'  => 'custom_file_name',
				'value' => ! empty( $field['custom_file_name'] ) ? $field['custom_file_name'] : '',
			),
			false
		);

		$smart_tag_toggle  = '<a href="#" class="evf-toggle-smart-tag-display" data-type="all"><span class="dashicons dashicons-editor-code"></span></a>';
		$smart_tag_toggle .= '<div class="evf-smart-tag-lists" style="display: none">';
		$smart_tag_toggle .= '<div class="smart-tag-title">' . esc_html__( 'Available Fields', 'everest-forms' ) . '</div><ul class="evf-fields"></ul>';
		$smart_tag_toggle .= '<div class="smart-tag-title other-tag-title">' . esc_html__( 'Others', 'everest-forms' ) . '</div><ul class="evf-others"></ul>';
		$smart_tag_toggle .= '</div>';

		$args = array(
			'slug'    => 'custom_file_name',
			'content' => $lbl . $fld . $smart_tag_toggle,
			'class'   => 'evf_smart_tag',
		);

		$this->field_element( 'row', $field, $args );
	}

	/**
	 * Limit message field option.
	 *
	 * @param array $field Field data.
	 */
	public function limit_message( $field ) {
		$max_file_number = ! empty( $field['max_file_number'] ) ? max( 1, absint( $field['max_file_number'] ) ) : 1;
		$lbl             = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'limit_message',
				'value'   => esc_html__( 'Upload Limit Message', 'everest-forms' ),
				'tooltip' => esc_html__( 'Enter text to be displayed as file upload limit message', 'everest-forms' ),
			),
			false
		);
		$fld             = $this->field_element(
			'text',
			$field,
			array(
				'slug'  => 'limit_message',
				/* translators: 1: Number of Files */
				'value' => ! empty( $field['limit_message'] ) ? $field['limit_message'] : sprintf( __( 'You can upload up to %s files.', 'everest-forms' ), (int) $max_file_number ),
			),
			false
		);
		$args            = array(
			'slug'    => 'limit_message',
			'content' => $lbl . $fld,
		);
		$this->field_element( 'row', $field, $args );
	}


	/**
	 * Max file size field option.
	 *
	 * @param array $field Field data.
	 */
	public function max_size( $field ) {
		$lbl  = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'max_size',
				'value'   => esc_html__( 'Max File Size', 'everest-forms' ),
				/* translators: %s - max upload size. */
				'tooltip' => sprintf( esc_html__( 'Enter the max file size, in megabytes, to allow. If left blank, the value defaults to the maximum size the server allows which is %s.', 'everest-forms' ), evf_max_upload() ),
			),
			false
		);
		$fld  = $this->field_element(
			'text',
			$field,
			array(
				'slug'  => 'max_size',
				'value' => ! empty( $field['max_size'] ) ? $field['max_size'] : '',
			),
			false
		);
		$args = array(
			'slug'    => 'max_size',
			'content' => $lbl . $fld,
		);
		$this->field_element( 'row', $field, $args );
	}

	/**
	 * File upload limit option.
	 *
	 * @param array $field Field data.
	 */
	public function max_file_number( $field ) {
		$lbl = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'max_file_number',
				'value'   => esc_html__( 'Maximum number limit on uploads', 'everest-forms' ),
				'tooltip' => sprintf( esc_html__( 'Enter the number of files you wish the user to upload.', 'everest-forms' ) ),
			),
			true
		);
		$fld = $this->field_element(
			'number',
			$field,
			array(
				'slug'    => 'max_file_number',
				'type'    => 'number',
				'min'     => '1',
				'max'     => $max_file_number = ( ( ( defined( 'EFP_PLUGIN_FILE' ) ) && ! ( empty( $field['max_file_number'] ) && 0 < intval( $field['max_file_number'] ) ) ) || ! empty( $field['max_file_number'] ) ) ? $field['max_file_number'] : 1,
				'value'   => $max_file_number = ( ( ( defined( 'EFP_PLUGIN_FILE' ) ) && ! ( empty( $field['max_file_number'] ) && 0 < intval( $field['max_file_number'] ) ) ) || ! empty( $field['max_file_number'] ) ) ? $field['max_file_number'] : 1,
				'desc'    => esc_html__( 'Maximum number limit on uploads', 'everest-forms' ),
				'tooltip' => esc_html__( 'Enter the number of files you wish the user to upload.', 'everest-forms' ),
			),
			false
		);
		$args = array(
			'slug'    => 'max_file_number',
			'content' => $fld,
		);
		$this->field_element( 'row', $field, $args );
	}

	/**
	 * Media Library field option.
	 *
	 * @param array $field Field data.
	 */
	public function media_library( $field ) {
		$fld  = $this->field_element(
			'toggle',
			$field,
			array(
				'slug'    => 'media_library',
				'value'   => ! empty( $field['media_library'] ) ? 1 : '',
				'desc'    => esc_html__( 'Store file in WordPress Media Library', 'everest-forms' ),
				'tooltip' => esc_html__( 'Check this option to store the final uploaded file in the WordPress Media Library', 'everest-forms' ),
			),
			false
		);
		$args = array(
			'slug'    => 'media_library',
			'content' => $fld,
		);
		$this->field_element( 'row', $field, $args );
	}

	/**
	 * Customize format for HTML field value.
	 *
	 * @param  string $val       Field value.
	 * @param  array  $field_val Field settings.
	 * @param  array  $form_data Form data.
	 * @param  string $context   Value display context.
	 * @return string $val       Html Value.
	 */
	public function html_field_value( $val, $field_val, $form_data = array(), $context = '', $field_meta_key = '' ) {
		if ( is_array( $field_meta_key ) && isset( $field_meta_key['type'] ) && $field_meta_key['type'] !== $this->type ) {
			return $val;
		}

		$meta_key = '';
		$entry_id = false;
		$uploads  = wp_upload_dir();

		if ( 'evffl-display-popup' === $context ) {
			foreach ( $form_data['form_fields'] as $fields_data ) {
				if ( $field_val['meta-key'] === $fields_data['meta-key'] ) {
					if ( 'image-upload' === $fields_data['type'] ) {
						$val = '';
						foreach ( $field_val['field_value']['value_raw'] as $key => $value ) {
							$image_url = isset( $value['value'] ) ? esc_url( $value['value'] ) : '';

							$val .= sprintf(
								'<a href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%1$s" style="width:200px;" alt="" /></a>',
								$image_url
							);
						}

						return $val;
					}

					if ( 'file-upload' === $fields_data['type'] ) {
						$count = count( $field_val['field_value']['value_raw'] );
						$val   = '';

						if ( $count > 1 ) {
							foreach ( $field_val['field_value']['value_raw'] as $key => $value ) {
								$file_url  = isset( $value['value'] ) ? esc_url( $value['value'] ) : '';
								$file_name = isset( $value['name'] ) ? esc_html( $value['name'] ) : '';

								if ( 1 === $count ) {
									$val .= sprintf(
										'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
										$file_url,
										$file_name
									);
								} else {
									$val .= sprintf(
										'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>, ',
										$file_url,
										$file_name
									);
								}

								--$count;
							}
						} else {
							$file_url  = isset( $field_val['value'] ) ? esc_url( $field_val['value'] ) : '';
							$file_name = isset( $field_val['field_value']['value_raw'][0]['name'] ) ? esc_html( $field_val['field_value']['value_raw'][0]['name'] ) : '';

							$val .= sprintf(
								'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
								$file_url,
								$file_name
							);
						}

						return $val;
					}
				}
			}
		}

		if ( isset( $_GET['view-entry'] ) && 'entry-single' === $context ) { // phpcs:ignore WordPress.Security.NonceVerification
			$entry_id = absint( $_GET['view-entry'] ); // phpcs:ignore WordPress.Security.NonceVerification
			$meta_key = ! empty( $field_meta_key ) ? evf_clean( $field_meta_key ) : array_search( $val, $form_data, true );
		} elseif ( isset( $_GET['edit-entry'], $field_val['meta_key'] ) && 'entry-single' === $context ) { // phpcs:ignore WordPress.Security.NonceVerification
			$entry_id = absint( $_GET['edit-entry'] ); // phpcs:ignore WordPress.Security.NonceVerification
			$meta_key = evf_clean( $field_val['meta_key'] );
		} elseif ( isset( $form_data, $field_val['meta_key'] ) && 'email-html' === $context ) {
			$entry_id = absint( $form_data['entry_id'] );
			$meta_key = evf_clean( $field_val['meta_key'] );
		} elseif ( is_object( $form_data ) ) {
			$entry_id = absint( $form_data->entry_id );
			$meta_key = array_search( $val, $form_data->meta, true );
		} elseif ( isset( $_GET['entry_id'], $_GET['list_id'] ) && 'entry-single' === $context ) { // phpcs:ignore WordPress.Security.NonceVerification
			$entry_id = absint( $_GET['entry_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
			$meta_key = array_search( $val, $form_data, true );
		}

		$entry  = $entry_id ? evf_get_entry( $entry_id, true ) : false;
		$fields = isset( $entry->fields ) ? evf_decode( $entry->fields ) : array();

		if ( ! empty( $fields ) && ! is_serialized( $field_val ) ) {
			$output = array();

			foreach ( $fields as $field ) {
				if ( empty( $field['value'] ) || $field['type'] !== $this->type || $field['meta_key'] !== $meta_key ) {
					continue;
				}

				if ( ! empty( $field['value_raw'] ) ) {
					foreach ( $field['value_raw'] as $file ) {
						if ( empty( $file['value'] ) || empty( $file['file_original'] ) ) {
							$output[ $meta_key ] = '';
							continue;
						}

						$file_url      = esc_url( $file['value'] );
						$file_original = esc_html( $file['file_original'] );

						if ( 'export-csv' === $context ) {
							$output[ $meta_key ][] = $file_url;
						} elseif ( 'image-upload' === $field['type'] ) {
							if ( 'email-html' === $context ) {
								$output[ $meta_key ][] = apply_filters(
									'everest_forms_image_value',
									sprintf(
										'<a href="%1$s" rel="noopener noreferrer" target="_blank"><img src="%1$s" style="width:200px;" alt="" /></a>',
										$file_url
									),
									$file_url
								);
							} elseif ( 'entry-single' === $context ) {
								$output[ $meta_key ][] = sprintf(
									'<a href="%1$s" rel="noopener noreferrer" target="_blank"><img src="%1$s" style="width:200px;" alt="" /></a>',
									$file_url
								);
							} else {
								$output[ $meta_key ][] = sprintf(
									'<a href="%1$s" rel="noopener noreferrer" target="_blank">%2$s</a>',
									$file_url,
									$file_original
								);
							}
						} else {
							$output[ $meta_key ][] = sprintf(
								'<a href="%1$s" rel="noopener noreferrer" target="_blank">%2$s</a>',
								$file_url,
								$file_original
							);
						}
					}
				}
			}

			if ( ! empty( $output[ $meta_key ] ) ) {
				$val = implode( 'export-csv' !== $context ? '<br>' : '|', $output[ $meta_key ] );
			}
		} elseif ( is_serialized( $field_val ) ) {
			$value = evf_maybe_unserialize( $field_val );

			if ( isset( $value['type'] ) && in_array( $value['type'], array( 'image-upload', 'file-upload' ), true ) ) {
				$val = empty( $value['file_url'] ) ? '<em>' . esc_html__( '(empty)', 'everest-forms' ) . '</em>' : $val;
			}

			if ( isset( $value['type'], $value['file_url'] ) && $value['type'] === $this->type ) {
				$file = $uploads['basedir'] . str_replace( '/uploads/', '/', str_replace( content_url(), '', esc_url( $value['file_url'] ) ) );

				switch ( $this->type ) {
					case 'image-upload':
						if ( '' !== $value['file_url'] ) {
							$file_url      = esc_url( $value['file_url'] );
							$file_original = isset( $value['file_original'] ) ? esc_html( $value['file_original'] ) : '';

							if ( 'export-pdf' === $context ) {
								$val = sprintf(
									'<img src="%s" style="width:200px;height:100px;" alt="" />',
									esc_attr( $file )
								);
							} elseif ( 'entry-single' === $context ) {
								$alt_text = esc_attr( basename( $file_url ) );
								$val = sprintf(
									'<a href="%1$s" rel="noopener noreferrer" target="_blank">
										<img src="%1$s" style="width:200px;" alt="%2$s" />
									</a>',
									esc_url( $file_url ),
									$alt_text
								);
							} else {
								$val = sprintf(
									'<a href="%1$s" target="_blank" rel="noopener noreferrer" class="image">%2$s</a>',
									$file_url,
									$file_original
								);
							}
						}
						break;

					default:
						if ( '' !== $value['file_url'] ) {
							$file_url      = esc_url( $value['file_url'] );
							$file_original = isset( $value['file_original'] ) ? esc_html( $value['file_original'] ) : '';

							$val = sprintf(
								'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
								$file_url,
								$file_original
							);
						}
						break;
				}

				if ( in_array( $context, array( 'export-csv' ), true ) ) {
					$val = esc_url( $value['file_url'] );
				}
			}
		}

		return $val;
	}

	/**
	 * Customize format for Plain field value.
	 *
	 * @param  string $val       Field value.
	 * @param  array  $field_val Field settings.
	 * @param  array  $form_data Form data.
	 * @param  string $context   Value display context.
	 *
	 * @return string $val       Formatted file url.
	 */
	public function plaintext_field_value( $val, $field_val, $form_data = array(), $context = '' ) {
		if ( is_array( $field_val ) && 'email-plain' === $context ) {
			if ( isset( $field_val['type'], $field_val['file_url'] ) && $field_val['type'] === $this->type ) {
				return esc_url( $field_val['file_url'] ) . "\r\n\r\n";
			}
		}

		return $val;
	}

	/**
	 * Filter callback for outputting formatted data.
	 *
	 * @param array $field Field Data.
	 */
	public function field_exporter( $field ) {
		$value = array();

		if ( ! empty( $field['value_raw'] ) ) {
			if ( ! is_array( $field['value_raw'] ) ) {
				$field['value_raw'] = (array) $field['value_raw'];
			}

			array_walk(
				$field['value_raw'],
				function ( &$val, $key, $img ) {
					$img['data'][] = ! empty( $val['value'] ) ? apply_filters(
						'everest_forms_field_exporter_image',
						sprintf(
							'<a href="%s" rel="noopener noreferrer" target="_blank">%s</a>',
							esc_url( $val['value'] ),
							esc_html( $val['name'] )
						),
						$val
					) : '';
				},
				array(
					'data' => &$value,
				)
			);
		}

		return array(
			'label' => ! empty( $field['name'] ) ? $field['name'] : ucfirst( str_replace( '_', ' ', $field['type'] ) ) . " - {$field['id']}",
			'value' => is_array( $value ) ? count( $value ) ? implode( '<br>', $value ) : false : $value,
		);
	}

	/**
	 * Field preview inside the builder.
	 *
	 * @param array $field Field data.
	 */
	public function field_preview( $field ) {
		// Label.
		$this->field_preview_option( 'label', $field );

		$max_file_number = ! empty( $field['max_file_number'] ) ? max( 1, absint( $field['max_file_number'] ) ) : 1;
		$upload_message  = isset( $field['upload_message'] ) ? $field['upload_message'] : esc_html( sprintf( _n( 'Drop your file here or click here to upload', 'Drop your files here or click here to upload', (int) $max_file_number, 'everest-forms' ), (int) $max_file_number ) );
		/* translators: 1: Number of Files */
		$limit_message = isset( $field['limit_message'] ) ? $field['limit_message'] : sprintf( __( 'You can upload up to %s files.', 'everest-forms' ), (int) $max_file_number );

		// Primary input.
		?>
		<div class="everest-forms-uploader">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32px" height="32px" fill="#868e96"><path class="cls-1" d="M18.12,17.52,17,16.4V25a1,1,0,0,1-2,0V16.4l-1.12,1.12a1,1,0,0,1-1.42,0,1,1,0,0,1,0-1.41l2.83-2.83a1,1,0,0,1,1.42,0l2.83,2.83a1,1,0,0,1-.71,1.7A1,1,0,0,1,18.12,17.52ZM22,22H20a1,1,0,0,1,0-2h2a4,4,0,0,0,.27-8,1,1,0,0,1-.84-.57,6,6,0,0,0-11.36,1.69,1,1,0,0,1-1,.86H9A3,3,0,0,0,9,20h3a1,1,0,0,1,0,2H9a5,5,0,0,1-.75-9.94A8,8,0,0,1,23,10.1,6,6,0,0,1,22,22Z"></path></svg>
			<span class="everest-forms-upload-title"><?php echo esc_html( $upload_message ); ?></span>
			<span class="everest-forms-upload-hint">
			<?php
			echo wp_kses( $limit_message, array( 'span' ) );
			?>
			</span>
		</div>
		<input type="file" class="widefat" disabled>
		<?php

		// Description.
		$this->field_preview_option( 'description', $field );
	}

	/**
	 * Field display on the form front-end.
	 *
	 * @since 1.0.0
	 *
	 * @param array $field Field Data.
	 * @param array $field_atts Field attributes.
	 * @param array $form_data All Form Data.
	 */
	public function field_display( $field, $field_atts, $form_data ) {

		// Define data.
		$primary           = $field['properties']['inputs']['primary'];
		$conditional_rules = isset( $field['properties']['inputs']['primary']['attr']['conditional_rules'] ) ? $field['properties']['inputs']['primary']['attr']['conditional_rules'] : '';
		$conditional_id    = isset( $field['properties']['inputs']['primary']['attr']['conditional_id'] ) ? $field['properties']['inputs']['primary']['attr']['conditional_id'] : '';
		$field_id          = $field['id'];
		$form_id           = (int) $form_data['id'];
		$url               = admin_url( 'admin-ajax.php' );
		$input_name        = 'everest_forms_' . $form_data['id'] . '_' . $field['id'];
		$required          = $primary['required'];
		$extensions        = $primary['data']['rule-extension'];
		$max_size          = abs( $primary['data']['rule-maxsize'] );
		$max_file_number   = isset( $field['max_file_number'] ) ? $field['max_file_number'] : 1;
		$max_file_number   = max( 1, absint( $max_file_number ) );
		$post_max_size     = wp_max_upload_size();
		$upload_message    = isset( $field['upload_message'] ) ? $field['upload_message'] : esc_html( sprintf( _n( 'Drop your file here or click here to upload', 'Drop your files here or click here to upload', (int) $max_file_number, 'everest-forms' ), (int) $max_file_number ) );
		/* translators: 1: Number of Files */
		$limit_message = isset( $field['limit_message'] ) ? $field['limit_message'] : sprintf( __( 'You can upload up to %s files.', 'everest-forms' ), (int) $max_file_number );

		$files             = ! empty( $primary['attr']['value'] ) ? explode( ' ', $primary['attr']['value'] ) : array();
		$old_input_name    = sprintf( 'everest_forms_%d_old_%s[]', $this->form_id, $this->field_id );
		$delete_input_name = sprintf( 'everest_forms_%d_delete_%s', $this->form_id, $this->field_id );

		?>
		<div class="everest-forms-uploader"
			data-field-id="<?php echo esc_attr( $field_id ); ?>"
			data-form-id="<?php echo (int) $form_id; ?>"
			data-input-name="<?php echo esc_attr( $input_name ); ?>"
			data-extensions="<?php echo esc_attr( $extensions ); ?>"
			data-max-size="<?php echo (int) $max_size; ?>"
			data-max-file-number="<?php echo (int) $max_file_number; ?>"
			data-post-max-size="<?php echo (int) $post_max_size; ?>"
			data-current-file-count="<?php echo count( $files ); ?>"
			>
			<div class="dz-message">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="32px" height="32px" fill="#868e96"><path class="cls-1" d="M18.12,17.52,17,16.4V25a1,1,0,0,1-2,0V16.4l-1.12,1.12a1,1,0,0,1-1.42,0,1,1,0,0,1,0-1.41l2.83-2.83a1,1,0,0,1,1.42,0l2.83,2.83a1,1,0,0,1-.71,1.7A1,1,0,0,1,18.12,17.52ZM22,22H20a1,1,0,0,1,0-2h2a4,4,0,0,0,.27-8,1,1,0,0,1-.84-.57,6,6,0,0,0-11.36,1.69,1,1,0,0,1-1,.86H9A3,3,0,0,0,9,20h3a1,1,0,0,1,0,2H9a5,5,0,0,1-.75-9.94A8,8,0,0,1,23,10.1,6,6,0,0,1,22,22Z"/></svg>
				<span class="everest-forms-upload-title">
					<?php
						echo esc_html( $upload_message );
					?>
				</span>

				<?php if ( (int) $max_file_number >= 1 ) : ?>
					<span class="everest-forms-upload-hint">
						<?php
						/* translators: %d - max number of files. */
						echo wp_kses( $limit_message, array( 'span' ) );
						?>
					</span>
				<?php endif; ?>
			</div>
			<?php
			if ( ! empty( $files ) ) {
				$key = $field['meta-key'];
				foreach ( $files as $attachment_url ) {
					if ( empty( $attachment_url ) || ! $attachment_url ) {
						continue;
					}
					?>
					<div class="dz-preview dz-processing dz-image-preview dz-success dz-complete">
						<div class="dz-image">
							<?php

							if ( empty( $attachment_url ) ) {
								$attachment_url = home_url() . '/wp-includes/images/media/text.png';
							}

							$filename_only = basename( $attachment_url ); // Just the file name.
							$file_original = preg_replace( '/-[a-f0-9]{32}/', '', $filename_only ); // Remove hash to get original name

							$filesize_only = $this->get_local_file_size( $attachment_url );

							if ( false !== $filesize_only ) {
								$filesize_only = size_format( $filesize_only, 1 );
							}

							$ext      = pathinfo( $attachment_url, PATHINFO_EXTENSION );
							$fileIcon = FormHelper::evf_file_upload_check_file_types( $ext );

							$fileIcon = ! is_null( $fileIcon ) ? evf()->plugin_url() . '/assets/images/filetypes/' . $fileIcon . '.png' : $attachment_url;

							$file = array(
								'name'          => $file_original,
								'value'         => $attachment_url,
								'file'          => $filename_only,
								'file_original' => $file_original,
								'ext'           => $ext,
								'attachment_id' => 0,
								'id'            => $field_id,
								'type'          => $this->get_file_mime_type( $attachment_url ),
							);
							?>

							<img data-dz-thumbnail="" alt="<?php echo esc_html( sanitize_text_field( $filename_only ) ); ?>"  src="<?php echo esc_url( $fileIcon ); ?>"/>
						</div>
						<div class="dz-details">
							<div class="dz-size">
								<span data-dz-size=""><strong><?php echo esc_html( sanitize_text_field( $filesize_only ) ); ?></strong></span>
							</div>
							<div class="dz-filename">
								<span data-dz-name=""><?php echo esc_html( sanitize_text_field( $filename_only ) ); ?></span>
							</div>
							<input type="hidden" class="existing-file" name="<?php echo $old_input_name; ?>" value='<?php echo json_encode( $file ); ?>' />
						</div>
						<a class="evf-download-file" href="<?php echo esc_url( $attachment_url ); ?>" title="Download" target="_blank" download ><span class="dashicons dashicons-arrow-down-alt"></span></a>
						<a class="dz-remove evf-remove-file" href="javascript:undefined;" title="Remove" data-dz-remove="" data-form-id="<?php echo absint( $form_id ); ?>" data-field-name="<?php echo esc_attr( $key ); ?>"></a>
					</div>
					<?php
				}
				?>
				<input type="hidden" class="deleted-input" name="<?php echo $delete_input_name; ?>" value='' />
				<?php
			}
			?>
		</div>
		<input type="text" class="dropzone-input input-text" id="everest-forms-<?php echo absint( $form_id ); ?>-field_<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $input_name ); ?>" <?php echo esc_attr( $required ); ?> conditional_id="<?php echo esc_attr( $conditional_id ); ?>" conditional_rules='<?php echo $conditional_rules; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>'>
		<?php
	}

	/**
	 * Validates field on form submit.
	 *
	 * @param int   $field_id Field ID.
	 * @param array $field_submit Submitted field value.
	 * @param array $form_data Form data and settings.
	 */
	public function validate( $field_id, $field_submit, $form_data ) {
		$this->form_data  = (array) $form_data;
		$this->form_id    = absint( $this->form_data['id'] );
		$this->field_id   = $field_id;
		$this->field_data = $this->form_data['form_fields'][ $this->field_id ];
		$entry            = isset( $form_data['entry'] ) ? $form_data['entry'] : array();
		$visible          = apply_filters( 'everest_forms_visible_fields', true, $form_data['form_fields'][ $field_id ], $entry, $form_data );
		$required_message = isset( $form_data['form_fields'][ $field_id ]['required-field-message'], $form_data['form_fields'][ $field_id ]['required_field_message_setting'] ) && 'individual' == $form_data['form_fields'][ $field_id ]['required_field_message_setting'] ? $form_data['form_fields'][ $field_id ]['required-field-message'] : get_option( 'everest_forms_required_validation' );

		$input_name = sprintf( 'everest_forms_%d_%s', $this->form_id, $this->field_id );

		if ( false === $visible || empty( $this->field_data['required'] ) ) {
			return;
		}

		$value = '';
		if ( ! empty( $_POST[ $input_name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$value = json_decode( wp_unslash( $_POST[ $input_name ] ), true ); // phpcs:ignore WordPress.Security
		}

		if ( empty( $value ) ) {
			evf()->task->errors[ $this->form_id ][ $this->field_id ] = $required_message;
			update_option( 'evf_validation_error', 'yes' );
		}
	}

	private function get_file_mime_type( $file_path ) {
		if ( function_exists( 'mime_content_type' ) && file_exists( $file_path ) ) {
			return mime_content_type( $file_path );
		}

		$extension  = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$mime_types = wp_get_mime_types();

		return $mime_types[ $extension ] ?? 'application/octet-stream';
	}

	/**
	 * Get the local file size
	 *
	 * @param [type] $file_path The path.
	 */
	private function get_local_file_size( $file_path ) {
		// If it's already a local path
		if ( file_exists( $file_path ) ) {
			return filesize( $file_path );
		}

		// Convert URL to local path
		$upload_dir = wp_upload_dir();
		$local_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $file_path );

		if ( file_exists( $local_path ) ) {
			return filesize( $local_path );
		}

		// Try remote URL as last resort
		if ( filter_var( $file_path, FILTER_VALIDATE_URL ) ) {
			$response = wp_remote_head( $file_path );
			if ( ! is_wp_error( $response ) && isset( $response['headers']['content-length'] ) ) {
				return (int) $response['headers']['content-length'];
			}
		}

		return 0;
	}
	/**
	 * Formats and sanitizes field.
	 *
	 * @param int    $field_id     Field ID.
	 * @param array  $field_submit Submitted field value.
	 * @param array  $form_data    Form data and settings.
	 * @param string $meta_key     Field Meta Key.
	 */
	public function format( $field_id, $field_submit, $form_data, $meta_key ) {
		// Setup class properties to reuse everywhere.
		$this->form_data  = (array) $form_data;
		$this->form_id    = absint( $this->form_data['id'] );
		$this->field_id   = $field_id;
		$this->field_data = $this->form_data['form_fields'][ $this->field_id ];

		$field_label = ! empty( $this->form_data['form_fields'][ $this->field_id ]['label'] ) ? $this->form_data['form_fields'][ $this->field_id ]['label'] : '';
		$input_name  = sprintf( 'everest_forms_%d_%s', $this->form_id, $this->field_id );

		$processed = array(
			'name'      => make_clickable( $field_label ),
			'value'     => '',
			'value_raw' => '',
			'id'        => $this->field_id,
			'type'      => $this->type,
			'meta_key'  => $meta_key,
		);

		// We should actually receive some files info.
		$raw_files = isset( $_POST[ $input_name ] ) ? $_POST[ $input_name ] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $raw_files ) ) {
			if ( empty( $field_submit ) ) {
				evf()->task->form_fields[ $this->field_id ] = $processed;
				return;
			} else {
				// For handle update entry case.
				$raw_files = isset( $field_submit['new_files'] ) ? $field_submit['new_files'] : array();
			}
		}

		// Make sure form fields are stored.
		if ( ! empty( evf()->task->form_fields[ $this->field_id ] ) ) { // @codingStandardsIgnoreLine
			return;
		}

		$data = array();

		if ( ! empty( $raw_files ) ) {
			// Make sure json_decode() doesn't fail on newer PHP.
			try {
				$raw_files = json_decode( wp_unslash( $raw_files ), true ); // phpcs:ignore WordPress.Security
			} catch ( Exception $e ) {
				evf()->task->form_fields[ $this->field_id ] = $processed;
				return;
			}

			// Make sure we process only submitted files with the expected structure and keys.
			$files = array_filter(
				$raw_files,
				static function ( $file ) {
					return ( is_array( $file ) || is_object( $file ) && count( $file ) === 2 ) && ! empty( $file['file'] ) && ! empty( $file['name'] );
				}
			);

			foreach ( $files as $file ) {
				$file = $this->generate_file_info( $file );

				$wp_filetype = wp_check_filetype_and_ext( $file['tmp_path'], $file['name'] );

				$ext             = empty( $wp_filetype['ext'] ) ? '' : $wp_filetype['ext'];
				$type            = empty( $wp_filetype['type'] ) ? '' : $wp_filetype['type'];
				$proper_filename = empty( $wp_filetype['proper_filename'] ) ? '' : $wp_filetype['proper_filename'];

				if ( $proper_filename || ! $ext || ! $type ) {
					evf()->task->errors[ $form_data['id'] ][ $field_id ] = esc_html__( 'File type is not allowed.', 'everest-forms' );
					update_option( 'evf_validation_error', 'yes' );
					wp_die( 'File type is not allowed' );
				}

				// Allow third-party integrations.
				if ( has_filter( 'everest_forms_integration_uploads' ) ) {
					$file = apply_filters( 'everest_forms_integration_uploads', $file, $this->form_data );
				}

				if ( $this->is_media_integrated() ) {
					$file['path'] = $file['tmp_path'];

					$file = $this->generate_file_attachment( $file );
				} elseif (
					! isset( $file['external'] )
					&& file_exists( $file['tmp_path'] )
				) {

					$this->create_dir( dirname( $file['path'] ) );
					@rename( $file['tmp_path'], $file['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$this->set_file_fs_permissions( $file['path'] );
				}

				$data[] = $this->generate_file_data( $file );
			}
		}

		if ( isset( $field_submit['old_files'] ) && is_array( $field_submit['old_files'] ) ) {

			$validated_old_data = array();

			foreach ( $field_submit['old_files'] as $file ) {
				$decoded = json_decode( $file, true );

				if ( ! is_array( $decoded ) || empty( $decoded['value'] ) || ! is_string( $decoded['value'] ) ) {
					continue;
				}

				$resolved_path = $this->resolve_uploads_file_from_url( $decoded['value'] );

				if ( false === $resolved_path ) {
					continue;
				}

				$decoded['value']     = esc_url_raw( $decoded['value'] );
				$validated_old_data[] = $decoded;
			}

			if ( ! empty( $validated_old_data ) ) {
				$data = array_merge( $data, $validated_old_data );
			}
		}

		if ( ! empty( $data ) ) {
			$mapped_value = array_map(
				function ( $file ) {
					return $file['value'];
				},
				$data
			);
			$mapped_value = implode( "\n", $mapped_value );
			$processed    = wp_parse_args(
				array(
					'value_raw' => $data,
					'value'     => $mapped_value,
				),
				$processed
			);
		}

		evf()->task->form_fields[ $this->field_id ] = $processed;
	}

	/**
	 * Generate a ready for DB data for each file.
	 *
	 * @since 1.3.0
	 *
	 * @param array $file File to generate data for.
	 *
	 * @return array
	 */
	protected function generate_file_data( $file ) {
		$field_label  = ! empty( $this->form_data['form_fields'][ $this->field_id ]['label'] ) ? $this->form_data['form_fields'][ $this->field_id ]['label'] : '';
		$file['name'] = apply_filters( 'everest_forms_upload_file_name', sanitize_text_field( $file['name'] ), $field_label );
		$file_path    = $file['path'];

		if ( isset( $this->form_data['settings']['dropbox_enabled'] ) && 1 === $this->form_data['settings']['dropbox_enabled'] && ! file_exists( $file_path ) ) {
			evf()->task->errors[ $this->form_id ][ $this->field_id ] = __( 'Something went wrong while uploading file,Please try again', 'everest-forms' );
			update_option( 'evf_validation_error', 'yes' );
		}

		return array(
			'name'          => sanitize_text_field( $file['name'] ),
			'value'         => esc_url_raw( $file['file_url'] ),
			'file'          => $file['file_name_new'],
			'file_original' => $file['name'],
			'ext'           => pathinfo( $file['name'], PATHINFO_EXTENSION ),
			'attachment_id' => isset( $file['attachment_id'] ) ? absint( $file['attachment_id'] ) : 0,
			'id'            => $this->field_id,
			'type'          => $file['type'],
		);
	}

	/**
	 * Add additional information to the files array for each file.
	 *
	 * @since 1.3.0
	 *
	 * @param array $file Submitted file basic info.
	 */
	protected function generate_file_info( $file ) {
		$dir              = $this->get_form_files_dir();
		$file['tmp_path'] = trailingslashit( $this->get_tmp_dir() ) . sanitize_file_name( $file['file'] );
		$file['type']     = 'application/octet-stream';
		if ( is_file( $file['tmp_path'] ) ) {
			$filetype     = wp_check_filetype( $file['tmp_path'] );
			$file['type'] = $filetype['type'];
		}

		// Data for no media case.
		$file_ext = pathinfo( $file['name'], PATHINFO_EXTENSION );

		if ( ! empty( $this->field_data['custom_file_name'] ) ) {
			$processed_custom_name = apply_filters( 'everest_forms_process_smart_tags', $this->field_data['custom_file_name'], $this->form_data, evf()->task->form_fields );
			$file_base             = sanitize_file_name( $processed_custom_name );
		} else {
			$file_base = wp_basename( $file['name'], ".$file_ext" );
		}

		$file['file_name_new'] = apply_filters(
			'everest_forms_modify_file_name',
			sprintf('%s-%s.%s', $file_base, wp_hash($dir['path'] . $this->form_data['id'] . $this->field_id), strtolower($file_ext)),
			$file_base,
			$file_ext
		);
		$file['file_name_new'] = wp_unique_filename( trailingslashit( $dir['path'] ), sanitize_file_name( $file['file_name_new'] ) );
		$file['file_url']      = trailingslashit( $dir['url'] ) . $file['file_name_new'];
		$file['path']          = trailingslashit( $dir['path'] ) . $file['file_name_new'];
		$file['attachment_id'] = 0;

		return $file;
	}

	/**
	 * Whether field is integrated with WordPress Media Library.
	 *
	 * @uses $this->field_data
	 *
	 * @since 1.3.0
	 */
	protected function is_media_integrated() {
		return ! empty( $this->field_data['media_library'] ) && '1' === $this->field_data['media_library'];
	}

	/**
	 * Get form-specific uploads directory path and URL.
	 *
	 * @since 1.3.0
	 */
	protected function get_form_files_dir() {
		$uploads = wp_upload_dir();
		$folder  = absint( $this->form_data['id'] ) . '-' . wp_hash( $this->form_data['id'] . $this->form_data['created'] );

		return array(
			'path' => "{$uploads['basedir']}/everest_forms_uploads/{$folder}",
			'url'  => "{$uploads['baseurl']}/everest_forms_uploads/{$folder}",
		);
	}

	/**
	 * Create a Media Library attachment.
	 *
	 * @since 1.3.0
	 *
	 * @param array $file File to create Media Library attachment for.
	 *
	 * @return array
	 */
	protected function generate_file_attachment( $file ) {
		// Include necessary code from core.
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_args = array(
			'error'    => '',
			'tmp_name' => $file['path'],
			'name'     => $file['file_name_new'],
			'type'     => $file['type'],
		);
		$upload    = wp_handle_sideload( $file_args, array( 'test_form' => false ) );

		if ( empty( $upload['file'] ) ) {
			return $file;
		}

		// Create a Media attachment for the file.
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => $this->field_data['label'],
				'post_status'    => 'publish',
				'post_mime_type' => $file['type'],
			),
			$upload['file']
		);

		if ( empty( $attachment_id ) ) {
			return $file;
		}

		// Generate and update attachment meta.
		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
		);

		// Update file information.
		$file_url              = wp_get_attachment_url( $attachment_id );
		$file['path']          = $upload['file'];
		$file['file_url']      = $file_url;
		$file['file_name_new'] = wp_basename( $file_url );
		$file['attachment_id'] = $attachment_id;

		return $file;
	}

	/**
	 * Determine max file size allowed.
	 *
	 * @return int Number of bytes allowed.
	 */
	public function max_file_size() {
		if ( ! empty( $this->field_data['max_size'] ) ) {
			// Strip any suffix provided (eg M, MB etc), which leaves is wit the raw MB value.
			$max_size = preg_replace( '/[^0-9.]/', '', $this->field_data['max_size'] );
			$max_size = evf_size_to_bytes( $max_size . 'M' );
		} else {
			$max_size = evf_max_upload( true );
		}

		return $max_size;
	}

		/**
		 * Send File as email attachment.
		 *
		 * @param string  $attachment    Attachment enable parameter.
		 * @param integer $entry         Form entry data object.
		 * @param array   $form_data     Form data with field params.
		 * @param string  $context       Context for the render, email or backend.
		 * @param integer $connection_id Connection id for the attachment.
		 * @param integer $entry_id      Entry id for the form.
		 */
	public function send_file_as_email_attachment( $attachment, $entry, $form_data, $context, $connection_id, $entry_id ) {

		$file_email_attachments = isset( $form_data['settings']['email'][ $connection_id ]['file-email-attachments'] ) ? $form_data['settings']['email'][ $connection_id ]['file-email-attachments'] : 0;
		if ( isset( $form_data['settings']['disabled_entries'] ) && '1' === $form_data['settings']['disabled_entries'] && '1' === $file_email_attachments ) {
				$attachment = $this->attach_entry_files_upload( $entry );
		} elseif ( isset( $form_data['settings']['disabled_entries'] ) && '1' === $form_data['settings']['disabled_entries'] && ! defined( 'EFP_PLUGIN_FILE' ) ) {
				$attachment = $this->attach_entry_files_upload( $entry );
		}

		if ( '1' === $file_email_attachments ) {
			$attachment = array_unique( array_merge( (array) $attachment, $this->attach_entry_files( $entry_id ) ) );
			return $attachment;
		}

		return $attachment;
	}

	/**
	 * Send CSV file as email attachment.
	 *
	 * @param string  $attachment    Attachment enable parameter.
	 * @param integer $entry         Form entry data object.
	 * @param array   $form_data     Form data with field params.
	 * @param string  $context       Context for the render, email or backend.
	 * @param integer $connection_id Connection id for the attachment.
	 * @param integer $entry_id      Entry id for the form.
	 */
	public function send_csv_file_as_email_attachment( $attachment, $entry, $form_data, $context, $connection_id, $entry_id ) {

		$csv_file_email_attachments = isset( $form_data['settings']['email'][ $connection_id ]['csv-file-email-attachments'] ) ? $form_data['settings']['email'][ $connection_id ]['csv-file-email-attachments'] : 0;
		if ( '1' !== $csv_file_email_attachments ) {
			return $attachment;
		}
		$attachment = array_merge( (array) $attachment, $this->csv_entry_files( $entry_id ) );
		return $attachment;
	}

	/**
	 * Remove CSV file attachment after email sent.
	 *
	 * @param string  $attachment    Attachment enable parameter.
	 * @param integer $entry         Form entry data object.
	 * @param array   $form_data     Form data with field params.
	 * @param string  $context       Context for the render, email or backend.
	 * @param integer $connection_id Connection id for the attachment.
	 * @param integer $entry_id      Entry id for the form.
	 */
	public function remove_csv_file_after_email_send( $attachment, $entry, $form_data, $context, $connection_id, $entry_id ) {

		if ( isset( $form_data['settings']['disabled_entries'] ) && '1' === $form_data['settings']['disabled_entries'] ) {
			if ( ! empty( $entry ) && is_array( $entry ) ) {
				foreach ( $entry as $meta_key => $meta_value ) {
					if ( empty( $meta_value ) ) {
						continue;
					}

					if ( preg_match( '/signature_/', $meta_key ) && file_exists( $meta_value ) ) {
						unlink( $meta_value );
					}

					if ( isset( $meta_value['type'] ) && ( 'file-upload' === $meta_value['type'] && ! empty( $meta_value['value_raw'] ) || 'image-upload' === $meta_value['type'] && isset( $meta_value['value_raw'] ) ) ) {

						foreach ( $meta_value['value_raw'] as $file_data ) {
							if ( isset( $file_data['value'] ) ) {
								$file_url = $file_data['value'];

								$uploaded_file = ABSPATH . preg_replace( '/.*wp-content/', 'wp-content', wp_parse_url( $file_url, PHP_URL_PATH ) );
								if ( file_exists( $uploaded_file ) ) {
									unlink( $uploaded_file );
								}
							}
						}
					}
				}
			}
		}

		if ( ! $entry_id ) {
			return;
		}

		$file_email_attachments     = isset( $form_data['settings']['email'][ $connection_id ]['file-email-attachments'] ) ? $form_data['settings']['email'][ $connection_id ]['file-email-attachments'] : 0;
		$csv_file_email_attachments = isset( $form_data['settings']['email'][ $connection_id ]['csv-file-email-attachments'] ) ? $form_data['settings']['email'][ $connection_id ]['csv-file-email-attachments'] : 0;
		$csv_file_email_attachments = apply_filters( 'everest_forms_change_csv_attachments', $csv_file_email_attachments );

		if ( '1' === $csv_file_email_attachments ) {
			$upload_dir = WP_CONTENT_DIR . '/uploads/Everes-Froms-Entries-CSV-file/';
			$csv_path   = $upload_dir . 'Entry data-' . $entry_id . '.csv';
			if ( file_exists( $csv_path ) ) {
				unlink( $csv_path );
			}
		}
	}

	/**
	 * Attach the entry file.
	 *
	 * @param int $entry_id Entry ID for which file should be attached.
	 */
	public function attach_entry_files( $entry_id ) {
		$entry_files = array();
		if ( $entry_id ) {
			$get_entry = evf_get_entry( $entry_id, 'meta' );
		}

		if ( ! empty( $get_entry->meta ) ) {
			foreach ( $get_entry->meta as $meta_key => $meta_value ) {
				if ( empty( $meta_value ) ) {
					continue;
				}

				if ( preg_match( '/signature_/', $meta_key ) ) {
					if ( file_exists( $meta_value ) ) {
						$entry_files [] = $meta_value;
					}
				} else {
					$files = explode( "\n", $meta_value );

					foreach ( $files as $file ) {
						$resolved = $this->resolve_uploads_file_from_url( $file );

						if ( false !== $resolved && ! in_array( $resolved, $entry_files, true ) ) {
							$entry_files[] = $resolved;
						}
					}
				}
			}
		}

		return $entry_files;
	}

	/**
	 * Attach the entry file.
	 *
	 * @param int $entry Entry for which file should be attached.
	 */
	public function attach_entry_files_upload( $entry ) {
		$entry_files = array();

		if ( ! empty( $entry ) && is_array( $entry ) ) {
			foreach ( $entry as $meta_key => $meta_value ) {
				if ( empty( $meta_value ) ) {
					continue;
				}

				if ( preg_match( '/signature_/', $meta_key ) ) {
					if ( file_exists( $meta_value ) ) {
						$entry_files[] = $meta_value;
					}
				} elseif ( isset( $meta_value['type'] ) && ( 'file-upload' === $meta_value['type'] && ! empty( $meta_value['value_raw'] ) || 'image-upload' === $meta_value['type'] && isset( $meta_value['value_raw'] ) ) ) {
					foreach ( $meta_value['value_raw'] as $file_data ) {
						$file_url = isset( $file_data['value'] ) && is_string( $file_data['value'] ) ? $file_data['value'] : '';
						$resolved = $this->resolve_uploads_file_from_url( $file_url );

						if ( false !== $resolved && ! in_array( $resolved, $entry_files, true ) ) {
							$entry_files[] = $resolved;
						}
					}
				}
			}
		}

		return $entry_files;
	}


	/**
	 * Resolve a file URL to a real local uploads path.
	 *
	 * Validates that the URL belongs to this site's uploads directory and that
	 * the resolved real path stays within that directory (no traversal). Returns
	 * the canonical filesystem path on success, or false on failure.
	 *
	 * @param string $file_url File URL to resolve.
	 * @return string|false
	 */
	protected function resolve_uploads_file_from_url( $file_url ) {
		if ( empty( $file_url ) || ! is_string( $file_url ) ) {
			return false;
		}

		$file_url        = esc_url_raw( $file_url );
		$upload_dir      = wp_get_upload_dir();
		$uploads_baseurl = trailingslashit( $upload_dir['baseurl'] );
		$uploads_basedir = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );

		if ( 0 !== strpos( $file_url, $uploads_baseurl ) ) {
			return false;
		}

		$path      = wp_parse_url( $file_url, PHP_URL_PATH );
		$base_path = wp_parse_url( $uploads_baseurl, PHP_URL_PATH );

		if ( ! is_string( $path ) || ! is_string( $base_path ) || 0 !== strpos( $path, $base_path ) ) {
			return false;
		}

		$relative_path = ltrim( substr( $path, strlen( $base_path ) ), '/' );
		$candidate     = wp_normalize_path( $uploads_basedir . $relative_path );
		$resolved_path = realpath( $candidate );

		if ( false === $resolved_path ) {
			return false;
		}

		$resolved_path = wp_normalize_path( $resolved_path );

		if ( 0 !== strpos( $resolved_path, $uploads_basedir ) || ! is_file( $resolved_path ) ) {
			return false;
		}

		return $resolved_path;
	}

	/**
	 * Attach the csv file.
	 *
	 * @param int $entry_id Entry ID for which file should be attached.
	 */
	public function csv_entry_files( $entry_id ) {
		$csv_entry_file = array();
		if ( $entry_id ) {
			$get_entry = evf_get_entry( $entry_id, 'meta' );
		}

		if ( ! empty( $get_entry->meta ) ) {
			include_once EVF_ABSPATH . 'includes/export/class-evf-entry-csv-exporter.php';
			include_once EFP_ABSPATH . 'includes/export/class-evf-entry-exporter.php';

			$exporter     = new EVF_Entry_CSV_Exporter( $get_entry->form_id, $get_entry->entry_id, array() );
			$column_names = $exporter->get_default_column_names();
			$row_data     = $exporter->prepare_data_to_export();

			$keys_to_remove = array(
				'status',
				'date_created',
				'date_created_gmt',
				'user_device',
				'user_ip_address',
			);

			foreach ( $keys_to_remove as $key ) {
				unset( $column_names[ $key ] );
				unset( $row_data[0][ $key ] );
			}

			$csv_entry_data = implode( ',', $column_names ) . "\n";
			foreach ( $row_data[0]  as $row_value ) {
				$csv_entry_data .= '"' . $row_value . '",';
			}

			// WordPress upload folder path.
			$upload_dir = WP_CONTENT_DIR . '/uploads/Everes-Froms-Entries-CSV-file/';
			if ( ! is_dir( $upload_dir ) ) {
				mkdir( $upload_dir, 0755, true );
				file_put_contents( $upload_dir . '.htaccess', "deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
				file_put_contents( $upload_dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			}
			$csv_path = $upload_dir . 'Entry data-' . $get_entry->entry_id . '.csv';

			// Save CSV file.
			if ( file_put_contents( $csv_path, $csv_entry_data ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
				array_push( $csv_entry_file, $csv_path );
			}
			/**
			 * Enable SSL Certificate verification.
			 */
			$context_options = array(
				'ssl' => array(
					'verify_peer'      => true,
					'verify_peer_name' => true,
				),
			);
			stream_context_set_default( $context_options );
		}

		return $csv_entry_file;
	}


	/**
	 * Return upload validation errors messages.
	 *
	 * @since 1.3.1
	 *
	 * @param int|string $error PHP file upload error code.
	 *
	 * @return array|string|boolean Get validationr message
	 */
	protected function get_upload_validation_errors( $error = null ) {
		$errors = apply_filters(
			'evf_upload_validation_errors',
			array(
				UPLOAD_ERR_INI_SIZE   => esc_html__( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'everest-forms' ),
				UPLOAD_ERR_FORM_SIZE  => esc_html__( 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'everest-forms' ),
				UPLOAD_ERR_PARTIAL    => esc_html__( 'The uploaded file was only partially uploaded.', 'everest-forms' ),
				UPLOAD_ERR_NO_FILE    => esc_html__( 'No file was uploaded.', 'everest-forms' ),
				UPLOAD_ERR_NO_TMP_DIR => esc_html__( 'Missing a temporary folder.', 'everest-forms' ),
				UPLOAD_ERR_CANT_WRITE => esc_html__( 'Failed to write file to disk.', 'everest-forms' ),
				UPLOAD_ERR_EXTENSION  => esc_html__( 'File upload stopped by extension.', 'everest-forms' ),
			)
		);

		if ( null === $error ) {
			return $errors;
		}

		if ( isset( $errors[ $error ] ) ) {
			return $errors[ $error ];
		}

		return true;
	}
}
