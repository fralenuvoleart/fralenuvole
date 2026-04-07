<?php

/**
 * CPT Base Removal Feature
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
 * Handles removal of CPT base slugs (e.g., /service-name instead of /services/service-name)
 *
 * This feature operates completely independently and handles:
 * - Single-segment URLs that could be CPT posts
 * - Hierarchical CPT posts (using get_page_by_path)
 * - Pagination for CPT archives
 */
class Frl_CPT_Base_Removal_Feature extends Frl_Rewriter_Feature_Base
{

    private array $cpt_slugs = [];
    private bool $config_loaded = false;

    public function __construct()
    {
        // Configuration must be loaded on the 'init' hook. CPTs are registered on 'init',
        // so post_type_exists() would fail earlier. The callback must be public.
        add_action('init', [$this, 'ensure_config_loaded'], 20, 0);

        // Add canonical redirect after main query is set up.
        add_action('template_redirect', [$this, 'maybe_redirect_canonical'], 1, 0);
        // Safety-net: late rescue at low priority. Runs only when catch-all missed and WP produced 404.
        add_action('pre_get_posts', [$this, 'late_rescue'], 999, 1);
    }

    /**
     * Late rescue: if WP ended with a 404 but this feature can resolve the URL,
     * convert the 404 into proper query vars.
     */
    public function late_rescue(\WP_Query $query): void
    {
        // ==================================================================
        // Guard-clauses to avoid the infinite pre_get_posts recursion loop
        // that occurs when the request is NOT for one of the CPTs we handle.
        // Guard-clause block above may still bail out; defer checks until after the first-segment check

        // 1) Must be main frontend query and feature enabled
        if ( !$this->is_enabled() || !$query->is_main_query() || !frl_is_valid_frontend_page_request() ) {
            return;
        }

        // 2) Allow exactly one execution per request.
        static $rescued = false;
        if ( $rescued ) {
            return; // already attempted rescue in this request
        }
        $rescued = true; // mark immediately – prevents any further recursion

        // 3) Exit early if URL still contains an explicit CPT base that
        //    this feature is NOT supposed to remove (e.g. /service/slug/).
        $request_path  = Frl_Rewriter_Path_Utils::extract_request_path( $_SERVER['REQUEST_URI'] ?? '' );
        $first_segment = explode( '/', $request_path )[0] ?? '';

        if ( ! in_array( $first_segment, $this->cpt_slugs, true ) ) {
            return;
        }

        // ------------------------------------------------------------------
        // From here on we *know* the URL could belong to a base-less CPT and
        // late_rescue can safely try to resolve it once.
        // ------------------------------------------------------------------

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (!$this->applies_to_request($request_uri)) {
            return;
        }

        $vars = $this->resolve_request($request_uri);
        if (empty($vars)) {
            return;
        }

        // Inject resolved vars.
        foreach ($vars as $key => $value) {
            $query->set($key, $value);
        }

        // Remove page-related vars for non-hierarchical CPTs so WP treats the request as single.
        if (isset($vars['post_type']) && !is_post_type_hierarchical($vars['post_type'])) {
            $query->set('pagename', '');
            $query->set('page_id', 0);
        }

        // Ensure any previous error flag is removed so WP doesn’t force 404 later.
        $query->set( 'error', '' );

        // Polylang adds a language tax_query that forces is_tax=true; remove it so WP treats this as singular.
        $query->set( 'taxonomy', '' );
        $query->set( 'term', '' );
        $query->set( 'tax_query', [] );

        // Recalculate WP_Query flags after our modifications exactly once to avoid recursion.
        static $reparsed = false;
        if (!$reparsed) {
            $reparsed = true;
            $query->parse_query($query->query_vars);
        }

        // Clear 404 state; WP_Query will set proper flags during get_posts().
        $query->is_404 = false;
    }

    public function get_name(): string
    {
        return 'CPT Base Removal';
    }

    public function ensure_config_loaded(): void
    {
        if ($this->config_loaded) {
            return;
        }

        $config = $this->get_option('remove_cpt_base');
        if (!empty(trim($config))) {
            $parsed = frl_textlist_to_array($config);
            foreach ($parsed as $line) {
                if (!empty($line[0]) && post_type_exists($line[0])) {
                    $this->cpt_slugs[] = $line[0];
                }
            }
        }

        $this->config_loaded = true;
    }

    public function is_enabled(): bool
    {
        // This check runs after ensure_config_loaded() is fired on the 'init' hook.
        return !empty($this->cpt_slugs);
    }

    /**
     * Enable explicit catch-all rule so URLs are resolved before hitting 404.
     * WP will add the query var automatically via abstract-base feature.
     */
    public function get_catch_all_query_var(): string
    {
        return 'frl_cpt_base_path';
    }

    protected function get_catch_all_exclusions(): array
    {
        // Merge parent reserved exclusions with configuration-derived patterns
        $patterns = parent::get_catch_all_exclusions();

        // Add standard configuration-driven exclusions (translated bases, CPT/tax bases, pages)
        $patterns = array_merge($patterns, Frl_Rewriter_Path_Utils::generate_standard_exclusion_patterns());

        // Add this feature's own explicit prefixes to protect archive pagination, etc.
        foreach ($this->cpt_slugs as $cpt) {
            $obj = get_post_type_object($cpt);
            if ($obj && !empty($obj->rewrite['slug'])) {
                $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($obj->rewrite['slug'], '#');
            }
        }

        // Exclude base slugs of *all* other public CPTs so the catch-all rule
        // does not hijack URLs that still retain their base. This prevents
        // infinite request-resolution loops (e.g. /service/slug/) when only a
        // subset of CPTs are configured for base removal.
        foreach (get_post_types(['public' => true], 'objects') as $pt_obj) {
            if (in_array($pt_obj->name, $this->cpt_slugs, true)) {
                continue; // We purposely process these CPTs
            }

            // Initialize to avoid undefined variable notices when rewrite slug is absent
            $rewrite_slug = '';

            if (!empty($pt_obj->rewrite['slug'])) {
                $rewrite_slug = $pt_obj->rewrite['slug'];
                $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($rewrite_slug, '#');
            }
            // Exclude the CPT slug itself (object name) to catch cases where rewrite slug differs.
            $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($pt_obj->name, '#');
            // Add lang-prefixed variants of the slug to cover multilingual URLs (e.g., it/service).
            $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
            foreach ($langs as $lang_code) {
                if (!empty($rewrite_slug)) {
                    $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex("{$lang_code}/{$rewrite_slug}", '#');
                }
                $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex("{$lang_code}/{$pt_obj->name}", '#');
            }
        }

        return array_values(array_unique($patterns));
    }

    public function generate_rules(): array
    {
        // This feature uses a catch-all approach handled by the abstract base class.
        return [];
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

        // Only apply to URLs that could potentially be posts from our configured CPTs
        $parts = explode('/', trim($uri, '/'));
        if (empty($parts)) {
            return false;
        }

        // Skip language prefixes to get to the actual content
        $available_langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        if (!empty($parts) && in_array($parts[0], $available_langs, true)) {
            array_shift($parts); // Remove language prefix
        }

        if (empty($parts)) {
            return false;
        }

        // Check if this could be a post from one of our configured CPTs
        // For CPT base removal, we're looking for URLs without the CPT base
        // So we need to check if this could be a post slug from our CPTs
        $slug = implode('/', $parts);

        // Check for pagination
        if (preg_match('#^(.+?)/page/?([0-9]+)/?$#', $slug, $matches)) {
            $slug = $matches[1];
        }

        // Cache per-request lookups (positive and negative) to minimize DB work
        static $slug_hit_map = [];
        static $multi_index = [];

        // Fast path: single multi-CPT resolution
        if (!array_key_exists($slug, $multi_index)) {
            // Persistent cache to avoid repeated DB hits across requests
            $multi_index[$slug] = frl_cache_remember(
                'permalinks',
                'rewriter_cpt_multislug_' . md5($slug),
                function () use ($slug) {
                    $found = get_page_by_path($slug, OBJECT, $this->cpt_slugs);
                    return ($found && isset($found->ID, $found->post_type))
                        ? ['cpt' => $found->post_type, 'id' => (int) $found->ID]
                        : false;
                }
            );

            if (count($multi_index) > 4096) {
                $multi_index = [];
            }
        }
        if ($multi_index[$slug]) {
            return true;
        }

        foreach ($this->cpt_slugs as $cpt) {
            $cache_key = $cpt . '|' . $slug;
            if (isset($slug_hit_map[$cache_key])) {
                if ($slug_hit_map[$cache_key]) {
                    return true;
                }
                continue;
            }

            $post_id = frl_get_cpt_id_by_slug($slug, $cpt);
            $exists  = ($post_id > 0);
            $slug_hit_map[ $cache_key ] = $exists;

            if ( $exists ) {
                // match found
                return true;
            }

            // Memory guard for long-running requests (CLI/REST). Reset after ~4k entries.
            if (count($slug_hit_map) > 4096) {
                $slug_hit_map = [];
            }
        }

        return false; // No matching posts found
    }

    public function resolve_request(string $request_uri): array
    {
        if (!$this->is_enabled()) {
            return [];
        }

        $uri = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
        $parts = explode('/', rtrim($uri, '/'));
        $lang = frl_get_default_language(); // Assume default

        // Check if the first segment is a language code - with validation
        $available_langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        if (!empty($parts) && in_array($parts[0], $available_langs, true)) {
            $lang = array_shift($parts);
        }

        $slug = implode('/', $parts);

        // Check for pagination.
        $paged = 1;
        if (preg_match('#^(.+?)/page/?([0-9]+)/?$#', $slug, $matches)) {
            $slug = $matches[1];
            $paged = (int) $matches[2];
        }

        // Share the per-request map with applies_to_request to benefit from previous checks
        static $slug_hit_map = [];
        static $multi_index = [];

        // Fast path: single multi-CPT resolution
        if (!array_key_exists($slug, $multi_index)) {
            $multi_index[$slug] = frl_cache_remember(
                'permalinks',
                'rewriter_cpt_multislug_' . md5($slug),
                function () use ($slug) {
                    $found = get_page_by_path($slug, OBJECT, $this->cpt_slugs);
                    return ($found && isset($found->ID, $found->post_type))
                        ? [
                            'cpt'  => $found->post_type,
                            'id'   => (int) $found->ID,
                            'name' => basename($slug),
                        ]
                        : false;
                }
            );

            if (count($multi_index) > 4096) {
                $multi_index = [];
            }
        }
        if ($multi_index[$slug]) {
            $cpt  = $multi_index[$slug]['cpt'];
            $post = (object) ['ID' => $multi_index[$slug]['id'], 'post_name' => basename($slug), 'post_type' => $cpt];
            return $this->build_query_vars($post, $cpt, $lang, $paged, $slug);
        }

        foreach ($this->cpt_slugs as $cpt) {
            $cache_key = $cpt . '|' . $slug;
            $post = $slug_hit_map[$cache_key] ?? null;

            if ($post === null) {
                $post_id = frl_get_cpt_id_by_slug($slug, $cpt);
                $post    = $post_id ? (object) ['ID' => $post_id, 'post_name' => basename($slug), 'post_type' => $cpt] : false;
                // Cache truthy/false result to avoid repeated look-ups within the request
                $slug_hit_map[$cache_key] = $post ?: false;
            }

            if ($post) {
                return $this->build_query_vars($post, $cpt, $lang, $paged, $slug);
            }
        }
        return [];
    }

    // --- URL Transformation Methods ---

    public function applies_to($object): bool
    {
        if (!$this->is_enabled() || !isset($object->post_type)) {
            return false;
        }
        $applies = in_array($object->post_type, $this->cpt_slugs, true);

        return $applies;
    }

    public function transform(string $url, $object): string
    {
        if (!$this->applies_to($object)) {
            return $url;
        }

        $post_type = $object->post_type;
        $post_type_obj = get_post_type_object($post_type);

        if (!$post_type_obj || empty($post_type_obj->rewrite['slug'])) {
            return $url;
        }

        $base_to_remove = $post_type_obj->rewrite['slug'];
        $components = Frl_Rewriter_Path_Utils::parse_url_segments($url);

        // If the first segment is the base slug, remove it and rebuild the URL.
        if (!empty($components['segments']) && $components['segments'][0] === $base_to_remove) {
            array_shift($components['segments']);
            return Frl_Rewriter_Path_Utils::rebuild_url(
                $components['home_url'],
                $components['lang_prefix'],
                $components['segments']
            );
        }

        return $url;
    }

    /**
     * Build query variables for found post - extracted for code reuse
     */
    private function build_query_vars($post, string $cpt, string $lang, int $paged, string $full_slug = ''): array
    {
        $post_slug = Frl_Rewriter_Path_Utils::get_post_slug($post);
        $post_type_obj = get_post_type_object($cpt);
        $query_var = !empty($post_type_obj->query_var) ? $post_type_obj->query_var : $cpt;

        $query_vars = [
            'p'         => $post->ID,
            'post_type' => $cpt,
            'name'      => $post_slug, // Add name here, it will be unset later if hierarchical
        ];

        if (is_post_type_hierarchical($cpt)) {
            // For hierarchical post types, 'pagename' must be the full path.
            // 'name' must be unset to avoid conflicts with 'pagename'.
            $query_vars['pagename'] = $full_slug;
            $query_vars[$query_var] = $full_slug;
            unset($query_vars['name']);
        } else {
            // For non-hierarchical post types, 'name' is used.
            $query_vars[$query_var] = $post_slug;
            unset($query_vars['pagename']);
        }

        if (!empty($lang)) {
            $query_vars['lang'] = $lang;
        }

        if ($paged > 1) {
            $query_vars['paged'] = $paged;
        }

        return $query_vars;
    }

    /**
     * Redirect legacy URLs that still contain the CPT base slug to the canonical URL without base.
     */
    public function maybe_redirect_canonical(): void
    {
        if (! $this->is_enabled() || ! is_singular($this->cpt_slugs)) {
            return;
        }

        $post       = get_queried_object();
        if (! $post || ! isset($post->ID)) {
            return;
        }

        $canonical  = get_permalink($post);
        if (!$canonical) {
            return;
        }

        Frl_Rewriter_Path_Utils::maybe_redirect_if_needed($canonical);
    }
}
