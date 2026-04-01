<?php

/**
 * CPT→CPT Hierarchy Feature
 *
 * Nests a child CPT under the permalink path of a related parent CPT.
 * Example: /world/europe/italy/service-a/ where `service` relates to `jurisdiction`.
 *
 * Config via options (optional, with safe defaults):
 * - cpt_hierarchy_child_slug       (default: 'service')
 * - cpt_hierarchy_parent_slug      (default: 'jurisdiction')
 * - cpt_hierarchy_relation_meta    (default: 'jurisdiction')
 *
 * Independence:
 * - Applies only to the configured child CPT.
 * - Claims only URLs where last segment is a valid child post AND the prefix equals the related parent's full path.
 * - No cross-feature coupling. Priority must be higher than CPT base removal.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/abstract-base-feature.php';

class Frl_CPT_CPT_Hierarchy_Feature extends Frl_Rewriter_Feature_Base
{
    private string $child_cpt = 'service';
    private string $parent_cpt = 'jurisdiction';
    private string $relation_meta = 'jurisdiction';
    private bool $config_loaded = false;

    public function __construct()
    {
        // Load CPT/meta configuration after CPTs are registered
        frl_hook_add('action', 'init', [$this, 'load_config'], 20, 0);
        // Canonical redirect enforcement for child CPT
        frl_hook_add('action', 'template_redirect', [$this, 'maybe_redirect_canonical'], 2, 0);
    }

    public function get_name(): string
    {
        return 'CPT→CPT Hierarchy';
    }

    public function load_config(): void
    {
        if ($this->config_loaded) {
            return;
        }

        // Runtime option (primary source). Format: parent|child (one pair per line). Empty → disable.
        $option_value = frl_get_option('cpt_cpt_hierarchical');
        $used_runtime = false;

        if ($option_value !== null) {
            $option_value = (string) $option_value;
            if (trim($option_value) === '') {
                // Explicitly disabled via option → clear config and finish
                $this->parent_cpt = '';
                $this->child_cpt = '';
                $this->relation_meta = '';
                $this->config_loaded = true;
                return;
            }

            $pairs = frl_textlist_to_array($option_value);
            foreach ($pairs as $line) {
                $parent = isset($line[0]) ? trim((string) $line[0]) : '';
                $child  = isset($line[1]) ? trim((string) $line[1]) : '';
                if ($parent !== '' && $child !== '') {
                    $this->parent_cpt = sanitize_key($parent);
                    $this->child_cpt  = sanitize_key($child);
                    $used_runtime = true;
                    break;
                }
            }
            // If runtime option is provided but invalid, treat as disabled
            if (!$used_runtime) {
                $this->parent_cpt = '';
                $this->child_cpt = '';
                $this->relation_meta = '';
                $this->config_loaded = true;
                return;
            }
        }

        // Constant fallback ONLY when option is undefined (null)
        if (!$used_runtime && $option_value === null) {
            $const = defined('FRL_REWRITER_CPT_CPT_HIERARCHY') && is_array(FRL_REWRITER_CPT_CPT_HIERARCHY)
                ? FRL_REWRITER_CPT_CPT_HIERARCHY : [];
            $parent = isset($const['parent_cpt']) ? (string) $const['parent_cpt'] : '';
            $child  = isset($const['child_cpt']) ? (string) $const['child_cpt'] : '';
            if ($parent !== '' && $child !== '') {
                $this->parent_cpt = sanitize_key($parent);
                $this->child_cpt  = sanitize_key($child);
            } else {
                $this->parent_cpt = '';
                $this->child_cpt = '';
            }
        }

        // Default relation meta to parent CPT; allow override from constant
        $this->relation_meta = $this->parent_cpt !== '' ? $this->parent_cpt : '';
        if (defined('FRL_REWRITER_CPT_CPT_HIERARCHY') && is_array(FRL_REWRITER_CPT_CPT_HIERARCHY)) {
            $const_meta = FRL_REWRITER_CPT_CPT_HIERARCHY['relation_meta'] ?? '';
            if (is_string($const_meta) && $const_meta !== '') {
                $this->relation_meta = sanitize_key($const_meta);
            }
        }

        $this->config_loaded = true;
    }

    public function is_enabled(): bool
    {
        // Enable only when both CPTs are registered
        return post_type_exists($this->child_cpt) && post_type_exists($this->parent_cpt);
    }

    public function generate_rules(): array
    {
        // This feature uses request filtering; no static rules required
        return [];
    }

    public function applies_to_request(string $request_uri): bool
    {
        if (!$this->is_enabled()) {
            return false;
        }

        $path = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
        if ($path === '') {
            return false;
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        if (count($parts) < 2) {
            return false; // must have parent path + child slug
        }

        // Skip language prefix
        $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        if (!empty($parts) && in_array($parts[0], $langs, true)) {
            array_shift($parts);
        }
        if (count($parts) < 2) {
            return false;
        }

        $child_slug = (string) end($parts);
        if ($child_slug === '') {
            return false;
        }

        // Fast existence check for child CPT by slug
        $child_id = frl_get_cpt_id_by_slug($child_slug, $this->child_cpt);
        return $child_id > 0;
    }

    public function resolve_request(string $request_uri): array
    {
        if (!$this->is_enabled()) {
            return [];
        }

        $uri = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
        $parts = array_values(array_filter(explode('/', trim($uri, '/'))));
        if (empty($parts)) {
            return [];
        }

        // Detect and remove language prefix
        $lang = frl_get_default_language();
        $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        if (!empty($parts) && in_array($parts[0], $langs, true)) {
            $lang = array_shift($parts);
        }

        if (count($parts) < 2) {
            return [];
        }

        $child_slug = array_pop($parts);
        $prefix_path = implode('/', $parts);

        // Lookup child post by slug (non-hierarchical expected)
        static $child_cache = [];
        if (!isset($child_cache[$child_slug])) {
            $child_cache[$child_slug] = frl_cache_remember(
                'permalinks',
                'nested_cpt_child_' . md5($this->child_cpt . '|' . $child_slug),
                function () use ($child_slug) {
                    $id = frl_get_cpt_id_by_slug($child_slug, $this->child_cpt);
                    return $id > 0 ? (int) $id : 0;
                }
            );
            if (count($child_cache) > 2048) {
                $child_cache = [];
            }
        }
        $child_id = (int) $child_cache[$child_slug];
        if ($child_id <= 0) {
            return [];
        }

        // Get related parent ID (first item if array)
        $raw = get_post_meta($child_id, $this->relation_meta, true);
        $parent_id = 0;
        if (is_array($raw)) {
            $first = reset($raw);
            $parent_id = (int) $first;
        } else {
            $parent_id = (int) $raw;
        }
        if ($parent_id <= 0) {
            return [];
        }

        // Compute parent's canonical path
        $parent_url = get_permalink($parent_id);
        if (!$parent_url) {
            return [];
        }
        $parsed = wp_parse_url($parent_url);
        $parent_path = trim((string) ($parsed['path'] ?? ''), '/');

        // Remove language prefix for comparison
        if ($lang !== '' && str_starts_with($parent_path, $lang . '/')) {
            $parent_path = substr($parent_path, strlen($lang) + 1);
        }

        if ($parent_path !== $prefix_path) {
            return [];
        }

        // Build query vars for the child post
        $pt_obj = get_post_type_object($this->child_cpt);
        $query_var = !empty($pt_obj->query_var) ? $pt_obj->query_var : $this->child_cpt;

        $vars = [
            'p'         => $child_id,
            'post_type' => $this->child_cpt,
            'name'      => $child_slug,
            $query_var  => $child_slug,
        ];

        if (!empty($lang)) {
            $vars['lang'] = $lang;
        }

        return $vars;
    }

    public function applies_to($object): bool
    {
        return $this->is_enabled() && isset($object->post_type) && $object->post_type === $this->child_cpt;
    }

    public function transform(string $url, $object): string
    {
        if (!$this->applies_to($object) || !isset($object->ID)) {
            return $url;
        }

        // Get related parent
        $raw = get_post_meta((int) $object->ID, $this->relation_meta, true);
        $parent_id = 0;
        if (is_array($raw)) {
            $first = reset($raw);
            $parent_id = (int) $first;
        } else {
            $parent_id = (int) $raw;
        }
        if ($parent_id <= 0) {
            return $url; // no relationship → leave unchanged
        }

        $parent_url = get_permalink($parent_id);
        if (!$parent_url) {
            return $url;
        }

        // Build nested URL: parent path + child slug
        $nested = rtrim($parent_url, '/') . '/' . Frl_Rewriter_Path_Utils::get_post_slug($object) . '/';
        return Frl_Rewriter_Path_Utils::collapse_slashes($nested);
    }

    public function maybe_redirect_canonical(): void
    {
        if (!$this->is_enabled() || !is_singular($this->child_cpt)) {
            return;
        }

        $post = get_queried_object();
        if (!$post || !isset($post->ID)) {
            return;
        }

        $canonical = $this->transform(get_permalink($post), $post);
        if (!$canonical) {
            return;
        }
        Frl_Rewriter_Path_Utils::maybe_redirect_if_needed($canonical);
    }

    protected function get_feature_config_for_hash(): array
    {
        return [
            'child' => $this->child_cpt,
            'parent' => $this->parent_cpt,
            'meta' => $this->relation_meta,
        ];
    }
}
