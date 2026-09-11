<?php

/**
 * EverestForms Core Functions
 *
 * General core functions available on both the front-end and admin.
 *
 * @package EverestForms/Functions
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

// Include core functions (available in both admin and frontend).
require EVF_ABSPATH . 'includes/evf-conditional-functions.php';
require EVF_ABSPATH . 'includes/evf-deprecated-functions.php';
require EVF_ABSPATH . 'includes/evf-formatting-functions.php';
require EVF_ABSPATH . 'includes/evf-entry-functions.php';

/**
 * Define a constant if it is not already defined.
 *
 * @since 1.0.0
 * @param string $name  Constant name.
 * @param mixed  $value Value.
 */
function evf_maybe_define_constant($name, $value)
{
	if (! defined($name)) {
		define($name, $value);
	}
}

/**
 * Get template part.
 *
 * EVF_TEMPLATE_DEBUG_MODE will prevent overrides in themes from taking priority.
 *
 * @param mixed  $slug Template slug.
 * @param string $name Template name (default: '').
 */
function evf_get_template_part($slug, $name = '')
{
	$cache_key = sanitize_key(implode('-', array('template-part', $slug, $name, EVF_VERSION)));
	$template  = (string) wp_cache_get($cache_key, 'everest-forms');

	if (! $template) {
		if ($name) {
			$template = EVF_TEMPLATE_DEBUG_MODE ? '' : locate_template(
				array(
					"{$slug}-{$name}.php",
					evf()->template_path() . "{$slug}-{$name}.php",
				)
			);

			if (! $template) {
				$fallback = evf()->plugin_path() . "/templates/{$slug}-{$name}.php";
				$template = file_exists($fallback) ? $fallback : '';
			}
		}

		if (! $template) {
			// If template file doesn't exist, look in yourtheme/slug.php and yourtheme/everest-forms/slug.php.
			$template = EVF_TEMPLATE_DEBUG_MODE ? '' : locate_template(
				array(
					"{$slug}.php",
					evf()->template_path() . "{$slug}.php",
				)
			);
		}

		wp_cache_set($cache_key, $template, 'everest-forms');
	}

	// Allow 3rd party plugins to filter template file from their plugin.
	$template = apply_filters('evf_get_template_part', $template, $slug, $name);

	if ($template) {
		load_template($template, false);
	}
}

/**
 * Get other templates passing attributes and including the file.
 *
 * @param string $template_name Template name.
 * @param array  $args          Arguments. (default: array).
 * @param string $template_path Template path. (default: '').
 * @param string $default_path  Default path. (default: '').
 */
function evf_get_template($template_name, $args = array(), $template_path = '', $default_path = '')
{
	$cache_key = sanitize_key(implode('-', array('template', $template_name, $template_path, $default_path, EVF_VERSION)));
	$template  = (string) wp_cache_get($cache_key, 'everest-forms');

	if (! $template) {
		$template = evf_locate_template($template_name, $template_path, $default_path);
		wp_cache_set($cache_key, $template, 'everest-forms');
	}

	// Allow 3rd party plugin filter template file from their plugin.
	$filter_template = apply_filters('evf_get_template', $template, $template_name, $args, $template_path, $default_path);

	if ($filter_template !== $template) {
		if (! file_exists($filter_template)) {
			/* translators: %s template */
			evf_doing_it_wrong(__FUNCTION__, sprintf(__('%s does not exist.', 'everest-forms'), '<code>' . $filter_template . '</code>'), '1.0.0');
			return;
		}
		$template = $filter_template;
	}

	$action_args = array(
		'template_name' => $template_name,
		'template_path' => $template_path,
		'located'       => $template,
		'args'          => $args,
	);

	if (! empty($args) && is_array($args)) {
		if (isset($args['action_args'])) {
			evf_doing_it_wrong(
				__FUNCTION__,
				__('action_args should not be overwritten when calling evf_get_template.', 'everest-forms'),
				'1.4.9'
			);
			unset($args['action_args']);
		}
		extract($args); // @codingStandardsIgnoreLine
	}

	do_action('everest_forms_before_template_part', $action_args['template_name'], $action_args['template_path'], $action_args['located'], $action_args['args']);

	include $action_args['located'];

	do_action('everest_forms_after_template_part', $action_args['template_name'], $action_args['template_path'], $action_args['located'], $action_args['args']);
}

/**
 * Like evf_get_template, but returns the HTML instead of outputting.
 *
 * @see    evf_get_template
 * @since  1.0.0
 * @param  string $template_name Template name.
 * @param  array  $args          Arguments. (default: array).
 * @param  string $template_path Template path. (default: '').
 * @param  string $default_path  Default path. (default: '').
 * @return string
 */
function evf_get_template_html($template_name, $args = array(), $template_path = '', $default_path = '')
{
	ob_start();
	evf_get_template($template_name, $args, $template_path, $default_path);
	return ob_get_clean();
}

/**
 * Locate a template and return the path for inclusion.
 *
 * This is the load order:
 *
 * yourtheme/$template_path/$template_name
 * yourtheme/$template_name
 * $default_path/$template_name
 *
 * @param  string $template_name Template name.
 * @param  string $template_path Template path. (default: '').
 * @param  string $default_path  Default path. (default: '').
 * @return string
 */
function evf_locate_template($template_name, $template_path = '', $default_path = '')
{
	if (! $template_path) {
		$template_path = evf()->template_path();
	}

	if (! $default_path) {
		$default_path = evf()->plugin_path() . '/templates/';
	}

	// Look within passed path within the theme - this is priority.
	$template = locate_template(
		array(
			trailingslashit($template_path) . $template_name,
			$template_name,
		)
	);

	// Get default template/.
	if (! $template || EVF_TEMPLATE_DEBUG_MODE) {
		$template = $default_path . $template_name;
	}

	// Return what we found.
	return apply_filters('everest_forms_locate_template', $template, $template_name, $template_path);
}

/**
 * Send HTML emails from EverestForms.
 *
 * @param mixed  $to          Receiver.
 * @param mixed  $subject     Subject.
 * @param mixed  $message     Message.
 * @param string $headers     Headers. (default: "Content-Type: text/html\r\n").
 * @param string $attachments Attachments. (default: "").
 */
function evf_mail($to, $subject, $message, $headers = "Content-Type: text/html\r\n", $attachments = '')
{
	$mailer = evf()->mailer();

	$mailer->send($to, $subject, $message, $headers, $attachments);
}

/**
 * Queue some JavaScript code to be output in the footer.
 *
 * @param string $code Code.
 */
function evf_enqueue_js($code)
{
	global $evf_queued_js;

	if (empty($evf_queued_js)) {
		$evf_queued_js = '';
	}

	$evf_queued_js .= "\n" . $code . "\n";
}

/**
 * Output any queued javascript code in the footer.
 */
function evf_print_js()
{
	global $evf_queued_js;

	if (! empty($evf_queued_js)) {
		// Sanitize.
		$evf_queued_js = wp_check_invalid_utf8($evf_queued_js);
		$evf_queued_js = preg_replace('/&#(x)?0*(?(1)27|39);?/i', "'", $evf_queued_js);
		$evf_queued_js = str_replace("\r", '', $evf_queued_js);

		$js = "<!-- Everest Forms JavaScript -->\n<script type=\"text/javascript\">\njQuery(function($) { $evf_queued_js });\n</script>\n";

		/**
		 * Queued jsfilter.
		 *
		 * @since 1.0.0
		 * @param string $js JavaScript code.
		 */
		echo wp_kses(apply_filters('everest_forms_queued_js', $js), array('script' => array('type' => true)));
		unset($evf_queued_js);
	}
}

/**
 * Set a cookie - wrapper for setcookie using WP constants.
 *
 * @param  string  $name   Name of the cookie being set.
 * @param  string  $value  Value of the cookie.
 * @param  integer $expire Expiry of the cookie.
 * @param  bool    $secure Whether the cookie should be served only over https.
 * @param  bool    $httponly Whether the cookie is only accessible over HTTP, not scripting languages like JavaScript. @since 1.4.9.
 */
function evf_setcookie($name, $value, $expire = 0, $secure = false, $httponly = false)
{
	if (! headers_sent()) {
		setcookie($name, $value, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, $secure, apply_filters('everest_forms_cookie_httponly', $httponly, $name, $value, $expire, $secure));
	} elseif (defined('WP_DEBUG') && WP_DEBUG) {
		headers_sent($file, $line);
		trigger_error("{$name} cookie cannot be set - headers already sent by {$file} on line {$line}", E_USER_NOTICE); // @codingStandardsIgnoreLine
	}
}

/**
 * Get a log file path.
 *
 * @since 1.0.0
 *
 * @param  string $handle name.
 * @return string the log file path.
 */
function evf_get_log_file_path($handle)
{
	return EVF_Log_Handler_File::get_log_file_path($handle);
}

/**
 * Get a csv file name.
 *
 * File names consist of the handle, followed by the date, followed by a hash, .csv.
 *
 * @since 1.3.0
 *
 * @param  string $handle Name.
 * @param  string $extension Extension Type.
 * @return bool|string The csv file name or false if cannot be determined.
 */
function evf_get_entry_export_file_name($handle, $extension = 'csv')
{
	if (function_exists('wp_hash')) {
		$date_suffix = date_i18n('Y-m-d', time());
		$hash_suffix = wp_hash($handle);
		return sanitize_file_name(implode('-', array('evf-entry-export', $handle, $date_suffix, $hash_suffix)) . '.' . $extension);
	} else {
		evf_doing_it_wrong(__METHOD__, __('This method should not be called before plugins_loaded.', 'everest-forms'), '1.3.0');
		return false;
	}
}

/**
 * Recursively get page children.
 *
 * @param  int $page_id Page ID.
 * @return int[]
 */
function evf_get_page_children($page_id)
{
	$page_ids = get_posts(
		array(
			'post_parent' => $page_id,
			'post_type'   => 'page',
			'numberposts' => -1,
			'post_status' => 'any',
			'fields'      => 'ids',
		)
	);

	if (! empty($page_ids)) {
		foreach ($page_ids as $page_id) {
			$page_ids = array_merge($page_ids, evf_get_page_children($page_id));
		}
	}

	return $page_ids;
}

/**
 * Get user agent string.
 *
 * @since  1.0.0
 * @return string
 */
function evf_get_user_agent()
{
	return isset($_SERVER['HTTP_USER_AGENT']) ? evf_clean(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : ''; // @codingStandardsIgnoreLine
}

// This function can be removed when WP 3.9.2 or greater is required.
if (! function_exists('hash_equals')) :
	/**
	 * Compare two strings in constant time.
	 *
	 * This function was added in PHP 5.6.
	 * It can leak the length of a string.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $a Expected string.
	 * @param  string $b Actual string.
	 * @return bool Whether strings are equal.
	 */
	function hash_equals($a, $b)
	{
		$a_length = strlen($a);
		if (strlen($b) !== $a_length) {
			return false;
		}
		$result = 0;

		// Do not attempt to "optimize" this.
		for ($i = 0; $i < $a_length; $i++) {
			$result |= ord($a[$i]) ^ ord($b[$i]);
		}

		return 0 === $result;
	}
endif;

/**
 * Generate a rand hash.
 *
 * @since  1.0.0
 * @return string
 */
function evf_rand_hash()
{
	if (! function_exists('openssl_random_pseudo_bytes')) {
		return sha1(wp_rand());
	}

	return bin2hex(openssl_random_pseudo_bytes(20)); // @codingStandardsIgnoreLine
}

/**
 * Find all possible combinations of values from the input array and return in a logical order.
 *
 * @since  1.0.0
 * @param  array $input Input.
 * @return array
 */
function evf_array_cartesian($input)
{
	$input   = array_filter($input);
	$results = array();
	$indexes = array();
	$index   = 0;

	// Generate indexes from keys and values so we have a logical sort order.
	foreach ($input as $key => $values) {
		foreach ($values as $value) {
			$indexes[$key][$value] = $index++;
		}
	}

	// Loop over the 2D array of indexes and generate all combinations.
	foreach ($indexes as $key => $values) {
		// When result is empty, fill with the values of the first looped array.
		if (empty($results)) {
			foreach ($values as $value) {
				$results[] = array($key => $value);
			}
		} else {
			// Second and subsequent input sub-array merging.
			foreach ($results as $result_key => $result) {
				foreach ($values as $value) {
					// If the key is not set, we can set it.
					if (! isset($results[$result_key][$key])) {
						$results[$result_key][$key] = $value;
					} else {
						// If the key is set, we can add a new combination to the results array.
						$new_combination         = $results[$result_key];
						$new_combination[$key] = $value;
						$results[]               = $new_combination;
					}
				}
			}
		}
	}

	// Sort the indexes.
	arsort($results);

	// Convert indexes back to values.
	foreach ($results as $result_key => $result) {
		$converted_values = array();

		// Sort the values.
		arsort($results[$result_key]);

		// Convert the values.
		foreach ($results[$result_key] as $key => $value) {
			$converted_values[$key] = array_search($value, $indexes[$key], true);
		}

		$results[$result_key] = $converted_values;
	}

	return $results;
}

/**
 * Run a MySQL transaction query, if supported.
 *
 * @since 1.0.0
 * @param string $type Types: start (default), commit, rollback.
 * @param bool   $force use of transactions.
 */
function evf_transaction_query($type = 'start', $force = false)
{
	global $wpdb;

	$wpdb->hide_errors();

	evf_maybe_define_constant('EVF_USE_TRANSACTIONS', true);

	if (EVF_USE_TRANSACTIONS || $force) {
		switch ($type) {
			case 'commit':
				$wpdb->query('COMMIT');
				break;
			case 'rollback':
				$wpdb->query('ROLLBACK');
				break;
			default:
				$wpdb->query('START TRANSACTION');
				break;
		}
	}
}

/**
 * Outputs a "back" link so admin screens can easily jump back a page.
 *
 * @param string $label Title of the page to return to.
 * @param string $url   URL of the page to return to.
 */
function evf_back_link($label, $url)
{
	echo '<small class="evf-admin-breadcrumb"><a href="' . esc_url($url) . '" aria-label="' . esc_attr($label) . '">&#x2934;</a></small>';
}

/**
 * Display a EverestForms help tip.
 *
 * @since  1.0.0
 *
 * @param  string $tip        Help tip text.
 * @param  bool   $allow_html Allow sanitized HTML if true or escape.
 * @return string
 */
function evf_help_tip($tip, $allow_html = false)
{
	if ($allow_html) {
		$tip = evf_sanitize_tooltip($tip);
	} else {
		$tip = esc_attr($tip);
	}

	return '<span class="everest-forms-help-tip" data-tip="' . $tip . '"></span>';
}

/**
 * Wrapper for set_time_limit to see if it is enabled.
 *
 * @since 1.0.0
 * @param int $limit Time limit.
 */
function evf_set_time_limit($limit = 0)
{
	if (function_exists('set_time_limit') && false === strpos(ini_get('disable_functions'), 'set_time_limit') && ! ini_get('safe_mode')) { // phpcs:ignore PHPCompatibility.IniDirectives.RemovedIniDirectives.safe_modeDeprecatedRemoved
		@set_time_limit($limit); // @codingStandardsIgnoreLine
	}
}

/**
 * Wrapper for nocache_headers which also disables page caching.
 *
 * @since 1.2.0
 */
function evf_nocache_headers()
{
	EVF_Cache_Helper::set_nocache_constants();
	nocache_headers();
}

/**
 * Get a shared logger instance.
 *
 * Use the everest_forms_logging_class filter to change the logging class. You may provide one of the following:
 *     - a class name which will be instantiated as `new $class` with no arguments
 *     - an instance which will be used directly as the logger
 * In either case, the class or instance *must* implement EVF_Logger_Interface.
 *
 * @see EVF_Logger_Interface
 *
 * @return EVF_Logger
 */
function evf_get_logger()
{
	static $logger = null;

	$class = apply_filters('everest_forms_logging_class', 'EVF_Logger');

	if (null !== $logger && is_string($class) && is_a($logger, $class)) {
		return $logger;
	}

	$implements = class_implements($class);

	if (is_array($implements) && in_array('EVF_Logger_Interface', $implements, true)) {
		$logger = is_object($class) ? $class : new $class();
	} else {
		evf_doing_it_wrong(
			__FUNCTION__,
			sprintf(
				/* translators: 1: class name 2: everest_forms_logging_class 3: EVF_Logger_Interface */
				__('The class %1$s provided by %2$s filter must implement %3$s.', 'everest-forms'),
				'<code>' . esc_html(is_object($class) ? get_class($class) : $class) . '</code>',
				'<code>everest_forms_logging_class</code>',
				'<code>EVF_Logger_Interface</code>'
			),
			'1.2'
		);
		$logger = is_a($logger, 'EVF_Logger') ? $logger : new EVF_Logger();
	}

	return $logger;
}

/**
 * Prints human-readable information about a variable.
 *
 * Some server environments blacklist some debugging functions. This function provides a safe way to
 * turn an expression into a printable, readable form without calling blacklisted functions.
 *
 * @since 1.0.0
 *
 * @param mixed $expression The expression to be printed.
 * @param bool  $return     Optional. Default false. Set to true to return the human-readable string.
 *
 * @return string|bool False if expression could not be printed. True if the expression was printed.
 *     If $return is true, a string representation will be returned.
 */
function evf_print_r($expression, $return = false)
{
	$alternatives = array(
		array(
			'func' => 'print_r',
			'args' => array($expression, true),
		),
		array(
			'func' => 'var_export',
			'args' => array($expression, true),
		),
		array(
			'func' => 'json_encode',
			'args' => array($expression),
		),
		array(
			'func' => 'serialize',
			'args' => array($expression),
		),
	);

	$alternatives = apply_filters('everest_forms_print_r_alternatives', $alternatives, $expression);

	foreach ($alternatives as $alternative) {
		if (function_exists($alternative['func'])) {
			$res = call_user_func_array($alternative['func'], $alternative['args']);
			if ($return) {
				return $res;
			}

			echo wp_kses_post($res);
			return true;
		}
	}

	return false;
}

/**
 * Registers the default log handler.
 *
 * @since  1.0.0
 * @param  array $handlers Handlers.
 * @return array
 */
function evf_register_default_log_handler($handlers)
{
	if (defined('EVF_LOG_HANDLER') && class_exists(EVF_LOG_HANDLER)) {
		$handler_class   = EVF_LOG_HANDLER;
		$default_handler = new $handler_class();
	} else {
		$default_handler = new EVF_Log_Handler_File();
	}

	array_push($handlers, $default_handler);

	return $handlers;
}

add_filter('everest_forms_register_log_handlers', 'evf_register_default_log_handler');

/**
 * Based on wp_list_pluck, this calls a method instead of returning a property.
 *
 * @since 1.0.0
 * @param array      $list              List of objects or arrays.
 * @param int|string $callback_or_field Callback method from the object to place instead of the entire object.
 * @param int|string $index_key         Optional. Field from the object to use as keys for the new array.
 *                                      Default null.
 * @return array Array of values.
 */
function evf_list_pluck($list, $callback_or_field, $index_key = null)
{
	// Use wp_list_pluck if this isn't a callback.
	$first_el = current($list);
	if (! is_object($first_el) || ! is_callable(array($first_el, $callback_or_field))) {
		return wp_list_pluck($list, $callback_or_field, $index_key);
	}
	if (! $index_key) {
		/*
		 * This is simple. Could at some point wrap array_column()
		 * if we knew we had an array of arrays.
		 */
		foreach ($list as $key => $value) {
			$list[$key] = $value->{$callback_or_field}();
		}
		return $list;
	}

	/*
	 * When index_key is not set for a particular item, push the value
	 * to the end of the stack. This is how array_column() behaves.
	 */
	$newlist = array();
	foreach ($list as $value) {
		// Get index.
		if (is_callable(array($value, $index_key))) {
			$newlist[$value->{$index_key}()] = $value->{$callback_or_field}();
		} elseif (isset($value->$index_key)) {
			$newlist[$value->$index_key] = $value->{$callback_or_field}();
		} else {
			$newlist[] = $value->{$callback_or_field}();
		}
	}
	return $newlist;
}

/**
 * Switch EverestForms to site language.
 *
 * @since 1.0.0
 */
function evf_switch_to_site_locale()
{
	if (function_exists('switch_to_locale')) {
		switch_to_locale(get_locale());

		// Filter on plugin_locale so load_plugin_textdomain loads the correct locale.
		add_filter('plugin_locale', 'get_locale');

		// Init EVF locale.
		evf()->load_plugin_textdomain();
	}
}

/**
 * Switch EverestForms language to original.
 *
 * @since 1.0.0
 */
function evf_restore_locale()
{
	if (function_exists('restore_previous_locale')) {
		restore_previous_locale();

		// Remove filter.
		remove_filter('plugin_locale', 'get_locale');

		// Init EVF locale.
		evf()->load_plugin_textdomain();
	}
}

/**
 * Get an item of post data if set, otherwise return a default value.
 *
 * @since  1.0.0
 * @param  string $key     Key.
 * @param  string $default Default.
 * @return mixed value sanitized by evf_clean
 */
function evf_get_post_data_by_key($key, $default = '')
{
	return evf_clean(evf_get_var($_POST[$key], $default)); // @codingStandardsIgnoreLine
}

/**
 * Get data if set, otherwise return a default value or null. Prevents notices when data is not set.
 *
 * @since  1.0.0
 * @param  mixed  $var     Variable.
 * @param  string $default Default value.
 * @return mixed
 */
function evf_get_var(&$var, $default = null)
{
	return isset($var) ? $var : $default;
}

/**
 * Read in EverestForms headers when reading plugin headers.
 *
 * @since  1.2.0
 * @param  array $headers Headers.
 * @return array
 */
function evf_enable_evf_plugin_headers($headers)
{
	if (! class_exists('EVF_Plugin_Updates')) {
		include_once __DIR__ . '/admin/plugin-updates/class-evf-plugin-updates.php';
	}

	// EVF requires at least - allows developers to define which version of Everest Forms the plugin requires to run.
	$headers[] = EVF_Plugin_Updates::VERSION_REQUIRED_HEADER;

	// EVF tested up to - allows developers to define which version of Everest Forms they have tested up to.
	$headers[] = EVF_Plugin_Updates::VERSION_TESTED_HEADER;

	return $headers;
}
add_filter('extra_theme_headers', 'evf_enable_evf_plugin_headers');
add_filter('extra_plugin_headers', 'evf_enable_evf_plugin_headers');

/**
 * Delete expired transients.
 *
 * Deletes all expired transients. The multi-table delete syntax is used.
 * to delete the transient record from table a, and the corresponding.
 * transient_timeout record from table b.
 *
 * Based on code inside core's upgrade_network() function.
 *
 * @since  1.0.0
 * @return int Number of transients that were cleared.
 */
function evf_delete_expired_transients()
{
	global $wpdb;

	$sql  = "DELETE a, b FROM $wpdb->options a, $wpdb->options b
		WHERE a.option_name LIKE %s
		AND a.option_name NOT LIKE %s
		AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
		AND b.option_value < %d";
	$rows = $wpdb->query($wpdb->prepare($sql, $wpdb->esc_like('_transient_') . '%', $wpdb->esc_like('_transient_timeout_') . '%', time())); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	$sql   = "DELETE a, b FROM $wpdb->options a, $wpdb->options b
		WHERE a.option_name LIKE %s
		AND a.option_name NOT LIKE %s
		AND b.option_name = CONCAT( '_site_transient_timeout_', SUBSTRING( a.option_name, 17 ) )
		AND b.option_value < %d";
	$rows2 = $wpdb->query($wpdb->prepare($sql, $wpdb->esc_like('_site_transient_') . '%', $wpdb->esc_like('_site_transient_timeout_') . '%', time())); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	return absint($rows + $rows2);
}
add_action('everest_forms_installed', 'evf_delete_expired_transients');

/**
 * Make a URL relative, if possible.
 *
 * @since  1.0.0
 * @param  string $url URL to make relative.
 * @return string
 */
function evf_get_relative_url($url)
{
	return evf_is_external_resource($url) ? $url : str_replace(array('http://', 'https://'), '//', $url);
}

/**
 * See if a resource is remote.
 *
 * @since  1.0.0
 * @param  string $url URL to check.
 * @return bool
 */
function evf_is_external_resource($url)
{
	$wp_base = str_replace(array('http://', 'https://'), '//', get_home_url(null, '/', 'http'));
	return strstr($url, '://') && strstr($wp_base, $url);
}

/**
 * See if theme/s is activate or not.
 *
 * @since  1.0.0
 * @param  string|array $theme Theme name or array of theme names to check.
 * @return boolean
 */
function evf_is_active_theme($theme)
{
	return is_array($theme) ? in_array(get_template(), $theme, true) : get_template() === $theme;
}

/**
 * Cleans up session data - cron callback.
 *
 * @since 1.0.0
 */
function evf_cleanup_session_data()
{
	$session_class = apply_filters('everest_forms_session_handler', 'EVF_Session_Handler');
	$session       = new $session_class();

	if (is_callable(array($session, 'cleanup_sessions'))) {
		$session->cleanup_sessions();
	}
}
add_action('everest_forms_cleanup_sessions', 'evf_cleanup_session_data');

/**
 * Return the html selected attribute if stringified $value is found in array of stringified $options
 * or if stringified $value is the same as scalar stringified $options.
 *
 * @param string|int       $value   Value to find within options.
 * @param string|int|array $options Options to go through when looking for value.
 * @return string
 */
function evf_selected($value, $options)
{
	if (is_array($options)) {
		$options = array_map('strval', $options);
		return selected(in_array((string) $value, $options, true), true, false);
	}

	return selected($value, $options, false);
}

/**
 * Retrieve actual fields from a form.
 *
 * Non-posting elements such as section divider, page break, and HTML are
 * automatically excluded. Optionally a white list can be provided.
 *
 * @since 1.0.0
 *
 * @param mixed $form Form data.
 * @param array $whitelist Whitelist args.
 *
 * @return mixed boolean or array
 */
function evf_get_form_fields($form = false, $whitelist = array())
{
	// Accept form (post) object or form ID.
	if (is_object($form)) {
		$form = json_decode($form->post_content);
	} elseif (is_numeric($form)) {
		$form = evf()->form->get(
			$form,
			array(
				'content_only' => true,
			)
		);
	}

	if (! is_array($form) || empty($form['form_fields'])) {
		return false;
	}

	// White list of field types to allow.
	$allowed_form_fields = array(
		'first-name',
		'last-name',
		'text',
		'textarea',
		'select',
		'radio',
		'checkbox',
		'email',
		'address',
		'country',
		'url',
		'name',
		'hidden',
		'date',
		'phone',
		'number',
		'file-upload',
		'image-upload',
		'payment-single',
		'payment-multiple',
		'payment-checkbox',
		'payment-total',
	);
	$allowed_form_fields = apply_filters('everest_forms_allowed_form_fields', $allowed_form_fields);

	$whitelist = ! empty($whitelist) ? $whitelist : $allowed_form_fields;

	$form_fields = $form['form_fields'];

	foreach ($form_fields as $id => $form_field) {
		if (! in_array($form_field['type'], $whitelist, true)) {
			unset($form_fields[$id]);
		}
	}

	return $form_fields;
}

/**
 * Sanitize a string, that can be a multiline.
 * If WP core `sanitize_textarea_field()` exists (after 4.7.0) - use it.
 * Otherwise - split onto separate lines, sanitize each one, merge again.
 *
 * @since 1.4.1
 *
 * @param string $string Raw string to sanitize.
 *
 * @return string If empty var is passed, or not a string - return unmodified. Otherwise - sanitize.
 */
function evf_sanitize_textarea_field($string)
{
	if (empty($string) || ! is_string($string)) {
		return $string;
	}

	if (function_exists('sanitize_textarea_field')) {
		$string = sanitize_textarea_field($string);
	} else {
		$string = implode("\n", array_map('sanitize_text_field', explode("\n", $string)));
	}

	return $string;
}

/**
 * Formats, sanitizes, and returns/echos HTML element ID, classes, attributes,
 * and data attributes.
 *
 * @param string $id    Element ID.
 * @param array  $class Class args.
 * @param array  $datas Data args.
 * @param array  $atts  Attributes.
 * @param bool   $echo  True to echo else return.
 *
 * @return string
 */
function evf_html_attributes($id = '', $class = array(), $datas = array(), $atts = array(), $echo = false)
{
	$id    = trim($id);
	$parts = array();

	$is_edit_entry = isset($_GET['edit-entry']) && ! sanitize_text_field(wp_unslash(empty($_GET['edit-entry']))) ? true : false;

	if (! empty($id)) {
		$id = sanitize_html_class($id);
		if (! empty($id)) {
			$parts[] = 'id="' . $id . '"';
		}
	}

	if (! empty($class)) {
		$class = evf_sanitize_classes($class, true);
		if (! empty($class)) {
			// While editing hidden field should be visible.
			if ($is_edit_entry) {
				$class = str_replace('evf-field-hidden', '', $class);
			}
			$parts[] = 'class="' . $class . '"';
		}
	}

	if (! empty($datas)) {
		foreach ($datas as $data => $val) {
			$parts[] = 'data-' . sanitize_html_class($data) . '="' . esc_attr($val) . '"';
		}
	}

	if (! empty($atts)) {
		foreach ($atts as $att => $val) {
			if ('0' === $val || ! empty($val)) {
				if ($att[0] === '[') { //phpcs:ignore
					// Handle special case for bound attributes in AMP.
					$escaped_att = '[' . sanitize_html_class(trim($att, '[]')) . ']';
				} else {
					$escaped_att = sanitize_html_class($att);
				}
				// While editing, fields must be visible even if they are hidden.
				if ('style' == $escaped_att && $is_edit_entry) {
					$parts[] = 'style = "display: block"';
				} else {
					$parts[] = $escaped_att . '="' . esc_attr($val) . '"';
				}
			}
		}
	}

	$output = implode(' ', $parts);

	if ($echo) {
		echo esc_html(trim($output));
	} else {
		return trim($output);
	}
}

/**
 * Sanitize string of CSS classes.
 *
 * @param array|string $classes Class names.
 * @param bool         $convert True will convert strings to array and vice versa.
 *
 * @return string|array
 */
function evf_sanitize_classes($classes, $convert = false)
{
	$css   = array();
	$array = is_array($classes);

	if (! empty($classes)) {
		if (! $array) {
			$classes = explode(' ', trim($classes));
		}
		foreach ($classes as $class) {
			$css[] = sanitize_html_class($class);
		}
	}

	if ($array) {
		return $convert ? implode(' ', $css) : $css;
	} else {
		return $convert ? $css : implode(' ', $css);
	}
}

/**
 * Performs json_decode and unslash.
 *
 * @since 1.0.0
 *
 * @param string $data Data to decode.
 *
 * @return array|bool
 */
function evf_decode($data)
{
	if (! $data || empty($data)) {
		return false;
	}

	return json_decode($data, true);
}

/**
 * Performs json_encode and wp_slash.
 *
 * @since 1.0.0
 *
 * @param mixed $data Data to encode.
 *
 * @return string
 */
function evf_encode($data = false)
{
	if (empty($data)) {
		return false;
	}

	return wp_slash(wp_json_encode($data));
}

/**
 * Crypto rand secure.
 *
 * @param int $min Min value.
 * @param int $max Max value.
 *
 * @return mixed
 */
function evf_crypto_rand_secure($min, $max)
{
	$range = $max - $min;
	if ($range < 1) {
		return $min;
	} // not so random...
	$log    = ceil(log($range, 2));
	$bytes  = (int) ($log / 8) + 1; // Length in bytes.
	$bits   = (int) $log + 1; // Length in bits.
	$filter = (int) (1 << $bits) - 1; // Set all lower bits to 1.
	do {
		$rnd = hexdec(bin2hex(openssl_random_pseudo_bytes($bytes)));
		$rnd = $rnd & $filter; // Discard irrelevant bits.
	} while ($rnd > $range);

	return $min + $rnd;
}

/**
 * Generate random string.
 *
 * @param int $length Length of string.
 *
 * @return string
 */
function evf_get_random_string($length = 10)
{
	$string         = '';
	$code_alphabet  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
	$code_alphabet .= 'abcdefghijklmnopqrstuvwxyz';
	$code_alphabet .= '0123456789';
	$max            = strlen($code_alphabet);
	for ($i = 0; $i < $length; $i++) {
		$string .= $code_alphabet[evf_crypto_rand_secure(0, $max - 1)];
	}

	return $string;
}

/**
 * Get all forms.
 *
 * @param  bool $skip_disabled_entries True to skip disabled entries.
 * @param bool $check_disable_storing_entry_info Check disable storing entry.
 * @return array of form data.
 */
function evf_get_all_forms( $skip_disabled_entries = false, $check_disable_storing_entry_info = true, $is_zapier_request = false ) {
	if ( is_null( evf()->form ) ) {
		return array();
	}
	$forms    = array();
	$form_ids = wp_parse_id_list(
		evf()->form->get_multiple(
			array(
				'fields'      => 'ids',
				'status'      => 'publish',
				'order'       => 'DESC',
				'numberposts' => -1, // @codingStandardsIgnoreLine
			)
		)
	);

	if (! empty($form_ids)) {
		foreach ($form_ids as $form_id) {
			$form      = evf()->form->get($form_id);
			$entries   = evf_get_entries_ids($form_id);
			$form_data = ! empty($form->post_content) ? evf_decode($form->post_content) : '';

			if (! $form || ($skip_disabled_entries && count($entries) < 1) && (isset($form_data['settings']['disabled_entries']) && '1' === $form_data['settings']['disabled_entries'])) {
				if (! $form || $check_disable_storing_entry_info) {
					continue;
				}
			}

			// Check permissions for forms with viewable.
			if ( current_user_can( 'everest_forms_view_form_entries', $form_id ) || $is_zapier_request ) {
				$forms[ $form_id ] = $form->post_title;
			}
		}
	}

	return $forms;
}

/**
 * Get random meta-key for field option.
 *
 * @param  array $field Field data array.
 * @return string
 */
function evf_get_meta_key_field_option($field)
{
	if (isset($field['type']) && isset($field['label'])) {
		switch ($field['type']) {
			case 'select':
				$field['label'] = 'Dropdown';
				break;
			case 'radio':
				$field['label'] = 'Multiple Choice';
				break;
			case 'checkbox':
				$field['label'] = 'Checkboxes';
				break;
		}
	}
	return str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9\s`_]/', '', strtolower($field['label']))) . '_' . rand(pow(10, 3), pow(10, 4) - 1); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_rand.
}

/**
 * Get current user IP Address.
 *
 * @return string
 */
function evf_get_ip_address() {
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) { // WPCS: input var ok, CSRF ok.
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ); // WPCS: input var ok, CSRF ok.
	}

	if ( isset( $_SERVER['HTTP_TRUE_CLIENT_IP'] ) ) { // WPCS: input var ok, CSRF ok.
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_TRUE_CLIENT_IP'] ) ); // WPCS: input var ok, CSRF ok.
	}

	if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) { // WPCS: input var ok, CSRF ok.
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ); // WPCS: input var ok, CSRF ok.
	}

	if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) { // WPCS: input var ok, CSRF ok.
		// Proxy servers can send through this header like this: X-Forwarded-For: client1, proxy1, proxy2
		// Make sure we always only send through the first IP in the list which should always be the client IP.
		$ip = trim( current( preg_split( '/[,:]/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) ) );
		$validated_ip = rest_is_ip_address( $ip );
		if ( $validated_ip ) {
			return (string) $validated_ip; // WPCS: input var ok, CSRF ok.
		}
	}

	if ( isset( $_SERVER['REMOTE_ADDR'] ) ) { // @codingStandardsIgnoreLine
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // @codingStandardsIgnoreLine
	}
	return '';
}

/**
 * Get User Agent browser and OS type
 *
 * @since  1.1.0
 * @return array
 */
function evf_get_browser()
{
	$u_agent  = ! empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))) : '';
	$bname    = 'Unknown';
	$platform = 'Unknown';
	$version  = '';

	// First get the platform.
	if (preg_match('/linux/i', $u_agent)) {
		$platform = 'Linux';
	} elseif (preg_match('/macintosh|mac os x/i', $u_agent)) {
		$platform = 'MAC OS';
	} elseif (preg_match('/windows|win32/i', $u_agent)) {
		$platform = 'Windows';
	}

	// Next get the name of the useragent yes seperately and for good reason.
	if (preg_match('/MSIE/i', $u_agent) && ! preg_match('/Opera/i', $u_agent)) {
		$bname = 'Internet Explorer';
		$ub    = 'MSIE';
	} elseif (preg_match('/Trident/i', $u_agent)) {
		// this condition is for IE11.
		$bname = 'Internet Explorer';
		$ub    = 'rv';
	} elseif (preg_match('/Firefox/i', $u_agent)) {
		$bname = 'Mozilla Firefox';
		$ub    = 'Firefox';
	} elseif (preg_match('/Chrome/i', $u_agent)) {
		$bname = 'Google Chrome';
		$ub    = 'Chrome';
	} elseif (preg_match('/Safari/i', $u_agent)) {
		$bname = 'Apple Safari';
		$ub    = 'Safari';
	} elseif (preg_match('/Opera/i', $u_agent)) {
		$bname = 'Opera';
		$ub    = 'Opera';
	} elseif (preg_match('/Netscape/i', $u_agent)) {
		$bname = 'Netscape';
		$ub    = 'Netscape';
	}

	// Finally get the correct version number.
	// Added "|:".
	$known   = array('Version', $ub, 'other');
	$pattern = '#(?<browser>' . join('|', $known) . ')[/|: ]+(?<version>[0-9.|a-zA-Z.]*)#';
	if (! preg_match_all($pattern, $u_agent, $matches)) { // @codingStandardsIgnoreLine
		// We have no matching number just continue.
	}

	// See how many we have.
	$i = count($matches['browser']);

	if (1 !== $i) {
		// we will have two since we are not using 'other' argument yet.
		// see if version is before or after the name.
		if (strripos($u_agent, 'Version') < strripos($u_agent, $ub)) {
			$version = $matches['version'][0];
		} else {
			$version = $matches['version'][1];
		}
	} else {
		$version = $matches['version'][0];
	}

	// Check if we have a number.
	if (null === $version || '' === $version) {
		$version = '';
	}

	return array(
		'userAgent' => $u_agent,
		'name'      => $bname,
		'version'   => $version,
		'platform'  => $platform,
		'pattern'   => $pattern,
	);
}

/**
 * Get the certain date of a specified day in a specified format.
 *
 * @since 1.1.0
 *
 * @param string $period Supported values: start, end.
 * @param string $timestamp Default is the current timestamp, if left empty.
 * @param string $format Default is a MySQL format.
 *
 * @return string
 */
function evf_get_day_period_date($period, $timestamp = '', $format = 'Y-m-d H:i:s')
{
	$date = '';

	if (empty($timestamp)) {
		$timestamp = time();
	}

	switch ($period) {
		case 'start_of_day':
			$date = date($format, strtotime('today', $timestamp)); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			break;

		case 'end_of_day':
			$date = date($format, strtotime('tomorrow', $timestamp) - 1); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			break;
	}

	return $date;
}

/**
 * Get field label by meta key
 *
 * @param int    $form_id  Form ID.
 * @param string $meta_key Field's meta key.
 * @param array  $fields Entry Field Data.
 *
 * @return string|false True if field label exists in form.
 */
function evf_get_form_data_by_meta_key($form_id, $meta_key, $fields = array())
{
	$get_post     = get_post($form_id);
	$post_content = json_decode($get_post->post_content, true);
	$form_fields  = isset($post_content['form_fields']) ? $post_content['form_fields'] : array();

	if (! empty($form_fields)) {
		foreach ($form_fields as $field) {
			if (isset($field['meta-key']) && $meta_key === $field['meta-key']) {
				return $field['label'];
			}
		}
	}

	if (! empty($fields)) {
		foreach ($fields as $field) {
			if (isset($field->meta_key) && $meta_key === $field->meta_key) {
				return isset($field->name) ? $field->name : $field->value->name;
			}
		}
	}

	return false;
}

/**
 * Get field type by meta key
 *
 * @param int    $form_id  Form ID.
 * @param string $meta_key Field's meta key.
 *
 * @return string|false True if field type exists in form.
 */
function evf_get_field_type_by_meta_key($form_id, $meta_key)
{
	$get_post     = get_post($form_id);
	$post_content = json_decode($get_post->post_content, true);
	$form_fields  = isset($post_content['form_fields']) ? $post_content['form_fields'] : array();

	if (! empty($form_fields)) {
		foreach ($form_fields as $field) {
			if (isset($field['meta-key']) && $meta_key === $field['meta-key']) {
				return $field['type'];
			}
		}
	}

	return false;
}

/**
 * Get all the email fields of a Form.
 *
 * @param int $form_id  Form ID.
 */
function evf_get_all_email_fields_by_form_id($form_id)
{
	$user_emails = array();
	$form_obj    = evf()->form->get($form_id);
	$form_data   = ! empty($form_obj->post_content) ? evf_decode($form_obj->post_content) : '';

	if (! empty($form_data['form_fields'])) {
		foreach ($form_data['form_fields'] as $form_fields) {
			if ('email' === $form_fields['type']) {
				$user_emails[$form_fields['meta-key']] = $form_fields['label'];
			}
		}
	}

	return $user_emails;
}

/**
 * Get all the field's meta-key label pair.
 *
 * @param int $form_id  Form ID.
 * @return array
 */
function evf_get_all_form_fields_by_form_id($form_id)
{
	$data      = array();
	$form_obj  = evf()->form->get($form_id);
	$form_data = ! empty($form_obj->post_content) ? evf_decode($form_obj->post_content) : '';

	if (! empty($form_data['form_fields'])) {
		foreach ($form_data['form_fields'] as $form_fields) {
			if (isset($form_fields['meta-key'], $form_fields['label'])) {
				$data[$form_fields['meta-key']] = $form_fields['label'];
			}
		}
	}

	return $data;
}

/**
 * Check if the string JSON.
 *
 * @param string $string String to check.
 * @return bool
 */
function evf_isJson($string)
{
	json_decode($string);
	return (json_last_error() == JSON_ERROR_NONE); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
}

/**
 * Checks whether the content passed contains a specific short code.
 *
 * @since  1.1.4
 * @param  string $tag Shortcode tag to check.
 * @return bool
 */
function evf_post_content_has_shortcode($tag = '')
{
	global $post;

	return is_singular() && is_a($post, 'WP_Post') && has_shortcode($post->post_content, $tag);
}

/**
 * Convert a file size provided, such as "2M", to bytes.
 *
 * @since 1.2.0
 * @link http://stackoverflow.com/a/22500394
 *
 * @param string $size Size to convert to bytes.
 *
 * @return int
 */
function evf_size_to_bytes($size)
{
	if (is_numeric($size)) {
		return $size;
	}

	$suffix = substr($size, -1);
	$value  = substr($size, 0, -1);

	// @codingStandardsIgnoreStart
	switch (strtoupper($suffix)) {
		case 'P':
			$value *= 1024;
		case 'T':
			$value *= 1024;
		case 'G':
			$value *= 1024;
		case 'M':
			$value *= 1024;
		case 'K':
			$value *= 1024;
			break;
	}
	// @codingStandardsIgnoreEnd

	return $value;
}

/**
 * Convert bytes to megabytes (or in some cases KB).
 *
 * @since 1.2.0
 *
 * @param int $bytes Bytes to convert to a readable format.
 *
 * @return string
 */
function evf_size_to_megabytes($bytes)
{
	if ($bytes < 1048676) {
		return number_format($bytes / 1024, 1) . ' KB';
	} else {
		return round((float) number_format($bytes / 1048576, 1)) . ' MB';
	}
}

/**
 * Convert a file size provided, such as "2M", to bytes.
 *
 * @since 1.2.0
 * @link http://stackoverflow.com/a/22500394
 *
 * @param  bool $bytes Whether to convert Bytes to a readable format.
 * @return mixed
 */
function evf_max_upload($bytes = false)
{
	$max = wp_max_upload_size();

	if ($bytes) {
		return $max;
	} else {
		return evf_size_to_megabytes($max);
	}
}

/**
 * Get the required label text, with a filter.
 *
 * @since  1.2.0
 * @return string
 */
function evf_get_required_label()
{
	return apply_filters('everest_forms_required_label', esc_html__('This field is required.', 'everest-forms'));
}

/**
 * Get a PRO license plan.
 *
 * @since  1.2.0
 * @return bool|string Plan on success, false on failure.
 */
function evf_get_license_plan() {
	if (
		( defined( 'REST_REQUEST' ) && REST_REQUEST ) ||
		( defined( 'DOING_CRON' )   && DOING_CRON   ) ||
		( defined( 'DOING_AJAX' )   && DOING_AJAX   )
	) {
		return evf_handle_license_plan_compatibility(
			get_option( 'evf_saved_license_plan', 'personal' )
		);
	}

	$license_key = get_option( 'everest-forms-pro_license_key' );

	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! $license_key || ! is_plugin_active( 'everest-forms-pro/everest-forms-pro.php' ) ) {
		return false;
	}

	$license_data = get_transient( 'evf_pro_license_plan' );

	if ( false !== $license_data ) {
		$license_plan = isset( $license_data->item_plan )
			? $license_data->item_plan
			: get_option( 'evf_saved_license_plan', 'personal' );

		return evf_handle_license_plan_compatibility( $license_plan );
	}

	$license_response = EVF_Updater_Key_API::check( array( 'license' => $license_key ) );

	if ( ! $license_response ) {
		$saved_plan = get_option( 'evf_saved_license_plan', 'personal' );
		set_transient( 'evf_pro_license_plan', (object) array( 'item_plan' => $saved_plan ), HOUR_IN_SECONDS );

		return evf_handle_license_plan_compatibility( $saved_plan );
	}

	$license_data = json_decode( $license_response );

	if ( empty( $license_data ) || ! is_object( $license_data ) ) {
		$saved_plan = get_option( 'evf_saved_license_plan', 'personal' );
		set_transient( 'evf_pro_license_plan', (object) array( 'item_plan' => $saved_plan ), HOUR_IN_SECONDS );

		return evf_handle_license_plan_compatibility( $saved_plan );
	}

	if ( ! empty( $license_data->item_name ) ) {
		$item_plan = trim( str_replace(
			array( 'everest forms', 'lifetime', '-lifetime' ),
			'',
			strtolower( $license_data->item_name )
		) );

		$license_data->item_plan = $item_plan;

		update_option( 'evf_saved_license_plan', $item_plan );
		set_transient( 'evf_pro_license_plan', $license_data, WEEK_IN_SECONDS );
	}

	$license_plan = isset( $license_data->item_plan )
		? $license_data->item_plan
		: get_option( 'evf_saved_license_plan', 'personal' );

	return evf_handle_license_plan_compatibility( $license_plan );
}

add_action('admin_init', 'evf_handle_force_update');

if (! function_exists('evf_handle_force_update')) {

	/**
	 * Delete our plugins addon updater transient during force update.
	 */
	function evf_handle_force_update()
	{
		global $pagenow;

		if ('update-core.php' === $pagenow && (isset($_GET['force-check'])) && ('1' === $_GET['force-check'])) {
			delete_transient('evf_pro_license_plan');
		}
	}
}


/** To handle the backward compatibility for those user who is still using the plus and professional plan license key.
 *
 * @since 3.0.0
 * @param $license_plan License plan.
 */
function evf_handle_license_plan_compatibility($license_plan)
{
	$license_plan = ('plus' === $license_plan || 'professional' === $license_plan || 'unknown' === $license_plan) ? 'personal' : $license_plan;
	return $license_plan;
}

/**
 * Decode special characters, both alpha- (<) and numeric-based (').
 *
 * @since 1.2.0
 *
 * @param string $string Raw string to decode.
 *
 * @return string
 */
function evf_decode_string($string)
{
	if (! is_string($string)) {
		return $string;
	}

	return wp_kses_decode_entities(html_entity_decode($string, ENT_QUOTES));
}
add_filter('everest_forms_email_message', 'evf_decode_string');

/**
 * Get Countries.
 *
 * @since  1.2.0
 * @return array
 */
function evf_get_countries()
{
	$countries = array(
		'AF' => esc_html__('Afghanistan', 'everest-forms'),
		'AX' => esc_html__('Åland Islands', 'everest-forms'),
		'AL' => esc_html__('Albania', 'everest-forms'),
		'DZ' => esc_html__('Algeria', 'everest-forms'),
		'AS' => esc_html__('American Samoa', 'everest-forms'),
		'AD' => esc_html__('Andorra', 'everest-forms'),
		'AO' => esc_html__('Angola', 'everest-forms'),
		'AI' => esc_html__('Anguilla', 'everest-forms'),
		'AQ' => esc_html__('Antarctica', 'everest-forms'),
		'AG' => esc_html__('Antigua and Barbuda', 'everest-forms'),
		'AR' => esc_html__('Argentina', 'everest-forms'),
		'AM' => esc_html__('Armenia', 'everest-forms'),
		'AW' => esc_html__('Aruba', 'everest-forms'),
		'AU' => esc_html__('Australia', 'everest-forms'),
		'AT' => esc_html__('Austria', 'everest-forms'),
		'AZ' => esc_html__('Azerbaijan', 'everest-forms'),
		'BS' => esc_html__('Bahamas', 'everest-forms'),
		'BH' => esc_html__('Bahrain', 'everest-forms'),
		'BD' => esc_html__('Bangladesh', 'everest-forms'),
		'BB' => esc_html__('Barbados', 'everest-forms'),
		'BY' => esc_html__('Belarus', 'everest-forms'),
		'BE' => esc_html__('Belgium', 'everest-forms'),
		'PW' => esc_html__('Belau', 'everest-forms'),
		'BZ' => esc_html__('Belize', 'everest-forms'),
		'BJ' => esc_html__('Benin', 'everest-forms'),
		'BM' => esc_html__('Bermuda', 'everest-forms'),
		'BT' => esc_html__('Bhutan', 'everest-forms'),
		'BO' => esc_html__('Bolivia', 'everest-forms'),
		'BQ' => esc_html__('Bonaire, Saint Eustatius and Saba', 'everest-forms'),
		'BA' => esc_html__('Bosnia and Herzegovina', 'everest-forms'),
		'BW' => esc_html__('Botswana', 'everest-forms'),
		'BV' => esc_html__('Bouvet Island', 'everest-forms'),
		'BR' => esc_html__('Brazil', 'everest-forms'),
		'IO' => esc_html__('British Indian Ocean Territory', 'everest-forms'),
		'BN' => esc_html__('Brunei', 'everest-forms'),
		'BG' => esc_html__('Bulgaria', 'everest-forms'),
		'BF' => esc_html__('Burkina Faso', 'everest-forms'),
		'BI' => esc_html__('Burundi', 'everest-forms'),
		'KH' => esc_html__('Cambodia', 'everest-forms'),
		'CM' => esc_html__('Cameroon', 'everest-forms'),
		'CA' => esc_html__('Canada', 'everest-forms'),
		'CV' => esc_html__('Cape Verde', 'everest-forms'),
		'KY' => esc_html__('Cayman Islands', 'everest-forms'),
		'CF' => esc_html__('Central African Republic', 'everest-forms'),
		'TD' => esc_html__('Chad', 'everest-forms'),
		'CL' => esc_html__('Chile', 'everest-forms'),
		'CN' => esc_html__('China', 'everest-forms'),
		'CX' => esc_html__('Christmas Island', 'everest-forms'),
		'CC' => esc_html__('Cocos (Keeling) Islands', 'everest-forms'),
		'CO' => esc_html__('Colombia', 'everest-forms'),
		'KM' => esc_html__('Comoros', 'everest-forms'),
		'CG' => esc_html__('Congo (Brazzaville)', 'everest-forms'),
		'CD' => esc_html__('Congo (Kinshasa)', 'everest-forms'),
		'CK' => esc_html__('Cook Islands', 'everest-forms'),
		'CR' => esc_html__('Costa Rica', 'everest-forms'),
		'HR' => esc_html__('Croatia', 'everest-forms'),
		'CU' => esc_html__('Cuba', 'everest-forms'),
		'CW' => esc_html__('Cura&ccedil;ao', 'everest-forms'),
		'CY' => esc_html__('Cyprus', 'everest-forms'),
		'CZ' => esc_html__('Czech Republic', 'everest-forms'),
		'DK' => esc_html__('Denmark', 'everest-forms'),
		'DJ' => esc_html__('Djibouti', 'everest-forms'),
		'DM' => esc_html__('Dominica', 'everest-forms'),
		'DO' => esc_html__('Dominican Republic', 'everest-forms'),
		'EC' => esc_html__('Ecuador', 'everest-forms'),
		'EG' => esc_html__('Egypt', 'everest-forms'),
		'SV' => esc_html__('El Salvador', 'everest-forms'),
		'GQ' => esc_html__('Equatorial Guinea', 'everest-forms'),
		'ER' => esc_html__('Eritrea', 'everest-forms'),
		'EE' => esc_html__('Estonia', 'everest-forms'),
		'ET' => esc_html__('Ethiopia', 'everest-forms'),
		'FK' => esc_html__('Falkland Islands', 'everest-forms'),
		'FO' => esc_html__('Faroe Islands', 'everest-forms'),
		'FJ' => esc_html__('Fiji', 'everest-forms'),
		'FI' => esc_html__('Finland', 'everest-forms'),
		'FR' => esc_html__('France', 'everest-forms'),
		'GF' => esc_html__('French Guiana', 'everest-forms'),
		'PF' => esc_html__('French Polynesia', 'everest-forms'),
		'TF' => esc_html__('French Southern Territories', 'everest-forms'),
		'GA' => esc_html__('Gabon', 'everest-forms'),
		'GM' => esc_html__('Gambia', 'everest-forms'),
		'GE' => esc_html__('Georgia', 'everest-forms'),
		'DE' => esc_html__('Germany', 'everest-forms'),
		'GH' => esc_html__('Ghana', 'everest-forms'),
		'GI' => esc_html__('Gibraltar', 'everest-forms'),
		'GR' => esc_html__('Greece', 'everest-forms'),
		'GL' => esc_html__('Greenland', 'everest-forms'),
		'GD' => esc_html__('Grenada', 'everest-forms'),
		'GP' => esc_html__('Guadeloupe', 'everest-forms'),
		'GU' => esc_html__('Guam', 'everest-forms'),
		'GT' => esc_html__('Guatemala', 'everest-forms'),
		'GG' => esc_html__('Guernsey', 'everest-forms'),
		'GN' => esc_html__('Guinea', 'everest-forms'),
		'GW' => esc_html__('Guinea-Bissau', 'everest-forms'),
		'GY' => esc_html__('Guyana', 'everest-forms'),
		'HT' => esc_html__('Haiti', 'everest-forms'),
		'HM' => esc_html__('Heard Island and McDonald Islands', 'everest-forms'),
		'HN' => esc_html__('Honduras', 'everest-forms'),
		'HK' => esc_html__('Hong Kong', 'everest-forms'),
		'HU' => esc_html__('Hungary', 'everest-forms'),
		'IS' => esc_html__('Iceland', 'everest-forms'),
		'IN' => esc_html__('India', 'everest-forms'),
		'ID' => esc_html__('Indonesia', 'everest-forms'),
		'IR' => esc_html__('Iran', 'everest-forms'),
		'IQ' => esc_html__('Iraq', 'everest-forms'),
		'IE' => esc_html__('Ireland', 'everest-forms'),
		'IM' => esc_html__('Isle of Man', 'everest-forms'),
		'IL' => esc_html__('Israel', 'everest-forms'),
		'IT' => esc_html__('Italy', 'everest-forms'),
		'CI' => esc_html__('Ivory Coast', 'everest-forms'),
		'JM' => esc_html__('Jamaica', 'everest-forms'),
		'JP' => esc_html__('Japan', 'everest-forms'),
		'JE' => esc_html__('Jersey', 'everest-forms'),
		'JO' => esc_html__('Jordan', 'everest-forms'),
		'KZ' => esc_html__('Kazakhstan', 'everest-forms'),
		'KE' => esc_html__('Kenya', 'everest-forms'),
		'KI' => esc_html__('Kiribati', 'everest-forms'),
		'KW' => esc_html__('Kuwait', 'everest-forms'),
		'XK' => esc_html__('Kosovo', 'everest-forms'),
		'KG' => esc_html__('Kyrgyzstan', 'everest-forms'),
		'LA' => esc_html__('Laos', 'everest-forms'),
		'LV' => esc_html__('Latvia', 'everest-forms'),
		'LB' => esc_html__('Lebanon', 'everest-forms'),
		'LS' => esc_html__('Lesotho', 'everest-forms'),
		'LR' => esc_html__('Liberia', 'everest-forms'),
		'LY' => esc_html__('Libya', 'everest-forms'),
		'LI' => esc_html__('Liechtenstein', 'everest-forms'),
		'LT' => esc_html__('Lithuania', 'everest-forms'),
		'LU' => esc_html__('Luxembourg', 'everest-forms'),
		'MO' => esc_html__('Macao', 'everest-forms'),
		'MK' => esc_html__('North Macedonia', 'everest-forms'),
		'MG' => esc_html__('Madagascar', 'everest-forms'),
		'MW' => esc_html__('Malawi', 'everest-forms'),
		'MY' => esc_html__('Malaysia', 'everest-forms'),
		'MV' => esc_html__('Maldives', 'everest-forms'),
		'ML' => esc_html__('Mali', 'everest-forms'),
		'MT' => esc_html__('Malta', 'everest-forms'),
		'MH' => esc_html__('Marshall Islands', 'everest-forms'),
		'MQ' => esc_html__('Martinique', 'everest-forms'),
		'MR' => esc_html__('Mauritania', 'everest-forms'),
		'MU' => esc_html__('Mauritius', 'everest-forms'),
		'YT' => esc_html__('Mayotte', 'everest-forms'),
		'MX' => esc_html__('Mexico', 'everest-forms'),
		'FM' => esc_html__('Micronesia', 'everest-forms'),
		'MD' => esc_html__('Moldova', 'everest-forms'),
		'MC' => esc_html__('Monaco', 'everest-forms'),
		'MN' => esc_html__('Mongolia', 'everest-forms'),
		'ME' => esc_html__('Montenegro', 'everest-forms'),
		'MS' => esc_html__('Montserrat', 'everest-forms'),
		'MA' => esc_html__('Morocco', 'everest-forms'),
		'MZ' => esc_html__('Mozambique', 'everest-forms'),
		'MM' => esc_html__('Myanmar', 'everest-forms'),
		'NA' => esc_html__('Namibia', 'everest-forms'),
		'NR' => esc_html__('Nauru', 'everest-forms'),
		'NP' => esc_html__('Nepal', 'everest-forms'),
		'NL' => esc_html__('Netherlands', 'everest-forms'),
		'NC' => esc_html__('New Caledonia', 'everest-forms'),
		'NZ' => esc_html__('New Zealand', 'everest-forms'),
		'NI' => esc_html__('Nicaragua', 'everest-forms'),
		'NE' => esc_html__('Niger', 'everest-forms'),
		'NG' => esc_html__('Nigeria', 'everest-forms'),
		'NU' => esc_html__('Niue', 'everest-forms'),
		'NF' => esc_html__('Norfolk Island', 'everest-forms'),
		'MP' => esc_html__('Northern Mariana Islands', 'everest-forms'),
		'KP' => esc_html__('North Korea', 'everest-forms'),
		'NO' => esc_html__('Norway', 'everest-forms'),
		'OM' => esc_html__('Oman', 'everest-forms'),
		'PK' => esc_html__('Pakistan', 'everest-forms'),
		'PS' => esc_html__('Palestinian Territory', 'everest-forms'),
		'PA' => esc_html__('Panama', 'everest-forms'),
		'PG' => esc_html__('Papua New Guinea', 'everest-forms'),
		'PY' => esc_html__('Paraguay', 'everest-forms'),
		'PE' => esc_html__('Peru', 'everest-forms'),
		'PH' => esc_html__('Philippines', 'everest-forms'),
		'PN' => esc_html__('Pitcairn', 'everest-forms'),
		'PL' => esc_html__('Poland', 'everest-forms'),
		'PT' => esc_html__('Portugal', 'everest-forms'),
		'PR' => esc_html__('Puerto Rico', 'everest-forms'),
		'QA' => esc_html__('Qatar', 'everest-forms'),
		'RE' => esc_html__('Reunion', 'everest-forms'),
		'RO' => esc_html__('Romania', 'everest-forms'),
		'RU' => esc_html__('Russia', 'everest-forms'),
		'RW' => esc_html__('Rwanda', 'everest-forms'),
		'BL' => esc_html__('Saint Barth&eacute;lemy', 'everest-forms'),
		'SH' => esc_html__('Saint Helena', 'everest-forms'),
		'KN' => esc_html__('Saint Kitts and Nevis', 'everest-forms'),
		'LC' => esc_html__('Saint Lucia', 'everest-forms'),
		'MF' => esc_html__('Saint Martin (French part)', 'everest-forms'),
		'SX' => esc_html__('Saint Martin (Dutch part)', 'everest-forms'),
		'PM' => esc_html__('Saint Pierre and Miquelon', 'everest-forms'),
		'VC' => esc_html__('Saint Vincent and the Grenadines', 'everest-forms'),
		'SM' => esc_html__('San Marino', 'everest-forms'),
		'ST' => esc_html__('S&atilde;o Tom&eacute; and Pr&iacute;ncipe', 'everest-forms'),
		'SA' => esc_html__('Saudi Arabia', 'everest-forms'),
		'SN' => esc_html__('Senegal', 'everest-forms'),
		'RS' => esc_html__('Serbia', 'everest-forms'),
		'SC' => esc_html__('Seychelles', 'everest-forms'),
		'SL' => esc_html__('Sierra Leone', 'everest-forms'),
		'SG' => esc_html__('Singapore', 'everest-forms'),
		'SK' => esc_html__('Slovakia', 'everest-forms'),
		'SI' => esc_html__('Slovenia', 'everest-forms'),
		'SB' => esc_html__('Solomon Islands', 'everest-forms'),
		'SO' => esc_html__('Somalia', 'everest-forms'),
		'ZA' => esc_html__('South Africa', 'everest-forms'),
		'GS' => esc_html__('South Georgia/Sandwich Islands', 'everest-forms'),
		'KR' => esc_html__('South Korea', 'everest-forms'),
		'SS' => esc_html__('South Sudan', 'everest-forms'),
		'ES' => esc_html__('Spain', 'everest-forms'),
		'LK' => esc_html__('Sri Lanka', 'everest-forms'),
		'SD' => esc_html__('Sudan', 'everest-forms'),
		'SR' => esc_html__('Suriname', 'everest-forms'),
		'SJ' => esc_html__('Svalbard and Jan Mayen', 'everest-forms'),
		'SZ' => esc_html__('Swaziland', 'everest-forms'),
		'SE' => esc_html__('Sweden', 'everest-forms'),
		'CH' => esc_html__('Switzerland', 'everest-forms'),
		'SY' => esc_html__('Syria', 'everest-forms'),
		'TW' => esc_html__('Taiwan', 'everest-forms'),
		'TJ' => esc_html__('Tajikistan', 'everest-forms'),
		'TZ' => esc_html__('Tanzania', 'everest-forms'),
		'TH' => esc_html__('Thailand', 'everest-forms'),
		'TL' => esc_html__('Timor-Leste', 'everest-forms'),
		'TG' => esc_html__('Togo', 'everest-forms'),
		'TK' => esc_html__('Tokelau', 'everest-forms'),
		'TO' => esc_html__('Tonga', 'everest-forms'),
		'TT' => esc_html__('Trinidad and Tobago', 'everest-forms'),
		'TN' => esc_html__('Tunisia', 'everest-forms'),
		'TR' => esc_html__('Turkey', 'everest-forms'),
		'TM' => esc_html__('Turkmenistan', 'everest-forms'),
		'TC' => esc_html__('Turks and Caicos Islands', 'everest-forms'),
		'TV' => esc_html__('Tuvalu', 'everest-forms'),
		'UG' => esc_html__('Uganda', 'everest-forms'),
		'UA' => esc_html__('Ukraine', 'everest-forms'),
		'AE' => esc_html__('United Arab Emirates', 'everest-forms'),
		'GB' => esc_html__('United Kingdom (UK)', 'everest-forms'),
		'US' => esc_html__('United States (US)', 'everest-forms'),
		'UM' => esc_html__('United States (US) Minor Outlying Islands', 'everest-forms'),
		'UY' => esc_html__('Uruguay', 'everest-forms'),
		'UZ' => esc_html__('Uzbekistan', 'everest-forms'),
		'VU' => esc_html__('Vanuatu', 'everest-forms'),
		'VA' => esc_html__('Vatican', 'everest-forms'),
		'VE' => esc_html__('Venezuela', 'everest-forms'),
		'VN' => esc_html__('Vietnam', 'everest-forms'),
		'VG' => esc_html__('Virgin Islands (British)', 'everest-forms'),
		'VI' => esc_html__('Virgin Islands (US)', 'everest-forms'),
		'WF' => esc_html__('Wallis and Futuna', 'everest-forms'),
		'EH' => esc_html__('Western Sahara', 'everest-forms'),
		'WS' => esc_html__('Samoa', 'everest-forms'),
		'YE' => esc_html__('Yemen', 'everest-forms'),
		'ZM' => esc_html__('Zambia', 'everest-forms'),
		'ZW' => esc_html__('Zimbabwe', 'everest-forms'),
	);

	return (array) apply_filters('everest_forms_countries', $countries);
}

/**
 * Get U.S. States.
 *
 * @since  1.7.0
 * @return array
 */
function evf_get_states()
{
	$states = array(
		'AF' => array(),
		'AL' => array( // Albanian states.
			'AL-01' => __('Berat', 'everest-forms'),
			'AL-09' => __('Dibër', 'everest-forms'),
			'AL-02' => __('Durrës', 'everest-forms'),
			'AL-03' => __('Elbasan', 'everest-forms'),
			'AL-04' => __('Fier', 'everest-forms'),
			'AL-05' => __('Gjirokastër', 'everest-forms'),
			'AL-06' => __('Korçë', 'everest-forms'),
			'AL-07' => __('Kukës', 'everest-forms'),
			'AL-08' => __('Lezhë', 'everest-forms'),
			'AL-10' => __('Shkodër', 'everest-forms'),
			'AL-11' => __('Tirana', 'everest-forms'),
			'AL-12' => __('Vlorë', 'everest-forms'),
		),
		'AO' => array( // Angolan states.
			'BGO' => __('Bengo', 'everest-forms'),
			'BLU' => __('Benguela', 'everest-forms'),
			'BIE' => __('Bié', 'everest-forms'),
			'CAB' => __('Cabinda', 'everest-forms'),
			'CNN' => __('Cunene', 'everest-forms'),
			'HUA' => __('Huambo', 'everest-forms'),
			'HUI' => __('Huíla', 'everest-forms'),
			'CCU' => __('Kuando Kubango', 'everest-forms'),
			'CNO' => __('Kwanza-Norte', 'everest-forms'),
			'CUS' => __('Kwanza-Sul', 'everest-forms'),
			'LUA' => __('Luanda', 'everest-forms'),
			'LNO' => __('Lunda-Norte', 'everest-forms'),
			'LSU' => __('Lunda-Sul', 'everest-forms'),
			'MAL' => __('Malanje', 'everest-forms'),
			'MOX' => __('Moxico', 'everest-forms'),
			'NAM' => __('Namibe', 'everest-forms'),
			'UIG' => __('Uíge', 'everest-forms'),
			'ZAI' => __('Zaire', 'everest-forms'),
		),
		'AR' => array( // Argentinian provinces.
			'C' => __('Ciudad Autónoma de Buenos Aires', 'everest-forms'),
			'B' => __('Buenos Aires', 'everest-forms'),
			'K' => __('Catamarca', 'everest-forms'),
			'H' => __('Chaco', 'everest-forms'),
			'U' => __('Chubut', 'everest-forms'),
			'X' => __('Córdoba', 'everest-forms'),
			'W' => __('Corrientes', 'everest-forms'),
			'E' => __('Entre Ríos', 'everest-forms'),
			'P' => __('Formosa', 'everest-forms'),
			'Y' => __('Jujuy', 'everest-forms'),
			'L' => __('La Pampa', 'everest-forms'),
			'F' => __('La Rioja', 'everest-forms'),
			'M' => __('Mendoza', 'everest-forms'),
			'N' => __('Misiones', 'everest-forms'),
			'Q' => __('Neuquén', 'everest-forms'),
			'R' => __('Río Negro', 'everest-forms'),
			'A' => __('Salta', 'everest-forms'),
			'J' => __('San Juan', 'everest-forms'),
			'D' => __('San Luis', 'everest-forms'),
			'Z' => __('Santa Cruz', 'everest-forms'),
			'S' => __('Santa Fe', 'everest-forms'),
			'G' => __('Santiago del Estero', 'everest-forms'),
			'V' => __('Tierra del Fuego', 'everest-forms'),
			'T' => __('Tucumán', 'everest-forms'),
		),
		'AT' => array(),
		'AU' => array( // Australian states.
			'ACT' => __('Australian Capital Territory', 'everest-forms'),
			'NSW' => __('New South Wales', 'everest-forms'),
			'NT'  => __('Northern Territory', 'everest-forms'),
			'QLD' => __('Queensland', 'everest-forms'),
			'SA'  => __('South Australia', 'everest-forms'),
			'TAS' => __('Tasmania', 'everest-forms'),
			'VIC' => __('Victoria', 'everest-forms'),
			'WA'  => __('Western Australia', 'everest-forms'),
		),
		'AX' => array(),
		'BD' => array( // Bangladeshi districts.
			'BD-05' => __('Bagerhat', 'everest-forms'),
			'BD-01' => __('Bandarban', 'everest-forms'),
			'BD-02' => __('Barguna', 'everest-forms'),
			'BD-06' => __('Barishal', 'everest-forms'),
			'BD-07' => __('Bhola', 'everest-forms'),
			'BD-03' => __('Bogura', 'everest-forms'),
			'BD-04' => __('Brahmanbaria', 'everest-forms'),
			'BD-09' => __('Chandpur', 'everest-forms'),
			'BD-10' => __('Chattogram', 'everest-forms'),
			'BD-12' => __('Chuadanga', 'everest-forms'),
			'BD-11' => __("Cox's Bazar", 'everest-forms'),
			'BD-08' => __('Cumilla', 'everest-forms'),
			'BD-13' => __('Dhaka', 'everest-forms'),
			'BD-14' => __('Dinajpur', 'everest-forms'),
			'BD-15' => __('Faridpur ', 'everest-forms'),
			'BD-16' => __('Feni', 'everest-forms'),
			'BD-19' => __('Gaibandha', 'everest-forms'),
			'BD-18' => __('Gazipur', 'everest-forms'),
			'BD-17' => __('Gopalganj', 'everest-forms'),
			'BD-20' => __('Habiganj', 'everest-forms'),
			'BD-21' => __('Jamalpur', 'everest-forms'),
			'BD-22' => __('Jashore', 'everest-forms'),
			'BD-25' => __('Jhalokati', 'everest-forms'),
			'BD-23' => __('Jhenaidah', 'everest-forms'),
			'BD-24' => __('Joypurhat', 'everest-forms'),
			'BD-29' => __('Khagrachhari', 'everest-forms'),
			'BD-27' => __('Khulna', 'everest-forms'),
			'BD-26' => __('Kishoreganj', 'everest-forms'),
			'BD-28' => __('Kurigram', 'everest-forms'),
			'BD-30' => __('Kushtia', 'everest-forms'),
			'BD-31' => __('Lakshmipur', 'everest-forms'),
			'BD-32' => __('Lalmonirhat', 'everest-forms'),
			'BD-36' => __('Madaripur', 'everest-forms'),
			'BD-37' => __('Magura', 'everest-forms'),
			'BD-33' => __('Manikganj ', 'everest-forms'),
			'BD-39' => __('Meherpur', 'everest-forms'),
			'BD-38' => __('Moulvibazar', 'everest-forms'),
			'BD-35' => __('Munshiganj', 'everest-forms'),
			'BD-34' => __('Mymensingh', 'everest-forms'),
			'BD-48' => __('Naogaon', 'everest-forms'),
			'BD-43' => __('Narail', 'everest-forms'),
			'BD-40' => __('Narayanganj', 'everest-forms'),
			'BD-42' => __('Narsingdi', 'everest-forms'),
			'BD-44' => __('Natore', 'everest-forms'),
			'BD-45' => __('Nawabganj', 'everest-forms'),
			'BD-41' => __('Netrakona', 'everest-forms'),
			'BD-46' => __('Nilphamari', 'everest-forms'),
			'BD-47' => __('Noakhali', 'everest-forms'),
			'BD-49' => __('Pabna', 'everest-forms'),
			'BD-52' => __('Panchagarh', 'everest-forms'),
			'BD-51' => __('Patuakhali', 'everest-forms'),
			'BD-50' => __('Pirojpur', 'everest-forms'),
			'BD-53' => __('Rajbari', 'everest-forms'),
			'BD-54' => __('Rajshahi', 'everest-forms'),
			'BD-56' => __('Rangamati', 'everest-forms'),
			'BD-55' => __('Rangpur', 'everest-forms'),
			'BD-58' => __('Satkhira', 'everest-forms'),
			'BD-62' => __('Shariatpur', 'everest-forms'),
			'BD-57' => __('Sherpur', 'everest-forms'),
			'BD-59' => __('Sirajganj', 'everest-forms'),
			'BD-61' => __('Sunamganj', 'everest-forms'),
			'BD-60' => __('Sylhet', 'everest-forms'),
			'BD-63' => __('Tangail', 'everest-forms'),
			'BD-64' => __('Thakurgaon', 'everest-forms'),
		),
		'BE' => array(),
		'BG' => array( // Bulgarian states.
			'BG-01' => __('Blagoevgrad', 'everest-forms'),
			'BG-02' => __('Burgas', 'everest-forms'),
			'BG-08' => __('Dobrich', 'everest-forms'),
			'BG-07' => __('Gabrovo', 'everest-forms'),
			'BG-26' => __('Haskovo', 'everest-forms'),
			'BG-09' => __('Kardzhali', 'everest-forms'),
			'BG-10' => __('Kyustendil', 'everest-forms'),
			'BG-11' => __('Lovech', 'everest-forms'),
			'BG-12' => __('Montana', 'everest-forms'),
			'BG-13' => __('Pazardzhik', 'everest-forms'),
			'BG-14' => __('Pernik', 'everest-forms'),
			'BG-15' => __('Pleven', 'everest-forms'),
			'BG-16' => __('Plovdiv', 'everest-forms'),
			'BG-17' => __('Razgrad', 'everest-forms'),
			'BG-18' => __('Ruse', 'everest-forms'),
			'BG-27' => __('Shumen', 'everest-forms'),
			'BG-19' => __('Silistra', 'everest-forms'),
			'BG-20' => __('Sliven', 'everest-forms'),
			'BG-21' => __('Smolyan', 'everest-forms'),
			'BG-23' => __('Sofia', 'everest-forms'),
			'BG-22' => __('Sofia-Grad', 'everest-forms'),
			'BG-24' => __('Stara Zagora', 'everest-forms'),
			'BG-25' => __('Targovishte', 'everest-forms'),
			'BG-03' => __('Varna', 'everest-forms'),
			'BG-04' => __('Veliko Tarnovo', 'everest-forms'),
			'BG-05' => __('Vidin', 'everest-forms'),
			'BG-06' => __('Vratsa', 'everest-forms'),
			'BG-28' => __('Yambol', 'everest-forms'),
		),
		'BH' => array(),
		'BI' => array(),
		'BJ' => array( // Beninese states.
			'AL' => __('Alibori', 'everest-forms'),
			'AK' => __('Atakora', 'everest-forms'),
			'AQ' => __('Atlantique', 'everest-forms'),
			'BO' => __('Borgou', 'everest-forms'),
			'CO' => __('Collines', 'everest-forms'),
			'KO' => __('Kouffo', 'everest-forms'),
			'DO' => __('Donga', 'everest-forms'),
			'LI' => __('Littoral', 'everest-forms'),
			'MO' => __('Mono', 'everest-forms'),
			'OU' => __('Ouémé', 'everest-forms'),
			'PL' => __('Plateau', 'everest-forms'),
			'ZO' => __('Zou', 'everest-forms'),
		),
		'BO' => array( // Bolivian states.
			'BO-B' => __('Beni', 'everest-forms'),
			'BO-H' => __('Chuquisaca', 'everest-forms'),
			'BO-C' => __('Cochabamba', 'everest-forms'),
			'BO-L' => __('La Paz', 'everest-forms'),
			'BO-O' => __('Oruro', 'everest-forms'),
			'BO-N' => __('Pando', 'everest-forms'),
			'BO-P' => __('Potosí', 'everest-forms'),
			'BO-S' => __('Santa Cruz', 'everest-forms'),
			'BO-T' => __('Tarija', 'everest-forms'),
		),
		'BR' => array( // Brazilian states.
			'AC' => __('Acre', 'everest-forms'),
			'AL' => __('Alagoas', 'everest-forms'),
			'AP' => __('Amapá', 'everest-forms'),
			'AM' => __('Amazonas', 'everest-forms'),
			'BA' => __('Bahia', 'everest-forms'),
			'CE' => __('Ceará', 'everest-forms'),
			'DF' => __('Distrito Federal', 'everest-forms'),
			'ES' => __('Espírito Santo', 'everest-forms'),
			'GO' => __('Goiás', 'everest-forms'),
			'MA' => __('Maranhão', 'everest-forms'),
			'MT' => __('Mato Grosso', 'everest-forms'),
			'MS' => __('Mato Grosso do Sul', 'everest-forms'),
			'MG' => __('Minas Gerais', 'everest-forms'),
			'PA' => __('Pará', 'everest-forms'),
			'PB' => __('Paraíba', 'everest-forms'),
			'PR' => __('Paraná', 'everest-forms'),
			'PE' => __('Pernambuco', 'everest-forms'),
			'PI' => __('Piauí', 'everest-forms'),
			'RJ' => __('Rio de Janeiro', 'everest-forms'),
			'RN' => __('Rio Grande do Norte', 'everest-forms'),
			'RS' => __('Rio Grande do Sul', 'everest-forms'),
			'RO' => __('Rondônia', 'everest-forms'),
			'RR' => __('Roraima', 'everest-forms'),
			'SC' => __('Santa Catarina', 'everest-forms'),
			'SP' => __('São Paulo', 'everest-forms'),
			'SE' => __('Sergipe', 'everest-forms'),
			'TO' => __('Tocantins', 'everest-forms'),
		),
		'CA' => array( // Canadian states.
			'AB' => __('Alberta', 'everest-forms'),
			'BC' => __('British Columbia', 'everest-forms'),
			'MB' => __('Manitoba', 'everest-forms'),
			'NB' => __('New Brunswick', 'everest-forms'),
			'NL' => __('Newfoundland and Labrador', 'everest-forms'),
			'NT' => __('Northwest Territories', 'everest-forms'),
			'NS' => __('Nova Scotia', 'everest-forms'),
			'NU' => __('Nunavut', 'everest-forms'),
			'ON' => __('Ontario', 'everest-forms'),
			'PE' => __('Prince Edward Island', 'everest-forms'),
			'QC' => __('Quebec', 'everest-forms'),
			'SK' => __('Saskatchewan', 'everest-forms'),
			'YT' => __('Yukon Territory', 'everest-forms'),
		),
		'CH' => array( // Swiss cantons.
			'AG' => __('Aargau', 'everest-forms'),
			'AR' => __('Appenzell Ausserrhoden', 'everest-forms'),
			'AI' => __('Appenzell Innerrhoden', 'everest-forms'),
			'BL' => __('Basel-Landschaft', 'everest-forms'),
			'BS' => __('Basel-Stadt', 'everest-forms'),
			'BE' => __('Bern', 'everest-forms'),
			'FR' => __('Fribourg', 'everest-forms'),
			'GE' => __('Geneva', 'everest-forms'),
			'GL' => __('Glarus', 'everest-forms'),
			'GR' => __('Graubünden', 'everest-forms'),
			'JU' => __('Jura', 'everest-forms'),
			'LU' => __('Luzern', 'everest-forms'),
			'NE' => __('Neuchâtel', 'everest-forms'),
			'NW' => __('Nidwalden', 'everest-forms'),
			'OW' => __('Obwalden', 'everest-forms'),
			'SH' => __('Schaffhausen', 'everest-forms'),
			'SZ' => __('Schwyz', 'everest-forms'),
			'SO' => __('Solothurn', 'everest-forms'),
			'SG' => __('St. Gallen', 'everest-forms'),
			'TG' => __('Thurgau', 'everest-forms'),
			'TI' => __('Ticino', 'everest-forms'),
			'UR' => __('Uri', 'everest-forms'),
			'VS' => __('Valais', 'everest-forms'),
			'VD' => __('Vaud', 'everest-forms'),
			'ZG' => __('Zug', 'everest-forms'),
			'ZH' => __('Zürich', 'everest-forms'),
		),
		'CL' => array( // Chilean states.
			'CL-AI' => __('Aisén del General Carlos Ibañez del Campo', 'everest-forms'),
			'CL-AN' => __('Antofagasta', 'everest-forms'),
			'CL-AP' => __('Arica y Parinacota', 'everest-forms'),
			'CL-AR' => __('La Araucanía', 'everest-forms'),
			'CL-AT' => __('Atacama', 'everest-forms'),
			'CL-BI' => __('Biobío', 'everest-forms'),
			'CL-CO' => __('Coquimbo', 'everest-forms'),
			'CL-LI' => __('Libertador General Bernardo O\'Higgins', 'everest-forms'),
			'CL-LL' => __('Los Lagos', 'everest-forms'),
			'CL-LR' => __('Los Ríos', 'everest-forms'),
			'CL-MA' => __('Magallanes', 'everest-forms'),
			'CL-ML' => __('Maule', 'everest-forms'),
			'CL-NB' => __('Ñuble', 'everest-forms'),
			'CL-RM' => __('Región Metropolitana de Santiago', 'everest-forms'),
			'CL-TA' => __('Tarapacá', 'everest-forms'),
			'CL-VS' => __('Valparaíso', 'everest-forms'),
		),
		'CN' => array( // Chinese states.
			'CN1'  => __('Yunnan / 云南', 'everest-forms'),
			'CN2'  => __('Beijing / 北京', 'everest-forms'),
			'CN3'  => __('Tianjin / 天津', 'everest-forms'),
			'CN4'  => __('Hebei / 河北', 'everest-forms'),
			'CN5'  => __('Shanxi / 山西', 'everest-forms'),
			'CN6'  => __('Inner Mongolia / 內蒙古', 'everest-forms'),
			'CN7'  => __('Liaoning / 辽宁', 'everest-forms'),
			'CN8'  => __('Jilin / 吉林', 'everest-forms'),
			'CN9'  => __('Heilongjiang / 黑龙江', 'everest-forms'),
			'CN10' => __('Shanghai / 上海', 'everest-forms'),
			'CN11' => __('Jiangsu / 江苏', 'everest-forms'),
			'CN12' => __('Zhejiang / 浙江', 'everest-forms'),
			'CN13' => __('Anhui / 安徽', 'everest-forms'),
			'CN14' => __('Fujian / 福建', 'everest-forms'),
			'CN15' => __('Jiangxi / 江西', 'everest-forms'),
			'CN16' => __('Shandong / 山东', 'everest-forms'),
			'CN17' => __('Henan / 河南', 'everest-forms'),
			'CN18' => __('Hubei / 湖北', 'everest-forms'),
			'CN19' => __('Hunan / 湖南', 'everest-forms'),
			'CN20' => __('Guangdong / 广东', 'everest-forms'),
			'CN21' => __('Guangxi Zhuang / 广西壮族', 'everest-forms'),
			'CN22' => __('Hainan / 海南', 'everest-forms'),
			'CN23' => __('Chongqing / 重庆', 'everest-forms'),
			'CN24' => __('Sichuan / 四川', 'everest-forms'),
			'CN25' => __('Guizhou / 贵州', 'everest-forms'),
			'CN26' => __('Shaanxi / 陕西', 'everest-forms'),
			'CN27' => __('Gansu / 甘肃', 'everest-forms'),
			'CN28' => __('Qinghai / 青海', 'everest-forms'),
			'CN29' => __('Ningxia Hui / 宁夏', 'everest-forms'),
			'CN30' => __('Macao / 澳门', 'everest-forms'),
			'CN31' => __('Tibet / 西藏', 'everest-forms'),
			'CN32' => __('Xinjiang / 新疆', 'everest-forms'),
		),
		'CO' => array( // Colombian states.
			'CO-AMA' => __('Amazonas', 'everest-forms'),
			'CO-ANT' => __('Antioquia', 'everest-forms'),
			'CO-ARA' => __('Arauca', 'everest-forms'),
			'CO-ATL' => __('Atlántico', 'everest-forms'),
			'CO-BOL' => __('Bolívar', 'everest-forms'),
			'CO-BOY' => __('Boyacá', 'everest-forms'),
			'CO-CAL' => __('Caldas', 'everest-forms'),
			'CO-CAQ' => __('Caquetá', 'everest-forms'),
			'CO-CAS' => __('Casanare', 'everest-forms'),
			'CO-CAU' => __('Cauca', 'everest-forms'),
			'CO-CES' => __('Cesar', 'everest-forms'),
			'CO-CHO' => __('Chocó', 'everest-forms'),
			'CO-COR' => __('Córdoba', 'everest-forms'),
			'CO-CUN' => __('Cundinamarca', 'everest-forms'),
			'CO-DC'  => __('Capital District', 'everest-forms'),
			'CO-GUA' => __('Guainía', 'everest-forms'),
			'CO-GUV' => __('Guaviare', 'everest-forms'),
			'CO-HUI' => __('Huila', 'everest-forms'),
			'CO-LAG' => __('La Guajira', 'everest-forms'),
			'CO-MAG' => __('Magdalena', 'everest-forms'),
			'CO-MET' => __('Meta', 'everest-forms'),
			'CO-NAR' => __('Nariño', 'everest-forms'),
			'CO-NSA' => __('Norte de Santander', 'everest-forms'),
			'CO-PUT' => __('Putumayo', 'everest-forms'),
			'CO-QUI' => __('Quindío', 'everest-forms'),
			'CO-RIS' => __('Risaralda', 'everest-forms'),
			'CO-SAN' => __('Santander', 'everest-forms'),
			'CO-SAP' => __('San Andrés & Providencia', 'everest-forms'),
			'CO-SUC' => __('Sucre', 'everest-forms'),
			'CO-TOL' => __('Tolima', 'everest-forms'),
			'CO-VAC' => __('Valle del Cauca', 'everest-forms'),
			'CO-VAU' => __('Vaupés', 'everest-forms'),
			'CO-VID' => __('Vichada', 'everest-forms'),
		),
		'CR' => array( // Costa Rican states.
			'CR-A'  => __('Alajuela', 'everest-forms'),
			'CR-C'  => __('Cartago', 'everest-forms'),
			'CR-G'  => __('Guanacaste', 'everest-forms'),
			'CR-H'  => __('Heredia', 'everest-forms'),
			'CR-L'  => __('Limón', 'everest-forms'),
			'CR-P'  => __('Puntarenas', 'everest-forms'),
			'CR-SJ' => __('San José', 'everest-forms'),
		),
		'CZ' => array(),
		'DE' => array( // German states.
			'DE-BW' => __('Baden-Württemberg', 'everest-forms'),
			'DE-BY' => __('Bavaria', 'everest-forms'),
			'DE-BE' => __('Berlin', 'everest-forms'),
			'DE-BB' => __('Brandenburg', 'everest-forms'),
			'DE-HB' => __('Bremen', 'everest-forms'),
			'DE-HH' => __('Hamburg', 'everest-forms'),
			'DE-HE' => __('Hesse', 'everest-forms'),
			'DE-MV' => __('Mecklenburg-Vorpommern', 'everest-forms'),
			'DE-NI' => __('Lower Saxony', 'everest-forms'),
			'DE-NW' => __('North Rhine-Westphalia', 'everest-forms'),
			'DE-RP' => __('Rhineland-Palatinate', 'everest-forms'),
			'DE-SL' => __('Saarland', 'everest-forms'),
			'DE-SN' => __('Saxony', 'everest-forms'),
			'DE-ST' => __('Saxony-Anhalt', 'everest-forms'),
			'DE-SH' => __('Schleswig-Holstein', 'everest-forms'),
			'DE-TH' => __('Thuringia', 'everest-forms'),
		),
		'DK' => array(),
		'DO' => array( // Dominican states.
			'DO-01' => __('Distrito Nacional', 'everest-forms'),
			'DO-02' => __('Azua', 'everest-forms'),
			'DO-03' => __('Baoruco', 'everest-forms'),
			'DO-04' => __('Barahona', 'everest-forms'),
			'DO-33' => __('Cibao Nordeste', 'everest-forms'),
			'DO-34' => __('Cibao Noroeste', 'everest-forms'),
			'DO-35' => __('Cibao Norte', 'everest-forms'),
			'DO-36' => __('Cibao Sur', 'everest-forms'),
			'DO-05' => __('Dajabón', 'everest-forms'),
			'DO-06' => __('Duarte', 'everest-forms'),
			'DO-08' => __('El Seibo', 'everest-forms'),
			'DO-37' => __('El Valle', 'everest-forms'),
			'DO-07' => __('Elías Piña', 'everest-forms'),
			'DO-38' => __('Enriquillo', 'everest-forms'),
			'DO-09' => __('Espaillat', 'everest-forms'),
			'DO-30' => __('Hato Mayor', 'everest-forms'),
			'DO-19' => __('Hermanas Mirabal', 'everest-forms'),
			'DO-39' => __('Higüamo', 'everest-forms'),
			'DO-10' => __('Independencia', 'everest-forms'),
			'DO-11' => __('La Altagracia', 'everest-forms'),
			'DO-12' => __('La Romana', 'everest-forms'),
			'DO-13' => __('La Vega', 'everest-forms'),
			'DO-14' => __('María Trinidad Sánchez', 'everest-forms'),
			'DO-28' => __('Monseñor Nouel', 'everest-forms'),
			'DO-15' => __('Monte Cristi', 'everest-forms'),
			'DO-29' => __('Monte Plata', 'everest-forms'),
			'DO-40' => __('Ozama', 'everest-forms'),
			'DO-16' => __('Pedernales', 'everest-forms'),
			'DO-17' => __('Peravia', 'everest-forms'),
			'DO-18' => __('Puerto Plata', 'everest-forms'),
			'DO-20' => __('Samaná', 'everest-forms'),
			'DO-21' => __('San Cristóbal', 'everest-forms'),
			'DO-31' => __('San José de Ocoa', 'everest-forms'),
			'DO-22' => __('San Juan', 'everest-forms'),
			'DO-23' => __('San Pedro de Macorís', 'everest-forms'),
			'DO-24' => __('Sánchez Ramírez', 'everest-forms'),
			'DO-25' => __('Santiago', 'everest-forms'),
			'DO-26' => __('Santiago Rodríguez', 'everest-forms'),
			'DO-32' => __('Santo Domingo', 'everest-forms'),
			'DO-41' => __('Valdesia', 'everest-forms'),
			'DO-27' => __('Valverde', 'everest-forms'),
			'DO-42' => __('Yuma', 'everest-forms'),
		),
		'DZ' => array( // Algerian states.
			'DZ-01' => __('Adrar', 'everest-forms'),
			'DZ-02' => __('Chlef', 'everest-forms'),
			'DZ-03' => __('Laghouat', 'everest-forms'),
			'DZ-04' => __('Oum El Bouaghi', 'everest-forms'),
			'DZ-05' => __('Batna', 'everest-forms'),
			'DZ-06' => __('Béjaïa', 'everest-forms'),
			'DZ-07' => __('Biskra', 'everest-forms'),
			'DZ-08' => __('Béchar', 'everest-forms'),
			'DZ-09' => __('Blida', 'everest-forms'),
			'DZ-10' => __('Bouira', 'everest-forms'),
			'DZ-11' => __('Tamanghasset', 'everest-forms'),
			'DZ-12' => __('Tébessa', 'everest-forms'),
			'DZ-13' => __('Tlemcen', 'everest-forms'),
			'DZ-14' => __('Tiaret', 'everest-forms'),
			'DZ-15' => __('Tizi Ouzou', 'everest-forms'),
			'DZ-16' => __('Algiers', 'everest-forms'),
			'DZ-17' => __('Djelfa', 'everest-forms'),
			'DZ-18' => __('Jijel', 'everest-forms'),
			'DZ-19' => __('Sétif', 'everest-forms'),
			'DZ-20' => __('Saïda', 'everest-forms'),
			'DZ-21' => __('Skikda', 'everest-forms'),
			'DZ-22' => __('Sidi Bel Abbès', 'everest-forms'),
			'DZ-23' => __('Annaba', 'everest-forms'),
			'DZ-24' => __('Guelma', 'everest-forms'),
			'DZ-25' => __('Constantine', 'everest-forms'),
			'DZ-26' => __('Médéa', 'everest-forms'),
			'DZ-27' => __('Mostaganem', 'everest-forms'),
			'DZ-28' => __('M’Sila', 'everest-forms'),
			'DZ-29' => __('Mascara', 'everest-forms'),
			'DZ-30' => __('Ouargla', 'everest-forms'),
			'DZ-31' => __('Oran', 'everest-forms'),
			'DZ-32' => __('El Bayadh', 'everest-forms'),
			'DZ-33' => __('Illizi', 'everest-forms'),
			'DZ-34' => __('Bordj Bou Arréridj', 'everest-forms'),
			'DZ-35' => __('Boumerdès', 'everest-forms'),
			'DZ-36' => __('El Tarf', 'everest-forms'),
			'DZ-37' => __('Tindouf', 'everest-forms'),
			'DZ-38' => __('Tissemsilt', 'everest-forms'),
			'DZ-39' => __('El Oued', 'everest-forms'),
			'DZ-40' => __('Khenchela', 'everest-forms'),
			'DZ-41' => __('Souk Ahras', 'everest-forms'),
			'DZ-42' => __('Tipasa', 'everest-forms'),
			'DZ-43' => __('Mila', 'everest-forms'),
			'DZ-44' => __('Aïn Defla', 'everest-forms'),
			'DZ-45' => __('Naama', 'everest-forms'),
			'DZ-46' => __('Aïn Témouchent', 'everest-forms'),
			'DZ-47' => __('Ghardaïa', 'everest-forms'),
			'DZ-48' => __('Relizane', 'everest-forms'),
		),
		'EE' => array(),
		'EC' => array( // Ecuadorian states.
			'EC-A'  => __('Azuay', 'everest-forms'),
			'EC-B'  => __('Bolívar', 'everest-forms'),
			'EC-F'  => __('Cañar', 'everest-forms'),
			'EC-C'  => __('Carchi', 'everest-forms'),
			'EC-H'  => __('Chimborazo', 'everest-forms'),
			'EC-X'  => __('Cotopaxi', 'everest-forms'),
			'EC-O'  => __('El Oro', 'everest-forms'),
			'EC-E'  => __('Esmeraldas', 'everest-forms'),
			'EC-W'  => __('Galápagos', 'everest-forms'),
			'EC-G'  => __('Guayas', 'everest-forms'),
			'EC-I'  => __('Imbabura', 'everest-forms'),
			'EC-L'  => __('Loja', 'everest-forms'),
			'EC-R'  => __('Los Ríos', 'everest-forms'),
			'EC-M'  => __('Manabí', 'everest-forms'),
			'EC-S'  => __('Morona-Santiago', 'everest-forms'),
			'EC-N'  => __('Napo', 'everest-forms'),
			'EC-D'  => __('Orellana', 'everest-forms'),
			'EC-Y'  => __('Pastaza', 'everest-forms'),
			'EC-P'  => __('Pichincha', 'everest-forms'),
			'EC-SE' => __('Santa Elena', 'everest-forms'),
			'EC-SD' => __('Santo Domingo de los Tsáchilas', 'everest-forms'),
			'EC-U'  => __('Sucumbíos', 'everest-forms'),
			'EC-T'  => __('Tungurahua', 'everest-forms'),
			'EC-Z'  => __('Zamora-Chinchipe', 'everest-forms'),
		),
		'EG' => array( // Egyptian states.
			'EGALX' => __('Alexandria', 'everest-forms'),
			'EGASN' => __('Aswan', 'everest-forms'),
			'EGAST' => __('Asyut', 'everest-forms'),
			'EGBA'  => __('Red Sea', 'everest-forms'),
			'EGBH'  => __('Beheira', 'everest-forms'),
			'EGBNS' => __('Beni Suef', 'everest-forms'),
			'EGC'   => __('Cairo', 'everest-forms'),
			'EGDK'  => __('Dakahlia', 'everest-forms'),
			'EGDT'  => __('Damietta', 'everest-forms'),
			'EGFYM' => __('Faiyum', 'everest-forms'),
			'EGGH'  => __('Gharbia', 'everest-forms'),
			'EGGZ'  => __('Giza', 'everest-forms'),
			'EGIS'  => __('Ismailia', 'everest-forms'),
			'EGJS'  => __('South Sinai', 'everest-forms'),
			'EGKB'  => __('Qalyubia', 'everest-forms'),
			'EGKFS' => __('Kafr el-Sheikh', 'everest-forms'),
			'EGKN'  => __('Qena', 'everest-forms'),
			'EGLX'  => __('Luxor', 'everest-forms'),
			'EGMN'  => __('Minya', 'everest-forms'),
			'EGMNF' => __('Monufia', 'everest-forms'),
			'EGMT'  => __('Matrouh', 'everest-forms'),
			'EGPTS' => __('Port Said', 'everest-forms'),
			'EGSHG' => __('Sohag', 'everest-forms'),
			'EGSHR' => __('Al Sharqia', 'everest-forms'),
			'EGSIN' => __('North Sinai', 'everest-forms'),
			'EGSUZ' => __('Suez', 'everest-forms'),
			'EGWAD' => __('New Valley', 'everest-forms'),
		),
		'ES' => array( // Spanish states.
			'C'  => __('A Coruña', 'everest-forms'),
			'VI' => __('Araba/Álava', 'everest-forms'),
			'AB' => __('Albacete', 'everest-forms'),
			'A'  => __('Alicante', 'everest-forms'),
			'AL' => __('Almería', 'everest-forms'),
			'O'  => __('Asturias', 'everest-forms'),
			'AV' => __('Ávila', 'everest-forms'),
			'BA' => __('Badajoz', 'everest-forms'),
			'PM' => __('Baleares', 'everest-forms'),
			'B'  => __('Barcelona', 'everest-forms'),
			'BU' => __('Burgos', 'everest-forms'),
			'CC' => __('Cáceres', 'everest-forms'),
			'CA' => __('Cádiz', 'everest-forms'),
			'S'  => __('Cantabria', 'everest-forms'),
			'CS' => __('Castellón', 'everest-forms'),
			'CE' => __('Ceuta', 'everest-forms'),
			'CR' => __('Ciudad Real', 'everest-forms'),
			'CO' => __('Córdoba', 'everest-forms'),
			'CU' => __('Cuenca', 'everest-forms'),
			'GI' => __('Girona', 'everest-forms'),
			'GR' => __('Granada', 'everest-forms'),
			'GU' => __('Guadalajara', 'everest-forms'),
			'SS' => __('Gipuzkoa', 'everest-forms'),
			'H'  => __('Huelva', 'everest-forms'),
			'HU' => __('Huesca', 'everest-forms'),
			'J'  => __('Jaén', 'everest-forms'),
			'LO' => __('La Rioja', 'everest-forms'),
			'GC' => __('Las Palmas', 'everest-forms'),
			'LE' => __('León', 'everest-forms'),
			'L'  => __('Lleida', 'everest-forms'),
			'LU' => __('Lugo', 'everest-forms'),
			'M'  => __('Madrid', 'everest-forms'),
			'MA' => __('Málaga', 'everest-forms'),
			'ML' => __('Melilla', 'everest-forms'),
			'MU' => __('Murcia', 'everest-forms'),
			'NA' => __('Navarra', 'everest-forms'),
			'OR' => __('Ourense', 'everest-forms'),
			'P'  => __('Palencia', 'everest-forms'),
			'PO' => __('Pontevedra', 'everest-forms'),
			'SA' => __('Salamanca', 'everest-forms'),
			'TF' => __('Santa Cruz de Tenerife', 'everest-forms'),
			'SG' => __('Segovia', 'everest-forms'),
			'SE' => __('Sevilla', 'everest-forms'),
			'SO' => __('Soria', 'everest-forms'),
			'T'  => __('Tarragona', 'everest-forms'),
			'TE' => __('Teruel', 'everest-forms'),
			'TO' => __('Toledo', 'everest-forms'),
			'V'  => __('Valencia', 'everest-forms'),
			'VA' => __('Valladolid', 'everest-forms'),
			'BI' => __('Biscay', 'everest-forms'),
			'ZA' => __('Zamora', 'everest-forms'),
			'Z'  => __('Zaragoza', 'everest-forms'),
		),
		'FI' => array(),
		'FR' => array(),
		'GF' => array(),
		'GH' => array( // Ghanaian regions.
			'AF' => __('Ahafo', 'everest-forms'),
			'AH' => __('Ashanti', 'everest-forms'),
			'BA' => __('Brong-Ahafo', 'everest-forms'),
			'BO' => __('Bono', 'everest-forms'),
			'BE' => __('Bono East', 'everest-forms'),
			'CP' => __('Central', 'everest-forms'),
			'EP' => __('Eastern', 'everest-forms'),
			'AA' => __('Greater Accra', 'everest-forms'),
			'NE' => __('North East', 'everest-forms'),
			'NP' => __('Northern', 'everest-forms'),
			'OT' => __('Oti', 'everest-forms'),
			'SV' => __('Savannah', 'everest-forms'),
			'UE' => __('Upper East', 'everest-forms'),
			'UW' => __('Upper West', 'everest-forms'),
			'TV' => __('Volta', 'everest-forms'),
			'WP' => __('Western', 'everest-forms'),
			'WN' => __('Western North', 'everest-forms'),
		),
		'GP' => array(),
		'GR' => array( // Greek regions.
			'I' => __('Attica', 'everest-forms'),
			'A' => __('East Macedonia and Thrace', 'everest-forms'),
			'B' => __('Central Macedonia', 'everest-forms'),
			'C' => __('West Macedonia', 'everest-forms'),
			'D' => __('Epirus', 'everest-forms'),
			'E' => __('Thessaly', 'everest-forms'),
			'F' => __('Ionian Islands', 'everest-forms'),
			'G' => __('West Greece', 'everest-forms'),
			'H' => __('Central Greece', 'everest-forms'),
			'J' => __('Peloponnese', 'everest-forms'),
			'K' => __('North Aegean', 'everest-forms'),
			'L' => __('South Aegean', 'everest-forms'),
			'M' => __('Crete', 'everest-forms'),
		),
		'GT' => array( // Guatemalan states.
			'GT-AV' => __('Alta Verapaz', 'everest-forms'),
			'GT-BV' => __('Baja Verapaz', 'everest-forms'),
			'GT-CM' => __('Chimaltenango', 'everest-forms'),
			'GT-CQ' => __('Chiquimula', 'everest-forms'),
			'GT-PR' => __('El Progreso', 'everest-forms'),
			'GT-ES' => __('Escuintla', 'everest-forms'),
			'GT-GU' => __('Guatemala', 'everest-forms'),
			'GT-HU' => __('Huehuetenango', 'everest-forms'),
			'GT-IZ' => __('Izabal', 'everest-forms'),
			'GT-JA' => __('Jalapa', 'everest-forms'),
			'GT-JU' => __('Jutiapa', 'everest-forms'),
			'GT-PE' => __('Petén', 'everest-forms'),
			'GT-QZ' => __('Quetzaltenango', 'everest-forms'),
			'GT-QC' => __('Quiché', 'everest-forms'),
			'GT-RE' => __('Retalhuleu', 'everest-forms'),
			'GT-SA' => __('Sacatepéquez', 'everest-forms'),
			'GT-SM' => __('San Marcos', 'everest-forms'),
			'GT-SR' => __('Santa Rosa', 'everest-forms'),
			'GT-SO' => __('Sololá', 'everest-forms'),
			'GT-SU' => __('Suchitepéquez', 'everest-forms'),
			'GT-TO' => __('Totonicapán', 'everest-forms'),
			'GT-ZA' => __('Zacapa', 'everest-forms'),
		),
		'HK' => array( // Hong Kong states.
			'HONG KONG'       => __('Hong Kong Island', 'everest-forms'),
			'KOWLOON'         => __('Kowloon', 'everest-forms'),
			'NEW TERRITORIES' => __('New Territories', 'everest-forms'),
		),
		'HN' => array( // Honduran states.
			'HN-AT' => __('Atlántida', 'everest-forms'),
			'HN-IB' => __('Bay Islands', 'everest-forms'),
			'HN-CH' => __('Choluteca', 'everest-forms'),
			'HN-CL' => __('Colón', 'everest-forms'),
			'HN-CM' => __('Comayagua', 'everest-forms'),
			'HN-CP' => __('Copán', 'everest-forms'),
			'HN-CR' => __('Cortés', 'everest-forms'),
			'HN-EP' => __('El Paraíso', 'everest-forms'),
			'HN-FM' => __('Francisco Morazán', 'everest-forms'),
			'HN-GD' => __('Gracias a Dios', 'everest-forms'),
			'HN-IN' => __('Intibucá', 'everest-forms'),
			'HN-LE' => __('Lempira', 'everest-forms'),
			'HN-LP' => __('La Paz', 'everest-forms'),
			'HN-OC' => __('Ocotepeque', 'everest-forms'),
			'HN-OL' => __('Olancho', 'everest-forms'),
			'HN-SB' => __('Santa Bárbara', 'everest-forms'),
			'HN-VA' => __('Valle', 'everest-forms'),
			'HN-YO' => __('Yoro', 'everest-forms'),
		),
		'HU' => array( // Hungarian states.
			'BK' => __('Bács-Kiskun', 'everest-forms'),
			'BE' => __('Békés', 'everest-forms'),
			'BA' => __('Baranya', 'everest-forms'),
			'BZ' => __('Borsod-Abaúj-Zemplén', 'everest-forms'),
			'BU' => __('Budapest', 'everest-forms'),
			'CS' => __('Csongrád-Csanád', 'everest-forms'),
			'FE' => __('Fejér', 'everest-forms'),
			'GS' => __('Győr-Moson-Sopron', 'everest-forms'),
			'HB' => __('Hajdú-Bihar', 'everest-forms'),
			'HE' => __('Heves', 'everest-forms'),
			'JN' => __('Jász-Nagykun-Szolnok', 'everest-forms'),
			'KE' => __('Komárom-Esztergom', 'everest-forms'),
			'NO' => __('Nógrád', 'everest-forms'),
			'PE' => __('Pest', 'everest-forms'),
			'SO' => __('Somogy', 'everest-forms'),
			'SZ' => __('Szabolcs-Szatmár-Bereg', 'everest-forms'),
			'TO' => __('Tolna', 'everest-forms'),
			'VA' => __('Vas', 'everest-forms'),
			'VE' => __('Veszprém', 'everest-forms'),
			'ZA' => __('Zala', 'everest-forms'),
		),
		'ID' => array( // Indonesian provinces.
			'AC' => __('Daerah Istimewa Aceh', 'everest-forms'),
			'SU' => __('Sumatera Utara', 'everest-forms'),
			'SB' => __('Sumatera Barat', 'everest-forms'),
			'RI' => __('Riau', 'everest-forms'),
			'KR' => __('Kepulauan Riau', 'everest-forms'),
			'JA' => __('Jambi', 'everest-forms'),
			'SS' => __('Sumatera Selatan', 'everest-forms'),
			'BB' => __('Bangka Belitung', 'everest-forms'),
			'BE' => __('Bengkulu', 'everest-forms'),
			'LA' => __('Lampung', 'everest-forms'),
			'JK' => __('DKI Jakarta', 'everest-forms'),
			'JB' => __('Jawa Barat', 'everest-forms'),
			'BT' => __('Banten', 'everest-forms'),
			'JT' => __('Jawa Tengah', 'everest-forms'),
			'JI' => __('Jawa Timur', 'everest-forms'),
			'YO' => __('Daerah Istimewa Yogyakarta', 'everest-forms'),
			'BA' => __('Bali', 'everest-forms'),
			'NB' => __('Nusa Tenggara Barat', 'everest-forms'),
			'NT' => __('Nusa Tenggara Timur', 'everest-forms'),
			'KB' => __('Kalimantan Barat', 'everest-forms'),
			'KT' => __('Kalimantan Tengah', 'everest-forms'),
			'KI' => __('Kalimantan Timur', 'everest-forms'),
			'KS' => __('Kalimantan Selatan', 'everest-forms'),
			'KU' => __('Kalimantan Utara', 'everest-forms'),
			'SA' => __('Sulawesi Utara', 'everest-forms'),
			'ST' => __('Sulawesi Tengah', 'everest-forms'),
			'SG' => __('Sulawesi Tenggara', 'everest-forms'),
			'SR' => __('Sulawesi Barat', 'everest-forms'),
			'SN' => __('Sulawesi Selatan', 'everest-forms'),
			'GO' => __('Gorontalo', 'everest-forms'),
			'MA' => __('Maluku', 'everest-forms'),
			'MU' => __('Maluku Utara', 'everest-forms'),
			'PA' => __('Papua', 'everest-forms'),
			'PB' => __('Papua Barat', 'everest-forms'),
		),
		'IE' => array( // Irish states.
			'CW' => __('Carlow', 'everest-forms'),
			'CN' => __('Cavan', 'everest-forms'),
			'CE' => __('Clare', 'everest-forms'),
			'CO' => __('Cork', 'everest-forms'),
			'DL' => __('Donegal', 'everest-forms'),
			'D'  => __('Dublin', 'everest-forms'),
			'G'  => __('Galway', 'everest-forms'),
			'KY' => __('Kerry', 'everest-forms'),
			'KE' => __('Kildare', 'everest-forms'),
			'KK' => __('Kilkenny', 'everest-forms'),
			'LS' => __('Laois', 'everest-forms'),
			'LM' => __('Leitrim', 'everest-forms'),
			'LK' => __('Limerick', 'everest-forms'),
			'LD' => __('Longford', 'everest-forms'),
			'LH' => __('Louth', 'everest-forms'),
			'MO' => __('Mayo', 'everest-forms'),
			'MH' => __('Meath', 'everest-forms'),
			'MN' => __('Monaghan', 'everest-forms'),
			'OY' => __('Offaly', 'everest-forms'),
			'RN' => __('Roscommon', 'everest-forms'),
			'SO' => __('Sligo', 'everest-forms'),
			'TA' => __('Tipperary', 'everest-forms'),
			'WD' => __('Waterford', 'everest-forms'),
			'WH' => __('Westmeath', 'everest-forms'),
			'WX' => __('Wexford', 'everest-forms'),
			'WW' => __('Wicklow', 'everest-forms'),
		),
		'IN' => array( // Indian states.
			'AP' => __('Andhra Pradesh', 'everest-forms'),
			'AR' => __('Arunachal Pradesh', 'everest-forms'),
			'AS' => __('Assam', 'everest-forms'),
			'BR' => __('Bihar', 'everest-forms'),
			'CT' => __('Chhattisgarh', 'everest-forms'),
			'GA' => __('Goa', 'everest-forms'),
			'GJ' => __('Gujarat', 'everest-forms'),
			'HR' => __('Haryana', 'everest-forms'),
			'HP' => __('Himachal Pradesh', 'everest-forms'),
			'JK' => __('Jammu and Kashmir', 'everest-forms'),
			'JH' => __('Jharkhand', 'everest-forms'),
			'KA' => __('Karnataka', 'everest-forms'),
			'KL' => __('Kerala', 'everest-forms'),
			'LA' => __('Ladakh', 'everest-forms'),
			'MP' => __('Madhya Pradesh', 'everest-forms'),
			'MH' => __('Maharashtra', 'everest-forms'),
			'MN' => __('Manipur', 'everest-forms'),
			'ML' => __('Meghalaya', 'everest-forms'),
			'MZ' => __('Mizoram', 'everest-forms'),
			'NL' => __('Nagaland', 'everest-forms'),
			'OR' => __('Odisha', 'everest-forms'),
			'PB' => __('Punjab', 'everest-forms'),
			'RJ' => __('Rajasthan', 'everest-forms'),
			'SK' => __('Sikkim', 'everest-forms'),
			'TN' => __('Tamil Nadu', 'everest-forms'),
			'TS' => __('Telangana', 'everest-forms'),
			'TR' => __('Tripura', 'everest-forms'),
			'UK' => __('Uttarakhand', 'everest-forms'),
			'UP' => __('Uttar Pradesh', 'everest-forms'),
			'WB' => __('West Bengal', 'everest-forms'),
			'AN' => __('Andaman and Nicobar Islands', 'everest-forms'),
			'CH' => __('Chandigarh', 'everest-forms'),
			'DN' => __('Dadra and Nagar Haveli', 'everest-forms'),
			'DD' => __('Daman and Diu', 'everest-forms'),
			'DL' => __('Delhi', 'everest-forms'),
			'LD' => __('Lakshadeep', 'everest-forms'),
			'PY' => __('Pondicherry (Puducherry)', 'everest-forms'),
		),
		'IR' => array( // Irania states.
			'KHZ' => __('Khuzestan (خوزستان)', 'everest-forms'),
			'THR' => __('Tehran (تهران)', 'everest-forms'),
			'ILM' => __('Ilaam (ایلام)', 'everest-forms'),
			'BHR' => __('Bushehr (بوشهر)', 'everest-forms'),
			'ADL' => __('Ardabil (اردبیل)', 'everest-forms'),
			'ESF' => __('Isfahan (اصفهان)', 'everest-forms'),
			'YZD' => __('Yazd (یزد)', 'everest-forms'),
			'KRH' => __('Kermanshah (کرمانشاه)', 'everest-forms'),
			'KRN' => __('Kerman (کرمان)', 'everest-forms'),
			'HDN' => __('Hamadan (همدان)', 'everest-forms'),
			'GZN' => __('Ghazvin (قزوین)', 'everest-forms'),
			'ZJN' => __('Zanjan (زنجان)', 'everest-forms'),
			'LRS' => __('Luristan (لرستان)', 'everest-forms'),
			'ABZ' => __('Alborz (البرز)', 'everest-forms'),
			'EAZ' => __('East Azarbaijan (آذربایجان شرقی)', 'everest-forms'),
			'WAZ' => __('West Azarbaijan (آذربایجان غربی)', 'everest-forms'),
			'CHB' => __('Chaharmahal and Bakhtiari (چهارمحال و بختیاری)', 'everest-forms'),
			'SKH' => __('South Khorasan (خراسان جنوبی)', 'everest-forms'),
			'RKH' => __('Razavi Khorasan (خراسان رضوی)', 'everest-forms'),
			'NKH' => __('North Khorasan (خراسان شمالی)', 'everest-forms'),
			'SMN' => __('Semnan (سمنان)', 'everest-forms'),
			'FRS' => __('Fars (فارس)', 'everest-forms'),
			'QHM' => __('Qom (قم)', 'everest-forms'),
			'KRD' => __('Kurdistan / کردستان)', 'everest-forms'),
			'KBD' => __('Kohgiluyeh and BoyerAhmad (کهگیلوییه و بویراحمد)', 'everest-forms'),
			'GLS' => __('Golestan (گلستان)', 'everest-forms'),
			'GIL' => __('Gilan (گیلان)', 'everest-forms'),
			'MZN' => __('Mazandaran (مازندران)', 'everest-forms'),
			'MKZ' => __('Markazi (مرکزی)', 'everest-forms'),
			'HRZ' => __('Hormozgan (هرمزگان)', 'everest-forms'),
			'SBN' => __('Sistan and Baluchestan (سیستان و بلوچستان)', 'everest-forms'),
		),
		'IS' => array(),
		'IT' => array( // Italian provinces.
			'AG' => __('Agrigento', 'everest-forms'),
			'AL' => __('Alessandria', 'everest-forms'),
			'AN' => __('Ancona', 'everest-forms'),
			'AO' => __('Aosta', 'everest-forms'),
			'AR' => __('Arezzo', 'everest-forms'),
			'AP' => __('Ascoli Piceno', 'everest-forms'),
			'AT' => __('Asti', 'everest-forms'),
			'AV' => __('Avellino', 'everest-forms'),
			'BA' => __('Bari', 'everest-forms'),
			'BT' => __('Barletta-Andria-Trani', 'everest-forms'),
			'BL' => __('Belluno', 'everest-forms'),
			'BN' => __('Benevento', 'everest-forms'),
			'BG' => __('Bergamo', 'everest-forms'),
			'BI' => __('Biella', 'everest-forms'),
			'BO' => __('Bologna', 'everest-forms'),
			'BZ' => __('Bolzano', 'everest-forms'),
			'BS' => __('Brescia', 'everest-forms'),
			'BR' => __('Brindisi', 'everest-forms'),
			'CA' => __('Cagliari', 'everest-forms'),
			'CL' => __('Caltanissetta', 'everest-forms'),
			'CB' => __('Campobasso', 'everest-forms'),
			'CE' => __('Caserta', 'everest-forms'),
			'CT' => __('Catania', 'everest-forms'),
			'CZ' => __('Catanzaro', 'everest-forms'),
			'CH' => __('Chieti', 'everest-forms'),
			'CO' => __('Como', 'everest-forms'),
			'CS' => __('Cosenza', 'everest-forms'),
			'CR' => __('Cremona', 'everest-forms'),
			'KR' => __('Crotone', 'everest-forms'),
			'CN' => __('Cuneo', 'everest-forms'),
			'EN' => __('Enna', 'everest-forms'),
			'FM' => __('Fermo', 'everest-forms'),
			'FE' => __('Ferrara', 'everest-forms'),
			'FI' => __('Firenze', 'everest-forms'),
			'FG' => __('Foggia', 'everest-forms'),
			'FC' => __('Forlì-Cesena', 'everest-forms'),
			'FR' => __('Frosinone', 'everest-forms'),
			'GE' => __('Genova', 'everest-forms'),
			'GO' => __('Gorizia', 'everest-forms'),
			'GR' => __('Grosseto', 'everest-forms'),
			'IM' => __('Imperia', 'everest-forms'),
			'IS' => __('Isernia', 'everest-forms'),
			'SP' => __('La Spezia', 'everest-forms'),
			'AQ' => __("L'Aquila", 'everest-forms'),
			'LT' => __('Latina', 'everest-forms'),
			'LE' => __('Lecce', 'everest-forms'),
			'LC' => __('Lecco', 'everest-forms'),
			'LI' => __('Livorno', 'everest-forms'),
			'LO' => __('Lodi', 'everest-forms'),
			'LU' => __('Lucca', 'everest-forms'),
			'MC' => __('Macerata', 'everest-forms'),
			'MN' => __('Mantova', 'everest-forms'),
			'MS' => __('Massa-Carrara', 'everest-forms'),
			'MT' => __('Matera', 'everest-forms'),
			'ME' => __('Messina', 'everest-forms'),
			'MI' => __('Milano', 'everest-forms'),
			'MO' => __('Modena', 'everest-forms'),
			'MB' => __('Monza e della Brianza', 'everest-forms'),
			'NA' => __('Napoli', 'everest-forms'),
			'NO' => __('Novara', 'everest-forms'),
			'NU' => __('Nuoro', 'everest-forms'),
			'OR' => __('Oristano', 'everest-forms'),
			'PD' => __('Padova', 'everest-forms'),
			'PA' => __('Palermo', 'everest-forms'),
			'PR' => __('Parma', 'everest-forms'),
			'PV' => __('Pavia', 'everest-forms'),
			'PG' => __('Perugia', 'everest-forms'),
			'PU' => __('Pesaro e Urbino', 'everest-forms'),
			'PE' => __('Pescara', 'everest-forms'),
			'PC' => __('Piacenza', 'everest-forms'),
			'PI' => __('Pisa', 'everest-forms'),
			'PT' => __('Pistoia', 'everest-forms'),
			'PN' => __('Pordenone', 'everest-forms'),
			'PZ' => __('Potenza', 'everest-forms'),
			'PO' => __('Prato', 'everest-forms'),
			'RG' => __('Ragusa', 'everest-forms'),
			'RA' => __('Ravenna', 'everest-forms'),
			'RC' => __('Reggio Calabria', 'everest-forms'),
			'RE' => __('Reggio Emilia', 'everest-forms'),
			'RI' => __('Rieti', 'everest-forms'),
			'RN' => __('Rimini', 'everest-forms'),
			'RM' => __('Roma', 'everest-forms'),
			'RO' => __('Rovigo', 'everest-forms'),
			'SA' => __('Salerno', 'everest-forms'),
			'SS' => __('Sassari', 'everest-forms'),
			'SV' => __('Savona', 'everest-forms'),
			'SI' => __('Siena', 'everest-forms'),
			'SR' => __('Siracusa', 'everest-forms'),
			'SO' => __('Sondrio', 'everest-forms'),
			'SU' => __('Sud Sardegna', 'everest-forms'),
			'TA' => __('Taranto', 'everest-forms'),
			'TE' => __('Teramo', 'everest-forms'),
			'TR' => __('Terni', 'everest-forms'),
			'TO' => __('Torino', 'everest-forms'),
			'TP' => __('Trapani', 'everest-forms'),
			'TN' => __('Trento', 'everest-forms'),
			'TV' => __('Treviso', 'everest-forms'),
			'TS' => __('Trieste', 'everest-forms'),
			'UD' => __('Udine', 'everest-forms'),
			'VA' => __('Varese', 'everest-forms'),
			'VE' => __('Venezia', 'everest-forms'),
			'VB' => __('Verbano-Cusio-Ossola', 'everest-forms'),
			'VC' => __('Vercelli', 'everest-forms'),
			'VR' => __('Verona', 'everest-forms'),
			'VV' => __('Vibo Valentia', 'everest-forms'),
			'VI' => __('Vicenza', 'everest-forms'),
			'VT' => __('Viterbo', 'everest-forms'),
		),
		'IL' => array(),
		'IM' => array(),
		'JM' => array( // Jamaican parishes.
			'JM-01' => __('Kingston', 'everest-forms'),
			'JM-02' => __('Saint Andrew', 'everest-forms'),
			'JM-03' => __('Saint Thomas', 'everest-forms'),
			'JM-04' => __('Portland', 'everest-forms'),
			'JM-05' => __('Saint Mary', 'everest-forms'),
			'JM-06' => __('Saint Ann', 'everest-forms'),
			'JM-07' => __('Trelawny', 'everest-forms'),
			'JM-08' => __('Saint James', 'everest-forms'),
			'JM-09' => __('Hanover', 'everest-forms'),
			'JM-10' => __('Westmoreland', 'everest-forms'),
			'JM-11' => __('Saint Elizabeth', 'everest-forms'),
			'JM-12' => __('Manchester', 'everest-forms'),
			'JM-13' => __('Clarendon', 'everest-forms'),
			'JM-14' => __('Saint Catherine', 'everest-forms'),
		),

		'JP' => array(
			'JP01' => __('Hokkaido', 'everest-forms'),
			'JP02' => __('Aomori', 'everest-forms'),
			'JP03' => __('Iwate', 'everest-forms'),
			'JP04' => __('Miyagi', 'everest-forms'),
			'JP05' => __('Akita', 'everest-forms'),
			'JP06' => __('Yamagata', 'everest-forms'),
			'JP07' => __('Fukushima', 'everest-forms'),
			'JP08' => __('Ibaraki', 'everest-forms'),
			'JP09' => __('Tochigi', 'everest-forms'),
			'JP10' => __('Gunma', 'everest-forms'),
			'JP11' => __('Saitama', 'everest-forms'),
			'JP12' => __('Chiba', 'everest-forms'),
			'JP13' => __('Tokyo', 'everest-forms'),
			'JP14' => __('Kanagawa', 'everest-forms'),
			'JP15' => __('Niigata', 'everest-forms'),
			'JP16' => __('Toyama', 'everest-forms'),
			'JP17' => __('Ishikawa', 'everest-forms'),
			'JP18' => __('Fukui', 'everest-forms'),
			'JP19' => __('Yamanashi', 'everest-forms'),
			'JP20' => __('Nagano', 'everest-forms'),
			'JP21' => __('Gifu', 'everest-forms'),
			'JP22' => __('Shizuoka', 'everest-forms'),
			'JP23' => __('Aichi', 'everest-forms'),
			'JP24' => __('Mie', 'everest-forms'),
			'JP25' => __('Shiga', 'everest-forms'),
			'JP26' => __('Kyoto', 'everest-forms'),
			'JP27' => __('Osaka', 'everest-forms'),
			'JP28' => __('Hyogo', 'everest-forms'),
			'JP29' => __('Nara', 'everest-forms'),
			'JP30' => __('Wakayama', 'everest-forms'),
			'JP31' => __('Tottori', 'everest-forms'),
			'JP32' => __('Shimane', 'everest-forms'),
			'JP33' => __('Okayama', 'everest-forms'),
			'JP34' => __('Hiroshima', 'everest-forms'),
			'JP35' => __('Yamaguchi', 'everest-forms'),
			'JP36' => __('Tokushima', 'everest-forms'),
			'JP37' => __('Kagawa', 'everest-forms'),
			'JP38' => __('Ehime', 'everest-forms'),
			'JP39' => __('Kochi', 'everest-forms'),
			'JP40' => __('Fukuoka', 'everest-forms'),
			'JP41' => __('Saga', 'everest-forms'),
			'JP42' => __('Nagasaki', 'everest-forms'),
			'JP43' => __('Kumamoto', 'everest-forms'),
			'JP44' => __('Oita', 'everest-forms'),
			'JP45' => __('Miyazaki', 'everest-forms'),
			'JP46' => __('Kagoshima', 'everest-forms'),
			'JP47' => __('Okinawa', 'everest-forms'),
		),
		'KE' => array( // Kenyan counties.
			'KE01' => __('Baringo', 'everest-forms'),
			'KE02' => __('Bomet', 'everest-forms'),
			'KE03' => __('Bungoma', 'everest-forms'),
			'KE04' => __('Busia', 'everest-forms'),
			'KE05' => __('Elgeyo-Marakwet', 'everest-forms'),
			'KE06' => __('Embu', 'everest-forms'),
			'KE07' => __('Garissa', 'everest-forms'),
			'KE08' => __('Homa Bay', 'everest-forms'),
			'KE09' => __('Isiolo', 'everest-forms'),
			'KE10' => __('Kajiado', 'everest-forms'),
			'KE11' => __('Kakamega', 'everest-forms'),
			'KE12' => __('Kericho', 'everest-forms'),
			'KE13' => __('Kiambu', 'everest-forms'),
			'KE14' => __('Kilifi', 'everest-forms'),
			'KE15' => __('Kirinyaga', 'everest-forms'),
			'KE16' => __('Kisii', 'everest-forms'),
			'KE17' => __('Kisumu', 'everest-forms'),
			'KE18' => __('Kitui', 'everest-forms'),
			'KE19' => __('Kwale', 'everest-forms'),
			'KE20' => __('Laikipia', 'everest-forms'),
			'KE21' => __('Lamu', 'everest-forms'),
			'KE22' => __('Machakos', 'everest-forms'),
			'KE23' => __('Makueni', 'everest-forms'),
			'KE24' => __('Mandera', 'everest-forms'),
			'KE25' => __('Marsabit', 'everest-forms'),
			'KE26' => __('Meru', 'everest-forms'),
			'KE27' => __('Migori', 'everest-forms'),
			'KE28' => __('Mombasa', 'everest-forms'),
			'KE29' => __('Murang’a', 'everest-forms'),
			'KE30' => __('Nairobi County', 'everest-forms'),
			'KE31' => __('Nakuru', 'everest-forms'),
			'KE32' => __('Nandi', 'everest-forms'),
			'KE33' => __('Narok', 'everest-forms'),
			'KE34' => __('Nyamira', 'everest-forms'),
			'KE35' => __('Nyandarua', 'everest-forms'),
			'KE36' => __('Nyeri', 'everest-forms'),
			'KE37' => __('Samburu', 'everest-forms'),
			'KE38' => __('Siaya', 'everest-forms'),
			'KE39' => __('Taita-Taveta', 'everest-forms'),
			'KE40' => __('Tana River', 'everest-forms'),
			'KE41' => __('Tharaka-Nithi', 'everest-forms'),
			'KE42' => __('Trans Nzoia', 'everest-forms'),
			'KE43' => __('Turkana', 'everest-forms'),
			'KE44' => __('Uasin Gishu', 'everest-forms'),
			'KE45' => __('Vihiga', 'everest-forms'),
			'KE46' => __('Wajir', 'everest-forms'),
			'KE47' => __('West Pokot', 'everest-forms'),
		),
		'KR' => array(),
		'KW' => array(),
		'LA' => array( // Laotian provinces.
			'AT' => __('Attapeu', 'everest-forms'),
			'BK' => __('Bokeo', 'everest-forms'),
			'BL' => __('Bolikhamsai', 'everest-forms'),
			'CH' => __('Champasak', 'everest-forms'),
			'HO' => __('Houaphanh', 'everest-forms'),
			'KH' => __('Khammouane', 'everest-forms'),
			'LM' => __('Luang Namtha', 'everest-forms'),
			'LP' => __('Luang Prabang', 'everest-forms'),
			'OU' => __('Oudomxay', 'everest-forms'),
			'PH' => __('Phongsaly', 'everest-forms'),
			'SL' => __('Salavan', 'everest-forms'),
			'SV' => __('Savannakhet', 'everest-forms'),
			'VI' => __('Vientiane Province', 'everest-forms'),
			'VT' => __('Vientiane', 'everest-forms'),
			'XA' => __('Sainyabuli', 'everest-forms'),
			'XE' => __('Sekong', 'everest-forms'),
			'XI' => __('Xiangkhouang', 'everest-forms'),
			'XS' => __('Xaisomboun', 'everest-forms'),
		),
		'LB' => array(),
		'LR' => array( // Liberian provinces.
			'BM' => __('Bomi', 'everest-forms'),
			'BN' => __('Bong', 'everest-forms'),
			'GA' => __('Gbarpolu', 'everest-forms'),
			'GB' => __('Grand Bassa', 'everest-forms'),
			'GC' => __('Grand Cape Mount', 'everest-forms'),
			'GG' => __('Grand Gedeh', 'everest-forms'),
			'GK' => __('Grand Kru', 'everest-forms'),
			'LO' => __('Lofa', 'everest-forms'),
			'MA' => __('Margibi', 'everest-forms'),
			'MY' => __('Maryland', 'everest-forms'),
			'MO' => __('Montserrado', 'everest-forms'),
			'NM' => __('Nimba', 'everest-forms'),
			'RV' => __('Rivercess', 'everest-forms'),
			'RG' => __('River Gee', 'everest-forms'),
			'SN' => __('Sinoe', 'everest-forms'),
		),
		'LU' => array(),
		'MD' => array( // Moldovan states.
			'C'  => __('Chișinău', 'everest-forms'),
			'BL' => __('Bălți', 'everest-forms'),
			'AN' => __('Anenii Noi', 'everest-forms'),
			'BS' => __('Basarabeasca', 'everest-forms'),
			'BR' => __('Briceni', 'everest-forms'),
			'CH' => __('Cahul', 'everest-forms'),
			'CT' => __('Cantemir', 'everest-forms'),
			'CL' => __('Călărași', 'everest-forms'),
			'CS' => __('Căușeni', 'everest-forms'),
			'CM' => __('Cimișlia', 'everest-forms'),
			'CR' => __('Criuleni', 'everest-forms'),
			'DN' => __('Dondușeni', 'everest-forms'),
			'DR' => __('Drochia', 'everest-forms'),
			'DB' => __('Dubăsari', 'everest-forms'),
			'ED' => __('Edineț', 'everest-forms'),
			'FL' => __('Fălești', 'everest-forms'),
			'FR' => __('Florești', 'everest-forms'),
			'GE' => __('UTA Găgăuzia', 'everest-forms'),
			'GL' => __('Glodeni', 'everest-forms'),
			'HN' => __('Hîncești', 'everest-forms'),
			'IL' => __('Ialoveni', 'everest-forms'),
			'LV' => __('Leova', 'everest-forms'),
			'NS' => __('Nisporeni', 'everest-forms'),
			'OC' => __('Ocnița', 'everest-forms'),
			'OR' => __('Orhei', 'everest-forms'),
			'RZ' => __('Rezina', 'everest-forms'),
			'RS' => __('Rîșcani', 'everest-forms'),
			'SG' => __('Sîngerei', 'everest-forms'),
			'SR' => __('Soroca', 'everest-forms'),
			'ST' => __('Strășeni', 'everest-forms'),
			'SD' => __('Șoldănești', 'everest-forms'),
			'SV' => __('Ștefan Vodă', 'everest-forms'),
			'TR' => __('Taraclia', 'everest-forms'),
			'TL' => __('Telenești', 'everest-forms'),
			'UN' => __('Ungheni', 'everest-forms'),
		),
		'MQ' => array(),
		'MT' => array(),
		'MX' => array( // Mexican states.
			'DF' => __('Ciudad de México', 'everest-forms'),
			'JA' => __('Jalisco', 'everest-forms'),
			'NL' => __('Nuevo León', 'everest-forms'),
			'AG' => __('Aguascalientes', 'everest-forms'),
			'BC' => __('Baja California', 'everest-forms'),
			'BS' => __('Baja California Sur', 'everest-forms'),
			'CM' => __('Campeche', 'everest-forms'),
			'CS' => __('Chiapas', 'everest-forms'),
			'CH' => __('Chihuahua', 'everest-forms'),
			'CO' => __('Coahuila', 'everest-forms'),
			'CL' => __('Colima', 'everest-forms'),
			'DG' => __('Durango', 'everest-forms'),
			'GT' => __('Guanajuato', 'everest-forms'),
			'GR' => __('Guerrero', 'everest-forms'),
			'HG' => __('Hidalgo', 'everest-forms'),
			'MX' => __('Estado de México', 'everest-forms'),
			'MI' => __('Michoacán', 'everest-forms'),
			'MO' => __('Morelos', 'everest-forms'),
			'NA' => __('Nayarit', 'everest-forms'),
			'OA' => __('Oaxaca', 'everest-forms'),
			'PU' => __('Puebla', 'everest-forms'),
			'QT' => __('Querétaro', 'everest-forms'),
			'QR' => __('Quintana Roo', 'everest-forms'),
			'SL' => __('San Luis Potosí', 'everest-forms'),
			'SI' => __('Sinaloa', 'everest-forms'),
			'SO' => __('Sonora', 'everest-forms'),
			'TB' => __('Tabasco', 'everest-forms'),
			'TM' => __('Tamaulipas', 'everest-forms'),
			'TL' => __('Tlaxcala', 'everest-forms'),
			'VE' => __('Veracruz', 'everest-forms'),
			'YU' => __('Yucatán', 'everest-forms'),
			'ZA' => __('Zacatecas', 'everest-forms'),
		),
		'MY' => array( // Malaysian states.
			'JHR' => __('Johor', 'everest-forms'),
			'KDH' => __('Kedah', 'everest-forms'),
			'KTN' => __('Kelantan', 'everest-forms'),
			'LBN' => __('Labuan', 'everest-forms'),
			'MLK' => __('Malacca (Melaka)', 'everest-forms'),
			'NSN' => __('Negeri Sembilan', 'everest-forms'),
			'PHG' => __('Pahang', 'everest-forms'),
			'PNG' => __('Penang (Pulau Pinang)', 'everest-forms'),
			'PRK' => __('Perak', 'everest-forms'),
			'PLS' => __('Perlis', 'everest-forms'),
			'SBH' => __('Sabah', 'everest-forms'),
			'SWK' => __('Sarawak', 'everest-forms'),
			'SGR' => __('Selangor', 'everest-forms'),
			'TRG' => __('Terengganu', 'everest-forms'),
			'PJY' => __('Putrajaya', 'everest-forms'),
			'KUL' => __('Kuala Lumpur', 'everest-forms'),
		),
		'MZ' => array( // Mozambican provinces.
			'MZP'   => __('Cabo Delgado', 'everest-forms'),
			'MZG'   => __('Gaza', 'everest-forms'),
			'MZI'   => __('Inhambane', 'everest-forms'),
			'MZB'   => __('Manica', 'everest-forms'),
			'MZL'   => __('Maputo Province', 'everest-forms'),
			'MZMPM' => __('Maputo', 'everest-forms'),
			'MZN'   => __('Nampula', 'everest-forms'),
			'MZA'   => __('Niassa', 'everest-forms'),
			'MZS'   => __('Sofala', 'everest-forms'),
			'MZT'   => __('Tete', 'everest-forms'),
			'MZQ'   => __('Zambézia', 'everest-forms'),
		),
		'NA' => array( // Namibian regions.
			'ER' => __('Erongo', 'everest-forms'),
			'HA' => __('Hardap', 'everest-forms'),
			'KA' => __('Karas', 'everest-forms'),
			'KE' => __('Kavango East', 'everest-forms'),
			'KW' => __('Kavango West', 'everest-forms'),
			'KH' => __('Khomas', 'everest-forms'),
			'KU' => __('Kunene', 'everest-forms'),
			'OW' => __('Ohangwena', 'everest-forms'),
			'OH' => __('Omaheke', 'everest-forms'),
			'OS' => __('Omusati', 'everest-forms'),
			'ON' => __('Oshana', 'everest-forms'),
			'OT' => __('Oshikoto', 'everest-forms'),
			'OD' => __('Otjozondjupa', 'everest-forms'),
			'CA' => __('Zambezi', 'everest-forms'),
		),
		'NG' => array( // Nigerian provinces.
			'AB' => __('Abia', 'everest-forms'),
			'FC' => __('Abuja', 'everest-forms'),
			'AD' => __('Adamawa', 'everest-forms'),
			'AK' => __('Akwa Ibom', 'everest-forms'),
			'AN' => __('Anambra', 'everest-forms'),
			'BA' => __('Bauchi', 'everest-forms'),
			'BY' => __('Bayelsa', 'everest-forms'),
			'BE' => __('Benue', 'everest-forms'),
			'BO' => __('Borno', 'everest-forms'),
			'CR' => __('Cross River', 'everest-forms'),
			'DE' => __('Delta', 'everest-forms'),
			'EB' => __('Ebonyi', 'everest-forms'),
			'ED' => __('Edo', 'everest-forms'),
			'EK' => __('Ekiti', 'everest-forms'),
			'EN' => __('Enugu', 'everest-forms'),
			'GO' => __('Gombe', 'everest-forms'),
			'IM' => __('Imo', 'everest-forms'),
			'JI' => __('Jigawa', 'everest-forms'),
			'KD' => __('Kaduna', 'everest-forms'),
			'KN' => __('Kano', 'everest-forms'),
			'KT' => __('Katsina', 'everest-forms'),
			'KE' => __('Kebbi', 'everest-forms'),
			'KO' => __('Kogi', 'everest-forms'),
			'KW' => __('Kwara', 'everest-forms'),
			'LA' => __('Lagos', 'everest-forms'),
			'NA' => __('Nasarawa', 'everest-forms'),
			'NI' => __('Niger', 'everest-forms'),
			'OG' => __('Ogun', 'everest-forms'),
			'ON' => __('Ondo', 'everest-forms'),
			'OS' => __('Osun', 'everest-forms'),
			'OY' => __('Oyo', 'everest-forms'),
			'PL' => __('Plateau', 'everest-forms'),
			'RI' => __('Rivers', 'everest-forms'),
			'SO' => __('Sokoto', 'everest-forms'),
			'TA' => __('Taraba', 'everest-forms'),
			'YO' => __('Yobe', 'everest-forms'),
			'ZA' => __('Zamfara', 'everest-forms'),
		),
		'NL' => array(),
		'NO' => array(),
		'NP' => array( // Nepalese zones.
			'BAG' => __('Bagmati', 'everest-forms'),
			'BHE' => __('Bheri', 'everest-forms'),
			'DHA' => __('Dhaulagiri', 'everest-forms'),
			'GAN' => __('Gandaki', 'everest-forms'),
			'JAN' => __('Janakpur', 'everest-forms'),
			'KAR' => __('Karnali', 'everest-forms'),
			'KOS' => __('Koshi', 'everest-forms'),
			'LUM' => __('Lumbini', 'everest-forms'),
			'MAH' => __('Mahakali', 'everest-forms'),
			'MEC' => __('Mechi', 'everest-forms'),
			'NAR' => __('Narayani', 'everest-forms'),
			'RAP' => __('Rapti', 'everest-forms'),
			'SAG' => __('Sagarmatha', 'everest-forms'),
			'SET' => __('Seti', 'everest-forms'),
		),
		'NI' => array( // Nicaraguan states.
			'NI-AN' => __('Atlántico Norte', 'everest-forms'),
			'NI-AS' => __('Atlántico Sur', 'everest-forms'),
			'NI-BO' => __('Boaco', 'everest-forms'),
			'NI-CA' => __('Carazo', 'everest-forms'),
			'NI-CI' => __('Chinandega', 'everest-forms'),
			'NI-CO' => __('Chontales', 'everest-forms'),
			'NI-ES' => __('Estelí', 'everest-forms'),
			'NI-GR' => __('Granada', 'everest-forms'),
			'NI-JI' => __('Jinotega', 'everest-forms'),
			'NI-LE' => __('León', 'everest-forms'),
			'NI-MD' => __('Madriz', 'everest-forms'),
			'NI-MN' => __('Managua', 'everest-forms'),
			'NI-MS' => __('Masaya', 'everest-forms'),
			'NI-MT' => __('Matagalpa', 'everest-forms'),
			'NI-NS' => __('Nueva Segovia', 'everest-forms'),
			'NI-RI' => __('Rivas', 'everest-forms'),
			'NI-SJ' => __('Río San Juan', 'everest-forms'),
		),
		'NZ' => array( // New Zealand states.
			'NL' => __('Northland', 'everest-forms'),
			'AK' => __('Auckland', 'everest-forms'),
			'WA' => __('Waikato', 'everest-forms'),
			'BP' => __('Bay of Plenty', 'everest-forms'),
			'TK' => __('Taranaki', 'everest-forms'),
			'GI' => __('Gisborne', 'everest-forms'),
			'HB' => __('Hawke’s Bay', 'everest-forms'),
			'MW' => __('Manawatu-Wanganui', 'everest-forms'),
			'WE' => __('Wellington', 'everest-forms'),
			'NS' => __('Nelson', 'everest-forms'),
			'MB' => __('Marlborough', 'everest-forms'),
			'TM' => __('Tasman', 'everest-forms'),
			'WC' => __('West Coast', 'everest-forms'),
			'CT' => __('Canterbury', 'everest-forms'),
			'OT' => __('Otago', 'everest-forms'),
			'SL' => __('Southland', 'everest-forms'),
		),
		'PA' => array( // Panamanian states.
			'PA-1'  => __('Bocas del Toro', 'everest-forms'),
			'PA-2'  => __('Coclé', 'everest-forms'),
			'PA-3'  => __('Colón', 'everest-forms'),
			'PA-4'  => __('Chiriquí', 'everest-forms'),
			'PA-5'  => __('Darién', 'everest-forms'),
			'PA-6'  => __('Herrera', 'everest-forms'),
			'PA-7'  => __('Los Santos', 'everest-forms'),
			'PA-8'  => __('Panamá', 'everest-forms'),
			'PA-9'  => __('Veraguas', 'everest-forms'),
			'PA-10' => __('West Panamá', 'everest-forms'),
			'PA-EM' => __('Emberá', 'everest-forms'),
			'PA-KY' => __('Guna Yala', 'everest-forms'),
			'PA-NB' => __('Ngöbe-Buglé', 'everest-forms'),
		),
		'PE' => array( // Peruvian states.
			'CAL' => __('El Callao', 'everest-forms'),
			'LMA' => __('Municipalidad Metropolitana de Lima', 'everest-forms'),
			'AMA' => __('Amazonas', 'everest-forms'),
			'ANC' => __('Ancash', 'everest-forms'),
			'APU' => __('Apurímac', 'everest-forms'),
			'ARE' => __('Arequipa', 'everest-forms'),
			'AYA' => __('Ayacucho', 'everest-forms'),
			'CAJ' => __('Cajamarca', 'everest-forms'),
			'CUS' => __('Cusco', 'everest-forms'),
			'HUV' => __('Huancavelica', 'everest-forms'),
			'HUC' => __('Huánuco', 'everest-forms'),
			'ICA' => __('Ica', 'everest-forms'),
			'JUN' => __('Junín', 'everest-forms'),
			'LAL' => __('La Libertad', 'everest-forms'),
			'LAM' => __('Lambayeque', 'everest-forms'),
			'LIM' => __('Lima', 'everest-forms'),
			'LOR' => __('Loreto', 'everest-forms'),
			'MDD' => __('Madre de Dios', 'everest-forms'),
			'MOQ' => __('Moquegua', 'everest-forms'),
			'PAS' => __('Pasco', 'everest-forms'),
			'PIU' => __('Piura', 'everest-forms'),
			'PUN' => __('Puno', 'everest-forms'),
			'SAM' => __('San Martín', 'everest-forms'),
			'TAC' => __('Tacna', 'everest-forms'),
			'TUM' => __('Tumbes', 'everest-forms'),
			'UCA' => __('Ucayali', 'everest-forms'),
		),
		'PH' => array( // Philippine provinces.
			'ABR' => __('Abra', 'everest-forms'),
			'AGN' => __('Agusan del Norte', 'everest-forms'),
			'AGS' => __('Agusan del Sur', 'everest-forms'),
			'AKL' => __('Aklan', 'everest-forms'),
			'ALB' => __('Albay', 'everest-forms'),
			'ANT' => __('Antique', 'everest-forms'),
			'APA' => __('Apayao', 'everest-forms'),
			'AUR' => __('Aurora', 'everest-forms'),
			'BAS' => __('Basilan', 'everest-forms'),
			'BAN' => __('Bataan', 'everest-forms'),
			'BTN' => __('Batanes', 'everest-forms'),
			'BTG' => __('Batangas', 'everest-forms'),
			'BEN' => __('Benguet', 'everest-forms'),
			'BIL' => __('Biliran', 'everest-forms'),
			'BOH' => __('Bohol', 'everest-forms'),
			'BUK' => __('Bukidnon', 'everest-forms'),
			'BUL' => __('Bulacan', 'everest-forms'),
			'CAG' => __('Cagayan', 'everest-forms'),
			'CAN' => __('Camarines Norte', 'everest-forms'),
			'CAS' => __('Camarines Sur', 'everest-forms'),
			'CAM' => __('Camiguin', 'everest-forms'),
			'CAP' => __('Capiz', 'everest-forms'),
			'CAT' => __('Catanduanes', 'everest-forms'),
			'CAV' => __('Cavite', 'everest-forms'),
			'CEB' => __('Cebu', 'everest-forms'),
			'COM' => __('Compostela Valley', 'everest-forms'),
			'NCO' => __('Cotabato', 'everest-forms'),
			'DAV' => __('Davao del Norte', 'everest-forms'),
			'DAS' => __('Davao del Sur', 'everest-forms'),
			'DAC' => __('Davao Occidental', 'everest-forms'),
			'DAO' => __('Davao Oriental', 'everest-forms'),
			'DIN' => __('Dinagat Islands', 'everest-forms'),
			'EAS' => __('Eastern Samar', 'everest-forms'),
			'GUI' => __('Guimaras', 'everest-forms'),
			'IFU' => __('Ifugao', 'everest-forms'),
			'ILN' => __('Ilocos Norte', 'everest-forms'),
			'ILS' => __('Ilocos Sur', 'everest-forms'),
			'ILI' => __('Iloilo', 'everest-forms'),
			'ISA' => __('Isabela', 'everest-forms'),
			'KAL' => __('Kalinga', 'everest-forms'),
			'LUN' => __('La Union', 'everest-forms'),
			'LAG' => __('Laguna', 'everest-forms'),
			'LAN' => __('Lanao del Norte', 'everest-forms'),
			'LAS' => __('Lanao del Sur', 'everest-forms'),
			'LEY' => __('Leyte', 'everest-forms'),
			'MAG' => __('Maguindanao', 'everest-forms'),
			'MAD' => __('Marinduque', 'everest-forms'),
			'MAS' => __('Masbate', 'everest-forms'),
			'MSC' => __('Misamis Occidental', 'everest-forms'),
			'MSR' => __('Misamis Oriental', 'everest-forms'),
			'MOU' => __('Mountain Province', 'everest-forms'),
			'NEC' => __('Negros Occidental', 'everest-forms'),
			'NER' => __('Negros Oriental', 'everest-forms'),
			'NSA' => __('Northern Samar', 'everest-forms'),
			'NUE' => __('Nueva Ecija', 'everest-forms'),
			'NUV' => __('Nueva Vizcaya', 'everest-forms'),
			'MDC' => __('Occidental Mindoro', 'everest-forms'),
			'MDR' => __('Oriental Mindoro', 'everest-forms'),
			'PLW' => __('Palawan', 'everest-forms'),
			'PAM' => __('Pampanga', 'everest-forms'),
			'PAN' => __('Pangasinan', 'everest-forms'),
			'QUE' => __('Quezon', 'everest-forms'),
			'QUI' => __('Quirino', 'everest-forms'),
			'RIZ' => __('Rizal', 'everest-forms'),
			'ROM' => __('Romblon', 'everest-forms'),
			'WSA' => __('Samar', 'everest-forms'),
			'SAR' => __('Sarangani', 'everest-forms'),
			'SIQ' => __('Siquijor', 'everest-forms'),
			'SOR' => __('Sorsogon', 'everest-forms'),
			'SCO' => __('South Cotabato', 'everest-forms'),
			'SLE' => __('Southern Leyte', 'everest-forms'),
			'SUK' => __('Sultan Kudarat', 'everest-forms'),
			'SLU' => __('Sulu', 'everest-forms'),
			'SUN' => __('Surigao del Norte', 'everest-forms'),
			'SUR' => __('Surigao del Sur', 'everest-forms'),
			'TAR' => __('Tarlac', 'everest-forms'),
			'TAW' => __('Tawi-Tawi', 'everest-forms'),
			'ZMB' => __('Zambales', 'everest-forms'),
			'ZAN' => __('Zamboanga del Norte', 'everest-forms'),
			'ZAS' => __('Zamboanga del Sur', 'everest-forms'),
			'ZSI' => __('Zamboanga Sibugay', 'everest-forms'),
			'00'  => __('Metro Manila', 'everest-forms'),
		),
		'PK' => array( // Pakistani states.
			'JK' => __('Azad Kashmir', 'everest-forms'),
			'BA' => __('Balochistan', 'everest-forms'),
			'TA' => __('FATA', 'everest-forms'),
			'GB' => __('Gilgit Baltistan', 'everest-forms'),
			'IS' => __('Islamabad Capital Territory', 'everest-forms'),
			'KP' => __('Khyber Pakhtunkhwa', 'everest-forms'),
			'PB' => __('Punjab', 'everest-forms'),
			'SD' => __('Sindh', 'everest-forms'),
		),
		'PL' => array(),
		'PR' => array(),
		'PT' => array(),
		'PY' => array( // Paraguayan states.
			'PY-ASU' => __('Asunción', 'everest-forms'),
			'PY-1'   => __('Concepción', 'everest-forms'),
			'PY-2'   => __('San Pedro', 'everest-forms'),
			'PY-3'   => __('Cordillera', 'everest-forms'),
			'PY-4'   => __('Guairá', 'everest-forms'),
			'PY-5'   => __('Caaguazú', 'everest-forms'),
			'PY-6'   => __('Caazapá', 'everest-forms'),
			'PY-7'   => __('Itapúa', 'everest-forms'),
			'PY-8'   => __('Misiones', 'everest-forms'),
			'PY-9'   => __('Paraguarí', 'everest-forms'),
			'PY-10'  => __('Alto Paraná', 'everest-forms'),
			'PY-11'  => __('Central', 'everest-forms'),
			'PY-12'  => __('Ñeembucú', 'everest-forms'),
			'PY-13'  => __('Amambay', 'everest-forms'),
			'PY-14'  => __('Canindeyú', 'everest-forms'),
			'PY-15'  => __('Presidente Hayes', 'everest-forms'),
			'PY-16'  => __('Alto Paraguay', 'everest-forms'),
			'PY-17'  => __('Boquerón', 'everest-forms'),
		),
		'RE' => array(),
		'RO' => array( // Romanian states.
			'AB' => __('Alba', 'everest-forms'),
			'AR' => __('Arad', 'everest-forms'),
			'AG' => __('Argeș', 'everest-forms'),
			'BC' => __('Bacău', 'everest-forms'),
			'BH' => __('Bihor', 'everest-forms'),
			'BN' => __('Bistrița-Năsăud', 'everest-forms'),
			'BT' => __('Botoșani', 'everest-forms'),
			'BR' => __('Brăila', 'everest-forms'),
			'BV' => __('Brașov', 'everest-forms'),
			'B'  => __('București', 'everest-forms'),
			'BZ' => __('Buzău', 'everest-forms'),
			'CL' => __('Călărași', 'everest-forms'),
			'CS' => __('Caraș-Severin', 'everest-forms'),
			'CJ' => __('Cluj', 'everest-forms'),
			'CT' => __('Constanța', 'everest-forms'),
			'CV' => __('Covasna', 'everest-forms'),
			'DB' => __('Dâmbovița', 'everest-forms'),
			'DJ' => __('Dolj', 'everest-forms'),
			'GL' => __('Galați', 'everest-forms'),
			'GR' => __('Giurgiu', 'everest-forms'),
			'GJ' => __('Gorj', 'everest-forms'),
			'HR' => __('Harghita', 'everest-forms'),
			'HD' => __('Hunedoara', 'everest-forms'),
			'IL' => __('Ialomița', 'everest-forms'),
			'IS' => __('Iași', 'everest-forms'),
			'IF' => __('Ilfov', 'everest-forms'),
			'MM' => __('Maramureș', 'everest-forms'),
			'MH' => __('Mehedinți', 'everest-forms'),
			'MS' => __('Mureș', 'everest-forms'),
			'NT' => __('Neamț', 'everest-forms'),
			'OT' => __('Olt', 'everest-forms'),
			'PH' => __('Prahova', 'everest-forms'),
			'SJ' => __('Sălaj', 'everest-forms'),
			'SM' => __('Satu Mare', 'everest-forms'),
			'SB' => __('Sibiu', 'everest-forms'),
			'SV' => __('Suceava', 'everest-forms'),
			'TR' => __('Teleorman', 'everest-forms'),
			'TM' => __('Timiș', 'everest-forms'),
			'TL' => __('Tulcea', 'everest-forms'),
			'VL' => __('Vâlcea', 'everest-forms'),
			'VS' => __('Vaslui', 'everest-forms'),
			'VN' => __('Vrancea', 'everest-forms'),
		),
		'SG' => array(),
		'SK' => array(),
		'SI' => array(),
		'SV' => array( // Salvadoran states.
			'SV-AH' => __('Ahuachapán', 'everest-forms'),
			'SV-CA' => __('Cabañas', 'everest-forms'),
			'SV-CH' => __('Chalatenango', 'everest-forms'),
			'SV-CU' => __('Cuscatlán', 'everest-forms'),
			'SV-LI' => __('La Libertad', 'everest-forms'),
			'SV-MO' => __('Morazán', 'everest-forms'),
			'SV-PA' => __('La Paz', 'everest-forms'),
			'SV-SA' => __('Santa Ana', 'everest-forms'),
			'SV-SM' => __('San Miguel', 'everest-forms'),
			'SV-SO' => __('Sonsonate', 'everest-forms'),
			'SV-SS' => __('San Salvador', 'everest-forms'),
			'SV-SV' => __('San Vicente', 'everest-forms'),
			'SV-UN' => __('La Unión', 'everest-forms'),
			'SV-US' => __('Usulután', 'everest-forms'),
		),
		'TH' => array( // Thai states.
			'TH-37' => __('Amnat Charoen', 'everest-forms'),
			'TH-15' => __('Ang Thong', 'everest-forms'),
			'TH-14' => __('Ayutthaya', 'everest-forms'),
			'TH-10' => __('Bangkok', 'everest-forms'),
			'TH-38' => __('Bueng Kan', 'everest-forms'),
			'TH-31' => __('Buri Ram', 'everest-forms'),
			'TH-24' => __('Chachoengsao', 'everest-forms'),
			'TH-18' => __('Chai Nat', 'everest-forms'),
			'TH-36' => __('Chaiyaphum', 'everest-forms'),
			'TH-22' => __('Chanthaburi', 'everest-forms'),
			'TH-50' => __('Chiang Mai', 'everest-forms'),
			'TH-57' => __('Chiang Rai', 'everest-forms'),
			'TH-20' => __('Chonburi', 'everest-forms'),
			'TH-86' => __('Chumphon', 'everest-forms'),
			'TH-46' => __('Kalasin', 'everest-forms'),
			'TH-62' => __('Kamphaeng Phet', 'everest-forms'),
			'TH-71' => __('Kanchanaburi', 'everest-forms'),
			'TH-40' => __('Khon Kaen', 'everest-forms'),
			'TH-81' => __('Krabi', 'everest-forms'),
			'TH-52' => __('Lampang', 'everest-forms'),
			'TH-51' => __('Lamphun', 'everest-forms'),
			'TH-42' => __('Loei', 'everest-forms'),
			'TH-16' => __('Lopburi', 'everest-forms'),
			'TH-58' => __('Mae Hong Son', 'everest-forms'),
			'TH-44' => __('Maha Sarakham', 'everest-forms'),
			'TH-49' => __('Mukdahan', 'everest-forms'),
			'TH-26' => __('Nakhon Nayok', 'everest-forms'),
			'TH-73' => __('Nakhon Pathom', 'everest-forms'),
			'TH-48' => __('Nakhon Phanom', 'everest-forms'),
			'TH-30' => __('Nakhon Ratchasima', 'everest-forms'),
			'TH-60' => __('Nakhon Sawan', 'everest-forms'),
			'TH-80' => __('Nakhon Si Thammarat', 'everest-forms'),
			'TH-55' => __('Nan', 'everest-forms'),
			'TH-96' => __('Narathiwat', 'everest-forms'),
			'TH-39' => __('Nong Bua Lam Phu', 'everest-forms'),
			'TH-43' => __('Nong Khai', 'everest-forms'),
			'TH-12' => __('Nonthaburi', 'everest-forms'),
			'TH-13' => __('Pathum Thani', 'everest-forms'),
			'TH-94' => __('Pattani', 'everest-forms'),
			'TH-82' => __('Phang Nga', 'everest-forms'),
			'TH-93' => __('Phatthalung', 'everest-forms'),
			'TH-56' => __('Phayao', 'everest-forms'),
			'TH-67' => __('Phetchabun', 'everest-forms'),
			'TH-76' => __('Phetchaburi', 'everest-forms'),
			'TH-66' => __('Phichit', 'everest-forms'),
			'TH-65' => __('Phitsanulok', 'everest-forms'),
			'TH-54' => __('Phrae', 'everest-forms'),
			'TH-83' => __('Phuket', 'everest-forms'),
			'TH-25' => __('Prachin Buri', 'everest-forms'),
			'TH-77' => __('Prachuap Khiri Khan', 'everest-forms'),
			'TH-85' => __('Ranong', 'everest-forms'),
			'TH-70' => __('Ratchaburi', 'everest-forms'),
			'TH-21' => __('Rayong', 'everest-forms'),
			'TH-45' => __('Roi Et', 'everest-forms'),
			'TH-27' => __('Sa Kaeo', 'everest-forms'),
			'TH-47' => __('Sakon Nakhon', 'everest-forms'),
			'TH-11' => __('Samut Prakan', 'everest-forms'),
			'TH-74' => __('Samut Sakhon', 'everest-forms'),
			'TH-75' => __('Samut Songkhram', 'everest-forms'),
			'TH-19' => __('Saraburi', 'everest-forms'),
			'TH-91' => __('Satun', 'everest-forms'),
			'TH-17' => __('Sing Buri', 'everest-forms'),
			'TH-33' => __('Sisaket', 'everest-forms'),
			'TH-90' => __('Songkhla', 'everest-forms'),
			'TH-64' => __('Sukhothai', 'everest-forms'),
			'TH-72' => __('Suphan Buri', 'everest-forms'),
			'TH-84' => __('Surat Thani', 'everest-forms'),
			'TH-32' => __('Surin', 'everest-forms'),
			'TH-63' => __('Tak', 'everest-forms'),
			'TH-92' => __('Trang', 'everest-forms'),
			'TH-23' => __('Trat', 'everest-forms'),
			'TH-34' => __('Ubon Ratchathani', 'everest-forms'),
			'TH-41' => __('Udon Thani', 'everest-forms'),
			'TH-61' => __('Uthai Thani', 'everest-forms'),
			'TH-53' => __('Uttaradit', 'everest-forms'),
			'TH-95' => __('Yala', 'everest-forms'),
			'TH-35' => __('Yasothon', 'everest-forms'),
		),
		'TR' => array( // Turkish states.
			'TR01' => __('Adana', 'everest-forms'),
			'TR02' => __('Adıyaman', 'everest-forms'),
			'TR03' => __('Afyon', 'everest-forms'),
			'TR04' => __('Ağrı', 'everest-forms'),
			'TR05' => __('Amasya', 'everest-forms'),
			'TR06' => __('Ankara', 'everest-forms'),
			'TR07' => __('Antalya', 'everest-forms'),
			'TR08' => __('Artvin', 'everest-forms'),
			'TR09' => __('Aydın', 'everest-forms'),
			'TR10' => __('Balıkesir', 'everest-forms'),
			'TR11' => __('Bilecik', 'everest-forms'),
			'TR12' => __('Bingöl', 'everest-forms'),
			'TR13' => __('Bitlis', 'everest-forms'),
			'TR14' => __('Bolu', 'everest-forms'),
			'TR15' => __('Burdur', 'everest-forms'),
			'TR16' => __('Bursa', 'everest-forms'),
			'TR17' => __('Çanakkale', 'everest-forms'),
			'TR18' => __('Çankırı', 'everest-forms'),
			'TR19' => __('Çorum', 'everest-forms'),
			'TR20' => __('Denizli', 'everest-forms'),
			'TR21' => __('Diyarbakır', 'everest-forms'),
			'TR22' => __('Edirne', 'everest-forms'),
			'TR23' => __('Elazığ', 'everest-forms'),
			'TR24' => __('Erzincan', 'everest-forms'),
			'TR25' => __('Erzurum', 'everest-forms'),
			'TR26' => __('Eskişehir', 'everest-forms'),
			'TR27' => __('Gaziantep', 'everest-forms'),
			'TR28' => __('Giresun', 'everest-forms'),
			'TR29' => __('Gümüşhane', 'everest-forms'),
			'TR30' => __('Hakkari', 'everest-forms'),
			'TR31' => __('Hatay', 'everest-forms'),
			'TR32' => __('Isparta', 'everest-forms'),
			'TR33' => __('İçel', 'everest-forms'),
			'TR34' => __('İstanbul', 'everest-forms'),
			'TR35' => __('İzmir', 'everest-forms'),
			'TR36' => __('Kars', 'everest-forms'),
			'TR37' => __('Kastamonu', 'everest-forms'),
			'TR38' => __('Kayseri', 'everest-forms'),
			'TR39' => __('Kırklareli', 'everest-forms'),
			'TR40' => __('Kırşehir', 'everest-forms'),
			'TR41' => __('Kocaeli', 'everest-forms'),
			'TR42' => __('Konya', 'everest-forms'),
			'TR43' => __('Kütahya', 'everest-forms'),
			'TR44' => __('Malatya', 'everest-forms'),
			'TR45' => __('Manisa', 'everest-forms'),
			'TR46' => __('Kahramanmaraş', 'everest-forms'),
			'TR47' => __('Mardin', 'everest-forms'),
			'TR48' => __('Muğla', 'everest-forms'),
			'TR49' => __('Muş', 'everest-forms'),
			'TR50' => __('Nevşehir', 'everest-forms'),
			'TR51' => __('Niğde', 'everest-forms'),
			'TR52' => __('Ordu', 'everest-forms'),
			'TR53' => __('Rize', 'everest-forms'),
			'TR54' => __('Sakarya', 'everest-forms'),
			'TR55' => __('Samsun', 'everest-forms'),
			'TR56' => __('Siirt', 'everest-forms'),
			'TR57' => __('Sinop', 'everest-forms'),
			'TR58' => __('Sivas', 'everest-forms'),
			'TR59' => __('Tekirdağ', 'everest-forms'),
			'TR60' => __('Tokat', 'everest-forms'),
			'TR61' => __('Trabzon', 'everest-forms'),
			'TR62' => __('Tunceli', 'everest-forms'),
			'TR63' => __('Şanlıurfa', 'everest-forms'),
			'TR64' => __('Uşak', 'everest-forms'),
			'TR65' => __('Van', 'everest-forms'),
			'TR66' => __('Yozgat', 'everest-forms'),
			'TR67' => __('Zonguldak', 'everest-forms'),
			'TR68' => __('Aksaray', 'everest-forms'),
			'TR69' => __('Bayburt', 'everest-forms'),
			'TR70' => __('Karaman', 'everest-forms'),
			'TR71' => __('Kırıkkale', 'everest-forms'),
			'TR72' => __('Batman', 'everest-forms'),
			'TR73' => __('Şırnak', 'everest-forms'),
			'TR74' => __('Bartın', 'everest-forms'),
			'TR75' => __('Ardahan', 'everest-forms'),
			'TR76' => __('Iğdır', 'everest-forms'),
			'TR77' => __('Yalova', 'everest-forms'),
			'TR78' => __('Karabük', 'everest-forms'),
			'TR79' => __('Kilis', 'everest-forms'),
			'TR80' => __('Osmaniye', 'everest-forms'),
			'TR81' => __('Düzce', 'everest-forms'),
		),
		'TZ' => array( // Tanzanian states.
			'TZ01' => __('Arusha', 'everest-forms'),
			'TZ02' => __('Dar es Salaam', 'everest-forms'),
			'TZ03' => __('Dodoma', 'everest-forms'),
			'TZ04' => __('Iringa', 'everest-forms'),
			'TZ05' => __('Kagera', 'everest-forms'),
			'TZ06' => __('Pemba North', 'everest-forms'),
			'TZ07' => __('Zanzibar North', 'everest-forms'),
			'TZ08' => __('Kigoma', 'everest-forms'),
			'TZ09' => __('Kilimanjaro', 'everest-forms'),
			'TZ10' => __('Pemba South', 'everest-forms'),
			'TZ11' => __('Zanzibar South', 'everest-forms'),
			'TZ12' => __('Lindi', 'everest-forms'),
			'TZ13' => __('Mara', 'everest-forms'),
			'TZ14' => __('Mbeya', 'everest-forms'),
			'TZ15' => __('Zanzibar West', 'everest-forms'),
			'TZ16' => __('Morogoro', 'everest-forms'),
			'TZ17' => __('Mtwara', 'everest-forms'),
			'TZ18' => __('Mwanza', 'everest-forms'),
			'TZ19' => __('Coast', 'everest-forms'),
			'TZ20' => __('Rukwa', 'everest-forms'),
			'TZ21' => __('Ruvuma', 'everest-forms'),
			'TZ22' => __('Shinyanga', 'everest-forms'),
			'TZ23' => __('Singida', 'everest-forms'),
			'TZ24' => __('Tabora', 'everest-forms'),
			'TZ25' => __('Tanga', 'everest-forms'),
			'TZ26' => __('Manyara', 'everest-forms'),
			'TZ27' => __('Geita', 'everest-forms'),
			'TZ28' => __('Katavi', 'everest-forms'),
			'TZ29' => __('Njombe', 'everest-forms'),
			'TZ30' => __('Simiyu', 'everest-forms'),
		),
		'LK' => array(),
		'RS' => array( // Serbian districts.
			'RS00' => _x('Belgrade', 'district', 'everest-forms'),
			'RS14' => _x('Bor', 'district', 'everest-forms'),
			'RS11' => _x('Braničevo', 'district', 'everest-forms'),
			'RS02' => _x('Central Banat', 'district', 'everest-forms'),
			'RS10' => _x('Danube', 'district', 'everest-forms'),
			'RS23' => _x('Jablanica', 'district', 'everest-forms'),
			'RS09' => _x('Kolubara', 'district', 'everest-forms'),
			'RS08' => _x('Mačva', 'district', 'everest-forms'),
			'RS17' => _x('Morava', 'district', 'everest-forms'),
			'RS20' => _x('Nišava', 'district', 'everest-forms'),
			'RS01' => _x('North Bačka', 'district', 'everest-forms'),
			'RS03' => _x('North Banat', 'district', 'everest-forms'),
			'RS24' => _x('Pčinja', 'district', 'everest-forms'),
			'RS22' => _x('Pirot', 'district', 'everest-forms'),
			'RS13' => _x('Pomoravlje', 'district', 'everest-forms'),
			'RS19' => _x('Rasina', 'district', 'everest-forms'),
			'RS18' => _x('Raška', 'district', 'everest-forms'),
			'RS06' => _x('South Bačka', 'district', 'everest-forms'),
			'RS04' => _x('South Banat', 'district', 'everest-forms'),
			'RS07' => _x('Srem', 'district', 'everest-forms'),
			'RS12' => _x('Šumadija', 'district', 'everest-forms'),
			'RS21' => _x('Toplica', 'district', 'everest-forms'),
			'RS05' => _x('West Bačka', 'district', 'everest-forms'),
			'RS15' => _x('Zaječar', 'district', 'everest-forms'),
			'RS16' => _x('Zlatibor', 'district', 'everest-forms'),
			'RS25' => _x('Kosovo', 'district', 'everest-forms'),
			'RS26' => _x('Peć', 'district', 'everest-forms'),
			'RS27' => _x('Prizren', 'district', 'everest-forms'),
			'RS28' => _x('Kosovska Mitrovica', 'district', 'everest-forms'),
			'RS29' => _x('Kosovo-Pomoravlje', 'district', 'everest-forms'),
			'RSKM' => _x('Kosovo-Metohija', 'district', 'everest-forms'),
			'RSVO' => _x('Vojvodina', 'district', 'everest-forms'),
		),
		'SE' => array(),
		'UA' => array( // Ukrainian oblasts.
			'VN' => __('Vinnytsia Oblast', 'everest-forms'),
			'VL' => __('Volyn Oblast', 'everest-forms'),
			'DP' => __('Dnipropetrovsk Oblast', 'everest-forms'),
			'DT' => __('Donetsk Oblast', 'everest-forms'),
			'ZT' => __('Zhytomyr Oblast', 'everest-forms'),
			'ZK' => __('Zakarpattia Oblast', 'everest-forms'),
			'ZP' => __('Zaporizhzhia Oblast', 'everest-forms'),
			'IF' => __('Ivano-Frankivsk Oblast', 'everest-forms'),
			'KV' => __('Kyiv Oblast', 'everest-forms'),
			'KH' => __('Kirovohrad Oblast', 'everest-forms'),
			'LH' => __('Luhansk Oblast', 'everest-forms'),
			'LV' => __('Lviv Oblast', 'everest-forms'),
			'MY' => __('Mykolaiv Oblast', 'everest-forms'),
			'OD' => __('Odessa Oblast', 'everest-forms'),
			'PL' => __('Poltava Oblast', 'everest-forms'),
			'RV' => __('Rivne Oblast', 'everest-forms'),
			'SM' => __('Sumy Oblast', 'everest-forms'),
			'TP' => __('Ternopil Oblast', 'everest-forms'),
			'KK' => __('Kharkiv Oblast', 'everest-forms'),
			'KS' => __('Kherson Oblast', 'everest-forms'),
			'KM' => __('Khmelnytskyi Oblast', 'everest-forms'),
			'CK' => __('Cherkasy Oblast', 'everest-forms'),
			'CH' => __('Chernihiv Oblast', 'everest-forms'),
			'CV' => __('Chernivtsi Oblast', 'everest-forms'),
		),
		'UG' => array( // Ugandan districts.
			'UG314' => __('Abim', 'everest-forms'),
			'UG301' => __('Adjumani', 'everest-forms'),
			'UG322' => __('Agago', 'everest-forms'),
			'UG323' => __('Alebtong', 'everest-forms'),
			'UG315' => __('Amolatar', 'everest-forms'),
			'UG324' => __('Amudat', 'everest-forms'),
			'UG216' => __('Amuria', 'everest-forms'),
			'UG316' => __('Amuru', 'everest-forms'),
			'UG302' => __('Apac', 'everest-forms'),
			'UG303' => __('Arua', 'everest-forms'),
			'UG217' => __('Budaka', 'everest-forms'),
			'UG218' => __('Bududa', 'everest-forms'),
			'UG201' => __('Bugiri', 'everest-forms'),
			'UG235' => __('Bugweri', 'everest-forms'),
			'UG420' => __('Buhweju', 'everest-forms'),
			'UG117' => __('Buikwe', 'everest-forms'),
			'UG219' => __('Bukedea', 'everest-forms'),
			'UG118' => __('Bukomansimbi', 'everest-forms'),
			'UG220' => __('Bukwa', 'everest-forms'),
			'UG225' => __('Bulambuli', 'everest-forms'),
			'UG416' => __('Buliisa', 'everest-forms'),
			'UG401' => __('Bundibugyo', 'everest-forms'),
			'UG430' => __('Bunyangabu', 'everest-forms'),
			'UG402' => __('Bushenyi', 'everest-forms'),
			'UG202' => __('Busia', 'everest-forms'),
			'UG221' => __('Butaleja', 'everest-forms'),
			'UG119' => __('Butambala', 'everest-forms'),
			'UG233' => __('Butebo', 'everest-forms'),
			'UG120' => __('Buvuma', 'everest-forms'),
			'UG226' => __('Buyende', 'everest-forms'),
			'UG317' => __('Dokolo', 'everest-forms'),
			'UG121' => __('Gomba', 'everest-forms'),
			'UG304' => __('Gulu', 'everest-forms'),
			'UG403' => __('Hoima', 'everest-forms'),
			'UG417' => __('Ibanda', 'everest-forms'),
			'UG203' => __('Iganga', 'everest-forms'),
			'UG418' => __('Isingiro', 'everest-forms'),
			'UG204' => __('Jinja', 'everest-forms'),
			'UG318' => __('Kaabong', 'everest-forms'),
			'UG404' => __('Kabale', 'everest-forms'),
			'UG405' => __('Kabarole', 'everest-forms'),
			'UG213' => __('Kaberamaido', 'everest-forms'),
			'UG427' => __('Kagadi', 'everest-forms'),
			'UG428' => __('Kakumiro', 'everest-forms'),
			'UG101' => __('Kalangala', 'everest-forms'),
			'UG222' => __('Kaliro', 'everest-forms'),
			'UG122' => __('Kalungu', 'everest-forms'),
			'UG102' => __('Kampala', 'everest-forms'),
			'UG205' => __('Kamuli', 'everest-forms'),
			'UG413' => __('Kamwenge', 'everest-forms'),
			'UG414' => __('Kanungu', 'everest-forms'),
			'UG206' => __('Kapchorwa', 'everest-forms'),
			'UG236' => __('Kapelebyong', 'everest-forms'),
			'UG126' => __('Kasanda', 'everest-forms'),
			'UG406' => __('Kasese', 'everest-forms'),
			'UG207' => __('Katakwi', 'everest-forms'),
			'UG112' => __('Kayunga', 'everest-forms'),
			'UG407' => __('Kibaale', 'everest-forms'),
			'UG103' => __('Kiboga', 'everest-forms'),
			'UG227' => __('Kibuku', 'everest-forms'),
			'UG432' => __('Kikuube', 'everest-forms'),
			'UG419' => __('Kiruhura', 'everest-forms'),
			'UG421' => __('Kiryandongo', 'everest-forms'),
			'UG408' => __('Kisoro', 'everest-forms'),
			'UG305' => __('Kitgum', 'everest-forms'),
			'UG319' => __('Koboko', 'everest-forms'),
			'UG325' => __('Kole', 'everest-forms'),
			'UG306' => __('Kotido', 'everest-forms'),
			'UG208' => __('Kumi', 'everest-forms'),
			'UG333' => __('Kwania', 'everest-forms'),
			'UG228' => __('Kween', 'everest-forms'),
			'UG123' => __('Kyankwanzi', 'everest-forms'),
			'UG422' => __('Kyegegwa', 'everest-forms'),
			'UG415' => __('Kyenjojo', 'everest-forms'),
			'UG125' => __('Kyotera', 'everest-forms'),
			'UG326' => __('Lamwo', 'everest-forms'),
			'UG307' => __('Lira', 'everest-forms'),
			'UG229' => __('Luuka', 'everest-forms'),
			'UG104' => __('Luwero', 'everest-forms'),
			'UG124' => __('Lwengo', 'everest-forms'),
			'UG114' => __('Lyantonde', 'everest-forms'),
			'UG223' => __('Manafwa', 'everest-forms'),
			'UG320' => __('Maracha', 'everest-forms'),
			'UG105' => __('Masaka', 'everest-forms'),
			'UG409' => __('Masindi', 'everest-forms'),
			'UG214' => __('Mayuge', 'everest-forms'),
			'UG209' => __('Mbale', 'everest-forms'),
			'UG410' => __('Mbarara', 'everest-forms'),
			'UG423' => __('Mitooma', 'everest-forms'),
			'UG115' => __('Mityana', 'everest-forms'),
			'UG308' => __('Moroto', 'everest-forms'),
			'UG309' => __('Moyo', 'everest-forms'),
			'UG106' => __('Mpigi', 'everest-forms'),
			'UG107' => __('Mubende', 'everest-forms'),
			'UG108' => __('Mukono', 'everest-forms'),
			'UG334' => __('Nabilatuk', 'everest-forms'),
			'UG311' => __('Nakapiripirit', 'everest-forms'),
			'UG116' => __('Nakaseke', 'everest-forms'),
			'UG109' => __('Nakasongola', 'everest-forms'),
			'UG230' => __('Namayingo', 'everest-forms'),
			'UG234' => __('Namisindwa', 'everest-forms'),
			'UG224' => __('Namutumba', 'everest-forms'),
			'UG327' => __('Napak', 'everest-forms'),
			'UG310' => __('Nebbi', 'everest-forms'),
			'UG231' => __('Ngora', 'everest-forms'),
			'UG424' => __('Ntoroko', 'everest-forms'),
			'UG411' => __('Ntungamo', 'everest-forms'),
			'UG328' => __('Nwoya', 'everest-forms'),
			'UG331' => __('Omoro', 'everest-forms'),
			'UG329' => __('Otuke', 'everest-forms'),
			'UG321' => __('Oyam', 'everest-forms'),
			'UG312' => __('Pader', 'everest-forms'),
			'UG332' => __('Pakwach', 'everest-forms'),
			'UG210' => __('Pallisa', 'everest-forms'),
			'UG110' => __('Rakai', 'everest-forms'),
			'UG429' => __('Rubanda', 'everest-forms'),
			'UG425' => __('Rubirizi', 'everest-forms'),
			'UG431' => __('Rukiga', 'everest-forms'),
			'UG412' => __('Rukungiri', 'everest-forms'),
			'UG111' => __('Sembabule', 'everest-forms'),
			'UG232' => __('Serere', 'everest-forms'),
			'UG426' => __('Sheema', 'everest-forms'),
			'UG215' => __('Sironko', 'everest-forms'),
			'UG211' => __('Soroti', 'everest-forms'),
			'UG212' => __('Tororo', 'everest-forms'),
			'UG113' => __('Wakiso', 'everest-forms'),
			'UG313' => __('Yumbe', 'everest-forms'),
			'UG330' => __('Zombo', 'everest-forms'),
		),
		'UM' => array(
			'81' => __('Baker Island', 'everest-forms'),
			'84' => __('Howland Island', 'everest-forms'),
			'86' => __('Jarvis Island', 'everest-forms'),
			'67' => __('Johnston Atoll', 'everest-forms'),
			'89' => __('Kingman Reef', 'everest-forms'),
			'71' => __('Midway Atoll', 'everest-forms'),
			'76' => __('Navassa Island', 'everest-forms'),
			'95' => __('Palmyra Atoll', 'everest-forms'),
			'79' => __('Wake Island', 'everest-forms'),
		),
		'US' => array( // U.S. states.
			'AL' => __('Alabama', 'everest-forms'),
			'AK' => __('Alaska', 'everest-forms'),
			'AZ' => __('Arizona', 'everest-forms'),
			'AR' => __('Arkansas', 'everest-forms'),
			'CA' => __('California', 'everest-forms'),
			'CO' => __('Colorado', 'everest-forms'),
			'CT' => __('Connecticut', 'everest-forms'),
			'DE' => __('Delaware', 'everest-forms'),
			'DC' => __('District Of Columbia', 'everest-forms'),
			'FL' => __('Florida', 'everest-forms'),
			'GA' => _x('Georgia', 'US state of Georgia', 'everest-forms'),
			'HI' => __('Hawaii', 'everest-forms'),
			'ID' => __('Idaho', 'everest-forms'),
			'IL' => __('Illinois', 'everest-forms'),
			'IN' => __('Indiana', 'everest-forms'),
			'IA' => __('Iowa', 'everest-forms'),
			'KS' => __('Kansas', 'everest-forms'),
			'KY' => __('Kentucky', 'everest-forms'),
			'LA' => __('Louisiana', 'everest-forms'),
			'ME' => __('Maine', 'everest-forms'),
			'MD' => __('Maryland', 'everest-forms'),
			'MA' => __('Massachusetts', 'everest-forms'),
			'MI' => __('Michigan', 'everest-forms'),
			'MN' => __('Minnesota', 'everest-forms'),
			'MS' => __('Mississippi', 'everest-forms'),
			'MO' => __('Missouri', 'everest-forms'),
			'MT' => __('Montana', 'everest-forms'),
			'NE' => __('Nebraska', 'everest-forms'),
			'NV' => __('Nevada', 'everest-forms'),
			'NH' => __('New Hampshire', 'everest-forms'),
			'NJ' => __('New Jersey', 'everest-forms'),
			'NM' => __('New Mexico', 'everest-forms'),
			'NY' => __('New York', 'everest-forms'),
			'NC' => __('North Carolina', 'everest-forms'),
			'ND' => __('North Dakota', 'everest-forms'),
			'OH' => __('Ohio', 'everest-forms'),
			'OK' => __('Oklahoma', 'everest-forms'),
			'OR' => __('Oregon', 'everest-forms'),
			'PA' => __('Pennsylvania', 'everest-forms'),
			'RI' => __('Rhode Island', 'everest-forms'),
			'SC' => __('South Carolina', 'everest-forms'),
			'SD' => __('South Dakota', 'everest-forms'),
			'TN' => __('Tennessee', 'everest-forms'),
			'TX' => __('Texas', 'everest-forms'),
			'UT' => __('Utah', 'everest-forms'),
			'VT' => __('Vermont', 'everest-forms'),
			'VA' => __('Virginia', 'everest-forms'),
			'WA' => __('Washington', 'everest-forms'),
			'WV' => __('West Virginia', 'everest-forms'),
			'WI' => __('Wisconsin', 'everest-forms'),
			'WY' => __('Wyoming', 'everest-forms'),
			'AA' => __('Armed Forces (AA)', 'everest-forms'),
			'AE' => __('Armed Forces (AE)', 'everest-forms'),
			'AP' => __('Armed Forces (AP)', 'everest-forms'),
		),
		'UY' => array( // Uruguayan states.
			'UY-AR' => __('Artigas', 'everest-forms'),
			'UY-CA' => __('Canelones', 'everest-forms'),
			'UY-CL' => __('Cerro Largo', 'everest-forms'),
			'UY-CO' => __('Colonia', 'everest-forms'),
			'UY-DU' => __('Durazno', 'everest-forms'),
			'UY-FS' => __('Flores', 'everest-forms'),
			'UY-FD' => __('Florida', 'everest-forms'),
			'UY-LA' => __('Lavalleja', 'everest-forms'),
			'UY-MA' => __('Maldonado', 'everest-forms'),
			'UY-MO' => __('Montevideo', 'everest-forms'),
			'UY-PA' => __('Paysandú', 'everest-forms'),
			'UY-RN' => __('Río Negro', 'everest-forms'),
			'UY-RV' => __('Rivera', 'everest-forms'),
			'UY-RO' => __('Rocha', 'everest-forms'),
			'UY-SA' => __('Salto', 'everest-forms'),
			'UY-SJ' => __('San José', 'everest-forms'),
			'UY-SO' => __('Soriano', 'everest-forms'),
			'UY-TA' => __('Tacuarembó', 'everest-forms'),
			'UY-TT' => __('Treinta y Tres', 'everest-forms'),
		),
		'VE' => array( // Venezuelan states.
			'VE-A' => __('Capital', 'everest-forms'),
			'VE-B' => __('Anzoátegui', 'everest-forms'),
			'VE-C' => __('Apure', 'everest-forms'),
			'VE-D' => __('Aragua', 'everest-forms'),
			'VE-E' => __('Barinas', 'everest-forms'),
			'VE-F' => __('Bolívar', 'everest-forms'),
			'VE-G' => __('Carabobo', 'everest-forms'),
			'VE-H' => __('Cojedes', 'everest-forms'),
			'VE-I' => __('Falcón', 'everest-forms'),
			'VE-J' => __('Guárico', 'everest-forms'),
			'VE-K' => __('Lara', 'everest-forms'),
			'VE-L' => __('Mérida', 'everest-forms'),
			'VE-M' => __('Miranda', 'everest-forms'),
			'VE-N' => __('Monagas', 'everest-forms'),
			'VE-O' => __('Nueva Esparta', 'everest-forms'),
			'VE-P' => __('Portuguesa', 'everest-forms'),
			'VE-R' => __('Sucre', 'everest-forms'),
			'VE-S' => __('Táchira', 'everest-forms'),
			'VE-T' => __('Trujillo', 'everest-forms'),
			'VE-U' => __('Yaracuy', 'everest-forms'),
			'VE-V' => __('Zulia', 'everest-forms'),
			'VE-W' => __('Federal Dependencies', 'everest-forms'),
			'VE-X' => __('La Guaira (Vargas)', 'everest-forms'),
			'VE-Y' => __('Delta Amacuro', 'everest-forms'),
			'VE-Z' => __('Amazonas', 'everest-forms'),
		),
		'VN' => array(),
		'YT' => array(),
		'ZA' => array( // South African states.
			'EC'  => __('Eastern Cape', 'everest-forms'),
			'FS'  => __('Free State', 'everest-forms'),
			'GP'  => __('Gauteng', 'everest-forms'),
			'KZN' => __('KwaZulu-Natal', 'everest-forms'),
			'LP'  => __('Limpopo', 'everest-forms'),
			'MP'  => __('Mpumalanga', 'everest-forms'),
			'NC'  => __('Northern Cape', 'everest-forms'),
			'NW'  => __('North West', 'everest-forms'),
			'WC'  => __('Western Cape', 'everest-forms'),
		),
		'ZM' => array( // Zambian provinces.
			'ZM-01' => __('Western', 'everest-forms'),
			'ZM-02' => __('Central', 'everest-forms'),
			'ZM-03' => __('Eastern', 'everest-forms'),
			'ZM-04' => __('Luapula', 'everest-forms'),
			'ZM-05' => __('Northern', 'everest-forms'),
			'ZM-06' => __('North-Western', 'everest-forms'),
			'ZM-07' => __('Southern', 'everest-forms'),
			'ZM-08' => __('Copperbelt', 'everest-forms'),
			'ZM-09' => __('Lusaka', 'everest-forms'),
			'ZM-10' => __('Muchinga', 'everest-forms'),
		),
	);

	return (array) apply_filters('everest_forms_states', $states);
}

/**
 * Get builder fields groups.
 *
 * @return array
 */
function evf_get_fields_groups()
{
	return (array) apply_filters(
		'everest_forms_builder_fields_groups',
		array(
			'general'  => __('General Fields', 'everest-forms'),
			'advanced' => __('Advanced Fields', 'everest-forms'),
			'payment'  => __('Payment Fields', 'everest-forms'),
			'survey'   => __('Survey Fields', 'everest-forms'),
		)
	);
}

/**
 * Get a builder fields type's name.
 *
 * @param string $type Coupon type.
 * @return string
 */
function evf_get_fields_group($type = '')
{
	$types = evf_get_fields_groups();
	return isset($types[$type]) ? $types[$type] : '';
}

/**
 * Determine whether a field type is "locked" for the current site.
 *
 * A locked field is a registered Pro/addon field that the user cannot
 * currently use (no license or required addon inactive). Locked fields are
 * shown in the builder for upsell (with all settings visible but not editable)
 * and are excluded from the published form until unlocked. The state is
 * derived, never stored, so fields auto-unlock once the addon/license becomes
 * available - no data migration required.
 *
 * @since 3.2.0
 *
 * @param string $type Field type slug (e.g. 'signature').
 * @return bool True when the field type is locked.
 */
function evf_is_field_locked($type)
{
	$locked = in_array($type, evf()->form_fields->get_pro_form_field_types(), true);

	/**
	 * Filter whether a given field type is locked.
	 *
	 * @since 3.2.0
	 *
	 * @param bool   $locked Whether the field type is locked.
	 * @param string $type   Field type slug.
	 */
	return (bool) apply_filters('everest_forms_is_field_locked', $locked, $type);
}

/**
 * Return the addon slug required by a field if that addon is currently inactive.
 *
 * This covers a gap that evf_is_field_locked() cannot: when EVF Pro is active it
 * registers the full field class (without is_pro), so the field is no longer
 * plan-locked — but the specific addon the field depends on (e.g. Stripe for the
 * credit-card field) may not be installed. In that case the builder preview renders
 * blank, giving the user no feedback. This function detects that state so the
 * builder can show the ADD-ON badge and a proper "Activate add-on" CTA.
 *
 * @since 3.4.6
 *
 * @param string $type Field type slug (e.g. 'credit-card').
 * @return string Addon slug (e.g. 'everest-forms-stripe') when the field needs an
 *               inactive addon, or empty string when no addon is required / addon
 *               is already active.
 */
function evf_field_inactive_addon( $type ) {
	// Pro/addons declare field→addon dependencies here when the field class
	// itself doesn't register while the addon is absent (e.g. credit-card
	// requires Stripe, but the class bails in its constructor when Stripe is
	// inactive, so it never appears in form_fields()).
	$requirements = apply_filters( 'everest_forms_field_addon_requirements', array() );
	if ( isset( $requirements[ $type ] ) ) {
		$addon       = $requirements[ $type ];
		$plugin_file = $addon . '/' . $addon . '.php';
		return is_plugin_active( $plugin_file ) ? '' : $addon;
	}

	// Fallback: field registered itself in the registry with an addon property.
	foreach ( evf()->form_fields->form_fields() as $group ) {
		foreach ( $group as $field_obj ) {
			if ( $field_obj->type === $type ) {
				if ( empty( $field_obj->addon ) ) {
					return '';
				}
				$plugin_file = $field_obj->addon . '/' . $field_obj->addon . '.php';
				return is_plugin_active( $plugin_file ) ? '' : $field_obj->addon;
			}
		}
	}
	return '';
}

/**
 * Get all fields settings.
 *
 * @return array Settings data.
 */
function evf_get_all_fields_settings()
{
	$settings = array(
		'label'         => array(
			'id'       => 'label',
			'title'    => __('Label', 'everest-forms'),
			'desc'     => __('Enter text for the form field label. This is recommended and can be hidden in the Advanced Settings.', 'everest-forms'),
			'default'  => '',
			'type'     => 'text',
			'desc_tip' => true,
		),
		'meta'          => array(
			'id'       => 'meta-key',
			'title'    => __('Meta Key', 'everest-forms'),
			'desc'     => __('Enter meta key to be stored in database.', 'everest-forms'),
			'default'  => '',
			'type'     => 'text',
			'desc_tip' => true,
		),
		'description'   => array(
			'id'       => 'description',
			'title'    => __('Description', 'everest-forms'),
			'type'     => 'textarea',
			'desc'     => __('Enter text for the form field description.', 'everest-forms'),
			'default'  => '',
			'desc_tip' => true,
		),
		'required'      => array(
			'id'       => 'require',
			'title'    => __('Required', 'everest-forms'),
			'type'     => 'checkbox',
			'desc'     => __('Check this option to mark the field required.', 'everest-forms'),
			'default'  => 'no',
			'desc_tip' => true,
		),
		'choices'       => array(
			'id'       => 'choices',
			'title'    => __('Choices', 'everest-forms'),
			'desc'     => __('Add choices for the form field.', 'everest-forms'),
			'type'     => 'choices',
			'desc_tip' => true,
			'defaults' => array(
				1 => __('First Choice', 'everest-forms'),
				2 => __('Second Choice', 'everest-forms'),
				3 => __('Third Choice', 'everest-forms'),
			),
		),
		'placeholder'   => array(
			'id'       => 'placeholder',
			'title'    => __('Placeholder Text', 'everest-forms'),
			'desc'     => __('Enter text for the form field placeholder.', 'everest-forms'),
			'default'  => '',
			'type'     => 'text',
			'desc_tip' => true,
		),
		'css'           => array(
			'id'       => 'css',
			'title'    => __('CSS Classes', 'everest-forms'),
			'desc'     => __('Enter CSS class for this field container. Class names should be separated with spaces.', 'everest-forms'),
			'default'  => '',
			'type'     => 'text',
			'desc_tip' => true,
		),
		'label_hide'    => array(
			'id'       => 'label_hide',
			'title'    => __('Hide Label', 'everest-forms'),
			'type'     => 'checkbox',
			'desc'     => __('Check this option to hide the form field label.', 'everest-forms'),
			'default'  => 'no',
			'desc_tip' => true,
		),
		'sublabel_hide' => array(
			'id'       => 'sublabel_hide',
			'title'    => __('Hide Sub-Labels', 'everest-forms'),
			'type'     => 'checkbox',
			'desc'     => __('Check this option to hide the form field sub-label.', 'everest-forms'),
			'default'  => 'no',
			'desc_tip' => true,
		),
	);

	return apply_filters('everest_form_all_fields_settings', $settings);
}

/**
 * Helper function to display debug data.
 *
 * @since 1.3.2
 *
 * @param mixed $expression The expression to be printed.
 * @param bool  $return     Optional. Default false. Set to true to return the human-readable string.
 *
 * @return string
 */
function evf_debug_data($expression, $return = false)
{
	if (defined('EVF_DEBUG') && true === EVF_DEBUG) {

		if (! $return) {
			echo '<textarea style="color:#666;background:#fff;margin: 20px 0;width:100%;height:500px;font-size:12px;font-family: Consolas,Monaco,Lucida Console,monospace;direction: ltr;unicode-bidi: embed;line-height: 1.4;padding: 4px 6px 1px;" readonly>';

			echo "==================== Everest Forms Debugging ====================\n\n";

			if (is_array($expression) || is_object($expression)) {
				echo esc_html(evf_print_r($expression, true));
			} else {
				echo esc_html($expression);
			}
			echo '</textarea>';
		} else {
			$output = '<textarea style="color:#666;background:#fff;margin: 20px 0;width:100%;height:500px;font-size:12px;font-family: Consolas,Monaco,Lucida Console,monospace;direction: ltr;unicode-bidi: embed;line-height: 1.4;padding: 4px 6px 1px;" readonly>';

			$output .= "==================== Everest Forms Debugging ====================\n\n";

			if (is_array($expression) || is_object($expression)) {
				$output .= evf_print_r($expression, true);
			} else {
				$output .= $expression;
			}

			$output .= '</textarea>';

			return $output;
		}
	}
}

/**
 * String translation function.
 *
 * @since 1.4.9
 *
 * @param int    $form_id Form ID.
 * @param string $field_id Field ID.
 * @param mixed  $value The string that needs to be translated.
 * @param string $suffix The suffix to make the field have unique naem.
 *
 * @return mixed The translated string.
 */
function evf_string_translation($form_id, $field_id, $value, $suffix = '')
{
	$context = isset($form_id) ? 'everest_forms_' . absint($form_id) : 0;
	$name    = isset($field_id) ? evf_clean($field_id . $suffix) : '';

	if (function_exists('icl_register_string')) {
		icl_register_string($context, $name, $value);
	}

	if (function_exists('icl_t')) {
		$value = icl_t($context, $name, $value);
	}

	return $value;
}

/**
 * Trigger logging cleanup using the logging class.
 *
 * @since 1.6.2
 */
function evf_cleanup_logs()
{
	$logger = evf_get_logger();

	if (is_callable(array($logger, 'clear_expired_logs'))) {
		$logger->clear_expired_logs();
	}
}
add_action('everest_forms_cleanup_logs', 'evf_cleanup_logs');


/**
 * Check whether it device is table or not from HTTP user agent
 *
 * @since 1.7.0
 *
 * @return bool
 */
function evf_is_tablet()
{
	return false !== stripos(evf_get_user_agent(), 'tablet') || false !== stripos(evf_get_user_agent(), 'tab');
}

/**
 * Get user device from user agent from HTTP user agent.
 *
 * @since 1.7.0
 *
 * @return string
 */
function evf_get_user_device()
{
	if (evf_is_tablet()) {
		return esc_html__('Tablet', 'everest-forms');
	} elseif (wp_is_mobile()) {
		return esc_html__('Mobile', 'everest-forms');
	} else {
		return esc_html__('Desktop', 'everest-forms');
	}
}


/**
 * A wp_parse_args() for multi-dimensional array.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_parse_args/
 *
 * @since 1.7.0
 *
 * @param array $args       Value to merge with $defaults.
 * @param array $defaults   Array that serves as the defaults.
 *
 * @return array    Merged user defined values with defaults.
 */
function evf_parse_args(&$args, $defaults)
{
	$args     = (array) $args;
	$defaults = (array) $defaults;
	$result   = $defaults;
	foreach ($args as $k => &$v) {
		if (is_array($v) && isset($result[$k])) {
			$result[$k] = evf_parse_args($v, $result[$k]);
		} else {
			$result[$k] = $v;
		}
	}
	return $result;
}

/**
 * Get date of ranges.
 *
 * @since 1.7.0
 *
 * @param string $first Starting date.
 * @param string $last  End date.
 * @param string $step Date step.
 * @param string $format Date format.
 *
 * @return array Range dates.
 */
function evf_date_range($first, $last = '', $step = '+1 day', $format = 'Y/m/d')
{
	$dates   = array();
	$current = strtotime($first);
	$last    = strtotime($last);

	while ($current <= $last) {
		$dates[] = date_i18n($format, $current);
		$current = strtotime($step, $current);
	}

	return $dates;
}

/**
 * Process syntaxes in a text.
 *
 * @since 1.7.0
 *
 * @param string $text Text to be processed.
 * @param bool   $escape_html Whether to escape all the htmls before processing or not.
 * @param bool   $trim_trailing_spaces Whether to trim trailing spaces or not.
 *
 * @return string Processed text.
 */
function evf_process_syntaxes($text, $escape_html = true, $trim_trailing_spaces = true)
{

	if (true === $trim_trailing_spaces) {
		$text = trim($text);
	}
	if (true === $escape_html) {
		$text = esc_html($text);
	}
	$text = evf_process_hyperlink_syntax($text);
	$text = evf_process_italic_syntax($text);
	$text = evf_process_bold_syntax($text);
	$text = evf_process_underline_syntax($text);
	$text = evf_process_line_breaks($text);
	return $text;
}

/**
 * Extract page ids from a text.
 *
 * @since 1.7.0
 *
 * @param string $text Text to extract page ids from.
 *
 * @return mixed
 */
function evf_extract_page_ids($text)
{
	$page_id_syntax_matches = array();
	$page_ids               = array();

	while (preg_match('/page_id=([0-9]+)/', $text, $page_id_syntax_matches)) {
		$page_id    = $page_id_syntax_matches[1];
		$page_ids[] = $page_id;
		$text       = str_replace('page_id=' . $page_id, '', $text);
	}

	if (count($page_ids) > 0) {
		return $page_ids;
	}
	return false;
}

/**
 * Process hyperlink syntaxes in a text.
 * The syntax used for hyperlink is: [Link Label](Link URL)
 * Example: [Google Search Page](https://google.com)
 *
 * @since 1.7.0
 *
 * @param string $text         Text to process.
 * @param string $use_no_a_tag If set to `true` only the link will be used and no `a` tag. Particularly useful for exporting CSV,
 *                             as the html tags are escaped in a CSV file.
 *
 * @return string Processed text.
 */
function evf_process_hyperlink_syntax($text, $use_no_a_tag = false)
{
	$matches = array();
	$regex   = '/(\[[^\[\]]*\])(\([^\(\)]*\))/';

	while (preg_match($regex, $text, $matches)) {
		$matched_string = $matches[0];
		$label          = $matches[1];
		$link           = $matches[2];
		$class          = '';
		$page_id        = '';

		// Trim brackets.
		$label = trim(substr($label, 1, -1));
		$link  = trim(substr($link, 1, -1));

		// Proceed only if label or link is not empty.
		if (! empty($label) || ! empty($link)) {

			// Use hash(#) if the link is empty.
			if (empty($link)) {
				$link = '#';
			}

			// Use link as label if it's empty.
			if (empty($label)) {
				$label = $link;
			}

			// See if it's a link to a local page.
			if (strpos($link, '?') === 0) {
				$class .= ' evf-privacy-policy-local-page-link';

				// Extract page id.
				$page_ids = evf_extract_page_ids($link);

				if (false !== $page_ids) {
					$page_id = $page_ids[0];
					$link    = get_page_link($page_id);

					if (empty($link)) {
						$link = '#';
					}
				}
			}

			// Insert hyperlink html.
			if (true === $use_no_a_tag) {
				$html = $link;
			} else {
				$html = sprintf('<a data-page-id="%s" target="_blank" rel="noopener noreferrer nofollow" href="%s" class="%s">%s</a>', $page_id, $link, $class, $label);
			}
			$text = str_replace($matched_string, $html, $text);
		} else {
			// If both label and link are empty then replace it with empty string.
			$text = str_replace($matched_string, '', $text);
		}
	}

	return $text;
}

/**
 * Process italic syntaxes in a text.
 * The syntax used for italic text is: `text`
 * Just wrap the text with back tick characters. To escape a backtick insert a backslash(\) before the character like "\`".
 *
 * @since 1.7.0
 *
 * @param string $text Text to process.
 *
 * @return string Processed text.
 */
function evf_process_italic_syntax($text)
{
	$matches = array();
	$regex   = '/`[^`]+`/';
	$text    = str_replace('\`', '<&&&&&>', $text); // To preserve an escaped special character '`'.

	while (preg_match($regex, $text, $matches)) {
		$matched_string = $matches[0];
		$label          = substr(trim($matched_string), 1, -1);
		$html           = sprintf('<i>%s</i>', $label);
		$text           = str_replace($matched_string, $html, $text);
	}

	return str_replace('<&&&&&>', '`', $text);
}

/**
 * Process bold syntaxes in a text.
 * The syntax used for bold text is: *text*
 * Just wrap the text with asterisk characters. To escape an asterisk insert a backslash(\) before the character like "\*".
 *
 * @since 1.7.0
 *
 * @param string $text Text to process.
 *
 * @return string Processed text.
 */
function evf_process_bold_syntax($text)
{
	$matches = array();
	$regex   = '/\*[^*]+\*/';
	$text    = str_replace('\*', '<&&&&&>', $text); // To preserve an escaped special character '*'.

	while (preg_match($regex, $text, $matches)) {
		$matched_string = $matches[0];
		$label          = substr(trim($matched_string), 1, -1);
		$html           = sprintf('<b>%s</b>', $label);
		$text           = str_replace($matched_string, $html, $text);
	}

	return str_replace('<&&&&&>', '*', $text);
}

/**
 * Process underline syntaxes in a text.
 * The syntax used for bold text is: __text__
 * Wrap the text with double underscore characters. To escape an underscore insert a backslash(\) before the character like "\_".
 *
 * @since 1.7.0
 *
 * @param string $text Text to process.
 *
 * @return string Processed text.
 */
function evf_process_underline_syntax($text)
{
	$matches = array();
	$regex   = '/__[^_]+__/';
	$text    = str_replace('\_', '<&&&&&>', $text); // To preserve an escaped special character '_'.

	while (preg_match($regex, $text, $matches)) {
		$matched_string = $matches[0];
		$label          = substr(trim($matched_string), 2, -2);
		$html           = sprintf('<u>%s</u>', $label);
		$text           = str_replace($matched_string, $html, $text);
	}

	$text = str_replace('<&&&&&>', '_', $text);
	return $text;
}

/**
 * It replaces `\n` characters with `<br/>` tag because new line `\n` character is not supported in html.
 *
 * @since 1.7.0
 *
 * @param string $text Text to process.
 *
 * @return string Processed text.
 */
function evf_process_line_breaks($text)
{
	return str_replace("\n", '<br/>', $text);
}

/**
 * Check whether the current page is in AMP mode or not.
 * We need to check for specific functions, as there is no special AMP header.
 *
 * @since 1.8.4
 *
 * @param bool $check_theme_support Whether theme support should be checked. Defaults to true.
 *
 * @return bool
 */
function evf_is_amp($check_theme_support = true)
{

	$is_amp = false;

	if (
		// AMP by Automattic.
		(function_exists('amp_is_request') && amp_is_request()) ||
		// Better AMP.
		(function_exists('is_better_amp') && is_better_amp())
	) {
		$is_amp = true;
	}

	if ($is_amp && $check_theme_support) {
		$is_amp = current_theme_supports('amp');
	}

	return apply_filters('evf_is_amp', $is_amp);
}

/**
 * EVF KSES.
 *
 * @since 1.8.2.1
 *
 * @param string $context Context.
 */
function evf_get_allowed_html_tags($context = '')
{

	$post_tags = wp_kses_allowed_html('post');
	if ('builder' === $context) {
		$builder_tags = get_transient('evf-builder-tags-list');
		if (! empty($builder_tags)) {
			return $builder_tags;
		}
		$allowed_tags = evf_get_json_file_contents('assets/allowed_tags/allowed_tags.json', true);
		if (! empty($allowed_tags)) {
			foreach ($allowed_tags as $tag => $args) {
				if (array_key_exists($tag, $post_tags)) {
					foreach ($args as $arg => $value) {
						if (! array_key_exists($arg, $post_tags[$tag])) {
							$post_tags[$tag][$arg] = true;
						}
					}
				} else {
					$post_tags[$tag] = $args;
				}
			}
			set_transient('evf-builder-tags-list', $post_tags, DAY_IN_SECONDS);
		}
		return $post_tags;
	}

	return wp_parse_args(
		$post_tags,
		array(
			'input'    => array(
				'type'  => true,
				'name'  => true,
				'value' => true,
			),
			'select'   => array(
				'name' => true,
				'id'   => true,
			),
			'option'   => array(
				'value'    => true,
				'selected' => true,
			),
			'textarea' => array(
				'style' => true,
			),
		)
	);
}

/**
 * Parse Builder Post Data.
 *
 * @param mixed $post_data Post Data.
 *
 * @since 1.8.2.2
 */
function evf_sanitize_builder($post_data = array())
{

	if (empty($post_data) || ! is_array($post_data)) {
		return array();
	}

	$form_data = array();
	foreach ( $post_data as $data_key => $data ) {
		$name = sanitize_text_field( $data->name );
		if ( preg_match( '/\<.*\>/', $data->value ) ) {
			$value = wp_kses_post( $data->value );
		} elseif ( 'settings[external_url]' === $data->name ) {
			$value = esc_url_raw( $data->value );
		} elseif ( preg_match( '/evf_email_message/', $data->name ) || preg_match( '/telegram_message/', $data->name ) || preg_match( '/slack_message/', $data->name ) || preg_match( '/user_registration_email_.*_message/', $data->name ) ) {
			$value = wp_kses_post( $data->value );
		} elseif ( preg_match( '/calculation_field/', $data->name ) ) {
			$value = wp_kses_post( $data->value );
		} elseif ( preg_match( '/successful_form_submission_message/', $data->name ) ) {
			$value = wp_kses_post( $data->value );
		} elseif ( preg_match( '/meta-key/', $data->name ) ) {
			$value = sanitize_key( $data->value );
		} else {
			$value = sanitize_text_field($data->value);
		}

		$form_data[sanitize_text_field($data_key)] = (object) array(
			'name'  => $name,
			'value' => $value,
		);
	}
	return $form_data;
}

/**
 * Entry Post Data.
 *
 * @param mixed $entry Post Data.
 *
 * @since 1.8.2.2
 */
function evf_sanitize_entry($entry = array())
{
	if (empty($entry) || ! is_array($entry) || empty($entry['form_fields'])) {
		return $entry;
	}

	$form_id   = absint($entry['id']);
	$form_data = evf()->form->get($form_id, array('contents_only' => true));

	if (! $form_data) {
		return array();
	}

	$form_data = evf_decode($form_data->post_content);

	$form_fields = $form_data['form_fields'];

	if (empty($form_fields)) {
		return array();
	}

	foreach ($form_fields as $key => $field) {
		$key = sanitize_text_field($key);
		if (array_key_exists($key, $entry['form_fields'])) {
			switch ($field['type']) {
				case 'email':
					if (isset($entry['form_fields'][$key]['primary'])) {
						$entry['form_fields'][$key]['primary']   = sanitize_email($entry['form_fields'][$key]['primary']);
						$entry['form_fields'][$key]['secondary'] = sanitize_email($entry['form_fields'][$key]['secondary']);
					} else {
						$entry['form_fields'][$key] = sanitize_email($entry['form_fields'][$key]);
					}
					break;
				case 'file-upload':
				case 'signature':
				case 'image-upload':
					if ( is_array( $entry['form_fields'][ $key ] ) ) {
						$entry['form_fields'][ $key ] = array();
					} else {
						$entry['form_fields'][ $key ] = esc_url_raw( $entry['form_fields'][ $key ] );
					}
					break;
				case 'textarea':
				case 'html':
				case 'privacy-policy':
				case 'wysiwug':
					$entry['form_fields'][$key] = wp_kses_post($entry['form_fields'][$key]);
					break;
				case 'repeater-fields':
					$entry['form_fields'][$key] = $entry['form_fields'][$key];
					break;
				default:
					if (is_array($entry['form_fields'][$key])) {
						foreach ($entry['form_fields'][$key] as $field_key => $value) {
							$field_key                                  = sanitize_text_field($field_key);
							$entry['form_fields'][$key][$field_key] = sanitize_text_field($value);
						}
					} else {
						$entry['form_fields'][$key] = sanitize_text_field($entry['form_fields'][$key]);
					}
			}
		}
		return $entry;
	}
}

/**
 * EVF Get json file contents.
 *
 * @param mixed $file File path.
 * @param mixed $to_array Returned data in array.
 */
function evf_get_json_file_contents($file, $to_array = false)
{
	if ($to_array) {
		return json_decode(evf_file_get_contents($file), true);
	}
	return json_decode(evf_file_get_contents($file));
}

/**
 * EVF file get contents.
 *
 * @param mixed $file File path.
 */
function evf_file_get_contents($file)
{
	if ($file) {
		$local_file = preg_replace('/\\\\|\/\//', '/', plugin_dir_path(EVF_PLUGIN_FILE) . $file);
		$response   = file_get_contents($local_file);
		if ($response) {
			return $response;
		}
		global $wp_filesystem;
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
		$local_file = preg_replace('/\\\\|\/\//', '/', plugin_dir_path(EVF_PLUGIN_FILE) . $file);
		if ($wp_filesystem->exists($local_file)) {
			$response = $wp_filesystem->get_contents($local_file);
			return $response;
		}
	}
	return;
}

/**
 * Parses datetime values based on the provided format and mode.
 *
 * @param string $datetime_value   The datetime value to parse.
 * @param string $datetime_format  The format of the datetime value.
 * @param string $date_format      The format of the date.
 * @param string $mode             The mode of the datetime field.
 * @param int    $time_interval    The time interval in minutes.
 * @param int    $entry_id         The entry id.
 * @return array
 */
function parse_datetime_values($datetime_value, $datetime_format, $date_format, $mode, $time_interval, $entry_id = 0)
{
	$datetime_arr = array();

	switch ($datetime_format) {
		case 'time':
			$current_date   = gmdate('Y-m-d');
			$datetime_value = gmdate('H:i', strtotime($datetime_value));
			$datetime_start = "$current_date $datetime_value";
			$date_time      = new DateTime($datetime_start);
			$date_time->modify("+$time_interval minute");
			$datetime_end              = $date_time->format('Y-m-d H:i');
			$datetime_arr[$entry_id] = array($datetime_start, $datetime_end);
			break;
		case 'date':
			if ('range' === $mode) {
				$datetime_value = apply_filters('everest_forms_time_date_format', $datetime_value);
				$selected_dates = explode(' to ', $datetime_value);
				if (count($selected_dates) >= 2) {
					if (count($selected_dates) >= 2) {
						$start_date = DateTime::createFromFormat($date_format, $selected_dates[0]);
						if ($start_date === false) {
							evf_get_logger()->debug(print_r("Invalid start date format: {$selected_dates[0]}", true));
						}
						$start_date->setTime(0, 0);
						$datetime_start = $start_date->format('Y-m-d H:i');

						$end_date = DateTime::createFromFormat($date_format, $selected_dates[1]);
						if ($end_date === false) {
							evf_get_logger()->debug(print_r("Invalid end date format: {$selected_dates[1]}", true));
						}
						$end_date->modify('+23 hours');
						$datetime_end = $end_date->format('Y-m-d H:i');

						$datetime_arr[$entry_id] = array($datetime_start, $datetime_end);
					}
				} else {
					if (!empty($datetime_value) && ! is_array($datetime_value)) {
						$datetime_arr[$entry_id] = $datetime_value;
					}
				}
			} else {
				$selected_dates = explode(', ', $datetime_value);

				foreach ($selected_dates as $selected_date) {
					$datetime_start = "$selected_date 00:00";
					$datetime_start = gmdate('Y-m-d H:i', strtotime($datetime_start));
					$date_time      = new DateTime($datetime_start);
					$date_time->modify('+23 hour');

					$datetime_end              = $date_time->format('Y-m-d H:i');
					$datetime_arr[$entry_id] = array($datetime_start, $datetime_end);
				}
			}
			break;
		case 'date-time':
			if ('range' === $mode) {
				$datetime_value = apply_filters('everest_forms_time_date_format', $datetime_value);
				$selected_dates = explode(' to ', $datetime_value);
				if (count($selected_dates) >= 2) {
					$datetime_start            = gmdate('Y-m-d H:i', strtotime($selected_dates[0]));
					$datetime_end              = gmdate('Y-m-d H:i', strtotime($selected_dates[1]));
					$datetime_arr[$entry_id] = array($datetime_start, $datetime_end);
				}
			} else {
				$selected_dates = explode(', ', $datetime_value);

				foreach ($selected_dates as $selected_date) {
					$datetime_start = gmdate('Y-m-d H:i', strtotime($selected_date));
					$date_time      = new DateTime($datetime_start);
					$date_time->modify("+$time_interval minute");
					$datetime_end              = $date_time->format('Y-m-d H:i');
					$datetime_arr[$entry_id] = array($datetime_start, $datetime_end);
				}
			}
			break;
	}

	return $datetime_arr;
}

/**
 * EVF word Count.
 *
 * @param string $text Text.
 * @param string $type Type of text.
 * @param array  $settings Settings.
 *
 * @since 2.0.2
 */
function _evf_word_count($text, $type = 'words', $settings = array())
{
	$defaults = array(
		'html_regexp'                        => '/<\/?[a-z][^>]*?>/i',
		'html_comment_regexp'                => '/<!--[\s\S]*?-->/',
		'space_regexp'                       => '/&nbsp;|&#160;/i',
		'html_entity_regexp'                 => '/&\S+?;/',
		'connector_regexp'                   => "/--|\x{2014}/u",
		'remove_regexp'                      => "/[\x{0021}-\x{0040}\x{005B}-\x{0060}\x{007B}-\x{007E}\x{0080}-\x{00BF}\x{00D7}\x{00F7}\x{2000}-\x{2BFF}\x{2E00}-\x{2E7F}]/u",
		'astral_regexp'                      => "/[\x{010000}-\x{10FFFF}]/u",
		'words_regexp'                       => '/\S\s+/u',
		'characters_excluding_spaces_regexp' => '/\S/u',
		'characters_including_spaces_regexp' => "/[^\f\n\r\t\v\x{00AD}\x{2028}\x{2029}]/u",
		'shortcodes'                         => array(),
	);

	$count = 0;

	if ('' === trim($text)) {
		return $count;
	}

	$settings = wp_parse_args($settings, $defaults);

	// If there are any shortcodes, add this as a shortcode regular expression.
	if (is_array($settings['shortcodes']) && ! empty($settings['shortcodes'])) {
		$settings['shortcodes_regexp'] = '/\\[\\/?(?:' . implode('|', $settings['shortcodes']) . ')[^\\]]*?\\]/';
	}

	// Sanitize type to one of three possibilities: 'words', 'characters_excluding_spaces' or 'characters_including_spaces'.
	if ('characters_excluding_spaces' !== $type && 'characters_including_spaces' !== $type) {
		$type = 'words';
	}

	$text .= "\n";

	// Replace all HTML with a new-line.
	$text = preg_replace($settings['html_regexp'], "\n", $text);

	// Remove all HTML comments.
	$text = preg_replace($settings['html_comment_regexp'], '', $text);

	// If a shortcode regular expression has been provided use it to remove shortcodes.
	if (! empty($settings['shortcodes_regexp'])) {
		$text = preg_replace($settings['shortcodes_regexp'], "\n", $text);
	}

	// Normalize non-breaking space to a normal space.
	$text = preg_replace($settings['space_regexp'], ' ', $text);

	if ('words' === $type) {
		// Remove HTML Entities.
		$text = preg_replace($settings['html_entity_regexp'], '', $text);

		// Convert connectors to spaces to count attached text as words.
		$text = preg_replace($settings['connector_regexp'], ' ', $text);

		// Remove unwanted characters.
		$text = preg_replace($settings['remove_regexp'], '', $text);
	} else {
		// Convert HTML Entities to "a".
		$text = preg_replace($settings['html_entity_regexp'], 'a', $text);

		// Remove surrogate points.
		$text = preg_replace($settings['astral_regexp'], 'a', $text);
	}

	// Match with the selected type regular expression to count the items.
	return (int) preg_match_all($settings[$type . '_regexp'], $text);
}

/**
 * EVF word Count
 *
 * @param string $text Text.
 * @param string $type Type of text.
 * @param array  $settings Settings.
 *
 * @since 2.0.2
 */
function evf_word_count($text, $type = 'words', $settings = array())
{
	if (function_exists(' wp_word_count ')) {
		return wp_word_count($text, $type = 'words', $settings = array());
	} else {
		return _evf_word_count($text, $type = 'words', $settings = array());
	}
}

if (! function_exists('evf_maybe_get_local_font_url')) {
	/**
	 * If load fonts locally option is checked in settings, we download the font
	 * locally and return the url for download font file.
	 *
	 * @param [string] $font_url Remote font url.
	 * @return string
	 */
	function evf_maybe_get_local_font_url($font_url)
	{
		$load_locally = get_option('everest_forms_load_fonts_locally', 'no');

		if ('yes' === $load_locally) {
			$font_url = wptt_get_webfont_url($font_url);
		}

		return $font_url;
	}
}

if (! function_exists('evf_is_akismet_configured')) {

	/**
	 * Has the Akismet plugin been configured wih a valid API key?
	 *
	 * @since 2.4.0
	 *
	 * @return bool
	 */
	function evf_is_akismet_configured()
	{

		if (! is_plugin_active('akismet/akismet.php')) {
			return false;
		}

		require_once WP_PLUGIN_DIR . '/akismet/akismet.php';

		$akismet_instance = new Akismet();
		// Akismet will only allow an API key to be saved if it is a valid key.
		// We can assume that if there is an API key saved, it is valid.
		$api_key = $akismet_instance->get_api_key();

		if (! empty($api_key)) {
			return true;
		}

		return false;
	}
}

if (! function_exists('evf_current_url')) {
	/**
	 * Get the current URL.
	 *
	 * @since 2.4.0
	 *
	 * @return string
	 */
	function evf_current_url()
	{

		$parsed_home_url = wp_parse_url(home_url());

		$url = $parsed_home_url['scheme'] . '://' . $parsed_home_url['host'];

		if (! empty($parsed_home_url['port'])) {
			$url .= ':' . $parsed_home_url['port'];
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$url .= wp_unslash($_SERVER['REQUEST_URI']);

		return esc_url_raw($url);
	}
}

if (! function_exists('evf_get_utc_time_to')) {
	/**
	 * Formats the end time in UTC format.
	 *
	 * @since 2.0.9
	 *
	 * @param string $evf_entries_report_summary_offset Time offest to be formatted in UTC time.
	 * @param string $evf_format DateTime format for UTC time.
	 * @param bool   $evf_display Display the time.
	 *
	 * @return datetime end_time in UTC format.
	 */
	function evf_get_utc_time_to($evf_entries_report_summary_offset, $evf_format = 'Y-m-d H:i:s', $evf_display = false)
	{

		// Get local time 23:59:59 today.
		$evf_time_to_local = wp_date('Y-m-d 23:59:59');
		$evf_format        = 'Y-m-d H:i:s';

		// Get local time to.
		$evf_time_to_offset = strtotime($evf_entries_report_summary_offset, strtotime($evf_time_to_local));

		if ($evf_display) {

			return gmdate($evf_format, $evf_time_to_offset);
		} else {

			return strtotime(get_gmt_from_date(gmdate($evf_format, $evf_time_to_offset)));
		}
	}
}


if (! function_exists('evf_get_utc_time_from')) {
	/**
	 * Extarcts the start time in UTC format.
	 *
	 * @since 2.0.9
	 *
	 * @param string $evf_entries_report_summary_offset Start time to be formated in UTC format.
	 * @param string $evf_format DateTime format for start time.
	 * @param bool   $evf_display To hide or display the time.
	 *
	 * @return datetime start_time in utc format.
	 */
	function evf_get_utc_time_from($evf_entries_report_summary_offset, $evf_format = 'Y-m-d H:i:s', $evf_display = false)
	{

		// Get local time midnight today.
		$evf_time_from_local = wp_date('Y-m-d 00:00:00');
		$evf_format          = 'Y-m-d H:i:s';

		// Get local time from.
		$evf_time_from_offset = strtotime($evf_entries_report_summary_offset, strtotime($evf_time_from_local));

		if ($evf_display) {

			return gmdate($evf_format, $evf_time_from_offset);
		} else {

			return strtotime(get_gmt_from_date(gmdate($evf_format, $evf_time_from_offset)));
		}
	}
}


if (! function_exists('evf_summary_start_offset_time')) {
	/**
	 * Extracts the start offset time according to the duration.
	 *
	 * @since 2.0.9
	 * @return datetime $starttime.
	 */
	function evf_summary_start_offset_time()
	{
		// Get options.
		$evf_report_frequency   = get_option('everest_forms_entries_reporting_frequency', 'Weekly');
		$evf_report_offset_from = '';
		$evf_report_email_to    = '';
		$evf_date_format        = '';
		$evf_report_offset_to   = '';

		// Set everest forms reporting frequency specific variables.
		switch ($evf_report_frequency) {

			case 'Daily':
				$evf_report_email_title = __('Everest Forms Daily Entries Statistics Report', 'everest-forms');
				$evf_report_offset_from = '-1 days';
				break;

			case 'Weekly':
				$evf_report_email_title = __('Everest Forms Weekly Entries Statistics Report', 'everest-forms');
				$evf_report_offset_from = '-8 days';
				break;

			case 'Monthly':
				$evf_report_email_title = __('Everest Forms Monthly Entries Statistics Report', 'everest-forms');
				$evf_report_offset_from = '-1 month -1 day';
				break;
		}

		// Build date range.
		$date_format = get_option('date_format');

		$start_time = evf_get_utc_time_from($evf_report_offset_from, $date_format, true);
		return $start_time;
	}
}

if (! function_exists('evf_summary_end_offset_time')) {
	/**
	 * Extracts the end offset time according to the duration.
	 *
	 * @since 2.0.9
	 * @return datetime $end_time.
	 */
	function evf_summary_end_offset_time()
	{
		// Get options.
		$evf_report_frequency   = get_option('everest_forms_entries_reporting_frequency', 'Weekly');
		$evf_report_offset_from = '';
		$evf_report_email_to    = '';
		$evf_date_format        = '';
		$evf_report_offset_to   = '';

		// Set everest forms reporting frequency specific variables.
		switch ($evf_report_frequency) {

			case 'Daily':
				$evf_report_email_title = __('Everest Forms Daily Entries Statistics Report', 'everest-forms');
				$evf_report_offset_to   = '-1 day';
				break;

			case 'Weekly':
				$evf_report_email_title = __('Everest Forms Weekly Entries Statistics Report', 'everest-forms');
				$evf_report_offset_to   = '-1 day';
				break;

			case 'Monthly':
				$evf_report_email_title = __('Everest Forms Monthly Entries Statistics Report', 'everest-forms');
				$evf_report_offset_to   = '-1 day';
				break;
		}

		// Build date range.
		$date_format = get_option('date_format');

		$end_time = evf_get_utc_time_to($evf_report_offset_to, $date_format, true);

		return $end_time;
	}
}


if (! function_exists('evf_process_all_fields_smart_tag')) {
	/**
	 * Return email content wrapped in email template if {all_fields} is there in email content.
	 *
	 * @since 2.0.5
	 * @param string $email_content Email Content.
	 * @return string
	 */
	function evf_process_all_fields_smart_tag($email_content)
	{

		$email_content  = '<div class="evf-all-fields-smart-tag-email-body" background-color: #ebebeb;">';
		$email_content .= '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="min-width:100%;border-collapse:collapse background:#cccccc" >';
		$email_content .= '<tbody>';
		$email_content .= '<tr>';
		$email_content .= '<td valign="top">';
		$email_content .= '<table align="left" border="0" cellpadding="0" cellspacing="0" width="100%" style="min-width:100%;border-collapse:collapse" class="mcnTextContentContainer">';
		$email_content .= '<tbody>';
		$email_content .= '<tr>';
		$email_content .= '<td valign = "top" class = "mcnTextContent">';
		$email_content .= "<table align = 'left' border = '0' cellpadding = '0' cellspacing = '0' width = '100%' style = 'display:block;min-width:100%;border-collapse:collapse;width:100%'>";
		$email_content .= '<tbody>';
		$email_content .= '<tr>';
		$email_content .= "<td style = 'color:#333333;padding-top:20px;padding-bottom:3px'><strong>Name</strong></td>";
		$email_content .= '</tr>';
		$email_content .= '<tr>';
		$email_content .= "<td style = 'color:#555555;padding-top:3px;padding-bottom:20px'>Demo Name</td>";
		$email_content .= '</tr>';
		$email_content .= '</tbody>';
		$email_content .= '</table>';
		$email_content .= "<table align = 'left' border = '0' cellpadding = '0' cellspacing = '0' width = '100%' style = 'border-top:1px solid #dddddd;display:block;min-width:100%;border-collapse:collapse;width:100%'>";
		$email_content .= '<tbody>';
		$email_content .= '<tr>';
		$email_content .= "<td style = 'color:#333333;padding-top:20px;padding-bottom:3px'> <strong>Email</strong><tr / td >";
		$email_content .= '</tr>';
		$email_content .= '<tr>';
		$email_content .= "<td style = 'color:#555555;padding-top:3px;padding-bottom:20px' > <a href = '#' target = '_blank'>Demo Email</a></td>";
		$email_content .= '</tr>';
		$email_content .= '</tbody>';
		$email_content .= '</table>';
		$email_content .= "<table align = 'left' border = '0' cellpadding = '0' cellspacing = '0' width = '100%' style = 'border-top:1px solid #dddddd;display:block;min-width:100%;border-collapse:collapse;width:100%'>";
		$email_content .= '<tbody>';
		$email_content .= '<tr>';
		$email_content .= "<td style = 'color:#333333;padding-top:20px;padding-bottom:3px'><strong>Email Subject</strong></td>";
		$email_content .= '</tr>';
		$email_content .= '<tr>';
		$email_content .= "<td style = 'color:#555555;padding-top:3px;padding-bottom:20px'>Demo Email Subject</td>";
		$email_content .= '</tr>';
		$email_content .= '</tbody>';
		$email_content .= '</table>';
		$email_content .= "<table align = 'left' border = '0' cellpadding = '0' cellspacing = '0' width = '100%' style = 'border-top:1px solid #dddddd;display:block;min-width:100%;border-collapse:collapse;width:100%'>";
		$email_content .= '<tbody>';
		$email_content .= '<tr>';
		$email_content .= "<td style = 'color:#333333;padding-top:20px;padding-bottom:3px'><strong>Email Message</strong></td>";
		$email_content .= '</tr>';
		$email_content .= '<tr>';
		$email_content .= "<td style = 'color:#555555;padding-top:3px;padding-bottom:20px'>Demo Email Message</td>";
		$email_content .= '</tr>';
		$email_content .= '</tbody>';
		$email_content .= '</table>';
		$email_content .= '</td>';
		$email_content .= '</tr>';
		$email_content .= '</tbody>';
		$email_content .= '</table>';
		$email_content .= '</td>';
		$email_content .= '</tr>';
		$email_content .= '</tbody>';
		$email_content .= '</table>';
		$email_content .= '</div>';

		return $email_content;
	}
}



/**
 * Get one time draggable fields fields.
 *
 * @return array
 */
function evf_get_one_time_draggable_fields()
{
	return apply_filters(
		'everest_forms_one_time_draggable_form_fields',
		array(
			'file-upload',
		)
	);
}

/**
 * Retrieve the next key in an array from given key.
 *
 * @param    array   $arr        List of items in a form of array.
 * @param    integer $key        Key to search and find within the array.
 * @return   mixed
 */
function evf_get_next_key_array($arr, $key)
{
	$keys     = array_keys($arr);
	$position = array_search($key, $keys, true);

	if (isset($keys[$position + 1])) {
		$next_key = $keys[$position + 1];
	}

	return isset($next_key) ? $next_key : '';
}
/**
 * Function to generate the api key base on the string.
 *
 * @since 3.0.5
 * @param $string The string value.
 */
function generate_api_key($string = 'evf_restapi', $length = 32)
{
	$key = bin2hex(random_bytes($length));

	return $key;
}

if (! function_exists('evf_hex_to_rgb')) {
	/**
	 * Converts a hex color code to an RGB array.
	 *
	 * @since 3.0.5
	 * @param string $hexcolor Hex color code, with or without '#'.
	 * @return array RGB values as [red, green, blue].
	 */
	function evf_hex_to_rgb($hexcolor)
	{
		$hexcolor = ltrim($hexcolor, '#');

		if (strlen($hexcolor) == 6) {
			$r = hexdec(substr($hexcolor, 0, 2));
			$g = hexdec(substr($hexcolor, 2, 2));
			$b = hexdec(substr($hexcolor, 4, 2));
		} elseif (strlen($hexcolor) == 3) {
			$r = hexdec(str_repeat(substr($hexcolor, 0, 1), 2));
			$g = hexdec(str_repeat(substr($hexcolor, 1, 1), 2));
			$b = hexdec(str_repeat(substr($hexcolor, 2, 1), 2));
		} else {
			return array(0, 0, 0);
		}

		return array($r, $g, $b);
	}
}

add_action('wp_mail_failed', 'evf_email_send_failed_handler', 1);

if (! function_exists('evf_email_send_failed_handler')) {

	/**
	 * Handle errors fetch mechanism when mail send failed.
	 *
	 * @param object $error_instance WP_Error message instance.
	 */
	function evf_email_send_failed_handler($error_instance)
	{
		$error_message   = '';
		$decoded_message = json_decode($error_instance->get_error_message());

		if (json_last_error() === JSON_ERROR_NONE && ! empty($decoded_message)) {
			/* translators: %s: Status Log URL */
			$error_message = wp_kses_post(sprintf(__('Please check the `evf_mail_errors` log under <a target="_blank" href="%s"> Logs </a> section.', 'everest-forms'), admin_url('admin.php?page=evf-tools&tab=logs')));
		} else {
			$error_message = $error_instance->get_error_message();
		}

		evf_get_logger()->info(
			$error_message,
			array('source' => 'evf_mail_errors')
		);

		if (! empty($error_message)) {
			add_filter(
				'everest_forms_email_send_failed_message',
				function ($msg) use ($error_message) {
					return $error_message;
				}
			);
		}
	}
}

/**
 * Get form data by field key.
 *
 * @param array  $form_data Form Data.
 * @param string $key Field Key.
 *
 * @return array
 */
function evf_get_form_data_by_key($form_data, $key = null)
{

	$form_data_array = array();

	foreach ($form_data['form_fields'] as $field_data) {

		$field_key = isset($field_data['type']) && null !== $field_data['type'] ? $field_data['type'] : '';

		if (! empty($field_key)) {
			if ($field_key === $key) {
				$form_data_array[] = $field_data;
			}
		}
	}

	return $form_data_array;
}

add_action(
	'admin_head',
	function () {
		$js = <<<JS
const isSidebarEnabled = localStorage.getItem( 'isPremiumSidebarEnabled' );
const interval = setInterval( () => {
	if ( document.body ) {
		clearInterval(interval);
		if ('true' === isSidebarEnabled) {
			document.body.classList.add( 'evf-premium-sidebar-show' );
		} else {
			document.body.classList.add( 'evf-premium-sidebar-hidden' );
		}
	}
}, 1 );
JS;
		if (function_exists('wp_print_inline_script_tag')) {
			wp_print_inline_script_tag($js);
		}
	}
);

/**
 * Get the list of addons or module that are depended on another plugins.
 *
 * @since 3.2.2
 */
function evf_get_addons_list_depend_on_another_plugins()
{
	$required_plugins = apply_filters(
		'everest_forms_required_plugins_lists',
		array(
			'everest-forms-woocommerce'      => array(
				'file' => 'woocommerce/woocommerce.php',
				'name' => 'WooCommerce',
			),
			'everest-forms-mailpoet'         => array(
				'file' => 'mailpoet/mailpoet.php',
				'name' => 'MailPoet',
			),
			'everest-forms-oxygen-builder'   => array(
				'file' => 'oxygen/functions.php',
				'name' => 'Oxygen Builder',
			),
			'everest-forms-beaver-builder'   => array(
				'file' => array(
					'beaver-builder-lite-version/fl-builder.php',
					'bb-plugin/fl-builder.php',
				),
				'name' => 'Beaver Builder',
			),
			'everest-forms-wpbakery-builder' => array(
				'file' => 'js_composer/js_composer.php',
				'name' => 'WPBakery Builder',
			),
			'everest-forms-divi-builder'     => array(
				'is_theme' => true,
				'name'     => 'Divi Builder',
				'id'       => 'Divi',
			),
			'everest-forms-bricks-builder'   => array(
				'is_theme' => true,
				'name'     => 'Bricks Builder',
				'id'       => 'bricks',
			),
		)
	);

	return $required_plugins;
}


if (! function_exists('evf_maybe_unserialize')) {
	/**
	 * EVF Unserialize data.
	 *
	 * @param string $data Data that might be unserialized.
	 * @param array  $options Options.
	 *
	 * @return mixed Unserialized data can be any type.
	 *
	 * @since 3.1.2
	 */
	function evf_maybe_unserialize($data, $options = array())
	{

		if (is_serialized($data)) {
			if (version_compare(PHP_VERSION, '7.1.0', '>=')) {
				$options = wp_parse_args($options, array('allowed_classes' => false));
				return @unserialize(trim($data), $options); //phpcs:ignore.
			}
			//Blocked unserialize() attempt on PHP <  7.1
			return null;
		}

		return $data;
	}
}

/**
 * Confimation backward compatiblity.
 *
 * @since 3.4.0
 * @param [type] $form_data The old form data.
 */
function evf_form_confirmation_backward_compatibility($form_data)
{
	if (empty($form_data)) {
		return $form_data;
	}
	//If the form is updated then return original form data.
	if (get_post_meta($form_data['id'], 'updated_form_confirmation', true)) {
		return $form_data;
	}

	$settings               = $form_data['settings'];
	$submission_redirection = isset($settings['submission_redirection']) ? $settings['submission_redirection'] : array();
	if (empty($submission_redirection)) {
		return $form_data;
	}

	$updated_conditionals = array();
	if (isset($submission_redirection['connection_1']['conditionals'])) {
		$conditionals = isset($submission_redirection['connection_1']['conditionals']) ? $submission_redirection['connection_1']['conditionals'] : array();
		$rules        = isset($submission_redirection['connection_1']['rules']) ? $submission_redirection['connection_1']['rules'] : array();

		if (empty($rules) || empty($conditionals)) {
			return $form_data;
		}

		foreach ($conditionals['rules'] as $key => $conditional) {
			$updated_conditionals['rules'][$key] = $conditional;

			$settings = array(
				'title'                              => 'Conditional Confirmations',
				'redirect_to'                        => isset($rules[$key]['conditional_option']) ? ('same_page' === $rules[$key]['conditional_option'] ? 'same' : $rules[$key]['conditional_option']) : 'same',
				'custom_page'                        => isset($rules[$key]['conditional_option_redirection_custom_page']) ? $rules[$key]['conditional_option_redirection_custom_page'] : 0,
				'external_url'                       => isset($rules[$key]['conditional_option_redirection_external_url']) ? $rules[$key]['conditional_option_redirection_custom_page'] : 0,
				'enable_redirect_in_new_tab'         => 0,
				'enable_redirect_query_string'       => 0,
				'query_string'                       => isset($settings['query_string']) ? $settings['query_string'] : '',
				'successful_form_submission_message' => isset(
					$settings['            [successful_form_submission_message] => Thanks for contacting us! We will be in touch with you shortly
']
				) ? $settings['            [successful_form_submission_message] => Thanks for contacting us! We will be in touch with you shortly
'] : 'Thanks for contacting us! We will be in touch with you shortly',
				'submission_message_scroll'          => isset($settings['submission_message_scroll']) ? $settings['submission_message_scroll'] : 0,
				'form_state_type'                    => 'hide',
				'preview_confirmation'               => isset($settings['preview_confirmation']) ? $settings['preview_confirmation'] : 0,
				'preview_confirmation_select'        => isset($settings['preview_confirmation_select']) ? $settings['preview_confirmation_select'] : 'basic',
				'message_display_location_of_hide'   => 'hide',
				'message_display_location_of_reset'  => 'top',
				'active'                             => 1,
			);

			$updated_conditionals['rules'][$key]['settings'] = $settings;
		}
	}

	if (! empty($updated_conditionals)) {
		$form_data['settings']['submission_redirection']['connection_1']['conditionals'] = $updated_conditionals;
	}

	return $form_data;
}
