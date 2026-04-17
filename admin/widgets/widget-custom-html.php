<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders a Custom HTML dashboard widget.
 *
 * @param int $widget_number The widget number (1, 2, or 3).
 * @return string The HTML content for the widget.
 */
function frl_render_custom_html_widget($widget_number)
{
    $content_key = "dash_widget_custom_html_content_{$widget_number}";
    $content = frl_get_option($content_key);

    if (empty(trim($content))) {
        return '<p>' . esc_html__('No content configured.', FRL_PREFIX) . '</p>';
    }

    // Allow shortcodes to be processed
    $html = do_shortcode($content);

    return $html;
}

/**
 * Renders Custom HTML dashboard widget 1.
 * @return string The HTML content for the widget.
 */
function frl_render_custom_html_widget_1()
{
    return frl_render_custom_html_widget(1);
}

/**
 * Renders Custom HTML dashboard widget 2.
 * @return string The HTML content for the widget.
 */
function frl_render_custom_html_widget_2()
{
    return frl_render_custom_html_widget(2);
}

/**
 * Renders Custom HTML dashboard widget 3.
 * @return string The HTML content for the widget.
 */
function frl_render_custom_html_widget_3()
{
    return frl_render_custom_html_widget(3);
}
