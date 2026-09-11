<?php
/**
 * EverestForms Entries Table List
 *
 * @package EverestForms\Admin
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'EVF_Base_List_Table' ) ) {
	require_once __DIR__ . '/class-evf-base-list-table.php';
}

/**
 * Entries table list class.
 */
class EVF_Admin_Entries_Table_List extends EVF_Base_List_Table {

	/**
	 * Form ID.
	 *
	 * @var int
	 */
	public $form_id;

	/**
	 * Forms object.
	 *
	 * @var EVF_Form_Handler
	 */
	public $form;

	/**
	 * Forms object.
	 *
	 * @var EVF_Form_Handler[]
	 */
	public $forms;

	/**
	 * Form data as an array.
	 *
	 * @var array
	 */
	public $form_data;

	/**
	 * Initialize the log table list.
	 */
	public function __construct() {
		$this->forms = evf_get_all_forms( true );
		if ( ! empty( $this->forms ) ) {
			$this->form_id = isset( $_REQUEST['form_id'] ) ? absint( $_REQUEST['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

			$default_form_id = apply_filters( 'everest_forms_entry_list_default_form_id', key( $this->forms ) );
			$active_form_id  = ( $this->form_id > 0 ) ? $this->form_id : $default_form_id;

			$this->form      = evf()->form->get( $active_form_id );
			$this->form_data = ! empty( $this->form->post_content ) ? evf_decode( $this->form->post_content ) : '';
		}

		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get the current action selected from the bulk actions dropdown.
	 *
	 * @since 1.5.3
	 *
	 * @return string|false The action name or False if no action was selected.
	 */
	public function current_action() {
		if ( isset( $_REQUEST['export_action'] ) && ! empty( $_REQUEST['export_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		if ( isset( $_REQUEST['delete_all'] ) || isset( $_REQUEST['delete_all2'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return 'delete_all';
		}

		return parent::current_action();
	}

	/**
	 * No items found text.
	 */
	public function no_items() {
		esc_html_e( 'Whoops, it appears you do not have any form entries yet.', 'everest-forms' );
	}

	/**
	 * Get list columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns       = array();
		$columns['cb'] = '<input type="checkbox" />';
		$columns       = apply_filters( 'everest_forms_add_extra_columns', $columns );

		if ( 0 === $this->form_id ) {
			$columns['entry'] = esc_html__( 'Entry', 'everest-forms' );
			$columns['form']  = esc_html__( 'Form', 'everest-forms' );
			$columns['date']  = esc_html__( 'Date Created', 'everest-forms' );
		} else {
			$columns         = apply_filters( 'everest_forms_entries_table_form_fields_columns', $this->get_columns_form_fields( $columns ), $this->form_id, $this->form_data );
			$columns['date'] = esc_html__( 'Date Created', 'everest-forms' );
		}

		return apply_filters( 'everest_forms_entries_table_columns', $columns, $this->form_data );
	}

	/**
	 * Get a list of sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		$sortable_columns = array();

		if ( isset( $_GET['form_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$sortable_columns = array(
				'date' => array( 'date_created', false ),
			);
		}

		return $sortable_columns;
	}

	/**
	 * Generates content for a single row of the table.
	 *
	 * @since 1.6.0
	 *
	 * @param object $entry Entry data.
	 */
	public function single_row( $entry ) {
		if ( empty( $_GET['status'] ) || ( isset( $_GET['status'] ) && 'trash' !== $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<tr class="' . ( $entry->viewed ? 'read' : 'unread' ) . '">';
			$this->single_row_columns( $entry );
			echo '</tr>';
		} else {
			parent::single_row( $entry );
		}
	}

	/**
	 * Get the list of fields, that are disallowed to be displayed as column in a table.
	 *
	 * @return array
	 */
	public static function get_columns_form_disallowed_fields() {
		return (array) apply_filters( 'everest_forms_entries_table_fields_disallow', array( 'html', 'title', 'captcha', 'repeater-fields', 'authorize-net', 'private-note' ) );
	}

	/**
	 * Logic to determine which fields are displayed in the table columns.
	 *
	 * @param array $columns List of colums.
	 * @param int   $display Numbers of columns to display.
	 *
	 * @return array
	 */
	public function get_columns_form_fields( $columns = array(), $display = 3 ) {
		$entry_columns = evf()->form->get_meta( $this->form_id, 'entry_columns' );

		if ( ! $entry_columns && ! empty( $this->form_data['form_fields'] ) ) {
			$x = 0;
			foreach ( $this->form_data['form_fields'] as $id => $field ) {
				if ( ! in_array( $field['type'], self::get_columns_form_disallowed_fields(), true ) && $x < $display ) {
					$columns[ 'evf_field_' . $id ] = ! empty( $field['label'] ) ? wp_strip_all_tags( $field['label'] ) : esc_html__( 'Field', 'everest-forms' );
					++$x;
				}
			}
		} elseif ( ! empty( $entry_columns ) ) {
			foreach ( $entry_columns as $id ) {
				// Check to make sure the field as not been removed.
				$status = defined( 'EFP_VERSION' ) ? true : false;
				$status = apply_filters( 'everest_forms_plugin_active_status', $status );
				if ( empty( $this->form_data['form_fields'][ $id ] ) ) {
					if ( $status ) {
						if ( 'sn' === $id ) {
							$extra_column = apply_filters( 'everest_forms_entries_table_extra_columns', array(), 0, array() );
							$columns      = array_merge( $columns, $extra_column );
						}
						if ( 'id' === $id ) {
							$extra_column = apply_filters( 'everest_forms_entries_table_extra_columns_id', array(), 0, array() );
							$columns      = array_merge( $columns, $extra_column );
						}
					}
					continue;
				}

				$columns[ 'evf_field_' . $id ] = ! empty( $this->form_data['form_fields'][ $id ]['label'] ) ? wp_strip_all_tags( $this->form_data['form_fields'][ $id ]['label'] ) : esc_html__( 'Field', 'everest-forms' );
			}
		}

		return apply_filters( 'everest_forms_entries_table_form_field_columns', $columns, $this->form_id, $this->form_data );
	}

	/**
	 * Column cb.
	 *
	 * @param  object $entry Entry object.
	 * @return string
	 */
	public function column_cb( $entry ) {
		return sprintf( '<input type="checkbox" name="%1$s[]" value="%2$s" />', $this->_args['singular'], $entry->entry_id );
	}

	/**
	 * Column for simplified "Entry" view (All Forms only)
	 *
	 * @param object $entry Entry object.
	 * @return string
	 */
	public function column_entry( $entry ) {
		if ( empty( $entry->meta ) ) {
			return '<span class="na">&mdash;</span>';
		}

		// Skip system fields
		$skip_fields = array( 'entry_id', 'form_id', 'user_id', 'user_device', 'user_ip_address', 'viewed', 'starred', 'status' );

		// Upload fields store only the hashed file URL in entry meta, so map each
		// upload field's meta key to its original file name(s) from the full
		// entry fields. This keeps the preview readable (e.g. "photo.png") instead
		// of the full URL / hashed file name. See EVF-2095.
		$upload_names = $this->get_upload_field_names( $entry );

		// Find first non-empty field value
		foreach ( $entry->meta as $key => $value ) {
			if ( in_array( $key, $skip_fields, true ) || strpos( $key, '_' ) === 0 ) {
				continue;
			}

			// Prefer the original file name(s) for upload fields.
			if ( isset( $upload_names[ $key ] ) && '' !== $upload_names[ $key ] ) {
				return $upload_names[ $key ];
			}

			// Process the value
			$field_value = $this->process_field_value( $value );

			if ( ! empty( $field_value ) && '&mdash;' !== $field_value ) {
				return $field_value;
			}
		}

		return '<span class="na">&mdash;</span>';
	}

	/**
	 * Build a map of upload field meta keys to their original file name(s).
	 *
	 * Entry meta only stores the public file URL (with a hashed file name), so
	 * the original names are read from the full entry fields payload. See EVF-2095.
	 *
	 * @param object $entry Entry object.
	 * @return array Map of meta key => escaped, comma-separated original file names.
	 */
	private function get_upload_field_names( $entry ) {
		$names = array();

		$fields = isset( $entry->fields ) ? evf_decode( $entry->fields ) : array();

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return $names;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field['meta_key'] ) || empty( $field['type'] ) ) {
				continue;
			}

			if ( ! in_array( $field['type'], array( 'image-upload', 'file-upload' ), true ) ) {
				continue;
			}

			if ( empty( $field['value_raw'] ) || ! is_array( $field['value_raw'] ) ) {
				continue;
			}

			$file_names = array();
			foreach ( $field['value_raw'] as $file ) {
				if ( ! empty( $file['file_original'] ) ) {
					$file_names[] = $file['file_original'];
				} elseif ( ! empty( $file['name'] ) ) {
					$file_names[] = $file['name'];
				}
			}

			if ( ! empty( $file_names ) ) {
				$names[ $field['meta_key'] ] = esc_html( implode( ', ', $file_names ) );
			}
		}

		return $names;
	}

	/**
	 * Column for form name (All Forms view only)
	 *
	 * @param object $entry Entry object.
	 * @return string
	 */
	public function column_form( $entry ) {
		$form_title = get_the_title( $entry->form_id );

		if ( empty( $form_title ) ) {
			return '&mdash;';
		}

		return '<a href="' . esc_url( admin_url( 'admin.php?page=evf-entries&form_id=' . $entry->form_id ) ) . '">' . esc_html( $form_title ) . '</a>';
	}

	/**
	 * Process field value for display
	 *
	 * @param mixed $value Field value.
	 * @return string Processed value.
	 */
	private function process_field_value( $value ) {
		if ( empty( $value ) ) {
			return '&mdash;';
		}

		if ( evf_is_json( $value ) ) {
			$field_value = json_decode( $value, true );
			$value       = isset( $field_value['value'] ) ? $field_value['value'] : $value;
		}

		if ( is_serialized( $value ) ) {
			$field_value = evf_maybe_unserialize( $value );
			$field_label = ! empty( $field_value['label'] ) ? evf_clean( $field_value['label'] ) : $field_value;

			if ( is_array( $field_label ) ) {
				$value = implode( ', ', array_map( 'esc_html', $field_label ) );
			} else {
				$value = esc_html( $field_label );
			}
		}

		if ( is_array( $value ) ) {
			if ( isset( $value['label'] ) ) {
				$value = is_array( $value['label'] ) ? implode( ', ', $value['label'] ) : $value['label'];
			} else {
				$value = implode( ', ', $value );
			}
		}

		if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
			$path = wp_parse_url( $value, PHP_URL_PATH );
			if ( ! empty( $path ) ) {
				$value = basename( $path );
			}
		}

		$value = (string) $value;
		if ( strlen( $value ) > 100 ) {
			$value = substr( $value, 0, 100 ) . '&hellip;';
		}

		$value = wp_strip_all_tags( trim( $value ) );

		return $value;
	}



	/**
	 * Show specific form fields (for specific form view).
	 *
	 * @param  object $entry Entry object.
	 * @param  string $column_name Column Name.
	 * @return string
	 */
	public function column_form_field( $entry, $column_name ) {
		$field_id = str_replace( 'evf_field_', '', $column_name );
		$meta_key = isset( $this->form_data['form_fields'][ $field_id ]['meta-key'] ) ? strtolower( $this->form_data['form_fields'][ $field_id ]['meta-key'] ) : $field_id;
		$field_type = isset( $this->form_data['form_fields'][ $field_id ]['type'] ) ? $this->form_data['form_fields'][ $field_id ]['type'] : '';

		if ( ! empty( $entry->meta[ $meta_key ] ) || ( isset( $entry->meta[ $meta_key ] ) && is_numeric( $entry->meta[ $meta_key ] ) ) ) {
			$value = $entry->meta[ $meta_key ];
			if ( evf_is_json( $value ) ) {
				$field_value = json_decode( $value, true );
				$decoded     = isset( $field_value['value'] ) ? $field_value['value'] : $field_value;

				// Coupons field stores data on array.
				if ( is_array( $decoded ) ) {
					$codes = array();
					foreach ( $decoded as $item ) {
						if ( is_array( $item ) && ! empty( $item['code'] ) ) {
							$codes[] = esc_html( $item['code'] );
						}
					}
					$value = ! empty( $codes ) ? implode( ', ', $codes ) : implode( ', ', array_filter( array_map( 'strval', $decoded ) ) );
				} else {
					$value = $decoded;
				}
			}

			if ( is_serialized( $value ) ) {
				$field_html  = array();
				$field_value = evf_maybe_unserialize( $value );

				$field_label = ! empty( $field_value['label'] ) ? evf_clean( $field_value['label'] ) : $field_value;
				if ( is_array( $field_label ) ) {
					foreach ( $field_label as $item ) {
						// Coupon field: each item is an array with 'code', 'discount_type', etc.
						if ( is_array( $item ) ) {
							if ( ! empty( $item['code'] ) ) {
								$field_html[] = esc_html( $item['code'] );
							}
						} else {
							$field_html[] = esc_html( $item );
						}
					}

					$value = implode( ', ', $field_html );
				} else {
					$value = esc_html( $field_label );
				}
			}

			$value = isset( $value['label'] ) && is_array( $value['label'] ) ? implode( ', ', $value['label'] ) : ( is_array( $value ) ? implode( ', ', $value ) : $value );

			$value = (string) $value;

			if ( false === strpos( $value, 'http' ) ) {
				$lines = explode( "\n", $value );
				$value = array_slice( $lines, 0, 4 );
				$value = implode( "\n", $value );

				if ( count( $lines ) > 5 ) {
					$value .= '&hellip;';
				} elseif ( strlen( $value ) > 75 ) {
					$value = substr( $value, 0, 75 ) . '&hellip;';
				}

				$value = nl2br( wp_strip_all_tags( trim( $value ) ) );
			}
			$value = apply_filters( 'everest_forms_html_field_value', $value, $entry->meta[ $meta_key ], $entry, 'entry-table', $meta_key );

			if ( in_array( $field_type, array( 'file-upload', 'image-upload', 'signature', 'wysiwyg', 'color', 'rating', 'country', 'likert', 'checkbox', 'radio', 'repeater-fields', 'payment-checkbox' ), true ) ) {
				return nl2br( make_clickable( $value ) );
			}elseif( 'address' === $field_type ){
				$allowed_tags = array(
					'br'   => array(),
					'span' => array(),
					'p'    => array(),
				);

				$output = nl2br( $value );
				return wp_kses( $output, $allowed_tags );
			}
			else{
				return esc_html( $value );
			}
		} else {
			return '<span class="na">&mdash;</span>';
		}
	}

	/**
	 * Renders the columns.
	 *
	 * @param  object $entry Entry object.
	 * @param  string $column_name Column Name.
	 * @return string
	 */
	public function column_default( $entry, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				$value = absint( $entry->entry_id );
				break;

			case 'date':
				$value = date_i18n(
					'Y/m/d',
					strtotime( $entry->date_created ) + ( get_option( 'gmt_offset' ) * 3600 )
				);

				break;

			case 'sn':
				$position = array_search( $entry, $this->items, true );
				$value    = $position + 1;
				break;

			case 'entry':
				$value = $this->column_entry( $entry );
				break;

			case 'form':
				$value = $this->column_form( $entry );
				break;

			default:
				if ( false !== strpos( $column_name, 'evf_field_' ) ) {
					$value = $this->column_form_field( $entry, $column_name );
				} else {
					$value = '';
				}
				break;
		}

		return apply_filters( 'everest_forms_entry_table_column_value', $value, $entry, $column_name );
	}

	/**
	 * Get row actions for an entry.
	 *
	 * @param  object $entry Entry object.
	 * @return array
	 */
	protected function get_row_actions( $entry ) {
		$actions = array();
		$status  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

		if ( 'trash' !== $entry->status ) {
			if ( current_user_can( 'everest_forms_view_entry', $entry->entry_id ) ) {
				$actions['view'] = '<a href="' . esc_url( admin_url( 'admin.php?page=evf-entries&amp;form_id=' . $entry->form_id . '&amp;view-entry=' . $entry->entry_id ) ) . '">' . esc_html__( 'View', 'everest-forms' ) . '</a>';
			}

			$user_can_edit_entry = is_admin() && current_user_can( 'everest_forms_edit_entry', $entry->entry_id );

			if ( $user_can_edit_entry ) {
				switch ( $status ) {
					case 'spam':
						$actions['unspam'] = '<a href="' . esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'unspam-entry' => $entry->entry_id,
										'form_id'      => $entry->form_id,
									),
									admin_url( 'admin.php?page=evf-entries' )
								),
								'unspam-entry'
							)
						) . '">' . esc_html__( 'Remove From Spam', 'everest-forms' ) . '</a>';
						break;
					default:
						$actions['spam'] = '<a href="' . esc_url(
							wp_nonce_url(
								add_query_arg(
									array(
										'spam-entry' => $entry->entry_id,
										'form_id'    => $entry->form_id,
									),
									admin_url( 'admin.php?page=evf-entries' )
								),
								'spam-entry'
							)
						) . '">' . esc_html__( 'Mark as Spam', 'everest-forms' ) . '</a>';
						break;
				}
			}

			if ( current_user_can( 'everest_forms_delete_entry', $entry->entry_id ) ) {
				/* translators: %s: entry name */
				$actions['trash'] = '<a class="submitdelete" aria-label="' . esc_attr__( 'Trash form entry', 'everest-forms' ) . '" href="' . esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'trash'   => $entry->entry_id,
								'form_id' => $this->form_id,
							),
							admin_url( 'admin.php?page=evf-entries' )
						),
						'trash-entry'
					)
				) . '">' . esc_html__( 'Trash', 'everest-forms' ) . '</a>';
			}
		} else {
			if ( current_user_can( 'everest_forms_edit_entry', $entry->entry_id ) ) {
				$actions['untrash'] = '<a aria-label="' . esc_attr__( 'Restore form entry from trash', 'everest-forms' ) . '" href="' . esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'untrash' => $entry->entry_id,
								'form_id' => $this->form_id,
							),
							admin_url( 'admin.php?page=evf-entries' )
						),
						'untrash-entry'
					)
				) . '">' . esc_html__( 'Restore', 'everest-forms' ) . '</a>';
			}

			if ( current_user_can( 'everest_forms_delete_entry', $entry->entry_id ) ) {
				/* translators: %s: entry name */
				$actions['delete'] = '<a class="submitdelete" aria-label="' . esc_attr__( 'Delete form entry permanently', 'everest-forms' ) . '" href="' . esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'delete'  => $entry->entry_id,
								'form_id' => $this->form_id,
							),
							admin_url( 'admin.php?page=evf-entries' )
						),
						'delete-entry'
					)
				) . '">' . esc_html__( 'Delete Permanently', 'everest-forms' ) . '</a>';
			}
		}

		return apply_filters( 'everest_forms_entry_table_actions', $actions, $entry );
	}

	/**
	 * Render the actions column.
	 *
	 * @param  object $entry Entry object.
	 * @return string
	 */
	public function column_actions( $entry ) {
		$actions = $this->get_row_actions( $entry );
		return implode( ' <span class="sep">|</span> ', $actions );
	}

	/**
	 * Handle row actions for the primary column.
	 *
	 * @param object $entry       Entry object.
	 * @param string $column_name Current column name.
	 * @param string $primary     Primary column name.
	 * @return string
	 */
	protected function handle_row_actions( $entry, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$actions = $this->get_row_actions( $entry );
		return $this->row_actions( $actions, false );
	}

	/**
	 * Get the name of the primary column.
	 *
	 * @return string
	 */
	protected function get_primary_column_name() {
		// For All Forms view
		if ( 0 === $this->form_id ) {
			return 'entry';
		}

		// For specific form view - use existing logic
		$columns = $this->get_columns();

		unset( $columns['cb'], $columns['actions'], $columns['more'] );

		$primary = '';
		if ( ! empty( $columns ) ) {
			foreach ( $columns as $column_key => $column_name ) {
				if ( strpos( $column_key, 'evf_field_' ) === 0 ) {
					$primary = $column_key;
					break;
				}
			}

			if ( empty( $primary ) ) {
				reset( $columns );
				$primary = key( $columns );
			}
		}

		return $primary;
	}

	/**
	 * Get the status label for entries.
	 *
	 * @param string $status_name Status name.
	 * @param int    $amount      Amount of entries.
	 * @return array
	 */
	private function get_status_label( $status_name, $amount ) {
		$statuses = evf_get_entry_statuses( $this->form_data );

		if ( isset( $statuses[ $status_name ] ) ) {
			return array(
				'singular' => sprintf( '%s <span class="count">(<span class="%s-count">%s</span>)</span>', esc_html( $statuses[ $status_name ] ), $status_name, $amount ),
				'plural'   => sprintf( '%s <span class="count">(<span class="%s-count">%s</span>)</span>', esc_html( $statuses[ $status_name ] ), $status_name, $amount ),
				'context'  => '',
				'domain'   => 'everest-forms',
			);
		}

		return array(
			'singular' => sprintf( '%s <span class="count">(<span class="%s-count">%s</span>)</span>', esc_html( $statuses[ $status_name ] ), $status_name, $amount ),
			'plural'   => sprintf( '%s <span class="count">(%s)</span>', esc_html( $status_name ), $amount ),
			'context'  => '',
			'domain'   => 'everest-forms',
		);
	}

	/**
	 * Table list views.
	 *
	 * @return array
	 */
	protected function get_views() {

		return array();

		// $status_links = array();

		// if ( 0 === $this->form_id ) {
		// $num_entries = $this->get_all_forms_entry_counts();
		// } else {
		// $num_entries = evf_get_count_entries_by_status( $this->form_id );
		// }

		// $total_entries = apply_filters( 'everest_forms_total_entries_count', (int) $num_entries['publish'], $num_entries, $this->form_id );
		// $spam_entries  = apply_filters( 'everest_forms_spam_total_entries_count', (int) $num_entries['spam'], $num_entries, $this->form_id );

		// $statuses = array_keys( evf_get_entry_statuses( $this->form_data ) );
		// $class    = empty( $_REQUEST['status'] ) ? ' class="current"' : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// $base_url = admin_url( 'admin.php?page=evf-entries' );
		// if ( $this->form_id > 0 ) {
		// $base_url = add_query_arg( 'form_id', $this->form_id, $base_url );
		// }

		// /* translators: %s: count */
		// $status_links['all'] = "<a href='" . esc_url( $base_url ) . "'$class>" . sprintf( _nx( 'All <span class="count">(%s)</span>', 'All <span class="count">(%s)</span>', $total_entries, 'entries', 'everest-forms' ), number_format_i18n( $total_entries ) ) . '</a>';

		// /* translators: %s: count */
		// $spam_url             = add_query_arg( 'status', 'spam', $base_url );
		// $spam_class           = ( isset( $_REQUEST['status'] ) && 'spam' === $_REQUEST['status'] ) ? ' class="current"' : '';
		// $status_links['spam'] = "<a href='" . esc_url( $spam_url ) . "'$spam_class>" . sprintf( _nx( 'Spam <span class="count">(%s)</span>', 'Spam <span class="count">(%s)</span>', $spam_entries, 'entries', 'everest-forms' ), number_format_i18n( $spam_entries ) ) . '</a>';

		// foreach ( $statuses as $status_name ) {
		// if ( 'publish' === $status_name ) {
		// continue;
		// }

		// if ( isset( $_REQUEST['status'] ) && sanitize_key( wp_unslash( $_REQUEST['status'] ) ) === $status_name ) { // phpcs:ignore WordPress.Security.NonceVerification
		// $class = ' class="current"';
		// } else {
		// $class = '';
		// }

		// $label      = $this->get_status_label( $status_name, $num_entries[ $status_name ] );
		// $status_url = add_query_arg( 'status', $status_name, $base_url );

		// $status_links[ $status_name ] = "<a href='" . esc_url( $status_url ) . "'$class>" . sprintf( translate_nooped_plural( $label, $num_entries[ $status_name ] ), number_format_i18n( $num_entries[ $status_name ] ) ) . '</a>';
		// }

		// return apply_filters( 'everest_forms_entries_table_views', $status_links, $num_entries, $this->form_data );
	}

	/**
	 * Get entry counts across all forms
	 *
	 * @return array
	 */
	private function get_all_forms_entry_counts() {
		global $wpdb;

		// Initialize counts array with default counts for each status
		$counts = array(
			'publish' => 0,
			'spam'    => 0,
			'trash'   => 0,
			'pending' => 0,
			'denied'  => 0,
			'unread'  => 0,
			'read'    => 0,
			'starred' => 0, // Add starred count
		);

		$results = $wpdb->get_results( "SELECT status, COUNT(*) as count FROM {$wpdb->prefix}evf_entries GROUP BY status" );

		foreach ( $results as $row ) {
			$counts[ $row->status ] = (int) $row->count;
		}

		// Count the unread entries (viewed = 0, status != 'trash')
		$unread_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}evf_entries WHERE viewed = 0 AND status != 'trash'" );

		$read_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}evf_entries WHERE viewed = 1 AND status != 'trash'" );

		$counts['unread'] = (int) $unread_count;
		$counts['read']   = (int) $read_count;

		$starred_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}evf_entries WHERE starred = 1 AND status != 'trash'" );

		$counts['starred'] = (int) $starred_count;

		return $counts;
	}


	/**
	 * Get bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		if ( isset( $_GET['status'] ) && 'trash' === $_GET['status'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$actions = array(
				'untrash' => __( 'Restore', 'everest-forms' ),
				'delete'  => __( 'Delete Permanently', 'everest-forms' ),
			);
		} else {
			$actions = array(
				'trash' => __( 'Move to Trash', 'everest-forms' ),
			);
		}

		return apply_filters( 'everest_forms_entry_bulk_actions', $actions );
	}

	/**
	 * Process bulk actions.
	 *
	 * @since 1.2.0
	 */
	public function process_bulk_action() {
		$pagenum   = $this->get_pagenum();
		$doaction  = $this->current_action();
		$entry_ids = isset( $_REQUEST['entry'] ) ? wp_parse_id_list( wp_unslash( $_REQUEST['entry'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$form_id   = isset( $_REQUEST['form_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['form_id'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$count     = 0;

		if ( $doaction ) {
			check_admin_referer( 'bulk-entries' );

			$sendback = remove_query_arg( array( 'trashed', 'untrashed', 'deleted' ), wp_get_referer() );
			if ( ! $sendback ) {
				$sendback = admin_url( 'admin.php?page=evf-entries' );
			}
			$sendback = add_query_arg( 'paged', $pagenum, $sendback );

			if ( ! isset( $entry_ids ) ) {
				wp_safe_redirect( $sendback );
				exit;
			}

			switch ( $doaction ) {
				case 'star':
				case 'unstar':
					foreach ( $entry_ids as $entry_id ) {
						if ( EVF_Admin_Entries::update_status( $entry_id, $doaction ) ) {
							++$count;
						}
					}

					add_settings_error(
						'bulk_action',
						'bulk_action',
						/* translators: %d: number of entries, %s: entries status */
						sprintf( _n( '%1$d entry successfully %2$s.', '%1$d entries successfully %2$s.', $count, 'everest-forms' ), $count, 'star' === $doaction ? 'starred' : 'unstarred' ),
						'updated'
					);
					break;
				case 'read':
				case 'unread':
					foreach ( $entry_ids as $entry_id ) {
						if ( EVF_Admin_Entries::update_status( $entry_id, $doaction ) ) {
							++$count;
						}
					}

					add_settings_error(
						'bulk_action',
						'bulk_action',
						/* translators: %d: number of entries, %s: entries status */
						sprintf( _n( '%1$d entry successfully marked as %2$s.', '%1$d entries successfully marked as %2$s.', $count, 'everest-forms' ), $count, $doaction ),
						'updated'
					);
					break;
				case 'approved':
					foreach ( $entry_ids as $entry_id ) {
						if ( EVF_Admin_Entries::update_status( $entry_id, $doaction ) ) {
							++$count;
						}
					}
					break;
				case 'denied':
					foreach ( $entry_ids as $entry_id ) {
						if ( EVF_Admin_Entries::update_status( $entry_id, $doaction ) ) {
							++$count;
						}
					}
					break;
				case 'trash':
					foreach ( $entry_ids as $entry_id ) {
						if ( EVF_Admin_Entries::update_status( $entry_id, 'trash' ) ) {
							++$count;
						}
					}

					add_settings_error(
						'bulk_action',
						'bulk_action',
						/* translators: %d: number of entries */
						sprintf( _n( '%d entry moved to the Trash.', '%d entries moved to the Trash.', $count, 'everest-forms' ), $count ),
						'updated'
					);
					break;
				case 'untrash':
					foreach ( $entry_ids as $entry_id ) {
						if ( EVF_Admin_Entries::update_status( $entry_id, 'publish' ) ) {
							++$count;
						}
					}

					add_settings_error(
						'bulk_action',
						'bulk_action',
						/* translators: %d: number of entries */
						sprintf( _n( '%d entry restored from the Trash.', '%d entries restored from the Trash.', $count, 'everest-forms' ), $count ),
						'updated'
					);
					break;
				case 'delete':
					foreach ( $entry_ids as $entry_id ) {
						if ( EVF_Admin_Entries::remove_entry( $entry_id, $form_id ) ) {
							++$count;
						}
					}

					add_settings_error(
						'bulk_action',
						'bulk_action',
						/* translators: %d: number of entries */
						sprintf( _n( '%d entry permanently deleted.', '%d entries permanently deleted.', $count, 'everest-forms' ), $count ),
						'updated'
					);
					break;
				case 'spam':
					foreach ( $entry_ids as $entry_id ) {
						if ( EVF_Admin_Entries::update_status( $entry_id, 'spam' ) ) {
							++$count;
						}
					}

					add_settings_error(
						'bulk_action',
						'bulk_action',
						/* translators: %d: number of entries */
						sprintf( _n( '%d entry sent to spam.', '%d entries sent to Spam.', $count, 'everest-forms' ), $count ),
						'updated'
					);
					break;
				case 'unspam':
					foreach ( $entry_ids as $entry_id ) {
						if ( EVF_Admin_Entries::update_status( $entry_id, $doaction ) ) {
							++$count;
						}
					}

					add_settings_error(
						'bulk_action',
						'bulk_action',
						/* translators: %d: number of entries */
						sprintf( _n( '%d removed from spam.', '%d entries removed from spam.', $count, 'everest-forms' ), $count ),
						'updated'
					);
					break;
			}
			$sendback = remove_query_arg( array( 'action', 'action2' ), $sendback );

			wp_safe_redirect( $sendback );
			exit();
		} elseif ( ! empty( $_REQUEST['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce' ), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			exit();
		}
	}

	/**
	 * Extra controls to be displayed between bulk actions and pagination.
	 *
	 * @param string $which The location of the extra table nav markup.
	 */
	protected function extra_tablenav( $which ) {
		global $entries_table_list, $wpdb;
		$num_entries = ( 0 === $this->form_id ) ? $this->get_all_forms_entry_counts() : evf_get_count_entries_by_status( $this->form_id );
		$show_export = isset( $_GET['status'] ) && 'trash' === $_GET['status'] ? false : true; // phpcs:ignore WordPress.Security.NonceVerification
		?>
	<div class="everest-forms-extra-table-nav">
		<?php
		if ( ! empty( $this->forms ) && 'top' === $which ) {
			if ( defined( 'EFP_VERSION' ) && $this->form_id > 0 ) {
				?>
			<button
				type="button"
				class="button evf-manage-columns-btn everest-forms-entries-setting"
				id="evf-manage-columns"
				data-evf-form_id="<?php echo esc_attr( $this->form_id ); ?>"
			>
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 6px;">
					<path d="M2 4.66667H14M2 8H14M2 11.3333H14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<?php esc_html_e( 'Manage Columns', 'everest-forms' ); ?>
			</button>
				<?php
			}

			$this->status_dropdown( $num_entries );

			// Export CSV submit button.
			if ( apply_filters( 'everest_forms_enable_csv_export', $show_export ) && current_user_can( 'export' ) && $this->form_id > 0 ) {
				?>
			<button type="submit" name="export_action" value="1" class="button" id="export-csv-submit">
				<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 6px;">
					<path d="M14 10V12.6667C14 13.0203 13.8595 13.3594 13.6095 13.6095C13.3594 13.8595 13.0203 14 12.6667 14H3.33333C2.97971 14 2.64057 13.8595 2.39052 13.6095C2.14048 13.3594 2 13.0203 2 12.6667V10M11.3333 5.33333L8 2M8 2L4.66667 5.33333M8 2V10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<?php esc_html_e( 'Export CSV', 'everest-forms' ); ?>
			</button>
				<?php
			}

			if ( $num_entries['trash'] && isset( $_GET['status'] ) && 'trash' === $_GET['status'] && current_user_can( 'manage_everest_forms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				submit_button( __( 'Empty Trash', 'everest-forms' ), 'apply', 'delete_all', false );
			}
		}
		?>
	</div>
		<?php
		do_action( 'everest_forms_entries_table_extra_filters', $this->form_id, $which );
	}

	/**
	 * Display a form dropdown for filtering entries.
	 */
	public function forms_dropdown() {
		$forms   = evf_get_all_forms( true );
		$form_id = isset( $_REQUEST['form_id'] ) ? absint( $_REQUEST['form_id'] ) : $this->form_id; // phpcs:ignore WordPress.Security.NonceVerification
		?>
	<label for="filter-by-form" class="screen-reader-text"><?php esc_html_e( 'Filter by form', 'everest-forms' ); ?></label>
	<select name="form_id" id="filter-by-form" class="evf-enhanced-normal-select evf-auto-filter" style="min-width: 200px;" data-placeholder="<?php esc_attr_e( 'Search form...', 'everest-forms' ); ?>">
		<option value="0" <?php selected( $form_id, 0 ); ?>><?php esc_html_e( 'All Forms', 'everest-forms' ); ?></option>
		<?php foreach ( $forms as $id => $form ) : ?>
			<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $form_id, $id ); ?>><?php echo esc_html( $form ); ?></option>
		<?php endforeach; ?>
	</select>
		<?php
	}

	/**
	 * Display a status dropdown for filtering entries.
	 */
	public function status_dropdown( $num_entries ) {
		$current_status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';

		$statuses = array(
			'all'    => __( 'All', 'everest-forms' ),
			'unread' => __( 'Unread', 'everest-forms' ),
			'read'   => __( 'Read', 'everest-forms' ),
			'spam'   => __( 'Spam', 'everest-forms' ),
			'trash'  => __( 'Trash', 'everest-forms' ),
		);

		if ( ! empty( $this->form_data ) ) {
			$extra_statuses = evf_get_entry_statuses( $this->form_data );
			foreach ( $extra_statuses as $key => $label ) {
				if ( ! isset( $statuses[ $key ] ) && 'publish' !== $key ) {
					$statuses[ $key ] = $label;
				}
			}
		}

		// Map status keys to count keys.
		$count_map = array(
			'all'    => 'publish',
			'unread' => 'unread',
			'read'   => 'read',
			'spam'   => 'spam',
			'trash'  => 'trash',
		);
		?>
	<select name="status" id="filter-by-status" class="evf-enhanced-normal-select evf-auto-filter">
		<?php
		foreach ( $statuses as $key => $label ) :
			$count_key = isset( $count_map[ $key ] ) ? $count_map[ $key ] : $key;
			$count     = isset( $num_entries[ $count_key ] ) ? (int) $num_entries[ $count_key ] : 0;
			$display   = sprintf( '%s (%d)', $label, $count );
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_status, $key ); ?>>
				<?php echo esc_html( $display ); ?>
			</option>
		<?php endforeach; ?>
	</select>
		<?php
	}


	/**
	 * Prepare table list items.
	 */
	public function prepare_items() {
		$per_page     = $this->get_items_per_page( 'evf_entries_per_page' );
		$current_page = $this->get_pagenum();

		// Query args.
		$args = array(
			'status'  => 'publish',
			'form_id' => $this->form_id,
			'limit'   => $per_page,
			'offset'  => $per_page * ( $current_page - 1 ),
		);

		// Handle the status query.
		if ( ! empty( $_REQUEST['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$status = sanitize_key( wp_unslash( $_REQUEST['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

			if ( 'unread' === $status ) {
				$args['status'] = 'publish';
				$args['viewed'] = 0;
			} elseif ( 'read' === $status ) {
				$args['status'] = 'publish';
				$args['viewed'] = 1;
			} elseif ( 'all' === $status ) {
				$args['status'] = 'publish';
			} else {
				$args['status'] = $status;
			}
		}

		// Handle the search query.
		if ( ! empty( $_REQUEST['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['search'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( ! empty( $_REQUEST['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['orderby'] = sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( ! empty( $_REQUEST['order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['order'] = sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		// Get the entries.
		$entries     = evf_search_entries( $args );
		$this->items = array_map( 'evf_get_entry', $entries );

		// Get total items.
		$args['limit']  = -1;
		$args['offset'] = 0;
		$total_items    = count( evf_search_entries( $args ) );

		// Set the pagination.
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			)
		);
	}
}
