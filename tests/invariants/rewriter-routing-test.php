<?php
/**
 * Rewriter routing invariants for the production URL shapes.
 *
 * Goal: no 404 on a valid URL. The plugin must resolve the URLs it owns, must
 * not hijack URLs that WordPress core or Polylang own, and must keep the
 * "post vs category under /blog/" and "team member vs child page under /about/"
 * disambiguation working.
 *
 * Fixture: tests/invariants/fixtures/live-rewriter.php (read from pbservices.ge).
 *
 * @package Fralenuvole
 */

require __DIR__ . '/lib/wp-stub.php';
require __DIR__ . '/lib/assert.php';

$frl_config = require __DIR__ . '/fixtures/live-rewriter.php';

require FRL_TEST_PLUGIN_DIR . 'core/rewriter/features/class-cpt-single-base-translation-feature.php';
require FRL_TEST_PLUGIN_DIR . 'core/rewriter/features/class-taxonomy-base-removal-feature.php';
require FRL_TEST_PLUGIN_DIR . 'core/rewriter/features/class-cpt-base-removal-feature.php';
require FRL_TEST_PLUGIN_DIR . 'includes/helpers/functions-slug-helpers.php';
require __DIR__ . '/lib/class-test-single-feature.php';
require __DIR__ . '/lib/class-test-tax-feature.php';

/**
 * Seed the live registry and options into the stub state.
 *
 * @param array $config Fixture.
 * @return void
 */
function frl_test_seed_live_config( array $config ): void {
	frl_test_reset();
	$state = &frl_test_state();

	$state['languages'] = $config['languages'];
	$state['language']  = $config['default_language'];

	foreach ( $config['post_types'] as $name => $args ) {
		$state['post_types'][ $name ] = (object) array_merge(
			array(
				'name'         => $name,
				'public'       => true,
				'hierarchical' => false,
				'has_archive'  => false,
				'query_var'    => $name,
				'rewrite'      => array( 'slug' => $name ),
			),
			$args
		);
	}

	foreach ( $config['taxonomies'] as $name => $args ) {
		$state['taxonomies'][ $name ] = (object) array_merge(
			array(
				'name'      => $name,
				'public'    => true,
				'query_var' => $name,
				'rewrite'   => array( 'slug' => $name ),
			),
			$args
		);
	}

	frl_test_set_option( 'permalink_structure', $config['permalink_structure'] );
	frl_test_set_option( 'category_base', $config['category_base'] );
	frl_test_set_option( 'tag_base', $config['tag_base'] );
	frl_test_set_option( 'frl_disable_comments', $config['disable_comments'] ? '1' : '0' );
	frl_test_set_option( 'frl_rewriter_remove_tax_base', implode( "\n", $config['remove_tax_base'] ) );
	frl_test_set_option( 'frl_rewriter_remove_cpt_base', implode( "\n", $config['remove_cpt_base'] ) );
	frl_test_set_option( 'frl_rewriter_translate_post_base', $config['translate_post_base'] );

	foreach ( $config['translate_cpt_slugs'] as $cpt => $mappings ) {
		$lines = array();
		foreach ( $mappings as $lang => $base ) {
			$lines[] = $lang . '|' . $base;
		}
		frl_test_set_option( 'frl_rewriter_translate_cpt_slugs_' . $cpt, implode( "\n", $lines ) );
	}
}

/**
 * Build a term object for the slug map.
 *
 * @param int    $id     Term ID.
 * @param string $slug   Slug.
 * @param string $name   Name.
 * @param int    $parent Parent ID.
 * @return object
 */
function frl_test_term( int $id, string $slug, string $name, int $parent_id = 0 ) {
	return (object) array(
		'term_id'  => $id,
		'slug'     => $slug,
		'name'     => $name,
		'parent'   => $parent_id,
		'taxonomy' => 'category',
	);
}

/**
 * Build a post-like object for the page/post maps.
 *
 * @param int    $id     Post ID.
 * @param string $type   Post type.
 * @param string $slug   Post name.
 * @param int    $parent Parent ID.
 * @param string $status Post status.
 * @return object
 */
function frl_test_post( int $id, string $type, string $slug, int $parent_id = 0, string $status = 'publish' ) {
	return (object) array(
		'ID'          => $id,
		'post_type'   => $type,
		'post_name'   => $slug,
		'post_parent' => $parent_id,
		'post_status' => $status,
		'post_title'  => $slug,
	);
}

frl_test_seed_live_config( $frl_config );
$state = &frl_test_state();

$single = new Frl_Test_Single_Feature( 'service' );
$single->seed_mappings( $frl_config['translate_cpt_slugs']['service'] );

$taxonomy = new Frl_Test_Tax_Feature();
$taxonomy->seed_slugs( $frl_config['remove_tax_base'] );

// ---------------------------------------------------------------------------
// 1. Single CPT: rules.
// ---------------------------------------------------------------------------
$single_rules = array_keys( $single->generate_rules() );
$en_rules     = array_values(
	array_filter(
		$single_rules,
		static function ( $pattern ) {
			return str_starts_with( $pattern, '^en/services/' );
		}
	)
);

frl_test_same( 3, count( $en_rules ), 'en service base has embed, comment-page and generic rules' );
frl_test_ok( str_contains( $en_rules[0], '/embed/' ), 'embed rule is registered first' );
frl_test_ok( str_contains( $en_rules[1], '/comment-page-' ), 'comment-page rule is registered second' );
frl_test_ok( str_contains( $en_rules[2], '(?!page/[0-9]+/?$)' ), 'generic single rule carries the page/N guard' );

$feed_rules = array_filter(
	$single_rules,
	static function ( $pattern ) {
		return str_contains( $pattern, '/feed/' );
	}
);
frl_test_same( array(), array_values( $feed_rules ), 'comments disabled: no single-CPT feed rule is generated' );

// ---------------------------------------------------------------------------
// 2. Single CPT: resolution.
// ---------------------------------------------------------------------------
$service = $frl_config['samples']['service'];

$resolved = $single->resolve_request( '/services/' . $service . '/' );
frl_test_same( 'service', $resolved['post_type'] ?? null, 'en service base resolves to the CPT' );
frl_test_same( $service, $resolved['name'] ?? null, 'en service slug resolves' );
frl_test_same( 'en', $resolved['lang'] ?? null, 'en service URL carries the en language' );

$resolved = $single->resolve_request( '/ru/uslugi/' . $service . '/' );
frl_test_same( 'ru', $resolved['lang'] ?? null, 'ru translated base resolves with lang=ru' );

$resolved = $single->resolve_request( '/ar/offers/' . $service . '/' );
frl_test_same( 'ar', $resolved['lang'] ?? null, 'ar translated base resolves with lang=ar' );

$resolved = $single->resolve_request( '/zh/service/' . $service . '/' );
frl_test_same( array(), $resolved, 'zh has no translated base mapping: core/Polylang owns the URL' );

$resolved = $single->resolve_request( '/services/' . $service . '/comment-page-2/' );
frl_test_same( 2, $resolved['cpage'] ?? null, 'comment pagination resolves' );

$resolved = $single->resolve_request( '/services/' . $service . '/embed/' );
frl_test_same( 'true', $resolved['embed'] ?? null, 'embed resolves' );

$resolved = $single->resolve_request( '/services/' . $service . '/feed/' );
frl_test_ok( ! isset( $resolved['feed'] ), 'comments disabled: /feed/ does not resolve to a feed' );
frl_test_same( $service . '/feed', $resolved['name'] ?? null, 'comments disabled: /feed/ falls through to a plain 404 slug' );

$resolved = $single->resolve_request( '/services/page/2/' );
frl_test_same( array(), $resolved, 'archive pagination is not swallowed by the single rule' );

// ---------------------------------------------------------------------------
// 3. Taxonomy base removal: rules and exclusions.
// ---------------------------------------------------------------------------
$taxonomy_rules = $taxonomy->generate_rules();
frl_test_same( array(), $taxonomy_rules, 'no explicit taxonomy rule shadows the static post base (blog)' );

$exclusions = $taxonomy->exclusions();
foreach ( array( 'blog', 'en\/blog', 'ru\/blog', 'ar\/blog', 'zh\/blog', 'service', 'lp', 'about', 'university', 'category', 'tag', 'wp-admin', 'en$', 'ru$', 'ar$', 'zh$' ) as $needle ) {
	frl_test_ok( in_array( $needle, $exclusions, true ), 'catch-all excludes ' . $needle );
}

// ---------------------------------------------------------------------------
// 4. Taxonomy base removal: resolution.
// ---------------------------------------------------------------------------
$state['terms_by_slug'][ 'category|' . $frl_config['samples']['category_en'] ] = frl_test_term( 101, $frl_config['samples']['category_en'], 'Licensing' );
$state['terms_by_slug'][ 'category|' . $frl_config['samples']['category_ru'] ] = frl_test_term( 102, $frl_config['samples']['category_ru'], 'Licenzirovanie' );
$state['terms_by_id'][101] = $state['terms_by_slug'][ 'category|' . $frl_config['samples']['category_en'] ];
$state['terms_by_id'][102] = $state['terms_by_slug'][ 'category|' . $frl_config['samples']['category_ru'] ];

$resolved = $taxonomy->resolve_request( '/blog/' . $frl_config['samples']['category_en'] . '/' );
frl_test_same( $frl_config['samples']['category_en'], $resolved['category_name'] ?? null, 'base-removed en category resolves' );
frl_test_same( 'en', $resolved['lang'] ?? null, 'base-removed en category carries en' );

$resolved = $taxonomy->resolve_request( '/ru/blog/' . $frl_config['samples']['category_ru'] . '/' );
frl_test_same( $frl_config['samples']['category_ru'], $resolved['category_name'] ?? null, 'base-removed ru category resolves' );
frl_test_same( 'ru', $resolved['lang'] ?? null, 'base-removed ru category carries ru' );

$resolved = $taxonomy->resolve_request( '/blog/' );
frl_test_same( array(), $resolved, 'the posts page /blog/ is not hijacked' );

$resolved = $taxonomy->resolve_request( '/blog/' . $frl_config['samples']['post'] . '/' );
frl_test_same( array(), $resolved, 'a post under /blog/ is left to core (no category hijack)' );

frl_test_ok( ! $taxonomy->applies_to_request( '/' ), 'the site root is not a taxonomy URL' );
frl_test_same( array(), $taxonomy->resolve_request( '/' ), 'the site root is never resolved as a taxonomy' );

frl_test_same( array(), $taxonomy->resolve_request( '/ru/' ), 'the ru language root is never resolved as a taxonomy' );
frl_test_same( array(), $taxonomy->resolve_request( '/zh/' ), 'the zh language root is never resolved as a taxonomy' );

frl_test_ok( ! $taxonomy->applies_to_request( '/zh/service/' . $service . '/' ), 'a zh service URL is not treated as a taxonomy URL' );
frl_test_ok( $taxonomy->applies_to_request( '/blog/' . $frl_config['samples']['category_en'] . '/' ), 'a base-removed category URL is handled by the feature' );

// ---------------------------------------------------------------------------
// 5. Post vs category disambiguation under /blog/.
// ---------------------------------------------------------------------------
$state['by_name'][ 'post|' . $frl_config['samples']['post'] ] = frl_test_post( 201, 'post', $frl_config['samples']['post'] );
$state['cache']         = array();
$_SERVER['REQUEST_URI'] = '/blog/' . $frl_config['samples']['post'] . '/';
$vars                   = array( 'name' => $frl_config['samples']['post'] );
$out                    = $taxonomy->disambiguate_static_base_category( $vars );
frl_test_same( $vars, $out, 'a real post keeps the post query vars' );

$state['cache']         = array();
$_SERVER['REQUEST_URI'] = '/blog/' . $frl_config['samples']['category_en'] . '/';
$vars                   = array( 'name' => $frl_config['samples']['category_en'] );
$out                    = $taxonomy->disambiguate_static_base_category( $vars );
frl_test_ok( ! isset( $out['name'] ), 'a category slug is removed from the post name var' );
frl_test_same( $frl_config['samples']['category_en'], $out['category_name'] ?? null, 'a category slug becomes a category query' );

$state['cache']         = array();
$_SERVER['REQUEST_URI'] = '/blog/' . $frl_config['samples']['category_en'] . '/page/2/';
$vars                   = array(
	'name'  => $frl_config['samples']['category_en'],
	'paged' => 2,
);
$out                    = $taxonomy->disambiguate_static_base_category( $vars );
frl_test_same( $frl_config['samples']['category_en'], $out['category_name'] ?? null, 'paginated base-removed category resolves' );
frl_test_same( 2, $out['paged'] ?? null, 'paginated base-removed category keeps the page number' );
frl_test_ok( ! isset( $out['name'] ), 'paginated category drops the post name var' );

$state['cache']         = array();
$_SERVER['REQUEST_URI'] = '/ru/blog/' . $frl_config['samples']['category_ru'] . '/';
$vars                   = array(
	'name' => $frl_config['samples']['category_ru'],
	'lang' => 'ru',
);
$out                    = $taxonomy->disambiguate_static_base_category( $vars );
frl_test_same( $frl_config['samples']['category_ru'], $out['category_name'] ?? null, 'ru category slug becomes a category query' );
frl_test_same( 'ru', $out['lang'] ?? null, 'ru language is preserved' );

$state['cache']         = array();
$_SERVER['REQUEST_URI'] = '/blog/no-such-slug/';
$vars                   = array( 'name' => 'no-such-slug' );
frl_test_same( $vars, $taxonomy->disambiguate_static_base_category( $vars ), 'an unknown slug is left for WordPress to 404' );

$state['cache']         = array();
$_SERVER['REQUEST_URI'] = '/blog/' . $frl_config['samples']['category_en'] . '/';
$vars                   = array(
	'name'      => $frl_config['samples']['category_en'],
	'post_type' => 'service',
);
frl_test_same( $vars, $taxonomy->disambiguate_static_base_category( $vars ), 'a request that already has a post type is untouched' );

// ---------------------------------------------------------------------------
// 6. CPT base removal is inert when its CPTs are not registered.
// ---------------------------------------------------------------------------
$cpt_removal = new Frl_CPT_Base_Removal_Feature();
frl_test_ok( ! $cpt_removal->is_enabled(), 'base removal is disabled when flag/jurisdiction are not registered' );
frl_test_same( array(), $cpt_removal->generate_rules(), 'disabled base removal generates no rules' );
frl_test_ok( ! $cpt_removal->applies_to_request( '/anything/' ), 'disabled base removal does not claim URLs' );

// ---------------------------------------------------------------------------
// 7. Team member vs child page under /about/.
// ---------------------------------------------------------------------------
require FRL_TEST_PLUGIN_DIR . 'modules/pbs/custom-post-types.php';

$state['pages_by_path'][ 'team-member|' . $frl_config['samples']['team_member'] ] = frl_test_post( 301, 'team-member', $frl_config['samples']['team_member'] );

$wp             = new stdClass();
$wp->query_vars = array( 'team-member' => $frl_config['samples']['team_member'] );
do_action( 'parse_request', $wp );
frl_test_same(
	array( 'team-member' => $frl_config['samples']['team_member'] ),
	$wp->query_vars,
	'a published team member keeps the CPT query vars'
);

$page_obj = frl_test_post( 302, 'page', $frl_config['samples']['page_under_about'] );
$state['pages_by_path'][ 'page|about/' . $frl_config['samples']['page_under_about'] ] = $page_obj;
$state['posts_by_id'][302] = $page_obj;

$wp             = new stdClass();
$wp->query_vars = array(
	'team-member' => $frl_config['samples']['page_under_about'],
	'post_type'   => 'team-member',
	'name'        => $frl_config['samples']['page_under_about'],
	'error'       => '404',
);
do_action( 'parse_request', $wp );
frl_test_same( 302, $wp->query_vars['page_id'] ?? null, 'a child page under /about/ resolves as a page' );
frl_test_same( $frl_config['samples']['page_under_about'], $wp->query_vars['pagename'] ?? null, 'the page hierarchy is rebuilt' );
frl_test_ok( ! isset( $wp->query_vars['team-member'] ), 'the team-member var is removed' );
frl_test_ok( ! isset( $wp->query_vars['error'] ), 'the 404 flag is removed' );

$wp             = new stdClass();
$wp->query_vars = array( 'team-member' => 'no-such-member' );
do_action( 'parse_request', $wp );
frl_test_same(
	array( 'team-member' => 'no-such-member' ),
	$wp->query_vars,
	'an unknown /about/ slug is left for WordPress to 404'
);

frl_test_done();
