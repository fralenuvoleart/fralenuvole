/**
 * Fralenuvole Import/Export JavaScript
 *
 * Handles AJAX import functionality for settings and translations.
 * Previously embedded inline in class-import-export.php
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        // Make sure ajaxurl is defined
        if (typeof ajaxurl === 'undefined') {
            window.ajaxurl = frlImportExport.ajaxUrl;
        }

        // Handle export link clicks (URLs set via wp_localize_script)
        $('#frl-export-settings-link').on('click', function (e) {
            e.preventDefault();
            window.location.href = frlImportExport.exportUrl;
        });

        $('#frl-export-translations-link').on('click', function (e) {
            e.preventDefault();
            window.location.href = frlImportExport.translationsExportUrl;
        });

        // Import settings
        $('.frl-import-settings-btn').on('click', function (e) {
            e.preventDefault();

            var fileInput = $('#import-file-input')[0];
            if (fileInput.files.length === 0) {
                alert(frlImportExport.strings.selectFile);
                return;
            }

            var formData = new FormData();
            formData.append('action', 'frl_post_ajax_import_settings');
            formData.append('import_file', fileInput.files[0]);
            formData.append('security', frlImportExport.importNonce);

            // Show progress
            $('#import-settings-progress').show();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function (response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        var errorMsg = response.data && response.data.message
                            ? response.data.message
                            : frlImportExport.strings.unknownError;
                        alert(frlImportExport.strings.importError + ' ' + errorMsg);
                    }
                    $('#import-settings-progress').hide();
                },
                error: function (xhr, textStatus, errorThrown) {
                    alert(frlImportExport.strings.importRetry + ' (' + xhr.status + ': ' + errorThrown + ')');
                    $('#import-settings-progress').hide();
                }
            });
        });

        // Import translations
        $('.frl-import-translations-btn').on('click', function (e) {
            e.preventDefault();

            var fileInput = $('#translation-file-input')[0];
            if (fileInput.files.length === 0) {
                alert(frlImportExport.strings.selectFile);
                return;
            }

            var formData = new FormData();
            formData.append('action', 'frl_post_ajax_import_translations');
            formData.append('translation_file', fileInput.files[0]);
            formData.append('security', frlImportExport.translationNonce);

            // Show progress
            $('#import-translations-progress').show();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                success: function (response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        var errorMsg = response.data && response.data.message
                            ? response.data.message
                            : frlImportExport.strings.unknownError;
                        alert(frlImportExport.strings.translationImportError + ' ' + errorMsg);
                    }
                    $('#import-translations-progress').hide();
                },
                error: function (xhr, textStatus, errorThrown) {
                    alert(frlImportExport.strings.translationRetry + ' (' + xhr.status + ': ' + errorThrown + ')');
                    $('#import-translations-progress').hide();
                }
            });
        });
    });
})(jQuery);
