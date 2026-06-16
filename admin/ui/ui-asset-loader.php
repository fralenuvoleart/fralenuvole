<?php

/**
 * External Scripts Loader
 *
 * Centralized management of external scripts used in the admin UI
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Critical hooks for immediate registration
add_action('admin_enqueue_scripts', 'frl_asset_loader_scripts', -999, 0);

// Inline critical CSS to prevent FOUC
add_action('admin_head', 'frl_inline_critical_admin_css', 1, 0);

// Load Prism.js scripts with the lowest possible priority to ensure it loads after all content
add_action('admin_footer', 'frl_enqueue_codemirror_scripts', 999, 0);
add_action('admin_footer', 'frl_enqueue_prism_scripts', 999, 0);
add_action('wp_print_footer_scripts', 'frl_add_prism_init_script', 1000, 0);

/**
 * Inline critical CSS to prevent FOUC (Flash of Unstyled Content).
 *
 * Specifically targets tab visibility before main CSS loads to ensure a smooth
 * transition. Assumes this code only runs on the plugin's admin settings page.
 *
 * @return void
 */
function frl_inline_critical_admin_css()
{
    // Output the critical CSS directly as we assume we are on the correct page
?>
    <style>
        /* Hide tab nav and  all jQuery UI tab panels initially */
        .frl-section,
        #frl-tabs-nav {
            opacity: 0;
            transition: opacity 0.1s ease;
         }
        /* Show tab nav and panel marked active by jQuery UI */
        #frl-tabs-nav.ui-tabs-nav,
        .frl-section:is(.ui-tabs-active, [aria-hidden="false"]) {
            opacity: 1;
        }
    </style>
<?php
}

/**
 * Enqueue styles and scripts for the admin UI.
 *
 * Handles the loading of core UI scripts, plugin-specific assets, and
 * localization of data for various admin components.
 *
 * @return void
 */
function frl_asset_loader_scripts(): void
{
    // --- Explicitly request core UI scripts early ---
    wp_enqueue_script('jquery');
    wp_enqueue_script('jquery-ui-core');
    wp_enqueue_script('jquery-ui-tabs');
    // --- End explicit request ---

    // Define assets managed by this loader
    $assets = [
        'admin-ui-css' => 'assets/css/admin-ui.css',
        'admin-ui-js' => 'assets/js/admin-ui.js',
        'dashboard-css' => 'assets/css/admin-dashboard.css',
        'tag-validator-js' => 'assets/js/admin-tag-validator.js',
        'log-manager-css' => 'assets/css/admin-log-manager.css',
        'log-manager-js' => 'assets/js/admin-log-manager.js',
        'import-export-js' => 'assets/js/admin-import-export.js',
    ];

    frl_enqueue_scripts(
        $assets,
        'asset_loader'
    );

    // Localize scripts
    wp_localize_script(
        FRL_PREFIX . '-tag-validator',
        'frlTagValidator',
        [
            'adminUrl' => admin_url(),
            'pluginPage' => FRL_NAME,
            'nonce' => frl_create_nonce('tag-validator')
        ]
    );
    wp_localize_script(
        FRL_PREFIX . '-log-manager',
        'logManagerData',
        array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('log_manager_nonce'),
        )
    );
    // Generate export URLs
    $export_action = 'frl_post_export_settings';
    $export_url = admin_url('admin-post.php') . '?' . http_build_query([
        'action' => $export_action,
        'nonce' => frl_create_nonce('export_settings_nonce')
    ]);

    $export_translations_url = admin_url('admin-post.php') . '?' . http_build_query([
        'action' => 'frl_post_export_translations',
        'nonce' => frl_create_nonce('export_translations_nonce')
    ]);

    wp_localize_script(
        FRL_PREFIX . '-import-export',
        'frlImportExport',
        [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'exportUrl' => $export_url,
            'translationsExportUrl' => $export_translations_url,
            'importNonce' => frl_create_nonce('ajax_import_nonce'),
            'translationNonce' => frl_create_nonce('ajax_translation_nonce'),
            'strings' => [
                'selectFile' => esc_js(__('Please select a file to import.', FRL_PREFIX)),
                'unknownError' => esc_js(__('Unknown error', FRL_PREFIX)),
                'importError' => esc_js(__('Error during import: ', FRL_PREFIX)),
                'importRetry' => esc_js(__('Error during import. Please try again.', FRL_PREFIX)),
                'translationImportError' => esc_js(__('Error during translation import: ', FRL_PREFIX)),
                'translationRetry' => esc_js(__('Error during translation import. Please try again.', FRL_PREFIX)),
            ],
        ]
    );
}

/**
 * Enqueue Prism.js scripts and styles with deferred loading.
 *
 * Performance Optimization:
 * - Loads Prism.js assets late in the page lifecycle.
 * - Uses async/defer attributes to prevent render blocking.
 *
 * @return void
 */
function frl_enqueue_prism_scripts()
{
    // Register Prism.js CSS
    wp_enqueue_style(
        FRL_PREFIX . '-prism-css',
        FRL_DIR_URL . 'assets/lib/prism/prism.min.css',
        [],
        '1.29.0'
    );

    // Add async loading attribute to CSS
    add_filter('style_loader_tag', function ($tag, $handle) {
        if ($handle === FRL_PREFIX . '-prism-css') {
            if (!str_contains($tag, 'media="print"')) {
                return str_replace(' rel=', ' media="print" onload="this.media=\'all\'" rel=', $tag);
            }
        }
        return $tag;
    }, 10, 2);

    // Load Prism.js script with defer attribute
    wp_enqueue_script(
        FRL_PREFIX . '-prism-js',
        FRL_DIR_URL . 'assets/lib/prism/prism.min.js',
        [],
        '1.29.0',
        true // Load in footer
    );

    // Add defer attribute to script
    add_filter('script_loader_tag', function ($tag, $handle) {
        if ($handle === FRL_PREFIX . '-prism-js') {
            if (!str_contains($tag, ' defer')) {
                return str_replace(' src=', ' defer src=', $tag);
            }
        }
        return $tag;
    }, 10, 2);

    // Load HTML language support with defer
    wp_enqueue_script(
        FRL_PREFIX . '-prism-markup',
        FRL_DIR_URL . 'assets/lib/prism/prism-markup.min.js',
        [FRL_PREFIX . '-prism-js'],
        '1.29.0',
        true
    );

    // Add defer attribute to script
    add_filter('script_loader_tag', function ($tag, $handle) {
        if ($handle === FRL_PREFIX . '-prism-markup') {
            if (!str_contains($tag, ' defer')) {
                return str_replace(' src=', ' defer src=', $tag);
            }
        }
        return $tag;
    }, 10, 2);
}

/**
 * Add centralized Prism initialization script for syntax highlighting.
 *
 * Handles both the initial highlighting of code blocks and re-initialization
 * when dynamic content is loaded via the 'frl_content_loaded' event.
 *
 * @return void
 */
function frl_add_prism_init_script()
{
?>
    <script>
        jQuery(document).ready(function($) {
            // Centralized function to initialize Prism highlighting
            function initPrismHighlighting() {
                if (typeof Prism !== "undefined") {
                    Prism.highlightAll();
                }
            }

            // Initial run
            initPrismHighlighting();

            // Re-initialize after dynamic content is loaded
            $(document).on("frl_content_loaded", function(e, container) {
                if (container && typeof Prism !== "undefined") {
                    Prism.highlightAllUnder(container);
                } else {
                    initPrismHighlighting();
                }
            });
        });
    </script>
<?php
}

/**
 * Enqueue and initialize CodeMirror for HTML textareas.
 *
 * Targets textareas with IDs ending in '_html' and integrates them with
 * the WordPress code editor.
 *
 * @return void
 */
function frl_enqueue_codemirror_scripts()
{
    // Explicitly load required CodeMirror scripts
    wp_enqueue_script('wp-theme-plugin-editor');
    wp_enqueue_style('wp-codemirror');

    // Enqueue the WordPress code editor with explicit scripts
    $settings = wp_enqueue_code_editor(array(
        'type' => 'application/x-httpd-php',
        'codemirror' => array(
            'lineNumbers' => true,
            'matchBrackets' => true,
            'indentUnit' => 4,
            'indentWithTabs' => true,
            'mode' => array(
                'name' => 'php',
                'htmlMode' => true  // Enables HTML mixed mode inside PHP
            )
        )
    ));

    if (false !== $settings) {
        wp_add_inline_script(
            FRL_PREFIX . '-admin-ui-js',
            'jQuery(function($) {
                var cmEditors = {};

                function initCodeMirrorForPanel(panelElement) {
                    var $panel = $(panelElement);
                    if (!$panel || !$panel.length) { return; }
                    $panel.find("textarea[id$=\'_html\']").each(function() {
                        var $textarea = $(this);
                        var id = $textarea.attr("id");
                        if (!cmEditors[id]) {
                            try {
                                cmEditors[id] = wp.codeEditor.initialize($textarea, ' . wp_json_encode($settings) . ');
                                $textarea.closest("form").on("submit", function() {
                                    if (cmEditors[id] && cmEditors[id].codemirror) {
                                        $textarea.val(cmEditors[id].codemirror.getValue());
                                    }
                                });
                            } catch (e) {
                                console.error("CodeMirror setup error for " + id + ":", e);
                            }
                        }
                    });
                }

                function refreshCodeMirrorInPanel(panelElement) {
                    var $panel = $(panelElement);
                    if (!$panel || !$panel.length) { return; }
                    $panel.find("textarea[id$=\'_html\']").each(function() {
                        var id = $(this).attr("id");
                        if (cmEditors[id] && cmEditors[id].codemirror) {
                            cmEditors[id].codemirror.refresh();
                        }
                    });
                }

                $(document).on("frl_content_loaded", function() {
                    var $initialActivePanel = $(".frl-sectionui-tabs-active");
                    if ($initialActivePanel.length) {
                        initCodeMirrorForPanel($initialActivePanel[0]);
                    } else {
                        initCodeMirrorForPanel($(".frl-section").first()[0]);
                    }
                });

                $("#tabs").on("tabsactivate", function(event, ui) {
                    initCodeMirrorForPanel(ui.newPanel[0]);
                    refreshCodeMirrorInPanel(ui.newPanel[0]);
                });

                setTimeout(function() {
                    if ($.isEmptyObject(cmEditors)) {
                        var $activePanel = $(".frl-section:visible");
                        if ($activePanel.length) {
                            initCodeMirrorForPanel($activePanel[0]);
                        }
                    }
                }, 300);

            });'
        );
    } else {
        frl_log('wp_enqueue_code_editor returned false. Inline script NOT added.');
    }
}
