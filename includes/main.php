<?php
/**
 * Main plugin functionality
 *
 * Contains core initialization and feature setup functions.
 *
 * @package FRL
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fralenuvole
 * main.php - Code executed in both frontend and backend pages
 */

// Register core hooks with Hook Manager
frl_hook_add('action', 'init', 'frl_main_init', 10, 0);
// Register FRL Icon Gutenberg block (server-rendered)
frl_hook_add('action', 'init', 'frl_register_icon_block', 10, 0);
//Critical CSS
frl_hook_add('action', 'wp_head', 'frl_add_critical_css', -999, 1, 'public');
// Register polylang integration hooks
frl_hook_add('filter', 'pll_get_post_types', 'frl_making_wp_navigation_translatable', 10, 2, 'core', false);
frl_hook_add('filter', 'block_type_metadata_settings', 'frl_render_block_core_navigation_translation', 10, 2, 'core', false);

// Register authentication and comments hooks
frl_hook_add('filter', 'auth_cookie_expiration', 'frl_extend_admin_cookie', 10, 1, 'logged', false);

// Process batched cache writes from Cache Manager::$deferred_writes at the end of request to optimize performance
frl_hook_add('action', 'shutdown', 'frl_process_deferred_writes', 10, 0, 'core', false);

// Register logger capture hooks
frl_hook_add('filter', 'render_block_data', 'frl_log_capture_render_block_enter', 10, 1);
frl_hook_add('filter', 'render_block', 'frl_log_capture_render_block_exit', 10, 2);
frl_hook_add('action', 'pre_get_posts', 'frl_log_capture_query', 1, 1);
frl_hook_add('filter', 'do_shortcode_tag', 'frl_log_capture_shortcode', 10, 4);

/**
 * Initialize main plugin functionality
 *
 * @hook init
 * @return void
 */
function frl_main_init()
{
    add_post_type_support('page', 'excerpt');

    frl_enable_custom_avatar();
    frl_add_image_sizes();
    frl_disable_wp_core_features();
}

/**
 * Register native Gutenberg block for FRL Icon
 * Block: frl/icon — server-rendered; editor script queries frl/v1/icons
 */
function frl_register_icon_block()
{
    // Ensure block APIs exist to avoid fatals on older installs
    if (!function_exists('register_block_type')) {
        return;
    }
    // Run only in admin/editor to avoid any frontend impact
    if (!is_admin()) {
        return;
    }
    // Register editor script (no build step required)
    $handle = FRL_PREFIX . '-block-icon';
    $dir_url = defined('FRL_DIR_URL') ? FRL_DIR_URL : trailingslashit(plugins_url('', dirname(__DIR__)));
    $dir_path = defined('FRL_DIR_PATH') ? FRL_DIR_PATH : trailingslashit(dirname(__DIR__) . '/');
    $src = $dir_url . 'assets/js/block-icon.js';
    $path = $dir_path . 'assets/js/block-icon.js';
    $deps = ['wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-block-editor', 'wp-api-fetch', 'wp-url'];
    $ver = file_exists($path) ? filemtime($path) : null;
    wp_register_script($handle, $src, $deps, $ver, true);

    // Localize minimal config used by the editor script
    $icons_relative = defined('FRL_ICONS_RELATIVE_PATH') ? FRL_ICONS_RELATIVE_PATH : 'assets/icons/';
    $roots = (defined('FRL_ICONS_FLAGS_ROOT') && is_array(FRL_ICONS_FLAGS_ROOT)) ? array_values(array_filter(FRL_ICONS_FLAGS_ROOT, 'strlen')) : [];
    $counter = defined('FRL_ICONS_COUNTER_TOKEN') ? FRL_ICONS_COUNTER_TOKEN : '';
    wp_localize_script($handle, 'FRL_ICONS_CFG', [
        'restIcons' => esc_url_raw(rest_url('frl/v1/icons')),
        'iconsBaseUrl' => $dir_url . $icons_relative,
        'roots' => $roots,
        'counter' => $counter,
    ]);

    // Register block type with server render
    register_block_type('frl/icon', [
        'editor_script' => $handle,
        'render_callback' => 'frl_render_icon_block',
        'attributes' => [
            'icon' => [ 'type' => 'string', 'default' => '' ],
            'mode' => [ 'type' => 'string', 'default' => 'default' ],
            'title' => [ 'type' => 'string', 'default' => '' ],
        ],
        'supports' => [ 'html' => false ],
    ]);
}

/**
 * Server render for FRL Icon block
 */
function frl_render_icon_block($attributes, $content, $block = null)
{
    $rel = isset($attributes['icon']) && is_string($attributes['icon']) ? trim($attributes['icon']) : '';
    if ($rel === '' || !class_exists('FRL_Icon_Renderer') || !FRL_Icon_Renderer::is_svg_rel($rel)) {
        return '';
    }

    $mode = isset($attributes['mode']) ? strtolower((string)$attributes['mode']) : 'default';
    $title = isset($attributes['title']) && is_string($attributes['title']) ? trim($attributes['title']) : '';
    $class = '';

    if ($mode === 'url') {
        return esc_url(FRL_Icon_Renderer::url($rel));
    }

    if ($mode === 'inline') {
        return FRL_Icon_Renderer::render('svg', $rel, $class, $title);
    }

    // Default: follow global shortcode default (span unless overridden in config)
    $default = defined('FRL_ICONS_RENDER_SHORTCODE') ? FRL_ICONS_RENDER_SHORTCODE : 'span';
    if ($default === 'inline' || $default === 'svg') {
        return FRL_Icon_Renderer::render('svg', $rel, $class, $title);
    }
    return FRL_Icon_Renderer::render('span', $rel, $class, $title);
}

/**
 * Add critical CSS functionality
 *
 * @hook wp_head
 * @return void
 */
function frl_add_critical_css()
{
    if (!frl_get_option('critical_css')) {
        return;
    }

    $css = frl_get_critical_css_data();

    if (frl_is_array_not_empty($css)) {
        printf(
            '<style id="%s-critical-css" data-lastmod="%s" data-no-defer="1" data-plugin="%s" data-parsing="critical-css">%s</style>',
            FRL_PREFIX,
            date('Y-m-d-H:i', $css['mtime']),
            FRL_NAME,
            $css['css']
        );
    }
}

/**
 * Get critical CSS data with caching
 *
 * @return array CSS data with mtime or empty array
 */
function frl_get_critical_css_data()
{
    $css_path = get_stylesheet_directory() . '/critical.css';
    $css_file = ['critical-css' => $css_path];
    // Get version with $absolute_path = true
    $css_version = frl_get_assets_versions($css_file, 'critical_css', 'versions', false);
    if (empty($css_version)) {
        return '';
    }

    $mtime = $css_version['critical-css'];

    if (!$mtime) {
        return '';
    }

    $critical_css = frl_cache_remember('html', "critical_css_{$mtime}",
        function () use ($css_path, $mtime) {
            // Single file read operation - more efficient than checking existence first
            $css_content = file_get_contents($css_path);
            if ($css_content === false || empty($css_content)) {
                return '';
            }

            $minified = frl_minify_css($css_content);

            // Cache the minified content with mtime
            $data = [
                'css' => $minified,
                'mtime' => $mtime
            ];

            return $data;
        }
    );

    return $critical_css;
}

/**
 * Disable selected WordPress core features
 *
 * @return void
 */
function frl_disable_wp_core_features()
{
    if (frl_get_option('disable_oembed')) {
        frl_disable_oembed();
    }

    if (frl_get_option('disable_emojis')) {
        frl_remove_emojis();
    }

    if (frl_get_option('disable_comments')) {
        frl_disable_comments();
    }

    if (!frl_is_logged_in() && !is_login()) {
        wp_dequeue_style('dashicons');
        wp_deregister_style('dashicons');
    }
}

/**
 * Disable oEmbed functionality
 *
 * @return void
 */
function frl_disable_oembed()
{
    // Use standard WordPress removal functions for built-in hooks
    remove_action('rest_api_init', 'wp_oembed_register_route');
    remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');

    frl_hook_add('filter', 'embed_oembed_discover', '__return_false', 10, 0, 'core', false);
}

/**
 * Disable emojis functionality
 *
 * @return void
 */
function frl_remove_emojis()
{
    if (!frl_get_option('disable_emojis')) {
        return;
    }

    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');

    // Avoid registering callbacks that live in public components when the plugin front-end is disabled.
    if (!frl_get_option('disable_plugin')) {
        frl_hook_add('filter', 'tiny_mce_plugins', 'frl_disable_emojis_tinymce', 10, 1, 'public', false);
        frl_hook_add('filter', 'wp_resource_hints', 'frl_disable_emojis_remove_dns_prefetch', 10, 2, 'public', false);
    }
}

/**
 * Completely disable WordPress comments
 *
 * @return void
 */
function frl_disable_comments()
{
    // Remove comment support from all post types
    foreach (get_post_types() as $post_type) {
        if (post_type_supports($post_type, 'comments')) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }

    // Perform the one-time DB update using frl_cache_remember with the 'options' group
    frl_cache_remember(
        'options',
        'disable_comments',
        function () {
            global $wpdb;
            $wpdb->update(
                $wpdb->posts,
                ['comment_status' => 'closed', 'ping_status' => 'closed'],
                ['post_status' => 'publish'] // WHERE condition - original was ['post_type' => 'post']
            );
            // Always return '1' to set the flag, preventing retries.
            return '1';
        },
        YEAR_IN_SECONDS,
    );

    // Hide existing comments menu and admin bar items
    frl_hook_add('action', 'admin_menu', function () {
        remove_menu_page('edit-comments.php');
    }, 10, 0, 'admin');

    frl_hook_add('action', 'admin_bar_menu', function ($wp_admin_bar) {
        $wp_admin_bar->remove_node('comments');
    }, 999, 1, 'admin');

    // Disable comments REST API endpoints
    frl_hook_add('filter', 'rest_endpoints', function ($endpoints) {
        unset($endpoints['/wp/v2/comments']);
        unset($endpoints['/wp/v2/comments/(?P<id>[\d]+)']);
        return $endpoints;
    });

    // Disable comment feed
    frl_hook_add('action', 'template_redirect', function () {
        if (is_comment_feed()) {
            $redirect_url = home_url();
            if (!empty($_GET)) {
                $redirect_url = add_query_arg($_GET, $redirect_url);
            }
            wp_redirect($redirect_url);
            exit;
        }
    }, 999, 0);

    // Remove comment-related widgets
    frl_hook_add('action', 'widgets_init', function () {
        unregister_widget('WP_Widget_Recent_Comments');
    }, 10, 0, 'admin');

    // Disable comment form and display
    frl_hook_add('filter', 'comments_open', '__return_false', 20, 2, 'core', false);
    frl_hook_add('filter', 'pings_open', '__return_false', 20, 2, 'core', false);
    frl_hook_add('filter', 'comments_array', '__return_empty_array', 10, 2, 'core', false);

    // Remove comments from admin dashboard
    frl_hook_add('action', 'admin_init', function () {
        remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
    }, 10, 0, 'admin');
}

/**
 * Add custom image sizes
 * @return void
 */
function frl_add_image_sizes()
{
    if (!frl_get_option('image_sizes')) {
        return;
    }

    static $added = false;
    if ($added) return;

    // Using remember for atomic operation
    $image_sizes = frl_cache_remember('options', 'image_sizes', function () {
        $raw = frl_get_option('image_sizes_list');
        $parsed_sizes = frl_textlist_to_array($raw);
        return array_filter(
            $parsed_sizes,
            function ($size) {
                // $size is always an array, check if it has the expected pipe-separated structure
                return is_array($size) && count($size) >= 3 && is_numeric($size[1]) && is_numeric($size[2]);
            }
        );
    }, WEEK_IN_SECONDS);

    foreach ($image_sizes as $size) {
        add_image_size($size[0], (int)$size[1], (int)$size[2], $size[3] ?? false);
    }

    $added = true;
}

function frl_add_image_size_names_choice($sizes)
{
    if (!frl_get_option('image_sizes')) {
        return $sizes;
    }

    // Use remember for atomic operation and prevent race conditions
    $custom_sizes = frl_cache_remember('options', 'image_size_names', function () {
        $image_sizes_list = frl_get_option('image_sizes_list');
        $image_sizes = frl_textlist_to_array($image_sizes_list);

        if (!$image_sizes) {
            return [];
        }

        // Check if we have valid array structure - each item should be an array with at least 4 elements
        if (frl_is_array_not_empty($image_sizes)) {
            $valid_sizes = array_filter($image_sizes, function($image_size) {
                return is_array($image_size) && count($image_size) >= 4;
            });

            return array_map(function ($image_size) {
                return [$image_size[0] => __($image_size[3])];
            }, $valid_sizes);
        }

        return [];
    }, WEEK_IN_SECONDS);  // Cache for a week since this rarely changes

    return array_merge($sizes, $custom_sizes);
}

/**
 * Set Admin Cookie Expiration to 1 Year
 * @param int $expirein Expiration time in seconds
 * @return int One year expiration time in seconds
 */
function frl_extend_admin_cookie(int $expirein): int
{
    if (!frl_get_option('extend_admin_cookie')) {
        return $expirein;
    }

    return YEAR_IN_SECONDS;
}

/**
 * Initialize custom avatar functionality
 */
function frl_enable_custom_avatar()
{
    if (!frl_get_option('custom_avatar')) {
        return;
    }

    // Add fields to user profile
    frl_hook_add('action', 'show_user_profile', 'frl_add_avatar_upload_field', 10, 1, 'admin');
    frl_hook_add('action', 'edit_user_profile', 'frl_add_avatar_upload_field', 10, 1, 'admin');

    // Save custom fields
    frl_hook_add('action', 'personal_options_update', 'frl_save_custom_avatar', 10, 1, 'admin');
    frl_hook_add('action', 'edit_user_profile_update', 'frl_save_custom_avatar', 10, 1, 'admin');

    // Enqueue necessary scripts
    frl_hook_add('action', 'admin_enqueue_scripts', 'frl_enqueue_avatar_scripts', 10, 1, 'admin');

    // Filter avatar - use the existing function from your code
    frl_hook_add('filter', 'get_avatar_data', 'frl_get_avatar_data', 100, 2);
}

/**
 * Get avatar data
 * @param array $args Arguments
 * @param mixed $id_or_email User ID or email
 * @return array Modified arguments
 */
function frl_get_avatar_data($args, $id_or_email)
{
    // Skip processing if not a user ID
    if (!is_numeric($id_or_email)) {
        return $args;
    }

    $user_id = (int)$id_or_email;
    $cache_key =  'avatar_uid' . $user_id;

    // Cache avatar data
    $sizes = frl_cache_remember('options', $cache_key, function () use ($user_id) {
        $attachment_id = frl_get_user_meta($user_id, 'avatar');
        if (!$attachment_id) {
            return [];
        }
        $image_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        if (!$image_url) {
            return [];
        }
        return [
            'url' => $image_url,
            'found' => true
        ];
    }, DAY_IN_SECONDS);

    if (!empty($sizes)) {
        $args['url'] = $sizes['url'];
        $args['found_avatar'] = $sizes['found'];
    }

    return $args;
}

/**
 * Add custom avatar upload field to user profiles
 */
function frl_add_avatar_upload_field($user)
{
    $avatar_id = frl_get_user_meta($user->ID, 'avatar');
    $avatar_url = $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';
?>
    <h3><?php _e('Custom Avatar', FRL_PREFIX); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="custom_avatar"><?php _e('Upload Avatar', FRL_PREFIX); ?></label></th>
            <td>
                <div class="custom-avatar-container">
                    <div class="custom-avatar-preview" style="margin-bottom: 10px;">
                        <?php if ($avatar_url): ?>
                            <img src="<?php echo esc_url($avatar_url); ?>" style="max-width: 100px; height: auto; border-radius: 50%;">
                        <?php endif; ?>
                    </div>
                    <input type="hidden" name="<?php echo FRL_PREFIX; ?>_avatar_id" id="<?php echo FRL_PREFIX; ?>_avatar_id" value="<?php echo esc_attr($avatar_id); ?>">
                    <button type="button" class="button" id="upload_avatar_button"><?php _e('Upload Image', FRL_PREFIX); ?></button>
                    <?php if ($avatar_id): ?>
                        <button type="button" class="button" id="remove_avatar_button"><?php _e('Remove', FRL_PREFIX); ?></button>
                    <?php endif; ?>
                    <p class="description"><?php _e('Upload a custom avatar image. Recommended size: 300x300px.', FRL_PREFIX); ?></p>
                </div>

                <script>
                    jQuery(document).ready(function($) {
                        var frame;

                        // Upload button click
                        $('#upload_avatar_button').on('click', function(e) {
                            e.preventDefault();

                            // If the media frame already exists, reopen it
                            if (frame) {
                                frame.open();
                                return;
                            }

                            // Create a new media frame
                            frame = wp.media({
                                title: '<?php _e('Select or Upload Avatar', FRL_PREFIX); ?>',
                                button: {
                                    text: '<?php _e('Use this image', FRL_PREFIX); ?>'
                                },
                                library: {
                                    type: 'image'
                                },
                                multiple: false
                            });

                            // When an image is selected in the media frame...
                            frame.on('select', function() {
                                var attachment = frame.state().get('selection').first().toJSON();
                                $('#<?php echo FRL_PREFIX; ?>_avatar_id').val(attachment.id);

                                var imgPreview = '<img src="' + attachment.url + '" style="max-width: 100px; height: auto; border-radius: 50%;">';
                                $('.custom-avatar-preview').html(imgPreview);

                                // Add remove button if not present
                                if ($('#remove_avatar_button').length === 0) {
                                    $('.custom-avatar-container #upload_avatar_button').after(' <button type="button" class="button" id="remove_avatar_button"><?php _e('Remove', FRL_PREFIX); ?></button>');
                                }
                            });

                            // Open the modal
                            frame.open();
                        });

                        // Remove button click
                        $(document).on('click', '#remove_avatar_button', function(e) {
                            e.preventDefault();
                            $('#<?php echo FRL_PREFIX; ?>_avatar_id').val('');
                            $('.custom-avatar-preview').empty();
                            $(this).remove();
                        });
                    });
                </script>
            </td>
        </tr>
    </table>
<?php
}

/**
 * Save custom avatar when user profile is updated
 *
 * @param int $user_id User ID
 * @return void
 */
function frl_save_custom_avatar($user_id)
{
    if (isset($_POST[FRL_PREFIX . '_avatar_id'])) {
        $avatar_id = absint($_POST[FRL_PREFIX . '_avatar_id']);
        frl_update_user_meta($user_id, 'avatar', $avatar_id);

        // Clear the avatar cache for this user
        $cache_key =  'avatar_uid' . $user_id;
        frl_cache_clear('options', $cache_key);
    }
}

/**
 * Enqueue media scripts for avatar uploader
 */
function frl_enqueue_avatar_scripts($hook)
{
    if ($hook === 'profile.php' || $hook === 'user-edit.php') {
        wp_enqueue_media();
    }
}

/**
 * Make navigation menus translatable
 * @return void
 */
function frl_making_wp_navigation_translatable($post_types, $is_settings)
{
    if (! $is_settings) {
        $post_types['wp_navigation'] = 'wp_navigation';
    }
    return $post_types;
}

function frl_render_block_core_navigation_translation($settings, $metadata)
{
    // Only proceed for navigation blocks
    if ('core/navigation' !== $metadata['name'] || !frl_is_multilingual('pll_get_post')) {
        return $settings;
    }

    // Get languages
    $current_lang = frl_get_language();
    $default_lang = frl_get_default_language();

    // --- Install custom render callback for ALL languages ---
    $settings['render_callback'] = function ($attributes, $content, $block) use ($current_lang, $default_lang) {
        // If no ref attribute, render normally
        if (!isset($attributes['ref'])) {
            return render_block_core_navigation($attributes, $content, $block);
        }

        $nav_id = absint($attributes['ref']);
        $final_nav_id = $nav_id; // Default to original ID

        // Only attempt translation for non-default languages
        if (!empty($current_lang) && $current_lang !== $default_lang) {
            $cache_key = "wp_navigation_{$nav_id}";

            $translated_id = frl_cache_remember('permalinks', $cache_key, function () use ($nav_id, $current_lang) {
                // pll_get_post returns 0 for non-existing translations
                return pll_get_post($nav_id, $current_lang);
            });

            // Only use translated ID if it's positive (not 0) and differs from original
            if ($translated_id > 0 && $translated_id !== $nav_id) {
                $final_nav_id = absint($translated_id);
            }
        }

        // Update the ref attribute with the appropriate ID
        $attributes['ref'] = $final_nav_id;

        // Always call the original renderer to ensure assets are loaded
        return render_block_core_navigation($attributes, $content, $block);
    };

    return $settings;
}

/**
 * Process deferred cache writes on shutdown
 * Handles batching and error management for cache operations
 */
function frl_process_deferred_writes()
{
    // Ensure no pending results are lingering
    frl_flush_db();

    $writes = frl_cache_get_deferred_writes();
    if (empty($writes)) {
        return;
    }

    // Merge duplicate writes (last write wins)
    $merged = [];
    foreach ($writes as $group => $items) {
        foreach ($items as $key => $value) {
            $merged[$group][$key] = $value;
        }
    }

    // Process merged writes with error handling
    foreach ($merged as $group => $items) {
        try {
            // Process each group in a separate try-catch
            foreach ($items as $key => $value) {
                frl_cache_set($group, $key, $value);
            }
        } catch (Exception $e) {
            frl_log("Error processing deferred writes for group {group}: {error}", ['group' => $group, 'error' => $e->getMessage()]);
        }
    }

    // Clear deferred writes using helper
    frl_cache_clear_deferred_writes();

    // Final flush to ensure no lingering results
    frl_flush_db();
}
