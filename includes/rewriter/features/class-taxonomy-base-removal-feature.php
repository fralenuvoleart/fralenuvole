<?php

/**
 * Taxonomy Base Removal Feature
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
 * Handles removal of taxonomy base slugs (e.g., /category-name instead of /category/category-name)
 *
 * This feature operates completely independently and handles:
 * - Single-segment URLs that could be taxonomy terms
 * - Pagination for taxonomy archives
 * - Multiple taxonomies configuration
 */
class Frl_Taxonomy_Base_Removal_Feature extends Frl_Rewriter_Feature_Base
{

    private array $taxonomy_slugs = [];

    public function __construct()
    {
        // Configuration must be loaded on the 'init' hook because taxonomies are registered on 'init'.
        add_action('init', [$this, 'load_configuration'], 20, 0);

        // Canonical redirect for legacy taxonomy URLs.
        add_action('template_redirect', [$this, 'maybe_redirect_canonical'], 1, 0);
        // Safety-net: late 404 rescue if catch-all missed.
        add_action('parse_request', [$this, 'late_rescue'], 99, 1);
        // Also run the same rescue after the main query is prepared, when 404 is known.
        add_action('wp', [$this, 'late_rescue'], 99, 1);

        // Deterministic disambiguation: if core parsed a post request under the static base but no post exists,
        // re-map to taxonomy when the slug matches a handled term. Runs very early in request parsing.
        add_filter('request', [$this, 'disambiguate_static_base_category'], 5, 1);
    }

    /**
     * Late rescue: resolve taxonomy URLs that lost their base only if WP produced a 404.
     */
    public function late_rescue($wp): void
    {
        if (!$this->is_enabled()) {
            return;
        }

        // Allow 404 rescue on frontend while still skipping non-frontend contexts.
        // We cannot use frl_is_valid_frontend_page_request() here because it returns false on 404s.
        if (frl_is_admin() || frl_is_rest_api_request() || frl_is_cron_job_request() || frl_is_doing_ajax()) {
            return;
        }

        // Ensure we are working with a WP instance and it actually represents a 404.
        if (!is_object($wp) || !property_exists($wp, 'is_404') || !$wp->is_404) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (!$this->applies_to_request($request_uri)) {
            return;
        }

        $vars = $this->resolve_request($request_uri);
        if (empty($vars)) {
            return;
        }

        foreach ($vars as $k => $v) {
            $wp->query_vars[$k] = $v;
        }

        // Clear error flag and matched properties so WordPress treats as resolved.
        $wp->query_vars['error'] = '';
        $wp->matched_rule  = ''; // prevents canonical redirect collisions
        $wp->matched_query = '';

        // Explicitly set taxonomy/query flags for later template loading
        if (isset($vars['lang'])) {
            $wp->query_vars['lang'] = $vars['lang'];
        }

        status_header(200);
        $wp->is_404 = false;
    }

    public function get_name(): string
    {
        return 'Taxonomy Base Removal';
    }

    public function is_enabled(): bool
    {
        return !empty($this->taxonomy_slugs);
    }

    public function load_configuration(): void
    {
        $config = $this->get_option('remove_tax_base');
        if (empty(trim($config))) {
            return;
        }

        $parsed = $this->parse_config($config);
        foreach ($parsed as $line) {
            if (!empty($line[0]) && taxonomy_exists($line[0])) {
                $this->taxonomy_slugs[] = $line[0];
            }
        }
    }

    /**
     * Enable explicit catch-all so taxonomy URLs resolve without 404.
     */
    public function get_catch_all_query_var(): string
    {
        return 'frl_tax_base_path';
    }

    protected function get_catch_all_exclusions(): array
    {
        $patterns = parent::get_catch_all_exclusions();

        // Add configuration-based exclusions (no coordinator dependency)
        $config_exclusions = $this->get_configuration_based_exclusions();
        $patterns = array_merge($patterns, $config_exclusions);

        return array_unique($patterns);
    }

    /**
     * Get exclusion patterns from configuration (independent of other features)
     */
    private function get_configuration_based_exclusions(): array
    {
        // In-request static cache to avoid repeated work
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cache_key = 'tax_base_page_exclusions';

        $cached = frl_cache_remember('rewriter', $cache_key, function () {
            // Base exclusions common to all features
            $patterns = Frl_Rewriter_Path_Utils::generate_standard_exclusion_patterns();

            // Page-based exclusions are produced centrally by Path Utils now.

            // Protect static base from catch-all hijack when no post base translation is configured
            if (empty(Frl_Rewriter_Path_Utils::get_post_base_mappings())) {
                $static_base = $this->get_static_permalink_base();
                if ($static_base !== '') {
                    $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($static_base, '#');
                    $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
                    foreach ($langs as $lc) {
                        $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex("{$lc}/{$static_base}", '#');
                    }
                }
            }

            return array_unique($patterns);
        });

        return $cached;
    }

    public function generate_rules(): array
    {
        if (!$this->is_enabled()) {
            return [];
        }

        // Add explicit rules for configured prefixes to handle taxonomy terms under those prefixes
        $rules = [];
        $prefixes = $this->get_configured_prefixes();

        if (!empty($prefixes)) {
            foreach ($this->taxonomy_slugs as $taxonomy) {
                foreach ($prefixes as $prefix) {
                    $prefix = trim($prefix, '/');
                    if ($prefix === '') {
                        continue;
                    }
                    // Avoid adding generic static-base rules (plain or lang-prefixed) that collide with post singles under /blog/%postname%/
                    $static_base = $this->get_static_permalink_base();
                    if ($static_base !== '' && empty(Frl_Rewriter_Path_Utils::get_post_base_mappings())) {
                        $skip = false;
                        if ($prefix === $static_base) {
                            $skip = true;
                        } else {
                            $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
                            foreach ($langs as $lc) {
                                if ($prefix === ($lc . '/' . $static_base)) {
                                    $skip = true;
                                    break;
                                }
                            }
                        }
                        if ($skip) {
                            continue;
                        }
                    }
                    $escaped = Frl_Rewriter_Path_Utils::escape_for_regex($prefix);
                    // Use taxonomy query var (e.g., category_name for "category") for maximum compatibility.
                    $tax_obj = get_taxonomy($taxonomy);
                    $query_var = is_object($tax_obj) && !empty($tax_obj->query_var) ? $tax_obj->query_var : $taxonomy;

                    // Match /prefix/term/
                    $rules['^' . $escaped . '/([^/]+)/?$'] = 'index.php?' . $query_var . '=$matches[1]';
                    // Match pagination /prefix/term/page/2/
                    $rules['^' . $escaped . '/([^/]+)/page/?([0-9]{1,})/?$'] = 'index.php?' . $query_var . '=$matches[1]&paged=$matches[2]';
                }
            }
        }

        // Catch-all rules are handled independently by the abstract base class
        return $rules;
    }

    /**
     * Get configured prefixes from configuration (independent of other features)
     */
    private function get_configured_prefixes(): array
    {
        $prefixes = [];

        // Get post base translation prefixes if configured - use consolidated config
        $post_mappings = Frl_Rewriter_Path_Utils::get_post_base_mappings();
        foreach ($post_mappings as $mapping) {
            if (is_array($mapping) && count($mapping) >= 2) {
                $lang = $mapping[0];
                $base = $mapping[1];
                $prefixes[] = $base;
                $prefixes[] = "{$lang}/{$base}";
            }
        }

        // Get CPT translation prefixes if configured - use consolidated config
        if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
            foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                $cpt_mappings = Frl_Rewriter_Path_Utils::get_cpt_mappings($cpt_slug);
                foreach ($cpt_mappings as $mapping) {
                    if (is_array($mapping) && count($mapping) >= 2) {
                        $lang = $mapping[0];
                        $base = $mapping[1];
                        $prefixes[] = $base;
                        $prefixes[] = "{$lang}/{$base}";
                    }
                }
            }
        }

        // Include static first segment from permalink structure if present (e.g., 'blog' in /blog/%postname%/)
        $static_base = $this->get_static_permalink_base();
        if ($static_base !== '') {
            $prefixes[] = $static_base;
            $languages = Frl_Rewriter_Path_Utils::get_active_languages_safe();
            foreach ($languages as $lc) {
                $prefixes[] = "{$lc}/{$static_base}";
            }
        }

        return array_unique($prefixes);
    }

    /**
     * Extract static first segment from permalink structure (if not a placeholder)
     */
    private function get_static_permalink_base(): string
    {
        return Frl_Rewriter_Path_Utils::get_static_permalink_base();
    }

    public function applies_to_request(string $request_uri): bool
    {
        if (!$this->is_enabled()) {
            return false;
        }

        $uri = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
        if (empty($uri)) {
            return false;
        }

        // Don't apply to URLs that clearly belong to other post types
        // Check if the first segment matches any registered CPT rewrite slug
        $parts = explode('/', trim($uri, '/'));
        if (empty($parts)) {
            return false;
        }

        $first_segment = $parts[0];

        // Skip language prefixes
        $available_langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        if (in_array($first_segment, $available_langs, true) && isset($parts[1])) {
            $first_segment = $parts[1];
        }

        // Don't apply if this looks like a CPT URL
        static $cpt_rewrite_slugs = null;
        if ($cpt_rewrite_slugs === null) {
            $cpt_rewrite_slugs = [];
            $cpt_objects = get_post_types(['public' => true, '_builtin' => false], 'objects');
            foreach ($cpt_objects as $cpt) {
                if (isset($cpt->rewrite['slug']) && $cpt->rewrite['slug'] !== '') {
                    $cpt_rewrite_slugs[] = $cpt->rewrite['slug'];
                }
            }
        }
        if (in_array($first_segment, $cpt_rewrite_slugs, true)) {
            return false; // This is clearly a CPT URL, not a taxonomy URL
        }

        return true; // Could be a taxonomy term URL
    }

    public function resolve_request(string $request_uri): array
    {

        $path = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);

        // Check for pagination first
        $paged = 1;
        if (preg_match('#/page/([0-9]+)/?$#', $path, $paged_matches)) {
            $paged = (int) $paged_matches[1];
            $path = preg_replace('#/page/([0-9]+)/?$#', '', $path);
        }

        $parts = explode('/', trim($path, '/'));
        $lang = frl_get_default_language(); // Assume default lang

        // Detect language code - with validation
        $available_langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        if (isset($parts[0]) && strlen($parts[0]) === 2 && in_array($parts[0], $available_langs, true)) {
            $lang = array_shift($parts);
        }

        // Detect translated post base (e.g., 'news') - use consolidated config
        $post_base_mappings = Frl_Rewriter_Path_Utils::get_post_base_mappings();
        $bases = array_column($post_base_mappings, 1);
        if (isset($parts[0]) && in_array($parts[0], $bases, true)) {
            array_shift($parts); // Remove the translated base
        }

        // Detect static permalink base (e.g., 'blog' from /blog/%postname%/) and remove it
        $static_base = $this->get_static_permalink_base();
        if ($static_base !== '' && isset($parts[0]) && $parts[0] === $static_base) {
            array_shift($parts);
        }

        $slug = implode('/', $parts);

        // Now find the term by the remaining slug
        foreach ($this->taxonomy_slugs as $taxonomy) {
            $term_id = frl_get_term_id_by_slug($slug, $taxonomy);
            if ($term_id) {
                // Use the same query var resolution logic as in rule generation.
                $tax_obj   = get_taxonomy($taxonomy);
                $query_var = is_object($tax_obj) && !empty($tax_obj->query_var) ? $tax_obj->query_var : $taxonomy;

                $query_vars = [
                    $query_var => $slug,
                    'lang'      => $lang,
                ];

                if ($paged > 1) {
                    $query_vars['paged'] = $paged;
                }

                return $query_vars;
            }
        }

        return [];
    }

    /**
     * Early deterministic category disambiguation for static-base paths.
     * If WP parsed this as a post name under the static base but no post exists,
     * and the slug matches a handled taxonomy term, rewrite the request to that taxonomy.
     */
    public function disambiguate_static_base_category(array $query_vars): array
    {
        if (!$this->is_enabled()) {
            return $query_vars;
        }

        // Only consider when core parsed a standard frontend request without explicit custom post_type
        if (!empty($query_vars['post_type'])) {
            return $query_vars;
        }

        // Check the path starts with the configured static base
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
        if ($path === '') {
            return $query_vars;
        }
        $parts = explode('/', trim($path, '/'));
        if (empty($parts)) {
            return $query_vars;
        }

        // Remove language prefix if present
        $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        if (in_array($parts[0], $langs, true)) {
            array_shift($parts);
        }

        $static_base = $this->get_static_permalink_base();
        if ($static_base === '' || empty($parts) || !isset($parts[0]) || $parts[0] !== $static_base) {
            return $query_vars;
        }
        array_shift($parts); // remove static base

        // Detect pagination suffix in path regardless of existing query_vars
        $paged = 1;
        if (!empty($parts) && end($parts) === '') {
            array_pop($parts);
        }
        if (!empty($parts)) {
            $last = end($parts);
            $prev = count($parts) > 1 ? $parts[count($parts)-2] : '';
            if ($prev === 'page' && ctype_digit($last)) {
                $paged = (int) $last;
                array_pop($parts); // remove page number
                array_pop($parts); // remove 'page'
            }
        }

        if (empty($parts)) {
            return $query_vars;
        }

        $slug = implode('/', $parts);

        // If a post truly exists with this name (when provided), keep as-is.
        if (!empty($query_vars['name'])) {
            $post = get_page_by_path($query_vars['name'], OBJECT, get_post_types(['public' => true]));
            if ($post && isset($post->ID)) {
                return $query_vars;
            }
        }

        // Map to taxonomy if slug matches
        foreach ($this->taxonomy_slugs as $taxonomy) {
            $term_id = frl_get_term_id_by_slug($slug, $taxonomy);
            if ($term_id) {
                $tax_obj   = get_taxonomy($taxonomy);
                $query_var = is_object($tax_obj) && !empty($tax_obj->query_var) ? $tax_obj->query_var : $taxonomy;

                // Rewrite query: remove post name, set taxonomy var
                unset($query_vars['name']);
                $query_vars[$query_var] = $slug;
                if ($paged > 1) {
                    $query_vars['paged'] = $paged;
                }
                return $query_vars;
            }
        }

        return $query_vars;
    }

    /**
     * Late rescue for static-base taxonomy paths (e.g., /blog/<term>) only when posts don't match.
     * Runs once at a very late priority to avoid interfering with core post resolution.
     */
    public function late_rescue_static_base(\WP_Query $query): void
    {
        if (!$this->is_enabled() || !$query->is_main_query() || !frl_is_valid_frontend_page_request()) {
            return;
        }

        // Only act on 404s; do not interfere with successful post matches
        if (!$query->is_404) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
        if ($path === '') {
            return;
        }

        $parts = explode('/', trim($path, '/'));
        if (empty($parts)) {
            return;
        }

        // Remove language prefix
        $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        $first = $parts[0];
        $lang = '';
        if (in_array($first, $langs, true)) {
            $lang = array_shift($parts);
        }

        // Require static base
        $static_base = $this->get_static_permalink_base();
        if ($static_base === '' || empty($parts) || !isset($parts[0]) || $parts[0] !== $static_base) {
            return;
        }
        array_shift($parts); // remove static base

        if (empty($parts)) {
            return;
        }

        $slug = implode('/', $parts);

        foreach ($this->taxonomy_slugs as $taxonomy) {
            $term_id = frl_get_term_id_by_slug($slug, $taxonomy);
            if ($term_id) {
                $tax_obj   = get_taxonomy($taxonomy);
                $query_var = is_object($tax_obj) && !empty($tax_obj->query_var) ? $tax_obj->query_var : $taxonomy;

                $query->set($query_var, $slug);
                if (!empty($lang)) {
                    $query->set('lang', $lang);
                }
                $query->set('error', '');
                $query->is_404 = false;
                $query->parse_query($query->query_vars);
                return;
            }
        }
    }

    /**
     * Get configured taxonomy slugs for exclusion pattern generation
     */
    public function get_taxonomy_slugs(): array
    {
        return $this->taxonomy_slugs;
    }

    /**
     * Protect static permalink base from catch-all hijack when no post base translation is set.
     */
    protected function get_exclusion_patterns(): array
    {
        $patterns = [];
        $has_post_base = !empty(Frl_Rewriter_Path_Utils::get_post_base_mappings());
        if (!$has_post_base) {
            $static_base = $this->get_static_permalink_base();
            if ($static_base !== '') {
                $patterns[] = preg_quote($static_base);
                $languages = Frl_Rewriter_Path_Utils::get_active_languages_safe();
                foreach ($languages as $lc) {
                    $patterns[] = preg_quote("{$lc}/{$static_base}");
                }
            }
        }
        return $patterns;
    }

    /**
     * Check if a given taxonomy is handled by this feature
     */
    public function handles_taxonomy(string $taxonomy): bool
    {
        return in_array($taxonomy, $this->taxonomy_slugs, true);
    }

    // --- URL Transformation Methods ---

    public function applies_to($object): bool
    {
        if (!isset($object->taxonomy)) {
            return false;
        }
        return in_array($object->taxonomy, $this->taxonomy_slugs, true);
    }

    public function transform(string $url, $object): string
    {
        if (!$this->is_enabled() || !$this->applies_to($object)) {
            return $url;
        }

        if (!is_object($object) || !isset($object->taxonomy)) {
            frl_log(
                'Taxonomy Base Removal: Invalid object provided for transformation. Object type: {object_type}',
                ['object_type' => gettype($object)],
                true
            );
            return $url;
        }

        $tax_base = $this->get_taxonomy_base_for_transform($object->taxonomy);
        if (empty($tax_base)) {
            return $url;
        }

        // Operate only on the path portion to avoid corrupting scheme/host part.
        $home = rtrim(home_url(), '/');
        if (!str_starts_with($url, $home)) {
            return $url; // Unexpected shape – bail early.
        }

        $path = substr($url, strlen($home)); // leading slash included or empty
        $pattern = '/' . Frl_Rewriter_Path_Utils::escape_for_regex($tax_base) . '/';
        $path = Frl_Rewriter_Path_Utils::safe_preg_replace($pattern, '/', $path);
        $path = Frl_Rewriter_Path_Utils::collapse_slashes($path);

        return $home . $path;
    }

    private function get_taxonomy_base_for_transform(string $taxonomy): string
    {
        if ($taxonomy === 'category') {
            return get_option('category_base') ?: 'category';
        }

        if ($taxonomy === 'post_tag') {
            return get_option('tag_base') ?: 'tag';
        }

        $tax_obj = get_taxonomy($taxonomy);
        if (!$tax_obj || !isset($tax_obj->rewrite) || !is_array($tax_obj->rewrite)) {
            return $taxonomy;
        }

        return $tax_obj->rewrite['slug'] ?? $taxonomy;
    }

    /**
     * Redirect legacy taxonomy URLs that still contain the base slug to canonical.
     */
    public function maybe_redirect_canonical(): void
    {
        if (!$this->is_enabled()) {
            return;
        }

        // Determine if current request is a handled taxonomy (supports built-in and custom)
        $handled = false;
        $taxes = $this->taxonomy_slugs;
        if (in_array('category', $taxes, true) && is_category()) {
            $handled = true;
        } elseif (in_array('post_tag', $taxes, true) && is_tag()) {
            $handled = true;
        } else {
            // Filter out built-ins for is_tax()
            $custom = array_values(array_filter($taxes, function ($t) {
                return $t !== 'category' && $t !== 'post_tag';
            }));
            if (!empty($custom) && is_tax($custom)) {
                $handled = true;
            }
        }

        if (!$handled) {
            return;
        }

        $term = get_queried_object();
        if (!$term || is_wp_error($term)) {
            return;
        }

        $canonical = get_term_link($term);
        if (is_wp_error($canonical)) {
            return;
        }

        // Apply this feature's transformation to ensure canonical reflects base removal and static base
        $canonical = $this->transform($canonical, $term);

        // Preserve pagination in canonical for paged archives
        $paged = (int) get_query_var('paged');
        if ($paged > 1) {
            $canonical = Frl_Rewriter_Path_Utils::collapse_slashes(rtrim($canonical, '/') . '/page/' . $paged . '/');
        }

        Frl_Rewriter_Path_Utils::maybe_redirect_if_needed($canonical);
    }
}
