<?php
/**
 * Media features
 * - Custom image sizes
 * - Custom avatars
 *
 * @package Fralenuvole
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register custom image sizes based on plugin options.
 *
 * @return void
 */
function frl_add_image_sizes()
{
    if (!frl_get_option('image_sizes')) {
        return;
    }

    static $added = false;
    if ($added) return;

    // Cache parsed image sizes to avoid repeated processing
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

/**
 * Append custom image size names to the media picker selection.
 *
 * @param array $sizes Existing image size names.
 * @return array Updated list of image size names.
 */
function frl_add_image_size_names_choice($sizes)
{
    if (!frl_get_option('image_sizes')) {
        return $sizes;
    }

    // Cache custom size names for the media picker
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
 * Initialize custom avatar functionality by registering profile fields and filters.
 *
 * @return void
 */
function frl_enable_custom_avatar()
{
    if (!frl_get_option('custom_avatar')) {
        return;
    }

    // Add fields to user profile
    add_action('show_user_profile', 'frl_add_avatar_upload_field', 10, 1);
    add_action('edit_user_profile', 'frl_add_avatar_upload_field', 10, 1);

    // Save custom fields
    add_action('personal_options_update', 'frl_save_custom_avatar', 10, 1);
    add_action('edit_user_profile_update', 'frl_save_custom_avatar', 10, 1);

    // Enqueue necessary scripts
    add_action('admin_enqueue_scripts', 'frl_enqueue_avatar_scripts', 10, 1);

    // Filter avatar - use the existing function from your code
    add_filter('get_avatar_data', 'frl_get_avatar_data', 100, 2);
}

/**
 * Filter avatar data to use a custom uploaded image if available.
 *
 * @param array $args Original avatar arguments.
 * @param mixed $id_or_email User ID or email address.
 * @return array Modified avatar arguments.
 */
function frl_get_avatar_data($args, $id_or_email)
{
    // Skip processing if not a user ID
    if (!is_numeric($id_or_email)) {
        return $args;
    }

    $user_id = (int)$id_or_email;
    $cache_key =  'avatar_uid' . $user_id;

    // Cache avatar URL to reduce meta and attachment lookups
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
 * Render the custom avatar upload field in the user profile page.
 *
 * @param WP_User $user The user object.
 * @return void
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
 * Save the custom avatar attachment ID to user meta.
 *
 * @param int $user_id The ID of the user being updated.
 * @return void
 */
function frl_save_custom_avatar($user_id)
{
    if (isset($_POST[FRL_PREFIX . '_avatar_id'])) {
        $avatar_id = absint($_POST[FRL_PREFIX . '_avatar_id']);
        frl_update_user_meta($user_id, 'avatar', $avatar_id);

        // Invalidate avatar cache after update
        $cache_key =  'avatar_uid' . $user_id;
        frl_cache_clear('options', $cache_key);
    }
}

/**
 * Enqueue WordPress media scripts for the avatar uploader on profile pages.
 *
 * @param string $hook The current admin page hook.
 * @return void
 */
function frl_enqueue_avatar_scripts($hook)
{
    if ($hook === 'profile.php' || $hook === 'user-edit.php') {
        wp_enqueue_media();
    }
}

