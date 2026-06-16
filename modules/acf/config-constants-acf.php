<?php

/**
 * ACF Custom Fields module settings
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FRL_ACF_CALC_OPTIONS
 * Shortcode: [frl_acf_calculated target=config_query_tax_optimization]
 *
 * Map of calculated option fields.
 *
 * IMPORTANT: Use the exact ACF field name (without 'options_' prefix) inside {}.
 *
 * - Array key = target ACF option field name to save the computed value into.
 * - 'operation' determines how the value is computed. Supported:
 *   TEMPLATE CONCAT, SUM, SUB, MUL, DIV, AVG, MIN, MAX
 *   Defaul = TEMPLATE if omitted and 'fields'/'template' present
 *
 * TEMPLATE:
 *   'template'  => string with placeholders like {field_name}
 *   (multi-line allowed)
 *   'urlencode' => bool (URL-encode substituted values)
 * CONCAT:
 *   'fields'    => [ 'field1', 'field2', ... ]
 *   'separator' => string
 * NUMERIC (SUM, SUB, MUL, DIV, AVG, MIN, MAX):
 *   'fields'    => [ 'num1', 'num2', ... ]
 *   'decimals'  => int
 *
 * Notes:
 * - Executed on acf/save_post for post_id = 'options' only.
 * - If the target ACF option field does not exist, the update is skipped (failure is logged).
 *
 * @var array<string, array{
 *     operation?: string,
 *     fields?: array,
 *     template?: string,
 *     urlencode?: bool,
 *     decimals?: int,
 *     separator?: string
 * }>
 */
const FRL_ACF_CALC_OPTIONS = [
	// TEMPLATE placeholders map to option field names: {query}, {filter}, {taxonomy}, {sort}
	'config_query_tax_focus' => [
		'template' => '
        ?gspb_filterid_gsbp-d187a60
        &filter_corporate_tax|range=0|{config_max_corporate_tax}
        &filter_personal_tax|range=0|{config_max_personal_tax}
        #filter-query',
	],
	'config_query_time_focus' => [
		'template' => '
        ?gspb_filterid_gsbp-d187a60
        &filter_processing_time|range=0|{config_max_processing_time}
        &orderby=meta_value_num&order=ASC&meta_key=processing_time
        #filter-query',
	],
	'config_query_business_focus' => [
		'template' => '
        ?gspb_filterid_gsbp-d187a60
        &filter_flag-category=business__584
        &filter_corporate_tax|range=0|{config_max_corporate_tax}
        &orderby=meta_value_num&order=ASC&meta_key=corporate_tax
        #filter-query',
	],
	'config_query_eu_focus' => [
		'template' => '
        ?gspb_filterid_gsbp-d187a60
        &filter_eu_access=1
        &orderby=meta_value&order=DESC&meta_key=eu_access
        #filter-query',
	],
];
