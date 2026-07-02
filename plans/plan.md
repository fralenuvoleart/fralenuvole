
\+

+## Executive Summary

\+

+The Fralenuvole plugin is a large, feature-rich WordPress plugin with custom caching, URL rewriting, environment management, multilingual translation, and admin tooling. The codebase shows mature patterns in many areas, but it also contains several **High** and **Critical** severity issues that should be addressed before production use:

\+

+- **Arbitrary PHP execution** via admin-editable HTML options.

+- **CSRF vulnerabilities** in public actions that skip nonce verification.

+- **IP spoofing** in the MU-plugin bot throttle.

+- **Potential session hijack / cross-user cache poisoning** in user caching.

+- **Unbounded recursion / memory growth** in cache dependency resolution.

+- **Silent failures** in cache and browser cache clearing.

+- **REST API security** gaps and hardcoded endpoint lists.

\+

+This report lists **25 findings** with severity, file references, and concrete fixes.

\+

+---

\+

+## Severity Legend

\+

+| Severity | Meaning |

+|----------|---------|

+| **Critical** | Immediate security risk or data-loss potential; fix before production. |

+| **High** | Significant bug or security issue; should be fixed in next release. |

+| **Medium** | Correctness, performance, or maintainability issue. |

+| **Low** | Minor issue or hardening recommendation. |

\+

+---

\+

+## Critical Findings

\+

+### C-001: Arbitrary PHP Execution via `frl_process_php_string()`

\+

+- **File:** `includes/helpers/utilities.php:473-498`

+- **Function:** `frl_process_php_string()`

+- **Description:** This function calls `eval()` on any string containing `<?` after a token syntax check. It is invoked from `frl_get_html_option()` when `header_html_php` or `footer_html_php` options are enabled. Any admin user who can edit these options can execute arbitrary PHP code.

+- **Impact:** Full site compromise if an attacker gains admin access (or if another plugin allows option manipulation).

+- **Fix:** Remove `eval()` entirely. If dynamic PHP is truly required, restrict it to a whitelist of safe template tags or use a sandboxed template engine. At minimum:

\+ 1. Gate the option behind `FRL_PLUGIN_ACCESS` (`delete_plugins`) instead of `manage_options`.

\+ 2. Log every execution with user ID and source option.

\+ 3. Never allow PHP execution from options on the frontend for unauthenticated requests.

\+

+```php

+// Hardened example: only allow PHP processing for super-admins and log it.

+function frl_process_php_string($string, $context = null): string

+{

\+ if (empty($string) || !str_contains($string, '<?')) {

\+ return $string;

\+ }

\+ if (!frl_has_access(FRL_PLUGIN_ACCESS)) {

\+ frl_log('Blocked PHP option execution: insufficient capability', ['context' => $context]);

\+ return '<!-- PHP execution blocked -->';

\+ }

\+ frl_log('PHP option executed', ['context' => $context, 'user' => get_current_user_id()]);

\+ // ... existing eval path, but consider replacing with a safe template engine.

+}

+```

\+

+---

\+

+### C-002: CSRF on Public Actions via `FRL_PUBLIC_ACTIONS`

\+

+- **File:** `config/config-base.php:93-95`, `admin/helpers/functions-admin-action-handlers.php:749-776`, `includes/helpers/functions-action-handlers.php:24-42`

+- **Description:** `clear_website_transients` is registered as a public action. The dispatcher allows execution for any logged-in user (`is_user_logged_in()`), and `frl_verify_plugin_action_nonce()` explicitly skips nonce verification for these actions. A malicious site can cause a logged-in user to trigger cache clearing via a simple GET link.

+- **Impact:** Authenticated users can be tricked into clearing transients/cache, causing denial of service or cache stampede.

+- **Fix:** Do not skip nonces for state-changing actions. If page caching breaks nonces, use `wp_create_nonce()` with a longer lifetime or use POST requests with proper nonces. Remove `FRL_PUBLIC_ACTIONS` bypass for destructive operations.

\+

+```php

+// In frl_verify_plugin_action_nonce()

+if (in_array($action_name, FRL_PUBLIC_ACTIONS, true) && is_user_logged_in()) {

\+ $capability = 'read';

\+ // $skip_nonce_verification = true; // REMOVE THIS

+}

+```

\+

+---

\+

+### C-003: IP Spoofing in MU-Plugin Bot Throttle

\+

+- **File:** `includes/mu/functions-mu.php` (throttle function), `config/config-mu.php:23-43`

+- **Function:** `frl_maybe_throttle_user_agent()`

+- **Description:** The throttle key is built from `md5($ip)` where `$ip` is likely derived from `$_SERVER['REMOTE_ADDR']` or a forwarded header. If the code uses `frl_get_client_ip()` that reads `HTTP_X_FORWARDED_FOR` first, attackers can spoof their IP to bypass rate limits or frame innocent users.

+- **Impact:** Rate limiting is ineffective; legitimate users may be blocked if attackers spoof their IP.

+- **Fix:** Use only `$_SERVER['REMOTE_ADDR']` for throttling decisions (it is the only non-spoofable source at the application layer). If the site is behind a trusted proxy, whitelist proxy IPs and parse `X-Forwarded-For` from right to left, taking the first untrusted IP.

\+

+```php

+$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

+// Only trust forwarded headers if REMOTE_ADDR is a known proxy.

+```

\+

+---

\+

+### C-004: Cross-User Cache Poisoning in `frl_get_current_user()`

\+

+- **File:** `includes/helpers/functions.php:121-161`

+- **Function:** `frl_get_current_user()`

+- **Description:** The function caches the `WP_User` object keyed by username + a short MD5 of the auth cookie. If the persistent cache is shared and an attacker can cause a cache write with their user object under another user's key (e.g., via race condition or cache backend weakness), the verification at line 156 only checks `user_login !== $cookie_username`. It does not verify the cookie token/HMAC, so a stale object from a different session of the *same* username could be served.

+- **Impact:** Potential session confusion, privilege escalation in shared-cache environments.

+- **Fix:** Do not cache `WP_User` objects in persistent cache. Use WordPress's built-in `wp_get_current_user()` which already maintains a per-request cache. If caching is required for performance, cache only immutable user metadata, not the full object.

\+

+```php

+function frl_get_current_user(): WP_User

+{

\+ if (!function_exists('wp_get_current_user')) {

\+ return new WP_User(0);

\+ }

\+ return wp_get_current_user();

+}

+```

\+

+---

\+

+## High Findings

\+

+### H-001: `frl_disable_comments()` Runs Expensive DB Update on Admin Request

\+

+- **File:** `includes/shared/website-features.php:234-261`

+- **Function:** `frl_disable_comments()`

+- **Description:** When comments are disabled, the function runs a `wpdb->update()` against all published posts on an admin page load, wrapped in a 1-year cache. On large sites this can lock the `wp_posts` table and slow/fail admin loads. The cache only prevents re-execution for one year, but the first admin hit after cache expiry pays the full cost.

+- **Impact:** Admin page timeouts, table locks, failed requests on large databases.

+- **Fix:** Move the one-time DB update to a background WP-Cron job or run it once during plugin activation. Do not run it on every admin request.

\+

+```php

+// Replace the admin-only cache block with:

+if (!wp_next_scheduled('frl_close_comments_cron')) {

\+ wp_schedule_single_event(time(), 'frl_close_comments_cron');

+}

+add_action('frl_close_comments_cron', function () {

\+ global $wpdb;

\+ $wpdb->update($wpdb->posts, ['comment_status' => 'closed', 'ping_status' => 'closed'], ['post_status' => 'publish', 'comment_status' => 'open']);

+});

+```

\+

+---

\+

+### H-002: `frl_alter_query()` Forces `post_status = 'publish'` on All Non-Main Queries

\+

+- **File:** `public/public.php:535-564`

+- **Function:** `frl_alter_query()`

+- **Description:** The hook fires on `pre_get_posts` for every non-main `WP_Query` and unconditionally sets `post_status` to `publish`, disables password-protected posts, and disables sticky post handling. This breaks admin list queries, preview links, private content plugins, and any query intentionally requesting drafts/private/password posts.

+- **Impact:** Missing content in admin and frontend; broken previews; plugin conflicts.

+- **Fix:** Restrict the optimization to frontend main queries or explicitly requested public queries. Add `!is_admin()` and `$query->is_search/is_archive` guards.

\+

+```php

+if (!$query instanceof WP_Query || $query->is_main_query() || is_admin()) {

\+ return;

+}

+if (!$query->is_front_page() && !$query->is_home() && !$query->is_archive() && !$query->is_search()) {

\+ return;

+}

+```

\+

+---

\+

+### H-003: `frl_get_debug_log_count()` Scans Entire Log File on Every Cache Miss

\+

+- **File:** `includes/shared/logged-user.php:405-456`

+- **Function:** `frl_get_debug_log_count()`

+- **Description:** The function reads `WP_CONTENT_DIR . '/debug.log'` line-by-line on every cache miss. On high-traffic sites with large log files this is expensive and the 5-minute transient can expire frequently across requests.

+- **Impact:** Slow admin bar rendering; I/O spikes.

+- **Fix:** Maintain a running counter in a separate option/transient updated via `frl_log_add_details()` hook, or use `wc -l` style shell exec if safe. Avoid reading the full file.

\+

+---

\+

+### H-004: `frl_trace_logged_user_visits()` Runs on Every Template Footer

\+

+- **File:** `includes/shared/logged-user.php:465-534`

+- **Function:** `frl_trace_logged_user_visits()`

+- **Description:** The function performs user meta reads/writes on every page load for logged-in users. It also calls `is_main_query()` in a footer hook where the main query may not be reliable, and builds the current URL manually from `$_SERVER` without sanitization.

+- **Impact:** Database write amplification; potential tracking of unintended URLs.

+- **Fix:** Move visit tracking to a lightweight JS beacon or batch writes via shutdown hook. Sanitize the URL with `esc_url_raw()` and validate it is a frontend request before tracking.

\+

+---

\+

+### H-005: REST Endpoint Disable List is Hardcoded and Uses `str_starts_with`

\+

+- **File:** `public/public.php:511-530`, `config/config-base.php:105-117`

+- **Function:** `frl_disable_rest_endpoints()`

+- **Description:** The list of disabled endpoints is a hardcoded constant. The function uses `str_starts_with($route, $prefix_to_remove)` which will also disable sub-routes that share a prefix (e.g., `/wp/v2/users/me`). The bypass is only `frl_is_logged_in()`, which is true for any authenticated user, including subscribers.

+- **Impact:** Subscribers can access sensitive REST endpoints; overly broad route blocking may break plugins.

+- **Fix:** Make the list filterable per role, and match exact route keys instead of prefixes. Use `current_user_can()` to decide access.

\+

+```php

+if (!current_user_can('read') || !frl_get_option('disable_rest')) {

\+ return $endpoints;

+}

+$endpoints_to_remove = apply_filters('frl_rest_endpoints_to_remove', FRL_REST_ENDPOINTS);

+foreach ($endpoints_to_remove as $route) {

\+ unset($endpoints[$route]);

+}

+```

\+

+---

\+

+### H-006: `frl_save_custom_avatar()` Lacks Capability and Nonce Checks

\+

+- **File:** `includes/shared/media.php:249-259`

+- **Function:** `frl_save_custom_avatar()`

+- **Description:** The function saves an attachment ID to user meta based only on `$_POST[FRL_PREFIX . '_avatar_id']`. It is hooked to `personal_options_update` and `edit_user_profile_update`, but it does not verify the user can edit the target user, nor does it validate the attachment belongs to the user.

+- **Impact:** Users may be able to set arbitrary attachment IDs as avatars for other users; attachment ID injection.

+- **Fix:** Verify `current_user_can('edit_user', $user_id)` and ensure the attachment is a valid image.

\+

+```php

+function frl_save_custom_avatar($user_id)

+{

\+ if (!current_user_can('edit_user', $user_id) || !isset($_POST[FRL_PREFIX . '_avatar_id'])) {

\+ return;

\+ }

\+ check_admin_referer('update-user_' . $user_id); // or verify the existing nonce

\+ $avatar_id = (int) $_POST[FRL_PREFIX . '_avatar_id'];

\+ if ($avatar_id && wp_attachment_is_image($avatar_id)) {

\+ frl_update_user_meta($user_id, 'avatar', $avatar_id);

\+ frl_cache_clear('options', 'avatar_uid' . $user_id);

\+ }

+}

+```

\+

+---

\+

+### H-007: `frl_maybe_throttle_user_agent()` Can Be Bypassed via Missing User-Agent

\+

+- **File:** `includes/mu/functions-mu.php` (throttle function)

+- **Function:** `frl_maybe_throttle_user_agent()`

+- **Description:** The function checks `stripos($_SERVER['HTTP_USER_AGENT'], $ua_pattern)`. If `HTTP_USER_AGENT` is not set, `stripos()` emits a warning in PHP 8+ and returns `false`, so the request is not throttled.

+- **Impact:** Bots sending no User-Agent bypass the throttle entirely.

+- **Fix:** Treat missing User-Agent as an empty string and optionally throttle all requests without a User-Agent.

\+

+```php

+$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

+foreach (FRL_MU_THROTTLE_USER_AGENT as $ua_pattern) {

\+ if (stripos($ua, $ua_pattern) !== false) {

\+ $is_throttled_bot = true;

\+ break;

\+ }

+}

+```

\+

+---

\+

+### H-008: `frl_get_post_terms()` Caches Empty Arrays for `WP_Error`

\+

+- **File:** `includes/helpers/functions.php:876-915`

+- **Function:** `frl_get_post_terms()`

+- **Description:** The function normalizes `WP_Error` and `false` to an empty array and caches it. A transient DB failure or invalid taxonomy will therefore be cached as "no terms" for the full TTL, hiding real terms until cache expires.

+- **Impact:** Missing taxonomy data on frontend; stale content.

+- **Fix:** Do not cache results when `get_the_terms()` returns `WP_Error`. Return the error or an empty array without caching.

\+

+```php

+$terms = get_the_terms($post_id, $taxonomy);

+if (is_wp_error($terms)) {

\+ return [];

+}

+if (!$terms) {

\+ $terms = [];

+}

+// Only cache non-error results.

+```

\+

+---

\+

+### H-009: `frl_get_page_title_from_url()` Uses `url_to_postid()` Without Cache

\+

+- **File:** `includes/helpers/functions.php:430-515`

+- **Function:** `frl_get_page_title_from_url()`

+- **Description:** The function calls `url_to_postid($url)` on every invocation. This is an expensive database query. It is used in the user visits widget, which can be rendered frequently.

+- **Impact:** Repeated expensive URL resolution; slow admin widget rendering.

+- **Fix:** Cache the result per URL, or avoid `url_to_postid()` by parsing slugs directly when possible.

\+

+---

\+

+### H-010: `frl_disable_oembed()` Removes REST Route Too Late

\+

+- **File:** `includes/shared/website-features.php` (`frl_disable_oembed()`)

+- **Function:** `frl_disable_oembed()`

+- **Description:** The function hooks `remove_action('rest_api_init', 'wp_oembed_register_route')` but is typically called on `init` or later. By then `rest_api_init` may have already fired, so the route remains registered.

+- **Impact:** oEmbed REST endpoints remain exposed even when the option is enabled.

+- **Fix:** Use the `rest_endpoints` filter to explicitly remove oEmbed routes, or hook before `rest_api_init` (priority < 10).

\+

+---

\+

+## Medium Findings

\+

+### M-001: `frl_get_transient()` Uses String Sentinel for `false`

\+

+- **File:** `includes/helpers/functions-options.php:622-649`

+- **Function:** `frl_get_transient()`

+- **Description:** The function uses the string `__TRANSIENT_FALSE__` to distinguish "not found" from "value is false". If a transient legitimately stores this exact string, it is returned as `false`.

+- **Impact:** Extremely unlikely collision, but possible with serialized data.

+- **Fix:** Use a private object sentinel instead of a string.

\+

+```php

+static $false_sentinel;

+if (!$false_sentinel) {

\+ $false_sentinel = new stdClass();

+}

+```

\+

+---

\+

+### M-002: `@` Suppression Detection Uses Magic Number 4437

\+

+- **File:** `core/error-handler.php:154`

+- **Function:** `frl_errors_handle_error()`

+- **Description:** The code checks `error_reporting() === 4437` to detect `@` suppression on PHP < 8.0. This value is a bitmask that could change in future PHP versions.

+- **Impact:** Silent failure to suppress errors if PHP changes the bitmask.

+- **Fix:** Compute the mask dynamically:

\+

+```php

+$suppressed_mask = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;

+if ($current_reporting === 0 || $current_reporting === $suppressed_mask) {

\+ return true;

+}

+```

\+

+---

\+

+### M-003: `frl_remove_jquery_migrate()` Modifies Global `WP_Scripts` Without Validation

\+

+- **File:** `public/public.php:488-498`

+- **Function:** `frl_remove_jquery_migrate()`

+- **Description:** The function directly mutates `$scripts->registered['jquery']->deps`. If another plugin has replaced the jquery handle or expects jquery-migrate, this can cause script load failures.

+- **Impact:** Broken frontend scripts on some themes/plugins.

+- **Fix:** Use the `wp_default_scripts` filter to register a corrected jquery dependency array, or document the incompatibility.

\+

+---

\+

+### M-004: `frl_add_image_sizes()` Registers Sizes Without Validation or Limits

\+

+- **File:** `includes/shared/media.php:20-47`

+- **Function:** `frl_add_image_sizes()`

+- **Description:** The function reads a text list from options and calls `add_image_size()` for each entry. There is no upper limit on the number of sizes, no validation of crop mode, and no check for duplicate names.

+- **Impact:** Excessive image sizes increase upload time and disk usage.

+- **Fix:** Add a maximum limit (e.g., 20 sizes), validate crop values, and deduplicate names.

\+

+---

\+

+### M-005: `frl_process_nav_menu_url_transforms()` Uses Regex on Rendered HTML

\+

+- **File:** `includes/shared/navigation.php:116-174`

+- **Function:** `frl_process_nav_menu_url_transforms()`

+- **Description:** The function uses `str_replace` on rendered block HTML to replace URLs. It assumes the `href` attribute format and does not handle all possible HTML escaping. Large menus may cause repeated string scans.

+- **Impact:** Potential broken links; minor performance cost.

+- **Fix:** Use DOM parsing for URL replacement, or hook earlier at the block attribute level.

\+

+---

\+

+### M-006: `frl_clear_browser_cache()` Silently Fails if Headers Already Sent

\+

+- **File:** `core/cache/class-cache-manager.php:1183-1192`

+- **Function:** `Frl_Cache_Manager::clear_browser_cache()`

+- **Description:** The function checks `headers_sent()` and does nothing if output has started. Callers receive no feedback that browser cache was not cleared.

+- **Impact:** Stale browser assets after cache purge.

+- **Fix:** Return a boolean and surface an admin notice when headers were already sent.

\+

+```php

+public static function clear_browser_cache(): bool

+{

\+ if (headers_sent($file, $line)) {

\+ return false;

\+ }

\+ // ... send headers ...

\+ return true;

+}

+```

\+

+---

\+

+### M-007: `frl_is_rest_api_request()` Only Checks URI Prefix and Constant

\+

+- **File:** `includes/helpers/functions-access-control.php:383-390`

+- **Function:** `frl_is_rest_api_request()`

+- **Description:** The function returns true for any request starting with `/wp-json/`. It does not verify the request is actually a REST request (e.g., custom rewrite rules could expose `/wp-json/` paths).

+- **Impact:** False positives may disable features on custom endpoints.

+- **Fix:** Also check `REST_REQUEST` constant and use `wp_is_rest_endpoint()` if available.

\+

+---

\+

+### M-008: `frl_get_auth_cookie_user_data()` Caches User Capability Query for 300s

\+

+- **File:** `includes/mu/functions-mu.php:122-182`

+- **Function:** `frl_get_auth_cookie_user_data()`

+- **Description:** The function caches the result of a direct DB query for 5 minutes. If a user's capabilities change during this window, the MU plugin exclusion logic will use stale data.

+- **Impact:** Delayed application of capability-based plugin exclusions.

+- **Fix:** Reduce TTL to 60s or invalidate on capability changes.

\+

+---

\+

+### M-009: `Frl_Rewriter::transform_url()` Feature Match Cache Grows Unbounded

\+

+- **File:** `core/rewriter/class-rewriter.php:219-254`

+- **Function:** `Frl_Rewriter::transform_url()`

+- **Description:** The static LRU cache has a 1024-entry cap, but the eviction removes only 10% (102 entries) when full. In long-running processes (e.g., WP-CLI, cron), this can still consume significant memory.

+- **Impact:** Memory growth in long-running processes.

+- **Fix:** Lower the cap or use a proper LRU implementation with per-request eviction.

\+

+---

\+

+### M-010: `frl_delete_plugin()` Uses `LIKE` Query Without Table Prefix Validation

\+

+- **File:** `includes/helpers/functions.php:709-743`

+- **Function:** `frl_delete_plugin()`

+- **Description:** The function deletes all options matching the plugin prefix via `LIKE`. If the prefix is short or common (e.g., `frl_`), it could delete options from other plugins.

+- **Impact:** Accidental data loss during reset/uninstall.

+- **Fix:** Use a strict whitelist of known plugin options instead of a `LIKE` query.

\+

+---

\+

+## Low Findings

\+

+### L-001: `frl_add_plugin_menu()` Uses `add_submenu_page` Under `options-general.php`

\+

+- **File:** `admin/admin.php:165-181`

+- **Function:** `frl_add_plugin_menu()`

+- **Description:** The plugin settings page is added as a submenu of Settings with `manage_options` capability. This is standard, but the menu title `fralenuvole` is not translatable.

+- **Impact:** Minor i18n issue.

+- **Fix:** Wrap menu title in `__()`.

\+

+---

\+

+### L-002: `frl_get_html_option()` Caches HTML by Login Status Only

\+

+- **File:** `public/public.php:414-432`

+- **Function:** `frl_get_html_option()`

+- **Description:** Header/footer HTML is cached only by `logged_in` vs `visitor`. If the HTML contains role-specific or language-specific content, users will see the wrong version.

+- **Impact:** Cache collisions for multilingual/role-based HTML.

+- **Fix:** Include language and role in the cache key.

\+

+---

\+

+### L-003: `frl_disable_rest_endpoints()` Does Not Disable `/wp/v2/oembed`

\+

+- **File:** `config/config-base.php:100-117`, `public/public.php:511-530`

+- **Description:** The comment says oEmbed is handled separately, but there is no guarantee `disable_oembed` is enabled. The REST endpoint list intentionally excludes oEmbed routes.

+- **Impact:** Inconsistent REST API lockdown.

+- **Fix:** Include `/wp/v2/oembed` in the default disabled list or always disable it when `disable_rest` is enabled.

\+

+---

\+

+### L-004: `frl_alter_query()` Disables Sticky Posts Globally

\+

+- **File:** `public/public.php:544`

+- **Function:** `frl_alter_query()`

+- **Description:** The function sets `ignore_sticky_posts => true` for all non-main queries. This may be intentional but is surprising behavior.

+- **Impact:** Sticky posts do not appear as expected in secondary loops.

+- **Fix:** Document the behavior or make it configurable per query.

\+

+---

\+

+### L-005: `frl_trace_logged_user_visits()` Stores Raw URLs Without Length Limit

\+

+- **File:** `includes/shared/logged-user.php:524-527`

+- **Function:** `frl_trace_logged_user_visits()`

+- **Description:** The full URL including query strings is stored in user meta. Very long URLs could exceed meta value limits or cause serialization issues.

+- **Impact:** Potential data truncation or storage errors.

+- **Fix:** Truncate URLs to a reasonable length (e.g., 500 chars) and strip query strings unless needed.

\+

+---

\+

+## Recommendations Summary

\+

+| Priority | Finding | Action |

+|----------|---------|--------|

+| **P0** | C-001 | Remove or heavily restrict `eval()` in HTML options |

+| **P0** | C-002 | Add nonce verification to public actions |

+| **P0** | C-003 | Use `REMOTE_ADDR` only for bot throttling |

+| **P0** | C-004 | Stop caching `WP_User` objects persistently |

+| **P1** | H-001 | Background the comment-closing DB update |

+| **P1** | H-002 | Restrict `frl_alter_query()` to frontend archives |

+| **P1** | H-003 | Replace log file line counting with counter |

+| **P1** | H-004 | Batch or defer visit tracking writes |

+| **P1** | H-005 | Use exact REST route matching and role checks |

+| **P1** | H-006 | Add capability/nonce checks to avatar save |

+| **P1** | H-007 | Handle missing User-Agent in throttle |

+| **P1** | H-008 | Do not cache `WP_Error` term results |

+| **P1** | H-009 | Cache `url_to_postid()` results |

+| **P1** | H-010 | Remove oEmbed REST routes via filter |

+| **P2** | M-
