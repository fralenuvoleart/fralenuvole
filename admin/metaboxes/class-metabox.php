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

// Meta Box Class: Frl_Metabox
class Frl_Metabox
{
	private $screens = array('post', 'page', 'service');

	public function __construct()
	{
		frl_hook_add('action', 'add_meta_boxes', array($this, 'add_meta_boxes'));
	}

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

	public function meta_box_callback($post)
	{
		if (!isset($post) || !is_object($post)) {
			return;
		}

		$file_path = FRL_DIR_PATH . 'admin/metaboxes/metabox-editor.php';
		if ($file_path && file_exists($file_path)) {
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
