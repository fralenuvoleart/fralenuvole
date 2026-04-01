<?php

/**
 * Feature: Integrate CPTs with main blog
 *
 * @package FRL
 * @since 3.1.0
 */

defined('ABSPATH') || exit;

final class Frl_CPT_Blog_Integrator_Feature extends Frl_Rewriter_Feature_Base
{
    const FEATURE_ID = 'cpt_blog_integrator';

    public function __construct()
    {
        // Canonical redirect for integrated CPT singles
        frl_hook_add('action', 'template_redirect', [$this, 'maybe_redirect_canonical'], 11, 0);

        // Ensure generated archive links reflect integration
        frl_hook_add('filter', 'post_type_archive_link', [$this, 'filter_archive_link'], 10, 2);
        frl_hook_add('filter', 'get_post_type_archive_link', [$this, 'filter_archive_link'], 10, 2);
        frl_hook_add('filter', 'post_type_archive_feed_link', [$this, 'filter_archive_link'], 10, 2);

        // Provide CPT archive permalinks for translator placeholders when enabled
        if ($this->is_enabled()) {
            frl_hook_add('filter', 'frl_translate_custom_permalink', [$this, 'provide_cpt_archive_permalink'], 10, 3, 'core', false);
            // Final guard: filter each language link emitted by the switcher
            frl_hook_add('filter', 'pll_the_language_link', [$this, 'filter_pll_language_link_for_cpt_archive'], 10, 2, 'core', false);
        }
    }

    public function is_enabled(): bool
    {
        $integrate = frl_get_option('integrate_cpt_with_blog');
        return !empty(trim($integrate ?? ''));
    }

    public function applies_to($object): bool
    {
        if (!isset($object->post_type)) {
            return false;
        }

        $enabled_cpts = $this->get_enabled_cpts();
        return in_array($object->post_type, $enabled_cpts, true);
    }

    public function transform(string $url, $object): string
    {
        if (!$this->is_enabled() || !$this->applies_to($object)) {
            return $url;
        }

        if (!is_object($object) || !isset($object->post_type) || !isset($object->ID)) {
            return $url;
        }

        $post_base_mappings = $this->get_post_base_mappings();
        if (empty($post_base_mappings)) {
            return $url;
        }

        $lang = frl_get_language($object->ID);
        $blog_base = $post_base_mappings[$lang] ?? '';

        if (empty($blog_base)) {
            return $url;
        }

        $cpt_slug_translated = $this->get_cpt_slug_for_transform($object->post_type, $lang);

        // Parse URL into segments
        $parsed = Frl_Rewriter_Path_Utils::parse_url_segments($url);
        $segments = $parsed['segments'];

        // If first segment after optional lang prefix is already blog_base, no change.
        $start_index = 0;
        if (!empty($parsed['lang_prefix'])) {
            $start_index = 0; // lang prefix is stripped already in segments array
        }

        if (isset($segments[$start_index]) && $segments[$start_index] === $blog_base) {
            return $url; // already integrated
        }

        // Find position of CPT slug in segments (prefer translated; fallback to original rewrite slug)
        $index = array_search($cpt_slug_translated, $segments, true);
        if ($index === false) {
            $pt = get_post_type_object($object->post_type);
            $original_cpt_segment = ($pt && isset($pt->rewrite['slug']) && !empty($pt->rewrite['slug'])) ? $pt->rewrite['slug'] : $object->post_type;
            $index = array_search($original_cpt_segment, $segments, true);
            if ($index === false) {
                return $url; // structure not recognized
            }
            // Replace original CPT segment with translated one to ensure tandem behaviour
            $segments[$index] = $cpt_slug_translated;
        }

        // Insert blog_base just before CPT slug occurrence (now guaranteed to be the translated segment)
        array_splice($segments, $index, 0, $blog_base);

        $new_url = Frl_Rewriter_Path_Utils::rebuild_url(
            $parsed['home_url'],
            $parsed['lang_prefix'],
            $segments
        );

        return Frl_Rewriter_Path_Utils::collapse_slashes($new_url);
    }

    private function get_cpt_slug_for_transform(string $cpt_type, string $lang): string
    {
        $cpt_mappings = frl_cache_remember('rewriter', "translate_cpt_type_{$cpt_type}", function () use ($cpt_type) {
            $content = frl_get_option("translate_cpt_slugs_{$cpt_type}");
            $parsed = frl_textlist_to_array($content);

            $mappings = [];
            foreach ($parsed as $line) {
                if (count($line) >= 2) {
                    $mappings[$line[0]] = $line[1];
                }
            }

            return $mappings;
        });

        return $cpt_mappings[$lang] ?? $cpt_type;
    }

    private function get_enabled_cpts(): array
    {
        $option = frl_get_option('integrate_cpt_with_blog');
        $parsed = frl_textlist_to_array($option);

        // This option is a simple list of CPT slugs. Each line is a CPT.
        // frl_textlist_to_array returns an array of arrays, so we need to flatten it.
        $enabled_cpts = [];
        foreach ($parsed as $line) {
            if (!empty($line[0])) {
                $enabled_cpts[] = trim($line[0]);
            }
        }
        return $enabled_cpts;
    }

    private function get_post_base_mappings(): array
    {
        return Frl_Rewriter_Path_Utils::parse_lang_mapping_option('translate_post_base');
    }

    public function generate_rules(): array
    {
        if (!$this->is_enabled()) {
            return [];
        }

        $rules = [];
        $post_base_mappings = $this->get_post_base_mappings();
        $enabled_cpts = $this->get_enabled_cpts();

        foreach ($enabled_cpts as $cpt_slug) {
            $post_type_obj = get_post_type_object($cpt_slug);
            if (!$post_type_obj) {
                continue;
            }
            $query_var = $post_type_obj->query_var ? $post_type_obj->query_var : $cpt_slug;

            foreach ($post_base_mappings as $lang => $base) {
                $cpt_slug_translated = $this->get_cpt_slug_for_transform($cpt_slug, $lang);

                $lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
                $base_esc = Frl_Rewriter_Path_Utils::escape_for_regex($base, '#');
                $slug_esc = Frl_Rewriter_Path_Utils::escape_for_regex($cpt_slug_translated, '#');

                // Rule with language prefix (include name for robust single resolution)
                $rules["^{$lang_esc}/{$base_esc}/{$slug_esc}/([^/]+)/?$"] = "index.php?post_type={$cpt_slug}&name=\$matches[1]&{$query_var}=\$matches[1]&lang={$lang}";

                // Rule without language prefix
                $rules["^{$base_esc}/{$slug_esc}/([^/]+)/?$"] = "index.php?post_type={$cpt_slug}&name=\$matches[1]&{$query_var}=\$matches[1]&lang={$lang}";

                // Archive rules (language-prefixed)
                $rules["^{$lang_esc}/{$base_esc}/{$slug_esc}/?$"] = "index.php?post_type={$cpt_slug}&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/{$slug_esc}/page/?([0-9]{1,})/?$"] = "index.php?post_type={$cpt_slug}&paged=\$matches[1]&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/{$slug_esc}/feed/?$"] = "index.php?post_type={$cpt_slug}&feed=feed&lang={$lang}";

                // Archive rules (without language prefix)
                $rules["^{$base_esc}/{$slug_esc}/?$"] = "index.php?post_type={$cpt_slug}&lang={$lang}";
                $rules["^{$base_esc}/{$slug_esc}/page/?([0-9]{1,})/?$"] = "index.php?post_type={$cpt_slug}&paged=\$matches[1]&lang={$lang}";
                $rules["^{$base_esc}/{$slug_esc}/feed/?$"] = "index.php?post_type={$cpt_slug}&feed=feed&lang={$lang}";
            }
        }

        return $rules;
    }

    public function applies_to_request(string $request_uri): bool
    {
        if (!$this->is_enabled()) {
            return false;
        }

        $post_base_mappings = $this->get_post_base_mappings();
        $enabled_cpts = $this->get_enabled_cpts();
        $uri = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);

        foreach ($enabled_cpts as $cpt_slug) {
            foreach ($post_base_mappings as $lang => $base) {
                $cpt_slug_translated = $this->get_cpt_slug_for_transform($cpt_slug, $lang);
                $lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
                $base_esc = Frl_Rewriter_Path_Utils::escape_for_regex($base, '#');
                $slug_esc = Frl_Rewriter_Path_Utils::escape_for_regex($cpt_slug_translated, '#');

                // With lang prefix
                if (preg_match("#^{$lang_esc}/{$base_esc}/{$slug_esc}/#", $uri)) {
                    return true;
                }

                // Without lang prefix
                if (preg_match("#^{$base_esc}/{$slug_esc}/#", $uri)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function resolve_request(string $request_uri): array
    {
        $post_base_mappings = $this->get_post_base_mappings();
        $enabled_cpts = $this->get_enabled_cpts();
        $uri = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);

        foreach ($enabled_cpts as $cpt_slug) {
            $post_type_obj = get_post_type_object($cpt_slug);
            if (!$post_type_obj) {
                continue;
            }
            $query_var = $post_type_obj->query_var ? $post_type_obj->query_var : $cpt_slug;

            foreach ($post_base_mappings as $lang => $base) {
                $cpt_slug_translated = $this->get_cpt_slug_for_transform($cpt_slug, $lang);
                $lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
                $base_esc = Frl_Rewriter_Path_Utils::escape_for_regex($base, '#');
                $slug_esc = Frl_Rewriter_Path_Utils::escape_for_regex($cpt_slug_translated, '#');

                // Archive with language prefix
                if (preg_match("#^{$lang_esc}/{$base_esc}/{$slug_esc}/?$#", $uri)) {
                    return ['post_type' => $cpt_slug, 'lang' => $lang];
                }
                if (preg_match("#^{$lang_esc}/{$base_esc}/{$slug_esc}/page/?([0-9]{1,})/?$#", $uri, $m)) {
                    return ['post_type' => $cpt_slug, 'paged' => (int)$m[1], 'lang' => $lang];
                }
                if (preg_match("#^{$lang_esc}/{$base_esc}/{$slug_esc}/feed/?$#", $uri)) {
                    return ['post_type' => $cpt_slug, 'feed' => 'feed', 'lang' => $lang];
                }

                // Archive without language prefix
                if (preg_match("#^{$base_esc}/{$slug_esc}/?$#", $uri)) {
                    return ['post_type' => $cpt_slug, 'lang' => $lang];
                }
                if (preg_match("#^{$base_esc}/{$slug_esc}/page/?([0-9]{1,})/?$#", $uri, $m)) {
                    return ['post_type' => $cpt_slug, 'paged' => (int)$m[1], 'lang' => $lang];
                }
                if (preg_match("#^{$base_esc}/{$slug_esc}/feed/?$#", $uri)) {
                    return ['post_type' => $cpt_slug, 'feed' => 'feed', 'lang' => $lang];
                }

                // With language prefix
                if (preg_match("#^{$lang_esc}/{$base_esc}/{$slug_esc}/([^/]+)/?$#", $uri, $matches)) {
                    return ['post_type' => $cpt_slug, $query_var => $matches[1], 'lang' => $lang];
                }
                // Without language prefix
                if (preg_match("#^{$base_esc}/{$slug_esc}/([^/]+)/?$#", $uri, $matches)) {
                    return ['post_type' => $cpt_slug, $query_var => $matches[1], 'lang' => $lang];
                }
            }
        }

        return [];
    }

    public function get_name(): string
    {
        return self::FEATURE_ID;
    }

    /**
     * CRITICAL: Protect CPT blog integration URLs from catch-all features
     */
    public function get_exclusion_patterns(): array
    {
        if (!$this->is_enabled()) {
            return [];
        }

        $patterns = [];
        $post_base_mappings = $this->get_post_base_mappings();
        $enabled_cpts = $this->get_enabled_cpts();

        foreach ($enabled_cpts as $cpt_slug) {
            foreach ($post_base_mappings as $lang => $base) {
                $cpt_slug_translated = $this->get_cpt_slug_for_transform($cpt_slug, $lang);

                // Protect both language-prefixed and non-prefixed patterns
                $patterns[] = preg_quote("{$base}/{$cpt_slug_translated}");
                $patterns[] = preg_quote("{$lang}/{$base}/{$cpt_slug_translated}");
            }
        }

        return $patterns;
    }

    /**
     * Transform generated CPT archive links to include blog base + translated CPT base.
     */
    public function filter_archive_link(string $link, $post_type): string
    {
        if (!$this->is_enabled()) {
            return $link;
        }

        if (!is_string($post_type)) {
            return $link;
        }

        $enabled_cpts = $this->get_enabled_cpts();
        if (!in_array($post_type, $enabled_cpts, true)) {
            return $link;
        }

        $post_base_mappings = $this->get_post_base_mappings();
        if (empty($post_base_mappings)) {
            return $link;
        }

        // Use current language context
        $lang = frl_get_language();
        $blog_base = $post_base_mappings[$lang] ?? '';
        if ($blog_base === '') {
            return $link;
        }

        $cpt_translated = $this->get_cpt_slug_for_transform($post_type, $lang);

        $parsed = Frl_Rewriter_Path_Utils::parse_url_segments($link);
        $segments = $parsed['segments'];

        // Avoid double integration
        if (isset($segments[0]) && $segments[0] === $blog_base) {
            return $link;
        }

        // If last segment is 'feed', keep it and operate before it
        $suffix = [];
        if (!empty($segments) && end($segments) === 'feed') {
            array_pop($segments);
            $suffix = ['feed'];
        }

        // Replace existing CPT base segment if present, else compose from scratch
        $index = array_search($cpt_translated, $segments, true);
        if ($index === false) {
            $pt = get_post_type_object($post_type);
            $original = ($pt && isset($pt->rewrite['slug']) && !empty($pt->rewrite['slug'])) ? $pt->rewrite['slug'] : $post_type;
            $index = array_search($original, $segments, true);
            if ($index !== false) {
                $segments[$index] = $cpt_translated;
            } else {
                $segments = [$blog_base, $cpt_translated];
                $segments = array_merge($segments, $suffix);
                return Frl_Rewriter_Path_Utils::rebuild_url($parsed['home_url'], $parsed['lang_prefix'], $segments);
            }
        }

        array_splice($segments, $index, 0, $blog_base);
        $segments = array_merge($segments, $suffix);
        return Frl_Rewriter_Path_Utils::rebuild_url($parsed['home_url'], $parsed['lang_prefix'], $segments);
    }

    public function validate_patterns(array $existing_patterns): bool
    {
        // This feature generates rules but they are specific to CPT integration,
        // so conflicts are unlikely. However, we should still validate for safety.
        try {
            $my_patterns = array_keys($this->generate_rules());
            foreach ($my_patterns as $my_pattern) {
                foreach ($existing_patterns as $existing_pattern) {
                    if ($my_pattern === $existing_pattern) {
                        return false;
                    }
                }
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Provide integrated CPT archive permalinks for translator placeholders.
     * Usage: ##cpt-archive:{cpt}## → /{lang?}/{post-base}/{translated-cpt}/
     *
     * @param string|null $custom_link Existing custom link from earlier providers
     * @param string $original_slug The placeholder slug (e.g., 'cpt-archive:pill')
     * @param string $language Target language code (e.g., 'en', 'it')
     * @return string|null A permalink or null to defer
     */
    public function provide_cpt_archive_permalink($custom_link, string $original_slug, string $language): ?string
    {
        if ($custom_link !== null) {
            return $custom_link;
        }

        if (!str_starts_with($original_slug, 'cpt-archive:')) {
            return null;
        }

        $cpt = sanitize_key(substr($original_slug, strlen('cpt-archive:')));
        if ($cpt === '' || !post_type_exists($cpt)) {
            return null;
        }

        $post_base_mappings = $this->get_post_base_mappings();
        if (empty($post_base_mappings) || empty($post_base_mappings[$language])) {
            return null;
        }
        $post_base = $post_base_mappings[$language];

        $cpt_mappings = Frl_Rewriter_Path_Utils::parse_lang_mapping_option('translate_cpt_slugs_' . $cpt);
        $translated_cpt = $cpt_mappings[$language] ?? $cpt;

        $default_lang = frl_get_default_language();
        $lang_prefix = ($language !== $default_lang) ? $language : '';

        return Frl_Rewriter_Path_Utils::rebuild_url(home_url(), $lang_prefix, [$post_base, $translated_cpt]);
    }

    /**
     * When current view is an integrated CPT archive, make Polylang switcher
     * point to the CPT archive in the target language instead of the homepage.
     */
    // Removed narrower Polylang URL overrides; per-link filter below is sufficient and cleaner.

    /**
     * Polylang per-language link override (most specific). Ensures the
     * emitted link points to the correct CPT archive path for that language.
     */
    public function filter_pll_language_link_for_cpt_archive(string $url, $lang): string
    {
        if (!$this->is_enabled()) {
            return $url;
        }

        // $lang may be a slug string or an array/object depending on caller
        $target_lang = is_string($lang) ? $lang : (is_array($lang) && isset($lang['slug']) ? (string)$lang['slug'] : '');
        if ($target_lang === '') {
            return $url;
        }

        $enabled_cpts = $this->get_enabled_cpts();
        if (empty($enabled_cpts) || !is_post_type_archive($enabled_cpts)) {
            return $url;
        }

        $current_pt = get_query_var('post_type');
        if (is_array($current_pt)) {
            $current_pt = reset($current_pt);
        }
        if (!is_string($current_pt) || !in_array($current_pt, $enabled_cpts, true)) {
            return $url;
        }

        $post_base_mappings = $this->get_post_base_mappings();
        $post_base = $post_base_mappings[$target_lang] ?? '';
        if ($post_base === '') {
            return $url;
        }

        $translated_cpt = $this->get_cpt_slug_for_transform($current_pt, $target_lang);
        $default_lang = frl_get_default_language();
        $lang_prefix = ($target_lang !== $default_lang) ? $target_lang : '';

        return Frl_Rewriter_Path_Utils::rebuild_url(home_url(), $lang_prefix, [$post_base, $translated_cpt]);
    }
    /**
     * Canonicalize legacy CPT single URLs to integrated blog paths.
     * Uses existing helper to avoid redirects in admin/preview/REST and prevent loops.
     */
    public function maybe_redirect_canonical(): void
    {
        if (!$this->is_enabled()) {
            return;
        }

        $enabled_cpts = $this->get_enabled_cpts();
        if (!empty($enabled_cpts) && is_singular($enabled_cpts)) {
            $post = get_queried_object();
            if (!$post || !isset($post->ID)) {
                return;
            }

            $canonical = get_permalink($post);
            if (empty($canonical) || is_wp_error($canonical)) {
                return;
            }

            Frl_Rewriter_Path_Utils::maybe_redirect_if_needed($canonical);
            return;
        }

        // Archive canonicalization for integrated CPT archives
        if (!empty($enabled_cpts) && is_post_type_archive($enabled_cpts)) {
            // Determine which CPT archive we're on
            $current_pt = get_query_var('post_type');
            if (is_array($current_pt)) {
                $current_pt = reset($current_pt);
            }
            if (!is_string($current_pt) || !in_array($current_pt, $enabled_cpts, true)) {
                return;
            }

            $canonical = get_post_type_archive_link($current_pt);
            if (empty($canonical)) {
                return;
            }

            // Preserve pagination for canonical
            $paged = (int) get_query_var('paged');
            if ($paged > 1) {
                $canonical = Frl_Rewriter_Path_Utils::collapse_slashes(rtrim($canonical, '/') . '/page/' . $paged . '/');
            }

            Frl_Rewriter_Path_Utils::maybe_redirect_if_needed($canonical);
            return;
        }

        // Legacy base redirect: handle bare (lang)/{translated-cpt}/ → (lang)/{post-base}/{translated-cpt}/
        // Minimal path-only detection to avoid regressions (no singles, no taxonomies).
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = trim(Frl_Rewriter_Path_Utils::extract_request_path($request_uri), '/');
        if ($path !== '') {
            $parts = $path === '' ? [] : explode('/', $path);
            if (!empty($parts)) {
                $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
                $lang = '';
                if (in_array($parts[0], $langs, true)) {
                    $lang = $parts[0];
                    array_shift($parts);
                }

                // Looking for exactly one segment remaining: the translated CPT base
                if (count($parts) === 1) {
                    $seg = $parts[0];
                    // Determine language for mapping (fallback to current)
                    $lang = $lang !== '' ? $lang : frl_get_language();
                    $post_base_mappings = $this->get_post_base_mappings();
                    if (!isset($post_base_mappings[$lang])) {
                        return;
                    }
                    foreach ($enabled_cpts as $cpt_slug) {
                        $translated = $this->get_cpt_slug_for_transform($cpt_slug, $lang);
                        if ($seg === $translated) {
                            $blog_base = $post_base_mappings[$lang];
                            $segments = [];
                            if ($lang !== '' && $lang !== frl_get_default_language()) {
                                $segments[] = $lang;
                            }
                            $segments[] = $blog_base;
                            $segments[] = $translated;
                            $canonical = home_url('/' . implode('/', $segments) . '/');
                            Frl_Rewriter_Path_Utils::maybe_redirect_if_needed($canonical);
                            return;
                        }
                    }
                }
            }
        }
    }
}
