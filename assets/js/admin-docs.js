/**
 * Fralenuvole Docs Viewer — JavaScript
 *
 * Handles the AJAX-based documentation browsing inside the Documentation
 * tab on the plugin settings page. Watches index clicks and injects
 * rendered HTML into the content panel, then fires the frl_content_loaded
 * event so Prism re-highlights code blocks.
 *
 * Depends on: jQuery, frlDocsData (localized in ui-asset-loader.php).
 *
 * @package Fralenuvole
 * @since  1.0.0
 */

(function ($) {
	'use strict';

	var $viewer   = $('#frl-docs-viewer');
	var $content  = $('#frl-docs-content');
	var $links    = $viewer.find('[data-frl-doc]');
	var loading   = false;

	/**
	 * Fetch and display a documentation file.
	 *
	 * @param {string} slug Document slug.
	 * @param {jQuery} $el  The clicked element (for active-class toggling).
	 */
	function loadDoc(slug, $el) {
		if (loading) {
			return;
		}

		loading     = true;
		var $target = $el || $links.filter('[data-frl-doc="' + slug + '"]').first();

		// Mark active.
		$links.removeClass('frl-docs-active');
		$target.addClass('frl-docs-active');

		// Show loading state.
		$content.html('<p class="frl-docs-loading">' + window.frlDocsData.strings.loading + '</p>');

		// Update URL hash (non-intrusive, no page reload).
		if (window.history && window.history.replaceState) {
			var url = window.location.pathname + window.location.search;
			if (slug) {
				url += '#frl-doc-' + slug;
			}
			window.history.replaceState(null, '', url);
		}

		$.ajax({
			url: window.frlDocsData.ajaxUrl,
			method: 'GET',
			data: {
				action: 'frl_post_ajax_docs_content',
				doc: slug,
				nonce: window.frlDocsData.nonce
			},
			success: function (response) {
				loading = false;
				if (response.success && response.data && response.data.html) {
					$content.html(response.data.html);
					// Re-highlight code blocks via the existing Prism event.
					$(document).trigger('frl_content_loaded', [$content[0]]);
				} else {
					var msg = (response.data && response.data.message) || window.frlDocsData.strings.error;
					$content.html('<p class="frl-docs-error">' + $('<div>').text(msg).html() + '</p>');
				}
			},
			error: function () {
				loading = false;
				$content.html('<p class="frl-docs-error">' + window.frlDocsData.strings.error + '</p>');
			}
		});
	}

	// Click handler on doc index links.
	$viewer.on('click', '[data-frl-doc]', function (e) {
		e.preventDefault();
		var slug = $(this).data('frl-doc');
		if (slug) {
			loadDoc(slug, $(this));
		}
	});

	// Also handle links inside rendered docs that were rewritten with data-frl-doc.
	$viewer.on('click', '.frl-docs-content a[data-frl-doc]', function (e) {
		e.preventDefault();
		var slug = $(this).data('frl-doc');
		if (slug) {
			loadDoc(slug, $links.filter('[data-frl-doc="' + slug + '"]'));
		}
	});

	// Restore doc from URL hash on load.
	var hash = window.location.hash;
	if (hash && hash.indexOf('#frl-doc-') === 0) {
		var initialSlug = hash.replace('#frl-doc-', '');
		loadDoc(initialSlug, $links.filter('[data-frl-doc="' + initialSlug + '"]'));
	}

})(jQuery);