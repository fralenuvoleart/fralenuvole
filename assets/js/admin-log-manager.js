/**
 * WordPress Debug Log Manager Script
 */
(function($) {
    'use strict';

    // Simple page load tracker using sessionStorage
    const isPageReload = sessionStorage.getItem('frl_page_loaded');
    if (!isPageReload) {
        // First load - set the flag
        sessionStorage.setItem('frl_page_loaded', 'true');
    }

    // DOM ready
    $(function() {
        // Elements
        const refreshBtn = $('#refresh-log');
        const clearBtn = $('#clear-log');
        const downloadBtn = $('#download-log');
        const copyAllBtn = $('#copy-all');
        const entriesSelect = $('#entries_limit');
        const sortSelect = $('#sort_order');
        const filterSelect = $('#error_filter');
        const entriesContainer = $('#log-entries');

        // Check URL parameters for any action
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('frl_action');
        
        // Skip auto-refresh if there's any non-log action
        const isLogAction = action && ['refresh_log', 'clear_log', 'download_log'].includes(action);
        if (action && !isLogAction) {
            return; // Exit early - no auto-refresh or event binding needed
        }

        // Automatically refresh log entries when page loads
        // This ensures the count is always up-to-date
        // Use a flag to suppress notification on initial load
        const initialLoad = true;
        if (!isPageReload) {
            refreshLogEntries(initialLoad);
        }

        /**
         * Show notification message
         * 
         * @param {string} message Message to display
         * @param {string} type    Notification type (success/error)
         */
        function showNotification(message, type = 'success') {
            // Remove existing notifications
            $('.log-notification').remove();
            
            // Create notification element
            const notification = $('<div>', {
                class: `log-notification ${type}`,
                text: message
            });
            
            // Add to body
            $('body').append(notification);
            
            // Show notification
            setTimeout(function() {
                notification.addClass('show');
            }, 10);
            
            // Hide after 3 seconds
            setTimeout(function() {
                notification.removeClass('show');
                
                // Remove after transition
                setTimeout(function() {
                    notification.remove();
                }, 300);
            }, 3000);
        }

        /**
         * Copy text to clipboard
         * 
         * @param {string} text Text to copy
         * @returns {boolean}   Success or failure
         */
        function copyToClipboard(text) {
            // Create temporary element
            const el = document.createElement('textarea');
            el.value = text;
            document.body.appendChild(el);
            el.select();
            
            // Copy text
            let success = false;
            try {
                success = document.execCommand('copy');
            } catch (err) {
                console.error('Unable to copy to clipboard', err);
            }
            
            // Remove temporary element
            document.body.removeChild(el);
            
            return success;
        }

        /**
         * Refresh log entries
         * 
         * @param {boolean} suppressNotification Whether to suppress notification (for auto-refresh)
         */
        function refreshLogEntries(suppressNotification = false) {
            // Set loading state
            entriesContainer.html('<div class="widget-table-row no-entries"><div class="widget-table-cell-name">Loading...</div><div class="widget-table-cell-value"></div></div>');
            refreshBtn.prop('disabled', true);
            
            // Get values from filters
            const limit = entriesSelect.val();
            const order = sortSelect.val();
            const filter = filterSelect.val();
            
            // Make AJAX request
            $.ajax({
                url: logManagerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'frl_post_ajax_debug_log_refresh',
                    nonce: logManagerData.nonce,
                    limit: limit,
                    order: order,
                    filter: filter
                },
                success: function(response) {
                    if (response.success) {
                        // Update the nonce if provided
                        if (response.data.new_nonce) {
                            logManagerData.nonce = response.data.new_nonce;
                        }
                        
                        entriesContainer.html(response.data.html);
                        initCopyRowButtons();
                        updateCopyAllButton();
                        
                        // Update counts if provided in response
                        if (response.data.count !== undefined) {
                            // Update admin bar count
                            updateAdminBarCount(response.data.count);
                            
                            // Update title count bubble
                            updateTitleCountBubble(response.data.count_html);
                            
                            // Only show notification if not suppressed
                            if (!suppressNotification) {
                                showNotification('Log entries refreshed. Count updated.');
                            }
                        } else if (!suppressNotification) {
                            // Only show notification if not suppressed
                            showNotification('Log entries refreshed.');
                        }
                    } else {
                        entriesContainer.html('<div class="widget-table-row no-entries"><div class="widget-table-cell-name">Error loading log entries.</div><div class="widget-table-cell-value"></div></div>');
                        // Always show error notifications
                        showNotification(response.data.message || 'Error refreshing log entries', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    // Don't show errors for aborted requests (happens during page navigation)
                    if (status === 'abort' || xhr.statusText === 'abort') {
                        return;
                    }
                    
                    entriesContainer.html('<div class="widget-table-row no-entries"><div class="widget-table-cell-name">Error communicating with server.</div><div class="widget-table-cell-value"></div></div>');
                    showNotification('Error communicating with server', 'error');
                },
                complete: function() {
                    refreshBtn.prop('disabled', false);
                }
            });
        }

        /**
         * Clear debug log
         */
        function clearDebugLog() {
            // Confirm before clearing
            if (!confirm('Are you sure you want to clear the debug log?')) {
                return;
            }
            
            // Set loading state
            clearBtn.prop('disabled', true);
            
            // Make AJAX request   
            $.ajax({
                url: logManagerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'frl_post_ajax_debug_log_clear',
                    nonce: logManagerData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Check if we have a new nonce in the response
                        if (response.data.new_nonce) {
                            // Update the nonce for future requests
                            logManagerData.nonce = response.data.new_nonce;
                        }
                        
                        // Check if we have a redirect URL in the response
                        if (response.data.redirect_url) {
                            // Just show notification and reload, no need to update count as page will reload
                            showNotification('Debug log cleared successfully.');
                            window.location.href = response.data.redirect_url;
                        } else {
                            // Only update count manually if we're NOT reloading
                            updateAdminBarCount(0);
                            // Explicitly clear the title count bubble
                            updateTitleCountBubble('');
                            showNotification('Debug log cleared successfully. Menu count updated.');
                            // Refresh log entries
                            refreshLogEntries(true);
                        }
                    } else {
                        showNotification(response.data.message || 'Error clearing debug log', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    entriesContainer.html('<div class="widget-table-row no-entries"><div class="widget-table-cell-name">Error communicating with server.</div><div class="widget-table-cell-value"></div></div>');
                    showNotification('Error communicating with server', 'error');
                },
                complete: function() {
                    clearBtn.prop('disabled', false);
                }
            });
        }

        /**
         * Download debug log
         */
        function downloadDebugLog() {
            // Set loading state
            downloadBtn.prop('disabled', true);
            
            // Make AJAX request
            $.ajax({
                url: logManagerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'frl_post_ajax_debug_log_download',
                    nonce: logManagerData.nonce
                },
                success: function(response) {
                    if (response.success && response.data.content !== undefined) {
                        // Update the nonce if provided
                        if (response.data.new_nonce) {
                            logManagerData.nonce = response.data.new_nonce;
                        }
                        
                        // Create blob (even if content is empty)
                        const content = response.data.content || '';
                        const blob = new Blob([content], { type: 'text/plain' });
                        
                        // Create download link
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'debug-log-' + new Date().toISOString().slice(0, 10) + '.txt';
                        
                        // Trigger download
                        document.body.appendChild(a);
                        a.click();
                        
                        // Cleanup
                        setTimeout(function() {
                            document.body.removeChild(a);
                            URL.revokeObjectURL(url);
                        }, 100);
                    } else {
                        showNotification(response.data.message || 'Error downloading debug log', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    // Don't show errors for aborted requests (happens during page navigation)
                    if (status === 'abort' || xhr.statusText === 'abort') {
                        return;
                    }
                    
                    showNotification('Error communicating with server', 'error');
                },
                complete: function() {
                    downloadBtn.prop('disabled', false);
                }
            });
        }

        /**
         * Initialize copy row buttons
         */
        function initCopyRowButtons() {
            $('.copy-row').on('click', function(e) {
                e.preventDefault();
                const message = $(this).data('message');
                
                if (copyToClipboard(message)) {
                    showNotification('Message copied to clipboard');
                } else {
                    showNotification('Failed to copy message', 'error');
                }
            });
        }

        /**
         * Update copy all button state
         */
        function updateCopyAllButton() {
            const entriesCount = entriesContainer.find('.widget-table-row').not('.no-entries').length;
            copyAllBtn.data('count', entriesCount);
            copyAllBtn.prop('disabled', entriesCount === 0);
        }

        /**
         * Copy all log messages
         */
        function copyAllLogMessages() {
            const entriesCount = copyAllBtn.data('count');
            
            if (entriesCount === 0) {
                return;
            }
            
            // Set loading state
            copyAllBtn.prop('disabled', true);
            
            // Use the same endpoint as download to get raw log content
            $.ajax({
                url: logManagerData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'frl_post_ajax_debug_log_download',
                    nonce: logManagerData.nonce
                },
                success: function(response) {
                    if (response.success && response.data.content !== undefined) {
                        if (copyToClipboard(response.data.content)) {
                            showNotification('Log file content copied to clipboard');
                        } else {
                            showNotification('Failed to copy log content', 'error');
                        }
                        
                        // Update nonce if provided
                        if (response.data.new_nonce) {
                            logManagerData.nonce = response.data.new_nonce;
                        }
                    } else {
                        showNotification(response.data.message || 'Error copying log content', 'error');
                    }
                },
                error: function() {
                    showNotification('Error communicating with server', 'error');
                },
                complete: function() {
                    copyAllBtn.prop('disabled', false);
                }
            });
        }

        /**
         * Update count in admin bar menu
         * 
         * @param {number} count New count to display
         */
        function updateAdminBarCount(count) {
            // First, let's find the correct Debug Log menu item by scanning all admin bar items
            let $menuItem = null;
            $('#wpadminbar .ab-item').each(function() {
                if ($(this).text().indexOf('Debug Log') !== -1) {
                    $menuItem = $(this).parent();
                    return false; // break the loop
                }
            });
            
            if (!$menuItem || !$menuItem.length) {
                return;
            }
            
            // Find the parent plugin menu item with correct prefix
            const $parentMenuItem = $('#wp-admin-bar-frl-menu-primary');
            
            if (count > 0) {
                // Update parent menu item to show logs indicator
                if ($parentMenuItem.length) {
                    // Add frl-has-logs class if not already present
                    if (!$parentMenuItem.hasClass('frl-has-logs')) {
                        $parentMenuItem.addClass('frl-has-logs');
                    }
                }
                
                // For the Debug Log menu item
                const $menuLink = $menuItem.find('.ab-item');
                
                // Check if we already have a count bubble
                let $countBubble = $menuLink.find('.frl-count-bubble');
                
                if ($countBubble.length) {
                    // Just update the existing count bubble
                    $countBubble.text(count);
                } else {
                    // Need to create the full structure with count bubble
                    const newTitle = '<span class="debug-log-text">Debug Log</span>' +
                                    '<span class="frl-count-bubble">' + count + '</span>';
                    $menuLink.html(newTitle);
                }
            } else {
                // Remove has-logs class from parent menu
                if ($parentMenuItem.length) {
                    $parentMenuItem.removeClass('frl-has-logs');
                    // Don't remove menupop class as it might be needed for other functionality
                }
                
                // For the Debug Log menu item, remove count bubble
                const $menuLink = $menuItem.find('.ab-item');
                $menuLink.html('Debug Log');
            }
        }

        /**
         * Update the count bubble in the page title
         * 
         * @param {string} countHtml HTML for the count bubble
         */
        function updateTitleCountBubble(countHtml) {
            // First look for existing bubble
            const $title = $('.log-manager-title');
            const $existingBubble = $title.find('.log-count-bubble');
            
            if ($existingBubble.length) {
                // If we have HTML, update the bubble content
                if (countHtml) {
                    // Extract the count number from the HTML
                    const count = $(countHtml).text();
                    $existingBubble.text(count);
                } else {
                    // Otherwise remove it
                    $existingBubble.remove();
                }
            } else if (countHtml) {
                // No existing bubble but we have HTML to add
                $title.append(countHtml);
            }
        }

        // Event: Refresh log
        refreshBtn.on('click', function(e) {
            e.preventDefault();
            refreshLogEntries();
        });

        // Event: Clear log
        clearBtn.on('click', function(e) {
            e.preventDefault();
            clearDebugLog();
        });

        // Event: Download log
        downloadBtn.on('click', function(e) {
            e.preventDefault();
            downloadDebugLog();
        });

        // Event: Copy all messages
        copyAllBtn.on('click', function(e) {
            e.preventDefault();
            copyAllLogMessages();
        });

        // Initialize copy row buttons
        initCopyRowButtons();
        
        // Update copy all button state
        updateCopyAllButton();

        // Event: Apply filters button
        $('#apply-filters').on('click', function(e) {
            e.preventDefault(); 
            refreshLogEntries(); 
        });
    });
})(jQuery);