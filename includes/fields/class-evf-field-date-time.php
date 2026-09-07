<?php
/**
 * Date and Time field.
 *
 * @package EverestForms\Fields
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * EVF_Field_Date_Time class.
 */
class EVF_Field_Date_Time extends EVF_Form_Fields {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->name     = esc_html__( 'Date / Time', 'everest-forms' );
		$this->type     = 'date-time';
		$this->icon     = 'evf-icon evf-icon-calendar';
		$this->order    = 20;
		$this->group    = 'advanced';
		$this->settings = array(
			'basic-options'    => array(
				'field_options' => array(
					'label',
					'choose_format',
					'choose_style',
					'description',
					'required',
					'required_field_message_setting',
					'required_field_message',
				),
			),
			'advanced-options' => array(
				'field_options' => array(
					'placeholder',
					'meta',
					'datetime_options',
					'label_hide',
					'css',
				),
			),
		);

		parent::__construct();
	}

	/**
	 * Hook in tabs.
	 */
	public function init_hooks() {
		add_action( 'everest_forms_shortcode_scripts', array( $this, 'load_assets' ) );
		add_filter( 'everest_forms_field_properties_' . $this->type, array( $this, 'field_properties' ), 5, 3 );
		add_filter( 'everest_forms_entry_save_fields', array( $this, 'save_timezone' ), 10, 3 );
		add_filter( 'everest_forms_html_field_value', array( $this, 'entry_html' ), 10, 5 );
		add_filter( 'everest_forms_date_time_properties', array( $this, 'set_default_timezone' ), 10, 2 );
	}

	/**
	 * Date field format option.
	 *
	 * @since 1.4.9
	 * @param array $field Field Data.
	 */
	public function choose_format( $field ) {
		$format        = ! empty( $field['datetime_format'] ) ? esc_attr( $field['datetime_format'] ) : 'date';
		$format_label  = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'datetime_format',
				'value'   => esc_html__( 'Format', 'everest-forms' ),
				'tooltip' => esc_html__( 'Select a format for the date field.', 'everest-forms' ),
			),
			false
		);
		$format_select = $this->field_element(
			'select',
			$field,
			array(
				'slug'    => 'datetime_format',
				'value'   => $format,
				'options' => array(
					'date'      => esc_html__( 'Date', 'everest-forms' ),
					'time'      => esc_html__( 'Time', 'everest-forms' ),
					'date-time' => esc_html__( 'Both', 'everest-forms' ),
				),
			),
			false
		);
		$args          = array(
			'slug'    => 'datetime_format',
			'content' => $format_label . $format_select,
		);
		$this->field_element( 'row', $field, $args );
	}

	/**
	 * Date field style option.
	 *
	 * @since 1.7.5
	 * @param array $field Field Data.
	 */
	public function choose_style( $field ) {
		$style        = ! empty( $field['datetime_style'] ) ? esc_attr( $field['datetime_style'] ) : 'picker';
		$style_label  = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'datetime_style',
				'value'   => esc_html__( 'Style', 'everest-forms' ),
				'tooltip' => esc_html__( 'Select a style for the date field.', 'everest-forms' ),
			),
			false
		);
		$style_select = $this->field_element(
			'select',
			$field,
			array(
				'slug'    => 'datetime_style',
				'value'   => $style,
				'options' => array(
					'picker'   => esc_html__( 'Picker', 'everest-forms' ),
					'dropdown' => esc_html__( 'Dropdown', 'everest-forms' ),
				),
			),
			false
		);
		$args         = array(
			'slug'    => 'datetime_style',
			'content' => $style_label . $style_select,
		);
		$this->field_element( 'row', $field, $args );
	}

	/**
	 * Date and time advanced field options.
	 *
	 * @since 1.4.9
	 * @param array $field Field Data.
	 */
	public function datetime_options( $field ) {
		$format             = ! empty( $field['datetime_format'] ) ? esc_attr( $field['datetime_format'] ) : 'date';
		$class_name         = isset( $field['enable_min_max'] ) && '1' === $field['enable_min_max'] ? '' : 'everest-forms-hidden';
		$field['date_mode'] = isset( $field['date_mode'] ) ? $field['date_mode'] : 'single';
		$field['date_mode'] = isset( $field['date_range'] ) && '1' === $field['date_range'] ? 'range' : $field['date_mode'];

		$this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'datetime_advanced',
				'value'   => esc_html__( 'Date & Time Format', 'everest-forms' ),
				'tooltip' => esc_html__( 'Advanced date and time formatting options.', 'everest-forms' ),
			),
			true
		);

		echo '<div class="format-selected-' . esc_attr( $format ) . ' format-selected">';
			echo '<div class="everest-forms-border-container everest-forms-date">';
			echo '<h4 class="everest-forms-border-container-title">' . esc_html__( 'Date', 'everest-forms' ) . '</h4>'; // phpcs:ignore WordPress.Security.NonceVerification

			$date_format_label = $this->field_element(
				'label',
				$field,
				array(
					'slug'    => 'date_format',
					'value'   => esc_html__( 'Date Format', 'everest-forms' ),
					'tooltip' => esc_html__( 'Choose a desire date format to display.', 'everest-forms' ),
				),
				false
			);

			$date_format_types = array(
				'Y-m-d'  => date_i18n( 'Y-m-d' ) . ' (Y-m-d)',
				'F j, Y' => date_i18n( 'F j, Y' ) . ' (F j, Y)',
				'm/d/Y'  => date_i18n( 'm/d/Y' ) . ' (m/d/Y)',
				'd/m/Y'  => date_i18n( 'd/m/Y' ) . ' (d/m/Y)',
				'Y.m.d'  => date_i18n( 'Y.m.d' ) . ' (Y.m.d)',
				'F,Y'    => date_i18n( 'F,Y' ) . ' (F,Y)',
				'm.d.y'  => date_i18n( 'm.d.y' ) . ' (m.d.y)',
				'd.m.y'  => date_i18n( 'd.m.y' ) . ' (d.m.y)',
				'd.m.y'  => date_i18n( 'd.m.Y' ) . ' (d.m.Y)',
				'm-d-y'  => date_i18n( 'm-d-y' ) . ' (m-d-y)',
			);

			/**
			 * Filter to modify the date format options.
			 *
			 * @since 3.2.2
			 */
			$date_format_options = apply_filters( 'everest_forms_date_format_options', $date_format_types );
			$date_format_select  = $this->field_element(
				'select',
				$field,
				array(
					'slug'    => 'date_format',
					'value'   => isset( $field['date_format'] ) ? $field['date_format'] : 'Y-m-d',
					'class'   => 'evf-date-format',
					'options' => $date_format_options,
				),
				false
			);

			// Disable certain dates option.
			$clear_disabled_dates_button = sprintf( '<a href="#" class="evf-clear-disabled-dates after-label-description">%s</a>', esc_html__( 'Clear', 'everest-forms' ) );
			$disable_dates_label         = $this->field_element(
				'label',
				$field,
				array(
					'slug'          => 'disable_dates',
					'value'         => esc_html__( 'Disable Dates', 'everest-forms' ),
					'tooltip'       => esc_html__( 'Select which dates you want to disable.', 'everest-forms' ),
					'after_tooltip' => $clear_disabled_dates_button,
				),
				false
			);
			$disable_dates               = $this->field_element(
				'text',
				$field,
				array(
					'slug'  => 'disable_dates',
					'value' => isset( $field['disable_dates'] ) ? $field['disable_dates'] : '',
					'class' => 'everest-forms-disable-dates',
					'data'  => array(
						'date-format' => isset( $field['date_format'] ) ? $field['date_format'] : 'Y-m-d',
					),
				),
				false
			);

			$current_date_mode = $this->field_element(
				'radio',
				$field,
				array(
					'slug'    => 'date_mode',
					'default' => isset( $field['date_mode'] ) ? $field['date_mode'] : 'single',
					'desc'    => esc_html__( 'Date Mode', 'everest-forms' ),
					'tooltip' => esc_html__( 'Select your desire date mode.', 'everest-forms' ),
					'options' => array(
						'single'   => 'Single',
						'range'    => 'Range',
						'multiple' => 'Multiple',
					),
				),
				false
			);

			$date_localization_label = $this->field_element(
				'label',
				$field,
				array(
					'slug'    => 'date_localization',
					'value'   => esc_html__( 'Date Localization', 'everest-forms' ),
					'tooltip' => esc_html__( 'Choose a desire date localization to display.', 'everest-forms' ),
				),
				false
			);

			$date_localization_select = $this->field_element(
				'select',
				$field,
				array(
					'slug'    => 'date_localization',
					'value'   => isset( $field['date_localization'] ) ? $field['date_localization'] : 'en',
					'options' => array(
						'en'    => 'English',
						'ar'    => 'Arabic',
						'at'    => 'Austria',
						'az'    => 'Azerbaijan',
						'be'    => 'Belarusian',
						'bg'    => 'Bulgarian',
						'bn'    => 'Bangla',
						'bs'    => 'Bosnian',
						'cat'   => 'Catalan',
						'cs'    => 'Czech',
						'cy'    => 'Welsh',
						'da'    => 'Danish',
						'de'    => 'German',
						'eo'    => 'Esperanto',
						'es'    => 'Spanish',
						'et'    => 'Estonian',
						'fa'    => 'Persian',
						'fi'    => 'Finnish',
						'fo'    => 'Faroese',
						'fr'    => 'French',
						'ga'    => 'Irish',
						'gr'    => 'Greek',
						'he'    => 'Hebrew',
						'hi'    => 'Hindi',
						'hr'    => 'Croatian',
						'hu'    => 'Hungarian',
						'id'    => 'Indonesian',
						'is'    => 'Icelandic',
						'it'    => 'Italian',
						'ja'    => 'Japanese',
						'ka'    => 'Georgian',
						'ko'    => 'Korean',
						'km'    => 'Khmer',
						'kz'    => 'Kazakh',
						'lt'    => 'Lithuanian',
						'lv'    => 'Latvian',
						'mk'    => 'Macedonian',
						'mn'    => 'Mongolian',
						'ms'    => 'Malaysian',
						'my'    => 'Burmese',
						'nl'    => 'Dutch',
						'no'    => 'Norwegian',
						'pa'    => 'Punjabi',
						'pl'    => 'Polish',
						'pt'    => 'Portuguese',
						'ro'    => 'Romanian',
						'ru'    => 'Russian',
						'si'    => 'Sinhala',
						'sk'    => 'Slovak',
						'sl'    => 'Slovenian',
						'sq'    => 'Albanian',
						'sr'    => 'Serbian',
						'sv'    => 'Swedish',
						'th'    => 'Thai',
						'tr'    => 'Turkish',
						'uk'    => 'Ukrainian',
						'vn'    => 'Vietnamese',
						'zh'    => 'Mandarin',
						'zh_tw' => 'MandarinTraditional',
					),
				),
				false
			);

			$date_timezone_label = $this->field_element(
				'label',
				$field,
				array(
					'slug'    => 'date_timezone',
					'value'   => esc_html__( 'Timezone', 'everest-forms' ),
					'tooltip' => esc_html__( 'Choose a desired timezone to save datetime in.', 'everest-forms' ),
				),
				false
			);

			$date_timezone_select = $this->field_element(
				'select',
				$field,
				array(
					'slug'    => 'date_timezone',
					'value'   => isset( $field['date_timezone'] ) ? $field['date_timezone'] : '',
					'options' => $this->get_timezones(),
				),
				false
			);

			$current_date_default = $this->field_element(
				'toggle',
				$field,
				array(
					'slug'    => 'date_default',
					'value'   => isset( $field['date_default'] ) ? $field['date_default'] : '',
					'desc'    => esc_html__( 'Default to current date.', 'everest-forms' ),
					'tooltip' => esc_html__( 'Check this option to set current date as default.', 'everest-forms' ),
				),
				false
			);

			$enable_past_date_disable = $this->field_element(
				'toggle',
				$field,
				array(
					'slug'    => 'past_date_disable',
					'value'   => isset( $field['past_date_disable'] ) ? $field['past_date_disable'] : '',
					'desc'    => esc_html__( 'Disable the past date', 'everest-forms' ),
					'tooltip' => esc_html__( 'Check this option to disable the past date', 'everest-forms' ),
				),
				false
			);

			$enable_min_max = $this->field_element(
				'toggle',
				$field,
				array(
					'slug'    => 'enable_min_max',
					'value'   => isset( $field['enable_min_max'] ) ? $field['enable_min_max'] : '',
					'desc'    => esc_html__( 'Enable Min Max date.', 'everest-forms' ),
					'tooltip' => esc_html__( 'Check this option to set min max date.', 'everest-forms' ),
				),
				false
			);

			$min_date_label = $this->field_element(
				'label',
				$field,
				array(
					'slug'    => 'min_date',
					'value'   => esc_html__( 'Minimum Date', 'everest-forms' ),
					'tooltip' => esc_html__( 'Select minium date.', 'everest-forms' ),
				),
				false
			);

			$min_date = $this->field_element(
				'text',
				$field,
				array(
					'slug'  => 'min_date',
					'value' => isset( $field['min_date'] ) ? $field['min_date'] : '',
					'class' => 'everest-forms-min-date',
				),
				false
			);

			$max_date_label = $this->field_element(
				'label',
				$field,
				array(
					'slug'    => 'max_date',
					'value'   => esc_html__( 'Maximum Date', 'everest-forms' ),
					'tooltip' => esc_html__( 'Select maximum date.', 'everest-forms' ),
				),
				false
			);

			$max_date = $this->field_element(
				'text',
				$field,
				array(
					'slug'  => 'max_date',
					'value' => isset( $field['max_date'] ) ? $field['max_date'] : '',
					'class' => 'everest-forms-max-date',
				),
				false
			);

			$set_date_range = $this->field_element(
				'toggle',
				$field,
				array(
					'slug'    => 'set_date_range',
					'value'   => isset( $field['set_date_range'] ) ? $field['set_date_range'] : '',
					'desc'    => esc_html__( 'Enable Custom Input', 'everest-forms' ),
					'tooltip' => esc_html__( "Check this option to set date range 'x' days after today.", 'everest-forms' ),
				),
				false
			);

			$min_date_range_level = $this->field_element(
				'label',
				$field,
				array(
					'slug'    => 'min_date_range',
					'value'   => esc_html__( 'Minimum Date', 'everest-forms' ),
					'tooltip' => esc_html__( 'Number of days after today or before for negative numbers. Example: today or +14 days or -5 days.', 'everest-forms' ),
				),
				false
			);

			$min_date_range = $this->field_element(
				'text',
				$field,
				array(
					'slug'        => 'min_date_range',
					'value'       => isset( $field['min_date_range'] ) ? $field['min_date_range'] : '',
					'class'       => 'everest-forms-min-date-range',
					'placeholder' => 'e.g. today',
				),
				false
			);

			$max_date_range_label = $this->field_element(
				'label',
				$field,
				array(
					'slug'    => 'max_date_range',
					'value'   => esc_html__( 'Maximum Date', 'everest-forms' ),
					'tooltip' => esc_html__( 'Number of days after today or before for negative numbers. Example: today or +14 days or -5 days.', 'everest-forms' ),
				),
				false
			);

			$max_date_range = $this->field_element(
				'text',
				$field,
				array(
					'slug'        => 'max_date_range',
					'value'       => isset( $field['max_date_range'] ) ? $field['max_date_range'] : '',
					'class'       => 'everest-forms-max-date-range',
					'placeholder' => 'e.g. +14 days',
				),
				false
			);

			$args = array(
				'slug'    => 'date_format',
				'content' => $date_format_label . $date_format_select . $disable_dates_label . $disable_dates . $date_localization_label . $date_localization_select . $date_timezone_label . $date_timezone_select . '<div class="everest-forms-checklist everest-forms-checklist-inline">' . $current_date_mode . '</div><div class="everest-forms-current-date-format">' . $current_date_default . '</div><div class="everest-forms-past-date-disable-format">' . $enable_past_date_disable . '</div><div class="everest-forms-min-max-date-format">' . $enable_min_max . '</div><div class="everest-forms-min-max-date-range-format ' . $class_name . '">' . $set_date_range . '</div><div class="everest-forms-min-max-date-option ' . $class_name . '">' . $min_date_label . $min_date . $max_date_label . $max_date . '</div><div class="everest-forms-min-max-date-range-option ' . $class_name . '">' . $min_date_range_level . $min_date_range . $max_date_range_label . $max_date_range . '</div>',
			);
			$this->field_element( 'row', $field, $args );

			echo '</div>';

			echo '<div class="everest-forms-border-container everest-forms-time">';
			echo '<h4 class="everest-forms-border-container-title">' . esc_html__( 'Time', 'everest-forms' ) . '</h4>'; // phpcs:ignore WordPress.Security.NonceVerification

			$time_format_label = $this->field_element(
				'label',
				$field,
				array(
					'slug'    => 'time_interval',
					'value'   => esc_html__( 'Time interval and format', 'everest-forms' ),
					'tooltip' => esc_html__( 'Choose time interval and format to display.', 'everest-forms' ),
				),
				false
			);

			$time_interval_select  = '<div class="input-group-col-2">';
			$time_interval_select .= $this->field_element(
				'select',
				$field,
				array(
					'slug'    => 'time_interval',
					'value'   => isset( $field['time_interval'] ) ? $field['time_interval'] : '',
					'class'   => 'time_interval',
					'options' => array(
						'15' => esc_html__( '15 minutes', 'everest-forms' ),
						'30' => esc_html__( '30 minutes', 'everest-forms' ),
						'60' => esc_html__( '1 hour', 'everest-forms' ),
					),
				),
				false
			);
			$time_format_select    = $this->field_element(
				'select',
				$field,
				array(
					'slug'    => 'time_format',
					'value'   => isset( $field['time_format'] ) ? $field['time_format'] : '',
					'class'   => 'time_format',
					'options' => array(
						'g:i A' => esc_html__( '12 H', 'everest-forms' ),
						'H:i'   => esc_html__( '24 H', 'everest-forms' ),
					),
				),
				false
			);
			$time_format_select   .= '</div>';

		$min_time_select  = '<div class="input-group-col-2">';
		$min_time_select .= $this->field_element(
			'select',
			$field,
			array(
				'slug'    => 'min_time_hour',
				'value'   => isset( $field['min_time_hour'] ) ? $field['min_time_hour'] : 9,
				'class'   => 'min_time_hour',
				'options' => $this->get_minute_hours( $field, 'hours' ),
			),
			false
		);
		$min_time_select .= $this->field_element(
			'select',
			$field,
			array(
				'slug'    => 'min_time_minute',
				'value'   => isset( $field['min_time_minute'] ) ? $field['min_time_minute'] : 30,
				'class'   => 'min_time_minute',
				'options' => $this->get_minute_hours( $field, 'minutes' ),
			),
			false
		);
		$min_time_select .= '</div>';

		$max_time_select  = '<div class="input-group-col-2">';
		$max_time_select .= $this->field_element(
			'select',
			$field,
			array(
				'slug'    => 'max_time_hour',
				'value'   => isset( $field['max_time_hour'] ) ? $field['max_time_hour'] : 18,
				'class'   => 'max_time_hour',
				'options' => $this->get_minute_hours( $field, 'hours' ),
			),
			false
		);
		$max_time_select .= $this->field_element(
			'select',
			$field,
			array(
				'slug'    => 'max_time_minute',
				'value'   => isset( $field['max_time_minute'] ) ? $field['max_time_minute'] : 30,
				'class'   => 'max_time_minute',
				'options' => $this->get_minute_hours( $field, 'minutes' ),
			),
			false
		);
		$max_time_select .= '</div>';

		$enable_min_max_time = '<div class="input-group-col-2">';

		$enable_min_max_time .= $this->field_element(
			'toggle',
			$field,
			array(
				'slug'    => 'enable_min_max_time',
				'value'   => isset( $field['enable_min_max_time'] ) ? $field['enable_min_max_time'] : '',
				'desc'    => esc_html__( 'Enable Min Max Time.', 'everest-forms' ),
				'tooltip' => esc_html__( 'Check this option to set min max time.', 'everest-forms' ),
			),
			false
		);
		$enable_min_max_time .= '</div>';

		$select_min_time = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'select_min_time',
				'value'   => esc_html__( 'Minimum Time', 'everest-forms' ),
				'tooltip' => esc_html__( 'Select minium time.', 'everest-forms' ),
			),
			false
		);

		$select_max_time = $this->field_element(
			'label',
			$field,
			array(
				'slug'    => 'select_max_time',
				'value'   => esc_html__( 'Maximum Time', 'everest-forms' ),
				'tooltip' => esc_html__( 'Select maximum time.', 'everest-forms' ),
			),
			false
		);
		$args            = array(
			'slug'    => 'time_interval_format',
			'content' => $time_format_label . $time_interval_select . $time_format_select . $enable_min_max_time . $select_min_time . $min_time_select . $select_max_time . $max_time_select,
		);
		$this->field_element( 'row', $field, $args );

		echo '</div>';
		echo '<div class="everest-forms-border-container everest-forms-slot-booking">';
		echo '<h4 class="everest-forms-border-container-title">' . esc_html__( 'Slot Booking', 'everest-forms' ) . '</h4>'; // phpcs:ignore WordPress.Security.NonceVerification

		$slot_booking_toggle = $this->field_element(
			'toggle',
			$field,
			array(
				'slug'    => 'slot_booking_advanced',
				'desc'    => esc_html__( 'Enable Slot Booking', 'everest-forms' ),
				'value'   => isset( $field['slot_booking_advanced'] ) ? $field['slot_booking_advanced'] : false,
				'tooltip' => esc_html__( 'Enable to use date/time field as slot booking.', 'everest-forms' ),
				'class'   => 'slot-booking-advanced	',
				'default' => false,
			),
			false
		);

		$args = array(
			'slug'    => 'slot_booking_advanced_setting',
			'content' => $slot_booking_toggle,
		);
		$this->field_element( 'row', $field, $args );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Time Hours/Minutes Provider.
	 *
	 * @since 1.7.5
	 * @param mixed  $field field data.
	 * @param string $required required type.
	 */
	public function get_minute_hours( $field, $required = 'hours' ) {
		$required_array = array();
		if ( 'hours' === $required ) {
			// Hours Array.
			$period = '';
			for ( $i = 0; $i <= 23; $i++ ) {
				if ( isset( $field['time_format'] ) && 'H:i' === $field['time_format'] ) {
					$required_array [] = ( $i < 10 ? '0' . $i : $i ) . $period;
				} else {
					if ( $i < 12 ) {
						$period = ' AM';
					} else {
						$period = ' PM';
					}
					$hour = $i;
					if ( 0 === $i ) {
						$hour = 12;
					}
					if ( $hour > 12 ) {
						$hour = $i - 12;
					}
					$required_array [] = $hour . $period;
				}
			}
		} else {
			// Minutes Array.
			for ( $i = 0; $i <= 59; $i++ ) {
				$required_array [] = ( $i < 10 ) ? '0' . $i : $i;
			}
		}
		return $required_array;
	}

	/**
	 * Define additional field properties.
	 *
	 * @since 1.0.0
	 *
	 * @param array $properties Field properties.
	 * @param array $field      Field settings.
	 * @param array $form_data  Form data and settings.
	 *
	 * @return array of additional field properties.
	 */
	public function field_properties( $properties, $field, $form_data ) {
		// Input primary: data-time-interval.
		if ( ! empty( $field['time_interval'] ) ) {
			$properties['inputs']['primary']['attr']['data-time-interval'] = esc_attr( $field['time_interval'] );
		}

		// Input primary: Disabled dates data.
		if ( ! empty( $field['disable_dates'] ) ) {
			$properties['inputs']['primary']['attr']['data-disable-dates'] = esc_attr( $field['disable_dates'] );
		}

		// Input primary: Booked slot.
		if ( isset( $field['slot_booking_advanced'] ) && evf_string_to_bool( $field['slot_booking_advanced'] ) ) {
			$properties['inputs']['primary']['attr']['data-slot-booking'] = esc_attr( $field['slot_booking_advanced'] );
			$properties['inputs']['primary']['attr']['data-form-id']      = esc_attr( $form_data['id'] );
		}

		// Input primary: data-date-time.
		if ( ! empty( $field['datetime_format'] ) ) {
			$properties['inputs']['primary']['attr']['data-date-time'] = esc_attr( $field['datetime_format'] );

			// Input primary: data-mode.
			if ( 'time' !== $field['datetime_format'] ) {
				if ( isset( $field['date_range'] ) && '1' === $field['date_range'] ) {
					$properties['inputs']['primary']['attr']['data-mode'] = 'range';
				} else {
					$properties['inputs']['primary']['attr']['data-mode'] = isset( $field['date_mode'] ) ? $field['date_mode'] : 'single';
				}
				$properties['inputs']['primary']['attr']['data-locale']         = isset( $field['date_localization'] ) ? $field['date_localization'] : 'en';
				$properties['inputs']['primary']['attr']['data-min-date']       = isset( $field['enable_min_max'], $field['min_date'] ) && ! isset( $field['set_date_range'] ) ? $field['min_date'] : '';
				$properties['inputs']['primary']['attr']['data-max-date']       = isset( $field['enable_min_max'], $field['max_date'] ) && ! isset( $field['set_date_range'] ) ? $field['max_date'] : '';
				$properties['inputs']['primary']['attr']['data-min-date-range'] = isset( $field['set_date_range'], $field['enable_min_max'], $field['min_date_range'] ) ? $field['min_date_range'] : '';
				$properties['inputs']['primary']['attr']['data-max-date-range'] = isset( $field['set_date_range'], $field['enable_min_max'], $field['max_date_range'] ) ? $field['max_date_range'] : '';
			}

			if ( 'date' !== $field['datetime_format'] ) {
				if ( isset( $field['enable_min_max_time'] ) ) {
					$properties['inputs']['primary']['attr']['data-min-hour']   = isset( $field['min_time_hour'] ) ? $field['min_time_hour'] : '';
					$properties['inputs']['primary']['attr']['data-min-minute'] = isset( $field['min_time_minute'] ) ? $field['min_time_minute'] : '';
					$properties['inputs']['primary']['attr']['data-max-hour']   = isset( $field['max_time_hour'] ) ? $field['max_time_hour'] : '';
					$properties['inputs']['primary']['attr']['data-max-minute'] = isset( $field['max_time_minute'] ) ? $field['max_time_minute'] : '';
				}
			}

			// Input primary: data-date-format and value.
			switch ( $field['datetime_format'] ) {
				case 'date':
					$properties['inputs']['primary']['attr']['value']                  = isset( $field['date_default'] ) ? esc_attr( date_i18n( $field['date_format'] ) ) : '';
					$properties['inputs']['primary']['attr']['data-date-format']       = ! empty( $field['date_format'] ) ? str_replace( 'g:i A', 'h:i K', esc_attr( $field['date_format'] ) ) : '';
					$properties['inputs']['primary']['attr']['data-past-disable-date'] = isset( $field['past_date_disable'] ) ? esc_attr( date_i18n( $field['date_format'] ) ) : '';
					break;
				case 'time':
					$properties['inputs']['primary']['attr']['value']            = '';
					$properties['inputs']['primary']['attr']['data-date-format'] = ! empty( $field['time_format'] ) ? str_replace( 'g:i A', 'h:i K', esc_attr( $field['time_format'] ) ) : 'g:i A';
					break;
				case 'date-time':
					if ( ! empty( $field['time_format'] ) ) {
						$date_format                                      = esc_attr( $field['date_format'] ) . ' ' . esc_attr( $field['time_format'] );
						$properties['inputs']['primary']['attr']['value'] = isset( $field['date_default'] ) ? esc_attr( date_i18n( $date_format ) ) : '';
						$properties['inputs']['primary']['attr']['data-date-format']       = ! empty( $field['date_format'] ) ? str_replace( 'g:i A', 'h:i K', esc_attr( $date_format ) ) : '';
						$properties['inputs']['primary']['attr']['data-past-disable-date'] = isset( $field['past_date_disable'] ) ? esc_attr( date_i18n( $field['date_format'] ) ) : '';
					} else {
						$date_format                                      = esc_attr( $field['date_format'] ) . ' g:i A';
						$properties['inputs']['primary']['attr']['value'] = isset( $field['date_default'] ) ? esc_attr( date_i18n( $date_format ) ) : '';
						$properties['inputs']['primary']['attr']['data-date-format']       = ! empty( $field['date_format'] ) ? str_replace( 'g:i A', 'h:i K', esc_attr( $date_format ) ) : '';
						$properties['inputs']['primary']['attr']['data-past-disable-date'] = isset( $field['past_date_disable'] ) ? esc_attr( date_i18n( $field['date_format'] ) ) : '';
					}
					break;
			}
		}
		return apply_filters( 'everest_forms_date_time_properties', $properties, $field );
	}

	/**
	 * Field preview inside the builder.
	 *
	 * @since 1.0.0
	 *
	 * @param array $field Field data and settings.
	 */
	public function field_preview( $field ) {
		// Define data.
		$placeholder = ! empty( $field['placeholder'] ) ? esc_attr( $field['placeholder'] ) : '';

		// Label.
		$this->field_preview_option( 'label', $field );

		// Primary input.
		echo '<input type="text" placeholder="' . esc_attr( $placeholder ) . '" class="widefat" disabled>';

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
		$primary = $field['properties']['inputs']['primary'];

		$class = array_merge( array( 'flatpickr-field' ), $primary['class'] );

		if ( ! array_key_exists( 'datetime_style', $field ) || 'picker' === $field['datetime_style'] ) {
			$class = array_merge( array( 'flatpickr-field' ), $primary['class'] );
			// Primary field.
			printf(
				'<input type="text" %s %s >',
				evf_html_attributes( $primary['id'], $class, $primary['data'], $primary['attr'] ),
				esc_attr( $primary['required'] )
			);
		} else {
			$class = array_merge( array( 'date-dropdown-field' ), $primary['class'] );
			echo '<div class="date-time-container">';

			printf(
				'<input type="text" %s %s >',
				evf_html_attributes( $primary['id'], $class, $primary['data'], $primary['attr'] ),
				esc_attr( $primary['required'] )
			);

			// Preselect the field's own value (e.g. when editing an entry), not
			// today. Both the date and the time selects read this.
			$primary['selected_date'] = $this->get_dropdown_selected_date( $primary, $field );

			if ( 'date-time' === $field['datetime_format'] || 'date' === $field['datetime_format'] ) {
				$data_date_format = $primary['attr']['data-date-format'];

				if ( 'F j, Y' === $data_date_format || 'm/d/Y' === $data_date_format || 'F j, Y H:i' === $data_date_format || 'F j, Y h:i K' === $data_date_format || 'm/d/Y H:i' === $data_date_format || 'm/d/Y h:i K' === $data_date_format ) {
					$this->get_month_html( $primary );
					$this->get_day_html( $primary );
					$this->get_year_html( $primary );
				} elseif ( 'd/m/Y' === $data_date_format || 'd/m/Y H:i' === $data_date_format || 'd/m/Y h:i K' === $data_date_format ) {
					$this->get_day_html( $primary );
					$this->get_month_html( $primary );
					$this->get_year_html( $primary );
				} else {
					$this->get_year_html( $primary );
					$this->get_month_html( $primary );
					$this->get_day_html( $primary );
				}
			}

			if ( 'date-time' === $field['datetime_format'] ) {
				echo '<span class="date-time-space-filler"></span>';
			}

			if ( 'time' === $field['datetime_format'] || 'date-time' === $field['datetime_format'] ) {

				$min_hour = (int) ( isset( $field['min_time_hour'], $field['enable_min_max_time'] ) ? $field['min_time_hour'] : 0 );
				$max_hour = (int) ( isset( $field['min_time_hour'], $field['enable_min_max_time'] ) ? $field['max_time_hour'] : 23 );

				// The field's own time when there is one, today's hour otherwise.
				$selected_hour = null === $primary['selected_date']['hour'] ? (int) gmdate( 'H' ) : $primary['selected_date']['hour'];

				if ( $selected_hour < $min_hour || $selected_hour > $max_hour ) {
					$selected_hour = $min_hour;
				}

				// For Hours.
				printf(
					'<select value = "%s" %s>',
					esc_attr( $selected_hour ),
					evf_html_attributes( 'hour-select-' . esc_attr( $primary['id'] ) )
				);

				for ( $i = $min_hour; $i <= $max_hour; $i++ ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $i ),
						$selected_hour === $i ? 'selected' : '',
						esc_html( $this->get_minute_hours( $field, 'hours' )[ $i ] )
					);
				}

				echo '</select>';

				$time_interval = isset( $field['time_interval'] ) ? $field['time_interval'] : 1;

				// For Minutes. The options are built by the frontend script, so
				// hand it the minute to restore once it has.
				printf(
					'<select data-selected-minute="%s" %s>',
					esc_attr( null === $primary['selected_date']['minute'] ? '' : $primary['selected_date']['minute'] ),
					evf_html_attributes( 'minute-select-' . esc_attr( $primary['id'] ) )
				);
				echo '</select>';
			}

			echo '</div>';
		}
	}

	/**
	 * Register/queue frontend scripts.
	 *
	 * @param array $atts Shortcode attributes.
	 */
	public static function load_assets( $atts ) {
		$form_id   = isset( $atts['id'] ) ? wp_unslash( $atts['id'] ) : ''; // WPCS: CSRF ok, input var ok, sanitization ok.
		$form_obj  = evf()->form->get( $form_id );
		$form_data = ! empty( $form_obj->post_content ) ? evf_decode( $form_obj->post_content ) : '';

		if ( ! empty( $form_data['form_fields'] ) ) {
			$data_i10ns = array();
			foreach ( $form_data['form_fields'] as $form_field ) {
				if ( 'date-time' === $form_field['type'] && isset( $form_field['datetime_style'] ) && 'picker' === $form_field['datetime_style'] ) {
					$data_i10n = isset( $form_field['date_localization'] ) ? $form_field['date_localization'] : 'en';

					if ( ! in_array( $data_i10n, $data_i10ns, true ) && 'en' !== $data_i10n ) {
						$data_i10ns[] = $data_i10n;
					}
				}
			}

			if ( ! empty( $data_i10ns ) ) {
				foreach ( $data_i10ns as $data_i10n ) {
					if ( wp_script_is( 'flatpickr' ) ) {
						wp_enqueue_script( 'flatpickr-localization-' . $data_i10n, evf()->plugin_url() . '/assets/js/flatpickr/dist/I10n/' . $data_i10n . '.js', array(), EVF_VERSION, true );
					}
				}
			}
		}
	}

	/**
	 * Resolve which date and time the dropdowns should preselect.
	 *
	 * The dropdowns are also used to render an existing value (editing an entry,
	 * a validation redisplay, Save & Continue), so they must reflect the field's
	 * own value and only fall back to today when there isn't one.
	 *
	 * @since 3.5.4
	 *
	 * @param array $primary Primary input properties.
	 * @param array $field   Field data and settings.
	 * @return array Year, month and day as integers, plus hour and minute as
	 *               integers when the value carried a time and null when it did not.
	 */
	private function get_dropdown_selected_date( $primary, $field ) {
		$fallback = array(
			'year'   => (int) gmdate( 'Y' ),
			'month'  => (int) gmdate( 'm' ),
			'day'    => (int) gmdate( 'd' ),
			'hour'   => null,
			'minute' => null,
		);

		$value = isset( $primary['attr']['value'] ) ? trim( $primary['attr']['value'] ) : '';

		if ( '' === $value ) {
			return $fallback;
		}

		$datetime_format = ! empty( $field['datetime_format'] ) ? $field['datetime_format'] : 'date';
		$date_format     = ! empty( $field['date_format'] ) ? $field['date_format'] : 'Y-m-d';
		$time_format     = ! empty( $field['time_format'] ) ? $field['time_format'] : 'g:i A';

		// Widest format first: a stored date-time still yields its date half
		// when the field has since been switched to date only. The flag says
		// whether a match carries a usable time.
		if ( 'time' === $datetime_format ) {
			$formats = array( $time_format => true );
		} elseif ( 'date-time' === $datetime_format ) {
			$formats = array(
				$date_format . ' ' . $time_format => true,
				$date_format                      => false,
			);
		} else {
			$formats = array( $date_format => false );
		}

		foreach ( $formats as $format => $has_time ) {
			$date = DateTime::createFromFormat( $format, $value );

			if ( ! $date instanceof DateTime ) {
				continue;
			}

			// createFromFormat() rolls an impossible date over rather than
			// failing (Feb 31 becomes Mar 2), so only accept a parse that
			// renders back to what was stored.
			$rendered = $date->format( $format );

			if ( 0 !== strncmp( $rendered, $value, strlen( $rendered ) ) ) {
				continue;
			}

			return array(
				'year'   => (int) $date->format( 'Y' ),
				'month'  => (int) $date->format( 'n' ),
				'day'    => (int) $date->format( 'j' ),
				'hour'   => $has_time ? (int) $date->format( 'G' ) : null,
				'minute' => $has_time ? (int) $date->format( 'i' ) : null,
			);
		}

		return $fallback;
	}

	/**
	 * Print HTML for year.
	 *
	 * @param array $primary Primary.
	 * @return void
	 */
	private function get_year_html( $primary ) {
		$selected_year = $primary['selected_date']['year'];

		// For Years.
		printf(
			'<select value="%s" %s >',
			esc_attr( $selected_year ),
			evf_html_attributes( 'year-select-' . esc_attr( $primary['id'] ) )
		);
		// Build the select options.
		$end_date   = gmdate( 'Y' ) + 100;
		$start_date = $end_date - 200;

		for ( $i = $end_date; $i >= $start_date; $i-- ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $i ),
				$selected_year === $i ? 'selected' : '',
				esc_html( $i )
			);
		}
		echo '</select>';
	}

	/**
	 * Print HTML for month.
	 *
	 * @param array $primary Primary.
	 * @return void
	 */
	private function get_month_html( $primary ) {
		$selected_month = $primary['selected_date']['month'];

		// For Months.
		printf(
			'<select value="%s" %s >',
			esc_attr( sprintf( '%02d', $selected_month ) ),
			evf_html_attributes( 'month-select-' . esc_attr( $primary['id'] ) )
		);
		// Build the select options.
		for ( $i = 1; $i <= 12; $i++ ) {
			$month = ( $i < 10 ) ? '0' . $i : $i;
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $i ),
				$selected_month === $i ? 'selected' : '',
				esc_html( $month )
			);
		}
		echo '</select>';
	}

	/**
	 * Print HTML for day.
	 *
	 * @param array $primary Primary.
	 * @return void
	 */
	private function get_day_html( $primary ) {
		$selected_day = $primary['selected_date']['day'];

		// For Days.
		printf(
			'<select value="%s" %s >',
			esc_attr( sprintf( '%02d', $selected_day ) ),
			evf_html_attributes( 'day-select-' . esc_attr( $primary['id'] ) )
		);
		// Build the select options.
		for ( $i = 1; $i <= 31; $i++ ) {
			$day = $i < 10 ? '0' . $i : $i;
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $i ),
				$selected_day === $i ? 'selected' : '',
				esc_html( $day )
			);
		}
		echo '</select>';
	}



	/**
	 * Returns a list of all timezones.
	 */
	public function get_timezones() {

		$utc_timezone    = new DateTimeZone( 'UTC' );
		$timezones       = DateTimeZone::listIdentifiers();
		$timezones_array = array(
			'Default' => 'Default',
		);

		foreach ( $timezones as $timezone ) {
			try {
				$dtz = new DateTimeZone( $timezone );
			} catch ( Exception $e ) {
				// Skip identifiers that the system timezone database does not recognize.
				continue;
			}
			$offset = $dtz->getOffset( new DateTime( 'now', $utc_timezone ) );

			$offset_hours   = abs( intval( $offset / 3600 ) );
			$offset_minutes = abs( intval( ( $offset % 3600 ) / 60 ) );
			$offset_sign    = ( $offset < 0 ) ? '-' : '+';

			$offset_string = sprintf( '%s%02d:%02d', $offset_sign, $offset_hours, $offset_minutes );

			$timezone_parts = explode( '/', $timezone );
			$timezone_parts = implode( '/', array_reverse( $timezone_parts ) );

			$timezones_array[ $timezone ] = $timezone_parts . " ($offset_string)";
		}

		return $timezones_array;
	}



	/**
	 * Save timezone data for datetime field.
	 *
	 * @param [array] $field Field.
	 * @param [array] $form_data Form Data.
	 * @param [int]   $entry_id Entry Id.
	 * @return array
	 */
	public function save_timezone( $field, $form_data, $entry_id ) {
		global $wpdb;

		$field_id = isset( $field['id'] ) ? $field['id'] : 0;

		if ( $field_id ) {
			$fields_settings = $form_data['form_fields'];
			$field_settings  = isset( $fields_settings[ $field_id ] ) ? $fields_settings[ $field_id ] : array();

			if ( ! empty( $field_settings ) && isset( $field_settings['date_timezone'] ) ) {

				$date_timezone = $field_settings['date_timezone'];

				if ( ! empty( $date_timezone ) && 'Default' !== $date_timezone ) {

					$entry_metadata = array(
						'entry_id'   => $entry_id,
						'meta_key'   => sanitize_key( $field_settings['meta-key'] . '_timezone' ),
						'meta_value' => maybe_serialize( $date_timezone ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					);

					// Insert entry meta.
					$wpdb->insert( $wpdb->prefix . 'evf_entrymeta', $entry_metadata );
				}
			}
		}

		return $field;
	}



	/**
	 * Adds Timezone for datetime column in entries table if available.
	 *
	 * @param [string] $value Value.
	 * @param [string] $entry_meta Entry Meta.
	 * @param [array]  $entry Entry.
	 * @param [string] $type Type.
	 * @param [string] $meta_key Meta Key.
	 *
	 * @return string
	 */
	public function entry_html( $value, $entry_meta, $entry, $type, $meta_key = '' ) {
		$field_metas = isset( $entry->meta ) && is_array( $entry->meta ) ? $entry->meta : array();

		$timezone_key = isset( $meta_key['meta_key'] )
			? sanitize_key( $meta_key['meta_key'] ) . '_timezone'
			: sanitize_key( (string) $meta_key ) . '_timezone';

		if ( isset( $meta_key['meta_key'], $field_metas[ $timezone_key ] ) && ! empty( $meta_key['meta_key'] ) ) {
			$timezone_value = sanitize_text_field( wp_unslash( (string) $field_metas[ $timezone_key ] ) );
			$all_timezones  = $this->get_timezones();

			if ( is_array( $all_timezones ) && isset( $all_timezones[ $timezone_value ] ) ) {
				$value .= '<p>' . esc_html( $all_timezones[ $timezone_value ] ) . '</p>';
			}
		}

		return $value;
	}



	/**
	 * Set default date time in frontend if timezone set.
	 *
	 * @param [array] $properties Properties array.
	 * @param [array] $field Field properties.
	 * @return array
	 */
	public function set_default_timezone( $properties, $field ) {
		// Does not set default date time if default date feature is not enabled.`
		if ( ! isset( $field['date_default'] ) || 1 !== absint( $field['date_default'] ) ) {
			return $properties;
		}

		if ( ! empty( $field['date_timezone'] ) && 'Default' !== $field['date_timezone'] ) {
			$timezone = $field['date_timezone'];

			if ( in_array( $timezone, timezone_identifiers_list(), true ) ) {

				$dtz      = new DateTimeZone( $timezone );
				$datetime = new DateTime( 'now', $dtz );

				switch ( $field['datetime_format'] ) {
					case 'date':
						$properties['inputs']['primary']['attr']['value'] = esc_attr( $datetime->format( $field['date_format'] ) );
						break;

					case 'date-time':
						if ( ! empty( $field['time_format'] ) ) {
							$date_format                                      = esc_attr( $field['date_format'] ) . ' ' . esc_attr( $field['time_format'] );
							$properties['inputs']['primary']['attr']['value'] = esc_attr( $datetime->format( $date_format ) );
						} else {
							$date_format                                      = esc_attr( $field['date_format'] ) . ' g:i A';
							$properties['inputs']['primary']['attr']['value'] = esc_attr( $datetime->format( $date_format ) );
						}
						break;
				}
			}
		}

		return $properties;
	}
	/**
	 * Form's field list for google calendar.
	 *
	 * @param int $form_id form id.
	 */
	public static function get_form_fields( $form_id ) {
		$text_field_name_option_list = array(
			'' => __( '---Select Field---', 'everest-forms' ),
		);
		if ( ! empty( $form_id ) && 'none' !== $form_id ) {
			$form       = json_decode( get_post_field( 'post_content', $form_id ) );
			$text_field = apply_filters( 'evf_support_field_type_for_google_event', array( 'first-name', 'last-name', 'text', 'textarea' ) );
			$form_arr   = isset( $form->form_fields ) ? (array) $form->form_fields : array();
			foreach ( $form_arr as $key => $value ) {
				if ( in_array( $value->type, $text_field, true ) ) {
					$text_field_name_option_list[ $key ] = $value->label;
				}
			}
		}
		return $text_field_name_option_list;
	}
}
