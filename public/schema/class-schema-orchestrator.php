<?php
/**
 * Schema Orchestrator
 *
 * Single output point for all JSON-LD structured data.
 *
 * Pipeline: Context Detection → Schema Selection → Build → Enrich → Merge → Output.
 * No external dependencies — all schemas are generated from local data files.
 *
 * @package Fralenuvole
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema orchestrator — single source of truth for JSON-LD output.
 *
 * One <script> tag in wp_head contains all structured data for the current request.
 * Schema types are loaded from individual definition files named after their
 * Schema.org type (e.g., Organization.php, Article.php).
 */
class Frl_Schema_Orchestrator {

	/**
	 * Context detector instance.
	 *
	 * @var Frl_Schema_Context
	 */
	private Frl_Schema_Context $context;

	/**
	 * Term property map from the builder.
	 *
	 * @var array
	 */
	private array $term_map;

	/**
	 * Person property map from the builder.
	 *
	 * @var array
	 */
	private array $person_map;

	/**
	 * Initialize the orchestrator and register the wp_head hook.
	 */
	public static function init(): void {
		$instance = new self();
		add_action( 'wp_head', array( $instance, 'output' ), 10, 0 );
	}

	/**
	 * Constructor — resolves dynamic mappings once per request.
	 */
	public function __construct() {
		$this->context    = new Frl_Schema_Context();
		$this->term_map   = frl_schema_builder_get_term_map();
		$this->person_map = frl_schema_builder_get_person_map();
	}

	/**
	 * Output all schema blocks as a single <script> tag.
	 *
	 * Hooked to wp_head. Gated on schema_enabled option.
	 */
	public function output(): void {
		if ( ! frl_get_option( 'schema_enabled' ) ) {
			return;
		}

		if ( frl_is_admin() || frl_is_rest_api_request() || is_preview() || frl_is_cron_job_request() ) {
			return;
		}

		if ( function_exists( 'frl_is_already_running' ) && frl_is_already_running( __METHOD__ ) ) {
			return;
		}

		$schemas = $this->build_all();
		if ( empty( $schemas ) ) {
			return;
		}

		$output             = count( $schemas ) === 1 ? $schemas[0] : array( '@graph' => $schemas );
		$output['@context'] = 'https://schema.org';

		echo "\n" . '<script id="frl-schema" type="application/ld+json">' . "\n"
			. wp_json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. "\n" . '</script>' . "\n";
	}

	/**
	 * Build all schemas for the current request context.
	 *
	 * @return array Array of schema arrays (each with @type).
	 */
	private function build_all(): array {
		$schemas = array();

		// 1. Global schemas — cached per-language
		$schemas = array_merge( $schemas, $this->build_global() );

		// 2. Context-specific schemas
		$context = $this->context->get_context();

		if ( $context === 'singular' ) {
			$schemas = array_merge( $schemas, $this->build_singular() );
		}

		return $schemas;
	}

	/**
	 * Build global schemas from FRL_SCHEMA_GLOBAL_TYPES.
	 *
	 * Cached per-language + toggle state.
	 *
	 * @return array
	 */
	private function build_global(): array {
		$language  = frl_get_language();
		$version   = frl_get_option( 'translation_version' ) ?: 1;
		$toggles   = $this->get_toggle_hash();
		$cache_key = "schema_global_{$language}_{$version}_{$toggles}";

		return frl_cache_remember(
			'html',
			$cache_key,
			function () {
				$schemas = array();
				$types   = defined( 'FRL_SCHEMA_GLOBAL_TYPES' ) ? FRL_SCHEMA_GLOBAL_TYPES : array();
				foreach ( $types as $type ) {
					$def = $this->load_definition( $type );
					if ( $def !== null ) {
						$built = $this->build_single( $def, null );
						if ( $built !== null ) {
							$schemas[] = $built;
						}
					}
				}
				return $schemas;
			}
		);
	}

	/**
	 * Build schemas for the current singular post.
	 *
	 * Cached per-post with version-based invalidation.
	 *
	 * @return array
	 */
	private function build_singular(): array {
		$post_id   = $this->context->get_post_id();
		$post_type = $this->context->get_post_type();

		if ( ! $post_id || ! $post_type ) {
			return array();
		}

		return frl_cache_remember(
			'postdata',
			frl_generate_cache_key( 'post', (string) $post_id, 'schema', 'v' . frl_get_post_cache_version( $post_id ) ),
			function () use ( $post_id, $post_type ) {
				$schemas = array();
				$types   = $this->get_types_for_post_type( $post_type, $post_id );

				foreach ( $types as $type ) {
					$def = $this->load_definition( $type );
					if ( $def !== null ) {
						$built = $this->build_single( $def, $post_id );
						if ( $built !== null ) {
							$schemas[] = $built;
						}
					}
				}

				return $schemas;
			}
		);
	}

	/**
	 * Get schema types for a post type, including special page types.
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $post_id   Post ID.
	 * @return array Schema.org type names.
	 */
	private function get_types_for_post_type( string $post_type, int $post_id ): array {
		$types = array();

		if ( defined( 'FRL_SCHEMA_POST_TYPE_MAP' ) && isset( FRL_SCHEMA_POST_TYPE_MAP[ $post_type ] ) ) {
			$types = FRL_SCHEMA_POST_TYPE_MAP[ $post_type ];
		}

		// Special pages: check slug against FRL_SCHEMA_SPECIAL_PAGES
		if ( $post_type === 'page' && defined( 'FRL_SCHEMA_SPECIAL_PAGES' ) ) {
			$slug = get_post_field( 'post_name', $post_id );
			if ( isset( FRL_SCHEMA_SPECIAL_PAGES[ $slug ] ) ) {
				$types = array_merge( $types, FRL_SCHEMA_SPECIAL_PAGES[ $slug ] );
			}
		}

		return $types;
	}

	/**
	 * Build a short hash of all schema toggle option values.
	 *
	 * @return string Hash of current toggle states.
	 */
	private function get_toggle_hash(): string {
		$keys = array(
			'schema_organization',
			'schema_website',
			'schema_webpage',
			'schema_article',
			'schema_breadcrumb',
			'schema_service',
			'schema_profilepage',
			'schema_aboutpage',
			'schema_contactpage',
			'schema_howto',
		);

		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = frl_get_option( $key ) ? '1' : '0';
		}

		return md5( implode( ',', $values ) );
	}

	/**
	 * Load a single schema definition by Schema.org type name.
	 *
	 * @param string $type Schema.org type name (e.g., 'Organization', 'Article').
	 * @return array|null Definition array, or null if file not found.
	 */
	private function load_definition( string $type ): ?array {
		$file = frl_schema_get_data_file( "{$type}.php", 'definitions' );

		if ( ! file_exists( $file ) ) {
			return null;
		}

		$def = include $file;

		if ( ! is_array( $def ) ) {
			return null;
		}

		/**
		 * Filter a schema definition before building.
		 *
		 * @param array  $def  Schema definition array.
		 * @param string $type Schema.org type name.
		 */
		return apply_filters( 'frl_schema_definition', $def, $type );
	}

	/**
	 * Build and enrich a single schema from its definition.
	 *
	 * Pipeline:
	 *   1. Check _if gate
	 *   2. Build base schema via recursive builder
	 *   3. Merge static properties from resolver
	 *   4. Merge term properties from builder
	 *   5. Merge person properties from builder
	 *   6. Strip empty properties
	 *
	 * @param array    $def     Schema definition array.
	 * @param int|null $post_id Post ID for post-aware resolution, or null.
	 * @return array|null Built and enriched schema array, or null.
	 */
	private function build_single( array $def, ?int $post_id ): ?array {
		// _if gate
		if ( ! empty( $def['_if'] ) && ! frl_get_option( $def['_if'] ) ) {
			return null;
		}

		try {
			$schema = frl_schema_generator_build( $post_id ?? 0, $def );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'frl_log' ) ) {
				frl_log(
					'Schema build threw for {type}: {msg}',
					array(
						'type' => $def['@type'] ?? '?',
						'msg'  => $e->getMessage(),
					)
				);
			}
			return null;
		}

		if ( ! is_array( $schema ) || empty( $schema ) ) {
			return null;
		}

		$type = $schema['@type'] ?? '';

		// Translate keys matching FRL_SCHEMA_TRANSLATE_KEYS
		if ( function_exists( 'frl_schema_resolver_resolve' ) ) {
			$schema = frl_schema_resolver_resolve( $schema );
		}

		// Merge term properties (dynamic — resolved from WP taxonomy data)
		static $taxonomy_cache = array();
		if ( $post_id && ! empty( $this->term_map[ $type ] ) ) {
			$term_props = frl_schema_builder_build_term_properties( $post_id, $this->term_map[ $type ], $taxonomy_cache );
			if ( ! empty( $term_props ) ) {
				$schema = frl_schema_merge_properties( $schema, $term_props );
			}
		}

		// Merge person properties
		static $ref_cache = array();
		if ( $post_id && ! empty( $this->person_map[ $type ] ) ) {
			$person_props = frl_schema_builder_build_person_properties( $post_id, $this->person_map[ $type ], $ref_cache );
			if ( ! empty( $person_props ) ) {
				$schema = frl_schema_merge_properties( $schema, $person_props );
			}
		}

		// Final cleanup
		$schema = frl_schema_strip_empty( $schema );

		return ! empty( $schema ) ? $schema : null;
	}
}
