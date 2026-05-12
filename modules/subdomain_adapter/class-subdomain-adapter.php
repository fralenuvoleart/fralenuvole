<?php

/**
 * Subdomain Adapter — Bidirectional URL Transformer
 *
 * Maps subdomains to Polylang languages and transforms URLs between a main domain
 * and its language-specific subdomain mirrors.
 *
 * ## Configuration
 *
 * Fully data-driven from FRL_SUBDOMAIN_ADAPTER_MAP, a main-domain-keyed constant.
 * Each main domain entry maps language slugs to subdomain hosts, with a 'default'
 * key specifying the Polylang default language for that main domain.
 *
 * ## How it works
 *
 * ### On a main domain (e.g., pbservices.ge):
 * - Russian content URLs → `ru.pbservices.ge/post-slug/` 
 * - Default language (EN) content URLs → unchanged (no subdomain)
 * - Other languages without subdomain mapping → unchanged
 *
 * ### On a mapped subdomain (e.g., ru.pbservices.ge):
 * - The `pll_default_language` filter (p1) tells Polylang that RU is the default
 *   language. Polylang naturally hides the language prefix for the default language,
 *   generating clean URLs: `ru.pbservices.ge/post-slug/` — zero str_replace needed.
 * - Cross-language content (EN, IT, AR) → swapped to primary main domain with correct prefix
 * - `template_redirect` (p5) 301-redirects non-target-language content to main domain
 *
 * ### Extensibility
 * Add new main domain entries in FRL_SUBDOMAIN_ADAPTER_MAP — zero class code changes needed.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Frl_Subdomain_Adapter
 */
class Frl_Subdomain_Adapter {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    /**
     * Fallback language slug used when a main domain entry is missing the
     * required 'default_lang' key in FRL_SUBDOMAIN_ADAPTER_MAP.
     *
     * @var string
     */
    private const FALLBACK_LANG = 'en';

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static ?self $instance = null;

    // -------------------------------------------------------------------------
    // Configuration (set once from constants in detect())
    // -------------------------------------------------------------------------

    /**
     * Main-domain-keyed config: main_domain => { lang => subdomain, 'default' => lang }.
     *
     * @var array<string, array<string, string>>
     */
    private array $domain_map = [];

    /**
     * Reverse index: subdomain_host => { lang, default_lang, main_domains[] }.
     *
     * @var array<string, array{lang: string, default_lang: string, main_domains: string[]}>
     */
    private array $subdomain_info = [];

    /** @var array<string, true> Flat set of known subdomain hosts for O(1) detection. */
    private array $subdomain_hosts = [];

    // -------------------------------------------------------------------------
    // Runtime state (set in detect(), O(1) reads thereafter)
    // -------------------------------------------------------------------------

    /** @var string Current HTTP_HOST from $_SERVER. */
    private string $current_host = '';

    /**
     * Language of the current subdomain if we are on a mapped subdomain, null otherwise.
     *
     * @var string|null
     */
    private ?string $current_subdomain_lang = null;

    /**
     * The subdomain host key from the map if we are on a mapped subdomain, null otherwise.
     *
     * @var string|null
     */
    private ?string $current_subdomain_host = null;

    /**
     * Whether the current host is a recognized main_domain from the map.
     *
     * @var bool
     */
    private bool $is_on_main_domain = false;

    // -------------------------------------------------------------------------
    // Static Factory
    // -------------------------------------------------------------------------

    /**
     * Initialize the singleton.
     *
     * Calls detect() and registers hooks only when on a configured domain
     * (main_domain or subdomain) and the translation system is available.
     *
     * @return self
     */
    public static function init(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->detect();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {}

    // -------------------------------------------------------------------------
    // Detection
    // -------------------------------------------------------------------------

    /**
     * Detect the current environment from HTTP_HOST and build internal indexes.
     *
     * Runs once per request (guarded by singleton construction). Sets runtime
     * state properties for O(1) checks in filters.
     *
     * Builds two internal structures from the main-domain-keyed config:
     *  - $subdomain_info: reverse index subdomain_host => { lang, default_lang, main_domains[] }
     *  - $subdomain_hosts: flat set for O(1) subdomain detection
     *
     * @return void
     */
    private function detect(): void {
        // Static cache: reverse indices are built once per request from the
        // constant. For small maps (≤5 domains × ≤5 languages) the cost is
        // negligible, but the guard avoids even that trivial work on repeated
        // singleton access within the same request.
        static $built = false;
        if ($built) {
            return;
        }

        // Load config.
        $this->domain_map = defined('FRL_SUBDOMAIN_ADAPTER_MAP')
            ? (array) FRL_SUBDOMAIN_ADAPTER_MAP : [];

        if (empty($this->domain_map) && defined('WP_DEBUG') && WP_DEBUG) {
            frl_log('Subdomain Adapter: FRL_SUBDOMAIN_ADAPTER_MAP not defined or empty');
        }

        // Validate configuration: ensure every main domain entry has a default_lang key.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            foreach ($this->domain_map as $main_domain => $config) {
                if (!isset($config['default_lang'])) {
                    frl_log('Subdomain Adapter: Configuration error — main domain {domain} is missing the required "default_lang" key', [
                        'domain' => $main_domain,
                    ]);
                }
            }
        }

        // Build reverse index: subdomain_host => { lang, default_lang, main_domains[] }.
        // Also build flat set of subdomain hosts for O(1) subdomain detection.
        $this->subdomain_info  = [];
        $this->subdomain_hosts = [];

        foreach ($this->domain_map as $main_domain => $config) {
            $default_lang = isset($config['default_lang']) ? (string) $config['default_lang'] : self::FALLBACK_LANG;
            foreach ($config as $lang => $subdomain) {
                if ($lang === 'default_lang') {
                    continue;
                }
                $subdomain = (string) $subdomain;
                if ($subdomain === '') {
                    continue;
                }
                $this->subdomain_hosts[$subdomain] = true;
                if (!isset($this->subdomain_info[$subdomain])) {
                    $this->subdomain_info[$subdomain] = [
                        'lang'         => $lang,
                        'default_lang' => $default_lang,
                        'main_domains' => [],
                    ];
                } elseif ($this->subdomain_info[$subdomain]['default_lang'] !== $default_lang) {
                    // Collision: same subdomain registered with different default_lang
                    // values from different main domains. The first registration wins.
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        frl_log('Subdomain Adapter: default_lang collision for subdomain {subdomain} — using {first}, ignoring {second}', [
                            'subdomain' => $subdomain,
                            'first'     => $this->subdomain_info[$subdomain]['default_lang'],
                            'second'    => $default_lang,
                        ]);
                    }
                }
                $this->subdomain_info[$subdomain]['main_domains'][] = $main_domain;
            }
        }

        $built = true;

        // Read current host.
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $host = strtolower(trim($host));

        // Strip port if present.
        if (($colon = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $colon);
        }

        $this->current_host = $host;

        // Check if we are on a mapped subdomain — O(1) via flat set.
        if (isset($this->subdomain_hosts[$host])) {
            $this->current_subdomain_host = $host;
            $this->current_subdomain_lang = $this->subdomain_info[$host]['lang'];
            $this->is_on_main_domain      = false;
            return;
        }

        // Check if we are on a recognized main domain — single O(1) lookup.
        $this->is_on_main_domain = isset($this->domain_map[$host]);
    }

    // -------------------------------------------------------------------------
    // Public Query Methods
    // -------------------------------------------------------------------------

    /**
     * Whether the domain map is non-empty (module is configured).
     *
     * @return bool
     */
    public function is_configured(): bool {
        return !empty($this->domain_map);
    }

    /**
     * Whether the current request is on a mapped subdomain.
     *
     * @return bool
     */
    public function is_on_subdomain(): bool {
        return $this->current_subdomain_host !== null;
    }

    /**
     * Whether the current request is on a recognized main domain.
     *
     * @return bool
     */
    public function is_on_main_domain(): bool {
        return $this->is_on_main_domain;
    }

    // -------------------------------------------------------------------------
    // Hook Registration (lazy — only when on a configured domain)
    // -------------------------------------------------------------------------

    /**
     * Register WordPress and Polylang hooks.
     *
     * Skips registration entirely if:
     * - Module is not configured (empty map)
     * - Not on a recognized main_domain or subdomain
     * - Hooks were already registered (re-entrancy guard)
     *
     * @return void
     */
    private function register_hooks(): void {
        // Early exit: not on a configured domain.
        if (!$this->is_configured()) {
            return;
        }

        if (!$this->is_on_main_domain && !$this->is_on_subdomain()) {
            return;
        }

        // Re-entrancy guard.
        if (frl_is_already_running(__CLASS__ . '::register_hooks')) {
            return;
        }

        // --- Polylang default language switch (Key mechanism) ---
        // Priority 1: runs before Polylang's internal setup so the subdomain's
        // language is treated as default from the start.
        add_filter('pll_default_language', [$this, 'filter_pll_default_language'], 1, 1);

        // --- Polylang current language safety net ---
        // Priority 2: ensures Polylang's current language matches the subdomain.
        add_filter('pll_current_language', [$this, 'filter_pll_current_language'], 2, 1);

        // --- Home URL filter ---
        // Priority 20: returns correct home URL for mapped languages.
        add_filter('pll_get_home_url', [$this, 'filter_pll_get_home_url'], 20, 2);

        // --- WordPress home_url override on subdomains ---
        // Priority 20: makes home_url() return the correct subdomain URL.
        add_filter('home_url', [$this, 'filter_home_url'], 20, 4);

        // --- URL transformation filters (priority 20 — after rewriter at p10) ---
        add_filter('post_link',             [$this, 'filter_post_link'],        20, 2);
        add_filter('post_type_link',        [$this, 'filter_post_type_link'],   20, 2);
        add_filter('page_link',             [$this, 'filter_page_link'],        21, 2);
        add_filter('term_link',             [$this, 'filter_term_link'],        20, 3);
        add_filter('wpseo_canonical',       [$this, 'filter_canonical_url'],    20, 1);
        add_filter('the_seo_framework_meta_render_data', [$this, 'filter_tsf_canonical_url'], 20, 1);

        // --- Template redirect: 301 non-target content on subdomain ---
        add_action('template_redirect',     [$this, 'redirect_non_target_content'], 5);
    }

    // -------------------------------------------------------------------------
    // Filter: pll_default_language (Key Mechanism)
    // -------------------------------------------------------------------------

    /**
     * Override Polylang's default language on mapped subdomains.
     *
     * When on `ru.pbservices.ge`, tells Polylang that RU is the default language.
     * Polylang then naturally hides the language prefix for RU, generating clean
     * URLs like `ru.pbservices.ge/post-slug/` without any str_replace.
     *
     * This makes target-language URLs **zero-cost** on the subdomain.
     *
     * @param  string|false $lang The current Polylang default language slug.
     * @return string
     */
    // No return type declaration: Polylang may pass false instead of a string.
    public function filter_pll_default_language($lang) {
        if ($this->is_on_subdomain()) {
            return $this->current_subdomain_lang;
        }
        return is_string($lang) ? $lang : '';
    }

    // -------------------------------------------------------------------------
    // Filter: pll_current_language (Safety Net)
    // -------------------------------------------------------------------------

    /**
     * Force Polylang's current language on mapped subdomains.
     *
     * Safety net: ensures that on the subdomain, Polylang's current language
     * always matches the subdomain's language. Prevents edge cases where
     * Polylang might detect a different language (e.g., from URL or browser).
     *
     * @param  string|false $lang The current Polylang language slug.
     * @return string
     */
    // No return type declaration: Polylang may pass false instead of a string.
    public function filter_pll_current_language($lang) {
        if ($this->is_on_subdomain()) {
            return $this->current_subdomain_lang;
        }
        return is_string($lang) ? $lang : '';
    }

    // -------------------------------------------------------------------------
    // Filter: pll_get_home_url
    // -------------------------------------------------------------------------

    /**
     * Return the correct home URL for mapped languages.
     *
     * Handles hreflang tags and language switcher URLs in both directions:
     * - On main domain: `pll_home_url('ru')` → `https://ru.pbservices.ge/`
     * - On subdomain: `pll_home_url('ru')` → `https://ru.pbservices.ge/`
     * - On subdomain: `pll_home_url('en')` → `https://pbservices.ge/` (default, no prefix)
     * - On subdomain: `pll_home_url('it')` → `https://pbservices.ge/it/` (has prefix)
     *
     * @param  string $url  The home URL Polylang computed.
     * @param  string $lang The language slug.
     * @return string
     */
    public function filter_pll_get_home_url($url, $lang): string {
        // Validate: if the language is not recognized, return the original URL unchanged.
        if (!in_array($lang, frl_get_active_languages(), true)) {
            return is_string($url) ? $url : '';
        }

        // Determine which main domain to use for resolution.
        if ($this->is_on_subdomain()) {
            if (!isset($this->subdomain_info[$this->current_subdomain_host])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    frl_log('Subdomain Adapter: Missing subdomain info in home_url filter for host {host}', [
                        'host' => $this->current_subdomain_host,
                    ]);
                }
                return is_string($url) ? $url : '';
            }
            $resolve_domain = $this->subdomain_info[$this->current_subdomain_host]['main_domains'][0];
        } elseif ($this->is_on_main_domain) {
            $resolve_domain = $this->current_host;
        } else {
            return is_string($url) ? $url : '';
        }

        $scheme = $this->get_scheme();

        // If this language has a mapped subdomain → return subdomain URL.
        if (isset($this->domain_map[$resolve_domain][$lang])
            && $this->domain_map[$resolve_domain][$lang] !== ''
        ) {
            return "{$scheme}://" . $this->domain_map[$resolve_domain][$lang] . '/';
        }

        // Return main domain URL, with or without prefix.
        $main_default = $this->domain_map[$resolve_domain]['default_lang'] ?? self::FALLBACK_LANG;
        if ((string) $lang === $main_default) {
            return "{$scheme}://{$resolve_domain}/";
        }
        return "{$scheme}://{$resolve_domain}/{$lang}/";
    }

    /**
     * Get the current request scheme.
     *
     * @return string 'https' or 'http'.
     */
    private function get_scheme(): string {
        return is_ssl() ? 'https' : 'http';
    }

    /**
     * Override home_url on mapped subdomains.
     *
     * Makes WordPress core functions (home_url, get_home_url) return
     * the correct subdomain URL instead of the main domain.
     *
     * $orig_scheme is intentionally ignored — is_ssl() is used instead
     * for dynamic protocol detection rather than trusting the passed value.
     *
     * @param  string      $url         The complete home URL.
     * @param  string      $path        Path relative to the home URL.
     * @param  string|null $orig_scheme Scheme for the home URL (unused, see above).
     * @param  int|null    $blog_id     Blog ID.
     * @return string
     */
    public function filter_home_url($url, $path, $orig_scheme, $blog_id): string {
        if (!$this->is_on_subdomain()) {
            return $url;
        }
        $scheme = $this->get_scheme();
        return "{$scheme}://{$this->current_subdomain_host}/" . ltrim($path, '/');
    }

    // -------------------------------------------------------------------------
    // URL Filters (post_link, post_type_link, page_link, term_link, canonical)
    // -------------------------------------------------------------------------

    /**
     * Guard pattern used by all URL filters.
     *
     * Intentionally does NOT exclude is_robots() or is_feed():
     * - robots.txt: each subdomain should have its own robots.txt pointing to its
     *   own sitemap (industry best practice for subdomain-based multilingual sites).
     * - Feeds: post URLs in feeds should point to the correct canonical domain,
     *   consistent with the plugin's goal of serving content on the right domain.
     *
     * @return bool True if URL transformation should proceed.
     */
    private function should_transform(): bool {
        if (frl_is_admin() || frl_is_rest_api_request() || is_preview() || frl_is_cron_job_request()) {
            return false;
        }
        if (!$this->is_configured()) {
            return false;
        }
        if (!frl_translator_is_enabled()) {
            return false;
        }
        return true;
    }

    /**
     * Shared logic for post_link, post_type_link, and page_link filters.
     *
     * @param  string   $link The permalink.
     * @param  \WP_Post $post The post object.
     * @return string
     */
    private function filter_post_link_internal(string $link, \WP_Post $post): string {
        if (!$this->should_transform()) {
            return $link;
        }
        $content_lang = frl_get_language($post->ID);
        if (empty($content_lang)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                frl_log('Subdomain Adapter: Empty content language for post {id} (type: {type}) — skipping URL transformation', [
                    'id'   => $post->ID,
                    'type' => $post->post_type,
                ]);
            }
            return $link;
        }
        return $this->transform_url($link, $content_lang);
    }

    /**
     * Filter: post_link
     *
     * @param  string   $link The post permalink.
     * @param  \WP_Post $post The post object.
     * @return string
     */
    public function filter_post_link(string $link, $post): string {
        if (!$post instanceof \WP_Post) {
            return $link;
        }
        return $this->filter_post_link_internal($link, $post);
    }

    /**
     * Filter: post_type_link
     *
     * @param  string   $link The CPT permalink.
     * @param  \WP_Post $post The post object.
     * @return string
     */
    public function filter_post_type_link(string $link, $post): string {
        if (!$post instanceof \WP_Post) {
            return $link;
        }
        return $this->filter_post_link_internal($link, $post);
    }

    /**
     * Filter: page_link
     *
     * @param  string   $link The page permalink.
     * @param  \WP_Post $post The page object.
     * @return string
     */
    public function filter_page_link(string $link, $post): string {
        // WordPress page_link filter passes $post->ID (int), not the WP_Post object.
        // Normalize to WP_Post so the shared internal method works correctly.
        if (is_numeric($post)) {
            $post = get_post((int) $post);
        }
        if (!$post instanceof \WP_Post) {
            return $link;
        }
        return $this->filter_post_link_internal($link, $post);
    }

    /**
     * Filter: term_link
     *
     * @param  string  $link     The term link.
     * @param  \WP_Term $term    The term object.
     * @param  string   $taxonomy The taxonomy slug.
     * @return string
     */
    public function filter_term_link(string $link, $term, string $taxonomy = ''): string {
        if (!$this->should_transform()) {
            return $link;
        }
        if (!$term instanceof \WP_Term) {
            return $link;
        }

        $content_lang = frl_get_language($term->term_id, 'term');
        if (empty($content_lang)) {
            return $link;
        }

        return $this->transform_url($link, $content_lang);
    }

    /**
     * Filter: wpseo_canonical
     *
     * @param  string|false $url The canonical URL.
     * @return string
     */
    // No return type declaration: Yoast SEO may pass false instead of a string.
    public function filter_canonical_url($url) {
        if (!$this->should_transform()) {
            return is_string($url) ? $url : '';
        }

        $content_lang = frl_get_language();
        if (empty($content_lang)) {
            return is_string($url) ? $url : '';
        }

        return $this->transform_url((string) $url, $content_lang);
    }

    /**
     * Filter: the_seo_framework_meta_render_data
     *
     * Transforms the canonical URL in The SEO Framework's render data array.
     * TSF v5.0+ uses this filter instead of WordPress core's get_canonical_url.
     *
     * @param  array $render_data TSF meta tag render data keyed by tag type.
     * @return array
     */
    public function filter_tsf_canonical_url(array $render_data): array {
        if (!$this->should_transform()) {
            return $render_data;
        }

        if (!isset($render_data['canonical']['attributes']['href'])) {
            return $render_data;
        }

        $content_lang = frl_get_language();
        if (empty($content_lang)) {
            return $render_data;
        }

        $render_data['canonical']['attributes']['href'] = $this->transform_url(
            $render_data['canonical']['attributes']['href'],
            $content_lang
        );

        return $render_data;
    }

    // -------------------------------------------------------------------------
    // Core Transformation Logic
    // -------------------------------------------------------------------------

    /**
     * Transform a URL between main domain and subdomain based on content language.
     *
     * Four cases handled:
     *
     * 1. **Main domain + default language** → No-op.
     *    Default language has no prefix on main, stays on main.
     *
     * 2. **Main domain + mapped language** → Swap domain, strip prefix.
     *    `pbservices.ge/ru/post/` → `ru.pbservices.ge/post/`
     *    Uses $this->current_host as source — works for any recognized main domain
     *    (production, staging, etc.) without hard-coding.
     *
     * 3. **Subdomain + target language** → No-op.
     *    `pll_default_language` filter makes Polylang generate clean URLs.
     *
     * 4. **Subdomain + cross language** → Strip prefix, swap domain, add prefix if needed.
     *    `ru.pbservices.ge/en/post/` → `pbservices.ge/post/` (EN default, no prefix)
     *    `ru.pbservices.ge/it/post/` → `pbservices.ge/it/post/` (IT has prefix)
     *    Uses the primary main domain (first registered) as the target.
     *
     * @param  string $url          The full URL to transform.
     * @param  string $content_lang The language slug of the content.
     * @return string
     */
    private function transform_url(string $url, string $content_lang): string {
        // Determine target subdomain and default language.
        if ($this->is_on_subdomain()) {
            // Case 3: Content matches subdomain's language → no-op (done early
            // before URL parsing and array lookups to avoid unnecessary work).
            if ($content_lang === $this->current_subdomain_lang) {
                return $url;
            }

            if (!isset($this->subdomain_info[$this->current_subdomain_host])) {
                return $url;
            }
            $info = $this->subdomain_info[$this->current_subdomain_host];
            $main_default = $info['default_lang'];
            $primary_main = $info['main_domains'][0];
        } else {
            $lang_map = $this->domain_map[$this->current_host] ?? [];
            $target_subdomain  = isset($lang_map[$content_lang])
                ? $lang_map[$content_lang] : null;
            $main_default      = $lang_map['default_lang'] ?? self::FALLBACK_LANG;

            // On main domain, only transform if language has a mapped subdomain.
            // Cross-language content on subdomains (Case 4) is handled below
            // and does NOT require a mapped subdomain.
            if ($target_subdomain === null) {
                return $url;
            }
        }

        // Static result cache: avoid re-computing the full transformation when
        // transform_url() is called multiple times for the same content in one
        // request (e.g., post_link + wpseo_canonical for the same post).
        static $transform_cache = [];
        $cache_key = $url . '|' . $content_lang;
        if (isset($transform_cache[$cache_key])) {
            return $transform_cache[$cache_key];
        }

        // Static cache: avoid re-parsing the same URL when transform_url()
        // is called multiple times for the same content in one request
        // (e.g., post_link + wpseo_canonical for the same post).
        static $parsed_cache = [];
        if (!isset($parsed_cache[$url])) {
            $parsed_cache[$url] = wp_parse_url($url);
        }
        $parsed = $parsed_cache[$url];

        if (empty($parsed['host'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                frl_log('Subdomain Adapter: Failed to parse URL {url}', [
                    'url' => $url,
                ]);
            }
            return $transform_cache[$cache_key] = $url;
        }

        $scheme   = $parsed['scheme'] ?? 'https';
        $path     = $parsed['path'] ?? '/';
        $query    = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        // --- Case 1 & 2: ON MAIN DOMAIN ---
        if (!$this->is_on_subdomain()) {
            // Case 1: Default language on main → no-op.
            if ($content_lang === $main_default) {
                return $transform_cache[$cache_key] = $url;
            }
            // Case 2: Mapped language on main → swap domain, strip prefix.
            // Use case-insensitive comparison: external links may have mixed-case
            // path segments (e.g., /RU/post-slug/).
            $path_lower = strtolower($path);
            $prefix     = '/' . strtolower($content_lang) . '/';
            if (str_starts_with($path_lower, $prefix)) {
                $path = '/' . substr($path, strlen($prefix));
            }
            return $transform_cache[$cache_key] = "{$scheme}://{$target_subdomain}{$path}{$query}{$fragment}";
        }

        // --- ON SUBDOMAIN ---
        // Case 4: Cross-language content on subdomain → swap to primary main domain.
        // Languages without a mapped subdomain (e.g., IT, AR) are also handled:
        // their prefix is stripped and they're placed on the primary main domain.
        // Use case-insensitive comparison: external links may have mixed-case
        // path segments (e.g., /RU/post-slug/).
        $path_lower = strtolower($path);
        $prefix     = '/' . strtolower($content_lang) . '/';
        if (str_starts_with($path_lower, $prefix)) {
            $path = '/' . substr($path, strlen($prefix));
        }

        if ($content_lang !== $main_default) {
            $path = '/' . $content_lang . $path;
        }

        return $transform_cache[$cache_key] = "{$scheme}://{$primary_main}{$path}{$query}{$fragment}";
    }

    // -------------------------------------------------------------------------
    // Template Redirect: 301 non-target content on subdomain
    // -------------------------------------------------------------------------

    /**
     * On subdomain, 301-redirect non-target-language content to the primary main domain.
     *
     * Handles WP_Post, WP_Term, and queries with no queried object (author archives,
     * date archives, post type archives). 404 errors are left to render natively
     * on the subdomain — they are not redirected.
     *
     * @return void
     */
    public function redirect_non_target_content(): void {
        if (!$this->is_on_subdomain()) {
            return;
        }
        if (is_404()) {
            return; // 404s render locally on the subdomain.
        }
        if (!frl_translator_is_enabled()) {
            return;
        }

        if (!isset($this->subdomain_info[$this->current_subdomain_host])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                frl_log('Subdomain Adapter: Missing subdomain info in redirect for host {host}', [
                    'host' => $this->current_subdomain_host,
                ]);
            }
            return;
        }

        $obj = get_queried_object();

        if ($obj instanceof \WP_Post) {
            $post_lang = frl_get_language($obj->ID);
            if ($post_lang && $post_lang !== $this->current_subdomain_lang) {
                $redirect_url = $this->transform_url(
                    home_url($this->get_request_uri()),
                    $post_lang
                );
                add_filter('x_redirect_by', [self::class, 'get_redirect_by'], 999);
                wp_redirect($redirect_url, 301);
                exit;
            }
        }

        if ($obj instanceof \WP_Term) {
            $term_lang = frl_get_language($obj->term_id, 'term');
            if ($term_lang && $term_lang !== $this->current_subdomain_lang) {
                $redirect_url = $this->transform_url(
                    home_url($this->get_request_uri()),
                    $term_lang
                );
                add_filter('x_redirect_by', [self::class, 'get_redirect_by'], 999);
                wp_redirect($redirect_url, 301);
                exit;
            }
        }
    }

    /**
     * Safely get the current request URI, stripped of null bytes and control characters.
     *
     * @return string
     */
    private function get_request_uri(): string {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        return preg_replace('/[\x00-\x1F\x7F]/', '', $uri);
    }

    /**
     * Returns the redirect-by header value for wp_redirect().
     *
     * @return string
     */
    public static function get_redirect_by(): string {
        return 'Frl_Subdomain_Adapter';
    }
}
