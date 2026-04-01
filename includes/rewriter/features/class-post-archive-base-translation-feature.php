<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/abstract-base-feature.php';

/**
 * Post Archive URL Transformation Feature.
 *
 * This feature's SOLE responsibility is to prepend a translated post base
 * to outgoing category archive URLs. It does NOT generate rewrite rules
 * or handle incoming requests. It relies on the priority system to act
 * before the Taxonomy Base Removal feature.
 *
 * Example:
 * 1. Post Archive Feature (Priority 10) receives: /category/my-cat/
 *    It transforms it to: /news/category/my-cat/
 * 2. Taxonomy Base Removal Feature (Priority 35) receives: /news/category/my-cat/
 *    It transforms it to: /news/my-cat/
 */
class Frl_Post_Archive_Base_Translation_Feature extends Frl_Rewriter_Feature_Base
{
    private array $mappings = [];
    private bool $has_category_structure = false;

    public function __construct()
    {
        // Configuration must be loaded on the 'init' hook. This is critical because
        // permalink structures are not available before this point.
        // The callback method must be public to be accessible by the hook.
        frl_hook_add('action', 'init', [$this, 'load_configuration'], 20, 0);
    }

    public function load_configuration(): void
    {
        // Cache the entire configuration parsing for performance
        $cache_key = 'post_archive_config';
        $cached_config = frl_cache_remember('rewriter', $cache_key, function () {
            return [
                'mappings'     => Frl_Rewriter_Path_Utils::parse_lang_mapping_option('translate_post_base'),
                'has_category' => Frl_Rewriter_Path_Utils::has_category_structure(),
            ];
        });

        $this->mappings = $cached_config['mappings'];
        $this->has_category_structure = $cached_config['has_category'];
    }

    public function get_name(): string
    {
        return 'Post Archive URL Transformation';
    }

    public function is_enabled(): bool
    {
        return !empty($this->mappings) && $this->has_category_structure;
    }

    /**
     * This feature does not generate rewrite rules.
     */
    public function generate_rules(): array
    {
        return [];
    }

    /**
     * This feature does not handle incoming requests.
     */
    public function applies_to_request(string $request_uri): bool
    {
        return false;
    }

    /**
     * This feature does not resolve incoming requests.
     */
    public function resolve_request(string $request_uri): array
    {
        return [];
    }

    /**
     * Check if this transformer applies to the given object.
     * We only care about category term objects.
     */
    public function applies_to($object): bool
    {
        if (!$this->is_enabled()) {
            return false;
        }

        return is_object($object) && isset($object->taxonomy) && $object->taxonomy === 'category';
    }

    /**
     * Transform category term archive links by prepending the translated post base.
     */
    public function transform(string $url, $object): string
    {
        if (!$this->applies_to($object)) {
            return $url;
        }

        $lang = frl_get_language($object->term_id, 'term');
        if (empty($lang) || empty($this->mappings[$lang])) {
            return $url;
        }

        $target_base = $this->mappings[$lang];
        $category_base = get_option('category_base') ?: 'category';

        // Robust, segment-aware injection: insert translated base directly before the category segment
        $parsed = Frl_Rewriter_Path_Utils::parse_url_segments($url);
        $segments = $parsed['segments'];

        if (empty($segments)) {
            return $url;
        }

        $idx = array_search($category_base, $segments, true);
        if ($idx === false) {
            // No explicit category base segment found; keep URL unchanged to avoid regressions
            return $url;
        }

        // Avoid duplicate insertion when already integrated
        if ($idx > 0 && $segments[$idx - 1] === $target_base) {
            return $url;
        }

        array_splice($segments, $idx, 0, $target_base);

        $new_url = Frl_Rewriter_Path_Utils::rebuild_url(
            $parsed['home_url'],
            $parsed['lang_prefix'],
            $segments
        );

        return Frl_Rewriter_Path_Utils::collapse_slashes($new_url);
    }
}
