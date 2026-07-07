<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/abstract-base-feature.php';

/**
 * CPT Archive Translation Feature
 *
 * Handles only archive URLs for a CPT under translated slug mappings.
 *
 * @package Fralenuvole
 * @since 3.0.0
 */
class Frl_CPT_Archive_Base_Translation_Feature extends Frl_Rewriter_Feature_Base {

	private string $cpt_slug;
	private array $mappings = array();
	/** Cached result of is_enabled() — computed once after load_configuration() runs. */
	private ?bool $enabled = null;

	/**
	 * Constructor
	 *
	 * @param string $cpt_slug The CPT slug to translate
	 */
	public function __construct( string $cpt_slug ) {
		$this->cpt_slug = $cpt_slug;
		// Property initialisation only. All hook registration happens in register_additional_hooks(),
		// which is called by the coordinator via register() at init priority 15.
	}

	protected function register_additional_hooks(): void {
		// Load configuration after CPTs are registered on 'init'.
		add_action( 'init', array( $this, 'load_configuration' ), 20, 0 );

		// Canonical redirect for CPT archive URLs to the translated base.
		add_action( 'template_redirect', array( $this, 'maybe_redirect_canonical' ), 11, 0 );

		// Publish this feature's URL prefixes so other features (e.g., taxonomy base removal)
		// can build their exclusion lists without coupling to FRL_REWRITER_MULTILINGUAL_CPT.
		add_filter( 'frl_rewriter_url_prefixes', array( $this, 'contribute_url_prefixes' ), 10, 1 );
	}

	/**
	 * Get a human-readable name for this feature (for logging/debugging)
	 *
	 * @return string The feature name
	 */
	public function get_name(): string {
		return "CPT Archive Base Translation ({$this->cpt_slug})";
	}

	/**
	 * Check if this feature is enabled via configuration
	 *
	 * @return bool True if the feature is enabled
	 */
	public function is_enabled(): bool {
		if ( $this->enabled === null ) {
			$pto           = get_post_type_object( $this->cpt_slug );
			$this->enabled = ! empty( $this->mappings ) && $pto !== null && (bool) $pto->has_archive;
		}
		return $this->enabled;
	}

	/**
	 * Load configuration from options
	 *
	 * @return void
	 */
	public function load_configuration(): void {
		$this->mappings = Frl_Rewriter_Path_Utils::parse_lang_mapping_option( "translate_cpt_slugs_{$this->cpt_slug}" );
		$this->enabled  = null; // Invalidate so is_enabled() recomputes with fresh mappings.
	}

	/**
	 * Generate rewrite rules for this feature only
	 *
	 * @return array Associative array of pattern => rewrite pairs
	 */
	public function generate_rules(): array {
		if ( ! $this->is_enabled() ) {
			return array();
		}
		$rules = array();
		foreach ( $this->mappings as $lang => $translated ) {
			$lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex( $lang, '#' );
			$base_esc = Frl_Rewriter_Path_Utils::escape_for_regex( $translated, '#' );

			// Archive with optional language prefix and pagination
			$rules[ "^{$lang_esc}/{$base_esc}/?$" ]                   = "index.php?post_type={$this->cpt_slug}&lang={$lang}";
			$rules[ "^{$lang_esc}/{$base_esc}/page/?([0-9]{1,})/?$" ] = "index.php?post_type={$this->cpt_slug}&paged=\$matches[1]&lang={$lang}";

			// Archive without language prefix
			$rules[ "^{$base_esc}/?$" ]                   = "index.php?post_type={$this->cpt_slug}&lang={$lang}";
			$rules[ "^{$base_esc}/page/?([0-9]{1,})/?$" ] = "index.php?post_type={$this->cpt_slug}&paged=\$matches[1]&lang={$lang}";
		}

		return $rules;
	}

	/**
	 * Check if this feature should handle the given request URI
	 *
	 * @param string $request_uri The raw request URI
	 * @return bool True if this feature should handle the request
	 */
	public function applies_to_request( string $request_uri ): bool {
		// Resolve the request and cache the result. If resolution is successful,
		// this method returns true without re-running the logic.
		return ! empty( $this->resolve_request( $request_uri ) );
	}

	/**
	 * Resolve the request URI to WordPress query variables
	 *
	 * @param string $request_uri The request URI to resolve
	 * @return array WordPress query variables or empty array if not handled
	 */
	public function resolve_request( string $request_uri ): array {
		// Keyed by cpt_slug to prevent cross-instance cache pollution when multiple CPTs are multilingual.
		static $cache = array();
		if ( isset( $cache[ $this->cpt_slug ][ $request_uri ] ) ) {
			return $cache[ $this->cpt_slug ][ $request_uri ];
		}

		if ( ! $this->is_enabled() ) {
			$cache[ $this->cpt_slug ][ $request_uri ] = array();
			return $cache[ $this->cpt_slug ][ $request_uri ];
		}

		$path = Frl_Rewriter_Path_Utils::extract_request_path( $request_uri );
		foreach ( $this->mappings as $lang => $translated ) {
			$lang_esc = Frl_Rewriter_Path_Utils::escape_for_regex( $lang, '#' );
			$base_esc = Frl_Rewriter_Path_Utils::escape_for_regex( $translated, '#' );

			$pagination = Frl_Rewriter_Path_Utils::parse_pagination( $path, "#^(?:{$lang_esc}/)?{$base_esc}/page/?([0-9]+)/?$#", null, 1 );
			if ( preg_match( "#^(?:{$lang_esc}/)?{$base_esc}/?$#", $pagination['path'] ) ) {
				$res = array(
					'post_type' => $this->cpt_slug,
					'lang'      => $lang,
				);
				if ( $pagination['paged'] > 1 ) {
					$res['paged'] = $pagination['paged'];
				}
				$cache[ $this->cpt_slug ][ $request_uri ] = $res;
				return $cache[ $this->cpt_slug ][ $request_uri ];
			}
		}
		$cache[ $this->cpt_slug ][ $request_uri ] = array();
		return $cache[ $this->cpt_slug ][ $request_uri ];
	}

	/**
	 * Contribute this CPT's translated base prefixes to the shared URL prefix registry.
	 * Consumed by Frl_Taxonomy_Base_Removal_Feature::get_configured_prefixes() via filter
	 * so that feature does not need to couple directly to FRL_REWRITER_MULTILINGUAL_CPT.
	 *
	 * Lazy-loads mappings so this callback is timing-safe even if fired before init:20
	 * (e.g., from compute_exclusion_patterns() called early by external code).
	 */
	public function contribute_url_prefixes( array $prefixes ): array {
		if ( empty( $this->mappings ) ) {
			$this->load_configuration();
		}

		foreach ( $this->mappings as $lang => $base ) {
			$prefixes[] = $base;
			$prefixes[] = "{$lang}/{$base}";
		}
		return $prefixes;
	}

	public function get_exclusion_patterns(): array {
		$patterns = array( preg_quote( $this->cpt_slug ) );
		$patterns = array_merge( $patterns, Frl_Rewriter_Path_Utils::get_lang_base_patterns( $this->mappings ) );
		return $patterns;
	}

	/**
	 * Canonicalize CPT archives to translated base.
	 *
	 * get_post_type_archive_link() returns the WordPress-default (untranslated) archive URL.
	 * Without Polylang translating it, comparing the current translated URL against that value
	 * would trigger a 301 redirect away from the correctly rewritten URL. We therefore build
	 * the canonical directly from $this->mappings using the current request's language var.
	 */
	public function maybe_redirect_canonical(): void {
		if ( ! $this->is_enabled() || ! post_type_exists( $this->cpt_slug ) ) {
			return;
		}

		if ( ! is_post_type_archive( $this->cpt_slug ) ) {
			return;
		}

		// Determine the language from the already-resolved query var (set by resolve_request()).
		$lang            = get_query_var( 'lang' ) ?: frl_get_default_language();
		$translated_base = $this->mappings[ $lang ] ?? null;
		if ( empty( $translated_base ) ) {
			return;
		}

		// Build the canonical directly from this feature's own configuration.
		$canonical = Frl_Rewriter_Path_Utils::collapse_slashes(
			home_url( "/{$lang}/{$translated_base}/" )
		);

		// Preserve pagination for canonical
		$paged = (int) get_query_var( 'paged' );
		if ( $paged > 1 ) {
			$canonical = Frl_Rewriter_Path_Utils::collapse_slashes( rtrim( $canonical, '/' ) . '/page/' . $paged . '/' );
		}

		Frl_Rewriter_Path_Utils::maybe_redirect_if_needed( $canonical );
	}
}
