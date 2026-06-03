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

    /**
     * Set the default language in the database.
     *
     * Used by the subdomain adapter to sync the DB default to the
     * subdomain's language on first visit.
     *
     * @param string $lang 2-letter language code.
     * @return bool True if the default language was updated, false otherwise.
     */
    public function set_default_language(string $lang): bool;

    /**
     * Flush the translation plugin's internal language cache.
     *
     * Note: When `frl_flush_rewrite_rules()` is called after `set_default_language()`,
     * this method is typically not needed because Polylang hooks `update_option_permalink_structure`
     * to call `clean_languages_cache()` automatically (see polylang/src/model.php:119).
     * This method is provided for callers that update the default language without
     * triggering a rewrite rules flush.
     *
     * @return void
     */
    public function flush_cache(): void;
}
