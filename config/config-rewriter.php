<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewriter Feature Priorities
 *
 * Defines the execution order for all rewriter features. Lower numbers run first.
 * The keys are the class names, and the values are the integer priorities.
 */
if ( ! defined( 'FRL_REWRITER_PRIORITIES' ) ) {
	define(
		'FRL_REWRITER_PRIORITIES',
		array(
			'Frl_CPT_Archive_Base_Translation_Feature' => 15,
			'Frl_CPT_Single_Base_Translation_Feature'  => 25,
			'Frl_Taxonomy_Base_Removal_Feature'        => 35,
			'Frl_CPT_Base_Removal_Feature'             => 40,
		)
	);
}

/**
 * Defines the list of available rewriter features.
 *
 * This constant centralizes the management of rewriter features, allowing for
 * easy addition or removal without modifying the core coordinator logic. The order
 * in this array does not matter, as features are sorted by their internal priority.
 *
 * NOTE: Features that require constructor arguments (like CPT translation features
 * that need a CPT slug) are derived at runtime from FRL_REWRITER_MULTILINGUAL_CPT.
 */
if ( ! defined( 'FRL_REWRITER_FEATURES' ) ) {
	define(
		'FRL_REWRITER_FEATURES',
		array(
			Frl_Taxonomy_Base_Removal_Feature::class,
			Frl_CPT_Base_Removal_Feature::class,
		)
	);
}

/**
 * Defines the custom post types that support multilingual slugs.
 *
 * Each CPT will have translation features instantiated with its slug:
 * - Frl_CPT_Archive_Base_Translation_Feature (priority 15)
 * - Frl_CPT_Single_Base_Translation_Feature (priority 25)
 */
if ( ! defined( 'FRL_REWRITER_MULTILINGUAL_CPT' ) ) {
	define(
		'FRL_REWRITER_MULTILINGUAL_CPT',
		array(
			'service',
		)
	);
}

/**
 * Enable fast prefix-based pattern conflict detection to avoid O(n²) exhaustive regex probes.
 * Does not change any runtime feature behaviour; only affects admin-side validation.
 */
if ( ! defined( 'FRL_REWRITER_USE_FAST_CONFLICT' ) ) {
	define( 'FRL_REWRITER_USE_FAST_CONFLICT', true );
}

/**
 * Cap for number of top-level pages considered in catch-all exclusion generation.
 * Keeping this bounded avoids excessively large regex alternations on very large sites.
 */
if ( ! defined( 'FRL_REWRITER_PAGE_TOPLEVEL_CAP' ) ) {
	define( 'FRL_REWRITER_PAGE_TOPLEVEL_CAP', 500 );
}

/**
 * Control logging of duplicate cross-feature pattern messages.
 * When false, duplicate pattern logs are suppressed (useful to reduce noise).
 */
if ( ! defined( 'FRL_REWRITER_LOG_DUPLICATES' ) ) {
	define( 'FRL_REWRITER_LOG_DUPLICATES', false );
}
