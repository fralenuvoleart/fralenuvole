<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the 'Last Updates' dashboard widget content.
 * @return string The HTML content for the widget.
 */
function frl_render_last_posts_widget()
{
    $args = array(
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => 5,
        'orderby'        => 'modified',
        'order'          => 'DESC'
    );

    $last_posts = new WP_Query($args);
    $output = '';

    if ($last_posts->have_posts()) {
        while ($last_posts->have_posts()) {
            $last_posts->the_post();
            $post_id = get_the_ID();
            $post_title = get_the_title();
            $post_type = get_post_type();
            $post_permalink = get_the_permalink();

            $max_chars = 8;
            $post_type_label = mb_strlen($post_type) > $max_chars
                ? ucfirst(mb_substr($post_type, 0, $max_chars)) . '...'
                : ucfirst($post_type);

            $post_type_link = admin_url('edit.php?post_type=' . $post_type);
            $edit_link = get_edit_post_link($post_id);
            $modified_time = get_the_modified_time('U');
            $time_ago = human_time_diff($modified_time, current_time('timestamp')) . ' ' . __('ago', FRL_PREFIX);
            $author_id = (int) get_the_author_meta('ID');
            $author_name = get_the_author_meta('display_name', $author_id);
            $author_link = '/wp-admin/user-edit.php?user_id=' . $author_id;

            $output .= sprintf(
                '<article class="post-item">
                    <div class="post-edit">
                        <a href="%1$s" class="post-edit"><span class="dashicons dashicons-edit"></span></a>
                    </div>
                    <div class="post-title">
                        %2$s
                    </div>
                    <div class="post-type">
                        <a title="%3$s" class="post-type" href="%4$s">%5$s</a>
                    </div>
                    <div class="post-meta">
                        <div class="post-date">
                            <time datetime="%6$s">%7$s</time>
                        </div>
                        <div class="post-author">
                            <a href="%8$s">%9$s</a>
                        </div>
                    </div>
                </article>',
                esc_url($edit_link),
                $edit_link
                    ? '<a href="' . esc_url($post_permalink) . '">' . esc_html($post_title) . '</a>'
                    : esc_html($post_title),
                esc_attr($post_type),
                esc_url($post_type_link),
                esc_html($post_type_label),
                esc_attr(date('c', $modified_time)),
                esc_html($time_ago),
                esc_url($author_link),
                esc_html($author_name)
            );
        }
        wp_reset_postdata();
    } else {
        $output .= '<p>' . esc_html__('No recent updates found.', FRL_PREFIX) . '</p>';
    }

    return $output;
}
