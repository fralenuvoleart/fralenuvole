<?php
/**
 * Cache Operations configuration.
 *
 * Defines the composite multi-step cache operations executed by
 * Frl_Cache_Operations. Each operation is an ordered sequence of
 * steps with callable references, arguments, and inline documentation notes.
 *
 * Two tiers of operations:
 *   clear_*  — Helper-level operations that frl_cache_clear() delegates to.
 *   action_* — Admin-action-level operations that action handlers call.
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
const FRL_CACHE_OPERATIONS = [

	// =====================================================================
	// HELPER OPERATIONS — frl_cache_clear() delegates here for 'hard'/'all'/'light'
	// =====================================================================

	'clear_hard'               => [
		'label'    => 'Helper: frl_cache_clear("hard")',
		'steps'    => [
			[
				'fn'   => [ 'Frl_Cache_Manager', 'hard_cache_reset' ],
				'args' => [],
				'note' => 'Fully reset plugin caches: purge_all() (all groups + dependencies via FRL_CACHE_DEPENDENCIES), wp_cache_flush() (global WP object cache), clear_all_website_transients()',
			],
			[
				'fn'   => 'frl_thirdparty_maybe_notify',
				'args' => [ 'hard' ],
				'note' => 'Notify third-party cache plugins: LiteSpeed, Breeze, WP Rocket (via FRL_THIRDPARTY_OUTBOUND_HOOKS config)',
			],
		],
		'hooks'    => [
			'before' => 'frl_before_cache_operation_clear_hard',
			'after'  => 'frl_after_cache_operation_clear_hard',
		],
	],

	'clear_all'                => [
		'label'    => 'Helper: frl_cache_clear("all")',
		'steps'    => [
			[
				'fn'   => [ 'Frl_Cache_Manager', 'purge_all' ],
				'args' => [],
				'note' => 'Purge ALL cache groups including heavy groups (FRL_CACHE_HEAVY_GROUPS), with FRL_CACHE_DEPENDENCIES cascade',
			],
			[
				'fn'   => 'frl_thirdparty_maybe_notify',
				'args' => [ 'all' ],
				'note' => 'Notify third-party cache plugins: LiteSpeed, Breeze, WP Rocket',
			],
		],
		'hooks'    => [
			'before' => 'frl_before_cache_operation_clear_all',
			'after'  => 'frl_after_cache_operation_clear_all',
		],
	],

	'clear_light'              => [
		'label'    => 'Helper: frl_cache_clear("light")',
		'steps'    => [
			[
				'fn'   => [ 'Frl_Cache_Manager', 'purge_light' ],
				'args' => [],
				'note' => 'Light purge: skip heavy groups (FRL_CACHE_HEAVY_GROUPS), clear all other groups with dependencies',
			],
			[
				'fn'   => 'frl_thirdparty_maybe_notify',
				'args' => [ 'light' ],
				'note' => 'Notify third-party cache plugins: LiteSpeed, Breeze, WP Rocket',
			],
		],
		'hooks'    => [
			'before' => 'frl_before_cache_operation_clear_light',
			'after'  => 'frl_after_cache_operation_clear_light',
		],
	],

	// =====================================================================
	// ACTION OPERATIONS — admin action handlers call here
	// =====================================================================

	'action_hard'              => [
		'label'    => 'Admin: Hard Cache Reset',
		'steps'    => [
			[
				'fn'   => 'frl_cache_clear',
				'args' => [ 'hard' ],
				'note' => '→ delegates to clear_hard operation: Frl_Cache_Manager::hard_cache_reset() + frl_thirdparty_maybe_notify("hard")',
			],
			[
				'fn'   => 'frl_schedule_admin_rewrite_flush',
				'args' => [],
				'note' => 'Sets 60s transient → admin_init:99 fires frl_execute_scheduled_admin_flush() → frl_flush_force_rewrite_rules() → Frl_Rewriter::flush_rules() (clears rewriter cache + flush_rewrite_rules()) + frl_cache_clear("rewriter") (defensive re-check of rewriter group)',
			],
		],
		'hooks'    => [
			'before' => 'frl_before_cache_operation_action_hard',
			'after'  => 'frl_after_cache_operation_action_hard',
		],
	],

	'action_flush_rewrite_rules' => [
		'label'    => 'Admin: Flush Rewrite Rules',
		'steps'    => [
			[
				'fn'   => 'frl_schedule_admin_rewrite_flush',
				'args' => [],
				'note' => 'Sets 60s transient → admin_init:99 fires frl_execute_scheduled_admin_flush() → frl_flush_force_rewrite_rules() → Frl_Rewriter::flush_rules() (clears rewriter cache + flush_rewrite_rules()) + frl_cache_clear("rewriter") (defensive re-check of rewriter group)',
			],
		],
		'hooks'    => [
			'before' => 'frl_before_cache_operation_action_flush_rewrite_rules',
			'after'  => 'frl_after_cache_operation_action_flush_rewrite_rules',
		],
	],

	'action_clear_plugin_transients' => [
		'label'    => 'Admin: Clear Plugin Transients',
		'steps'    => [
			[
				'fn'   => 'frl_cache_clear',
				'args' => [ 'plugin_transients' ],
				'note' => 'Clear all plugin transients from DB via Frl_Cache_Manager::clear_transients()',
			],
			[
				'fn'   => 'frl_cache_clear',
				'args' => [ 'adminui' ],
				'note' => 'Clear admin UI cache group (admin notices, dashboard widgets)',
			],
		],
		'hooks'    => [
			'after' => 'frl_after_cache_operation_action_clear_plugin_transients',
		],
	],

	'action_clear_website_transients' => [
		'label'    => 'Admin: Clear Website Transients',
		'steps'    => [
			[
				'fn'   => 'frl_cache_clear',
				'args' => [ 'website_transients' ],
				'note' => 'Clear all website transients from DB via Frl_Cache_Manager::clear_all_website_transients()',
			],
			[
				'fn'   => 'frl_cache_clear',
				'args' => [ 'adminui' ],
				'note' => 'Clear admin UI cache group',
			],
		],
		'hooks'    => [
			'after' => 'frl_after_cache_operation_action_clear_website_transients',
		],
	],

	'action_clear_scripts_tags' => [
		'label'    => 'Admin: Clear CSS/JS Caches',
		'steps'    => [
			[
				'fn'   => 'frl_cache_clear',
				'args' => [ 'versions' ],
				'note' => 'Clear versions cache group (asset version strings)',
			],
			[
				'fn'   => 'frl_cache_clear',
				'args' => [ 'html' ],
				'note' => 'Clear HTML cache group (minified/processed HTML)',
			],
			[
				'fn'   => 'frl_cache_clear',
				'args' => [ 'shortcodes' ],
				'note' => 'Clear shortcodes cache group',
			],
		],
		'hooks'    => [
			'after' => 'frl_after_cache_operation_action_clear_scripts_tags',
		],
	],
];
