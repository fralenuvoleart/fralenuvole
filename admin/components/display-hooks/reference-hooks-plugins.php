<?php
/**
 * Comprehensive WordPress Hooks List - Master Reference
 *
 * This file contains a single master list of WordPress hooks with:
 * - Global execution order
 * - Context (admin, public, all)
 * - Hook type (action, filter)
 * - User login status (logged-in, logged-out, both)
 * - Number of default arguments
 * - Short description of what the hook does
 *
 * Based on WordPress 6.5.5
 */

$plugins_hooks_master_sequence = [
    // Fralenuvole Plugin Hooks
    'frl_default_fields' => [
        'order' => 7,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Plugin custom Hook on hook plugins_loaded / wsf_submit_post_complete for frl_load_modules to allow modules to add their own fields'
    ],
    'frl_wsf_send_form_submission_webhook' => [
        'order' => 7,
        'context' => 'public',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Plugin custom Hook on plugins_loaded / wsf_submit_post_complete, to schedule a background event to send the submitted data to a webhook'
    ],
    'frl_block_translation_filter' => [
        'order' => 7,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Plugin custom Hook on frl_process_block_translation filter to translate additional strings'
    ],
    'frl_settings_updated' => [
        'order' => 7,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Plugin custom Hook on options saved by admin_post_frl_save_options'
    ],
    'frl_is_section_restricted' => [
        'order' => 8,
        'context' => 'admin',
        'hook_type' => 'filter',
        'args' => 2,
        'user_logged' => 'logged-in',
        'description' => 'Plugin custom Hook on plugins_loaded hook for frl_load_plugin_ui for Tab Manager'
    ],
    'frl_dashboard_content' => [
        'order' => 8,
        'context' => 'admin',
        'hook_type' => 'action',
        'args' => 0,
        'user_logged' => 'logged-in',
        'description' => 'Plugin custom Hook on plugins_loaded hook for frl_load_plugin_ui for Dashboard tab'
    ],
    'frl_before_section_settings_content' => [
        'order' => 9,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Plugin custom Hook on plugins_loaded hook for frl_load_plugin_ui for Settings tab'
    ],
    'frl_after_section_settings_content' => [
        'order' => 9,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Plugin custom Hook on plugins_loaded hook for frl_load_plugin_ui for Settings tab'
    ],
    'frl_developer_content' => [
        'order' => 8,
        'context' => 'admin',
        'hook_type' => 'action',
        'args' => 0,
        'user_logged' => 'logged-in',
        'description' => 'Plugin custom Hook on plugins_loaded hook for frl_load_plugin_ui for Developer tab'
    ],
    'frl_before_section_developer_content' => [
        'order' => 9,
        'context' => 'admin',
        'hook_type' => 'action',
        'args' => 0,
        'user_logged' => 'logged-in',
        'description' => 'Plugin custom Hook on admin_menu hook for frl_render_admin_ui for Developer Settings content'
    ],
    'frl_after_section_developer_content' => [
        'order' => 9,
        'context' => 'admin',
        'hook_type' => 'action',
        'args' => 0,
        'user_logged' => 'logged-in',
        'description' => 'Plugin custom Hook on admin_menu hook for frl_render_admin_ui for Developer Settings content'
    ],
    // Rewriter & Translator internal hooks
    'frl_rewriter_register_features' => [
        'order' => 10,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Action fired to allow external code to register additional rewriter features.'
    ],
    'frl_translator_term_meta' => [
        'order' => 11,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 4,
        'description' => 'Filters term meta values during translation handling.'
    ],
    'frl_translator_acf_link' => [
        'order' => 12,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Custom formatter for ACF link fields during translation.'
    ],
    'frl_translator_acf_repeater' => [
        'order' => 13,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Custom formatter for ACF repeater fields during translation.'
    ],
    'frl_daily_cache_cleanup' => [
        'order' => 800,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Plugin custom Hook on daily cache cleanup'
    ],
    'frl_execute_rewrite_flush' => [
        'order' => 800,
        'context' => 'core',
        'hook_type' => 'action',
        'user_logged' => 'both',
        'args' => 0,
        'description' => 'Deferred custom flush hook for rewrite rules.'
    ],

    // ACF core hooks
	'acf/init' => [
		'order' => 405,
		'context' => 'core',
		'hook_type' => 'action',
		'user_logged' => 'both',
		'args' => 0,
		'description' => 'Fires after ACF has initialized; register fields and local JSON here.'
	],
	'acf/format_value/type=*' => [
		'order' => 409,
		'context' => 'core',
		'hook_type' => 'filter',
		'user_logged' => 'both',
		'args' => 3,
		'description' => 'Filters the value for any ACF field type after it is loaded.'
	],
    'acf/format_value/type=link' => [
        'order' => 410,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the value of an ACF link field after it is loaded.'
    ],
    'acf/format_value/type=repeater' => [
        'order' => 411,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 3,
        'description' => 'Filters the value of an ACF repeater field after it is loaded.'
    ],
	'acf/format_value/type=taxonomy' => [
		'order' => 412,
		'context' => 'core',
		'hook_type' => 'filter',
		'user_logged' => 'both',
		'args' => 3,
		'description' => 'Filters the value of an ACF taxonomy field after it is loaded.'
	],
	'acf/save_post' => [
		'order' => 415,
		'context' => 'core',
		'hook_type' => 'action',
		'user_logged' => 'both',
		'args' => 1,
		'description' => 'Fires after ACF saves field values for a given $post_id.'
	],
	'acf_quick_edit_fields_types' => [
		'order' => 420,
		'context' => 'admin',
		'hook_type' => 'filter',
		'user_logged' => 'logged-in',
		'args' => 1,
		'description' => 'Filters the list of ACF field types enabled for Quick Edit.'
	],

    // Polylang hooks
    'pll_current_language' => [
        'order' => 4000,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Allows plugins to modify the list of post types which will be filtered by language in Polylang'
    ],
    'pll_get_post_types' => [
        'order' => 4010,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Allows plugins to modify the list of post types which will be filtered by language in Polylang'
    ],
    'pll_save_strings_translations' => [
        'order' => 4020,
        'context' => 'admin',
        'hook_type' => 'action',
        'user_logged' => 'logged-in',
        'args' => 0,
        'description' => 'Fires after string translations are saved in Polylang'
    ],

    // WS Form plugin hooks
    'wsf_pre_render' => [
        'order' => 401,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the form object prior to the form being rendered in WS Form'
    ],
    // WS Form plugin hooks
    'wsf_submit_post_complete' => [
        'order' => 401,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the form object prior to the form being rendered in WS Form'
    ],
    'wsf_field_invalid_feedback_text' => [
        'order' => 401,
        'context' => 'public',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 1,
        'description' => 'Filters the default invalid feedback text shown on a field if it is not validated in WS Form'
    ],

    // Geodirectory hooks
    'geodir_posts_where' => [
        'order' => 902,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the posts_where queries in Geodirectory plugin'
    ],
    'geodir_filter_widget_listings_where' => [
        'order' => 902,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the widgets posts_where queries in Geodirectory plugin'
    ],

    // The SEO Framework hooks
    'the_seo_framework_title_from_generation' => [
        'order' => 5000,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the generated title in The SEO Framework plugin'
    ],
    'the_seo_framework_custom_field_description' => [
        'order' => 5001,
        'context' => 'core',
        'hook_type' => 'filter',
        'user_logged' => 'both',
        'args' => 2,
        'description' => 'Filters the custom field description in The SEO Framework plugin'
    ],
    'emr/feature/background' => [
        'order' => 5001,
        'context' => 'admin',
        'hook_type' => 'filter',
        'user_logged' => 'logged-in',
        'args' => 1,
        'description' => 'Filters the custom field description in The SEO Framework plugin'
    ],
];

// Return the array directly using the correct name
return $plugins_hooks_master_sequence;
