<?php
/**
 * Default Schema Person Reference Data
 *
 * Pure data file — no function calls.
 * Maps schema @type properties to Person reference definitions.
 * Person objects are resolved at injection time, per-request.
 *
 * The 'name' key designates the ACF field on the current post that
 * holds the reference CPT ID(s). All other keys map to Person sub-fields
 * resolved from each referenced post.
 *
 * Format:
 *   'SchemaType' => [
 *       'schemaProperty' => [
 *           'name'     => 'acf_field_on_current_post',  // source of ref IDs
 *           'jobTitle' => 'acf_field_on_ref_post',       // Person.jobTitle
 *           'url'      => 'permalink',                   // special: ref post permalink
 *       ],
 *   ],
 *
 * Supported source values:
 *   'permalink'      → get_permalink($ref_id)
 *   '_thumbnail_id'  → get_the_post_thumbnail_url($ref_id, 'full')
 *   any other string → get_field($value, $ref_id)
 *
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

return [];
