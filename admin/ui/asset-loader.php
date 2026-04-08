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
 * Inline critical CSS to prevent FOUC (Flash of Unstyled Content)
 * Specifically targets tab visibility before main CSS loads.
 * Assumes this code only runs on the plugin's admin settings page.
 *
 * @since X.Y.Z // TODO: Add appropriate version
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
 * Enqueues styles and scripts for the admin UI.
 *
 * @since 1.0.0
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
    ];

    frl_enqueue_scripts(
        $assets,
        'asset_loader'
    );

    // Localize scripts (if needed, keep separate)
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
}

/**
 * Enqueue Prism.js scripts and styles separately with deferred loading
 *
 * PERFORMANCE OPTIMIZATION:
 * - Loads all Prism.js assets (CSS and JS) late in page lifecycle
 * - Uses async/defer attributes to prevent render blocking
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
 * Add centralized Prism initialization script to handle toggle buttons and syntax highlighting
 *
 * This function adds a single initialization script that handles both:
 * 1. Toggle button functionality for code examples
 * 2. Prism syntax highlighting for all code blocks
 *
 * It's called at the very end of the page to ensure all content is loaded
 * and it provides a custom event 'frl_content_loaded' for dynamic content
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
 * Enqueue CodeMirror for textareas with IDs ending in '_html'
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
