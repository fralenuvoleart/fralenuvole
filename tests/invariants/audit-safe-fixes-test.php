<?php
/**
 * Invariant: regression guards for the 2026-11-26 safe audit fixes.
 *
 * Covers: INCLUDES-1, INCLUDES-7, PUBLIC-2, PUBLIC-3, ADMIN-1, ADMIN-2,
 * ROOT-1, ROOT-7, and CORE-6.
 *
 * @package Fralenuvole
 */

require __DIR__ . '/lib/wp-stub.php';
require __DIR__ . '/lib/assert.php';

if ( ! function_exists( 'get_admin_url' ) ) {
	function get_admin_url() {
		return 'https://example.com/wp-admin/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'https://example.com/wp-content/plugins/fralenuvole/';
	}
}
if ( ! function_exists( 'frl_get_post_meta' ) ) {
	$GLOBALS['frl_test_post_meta']       = array();
	$GLOBALS['frl_test_post_meta_calls'] = 0;
	function frl_get_post_meta( $post_id, $key, $single = true ) {
		++$GLOBALS['frl_test_post_meta_calls'];
		return $GLOBALS['frl_test_post_meta'][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'have_rows' ) ) {
	$GLOBALS['frl_test_acf_rows'] = array();
	$GLOBALS['frl_test_acf_idx']  = -1;
	function have_rows( $repeater, $post_id ) {
		return ( $GLOBALS['frl_test_acf_idx'] + 1 ) < count( $GLOBALS['frl_test_acf_rows'] );
	}
	function the_row() {
		++$GLOBALS['frl_test_acf_idx'];
	}
	function get_sub_field( $name ) {
		return $GLOBALS['frl_test_acf_rows'][ $GLOBALS['frl_test_acf_idx'] ][ $name ] ?? null;
	}
}

require FRL_TEST_PLUGIN_DIR . 'config/config-base.php';
require FRL_TEST_PLUGIN_DIR . 'config/config-fields.php';
require FRL_TEST_PLUGIN_DIR . 'includes/helpers/functions-schema.php';
require FRL_TEST_PLUGIN_DIR . 'public/schema/class-builder.php';
require FRL_TEST_PLUGIN_DIR . 'admin/helpers/functions-admin.php';

frl_test_reset();

// 1. INCLUDES-1: frl_schema_get_repeater_rows_acf() advances cursor with the_row() and terminates.
$GLOBALS['frl_test_acf_rows'] = array(
	array(
		'title'  => 'Step One',
		'answer' => 'Do A',
	),
	array(
		'title'  => 'Step Two',
		'answer' => 'Do B',
	),
);
$GLOBALS['frl_test_acf_idx']  = -1;

$acf_rows = frl_schema_get_repeater_rows_acf(
	42,
	'howto',
	array(
		'name' => 'title',
		'text' => 'answer',
	)
);
frl_test_same( 2, count( $acf_rows ), 'INCLUDES-1: ACF repeater returns both rows without infinite loop' );
frl_test_same( 'Step Two', $acf_rows[1]['name'] ?? '', 'INCLUDES-1: second row populated via the_row()' );

// 2. INCLUDES-7: unresolved {{placeholder}} returns null without calling frl_get_post_meta().
$calls_before = $GLOBALS['frl_test_post_meta_calls'];
$unresolved   = frl_schema_resolve_value( 42, '{{nonexistent_token}}', array( '{{known}}' => 'val' ) );
frl_test_same( null, $unresolved, 'INCLUDES-7: unresolved {{placeholder}} returns null' );
frl_test_same( $calls_before, $GLOBALS['frl_test_post_meta_calls'], 'INCLUDES-7: no DB postmeta lookup for unresolved {{placeholder}}' );

// 3. PUBLIC-2: repeater builder partitions static/template props (position => {{index}}) from @field: keys.
$GLOBALS['frl_test_post_meta'][42]['steps'] = array(
	'title'  => array( 'First', 'Second' ),
	'answer' => array( 'Body 1', 'Body 2' ),
);

$built_steps = frl_schema_generator_build_repeater(
	42,
	array(
		'repeater' => 'steps',
		'source'   => 'acpt',
		'@type'    => 'HowToStep',
		'position' => '{{index}}',
		'name'     => '@field:title',
		'text'     => '@field:answer',
	),
	array()
);
frl_test_same( 2, count( $built_steps ), 'PUBLIC-2: ACPT repeater builds both steps even with position={{index}} first' );
frl_test_same( '1', $built_steps[0]['position'] ?? '', 'PUBLIC-2: step 1 gets position 1' );
frl_test_same( '2', $built_steps[1]['position'] ?? '', 'PUBLIC-2: step 2 gets position 2' );

// 4. PUBLIC-3: list array with empty index 0 re-indexes with array_values() so JSON-LD stays a list.
$GLOBALS['frl_test_post_meta'][42]['linkedin'] = '';
$GLOBALS['frl_test_post_meta'][42]['facebook'] = 'https://facebook.com/example';

$same_as = frl_schema_generator_build(
	42,
	array( '@field:linkedin', '@field:facebook' ),
	array()
);
frl_test_same( array( 'https://facebook.com/example' ), $same_as, 'PUBLIC-3: sparse list re-indexed to 0-based JSON array' );
frl_test_ok( array_is_list( $same_as ), 'PUBLIC-3: result passes array_is_list()' );

// 5. ADMIN-2: frl_normalize_html_content() does not double-strip intentional backslashes.
$raw_js = '<script>const re = /\d+\n/;</script>';
frl_test_same( $raw_js, frl_normalize_html_content( $raw_js, false ), 'ADMIN-2: intentional backslashes preserved on save' );

// 6. ROOT-1: FRL_PLUGIN_ADMIN_URL points to options-general.php.
frl_test_ok(
	str_contains( FRL_PLUGIN_ADMIN_URL, 'options-general.php?page=' . FRL_NAME ),
	'ROOT-1: FRL_PLUGIN_ADMIN_URL targets options-general.php'
);

// 7. ROOT-7: FRL_FIELD_ATTRIBUTES includes sanitize_callback.
frl_test_ok(
	array_key_exists( 'sanitize_callback', FRL_FIELD_ATTRIBUTES ),
	'ROOT-7: FRL_FIELD_ATTRIBUTES retains sanitize_callback'
);

// 8. ROOT-4: Production default config enables custom_robots_txt without leading tab indentation.
require FRL_TEST_PLUGIN_DIR . 'config/environment/config-defaults.php';
frl_test_same(
	true,
	FRL_ENV_DEFAULT_PRODUCTION['plugin_options']['enable_custom_robots'] ?? null,
	'ROOT-4: FRL_ENV_DEFAULT_PRODUCTION enables enable_custom_robots'
);
frl_test_ok(
	! str_contains( FRL_ENV_DEFAULT_PRODUCTION['plugin_options']['custom_robots_txt'] ?? '', "\t" ),
	'ROOT-4: custom_robots_txt has no leading tab indentation'
);

// 9. ROOT-5 & ROOT-6: frl-mu-plugin.php guards with is_file() and zz-cache-flush-logger.php is removed.
$mu_src = file_get_contents( FRL_TEST_PLUGIN_DIR . 'assets/mu/frl-mu-plugin.php' );
frl_test_ok( is_string( $mu_src ) && str_contains( $mu_src, 'is_file(' ), 'ROOT-5: frl-mu-plugin.php guards bootstrap with is_file()' );
frl_test_ok( ! file_exists( FRL_TEST_PLUGIN_DIR . 'assets/mu/zz-cache-flush-logger.php' ), 'ROOT-6: zz-cache-flush-logger.php deleted' );

// 10. CORE__DEBUGGER-2 & CORE__DEBUGGER-3: RUM sync never calls frl_get_option('debugger_rum', true) and hooks add_option_.
$rum_src = file_get_contents( FRL_TEST_PLUGIN_DIR . 'core/debugger/rum/rum.php' );
frl_test_ok( is_string( $rum_src ) && ! str_contains( $rum_src, "frl_get_option( 'debugger_rum', true )" ), 'CORE__DEBUGGER-3: rum.php never bypasses option cache on frontend' );
frl_test_ok( is_string( $rum_src ) && str_contains( $rum_src, "add_action( 'add_option_'" ), 'CORE__DEBUGGER-2: rum.php hooks add_option_ for first-time option creation' );

// 11. CORE__CACHE-7: dead atomic_clear_group() removed from Frl_Cache_Manager.
$cm_src = file_get_contents( FRL_TEST_PLUGIN_DIR . 'core/cache/class-cache-manager.php' );
frl_test_ok( is_string( $cm_src ) && ! str_contains( $cm_src, 'function atomic_clear_group' ), 'CORE__CACHE-7: dead atomic_clear_group() removed' );

// 12. PUBLIC__SCHEMA-1: frl_schema_merge_properties() replaces list properties cleanly without dropping items.
$merged_schema = frl_schema_merge_properties(
	array(
		'@type'  => 'BlogPosting',
		'author' => array(
			'@type' => 'Person',
			'name'  => 'Old',
		),
	),
	array(
		'author' => array(
			array(
				'@type' => 'Person',
				'name'  => 'Author A',
			),
			array(
				'@type' => 'Person',
				'name'  => 'Author B',
			),
		),
	)
);
frl_test_ok( array_is_list( $merged_schema['author'] ), 'PUBLIC__SCHEMA-1: list author property remains a list after merge' );
frl_test_same( 2, count( $merged_schema['author'] ), 'PUBLIC__SCHEMA-1: both authors preserved after merge' );

// 13. PUBLIC__SCHEMA-2: nested address strings are translated while structural keys stay untouched.
if ( ! function_exists( 'frl_get_translation' ) ) {
	function frl_get_translation( $value ) {
		return 'TR:' . $value;
	}
}
require FRL_TEST_PLUGIN_DIR . 'config/config-schema.php';
require FRL_TEST_PLUGIN_DIR . 'public/schema/class-resolver.php';
$resolved_nested = frl_schema_resolver_resolve(
	array(
		'@type'   => 'Organization',
		'address' => array(
			'@type'           => 'PostalAddress',
			'addressLocality' => 'Tbilisi',
			'url'             => 'https://example.com',
		),
	),
	'',
	array( '{{site_name}}' => 'Example' )
);
frl_test_same( 'PostalAddress', $resolved_nested['address']['@type'], 'PUBLIC__SCHEMA-2: nested @type is not translated' );
frl_test_same( 'TR:Tbilisi', $resolved_nested['address']['addressLocality'], 'PUBLIC__SCHEMA-2: nested addressLocality is translated' );
frl_test_same( 'https://example.com', $resolved_nested['address']['url'], 'PUBLIC__SCHEMA-2: nested url is not translated' );

// 14. ADMIN__HELPERS-2: frl_prepare_settings_for_export() preserves '0' and HTML indentation.
require FRL_TEST_PLUGIN_DIR . 'admin/helpers/functions-admin-import-export.php';
$exported = frl_prepare_settings_for_export(
	array(
		'zero_val' => '0',
		'html_val' => "  <div>\r\n    <span>ok</span>\r\n  </div>",
	)
);
frl_test_same( '0', $exported[ frl_prefix( 'zero_val' ) ], 'ADMIN__HELPERS-2: string "0" is preserved on export' );
frl_test_same( "  <div>\n    <span>ok</span>\n  </div>", $exported[ frl_prefix( 'html_val' ) ], 'ADMIN__HELPERS-2: indentation preserved and CRLF normalized' );

// 15. CORE__TRANSLATOR-1: frl_translator_filter_get_terms() passes scalar counts through without TypeError.
require FRL_TEST_PLUGIN_DIR . 'core/translator/field-translator.php';
frl_test_same( '7', frl_translator_filter_get_terms( '7', array( 'category' ), array( 'fields' => 'count' ) ), 'CORE__TRANSLATOR-1: scalar term count passes through unchanged' );

// 16. S3-1: '!' exclusion rules still override inherited subtree translation in frl_schema_should_translate_key().
frl_test_same(
	false,
	frl_schema_should_translate_key( 'postalCode', 'address.postalCode', array( 'address', '!address.postalCode' ), true ),
	'S3-1: ! exclusion rule overrides inherited subtree translation'
);
frl_test_same(
	true,
	frl_schema_should_translate_key( 'addressLocality', 'address.addressLocality', array( 'address', '!address.postalCode' ), true ),
	'S3-1: non-excluded descendant still inherits subtree translation'
);

// 17. SLICE2-1: Frl_CPT_Base_Removal_Feature::applies_to_request() forwards extracted $lang to resolve_multi_cpt_slug().
$cpt_base_src = file_get_contents( FRL_TEST_PLUGIN_DIR . 'core/rewriter/features/class-cpt-base-removal-feature.php' );
frl_test_ok(
	is_string( $cpt_base_src ) && str_contains( $cpt_base_src, 'resolve_multi_cpt_slug( $slug, $lang )' ),
	'SLICE2-1: applies_to_request() passes URI-extracted $lang to resolve_multi_cpt_slug()'
);

frl_test_done();
