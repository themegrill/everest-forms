<?php
/**
 * WordPress Abilities API integration for Everest Forms.
 *
 * Registers a starter set of abilities (forms, entries, analytics) against
 * the WordPress Abilities API when available, and exposes them through a
 * minimal MCP-over-HTTP endpoint so external MCP clients can call them.
 *
 * @package EverestForms\Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Abilities integration.
 */
class EVF_Abilities {

	/**
	 * Namespace used for ability ids and the MCP route.
	 */
	const NAMESPACE_ID = 'everest-forms';

	/**
	 * Bare ability names that modify site state.
	 *
	 * These flip the MCP `destructiveHint` annotation on `tools/list`, which
	 * is how MCP clients (Claude Desktop, ChatGPT connectors, etc.) know to
	 * show a confirmation prompt before invoking the tool. Without this list
	 * the client treats every tool as safe and skips the approval UI.
	 *
	 * @var string[]
	 */
	const DESTRUCTIVE = array(
		'create-form',
		'update-form',
		'delete-form',
		'duplicate-form',
		'update-form-status',
		'activate-addon',
		'create-entry',
		'update-entry-fields',
		'update-entry-status',
		'delete-entry',
		'bulk-delete-entries',
		'set-entry-starred',
		'set-entry-viewed',
	);

	/**
	 * Whether a bare ability name (e.g. "delete-form") mutates site state.
	 *
	 * @param string $name Bare ability name.
	 * @return bool
	 */
	public static function is_destructive( $name ) {
		return in_array( (string) $name, self::DESTRUCTIVE, true );
	}

	/**
	 * Init hooks.
	 */
	public static function init() {
		// Always populate our internal registry — MCP reads from it directly,
		// which keeps tools/list deterministic regardless of the Abilities API
		// lifecycle. Registered on `init` (not `plugins_loaded`) because the
		// ability definitions contain translated labels/descriptions, and
		// requesting translations before `init` triggers the WordPress 6.7+
		// "translation loading was triggered too early" notice. Consumers run
		// later (`wp_abilities_api_init`, `rest_api_init`), so `init` is early
		// enough.
		add_action( 'init', array( __CLASS__, 'self_register' ), 20 );

		// Additionally mirror into the WP Abilities API when present, so other
		// Abilities-aware consumers (e.g. the official REST controller + MCP
		// adapters) see them. Categories MUST be registered on their own
		// earlier hook; abilities reference the category on wp_abilities_api_init.
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );

		add_action( 'rest_api_init', array( __CLASS__, 'register_mcp_route' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_missing_api_notice' ) );
	}

	/**
	 * Whether the WordPress Abilities API is available on this site.
	 *
	 * @return bool
	 */
	public static function has_abilities_api() {
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * Show an admin notice if the Abilities API isn't installed yet, so
	 * site owners know how to light up full integration.
	 */
	public static function maybe_show_missing_api_notice() {
		if ( self::has_abilities_api() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Only on Everest Forms screens to avoid noise.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'everest-forms' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>Everest Forms</strong>: WordPress Abilities API not detected. The plugin\'s MCP endpoint still works at <code>/wp-json/everest-forms/v1/mcp</code>, but to expose abilities to other Abilities-aware plugins, install the <a href="https://github.com/WordPress/abilities-api" target="_blank" rel="noopener">WordPress Abilities API</a>.</p></div>';
	}

	/**
	 * Category slug used when registering abilities with the WP Abilities API.
	 */
	const CATEGORY = 'everest-forms';

	/**
	 * Register abilities with the official WP Abilities API.
	 *
	 * Three things are required for abilities to surface through the Abilities
	 * REST API and any MCP adapter plugin built on top of it:
	 *   1. a registered `category` (Abilities API 6.9+ requires each ability to
	 *      reference one),
	 *   2. `meta.show_in_rest = true` — abilities default to NOT exposed in
	 *      REST, so without this the Abilities REST API (and therefore any MCP
	 *      adapter) returns ZERO abilities, even though they're registered,
	 *   3. `meta.annotations` (readonly/destructive/idempotent) so the adapter
	 *      can render the right confirmation UI.
	 */
	public static function register_category() {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				self::CATEGORY,
				array(
					'label'       => __( 'Everest Forms', 'everest-forms' ),
					'description' => __( 'Form, entry, and analytics operations exposed by Everest Forms.', 'everest-forms' ),
				)
			);
		}
	}

	public static function register_abilities() {
		if ( ! self::has_abilities_api() ) {
			return;
		}

		foreach ( self::ability_definitions() as $def ) {
			$args        = $def['args'];
			$destructive = self::is_destructive( $def['name'] );

			if ( ! isset( $args['category'] ) ) {
				$args['category'] = self::CATEGORY;
			}

			// Ensure the ability is exposed via REST (default is false) and
			// carries behavior annotations for adapter UIs.
			$meta                = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
			$meta['show_in_rest'] = true;
			$meta['annotations']  = wp_parse_args(
				isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array(),
				array(
					'readonly'    => ! $destructive,
					'destructive' => $destructive,
					'idempotent'  => ! $destructive,
				)
			);
			$args['meta'] = $meta;

			wp_register_ability( self::NAMESPACE_ID . '/' . $def['name'], $args );
		}
	}

	/**
	 * Register abilities into our internal registry. Always runs.
	 */
	public static function self_register() {
		EVF_Abilities_Registry::instance()->bulk_register( self::ability_definitions() );
	}

	/**
	 * Ability definitions.
	 *
	 * @return array
	 */
	protected static function ability_definitions() {
		return array(
			array(
				'name' => 'list-forms',
				'args' => array(
					'label'               => __( 'List forms', 'everest-forms' ),
					'description'         => __( 'List Everest Forms forms with id, title and status.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
							'status' => array( 'type' => 'string', 'enum' => array( 'any', 'publish', 'draft', 'trash' ), 'default' => 'publish' ),
						),
					),
					'output_schema'       => array( 'type' => 'array' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'list_forms' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_view_forms' ),
				),
			),
			array(
				'name' => 'get-form',
				'args' => array(
					'label'               => __( 'Get form', 'everest-forms' ),
					'description'         => __( 'Get a single form including its fields and settings.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'form_id' ),
						'properties' => array(
							'form_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'get_form' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_view_form' ),
				),
			),
			array(
				'name' => 'create-form',
				'args' => array(
					'label'               => __( 'Create form', 'everest-forms' ),
					'description'         => __( 'Create a new Everest Forms form. By default the new form is created **inactive** (the Active toggle is off and post_status is `inactive`) so it does not start collecting submissions until you explicitly publish it — pass `status: "publish"` to make it live on creation. Accepts a flat `fields` array (one field per row), or a richer `layout` array of rows for multi-column layouts. Each layout row may carry `part: N` to assign it to a multi-step page; pass `multi_part: { parts: [{name:"..."}] }` to enable the Multi-Part Forms addon and label the steps. Pass `conversational: { enabled: true, slug, title, description }` to render the form one-question-at-a-time via the Conversational Forms addon (mutually exclusive with multi_part). Fields support `choices` (select/radio/checkbox/country), `default_value`, `description`, `placeholder`, `required`, `sublabels`, plus any type-specific keys. Unknown field types AND missing required addons (Multi-Part, Conversational Forms, Coupons, etc.) return a structured error naming what to activate. Pass `dry_run: true` to validate without persisting.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'title' ),
						'properties' => array(
							'title'       => array( 'type' => 'string', 'minLength' => 1 ),
							'description' => array( 'type' => 'string' ),
							'template'    => array( 'type' => 'string', 'default' => 'blank' ),
							'status'      => array( 'type' => 'string', 'enum' => array( 'inactive', 'publish' ), 'default' => 'inactive' ),
							'settings'    => array( 'type' => 'object' ),
							'fields'      => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'layout'      => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'row' => array(
											'type'  => 'array',
											'items' => array( 'type' => 'object' ),
										),
									),
								),
							),
							'multi_part'  => array(
								'type'       => 'object',
								'properties' => array(
									'enabled' => array( 'type' => 'boolean' ),
									'parts'   => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'name' => array( 'type' => 'string' ),
											),
										),
									),
								),
							),
							'conversational' => array(
								'type'       => 'object',
								'properties' => array(
									'enabled'     => array( 'type' => 'boolean' ),
									'slug'        => array( 'type' => 'string' ),
									'title'       => array( 'type' => 'string' ),
									'description' => array( 'type' => 'string' ),
								),
							),
							'dry_run'     => array( 'type' => 'boolean', 'default' => false ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'create_form' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_create_forms' ),
				),
			),
			array(
				'name' => 'update-form',
				'args' => array(
					'label'               => __( 'Update form', 'everest-forms' ),
					'description'         => __( 'Update an existing form. Patch top-level `title`/`description`/`settings` (deep-merged), append new fields via `fields` or `layout` (same schema as create-form), and patch existing fields by id via `form_fields_patch` ({ field_id: { key: value } }). Validates field types against installed addons and returns structured errors on unknown types. Pass `dry_run: true` to preview without persisting.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'form_id' ),
						'properties' => array(
							'form_id'           => array( 'type' => 'integer', 'minimum' => 1 ),
							'title'             => array( 'type' => 'string' ),
							'description'       => array( 'type' => 'string' ),
							'settings'          => array( 'type' => 'object' ),
							'fields'            => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'layout'            => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'form_fields_patch' => array( 'type' => 'object' ),
							'multi_part'        => array( 'type' => 'object' ),
							'dry_run'           => array( 'type' => 'boolean', 'default' => false ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'update_form' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_edit_form' ),
				),
			),
			array(
				'name' => 'update-form-status',
				'args' => array(
					'label'               => __( 'Update form status', 'everest-forms' ),
					'description'         => __( 'Change a form\'s WordPress post_status (publish/draft/trash). Useful for archiving forms or restoring trashed ones without deleting them.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'form_id', 'status' ),
						'properties' => array(
							'form_id' => array( 'type' => 'integer', 'minimum' => 1 ),
							'status'  => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'trash' ) ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'update_form_status' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_edit_form' ),
				),
			),
			array(
				'name' => 'bulk-delete-entries',
				'args' => array(
					'label'               => __( 'Bulk delete entries', 'everest-forms' ),
					'description'         => __( 'Delete multiple entries in one call by their ids. By default permanent; set permanent=false to move them to trash. Returns counts of deleted and skipped entries.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'entry_ids' ),
						'properties' => array(
							'entry_ids' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'integer', 'minimum' => 1 ),
							),
							'permanent' => array( 'type' => 'boolean', 'default' => true ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'bulk_delete_entries' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_delete_entries' ),
				),
			),
			array(
				'name' => 'delete-form',
				'args' => array(
					'label'               => __( 'Delete form', 'everest-forms' ),
					'description'         => __( 'Delete a form (and its entries) by id.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'form_id' ),
						'properties' => array(
							'form_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'delete_form' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_delete_form' ),
				),
			),
			array(
				'name' => 'duplicate-form',
				'args' => array(
					'label'               => __( 'Duplicate form', 'everest-forms' ),
					'description'         => __( 'Duplicate an existing form. Returns the new form id.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'form_id' ),
						'properties' => array(
							'form_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'duplicate_form' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_create_forms' ),
				),
			),
			array(
				'name' => 'activate-addon',
				'args' => array(
					'label'               => __( 'Activate an Everest Forms addon', 'everest-forms' ),
					'description'         => __( 'Activate an installed Everest Forms addon plugin by its plugin file (e.g. "everest-forms-survey-polls-quiz/everest-forms-survey-polls-quiz.php"). TWO-STEP: calling without confirm:true returns status:"confirmation_required" and does NOT activate — you must ask the user to confirm, then call again with confirm:true. Activating plugins changes site state, so never confirm on the user\'s behalf. If the plugin is not installed, the response indicates the user must install/upgrade it from their Everest Forms account.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'plugin' ),
						'properties' => array(
							'plugin'  => array( 'type' => 'string', 'minLength' => 3 ),
							'confirm' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Must be true to actually activate. Only set this after the user has explicitly agreed.' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'activate_addon' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_activate_plugins' ),
				),
			),
			array(
				'name' => 'list-addons',
				'args' => array(
					'label'               => __( 'List Everest Forms addons', 'everest-forms' ),
					'description'         => __( 'List installed Everest Forms addons with their active/inactive state. Use before create-form or update-form to decide whether a requested field type (likert, file-upload, etc.) is available.', 'everest-forms' ),
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'array' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'list_addons' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_view_forms' ),
				),
			),
			array(
				'name' => 'describe-field-type',
				'args' => array(
					'label'               => __( 'Describe field type', 'everest-forms' ),
					'description'         => __( 'Return the schema for a single field type — accepted keys, whether it requires choices, and the addon that ships it. Useful before constructing a complex field.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'type' ),
						'properties' => array(
							'type' => array( 'type' => 'string' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'describe_field_type' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_view_forms' ),
				),
			),
			array(
				'name' => 'list-field-types',
				'args' => array(
					'label'               => __( 'List field types', 'everest-forms' ),
					'description'         => __( 'List all registered Everest Forms field types (id, name, group, icon) usable when building or editing forms.', 'everest-forms' ),
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'array' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'list_field_types' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_view_forms' ),
				),
			),
			array(
				'name' => 'list-entries',
				'args' => array(
					'label'               => __( 'List entries', 'everest-forms' ),
					'description'         => __( 'List entries for a given form. Supports status filter (unread, read, starred, approved, denied, pending, spam, trash, publish).', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'form_id' ),
						'properties' => array(
							'form_id' => array( 'type' => 'integer', 'minimum' => 1 ),
							'limit'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 25 ),
							'offset'  => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
							'search'  => array( 'type' => 'string' ),
							'status'  => array( 'type' => 'string', 'enum' => array( '', 'unread', 'read', 'starred', 'approved', 'denied', 'pending', 'spam', 'trash', 'publish' ) ),
						),
					),
					'output_schema'       => array( 'type' => 'array' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'list_entries' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_view_form_entries' ),
				),
			),
			array(
				'name' => 'get-entry',
				'args' => array(
					'label'               => __( 'Get entry', 'everest-forms' ),
					'description'         => __( 'Get a single entry with all field values.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'entry_id' ),
						'properties' => array(
							'entry_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'get_entry' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_view_entry' ),
				),
			),
			array(
				'name' => 'delete-entry',
				'args' => array(
					'label'               => __( 'Delete entry', 'everest-forms' ),
					'description'         => __( 'Delete a form entry. By default permanent; set permanent=false to move it to trash instead.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'entry_id' ),
						'properties' => array(
							'entry_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
							'permanent' => array( 'type' => 'boolean', 'default' => true ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'delete_entry' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_delete_entry' ),
				),
			),
			array(
				'name' => 'update-entry-status',
				'args' => array(
					'label'               => __( 'Update entry status', 'everest-forms' ),
					'description'         => __( 'Set the status of an entry to approved, denied, pending, spam, publish, or trash.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'entry_id', 'status' ),
						'properties' => array(
							'entry_id' => array( 'type' => 'integer', 'minimum' => 1 ),
							'status'   => array( 'type' => 'string', 'enum' => array( 'approved', 'denied', 'pending', 'spam', 'publish', 'trash' ) ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'update_entry_status' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_edit_entry' ),
				),
			),
			array(
				'name' => 'set-entry-starred',
				'args' => array(
					'label'               => __( 'Star/unstar entry', 'everest-forms' ),
					'description'         => __( 'Toggle the starred flag on an entry.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'entry_id', 'starred' ),
						'properties' => array(
							'entry_id' => array( 'type' => 'integer', 'minimum' => 1 ),
							'starred'  => array( 'type' => 'boolean' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'set_entry_starred' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_edit_entry' ),
				),
			),
			array(
				'name' => 'set-entry-viewed',
				'args' => array(
					'label'               => __( 'Mark entry read/unread', 'everest-forms' ),
					'description'         => __( 'Toggle the viewed (read) flag on an entry.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'entry_id', 'viewed' ),
						'properties' => array(
							'entry_id' => array( 'type' => 'integer', 'minimum' => 1 ),
							'viewed'   => array( 'type' => 'boolean' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'set_entry_viewed' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_edit_entry' ),
				),
			),
			array(
				'name' => 'count-entries',
				'args' => array(
					'label'               => __( 'Count entries', 'everest-forms' ),
					'description'         => __( 'Counts of entries broken down by status (total, unread, starred, approved, denied, pending, spam, trash) for a form or site-wide.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'form_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'count_entries' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_view_entries_scope' ),
				),
			),
			array(
				'name' => 'create-entry',
				'args' => array(
					'label'               => __( 'Create entry', 'everest-forms' ),
					'description'         => __( 'Create a new entry for a form. Pass fields as an object keyed by field id.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'form_id', 'fields' ),
						'properties' => array(
							'form_id' => array( 'type' => 'integer', 'minimum' => 1 ),
							'fields'  => array( 'type' => 'object' ),
							'status'  => array( 'type' => 'string', 'enum' => array( 'publish', 'approved', 'denied', 'pending', 'spam' ), 'default' => 'publish' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'create_entry' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_edit_form_entries' ),
				),
			),
			array(
				'name' => 'update-entry-fields',
				'args' => array(
					'label'               => __( 'Update entry field values', 'everest-forms' ),
					'description'         => __( 'Update one or more field values on an existing entry. Pass fields as an object keyed by field id.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'entry_id', 'fields' ),
						'properties' => array(
							'entry_id' => array( 'type' => 'integer', 'minimum' => 1 ),
							'fields'   => array( 'type' => 'object' ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'update_entry_fields' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_edit_entry' ),
				),
			),
			array(
				'name' => 'analytics-summary',
				'args' => array(
					'label'               => __( 'Analytics summary', 'everest-forms' ),
					'description'         => __( 'Summary stats: total forms, total entries, entries in last N days, top forms by submissions.', 'everest-forms' ),
					'input_schema'        => array(
						'type'       => 'object',
						'properties' => array(
							'days'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30 ),
							'form_id' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
						),
					),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => array( 'EVF_Abilities_Handlers', 'analytics_summary' ),
					'permission_callback' => array( 'EVF_Abilities_Handlers', 'can_view_entries_scope' ),
				),
			),
		);
	}

	/**
	 * Register the in-plugin MCP HTTP endpoint.
	 */
	public static function register_mcp_route() {
		register_rest_route(
			'everest-forms/v1',
			'/mcp',
			array(
				'methods'             => array( 'POST', 'GET' ),
				'callback'            => array( 'EVF_MCP_Server', 'handle_request' ),
				'permission_callback' => array( 'EVF_MCP_Server', 'permission_check' ),
			)
		);
	}
}
