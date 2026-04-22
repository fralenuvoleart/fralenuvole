FRL_HAS_ACCESS - WHO CAN ACCESS WHAT
====================

SUPERADMIN (User ID 1 only)
---------------------------
- All Plugin Features
- FRL_NAME links in adminbar

PLUGIN ADMIN (delete_plugins capability)
-----------------------------------------
Who: User ID 1, Administrators, or any role with delete_plugins
- Plugin settings page
- Debug log
- Cache management (most buttons)
- Environment switcher (see current server type)
- Developer tab

NOT LOGGED IN
-------------
- Nothing

CODE EXAMPLES
-------------

// User ID 1 only (fralenuvole.art links, critical features)
if (frl_has_access('superadmin')) {
    // Show restricted links
}

// Plugin admin - default (User ID 1 + Administrators)
if (frl_has_access()) {
    // Most admin features
}

// Administrators only (settings pages, environment switcher)
if (frl_has_access('manage_options')) {
    // Admin-level features
}

SPECIAL CASES
-------------
FRL_MODE = disable: Disables the plugin entirely.
FRL_MODE =  core:    Mimics 'disable_plugin' option behavior.
FRL_MODE = nocache: Bypasses the plugin's cache system.
FRL_MODE = migrate: Applies Environment Manager Config to current environment.
URL: ?frlmode=migrate (temporary for this request)
wp-config.php: define('FRL_MODE', 'migrate') (persistent)

NOTE ON PLUGIN ADMIN ACCESS
-------------
FRL_PLUGIN_ACCESS = 'delete_plugins'
'administrator' role = Has full access
'admin' role (custom) = Has not delete_plugins access
Adding delete_plugins to a custom role makes those users equivalent to standard Administrators for the plugin

ADMIN BAR ACCESS
--------------
Superadmin (User ID 1) + Plugin admins (Administrators 'manage_options') see plugin link and Environment Button in adminbar.
Superadmin (User ID 1) additionally sees FRL_NAME links.

CACHE & RESET BUTTONS
---------------------
How it works: frl_render_action_button() passes $cap to frl_has_access()
- Default cap: 'manage_options' (Administrators)
- Empty string '': falls back to 'delete_plugins' (Plugin Admin)

Plugin Admin only (delete_plugins):
- Clear Caches (All)
- Clear Caches (Hard)
- Reset Plugin
- Sync wp-config.php

Administrators (manage_options):
- Clear CSS/JS Caches
- Clear Shortcodes Caches
- Clear Caches (Light)
- Clear Plugin Transients
- Delete Orphan Options
- Reset Environment
- Reset Ignored Plugins
- Clear Website Transients
- Flush Rewrite Rules