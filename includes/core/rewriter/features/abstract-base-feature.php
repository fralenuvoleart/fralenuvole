<?php

/**
 * Abstract base class for all rewriter features
 *
 * @package Fralenuvole
 * @since 3.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/../class-rewriter-path-utils.php';
require_once __DIR__ . '/../interface-feature.php';

/**
 * Base class for independent rewriter features
 *
 * Each feature operates completely independently with its own:
 * - Configuration validation
 * - Rewrite rule generation
 * - URL request handling
 * - Priority assignment
 *
 * @package Fralenuvole
 * @since 3.0.0
 */

/**
 * DEVELOPER NOTICE: How to handle URL pattern conflicts
 * =======================================================
 *
 * If your feature introduces new, specific URL prefixes (e.g., 'news/' or 'en/events/'),
 * you MUST implement the `get_exclusion_patterns()` method.
 *
 * public function get_exclusion_patterns(): array {
 *     return ['news', 'en/events'];
 * }
 *
 * This prevents lower-priority "catch-all" features (like CPT Base Removal)
 * from incorrectly hijacking your feature's URLs. Your patterns will be automatically
 * escaped. This is critical for ensuring feature independence.
 */
abstract class Frl_Rewriter_Feature_Base implements Frl_Rewriter_Feature_Interface
{
    /**
     * Class-level static storage for add_rules() re-entrancy guard.
     * Using class-level static ensures the flag persists across all instances
     * and protects against double-registration if an exception is thrown before
     * the method-scoped flag would be set.
     *
     * @var array<string, bool>
     */
    private static array $rules_added = [];

    /**
     * Feature priority (lower number = higher priority)
     *
     * Priority ranges:
     * 15:    CPT Archive Base Translation
     * 25:    CPT Single Base Translation
     * 35:    Taxonomy Base Removal
     * 40:    CPT Base Removal
     * 50+:   WordPress Defaults (lowest priority)
     */
    final public function get_priority(): int
    {
        $class_name = get_class($this);
        if (isset(FRL_REWRITER_PRIORITIES[$class_name])) {
            return FRL_REWRITER_PRIORITIES[$class_name];
        }

        // Fallback for safety, though this should not be reached if config is correct
        return 99;
    }

    /**
     * Check if this feature is enabled via configuration
     *
     * @return bool True if the feature is enabled
     */
    abstract public function is_enabled(): bool;

    /**
     * Generate rewrite rules for this feature only
     *
     * @return array Associative array of pattern => rewrite pairs
     */
    abstract public function generate_rules(): array;

    /**
     * Check if this feature should handle the given request URI
     *
     * @param string $request_uri The raw request URI
     * @return bool True if this feature should handle the request
     */
    abstract public function applies_to_request(string $request_uri): bool;

    /**
     * Resolve the request URI to WordPress query variables
     *
     * @param string $request_uri The request URI to resolve
     * @return array WordPress query variables or empty array if not handled
     */
    abstract public function resolve_request(string $request_uri): array;

    /**
     * Check if this transformer applies to the given object.
     * (Optional: override in features that transform outgoing URLs)
     *
     * @param mixed $object The object to check
     * @return bool True if this transformer should process the object
     */
    public function applies_to($object): bool
    {
        return false; // Default: do not apply transformation
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
        return $url; // Default: return original URL
    }

    /**
     * Get a human-readable name for this feature (for logging/debugging)
     *
     * @return string The feature name
     */
    abstract public function get_name(): string;

    /**
     * Get the catch-all query variable name for this feature (if it uses catch-all)
     *
     * @return string Empty string if feature doesn't use catch-all
     */
    public function get_catch_all_query_var(): string
    {
        return ''; // Default: no catch-all support
    }

    /**
     * Check if this feature uses catch-all rules
     *
     * @return bool True if the feature uses catch-all rules
     */
    public function uses_catch_all(): bool
    {
        return !empty($this->get_catch_all_query_var());
    }

    /**
     * Register this feature with WordPress hooks
     *
     * This is called automatically by the coordinator
     *
     * @return void
     */
    final public function register(): void
    {
        // Re-entrancy guard
        static $registered_features = [];
        $feature_key = $this->get_name();
        if (isset($registered_features[$feature_key])) {
            return;
        }
        $registered_features[$feature_key] = true;

        // Offset priority so hooks run after features are registered (coordinator runs at init 15)
        $hook_priority = 100 + $this->get_priority();

        // Register rewrite rules and request filter
        add_action('init', [$this, 'add_rules'], $hook_priority, 0);
        add_filter('request', [$this, 'filter_request'], $hook_priority, 1);

        // Register independent catch-all mechanism if this feature uses it
        if ($this->uses_catch_all()) {
            add_action('init', [$this, 'add_catch_all_rules'], $hook_priority + 50, 0); // Lower priority than normal rules
            add_filter('request', [$this, 'filter_catch_all_request'], $hook_priority + 50, 1);
            add_filter('query_vars', [$this, 'add_catch_all_query_var'], 10, 1);
        }

        // Allow each feature to register its own additional hooks (config loader,
        // canonical redirects, etc.) without breaking the final register() contract.
        $this->register_additional_hooks();
    }

    /**
     * Register feature-specific WordPress hooks.
     *
     * Called by register() at WordPress init priority 15, after CPTs are registered
     * and after the core rule/request hooks have been added. Override this method
     * in concrete features instead of using __construct() for hook registration.
     * The constructor must remain reserved exclusively for property initialisation.
     *
     * @return void
     */
    protected function register_additional_hooks(): void
    {
        // Default: no additional hooks beyond what register() already wires.
    }

    /**
     * Add rewrite rules for this feature (called by WordPress init hook)
     *
     * @return void
     */
    final public function add_rules(): void
    {
        // Re-entrancy guard - use class-level static to persist across all instances
        // and protect against double-call if exception is thrown before flag is set
        $feature_key = $this->get_name();
        if (isset(self::$rules_added[$feature_key])) {
            return;
        }

        // Mark as registered BEFORE attempting to add rules, so any exception
        // path still prevents a second attempt on retry.
        self::$rules_added[$feature_key] = true;

        if (!$this->is_enabled()) {
            return;
        }

        $cache_key = 'rules_' . sanitize_key( str_replace(' ', '_', $this->get_name()) )
            . '_' . $this->get_priority() . '_' . $this->get_config_hash();

        $rules = frl_cache_remember('rewriter', $cache_key, function () {
            return $this->generate_rules();
        });

        // Detect duplicate patterns to prevent silent overrides
        $final_rules = [];
        foreach ($rules as $pattern => $rewrite) {
            if (isset($final_rules[$pattern])) {
                // Log once per duplicate
                frl_log('Rewriter duplicate pattern skipped in feature {feature}: {pattern}', [
                    'feature' => $this->get_name(),
                    'pattern' => $pattern,
                ], true);
                continue; // Skip duplicate to preserve first occurrence
            }
            $final_rules[$pattern] = $rewrite;
        }

        // Global duplicate-pattern guard across all features.
        // Keeps a static registry of patterns added during this request and skips later duplicates.
        static $global_patterns = [];
        foreach ($final_rules as $pattern => $rewrite) {
            if (isset($global_patterns[$pattern])) {
                // Log at most once per pattern per request to avoid noise in high-traffic.
                static $logged_patterns = [];
                if (!isset($logged_patterns[$pattern]) && (defined('FRL_REWRITER_LOG_DUPLICATES') ? FRL_REWRITER_LOG_DUPLICATES : true)) {
                    frl_log('Rewriter cross-feature duplicate pattern skipped: {pattern} (from {feature}, first defined by {first})', [
                        'pattern' => $pattern,
                        'feature' => $this->get_name(),
                        'first'   => $global_patterns[$pattern],
                    ], true);
                    $logged_patterns[$pattern] = true;
                }
                continue;
            }

            add_rewrite_rule($pattern, $rewrite, 'top');
            $global_patterns[$pattern] = $this->get_name();

            // Safety valve for extreme edge cases (e.g. CLI bulk generation).
            // Threshold is intentionally high: resetting clears the cross-feature
            // deduplication memory, so it must never happen in normal operation.
            if (count($global_patterns) > 50000) {
                frl_log('Rewriter: global_patterns guard exceeded 50 000 entries — resetting. Check for runaway rule generation.', [], true);
                $global_patterns = [];
            }
        }
    }

    /**
     * True when this feature is active and the current request is a public page request.
     * Use as the single early-exit guard in all request-filter callbacks so the two
     * conditions are never checked independently in different methods.
     *
     * @return bool True if active and valid page request
     */
    protected function is_active_page_request(): bool
    {
        return $this->is_enabled() && frl_is_valid_page_request();
    }

    /**
     * Filter WordPress request (called by request hook)
     *
     * @param array $query_vars The query variables to filter
     * @return array Filtered query variables
     */
    final public function filter_request(array $query_vars): array
    {
        if (!$this->is_active_page_request()) {
            return $query_vars;
        }

        // When the URL was matched by this feature's catch-all rule, the catch-all
        // query var is already set in $query_vars. filter_catch_all_request() owns
        // that resolution path. Returning here prevents a redundant DB lookup and
        // avoids leaving an ambiguous state (both catch-all var and resolved product
        // vars set simultaneously) visible to other plugins' request filters.
        if ($this->uses_catch_all() && isset($query_vars[$this->get_catch_all_query_var()])) {
            return $query_vars;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Re-entrancy guard for request filtering
        static $processing_requests = [];
        $feature_key = $this->get_name();
        if (isset($processing_requests[$feature_key])) {
            return $query_vars;
        }
        $processing_requests[$feature_key] = true;

        try {
            if ($this->applies_to_request($request_uri)) {
                $resolved = $this->resolve_request($request_uri);
                if (!empty($resolved)) {
                    unset($processing_requests[$feature_key]);
                    return array_merge($query_vars, $resolved);
                }
            }
        } catch (Throwable $e) {
            frl_log('Rewriter feature {feature} failed during request resolution: {error}', [
                'feature' => $this->get_name(),
                'error'   => $e->getMessage(),
            ]);
        }

        unset($processing_requests[$feature_key]);
        // Trim to max entries instead of hard-reset to preserve re-entrancy protection
        // Use array_slice for O(1) operation instead of O(n) array_shift loop
        if (count($processing_requests) > 256) {
            // Keep newest 50% entries
            $processing_requests = array_slice($processing_requests, -128, null, true);
        }

        return $query_vars;
    }

    /**
     * Validate that this feature's patterns don't conflict with others
     *
     * @param array $existing_patterns Patterns from other features
     * @return bool True if no conflicts
     * @throws Exception If patterns conflict
     */
    public function validate_patterns(array $existing_patterns): bool
    {
        $my_patterns = array_keys($this->generate_rules());

        foreach ($my_patterns as $my_pattern) {
            foreach ($existing_patterns as $existing_pattern) {
                if ($this->patterns_conflict($my_pattern, $existing_pattern)) {
                    throw new Exception(
                        "Pattern conflict in feature {$this->get_name()}: `{$my_pattern}` conflicts with `{$existing_pattern}`"
                    );
                }
            }
        }
        return true;
    }

    /**
     * Check if two regex patterns could match the same URL
     *
     * This is a simplified conflict detection with optimized pattern compilation
     *
     * @param string $p1 First pattern
     * @param string $p2 Second pattern
     * @return bool True if patterns conflict
     */
    protected function patterns_conflict(string $p1, string $p2): bool
    {
        // Optional fast-path – enabled when FRL_REWRITER_USE_FAST_CONFLICT is true
        $useFast = defined('FRL_REWRITER_USE_FAST_CONFLICT') && constant('FRL_REWRITER_USE_FAST_CONFLICT');
        if ($useFast) {
            if ($p1 === $p2) {
                return true;
            }

            $prefix1 = $this->extract_static_prefix($p1);
            $prefix2 = $this->extract_static_prefix($p2);

            if ($prefix1 !== '' && $prefix1 === $prefix2) {
                return true; // share identical literal prefix
            }
            // If prefixes differ we assume no conflict and skip expensive regex probes
            return false;
        }

        // Legacy exhaustive check (default)
        if ($p1 === $p2) {
            return true;
        }

        $delim = '!'; // unlikely delimiter

        $p1_valid = @preg_match("{$delim}{$p1}{$delim}", '') !== false;
        $p2_valid = @preg_match("{$delim}{$p2}{$delim}", '') !== false;

        if (!$p1_valid || !$p2_valid) {
            return true; // Invalidate bad patterns
        }

        $test_uris = [
            'test-slug',
            'test-slug/child-slug',
            'test-slug/page/2',
            'it/test-slug',
            'it/test-slug/child-slug',
        ];

        foreach ($test_uris as $uri) {
            $match1 = preg_match("{$delim}{$p1}{$delim}", $uri);
            $match2 = preg_match("{$delim}{$p2}{$delim}", $uri);

            if ($match1 && $match2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the initial literal prefix of a rewrite regex until the first meta-character.
     *
     * @param string $pattern The regex pattern
     * @return string The static prefix or empty string
     */
    private function extract_static_prefix(string $pattern): string
    {
        // Remove leading caret and delimiter if present
        if (str_starts_with($pattern, '^')) {
            $pattern = substr($pattern, 1);
        }

        $len     = strlen($pattern);
        $literal = '';
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            // Stop on regex meta characters (slash now considered literal)
            if (str_contains('*+?()[]{}|.^$\\', $c)) {
                break;
            }
            $literal .= $c;
        }

        return $literal;
    }

    /**
     * Add catch-all rewrite rules (for features that use catch-all)
     *
     * @return void
     */
    final public function add_catch_all_rules(): void
    {
        if (!$this->is_enabled() || !$this->uses_catch_all()) {
            return;
        }

        $query_var = $this->get_catch_all_query_var();
        $exclusion_patterns = $this->get_catch_all_exclusions();

        if (!empty($exclusion_patterns)) {
            // Allow features to contribute their own exclusion patterns (already regex-ready)
            $feature_exclusions = (array) $this->get_exclusion_patterns();
            if (!empty($feature_exclusions)) {
                $exclusion_patterns = array_merge($exclusion_patterns, $feature_exclusions);
            }

            // Normalize and de-duplicate without re-escaping to avoid double quoting
            $exclusion_patterns = array_values(array_unique(array_filter($exclusion_patterns)));

            // Cache the alternation string per-request to avoid repeated work
            static $alternation_cache = [];

            // Optional grouping: combine lang-prefixed patterns into (?:lang1|lang2)/(?:items)
            $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
            $lang_groups = [];
            $plain_items = [];
            if (!empty($langs)) {
                $lang_prefix_map = [];
                foreach ($langs as $lc) {
                    $lang_prefix_map[$lc] = [];
                }
                foreach ($exclusion_patterns as $pat) {
                    $matchedLang = null;
                    foreach ($langs as $lc) {
                        $prefix = $lc . '\\/'; // escaped '/'
                        if (str_starts_with($pat, $prefix)) {
                            $matchedLang = $lc;
                            $lang_prefix_map[$lc][] = substr($pat, strlen($prefix));
                            break;
                        }
                    }
                    if ($matchedLang === null) {
                        $plain_items[] = $pat;
                    }
                }
                foreach ($lang_prefix_map as $lc => $items) {
                    if (!empty($items)) {
                        $lang_groups[] = $lc . '\\/' . '(?:' . implode('|', array_values(array_unique($items))) . ')';
                    }
                }
            }

            $cache_input = [$exclusion_patterns, $lang_groups, $plain_items];
            $alt_key = md5(serialize($cache_input));
            if (!isset($alternation_cache[$alt_key])) {
                if (!empty($lang_groups)) {
                    $parts = array_merge($lang_groups, $plain_items);
                    $alternation_cache[$alt_key] = '(?:' . implode('|', $parts) . ')';
                } else {
                    $alternation_cache[$alt_key] = implode('|', $exclusion_patterns);
                }
                if (count($alternation_cache) > 1024) {
                    $alternation_cache = [];
                }
            }
            $excluded_pattern = $alternation_cache[$alt_key];

            // Pagination rule must be registered BEFORE the single-slug rule.
            // Both use 'top' priority so they are inserted into extra_rules_top in insertion order.
            // Apache/Nginx evaluate rules top-to-bottom; without this ordering the single rule
            // (.+?)/?$ matches slug/page/2 before the pagination rule ever fires.
            $pagination_rule_pattern = "^(?!(?:{$excluded_pattern})(?:/|$))(.+?)/page/?([0-9]{1,})/?$";
            add_rewrite_rule(
                $pagination_rule_pattern,
                "index.php?{$query_var}=\$matches[1]&paged=\$matches[2]",
                'top'
            );

            $rule_pattern = "^(?!(?:{$excluded_pattern})(?:/|$))(.+?)/?$";
            add_rewrite_rule(
                $rule_pattern,
                "index.php?{$query_var}=\$matches[1]",
                'top'
            );
        } else {
            // Simple catch-all when no exclusions needed.
            // Pagination rule first for the same ordering reason as above.
            add_rewrite_rule("(.+?)/page/?([0-9]{1,})/?$", "index.php?{$query_var}=\$matches[1]&paged=\$matches[2]", 'top');
            add_rewrite_rule("(.+?)/?$", "index.php?{$query_var}=\$matches[1]", 'top');
        }
    }

    /**
     * Filter catch-all requests (for features that use catch-all)
     *
     * @param array $query_vars The query variables to filter
     * @return array Filtered query variables
     */
    final public function filter_catch_all_request(array $query_vars): array
    {
        if (!$this->uses_catch_all() || !$this->is_active_page_request()) {
            return $query_vars;
        }

        $query_var = $this->get_catch_all_query_var();
        if (!isset($query_vars[$query_var])) {
            return $query_vars;
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($this->applies_to_request($request_uri)) {
            $resolved = $this->resolve_request($request_uri);
            if (!empty($resolved)) {
                unset($query_vars[$query_var]);
                return array_merge($query_vars, $resolved);
            }
        }

        // Catch-all matched but this feature cannot resolve it.
        // Remove our own query var to avoid hijacking the request and
        // set a safe fallback so WP can perform its normal page resolution/404.
        unset($query_vars[$query_var]);
        if (!isset($query_vars['pagename'])) {
            $path = Frl_Rewriter_Path_Utils::extract_request_path($request_uri);
            if ($path !== '') {
                $query_vars['pagename'] = $path;
            }
        }

        // Preserve other features' query vars
        return $query_vars;
    }

    /**
     * Add catch-all query variable to WordPress
     *
     * @param array $vars The query variables array
     * @return array Updated query variables
     */
    final public function add_catch_all_query_var(array $vars): array
    {
        if ($this->uses_catch_all()) {
            $vars[] = $this->get_catch_all_query_var();
        }
        return $vars;
    }

    /**
     * Get exclusion patterns for catch-all rules.
     * Override this method in features that need to exclude specific patterns.
     *
     * @return array Array of regex patterns to exclude from catch-all
     */
    protected function get_catch_all_exclusions(): array
    {
        // WordPress reserved slugs that should never be intercepted
        $patterns = [
            'wp-admin',
            'wp-content',
            'wp-includes',
            'wp-json',
            'feed',
            'rdf',
            'rss',
            'rss2',
            'atom',
            'trackback',
            'comments',
            'embed',
            'search',
            'sitemap',
            'robots\.txt',
            'favicon\.ico',
            'xmlrpc\.php',
            'wp-cron\.php',
            'wp-login\.php',
            'wp-signup\.php',
            'wp-activate\.php',
            'author',
            'archives',
            'page',
            'attachment'
        ];

        // Also exclude bare language roots (e.g., 'ru', 'ar', 'zh') so catch-all rules
        // never hijack language homepages like /ru/ handled by multilingual plugins.
        $langs = Frl_Rewriter_Path_Utils::get_active_languages_safe();
        foreach ($langs as $lc) {
            if (is_string($lc) && $lc !== '') {
                // Exclude only the bare language root (e.g., 'ru'), not all lang-prefixed paths (e.g., 'ru/…').
                // Uses escape_for_regex() (default '/' delimiter) for consistency with every other
                // exclusion pattern in the codebase. Functionally identical for alphanumeric lang codes.
                $patterns[] = Frl_Rewriter_Path_Utils::escape_for_regex($lc) . '$';
            }
        }

        return $patterns;
    }

    /**
     * Optional: Features can override to provide their own exclusion prefixes
     * to protect their specific URL spaces from catch-all hijacking.
     *
     * @return array Array of regex patterns to exclude
     */
    protected function get_exclusion_patterns(): array
    {
        return [];
    }

    /**
     * Get configuration option for this feature
     *
     * @param string $option_name The option name
     * @return string The option value or empty string
     */
    protected function get_option(string $option_name): string
    {
        return frl_get_option($option_name) ?? '';
    }

    /**
     * Parse a text list configuration into array format
     *
     * @param string $config The configuration string
     * @return array Parsed configuration array
     */
    protected function parse_config(string $config): array
    {
        return frl_textlist_to_array($config);
    }

    /**
     * Generate configuration hash for cache invalidation
     *
     * This ensures cache keys change when relevant configuration changes,
     * preventing stale cache issues without affecting current behavior.
     *
     * @return string MD5 hash of configuration data
     */
    protected function get_config_hash(): string
    {
        $config_data = [
            'class' => static::class,
            'priority' => $this->get_priority(),
        ];

        // Add feature-specific configuration data
        $config_data['feature_config'] = $this->get_feature_config_for_hash();

        // Add global configuration that affects this feature
        $config_data['global_config'] = [
            'remove_tax_base' => frl_get_option('remove_tax_base'),
            'remove_cpt_base' => frl_get_option('remove_cpt_base'),
        ];

        // Add CPT translation configs if applicable
        if (defined('FRL_REWRITER_MULTILINGUAL_CPT') && is_array(FRL_REWRITER_MULTILINGUAL_CPT)) {
            foreach (FRL_REWRITER_MULTILINGUAL_CPT as $cpt_slug) {
                $config_data['global_config']["translate_cpt_slugs_{$cpt_slug}"] = frl_get_option("translate_cpt_slugs_{$cpt_slug}");
            }
        }

        return md5(serialize($config_data));
    }

    /**
     * Get feature-specific configuration for cache hash
     * Override in child classes to include feature-specific options
     *
     * @return array Feature-specific configuration data
     */
    protected function get_feature_config_for_hash(): array
    {
        return [];
    }

    /**
     * Self-register this feature with the rewriter system
     * Call this method in feature files to enable auto-discovery
     *
     * @return void
     */
    final public function self_register(): void
    {
        add_action('frl_rewriter_register_features', function ($coordinator) {
            if ($coordinator instanceof Frl_Rewriter_Coordinator) {
                $coordinator->add_feature($this);
            }
        });
    }
}
