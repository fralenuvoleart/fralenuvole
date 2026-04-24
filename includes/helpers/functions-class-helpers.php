<?php

/**
 * Cache helper functions
 * @package Fralenuvole
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if the Cache Manager subsystem should load/run for the current request,
 * attempts to load it if necessary, and caches the result per request.
 *
 * @return bool True if the cache manager is loaded and should proceed, false otherwise.
 */
function frl_cache_is_loaded()
{
    static $is_available = null;
    static $is_initializing = false;

    if ($is_available !== null) return $is_available;

    if ($is_initializing) {
        return false;
    }

    $is_initializing = true;

    // Final check to ensure class definition is present
    $is_available = frl_class_exists('Frl_Cache_Manager', __FUNCTION__);

    $is_initializing = false;
    return $is_available;
}

/**
 * Check if the cache system is currently bypassed (due to settings or ?nocache).
 *
 * @return bool True if cache is bypassed, false otherwise.
 */
function frl_cache_is_bypassed(): bool
{
    if (!frl_cache_is_loaded()) {
        // If the cache manager cannot even load, caching is effectively bypassed.
        return true;
    }
    // Manager is loaded, check its bypass status.
    return Frl_Cache_Manager::is_bypass_active();
}

/**
 * Check if a recognized, high-performance object cache is truly functional.
 * This provides a lightweight boolean check.
 *
 * @return bool True if a recognized object cache is truly functional, false otherwise.
 */
function frl_is_object_cache_functional(): bool
{
    if (!frl_cache_is_loaded()) {
        return false;
    }

    $result = Frl_Cache_Manager::is_object_cache_truly_functional();
    return $result;
}

/**
 * Get cached value
 * @param string $group Cache group
 * @param string $key Cache key
 * @param callable|null $callback Optional callback if value not found
 * @return mixed|null Cached value or null
 */
function frl_cache_get(string $group, string $key, ?callable $callback = null): mixed
{
    if (!frl_cache_is_loaded()) {
        return null;
    }
    return Frl_Cache_Manager::get($group, $key, $callback);
}

/**
 * Set cached value
 * @param string $group Cache group
 * @param string $key Cache key
 * @param mixed $value Value to cache
 * @param int|null $ttl Optional TTL in seconds
 * @return bool Success
 */
function frl_cache_set(string $group, string $key, mixed $value, ?int $ttl = null): bool
{
    if (!frl_cache_is_loaded()) {
        return false;
    }
    return Frl_Cache_Manager::set($group, $key, $value, $ttl);
}

/**
 * Remember and cache value
 * @param string $group Cache group
 * @param string $key Cache key
 * @param callable $callback Function to generate value
 * @param int|null $ttl Optional TTL in seconds
 * @return mixed|null Cached value
 */
function frl_cache_remember(string $group, string $key, callable $callback, ?int $ttl = null): mixed
{
    if (!frl_cache_is_loaded()) {
        return $callback();
    }

    return Frl_Cache_Manager::remember($group, $key, $callback, $ttl);
}

/**
 * Get multiple cached values
 * @param string $group Cache group
 * @param array|null $keys Array of keys to retrieve, or null for all keys
 * @return array Result of the cache retrieval operation
 */
function frl_cache_get_multi($group, $keys = null): array
{
    if (!frl_cache_is_loaded()) {
        return [];
    }
    /** @var array */
    return Frl_Cache_Manager::get_multi($group, $keys);
}

/**
 * Preload multiple cache keys without returning their values
 *
 * @param string $group Cache group
 * @param array|null $keys Array of keys to preload, or null to preload all keys in group
 * @return void
 */
function frl_cache_preload_multi($group, $keys = null): void
{
    if (!frl_cache_is_loaded()) {
        return;
    }
    Frl_Cache_Manager::preload_multi($group, $keys);
}

/**
 * Preload multiple cache groups
 *
 * @param array $groups Array of cache groups to preload
 * @return void
 */
function frl_cache_preload_groups($groups = []): void
{
    if (!frl_cache_is_loaded()) {
        return;
    }
    foreach ($groups as $group) {
        frl_cache_preload_multi($group);
    }
}

/**
 * Clear cache for a group or key.
 *
 * @param string $group Cache group to clear.
 * @param string|null $key Optional specific key to clear within the group.
 * @param bool $include_dependencies Whether to include dependencies in the clear operation.
 * @return array|bool|int|string Result of the cache clearing operation.
 */
function frl_cache_clear(string $group, ?string $key = null, bool $include_dependencies = true): array|bool|int|string
{
    if (!frl_cache_is_loaded()) {
        return false;
    }

    if ($group === 'hard') {
        $result = Frl_Cache_Manager::hard_cache_reset();
    } elseif ($group === 'opcache') {
        return Frl_Cache_Manager::opcache_reset();
    } elseif ($group === 'all') {
        $result = Frl_Cache_Manager::purge_all();
    } elseif ($group === 'light') {
        $result = Frl_Cache_Manager::purge_light();
    } elseif ($group === 'plugin_transients') {
        return Frl_Cache_Manager::clear_transients();
    } elseif ($group === 'website_transients') {
        return Frl_Cache_Manager::clear_all_website_transients();
    } else {
        return Frl_Cache_Manager::clear_group_with_dependencies($group, $key, $include_dependencies);
    }

    // Outbound: notify third-party cache plugins for broad purge operations.
    // Driven entirely by FRL_THIRDPARTY_OUTBOUND_HOOKS config in the thirdparty module.
    if (function_exists('frl_thirdparty_maybe_notify')) {
        frl_thirdparty_maybe_notify($group);
    }

    return $result;
}

/**
 * Get deferred writes from cache manager
 * @return array Deferred writes
 */
function frl_cache_get_deferred_writes(): array
{
    if (!frl_cache_is_loaded()) {
        return [];
    }
    return Frl_Cache_Manager::$deferred_writes;
}

/**
 * Clear deferred cache writes
 * @return void
 */
function frl_cache_clear_deferred_writes(): void
{
    if (!frl_cache_is_loaded()) {
        return;
    }
    Frl_Cache_Manager::$deferred_writes = [];
}

/**
 * Add a deferred cache write (for re-queuing failed writes)
 * @param string $group Cache group
 * @param string $key Cache key
 * @param mixed $value Value to cache
 * @return void
 */
function frl_cache_add_deferred_write(string $group, string $key, mixed $value): void
{
    if (!frl_cache_is_loaded()) {
        return;
    }
    Frl_Cache_Manager::$deferred_writes[$group][$key] = $value;
}

/**
 * Check if the Environment Manager subsystem should load/run for the current request.
 * This is the central gatekeeper for EM activation.
 *
 * @return bool True if the environment manager should load, false otherwise.
 */
function frl_environment_is_loaded()
{
    static $is_available = null;

    // If $is_available is already cached, return cached.
    if ($is_available !== null) {
        return $is_available;
    }

    // Check if the general request type is unsuitable for EM.
    if (!frl_is_valid_page_request()) {
        $is_available = false;
        return false;
    }

    // Check for explicit plugin-level or EM-specific disable options.
    $plugin_disabled = (frl_get_option('disable_plugin') === '1');
    $environment_disabled = (frl_get_option('disable_environment') === '1');
    $core_mode = (defined('FRL_MODE') && FRL_MODE === 'core');

    if ($plugin_disabled || $environment_disabled || $core_mode) {
        $is_available = false;
        return false;
    }

    // We just verify it was indeed loaded as expected.
    $is_available = frl_class_exists('Frl_Environment_Manager', __FUNCTION__);

    return $is_available;
}

/**
 * Initialize the Environment Manager
 */
function frl_environment_init()
{
    if (!frl_environment_is_loaded()) {
        return;
    }
    Frl_Environment_Manager::init();
}

/**
 * Retrieves the current environment configuration
 *
 * Bypass frl_environment_is_loaded() check to allow to retrieve
 * configuration even if environment manager is not loaded.
 *
 * @return array Environment configuration
 */
function frl_environment_get_config(): array
{
    // Bypass frl_environment_is_loaded() check
    $config = Frl_Environment_Manager::get_config();
    return $config;
}

/**
 * Enforce the Environment Manager settings.
 *
 * @param bool $force Whether to force applying settings regardless of state change.
 * @return array|null Result of the enforcement operation or null if EM is not loaded.
 */
function frl_environment_enforce_settings($force = false): ?array
{
    if (!frl_environment_is_loaded()) {
        return null;
    }
    return Frl_Environment_Manager::enforce_environment_settings($force);
}

/**
 * Reset ignored customizations in Environment Manager
 *
 * @return array Result of the reset operation
 */
function frl_environment_reset_ignored(): array
{
    if (!frl_environment_is_loaded()) {
        return ['error' => 'Environment manager should not load for this request.'];
    }
    // Class existence is guaranteed by frl_environment_is_loaded returning true
    return Frl_Environment_Manager::reset_customizations();
}

/**
 * Check if any multilingual translation plugin (Polylang/WPML) is active.
 * This serves as a high-performance gatekeeper to avoid initializing the
 * Translation Service and its caching layer when no translation is possible.
 *
 * @return bool True if a multilingual plugin is active, false otherwise.
 */
function frl_is_multilingual_active(): bool
{
    static $is_active = null;

    if ($is_active !== null) {
        return $is_active;
    }

    $is_active = (defined('ICL_SITEPRESS_VERSION') || function_exists('pll_the_languages') || defined('PLL'));

    return $is_active;
}

/**
 * Check whether the Rewriter subsystem is available and enabled.
 *
 * Mirrors frl_environment_is_loaded() / frl_cache_is_loaded(): centralises the
 * class-existence check and the disable_rewriter option so every call site shares one canonical guard.
 * Result is static-cached for the lifetime of the request.
 *
 * @return bool True if Frl_Rewriter is loaded and the rewriter is not disabled.
 */
function frl_rewriter_is_loaded(): bool
{
    static $is_available = null;
    if ($is_available !== null) {
        return $is_available;
    }
    if (frl_get_option('disable_rewriter')) {
        return $is_available = false;
    }
    return $is_available = frl_class_exists('Frl_Rewriter', __FUNCTION__);
}

/**
 * Initialise the Rewriter subsystem.
 *
 * Mirrors frl_environment_init() / frl_translator_init(): a thin, guarded wrapper
 * so fralenuvole.php does not need to know about class names or disable options.
 */
function frl_rewriter_init(): void
{
    if (!frl_rewriter_is_loaded()) {
        return;
    }
    Frl_Rewriter::init();
}

/**
 * Check if a class exists
 *
 * @param string $class Class name
 * @param string|null $context Optional context
 * @return bool True if class exists, false otherwise
 */
function frl_class_exists($class, $context = null)
{
    if (!class_exists($class)) {
        $context = $context ?? 'unknown context';
        frl_log('Class {class} not found in {context}', ['class' => $class, 'context' => $context]);
        return false;
    }
    return true;
}
