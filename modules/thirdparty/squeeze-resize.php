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

		// Squeeze bug fix: normalise $_POST['format'] from 'jpeg' to 'jpg'
		// before Squeeze's update_attachment handler (p10). Squeeze's
		// ALLOWED_IMAGE_FORMATS uses key 'jpg' but the browser sends 'jpeg'.
		add_action('wp_ajax_squeeze_update_attachment', [self::class, 'fix_format_jpeg'], 1, 0);

		// AJAX handler for missing-function alert
		add_action('wp_ajax_frl_squeeze_resize_alert', [self::class, 'handle_alert'], 10, 0);
	}

	/**
	 * Normalise Squeeze's $_POST['format'] from 'jpeg' to 'jpg'.
	 *
	 * Squeeze's ALLOWED_IMAGE_FORMATS uses key 'jpg' but browsers report
	 * MIME type 'image/jpeg', which Squeeze's JS sends as format=jpeg.
	 * This hook runs at priority 1, before Squeeze's update_attachment at 10.
	 *
	 * @return void
	 */
	public static function fix_format_jpeg(): void {
		if (isset($_POST['format'])) {
			frl_log('Squeeze fix_format_jpeg: raw format={raw}, extension={ext}, filename={name}', [
				'raw'  => $_POST['format'],
				'ext'  => isset($_POST['extension']) ? $_POST['extension'] : 'n/a',
				'name' => isset($_POST['filename']) ? $_POST['filename'] : 'n/a',
			]);

			if ($_POST['format'] === 'jpeg') {
				$_POST['format'] = 'jpg';
				frl_log('Squeeze fix_format_jpeg: normalised jpeg → jpg');
			}
		} else {
			frl_log('Squeeze fix_format_jpeg: $_POST[format] not set. POST keys: {keys}', [
				'keys' => implode(', ', array_keys($_POST)),
			]);
		}
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
	var S=window.Squeeze||{};
	if(typeof S.compressBeforeUpload!=='function'){
		console.warn('Fralenuvole: Squeeze resize wrapper — compressBeforeUpload not found, resize disabled.');
		fetch('{$ajax_url}?action=frl_squeeze_resize_alert&_ajax_nonce={$nonce}');
		return;
	}
	var orig=S.compressBeforeUpload;

	/**
		* Resize a File to max-dimension canvas blob, preserving quality 0.92.
		* Returns the original file if resize is not needed or fails.
		*/
	async function resizeIfNeeded(file){
		if(!file||!file.type||!file.type.startsWith('image/'))return file;
		var bmp;try{bmp=await createImageBitmap(file);}catch(e){return file;}
		if(bmp.width<=d&&bmp.height<=d){bmp.close();return file;}
		var s=Math.min(d/bmp.width,d/bmp.height);
		var c=document.createElement('canvas');
		c.width=Math.round(bmp.width*s);c.height=Math.round(bmp.height*s);
		c.getContext('2d').drawImage(bmp,0,0,c.width,c.height);
		bmp.close();
		return new Promise(function(r){c.toBlob(r,file.type,0.92);});
	}

	S.compressBeforeUpload=async function(file,compressSizes){
		var resized=await resizeIfNeeded(file);
		if(!(resized instanceof Blob))return orig.call(S,file,compressSizes);
		var f=new File([resized],file.name||'image.jpg',{type:file.type,lastModified:Date.now()});
		return orig.call(S,f,compressSizes);
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
