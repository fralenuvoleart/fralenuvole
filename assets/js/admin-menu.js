/**
 * Common UI functionality for FraLeNuvole plugin admin interface
 */

(function($) {
    document.querySelectorAll('button[aria-controls*="greenShift-custom-site-settings"]').forEach(function (item) {item.setAttribute('aria-label', '');});

    $(function() {
        const menu = $('#adminmenu');
        const menuWrap = $('#adminmenuwrap');
        const collapseMenuItem = $('#collapse-menu');

        if (!menu.length || !menuWrap.length || !collapseMenuItem.length) {
            return;
        }

        const menuItems = menu.children('li.menu-top:not(.wp-menu-separator, #collapse-menu)');
        const itemsToShow = (typeof frlAdminTheme !== 'undefined' && frlAdminTheme.itemsToShow) ? parseInt(frlAdminTheme.itemsToShow, 10) : 10;

        if (menuItems.length > itemsToShow) {

            const separator = $('<li class="wp-menu-separator frl-show-more-separator" style="margin: 7px 0 0 0;"><div class="separator"></div></li>');
            const toggleButton = $('<li class="menu-top frl-show-more"><a href="#" class="menu-top" style="padding-top: 0px;"><div class="wp-menu-image dashicons-before dashicons-arrow-down-alt2"></div><div class="wp-menu-name">Show More</div></a></li>');

            toggleButton.insertBefore(collapseMenuItem);
            separator.insertAfter(toggleButton);

            toggleButton.on('click', function(e) {
                e.preventDefault();
                menuWrap.toggleClass('frl-menu-expanded');

                const buttonText = $(this).find('.wp-menu-name');
                const buttonIcon = $(this).find('.wp-menu-image');
                let menuState;

                if (menuWrap.hasClass('frl-menu-expanded')) {
                    buttonText.text('Show Less');
                    buttonIcon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                    menuState = 'open';
                } else {
                    buttonText.text('Show More');
                    buttonIcon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                    menuState = 'folded';
                }

                // Trigger the WordPress event to recalculate menu positioning.
                // This is necessary for the menu scrollbar to appear correctly.
                $(document).trigger( 'wp-collapse-menu', { state: menuState } );
            });
        }
    });
})(jQuery);
