<?php
/**
 * Default Schema Person Reference Data
 *
 * Pure data file — no function calls.
 * Maps schema @type properties to Person reference definitions.
 * Person objects are resolved at injection time, per-request.
 *
 * The '_ref' key is reserved — it designates the ACF field on the
 * current post that holds the reference CPT ID(s). All other keys
 * are Person schema properties resolved from each referenced post.
 *
 * Single convention: 'post_' prefix = WP-native functionality.
 *   'post_permalink'      → get_permalink($ref_id)
 *   'post_thumbnail'      → ImageObject { @type, url, height, width }
 *   'post_thumbnail_url'  → get_the_post_thumbnail_url($ref_id, 'full') (string)
 *   'post_{field}'        → $post->{field} (e.g. post_title, post_content)
 *   anything else         → get_field($value, $ref_id) (ACF)
 *
 * Format:
 *   'SchemaType' => [
 *       'schemaProperty' => [
 *           '_ref'     => 'acf_field_on_current_post',   // source of ref IDs on current post
 *           'name'     => 'post_title',                    // Person.name
 *           'jobTitle' => 'acf_field_on_ref_post',         // Person.jobTitle
 *           'url'      => 'post_permalink',                // Person.url
 *       ],
 *   ],
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'Article' => [
        'author' => [
            '_ref'     => 'post-settings_post-authors', // ACF field on current post → ref IDs
            'name'     => 'post_title',                 // Person.name from ref post title
            'url'      => 'post_permalink',             // Person.url from ref post permalink
            'image'    => 'post_thumbnail',             // Person.image → ImageObject
            'jobTitle' => 'team-settings_team-role',    // Person.jobTitle from ref post ACF
        ],
    ],
];
