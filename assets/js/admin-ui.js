/**
 * Common UI functionality for FraLeNuvole plugin admin interface
 */

// PERFORMANCE OPTIMIZATION: Early tab initialization
// This code runs immediately when parsed, before document.ready
(function($) {
    // Initialize tabs as early as possible
    function initTabsEarly() {
        if ($("#tabs").length) {
            try {

                // OPTIMIZATION: Show tabs immediately using CSS classes instead of jQuery UI
                // This provides immediate visual feedback before jQuery UI initialization
                var activeTab = 0;
                var activeTabId = "";

                // Get active tab from URL fragment or localStorage
                var fragment = window.location.hash;

                if (fragment && fragment.length > 1) {
                    var tabId = fragment.substring(1); // Remove '#'
                    var found = false;

                    // 1. Prefer navigation list order (jQuery-UI relies on that)
                    $("#frl-tabs-nav > li > a").each(function(index) {
                        if ($(this).attr("href") === fragment) {
                            activeTab = index;
                            activeTabId = tabId;
                            found = true;
                            return false; // break
                        }
                    });

                    // 2. Fallback: derive index from panel order if nav link was not found
                    if (!found) {
                        $("#frl-tabs-content .frl-section").each(function(index) {
                            if ($(this).attr("id") === tabId) {
                                activeTab = index;
                                activeTabId = tabId;
                                return false; // break
                            }
                        });
                    }
                } else {
                    // FALLBACK: rely on server-side value embedded in the markup
                    var serverActive = parseInt($("#tabs").data("active-tab"), 10);
                    if (!isNaN(serverActive) && serverActive >= 0) {
                        activeTab = serverActive;

                        // Derive the corresponding panel ID if it exists
                        var $panel = $("#frl-tabs-content .frl-section").eq(activeTab);
                        if ($panel.length) {
                            activeTabId = $panel.attr("id");
                        }
                    }
                }

                // Apply active classes manually before jQuery UI initializes
                $("#frl-tabs-nav > li").removeClass("ui-tabs-active");
                $("#frl-tabs-nav > li").eq(activeTab).addClass("ui-tabs-active");
                $("#frl-tabs-content .frl-section").removeClass("ui-tabs-active");
                if (activeTabId) {
                    $("#" + activeTabId).addClass("ui-tabs-active");
                } else {
                    $("#frl-tabs-content .frl-section").eq(activeTab).addClass("ui-tabs-active");
                }

                // Initialize tabs with minimal delay
                setTimeout(function() {
                    $("#tabs").tabs({
                        active: activeTab,
                        create: function() {
                            // Update the form field with the active tab ID during initialization
                            if (activeTabId) {
                                $("input[name='frl_active_tab']").val(activeTabId);
                            }
                        },
                        activate: function(event, ui) {
                            var newTabId = ui.newPanel.attr("id");

                            // --- Conditional Load for Admin Tools ---
                            // Check if the activated tab is the Administration tab and if the UI hasn't been initialized yet.
                            if (newTabId === 'frl-tabs-administration' && !window.frlMenuReorderUIInitialized) {
                                initMenuReorderUI();
                                window.frlMenuReorderUIInitialized = true; // Set flag to prevent re-initialization
                            }
                            // --- End Conditional Load ---

                            // Update the hidden form field with the current tab ID
                            $("input[name='frl_active_tab']").val(newTabId);

                            if (history.pushState) {
                                var baseUrl = window.location.href.split("#")[0];

                                // Always add the fragment identifier
                                var newUrl = baseUrl + "#" + newTabId;

                                history.pushState(null, null, newUrl);
                            }
                        }
                    });

                    // Trigger content loaded event
                    $(document).trigger("frl_content_loaded");
                    window.frlAdminTabsInitialized = true; // Set flag
                }, 1); // Minimal timeout to allow DOM processing

            } catch(e) {
                // console.error("Early tab initialization error:", e);
            }
        }
    }

    // Run immediately if document already interactive
    if (document.readyState === "interactive" || document.readyState === "complete") {
        initTabsEarly();
    } else {
        // Otherwise wait for DOMContentLoaded (still faster than document.ready)
        document.addEventListener("DOMContentLoaded", initTabsEarly);
    }
})(jQuery);

// Original document.ready code
jQuery(document).ready(function ($) {
    // TABS ALREADY INITIALIZED: Skip tab initialization since we do it early
    if ($("#tabs").length) {
        // Clean up URL after settings update
        if (window.location.search.indexOf('settings-updated=true') > -1) {
            var baseUrl = window.location.href.split('?')[0];
            var queryParams = new URLSearchParams(window.location.search);

            // Remove unnecessary parameters
            queryParams.delete('settings-updated');

            // Preserve any other query parameters
            var newUrl = baseUrl;
            if (queryParams.toString()) {
                newUrl += '?' + queryParams.toString();
            }

            // Preserve the fragment identifier
            if (window.location.hash) {
                newUrl += window.location.hash;
            }

            // Replace the URL without adding to browser history
            if (history.replaceState) {
                history.replaceState(null, null, newUrl);
            }
        }
    }

    // Initialize toggle functionality for code examples
    function initToggleButtons() {
        // First remove any existing click handlers to prevent duplicates
        $(".toggle-example").off("click");

        // Store original text for all toggle buttons
        $(".toggle-example").each(function() {
            $(this).data("originalText", $(this).text().trim());
        });

        // Add the click handler
        $(".toggle-example").on("click", function () {
            var targetId = $(this).data("target");
            var $target = $("#" + targetId);
            var $button = $(this);

            // Get the original text
            var originalText = $button.data("originalText");

            if ($target.is(":visible")) {
                $target.slideUp();
                // Always restore the original label when hiding content
                $button.text(originalText);
                $button.removeAttr("data-state");
            } else {
                // Close all other open toggles first
                $(".toggle-example[data-state='open']").each(function() {
                    $("#" + $(this).data("target")).slideUp();
                    $(this).text($(this).data("originalText")).removeAttr("data-state");
                });

                $target.slideDown();
                // Always use "Hide Code" when showing content
                $button.text("Hide Code");
                $button.attr("data-state", "open");

                // Add Prism highlighting when showing code
                if (typeof Prism !== "undefined" && $target.find("code").length) {
                    Prism.highlightAllUnder($target[0]);
                }
            }
        });
    }

    // Run the initialization
    initToggleButtons();

    // Re-initialize after AJAX content is loaded
    $(document).on('frl_content_loaded', function () {
        initToggleButtons();
    });

    /**
     * Initializes the UI functionality for the admin menu reordering tool.
     * This function is called only when the "Admin Tools" tab is active.
     */
    function initMenuReorderUI() {
        var $reorderUI = $('#frl-menu-reorder-ui');
        if (!$reorderUI.length) {
            return; // Exit if the container doesn't exist
        }

        var $copyAllButton = $('#frl-copy-all-menu-items');
        var $targetTextarea = $('textarea[name="frl_am_menu_order"]');

        // 1. Handle copying all items to the textarea
        if ($copyAllButton.length) {
            $copyAllButton.on('click', function () {
                var allItemsText = $reorderUI.find('.frl-copy-menu-item')
                    .map(function() {
                        return $(this).data('copy-text');
                    }).get().join('\n');

                $targetTextarea.val(allItemsText);

                var originalButtonText = $(this).html();
                $(this).text('Order Copied!');
                setTimeout(function() {
                    $copyAllButton.html(originalButtonText);
                }, 2000);
            });
        }

        // 2. Handle copying a single item to the clipboard using event delegation
        $reorderUI.on('click', '.frl-copy-menu-item', function(e) {
            var $listItem = $(this);
            var textToCopy = $listItem.data('copy-text');
            var originalItemHTML = $listItem.html();

            navigator.clipboard.writeText(textToCopy).then(function() {
                $listItem.html('<strong>Copied to clipboard!</strong>').css('opacity', 0.7);
                setTimeout(function() {
                    $listItem.html(originalItemHTML).css('opacity', 1);
                }, 1500);
            }).catch(function(err) {
                console.error('Failed to copy text: ', err);
                $listItem.html('<strong>Copy Failed!</strong>');
                setTimeout(function() {
                    $listItem.html(originalItemHTML);
                }, 2000);
            });
        });
    }

    // --- Initial check on page load ---
    // This handles the case where the page loads with the Admin Tools tab already active.
    if ($('#frl-tabs-administration.ui-tabs-active').length) {
        if (!window.frlMenuReorderUIInitialized) {
            initMenuReorderUI();
            window.frlMenuReorderUIInitialized = true;
        }
    }
});
