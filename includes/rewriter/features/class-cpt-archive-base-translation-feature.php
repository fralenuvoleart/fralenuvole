<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/abstract-base-feature.php';

/**
 * CPT Archive Translation Feature
 *
 * Handles only archive URLs for a CPT under translated slug mappings.
 */
class Frl_CPT_Archive_Base_Translation_Feature extends Frl_Rewriter_Feature_Base
{
    private string $cpt_slug;
    private array $mappings = [];

    public function __construct(string $cpt_slug)
    {
        $this->cpt_slug = $cpt_slug;
        // Load configuration at standard timing
        add_action('init', [$this, 'load_configuration'], 20, 0);

        // Canonical redirect for CPT archive URLs
        add_action('template_redirect', [$this, 'maybe_redirect_canonical'], 11, 0);
    }

    public function get_name(): string
    {
        return "CPT Archive Base Translation ({$this->cpt_slug})";
    }

    public function is_enabled(): bool {
        return !empty($this->mappings) && post_type_exists($this->cpt_slug) && get_post_type_object($this->cpt_slug)->has_archive;
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
        foreach ($this->mappings as $lang => $translated) {
            $lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
            $base_esc = Frl_Rewriter_Path_Utils::escape_for_regex($translated, '#');

            // Archive with optional language prefix and pagination
            $rules["^{$lang_esc}/{$base_esc}/?$"] = "index.php?post_type={$this->cpt_slug}&lang={$lang}";
            $rules["^{$lang_esc}/{$base_esc}/page/?([0-9]{1,})/?$"] = "index.php?post_type={$this->cpt_slug}&paged=\$matches[1]&lang={$lang}";

            // Archive without language prefix
            $rules["^{$base_esc}/?$"] = "index.php?post_type={$this->cpt_slug}&lang={$lang}";
            $rules["^{$base_esc}/page/?([0-9]{1,})/?$"] = "index.php?post_type={$this->cpt_slug}&paged=\$matches[1]&lang={$lang}";
        }

        return $rules;
    }

    public function applies_to_request(string $request_uri): bool
    {
        // Resolve the request and cache the result. If resolution is successful,
        // this method returns true without re-running the logic.
        return !empty($this->resolve_request($request_uri));
    }

    public function resolve_request(string $request_uri): array
    {
        // In-request static cache to ensure resolution logic runs only once.
        static $cache = [];
        if (isset($cache[$request_uri])) {
            return $cache[$request_uri];
        }

        if (!$this->is_enabled()) {
            return $cache[$request_uri] = [];
        }

        $path = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
        foreach ($this->mappings as $lang => $translated) {
            $lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
            $base_esc = Frl_Rewriter_Path_Utils::escape_for_regex($translated, '#');

            if (preg_match("#^(?:{$lang_esc}/)?{$base_esc}/page/?([0-9]+)/?$#", $path, $m)) {
                return $cache[$request_uri] = ['post_type' => $this->cpt_slug, 'paged' => (int)$m[1], 'lang' => $lang];
            }
            if (preg_match("#^(?:{$lang_esc}/)?{$base_esc}/?$#", $path)) {
                return $cache[$request_uri] = ['post_type' => $this->cpt_slug, 'lang' => $lang];
            }
        }
        return $cache[$request_uri] = [];
    }

    public function get_exclusion_patterns(): array
    {
        $patterns = [preg_quote($this->cpt_slug)];
        $patterns = array_merge($patterns, Frl_Rewriter_Path_Utils::get_lang_base_patterns($this->mappings));
        return $patterns;
    }

    /**
     * Canonicalize CPT archives to translated base.
     */
    public function maybe_redirect_canonical(): void
    {
        if (!$this->is_enabled() || !post_type_exists($this->cpt_slug)) {
            return;
        }

        if (!is_post_type_archive($this->cpt_slug)) {
            return;
        }

        $canonical = get_post_type_archive_link($this->cpt_slug);
        if (empty($canonical)) {
            return;
        }

        // Preserve pagination for canonical
        $paged = (int) get_query_var('paged');
        if ($paged > 1) {
            $canonical = Frl_Rewriter_Path_Utils::collapse_slashes(rtrim($canonical, '/') . '/page/' . $paged . '/');
        }

        Frl_Rewriter_Path_Utils::maybe_redirect_if_needed($canonical);
    }
}
