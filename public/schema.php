<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * schema.php - Code executed only on frontend pages
 */

// wp_footer hook registered in public/public.php

/**
 * Add Schema markup
 * @return html
 */
function frl_add_schema()
{
    $post_type = get_post_type();

    if ($post_type === 'post' && frl_get_option('schema_article')) {
        frl_render_schema('article');
    } elseif ($post_type === 'service' && frl_get_option('schema_service')) {
        frl_render_schema('service');
    } elseif ($post_type === 'portfolio' && frl_get_option('schema_portfolio')) {
        frl_render_schema('portfolio');
    } elseif ($post_type === 'page') {
        $schema_org = frl_get_option('schema_organization');
        $schema_person = frl_get_option('schema_person');
        if ($schema_org) {
            frl_render_schema('organization');
        } elseif ($schema_person) {
            frl_render_schema('person');
        }
    }
}

function frl_render_schema($schema_type)
{
    // Template loading with proper cache_remember
    $schema_templates = frl_get_schema_templates();

    // Build cache key
    $post_id = get_the_ID();

    $cache_key = "schema_{$schema_type}_post_{$post_id}";

    // Get schema data only (no output)
    $schema = frl_cache_remember('metadata', $cache_key, function () use ($schema_templates, $schema_type) {

        // Get post data efficiently
        $post = get_post();
        if (!$post) {
            return '';
        }

        // Single DB call to get all post meta for this post
        $post_meta = get_post_meta($post->ID);

        // Extract data from post object directly
        $description = $post->post_excerpt;
        if (empty($description)) {
            $content = wp_strip_all_tags($post->post_content);
            $description = wp_trim_words($content, 55, '...');
        }

        // Get thumbnail - still requires a DB call
        $thumbnail = wp_get_attachment_image_src(get_post_thumbnail_id($post->ID), 'full') ?: [null, null, null];

        // Basic replacements using direct post data
        $replacements = [
            '{{TITLE}}' => $post->post_title,
            '{{DESCRIPTION}}' => $description,
            '{{IMAGE}}' => $thumbnail[0] ?? '',
            '{{URL}}' => get_permalink($post->ID),
            '{{DATE}}' => get_the_date('c', $post->ID),
        ];

        // Schema-specific replacements
        if ('person' == $schema_type) {

            // --- DEBUG LOGGING FOR ANONYMOUS PERSON OPTIONS REMOVED ---

            $replacements = array_merge($replacements, [
                '{{PERSON_NAME}}' => frl_get_option('schema_owner_name') ?: '',
                '{{PERSON_DESCRIPTION}}' => frl_get_option('schema_owner_description') ?: '',
                '{{PERSON_IMAGE}}' => frl_get_option('schema_owner_image') ?: '',
                '{{PERSON_URL}}' => frl_get_option('schema_owner_url') ?: '',
                '{{PERSON_ROLE}}' => frl_get_option('schema_owner_role') ?: '',
                '{{PERSON_SAMEAS}}' => frl_get_option('schema_owner_sameas') ?: ''
            ]);
        } elseif ('article' == $schema_type) {
            // Use prefetched post meta
            $author = isset($post_meta['post-settings_post-authors']) ? $post_meta['post-settings_post-authors'][0] : '';
            $author_name = $author_role = $author_sameas = '';

            if (empty($author)) {
                $author_id = $post->post_author;
                $author_name = get_the_author_meta('author_role', $author_id) ?: '';
                $author_role = get_the_author_meta('display_name', $author_id) ?: '';
                $author_sameas = get_author_posts_url($author_id) ?: '';
            } else {
                $author_id = isset($author['0']) ? false : $author['0'];
                if (is_numeric($author_id)) {
                    $author_name = get_the_title($author_id) ?: '';
                    // Get team role directly from meta
                    $author_role = isset($post_meta['team-settings_team-role']) ?
                        $post_meta['team-settings_team-role'][0] : '';
                    $author_sameas = get_permalink($author_id) ?: '';
                }
            }

            $replacements = array_merge($replacements, [
                '{{AUTHOR}}' => $author_name,
                '{{AUTHOR_ROLE}}' => $author_role,
                '{{AUTHOR_WEBSITE}}' => get_bloginfo('name'),
                '{{AUTHOR_SAMEAS}}' => $author_sameas,
            ]);
        } elseif ('portfolio' == $schema_type) {

            $replacements['{{PORTFOLIO_AUTHOR}}'] = frl_get_option('schema_owner_name') ?: '';
        } elseif ('organization' == $schema_type || 'service' == $schema_type) {
            // Get website options efficiently
            $website_data = frl_get_website_options();

            // Use prefetched meta for service type
            $service_type = isset($post_meta['service-settings_service-type']) ?
                $post_meta['service-settings_service-type'][0] : '';

            $business_data = [
                '{{BUSINESS_NAME}}' => $website_data ? ($website_data['business_name'] ?? '') : '',
                '{{BUSINESS_URL}}' => home_url(),
                '{{BUSINESS_IMAGE}}' => (isset($website_data['logo']) && !empty($website_data['logo'][0])) ? $website_data['logo'][0] : '',
                '{{BUSINESS_CATEGORY}}' => $service_type,
                '{{BUSINESS_PHONE}}' => $website_data ? ($website_data['phone'] ?? '') : '',
                '{{BUSINESS_EMAIL}}' => $website_data ? ($website_data['email'] ?? '') : '',
                '{{BUSINESS_ADDRESS}}' => $website_data ? ($website_data['address_street'] ?? '') : '',
                '{{BUSINESS_CITY}}' => $website_data ? ($website_data['address_city'] ?? '') : '',
                '{{BUSINESS_ZIP}}' => $website_data ? ($website_data['address_zip'] ?? '') : '',
                '{{BUSINESS_COUNTRY}}' => $website_data ? ($website_data['address_country'] ?? '') : '',
                '{{BUSINESS_MAP}}' => $website_data ? ($website_data['map'] ?? '') : '',
                '{{BUSINESS_LINKEDIN}}' => $website_data ? ($website_data['linkedin'] ?? '') : '',
                '{{BUSINESS_FACEBOOK}}' => $website_data ? ($website_data['facebook'] ?? '') : '',
                '{{BUSINESS_INSTAGRAM}}' => $website_data ? ($website_data['instagram'] ?? '') : '',
            ];

            $replacements = array_merge($replacements, $business_data);
        } else {
            return '';
        }

        // Process replacements
        foreach ($replacements as $key => $value) {
            if (empty($value)) {
                $replacements[$key] = ''; // Explicit empty string
            } else {
                // JSON encode the value to properly escape special characters
                $replacements[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                // Remove the surrounding quotes that json_encode adds
                $replacements[$key] = substr($replacements[$key], 1, -1);
            }
        }

        // Generate and validate schema
        $raw_schema = strtr($schema_templates[$schema_type], $replacements);
        $decoded = json_decode($raw_schema);

        if (!$decoded) {
            return '';
        }

        $schema = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return (!empty($schema) && $schema !== 'null') ? $schema : '';
    });

    // Handle output separately
    if (!empty($schema) && $schema !== 'null') {

        /** Output the schema HTML tag */
        printf(
            '
<script id="%s-schema" data-lastmod="%s" data-plugin="%s" data-parsing="schema-%s" type="application/ld+json">
%s
</script>',
            FRL_PREFIX,
            date('y-m-d-H:i'),
            FRL_NAME,
            $schema_type,
            $schema
        );

        // Force output buffer flush
        $is_logged_in = is_user_logged_in(); // Check login status
        if (!$is_logged_in) { // Flush for any anonymous request where schema is printed
            flush();
        }
    } else {
    }
}

/**
 * Get website data
 * @return array
 */
function frl_get_website_options()
{
    return frl_cache_remember('options', 'schema_website', function () {
        // Optimize the options lookup
        static $options_cache = null;

        if ($options_cache === null) {
            // Same core behavior as original, but with minor optimization
            $all_options = wp_load_alloptions();
            $prefix = 'website-options_';
            $prefix_len = strlen($prefix);

            $options_cache = [];
            // More efficient filtering using strncmp with early exit
            foreach ($all_options as $name => $value) {
                if (0 === strncmp($name, $prefix, $prefix_len)) {
                    $options_cache[$name] = $value;
                }
            }
        }

        $options = $options_cache;

        // The website data array remains exactly the same
        $website_data = [
            'business_name' => get_bloginfo('name'),
            'logo' => wp_get_attachment_image_src(get_theme_mod('custom_logo'), 'full') ?: [null, null, null],
            'phone' => $options['website-options_general-settings_phone'] ?? '',
            'email' => $options['website-options_general-settings_email'] ?? '',
            'address_street' => $options['website-options_general-settings_address-street'] ?? '',
            'address_city' => $options['website-options_general-settings_address-city'] ?? '',
            'address_zip' => $options['website-options_general-settings_address-zip'] ?? '',
            'address_country' => $options['website-options_general-settings_address-country-code'] ?? '',
            'map' => $options['website-options_general-settings_map'] ?? '',
            'linkedin' => $options['website-options_social-media_linkedin'] ?? '',
            'facebook' => $options['website-options_social-media_facebook'] ?? '',
            'instagram' => $options['website-options_social-media_instagram'] ?? ''
        ];
        return $website_data;
    });
}

/**
 * Get schema templates with proper caching and error handling
 * @return array Schema templates
 */
function frl_get_schema_templates()
{
    return frl_cache_remember('versions', 'schema_templates', function () {
        $schema_templates = [];

        // Process each schema type
        foreach (FRL_SCHEMA_TYPES as $type) {
            $path = FRL_DIR_PATH . 'public/schema/' . $type . '.json';

            // Check if file exists
            if (!file_exists($path)) {
                continue;
            }

            // Read file contents
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            // Validate JSON syntax
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            $schema_templates[$type] = trim($content);
        }

        return $schema_templates;
    });
}
