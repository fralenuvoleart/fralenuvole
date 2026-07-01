<?php

/**
 * CPT Translation Feature
 *
 * @package Fralenuvole
 * @since 3.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/abstract-base-feature.php';

/**
 * Handles translation of CPT base slugs for individual CPT post URLs (e.g., en/services/post-name)
 *
 * This feature operates completely independently and handles:
 * - Single CPT post URLs with translated bases
 * - Language prefix support
 */
class Frl_CPT_Single_Base_Translation_Feature extends Frl_Rewriter_Feature_Base
{

    private string $cpt_slug;
    private array $mappings = [];

    public function __construct(string $cpt_slug)
    {
        $this->cpt_slug = $cpt_slug;
        // Property initialisation only. All hook registration happens in register_additional_hooks(),
        // which is called by the coordinator via register() at init priority 15.
    }

    protected function register_additional_hooks(): void
    {
        // Load configuration after CPTs are registered on 'init'.
        add_action('init', [$this, 'load_configuration'], 20, 0);

        // Canonical redirect for CPT single URLs to the translated base.
        add_action('template_redirect', [$this, 'maybe_redirect_canonical'], 11, 0);

        // Ensure translated-base rules are prioritised over generic CPT rules from other plugins.
        // This filter runs only when rules are (re)built, not on every request.
        add_filter('rewrite_rules_array', [$this, 'prioritize_translated_cpt_rules'], 9999, 1);
    }

    /**
     * Get a human-readable name for this feature (for logging/debugging)
     *
     * @return string The feature name
     */
    public function get_name(): string
    {
        return "CPT Single Base Translation ({$this->cpt_slug})";
    }

    /**
     * Check if this feature is enabled via configuration
     *
     * @return bool True if the feature is enabled
     */
    public function is_enabled(): bool
    {
        return !empty($this->mappings) && post_type_exists($this->cpt_slug);
    }

    /**
     * Load configuration from options
     *
     * @return void
     */
    public function load_configuration(): void
    {
        $this->mappings = Frl_Rewriter_Path_Utils::parse_lang_mapping_option("translate_cpt_slugs_{$this->cpt_slug}");
    }

    /**
     * Generate rewrite rules for this feature only
     *
     * @return array Associative array of pattern => rewrite pairs
     */
    public function generate_rules(): array
    {
        if (!$this->is_enabled()) {
            return [];
        }



        $rules = [];
        $cpt_query_var = $this->cpt_slug; // Use the CPT slug as the query var

        foreach ($this->mappings as $lang => $translated_base) {
            $lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
            $base_esc = Frl_Rewriter_Path_Utils::escape_for_regex($translated_base, '#');

            // Post single-item rules with language prefix
            $rules["^{$lang_esc}/{$base_esc}/(.+?)/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&lang={$lang}";
            $rules["^{$lang_esc}/{$base_esc}/(.+?)/feed/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&feed=feed&lang={$lang}";
            $rules["^{$lang_esc}/{$base_esc}/(.+?)/embed/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&embed=true&lang={$lang}";
            $rules["^{$lang_esc}/{$base_esc}/(.+?)/comment-page-([0-9]{1,})/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&cpage=\$matches[2]&lang={$lang}";

            // Post single-item rules without language prefix (only add if no multilingual plugin manages lang roots)
            $rules["^{$base_esc}/(.+?)/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&lang={$lang}";
            $rules["^{$base_esc}/(.+?)/feed/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&feed=feed&lang={$lang}";
            $rules["^{$base_esc}/(.+?)/embed/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&embed=true&lang={$lang}";
            $rules["^{$base_esc}/(.+?)/comment-page-([0-9]{1,})/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&cpage=\$matches[2]&lang={$lang}";
        }



        return $rules;
    }

    /**
     * Move this feature's translated single-item rules to the top of the ruleset.
     * Guarantees precedence over generic (lang)/{$cpt}/... rules added by other plugins.
     *
     * @param array $rules
     * @return array
     */
    public function prioritize_translated_cpt_rules(array $rules): array
    {
        if (!$this->is_enabled()) {
            return $rules;
        }

        $my_rules = $this->generate_rules();
        if (empty($my_rules)) {
            return $rules;
        }

        // Prepend our rules, preserving their query strings, and keep remaining rules after
        // Remove any duplicates from the tail to avoid redundant evaluation
        $tail = array_diff_key($rules, $my_rules);
        return $my_rules + $tail;
    }

    /**
     * Check if this feature should handle the given request URI
     *
     * @param string $request_uri The raw request URI
     * @return bool True if this feature should handle the request
     */
    public function applies_to_request(string $request_uri): bool
    {
        return !empty($this->resolve_request($request_uri));
    }

    /**
     * Resolve the request URI to WordPress query variables
     *
     * @param string $request_uri The request URI to resolve
     * @return array WordPress query variables or empty array if not handled
     */
    public function resolve_request(string $request_uri): array
    {
        // Keyed by cpt_slug to prevent cross-instance cache pollution when multiple CPTs are multilingual.
        static $cache = [];
        if (isset($cache[$this->cpt_slug][$request_uri])) {
            return $cache[$this->cpt_slug][$request_uri];
        }
        // Memory guard: bound per-CPT-slug entries to avoid unbounded growth in long-running
        // CLI/cron contexts. Uses count() which is O(1) in PHP (array size is tracked internally).
        if (isset($cache[$this->cpt_slug]) && count($cache[$this->cpt_slug]) > 4096) {
            $cache[$this->cpt_slug] = [];
        }

        if (!$this->is_enabled()) {
            return $cache[$this->cpt_slug][$request_uri] = [];
        }

        $uri = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
        $cpt_query_var = $this->cpt_slug;

        foreach ($this->mappings as $lang => $translated_base) {
            $lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
            $base_esc = Frl_Rewriter_Path_Utils::escape_for_regex($translated_base, '#');

            $single_req = Frl_Rewriter_Path_Utils::parse_cpt_single_request($uri, $lang_esc, $base_esc);
            if ($single_req) {
                $res = [
                    'post_type'    => $this->cpt_slug,
                    'name'         => $single_req['name'],
                    $cpt_query_var => $single_req['name'],
                ];

                if ($single_req['type'] === 'comment-page') {
                    $res['cpage'] = $single_req['paged'];
                } elseif ($single_req['type'] === 'feed') {
                    $res['feed'] = 'feed';
                } elseif ($single_req['type'] === 'embed') {
                    $res['embed'] = 'true';
                }

                if ($single_req['lang']) {
                    $res['lang'] = $lang;
                }

                return $cache[$this->cpt_slug][$request_uri] = $res;
            }

            // Main post slug (support hierarchical CPTs by using pagename)
            if (preg_match("#^{$lang_esc}/{$base_esc}/(.+?)/?$#", $uri, $matches)) {
                $slug = $matches[1];
                if (is_post_type_hierarchical($this->cpt_slug)) {
                    return $cache[$this->cpt_slug][$request_uri] = [
                        'post_type' => $this->cpt_slug,
                        'pagename'  => $slug,
                        'lang'      => $lang,
                    ];
                }
                return $cache[$this->cpt_slug][$request_uri] = [
                    'post_type'    => $this->cpt_slug,
                    'name'         => $slug,
                    $cpt_query_var => $slug,
                    'lang'         => $lang,
                ];
            }
            if (preg_match("#^{$base_esc}/(.+?)/?$#", $uri, $matches)) {
                $slug = $matches[1];
                if (is_post_type_hierarchical($this->cpt_slug)) {
                    return $cache[$this->cpt_slug][$request_uri] = [
                        'post_type' => $this->cpt_slug,
                        'pagename'  => $slug,
                        'lang'      => $lang,
                    ];
                }
                return $cache[$this->cpt_slug][$request_uri] = [
                    'post_type'    => $this->cpt_slug,
                    'name'         => $slug,
                    $cpt_query_var => $slug,
                    'lang'         => $lang,
                ];
            }
        }
        return $cache[$this->cpt_slug][$request_uri] = [];
    }

    /**
     * Canonicalize CPT single URLs to translated base.
     *
     * @return void
     */
    public function maybe_redirect_canonical(): void
    {
        if (!$this->is_enabled()) {
            return;
        }

        if (!is_singular($this->cpt_slug)) {
            return;
        }

        $post = get_queried_object();
        if (!$post || !isset($post->ID)) {
            return;
        }

        $canonical = get_permalink($post);
        if (empty($canonical) || is_wp_error($canonical)) {
            return;
        }

        Frl_Rewriter_Path_Utils::maybe_redirect_if_needed($canonical);
    }

    /**
     * Get the CPT slug this feature handles
     *
     * @return string The CPT slug
     */
    public function get_cpt_slug(): string
    {
        return $this->cpt_slug;
    }

    /**
     * Get all configured translated bases for this CPT
     *
     * @return array Array of translated base slugs
     */
    public function get_translated_bases(): array
    {
        return array_values($this->mappings);
    }

    /**
     * Get exclusion patterns for this feature
     *
     * @return array Array of regex patterns to exclude
     */
    public function get_exclusion_patterns(): array
    {
        $patterns = [preg_quote($this->cpt_slug)];
        $patterns = array_merge($patterns, Frl_Rewriter_Path_Utils::get_lang_base_patterns($this->mappings));
        return $patterns;
    }

    // --- URL Transformation Methods ---

    /**
     * Check if this transformer applies to the given object.
     * (Optional: override in features that transform outgoing URLs)
     *
     * @param mixed $object The object to check
     * @return bool True if this transformer should process the object
     */
    public function applies_to($object): bool
    {
        return isset($object->post_type) && $object->post_type === $this->cpt_slug;
    }

    /**
     * Transform a URL for the given object.
     * (Optional: override in features that transform outgoing URLs)
     *
     * @param string $url The URL to transform
     * @param mixed $object The object (post, term) the URL belongs to
     * @return string The transformed URL
     */
    public function transform(string $url, $object): string
    {
        if (!$this->is_enabled() || !$this->applies_to($object)) {
            return $url;
        }

        if (empty($this->mappings)) {
            return $url;
        }

        if (!is_object($object) || !isset($object->ID)) {
            return $url;
        }

        $lang = frl_get_language($object->ID);

        // A mapping must exist for the specific language of the post.
        // If not, no transformation should occur.
        if (!isset($this->mappings[$lang])) {
            return $url;
        }

        $translated_slug = $this->mappings[$lang] ?? $this->cpt_slug;

        // Parse and rebuild using path utils for robustness
        $parsed = Frl_Rewriter_Path_Utils::parse_url_segments($url);
        $segments = $parsed['segments'];
        if (empty($segments)) {
            return $url;
        }

        // Determine the current CPT base segment present in URLs (rewrite slug or fallback to CPT slug)
        $cpt_obj = get_post_type_object($this->cpt_slug);
        $current_base = ($cpt_obj && isset($cpt_obj->rewrite['slug']) && $cpt_obj->rewrite['slug'] !== '')
            ? trim((string)$cpt_obj->rewrite['slug'], '/')
            : $this->cpt_slug;

        // Replace first occurrence of the CPT base segment only
        $index = array_search($current_base, $segments, true);
        if ($index === false) {
            return $url;
        }
        $segments[$index] = $translated_slug;

        return Frl_Rewriter_Path_Utils::rebuild_url(
            $parsed['home_url'],
            $parsed['lang_prefix'],
            $segments
        );
    }
}
