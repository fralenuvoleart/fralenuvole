<?php

/**
 * Squeeze Upload Resize: Client-side image resize before browser compression.
 *
 * Wraps Squeeze's handleCompressBeforeUpload function to resize images
 * exceeding the maximum dimension (2560px) before Squeeze compresses them.
 * This ensures resize happens before compression, preserving optimization quality.
 *
 * Loaded conditionally from thirdparty.php — remove the require_once line
 * and the entire feature disappears with zero residual hooks.
 *
 * @package Fralenuvole
 * @since   5.8.0
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles client-side image resize before Squeeze compression.
 *
 * Single class with no constructor arguments, no state, no options.
 * All configuration is constant-based. Zero database writes.
 */
final class Frl_Thirdparty_Squeeze_Resize {

	/**
	 * Nonce action for the admin alert AJAX endpoint.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'frl_squeeze_resize_alert';

	/**
	 * Register hooks. Call once from thirdparty.php after require_once.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Disabled via constant — bail without registering any hooks.
		if (defined('FRL_THIRDPARTY_SQUEEZE_MAX_DIM') && FRL_THIRDPARTY_SQUEEZE_MAX_DIM === 0) {
			return;
		}

		// Admin: media uploader + bulk page
		add_action('admin_enqueue_scripts', [self::class, 'enqueue_resize_script'], 10, 0);

		// AJAX handler for missing-function alert
		add_action('wp_ajax_frl_squeeze_resize_alert', [self::class, 'handle_alert'], 10, 0);
	}

	/**
	 * Enqueue inline resize script as a dependency of squeeze-script.
	 *
	 * Uses wp_add_inline_script so the resize wrapper loads immediately
	 * after Squeeze's own JavaScript, wrapping handleCompressBeforeUpload
	 * before any upload events fire.
	 *
	 * @return void
	 */
	public static function enqueue_resize_script(): void {
		// Defensive: if squeeze-script isn't enqueued, nothing to wrap.
		if (!wp_script_is('squeeze-script', 'enqueued') && !wp_script_is('squeeze-script', 'registered')) {
			return;
		}

		wp_add_inline_script('squeeze-script', self::get_resize_js(), 'after');
	}

	/**
	 * Generate the inline JavaScript for the resize wrapper.
	 *
	 * Public for testability; called only by enqueue_resize_script().
	 *
	 * @return string
	 */
	public static function get_resize_js(): string {
		$max_dim   = defined('FRL_THIRDPARTY_SQUEEZE_MAX_DIM') ? FRL_THIRDPARTY_SQUEEZE_MAX_DIM : 2560;
		$nonce     = wp_create_nonce(frl_prefix(self::NONCE_ACTION));
		$ajax_url  = admin_url('admin-ajax.php');

		// Minified for performance; readable form available in the source comment.
		return <<<JS
(function(){
	var d={$max_dim};
	if(typeof squeezeHandlers==='undefined'||typeof squeezeHandlers.handleCompressBeforeUpload!=='function'){
		console.warn('Fralenuvole: Squeeze resize wrapper — function not found, resize disabled.');
		fetch('{$ajax_url}?action=frl_squeeze_resize_alert&_ajax_nonce={$nonce}');
		return;
	}
	var orig=squeezeHandlers.handleCompressBeforeUpload;
	squeezeHandlers.handleCompressBeforeUpload=async function(up,file,opts){
		var n=file.getNative();
		if(!n||!n.type||!n.type.startsWith('image/'))return orig.call(this,up,file,opts);
		var bmp;try{bmp=await createImageBitmap(n);}catch(e){return orig.call(this,up,file,opts);}
		if(bmp.width<=d&&bmp.height<=d){bmp.close();return orig.call(this,up,file,opts);}
		var s=Math.min(d/bmp.width,d/bmp.height);
		var c=document.createElement('canvas');
		c.width=Math.round(bmp.width*s);c.height=Math.round(bmp.height*s);
		c.getContext('2d').drawImage(bmp,0,0,c.width,c.height);
		bmp.close();
		var blob=await new Promise(function(r){c.toBlob(r,n.type,0.92);});
		file.setNative(blob);file.size=blob.size;
		return orig.call(this,up,file,opts);
	};
})();
JS;
	}

	/**
	 * AJAX handler: admin notice when the Squeeze function is not found.
	 *
	 * Called by the resize JS when handleCompressBeforeUpload is missing,
	 * typically after a Squeeze update that changes internal exports.
	 *
	 * @return void
	 */
	public static function handle_alert(): void {
		check_ajax_referer(frl_prefix(self::NONCE_ACTION));

		$max_dim = defined('FRL_THIRDPARTY_SQUEEZE_MAX_DIM') ? FRL_THIRDPARTY_SQUEEZE_MAX_DIM : 2560;

		frl_add_admin_notice(
			sprintf(
				/* translators: %d: maximum image dimension in pixels */
				__('Squeeze resize wrapper: internal function not found by Fralenuvole. Uploaded images will not be resized to max %d px.', FRL_PREFIX),
				$max_dim
			),
			'warning',
			0 // No timeout — persists until admin dismisses or function returns.
		);

		wp_send_json_success();
	}
}
