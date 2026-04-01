<?php
// Exit if accessed directly
if (! defined('ABSPATH')) exit;

/**
 * Fralenuvole
 * class-import-export.php - Import/Export plugin settings
 */

/**
 * Class Frl_Import_Export
 *
 * Handles all import/export functionality for the plugin
 */
class Frl_Import_Export
{
    /**
     * Render the import/export widget
     *
     * @param string $title Widget title
     * @param string $description Widget description
     * @return string HTML content
     */
    public static function render(
        $title = '',
        $description = ''
    ) {
        $content = '';


        if (!empty($description)) {
            $content .= '<p>' . esc_html($description) . '</p>';
        }

        // Widget structure
        $static_content = self::render_content();
        // Process the static content to insert dynamic elements
        $dynamic_content = self::process_cached_content($static_content);

        // Pass the processed dynamic content to the UI renderer
        return frl_ui_render_widget(
            'import-export',
            $dynamic_content, // Use processed content
            $title,
            'after-section has-section-title',
            WEEK_IN_SECONDS
        );
    }

    /**
     * Render the import/export content
     *
     * @return string HTML content
     */
    public static function render_content()
    {
        // Cache the static structure of the content but keep the dynamic parts as placeholders

        $output = '<div class="metabox-holder">';

        // Settings Export/Import
        $output .= '<div class="postbox">';
        $output .= frl_ui_render_table_row(
            __('Plugin Settings'),
            '',
            false,
            'import-export-widget',
        );
        $output .= '<div class="inside">';
        $output .= '<p>' . __('Export all plugin settings as .json file.') . '</p>';

        // Placeholder for export URL (dynamically generated for security)
        $output .= '<p><a href="<!--EXPORT_URL_PLACEHOLDER-->" class="button button-secondary">' .
            __('Export Settings') . '</a></p>';

        $output .= '<div class="frl-import-settings-container">';
        $output .= '<p><button type="button" class="button button-secondary frl-import-settings-btn">' .
            __('Import Settings') . '</button></p>';
        $output .= '<p><label for="import-file-input">' . __('Select plugin settings file:') . '</label><br>';
        $output .= '<input type="file" id="import-file-input" accept="application/json"></p></div>';

        $output .= '<div id="import-settings-progress" style="display:none;">';
        $output .= '<p class="uploading">' . __('Uploading...') . '</p>';
        $output .= '<div class="progress-bar"></div></div>';
        $output .= '</div></div>';

        // Translations Export/Import
        $output .= '<div class="postbox">';
        $output .= frl_ui_render_table_row(
            __('String Translations'),
            '',
            false,
            'import-export-widget',
        );
        $output .= '<div class="inside">';
        $output .= '<p>' . __('Export all string translations as .json file.') . '</p>';

        // Placeholder for translations export URL
        $output .= '<p><a href="<!--TRANSLATIONS_EXPORT_URL_PLACEHOLDER-->" class="button button-secondary">' .
            __('Export Translations') . '</a></p>';

        $output .= '<div class="frl-import-translations-container">';
        $output .= '<p><button type="button" class="button button-secondary frl-import-translations-btn">' .
            __('Import Translations') . '</button></p>';
        $output .= '<p><label for="translation-file-input">' . __('Select string translations file:') . '</label><br>';
        $output .= '<input type="file" id="translation-file-input" accept="application/json"></p></div>';

        $output .= '<div id="import-translations-progress" style="display:none;">';
        $output .= '<p class="uploading">' . __('Uploading...') . '</p>';
        $output .= '<div class="progress-bar"></div></div>';
        $output .= '</div></div>';

        $output .= '</div>';

        // Placeholder for AJAX script
        $output .= '<!--AJAX_SCRIPT_PLACEHOLDER-->';

        return $output;
    }

    /**
     * Process the cached content to insert dynamic elements
     *
     * This method takes the cached structure and inserts the dynamic parts
     * before returning the final HTML to the user.
     *
     * @return string Processed HTML with dynamic content
     */
    public static function process_cached_content($content)
    {
        // Generate fresh security nonces and URLs
        $export_action = 'frl_post_export_settings';
        $export_url = admin_url('admin-post.php') . '?' . http_build_query([
            'action' => $export_action,
            'nonce' => frl_create_nonce('export_settings_nonce')
        ]);

        $export_translations_url = admin_url('admin-post.php') . '?' . http_build_query([
            'action' => 'frl_post_export_translations',
            'nonce' => frl_create_nonce('export_translations_nonce')
        ]);

        // Replace placeholders with dynamic content
        $content = str_replace('<!--EXPORT_URL_PLACEHOLDER-->', esc_url($export_url), $content);
        $content = str_replace('<!--TRANSLATIONS_EXPORT_URL_PLACEHOLDER-->', esc_url($export_translations_url), $content);
        $content = str_replace('<!--AJAX_SCRIPT_PLACEHOLDER-->', self::get_ajax_script(), $content);

        return $content;
    }

    /**
     * Get the JavaScript for AJAX functionality
     *
     * @return string JavaScript code
     */
    public static function get_ajax_script()
    {
        return '<script>
            jQuery(document).ready(function($) {
                // Make sure ajaxurl is defined
                if (typeof ajaxurl === "undefined") {
                    var ajaxurl = "' . admin_url('admin-ajax.php') . '";
                }

                // Import settings
                $(".frl-import-settings-btn").click(function(e) {
                    e.preventDefault();

                    var fileInput = $("#import-file-input")[0];
                    if (fileInput.files.length === 0) {
                        alert("' . esc_js(__("Please select a file to import.")) . '");
                        return;
                    }

                    var formData = new FormData();
                    formData.append("action", "frl_post_ajax_import_settings");
                    formData.append("import_file", fileInput.files[0]);
                    formData.append("security", "' . frl_create_nonce('ajax_import_nonce') . '");

                    // Show progress
                    $("#import-settings-progress").show();

                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: formData,
                        contentType: false,
                        processData: false,
                        success: function(response) {
                            if (response.success) {
                                // Remove redundant alert - admin notice will show after reload
                                window.location.reload();
                            } else {
                                alert("' . esc_js(__("Error during import: ")) . '" + (response.data && response.data.message ? response.data.message : "' . esc_js(__("Unknown error")) . '"));
                            }
                            $("#import-settings-progress").hide();
                        },
                        error: function(xhr, textStatus, errorThrown) {
                            alert("' . esc_js(__("Error during import. Please try again.")) . ' (" + xhr.status + ": " + errorThrown + ")");
                            $("#import-settings-progress").hide();
                        }
                    });
                });

                // Import translations
                $(".frl-import-translations-btn").click(function(e) {
                    e.preventDefault();

                    var fileInput = $("#translation-file-input")[0];
                    if (fileInput.files.length === 0) {
                        alert("' . esc_js(__("Please select a file to import.")) . '");
                        return;
                    }

                    var formData = new FormData();
                    formData.append("action", "frl_post_ajax_import_translations");
                    formData.append("translation_file", fileInput.files[0]);
                    formData.append("security", "' . frl_create_nonce('ajax_translation_nonce') . '");

                    // Show progress
                    $("#import-translations-progress").show();

                    $.ajax({
                        url: ajaxurl,
                        type: "POST",
                        data: formData,
                        contentType: false,
                        processData: false,
                        success: function(response) {
                            if (response.success) {
                                // Remove redundant alert - admin notice will show after reload
                                window.location.reload();
                            } else {
                                alert("' . esc_js(__("Error during translation import: ")) . '" + (response.data && response.data.message ? response.data.message : "' . esc_js(__("Unknown error")) . '"));
                            }
                            $("#import-translations-progress").hide();
                        },
                        error: function(xhr, textStatus, errorThrown) {
                            alert("' . esc_js(__("Error during translation import. Please try again.")) . ' (" + xhr.status + ": " + errorThrown + ")");
                            $("#import-translations-progress").hide();
                        }
                    });
                });
            });
        </script>';
    }
}
