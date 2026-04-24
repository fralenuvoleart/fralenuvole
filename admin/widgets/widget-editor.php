<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render the 'Editor Panel' dashboard widget content.
 *
 * Provides a set of quick links for content editors.
 *
 * @return string The generated HTML content for the widget.
 */
function frl_render_editor_widget()
{
    // Return only the inner content (list)
    $output = '<h4>' . esc_html__('Quick Links', FRL_PREFIX) . '</h4>';
    $output .= '<ul>';
    $output .= '<li><a href="' . esc_url(admin_url('post-new.php')) . '">' . esc_html__('Add New Post', FRL_PREFIX) . '</a></li>';
    $output .= '<li><a href="' . esc_url(admin_url('edit.php')) . '">' . esc_html__('View All Posts', FRL_PREFIX) . '</a></li>';
    $output .= '<li><a href="' . esc_url(admin_url('edit-comments.php')) . '">' . esc_html__('Manage Comments', FRL_PREFIX) . '</a></li>';
    // Add more relevant links or information for editors
    $output .= '</ul>';

    return $output;
}
