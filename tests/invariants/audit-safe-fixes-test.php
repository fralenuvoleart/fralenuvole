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

// 8. ROOT-4 & S1-4: Production default config enables custom_robots_txt, while baseline and RU subdomain disable it.
require_once FRL_TEST_PLUGIN_DIR . 'config/environment/config-defaults.php';
require_once FRL_TEST_PLUGIN_DIR . 'config/environment/config-environment.php';
frl_test_same(
	false,
	FRL_ENV_DEFAULT['plugin_options']['enable_custom_robots'] ?? null,
	'S1-4: FRL_ENV_DEFAULT baseline sets enable_custom_robots to false'
);
frl_test_same(
	false,
	FRL_ENV_PBS_RU_SUBDOMAIN['plugin_options']['enable_custom_robots'] ?? null,
	'S1-4: FRL_ENV_PBS_RU_SUBDOMAIN disables enable_custom_robots'
);
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

// 17. SLICE2-1 & S2-2: Frl_CPT_Base_Removal_Feature scopes both applies_to_request() and resolve_request() by $lang.
$cpt_base_src = file_get_contents( FRL_TEST_PLUGIN_DIR . 'core/rewriter/features/class-cpt-base-removal-feature.php' );
frl_test_ok(
	is_string( $cpt_base_src ) && str_contains( $cpt_base_src, 'resolve_multi_cpt_slug( $slug, $lang )' ),
	'SLICE2-1: applies_to_request() passes URI-extracted $lang to resolve_multi_cpt_slug()'
);
frl_test_same(
	2,
	substr_count( (string) $cpt_base_src, 'frl_get_cpt_id_by_slug( $slug, $cpt, $lang )' ),
	'S2-2: both applies_to_request() and resolve_request() pass $lang to frl_get_cpt_id_by_slug()'
);

// 18. S1-1: frl_is_html_document_request() checks non-HTML query types, while visits and RUM exclude 404/attachment.
$ac_full_src = (string) file_get_contents( FRL_TEST_PLUGIN_DIR . 'includes/helpers/functions-access-control.php' );
preg_match( '/function frl_is_html_document_request\(\): bool \{.*?\n\}/s', $ac_full_src, $html_doc_match );
frl_test_ok(
	! empty( $html_doc_match[0] ) && str_contains( $html_doc_match[0], 'is_feed' ) && ! str_contains( $html_doc_match[0], 'is_404' ),
	'S1-1: frl_is_html_document_request() checks non-HTML feeds/robots/favicons/trackbacks instead of is_404()'
);
$logged_user_src = (string) file_get_contents( FRL_TEST_PLUGIN_DIR . 'includes/main/logged-user.php' );
frl_test_ok(
	str_contains( $logged_user_src, "is_404() ) ||\n\t\t( function_exists( 'is_attachment' ) && is_attachment() )" ),
	'S1-1: frl_trace_logged_user_visits() excludes 404 and attachment requests'
);
frl_test_ok(
	is_string( $rum_src ) && str_contains( $rum_src, 'is_404()' ) && str_contains( $rum_src, 'is_attachment()' ),
	'S1-1: frl_debug_rum_is_active() excludes 404 and attachment requests'
);

// 19. S1-2: frl_log() mutates $safe_payload variable in place instead of passing an array literal by reference.
$err_log_src = (string) file_get_contents( FRL_TEST_PLUGIN_DIR . 'includes/helpers/functions-error-log.php' );
frl_test_ok(
	str_contains( $err_log_src, '$safe_payload = array( $message, $context );' ) &&
	str_contains( $err_log_src, 'frl_sanitize_for_serialization( $safe_payload );' ) &&
	! str_contains( $err_log_src, '? frl_sanitize_for_serialization(' ),
	'S1-2: frl_log() passes variable $safe_payload by reference and preserves mutated array'
);

// 20. S1-3: frl_errors_handle_doing_it_wrong() skips do_action inside wp-includes/functions.php and scans for FRL_DIR_PATH.
$err_handler_src = (string) file_get_contents( FRL_TEST_PLUGIN_DIR . 'core/debugger/error-handler.php' );
preg_match( '/function frl_errors_handle_doing_it_wrong\(.*?\n\}/s', $err_handler_src, $diw_match );
frl_test_ok(
	! empty( $diw_match[0] ) &&
	str_contains( $diw_match[0], "array( 'do_action', '_doing_it_wrong' )" ) &&
	1 === substr_count( $diw_match[0], 'break;' ),
	'S1-3: frl_errors_handle_doing_it_wrong() skips wp-includes/functions.php do_action frame and only breaks on FRL_DIR_PATH'
);

// 21. S1-4: frl_append_custom_robots() and frl_append_custom_robots_tsf() skip custom Allow rules when blog_public = 0.
$GLOBALS['frl_test']['valid_page_request'] = false;
require_once FRL_TEST_PLUGIN_DIR . 'public/public.php';
$GLOBALS['frl_test']['valid_page_request'] = true;
frl_test_set_option( 'frl_enable_custom_robots', '1' );
frl_test_set_option( 'frl_custom_robots_txt', "User-agent: ClaudeBot\nAllow: /" );
frl_test_set_option( 'blog_public', 0 );
frl_test_same(
	"User-agent: *\nDisallow: /\n",
	frl_append_custom_robots( "User-agent: *\nDisallow: /\n", false ),
	'S1-4: frl_append_custom_robots() does not append custom rules when $is_public is false'
);
frl_test_same(
	array(),
	frl_append_custom_robots_tsf( array(), '/' ),
	'S1-4: frl_append_custom_robots_tsf() does not append custom rules when blog_public is 0'
);
frl_test_set_option( 'blog_public', 1 );
frl_test_ok(
	str_contains( frl_append_custom_robots( "User-agent: *\nDisallow:\n", true ), 'Allow: /' ),
	'S1-4: frl_append_custom_robots() appends custom rules when $is_public is true'
);

// 22. S2-1: redirect_legacy_incoming_url() compares path-only URL before appending raw query string.
$legacy_sub_src = (string) file_get_contents( FRL_TEST_PLUGIN_DIR . 'modules/subdomain_adapter/class-subdomain-adapter-legacy.php' );
frl_test_ok(
	str_contains( $legacy_sub_src, "rtrim( home_url( \$path ), '/' ) === rtrim( \$target_url, '/' )" ) &&
	str_contains( $legacy_sub_src, 'parse_url( $uri, PHP_URL_QUERY )' ) &&
	! str_contains( $legacy_sub_src, 'add_query_arg( wp_unslash( $_GET )' ),
	'S2-1: redirect_legacy_incoming_url() compares path before preserving raw encoded query string'
);

// 23. SLICE3-1: Frl_Polylang_Adapter::get_object_id() honors $fallback = false.
if ( ! function_exists( 'icl_object_id' ) ) {
	function icl_object_id( $id, $type, $return_original, $lang ) {
		return $return_original ? $id : null;
	}
}
require_once FRL_TEST_PLUGIN_DIR . 'core/translator/adapters/interface.php';
require_once FRL_TEST_PLUGIN_DIR . 'core/translator/adapters/polylang.php';
$pll_adapter = new Frl_Polylang_Adapter();
frl_test_same( 0, $pll_adapter->get_object_id( 42, 'category', false, 'ru' ), 'SLICE3-1: get_object_id() returns 0 when $fallback is false and translation missing' );
frl_test_same( 42, $pll_adapter->get_object_id( 42, 'category', true, 'ru' ), 'SLICE3-1: get_object_id() returns original ID when $fallback is true' );

// 24. SLICE4-1: frl_admin_bar_menu_render() scopes non-admin cache key with _removal_only and gates filter.
frl_test_ok(
	str_contains( $logged_user_src, '( $show_custom_menu ? \'\' : \'_removal_only\' )' ) &&
	str_contains( $logged_user_src, "if ( \$show_custom_menu ) {\n\t\t\t\t/**" ),
	'SLICE4-1: adminbar cache key separates removal-only entries while preserving admin key for clear_debug_log()'
);

// 25. SLICE5-1 & SLICE5-2: ACF calculated options resolve unseeded registered fields and raw meta fallbacks.
if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( $tag, $callback ) {}
}
$GLOBALS['frl_test_acf_registered'] = array( 'config_query_tax_focus' => array( 'key' => 'field_calc_1' ) );
$GLOBALS['frl_test_acf_objects']    = array();
$GLOBALS['frl_test_acf_values']     = array(
	'option|src_a'    => '10',
	'42|raw_post_tax' => '15',
);
$GLOBALS['frl_test_acf_updates']    = array();
if ( ! function_exists( 'acf_maybe_get_field' ) ) {
	function acf_maybe_get_field( $selector, $post_id = false, $strict = true ) {
		if ( ! $strict && isset( $GLOBALS['frl_test_acf_registered'][ $selector ] ) ) {
			return $GLOBALS['frl_test_acf_registered'][ $selector ];
		}
		return $GLOBALS['frl_test_acf_objects'][ $post_id . '|' . $selector ] ?? false;
	}
}
if ( ! function_exists( 'get_field_object' ) ) {
	function get_field_object( $selector, $post_id = false, $format = true ) {
		return $GLOBALS['frl_test_acf_objects'][ $post_id . '|' . $selector ] ?? false;
	}
}
if ( ! function_exists( 'get_field' ) ) {
	function get_field( $selector, $post_id = false, $format = true ) {
		return $GLOBALS['frl_test_acf_values'][ $post_id . '|' . $selector ] ?? false;
	}
}
if ( ! function_exists( 'update_field' ) ) {
	function update_field( $selector, $value, $post_id = false ) {
		$GLOBALS['frl_test_acf_updates'][ $post_id . '|' . $selector ] = $value;
		return true;
	}
}
require_once FRL_TEST_PLUGIN_DIR . 'modules/acf/acf.php';
frl_acf_process_calc_options(
	array(
		'config_query_tax_focus' => array(
			'template' => 'val={src_a}',
		),
		'unregistered_target'    => array(
			'template' => 'val={src_a}',
		),
	)
);
frl_test_same(
	'val=10',
	$GLOBALS['frl_test_acf_updates']['option|config_query_tax_focus'] ?? null,
	'SLICE5-1: unseeded registered ACF option field is updated via acf_maybe_get_field(..., false)'
);
frl_test_ok(
	! isset( $GLOBALS['frl_test_acf_updates']['option|unregistered_target'] ),
	'SLICE5-1: genuinely missing target field is still skipped'
);
frl_test_same(
	'?tax=15',
	frl_acf_render_template( '?tax={raw_post_tax}', 'post', 42 ),
	'SLICE5-2: frl_acf_render_template() falls back to get_field() when get_field_object() returns false'
);

frl_test_done();
