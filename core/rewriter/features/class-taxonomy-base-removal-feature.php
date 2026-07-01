<?php

/**
 * Taxonomy Base Removal Feature
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
        // Property initialisation only. All hook registration happens in register_additional_hooks(),
        // which is called by the coordinator via register() at init priority 15.
    }

    protected function register_additional_hooks(): void
    {
        // Configuration must be loaded on 'init' because taxonomies are registered on 'init'.
        add_action('init', [$this, 'load_configuration'], 20, 0);

        // Canonical redirect for legacy taxonomy URLs that still contain the base slug.
        add_action('template_redirect', [$this, 'maybe_redirect_canonical'], 1, 0);

        // Safety-net: inject correct query vars before the main query runs so the 404
        // state is never reached in the first place. Runs at very late pre_get_posts
        // priority (999) so all other query modifications complete first.
        add_action('pre_get_posts', [$this, 'late_rescue'], 999, 1);

        // Early disambiguation: if core parsed a post request under the static base but no
        // post exists, re-map to taxonomy when the slug matches a handled term.
        add_filter('request', [$this, 'disambiguate_static_base_category'], 5, 1);
    }

    /**
     * Late rescue: inject correct taxonomy query vars before the main query executes.
     *
     * Runs on pre_get_posts (same pattern as Frl_CPT_Base_Removal_Feature::late_rescue)
     * so that WP never reaches the 404 state for resolvable taxonomy URLs. The previous
     * approach hooked on 'wp' (after handle_404), which only updated WP::$is_404 but not
     * WP_Query::$is_404 — the property the template loader actually reads — producing
     * soft 404s (200 HTTP status, 404 template content).
     */
    public function late_rescue(\WP_Query $query): void
    {
        if (!$this->is_enabled() || !$query->is_main_query() || !frl_is_valid_frontend_page_request()) {
            return;
        }

        // Performance: bail early when filter_request() / catch-all already resolved the
        // URL into one of the taxonomy query vars handled by this feature. Avoids the DB
        // lookup inside applies_to_request() on every page view.
        foreach ($this->taxonomy_slugs as $tax) {
            $tax_obj   = get_taxonomy($tax);
            $query_var = ($tax_obj && !empty($tax_obj->query_var)) ? $tax_obj->query_var : $tax;
            if ($query->get($query_var) !== '') {
                return;
            }
        }

        // One-shot guard: prevents re-entry if parse_query() is called recursively.
        static $rescued = false;
        if ($rescued) {
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
            $query->set($k, $v);
        }

        // Only mark as rescued after successful resolution, so a transient failure
        // (e.g. DB hiccup in applies_to_request) can be retried on a subsequent
        // parse_query() call during the same request.
        $rescued = true;
    }

    /**
     * Get a human-readable name for this feature (for logging/debugging)
     *
     * @return string The feature name
     */
    public function get_name(): string
    {
        return 'Taxonomy Base Removal';
    }

    /**
     * Check if this feature is enabled via configuration
     *
     * @return bool True if the feature is enabled
     */
    public function is_enabled(): bool
    {
        // Defensive: ensure config is loaded even if called before init/20
        if (empty($this->taxonomy_slugs)) {
            $this->load_configuration();
        }
        return !empty($this->taxonomy_slugs);
    }

    /**
     * Load configuration from options
     *
     * @return void
     */
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
     * Get the catch-all query variable name for this feature (if it uses catch-all)
     *
     * @return string The catch-all query variable name
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
                    // Use the default '/' delimiter so lang-grouping optimization in add_catch_all_rules() applies.
                    $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($static_base);
                    $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
                    foreach ($langs as $lc) {
                        $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex("{$lc}/{$static_base}");
                    }
                }
            }

            return array_unique($patterns);
        });

        return $cached;
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
     * Get configured prefixes from other active rewriter features.
     *
     * CPT translation features contribute their prefixes via the frl_rewriter_url_prefixes
     * filter (registered in their register_additional_hooks()). This keeps the taxonomy
     * feature decoupled from FRL_REWRITER_MULTILINGUAL_CPT and any future features.
     *
     * @return array Array of URL prefixes
     */
    private function get_configured_prefixes(): array
    {
        $prefixes = [];

        // Post base translation prefixes (still read directly — this is taxonomy-agnostic config).
        $post_mappings = Frl_Rewriter_Path_Utils::get_post_base_mappings();
        foreach ($post_mappings as $mapping) {
            if (is_array($mapping) && count($mapping) >= 2) {
                $lang = $mapping[0];
                $base = $mapping[1];
                $prefixes[] = $base;
                $prefixes[] = "{$lang}/{$base}";
            }
        }

        // CPT translation prefixes contributed by individual CPT features via filter.
        // Frl_CPT_Archive_Base_Translation_Feature::contribute_url_prefixes() populates this.
        $prefixes = (array) apply_filters('frl_rewriter_url_prefixes', $prefixes);

        // Include static first segment from permalink structure if present (e.g., 'blog' in /blog/%postname%/).
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
     *
     * @return string The static permalink base or empty string
     */
    private function get_static_permalink_base(): string
    {
        return Frl_Rewriter_Path_Utils::get_static_permalink_base();
    }

    /**
     * Check if this feature should handle the given request URI
     *
     * @param string $request_uri The raw request URI
     * @return bool True if this feature should handle the request
     */
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

    /**
     * Resolve the request URI to WordPress query variables
     *
     * @param string $request_uri The request URI to resolve
     * @return array WordPress query variables or empty array if not handled
     */
    public function resolve_request(string $request_uri): array
    {
        if (!$this->is_enabled()) {
            return [];
        }

        $path = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);

        // Check for pagination first
        $pagination = Frl_Rewriter_Path_Utils::parse_pagination($path, '#/page/([0-9]+)/?$#');
        $path = $pagination['path'];
        $paged = $pagination['paged'];

        $parts = explode('/', trim($path, '/'));
        $lang = frl_get_default_language(); // Assume default lang

        // Detect language code — no length restriction to support locales like pt-br, zh-tw.
        $available_langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        if (isset($parts[0]) && in_array($parts[0], $available_langs, true)) {
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
     *
     * @param array $query_vars The query variables to disambiguate
     * @return array The modified query variables
     */
    public function disambiguate_static_base_category(array $query_vars): array
    {
        if (!$this->is_active_page_request()) {
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
        if (count($parts) > 1) {
            $last = end($parts);
            $prev = $parts[count($parts) - 2];
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
        // Only checks 'post' type since this disambiguation handles static base paths
        // under the post base (e.g., /blog/slug). CPTs have their own base and are not relevant.
        if (!empty($query_vars['name'])) {
            $name        = $query_vars['name'];
            $post_exists = frl_cache_remember('rewriter', 'disambig_page_' . md5($name), function () use ($name) {
                $posts = get_posts([
                    'name'           => $name,
                    'post_type'      => 'post',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                ]);
                return !empty($posts);
            });
            if ($post_exists) {
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
     * Get configured taxonomy slugs for exclusion pattern generation
     *
     * @return array Array of taxonomy slugs
     */
    public function get_taxonomy_slugs(): array
    {
        return $this->taxonomy_slugs;
    }

    /**
     * Protect static permalink base from catch-all hijack when no post base translation is set.
     *
     * @return array Array of regex patterns to exclude
     */
    protected function get_exclusion_patterns(): array
    {
        $patterns = [];
        $has_post_base = !empty(Frl_Rewriter_Path_Utils::get_post_base_mappings());
        if (!$has_post_base) {
            $static_base = $this->get_static_permalink_base();
            if ($static_base !== '') {
                // Use escape_for_regex() with default '/' delimiter so lang-grouping optimization
                // in add_catch_all_rules() can group these patterns (requires '\/' not '/').
                $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($static_base);
                $languages = Frl_Rewriter_Path_Utils::get_active_languages_safe();
                foreach ($languages as $lc) {
                    $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex("{$lc}/{$static_base}");
                }
            }
        }
        return $patterns;
    }

    /**
     * Check if a given taxonomy is handled by this feature
     *
     * @param string $taxonomy The taxonomy slug to check
     * @return bool True if the taxonomy is handled by this feature
     */
    public function handles_taxonomy(string $taxonomy): bool
    {
        return in_array($taxonomy, $this->taxonomy_slugs, true);
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
        if (!isset($object->taxonomy)) {
            return false;
        }
        return in_array($object->taxonomy, $this->taxonomy_slugs, true);
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

    /**
     * Get the base slug for a taxonomy for URL transformation
     *
     * @param string $taxonomy The taxonomy slug
     * @return string The base slug to remove
     */
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
