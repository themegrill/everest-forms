<?php
/**
 * EverestForms Formatting
 *
 * Functions for formatting data.
 *
 * @package WPEverest\Functions
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts a string (e.g. 'yes' or 'no') to a bool.
 *
 * @param string $string String to convert.
 * @return bool
 */
function evf_string_to_bool( $string ) {
	return is_bool( $string ) ? $string : ( 'yes' === $string || 1 === $string || 'true' === $string || '1' === $string );
}

/**
 * Converts a bool to a 'yes' or 'no'.
 *
 * @param bool $bool String to convert.
 * @return string
 */
function evf_bool_to_string( $bool ) {
	if ( ! is_bool( $bool ) ) {
		$bool = evf_string_to_bool( $bool );
	}
	return true === $bool ? 'yes' : 'no';
}

/**
 * Add a suffix into an array.
 *
 * @since  1.4.5
 * @param  array  $array  Raw array data.
 * @param  string $suffix Suffix to be added.
 * @return array Modified array with suffix added.
 */
function evf_suffix_array( $array = array(), $suffix = '' ) {
	return preg_filter( '/$/', $suffix, $array );
}

/**
 * Implode an array into a string by $glue and remove empty values.
 *
 * @since  1.4.5
 * @param  array  $array Array to convert.
 * @param  string $glue  Glue, defaults to ' '.
 * @return string
 */
function evf_array_to_string( $array = array(), $glue = ' ' ) {
	return is_string( $array ) ? $array : implode( $glue, array_filter( $array ) );
}

/**
 * Explode a string into an array by $delimiter and remove empty values.
 *
 * @param  string $string    String to convert.
 * @param  string $delimiter Delimiter, defaults to ','.
 * @return array
 */
function evf_string_to_array( $string, $delimiter = ',' ) {
	return is_array( $string ) ? $string : array_filter( explode( $delimiter, $string ) );
}

/**
 * Format dimensions for display.
 *
 * @since  1.4.5
 * @param  array $dimensions Array of dimensions.
 * @param  array $unit       Unit, defaults to 'px'.
 * @return string
 */
function evf_sanitize_dimension_unit( $dimensions = array(), $unit = 'px' ) {
	return evf_array_to_string( evf_suffix_array( $dimensions, $unit ) );
}

/**
 * Sanitize taxonomy names. Slug format (no spaces, lowercase).
 * Urldecode is used to reverse munging of UTF8 characters.
 *
 * @param string $taxonomy Taxonomy name.
 * @return string
 */
function evf_sanitize_taxonomy_name( $taxonomy ) {
	return apply_filters( 'sanitize_taxonomy_name', urldecode( sanitize_title( urldecode( $taxonomy ) ) ), $taxonomy );
}

/**
 * Sanitize permalink values before insertion into DB.
 *
 * Cannot use evf_clean because it sometimes strips % chars and breaks the user's setting.
 *
 * @param  string $value Permalink.
 * @return string
 */
function evf_sanitize_permalink( $value ) {
	global $wpdb;

	$value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );

	if ( is_wp_error( $value ) ) {
		$value = '';
	}

	$value = esc_url_raw( trim( $value ) );
	$value = str_replace( 'http://', '', $value );
	return untrailingslashit( $value );
}

/**
 * Gets the filename part of a download URL.
 *
 * @param string $file_url File URL.
 * @return string
 */
function evf_get_filename_from_url( $file_url ) {
	$parts = wp_parse_url( $file_url );
	if ( isset( $parts['path'] ) ) {
		return basename( $parts['path'] );
	}
}

/**
 * Clean variables using sanitize_text_field. Arrays are cleaned recursively.
 * Non-scalar values are ignored.
 *
 * @param string|array $var Data to sanitize.
 * @return string|array
 */
function evf_clean( $var ) {
	if ( is_array( $var ) ) {
		return array_map( 'evf_clean', $var );
	} else {
		return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
	}
}

/**
 * Run evf_clean over posted textarea but maintain line breaks.
 *
 * @param  string $var Data to sanitize.
 * @return string
 */
function evf_sanitize_textarea( $var ) {
	return implode( "\n", array_map( 'evf_clean', explode( "\n", $var ) ) );
}

/**
 * Sanitize rich text submitted through a WYSIWYG field.
 *
 * Stricter than wp_kses_post(): admin screens initialise behaviour from markup
 * attributes, so visitor-supplied class, id and data-* attributes are dropped.
 *
 * @since 3.6.4
 *
 * @param string $var Submitted rich text.
 * @return string
 */
function evf_sanitize_wysiwyg( $var ) {
	if ( ! is_string( $var ) ) {
		return '';
	}

	return wp_kses( $var, evf_get_allowed_html_tags( 'wysiwyg' ) );
}

/**
 * Sanitize a string destined to be a tooltip.
 *
 * @since  1.0.0 Tooltips are encoded with htmlspecialchars to prevent XSS. Should not be used in conjunction with esc_attr()
 * @param  string $var Data to sanitize.
 * @return string
 */
function evf_sanitize_tooltip( $var ) {
	return htmlspecialchars(
		wp_kses(
			html_entity_decode( $var ),
			array(
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'small'  => array(),
				'span'   => array(),
				'ul'     => array(),
				'li'     => array(),
				'ol'     => array(),
				'p'      => array(),
			)
		)
	);
}

/**
 * Merge two arrays.
 *
 * @param array $a1 First array to merge.
 * @param array $a2 Second array to merge.
 * @return array
 */
function evf_array_overlay( $a1, $a2 ) {
	foreach ( $a1 as $k => $v ) {
		if ( ! array_key_exists( $k, $a2 ) ) {
			continue;
		}
		if ( is_array( $v ) && is_array( $a2[ $k ] ) ) {
			$a1[ $k ] = evf_array_overlay( $v, $a2[ $k ] );
		} else {
			$a1[ $k ] = $a2[ $k ];
		}
	}
	return $a1;
}

/**
 * Array combine.
 *
 * @param  array $array Array of data.
 * @return array
 */
function evf_sanitize_array_combine( $array ) {
	if ( empty( $array ) || ! is_array( $array ) ) {
		return $array;
	}

	return array_map( 'sanitize_text_field', $array );
}

/**
 * Notation to numbers.
 *
 * This function transforms the php.ini notation for numbers (like '2M') to an integer.
 *
 * @param  string $size Size value.
 * @return int
 */
function evf_let_to_num( $size ) {
	$l    = substr( $size, -1 );
	$ret  = substr( $size, 0, -1 );
	$byte = 1024;

	switch ( strtoupper( $l ) ) {
		case 'P':
			$ret *= 1024;
			// No break.
		case 'T':
			$ret *= 1024;
			// No break.
		case 'G':
			$ret *= 1024;
			// No break.
		case 'M':
			$ret *= 1024;
			// No break.
		case 'K':
			$ret *= 1024;
			// No break.
	}
	return $ret;
}

/**
 * EverestForms Date Format - Allows to change date format for everything EverestForms.
 *
 * @return string
 */
function evf_date_format() {
	return apply_filters( 'everest_forms_date_format', get_option( 'date_format' ) );
}

/**
 * EverestForms Time Format - Allows to change time format for everything EverestForms.
 *
 * @return string
 */
function evf_time_format() {
	return apply_filters( 'everest_forms_time_format', get_option( 'time_format' ) );
}

/**
 * Callback which can flatten post meta (gets the first value if it's an array).
 *
 * @param  array $value Value to flatten.
 * @return mixed
 */
function evf_flatten_meta_callback( $value ) {
	return is_array( $value ) ? current( $value ) : $value;
}

if ( ! function_exists( 'evf_rgb_from_hex' ) ) {

	/**
	 * Convert RGB to HEX.
	 *
	 * @param mixed $color Color.
	 *
	 * @return array
	 */
	function evf_rgb_from_hex( $color ) {
		$color = str_replace( '#', '', $color );
		// Convert shorthand colors to full format, e.g. "FFF" -> "FFFFFF".
		$color = preg_replace( '~^(.)(.)(.)$~', '$1$1$2$2$3$3', $color );

		$rgb      = array();
		$rgb['R'] = hexdec( $color[0] . $color[1] );
		$rgb['G'] = hexdec( $color[2] . $color[3] );
		$rgb['B'] = hexdec( $color[4] . $color[5] );

		return $rgb;
	}
}

if ( ! function_exists( 'evf_hex_darker' ) ) {

	/**
	 * Make HEX color darker.
	 *
	 * @param mixed $color  Color.
	 * @param int   $factor Darker factor.
	 *                      Defaults to 30.
	 * @return string
	 */
	function evf_hex_darker( $color, $factor = 30 ) {
		$base  = evf_rgb_from_hex( $color );
		$color = '#';

		foreach ( $base as $k => $v ) {
			$amount      = $v / 100;
			$amount      = round( $amount * $factor );
			$new_decimal = $v - $amount;

			$new_hex_component = dechex( $new_decimal );
			if ( strlen( $new_hex_component ) < 2 ) {
				$new_hex_component = '0' . $new_hex_component;
			}
			$color .= $new_hex_component;
		}

		return $color;
	}
}

if ( ! function_exists( 'evf_hex_lighter' ) ) {

	/**
	 * Make HEX color lighter.
	 *
	 * @param mixed $color  Color.
	 * @param int   $factor Lighter factor.
	 *                      Defaults to 30.
	 * @return string
	 */
	function evf_hex_lighter( $color, $factor = 30 ) {
		$base  = evf_rgb_from_hex( $color );
		$color = '#';

		foreach ( $base as $k => $v ) {
			$amount      = 255 - $v;
			$amount      = $amount / 100;
			$amount      = round( $amount * $factor );
			$new_decimal = $v + $amount;

			$new_hex_component = dechex( $new_decimal );
			if ( strlen( $new_hex_component ) < 2 ) {
				$new_hex_component = '0' . $new_hex_component;
			}
			$color .= $new_hex_component;
		}

		return $color;
	}
}

if ( ! function_exists( 'evf_is_light' ) ) {

	/**
	 * Determine whether a hex color is light.
	 *
	 * @param mixed $color Color.
	 * @return bool  True if a light color.
	 */
	function evf_hex_is_light( $color ) {
		$hex = str_replace( '#', '', $color );

		$c_r = hexdec( substr( $hex, 0, 2 ) );
		$c_g = hexdec( substr( $hex, 2, 2 ) );
		$c_b = hexdec( substr( $hex, 4, 2 ) );

		$brightness = ( ( $c_r * 299 ) + ( $c_g * 587 ) + ( $c_b * 114 ) ) / 1000;

		return $brightness > 155;
	}
}

if ( ! function_exists( 'evf_light_or_dark' ) ) {

	/**
	 * Detect if we should use a light or dark color on a background color.
	 *
	 * @param mixed  $color Color.
	 * @param string $dark  Darkest reference.
	 *                      Defaults to '#000000'.
	 * @param string $light Lightest reference.
	 *                      Defaults to '#FFFFFF'.
	 * @return string
	 */
	function evf_light_or_dark( $color, $dark = '#000000', $light = '#FFFFFF' ) {
		return evf_hex_is_light( $color ) ? $dark : $light;
	}
}

if ( ! function_exists( 'evf_format_hex' ) ) {

	/**
	 * Format string as hex.
	 *
	 * @param string $hex HEX color.
	 * @return string|null
	 */
	function evf_format_hex( $hex ) {
		$hex = trim( str_replace( '#', '', $hex ) );

		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return $hex ? '#' . $hex : null;
	}
}

/**
 * Format phone numbers.
 *
 * @param  string $phone Phone number.
 * @return string
 */
function evf_format_phone_number( $phone ) {
	return str_replace( '.', '-', $phone );
}

/**
 * Wrapper for mb_strtoupper which see's if supported first.
 *
 * @param  string $string String to format.
 * @return string
 */
function evf_strtoupper( $string ) {
	return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $string, 'UTF-8' ) : strtoupper( $string );
}

/**
 * Make a string lowercase.
 * Try to use mb_strtolower() when available.
 *
 * @param  string $string String to format.
 * @return string
 */
function evf_strtolower( $string ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $string, 'UTF-8' ) : strtolower( $string );
}

/**
 * Trim a string and append a suffix.
 *
 * @param  string  $string String to trim.
 * @param  integer $chars  Amount of characters.
 *                         Defaults to 200.
 * @param  string  $suffix Suffix.
 *                         Defaults to '...'.
 * @return string
 */
function evf_trim_string( $string, $chars = 200, $suffix = '...' ) {
	if ( strlen( $string ) > $chars ) {
		if ( function_exists( 'mb_substr' ) ) {
			$string = mb_substr( $string, 0, ( $chars - mb_strlen( $suffix, 'UTF-8' ) ), 'UTF-8' ) . $suffix;
		} else {
			$string = substr( $string, 0, ( $chars - strlen( $suffix ) ) ) . $suffix;
		}
	}
	return $string;
}

/**
 * Format content to display shortcodes.
 *
 * @param  string $raw_string Raw string.
 * @return string
 */
function evf_format_content( $raw_string ) {
	return apply_filters( 'everest_forms_format_content', apply_filters( 'everest_forms_short_description', $raw_string ), $raw_string );
}

/**
 * Process oEmbeds.
 *
 * @param  string $content Content.
 * @return string
 */
function evf_do_oembeds( $content ) {
	global $wp_embed;

	$content = $wp_embed->autoembed( $content );

	return $content;
}

/**
 * Array merge and sum function.
 *
 * Source:  https://gist.github.com/Nickology/f700e319cbafab5eaedc
 *
 * @since  1.0.0
 * @return array
 */
function evf_array_merge_recursive_numeric() {
	$arrays = func_get_args();

	// If there's only one array, it's already merged.
	if ( 1 === count( $arrays ) ) {
		return $arrays[0];
	}

	// Remove any items in $arrays that are NOT arrays.
	foreach ( $arrays as $key => $array ) {
		if ( ! is_array( $array ) ) {
			unset( $arrays[ $key ] );
		}
	}

	// We start by setting the first array as our final array.
	// We will merge all other arrays with this one.
	$final = array_shift( $arrays );

	foreach ( $arrays as $b ) {
		foreach ( $final as $key => $value ) {
			// If $key does not exist in $b, then it is unique and can be safely merged.
			if ( ! isset( $b[ $key ] ) ) {
				$final[ $key ] = $value;
			} else {
				// If $key is present in $b, then we need to merge and sum numeric values in both.
				if ( is_numeric( $value ) && is_numeric( $b[ $key ] ) ) {
					// If both values for these keys are numeric, we sum them.
					$final[ $key ] = $value + $b[ $key ];
				} elseif ( is_array( $value ) && is_array( $b[ $key ] ) ) {
					// If both values are arrays, we recursively call ourself.
					$final[ $key ] = evf_array_merge_recursive_numeric( $value, $b[ $key ] );
				} else {
					// If both keys exist but differ in type, then we cannot merge them.
					// In this scenario, we will $b's value for $key is used.
					$final[ $key ] = $b[ $key ];
				}
			}
		}

		// Finally, we need to merge any keys that exist only in $b.
		foreach ( $b as $key => $value ) {
			if ( ! isset( $final[ $key ] ) ) {
				$final[ $key ] = $value;
			}
		}
	}

	return $final;
}

/**
 * Implode and escape HTML attributes for output.
 *
 * @since 1.2.0
 * @param array $raw_attributes Attribute name value pairs.
 * @return string
 */
function evf_implode_html_attributes( $raw_attributes ) {
	$attributes = array();
	foreach ( $raw_attributes as $name => $value ) {
		$attributes[] = esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
	}
	return implode( ' ', $attributes );
}

/**
 * Parse a relative date option from the settings API into a standard format.
 *
 * @since 1.2.0
 * @param mixed $raw_value Value stored in DB.
 * @return array Nicely formatted array with number and unit values.
 */
function evf_parse_relative_date_option( $raw_value ) {
	$periods = array(
		'days'   => __( 'Day(s)', 'everest-forms' ),
		'weeks'  => __( 'Week(s)', 'everest-forms' ),
		'months' => __( 'Month(s)', 'everest-forms' ),
		'years'  => __( 'Year(s)', 'everest-forms' ),
	);

	$value = wp_parse_args(
		(array) $raw_value,
		array(
			'number' => '',
			'unit'   => 'days',
		)
	);

	$value['number'] = ! empty( $value['number'] ) ? absint( $value['number'] ) : '';

	if ( ! in_array( $value['unit'], array_keys( $periods ), true ) ) {
		$value['unit'] = 'days';
	}

	return $value;
}

/**
 * Callback which can flatten structure data (gets the value if it's a multidimensional array).
 *
 * @since  1.3.0
 * @param  array $value Value to flatten.
 * @return array
 */
function evf_flatten_array( $value = array() ) {
	$return = array();
	array_walk_recursive( $value, function( $a ) use ( &$return ) { $return[] = $a; } ); // @codingStandardsIgnoreLine.
	return $return;
}

/**
 * An `array_splice` which does preverse the keys of the replacement array
 *
 * The argument list is identical to `array_splice`
 *
 * @since 1.6.5
 *
 * @link https://github.com/lode/gaps/blob/master/src/gaps.php
 *
 * @param  array $input       The input array.
 * @param  int   $offset      The offeset to start.
 * @param  int   $length      Optional length.
 * @param  array $replacement The replacement array.
 *
 * @return array the array consisting of the extracted elements.
 */
function evf_array_splice_preserve_keys( &$input, $offset, $length = null, $replacement = array() ) {
	if ( empty( $replacement ) ) {
		return array_splice( $input, $offset, $length );
	}

	$part_before  = array_slice( $input, 0, $offset, true );
	$part_removed = array_slice( $input, $offset, $length, true );
	$part_after   = array_slice( $input, $offset + $length, null, true );

	$input = $part_before + $replacement + $part_after;

	return $part_removed;
}
