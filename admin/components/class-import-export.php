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
     * Render the import/export widget.
     *
     * @param string $title       Widget title.
     * @param string $description Widget description.
     * @return string HTML content of the widget.
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
        $content .= self::render_content();

        // Pass the content to the UI renderer
        return frl_ui_render_widget(
            'import-export',
            $content,
            $title,
            'after-section has-section-title',
            WEEK_IN_SECONDS
        );
    }

    /**
     * Render the import/export content.
     *
     * @return string HTML content of the import/export sections.
     */
    public static function render_content()
    {

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

        // Export URL is set via wp_localize_script in ui-asset-loader.php
        $output .= '<p><a href="#" id="frl-export-settings-link" class="button button-secondary">' .
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

        // Translations export URL is set via wp_localize_script in ui-asset-loader.php
        $output .= '<p><a href="#" id="frl-export-translations-link" class="button button-secondary">' .
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

        return $output;
    }

}
