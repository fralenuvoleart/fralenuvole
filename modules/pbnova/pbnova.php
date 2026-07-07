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

add_filter('frl_breadcrumbs_extra_items',
    'frl_pbnova_breadcrumbs_extra_items',
    10,
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
		$old = frl_get_post_meta($post_id, 'has_content', true);
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
	$old = frl_get_post_meta($post_id, 'has_content', true);
	if ($old !== $new) {
		update_post_meta($post_id, 'has_content', $new);
	}
}

/**
	* Inject PB Nova-specific breadcrumb items for the 'service' and 'jurisdiction' CPTs.
	*
	* Hooked into the generic [frl_breadcrumbs] shortcode's 'frl_breadcrumbs_extra_items'
	* filter (see public/shortcodes.php:frl_shortcode_breadcrumbs()) so this brand-specific
	* business rule lives in the PB Nova module instead of the shared core shortcode file.
	* Runs after ancestors are computed but before they're appended to $links, matching the
	* original inline placement exactly.
	*
	* - 'service' CPT: inserts the post's first 'jurisdiction' relation (if any, and not
	*   already an ancestor) as a breadcrumb link.
	* - 'jurisdiction' CPT: prepends a link to the 'jurisdictions' archive page.
	*
	* @param array   $links     Breadcrumb link HTML strings built so far.
	* @param WP_Post $post      The queried singular post.
	* @param int[]   $ancestors Reversed list of ancestor post IDs (root first).
	* @return array Updated breadcrumb link HTML strings.
	*/
function frl_pbnova_breadcrumbs_extra_items($links, $post, $ancestors)
{
	if (!($post instanceof WP_Post)) {
		return $links;
	}

	$post_type = get_post_type($post);

	// Insert first jurisdiction (if any) only for the 'service' CPT.
	// frl_extract_relation_ids() is the same normalization helper
	// frl_shortcode_meta_rel() uses internally, so behavior (including
	// dedup) stays identical to reading it via that shortcode.
	if ($post_type === 'service') {
		$jur_raw = frl_get_post_meta($post->ID, 'jurisdiction', true);
		$jur_ids = frl_extract_relation_ids($jur_raw);
		$first_jur_id = !empty($jur_ids) ? $jur_ids[0] : 0;
		if ($first_jur_id > 0 && !in_array($first_jur_id, $ancestors, true)) {
			$jur_title = get_the_title($first_jur_id);
			$jur_link  = get_permalink($first_jur_id);
			if ($jur_title && $jur_link) {
				$links[] = sprintf('<a href="%s">%s</a>', esc_url($jur_link), esc_html($jur_title));
			}
		}
	}

	// Prepend the 'jurisdictions' page for the 'jurisdiction' CPT.
	if ($post_type === 'jurisdiction') {
		$pg_title = frl_get_translation('Jurisdictions');
		$pg_link = frl_get_translation_permalink('jurisdictions');
		if ($pg_link && $pg_title) {
			$links[] = sprintf('<a href="%s">%s</a>', esc_url($pg_link), esc_html($pg_title));
		}
	}

	return $links;
}
