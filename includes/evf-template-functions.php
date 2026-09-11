<?php
/**
 * EverestForms Template
 *
 * Functions for the templating system.
 *
 * @package EverestForms/Functions
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add body classes for EVF pages.
 *
 * @param  array $classes Body Classes.
 * @return array
 */
function evf_body_class( $classes ) {
	$classes = (array) $classes;

	if ( EVF_Frontend_Scripts::current_page_has_form() ) {
		$classes[] = 'everest-forms-no-js';

		add_action( 'wp_footer', 'evf_no_js' );
	}

	return array_unique( $classes );
}

/**
 * NO JS handling.
 *
 * @since 1.2.0
 */
function evf_no_js() {
	if ( evf_is_amp() ) {
		return;
	}
	?>
	<script type="text/javascript">
		var c = document.body.className;
		c = c.replace( /everest-forms-no-js/, 'everest-forms-js' );
		document.body.className = c;
	</script>
	<?php
}

/**
 * Output generator tag to aid debugging.
 *
 * @param string $gen Generator.
 * @param string $type Type.
 *
 * @return string
 */
function evf_generator_tag( $gen, $type ) {
	switch ( $type ) {
		case 'html':
			$gen .= "\n" . '<meta name="generator" content="Everest Forms ' . esc_attr( EVF_VERSION ) . '">';
			break;
		case 'xhtml':
			$gen .= "\n" . '<meta name="generator" content="Everest Forms ' . esc_attr( EVF_VERSION ) . '" />';
			break;
	}

	return $gen;
}
