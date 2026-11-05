<?php
/**
 * Everest Form email preview template.
 *
 * @since 2.0.5
 *
 * @package Everest form email preview template.
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
	<html <?php language_attributes(); ?> style="background-color: #E9EAEC;">
		<head>
			<meta name="viewport" content="width=device-width"/>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
			<title>
				<?php get_bloginfo( 'name' ); ?>
			</title>
		</head>
		<body <?php body_class(); ?> >
			<?php
			$connection_id = isset( $_GET['evf_email_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['evf_email_preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			/**
			 * Get email message from the specific email connection
			 *
			 * @return array email preview message.
			 */
			function form_data() {
				$form_data = array();

				if ( ! empty( $_GET['form_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					$connection_id = isset( $_GET['evf_email_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['evf_email_preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
					$form_data     = evf()->form->get(
						absint( $_GET['form_id'] ), // phpcs:ignore WordPress.Security.NonceVerification
						array(
							'content_only' => true,
							'cap'          => 'everest_forms_edit_form',
						)
					);

					if ( isset( $form_data['settings']['email'][ "$connection_id" ] ) && isset( $form_data['settings']['email'][ "$connection_id" ]['evf_email_message'] ) ) {
						$email_preview_message = $form_data['settings']['email'][ "$connection_id" ]['evf_email_message'];
					}
				}

				return $form_data;
			}


			// Email data of the specific connection.
			$email_form_data = form_data();

			if ( isset( $email_form_data['settings']['email'] ) && isset( $email_form_data['settings']['email'][ $connection_id ] ) ) {
				$email_content           = $email_form_data['settings']['email'][ $connection_id ]['evf_email_message'];
				$email_template_included = isset( $email_form_data['settings']['email'][ $connection_id ]['choose_template'] ) ? $email_form_data['settings']['email'][ $connection_id ]['choose_template'] : 0;
			}

			// Initializing the EVF_Emails class to import the email template.
			$evf_emails_obj            = new EVF_Emails();
			$evf_emails_obj->form_data = $email_form_data;

			if ( empty( $email_content ) ) {
				$email_content = esc_html__( '{all_fields}', 'everest-forms' );
			}

			$email_content = str_replace( '{all_fields}', evf_process_all_fields_smart_tag( $email_content ), $email_content );

			// Email Template Enabled or not checked.
			$email_template_included = ! empty( $email_form_data['settings']['email'][ $connection_id ]['choose_template'] ) ? $email_content : 0;

			if ( $email_template_included ) {
				$email_content = apply_filters( 'everest_forms_email_template_message', $email_content, $evf_emails_obj, $connection_id );
				$allowed_tags  = array(
					'p'      => array(
						'style' => array(),
					),
					'table'  => array(
						'border'      => array(),
						'cellpadding' => array(),
						'cellspacing' => array(),
						'style'       => array(),
					),
					'tr'     => array(
						'style' => array(),
					),
					'td'     => array(
						'style' => array(),
					),
					'img'    => array(
						'src'    => array(),
						'alt'    => array(),
						'height' => array(),
						'width'  => array(),
						'style'  => array(),
					),
					'a'      => array(
						'href'  => array(),
						'title' => array(),
						'style' => array(),
					),
					'h1'     => array( 'style' => array() ),
					'h2'     => array( 'style' => array() ),
					'h3'     => array( 'style' => array() ),
					'h4'     => array( 'style' => array() ),
					'h5'     => array( 'style' => array() ),
					'h6'     => array( 'style' => array() ),
					'br'     => array(),
					'strong' => array(),
					'em'     => array(),
					'style'  => array(),
				);
				echo wp_kses( $email_content, $allowed_tags );
			} else {
				$email_content = $evf_emails_obj->build_email( $email_content );
				$allowed_tags  = array(
					'p'      => array(
						'style' => array(),
					),
					'table'  => array(
						'border'      => array(),
						'cellpadding' => array(),
						'cellspacing' => array(),
						'style'       => array(),
					),
					'tr'     => array(
						'style' => array(),
					),
					'td'     => array(
						'style' => array(),
					),
					'img'    => array(
						'src'    => array(),
						'alt'    => array(),
						'height' => array(),
						'width'  => array(),
						'style'  => array(),
					),
					'a'      => array(
						'href'  => array(),
						'title' => array(),
						'style' => array(),
					),
					'h1'     => array( 'style' => array() ),
					'h2'     => array( 'style' => array() ),
					'h3'     => array( 'style' => array() ),
					'h4'     => array( 'style' => array() ),
					'h5'     => array( 'style' => array() ),
					'h6'     => array( 'style' => array() ),
					'br'     => array(),
					'strong' => array(),
					'em'     => array(),
					'style'  => array(),
				);
				echo wp_kses( $email_content, $allowed_tags );
			}

			?>
		</body>
	</html>
