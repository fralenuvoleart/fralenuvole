/**
 * ACF Migration — Admin JavaScript
 *
 * Handles admin UI interactions for the migration page.
 * Production migrations should use WP-CLI (wp acpt-migrate ...).
 *
 * @package Fralenuvole
 * @since  5.9.0
 */

(function ($, FRL_ACF_MIGRATION) {
    'use strict';

    // ─── Progress bar ────────────────────────────────────────

    function showProgress(text) {
        $('.frl-migration-progress').addClass('is-active');
        $('.frl-progress-text').text(text || '');
    }

    function updateProgress(percent, text) {
        $('.frl-progress-bar-fill').css('width', percent + '%');
        if (text) {
            $('.frl-progress-text').text(text);
        }
    }

    function hideProgress() {
        $('.frl-migration-progress').removeClass('is-active');
    }

    // ─── AJAX helper ──────────────────────────────────────────

    function ajaxPost(action, data) {
        return $.ajax({
            url: FRL_ACF_MIGRATION.ajaxUrl,
            method: 'POST',
            data: $.extend({}, data, {
                action: 'frl_acf_migration_' + action,
                _ajax_nonce: FRL_ACF_MIGRATION.nonce
            })
        });
    }

    // ─── Button handlers ──────────────────────────────────────

    $(document).ready(function () {
        var $wrap = $('.frl-acf-migration-wrap');

        // Shim toggle handled by form submission — no JS needed.

        // Confirm dangerous actions
        $wrap.on('click', '[data-confirm]', function (e) {
            var msg = $(this).data('confirm');
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });
    });

})(jQuery, window.FRL_ACF_MIGRATION || {});
