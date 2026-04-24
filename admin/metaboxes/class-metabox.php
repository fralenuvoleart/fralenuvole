<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Fralenuvole
 * metaboxes.php - Add custom metaboxes to post edit screen
 */

if (!frl_get_option('editor_metabox')) {
	return;
}

/**
 * Class Frl_Metabox
 *
 * Handles the registration and rendering of custom guidelines metaboxes
 * on the post edit screens.
 */
class Frl_Metabox
{
	private $screens = array('post', 'page', 'service');

	/**
	 * Constructor.
	 *
	 * Registers the metabox addition hook.
	 *
	 * @return void
	 */
	public function __construct()
	{
		add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
	}

	/**
	 * Register custom metaboxes for supported post types.
	 *
	 * @return void
	 */
	public function add_meta_boxes()
	{
		foreach ($this->screens as $s) {
			add_meta_box(
				FRL_PREFIX . '-metabox',
				__('Guidelines', FRL_PREFIX),
				array($this, 'meta_box_callback'),
				$s,
				'side',
				'high'
			);
		}
	}

	/**
	 * Callback function to render the metabox content.
	 *
	 * @param WP_Post $post The current post object.
	 * @return void
	 */
	public function meta_box_callback($post)
	{
		if (!isset($post) || !is_object($post)) {
			return;
		}

		$file_path = FRL_DIR_PATH . 'admin/metaboxes/metabox-editor.php';
		if (file_exists($file_path)) {
			include $file_path;
		} else {
			echo '<p>' . esc_html__('Guidelines not available', FRL_PREFIX) . '</p>';
		}

		wp_nonce_field('metabox_nonce', 'metabox_nonce_field');
	}
}

if (frl_class_exists('Frl_Metabox')) {
	new Frl_Metabox;
}
