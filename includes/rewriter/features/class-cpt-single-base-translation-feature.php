<?php

/**
 * CPT Translation Feature
 *
 * @package FRL
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



        // Load configuration early so it is available when rewrite rules are being generated.
        // CPTs are registered on 'init', but post_type_exists() will still be true for public CPTs at default priorities.
        add_action('init', [$this, 'load_configuration'], 20, 0);

        // Canonical redirect for CPT single URLs
        add_action('template_redirect', [$this, 'maybe_redirect_canonical'], 11, 0);

        // Ensure translated-base rules are prioritized over generic CPT rules from other plugins.
        // This filter runs only when rules are (re)built, not on every request.
        add_filter('rewrite_rules_array', [$this, 'prioritize_translated_cpt_rules'], 9999, 1);
    }

    public function get_name(): string
    {
        return "CPT Single Base Translation ({$this->cpt_slug})";
    }

    public function is_enabled(): bool
    {
        return !empty($this->mappings) && post_type_exists($this->cpt_slug);
    }

    public function load_configuration(): void
    {
        $this->mappings = Frl_Rewriter_Path_Utils::parse_lang_mapping_option("translate_cpt_slugs_{$this->cpt_slug}");
    }

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
            $rules["^{$base_esc}/(.+?)/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]";
            $rules["^{$base_esc}/(.+?)/feed/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&feed=feed";
            $rules["^{$base_esc}/(.+?)/embed/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&embed=true";
            $rules["^{$base_esc}/(.+?)/comment-page-([0-9]{1,})/?$"] = "index.php?post_type={$this->cpt_slug}&name=\$matches[1]&{$cpt_query_var}=\$matches[1]&cpage=\$matches[2]";
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

    public function applies_to_request(string $request_uri): bool
    {
        return !empty($this->resolve_request($request_uri));
    }

    public function resolve_request(string $request_uri): array
    {
        static $cache = [];
        if (isset($cache[$request_uri])) {
            return $cache[$request_uri];
        }

        if (!$this->is_enabled()) {
            return $cache[$request_uri] = [];
        }

        $uri = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
        $cpt_query_var = $this->cpt_slug;

        foreach ($this->mappings as $lang => $translated_base) {
            $lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
            $base_esc = Frl_Rewriter_Path_Utils::escape_for_regex($translated_base, '#');
            // Check for comment pagination first
            if (preg_match("#^{$lang_esc}/{$base_esc}/(.+?)/comment-page-([0-9]{1,})/?$#", $uri, $matches)) {
                return $cache[$request_uri] = [
                    'post_type' => $this->cpt_slug,
                    'name'      => $matches[1],
                    $cpt_query_var => $matches[1],
                    'cpage'     => (int)$matches[2],
                    'lang'      => $lang
                ];
            }
            if (preg_match("#^{$base_esc}/(.+?)/comment-page-([0-9]{1,})/?$#", $uri, $matches)) {
                return $cache[$request_uri] = [
                    'post_type' => $this->cpt_slug,
                    'name'      => $matches[1],
                    $cpt_query_var => $matches[1],
                    'cpage'     => (int)$matches[2],
                    'lang'      => $lang
                ];
            }
            // Check for main post slug (support hierarchical CPTs by using pagename if hierarchical)
            if (preg_match("#^{$lang_esc}/{$base_esc}/(.+?)/?$#", $uri, $matches)) {
                $slug = $matches[1];
                if (is_post_type_hierarchical($this->cpt_slug)) {
                    return $cache[$request_uri] = [
                        'post_type' => $this->cpt_slug,
                        'pagename'  => $slug,
                        'lang'      => $lang
                    ];
                }
                return $cache[$request_uri] = [
                    'post_type' => $this->cpt_slug,
                    'name'      => $slug,
                    $cpt_query_var => $slug,
                    'lang'      => $lang
                ];
            }
            if (preg_match("#^{$base_esc}/(.+?)/?$#", $uri, $matches)) {
                $slug = $matches[1];
                if (is_post_type_hierarchical($this->cpt_slug)) {
                    return $cache[$request_uri] = [
                        'post_type' => $this->cpt_slug,
                        'pagename'  => $slug,
                        'lang'      => $lang
                    ];
                }
                return $cache[$request_uri] = [
                    'post_type' => $this->cpt_slug,
                    'name'      => $slug,
                    $cpt_query_var => $slug,
                    'lang'      => $lang
                ];
            }
        }
        return $cache[$request_uri] = [];
    }

    /**
     * Canonicalize CPT single URLs to translated base.
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
     */
    public function get_cpt_slug(): string
    {
        return $this->cpt_slug;
    }

    /**
     * Get all configured translated bases for this CPT
     */
    public function get_translated_bases(): array
    {
        return array_values($this->mappings);
    }

    /**
     * Get exclusion patterns for this feature
     */
    public function get_exclusion_patterns(): array
    {
        $patterns = [preg_quote($this->cpt_slug)];
        $patterns = array_merge($patterns, Frl_Rewriter_Path_Utils::get_lang_base_patterns($this->mappings));
        return $patterns;
    }

    // --- URL Transformation Methods ---

    public function applies_to($object): bool
    {
        return isset($object->post_type) && $object->post_type === $this->cpt_slug;
    }

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
