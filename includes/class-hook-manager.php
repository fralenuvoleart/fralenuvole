<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole Hook Manager
 *
 * Centralized system for registering and managing WordPress hooks.
 * This class organizes hooks by category and ensures proper execution order.
 *
 * @since 3.0.0
 */
class Frl_Hook_Manager
{
    /**
     * Stores all registered hooks
     *
     * @var array
     */
    private static $hooks = [
        'core'    => [], // Core plugin functionality
        'admin'   => [], // Admin-specific hooks
        'public'  => [], // Public/frontend hooks
        'logged'  => [], // Logged-in user specific hooks
        'ajax'    => [], // AJAX handlers
        'cron'    => [], // Scheduled tasks
        'custom'  => []  // Custom hooks for extensions
    ];

    /**
     * Flag to track if hooks have been registered
     *
     * @var bool
     */
    private static $hooks_registered = false;

    /**
     * Tracks which hooks have been immediately registered
     *
     * @var array
     */
    private static $registered_hooks = [];

    /**
     * Stores hooks for deferred tracking when WordPress isn't ready yet
     *
     * @var array
     */
    private static $deferred_tracking = [];

    /**
     * Flag to track if deferred tracking has been processed
     *
     * @var bool
     */
    private static $deferred_processed = false;

    /* --------------------------------------------------
     * Quick-win diagnostics (profiler & conflict radar)
     * --------------------------------------------------*/
    /**
     * Stores individual timings [ [tag, cb, time] ] and aggregated averages
     */
    private static $timings = [];
    /**
     * Aggregated avg time map tag|cb => ['total'=>float,'count'=>int]
     */
    private static $timing_agg = [];

    /** @var array<string,array<int,int>> duplicate counter tag=>priority=>count */
    private static $conflicts = [];

    /** @var array<string,int> for recursion detection */
    private static $call_stack = [];

    /** @var array<string,true> denylist for runaway hooks */
    private static $denylisted_hooks = [];

    /** @var array<string,true> denylist using precise recursion keys */
    private static $denylisted_recursion = [];

    /** Ensure shutdown reporter added once */
    private static $reporter_added = false;

    /** Map original Closure ids to wrapper closures to avoid double-wrapping */
    private static $wrapped_callbacks = [];

    /** Determine if profiler is enabled */
    public static function profiler_enabled(): bool
    {
        static $enabled = null;
        if ($enabled === null) {
            $enabled = (
                (defined('FRL_HOOKS_PROFILER') && FRL_HOOKS_PROFILER) &&
                (defined('WP_DEBUG') && WP_DEBUG)
            );
        }
        return $enabled;
    }

    /**
     * Wrap any callback (even if not yet defined). Delay callable check until invoke.
     * @param string $tag
     * @param mixed  $callback string|array|callable
     * @param string $type 'action' or 'filter'
     */
    private static function wrap_callback(string $tag, $callback, string $type, int $priority, int $accepted_args)
    {
        // If already a wrapper, return as-is
        if (is_array($callback) && isset($callback['__frl_profiler'])) {
            return $callback;
        }
        if ($callback instanceof \Closure) {
            $cb_id = spl_object_hash($callback);
            if (isset(self::$wrapped_callbacks[$cb_id])) {
                return self::$wrapped_callbacks[$cb_id];
            }
        }

        // cumulative profiler self-time
        static $profiler_self_ms = 0.0;
        $function = ($type === 'action') ? 'add_action' : 'add_filter';

        $wrapper = null;
        $wrapper = function () use ($tag, $callback, $type, &$profiler_self_ms, &$wrapper, $priority, $accepted_args, $function) {
            $string_key = $tag . '|' . self::callback_to_string($callback);
            $recursion_key = self::make_recursion_key($tag, $callback);

            // --- Recursion Guard ---
            if (isset(self::$call_stack[$recursion_key])) {
                // Recursion detected. Prevent infinite loop.
                if (!isset(self::$denylisted_hooks[$string_key])) {
                    self::$denylisted_hooks[$string_key] = true;
                    self::$denylisted_recursion[$recursion_key] = true;

                    // Remove our wrapper and add back the original callback. This allows a later remove_action() to work.
                    if ($wrapper) {
                        $hook_type_remover = ($type === 'action') ? 'remove_action' : 'remove_filter';
                        $hook_type_remover($tag, $wrapper, $priority);
                    }
                    $function($tag, $callback, $priority, $accepted_args);
                }
                // Now, execute the original callback directly to continue the chain.
                return call_user_func_array($callback, func_get_args());
            }

            self::$call_stack[$recursion_key] = 1;

            $args   = func_get_args();
            $start  = microtime(true);
            try {
				// Execute original callback if callable; otherwise, log and safely skip to avoid fatal
				if (is_callable($callback)) {
					$result = $callback(...$args);
				} else {
					if (function_exists('frl_log')) {
						frl_log('Hook callback not callable for {tag} -> {cb}', [
							'tag' => $tag,
							'cb'  => self::callback_to_string($callback),
						]);
					}
					// For filters, return the first argument unchanged; for actions, no-op
					return ($type === 'filter') ? ($args[0] ?? null) : null;
				}
            } finally {
                $elapsed = (microtime(true) - $start) * 1000; // ms
                $profiler_self_ms += $elapsed;

                // Aggregation with bounded size and simple eviction of least-called entry
                $max_keys = defined('FRL_HOOKS_PROFILER_MAX_KEYS') ? (int) constant('FRL_HOOKS_PROFILER_MAX_KEYS') : 100;
                if (!isset(self::$timing_agg[$string_key]) && count(self::$timing_agg) >= $max_keys) {
                    $victim_key = null;
                    $victim_count = PHP_INT_MAX;
                    foreach (self::$timing_agg as $k => $stats) {
                        $c = $stats['count'] ?? 0;
                        if ($c < $victim_count) {
                            $victim_count = $c;
                            $victim_key = $k;
                        }
                    }
                    if ($victim_key !== null) {
                        unset(self::$timing_agg[$victim_key]);
                    }
                }
                if (!isset(self::$timing_agg[$string_key])) {
                    self::$timing_agg[$string_key] = ['total'=>0.0,'count'=>0,'max'=>0.0];
                }
                self::$timing_agg[$string_key]['total'] += $elapsed;
                self::$timing_agg[$string_key]['count']++;
                // Track per-key max time for UI display
                $prevMax = isset(self::$timing_agg[$string_key]['max']) ? (float) self::$timing_agg[$string_key]['max'] : 0.0;
                if ($elapsed > $prevMax) {
                    self::$timing_agg[$string_key]['max'] = $elapsed;
                }

                // Capture per-call timing samples for top-spikes reporting (bounded)
                if (count(self::$timings) >= 1000) {
                    array_shift(self::$timings);
                }
                self::$timings[] = [
                    'tag' => $tag,
                    'callback' => self::callback_to_string($callback),
                    'type' => $type,
                    'time' => $elapsed,
                ];

                unset(self::$call_stack[$recursion_key]);

                // Lazy-register shutdown reporter once
                if (!self::$reporter_added) {
                    add_action('shutdown', [__CLASS__, 'report_diagnostics'], 9998);
                    self::$reporter_added = true;
                }
            }

            return ($type === 'filter') ? $result : null;
        };

        // Mark to avoid double-wrapping
        if ($callback instanceof \Closure) {
            $cb_id = spl_object_hash($callback);
            self::$wrapped_callbacks[$cb_id] = $wrapper;
        }
        return $wrapper;
    }

    /** Convert any callback into readable string */
    public static function callback_to_string($cb): string
    {
        if (is_string($cb)) return $cb;
        if ($cb instanceof \Closure) return 'Closure';
        if (is_array($cb)) {
            // unwrap profiler wrapper
            if (isset($cb['__frl_profiler'])) {
                $cb = $cb['cb'] ?? $cb;
                // recurse once
                return self::callback_to_string($cb);
            }
            // persisted meta shapes (display-friendly; live keys unchanged elsewhere)
            if (isset($cb['__type'])) {
                if ($cb['__type'] === 'closure' && isset($cb['file'], $cb['line'])) {
                    return 'P|closure ' . $cb['file'] . ':' . $cb['line'];
                }
                if ($cb['__type'] === 'object_method' && isset($cb['class'], $cb['method'])) {
                    return 'P|' . $cb['class'] . '->' . $cb['method'];
                }
                // P = persisted
                if ($cb['__type'] === 'object' && isset($cb['class'])) {
                    return 'P|' . $cb['class'] . '::__invoke';
                }
            }
            if (isset($cb[0], $cb[1])) {
                if (is_object($cb[0])) return get_class($cb[0]).'->'.$cb[1];
                return $cb[0].'::'.$cb[1];
            }
            // Try common nested shape
            if (isset($cb['callback'])) {
                return self::callback_to_string($cb['callback']);
            }
            // Fallback: show a hint of array keys (no heavy work)
            $keys = array_slice(array_keys($cb), 0, 3);
            return 'Array[' . implode(',', $keys) . ']';
        }
        if (is_object($cb) && method_exists($cb, '__invoke')) return get_class($cb).'::__invoke';
        return 'Unknown';
    }



    /** Output top timings & conflicts to error_log and admin footer */
    public static function report_diagnostics(): void
    {
        if (!function_exists('frl_has_access') || !frl_has_access()) return;

        // ---- Conflicts ----
        if (!empty(self::$conflicts) && function_exists('frl_cache_set')) {
            frl_cache_set('staticdata', 'hook_conflicts', self::$conflicts, 300);
        }

        // Persist aggregated timings for later requests (5-min TTL)
        if (function_exists('frl_cache_set') && !empty(self::$timing_agg)) {
            frl_cache_set('staticdata', 'hook_profiler_avg', self::$timing_agg, 300);
        }

        // Persist top spike timings (top N single runs) for UI display (5-min TTL)
        if (function_exists('frl_cache_set') && !empty(self::$timings)) {
            $samples = self::$timings;
            usort($samples, function ($a, $b) {
                return ($b['time'] <=> $a['time']);
            });
            $limit = defined('FRL_HOOKS_PROFILER_TOP_LIMIT') ? (int) constant('FRL_HOOKS_PROFILER_TOP_LIMIT') : 25;
            $top = array_slice($samples, 0, $limit);
            frl_cache_set('staticdata', 'hook_profiler_top', $top, 300);
        }
    }

    /** Build a precise recursion key for a given tag+callback */
    private static function make_recursion_key(string $tag, $cb): string
    {
        $prefix = '';
        if (is_string($cb)) {
            $prefix = 's|' . $cb;
        } elseif ($cb instanceof \Closure) {
            $prefix = 'cl|' . spl_object_hash($cb);
        } elseif (is_array($cb) && isset($cb[0], $cb[1])) {
            if (is_object($cb[0])) {
                $prefix = 'om|' . spl_object_hash($cb[0]) . '|' . get_class($cb[0]) . '|' . $cb[1];
            } else {
                $prefix = 'sm|' . $cb[0] . '|' . $cb[1];
            }
        } elseif (is_object($cb) && method_exists($cb, '__invoke')) {
            $prefix = 'iv|' . spl_object_hash($cb) . '|' . get_class($cb);
        } else {
            $prefix = 'u|' . self::callback_to_string($cb);
        }
        return $tag . '|' . $prefix;
    }

    /** Get average exec time for specific tag+callback */
    public static function get_avg_exec_time(string $tag, string $cb): ?float
    {
        $key = $tag.'|'.$cb;
        if (!isset(self::$timing_agg[$key])) return null;
        return self::$timing_agg[$key]['total'] / max(1, self::$timing_agg[$key]['count']);
    }

    /** Public helper: avg time using raw callback */
    public static function get_avg_exec_time_raw(string $tag, $callback): ?float
    {
        $key = $tag.'|'.self::callback_to_string($callback);
        if (!isset(self::$timing_agg[$key])) return null;
        return self::$timing_agg[$key]['total']/max(1,self::$timing_agg[$key]['count']);
    }

    /** Average exec time using cache fallback */
    public static function get_avg_exec_time_persisted(string $tag, $callback): ?float
    {
        $live = self::get_avg_exec_time_raw($tag, $callback);
        if ($live !== null) return $live;
        if (!function_exists('frl_cache_get')) return null;
        $map = frl_cache_get('staticdata', 'hook_profiler_avg', null);
        if (!$map) return null;
        $key = $tag.'|'.self::callback_to_string($callback);
        if (!isset($map[$key])) return null;
        return $map[$key]['total'] / max(1,$map[$key]['count']);
    }

    /** Return conflict map tag=>[priority=>count] */
    public static function get_conflict_map(): array
    {
        return self::$conflicts;
    }

    /**
     * Get all registered hooks data.
     * Provides read-only access to the internal hooks array for display/debugging.
     *
     * @return array The complete hooks array, categorized.
     */
    public static function get_all_registered_hooks()
    {
        return self::$hooks;
    }

    /**
     * Get all registered hooks across all requests from persistent storage
     * This combines the current request's hooks with hooks from previous requests
     *
     * @return array The complete hooks array from all requests, categorized
     */
    public static function get_all_persistent_hooks()
    {
        static $cached_results = null;

        // Return cached results if available
        if ($cached_results !== null) {
            return $cached_results;
        }

        // Prevent recursion
        if (frl_is_already_running(__METHOD__)) {
            // Return empty array to break potential infinite recursion
            return [];
        }

        // Get current request hooks
        $current_hooks = self::$hooks;

        // Get hooks from persistent storage
        $persistent_hooks = [];
        if (function_exists('frl_cache_get')) {
            $persistent_hooks = frl_cache_get('staticdata', 'persistent_hooks', null) ?: [];
        }

        // Merge the hooks (persistent hooks first, then current hooks override/add to them)
        $merged_hooks = $persistent_hooks;

        foreach ($current_hooks as $category => $hooks) {
            if (!isset($merged_hooks[$category])) {
                $merged_hooks[$category] = [];
            }

            foreach ($hooks as $hook) {
                // Use hook ID to avoid duplicates
                $hook_id = $hook['id'] ?? self::generate_hook_id($hook);
                $merged_hooks[$category][$hook_id] = $hook;
            }
        }

        // Convert associative arrays back to indexed arrays for consistency
        // and make sure all hook entries have expected keys
        foreach ($merged_hooks as $category => $hooks) {
            $processed_hooks = [];
            foreach ($hooks as $hook) {
                // Ensure all required fields exist
                if (!isset($hook['tag']) || !isset($hook['type'])) {
                    continue; // Skip invalid entries
                }

                $processed_hooks[] = $hook;
            }
            $merged_hooks[$category] = $processed_hooks;
        }

        // Cache the results
        $cached_results = $merged_hooks;
        return $merged_hooks;
    }

    /**
     * Generate a unique ID for a hook
     *
     * @param array $hook_data Hook data
     * @return string Unique ID
     */
    private static function generate_hook_id($hook_data)
    {
        $callback = $hook_data['callback'];

        // Handle different callback types
        if ($callback instanceof Closure) {
            // For closures, use spl_object_hash as they can't be serialized
            $callback_id = spl_object_hash($callback);
        } elseif (is_array($callback)) {
            // For array callbacks (class methods)
            if (is_object($callback[0])) {
                $callback_id = get_class($callback[0]) . '::' . $callback[1];
            } else {
                $callback_id = $callback[0] . '::' . $callback[1];
            }
        } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
            // Invokable objects
            $callback_id = get_class($callback);
        } elseif (is_string($callback)) {
            // For string callbacks (function names)
            $callback_id = $callback;
        } else {
            // Fallback
            $callback_id = 'unknown_' . mt_rand();
        }

        // Create unique ID from hook data
        return md5($hook_data['type'] .
            $hook_data['tag'] .
            $callback_id .
            $hook_data['priority']);
    }

    /**
     * Store hook registration in persistent storage for cross-request tracking
     *
     * @param array $hook_data The hook registration data
     * @param string $category The hook category
     * @return void
     */
    private static function store_hook_for_tracking($hook_data, $category)
    {
        // Check if WordPress is ready for user authentication
        if (!did_action('plugins_loaded')) {
            // Store for later processing when WordPress is ready
            self::$deferred_tracking[] = [
                'hook_data' => $hook_data,
                'category' => $category
            ];

            // Schedule processing for when WordPress is ready (only once)
            if (!self::$deferred_processed) {
                add_action('plugins_loaded', [__CLASS__, 'process_deferred_tracking'], 99);
                self::$deferred_processed = true;
            }

            return;
        }

        // WordPress is ready, proceed with admin check
        self::process_hook_tracking($hook_data, $category);
    }

    /**
     * Process deferred hook tracking when WordPress is ready
     *
     * @return void
     */
    public static function process_deferred_tracking()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (empty(self::$deferred_tracking)) {
            return;
        }

        // Process all deferred hooks
        foreach (self::$deferred_tracking as $deferred) {
            self::process_hook_tracking($deferred['hook_data'], $deferred['category']);
        }

        // Clear deferred tracking data
        self::$deferred_tracking = [];
    }

    /**
     * Actually process hook tracking with admin check
     *
     * @param array $hook_data The hook registration data
     * @param string $category The hook category
     * @return void
     */
    private static function process_hook_tracking($hook_data, $category)
    {
        // Only track hooks when plugin admin is viewing the site
        if (!frl_has_access()) {
            return;
        }

        static $batch_updates = [];
        static $update_scheduled = false;

        if (!function_exists('frl_cache_get') || !function_exists('frl_cache_set')) {
            return;
        }

        // Add to batch updates instead of immediate saving
        if (!isset($batch_updates[$category])) {
            $batch_updates[$category] = [];
        }

        // Create a serializable copy of the hook data
        $storable_hook_data = $hook_data;

        // Process callback to handle Closures and object instances that can't be serialized
        if (isset($storable_hook_data['callback'])) {
            $callback = $storable_hook_data['callback'];

            // Handle different callback types
            if ($callback instanceof \Closure) {
                try {
                    $reflection = new \ReflectionFunction($callback);
                    $storable_hook_data['callback'] = [
                        '__type' => 'closure',
                        'file' => $reflection->getFileName(),
                        'line' => $reflection->getStartLine()
                    ];
                } catch (\ReflectionException $e) {
                    // Fallback for reflection failures
                    $storable_hook_data['callback'] = [
                        '__type' => 'closure',
                        'file' => 'N/A',
                        'line' => 'N/A'
                    ];
                }
            } elseif (is_array($callback)) {
                // Handle method callbacks [object, method]
                if (is_object($callback[0])) {
                    $storable_hook_data['callback'] = [
                        '__type' => 'object_method',
                        'class' => get_class($callback[0]),
                        'method' => $callback[1]
                    ];
                }
                // Static method calls [class, method] are already serializable
            } elseif (is_object($callback) && !($callback instanceof \Closure)) {
                // Other object instances that implement __invoke
                $storable_hook_data['callback'] = [
                    '__type' => 'object',
                    'class' => get_class($callback)
                ];
            }
            // String callbacks (function names) are already serializable
        }

        // Final recursive sweep: ensure serialisable data only
        frl_sanitize_for_serialization($storable_hook_data);

        // Add hook to batch updates using a stable persistence key that collapses duplicates across requests
        // Build a stable signature from the normalized/serializable callback
        $persistSig = '';
        if (isset($storable_hook_data['callback']['__type'])) {
            $c = $storable_hook_data['callback'];
            if ($c['__type'] === 'closure' && isset($c['file'], $c['line'])) {
                $persistSig = 'cl|' . $c['file'] . ':' . $c['line'];
            } elseif ($c['__type'] === 'object_method' && isset($c['class'], $c['method'])) {
                $persistSig = 'om|' . $c['class'] . '|' . $c['method'];
            } elseif ($c['__type'] === 'object' && isset($c['class'])) {
                $persistSig = 'iv|' . $c['class'];
            }
        } elseif (is_string($storable_hook_data['callback'] ?? null)) {
            $persistSig = 'fn|' . $storable_hook_data['callback'];
        } elseif (is_array($storable_hook_data['callback'] ?? null) && isset($storable_hook_data['callback'][0], $storable_hook_data['callback'][1])) {
            $cb0 = $storable_hook_data['callback'][0];
            $cls = is_object($cb0) ? get_class($cb0) : $cb0;
            $persistSig = 'sm|' . $cls . '|' . $storable_hook_data['callback'][1];
        } else {
            $persistSig = 'u|' . self::callback_to_string($storable_hook_data['callback'] ?? 'unknown');
        }

        $persistKey = md5(
            ($storable_hook_data['type'] ?? '') .
            ($storable_hook_data['tag'] ?? '') .
            $persistSig .
            ($storable_hook_data['priority'] ?? '')
        );

        $batch_updates[$category][$persistKey] = $storable_hook_data;

        // Schedule batch update if not already scheduled
        if (!$update_scheduled) {
            add_action('shutdown', function () use (&$batch_updates) {
                // Skip if no updates or if shutdown hook triggered multiple times
                if (empty($batch_updates)) {
                    return;
                }

                // Get current persistent hooks
                $persistent_hooks = frl_cache_get('staticdata', 'persistent_hooks', function () {
                    return [];
                });

                $updated = false;
                // Apply batch updates
                foreach ($batch_updates as $cat => $hooks) {
                    if (!isset($persistent_hooks[$cat])) {
                        $persistent_hooks[$cat] = [];
                    }

                    // Merge new hooks, limit to 1000 hooks per category to prevent bloat
                    $persistent_hooks[$cat] = array_merge($persistent_hooks[$cat], $hooks);

                    // If we have too many hooks, keep only the most recent 1000
                    if (count($persistent_hooks[$cat]) > 1000) {
                        $persistent_hooks[$cat] = array_slice($persistent_hooks[$cat], -1000, 1000, true);
                    }

                    $updated = true;
                }

                if ($updated) {
                    // Store back in cache with one week TTL
                    frl_cache_set('staticdata', 'persistent_hooks', $persistent_hooks, WEEK_IN_SECONDS);
                }

                // Clear batch updates
                $batch_updates = [];
            }, 9999);

            $update_scheduled = true;
        }
    }

    /**
     * Register a hook (action or filter)
     *
     * @param string $hook_type 'action' or 'filter'
     * @param string $tag The hook name
     * @param callable $callback Function or method to call
     * @param int $priority Priority (default: 10)
     * @param int $accepted_args Number of arguments (default: 1)
     * @param string $category Hook category (default: 'core')
     * @param bool $immediate Whether to register this hook immediately (default: false)
     * @return void
     */
    public static function register($hook_type, $tag, $callback, $priority = 10, $accepted_args = 1, $category = 'core', $immediate = false)
    {
        // Validate hook type
        if (!in_array($hook_type, ['action', 'filter'])) {
            return;
        }

        // Validate category
        if (!isset(self::$hooks[$category])) {
            $category = 'custom'; // Default to custom if category doesn't exist
        }

        // Create hook data array
        $hook_data = [
            'type'     => $hook_type,
            'tag'      => $tag,
            'callback' => $callback,
            'priority' => $priority
        ];

        // Generate unique ID using new method
        $hook_id = self::generate_hook_id($hook_data);

        // Detect duplicate tag+priority for conflict radar
        $confKeyTag = $tag;
        if (!isset(self::$conflicts[$confKeyTag])) self::$conflicts[$confKeyTag] = [];
        self::$conflicts[$confKeyTag][$priority] = (self::$conflicts[$confKeyTag][$priority] ?? 0) + 1;

        // Add hook to registry
        $complete_hook_data = [
            'id'            => $hook_id,
            'type'          => $hook_type,
            'tag'           => $tag,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args
        ];

        self::$hooks[$category][] = $complete_hook_data;

        // Store hook in persistent storage for cross-request tracking
        self::store_hook_for_tracking($complete_hook_data, $category);

        // If immediate flag is set, register this hook immediately with WordPress
        if ($immediate && !isset(self::$registered_hooks[$hook_id])) {
            $key = $tag . '|' . self::callback_to_string($callback);
            // If profiler is enabled, wrap the callback just like in deferred registration
            $cb_to_register = (self::profiler_enabled() && !isset(self::$denylisted_hooks[$key]))
                ? self::wrap_callback($tag, $callback, $hook_type, $priority, $accepted_args)
                : $callback;

            $function = $hook_type === 'action' ? 'add_action' : 'add_filter';
            $function($tag, $cb_to_register, $priority, $accepted_args);
            self::$registered_hooks[$hook_id] = true;
        } elseif ($immediate && isset(self::$registered_hooks[$hook_id])) {
        }
    }

    /**
     * Register an action hook
     *
     * @param string $tag The hook name
     * @param callable $callback Function or method to call
     * @param int $priority Priority (default: 10)
     * @param int $accepted_args Number of arguments (default: 1)
     * @param string $category Hook category (default: 'core')
     * @param bool $immediate Whether to register this hook immediately (default: false)
     * @return void
     */
    public static function add_action($tag, $callback, $priority = 10, $accepted_args = 1, $category = 'core', $immediate = null)
    {
        if ($immediate === null) {
            $immediate = FRL_HOOKS_IMMEDIATE;
        }
        self::register('action', $tag, $callback, $priority, $accepted_args, $category, $immediate);
    }

    /**
     * Register a filter hook
     *
     * @param string $tag The hook name
     * @param callable $callback Function or method to call
     * @param int $priority Priority (default: 10)
     * @param int $accepted_args Number of arguments (default: 1)
     * @param string $category Hook category (default: 'core')
     * @param bool $immediate Whether to register this hook immediately (default: false)
     * @return void
     */
    public static function add_filter($tag, $callback, $priority = 10, $accepted_args = 1, $category = 'core', $immediate = null)
    {
        if ($immediate === null) {
            $immediate = FRL_HOOKS_IMMEDIATE;
        }
        self::register('filter', $tag, $callback, $priority, $accepted_args, $category, $immediate);
    }

    /**
     * Register all hooks with WordPress
     *
     * This function handles the actual registration of hooks with WordPress.
     * It respects a specific order to ensure proper execution.
     *
     * @return void
     */
    public static function register_hooks()
    {
        // Only register each hook once
        if (self::$hooks_registered) {
            return;
        }

        // Define the registration order for hook categories
        $registration_order = [
            'core',   // Core hooks first
            'admin',  // Then admin hooks
            'public', // Then public-facing hooks
            'logged', // Then logged-in user hooks
            'ajax',   // Then AJAX handlers
            'cron',   // Then scheduled tasks
            'custom'  // Finally custom hooks
        ];

        // Register hooks in the specified order
        foreach ($registration_order as $category) {
            if (empty(self::$hooks[$category])) {
                continue;
            }

            foreach (self::$hooks[$category] as $hook) {
                // Skip hooks that have already been registered immediately
                if (isset(self::$registered_hooks[$hook['id']])) {
                    continue;
                }

                $function = $hook['type'] === 'action' ? 'add_action' : 'add_filter';
                $key = $hook['tag'] . '|' . self::callback_to_string($hook['callback']);

                $wrapped = (self::profiler_enabled() && !isset(self::$denylisted_hooks[$key]))
                    ? self::wrap_callback($hook['tag'], $hook['callback'], $hook['type'], $hook['priority'], $hook['accepted_args'])
                    : $hook['callback'];

                $function(
                    $hook['tag'],
                    $wrapped,
                    $hook['priority'],
                    $hook['accepted_args']
                );

                self::$registered_hooks[$hook['id']] = true;
            }
        }

        self::$hooks_registered = true;
    }

    /**
     * Get all registered hooks (for debugging)
     *
     * @param string $category Optional category to filter by
     * @return array Registered hooks
     */
    public static function get_hooks($category = null)
    {
        if ($category !== null && isset(self::$hooks[$category])) {
            return self::$hooks[$category];
        }

        return self::$hooks;
    }

    /**
     * Clear all registered hooks
     *
     * @param string $category Optional category to clear
     * @return void
     */
    public static function clear_hooks($category = null)
    {
        if ($category !== null && isset(self::$hooks[$category])) {
            self::$hooks[$category] = [];
            return;
        }

        foreach (self::$hooks as $key => $value) {
            self::$hooks[$key] = [];
        }

        self::$registered_hooks = [];
        self::$hooks_registered = false;
    }
}
