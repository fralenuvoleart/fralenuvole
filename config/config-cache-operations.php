<?php
/**
 * Cache Operations configuration.
 *
 * Defines the composite multi-step cache operations executed by
 * Frl_Cache_Operations. Each operation is an ordered sequence of
 * steps with callable references, arguments, and inline documentation notes.
 *
 * Three tiers of operations:
 *   clear_*  — Helper-level operations that frl_cache_clear() delegates to.
 *   action_* — Admin-action-level operations that action handlers call.
 *   env_*    — Environment Manager operations triggered by enforce_environment_settings().
 *
 * @package Fralenuvole
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache operation definitions.
 *
 * Each operation lists its steps in execution order. All steps always
 * execute sequentially — there is no abort-on-failure flag. The caller
 * inspects per-step results and decides how to report.
 *
 * Step `note` fields document deferred chains and internals so you never
 * need to search the codebase to understand what a step does.
 *
 * @var array
 */
const FRL_CACHE_OPERATIONS = array(

	// =====================================================================
	// HELPER OPERATIONS — frl_cache_clear() delegates here for 'hard'/'all'/'light'/'options'/'rewriter'
	// =====================================================================

	'clear_hard'                      => array(
		'label' => 'Helper: frl_cache_clear("hard")',
		'steps' => array(
			array(
				'fn'   => array( 'Frl_Cache_Manager', 'hard_cache_reset' ),
				'args' => array(),
				'note' => 'Fully reset plugin caches: purge_all() (all groups + dependencies via FRL_CACHE_DEPENDENCIES), clear_transients() (plugin-prefixed transients only — other plugins\' transients are untouched)',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_cache_operation_clear_hard',
			'after'  => 'frl_after_cache_operation_clear_hard',
		),
	),

	'clear_all'                       => array(
		'label' => 'Helper: frl_cache_clear("all")',
		'steps' => array(
			array(
				'fn'   => array( 'Frl_Cache_Manager', 'purge_all' ),
				'args' => array(),
				'note' => 'Purge ALL cache groups including heavy groups (FRL_CACHE_HEAVY_GROUPS), with FRL_CACHE_DEPENDENCIES cascade',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_cache_operation_clear_all',
			'after'  => 'frl_after_cache_operation_clear_all',
		),
	),

	'clear_light'                     => array(
		'label' => 'Helper: frl_cache_clear("light")',
		'steps' => array(
			array(
				'fn'   => array( 'Frl_Cache_Manager', 'purge_light' ),
				'args' => array(),
				'note' => 'Light purge: skip heavy groups (FRL_CACHE_HEAVY_GROUPS), clear all other groups with dependencies',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_cache_operation_clear_light',
			'after'  => 'frl_after_cache_operation_clear_light',
		),
	),

	'clear_options'                   => array(
		'label' => 'Helper: frl_cache_clear("options")',
		'steps' => array(
			array(
				'fn'   => array( 'Frl_Cache_Manager', 'clear_group_with_dependencies' ),
				'args' => array( 'options' ),
				'note' => 'Clear options group with FRL_CACHE_DEPENDENCIES cascade: options → theme, html, environment, admin, adminui, rewriter → permalinks',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_cache_operation_clear_options',
			'after'  => 'frl_after_cache_operation_clear_options',
		),
	),

	'clear_rewriter'                  => array(
		'label' => 'Helper: frl_cache_clear("rewriter")',
		'steps' => array(
			array(
				'fn'   => array( 'Frl_Cache_Manager', 'clear_group_with_dependencies' ),
				'args' => array( 'rewriter' ),
				'note' => 'Clear rewriter group with FRL_CACHE_DEPENDENCIES cascade: rewriter → permalinks',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_cache_operation_clear_rewriter',
			'after'  => 'frl_after_cache_operation_clear_rewriter',
		),
	),

	// =====================================================================
	// ACTION OPERATIONS — admin action handlers call here
	// =====================================================================

	'action_hard'                     => array(
		'label' => 'Admin: Hard Cache Reset',
		'steps' => array(
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'hard' ),
				'note' => '→ delegates to clear_hard operation: Frl_Cache_Manager::hard_cache_reset()',
			),
			array(
				'fn'   => 'frl_flush_rewrite_rules',
				'args' => array(),
				'note' => 'Mirrors WP_Rewrite::set_permalink_structure(): fires update_option_permalink_structure (→ clear_rewriter_caches() clears options→rewriter→permalinks + deletes exclusion patterns transient + flush_rewrite_rules(true) + notifies Litespeed; → Polylang cleans language cache) + fires permalink_structure_changed',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_cache_operation_action_hard',
			'after'  => 'frl_after_cache_operation_action_hard',
		),
	),

	'action_flush_rewrite_rules'      => array(
		'label' => 'Admin: Flush Rewrite Rules',
		'steps' => array(
			array(
				'fn'   => 'frl_flush_rewrite_rules',
				'args' => array(),
				'note' => 'Immediately mirrors WP_Rewrite::set_permalink_structure(): fires update_option_permalink_structure (→ clear_rewriter_caches() clears options→rewriter→permalinks + deletes exclusion patterns transient + flush_rewrite_rules(true) + notifies Litespeed; → Polylang cleans language cache) + fires permalink_structure_changed',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_cache_operation_action_flush_rewrite_rules',
			'after'  => 'frl_after_cache_operation_action_flush_rewrite_rules',
		),
	),

	// =====================================================================
	// ENVIRONMENT OPERATIONS — triggered by Environment Manager enforce_environment_settings()
	// =====================================================================

	'env_enforce_full'                => array(
		'label' => 'Env: Full enforcement — plugin/module change or force mode',
		'steps' => array(
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'all' ),
				'note' => 'Full cache purge via clear_all operation — plugins/modules can register new post types, rewrite rules, shortcodes; force mode bypasses throttle',
			),
			array(
				'fn'   => 'frl_flush_rewrite_rules',
				'args' => array(),
				'note' => 'Mirrors WP_Rewrite::set_permalink_structure(): fires update_option_permalink_structure (→ clear_rewriter_caches() clears options→rewriter→permalinks + deletes exclusion patterns transient + flush_rewrite_rules(true) + notifies Litespeed; → Polylang cleans language cache) + fires permalink_structure_changed',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_env_enforce_full',
			'after'  => 'frl_after_env_enforce_full',
		),
	),

	'env_enforce_url_change'          => array(
		'label' => 'Env: URL change detected — siteurl or home modified',
		'steps' => array(
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'all' ),
				'note' => 'Full cache purge via clear_all operation — site URLs changed (siteurl/home), all cached URLs are invalidated',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_env_enforce_url_change',
			'after'  => 'frl_after_env_enforce_url_change',
		),
	),

	'env_enforce_options'             => array(
		'label' => 'Env: Options-only change',
		'steps' => array(
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'options' ),
				'note' => 'Options group purge via clear_options operation — only plugin/WordPress options changed, no structural changes requiring full purge or rewrite flush',
			),
		),
		'hooks' => array(
			'before' => 'frl_before_env_enforce_options',
			'after'  => 'frl_after_env_enforce_options',
		),
	),

	'action_clear_plugin_transients'  => array(
		'label' => 'Admin: Clear Plugin Transients',
		'steps' => array(
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'plugin_transients' ),
				'note' => 'Clear all plugin transients from DB via Frl_Cache_Manager::clear_transients()',
			),
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'adminui' ),
				'note' => 'Clear admin UI cache group (admin notices, dashboard widgets)',
			),
		),
		'hooks' => array(
			'after' => 'frl_after_cache_operation_action_clear_plugin_transients',
		),
	),

	'action_clear_website_transients' => array(
		'label' => 'Admin: Clear Website Transients',
		'steps' => array(
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'website_transients' ),
				'note' => 'Clear all website transients from DB via Frl_Cache_Manager::clear_all_website_transients()',
			),
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'adminui' ),
				'note' => 'Clear admin UI cache group',
			),
		),
		'hooks' => array(
			'after' => 'frl_after_cache_operation_action_clear_website_transients',
		),
	),

	'action_clear_scripts_tags'       => array(
		'label' => 'Admin: Clear CSS/JS Caches',
		'steps' => array(
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'versions' ),
				'note' => 'Clear versions cache group (asset version strings)',
			),
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'html' ),
				'note' => 'Clear HTML cache group (minified/processed HTML)',
			),
			array(
				'fn'   => 'frl_cache_clear',
				'args' => array( 'shortcodes' ),
				'note' => 'Clear shortcodes cache group',
			),
		),
		'hooks' => array(
			'after' => 'frl_after_cache_operation_action_clear_scripts_tags',
		),
	),
);
