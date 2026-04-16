DEBUG MODES (FRL_MODE)
=======================

The plugin supports debug/troubleshooting modes via URL parameter or constant.

URL PARAMETER: ?frlmode=<mode>
================================
Append to any page URL for temporary mode activation:

- ?frlmode=disable   - Completely disables the plugin
- ?frlmode=core      - Core mode (no optimizations, minimal hooks)
- ?frlmode=nocache   - Disables internal cache system
- ?frlmode=migrate   - Migration mode (bypasses login for environment detection)

WP-CONFIG CONSTANT: define('FRL_MODE', '<mode>')
================================================
Add to wp-config.php for persistent mode:

- define('FRL_MODE', 'migrate')  - Useful during domain migration

NOTES
=====
- URL parameter takes precedence and defines FRL_MODE constant
- 'migrate' mode allows environment detection without requiring logged-in admin
- 'disable' stops plugin initialization entirely (emergency only)
