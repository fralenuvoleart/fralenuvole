<?php

/**
 * Subdomain Adapter — Robots.txt Handler
 *
 * Modifies robots.txt output for subdomain-based multilingual setups.
 * Handles sitemap URLs, user-agent directives, and other robots.txt concerns
 * specific to the main domain and its slave subdomains.
 *
 * Current integrations:
 * - The SEO Framework: optionally removes slave subdomain sitemap URLs from the
 *   main domain's robots.txt and strips extra language sitemaps on subdomains.
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Frl_Subdomain_Adapter_Robots
 *
 * Handles robots.txt modifications for the subdomain adapter.
 * New filters and handlers should be added here as needed.
 */
class Frl_Subdomain_Adapter_Robots {

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /** @var self|null */
    private static ?self $instance = null;

    /** @var Frl_Subdomain_Adapter Reference to the main adapter for domain detection. */
    private Frl_Subdomain_Adapter $adapter;

    // -------------------------------------------------------------------------
    // Static Factory
    // -------------------------------------------------------------------------

    /**
     * Initialize the singleton.
     *
     * @return self
     */
    public static function init(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    /**
     * Private constructor.
     */
    private function __construct() {
        $this->adapter = Frl_Subdomain_Adapter::init();
    }

    // -------------------------------------------------------------------------
    // Hook Registration
    // -------------------------------------------------------------------------

    /**
     * Register robots.txt related filters.
     *
     * @return void
     */
    private function register_hooks(): void {
        // Re-entrancy guard: prevent double hook registration.
        if (frl_is_already_running(__CLASS__ . '::register_hooks')) {
            return;
        }

        // --- The SEO Framework: sitemap endpoints ---
        // Priority 30: runs after Polylang's compatibility at p20.
        add_filter('the_seo_framework_sitemap_endpoint_list', [$this, 'filter_tsf_sitemap_endpoint_list'], 30, 1);

        // --- The SEO Framework: robots.txt sections ---
        // Priority 30: runs after TSF's own processing.
        add_filter('the_seo_framework_robots_txt_sections', [$this, 'filter_tsf_robots_txt_sections'], 30, 2);
    }

    // -------------------------------------------------------------------------
    // Filter: the_seo_framework_sitemap_endpoint_list (TSF)
    // -------------------------------------------------------------------------

    /**
     * Strip extra Polylang sitemap endpoints on subdomain.
     *
     * On main domain, the endpoint list is left untouched — TSF generates
     * directory-style URLs (e.g., /ru/sitemap.xml) which are handled by
     * existing 301 redirects.
     *
     * @param  array[] $list Sitemap endpoints keyed by ID.
     * @return array[]
     */
    public function filter_tsf_sitemap_endpoint_list(array $list): array {
        // Guard: adapter must be configured.
        if (!$this->adapter->is_configured()) {
            return $list;
        }

        // On subdomain: strip all Polylang directory-style endpoints, keep only base.
        if ($this->adapter->is_on_subdomain()) {
            return $this->strip_extra_endpoints_on_subdomain($list);
        }

        return $list;
    }

    /**
     * On subdomain: strip all Polylang directory-style endpoints, keep only base.
     *
     * @param  array[] $list
     * @return array[]
     */
    private function strip_extra_endpoints_on_subdomain(array $list): array {
        foreach ($list as $key => $data) {
            if (str_starts_with($key, '_base_polylang_')) {
                unset($list[$key]);
            }
        }
        return $list;
    }

    // -------------------------------------------------------------------------
    // Filter: the_seo_framework_robots_txt_sections (TSF)
    // -------------------------------------------------------------------------

    /**
     * Optionally remove slave subdomain sitemap URLs from robots.txt on main domain.
     *
     * When the subdomain_adapter_robots_sitemap option is enabled, the legacy
     * directory-style URLs (e.g., https://staging.pbservices.ge/ru/sitemap.xml)
     * are kept as-is — existing 301 redirects handle forwarding to the correct
     * subdomain URLs.
     *
     * When disabled (default), slave subdomain URLs are removed from robots.txt.
     *
     * @param  array  $robots_sections The robots directives array.
     * @param  string $site_path       The site path prefix.
     * @return array
     */
    public function filter_tsf_robots_txt_sections(array $robots_sections, string $site_path): array {
        // Guard: adapter must be configured.
        if (!$this->adapter->is_configured()) {
            return $robots_sections;
        }

        // Only act on main domain. On subdomain, the endpoint list filter
        // already stripped extra endpoints, so TSF only includes the base sitemap.
        if (!$this->adapter->is_on_main_domain()) {
            return $robots_sections;
        }

        // When option is disabled (default), remove slave subdomain URLs.
        // When enabled, leave URLs as-is — 301 redirects handle the rest.
        if (!frl_get_option('subdomain_adapter_robots_sitemap')) {
            if (!isset($robots_sections['sitemaps']['sitemaps']) || empty($robots_sections['sitemaps']['sitemaps'])) {
                return $robots_sections;
            }

            $domain_map = $this->adapter->get_domain_map();
            $current_host = $this->adapter->get_current_host();
            $lang_map = $domain_map[$current_host] ?? [];

            // Collect all slave subdomain hosts for this main domain.
            $subdomain_hosts = [];
            foreach ($lang_map as $lang => $subdomain_host) {
                if ($lang === 'default_lang' || $subdomain_host === '') {
                    continue;
                }
                $subdomain_hosts[] = $subdomain_host;
            }

            if (!empty($subdomain_hosts)) {
                $robots_sections['sitemaps']['sitemaps'] = array_values(array_filter(
                    $robots_sections['sitemaps']['sitemaps'],
                    function (string $url) use ($subdomain_hosts): bool {
                        foreach ($subdomain_hosts as $host) {
                            if (str_contains($url, $host)) {
                                return false;
                            }
                        }
                        return true;
                    }
                ));
            }
        }

        return $robots_sections;
    }
}
