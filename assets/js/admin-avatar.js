jQuery(document).ready(function($) {
    var frame;
    var config = typeof frl_avatar_config !== 'undefined' ? frl_avatar_config : {};

    // Upload button click
    $('#upload_avatar_button').on('click', function(e) {
        e.preventDefault();

        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: config.title || 'Select or Upload Avatar',
            button: {
                text: config.button_text || 'Use this image'
            },
            library: {
                type: 'image'
            },
            multiple: false
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#' + (config.prefix || 'frl') + '_avatar_id').val(attachment.id);

            var imgPreview = '<img src="' + attachment.url + '" style="max-width: 100px; height: auto; border-radius: 50%;">';
            $('.custom-avatar-preview').html(imgPreview);

            if ($('#remove_avatar_button').length === 0) {
                $('.custom-avatar-container #upload_avatar_button').after(' <button type="button" class="button" id="remove_avatar_button">' + (config.remove_text || 'Remove') + '</button>');
            }
        });

        frame.open();
    });

    // Remove button click
    $(document).on('click', '#remove_avatar_button', function(e) {
        e.preventDefault();
        $('#' + (config.prefix || 'frl') + '_avatar_id').val('');
        $('.custom-avatar-preview').empty();
        $(this).remove();
    });
});
