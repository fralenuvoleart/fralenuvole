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
    // Start output buffering
    ob_start();

    $args = array(
        'post_type'      => 'any', // Consider specifying relevant post types
        'post_status'    => 'publish',
        'posts_per_page' => 5, // Number of posts to show
        'orderby'        => 'modified', // Order by last modified date
        'order'          => 'DESC'
    );

    $last_posts = new WP_Query($args);

    if ($last_posts->have_posts()) {
        while ($last_posts->have_posts()) {

            $last_posts->the_post();
            $post_id = get_the_ID();
            $post_title = get_the_title();
            $post_type = get_post_type();
            $post_permalink = get_the_permalink();

            $max_chars = 8;
            if (mb_strlen($post_type) > $max_chars) {
                $post_type_label = ucfirst(mb_substr($post_type, 0, $max_chars)) . '...';
            } else {
                $post_type_label = ucfirst($post_type);
            }

            // Get the link to the admin edit screen for this post type
            $post_type_link = admin_url('edit.php?post_type=' . $post_type);
            $edit_link = get_edit_post_link($post_id);
            $modified_time = get_the_modified_time('U');
            $time_ago = human_time_diff($modified_time, current_time('timestamp')) . ' ' . __('ago', FRL_PREFIX);
            $author_id = (int) get_the_author_meta('ID');
            $author_name = get_the_author_meta('display_name', $author_id);
            $author_link = '/wp-admin/user-edit.php?user_id=' . $author_id;

?>
            <article class="post-item">
                <div class="post-edit">
                    <a href="<?php echo esc_url($edit_link); ?>" class="post-edit">
                        <span class="dashicons dashicons-edit"></span>
                    </a>
                </div>
                <div class="post-title">
                    <?php if ($edit_link): ?>
                        <a href="<?php echo esc_url($post_permalink); ?>"><?php echo esc_html($post_title); ?></a>
                    <?php else: ?>
                        <?php echo esc_html($post_title); ?>
                    <?php endif; ?>
                </div>
                <div class="post-type">
                    <a title="<?php echo esc_html($post_type); ?>" class="post-type" href="<?php echo esc_html($post_type_link); ?>">
                        <?php echo esc_html($post_type_label); ?>
                    </a>
                </div>
                <div class="post-meta">
                    <div class="post-date">
                        <time datetime="<?php echo esc_attr(date('c', $modified_time)); ?>">
                            <?php echo esc_html($time_ago); ?>
                        </time>
                    </div>
                    <div class="post-author">
                        <a href="<?php echo esc_url($author_link); ?>">
                            <?php echo esc_html($author_name); ?>
                        </a>
                    </div>
                </div>
            </article>
<?php
        }
        wp_reset_postdata(); // Restore original Post Data
    } else {
        echo '<p>' . esc_html__('No recent updates found.', FRL_PREFIX) . '</p>';
    }

    // Return the captured output
    return ob_get_clean();
}
