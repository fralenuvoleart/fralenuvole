<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Fralenuvole
 * options-page.php - Creates plugin options page
 */

// Initialize the static filters for the settings page
Frl_Settings_Fields::init_filters();

/**
 * Get or create the settings page instance
 *
 * This function implements a singleton pattern for the Frl_Settings_Fields class.
 *
 * @return Frl_Settings_Fields The single settings page instance
 */
function frl_get_settings_page()
{
	static $instance = null;

	if ($instance === null) {
		// Get all UI field definitions
		// ($key= null: All fields, $ui_fields = true: config + modules, excludes runtime)
		$all_fields = frl_get_all_plugin_options_settings(null, true);

		// Create the instance - this will also trigger widget registration via the constructor
		$instance = new Frl_Settings_Fields(['fields' => $all_fields]);

		// Always initialize sections and fields
		$instance->frl_setup_sections();
		$instance->frl_setup_fields();
	}

	return $instance;
}

/**
 * Settings Page Class: Frl_Settings_Fields
 *
 * This class uses a hybrid approach with both static and instance methods.
 */
class Frl_Settings_Fields
{

	public $fields;

	private $processed_sections = [];

	private $registered_fields = [];

	private $tab_labels = [];

	private $active_tab = 0;

	// Registry for widgets that will be inserted in sections
	public $widgets = [
		'before' => [], // Widgets to show before section fields
		'after' => []   // Widgets to show after section fields
	];

	/**
	 * Initialize static filters for the class
	 */
	public static function init_filters()
	{
		// No filters needed - all tabs are handled consistently
	}

	public function __construct($args = [])
	{
		if (frl_is_array_not_empty($args)) {
			foreach ($args as $key => $property) {
				if (property_exists($this, $key)) {
					$this->{$key} = $property;
				}
			}
		}

		// Sections and fields are registered when frl_get_settings_page() runs (admin_init),
		// which calls frl_setup_sections() and frl_setup_fields() directly after construction.

		// Fire widget registration hook during construction
		do_action('frl_register_section_widgets', $this);

		// IMMEDIATELY register action handlers for widgets - don't use a hook
		// This ensures callbacks are registered before they're checked
		$this->register_widget_actions();

		if (frl_is_array_not_empty($this->fields)) {
			foreach ($this->fields as $field) {
				$this->validate_field($field);
			}
		}
	}

	/**
	 * Register action handlers for each registered widget
	 * This connects the widget registry with the action hooks
	 */
	public function register_widget_actions()
	{
		// Process both 'before' and 'after' positions
		foreach (['before', 'after'] as $position) {
			if (empty($this->widgets[$position])) {
				continue;
			}

			foreach ($this->widgets[$position] as $section_id => $callbacks) {
				// Create action name dynamically based on position and section
				$action_name = FRL_PREFIX . "_{$position}_section_{$section_id}_content";

				// Create a stable closure that will call all callbacks for this section
				// Note: Using direct reference to $this to avoid closure issues with object references
				add_action($action_name,
					function ($section) use ($section_id, $position) {
						// Early return if section doesn't match
						if ($section['id'] !== $section_id || empty($this->widgets[$position][$section_id])) {
							return;
						}

						// Execute all callbacks for this section/position
						foreach ($this->widgets[$position][$section_id] as $callback) {
							if (is_callable($callback)) {
								echo call_user_func($callback);
							}
						}
					},
					10,
					1
				);
			}
		}
	}

	// Changed to static method so it can be called without an instance
	public static function render_settings_page()
	{
		// Get the settings page instance
		$settings_page = frl_get_settings_page();

		// Call the instance method
		$settings_page->frl_settings_content();
	}

	public function frl_settings_content()
	{
		// Get the current active tab from Tab Manager
		$active_tab = frl_tab_get_active_tab();

		// Set this to true to enable vertical tabs, false for horizontal
		$use_vertical_tabs = true;

		// Let the Tab Manager render the container start
		frl_tab_render_tab_container_start($use_vertical_tabs, '', $active_tab);

		// Render the header
		echo frl_ui_render_plugin_settings_header();

		$this->frl_setup_tabs($active_tab);

		// Allow adding content before the settings form
		echo apply_filters('frl_before_settings_sections', '');
?>

		<div id="frl-tabs-content">
			<form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="frl-settings-form">
				<input type="hidden" name="action" value="<?php echo frl_prefix('save_options'); ?>">

				<?php
				// Generate a fresh nonce
				frl_nonce_field('save_options', '_wpnonce', true, true);
				?>
				<input type="hidden" name="<?php echo frl_prefix('active_tab'); ?>" id="<?php echo frl_prefix('active_tab'); ?>" value="<?php echo $active_tab; ?>">

				<?php
				// Render all custom tabs from the registry - still inside the form
				frl_tab_render_all_custom_tabs();

				// Use the custom renderer with hooks for widget insertion
				$this->custom_do_settings_sections(FRL_NAME);

				?>
				<?php // submit_button(); // Removed from here, assuming it's in custom_do_settings_sections
				?>
			</form>
		</div>

<?php
		// Allow adding custom content after the settings form
		echo apply_filters(FRL_PREFIX . '_after_settings_content', '');

		// Let the Tab Manager close the container
		frl_tab_render_tab_container_end();
	}

	public function frl_setup_sections()
	{
		if (!empty($this->processed_sections)) {
			return;
		}

		// Use cache_remember to store and retrieve the section structure
		// This caches the processing but still allows for dynamic registration
		$sections_structure = frl_cache_remember('adminui', 'settings_sections', function () {
			$fields = $this->fields;
			$sections = frl_group_array_by_key('section', $fields);
			$section_names = frl_get_default_fields_sections();

			$processed = [];
			foreach ($sections as $key => $section) {
				$processed[$key] = [
					'title' => $section_names[$key],
					'class' => $key,
					// Capture any other metadata but not the actual fields
				];
			}
			return $processed;
		}) ?: [];

		// Register sections with WordPress using the cached structure
		foreach ($sections_structure as $key => $section_data) {
			$this->processed_sections[$key] = true;

			$args = array(
				'before_section' => '<div id="tabs-%s" class="frl-section">',
				'after_section' => '</div>',
				'section_class' => $key,
			);

			// Use cached title instead of processing again
			$title = $section_data['title'];

			// Simple empty callback for all sections
			$callback = function () {};

			add_settings_section(
				$key,
				$title,
				$callback,
				FRL_NAME,
				$args
			);
		}
	}

	/**
	 * Custom implementation of WordPress's do_settings_sections function
	 * that adds proper hooks for injecting widget content.
	 *
	 * This custom renderer mimics the WordPress core functionality while
	 * adding action hooks for better extensibility and widget insertion.
	 *
	 * @param string $page The slug name of the page whose settings sections are being rendered
	 * @return void
	 */
	public function custom_do_settings_sections($page)
	{
		global $wp_settings_sections, $wp_settings_fields;

		if (!isset($wp_settings_sections[$page])) {
			return;
		}

		foreach ((array)$wp_settings_sections[$page] as $section) {
			$section_id = $section['id'];

			// Check if this section is specifically marked as restricted
			$is_restricted_by_capability = apply_filters('frl_is_section_restricted', false, $section_id);
			if ($is_restricted_by_capability) {
				continue; // Skip rendering this restricted section
			}

			if (isset($section['before_section'])) {
				$opening_html = sprintf($section['before_section'], $section_id);
				echo $opening_html;
			} else {
				echo '<div id="' . esc_attr($section_id) . '" class="frl-section">';
			}

			$before_action = FRL_PREFIX . "_before_section_{$section_id}_content";
			do_action($before_action, $section);

			if ($section['title']) {
				echo "<h2>{$section['title']}</h2>\n";
			}

			if ($section['callback']) {
				call_user_func($section['callback'], $section);
			}

			if (isset($wp_settings_fields[$page][$section['id']])) {
				echo '<div class="frl-widget form-section">
					<table class="form-table" role="presentation">';
				do_settings_fields($page, $section['id']);
				echo '</table>';

				submit_button();
				echo '</div>';
			}

			$after_action = FRL_PREFIX . "_after_section_{$section_id}_content";
			do_action($after_action, $section);

			if (isset($section['after_section'])) {
				echo $section['after_section'];
			} else {
				echo '</div>';
			}
		}
	}

	public function frl_setup_fields()
	{
		if (!empty($this->registered_fields)) {
			return;
		}

		if (!frl_is_array_not_empty($this->fields)) {
			$this->fields = [];
			return;
		}

		foreach ($this->fields as $field) {
			if (!isset($field['id'], $field['section'], $field['label'], $field['type'])) {
				continue;
			}

			$this->registered_fields[$field['id']] = true;
			$prefixed_id = frl_prefix($field['id']);

			add_settings_field(
				$prefixed_id,
				$field['label'],
				[$this, 'frl_field_callback'],
				FRL_NAME,
				$field['section'],
				array_merge($field, ['id' => $prefixed_id])
			);
		}
	}

	public function frl_field_callback($field)
	{
		if (!isset($field['type'], $field['id'])) {
			return;
		}

		// Field ID (will include the prefix)
		$field_id = $field['id'];

		// Original key without prefix - for getting the current value and hidden input name
		$original_key = substr($field_id, strlen(FRL_PREFIX) + 1);

		// Handle formatting field types
		if (in_array($field['type'], FRL_FIELD_FORMATTERS)) {
			echo frl_ui_render_formatting_field($field, $field['type']);
			return; // Formatting fields are fully handled, no option value needed.
		}

		// Common attributes for non-formatting fields
		$is_restricted = !empty($field['restricted']) && $field['restricted'];
		$is_admin = frl_has_access();
		$disabled_attr = ($is_restricted && !$is_admin) ? 'disabled="disabled"' : '';
		$restricted_class = $field['type'];
		$restricted_class .= ($is_restricted && !$is_admin) ? FRL_PREFIX . '-restricted-field' : '';

		// Handle 'custom' type separately as it gets its value from a callback, not an option.
		if ($field['type'] === 'custom' && isset($field['callback']) && is_callable($field['callback'])) {
			$value = call_user_func($field['callback']);
			echo '<div class="frl-custom-field ' . esc_attr($restricted_class) . '">' . $value . '</div>';

			$description = $field['description'] ?? '';
			if ($description) {
				printf('<p class="description">%s</p>', wp_kses_post($description));
			}
			if ($is_restricted && !$is_admin) {
				echo '<p class="frl-restricted-message">' . esc_html__('Critical field restricted to plugin admin', FRL_PREFIX) . '</p>';
				echo '<input type="hidden" name="' . esc_attr(frl_prefix('field_restricted')) . '[]" value="' . esc_attr($original_key) . '" />';
			}
		} else {
			// This block is for all other standard field types that DO represent options.
			$value = frl_get_option($original_key, true);
			$value = $value ?? '';

			$renderer_args = array_merge($field, [
				'disabled' => $disabled_attr,
				'restricted_class' => $restricted_class,
				'description_html' => $field['description'] ?? '',
				'is_restricted_field' => $is_restricted,
				'current_user_can_edit' => $is_admin,
				'original_field_key' => $original_key
			]);

			echo frl_ui_render_field($renderer_args, $value);
		}
	}

	public function frl_setup_tabs($active_tab = 0)
	{
		// Get sections from fields for registering tabs
		$section_names = frl_get_default_fields_sections();
		$section_keys = array_keys($section_names);

		// Add section titles as tab labels for backward compatibility
		foreach ($section_keys as $key => $name) {
			if (!isset($this->tab_labels[$key])) {
				$this->tab_labels[$key] = $section_names[$name];
			}
		}

		// Use the Tab Manager to render tabs from sections
		frl_tab_render_tabs_from_sections($section_keys);

		// Store the active tab for use elsewhere in this class if needed
		$this->active_tab = $active_tab;
	}

	/**
	 * Validates a field configuration to ensure it has the required properties
	 *
	 * @param array $field The field configuration array to validate
	 * @throws InvalidArgumentException If validation fails
	 */
	private function validate_field($field)
	{
		$allowed_types = FRL_FIELD_TYPES;

		if (!in_array($field['type'], $allowed_types)) {
			throw new InvalidArgumentException(
				"Invalid field type: {$field['type']}"
			);
		}

		// For formatting-only fields, we have different requirements
		if (in_array($field['type'], FRL_FIELD_FORMATTERS)) {
			return;
		}

		if (empty($field['id'])) {
			throw new InvalidArgumentException(
				"Field ID is required"
			);
		}

		if (empty($field['label'])) {
			throw new InvalidArgumentException(
				"Field label is required for ID: {$field['id']}"
			);
		}
	}

	/**
	 * Sanitizes field values based on their field type
	 *
	 * @param string $type The field type (checkbox, text, email, etc.)
	 * @param mixed $value The value to sanitize
	 * @return mixed The sanitized value
	 */
	private static function sanitize_field_value($type, $value)
	{
		switch ($type) {
			case 'checkbox':
				return (int)!!$value;
			case 'number':
				return is_numeric($value) ? floatval($value) : 0;
			case 'email':
				return sanitize_email($value);
			case 'textarea':
				return wp_kses_post($value);
			case 'wysiwyg':
				return wp_kses_post($value);
			case 'textlist':
				return sanitize_textarea_field($value);
			case 'checkboxes':
				return frl_is_array_not_empty($value) ? array_map('sanitize_text_field', $value) : [];
			case 'html':
			case 'custom':
				return $value; // Don't sanitize HTML content to preserve PHP tags
			default:
				return sanitize_text_field($value);
		}
	}

	/**
	 * Handle saving options from the settings form - Public Entry Point
	 */
	public static function handle_save_options()
	{
		// 1. Verify nonce and get initial redirect info
		list($redirect_url_base, $redirect_args) = self::_verify_save_nonce();

		// 2. Determine active tab info
		list($active_tab_index, $active_tab_id, $active_tab_fragment) = self::_get_active_tab_info();
		$redirect_args['tab'] = $active_tab_index; // Use numeric index for redirect arg

		// 3. Save active tab index for potential use after redirect
		frl_tab_save_active_tab($active_tab_index);

		// 4. Get all potentially submitted values, sanitized
		$submitted_values = self::_get_submitted_values();

		// 5. Apply validation rules for the active tab
		list($validated_values, $validation_errors) = self::_apply_field_validation(
			$active_tab_id,
			$submitted_values
		);

		// 6. Compare with current options and prepare batch for actual updates
		$updates = self::_prepare_update_batch($validated_values);

		// 7. Perform updates, handle notices, and redirect
		self::_perform_updates_and_redirect(
			$updates,
			$validation_errors,
			$redirect_url_base,
			$redirect_args,
			$active_tab_fragment // Pass the fragment for the final URL
		);
	}

	/**
	 * Verify nonce for saving options.
	 *
	 * @return array [redirect_url_base, initial_redirect_args]
	 */
	private static function _verify_save_nonce(): array
	{
		$redirect_url_base = admin_url('options-general.php');
		$redirect_args = [
			'page' => FRL_NAME,
			// 'tab' will be added later
		];

		// Use centralized nonce verification (assuming frl_verify_admin_action_nonce handles exit on failure)
        frl_verify_admin_action_nonce('_wpnonce', FRL_PREFIX . '_save_options', $redirect_url_base, $redirect_args);

		return [$redirect_url_base, $redirect_args];
	}

	/**
	 * Determine active tab information from POST data.
	 *
	 * @return array [active_tab_index, active_tab_id, active_tab_fragment]
	 */
	private static function _get_active_tab_info(): array
	{
		$post_action = frl_prefix('active_tab');
		$active_tab_value = isset($_POST[$post_action]) ? sanitize_text_field($_POST[$post_action]) : '';
		$is_fragment = !empty($active_tab_value) && str_starts_with($active_tab_value, 'tabs-');

		$active_tab_id = null;
		$active_tab_index = 0; // Default to first tab index
		$active_tab_fragment = null;

		if ($is_fragment) {
			$active_tab_fragment = $active_tab_value; // e.g., tabs-general
			$active_tab_id = substr($active_tab_value, 5); // e.g., general

			// Find the numeric index corresponding to this ID
			$all_tabs = frl_tab_get_sorted_tabs(); // Use the public static method
			foreach ($all_tabs as $index => $tab_data) {
				if ($tab_data['id'] === $active_tab_id) {
					$active_tab_index = $index;
					break;
				}
			}
		} else {
			// Legacy: Use numeric index directly from POST if available
			$active_tab_index = isset($_POST[$post_action]) ? intval($_POST[$post_action]) : 0;

			// Find the ID corresponding to this numeric index
			$all_tabs = frl_tab_get_sorted_tabs(); // Use the public static method
			if (isset($all_tabs[$active_tab_index])) {
				$active_tab_id = $all_tabs[$active_tab_index]['id'];
				$active_tab_fragment = 'tabs-' . $active_tab_id; // Construct fragment
			}
		}

		return [$active_tab_index, $active_tab_id, $active_tab_fragment];
	}

	/**
	 * Get sanitized values for all submitted fields.
	 *
	 * @return array Associative array of [option_key => sanitized_value]
	 */
	private static function _get_submitted_values(): array
	{
		$all_fields = frl_get_all_plugin_options_settings(null);
		$submitted_values = [];
		$restricted_fields_keys = isset($_POST[frl_prefix('field_restricted')]) ?
			array_map('sanitize_text_field', $_POST[frl_prefix('field_restricted')]) : [];

		foreach ($all_fields as $field) {
			if (!isset($field['id'])) continue;

			// Skip formatting fields
			if (isset($field['type']) && in_array($field['type'], FRL_FIELD_FORMATTERS)) {
				continue;
			}

			// Skip fields explicitly marked not to be saved
			if (isset($field['save_option']) && $field['save_option'] === false) {
				continue;
			}

			$option_key = $field['id'];
			$prefixed_id = frl_prefix($option_key);

			// Skip restricted fields if current user doesn't have access
			if (
				in_array($option_key, $restricted_fields_keys) ||
				(isset($field['restricted']) && $field['restricted'] && !frl_has_access())
			) {
				continue;
			}

			// Determine the submitted value based on field type
			$submitted_value = null;
			if ($field['type'] === 'checkbox') {
				$submitted_value = isset($_POST[$prefixed_id]) ? 1 : 0;
			} elseif (isset($_POST[$prefixed_id])) {
				// Note: sanitize_field_value handles the actual sanitization based on type
				// Crucially, 'html' and 'custom' are returned as-is here.
				$submitted_value = self::sanitize_field_value($field['type'], $_POST[$prefixed_id]);
			} else {
				// Field not in submission, skip
				continue;
			}

			$submitted_values[$option_key] = $submitted_value;
		}

		return $submitted_values;
	}

	/**
	 * Apply validation rules to submitted values.
	 *
	 * @param string|null $active_tab_id ID of the active tab.
	 * @param array $submitted_values Array of [option_key => sanitized_value].
	 * @return array [validated_values, validation_errors]
	 */
	private static function _apply_field_validation(?string $active_tab_id, array $submitted_values): array
	{
		$validation_rules = $active_tab_id ? frl_tab_get_validation_rules($active_tab_id) : [];
		$validated_values = [];
		$validation_errors = [];

		$all_fields = frl_get_all_plugin_options_settings(null);
		$fields_map = array_column($all_fields, null, 'id'); // Map fields by id for quick lookup

		foreach ($submitted_values as $option_key => $new_value) {
			// Apply tab-specific validation if available
			if (isset($validation_rules[$option_key]) && is_callable($validation_rules[$option_key])) {
				$current_value = frl_get_option($option_key); // Get current value for context
				$field_config = $fields_map[$option_key] ?? null; // Get field config for context

				$validation_result = call_user_func(
					$validation_rules[$option_key],
					$new_value,
					$current_value,
					$field_config
				);

				// If validation returns an error message, store it and skip this field
				if (is_string($validation_result)) {
					$validation_errors[$option_key] = $validation_result;
					continue; // Skip adding to validated values
				}

				// If validation returns a modified value, use that instead
				if ($validation_result !== true) {
					$new_value = $validation_result;
				}
			}

			$validated_values[$option_key] = $new_value;
		}

		return [$validated_values, $validation_errors];
	}

	/**
	 * Compare validated values with current options and prepare update batch.
	 *
	 * @param array $validated_values Array of [option_key => validated_value].
	 * @return array Array of [option_key => new_value] for options that need updating.
	 */
	private static function _prepare_update_batch(array $validated_values): array
	{
		$updates = [];
		$all_fields = frl_get_all_plugin_options_settings(null);

		$fields_map = array_column($all_fields, null, 'id'); // Map fields by id for quick lookup

		foreach ($validated_values as $option_key => $new_value) {
			// Get current value using frl_get_option with cache bypass
			// to ensure we compare against the DB state at the start of this request.
			$current_value = frl_get_option($option_key, true);
			$field_config = $fields_map[$option_key] ?? null;
			$field_type = $field_config['type'] ?? 'text';

			// Special handling for HTML content specifically
			if ($field_type === 'html') {
				$new_value = frl_normalize_html_content($new_value);
			}

			// Compare current and new values before adding to updates
			// Apply specific comparison logic based on type
			$compare_current = $current_value;
			$compare_new = $new_value;

			if ($field_type === 'html') {
				$compare_current = frl_normalize_html_content($current_value, true);
				$compare_new = frl_normalize_html_content($new_value, true);
			} elseif (in_array($field_type, ['textarea', 'textlist'])) {
				$compare_current = frl_normalize_text_for_comparison($current_value);
				$compare_new = frl_normalize_text_for_comparison($new_value);
			} else {
				// Default string comparison for other types
				$compare_current = (string)$current_value;
				$compare_new = (string)$new_value;
			}

			// Only add to updates if the value has changed
			if ($compare_new !== $compare_current) {
				$updates[$option_key] = $new_value;
			}
		}

		return $updates;
	}

	/**
	 * Perform batch updates, add admin notices, and redirect.
	 *
	 * @param array $updates Options to update [option_key => new_value].
	 * @param array $validation_errors Validation errors [option_key => error_message].
	 * @param string $redirect_url_base Base URL for redirection.
	 * @param array $redirect_args Query arguments for redirection.
	 * @param string|null $fragment Optional URL fragment (e.g., #tabs-general).
	 */
	private static function _perform_updates_and_redirect(
		array $updates,
		array $validation_errors,
		string $redirect_url_base,
		array $redirect_args,
		?string $fragment = null
	) {
		$updated = false;
		$updated_count = 0;

		if (!empty($updates)) {
			// Use the utility function to update options in batch
			$updated_count = frl_batch_update_options($updates);

			if ($updated_count > 0) {
				frl_add_admin_notice(
					sprintf(__('%d settings updated.', FRL_PREFIX), $updated_count),
					'success'
				);
				$updated = true;
				// Fire an action with the updated options
				do_action('frl_settings_updated', $updates);
			}
		}

		// Add validation error notices
		if (!empty($validation_errors)) {
			foreach ($validation_errors as $field_id => $error_message) {
				frl_add_admin_notice(
					__('Validation error for ', FRL_PREFIX) . $field_id . ': ' . $error_message,
					'error'
				);
			}
		}

		// Build the final redirect URL
		$redirect_args['settings-updated'] = $updated ? 'true' : 'false';
		$redirect_args['validation-errors'] = !empty($validation_errors) ? count($validation_errors) : false;
		$redirect_url = add_query_arg($redirect_args, $redirect_url_base);

		// Add fragment identifier if provided
		if (!empty($fragment)) {
			$redirect_url .= '#' . $fragment;
		}

		// Use frl_safe_redirect instead of wp_redirect
		frl_safe_redirect($redirect_url);
		exit;
	}

	/**
	 * Register a widget for a specific section
	 *
	 * This allows inserting custom content before or after a section's fields.
	 * Widgets are rendered via WordPress action hooks when sections are displayed.
	 *
	 * The widgets are inserted using the following actions:
	 * - 'frl_before_section_{$section_id}_content' - Called before the section content
	 * - 'frl_after_section_{$section_id}_content' - Called after the section content
	 *
	 * Multiple widgets can be registered for the same section/position. They will be
	 * rendered in the order they were registered.
	 *
	 * @param string $section_id The section ID to add the widget to
	 * @param callable $callback Function that returns the widget content
	 * @param string $position Position of the widget ('before' or 'after'), defaults to 'after'
	 */
	public function register_widget($section_id, $callback, $position = 'after')
	{
		if (!is_callable($callback)) {
			return;
		}

		$position = ($position === 'before') ? 'before' : 'after';

		// Initialize the array if needed and add the callback
		$this->widgets[$position][$section_id] = $this->widgets[$position][$section_id] ?? [];
		$this->widgets[$position][$section_id][] = $callback;
	}
}