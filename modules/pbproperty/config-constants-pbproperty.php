<?php

/**
 * PB Property Module - Configuration Constants
 * Site-specific settings for PBProperty (Polylang + GeoDirectory)
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation: CSS classes that trigger block translation
 */
const PBP_TRANSLATE_CLASSES = array(
	'wp-block-blockstrap-blockstrap-widget-container',
);

/**
 * Translation: Hardcoded strings to translate in output
 */
const PBP_TRANSLATE_STRINGS = array(
	'Apartment',
	'Properties',
	'Property Price',
	'Property Tax',
	'Down Payment',
	'Monthly Mortgage Payment',
	'Interest Rate',
	'Loan Amount',
	'Loan Term (Years)',
	'Home Insurance',
	'PMI',
	'Monthly HOA Fees',
	'Monthly',
	'Area (m2)',
);

/**
 * Search: Language-specific search page slugs
 */
const PBP_SEARCH_SLUGS = array(
	'ru' => 'search',
);

/**
 * GeoDirectory: HTML markers to identify GD widget content
 */
const PBP_GEODIR_MARKERS = array(
	'geodir_bestof_widget',
	'geodir-tabs-content',
	'geo-bestof-contentwrap',
	'geodir-bestof-cat-list',
	'geodir_widget_listings',
	'gd-bestof-tabs',
);

/**
 * GeoDirectory: Field htmlvar_names to translate via geodir_get_cf_value filter
 *
 * - Empty array = NO fields are translated (fail-safe, explicit opt-in)
 * - Supports % wildcard matching
 * - Only string values are processed
 *
 * Examples:
 * - 'property_description'     - Exact match
 * - '%_details'                - Suffix wildcard
 * - 'amenities_%'              - Prefix wildcard
 */
const FRL_GEODIR_TRANSLATOR_FIELDS = array(
	// Add field htmlvar_names here to enable translation
	'property_%',
	// Example: '%_description',
);
