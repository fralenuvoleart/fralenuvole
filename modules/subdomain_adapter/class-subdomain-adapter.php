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

        // Lowercase all map keys and subdomain values for case-insensitive domain
        // matching, since HTTP_HOST is always lowercased by detect().
        $normalized_map = [];
        foreach ($this->domain_map as $main_domain => $config) {
            $md = strtolower($main_domain);
            $normalized_map[$md] = [];
            foreach ($config as $key => $value) {
                $nv = is_string($value) ? strtolower($value) : $value;
                $normalized_map[$md][$key] = $nv;
            }
        }
        $this->domain_map = $normalized_map;

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

    /**
     * Get the domain map (main → { lang → subdomain, default_lang → lang }).
     *
     * @return array<string, array<string, string>>
     */
    public function get_domain_map(): array {
        return $this->domain_map;
    }

    /**
     * Get the subdomain reverse index.
     *
     * @return array<string, array{lang: string, default_lang: string, main_domains: string[]}>
     */
    public function get_subdomain_info(): array {
        return $this->subdomain_info;
    }

    /**
     * Get the current HTTP_HOST.
     *
     * @return string
     */
    public function get_current_host(): string {
        return $this->current_host;
    }

    /**
     * Get the current subdomain's language, or null if not on a subdomain.
     *
     * @return string|null
     */
    public function get_subdomain_lang(): ?string {
        return $this->current_subdomain_lang;
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

        // --- Polylang current language override on subdomains ---
        // Hooks into the real Polylang filter `pll_get_current_language` at
        // choose-lang.php:103 (inside PLL_Choose_Lang::set_language()). This is
        // the ONLY filter in Polylang 3.7+ that controls PLL()->curlang during
        // language resolution. The callback must return a PLL_Language object.
        add_filter('pll_get_current_language', [$this, 'filter_pll_get_current_language'], 10, 1);

        // --- Language home URL filter (for when PLL_CACHE_HOME_URL is false) ---
        // Priority 20: returns correct home URL for mapped languages when Polylang's
        // home_url cache is disabled. The same logic handles pll_additional_language_data
        // (p20) for the cached path.
        add_filter('pll_language_home_url', [$this, 'filter_pll_language_home_url'], 20, 2);

        // --- Override home_url in language data (cached path) ---
        // Priority 20: runs after Polylang's own handler at p10 to set the correct
        // subdomain URL directly in the language object's cached home_url property.
        // This is the primary fix for the homepage language switcher: when
        // PLL_CACHE_LANGUAGES and PLL_CACHE_HOME_URL are both true (default),
        // PLL_Language::get_home_url() returns the stored $this->home_url without
        // firing any filter, so we must set it correctly at creation time.
        add_filter('pll_additional_language_data', [$this, 'filter_pll_additional_language_data'], 20, 2);

        // --- Polylang canonical redirect suppression on subdomains ---
        // Priority 10: prevents Polylang's check_canonical_url() at template_redirect
        // P4 from issuing redirects on subdomains when the content language matches the
        // subdomain language. Polylang operates in directory mode (force_lang=1) and
        // would add /ru/ prefix to clean URLs on ru.pbservices.ge, creating a redirect
        // loop with the legacy adapter at P6 which strips that prefix.
        add_filter('pll_check_canonical_url', [$this, 'filter_pll_check_canonical_url'], 10, 2);

        // --- WordPress home_url override on subdomains ---
        // Priority 20: makes home_url() return the correct subdomain URL.
        add_filter('home_url', [$this, 'filter_home_url'], 20, 4);

        // --- URL transformation filters (priority PHP_INT_MAX — always last) ---
        // Polylang registers post_link, post_type_link, page_link, term_link, etc.
        // at priority 20. Since the Subdomain Adapter is the final authority on URL
        // structure for SEO, PHP_INT_MAX guarantees no other plugin can override the
        // subdomain transformation, regardless of plugin load order.
        add_filter('post_link',             [$this, 'filter_post_link'],        PHP_INT_MAX, 2);
        add_filter('post_type_link',        [$this, 'filter_post_type_link'],   PHP_INT_MAX, 2);
        add_filter('page_link',             [$this, 'filter_page_link'],        PHP_INT_MAX, 2);
        add_filter('term_link',             [$this, 'filter_term_link'],        PHP_INT_MAX, 3);
        add_filter('wpseo_canonical',       [$this, 'filter_canonical_url'],    PHP_INT_MAX, 1);
        add_filter('the_seo_framework_meta_render_data', [$this, 'filter_tsf_canonical_url'], PHP_INT_MAX, 1);

        // --- Subdomain front page resolution ---
        // On a subdomain (e.g. ru.pbservices.ge), the DB option page_on_front
        // holds the main domain's front page ID (EN). Override it to return the
        // subdomain language's translation so the root URL resolves to the
        // correct language content instead of triggering redirect_non_target_content().
        add_filter('option_page_on_front',  [$this, 'filter_option_page_on_front'], 20, 1);
        add_filter('option_page_for_posts', [$this, 'filter_option_page_for_posts'], 20, 1);

        // --- Template redirect: 301 non-target content on subdomain ---
        add_action('template_redirect',     [$this, 'redirect_non_target_content'], 5);
    }

    // -------------------------------------------------------------------------
    // Filter: pll_get_current_language (Key Mechanism)
    // -------------------------------------------------------------------------

    /**
     * Override Polylang's current language on mapped subdomains.
     *
     * THE REAL FILTER — unlike `pll_default_language` and `pll_current_language`
     * which are FUNCTION NAMES in Polylang 3.7+ (not `apply_filters` hooks),
     * `pll_get_current_language` at choose-lang.php:103 IS a real filter inside
     * `PLL_Choose_Lang::set_language()`. It receives a `PLL_Language` object (or
     * false) and fires BEFORE the default-language fallback.
     *
     * When on `ru.pbservices.ge`, returns the `PLL_Language` object for RU.
     * Polylang then sets `PLL()->curlang = RU`, which makes:
     *   - `pll_current_language()` → 'ru'
     *   - `pll_translate_string('string', 'ru')` → short-circuits to `pll__()`
     *   - `pll__()` → reads from the RU MO (`$GLOBALS['l10n']['pll_string']`)
     *
     * This is functionally equivalent to changing the DB default to RU, but
     * without touching the database.
     *
     * @param  PLL_Language|false $curlang The PLL_Language object resolved so far,
     *                                     or false if no language was detected.
     * @return PLL_Language|false
     */
    public function filter_pll_get_current_language($curlang) {
        if ($this->is_on_subdomain()) {
            $subdomain_lang = PLL()->model->get_language($this->current_subdomain_lang);
            if ($subdomain_lang instanceof \PLL_Language) {
                return $subdomain_lang;
            }
        }
        return $curlang;
    }

    // -------------------------------------------------------------------------
    // Filter: pll_check_canonical_url (Redirect loop prevention)
    // -------------------------------------------------------------------------

    /**
     * Prevent Polylang from issuing canonical redirects on mapped subdomains
     * when the content language matches the subdomain's language.
     *
     * On a subdomain like `ru.pbservices.ge`, Polylang operates in directory
     * mode (`force_lang=1`) and treats `/novosti/` as the EN version. When it
     * detects the content is RU, it adds the `/ru/` prefix via
     * `switch_language_in_link()` and issues a 301 redirect to
     * `ru.pbservices.ge/ru/novosti/`. This conflicts with the legacy adapter
     * at `template_redirect` P6 which strips the `/ru/` prefix, creating an
     * infinite redirect loop.
     *
     * By returning `false`, we cancel Polylang's redirect when the content
     * matches the subdomain's language, because clean URLs are already
     * canonical on the subdomain. Cross-language content (e.g., EN posts on
     * `ru.pbservices.ge`) still gets redirected by Polylang as intended.
     *
     * Hooked to `pll_check_canonical_url` at priority 10 (registered in
     * register_hooks()). This filter fires inside
     * {@see PLL_Canonical::check_canonical_url()} at line 138 of
     * `src/frontend/canonical.php` (Polylang plugin).
     *
     * @see https://github.com/wp-plugins/polylang/blob/master/frontend/canonical.php
     *
     * @param  string|false $redirect_url The redirect URL proposed by Polylang.
     * @param  PLL_Language $language     The detected content language object.
     * @return string|false               Return false to cancel the redirect.
     */
    public function filter_pll_check_canonical_url($redirect_url, $language) {
        if (!$this->is_on_subdomain()) {
            return $redirect_url;
        }

        if ($language->slug === $this->current_subdomain_lang) {
            return false;
        }

        return $redirect_url;
    }

    // -------------------------------------------------------------------------
    // Filter: pll_language_home_url (dynamic, non-cached path)
    // -------------------------------------------------------------------------

    /**
     * Return the correct home URL for mapped languages.
     *
     * Hooked to `pll_language_home_url` (fired by PLL_Language::get_home_url()
     * when PLL_CACHE_LANGUAGES or PLL_CACHE_HOME_URL is false).
     *
     * Handles hreflang tags and language switcher URLs in both directions:
     * - On main domain: `pll_home_url('ru')` → `https://ru.pbservices.ge/`
     * - On subdomain: `pll_home_url('ru')` → `https://ru.pbservices.ge/`
     * - On subdomain: `pll_home_url('en')` → `https://pbservices.ge/` (default, no prefix)
     * - On subdomain: `pll_home_url('it')` → `https://pbservices.ge/it/` (has prefix)
     *
     * @param  string $url      The home URL Polylang computed.
     * @param  array  $language Array of PLL_Language properties (from to_array('db')).
     * @return string
     */
    public function filter_pll_language_home_url($url, $language): string {
        $lang = $language['slug'] ?? '';

        // Validate: if the language is not recognized, return the original URL unchanged.
        if (empty($lang) || !in_array($lang, frl_get_active_languages(), true)) {
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

    // -------------------------------------------------------------------------
    // Filter: pll_additional_language_data (cached path — primary fix)
    // -------------------------------------------------------------------------

    /**
     * Override home_url in language data for mapped languages.
     *
     * Hooked to `pll_additional_language_data` at p20, after Polylang's own
     * handler at p10 (PLL_Links_Model::set_language_home_urls). This sets the
     * correct subdomain URL directly in the language object's cached home_url
     * property, which is what PLL_Language::get_home_url() returns when
     * PLL_CACHE_LANGUAGES and PLL_CACHE_HOME_URL are both true (default).
     *
     * This is the primary fix for the homepage language switcher: when Polylang's
     * language switcher requests the RU translation URL on the EN front page,
     * PLL_Frontend_Static_Pages::pll_pre_translation_url() calls
     * $language->get_home_url(), which returns this property without firing
     * any filter. Setting it correctly at creation time covers all cases.
     *
     * @param  array $additional_data Additional language data (home_url, search_url, etc.).
     * @param  array $language        Language properties array.
     * @return array
     */
    public function filter_pll_additional_language_data(array $additional_data, array $language): array {
        $lang = $language['slug'] ?? '';

        if (empty($lang)) {
            return $additional_data;
        }

        // Determine which main domain to use for resolution.
        if ($this->is_on_subdomain()) {
            if (!isset($this->subdomain_info[$this->current_subdomain_host])) {
                return $additional_data;
            }
            $resolve_domain = $this->subdomain_info[$this->current_subdomain_host]['main_domains'][0];
        } elseif ($this->is_on_main_domain) {
            $resolve_domain = $this->current_host;
        } else {
            return $additional_data;
        }

        $scheme = $this->get_scheme();

        // If this language has a mapped subdomain → set home_url to subdomain URL.
        if (isset($this->domain_map[$resolve_domain][$lang])
            && $this->domain_map[$resolve_domain][$lang] !== ''
        ) {
            $additional_data['home_url'] = "{$scheme}://" . $this->domain_map[$resolve_domain][$lang] . '/';
            return $additional_data;
        }

        // Set home_url to main domain URL (with or without prefix).
        $main_default = $this->domain_map[$resolve_domain]['default_lang'] ?? self::FALLBACK_LANG;
        if ((string) $lang === $main_default) {
            $additional_data['home_url'] = "{$scheme}://{$resolve_domain}/";
        } else {
            $additional_data['home_url'] = "{$scheme}://{$resolve_domain}/{$lang}/";
        }

        return $additional_data;
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
    public function transform_url(string $url, string $content_lang): string {
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
                add_filter('x_redirect_by', function () {
                    return 'Frl_Subdomain_Adapter::redirect_non_target_content(post)';
                }, 999);
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
                add_filter('x_redirect_by', function () {
                    return 'Frl_Subdomain_Adapter::redirect_non_target_content(term)';
                }, 999);
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

    // -------------------------------------------------------------------------
    // Filters: option_page_on_front / option_page_for_posts
    // -------------------------------------------------------------------------

    /**
     * On mapped subdomains, translate page_on_front to the subdomain's language.
     *
     * The DB stores the main domain's front page ID (e.g. EN). When on a
     * subdomain like ru.pbservices.ge, this filter returns the RU translation
     * so the root URL resolves to the correct language content. Without this,
     * get_queried_object() on '/' returns the EN page, and
     * redirect_non_target_content() would 301-redirect to the main domain.
     *
     * @param  mixed $page_id The DB-stored front page ID (string|int).
     * @return int Translated page ID in the subdomain's language, or the original.
     */
    public function filter_option_page_on_front($page_id): int {
        if (!$this->is_on_subdomain()) {
            return (int) $page_id;
        }
        $translation = pll_get_post((int) $page_id, $this->current_subdomain_lang);
        return $translation ? (int) $translation : (int) $page_id;
    }

    /**
     * On mapped subdomains, translate page_for_posts to the subdomain's language.
     *
     * Same principle as filter_option_page_on_front() but for the posts page.
     *
     * @param  mixed $page_id The DB-stored posts page ID (string|int).
     * @return int Translated page ID in the subdomain's language, or the original.
     */
    public function filter_option_page_for_posts($page_id): int {
        if (!$this->is_on_subdomain()) {
            return (int) $page_id;
        }
        $translation = pll_get_post((int) $page_id, $this->current_subdomain_lang);
        return $translation ? (int) $translation : (int) $page_id;
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
