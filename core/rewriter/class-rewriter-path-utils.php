<?php
declare(strict_types=1);
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal Path Utilities for Rewriter Transformers.
 *
 * Provides essential URL parsing and manipulation functions
 * using existing plugin helpers where possible.
 *
 * @package Fralenuvole
 * @since 3.0.0
 */
final class Frl_Rewriter_Path_Utils
{

    /**
     * Parse URL into components.
     *
     * @param string $url The URL to parse
     * @return array Array with 'home_url', 'lang_prefix', and 'segments' keys
     */
    public static function parse_url_segments(string $url): array
    {
        $parsed = wp_parse_url($url);
        $path = $parsed['path'] ?? '';

        // Extract language prefix if present.
        // array_values() re-indexes after array_filter so callers can safely use $segments[0].
        $lang_prefix = '';
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        $active_langs = self::get_active_languages_safe();
        if (
            !empty($segments)
            && in_array($segments[0], $active_langs, true)
        ) {
            $lang_prefix = array_shift($segments);
        }

        return [
            'home_url' => home_url(),
            'lang_prefix' => $lang_prefix,
            'segments' => $segments
        ];
    }

    /**
     * Rebuild URL from components.
     *
     * @param string $home_url The home URL
     * @param string $lang_prefix The language prefix
     * @param array $segments The URL segments
     * @return string Rebuilt URL
     */
    public static function rebuild_url(string $home_url, string $lang_prefix, array $segments): string
    {
        $path_parts = [];

        if (!empty($lang_prefix)) {
            $path_parts[] = $lang_prefix;
        }

        $path_parts = array_merge($path_parts, $segments);
        $path = implode('/', $path_parts);

        // Add leading/trailing slashes only if the path is not empty
        if (!empty($path)) {
            $path = '/' . $path . '/';
        }

        return rtrim($home_url, '/') . $path;
    }

    /**
     * Safe regex replacement with error handling.
     *
     * @param string $pattern The regex pattern
     * @param string $replacement The replacement string
     * @param string $subject The subject string
     * @return string Result of replacement or original subject on error
     */
    public static function safe_preg_replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);
        if ($result === null) {
            // Avoid silent failures – log the regex error once per request.
            static $logged = false;
            if (!$logged) {
                $errorCode = preg_last_error();
                $errorMsg  = function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'unknown';
                frl_log('Rewriter: Regex error during safe_preg_replace. Pattern: {pattern} Subject: {subject} Error: {error} ({code})', [
                    'pattern' => $pattern,
                    'subject' => mb_substr($subject, 0, 150),
                    'error'   => $errorMsg,
                    'code'    => $errorCode,
                ]);
                $logged = true;
            }
            return self::collapse_slashes($subject);
        }
        return self::collapse_slashes($result);
    }

    /**
     * Collapse multiple slashes into single slashes.
     *
     * @param string $url The URL to collapse
     * @return string URL with collapsed slashes
     */
    public static function collapse_slashes(string $url): string
    {
        // Protect the scheme's :// from collapse by splitting on it first.
        // This handles malformed URLs like https:///path without corrupting them.
        static $logged = false;
        $parts = explode('://', $url, 2);
        if (count($parts) === 2) {
            $collapsed = preg_replace('#/{2,}#', '/', $parts[1]);
            if ($collapsed === null) {
                if (!$logged) {
                    $errorCode = preg_last_error();
                    $errorMsg  = function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'unknown';
                    frl_log('Rewriter: Regex error in collapse_slashes. URL: {url} Error: {error} ({code})', [
                        'url'   => $url,
                        'error' => $errorMsg,
                        'code'  => $errorCode,
                    ]);
                    $logged = true;
                }
                return $url;
            }
            return implode('://', [$parts[0], $collapsed]);
        }
        // No scheme: collapse all redundant slashes.
        $result = preg_replace('#/{2,}#', '/', $url);
        if ($result === null) {
            if (!$logged) {
                $errorCode = preg_last_error();
                $errorMsg  = function_exists('preg_last_error_msg') ? preg_last_error_msg() : 'unknown';
                frl_log('Rewriter: Regex error in collapse_slashes. URL: {url} Error: {error} ({code})', [
                    'url'   => $url,
                    'error' => $errorMsg,
                    'code'  => $errorCode,
                ]);
                $logged = true;
            }
            return $url;
        }
        return $result;
    }

    /**
     * Detect post language using existing helper.
     *
     * @param int $post_id The post ID
     * @return string Language code
     */
    public static function detect_post_language(int $post_id): string
    {
        return frl_get_language($post_id);
    }

    /**
     * Detect term language using existing helper.
     *
     * @param int $term_id The term ID
     * @param string $taxonomy The taxonomy name
     * @return string Language code
     */
    public static function detect_term_language(int $term_id, string $taxonomy): string
    {
        return frl_get_language($term_id, 'term');
    }

    /**
     * Extract clean path from request URI (common pattern across all features)
     *
     * @param string $request_uri The request URI
     * @return string Clean path without leading/trailing slashes
     */
    public static function extract_request_path(string $request_uri): string
    {
        return ltrim((string)(parse_url($request_uri, PHP_URL_PATH) ?? ''), '/');
    }

    /**
     * Escape value for regex pattern (common pattern across features)
     *
     * @param string $value Value to escape
     * @param string $delimiter Regex delimiter (default: '/')
     * @return string Escaped value
     */
    public static function escape_for_regex(string $value, string $delimiter = '/'): string
    {
        return preg_quote($value, $delimiter);
    }

    /**
     * Generate exclusion patterns from language/base mappings.
     * Accepts either associative (lang=>base) or numeric arrays [lang, base].
     *
     * @param array $mappings Array of [lang, base] pairs
     * @return array Array of escaped patterns
     */
    public static function get_lang_base_patterns(array $mappings): array
    {
        $result = [];
        foreach ($mappings as $lang => $base) {
            // Handle numeric arrays: [ ['en', 'news'], ['it', 'notizie'] ]
            if (is_array($base) && count($base) >= 2) {
                $lang = $base[0];
                $base = $base[1];
            }

            // Ensure base is a string and not empty before proceeding
            $base = trim((string)$base);
            if ($base === '') {
                continue;
            }

            $result[] = self::escape_for_regex($base);
            $result[] = self::escape_for_regex("{$lang}/{$base}");
        }
        return array_unique($result);
    }

    /**
     * Transient key used by generate_standard_exclusion_patterns() for persistent storage
     * on sites without an external object cache. Exposed so clear_rewriter_caches() can
     * delete it when permalink options change.
     */
    const EXCLUSION_PATTERNS_TRANSIENT = 'rewriter_excl_patterns';

    /**
     * Generate configuration-based exclusion patterns (shared by catch-all features).
     *
     * Results are cached in two layers:
     *  - When a persistent object cache is active: keyed by posts:last_changed so slugs
     *    added or removed automatically invalidate the cache (no DB transient needed).
     *  - Without persistent object cache: stored in a DB transient (TTL = 1 hour).
     *    The transient is deleted by Frl_Rewriter::clear_rewriter_caches() whenever
     *    permalink structure or relevant options change, so stale data is bounded.
     *
     * @return array Array of escaped regex patterns
     */
    public static function generate_standard_exclusion_patterns(): array
    {
        if (wp_using_ext_object_cache()) {
            // Fine-grained invalidation: re-key on posts last_changed counter.
            $postsLastChanged = wp_cache_get('last_changed', 'posts');
            if (!$postsLastChanged) {
                $postsLastChanged = microtime();
                wp_cache_set('last_changed', $postsLastChanged, 'posts');
            }
            $cacheKey = "exclusion_patterns_{$postsLastChanged}";
            return frl_cache_remember('rewriter', $cacheKey, [self::class, 'compute_exclusion_patterns']);
        }

        // No persistent object cache: avoid the expensive get_pages() on every request
        // by storing results in a DB transient. TTL is 1 hour; explicit deletion is wired
        // to clear_rewriter_caches() which fires on permalink/option changes.
        $cached = frl_get_transient(self::EXCLUSION_PATTERNS_TRANSIENT);
        if ($cached !== false) {
            return $cached;
        }

        $patterns = self::compute_exclusion_patterns();
        frl_set_transient(self::EXCLUSION_PATTERNS_TRANSIENT, $patterns, HOUR_IN_SECONDS);
        return $patterns;
    }

    /**
     * Compute exclusion patterns without any caching layer.
     * Called by generate_standard_exclusion_patterns() and tests.
     *
     * @return array Array of unique escaped regex patterns
     */
    public static function compute_exclusion_patterns(): array
    {
        $patterns = [];

        // Post base translation bases
        $post_mappings = self::get_post_base_mappings();
        $patterns = array_merge($patterns, self::get_lang_base_patterns($post_mappings));

        // CPT translation bases contributed via filter (avoids coupling to FRL_REWRITER_MULTILINGUAL_CPT).
        // Frl_CPT_Archive_Base_Translation_Feature::contribute_url_prefixes() populates this.
        $contributed = (array) apply_filters('frl_rewriter_url_prefixes', []);
        foreach ($contributed as $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix !== '') {
                $patterns[] = self::escape_for_regex($prefix);
            }
        }

        // All registered public CPT base slugs (prevents catch-all from hijacking CPT archives).
        $cpts = get_post_types(['public' => true, '_builtin' => false], 'objects');
        if (is_array($cpts)) {
            foreach ($cpts as $cpt_obj) {
                if (is_object($cpt_obj) && isset($cpt_obj->rewrite['slug']) && $cpt_obj->rewrite['slug'] !== '') {
                    $patterns[] = self::escape_for_regex($cpt_obj->rewrite['slug']);
                }
            }
        }

        // Public taxonomy rewrite bases (prevents catch-all from capturing taxonomy archives).
        $taxes = get_taxonomies(['public' => true], 'objects');
        if (is_array($taxes)) {
            foreach ($taxes as $tax) {
                if (is_object($tax) && isset($tax->rewrite['slug']) && $tax->rewrite['slug'] !== '') {
                    $patterns[] = self::escape_for_regex($tax->rewrite['slug']);
                }
            }
        }

        // Top-level published page slugs (avoids hijacking standard pages).
        $limit = defined('FRL_REWRITER_PAGE_TOPLEVEL_CAP') ? (int) FRL_REWRITER_PAGE_TOPLEVEL_CAP : 500;
        $pages = get_pages(['post_status' => 'publish', 'number' => $limit, 'parent' => 0]);
        if ($pages) {
            $langs = self::get_active_languages_safe();
            foreach ($pages as $page) {
                if (is_object($page) && !empty($page->post_name)) {
                    $slug = $page->post_name;
                    $patterns[] = self::escape_for_regex($slug);
                    foreach ($langs as $lang) {
                        $patterns[] = self::escape_for_regex("{$lang}/{$slug}");
                    }
                }
            }
        }

        return array_unique($patterns);
    }

    /**
     * Get post base mappings with caching - consolidated configuration parsing
     *
     * @return array Array of [lang, base] pairs
     */
    public static function get_post_base_mappings(): array
    {
        return frl_cache_remember('rewriter', 'translate_post_base', function () {
            $post_base_config = frl_get_option('translate_post_base');
            if (empty($post_base_config)) {
                return [];
            }
            return frl_textlist_to_array($post_base_config);
        });
    }

    /**
     * Get CPT mappings with caching - consolidated configuration parsing
     *
     * @param string $cpt_slug CPT slug to get mappings for
     * @return array Array of [lang, base] pairs
     */
    public static function get_cpt_mappings(string $cpt_slug): array
    {
        return frl_cache_remember('rewriter', "translate_cpt_slug_{$cpt_slug}", function () use ($cpt_slug) {
            $cpt_config = frl_get_option("translate_cpt_slugs_{$cpt_slug}");
            if (empty($cpt_config)) {
                return [];
            }
            return frl_textlist_to_array($cpt_config);
        });
    }

    /**
     * Get active languages with caching and validation
     *
     * @return array Array of active language codes
     */
    public static function get_active_languages_safe(): array
    {
        return frl_cache_remember('rewriter', 'active_languages', function () {
            $languages = frl_get_active_languages();
            // Validate that we have a non-empty array, fallback to DB query
            return !empty($languages) ? $languages : frl_get_active_languages_fallback();
        });
    }

    /**
     * Get post slug from post object
     *
     * @param object $object Post object
     * @return string Post slug or empty string
     */
    public static function get_post_slug($object): string
    {
        return isset($object->post_name) ? (string) $object->post_name : '';
    }

    /**
     * Get WordPress permalink structure with caching
     *
     * @return string Permalink structure
     */
    public static function get_permalink_structure(): string
    {
        return frl_cache_remember('rewriter', 'permalink_structure', function () {
            return (string) get_option('permalink_structure');
        });
    }

    /**
     * Extract static first segment from permalink structure (if not a placeholder)
     *
     * @return string Static base or empty string
     */
    public static function get_static_permalink_base(): string
    {
        $structure = ltrim(self::get_permalink_structure(), '/');
        if ($structure !== '') {
            $first = strtok($structure, '/');
            if ($first && !str_starts_with($first, '%')) {
                return $first;
            }
        }
        return '';
    }

    /**
     * Check if permalink structure contains category with caching
     *
     * @return bool True if contains %category%
     */
    public static function has_category_structure(): bool
    {
        // Cast to bool to guard against Memcached returning string/int representations
        return (bool) frl_cache_remember('rewriter', 'has_category_structure', function () {
            return str_contains(self::get_permalink_structure(), '%category%');
        });
    }

    /**
     * Clear static caches for memory management
     * Call this during plugin deactivation or cache clearing
     *
     * @return void
     */
    public static function clear_static_caches(): void
    {
        // Clear any static caches used by the rewriter system
        // This helps prevent memory leaks in long-running processes
        frl_cache_clear('rewriter');
    }

    /**
     * Parse a lang=>base option into associative array, cached.
     *
     * @param string $option_name The option name to parse
     * @return array Associative array of lang=>base pairs
     */
    public static function parse_lang_mapping_option(string $option_name): array
    {
        return frl_cache_remember('rewriter', 'langmap_' . $option_name, function () use ($option_name) {
            $value = frl_get_option($option_name);
            if (empty(trim((string) $value))) {
                return [];
            }
            $parsed   = frl_textlist_to_array($value);
            $mappings = [];
            foreach ($parsed as $line) {
                if (count($line) >= 2 && trim($line[1]) !== '') {
                    $mappings[trim($line[0])] = trim($line[1]);
                }
            }
            return $mappings;
        });
    }

    /**
     * Get current request URL (path portion, no query) for comparison.
     *
     * @return string Current request URL
     */
    public static function get_current_request_url(): string
    {
        global $wp;
        $path = $wp && isset($wp->request) ? '/' . ltrim($wp->request, '/') : (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
        return home_url($path);
    }

    /**
     * Helper to issue 301 redirect to canonical if current URL differs.
     *
     * @param string $canonical The canonical URL to redirect to
     * @return void
     */
    public static function maybe_redirect_if_needed(string $canonical): void
    {
        // Skip redirects in non-standard contexts
        if (!function_exists('frl_is_valid_page_request') || !frl_is_valid_page_request() || is_preview()) {
            return;
        }

        /**
         * Filters whether to skip the rewriter's canonical redirect.
         *
         * Allows other modules (e.g., Subdomain Adapter) to cancel the redirect
         * when they handle URL structure independently. Without this, the rewriter
         * in directory mode (force_lang=1) would add /{lang}/ prefixes that conflict
         * with subdomain clean URLs, creating redirect loops.
         *
         * @since 5.4.0
         *
         * @param bool   $skip      Whether to skip the redirect. Default false.
         * @param string $canonical The canonical URL that would be redirected to.
         */
        if (apply_filters('frl_rewriter_skip_canonical_redirect', false, $canonical)) {
            return;
        }

        $current = self::get_current_request_url();

        if (rtrim($current, '/') !== rtrim($canonical, '/')) {
            // Preserve query string during redirect
            if (!empty($_GET)) {
                $canonical = add_query_arg($_GET, $canonical);
            }
            add_filter('x_redirect_by', function () {
                return 'Frl_Rewriter_Path_Utils';
            }, 999);
            wp_safe_redirect($canonical, 301);
            exit;
        }
    }

    /**
     * Extract pagination from a path using a provided pattern.
     *
     * @param string $path The path to parse
     * @param string $pattern Regex pattern to match pagination
     * @param int|null $prefix_idx Index of the capture group containing the cleaned path, or null to use preg_replace
     * @param int $paged_idx Index of the capture group containing the page number
     * @return array Array with 'path' (cleaned) and 'paged' (int)
     */
    public static function parse_pagination(string $path, string $pattern = '#/page/?([0-9]+)/?$#', ?int $prefix_idx = null, int $paged_idx = 1): array
    {
        if (preg_match($pattern, $path, $matches)) {
            $paged = (int) $matches[$paged_idx];
            $cleaned_path = ($prefix_idx !== null) ? $matches[$prefix_idx] : preg_replace($pattern, '', $path);
            return ['path' => $cleaned_path, 'paged' => $paged];
        }
        return ['path' => $path, 'paged' => 1];
    }

    /**
     * Parse a CPT single post request for special types (feed, embed, comment-page).
     *
     * @param string $uri The request URI
     * @param string $lang_esc Escaped language prefix
     * @param string $base_esc Escaped CPT base
     * @return array|null Result array with 'name', 'type', 'paged' (optional), and 'lang' (bool), or null if no match
     */
    public static function parse_cpt_single_request(string $uri, string $lang_esc, string $base_esc): ?array
    {
        // Comment pagination
        if (preg_match("#^{$lang_esc}/{$base_esc}/(.+?)/comment-page-([0-9]{1,})/?$#", $uri, $matches)) {
            return ['name' => $matches[1], 'type' => 'comment-page', 'paged' => (int) $matches[2], 'lang' => true];
        }
        if (preg_match("#^{$base_esc}/(.+?)/comment-page-([0-9]{1,})/?$#", $uri, $matches)) {
            return ['name' => $matches[1], 'type' => 'comment-page', 'paged' => (int) $matches[2], 'lang' => false];
        }
        // Feed
        if (preg_match("#^{$lang_esc}/{$base_esc}/(.+?)/feed/?$#", $uri, $matches)) {
            return ['name' => $matches[1], 'type' => 'feed', 'lang' => true];
        }
        if (preg_match("#^{$base_esc}/(.+?)/feed/?$#", $uri, $matches)) {
            return ['name' => $matches[1], 'type' => 'feed', 'lang' => false];
        }
        // Embed
        if (preg_match("#^{$lang_esc}/{$base_esc}/(.+?)/embed/?$#", $uri, $matches)) {
            return ['name' => $matches[1], 'type' => 'embed', 'lang' => true];
        }
        if (preg_match("#^{$base_esc}/(.+?)/embed/?$#", $uri, $matches)) {
            return ['name' => $matches[1], 'type' => 'embed', 'lang' => false];
        }

        return null;
    }
}
