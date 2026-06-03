<?php
/**
 * Default Schema Person Reference Data
 *
 * Pure data file. Maps schema @type to Person field definitions.
 * Person objects are resolved from CPT posts referenced by ACF fields.
 *
 * Field definition keys:
 *   '_ref'     — ACF field name on the current post that holds CPT ref ID(s)
 *   '_default' — Fallback when _ref is empty: int = CPT post ID (resolved), array = static Person
 *   '_remove'  — true = omit the property entirely when no refs found
 *
 * Source resolution (for name, url, image, etc.):
 *   'post_permalink'     → get_permalink($ref_id)
 *   'post_thumbnail'     → ImageObject from featured image
 *   'post_{field}'       → $post->{field} (e.g. post_title)
 *   anything else        → get_post_meta($ref_id, $value, true)
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
            '_default' => FRL_DEFAULT_AUTHOR_CPT_ID, // fallback CPT post ID when ACF is empty
            'name'     => 'post_title', // Person.name from ref post title
            'url'      => 'post_permalink', // Person.url from ref post permalink
            'image'    => 'post_thumbnail', // Person.image ImageObject
            'jobTitle' => 'team-settings_team-role', // Person.jobTitle from ref post ACF        
            'sameAs' => [
                'team-settings_team-linkedin',
                'team-settings_team-facebook',
                'team-settings_team-website',
                'team-settings_team-whatsapp',
            ],
        ],
        'editor' => [
            '_ref'     => 'post-settings_post-authors', // ACF field on current post → ref IDs
            '_default' => FRL_DEFAULT_EDITOR_CPT_ID, // fallback CPT post ID when ACF is empty
            'name'     => 'post_title', // Person.name from ref post title
            'url'      => 'post_permalink', // Person.url from ref post permalink
            'image'    => 'post_thumbnail', // Person.image ImageObject
            'jobTitle' => 'team-settings_team-role', // Person.jobTitle from ref post ACF         
            'sameAs' => [
                'team-settings_team-linkedin',
                'team-settings_team-facebook',
                'team-settings_team-website',
                'team-settings_team-whatsapp',
            ],
        ],
    ],
];
