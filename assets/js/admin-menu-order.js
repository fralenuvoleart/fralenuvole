/**
 * Fralenuvole Menu Order JavaScript
 *
 * Handles copy functionality for menu order configuration.
 * Previously embedded inline in functions-admin-ui.php
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var container = document.getElementById('frl-menu-reorder-ui');
        if (!container) return;

        // Handle single item copy
        container.addEventListener('click', function (e) {
            if (e.target.classList.contains('frl-copy-single-item')) {
                var textToCopy = e.target.dataset.copyText;
                navigator.clipboard.writeText(textToCopy).then(function () {
                    var originalText = e.target.textContent;
                    e.target.textContent = 'Copied!';
                    setTimeout(function () {
                        e.target.textContent = originalText;
                    }, 1500);
                });
            }
        });

        // Handle bulk copy
        var copyAllButton = document.getElementById('frl-copy-all-menu-items');
        if (copyAllButton) {
            var allItemsText = container.dataset.allItems;
            copyAllButton.addEventListener('click', function () {
                navigator.clipboard.writeText(allItemsText).then(function () {
                    var originalText = this.textContent;
                    this.textContent = 'Copied!';
                    setTimeout(function () {
                        this.textContent = originalText;
                    }.bind(this), 2000);
                }.bind(this));
            });
        }
    });
})();
