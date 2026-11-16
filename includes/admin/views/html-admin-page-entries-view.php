<?php
/**
 * Admin View: Entries
 *
 * @package EverestForms/Admin/Entries/Views
 */

defined( 'ABSPATH' ) || exit;

$form_id      = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$entry_id     = isset( $_GET['view-entry'] ) ? absint( $_GET['view-entry'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$entry        = evf_get_entry( $entry_id, true );
$entry_fields = json_decode( $entry->fields );
$form_data    = evf()->form->get( $form_id, array( 'content_only' => true ) );
$hide_empty   = isset( $_COOKIE['everest_forms_entry_hide_empty'] ) && 'true' === $_COOKIE['everest_forms_entry_hide_empty'];

$form_entries = evf_get_entries_by_form_id( $form_id, '', '', true );
$form_entries = array_map(
	function ( $el ) {
		return $el['entry_id'];
	},
	$form_entries
);

$entry_index    = array_search( $entry_id, $form_entries ); // phpcs:ignore WordPress.PHP.StrictInArray
$prev_entry     = '';
$next_entry     = '';
$prev_entry_url = '#';
$next_entry_url = '#';

if ( false !== $entry_index ) {
	if ( isset( $form_entries[ $entry_index - 1 ] ) ) {
		$prev_entry     = $form_entries[ $entry_index - 1 ];
		$prev_entry_url = admin_url( sprintf( 'admin.php?page=evf-entries&amp;form_id=%d&amp;view-entry=%d', $form_id, $prev_entry ) );
	}

	if ( isset( $form_entries[ $entry_index + 1 ] ) ) {
		$next_entry     = $form_entries[ $entry_index + 1 ];
		$next_entry_url = admin_url( sprintf( 'admin.php?page=evf-entries&amp;form_id=%d&amp;view-entry=%d', $form_id, $next_entry ) );
	}

	$next_entry = isset( $form_entries[ $entry_index + 1 ] ) ? $form_entries[ $entry_index + 1 ] : '';
}

?>
<div class="wrap everest-forms evf-entry-view-wrapper">
	<!-- Header Section -->
	<div class="evf-entry-header">
		<div class="evf-entry-header-left">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=evf-entries&amp;form_id=' . $form_id ) ); ?>" class="evf-back-link">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 22 22"><path d="M10.352 3.935a.917.917 0 0 1 1.296 1.297l-5.769 5.767 5.769 5.77a.916.916 0 1 1-1.296 1.296l-6.417-6.417a.917.917 0 0 1 0-1.296z"/><path d="M17.416 10.083a.917.917 0 0 1 0 1.834H4.583a.917.917 0 0 1 0-1.834z"/></svg>
			</a>
			<span class="evf-entry-title">
				<?php
				printf( esc_html__( '%1$s: Entry #%2$s', 'everest-forms' ), esc_html( _draft_or_post_title( $form_id ) ), absint( $entry_id ) );
				?>
			</span>
		</div>
		<div class="evf-entry-header-right">
			<a class="evf-nav-btn evf-prev-btn" href="<?php echo esc_url( $prev_entry_url ); ?>" <?php echo empty( $prev_entry ) ? 'disabled' : ''; ?>>
				<span class="dashicons dashicons-arrow-left-alt2"></span>
				<?php esc_html_e( 'Previous', 'everest-forms' ); ?>
			</a>
			<a class="evf-nav-btn evf-next-btn" href="<?php echo esc_url( $next_entry_url ); ?>" <?php echo empty( $next_entry ) ? 'disabled' : ''; ?>>
				<?php esc_html_e( 'Next', 'everest-forms' ); ?>
				<span class="dashicons dashicons-arrow-right-alt2"></span>
			</a>
		</div>
	</div>

	<?php do_action( 'everest_forms_view_entries_notices' ); ?>

	<!-- Main Content Wrapper -->
	<div class="evf-entry-content-wrapper">
		<!-- Left Column: Entry Fields and Details -->
		<div class="evf-entry-main-content">
			<!-- Personal Information Section -->
			<div id="everest-forms-entry-fields" class="evf-entry-section evf-personal-info stuffbox">
				<div class="evf-section-header">
					<div class="evf-section-title hndle">
						<?php do_action( 'everest_forms_before_entry_details_hndle', $entry ); ?>
						<span><?php esc_html_e( 'Personal Information', 'everest-forms' ); ?></span>
						<?php do_action( 'everest_forms_after_entry_details_hndle', $entry ); ?>
					</div>
					<div class="evf-section-header-actions">
						<a href="#"
							class="evf-toggle-empty everest-forms-empty-field-toggle password_preview dashicons <?php echo $hide_empty ? 'dashicons-hidden' : 'dashicons-visibility'; ?>"
							title="<?php echo $hide_empty ? esc_attr__( 'Show empty fields', 'everest-forms' ) : esc_attr__( 'Hide empty fields', 'everest-forms' ); ?>">
							<?php
							echo $hide_empty
								? esc_html__( 'Show Empty Fields', 'everest-forms' )
								: esc_html__( 'Hide Empty Fields', 'everest-forms' );
							?>
						</a>
						<?php if ( current_user_can( 'everest_forms_edit_entry', $entry->entry_id ) ) : ?>
							<?php do_action( 'everest_forms_entry_details_sidebar_action', $entry, $form_data ); ?>
						<?php endif; ?>
					</div>
				</div>

				<div class="evf-section-content inside">
					<?php
					$entry_meta = apply_filters( 'everest_forms_entry_single_data', $entry->meta, $entry, $form_data );

					$field_type_by_meta_key = array();
					$exclude_fields_array   = array( 'captcha', 'recaptcha', 'hcaptcha', 'turnstile', 'private-note', 'authorize-net' );
					$exclude_fields_array   = apply_filters( 'everest_forms_view_entry_exclude_fields', $exclude_fields_array, $entry_meta, $form_data );

					foreach ( $form_data['form_fields'] as $field ) {
						if ( isset( $field['meta-key'] ) ) {
							$field_type_by_meta_key[ $field['meta-key'] ] = $field['type'];
						}
					}

					if ( empty( $entry_meta ) ) {
						echo '<p class="evf-no-fields">' . esc_html__( 'This entry does not have any fields.', 'everest-forms' ) . '</p>';
					} else {
						foreach ( $entry_meta as $meta_key => $meta_value ) {
							if ( in_array( $meta_key, array_keys( $field_type_by_meta_key ), true ) && in_array( $field_type_by_meta_key[ $meta_key ], $exclude_fields_array, true ) ) {
								continue;
							}

							if ( in_array( $meta_key, apply_filters( 'everest_forms_hidden_entry_fields', array() ), true ) ) {
								continue;
							}

							// Escape plain text values; leave serialized data untouched for further processing.
							$meta_value = is_serialized( $meta_value ) ? $meta_value : esc_html( $meta_value );

							if ( is_serialized( $meta_value ) ) {
								$raw_meta_val = evf_maybe_unserialize( $meta_value );

								$field_type_array = apply_filters(
									'everest_forms_serialized_value_field_type',
									array(
										'payment-checkbox',
										'checkbox',
										'radio',
										'payment-multiple',
									)
								);

								if ( ! empty( $raw_meta_val['type'] ) && in_array( $raw_meta_val['type'], $field_type_array, true ) && empty( $raw_meta_val['label'][0] ) ) {
									$meta_value = '';
								} else {
									$is_dropdown = false;
									foreach ( $entry_fields as $field ) {
										if ( $meta_key === $field->meta_key && 'select' === $field->type ) {
											$is_dropdown = true;
											break;
										}
									}

									if ( $is_dropdown && empty( $raw_meta_val[0] ) ) {
										$meta_value = '';
									}
								}
							}

							if ( evf_is_json( $meta_value ) ) {
								$meta_value = json_decode( $meta_value, true );
								$meta_value = $meta_value['value'];
							}

							$field_value     = apply_filters( 'everest_forms_html_field_value', $meta_value, $entry_meta[ $meta_key ], $entry_meta, 'entry-single', $meta_key );
							$is_empty        = is_string( $field_value ) && ( '(empty)' === wp_strip_all_tags( $field_value ) || '' === $field_value );
							$field_class     = $is_empty ? 'evf-field-empty' : '';
							$correct_answers = false;

							$field_label = evf_get_form_data_by_meta_key( $form_id, $meta_key, $entry_fields );
							if ( ! $field_label ) {
								$field_label = esc_html__( 'Field ID', 'everest-forms' );
							}

							if ( apply_filters( 'everest_forms_html_field_label', false ) ) {
								$correct_answers = apply_filters( 'everest_forms_single_entry_label', $field_label, $meta_key, $field_value );
							}

							echo '<div class="evf-field-row everest-forms-entry-field ' . esc_attr( $field_class ) . '">';
							echo '<div class="evf-field-label field-name"><strong>' . esc_html( $field_label ) . '</strong></div>';
							echo '<div class="evf-field-value field-value">';

							if ( ! empty( $field_value ) || is_numeric( $field_value ) ) {
								if ( is_serialized( $field_value ) ) {
									$field_value     = evf_maybe_unserialize( $field_value );
									$field_label_val = isset( $field_value['label'] ) ? $field_value['label'] : $field_value;

									if ( ! empty( $field_label_val ) && is_array( $field_label_val ) ) {
										foreach ( $field_label_val as $field => $value ) {
											$answer_class = '';
											if ( $correct_answers ) {
												if ( in_array( $value, $correct_answers, true ) ) {
													$answer_class = 'correct_answer';
												} else {
													$answer_class = 'wrong_answer';
												}
											}
											// Coupon data (and other addon field values) can remain an array when the owning addon is deactivated; encode arrays so the string helpers below never receive one. Scalars are left untouched.
											if ( is_array( $value ) ) {
												$value = wp_json_encode( $value );
											}
											echo '<span class="list evf-answer-badge ' . esc_attr( $answer_class ) . '">' . esc_html( wp_strip_all_tags( $value ) ) . '</span>';
										}
									} else {
										// Output serialized non-array label value safely.
										echo nl2br( esc_html( $field_label_val ) );
									}
								} elseif ( $correct_answers && false !== $correct_answers ) {
									if ( in_array( $field_value, $correct_answers, true ) ) {
										$answer_class = 'correct_answer';
									} else {
										$answer_class = 'wrong_answer';
									}
									if ( is_array( $field_value ) ) {
										$field_value = wp_json_encode( $field_value );
									}
									echo '<span class="list evf-answer-badge ' . esc_attr( $answer_class ) . '">' . esc_html( wp_strip_all_tags( $field_value ) ) . '</span>';
								} else {
									// Coupon and other addon fields can leave an array value when their addon is deactivated; normalize it so the output helpers below never receive an array.
									if ( is_array( $field_value ) ) {
										$field_value = wp_json_encode( $field_value );
									}
									$allow_html_types = apply_filters(
										'everest_forms_entry_view_field_types_allowing_html',
										array( 'wysiwyg', 'repeater-fields' ),
										$meta_key,
										$form_data
									);
									$meta_field_type = isset( $field_type_by_meta_key[ $meta_key ] ) ? $field_type_by_meta_key[ $meta_key ] : '';
									if ( is_string( $field_value ) && in_array( $meta_field_type, $allow_html_types, true ) ) {
										// Entries stored by an earlier version can still hold attributes the field no longer saves.
										echo 'wysiwyg' === $meta_field_type ? evf_sanitize_wysiwyg( $field_value ) : wp_kses_post( $field_value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Both branches escape through wp_kses().
									} elseif ( in_array( $meta_field_type, array( 'file-upload', 'image-upload', 'signature', 'color', 'rating', 'country', 'likert', 'checkbox', 'radio', 'payment-checkbox' ), true ) ) {
										echo nl2br( make_clickable( $field_value ) );
									} else {
										// Output plain field value safely.
										echo nl2br( esc_html( $field_value ) );
									}
								}
							} else {
								echo '<span class="evf-empty-value">' . esc_html__( 'Empty', 'everest-forms' ) . '</span>';
							}

							echo '</div>';
							echo '</div>';
						}
					}
					?>
				</div>
			</div>

			<?php do_action( 'everest_forms_entry_details_content', $entry, $form_id ); ?>

			<!-- Entry Details Section -->
			<div id="everest-forms-entry-details-table" class="evf-entry-section evf-entry-details-section stuffbox">
				<div class="evf-section-header">
					<div class="evf-section-title hndle"><span><?php esc_html_e( 'Entry Details', 'everest-forms' ); ?></span></div>
				</div>

				<div class="evf-section-content inside">
					<div class="evf-details-table-wrapper">
						<table class="evf-details-table wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'ID', 'everest-forms' ); ?></th>
									<th><?php esc_html_e( 'User', 'everest-forms' ); ?></th>
									<th><?php esc_html_e( 'IP', 'everest-forms' ); ?></th>
									<th><?php esc_html_e( 'Status', 'everest-forms' ); ?></th>
									<th><?php esc_html_e( 'Submitted Date', 'everest-forms' ); ?></th>
									<?php if ( ! empty( $entry->date_modified ) ) : ?>
										<th><?php esc_html_e( 'Modified Date', 'everest-forms' ); ?></th>
									<?php endif; ?>
									<th><?php esc_html_e( 'Referer Link', 'everest-forms' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><?php echo absint( $entry_id ); ?></td>
									<td>
										<?php
										if ( ! empty( $entry->user_id ) && 0 !== $entry->user_id ) {
											$user      = get_userdata( $entry->user_id );
											$user_name = ! empty( $user->display_name ) ? $user->display_name : $user->user_login;
											$user_url  = add_query_arg(
												array(
													'user_id' => absint( $user->ID ),
												),
												admin_url( 'user-edit.php' )
											);
											echo '<a href="' . esc_url( $user_url ) . '">' . esc_html( $user_name ) . '</a>';
										} else {
											esc_html_e( 'Guest', 'everest-forms' );
										}
										?>
									</td>
									<td><?php echo ! empty( $entry->user_ip_address ) ? esc_html( $entry->user_ip_address ) : '-'; ?></td>
									<td>
										<?php
										$status       = ! empty( $entry->status ) ? sanitize_text_field( $entry->status ) : 'completed';
										$status_class = 'evf-status-' . strtolower( $status );
										?>
										<span class="evf-status-badge <?php echo esc_attr( $status_class ); ?>">
											<?php echo esc_html( ucwords( $status ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $entry->date_created ) + ( get_option( 'gmt_offset' ) * 3600 ) ) ); ?></td>
									<?php if ( ! empty( $entry->date_modified ) ) : ?>
										<td><?php echo esc_html( date_i18n( 'Y-m-d H:i:s', strtotime( $entry->date_modified ) + ( get_option( 'gmt_offset' ) * 3600 ) ) ); ?></td>
									<?php endif; ?>
									<td>
										<?php if ( ! empty( $entry->referer ) ) : ?>
											<a href="<?php echo esc_url( $entry->referer ); ?>" target="_blank" class="evf-referer-link">
												<?php esc_html_e( 'View', 'everest-forms' ); ?>
											</a>
										<?php else : ?>
											-
										<?php endif; ?>
									</td>
								</tr>
							</tbody>
						</table>
					</div>

					<?php do_action( 'everest_forms_entry_details_sidebar_details', $entry, $entry_meta, $form_data ); ?>
				</div>
			</div>
		</div>

		<!-- Right Sidebar -->
		<div class="evf-entry-sidebar">
			<div id="everest-forms-entry-actions" class="stuffbox">
				<div class="evf-entry-actions-header"><?php esc_html_e( 'Entry Actions', 'everest-forms' ); ?></div>
				<div class="inside">
					<div class="everest-forms-entry-actions-meta">
						<?php do_action( 'everest_forms_entry_details_sidebar_actions', $entry, $form_data ); ?>

						<?php if ( current_user_can( 'everest_forms_delete_entry', $entry->entry_id ) ) : ?>
							<?php
							$trash_link = wp_nonce_url(
								add_query_arg(
									array(
										'trash' => $entry_id,
									),
									admin_url( 'admin.php?page=evf-entries&form_id=' . $form_id )
								),
								'trash-entry'
							);
							?>
							<p class="everest-forms-entry-delete">
								<a href="<?php echo esc_url( $trash_link ); ?>">
									<span class="dashicons dashicons-trash"></span>
									<?php esc_html_e( 'Delete Entry', 'everest-forms' ); ?>
								</a>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php do_action( 'everest_forms_after_entry_details', $entry, $entry_meta, $form_data ); ?>
		</div>
	</div>
</div>

<script type="text/javascript">
jQuery(document).on('click', '.everest-forms-empty-field-toggle', function (event) {
	event.preventDefault();

	var $btn = jQuery(this);

	if (wpCookies.get('everest_forms_entry_hide_empty') === 'true') {
		wpCookies.remove('everest_forms_entry_hide_empty');

		$btn
			.removeClass('dashicons-hidden')
			.addClass('dashicons-visibility')
			.attr('title', '<?php esc_attr_e( 'Hide empty fields', 'everest-forms' ); ?>')
			.text('<?php esc_html_e( 'Hide Empty Fields', 'everest-forms' ); ?>');

		jQuery('.evf-field-empty').show();
	} else {
		wpCookies.set('everest_forms_entry_hide_empty', 'true', 2592000);

		$btn
			.removeClass('dashicons-visibility')
			.addClass('dashicons-hidden')
			.attr('title', '<?php esc_attr_e( 'Show empty fields', 'everest-forms' ); ?>')
			.text('<?php esc_html_e( 'Show Empty Fields', 'everest-forms' ); ?>');

		jQuery('.evf-field-empty').hide();
	}
});

jQuery(document).ready(function($) {
	if (wpCookies.get('everest_forms_entry_hide_empty') === 'true') {
		$('.evf-field-empty').hide();
	}
});
</script>
