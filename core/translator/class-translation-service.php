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
 *
 * ## Caching Strategy
 *
 * Two categories, two mechanisms:
 *
 * - **Request-immutable values** (language, default language, active languages,
 *   object language, multilingual checks): stored in declared instance properties
 *   (e.g., `private ?string $language_cache`). These never change during a request;
 *   persistent caching would add serialization overhead for zero benefit.
 *
 * - **Persistent values** (translations, permalinks, block translations, post/term
 *   translation maps): stored via `frl_cache_remember()` with appropriate groups
 *   (`translations`, `blocks`, `permalinks`, `postdata`). These involve expensive
 *   operations (DB queries, Polylang API calls) and are stable until content changes.
 *
 * Helper functions in `functions-translator-helpers.php` are thin wrappers with
 * NO caching of their own — all caching lives here in the service.
 */

final class Frl_Translation_Service
{
    private static ?self $instance = null;
    private Frl_Translation_Adapter_Interface $adapter;

    // Configuration properties for better readability and avoid array access.
    private string $prefix;
    private string $name;
    private string $delimiter_text_start;
    private string $delimiter_text_end;
    private string $delimiter_link_start;
    private string $delimiter_link_end;

    // Caches to hold state within a single request, preventing redundant lookups.
    private array $is_multilingual_cache = [];
    private ?string $language_cache = null;
    private ?string $default_language_cache = null;
    private ?string $source_language_cache = null;
    private ?array $active_languages_cache = null;
    private array $object_language_cache = [];
    private array $batch_strings_cache = [];
    private array $batch_permalinks_cache = [];
    private array $string_registration_queue = [];
    private bool $shutdown_hook_added = false;

    // Private constructor to enforce the singleton pattern.
    private function __construct()
    {
        // Adapter files are loaded early in translator.php so the class
        // is always available regardless of whether the service is instantiated.

        // Initialize configuration properties from constants.
        $this->prefix              = FRL_PREFIX;
        $this->name                = FRL_NAME;
        $this->delimiter_text_start = FRL_TRANSLATOR_DELIMITER_TEXT['start'];
        $this->delimiter_text_end   = FRL_TRANSLATOR_DELIMITER_TEXT['end'];
        $this->delimiter_link_start = FRL_TRANSLATOR_DELIMITER_LINK['start'];
        $this->delimiter_link_end   = FRL_TRANSLATOR_DELIMITER_LINK['end'];

        // Default to Polylang adapter. In a more complex system, this could be determined by a config.
        $this->adapter = new Frl_Polylang_Adapter();
    }

    /**
     * Get the singleton instance of the service.
     *
     * @return self
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
     *
     * @return bool
     */
    public function has_multilingual_plugin(): bool
    {
        return (defined('ICL_SITEPRESS_VERSION') || function_exists('pll_the_languages') || defined('PLL'));
    }

    /**
     * Checks for multilingual function availability.
     *
     * @param string|null $function_name Optional function name to check for existence.
     * @return bool
     */
    public function is_multilingual(?string $function_name = null): bool
    {
        $check_type = $function_name ?? 'all';

        if (isset($this->is_multilingual_cache[$check_type])) {
            return $this->is_multilingual_cache[$check_type];
        }

        if ($function_name !== null) {
            $check_result = function_exists($function_name);

           $log_missing = defined('FRL_TRANSLATOR_LOG_MISSING_TRANSLATION') && FRL_TRANSLATOR_LOG_MISSING_TRANSLATION;
           if ($log_missing && $this->has_multilingual_plugin() && !$check_result) {
                frl_log('Multilingual function ' . $function_name . ' does not exist');
            }
        } else {
            $check_result = $this->has_multilingual_plugin();
        }

        $this->is_multilingual_cache[$check_type] = $check_result;
        return $check_result;
    }

    /**
     * Get current language.
     *
     * @return string
     */
    public function get_language(): string
    {
        if ($this->language_cache === null) {
            // First, get the language from the adapter (which calls Polylang's
            // pll_current_language()). On subdomains this correctly returns 'ru'.
            $language = $this->adapter->get_current_language();

            // Fallback: if the adapter returned nothing (e.g. Polylang not ready
            // on early AJAX requests), try to derive language from the query var.
            // IMPORTANT: this must NOT overwrite a valid adapter result, otherwise
            // the subdomain adapter's language override is silently discarded.
            if (empty($language)) {
                global $wp_query;
                if (isset($wp_query->query['lang']) && is_string($wp_query->query['lang']) && strlen($wp_query->query['lang']) === 2) {
                    $language = $wp_query->query['lang'];
                }
            }

            $this->language_cache = $language;
        }

        // Ensure we never return an empty string (e.g. from Polylang on AJAX)
        if (empty($this->language_cache)) {
            $this->language_cache = frl_get_default_language_fallback();
        }

        return $this->language_cache;
    }

    /**
     * Get default language.
     *
     * @return string
     */
    public function get_default_language(): string
    {
        if ($this->default_language_cache === null) {
            $this->default_language_cache = $this->adapter->get_default_language();
        }
        return $this->default_language_cache;
    }

    /**
     * Get the source language — the language in which strings are authored.
     *
     * This is an architectural constant (FRL_TRANSLATOR_SOURCE_LANG, default 'en'),
     * NOT derived from Polylang's default language. On the main domain they happen
     * to match, but on subdomains Polylang's default is overridden (e.g. to 'ru')
     * while content is still authored in English.
     *
     * The performance guard in get_translation_batch_strings() skips translation
     * when current language equals source language. Keeping source language as
     * a fixed constant ensures translation always runs on non-English subdomains
     * regardless of Polylang's internal default.
     *
     * @return string
     */
    public function get_source_language(): string
    {
        if ($this->source_language_cache === null) {
            $this->source_language_cache = FRL_TRANSLATOR_SOURCE_LANG;
        }
        return $this->source_language_cache;
    }

    /**
     * Get active site languages.
     *
     * @return array
     */
    public function get_active_languages(): array
    {
        if ($this->active_languages_cache === null) {
            $this->active_languages_cache = $this->adapter->get_active_languages();
        }
        return apply_filters('frl_active_languages', $this->active_languages_cache);
    }


    /**
     * Get a string's translation.
     *
     * @param string $string The string to translate.
     * @param string|null $lang Optional target language.
     * @return string
     */
    public function get_translation(string $string, ?string $lang = null): string
    {
        if (empty($string)) return '';

        $this->queue_string_registration([$string]);
        $language = $lang ?: $this->get_language();

        $version = $this->get_translation_version();
        $cache_key = substr(md5($string . '_' . $version), 0, 12);

        return frl_cache_remember('translations', $cache_key, function () use ($string, $language) {
            $translation = $this->adapter->translate_string($string, $language);
            if ($translation !== null) {
                return frl_validate_html_fragment($translation);
            }
            return $string;
        });
    }

    /**
     * Get a block's translation.
     *
     * @param string $block_content The content of the block.
     * @param array $block The block attributes and context.
     * @return string
     */
    public function get_translation_block(string $block_content, array $block): string
    {
        if (empty($block_content)) {
            return '';
        }

        // Check for the translation class. If not present, no processing is needed.
        $prefix = $this->prefix;
        $has_translate_class = (!empty($block['attrs']['className']) && str_contains($block['attrs']['className'], $prefix . '-translate')) ||
            (!empty($block['attrs']['css_class']) && str_contains($block['attrs']['css_class'], $prefix . '-translate'));

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
            $tStart = preg_quote($this->delimiter_text_start, '/');
            $tEnd   = preg_quote($this->delimiter_text_end, '/');
            $text_pattern = "/{$tStart}(.*?){$tEnd}/";

            // Pre-load exclude map for O(1) lookup during registration gathering.
            $exclude = defined('FRL_TRANSLATOR_EXCLUDE') ? FRL_TRANSLATOR_EXCLUDE : [];

            if (preg_match_all($text_pattern, $block_content, $string_matches)) {
                $all_tokens = array_unique(array_map('trim', $string_matches[1]));
                foreach ($all_tokens as $token) {
                    if (!isset($exclude[$token])) {
                        $strings_to_register[] = $token;
                    }
                }
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
     *
     * @param int $id Post ID.
     * @param string $lang Target language.
     * @return string
     */
    public function get_translation_permalink(int $id, string $lang): string
    {
        // Include $lang in key because the target language may differ from request language added by cache manager.
        $cache_key = "post_{$id}_{$lang}";

        return frl_cache_remember('permalinks', $cache_key, function () use ($id, $lang) {
            $source_language = $this->get_source_language();

            // If no multilingual plugin or we want the source language, get direct permalink.
            if (!$this->has_multilingual_plugin() || $lang === $source_language) {
                return get_permalink($id) ?: '#';
            }

            // Get the ID of the translated post.
            $translated_id = $this->adapter->get_post_translation($id, $lang);

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
     *
     * @param string $slug Term slug.
     * @param string $language Target language.
     * @param string $taxonomy Taxonomy name.
     * @return string
     */
    public function get_translation_term_permalink(string $slug, string $language, string $taxonomy = 'category'): string
    {
            // Include $lang in key because the target language may differ from request language added by cache manager.
        $cache_key = "term_" . sanitize_key($slug) . "_{$taxonomy}_{$language}";
        return frl_cache_remember(
            'permalinks',
            $cache_key,
            function () use ($slug, $language, $taxonomy) {
                $source_language = $this->get_source_language();

                // For source language, try direct term lookup
                if ($language === $source_language) {
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
                $translated_id = $this->adapter->get_object_id($term_id, $taxonomy, true, $language);
                $translated_term = get_term($translated_id);

                $link = get_term_link($translated_term) ?? '#';
                return $link;
            }
        );
    }

    /**
     * Get translations for a batch of strings.
     *
     * @param array $strings List of strings to translate.
     * @return array Map of original strings to translations.
     */
    public function get_translation_batch_strings(array $strings): array
    {
        if (empty($strings)) {
            return [];
        }

        $language = $this->get_language();

        // Early return if current language is the source language — block text
        // tokens are always authored in the source language, so no translation
        // is needed. The source language is filterable via frl_source_language
        // (Subdomain Adapter pins it to 'en' on RU subdomains).
        if ($language === $this->get_source_language()) {
            return array_combine($strings, $strings);
        }

        // Create a consistent cache key with sorted strings (language-scoped)
        $sorted_strings = $strings;
        sort($sorted_strings);
        $batch_key = $language . '|' . implode('|', $sorted_strings);

        // Check if we already processed this exact batch in this request
        if (isset($this->batch_strings_cache[$batch_key])) {
            return $this->batch_strings_cache[$batch_key];
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
        $this->batch_strings_cache[$batch_key] = $translations;

        return $translations;
    }

    /**
     * Get translations for a batch of permalink slugs.
     *
     * @param array $slugs List of slugs to translate.
     * @param string|null $language Optional target language.
     * @return array Map of original slugs to translated permalinks.
     */
    public function get_translation_batch_permalinks(array $slugs, ?string $language = null): array
    {
        if (empty($slugs)) {
            return [];
        }

        $language = $language ?: $this->get_language();
        $batch_key = $language . '|' . implode('|', $slugs);

        if (isset($this->batch_permalinks_cache[$batch_key])) {
            return $this->batch_permalinks_cache[$batch_key];
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

        $this->batch_permalinks_cache[$batch_key] = $permalinks;

        return $permalinks;
    }

    /**
     * Register a string for translation.
     *
     * @param string $string The string to register.
     * @return void
     */
    public function register_translation(string $string): void
    {
        if (empty($string) || !$this->is_multilingual('icl_register_string')) {
            return;
        }

        // Heuristic: If the string is already translated in the current language,
        // we assume it is registered and bail out to prevent redundant calls.
        $current_language = $this->get_language();
        if (empty($current_language)) {
            return;
        }
        $source_language = $this->get_source_language();

        if ($current_language !== $source_language) {
            $translation = $this->adapter->translate_string($string, $current_language);
            if ($translation !== null) {
                return;
            }
        }

        // Create readable identifier: lowercase, underscores, max 64 chars
        $identifier = str_replace('-', '_', sanitize_title($string));
        if (strlen($identifier) > 64) {
            $identifier = substr($identifier, 0, 60) . '_' . substr(md5($string), 0, 3);
        }
        $domain = $this->name;
        $this->adapter->register_string($domain, $identifier, $string);
    }

    /**
     * Queues strings for registration at shutdown.
     *
     * @param array $strings List of strings to queue.
     * @return void
     */
    public function queue_string_registration(array $strings): void
    {
        // Filter out any strings that are already in the queue for this request.
        $newly_found_strings = array_diff($strings, array_keys($this->string_registration_queue));

        if (!empty($newly_found_strings)) {
            // Add new strings to the request's batch.
            foreach ($newly_found_strings as $new_str) {
                if (count($this->string_registration_queue) >= FRL_TRANSLATOR_MAX_QUEUE_SIZE) {
                    break;
                }
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
     *
     * @return void
     */
    public function process_string_registration_queue(): void
    {
        if (empty($this->string_registration_queue)) return;
        $source_lang = $this->get_source_language();
        $original_lang = $this->get_language();

        // Polylang registers strings in the current language.
        // We must force the source language context to ensure strings are registered as originals.
        if (function_exists('pll_set_current_language')) {
            pll_set_current_language($source_lang);
        }

        foreach (array_keys($this->string_registration_queue) as $string) {
            $this->register_translation($string);
        }

        if (function_exists('pll_set_current_language')) {
            pll_set_current_language($original_lang);
        }

        $this->string_registration_queue = [];
    }

    /**
     * Get post translations IDs for a given post ID.
     *
     * @param int $post_id Post ID.
     * @return array Language-keyed map of post IDs.
     */
    public function get_post_translations(int $post_id): array
    {
        if ($post_id <= 0) return [];
        return frl_cache_remember('postdata', "post_{$post_id}_translations", function () use ($post_id) {
            $translations = $this->adapter->get_post_translations($post_id);
            return $translations;
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
            return $this->adapter->get_term_translations($term_id);
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
        $key = "{$type}:{$id}";
        if (array_key_exists($key, $this->object_language_cache)) {
            return $this->object_language_cache[$key];
        }
        if ($type === 'term') {
            return $this->object_language_cache[$key] = $this->detect_term_language($id);
        }
        return $this->object_language_cache[$key] = $this->detect_post_language($id);
    }

    /**
     * Detect language for a specific post ID.
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function detect_post_language(int $post_id): string
    {
        $lang = $this->adapter->get_post_language($post_id);
        if ($lang) {
            return $lang;
        }

        // Fallback to global language
        return $this->get_language();
    }

    /**
     * Detect language for a specific term ID.
     *
     * @param int $term_id Term ID.
     * @return string
     */
    private function detect_term_language(int $term_id): string
    {
        $lang = $this->adapter->get_term_language($term_id);
        if ($lang) {
            return $lang;
        }

        // Fallback to global language
        return $this->get_language();
    }

    /**
     * Get the home URL for the current or specified language.
     *
     * @param string|null $language Optional target language code.
     * @return string The home URL for the language.
     */
    public function get_home_url(?string $language = null): string
    {
        $lang = $language ?: $this->get_language();
        return $this->adapter->get_home_url($lang);
    }

    /**
     * Get the global translation version.
     *
     * @return int
     */
    public function get_translation_version(): int
    {
        return (int)frl_get_option('translation_version') ?: 1;
    }

    /**
     * Efficiently processes a string for all placeholder patterns ({{...}} and ##...##).
     *
     * @param string $content The content to process.
     * @return string
     */
    private function _process_all_patterns(string $content): string
    {
        // Use a single regex to find all placeholder types in one pass.
        $tStart = preg_quote($this->delimiter_text_start, '/');
        $tEnd   = preg_quote($this->delimiter_text_end, '/');
        $lStart = preg_quote($this->delimiter_link_start, '/');
        $lEnd   = preg_quote($this->delimiter_link_end, '/');

        $combined_pattern = "/{$tStart}(.*?){$tEnd}|{$lStart}(.*?){$lEnd}/";

        if (!preg_match_all($combined_pattern, $content, $matches, PREG_SET_ORDER)) {
            return $content;
        }

        $strings_to_translate = [];
        $permalinks_to_translate = [];

        // Pre-load exclude map for O(1) lookup.
        $exclude = defined('FRL_TRANSLATOR_EXCLUDE') ? FRL_TRANSLATOR_EXCLUDE : [];

        // Sort matches into batches for efficient lookup.
        foreach ($matches as $match) {
            if (!empty($match[1])) {
                $token = trim($match[1]);
                if (!isset($exclude[$token])) {
                    $strings_to_translate[] = $token;
                }
            } elseif (!empty($match[2])) {
                $permalinks_to_translate[] = trim($match[2]);
            }
        }

        // Fetch all translations up front.
        $translated_strings = !empty($strings_to_translate) ? $this->get_translation_batch_strings(array_unique($strings_to_translate)) : [];
        $translated_permalinks = !empty($permalinks_to_translate) ? $this->get_translation_batch_permalinks(array_unique($permalinks_to_translate)) : [];

        // Use a single replace callback for performance.
        $tStart = preg_quote($this->delimiter_text_start, '/');
        $tEnd   = preg_quote($this->delimiter_text_end, '/');
        $lStart = preg_quote($this->delimiter_link_start, '/');
        $lEnd   = preg_quote($this->delimiter_link_end, '/');
        $combined_pattern = "/{$tStart}(.*?){$tEnd}|{$lStart}(.*?){$lEnd}/";

        return preg_replace_callback($combined_pattern, function ($match) use ($translated_strings, $translated_permalinks, $exclude) {
            if (!empty($match[1])) {
                $original = trim($match[1]);
                // Excluded tokens: pass through verbatim (including delimiters), no translation.
                if (isset($exclude[$original])) {
                    return $match[0];
                }
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
     *
     * @param array $block The block attributes and context.
     * @param string $content_hash Hash of the normalized content.
     * @return string
     */
    private function generate_block_cache_key(array $block, string $content_hash): string
    {
        static $mod_time_cache = [];
        global $post;

        $container_id = 0;
        $container_type = 'general';
        $mod_timestamp = '0';

        if (!empty($block['context']['postId'])) {
            $context_post_id = (int) absint($block['context']['postId']);
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
            $ref_id = (int) absint($block['attrs']['ref']);
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
     *
     * @param string $content The content to process.
     * @return string
     */
    public function process_permalink_patterns(string $content): string
    {
        $lStart = $this->delimiter_link_start;
        if (!str_contains($content, $lStart)) {
            return $content;
        }

        $cache_key = 'pattern_' . md5($content);

        return frl_cache_remember('permalinks', $cache_key, function () use ($content) {
            // This callback only runs on a cache miss.
            $lStart = preg_quote($this->delimiter_link_start, '/');
            $lEnd   = preg_quote($this->delimiter_link_end, '/');
            $link_pattern = "/{$lStart}(.*?){$lEnd}/";

            if (!preg_match_all($link_pattern, $content, $matches)) {
                return $content;
            }

            $slugs_to_translate = array_unique(array_map('trim', $matches[1]));
            if (empty($slugs_to_translate)) {
                return $content;
            }

            $translated_permalinks = $this->get_translation_batch_permalinks($slugs_to_translate);

            $lStart = preg_quote($this->delimiter_link_start, '/');
            $lEnd   = preg_quote($this->delimiter_link_end, '/');
            $link_pattern = "/{$lStart}(.*?){$lEnd}/";

            return preg_replace_callback($link_pattern, function ($match) use ($translated_permalinks) {
                $original = trim($match[1]);
                return $translated_permalinks[$original] ?? '#';
            }, $content);
        });
    }
}
