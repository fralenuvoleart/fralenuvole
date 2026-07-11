<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fralenuvole
 * functions-translator-helpers.php - Global helper functions for the Translator module.
 *
 * These functions provide a procedural API for the Frl_Translation_Service,
 * for ease of use across the plugin's own internal code. This is a
 * self-contained plugin with no external theme/plugin API contract — these
 * function names can be renamed/removed freely as long as every internal
 * caller (verified via grep) is updated in the same change.
 */

/**
 * Detect whether Polylang is the active multilingual plugin.
 *
 * @return bool
 */
function frl_is_polylang_active(): bool {
	static $is_active = null;
	if ( $is_active === null ) {
		$is_active = ( function_exists( 'pll_the_languages' ) || defined( 'PLL' ) );
	}
	return $is_active;
}

/**
 * Detect whether WPML is the active multilingual plugin.
 *
 * @return bool
 */
function frl_is_wpml_active(): bool {
	static $is_active = null;
	if ( $is_active === null ) {
		$is_active = defined( 'ICL_SITEPRESS_VERSION' );
	}
	return $is_active;
}

/**
 * FQCN of the translation adapter for the active plugin, or null if none
 * is implemented. Single source of truth: frl_is_multilingual_plugin_active()
 * and Frl_Translation_Service's adapter selection both derive from this —
 * adding a new adapter is a one-line change, here only.
 *
 * @return string|null
 */
function frl_get_translation_adapter_class(): ?string {
	if ( frl_is_polylang_active() ) {
		return 'Frl_Polylang_Adapter';
	}
	if ( frl_is_wpml_active() ) {
		return null; // No Frl_Wpml_Adapter yet.
	}
	return null;
}

/**
 * Whether this codebase has a working adapter for the active plugin.
 * Gatekeeper for loading the translator module (fralenuvole.php) and for
 * instantiating Frl_Translation_Service.
 *
 * @return bool
 */
function frl_is_multilingual_plugin_active(): bool {
	static $is_active = null;
	if ( $is_active === null ) {
		$is_active = ( frl_get_translation_adapter_class() !== null );
	}
	return $is_active;
}

/**
 * Central guard to check if the translation system is active and enabled.
 *
 * @return bool True if a multilingual plugin is active AND the translator is not disabled in settings.
 */
function frl_translator_is_enabled(): bool {
	return frl_is_multilingual_plugin_active() && ! frl_get_option( 'disable_translator' );
}

/**
 * Does a specific multilingual function exist right now?
 *
 * @param string $function_name Function name to check for existence.
 * @return bool
 */
function frl_multilingual_function_exists( string $function_name ): bool {
	if ( ! frl_translator_is_enabled() ) {
		return false;
	}
	return Frl_Translation_Service::get_instance()->multilingual_function_exists( $function_name );
}

/**
 * Get current language for the request or a specific object.
 *
 * @param int|null    $id   Optional object ID (post or term).
 * @param string      $type Object type ('post' or 'term'). Defaults to 'post'.
 * @return string Language code (e.g., 'en', 'it').
 */
function frl_get_language( ?int $id = null, string $type = 'post' ): string {
	if ( ! frl_translator_is_enabled() ) {
		return frl_get_default_language_fallback();
	}
	if ( $id === null ) {
		$language = Frl_Translation_Service::get_instance()->get_language();
		return ! empty( $language ) ? $language : frl_get_default_language_fallback();
	}
	return Frl_Translation_Service::get_instance()->get_object_language( $id, $type );
}

/**
 * Get the default site language.
 *
 * @return string Default language code.
 */
function frl_get_default_language(): string {
	if ( ! frl_translator_is_enabled() ) {
		return frl_get_default_language_fallback();
	}
	return Frl_Translation_Service::get_instance()->get_default_language();
}

/**
 * Get all active site languages.
 *
 * @return array List of active language codes.
 */
function frl_get_active_languages(): array {
	if ( ! frl_translator_is_enabled() ) {
		return frl_get_active_languages_fallback();
	}
	return Frl_Translation_Service::get_instance()->get_active_languages();
}

/**
 * Get a string's translation.
 *
 * @param string      $str The string to translate.
 * @param string|null $lang    Optional target language.
 * @return string The translated string or the original if no translation is found.
 */
function frl_get_translation( string $str, ?string $lang = null ): string {
	if ( ! frl_translator_is_enabled() ) {
		return $str;
	}
	return Frl_Translation_Service::get_instance()->get_translation( $str, $lang );
}

/**
 * Get a block's translation, processing delimiters and caching the result.
 *
 * @param string $block_content The content of the block.
 * @param array  $block         The block attributes and context.
 * @return string The translated block content.
 */
function frl_get_translation_block( string $block_content, array $block ): string {
	/**
	 * Three-tier guard architecture:
	 * 1. Fully disabled: Zero overhead, return content as-is.
	 * 2. Polylang off but not disabled: Safe Mode. Strip delimiters to keep site usable.
	 * 3. Polylang active: Full translation processing.
	 */
	if ( frl_get_option( 'disable_translator' ) ) {
		return $block_content;
	} elseif ( ! frl_is_multilingual_plugin_active() ) {

		// Safe Mode: Lightweight processing to remove delimiters without booting the full service.
		$t_start = FRL_TRANSLATOR_DELIMITER_TEXT['start'];
		$l_start = FRL_TRANSLATOR_DELIMITER_LINK['start'];

		if ( ! str_contains( $block_content, $t_start ) && ! str_contains( $block_content, $l_start ) ) {
			return $block_content;
		}

		// Request-level deduplication only. Persistent caching is intentionally omitted
		// because $block_content may contain per-request dynamic values (e.g. GeoDirectory
		// random IDs) that make md5-based keys unstable. A persistent cache with unstable
		// keys produces 0% hit rate and transient bloat on sites without object cache.
		static $safe_cache = array();
		$sig               = md5( $block_content );
		if ( isset( $safe_cache[ $sig ] ) ) {
			return $safe_cache[ $sig ];
		}

		$t_start = preg_quote( FRL_TRANSLATOR_DELIMITER_TEXT['start'], '/' );
		$t_end   = preg_quote( FRL_TRANSLATOR_DELIMITER_TEXT['end'], '/' );
		$l_start = preg_quote( FRL_TRANSLATOR_DELIMITER_LINK['start'], '/' );
		$l_end   = preg_quote( FRL_TRANSLATOR_DELIMITER_LINK['end'], '/' );

		// Strip delimiters in a single pass, honouring FRL_TRANSLATOR_EXCLUDE
		// so third-party placeholder syntax is left intact.
		$combined = "/{$t_start}(.*?){$t_end}|{$l_start}(.*?){$l_end}/";
		$content  = preg_replace_callback(
			$combined,
			function ( $m ) {
				if ( ! empty( $m[1] ) ) {
					$token = trim( $m[1] );
					// Excluded tokens: keep delimiters, leave untouched.
					return frl_is_token_match( $token ) ? $m[0] : $token;
				}
				if ( ! empty( $m[2] ) ) {
					// Permalink patterns: always strip to '#'.
					return '#';
				}
				return $m[0];
			},
			$block_content
		);

		$safe_cache[ $sig ] = $content;
		return $safe_cache[ $sig ];
	}

	return Frl_Translation_Service::get_instance()->get_translation_block( $block_content, $block );
}

/**
 * Mirror of frl_get_translation for permalinks: translate a single slug to a permalink.
 *
 * @param string      $slug     The slug to translate.
 * @param string|null $language Optional target language.
 * @return string The translated permalink or '#' if not found.
 */
function frl_get_translation_permalink( string $slug, ?string $language = null ): string {
	if ( ! frl_translator_is_enabled() ) {
		return '#';
	}
	$map = frl_get_translation_batch_permalinks( array( $slug ), $language );
	return $map[ $slug ] ?? '#';
}

/**
 * Get translations for a batch of permalink slugs.
 *
 * @param array       $slugs    List of slugs to translate.
 * @param string|null $language Optional target language.
 * @return array Map of original slugs to translated permalinks.
 */
function frl_get_translation_batch_permalinks( array $slugs, ?string $language = null ): array {
	if ( ! frl_translator_is_enabled() ) {
		return array_fill_keys( $slugs, '#' );
	}
	return Frl_Translation_Service::get_instance()->get_translation_batch_permalinks( $slugs, $language );
}

/**
 * Processes a string for ##slug## patterns with caching.
 *
 * @param string $content The content to process.
 * @return string The content with translated permalinks.
 */
function frl_process_permalink_patterns( string $content ): string {
	if ( ! frl_translator_is_enabled() ) {
		// Safe Mode: Replace ##slug## with # to avoid showing raw tokens.
		$l_start = FRL_TRANSLATOR_DELIMITER_LINK['start'];
		if ( ! str_contains( $content, $l_start ) ) {
			return $content;
		}
		$l_start = preg_quote( $l_start, '/' );
		$l_end   = preg_quote( FRL_TRANSLATOR_DELIMITER_LINK['end'], '/' );
		return preg_replace( "/{$l_start}(.*?){$l_end}/", '#', $content );
	}
	return Frl_Translation_Service::get_instance()->process_permalink_patterns( $content );
}

/**
 * Get post translations IDs for a given post ID.
 *
 * @param int $post_id Post ID.
 * @return array Language-keyed map of post IDs.
 */
function frl_get_post_translations( int $post_id ): array {
	if ( ! frl_translator_is_enabled() ) {
		$default_lang = frl_get_default_language_fallback();
		return array( $default_lang => $post_id );
	}
	return Frl_Translation_Service::get_instance()->get_post_translations( $post_id );
}

/**
 * Get term translations IDs for a given term ID.
 *
 * @param int $term_id Term ID.
 * @return array Language-keyed map of term IDs.
 */
function frl_get_term_translations( int $term_id ): array {
	if ( ! frl_translator_is_enabled() ) {
		$default_lang = frl_get_default_language_fallback();
		return array( $default_lang => $term_id );
	}
	return Frl_Translation_Service::get_instance()->get_term_translations( $term_id );
}

/**
 * Get default language via the adapter's fallback mechanism.
 *
 * Delegates to Frl_Polylang_Adapter when available (which encapsulates
 * the Polylang-specific DB read). Falls back to the module constant
 * when no adapter class exists (e.g., Polylang not installed).
 *
 * Used as fallback when the Translator service isn't enabled or
 * Polylang isn't fully initialized (e.g., during CLI/cron/early AJAX).
 *
 * @return string 2-letter language code (e.g., 'en', 'ru')
 */
function frl_get_default_language_fallback(): string {
	if ( class_exists( 'Frl_Polylang_Adapter' ) ) {
		$adapter = new Frl_Polylang_Adapter();
		return $adapter->get_default_language();
	}
	return FRL_TRANSLATOR_DEFAULT_LANG;
}

/**
 * Get active languages via the adapter's fallback mechanism.
 *
 * Delegates to Frl_Polylang_Adapter when available (which encapsulates
 * the Polylang-specific DB query). Falls back to the module constant
 * when no adapter class exists (e.g., Polylang not installed).
 *
 * Used as fallback when the Translator service isn't enabled or
 * Polylang's pll_languages_list() returns empty.
 *
 * @return array Array of 2-letter language codes (e.g., ['en', 'ru', 'ar', 'zh'])
 */
function frl_get_active_languages_fallback(): array {
	if ( class_exists( 'Frl_Polylang_Adapter' ) ) {
		$adapter = new Frl_Polylang_Adapter();
		return $adapter->get_active_languages();
	}
	return array( FRL_TRANSLATOR_DEFAULT_LANG );
}

/**
 * Get the home URL for the current or specified language.
 *
 * @param string|null $language Optional target language code.
 * @return string The home URL for the language.
 */
function frl_get_home_url( ?string $language = null ): string {
	if ( ! frl_translator_is_enabled() ) {
		return home_url();
	}
	return Frl_Translation_Service::get_instance()->get_home_url( $language );
}

/**
 * Get the language label for a given language slug.
 *
 * @param string $slug Language slug (e.g., 'en', 'ru').
 * @return string Label (e.g., 'English', 'Русский'), or empty string if not found.
 */
function frl_get_language_label( string $slug ): string {
	if ( ! frl_translator_is_enabled() ) {
		return '';
	}
	return Frl_Translation_Service::get_instance()->get_language_label( $slug );
}

/**
 * Get the language of a term.
 *
 * @param int $term_id Term ID.
 * @return string|null Language code or null if not assigned.
 */
function frl_get_term_language( int $term_id ): ?string {
	if ( ! frl_translator_is_enabled() ) {
		return null;
	}
	return Frl_Translation_Service::get_instance()->get_term_language( $term_id );
}

/**
 * Get the translated post ID.
 *
 * @param int    $post_id  Original post ID.
 * @param string $language Target language.
 * @return int|false Translated post ID or false.
 */
function frl_get_post_translation( int $post_id, string $language ) {
	if ( ! frl_translator_is_enabled() ) {
		return false;
	}
	return Frl_Translation_Service::get_instance()->get_post_translation( $post_id, $language );
}
