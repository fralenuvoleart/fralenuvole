<?php
declare(strict_types=1);
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trait providing generate_cache_key() utility used by classes that transform URLs.
 * No dependencies on the consuming class except method invocation context.
 *
 * @package Fralenuvole
 * @since 3.0.0
 */
trait Frl_Rewriter_Cache_Key_Trait
{
    /**
     * Generate stable cache key for transformed URLs.
     * Extracted from Frl_Rewriter::generate_cache_key() unchanged.
     */
    private function generate_cache_key(string $url, $object): string
    {
        // Guard against non-object inputs (e.g., archive slugs).
        if (!is_object($object)) {
            $object_type    = is_scalar($object) ? 'scalar_' . (string) $object : gettype($object);
            $unique_id      = 'non_object';
            $cache_modifier = '';
        } else {
            $object_type = get_class($object);

            // Generate unique identifier to prevent post/term ID collisions
            if (isset($object->ID)) {
                $unique_id      = "post_{$object->ID}";
                // Include post_modified for cache invalidation when content changes
                // Optimization: Use crc32 for much faster hashing of the date string.
                $cache_modifier = isset($object->post_modified) ? dechex(crc32((string)$object->post_modified)) : '';
            } elseif (isset($object->term_id)) {
                $unique_id      = "term_{$object->term_id}";
                // Use term count as cache modifier for terms
                $cache_modifier = isset($object->count) ? $object->count : '0';
            } else {
                $unique_id      = 'unknown_0';
                $cache_modifier = '';
            }
        }

        // Optimize URL hash - only hash the path portion (domain-agnostic)
        $relative   = wp_make_link_relative($url);
        $path_only  = wp_parse_url($relative, PHP_URL_PATH) ?: $relative;
        // Optimization: Use crc32 for much faster hashing of the URL path.
        $url_hash   = dechex(crc32((string)$path_only));

        // Cache keys for group permalink are already language-aware
        return "rewriter_key_{$object_type}_{$unique_id}_{$cache_modifier}_{$url_hash}";
    }
}
