<?php
/**
 * Post Base Translation Feature
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
 * Handles translation of post base slugs (e.g., en/words, it/parole)
 *
 * This feature operates completely independently and handles:
 * - Post URLs with translated bases
 * - Category + post URLs when %category% is in permalink structure
 * - Language prefix support
 */
class Frl_Post_Single_Base_Translation_Feature extends Frl_Rewriter_Feature_Base {

    private array $mappings = [];
    private bool $has_category_structure = false;

    public function __construct() {
        // Configuration must be loaded on the 'init' hook. This is critical because
        // other features and permalink structures are not available before this point.
        // The callback method must be public to be accessible by the hook.
        frl_hook_add('action', 'init', [$this, 'load_configuration'], 20, 0);
    }

    public function get_name(): string {
        return 'Post Single Base Translation';
    }

    public function is_enabled(): bool {
        return !empty($this->mappings);
    }

    public function load_configuration(): void {
        // Cache the entire configuration parsing for performance
        $cache_key = 'post_single_config';
        $cached_config = frl_cache_remember('rewriter', $cache_key, function() {
            $mappings = Frl_Rewriter_Path_Utils::parse_lang_mapping_option('translate_post_base');

            return [
                'mappings'      => $mappings,
                'has_category'  => Frl_Rewriter_Path_Utils::has_category_structure(),
            ];
        });

        $this->mappings = $cached_config['mappings'];
        $this->has_category_structure = $cached_config['has_category'];

        // Register custom permalink translator filter to keep translator and
        // rewriter decoupled while still allowing ##slug## placeholders to
        // resolve to post-base URLs.
        if ($this->is_enabled()) {
            frl_hook_add('filter', 'frl_translate_custom_permalink', [$this, 'translate_base_permalink'], 10, 3, 'core', false);
        }

        // Add a redirect hook to handle canonical URL enforcement.
        if ($this->is_enabled() && $this->has_category_structure) {
            frl_hook_add('action', 'template_redirect', [$this, 'redirect_canonical'], 11, 1);
        }
    }

    public function generate_rules(): array {
        if (!$this->is_enabled()) {
            return [];
        }

        $rules = [];

        // Check if permalink structure has date components
        $permalink_structure = Frl_Rewriter_Path_Utils::get_permalink_structure();
        $has_date = str_contains($permalink_structure, '%year%') ||
                   str_contains($permalink_structure, '%monthnum%') ||
                   str_contains($permalink_structure, '%day%');

        foreach ($this->mappings as $lang => $base) {
            $lang_esc  = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
            $base_esc  = Frl_Rewriter_Path_Utils::escape_for_regex($base, '#');

            if ($this->has_category_structure) {
                // Category rules: posts with categories in permalink structure
                // With language prefix (e.g., it/parole/categoria/post-name)
                $rules["^{$lang_esc}/{$base_esc}/([^/]+)/([^/]+)/?$"] = "index.php?category_name=\$matches[1]&name=\$matches[2]&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/([^/]+)/([^/]+)/feed/?$"] = "index.php?category_name=\$matches[1]&name=\$matches[2]&feed=feed&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/([^/]+)/([^/]+)/embed/?$"] = "index.php?category_name=\$matches[1]&name=\$matches[2]&embed=true&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/([^/]+)/([^/]+)/comment-page-([0-9]{1,})/?$"] = "index.php?category_name=\$matches[1]&name=\$matches[2]&cpage=\$matches[3]&lang={$lang}";
                // Without language prefix (e.g., parole/categoria/post-name)
                $rules["^{$base_esc}/([^/]+)/([^/]+)/?$"] = "index.php?category_name=\$matches[1]&name=\$matches[2]&lang={$lang}";
                $rules["^{$base_esc}/([^/]+)/([^/]+)/feed/?$"] = "index.php?category_name=\$matches[1]&name=\$matches[2]&feed=feed&lang={$lang}";
                $rules["^{$base_esc}/([^/]+)/([^/]+)/embed/?$"] = "index.php?category_name=\$matches[1]&name=\$matches[2]&embed=true&lang={$lang}";
                $rules["^{$base_esc}/([^/]+)/([^/]+)/comment-page-([0-9]{1,})/?$"] = "index.php?category_name=\$matches[1]&name=\$matches[2]&cpage=\$matches[3]&lang={$lang}";
            }

            // Handle date-based permalinks if present
            if ($has_date) {
                // Year/Month/Day/Postname structure with language prefix
                $rules["^{$lang_esc}/{$base_esc}/([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/([^/]+)/?$"] = "index.php?year=\$matches[1]&monthnum=\$matches[2]&day=\$matches[3]&name=\$matches[4]&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/([0-9]{4})/([0-9]{1,2})/([^/]+)/?$"] = "index.php?year=\$matches[1]&monthnum=\$matches[2]&name=\$matches[3]&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/([0-9]{4})/([^/]+)/?$"] = "index.php?year=\$matches[1]&name=\$matches[2]&lang={$lang}";

                // Without language prefix
                $rules["^{$base_esc}/([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/([^/]+)/?$"] = "index.php?year=\$matches[1]&monthnum=\$matches[2]&day=\$matches[3]&name=\$matches[4]&lang={$lang}";
                $rules["^{$base_esc}/([0-9]{4})/([0-9]{1,2})/([^/]+)/?$"] = "index.php?year=\$matches[1]&monthnum=\$matches[2]&name=\$matches[3]&lang={$lang}";
                $rules["^{$base_esc}/([0-9]{4})/([^/]+)/?$"] = "index.php?year=\$matches[1]&name=\$matches[2]&lang={$lang}";
            }

            // ONLY add post-only rules if the permalink structure does not contain a category.
            // This prevents duplicate content issues.
            if (!$this->has_category_structure) {
                // With language prefix (e.g., it/parole/post-slug)
                $rules["^{$lang_esc}/{$base_esc}/([^/]+)/?$"] = "index.php?name=\$matches[1]&post_type=post&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/([^/]+)/feed/?$"] = "index.php?name=\$matches[1]&post_type=post&feed=feed&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/([^/]+)/embed/?$"] = "index.php?name=\$matches[1]&post_type=post&embed=true&lang={$lang}";
                $rules["^{$lang_esc}/{$base_esc}/([^/]+)/comment-page-([0-9]{1,})/?$"] = "index.php?name=\$matches[1]&post_type=post&cpage=\$matches[2]&lang={$lang}";

                // Without language prefix (e.g., parole/post-slug)
                $rules["^{$base_esc}/([^/]+)/?$"] = "index.php?name=\$matches[1]&post_type=post&lang={$lang}";
                $rules["^{$base_esc}/([^/]+)/feed/?$"] = "index.php?name=\$matches[1]&post_type=post&feed=feed&lang={$lang}";
                $rules["^{$base_esc}/([^/]+)/embed/?$"] = "index.php?name=\$matches[1]&post_type=post&embed=true&lang={$lang}";
                $rules["^{$base_esc}/([^/]+)/comment-page-([0-9]{1,})/?$"] = "index.php?name=\$matches[1]&post_type=post&cpage=\$matches[2]&lang={$lang}";
            }
        }

        return $rules;
    }

    public function applies_to_request(string $request_uri): bool {
        return !empty($this->resolve_request($request_uri));
    }

    public function resolve_request(string $request_uri): array {
        static $cache = [];
        if (isset($cache[$request_uri])) {
            return $cache[$request_uri];
        }

        if (!$this->is_enabled()) {
            return $cache[$request_uri] = [];
        }

        $uri = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);

        foreach ($this->mappings as $lang => $base) {
            $lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex($lang, '#');
            $base_esc = Frl_Rewriter_Path_Utils::escape_for_regex($base, '#');

            if ($this->has_category_structure) {
                // Category + post pattern with language prefix
                if (preg_match("#^{$lang_esc}/{$base_esc}/([^/]+)/([^/]+)/?$#", $uri, $matches)) {
                    return $cache[$request_uri] = [
                        'category_name' => $matches[1],
                        'name' => $matches[2],
                        'lang' => $lang,
                        'post_type' => 'post'
                    ];
                }

                // Category + post pattern without language prefix
                if (preg_match("#^{$base_esc}/([^/]+)/([^/]+)/?$#", $uri, $matches)) {
                    return $cache[$request_uri] = [
                        'category_name' => $matches[1],
                        'name' => $matches[2],
                        'lang' => $lang,
                        'post_type' => 'post'
                    ];
                }
            }

            // Only attempt to resolve post-only patterns if the structure allows it.
            if (!$this->has_category_structure) {
                // Post-only pattern with language prefix
                if (preg_match("#^{$lang_esc}/{$base_esc}/([^/]+)/?$#", $uri, $matches)) {
                    return $cache[$request_uri] = [
                        'name' => $matches[1],
                        'post_type' => 'post',
                        'lang' => $lang
                    ];
                }

                // Post-only pattern without language prefix
                if (preg_match("#^{$base_esc}/([^/]+)/?$#", $uri, $matches)) {
                    return $cache[$request_uri] = [
                        'name' => $matches[1],
                        'post_type' => 'post',
                        'lang' => $lang
                    ];
                }
            }
        }

        return $cache[$request_uri] = [];
    }

    /**
     * Get all configured post bases (for use by other features to avoid conflicts)
     */
    public function get_post_bases(): array {
        return array_values($this->mappings);
    }

    /**
     * Get all configured language + base combinations for pattern exclusions
     */
    public function get_exclusion_patterns(): array {
        return Frl_Rewriter_Path_Utils::get_lang_base_patterns($this->mappings);
    }



    // --- URL Transformation Methods ---

    public function applies_to($object): bool {
        // When category is in the permalink structure, this feature must also handle category term links.
        if ($this->has_category_structure && isset($object->taxonomy) && $object->taxonomy === 'category') {
            return false; // Category term objects handled by Post Archive feature
        }

        $post_type = get_post_type($object);
        return $post_type === 'post';
    }

    public function transform(string $url, $object): string {
        if (!$this->is_enabled() || !$this->applies_to($object)) {
            return $url;
        }

        $lang = $this->detect_language($object);
        if (empty($lang)) {
            return $url;
        }

        $target_base = $this->mappings[$lang] ?? '';
        if (empty($target_base)) {
            return $url;
        }

                // Category term links are handled by the Post-Archive translation feature.
        if (isset($object->taxonomy) && $object->taxonomy === 'category') {
            return $url; // Skip – maintain feature isolation.
        }

        // Validate URL before transformation for posts.
        if (!$this->should_transform_url($url)) {
            return $url;
        }

        return $this->apply_base_transformation($url, $target_base);
    }

    private function detect_language($object): string {
        if (!is_object($object)) {
            frl_log(
                'Post Base Translation: Invalid object provided for language detection. Object type: {object_type}',
                ['object_type' => gettype($object)],
                true
            );
            return '';
        }

        if (isset($object->term_id) && isset($object->taxonomy)) {
            return frl_get_language($object->term_id, 'term');
        }

        if (isset($object->ID)) {
            return frl_get_language($object->ID, 'post');
        }

        return '';
    }

    private function should_transform_url(string $url): bool {
        if (empty(trim($url))) {
            return false;
        }
        if (str_contains($url, '/wp-admin/') || str_contains($url, '/wp-content/')) {
            return false;
        }
        return true;
    }

    private function apply_base_transformation(string $url, string $target_base): string {
        $parsed = $this->parse_url_components($url);
        $new_segments = $this->build_transformed_segments($parsed, $target_base);

        return Frl_Rewriter_Path_Utils::rebuild_url(
            $parsed['home_url'],
            $parsed['lang_prefix'],
            $new_segments
        );
    }

    private function build_transformed_segments(array $parsed, string $target_base): array {
        $segments = [$target_base];

        if ($parsed['has_category_in_structure']) {
            if (!empty($parsed['category_slug'])) {
                $segments[] = $parsed['category_slug'];
            }
            if (!empty($parsed['post_slug'])) {
                $segments[] = $parsed['post_slug'];
            }
        } else {
            if (!empty($parsed['post_slug'])) {
                $segments[] = $parsed['post_slug'];
            }
        }

        return $segments;
    }

    private function parse_url_components(string $url): array {
        $components = Frl_Rewriter_Path_Utils::parse_url_segments($url);
        $permalink_analysis = $this->analyze_permalink_structure();

        $parsed = [
            'home_url' => $components['home_url'],
            'lang_prefix' => $components['lang_prefix'],
            'segments' => $components['segments'],
            'has_category_in_structure' => $permalink_analysis['has_category'],
            'category_slug' => null,
            'post_slug' => null,
            'translated_base' => null
        ];

        if (!empty($components['segments'])) {
            $this->extract_url_parts($parsed, $components['segments']);
        }

        return $parsed;
    }

    private function extract_url_parts(array &$parsed, array $segments): void {
        $known_bases = array_values($this->mappings);
        $default_base = $this->extract_default_base();
        if (!empty($default_base)) {
            $known_bases[] = $default_base;
        }

        if (!empty($segments[0])) {
            foreach ($known_bases as $base) {
                if (!empty($base) && $segments[0] === $base) {
                    $parsed['translated_base'] = $base;
                    array_shift($segments);
                    break;
                }
            }
        }

        if ($parsed['has_category_in_structure'] && count($segments) >= 2) {
            $parsed['category_slug'] = $segments[0];
            $parsed['post_slug'] = $segments[1];
        } elseif (!$parsed['has_category_in_structure'] && count($segments) >= 1) {
            $parsed['post_slug'] = $segments[0];
        } elseif (count($segments) >= 1) {
            $parsed['post_slug'] = end($segments);
            if (count($segments) > 1) {
                $parsed['category_slug'] = $segments[0];
            }
        }
    }

    private function extract_default_base(): string {
        return Frl_Rewriter_Path_Utils::get_static_permalink_base();
    }

    private function analyze_permalink_structure(): array {
        $structure = Frl_Rewriter_Path_Utils::get_permalink_structure();
        return [
            'has_category' => str_contains($structure, '%category%'),
            'has_postname' => str_contains($structure, '%postname%'),
            'structure' => $structure,
        ];
    }

    public function redirect_canonical(): void {
        if (!is_singular('post')) {
            return;
        }

        $current_url = home_url(add_query_arg(null, null));
        $post = get_queried_object();
        if (!$post) {
            return;
        }
        $correct_url = get_permalink($post);

        // Preserve query string during redirect
        if (!empty($_GET)) {
            $correct_url = add_query_arg($_GET, $correct_url);
        }

        if (!empty($correct_url) && $correct_url !== $current_url) {
            wp_redirect($correct_url, 301);
            exit;
        }
    }

    /**
     * Filter callback to translate ##slug## placeholders for post bases.
     * Keeps this feature isolated: returns null when it does not handle the slug.
     *
     * @param string|null $link Existing link provided by previous filter (or null).
     * @param string      $slug Original slug from placeholder (e.g. "words").
     * @param string      $lang Target language code (e.g. "en", "it").
     * @return string|null The translated permalink or null to defer.
     */
    public function translate_base_permalink(?string $link, string $slug, string $lang): ?string {
        // If another filter has already provided a link, keep it.
        if ($link !== null) {
            return $link;
        }

        // Only handle slugs that match one of the configured post bases.
        if (!in_array($slug, $this->get_post_bases(), true)) {
            return null;
        }

        // Determine the base for the requested language. Fallback to the original slug.
        $target_base = $this->mappings[$lang] ?? $slug;

        // Build the URL respecting language prefixes.
        $default_lang = frl_get_default_language();
        $lang_prefix = ($lang !== $default_lang) ? "{$lang}/" : '';

        return trailingslashit(home_url("/{$lang_prefix}{$target_base}"));
    }
}
