<?php
/**
 * PHPStan bootstrap file - WordPress and plugin constants for static analysis
 *
 * This file provides constants that may be missing from the official
 * wordpress-stubs package during PHPStan analysis.
 */

// WordPress time constants
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('HOUR_IN_SECONDS'))   { define('HOUR_IN_SECONDS', 3600); }
if (!defined('DAY_IN_SECONDS'))    { define('DAY_IN_SECONDS', 86400); }
if (!defined('WEEK_IN_SECONDS'))   { define('WEEK_IN_SECONDS', 604800); }
if (!defined('MONTH_IN_SECONDS'))  { define('MONTH_IN_SECONDS', 2592000); }
if (!defined('YEAR_IN_SECONDS'))   { define('YEAR_IN_SECONDS', 31536000); }

// WordPress debug and environment constants
if (!defined('WP_DEBUG'))          { define('WP_DEBUG', false); }
if (!defined('WP_DEBUG_LOG'))      { define('WP_DEBUG_LOG', false); }
if (!defined('WP_DEBUG_DISPLAY'))  { define('WP_DEBUG_DISPLAY', true); }
if (!defined('SCRIPT_DEBUG'))      { define('SCRIPT_DEBUG', false); }

// WordPress directory constants
if (!defined('WP_CONTENT_DIR'))    { define('WP_CONTENT_DIR', '/wp-content'); }
if (!defined('WP_PLUGIN_DIR'))     { define('WP_PLUGIN_DIR', '/wp-content/plugins'); }
if (!defined('WPMU_PLUGIN_DIR'))   { define('WPMU_PLUGIN_DIR', '/wp-content/mu-plugins'); }
if (!defined('UPLOADBLOGSDIR'))    { define('UPLOADBLOGSDIR', '/wp-content/blogs.dir'); }

// WordPress URL constants
if (!defined('WP_CONTENT_URL'))    { define('WP_CONTENT_URL', '/wp-content'); }
if (!defined('WP_PLUGIN_URL'))     { define('WP_PLUGIN_URL', '/wp-content/plugins'); }

// Plugin constants - define before includes/bootstrap.php loads
// FRL_DIR_PATH: absolute path to the plugin directory
if (!defined('FRL_DIR_PATH')) {
    define('FRL_DIR_PATH', '/mnt/backup/BACKUP/WWW/PBS/public_html/wp-content/plugins/fralenuvole/');
}
if (!defined('FRL_DIR_URL')) {
    define('FRL_DIR_URL', 'https://example.com/wp-content/plugins/fralenuvole/');
}

// Signal to includes/bootstrap.php that we're running under PHPStan
// This prevents WordPress-dependent code (like $wpdb) from loading
if (!defined('PHPSTAN_RUNNING')) {
    define('PHPSTAN_RUNNING', true);
}

// Define WP_CLI for PHPStan so includes/bootstrap.php doesn't override with false
if (!defined('WP_CLI')) {
    define('WP_CLI', false);
}

// WordPress database constants for $wpdb->get_results()
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('ARRAY_N')) { define('ARRAY_N', 'ARRAY_N'); }
if (!defined('OBJECT'))  { define('OBJECT', 'OBJECT'); }
if (!defined('OBJECT_K')){ define('OBJECT_K', 'OBJECT_K'); }

// WordPress cookie constants
if (!defined('COOKIEPATH'))  { define('COOKIEPATH', '/'); }
if (!defined('COOKIE_DOMAIN')){ define('COOKIE_DOMAIN', ''); }

// WordPress rewrite endpoint constants
if (!defined('EP_ROOT'))     { define('EP_ROOT', 1); }
if (!defined('EP_PERMALINK')){ define('EP_PERMALINK', 2); }

// Stub for html class used in return types
if (!class_exists('html')) {
    /**
     * @internal This is a stub class for PHPStan analysis
     * @property string $content
     */
    class html {
        /** @param mixed $content */
        public function __construct($content = '') {}

        /** @return string */
        public function __toString() {
            return '';
        }
    }
}
