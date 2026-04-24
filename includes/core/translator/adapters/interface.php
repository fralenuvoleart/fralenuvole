<?php
/**
 * Translation Adapter Interface
 *
 * Defines the contract for translation providers (e.g., Polylang, WPML).
 */

if (!defined('ABSPATH')) exit;

interface Frl_Translation_Adapter_Interface
{
    /**
     * Get the current language.
     *
     * @return string
     */
    public function get_current_language(): string;

    /**
     * Get the default language.
     *
     * @return string
     */
    public function get_default_language(): string;

    /**
     * Get a list of active languages.
     *
     * @return array
     */
    public function get_active_languages(): array;

    /**
     * Translate a string.
     *
     * @param string $string The string to translate.
     * @param string $language Target language.
     * @return string|null The translation or null if not found.
     */
    public function translate_string(string $string, string $language): ?string;

    /**
     * Register a string for translation.
     *
     * @param string $domain The translation domain.
     * @param string $identifier The unique identifier for the string.
     * @param string $string The original string.
     * @return void
     */
    public function register_string(string $domain, string $identifier, string $string): void;

    /**
     * Get the translated post ID.
     *
     * @param int $post_id Original post ID.
     * @param string $language Target language.
     * @return int|false Translated post ID or false.
     */
    public function get_post_translation(int $post_id, string $language);

    /**
     * Get post translations for all languages.
     *
     * @param int $post_id Original post ID.
     * @return array Language-keyed map of post IDs.
     */
    public function get_post_translations(int $post_id): array;

    /**
     * Get term translations for all languages.
     *
     * @param int $term_id Original term ID.
     * @return array Language-keyed map of term IDs.
     */
    public function get_term_translations(int $term_id): array;

    /**
     * Get the language of a specific post.
     *
     * @param int $post_id Post ID.
     * @return string|null Language code or null.
     */
    public function get_post_language(int $post_id): ?string;

    /**
     * Get the language of a specific term.
     *
     * @param int $term_id Term ID.
     * @return string|null Language code or null.
     */
    public function get_term_language(int $term_id): ?string;

    /**
     * Get the translated object ID (e.g., for terms).
     *
     * @param int $id Original object ID.
     * @param string $taxonomy Taxonomy name.
     * @param bool $fallback Whether to fallback to original ID.
     * @param string $language Target language.
     * @return int
     */
    public function get_object_id(int $id, string $taxonomy, bool $fallback, string $language): int;
}
