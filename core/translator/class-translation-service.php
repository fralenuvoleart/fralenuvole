<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * class-translation-service.php - Object-oriented translation service.
 *
 * This file defines the Frl_Translation_Service class, which encapsulates
 * all translation, localization, and caching logic. It is implemented as a singleton
 * to ensure a single, consistent state throughout the request lifecycle.
 *
 * For backward compatibility, this file also provides global helper functions
 * that wrap the class methods, ensuring that existing code continues to work flawlessly.
 */

final class Frl_Translation_Service
{
    private static ?self $instance = null;

    // Caches to hold state within a single request, preventing redundant lookups.
    private array $is_multilingual_cache = [];
    private ?string $language_cache = null;
    private ?string $default_language_cache = null;
    private ?array $active_languages_cache = null;
    private array $string_registration_queue = [];
    private bool $shutdown_hook_added = false;

    // Private constructor to enforce the singleton pattern.
    private function __construct()
    {
    }

    /**
     * Get the singleton instance of the service.
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Checks for multilingual function availability.
     */
    public function has_multilingual_plugin(): bool
    {
        // Check for the presence of either WPML or Polylang's main constant/function.
        // This is a more robust way to detect a multilingual environment.
        if (defined('ICL_SITEPRESS_VERSION') || function_exists('pll_the_languages') || defined('PLL')) {
            $check_result = true;
        } else {
            $check_result = false;
        }

        return $check_result;
    }

    /**
     * Checks for multilingual function availability.
     */
    public function is_multilingual(?string $function_name = null): bool
    {
        $check_type = $function_name ?? 'all';

        if (isset($this->is_multilingual_cache[$check_type])) {
            return $this->is_multilingual_cache[$check_type];
        }

        $has_multilingual_plugin = $this->has_multilingual_plugin();

        if ($function_name !== null) {
            $check_result = function_exists($function_name);

            if (
                defined('FRL_TRANSLATOR_LOG_MISSING_TRANSLATION') && FRL_TRANSLATOR_LOG_MISSING_TRANSLATION &&
                $has_multilingual_plugin && !$check_result
            ) {
                frl_log('Multilingual function ' . $function_name . ' does not exist');
            }
        } else {
            // Check for the presence of either WPML or Polylang's main constant/function.
            // Detect a multilingual environment.
            if ($has_multilingual_plugin) {
                $check_result = true;
            } else {
                $check_result = false;
            }
        }

        $this->is_multilingual_cache[$check_type] = $check_result;
        return $check_result;
    }

    /**
     * Get current language.
     */
    public function get_language(): string
    {
        if ($this->language_cache === null) {
            $language = 'en';
            if ($this->is_multilingual('pll_current_language')) {
                $lang = pll_current_language();
                if (is_string($lang) && strlen($lang) === 2) $language = $lang;
            } else {
                $lang = substr(get_locale(), 0, 2);
                if (strlen($lang) === 2) $language = $lang;
            }
            global $wp_query;

            if (isset($wp_query->query['lang']) && is_string($wp_query->query['lang']) && strlen($wp_query->query['lang']) === 2) {
                $language = $wp_query->query['lang'];
            }

            $this->language_cache = $language;
        }
        return $this->language_cache;
    }

    /**
     * Get default language.
     */
    public function get_default_language(): string
    {
        if ($this->default_language_cache === null) {
            $this->default_language_cache = $this->is_multilingual('pll_default_language') ? pll_default_language() : 'en';
        }
        return $this->default_language_cache;
    }

    /**
     * Get active site languages.
     */
    public function get_active_languages(): array
    {
        if ($this->active_languages_cache === null) {
            $languages = [];
            if ($this->is_multilingual('pll_languages_list')) {
                /** @disregard P1010 Undefined type */
                $languages = pll_languages_list(['fields' => 'slug']);
            }
            $this->active_languages_cache = !empty($languages) ? $languages : [$this->get_default_language()];
        }
        return apply_filters('frl_active_languages', $this->active_languages_cache);
    }

    /**
     * Get a string's translation.
     */
    public function get_translation(string $string, ?string $lang = null): string
    {
        if (empty($string)) return '';

        $this->queue_string_registration([$string]);
        $language = $lang ?: $this->get_language();

        $version = $this->get_translation_version();
        $cache_key = substr(md5($string . '_' . $version), 0, 12);

        return frl_cache_remember('translations', $cache_key, function () use ($string, $language) {
            $translation = $this->attempt_string_translation($string, $language);
            if ($translation !== null) {
                return frl_validate_html_fragment($translation);
            }
            return $string;
        });
    }

    /**
     * Get a block's translation.
     */
    public function get_translation_block(string $block_content, array $block): string
    {
        if (empty($block_content)) {
            return '';
        }

        // Check for the translation class. If not present, no processing is needed.
        $has_translate_class = (!empty($block['attrs']['className']) && str_contains($block['attrs']['className'], FRL_PREFIX . '-translate')) ||
            (!empty($block['attrs']['css_class']) && str_contains($block['attrs']['css_class'], FRL_PREFIX . '-translate'));

        if (!$has_translate_class) {
            return $block_content;
        }

        // Normalize content for stable caching by removing dynamic values
        $normalized_content = $this->normalize_block_content_for_caching($block_content);
        $content_hash = md5($normalized_content);
        $cache_key = $this->generate_block_cache_key($block, $content_hash);

        // This is the new architecture. All expensive work is inside the callback.
        $cached_data = frl_cache_remember('blocks', $cache_key, function () use ($block_content) {
            // This logic now only runs ONCE per block version (a cache miss).

            // 1. Gather all strings that need registration.
            $strings_to_register = [];
            if (preg_match_all('/{{([^{}]+)}}/', $block_content, $string_matches)) {
                $strings_to_register = array_unique(array_map('trim', $string_matches[1]));
            }

            // 2. Perform all translations in one efficient pass.
            $translated_html = $this->_process_all_patterns($block_content);

            // Apply any additional filters.
            $translated_html = apply_filters('frl_block_translation_filter', $translated_html);

            // 3. Queue registration only on cache miss to reduce overhead under load.
            if (!empty($strings_to_register)) {
                $this->queue_string_registration($strings_to_register);
            }

            // 4. Store both the final HTML and the list of strings in the cache.
            return [
                'html'    => $translated_html ?: $block_content,
                'strings' => $strings_to_register,
            ];
        }, DAY_IN_SECONDS);

        // Hyper-optimized cache-hit path: return cached HTML without re-queuing registrations.
        return $cached_data['html'] ?? $block_content;
    }

    /**
     * Get a post permalink's translation.
     */
    public function get_translation_permalink(int $id, string $lang): string
    {
        $cache_key = "post_{$id}"; // Key must be language-specific

        return frl_cache_remember('permalinks', $cache_key, function () use ($id, $lang) {
            $default_language = $this->get_default_language();

            // If no multilingual plugin or we want the default language, get direct permalink.
            if (!$this->is_multilingual('pll_get_post') || $lang === $default_language) {
                return get_permalink($id) ?: '#';
            }

            // Get the ID of the translated post.
            $translated_id = pll_get_post($id, $lang);

            // If a translation exists for the target language, return its permalink.
            if ($translated_id) {
                return get_permalink($translated_id) ?: '#';
            }

            // Fallback: If no translation exists, return the permalink for the original ID.
            return get_permalink($id) ?: '#';
        });
    }

    /**
     * Get a term permalink's translation.
     */
    public function get_translation_term_permalink(string $slug, string $language, string $taxonomy = 'category'): string
    {
        $cache_key = "term_" . sanitize_key($slug) . "_{$taxonomy}";
        return frl_cache_remember(
            'permalinks',
            $cache_key,
            function () use ($slug, $language, $taxonomy) {
                // For default language, try direct term lookup
                if ($language === $this->get_default_language()) {
                    $term = get_term_by('slug', $slug, $taxonomy);
                    if (!$term || is_wp_error($term)) {
                        return '#';
                    }

                    $link = get_term_link($term);
                    return is_wp_error($link) ? '#' : $link;
                }

                // For translations, use DB query to get base term ID by slug + taxonomy (bypass language filters)
                global $wpdb;
                $term_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT t.term_id
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                WHERE t.slug = %s AND tt.taxonomy = %s",
                    $slug,
                    $taxonomy
                ));

                if (!$term_id) {
                    return '#';
                }
                if (!$this->is_multilingual('icl_object_id')) {
                    return '#';
                }

                // Get translated term ID and term
                $translated_id = icl_object_id($term_id, $taxonomy, true, $language) ?? $term_id;
                $translated_term = get_term($translated_id);

                $link = get_term_link($translated_term) ?? '#';
                return $link;
            }
        );
    }

    /**
     * Get translations for a batch of strings.
     */
    public function get_translation_batch_strings(array $strings): array
    {
        // Static request-level cache to avoid redundant processing within the same request
        static $request_cache = [];

        if (empty($strings)) {
            return [];
        }

        $language = $this->get_language();

        // Early return if current language is default language
        if ($language === $this->get_default_language()) {
            return array_combine($strings, $strings);
        }

        // Create a consistent cache key with sorted strings (language-scoped)
        $sorted_strings = $strings;
        sort($sorted_strings);
        $batch_key = $language . '|' . implode('|', $sorted_strings);

        // Check if we already processed this exact batch in this request
        if (isset($request_cache[$batch_key])) {
            return $request_cache[$batch_key];
        }

        // Process each string using the shared core function
        $translations = [];

        foreach ($sorted_strings as $string) {
            if (empty($string)) {
                continue;
            }

            // Use the single-string translation function to leverage its persistent cache.
            $translations[$string] = $this->get_translation($string);
        }

        // Store in request cache
        $request_cache[$batch_key] = $translations;

        return $translations;
    }

    /**
     * Get translations for a batch of permalink slugs.
     */
    public function get_translation_batch_permalinks(array $slugs, ?string $language = null): array
    {
        // Static request-level cache to avoid redundant processing within the same request
        static $request_cache = [];

        if (empty($slugs)) {
            return [];
        }

        $language = $language ?: $this->get_language();
        $batch_key = $language . '|' . implode('|', $slugs);

        if (isset($request_cache[$batch_key])) {
            return $request_cache[$batch_key];
        }

        $permalinks = [];
        foreach ($slugs as $original_slug) {
            // Prefer the official Posts Page when its base slug matches.
            $posts_page = (int) get_option( 'page_for_posts' );
            if ( $posts_page ) {
                $posts_page_slug = get_post_field( 'post_name', $posts_page );
                if ( $posts_page_slug === $original_slug ) {
                    $link = $this->get_translation_permalink( $posts_page, $language );
                    if ( $link ) {
                        // debugging removed
                        $permalinks[ $original_slug ] = $link;
                        continue;
                    }
                }
            }

            $taxonomy = 'category';
            $lookup_slug = $original_slug;
            $is_term_only = false;

            // Allow external components (e.g., rewriter features) to provide
            // custom permalink translations without creating a hard dependency.
            $custom_link = apply_filters(
                'frl_translate_custom_permalink',
                null,
                $original_slug,
                $language
            );
            if ($custom_link !== null) {
                // A custom link was provided – accept it and skip further look-ups.
                $permalinks[$original_slug] = $custom_link;
                continue;
            }

            // --- Generic post type archive support: archive:slug ---
            if (str_starts_with($original_slug, 'archive:')) {
                [, $archive_slug] = explode(':', $original_slug, 2);
                $archive_slug = sanitize_key($archive_slug);

                if (!empty($archive_slug)) {
                    $types = get_post_types(['public' => true], 'objects');
                    foreach ($types as $type) {
                        /** @var WP_Post_Type $type */
                        if (empty($type->has_archive)) continue;
                        $rewrite_slug = is_array($type->rewrite ?? null) ? ($type->rewrite['slug'] ?? '') : '';
                        if ($archive_slug === $type->name || ($rewrite_slug && $archive_slug === sanitize_key($rewrite_slug))) {
                            $link = get_post_type_archive_link($type->name);
                            if ($link) {
                                if ($language !== $this->get_language()) {
                                    if ($this->is_multilingual('pll_home_url')) {
                                        $pll_home = call_user_func('pll_home_url', $language);
                                        $link = preg_replace(
                                            '~^' . preg_quote(home_url(), '~') . '~',
                                            rtrim((string) $pll_home, '/'),
                                            $link
                                        );
                                    } else {
                                        $link = apply_filters('wpml_permalink', $link, $language);
                                    }
                                }
                                $permalinks[$original_slug] = $link;
                                continue 2; // continue outer foreach ($slugs ...)
                            }
                        }
                    }
                }
                // If not resolved, fall through to generic handling below
            }

            if (str_contains($original_slug, ':')) {
                [$taxonomy, $lookup_slug] = explode(':', $original_slug, 2);
                $is_term_only = true;
            }

            if (!$is_term_only) {
                $post_id = frl_get_post_id_by_slug($lookup_slug);

                if ($post_id && $post_id > 0) {
                    $link = $this->get_translation_permalink($post_id, $language);
                    if ($link) {
                        $permalinks[$original_slug] = $link;
                        continue;
                    }
                }
            }

            $link = $this->get_translation_term_permalink($lookup_slug, $language, $taxonomy);
            if ($link && $link !== '#') {
                $permalinks[$original_slug] = $link;
                continue;
            }

            $permalinks[$original_slug] = '#';
        }

        $request_cache[$batch_key] = $permalinks;

        return $permalinks;
    }

    /**
     * Register a string for translation.
     */
    public function register_translation(string $string): void
    {
        if (empty($string) || !$this->is_multilingual('icl_register_string')) {
            return;
        }

        // Heuristic: If the string is already translated in the current language,
        // we assume it is registered and bail out to prevent redundant calls.
        $current_language = $this->get_language();
        $default_language = $this->get_default_language();

        if ($current_language !== $default_language) {
            $translation = $this->attempt_string_translation($string, $current_language);
            if ($translation !== null) {
                return;
            }
        }

        // Create readable identifier: lowercase, underscores, max 64 chars
        $identifier = str_replace('-', '_', sanitize_title($string));
        if (strlen($identifier) > 64) {
            $identifier = substr($identifier, 0, 60) . '_' . substr(md5($string), 0, 3);
        }
        icl_register_string(FRL_NAME, $identifier, $string);
    }

    /**
     * Queues strings for registration at shutdown.
     */
    public function queue_string_registration(array $strings): void
    {
        // Filter out any strings that are already in the queue for this request.
        $newly_found_strings = array_diff($strings, array_keys($this->string_registration_queue));

        if (!empty($newly_found_strings)) {
            // Add new strings to the request's batch.
            foreach ($newly_found_strings as $new_str) {
                $this->string_registration_queue[$new_str] = true; // Mark as found
            }
        }

        // Schedule the shutdown action only once per request.
        if (!$this->shutdown_hook_added) {
            add_action('shutdown',
                [$this, 'process_string_registration_queue']);
            $this->shutdown_hook_added = true;
        }
    }

    /**
     * Processes the queued strings for registration. Hooked to 'shutdown'.
     */
    public function process_string_registration_queue(): void
    {
        if (empty($this->string_registration_queue)) return;
        foreach (array_keys($this->string_registration_queue) as $string) {
            $this->register_translation($string);
        }
        $this->string_registration_queue = [];
    }

    /**
     * Get post translations IDs for a given post ID.
     */
    public function get_post_translations(int $post_id): array
    {
        if ($post_id <= 0) return [];
        return frl_cache_remember('postdata', "post_{$post_id}_translations", function () use ($post_id) {
            if ($this->is_multilingual('pll_get_post_translations')) {
                $translations = pll_get_post_translations($post_id);
                if (!empty($translations)) return $translations;
            }
            return [$this->get_default_language() => $post_id];
        });
    }

    /**
     * Get term translations IDs for a given term ID.
     * Mirrors logic of get_post_translations() but for taxonomy terms.
     *
     * @param int    $term_id  Term ID.
     * @return array Language-keyed map of term IDs.
     */
    public function get_term_translations(int $term_id): array
    {
        if ($term_id <= 0) {
            return [];
        }

        return frl_cache_remember('postdata', "term_{$term_id}_translations", function () use ($term_id) {
            if ($this->is_multilingual('pll_get_term_translations')) {
                $translations = pll_get_term_translations($term_id);
                if (!empty($translations)) {
                    return $translations;
                }
            }

            // Fallback – map default language to provided term ID.
            return [$this->get_default_language() => $term_id];
        });
    }

    /**
     * Get language for a specific post or term ID.
     *
     * @param int $id Post ID or Term ID
     * @param string $type 'post' or 'term'
     * @return string Language code (e.g., 'en', 'it')
     */
    public function get_object_language(int $id, string $type = 'post'): string
    {
        if ($type === 'term') {
            return $this->detect_term_language($id);
        }
        return $this->detect_post_language($id);
    }

    /**
     * Detect language for a specific post ID.
     */
    private function detect_post_language(int $post_id): string
    {
        // Try Polylang first
        if ($this->is_multilingual('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id);
            if (!empty($lang) && is_string($lang)) {
                return $lang;
            }
        }

        // Fallback to global language
        return $this->get_language();
    }

    /**
     * Detect language for a specific term ID.
     */
    private function detect_term_language(int $term_id): string
    {
        // Try Polylang first
        if (function_exists('pll_get_term_language')) {
            $lang = pll_get_term_language($term_id);
            if (!empty($lang) && is_string($lang)) {
                return $lang;
            }
        }

        // Fallback to global language
        return $this->get_language();
    }

    /**
     * Get the global translation version.
     */
    public function get_translation_version(): int
    {
        return (int)frl_get_option('translation_version') ?: 1;
    }

    /**
     * Efficiently processes a string for all placeholder patterns ({{...}} and ##...##).
     */
    private function _process_all_patterns(string $content): string
    {
        // Use a single regex to find all placeholder types in one pass.
        if (!preg_match_all('/{{([^{}]+)}}|##([^#]+)##/', $content, $matches, PREG_SET_ORDER)) {
            return $content;
        }

        $strings_to_translate = [];
        $permalinks_to_translate = [];

        // Sort matches into batches for efficient lookup.
        foreach ($matches as $match) {
            if (!empty($match[1])) {
                $strings_to_translate[] = trim($match[1]);
            } elseif (!empty($match[2])) {
                $permalinks_to_translate[] = trim($match[2]);
            }
        }

        // Fetch all translations up front.
        $translated_strings = !empty($strings_to_translate) ? $this->get_translation_batch_strings(array_unique($strings_to_translate)) : [];
        $translated_permalinks = !empty($permalinks_to_translate) ? $this->get_translation_batch_permalinks(array_unique($permalinks_to_translate)) : [];

        // Use a single replace callback for performance.
        return preg_replace_callback('/{{([^{}]+)}}|##([^#]+)##/', function ($match) use ($translated_strings, $translated_permalinks) {
            if (!empty($match[1])) {
                $original = trim($match[1]);
                // Fetch the translated string (or fall back to original).
                $translated = $translated_strings[$original] ?? $original;

                // Process any ##slug## placeholders that might be nested inside
                // the translated string (e.g. translations containing links).
                $translated = $this->process_permalink_patterns($translated);

                return $translated;
            }
            if (!empty($match[2])) {
                $original = trim($match[2]);
                return $translated_permalinks[$original] ?? '#';
            }
            return $match[0]; // Should not happen, but as a fallback.
        }, $content);
    }

    /**
     * Normalize block content for stable caching by removing dynamic values.
     *
     * This removes random values that change on every page load, which would
     * otherwise prevent effective caching of translated blocks.
     *
     * @param string $content Original block content
     * @return string Normalized content with dynamic values removed
     */
    private function normalize_block_content_for_caching(string $content): string
    {
        // Remove Greenshift random CSS custom properties that change on every request
        $content = preg_replace('/--random:\s*[\d.]+;?/', '--random:NORMALIZED;', $content);

        // Remove other dynamic values that might affect caching
        // Note: Only remove values that don't affect the actual translation content

        // Normalize timestamps or other time-based values if present
        $content = preg_replace('/data-timestamp="\d+"/', 'data-timestamp="NORMALIZED"', $content);

        // Normalize any other random IDs that might be generated dynamically
        // but preserve the structure for proper translation

        return $content;
    }

    /**
     * Generates the cache key for block translation.
     */
    private function generate_block_cache_key(array $block, string $content_hash): string
    {
        static $mod_time_cache = [];
        global $post;

        $container_id = 0;
        $container_type = 'general';
        $mod_timestamp = '0';

        if (!empty($block['context']['postId'])) {
            $context_post_id = absint($block['context']['postId']);
            $context_post_type = $block['context']['postType'] ?? 'post';
            $container_id = $context_post_id;
            if (in_array($context_post_type, ['wp_template_part', 'wp_template'])) {
                $container_type = 'template';
            } elseif ($context_post_type === 'wp_block') {
                $container_type = 'reusable';
            } else {
                $container_type = 'post';
            }
        } elseif (isset($block['attrs']['ref'])) {
            $ref_id = absint($block['attrs']['ref']);
            if ($ref_id > 0) {
                $container_id = $ref_id;
                $container_type = 'reusable';
            }
        } elseif ($post && isset($post->ID)) {
            $container_id = $post->ID;
            $container_type = 'post';
        }

        if ($container_id > 0) {
            if (!isset($mod_time_cache[$container_id])) {
                $mod_time_cache[$container_id] = (string)get_post_modified_time('U', true, $container_id) ?: '0';
            }
            $mod_timestamp = $mod_time_cache[$container_id];
        }

        $translation_version = $this->get_translation_version();

        return "{$container_type}_{$container_id}_{$mod_timestamp}_{$translation_version}_{$content_hash}";
    }

    /**
     * Processes a string for ##slug## patterns with caching.
     */
    public function process_permalink_patterns(string $content): string
    {
        if (!str_contains($content, '##')) {
            return $content;
        }

        $cache_key = 'pattern_' . md5($content);

        return frl_cache_remember('permalinks', $cache_key, function () use ($content) {
            // This callback only runs on a cache miss.
            if (!preg_match_all('/##([^#]+)##/', $content, $matches)) {
                return $content;
            }

            $slugs_to_translate = array_unique(array_map('trim', $matches[1]));
            if (empty($slugs_to_translate)) {
                return $content;
            }

            $translated_permalinks = $this->get_translation_batch_permalinks($slugs_to_translate);

            return preg_replace_callback('/##([^#]+)##/', function ($match) use ($translated_permalinks) {
                $original = trim($match[1]);
                return $translated_permalinks[$original] ?? '#';
            }, $content);
        });
    }

    private function attempt_string_translation(string $string, string $language): ?string {
        if ($this->is_multilingual('pll_translate_string')) {
            $translation = pll_translate_string($string, $language);
            if ($translation !== $string) {
                return $translation;
            }
        }
        return null;
    }
}
// =================================================================
// BCompatibility Helper Functions
// =================================================================

function frl_is_multilingual(?string $function_name = null): bool
{
    return Frl_Translation_Service::get_instance()->is_multilingual($function_name);
}

function frl_get_language(?int $id = null, string $type = 'post'): string
{
    if ($id === null) {
        return Frl_Translation_Service::get_instance()->get_language();
    }
    return Frl_Translation_Service::get_instance()->get_object_language($id, $type);
}

function frl_get_default_language(): string
{
    return Frl_Translation_Service::get_instance()->get_default_language();
}

function frl_get_active_languages(): array
{
    return Frl_Translation_Service::get_instance()->get_active_languages();
}

function frl_get_translation(string $string, ?string $lang = null): string
{
    return Frl_Translation_Service::get_instance()->get_translation($string, $lang);
}

function frl_get_translation_block(string $block_content, array $block): string
{
    return Frl_Translation_Service::get_instance()->get_translation_block($block_content, $block);
}

/**
 * Mirror of frl_get_translation for permalinks: translate a single slug to a permalink.
 */
function frl_get_translation_permalink(string $slug, ?string $language = null): string
{
    $map = frl_get_translation_batch_permalinks([$slug], $language);
    return $map[$slug] ?? '#';
}

function frl_get_translation_batch_permalinks(array $slugs, ?string $language = null): array
{
    return Frl_Translation_Service::get_instance()->get_translation_batch_permalinks($slugs, $language);
}

function frl_process_permalink_patterns(string $content): string
{
    return Frl_Translation_Service::get_instance()->process_permalink_patterns($content);
}

function frl_get_post_translations(int $post_id): array
{
    return Frl_Translation_Service::get_instance()->get_post_translations($post_id);
}

function frl_get_term_translations(int $term_id): array
{
    return Frl_Translation_Service::get_instance()->get_term_translations($term_id);
}
