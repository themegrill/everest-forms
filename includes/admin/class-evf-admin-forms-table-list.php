<?php
/**
 * EverestForms Forms Table List
 *
 * @package EverestForms\Admin
 * @version 1.0.0
 */

use EverestForms\Helpers\FormHelper;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'EVF_Base_List_Table' ) ) {
	require_once __DIR__ . '/class-evf-base-list-table.php';
}

/**
 * Forms table list class.
 */
class EVF_Admin_Forms_Table_List extends EVF_Base_List_Table {

	/**
	 * Initialize the form table list.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'form',
				'plural'   => 'forms',
				'ajax'     => false,
			)
		);
	}

	/**
	 * No items found text.
	 */
	public function no_items() {
		esc_html_e( 'No Forms found.', 'everest-forms' );
	}

	/**
	 * Get list columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$has_tags = ! empty( FormHelper::get_all_form_tags( 'term_id' ) );

		$forms_columns = array(
			'cb'    => '<input type="checkbox" />',
			'title' => esc_html__( 'Title', 'everest-forms' ),
		);

		if ( $has_tags ) {
			$forms_columns['tags'] = esc_html__( 'Tags', 'everest-forms' );
		}

		$forms_columns['shortcode'] = esc_html__( 'Shortcode', 'everest-forms' );
		$forms_columns['enabled']   = esc_html__( 'Status', 'everest-forms' );
		$forms_columns['author']    = esc_html__( 'Author', 'everest-forms' );
		$forms_columns['date']      = esc_html__( 'Date', 'everest-forms' );

		if ( isset( $_GET['status'] ) && 'trash' === $_GET['status'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			unset( $forms_columns['enabled'] );
		}

		if ( current_user_can( 'everest_forms_view_entries' ) || current_user_can( 'everest_forms_view_others_entries' ) ) {
			$forms_columns['entries'] = esc_html__( 'Entries', 'everest-forms' );
		}

		if ( isset( $_GET['status'] ) && 'trash' !== $_GET['status'] && ! current_user_can( 'everest_forms_delete_forms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			unset( $forms_columns['cb'] );
		}

		return $forms_columns;
	}

	/**
	 * Get a list of sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title'  => array( 'title', false ),
			'author' => array( 'author', false ),
			'date'   => array( 'date', false ),
		);
	}

	/**
	 * Column cb.
	 *
	 * @param  object $form Form object.
	 * @return string
	 */
	public function column_cb( $form ) {
		$show   = current_user_can( 'everest_forms_edit_form', $form->ID );
		$delete = current_user_can( 'everest_forms_delete_form', $form->ID );

		/**
		 * Filters whether to show the bulk edit checkbox for a form in its list table.
		 *
		 * By default the checkbox is only shown if the current user can edit the form.
		 *
		 * @since 1.7.5
		 *
		 * @param bool    $show Whether to show the checkbox.
		 * @param WP_Post $post The current WP_Post object.
		 */
		if ( apply_filters( 'everest_forms_list_table_show_form_checkbox', $show, $form ) || apply_filters( 'everest_forms_list_table_delete_form_checkbox', $delete, $form ) ) {
			return sprintf( '<input type="checkbox" name="form_id[]" value="%1$s" />', esc_attr( $form->ID ) );
		}
	}

	/**
	 * Column enabled.
	 *
	 * @param  object $posts Form object.
	 * @return string
	 */
	public function column_enabled( $posts ) {
		$form_data    = evf()->form->get( absint( $posts->ID ), array( 'content_only' => true ) );
		$form_enabled = isset( $form_data['form_enabled'] ) ? $form_data['form_enabled'] : 1;

		if ( current_user_can( 'everest_forms_edit_form', $posts->ID ) ) {
			return '<label class="everest-forms-toggle-form form-enabled"><input type="checkbox" data-form_id="' . absint( $posts->ID ) . '" value="1" ' . checked( 1, $form_enabled, false ) . '/><span class="slider round"></span></label>';
		}
	}

	/**
	 * Return title column.
	 *
	 * @param  object $posts Form object.
	 * @return string
	 */
	public function column_title( $posts ) {
		$edit_link        = admin_url( 'admin.php?page=evf-builder&tab=fields&form_id=' . $posts->ID );
		$preview_link     = add_query_arg(
			array(
				'form_id'     => absint( $posts->ID ),
				'evf_preview' => 'true',
			),
			home_url()
		);
		$title            = _draft_or_post_title( $posts->ID );
		$post_type_object = get_post_type_object( 'everest_form' );
		$post_status      = $posts->post_status;
		$form_data        = ! empty( $posts->post_content ) ? evf_decode( $posts->post_content ) : array();
		// Title.
		$output = '<strong>';
		if ( 'trash' === $post_status ) {
			$output .= esc_html( $title );
		} else {
			$name = esc_html( $title );

			if ( current_user_can( 'everest_forms_view_form', $posts->ID ) ) {
				$name = '<a href="' . esc_url( $preview_link ) . '" title="' . esc_html__( 'View Preview', 'everest-forms' ) . '" class="row-title" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
			}

			if ( current_user_can( 'everest_forms_view_form_entries', $posts->ID ) ) {
				$name = '<a href="' . esc_url( admin_url( 'admin.php?page=evf-entries&amp;form_id=' . $posts->ID ) ) . '" title="' . esc_html__( 'View Entries', 'everest-forms' ) . '" class="row-title">' . esc_html( $title ) . '</a>';
			}

			if ( current_user_can( 'everest_forms_edit_form', $posts->ID ) ) {
				$name = '<a href="' . esc_url( $edit_link ) . '" title="' . esc_html__( 'Edit this Form', 'everest-forms' ) . '" class="row-title">' . esc_html( $title ) . '</a>';
			}

			$output .= $name;
		}
		$output .= '</strong>';

		// Get actions.
		$actions = array();

		if ( current_user_can( 'everest_forms_edit_form', $posts->ID ) && 'trash' !== $post_status ) {
			$actions['edit'] = '<a href="' . esc_url( $edit_link ) . '" title="' . esc_html__( 'Edit this Form', 'everest-forms' ) . '">' . __( 'Edit', 'everest-forms' ) . '</a>';
		}

		if ( current_user_can( 'everest_forms_view_form_entries', $posts->ID ) && 'trash' !== $post_status ) {
			$actions['entries'] = '<a href="' . esc_url( admin_url( 'admin.php?page=evf-entries&amp;form_id=' . $posts->ID ) ) . '" title="' . esc_html__( 'View Entries', 'everest-forms' ) . '">' . __( 'Entries', 'everest-forms' ) . '</a>';
		}

		if ( current_user_can( 'everest_forms_delete_form', $posts->ID ) ) {
			if ( 'trash' === $post_status ) {
				$actions['untrash'] = '<a aria-label="' . esc_attr__( 'Restore this item from the Trash', 'everest-forms' ) . '" href="' . wp_nonce_url( admin_url( sprintf( $post_type_object->_edit_link . '&amp;action=untrash', $posts->ID ) ), 'untrash-post_' . $posts->ID ) . '">' . esc_html__( 'Restore', 'everest-forms' ) . '</a>';
			} elseif ( EMPTY_TRASH_DAYS ) {
				$actions['trash'] = '<a class="submitdelete" aria-label="' . esc_attr__( 'Move this item to the Trash', 'everest-forms' ) . '" href="' . get_delete_post_link( $posts->ID ) . '">' . esc_html__( 'Trash', 'everest-forms' ) . '</a>';
			}
			if ( 'trash' === $post_status || ! EMPTY_TRASH_DAYS ) {
				$actions['delete'] = '<a class="submitdelete" aria-label="' . esc_attr__( 'Delete this item permanently', 'everest-forms' ) . '" href="' . get_delete_post_link( $posts->ID, '', true ) . '">' . esc_html__( 'Delete permanently', 'everest-forms' ) . '</a>';
			}
		}

		if ( current_user_can( 'everest_forms_view_form', $posts->ID ) ) {
			$preview_link       = add_query_arg(
				array(
					'form_id'     => absint( $posts->ID ),
					'evf_preview' => 'true',
				),
				home_url()
			);
			$duplicate_link     = wp_nonce_url( admin_url( 'admin.php?page=evf-builder&action=duplicate_form&form_id=' . absint( $posts->ID ) ), 'everest-forms-duplicate-form_' . $posts->ID );
			$form_settings_link = admin_url( 'admin.php?page=evf-builder&tab=settings&form_id=' . absint( $posts->ID ) );

			if ( 'trash' !== $post_status ) {
				$actions['view'] = '<a href="' . esc_url( $preview_link ) . '" rel="bookmark" target="_blank">' . __( 'Preview', 'everest-forms' ) . '</a>';
			}

			if ( 'publish' === $post_status && current_user_can( 'everest_forms_create_forms' ) ) {
				$actions['settings'] = '<a href="' . $form_settings_link . '" target="__blank">' . __( 'Settings', 'everest-forms' ) . '</a>';
			}

			if ( isset( $form_data['settings']['enable_conversational_forms'] ) && $form_data['settings']['enable_conversational_forms'] ) {
				$actions['view_conversational_forms'] = '<a href="' . esc_url( home_url( $posts->post_name ) ) . '" title="View ConversationalForm"  target="_blank">' . __( 'Conversational Form Preview', 'everest-forms' ) . '</a>';
			}

			if ( 'publish' === $post_status && current_user_can( 'everest_forms_create_forms' ) ) {
				$actions['duplicate'] = '<a href="' . esc_url( $duplicate_link ) . '">' . __( 'Duplicate', 'everest-forms' ) . '</a>';
			}

			if ( 'publish' === $post_status && current_user_can( 'everest_forms_create_forms' ) ) {
				$actions['locate'] = '<a href="#" class="evf-form-locate" data-id= "' . esc_attr( $posts->ID ) . '">' . __( 'Locate', 'everest-forms' ) . '</a>';
			}
		}

		/**
		 * Filter form list row actions.
		 *
		 * @since 3.4.2
		 * @param array  $actions Array of row actions.
		 * @param object $posts   Form object.
		 * @return array
		 */
		$actions = apply_filters( 'everest_forms_form_list_actions', $actions, $posts );

		$row_actions = array();

		foreach ( $actions as $action => $link ) {
			$row_actions[] = '<span class="' . esc_attr( $action ) . '">' . $link . '</span>';
		}

		$output .= '<div class="row-actions">' . implode( ' | ', $row_actions ) . '</div>';

		return $output;
	}

	/**
	 * Return shortcode column.
	 *
	 * @param object $posts Form object.
	 */
	public function column_shortcode( $posts ) {
		?>
		<span class="shortcode evf-shortcode-field">
			<input type="text" onfocus="this.select();" readonly="readonly" value="<?php echo esc_attr( '[everest_form id="' . absint( $posts->ID ) . '"]' ); ?> " class="large-text code">
			<button class="button evf-copy-shortcode help_tip" type="button" href="#" data-tip="<?php esc_attr_e( 'Copy Shortcode!', 'everest-forms' ); ?>" data-copied="<?php esc_attr_e( 'Copied!', 'everest-forms' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
				<path fill="#383838" fill-rule="evenodd" d="M3.116 3.116A1.25 1.25 0 0 1 4 2.75h9A1.25 1.25 0 0 1 14.25 4v1a.75.75 0 0 0 1.5 0V4A2.75 2.75 0 0 0 13 1.25H4A2.75 2.75 0 0 0 1.25 4v9A2.75 2.75 0 0 0 4 15.75h1a.75.75 0 0 0 0-1.5H4A1.25 1.25 0 0 1 2.75 13V4c0-.332.132-.65.366-.884ZM9.75 11c0-.69.56-1.25 1.25-1.25h9c.69 0 1.25.56 1.25 1.25v9c0 .69-.56 1.25-1.25 1.25h-9c-.69 0-1.25-.56-1.25-1.25v-9ZM11 8.25A2.75 2.75 0 0 0 8.25 11v9A2.75 2.75 0 0 0 11 22.75h9A2.75 2.75 0 0 0 22.75 20v-9A2.75 2.75 0 0 0 20 8.25h-9Z" clip-rule="evenodd"></path>
			</svg>
			</button>
		</span>
		<?php
	}

	/**
	 * Return author column.
	 *
	 * @param  object $posts Form object.
	 * @return string
	 */
	public function column_author( $posts ) {
		$user = get_user_by( 'id', $posts->post_author );

		if ( ! $user ) {
			return '<span class="na">&ndash;</span>';
		}

		$user_name = ! empty( $user->data->display_name ) ? $user->data->display_name : $user->data->user_login;

		if ( current_user_can( 'edit_user' ) ) {
			return '<a href="' . esc_url(
				add_query_arg(
					array(
						'user_id' => $user->ID,
					),
					admin_url( 'user-edit.php' )
				)
			) . '">' . esc_html( $user_name ) . '</a>';
		}

		return esc_html( $user_name );
	}

	/**
	 * Return date column.
	 *
	 * @param  object $posts Form object.
	 * @return string
	 */
	public function column_date( $posts ) {
		$post = get_post( $posts->ID );

		if ( ! $post ) {
			return;
		}

		$t_time = mysql2date(
			__( 'Y/m/d g:i:s A', 'everest-forms' ),
			$post->post_date,
			true
		);
		$m_time = $post->post_date;
		$time   = mysql2date( 'G', $post->post_date ) - get_option( 'gmt_offset' ) * 3600;

		$time_diff = time() - $time;

		if ( $time_diff > 0 && $time_diff < 24 * 60 * 60 ) {
			$h_time = sprintf(
				/* translators: %s: Time */
				__( '%s ago', 'everest-forms' ),
				human_time_diff( $time )
			);
		} else {
			$h_time = mysql2date( __( 'Y/m/d', 'everest-forms' ), $m_time );
		}

		return '<abbr title="' . $t_time . '">' . $h_time . '</abbr>';
	}

	/**
	 * Return entries count.
	 *
	 * @param  object $posts Form object.
	 * @return string
	 */
	public function column_entries( $posts ) {
		global $wpdb;

		if ( ! current_user_can( 'everest_forms_view_form_entries', $posts->ID ) ) {
			return '-';
		}

		$entries = count( $wpdb->get_results( $wpdb->prepare( "SELECT form_id FROM {$wpdb->prefix}evf_entries WHERE `status` != 'trash' AND form_id = %d", $posts->ID ) ) ); // WPCS: cache ok, DB call ok.

		if ( isset( $_GET['status'] ) && 'trash' === $_GET['status'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '<strong>' . absint( $entries ) . '</strong>';
		} else {
			return '<a href="' . esc_url( admin_url( 'admin.php?page=evf-entries&amp;form_id=' . $posts->ID ) ) . '">' . absint( $entries ) . '</a>';
		}
	}

	/**
	 * Return entries count.
	 *
	 * @param  object $posts Form object.
	 * @return string
	 */
	public function column_tags( $posts ) {
		$tags = FormHelper::get_form_tags( $posts->ID );

		if ( empty( $tags ) ) {
			return '<span class="na">&ndash;</span>';
		}

		$tag_count = count( $tags );
		$i         = 0;
		$output    = '';

		foreach ( $tags as $id => $tag ) {
			if ( empty( $tag ) ) {
				continue;
			}

			$output .= sprintf(
				'<button type="submit" name="tags" class="button button-small evf-form-tags" value="%s" data-tag-id="%s">%s</button>',
				esc_attr( $id ),
				esc_attr( $id ),
				esc_html( $tag )
			);

			if ( ++$i < $tag_count ) {
				$output .= ' ';
			}
		}

		return $output ?: '<span class="na">&ndash;</span>';
	}

	/**
	 * Table list views.
	 *
	 * @return array
	 */
	protected function get_views() {
		return array();
		// global $wpdb;

		// $class        = '';
		// $status_links = array();
		// $num_posts    = array();

		// $total_posts = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'everest_form' AND post_status IN ( 'publish', 'inactive' )" ); // WPCS: cache ok, db call ok.
		// $all_args    = array( 'page' => 'evf-builder' );
		// if ( empty( $class ) && empty( $_REQUEST['status'] ) ) {
		// $class = 'current';
		// }

		// $all_inner_html = sprintf(
		// // * translators: %s: count */
		// _nx(
		// 'All <span class="count">(%s)</span>',
		// 'All <span class="count">(%s)</span>',
		// $total_posts,
		// 'posts',
		// 'everest-forms'
		// ),
		// number_format_i18n( $total_posts )
		// );

		// $status_links['all'] = $this->get_edit_link( $all_args, $all_inner_html, $class );

		// foreach ( get_post_stati( array( 'show_in_admin_status_list' => true ), 'objects' ) as $status ) {
		// $class                     = '';
		// $status_name               = $status->name;
		// $num_posts[ $status_name ] = count( evf()->form->get_multiple( array( 'post_status' => $status_name ) ) );

		// if ( ! in_array( $status_name, array( 'publish', 'draft', 'pending', 'trash', 'future', 'private', 'auto-draft', 'approved', 'denied' ), true ) || empty( $num_posts[ $status_name ] ) ) {
		// continue;
		// }

		// if ( isset( $_REQUEST['status'] ) && $status_name === $_REQUEST['status'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		// $class = 'current';
		// }

		// $status_args = array(
		// 'page'   => 'evf-builder',
		// 'status' => $status_name,
		// );

		// $status_label = sprintf(
		// translate_nooped_plural( $status->label_count, $num_posts[ $status_name ] ),
		// number_format_i18n( $num_posts[ $status_name ] )
		// );

		// if ( 'publish' === $status_name ) {
		// $status_label = str_replace( 'Published', __( 'Active', 'everest-forms' ), $status_label );
		// }
		// $status_links[ $status_name ] = $this->get_edit_link( $status_args, $status_label, $class );
		// }

		// $inactive_count = count( evf()->form->get_multiple( array( 'post_status' => 'inactive' ) ) );

		// $inactive_args = array(
		// 'page'   => 'evf-builder',
		// 'status' => 'inactive',
		// );

		// $inactive_label           = sprintf(
		// _nx(
		// 'Inactive <span class="count">(%s)</span>',
		// 'Inactive <span class="count">(%s)</span>',
		// $inactive_count,
		// 'posts',
		// 'everest-forms'
		// ),
		// number_format_i18n( $inactive_count )
		// );
		// $status_links['inactive'] = $this->get_edit_link( $inactive_args, $inactive_label, $class );

		// return $status_links;
	}

	/**
	 * Helper to create links to admin.php with params.
	 *
	 * @since 1.5.3
	 *
	 * @param string[] $args  Associative array of URL parameters for the link.
	 * @param string   $label Link text.
	 * @param string   $class Optional. Class attribute. Default empty string.
	 * @return string  The formatted link string.
	 */
	protected function get_edit_link( $args, $label, $class = '' ) {
		$url = add_query_arg( $args, 'admin.php' );

		$class_html   = '';
		$aria_current = '';

		if ( ! empty( $class ) ) {
			$class_html = sprintf(
				' class="%s"',
				esc_attr( $class )
			);

			if ( 'current' === $class ) {
				$aria_current = ' aria-current="page"';
			}
		}

		return sprintf(
			'<a href="%s"%s%s>%s</a>',
			esc_url( $url ),
			$class_html,
			$aria_current,
			$label
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		$actions = array();

		$has_tags = ! empty( FormHelper::get_all_form_tags( 'term_id' ) );

		if ( isset( $_GET['status'] ) && 'trash' === $_GET['status'] ) {
			if ( current_user_can( 'everest_forms_edit_forms' ) ) {
				$actions['untrash'] = esc_html__( 'Restore', 'everest-forms' );
			}

			if ( current_user_can( 'everest_forms_delete_forms' ) ) {
				$actions['delete'] = esc_html__( 'Delete permanently', 'everest-forms' );
			}
		} elseif ( current_user_can( 'everest_forms_delete_forms' ) ) {
			$actions = array(
				'trash' => esc_html__( 'Move to trash', 'everest-forms' ),
			);

			if ( $has_tags ) {
				$actions['edit-tags'] = esc_html__( 'Edit Tags', 'everest-forms' );
			}

			$actions['inactive'] = esc_html__( 'Inactive', 'everest-forms' );
			$actions['active']   = esc_html__( 'Active', 'everest-forms' );
		}

		return $actions;
	}


	/**
	 * Process bulk actions.
	 *
	 * @since 1.2.0
	 */
	public function process_bulk_action() {
		$action   = $this->current_action();
		$form_ids = isset( $_REQUEST['form_id'] ) ? wp_parse_id_list( wp_unslash( $_REQUEST['form_id'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$count    = 0;

		if ( $form_ids ) {
			check_admin_referer( 'bulk-forms' );
		}

		switch ( $action ) {
			case 'trash':
				foreach ( $form_ids as $form_id ) {
					if ( wp_trash_post( $form_id ) ) {
						++$count;
					}
				}

				add_settings_error(
					'bulk_action',
					'bulk_action',
					/* translators: %d: number of forms */
					sprintf( _n( '%d form moved to the Trash.', '%d forms moved to the Trash.', $count, 'everest-forms' ), $count ),
					'updated'
				);
				break;
			case 'untrash':
				foreach ( $form_ids as $form_id ) {
					if ( wp_untrash_post( $form_id ) ) {
						++$count;
					}
				}

				add_settings_error(
					'bulk_action',
					'bulk_action',
					/* translators: %d: number of forms */
					sprintf( _n( '%d form restored from the Trash.', '%d forms restored from the Trash.', $count, 'everest-forms' ), $count ),
					'updated'
				);
				break;
			case 'delete':
				foreach ( $form_ids as $form_id ) {
					if ( wp_delete_post( $form_id, true ) ) {
						++$count;
					}
				}

				add_settings_error(
					'bulk_action',
					'bulk_action',
					/* translators: %d: number of forms */
					sprintf( _n( '%d form permanently deleted.', '%d forms permanently deleted.', $count, 'everest-forms' ), $count ),
					'updated'
				);
				break;
			case 'inactive':
				foreach ( $form_ids as $form_id ) {
					$result = wp_update_post(
						array(
							'ID'          => $form_id,
							'post_status' => 'inactive',
						)
					);
					if ( $result ) {
						$form_data                 = evf()->form->get( absint( $form_id ), array( 'content_only' => true ) );
						$form_data['form_enabled'] = 0;

						evf()->form->update( $form_id, $form_data );
						++$count;
					}
				}

				add_settings_error(
					'bulk_action',
					'bulk_action',
					/* translators: %d: number of forms */
					sprintf( _n( '%d form marked as inactive.', '%d forms marked as inactive.', $count, 'everest-forms' ), $count ),
					'updated'
				);
				break;

			case 'active':
				foreach ( $form_ids as $form_id ) {
					$result = wp_update_post(
						array(
							'ID'          => $form_id,
							'post_status' => 'publish',
						)
					);
					if ( $result ) {
						$form_data                 = evf()->form->get( absint( $form_id ), array( 'content_only' => true ) );
						$form_data['form_enabled'] = 1;

						evf()->form->update( $form_id, $form_data );
						++$count;
					}
				}

				add_settings_error(
					'bulk_action',
					'bulk_action',
					/* translators: %d: number of forms */
					sprintf( _n( '%d form marked as active.', '%d forms marked as active.', $count, 'everest-forms' ), $count ),
					'updated'
				);
				break;
		}
	}

	/**
	 * Extra controls to be displayed between bulk actions and pagination.
	 *
	 * @param string $which The location of the extra table nav markup.
	 */
	protected function extra_tablenav( $which ) {
		$num_posts = wp_count_posts( 'everest_form', 'readable' );
		$tag_list  = FormHelper::get_all_form_tags( 'term_id' );

		echo '<div class="everest-forms-extra-table-nav">';

		if ( 'top' === $which ) {
			$this->forms_status_dropdown();

			if ( ! empty( $tag_list ) ) {
				$this->tags_dropdown();
				submit_button( __( 'Filter', 'everest-forms' ), '', 'filter_action', false, array( 'category' => 'post-query-submit' ) );
			}
			$this->manage_tags();
			if ( $num_posts->trash && isset( $_GET['status'] ) && 'trash' === $_GET['status'] && current_user_can( 'everest_forms_delete_forms' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				submit_button( __( 'Empty Trash', 'everest-forms' ), 'apply', 'delete_all', false );
			}
		}

		echo '</div>';
	}

	/**
	 * Display a status dropdown for filtering forms.
	 */
	public function forms_status_dropdown() {
		global $wpdb;

		$current_status = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$total_posts    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'everest_form' AND post_status IN ( 'publish', 'inactive' )" );
		$active_count   = count( evf()->form->get_multiple( array( 'post_status' => 'publish' ) ) );
		$inactive_count = count( evf()->form->get_multiple( array( 'post_status' => 'inactive' ) ) );
		$trash_count    = count( evf()->form->get_multiple( array( 'post_status' => 'trash' ) ) );

		$statuses = array(
			''         => sprintf( __( 'All (%d)', 'everest-forms' ), $total_posts ),
			'publish'  => sprintf( __( 'Active (%d)', 'everest-forms' ), $active_count ),
			'inactive' => sprintf( __( 'Inactive (%d)', 'everest-forms' ), $inactive_count ),
			'trash'    => sprintf( __( 'Trash (%d)', 'everest-forms' ), $trash_count ),
		);
		?>
	<label for="filter-by-form-status" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'everest-forms' ); ?></label>
	<select name="status" class="evf-enhanced-select" id="filter-by-form-status" >
		<?php foreach ( $statuses as $value => $label ) : ?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_status, $value ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
		<?php endforeach; ?>
	</select>
		<?php
	}

	/**
	 * Prepare table list items.
	 */
	public function prepare_items() {
		$user_id      = get_current_user_id();
		$per_page     = $this->get_items_per_page( 'evf_forms_per_page' );
		$current_page = $this->get_pagenum();

		// Query args.
		$args = array(
			'post_type'           => 'everest_form',
			'posts_per_page'      => $per_page,
			'paged'               => $current_page,
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
		);

		/**
		 * Filter on the basis of tags.
		 *
		 * @since 3.2.0
		 */
		if ( ! empty( $_REQUEST['tags'] ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => EVF_Post_Types::TAGS_TAXONOMY,
					'field'    => 'term_id',
					'terms'    => array_map( 'absint', is_array( $_REQUEST['tags'] ) ? $_REQUEST['tags'] : array( $_REQUEST['tags'] ) ),
					'operator' => 'IN',
				),
			);
		}

		if ( ! empty( $_REQUEST['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$status = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

			if ( $status === 'inactive' ) {
				$args['post_status'] = 'inactive';
			} else {
				// If it's a valid WordPress status, use it
				$args['post_status'] = $status;
			}
		} else {
			$args['post_status'] = array( 'publish', 'inactive' );
		}

		// Handle the search query.
		if ( ! empty( $_REQUEST['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['s'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		$args['orderby'] = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'date_created'; // phpcs:ignore WordPress.Security.NonceVerification
		$args['order']   = isset( $_REQUEST['order'] ) && 'ASC' === strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification

		// Can user interact, lets check the view capabilities?
		if ( current_user_can( 'everest_forms_view_forms' ) && ! current_user_can( 'everest_forms_view_others_forms' ) ) {
			$args['author'] = $user_id;
		}

		if ( ! current_user_can( 'everest_forms_view_forms' ) && current_user_can( 'everest_forms_view_others_forms' ) ) {
			$args['author__not_in'] = $user_id;
		}

		if ( ! current_user_can( 'everest_forms_view_forms' ) && ! current_user_can( 'everest_forms_view_others_forms' ) ) {
			$args['post__in'] = array( 0 );
		}

		// Get the forms.
		$posts       = new WP_Query( $args );
		$this->items = $posts->posts;

		// Set the pagination.
		$this->set_pagination_args(
			array(
				'total_items' => $posts->found_posts,
				'per_page'    => $per_page,
				'total_pages' => $posts->max_num_pages,
			)
		);
	}

	/**
	 * Display a form dropdown for filtering entries.
	 */
	public function tags_dropdown() {
		$tag_list      = FormHelper::get_all_form_tags( 'term_id' );
		$selected_tags = isset( $_REQUEST['tags'] ) ? is_array( $_REQUEST['tags'] ) ? $_REQUEST['tags'] : array( $_REQUEST['tags'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<label for="filter-by-tags" class="screen-reader-text"><?php esc_html_e( 'Filter by Category', 'everest-forms' ); ?></label>
		<select name="tags[]" id="filter-by-tags" class="evf-enhanced-select" data-placeholder="<?php echo __( 'Select tags', 'everest-forms' ); ?>" multiple style="min-width: 150px;">

			<?php foreach ( $tag_list as $id => $tag ) : ?>
				<option value="<?php echo esc_attr( $id ); ?>" <?php echo in_array( $id, $selected_tags ) ? 'selected' : ''; ?>><?php echo esc_html( $tag ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Manage tags - delete.
	 *
	 * @since 3.2.0
	 */
	public function manage_tags() {
		$tags = FormHelper::get_all_form_tags( 'term_id' );

		if ( empty( $tags ) ) {
			return;
		}

		printf(
			'<button type="button" data-tags="%s" class="button evf-manage-tags">%s</button>',
			htmlspecialchars( json_encode( $tags ) ),
			__( 'Delete Tags', 'everest-forms' )
		);
	}

	public function display_tablenav( $which ) {
		$action   = $this->current_action();
		$form_ids = isset( $_REQUEST['form_id'] ) ? wp_parse_id_list( wp_unslash( $_REQUEST['form_id'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification

		parent::display_tablenav( $which );

		$tags     = array_keys( FormHelper::get_selected_forms_tags( $form_ids ) );
		$tag_list = FormHelper::get_all_form_tags( 'term_id' );

		$all_forms = array();
		foreach ( $this->items as $items ) {
			$all_forms[ $items->ID ] = $items->post_title;
		}

		if ( 'edit-tags' === $action && 'top' === $which ) {
			?>
			<div class="evf-bulk-edit-forms-tags-container">
				<div class="tags-selects">

					<label class="label"><?php echo __( 'Selected forms', 'everest-forms' ); ?></label>
					<select name="bulk_tag_forms[]" id="bulk_tag_forms" class="evf-enhanced-select" data-placeholder="<?php echo __( 'Select forms', 'everest-forms' ); ?>" multiple style="min-width: 300px;">

					<?php foreach ( $all_forms as $id => $form_title ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php echo in_array( $id, $form_ids ) ? 'selected' : ''; ?>><?php echo esc_html( $form_title ); ?></option>
					<?php endforeach; ?>
					</select>
				</div>

				<div class="tags-selects">
					<label class="label"><?php echo __( 'Selected Tags', 'everest-forms' ); ?></label>
					<select name="bulk_tags[]" id="bulk_tags" class="form-tags-select2" data-placeholder="<?php echo __( 'Select tags', 'everest-forms' ); ?>" multiple style="min-width: 300px;">

					<?php foreach ( $tag_list as $id => $tag ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php echo in_array( $id, $tags ) ? 'selected' : ''; ?>><?php echo esc_html( $tag ); ?></option>
					<?php endforeach; ?>
					</select>
				</div>
				<div class="tags-selects">
					<button type="button" class="button evf-update-bulk-tags"><?php echo __( 'Update Tags', 'everest-forms' ); ?></button>
				</div>

			</div>
			<?php

		}
	}
}
