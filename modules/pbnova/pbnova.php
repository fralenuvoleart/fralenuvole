<?php

/**
 * Module Name: PB Nova Module
 * Description: Customizations for PB Nova (Service form filters, custom post-types, etc.)
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Load constants
require_once __DIR__ . '/config-constants-pbnova.php';

// Load public scripts
add_action('init',
    'frl_pbnova_load_public_scripts',
    10,
    0);

add_filter('wsf_pre_render',
    'frl_pbnova_set_monday_field',
    10,
    2);

add_action('wp_after_insert_post',
    'frl_pbnova__acf_update_has_content',
    20,
    3);

/**
 * Add common scripts
 */
 function frl_pbnova_load_public_scripts()
 {
    $assets = ['pbnova-public-js' => 'modules/pbnova/assets/js/public.js'];
    frl_enqueue_scripts($assets, 'pbnova_public');
}

/**
 * Translate labels and options, and set field default values
 */
function frl_pbnova_set_monday_field( $form, $preview )
{
    /** @disregard P1010 Undefined type */
    $fields = wsf_form_get_fields( $form );

    foreach( $fields as $object ) {
        /** @disregard P1010 Undefined type */
        $field = wsf_field_get_object( $form, $object->id );

        if( isset( $field->meta->default_value ) ) {
            $default = $field->meta->default_value;

            if( 'Service-Type' == $field->label ) {
                $field->meta->default_value = $default;
            }
        }
    }

    return $form;
}

function frl_pbnova__acf_update_has_content($post, $update, $post_before) {
	// Normalize $post to WP_Post for safety (codex allows int|WP_Post)
	if (!($post instanceof WP_Post)) {
		$post = get_post((int) $post);
		if (!$post) {
			return;
		}
	}

	// Scope to target post types early
	if (!in_array($post->post_type, PBNOVA_ACF_AUTO_CONTENT, true)) {
		return;
	}

	$post_id = (int) $post->ID;

	// Cheap guards first
	if (wp_is_post_revision($post_id)) {
		return;
	}
    if (frl_is_doing_ajax() || frl_is_cron_job_request() || frl_is_cli_request()) {
        return; // keep REST allowed
    }
	if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
		return;
	}
	if (in_array($post->post_status, ['auto-draft', 'inherit', 'trash'], true)) {
		return;
	}

	// No content change → skip work
	if ($update && ($post_before instanceof WP_Post)) {
		if ((string) $post->post_content === (string) $post_before->post_content) {
			return;
		}
	}

	// Fast path: empty content string → definitely no content
	$content = (string) ($post->post_content ?? '');
	if ($content === '') {
		$new = '0';
		$old = get_post_meta($post_id, 'has_content', true);
		if ($old !== $new) {
			update_post_meta($post_id, 'has_content', $new);
		}
		return;
	}

	// Remove comments only if present (saves regex)
	if (strpos($content, '<!--') !== false) {
		$content = preg_replace('/<!--.*?-->/s', '', $content);
	}
	// Remove shortcodes only if present (saves parser)
	if (strpos($content, '[') !== false) {
		$content = strip_shortcodes($content);
	}

	// Strip tags and whitespace
	$text = trim(wp_strip_all_tags(htmlspecialchars_decode($content, ENT_QUOTES), true));

	$new = ($text !== '') ? '1' : '0';
	$old = get_post_meta($post_id, 'has_content', true);
	if ($old !== $new) {
		update_post_meta($post_id, 'has_content', $new);
	}
}
