<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * functions-translation-helpers.php - Global helper functions for translation.
 *
 * These functions provide a procedural API for the Frl_Translation_Service,
 * ensuring backward compatibility and ease of use across the plugin.
 */

/**
 * Central guard to check if the translation system is active and enabled.
 *
 * @return bool True if a multilingual plugin is active AND the translator is not disabled in settings.
 */
function frl_translator_is_enabled(): bool
{
    if (!frl_is_multilingual_active()) {
        return false;
    }
    if (frl_get_option('disable_translator')) {
        return false;
    }
    if (!class_exists('Frl_Translation_Service')) {
        return false;
    }
    return true;
}

/**
 * Checks for multilingual function availability.
 *
 * @param string|null $function_name Optional function name to check for existence.
 * @return bool True if multilingual capabilities are active and available.
 */
function frl_is_multilingual(?string $function_name = null): bool
{
    if (!frl_translator_is_enabled()) {
        return false;
    }
    return Frl_Translation_Service::get_instance()->is_multilingual($function_name);
}

/**
 * Get current language for the request or a specific object.
 *
 * @param int|null    $id   Optional object ID (post or term).
 * @param string      $type Object type ('post' or 'term'). Defaults to 'post'.
 * @return string Language code (e.g., 'en', 'it').
 */
function frl_get_language(?int $id = null, string $type = 'post'): string
{
    if (!frl_translator_is_enabled()) {
        return 'en';
    }
    if ($id === null) {
        $language = Frl_Translation_Service::get_instance()->get_language();
        return !empty($language) ? $language : 'en';
    }
    return Frl_Translation_Service::get_instance()->get_object_language($id, $type);
}

/**
 * Get the default site language.
 *
 * @return string Default language code.
 */
function frl_get_default_language(): string
{
    if (!frl_translator_is_enabled()) {
        return 'en';
    }
    return Frl_Translation_Service::get_instance()->get_default_language();
}

/**
 * Get all active site languages.
 *
 * @return array List of active language codes.
 */
function frl_get_active_languages(): array
{
    if (!frl_translator_is_enabled()) {
        return ['en'];
    }
    return Frl_Translation_Service::get_instance()->get_active_languages();
}

/**
 * Get a string's translation.
 *
 * @param string      $string The string to translate.
 * @param string|null $lang    Optional target language.
 * @return string The translated string or the original if no translation is found.
 */
function frl_get_translation(string $string, ?string $lang = null): string
{
    if (!frl_translator_is_enabled()) {
        return $string;
    }
    return Frl_Translation_Service::get_instance()->get_translation($string, $lang);
}

/**
 * Get a block's translation, processing delimiters and caching the result.
 *
 * @param string $block_content The content of the block.
 * @param array  $block         The block attributes and context.
 * @return string The translated block content.
 */
function frl_get_translation_block(string $block_content, array $block): string
{
    /**
     * Three-tier guard architecture:
     * 1. Fully disabled: Zero overhead, return content as-is.
     * 2. Polylang off but not disabled: Safe Mode. Strip delimiters to keep site usable.
     * 3. Polylang active: Full translation processing.
     */
    if ( frl_get_option('disable_translator') ) {
        return $block_content;
    }
    elseif ( !frl_is_multilingual_active() ) {

        // Safe Mode: Lightweight processing to remove delimiters without booting the full service.
        $t_start = FRL_TRANSLATOR_DELIMITER_TEXT['start'];
        $l_start = FRL_TRANSLATOR_DELIMITER_LINK['start'];

        if (!str_contains($block_content, $t_start) && !str_contains($block_content, $l_start)) {
            return $block_content;
        }

        $content_hash = md5($block_content);

        // Leverage Cache Manager for both runtime (static) and persistent caching.
        return frl_cache_remember('safe_blocks', $content_hash, function () use ($block_content) {
            $t_start = preg_quote(FRL_TRANSLATOR_DELIMITER_TEXT['start'], '/');
            $t_end   = preg_quote(FRL_TRANSLATOR_DELIMITER_TEXT['end'], '/');
            $l_start = preg_quote(FRL_TRANSLATOR_DELIMITER_LINK['start'], '/');
            $l_end   = preg_quote(FRL_TRANSLATOR_DELIMITER_LINK['end'], '/');

            // 1. Strip {{String}} -> String
            $content = preg_replace("/{$t_start}(.*?){$t_end}/", '$1', $block_content);
            // 2. Replace ##slug## -> #
            $content = preg_replace("/{$l_start}(.*?){$l_end}/", '#', $content);
            return $content;
        }, DAY_IN_SECONDS);
    }

    return Frl_Translation_Service::get_instance()->get_translation_block($block_content, $block);
}

/**
 * Mirror of frl_get_translation for permalinks: translate a single slug to a permalink.
 *
 * @param string      $slug     The slug to translate.
 * @param string|null $language Optional target language.
 * @return string The translated permalink or '#' if not found.
 */
function frl_get_translation_permalink(string $slug, ?string $language = null): string
{
    if (!frl_translator_is_enabled()) {
        return '#';
    }
    $map = frl_get_translation_batch_permalinks([$slug], $language);
    return $map[$slug] ?? '#';
}

/**
 * Get translations for a batch of permalink slugs.
 *
 * @param array       $slugs    List of slugs to translate.
 * @param string|null $language Optional target language.
 * @return array Map of original slugs to translated permalinks.
 */
function frl_get_translation_batch_permalinks(array $slugs, ?string $language = null): array
{
    if (!frl_translator_is_enabled()) {
        return array_fill_keys($slugs, '#');
    }
    return Frl_Translation_Service::get_instance()->get_translation_batch_permalinks($slugs, $language);
}

/**
 * Processes a string for ##slug## patterns with caching.
 *
 * @param string $content The content to process.
 * @return string The content with translated permalinks.
 */
function frl_process_permalink_patterns(string $content): string
{
    if (!frl_translator_is_enabled()) {
        // Safe Mode: Replace ##slug## with # to avoid showing raw tokens.
        $l_start = FRL_TRANSLATOR_DELIMITER_LINK['start'];
        if (!str_contains($content, $l_start)) {
            return $content;
        }
        $l_start = preg_quote($l_start, '/');
        $l_end   = preg_quote(FRL_TRANSLATOR_DELIMITER_LINK['end'], '/');
        return preg_replace("/{$l_start}(.*?){$l_end}/", '#', $content);
    }
    return Frl_Translation_Service::get_instance()->process_permalink_patterns($content);
}

/**
 * Get post translations IDs for a given post ID.
 *
 * @param int $post_id Post ID.
 * @return array Language-keyed map of post IDs.
 */
function frl_get_post_translations(int $post_id): array
{
    if (!frl_translator_is_enabled()) {
        return ['en' => $post_id];
    }
    return Frl_Translation_Service::get_instance()->get_post_translations($post_id);
}

/**
 * Get term translations IDs for a given term ID.
 *
 * @param int $term_id Term ID.
 * @return array Language-keyed map of term IDs.
 */
function frl_get_term_translations(int $term_id): array
{
    if (!frl_translator_is_enabled()) {
        return ['en' => $term_id];
    }
    return Frl_Translation_Service::get_instance()->get_term_translations($term_id);
}
