<?php
/**
 * Standalone check for EVF_Field_Date_Time::get_dropdown_selected_date().
 *
 * The date and time dropdowns must preselect the field's own value (editing an
 * entry) and only fall back to today when there is no usable value. The
 * resolver is pure date parsing, so this loads the two class files directly and
 * needs neither WordPress nor a database. Run from the plugin root:
 *
 *   php tests/date-dropdown-selected-date-check.php
 *
 * @package EverestForms
 */

// A command line check: everything it prints goes to a terminal, never a page.
// phpcs:disable WordPress.Security.EscapeOutput, WordPress.WP.AlternativeFunctions

define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );

require_once dirname( __DIR__ ) . '/includes/abstracts/class-evf-form-fields.php';
require_once dirname( __DIR__ ) . '/includes/fields/class-evf-field-date-time.php';

// The constructor registers WordPress hooks; the resolver needs none of them.
$field  = ( new ReflectionClass( 'EVF_Field_Date_Time' ) )->newInstanceWithoutConstructor();
$method = new ReflectionMethod( $field, 'get_dropdown_selected_date' );
$method->setAccessible( true );

$today = array(
	'year'   => (int) gmdate( 'Y' ),
	'month'  => (int) gmdate( 'm' ),
	'day'    => (int) gmdate( 'd' ),
	'hour'   => null,
	'minute' => null,
);

/**
 * Build the expected result for a match that carries no time.
 *
 * @param int $year  Year.
 * @param int $month Month.
 * @param int $day   Day.
 * @return array
 */
function evf_check_date( $year, $month, $day ) {
	return array(
		'year'   => $year,
		'month'  => $month,
		'day'    => $day,
		'hour'   => null,
		'minute' => null,
	);
}

/**
 * Build the expected result for a match that carries a time.
 *
 * @param int $year   Year.
 * @param int $month  Month.
 * @param int $day    Day.
 * @param int $hour   Hour.
 * @param int $minute Minute.
 * @return array
 */
function evf_check_datetime( $year, $month, $day, $hour, $minute ) {
	return array(
		'year'   => $year,
		'month'  => $month,
		'day'    => $day,
		'hour'   => $hour,
		'minute' => $minute,
	);
}

// name => value, date_format, datetime_format, time_format, expected.
$cases = array(
	'ISO date'               => array( '1985-03-22', 'Y-m-d', 'date', 'H:i', evf_check_date( 1985, 3, 22 ) ),
	'US date'                => array( '03/22/1985', 'm/d/Y', 'date', 'H:i', evf_check_date( 1985, 3, 22 ) ),
	'EU date'                => array( '22/03/1985', 'd/m/Y', 'date', 'H:i', evf_check_date( 1985, 3, 22 ) ),
	'Long date'              => array( 'March 22, 1985', 'F j, Y', 'date', 'H:i', evf_check_date( 1985, 3, 22 ) ),
	'Date and 24h time'      => array( '1985-03-22 14:30', 'Y-m-d', 'date-time', 'H:i', evf_check_datetime( 1985, 3, 22, 14, 30 ) ),
	'Date and 12h time'      => array( '1985-03-22 2:05 PM', 'Y-m-d', 'date-time', 'g:i A', evf_check_datetime( 1985, 3, 22, 14, 5 ) ),
	'Midnight is hour zero'  => array( '1985-03-22 12:00 AM', 'Y-m-d', 'date-time', 'g:i A', evf_check_datetime( 1985, 3, 22, 0, 0 ) ),
	'Date-only on date-time' => array( '1985-03-22', 'Y-m-d', 'date-time', 'H:i', evf_check_date( 1985, 3, 22 ) ),
	'Time only'              => array( '22:05', 'Y-m-d', 'time', 'H:i', evf_check_datetime( $today['year'], $today['month'], $today['day'], 22, 5 ) ),
	'Impossible date'        => array( '2024-02-31', 'Y-m-d', 'date', 'H:i', $today ),
	'Empty falls back'       => array( '', 'Y-m-d', 'date', 'H:i', $today ),
	'Junk falls back'        => array( 'not-a-date', 'Y-m-d', 'date', 'H:i', $today ),
);

$failed = 0;

foreach ( $cases as $name => $case ) {
	list( $value, $format, $datetime_format, $time_format, $expected ) = $case;

	$primary = array( 'attr' => array( 'value' => $value ) );
	$config  = array(
		'date_format'     => $format,
		'datetime_format' => $datetime_format,
		'time_format'     => $time_format,
	);

	$actual = $method->invoke( $field, $primary, $config );

	if ( $actual === $expected ) {
		printf( "PASS  %-24s %s\n", $name, json_encode( $actual ) );
	} else {
		++$failed;
		printf( "FAIL  %-24s got %s want %s\n", $name, json_encode( $actual ), json_encode( $expected ) );
	}
}

echo $failed ? "\n{$failed} check(s) failed\n" : "\nAll checks passed\n";
exit( $failed ? 1 : 0 );
