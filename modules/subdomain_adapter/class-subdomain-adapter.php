<?php

/**
 * Subdomain Adapter — Bidirectional URL Transformer
 *
 * Maps subdomains to Polylang languages and transforms URLs between a main domain
 * and its language-specific subdomain mirrors.
 *
 * ## How it works
 *
 * ### On the main domain (e.g., pbservices.ge):
 * - Russian content URLs → `ru.pbservices.ge/post-slug/` 
 * - Default language (EN) content URLs → unchanged (no subdomain)
 * - Other languages without subdomain mapping → unchanged
 *
 * ### On a mapped subdomain (e.g., ru.pbservices.ge):
 * - The `pll_default_language` filter (p1) tells Polylang that RU is the default
 *   language. Polylang naturally hides the language prefix for the default language,
 *   generating clean URLs: `ru.pbservices.ge/post-slug/` — zero str_replace needed.
 * - Cross-language content (EN, IT, AR) → swapped to main domain with correct prefix
 * - `template_redirect` (p5) 301-redirects non-target-language content to main domain
 *
 * ### Extensibility
 * Fully data-driven from FRL_SUBDOMAIN_ADAPTER_MAP and FRL_SUBDOMAIN_ADAPTER_MAIN_DEFAULTS.
 * Add new subdomain entries in the constants — zero class code changes needed.
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
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static ?self $instance = null;

    /** @var bool Whether hooks have been registered this request. */
    private bool $hooks_registered = false;

    // -------------------------------------------------------------------------
    // Configuration (set once from constants in detect())
    // -------------------------------------------------------------------------

    /** @var array<string, array{lang: string, main_domain: string}> subdomain_host => config */
    private array $subdomain_map = [];

    /** @var array<string, string> lang => subdomain_host (reverse index) */
    private array $lang_to_subdomain = [];

    /** @var array<string, string> main_domain => default_lang */
    private array $main_defaults = [];

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
     * @return void
     */
    private function detect(): void {
        $this->subdomain_map  = defined('FRL_SUBDOMAIN_ADAPTER_MAP') ? (array) FRL_SUBDOMAIN_ADAPTER_MAP : [];
        $this->main_defaults  = defined('FRL_SUBDOMAIN_ADAPTER_MAIN_DEFAULTS') ? (array) FRL_SUBDOMAIN_ADAPTER_MAIN_DEFAULTS : [];

        // Build reverse index: lang => subdomain_host.
        $this->lang_to_subdomain = [];
        foreach ($this->subdomain_map as $host => $config) {
            if (!empty($config['lang']) && !empty($config['main_domain'])) {
                $this->lang_to_subdomain[$config['lang']] = $host;
            }
        }

        // Read current host.
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $host = strtolower(trim($host));

        // Strip port if present.
        if (($colon = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $colon);
        }

        $this->current_host = $host;

        // Check if we are on a mapped subdomain.
        if (isset($this->subdomain_map[$host])) {
            $this->current_subdomain_host   = $host;
            $this->current_subdomain_lang   = $this->subdomain_map[$host]['lang'];
            $this->is_on_main_domain        = false;
            return;
        }

        // Check if we are on a recognized main domain.
        $main_domains = [];
        foreach ($this->subdomain_map as $config) {
            $main_domains[$config['main_domain']] = true;
        }
        $this->is_on_main_domain = isset($main_domains[$host]);
    }

    // -------------------------------------------------------------------------
    // Public Query Methods
    // -------------------------------------------------------------------------

    /**
     * Whether the subdomain map is non-empty (module is configured).
     *
     * @return bool
     */
    public function is_configured(): bool {
        return !empty($this->subdomain_map);
    }

    /**
     * Whether the current request is on a mapped subdomain.
     *
     * @return bool
     */
    public function is_on_subdomain(): bool {
        return $this->current_subdomain_host !== null;
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

        // --- URL transformation filters (priority 20 — after rewriter at p10) ---
        add_filter('post_link',             [$this, 'filter_post_link'],        20, 2);
        add_filter('post_type_link',        [$this, 'filter_post_type_link'],   20, 2);
        add_filter('page_link',             [$this, 'filter_page_link'],        20, 2);
        add_filter('term_link',             [$this, 'filter_term_link'],        20, 3);
        add_filter('wpseo_canonical',       [$this, 'filter_canonical_url'],    20, 1);

        // --- Template redirect: 301 non-target content on subdomain ---
        add_action('template_redirect',     [$this, 'redirect_non_target_content'], 5);

        $this->hooks_registered = true;
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
     * @param  string $lang The current Polylang default language slug.
     * @return string
     */
    public function filter_pll_default_language($lang): string {
        if ($this->is_on_subdomain()) {
            return $this->current_subdomain_lang;
        }
        return $lang;
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
     * @param  string $lang The current Polylang language slug.
     * @return string
     */
    public function filter_pll_current_language($lang): string {
        if ($this->is_on_subdomain()) {
            return $this->current_subdomain_lang;
        }
        return $lang;
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
        // If this language has a mapped subdomain → return subdomain URL.
        if (isset($this->lang_to_subdomain[$lang])) {
            return 'https://' . $this->lang_to_subdomain[$lang] . '/';
        }

        // On a subdomain, for non-target languages → return main domain URL.
        if ($this->is_on_subdomain() && $lang !== $this->current_subdomain_lang) {
            $main_domain = $this->subdomain_map[$this->current_subdomain_host]['main_domain'];
            $main_default = $this->main_defaults[$main_domain] ?? null;

            if ($lang === $main_default) {
                // Default language on main → no prefix.
                return 'https://' . $main_domain . '/';
            }
            // Non-default language on main → has prefix.
            return 'https://' . $main_domain . '/' . $lang . '/';
        }

        return $url;
    }

    // -------------------------------------------------------------------------
    // URL Filters (post_link, post_type_link, page_link, term_link, canonical)
    // -------------------------------------------------------------------------

    /**
     * Guard pattern used by all URL filters.
     *
     * @return bool True if URL transformation should proceed.
     */
    private function should_transform(): bool {
        if (is_admin() || frl_is_rest_api_request() || is_preview()) {
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
     * Filter: post_link
     *
     * @param  string  $link The post permalink.
     * @param  \WP_Post $post The post object.
     * @return string
     */
    public function filter_post_link(string $link, $post): string {
        if (!$this->should_transform()) {
            return $link;
        }
        if (!$post instanceof \WP_Post) {
            return $link;
        }

        $content_lang = frl_get_language($post->ID);
        if (empty($content_lang)) {
            return $link;
        }

        return $this->transform_url($link, $content_lang);
    }

    /**
     * Filter: post_type_link
     *
     * @param  string  $link The CPT permalink.
     * @param  \WP_Post $post The post object.
     * @return string
     */
    public function filter_post_type_link(string $link, $post): string {
        if (!$this->should_transform()) {
            return $link;
        }
        if (!$post instanceof \WP_Post) {
            return $link;
        }

        $content_lang = frl_get_language($post->ID);
        if (empty($content_lang)) {
            return $link;
        }

        return $this->transform_url($link, $content_lang);
    }

    /**
     * Filter: page_link
     *
     * @param  string  $link The page permalink.
     * @param  \WP_Post $post The page object.
     * @return string
     */
    public function filter_page_link(string $link, $post): string {
        if (!$this->should_transform()) {
            return $link;
        }
        if (!$post instanceof \WP_Post) {
            return $link;
        }

        $content_lang = frl_get_language($post->ID);
        if (empty($content_lang)) {
            return $link;
        }

        return $this->transform_url($link, $content_lang);
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
     * @param  string $url The canonical URL.
     * @return string
     */
    public function filter_canonical_url($url): string {
        if (!$this->should_transform()) {
            return $url;
        }

        $content_lang = frl_get_language();
        if (empty($content_lang)) {
            return $url;
        }

        return $this->transform_url($url, $content_lang);
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
     *
     * 3. **Subdomain + target language** → No-op.
     *    `pll_default_language` filter makes Polylang generate clean URLs.
     *
     * 4. **Subdomain + cross language** → Strip prefix, swap domain, add prefix if needed.
     *    `ru.pbservices.ge/en/post/` → `pbservices.ge/post/` (EN default, no prefix)
     *    `ru.pbservices.ge/it/post/` → `pbservices.ge/it/post/` (IT has prefix)
     *
     * @param  string $url          The full URL to transform.
     * @param  string $content_lang The language slug of the content.
     * @return string
     */
    private function transform_url(string $url, string $content_lang): string {
        $target_subdomain = $this->lang_to_subdomain[$content_lang] ?? null;

        // No subdomain mapped for this language → unchanged.
        if ($target_subdomain === null) {
            return $url;
        }

        $main_domain  = $this->subdomain_map[$target_subdomain]['main_domain'];
        $main_default = $this->main_defaults[$main_domain] ?? null;

        // --- Case 1 & 2: ON MAIN DOMAIN ---
        if (!$this->is_on_subdomain()) {
            // Case 1: Default language on main → stays on main (no prefix).
            if ($content_lang === $main_default) {
                return $url;
            }

            // Case 2: Mapped language on main → swap domain, strip prefix.
            return str_replace(
                "https://{$main_domain}/{$content_lang}/",
                "https://{$target_subdomain}/",
                $url
            );
        }

        // --- ON SUBDOMAIN ---
        // Case 3: Content matches subdomain's language.
        // pll_default_language filter makes Polylang generate clean URLs
        // (no prefix) for this language → NO transformation needed.
        if ($content_lang === $this->current_subdomain_lang) {
            return $url;
        }

        // --- Case 4: Cross-language content on subdomain → swap to main domain ---

        // Step 1: Strip the language prefix from the subdomain URL.
        // The URL already has /{lang}/ prefix because Polylang added it for
        // the non-default language on the subdomain.
        $url = str_replace(
            "https://{$this->current_subdomain_host}/{$content_lang}/",
            "https://{$this->current_subdomain_host}/",
            $url
        );

        // Step 2: Swap subdomain host to main domain.
        $url = str_replace(
            "https://{$this->current_subdomain_host}/",
            "https://{$main_domain}/",
            $url
        );

        // Step 3: Add language prefix back if this language is NOT default on main.
        if ($content_lang !== $main_default) {
            $url = str_replace(
                "https://{$main_domain}/",
                "https://{$main_domain}/{$content_lang}/",
                $url
            );
        }

        return $url;
    }

    // -------------------------------------------------------------------------
    // Template Redirect: 301 non-target content on subdomain
    // -------------------------------------------------------------------------

    /**
     * On subdomain, 301-redirect non-target-language content to the main domain.
     *
     * Also handles 404 pages on the subdomain → redirect to main domain home.
     *
     * @return void
     */
    public function redirect_non_target_content(): void {
        if (!$this->is_on_subdomain()) {
            return;
        }
        if (!frl_translator_is_enabled()) {
            return;
        }

        $main_domain = $this->subdomain_map[$this->current_subdomain_host]['main_domain'];

        $obj = get_queried_object();

        if ($obj instanceof \WP_Post) {
            $post_lang = frl_get_language($obj->ID);
            if ($post_lang && $post_lang !== $this->current_subdomain_lang) {
                $redirect_url = $this->transform_url(
                    home_url($_SERVER['REQUEST_URI']),
                    $post_lang
                );
                wp_redirect($redirect_url, 301);
                exit;
            }
        }

        // 404 on subdomain → redirect to main domain home.
        if (is_404()) {
            wp_redirect('https://' . $main_domain . '/', 301);
            exit;
        }
    }
}
