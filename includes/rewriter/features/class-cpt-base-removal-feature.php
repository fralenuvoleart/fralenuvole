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
        // Property initialisation only. All hook registration happens in register_additional_hooks(),
        // which is called by the coordinator via register() at init priority 15.
    }

    protected function register_additional_hooks(): void
    {
        // CPTs are registered on 'init', so post_type_exists() would fail earlier.
        add_action('init', [$this, 'ensure_config_loaded'], 20, 0);

        // Canonical redirect for CPT singles that still contain the base slug.
        add_action('template_redirect', [$this, 'maybe_redirect_canonical'], 1, 0);

        // Safety-net: late rescue. Runs only when catch-all missed and WP produced a 404.
        add_action('pre_get_posts', [$this, 'late_rescue'], 999, 1);
    }

    /**
     * Late rescue: if WP would 404 but this feature can resolve the URL,
     * inject the correct query vars before the main query executes.
     */
    public function late_rescue(\WP_Query $query): void
    {
        // Must be main frontend query with feature enabled.
        if (!$this->is_enabled() || !$query->is_main_query() || !frl_is_valid_frontend_page_request()) {
            return;
        }

        // Performance: bail early when filter_request() / catch-all already resolved this URL
        // to one of our CPTs. Avoids the DB lookup inside applies_to_request() on every page view.
        $resolved_post_type = $query->get('post_type');
        if (is_string($resolved_post_type) && in_array($resolved_post_type, $this->cpt_slugs, true)) {
            return;
        }

        // One-shot guard: prevents re-entry if parse_query() is called recursively.
        static $rescued = false;
        if ($rescued) {
            return;
        }
        $rescued = true;

        // The previous guard compared the first URL segment against $this->cpt_slugs (CPT type
        // names, e.g. 'service'). For base-removed URLs like /my-post-slug/, the first segment
        // is a post slug, never the CPT type name, so the guard always returned early — making
        // late_rescue effectively inoperative. The guard is removed; applies_to_request() below
        // performs the correct (and only necessary) check via a cached DB lookup.

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
        // Cache the full exclusion list cross-request. The list depends on:
        //   - the configured CPT slugs ($this->cpt_slugs)
        //   - all public CPTs registered in this WP install
        //   - generate_standard_exclusion_patterns() (which has its own cache)
        // All of the above are stable between deploys; frl_cache_clear('rewriter') in
        // clear_rewriter_caches() invalidates this whenever permalink options change.
        $cache_key = 'cpt_base_catch_all_exclusions_' . md5(implode(',', $this->cpt_slugs));

        return frl_cache_remember('rewriter', $cache_key, function () {
            // Merge parent reserved exclusions with configuration-derived patterns
            $patterns = parent::get_catch_all_exclusions();

            // Add standard configuration-driven exclusions (translated bases, CPT/tax bases, pages)
            $patterns = array_merge($patterns, Frl_Rewriter_Path_Utils::generate_standard_exclusion_patterns());

            // Add this feature's own explicit prefixes to protect archive pagination, etc.
            // Use the default '/' delimiter so lang-grouping optimization in add_catch_all_rules() applies.
            foreach ($this->cpt_slugs as $cpt) {
                $obj = get_post_type_object($cpt);
                if ($obj && !empty($obj->rewrite['slug'])) {
                    $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($obj->rewrite['slug']);
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
                    $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($rewrite_slug);
                }
                // Exclude the CPT slug itself (object name) to catch cases where rewrite slug differs.
                $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($pt_obj->name);
                // Add lang-prefixed variants of the slug to cover multilingual URLs (e.g., it/service).
                $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
                foreach ($langs as $lang_code) {
                    if (!empty($rewrite_slug)) {
                        $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex("{$lang_code}/{$rewrite_slug}");
                    }
                    $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex("{$lang_code}/{$pt_obj->name}");
                }
            }

            return array_values(array_unique($patterns));
        });
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

        // Per-request caches to minimize DB work. Note: PHP static variables are
        // method-scoped, so these are NOT shared with resolve_request()'s statics.
        // The persistent frl_cache_remember layer IS shared (same cache key), so both
        // methods store the same shape: ['cpt', 'id', 'name'] or false.
        static $slug_hit_map = [];
        static $multi_index = [];

        // Fast path: single multi-CPT resolution via get_page_by_path
        if (!array_key_exists($slug, $multi_index)) {
            $multi_index[$slug] = frl_cache_remember(
                'permalinks',
                'rewriter_cpt_multislug_' . md5($slug),
                function () use ($slug) {
                    $found = get_page_by_path($slug, OBJECT, $this->cpt_slugs);
                    return ($found && isset($found->ID, $found->post_type))
                        ? ['cpt' => $found->post_type, 'id' => (int) $found->ID, 'name' => basename($slug)]
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

        // Per-request caches. The persistent frl_cache_remember layer is shared with
        // applies_to_request() via the same cache key; both store ['cpt','id','name'] or false.
        static $slug_hit_map = [];
        static $multi_index = [];

        // Fast path: single multi-CPT resolution via get_page_by_path
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
